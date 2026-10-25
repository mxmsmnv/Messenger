<?php namespace ProcessWire;

trait MessengerMediaCatalog {
    /** Authorized history only; empty intermediate scans retain an exclusive cursor. */
    public function conversationMedia(User $actor,int $conversationId,string $category,?int $beforeId=null,int $limit=30,string $query=''):array {
        $this->assertActor($actor);
        if(!$this->conversation($conversationId,$actor))throw new WirePermissionException('Conversation unavailable.');
        if(!in_array($category,['visual','documents','audio','links'],true))throw new WireException('Invalid media category.');
        $query=trim($query);if($query!==''&&(mb_strlen($query)<2||mb_strlen($query)>128))throw new WireException('Search requires 2 to 128 characters.');
        if($beforeId!==null&&$beforeId<1)throw new WireException('Invalid media cursor.');
        $limit=max(1,min(50,$limit));$items=[];$scanned=0;$cursor=$beforeId;$complete=false;
        while($scanned<500){
            $batchSize=min(100,500-$scanned);
            $batch=array_reverse($this->conversationMessages($conversationId,$actor,$cursor,null,$batchSize));
            if(!$batch){$complete=true;break;}
            foreach($batch as $index=>$message){
                $scanned++;$cursor=(int)$message['id'];
                if(empty($message['deleted_at'])&&($message['moderation_state']??'visible')==='visible'&&$this->catalogMatches($message,$category)&&($query===''||mb_stripos((string)$message['body'],$query)!==false||mb_stripos((string)($message['attachment']['filename']??''),$query)!==false))$items[]=$message;
                if(count($items)>=$limit){
                    $complete=$index===count($batch)-1&&count($batch)<$batchSize;
                    return ['category'=>$category,'items'=>$items,'next_before'=>$complete?null:$cursor,'scanned'=>$scanned,'complete'=>$complete];
                }
            }
            if(count($batch)<$batchSize){$complete=true;break;}
        }
        return ['category'=>$category,'items'=>$items,'next_before'=>$complete?null:$cursor,'scanned'=>$scanned,'complete'=>$complete];
    }
    private function catalogMatches(array $message,string $category):bool {
        $file=$message['attachment']??null;$mime=strtolower((string)($file['mime_type']??''));
        $visual=$file&&(str_starts_with($mime,'image/')||str_starts_with($mime,'video/'));
        $audio=$file&&(($file['kind']??'')==='voice'||str_starts_with($mime,'audio/'));
        return match($category){
            'visual'=>(bool)$visual,
            'audio'=>(bool)$audio,
            'documents'=>(bool)$file&&!$visual&&!$audio,
            'links'=>preg_match('~https?://[^\s<>"\x00-\x20]+~iu',(string)($message['body']??''))===1,
            default=>false,
        };
    }
}
