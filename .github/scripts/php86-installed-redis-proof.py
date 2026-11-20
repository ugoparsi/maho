from collections import Counter
import http.client
from http.cookies import SimpleCookie
import json
import os
from pathlib import Path
import re
import socket
import subprocess
import time
from xml.etree import ElementTree

root = Path(os.environ['GITHUB_WORKSPACE'])
tmp = Path(os.environ['RUNNER_TEMP'])
service = (tmp/'maho-redis-service-id').read_text().strip()
redis_socket = Path(os.environ['MAHO_REDIS_SOCKET'])
assert redis_socket==tmp/'redis-unix-proof/redis.sock' and redis_socket.is_socket() and not redis_socket.is_symlink()
guard = Path(os.environ['MAHO_LOCALE_PROOF_RECEIPT'])
config = root/'app/etc/local.xml'
endpoint = '127.0.0.1'
port = 18986
prefix = 'maho_php86_installed:'
assert os.environ['GITHUB_REPOSITORY']=='ugoparsi/maho' and Path.cwd()==root
assert config.is_file() and config.resolve()==config
assert re.fullmatch(r'[0-9a-f]{64}',service)

def redis(db, *args):
    return subprocess.check_output(['docker','exec',service,'redis-cli','--raw','-s','/proof/redis.sock','-n',str(db),*args],
        text=True,timeout=5).strip()

def reachable():
    with socket.socket() as client:
        client.settimeout(0.3)
        return client.connect_ex((endpoint,port))==0

health = subprocess.check_output(['docker','inspect','--format','{{.State.Health.Status}}',service],text=True).strip()
assert health=='healthy'
info = redis(0,'INFO','server')
run_id = next(line.split(':',1)[1] for line in info.splitlines() if line.startswith('run_id:'))
assert re.fullmatch(r'[0-9a-f]{40}',run_id)
local = subprocess.check_output(['php','-r',
    '$r=new Redis; $r->connect(getenv("MAHO_REDIS_SOCKET"), 0, 2); echo $r->info("server")["run_id"];'],
    text=True,timeout=6)
assert local==run_id
assert redis(0,'DBSIZE')==redis(1,'DBSIZE')=='0'
assert not reachable()

tree = ElementTree.parse(config)
global_config = tree.find('global')
assert global_config.findtext('session_save')=='files'
assert global_config.find('redis_session') is None
global_config.find('session_save').text = 'redis'
session = ElementTree.SubElement(global_config,'redis_session')
ElementTree.SubElement(session,'dsn').text = f'redis://{redis_socket}/1'
ElementTree.SubElement(session,'key_prefix').text = prefix
cache = global_config.find('cache')
if cache is None:
    cache = ElementTree.SubElement(global_config,'cache')
backend = cache.find('backend')
if backend is None:
    backend = ElementTree.SubElement(cache,'backend')
backend.text = 'redis'
options = cache.find('backend_options')
if options is None:
    options = ElementTree.SubElement(cache,'backend_options')
assert options.find('dsn') is None
ElementTree.SubElement(options,'dsn').text = f'redis://{redis_socket}/0'
tree.write(config,encoding='utf-8')

def request(path, identifier=None):
    client=http.client.HTTPConnection(endpoint,port,timeout=7)
    try:
        client.request('GET',path,headers={} if identifier is None else {'Cookie':'maho_session='+identifier})
        response=client.getresponse()
        assert response.status==200,(path,response.status)
        cookies=[]
        for key,value in response.getheaders():
            if key.lower()=='set-cookie':
                jar=SimpleCookie(value)
                if 'maho_session' in jar:
                    cookies.append(jar['maho_session'])
        assert len(cookies)==1
        cookie=cookies[0]
        assert cookie['httponly'] and cookie['samesite']=='Lax'
        assert re.fullmatch(r'[a-zA-Z0-9,-]{16,256}',cookie.value)
        return json.loads(response.read()),cookie.value
    finally:
        client.close()

log=tmp/'redis-app-server.log'
with log.open('xb') as writer:
    server=subprocess.Popen(['php','-d','display_errors=0','-d','log_errors=1','-d','error_reporting=-1',
        '-S',f'{endpoint}:{port}','-t','public','.github/scripts/php86-installed-redis-router.php.source'],
        cwd=root,stdout=writer,stderr=writer)
    try:
        for _ in range(40):
            assert server.poll() is None
            if reachable():
                break
            time.sleep(0.1)
        else:
            raise RuntimeError('Loopback server not ready')

        initial,identifier=request('/start')
        assert initial['php'].startswith('8.6.') and initial['redis_extension']
        assert initial['jit_active'] and not initial['xdebug']
        assert initial['session'] is None and initial['cache'] is False
        assert redis(1,'DBSIZE')=='0'
        updated,after=request('/save',identifier)
        assert after==identifier and updated['cookie_accepted']
        assert updated['session']=='persisted-session' and updated['cache']=='persisted-cache'
        assert redis(1,'DBSIZE')=='1' and redis(0,'EXISTS',prefix+identifier)=='0'
        ttl=int(redis(1,'TTL',prefix+identifier))
        assert 0 < updated['session_ttl']-2 <= ttl <= updated['session_ttl']
        resumed,after=request('/read',identifier)
        assert after==identifier and resumed['cookie_accepted']
        assert resumed['cache']==updated['cache'] and resumed['session']==updated['session']

        cleared,after=request('/clear',identifier)
        assert after==identifier and cleared['tag_invalidated'] and cleared['untagged_cache_preserved']
        assert redis(1,'DBSIZE')=='0' and not list((root/'var/session').rglob('sess_*'))
        phases=Counter()
        for event in map(json.loads, guard.read_text().splitlines()):
            key=event['phase']
            if key=='redis_before_connect':
                key += ':'+event['target']
            phases[key] += 1
        for phase in ('redis_before_connect:cache','redis_before_connect:session','redis_fixture_ready','redis_fixture_closed'):
            assert phases[phase]>=4, (phase,phases)
        assert phases['before_pdo_open'] and phases['before_first_adapter_query']
        assert not re.search(r'(?:Warning|Deprecated|Fatal error|RuntimeException|TypeError):',log.read_text())
        print(json.dumps({'php':initial['php'],'ext_redis':initial['redis_extension'],
            'cache_db':0,'session_db':1,'session_ttl':ttl,'configured_ttl':updated['session_ttl'],
            'session_reused':True,'tag_invalidated':True,'untagged_cache_preserved':True,
            'db1_keys_after_clear':0,'redis_transport':'unix_socket','jit_active':True,'xdebug':False,
            'file_sessions_after_clear':0,'guard_phases':dict(phases)},sort_keys=True))
    finally:
        if server.poll() is None:
            server.terminate()
            server.wait(timeout=5)
        assert not reachable()
