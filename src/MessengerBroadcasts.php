<?php namespace ProcessWire;

/**
 * Administrative broadcast queue for Messenger.
 *
 * A broadcast snapshots its recipients when it is created and delivers one
 * ordinary encrypted direct message per recipient. Delivery rows and stable
 * client IDs make retries idempotent.
 */
trait MessengerBroadcasts {

	public function previewBroadcast(User $actor, string $audience = 'all', string $role = ''): array {
		$this->assertBroadcastAdmin($actor);
		$selection = $this->broadcastRecipientIds($actor, $audience, $role);
		return [
			'audience' => $selection['audience'],
			'audience_value' => $selection['audience_value'],
			'label' => $selection['label'],
			'recipient_count' => count($selection['ids']),
		];
	}

	public function createBroadcast(User $actor, string $body, string $audience = 'all', string $role = ''): array {
		$this->assertBroadcastAdmin($actor);
		$body = $this->messageBody($body);
		$selection = $this->broadcastRecipientIds($actor, $audience, $role);
		if(!$selection['ids']) throw new WireException('The selected audience has no eligible recipients.');

		$db = $this->wire('database');
		$now = $this->now();
		$db->beginTransaction();
		try {
			$stmt = $db->prepare('INSERT INTO `' . self::TABLE_BROADCASTS . '` (created_by,sender_user_id,audience,audience_value,body,status,total_count,processed_count,delivered_count,skipped_count,failed_count,created_at,started_at,completed_at,updated_at) VALUES (?,?,?,?,\'\',\'queued\',?,0,0,0,0,?,NULL,NULL,?)');
			$stmt->execute([(int)$actor->id, (int)$actor->id, $selection['audience'], $selection['audience_value'], count($selection['ids']), $now, $now]);
			$id = (int)$db->lastInsertId();
			$row = ['id'=>$id,'created_by'=>(int)$actor->id,'sender_user_id'=>(int)$actor->id,'audience'=>$selection['audience'],'audience_value'=>$selection['audience_value'],'created_at'=>$now];
			$encrypted = $this->encryptValue($body, $this->broadcastAad($row));
			$db->prepare('UPDATE `' . self::TABLE_BROADCASTS . '` SET body=? WHERE id=?')->execute([$encrypted, $id]);
			$delivery = $db->prepare('INSERT INTO `' . self::TABLE_BROADCAST_DELIVERIES . '` (broadcast_id,recipient_user_id,status,attempts,conversation_id,message_id,last_error,created_at,processed_at) VALUES (?,? ,\'pending\',0,0,0,\'\',?,NULL)');
			foreach($selection['ids'] as $recipientId) $delivery->execute([$id, $recipientId, $now]);
			$this->audit('broadcast_created', 0, 0, (int)$actor->id, ['broadcast_id'=>$id,'audience'=>$selection['audience'],'audience_value'=>$selection['audience_value'],'recipient_count'=>count($selection['ids'])]);
			$db->commit();
		} catch(\Throwable $error) {
			if($db->inTransaction()) $db->rollBack();
			throw $error;
		}
		return $this->broadcast($id, $actor);
	}

	public function broadcasts(User $actor, int $limit = 50): array {
		$this->assertBroadcastAdmin($actor);
		$limit = max(1, min(100, $limit));
		$stmt = $this->wire('database')->query('SELECT id,created_by,sender_user_id,audience,audience_value,status,total_count,processed_count,delivered_count,skipped_count,failed_count,created_at,started_at,completed_at,updated_at FROM `' . self::TABLE_BROADCASTS . '` ORDER BY id DESC LIMIT ' . $limit);
		return array_map([$this, 'normalizeBroadcastRow'], $stmt->fetchAll(\PDO::FETCH_ASSOC));
	}

	public function broadcast(int $id, User $actor): array {
		$this->assertBroadcastAdmin($actor);
		$row = $this->broadcastStoredRow($id);
		if(!$row) return [];
		$row['body'] = $this->decryptValue((string)$row['body'], $this->broadcastAad($row));
		return $this->normalizeBroadcastRow($row);
	}

	public function processBroadcast(int $id, User $actor, int $limit = 100, bool $execute = false): array {
		$this->assertBroadcastAdmin($actor);
		return $this->processBroadcastInternal($id, $limit, $execute);
	}

