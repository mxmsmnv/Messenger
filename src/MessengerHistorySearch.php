<?php namespace ProcessWire;

trait MessengerHistorySearch {
    /** Bounded encrypted-history scan. Every batch uses the normal message access boundary. */
    public function searchConversationHistory(User $actor,int $conversationId,string $query,?int $beforeId=null,int $limit=30):array {
        $this->assertActor($actor);
        if(!$this->conversation($conversationId,$actor))throw new WirePermissionException('Conversation unavailable.');
        $query=trim($query);
        if(mb_strlen($query)<2||mb_strlen($query)>128)throw new WireException('Search requires 2 to 128 characters.');
        if($beforeId!==null&&$beforeId<1)throw new WireException('Invalid search cursor.');
        $limit=max(1,min(50,$limit));$items=[];$scanned=0;$cursor=$beforeId;$complete=false;
        while($scanned<500){
            $batchSize=min(100,500-$scanned);
            $batch=array_reverse($this->conversationMessages($conversationId,$actor,$cursor,null,$batchSize));
            if(!$batch){$complete=true;break;}
            foreach($batch as $index=>$message){
                $scanned++;$cursor=(int)$message['id'];
                if(empty($message['deleted_at'])&&($message['moderation_state']??'visible')==='visible'&&mb_stripos((string)$message['body'],$query)!==false)$items[]=$message;
                if(count($items)>=$limit){
                    $complete=$index===count($batch)-1&&count($batch)<$batchSize;
                    return ['items'=>$items,'next_before'=>$complete?null:$cursor,'scanned'=>$scanned,'complete'=>$complete];
                }
            }
            if(count($batch)<$batchSize){$complete=true;break;}
        }
        return ['items'=>$items,'next_before'=>$complete?null:$cursor,'scanned'=>$scanned,'complete'=>$complete];
    }
}
