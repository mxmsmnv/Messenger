<?php namespace ProcessWire;

/** Browser consent and cookie-free native credential endpoints, separate from web v1. */
final class MessengerMobileHttp {
	public function __construct(private Messenger $module) {}
	private function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
	private const MOBILE_CSS = <<<'CSS'
:root{color-scheme:dark;--bg:#242629;--card:#303236;--ink:#f4f4f5;--muted:#b4b7bd;--line:#50545b;--accent:#f4f4f5;--on-accent:#242629;--brand:#ffffff;--soft:#3b3e43}
*{box-sizing:border-box}body{margin:0;min-height:100vh;min-height:100dvh;padding:20px 16px;display:flex;align-items:center;justify-content:center;background:var(--bg);color:var(--ink);font:400 16px/1.5 system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}main{width:100%;max-width:440px;padding:24px 20px;background:var(--card);border-radius:8px}header{display:flex;align-items:center;gap:12px;margin-bottom:24px}.mark{width:44px;height:44px;flex:none;color:var(--brand)}.wordmark{font-size:25px;font-weight:600;letter-spacing:-.035em}h1{font-size:28px;line-height:1.2;letter-spacing:-.03em;font-weight:600;margin:0 0 12px}p{margin:0 0 16px}.muted,footer{color:var(--muted)}.details{margin:20px 0;padding:14px 16px;border-radius:6px;background:var(--soft)}.details div+div{margin-top:12px}dt{font-size:12px;color:var(--muted);margin:0 0 2px}dd{font-weight:500;margin:0;overflow-wrap:anywhere}form{margin:18px 0 0}button,.primary{display:flex;align-items:center;justify-content:center;min-height:48px;width:100%;padding:12px 16px;border:0;border-radius:6px;background:var(--accent);color:var(--on-accent);font:600 16px/1.3 system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;text-decoration:none;cursor:pointer;text-align:center}button:focus-visible,a:focus-visible{outline:3px solid var(--brand);outline-offset:4px}footer{margin-top:18px;font-size:13px}footer p{margin:0}strong{font-weight:600}.success{display:inline-flex;align-items:center;justify-content:center;width:44px;height:44px;margin:0 0 16px;border-radius:6px;background:var(--soft);color:var(--accent);font-size:25px}a{color:var(--accent)}
@media(max-width:360px){body{padding:16px 12px}main{padding:20px 16px}h1{font-size:26px}}
CSS;
	private function documentStart(string $title,?string $redirectUri=null): string {
		$hash=base64_encode(hash('sha256',self::MOBILE_CSS,true));
		$formTarget="'self'";
		// Chromium checks form-action on the redirect after POST as well. This value
		// is passed only after exact authorizationRequest allow-list validation.
		if($redirectUri!==null){$parts=parse_url($redirectUri);$scheme=strtolower((string)($parts['scheme']??''));
			if($scheme==='https'&&isset($parts['host'])&&preg_match('/^(?:[a-z0-9.-]+|\[[a-f0-9:]+\])$/iD',(string)$parts['host']))$formTarget.=' https://'.$parts['host'].(isset($parts['port'])?':'.(int)$parts['port']:'');
			elseif(preg_match('/^[a-z][a-z0-9-]*(?:\.[a-z0-9-]+)+$/D',$scheme))$formTarget.=' '.$scheme.':';
		}
		header("Content-Security-Policy: default-src 'none'; script-src 'none'; style-src 'sha256-".$hash."'; form-action ".$formTarget."; frame-ancestors 'none'; base-uri 'none'");
		return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="color-scheme" content="dark"><title>'.$this->e($title).'</title><style>'.self::MOBILE_CSS.'</style></head><body><main><header><svg class="mark" viewBox="0 0 76.815 77.239" aria-hidden="true" focusable="false"><path fill="currentColor" fill-rule="evenodd" d="M76.647,0.255c0.194,3.892,0.184,7.56,0.147,11.54   c-0.005,0.546,0.052,1.087-0.049,1.564c-0.16,0.757-1.063,1.613-1.564,2.299c-1.617,2.213-3.412,4.374-5.037,6.454   c-2.299,2.944-4.35,5.816-6.65,8.704c-3.849,4.83-7.736,10.1-11.735,15.158c-0.651,0.823-1.222,1.594-2.054,1.76   c-3.597,0.72-2.856-4.571-2.738-7.53c0.044-1.088-0.024-2.149,0-3.129c0.075-3.133,0.012-6.217-0.538-8.851   c-1.068-5.115-3.952-8.898-7.628-11.638c-2.467-1.839-5.449-3.339-9.046-3.765c-8.21-0.973-14.198,3.27-17.701,7.677   c-3.854,4.849-7.266,9.582-11.148,14.522c-0.231,0.246-0.407,0.792-0.783,0.733c-0.163-11.546-0.162-24.141,0-35.598   c6.584-0.216,13.716-0.147,20.684-0.147c6.198-0.001,12.442,0.05,18.63,0.049C51.859,0.058,64.359,0.031,76.647,0.255z"/><path fill="currentColor" fill-rule="evenodd" d="M76.695,42.649c0.129,11.343,0.182,22.811,0,34.473   c-9.379,0.185-19.178,0.096-28.801,0.098c-16.133,0.004-32.351-0.086-47.773-0.146c-0.195-3.831-0.098-8.214-0.049-12.127   c0.905-1.59,2.172-2.932,3.227-4.4c1.059-1.475,2.185-2.89,3.276-4.353c1.073-1.435,2.261-2.824,3.325-4.303   c2.098-2.916,4.448-5.724,6.601-8.654c2.165-2.946,4.343-5.566,6.65-8.606c1.01-1.33,2.081-3.433,3.521-4.106   c1.458-0.685,2.796,0.374,3.129,1.565c0.236,0.846,0.161,1.978,0.146,2.885c-0.044,2.916-0.071,6.264-0.049,9.144   c0.015,1.967-0.01,4.087,0.391,5.771c0.386,1.618,0.997,3.126,1.711,4.547c2.021,4.017,5.217,7.354,9.535,9.242   c1.412,0.616,3.104,1.208,4.841,1.418c8.339,1.006,14.033-2.477,17.799-6.846c1.958-2.273,3.694-4.661,5.525-6.993   c1.825-2.323,3.613-4.548,5.477-6.992C75.684,43.755,76.018,42.821,76.695,42.649z"/></svg><span class="wordmark">Narzan</span></header>';
	}
	private function documentEnd(string $hint='Your password stays with your website.'): string { return '<footer><p>'.$this->e($hint).'</p></footer></main></body></html>'; }
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
			return $this->documentStart('Sign in to Narzan').'<h1>Sign in to continue</h1><p class="muted">Sign in on your website, then return to Narzan and try again.</p>'.$this->documentEnd();
		}
		$csrf=$wire->session->CSRF->getToken('messenger-mobile-consent');
		if($method==='POST'){
			$provided=(string)$input->post($csrf['name']);
			if(!hash_equals((string)$csrf['value'],$provided) || (string)$input->post('approve')!=='yes')throw new MessengerMobileException('access_denied','Consent could not be verified.',403);
			$auth->rateLimit('approval',(string)$wire->user->id,10);
			$callback=$auth->approve($wire->user,$request);
			// Normal native authorization completion: hand the exact allow-listed URI to the OS.
			// Keep an accessible, non-script fallback body for clients that render 303 content.
			header('Location: '.$callback,true,303);
			return $this->documentStart('Opening Narzan',$request['redirect_uri']).'<div class="success" aria-hidden="true">&#10003;</div><h1>Opening Narzan</h1><p class="muted">Finish connecting in the app. If Narzan did not open, use the button below.</p><a class="primary" rel="noreferrer" role="link" aria-label="Return to Narzan" href="'.$this->e($callback).'"><strong>Return to Narzan</strong></a>'.$this->documentEnd('If the browser asks, choose Narzan. You can also return to the app and try again.');
		}
		$form=$this->documentStart('Connect Narzan',$request['redirect_uri']).'<h1>Continue to Narzan</h1><p class="muted">Connect this device to your messages, contacts and files.</p><dl class="details"><div><dt>Account</dt><dd>'.$this->e((string)$wire->user->name).'</dd></div><div><dt>Website</dt><dd>'.$this->e($auth->origin()).'</dd></div><div><dt>Device</dt><dd>'.$this->e($request['device_name']).'</dd></div></dl><p class="muted">Narzan can read and send your Messenger messages and manage your messaging preferences. It receives no site administration access.</p><form method="post">';
		foreach($request as $name=>$value)$form.='<input type="hidden" name="'.$this->e($name).'" value="'.$this->e($value).'">';
		$form.='<input type="hidden" name="'.$this->e($csrf['name']).'" value="'.$this->e($csrf['value']).'"><button type="submit" aria-label="Allow this device" name="approve" value="yes">Allow this device</button></form>';
		return $form.$this->documentEnd('Close this page to cancel. Your password stays with your website.');
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
		return $out.$this->documentEnd();
	}

	private function json(int $status,?array $result=null,string $error=''): string {
		header('Content-Type: application/json; charset=utf-8');http_response_code($status);
		return (string)json_encode($error!==''?['error'=>$error]:$result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
	}
}
