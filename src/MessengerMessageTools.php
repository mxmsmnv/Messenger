<?php namespace ProcessWire;

/** Optional additive message operations. Never install schema through a member request. */
trait MessengerMessageTools {
    public function forwardingReady(): bool {
        if(!(bool)$this->message_tools_enabled)return false;
        try{return $this->wire('database')->query('SELECT user_id,client_id,request_hash,conversation_id,result_ids FROM messenger_forward_ops LIMIT 0')!==false&&$this->wire('database')->query('SELECT message_id,kind FROM messenger_message_markers LIMIT 0')!==false;}
        catch(\PDOException $e){return false;}
    }
    public function installMessageTools(User $actor): void {
        if(!$this->canAdmin($actor))throw new WirePermissionException('Administrative access required.');
        $db=$this->wire('database');$mysql=$db->getAttribute(\PDO::ATTR_DRIVER_NAME)==='mysql';$tail=$mysql?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci':'';
        $client=$mysql?'VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin':'VARCHAR(64) COLLATE BINARY';
        $db->exec('CREATE TABLE IF NOT EXISTS messenger_forward_ops (user_id BIGINT NOT NULL,client_id '.$client.',request_hash CHAR(64) NOT NULL,conversation_id BIGINT NOT NULL,result_ids TEXT NOT NULL,created_at BIGINT NOT NULL,PRIMARY KEY(user_id,client_id))'.$tail);
        $db->exec('CREATE TABLE IF NOT EXISTS messenger_message_markers (message_id BIGINT PRIMARY KEY,kind VARCHAR(16) NOT NULL)'.$tail);
        $this->notificationMarkersPresent=null;
        $this->installReactionsSchema();
        $this->installPinsSchema();
    }
    private function installMemberRateTable(string $table): void {
        if(!in_array($table,['messenger_reaction_locks','messenger_pin_locks','messenger_poll_locks','messenger_group_link_locks','messenger_mention_locks','messenger_group_event_locks'],true))throw new \InvalidArgumentException('Unknown rate table.');
        $db=$this->wire('database');$tail=$db->getAttribute(\PDO::ATTR_DRIVER_NAME)==='mysql'?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci':'';
        $db->exec('CREATE TABLE IF NOT EXISTS '.$table.' (user_id BIGINT PRIMARY KEY,window_started_at BIGINT NOT NULL DEFAULT 0,event_count INTEGER NOT NULL DEFAULT 0)'.$tail);
        // Explicit operator migration, including existing1.5.6 lock rows. No member-side DDL.
        foreach(['window_started_at'=>'BIGINT','event_count'=>'INTEGER']as$column=>$type){try{$db->query('SELECT '.$column.' FROM '.$table.' LIMIT 0');}catch(\PDOException $e){$db->exec('ALTER TABLE '.$table.' ADD COLUMN '.$column.' '.$type.' NOT NULL DEFAULT 0');}}
    }
    private function consumeMemberRate(User $actor,string $table): void {
        if(!in_array($table,['messenger_reaction_locks','messenger_pin_locks','messenger_poll_locks','messenger_group_link_locks','messenger_mention_locks','messenger_group_event_locks'],true)||!$this->wire('database')->inTransaction())throw new WireException('Member rate lock required.');
        $db=$this->wire('database');$q=$db->prepare('SELECT window_started_at,event_count FROM '.$table.' WHERE user_id=?'.$this->forUpdate($db));$q->execute([(int)$actor->id]);$row=$q->fetch(\PDO::FETCH_ASSOC);if(!$row)throw new WireException('Member rate lock required.');
        $now=time();$start=(int)$row['window_started_at'];$count=max(0,(int)$row['event_count']);if($now<$start||$now-$start>=60){$start=$now;$count=0;}
        if($count>=60)throw new MessengerMobileException('rate_limited','Too many changes. Try again shortly.',429);
        $db->prepare('UPDATE '.$table.' SET window_started_at=?,event_count=? WHERE user_id=?')->execute([$start,$count+1,(int)$actor->id]);
    }
    private function messageToolsPresentation(array $messages,User $actor): array {
        $messages=$this->groupEventsPresentation($this->mentionsPresentation($this->pollsPresentation($this->silentPresentation($messages),$actor),$actor),$actor);
        if(!$messages||!$this->forwardingReady())return $messages;
        $ids=array_map(static fn(array $m):int=>(int)$m['id'],$messages);$q=$this->wire('database')->prepare('SELECT message_id FROM messenger_message_markers WHERE kind IN (?,?) AND message_id IN ('.implode(',',array_fill(0,count($ids),'?')).')');$q->execute(['forwarded','forwarded_silent',...$ids]);$marked=array_fill_keys(array_map('intval',$q->fetchAll(\PDO::FETCH_COLUMN)),true);
        foreach($messages as&$message){
            $message['forwarded']=empty($message['deleted_at'])&&isset($marked[(int)$message['id']]);
            $a=$message['attachment']??null;
            $message['content_revision']=$this->contentFingerprint('forward-view|'.json_encode([(int)$message['id'],(int)$message['conversation_id'],(int)$message['sender_user_id'],$message['body'],$message['deleted_at']??null,$message['edited_at']??null,$message['expires_at']??null,$message['moderation_state']??'visible',$a?array_intersect_key($a,array_flip(['id','filename','mime_type','kind','size','duration_ms'])):null],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        }return $messages;
    }
    private function forwardResult(User $actor,int $target,array $ids): array {
        $messages=[];
        foreach($ids as$id){
            if(!is_int($id)||$id<1||$id>=PHP_INT_MAX)throw new WireException('Forward result unavailable.');
            $row=$this->conversationMessages($target,$actor,$id+1,$id-1,1)[0]??null;
            // An actor's own hidden/deleted copy stays acknowledged without restoring its content.
            $messages[]=$row&&$row['id']===$id?$row:['id'=>$id,'conversation_id'=>$target,'sender_user_id'=>(int)$actor->id,'body'=>'','deleted_at'=>$this->now(),'attachment'=>null,'forwarded'=>false];
        }
        return ['conversation'=>$this->conversation($target,$actor),'messages'=>$messages];
    }
    public function forwardMessages(User $actor,int $source,int $target,array $ids,string $clientId,array $versions): array {
        $this->assertActor($actor);
        if(!$this->forwardingReady())throw new WirePermissionException('Message forwarding unavailable.');
        if($source<1||$target<1||count($ids)<1||count($ids)>10||count(array_unique($ids,SORT_REGULAR))!==count($ids)||trim($clientId)==='')throw new \InvalidArgumentException('Choose one to ten messages and a destination.');
        foreach($ids as$id){if(!is_int($id)||$id<1||$id>=PHP_INT_MAX)throw new \InvalidArgumentException('Positive message IDs required.');if(!is_string($versions[$id]??null)||!preg_match('/^[a-f0-9]{64}$/D',$versions[$id]))throw new \InvalidArgumentException('A selected content revision is required.');}
        sort($ids,SORT_NUMERIC);$clientId=$this->clientId($clientId);$fingerprint=$this->contentFingerprint(json_encode([$source,$target,array_map(static fn(int $id):array=>[$id,$versions[$id]],$ids)],JSON_THROW_ON_ERROR));
        $operation=function()use($actor,$source,$target,$ids,$clientId,$fingerprint,$versions):array{
            $db=$this->wire('database');$locks=array_values(array_unique([$source,$target]));sort($locks,SORT_NUMERIC);
            $q=$db->prepare('SELECT c.id FROM messenger_conversations c JOIN messenger_participants p ON p.conversation_id=c.id AND p.user_id=? WHERE c.id IN ('.implode(',',array_fill(0,count($locks),'?')).') ORDER BY c.id'.$this->forUpdate($db));$q->execute([(int)$actor->id,...$locks]);$q->fetchAll();
            $destination=$this->conversation($target,$actor);
            if(!$destination||$destination['state']!=='active'||$destination['request_state']!=='accepted'||!$destination['can_send']||$destination['blocked'])throw new WirePermissionException('Destination unavailable.');
            $q=$db->prepare('SELECT request_hash,result_ids FROM messenger_forward_ops WHERE user_id=? AND client_id=?'.$this->forUpdate($db));$q->execute([(int)$actor->id,$clientId]);$old=$q->fetch(\PDO::FETCH_ASSOC);
            $aad='Messenger|forward-op|'.(int)$actor->id.'|'.$clientId;
            if($old){if(!hash_equals($old['request_hash'],$fingerprint))throw new MessengerMobileException('idempotency_conflict','Forward conflicts with an earlier operation.',409);return $this->forwardResult($actor,$target,json_decode($this->decryptValue($old['result_ids'],$aad),true,16,JSON_THROW_ON_ERROR));}
            $origin=$this->conversation($source,$actor);
            if(!$origin||$origin['state']!=='active'||$origin['blocked']||(($origin['type']??'direct')==='group'&&!$this->groupCanSend($source,$actor)))throw new WirePermissionException('Source unavailable.');
            $selected=[];$bytes=0;$album=true;
            foreach($ids as$id){
                $row=$this->conversationMessages($source,$actor,$id+1,$id-1,1)[0]??null;
                if(!$row||$row['id']!==$id||!empty($row['deleted_at'])||($row['moderation_state']??'visible')!=='visible')throw new WirePermissionException('Source message unavailable.');
                if(!hash_equals($versions[$id],$row['content_revision']))throw new MessengerMobileException('message_changed','Selected message changed. Refresh the conversation.',409);
                if(isset($row['attachment']))$bytes+=(int)$row['attachment']['size'];
                if($bytes>32*1024*1024)throw new \InvalidArgumentException('Forwarded files exceed 32 MiB.');
                $a=$row['attachment']??null;$album=$album&&$a&&$a['kind']==='file'&&in_array($a['mime_type'],['image/jpeg','image/png','image/webp','image/gif','image/heic'],true);
                $selected[]=$row;
            }
            $db->prepare('INSERT INTO messenger_forward_ops(user_id,client_id,request_hash,conversation_id,result_ids,created_at) VALUES(?,?,?,?,?,?)')->execute([(int)$actor->id,$clientId,$fingerprint,$target,$this->encryptValue('[]',$aad),time()]);
            $group=rtrim(strtr(base64_encode(hex2bin($this->contentFingerprint('forward-album|'.(int)$actor->id.'|'.$clientId))),'+/','-_'),'=');$created=[];
            foreach($selected as$index=>$row){
                $copyId=$album&&count($selected)>1?'album_'.$group.'_'.$index:'fwd_'.substr($this->contentFingerprint('forward-copy|'.(int)$actor->id.'|'.$clientId.'|'.$index),0,60);
                if(isset($row['attachment'])){
                    $media=$this->attachment($actor,(int)$row['attachment']['id']);$media['body']=$row['body'];
                    $copy=$this->sendAttachment($target,$actor,$media,$copyId);
                }else $copy=$this->sendMessage($target,$actor,$row['body'],$copyId);
                $newId=(int)$copy['id'];$this->eraseMessageMentions($newId);$db->prepare('INSERT INTO messenger_message_markers(message_id,kind) VALUES(?,?)')->execute([$newId,'forwarded']);$created[]=$newId;
            }
            $db->prepare('UPDATE messenger_forward_ops SET result_ids=? WHERE user_id=? AND client_id=?')->execute([$this->encryptValue(json_encode($created,JSON_THROW_ON_ERROR),$aad),(int)$actor->id,$clientId]);
            $this->audit('messages_forwarded',$target,0,(int)$actor->id);
            return $this->forwardResult($actor,$target,$created);
        };
        try{return $this->atomic($operation);}catch(\PDOException $e){if(!in_array((string)$e->getCode(),['23000','23505'],true))throw $e;return $this->atomic($operation);}
    }
}
