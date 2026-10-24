<?php declare(strict_types=1); namespace ProcessWire;
require __DIR__.'/support/bootstrap.php';
[$m,$db,$a,$b,$outsider]=fixture();
$m->services['config']=new class{public string $tableSalt='synthetic-audit-only';public array $messengerCalls=[];public function get($key){return null;}};
check(!$m->experimentalCallsReady(),'calls are disabled without explicit configuration');
denied(fn()=>$m->installExperimentalCalls($a));
$admin=new class(1)extends User{public function isSuperuser(){return true;}};
$m->installExperimentalCalls($admin);$m->installExperimentalCalls($admin);
$m->wire('config')->messengerCalls=['enabled'=>true,'turn_secret'=>str_repeat('synthetic',8),'turn_urls'=>['turn:relay.example.test:3478?transport=udp'],'push_ready'=>true];
check($m->experimentalCallsReady(),'explicit relay/push configuration enables experimental calls');
foreach([str_repeat('a',32)=>$a->id,str_repeat('b',32)=>$b->id,str_repeat('c',32)=>$outsider->id,str_repeat('d',32)=>$b->id]as$grant=>$id)$db->prepare('INSERT INTO messenger_mobile_grants VALUES(?,?,?, ?,NULL)')->execute([$grant,$id,'synthetic',time()]);
$ga=str_repeat('a',32);$gb=str_repeat('b',32);$gc=str_repeat('c',32);$gd=str_repeat('d',32);
$device=fn($actor,$grant,$enabled=true)=>$m->experimentalCallRequest($actor,$grant,'call-device','POST',['enabled'=>$enabled,'platform'=>'ios','token'=>'synthetic-token'],[]);
$device($a,$ga);$device($b,$gb);$device($outsider,$gc);$device($b,$gd);
check(strpos((string)$db->query('SELECT token FROM messenger_call_devices LIMIT 1')->fetchColumn(),'synthetic-token')===false,'push tokens are encrypted at rest');
denied(fn()=>$device($a,$gb));
$m->set('first_contact_mode','requests');$started=$m->startConversation($a,$b,'synthetic call fixture','calls_message_0001');$chat=(int)$started['conversation']['id'];
$start=fn($actor,$grant,$client='client_call_00000001')=>$m->experimentalCallRequest($actor,$grant,'call-start','POST',['conversation_id'=>$chat,'client_id'=>$client],[]);
denied(fn()=>$start($a,$ga));$m->acceptRequest($chat,$b);
$call=$start($a,$ga);check($call['state']==='ringing','accepted chat can start ringing');
check($start($a,$ga)['id']===$call['id'],'call start retry is idempotent');
try{$start($a,$ga,'busy_caller_00000001');throw new \RuntimeException('Expected busy caller');}catch(MessengerMobileException $e){check($e->apiCode==='caller_busy'&&$e->httpStatus===409,'a different device call reports own busy state without a duplicate call');}

check((int)$db->query('SELECT COUNT(*) FROM messenger_call_pushes')->fetchColumn()===2,'both opted-in recipient devices receive queued invitations');
$other=$m->startConversation($outsider,$b,'another accepted peer','other_call_message_01');$otherChat=(int)$other['conversation']['id'];$m->acceptRequest($otherChat,$b);
try{$m->experimentalCallRequest($outsider,$gc,'call-start','POST',['conversation_id'=>$otherChat,'client_id'=>'busy_peer_0000000001'],[]);throw new \RuntimeException('Expected busy peer');}catch(MessengerMobileException $e){check($e->apiCode==='peer_busy'&&$e->httpStatus===409,'engaged peer reports busy without disclosing another call');}

// Real ProcessWire users are logged in only when they match the current wire user.
$originalUser=$m->services['user'];$GLOBALS['calls_current_user']=0;
$workerCaller=new class($a->id,$a->name)extends User {public function isLoggedin(){return empty($GLOBALS['calls_deny_caller'])&&$this->id===($GLOBALS['calls_current_user']??0);}};
$m->services['users']=new class($m,$workerCaller,$b) {
    public function __construct(private $m,private $a,private $b){}
    public function get($id){return (int)$id===$this->a->id?$this->a:$this->b;}
    public function setCurrentUser($user){$this->m->services['user']=$user;$GLOBALS['calls_current_user']=(int)$user->id;}
};
$m->services['user']=new User(0);
check(!$workerCaller->isLoggedin(),'background caller is not the CLI current user');
$delivered=[];$presented=[];$delivery=$m->processExperimentalCallPushes($admin,function($platform,$token,$id,$name)use(&$delivered,&$presented){$delivered[]=$id;$presented[]=$name;return true;});
check($delivery['sent']===2&&count($delivered)===2,'bounded worker delivers opted-in registered devices');
check($presented===[$a->name,$a->name],'system call invitation uses authenticated caller name on every device');
check($m->services['user']->id===0&&$GLOBALS['calls_current_user']===0,'worker restores guest context after successful caller authorization');
$m->services['users']->setCurrentUser($originalUser);
check($m->processExperimentalCallPushes($admin,fn()=>true)['sent']===0,'delivered pushes are not sent again');
$m->wire('database')->exec("UPDATE messenger_call_pushes SET status='pending',attempts=0,available_at=0");
$GLOBALS['calls_deny_caller']=true;$m->services['users']->setCurrentUser(new User(0));
$deniedDelivery=$m->processExperimentalCallPushes($admin,fn()=>true);
check($deniedDelivery['discarded']===2&&$deniedDelivery['sent']===0,'worker still denies caller policy after entering caller context');
check($m->services['user']->id===0&&$GLOBALS['calls_current_user']===0,'worker restores guest context when caller policy throws');
$GLOBALS['calls_deny_caller']=false;$m->services['users']->setCurrentUser($originalUser);

