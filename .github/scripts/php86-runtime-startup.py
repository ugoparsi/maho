import http.client
import json
import os
from pathlib import Path
import subprocess
import time

assert os.environ['GITHUB_REPOSITORY']=='ugoparsi/maho'
tmp=Path(os.environ['RUNNER_TEMP'])/'php86-controlled-startup'
tmp.mkdir()
probe=tmp/'probe.php'
probe.write_text('''<?php
$status = opcache_get_status(false);
header('Content-Type: application/json');
echo json_encode([
    'php' => PHP_VERSION, 'xdebug' => extension_loaded('xdebug'), 'pcov' => extension_loaded('pcov'),
    'redis' => phpversion('redis'), 'zend_extensions' => get_loaded_extensions(true),
    'jit_requested' => ini_get('opcache.jit'), 'jit_buffer' => ini_get('opcache.jit_buffer_size'),
    'jit_active' => is_array($status) && ($status['jit']['on'] ?? false),
    'ini' => array_values(array_filter(array_map('trim', explode(',', php_ini_scanned_files() ?: '')))),
], JSON_THROW_ON_ERROR);
''')

def attempt(mode, port, extra=(), scans=None):
    env=os.environ.copy()
    if scans is not None:
        env['PHP_INI_SCAN_DIR']=str(scans)
    log=tmp/(mode+'.log')
    with log.open('wb') as out:
        server=subprocess.Popen(['php','-d','error_reporting=-1','-d','display_errors=0','-d','log_errors=1',
            *extra,'-S',f'127.0.0.1:{port}','-t',str(tmp)], stdout=out,stderr=out,env=env)
        try:
            for _ in range(50):
                assert server.poll() is None
                if 'Development Server' in log.read_text():
                    break
                time.sleep(.1)
            else:
                raise RuntimeError('Loopback server failed to start')
            client=http.client.HTTPConnection('127.0.0.1',port,timeout=5)
            try:
                client.request('GET','/probe.php')
                response=client.getresponse()
                assert response.status==200
                details=json.loads(response.read())
            finally:
                client.close()
        finally:
            if server.poll() is None:
                server.terminate()
                server.wait(timeout=5)
    warning='JIT is incompatible with third party extensions that override zend_execute_ex()'
    details['jit_warning']=warning in log.read_text()
    details['mode']=mode
    return details

stock=attempt('stock',18991)
assert stock['php'].startswith('8.6.') and stock['redis']
print(json.dumps(stock,sort_keys=True),flush=True)
paths=[Path(name) for name in stock['ini']]
debug=[path for path in paths if 'xdebug' in path.name.lower() or 'pcov' in path.name.lower()]
if debug:
    stripped=tmp/'without-debug-ini'
    stripped.mkdir()
    for path in paths:
        if path not in debug:
            (stripped/path.name).symlink_to(path)
    clean=attempt('without-debug-extensions',18992,scans=stripped)
    assert clean['redis']==stock['redis']
    assert not clean['xdebug'] and not clean['pcov']
    print(json.dumps(clean,sort_keys=True),flush=True)
    if stock['jit_warning']:
        assert not clean['jit_warning']
off=attempt('jit-explicitly-disabled',18993,extra=('-d','opcache.jit=disable','-d','opcache.jit_buffer_size=0'))
assert not off['jit_warning'] and not off['jit_active'] and off['redis']==stock['redis']
print(json.dumps(off,sort_keys=True),flush=True)
