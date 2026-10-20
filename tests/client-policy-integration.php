<?php declare(strict_types=1); namespace ProcessWire;
// Real ProcessWire/MySQL/TLS, with both browser-session/CSRF and native bearer transports.
$root='';foreach(array_slice($argv,1) as $arg)if(str_starts_with($arg,'--root='))$root=rtrim(substr($arg,7),'/');
if($root===''||trim((string)@file_get_contents(dirname($root).'/.narzan-disposable'))!=='narzan-emulator-fixture-v1')throw new \RuntimeException('Marked disposable root required');
require $root.'/index.php';if(wire('config')->dbName!=='narzan_fresh')throw new \RuntimeException('Dedicated fixture DB required');
$m=wire('modules')->get('Messenger');$original=wire('modules')->getConfig('Messenger');$settings=$original;$checks=0;$tokens=[];$csrf=[];
$cookie=tempnam(dirname($root),'policy-cookie-');chmod($cookie,0600);
function check(bool $ok,string $why):void{global $checks;if(!$ok)throw new \RuntimeException($why);$checks++;}
function configure(array $patch):void{global $m,$settings;$settings=array_replace($settings,$patch);wire('modules')->saveConfig('Messenger',$settings);foreach($patch as $k=>$v)$m->set($k,$v);}
function http(string $path,bool $native=true,?array $body=null,int $expected=200,bool $form=false):array {
 global $root,$tokens,$csrf,$cookie;
 $c=curl_init('https://localhost:19443'.$path);$headers=['Accept: application/json'];
 if($native)$headers[]='Authorization: Bearer '.$tokens['access_token'];
 if($body!==null){if(!$native&&!$form)$body[$csrf['name']]=$csrf['value'];$headers[]='Content-Type: '.($form?'application/x-www-form-urlencoded':'application/json');}
 curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>20,CURLOPT_CAINFO=>dirname($root).'/tls.crt',CURLOPT_HTTPHEADER=>$headers,CURLOPT_FOLLOWLOCATION=>false]);
 if(!$native){curl_setopt($c,CURLOPT_COOKIEJAR,$cookie);curl_setopt($c,CURLOPT_COOKIEFILE,$cookie);}
 if($body!==null){curl_setopt($c,CURLOPT_POST,true);curl_setopt($c,CURLOPT_POSTFIELDS,$form?http_build_query($body):json_encode($body,JSON_THROW_ON_ERROR));}
 $raw=curl_exec($c);$status=curl_getinfo($c,CURLINFO_RESPONSE_CODE);curl_close($c);
 check(is_string($raw)&&$status===$expected,'HTTP status '.$expected.' for '.strtok($path,'?').', got '.$status);
 if($status>=300&&$status<400)return [];
 return json_decode($raw,true,512,JSON_THROW_ON_ERROR);
}
function api(string $resource,bool $native=true,?array $body=null,int $expected=200):array {
 $parts=explode('?',$resource,2);return http('/messenger-api/v1/'.$parts[0].'/'.(isset($parts[1])?'?'.$parts[1]:''),$native,$body,$expected)['result']??[];
}
function peer():User{$u=new User();$u->name='narzan-policy-api-'.bin2hex(random_bytes(5));$u->pass=bin2hex(random_bytes(24));$u->save();return $u;}
function clientId():string{return 'policy_api_'.bin2hex(random_bytes(12));}
try {
 configure(['mobile_enabled'=>1,'mobile_origin'=>'https://localhost:19443','mobile_redirect_uris'=>'','max_messages_minute'=>1000,'max_new_recipients_day'=>1000,'max_pending_request_messages'=>2,'max_message_length'=>5000]);
 $login=http('/test-login/',false);$loginCsrf=$login['csrf'];
 http('/test-login/',false,['fixture'=>'alice',$loginCsrf['name']=>$loginCsrf['value'],'return'=>'/messages/'],301,true);
 $session=api('session',false);$csrf=$session['csrf'];check($session['can_use']&&$session['name']==='narzan-alice','real browser fixture login');
 $a=wire('users')->get('narzan-alice');wire('users')->setCurrentUser($a);
 $auth=new MessengerMobileAuth($m);$verifier=str_repeat('p',64);$redirect='org.smnv.narzan.dev://auth/callback';
 $callback=$auth->approve($a,['redirect_uri'=>$redirect,'state'=>str_repeat('s',32),'code_challenge'=>rtrim(strtr(base64_encode(hash('sha256',$verifier,true)),'+/','-_'),'='),'code_challenge_method'=>'S256','device_name'=>'Synthetic policy API']);
 parse_str((string)parse_url($callback,PHP_URL_QUERY),$parts);$tokens=$auth->exchange(['code'=>$parts['code'],'code_verifier'=>$verifier,'redirect_uri'=>$redirect]);unset($callback,$parts);
 foreach(['open','requests','closed'] as $mode){
  configure(['first_contact_mode'=>$mode]);$p=peer();
  $policy=api('client-policy');check($policy===api('client-policy',false)&&$policy['first_contact_mode']===$mode,'same policy in both transports');
  $context=api('recipient-context?user_id='.$p->id);check($context===api('recipient-context?user_id='.$p->id,false),'same recipient decision');
  check($context['conversation']===null&&$context['decision']===['open'=>'allow','requests'=>'request','closed'=>'deny'][$mode],'configured new-pair behavior');
  $body=['recipient_id'=>(int)$p->id,'body'=>'Policy first','client_id'=>clientId()];
  if($mode==='closed'){api('start',true,$body,403);api('start',false,array_replace($body,['client_id'=>clientId()]),403);continue;}
  $r=api('start',true,$body);$id=$r['conversation']['id'];
  check($r['conversation']['request_state']===($mode==='open'?'accepted':'pending'),'actual first-contact state');
  if($mode==='requests'){
   check(api('conversation?id='.$id)['can_send']===true,'requester has configured second message');
   api('send',false,['id'=>$id,'body'=>'Policy second','client_id'=>clientId()]);
   check(api('conversation?id='.$id)['can_send']===false,'configured request allowance exhausted');
   api('send',true,['id'=>$id,'body'=>'Policy excess','client_id'=>clientId()],403);
   api('send-attachment',true,['id'=>$id,'client_id'=>clientId()],403);
   wire('users')->setCurrentUser($p);$m->acceptRequest($id,$p);wire('users')->setCurrentUser($a);
  }
  api('archive',false,['id'=>$id,'archived'=>true]);configure(['first_contact_mode'=>'closed']);
  $existing=api('recipient-context?user_id='.$p->id);check($existing['conversation']['id']===$id&&$existing['decision']==='existing','archived existing pair survives closed first contact');
  api('send',true,['id'=>$id,'body'=>'Continued','client_id'=>clientId()]);
 }
 configure(['max_message_length'=>50000]);foreach([true,false] as $native){$large=api('send',$native,['id'=>$id,'body'=>str_repeat('😀',50000),'client_id'=>clientId()]);check(mb_strlen($large['body'])===50000,'configured large Unicode message survives JSON transport');}
 configure(['max_message_length'=>3,'edit_window_minutes'=>0,'delete_window_minutes'=>0,'poll_thread_seconds'=>-1,'poll_inbox_seconds'=>-1]);
 $policy=api('client-policy');check($policy['max_message_length']===3&&$policy['poll_thread_seconds']===3&&$policy['poll_inbox_seconds']===10,'effective lengths and minimum polling bounds');
 foreach([true,false] as $native){api('send',$native,['id'=>$id,'body'=>'four','client_id'=>clientId()],400);}
 $sent=api('send',true,['id'=>$id,'body'=>'😀ab','client_id'=>clientId()]);check($sent['can_edit']===false&&$sent['can_delete_for_everyone']===false,'zero windows reflected in send payload');
 foreach([true,false] as $native){api('edit',$native,['message_id'=>$sent['id'],'body'=>'new'],403);api('delete',$native,['message_id'=>$sent['id']],403);}
 configure(['edit_window_minutes'=>15,'delete_window_minutes'=>15]);$rows=api('messages?id='.$id);$last=end($rows);check($last['can_edit']&&$last['can_delete_for_everyone']&&str_contains($last['created_at_iso'],'T'),'current own message presentation follows changed windows');
 configure(['max_messages_minute'=>1]);foreach([true,false] as $native)api('send',$native,['id'=>$id,'body'=>'new','client_id'=>clientId()],429);
 configure(['max_messages_minute'=>1000,'max_new_recipients_day'=>1,'first_contact_mode'=>'open']);$p=peer();foreach([true,false] as $native)api('start',$native,['recipient_id'=>(int)$p->id,'body'=>'new','client_id'=>clientId()],429);
 configure(['mobile_enabled'=>0]);api('session',true,null,404);check(api('session',false)['can_use']===true,'disabling mobile preserves browser session');
 configure(['mobile_enabled'=>1]);check(api('session')['can_use']===true,'re-enabling preserves valid grant');
 echo json_encode(['checks'=>$checks,'verified_tls'=>true,'browser_csrf_and_native_bearer'=>true,'settings_restored_in_finally'=>true]),PHP_EOL;
}finally{wire('modules')->saveConfig('Messenger',$original);if(is_file($cookie))unlink($cookie);}
