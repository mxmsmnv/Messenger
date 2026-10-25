<?php namespace ProcessWire;

/** Same-origin JSON transport for the Messenger frontend. */
final class MessengerRestApi extends Wire {
	private const MAX_BODY_BYTES = 1048576;
	private const RATE_SESSION_KEY = 'MessengerRestRate';
	private Messenger $messenger;

	public function __construct(Messenger $messenger) { $this->messenger = $messenger; }

	public function handle(string $version, string $resource): string {
		$this->headers();
		$originalActor = $this->wire()->user;
		$native = false;
		try {
			if(strtolower(trim($version)) !== Messenger::REST_API_VERSION) return $this->response(404, null, 'Not found.');
			$resource = strtolower(trim($resource));
			if(!preg_match('/^[a-z-]{2,32}$/', $resource)) return $this->response(404, null, 'Not found.');
			$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
			$actor = $this->wire()->user;
			$authorization = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
			if(preg_match('/^Bearer(?:\\s|$)/i', $authorization)) {
				if(!empty($_SERVER['HTTP_COOKIE'])) throw new MessengerMobileException('mixed_credentials', 'Native requests must not include cookies.');
				$auth = new MessengerMobileAuth($this->messenger);
				$auth->assertTransport();
				$actor = $auth->authenticate($authorization);
				$native = true;
				$this->wire()->users->setCurrentUser($actor);
				$auth->rateLimit($method === 'GET' ? 'reads' : 'writes', (string)$actor->id, $method === 'GET' ? 180 : 60);
			}
			$expectedActor=$_SERVER['HTTP_X_MESSENGER_ACTOR']??'';
			if($expectedActor!==''&&(!is_string($expectedActor)||!preg_match('/^[1-9][0-9]{0,18}$/D',$expectedActor)||(string)(int)$expectedActor!==$expectedActor||!$actor instanceof User||(int)$actor->id!==(int)$expectedActor))throw new WirePermissionException('Account changed. Reload Messenger.');
			if($resource === 'session') { $this->allow($method, ['GET']); return $this->sessionResponse($actor, $native); }
			if(!$actor instanceof User || !$this->messenger->canUse($actor)) throw new WirePermissionException('Messenger access denied.');
			if(!$native) $this->rateLimit($method !== 'GET');
			$body = $method === 'POST' ? $this->jsonBody($resource) : [];
			if($method === 'POST' && !$native) $this->validateCsrf($body);

            if(in_array($resource,['devices','revoke-device','revoke-other-devices'],true)) {
                if(!$native)throw new WirePermissionException('Native connection required.');
                $grant=$auth->authenticatedGrant($authorization);
                if($resource==='devices'){$this->allow($method,['GET']);$items=$auth->devices($actor,$grant,101);return $this->response(200,['items'=>array_slice($items,0,100),'has_more'=>count($items)>100]);}
                $this->allow($method,['POST']);
                if($resource==='revoke-other-devices')return $this->response(200,['revoked'=>$auth->revokeOtherDevices($actor,$grant),'current_revoked'=>false]);
                $id=$body['connection_id']??null;
                if(!is_string($id)||!preg_match('/^[a-f0-9]{32}$/D',$id))throw new \InvalidArgumentException('A connection ID is required.');
                $auth->revokeDevice($actor,$id);return $this->response(200,['revoked'=>true,'current_revoked'=>hash_equals($grant,$id)]);
            }
            if($resource==='message-push-device') {
                if(!$native)throw new WirePermissionException('Native connection required.');
                return $this->response(200,$this->messenger->messagePushDevice($actor,$auth->authenticatedGrant($authorization),$method,$body));
            }
            if($resource==='call-events') {
                if(!$native)throw new WirePermissionException('Native connection required.');
                $this->allow($method,['GET']);
                $id=$this->nullablePositiveQueryInt('id');if($id===null)throw new \InvalidArgumentException('Conversation required.');
                return $this->response(200,$this->messenger->conversationCallEvents($actor,$id));
            }
            if(in_array($resource,['call-config','call-device','call-start','call-inbox','call-state','call-ice','call-signal','call-feedback'],true)) {
                if(!$native)throw new WirePermissionException('Native connection required.');
                $result=$this->messenger->experimentalCallRequest($actor,$auth->authenticatedGrant($authorization),$resource,$method,$body,['id'=>$this->wire()->input->get('id'),'after'=>$this->wire()->input->get('after')]);
                return $this->response(200,$result);
            }
			if(str_starts_with($resource,'group-'))return $this->response(200,$this->messenger->groupRequest($actor,$resource,$method,$body,['id'=>$this->wire()->input->get('id'),'message_id'=>$this->wire()->input->get('message_id'),'before'=>$this->wire()->input->get('before')]));
            if($resource==='pin-chat'){
                $this->allow($method,['POST']);
                if(!is_int($body['id']??null)||!is_bool($body['pinned']??null))throw new \InvalidArgumentException('Conversation and exact desired pin state required.');
                return $this->response(200,$this->messenger->pinChat($actor,$body['id'],$body['pinned']));
            }
            if($resource==='pin-message'){
                $this->allow($method,['POST']);
                if(!is_int($body['id']??null)||!is_int($body['message_id']??null)||!is_bool($body['pinned']??null)||!is_string($body['scope']??null))throw new \InvalidArgumentException('Message, scope and exact desired pin state required.');
                return $this->response(200,$this->messenger->pinMessage($actor,$body['id'],$body['message_id'],$body['pinned'],$body['scope']));
            }
            if($resource==='pins'){
                $this->allow($method,['GET']);$id=$this->conversationId($actor,$this->identifier());
                return $this->response(200,$this->messenger->pinnedMessages($actor,$id));
            }
            if($resource==='reaction'){
                $this->allow($method,['POST']);
                if(!is_int($body['message_id']??null)||!array_key_exists('emoji',$body)||($body['emoji']!==null&&!is_string($body['emoji'])))throw new \InvalidArgumentException('Message ID and exact desired reaction required.');
                return $this->response(200,$this->messenger->reactToMessage($actor,$body['message_id'],$body['emoji']));
            }
            if($resource==='forward'){
                $this->allow($method,['POST']);
                if(!is_int($body['from_id']??null)||!is_int($body['to_id']??null)||!is_array($body['message_ids']??null)||!is_array($body['source_versions']??null)||!is_string($body['client_id']??null))throw new \InvalidArgumentException('Source, destination, messages and client ID required.');
                return $this->response(200,$this->messenger->forwardMessages($actor,$body['from_id'],$body['to_id'],$body['message_ids'],$body['client_id'],$body['source_versions']));
            }
			switch($resource) {
				case 'client-policy':
					$this->allow($method,['GET']);$result=$this->messenger->clientPolicy($actor);break;
				case 'recipient-context':
					$this->allow($method,['GET']);
					$id=$this->wire()->input->get('user_id');
					if(!is_string($id)||!preg_match('/^[1-9][0-9]{0,18}$/D',$id)||(string)(int)$id!==$id)throw new \InvalidArgumentException('A positive user_id is required.');
					$result=$this->messenger->recipientContext($actor,$this->wire()->users->get((int)$id));break;
				case 'presence':
					$this->allow($method,['GET','POST']);
					if($method==='GET')$result=$this->messenger->presence($actor);
					elseif(array_key_exists('heartbeat',$body)){
						if($body['heartbeat']!==true||array_key_exists('status',$body)||array_key_exists('text',$body))throw new \InvalidArgumentException('Heartbeat must not change presence preferences.');
						$result=$this->messenger->heartbeatPresence($actor);
					}else $result=$this->messenger->setPresence($actor,(string)($body['status']??''),(string)($body['text']??''));break;
				case 'contact':
					$this->allow($method,['POST']);if(!isset($body['user_id'])||!is_int($body['user_id'])||$body['user_id']<1||!isset($body['added'])||!is_bool($body['added']))throw new \InvalidArgumentException('Contact and added flag are required.');
					$result=$body['added']?$this->messenger->setContact($actor,$this->wire()->users->get($body['user_id']),true):$this->messenger->removeContact($actor,$body['user_id']);break;
				case 'retention':
					$this->allow($method,['POST']);$seconds=$body['seconds']??0;$at=$body['at']??null;if(!is_int($seconds)||($at!==null&&!is_int($at)))throw new \InvalidArgumentException('Integer retention times are required.');
					$result=$this->messenger->setConversationRetention($this->bodyConversationId($actor,$body),$actor,$seconds,$at);break;
				case 'message-expiry':
					$this->allow($method,['POST']);if(!isset($body['message_id'])||!is_int($body['message_id'])||!array_key_exists('expires_at',$body)||($body['expires_at']!==null&&!is_int($body['expires_at'])))throw new \InvalidArgumentException('Message and expiry are required.');
					$result=$this->messenger->setMessageExpiry($body['message_id'],$actor,$body['expires_at']);break;
				case 'delete-conversation':
					$this->allow($method,['POST']);$result=$this->messenger->deleteConversationForUser($this->bodyConversationId($actor,$body),$actor);break;
				case 'expire':
					$this->allow($method,['POST']);$result=$this->messenger->sweepExpiredMessages(100,true,$actor);break;
				case 'contact-book':
					$this->allow($method,['GET']);$result=$this->messenger->contactBook($actor,(string)$this->wire()->input->get('q'),$this->queryInt('limit',30,30));break;
				case 'directory':
				case 'contacts':
					$this->allow($method,['GET']);$result=$this->messenger->memberDirectory($actor,(string)$this->wire()->input->get('q'),$this->queryInt('limit',30,30),$resource==='contacts');break;
				case 'send-attachment':
					$this->allow($method,['POST']);$result=$this->messenger->sendAttachment($this->bodyConversationId($actor,$body),$actor,$body,(string)($body['client_id']??''),$this->bodySilent($body));break;
				case 'attachment':
					$this->allow($method,['GET']);$result=$this->messenger->attachment($actor,(int)$this->wire()->input->get('id'));break;
				case 'sync':
					$this->allow($method, ['GET']);
					$result = $this->messenger->syncState($actor, (string)$this->wire()->input->get('revision'));
					break;
				case 'inbox':
					$this->allow($method, ['GET']);
					$scope = (string)$this->wire()->input->get('scope');if(!in_array($scope,['inbox','requests','archived'],true))$scope='inbox';
					$before = trim((string)$this->wire()->input->get('before')) ?: null;
					$result = $this->messenger->conversationsForUser($actor, $scope, $before, $this->queryInt('limit', 30, 100), (string)$this->wire()->input->get('include_groups')==='1',(string)$this->wire()->input->get('include_saved')==='1');
                    if(!$before&&(string)$this->wire()->input->get('include_pins')==='1'&&$this->messenger->pinsReady())$result['pinned_items']=$this->messenger->pinnedChats($actor,$scope,(string)$this->wire()->input->get('include_groups')==='1',(string)$this->wire()->input->get('include_saved')==='1');
					break;
				case 'saved-messages':
					$this->allow($method,['POST']);
					$result=$this->messenger->openSavedConversation($actor);
					break;
				case 'conversation':
					$this->allow($method, ['GET']);
					$result = $this->messenger->conversation($this->identifier(), $actor);
					if(!$result) throw new Wire404Exception('Conversation unavailable.');
					break;
				case 'message-info':
					$this->allow($method,['GET']);
					$id=$this->wire()->input->get('message_id');
					if(!is_string($id)||!preg_match('/^[1-9][0-9]{0,18}$/D',$id)||(string)(int)$id!==$id) throw new \InvalidArgumentException('A positive message_id is required.');
					$result=$this->messenger->messageInfo((int)$id,$actor);break;
				case 'messages':
					$this->allow($method, ['GET']);
					$id = $this->conversationId($actor, $this->identifier());
					$result = $this->messenger->conversationMessages($id, $actor, $this->nullableQueryInt('before'), $this->nullableQueryInt('after'), $this->queryInt('limit', 50, 100));
					break;
				case 'search':
					$this->allow($method, ['GET']);
					$result = $this->messenger->searchMessages($actor, (string)$this->wire()->input->get('q'), $this->queryInt('limit', 30, 50), (string)$this->wire()->input->get('include_groups')==='1');
					break;
                case 'activity':
                    $this->allow($method,['GET','POST']);
                    if($method==='GET')$result=$this->messenger->conversationActivity($actor,$this->conversationId($actor,$this->identifier()));
                    else {
                        $kind=$body['kind']??null;if(!is_string($kind))throw new \InvalidArgumentException('Activity kind is required.');
                        $result=$this->messenger->publishConversationActivity($actor,$this->bodyConversationId($actor,$body),$kind);
                    }
                    break;
				case 'media-catalog':
					$this->allow($method,['GET']);
					$category=$this->wire()->input->get('category');if(!is_string($category))throw new \InvalidArgumentException('A media category is required.');
					$query=$this->wire()->input->get('q');if($query!==null&&!is_string($query))throw new \InvalidArgumentException('Invalid media query.');
					$result=$this->messenger->conversationMedia($actor,$this->conversationId($actor,$this->identifier()),$category,$this->nullablePositiveQueryInt('before'),$this->queryInt('limit',30,50),$query??'');
					break;
				case 'search-history':
					$this->allow($method,['GET']);
					$query=$this->wire()->input->get('q');if(!is_string($query))throw new \InvalidArgumentException('A search query is required.');
					$result=$this->messenger->searchConversationHistory($actor,$this->conversationId($actor,$this->identifier()),$query,$this->nullablePositiveQueryInt('before'),$this->queryInt('limit',30,50));
					break;
				case 'recipients':
					$this->allow($method, ['GET']);
					$result = $this->messenger->searchRecipients($actor, (string)$this->wire()->input->get('q'), $this->queryInt('limit', 10, 20));
					break;
				case 'start':
					$this->allow($method, ['POST']);
					$recipient = $this->wire()->users->get((int)($body['recipient_id'] ?? 0));
					if(!$recipient instanceof User || !$recipient->id) throw new WirePermissionException('Messaging is unavailable.');
					$result = $this->messenger->startConversation($actor, $recipient, $this->bodyText($body), (string)($body['client_id'] ?? ''), (array)($body['context'] ?? []),$this->bodySilent($body));
					break;
				case 'send':
					$this->allow($method, ['POST']);
					$id = $this->bodyConversationId($actor, $body);
					$result = $this->messenger->sendMessage($id, $actor, $this->bodyText($body), (string)($body['client_id'] ?? ''),$this->bodySilent($body));
					break;
				case 'accept':
					$this->allow($method, ['POST']);
					$result = $this->messenger->acceptRequest($this->bodyConversationId($actor, $body), $actor);
					break;
				case 'decline':
					$this->allow($method, ['POST']);
					$this->messenger->declineRequest($this->bodyConversationId($actor, $body), $actor); $result = ['declined' => true];
					break;
				case 'read':
					$this->allow($method, ['POST']);
					$this->messenger->markRead($this->bodyConversationId($actor, $body), $actor, (int)($body['message_id'] ?? 0)); $result = ['read' => true];
					break;
				case 'edit':
					$this->allow($method, ['POST']);
					$result = $this->messenger->editMessage((int)($body['message_id'] ?? 0), $actor, $this->bodyText($body));
					break;
				case 'delete':
					$this->allow($method, ['POST']);
					$this->messenger->deleteMessage((int)($body['message_id'] ?? 0), $actor); $result = ['deleted' => true];
					break;
				case 'hide':
					$this->allow($method, ['POST']);
					$this->messenger->hideMessage((int)($body['message_id'] ?? 0), $actor); $result = ['hidden' => true];
					break;
				case 'archive':
					$this->allow($method, ['POST']);
					$this->messenger->setArchived($this->bodyConversationId($actor, $body), $actor, !empty($body['archived'])); $result = ['archived' => !empty($body['archived'])];
					break;
				case 'mute':
					$this->allow($method, ['POST']);
					if(array_key_exists('muted',$body)){if(!is_bool($body['muted']))throw new \InvalidArgumentException('Muted must be a boolean.');$result=$this->messenger->setConversationMuted($this->bodyConversationId($actor,$body),$actor,$body['muted']);break;}
					$until = isset($body['until']) && $body['until'] !== '' ? (string)$body['until'] : null;
					$this->messenger->setMuted($this->bodyConversationId($actor, $body), $actor, $until); $result = ['muted_until' => $until];
					break;
				case 'notification-preference':
					$this->allow($method, ['POST']);
					$this->messenger->setEmailMode($this->bodyConversationId($actor, $body), $actor, (string)($body['mode'] ?? '')); $result = ['mode' => (string)$body['mode']];
					break;
				case 'block':
					$this->allow($method, ['POST']);
					$target = $this->wire()->users->get((int)($body['user_id'] ?? 0));
					if(!$target instanceof User || !$target->id) throw new WireException('Invalid user.');
					$this->messenger->blockUser($actor, $target, (string)($body['reason'] ?? '')); $result = ['blocked' => true];
					break;
				case 'unblock':
					$this->allow($method, ['POST']);
					$target = $this->wire()->users->get((int)($body['user_id'] ?? 0));
					if(!$target instanceof User || !$target->id) throw new WireException('Invalid user.');
					$this->messenger->unblockUser($actor, $target); $result = ['blocked' => false];
					break;
				case 'report':
					$this->allow($method, ['POST']);
					$result = $this->messenger->reportMessage($actor, (int)($body['message_id'] ?? 0), (string)($body['category'] ?? 'other'), (string)($body['comment'] ?? ''));
					break;
				default:
					return $this->response(404, null, 'Not found.');
			}
            // The core has already validated actor, rights and immutable request hash.
            // Echo correlation only to the native writer, never browser/history reads.
            if($native&&$method==='POST'&&in_array($resource,['start','send','send-attachment'],true)&&is_string($body['client_id']??null)&&trim($body['client_id'])!=='') {
                if($resource==='start')$result['message']=$this->acknowledgeWrite($actor,$result['message'],trim($body['client_id']),$this->bodySilent($body));
                else $result=$this->acknowledgeWrite($actor,$result,trim($body['client_id']),$this->bodySilent($body));
            }
			return $this->response(200, $result);
		} catch(MessengerMobileException $error) {
			if($error->httpStatus === 429) header('Retry-After: 60');
			return $this->response($error->httpStatus, null, $error->getMessage(), $error->apiCode);
		} catch(WirePermissionException $error) {
			return $this->response(403, null, 'Messenger action is not available.');
		} catch(Wire404Exception $error) {
			return $this->response(404, null, 'Conversation unavailable.');
		} catch(MessengerRestException $error) {
			return $this->response($error->status(), null, $error->getMessage());
		} catch(\InvalidArgumentException|WireException $error) {
			return $this->response(400, null, $error->getMessage());
		} catch(\Throwable $error) {
			$this->wire()->log->save('messenger', 'REST request failed (' . get_class($error) . ').');
			return $this->response(500, null, 'Messenger request failed.');
		}
		finally { if($native) $this->wire()->users->setCurrentUser($originalActor); }
	}

