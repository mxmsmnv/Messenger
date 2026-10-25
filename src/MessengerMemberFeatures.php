<?php namespace ProcessWire;

/** Additive member presence and encrypted, conversation-scoped media. */
trait MessengerMemberFeatures {
	private function installMemberFeatures(): void {
		$db=$this->wire('database');$sqlite=$db->getAttribute(\PDO::ATTR_DRIVER_NAME)==='sqlite';$engine=$sqlite?'':' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
		$db->exec('CREATE TABLE IF NOT EXISTS messenger_presence (user_id '.($sqlite?'INTEGER PRIMARY KEY':'INT UNSIGNED PRIMARY KEY').', status VARCHAR(16) NOT NULL, status_text TEXT NOT NULL, expires_at BIGINT NOT NULL)'.$engine);
		$db->exec('CREATE TABLE IF NOT EXISTS messenger_attachments (id '.($sqlite?'INTEGER PRIMARY KEY AUTOINCREMENT':'BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT').', message_id BIGINT NOT NULL UNIQUE, metadata TEXT NOT NULL, payload '.($sqlite?'TEXT':'LONGTEXT').' NOT NULL)'.$engine);
	}

	public function presence(User $actor): array {
		$this->assertActor($actor);return $this->memberPresence((int)$actor->id,true);
	}
	/** Renew activity without replaying a possibly stale device's status preference. */
	public function heartbeatPresence(User $actor): array {
		$this->assertActor($actor);$expires=time()+90;$empty=$this->encryptValue('','Messenger|presence|'.(int)$actor->id);
		return $this->atomic(function()use($actor,$expires,$empty):array{
			$sql=$this->wire('database')->getAttribute(\PDO::ATTR_DRIVER_NAME)==='sqlite'?'INSERT INTO messenger_presence (user_id,status,status_text,expires_at) VALUES (?,\'online\',?,?) ON CONFLICT(user_id) DO UPDATE SET expires_at=excluded.expires_at':'INSERT INTO messenger_presence (user_id,status,status_text,expires_at) VALUES (?,\'online\',?,?) ON DUPLICATE KEY UPDATE expires_at=VALUES(expires_at)';
			$this->execute($sql,[(int)$actor->id,$empty,$expires]);return $this->presence($actor);
		});
	}
	public function setPresence(User $actor,string $status,string $text=''): array {
		$this->assertActor($actor);
		if(!mb_check_encoding($text,'UTF-8')||!in_array($status,['online','away','busy','invisible','offline'],true)||mb_strlen($text)>120||preg_match('/[\x00-\x1F\x7F]/u',$text))throw new \InvalidArgumentException('Invalid presence.');
		$text=trim($text);$expires=time()+90;$cipher=$this->encryptValue($text,'Messenger|presence|'.(int)$actor->id);
		return $this->atomic(function()use($actor,$status,$expires,$cipher):array{
			$sql=$this->wire('database')->getAttribute(\PDO::ATTR_DRIVER_NAME)==='sqlite'?'INSERT INTO messenger_presence (user_id,status,status_text,expires_at) VALUES (?,?,?,?) ON CONFLICT(user_id) DO UPDATE SET status=excluded.status,status_text=excluded.status_text,expires_at=excluded.expires_at':'INSERT INTO messenger_presence (user_id,status,status_text,expires_at) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE status=VALUES(status),status_text=VALUES(status_text),expires_at=VALUES(expires_at)';
			$this->execute($sql,[(int)$actor->id,$status,$cipher,$expires]);return $this->presence($actor);
		});
	}
	private function memberPresence(int $id,bool $own=false): array {
		$stmt=$this->wire('database')->prepare('SELECT status,status_text,expires_at FROM messenger_presence WHERE user_id=?');$stmt->execute([$id]);$row=$stmt->fetch(\PDO::FETCH_ASSOC);
		if(!$row)return ['status'=>$own?'online':'offline','text'=>'','expires_at'=>0];
		// Preserve the owner's selected status for the next heartbeat; peers never see invisible or expiry times.
		if(!$own&&($row['status']==='invisible'||$row['status']==='offline'||(int)$row['expires_at']<=time()))return ['status'=>'offline','text'=>'','expires_at'=>0];
		return ['status'=>(string)$row['status'],'text'=>$this->decryptValue((string)$row['status_text'],'Messenger|presence|'.$id),'expires_at'=>$own?(int)$row['expires_at']:0];
	}
	public function memberDirectory(User $actor,string $query='',int $limit=30,bool $contacts=false): array { return $this->directoryMembers($actor,$query,$limit,$contacts?'peers':'directory'); }
	public function contactBook(User $actor,string $query='',int $limit=30): array { return $this->directoryMembers($actor,$query,$limit,'book'); }
	private function directoryMembers(User $actor,string $query,int $limit,string $mode): array {
		$this->assertActor($actor);$query=trim($query);if(mb_strlen($query)>120)throw new \InvalidArgumentException('Query is too long.');$limit=max(1,min(30,$limit));
		if($mode==='book'){$stmt=$this->wire('database')->prepare('SELECT contact_id FROM messenger_contacts WHERE owner_id=? ORDER BY added_at DESC,contact_id LIMIT 100');$stmt->execute([(int)$actor->id]);$candidates=[];foreach($stmt->fetchAll(\PDO::FETCH_COLUMN) as $id)$candidates[]=$this->wire('users')->get((int)$id);}
		elseif($mode==='peers'){$stmt=$this->wire('database')->prepare('SELECT DISTINCT p.user_id FROM messenger_participants p JOIN messenger_participants own ON own.conversation_id=p.conversation_id AND own.user_id=? WHERE p.user_id<>? ORDER BY p.user_id LIMIT 100');$stmt->execute([(int)$actor->id,(int)$actor->id]);$candidates=[];foreach($stmt->fetchAll(\PDO::FETCH_COLUMN) as $id)$candidates[]=$this->wire('users')->get((int)$id);}
		else{$selector='id!='.(int)$actor->id.', include=all, sort=name, limit=90';if($query!=='')$selector.=', name*='.$this->wire('sanitizer')->selectorValue($query);$candidates=$this->wire('users')->find($selector);}
		$result=[];foreach($candidates as $member){if(!$member instanceof User||!$this->canReceive($member)||$this->isBlockedEitherWay((int)$actor->id,(int)$member->id))continue;if($mode!=='directory'&&$query!==''&&mb_stripos((string)$member->name,$query)===false)continue;
			$result[]=['id'=>(int)$member->id,'name'=>(string)$member->name,'title'=>(string)($member->get('display_name')?:$member->get('title')?:$member->name),'presence'=>$this->memberPresence((int)$member->id),'added_at'=>$this->contactAddedAt((int)$actor->id,(int)$member->id)];if(count($result)>=$limit)break;}
		return $result;
	}
    private function canUseConversationMedia(array $chat,User $actor):bool {
        if(($chat['type']??'direct')==='saved')return $this->savedOwnerAllowed($chat,$actor);
        if(($chat['type']??'direct')==='group')return $this->groupCanSend((int)$chat['id'],$actor);
        return $this->canReceive($this->wire('users')->get((int)$chat['other_user']['id']));
    }