	public function runBroadcastQueue(int $limit = 100, bool $execute = false): array {
		$limit = max(1, min(500, $limit));
		$pending = $this->scalar('SELECT COUNT(*) FROM `' . self::TABLE_BROADCAST_DELIVERIES . '` d JOIN `' . self::TABLE_BROADCASTS . '` b ON b.id=d.broadcast_id WHERE b.status IN (\'queued\',\'running\') AND d.status=\'pending\'');
		$result = ['selected'=>min($limit, $pending),'processed'=>0,'delivered'=>0,'skipped'=>0,'failed'=>0,'execute'=>$execute];
		if(!$execute) return $result;
		if(!(bool)$this->enable_cli) throw new WireException('Messenger CLI is disabled.');
		$stmt = $this->wire('database')->query('SELECT id FROM `' . self::TABLE_BROADCASTS . '` WHERE status IN (\'queued\',\'running\') ORDER BY id ASC');
		foreach($stmt->fetchAll(\PDO::FETCH_COLUMN) as $broadcastId) {
			$remaining = $limit - $result['processed'];
			if($remaining < 1) break;
			$batch = $this->processBroadcastInternal((int)$broadcastId, $remaining, true);
			foreach(['processed','delivered','skipped','failed'] as $key) $result[$key] += (int)$batch['batch_' . $key];
		}
		return $result;
	}

	public function cancelBroadcast(int $id, User $actor): array {
		$this->assertBroadcastAdmin($actor);
		$row = $this->broadcastStoredRow($id);
		if(!$row) throw new WireException('Broadcast unavailable.');
		if(in_array((string)$row['status'], ['completed','cancelled'], true)) return $this->broadcast($id, $actor);
		$now = $this->now();
		$db = $this->wire('database');
		$db->beginTransaction();
		try {
			$db->prepare('UPDATE `' . self::TABLE_BROADCAST_DELIVERIES . '` SET status=\'cancelled\',processed_at=? WHERE broadcast_id=? AND status=\'pending\'')->execute([$now, $id]);
			$db->prepare('UPDATE `' . self::TABLE_BROADCASTS . '` SET status=\'cancelled\',completed_at=?,updated_at=? WHERE id=?')->execute([$now, $now, $id]);
			$this->audit('broadcast_cancelled', 0, 0, (int)$actor->id, ['broadcast_id'=>$id]);
			$db->commit();
		} catch(\Throwable $error) {
			if($db->inTransaction()) $db->rollBack();
			throw $error;
		}
		return $this->broadcast($id, $actor);
	}

	private function processBroadcastInternal(int $id, int $limit, bool $execute): array {
		$limit = max(1, min(500, $limit));
		$broadcast = $this->broadcastStoredRow($id);
		if(!$broadcast) throw new WireException('Broadcast unavailable.');
		if(in_array((string)$broadcast['status'], ['completed','cancelled'], true)) return $this->broadcastBatchResult($broadcast, 0, 0, 0, 0, $execute);
		$stmt = $this->wire('database')->prepare('SELECT id,broadcast_id,recipient_user_id,status,attempts FROM `' . self::TABLE_BROADCAST_DELIVERIES . '` WHERE broadcast_id=? AND status=\'pending\' ORDER BY id ASC LIMIT ' . $limit);
		$stmt->execute([$id]);
		$deliveries = $stmt->fetchAll(\PDO::FETCH_ASSOC);
		if(!$execute) return $this->broadcastBatchResult($broadcast, count($deliveries), 0, 0, 0, false);
		$body = $this->decryptValue((string)$broadcast['body'], $this->broadcastAad($broadcast));
		$now = $this->now();
		$this->wire('database')->prepare('UPDATE `' . self::TABLE_BROADCASTS . '` SET status=\'running\',started_at=COALESCE(started_at,?),updated_at=? WHERE id=? AND status=\'queued\'')->execute([$now, $now, $id]);
		$delivered = 0;
		$skipped = 0;
		$failed = 0;
		foreach($deliveries as $delivery) {
			$outcome = $this->deliverBroadcastRecipient($broadcast, $delivery, $body);
			if($outcome === 'sent') $delivered++;
			elseif($outcome === 'skipped') $skipped++;
			else $failed++;
		}
		$broadcast = $this->refreshBroadcastProgress($id);
		return $this->broadcastBatchResult($broadcast, count($deliveries), $delivered, $skipped, $failed, true);
	}

