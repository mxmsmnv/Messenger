<?php declare(strict_types=1); namespace ProcessWire;
require __DIR__.'/support/bootstrap.php';
class HookEvent {public function __construct(private array $values){} public function arguments($i,$value=null){if(func_num_args()>1)$this->values[$i]=$value;return $this->values[$i]??null;}}
[$m,$db,$a]=fixture();$admin=new class(1)extends User{public function isSuperuser(){return true;}};$m->services['user']=$admin;$m->installExperimentalCalls($admin);
$m->wire('config')->messengerCalls=['enabled'=>true,'push_ready'=>true];check($m->experimentalCallsReady(),'direct calls do not require TURN');
$row=['id'=>'01234567-0123-4123-8123-0123456789ab','created_at'=>time()];$pool=new \ReflectionMethod($m,'callIcePool');
check($pool->invoke($m,$row,str_repeat('a',32))['ice_servers']===[],'direct mode returns empty ICE pool');
$secret=str_repeat('s',40);$input=['call_relays_apply'=>1,'mobile_enabled'=>1,'call_relay_1_urls'=>'turn:one.example:3478?transport=udp','call_relay_1_secret'=>$secret,'call_relay_3_urls'=>'turns:three.example:5349?transport=tcp','call_relay_3_secret'=>str_repeat('t',40)];
$event=new HookEvent([$m,$input]);$m->beforeSaveCallRelays($event);
check($event->arguments(1)===['mobile_enabled'=>1],'transient secrets and relay fields stripped from ordinary configuration');
$ice=$pool->invoke($m,$row,str_repeat('a',32));check(count($ice['ice_servers'])===2,'several relay servers returned');check($ice['urls']===$ice['ice_servers'][0]['urls'],'legacy clients retain first relay');
$dump=json_encode($db->query('SELECT * FROM messenger_call_relays')->fetchAll(\PDO::FETCH_ASSOC));check(!str_contains($dump,$secret)&&!str_contains($dump,'one.example'),'whole private pool encrypted');
$input['call_relay_1_secret']='';$input['call_relay_3_secret']='';$m->beforeSaveCallRelays(new HookEvent([$m,$input]));check($ice===$pool->invoke($m,$row,str_repeat('a',32)),'blank password keeps exact slot secret, including gaps');
$bad=$input;$bad['call_relay_1_urls']='turn:user:password@relay.example';$event=new HookEvent([$m,$bad]);try{$m->beforeSaveCallRelays($event);throw new \RuntimeException('expected URL denial');}catch(\InvalidArgumentException $e){check(true,'embedded credentials rejected');}check($event->arguments(1)===['mobile_enabled'=>1],'even invalid form never persists secrets');check($ice===$pool->invoke($m,$row,str_repeat('a',32)),'failed validation leaves saved pool untouched');
$unchecked=new HookEvent([$m,['call_relay_1_secret'=>$secret]]);$m->beforeSaveCallRelays($unchecked);check($unchecked->arguments(1)===[],'unchecked or missing apply flag still strips password');
$m->beforeSaveCallRelays(new HookEvent([$m,['call_relays_apply'=>1]]));check($pool->invoke($m,$row,str_repeat('a',32))['ice_servers']===[],'explicit direct-only overrides legacy fallback');
$m->wire('config')->messengerCalls['push_ready']=false;check(!$m->experimentalCallsReady(),'direct calls still require explicit incoming push provisioning');
echo 'Relay administration checks: '.$GLOBALS['checks'].PHP_EOL;
