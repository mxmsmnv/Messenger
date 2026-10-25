<?php declare(strict_types=1); namespace ProcessWire;
require __DIR__.'/support/bootstrap.php';
function grant(MessengerMobileAuth $auth,User $actor,string $name='Synthetic device'):array{
    $v=str_repeat('v',64);$request=['redirect_uri'=>'org.example.narzan://callback','state'=>str_repeat('s',32),'code_challenge'=>rtrim(strtr(base64_encode(hash('sha256',$v,true)),'+/','-_'),'='),'code_challenge_method'=>'S256','device_name'=>$name];
    parse_str((string)parse_url($auth->approve($actor,$request),PHP_URL_QUERY),$query);
    return $auth->exchange(['code'=>$query['code'],'code_verifier'=>$v,'redirect_uri'=>$request['redirect_uri']]);
}
[$m,$db,$a,$b]=fixture();$auth=new MessengerMobileAuth($m);$current=grant($auth,$a,'Phone');$other=grant($auth,$a,'Tablet');$foreign=grant($auth,$b,'Another member');
check(in_array('device_sessions',$auth->discovery()['features'],true),'advertised without new schema');
$rows=$auth->devices($a,$current['connection_id']);check(count($rows)===2&&$rows[0]['current']===true&&$rows[1]['current']===false,'current device first and correctly labeled');
check(is_int($rows[0]['created_at'])&&$rows[0]['device_name']==='Phone','only human name and actual creation time');
check(!str_contains(json_encode($rows),$current['access_token'])&&!str_contains(json_encode($rows),$current['refresh_token']),'no credential in device metadata');
denied(fn()=>$auth->revokeOtherDevices($b,$current['connection_id']),'access_denied');
$auth->revokeDevice($a,$foreign['connection_id']);check($auth->authenticate('Bearer '.$foreign['access_token'])->id===$b->id,'cannot revoke another member');
check($auth->revokeOtherDevices($a,$current['connection_id'])===1,'bulk ends only other grants of this actor');
check($auth->authenticate('Bearer '.$current['access_token'])->id===$a->id,'current grant preserved');
denied(fn()=>$auth->authenticate('Bearer '.$other['access_token']),'unauthorized');denied(fn()=>$auth->refresh(['refresh_token'=>$other['refresh_token']]),'invalid_grant');
check($auth->revokeOtherDevices($a,$current['connection_id'])===0,'bulk retry is idempotent');check(count($auth->devices($a))===1,'revoked grants omitted');
$expired=grant($auth,$a);$q=$db->prepare('UPDATE messenger_mobile_tokens SET expires_at=0 WHERE grant_id=?');$q->execute([$expired['connection_id']]);check(count($auth->devices($a))===1,'expired connections are not advertised as active');
$auth->revokeDevice($a,$current['connection_id']);denied(fn()=>$auth->authenticate('Bearer '.$current['access_token']),'unauthorized');denied(fn()=>$auth->refresh(['refresh_token'=>$current['refresh_token']]),'invalid_grant');
check($auth->authenticate('Bearer '.$foreign['access_token'])->id===$b->id,'other account survives all operations');
denied(fn()=>$auth->revokeOtherDevices($a,$current['connection_id']),'access_denied');
echo 'Device session checks: '.$GLOBALS['checks'].PHP_EOL;
