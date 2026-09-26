<?php namespace ProcessWire;

/**
 * Private member-to-member messaging with requests, safety controls and moderation.
 */
class Messenger extends WireData implements Module, ConfigurableModule {

	public const VERSION = 101;
	public const REST_API_VERSION = 'v1';
	private const ENCRYPTION_PREFIX = 'menc:v1:';
	private const ENCRYPTION_CONTEXT = 'ProcessWire|Messenger|at-rest|v1';
	public const PERMISSION_MODERATE = 'messenger-moderate';
	public const PERMISSION_VIEW_CONTENT = 'messenger-view-content';
	public const PERMISSION_ADMIN = 'messenger-admin';
	public const PERMISSION_EXPORT = 'messenger-export';
	public const PERMISSION_DELETE = 'messenger-delete';
	public const TABLE_CONVERSATIONS = 'messenger_conversations';
	public const TABLE_PARTICIPANTS = 'messenger_participants';
	public const TABLE_MESSAGES = 'messenger_messages';
	public const TABLE_MESSAGE_HIDES = 'messenger_message_hides';
	public const TABLE_BLOCKS = 'messenger_blocks';
	public const TABLE_REPORTS = 'messenger_reports';
	public const TABLE_RESTRICTIONS = 'messenger_restrictions';
	public const TABLE_OUTBOX = 'messenger_notification_outbox';
	public const TABLE_AUDIT = 'messenger_audit';

	public static function getModuleInfo(): array {
		return [
			'title' => 'Messenger',
			'version' => 101,
			'summary' => 'Private member messaging, message requests, blocking, reporting and moderation.',
			'author' => 'Maxim Semenov',
			'license' => 'MIT',
			'icon' => 'comments',
			'singular' => true,
			'autoload' => true,
			'requires' => ['ProcessWire>=3.0.200', 'PHP>=8.1'],
			'installs' => ['ProcessMessenger'],
		];
	}

	public static function getDefaultConfig(): array {
		return [
			'public_path' => '/messages/',
			'frontend_framework' => 'designsystemet',
			'frontend_custom_map' => '',
			'first_contact_mode' => 'requests',
			'max_message_length' => 5000,
			'max_messages_minute' => 20,
			'max_new_recipients_day' => 20,
			'max_pending_request_messages' => 1,
			'edit_window_minutes' => 15,
			'delete_window_minutes' => 15,
			'poll_thread_seconds' => 4,
			'poll_inbox_seconds' => 20,
			'email_notifications' => 0,
			'mail_module' => '',
			'notification_origin' => '',
			'enable_cli' => 0,
			'from_email' => 'no-reply@example.com',
			'from_name' => 'Messenger',
			'retention_days' => 0,
		];
	}

	public function __construct() {
		parent::__construct();
		foreach(self::getDefaultConfig() as $key => $value) $this->set($key, $value);
	}

	public function init(): void {
		$this->addHook('/messenger-api/{version}/{resource}/', $this, 'handleRestRequest');
	}

	public function handleRestRequest(HookEvent $event): string {
		require_once __DIR__ . '/MessengerRestApi.php';
		$rest = $this->wire(new MessengerRestApi($this));
		return $rest->handle((string)$event->arguments('version'), (string)$event->arguments('resource'));
	}

	public function getModuleConfigInputfields(InputfieldWrapper $inputfields): InputfieldWrapper {
		$fieldset = function(string $key, string $label, string $icon, string $description, bool $expanded = false): InputfieldWrapper {
			/** @var InputfieldWrapper $field */
			$field = $this->wire('modules')->get('InputfieldFieldset');
			$field->name = 'messenger_config_' . $key;
			$field->label = $label;
			$field->icon = $icon;
			$field->description = $description;
			$field->collapsed = $expanded ? Inputfield::collapsedNo : Inputfield::collapsedYes;
			return $field;
		};
		$add = function(InputfieldWrapper $group, string $type, string $name, string $label, string $description, int $width, array $settings = []): Inputfield {
			/** @var Inputfield $field */
			$field = $this->wire('modules')->get($type);
			$field->name = $name;
			$field->label = $label;
			$field->description = $description;
			$field->columnWidth = $width;
			if($type === 'InputfieldCheckbox') $field->checked = (bool)$this->get($name);
			else $field->value = $this->get($name);
			foreach($settings as $property => $value) $field->$property = $value;
			$group->add($field);
			return $field;
		};

		$experience = $fieldset('experience', $this->_('Member experience'), 'comments', $this->_('Configure the public messages route, first-contact behavior, and message editing rules.'), true);
		$add($experience, 'InputfieldText', 'public_path', $this->_('Public messages path'), $this->_('Absolute site path that renders Messenger::renderApp(), for example /messages/.'), 50, ['required' => true, 'placeholder' => '/messages/']);
		$framework = $add($experience, 'InputfieldSelect', 'frontend_framework', $this->_('Frontend framework'), $this->_('Messenger keeps its behavior and accessibility contract while adding classes and attributes for the selected component framework.'), 50);
		$framework->addOptions($this->frontendFrameworks());
		$customMap = $add($experience, 'InputfieldTextarea', 'frontend_custom_map', $this->_('Custom framework adapter (JSON)'), $this->_('Optional role-to-attributes map. It can define a custom framework or override individual roles in a built-in adapter.'), 100, ['rows' => 7, 'collapsed' => Inputfield::collapsedBlank]);
		$customMap->notes = '{"input":{"class":"my-input"},"button_primary":{"class":"my-button","data-variant":"primary"},"badge":{"class":"my-badge"}}';
		$contactMode = $add($experience, 'InputfieldSelect', 'first_contact_mode', $this->_('First contact policy'), $this->_('Message Requests protects members from unsolicited conversations. A site may override this through Messenger::messagingDecision.'), 50);
		$contactMode->addOptions([
			'requests' => $this->_('Message Requests'),
			'open' => $this->_('Open messaging'),
			'closed' => $this->_('Site policy only'),
		]);
		$add($experience, 'InputfieldInteger', 'max_message_length', $this->_('Maximum message length'), $this->_('Characters allowed in one plain-text message.'), 50, ['min' => 1, 'max' => 50000]);
		$add($experience, 'InputfieldInteger', 'edit_window_minutes', $this->_('Edit window'), $this->_('Minutes after sending. Zero disables member editing.'), 50, ['min' => 0, 'max' => 1440]);
		$add($experience, 'InputfieldInteger', 'delete_window_minutes', $this->_('Delete-for-everyone window'), $this->_('Minutes after sending. Report evidence remains preserved.'), 50, ['min' => 0, 'max' => 1440]);
		$inputfields->add($experience);

		$limits = $fieldset('limits', $this->_('Requests and abuse limits'), 'shield', $this->_('Set per-member limits that slow spam and repeated unsolicited contact.'), true);
		$add($limits, 'InputfieldInteger', 'max_messages_minute', $this->_('Messages per minute'), $this->_('Per-sender message mutation limit.'), 34, ['min' => 1, 'max' => 1000]);
		$add($limits, 'InputfieldInteger', 'max_new_recipients_day', $this->_('New recipients per day'), $this->_('Limits how many members one sender may contact for the first time.'), 33, ['min' => 1, 'max' => 1000]);
		$add($limits, 'InputfieldInteger', 'max_pending_request_messages', $this->_('Messages in a pending request'), $this->_('The requester cannot continue sending after this limit until the recipient accepts.'), 33, ['min' => 1, 'max' => 100]);
		$inputfields->add($limits);

		$updates = $fieldset('updates', $this->_('Live updates'), 'refresh', $this->_('Control how often an open conversation and the conversation list check for changes.'));
		$add($updates, 'InputfieldInteger', 'poll_thread_seconds', $this->_('Open conversation interval'), $this->_('Seconds between checks while a member has a conversation open.'), 50, ['min' => 3, 'max' => 60]);
		$add($updates, 'InputfieldInteger', 'poll_inbox_seconds', $this->_('Conversation list interval'), $this->_('Seconds between inbox and Message Requests count checks.'), 50, ['min' => 10, 'max' => 300]);
		$inputfields->add($updates);

		$notifications = $fieldset('notifications', $this->_('Notifications and maintenance'), 'envelope', $this->_('Configure optional email delivery and the explicitly enabled local maintenance worker.'));
		$mailProvider = $add($notifications, 'InputfieldSelect', 'mail_module', $this->_('Email provider'), $this->_('Choose the WireMail transport used only by Messenger. Default follows the site-wide ProcessWire mail setting.'), 100);
		$mailProvider->notes = $this->_('Install and configure additional WireMail modules to make more providers available. Messenger never stores provider credentials.');
		$mailProviderOptions = $this->mailProviderOptions();
		$currentMailProvider = trim((string)$this->mail_module);
		if($currentMailProvider !== '' && !isset($mailProviderOptions[$currentMailProvider])) {
			$mailProviderOptions[$currentMailProvider] = sprintf($this->_('%s (not installed)'), $currentMailProvider);
		}
		$mailProvider->addOptions($mailProviderOptions);
		$mailProvider->value = $currentMailProvider;
		$add($notifications, 'InputfieldCheckbox', 'email_notifications', $this->_('Enable email notifications'), $this->_('Uses the selected WireMail provider; each participant preference still applies.'), 50);
		$add($notifications, 'InputfieldCheckbox', 'enable_cli', $this->_('Enable local maintenance CLI'), $this->_('Allows the notification outbox worker after an explicit site-root bootstrap.'), 50);
		$add($notifications, 'InputfieldURL', 'notification_origin', $this->_('Notification site origin'), $this->_('Canonical HTTPS origin used for links created by cron and CLI.'), 100, ['placeholder' => 'https://example.com']);
		$add($notifications, 'InputfieldEmail', 'from_email', $this->_('Notification sender email'), $this->_('Review this address before enabling email delivery.'), 50);
		$add($notifications, 'InputfieldText', 'from_name', $this->_('Notification sender name'), $this->_('Display name used for Messenger notification email.'), 50);
		$inputfields->add($notifications);

		$lifecycle = $fieldset('lifecycle', $this->_('Data lifecycle and encryption'), 'lock', $this->_('Review retention and the current non-sensitive encrypted-storage health.'));
		$add($lifecycle, 'InputfieldInteger', 'retention_days', $this->_('Retention days'), $this->_('Zero disables automatic retention. Destructive execution requires an explicit maintenance call.'), 50, ['min' => 0, 'max' => 3650]);
		/** @var InputfieldMarkup $encryption */
		$encryption = $this->wire('modules')->get('InputfieldMarkup');
		$encryption->name = 'messenger_encryption_health';
		$encryption->label = $this->_('Encrypted storage health');
		$encryption->columnWidth = 50;
		try {
			$status = $this->encryptionStatus();
			$keySource = $status['key_source'] === 'messengerEncryptionKey' ? $this->_('Dedicated Messenger key') : $this->_('ProcessWire tableSalt fallback');
			$plaintext = (int)$status['plaintext_messages'] + (int)$status['plaintext_report_fields'];
			$health = $plaintext === 0 ? $this->_('Healthy — no plaintext content detected') : sprintf($this->_('Action required — %d plaintext values detected'), $plaintext);
			$encryption->value = '<p><strong>' . $this->_('XChaCha20-Poly1305') . '</strong><br>' . $this->_('Key source:') . ' ' . $this->wire('sanitizer')->entities($keySource) . '<br>' . $this->wire('sanitizer')->entities($health) . '</p>';
		} catch(\Throwable $error) {
			$encryption->value = '<p>' . $this->_('Encryption health is unavailable. Check the configured Messenger encryption key and database connection.') . '</p>';
		}
		$lifecycle->add($encryption);
		$inputfields->add($lifecycle);

		return $inputfields;
	}

