<?php declare(strict_types=1); namespace ProcessWire;
require __DIR__.'/support/bootstrap.php';
function mint(MessengerMobileAuth $auth,User $user):array {
    $v=str_repeat('v',64);$challenge=rtrim(strtr(base64_encode(hash('sha256',$v,true)),'+/','-_'),'=');
    $url=$auth->approve($user,['redirect_uri'=>'org.example.narzan://callback','state'=>str_repeat('s',32),'code_challenge'=>$challenge,'code_challenge_method'=>'S256','device_name'=>'Recovery fixture']);parse_str((string)parse_url($url,PHP_URL_QUERY),$query);
    return $auth->exchange(['code'=>$query['code'],'code_verifier'=>$v,'redirect_uri'=>'org.example.narzan://callback']);
}
[$m,$db,$a]=fixture();$auth=new MessengerMobileAuth($m);
check(in_array('refresh_recovery',$auth->discovery()['features'],true),'capability requires installed receipt schema');
$old=mint($auth,$a);$body=['refresh_token'=>$old['refresh_token'],'recovery_id'=>str_repeat('n',43)];
$new=$auth->refresh($body);$again=$auth->refresh($body);
check($new['access_token']===$again['access_token']&&$new['refresh_token']===$again['refresh_token'],'lost response retries exact replacement credentials');
check($again['expires_in']<=600,'retry does not extend access expiry');
check($auth->authenticate('Bearer '.$again['access_token'])->id===$a->id,'recovered access usable');
$stored=json_encode($db->query('SELECT * FROM messenger_mobile_receipts')->fetchAll(\PDO::FETCH_ASSOC));
check(!str_contains($stored,$new['access_token'])&&!str_contains($stored,$new['refresh_token'])&&!str_contains($stored,$body['recovery_id']),'receipts encrypted, nonce hashed');
denied(fn()=>$auth->refresh(array_replace($body,['recovery_id'=>str_repeat('x',43)])),'refresh_reuse');
denied(fn()=>$auth->authenticate('Bearer '.$new['access_token']),'unauthorized');
$old=mint($auth,$a);$body['refresh_token']=$old['refresh_token'];$new=$auth->refresh($body);$auth->refresh(['refresh_token'=>$new['refresh_token'],'recovery_id'=>str_repeat('z',43)]);
denied(fn()=>$auth->refresh($body),'invalid_grant');
$old=mint($auth,$a);$body['refresh_token']=$old['refresh_token'];$auth->refresh($body);$auth->revoke(['refresh_token'=>$old['refresh_token']]);denied(fn()=>$auth->refresh($body),'invalid_grant');
$old=mint($auth,$a);$body['refresh_token']=$old['refresh_token'];$auth->refresh($body);$db->exec('UPDATE messenger_mobile_receipts SET expires_at=0');denied(fn()=>$auth->refresh($body),'refresh_reuse');
$db->exec('DROP TABLE messenger_mobile_receipts');check(!in_array('refresh_recovery',$auth->discovery()['features'],true),'old site does not advertise recovery');
echo 'Refresh recovery checks: '.$GLOBALS['checks'].PHP_EOL;
