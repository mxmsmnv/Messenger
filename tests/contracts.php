<?php declare(strict_types=1);

$root = dirname(__DIR__);
$module = (string)file_get_contents($root . '/Messenger.module.php');
$rest = (string)file_get_contents($root . '/MessengerRestApi.php');
$admin = (string)file_get_contents($root . '/ProcessMessenger.module.php');
$readme = (string)file_get_contents($root . '/README.md');
$api = (string)file_get_contents($root . '/API.md');
$changelog = (string)file_get_contents($root . '/CHANGELOG.md');
$frontend = (string)file_get_contents($root . '/assets/messenger.js');

if(!str_contains($module, 'public const VERSION = 100;') || !str_contains($module, "'version' => 100") || !str_contains($admin, "'version' => 100")) throw new RuntimeException('Release version must be 1.0.0 / 100 everywhere.');
if(!str_contains($readme, '![Messenger](assets/Messenger.png)') || !is_file($root . '/assets/Messenger.png')) throw new RuntimeException('README doodle contract missing.');
if(!is_file($root . '/LICENSE') || !str_contains((string)file_get_contents($root . '/LICENSE'), 'MIT License')) throw new RuntimeException('MIT license contract missing.');
if(!is_file($root . '/.github/FUNDING.yml') || !str_contains((string)file_get_contents($root . '/.github/FUNDING.yml'), 'smnv.org/sponsor')) throw new RuntimeException('Sponsorship metadata contract missing.');
if(substr_count($changelog, '## [') !== 1 || !str_contains($changelog, '## [1.0.0]') || !str_contains($changelog, 'First public release.')) throw new RuntimeException('Changelog must contain only the first public 1.0.0 release.');
foreach(['Messenger 1.0.0', 'module version `100`'] as $releaseDoc) if(!str_contains($api, $releaseDoc)) throw new RuntimeException("API release contract missing: {$releaseDoc}");

$requiredTables = [
	'messenger_conversations','messenger_participants','messenger_messages','messenger_message_hides',
	'messenger_blocks','messenger_reports','messenger_restrictions','messenger_notification_outbox','messenger_audit',
];
foreach($requiredTables as $table) if(!str_contains($module, "'{$table}'")) throw new RuntimeException("Missing table contract: {$table}");

$permissions = ['messenger-moderate','messenger-view-content','messenger-admin','messenger-export','messenger-delete'];
foreach($permissions as $permission) if(!str_contains($module, "'{$permission}'")) throw new RuntimeException("Missing permission: {$permission}");

$methods = ['canReceive','messagingDecision','startConversation','sendMessage','conversationsForUser','pendingRequestCount','conversationMessages','editMessage','deleteMessage','hideMessage','searchMessages','searchRecipients','acceptRequest','declineRequest','markRead','blockUser','unblockUser','reportMessage','reportStatusCounts','restrictUser','clearRestriction','restrictionStatus','activeRestrictions','runNotificationOutbox','renderApp','encryptionStatus','migrateEncryptionAtRest','frontendFrameworks','frontendUi','frontendAttributes'];
foreach($methods as $method) if(!preg_match('/function\s+(?:___)?' . preg_quote($method,'/') . '\s*\(/',$module)) throw new RuntimeException("Missing method: {$method}");