    /** Only after a successful core write/replay. Current visibility is independent
     * from original request acceptance, including edits and personal hiding. */
    private function acknowledgeWrite(User $actor,array $message,string $clientId,bool $silent=false):array {
        if(($message['silent']??false)!==$silent)throw new WireException('Invalid write notification acknowledgement.');
        $id=(int)($message['id']??0);$chat=(int)($message['conversation_id']??0);
        if($id<1||$id>=PHP_INT_MAX||$chat<1||(int)($message['sender_user_id']??0)!==(int)$actor->id||!preg_match('/^[A-Za-z0-9_-]{16,64}$/D',$clientId))throw new WireException('Invalid write acknowledgement.');
        $visible=false;
        try {
            $conversation=$this->messenger->conversation($chat,$actor);
            if(($conversation['state']??'')==='active') {
                $rows=$this->messenger->conversationMessages($chat,$actor,$id+1,$id-1,1);
                if(count($rows)===1&&(int)$rows[0]['id']===$id&&(int)$rows[0]['sender_user_id']===(int)$actor->id&&empty($rows[0]['deleted_at'])&&($rows[0]['moderation_state']??'')==='visible'&&(!isset($rows[0]['expires_at'])||(int)$rows[0]['expires_at']>time())) {
                    $message=$rows[0];$visible=true;
                }
            }
        }catch(WirePermissionException|Wire404Exception $e) { /* Accepted, now unavailable. */ }
        if(!$visible)$message=['id'=>$id,'conversation_id'=>$chat,'sender_user_id'=>(int)$actor->id,'body'=>'','attachment'=>null];
        $message['accepted_client_id']=$clientId;$message['ack_visible']=$visible;if($silent)$message['accepted_silent']=true;
        return $message;
    }