	public function mailProviderOptions(): array {
		$options = ['' => $this->_('Default (site WireMail setting)')];
		$modules = $this->wire('modules');
		foreach($modules->findByPrefix('WireMail') as $name) {
			$name = (string)$name;
			if($name === '' || $name === 'WireMail') continue;
			$info = (array)$modules->getModuleInfo($name);
			$title = trim((string)($info['title'] ?? ''));
			$options[$name] = $title !== '' && strcasecmp($title, $name) !== 0 ? $title . ' (' . $name . ')' : $name;
		}
		if(count($options) > 2) {
			$default = array_shift($options);
			natcasesort($options);
			$options = ['' => $default] + $options;
		}
		return $options;
	}

	public function mailProviderLabel(): string {
		$provider = trim((string)$this->mail_module);
		$options = $this->mailProviderOptions();
		if($provider === '') return (string)$options[''];
		return (string)($options[$provider] ?? sprintf($this->_('%s (not installed)'), $provider));
	}

	public function frontendFrameworks(): array {
		return [
			'semantic' => $this->_('Semantic HTML / module styles'),
			'designsystemet' => $this->_('Designsystemet'),
			'uikit' => $this->_('UIkit'),
			'bootstrap' => $this->_('Bootstrap'),
			'tailwind' => $this->_('Tailwind CSS'),
			'custom' => $this->_('Custom framework adapter'),
		];
	}

	/** Framework-neutral roles used by server-rendered and dynamic Messenger UI. */
	public function frontendUi(): array {
		$presets = [
			'semantic' => [],
			'designsystemet' => [
				'input' => ['class' => 'ds-input'], 'textarea' => ['class' => 'ds-input'],
				'button_primary' => ['class' => 'ds-button', 'data-variant' => 'primary'], 'button_secondary' => ['class' => 'ds-button', 'data-variant' => 'secondary'], 'button_tertiary' => ['class' => 'ds-button', 'data-variant' => 'tertiary'],
				'button_danger' => ['class' => 'ds-button', 'data-variant' => 'secondary'], 'badge' => ['class' => 'ds-tag'],
			],
			'uikit' => [
				'layout' => ['class' => 'uk-card uk-card-default'], 'tabs' => ['class' => 'uk-subnav uk-subnav-pill'],
				'input' => ['class' => 'uk-input'], 'textarea' => ['class' => 'uk-textarea'],
				'button_primary' => ['class' => 'uk-button uk-button-primary'], 'button_secondary' => ['class' => 'uk-button uk-button-default'], 'button_tertiary' => ['class' => 'uk-button uk-button-text'], 'button_danger' => ['class' => 'uk-button uk-button-danger'],
				'avatar' => ['class' => 'uk-border-circle'], 'badge' => ['class' => 'uk-badge'], 'request' => ['class' => 'uk-alert-warning'], 'dialog' => ['class' => 'uk-modal-dialog'],
			],
			'bootstrap' => [
				'layout' => ['class' => 'card'], 'tabs' => ['class' => 'nav nav-pills'], 'tab' => ['class' => 'nav-link'], 'conversation' => ['class' => 'list-group-item list-group-item-action'],
				'input' => ['class' => 'form-control'], 'textarea' => ['class' => 'form-control'],
				'button_primary' => ['class' => 'btn btn-primary'], 'button_secondary' => ['class' => 'btn btn-outline-secondary'], 'button_tertiary' => ['class' => 'btn btn-link'], 'button_danger' => ['class' => 'btn btn-outline-danger'],
				'avatar' => ['class' => 'rounded-circle'], 'badge' => ['class' => 'badge rounded-pill text-bg-danger'], 'thread_header' => ['class' => 'card-header'], 'request' => ['class' => 'alert alert-warning'], 'bubble' => ['class' => 'rounded-3'], 'composer' => ['class' => 'card-footer'], 'dialog' => ['class' => 'modal-content'],
			],
			'tailwind' => [
				'layout' => ['class' => 'overflow-hidden rounded-xl border'], 'tabs' => ['class' => 'flex gap-1 overflow-x-auto'], 'tab' => ['class' => 'inline-flex items-center rounded-md px-3 py-2'], 'conversation' => ['class' => 'flex w-full items-center gap-3 border-b px-3 py-3 text-left'],
				'input' => ['class' => 'block w-full rounded-md border px-3 py-2'], 'textarea' => ['class' => 'block w-full rounded-md border px-3 py-2'],
				'button_primary' => ['class' => 'inline-flex items-center justify-center rounded-md px-4 py-2 font-medium'], 'button_secondary' => ['class' => 'inline-flex items-center justify-center rounded-md border px-3 py-2 font-medium'], 'button_tertiary' => ['class' => 'inline-flex items-center px-2 py-1 text-sm'], 'button_danger' => ['class' => 'inline-flex items-center justify-center rounded-md border px-3 py-2 font-medium'],
				'avatar' => ['class' => 'inline-grid place-items-center rounded-full'], 'badge' => ['class' => 'inline-grid min-w-5 place-items-center rounded-full px-1.5 text-xs'], 'thread_header' => ['class' => 'flex items-center justify-between border-b p-3'], 'request' => ['class' => 'flex items-center justify-between border-b p-3'], 'bubble' => ['class' => 'rounded-2xl px-3 py-2'], 'composer' => ['class' => 'flex items-end gap-2 border-t p-3'], 'dialog' => ['class' => 'rounded-xl p-4 shadow-2xl'],
			],
		];
		$framework = isset($presets[(string)$this->frontend_framework]) ? (string)$this->frontend_framework : 'semantic';
		$map = $presets[$framework] ?? [];
		if($framework === 'custom' || trim((string)$this->frontend_custom_map) !== '') {
			$custom = json_decode((string)$this->frontend_custom_map, true);
			if(is_array($custom)) {
				foreach($custom as $role => $attributes) {
					if(!is_string($role) || !preg_match('/^[a-z][a-z0-9_]*$/', $role)) continue;
					if(is_string($attributes)) $attributes = ['class' => $attributes];
					if(is_array($attributes)) $map[$role] = $attributes;
				}
			}
		}
		return $map;
	}

	public function frontendAttributes(string $role, array $extra = []): string {
		$attributes = (array)($this->frontendUi()[$role] ?? []);
		if(isset($attributes['class'], $extra['class'])) {
			$attributes['class'] = trim((string)$attributes['class'] . ' ' . (string)$extra['class']);
			unset($extra['class']);
		}
		$attributes = array_merge($attributes, $extra);
		$out = '';
		foreach($attributes as $name => $value) {
			if(!is_scalar($value) || !preg_match('/^(?:class|id|role|title|aria-[a-z0-9-]+|data-[a-z0-9-]+)$/', (string)$name)) continue;
			$out .= ' ' . $name . '="' . htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
		}
		return $out;
	}

	public function canUse(?User $user = null): bool {
		$user = $user ?: $this->wire('user');
		return $user instanceof User && $user->id > 0 && $user->isLoggedin() && !$this->restriction($user)['active'];
	}

	public function canReceive(?User $user = null): bool {
		return $user instanceof User && $user->id > 0 && !$user->isGuest() && !$this->restriction($user)['active'];
	}

	public function canModerate(?User $user = null): bool {
		$user = $user ?: $this->wire('user');
		return $user instanceof User && ($user->isSuperuser() || $user->hasPermission(self::PERMISSION_MODERATE));
	}

	public function canViewContent(?User $user = null): bool {
		$user = $user ?: $this->wire('user');
		return $user instanceof User && ($user->isSuperuser() || $user->hasPermission(self::PERMISSION_VIEW_CONTENT));
	}

	public function canAdmin(?User $user = null): bool {
		$user = $user ?: $this->wire('user');
		return $user instanceof User && ($user->isSuperuser() || $user->hasPermission(self::PERMISSION_ADMIN));
	}

	/** Hookable site policy. Return decision allow, request or deny. */
	public function ___messagingDecision(User $actor, User $recipient, array $context = []): array {
		if(!$this->canUse($actor) || !$this->canReceive($recipient) || $actor->id === $recipient->id) return ['decision' => 'deny', 'reason' => 'Messaging is unavailable.'];
		if($this->isBlockedEitherWay((int)$actor->id, (int)$recipient->id)) return ['decision' => 'deny', 'reason' => 'Messaging is unavailable.'];
		$mode = in_array((string)$this->first_contact_mode, ['open', 'requests', 'closed'], true) ? (string)$this->first_contact_mode : 'requests';
		return ['decision' => $mode === 'open' ? 'allow' : ($mode === 'requests' ? 'request' : 'deny'), 'reason' => $mode === 'closed' ? 'A connection is required.' : ''];
	}

