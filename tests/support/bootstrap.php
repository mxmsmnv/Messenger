<?php declare(strict_types=1);
namespace ProcessWire;
// In-memory fixture services only; never bootstrap a consuming website.
interface Module {} interface ConfigurableModule {}
class WireException extends \RuntimeException {} class WirePermissionException extends WireException {} class Wire404Exception extends WireException {}
class WireData {
 public array $services=[]; private array $values=[];
 public function __construct(){} public function set($k,$v){$this->values[$k]=$v;return $this;}
 public function __get($k){return $this->values[$k]??null;}
 public function wire($k=null){return $k===null?(object)$this->services:($this->services[$k]??null);}
 public function _($s){return $s;}
}
class User { public function __construct(public int $id,public string $name='fixture'){} public function isLoggedin(){return $this->id>0;} public function isGuest(){return false;}public function isSuperuser(){return false;}public function hasPermission($p){return false;} public function get($k){return $k==='title'?$this->name:null;} }
class Wire extends WireData {}
require dirname(__DIR__,2).'/Messenger.module.php';
require dirname(__DIR__,2).'/MessengerRestApi.php';
class AuditMessenger extends Messenger {
 public bool $hookAllow=false;
 public function messagingDecision(User $a,User $b,array $ctx=[]):array {return $this->hookAllow?['decision'=>'allow','reason'=>'test trusted hook']:parent::___messagingDecision($a,$b,$ctx);}
}
function fixture():array {
 $dsn=getenv('MESSENGER_FIXTURE_DB')?:'sqlite::memory:';$db=class_exists('Pdo\\Sqlite')?new \Pdo\Sqlite($dsn):new \PDO($dsn);$db->setAttribute(\PDO::ATTR_ERRMODE,\PDO::ERRMODE_EXCEPTION);if(method_exists($db,'createFunction'))$db->createFunction('GREATEST',fn($a,$b)=>max($a,$b));else $db->sqliteCreateFunction('GREATEST',fn($a,$b)=>max($a,$b));
 $ddl=[
 'messenger_conversations'=>'id INTEGER PRIMARY KEY AUTOINCREMENT,public_key TEXT,direct_key TEXT UNIQUE,type TEXT,state TEXT,request_state TEXT,requester_id INT,recipient_id INT,last_message_id INT,last_message_at TEXT,created_at TEXT,updated_at TEXT',
 'messenger_participants'=>'id INTEGER PRIMARY KEY AUTOINCREMENT,conversation_id INT,user_id INT,role TEXT,last_read_message_id INT,archived_at TEXT,muted_until TEXT,hidden_before TEXT,email_mode TEXT,created_at TEXT',
 'messenger_messages'=>'id INTEGER PRIMARY KEY AUTOINCREMENT,conversation_id INT,sender_user_id INT,client_id TEXT,body TEXT,request_hash TEXT DEFAULT \'\',reply_to_id INT,moderation_state TEXT,created_at TEXT,edited_at TEXT,deleted_at TEXT,UNIQUE(sender_user_id,client_id)',
 'messenger_message_hides'=>'message_id INT,user_id INT,created_at TEXT,PRIMARY KEY(message_id,user_id)',
 'messenger_blocks'=>'blocker_user_id INT,blocked_user_id INT',
 'messenger_restrictions'=>'user_id INT,status TEXT,reason TEXT,expires_at TEXT',
 'messenger_notification_outbox'=>'id INTEGER PRIMARY KEY,message_id INT,conversation_id INT,recipient_user_id INT,status TEXT,processed_at TEXT',
 'messenger_audit'=>'id INTEGER PRIMARY KEY,actor_user_id INT,action TEXT,conversation_id INT,message_id INT,metadata TEXT,created_at TEXT'];
 foreach($ddl as $t=>$cols)$db->exec("CREATE TABLE IF NOT EXISTS $t ($cols)");
 $users=new class {public ?object $owner=null;public function get($id){return new User((int)$id,'fixture-'.$id);}public function setCurrentUser($user){if($this->owner)$this->owner->services['user']=$user;}};
 $m=new AuditMessenger();$m->services=['database'=>$db,'users'=>$users,'user'=>new User(11),'config'=>new class {public string $tableSalt='synthetic-audit-only'; public bool $https=true; public array $httpHosts=[]; public array $messengerCalls=[]; public array $messengerPush=[]; public object $urls; public function __construct(){$this->urls=(object)['root'=>'/'];} public function get($key){return null;}}];
 $users->owner=$m;
 $m->set('mobile_enabled',1)->set('mobile_origin','https://example.test')->set('mobile_redirect_uris','org.example.narzan://callback');
 MessengerMobileAuth::install($db);
 $install=new \ReflectionMethod($m,'installMemberFeatures');$install->invoke($m);$settings=new \ReflectionMethod($m,'installConversationSettings');$settings->invoke($m);
 return [$m,$db,new User(11),new User(22),new User(33)];
}

function check(bool $ok,string $message): void {if(!$ok)throw new \RuntimeException($message);$GLOBALS['checks']=($GLOBALS['checks']??0)+1;}
function denied(callable $fn, ?string $code=null): void {try{$fn();}catch(WireException $e){if($code!==null)check($e instanceof MessengerMobileException && $e->apiCode===$code,'wrong error code');else check(true,'denied');return;}throw new \RuntimeException('Expected denial');}
