<?php declare(strict_types=1); namespace ProcessWire;
$root='';foreach(array_slice($argv,1)as$arg)if(str_starts_with($arg,'--root='))$root=rtrim(substr($arg,7),'/');
if(!$root||!is_file($root.'/index.php')||trim((string)@file_get_contents(dirname($root).'/.narzan-disposable'))!=='narzan-groups-integration-v1')throw new \RuntimeException('Owned disposable fixture required.');
require $root.'/index.php';$db=wire('database');
if(!preg_match('/^narzan_groups_[a-f0-9]{8}$/D',(string)$db->query('SELECT DATABASE()')->fetchColumn()))throw new \RuntimeException('Dedicated cloned database required.');
function check(bool $ok,string $why):void{if(!$ok)throw new \RuntimeException($why);$GLOBALS['checks']=($GLOBALS['checks']??0)+1;}
function denied(callable $op):void{try{$op();}catch(WireException $e){check(true,'permission enforced');return;}throw new \RuntimeException('Expected denial');}
$m=wire('modules')->get('Messenger');check(realpath((new \ReflectionClass($m))->getFileName())===realpath($root.'/site/modules/Messenger/Messenger.module.php'),'candidate runtime belongs to clone');
$operator=wire('users')->get('roles=superuser');wire('users')->setCurrentUser($operator);$m->install();$m->installMessageTools($operator);$m->set('message_tools_enabled',1);$m->set('first_contact_policy','open');check($m->forwardingReady(),'real schema ready');
$suffix=bin2hex(random_bytes(4));$accounts=[];foreach(['a','b','c']as$l){$u=new User();$u->name='forward-'.$l.'-'.$suffix;$u->email=$u->name.'@example.test';$u->pass=bin2hex(random_bytes(24));$u->save();$accounts[]=$u;}[$a,$b,$c]=$accounts;
$as=function(User $actor,callable $op){$previous=wire('user');try{wire('users')->setCurrentUser($actor);return $op();}finally{wire('users')->setCurrentUser($previous);}};
$src=$as($b,fn()=>$m->startConversation($b,$a,'Original secret','mysql_forward_src_'.$suffix));$source=$src['conversation']['id'];$as($a,fn()=>$m->acceptRequest($source,$a));
$dst=$as($c,fn()=>$m->startConversation($c,$a,'Target chat','mysql_forward_dst_'.$suffix));$target=$dst['conversation']['id'];$as($a,fn()=>$m->acceptRequest($target,$a));
$id=$src['message']['id'];$revision=$as($a,fn()=>$m->conversationMessages($source,$a,$id+1,$id-1,1))[0]['content_revision'];
$copy=$as($a,fn()=>$m->forwardMessages($a,$source,$target,[$id],'mysql_forward_op_'.$suffix,[$id=>$revision]))['messages'][0];
check($copy['body']==='Original secret'&&$copy['forwarded']===true&&$copy['id']!==$id,'real MySQL independent copy');
check($as($c,fn()=>$m->conversationMessages($target,$c))[1]['forwarded']===true,'recipient receives forwarding marker');
check($as($a,fn()=>$m->forwardMessages($a,$source,$target,[$id],'mysql_forward_op_'.$suffix,[$id=>$revision]))['messages'][0]['id']===$copy['id'],'retry once only');
$as($c,fn()=>denied(fn()=>$m->forwardMessages($c,$source,$target,[$id],'mysql_denied_'.$suffix,[$id=>$revision])));
$file=['filename'=>'fixture.txt','mime_type'=>'text/plain','kind'=>'file','data_base64'=>base64_encode('Independent native file')];$media=$as($b,fn()=>$m->sendAttachment($source,$b,$file,'mysql_file_'.$suffix));$mid=$media['id'];$rev=$as($a,fn()=>$m->conversationMessages($source,$a,$mid+1,$mid-1,1))[0]['content_revision'];
$attachment=$as($a,fn()=>$m->forwardMessages($a,$source,$target,[$mid],'mysql_copy_file_'.$suffix,[$mid=>$rev]))['messages'][0]['attachment'];
check($attachment['id']!==$media['attachment']['id'],'new attachment identity');$as($b,fn()=>$m->deleteMessage($mid,$b));
check($as($c,fn()=>$m->attachment($c,$attachment['id']))['data_base64']===$file['data_base64'],'original deletion preserves private copy');
$as($c,fn()=>denied(fn()=>$m->attachment($c,$media['attachment']['id'])));
$q=$db->prepare('SELECT result_ids FROM messenger_forward_ops WHERE user_id=?');$q->execute([(int)$a->id]);check(str_starts_with((string)$q->fetchColumn(),'menc:v1:'),'result IDs encrypted in actual MySQL');
$m->set('edit_window_minutes',60);$edit=$as($b,fn()=>$m->sendMessage($source,$b,'Before selection','mysql_edit_'.$suffix));$eid=$edit['id'];$rev=$as($a,fn()=>$m->conversationMessages($source,$a,$eid+1,$eid-1,1))[0]['content_revision'];$as($b,fn()=>$m->editMessage($eid,$b,'Changed later'));
$as($a,fn()=>denied(fn()=>$m->forwardMessages($a,$source,$target,[$eid],'mysql_changed_'.$suffix,[$eid=>$rev])));
check(!$db->inTransaction(),'no leaked transaction');
echo json_encode(['checks'=>$GLOBALS['checks'],'database'=>'isolated MySQL clone','processwire'=>wire('config')->version,'live_site_modified'=>false],JSON_PRETTY_PRINT).PHP_EOL;
