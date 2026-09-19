<?php declare(strict_types=1);

namespace ProcessWire;

$root = '';
foreach(array_slice($argv, 1) as $argument) {
	if(str_starts_with($argument, '--root=')) $root = substr($argument, 7);
}
$root = rtrim($root, '/');
if($root === '' || !is_file($root . '/index.php')) {
	fwrite(STDERR, "Usage: php tests/encryption-integration.php --root=/path/to/processwire\n");
	exit(2);
}

require $root . '/index.php';

function check(bool $condition, string $message): void {
	if(!$condition) throw new \RuntimeException($message);
}

/** @var Messenger $messenger */
$messenger = wire('modules')->get('Messenger');
$database = wire('database');
$users = wire('users');
$suffix = strtolower(bin2hex(random_bytes(4)));
$senderName = 'messenger-crypto-sender-' . $suffix;
$recipientName = 'messenger-crypto-recipient-' . $suffix;
$sender = new User();
$recipient = new User();
$conversationId = 0;
$messageId = 0;
$checks = 0;

try {
	foreach([[$sender, $senderName], [$recipient, $recipientName]] as [$account, $name]) {
		$account->name = $name;
		$account->email = $name . '@example.test';
		$account->pass = bin2hex(random_bytes(18));
		$account->save();
		check((int)$account->id > 0, 'Fixture user was not created.');
		$checks++;
	}

	wire('session')->forceLogin($sender);
	$senderActor = wire('user');
	$plain = 'Messenger encryption integration ' . $suffix;
	$created = $messenger->startConversation($senderActor, $recipient, $plain, 'crypto_test_' . str_repeat('a', 20));
	$conversationId = (int)$created['conversation']['id'];
	$messageId = (int)$created['message']['id'];
	check($conversationId > 0 && $messageId > 0, 'Encrypted conversation was not created.');
	$checks++;

	$stmt = $database->prepare('SELECT body FROM messenger_messages WHERE id=?');
	$stmt->execute([$messageId]);
	$stored = (string)$stmt->fetchColumn();
	check(str_starts_with($stored, 'menc:v1:'), 'Message body is not stored as a versioned encrypted envelope.');
	check(!str_contains($stored, $plain), 'Plaintext leaked into the stored message body.');
	$checks += 2;

	wire('session')->forceLogin($recipient);
	$recipientActor = wire('user');
	$messages = $messenger->conversationMessages($conversationId, $recipientActor, null, null, 20);
	check(count($messages) === 1 && $messages[0]['body'] === $plain, 'Encrypted message did not decrypt through the public API.');
	$checks++;

	$matches = $messenger->searchMessages($recipientActor, 'encryption integration', 10);
	check(count($matches) === 1 && $matches[0]['body'] === $plain, 'Bounded encrypted message search failed.');
	$checks++;

	$report = $messenger->reportMessage($recipientActor, $messageId, 'other', 'Encrypted report comment');
	check($report['evidence_body'] === $plain && $report['comment'] === 'Encrypted report comment', 'Encrypted report content did not round-trip.');
	$checks++;
	$stmt = $database->prepare('SELECT comment,evidence_body,evidence_hash FROM messenger_reports WHERE id=?');
	$stmt->execute([(int)$report['id']]);
	$storedReport = $stmt->fetch(\PDO::FETCH_ASSOC);
	check(str_starts_with((string)$storedReport['comment'], 'menc:v1:') && str_starts_with((string)$storedReport['evidence_body'], 'menc:v1:'), 'Report content is not encrypted at rest.');
	check(!hash_equals(hash('sha256', $plain), (string)$storedReport['evidence_hash']), 'Report evidence uses an unkeyed plaintext hash.');
	$checks += 2;

	$tampered = substr($stored, 0, -1) . (substr($stored, -1) === 'A' ? 'B' : 'A');
	$database->prepare('UPDATE messenger_messages SET body=? WHERE id=?')->execute([$tampered, $messageId]);
	$failedClosed = false;
	try {
		$messenger->conversationMessages($conversationId, $recipientActor, null, null, 20);
	} catch(WireException $error) {
		$failedClosed = str_contains($error->getMessage(), 'failed authentication');
	} finally {
		$database->prepare('UPDATE messenger_messages SET body=? WHERE id=?')->execute([$stored, $messageId]);
	}
	check($failedClosed, 'Tampered ciphertext did not fail closed.');
	$checks++;

	wire('session')->forceLogin($sender);
	$senderActor = wire('user');
	$edited = $messenger->editMessage($messageId, $senderActor, 'Edited encrypted message ' . $suffix);
	check($edited['body'] === 'Edited encrypted message ' . $suffix, 'Encrypted edit did not return plaintext to the authorized sender.');
	$stmt = $database->prepare('SELECT body FROM messenger_messages WHERE id=?');
	$stmt->execute([$messageId]);
	check(str_starts_with((string)$stmt->fetchColumn(), 'menc:v1:'), 'Edited message was stored without encryption.');
	$checks += 2;

	$status = $messenger->encryptionStatus();
	check($status['ready'] === true && $status['plaintext_messages'] === 0 && $status['plaintext_report_fields'] === 0, 'Encryption status reports plaintext content.');
	$checks++;
} finally {
	$senderId = (int)$sender->id;
	$recipientId = (int)$recipient->id;
	if($conversationId > 0) {
		$database->prepare('DELETE FROM messenger_reports WHERE conversation_id=?')->execute([$conversationId]);
		$database->prepare('DELETE FROM messenger_notification_outbox WHERE conversation_id=?')->execute([$conversationId]);
		$database->prepare('DELETE FROM messenger_audit WHERE conversation_id=?')->execute([$conversationId]);
		$database->prepare('DELETE h FROM messenger_message_hides h JOIN messenger_messages m ON m.id=h.message_id WHERE m.conversation_id=?')->execute([$conversationId]);
		$database->prepare('DELETE FROM messenger_messages WHERE conversation_id=?')->execute([$conversationId]);
		$database->prepare('DELETE FROM messenger_participants WHERE conversation_id=?')->execute([$conversationId]);
		$database->prepare('DELETE FROM messenger_conversations WHERE id=?')->execute([$conversationId]);
	}
	if($senderId > 0 && $recipientId > 0) {
		$database->prepare('DELETE FROM messenger_blocks WHERE blocker_user_id IN (?,?) OR blocked_user_id IN (?,?)')->execute([$senderId,$recipientId,$senderId,$recipientId]);
		$database->prepare('DELETE FROM messenger_restrictions WHERE user_id IN (?,?) OR created_by IN (?,?)')->execute([$senderId,$recipientId,$senderId,$recipientId]);
	}
	foreach([$sender, $recipient] as $account) if((int)$account->id > 0) $users->delete($account);
}

fwrite(STDOUT, "Messenger encryption integration: OK ({$checks} checks)\n");
