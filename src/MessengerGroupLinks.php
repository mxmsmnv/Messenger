<?php namespace ProcessWire;

/** Authenticated, bounded group grants. Tokens are encrypted at rest and never audited. */
trait MessengerGroupLinks {
    private ?bool $groupLinkTablePresent=null;
    private ?bool $groupLinkRatePresent=null;
    public function groupMessagesPath():?string {
        $path=(string)$this->public_path;$decoded=rawurldecode($path);
        if(strlen($path)>512||!str_starts_with($path,'/')||str_starts_with($path,'//')||!str_starts_with($decoded,'/')||str_starts_with($decoded,'//')||preg_match('~[\x00-\x20\x7f\\\\?#]~',$decoded)||preg_match('~(?:^|/)\.{1,2}(?:/|$)~',$decoded)||!mb_check_encoding($decoded,'UTF-8'))return null;
        return $path;
    }
    public function groupLinksReady():bool {
        if(!$this->groupsReady()||$this->groupMessagesPath()===null)return false;
        try{$db=$this->wire('database');foreach(['messenger_group_links'=>'id,conversation_id,created_by,client_id,request_hash,token_hash,token_cipher,created_at,expires_at,max_uses,use_count,revoked_at','messenger_group_link_uses'=>'link_id,user_id,joined_at','messenger_group_link_locks'=>'user_id,window_started_at,event_count']as$t=>$columns)$db->query('SELECT '.$columns.' FROM '.$t.' LIMIT 0');$this->groupLinkTablePresent=true;$this->groupLinkRatePresent=true;return true;}
        catch(\PDOException $e){return false;}
    }
    /** Explicit administrator action; no discovery/member-side DDL. */
    public function installGroupLinks(User $actor):void {
        if(!$this->canAdmin($actor))throw new WirePermissionException('Administrative access required.');
        $db=$this->wire('database');$mysql=$db->getAttribute(\PDO::ATTR_DRIVER_NAME)==='mysql';$tail=$mysql?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci':'';$id=$mysql?'BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT':'INTEGER PRIMARY KEY AUTOINCREMENT';$client=$mysql?'VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin':'VARCHAR(64) COLLATE BINARY';
        $db->exec('CREATE TABLE IF NOT EXISTS messenger_group_links (id '.$id.',conversation_id BIGINT NOT NULL,created_by BIGINT NOT NULL,client_id '.$client.' NOT NULL,request_hash CHAR(64) NOT NULL,token_hash CHAR(64) NOT NULL UNIQUE,token_cipher TEXT NOT NULL,created_at BIGINT NOT NULL,expires_at BIGINT NOT NULL,max_uses INTEGER NOT NULL,use_count INTEGER NOT NULL,revoked_at BIGINT NULL,UNIQUE(created_by,client_id)'.($mysql?',INDEX group_current(conversation_id,id)':'').')'.$tail);$this->groupLinkTablePresent=true;
        if(!$mysql)$db->exec('CREATE INDEX IF NOT EXISTS messenger_group_links_group ON messenger_group_links(conversation_id,id)');
        $db->exec('CREATE TABLE IF NOT EXISTS messenger_group_link_uses (link_id BIGINT NOT NULL,user_id BIGINT NOT NULL,joined_at BIGINT NOT NULL,PRIMARY KEY(link_id,user_id))'.$tail);$this->installMemberRateTable('messenger_group_link_locks');$this->groupLinkRatePresent=true;
    }
    private function requireGroupLinks(User $actor):void {$this->assertActor($actor);if(!$this->groupLinksReady())throw new WirePermissionException('Invitation links unavailable.');}
    private function groupLinkRateReady():bool {
        if($this->groupLinkRatePresent!==null)return $this->groupLinkRatePresent;
        try{$this->wire('database')->query('SELECT user_id FROM messenger_group_link_locks LIMIT 0');return $this->groupLinkRatePresent=true;}
        catch(\PDOException $e){if((string)$e->getCode()==='42S02'||(int)($e->errorInfo[1]??0)===1146||str_contains($e->getMessage(),'no such table: messenger_group_link_locks'))return $this->groupLinkRatePresent=false;throw $e;}
    }
    private function groupLinkActorLock(User $actor):void {$this->groupLinkActorLocks([(int)$actor->id]);}
    private function groupLinkActorLocks(array $ids):void {
        if(!$this->groupLinkRateReady())return;
        $ids=array_values(array_unique($ids));sort($ids,SORT_NUMERIC);$db=$this->wire('database');
        foreach($ids as$id){if(!is_int($id)||$id<1)throw new WireException('Invitation lock unavailable.');$db->prepare(($db->getAttribute(\PDO::ATTR_DRIVER_NAME)==='mysql'?'INSERT IGNORE':'INSERT OR IGNORE').' INTO messenger_group_link_locks(user_id) VALUES(?)')->execute([$id]);$q=$db->prepare('SELECT user_id FROM messenger_group_link_locks WHERE user_id=?'.$this->forUpdate($db));$q->execute([$id]);$q->fetchColumn();}
    }
    private function groupLinkHash(string $token):string {if(!preg_match('/^[A-Za-z0-9_-]{43}$/D',$token))throw new WirePermissionException('Invitation link unavailable.');return hash('sha256','Messenger|group-link|'.$token);}
    private function groupLinkIssuer(array $row):?User {
        $issuer=$this->wire('users')->get((int)$row['created_by']);if(!$issuer instanceof User)return null;
        try{$this->groupActor($issuer,fn()=>$this->groupLock((int)$row['conversation_id'],$issuer,true));}catch(WirePermissionException $e){return null;}return $issuer;
    }
    private function groupLinkActive(array $row):bool {return $row['revoked_at']===null&&(int)$row['expires_at']>time()&&(int)$row['use_count']<(int)$row['max_uses']&&$this->groupLinkIssuer($row)!==null;}
    private function groupLinkAdminView(?array $row):array {
        if(!$row)return ['link'=>null];$active=$this->groupLinkActive($row);$token=$active?$this->decryptValue((string)$row['token_cipher'],'Messenger|group-link|'.$row['id']):null;
        if($token!==null&&!hash_equals((string)$row['token_hash'],$this->groupLinkHash($token)))throw new WireException('Invitation link unavailable.');
        return ['link'=>['id'=>(int)$row['id'],'conversation_id'=>(int)$row['conversation_id'],'created_by'=>(int)$row['created_by'],'created_at'=>(int)$row['created_at'],'expires_at'=>(int)$row['expires_at'],'max_uses'=>(int)$row['max_uses'],'use_count'=>(int)$row['use_count'],'active'=>$active,'token'=>$token,'messages_path'=>$this->groupMessagesPath()]];
    }
    public function groupLinkStatus(User $actor,int $id):array {
        $this->requireGroupLinks($actor);return $this->atomic(function()use($actor,$id){$this->groupLock($id,$actor,true);$q=$this->wire('database')->prepare('SELECT * FROM messenger_group_links WHERE conversation_id=? ORDER BY id DESC LIMIT 1');$q->execute([$id]);return $this->groupLinkAdminView($q->fetch(\PDO::FETCH_ASSOC)?:null);});
    }
    public function createGroupLink(User $actor,int $id,int $seconds,int $maxUses,string $clientId):array {
        $this->requireGroupLinks($actor);if($seconds<3600||$seconds>2592000||$maxUses<1||$maxUses>100||!preg_match('/^[A-Za-z0-9_-]{16,64}$/D',$clientId))throw new \InvalidArgumentException('Choose a lifetime of one hour to 30 days and 1 to 100 uses.');
        return $this->atomic(function()use($actor,$id,$seconds,$maxUses,$clientId){
            $this->groupLinkActorLock($actor);$this->groupLock($id,$actor,true);$db=$this->wire('database');$hash=$this->contentFingerprint('group-link|'.json_encode([$id,$seconds,$maxUses],JSON_THROW_ON_ERROR));
            $q=$db->prepare('SELECT * FROM messenger_group_links WHERE created_by=? AND client_id=?');$q->execute([(int)$actor->id,$clientId]);$old=$q->fetch(\PDO::FETCH_ASSOC);
            if($old){if((int)$old['conversation_id']!==$id||!hash_equals((string)$old['request_hash'],$hash))throw new MessengerMobileException('idempotency_conflict','Invitation creation conflicts with an earlier operation.',409);return $this->groupLinkAdminView($old)+['accepted_client_id'=>$clientId];}
            $this->consumeMemberRate($actor,'messenger_group_link_locks');$this->invalidateGroupLinks($id);
            $token=rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=');$now=time();
            $db->prepare('INSERT INTO messenger_group_links(conversation_id,created_by,client_id,request_hash,token_hash,token_cipher,created_at,expires_at,max_uses,use_count,revoked_at) VALUES(?,?,?,?,?,\'\',?,?,?,0,NULL)')->execute([$id,(int)$actor->id,$clientId,$hash,$this->groupLinkHash($token),$now,$now+$seconds,$maxUses]);$linkId=(int)$db->lastInsertId();
            $db->prepare('UPDATE messenger_group_links SET token_cipher=? WHERE id=?')->execute([$this->encryptValue($token,'Messenger|group-link|'.$linkId),$linkId]);$this->audit('group_link_created',$id,0,(int)$actor->id,['link_id'=>$linkId]);
            $q=$db->prepare('SELECT * FROM messenger_group_links WHERE id=?');$q->execute([$linkId]);return $this->groupLinkAdminView($q->fetch(\PDO::FETCH_ASSOC))+['accepted_client_id'=>$clientId];
        });
    }
    /** Exact id prevents a stale revoke button from revoking a newer rotation. */
    public function revokeGroupLink(User $actor,int $id,int $linkId):array {
        $this->requireGroupLinks($actor);if($linkId<1)throw new \InvalidArgumentException('Invitation identity required.');
        return $this->atomic(function()use($actor,$id,$linkId){$this->groupLinkActorLock($actor);$this->groupLock($id,$actor,true);$db=$this->wire('database');$q=$db->prepare('SELECT revoked_at FROM messenger_group_links WHERE id=? AND conversation_id=?'.$this->forUpdate($db));$q->execute([$linkId,$id]);$row=$q->fetch(\PDO::FETCH_ASSOC);if(!$row)throw new WirePermissionException('Invitation link unavailable.');if($row['revoked_at']===null){$this->consumeMemberRate($actor,'messenger_group_link_locks');$db->prepare('UPDATE messenger_group_links SET revoked_at=?,token_cipher=\'\' WHERE id=?')->execute([time(),$linkId]);$this->audit('group_link_revoked',$id,0,(int)$actor->id,['link_id'=>$linkId]);}return ['id'=>$linkId,'conversation_id'=>$id,'revoked'=>true];});
    }
    /** Role/membership changes permanently revoke the issuer's earlier capabilities. */
    private function invalidateGroupLinks(int $id,?int $issuer=null):void {
        if($this->groupLinkTablePresent===false)return;
        try{$this->wire('database')->prepare('UPDATE messenger_group_links SET revoked_at=?,token_cipher=\'\' WHERE conversation_id=? AND revoked_at IS NULL'.($issuer===null?'':' AND created_by=?'))->execute($issuer===null?[time(),$id]:[time(),$id,$issuer]);$this->groupLinkTablePresent=true;}
        catch(\PDOException $e){if((string)$e->getCode()==='42S02'||(int)($e->errorInfo[1]??0)===1146||str_contains($e->getMessage(),'no such table: messenger_group_links')){$this->groupLinkTablePresent=false;return;}throw $e;}
    }
    /** Lookup outside the write transaction; it is only a hint, never authority. */
    private function groupLinkHint(string $token):array {
        $q=$this->wire('database')->prepare('SELECT conversation_id,created_by FROM messenger_group_links WHERE token_hash=?');$q->execute([$this->groupLinkHash($token)]);$row=$q->fetch(\PDO::FETCH_ASSOC);if(!$row)throw new WirePermissionException('Invitation link unavailable.');return $row;
    }
    private function lockedGroupLink(string $token,int $id):array {
        $db=$this->wire('database');$hash=$this->groupLinkHash($token);
        // No consistent read before the conversation lock: after a wait, capacity,
        // policy and quota must start from the committed current snapshot.

        $q=$db->prepare("SELECT id FROM messenger_conversations WHERE id=? AND type='group' AND state='active'".$this->forUpdate($db));$q->execute([$id]);if(!$q->fetchColumn())throw new WirePermissionException('Invitation link unavailable.');
        $q=$db->prepare('SELECT * FROM messenger_group_links WHERE token_hash=?'.$this->forUpdate($db));$q->execute([$hash]);$row=$q->fetch(\PDO::FETCH_ASSOC);if(!$row||(int)$row['conversation_id']!==$id)throw new WirePermissionException('Invitation link unavailable.');return $row;
    }
    private function currentGroupLinkMember(int $id,User $actor):bool {return (bool)$this->scalar('SELECT COUNT(*) FROM messenger_participants p JOIN messenger_group_members g ON g.conversation_id=p.conversation_id AND g.user_id=p.user_id AND g.active=1 WHERE p.conversation_id=? AND p.user_id=?',[$id,(int)$actor->id]);}
    private function assertGroupLinkTarget(array $row,User $actor):User {
        if(!$this->groupLinkActive($row))throw new WirePermissionException('Invitation link unavailable.');$issuer=$this->groupLinkIssuer($row);if(!$issuer)throw new WirePermissionException('Invitation link unavailable.');
        if($this->scalar('SELECT COUNT(*) FROM messenger_group_link_uses WHERE link_id=? AND user_id=?',[(int)$row['id'],(int)$actor->id]))throw new WirePermissionException('Invitation link unavailable.');$this->groupPolicy($issuer,$actor,(int)$row['conversation_id']);return $issuer;
    }
    private function groupLinkSpace(int $id,User $actor):bool {
        return $this->scalar('SELECT COUNT(*) FROM messenger_participants WHERE conversation_id=?',[$id])+$this->scalar('SELECT COUNT(*) FROM messenger_group_invites WHERE conversation_id=? AND user_id<>? AND expires_at>?',[$id,(int)$actor->id,time()])<100;
    }
    public function previewGroupLink(User $actor,string $token):array {
        $this->requireGroupLinks($actor);$hint=$this->groupLinkHint($token);return $this->atomic(function()use($actor,$token,$hint){$row=$this->lockedGroupLink($token,(int)$hint['conversation_id']);$id=(int)$row['conversation_id'];$member=$this->currentGroupLinkMember($id,$actor);if(!$member)$this->assertGroupLinkTarget($row,$actor);
            $q=$this->wire('database')->prepare('SELECT title FROM messenger_groups WHERE conversation_id=?');$q->execute([$id]);$title=$q->fetchColumn();if($title===false)throw new WirePermissionException('Invitation link unavailable.');
            return ['id'=>$id,'title'=>$this->decryptValue((string)$title,'Messenger|group-title|'.$id),'member_count'=>$this->scalar('SELECT COUNT(*) FROM messenger_participants WHERE conversation_id=?',[$id]),'expires_at'=>(int)$row['expires_at'],'remaining_uses'=>max(0,(int)$row['max_uses']-(int)$row['use_count']),'is_member'=>$member,'can_join'=>!$member&&$this->groupLinkSpace($id,$actor)];});
    }
    public function joinGroupLink(User $actor,string $token):array {
        $this->requireGroupLinks($actor);$hint=$this->groupLinkHint($token);return $this->atomic(function()use($actor,$token,$hint){$this->groupLinkActorLocks([(int)$actor->id,(int)$hint['created_by']]);$row=$this->lockedGroupLink($token,(int)$hint['conversation_id']);if((int)$row['created_by']!==(int)$hint['created_by'])throw new WirePermissionException('Invitation link unavailable.');$id=(int)$row['conversation_id'];if($this->currentGroupLinkMember($id,$actor))return $this->groupDetails($id,$actor);
            $issuer=$this->assertGroupLinkTarget($row,$actor);if(!$this->groupLinkSpace($id,$actor))throw new WirePermissionException('Group member limit reached.');$this->consumeMemberRate($actor,'messenger_group_link_locks');$this->assertGroupInviteRate($issuer);
            $this->joinGroupMember($id,$actor);$this->execute('DELETE FROM messenger_group_invites WHERE conversation_id=? AND user_id=?',[$id,(int)$actor->id]);$this->execute('INSERT INTO messenger_group_link_uses(link_id,user_id,joined_at) VALUES(?,?,?)',[(int)$row['id'],(int)$actor->id,time()]);$this->execute('UPDATE messenger_group_links SET use_count=use_count+1 WHERE id=?',[(int)$row['id']]);
            $this->audit('group_invited',$id,0,(int)$issuer->id,['user_id'=>(int)$actor->id]);$this->audit('group_joined',$id,0,(int)$actor->id);return $this->groupDetails($id,$actor);
        });
    }
    private function groupLinkRequest(User $actor,string $resource,string $method,array $body,array $query):array {
        if(!(bool)$this->wire('config')->https||!in_array(strtolower((string)($_SERVER['HTTPS']??'')),['on','1'],true))throw new WirePermissionException('HTTPS is required for invitation links.');
        if(in_array($resource,['group-link-preview','group-link-join'],true)&&$method==='POST'){if(!is_string($body['token']??null))throw new \InvalidArgumentException('Invitation link required.');return $resource==='group-link-preview'?$this->previewGroupLink($actor,$body['token']):$this->joinGroupLink($actor,$body['token']);}
        $id=$method==='GET'?($query['id']??null):($body['id']??null);if(is_string($id)&&preg_match('/^[1-9][0-9]{0,18}$/D',$id)&&(string)(int)$id===$id)$id=(int)$id;if(!is_int($id)||$id<1)throw new \InvalidArgumentException('Group required.');
        if($resource==='group-link-status'&&$method==='GET')return $this->groupLinkStatus($actor,$id);
        if($resource==='group-link-create'&&$method==='POST'){if(!is_int($body['expires_in']??null)||!is_int($body['max_uses']??null)||!is_string($body['client_id']??null))throw new \InvalidArgumentException('Link limits and operation identity required.');return $this->createGroupLink($actor,$id,$body['expires_in'],$body['max_uses'],$body['client_id']);}
        if($resource==='group-link-revoke'&&$method==='POST'){if(!is_int($body['link_id']??null))throw new \InvalidArgumentException('Invitation identity required.');return $this->revokeGroupLink($actor,$id,$body['link_id']);}throw new \InvalidArgumentException('Invitation action unavailable.');
    }
}
