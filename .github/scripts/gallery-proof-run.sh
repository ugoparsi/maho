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
    if ss -Hln '( sport = :8901 )' | grep -q .; then
        echo 'Owned listener remains'
        return 1
    fi
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
