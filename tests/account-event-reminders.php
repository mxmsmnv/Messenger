<?php declare(strict_types=1);namespace ProcessWire;
require __DIR__.'/support/bootstrap.php';[$m,$db,$a,$b,$c]=fixture();$admin=new class(99)extends User{public function isSuperuser(){return true;}};
$m->installGroups($admin);$m->installGroupEvents($admin);$m->installMessagePush($admin);$m->set('groups_enabled',1)->set('first_contact_mode','open')->set('max_messages_minute',1000);$m->wire('config')->messengerPush=['enabled'=>true,'push_ready'=>true];
$gb=str_repeat('b',32);$gc=str_repeat('c',32);foreach([$gb=>$b->id,$gc=>$c->id]as$grant=>$user){$db->prepare('INSERT INTO messenger_mobile_grants VALUES(?,?,?,?,NULL)')->execute([$grant,$user,'synthetic',time()]);$m->messagePushDevice($user===$b->id?$b:$c,$grant,'POST',['enabled'=>true,'platform'=>'android','token'=>'synthetic_event_account_token_001','sound'=>true,'account_binding'=>true]);}
$g=$m->createGroup($a,'Bound reminders',[$b->id,$c->id],'account_event_group_001');$gid=$g['id'];$m->respondGroupInvitation($gid,$b,true);$m->respondGroupInvitation($gid,$c,true);
$details=['title'=>'Private account event','description'=>'Not in push','location'=>'Private','starts_at'=>time()+7200,'timezone'=>'UTC','duration_minutes'=>60];$mid=$m->createGroupEvent($a,$gid,$details,'account_event_create_001')['message_id'];
foreach([$b,$c]as$member)$m->respondGroupEvent($member,$mid,'yes',60,1);$db->prepare('UPDATE messenger_group_event_answers SET due_at=? WHERE message_id=?')->execute([time()-1,$mid]);
$sent=[];$r=$m->processGroupEventReminders($admin,function(...$args)use(&$sent){$sent[]=$args;return true;});check($r['sent']===2&&count($sent)===2,'one shared phone token receives both consented account reminders');
$d=(new MessengerMobileAuth($m))->discovery();$prefix=$d['issuer'].'#'.$d['site_id'].'#';$keys=array_column($sent,4);check(in_array($prefix.$b->id.'#'.$gb,$keys,true)&&in_array($prefix.$c->id.'#'.$gc,$keys,true),'each reminder carries its exact connection routing suffix');check(count($sent[0])===7&&$sent[0][6]==='1:1'&&!str_contains(json_encode($sent),'Private'),'reminder signature revision and privacy remain unchanged');
check($m->processGroupEventReminders($admin,fn(...$args)=>true)['sent']===0,'bound reminders retain existing deduplication');

echo 'Account-bound event reminder checks: '.$GLOBALS['checks'].PHP_EOL;