    private function bodySilent(array $body):bool {if(!array_key_exists('silent',$body))return false;if(!is_bool($body['silent']))throw new \InvalidArgumentException('silent must be a boolean.');return $body['silent'];}

	private function sessionResponse(User $actor, bool $native = false): string {
		$result = ['is_login' => (bool)$actor->isLoggedin(), 'user_id' => (int)$actor->id, 'can_use' => $this->messenger->canUse($actor), 'can_moderate' => !$native && $this->messenger->canModerate($actor)];
		// Only the authenticated member may read their own minimal profile.
		if($result['is_login'] && $result['can_use']) {
            $result['history_search_supported']=true;
            $result['media_catalog_supported']=true;
            $result['activity_supported']=$this->messenger->activityReady();
            $result['saved_messages_supported']=$this->messenger->savedMessagesReady();
			$result['groups_supported']=$this->messenger->groupsReady();
            $result['polls_supported']=$this->messenger->pollsReady();
            $result['mentions_supported']=$this->messenger->mentionsReady();$result['group_links_supported']=$this->messenger->groupLinksReady();$result['messages_path']=$this->messenger->groupMessagesPath();
            $result['forwarding_supported']=$this->messenger->forwardingReady();
            $result['silent_messages_supported']=$this->messenger->silentMessagesReady();
            $result['reactions_supported']=$this->messenger->reactionsReady();
            $result['pins_supported']=$this->messenger->pinsReady();
			$result['name'] = (string)$actor->name;
			$result['title'] = (string)($actor->get('display_name') ?: $actor->get('title') ?: $actor->name);
		}
		if($result['can_use'] && !$native) { $token = $this->wire()->session->CSRF->getToken('messenger-rest'); $result['csrf'] = ['name'=>$token['name'],'value'=>$token['value'],'header'=>'X-' . $token['name']]; }
		return $this->response(200, $result);
	}

