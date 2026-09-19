<?php namespace ProcessWire;

/** Same-origin JSON transport for the Messenger frontend. */
final class MessengerRestApi extends Wire {
	private const MAX_BODY_BYTES = 65536;
	private const RATE_SESSION_KEY = 'MessengerRestRate';
	private Messenger $messenger;

	public function __construct(Messenger $messenger) { $this->messenger = $messenger; }

	public function handle(string $version, string $resource): string {
		$this->headers();
		try {
			if(strtolower(trim($version)) !== Messenger::REST_API_VERSION) return $this->response(404, null, 'Not found.');
			$resource = strtolower(trim($resource));
			if(!preg_match('/^[a-z-]{2,32}$/', $resource)) return $this->response(404, null, 'Not found.');
			$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
			$actor = $this->wire()->user;
			if($resource === 'session') { $this->allow($method, ['GET']); return $this->sessionResponse($actor); }
			if(!$actor instanceof User || !$this->messenger->canUse($actor)) throw new WirePermissionException('Messenger access denied.');
			$this->rateLimit($method !== 'GET');
			$body = $method === 'POST' ? $this->jsonBody() : [];
			if($method === 'POST') $this->validateCsrf($body);

			switch($resource) {
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
	}

	private function sessionResponse(User $actor): string {
		$result = ['is_login' => (bool)$actor->isLoggedin(), 'user_id' => (int)$actor->id, 'can_use' => $this->messenger->canUse($actor), 'can_moderate' => $this->messenger->canModerate($actor)];
		if($result['can_use']) { $token = $this->wire()->session->CSRF->getToken('messenger-rest'); $result['csrf'] = ['name'=>$token['name'],'value'=>$token['value'],'header'=>'X-' . $token['name']]; }
		return $this->response(200, $result);
	}

	private function conversationId(User $actor, $identifier): int { $item=$this->messenger->conversation($identifier,$actor);if(!$item)throw new Wire404Exception('Conversation unavailable.');return (int)$item['id']; }
	private function bodyConversationId(User $actor,array $body): int { $value=$body['key']??$body['id']??0;if(!is_int($value)&&!is_string($value))throw new \InvalidArgumentException('Conversation is required.');return $this->conversationId($actor,$value); }
	private function identifier(){ $value=trim((string)($this->wire()->input->get('key')?:$this->wire()->input->get('id')));if(!preg_match('/^(?:[1-9][0-9]*|[a-f0-9]{32})$/i',$value))throw new \InvalidArgumentException('Conversation is required.');return ctype_digit($value)?(int)$value:strtolower($value); }
	private function bodyText(array $body): string { if(!isset($body['body'])||!is_string($body['body']))throw new \InvalidArgumentException('body must be a string.');return $body['body']; }
	private function queryInt(string $name,int $default,int $maximum): int { return max(1,min($maximum,(int)$this->wire()->input->get($name)?:$default)); }
	private function nullableQueryInt(string $name): ?int { $value=(int)$this->wire()->input->get($name);return $value>0?$value:null; }

	private function jsonBody(): array {
		$type=strtolower(trim(explode(';',(string)($_SERVER['CONTENT_TYPE']??''))[0]));if($type!=='application/json')throw new \InvalidArgumentException('Content-Type application/json is required.');
		$length=(int)($_SERVER['CONTENT_LENGTH']??0);if($length>self::MAX_BODY_BYTES)throw new \InvalidArgumentException('Request is too large.');
		$raw=file_get_contents('php://input',false,null,0,self::MAX_BODY_BYTES+1);if(!is_string($raw)||strlen($raw)>self::MAX_BODY_BYTES)throw new \InvalidArgumentException('Request is too large.');
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
	private function response(int $status,$result=null,string $error=''): string { http_response_code($status);return (string)json_encode($error===''?['ok'=>true,'api_version'=>Messenger::REST_API_VERSION,'result'=>$result]:['ok'=>false,'api_version'=>Messenger::REST_API_VERSION,'error'=>$error],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE); }
}

final class MessengerRestException extends \RuntimeException {
	private int $httpStatus;
	public function __construct(string $message,int $status){parent::__construct($message);$this->httpStatus=$status;}
	public function status(): int{return $this->httpStatus;}
}
