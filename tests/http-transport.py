#!/usr/bin/env python3
"""Disposable PHP HTTP integration with fixture services; no real site/TLS/database."""
import base64, hashlib, html, json, os, pathlib, socket, sqlite3, subprocess, tempfile, time
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
    env=dict(os.environ,MESSENGER_FIXTURE_DB='sqlite:'+folder+'/fixture.sqlite3',MESSENGER_FIXTURE_TOOLS='1')
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
            check('--bg:#242629' in style and '--accent:#f4f4f5' in style and '--brand:#ffffff' in style and 'color-scheme:dark' in style,'Neutral graphite and inverse mark are the mobile identity style')
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
            # Explicit schema setup on this disposable fixture only, never a live site.
            status,text,_=request('/messenger-api/v1/call-config/',token=tokens['access_token'])
            check(status==200 and json.loads(text)['result']['feedback_enabled'] is False,'old HTTP schema feature detection is false')
            setup="require 'tests/support/bootstrap.php'; [$m]=\\ProcessWire\\fixture(); $a=new class(1) extends \\ProcessWire\\User {public function isSuperuser(){return true;}}; $m->installExperimentalCalls($a);"
            subprocess.run(['php','-r',setup],cwd=ROOT,env=env,check=True,capture_output=True,timeout=10)
            with sqlite3.connect(folder+'/fixture.sqlite3') as seed:
                grant=seed.execute('SELECT id FROM messenger_mobile_grants WHERE user_id=11').fetchone()[0]
                call_id='12345678-abcd-4abc-8abc-123456789abc'; now=int(time.time())
                seed.execute("INSERT INTO messenger_calls VALUES(?,?,?,?,?,?,?,'ended',2,?,?,?,?)",(call_id,'http_call_fixture_0001',123,11,22,grant,'b'*32,now,now+3600,now,now))
                for sequence,kind in enumerate(['offer','answer'],1):
                    seed.execute('INSERT INTO messenger_call_signals VALUES(?,?,?,?,?,?,?)',(call_id,sequence,grant,'http_signal_00000'+str(sequence),kind,'not-read',now))
            status,text,_=request('/messenger-api/v1/call-config/',token=tokens['access_token'])
            check(status==200 and json.loads(text)['result']['feedback_enabled'] is True and json.loads(text)['result']['enabled'] is False,'HTTP feedback available independently of disabled calls')
            for resource in ['call-config','call-feedback']:
                status,text,_=request('/messenger-api/v1/'+resource+'/',browser=True)
                check(status==403,'browser session cannot invoke '+resource)
            feedback={'id':call_id,'stars':4,'issues':['echo'],'consent':True}
            status,text,feedback_headers=request('/messenger-api/v1/call-feedback/',feedback,token=tokens['access_token'])
            check(status==200 and json.loads(text)['result']=={'saved':True},'native consented HTTP feedback accepted')
            check('no-store' in feedback_headers.get('Cache-Control',''),'feedback response never cached')
            status,text,_=request('/messenger-api/v1/call-feedback/',feedback,token=tokens['access_token'])
            check(status==200,'HTTP feedback retry idempotent')
            status,text,_=request('/messenger-api/v1/call-feedback/',feedback|{'stars':1},token=tokens['access_token'])
            check(status==409 and json.loads(text)['code']=='idempotency_conflict','changed HTTP feedback retry rejected')
            status,text,_=request('/messenger-api/v1/call-feedback/',feedback|{'consent':False},token=tokens['access_token'])
            check(status==400,'HTTP feedback requires explicit boolean consent')
            # Explicitly prepare only the owned synthetic database; never mutate a live site's policy.
            cid=started['conversation']['id']
            with sqlite3.connect(folder+'/fixture.sqlite3') as fixture_db:
                fixture_db.execute("UPDATE messenger_conversations SET request_state='accepted' WHERE id=?",(cid,))
            status,text,_=request('/messenger-api/v1/messages/?id='+str(cid),token=tokens['access_token'])
            selected_message=next(m for m in json.loads(text)['result'] if m['id']==message_id)
            forward_payload={'from_id':cid,'to_id':cid,'message_ids':[message_id],'source_versions':{str(message_id):selected_message['content_revision']},'client_id':'http_forward_operation_01'}
            status,text,_=request('/messenger-api/v1/forward/',forward_payload,browser=True);check(status==403,'web forwarding still requires CSRF')
            status,text,_=request('/messenger-api/v1/forward/',forward_payload,token=tokens['access_token']);forwarded=json.loads(text)['result'];check(status==200 and forwarded['messages'][0]['forwarded'],'native HTTP forward accepted with frozen revision')
            status,text,_=request('/messenger-api/v1/forward/',forward_payload,token=tokens['access_token']);check(json.loads(text)['result']['messages'][0]['id']==forwarded['messages'][0]['id'],'HTTP retry returns original independent copy')
            forward_payload['client_id']='http_forward_browser_02';forward_payload['fixture_csrf']='fixture_csrf_value'
            status,text,_=request('/messenger-api/v1/forward/',forward_payload,browser=True);check(status==200 and json.loads(text)['result']['messages'][0]['id']!=forwarded['messages'][0]['id'],'valid browser forwarding uses existing session CSRF boundary')
            forward_payload['source_versions']={str(message_id):'0'*64};forward_payload['client_id']='http_forward_changed_03'
            status,text,_=request('/messenger-api/v1/forward/',forward_payload,token=tokens['access_token']);check(status==409 and json.loads(text)['code']=='message_changed','HTTP stale selection is a typed conflict')
            status,text,_=request('/messenger-api/v1/devices/',token=tokens['access_token'])
            devices=json.loads(text)['result'];check(status==200 and len(devices['items'])==1 and devices['items'][0]['current'],'HTTP own devices and current grant')
            check(tokens['access_token'] not in text and tokens['refresh_token'] not in text,'HTTP device metadata omits credentials')
            status,text,_=request('/messenger-api/v1/devices/',browser=True);check(status==403,'native device API never falls back to browser authority')
            def extra_device():
                status,_,headers=request('/messenger-mobile/v1/authorize/',data|{'approve':'yes','fixture_csrf':'fixture_csrf_value','device_name':'Second HTTP fixture'},browser=True,form=True)
                code=urllib.parse.parse_qs(urllib.parse.urlparse(headers['Location']).query)['code'][0]
                status,text,_=request('/messenger-mobile/v1/token/',{'code':code,'code_verifier':verifier,'redirect_uri':data['redirect_uri']})
                check(status==200,'HTTP extra device grant');return json.loads(text)
            extra=extra_device()
            status,text,_=request('/messenger-api/v1/revoke-other-devices/',{'keep_connection_id':extra['connection_id']},token=tokens['access_token'])
            check(status==200 and json.loads(text)['result']['current_revoked'] is False,'bulk exemption derives from bearer, not supplied body')
            status,_,_=request('/messenger-api/v1/session/',token=extra['access_token']);check(status==401,'other-device HTTP access revoked')
            status,_,_=request('/messenger-api/v1/session/',token=tokens['access_token']);check(status==200,'current HTTP connection survives bulk revoke')
            extra=extra_device()
            status,text,_=request('/messenger-api/v1/revoke-device/',{'connection_id':extra['connection_id']},token=extra['access_token'])
            check(status==200 and json.loads(text)['result']['current_revoked'] is True,'single HTTP revoke acknowledges own current connection')
            status,_,_=request('/messenger-api/v1/session/',token=extra['access_token']);check(status==401,'self-revoked HTTP access denied')
            status,text,_=request('/messenger-mobile/v1/refresh/',{'refresh_token':tokens['refresh_token']});new=json.loads(text);check(status==200 and new['access_token']!=tokens['access_token'],'HTTP rotation')
            status,text,_=request('/messenger-api/v1/session/',token=tokens['access_token']);check(status==401,'old access expired after rotation')
            status,text,_=request('/messenger-mobile/v1/revoke/',{'refresh_token':new['refresh_token']});check(status==200,'HTTP device revoke')
            status,text,_=request('/messenger-api/v1/session/',token=new['access_token']);check(status==401,'revoked access denied')
        finally:
            server.terminate()
            try:server.wait(timeout=5)
            except subprocess.TimeoutExpired:server.kill();server.wait(timeout=5)
print(f'HTTP transport checks: {checks}; task-created server stopped')
