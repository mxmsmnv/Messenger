<?php namespace ProcessWire;

/** Machine-readable failures shared by native identity and member transport. */
final class MessengerMobileException extends WireException {
	public function __construct(public readonly string $apiCode, string $message, public readonly int $httpStatus = 400) { parent::__construct($message); }
}

/** Optional Narzan identity. Does not authenticate a browser session or accept passwords. */
final class MessengerMobileAuth {
	private const ACCESS_TTL = 600;
	private const REFRESH_TTL = 2592000;
	public function __construct(private Messenger $module) {}
	private function db() { return $this->module->wire('database'); }
	private function fail(string $code = 'invalid_grant', int $status = 400): never { throw new MessengerMobileException($code, 'Mobile authorization is unavailable or invalid.', $status); }
	private function digest(string $value): string {
		$config = $this->module->wire('config');
		$secret = (string)($config->get('messengerEncryptionKey') ?: $config->tableSalt);
		if($secret === '') $this->fail('configuration_unavailable', 503);
		return hash_hmac('sha256', 'Messenger|mobile|v1|' . $value, hash('sha256', $secret, true));
	}
	private function random(): string { return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='); }
	private function hashCredential(string $raw): string { if(!preg_match('/^[A-Za-z0-9_-]{43}$/D', $raw)) $this->fail(); return $this->digest($raw); }
	private function lock(): string { return $this->db()->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite' ? '' : ' FOR UPDATE'; }
	private function eligible(int $id): User {
		$user = $this->module->wire('users')->get($id);
		if(!$user instanceof User || !$this->module->canReceive($user)) $this->fail('access_denied', 403);
		return $user;
	}

	/** Fixed, operator-configured HTTPS issuer; never derived from an untrusted Host header. */
	public function origin(): string {
		if(!(bool)$this->module->mobile_enabled) $this->fail('mobile_disabled', 404);
		$origin = rtrim(trim((string)$this->module->mobile_origin), '/');
		if($origin==='') {
			// httpHosts is explicit installation configuration. Never use runtime httpHost/Host/X-Forwarded-Host.
			$hosts=$this->module->wire('config')->httpHosts??[];
			$host=is_array($hosts)?trim((string)($hosts[0]??'')):'';
			if($host===''||preg_match('~[\s/@?#\\\\*]~',$host))$this->fail('configuration_unavailable',503);
			$origin='https://'.$host;
		}
		$p = parse_url($origin);
		if(!$p || !filter_var($origin,FILTER_VALIDATE_URL) || ($p['scheme'] ?? '') !== 'https' || empty($p['host']) || (isset($p['user']) || isset($p['pass'])) || isset($p['query']) || isset($p['fragment']) || (isset($p['path']) && $p['path'] !== '')) $this->fail('configuration_unavailable', 503);
		return 'https://'.strtolower((string)$p['host']).(isset($p['port'])&&(int)$p['port']!==443?':'.(int)$p['port']:'');
	}
	public function assertTransport(): void {
		$origin = $this->origin(); $p = parse_url($origin);
		$host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
		$expected = strtolower((string)$p['host'] . (isset($p['port']) && (int)$p['port'] !== 443 ? ':' . $p['port'] : ''));
		// ProcessWire can infer config->https from an untrusted X-Forwarded-Proto.
		// Require the web server's TLS indicator too; a trusted TLS proxy must set HTTPS.
		$https = (bool)$this->module->wire('config')->https
			&& in_array(strtolower((string)($_SERVER['HTTPS'] ?? '')), ['on', '1'], true);
		if(!$https || $host !== $expected) $this->fail('invalid_origin', 400);
	}
	public function discovery(): array {
		$origin = $this->origin();
		$base = rtrim((string)$this->module->wire('config')->urls->root, '/');
		return ['protocol'=>'narzan-v1','issuer'=>$origin,'site_id'=>$this->digest('site|' . $origin . '|' . $base),
			'authorize_endpoint'=>$base . '/messenger-mobile/v1/authorize/', 'token_endpoint'=>$base . '/messenger-mobile/v1/token/',
			'refresh_endpoint'=>$base . '/messenger-mobile/v1/refresh/', 'revoke_endpoint'=>$base . '/messenger-mobile/v1/revoke/',
			'devices_endpoint'=>$base . '/messenger-mobile/v1/devices/', 'member_api'=>$base . '/messenger-api/v1/', 'code_challenge_methods_supported'=>['S256'], 'push_supported'=>$this->module->messagePushReady(), 'features'=>['write_ack',...($this->module->silentMessagesReady()?['silent_messages']:[]),...($this->module->callEventsReady()?['call_events']:[]),...($this->module->activityReady()?['activity']:[]),...($this->module->savedMessagesReady()?['saved_messages']:[]),'history_search','media_catalog','device_sessions','client_policy','recipient_context','presence','directory','attachments','read_receipts','message_info','contact_book','retention','chat_delete','chat_mute',...($this->module->messagePushReady()?['message_push']:[]),...($this->module->groupsReady()?['groups']:[]),...($this->module->forwardingReady()?['forwarding']:[]),...($this->module->reactionsReady()?['reactions']:[]),...($this->module->pinsReady()?['pins']:[])]];
	}
	public function authorizationRequest(array $input): array {
		$this->origin();
		foreach(['redirect_uri','state','code_challenge','code_challenge_method','device_name'] as $key) if(isset($input[$key]) && !is_string($input[$key])) $this->fail('invalid_request');
		$redirect = (string)($input['redirect_uri'] ?? '');
		// An empty override selects the shipped Narzan identity, never arbitrary callback discovery.
		$configured=trim((string)$this->module->mobile_redirect_uris);
		$allowed = $configured==='' ? ['org.smnv.narzan.dev://auth/callback'] : (preg_split('/\s+/', $configured, -1, PREG_SPLIT_NO_EMPTY) ?: []);
		$p = parse_url($redirect);
		$scheme=strtolower((string)($p['scheme']??''));
		if(!$p || !$redirect || !in_array($redirect, $allowed, true) || isset($p['fragment']) || isset($p['user']) || isset($p['pass']) || !isset($p['scheme']) || ($scheme!=='https' && !preg_match('/^[a-z][a-z0-9-]*(?:\.[a-z0-9-]+)+$/D',$scheme))) $this->fail('invalid_redirect_uri');
		$state = (string)($input['state'] ?? ''); $challenge = (string)($input['code_challenge'] ?? '');
		if(!preg_match('/^[A-Za-z0-9_-]{22,128}$/D', $state) || !preg_match('/^[A-Za-z0-9_-]{43}$/D', $challenge) || ($input['code_challenge_method'] ?? '') !== 'S256') $this->fail('invalid_request');
		return ['redirect_uri'=>$redirect, 'state'=>$state, 'code_challenge'=>$challenge, 'code_challenge_method'=>'S256', 'device_name'=>mb_substr(trim((string)($input['device_name'] ?? 'Narzan')), 0, 80) ?: 'Narzan'];
	}
	public function loginUrl(array $request): ?string {
		$path=trim((string)$this->module->mobile_login_path);$parameter=trim((string)$this->module->mobile_login_return_param);
		if($path===''||$parameter==='')return null;
		if(!str_starts_with($path,'/')||str_starts_with($path,'//')||str_contains($path,'\\')||preg_match('/[\x00-\x20\x7f]/',$path)||parse_url($path,PHP_URL_FRAGMENT)!==null||!preg_match('/^[A-Za-z][A-Za-z0-9_]{0,40}$/D',$parameter))$this->fail('configuration_unavailable',503);
		$return=$this->discovery()['authorize_endpoint'].'?'.http_build_query($request,'','&',PHP_QUERY_RFC3986);
		return $this->origin().$path.(str_contains($path,'?')?'&':'?').http_build_query([$parameter=>$return],'','&',PHP_QUERY_RFC3986);
	}

	/** Called only after browser session, explicit consent and CSRF verification by HTTP controller. */
	public function approve(User $actor, array $input): string {
		$request = $this->authorizationRequest($input);
		if(!$this->module->canUse($actor)) $this->fail('access_denied', 403);
		$raw = $this->random(); $now = time();
		$this->db()->prepare('INSERT INTO messenger_mobile_codes (digest,user_id,redirect_uri,challenge,device_name,expires_at,used_at) VALUES (?,?,?,?,?,?,NULL)')
			->execute([$this->digest($raw), (int)$actor->id, $request['redirect_uri'], $request['code_challenge'], $request['device_name'], $now + 120]);
		return $request['redirect_uri'] . (str_contains($request['redirect_uri'], '?') ? '&' : '?') . http_build_query(['code'=>$raw,'state'=>$request['state'],'iss'=>$this->origin()], '', '&', PHP_QUERY_RFC3986);
	}
	public function exchange(array $body): array {
		$this->origin();
		$raw = $body['code'] ?? ''; $verifier = $body['code_verifier'] ?? ''; $redirect = $body['redirect_uri'] ?? '';
		if(!is_string($raw) || !is_string($verifier) || !is_string($redirect) || !preg_match('/^[A-Za-z0-9._~-]{43,128}$/D', $verifier)) $this->fail();
		$digest = $this->hashCredential($raw); $db = $this->db(); $db->beginTransaction();
		try {
			$q=$db->prepare('SELECT * FROM messenger_mobile_codes WHERE digest=?' . $this->lock()); $q->execute([$digest]); $code=$q->fetch(\PDO::FETCH_ASSOC);
			$challenge=rtrim(strtr(base64_encode(hash('sha256',$verifier,true)), '+/', '-_'), '=');
			if(!$code || $code['used_at'] !== null || (int)$code['expires_at'] <= time() || !hash_equals($code['challenge'],$challenge) || !hash_equals($code['redirect_uri'],$redirect)) $this->fail();
			$this->eligible((int)$code['user_id']);
			$q=$db->prepare('UPDATE messenger_mobile_codes SET used_at=? WHERE digest=? AND used_at IS NULL'); $q->execute([time(),$digest]); if($q->rowCount()!==1) $this->fail();
			$grant=bin2hex(random_bytes(16));
			$db->prepare('INSERT INTO messenger_mobile_grants (id,user_id,device_name,created_at,revoked_at) VALUES (?,?,?,?,NULL)')->execute([$grant,(int)$code['user_id'],$code['device_name'],time()]);
			$result=$this->issue($grant); $db->commit(); return $result;
		} catch(\Throwable $e) { if($db->inTransaction())$db->rollBack(); throw $e; }
	}
	private function issue(string $grant): array {
		$now=time(); $access=$this->random(); $refresh=$this->random();
		$q=$this->db()->prepare('INSERT INTO messenger_mobile_tokens (digest,grant_id,kind,expires_at,consumed_at) VALUES (?,?,?,?,NULL)');
		$q->execute([$this->digest($access),$grant,'access',$now+self::ACCESS_TTL]); $q->execute([$this->digest($refresh),$grant,'refresh',$now+self::REFRESH_TTL]);
		return ['token_type'=>'Bearer','access_token'=>$access,'refresh_token'=>$refresh,'expires_in'=>self::ACCESS_TTL,'refresh_expires_in'=>self::REFRESH_TTL,'connection_id'=>$grant,'issuer'=>$this->origin(),'scope'=>'messenger.member'];
	}
	private function token(string $digest, string $kind, bool $lock=false): array {
		$q=$this->db()->prepare('SELECT t.*,g.user_id,g.revoked_at FROM messenger_mobile_tokens t JOIN messenger_mobile_grants g ON g.id=t.grant_id WHERE t.digest=? AND t.kind=?' . ($lock?$this->lock():''));
		$q->execute([$digest,$kind]); return $q->fetch(\PDO::FETCH_ASSOC) ?: [];
	}
	public function refresh(array $body): array {
		$this->origin(); $raw=$body['refresh_token']??''; if(!is_string($raw))$this->fail(); $digest=$this->hashCredential($raw); $db=$this->db();$db->beginTransaction();
		try {
			$row=$this->token($digest,'refresh',true);
			if(!$row || $row['revoked_at']!==null || (int)$row['expires_at']<=time())$this->fail();
			if($row['consumed_at']!==null){$this->revokeGrant($row['grant_id']);$db->commit();$this->fail('refresh_reuse');}
			$this->eligible((int)$row['user_id']);
			$q=$db->prepare('UPDATE messenger_mobile_tokens SET consumed_at=? WHERE digest=? AND consumed_at IS NULL');$q->execute([time(),$digest]);if($q->rowCount()!==1)$this->fail();
			$db->prepare("UPDATE messenger_mobile_tokens SET consumed_at=? WHERE grant_id=? AND kind='access' AND consumed_at IS NULL")->execute([time(),$row['grant_id']]);
			$result=$this->issue($row['grant_id']);$db->commit();return $result;
		}catch(\Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
	}
	/** Returns authority only; caller binds/restores the current PW user without forceLogin. */
	public function authenticate(string $authorization): User {
		$this->origin(); if(!preg_match('/^Bearer ([A-Za-z0-9_-]{43})$/Di',$authorization,$m))$this->fail('unauthorized',401);
		$row=$this->token($this->digest($m[1]),'access');
		if(!$row || $row['revoked_at']!==null || $row['consumed_at']!==null || (int)$row['expires_at']<=time())$this->fail('unauthorized',401);
		return $this->eligible((int)$row['user_id']);
	}
	public function authenticatedGrant(string $authorization): string {
        $this->authenticate($authorization);
        preg_match('/^Bearer ([A-Za-z0-9_-]{43})$/Di',$authorization,$m);
        $row=$this->token($this->digest($m[1]),'access');
        return (string)$row['grant_id'];
    }

	/** Possession of a refresh token can revoke only its own device connection. */
	public function revoke(array $body): array {
		$this->origin();$raw=$body['refresh_token']??'';if(!is_string($raw))$this->fail();$row=$this->token($this->hashCredential($raw),'refresh');
		if($row)$this->revokeGrant($row['grant_id']);return ['revoked'=>true];
	}
	public function devices(User $actor,string $currentGrant='',int $limit=100): array {
		if(!$this->module->canUse($actor))$this->fail('access_denied',403);
		$q=$this->db()->prepare('SELECT g.id,g.device_name,g.created_at FROM messenger_mobile_grants g WHERE g.user_id=? AND g.revoked_at IS NULL AND EXISTS(SELECT 1 FROM messenger_mobile_tokens t WHERE t.grant_id=g.id AND t.consumed_at IS NULL AND t.expires_at>?) ORDER BY (g.id=?) DESC,g.created_at DESC,g.id LIMIT '.max(1,min(101,$limit)));$q->execute([(int)$actor->id,time(),$currentGrant]);
        return array_map(static fn(array $row):array=>['id'=>(string)$row['id'],'device_name'=>(string)$row['device_name'],'created_at'=>(int)$row['created_at'],'current'=>$currentGrant!==''&&hash_equals($currentGrant,(string)$row['id'])],$q->fetchAll(\PDO::FETCH_ASSOC));
	}
	public function revokeDevice(User $actor,string $id): void {
		if(!$this->module->canUse($actor)||!preg_match('/^[a-f0-9]{32}$/D',$id))$this->fail('access_denied',403);
		$this->db()->prepare('UPDATE messenger_mobile_grants SET revoked_at=COALESCE(revoked_at,?) WHERE id=? AND user_id=?')->execute([time(),$id,(int)$actor->id]);
	}
    /** Native authority comes from the authenticated token, never a body-provided exemption. */
    public function revokeOtherDevices(User $actor,string $currentGrant): int {
        if(!$this->module->canUse($actor)||!preg_match('/^[a-f0-9]{32}$/D',$currentGrant))$this->fail('access_denied',403);
        $q=$this->db()->prepare('SELECT user_id FROM messenger_mobile_grants WHERE id=? AND revoked_at IS NULL');$q->execute([$currentGrant]);
        if((int)$q->fetchColumn()!==(int)$actor->id)$this->fail('access_denied',403);
        $q=$this->db()->prepare('UPDATE messenger_mobile_grants SET revoked_at=? WHERE user_id=? AND id<>? AND revoked_at IS NULL');$q->execute([time(),(int)$actor->id,$currentGrant]);return $q->rowCount();
    }

	private function revokeGrant(string $id): void { $this->db()->prepare('UPDATE messenger_mobile_grants SET revoked_at=COALESCE(revoked_at,?) WHERE id=?')->execute([time(),$id]); }
	/** Shared per-account API budget, or per-IP auth budget. Identity is keyed before storage. */
	public function rateLimit(string $lane,string $identity,int $limit): void {
		$now=time();$key=$this->digest('rate|' . $lane . '|' . $identity . '|' . intdiv($now,60));$db=$this->db();
		try{$db->prepare('INSERT INTO messenger_mobile_rates (id,hits,expires_at) VALUES (?,0,?)')->execute([$key,$now+120]);}
		catch(\PDOException $e){if(!in_array((string)$e->getCode(),['23000','23505'],true))throw $e;}
		$db->prepare('UPDATE messenger_mobile_rates SET hits=hits+1 WHERE id=?')->execute([$key]);
		$q=$db->prepare('SELECT hits FROM messenger_mobile_rates WHERE id=?');$q->execute([$key]);
		if((int)$q->fetchColumn()>$limit)throw new MessengerMobileException('rate_limited','Too many requests.',429);
		$db->prepare('DELETE FROM messenger_mobile_rates WHERE expires_at<?')->execute([$now]);
	}
	public static function install($db): void {
		// No foreign-key dependency or secret-bearing defaults; tables retained on uninstall.
		foreach([
			'messenger_mobile_codes'=>'digest CHAR(64) PRIMARY KEY,user_id BIGINT NOT NULL,redirect_uri VARCHAR(1000) NOT NULL,challenge CHAR(43) NOT NULL,device_name VARCHAR(80) NOT NULL,expires_at BIGINT NOT NULL,used_at BIGINT NULL',
			'messenger_mobile_grants'=>'id CHAR(32) PRIMARY KEY,user_id BIGINT NOT NULL,device_name VARCHAR(80) NOT NULL,created_at BIGINT NOT NULL,revoked_at BIGINT NULL',
			'messenger_mobile_tokens'=>'digest CHAR(64) PRIMARY KEY,grant_id CHAR(32) NOT NULL,kind VARCHAR(10) NOT NULL,expires_at BIGINT NOT NULL,consumed_at BIGINT NULL',
			'messenger_mobile_rates'=>'id CHAR(64) PRIMARY KEY,hits INT NOT NULL,expires_at BIGINT NOT NULL',
		] as $table=>$columns) {
			$mysql=$db->getAttribute(\PDO::ATTR_DRIVER_NAME)==='mysql';
			$indexColumn=match($table){'messenger_mobile_tokens'=>'grant_id','messenger_mobile_grants'=>'user_id',default=>'expires_at'};
			$index=$table.'_lookup';
			$db->exec('CREATE TABLE IF NOT EXISTS '.$table.' ('.$columns.($mysql?',KEY '.$index.' ('.$indexColumn.')':'').')'.($mysql?' ENGINE=InnoDB':''));
			if(!$mysql)$db->exec('CREATE INDEX IF NOT EXISTS '.$index.' ON '.$table.' ('.$indexColumn.')');
		}
	}
}