	private function conversationId(User $actor, $identifier): int { $item=$this->messenger->conversation($identifier,$actor);if(!$item)throw new Wire404Exception('Conversation unavailable.');return (int)$item['id']; }
	private function bodyConversationId(User $actor,array $body): int { $value=$body['key']??$body['id']??0;if(!is_int($value)&&!is_string($value))throw new \InvalidArgumentException('Conversation is required.');return $this->conversationId($actor,$value); }
	private function identifier(){ $value=trim((string)($this->wire()->input->get('key')?:$this->wire()->input->get('id')));if(!preg_match('/^(?:[1-9][0-9]*|[a-f0-9]{32})$/i',$value))throw new \InvalidArgumentException('Conversation is required.');return ctype_digit($value)?(int)$value:strtolower($value); }
	private function bodyText(array $body): string { if(!isset($body['body'])||!is_string($body['body']))throw new \InvalidArgumentException('body must be a string.');return $body['body']; }
	private function queryInt(string $name,int $default,int $maximum): int { return max(1,min($maximum,(int)$this->wire()->input->get($name)?:$default)); }
	private function nullableQueryInt(string $name): ?int { $value=(int)$this->wire()->input->get($name);return $value>0?$value:null; }
	private function nullablePositiveQueryInt(string $name): ?int {
        $value=$this->wire()->input->get($name);if($value===null||$value==='')return null;
        if(!is_string($value)||!preg_match('/^[1-9][0-9]*$/D',$value)||filter_var($value,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])===false)throw new \InvalidArgumentException('Invalid cursor.');
        return (int)$value;
    }

	private function jsonBody(string $resource = ''): array {
		$maximum=$resource==='send-attachment'?12*1024*1024:($resource==='group-avatar'?1024*1024:self::MAX_BODY_BYTES);
		$type=strtolower(trim(explode(';',(string)($_SERVER['CONTENT_TYPE']??''))[0]));if($type!=='application/json')throw new \InvalidArgumentException('Content-Type application/json is required.');
		$length=(int)($_SERVER['CONTENT_LENGTH']??0);if($length>$maximum)throw new \InvalidArgumentException('Request is too large.');
		$raw=file_get_contents('php://input',false,null,0,$maximum+1);if(!is_string($raw)||strlen($raw)>$maximum)throw new \InvalidArgumentException('Request is too large.');
		$body=json_decode($raw,true);if(!is_array($body))throw new \InvalidArgumentException('A JSON object is required.');return $body;
	}

	private function validateCsrf(array $body): void {
		$token=$this->wire()->session->CSRF->getToken('messenger-rest');$serverKey='HTTP_' . strtoupper(str_replace('-','_','X-' . $token['name']));$provided=(string)($body[$token['name']]??($_SERVER[$serverKey]??''));
		if($provided===''||!hash_equals((string)$token['value'],$provided))throw new WirePermissionException('Invalid CSRF token.');
	}

	private function rateLimit(bool $mutation): void {
		$state=$this->wire()->session->get(self::RATE_SESSION_KEY);$now=time();if(!is_array($state)||(int)($state['started']??0)<=$now-60)$state=['started'=>$now,'reads'=>0,'writes'=>0];$key=$mutation?'writes':'reads';$state[$key]=(int)$state[$key]+1;$this->wire()->session->set(self::RATE_SESSION_KEY,$state);if($state[$key]>($mutation?60:180))throw new MessengerRestException('Too many requests.',429);
	}

	private function allow(string $method,array $allowed): void { if(in_array($method,$allowed,true))return;header('Allow: ' . implode(', ',$allowed));throw new MessengerRestException('Method not allowed.',405); }
	private function headers(): void { header('Content-Type: application/json; charset=utf-8');header('Cache-Control: private, no-store, max-age=0');header('Pragma: no-cache');header('X-Content-Type-Options: nosniff');header('X-Frame-Options: DENY');header('X-Robots-Tag: noindex, nofollow, noarchive'); }
	private function response(int $status,$result=null,string $error='', string $code=''): string { http_response_code($status);return (string)json_encode($error===''?['ok'=>true,'api_version'=>Messenger::REST_API_VERSION,'result'=>$result]:['ok'=>false,'api_version'=>Messenger::REST_API_VERSION,'error'=>$error] + ($code !== '' ? ['code'=>$code] : []),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE); }
}

final class MessengerRestException extends \RuntimeException {
	private int $httpStatus;
	public function __construct(string $message,int $status){parent::__construct($message);$this->httpStatus=$status;}
	public function status(): int{return $this->httpStatus;}
}
