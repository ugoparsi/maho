#!/usr/bin/env bash
set -euo pipefail
root=$(pwd -P)
proof="$RUNNER_TEMP/gallery-proof"
log="$proof/guard.jsonl"
export MAHO_LOCALE_PROOF_ROOT="$root" MAHO_LOCALE_PROOF_RECEIPT="$log"
export GALLERY_ENTRY="$root/.github/scripts/gallery-proof-entry.php.source"
export MAHO_BROWSER_HOST=127.0.0.1 MAHO_BROWSER_PORT=8901 MAGE_IS_DEVELOPER_MODE=1
export TMPDIR="$proof/tmp"
mkdir "$proof" "$proof/tmp" "$proof/ini"
mkdir -p var/db
test ! -e app/etc/local.xml
test ! -e var/db/maho.sqlite
printf 'auto_prepend_file="%s"\n' "$GALLERY_ENTRY" > "$proof/ini/90-gallery.ini"
export PHP_INI_SCAN_DIR="${PHP_INI_SCAN_DIR:-}:$proof/ini"
php .github/scripts/gallery-proof-layout.php.source > "$proof/layout.json"
jq -e --arg root "$root" '.root==$root and .php=="8.6.0"' "$proof/layout.json"
php maho install --license_agreement_accepted yes --locale en_US --timezone Europe/London \
    --default_currency USD --db_name maho.sqlite --db_engine sqlite \
    --url http://127.0.0.1:8901/ --secure_base_url http://127.0.0.1:8901/ --use_secure 0 --use_secure_admin 0 \
    --admin_lastname Example --admin_firstname Fixture --admin_email gallery@example.test \
    --admin_username gallery_fixture --admin_password 'GalleryFixtureOnly2026!' \
    --sample_data "$RUNNER_TEMP/maho-sample-data-e796677c36ae694cadf156b763b096fd2abb619e"
php maho index:reindex:all
php maho cache:flush
test "$(realpath var/db/maho.sqlite)" = "$root/var/db/maho.sqlite"
test "$(realpath app/etc/local.xml)" = "$root/app/etc/local.xml"
test ! -e var/db/maho.sqlite-wal
test ! -e var/db/maho.sqlite-shm
grep -q '"phase":"before_pdo_open"' "$log"
grep -q '"phase":"shutdown_idle"' "$log"
sha256sum app/etc/local.xml var/db/maho.sqlite > "$proof/installed-hashes"
cp "$log" "$proof/install-guard.jsonl"
export MAHO_LOCALE_PROOF_RECEIPT="$proof/native-guard.jsonl"
cp app/Mage.php "$proof/Mage.candidate.php.source"
git show HEAD~4:app/Mage.php > "$proof/Mage.baseline.php.source"
cp "$proof/Mage.baseline.php.source" app/Mage.php
if timeout 45 php vendor/bin/pest --filter 'dispatches within the current transaction after resetting Mage' \
    --log-junit "$proof/queue-baseline.xml" tests/Backend/Integration/Queue/DbTransportTest.php; then
    echo 'Expected the baseline transaction assertion to fail'
    exit 1
else
    baseline_exit=$?
    echo "Queue rollback baseline exit $baseline_exit"
    test "$baseline_exit" -eq 1
fi
grep -q '"phase":"queue_after_reset_idle"' "$MAHO_LOCALE_PROOF_RECEIPT"
mv "$MAHO_LOCALE_PROOF_RECEIPT" "$proof/queue-baseline-guard.jsonl"
cp "$proof/Mage.candidate.php.source" app/Mage.php
test "$(git hash-object app/Mage.php)" = "$(git rev-parse HEAD:app/Mage.php)"
timeout 45 php vendor/bin/pest --filter 'dispatches within the current transaction after resetting Mage' \
    --log-junit "$proof/queue-candidate.xml" tests/Backend/Integration/Queue/DbTransportTest.php
grep -q '"phase":"queue_after_rollback_idle"' "$MAHO_LOCALE_PROOF_RECEIPT"
python3 - "$proof" <<'PY'
from pathlib import Path
import sys
import xml.etree.ElementTree as ET

proof = Path(sys.argv[1])
for variant in ('baseline', 'candidate'):
    root = ET.parse(proof / f'queue-{variant}.xml').getroot()
    cases = root.findall('.//testcase')
    expected_assertions = '1' if variant == 'baseline' else '3'
    assert len(cases) == 1 and cases[0].attrib['assertions'] == expected_assertions
    failure = cases[0].find('failure')
    assert cases[0].find('error') is None and cases[0].find('skipped') is None
    if variant == 'baseline':
        assert failure is not None and 'Failed asserting that true is false.' in failure.text
    else:
        assert failure is None
    print(f'Queue rollback {variant}: 1 test, {expected_assertions} assertions, expected result')
PY
mv "$MAHO_LOCALE_PROOF_RECEIPT" "$proof/queue-candidate-guard.jsonl"
test ! -e var/db/maho.sqlite-wal
test ! -e var/db/maho.sqlite-shm

cat > "$proof/router.php" <<'PHP'
<?php
require_once getenv('GALLERY_ENTRY');
$galleryStarted = hrtime(true);
register_shutdown_function(static fn () => galleryProofSlow('http_request', $galleryStarted));
return require LOCALE_PROOF_ROOT . '/tests/router.php';
PHP

pid=
finish() {
    if [[ -n "$pid" ]]; then
        if kill -0 "$pid" 2>/dev/null; then
            local pgid
            pgid=$(ps -o pgid= -p "$pid" | tr -d ' ')
            if [[ "$pgid" == "$pid" ]]; then kill -TERM -- "-$pid"; fi
        fi
        wait "$pid" || true
    fi
    if [[ -e "$MAHO_LOCALE_PROOF_RECEIPT" ]]; then
        jq -c 'select(.phase=="gallery_phase" or .phase=="gallery_slow")' "$MAHO_LOCALE_PROOF_RECEIPT"
    fi
    if [[ -f "$proof/server.log" ]]; then
        tail -25 "$proof/server.log"
    fi
    python3 - <<'PY'
import socket
with socket.socket() as client:
    client.settimeout(2)
    assert client.connect_ex(('127.0.0.1', 8901)) != 0, 'Owned listener remains'
PY
}
trap finish EXIT
PHP_CLI_SERVER_WORKERS=8 setsid php -d auto_prepend_file= -d opcache.enable_cli=1 \
    -d opcache.validate_timestamps=1 -d opcache.revalidate_freq=0 \
    -S 127.0.0.1:8901 -t public "$proof/router.php" > "$proof/server.log" 2>&1 &
pid=$!
for attempt in {1..15}; do
    if curl -fsS --max-time 5 http://127.0.0.1:8901/ -o /dev/null; then break; fi
    kill -0 "$pid"
    test "$attempt" -lt 15
    sleep 1
done
timeout 260 php vendor/bin/pest --profile --display-errors --display-warnings \
    --testsuite=Browser --exclude-group=currency-live --exclude-group=sandbox \
    --fail-on-empty-test-suite --fail-on-skipped --filter 'shows every gallery image|shows one image and the thumbnails at its left' \
    tests/Browser/ProductGalleryLayoutTest.php
grep -q '"phase":"gallery_browser_setup_idle"' "$MAHO_LOCALE_PROOF_RECEIPT"
grep -q '"phase":"before_gallery_product_write"' "$MAHO_LOCALE_PROOF_RECEIPT"
grep -q '"phase":"fixture_commit_idle"' "$MAHO_LOCALE_PROOF_RECEIPT"
