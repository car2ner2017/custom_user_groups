<?php

declare(strict_types=1);

namespace OCA\UserGroupsHzs\Db;

use DateTime;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<CustomGroupDelegation>
 */
class CustomGroupDelegationMapper extends QBMapper {

	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'hzs_user_group_delegations', CustomGroupDelegation::class);
	}

	/**
	 * @return CustomGroupDelegation[]
	 */
	public function getDelegations(string $groupId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('group_id', $qb->createNamedParameter($groupId)))
			->orderBy('id', 'ASC');

		return $this->findEntities($qb);
	}

	public function getDelegation(string $groupId, string $userId): ?CustomGroupDelegation {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where(
				$qb->expr()->andX(
					$qb->expr()->eq('group_id', $qb->createNamedParameter($groupId)),
					$qb->expr()->eq('user_id', $qb->createNamedParameter($userId))
				)
			);

		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException|MultipleObjectsReturnedException) {
			return null;
		}
	}

	public function setDelegation(string $groupId, string $userId, string $level): CustomGroupDelegation {
		$existing = $this->getDelegation($groupId, $userId);
		if ($existing !== null) {
			$existing->setLevel($level);
			return $this->update($existing);
		}

		$entity = new CustomGroupDelegation();
		$entity->setGroupId($groupId);
		$entity->setUserId($userId);
		$entity->setLevel($level);
		$entity->setCreatedAt(new DateTime());

		return $this->insert($entity);
	}

	public function removeDelegation(string $groupId, string $userId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where(
				$qb->expr()->andX(
					$qb->expr()->eq('group_id', $qb->createNamedParameter($groupId)),
					$qb->expr()->eq('user_id', $qb->createNamedParameter($userId))
				)
			);

		$qb->executeStatement();
	}

	public function revokeDelegation(string $groupId, string $userId): void {
		$this->removeDelegation($groupId, $userId);
	}

	public function removeGroupDelegations(string $groupId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('group_id', $qb->createNamedParameter($groupId)));

		$qb->executeStatement();
	}

	public function removeUserDelegations(string $userId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

		$qb->executeStatement();
	}
}

