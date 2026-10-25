<?php namespace ProcessWire;

/** Additive group membership. Direct conversations keep their existing identity. */
trait MessengerGroups {
    public function groupsReady(): bool {
        if(!(bool)$this->groups_enabled) return false;
        try { return $this->wire('database')->query('SELECT conversation_id,owner_id,created_by,client_id,request_hash,title,avatar,version,avatar_version FROM messenger_groups LIMIT 0')!==false && $this->wire('database')->query('SELECT conversation_id,user_id,joined_after_id,joined_at,active FROM messenger_group_members LIMIT 0')!==false && $this->wire('database')->query('SELECT conversation_id,user_id,inviter_id,expires_at FROM messenger_group_invites LIMIT 0')!==false; }
        catch(\PDOException $error) { return false; }
    }
    /** Explicit operator migration, never from discovery or a member request. */
    public function installGroups(User $actor): void {
        if(!$this->canAdmin($actor)) throw new WirePermissionException('Administrative access required.');
        $db=$this->wire('database');$engine=$db->getAttribute(\PDO::ATTR_DRIVER_NAME)==='sqlite'?'':' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        $clientColumn=$engine?'VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin':'VARCHAR(64) COLLATE BINARY';
        foreach([
            'messenger_groups'=>'conversation_id BIGINT PRIMARY KEY,owner_id BIGINT NOT NULL,created_by BIGINT NOT NULL,client_id '.$clientColumn.' NOT NULL,request_hash CHAR(64) NOT NULL,title TEXT NOT NULL,avatar '.($engine?'MEDIUMTEXT':'TEXT').' NULL,version BIGINT NOT NULL,avatar_version BIGINT NOT NULL DEFAULT 0,UNIQUE(created_by,client_id)',
            'messenger_group_members'=>'conversation_id BIGINT NOT NULL,user_id BIGINT NOT NULL,joined_after_id BIGINT NOT NULL,joined_at BIGINT NOT NULL,active INTEGER NOT NULL,PRIMARY KEY(conversation_id,user_id)',
            'messenger_group_invites'=>'conversation_id BIGINT NOT NULL,user_id BIGINT NOT NULL,inviter_id BIGINT NOT NULL,expires_at BIGINT NOT NULL,PRIMARY KEY(conversation_id,user_id)',
        ]as$table=>$columns)$db->exec("CREATE TABLE IF NOT EXISTS `$table` ($columns)".$engine);
    }
    public function groupRequest(User $actor,string $resource,string $method,array $body,array $query): array {
        $this->requireGroups($actor);
        if($resource==='group-mention-mode'&&$method==='POST'){if(!is_int($body['id']??null)||!is_bool($body['mentions_only']??null))throw new \InvalidArgumentException('Exact group notification mode required.');return $this->setGroupMentionMode($actor,$body['id'],$body['mentions_only']);}
        if($resource==='group-mentions'&&$method==='GET'){$id=$query['id']??'';$before=$query['before']??null;if(!is_string($id)||!preg_match('/^[1-9][0-9]{0,17}$/D',$id)||($before!==null&&(!is_string($before)||!preg_match('/^[1-9][0-9]{0,17}$/D',$before))))throw new \InvalidArgumentException('Group and mention cursor required.');return $this->groupMentions($actor,(int)$id,$before===null?null:(int)$before);}
        if(str_starts_with($resource,'group-link-'))return $this->groupLinkRequest($actor,$resource,$method,$body,$query);
        if(str_starts_with($resource,'group-poll')) {
            if($resource==='group-poll'&&$method==='GET'){$id=$query['message_id']??null;if(!is_string($id)||!preg_match('/^[1-9][0-9]{0,17}$/D',$id))throw new \InvalidArgumentException('Poll ID required.');return $this->groupPoll($actor,(int)$id);}
            if($method!=='POST')throw new \InvalidArgumentException('Poll action unavailable.');
            if($resource==='group-poll-create'){if(!is_int($body['id']??null)||!is_string($body['question']??null)||!is_array($body['options']??null)||!is_string($body['client_id']??null))throw new \InvalidArgumentException('Poll details required.');return $this->createGroupPoll($actor,$body['id'],$body['question'],$body['options'],$body['client_id']);}
            if(!is_int($body['message_id']??null))throw new \InvalidArgumentException('Poll ID required.');
            if($resource==='group-poll-close')return $this->closeGroupPoll($actor,$body['message_id']);
            if($resource==='group-poll-vote'){if(!array_key_exists('choice',$body)||($body['choice']!==null&&!is_int($body['choice'])))throw new \InvalidArgumentException('Exact poll choice required.');return $this->voteGroupPoll($actor,$body['message_id'],$body['choice']);}
            throw new \InvalidArgumentException('Poll action unavailable.');
        }
        if($resource==='group-invitations' && $method==='GET')return $this->groupInvitations($actor);
        if($resource==='group-create' && $method==='POST'){
            if(!is_string($body['title']??null)||!is_array($body['user_ids']??null)||!is_string($body['client_id']??null))throw new \InvalidArgumentException('Invalid group creation.');
            return $this->createGroup($actor,$body['title'],$body['user_ids'],$body['client_id']);
        }
        $id=$method==='GET'?($query['id']??null):($body['id']??null);
        if(is_string($id)&&preg_match('/^[1-9][0-9]{0,18}$/D',$id)&&(string)(int)$id===$id)$id=(int)$id;
        if(!is_int($id)||$id<1)throw new \InvalidArgumentException('A positive group ID is required.');
        if($resource==='group-details'&&$method==='GET')return $this->groupDetails($id,$actor);
        if($resource==='group-avatar'&&$method==='GET')return ['avatar'=>$this->groupAvatar($id,$actor)];
        if($resource==='group-respond'&&$method==='POST'){
            if(!is_bool($body['accept']??null))throw new \InvalidArgumentException('An invitation decision is required.');
            return $this->respondGroupInvitation($id,$actor,$body['accept']);
        }
        $action=substr($resource,6);
        if($method!=='POST'||!in_array($action,['rename','invite','remove','leave','role','transfer','delete','avatar'],true))throw new \InvalidArgumentException('Group action unavailable.');
        if($action==='avatar'&&!array_key_exists('avatar',$body))throw new \InvalidArgumentException('A photo or explicit removal is required.');
        if($action==='rename'&&!is_string($body['title']??null))throw new \InvalidArgumentException('A group name is required.');
        if($action==='invite'&&!is_array($body['user_ids']??null))throw new \InvalidArgumentException('Members required.');
        return $this->changeGroup($id,$actor,$action,$body);
    }
    private function groupReaders(int $id,bool $current=false): array {
        $q=$this->wire('database')->prepare('SELECT p.user_id,p.last_read_message_id,gm.joined_after_id FROM messenger_participants p JOIN messenger_group_members gm ON gm.conversation_id=p.conversation_id AND gm.user_id=p.user_id AND gm.active=1 WHERE p.conversation_id=? ORDER BY p.user_id LIMIT 100'.($current&&$this->wire('database')->inTransaction()?$this->forUpdate($this->wire('database')):''));$q->execute([$id]);return $q->fetchAll(\PDO::FETCH_ASSOC);
    }
    private function groupReceipt(array $message,array $readers): array {
        if(!empty($message['deleted_at']))return ['read_count'=>0,'recipient_count'=>0,'read_by_all'=>false];
        $read=0;$total=0;foreach($readers as$p){if((int)$p['user_id']===(int)$message['sender_user_id']||(int)$p['joined_after_id']>=(int)$message['id'])continue;$total++;if((int)$p['last_read_message_id']>=(int)$message['id'])$read++;}
        return ['read_count'=>$read,'recipient_count'=>$total,'read_by_all'=>$total>0&&$read===$total];
    }
    /** Aggregate receipts for the current audience; newcomers never count for older messages. */
    private function groupMessageInfo(int $messageId,User $actor): array {
        if(!$this->groupsReady()||!$this->canUse($actor))throw new WirePermissionException('Message unavailable.');
        $q=$this->wire('database')->prepare("SELECT m.* FROM messenger_messages m JOIN messenger_conversations c ON c.id=m.conversation_id JOIN messenger_participants p ON p.conversation_id=c.id AND p.user_id=? JOIN messenger_group_members gm ON gm.conversation_id=c.id AND gm.user_id=p.user_id AND gm.active=1 WHERE m.id=? AND c.type='group' AND c.state='active' AND m.id>p.hidden_through_message_id AND m.id>gm.joined_after_id AND m.deleted_at IS NULL AND m.moderation_state='visible' AND (m.expires_at IS NULL OR m.expires_at>?) AND NOT EXISTS(SELECT 1 FROM messenger_message_hides h WHERE h.user_id=p.user_id AND h.message_id=m.id)");$q->execute([(int)$actor->id,$messageId,time()]);$m=$q->fetch(\PDO::FETCH_ASSOC);if(!$m)throw new WirePermissionException('Message unavailable.');
        $receipt=$this->groupReceipt($m,$this->groupReaders((int)$m['conversation_id']));
        $own=(int)$m['sender_user_id']===(int)$actor->id;
        return ['message_id'=>$messageId,'sent_at'=>$m['created_at'],'is_own'=>$own,'is_read'=>$own?$receipt['read_by_all']:true,'read_at'=>null,'read_count'=>$receipt['read_count'],'recipient_count'=>$receipt['recipient_count']];
    }
    private function requireGroups(User $actor): void {
        $this->assertActor($actor);
        if(!$this->groupsReady()) throw new WirePermissionException('Group conversations unavailable.');
    }
    private function groupTitle(string $title): string {
        $title=trim($title);
        if(!mb_check_encoding($title,'UTF-8') || mb_strlen($title)<1 || mb_strlen($title)>100 || preg_match('/[\x00-\x1F\x7F]/u',$title)) throw new \InvalidArgumentException('A group name of 1 to 100 characters is required.');
        return $title;
    }
    private function groupLock(int $id,User $actor,bool $admin=false): array {
        $this->requireGroups($actor);$db=$this->wire('database');
        // Canonical order shared with send/edit: conversation before membership/group rows.
        // A join's optimizer-selected row order is not a stable locking protocol.
        if($db->inTransaction()){$lock=$db->prepare('SELECT id FROM messenger_conversations WHERE id=?'.$this->forUpdate($db));$lock->execute([$id]);$lock->fetchColumn();}
        $q=$db->prepare("SELECT g.*,p.role FROM messenger_groups g JOIN messenger_conversations c ON c.id=g.conversation_id JOIN messenger_participants p ON p.conversation_id=g.conversation_id AND p.user_id=? JOIN messenger_group_members gm ON gm.conversation_id=g.conversation_id AND gm.user_id=p.user_id AND gm.active=1 WHERE g.conversation_id=? AND c.type='group' AND c.state='active'".($db->inTransaction()?$this->forUpdate($db):''));
        $q->execute([(int)$actor->id,$id]);$row=$q->fetch(\PDO::FETCH_ASSOC);
        if(!$row || ($admin&&!in_array($row['role'],['owner','admin'],true)))throw new WirePermissionException('Group unavailable.');
        return $row;
    }
    private function groupIds(array $ids,User $actor): array {
        if(count($ids)>99)throw new \InvalidArgumentException('Groups support up to 100 members.');
        foreach($ids as$id)if(!is_int($id)||$id<1||$id===(int)$actor->id)throw new \InvalidArgumentException('Invalid group members.');
        $ids=array_values(array_unique($ids));sort($ids,SORT_NUMERIC);return $ids;
    }
    public function createGroup(User $actor,string $title,array $ids,string $clientId): array {
        $this->requireGroups($actor);$title=$this->groupTitle($title);$ids=$this->groupIds($ids,$actor);
        if(!$ids || $clientId==='')throw new \InvalidArgumentException('Members and a client ID are required.');
        $clientId=$this->clientId($clientId);$hash=$this->contentFingerprint(json_encode([$title,$ids],JSON_THROW_ON_ERROR));
        $operation=function()use($actor,$title,$ids,$clientId,$hash):array{
            $this->groupLinkActorLock($actor);
            $db=$this->wire('database');$q=$db->prepare('SELECT conversation_id,request_hash FROM messenger_groups WHERE created_by=? AND client_id=?');$q->execute([(int)$actor->id,$clientId]);$old=$q->fetch(\PDO::FETCH_ASSOC);
            if($old){if(!hash_equals($old['request_hash'],$hash))throw new MessengerMobileException('idempotency_conflict','Group creation conflicts with an earlier operation.',409);return $this->groupDetails((int)$old['conversation_id'],$actor);}
            $this->assertNewRecipientRate((int)$actor->id);$now=$this->now();
            $q=$db->prepare("INSERT INTO messenger_conversations(public_key,direct_key,type,state,request_state,requester_id,recipient_id,last_message_id,last_message_at,created_at,updated_at) VALUES(?,?,'group','active','accepted',?,0,0,NULL,?,?)");
            $q->execute([bin2hex(random_bytes(16)),hash('sha256','group:'.random_bytes(32)),(int)$actor->id,$now,$now]);$id=(int)$db->lastInsertId();
            $this->execute('INSERT INTO messenger_groups(conversation_id,owner_id,created_by,client_id,request_hash,title,avatar,version) VALUES(?,?,?,?,?,?,NULL,1)',[$id,(int)$actor->id,(int)$actor->id,$clientId,$hash,$this->encryptValue($title,'Messenger|group-title|'.$id)]);
            $this->joinGroupMember($id,$actor,'owner');
            foreach($ids as$member)$this->inviteGroupMember($id,$actor,$member);
            $this->audit('group_created',$id,0,(int)$actor->id);
            return $this->groupDetails($id,$actor);
        };
        try{return $this->atomic($operation);}catch(\PDOException $e){if(!in_array((string)$e->getCode(),['23000','23505'],true))throw $e;return $this->atomic($operation);}
    }
    private function groupActor(User $actor,callable $operation) {
        $previous=$this->wire('user');
        try{$this->wire('users')->setCurrentUser($actor);return $operation();}
        finally{$this->wire('users')->setCurrentUser($previous);}
    }
    private function groupPolicy(User $actor,User $target,int $id): void {
        $this->assertRecipient($actor,$target);
        $previous=$this->wire('user');
        try{$this->wire('users')->setCurrentUser($actor);$decision=$this->messagingDecision($actor,$target,['group_id'=>$id,'purpose'=>'group_invitation']);}
        finally{$this->wire('users')->setCurrentUser($previous);}
        if(!in_array($decision['decision']??'',['allow','request'],true))throw new WirePermissionException('Group invitation unavailable.');
        if($this->scalar('SELECT COUNT(*) FROM messenger_participants p JOIN messenger_blocks b ON (b.blocker_user_id=p.user_id AND b.blocked_user_id=?) OR (b.blocked_user_id=p.user_id AND b.blocker_user_id=?) WHERE p.conversation_id=? AND p.user_id<>?',[(int)$target->id,(int)$target->id,$id,(int)$target->id]))throw new WirePermissionException('Group invitation unavailable.');
    }
    private function assertGroupInviteRate(User $actor): void {
        $since=date('Y-m-d H:i:s',time()-86400);
        $used=$this->scalar("SELECT COUNT(*) FROM messenger_conversations WHERE requester_id=? AND type='direct' AND created_at>=?",[(int)$actor->id,$since]);
        $used+=$this->scalar("SELECT COUNT(*) FROM messenger_audit WHERE actor_user_id=? AND action='group_invited' AND created_at>=?",[(int)$actor->id,$since]);
        if($used>=max(1,(int)$this->max_new_recipients_day))throw new MessengerMobileException('rate_limited','Daily new recipient limit reached.',429);
    }
    private function inviteGroupMember(int $id,User $actor,int $targetId): void {
        $target=$this->wire('users')->get($targetId);if(!$target instanceof User)throw new WirePermissionException('Group invitation unavailable.');$this->groupPolicy($actor,$target,$id);
        if($this->scalar('SELECT COUNT(*) FROM messenger_participants WHERE conversation_id=? AND user_id=?',[$id,$targetId]))return;
        if($this->scalar('SELECT COUNT(*) FROM messenger_group_invites WHERE conversation_id=? AND user_id=? AND expires_at>?',[$id,$targetId,time()]))return;
        if($this->scalar('SELECT COUNT(*) FROM messenger_participants WHERE conversation_id=?',[$id])+$this->scalar('SELECT COUNT(*) FROM messenger_group_invites WHERE conversation_id=? AND expires_at>?',[$id,time()])>=100)throw new WirePermissionException('Group member limit reached.');
        $this->assertGroupInviteRate($actor);
        $this->execute('DELETE FROM messenger_group_invites WHERE conversation_id=? AND user_id=?',[$id,$targetId]);
        $this->execute('INSERT INTO messenger_group_invites(conversation_id,user_id,inviter_id,expires_at) VALUES(?,?,?,?)',[$id,$targetId,(int)$actor->id,time()+604800]);
        $this->audit('group_invited',$id,0,(int)$actor->id,['user_id'=>$targetId]);
    }
    private function joinGroupMember(int $id,User $target,string $role='member'): void {
        $floor=$this->scalar('SELECT COALESCE(MAX(id),0) FROM messenger_messages WHERE conversation_id=?',[$id]);
        $this->execute('DELETE FROM messenger_group_members WHERE conversation_id=? AND user_id=?',[$id,(int)$target->id]);
        $this->execute('INSERT INTO messenger_group_members(conversation_id,user_id,joined_after_id,joined_at,active) VALUES(?,?,?,?,1)',[$id,(int)$target->id,$floor,time()]);
        $this->execute("INSERT INTO messenger_participants(conversation_id,user_id,role,last_read_message_id,archived_at,muted_until,hidden_before,email_mode,created_at,hidden_through_message_id) VALUES(?,?,?,?,NULL,NULL,NULL,'off',?,?)",[$id,(int)$target->id,$role,$floor,$this->now(),$floor]);
    }
    public function groupInvitations(User $actor): array {
        $this->requireGroups($actor);$q=$this->wire('database')->prepare("SELECT g.conversation_id,g.title,i.inviter_id,i.expires_at FROM messenger_groups g JOIN messenger_group_invites i ON i.conversation_id=g.conversation_id JOIN messenger_conversations c ON c.id=g.conversation_id WHERE i.user_id=? AND i.expires_at>? AND c.state='active' ORDER BY i.expires_at DESC LIMIT 100");$q->execute([(int)$actor->id,time()]);$items=[];
        foreach($q->fetchAll(\PDO::FETCH_ASSOC)as$row){$inviter=$this->wire('users')->get((int)$row['inviter_id']);if(!$inviter instanceof User)continue;try{$this->groupPolicy($inviter,$actor,(int)$row['conversation_id']);}catch(WirePermissionException $e){continue;}
            if(!$this->groupActor($inviter,fn()=>$this->canUse($inviter))||!$this->scalar("SELECT COUNT(*) FROM messenger_participants WHERE conversation_id=? AND user_id=? AND role IN ('owner','admin')",[(int)$row['conversation_id'],(int)$inviter->id]))continue;
            $items[]=['id'=>(int)$row['conversation_id'],'title'=>$this->decryptValue($row['title'],'Messenger|group-title|'.$row['conversation_id']),'inviter'=>['id'=>(int)$inviter->id,'name'=>(string)$inviter->name],'expires_at'=>(int)$row['expires_at']];}
        return $items;
    }
    public function respondGroupInvitation(int $id,User $actor,bool $accept): array {
        $this->requireGroups($actor);
        return $this->atomic(function()use($id,$actor,$accept):array{
            $db=$this->wire('database');$q=$db->prepare("SELECT c.id FROM messenger_conversations c WHERE c.id=? AND c.type='group' AND c.state='active'".$this->forUpdate($db));$q->execute([$id]);if(!$q->fetchColumn())throw new WirePermissionException('Invitation unavailable.');
            $q=$db->prepare('SELECT inviter_id FROM messenger_group_invites WHERE conversation_id=? AND user_id=? AND expires_at>?');$q->execute([$id,(int)$actor->id,time()]);$inviterId=(int)$q->fetchColumn();if(!$inviterId)throw new WirePermissionException('Invitation unavailable.');
            if($accept){$inviter=$this->wire('users')->get($inviterId);if(!$inviter instanceof User)throw new WirePermissionException('Invitation unavailable.');$this->groupActor($inviter,fn()=>$this->groupLock($id,$inviter,true));$this->groupPolicy($inviter,$actor,$id);
                if($this->scalar('SELECT COUNT(*) FROM messenger_participants WHERE conversation_id=?',[$id])>=100)throw new WirePermissionException('Group member limit reached.');
                $this->joinGroupMember($id,$actor);$this->audit('group_joined',$id,0,(int)$actor->id);}
            $this->execute('DELETE FROM messenger_group_invites WHERE conversation_id=? AND user_id=?',[$id,(int)$actor->id]);
            return $accept?$this->groupDetails($id,$actor):['declined'=>true];
        });
    }
    public function groupDetails(int $id,User $actor): array {
        $row=$this->groupLock($id,$actor);$chat=$this->conversation($id,$actor);$members=[];
        $q=$this->wire('database')->prepare('SELECT p.user_id,p.role,gm.joined_at FROM messenger_participants p JOIN messenger_group_members gm ON gm.conversation_id=p.conversation_id AND gm.user_id=p.user_id AND gm.active=1 WHERE p.conversation_id=? ORDER BY p.user_id LIMIT 100');$q->execute([$id]);
        foreach($q->fetchAll(\PDO::FETCH_ASSOC)as$member){$u=$this->wire('users')->get((int)$member['user_id']);$members[]=['id'=>(int)$u->id,'name'=>(string)$u->name,'title'=>(string)($u->get('display_name')?:$u->get('title')?:$u->name),'role'=>$member['role'],'joined_at'=>(int)$member['joined_at']];}
        $chat['members']=$members;$chat['role']=$row['role'];$chat['owner_id']=(int)$row['owner_id'];$chat['version']=(int)$row['version'];return $chat;
    }
    public function changeGroup(int $id,User $actor,string $action,array $body): array {
        $this->requireGroups($actor);
        return $this->atomic(function()use($id,$actor,$action,$body):array{
            $this->groupLinkActorLock($actor);$g=$this->groupLock($id,$actor,$action!=='leave');$owner=(int)$g['owner_id']===(int)$actor->id;
            if($action==='rename'){$title=$this->groupTitle($body['title']??'');$this->execute('UPDATE messenger_groups SET title=? WHERE conversation_id=?',[$this->encryptValue($title,'Messenger|group-title|'.$id),$id]);}
            elseif($action==='invite'){$ids=$this->groupIds($body['user_ids']??[],$actor);if(!$ids)throw new \InvalidArgumentException('Members required.');foreach($ids as$target)$this->inviteGroupMember($id,$actor,$target);}
            elseif($action==='remove'||$action==='leave'){
                $target=$action==='leave'?(int)$actor->id:($body['user_id']??0);if(!is_int($target)||$target<1)throw new \InvalidArgumentException('Member required.');
                if($target===(int)$g['owner_id'])throw new WirePermissionException('Transfer ownership or delete the group first.');
                $q=$this->wire('database')->prepare('SELECT role FROM messenger_participants WHERE conversation_id=? AND user_id=?');$q->execute([$id,$target]);$role=$q->fetchColumn();
                if($action==='remove'&&$role==='admin'&&!$owner)throw new WirePermissionException('Only the owner may remove an administrator.');
                $this->execute('DELETE FROM messenger_participants WHERE conversation_id=? AND user_id=?',[$id,$target]);$this->execute('UPDATE messenger_group_members SET active=0 WHERE conversation_id=? AND user_id=?',[$id,$target]);$this->execute('DELETE FROM messenger_group_invites WHERE conversation_id=? AND user_id=?',[$id,$target]);
            }
            elseif($action==='role'||$action==='transfer'){
                if(!$owner)throw new WirePermissionException('Owner required.');$target=$body['user_id']??0;$role=$action==='transfer'?'owner':($body['role']??'');
                if(!is_int($target)||$target<1||$target===(int)$actor->id||!in_array($role,['owner','admin','member'],true)||($action==='role'&&$role==='owner')||!$this->scalar('SELECT COUNT(*) FROM messenger_participants WHERE conversation_id=? AND user_id=?',[$id,$target]))throw new \InvalidArgumentException('Invalid member role.');
                $this->execute('UPDATE messenger_participants SET role=? WHERE conversation_id=? AND user_id=?',[$role,$id,$target]);
                if($action==='transfer'&&(!($this->wire('users')->get($target) instanceof User)||!$this->groupActor($this->wire('users')->get($target),fn()=>$this->canUse($this->wire('users')->get($target)))))throw new WirePermissionException('Member unavailable.');
                if($action==='transfer'){$this->execute("UPDATE messenger_participants SET role='admin' WHERE conversation_id=? AND user_id=?",[$id,(int)$actor->id]);$this->execute('UPDATE messenger_groups SET owner_id=? WHERE conversation_id=?',[$target,$id]);}
            }
            elseif($action==='delete') {if(!$owner)throw new WirePermissionException('Owner required.');$this->execute("UPDATE messenger_conversations SET state='deleted' WHERE id=?",[$id]);$this->execute('DELETE FROM messenger_group_invites WHERE conversation_id=?',[$id]);}
            elseif($action==='avatar'){
                $input=$body['avatar']??null;$payload=null;
                if($input!==null){if(!is_array($input)||!is_string($input['data_base64']??null)||!is_string($input['filename']??null)||!is_string($input['mime_type']??null)||($input['kind']??'file')!=='file'||strlen($input['data_base64'])>699052)throw new \InvalidArgumentException('Group photo is too large.');$a=$this->validateAttachment($input);$bytes=base64_decode($a['data_base64'],true);if(strlen($bytes)>524288)throw new \InvalidArgumentException('Group photo is too large.');if(!in_array($a['mime_type'],['image/jpeg','image/png','image/webp'],true))throw new \InvalidArgumentException('Unsupported group photo.');$size=@getimagesizefromstring($bytes);if(!$size||$size[0]>1024||$size[1]>1024)throw new \InvalidArgumentException('Choose a JPEG, PNG or WebP group photo up to 1024 pixels.');$payload=$this->encryptValue(json_encode($a,JSON_THROW_ON_ERROR),'Messenger|group-avatar|'.$id);}
                $this->execute('UPDATE messenger_groups SET avatar=?,avatar_version=avatar_version+1 WHERE conversation_id=?',[$payload,$id]);
            } else throw new \InvalidArgumentException('Invalid group action.');
            if(in_array($action,['delete','transfer'],true))$this->invalidateGroupLinks($id);
            elseif(in_array($action,['remove','role','leave'],true))$this->invalidateGroupLinks($id,$action==='leave'?(int)$actor->id:(int)$body['user_id']);
            $this->execute('UPDATE messenger_groups SET version=version+1 WHERE conversation_id=?',[$id]);$this->execute('UPDATE messenger_conversations SET updated_at=? WHERE id=?',[$this->now(),$id]);$this->audit('group_'.$action,$id,0,(int)$actor->id);
            return in_array($action,['delete','leave'],true)?['updated'=>true]:$this->groupDetails($id,$actor);
        });
    }
    public function groupAvatar(int $id,User $actor): ?array {
        $g=$this->groupLock($id,$actor);return $g['avatar']===null?null:json_decode($this->decryptValue($g['avatar'],'Messenger|group-avatar|'.$id),true,32,JSON_THROW_ON_ERROR);
    }
    private function groupCanSend(int $id,User $actor): bool {
        if(!$this->groupsReady())return false;
        return $this->scalar('SELECT COUNT(*) FROM messenger_participants p JOIN messenger_blocks b ON (b.blocker_user_id=p.user_id AND b.blocked_user_id=?) OR (b.blocked_user_id=p.user_id AND b.blocker_user_id=?) WHERE p.conversation_id=? AND p.user_id<>?',[(int)$actor->id,(int)$actor->id,$id,(int)$actor->id])===0;
    }
    private function decorateGroup(array $row,User $actor): array {
        if(!$this->groupsReady()||$row['state']!=='active')return [];
        $q=$this->wire('database')->prepare('SELECT g.title,g.owner_id,(g.avatar IS NOT NULL) AS has_avatar,g.version,g.avatar_version,p.role,gm.joined_after_id FROM messenger_groups g JOIN messenger_group_members gm ON gm.conversation_id=g.conversation_id AND gm.user_id=? AND gm.active=1 JOIN messenger_participants p ON p.conversation_id=g.conversation_id AND p.user_id=gm.user_id WHERE g.conversation_id=?');$q->execute([(int)$actor->id,(int)$row['id']]);$g=$q->fetch(\PDO::FETCH_ASSOC);if(!$g)return [];
        foreach(['id','requester_id','recipient_id','last_message_id','last_read_message_id','unread_count']as$key)$row[$key]=(int)($row[$key]??0);
        $row['hidden_through_message_id']=max((int)($row['hidden_through_message_id']??0),(int)$g['joined_after_id']);$row['role']=$g['role'];$row['member_count']=$this->scalar('SELECT COUNT(*) FROM messenger_participants WHERE conversation_id=?',[$row['id']]);
        $row['other_user']=['id'=>0,'name'=>'','title'=>$this->decryptValue($g['title'],'Messenger|group-title|'.$row['id'])];$row['has_avatar']=(bool)$g['has_avatar'];$row['avatar_version']=(int)$g['avatar_version'];$row['requester_id']=(int)$g['owner_id'];$row['group_version']=(int)$g['version'];$row['mentions_only']=$this->groupMentionsOnly((int)$row['id'],(int)$actor->id);
        $row['blocked']=false;$row['blocked_by_actor']=false;$row['blocked_by_other']=false;$row['can_send']=$this->groupCanSend($row['id'],$actor);$row['can_attach']=$row['can_send'];$row['other_last_read_message_id']=0;$row['client_policy']=$this->effectiveClientPolicy();
        $row['auto_delete_seconds']=(int)($row['auto_delete_seconds']??0);$row['auto_delete_at']=isset($row['auto_delete_at'])?(int)$row['auto_delete_at']:null;$row['muted_until_epoch']=$row['muted_until']?strtotime($row['muted_until']):null;$row['muted']=$row['muted_until_epoch']!==null&&$row['muted_until_epoch']>time();
        $row['sort_at']=(string)($row['last_message_at']?:$row['created_at']);$row['sort_at_iso']=date('c',strtotime($row['sort_at']));$row['message_revision']=$this->scalar("SELECT COALESCE(MAX(id),0) FROM messenger_audit WHERE conversation_id=? AND (action IN ('conversation_read','message_edited','message_deleted','message_reacted','message_pinned_shared','message_expiry_changed','message_expired','poll_created','poll_voted','poll_closed','group_remove','group_leave','group_rename','group_role','group_transfer','group_avatar','group_delete') OR (actor_user_id=? AND action IN ('message_hidden','conversation_deleted_for_user','message_pinned_personal')))",[$row['id'],(int)$actor->id]);
        $items=$this->groupLastMessage($row['id'],$actor,$row['hidden_through_message_id']);if($items)$row['last_message']=$items;$row['last_mention']=$row['mentions_only']?$this->groupMentionPreview($row['id'],$actor,$row['hidden_through_message_id'],$row['last_read_message_id']):null;
        unset($row['direct_key']);return $row;
    }
    private function groupLastMessage(int $id,User $actor,int $floor): ?array {
        $q=$this->wire('database')->prepare("SELECT m.id,m.conversation_id,m.sender_user_id,m.client_id,m.body,m.created_at,m.deleted_at,m.expires_at FROM messenger_messages m JOIN messenger_participants p ON p.conversation_id=m.conversation_id AND p.user_id=? JOIN messenger_group_members gm ON gm.conversation_id=p.conversation_id AND gm.user_id=p.user_id AND gm.active=1 JOIN messenger_conversations c ON c.id=m.conversation_id AND c.type='group' AND c.state='active' WHERE m.conversation_id=? AND m.moderation_state='visible' AND m.id>? AND m.id>p.hidden_through_message_id AND m.id>gm.joined_after_id AND NOT EXISTS(SELECT 1 FROM messenger_message_hides h WHERE h.message_id=m.id AND h.user_id=p.user_id) ORDER BY m.id DESC LIMIT 1");$q->execute([(int)$actor->id,$id,$floor]);$row=$q->fetch(\PDO::FETCH_ASSOC);return $row?$this->groupPreviewRow($row,$id,$actor):null;
    }
    private function groupPreviewRow(array $row,int $id,User $actor):array {
        $row=$this->applyExpiry($row);$row['body']=$row['deleted_at']?'':mb_substr($this->decryptMessageRow($row),0,160);foreach(['id','conversation_id','sender_user_id']as$key)$row[$key]=(int)$row[$key];$sender=$this->wire('users')->get($row['sender_user_id']);$row['sender']=['id'=>(int)$sender->id,'name'=>(string)$sender->name,'title'=>(string)($sender->get('display_name')?:$sender->get('title')?:$sender->name)];$row+=$this->groupReceipt($row,$this->groupReaders($id));unset($row['client_id']);return $this->mentionsPresentation($this->silentPresentation([$row]),$actor)[0];
    }
}
