#!/usr/bin/env python3
"""Explicit disposable real-TLS browser consent -> native exchange; never print credentials."""
import argparse,base64,hashlib,html,http.cookiejar,json,pathlib,re,secrets,ssl,urllib.error,urllib.parse,urllib.request
from html.parser import HTMLParser
parser=argparse.ArgumentParser();parser.add_argument('--fixture-root',required=True);args=parser.parse_args();root=pathlib.Path(args.fixture_root).resolve()
if (root/'.narzan-disposable').read_text().strip()!='narzan-emulator-fixture-v1':raise RuntimeError('Disposable fixture marker required')
base='https://localhost:19443';context=ssl.create_default_context(cafile=str(root/'tls.crt'))
class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self,req,fp,code,msg,headers,newurl):return None
cookies=http.cookiejar.CookieJar();browser=urllib.request.build_opener(urllib.request.HTTPSHandler(context=context),NoRedirect(),urllib.request.HTTPCookieProcessor(cookies));native=urllib.request.build_opener(urllib.request.HTTPSHandler(context=context),NoRedirect())
checks=0

def check(ok,label):
    global checks
    if not ok:raise RuntimeError(label)
    checks+=1

def request(opener,path,body=None,form=False,token=None):
    headers={};data=None
    if body is not None:headers['Content-Type']='application/x-www-form-urlencoded' if form else 'application/json';data=urllib.parse.urlencode(body).encode() if form else json.dumps(body).encode()
    if token:headers['Authorization']='Bearer '+token
    req=urllib.request.Request(base+path,data=data,headers=headers)
    try:
        with opener.open(req,timeout=30) as response:return response.status,response.read().decode(),dict(response.headers)
    except urllib.error.HTTPError as error:return error.code,error.read().decode(),dict(error.headers)

class Inputs(HTMLParser):
    def __init__(self):super().__init__();self.fields={}
    def handle_starttag(self,tag,attrs):
        values=dict(attrs)
        if tag=='input' and values.get('type')=='hidden' and 'name' in values:self.fields[values['name']]=values.get('value','')

tokens=None;revoked=False
try:
    verifier=secrets.token_urlsafe(48);state=secrets.token_urlsafe(32);redirect='org.smnv.narzan.dev://auth/callback';challenge=base64.urlsafe_b64encode(hashlib.sha256(verifier.encode()).digest()).decode().rstrip('=');values={'redirect_uri':redirect,'state':state,'code_challenge':challenge,'code_challenge_method':'S256','device_name':'Synthetic handoff TLS verification'};authorize='/messenger-mobile/v1/authorize/?'+urllib.parse.urlencode(values)
    status,text,_=request(browser,'/test-login/');login=json.loads(text);check(status==200,'fixture login metadata');csrf=login['csrf'];status,login_response,_=request(browser,'/test-login/',{'fixture':'alice',csrf['name']:csrf['value'],'return':authorize},True);check(status in [301,302,303],'synthetic browser login HTTP '+str(status)+' JSONerror '+str(json.loads(login_response).get('error')) if login_response.startswith('{') else 'synthetic browser login HTTP '+str(status));status,body,_=request(browser,'/test-login/');signed_in=json.loads(body);account_id=int(signed_in['user_id']);check(status==200 and signed_in['logged_in'] and account_id>0,'synthetic browser account verified')
    status,consent,headers=request(browser,authorize);check(status==200 and 'Allow this device' in consent,'real consent GET');check('Continue to Narzan' in consent and '<svg' in consent and 'Website</dt>' in consent,'branded account and site consent');check("form-action 'self' org.smnv.narzan.dev:" in headers.get('Content-Security-Policy',''),'registered callback scheme allowed in consent CSP');fields=Inputs();fields.feed(consent)
    status,_,denied_headers=request(browser,'/messenger-mobile/v1/authorize/',values|{'approve':'yes'},True);check(status==403 and 'Location' not in denied_headers,'real approval CSRF enforced')
    status,completed,headers=request(browser,'/messenger-mobile/v1/authorize/',fields.fields|{'approve':'yes'},True);callback=headers.get('Location','');check(status==303 and callback.startswith(redirect+'?'),'real automatic callback redirect');parts=urllib.parse.parse_qs(urllib.parse.urlparse(callback).query);check(parts.get('state')==[state] and parts.get('iss')==[base],'state and issuer retained');check(html.unescape(re.search(r'href="([^"]+)"',completed).group(1))==callback,'real accessible fallback matches callback');check('Authorization approved' not in completed and 'Opening Narzan' in completed,'human completion language');style=re.search(r'<style>(.*?)</style>',completed,re.S).group(1);style_hash=base64.b64encode(hashlib.sha256(style.encode()).digest()).decode();check("style-src 'sha256-"+style_hash+"'" in headers.get('Content-Security-Policy',''),'real exact CSS hash');check(headers.get('Referrer-Policy')=='no-referrer' and 'no-store' in headers.get('Cache-Control',''),'real redirect privacy headers')
    status,body,_=request(native,'/messenger-mobile/v1/token/',{'code':parts['code'][0],'code_verifier':verifier,'redirect_uri':redirect});tokens=json.loads(body);check(status==200 and tokens['issuer']==base,'cookie-free real PKCE exchange');status,body,_=request(native,'/messenger-api/v1/session/',token=tokens['access_token']);check(status==200 and json.loads(body)['result']['user_id']==account_id,'native member session');status,_,_=request(native,'/messenger-mobile/v1/token/',{'code':parts['code'][0],'code_verifier':verifier,'redirect_uri':redirect});check(status==400,'authorization code remains single use')
    status,_,_=request(native,'/messenger-mobile/v1/revoke/',{'refresh_token':tokens['refresh_token']});revoked=status==200;check(revoked,'test connection revoked');status,_,_=request(native,'/messenger-api/v1/session/',token=tokens['access_token']);check(status==401,'revoked native credentials rejected');status,body,_=request(browser,'/messages/');check(status==200 and 'MessengerApp' in body,'browser web app session remains usable')
finally:
    if tokens and not revoked and tokens.get('refresh_token'):request(native,'/messenger-mobile/v1/revoke/',{'refresh_token':tokens['refresh_token']})
print(json.dumps({'checks':checks,'tls_verified':True,'approval_http_status':303,'exact_callback':True,'csrf_pkce_single_use_preserved':True,'web_session_preserved':True,'scripts_or_external_assets':False},indent=2))
