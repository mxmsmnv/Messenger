<?php namespace ProcessWire;

/** Same-origin JSON transport for the Messenger frontend. */
final class MessengerRestApi extends Wire {
	private const MAX_BODY_BYTES = 65536;
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
			if($resource === 'session') { $this->allow($method, ['GET']); return $this->sessionResponse($actor, $native); }
			if(!$actor instanceof User || !$this->messenger->canUse($actor)) throw new WirePermissionException('Messenger access denied.');
			if(!$native) $this->rateLimit($method !== 'GET');
			$body = $method === 'POST' ? $this->jsonBody($resource) : [];
			if($method === 'POST' && !$native) $this->validateCsrf($body);

			switch($resource) {
				case 'presence':
					$this->allow($method,['GET','POST']);
					if($method==='GET')$result=$this->messenger->presence($actor);
					elseif(array_key_exists('heartbeat',$body)){
						if($body['heartbeat']!==true||array_key_exists('status',$body)||array_key_exists('text',$body))throw new \InvalidArgumentException('Heartbeat must not change presence preferences.');
						$result=$this->messenger->heartbeatPresence($actor);
					}else $result=$this->messenger->setPresence($actor,(string)($body['status']??''),(string)($body['text']??''));break;
				case 'directory':
				case 'contacts':
					$this->allow($method,['GET']);$result=$this->messenger->memberDirectory($actor,(string)$this->wire()->input->get('q'),$this->queryInt('limit',30,30),$resource==='contacts');break;
				case 'send-attachment':
					$this->allow($method,['POST']);$result=$this->messenger->sendAttachment($this->bodyConversationId($actor,$body),$actor,$body,(string)($body['client_id']??''));break;
				case 'attachment':
					$this->allow($method,['GET']);$result=$this->messenger->attachment($actor,(int)$this->wire()->input->get('id'));break;
				case 'sync':
					$this->allow($method, ['GET']);
					$result = $this->messenger->syncState($actor, (string)$this->wire()->input->get('revision'));
					break;
				case 'inbox':
					$this->allow($method, ['GET']);
					$scope = (string)$this->wire()->input->get('scope');
					$before = trim((string)$this->wire()->input->get('before')) ?: null;
					$result = $this->messenger->conversationsForUser($actor, $scope, $before, $this->queryInt('limit', 30, 100));
					break;
				case 'conversation':
					$this->allow($method, ['GET']);
					$result = $this->messenger->conversation($this->identifier(), $actor);
					if(!$result) throw new Wire404Exception('Conversation unavailable.');
					break;
				case 'messages':
					$this->allow($method, ['GET']);
					$id = $this->conversationId($actor, $this->identifier());
					$result = $this->messenger->conversationMessages($id, $actor, $this->nullableQueryInt('before'), $this->nullableQueryInt('after'), $this->queryInt('limit', 50, 100));
					break;
				case 'search':
					$this->allow($method, ['GET']);
					$result = $this->messenger->searchMessages($actor, (string)$this->wire()->input->get('q'), $this->queryInt('limit', 30, 50));
					break;
				case 'recipients':
					$this->allow($method, ['GET']);
					$result = $this->messenger->searchRecipients($actor, (string)$this->wire()->input->get('q'), $this->queryInt('limit', 10, 20));
					break;
				case 'start':
					$this->allow($method, ['POST']);
					$recipient = $this->wire()->users->get((int)($body['recipient_id'] ?? 0));
					if(!$recipient instanceof User || !$recipient->id) throw new WirePermissionException('Messaging is unavailable.');
					$result = $this->messenger->startConversation($actor, $recipient, $this->bodyText($body), (string)($body['client_id'] ?? ''), (array)($body['context'] ?? []));
					break;
				case 'send':
					$this->allow($method, ['POST']);
					$id = $this->bodyConversationId($actor, $body);
					$result = $this->messenger->sendMessage($id, $actor, $this->bodyText($body), (string)($body['client_id'] ?? ''));
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

	private function sessionResponse(User $actor, bool $native = false): string {
		$result = ['is_login' => (bool)$actor->isLoggedin(), 'user_id' => (int)$actor->id, 'can_use' => $this->messenger->canUse($actor), 'can_moderate' => !$native && $this->messenger->canModerate($actor)];
		if($result['can_use'] && !$native) { $token = $this->wire()->session->CSRF->getToken('messenger-rest'); $result['csrf'] = ['name'=>$token['name'],'value'=>$token['value'],'header'=>'X-' . $token['name']]; }
		return $this->response(200, $result);
	}

	private function conversationId(User $actor, $identifier): int { $item=$this->messenger->conversation($identifier,$actor);if(!$item)throw new Wire404Exception('Conversation unavailable.');return (int)$item['id']; }
	private function bodyConversationId(User $actor,array $body): int { $value=$body['key']??$body['id']??0;if(!is_int($value)&&!is_string($value))throw new \InvalidArgumentException('Conversation is required.');return $this->conversationId($actor,$value); }
	private function identifier(){ $value=trim((string)($this->wire()->input->get('key')?:$this->wire()->input->get('id')));if(!preg_match('/^(?:[1-9][0-9]*|[a-f0-9]{32})$/i',$value))throw new \InvalidArgumentException('Conversation is required.');return ctype_digit($value)?(int)$value:strtolower($value); }
	private function bodyText(array $body): string { if(!isset($body['body'])||!is_string($body['body']))throw new \InvalidArgumentException('body must be a string.');return $body['body']; }
	private function queryInt(string $name,int $default,int $maximum): int { return max(1,min($maximum,(int)$this->wire()->input->get($name)?:$default)); }
	private function nullableQueryInt(string $name): ?int { $value=(int)$this->wire()->input->get($name);return $value>0?$value:null; }

	private function jsonBody(string $resource = ''): array {
		$maximum=$resource==='send-attachment'?12*1024*1024:self::MAX_BODY_BYTES;
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
