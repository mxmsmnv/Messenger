<?php declare(strict_types=1); namespace ProcessWire;
require __DIR__.'/support/bootstrap.php';
require dirname(__DIR__).'/src/MessengerMobileHttp.php';
[$m,$db,$a,$b]=fixture();
$csrf=new class {public int $calls=0;public function getToken($name){$this->calls++;return ['name'=>'fixture_csrf','value'=>'fixture_csrf_value'];}};
$session=new class($csrf) {private array $state=[];public function __construct(public object $CSRF){}public function get($k){return $this->state[$k]??null;}public function set($k,$v){$this->state[$k]=$v;}};
$input=new class {public array $query=[]; public array $form=[];public function get($k){return $this->query[$k]??null;}public function post($k){return $this->form[$k]??null;}};
$m->services['session']=$session;$m->services['input']=$input;$m->services['log']=new class {public array $entries=[];public function save($kind,$value){$this->entries[]=$value;}};
$users=new class {public $services; public function get($id){return new User((int)$id,'fixture-'.$id);}public function setCurrentUser($user){$this->services['user']=$user;}};
$users->services=&$m->services;$m->services['users']=$users;
$rest=new MessengerRestApi($m);$rest->services=&$m->services;
$_SERVER=['HTTPS'=>'on','REQUEST_METHOD'=>'GET','HTTP_HOST'=>'example.test'];
$browser=json_decode($rest->handle('v1','session'),true);check($browser['ok']&&isset($browser['result']['csrf']),'browser session still supplies CSRF');check($csrf->calls===1,'web CSRF requested');
$_SERVER['HTTP_AUTHORIZATION']='Basic synthetic-proxy-credential';$proxy=json_decode($rest->handle('v1','session'),true);check($proxy['ok']&&isset($proxy['result']['csrf']),'upstream browser authentication does not become native auth');unset($_SERVER['HTTP_AUTHORIZATION']);
// Exercise actual CSRF validator with parsed JSON-shaped body, not a duplicate implementation.
$validator=new \ReflectionMethod($rest,'validateCsrf');denied(fn()=>$validator->invoke($rest,[]));$validator->invoke($rest,['fixture_csrf'=>'fixture_csrf_value']);check(true,'valid browser CSRF accepted');
$_SERVER['REQUEST_METHOD']='POST';$badMethod=json_decode($rest->handle('v1','session'),true);check(http_response_code()===405&&!$badMethod['ok'],'session method unchanged');
$auth=new MessengerMobileAuth($m);$v=str_repeat('v',64);$data=['redirect_uri'=>'org.example.narzan://callback','state'=>str_repeat('s',32),'device_name'=>'Fixture','code_challenge_method'=>'S256','code_challenge'=>rtrim(strtr(base64_encode(hash('sha256',$v,true)),'+/','-_'),'=')];
$cb=$auth->approve($a,$data);parse_str((string)parse_url($cb,PHP_URL_QUERY),$q);$tokens=$auth->exchange(['code'=>$q['code'],'code_verifier'=>$v,'redirect_uri'=>$data['redirect_uri']]);
$original=new User(0);$users->setCurrentUser($original);$_SERVER=['HTTPS'=>'on','REQUEST_METHOD'=>'GET','HTTP_HOST'=>'example.test','HTTP_AUTHORIZATION'=>'Bearer '.$tokens['access_token']];
$native=json_decode($rest->handle('v1','session'),true);check($native['ok']&&$native['result']['user_id']===$a->id&&!isset($native['result']['csrf']),'native session is member-only with no CSRF');check($m->services['user']===$original,'native authority restored');
$_SERVER['HTTP_AUTHORIZATION']='Bearer invalid';$users->setCurrentUser($a);$rejected=json_decode($rest->handle('v1','session'),true);check(!$rejected['ok']&&http_response_code()===401,'bad bearer never falls back to logged-in web user');
$_SERVER['HTTP_AUTHORIZATION']='Bearer '.$tokens['access_token'];$_SERVER['HTTP_COOKIE']='fixture=1';$mixed=json_decode($rest->handle('v1','session'),true);check(!$mixed['ok']&&$mixed['code']==='mixed_credentials','mixed browser and bearer rejected');unset($_SERVER['HTTP_COOKIE']);
$_SERVER['REQUEST_METHOD']='GET';$before=$m->syncState($a);$r=$m->startConversation($a,$b,'first','sync_fixture_0001');$initial=$m->syncState($b);check(!$m->syncState($b,$initial['revision'])['changed'],'stable sync revision');$m->editMessage($r['message']['id'],$a,'edit');check($m->syncState($b,$initial['revision'])['reset_required'],'edit invalidates old cache');$edited=$m->syncState($b);$m->deleteMessage($r['message']['id'],$a);check($m->syncState($b,$edited['revision'])['changed'],'delete invalidates old cache');
// Consent GET must never create codes; POST needs browser session, explicit consent and matching CSRF.
$http=new MessengerMobileHttp($m);$_SERVER=['HTTPS'=>'on','REQUEST_METHOD'=>'GET','HTTP_HOST'=>'example.test'];$input->query=$data;$users->setCurrentUser($a);$count=(int)$db->query('select count(*) from messenger_mobile_codes')->fetchColumn();$form=$http->handle('authorize');check(str_contains($form,'Allow this device'),'explicit consent shown');check((int)$db->query('select count(*) from messenger_mobile_codes')->fetchColumn()===$count,'GET never grants access');
$_SERVER['REQUEST_METHOD']='POST';$input->form=$data+['approve'=>'yes'];$denial=json_decode($http->handle('authorize'),true);check(http_response_code()===403&&$denial['error']==='access_denied','consent without CSRF denied');$input->form['fixture_csrf']='fixture_csrf_value';$approved=$http->handle('authorize');check(http_response_code()===303&&str_contains($approved,'Return to Narzan'),'valid consent redirects with accessible fallback');check(!str_contains($approved,'Authorization approved'),'completion avoids raw technical success screen');
check($m->services['log']->entries===[],'no secrets or expected auth errors logged');
echo 'Web/mobile transport checks: '.$GLOBALS['checks'].PHP_EOL;
