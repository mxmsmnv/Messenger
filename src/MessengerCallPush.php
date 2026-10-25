<?php namespace ProcessWire;

/** Provider credentials are loaded from private files, never module settings or responses. */
final class MessengerCallPush {
    private array $config;
    private ?string $firebaseToken=null;
    public function __construct(array $config){$this->config=$config;}
    private function b64(string $v):string{return rtrim(strtr(base64_encode($v),'+/','-_'),'=');}
    private function keyFile(string $path):string {
        if($path===''||!is_file($path)||is_link($path)||filesize($path)>32768||(fileperms($path)&0007)!==0)throw new \RuntimeException('Push credential unavailable.');
        $v=file_get_contents($path);if($v===false)throw new \RuntimeException('Push credential unavailable.');return $v;
    }
    private function post(string $url,array $headers,string $body,bool $http2=false):array {
        $received='';$ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,CURLOPT_HTTPHEADER=>$headers,CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk)use(&$received):int{if(strlen($received)+strlen($chunk)>65536)return 0;$received.=$chunk;return strlen($chunk);},CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>10,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2]);
        if($http2)curl_setopt($ch,CURLOPT_HTTP_VERSION,CURL_HTTP_VERSION_2_0);
        $response=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);
        if($response===false)throw new \RuntimeException('Push provider unavailable.');return [$status,$received];
    }
    private function firebaseAccess():string {
        if($this->firebaseToken!==null)return $this->firebaseToken;
        $credentials=json_decode($this->keyFile((string)($this->config['firebase_credentials']??'')),true,16,JSON_THROW_ON_ERROR);
        if(!is_array($credentials)||!isset($credentials['client_email'],$credentials['private_key']))throw new \RuntimeException('Firebase credentials unavailable.');
        $header=$this->b64('{"alg":"RS256","typ":"JWT"}');$claims=$this->b64(json_encode(['iss'=>$credentials['client_email'],'scope'=>'https://www.googleapis.com/auth/firebase.messaging','aud'=>'https://oauth2.googleapis.com/token','iat'=>time(),'exp'=>time()+300],JSON_THROW_ON_ERROR));
        if(!openssl_sign($header.'.'.$claims,$signature,openssl_pkey_get_private($credentials['private_key']),OPENSSL_ALGO_SHA256))throw new \RuntimeException('Firebase signing failed.');
        [$status,$body]=$this->post('https://oauth2.googleapis.com/token',['Content-Type: application/x-www-form-urlencoded'],http_build_query(['grant_type'=>'urn:ietf:params:oauth:grant-type:jwt-bearer','assertion'=>$header.'.'.$claims.'.'.$this->b64($signature)]));
        $result=json_decode($body,true);if($status!==200||!is_string($result['access_token']??null))throw new \RuntimeException('Firebase authentication failed.');
        return $this->firebaseToken=$result['access_token'];
    }
    /** ES256 JWT uses raw r||s; OpenSSL returns ASN.1 DER. Reject unexpected encodings. */
    public static function rawEs256(string $der):string {
        if(strlen($der)<8||ord($der[0])!==0x30)throw new \RuntimeException('Invalid signing result.');
        $offset=2;if(ord($der[1])>=128)$offset+=ord($der[1])&127;
        $values=[];for($i=0;$i<2;$i++){if(!isset($der[$offset+1])||ord($der[$offset])!==2)throw new \RuntimeException('Invalid signing result.');$length=ord($der[$offset+1]);$offset+=2;$value=ltrim(substr($der,$offset,$length),"\0");$offset+=$length;if(strlen($value)>32)throw new \RuntimeException('Invalid signing result.');$values[]=str_pad($value,32,"\0",STR_PAD_LEFT);}
        return implode('',$values);
    }
    public function send(string $platform,string $token,string $callId,string $caller="Narzan"):bool {
        $name=mb_substr(trim((string)preg_replace('/[\\x00-\\x1F\\x7F]/u','',$caller)),0,100);
        $payload=['kind'=>'call','call_id'=>$callId,'caller'=>$name!==''?$name:'Narzan'];
        if($platform==='android') {
            $project=(string)($this->config['firebase_project']??'');if(!preg_match('/^[a-z][a-z0-9-]{4,62}$/D',$project))throw new \RuntimeException('Firebase project unavailable.');
            [$status]=$this->post('https://fcm.googleapis.com/v1/projects/'.$project.'/messages:send',['Authorization: Bearer '.$this->firebaseAccess(),'Content-Type: application/json'],json_encode(['message'=>['token'=>$token,'data'=>$payload,'android'=>['priority'=>'HIGH','ttl'=>'45s','collapse_key'=>$callId]]],JSON_THROW_ON_ERROR));
        } elseif($platform==='ios') {
            $environment=null;if(str_contains($token,':')){[$environment,$token]=explode(':',$token,2);if(!in_array($environment,['sandbox','production'],true))throw new \RuntimeException('Invalid Apple push environment.');}
            if(!preg_match('/^[a-f0-9]{64,200}$/Di',$token))throw new \RuntimeException('Invalid Apple push registration.');
            $key=(string)($this->config['apns_key_id']??'');$team=(string)($this->config['apns_team_id']??'');if(!preg_match('/^[A-Z0-9]{10}$/D',$key)||!preg_match('/^[A-Z0-9]{10}$/D',$team))throw new \RuntimeException('Apple push configuration unavailable.');
            $jwt=$this->b64(json_encode(['alg'=>'ES256','kid'=>$key],JSON_THROW_ON_ERROR)).'.'.$this->b64(json_encode(['iss'=>$team,'iat'=>time()],JSON_THROW_ON_ERROR));
            if(!openssl_sign($jwt,$signature,openssl_pkey_get_private($this->keyFile((string)($this->config['apns_private_key']??''))),OPENSSL_ALGO_SHA256))throw new \RuntimeException('Apple signing failed.');
            $jwt.='.'.$this->b64(self::rawEs256($signature));
            $host=($environment==='sandbox'||($environment===null&&!empty($this->config['apns_sandbox'])))?'api.sandbox.push.apple.com':'api.push.apple.com';
            $topic=(string)($this->config['apns_bundle_id']??'org.smnv.narzan.dev');if(!preg_match('/^[a-zA-Z0-9.-]+$/D',$topic))throw new \RuntimeException('Invalid Apple push topic.');
            [$status]=$this->post('https://'.$host.'/3/device/'.$token,['authorization: bearer '.$jwt,'apns-topic: '.$topic.'.voip','apns-push-type: voip','apns-priority: 10','apns-expiration: '.(time()+40),'apns-collapse-id: '.$callId,'content-type: application/json'],json_encode(['aps'=>['content-available'=>1]]+$payload,JSON_THROW_ON_ERROR),true);
        } else throw new \RuntimeException('Unsupported push platform.');
        if($status>=200&&$status<300)return true;
        if(in_array($status,[404,410],true))return false;
        throw new \RuntimeException('Push provider rejected delivery.');
    }
    /** Standard notification tokens/topics only. Never send an ordinary message via VoIP. */
    public function sendMessage(string $platform,string $token,int $messageId,int $chatId,string $account,bool $sound):bool {
        return $this->sendContentNotification($platform,$token,$messageId,$chatId,$account,$sound,null);
    }
    public function sendEventReminder(string $platform,string $token,int $messageId,int $chatId,string $account,bool $sound,string $revision):bool {
        if(!preg_match('/^[1-9][0-9]{0,17}:[1-9][0-9]{0,17}$/D',$revision))throw new \InvalidArgumentException('Invalid event reminder revision.');
        return $this->sendContentNotification($platform,$token,$messageId,$chatId,$account,$sound,$revision);
    }
    private function sendContentNotification(string $platform,string $token,int $messageId,int $chatId,string $account,bool $sound,?string $reminder):bool {
        if($messageId<1||$chatId<1||$account===''||strlen($account)>2048)throw new \InvalidArgumentException('Invalid message push.');
        $payload=['kind'=>'message','narzan_message_id'=>(string)$messageId,'narzan_chat_id'=>(string)$chatId,'narzan_account'=>$account];
        $collapse=substr(hash('sha256',$account.'|'.$chatId.($reminder===null?'':'|event|'.$messageId.'|'.$reminder)),0,64);
        if($reminder!==null){$payload['kind']='event_reminder';$payload['narzan_reminder_revision']=$reminder;}
        $ttl=$reminder===null?86400:300;
        if($platform==='android') {
            $project=(string)($this->config['firebase_project']??'');if(!preg_match('/^[a-z][a-z0-9-]{4,62}$/D',$project))throw new \RuntimeException('Firebase project unavailable.');
            [$status,$response]=$this->post('https://fcm.googleapis.com/v1/projects/'.$project.'/messages:send',['Authorization: Bearer '.$this->firebaseAccess(),'Content-Type: application/json'],json_encode(['message'=>['token'=>$token,'data'=>$payload,'android'=>['priority'=>'HIGH','ttl'=>$ttl.'s']]],JSON_THROW_ON_ERROR));
        }elseif($platform==='ios') {
            if(!preg_match('/^(sandbox|production):([a-f0-9]{64,200})$/D',$token,$registration))throw new \RuntimeException('Invalid Apple message registration.');
            $key=(string)($this->config['apns_key_id']??'');$team=(string)($this->config['apns_team_id']??'');
            if(!preg_match('/^[A-Z0-9]{10}$/D',$key)||!preg_match('/^[A-Z0-9]{10}$/D',$team))throw new \RuntimeException('Apple push configuration unavailable.');
            $jwt=$this->b64(json_encode(['alg'=>'ES256','kid'=>$key],JSON_THROW_ON_ERROR)).'.'.$this->b64(json_encode(['iss'=>$team,'iat'=>time()],JSON_THROW_ON_ERROR));
            if(!openssl_sign($jwt,$signature,openssl_pkey_get_private($this->keyFile((string)($this->config['apns_private_key']??''))),OPENSSL_ALGO_SHA256))throw new \RuntimeException('Apple signing failed.');
            $jwt.='.'.$this->b64(self::rawEs256($signature));
            $topic=(string)($this->config['apns_bundle_id']??'org.smnv.narzan.dev');if(!preg_match('/^[a-zA-Z0-9.-]+$/D',$topic))throw new \RuntimeException('Invalid Apple push topic.');
            $host=$registration[1]==='sandbox'?'api.sandbox.push.apple.com':'api.push.apple.com';
            $aps=['alert'=>['title'=>'Narzan','body'=>$reminder===null?'New message':'Event reminder'],'thread-id'=>$collapse];if($sound)$aps['sound']='default';
            [$status,$response]=$this->post('https://'.$host.'/3/device/'.$registration[2],['authorization: bearer '.$jwt,'apns-topic: '.$topic,'apns-push-type: alert','apns-priority: 10','apns-expiration: '.(time()+$ttl),'apns-collapse-id: '.$collapse,'content-type: application/json'],json_encode(['aps'=>$aps]+$payload,JSON_THROW_ON_ERROR),true);
        }else throw new \RuntimeException('Unsupported message push platform.');
        if($status>=200&&$status<300)return true;
        if($platform==='ios'&&$status===410)return false;
        if($platform==='android'&&$status===404) {
            $error=json_decode($response,true);
            foreach(($error['error']['details']??[])as$detail)if(is_array($detail)&&($detail['errorCode']??null)==='UNREGISTERED')return false;
        }
        throw new \RuntimeException('Message push provider rejected delivery.');
    }

}