	public function startConversation(User $actor, User $recipient, string $body, string $clientId = '', array $context = []): array {
		$this->assertActor($actor);
		$existing = $this->directConversationId((int)$actor->id, (int)$recipient->id);
		if($existing > 0) {
			$conversation = $this->conversation($existing, $actor);
			if(!$conversation) throw new WirePermissionException('Conversation unavailable.');
			if((string)$conversation['request_state'] === 'declined') throw new WirePermissionException('This request was declined.');
			return ['conversation' => $conversation, 'message' => $this->sendMessage((int)$conversation['id'], $actor, $body, $clientId)];
		}
		$decision = $this->messagingDecision($actor, $recipient, $context);
		if(!in_array((string)($decision['decision'] ?? ''), ['allow', 'request'], true)) throw new WirePermissionException((string)($decision['reason'] ?? 'Messaging is unavailable.'));
		$this->assertNewRecipientRate((int)$actor->id);
		$body = $this->messageBody($body);
		$clientId = $this->clientId($clientId);
		$now = $this->now();
		$key = bin2hex(random_bytes(16));
		$directKey = $this->directKey((int)$actor->id, (int)$recipient->id);
		$requestState = (string)$decision['decision'] === 'request' ? 'pending' : 'accepted';
		$db = $this->wire('database');
		$db->beginTransaction();
		try {
			$stmt = $db->prepare('INSERT INTO `' . self::TABLE_CONVERSATIONS . '` (public_key,direct_key,type,state,request_state,requester_id,recipient_id,last_message_id,last_message_at,created_at,updated_at) VALUES (?,?,\'direct\',\'active\',?,?,?,0,NULL,?,?)');
			$stmt->execute([$key, $directKey, $requestState, (int)$actor->id, (int)$recipient->id, $now, $now]);
			$conversationId = (int)$db->lastInsertId();
			$participant = $db->prepare('INSERT INTO `' . self::TABLE_PARTICIPANTS . '` (conversation_id,user_id,role,last_read_message_id,archived_at,muted_until,hidden_before,email_mode,created_at) VALUES (?,?,?,0,NULL,NULL,NULL,\'instant\',?)');
			$participant->execute([$conversationId, (int)$actor->id, 'requester', $now]);
			$participant->execute([$conversationId, (int)$recipient->id, 'recipient', $now]);
			$message = $this->insertMessage($conversationId, $actor, $body, $clientId, $now);
			$this->queueEmailNotification($conversationId, (int)$message['id'], (int)$recipient->id, $now);
			$this->audit('conversation_created', $conversationId, (int)$message['id'], (int)$actor->id, ['request_state' => $requestState]);
			$db->commit();
		} catch(\Throwable $error) {
			if($db->inTransaction()) $db->rollBack();
			if(str_contains(strtolower($error->getMessage()), 'duplicate')) {
				$id = $this->directConversationId((int)$actor->id, (int)$recipient->id);
				if($id) return ['conversation' => $this->conversation($id, $actor), 'message' => $this->sendMessage($id, $actor, $body, $clientId)];
			}
			throw $error;
		}
		return ['conversation' => $this->conversation($conversationId, $actor), 'message' => $message];
	}

	public function sendMessage(int $conversationId, User $actor, string $body, string $clientId = ''): array {
		$this->assertActor($actor);
		$conversation = $this->conversation($conversationId, $actor);
		if(!$conversation || (string)$conversation['state'] !== 'active') throw new WirePermissionException('Conversation unavailable.');
		$otherId = $this->otherParticipantId($conversationId, (int)$actor->id);
		if($otherId < 1 || $this->isBlockedEitherWay((int)$actor->id, $otherId)) throw new WirePermissionException('Messaging is unavailable.');
		if((string)$conversation['request_state'] === 'declined') throw new WirePermissionException('This request was declined.');
		if((string)$conversation['request_state'] === 'pending' && (int)$conversation['requester_id'] !== (int)$actor->id) throw new WirePermissionException('Accept the message request before replying.');
		if((string)$conversation['request_state'] === 'pending') {
			$count = $this->scalar('SELECT COUNT(*) FROM `' . self::TABLE_MESSAGES . '` WHERE conversation_id=? AND sender_user_id=? AND deleted_at IS NULL', [$conversationId, (int)$actor->id]);
			if($count >= max(1, (int)$this->max_pending_request_messages)) throw new WirePermissionException('Wait for the recipient to accept this request.');
		}
		$this->assertMessageRate((int)$actor->id);
		$body = $this->messageBody($body);
		$clientId = $this->clientId($clientId);
		$existing = $this->messageByClientId((int)$actor->id, $clientId);
		if($existing) return $existing;
		$now = $this->now();
		$db = $this->wire('database');
		$db->beginTransaction();
		try {
			$message = $this->insertMessage($conversationId, $actor, $body, $clientId, $now);
			$this->queueEmailNotification($conversationId, (int)$message['id'], $otherId, $now);
			$this->audit('message_sent', $conversationId, (int)$message['id'], (int)$actor->id);
			$db->commit();
		} catch(\Throwable $error) {
			if($db->inTransaction()) $db->rollBack();
			$existing = $this->messageByClientId((int)$actor->id, $clientId);
			if($existing) return $existing;
			throw $error;
		}
		return $message;
	}