$id=$call['id'];
$signal=fn($actor,$grant,$kind,$payload='',$client='signal_client_000001')=>$m->experimentalCallRequest($actor,$grant,'call-signal','POST',['id'=>$id,'kind'=>$kind,'payload'=>$payload,'client_id'=>$client],[]);
denied(fn()=>$signal($outsider,$gc,'accept'));
denied(fn()=>$signal($a,$ga,'accept'));
denied(fn()=>$signal($a,$ga,'offer','premature','early_offer_00000001'));
denied(fn()=>$m->experimentalCallRequest($a,$ga,'call-ice','GET',[],['id'=>$id]));
$signal($b,$gb,'accept');denied(fn()=>$m->experimentalCallRequest($b,$gd,'call-ice','GET',[],['id'=>$id]));denied(fn()=>$signal($b,$gd,'accept','','second_device_accept'));
$signal($a,$ga,'offer','synthetic-private-sdp','offer_client_0000001');
check(strpos((string)$db->query("SELECT payload FROM messenger_call_signals WHERE kind='offer'")->fetchColumn(),'synthetic-private-sdp')===false,'SDP is encrypted at rest');
$one=$signal($a,$ga,'ice','synthetic-ice','ice_client_000000001');$two=$signal($a,$ga,'ice','synthetic-ice','ice_client_000000001');check($one===$two,'signal retry preserves exact sequence');
try{$signal($a,$ga,'ice','different','ice_client_000000001');throw new \RuntimeException('Expected retry conflict');}catch(\InvalidArgumentException $e){check(true,'changed retry rejected');}
$state=$m->experimentalCallRequest($b,$gb,'call-state','GET',[],['id'=>$id,'after'=>0]);check($state['call']['state']==='active'&&count($state['signals'])===3,'participant receives cursor-scoped encrypted signaling');
check($state['signals'][1]['payload']==='synthetic-private-sdp','authorized recipient decrypts the offer');
$ice=$m->experimentalCallRequest($a,$ga,'call-ice','GET',[],['id'=>$id]);check($ice['expires_in']>=7495&&$ice['expires_in']<=7500&&$ice['credential']===base64_encode(hash_hmac('sha1',$ice['username'],$m->wire('config')->messengerCalls['turn_secret'],true)),'TURN uses expiring HMAC credentials');
denied(fn()=>$m->experimentalCallRequest($outsider,$gc,'call-ice','GET',[],['id'=>$id]));
$signal($a,$ga,'end','','end_client_00000001');
denied(fn()=>$signal($b,$gb,'answer','late','late_client_00000001'));
$call2=$start($a,$ga,'client_call_00000002');$id=$call2['id'];$db->exec('UPDATE messenger_calls SET expires_at=1');
$expired=$m->experimentalCallRequest($b,$gb,'call-state','GET',[],['id'=>$id]);check($expired['call']['state']==='ended','expired invitations cannot resume');
$device($b,$gb,false);$device($b,$gd,false);denied(fn()=>$start($a,$ga,'client_call_00000003'));
$device($b,$gb);$db->exec("UPDATE messenger_mobile_grants SET revoked_at=1 WHERE id='$gb'");denied(fn()=>$start($a,$ga,'client_call_00000004'));
require dirname(__DIR__).'/src/MessengerCallPush.php';
$key=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_EC,'curve_name'=>'prime256v1']);openssl_sign('synthetic',$signature,$key,OPENSSL_ALGO_SHA256);check(strlen(MessengerCallPush::rawEs256($signature))===64,'Apple JWT converts OpenSSL DER to raw ES256');
echo 'Experimental calls checks: '.$GLOBALS['checks']."\n";
