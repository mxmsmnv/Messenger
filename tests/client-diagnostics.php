<?php declare(strict_types=1); namespace ProcessWire;
require __DIR__.'/support/bootstrap.php';
[$m,$db,$a,$b,$out]=fixture();
$admin=new class(1)extends User{public function isSuperuser(){return true;}};
check(!$m->clientDiagnosticsReady(),'no member/discovery-created schema');denied(fn()=>$m->installClientDiagnostics($a));
$m->installClientDiagnostics($admin);$m->installClientDiagnostics($admin);check($m->clientDiagnosticsReady(),'explicit idempotent install');
$ga=str_repeat('a',32);$gb=str_repeat('b',32);
foreach([$ga=>$a->id,$gb=>$b->id]as$g=>$u)$db->prepare('INSERT INTO messenger_mobile_grants VALUES(?,?,?,?,NULL)')->execute([$g,$u,'synthetic',time()]);
$chat=(int)$m->startConversation($a,$b,'not diagnostic content','diagnostic_fixture_01')['conversation']['id'];
$e=['id'=>str_repeat('1',32),'name'=>'message_send_tap','time_ms'=>time()*1000,'chat_id'=>$chat];
$body=['version'=>1,'platform'=>'ios','enabled'=>true,'events'=>[$e]];
$send=fn($actor,$grant,$v)=>$m->receiveClientDiagnostics($actor,$grant,'POST',$v);
check($send($a,$ga,$body)['accepted']===[$e['id']],'exact ack');check($send($a,$ga,$body)['accepted']===[$e['id']],'retry ack');
check((int)$db->query('SELECT COUNT(*) FROM messenger_client_diagnostics WHERE user_id>0')->fetchColumn()===1,'no duplicate');
$raw=$db->query('SELECT payload FROM messenger_client_diagnostics WHERE user_id>0')->fetchColumn();check(!str_contains($raw,'message_send_tap')&&!str_contains($raw,'not diagnostic content'),'encrypted metadata only');
$invalid=function($b)use($send,$a,$ga){try{$send($a,$ga,$b);}catch(\InvalidArgumentException $e){check(true,'invalid payload rejected');return;}throw new \RuntimeException('Expected invalid diagnostics');};
foreach(['body'=>'private','password'=>'secret','coordinates'=>[1,2]]as$k=>$v){$bad=$body;$bad['events'][0][$k]=$v;$invalid($bad);}
$bad=$body;$bad['enabled']=false;$invalid($bad);$bad=$body;$bad['events'][0]['values']=['text'=>12];$invalid($bad);$bad=$body;$bad['events'][0]['screen']=null;$invalid($bad);
denied(fn()=>$send($b,$ga,$body));denied(fn()=>$send($out,$ga,$body));
$bad=$body;$bad['events'][0]['chat_id']=$chat+999;denied(fn()=>$send($a,$ga,$bad));
$bad=$body;$bad['events'][0]['name']='screen_open';denied(fn()=>$send($a,$ga,$bad),'idempotency_conflict');
denied(fn()=>$m->clientDiagnostics($a));$export=$m->clientDiagnostics($admin,[],1);check($export[0]['data']['event']['chat_id']===$chat,'authorized correlation');check($m->clientDiagnostics($admin,$export[0]['cursor'],1)===[],'cursor advances with same timestamp');
$m->installExperimentalCalls($admin);
$id='11111111-1111-1111-1111-111111111111';
$db->prepare('INSERT INTO messenger_calls (id,conversation_id,caller_id,callee_id,caller_grant,answer_grant,state,created_at,expires_at,client_id,sequence) VALUES(?,?,?,?,?,?,?,?,?,?,?)')->execute([$id,$chat,$a->id,$b->id,$ga,$gb,'ended',time(),time()+60,'synthetic_call_id',0]);
$callBody=$body;$callBody['events'][0]=['id'=>str_repeat('2',32),'name'=>'call_quality','time_ms'=>time()*1000,'call_id'=>$id,'chat_id'=>$chat,'values'=>['rtt_ms'=>800.0,'loss_percent'=>12.0]];
check(count($send($a,$ga,$callBody)['accepted'])===1,'call and chat exact current grant accepted');denied(fn()=>$send($out,$ga,$callBody));
$db->exec('UPDATE messenger_client_diagnostics SET created_at=1 WHERE user_id>0');$m->pruneClientDiagnostics();check($m->clientDiagnostics($admin)===[],'seven day pruning');
$db->prepare('UPDATE messenger_mobile_grants SET revoked_at=? WHERE id=?')->execute([time(),$ga]);denied(fn()=>$send($a,$ga,$body));
// Saturating quota cannot accept an extra event or partially commit the batch.
$db->prepare('UPDATE messenger_mobile_grants SET revoked_at=NULL WHERE id=?')->execute([$ga]);
$db->beginTransaction();$q=$db->prepare('INSERT INTO messenger_client_diagnostics VALUES(?,?,?,?,?)');for($i=1;$i<=5000;$i++)$q->execute([str_pad(dechex($i),32,'0',STR_PAD_LEFT),str_repeat('f',64),(int)$a->id,'synthetic encrypted fixture',time()]);$db->commit();
try{$send($a,$ga,$body);throw new \RuntimeException('Expected quota');}catch(MessengerMobileException $e){check($e->httpStatus===429,'daily quota enforced');}
check((int)$db->query('SELECT COUNT(*) FROM messenger_client_diagnostics WHERE user_id>0')->fetchColumn()===5000,'quota rollback preserves count');
$db->exec('UPDATE messenger_client_diagnostics SET created_at=1 WHERE user_id>0');$m->processExperimentalCallPushes($admin,fn()=>throw new \RuntimeException('No provider should run'));check((int)$db->query('SELECT COUNT(*) FROM messenger_client_diagnostics WHERE user_id>0')->fetchColumn()===0,'existing call worker prunes diagnostics with message push and calls disabled');
echo ($GLOBALS['checks']??0)." client diagnostics checks passed\n";
