<?php namespace ProcessWire;

/** Ordinary message delivery is independent from CallKit/VoIP and experimental calls. */
trait MessengerMessagePush {
    private function messagePushConfig():array { $c=($this->wire('config')->messengerPush??null);return is_array($c)?$c:[]; }
    public function messagePushReady():bool {
        $c=$this->messagePushConfig();return !empty($c['enabled'])&&!empty($c['push_ready'])&&$this->messagePushSchemaReady();
    }
    private function messagePushSchemaReady():bool {
        try {return $this->wire('database')->query('SELECT id FROM messenger_mobile_grants LIMIT 0')!==false && $this->wire('database')->query('SELECT grant_id FROM messenger_message_devices LIMIT 0')!==false && $this->wire('database')->query('SELECT message_id FROM messenger_message_pushes LIMIT 0')!==false;}
        catch(\PDOException $e){return false;}
    }
    /** Operator-only migration; never called by discovery, writes or device registration. */
    public function installMessagePush(User $actor):void {
        if(!$this->canAdmin($actor))throw new WirePermissionException('Administrative access required.');
        foreach([
            'messenger_message_devices'=>'grant_id CHAR(32) PRIMARY KEY,user_id BIGINT NOT NULL,platform VARCHAR(12) NOT NULL,token TEXT NOT NULL,token_hash CHAR(64) NULL UNIQUE,enabled INTEGER NOT NULL,sound INTEGER NOT NULL,updated_at BIGINT NOT NULL',
            'messenger_message_pushes'=>'message_id BIGINT NOT NULL,conversation_id BIGINT NOT NULL,grant_id CHAR(32) NOT NULL,status VARCHAR(12) NOT NULL,attempts INTEGER NOT NULL,available_at BIGINT NOT NULL,created_at BIGINT NOT NULL,PRIMARY KEY(message_id,grant_id)',
        ] as $name=>$columns)$this->wire('database')->exec("CREATE TABLE IF NOT EXISTS `$name` ($columns)");
    }
    public function messagePushDevice(User $actor,string $grant,string $method,array $body):array {
        $this->assertActor($actor);
        if(!$this->messagePushReady())throw new WirePermissionException('Message push unavailable.');
        if($method!=='POST'||array_diff(array_keys($body),['enabled','platform','token','sound'])||!is_bool($body['enabled']??null)||!is_bool($body['sound']??null)||!in_array($body['platform']??null,['ios','android'],true)||!is_string($body['token']??null))throw new \InvalidArgumentException('Invalid message registration.');
        return $this->atomic(function()use($actor,$grant,$body):array{
            $db=$this->wire('database');$lock=$db->getAttribute(\PDO::ATTR_DRIVER_NAME)==='sqlite'?'':' FOR UPDATE';
            $q=$db->prepare('SELECT user_id FROM messenger_mobile_grants WHERE id=? AND revoked_at IS NULL'.$lock);$q->execute([$grant]);
            if((int)$q->fetchColumn()!==(int)$actor->id)throw new WirePermissionException('Native connection unavailable.');
            $token=$body['token'];
            if($body['enabled'] && !(($body['platform']==='ios'&&preg_match('/^(sandbox|production):[a-f0-9]{64,200}$/D',$token))||($body['platform']==='android'&&preg_match('/^[A-Za-z0-9:_-]{16,2048}$/D',$token))))throw new \InvalidArgumentException('Invalid message registration.');
            // One current token may belong to this authenticated grant only. The token
            // is never returned, logged, placed in configuration or stored as plaintext.
            $q=$db->prepare('SELECT grant_id FROM messenger_message_devices WHERE grant_id=?');$q->execute([$grant]);$exists=$q->fetchColumn();
            $hash=$body['enabled']?hash('sha256',$token):null;
            if($hash!==null)$db->prepare('UPDATE messenger_message_devices SET enabled=0,token_hash=NULL WHERE token_hash=? AND grant_id<>?')->execute([$hash,$grant]);
            $values=[(int)$actor->id,$body['platform'],$body['enabled']?$this->encryptValue($token,'Messenger|message-device|'.$grant):'',$hash,(int)$body['enabled'],(int)$body['sound'],time()];
            if($exists)$db->prepare('UPDATE messenger_message_devices SET user_id=?,platform=?,token=?,token_hash=?,enabled=?,sound=?,updated_at=? WHERE grant_id=?')->execute([...$values,$grant]);
            else $db->prepare('INSERT INTO messenger_message_devices(user_id,platform,token,token_hash,enabled,sound,updated_at,grant_id) VALUES(?,?,?,?,?,?,?,?)')->execute([...$values,$grant]);
            if(!$body['enabled'])$this->execute("UPDATE messenger_message_pushes SET status='discarded' WHERE grant_id=? AND status IN ('pending','processing')",[$grant]);
            return ['enabled'=>$body['enabled']];
        });
    }
    private function queueMessagePush(int $conversationId,int $messageId,int $senderId):void {
        if(!$this->messagePushReady())return;
        $q=$this->wire('database')->prepare('SELECT d.grant_id FROM messenger_message_devices d JOIN messenger_mobile_grants g ON g.id=d.grant_id JOIN messenger_participants p ON p.user_id=d.user_id AND p.conversation_id=? WHERE d.user_id<>? AND d.enabled=1 AND d.updated_at>? AND g.revoked_at IS NULL AND (p.muted_until IS NULL OR p.muted_until<=?)');
        $q->execute([$conversationId,$senderId,time()-2592000,$this->now()]);
        foreach($q->fetchAll(\PDO::FETCH_COLUMN)as$grant)$this->execute("INSERT INTO messenger_message_pushes(message_id,conversation_id,grant_id,status,attempts,available_at,created_at) VALUES(?,?,?,'pending',0,?,?)",[$messageId,$conversationId,$grant,time(),time()]);
    }
    /** Metadata-only alerts: message bodies and sender names never go to APNs/FCM. */
    public function processMessagePushes(User $actor,callable $send,int $limit=20):array {
        if(!$this->canAdmin($actor))throw new WirePermissionException('Worker administrator required.');
        $result=['sent'=>0,'retry'=>0,'discarded'=>0];if(!$this->messagePushSchemaReady())return $result;
        $db=$this->wire('database');$now=time();$limit=max(1,min(20,$limit));
        $this->execute('DELETE FROM messenger_message_pushes WHERE created_at<?',[$now-604800]);
        $this->execute('DELETE FROM messenger_message_devices WHERE updated_at<?',[$now-2592000]);
        if(!$this->messagePushReady())return $result;
        $this->execute("UPDATE messenger_message_pushes SET status=CASE WHEN attempts>=5 THEN 'failed' ELSE 'pending' END WHERE status='processing' AND available_at<?",[$now-60]);
        $q=$db->prepare("SELECT p.*,d.user_id,d.platform,d.token,d.sound FROM messenger_message_pushes p JOIN messenger_message_devices d ON d.grant_id=p.grant_id WHERE p.status='pending' AND p.attempts<5 AND p.available_at<=? ORDER BY p.message_id LIMIT ".$limit);$q->execute([$now]);
        foreach($q->fetchAll(\PDO::FETCH_ASSOC)as$row){
            $claim=$db->prepare("UPDATE messenger_message_pushes SET status='processing',attempts=attempts+1,available_at=? WHERE message_id=? AND grant_id=? AND status='pending'");$claim->execute([$now,$row['message_id'],$row['grant_id']]);if($claim->rowCount()!==1)continue;
            try {
                $valid=$db->prepare('SELECT d.enabled,d.sound,d.token,d.updated_at,g.revoked_at,p.last_read_message_id,p.muted_until FROM messenger_message_devices d JOIN messenger_mobile_grants g ON g.id=d.grant_id JOIN messenger_participants p ON p.user_id=d.user_id AND p.conversation_id=? WHERE d.grant_id=? AND g.user_id=d.user_id');$valid->execute([(int)$row['conversation_id'],$row['grant_id']]);$state=$valid->fetch(\PDO::FETCH_ASSOC);
                if(!$state||!$state['enabled']||$state['updated_at']<$now-2592000||$state['revoked_at']!==null||$state['last_read_message_id']>=$row['message_id']||($state['muted_until']&&strtotime($state['muted_until'])>$now))throw new WirePermissionException('Notification no longer eligible.');
                $recipient=$this->wire('users')->get((int)$row['user_id']);$previous=$this->wire('user');
                try {
                    $this->wire('users')->setCurrentUser($recipient);
                    $chat=$this->conversation((int)$row['conversation_id'],$recipient);
                    if(!$chat||!empty($chat['blocked']))throw new WirePermissionException('Conversation unavailable.');
                    $messages=$this->conversationMessages((int)$row['conversation_id'],$recipient,(int)$row['message_id']+1,(int)$row['message_id']-1,1);
                    $message=$messages[0]??null;
                    if(!$message||(int)$message['id']!==(int)$row['message_id']||!empty($message['deleted_at'])||(int)$message['sender_user_id']===(int)$recipient->id)throw new WirePermissionException('Message unavailable.');
                    if($this->isBlockedEitherWay((int)$message['sender_user_id'],(int)$recipient->id)||!$this->canReceive($this->wire('users')->get((int)$message['sender_user_id'])))throw new WirePermissionException('Message unavailable.');
                    $discovery=(new MessengerMobileAuth($this))->discovery();
                    $account=$discovery['issuer'].'#'.$discovery['site_id'].'#'.(int)$recipient->id;
                }finally{$this->wire('users')->setCurrentUser($previous);}
                $token=$this->decryptValue($state['token'],'Messenger|message-device|'.$row['grant_id']);
                $accepted=$send($row['platform'],$token,(int)$row['message_id'],(int)$row['conversation_id'],$account,(bool)$state['sound']);
                if(!is_bool($accepted))throw new \RuntimeException('Invalid provider acknowledgement.');
                if(!$accepted)$this->execute('UPDATE messenger_message_devices SET enabled=0 WHERE grant_id=?',[$row['grant_id']]);
                $status=$accepted?'sent':'discarded';$result[$status]++;
            }catch(WirePermissionException $e){$status='discarded';$result['discarded']++;}
            catch(\Throwable $e){$status=((int)$row['attempts']>=4)?'failed':'pending';$result['retry']++;}
            $this->execute('UPDATE messenger_message_pushes SET status=?,available_at=? WHERE message_id=? AND grant_id=?',[$status,$now+min(300,5*(2**(int)$row['attempts'])),$row['message_id'],$row['grant_id']]);
        }
        return $result;
    }
}
