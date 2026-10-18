<?php namespace ProcessWire;

/** Explicit private contacts, participant mute, and server-enforced message expiry. */
trait MessengerConversationSettings {
	private function installConversationSettings(): void {
		$db=$this->wire('database');$sqlite=$db->getAttribute(\PDO::ATTR_DRIVER_NAME)==='sqlite';
		$db->exec('CREATE TABLE IF NOT EXISTS messenger_contacts (owner_id BIGINT NOT NULL, contact_id BIGINT NOT NULL, added_at BIGINT NOT NULL, PRIMARY KEY(owner_id,contact_id))'.($sqlite?'':' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'));
		foreach([['messenger_messages','expires_at','BIGINT NULL'],['messenger_conversations','auto_delete_seconds','BIGINT NOT NULL DEFAULT 0'],['messenger_conversations','auto_delete_at','BIGINT NULL'],['messenger_participants','hidden_through_message_id','BIGINT NOT NULL DEFAULT 0']] as [$table,$column,$definition]){
			try{$db->query('SELECT '.$column.' FROM '.$table.' WHERE 1=0');}
			catch(\PDOException $e){if((string)$e->getCode()!=='42S22'&&!($sqlite&&str_contains($e->getMessage(),'no such column')))throw $e;$db->exec('ALTER TABLE '.$table.' ADD COLUMN '.$column.' '.$definition);}
		}
		// Add the expiry index once; index metadata is never message content.
		$exists=$sqlite?$db->query("SELECT COUNT(*) FROM sqlite_master WHERE type='index' AND name='messenger_message_expiry'")->fetchColumn():$db->query("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='messenger_messages' AND INDEX_NAME='messenger_message_expiry'")->fetchColumn();
		if(!$exists)$db->exec('CREATE INDEX messenger_message_expiry ON messenger_messages (expires_at,id)');
	}
	public function setContact(User $actor,User $contact,bool $added): array {
		if(!$added)return $this->removeContact($actor,(int)$contact->id);$this->assertActor($actor);
		if(!$contact->id||(int)$contact->id===(int)$actor->id)throw new WirePermissionException('Member unavailable.');
		$this->assertRecipient($actor,$contact);
		return $this->atomic(function()use($actor,$contact,$added):array{
			if($added){$sql=$this->wire('database')->getAttribute(\PDO::ATTR_DRIVER_NAME)==='sqlite'?'INSERT INTO messenger_contacts (owner_id,contact_id,added_at) VALUES (?,?,?) ON CONFLICT(owner_id,contact_id) DO NOTHING':'INSERT INTO messenger_contacts (owner_id,contact_id,added_at) VALUES (?,?,?) ON DUPLICATE KEY UPDATE added_at=added_at';$this->execute($sql,[(int)$actor->id,(int)$contact->id,time()]);}
			else $this->execute('DELETE FROM messenger_contacts WHERE owner_id=? AND contact_id=?',[(int)$actor->id,(int)$contact->id]);
			return ['added'=>$added,'user_id'=>(int)$contact->id,'added_at'=>$this->contactAddedAt((int)$actor->id,(int)$contact->id)];
		});
	}
	/** Removal never resolves the target: inaccessible and nonexistent IDs have identical results. */
	public function removeContact(User $actor,int $contactId): array {
		$this->assertActor($actor);if($contactId<1)throw new \InvalidArgumentException('A positive contact ID is required.');
		return $this->atomic(function()use($actor,$contactId):array{$this->execute('DELETE FROM messenger_contacts WHERE owner_id=? AND contact_id=?',[(int)$actor->id,$contactId]);return ['added'=>false,'user_id'=>$contactId,'added_at'=>null];});
	}
	private function contactAddedAt(int $ownerId,int $contactId): ?int { $time=$this->scalar('SELECT added_at FROM messenger_contacts WHERE owner_id=? AND contact_id=?',[$ownerId,$contactId]);return $time?:null; }
	public function setConversationMuted(int $id,User $actor,bool $muted): array { $this->setMuted($id,$actor,$muted?date('Y-m-d H:i:s',4102444800):null);return $this->conversation($id,$actor); }
	public function setConversationRetention(int $id,User $actor,int $seconds=0,?int $at=null): array {
		$this->assertActor($actor);if(!in_array($seconds,[0,86400,604800,2592000],true)||($at!==null&&$seconds!==0))throw new \InvalidArgumentException('Invalid auto-delete policy.');$this->validateExpiry($at);
		return $this->atomic(function()use($id,$actor,$seconds,$at):array{
			$stmt=$this->wire('database')->prepare('SELECT id FROM messenger_conversations WHERE id=?'.$this->forUpdate($this->wire('database')));$stmt->execute([$id]);
			$conversation=$this->conversation($id,$actor);if(!$conversation||$conversation['state']!=='active'||$conversation['request_state']!=='accepted'||$conversation['blocked'])throw new WirePermissionException('Conversation unavailable.');
			if(!$this->canReceive($this->wire('users')->get((int)$conversation['other_user']['id'])))throw new WirePermissionException('Conversation unavailable.');
			$this->execute('UPDATE messenger_conversations SET auto_delete_seconds=?,auto_delete_at=? WHERE id=?',[$seconds,$at,$id]);$this->audit('retention_changed',$id,0,(int)$actor->id,['seconds'=>$seconds,'at'=>$at]);return $this->conversation($id,$actor);
		});
	}
	public function deleteConversationForUser(int $id,User $actor): array {
		$this->assertActor($actor);
		return $this->atomic(function()use($id,$actor):array{
			$stmt=$this->wire('database')->prepare('SELECT id FROM messenger_conversations WHERE id=?'.$this->forUpdate($this->wire('database')));$stmt->execute([$id]);
			$conversation=$this->conversation($id,$actor);if(!$conversation)throw new WirePermissionException('Conversation unavailable.');
			$through=$this->scalar('SELECT COALESCE(MAX(id),0) FROM messenger_messages WHERE conversation_id=?',[$id]);
			$this->execute('UPDATE messenger_participants SET hidden_through_message_id=?,hidden_before=?,archived_at=?,last_read_message_id=GREATEST(last_read_message_id,CAST(? AS UNSIGNED)) WHERE conversation_id=? AND user_id=?',[$through,$this->now(),$this->now(),$through,$id,(int)$actor->id]);
			$this->execute("UPDATE messenger_notification_outbox SET status='cancelled',processed_at=? WHERE conversation_id=? AND recipient_user_id=? AND message_id<=? AND status='pending'",[$this->now(),$id,(int)$actor->id,$through]);
			$this->audit('conversation_deleted_for_user',$id,0,(int)$actor->id);return ['deleted'=>true];
		});
	}
	public function setMessageExpiry(int $id,User $actor,?int $expiresAt): array {
		$this->assertActor($actor);$this->validateExpiry($expiresAt);
		return $this->atomic(function()use($id,$actor,$expiresAt):array{
			$message=$this->ownedMessage($id,$actor);$conversation=$this->conversation((int)$message['conversation_id'],$actor);
			if($message['deleted_at']||!$conversation||$conversation['blocked']||$conversation['request_state']!=='accepted')throw new WirePermissionException('Message unavailable.');
			$this->execute('UPDATE messenger_messages SET expires_at=? WHERE id=?',[$expiresAt,$id]);$this->audit('message_expiry_changed',(int)$message['conversation_id'],$id,(int)$actor->id,['expires_at'=>$expiresAt]);$message['expires_at']=$expiresAt;unset($message['request_hash'],$message['client_id']);$message['attachment']=$this->messageAttachment($id);return $message;
		});
	}
	private function validateExpiry(?int $at): void { if($at!==null&&($at<=time()+60||$at>time()+315360000))throw new \InvalidArgumentException('Expiry must be more than a minute and at most ten years ahead.'); }
	private function insertExpiry(int $conversationId): ?int {
		$stmt=$this->wire('database')->prepare('SELECT auto_delete_seconds,auto_delete_at FROM messenger_conversations WHERE id=?');$stmt->execute([$conversationId]);$policy=$stmt->fetch(\PDO::FETCH_ASSOC);
		if(!$policy)return null;if($policy['auto_delete_at']!==null){$at=(int)$policy['auto_delete_at'];if($at<=time())throw new WirePermissionException('The conversation auto-delete deadline has passed. Change the policy before sending.');return $at;}
		$seconds=(int)$policy['auto_delete_seconds'];return $seconds>0?time()+$seconds:null;
	}
	private function applyExpiry(array $message): array {
		$message['expires_at']=isset($message['expires_at'])?(int)$message['expires_at']:null;
		if($message['expires_at']!==null&&$message['expires_at']<=time()){$message['deleted_at']=$message['deleted_at']?:date('Y-m-d H:i:s',$message['expires_at']);$message['body']='';$message['attachment']=null;}
		return $message;
	}
	/** Bounded cleanup, callable by an explicitly configured maintenance worker. No worker is installed. */
	public function sweepExpiredMessages(int $limit=100,bool $execute=false,?User $actor=null): array {
		if($actor!==null&&!$this->canUse($actor))throw new WirePermissionException('Messenger access denied.');$limit=max(1,min(500,$limit));$db=$this->wire('database');
		return $this->atomic(function()use($actor,$limit,$execute,$db):array{
			$where='m.expires_at IS NOT NULL AND m.expires_at<=? AND (m.deleted_at IS NULL OR EXISTS (SELECT 1 FROM messenger_attachments a WHERE a.message_id=m.id))';$params=[time()];
			if($actor!==null){$where.=' AND EXISTS (SELECT 1 FROM messenger_participants p WHERE p.conversation_id=m.conversation_id AND p.user_id=?)';$params[]=(int)$actor->id;}
			$stmt=$db->prepare('SELECT m.id,m.conversation_id FROM messenger_messages m WHERE '.$where.' ORDER BY m.id LIMIT '.$limit.($execute?$this->forUpdate($db):''));$stmt->execute($params);$rows=$stmt->fetchAll(\PDO::FETCH_ASSOC);
			if($execute)foreach($rows as $row){$id=(int)$row['id'];$this->execute("UPDATE messenger_messages SET body='',deleted_at=COALESCE(deleted_at,?) WHERE id=?",[$this->now(),$id]);$this->execute('DELETE FROM messenger_attachments WHERE message_id=?',[$id]);$this->execute("UPDATE messenger_notification_outbox SET status='cancelled',processed_at=? WHERE message_id=? AND status='pending'",[$this->now(),$id]);$this->audit('message_expired',(int)$row['conversation_id'],$id,0);}
			return ['selected'=>count($rows),'expired'=>$execute?count($rows):0,'execute'=>$execute];
		});
	}
}
