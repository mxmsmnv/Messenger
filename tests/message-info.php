<?php declare(strict_types=1); namespace ProcessWire;
require __DIR__.'/support/bootstrap.php';

function acceptedInfoFixture(): array {
    [$m,$db,$a,$b,$c]=fixture();
    $m->set('first_contact_mode','open')->set('max_messages_minute',100);
    $started=$m->startConversation($a,$b,'synthetic metadata source','info_fixture_0001');
    return [$m,$db,$a,$b,$c,(int)$started['conversation']['id'],(int)$started['message']['id']];
}
function infoDenied(callable $call): void {
    try {$call();} catch(WirePermissionException $error) {check($error->getMessage()==='Message unavailable.','metadata denial is uniform');return;}
    throw new \RuntimeException('Expected uniform metadata denial');
}

[$m,$db,$a,$b,$c,$conversation,$id]=acceptedInfoFixture();
$created=(string)$db->query('SELECT created_at FROM messenger_messages WHERE id='.$id)->fetchColumn();
$info=$m->messageInfo($id,$a);
check($info===['message_id'=>$id,'sent_at'=>$created,'is_own'=>true,'is_read'=>false,'read_at'=>null],'own unread metadata contains only authoritative timestamps and flags');
$other=$m->messageInfo($id,$b);
check(!$other['is_own']&&!$other['is_read']&&$other['read_at']===null,'incoming metadata uses current reader cursor');
infoDenied(fn()=>$m->messageInfo($id,$c));
infoDenied(fn()=>$m->messageInfo($id,new User(0)));
infoDenied(fn()=>$m->messageInfo(999999,$a));
infoDenied(fn()=>$m->messageInfo(0,$a));
infoDenied(fn()=>$m->messageInfo(-1,$a));

// A real cursor advance creates an existing audit; make its authoritative time deterministic.
$m->markRead($conversation,$b,$id);
$db->exec("UPDATE messenger_audit SET created_at='2026-10-19 03:04:05' WHERE action='conversation_read' AND actor_user_id=".$b->id);
$own=$m->messageInfo($id,$a);$incoming=$m->messageInfo($id,$b);
check($own['is_read']&&$own['read_at']==='2026-10-19 03:04:05','own info exposes first peer read audit');
check($incoming['is_read']&&$incoming['read_at']===$own['read_at'],'incoming info exposes current member read audit');
// Later repeated/superset cursors, other actions, users and conversations cannot replace the first read.
$insert=$db->prepare('INSERT INTO messenger_audit(actor_user_id,action,conversation_id,message_id,metadata,created_at) VALUES(?,?,?,?,?,?)');
$insert->execute([$b->id,'conversation_read',$conversation,$id+100,'{}','2026-10-19 06:00:00']);
$insert->execute([$a->id,'conversation_read',$conversation,$id,'{}','2026-10-19 01:00:00']);
$insert->execute([$b->id,'message_edited',$conversation,$id,'{}','2026-10-19 00:00:00']);
$insert->execute([$c->id,'conversation_read',$conversation,$id,'{}','2026-10-18 00:00:00']);
$insert->execute([$b->id,'conversation_read',$conversation+500,$id,'{}','2026-10-17 00:00:00']);
check($m->messageInfo($id,$a)['read_at']==='2026-10-19 03:04:05','audit lookup is reader/action/conversation scoped and earliest');
$db->exec('UPDATE messenger_participants SET last_read_message_id=0 WHERE user_id='.$b->id);
check($m->messageInfo($id,$a)['read_at']===null&&!$m->messageInfo($id,$a)['is_read'],'old audit cannot override current cursor');
$db->exec('UPDATE messenger_participants SET last_read_message_id='.$id.' WHERE user_id='.$b->id);
$db->exec("DELETE FROM messenger_audit WHERE action='conversation_read' AND conversation_id=$conversation AND actor_user_id=".$b->id);
check($m->messageInfo($id,$a)['is_read']&&$m->messageInfo($id,$a)['read_at']===null,'legacy cursor without retained audit remains read with unknown time');
// An audit below this message cannot invent a read timestamp.
$insert->execute([$b->id,'conversation_read',$conversation,$id-1,'{}','2026-10-18 00:00:00']);
check($m->messageInfo($id,$a)['read_at']===null,'insufficient audit cursor never supplies time');

