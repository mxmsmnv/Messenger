<?php declare(strict_types=1);namespace ProcessWire;
require __DIR__.'/support/bootstrap.php';
require dirname(__DIR__).'/src/MessengerCallPush.php';
// Isolated protocol boundary: run the real sender and signing code with a fake
// HTTPS transport. No requests or credentials reach Google/Apple in this test.
function curl_init($url){return (object)['url'=>$url,'options'=>[],'status'=>0];}
function curl_setopt_array($ch,$options){$ch->options=$options;return true;}
function curl_setopt($ch,$option,$value){$ch->options[$option]=$value;return true;}
function curl_exec($ch){
    $GLOBALS['provider_requests'][]=['url'=>$ch->url,'options'=>$ch->options];
    if($ch->url==='https://oauth2.googleapis.com/token'){$ch->status=200;$body='{"access_token":"synthetic-test-access"}';}
    else {[$ch->status,$body]=$GLOBALS['provider_response'];}
    ($ch->options[CURLOPT_WRITEFUNCTION])($ch,$body);return true;
}
function curl_getinfo($ch,$option){return $ch->status;}
function curl_close($ch){}
$directory=dirname(__DIR__).'/.work/todo-20261024';if(!is_dir($directory))mkdir($directory,0700,true);
$rsa=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_RSA,'private_key_bits'=>2048]);openssl_pkey_export($rsa,$rsaPrivate);
$ec=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_EC,'curve_name'=>'prime256v1']);openssl_pkey_export($ec,$ecPrivate);
$firebase=tempnam($directory,'firebase-test-');$apple=tempnam($directory,'apple-test-');
try {
    file_put_contents($firebase,json_encode(['client_email'=>'synthetic@example.test','private_key'=>$rsaPrivate]));chmod($firebase,0600);
    file_put_contents($apple,$ecPrivate);chmod($apple,0600);
    $sender=new MessengerCallPush(['firebase_project'=>'narzan-synthetic','firebase_credentials'=>$firebase,'apns_key_id'=>'KEY1234567','apns_team_id'=>'TEAM123456','apns_private_key'=>$apple,'apns_bundle_id'=>'org.smnv.narzan.dev']);
    $GLOBALS['provider_response']=[200,'{}'];$GLOBALS['provider_requests']=[];
    check($sender->sendMessage('android','synthetic-registration',10,3,'https://example.test#site#11',true),'standard FCM request accepted');
    $last=end($GLOBALS['provider_requests']);$message=json_decode($last['options'][CURLOPT_POSTFIELDS],true)['message'];
    check($message['data']['kind']==='message'&&!isset($message['notification'])&&!isset($message['data']['body']),'Android service receives only metadata, not an automatic public OS preview');
    check($message['data']['narzan_message_id']==='10'&&$message['data']['narzan_chat_id']==='3','message ids remain immutable strings across FCM');
    check($last['options'][CURLOPT_FOLLOWLOCATION]===false&&$last['options'][CURLOPT_SSL_VERIFYPEER]===true,'message transport keeps TLS and no-redirect rules');
    $GLOBALS['provider_response']=[404,'{"error":{"details":[]}}'];
    try{$sender->sendMessage('android','synthetic-registration',10,3,'scope',true);throw new \RuntimeException('Expected provider config failure');}catch(\RuntimeException $e){check($e->getMessage()==='Message push provider rejected delivery.','project404 is retried, not mistaken for a revoked registration');}
    $GLOBALS['provider_response']=[404,'{"error":{"details":[{"errorCode":"UNREGISTERED"}]}}'];
    check(!$sender->sendMessage('android','synthetic-registration',10,3,'scope',true),'explicit FCM unregistered status disables only that device');
    $GLOBALS['provider_response']=[200,'{}'];
    check($sender->sendMessage('ios','sandbox:'.str_repeat('a',64),10,3,'scope',false),'standard sandbox APNs request accepted');
    $last=end($GLOBALS['provider_requests']);$payload=json_decode($last['options'][CURLOPT_POSTFIELDS],true);$headers=$last['options'][CURLOPT_HTTPHEADER];
    check(in_array('apns-push-type: alert',$headers,true)&&in_array('apns-topic: org.smnv.narzan.dev',$headers,true),'ordinary messages use the bundle alert topic, never VoIP');
    check($payload['aps']['alert']===['title'=>'Narzan','body'=>'New message']&&!isset($payload['aps']['sound']),'silent preference leaves out APNs sound and hides content');
    check(str_starts_with($last['url'],'https://api.sandbox.push.apple.com/'),'development token cannot target production APNs');
    $sender->sendMessage('ios','production:'.str_repeat('b',64),11,3,'scope',true);
    $last=end($GLOBALS['provider_requests']);$payload=json_decode($last['options'][CURLOPT_POSTFIELDS],true);
    check(str_starts_with($last['url'],'https://api.push.apple.com/')&&$payload['aps']['sound']==='default','production APNs and audible preference are explicit');
    $GLOBALS['provider_response']=[404,'{}'];
    try{$sender->sendMessage('ios','production:'.str_repeat('b',64),12,3,'scope',true);throw new \RuntimeException('Expected APNs request failure');}catch(\RuntimeException $e){check($e->getMessage()==='Message push provider rejected delivery.','Apple404 is a transport failure, not a revoked device');}
    $GLOBALS['provider_response']=[410,'{}'];
    check(!$sender->sendMessage('ios','production:'.str_repeat('b',64),12,3,'scope',true),'Apple410 expires that registration');
}finally{unlink($firebase);unlink($apple);}
echo 'Message provider checks: '.$GLOBALS['checks']."\n";
