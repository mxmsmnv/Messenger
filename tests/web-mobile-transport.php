<?php declare(strict_types=1); namespace ProcessWire;
require __DIR__.'/support/bootstrap.php';
require dirname(__DIR__).'/src/MessengerMobileHttp.php';
[$m,$db,$a,$b]=fixture();
$a=new class($a->id,'own-login') extends User {public function get($key){return $key==='display_name'?'Own Display Name':parent::get($key);}};
$m->services['user']=$a;
$csrf=new class {public int $calls=0;public function getToken($name){$this->calls++;return ['name'=>'fixture_csrf','value'=>'fixture_csrf_value'];}};
$session=new class($csrf) {private array $state=[];public function __construct(public object $CSRF){}public function get($k){return $this->state[$k]??null;}public function set($k,$v){$this->state[$k]=$v;}};
$input=new class {public array $query=[]; public array $form=[];public function get($k){return $this->query[$k]??null;}public function post($k){return $this->form[$k]??null;}};
$m->services['session']=$session;$m->services['input']=$input;$m->services['log']=new class {public array $entries=[];public function save($kind,$value){$this->entries[]=$value;}};
$users=new class($a) {public function __construct(private User $own){} public $services; public function get($id){return (int)$id===$this->own->id?$this->own:new User((int)$id,'fixture-'.$id);}public function setCurrentUser($user){$this->services['user']=$user;}};
$users->services=&$m->services;$m->services['users']=$users;
$rest=new MessengerRestApi($m);$rest->services=&$m->services;
$_SERVER=['HTTPS'=>'on','REQUEST_METHOD'=>'GET','HTTP_HOST'=>'example.test'];
$browser=json_decode($rest->handle('v1','session'),true);check($browser['ok']&&isset($browser['result']['csrf']),'browser session still supplies CSRF');check($csrf->calls===1,'web CSRF requested');
check($browser['result']['name']==='own-login'&&$browser['result']['title']==='Own Display Name','browser own login/display label additive');
check(array_diff(array_keys($browser['result']),['is_login','user_id','can_use','can_moderate','csrf','name','title','groups_supported','forwarding_supported','reactions_supported'])===[],'profile exposes no email or credential fields');
$_SERVER['HTTP_X_MESSENGER_ACTOR']=(string)$b->id;$mismatch=json_decode($rest->handle('v1','session'),true);check(!$mismatch['ok']&&!isset($mismatch['result']),'stale browser actor cannot read or write as a different session');unset($_SERVER['HTTP_X_MESSENGER_ACTOR']);
$input->query=['user_id'=>$b->id];$self=json_decode($rest->handle('v1','session'),true);check($self['result']['name']==='own-login','session target query cannot read another profile');$input->query=[];
$_SERVER['HTTP_AUTHORIZATION']='Basic synthetic-proxy-credential';$proxy=json_decode($rest->handle('v1','session'),true);check($proxy['ok']&&isset($proxy['result']['csrf']),'upstream browser authentication does not become native auth');unset($_SERVER['HTTP_AUTHORIZATION']);
// Exercise actual CSRF validator with parsed JSON-shaped body, not a duplicate implementation.
$validator=new \ReflectionMethod($rest,'validateCsrf');denied(fn()=>$validator->invoke($rest,[]));$validator->invoke($rest,['fixture_csrf'=>'fixture_csrf_value']);check(true,'valid browser CSRF accepted');
$_SERVER['REQUEST_METHOD']='POST';$badMethod=json_decode($rest->handle('v1','session'),true);check(http_response_code()===405&&!$badMethod['ok'],'session method unchanged');
$auth=new MessengerMobileAuth($m);$v=str_repeat('v',64);$data=['redirect_uri'=>'org.example.narzan://callback','state'=>str_repeat('s',32),'device_name'=>'Fixture','code_challenge_method'=>'S256','code_challenge'=>rtrim(strtr(base64_encode(hash('sha256',$v,true)),'+/','-_'),'=')];
$cb=$auth->approve($a,$data);parse_str((string)parse_url($cb,PHP_URL_QUERY),$q);$tokens=$auth->exchange(['code'=>$q['code'],'code_verifier'=>$v,'redirect_uri'=>$data['redirect_uri']]);
$original=new User(0);$users->setCurrentUser($original);$_SERVER=['HTTPS'=>'on','REQUEST_METHOD'=>'GET','HTTP_HOST'=>'example.test','HTTP_AUTHORIZATION'=>'Bearer '.$tokens['access_token']];
$native=json_decode($rest->handle('v1','session'),true);check($native['ok']&&$native['result']['user_id']===$a->id&&!isset($native['result']['csrf']),'native session is member-only with no CSRF');check($m->services['user']===$original,'native authority restored');
check($native['result']['name']==='own-login'&&$native['result']['title']==='Own Display Name','native profile belongs to bearer actor not restored guest');
check($native['result']['can_moderate']===false&&!isset($native['result']['csrf']),'profile grants no native moderation or browser authority');
$devicePage=json_decode($rest->handle('v1','devices'),true);check($devicePage['ok']&&count($devicePage['result']['items'])===1&&$devicePage['result']['items'][0]['current']===true,'native device page is scoped to actual bearer grant');check(!str_contains(json_encode($devicePage),$tokens['access_token'])&&!str_contains(json_encode($devicePage),$tokens['refresh_token']),'device response never contains credentials');
$_SERVER['HTTP_AUTHORIZATION']='Bearer invalid';$users->setCurrentUser($a);$rejected=json_decode($rest->handle('v1','session'),true);check(!$rejected['ok']&&http_response_code()===401,'bad bearer never falls back to logged-in web user');
$_SERVER['HTTP_AUTHORIZATION']='Bearer '.$tokens['access_token'];$_SERVER['HTTP_COOKIE']='fixture=1';$mixed=json_decode($rest->handle('v1','session'),true);check(!$mixed['ok']&&$mixed['code']==='mixed_credentials','mixed browser and bearer rejected');unset($_SERVER['HTTP_COOKIE']);
$_SERVER['REQUEST_METHOD']='GET';$before=$m->syncState($a);$r=$m->startConversation($a,$b,'first','sync_fixture_0001');$initial=$m->syncState($b);check(!$m->syncState($b,$initial['revision'])['changed'],'stable sync revision');$m->editMessage($r['message']['id'],$a,'edit');check($m->syncState($b,$initial['revision'])['reset_required'],'edit invalidates old cache');$edited=$m->syncState($b);$m->deleteMessage($r['message']['id'],$a);check($m->syncState($b,$edited['revision'])['changed'],'delete invalidates old cache');
// Optional metadata goes through the real member dispatch with strict query/method checks.
$m->acceptRequest((int)$r['conversation']['id'],$b);
$metadataMessage=$m->sendMessage((int)$r['conversation']['id'],$a,'synthetic transport metadata','transport_info_0001');
$input->query=['message_id'=>(string)$metadataMessage['id']];
$_SERVER=['HTTPS'=>'on','REQUEST_METHOD'=>'GET','HTTP_HOST'=>'example.test','HTTP_AUTHORIZATION'=>'Bearer '.$tokens['access_token']];
$info=json_decode($rest->handle('v1','message-info'),true);
check(http_response_code()===200&&$info['ok']&&$info['result']['message_id']===$metadataMessage['id'],'native metadata GET reaches authorized message');
check($info['result']['is_own']&&!$info['result']['is_read']&&$info['result']['read_at']===null,'native metadata status is typed and no timestamp is invented');
check(!isset($info['result']['body'],$info['result']['sender_user_id'],$info['result']['actor_user_id'],$info['result']['csrf']),'metadata response omits content/profile/authority fields');
foreach([null,'0','-1','01','1x','1.2','9223372036854775808',['1']] as $invalid) {
    $input->query=['message_id'=>$invalid];$bad=json_decode($rest->handle('v1','message-info'),true);
    check(http_response_code()===400&&!$bad['ok'],'metadata ID must be one positive bounded decimal string');
}
$input->query=['message_id'=>(string)$metadataMessage['id']];$_SERVER['REQUEST_METHOD']='DELETE';
$bad=json_decode($rest->handle('v1','message-info'),true);check(http_response_code()===405&&!$bad['ok'],'metadata endpoint is GET only');
$_SERVER=['HTTPS'=>'on','REQUEST_METHOD'=>'GET','HTTP_HOST'=>'example.test'];$users->setCurrentUser($a);
$webInfo=json_decode($rest->handle('v1','message-info'),true);check(http_response_code()===200&&$webInfo['result']===$info['result'],'existing web member session gets identical authorized metadata');
$input->query=[];
// Additive member-policy resources preserve web and bearer authorization parity.
$_SERVER=['HTTPS'=>'on','REQUEST_METHOD'=>'GET','HTTP_HOST'=>'example.test','HTTP_AUTHORIZATION'=>'Bearer '.$tokens['access_token']];
$input->query=[];$nativePolicy=json_decode($rest->handle('v1','client-policy'),true);
check(http_response_code()===200&&$nativePolicy['result']['max_message_length']===5000,'native member policy');
$input->query=['user_id'=>(string)$b->id];$context=json_decode($rest->handle('v1','recipient-context'),true);
check(http_response_code()===200&&$context['result']['conversation']['id']===$metadataMessage['conversation_id'],'native context finds exact existing pair');
unset($_SERVER['HTTP_AUTHORIZATION']);$webContext=json_decode($rest->handle('v1','recipient-context'),true);
check($context['result']===$webContext['result'],'web and native recipient context identical');
foreach([null,'0','01','1x',['22']] as $badId){$input->query=['user_id'=>$badId];$bad=json_decode($rest->handle('v1','recipient-context'),true);check(http_response_code()===400&&!$bad['ok'],'strict context identifier');}
$input->query=[];
// Consent GET must never create codes; POST needs browser session, explicit consent and matching CSRF.
$http=new MessengerMobileHttp($m);$_SERVER=['HTTPS'=>'on','REQUEST_METHOD'=>'GET','HTTP_HOST'=>'example.test'];$input->query=$data;$users->setCurrentUser($a);$count=(int)$db->query('select count(*) from messenger_mobile_codes')->fetchColumn();$form=$http->handle('authorize');check(str_contains($form,'Allow this device'),'explicit consent shown');check((int)$db->query('select count(*) from messenger_mobile_codes')->fetchColumn()===$count,'GET never grants access');
$_SERVER['REQUEST_METHOD']='POST';$input->form=$data+['approve'=>'yes'];$denial=json_decode($http->handle('authorize'),true);check(http_response_code()===403&&$denial['error']==='access_denied','consent without CSRF denied');$input->form['fixture_csrf']='fixture_csrf_value';$approved=$http->handle('authorize');check(http_response_code()===303&&str_contains($approved,'Return to Narzan'),'valid consent redirects with accessible fallback');check(!str_contains($approved,'Authorization approved'),'completion avoids raw technical success screen');
check($m->services['log']->entries===[],'no secrets or expected auth errors logged');
$_SERVER=['HTTPS'=>'on','REQUEST_METHOD'=>'GET','HTTP_HOST'=>'example.test'];$users->setCurrentUser(new User(0,'guest-secret'));
$guest=json_decode($rest->handle('v1','session'),true);check($guest['ok']&&!$guest['result']['is_login']&&!isset($guest['result']['name'])&&!isset($guest['result']['title']),'guest session omits profile');
$users->setCurrentUser($a);$db->prepare('INSERT INTO messenger_restrictions (user_id,status,reason,expires_at) VALUES (?,?,?,NULL)')->execute([$a->id,'suspended','synthetic']);
$restricted=json_decode($rest->handle('v1','session'),true);check($restricted['ok']&&!$restricted['result']['can_use']&&!isset($restricted['result']['name'])&&!isset($restricted['result']['title']),'restricted browser session omits profile');
$_SERVER['HTTP_AUTHORIZATION']='Bearer '.$tokens['access_token'];$restrictedNative=json_decode($rest->handle('v1','session'),true);check(!$restrictedNative['ok']&&!isset($restrictedNative['result']['name'])&&!isset($restrictedNative['result']['title']),'restricted bearer cannot read self profile');
echo 'Web/mobile transport checks: '.$GLOBALS['checks'].PHP_EOL;
