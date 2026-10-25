<?php namespace ProcessWire;

/** Explicit group calendar entries. Encrypted content, own RSVP and opt-in reminders. */
trait MessengerGroupEvents {
    private ?bool $eventSchemaPresent=null;
    private function eventSchemaReady():bool {
        if($this->eventSchemaPresent!==null)return $this->eventSchemaPresent;
        $configured=false;
        try {
            foreach(['messenger_group_events'=>'message_id,conversation_id,creator_id,details,starts_at,version,cancelled_at','messenger_group_event_answers'=>'message_id,user_id,answer,reminder_minutes,event_version,revision,due_at,state,attempts,available_at','messenger_group_event_locks'=>'user_id,window_started_at,event_count']as$table=>$columns){$this->wire('database')->query('SELECT '.$columns.' FROM '.$table.' LIMIT 0');$configured=true;}
            return $this->eventSchemaPresent=true;
        }catch(\PDOException $e){if($configured)throw new WireException('Group event storage unavailable.',0,$e);if((string)$e->getCode()==='42S02'||(int)($e->errorInfo[1]??0)===1146||str_contains($e->getMessage(),'no such table: messenger_'))return $this->eventSchemaPresent=false;throw $e;}
    }
    public function groupEventsReady():bool {return $this->groupsReady()&&$this->eventSchemaReady();}
    public function installGroupEvents(User $actor):void {
        if(!$this->canAdmin($actor))throw new WirePermissionException('Administrative access required.');
        $db=$this->wire('database');$mysql=$db->getAttribute(\PDO::ATTR_DRIVER_NAME)==='mysql';$tail=$mysql?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci':'';
        $db->exec('CREATE TABLE IF NOT EXISTS messenger_group_events(message_id BIGINT PRIMARY KEY,conversation_id BIGINT NOT NULL,creator_id BIGINT NOT NULL,details TEXT NOT NULL,starts_at BIGINT NOT NULL,version BIGINT NOT NULL DEFAULT 1,cancelled_at BIGINT NULL)'.$tail);
        $db->exec("CREATE TABLE IF NOT EXISTS messenger_group_event_answers(message_id BIGINT NOT NULL,user_id BIGINT NOT NULL,answer TEXT NOT NULL,reminder_minutes INTEGER NULL,event_version BIGINT NOT NULL,revision BIGINT NOT NULL DEFAULT 1,due_at BIGINT NULL,state VARCHAR(16) NOT NULL DEFAULT 'none',attempts INTEGER NOT NULL DEFAULT 0,available_at BIGINT NOT NULL DEFAULT 0,PRIMARY KEY(message_id,user_id)".($mysql?',INDEX due_reminders(state,available_at,due_at)':'').')'.$tail);
        if(!$mysql)$db->exec('CREATE INDEX IF NOT EXISTS messenger_event_due ON messenger_group_event_answers(state,available_at,due_at)');
        $this->installMemberRateTable('messenger_group_event_locks');$this->eventSchemaPresent=true;
    }
    private function requireGroupEvents(User $actor):void {$this->assertActor($actor);if(!$this->groupEventsReady())throw new WirePermissionException('Group events unavailable.');}
    private function eventActorLock(User $actor):void {
        $db=$this->wire('database');$db->prepare(($db->getAttribute(\PDO::ATTR_DRIVER_NAME)==='mysql'?'INSERT IGNORE':'INSERT OR IGNORE').' INTO messenger_group_event_locks(user_id) VALUES(?)')->execute([(int)$actor->id]);
        $q=$db->prepare('SELECT user_id FROM messenger_group_event_locks WHERE user_id=?'.$this->forUpdate($db));$q->execute([(int)$actor->id]);$q->fetchColumn();
    }
    private function eventInput(array $details,bool $future=true):array {
        if(array_diff(array_keys($details),['title','description','location','starts_at','timezone','duration_minutes']))throw new \InvalidArgumentException('Unknown event field.');
        $clean=[];foreach(['title'=>160,'description'=>1000,'location'=>240]as$key=>$limit){$value=$details[$key]??'';if(!is_string($value)||!mb_check_encoding($value,'UTF-8'))throw new \InvalidArgumentException('Invalid event text.');$value=trim($value);if(mb_strlen($value)>$limit||($key==='title'&&$value==='')||preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u',$value)||($key!=='description'&&preg_match('/[\r\n\t]/u',$value)))throw new \InvalidArgumentException('Invalid event text.');$clean[$key]=$value;}
        $start=$details['starts_at']??null;$zone=$details['timezone']??null;$duration=$details['duration_minutes']??null;
        if(!is_int($start)||$start<1||($future&&($start<=time()||$start>time()+15552000))||!is_string($zone)||!in_array($zone,\DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC),true)||!is_int($duration)||$duration<5||$duration>10080)throw new \InvalidArgumentException('Choose a future time within180days, a timezone and duration5–10080minutes.');
        return $clean+['starts_at'=>$start,'timezone'=>$zone,'duration_minutes'=>$duration];
    }
    private function eventBody(array $d):string {return $d['title']."\n".gmdate('Y-m-d\TH:i:s\Z',$d['starts_at']).' · '.$d['timezone'].' · '.$d['duration_minutes'].' min'.($d['location']!==''?"\n".$d['location']:'').($d['description']!==''?"\n".$d['description']:'');}
    private function eventRow(int $mid,bool $lock=false):?array {$q=$this->wire('database')->prepare('SELECT * FROM messenger_group_events WHERE message_id=?'.($lock?$this->forUpdate($this->wire('database')):''));$q->execute([$mid]);return $q->fetch(\PDO::FETCH_ASSOC)?:null;}
    private function messageIsGroupEvent(int $mid):bool {return $this->eventSchemaReady()&&$this->eventRow($mid)!==null;}
    private function eraseGroupEvent(int $mid):void {
        if(!$this->messageIsGroupEvent($mid))return;$this->execute("UPDATE messenger_group_events SET details='',cancelled_at=COALESCE(cancelled_at,?) WHERE message_id=?",[time(),$mid]);$this->execute('DELETE FROM messenger_group_event_answers WHERE message_id=?',[$mid]);
    }
    private function eventDetails(array $row):array {$d=json_decode($this->decryptValue((string)$row['details'],'Messenger|group-event|'.$row['message_id']),true,16,JSON_THROW_ON_ERROR);return $this->eventInput($d,false);}
    private function eventView(array $row,User $actor,?array $answers=null):array {
        $details=$this->eventDetails($row);$counts=['yes'=>0,'maybe'=>0,'no'=>0];$own=null;$reminder=null;
        if($answers===null){$q=$this->wire('database')->prepare('SELECT a.user_id,a.answer,a.reminder_minutes FROM messenger_group_event_answers a JOIN messenger_group_events e ON e.message_id=a.message_id JOIN messenger_group_members gm ON gm.conversation_id=e.conversation_id AND gm.user_id=a.user_id AND gm.active=1 AND gm.joined_after_id<a.message_id WHERE a.message_id=? AND a.event_version=e.version LIMIT 101'.($this->wire('database')->inTransaction()?$this->forUpdate($this->wire('database')):''));$q->execute([(int)$row['message_id']]);$answers=$q->fetchAll(\PDO::FETCH_ASSOC);}if(count($answers)>100)throw new WireException('Group event unavailable.');
        foreach($answers as$a){$value=$this->decryptValue($a['answer'],'Messenger|event-answer|'.$row['message_id'].'|'.$a['user_id']);if($value==='none')continue;if(!isset($counts[$value]))throw new WireException('Group event unavailable.');$counts[$value]++;if((int)$a['user_id']===(int)$actor->id){$own=$value;$reminder=$a['reminder_minutes']===null?null:(int)$a['reminder_minutes'];}}
        return $details+['message_id'=>(int)$row['message_id'],'conversation_id'=>(int)$row['conversation_id'],'created_by'=>(int)$row['creator_id'],'version'=>(int)$row['version'],'cancelled_at'=>$row['cancelled_at']===null?null:(int)$row['cancelled_at'],'counts'=>$counts,'own_answer'=>$own,'reminder_minutes'=>$reminder];
    }
    private function groupEventsPresentation(array $messages,User $actor):array {
        if(!$messages||!$this->groupEventsReady())return $messages;
        $ids=array_map(static fn($m)=>(int)$m['id'],$messages);$db=$this->wire('database');$q=$db->prepare('SELECT * FROM messenger_group_events WHERE message_id IN ('.implode(',',array_fill(0,count($ids),'?')).')'.($db->inTransaction()?$this->forUpdate($db):''));$q->execute($ids);$rows=[];foreach($q->fetchAll(\PDO::FETCH_ASSOC)as$row)$rows[(int)$row['message_id']]=$row;
        if(!$rows)return $messages;$keys=array_keys($rows);$q=$db->prepare('SELECT a.message_id,a.user_id,a.answer,a.reminder_minutes FROM messenger_group_event_answers a JOIN messenger_group_events e ON e.message_id=a.message_id AND a.event_version=e.version JOIN messenger_group_members gm ON gm.conversation_id=e.conversation_id AND gm.user_id=a.user_id AND gm.active=1 AND gm.joined_after_id<a.message_id WHERE a.message_id IN ('.implode(',',array_fill(0,count($keys),'?')).') LIMIT '.(count($keys)*100+1).($db->inTransaction()?$this->forUpdate($db):''));$q->execute($keys);$answers=[];foreach($q->fetchAll(\PDO::FETCH_ASSOC)as$answer)$answers[(int)$answer['message_id']][]=$answer;
        foreach($messages as&$m)if(empty($m['deleted_at'])&&isset($rows[(int)$m['id']])){$m['event']=$this->eventView($rows[(int)$m['id']],$actor,$answers[(int)$m['id']]??[]);$m['can_edit']=false;}
        return $messages;
    }
    private function authorizedGroupEvent(int $mid,User $actor):array {
        if($mid<1||$mid>=PHP_INT_MAX)throw new \InvalidArgumentException('Event message ID required.');$row=$this->eventRow($mid);if(!$row)throw new WirePermissionException('Event unavailable.');
        $group=$this->groupLock((int)$row['conversation_id'],$actor);$row=$this->eventRow($mid,true);$messages=$this->conversationMessages((int)$row['conversation_id'],$actor,$mid+1,$mid-1,1);$m=$messages[0]??null;
        // A locking current read after the group lock protects visibility even when
        // an outer caller already established a repeatable-read snapshot.
        $live=$this->wire('database')->prepare("SELECT m.id FROM messenger_messages m JOIN messenger_conversations c ON c.id=m.conversation_id AND c.type='group' AND c.state='active' JOIN messenger_participants p ON p.conversation_id=m.conversation_id AND p.user_id=? JOIN messenger_group_members gm ON gm.conversation_id=m.conversation_id AND gm.user_id=p.user_id AND gm.active=1 WHERE m.id=? AND m.conversation_id=? AND m.id>p.hidden_through_message_id AND m.id>gm.joined_after_id AND m.deleted_at IS NULL AND m.moderation_state='visible' AND (m.expires_at IS NULL OR m.expires_at>?) AND NOT EXISTS(SELECT 1 FROM messenger_message_hides h WHERE h.message_id=m.id AND h.user_id=p.user_id)".$this->forUpdate($this->wire('database')));$live->execute([(int)$actor->id,$mid,(int)$row['conversation_id'],time()]);
        if(!$live->fetchColumn()||!$m||(int)$m['id']!==$mid||!empty($m['deleted_at']))throw new WirePermissionException('Event unavailable.');return [$row,$group];
    }
    public function groupEvent(User $actor,int $mid):array {$this->requireGroupEvents($actor);return $this->atomic(function()use($actor,$mid){[$row]=$this->authorizedGroupEvent($mid,$actor);return $this->eventView($row,$actor);});}
    public function createGroupEvent(User $actor,int $id,array $details,string $clientId):array {
        $this->requireGroupEvents($actor);$details=$this->eventInput($details,false);if(!preg_match('/^[A-Za-z0-9_-]{16,64}$/D',$clientId))throw new \InvalidArgumentException('Immutable event operation ID required.');
        return $this->atomic(function()use($actor,$id,$details,$clientId){$this->eventActorLock($actor);$this->groupLock($id,$actor);$db=$this->wire('database');$q=$db->prepare('SELECT id FROM messenger_messages WHERE sender_user_id=? AND client_id=?');$q->execute([(int)$actor->id,$clientId]);$old=$q->fetchColumn();
            if($old&&!$this->eventRow((int)$old))throw new MessengerMobileException('idempotency_conflict','Operation belongs to another message.',409);
            if(!$old)$this->eventInput($details);$message=$this->sendMessage($id,$actor,$this->eventBody($details),$clientId);
            if(!$old){$this->eraseMessageMentions((int)$message['id']);$this->consumeMemberRate($actor,'messenger_group_event_locks');$db->prepare('INSERT INTO messenger_group_events(message_id,conversation_id,creator_id,details,starts_at,version,cancelled_at) VALUES(?,?,?,?,?,1,NULL)')->execute([(int)$message['id'],$id,(int)$actor->id,$this->encryptValue(json_encode($details,JSON_THROW_ON_ERROR),'Messenger|group-event|'.$message['id']),$details['starts_at']]);$this->audit('event_created',$id,(int)$message['id'],(int)$actor->id);}
            $visible=$this->conversationMessages($id,$actor,(int)$message['id']+1,(int)$message['id']-1,1)[0]??null;$event=$visible&&empty($visible['deleted_at'])?($visible['event']??null):null;
            return ['message_id'=>(int)$message['id'],'conversation_id'=>$id,'accepted_client_id'=>$clientId,'ack_visible'=>$event!==null,'event'=>$event];
        });
    }
    private function eventWriteAllowed(User $actor,int $id):void {$chat=$this->conversation($id,$actor);if(empty($chat['can_send'])||!empty($chat['blocked']))throw new WirePermissionException('Event changes unavailable.');}
    private function eventFallbackBody(int $mid,string $body):void {
        $q=$this->wire('database')->prepare('SELECT * FROM messenger_messages WHERE id=?');$q->execute([$mid]);$message=$q->fetch(\PDO::FETCH_ASSOC);if(!$message)throw new WirePermissionException('Event unavailable.');
        $this->execute('UPDATE messenger_messages SET body=?,edited_at=? WHERE id=?',[$this->encryptValue($this->messageBody($body),$this->messageAad($message)),$this->now(),$mid]);$this->eraseMessageMentions($mid);
    }
    public function updateGroupEvent(User $actor,int $mid,int $version,array $details):array {
        $this->requireGroupEvents($actor);$details=$this->eventInput($details,false);
        return $this->atomic(function()use($actor,$mid,$version,$details){$this->eventActorLock($actor);[$row,$group]=$this->authorizedGroupEvent($mid,$actor);$this->eventWriteAllowed($actor,(int)$row['conversation_id']);
            if((int)$row['creator_id']!==(int)$actor->id&&!in_array($group['role'],['owner','admin'],true))throw new WirePermissionException('Only the author or an administrator can change this event.');
            if($row['cancelled_at']!==null)throw new WirePermissionException('Event cancelled.');if($this->eventDetails($row)===$details)return $this->eventView($row,$actor);
            if($version!==(int)$row['version'])throw new MessengerMobileException('event_changed','Refresh the changed event.',409);$this->eventInput($details);$this->consumeMemberRate($actor,'messenger_group_event_locks');$row['version']=(int)$row['version']+1;$row['starts_at']=$details['starts_at'];$row['details']=$this->encryptValue(json_encode($details,JSON_THROW_ON_ERROR),'Messenger|group-event|'.$mid);
            $this->execute('UPDATE messenger_group_events SET details=?,starts_at=?,version=? WHERE message_id=?',[$row['details'],$row['starts_at'],$row['version'],$mid]);
            $this->eventFallbackBody($mid,$this->eventBody($details));
            $this->execute('DELETE FROM messenger_group_event_answers WHERE message_id=?',[$mid]);$this->audit('event_updated',(int)$row['conversation_id'],$mid,(int)$actor->id);return $this->eventView($row,$actor);
        });
    }
    public function respondGroupEvent(User $actor,int $mid,?string $answer,?int $reminder,int $version):array {
        $this->requireGroupEvents($actor);if($reminder!==null&&!$this->messagePushReady())throw new WirePermissionException('Event reminders require configured message notifications.');if(($answer!==null&&!in_array($answer,['yes','maybe','no'],true))||($reminder!==null&&!in_array($reminder,[5,15,60,1440],true))||($reminder!==null&&!in_array($answer,['yes','maybe'],true)))throw new \InvalidArgumentException('Choose an answer and optional reminder.');
        return $this->atomic(function()use($actor,$mid,$answer,$reminder,$version){$this->eventActorLock($actor);[$row]=$this->authorizedGroupEvent($mid,$actor);if($version!==(int)$row['version'])throw new MessengerMobileException('event_changed','Refresh the changed event.',409);$chat=$this->conversation((int)$row['conversation_id'],$actor);if(empty($chat['can_send'])||!empty($chat['blocked']))throw new WirePermissionException('Event unavailable.');$view=$this->eventView($row,$actor);
            if($view['own_answer']===$answer&&$view['reminder_minutes']===$reminder)return $view;if($row['cancelled_at']!==null||(int)$row['starts_at']<=time())throw new WirePermissionException('Event is no longer accepting answers.');if($reminder!==null&&(int)$row['starts_at']-$reminder*60<=time())throw new \InvalidArgumentException('Choose a reminder before the event.');$expiry=$this->scalar('SELECT expires_at FROM messenger_messages WHERE id=?',[$mid]);if($reminder!==null&&$expiry>0&&$expiry<=(int)$row['starts_at']-$reminder*60)throw new \InvalidArgumentException('This event expires before the reminder.');$this->consumeMemberRate($actor,'messenger_group_event_locks');
            $old=$this->scalar('SELECT revision FROM messenger_group_event_answers WHERE message_id=? AND user_id=?',[$mid,(int)$actor->id]);$this->execute('DELETE FROM messenger_group_event_answers WHERE message_id=? AND user_id=?',[$mid,(int)$actor->id]);
            $this->execute('INSERT INTO messenger_group_event_answers(message_id,user_id,answer,reminder_minutes,event_version,revision,due_at,state,attempts,available_at) VALUES(?,?,?,?,?,?,?,?,0,0)',[$mid,(int)$actor->id,$this->encryptValue($answer??'none','Messenger|event-answer|'.$mid.'|'.(int)$actor->id),$reminder,(int)$row['version'],(int)$old+1,$reminder===null?null:(int)$row['starts_at']-$reminder*60,$reminder===null?'none':'pending']);
            $this->audit('event_responded',(int)$row['conversation_id'],$mid,(int)$actor->id);return $this->eventView($row,$actor);
        });
    }
    public function cancelGroupEvent(User $actor,int $mid,int $version):array {
        $this->requireGroupEvents($actor);return $this->atomic(function()use($actor,$mid,$version){$this->eventActorLock($actor);[$row,$group]=$this->authorizedGroupEvent($mid,$actor);$this->eventWriteAllowed($actor,(int)$row['conversation_id']);if((int)$row['creator_id']!==(int)$actor->id&&!in_array($group['role'],['owner','admin'],true))throw new WirePermissionException('Only the author or an administrator can cancel this event.');
            if($row['cancelled_at']===null){if($version!==(int)$row['version'])throw new MessengerMobileException('event_changed','Refresh the changed event.',409);$this->consumeMemberRate($actor,'messenger_group_event_locks');$row['cancelled_at']=time();$this->eventFallbackBody($mid,"Event cancelled\n".$this->eventDetails($row)['title']);$row['version']=(int)$row['version']+1;$this->execute('UPDATE messenger_group_events SET cancelled_at=?,version=? WHERE message_id=?',[$row['cancelled_at'],$row['version'],$mid]);$this->execute("UPDATE messenger_group_event_answers SET state='cancelled',revision=revision+1 WHERE message_id=?",[$mid]);$this->audit('event_cancelled',(int)$row['conversation_id'],$mid,(int)$actor->id);}return $this->eventView($row,$actor);
        });
    }
    /** Separate reminder queue: only an explicit current RSVP/consent can deliver. */
    public function processGroupEventReminders(User $operator,callable $send,int $limit=5):array {
        if(!$this->canAdmin($operator))throw new WirePermissionException('Administrative access required.');
        $result=['sent'=>0,'discarded'=>0,'retry'=>0];if(!$this->groupEventsReady()||!$this->messagePushReady())return $result;
        $db=$this->wire('database');$now=time();$limit=max(1,min(10,$limit));
        $this->execute("UPDATE messenger_group_event_answers SET state=CASE WHEN attempts>=5 THEN 'failed' ELSE 'pending' END WHERE state='processing' AND available_at<?",[$now]);
        $q=$db->prepare("SELECT a.*,e.conversation_id FROM messenger_group_event_answers a JOIN messenger_group_events e ON e.message_id=a.message_id WHERE a.state='pending' AND a.attempts<5 AND a.due_at<=? AND a.available_at<=? ORDER BY a.due_at,a.message_id LIMIT ".$limit);$q->execute([$now,$now]);
        foreach($q->fetchAll(\PDO::FETCH_ASSOC)as$job){
            $key=[(int)$job['message_id'],(int)$job['user_id'],(int)$job['event_version'],(int)$job['revision']];
            $claim=$db->prepare("UPDATE messenger_group_event_answers SET state='processing',attempts=attempts+1,available_at=? WHERE message_id=? AND user_id=? AND event_version=? AND revision=? AND state='pending'");$claim->execute([$now+60,...$key]);if($claim->rowCount()!==1)continue;
            try {
                if((int)$job['due_at']<$now-300)throw new WirePermissionException('Reminder expired.');
                $recipient=$this->wire('users')->get((int)$job['user_id']);$previous=$this->wire('user');
                try {
                    $this->wire('users')->setCurrentUser($recipient);$event=$this->groupEvent($recipient,(int)$job['message_id']);$chat=$this->conversation((int)$job['conversation_id'],$recipient);
                    $fresh=$db->prepare('SELECT state,revision,event_version FROM messenger_group_event_answers WHERE message_id=? AND user_id=?');$fresh->execute([$key[0],$key[1]]);$answer=$fresh->fetch(\PDO::FETCH_ASSOC);
                    if(!$answer||$answer['state']!=='processing'||(int)$answer['revision']!==$key[3]||(int)$answer['event_version']!==$key[2]||$event['version']!==$key[2]||$event['cancelled_at']!==null||$event['starts_at']<=$now||!in_array($event['own_answer'],['yes','maybe'],true)||$event['reminder_minutes']===null||!empty($chat['blocked'])||(!empty($chat['muted_until'])&&strtotime($chat['muted_until'])>$now))throw new WirePermissionException('Reminder no longer eligible.');
                    $origin=$this->wire('users')->get($event['created_by']);if(!$this->canReceive($origin)||$this->isBlockedEitherWay((int)$origin->id,(int)$recipient->id))throw new WirePermissionException('Event unavailable.');
                    $devices=$db->prepare('SELECT d.grant_id,d.platform,d.token,d.sound FROM messenger_message_devices d JOIN messenger_mobile_grants g ON '.$this->messagePushGrantJoin().' WHERE d.user_id=? AND g.user_id=d.user_id AND d.enabled=1 AND d.updated_at>=? AND g.revoked_at IS NULL ORDER BY d.grant_id LIMIT 11');$devices->execute([(int)$recipient->id,$now-2592000]);$targets=$devices->fetchAll(\PDO::FETCH_ASSOC);if(!$targets||count($targets)>10)throw new WirePermissionException('No bounded current notification targets.');
                    $discovery=(new MessengerMobileAuth($this))->discovery();$account=$discovery['issuer'].'#'.$discovery['site_id'].'#'.(int)$recipient->id;
                }finally{$this->wire('users')->setCurrentUser($previous);}
                foreach($targets as$device){
                    // Recheck consent/version before each provider call. Never hold SQL locks
                    // across a network request; already delivered alerts cannot be recalled.
                    $fresh->execute([$key[0],$key[1]]);$answer=$fresh->fetch(\PDO::FETCH_ASSOC);$current=$this->eventRow($key[0]);
                    if(!$answer||$answer['state']!=='processing'||(int)$answer['revision']!==$key[3]||(int)$answer['event_version']!==$key[2]||!$current||$current['cancelled_at']!==null||(int)$current['version']!==$key[2])throw new WirePermissionException('Reminder changed.');
                    $prior=$this->wire('user');try{$this->wire('users')->setCurrentUser($recipient);$check=$this->groupEvent($recipient,$key[0]);$liveChat=$this->conversation((int)$job['conversation_id'],$recipient);if($check['version']!==$key[2]||$check['cancelled_at']!==null||!in_array($check['own_answer'],['yes','maybe'],true)||$check['reminder_minutes']===null||!empty($liveChat['blocked'])||(!empty($liveChat['muted_until'])&&strtotime($liveChat['muted_until'])>time()))throw new WirePermissionException('Reminder no longer eligible.');}finally{$this->wire('users')->setCurrentUser($prior);}
                    $active=$db->prepare('SELECT d.enabled,d.token,d.platform,d.sound,d.updated_at,g.revoked_at FROM messenger_message_devices d JOIN messenger_mobile_grants g ON '.$this->messagePushGrantJoin().' WHERE d.grant_id=? AND d.user_id=? AND g.user_id=d.user_id');$active->execute([$device['grant_id'],$key[1]]);$registration=$active->fetch(\PDO::FETCH_ASSOC);if(!$registration||!$registration['enabled']||$registration['updated_at']<$now-2592000||$registration['revoked_at']!==null)continue;
                    $token=$this->decryptValue($registration['token'],'Messenger|message-device|'.$device['grant_id']);$accepted=$send($registration['platform'],$token,$key[0],(int)$job['conversation_id'],$account,(bool)$registration['sound'],$key[2].':'.$key[3]);
                    if(!is_bool($accepted))throw new \RuntimeException('Invalid reminder provider acknowledgement.');if(!$accepted)$this->execute('UPDATE messenger_message_devices SET enabled=0 WHERE grant_id=?',[$device['grant_id']]);
                }
                $state='sent';$result['sent']++;
            }catch(WirePermissionException $e){$state='discarded';$result['discarded']++;}
            catch(\Throwable $e){$state=(int)$job['attempts']>=4?'failed':'pending';$result['retry']++;}
            $this->execute("UPDATE messenger_group_event_answers SET state=?,available_at=? WHERE message_id=? AND user_id=? AND event_version=? AND revision=? AND state='processing'",[$state,$now+min(300,5*(2**(int)$job['attempts'])),...$key]);
        }
        return $result;
    }

}
