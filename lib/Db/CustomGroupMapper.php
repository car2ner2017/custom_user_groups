<?php

declare(strict_types=1);

namespace OCA\CustomUserGroups\Db;

use DateTime;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<CustomGroupMember>
 */
class CustomGroupMapper extends QBMapper {

	private static bool $schemaChecked = false;

	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'custom_user_groups', CustomGroupMember::class);
		$this->ensureSchema();
	}

	private function ensureSchema(): void {
		if (self::$schemaChecked) {
			return;
		}
		self::$schemaChecked = true;

		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select('*')
				->from($this->getTableName())
				->setMaxResults(1);
			$res = $qb->executeQuery();
			$row = $res->fetchAssociative();
			$res->closeCursor();

			if ($row !== false && !array_key_exists('owner_id', $row)) {
				$this->db->executeStatement("ALTER TABLE {$this->getTableName()} ADD COLUMN owner_id VARCHAR(64) NULL DEFAULT NULL");
				$this->db->executeStatement("UPDATE {$this->getTableName()} SET owner_id = creator_id WHERE owner_id IS NULL OR owner_id = ''");
			}
		} catch (\Throwable $e) {
			// Suppress error if table does not exist yet or no permission
		}
	}

	public function groupExists(string $groupId): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->createFunction('COUNT(*)'))
			->from($this->getTableName())
			->where($qb->expr()->eq('group_id', $qb->createNamedParameter($groupId)));

		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();

		return $count > 0;
	}

	public function getGroupName(string $groupId): ?string {
		$qb = $this->db->getQueryBuilder();
		$qb->select('name')
			->from($this->getTableName())
			->where($qb->expr()->eq('group_id', $qb->createNamedParameter($groupId)))
			->setMaxResults(1);

		$result = $qb->executeQuery();
		$row = $result->fetchAssociative();
		$result->closeCursor();

		return $row ? (string)$row['name'] : null;
	}

	/**
	 * @return string[]
	 */
	public function getMembers(string $groupId, string $search = '', int $limit = -1, int $offset = 0): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('member_id')
			->from($this->getTableName())
			->where(
				$qb->expr()->andX(
					$qb->expr()->eq('group_id', $qb->createNamedParameter($groupId)),
					$qb->expr()->isNotNull('member_id'),
					$qb->expr()->neq('member_id', $qb->createNamedParameter(''))
				)
			);

		if ($search !== '') {
			$qb->andWhere($qb->expr()->like('member_id', $qb->createNamedParameter('%' . $this->db->escapeLikeParameter($search) . '%')));
		}

		if ($limit > 0) {
			$qb->setMaxResults($limit);
		}
		if ($offset > 0) {
			$qb->setFirstResult($offset);
		}

		$result = $qb->executeQuery();
		$rows = $result->fetchAllAssociative();
		$result->closeCursor();

		return array_values(array_unique(array_filter(array_column($rows, 'member_id'))));
	}

	public function countMembers(string $groupId, string $search = ''): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->createFunction('COUNT(DISTINCT ' . $qb->getColumnName('member_id') . ')'))
			->from($this->getTableName())
			->where(
				$qb->expr()->andX(
					$qb->expr()->eq('group_id', $qb->createNamedParameter($groupId)),
					$qb->expr()->isNotNull('member_id'),
					$qb->expr()->neq('member_id', $qb->createNamedParameter(''))
				)
			);

		if ($search !== '') {
			$qb->andWhere($qb->expr()->like('member_id', $qb->createNamedParameter('%' . $this->db->escapeLikeParameter($search) . '%')));
		}

		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();

		return $count;
	}

	public function isMember(string $groupId, string $userId): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->createFunction('COUNT(*)'))
			->from($this->getTableName())
			->where(
				$qb->expr()->andX(
					$qb->expr()->eq('group_id', $qb->createNamedParameter($groupId)),
					$qb->expr()->eq('member_id', $qb->createNamedParameter($userId))
				)
			);

		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();

		return $count > 0;
	}

	/**
	 * @return string[]
	 */
	public function getUserGroupIds(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->selectDistinct('group_id')
			->from($this->getTableName())
			->where($qb->expr()->eq('member_id', $qb->createNamedParameter($userId)));

		$result = $qb->executeQuery();
		$rows = $result->fetchAllAssociative();
		$result->closeCursor();

		return array_values(array_filter(array_column($rows, 'group_id')));
	}

	/**
	 * @return string[]
	 */
	public function getGroupIds(string $search = '', int $limit = -1, int $offset = 0): array {
		$qb = $this->db->getQueryBuilder();
		$qb->selectDistinct('group_id')
			->from($this->getTableName());

		if ($search !== '') {
			$escaped = '%' . $this->db->escapeLikeParameter($search) . '%';
			$qb->where(
				$qb->expr()->orX(
					$qb->expr()->like('name', $qb->createNamedParameter($escaped)),
					$qb->expr()->like('group_id', $qb->createNamedParameter($escaped))
				)
			);
		}

		if ($limit > 0) {
			$qb->setMaxResults($limit);
		}
		if ($offset > 0) {
			$qb->setFirstResult($offset);
		}

		$result = $qb->executeQuery();
		$rows = $result->fetchAllAssociative();
		$result->closeCursor();

		return array_values(array_filter(array_column($rows, 'group_id')));
	}

	/**
	 * Returns metadata and list of member IDs for a group
	 *
	 * @return array{group_id: string, name: string, creator_id: string, owner_id: string, created_at: string, member_ids: string[]}|null
	 */
	public function getGroupDetails(string $groupId): ?array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('group_id', $qb->createNamedParameter($groupId)))
			->orderBy('id', 'ASC');

		$result = $qb->executeQuery();
		$rows = $result->fetchAllAssociative();
		$result->closeCursor();

		if (empty($rows)) {
			return null;
		}

		$first = $rows[0];
		$creatorId = (string)$first['creator_id'];
		$ownerId = (!empty($first['owner_id'])) ? (string)$first['owner_id'] : $creatorId;
		$members = [];
		foreach ($rows as $row) {
			if (!empty($row['member_id'])) {
				$members[] = (string)$row['member_id'];
			}
		}

		return [
			'group_id' => (string)$first['group_id'],
			'name' => (string)$first['name'],
			'creator_id' => $creatorId,
			'owner_id' => $ownerId,
			'created_at' => (string)$first['created_at'],
			'member_ids' => array_values(array_unique($members)),
		];
	}

	/**
	 * Returns list of groups with members
	 *
	 * @return array<int, array{group_id: string, name: string, creator_id: string, owner_id: string, created_at: string, member_ids: string[]}>
	 */
	public function getAllGroups(?string $forUserId = null, bool $isAdmin = false, string $search = ''): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->orderBy('created_at', 'DESC')
			->addOrderBy('id', 'ASC');

		if (!$isAdmin && $forUserId !== null) {
			// Find group_ids where user is creator, owner or member
			$subQb = $this->db->getQueryBuilder();
			$subQb->selectDistinct('group_id')
				->from($this->getTableName())
				->where(
					$subQb->expr()->orX(
						$subQb->expr()->eq('creator_id', $subQb->createNamedParameter($forUserId)),
						$subQb->expr()->eq('owner_id', $subQb->createNamedParameter($forUserId)),
						$subQb->expr()->eq('member_id', $subQb->createNamedParameter($forUserId))
					)
				);
			$subResult = $subQb->executeQuery();
			$allowedGroupIds = array_column($subResult->fetchAllAssociative(), 'group_id');
			$subResult->closeCursor();

			if (empty($allowedGroupIds)) {
				return [];
			}

			$qb->where($qb->expr()->in('group_id', $qb->createNamedParameter($allowedGroupIds, IQueryBuilder::PARAM_STR_ARRAY)));
		}

		if ($search !== '') {
			$escaped = '%' . $this->db->escapeLikeParameter($search) . '%';
			$qb->andWhere(
				$qb->expr()->orX(
					$qb->expr()->like('name', $qb->createNamedParameter($escaped)),
					$qb->expr()->like('group_id', $qb->createNamedParameter($escaped))
				)
			);
		}

		$result = $qb->executeQuery();
		$rows = $result->fetchAllAssociative();
		$result->closeCursor();

		$groups = [];
		foreach ($rows as $row) {
			$gid = (string)$row['group_id'];
			if (!isset($groups[$gid])) {
				$creatorId = (string)$row['creator_id'];
				$ownerId = (!empty($row['owner_id'])) ? (string)$row['owner_id'] : $creatorId;
				$groups[$gid] = [
					'group_id' => $gid,
					'name' => (string)$row['name'],
					'creator_id' => $creatorId,
					'owner_id' => $ownerId,
					'created_at' => (string)$row['created_at'],
					'member_ids' => [],
				];
			}
			if (!empty($row['member_id'])) {
				$groups[$gid]['member_ids'][] = (string)$row['member_id'];
			}
		}

		// Ensure unique member_ids
		foreach ($groups as &$group) {
			$group['member_ids'] = array_values(array_unique($group['member_ids']));
		}

		return array_values($groups);
	}

	/**
	 * Create group and insert rows per member
	 *
	 * @param string[] $memberIds
	 */
	public function createGroup(string $groupId, string $name, string $creatorId, array $memberIds, DateTime $createdAt, ?string $ownerId = null): void {
		$memberIds = array_values(array_unique(array_filter($memberIds)));
		$ownerId = ($ownerId !== null && trim($ownerId) !== '') ? trim($ownerId) : $creatorId;

		if (empty($memberIds)) {
			$entity = new CustomGroupMember();
			$entity->setGroupId($groupId);
			$entity->setName($name);
			$entity->setCreatorId($creatorId);
			$entity->setOwnerId($ownerId);
			$entity->setMemberId(null);
			$entity->setCreatedAt($createdAt);
			$this->insert($entity);
			return;
		}

		foreach ($memberIds as $memberId) {
			$entity = new CustomGroupMember();
			$entity->setGroupId($groupId);
			$entity->setName($name);
			$entity->setCreatorId($creatorId);
			$entity->setOwnerId($ownerId);
			$entity->setMemberId($memberId);
			$entity->setCreatedAt($createdAt);
			$this->insert($entity);
		}
	}

	/**
	 * Update group name, members list, and optionally transfer owner
	 *
	 * @param string[] $newMemberIds
	 */
	public function updateGroup(string $groupId, string $newName, array $newMemberIds, ?string $newOwnerId = null): void {
		$existingDetails = $this->getGroupDetails($groupId);
		if ($existingDetails === null) {
			return;
		}

		$creatorId = $existingDetails['creator_id'];
		$ownerId = ($newOwnerId !== null && trim($newOwnerId) !== '') ? trim($newOwnerId) : $existingDetails['owner_id'];
		$createdAt = new DateTime($existingDetails['created_at']);
		$newMemberIds = array_values(array_unique(array_filter($newMemberIds)));

		// Revoke delegations for users removed from group
		$removed = array_diff($existingDetails['member_ids'], $newMemberIds);
		foreach ($removed as $removedUid) {
			$this->cleanupUserDelegation($groupId, (string)$removedUid);
		}

		// If owner was transferred, remove delegation entry for the new owner
		if ($ownerId !== $existingDetails['owner_id']) {
			$this->cleanupUserDelegation($groupId, $ownerId);
		}

		// Delete existing membership rows for this group
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('group_id', $qb->createNamedParameter($groupId)));
		$qb->executeStatement();

		// Insert updated rows with preserved creatorId and updated/preserved ownerId
		$this->createGroup($groupId, $newName, $creatorId, $newMemberIds, $createdAt, $ownerId);
	}

	public function transferOwnership(string $groupId, string $newOwnerId): void {
		$newOwnerId = trim($newOwnerId);
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('owner_id', $qb->createNamedParameter($newOwnerId))
			->where($qb->expr()->eq('group_id', $qb->createNamedParameter($groupId)));
		$qb->executeStatement();

		$this->cleanupUserDelegation($groupId, $newOwnerId);
	}

	public function deleteGroup(string $groupId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('group_id', $qb->createNamedParameter($groupId)));
		$qb->executeStatement();

		$this->cleanupGroupDelegationsAndRequests($groupId);
	}

	public function addToGroup(string $groupId, string $userId): bool {
		if ($this->isMember($groupId, $userId)) {
			return true;
		}

		$details = $this->getGroupDetails($groupId);
		if ($details === null) {
			return false;
		}

		// Check if there is a placeholder row with null or empty member_id
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from($this->getTableName())
			->where($qb->expr()->eq('group_id', $qb->createNamedParameter($groupId)))
			->andWhere($qb->expr()->orX(
				$qb->expr()->isNull('member_id'),
				$qb->expr()->eq('member_id', $qb->createNamedParameter(''))
			))
			->setMaxResults(1);
		$res = $qb->executeQuery();
		$placeholderId = $res->fetchOne();
		$res->closeCursor();

		if ($placeholderId !== false) {
			$updateQb = $this->db->getQueryBuilder();
			$updateQb->update($this->getTableName())
				->set('member_id', $updateQb->createNamedParameter($userId))
				->where($updateQb->expr()->eq('id', $updateQb->createNamedParameter((int)$placeholderId)))
				->executeStatement();
			return true;
		}

		$entity = new CustomGroupMember();
		$entity->setGroupId($groupId);
		$entity->setName($details['name']);
		$entity->setCreatorId($details['creator_id']);
		$entity->setOwnerId($details['owner_id']);
		$entity->setMemberId($userId);
		$entity->setCreatedAt(new DateTime($details['created_at']));
		$this->insert($entity);

		return true;
	}

	public function removeFromGroup(string $groupId, string $userId): bool {
		if (!$this->isMember($groupId, $userId)) {
			return true;
		}

		$details = $this->getGroupDetails($groupId);
		if ($details === null) {
			return false;
		}

		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('group_id', $qb->createNamedParameter($groupId)))
			->andWhere($qb->expr()->eq('member_id', $qb->createNamedParameter($userId)))
			->executeStatement();

		// Revoke delegation when user is removed from membership
		$this->cleanupUserDelegation($groupId, $userId);

		// If no rows remain for this group, insert a placeholder row so the group itself persists
		if (!$this->groupExists($groupId)) {
			$entity = new CustomGroupMember();
			$entity->setGroupId($groupId);
			$entity->setName($details['name']);
			$entity->setCreatorId($details['creator_id']);
			$entity->setOwnerId($details['owner_id']);
			$entity->setMemberId(null);
			$entity->setCreatedAt(new DateTime($details['created_at']));
			$this->insert($entity);
		}

		return true;
	}

	public function cleanupUserDelegation(string $groupId, string $userId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete('custom_user_group_delegations')
			->where(
				$qb->expr()->andX(
					$qb->expr()->eq('group_id', $qb->createNamedParameter($groupId)),
					$qb->expr()->eq('user_id', $qb->createNamedParameter($userId))
				)
			);
		$qb->executeStatement();
	}

	public function cleanupGroupDelegationsAndRequests(string $groupId): void {
		$qb1 = $this->db->getQueryBuilder();
		$qb1->delete('custom_user_group_delegations')
			->where($qb1->expr()->eq('group_id', $qb1->createNamedParameter($groupId)));
		$qb1->executeStatement();

		$qb2 = $this->db->getQueryBuilder();
		$qb2->delete('custom_user_group_requests')
			->where($qb2->expr()->eq('group_id', $qb2->createNamedParameter($groupId)));
		$qb2->executeStatement();

		$qb3 = $this->db->getQueryBuilder();
		$qb3->delete('custom_user_group_activity')
			->where($qb3->expr()->eq('group_id', $qb3->createNamedParameter($groupId)));
		$qb3->executeStatement();
	}

	public function setGroupName(string $groupId, string $name): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('name', $qb->createNamedParameter($name))
			->where($qb->expr()->eq('group_id', $qb->createNamedParameter($groupId)))
			->executeStatement();

		return true;
	}
}

