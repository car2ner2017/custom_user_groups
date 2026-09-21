<?php

declare(strict_types=1);

namespace OCA\CustomUserGroups\Db;

use DateTime;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\IUser;

/**
 * @template-extends QBMapper<CustomGroupActivity>
 */
class CustomGroupActivityMapper extends QBMapper {

	public function __construct(
		IDBConnection $db,
		private IUserManager $userManager,
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
				return $targetName ? "Добавлен участник $targetName" : 'Добавлен новый участник';

			case CustomGroupActivity::ACTION_MEMBER_REMOVE:
				return $targetName ? "Удален участник $targetName" : 'Удален участник';

			case CustomGroupActivity::ACTION_DELEGATION_ASSIGN:
				$level = $details['level'] ?? '';
				$levelText = $level === 'manage' ? '«Управление»' : ($level === 'moderate' ? '«Модерация»' : '');
				return $targetName
					? "Назначены права $levelText участнику $targetName"
					: "Назначены права делегирования $levelText";

			case CustomGroupActivity::ACTION_DELEGATION_REVOKE:
				return $targetName
					? "Отозваны права управления у участника $targetName"
					: 'Отозваны права управления';

			case CustomGroupActivity::ACTION_NAME_CHANGE:
				$newName = $details['new_name'] ?? '';
				$oldName = $details['old_name'] ?? '';
				if ($oldName !== '' && $newName !== '') {
					return "Название группы изменено с «{$oldName}» на «{$newName}»";
				}
				return $newName !== '' ? "Группа переименована в «{$newName}»" : 'Изменено название группы';

			case CustomGroupActivity::ACTION_OWNER_TRANSFER:
				return $targetName
					? "Владение группой передано участнику $targetName"
					: 'Передано владение группой';

			case CustomGroupActivity::ACTION_SHARE_UNSHARE:
				$name = $details['name'] ?? '';
				$itemType = ($details['item_type'] ?? '') === 'folder' ? 'папке' : 'файлу';
				if ($name !== '') {
					return "Отозван общий доступ к {$itemType} «{$name}» для группы";
				}
				return 'Отозван общий доступ к ресурсу для группы';

			default:
				return 'Действие с группой';
		}
	}
}

