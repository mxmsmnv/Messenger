#!/usr/bin/env python3
"""Disposable PHP HTTP integration with fixture services; no real site/TLS/database."""
import base64, hashlib, html, json, os, pathlib, socket, subprocess, tempfile, time
import urllib.request, urllib.parse, urllib.error
ROOT=pathlib.Path(__file__).resolve().parents[1]
checks=0
class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self,req,fp,code,msg,headers,newurl): return None
opener=urllib.request.build_opener(NoRedirect())
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
                with opener.open(req,timeout=4) as r:return r.status,r.read().decode(),dict(r.headers)
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
            status,text,consent_headers=request('/messenger-mobile/v1/authorize/?'+urllib.parse.urlencode(data),browser=True);check(status==200 and 'Allow this device' in text,'consent GET');check('<html lang="en">' in text and 'name="viewport" content="width=device-width, initial-scale=1"' in text and 'aria-label="Allow this device"' in text,'mobile consent semantics');check("form-action 'self' org.example.narzan:" in consent_headers.get('Content-Security-Policy',''),'consent CSP permits only registered app scheme beside self')
            status,text,_=request('/messenger-mobile/v1/authorize/',data|{'approve':'yes'},browser=True,form=True);check(status==403,'consent CSRF mandatory')
            status,text,headers=request('/messenger-mobile/v1/authorize/',data|{'approve':'yes','fixture_csrf':'fixture_csrf_value'},browser=True,form=True)
            check('role="link" aria-label="Return to Narzan"' in text and '<strong>Return to Narzan</strong>' in text and 'name="viewport"' in text,'mobile return accessibility')
            import re
            callback=headers.get('Location','');check(status==303 and callback.startswith(data['redirect_uri']+'?'),'consent automatically redirects to exact registered app callback');check(html.unescape(re.search(r'href="([^"]+)"',text).group(1))==callback,'manual fallback matches redirect exactly');query=urllib.parse.parse_qs(urllib.parse.urlparse(callback).query)
            check(headers.get('Referrer-Policy')=='no-referrer' and 'no-store' in headers.get('Cache-Control',''),'completion is private and has no referrer')
            style=re.search(r'<style>(.*?)</style>',text,re.S).group(1)
            check('--bg:#26312d' in style and '--accent:#a8c785' in style and 'color-scheme:dark' in style,'Muted Narzan green is the default mobile identity style')
            check('prefers-color-scheme' not in style and all(color not in style for color in ['#f0efeb','#315fd1','#4a7ff8']),'mobile identity has no alternate Niva palette')
            style_hash=base64.b64encode(hashlib.sha256(style.encode()).digest()).decode()
            csp=headers.get('Content-Security-Policy','')
            check("style-src 'sha256-"+style_hash+"'" in csp and "script-src 'none'" in csp and "default-src 'none'" in csp and "form-action 'self'" in csp and "frame-ancestors 'none'" in csp and "base-uri 'none'" in csp and "unsafe-inline" not in csp,'only exact bundled CSS is allowed by CSP');check("form-action 'self' org.example.narzan:" in csp and 'https:' not in csp and '*' not in csp,'completion CSP has no wildcard or general network form target')
            visible=html.unescape(re.sub(r'<[^>]+>','',text))
            check('Authorization approved' not in visible and query['code'][0] not in visible and data['state'] not in visible and data['redirect_uri'] not in visible,'no technical success page or visible authorization values')
            check('<script' not in text.lower() and '@import' not in style and 'url(' not in style and 'src=' not in text.lower(),'completion uses no scripts or external assets')
            denied_status,denied_text,denied_headers=request('/messenger-mobile/v1/authorize/',data|{'redirect_uri':'https://invalid.example/callback','approve':'yes','fixture_csrf':'fixture_csrf_value'},browser=True,form=True)
            check(denied_status==400 and 'Location' not in denied_headers,'unregistered callback never redirects')
            escaped_status,escaped_form,_=request('/messenger-mobile/v1/authorize/?'+urllib.parse.urlencode(data|{'device_name':'<img src=x onerror=alert(1)>'}),browser=True)
            check(escaped_status==200 and '<img' not in escaped_form and '&lt;img' in escaped_form,'untrusted device label is escaped')
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
            # Real HTTP dispatch keeps metadata GET private, query-strict and unavailable for pending requests.
            started=json.loads(text)['result'];message_id=started['message']['id']
            status,text,meta_headers=request('/messenger-api/v1/message-info/?message_id='+str(message_id),token=tokens['access_token'])
            check(status==403 and not json.loads(text)['ok'],'pending native message has no information disclosure')
            status,text,_=request('/messenger-api/v1/message-info/?message_id=9999999',token=tokens['access_token'])
            check(status==403 and not json.loads(text)['ok'],'absent metadata uses same unavailable HTTP status')
            for invalid in ['0','-1','01','1x','1.2','9223372036854775808','%5B1%5D']:
                status,text,_=request('/messenger-api/v1/message-info/?message_id='+invalid,token=tokens['access_token'])
                check(status==400 and not json.loads(text)['ok'],'metadata HTTP ID parsing is strict')
            status,text,_=request('/messenger-api/v1/message-info/?message_id='+str(message_id),{},token=tokens['access_token'])
            check(status==405 and not json.loads(text)['ok'],'metadata HTTP POST is not a write path')
            check('no-store' in meta_headers.get('Cache-Control','') and not any(key.lower()=='access-control-allow-origin' for key in meta_headers),'metadata HTTP response remains private without CORS')
            status,text,_=request('/messenger-mobile/v1/refresh/',{'refresh_token':tokens['refresh_token']});new=json.loads(text);check(status==200 and new['access_token']!=tokens['access_token'],'HTTP rotation')
            status,text,_=request('/messenger-api/v1/session/',token=tokens['access_token']);check(status==401,'old access expired after rotation')
            status,text,_=request('/messenger-mobile/v1/revoke/',{'refresh_token':new['refresh_token']});check(status==200,'HTTP device revoke')
            status,text,_=request('/messenger-api/v1/session/',token=new['access_token']);check(status==401,'revoked access denied')
        finally:
            server.terminate()
            try:server.wait(timeout=5)
            except subprocess.TimeoutExpired:server.kill();server.wait(timeout=5)
print(f'HTTP transport checks: {checks}; task-created server stopped')
