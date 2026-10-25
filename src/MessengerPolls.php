<?php namespace ProcessWire;

/** Single-choice anonymous group polls. Options and individual choices stay encrypted. */
trait MessengerPolls {
    private ?bool $pollTablePresent=null;
    public function pollsReady():bool {
        if(!$this->groupsReady())return false;
        try { $db=$this->wire('database');foreach(['messenger_polls'=>'message_id,conversation_id,creator_id,options,closed_at','messenger_poll_votes'=>'message_id,user_id,choice','messenger_poll_locks'=>'user_id,window_started_at,event_count']as$t=>$c)$db->query('SELECT '.$c.' FROM '.$t.' LIMIT 0');$this->pollTablePresent=true;return true; }
        catch(\PDOException $e){return false;}
    }
    /** Explicit operator migration. Discovery/member reads never create schema. */
    public function installGroupPolls(User $actor):void {
        if(!$this->canAdmin($actor))throw new WirePermissionException('Administrative access required.');
        $db=$this->wire('database');$tail=$db->getAttribute(\PDO::ATTR_DRIVER_NAME)==='mysql'?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci':'';
        $db->exec('CREATE TABLE IF NOT EXISTS messenger_polls (message_id BIGINT PRIMARY KEY,conversation_id BIGINT NOT NULL,creator_id BIGINT NOT NULL,options TEXT NOT NULL,closed_at BIGINT NULL)'.$tail);
        $db->exec('CREATE TABLE IF NOT EXISTS messenger_poll_votes (message_id BIGINT NOT NULL,user_id BIGINT NOT NULL,choice TEXT NOT NULL,PRIMARY KEY(message_id,user_id))'.$tail);
        $this->pollTablePresent=true;
        $this->installMemberRateTable('messenger_poll_locks');
    }
    private function requirePolls(User $actor):void {$this->assertActor($actor);if(!$this->pollsReady())throw new WirePermissionException('Group polls unavailable.');}
    private function pollActorLock(User $actor):void {
        $db=$this->wire('database');$db->prepare(($db->getAttribute(\PDO::ATTR_DRIVER_NAME)==='mysql'?'INSERT IGNORE':'INSERT OR IGNORE').' INTO messenger_poll_locks(user_id) VALUES(?)')->execute([(int)$actor->id]);
        $q=$db->prepare('SELECT user_id FROM messenger_poll_locks WHERE user_id=?'.$this->forUpdate($db));$q->execute([(int)$actor->id]);$q->fetchColumn();
    }
    private function pollInput(string $question,array $options):array {
        $question=trim($question);if(!mb_check_encoding($question,'UTF-8')||mb_strlen($question)<1||mb_strlen($question)>240||preg_match('/[\x00-\x1F\x7F]/u',$question)||count($options)<2||count($options)>8||!array_is_list($options))throw new \InvalidArgumentException('Use a question and 2 to 8 options.');
        $clean=[];foreach($options as$option){if(!is_string($option))throw new \InvalidArgumentException('Invalid poll option.');$option=trim($option);if(!mb_check_encoding($option,'UTF-8')||mb_strlen($option)<1||mb_strlen($option)>100||preg_match('/[\x00-\x1F\x7F]/u',$option))throw new \InvalidArgumentException('Use options of 1 to 100 characters.');$clean[]=$option;}
        if(count(array_unique(array_map(static fn($s)=>mb_strtolower($s,'UTF-8'),$clean)))!==count($clean))throw new \InvalidArgumentException('Poll options must differ.');return [$question,$clean];
    }
    private function pollBody(string $question,array $options):string {$lines=[$question];foreach($options as$i=>$option)$lines[]=($i+1).'. '.$option;return implode("\n",$lines);}
    private function pollRow(int $messageId,bool $lock=false):?array {$q=$this->wire('database')->prepare('SELECT * FROM messenger_polls WHERE message_id=?'.($lock?$this->forUpdate($this->wire('database')):''));$q->execute([$messageId]);return $q->fetch(\PDO::FETCH_ASSOC)?:null;}
    private function messageIsPoll(int $messageId):bool {
        if($this->pollTablePresent===false)return false;
        try{$row=$this->pollRow($messageId);$this->pollTablePresent=true;return $row!==null;}catch(\PDOException $e){if((string)$e->getCode()==='42S02'||(int)($e->errorInfo[1]??0)===1146||str_contains($e->getMessage(),'no such table: messenger_polls')){$this->pollTablePresent=false;return false;}throw $e;}
    }
    /** Keep only the immutable operation tombstone after content erasure. */
    private function erasePollContent(int $messageId):void {
        if(!$this->messageIsPoll($messageId))return;
        $db=$this->wire('database');$db->prepare('UPDATE messenger_polls SET options=\'\',closed_at=COALESCE(closed_at,?) WHERE message_id=?')->execute([time(),$messageId]);
        try{$db->prepare('DELETE FROM messenger_poll_votes WHERE message_id=?')->execute([$messageId]);}catch(\PDOException $e){if((string)$e->getCode()!=='42S02'&&(int)($e->errorInfo[1]??0)!==1146&&!str_contains($e->getMessage(),'no such table: messenger_poll_votes'))throw $e;}
    }
    private function pollOptions(array $row):array {
        $options=json_decode($this->decryptValue((string)$row['options'],'Messenger|poll|'.$row['message_id']),true,16,JSON_THROW_ON_ERROR);
        if(!is_array($options)||!array_is_list($options)||count($options)<2||count($options)>8)throw new WireException('Poll unavailable.');return $options;
    }
    private function pollPresentation(array $row,User $actor,?array $votes=null):array {
        $options=$this->pollOptions($row);$counts=array_fill(0,count($options),0);$own=null;
        if($votes===null){$q=$this->wire('database')->prepare('SELECT user_id,choice FROM messenger_poll_votes WHERE message_id=? LIMIT 101'.($this->wire('database')->inTransaction()?$this->forUpdate($this->wire('database')):''));$q->execute([(int)$row['message_id']]);$votes=$q->fetchAll(\PDO::FETCH_ASSOC);}
        if(count($votes)>100)throw new WireException('Poll unavailable.');
        foreach($votes as$v){$choice=$this->decryptValue((string)$v['choice'],'Messenger|poll-vote|'.$row['message_id'].'|'.$v['user_id']);if(!ctype_digit($choice)||!array_key_exists((int)$choice,$counts))throw new WireException('Poll unavailable.');$counts[(int)$choice]++;if((int)$v['user_id']===(int)$actor->id)$own=(int)$choice;}
        return ['message_id'=>(int)$row['message_id'],'options'=>$options,'counts'=>$counts,'total'=>array_sum($counts),'own_choice'=>$own,'closed_at'=>$row['closed_at']===null?null:(int)$row['closed_at'],'created_by'=>(int)$row['creator_id'],'anonymous'=>true];
    }
    /** Input messages have already passed all history/visibility checks. No voter identities. */
    private function pollsPresentation(array $messages,User $actor):array {
        if(!$messages||!$this->pollsReady())return $messages;
        $ids=array_map(static fn($m)=>(int)$m['id'],$messages);$q=$this->wire('database')->prepare('SELECT * FROM messenger_polls WHERE message_id IN ('.implode(',',array_fill(0,count($ids),'?')).')'.($this->wire('database')->inTransaction()?$this->forUpdate($this->wire('database')):''));$q->execute($ids);$rows=[];foreach($q->fetchAll(\PDO::FETCH_ASSOC)as$r)$rows[(int)$r['message_id']]=$r;
        $votes=[];if($rows){$keys=array_keys($rows);$v=$this->wire('database')->prepare('SELECT message_id,user_id,choice FROM messenger_poll_votes WHERE message_id IN ('.implode(',',array_fill(0,count($keys),'?')).') LIMIT '.(count($keys)*100+1).($this->wire('database')->inTransaction()?$this->forUpdate($this->wire('database')):''));$v->execute($keys);foreach($v->fetchAll(\PDO::FETCH_ASSOC)as$r)$votes[(int)$r['message_id']][]=$r;}
        foreach($messages as&$m)if(empty($m['deleted_at'])&&isset($rows[(int)$m['id']])){$m['poll']=$this->pollPresentation($rows[(int)$m['id']],$actor,$votes[(int)$m['id']]??[]);$m['can_edit']=false;}return $messages;
    }
    private function authorizedPoll(int $id,User $actor):array {
        if($id<1||$id>=PHP_INT_MAX)throw new \InvalidArgumentException('Poll message ID required.');
        $row=$this->pollRow($id);if(!$row)throw new WirePermissionException('Poll unavailable.');
        $this->groupLock((int)$row['conversation_id'],$actor);$row=$this->pollRow($id,true);if(!$row)throw new WirePermissionException('Poll unavailable.');
        $m=$this->conversationMessages((int)$row['conversation_id'],$actor,$id+1,$id-1,1)[0]??null;
        if(!$m||(int)$m['id']!==$id||!empty($m['deleted_at']))throw new WirePermissionException('Poll unavailable.');return [$row,$m];
    }
    public function groupPoll(User $actor,int $messageId):array {$this->requirePolls($actor);return $this->atomic(function()use($actor,$messageId){[$row]=$this->authorizedPoll($messageId,$actor);return $this->pollPresentation($row,$actor);});}
    public function createGroupPoll(User $actor,int $id,string $question,array $options,string $clientId):array {
        $this->requirePolls($actor);[$question,$options]=$this->pollInput($question,$options);if(!preg_match('/^[A-Za-z0-9_-]{16,64}$/D',$clientId))throw new \InvalidArgumentException('Immutable poll operation ID required.');
        return $this->atomic(function()use($actor,$id,$question,$options,$clientId):array{
            $this->pollActorLock($actor);$this->groupLock($id,$actor);$db=$this->wire('database');
            $q=$db->prepare('SELECT id,conversation_id FROM messenger_messages WHERE sender_user_id=? AND client_id=?');$q->execute([(int)$actor->id,$clientId]);$old=$q->fetch(\PDO::FETCH_ASSOC);
            if($old&&!$this->pollRow((int)$old['id']))throw new MessengerMobileException('idempotency_conflict','Operation belongs to another message.',409);
            $body=$this->pollBody($question,$options);$message=$this->sendMessage($id,$actor,$body,$clientId);
            if(!$old){$this->consumeMemberRate($actor,'messenger_poll_locks');$db->prepare('INSERT INTO messenger_polls(message_id,conversation_id,creator_id,options,closed_at) VALUES(?,?,?,?,NULL)')->execute([(int)$message['id'],$id,(int)$actor->id,$this->encryptValue(json_encode($options,JSON_THROW_ON_ERROR),'Messenger|poll|'.$message['id'])]);$this->audit('poll_created',$id,(int)$message['id'],(int)$actor->id);}
            $visible=$this->conversationMessages($id,$actor,(int)$message['id']+1,(int)$message['id']-1,1)[0]??null;
            $poll=$visible&&empty($visible['deleted_at'])?($visible['poll']??null):null;
            return ['message_id'=>(int)$message['id'],'conversation_id'=>$id,'accepted_client_id'=>$clientId,'ack_visible'=>$poll!==null,'poll'=>$poll];
        });
    }
    public function voteGroupPoll(User $actor,int $messageId,?int $choice):array {
        $this->requirePolls($actor);
        return $this->atomic(function()use($actor,$messageId,$choice):array{
            $this->pollActorLock($actor);[$row]=$this->authorizedPoll($messageId,$actor);$id=(int)$row['conversation_id'];$chat=$this->conversation($id,$actor);
            if(empty($chat['can_send'])||!empty($chat['blocked']))throw new WirePermissionException('Poll unavailable.');
            $view=$this->pollPresentation($row,$actor);if($choice!==null&&($choice<0||$choice>=count($view['options'])))throw new \InvalidArgumentException('Choose an existing option.');
            if($view['own_choice']===$choice)return $view;
            if($row['closed_at']!==null)throw new WirePermissionException('Poll is closed.');$this->consumeMemberRate($actor,'messenger_poll_locks');
            $db=$this->wire('database');$db->prepare('DELETE FROM messenger_poll_votes WHERE message_id=? AND user_id=?')->execute([$messageId,(int)$actor->id]);
            if($choice!==null)$db->prepare('INSERT INTO messenger_poll_votes(message_id,user_id,choice) VALUES(?,?,?)')->execute([$messageId,(int)$actor->id,$this->encryptValue((string)$choice,'Messenger|poll-vote|'.$messageId.'|'.(int)$actor->id)]);
            $this->audit('poll_voted',$id,$messageId,(int)$actor->id);
            return $this->pollPresentation($row,$actor);
        });
    }
    public function closeGroupPoll(User $actor,int $messageId):array {
        $this->requirePolls($actor);return $this->atomic(function()use($actor,$messageId):array{
            $this->pollActorLock($actor);[$row]=$this->authorizedPoll($messageId,$actor);$group=$this->groupLock((int)$row['conversation_id'],$actor);
            if((int)$row['creator_id']!==(int)$actor->id&&!in_array($group['role'],['owner','admin'],true))throw new WirePermissionException('Only the author or an administrator can close this poll.');
            if($row['closed_at']===null){$this->consumeMemberRate($actor,'messenger_poll_locks');$row['closed_at']=time();$this->wire('database')->prepare('UPDATE messenger_polls SET closed_at=? WHERE message_id=?')->execute([$row['closed_at'],$messageId]);$this->audit('poll_closed',(int)$row['conversation_id'],$messageId,(int)$actor->id);}
            return $this->pollPresentation($row,$actor);
        });
    }
}
