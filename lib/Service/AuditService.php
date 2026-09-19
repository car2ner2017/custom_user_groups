<?php

declare(strict_types=1);

namespace OCA\CustomUserGroups\Service;

use OCP\EventDispatcher\IEventDispatcher;
use OCP\Log\Audit\CriticalActionPerformedEvent;
use Psr\Log\LoggerInterface;
use Throwable;

class AuditService {

	public function __construct(
		private IEventDispatcher $eventDispatcher,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Dispatches an audit event to Nextcloud's admin_audit system safely
	 *
	 * @param string $message sprintf-formatted template string
	 * @param array<string, mixed>|list<mixed> $parameters Array of values matching sprintf specifiers
	 */
	public function logAction(string $message, array $parameters = []): void {
		try {
			if (class_exists(CriticalActionPerformedEvent::class)) {
				$this->eventDispatcher->dispatchTyped(
					new CriticalActionPerformedEvent($message, $parameters)
				);
			}
		} catch (Throwable $e) {
			$this->logger->warning('Failed to dispatch Nextcloud audit event: ' . $e->getMessage(), [
				'app' => 'customusergroups',
			]);
		}
	}

	public function auditGroupCreated(string $groupId, string $groupName, string $actorId): void {
		$this->logAction(
			'Custom group "%s" (%s) created by %s',
			[
				'group_name' => $groupName,
				'group_id' => $groupId,
				'actor' => $actorId,
			]
		);
	}

	public function auditGroupRenamed(string $groupId, string $oldName, string $newName, string $actorId): void {
		$this->logAction(
			'Custom group %s renamed from "%s" to "%s" by %s',
			[
				'group_id' => $groupId,
				'old_name' => $oldName,
				'new_name' => $newName,
				'actor' => $actorId,
			]
		);
	}

	public function auditGroupDeleted(string $groupId, string $groupName, string $actorId): void {
		$this->logAction(
			'Custom group "%s" (%s) deleted by %s',
			[
				'group_name' => $groupName,
				'group_id' => $groupId,
				'actor' => $actorId,
			]
		);
	}

	public function auditMemberAdded(string $groupId, string $groupName, string $userId, string $actorId): void {
		$this->logAction(
			'User %s added to custom group "%s" (%s) by %s',
			[
				'user_id' => $userId,
				'group_name' => $groupName,
				'group_id' => $groupId,
				'actor' => $actorId,
			]
		);
	}

	public function auditMemberRemoved(string $groupId, string $groupName, string $userId, string $actorId): void {
		$this->logAction(
			'User %s removed from custom group "%s" (%s) by %s',
			[
				'user_id' => $userId,
				'group_name' => $groupName,
				'group_id' => $groupId,
				'actor' => $actorId,
			]
		);
	}

	public function auditDelegationAssigned(string $groupId, string $groupName, string $userId, string $level, string $actorId): void {
		$this->logAction(
			'Management rights "%s" for group "%s" (%s) delegated to user %s by %s',
			[
				'level' => $level,
				'group_name' => $groupName,
				'group_id' => $groupId,
				'user_id' => $userId,
				'actor' => $actorId,
			]
		);
	}

	public function auditDelegationChanged(string $groupId, string $groupName, string $userId, string $oldLevel, string $newLevel, string $actorId): void {
		$this->logAction(
			'Delegation level for user %s in group "%s" (%s) changed from "%s" to "%s" by %s',
			[
				'user_id' => $userId,
				'group_name' => $groupName,
				'group_id' => $groupId,
				'old_level' => $oldLevel,
				'new_level' => $newLevel,
				'actor' => $actorId,
			]
		);
	}

	public function auditDelegationRevoked(string $groupId, string $groupName, string $userId, string $actorId): void {
		$this->logAction(
			'Delegation for user %s in group "%s" (%s) revoked by %s',
			[
				'user_id' => $userId,
				'group_name' => $groupName,
				'group_id' => $groupId,
				'actor' => $actorId,
			]
		);
	}

	public function auditRequestSubmitted(string $groupId, string $groupName, string $candidateId, string $actorId): void {
		$this->logAction(
			'Membership request for user %s to group "%s" (%s) submitted by %s',
			[
				'candidate_id' => $candidateId,
				'group_name' => $groupName,
				'group_id' => $groupId,
				'actor' => $actorId,
			]
		);
	}

	public function auditRequestApproved(string $groupId, string $groupName, string $candidateId, string $actorId): void {
		$this->logAction(
			'Membership request for user %s to group "%s" (%s) approved by %s',
			[
				'candidate_id' => $candidateId,
				'group_name' => $groupName,
				'group_id' => $groupId,
				'actor' => $actorId,
			]
		);
	}

	public function auditRequestRejected(string $groupId, string $groupName, string $candidateId, string $actorId): void {
		$this->logAction(
			'Membership request for user %s to group "%s" (%s) rejected by %s',
			[
				'candidate_id' => $candidateId,
				'group_name' => $groupName,
				'group_id' => $groupId,
				'actor' => $actorId,
			]
		);
	}
}