	public function sendAttachment(int $conversationId,User $actor,array $input,string $clientId): array {
		$this->assertActor($actor);
		// Reject unavailable conversations before decoding or sniffing a large media payload.
		// sendMessageWithAttachment repeats these checks under its transaction lock.
		$conversation=$this->conversation($conversationId,$actor);
		if(!$conversation||$conversation['state']!=='active'||$conversation['request_state']!=='accepted'||$conversation['blocked']||!$this->canUseConversationMedia($conversation,$actor))throw new WirePermissionException('Attachment unavailable.');
		$attachment=$this->validateAttachment($input);
		$body=isset($input['body'])&&is_string($input['body'])?trim($input['body']):'';
		if($body==='')$body=$attachment['kind']==='voice'?'Voice message':$attachment['filename'];
		return $this->sendMessageWithAttachment($conversationId,$actor,$body,$clientId,$attachment);
	}
	private function validateAttachment(array $input): array {
		$kind=(string)($input['kind']??'file');$filename=(string)($input['filename']??'');$mime=strtolower((string)($input['mime_type']??''));$encoded=$input['data_base64']??null;
		if(!mb_check_encoding($filename,'UTF-8')||!in_array($kind,['file','voice'],true)||$filename===''||mb_strlen($filename)>180||preg_match('/[\x00-\x1F\x7F\\\\\/]/u',$filename)||!preg_match('/^[a-z0-9][a-z0-9.+-]*\/[a-z0-9][a-z0-9.+-]*$/D',$mime)||!is_string($encoded))throw new \InvalidArgumentException('Invalid attachment.');
		$max=$kind==='voice'?4*1024*1024:8*1024*1024;if(strlen($encoded)>4*(int)ceil($max/3))throw new \InvalidArgumentException('Attachment is too large.');
		$bytes=base64_decode($encoded,true);if($bytes===false||$bytes===''||strlen($bytes)>$max)throw new \InvalidArgumentException('Attachment is empty or too large.');
		$actual=(new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);if(!is_string($actual))throw new \InvalidArgumentException('Unsupported attachment.');
		// Apple AAC-in-M4A is reported by libmagic as this alias; retain the
		// existing declared audio/mp4 contract and validate the actual bytes.
		if($kind==='voice'&&$actual==='audio/x-m4a')$actual='audio/mp4';
		// Files are downloaded as opaque bytes; active content is never rendered or exposed by public URL.
		if($kind==='voice'&&(!in_array($mime,['audio/mp4','audio/mpeg','audio/aac','audio/ogg','audio/wav','audio/x-wav','audio/webm','audio/3gpp'],true)||!in_array($actual,['audio/mp4','video/mp4','audio/mpeg','audio/aac','audio/x-hx-aac-adts','audio/ogg','application/ogg','audio/wav','audio/x-wav','video/webm','audio/webm','audio/3gpp','video/3gpp'],true)))throw new \InvalidArgumentException('Unsupported voice format.');
		$duration=$input['duration_ms']??null;if($duration!==null&&(!is_int($duration)||$duration<1||$duration>120000))throw new \InvalidArgumentException('Invalid recording duration.');
		return ['filename'=>$filename,'mime_type'=>$kind==='voice'?$mime:$actual,'size'=>strlen($bytes),'kind'=>$kind,'duration_ms'=>$kind==='voice'?$duration:null,'data_base64'=>base64_encode($bytes)];
	}
	/** Optional presentation identity; never an authorization or attachment lookup key. */
	private function messageMediaGroup(array $message,string $clientId): ?string {
		$attachment=$message['attachment']??null;
		if(!empty($message['deleted_at'])||!is_array($attachment)||($attachment['kind']??'')!=='file'||!in_array($attachment['mime_type']??'',['image/jpeg','image/png','image/webp','image/gif','image/heic'],true))return null;
		return preg_match('/^album_([A-Za-z0-9_-]{43})_[0-9]$/D',$clientId,$match)===1?$match[1]:null;
	}
	private function attachmentRequestBody(string $body,?array $attachment): string {return $attachment===null?$body:$body."\0Messenger|attachment|v1|".json_encode($attachment,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);}
	private function storeAttachment(int $messageId,array $attachment): array {
		$payload=json_encode($attachment,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
		$metadata=$attachment;unset($metadata['data_base64']);
		$this->execute('INSERT INTO messenger_attachments (message_id,metadata,payload) VALUES (?,?,?)',[$messageId,$this->encryptValue(json_encode($metadata,JSON_THROW_ON_ERROR),'Messenger|attachment-metadata|'.$messageId),$this->encryptValue($payload,'Messenger|attachment|'.$messageId)]);$metadata['id']=(int)$this->wire('database')->lastInsertId();return $metadata;
	}
	private function messageAttachment(int $messageId,bool $bytes=false): ?array {
		$expiry=$this->scalar('SELECT COALESCE(expires_at,0) FROM messenger_messages WHERE id=?',[$messageId]);if($expiry>0&&$expiry<=time())return null;
		$column=$bytes?'payload':'metadata';$stmt=$this->wire('database')->prepare('SELECT id,'.$column.' FROM messenger_attachments WHERE message_id=?');$stmt->execute([$messageId]);$row=$stmt->fetch(\PDO::FETCH_ASSOC);if(!$row)return null;
		$item=json_decode($this->decryptValue((string)$row[$column],($bytes?'Messenger|attachment|':'Messenger|attachment-metadata|').$messageId),true,512,JSON_THROW_ON_ERROR);$item['id']=(int)$row['id'];if(!$bytes)unset($item['data_base64']);return $item;
	}
	public function attachment(User $actor,int $attachmentId): array {
		$this->assertActor($actor);$stmt=$this->wire('database')->prepare('SELECT m.id,m.conversation_id,m.deleted_at,m.moderation_state,m.expires_at FROM messenger_attachments a JOIN messenger_messages m ON m.id=a.message_id WHERE a.id=?');$stmt->execute([$attachmentId]);$message=$stmt->fetch(\PDO::FETCH_ASSOC);
		if(!$message||($message['expires_at']!==null&&(int)$message['expires_at']<=time())||$message['deleted_at']||$message['moderation_state']!=='visible')throw new WirePermissionException('Attachment unavailable.');
		$conversation=$this->conversation((int)$message['conversation_id'],$actor);if(!$conversation||(int)$message['id']<=(int)($conversation['hidden_through_message_id']??0)||$conversation['state']!=='active'||$conversation['request_state']!=='accepted'||$conversation['blocked']||!$this->canUseConversationMedia($conversation,$actor)||$this->scalar('SELECT COUNT(*) FROM messenger_message_hides WHERE message_id=? AND user_id=?',[(int)$message['id'],(int)$actor->id]))throw new WirePermissionException('Attachment unavailable.');
		return $this->messageAttachment((int)$message['id'],true)??throw new WirePermissionException('Attachment unavailable.');
	}
}