foreach(['deleted','expired','moderated','hidden','hidden-conversation','blocked-by-actor','blocked-by-peer','restricted-actor','restricted-peer','pending','declined','closed','third-participant'] as $case) {
    [$m,$db,$a,$b,$c,$conversation,$id]=acceptedInfoFixture();
    switch($case) {
        case 'deleted':$m->deleteMessage($id,$a);break;
        case 'expired':$db->exec('UPDATE messenger_messages SET expires_at='.(time()-1).' WHERE id='.$id);break;
        case 'moderated':$db->exec("UPDATE messenger_messages SET moderation_state='hidden' WHERE id=$id");break;
        case 'hidden':$m->hideMessage($id,$a);break;
        case 'hidden-conversation':$db->exec('UPDATE messenger_participants SET hidden_through_message_id='.$id.' WHERE user_id='.$a->id);break;
        case 'blocked-by-actor':$db->exec('INSERT INTO messenger_blocks VALUES('.$a->id.','.$b->id.')');break;
        case 'blocked-by-peer':$db->exec('INSERT INTO messenger_blocks VALUES('.$b->id.','.$a->id.')');break;
        case 'restricted-actor':$db->exec("INSERT INTO messenger_restrictions VALUES($a->id,'suspended','fixture',NULL)");break;
        case 'restricted-peer':$db->exec("INSERT INTO messenger_restrictions VALUES($b->id,'suspended','fixture',NULL)");break;
        case 'pending':case 'declined':$db->exec("UPDATE messenger_conversations SET request_state='$case' WHERE id=$conversation");break;
        case 'closed':$db->exec("UPDATE messenger_conversations SET state='closed' WHERE id=$conversation");break;
        case 'third-participant':
            $db->exec('INSERT INTO messenger_participants(conversation_id,user_id,last_read_message_id) VALUES('.$conversation.','.$c->id.',0)');$a=$c;break;
    }
    $before=(int)$db->query('SELECT total_changes()')->fetchColumn();
    infoDenied(fn()=>$m->messageInfo($id,$a));
    check((int)$db->query('SELECT total_changes()')->fetchColumn()===$before,'denied metadata is read-only: '.$case);
}

[$m,$db,$a,$b,$c,$conversation,$id]=acceptedInfoFixture();
$oldCreated=(string)$db->query('SELECT created_at FROM messenger_messages WHERE id='.$id)->fetchColumn();
for($i=0;$i<55;$i++)$m->sendMessage($conversation,$a,'synthetic later '.$i,'info_later_'.str_pad((string)$i,10,'0',STR_PAD_LEFT));
check(!in_array($id,array_column($m->conversationMessages($conversation,$a),'id'),true),'target is genuinely older than the latest message page');
// Make a different row expired: metadata GET must not run the ordinary expiry sweeper.
$db->exec('UPDATE messenger_messages SET expires_at='.(time()-1).' WHERE id='.($id+1));
$before=(int)$db->query('SELECT total_changes()')->fetchColumn();
check($m->messageInfo($id,$a)['sent_at']===$oldCreated,'direct metadata fetch supports an older authorized message');
check((int)$db->query('SELECT total_changes()')->fetchColumn()===$before,'successful metadata performs no sweep/audit/write');
check($db->query('SELECT deleted_at FROM messenger_messages WHERE id='.($id+1))->fetchColumn()===null,'unrelated expired row is not mutated');
check(in_array('message_info',(new MessengerMobileAuth($m))->discovery()['features'],true),'optional metadata feature is advertised');
echo 'Message info checks: '.$GLOBALS['checks'].PHP_EOL;
