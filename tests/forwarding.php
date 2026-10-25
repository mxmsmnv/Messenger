<?php declare(strict_types=1); namespace ProcessWire;
require __DIR__.'/support/bootstrap.php';
function setupForwarding():array{
    [$m,$db,$a,$b,$c]=fixture();$operator=new class(99)extends User{public function isSuperuser(){return true;}};
    $m->set('max_messages_minute',1000)->set('delete_window_minutes',60);
    $source=$m->startConversation($b,$a,'Original private message','forward_source_start');$sid=$source['conversation']['id'];$m->acceptRequest($sid,$a);
    $target=$m->startConversation($c,$a,'Destination conversation','forward_target_start');$tid=$target['conversation']['id'];$m->acceptRequest($tid,$a);
    return [$m,$db,$a,$b,$c,$operator,$sid,$tid,$source['message']['id']];
}
// Retain the exact displayed revisions across retry, as a native/browser selection does.
function forward(Messenger $m,User $actor,int $source,int $target,array $ids,string $client):array{
    static $snapshots=[];$key=spl_object_id($m);$versions=[];
    foreach($ids as$id){if(!isset($snapshots[$key][$id])){try{$row=$m->conversationMessages($source,$actor,$id+1,$id-1,1)[0]??null;}catch(WireException $e){$row=null;}$snapshots[$key][$id]=$row['content_revision']??str_repeat('0',64);}$versions[$id]=$snapshots[$key][$id];}
    return $m->forwardMessages($actor,$source,$target,$ids,$client,$versions);
}
[$m,$db,$a,$b,$c,$operator,$source,$target,$message]=setupForwarding();
check(!$m->forwardingReady(),'forwarding is off by default');denied(fn()=>$m->installMessageTools($a));$m->installMessageTools($operator);check(!$m->forwardingReady(),'schema does not enable forwarding');$m->set('message_tools_enabled',1);check($m->forwardingReady(),'explicit operator setup ready');
check(in_array('forwarding',(new MessengerMobileAuth($m))->discovery()['features'],true),'additive advertised capability');
$copy=forward($m,$a,$source,$target,[$message],'forward_text_operation');$sent=$copy['messages'][0];
check($sent['id']!==$message&&$sent['conversation_id']===$target&&$sent['sender_user_id']===$a->id,'independent destination identity and actual sender');
check($sent['body']==='Original private message'&&$sent['forwarded']===true,'original text preserved with typed forwarding marker');
check(!isset($sent['source_message_id'])&&!isset($sent['source_conversation_id'])&&!isset($sent['forwarded_from']),'no private source identity disclosed');
check(forward($m,$a,$source,$target,[$message],'forward_text_operation')['messages'][0]['id']===$sent['id'],'manual retry returns same copy');
$second=$m->sendMessage($source,$b,'Second message','forward_second_source');denied(fn()=>forward($m,$a,$source,$target,[$second['id']],'forward_text_operation'),'idempotency_conflict');
$m->deleteMessage($message,$b);check(forward($m,$a,$source,$target,[$message],'forward_text_operation')['messages'][0]['body']==='Original private message','retry remains independent after source deletion');
denied(fn()=>forward($m,$a,$source,$target,[$message],'forward_deleted_fresh'));
denied(fn()=>forward($m,$c,$source,$target,[$second['id']],'forward_no_source_user'));
denied(fn()=>forward($m,$b,$source,$target,[$second['id']],'forward_no_target_user'));
$m->hideMessage($second['id'],$a);denied(fn()=>forward($m,$a,$source,$target,[$second['id']],'forward_hidden_source'));
$media=['filename'=>'private.txt','mime_type'=>'text/plain','kind'=>'file','data_base64'=>base64_encode('Independent private file bytes')];$original=$m->sendAttachment($source,$b,$media,'forward_file_original');
$file=forward($m,$a,$source,$target,[$original['id']],'forward_file_operation')['messages'][0];
check($file['attachment']['id']!==$original['attachment']['id'],'attachment copy has an independent authorized ID');
check($m->attachment($c,$file['attachment']['id'])['data_base64']===$media['data_base64'],'destination recipient can read private copy');denied(fn()=>$m->attachment($c,$original['attachment']['id']));
$m->deleteMessage($original['id'],$b);check($m->attachment($c,$file['attachment']['id'])['data_base64']===$media['data_base64'],'source deletion does not invalidate destination copy');
$row=$db->query('SELECT result_ids FROM messenger_forward_ops LIMIT 1')->fetchColumn();check(str_starts_with($row,'menc:v1:'),'result identity encrypted at rest');
$png=['filename'=>'one.png','mime_type'=>'image/png','kind'=>'file','data_base64'=>'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jp1sAAAAASUVORK5CYII='];
$first=$m->sendAttachment($source,$b,$png,'album_'.str_repeat('x',43).'_0');$png['filename']='two.png';$last=$m->sendAttachment($source,$b,$png,'album_'.str_repeat('x',43).'_1');
$album=forward($m,$a,$source,$target,[$last['id'],$first['id']],'forward_album_operation')['messages'];
check(count($album)===2&&$album[0]['body']==='one.png','source order is canonical and independent of selection order');
check($album[0]['media_group_id']===$album[1]['media_group_id']&&$album[0]['media_group_id']!==str_repeat('x',43),'forwarded photo album has its own explicit server identity');
$db->prepare('INSERT INTO messenger_blocks(blocker_user_id,blocked_user_id) VALUES(?,?)')->execute([$a->id,$b->id]);check(forward($m,$a,$source,$target,[$message],'forward_text_operation')['messages'][0]['id']===$sent['id'],'existing destination copy does not reacquire source authority');denied(fn()=>forward($m,$a,$source,$target,[$first['id']],'forward_blocked_source'));$db->prepare('DELETE FROM messenger_blocks WHERE blocker_user_id=? AND blocked_user_id=?')->execute([$a->id,$b->id]);
$m->hideMessage($sent['id'],$a);$hidden=forward($m,$a,$source,$target,[$message],'forward_text_operation')['messages'][0];check($hidden['body']===''&&$hidden['deleted_at']!==null,'retry acknowledges hidden copy without restoring its content');
$editable=$m->sendMessage($source,$b,'Selected original text','forward_edit_original');
$view=$m->conversationMessages($source,$a,$editable['id']+1,$editable['id']-1,1)[0];$snapshot=$view['content_revision'];
$m->set('edit_window_minutes',60);$m->editMessage($editable['id'],$b,'Changed after selection');
denied(fn()=>$m->forwardMessages($a,$source,$target,[$editable['id']],'forward_changed_snapshot',[$editable['id']=>$snapshot]),'message_changed');
check((int)$db->query("SELECT COUNT(*) FROM messenger_forward_ops WHERE client_id='forward_changed_snapshot'")->fetchColumn()===0,'changed selection never creates an operation');
$latest=$m->conversationMessages($source,$a,$editable['id']+1,$editable['id']-1,1)[0];
$updated=$m->forwardMessages($a,$source,$target,[$editable['id']],'forward_updated_snapshot',[$editable['id']=>$latest['content_revision']]);check($updated['messages'][0]['body']==='Changed after selection','new explicit snapshot can forward current edited text');
[$m,$db,$a,$b,$c,$operator,$source,$target,$one]=setupForwarding();$m->installMessageTools($operator);$m->set('message_tools_enabled',1);$two=$m->sendMessage($source,$b,'Another original','forward_rollback_source');$m->set('max_messages_minute',1);
denied(fn()=>forward($m,$a,$source,$target,[$one,$two['id']],'forward_rollback_batch'));
check((int)$db->query('SELECT COUNT(*) FROM messenger_messages WHERE sender_user_id=11')->fetchColumn()===0,'batch failure rolls back earlier copied messages');
check((int)$db->query('SELECT COUNT(*) FROM messenger_forward_ops')->fetchColumn()===0&&(int)$db->query('SELECT COUNT(*) FROM messenger_message_markers')->fetchColumn()===0,'failed batch leaves no operation or marker');
check(!$db->inTransaction(),'owned transaction always closes');
[$m,$db,$a,$b,$c,$operator,$source,$target,$one]=setupForwarding();$m->installMessageTools($operator);$m->set('message_tools_enabled',1);$m->installGroups($operator);$m->set('groups_enabled',1);
$group=$m->createGroup($b,'Forwarding group',[$a->id,$c->id],'forward_group_setup');$gid=$group['id'];$old=$m->sendMessage($gid,$b,'Before membership','forward_before_membership');
$m->respondGroupInvitation($gid,$a,true);$m->respondGroupInvitation($gid,$c,true);
denied(fn()=>forward($m,$a,$gid,$target,[$old['id']],'forward_prejoin_denied'));
$fresh=$m->sendMessage($gid,$b,'Group original','forward_group_source');$fromGroup=forward($m,$a,$gid,$target,[$fresh['id']],'forward_group_to_direct')['messages'][0];check($fromGroup['body']==='Group original','member can explicitly forward visible group content');
$intoGroup=forward($m,$a,$source,$gid,[$one],'forward_direct_to_group')['messages'][0];check($intoGroup['forwarded']&&$intoGroup['conversation_id']===$gid,'accepted group destination supported');
$m->changeGroup($gid,$b,'remove',['user_id'=>$a->id]);denied(fn()=>forward($m,$a,$gid,$target,[$fresh['id']],'forward_removed_source'));denied(fn()=>forward($m,$a,$source,$gid,[$one],'forward_direct_to_group'));
[$m,$db,$a,$b,$c,$operator,$source,$target,$one]=setupForwarding();$m->installMessageTools($operator);$m->set('message_tools_enabled',1);$file=$m->sendAttachment($source,$b,['filename'=>'stopped.txt','mime_type'=>'text/plain','kind'=>'file','data_base64'=>base64_encode('Original file')],'forward_original_stopped');$db->prepare('UPDATE messenger_attachments SET payload=? WHERE message_id=?')->execute(['corrupted-fixture-envelope',$file['id']]);
denied(fn()=>forward($m,$a,$source,$target,[$one,$file['id']],'forward_file_rollback'));
check((int)$db->query('SELECT COUNT(*) FROM messenger_messages WHERE sender_user_id=11')->fetchColumn()===0,'unreadable file rolls back earlier copied text');check(!$db->inTransaction(),'attachment failure closes outer transaction');
echo 'Forwarding checks: '.$GLOBALS['checks'].PHP_EOL;
