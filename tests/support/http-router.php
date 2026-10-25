<?php declare(strict_types=1); namespace ProcessWire;
// Disposable local fixture only. Never usable through a normal web/PHP-FPM site.
if(PHP_SAPI!=='cli-server'||!in_array($_SERVER['REMOTE_ADDR']??'',['127.0.0.1','::1'],true)){http_response_code(404);exit;}
require __DIR__.'/bootstrap.php';require dirname(__DIR__,2).'/src/MessengerMobileHttp.php';
[$m,$db,$a]=fixture();
$m->services['cache']=new class {private array $rows=[];public function get($key){$r=$this->rows[$key]??null;return $r&&$r[1]>time()?$r[0]:null;}public function save($key,$value,$expires){$this->rows[$key]=[$value,$expires];return true;}public function delete($key){unset($this->rows[$key]);return true;}};
if(getenv('MESSENGER_FIXTURE_SAVED')==='1')$m->set('saved_messages_enabled',1);
if(getenv('MESSENGER_FIXTURE_TOOLS')==='1'){$operator=new class(99) extends User{public function isSuperuser(){return true;}};$m->installMessageTools($operator);$m->set('message_tools_enabled',1);}
if(getenv('MESSENGER_FIXTURE_POLLS')==='1'){$operator=new class(99)extends User{public function isSuperuser(){return true;}};$m->installGroups($operator);$m->set('groups_enabled',1);$m->installGroupPolls($operator);$m->installGroupLinks($operator);$m->installGroupMentions($operator);}
// Synthetic TLS only for fixture tests. Real HTTPS is a separate integration gate.
$_SERVER['HTTPS']='on';
$csrf=new class {public function getToken($name){return ['name'=>'fixture_csrf','value'=>'fixture_csrf_value'];}};
$session=new class($csrf) {private array $data=[];public function __construct(public object $CSRF){}public function get($k){return $this->data[$k]??null;}public function set($k,$v){$this->data[$k]=$v;}};
$input=new class {public function get($key){return $_GET[$key]??null;}public function post($key){return $_POST[$key]??null;}};
$m->services['user']=new User(($_COOKIE['fixture_account']??'')==='member'?11:0);
$m->services['session']=$session;$m->services['input']=$input;$m->services['log']=new class {public function save($kind,$value){}};
$users=new class {public $services;public function get($id){return new User((int)$id);}public function setCurrentUser($u){$this->services['user']=$u;}};$users->services=&$m->services;$m->services['users']=$users;
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);$resource=basename((string)$path);
if(str_starts_with($path,'/messenger-mobile/'))echo(new MessengerMobileHttp($m))->handle($resource);
else{$rest=new MessengerRestApi($m);$rest->services=&$m->services;echo $rest->handle('v1',$resource);}
