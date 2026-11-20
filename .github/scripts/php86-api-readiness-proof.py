import hashlib
import json
import os
from pathlib import Path
import shutil
import signal
import socket
import subprocess
import time
from xml.etree import ElementTree


root = Path(os.environ['GITHUB_WORKSPACE'])
temp = Path(os.environ['RUNNER_TEMP'])
receipt = Path(os.environ['MAHO_LOCALE_PROOF_RECEIPT'])
db = root / 'var/db/api-proof.sqlite'
helper = root / 'tests/Helpers/ApiV2Helper.php'
original_helper = helper.read_text()
test_files = ['tests/Api/Soap/SoapResponseTest.php', 'tests/Api/V2/Read/HttpCacheTest.php']
address = '127.0.0.1:18986'
base_url = f'http://{address}'
router = temp / 'api-proof-router.php'


def assert_closed():
    assert db.is_file() and not db.is_symlink()
    assert not any(Path(f'{db}{suffix}').exists() for suffix in ('-wal', '-shm', '-journal'))
    assert subprocess.run(['fuser', str(db)], capture_output=True).returncode == 1
    with socket.socket() as probe:
        assert probe.connect_ex(('127.0.0.1', 18986)) != 0


def fixture_hash(path):
    with path.open('rb') as stream:
        return hashlib.file_digest(stream, 'sha256').hexdigest()


def wait_for_server(process):
    for _ in range(20):
        assert process.poll() is None
        for path in ('/api/rest/v2/store-config', '/'):
            if subprocess.run(['curl', '-fsS', '--max-time', '10', '-o', '/dev/null', base_url + path]).returncode == 0:
                return
        time.sleep(1)
    raise RuntimeError('Web server startup timed out')


def run_probe():
    exec_line = '            curl_exec($handle);'
    assert original_helper.count(exec_line) == 1
    trace = '''
            fwrite(STDERR, 'API_READINESS ' . json_encode([
                'http' => curl_getinfo($handle, CURLINFO_HTTP_CODE),
                'seconds' => curl_getinfo($handle, CURLINFO_TOTAL_TIME),
                'errno' => curl_errno($handle), 'error' => curl_error($handle),
            ], JSON_THROW_ON_ERROR) . PHP_EOL);'''
    helper.write_text(original_helper.replace(exec_line, exec_line + trace))
    server_log = temp / 'api-server-candidate.log'
    junit = temp / 'api-junit-candidate.xml'
    start_at = receipt.stat().st_size
    print(f'API_PROBE_BEGIN helper_sha256={fixture_hash(helper)}', flush=True)
    with server_log.open('x') as log:
        server = subprocess.Popen([
            'php', '-d', 'auto_prepend_file=', '-d', 'opcache.enable_cli=1', '-d', 'opcache.validate_timestamps=1',
            '-d', 'opcache.revalidate_freq=0', '-S', address, '-t', 'public', str(router),
        ], env=os.environ | {'PHP_CLI_SERVER_WORKERS': '8', 'MAHO_GRAPHQL_INTROSPECTION': '1'},
           stdin=subprocess.DEVNULL, stdout=log, stderr=log, start_new_session=True)
        try:
            wait_for_server(server)
            test = subprocess.run([
                'php', 'vendor/bin/pest', *test_files, '--colors=never', '--fail-on-skipped', '--log-junit', str(junit),
            ], capture_output=True, text=True, timeout=90)
            print(test.stdout, end='', flush=True)
            print(test.stderr, end='', flush=True)
        finally:
            # Let workers finish request shutdown after the last response.
            for _ in range(30):
                if subprocess.run(['fuser', str(db)], capture_output=True).returncode == 1:
                    break
                time.sleep(0.2)
            os.killpg(server.pid, signal.SIGTERM)
            server.wait(timeout=15)
            print(server_log.read_text(), end='', flush=True)
    assert_closed()
    cases = ElementTree.parse(junit).findall('.//testcase')
    assert len(cases) == 6, len(cases)
    for case in cases:
        assert all(case.find(flag) is None for flag in ('failure', 'error', 'skipped')), case.attrib
    assert test.returncode == 0
    traces = [json.loads(line.split('API_READINESS ', 1)[1]) for line in (test.stderr + test.stdout).splitlines() if 'API_READINESS ' in line]
    assert len(traces) == 1, traces
    with receipt.open() as stream:
        stream.seek(start_at)
        records = [json.loads(line) for line in stream]
    phases = {row['phase'] for row in records}
    assert {'prepend', 'before_pdo_open', 'pdo_opened', 'before_first_adapter_query', 'api_before_fixtures', 'shutdown_idle'} <= phases
    assert all(row['php'] == '8.6.0' for row in records)
    requests = {row.get('request_path') for row in records if row['phase'] == 'prepend'}
    assert {'/api/rest/v2/store-config', '/api/v2_soap'} <= requests
    assert 'Unexpected' not in test.stderr + server_log.read_text()
    result = {
        'readiness': traces[0], 'readiness_calls': len(traces), 'passed': len(cases),
        'assertions': sum(int(case.attrib.get('assertions', '0')) for case in cases),
        'guard_phases': sorted(phases), 'runtime_closed': True,
    }
    assert result['readiness']['http'] == 200 and result['readiness']['errno'] == 0, result
    print('API_PROBE_RESULT ' + json.dumps(result, sort_keys=True), flush=True)


