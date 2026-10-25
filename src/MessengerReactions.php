<?php namespace ProcessWire;

/** Explicit member reactions. Exact desired state makes manual retries idempotent. */
trait MessengerReactions {
    public function reactionsReady(): bool {
        if(!(bool)$this->message_tools_enabled)return false;
        try{return $this->wire('database')->query('SELECT message_id,user_id,emoji,created_at FROM messenger_reactions LIMIT 0')!==false&&$this->wire('database')->query('SELECT user_id,window_started_at,event_count FROM messenger_reaction_locks LIMIT 0')!==false;}
        catch(\PDOException $e){return false;}
    }
    private function installReactionsSchema(): void {
        $db=$this->wire('database');$mysql=$db->getAttribute(\PDO::ATTR_DRIVER_NAME)==='mysql';
        $this->installMemberRateTable('messenger_reaction_locks');
        $db->exec('CREATE TABLE IF NOT EXISTS messenger_reactions (message_id BIGINT NOT NULL,user_id BIGINT NOT NULL,emoji TEXT NOT NULL,created_at BIGINT NOT NULL,PRIMARY KEY(message_id,user_id))'.($mysql?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci':''));
    }
    private function reactionsPresentation(array $messages,User $actor): array {
        if(!$messages||!$this->reactionsReady())return $messages;
        $ids=array_map(static fn(array $m):int=>(int)$m['id'],array_values(array_filter($messages,static fn(array $m):bool=>empty($m['deleted_at'])&&($m['moderation_state']??'visible')==='visible')));
        if(!$ids){foreach($messages as&$m)$m['reactions']=[];return $messages;}
        // Participants who left a group, or rejoined after this message, are excluded.
        $q=$this->wire('database')->prepare('SELECT r.message_id,r.user_id,r.emoji FROM messenger_reactions r JOIN messenger_messages m ON m.id=r.message_id JOIN messenger_conversations c ON c.id=m.conversation_id JOIN messenger_participants p ON p.conversation_id=c.id AND p.user_id=r.user_id WHERE r.message_id IN ('.implode(',',array_fill(0,count($ids),'?')).')'.(" AND (c.type='direct'".($this->savedMessagesReady()?" OR (c.type='saved' AND c.requester_id=p.user_id AND c.recipient_id=p.user_id)":"").($this->groupsReady()?" OR (c.type='group' AND EXISTS(SELECT 1 FROM messenger_group_members gm WHERE gm.conversation_id=c.id AND gm.user_id=r.user_id AND gm.active=1 AND m.id>gm.joined_after_id))":"").")").' ORDER BY r.message_id,r.user_id');
        $q->execute($ids);$by=[];$known=[];
        foreach($q->fetchAll(\PDO::FETCH_ASSOC)as$row){
            $id=(int)$row['message_id'];$uid=(int)$row['user_id'];$emoji=$this->decryptValue((string)$row['emoji'],'Messenger|reaction|'.$id.'|'.$uid);
            if(!in_array($emoji,self::REACTION_EMOJI,true))continue;
            if(!isset($known[$uid])){$u=$this->wire('users')->get($uid);$known[$uid]=$u instanceof User&&(int)$u->id>0?['id'=>$uid,'name'=>(string)$u->name,'title'=>(string)($u->get('display_name')?:$u->get('title')?:$u->name)]:null;}
            if($known[$uid]===null)continue;
            if(!isset($by[$id][$emoji]))$by[$id][$emoji]=['emoji'=>$emoji,'count'=>0,'mine'=>false,'users'=>[]];
            $by[$id][$emoji]['count']++;$by[$id][$emoji]['mine']=$by[$id][$emoji]['mine']||$uid===(int)$actor->id;$by[$id][$emoji]['users'][]=$known[$uid];
        }
        foreach($messages as&$m)$m['reactions']=empty($m['deleted_at'])?array_values($by[(int)$m['id']]??[]):[];
        return $messages;
    }
    public function reactToMessage(User $actor,int $messageId,?string $emoji): array {
        $this->assertActor($actor);
        if(!$this->reactionsReady())throw new WirePermissionException('Reactions unavailable.');
        if($messageId<1||$messageId>=PHP_INT_MAX||($emoji!==null&&!in_array($emoji,self::REACTION_EMOJI,true)))throw new \InvalidArgumentException('Choose a supported reaction.');
        return $this->atomic(function()use($actor,$messageId,$emoji):array{
            $db=$this->wire('database');
            // Serialize this member across conversations, so concurrent writes cannot evade the rate cap.
            $db->prepare(($db->getAttribute(\PDO::ATTR_DRIVER_NAME)==='mysql'?'INSERT IGNORE':'INSERT OR IGNORE').' INTO messenger_reaction_locks(user_id) VALUES(?)')->execute([(int)$actor->id]);
            $q=$db->prepare('SELECT user_id FROM messenger_reaction_locks WHERE user_id=?'.$this->forUpdate($db));$q->execute([(int)$actor->id]);$q->fetchColumn();
            // Lock the owning conversation before the message, matching the normal send/forward order.
            $q=$db->prepare('SELECT c.id FROM messenger_conversations c JOIN messenger_messages m ON m.conversation_id=c.id JOIN messenger_participants p ON p.conversation_id=c.id AND p.user_id=? WHERE m.id=?'.$this->forUpdate($db));$q->execute([(int)$actor->id,$messageId]);$conversationId=(int)$q->fetchColumn();
            if(!$conversationId)throw new WirePermissionException('Message unavailable.');
            $chat=$this->conversation($conversationId,$actor);
            if(!$chat||$chat['state']!=='active'||$chat['request_state']!=='accepted'||!$chat['can_send']||$chat['blocked'])throw new WirePermissionException('Message unavailable.');
            $row=$this->conversationMessages($conversationId,$actor,$messageId+1,$messageId-1,1)[0]??null;
            if(!$row||$row['id']!==$messageId||!empty($row['deleted_at'])||($row['moderation_state']??'visible')!=='visible')throw new WirePermissionException('Message unavailable.');
            $q=$db->prepare('SELECT emoji FROM messenger_reactions WHERE message_id=? AND user_id=?');$q->execute([$messageId,(int)$actor->id]);$stored=$q->fetchColumn();$previous=$stored===false?null:$this->decryptValue((string)$stored,'Messenger|reaction|'.$messageId.'|'.(int)$actor->id);
            if($previous===$emoji)return $row;
            $this->consumeMemberRate($actor,'messenger_reaction_locks');
            $db->prepare('DELETE FROM messenger_reactions WHERE message_id=? AND user_id=?')->execute([$messageId,(int)$actor->id]);
            if($emoji!==null)$db->prepare('INSERT INTO messenger_reactions(message_id,user_id,emoji,created_at) VALUES(?,?,?,?)')->execute([$messageId,(int)$actor->id,$this->encryptValue($emoji,'Messenger|reaction|'.$messageId.'|'.(int)$actor->id),time()]);
            $this->audit('message_reacted',$conversationId,$messageId,(int)$actor->id);
            return $this->conversationMessages($conversationId,$actor,$messageId+1,$messageId-1,1)[0];
        });
    }
}
