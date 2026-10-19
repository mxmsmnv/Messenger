<?php namespace ProcessWire;

/**
 * Optional, owner-scoped WireWall integration for Messenger public endpoints.
 */
trait MessengerWireWallIntegration {
	private const WIREWALL_INTEGRATION_OWNER = 'Messenger';
	private const WIREWALL_INTEGRATION_PATHS = ['/messenger-api/', '/messenger-mobile/'];
	private ?bool $wireWallIntegrationPreviousState = null;
	private bool $wireWallIntegrationSaveGuard = false;

	public function wireWallIntegrationStatus(): array {
		$modules = $this->wire('modules');
		$installed = $modules && method_exists($modules, 'isInstalled') && $modules->isInstalled('WireWall');
		$status = [
			'installed' => $installed,
			'supported' => false,
			'enabled' => (bool)$this->wirewall_trust_routes,
			'registered' => false,
			'paths' => self::WIREWALL_INTEGRATION_PATHS,
		];
		if(!$installed) return $status;
		$wirewall = $modules->get('WireWall');
		$status['supported'] = is_object($wirewall)
			&& method_exists($wirewall, 'syncTrustedApiPaths')
			&& method_exists($wirewall, 'getTrustedApiPathIntegration');
		if(!$status['supported']) return $status;
		$registration = $wirewall->getTrustedApiPathIntegration(self::WIREWALL_INTEGRATION_OWNER);
		$status['registered'] = !empty($registration['enabled'])
			&& ($registration['paths'] ?? []) === self::WIREWALL_INTEGRATION_PATHS;
		return $status;
	}

	public function beforeSaveWireWallIntegration(HookEvent $event): void {
		if($this->wireWallIntegrationSaveGuard || !$this->isMessengerConfigSave($event)) return;
		$data = $event->arguments(1);
		if(!is_array($data)) return;
		$current = $this->wire('modules')->getModuleConfigData($this);
		$this->wireWallIntegrationPreviousState = !empty($current['wirewall_trust_routes']);
		$data['wirewall_trust_routes'] = !empty($data['wirewall_trust_routes']) ? 1 : 0;
		if($data['wirewall_trust_routes']) $this->wireWallModuleOrFail();
		$event->arguments(1, $data);
	}

	public function afterSaveWireWallIntegration(HookEvent $event): void {
		if($this->wireWallIntegrationSaveGuard || !$this->isMessengerConfigSave($event)) return;
		$data = $event->arguments(1);
		if(!is_array($data)) return;
		$enabled = !empty($data['wirewall_trust_routes']);
		$previous = (bool)$this->wireWallIntegrationPreviousState;
		$this->wireWallIntegrationPreviousState = null;
		if(!$enabled && !$previous) return;

		try {
			$this->syncWireWallIntegration($enabled);
		} catch(\Throwable $error) {
			$this->restoreWireWallIntegrationSetting($previous);
			throw new WireException($this->_('Messenger could not update the WireWall route exception. The previous setting was restored.'), 0, $error);
		}
	}

	protected function syncWireWallIntegration(bool $enabled): array {
		return $this->wireWallModuleOrFail()->syncTrustedApiPaths(
			self::WIREWALL_INTEGRATION_OWNER,
			self::WIREWALL_INTEGRATION_PATHS,
			$enabled
		);
	}

	protected function removeWireWallIntegrationOnUninstall(): void {
		if(!(bool)$this->wirewall_trust_routes) return;
		$this->syncWireWallIntegration(false);
	}

	private function wireWallModuleOrFail(): object {
		$modules = $this->wire('modules');
		$wirewall = $modules && method_exists($modules, 'isInstalled') && $modules->isInstalled('WireWall')
			? $modules->get('WireWall')
			: null;
		if(
			!is_object($wirewall)
			|| !method_exists($wirewall, 'syncTrustedApiPaths')
			|| !method_exists($wirewall, 'getTrustedApiPathIntegration')
		) {
			throw new WireException($this->_('Install or update WireWall before enabling its Messenger route exception.'));
		}
		return $wirewall;
	}

	private function isMessengerConfigSave(HookEvent $event): bool {
		$module = $event->arguments(0);
		return $module === $this || $module === 'Messenger';
	}

	private function restoreWireWallIntegrationSetting(bool $enabled): void {
		$modules = $this->wire('modules');
		$data = $modules->getModuleConfigData($this);
		if(!is_array($data)) $data = [];
		$data['wirewall_trust_routes'] = $enabled ? 1 : 0;
		$this->wireWallIntegrationSaveGuard = true;
		try {
			$modules->saveModuleConfigData($this, $data);
			$this->set('wirewall_trust_routes', $enabled ? 1 : 0);
		} finally {
			$this->wireWallIntegrationSaveGuard = false;
		}
	}
}
