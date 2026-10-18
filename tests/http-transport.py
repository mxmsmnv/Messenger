#!/usr/bin/env python3
"""Disposable PHP HTTP integration with fixture services; no real site/TLS/database."""
import base64, hashlib, html, json, os, pathlib, socket, subprocess, tempfile, time
import urllib.request, urllib.parse, urllib.error
ROOT=pathlib.Path(__file__).resolve().parents[1]
checks=0
def check(ok, why):
    global checks
    if not ok: raise AssertionError(why)
    checks+=1
with tempfile.TemporaryDirectory(prefix='messenger-http-') as folder:
    with socket.socket() as sock:
        sock.bind(('127.0.0.1',0)); port=sock.getsockname()[1]
    env=dict(os.environ,MESSENGER_FIXTURE_DB='sqlite:'+folder+'/fixture.sqlite3')
    env.pop('PHP_CLI_SERVER_WORKERS',None)
    with open(folder+'/server.log','w+') as log:
        server=subprocess.Popen(['php','-S',f'127.0.0.1:{port}',str(ROOT/'tests/support/http-router.php')],cwd=ROOT,env=env,stdout=log,stderr=log)
        def request(path,body=None,browser=False,token=None,form=False):
            headers={'Host':'example.test'}
            if browser: headers['Cookie']='fixture_account=member'
            if token: headers['Authorization']='Bearer '+token
            data=None
            if body is not None:
                headers['Content-Type']='application/x-www-form-urlencoded' if form else 'application/json'
                data=urllib.parse.urlencode(body).encode() if form else json.dumps(body).encode()
            req=urllib.request.Request(f'http://127.0.0.1:{port}'+path,data=data,headers=headers)
            try:
                with urllib.request.urlopen(req,timeout=4) as r:return r.status,r.read().decode(),dict(r.headers)
            except urllib.error.HTTPError as e:return e.code,e.read().decode(),dict(e.headers)
        try:
            for _ in range(40):
                if server.poll() is not None: raise RuntimeError('Fixture server exited')
                try:
                    with socket.create_connection(('127.0.0.1',port),timeout=.1):break
                except OSError: time.sleep(.05)
            status,text,headers=request('/messenger-api/v1/session/',browser=True)
            session=json.loads(text)['result'];check(status==200 and 'csrf' in session,'web session')
            payload={'recipient_id':22,'body':'synthetic browser message','client_id':'http_browser_fixture_001'}
            status,text,_=request('/messenger-api/v1/start/',payload,browser=True);check(status==403,'browser POST without CSRF rejected')
            payload['fixture_csrf']='fixture_csrf_value';status,text,_=request('/messenger-api/v1/start/',payload,browser=True);check(status==200 and json.loads(text)['ok'],'valid browser CSRF still works')
            verifier='v'*64;data={'redirect_uri':'org.example.narzan://callback','state':'s'*32,'code_challenge_method':'S256','code_challenge':base64.urlsafe_b64encode(hashlib.sha256(verifier.encode()).digest()).decode().rstrip('='),'device_name':'HTTP fixture'}
            status,text,_=request('/messenger-mobile/v1/authorize/?'+urllib.parse.urlencode(data),browser=True);check(status==200 and 'Allow this device' in text,'consent GET');check('<html lang="en">' in text and 'name="viewport" content="width=device-width, initial-scale=1"' in text and 'aria-label="Allow this device"' in text,'mobile consent semantics')
            status,text,_=request('/messenger-mobile/v1/authorize/',data|{'approve':'yes'},browser=True,form=True);check(status==403,'consent CSRF mandatory')
            status,text,_=request('/messenger-mobile/v1/authorize/',data|{'approve':'yes','fixture_csrf':'fixture_csrf_value'},browser=True,form=True)
            check('role="link" aria-label="Return to Narzan"' in text and '<strong>Return to Narzan</strong>' in text and 'name="viewport"' in text,'mobile return accessibility')
            import re
            callback=html.unescape(re.search(r'href="([^"]+)"',text).group(1));query=urllib.parse.parse_qs(urllib.parse.urlparse(callback).query)
            status,text,headers=request('/messenger-mobile/v1/token/',{'code':query['code'][0],'code_verifier':verifier,'redirect_uri':data['redirect_uri']})
            tokens=json.loads(text);check(status==200 and 'access_token' in tokens,'native code exchange')
            check('no-store' in headers.get('Cache-Control',''),'tokens not cached');check(not any(k.lower()=='access-control-allow-origin' for k in headers),'no wildcard CORS')
            status,text,_=request('/messenger-api/v1/session/',token=tokens['access_token']);check(status==200 and 'csrf' not in json.loads(text)['result'],'native no browser CSRF')
            status,text,_=request('/messenger-api/v1/presence/',{'heartbeat':True},token=tokens['access_token']);check(status==200 and json.loads(text)['result']['status']=='online','heartbeat initializes online')
            status,text,_=request('/messenger-api/v1/presence/',{'status':'invisible','text':'Synthetic private status'},token=tokens['access_token']);check(status==200,'choose invisible')
            status,text,_=request('/messenger-api/v1/presence/',{'heartbeat':True},token=tokens['access_token']);presence=json.loads(text)['result'];check(status==200 and presence['status']=='invisible' and presence['text']=='Synthetic private status','heartbeat preserves latest preference')
            status,text,_=request('/messenger-api/v1/presence/',{'heartbeat':True,'status':'online'},token=tokens['access_token']);check(status==400,'heartbeat cannot overwrite preferences')
            status,text,_=request('/messenger-api/v1/presence/',{'heartbeat':'true'},token=tokens['access_token']);check(status==400,'heartbeat requires boolean true')

            status,text,_=request('/messenger-api/v1/session/',browser=True,token='invalid');check(status==400 and json.loads(text)['code']=='mixed_credentials','no cookie fallback')
            native_payload={'recipient_id':33,'body':'synthetic native message','client_id':'http_native_fixture_0001'}
            status,text,_=request('/messenger-api/v1/start/',native_payload,token=tokens['access_token']);check(status==200 and json.loads(text)['ok'],'valid native POST without CSRF')
            status,text,_=request('/messenger-mobile/v1/refresh/',{'refresh_token':tokens['refresh_token']});new=json.loads(text);check(status==200 and new['access_token']!=tokens['access_token'],'HTTP rotation')
            status,text,_=request('/messenger-api/v1/session/',token=tokens['access_token']);check(status==401,'old access expired after rotation')
            status,text,_=request('/messenger-mobile/v1/revoke/',{'refresh_token':new['refresh_token']});check(status==200,'HTTP device revoke')
            status,text,_=request('/messenger-api/v1/session/',token=new['access_token']);check(status==401,'revoked access denied')
        finally:
            server.terminate()
            try:server.wait(timeout=5)
            except subprocess.TimeoutExpired:server.kill();server.wait(timeout=5)
print(f'HTTP transport checks: {checks}; task-created server stopped')
