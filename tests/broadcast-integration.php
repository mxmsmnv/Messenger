<?php declare(strict_types=1);

namespace ProcessWire;

$root = '';
foreach(array_slice($argv, 1) as $argument) if(str_starts_with($argument, '--root=')) $root = substr($argument, 7);
$root = rtrim($root, '/');
if($root === '' || !is_file($root . '/index.php')) {
	fwrite(STDERR, "Usage: php tests/broadcast-integration.php --root=/path/to/processwire\n");
	exit(2);
}

require $root . '/index.php';

function broadcastCheck(bool $condition, string $message): void {
	if(!$condition) throw new \RuntimeException($message);
}

/** @var Messenger $messenger */
$messenger = wire('modules')->get('Messenger');
$database = wire('database');
$users = wire('users');
$roles = wire('roles');
$config = wire('config');
$suffix = strtolower(bin2hex(random_bytes(4)));
$roleName = 'messenger-broadcast-test-' . $suffix;
$role = new Role();
$recipients = [];
$broadcastId = 0;
$conversationIds = [];
$checks = 0;

try {
	$role = $roles->add($roleName);
	broadcastCheck((int)$role->id > 0, 'Fixture role was not created.');
	$checks++;
	foreach([1, 2] as $number) {
		$recipient = new User();
		$recipient->name = 'messenger-broadcast-recipient-' . $number . '-' . $suffix;
		$recipient->email = $recipient->name . '@example.test';
		$recipient->pass = bin2hex(random_bytes(18));
		$recipient->addRole($role);
		$recipient->save();
		broadcastCheck((int)$recipient->id > 0, 'Fixture recipient was not created.');
		$recipients[] = $recipient;
		$checks++;
	}
	$actor = $users->get((int)$config->superUserPageID);
	broadcastCheck($actor->id > 0 && $actor->isSuperuser(), 'Superuser actor unavailable.');
	$checks++;
	$preview = $messenger->previewBroadcast($actor, 'role', (string)$role->name);
	broadcastCheck((int)$preview['recipient_count'] === 2, 'Role audience preview did not isolate the fixture recipients.');
	$checks++;
	$plain = 'E2E broadcast integration ' . $suffix;
	$broadcast = $messenger->createBroadcast($actor, $plain, 'role', (string)$role->name);
	$broadcastId = (int)$broadcast['id'];
	broadcastCheck($broadcastId > 0 && $broadcast['status'] === 'queued' && (int)$broadcast['total_count'] === 2, 'Broadcast was not queued correctly.');
	$checks++;
	$stmt = $database->prepare('SELECT body FROM messenger_broadcasts WHERE id=?');
	$stmt->execute([$broadcastId]);
	$stored = (string)$stmt->fetchColumn();
	broadcastCheck(str_starts_with($stored, 'menc:v1:') && !str_contains($stored, $plain), 'Broadcast source body is not encrypted at rest.');
	$checks++;
	$result = $messenger->processBroadcast($broadcastId, $actor, 10, true);
	broadcastCheck((int)$result['batch_delivered'] === 2 && $result['status'] === 'completed', 'Broadcast batch was not delivered completely.');
	$checks++;
	$stmt = $database->prepare('SELECT recipient_user_id,conversation_id,message_id,status FROM messenger_broadcast_deliveries WHERE broadcast_id=? ORDER BY recipient_user_id');
	$stmt->execute([$broadcastId]);
	$deliveries = $stmt->fetchAll(\PDO::FETCH_ASSOC);
	broadcastCheck(count($deliveries) === 2 && count(array_filter($deliveries, fn(array $row): bool => $row['status'] === 'sent')) === 2, 'Delivery rows are incomplete.');
	$checks++;
	foreach($deliveries as $delivery) {
		$conversationIds[] = (int)$delivery['conversation_id'];
		$recipient = $users->get((int)$delivery['recipient_user_id']);
		wire('session')->forceLogin($recipient);
		$messages = $messenger->conversationMessages((int)$delivery['conversation_id'], wire('user'), null, null, 10);
		broadcastCheck(count($messages) === 1 && $messages[0]['body'] === $plain, 'Recipient could not read the broadcast message.');
		$checks++;
	}
	$again = $messenger->processBroadcast($broadcastId, $actor, 10, true);
	broadcastCheck((int)$again['batch_processed'] === 0, 'Completed broadcast replay was not idempotent.');
	$checks++;
	$stmt = $database->prepare('SELECT COUNT(*) FROM messenger_messages WHERE client_id LIKE ?');
	$stmt->execute(['broadcast_%']);
	broadcastCheck((int)$stmt->fetchColumn() >= 2, 'Stable broadcast message identifiers are missing.');
	$checks++;
	$unauthorized = new User();
	$unauthorized->name = 'messenger-broadcast-unauthorized-' . $suffix;
	$unauthorized->pass = bin2hex(random_bytes(18));
	$unauthorized->save();
	$denied = false;
	try { $messenger->previewBroadcast($unauthorized, 'role', (string)$role->name); } catch(WirePermissionException $error) { $denied = true; }
	broadcastCheck($denied, 'Broadcast preview did not enforce messenger-admin.');
	$checks++;
	$users->delete($unauthorized);
} finally {
	if($broadcastId > 0) {
		$conversationIds = array_values(array_unique(array_filter($conversationIds)));
		$database->prepare('DELETE FROM messenger_broadcast_deliveries WHERE broadcast_id=?')->execute([$broadcastId]);
		$database->prepare('DELETE FROM messenger_broadcasts WHERE id=?')->execute([$broadcastId]);
		$database->prepare('DELETE FROM messenger_audit WHERE metadata LIKE ?')->execute(['%"broadcast_id":' . $broadcastId . '%']);
		foreach($conversationIds as $conversationId) {
			$database->prepare('DELETE FROM messenger_notification_outbox WHERE conversation_id=?')->execute([$conversationId]);
			$database->prepare('DELETE FROM messenger_audit WHERE conversation_id=?')->execute([$conversationId]);
			$database->prepare('DELETE h FROM messenger_message_hides h JOIN messenger_messages m ON m.id=h.message_id WHERE m.conversation_id=?')->execute([$conversationId]);
			$database->prepare('DELETE FROM messenger_messages WHERE conversation_id=?')->execute([$conversationId]);
			$database->prepare('DELETE FROM messenger_participants WHERE conversation_id=?')->execute([$conversationId]);
			$database->prepare('DELETE FROM messenger_conversations WHERE id=?')->execute([$conversationId]);
		}
	}
	foreach($recipients as $recipient) if((int)$recipient->id > 0) $users->delete($recipient);
	if((int)$role->id > 0) $roles->delete($role);
}

fwrite(STDOUT, "Messenger broadcast integration: OK ({$checks} checks)\n");
