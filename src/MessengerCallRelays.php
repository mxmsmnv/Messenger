<?php namespace ProcessWire;

/** Operator-owned relay pool. Secrets never enter ordinary module configuration. */
trait MessengerCallRelays {
    private function callRelaySchemaReady():bool {
        try{return $this->wire('database')->query('SELECT slot FROM messenger_call_relays LIMIT 0')!==false;}catch(\PDOException $e){return false;}
    }
    private function configuredCallRelays():array {
        if($this->callRelaySchemaReady()){
            $rows=$this->wire('database')->query('SELECT slot,payload FROM messenger_call_relays ORDER BY slot')->fetchAll(\PDO::FETCH_ASSOC);
            if($rows){$pool=[];foreach($rows as $row)if((int)$row['slot']>0)$pool[(int)$row['slot']-1]=json_decode($this->decryptValue($row['payload'],'Messenger|call-relay|'.$row['slot']),true,512,JSON_THROW_ON_ERROR);return $pool;}
        }
        $c=$this->callsConfig();
        return !empty($c['turn_urls'])&&strlen((string)($c['turn_secret']??''))>=32?[['urls'=>$c['turn_urls'],'secret'=>$c['turn_secret'],'label'=>'Website relay']]:[];
    }
    private function validateCallRelayUrls(array $urls):array {
        if(count($urls)>4)throw new \InvalidArgumentException('Use up to four URLs per relay.');
        foreach($urls as $url){
            if(!is_string($url)||strlen($url)>255||!preg_match('/^turns?:([a-zA-Z0-9.-]+|\[[a-fA-F0-9:]+\])(?::([0-9]{1,5}))?(?:\?transport=(udp|tcp))?$/D',$url,$m))throw new \InvalidArgumentException('Use TURN URLs without embedded credentials or paths.');
            if(isset($m[2])&&$m[2]!==''&&((int)$m[2]<1||(int)$m[2]>65535))throw new \InvalidArgumentException('Invalid relay port.');
        }
        return array_values(array_unique($urls));
    }
    private function callStunUrls():array {
        if($this->callRelaySchemaReady()){
            $q=$this->wire('database')->query('SELECT payload FROM messenger_call_relays WHERE slot=0');$payload=$q->fetchColumn();
            if($payload!==false)return json_decode($this->decryptValue($payload,'Messenger|call-relay|0'),true,512,JSON_THROW_ON_ERROR)['stun_urls']??[];
        }
        $c=$this->callsConfig();
        if(isset($c['stun_urls']))return $this->validateStunUrls($c['stun_urls']);
        // Discover public candidates through the already configured operator's
        // coturn; never silently introduce a third-party public STUN provider.
        $urls=[];foreach($c['turn_urls']??[]as$url)if(preg_match('/^turn(s?):(.+?)(?:\?transport=(?:udp|tcp))?$/D',$url,$m))$urls[]='stun'.$m[1].':'.$m[2];
        return $this->validateStunUrls(array_values(array_unique($urls)));
    }
    private function validateStunUrls(array $urls):array {
        $mapped=[];foreach($urls as$url){if(!is_string($url)||!str_starts_with($url,'stun'))throw new \InvalidArgumentException('Invalid STUN URL.');$mapped[]=preg_replace('/^stun/','turn',$url);}
        $this->validateCallRelayUrls($mapped);return array_values(array_unique($urls));
    }
    private function callIcePool(array $row,string $grant):array {
        $username=((int)$row['created_at']+7500).':'.hash('sha256',$grant.'|'.$row['id']);$servers=[];
        foreach($this->configuredCallRelays() as $relay){
            $urls=$this->validateCallRelayUrls($relay['urls']);$secret=(string)$relay['secret'];
            if(strlen($secret)<32)throw new \RuntimeException('Invalid relay credentials.');
            if($urls)$servers[]=['urls'=>$urls,'username'=>$username,'credential'=>base64_encode(hash_hmac('sha1',$username,$secret,true))];
        }
        $stun=$this->callStunUrls();if($stun)$servers[]=['urls'=>$stun,'username'=>'','credential'=>''];
        return array_merge($servers[0]??['urls'=>[],'username'=>'','credential'=>''],['ice_servers'=>$servers,'expires_in'=>max(0,(int)$row['created_at']+7500-time())]);
    }
    public function beforeSaveCallRelays(HookEvent $event):void {
        if(!$this->isMessengerConfigSave($event))return;
        $data=$event->arguments(1);if(!is_array($data))return;
        // Strip every transient field before any later hook/config persistence, even on error.
        $input=$data;
        foreach(array_keys($data)as$key)if(str_starts_with($key,'call_relay_')||$key==='call_relays_apply')unset($data[$key]);
        $event->arguments(1,$data);
        if(empty($input['call_relays_apply']))return;
        if(!$this->canAdmin($this->wire('user')))throw new WirePermissionException('Administrator required.');
        if(!$this->callRelaySchemaReady())throw new WireException('Run the explicit experimental calls setup before editing relays.');
        $stun=$this->validateStunUrls(preg_split('/\s+/',trim((string)($input['call_relay_stun_urls']??'')),-1,PREG_SPLIT_NO_EMPTY)?:[]);
        $pool=$this->configuredCallRelays();$replacement=[];
        for($slot=1;$slot<=5;$slot++){
            $text=trim((string)($input['call_relay_'.$slot.'_urls']??''));if($text==='')continue;
            $urls=$this->validateCallRelayUrls(preg_split('/\s+/',$text,-1,PREG_SPLIT_NO_EMPTY)?:[]);
            $secret=trim((string)($input['call_relay_'.$slot.'_secret']??''));
            if($secret==='')$secret=(string)($pool[$slot-1]['secret']??'');
            if(strlen($secret)<32||strlen($secret)>256)throw new WireException('Each relay needs its coturn REST shared secret (32–256 characters). Blank keeps the saved secret.');
            $replacement[$slot]=['label'=>mb_substr(trim((string)($input['call_relay_'.$slot.'_label']??'')),0,64),'urls'=>$urls,'secret'=>$secret];
        }
        $this->atomic(function()use($replacement,$stun){
            $this->execute('DELETE FROM messenger_call_relays',[]);
            // Sentinel explicitly distinguishes direct-only from legacy private configuration.
            $this->execute('INSERT INTO messenger_call_relays(slot,payload) VALUES(?,?)',[0,$this->encryptValue(json_encode(['stun_urls'=>$stun],JSON_THROW_ON_ERROR),'Messenger|call-relay|0')]);
            foreach($replacement as$slot=>$value)$this->execute('INSERT INTO messenger_call_relays(slot,payload) VALUES(?,?)',[$slot,$this->encryptValue(json_encode($value,JSON_THROW_ON_ERROR),'Messenger|call-relay|'.$slot)]);
        });
    }
    private function callRelayInputfields(InputfieldWrapper $mobile):void {
        $modules=$this->wire('modules');
        $toggle=$modules->get('InputfieldCheckbox');$toggle->name='call_relays_apply';$toggle->label='Update call relay pool';$toggle->description='Advanced: up to five coturn servers using REST shared-secret authentication. All empty selects direct connections only. Push setup remains independent. Check this box to apply; otherwise existing configuration is preserved.';$mobile->add($toggle);
        $stun=$modules->get('InputfieldTextarea');$stun->name='call_relay_stun_urls';$stun->label='STUN discovery URLs (optional)';$stun->description='One STUN/STUNS URL per line. Discovers public addresses for direct connections without relaying audio. Empty disables STUN. Existing coturn is used by default, never a third-party provider.';$stun->showIf='call_relays_apply=1';$stun->value=implode("\n",$this->callStunUrls());$mobile->add($stun);
        $pool=$this->configuredCallRelays();
        for($slot=1;$slot<=5;$slot++){
            $relay=$pool[$slot-1]??[];
            foreach(['label'=>'Relay '.$slot.' name','urls'=>'Relay '.$slot.' URLs','secret'=>'Relay '.$slot.' REST shared secret']as$key=>$label){
                $field=$modules->get($key==='secret'?'InputfieldPassword':($key==='urls'?'InputfieldTextarea':'InputfieldText'));
                $field->name='call_relay_'.$slot.'_'.$key;$field->label=$label;$field->showIf='call_relays_apply=1';
                if($key==='secret'){$field->attr('autocomplete','new-password');$field->description='Never displayed. Leave empty to retain the existing secret for this slot.';}
                else $field->value=$key==='urls'?implode("\n",$relay['urls']??[]):($relay['label']??'');
                $mobile->add($field);
            }
        }
    }
}
