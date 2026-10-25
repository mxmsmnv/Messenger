<?php namespace ProcessWire;

trait MessengerSavedMessages {
    public function savedMessagesReady(): bool { return (bool)$this->saved_messages_enabled; }

    private function savedOwnerAllowed(array $chat,User $actor):bool {
        if(!$this->savedMessagesReady()||($chat['state']??'')!=='active'||($chat['type']??'')!=='saved'||(int)$chat['requester_id']!==(int)$actor->id||(int)$chat['recipient_id']!==(int)$actor->id)return false;
        return $this->scalar('SELECT COUNT(*) FROM messenger_participants WHERE conversation_id=?',[(int)$chat['id']])===1&&
            $this->scalar('SELECT COUNT(*) FROM messenger_participants WHERE conversation_id=? AND user_id=?',[(int)$chat['id'],(int)$actor->id])===1;
    }

    /** An explicit, idempotent member action. Opening never restores hidden history. */
    public function openSavedConversation(User $actor):array {
        $this->assertActor($actor);
        if(!$this->savedMessagesReady())throw new WirePermissionException('Saved messages unavailable.');
        $key=hash('sha256','Messenger|saved-conversation|v1|'.(int)$actor->id);
        $operation=function()use($actor,$key):array {
            $db=$this->wire('database');$q=$db->prepare('SELECT id FROM messenger_conversations WHERE direct_key=?');$q->execute([$key]);$id=(int)$q->fetchColumn();
            if(!$id){
                $now=$this->now();$q=$db->prepare("INSERT INTO messenger_conversations(public_key,direct_key,type,state,request_state,requester_id,recipient_id,last_message_id,last_message_at,created_at,updated_at) VALUES(?,?,'saved','active','accepted',?,?,0,NULL,?,?)");
                $q->execute([bin2hex(random_bytes(16)),$key,(int)$actor->id,(int)$actor->id,$now,$now]);$id=(int)$db->lastInsertId();
                $this->execute("INSERT INTO messenger_participants(conversation_id,user_id,role,last_read_message_id,archived_at,muted_until,hidden_before,email_mode,created_at) VALUES(?,?,'member',0,NULL,NULL,NULL,'off',?)",[$id,(int)$actor->id,$now]);
                $this->audit('saved_conversation_created',$id,0,(int)$actor->id);
            }
            $chat=$this->conversation($id,$actor);if(!$chat||($chat['type']??'')!=='saved')throw new WirePermissionException('Saved messages unavailable.');
            return $chat;
        };
        try{return $this->atomic($operation);}catch(\PDOException $e){if(!in_array((string)$e->getCode(),['23000','23505'],true))throw $e;return $this->atomic($operation);}
    }

    private function decorateSavedConversation(array $row,User $actor):array {
        if(!$this->savedOwnerAllowed($row,$actor))return [];
        // Reuse the ordinary private-history presentation, then remove peer-only semantics.
        $row['type']='direct';$row=$this->decorateConversation($row,$actor);$row['type']='saved';
        $row['can_send']=$row['state']==='active'&&$this->canUse($actor);$row['can_attach']=$row['can_send'];
        $row['blocked']=$row['blocked_by_actor']=$row['blocked_by_other']=false;$row['unread_count']=0;$row['other_last_read_message_id']=0;
        $row['other_user']=['id'=>(int)$actor->id,'name'=>(string)$actor->name,'title'=>$this->_('Saved messages'),'presence'=>['status'=>'offline','text'=>'','expires_at'=>0]];
        return $row;
    }
}