	public function conversationsForUser(User $actor, string $scope = 'inbox', ?string $before = null, int $limit = 30): array {
		$this->assertActor($actor);
		$scope = in_array($scope, ['inbox', 'requests', 'archived'], true) ? $scope : 'inbox';
		$limit = max(1, min(100, $limit));
		$where = ['p.user_id=?', 'c.state=\'active\''];
		$params = [(int)$actor->id];
		if($scope === 'requests') { $where[] = 'c.request_state=\'pending\''; $where[] = 'c.recipient_id=?'; $params[] = (int)$actor->id; $where[] = 'p.archived_at IS NULL'; }
		elseif($scope === 'archived') $where[] = 'p.archived_at IS NOT NULL';
		else { $where[] = 'p.archived_at IS NULL'; $where[] = '(c.request_state=\'accepted\' OR c.requester_id=?)'; $params[] = (int)$actor->id; }
		if($before && preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\|(\d+)$/', $before, $match)) { $where[] = '(COALESCE(c.last_message_at,c.created_at) < ? OR (COALESCE(c.last_message_at,c.created_at)=? AND c.id<?))'; array_push($params, $match[1], $match[1], (int)$match[2]); }
		$sql = 'SELECT c.*,p.last_read_message_id,p.archived_at,p.muted_until,p.email_mode,(SELECT COUNT(*) FROM `' . self::TABLE_MESSAGES . '` um WHERE um.conversation_id=c.id AND um.id>p.last_read_message_id AND um.sender_user_id<>p.user_id AND um.deleted_at IS NULL) unread_count FROM `' . self::TABLE_CONVERSATIONS . '` c JOIN `' . self::TABLE_PARTICIPANTS . '` p ON p.conversation_id=c.id WHERE ' . implode(' AND ', $where) . ' ORDER BY COALESCE(c.last_message_at,c.created_at) DESC,c.id DESC LIMIT ' . $limit;
		$stmt = $this->wire('database')->prepare($sql); $stmt->execute($params);
		$items = [];
		foreach($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) $items[] = $this->decorateConversation($row, $actor);
		$last = end($items);
		return [
			'items' => $items,
			'next_cursor' => $last ? (string)$last['sort_at'] . '|' . (int)$last['id'] : null,
			'pending_request_count' => $this->pendingRequestCount($actor),
		];
	}

	public function pendingRequestCount(User $actor): int {
		$this->assertActor($actor);
		return $this->scalar(
			'SELECT COUNT(*) FROM `' . self::TABLE_CONVERSATIONS . '` c JOIN `' . self::TABLE_PARTICIPANTS . '` p ON p.conversation_id=c.id AND p.user_id=? WHERE c.state=\'active\' AND c.request_state=\'pending\' AND c.recipient_id=? AND p.archived_at IS NULL',
			[(int)$actor->id, (int)$actor->id]
		);
	}

	public function conversation($identifier, User $actor): array {
		$this->assertActor($actor);
		$field = is_int($identifier) || ctype_digit((string)$identifier) ? 'c.id' : 'c.public_key';
		$stmt = $this->wire('database')->prepare('SELECT c.*,p.last_read_message_id,p.archived_at,p.muted_until,p.email_mode FROM `' . self::TABLE_CONVERSATIONS . '` c JOIN `' . self::TABLE_PARTICIPANTS . '` p ON p.conversation_id=c.id AND p.user_id=? WHERE ' . $field . '=? LIMIT 1');
		$stmt->execute([(int)$actor->id, $identifier]);
		$row = $stmt->fetch(\PDO::FETCH_ASSOC);
		return $row ? $this->decorateConversation($row, $actor) : [];
	}

	public function conversationMessages(int $conversationId, User $actor, ?int $beforeId = null, ?int $afterId = null, int $limit = 50): array {
		$conversation = $this->conversation($conversationId, $actor);
		if(!$conversation) throw new WirePermissionException('Conversation unavailable.');
		$limit = max(1, min(100, $limit));
		$where = ['conversation_id=?']; $params = [$conversationId];
		if($beforeId) { $where[] = 'id<?'; $params[] = $beforeId; }
		if($afterId) { $where[] = 'id>?'; $params[] = $afterId; }
		$order = $afterId ? 'ASC' : 'DESC';
		$where[] = 'id NOT IN (SELECT message_id FROM `' . self::TABLE_MESSAGE_HIDES . '` WHERE user_id=?)'; $params[] = (int)$actor->id;
		$stmt = $this->wire('database')->prepare('SELECT id,conversation_id,sender_user_id,client_id,body,reply_to_id,moderation_state,created_at,edited_at,deleted_at FROM `' . self::TABLE_MESSAGES . '` WHERE ' . implode(' AND ', $where) . ' ORDER BY id ' . $order . ' LIMIT ' . $limit);
		$stmt->execute($params); $items = $stmt->fetchAll(\PDO::FETCH_ASSOC);
		if(!$afterId) $items = array_reverse($items);
		foreach($items as &$item) { $item['id'] = (int)$item['id']; $item['conversation_id'] = (int)$item['conversation_id']; $item['sender_user_id'] = (int)$item['sender_user_id']; $item['body'] = $item['deleted_at'] ? '' : $this->decryptMessageRow($item); unset($item['client_id']); }
		return $items;
	}

	public function editMessage(int $messageId, User $actor, string $body): array {
		$this->assertActor($actor); $message=$this->ownedMessage($messageId,$actor); $window=max(0,(int)$this->edit_window_minutes);
		if($window<1||strtotime((string)$message['created_at'])<time()-$window*60||$message['deleted_at']) throw new WirePermissionException('This message can no longer be edited.');
		$body=$this->messageBody($body);$this->execute('UPDATE `' . self::TABLE_MESSAGES . '` SET body=?,edited_at=? WHERE id=?',[$this->encryptValue($body,$this->messageAad($message)),$this->now(),$messageId]);$this->audit('message_edited',(int)$message['conversation_id'],$messageId,(int)$actor->id);
		$message['body']=$body;$message['edited_at']=$this->now();return $message;
	}

	public function deleteMessage(int $messageId, User $actor): void {
		$message=$this->ownedMessage($messageId,$actor);$window=max(0,(int)$this->delete_window_minutes);
		if($window<1||strtotime((string)$message['created_at'])<time()-$window*60||$message['deleted_at']) throw new WirePermissionException('This message can no longer be removed.');
		$this->execute('UPDATE `' . self::TABLE_MESSAGES . '` SET body=\'\',deleted_at=? WHERE id=?',[$this->now(),$messageId]);$this->audit('message_deleted',(int)$message['conversation_id'],$messageId,(int)$actor->id);
	}

	public function hideMessage(int $messageId, User $actor): void {
		$stmt=$this->wire('database')->prepare('SELECT m.conversation_id FROM `' . self::TABLE_MESSAGES . '` m JOIN `' . self::TABLE_PARTICIPANTS . '` p ON p.conversation_id=m.conversation_id AND p.user_id=? WHERE m.id=?');$stmt->execute([(int)$actor->id,$messageId]);$conversationId=(int)$stmt->fetchColumn();if(!$conversationId)throw new WirePermissionException('Message unavailable.');
		$stmt=$this->wire('database')->prepare('INSERT IGNORE INTO `' . self::TABLE_MESSAGE_HIDES . '` (message_id,user_id,created_at) VALUES (?,?,?)');$stmt->execute([$messageId,(int)$actor->id,$this->now()]);$this->audit('message_hidden',$conversationId,$messageId,(int)$actor->id);
	}

	public function searchMessages(User $actor, string $query, int $limit = 30): array {
		$this->assertActor($actor);$query=trim($query);if(mb_strlen($query)<2) return [];$limit=max(1,min(50,$limit));
		$stmt=$this->wire('database')->prepare('SELECT m.id,m.conversation_id,m.sender_user_id,m.client_id,m.body,m.created_at FROM `' . self::TABLE_MESSAGES . '` m JOIN `' . self::TABLE_PARTICIPANTS . '` p ON p.conversation_id=m.conversation_id AND p.user_id=? WHERE m.deleted_at IS NULL AND m.moderation_state=\'visible\' AND m.id NOT IN (SELECT message_id FROM `' . self::TABLE_MESSAGE_HIDES . '` WHERE user_id=?) ORDER BY m.id DESC LIMIT 1000');
		$stmt->execute([(int)$actor->id,(int)$actor->id]);$result=[];
		foreach($stmt->fetchAll(\PDO::FETCH_ASSOC) as $message){$message['body']=$this->decryptMessageRow($message);if(mb_stripos($message['body'],$query)===false)continue;unset($message['client_id']);$message['id']=(int)$message['id'];$message['conversation_id']=(int)$message['conversation_id'];$message['sender_user_id']=(int)$message['sender_user_id'];$result[]=$message;if(count($result)>=$limit)break;}
		return $result;
	}

	public function searchRecipients(User $actor, string $query, int $limit = 10): array {
		$this->assertActor($actor);
		$query = trim($query);
		if(mb_strlen($query) < 2) return [];
		$limit = max(1, min(20, $limit));
		$value = $this->wire('sanitizer')->selectorValue($query);
		$candidates = $this->wire('users')->find('id!=' . (int)$actor->id . ', name*=' . $value . ', include=all, sort=name, limit=' . ($limit * 3));
		$result = [];
		foreach($candidates as $candidate) {
			if(!$candidate instanceof User || !$this->canReceive($candidate) || $this->isBlockedEitherWay((int)$actor->id, (int)$candidate->id)) continue;
			$title = trim((string)($candidate->get('display_name') ?: $candidate->get('title') ?: $candidate->name));
			$result[] = ['id' => (int)$candidate->id, 'name' => (string)$candidate->name, 'title' => $title];
			if(count($result) >= $limit) break;
		}
		return $result;
	}

	public function acceptRequest(int $conversationId, User $actor): array {
		$conversation = $this->conversation($conversationId, $actor);
		if(!$conversation || (int)$conversation['recipient_id'] !== (int)$actor->id || (string)$conversation['request_state'] !== 'pending') throw new WirePermissionException('Message request unavailable.');
		$this->execute('UPDATE `' . self::TABLE_CONVERSATIONS . '` SET request_state=\'accepted\',updated_at=? WHERE id=?', [$this->now(), $conversationId]);
		$this->audit('request_accepted', $conversationId, 0, (int)$actor->id);
		return $this->conversation($conversationId, $actor);
	}

	public function declineRequest(int $conversationId, User $actor): void {
		$conversation = $this->conversation($conversationId, $actor);
		if(!$conversation || (int)$conversation['recipient_id'] !== (int)$actor->id || (string)$conversation['request_state'] !== 'pending') throw new WirePermissionException('Message request unavailable.');
		$this->execute('UPDATE `' . self::TABLE_CONVERSATIONS . '` SET request_state=\'declined\',updated_at=? WHERE id=?', [$this->now(), $conversationId]);
		$this->audit('request_declined', $conversationId, 0, (int)$actor->id);
	}

	public function markRead(int $conversationId, User $actor, int $messageId): void {
		if(!$this->conversation($conversationId, $actor)) throw new WirePermissionException('Conversation unavailable.');
		$max = $this->scalar('SELECT COALESCE(MAX(id),0) FROM `' . self::TABLE_MESSAGES . '` WHERE conversation_id=? AND id<=?', [$conversationId, $messageId]);
		$this->execute('UPDATE `' . self::TABLE_PARTICIPANTS . '` SET last_read_message_id=GREATEST(last_read_message_id,CAST(? AS UNSIGNED)) WHERE conversation_id=? AND user_id=?', [$max, $conversationId, (int)$actor->id]);
	}

	public function setArchived(int $conversationId, User $actor, bool $archived): void { $this->participantMutation($conversationId, $actor, 'archived_at', $archived ? $this->now() : null); }
	public function setMuted(int $conversationId, User $actor, ?string $until): void { if($until && strtotime($until) === false) throw new WireException('Invalid mute time.'); $this->participantMutation($conversationId, $actor, 'muted_until', $until); }
	public function setEmailMode(int $conversationId, User $actor, string $mode): void { if(!in_array($mode, ['instant','digest','off'], true)) throw new WireException('Invalid notification preference.'); $this->participantMutation($conversationId, $actor, 'email_mode', $mode); }

	public function blockUser(User $actor, User $blocked, string $reason = ''): void {
		$this->assertActor($actor); if(!$blocked->id || $actor->id === $blocked->id) throw new WireException('Invalid user.');
		$stmt = $this->wire('database')->prepare('INSERT INTO `' . self::TABLE_BLOCKS . '` (blocker_user_id,blocked_user_id,reason,created_at) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE reason=VALUES(reason)');
		$stmt->execute([(int)$actor->id, (int)$blocked->id, mb_substr(trim($reason), 0, 500), $this->now()]);
		$this->audit('user_blocked', 0, 0, (int)$actor->id, ['blocked_user_id' => (int)$blocked->id]);
	}

	public function unblockUser(User $actor, User $blocked): void {
		$this->assertActor($actor);
		$this->execute('DELETE FROM `' . self::TABLE_BLOCKS . '` WHERE blocker_user_id=? AND blocked_user_id=?', [(int)$actor->id, (int)$blocked->id]);
		$this->audit('user_unblocked', 0, 0, (int)$actor->id, ['blocked_user_id' => (int)$blocked->id]);
	}

	public function reportMessage(User $actor, int $messageId, string $category, string $comment = ''): array {
		$this->assertActor($actor);
		$stmt = $this->wire('database')->prepare('SELECT m.*,c.public_key FROM `' . self::TABLE_MESSAGES . '` m JOIN `' . self::TABLE_CONVERSATIONS . '` c ON c.id=m.conversation_id JOIN `' . self::TABLE_PARTICIPANTS . '` p ON p.conversation_id=c.id AND p.user_id=? WHERE m.id=? LIMIT 1');
		$stmt->execute([(int)$actor->id, $messageId]); $message = $stmt->fetch(\PDO::FETCH_ASSOC);
		if(!$message || (int)$message['sender_user_id'] === (int)$actor->id) throw new WirePermissionException('Message unavailable.');
		$message['body'] = $this->decryptMessageRow($message);
		$category = $this->wire('sanitizer')->name($category); if(!in_array($category, ['spam','harassment','hate','sexual','fraud','privacy','other'], true)) $category = 'other';
		$now = $this->now();
		$reportContext = ['reporter_user_id'=>(int)$actor->id,'conversation_id'=>(int)$message['conversation_id'],'message_id'=>$messageId,'created_at'=>$now];
		$comment = mb_substr(trim($comment),0,2000);
		$stmt = $this->wire('database')->prepare('INSERT INTO `' . self::TABLE_REPORTS . '` (reporter_user_id,reported_user_id,conversation_id,message_id,category,comment,evidence_body,evidence_hash,status,assigned_user_id,resolution,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,\'open\',0,\'\',?,?)');
		$stmt->execute([(int)$actor->id, (int)$message['sender_user_id'], (int)$message['conversation_id'], $messageId, $category, $comment===''?'':$this->encryptValue($comment,$this->reportAad($reportContext,'comment')), $this->encryptValue((string)$message['body'],$this->reportAad($reportContext,'evidence_body')), $this->contentFingerprint((string)$message['body']), $now, $now]);
		$id = (int)$this->wire('database')->lastInsertId(); $this->audit('report_created', (int)$message['conversation_id'], $messageId, (int)$actor->id, ['report_id' => $id]);
		return $this->report($id, $actor);
	}

	public function reports(User $actor, string $status = 'open', int $limit = 50): array {
		if(!$this->canModerate($actor)) throw new WirePermissionException('Moderation access denied.');
		$status = in_array($status, ['open','reviewing','resolved','dismissed','all'], true) ? $status : 'open'; $limit = max(1,min(100,$limit));
		$sql = 'SELECT id,reporter_user_id,reported_user_id,conversation_id,message_id,category,status,assigned_user_id,created_at,updated_at FROM `' . self::TABLE_REPORTS . '`' . ($status === 'all' ? '' : ' WHERE status=' . $this->wire('database')->quote($status)) . ' ORDER BY created_at ASC,id ASC LIMIT ' . $limit;
		return $this->wire('database')->query($sql)->fetchAll(\PDO::FETCH_ASSOC);
	}

	public function reportStatusCounts(User $actor): array {
		if(!$this->canModerate($actor)) throw new WirePermissionException('Moderation access denied.');
		$counts=['open'=>0,'reviewing'=>0,'resolved'=>0,'dismissed'=>0,'all'=>0];
		$rows=$this->wire('database')->query('SELECT status,COUNT(*) total FROM `' . self::TABLE_REPORTS . '` GROUP BY status')->fetchAll(\PDO::FETCH_ASSOC);
		foreach($rows as $row){$status=(string)$row['status'];$total=(int)$row['total'];if(array_key_exists($status,$counts))$counts[$status]=$total;$counts['all']+=$total;}
		return $counts;
	}

	public function report(int $id, User $actor): array {
		$stmt = $this->wire('database')->prepare('SELECT * FROM `' . self::TABLE_REPORTS . '` WHERE id=? LIMIT 1'); $stmt->execute([$id]); $report = $stmt->fetch(\PDO::FETCH_ASSOC);
		if(!$report) return [];
		if((int)$report['reporter_user_id'] !== (int)$actor->id && !$this->canModerate($actor)) throw new WirePermissionException('Report unavailable.');
		if(!$this->canViewContent($actor) && (int)$report['reporter_user_id'] !== (int)$actor->id) unset($report['evidence_body'], $report['comment']);
		else { $report['evidence_body']=$this->decryptValue((string)$report['evidence_body'],$this->reportAad($report,'evidence_body')); $report['comment']=$report['comment']===''?'':$this->decryptValue((string)$report['comment'],$this->reportAad($report,'comment')); }
		if($this->canModerate($actor) && (int)$report['reporter_user_id'] !== (int)$actor->id) $this->audit('report_content_viewed',(int)$report['conversation_id'],(int)$report['message_id'],(int)$actor->id,['report_id'=>$id]);
		$report['resolution']=$report['resolution']===''?'':$this->decryptValue((string)$report['resolution'],$this->reportAad($report,'resolution'));
		return $report;
	}

	public function runNotificationOutbox(int $limit = 50, bool $execute = false): array {
		$limit=max(1,min(100,$limit));$stmt=$this->wire('database')->prepare('SELECT * FROM `' . self::TABLE_OUTBOX . '` WHERE status=\'pending\' AND available_at<=? ORDER BY id ASC LIMIT ' . $limit);$stmt->execute([$this->now()]);$rows=$stmt->fetchAll(\PDO::FETCH_ASSOC);$result=['selected'=>count($rows),'sent'=>0,'failed'=>0,'execute'=>$execute];if(!$execute)return $result;
		foreach($rows as $row){try{$recipient=$this->wire('users')->get((int)$row['recipient_user_id']);$email=$recipient->id?(string)$recipient->email:'';if(!$this->wire('sanitizer')->email($email))throw new WireException('Recipient email unavailable.');$conversation=$this->conversation((int)$row['conversation_id'],$recipient);if(!$conversation)throw new WireException('Conversation unavailable.');$mail=$this->newNotificationMail();$origin=rtrim((string)$this->notification_origin,'/');if($origin===''||!str_starts_with($origin,'https://'))throw new WireException('Notification origin unavailable.');$url=$origin . '/' . trim((string)$this->public_path,'/') . '/?conversation=' . rawurlencode((string)$conversation['public_key']);$mail->to($email)->from((string)$this->from_email,(string)$this->from_name)->subject($this->_('You have a new message'))->body($this->_('A member sent you a private message. Open your inbox: ') . $url);if(method_exists($mail,'addTag'))$mail->addTag('source','messenger');if((int)$mail->send()<1)throw new WireException('Delivery failed.');$this->execute('UPDATE `' . self::TABLE_OUTBOX . '` SET status=\'sent\',processed_at=?,attempts=attempts+1,last_error=\'\' WHERE id=?',[$this->now(),(int)$row['id']]);$result['sent']++;}catch(\Throwable $error){$this->execute('UPDATE `' . self::TABLE_OUTBOX . '` SET attempts=attempts+1,last_error=?,available_at=DATE_ADD(NOW(),INTERVAL LEAST(60,POW(2,attempts)) MINUTE),status=IF(attempts>=5,\'failed\',\'pending\') WHERE id=?',[mb_substr($error->getMessage(),0,500),(int)$row['id']]);$result['failed']++;}}
		return $result;
	}

	private function newNotificationMail(): WireMail {
		$modules = $this->wire('modules');
		$provider = trim((string)$this->mail_module);
		if($provider !== '' && (!str_starts_with($provider, 'WireMail') || !$modules->isInstalled($provider))) throw new WireException('Selected WireMail provider is unavailable.');
		/** @var WireMail|null $mail */
		$mail = $provider !== '' ? $this->wire('mail')->new($provider) : $this->wire('mail')->new();
		if(!$mail) throw new WireException('WireMail provider unavailable.');
		if(method_exists($mail, 'apiKeyPermission') && $mail->apiKeyPermission() === '') throw new WireException('Selected WireMail provider is not configured.');
		return $mail;
	}

	public function updateReport(int $id, User $actor, string $status, string $resolution = ''): array {
		if(!$this->canModerate($actor)) throw new WirePermissionException('Moderation access denied.');
		if(!in_array($status, ['open','reviewing','resolved','dismissed'], true)) throw new WireException('Invalid report status.');
		$stmt=$this->wire('database')->prepare('SELECT reporter_user_id,conversation_id,message_id,created_at FROM `' . self::TABLE_REPORTS . '` WHERE id=? LIMIT 1');$stmt->execute([$id]);$report=$stmt->fetch(\PDO::FETCH_ASSOC);if(!$report)throw new WireException('Report unavailable.');
		$resolution=mb_substr(trim($resolution),0,2000);$storedResolution=$resolution===''?'':$this->encryptValue($resolution,$this->reportAad($report,'resolution'));
		$this->execute('UPDATE `' . self::TABLE_REPORTS . '` SET status=?,assigned_user_id=?,resolution=?,updated_at=? WHERE id=?', [$status,(int)$actor->id,$storedResolution,$this->now(),$id]);
		$this->audit('report_updated', 0, 0, (int)$actor->id, ['report_id'=>$id,'status'=>$status]);
		return $this->report($id, $actor);
	}

	public function restrictUser(User $target, User $actor, ?string $until, string $reason): void {
		if(!$this->canModerate($actor)) throw new WirePermissionException('Moderation access denied.');
		if($target->isSuperuser() || !$target->id) throw new WireException('This user cannot be restricted.');
		if($until && strtotime($until) === false) throw new WireException('Invalid restriction time.');
		$stmt = $this->wire('database')->prepare('INSERT INTO `' . self::TABLE_RESTRICTIONS . '` (user_id,status,reason,expires_at,created_by,created_at,updated_at) VALUES (?,\'suspended\',?,?,?,?,?) ON DUPLICATE KEY UPDATE status=\'suspended\',reason=VALUES(reason),expires_at=VALUES(expires_at),created_by=VALUES(created_by),updated_at=VALUES(updated_at)');
		$now=$this->now(); $stmt->execute([(int)$target->id,mb_substr(trim($reason),0,1000),$until,(int)$actor->id,$now,$now]);
		$this->audit('user_restricted',0,0,(int)$actor->id,['user_id'=>(int)$target->id,'until'=>$until]);
	}

	public function clearRestriction(User $target, User $actor): void {
		if(!$this->canModerate($actor)) throw new WirePermissionException('Moderation access denied.');
		$this->execute('UPDATE `' . self::TABLE_RESTRICTIONS . '` SET status=\'cleared\',updated_at=? WHERE user_id=?',[$this->now(),(int)$target->id]);
		$this->audit('restriction_cleared',0,0,(int)$actor->id,['user_id'=>(int)$target->id]);
	}

	public function restrictionStatus(User $target, User $actor): array {
		if(!$this->canModerate($actor)) throw new WirePermissionException('Moderation access denied.');
		if(!$target->id || $target->isGuest()) return ['active'=>false,'reason'=>'','expires_at'=>null];
		return $this->restriction($target)+['reason'=>'','expires_at'=>null];
	}

	public function activeRestrictions(User $actor, int $limit = 100): array {
		if(!$this->canModerate($actor)) throw new WirePermissionException('Moderation access denied.');
		$limit=max(1,min(100,$limit));
		$stmt=$this->wire('database')->query('SELECT id,user_id,status,reason,expires_at,created_by,created_at,updated_at FROM `' . self::TABLE_RESTRICTIONS . '` WHERE status=\'suspended\' AND (expires_at IS NULL OR expires_at>NOW()) ORDER BY COALESCE(expires_at,\'9999-12-31 23:59:59\') ASC,id ASC LIMIT ' . $limit);
		return $stmt->fetchAll(\PDO::FETCH_ASSOC);
	}

	public function moderationStats(User $actor): array {
		if(!$this->canModerate($actor)) throw new WirePermissionException('Moderation access denied.');
		return [
			'open_reports'=>$this->scalar('SELECT COUNT(*) FROM `' . self::TABLE_REPORTS . '` WHERE status IN (\'open\',\'reviewing\')'),
			'pending_requests'=>$this->scalar('SELECT COUNT(*) FROM `' . self::TABLE_CONVERSATIONS . '` WHERE request_state=\'pending\''),
			'active_conversations'=>$this->scalar('SELECT COUNT(*) FROM `' . self::TABLE_CONVERSATIONS . '` WHERE state=\'active\''),
			'messages_24h'=>$this->scalar('SELECT COUNT(*) FROM `' . self::TABLE_MESSAGES . '` WHERE created_at>=?',[date('Y-m-d H:i:s',time()-86400)]),
			'active_restrictions'=>$this->scalar('SELECT COUNT(*) FROM `' . self::TABLE_RESTRICTIONS . '` WHERE status=\'suspended\' AND (expires_at IS NULL OR expires_at>NOW())'),
		];
	}

	public function renderApp(User $actor, array $options = []): string {
		if(!$actor->id || !$actor->isLoggedin()) throw new WirePermissionException('Messenger access denied.');
		$config = $this->wire('config');
		$config->styles->add($config->urls->Messenger . 'assets/messenger.css?v=' . self::VERSION);
		$title = $this->wire('sanitizer')->entities((string)($options['title'] ?? $this->_('Messages')));
		$framework = array_key_exists((string)$this->frontend_framework, $this->frontendFrameworks()) ? (string)$this->frontend_framework : 'semantic';
		$ui = json_encode($this->frontendUi(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		if(!is_string($ui)) $ui = '{}';
		$restriction = $this->restriction($actor);
		if($restriction['active']) {
			$until = '';
			if(!empty($restriction['expires_at'])) {
				$timestamp = strtotime((string)$restriction['expires_at']);
				$formatted = $timestamp ? date('M j, Y · H:i', $timestamp) : (string)$restriction['expires_at'];
				$until = '<p>' . sprintf($this->_('Access is scheduled to return after %s.'), $this->wire('sanitizer')->entities($formatted)) . '</p>';
			}
			return '<section' . $this->frontendAttributes('app', ['class' => 'MessengerApp MessengerApp--restricted', 'data-framework' => $framework, 'data-ui' => $ui]) . '><header' . $this->frontendAttributes('header', ['class' => 'MessengerApp-header']) . '><h1>' . $title . '</h1></header><div' . $this->frontendAttributes('empty', ['class' => 'MessengerEmpty', 'role' => 'status']) . '><h2>' . $this->_('Messaging unavailable') . '</h2><p>' . $this->_('Your account is temporarily unable to use Messenger.') . '</p>' . $until . '<p>' . $this->_('Contact support if you believe this is a mistake.') . '</p></div></section>';
		}
		$config->scripts->add($config->urls->Messenger . 'assets/messenger.js?v=' . self::VERSION);
		$endpoint = (string)($options['api'] ?? '/messenger-api/' . self::REST_API_VERSION . '/');
		return '<section' . $this->frontendAttributes('app', [
			'class' => 'MessengerApp', 'data-messenger-app' => '', 'data-api' => $endpoint,
			'data-thread-poll' => max(3,(int)$this->poll_thread_seconds), 'data-inbox-poll' => max(10,(int)$this->poll_inbox_seconds),
			'data-framework' => $framework, 'data-ui' => $ui,
		]) . '><header' . $this->frontendAttributes('header', ['class' => 'MessengerApp-header']) . '><h1>' . $title . '</h1><button type="button"' . $this->frontendAttributes('button_primary', ['data-messenger-new' => '']) . '>' . $this->_('New message') . '</button></header><div' . $this->frontendAttributes('layout', ['class' => 'MessengerApp-layout']) . '><aside' . $this->frontendAttributes('inbox', ['class' => 'MessengerInbox', 'aria-label' => $this->_('Conversations')]) . '><nav' . $this->frontendAttributes('tabs') . '><button type="button"' . $this->frontendAttributes('tab', ['data-scope' => 'inbox', 'aria-current' => 'page']) . '>' . $this->_('Inbox') . '</button><button type="button"' . $this->frontendAttributes('tab', ['data-scope' => 'requests']) . '>' . $this->_('Requests') . '</button><button type="button"' . $this->frontendAttributes('tab', ['data-scope' => 'archived']) . '>' . $this->_('Archived') . '</button></nav><form' . $this->frontendAttributes('search_form', ['class' => 'MessengerSearch', 'data-message-search' => '']) . '><input type="search" name="q" minlength="2" placeholder="' . $this->_('Search messages') . '"' . $this->frontendAttributes('input') . '><button type="submit"' . $this->frontendAttributes('button_secondary', ['aria-label' => $this->_('Search')]) . '>⌕</button></form><div' . $this->frontendAttributes('conversation_list', ['data-conversation-list' => '', 'aria-live' => 'polite']) . '><p' . $this->frontendAttributes('status', ['class' => 'MessengerState']) . '>' . $this->_('Loading conversations…') . '</p></div></aside><main' . $this->frontendAttributes('thread', ['class' => 'MessengerThread', 'data-thread' => '']) . '><div' . $this->frontendAttributes('empty', ['class' => 'MessengerEmpty']) . '><h2>' . $this->_('Select a conversation') . '</h2><p>' . $this->_('Your private messages and requests appear here.') . '</p></div></main></div><div' . $this->frontendAttributes('status', ['class' => 'MessengerLive', 'role' => 'status', 'aria-live' => 'polite', 'data-messenger-status' => '']) . '></div></section>';
	}

	public function encryptionStatus(): array {
		$this->encryptionKey();
		return [
			'ready'=>true,
			'key_source'=>trim((string)$this->wire('config')->get('messengerEncryptionKey'))!==''?'messengerEncryptionKey':'tableSalt',
			'plaintext_messages'=>$this->scalar('SELECT COUNT(*) FROM `' . self::TABLE_MESSAGES . '` WHERE body<>\'\' AND LEFT(body,8)<>?', [self::ENCRYPTION_PREFIX]),
			'plaintext_report_fields'=>$this->scalar('SELECT COUNT(*) FROM `' . self::TABLE_REPORTS . '` WHERE (evidence_body<>\'\' AND LEFT(evidence_body,8)<>?) OR (comment<>\'\' AND LEFT(comment,8)<>?) OR (resolution<>\'\' AND LEFT(resolution,8)<>?)', [self::ENCRYPTION_PREFIX,self::ENCRYPTION_PREFIX,self::ENCRYPTION_PREFIX]),
		];
	}

	private function beginWriteTransaction($database): void {
		if($database->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite') {
			$database->exec('BEGIN IMMEDIATE');
			return;
		}
		$database->beginTransaction();
	}

	private function forUpdate($database): string {
		return $database->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite' ? '' : ' FOR UPDATE';
	}

	public function migrateEncryptionAtRest(): array {
		$this->encryptionKey();$db=$this->wire('database');$result=['messages'=>0,'reports'=>0];$this->beginWriteTransaction($db);
		try {
			$messages=$db->query('SELECT id,conversation_id,sender_user_id,client_id,body,created_at FROM `' . self::TABLE_MESSAGES . '` WHERE body<>\'\' AND LEFT(body,8)<>\'' . self::ENCRYPTION_PREFIX . '\'' . $this->forUpdate($db))->fetchAll(\PDO::FETCH_ASSOC);
			$updateMessage=$db->prepare('UPDATE `' . self::TABLE_MESSAGES . '` SET body=? WHERE id=?');
			foreach($messages as $message){$encrypted=$this->encryptValue((string)$message['body'],$this->messageAad($message));if($this->decryptValue($encrypted,$this->messageAad($message))!==(string)$message['body'])throw new WireException('Messenger encryption verification failed.');$updateMessage->execute([$encrypted,(int)$message['id']]);$result['messages']++;}
			$reports=$db->query('SELECT id,reporter_user_id,conversation_id,message_id,comment,evidence_body,resolution,created_at FROM `' . self::TABLE_REPORTS . '`' . $this->forUpdate($db))->fetchAll(\PDO::FETCH_ASSOC);
			$updateReport=$db->prepare('UPDATE `' . self::TABLE_REPORTS . '` SET comment=?,evidence_body=?,evidence_hash=?,resolution=? WHERE id=?');
			foreach($reports as $report){$evidencePlain=str_starts_with((string)$report['evidence_body'],self::ENCRYPTION_PREFIX)?$this->decryptValue((string)$report['evidence_body'],$this->reportAad($report,'evidence_body')):(string)$report['evidence_body'];foreach(['comment','evidence_body','resolution'] as $field)if($report[$field]!==''&&!str_starts_with((string)$report[$field],self::ENCRYPTION_PREFIX))$report[$field]=$this->encryptValue((string)$report[$field],$this->reportAad($report,$field));$updateReport->execute([$report['comment'],$report['evidence_body'],$this->contentFingerprint($evidencePlain),$report['resolution'],(int)$report['id']]);$result['reports']++;}
			$db->commit();
		} catch(\Throwable $error) { if($db->inTransaction())$db->rollBack();throw $error; }
		$status=$this->encryptionStatus();if($status['plaintext_messages']||$status['plaintext_report_fields'])throw new WireException('Messenger encryption migration is incomplete.');return $result+$status;
	}

	public function install(): void { $this->encryptionKey(); $this->installPermissions(); $this->installTables(); }
	public function upgrade($fromVersion, $toVersion): void { $this->installPermissions(); $this->installTables(); if((int)$fromVersion<7&&(int)$toVersion>=7)$this->migrateEncryptionAtRest(); }
	public function uninstall(): void { $this->message($this->_('Messenger data and permissions were retained.')); }

	private function insertMessage(int $conversationId, User $actor, string $body, string $clientId, string $now): array {
		$stmt=$this->wire('database')->prepare('INSERT INTO `' . self::TABLE_MESSAGES . '` (conversation_id,sender_user_id,client_id,body,reply_to_id,moderation_state,created_at,edited_at,deleted_at) VALUES (?,?,?,?,0,\'visible\',?,NULL,NULL)');
		$message=['conversation_id'=>$conversationId,'sender_user_id'=>(int)$actor->id,'client_id'=>$clientId,'created_at'=>$now];
		$stmt->execute([$conversationId,(int)$actor->id,$clientId,$this->encryptValue($body,$this->messageAad($message)),$now]); $id=(int)$this->wire('database')->lastInsertId();
		$this->execute('UPDATE `' . self::TABLE_CONVERSATIONS . '` SET last_message_id=?,last_message_at=?,updated_at=? WHERE id=?',[$id,$now,$now,$conversationId]);
		return ['id'=>$id,'conversation_id'=>$conversationId,'sender_user_id'=>(int)$actor->id,'body'=>$body,'moderation_state'=>'visible','created_at'=>$now,'edited_at'=>null,'deleted_at'=>null];
	}

	private function decorateConversation(array $row, User $actor): array {
		$otherId=(int)$row['requester_id']===(int)$actor->id?(int)$row['recipient_id']:(int)$row['requester_id']; $other=$this->wire('users')->get($otherId);
		$row['id']=(int)$row['id']; $row['requester_id']=(int)$row['requester_id']; $row['recipient_id']=(int)$row['recipient_id']; $row['last_message_id']=(int)$row['last_message_id']; $row['last_read_message_id']=(int)($row['last_read_message_id']??0); $row['unread_count']=(int)($row['unread_count']??0);
		$row['sort_at']=(string)($row['last_message_at']?:$row['created_at']);
		$row['blocked_by_actor']=$this->isBlockedBy((int)$actor->id,$otherId);
		$row['blocked_by_other']=$this->isBlockedBy($otherId,(int)$actor->id);
		$row['blocked']=$row['blocked_by_actor']||$row['blocked_by_other'];
		$row['other_user']=['id'=>$otherId,'name'=>$other->id?(string)$other->name:'','title'=>$other->id?(string)($other->get('title')?:$other->name):$this->_('Unavailable member')];
		if($row['last_message_id']) { $stmt=$this->wire('database')->prepare('SELECT id,conversation_id,sender_user_id,client_id,body,created_at,deleted_at FROM `' . self::TABLE_MESSAGES . '` WHERE id=?'); $stmt->execute([(int)$row['last_message_id']]); $last=$stmt->fetch(\PDO::FETCH_ASSOC); if($last){$last['body']=$last['deleted_at']?'':mb_substr($this->decryptMessageRow($last),0,160);unset($last['client_id']);$row['last_message']=$last;} }
		unset($row['direct_key']); return $row;
	}

	private function participantMutation(int $conversationId, User $actor, string $column, $value): void {
		if(!$this->conversation($conversationId,$actor)) throw new WirePermissionException('Conversation unavailable.');
		if(!in_array($column,['archived_at','muted_until','email_mode'],true)) throw new WireException('Invalid preference.');
		$this->execute('UPDATE `' . self::TABLE_PARTICIPANTS . '` SET `' . $column . '`=? WHERE conversation_id=? AND user_id=?',[$value,$conversationId,(int)$actor->id]);
	}

	private function ownedMessage(int $messageId, User $actor): array {
		$stmt=$this->wire('database')->prepare('SELECT m.* FROM `' . self::TABLE_MESSAGES . '` m JOIN `' . self::TABLE_PARTICIPANTS . '` p ON p.conversation_id=m.conversation_id AND p.user_id=? WHERE m.id=? AND m.sender_user_id=? LIMIT 1');$stmt->execute([(int)$actor->id,$messageId,(int)$actor->id]);$message=$stmt->fetch(\PDO::FETCH_ASSOC);if(!$message)throw new WirePermissionException('Message unavailable.');$message['body']=$message['deleted_at']?'':$this->decryptMessageRow($message);return $message;
	}

	private function restriction(User $user): array {
		if(!$user->id) return ['active'=>true]; $stmt=$this->wire('database')->prepare('SELECT status,reason,expires_at FROM `' . self::TABLE_RESTRICTIONS . '` WHERE user_id=? LIMIT 1');
		try{$stmt->execute([(int)$user->id]);$row=$stmt->fetch(\PDO::FETCH_ASSOC);}catch(\Throwable $e){return ['active'=>false];}
		$active=$row&&$row['status']==='suspended'&&(!$row['expires_at']||strtotime($row['expires_at'])>time()); return ['active'=>$active,'reason'=>$active?(string)$row['reason']:'','expires_at'=>$active?$row['expires_at']:null];
	}

	private function queueEmailNotification(int $conversationId,int $messageId,int $recipientId,string $now): void {
		if(!(bool)$this->email_notifications) return;
		$stmt=$this->wire('database')->prepare('SELECT email_mode,muted_until FROM `' . self::TABLE_PARTICIPANTS . '` WHERE conversation_id=? AND user_id=?');$stmt->execute([$conversationId,$recipientId]);$pref=$stmt->fetch(\PDO::FETCH_ASSOC);
		if(!$pref||$pref['email_mode']==='off'||($pref['muted_until']&&strtotime($pref['muted_until'])>time())) return;
		$this->execute('INSERT INTO `' . self::TABLE_OUTBOX . '` (event_key,conversation_id,message_id,recipient_user_id,channel,status,available_at,attempts,last_error,created_at,processed_at) VALUES (?,?,?,?,\'email\',\'pending\',?,0,\'\',?,NULL)',['message:' . $messageId . ':email:' . $recipientId,$conversationId,$messageId,$recipientId,$pref['email_mode']==='digest'?date('Y-m-d H:00:00',time()+3600):$now,$now]);
	}

	private function audit(string $action,int $conversationId,int $messageId,int $actorId,array $metadata=[]): void {
		$this->execute('INSERT INTO `' . self::TABLE_AUDIT . '` (actor_user_id,action,conversation_id,message_id,metadata,created_at) VALUES (?,?,?,?,?,?)',[$actorId,$action,$conversationId,$messageId,json_encode($metadata,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$this->now()]);
	}

	private function assertActor(User $actor): void { if(!$this->canUse($actor)) throw new WirePermissionException('Messenger access denied.'); }
	private function encryptionKey(): string { if(!function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt'))throw new WireException('Messenger requires the sodium extension for encrypted storage.');$config=$this->wire('config');$secret=trim((string)$config->get('messengerEncryptionKey'));if($secret==='')$secret=(string)$config->tableSalt;if($secret==='')throw new WireException('Configure messengerEncryptionKey or tableSalt before using Messenger.');return sodium_crypto_generichash(self::ENCRYPTION_CONTEXT,hash('sha256',$secret,true),SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES); }
	private function encryptValue(string $plaintext,string $aad): string { $nonce=random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);$cipher=sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plaintext,$aad,$nonce,$this->encryptionKey());return self::ENCRYPTION_PREFIX . sodium_bin2base64($nonce.$cipher,SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING); }
	private function decryptValue(string $stored,string $aad): string {
		if($stored==='')return '';
		if(!str_starts_with($stored,self::ENCRYPTION_PREFIX))throw new WireException('Messenger found unencrypted stored content.');
		try {
			$packed=sodium_base642bin(substr($stored,strlen(self::ENCRYPTION_PREFIX)),SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
			$minimum=SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES+SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_ABYTES;
			if(strlen($packed)<$minimum)throw new WireException('Invalid encrypted Messenger envelope.');
			$nonce=substr($packed,0,SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
			$cipher=substr($packed,SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
			$plain=sodium_crypto_aead_xchacha20poly1305_ietf_decrypt($cipher,$aad,$nonce,$this->encryptionKey());
		} catch(\Throwable $error) {
			$plain=false;
		}
		if($plain===false)throw new WireException('Encrypted Messenger content failed authentication.');
		return $plain;
	}
	private function messageAad(array $message): string { return self::ENCRYPTION_CONTEXT . '|message|' . (int)$message['conversation_id'] . '|' . (int)$message['sender_user_id'] . '|' . (string)$message['client_id'] . '|' . (string)$message['created_at']; }
	private function reportAad(array $report,string $field): string { return self::ENCRYPTION_CONTEXT . '|report|' . $field . '|' . (int)$report['reporter_user_id'] . '|' . (int)$report['conversation_id'] . '|' . (int)$report['message_id'] . '|' . (string)$report['created_at']; }
	private function contentFingerprint(string $plaintext): string { return sodium_bin2hex(sodium_crypto_generichash('Messenger|evidence|' . $plaintext,$this->encryptionKey(),32)); }
	private function decryptMessageRow(array $message): string { return $this->decryptValue((string)$message['body'],$this->messageAad($message)); }
	private function messageBody(string $body): string { $body=trim(str_replace(["\r\n","\r"],"\n",$body)); $max=max(1,min(50000,(int)$this->max_message_length)); if($body===''||mb_strlen($body)>$max) throw new WireException('Message is empty or too long.'); return $body; }
	private function clientId(string $id): string { $id=trim($id); if($id==='') return bin2hex(random_bytes(16)); if(!preg_match('/^[A-Za-z0-9_-]{16,64}$/',$id)) throw new WireException('Invalid client message id.'); return $id; }
	private function directKey(int $a,int $b): string { return hash('sha256',min($a,$b) . ':' . max($a,$b)); }
	private function directConversationId(int $a,int $b): int { return $this->scalar('SELECT id FROM `' . self::TABLE_CONVERSATIONS . '` WHERE direct_key=? LIMIT 1',[$this->directKey($a,$b)]); }
	private function otherParticipantId(int $conversationId,int $actorId): int { return $this->scalar('SELECT user_id FROM `' . self::TABLE_PARTICIPANTS . '` WHERE conversation_id=? AND user_id<>? LIMIT 1',[$conversationId,$actorId]); }
	private function isBlockedBy(int $blockerId,int $blockedId): bool { return $this->scalar('SELECT COUNT(*) FROM `' . self::TABLE_BLOCKS . '` WHERE blocker_user_id=? AND blocked_user_id=?',[$blockerId,$blockedId])>0; }
	private function isBlockedEitherWay(int $a,int $b): bool { return $this->scalar('SELECT COUNT(*) FROM `' . self::TABLE_BLOCKS . '` WHERE (blocker_user_id=? AND blocked_user_id=?) OR (blocker_user_id=? AND blocked_user_id=?)',[$a,$b,$b,$a])>0; }
	private function messageByClientId(int $userId,string $clientId): array { $stmt=$this->wire('database')->prepare('SELECT id,conversation_id,sender_user_id,client_id,body,moderation_state,created_at,edited_at,deleted_at FROM `' . self::TABLE_MESSAGES . '` WHERE sender_user_id=? AND client_id=? LIMIT 1');$stmt->execute([$userId,$clientId]);$message=$stmt->fetch(\PDO::FETCH_ASSOC)?:[];if($message){$message['body']=$message['deleted_at']?'':$this->decryptMessageRow($message);unset($message['client_id']);}return $message; }
	private function assertMessageRate(int $userId): void { $count=$this->scalar('SELECT COUNT(*) FROM `' . self::TABLE_MESSAGES . '` WHERE sender_user_id=? AND created_at>=?',[$userId,date('Y-m-d H:i:s',time()-60)]); if($count>=max(1,(int)$this->max_messages_minute)) throw new WireException('Please wait before sending more messages.'); }
	private function assertNewRecipientRate(int $userId): void { $count=$this->scalar('SELECT COUNT(*) FROM `' . self::TABLE_CONVERSATIONS . '` WHERE requester_id=? AND created_at>=?',[$userId,date('Y-m-d H:i:s',time()-86400)]); if($count>=max(1,(int)$this->max_new_recipients_day)) throw new WireException('Daily new conversation limit reached.'); }
	private function now(): string { return date('Y-m-d H:i:s'); }
	private function scalar(string $sql,array $params=[]): int { $stmt=$this->wire('database')->prepare($sql);$stmt->execute($params);return (int)$stmt->fetchColumn(); }
	private function execute(string $sql,array $params=[]): void { $stmt=$this->wire('database')->prepare($sql);$stmt->execute($params); }

	private function installPermissions(): void {
		$definitions=[self::PERMISSION_MODERATE=>'Moderate Messenger reports',self::PERMISSION_VIEW_CONTENT=>'View reported message content',self::PERMISSION_ADMIN=>'Administer Messenger',self::PERMISSION_EXPORT=>'Export Messenger data',self::PERMISSION_DELETE=>'Permanently delete Messenger data'];
		foreach($definitions as $name=>$title){if($this->wire('permissions')->get($name)->id)continue;$permission=new Permission();$permission->name=$name;$permission->title=$title;$permission->save();}
	}

	private function installTables(): void {
		$db=$this->wire('database');
		$db->exec('CREATE TABLE IF NOT EXISTS `' . self::TABLE_CONVERSATIONS . '` (`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,`public_key` CHAR(32) NOT NULL,`direct_key` CHAR(64) NOT NULL,`type` VARCHAR(20) NOT NULL DEFAULT \'direct\',`state` VARCHAR(20) NOT NULL DEFAULT \'active\',`request_state` VARCHAR(20) NOT NULL DEFAULT \'pending\',`requester_id` INT UNSIGNED NOT NULL,`recipient_id` INT UNSIGNED NOT NULL,`last_message_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,`last_message_at` DATETIME NULL,`created_at` DATETIME NOT NULL,`updated_at` DATETIME NOT NULL,PRIMARY KEY (`id`),UNIQUE KEY `public_key` (`public_key`),UNIQUE KEY `direct_key` (`direct_key`),KEY `activity` (`last_message_at`,`id`),KEY `requests` (`recipient_id`,`request_state`,`updated_at`),KEY `requester` (`requester_id`,`created_at`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
		$db->exec('CREATE TABLE IF NOT EXISTS `' . self::TABLE_PARTICIPANTS . '` (`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,`conversation_id` BIGINT UNSIGNED NOT NULL,`user_id` INT UNSIGNED NOT NULL,`role` VARCHAR(20) NOT NULL DEFAULT \'member\',`last_read_message_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,`archived_at` DATETIME NULL,`muted_until` DATETIME NULL,`hidden_before` DATETIME NULL,`email_mode` VARCHAR(20) NOT NULL DEFAULT \'instant\',`created_at` DATETIME NOT NULL,PRIMARY KEY (`id`),UNIQUE KEY `conversation_user` (`conversation_id`,`user_id`),KEY `user_inbox` (`user_id`,`archived_at`,`conversation_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
		$db->exec('CREATE TABLE IF NOT EXISTS `' . self::TABLE_MESSAGES . '` (`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,`conversation_id` BIGINT UNSIGNED NOT NULL,`sender_user_id` INT UNSIGNED NOT NULL,`client_id` VARCHAR(64) NOT NULL,`body` MEDIUMTEXT NOT NULL,`reply_to_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,`moderation_state` VARCHAR(20) NOT NULL DEFAULT \'visible\',`created_at` DATETIME NOT NULL,`edited_at` DATETIME NULL,`deleted_at` DATETIME NULL,PRIMARY KEY (`id`),UNIQUE KEY `sender_client` (`sender_user_id`,`client_id`),KEY `thread_cursor` (`conversation_id`,`id`),KEY `sender_rate` (`sender_user_id`,`created_at`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
		$db->exec('CREATE TABLE IF NOT EXISTS `' . self::TABLE_MESSAGE_HIDES . '` (`message_id` BIGINT UNSIGNED NOT NULL,`user_id` INT UNSIGNED NOT NULL,`created_at` DATETIME NOT NULL,PRIMARY KEY (`message_id`,`user_id`),KEY `user_id` (`user_id`,`message_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
		$db->exec('CREATE TABLE IF NOT EXISTS `' . self::TABLE_BLOCKS . '` (`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,`blocker_user_id` INT UNSIGNED NOT NULL,`blocked_user_id` INT UNSIGNED NOT NULL,`reason` VARCHAR(500) NOT NULL DEFAULT \'\',`created_at` DATETIME NOT NULL,PRIMARY KEY (`id`),UNIQUE KEY `block_pair` (`blocker_user_id`,`blocked_user_id`),KEY `blocked_user` (`blocked_user_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
		$db->exec('CREATE TABLE IF NOT EXISTS `' . self::TABLE_REPORTS . '` (`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,`reporter_user_id` INT UNSIGNED NOT NULL,`reported_user_id` INT UNSIGNED NOT NULL,`conversation_id` BIGINT UNSIGNED NOT NULL,`message_id` BIGINT UNSIGNED NOT NULL,`category` VARCHAR(30) NOT NULL,`comment` TEXT NOT NULL,`evidence_body` MEDIUMTEXT NOT NULL,`evidence_hash` CHAR(64) NOT NULL,`status` VARCHAR(20) NOT NULL DEFAULT \'open\',`assigned_user_id` INT UNSIGNED NOT NULL DEFAULT 0,`resolution` TEXT NOT NULL,`created_at` DATETIME NOT NULL,`updated_at` DATETIME NOT NULL,PRIMARY KEY (`id`),KEY `queue` (`status`,`created_at`),KEY `reported_user` (`reported_user_id`,`created_at`),KEY `message_id` (`message_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
		$db->exec('CREATE TABLE IF NOT EXISTS `' . self::TABLE_RESTRICTIONS . '` (`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,`user_id` INT UNSIGNED NOT NULL,`status` VARCHAR(20) NOT NULL DEFAULT \'suspended\',`reason` TEXT NOT NULL,`expires_at` DATETIME NULL,`created_by` INT UNSIGNED NOT NULL,`created_at` DATETIME NOT NULL,`updated_at` DATETIME NOT NULL,PRIMARY KEY (`id`),UNIQUE KEY `user_id` (`user_id`),KEY `active` (`status`,`expires_at`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
		$db->exec('CREATE TABLE IF NOT EXISTS `' . self::TABLE_OUTBOX . '` (`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,`event_key` VARCHAR(190) NOT NULL,`conversation_id` BIGINT UNSIGNED NOT NULL,`message_id` BIGINT UNSIGNED NOT NULL,`recipient_user_id` INT UNSIGNED NOT NULL,`channel` VARCHAR(20) NOT NULL,`status` VARCHAR(20) NOT NULL DEFAULT \'pending\',`available_at` DATETIME NOT NULL,`attempts` INT UNSIGNED NOT NULL DEFAULT 0,`last_error` VARCHAR(500) NOT NULL DEFAULT \'\',`created_at` DATETIME NOT NULL,`processed_at` DATETIME NULL,PRIMARY KEY (`id`),UNIQUE KEY `event_key` (`event_key`),KEY `worker` (`status`,`available_at`,`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
		$db->exec('CREATE TABLE IF NOT EXISTS `' . self::TABLE_AUDIT . '` (`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,`actor_user_id` INT UNSIGNED NOT NULL,`action` VARCHAR(50) NOT NULL,`conversation_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,`message_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,`metadata` TEXT NOT NULL,`created_at` DATETIME NOT NULL,PRIMARY KEY (`id`),KEY `conversation` (`conversation_id`,`created_at`),KEY `actor` (`actor_user_id`,`created_at`),KEY `action` (`action`,`created_at`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
	}
}
