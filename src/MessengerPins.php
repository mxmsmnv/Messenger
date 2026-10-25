<?php namespace ProcessWire;

/** Personal organisation and explicitly shared message pins; no content duplication. */
trait MessengerPins {
    private array $chatPinCache=[];
    public function pinsReady(): bool {
        if(!(bool)$this->message_tools_enabled)return false;
        try{return $this->wire('database')->query('SELECT user_id,conversation_id,created_at FROM messenger_chat_pins LIMIT 0')!==false&&$this->wire('database')->query('SELECT id,conversation_id,scope_user_id,message_id,created_by,created_at FROM messenger_message_pins LIMIT 0')!==false&&$this->wire('database')->query('SELECT user_id,window_started_at,event_count FROM messenger_pin_locks LIMIT 0')!==false;}
        catch(\PDOException $e){return false;}
    }
    private function installPinsSchema(): void {
        $db=$this->wire('database');$tail=$db->getAttribute(\PDO::ATTR_DRIVER_NAME)==='mysql'?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci':'';
        $db->exec('CREATE TABLE IF NOT EXISTS messenger_chat_pins (user_id BIGINT NOT NULL,conversation_id BIGINT NOT NULL,created_at BIGINT NOT NULL,PRIMARY KEY(user_id,conversation_id))'.$tail);
        $key=$db->getAttribute(\PDO::ATTR_DRIVER_NAME)==='mysql'?'BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT':'INTEGER PRIMARY KEY AUTOINCREMENT';
        $db->exec('CREATE TABLE IF NOT EXISTS messenger_message_pins (id '.$key.',conversation_id BIGINT NOT NULL,scope_user_id BIGINT NOT NULL,message_id BIGINT NOT NULL,created_by BIGINT NOT NULL,created_at BIGINT NOT NULL,UNIQUE(conversation_id,scope_user_id,message_id))'.$tail);
        $this->installMemberRateTable('messenger_pin_locks');$this->chatPinCache=[];
    }
    private function personalChatPins(User $actor): array {
        $uid=(int)$actor->id;
        if(isset($this->chatPinCache[$uid]))return $this->chatPinCache[$uid];
        if(!$this->pinsReady())return $this->chatPinCache[$uid]=[];
        $q=$this->wire('database')->prepare('SELECT conversation_id,created_at FROM messenger_chat_pins WHERE user_id=? ORDER BY created_at DESC,conversation_id DESC LIMIT 5');$q->execute([$uid]);$map=[];
        foreach($q->fetchAll(\PDO::FETCH_ASSOC)as$r)$map[(int)$r['conversation_id']]=(int)$r['created_at'];
        return $this->chatPinCache[$uid]=$map;
    }
    private function decorateChatPin(array $chat,User $actor): array {
        if(!$chat||!(bool)$this->message_tools_enabled)return $chat;
        $pins=$this->personalChatPins($actor);$chat['pinned_at']=$pins[(int)$chat['id']]??null;return $chat;
    }
    private function pinActorLock(User $actor): void {
        $db=$this->wire('database');$db->prepare(($db->getAttribute(\PDO::ATTR_DRIVER_NAME)==='mysql'?'INSERT IGNORE':'INSERT OR IGNORE').' INTO messenger_pin_locks(user_id) VALUES(?)')->execute([(int)$actor->id]);
        $q=$db->prepare('SELECT user_id FROM messenger_pin_locks WHERE user_id=?'.$this->forUpdate($db));$q->execute([(int)$actor->id]);$q->fetchColumn();
    }
    private function assertPinRate(User $actor): void {$this->consumeMemberRate($actor,'messenger_pin_locks');}
    private function pinConversationLock(int $id,User $actor): array {
        $db=$this->wire('database');$q=$db->prepare('SELECT c.id FROM messenger_conversations c JOIN messenger_participants p ON p.conversation_id=c.id AND p.user_id=? WHERE c.id=?'.$this->forUpdate($db));$q->execute([(int)$actor->id,$id]);$q->fetchColumn();
        $chat=$this->conversation($id,$actor);
        if(!$chat||$chat['state']!=='active'||$chat['request_state']!=='accepted')throw new WirePermissionException('Conversation unavailable.');return $chat;
    }
    private function forgetChatPin(User $actor,int $id): void {
        $this->wire('database')->prepare('DELETE FROM messenger_chat_pins WHERE user_id=? AND conversation_id=?')->execute([(int)$actor->id,$id]);unset($this->chatPinCache[(int)$actor->id]);
    }
    public function pinChat(User $actor,int $id,bool $pinned): array {
        $this->assertActor($actor);if(!$this->pinsReady())throw new WirePermissionException('Pins unavailable.');if($id<1)throw new \InvalidArgumentException('Conversation ID required.');
        return $this->atomic(function()use($actor,$id,$pinned):array{
            $this->pinActorLock($actor);$db=$this->wire('database');unset($this->chatPinCache[(int)$actor->id]);
            if(!$pinned){if(isset($this->personalChatPins($actor)[$id])){$this->assertPinRate($actor);$this->forgetChatPin($actor,$id);$this->audit('chat_unpinned',$id,0,(int)$actor->id);}return ['id'=>$id,'pinned'=>false];}
            $this->pinConversationLock($id,$actor);$pins=$this->personalChatPins($actor);
            if(!isset($pins[$id])){
                $this->assertPinRate($actor);
                // Remove only this member's inaccessible stale pointers, never another person's pins.
                foreach($pins as$old=>$at){$chat=$this->conversation($old,$actor);if(!$chat||$chat['state']!=='active'||$chat['request_state']!=='accepted')$this->forgetChatPin($actor,$old);}
                $q=$db->prepare('SELECT COUNT(*) FROM messenger_chat_pins WHERE user_id=?');$q->execute([(int)$actor->id]);if((int)$q->fetchColumn()>=5)throw new MessengerMobileException('pin_limit','You can pin up to five chats.',409);
                $db->prepare('INSERT INTO messenger_chat_pins(user_id,conversation_id,created_at) VALUES(?,?,?)')->execute([(int)$actor->id,$id,time()]);$this->audit('chat_pinned',$id,0,(int)$actor->id);unset($this->chatPinCache[(int)$actor->id]);
            }
            return ['id'=>$id,'pinned'=>true];
        });
    }
    public function pinnedChats(User $actor,string $scope='inbox',bool $includeGroups=false): array {
        $this->assertActor($actor);if(!$this->pinsReady()||!in_array($scope,['inbox','archived'],true))return [];
        $items=[];foreach($this->personalChatPins($actor)as$id=>$at){
            $c=$this->conversation($id,$actor);if(!$c||$c['state']!=='active'||$c['request_state']!=='accepted'||(!$includeGroups&&($c['type']??'direct')==='group'))continue;
            if(($scope==='archived')!==!empty($c['archived_at']))continue;
            if(($c['type']??'direct')!=='group'&&(int)$c['last_message_id']<=(int)($c['hidden_through_message_id']??0))continue;
            $items[]=$c;
        }return $items;
    }
    private function visiblePinMessage(User $actor,int $conversation,int $id): ?array {
        if($id<1||$id>=PHP_INT_MAX)return null;
        $m=$this->conversationMessages($conversation,$actor,$id+1,$id-1,1)[0]??null;
        return $m&&$m['id']===$id&&empty($m['deleted_at'])&&($m['moderation_state']??'visible')==='visible'?$m:null;
    }
    public function pinnedMessages(User $actor,int $id): array {
        $this->assertActor($actor);if(!$this->pinsReady())throw new WirePermissionException('Pins unavailable.');
        $chat=$this->conversation($id,$actor);if(!$chat||$chat['state']!=='active'||$chat['request_state']!=='accepted')throw new WirePermissionException('Conversation unavailable.');
        $q=$this->wire('database')->prepare('SELECT scope_user_id,message_id,created_at FROM messenger_message_pins WHERE conversation_id=? AND scope_user_id IN (0,?) ORDER BY id DESC LIMIT 10');$q->execute([$id,(int)$actor->id]);$items=[];
        foreach($q->fetchAll(\PDO::FETCH_ASSOC)as$r){$message=$this->visiblePinMessage($actor,$id,(int)$r['message_id']);if($message)$items[]=['scope'=>(int)$r['scope_user_id']===0?'everyone':'personal','created_at'=>(int)$r['created_at'],'message'=>$message];}
        return ['items'=>$items,'can_pin_shared'=>!$chat['blocked']&&$chat['can_send']&&(($chat['type']??'direct')!=='group'||in_array($chat['role'],['owner','admin'],true))];
    }
    public function pinMessage(User $actor,int $conversation,int $messageId,bool $pinned,string $scope='personal'): array {
        $this->assertActor($actor);if(!$this->pinsReady())throw new WirePermissionException('Pins unavailable.');
        if($conversation<1||$messageId<1||$messageId>=PHP_INT_MAX||!in_array($scope,['personal','everyone'],true))throw new \InvalidArgumentException('Message and pin scope required.');
        return $this->atomic(function()use($actor,$conversation,$messageId,$pinned,$scope):array{
            $this->pinActorLock($actor);$db=$this->wire('database');$who=$scope==='everyone'?0:(int)$actor->id;$replaced=false;
            if($pinned||$scope==='everyone'){
                $chat=$this->pinConversationLock($conversation,$actor);
                if($scope==='everyone'&&($chat['blocked']||!$chat['can_send']||(($chat['type']??'direct')==='group'&&!in_array($chat['role'],['owner','admin'],true))))throw new WirePermissionException('Shared pin requires conversation rights.');
            }
            $q=$db->prepare('SELECT message_id FROM messenger_message_pins WHERE conversation_id=? AND scope_user_id=? AND message_id=?');$q->execute([$conversation,$who,$messageId]);$exists=$q->fetchColumn()!==false;
            if($pinned&&!$this->visiblePinMessage($actor,$conversation,$messageId))throw new WirePermissionException('Message unavailable.');
            if($pinned!==$exists)$this->assertPinRate($actor);
            if($pinned&&!$exists){
                if($who>0){$q=$db->prepare('SELECT message_id FROM messenger_message_pins WHERE conversation_id=? AND scope_user_id=?');$q->execute([$conversation,$who]);foreach($q->fetchAll(\PDO::FETCH_COLUMN)as$old)if(!$this->visiblePinMessage($actor,$conversation,(int)$old))$db->prepare('DELETE FROM messenger_message_pins WHERE conversation_id=? AND scope_user_id=? AND message_id=?')->execute([$conversation,$who,(int)$old]);}
                if($who===0){$q=$db->prepare('SELECT p.id FROM messenger_message_pins p LEFT JOIN messenger_messages m ON m.id=p.message_id WHERE p.conversation_id=? AND p.scope_user_id=0 AND (m.id IS NULL OR m.deleted_at IS NOT NULL OR (m.expires_at IS NOT NULL AND m.expires_at<=?))');$q->execute([$conversation,time()]);foreach($q->fetchAll(\PDO::FETCH_COLUMN)as$gone)$db->prepare('DELETE FROM messenger_message_pins WHERE id=?')->execute([(int)$gone]);}
                $q=$db->prepare('SELECT COUNT(*) FROM messenger_message_pins WHERE conversation_id=? AND scope_user_id=?');$q->execute([$conversation,$who]);if((int)$q->fetchColumn()>=5){if($who>0)throw new MessengerMobileException('pin_limit','You can pin up to five personal messages.',409);$oldest=$db->prepare('SELECT id FROM messenger_message_pins WHERE conversation_id=? AND scope_user_id=0 ORDER BY id LIMIT 1');$oldest->execute([$conversation]);$db->prepare('DELETE FROM messenger_message_pins WHERE id=?')->execute([(int)$oldest->fetchColumn()]);$replaced=true;}
                $db->prepare('INSERT INTO messenger_message_pins(conversation_id,scope_user_id,message_id,created_by,created_at) VALUES(?,?,?,?,?)')->execute([$conversation,$who,$messageId,(int)$actor->id,time()]);
            }elseif(!$pinned&&$exists)$db->prepare('DELETE FROM messenger_message_pins WHERE conversation_id=? AND scope_user_id=? AND message_id=?')->execute([$conversation,$who,$messageId]);
            if($pinned!==$exists)$this->audit($scope==='everyone'?'message_pinned_shared':'message_pinned_personal',$conversation,$messageId,(int)$actor->id,['pinned'=>$pinned,'replaced_oldest'=>$replaced]);
            return ['id'=>$conversation,'message_id'=>$messageId,'scope'=>$scope,'pinned'=>$pinned];
        });
    }
}
