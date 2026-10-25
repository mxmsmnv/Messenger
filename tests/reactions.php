<?php declare(strict_types=1); namespace ProcessWire;
require __DIR__.'/support/bootstrap.php';
[$m,$db,$a,$b,$c]=fixture();$operator=new class(99)extends User{public function isSuperuser(){return true;}};
check(!$m->reactionsReady(),'reactions off by default');denied(fn()=>$m->installMessageTools($a));$m->installMessageTools($operator);check(!$m->reactionsReady(),'schema does not enable reactions');$m->set('message_tools_enabled',1);check($m->reactionsReady(),'operator-installed reaction schema ready');
$m->set('first_contact_mode','open');$started=$m->startConversation($a,$b,'A private original','reaction_source_start');$id=$started['conversation']['id'];$mid=$started['message']['id'];
$initial=$m->conversationMessages($id,$a)[0];check($initial['reactions']===[],'new message has an explicit empty reaction list');$revision=$initial['content_revision'];
$row=$m->reactToMessage($b,$mid,'👍');check($row['reactions'][0]['count']===1&&$row['reactions'][0]['mine']===true,'member sets a reaction');check($row['reactions'][0]['users'][0]['id']===$b->id,'actual reactor identity, not body authority');
check($m->reactToMessage($b,$mid,'👍')['reactions'][0]['count']===1,'identical desired state is idempotent');
$row=$m->reactToMessage($a,$mid,'👍');check($row['reactions'][0]['count']===2&&count($row['reactions'][0]['users'])===2,'counts aggregate actual participants');check($row['content_revision']===$revision,'reactions do not invalidate a frozen forwarding selection');
$row=$m->reactToMessage($b,$mid,'❤️');check(count($row['reactions'])===2,'a member can replace their reaction without changing another member');
$row=$m->reactToMessage($b,$mid,null);check(count($row['reactions'])===1&&$row['reactions'][0]['count']===1,'clear removes only actor reaction');check($m->reactToMessage($b,$mid,null)['reactions'][0]['count']===1,'repeated clear has no toggle effect');
check(str_starts_with((string)$db->query('SELECT emoji FROM messenger_reactions LIMIT 1')->fetchColumn(),'menc:v1:'),'emoji encrypted at rest');
denied(fn()=>$m->reactToMessage($c,$mid,'👍'));denied(fn()=>$m->reactToMessage($a,99999,'👍'));try{$m->reactToMessage($a,$mid,'<img src=x>');throw new \RuntimeException('Invalid emoji accepted');}catch(\InvalidArgumentException $e){check(true,'unsupported emoji rejected');}
$m->hideMessage($mid,$b);denied(fn()=>$m->reactToMessage($b,$mid,'👍'));
$next=$m->sendMessage($id,$a,'Later','reaction_second_message');$db->prepare('INSERT INTO messenger_blocks(blocker_user_id,blocked_user_id) VALUES(?,?)')->execute([$b->id,$a->id]);denied(fn()=>$m->reactToMessage($b,$next['id'],'👍'));$db->prepare('DELETE FROM messenger_blocks WHERE blocker_user_id=? AND blocked_user_id=?')->execute([$b->id,$a->id]);
$m->reactToMessage($b,$next['id'],'😂');$m->deleteMessage($next['id'],$a);check($m->conversationMessages($id,$a)[1]['reactions']===[],'deleted message exposes no reaction identities');denied(fn()=>$m->reactToMessage($b,$next['id'],'👍'));
$m->installGroups($operator);$m->set('groups_enabled',1);$g=$m->createGroup($a,'Reaction group',[$b->id,$c->id],'reaction_group_create');$gid=$g['id'];$before=$m->sendMessage($gid,$a,'Before join','reaction_prejoin_message');$m->respondGroupInvitation($gid,$b,true);denied(fn()=>$m->reactToMessage($b,$before['id'],'👍'));
$gm=$m->sendMessage($gid,$a,'Current members only','reaction_group_message');$m->reactToMessage($b,$gm['id'],'👍');$m->respondGroupInvitation($gid,$c,true);check($m->conversationMessages($gid,$c)===[],'new member cannot retrieve earlier reaction identities');
$m->changeGroup($gid,$a,'remove',['user_id'=>$b->id]);check($m->conversationMessages($gid,$a)[1]['reactions']===[],'removed reactor excluded from visible reaction counts');denied(fn()=>$m->reactToMessage($b,$gm['id'],'❤️'));
$m->changeGroup($gid,$a,'invite',['user_ids'=>[$b->id]]);$m->respondGroupInvitation($gid,$b,true);check($m->conversationMessages($gid,$a)[1]['reactions']===[],'rejoining cannot restore an old reaction');
$m->set('message_tools_enabled',0);check(!isset($m->conversationMessages($id,$a)[0]['reactions']),'disabled feature preserves legacy message shape');denied(fn()=>$m->reactToMessage($a,$mid,null));check(!$db->inTransaction(),'no transaction leak');
[$m,$db,$a,$b]=fixture();$m->installMessageTools($operator);$m->set('message_tools_enabled',1);$m->set('first_contact_mode','open');$fresh=$m->startConversation($a,$b,'Reaction rate fixture','reaction_rate_fixture');$mid=$fresh['message']['id'];
for($i=0;$i<60;$i++)$m->reactToMessage($a,$mid,$i%2===0?'👍':'❤️');
try{$m->reactToMessage($a,$mid,'😂');throw new \RuntimeException('Reaction rate not enforced');}catch(MessengerMobileException $e){check($e->apiCode==='rate_limited','bounded rate stops state-changing writes');}
check($m->reactToMessage($a,$mid,'❤️')['reactions'][0]['count']===1,'exact repeat remains idempotent after rate limit');check(!$db->inTransaction(),'rate failure closes its transaction');
$db->exec('DROP TABLE messenger_reaction_locks');check(!$m->reactionsReady(),'incomplete schema stays unavailable');denied(fn()=>$m->reactToMessage($a,$mid,null));
echo 'Reaction checks: '.$GLOBALS['checks'].PHP_EOL;