	private function deliverBroadcastRecipient(array $broadcast, array $delivery, string $body): string {
		$db = $this->wire('database');
		$deliveryId = (int)$delivery['id'];
		$recipientId = (int)$delivery['recipient_user_id'];
		$senderId = (int)$broadcast['sender_user_id'];
		$now = $this->now();
		try {
			$recipient = $this->wire('users')->get($recipientId);
			if(!$recipient->id || $recipient->isGuest() || $recipientId === $senderId || !$this->canReceive($recipient)) {
				$db->prepare('UPDATE `' . self::TABLE_BROADCAST_DELIVERIES . '` SET status=\'skipped\',attempts=attempts+1,last_error=\'Recipient unavailable\',processed_at=? WHERE id=? AND status=\'pending\'')->execute([$now, $deliveryId]);
				return 'skipped';
			}
			$sender = $this->wire('users')->get($senderId);
			if(!$sender->id) throw new WireException('Broadcast sender unavailable.');
			$db->beginTransaction();
			$conversationId = $this->directConversationId($senderId, $recipientId);
			if($conversationId < 1) {
				$key = bin2hex(random_bytes(16));
				$stmt = $db->prepare('INSERT INTO `' . self::TABLE_CONVERSATIONS . '` (public_key,direct_key,type,state,request_state,requester_id,recipient_id,last_message_id,last_message_at,created_at,updated_at) VALUES (?,?,\'direct\',\'active\',\'accepted\',?,?,0,NULL,?,?)');
				$stmt->execute([$key, $this->directKey($senderId, $recipientId), $senderId, $recipientId, $now, $now]);
				$conversationId = (int)$db->lastInsertId();
				$participant = $db->prepare('INSERT INTO `' . self::TABLE_PARTICIPANTS . '` (conversation_id,user_id,role,last_read_message_id,archived_at,muted_until,hidden_before,email_mode,created_at) VALUES (?,?,?,0,NULL,NULL,NULL,\'instant\',?)');
				$participant->execute([$conversationId, $senderId, 'requester', $now]);
				$participant->execute([$conversationId, $recipientId, 'recipient', $now]);
			} else {
				$db->prepare('UPDATE `' . self::TABLE_CONVERSATIONS . '` SET state=\'active\',request_state=\'accepted\',updated_at=? WHERE id=?')->execute([$now, $conversationId]);
			}
			$clientId = 'broadcast_' . substr(hash('sha256', (int)$broadcast['id'] . ':' . $recipientId), 0, 32);
			$existing = $db->prepare('SELECT id FROM `' . self::TABLE_MESSAGES . '` WHERE sender_user_id=? AND client_id=? LIMIT 1');
			$existing->execute([$senderId, $clientId]);
			$messageId = (int)$existing->fetchColumn();
			if($messageId < 1) {
				$message = $this->insertMessage($conversationId, $sender, $body, $clientId, $now);
				$messageId = (int)$message['id'];
				$this->queueEmailNotification($conversationId, $messageId, $recipientId, $now);
			}
			$db->prepare('UPDATE `' . self::TABLE_BROADCAST_DELIVERIES . '` SET status=\'sent\',attempts=attempts+1,conversation_id=?,message_id=?,last_error=\'\',processed_at=? WHERE id=? AND status=\'pending\'')->execute([$conversationId, $messageId, $now, $deliveryId]);
			$this->audit('broadcast_delivered', $conversationId, $messageId, $senderId, ['broadcast_id'=>(int)$broadcast['id'],'recipient_user_id'=>$recipientId]);
			$db->commit();
			return 'sent';
		} catch(\Throwable $error) {
			if($db->inTransaction()) $db->rollBack();
			$attempts = (int)$delivery['attempts'] + 1;
			$status = $attempts >= 3 ? 'failed' : 'pending';
			$message = mb_substr(preg_replace('/\s+/', ' ', $error->getMessage()) ?: 'Delivery failed.', 0, 500);
			$db->prepare('UPDATE `' . self::TABLE_BROADCAST_DELIVERIES . '` SET status=?,attempts=?,last_error=?,processed_at=? WHERE id=? AND status=\'pending\'')->execute([$status, $attempts, $message, $now, $deliveryId]);
			return 'failed';
		}
	}

