<?php declare(strict_types=1); namespace ProcessWire;
require __DIR__.'/forwarding-integration.php';$inherited=$GLOBALS['checks'];
$m->installGroups($operator);$m->set('groups_enabled',1)->set('first_contact_mode','requests')->set('max_new_recipients_day',1000)->set('max_messages_minute',1000)->set('email_enabled',0);$m->installGroupPolls($operator);check($m->pollsReady(),'real MySQL explicit poll schema');
$g=$as($a,fn()=>$m->createGroup($a,'Real poll',[(int)$b->id,(int)$c->id],'mysql_poll_group_'.$suffix));$gid=$g['id'];$as($b,fn()=>$m->respondGroupInvitation($gid,$b,true));
$p=$as($a,fn()=>$m->createGroupPoll($a,$gid,'Where?',['Harbour','Park'],'mysql_poll_create_'.$suffix));$mid=$p['message_id'];check($p['poll']['counts']===[0,0]&&$p['ack_visible'],'actual MySQL poll creation');
check($as($a,fn()=>$m->createGroupPoll($a,$gid,'Where?',['Harbour','Park'],'mysql_poll_create_'.$suffix))['message_id']===$mid,'actual operation replay is unique');
$as($a,fn()=>denied(fn()=>$m->createGroupPoll($a,$gid,'Changed',['Harbour','Park'],'mysql_poll_create_'.$suffix)));
check($as($b,fn()=>$m->voteGroupPoll($b,$mid,0))['counts']===[1,0],'actual vote persists');check($as($b,fn()=>$m->voteGroupPoll($b,$mid,0))['counts']===[1,0],'actual vote retry unique');check($as($b,fn()=>$m->voteGroupPoll($b,$mid,1))['counts']===[0,1],'actual vote changes');
$q=$db->prepare('SELECT choice FROM messenger_poll_votes WHERE message_id=?');$q->execute([$mid]);check(str_starts_with((string)$q->fetchColumn(),'menc:v1:'),'actual vote encryption envelope');
$as($c,fn()=>denied(fn()=>$m->groupPoll($c,$mid)));$as($c,fn()=>$m->respondGroupInvitation($gid,$c,true));$as($c,fn()=>denied(fn()=>$m->groupPoll($c,$mid)));
$history=$as($b,fn()=>$m->conversationMessages($gid,$b));check($history[0]['poll']['own_choice']===1,'actual history poll metadata');
// Force an actual concurrent voter to wait after its initial metadata read, then
// close under the existing conversation lock. Current locking reads must reject it.
$race=$as($a,fn()=>$m->createGroupPoll($a,$gid,'Concurrent close',['One','Two'],'mysql_poll_race_'.$suffix));$rid=$race['message_id'];$proc=null;$pipes=[];
try {
    $db->beginTransaction();$q=$db->prepare('SELECT id FROM messenger_conversations WHERE id=? FOR UPDATE');$q->execute([$gid]);$q->fetchColumn();
    $proc=proc_open([PHP_BINARY,__DIR__.'/polls-race-worker.php','--root='.$root,'--actor='.(int)$b->id,'--message='.$rid],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($proc))throw new \RuntimeException('Concurrent clone writer unavailable.');fclose($pipes[0]);stream_set_blocking($pipes[1],false);stream_set_blocking($pipes[2],false);
    $database=(string)$db->query('SELECT DATABASE()')->fetchColumn();$waiting=false;
    for($i=0;$i<100;$i++){$q=$db->prepare("SELECT COUNT(*) FROM performance_schema.data_lock_waits w JOIN performance_schema.data_locks l ON l.ENGINE_LOCK_ID=w.REQUESTING_ENGINE_LOCK_ID AND l.ENGINE=w.ENGINE WHERE l.OBJECT_SCHEMA=? AND l.OBJECT_NAME='messenger_conversations'");$q->execute([$database]);if((int)$q->fetchColumn()>0){$waiting=true;break;}if(!proc_get_status($proc)['running'])break;usleep(100000);}
    check($waiting,'real concurrent voter waits on owned conversation lock');$as($a,fn()=>$m->closeGroupPoll($a,$rid));$db->commit();
    $out='';$err='';for($i=0;$i<100;$i++){$out.=stream_get_contents($pipes[1]);$err.=stream_get_contents($pipes[2]);if(!proc_get_status($proc)['running'])break;usleep(100000);}
    $out.=stream_get_contents($pipes[1]);check(str_contains($out,'poll-worker:denied')&&!str_contains($out,'poll-worker:accepted'),'concurrent vote observes newly closed state');$q=$db->prepare('SELECT COUNT(*) FROM messenger_poll_votes WHERE message_id=?');$q->execute([$rid]);check((int)$q->fetchColumn()===0,'concurrent close commits no late vote');
} finally {if($db->inTransaction())$db->rollBack();if(is_resource($proc)){if(proc_get_status($proc)['running'])proc_terminate($proc);foreach([1,2]as$i)if(isset($pipes[$i])&&is_resource($pipes[$i]))fclose($pipes[$i]);proc_close($proc);}}
$as($a,fn()=>$m->hideMessage($mid,$a));$hidden=$as($a,fn()=>$m->createGroupPoll($a,$gid,'Where?',['Harbour','Park'],'mysql_poll_create_'.$suffix));check(!$hidden['ack_visible']&&$hidden['poll']===null,'actual hidden replay only acknowledges immutable operation');
$as($a,fn()=>$m->changeGroup($gid,$a,'remove',['user_id'=>(int)$b->id]));$as($b,fn()=>denied(fn()=>$m->groupPoll($b,$mid)));check(!$db->inTransaction(),'no leaked real transaction');
$expiry=$as($a,fn()=>$m->createGroupPoll($a,$gid,'Expire',['One','Two'],'mysql_poll_expiry_'.$suffix));$eid=$expiry['message_id'];$db->prepare('UPDATE messenger_messages SET expires_at=? WHERE id=?')->execute([time()-1,$eid]);$as($a,fn()=>denied(fn()=>$m->groupPoll($a,$eid)));$q=$db->prepare('SELECT options FROM messenger_polls WHERE message_id=?');$q->execute([$eid]);check($q->fetchColumn()==='','actual expiry erases structured option content');$hidden=$as($a,fn()=>$m->createGroupPoll($a,$gid,'Expire',['One','Two'],'mysql_poll_expiry_'.$suffix));check(!$hidden['ack_visible']&&$hidden['poll']===null,'actual expired replay keeps only acceptance');
echo json_encode(['poll_checks'=>$GLOBALS['checks']-$inherited,'database'=>'isolated MySQL clone','live_site_modified'=>false,'providers_called'=>false],JSON_PRETTY_PRINT).PHP_EOL;
