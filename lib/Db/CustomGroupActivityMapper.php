<?php

declare(strict_types=1);

namespace OCA\CustomUserGroups\Db;

use DateTime;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\IUserManager;
use OCP\IUser;

/**
 * @template-extends QBMapper<CustomGroupActivity>
 */
class CustomGroupActivityMapper extends QBMapper {

	public function __construct(
		IDBConnection $db,
		private IUserManager $userManager,
		private IL10N $l10n,
	) {
		parent::__construct($db, 'custom_user_group_activity', CustomGroupActivity::class);
	}

	public function logActivity(
		string $groupId,
		string $actionType,
		string $actorId,
		?string $targetId = null,
		?array $details = null
	): CustomGroupActivity {
		$activity = new CustomGroupActivity();
		$activity->setGroupId($groupId);
		$activity->setActionType($actionType);
		$activity->setActorId($actorId);
		$activity->setTargetId($targetId);
		$activity->setDetails($details !== null ? json_encode($details, JSON_UNESCAPED_UNICODE) : null);
		$activity->setCreatedAt(new DateTime());

		return $this->insert($activity);
	}

	/**
	 * @return array<int, array{
	 *     id: int,
	 *     group_id: string,
	 *     action_type: string,
	 *     actor_id: string,
	 *     actor_displayName: string,
	 *     actor_email: string,
	 *     target_id: ?string,
	 *     target_displayName: ?string,
	 *     target_email: ?string,
	 *     details: ?array,
	 *     description: string,
	 *     created_at: string
	 * }>
	 */
	public function getActivities(string $groupId, int $limit = 200, int $offset = 0): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('group_id', $qb->createNamedParameter($groupId)))
			->orderBy('created_at', 'DESC')
			->addOrderBy('id', 'DESC');

		if ($limit > 0) {
			$qb->setMaxResults($limit);
		}
		if ($offset > 0) {
			$qb->setFirstResult($offset);
		}

		$entities = $this->findEntities($qb);
		$result = [];

		foreach ($entities as $entity) {
			$actorUser = $this->userManager->get((string)$entity->getActorId());
			$actorName = $actorUser instanceof IUser ? $actorUser->getDisplayName() : (string)$entity->getActorId();
			$actorEmail = $actorUser instanceof IUser ? ($actorUser->getEMailAddress() ?: '') : '';

			$targetId = $entity->getTargetId();
			$targetName = null;
			$targetEmail = null;
			if ($targetId !== null && $targetId !== '') {
				$targetUser = $this->userManager->get($targetId);
				$targetName = $targetUser instanceof IUser ? $targetUser->getDisplayName() : $targetId;
				$targetEmail = $targetUser instanceof IUser ? ($targetUser->getEMailAddress() ?: '') : '';
			}

			$rawDetails = $entity->getDetails();
			$details = null;
			if ($rawDetails !== null && $rawDetails !== '') {
				$details = json_decode($rawDetails, true);
			}

			$description = $this->formatDescription(
				(string)$entity->getActionType(),
				$targetName ?: (string)$targetId,
				$details
			);

			$createdAt = $entity->getCreatedAt();

			$result[] = [
				'id' => (int)$entity->getId(),
				'group_id' => (string)$entity->getGroupId(),
				'action_type' => (string)$entity->getActionType(),
				'actor_id' => (string)$entity->getActorId(),
				'actor_displayName' => $actorName,
				'actor_email' => $actorEmail,
				'target_id' => $targetId,
				'target_displayName' => $targetName,
				'target_email' => $targetEmail,
				'details' => $details,
				'description' => $description,
				'created_at' => $createdAt ? $createdAt->format(DateTime::ATOM) : '',
			];
		}

		return $result;
	}

	private function formatDescription(string $actionType, ?string $targetName, ?array $details): string {
		switch ($actionType) {
			case CustomGroupActivity::ACTION_MEMBER_ADD:
				return $targetName
					? $this->l10n->t('Added member %s', [$targetName])
					: $this->l10n->t('Added new member');

			case CustomGroupActivity::ACTION_MEMBER_REMOVE:
				return $targetName
					? $this->l10n->t('Removed member %s', [$targetName])
					: $this->l10n->t('Removed member');

			case CustomGroupActivity::ACTION_DELEGATION_ASSIGN:
				$level = $details['level'] ?? '';
				$levelText = $level === 'manage' ? $this->l10n->t('«Manage»') : ($level === 'moderate' ? $this->l10n->t('«Moderate»') : '');
				return $targetName
					? $this->l10n->t('Assigned rights %s to member %s', [$levelText, $targetName])
					: $this->l10n->t('Assigned delegation rights %s', [$levelText]);

			case CustomGroupActivity::ACTION_DELEGATION_REVOKE:
				return $targetName
					? $this->l10n->t('Revoked management rights from member %s', [$targetName])
					: $this->l10n->t('Revoked management rights');

			case CustomGroupActivity::ACTION_NAME_CHANGE:
				$newName = $details['new_name'] ?? '';
				$oldName = $details['old_name'] ?? '';
				if ($oldName !== '' && $newName !== '') {
					return $this->l10n->t('Group name changed from «%s» to «%s»', [$oldName, $newName]);
				}
				return $newName !== '' ? $this->l10n->t('Group renamed to «%s»', [$newName]) : $this->l10n->t('Group name changed');

			case CustomGroupActivity::ACTION_OWNER_TRANSFER:
				return $targetName
					? $this->l10n->t('Group ownership transferred to member %s', [$targetName])
					: $this->l10n->t('Group ownership transferred');

			case CustomGroupActivity::ACTION_SHARE_UNSHARE:
				$name = $details['name'] ?? '';
				$itemType = ($details['item_type'] ?? '') === 'folder' ? $this->l10n->t('folder') : $this->l10n->t('file');
				if ($name !== '') {
					return $this->l10n->t('Revoked group access to %s «%s»', [$itemType, $name]);
				}
				return $this->l10n->t('Revoked group access to resource');

			default:
				return $this->l10n->t('Group action');
		}
	}
}