	private function refreshBroadcastProgress(int $id): array {
		$stmt = $this->wire('database')->prepare('SELECT status,COUNT(*) total FROM `' . self::TABLE_BROADCAST_DELIVERIES . '` WHERE broadcast_id=? GROUP BY status');
		$stmt->execute([$id]);
		$counts = ['pending'=>0,'sent'=>0,'skipped'=>0,'failed'=>0,'cancelled'=>0];
		foreach($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) $counts[(string)$row['status']] = (int)$row['total'];
		$processed = $counts['sent'] + $counts['skipped'] + $counts['failed'] + $counts['cancelled'];
		$status = $counts['pending'] > 0 ? 'running' : 'completed';
		$now = $this->now();
		$completedAt = $status === 'completed' ? $now : null;
		$this->wire('database')->prepare('UPDATE `' . self::TABLE_BROADCASTS . '` SET status=?,processed_count=?,delivered_count=?,skipped_count=?,failed_count=?,completed_at=?,updated_at=? WHERE id=? AND status<>\'cancelled\'')->execute([$status,$processed,$counts['sent'],$counts['skipped'],$counts['failed'],$completedAt,$now,$id]);
		return $this->broadcastStoredRow($id);
	}

	private function broadcastBatchResult(array $broadcast, int $processed, int $delivered, int $skipped, int $failed, bool $execute): array {
		$row = $this->normalizeBroadcastRow($broadcast);
		unset($row['body']);
		return $row + ['batch_processed'=>$processed,'batch_delivered'=>$delivered,'batch_skipped'=>$skipped,'batch_failed'=>$failed,'execute'=>$execute];
	}

	private function broadcastRecipientIds(User $actor, string $audience, string $role): array {
		$audience = $audience === 'role' ? 'role' : 'all';
		$role = trim($role);
		$roleObject = null;
		if($audience === 'role') {
			$roleObject = $this->wire('roles')->get($role);
			if(!$roleObject->id || $roleObject->name === 'guest') throw new WireException('Select a valid recipient role.');
			$role = (string)$roleObject->name;
		}
		$ids = [];
		foreach($this->wire('users')->find('include=all,sort=id') as $candidate) {
			if(!$candidate->id || $candidate->isGuest() || (int)$candidate->id === (int)$actor->id) continue;
			if(method_exists($candidate, 'isUnpublished') && $candidate->isUnpublished()) continue;
			if($roleObject && !$candidate->hasRole($roleObject)) continue;
			if(!$this->canReceive($candidate)) continue;
			$ids[] = (int)$candidate->id;
		}
		return ['ids'=>$ids,'audience'=>$audience,'audience_value'=>$audience === 'role' ? $role : '','label'=>$audience === 'role' ? 'Role: ' . $role : 'All eligible members'];
	}

	private function broadcastStoredRow(int $id): array {
		$stmt = $this->wire('database')->prepare('SELECT * FROM `' . self::TABLE_BROADCASTS . '` WHERE id=? LIMIT 1');
		$stmt->execute([$id]);
		return $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
	}

	private function normalizeBroadcastRow(array $row): array {
		foreach(['id','created_by','sender_user_id','total_count','processed_count','delivered_count','skipped_count','failed_count'] as $key) if(isset($row[$key])) $row[$key] = (int)$row[$key];
		return $row;
	}

	private function broadcastAad(array $row): string {
		return self::ENCRYPTION_CONTEXT . '|broadcast|' . (int)$row['id'] . '|' . (int)$row['created_by'] . '|' . (int)$row['sender_user_id'] . '|' . (string)$row['audience'] . '|' . (string)$row['audience_value'] . '|' . (string)$row['created_at'];
	}

	private function assertBroadcastAdmin(User $actor): void {
		if(!$this->canAdmin($actor)) throw new WirePermissionException('Messenger broadcast administration denied.');
	}
}