assert os.environ['GITHUB_REPOSITORY'] == 'ugoparsi/maho'
assert root.resolve() == root and Path.cwd() == root
assert (root / 'app/etc/local.xml').is_file() and not (root / 'app/etc/local.xml').is_symlink()
assert os.environ['API_BASE_URL'] == base_url
assert_closed()
with router.open('x') as stream:
    stream.write('''<?php
require getenv('GITHUB_WORKSPACE') . '/.github/scripts/php86-api-readiness-guard.php.source';
return require getenv('GITHUB_WORKSPACE') . '/tests/router.php';
''')
seed = temp / 'api-proof-closed-seed.sqlite'
shutil.copyfile(db, seed)
seed.chmod(0o400)
seed_hash = fixture_hash(seed)

try:
    run_probe()
finally:
    helper.write_text(original_helper)
    assert not db.is_symlink() and db.is_file()
    assert subprocess.run(['fuser', str(db)], capture_output=True).returncode == 1
    if any(Path(f'{db}{suffix}').exists() for suffix in ('-wal', '-shm', '-journal')):
        archive = temp / 'api-proof-interrupted'
        archive.mkdir(mode=0o700)
        for suffix in ('', '-wal', '-shm', '-journal'):
            path = Path(f'{db}{suffix}')
            if path.exists():
                shutil.copyfile(path, archive / path.name)
        print('API_PROOF_UNCHECKPOINTED=' + json.dumps({
            path.name: fixture_hash(path) for path in archive.iterdir()
        }, sort_keys=True), flush=True)
    assert_closed()
    assert fixture_hash(seed) == seed_hash
    db.rename(temp / 'api-proof-candidate.sqlite')
    (root / 'app/etc/local.xml').rename(temp / 'api-proof-local.xml')
    sessions = list((root / 'var/session').glob('sess_*'))
    for session in sessions:
        assert session.is_file() and not session.is_symlink()
        session.unlink()
    print(f'API_FIXTURE_CLOSED=1 sessions_removed={len(sessions)}', flush=True)
    subprocess.run([
        'php', '-r', r'require "tests/Helpers/ApiV2Helper.php"; exit(\Tests\Helpers\ApiV2Helper::isServerAvailable() ? 1 : 0);',
    ], check=True, timeout=5)
    print('API_OFFLINE_CONTROL=unavailable', flush=True)
