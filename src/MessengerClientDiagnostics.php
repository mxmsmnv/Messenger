<?php namespace ProcessWire;

/** Native-only, metadata-only diagnostics. No message/audio/keyboard payloads. */
trait MessengerClientDiagnostics {
    public const DIAGNOSTIC_EVENTS=['screen_open','chat_open','chat_back','message_send_tap','message_send_success','message_send_retry','message_send_error','attachment_pick','attachment_send_tap','attachment_send_success','attachment_send_error','call_start_tap','call_accept_tap','call_reject_tap','call_end_tap','call_connected','call_reconnecting','call_failed','call_ended','call_mic_tap','call_speaker_tap','call_audio_tap','call_chat_tap','call_quality','account_switch_tap','app_foreground','app_background','operation_error','request_accept_tap','request_decline_tap'];
    public function clientDiagnosticsReady():bool {
        try{return $this->wire('database')->query('SELECT event_id,grant_hash,user_id,payload,created_at FROM messenger_client_diagnostics LIMIT 0')!==false;}
        catch(\PDOException $e){return false;}
    }
    /** Explicit operator action only. Never invoked by discovery, upgrade or member writes. */
    public function installClientDiagnostics(User $actor):void {
        if(!$this->canAdmin($actor))throw new WirePermissionException('Administrator required.');
        $db=$this->wire('database');$engine=$db->getAttribute(\PDO::ATTR_DRIVER_NAME)==='sqlite'?'':' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
        $existed=$this->clientDiagnosticsReady();
        $db->exec('CREATE TABLE IF NOT EXISTS messenger_client_diagnostics (event_id CHAR(32) NOT NULL,grant_hash CHAR(64) NOT NULL,user_id BIGINT NOT NULL,payload TEXT NOT NULL,created_at BIGINT NOT NULL,PRIMARY KEY(event_id,grant_hash))'.$engine);
        if(!$existed){$db->exec('CREATE INDEX messenger_diagnostic_retention ON messenger_client_diagnostics(created_at)');$db->exec('CREATE INDEX messenger_diagnostic_user_time ON messenger_client_diagnostics(user_id,created_at)');}
        // Singleton quota mutex. Contains no account/event data; not returned by exports.
        $q=$db->prepare('SELECT user_id FROM messenger_client_diagnostics WHERE event_id=? AND grant_hash=?');$q->execute([str_repeat('0',32),str_repeat('0',64)]);
        if($q->fetchColumn()===false)$this->execute('INSERT INTO messenger_client_diagnostics VALUES(?,?,?,?,?)',[str_repeat('0',32),str_repeat('0',64),0,'',0]);
    }
    public function receiveClientDiagnostics(User $actor,string $grant,string $method,array $body):array {
        $this->assertActor($actor);
        if(!$this->clientDiagnosticsReady())throw new WirePermissionException('Client diagnostics unavailable.');
        if($method!=='POST'||array_diff(array_keys($body),['version','platform','enabled','events'])||count($body)!==4||($body['version']??null)!==1||($body['enabled']??null)!==true||!in_array($body['platform']??null,['android','ios','other'],true)||!is_array($body['events']??null)||!array_is_list($body['events'])||count($body['events'])<1||count($body['events'])>50||strlen(json_encode($body,JSON_THROW_ON_ERROR))>65536)throw new \InvalidArgumentException('Invalid diagnostics batch.');
        if(!preg_match('/^[a-f0-9]{32}$/D',$grant))throw new WirePermissionException('Native connection required.');
        $events=[];$now=time();
        foreach($body['events'] as $event){$valid=$this->validateClientDiagnostic($event,$now);if(isset($events[$valid['id']]))throw new \InvalidArgumentException('Duplicate event ID.');$events[$valid['id']]=$valid;}
        return $this->atomic(function()use($actor,$grant,$body,$events,$now){
            $db=$this->wire('database');$lock=$db->getAttribute(\PDO::ATTR_DRIVER_NAME)==='sqlite'?'':' FOR UPDATE';
            $q=$db->prepare('SELECT user_id FROM messenger_mobile_grants WHERE id=? AND revoked_at IS NULL'.$lock);$q->execute([$grant]);
            if((int)$q->fetchColumn()!==(int)$actor->id)throw new WirePermissionException('Native connection unavailable.');
            $q=$db->prepare('SELECT user_id FROM messenger_client_diagnostics WHERE event_id=? AND grant_hash=?'.$lock);$q->execute([str_repeat('0',32),str_repeat('0',64)]);
            if($q->fetchColumn()===false)throw new WireException('Diagnostic quota mutex unavailable.');
            $this->execute('DELETE FROM messenger_client_diagnostics WHERE user_id>0 AND created_at<?',[$now-604800]);
            $hash=hash_hmac('sha256',$grant,$this->encryptionKey());$new=[];
            foreach($events as $id=>$event){
                $chat=$event['chat_id']??null;$call=$event['call_id']??null;
                if($call!==null){
                    $q=$db->prepare('SELECT conversation_id,caller_id,callee_id,caller_grant,answer_grant FROM messenger_calls WHERE id=?');$q->execute([$call]);$c=$q->fetch(\PDO::FETCH_ASSOC);
                    if(!$c||!(((int)$c['caller_id']===(int)$actor->id&&$c['caller_grant']===$grant)||((int)$c['callee_id']===(int)$actor->id&&($c['answer_grant']===$grant||($c['answer_grant']===null&&$this->scalar('SELECT COUNT(*) FROM messenger_call_pushes WHERE call_id=? AND grant_id=?',[$call,$grant])))))||($chat!==null&&(int)$c['conversation_id']!==$chat))throw new WirePermissionException('Diagnostic context unavailable.');
                }
                if($chat!==null){
                    $q=$db->prepare('SELECT c.type FROM messenger_conversations c JOIN messenger_participants p ON p.conversation_id=c.id AND p.user_id=? WHERE c.id=?');$q->execute([(int)$actor->id,$chat]);$type=$q->fetchColumn();
                    if($type===false)throw new WirePermissionException('Diagnostic context unavailable.');
                    if($type==='group'&&!$this->scalar('SELECT COUNT(*) FROM messenger_group_members WHERE conversation_id=? AND user_id=? AND active=1',[$chat,(int)$actor->id]))throw new WirePermissionException('Diagnostic context unavailable.');
                }
                $payload=json_encode(['platform'=>$body['platform'],'event'=>$event],JSON_THROW_ON_ERROR);$aad='Messenger|client-diagnostic|'.$hash.'|'.$id.'|'.(int)$actor->id;
                $q=$db->prepare('SELECT payload FROM messenger_client_diagnostics WHERE event_id=? AND grant_hash=?');$q->execute([$id,$hash]);$old=$q->fetchColumn();
                if($old!==false){if(!hash_equals($payload,$this->decryptValue($old,$aad)))throw new MessengerMobileException('idempotency_conflict','Diagnostic event conflict.',409);continue;}
                $new[]=[$id,$hash,(int)$actor->id,$this->encryptValue($payload,$aad),$now];
            }
            if($this->scalar('SELECT COUNT(*) FROM messenger_client_diagnostics WHERE user_id=? AND created_at>=?',[(int)$actor->id,$now-86400])+count($new)>5000||$this->scalar('SELECT COUNT(*) FROM messenger_client_diagnostics WHERE user_id>0')+count($new)>100000)throw new MessengerMobileException('rate_limited','Diagnostic quota reached.',429);
            foreach($new as $row)$this->execute('INSERT INTO messenger_client_diagnostics VALUES(?,?,?,?,?)',$row);
            return ['accepted'=>array_keys($events),'retention_days'=>7];
        });
    }
    private function validateClientDiagnostic($e,int $now):array {
        if(!is_array($e)||array_diff(array_keys($e),['id','name','time_ms','screen','chat_id','call_id','values'])||!is_string($e['id']??null)||!preg_match('/^[a-f0-9]{32}$/D',$e['id'])||$e['id']===str_repeat('0',32)||!in_array($e['name']??null,self::DIAGNOSTIC_EVENTS,true)||!is_int($e['time_ms']??null)||$e['time_ms']<($now-86400)*1000||$e['time_ms']>($now+300)*1000)throw new \InvalidArgumentException('Invalid diagnostic event.');
        foreach(['screen','chat_id','call_id','values'] as $optional)if(array_key_exists($optional,$e)&&$e[$optional]===null)throw new \InvalidArgumentException('Invalid diagnostic field.');
        $v=['id'=>$e['id'],'name'=>$e['name'],'time_ms'=>$e['time_ms']];
        if(isset($e['screen'])){if(!in_array($e['screen'],['chats','calls','contacts','settings','conversation','requests','assistant','connect','group_picker'],true))throw new \InvalidArgumentException('Invalid diagnostic screen.');$v['screen']=$e['screen'];}
        if(isset($e['chat_id'])){if(!is_int($e['chat_id'])||$e['chat_id']<=0)throw new \InvalidArgumentException('Invalid diagnostic chat.');$v['chat_id']=$e['chat_id'];}
        if(isset($e['call_id'])){if(!is_string($e['call_id'])||!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D',$e['call_id']))throw new \InvalidArgumentException('Invalid diagnostic call.');$v['call_id']=$e['call_id'];}
        $limits=['rtt_ms'=>60000,'jitter_ms'=>10000,'loss_percent'=>100,'bytes_sent'=>1000000000000,'bytes_received'=>1000000000000,'elapsed_ms'=>86400000,'file_bytes'=>8388608,'attempt'=>100,'enabled'=>1,'quality'=>3,'error_code'=>9];
        $values=$e['values']??[];if(!is_array($values)||count($values)>8)throw new \InvalidArgumentException('Invalid diagnostic metrics.');
        foreach($values as $key=>$number)if(!isset($limits[$key])||(!is_int($number)&&!is_float($number))||!is_finite((float)$number)||$number<0||$number>$limits[$key])throw new \InvalidArgumentException('Invalid diagnostic metric.');
        ksort($values);if($values)$v['values']=$values;return $v;
    }
    public function pruneClientDiagnostics():void {if($this->clientDiagnosticsReady())$this->execute('DELETE FROM messenger_client_diagnostics WHERE user_id>0 AND created_at<?',[time()-604800]);}
    /** Explicit private export; administration alone does not grant diagnostic access. */
    public function clientDiagnostics(User $actor,array $after=[],int $limit=100):array {
        if(!$this->canAdmin($actor)||(!$actor->isSuperuser()&&!$actor->hasPermission('messenger-export')))throw new WirePermissionException('Administrator and export access required.');
        if(($after&&(!isset($after['time'],$after['id'],$after['grant'])||count($after)!==3||!is_int($after['time'])||$after['time']<0||!preg_match('/^[a-f0-9]{32}$/D',(string)$after['id'])||!preg_match('/^[a-f0-9]{64}$/D',(string)$after['grant'])))||$limit<1||$limit>200)throw new \InvalidArgumentException('Invalid diagnostic export.');
        if(!$this->clientDiagnosticsReady())return [];
        $this->execute('DELETE FROM messenger_client_diagnostics WHERE user_id>0 AND created_at<?',[time()-604800]);
        $q=$this->wire('database')->prepare('SELECT * FROM messenger_client_diagnostics WHERE user_id>0'.($after?' AND (created_at>? OR (created_at=? AND event_id>?) OR (created_at=? AND event_id=? AND grant_hash>?))':'').' ORDER BY created_at,event_id,grant_hash LIMIT '.$limit);$q->execute($after?[$after['time'],$after['time'],$after['id'],$after['time'],$after['id'],$after['grant']]:[]);$rows=[];
        foreach($q->fetchAll(\PDO::FETCH_ASSOC) as $r)$rows[]=['cursor'=>['time'=>(int)$r['created_at'],'id'=>$r['event_id'],'grant'=>$r['grant_hash']],'user_id'=>(int)$r['user_id'],'received_at'=>(int)$r['created_at'],'data'=>json_decode($this->decryptValue($r['payload'],'Messenger|client-diagnostic|'.$r['grant_hash'].'|'.$r['event_id'].'|'.$r['user_id']),true,512,JSON_THROW_ON_ERROR)];
        return $rows;
    }
}