foreach(['Cache-Control: private, no-store','X-Robots-Tag: noindex','validateCsrf','rateLimit'] as $boundary) if(!str_contains($rest,$boundary)) throw new RuntimeException("Missing REST boundary: {$boundary}");
foreach(['ENCRYPTION_PREFIX','sodium_crypto_aead_xchacha20poly1305_ietf_encrypt','sodium_crypto_aead_xchacha20poly1305_ietf_decrypt','contentFingerprint','Messenger found unencrypted stored content'] as $cryptoContract) if(!str_contains($module,$cryptoContract)) throw new RuntimeException("Encrypted storage contract missing: {$cryptoContract}");
foreach(['InputfieldFieldset','messenger_config_','columnWidth','poll_thread_seconds','poll_inbox_seconds','messenger_encryption_health'] as $configUiContract) if(!str_contains($module,$configUiContract)) throw new RuntimeException("Module configuration UI contract missing: {$configUiContract}");
foreach(['$fieldset(\'experience\'', '$fieldset(\'limits\'', '$fieldset(\'updates\'', '$fieldset(\'notifications\'', '$fieldset(\'lifecycle\''] as $configSectionContract) if(!str_contains($module,$configSectionContract)) throw new RuntimeException("Module configuration section missing: {$configSectionContract}");
foreach(['mail_module','mailProviderOptions','mailProviderLabel',"findByPrefix('WireMail')",'new($provider)','Selected WireMail provider is unavailable'] as $mailProviderContract) if(!str_contains($module,$mailProviderContract)) throw new RuntimeException("WireMail provider contract missing: {$mailProviderContract}");
foreach(['frontend_framework','frontend_custom_map',"'semantic'", "'designsystemet'", "'uikit'", "'bootstrap'", "'tailwind'", "'custom'", 'data-framework', 'data-ui'] as $frameworkContract) if(!str_contains($module,$frameworkContract)) throw new RuntimeException("Frontend framework contract missing: {$frameworkContract}");
foreach(['MessengerApp--restricted', 'Messaging unavailable', 'Access is scheduled to return after %s.'] as $restrictionContract) if(!str_contains($module,$restrictionContract)) throw new RuntimeException("Restricted-state contract missing: {$restrictionContract}");
foreach(['function applyUi','button_primary','button_secondary','button_tertiary','button_danger',"'conversation'","'textarea'","'badge'","'dialog'"] as $dynamicFrameworkContract) if(!str_contains($frontend,$dynamicFrameworkContract)) throw new RuntimeException("Dynamic frontend framework contract missing: {$dynamicFrameworkContract}");
if(!str_contains($module,'GREATEST(last_read_message_id,CAST(? AS UNSIGNED))')) throw new RuntimeException('Unread watermark must compare message IDs numerically.');
foreach(['blocked_by_actor','blocked_by_other','isBlockedBy'] as $blockDirectionContract) if(!str_contains($module,$blockDirectionContract) && !str_contains($frontend,$blockDirectionContract)) throw new RuntimeException("Directional block contract missing: {$blockDirectionContract}");
if(!str_contains($frontend,"item.last_message.deleted_at ? 'Message removed'")) throw new RuntimeException('Deleted last-message preview contract missing.');
if(str_contains($module,'m.body LIKE')) throw new RuntimeException('Encrypted message bodies must not use SQL LIKE search.');
if(str_contains($module,"hash('sha256',(string)\$message['body'])")) throw new RuntimeException('Report evidence must not use an unkeyed plaintext hash.');
if(!str_contains($admin,'PERMISSION_MODERATE')) throw new RuntimeException('Admin process is not permission gated.');
foreach(['MessengerAdminNavigation','uk-subnav uk-subnav-pill MessengerAdminNav','aria-current="page"','MessengerAdminSettings'] as $adminPillContract) if(!str_contains($admin,$adminPillContract)) throw new RuntimeException("Admin pill navigation contract missing: {$adminPillContract}");
foreach(['MessengerReportSummary','MessengerReportFilters','MessengerFilterCount','MessengerReportsToolbar','MessengerStatusBadge','MessengerReportsEmpty'] as $reportsUiContract) if(!str_contains($admin,$reportsUiContract)) throw new RuntimeException("Reports UI contract missing: {$reportsUiContract}");
foreach(['MessengerRestrictionSearch','MessengerSearchControl','MessengerMemberResults','MessengerRestrictionWorkspace','MessengerRestrictionState','MessengerActiveRestrictions'] as $restrictionsUiContract) if(!str_contains($admin,$restrictionsUiContract)) throw new RuntimeException("Restrictions UI contract missing: {$restrictionsUiContract}");
foreach(['MessengerDashboardState','MessengerMetricCopy','MessengerDashboardGrid','MessengerQuickActions','MessengerReadiness','mailProviderLabel'] as $dashboardUiContract) if(!str_contains($admin,$dashboardUiContract)) throw new RuntimeException("Dashboard UI contract missing: {$dashboardUiContract}");
if(str_contains($admin,'uk-tab MessengerAdminNav')) throw new RuntimeException('Messenger admin navigation must not use legacy tabs.');
if(!str_contains($readme,'Message Requests')) throw new RuntimeException('README omits Message Requests.');
foreach(['pending_request_count','MessengerTabBadge','Requests: ','MessengerRequest-actions'] as $badgeContract) if(!str_contains($frontend,$badgeContract)) throw new RuntimeException("Frontend request badge contract missing: {$badgeContract}");
foreach(['--ds-color-primary-base-default','--ds-color-primary-surface-tinted','--ds-color-neutral-text-default','--ds-color-warning-surface-tinted','--ds-color-danger-base-default','MessengerApp-header [data-messenger-new]'] as $themeContract) if(!str_contains((string)file_get_contents($root . '/assets/messenger.css'),$themeContract)) throw new RuntimeException("LQRS theme contract missing: {$themeContract}");
if(str_contains((string)file_get_contents($root . '/assets/messenger.css'),'#2563eb')) throw new RuntimeException('Messenger must not retain its legacy blue primary color.');
$css = (string)file_get_contents($root . '/assets/messenger.css');
foreach(['width:100%', 'max-width:100%', 'min-width:0'] as $responsiveContract) if(!str_contains($css,$responsiveContract)) throw new RuntimeException("Responsive containment contract missing: {$responsiveContract}");
if(str_contains($module,'DROP TABLE')) throw new RuntimeException('Uninstall must not drop private data.');

fwrite(STDOUT,"Messenger contracts: OK\n");
