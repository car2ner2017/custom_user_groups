<?php

declare(strict_types=1);

namespace OCA\CustomUserGroups\Db;

use DateTime;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<CustomGroupRequest>
 */
class CustomGroupRequestMapper extends QBMapper {

	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'custom_user_group_requests', CustomGroupRequest::class);
	}

	/**
	 * @return CustomGroupRequest[]
	 */
	public function getRequests(string $groupId, ?string $status = null): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('group_id', $qb->createNamedParameter($groupId)));

		if ($status !== null) {
			$qb->andWhere($qb->expr()->eq('status', $qb->createNamedParameter($status)));
		}

		$qb->orderBy('created_at', 'DESC');

		return $this->findEntities($qb);
	}

	public function getRequest(int $id): ?CustomGroupRequest {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));

		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException|MultipleObjectsReturnedException) {
			return null;
		}
	}

	public function hasPendingRequest(string $groupId, string $candidateId): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->createFunction('COUNT(*)'))
			->from($this->getTableName())
			->where(
				$qb->expr()->andX(
					$qb->expr()->eq('group_id', $qb->createNamedParameter($groupId)),
					$qb->expr()->eq('candidate_id', $qb->createNamedParameter($candidateId)),
					$qb->expr()->eq('status', $qb->createNamedParameter(CustomGroupRequest::STATUS_PENDING))
				)
			);

		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();

		return $count > 0;
	}

	public function hasActiveRequest(string $groupId, string $candidateId): bool {
		return $this->hasPendingRequest($groupId, $candidateId);
	}

	public function countPendingRequests(string $groupId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->createFunction('COUNT(*)'))
			->from($this->getTableName())
			->where(
				$qb->expr()->andX(
					$qb->expr()->eq('group_id', $qb->createNamedParameter($groupId)),
					$qb->expr()->eq('status', $qb->createNamedParameter(CustomGroupRequest::STATUS_PENDING))
				)
			);

		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();

		return $count;
	}

	public function createRequest(string $groupId, string $candidateId, string $requesterId): CustomGroupRequest {
		$entity = new CustomGroupRequest();
		$entity->setGroupId($groupId);
		$entity->setCandidateId($candidateId);
		$entity->setRequesterId($requesterId);
		$entity->setStatus(CustomGroupRequest::STATUS_PENDING);
		$now = new DateTime();
		$entity->setCreatedAt($now);
		$entity->setUpdatedAt($now);
		$entity->setProcessedBy(null);

		return $this->insert($entity);
	}

	public function updateStatus(int $id, string $status, string $processedBy): ?CustomGroupRequest {
		$request = $this->getRequest($id);
		if ($request === null) {
			return null;
		}

		$request->setStatus($status);
		$request->setProcessedBy($processedBy);
		$request->setUpdatedAt(new DateTime());

		return $this->update($request);
	}

	public function removeGroupRequests(string $groupId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('group_id', $qb->createNamedParameter($groupId)));

		$qb->executeStatement();
	}
}

