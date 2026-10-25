<?php namespace ProcessWire;

/** Best-effort encrypted cache metadata; never a message, draft, audio or audit record. */
trait MessengerActivity {
    public function activityReady():bool {
        $cache=$this->wire('cache');return is_object($cache)&&method_exists($cache,'get')&&method_exists($cache,'save')&&method_exists($cache,'delete');
    }
    private function activityChat(User $actor,int $id):array {
        $this->assertActor($actor);$chat=$this->conversation($id,$actor);
        if(!$chat)throw new WirePermissionException('Conversation unavailable.');
        if(!$this->activityReady())throw new WireException('Conversation activity unavailable.');
        if(($chat['type']??'direct')==='group'){
            $q=$this->wire('database')->prepare('SELECT user_id,joined_after_id,joined_at FROM messenger_group_members WHERE conversation_id=? AND active=1 ORDER BY user_id LIMIT 101');$q->execute([$id]);$members=$q->fetchAll(\PDO::FETCH_ASSOC);
            if(count($members)>100)throw new WireException('Conversation activity unavailable.');
            $chat['activity_context']=hash('sha256',json_encode($members,JSON_THROW_ON_ERROR));
        }
        return $chat;
    }
    private function activityAllowed(array $chat):bool {
        return ($chat['state']??'')==='active'&&($chat['request_state']??'')==='accepted'&&($chat['type']??'direct')!=='saved'&&empty($chat['blocked'])&&!empty($chat['can_send']);
    }
    private function activityKey(array $chat,int $actor):string {
        return 'MessengerActivity_v1_'.hash_hmac('sha256',(int)$chat['id'].'|'.$actor.'|'.(int)($chat['group_version']??0).'|'.($chat['activity_context']??''),$this->encryptionKey());
    }
    private function activityVisible(int $user):bool {
        return !in_array($this->memberPresence($user,true)['status'],['invisible','offline'],true);
    }
    private function activityValue(string $key):?array {
        try{
            $cipher=$this->wire('cache')->get($key);if(!is_string($cipher)||$cipher==='')return null;
            $row=json_decode($this->decryptValue($cipher,'Messenger|activity|'.$key),true,8,JSON_THROW_ON_ERROR);
            $now=time();
            if(!is_array($row)||!in_array($row['kind']??null,['typing','recording'],true)||!is_int($row['sent_at']??null)||!is_int($row['expires_at']??null)||$row['sent_at']>$now||$row['expires_at']<=$now||$row['expires_at']-$row['sent_at']!==8)return null;
            return $row;
        }catch(\Throwable $e){return null;}
    }
    public function publishConversationActivity(User $actor,int $id,string $kind):array {
        if(!in_array($kind,['typing','recording','none'],true))throw new WireException('Invalid activity kind.');
        $chat=$this->activityChat($actor,$id);$key=$this->activityKey($chat,(int)$actor->id);$cache=$this->wire('cache');
        if($kind==='none'||!$this->activityAllowed($chat)||!$this->activityVisible((int)$actor->id)){$cache->delete($key);return ['updated'=>true];}
        $now=time();$old=$this->activityValue($key);
        if($old&&$old['kind']===$kind&&$now-$old['sent_at']<2)return ['updated'=>true];
        $row=['kind'=>$kind,'sent_at'=>$now,'expires_at'=>$now+8];
        if(!$cache->save($key,$this->encryptValue(json_encode($row,JSON_THROW_ON_ERROR),'Messenger|activity|'.$key),$now+8))throw new WireException('Conversation activity unavailable.');
        return ['updated'=>true];
    }
    public function conversationActivity(User $actor,int $id):array {
        $chat=$this->activityChat($actor,$id);if(!$this->activityAllowed($chat))return ['items'=>[]];
        if(($chat['type']??'direct')==='group'){
            $q=$this->wire('database')->prepare('SELECT p.user_id FROM messenger_participants p JOIN messenger_group_members gm ON gm.conversation_id=p.conversation_id AND gm.user_id=p.user_id AND gm.active=1 WHERE p.conversation_id=? AND p.user_id<>? ORDER BY p.user_id LIMIT 100');$q->execute([$id,(int)$actor->id]);$ids=$q->fetchAll(\PDO::FETCH_COLUMN);
        }else $ids=[(int)$chat['other_user']['id']];
        $items=[];foreach($ids as $userId){
            $userId=(int)$userId;if($userId<1||$userId===(int)$actor->id||$this->isBlockedEitherWay((int)$actor->id,$userId))continue;
            $peer=$this->wire('users')->get($userId);if(!$peer instanceof User||!$this->canReceive($peer)||!$this->activityVisible($userId))continue;
            $row=$this->activityValue($this->activityKey($chat,$userId));if(!$row)continue;
            $items[]=['user_id'=>$userId,'name'=>mb_substr((string)$peer->name,0,120),'title'=>mb_substr((string)($peer->get('display_name')?:$peer->get('title')?:$peer->name),0,120),'kind'=>$row['kind'],'expires_in'=>max(1,min(8,$row['expires_at']-time()))];
            if(count($items)===20)break;
        }
        return ['items'=>$items];
    }
}
