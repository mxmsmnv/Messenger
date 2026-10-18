<?php namespace ProcessWire;

/** Browser consent and cookie-free native credential endpoints, separate from web v1. */
final class MessengerMobileHttp {
	public function __construct(private Messenger $module) {}
	private function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
	private function documentStart(string $title): string { return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>'.$this->e($title).'</title></head><body>'; }
	public function handle(string $resource): string {
		header('Cache-Control: private, no-store, max-age=0'); header('Pragma: no-cache'); header('Referrer-Policy: no-referrer');
		header('X-Content-Type-Options: nosniff'); header('X-Frame-Options: DENY'); header('X-Robots-Tag: noindex, nofollow');
		$auth=new MessengerMobileAuth($this->module);$method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));
		try {
			$auth->assertTransport();
			if($resource==='authorize')return $this->authorize($auth,$method);
			if($resource==='devices')return $this->devices($auth,$method);
			if($resource==='discovery' && $method==='GET')return $this->json(200,$auth->discovery());
			// Token endpoints are never browser-cookie authority. No CORS and no JSONP.
			if(!empty($_SERVER['HTTP_COOKIE']) || !empty($_SERVER['HTTP_AUTHORIZATION']))throw new MessengerMobileException('mixed_credentials','Native credentials must be sent without cookies.',400);
			if(!in_array($resource,['token','refresh','revoke'],true))throw new MessengerMobileException('not_found','Not found.',404);
			if($method!=='POST'){header('Allow: POST');throw new MessengerMobileException('method_not_allowed','Method not allowed.',405);}
			$auth->rateLimit('identity',(string)($_SERVER['REMOTE_ADDR']??'unknown'),30);
			$auth->rateLimit('identity-site','site',120);
			$body=$this->body();
			$allowed=$resource==='token'?['code','code_verifier','redirect_uri']:['refresh_token'];
			if(array_diff(array_keys($body),$allowed))throw new MessengerMobileException('invalid_request','Unexpected fields.');
			$result=match($resource){'token'=>$auth->exchange($body),'refresh'=>$auth->refresh($body),'revoke'=>$auth->revoke($body)};
			return $this->json(200,$result);
		}catch(MessengerMobileException $e){if($e->httpStatus===429)header('Retry-After: 60');return $this->json($e->httpStatus,null,$e->apiCode);}
		catch(\Throwable $e){return $this->json(503,null,'temporarily_unavailable');}
	}
	private function body(): array {
		if(strtolower(trim(explode(';',(string)($_SERVER['CONTENT_TYPE']??''))[0]))!=='application/json')throw new MessengerMobileException('invalid_request','JSON is required.');
		$raw=file_get_contents('php://input',false,null,0,8193);
		if(!is_string($raw)||strlen($raw)>8192)throw new MessengerMobileException('invalid_request','Request too large.');
		$object=json_decode($raw);
		if(!$object instanceof \stdClass)throw new MessengerMobileException('invalid_request','A JSON object is required.');
		return (array)$object;
	}
	private function authorize(MessengerMobileAuth $auth,string $method): string {
		header('Content-Type: text/html; charset=utf-8');
		header("Content-Security-Policy: default-src 'none'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
		if(!in_array($method,['GET','POST'],true))throw new MessengerMobileException('method_not_allowed','Method not allowed.',405);
		if(!empty($_SERVER['HTTP_AUTHORIZATION']))throw new MessengerMobileException('mixed_credentials','Browser login is required.');
		$wire=$this->module->wire(); $input=$wire->input; $values=[];
		foreach(['redirect_uri','state','code_challenge','code_challenge_method','device_name'] as $key)$values[$key]=$method==='POST'?$input->post($key):$input->get($key);
		$request=$auth->authorizationRequest($values);
		if(!$wire->user->isLoggedin() || !$this->module->canUse($wire->user)){
			$login=$auth->loginUrl($request);
			if($method==='GET' && $login!==null){header('Location: '.$login,true,303);return '';}
			http_response_code(401);
			return $this->documentStart('Narzan').'<h1>Sign in to this website</h1><p>Use the website’s normal sign-in, then reopen Narzan authorization. No password is sent to the app.</p></body></html>';
		}
		$csrf=$wire->session->CSRF->getToken('messenger-mobile-consent');
		if($method==='POST'){
			$provided=(string)$input->post($csrf['name']);
			if(!hash_equals((string)$csrf['value'],$provided) || (string)$input->post('approve')!=='yes')throw new MessengerMobileException('access_denied','Consent could not be verified.',403);
			$auth->rateLimit('approval',(string)$wire->user->id,10);
			$callback=$auth->approve($wire->user,$request);
			// CSP blocks scripts and third-party requests; a human click opens the configured app.
			return $this->documentStart('Narzan connected').'<h1>Authorization approved</h1><p><a rel="noreferrer" role="link" aria-label="Return to Narzan" href="'.$this->e($callback).'"><strong>Return to Narzan</strong></a></p></body></html>';
		}
		$form=$this->documentStart('Connect Narzan').'<h1>Connect Narzan</h1><p>Account: '.$this->e((string)$wire->user->name).'</p><p>Device: '.$this->e($request['device_name']).'</p><p>This device can read and send your Messenger messages and manage your member messaging preferences. It receives no site administration access.</p><form method="post">';
		foreach($request as $name=>$value)$form.='<input type="hidden" name="'.$this->e($name).'" value="'.$this->e($value).'">';
		$form.='<input type="hidden" name="'.$this->e($csrf['name']).'" value="'.$this->e($csrf['value']).'"><button type="submit" aria-label="Allow this device" name="approve" value="yes">Allow this device</button></form><p>Close this page to cancel.</p>';
		return $form.'</body></html>';
	}
	private function devices(MessengerMobileAuth $auth,string $method): string {
		header('Content-Type: text/html; charset=utf-8');header("Content-Security-Policy: default-src 'none'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
		if(!in_array($method,['GET','POST'],true))throw new MessengerMobileException('method_not_allowed','Method not allowed.',405);
		$wire=$this->module->wire();
		if(!empty($_SERVER['HTTP_AUTHORIZATION'])||!$this->module->canUse($wire->user))throw new MessengerMobileException('access_denied','Browser sign-in is required.',403);
		$csrf=$wire->session->CSRF->getToken('messenger-mobile-devices');
		if($method==='POST'){
			if(!hash_equals((string)$csrf['value'],(string)$wire->input->post($csrf['name']))||(string)$wire->input->post('revoke')!=='yes')throw new MessengerMobileException('access_denied','Consent could not be verified.',403);
			$auth->revokeDevice($wire->user,(string)$wire->input->post('connection_id'));
		}
		$out=$this->documentStart('Narzan devices').'<h1>Your Narzan devices</h1>';
		foreach($auth->devices($wire->user) as $device)$out.='<form method="post"><p>'.$this->e((string)$device['device_name']).'</p><input type="hidden" name="connection_id" value="'.$this->e($device['id']).'"><input type="hidden" name="'.$this->e($csrf['name']).'" value="'.$this->e($csrf['value']).'"><button name="revoke" value="yes">Revoke this device</button></form>';
		return $out.'</body></html>';
	}

	private function json(int $status,?array $result=null,string $error=''): string {
		header('Content-Type: application/json; charset=utf-8');http_response_code($status);
		return (string)json_encode($error!==''?['error'=>$error]:$result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
	}
}
