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
    env=dict(os.environ,MESSENGER_FIXTURE_DB='sqlite:'+folder+'/fixture.sqlite3',MESSENGER_FIXTURE_TOOLS='1',MESSENGER_FIXTURE_SAVED='1',MESSENGER_FIXTURE_POLLS='1')
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
            event_chat=started['conversation']['id']
            with sqlite3.connect(folder+'/fixture.sqlite3') as seed:
                event_policy=seed.execute("SELECT request_state FROM messenger_conversations WHERE id=?",(event_chat,)).fetchone()[0]
                seed.execute("UPDATE messenger_conversations SET request_state='accepted' WHERE id=?",(event_chat,))
                seed.execute("UPDATE messenger_calls SET conversation_id=?,callee_id=33 WHERE id=?",(event_chat,call_id))
            status,text,event_headers=request('/messenger-api/v1/call-events/?id='+str(event_chat),token=tokens['access_token'])
            check(status==200 and len(json.loads(text)['result']['items'])==1,'native history reads own real call row while calls are disabled')
            check('no-store' in event_headers.get('Cache-Control',''),'call events remain private')
            status,text,_=request('/messenger-api/v1/call-events/?id='+str(event_chat),browser=True)
            check(status==403,'browser cannot invoke native call events')
            status,text,_=request('/messenger-api/v1/call-events/?id='+str(event_chat),{},token=tokens['access_token'])
            check(status==405,'call event history is read-only')
            for invalid in ['', '0','-1','1x','99999999999999999999999999']:
                status,text,_=request('/messenger-api/v1/call-events/?id='+invalid,token=tokens['access_token'])
                check(status==400,'call event conversation ID parsing is strict')
            status,text,_=request('/messenger-api/v1/call-events/?id=999999',token=tokens['access_token'])
            check(status==403,'call events do not expose a foreign conversation')
            with sqlite3.connect(folder+'/fixture.sqlite3') as seed:
                seed.execute('UPDATE messenger_calls SET conversation_id=123,callee_id=22 WHERE id=?',(call_id,))
                seed.execute('UPDATE messenger_conversations SET request_state=? WHERE id=?',(event_policy,event_chat))
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
            reaction_payload={'message_id':message_id,'emoji':'👍','user_id':33}
            status,text,_=request('/messenger-api/v1/reaction/',reaction_payload,token=tokens['access_token']);reaction=json.loads(text)['result']['reactions'][0];check(status==200 and reaction['mine'] and reaction['users'][0]['id']==11,'reaction actor is the Bearer grant owner, never body user_id')
            status,text,_=request('/messenger-api/v1/reaction/',reaction_payload,token=tokens['access_token']);check(json.loads(text)['result']['reactions'][0]['count']==1,'same desired emoji is idempotent over HTTP')
            status,text,_=request('/messenger-api/v1/reaction/',{'message_id':message_id,'emoji':None},browser=True);check(status==403,'web reaction still requires CSRF')
            status,text,_=request('/messenger-api/v1/reaction/',{'message_id':message_id,'emoji':None,'fixture_csrf':'fixture_csrf_value'},browser=True);check(status==200 and not json.loads(text)['result']['reactions'],'browser can explicitly clear own reaction with CSRF')
            status,text,_=request('/messenger-api/v1/reaction/',{'message_id':message_id,'emoji':['👍']},token=tokens['access_token']);check(status==400,'reaction payload requires nullable string, not array')
            status,text,_=request('/messenger-api/v1/pin-chat/',{'id':cid,'pinned':True},browser=True);check(status==403,'browser chat pin requires CSRF')
            status,text,_=request('/messenger-api/v1/pin-chat/',{'id':cid,'pinned':True,'user_id':33},token=tokens['access_token']);check(status==200 and json.loads(text)['result']['pinned'],'native pin actor is grant-derived')
            status,text,_=request('/messenger-api/v1/inbox/?scope=inbox&include_pins=1',token=tokens['access_token']);inbox=json.loads(text)['result'];check(status==200 and inbox['pinned_items'][0]['id']==cid,'explicit first-page pin prefill preserves inbox result')
            status,text,_=request('/messenger-api/v1/pin-message/',{'id':cid,'message_id':message_id,'pinned':True,'scope':'personal'},token=tokens['access_token']);check(status==200 and json.loads(text)['result']['scope']=='personal','message pin defaults to explicitly selected private scope')
            status,text,_=request('/messenger-api/v1/pins/?id='+str(cid),token=tokens['access_token']);pins=json.loads(text)['result'];check(status==200 and len(pins['items'])==1 and pins['items'][0]['message']['id']==message_id,'authorized pin read returns actual original')
            status,text,_=request('/messenger-api/v1/search-history/?id='+str(cid)+'&q=synthetic',token=tokens['access_token']);history=json.loads(text)['result'];check(status==200 and message_id in [row['id'] for row in history['items']] and history['complete'],'history search reads original under bearer ownership')
            status,text,_=request('/messenger-api/v1/search-history/?id=99999999&q=synthetic',token=tokens['access_token']);check(status==404,'history search cannot enumerate inaccessible conversation')
            status,text,_=request('/messenger-api/v1/search-history/?id='+str(cid)+'&q=x',token=tokens['access_token']);check(status==400,'history query minimum validated over HTTP')
            status,text,_=request('/messenger-api/v1/search-history/?id='+str(cid)+'&q=synthetic&before=-1',token=tokens['access_token']);check(status==400,'history cursor strictly positive over HTTP')
            status,text,_=request('/messenger-api/v1/search-history/?id='+str(cid)+'&q=synthetic',{},token=tokens['access_token']);check(status==405,'history search accepts read method only')
            status,text,_=request('/messenger-api/v1/activity/?id='+str(cid),token=tokens['access_token']);check(status==200 and json.loads(text)['result']['items']==[],'native activity read derives current actor')
            status,text,_=request('/messenger-api/v1/activity/',{'id':cid,'kind':'typing','user_id':33,'body':'ignored synthetic draft'},token=tokens['access_token']);check(status==200 and json.loads(text)['result']=={'updated':True},'native activity accepts only typed metadata result')
            status,text,_=request('/messenger-api/v1/activity/',{'id':cid,'kind':'bad'},token=tokens['access_token']);check(status==400,'activity kind enum validated')
            status,text,_=request('/messenger-api/v1/activity/',{'id':cid,'kind':[]},token=tokens['access_token']);check(status==400,'activity kind must be string')
            status,text,_=request('/messenger-api/v1/activity/?id=99999999',token=tokens['access_token']);check(status==404,'activity cannot enumerate inaccessible conversation')
            status,text,_=request('/messenger-api/v1/activity/',{'id':cid,'kind':'recording'},browser=True);check(status==403,'browser activity writes require CSRF')
            status,text,_=request('/messenger-api/v1/activity/',{'id':cid,'kind':'none','fixture_csrf':'fixture_csrf_value'},browser=True);check(status==200 and json.loads(text)['result']['updated'],'browser stop uses normal CSRF transport')
            status,text,_=request('/messenger-api/v1/activity/?id='+str(cid),browser=True);check(status==200 and json.loads(text)['result']['items']==[],'browser activity read stays authorized')
            status,text,_=request('/messenger-api/v1/media-catalog/?id='+str(cid)+'&category=documents',token=tokens['access_token']);catalog=json.loads(text)['result'];check(status==200 and catalog['category']=='documents' and catalog['complete'] and catalog['items']==[],'native media catalog follows authenticated history')
            status,text,_=request('/messenger-api/v1/media-catalog/?id=99999999&category=links',token=tokens['access_token']);check(status==404,'catalog cannot enumerate inaccessible conversation')
            status,text,_=request('/messenger-api/v1/media-catalog/?id='+str(cid)+'&category=invalid',token=tokens['access_token']);check(status==400,'catalog category validated over HTTP')
            status,text,_=request('/messenger-api/v1/media-catalog/?id='+str(cid)+'&category=links&before=0',token=tokens['access_token']);check(status==400,'catalog cursor strictly positive')
            status,text,_=request('/messenger-api/v1/media-catalog/?id='+str(cid)+'&category=links&q=x',token=tokens['access_token']);check(status==400,'catalog query minimum validated')
            status,text,_=request('/messenger-api/v1/media-catalog/?id='+str(cid)+'&category=links&q[]=x',token=tokens['access_token']);check(status==400,'catalog rejects nonstring query instead of expanding to all history')
            status,text,_=request('/messenger-api/v1/media-catalog/?id='+str(cid)+'&category=links',{},token=tokens['access_token']);check(status==405,'catalog accepts only read method')
            status,text,_=request('/messenger-api/v1/media-catalog/?id='+str(cid)+'&category=links',browser=True);check(status==200 and json.loads(text)['result']['category']=='links','browser catalog shares authorized history')
            status,text,_=request('/messenger-api/v1/saved-messages/',{'user_id':33},token=tokens['access_token']);personal=json.loads(text)['result'];sid=personal['id'];check(status==200 and personal['type']=='saved' and personal['other_user']['id']==11,'Saved actor is bearer owner, never supplied user id')
            status,text,_=request('/messenger-api/v1/saved-messages/',{},token=tokens['access_token']);check(json.loads(text)['result']['id']==sid,'HTTP Saved opening is idempotent')
            status,text,_=request('/messenger-api/v1/inbox/',token=tokens['access_token']);check(sid not in [c['id'] for c in json.loads(text)['result']['items']],'legacy inbox excludes saved kind')
            status,text,_=request('/messenger-api/v1/inbox/?include_saved=1',token=tokens['access_token']);check(sid in [c['id'] for c in json.loads(text)['result']['items']],'new client explicitly opts into saved kind')
            status,text,_=request('/messenger-api/v1/saved-messages/',{},browser=True);check(status==403,'web Saved mutation requires CSRF')
            status,text,_=request('/messenger-api/v1/saved-messages/',{'fixture_csrf':'fixture_csrf_value'},browser=True);check(status==200 and json.loads(text)['result']['id']==sid,'browser Saved shares account history with native connection')
            status,text,_=request('/messenger-api/v1/saved-messages/',token=tokens['access_token']);check(status==405,'Saved explicit opening requires mutation method')


            status,text,_=request('/messenger-api/v1/pin-message/',{'id':cid,'message_id':message_id,'pinned':True,'scope':'someone-else'},token=tokens['access_token']);check(status==400,'scope cannot impersonate another member')
            status,text,_=request('/messenger-api/v1/pin-message/',{'id':cid,'message_id':message_id,'pinned':False,'scope':'personal','fixture_csrf':'fixture_csrf_value'},browser=True);check(status==200 and not json.loads(text)['result']['pinned'],'valid web CSRF can clear only own personal pin')
            # Immutable native operation acceptance is distinct from current visibility.
            ack_payload={'id':sid,'body':'Original acknowledgement text','client_id':'http_write_ack_000001'}
            status,text,_=request('/messenger-api/v1/send/',ack_payload,token=tokens['access_token']);ack=json.loads(text)['result'];ack_id=ack['id']
            check(status==200 and ack['accepted_client_id']==ack_payload['client_id'] and ack['ack_visible'] is True,'native write returns authoritative operation correlation')
            script="namespace ProcessWire;require 'tests/support/bootstrap.php';[$m,$db,$a]=fixture();$m->set('saved_messages_enabled',1)->set('edit_window_minutes',60);$m->editMessage("+str(ack_id)+",$a,'Edited elsewhere');"
            subprocess.run(['php','-r',script],cwd=ROOT,env=env,check=True,timeout=10)
            status,text,_=request('/messenger-api/v1/send/',ack_payload,token=tokens['access_token']);edited=json.loads(text)['result']
            check(status==200 and edited['id']==ack_id and edited['body']=='Edited elsewhere' and edited['ack_visible'] is True,'HTTP edited replay confirms original immutable body without duplicate')
            status,text,_=request('/messenger-api/v1/send/',ack_payload|{'body':'Changed request'},token=tokens['access_token']);check(status==409,'HTTP correlation cannot acknowledge a changed original request')
            status,text,_=request('/messenger-api/v1/messages/?id='+str(sid),token=tokens['access_token']);check(status==200 and 'accepted_client_id' not in text and 'ack_visible' not in text,'read history excludes write transport correlation')
            script="namespace ProcessWire;require 'tests/support/bootstrap.php';[$m,$db,$a]=fixture();$m->set('saved_messages_enabled',1);$m->hideMessage("+str(ack_id)+",$a);"
            subprocess.run(['php','-r',script],cwd=ROOT,env=env,check=True,timeout=10)
            status,text,_=request('/messenger-api/v1/send/',ack_payload,token=tokens['access_token']);hidden=json.loads(text)['result']
            check(status==200 and hidden=={'id':ack_id,'conversation_id':sid,'sender_user_id':11,'body':'','attachment':None,'accepted_client_id':ack_payload['client_id'],'ack_visible':False},'HTTP hidden replay redacts content and confirms only the accepted identity')
            status,text,_=request('/messenger-api/v1/send/',{'id':sid,'body':'Browser unchanged','client_id':'http_web_ack_000001','fixture_csrf':'fixture_csrf_value'},browser=True)
            check(status==200 and 'accepted_client_id' not in text and 'ack_visible' not in text,'browser write response remains unchanged')
            status,text,_=request('/messenger-api/v1/session/',token=tokens['access_token']);check(status==200 and json.loads(text)['result']['silent_messages_supported'] is True,'explicit tools expose a Boolean notification-mode capability')
            quiet={'id':cid,'filename':'quiet-photo.png','mime_type':'image/png','kind':'file','data_base64':'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jp1sAAAAASUVORK5CYII=','client_id':'http_quiet_photo_0001','silent':True}
            status,text,_=request('/messenger-api/v1/send-attachment/',quiet,token=tokens['access_token']);quiet_ack=json.loads(text)['result'];quiet_id=quiet_ack['id']
            check(status==200 and quiet_ack['silent'] is True and quiet_ack['accepted_silent'] is True and quiet_ack['accepted_client_id']==quiet['client_id'],'native photo receipt confirms exact suppressed notification intent')
            status,text,_=request('/messenger-api/v1/send-attachment/',quiet,token=tokens['access_token']);check(status==200 and json.loads(text)['result']['id']==quiet_id,'quiet native photo retry is unique')
            status,text,_=request('/messenger-api/v1/send-attachment/',quiet|{'silent':False},token=tokens['access_token']);check(status==409,'reusing quiet operation ID with another mode conflicts')
            for invalid in ['true',None,[]]:
                status,text,_=request('/messenger-api/v1/send-attachment/',quiet|{'silent':invalid},token=tokens['access_token']);check(status==400,'notification mode requires strict JSON Boolean')
            status,text,_=request('/messenger-api/v1/messages/?id='+str(cid),token=tokens['access_token']);rows=json.loads(text)['result']
            check(status==200 and next(row for row in rows if row['id']==quiet_id)['silent'] is True and 'accepted_silent' not in text,'authorized history carries mode but no write-only proof')
            status,text,_=request('/messenger-api/v1/send-attachment/',quiet|{'client_id':'http_quiet_web_photo_01'},browser=True);check(status==403,'quiet browser write still requires CSRF')
            status,text,_=request('/messenger-api/v1/send-attachment/',quiet|{'client_id':'http_quiet_web_photo_01','fixture_csrf':'fixture_csrf_value'},browser=True);check(status==200 and json.loads(text)['result']['silent'] is True and 'accepted_silent' not in text,'browser photo honors explicit mode without native-only receipt fields')
            status,text,_=request('/messenger-api/v1/devices/',token=tokens['access_token'])
            devices=json.loads(text)['result'];check(status==200 and len(devices['items'])==1 and devices['items'][0]['current'],'HTTP own devices and current grant')
            check(tokens['access_token'] not in text and tokens['refresh_token'] not in text,'HTTP device metadata omits credentials')
            status,text,_=request('/messenger-api/v1/group-create/',{'title':'Weekend poll','user_ids':[22],'client_id':'http_poll_group_0001'},token=tokens['access_token']);check(status==200,'HTTP group creation for poll');gid=json.loads(text)['result']['id']
            script="namespace ProcessWire;require 'tests/support/bootstrap.php';[$m,$db,$a,$b]=fixture();$operator=new class(99)extends User{public function isSuperuser(){return true;}};$m->installGroups($operator);$m->set('groups_enabled',1);$m->respondGroupInvitation("+str(gid)+",$b,true);"
            subprocess.run(['php','-r',script],cwd=ROOT,env=env,check=True,timeout=10)
            payload={'id':gid,'question':'Where?','options':['Harbour','Park'],'client_id':'http_poll_create_001'}
            status,text,_=request('/messenger-api/v1/group-poll-create/',payload,token=tokens['access_token']);result=json.loads(text)['result'];pmid=result['message_id'];check(status==200 and result['ack_visible'] and result['accepted_client_id']==payload['client_id'] and result['poll']['counts']==[0,0],'HTTP create poll and correlated receipt')
            status,text,_=request('/messenger-api/v1/group-poll-create/',payload,token=tokens['access_token']);check(status==200 and json.loads(text)['result']['message_id']==pmid,'HTTP poll retry unique')
            status,text,_=request('/messenger-api/v1/group-poll-create/',payload|{'options':['Changed','Park']},token=tokens['access_token']);check(status==409,'HTTP changed poll retry conflicts')
            status,text,_=request('/messenger-api/v1/group-poll-vote/',{'message_id':pmid,'choice':1},token=tokens['access_token']);check(status==200 and json.loads(text)['result']['counts']==[0,1],'HTTP poll vote')
            status,text,_=request('/messenger-api/v1/group-poll/?message_id='+str(pmid),token=tokens['access_token']);check(status==200 and json.loads(text)['result']['own_choice']==1 and 'voters' not in json.loads(text)['result'],'HTTP authorized aggregate and own vote')
            status,text,_=request('/messenger-api/v1/group-poll-vote/',{'message_id':pmid,'choice':True},token=tokens['access_token']);check(status==400,'HTTP Boolean cannot become a choice index')
            status,text,_=request('/messenger-api/v1/group-poll-vote/',{'message_id':pmid,'choice':0},browser=True);check(status==403,'browser poll vote needs CSRF')
            status,text,_=request('/messenger-api/v1/group-poll-vote/',{'message_id':pmid,'choice':0,'fixture_csrf':'fixture_csrf_value'},browser=True);check(status==200 and json.loads(text)['result']['counts']==[1,0],'browser exact-state vote with CSRF')
            status,text,_=request('/messenger-api/v1/group-poll-close/',{'message_id':pmid},token=tokens['access_token']);check(status==200 and json.loads(text)['result']['closed_at'] is not None,'HTTP poll close')
            status,text,_=request('/messenger-api/v1/group-poll-vote/',{'message_id':pmid,'choice':1},token=tokens['access_token']);check(status==403,'HTTP closed poll rejects changed vote')
            link_body={'id':gid,'expires_in':86400,'max_uses':5,'client_id':'http_group_link_001'}
            status,text,_=request('/messenger-api/v1/group-link-create/',link_body,token=tokens['access_token']);link=json.loads(text)['result']['link'];check(status==200 and link['active'] and len(link['token'])==43,'HTTP explicit bounded invitation link')
            status,text,_=request('/messenger-api/v1/group-link-create/',link_body,token=tokens['access_token']);check(status==200 and json.loads(text)['result']['link']['id']==link['id'],'HTTP invitation creation retry is stable')
            status,text,_=request('/messenger-api/v1/group-link-create/',link_body|{'max_uses':6},token=tokens['access_token']);check(status==409,'HTTP changed link operation conflicts')
            status,text,_=request('/messenger-api/v1/group-link-preview/',{'token':link['token']},token=tokens['access_token']);check(status==200 and json.loads(text)['result']['is_member'] and 'token' not in json.loads(text)['result'],'HTTP authenticated explicit preview')
            status,text,_=request('/messenger-api/v1/group-link-preview/?token='+link['token'],token=tokens['access_token']);check(status>=400,'invitation token is never accepted in GET query')
            status,text,_=request('/messenger-api/v1/group-link-join/',{'token':link['token']},browser=True);check(status==403,'browser invitation join requires CSRF')
            status,text,_=request('/messenger-api/v1/group-link-join/',{'token':link['token'],'fixture_csrf':'fixture_csrf_value'},browser=True);check(status==200 and json.loads(text)['result']['id']==gid,'browser explicit join respects existing membership')
            status,text,_=request('/messenger-api/v1/group-link-create/',link_body|{'expires_in':True,'client_id':'http_link_invalid_001'},token=tokens['access_token']);check(status==400,'Boolean is not an invitation lifetime')
            status,text,_=request('/messenger-api/v1/group-link-revoke/',{'id':gid,'link_id':link['id']},token=tokens['access_token']);check(status==200 and json.loads(text)['result']['revoked'],'HTTP exact invitation revocation')
            status,text,_=request('/messenger-api/v1/group-link-status/?id='+str(gid),token=tokens['access_token']);check(status==200 and json.loads(text)['result']['link']['token'] is None,'HTTP revoked invitation does not reveal credential')

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
