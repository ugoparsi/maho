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
setup = root / 'tests/Pest.php'
original_setup = setup.read_text()
test_file = 'tests/Api/Soap/SoapResponseTest.php'
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


def run_probe(deadline):
    timeout_line = '                curl_setopt($ch, CURLOPT_TIMEOUT, 3);'
    exec_line = '                curl_exec($ch);'
    assert original_setup.count(timeout_line) == 1 and original_setup.count(exec_line) == 1
    trace = '''
                fwrite(STDERR, 'API_READINESS ' . json_encode([
                    'http' => curl_getinfo($ch, CURLINFO_HTTP_CODE),
                    'seconds' => curl_getinfo($ch, CURLINFO_TOTAL_TIME),
                    'errno' => curl_errno($ch), 'error' => curl_error($ch),
                ], JSON_THROW_ON_ERROR) . PHP_EOL);'''
    setup.write_text(original_setup.replace(timeout_line, timeout_line.replace('3);', f'{deadline});')).replace(exec_line, exec_line + trace))
    server_log = temp / f'api-server-{deadline}.log'
    junit = temp / f'api-junit-{deadline}.xml'
    start_at = receipt.stat().st_size
    print(f'API_PROBE_BEGIN deadline={deadline} setup_sha256={fixture_hash(setup)}', flush=True)
    with server_log.open('x') as log:
        server = subprocess.Popen([
            'php', '-d', 'auto_prepend_file=', '-d', 'opcache.enable_cli=1', '-d', 'opcache.validate_timestamps=1',
            '-d', 'opcache.revalidate_freq=0', '-S', address, '-t', 'public', str(router),
        ], env=os.environ | {'PHP_CLI_SERVER_WORKERS': '8', 'MAHO_GRAPHQL_INTROSPECTION': '1'},
           stdin=subprocess.DEVNULL, stdout=log, stderr=log, start_new_session=True)
        try:
            wait_for_server(server)
            test = subprocess.run([
                'php', 'vendor/bin/pest', test_file, '--colors=never', '--fail-on-skipped', '--log-junit', str(junit),
            ], capture_output=True, text=True, timeout=90)
            print(test.stdout, end='', flush=True)
            print(test.stderr, end='', flush=True)
        finally:
            os.killpg(server.pid, signal.SIGTERM)
            server.wait(timeout=15)
            print(server_log.read_text(), end='', flush=True)
    assert_closed()
    case, = ElementTree.parse(junit).findall('.//testcase')
    assert case.find('failure') is None and case.find('error') is None, case.attrib
    traces = [json.loads(line.split('API_READINESS ', 1)[1]) for line in (test.stderr + test.stdout).splitlines() if 'API_READINESS ' in line]
    assert len(traces) == 1, traces
    with receipt.open() as stream:
        stream.seek(start_at)
        records = [json.loads(line) for line in stream]
    phases = {row['phase'] for row in records}
    assert {'prepend', 'before_pdo_open', 'pdo_opened', 'before_first_adapter_query', 'api_before_fixtures', 'shutdown_idle'} <= phases
    assert all(row['php'] == '8.6.0' for row in records)
    requests = {row.get('request_path') for row in records if row['phase'] == 'prepend'}
    assert '/api/rest/v2/store-config' in requests and (case.find('skipped') is not None or '/api/v2_soap' in requests)
    assert 'Unexpected' not in test.stderr + server_log.read_text()
    result = traces[0] | {
        'deadline': deadline, 'skipped': case.find('skipped') is not None,
        'assertions': int(case.attrib.get('assertions', '0')), 'exit': test.returncode,
        'guard_phases': sorted(phases), 'runtime_closed': True,
    }
    print('API_PROBE_RESULT ' + json.dumps(result, sort_keys=True), flush=True)
    return result


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
    baseline = run_probe(3)
    assert_closed()
    db.rename(temp / 'api-proof-reference.sqlite')
    shutil.copyfile(seed, db)
    assert fixture_hash(db) == seed_hash
    subprocess.run(['php', 'maho', 'cache:flush'], check=True)
    candidate = run_probe(15)
    assert candidate['exit'] == 0 and not candidate['skipped'] and candidate['assertions'] >= 3, candidate
    print('API_READINESS_DIFFERENTIAL ' + json.dumps({'baseline': baseline, 'candidate': candidate, 'seed': seed_hash}), flush=True)
finally:
    setup.write_text(original_setup)
    assert_closed()
    assert fixture_hash(seed) == seed_hash
    db.rename(temp / 'api-proof-candidate.sqlite')
    (root / 'app/etc/local.xml').rename(temp / 'api-proof-local.xml')
    sessions = list((root / 'var/session').glob('sess_*'))
    for session in sessions:
        assert session.is_file() and not session.is_symlink()
        session.unlink()
    print(f'API_FIXTURE_CLOSED=1 sessions_removed={len(sessions)}', flush=True)
