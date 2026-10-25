<?php declare(strict_types=1); namespace ProcessWire;
// Reuses its guarded disposable real-ProcessWire setup and independent media checks.
require __DIR__.'/forwarding-integration.php';
$before=$GLOBALS['checks'];
check($m->reactionsReady(),'real reaction schema ready');$revision=$as($a,fn()=>$m->conversationMessages($source,$a,$eid+1,$eid-1,1))[0]['content_revision'];
$one=$as($a,fn()=>$m->reactToMessage($a,$eid,'👍'));check($one['reactions'][0]['count']===1&&$one['reactions'][0]['mine'],'real actor reaction');
$two=$as($b,fn()=>$m->reactToMessage($b,$eid,'👍'));check($two['reactions'][0]['count']===2,'real MySQL aggregate');check($two['content_revision']===$revision,'reaction does not change selection revision');
$as($b,fn()=>$m->reactToMessage($b,$eid,'👍'));check((int)$db->query("SELECT COUNT(*) FROM messenger_audit WHERE action='message_reacted' AND message_id=".$eid)->fetchColumn()===2,'exact retry adds no audit or toggle');
$q=$db->prepare('SELECT emoji FROM messenger_reactions WHERE message_id=? LIMIT 1');$q->execute([$eid]);check(str_starts_with((string)$q->fetchColumn(),'menc:v1:'),'real reaction encryption');
$as($c,fn()=>denied(fn()=>$m->reactToMessage($c,$eid,'❤️')));$clear=$as($b,fn()=>$m->reactToMessage($b,$eid,null));check($clear['reactions'][0]['count']===1&&!$clear['reactions'][0]['mine'],'clear preserves other member state');
$m->installGroups($operator);$m->set('groups_enabled',1);$g=$as($a,fn()=>$m->createGroup($a,'Reaction fixture',[(int)$b->id,(int)$c->id],'mysql_reaction_group_'.$suffix));$gid=$g['id'];$as($b,fn()=>$m->respondGroupInvitation($gid,$b,true));
$gm=$as($a,fn()=>$m->sendMessage($gid,$a,'React here','mysql_reaction_group_message_'.$suffix));$as($b,fn()=>$m->reactToMessage($b,$gm['id'],'❤️'));
check($as($a,fn()=>$m->conversationMessages($gid,$a))[0]['reactions'][0]['users'][0]['id']===(int)$b->id,'group actual author');
$as($a,fn()=>$m->changeGroup($gid,$a,'remove',['user_id'=>(int)$b->id]));check($as($a,fn()=>$m->conversationMessages($gid,$a))[0]['reactions']===[],'removed reactor excluded');$as($b,fn()=>denied(fn()=>$m->reactToMessage($b,$gm['id'],'👍')));
$as($c,fn()=>$m->respondGroupInvitation($gid,$c,true));$as($c,fn()=>denied(fn()=>$m->reactToMessage($c,$gm['id'],'👍')));
check(!$db->inTransaction(),'no transaction remains');
echo json_encode(['reaction_checks'=>$GLOBALS['checks']-$before,'database'=>'isolated MySQL clone','processwire'=>wire('config')->version,'live_site_modified'=>false],JSON_PRETTY_PRINT).PHP_EOL;
