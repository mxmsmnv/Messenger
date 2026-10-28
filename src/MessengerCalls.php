<?php namespace ProcessWire;

/** Opt-in experimental 1:1 audio signaling. Media never passes through PHP. */
trait MessengerCalls {
    /** Read-only recent call metadata. No signaling payload, provider token or audio. */
    public function callEventsReady():bool {
        try {
            return $this->wire('database')->query('SELECT id FROM messenger_calls LIMIT 0')!==false
                && $this->wire('database')->query('SELECT call_id FROM messenger_call_signals LIMIT 0')!==false;
        } catch(\PDOException $e) { return false; }
    }
    public function conversationCallEvents(User $actor,int $id):array {
        $this->assertActor($actor);
        $chat=$this->conversation($id,$actor);
        if(!$chat||($chat['type']??'direct')!=='direct'||($chat['state']??'')!=='active'||($chat['request_state']??'')!=='accepted')throw new WirePermissionException('Conversation unavailable.');
        if(!$this->callEventsReady())throw new WirePermissionException('Call history unavailable.');
        $user=(int)$actor->id;$peer=(int)$chat['requester_id']===$user?(int)$chat['recipient_id']:(int)$chat['requester_id'];$now=time();
        // Read-only derivation also covers a ringing/active row whose worker has not
        // expired it yet. It never changes call state or claims an audible connection.
        $q=$this->wire('database')->prepare("SELECT c.id,c.caller_id,c.callee_id,c.state,c.created_at,c.expires_at,EXISTS(SELECT 1 FROM messenger_call_signals s WHERE s.call_id=c.id AND s.kind='accept') accepted FROM messenger_calls c WHERE c.conversation_id=? AND c.created_at>=? AND c.created_at<=? AND ((c.caller_id=? AND c.callee_id=?) OR (c.caller_id=? AND c.callee_id=?)) AND c.state IN ('ringing','active','ended','rejected') AND (c.state IN ('ended','rejected') OR c.expires_at<=? OR (c.state='active' AND (c.caller_seen<? OR c.callee_seen<?))) ORDER BY c.created_at DESC,c.id DESC LIMIT 20");
        $q->execute([$id,$now-86400,$now,$user,$peer,$peer,$user,$now,$now-90,$now-90]);$items=[];
        foreach($q->fetchAll(\PDO::FETCH_ASSOC)as$row) {
            if(!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D',(string)$row['id']))continue;
            $incoming=(int)$row['callee_id']===$user;
            $outcome=$row['state']==='rejected'?'rejected':(!empty($row['accepted'])?'ended':((int)$row['expires_at']<=$now?'missed':'cancelled'));
            $items[]=['id'=>$row['id'],'conversation_id'=>$id,'incoming'=>$incoming,'created_at'=>(int)$row['created_at'],'outcome'=>$outcome];
        }
        return ['items'=>$items,'window_seconds'=>86400];
    }
    private function callsConfig(): array { $v=$this->wire('config')->messengerCalls;return is_array($v)?$v:[]; }
    public function experimentalCallsReady(): bool {
        $c=$this->callsConfig();return !empty($c['enabled']) && !empty($c['push_ready']);
    }
    /** Opaque native grants compare byte-exactly across historical MySQL collations. */
    private function callPushGrantJoin():string {
        return $this->wire('database')->getAttribute(\PDO::ATTR_DRIVER_NAME)==='mysql'?'g.id=CAST(d.grant_id AS BINARY)':'g.id=d.grant_id';
    }
    public function accountCallsReady():bool {
        if(!$this->experimentalCallsReady())return false;
        try{return $this->wire('database')->query('SELECT grant_id,token FROM messenger_call_devices LIMIT 0')!==false;}
        catch(\PDOException $error){return false;}
    }
    /** Authenticate mode with AAD, never an ambiguous plaintext token prefix. */
    private function accountCallRegistration(string $stored,string $grant):array {
        if($stored==='')throw new WirePermissionException('Call registration unavailable.');
        try{$token=$this->decryptValue($stored,'Messenger|call-device-account|'.$grant);$bound=true;}
        catch(WireException $error){$token=$this->decryptValue($stored,'Messenger|call-device|'.$grant);$bound=false;}
        if($token==='')throw new WirePermissionException('Call registration unavailable.');
        return [$token,$bound];
    }
    /** Explicit administrative setup only. Existing messaging schema/data remain untouched. */
    public function installExperimentalCalls(User $actor): void {
        if(!$this->canAdmin($actor))throw new WirePermissionException('Administrative access required.');
        foreach([
            'messenger_call_relays'=>'slot INTEGER PRIMARY KEY,payload TEXT NOT NULL',
            'messenger_call_devices'=>'grant_id CHAR(32) PRIMARY KEY,user_id BIGINT NOT NULL,platform VARCHAR(12) NOT NULL,token TEXT NOT NULL,enabled INTEGER NOT NULL,updated_at BIGINT NOT NULL',
            'messenger_calls'=>'id CHAR(36) PRIMARY KEY,client_id VARCHAR(64) NOT NULL,conversation_id BIGINT NOT NULL,caller_id BIGINT NOT NULL,callee_id BIGINT NOT NULL,caller_grant CHAR(32) NOT NULL,answer_grant CHAR(32) NULL,state VARCHAR(16) NOT NULL,sequence BIGINT NOT NULL,created_at BIGINT NOT NULL,expires_at BIGINT NOT NULL,caller_seen BIGINT NOT NULL DEFAULT 0,callee_seen BIGINT NOT NULL DEFAULT 0,UNIQUE(caller_grant,client_id)',
            'messenger_call_signals'=>'call_id CHAR(36) NOT NULL,sequence BIGINT NOT NULL,grant_id CHAR(32) NOT NULL,client_id VARCHAR(64) NOT NULL,kind VARCHAR(12) NOT NULL,payload TEXT NOT NULL,created_at BIGINT NOT NULL,PRIMARY KEY(call_id,sequence),UNIQUE(call_id,grant_id,client_id)',
            'messenger_call_feedback'=>'call_id CHAR(36) NOT NULL,user_id BIGINT NOT NULL,payload TEXT NOT NULL,created_at BIGINT NOT NULL,PRIMARY KEY(call_id,user_id)',
            'messenger_call_pushes'=>'call_id CHAR(36) NOT NULL,grant_id CHAR(32) NOT NULL,status VARCHAR(12) NOT NULL,attempts INTEGER NOT NULL,available_at BIGINT NOT NULL,PRIMARY KEY(call_id,grant_id)',
        ] as $table=>$columns)$this->wire('database')->exec("CREATE TABLE IF NOT EXISTS `$table` ($columns)");
    }
    /** Schema capability only: never creates a table from a member request. */
    private function experimentalCallFeedbackReady():bool {
        try {return $this->wire('database')->query('SELECT call_id FROM messenger_call_feedback LIMIT 0')!==false;}
        catch(\PDOException $e){return false;}
    }
    private function saveExperimentalCallFeedback(User $actor,string $grant,string $method,array $body):array {
        if(!$this->experimentalCallFeedbackReady())throw new WirePermissionException('Call feedback unavailable.');
        if($method!=='POST'||count($body)!==4||array_diff(array_keys($body),['id','stars','issues','consent'])||($body['consent']??null)!==true||!is_int($body['stars']??null)||$body['stars']<1||$body['stars']>5||!is_array($body['issues']??null)||!array_is_list($body['issues'])||count($body['issues'])>9||!is_string($body['id']??null)||!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D',$body['id']))throw new \InvalidArgumentException('Invalid call feedback.');
        $allowed=['no_audio','quiet','distortion','echo','delay','dropouts','connection','speaker','microphone'];
        foreach($body['issues'] as $issue)if(!is_string($issue)||!in_array($issue,$allowed,true))throw new \InvalidArgumentException('Invalid feedback issue.');
        if(count(array_unique($body['issues']))!==count($body['issues']))throw new \InvalidArgumentException('Duplicate feedback issue.');
        sort($body['issues']);$id=$body['id'];$user=(int)$actor->id;$db=$this->wire('database');
        // This metadata-only route deliberately remains available when media/push is off.
        // It does not depend on a still-sendable chat: a participant may report a bad
        // call after blocking the peer. The original accepted call and exact grant own it.
        return $this->atomic(function()use($actor,$grant,$body,$id,$user,$db):array{
            $lock=$db->getAttribute(\PDO::ATTR_DRIVER_NAME)==='sqlite'?'':' FOR UPDATE';
            // Serialize with native grant revocation before committing private feedback.
            $g=$db->prepare('SELECT user_id FROM messenger_mobile_grants WHERE id=? AND revoked_at IS NULL'.$lock);$g->execute([$grant]);
            if((int)$g->fetchColumn()!==$user)throw new WirePermissionException('Native connection unavailable.');
            $q=$db->prepare('SELECT * FROM messenger_calls WHERE id=?'.$lock);$q->execute([$id]);$call=$q->fetch(\PDO::FETCH_ASSOC);
            if(!$call||$call['state']!=='ended'||!$call['answer_grant']||(int)$call['created_at']<time()-86400||!(((int)$call['caller_id']===$user&&$call['caller_grant']===$grant)||((int)$call['callee_id']===$user&&$call['answer_grant']===$grant)))throw new WirePermissionException('Call feedback unavailable.');
            // Offer + answer establish negotiated media, not proof of audible RTP.
            $q=$db->prepare("SELECT COUNT(DISTINCT kind) FROM messenger_call_signals WHERE call_id=? AND kind IN ('offer','answer')");$q->execute([$id]);
            if((int)$q->fetchColumn()!==2)throw new WirePermissionException('Call feedback unavailable.');
            $payload=json_encode(['stars'=>$body['stars'],'issues'=>$body['issues']],JSON_THROW_ON_ERROR);$aad='Messenger|call-feedback|'.$id.'|'.$user;
            $this->execute('DELETE FROM messenger_call_feedback WHERE created_at<?',[time()-2592000]);
            $q=$db->prepare('SELECT payload FROM messenger_call_feedback WHERE call_id=? AND user_id=?');$q->execute([$id,$user]);$old=$q->fetchColumn();
            if($old!==false){if(!hash_equals($payload,$this->decryptValue($old,$aad)))throw new MessengerMobileException('idempotency_conflict','Feedback already submitted.',409);return ['saved'=>true];}
            $this->execute('INSERT INTO messenger_call_feedback(call_id,user_id,payload,created_at) VALUES(?,?,?,?)',[$id,$user,$this->encryptValue($payload,$aad),time()]);
            return ['saved'=>true];
        });
    }
    /** Private bounded export; admin alone does not confer the export permission. */
    public function experimentalCallFeedback(User $actor,string $after='',int $limit=100):array {
        if(!$this->canAdmin($actor)||(!$actor->isSuperuser()&&!$actor->hasPermission('messenger-export')))throw new WirePermissionException('Administrator and export access required.');
        if($limit<1||$limit>200||($after!==''&&!preg_match('/^([0-9]{1,12}):([a-f0-9-]{36}):([1-9][0-9]{0,18})$/D',$after,$cursor)))throw new \InvalidArgumentException('Invalid feedback page.');
        if(!$this->experimentalCallFeedbackReady())return ['items'=>[],'next'=>null];
        $this->execute('DELETE FROM messenger_call_feedback WHERE created_at<?',[time()-2592000]);
        $where='';$params=[];if($after!==''){$where=' WHERE created_at>? OR (created_at=? AND call_id>?) OR (created_at=? AND call_id=? AND user_id>?)';$params=[(int)$cursor[1],(int)$cursor[1],$cursor[2],(int)$cursor[1],$cursor[2],(int)$cursor[3]];}
        $q=$this->wire('database')->prepare('SELECT call_id,user_id,payload,created_at FROM messenger_call_feedback'.$where.' ORDER BY created_at,call_id,user_id LIMIT '.$limit);$q->execute($params);$items=[];
        foreach($q->fetchAll(\PDO::FETCH_ASSOC) as $row){$value=json_decode($this->decryptValue($row['payload'],'Messenger|call-feedback|'.$row['call_id'].'|'.$row['user_id']),true,512,JSON_THROW_ON_ERROR);$items[]=['call_id'=>$row['call_id'],'user_id'=>(int)$row['user_id'],'created_at'=>(int)$row['created_at'],'stars'=>$value['stars'],'issues'=>$value['issues']];}
        $last=$items?end($items):null;return ['items'=>$items,'next'=>count($items)===$limit?$last['created_at'].':'.$last['call_id'].':'.$last['user_id']:null];
    }
    /** Bounded CLI worker; provider send is injectable for deterministic delivery tests. */
    public function processExperimentalCallPushes(User $actor,callable $send,int $limit=20):array {
        if(PHP_SAPI!=='cli'||!$this->canAdmin($actor))throw new WirePermissionException('CLI administrator required.');
        $this->pruneClientDiagnostics();
        if($this->experimentalCallFeedbackReady())$this->execute('DELETE FROM messenger_call_feedback WHERE created_at<?',[time()-2592000]);
        $result=['sent'=>0,'retry'=>0,'discarded'=>0];if(!$this->experimentalCallsReady())return $result;
        $db=$this->wire('database');$this->expireExperimentalCalls();$now=time();$limit=max(1,min(20,$limit));
        $this->execute("UPDATE messenger_call_pushes SET status='pending' WHERE status='processing' AND available_at<?",[$now-30]);
        $q=$db->prepare("SELECT p.call_id,p.grant_id,d.platform,d.token,c.caller_id,c.conversation_id FROM messenger_call_pushes p JOIN messenger_calls c ON c.id=p.call_id JOIN messenger_call_devices d ON d.grant_id=p.grant_id JOIN messenger_mobile_grants g ON ".$this->callPushGrantJoin()." WHERE p.status='pending' AND p.attempts<3 AND p.available_at<=? AND c.state='ringing' AND c.expires_at>? AND d.enabled=1 AND g.revoked_at IS NULL ORDER BY p.available_at LIMIT ".$limit);$q->execute([$now,$now]);
        foreach($q->fetchAll(\PDO::FETCH_ASSOC)as$row){
            $claim=$db->prepare("UPDATE messenger_call_pushes SET status='processing',attempts=attempts+1,available_at=? WHERE call_id=? AND grant_id=? AND status='pending'");$claim->execute([$now,$row['call_id'],$row['grant_id']]);if($claim->rowCount()!==1)continue;
            try{
                $users=$this->wire('users');$caller=$users->get((int)$row['caller_id']);$previous=$this->wire('user');
                // ProcessWire User::isLoggedin() is current-user scoped, including in CLI.
                // Re-evaluate normal caller/conversation policy, then restore the worker context
                // before handing any registration to the provider (also on denial/exception).
                try {$users->setCurrentUser($caller);$call=$this->callRow($caller,$row['call_id']);}
                finally {$users->setCurrentUser($previous);}
                if($call['state']!=='ringing')throw new WirePermissionException('Call no longer ringing.');
                $origin=$db->prepare('SELECT user_id FROM messenger_mobile_grants WHERE id=? AND revoked_at IS NULL');$origin->execute([$call['caller_grant']]);if((int)$origin->fetchColumn()!==(int)$call['caller_id'])throw new WirePermissionException('Caller connection unavailable.');
                $live=$db->prepare('SELECT d.user_id,d.platform,d.token,d.enabled,d.updated_at,g.revoked_at FROM messenger_call_devices d JOIN messenger_mobile_grants g ON '.$this->callPushGrantJoin().' AND g.user_id=d.user_id WHERE d.grant_id=?');$live->execute([$row['grant_id']]);$registration=$live->fetch(\PDO::FETCH_ASSOC);
                if(!$registration||!$registration['enabled']||$registration['revoked_at']!==null||$registration['updated_at']<$now-2592000||(int)$registration['user_id']!==(int)$call['callee_id'])throw new WirePermissionException('Call registration unavailable.');
                $recipient=$users->get((int)$call['callee_id']);
                try{$users->setCurrentUser($recipient);$current=$this->callRow($recipient,$row['call_id']);if($current['state']!=='ringing')throw new WirePermissionException('Call unavailable.');}
                finally{$users->setCurrentUser($previous);}
                [$token,$bound]=$this->accountCallRegistration($registration['token'],$row['grant_id']);
                $account=null;if($bound){$discovery=(new MessengerMobileAuth($this))->discovery();$account=$discovery['issuer'].'#'.$discovery['site_id'].'#'.(int)$recipient->id.'#'.$row['grant_id'];}
                $name=(string)($caller->get('display_name')?:$caller->get('title')?:$caller->name);
                $args=[$registration['platform'],$token,$row['call_id'],mb_substr(trim((string)preg_replace('/[\\x00-\\x1F\\x7F]/u','',$name)),0,100)];if($account!==null)$args[]=$account;$accepted=$send(...$args);if(!is_bool($accepted))throw new \RuntimeException('Invalid call provider acknowledgement.');
                if(!$accepted)$this->execute('UPDATE messenger_call_devices SET enabled=0 WHERE grant_id=?',[$row['grant_id']]);
                $status=$accepted?'sent':'discarded';$result[$status]++;
            }catch(WirePermissionException $e){$status='discarded';$result['discarded']++;}
            catch(\Throwable $e){$status='pending';$result['retry']++;}
            $this->execute('UPDATE messenger_call_pushes SET status=?,available_at=? WHERE call_id=? AND grant_id=?',[$status,$now+5,$row['call_id'],$row['grant_id']]);
        }
        $this->execute('DELETE FROM messenger_call_signals WHERE created_at<?',[$now-86400]);
        $this->execute('DELETE FROM messenger_calls WHERE expires_at<?',[$now-86400]);
        $db->exec('DELETE FROM messenger_call_pushes WHERE call_id NOT IN (SELECT id FROM messenger_calls)');
        $this->execute('DELETE FROM messenger_call_devices WHERE updated_at<?',[$now-2592000]);
        return $result;
    }
    private function expireExperimentalCalls():void {
        $mysql=$this->wire('database')->getAttribute(\PDO::ATTR_DRIVER_NAME)==='mysql';
        $caller=$mysql?'CAST(caller_grant AS BINARY)':'caller_grant';$answer=$mysql?'CAST(answer_grant AS BINARY)':'answer_grant';$id=$mysql?'CAST(id AS BINARY)':'id';
        $this->execute("UPDATE messenger_calls SET state='ended' WHERE state IN ('ringing','active') AND (expires_at<=? OR (state='active' AND (caller_seen<? OR callee_seen<?)) OR ".$caller." NOT IN (SELECT ".$id." FROM messenger_mobile_grants WHERE revoked_at IS NULL) OR (answer_grant IS NOT NULL AND ".$answer." NOT IN (SELECT ".$id." FROM messenger_mobile_grants WHERE revoked_at IS NULL)))",[time(),time()-90,time()-90]);
    }
    private function callConversation(User $actor,int $id):array {
        if($this->scalar("SELECT COUNT(*) FROM messenger_conversations WHERE id=? AND type<>'direct'",[$id]))throw new WirePermissionException('Direct conversation required.');
        $this->assertActor($actor);$chat=$this->conversation($id,$actor);
        if(!$chat || ($chat['state']??'')!=='active' || ($chat['request_state']??'')!=='accepted' || !empty($chat['blocked']) || empty($chat['can_send']))throw new WirePermissionException('Call unavailable.');
        return $chat;
    }
    private function callRow(User $actor,string $id):array {
        if(!preg_match('/^[a-f0-9-]{36}$/D',$id))throw new \InvalidArgumentException('Invalid call ID.');
        $q=$this->wire('database')->prepare('SELECT * FROM messenger_calls WHERE id=?');$q->execute([$id]);$row=$q->fetch(\PDO::FETCH_ASSOC);
        if(!$row || !in_array((int)$actor->id,[(int)$row['caller_id'],(int)$row['callee_id']],true))throw new WirePermissionException('Call unavailable.');
        $this->callConversation($actor,(int)$row['conversation_id']);
        if((int)$row['expires_at']<=time() && in_array($row['state'],['ringing','active'],true)) {$this->execute("UPDATE messenger_calls SET state='ended' WHERE id=?",[$id]);$row['state']='ended';}
        return $row;
    }
    private function callsDevice(User $actor,string $grant):void {
        $q=$this->wire('database')->prepare('SELECT enabled FROM messenger_call_devices WHERE grant_id=? AND user_id=?');$q->execute([$grant,(int)$actor->id]);if((int)$q->fetchColumn()!==1)throw new WirePermissionException('Calls are disabled on this device.');
    }
    private function callPresentation(array $row):array {
        $q=$this->wire('database')->prepare("SELECT MIN(CASE WHEN kind='accept' THEN created_at END) accepted_at,MAX(CASE WHEN kind IN ('end','reject') THEN created_at END) ended_at FROM messenger_call_signals WHERE call_id=?");$q->execute([$row['id']]);$times=$q->fetch(\PDO::FETCH_ASSOC);
        $ended=$times['ended_at']??null;
        // Worker expiry has no explicit End signal; the common last heartbeat
        // marks the last observed participation, rather than either device clock.
        if($ended===null&&$row['state']==='ended')$ended=min((int)$row['caller_seen'],(int)$row['callee_seen']);
        return ['id'=>$row['id'],'conversation_id'=>(int)$row['conversation_id'],'caller_id'=>(int)$row['caller_id'],'callee_id'=>(int)$row['callee_id'],'state'=>$row['state'],'created_at'=>(int)$row['created_at'],'accepted_at'=>isset($times['accepted_at'])?(int)$times['accepted_at']:null,'ended_at'=>$ended!==null?(int)$ended:null,'expires_at'=>(int)$row['expires_at']];
    }
    /** Native-only transport binds registrations/signals to the authenticated, revocable grant. */
    public function experimentalCallRequest(User $actor,string $grant,string $resource,string $method,array $body,array $query):array {
        $this->assertActor($actor);
        if($resource==='call-config') {
            if($method!=='GET')throw new \InvalidArgumentException('GET required.');
            return ['enabled'=>$this->experimentalCallsReady(),'feedback_enabled'=>$this->experimentalCallFeedbackReady(),'diagnostics_enabled'=>$this->clientDiagnosticsReady(),'experimental'=>true,'incoming_requires_push'=>true,'max_ring_seconds'=>45,'account_binding_supported'=>$this->accountCallsReady(),'ice_restart_supported'=>true,'direct_connections'=>true,'relay_count'=>count($this->configuredCallRelays())];
        }
        if(!preg_match('/^[a-f0-9]{32}$/D',$grant))throw new WirePermissionException('Native connection required.');
        $db=$this->wire('database');
        $g=$db->prepare('SELECT user_id FROM messenger_mobile_grants WHERE id=? AND revoked_at IS NULL');$g->execute([$grant]);
        if((int)$g->fetchColumn()!==(int)$actor->id)throw new WirePermissionException('Native connection unavailable.');
        if($resource==='call-feedback')return $this->saveExperimentalCallFeedback($actor,$grant,$method,$body);
        if(!$this->experimentalCallsReady())throw new WirePermissionException('Experimental calls are not configured.');
        if($resource==='call-device') {
            if((array_key_exists('account_binding',$body)&&!is_bool($body['account_binding']))||$method!=='POST' || !isset($body['enabled']) || !is_bool($body['enabled']))throw new \InvalidArgumentException('Enabled flag required.');
            $binding=$body['account_binding']??false;if($binding&&!$this->accountCallsReady())throw new WirePermissionException('Account calls unavailable.');
            $platform=$body['platform']??'';$token=$body['token']??'';
            if(!in_array($platform,['ios','android'],true)||!is_string($token)||strlen($token)>4096||($body['enabled']&&$token===''))throw new \InvalidArgumentException('Push registration required.');
            return $this->atomic(function()use($actor,$grant,$body,$platform,$token,$binding):array{
                $this->execute('DELETE FROM messenger_call_devices WHERE grant_id=?',[$grant]);
                $this->execute('INSERT INTO messenger_call_devices(grant_id,user_id,platform,token,enabled,updated_at) VALUES(?,?,?,?,?,?)',[$grant,(int)$actor->id,$platform,$this->encryptValue($token,($binding?'Messenger|call-device-account|':'Messenger|call-device|').$grant),$body['enabled']?1:0,time()]);
                if(!$body['enabled'])$this->execute("UPDATE messenger_calls SET state='ended' WHERE (caller_grant=? OR answer_grant=?) AND state IN ('ringing','active')",[$grant,$grant]);
                $result=['enabled'=>$body['enabled']];if(array_key_exists('account_binding',$body)){$result['account_binding']=$binding;$result['connection_id']=$grant;}return $result;
            });
        }
        $this->callsDevice($actor,$grant);$this->expireExperimentalCalls();
        if($resource==='call-start') {
            if($method!=='POST'||!is_int($body['conversation_id']??null))throw new \InvalidArgumentException('Conversation required.');
            $client=$body['client_id']??'';if(!is_string($client)||!preg_match('/^[A-Za-z0-9_-]{16,64}$/D',$client))throw new \InvalidArgumentException('Client ID required.');
            $chat=$this->callConversation($actor,$body['conversation_id']);$peer=(int)$chat['requester_id']===(int)$actor->id?(int)$chat['recipient_id']:(int)$chat['requester_id'];
            return $this->atomic(function()use($actor,$grant,$client,$chat,$peer,$db):array{
                // Lock the conversation to serialize simultaneous calls and retries.
                if($db->getAttribute(\PDO::ATTR_DRIVER_NAME)!=='sqlite'){$q=$db->prepare('SELECT grant_id FROM messenger_call_devices WHERE user_id IN (?,?) ORDER BY user_id,grant_id FOR UPDATE');$q->execute([(int)$actor->id,$peer]);}
                $q=$db->prepare('SELECT * FROM messenger_calls WHERE caller_grant=? AND client_id=?');$q->execute([$grant,$client]);$existing=$q->fetch(\PDO::FETCH_ASSOC);if($existing)return $this->callPresentation($existing);
                $q=$db->prepare("SELECT COUNT(*) FROM messenger_calls WHERE expires_at>? AND state IN ('ringing','active') AND (caller_id IN (?,?) OR callee_id IN (?,?))");$q->execute([time(),(int)$actor->id,$peer,(int)$actor->id,$peer]);if((int)$q->fetchColumn()>0) {
                    $own=$db->prepare("SELECT COUNT(*) FROM messenger_calls WHERE expires_at>? AND state IN ('ringing','active') AND (caller_id=? OR callee_id=?)");$own->execute([time(),(int)$actor->id,(int)$actor->id]);
                    throw new MessengerMobileException((int)$own->fetchColumn()>0?'caller_busy':'peer_busy','A call is already in progress.',409);
                }
                $q=$db->prepare('SELECT d.grant_id FROM messenger_call_devices d JOIN messenger_mobile_grants g ON '.$this->callPushGrantJoin().' WHERE d.user_id=? AND d.enabled=1 AND g.revoked_at IS NULL AND d.updated_at>?');$q->execute([$peer,time()-2592000]);$devices=$q->fetchAll(\PDO::FETCH_COLUMN);if(!$devices)throw new WirePermissionException('The other person has not enabled calls.');
                $h=bin2hex(random_bytes(16));$id=substr($h,0,8).'-'.substr($h,8,4).'-4'.substr($h,13,3).'-'.dechex((hexdec($h[16])&3)|8).substr($h,17,3).'-'.substr($h,20);
                $this->execute("INSERT INTO messenger_calls(id,client_id,conversation_id,caller_id,callee_id,caller_grant,state,sequence,created_at,expires_at) VALUES(?,?,?,?,?,?,'ringing',0,?,?)",[$id,$client,(int)$chat['id'],(int)$actor->id,$peer,$grant,time(),time()+45]);
                $this->execute('UPDATE messenger_calls SET caller_seen=? WHERE id=?',[time(),$id]);
                foreach($devices as $device)$this->execute("INSERT INTO messenger_call_pushes VALUES(?,?,'pending',0,?)",[$id,$device,time()]);
                return $this->callPresentation($this->callRow($actor,$id));
            });
        }
        if($resource==='call-inbox') {
            if($method!=='GET')throw new \InvalidArgumentException('GET required.');
            $q=$db->prepare("SELECT * FROM messenger_calls WHERE expires_at>? AND state IN ('ringing','active') AND (caller_id=? OR callee_id=?) ORDER BY created_at DESC LIMIT 20");$q->execute([time(),(int)$actor->id,(int)$actor->id]);$items=[];
            foreach($q->fetchAll(\PDO::FETCH_ASSOC) as $row){try{$this->callConversation($actor,(int)$row['conversation_id']);$items[]=$this->callPresentation($row);}catch(WirePermissionException $e){}}
            return ['items'=>$items];
        }
        $id=(string)($method==='GET'?($query['id']??''):($body['id']??''));$row=$this->callRow($actor,$id);
        if($resource==='call-state') {
            if($method!=='GET')throw new \InvalidArgumentException('GET required.');
            if($row['state']==='active'&&$grant!==$row['caller_grant']&&$grant!==$row['answer_grant'])return ['call'=>array_merge($this->callPresentation($row),['state'=>'answered_elsewhere']),'signals'=>[]];
            if($grant===$row['caller_grant'])$this->execute('UPDATE messenger_calls SET caller_seen=? WHERE id=?',[time(),$id]);
            elseif($grant===$row['answer_grant'])$this->execute('UPDATE messenger_calls SET callee_seen=? WHERE id=?',[time(),$id]);
            $after=filter_var($query['after']??0,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);if($after===false)throw new \InvalidArgumentException('Invalid cursor.');
            $q=$db->prepare('SELECT sequence,grant_id,kind,payload FROM messenger_call_signals WHERE call_id=? AND sequence>? ORDER BY sequence LIMIT 100');$q->execute([$id,$after]);$events=[];
            foreach($q->fetchAll(\PDO::FETCH_ASSOC) as $event){$events[]=['sequence'=>(int)$event['sequence'],'own'=>$event['grant_id']===$grant,'kind'=>$event['kind'],'payload'=>$this->decryptValue($event['payload'],'Messenger|call-signal|'.$id.'|'.$event['sequence'])];}
            return ['call'=>$this->callPresentation($row),'signals'=>$events];
        }
        if($resource==='call-ice') {
            if($method!=='GET'||$row['state']!=='active'||!in_array($grant,[$row['caller_grant'],$row['answer_grant']],true))throw new WirePermissionException('Call unavailable.');
            return $this->callIcePool($row,$grant);
        }
        if($resource!=='call-signal'||$method!=='POST')throw new \InvalidArgumentException('Unknown call operation.');
        $kind=$body['kind']??'';$payload=$body['payload']??'';$client=$body['client_id']??'';
        if(!in_array($kind,['offer','answer','ice','restart','accept','reject','end'],true)||!is_string($payload)||strlen($payload)>65536||!is_string($client)||!preg_match('/^[A-Za-z0-9_-]{16,64}$/D',$client))throw new \InvalidArgumentException('Invalid call signal.');
        return $this->atomic(function()use($actor,$grant,$id,$kind,$payload,$client,$db):array{
            $lock=$db->getAttribute(\PDO::ATTR_DRIVER_NAME)==='sqlite'?'':' FOR UPDATE';$q=$db->prepare('SELECT * FROM messenger_calls WHERE id=?'.$lock);$q->execute([$id]);$row=$q->fetch(\PDO::FETCH_ASSOC);
            $q=$db->prepare('SELECT sequence,kind,payload FROM messenger_call_signals WHERE call_id=? AND grant_id=? AND client_id=?');$q->execute([$id,$grant,$client]);$old=$q->fetch(\PDO::FETCH_ASSOC);
            if($old){if($old['kind']!==$kind||!hash_equals($payload,$this->decryptValue($old['payload'],'Messenger|call-signal|'.$id.'|'.$old['sequence'])))throw new \InvalidArgumentException('Signal retry differs.');return ['sequence'=>(int)$old['sequence']];}
            if(!in_array($row['state'],['ringing','active'],true)||(int)$row['expires_at']<=time())throw new WirePermissionException('Call ended.');
            $caller=$grant===$row['caller_grant'];$callee=(int)$actor->id===(int)$row['callee_id'];
            if(in_array($kind,['offer','answer','ice','restart'],true)&&$row['state']!=='active')throw new WirePermissionException('Call must be accepted before media signaling.');
            if($kind==='accept') {if(!$callee||$row['state']!=='ringing')throw new WirePermissionException('Cannot accept.');$this->execute("UPDATE messenger_calls SET state='active',answer_grant=?,expires_at=?,callee_seen=? WHERE id=?",[$grant,time()+7200,time(),$id]);}
            elseif($kind==='reject'){if(!$callee||$row['state']!=='ringing')throw new WirePermissionException('Cannot reject.');$this->execute("UPDATE messenger_calls SET state='rejected' WHERE id=?",[$id]);}
            elseif(!$caller&&$row['answer_grant']!==$grant)throw new WirePermissionException('Another device owns this call.');
            if(($kind==='offer'&&!$caller)||($kind==='answer'&&(!$callee||$row['answer_grant']!==$grant)))throw new WirePermissionException('Invalid SDP role.');
            if($kind==='end')$this->execute("UPDATE messenger_calls SET state='ended' WHERE id=?",[$id]);
            $seq=(int)$row['sequence']+1;$this->execute('UPDATE messenger_calls SET sequence=? WHERE id=?',[$seq,$id]);
            $this->execute('INSERT INTO messenger_call_signals VALUES(?,?,?,?,?,?,?)',[$id,$seq,$grant,$client,$kind,$this->encryptValue($payload,'Messenger|call-signal|'.$id.'|'.$seq),time()]);
            return ['sequence'=>$seq];
        });
    }
}
