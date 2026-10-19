<?php declare(strict_types=1); namespace ProcessWire;

require __DIR__ . '/support/bootstrap.php';

if(!class_exists(HookEvent::class)) {
	class HookEvent {
		public function __construct(private array $values) {}
		public function arguments(int $index, mixed $value = null): mixed {
			if(func_num_args() === 2) $this->values[$index] = $value;
			return $this->values[$index] ?? null;
		}
	}
}

class MessengerWireWallFixture {
	public array $integrations = ['OtherModule' => ['/other-api/']];
	public bool $fail = false;
	public function syncTrustedApiPaths(string $owner, array $paths, bool $enabled): array {
		if($this->fail) throw new RuntimeException('Synthetic WireWall write failure.');
		if($enabled) $this->integrations[$owner] = $paths;
		else unset($this->integrations[$owner]);
		return $this->getTrustedApiPathIntegration($owner);
	}
	public function getTrustedApiPathIntegration(string $owner): array {
		$paths = $this->integrations[$owner] ?? [];
		return ['owner' => $owner, 'enabled' => (bool)$paths, 'paths' => $paths];
	}
}

class MessengerModulesFixture {
	public array $config = [];
	public function __construct(public MessengerWireWallFixture $wirewall) {}
	public function isInstalled(string $name): bool { return $name === 'WireWall'; }
	public function get(string $name): mixed { return $name === 'WireWall' ? $this->wirewall : null; }
	public function getModuleConfigData(object|string $module): array { return $this->config; }
	public function saveModuleConfigData(object|string $module, array $data): void { $this->config = $data; }
}

$wirewall = new MessengerWireWallFixture();
$modules = new MessengerModulesFixture($wirewall);
$messenger = new Messenger();
$messenger->services = ['modules' => $modules];

$enable = new HookEvent([$messenger, ['wirewall_trust_routes' => 1]]);
$messenger->beforeSaveWireWallIntegration($enable);
$modules->saveModuleConfigData($messenger, $enable->arguments(1));
$messenger->afterSaveWireWallIntegration($enable);
check($wirewall->integrations['Messenger'] === ['/messenger-api/', '/messenger-mobile/'], 'enable registers exact Messenger paths');
check($wirewall->integrations['OtherModule'] === ['/other-api/'], 'enable preserves another module registration');
check($messenger->wireWallIntegrationStatus()['registered'] === true, 'status confirms the exact registration');

$disable = new HookEvent([$messenger, ['wirewall_trust_routes' => 0]]);
$messenger->beforeSaveWireWallIntegration($disable);
$modules->saveModuleConfigData($messenger, $disable->arguments(1));
$messenger->afterSaveWireWallIntegration($disable);
check(!isset($wirewall->integrations['Messenger']), 'disable removes only Messenger registration');
check(isset($wirewall->integrations['OtherModule']), 'disable preserves another module registration');

$wirewall->fail = true;
$enableFailure = new HookEvent([$messenger, ['wirewall_trust_routes' => 1]]);
$messenger->beforeSaveWireWallIntegration($enableFailure);
$modules->saveModuleConfigData($messenger, $enableFailure->arguments(1));
try {
	$messenger->afterSaveWireWallIntegration($enableFailure);
	throw new RuntimeException('Expected a failed WireWall synchronization.');
} catch(WireException $error) {
	check($modules->config['wirewall_trust_routes'] === 0, 'failed sync restores the previous checkbox value');
}

echo 'WireWall integration checks: ' . ($GLOBALS['checks'] ?? 0) . PHP_EOL;
