<?php namespace ProcessWire;

/** Group-local username mentions; notification filters never override mute/silent. */
trait MessengerMentions {
    private ?bool $mentionSchemaPresent=null;
    private function mentionSchemaReady():bool {
        if($this->mentionSchemaPresent!==null)return $this->mentionSchemaPresent;
         $configured=false;try{foreach(['messenger_mention_preferences'=>'conversation_id,user_id,mentions_only','messenger_mentions'=>'message_id,conversation_id,user_id,username','messenger_mention_locks'=>'user_id,window_started_at,event_count']as$t=>$columns){$this->wire('database')->query('SELECT '.$columns.' FROM '.$t.' LIMIT 0');if($t==='messenger_mention_preferences')$configured=true;}return $this->mentionSchemaPresent=true;}
        catch(\PDOException $e){if($configured)throw new \RuntimeException('Group mention preferences unavailable.',0,$e);if((string)$e->getCode()==='42S02'||(int)($e->errorInfo[1]??0)===1146||str_contains($e->getMessage(),'no such table: messenger_'))return $this->mentionSchemaPresent=false;throw $e;}
    }
    public function mentionsReady():bool {return $this->groupsReady()&&$this->mentionSchemaReady();}
    public function installGroupMentions(User $actor):void {
        if(!$this->canAdmin($actor))throw new WirePermissionException('Administrative access required.');$db=$this->wire('database');$mysql=$db->getAttribute(\PDO::ATTR_DRIVER_NAME)==='mysql';$tail=$mysql?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci':'';
        $db->exec('CREATE TABLE IF NOT EXISTS messenger_mentions(message_id BIGINT NOT NULL,conversation_id BIGINT NOT NULL,user_id BIGINT NOT NULL,username TEXT NOT NULL,PRIMARY KEY(message_id,user_id)'.($mysql?',INDEX member_mentions(conversation_id,user_id,message_id)':'').')'.$tail);
        if(!$mysql)$db->exec('CREATE INDEX IF NOT EXISTS messenger_mentions_member ON messenger_mentions(conversation_id,user_id,message_id)');
        $db->exec('CREATE TABLE IF NOT EXISTS messenger_mention_preferences(conversation_id BIGINT NOT NULL,user_id BIGINT NOT NULL,mentions_only INTEGER NOT NULL DEFAULT 0,PRIMARY KEY(conversation_id,user_id))'.$tail);$this->installMemberRateTable('messenger_mention_locks');$this->mentionSchemaPresent=true;
    }
    private function captureGroupMentions(int $conversationId,int $messageId,string $body):void {
        if(!$this->mentionSchemaReady())return;$db=$this->wire('database');$db->prepare('DELETE FROM messenger_mentions WHERE message_id=?')->execute([$messageId]);
        if(!str_contains($body,'@')||!$this->groupsReady()||!$this->scalar("SELECT COUNT(*) FROM messenger_conversations WHERE id=? AND type='group' AND state='active'",[$conversationId]))return;
        // A complete token only: never turn an address, substring
        // into an implicit member identifier. Names are current authoritative names.
        $body=implode("\n",array_filter(explode("\n",$body),static fn($line)=>!preg_match('/^\s*>/u',$line)));
        preg_match_all('/(?<![\p{L}\p{N}_.@-])@([\p{L}\p{N}_.-]{1,128})(?![\p{L}\p{N}_.-])/u',$body,$matches);$tokens=array_fill_keys(array_map(static fn($n)=>mb_strtolower($n),$matches[1]),true);if(!$tokens)return;
        $members=[];foreach($this->groupReaders($conversationId,true)as$row){$u=$this->wire('users')->get((int)$row['user_id']);if($u instanceof User&&$this->canReceive($u))$members[mb_strtolower((string)$u->name)]=$u;}
        $found=[];foreach(array_keys($tokens)as$token){$u=$members[$token]??$members[rtrim($token,'.')]??null;if($u)$found[(int)$u->id]=(string)$u->name;}
        if(count($found)>20)throw new \InvalidArgumentException('Mention at most 20 group members in one message.');
        $q=$db->prepare('INSERT INTO messenger_mentions(message_id,conversation_id,user_id,username) VALUES(?,?,?,?)');foreach($found as$id=>$name)$q->execute([$messageId,$conversationId,$id,$this->encryptValue($name,'Messenger|mention|'.$messageId.'|'.$id)]);
    }
    private function eraseMessageMentions(int $messageId):void {if($this->mentionSchemaReady())$this->wire('database')->prepare('DELETE FROM messenger_mentions WHERE message_id=?')->execute([$messageId]);}
    private function groupMentionsOnly(int $conversationId,int $actor):bool {
        if(!$this->mentionSchemaReady())return false;return (bool)$this->scalar('SELECT mentions_only FROM messenger_mention_preferences WHERE conversation_id=? AND user_id=?'.($this->wire('database')->inTransaction()?$this->forUpdate($this->wire('database')):''),[$conversationId,$actor]);
    }
    private function mentionNotificationAllowed(int $messageId,int $recipient):bool {
        if(!$this->mentionSchemaReady())return true;
        $q=$this->wire('database')->prepare('SELECT m.conversation_id,c.type FROM messenger_messages m JOIN messenger_conversations c ON c.id=m.conversation_id WHERE m.id=?');$q->execute([$messageId]);$row=$q->fetch(\PDO::FETCH_ASSOC);
        if(!$row)return false;if($row['type']!=='group'||!$this->groupMentionsOnly((int)$row['conversation_id'],$recipient))return true;
        return (bool)$this->scalar('SELECT COUNT(*) FROM messenger_mentions WHERE message_id=? AND user_id=?',[$messageId,$recipient]);
    }
    private function mentionsPresentation(array $messages,User $actor):array {
        if(!$messages||!$this->mentionSchemaReady())return $messages;$ids=array_map(static fn($m)=>(int)$m['id'],$messages);$q=$this->wire('database')->prepare('SELECT message_id,user_id,username FROM messenger_mentions WHERE message_id IN ('.implode(',',array_fill(0,count($ids),'?')).') ORDER BY user_id');$q->execute($ids);$by=[];
        foreach($q->fetchAll(\PDO::FETCH_ASSOC)as$row){$mid=(int)$row['message_id'];$uid=(int)$row['user_id'];$by[$mid][]=['id'=>$uid,'name'=>$this->decryptValue((string)$row['username'],'Messenger|mention|'.$mid.'|'.$uid)];}
        foreach($messages as&$m){$items=empty($m['deleted_at'])?($by[(int)$m['id']]??[]):[];$m['mentions']=$items;$m['mentioned']=in_array((int)$actor->id,array_column($items,'id'),true);}return $messages;
    }
    /** Indexed current-recipient preview; a later ordinary message cannot mask a mention. */
    private function groupMentionPreview(int $id,User $actor,int $floor,int $read):?array {
        if(!$this->mentionSchemaReady())return null;$markers=$this->notificationMarkerTable();
        $q=$this->wire('database')->prepare("SELECT m.id,m.conversation_id,m.sender_user_id,m.client_id,m.body,m.created_at,m.deleted_at,m.expires_at FROM messenger_mentions t JOIN messenger_messages m ON m.id=t.message_id".($markers?" LEFT JOIN messenger_message_markers s ON s.message_id=m.id AND s.kind IN ('silent','forwarded_silent')":"")." JOIN messenger_participants p ON p.conversation_id=m.conversation_id AND p.user_id=t.user_id JOIN messenger_group_members gm ON gm.conversation_id=p.conversation_id AND gm.user_id=p.user_id AND gm.active=1 JOIN messenger_conversations c ON c.id=m.conversation_id AND c.state='active' AND c.type='group' WHERE t.conversation_id=? AND t.user_id=? AND m.conversation_id=t.conversation_id AND m.id>p.hidden_through_message_id AND m.id>gm.joined_after_id AND m.id>p.last_read_message_id AND m.id>? AND m.sender_user_id<>? AND m.deleted_at IS NULL AND m.moderation_state='visible' AND (m.expires_at IS NULL OR m.expires_at>?) AND NOT EXISTS(SELECT 1 FROM messenger_message_hides h WHERE h.message_id=m.id AND h.user_id=t.user_id) AND NOT EXISTS(SELECT 1 FROM messenger_blocks b WHERE (b.blocker_user_id=t.user_id AND b.blocked_user_id=m.sender_user_id) OR (b.blocked_user_id=t.user_id AND b.blocker_user_id=m.sender_user_id))".($markers?' AND s.message_id IS NULL':'').' ORDER BY m.id DESC LIMIT 30');$q->execute([$id,(int)$actor->id,max($floor,$read),(int)$actor->id,time()]);
        foreach($q->fetchAll(\PDO::FETCH_ASSOC)as$row){$sender=$this->wire('users')->get((int)$row['sender_user_id']);if($sender instanceof User&&$this->canReceive($sender))return $this->groupPreviewRow($row,$id,$actor);}return null;
    }
    public function setGroupMentionMode(User $actor,int $id,bool $only):array {
        $this->assertActor($actor);if(!$this->mentionsReady())throw new WirePermissionException('Group mentions unavailable.');
        return $this->atomic(function()use($actor,$id,$only){$db=$this->wire('database');$db->prepare(($db->getAttribute(\PDO::ATTR_DRIVER_NAME)==='mysql'?'INSERT IGNORE':'INSERT OR IGNORE').' INTO messenger_mention_locks(user_id) VALUES(?)')->execute([(int)$actor->id]);$q=$db->prepare('SELECT user_id FROM messenger_mention_locks WHERE user_id=?'.$this->forUpdate($db));$q->execute([(int)$actor->id]);$q->fetchColumn();$this->groupLock($id,$actor);$previous=$this->groupMentionsOnly($id,(int)$actor->id);if($previous!==$only){$this->consumeMemberRate($actor,'messenger_mention_locks');$this->execute('DELETE FROM messenger_mention_preferences WHERE conversation_id=? AND user_id=?',[$id,(int)$actor->id]);$this->execute('INSERT INTO messenger_mention_preferences(conversation_id,user_id,mentions_only) VALUES(?,?,?)',[$id,(int)$actor->id,(int)$only]);$this->audit('group_mention_mode',$id,0,(int)$actor->id);}return ['id'=>$id,'mentions_only'=>$only];});
    }
    public function groupMentions(User $actor,int $id,?int $before=null):array {
        $this->assertActor($actor);if(!$this->mentionsReady())throw new WirePermissionException('Group mentions unavailable.');$this->groupLock($id,$actor);if($before!==null&&$before<1)throw new \InvalidArgumentException('Invalid mention cursor.');
        $db=$this->wire('database');$q=$db->prepare("SELECT m.* FROM messenger_mentions t JOIN messenger_messages m ON m.id=t.message_id JOIN messenger_conversations c ON c.id=m.conversation_id AND c.type='group' AND c.state='active' JOIN messenger_participants p ON p.conversation_id=m.conversation_id AND p.user_id=t.user_id JOIN messenger_group_members gm ON gm.conversation_id=m.conversation_id AND gm.user_id=p.user_id AND gm.active=1 WHERE t.user_id=? AND t.conversation_id=m.conversation_id AND m.conversation_id=? AND m.id>p.hidden_through_message_id AND m.id>gm.joined_after_id AND m.deleted_at IS NULL AND m.moderation_state='visible' AND (m.expires_at IS NULL OR m.expires_at>?) AND NOT EXISTS(SELECT 1 FROM messenger_message_hides h WHERE h.message_id=m.id AND h.user_id=p.user_id)".($before!==null?' AND m.id<?':'').' ORDER BY m.id DESC LIMIT 31');$q->execute($before!==null?[(int)$actor->id,$id,time(),$before]:[(int)$actor->id,$id,time()]);$rows=$q->fetchAll(\PDO::FETCH_ASSOC);$more=count($rows)>30;$rows=array_slice($rows,0,30);$next=$more?(int)end($rows)['id']:null;$readers=$this->groupReaders($id);
        foreach($rows as&$m){foreach(['id','conversation_id','sender_user_id']as$key)$m[$key]=(int)$m[$key];$m['body']=$this->decryptMessageRow($m);$m['attachment']=$this->messageAttachment($m['id']);$m=$this->memberMessagePresentation($m,$actor);$m+=$this->groupReceipt($m,$readers);$u=$this->wire('users')->get($m['sender_user_id']);$m['sender']=['id'=>(int)$u->id,'name'=>(string)$u->name];unset($m['request_hash'],$m['client_id']);}
        return ['items'=>$this->messageToolsPresentation($rows,$actor),'next_before'=>$next];
    }
}
