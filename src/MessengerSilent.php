<?php namespace ProcessWire;

/** Per-message notification mode; reuses the explicitly installed optional marker table. */
trait MessengerSilent {
    private ?bool $notificationMarkersPresent=null;
    public function silentMessagesReady():bool {return $this->forwardingReady();}
    private function notificationMarkerTable():bool {
        if($this->notificationMarkersPresent!==null)return $this->notificationMarkersPresent;
        try{$this->wire('database')->query('SELECT message_id,kind FROM messenger_message_markers LIMIT 0');return $this->notificationMarkersPresent=true;}
        catch(\PDOException $e){
            if((string)$e->getCode()==='42S02'||(int)($e->errorInfo[1]??0)===1146||str_contains($e->getMessage(),'no such table: messenger_message_markers'))return $this->notificationMarkersPresent=false;
            throw new \RuntimeException('Message notification metadata unavailable.',0,$e);
        }
    }
    private function markerFlags(?string $kind):array {
        return match($kind){null=>[false,false],'forwarded'=>[true,false],'silent'=>[false,true],'forwarded_silent'=>[true,true],default=>throw new \RuntimeException('Message notification metadata unavailable.')};
    }
    private function messageIsSilent(int $id):bool {
        if(!$this->notificationMarkerTable())return false;
        $q=$this->wire('database')->prepare('SELECT kind FROM messenger_message_markers WHERE message_id=?');$q->execute([$id]);$kind=$q->fetchColumn();
        return $this->markerFlags($kind===false?null:(string)$kind)[1];
    }
    private function markMessageFlags(int $id,bool $forwarded,bool $silent):void {
        if(!$forwarded&&!$silent)return;
        if(!$this->notificationMarkerTable())throw new WirePermissionException('Message notification mode unavailable.');
        $db=$this->wire('database');$q=$db->prepare('SELECT kind FROM messenger_message_markers WHERE message_id=?');$q->execute([$id]);$old=$q->fetchColumn();
        [$f,$s]=$this->markerFlags($old===false?null:(string)$old);$f=$f||$forwarded;$s=$s||$silent;$kind=$f?($s?'forwarded_silent':'forwarded'):'silent';
        if($old===false)$db->prepare('INSERT INTO messenger_message_markers(message_id,kind) VALUES(?,?)')->execute([$id,$kind]);
        elseif($old!==$kind)$db->prepare('UPDATE messenger_message_markers SET kind=? WHERE message_id=?')->execute([$kind,$id]);
    }
    private function assertSilentMode(bool $silent):void {if($silent&&!$this->silentMessagesReady())throw new WirePermissionException('Sending without notification is unavailable.');}
    private function notificationRequestBody(string $body,bool $silent):string {return $silent?json_encode(['Messenger-silent-v1',$body],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES):$body;}
    private function silentPresentation(array $messages):array {
        if(!$messages||!$this->notificationMarkerTable())return $messages;
        $ids=array_map(static fn(array $m):int=>(int)$m['id'],$messages);
        $q=$this->wire('database')->prepare('SELECT message_id,kind FROM messenger_message_markers WHERE message_id IN ('.implode(',',array_fill(0,count($ids),'?')).')');$q->execute($ids);
        $flags=[];foreach($q->fetchAll(\PDO::FETCH_ASSOC)as$row)$flags[(int)$row['message_id']]=$this->markerFlags((string)$row['kind'])[1];
        foreach($messages as&$message)$message['silent']=$flags[(int)$message['id']]??false;
        return $messages;
    }
}
