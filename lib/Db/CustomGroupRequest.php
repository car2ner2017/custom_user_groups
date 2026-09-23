<?php

declare(strict_types=1);

namespace OCA\UserGroupsHzs\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * @method string|null getGroupId()
 * @method void setGroupId(string $groupId)
 * @method string|null getCandidateId()
 * @method void setCandidateId(string $candidateId)
 * @method string|null getRequesterId()
 * @method void setRequesterId(string $requesterId)
 * @method string|null getStatus()
 * @method void setStatus(string $status)
 * @method DateTime|null getCreatedAt()
 * @method void setCreatedAt(?DateTime $createdAt)
 * @method DateTime|null getUpdatedAt()
 * @method void setUpdatedAt(?DateTime $updatedAt)
 * @method string|null getProcessedBy()
 * @method void setProcessedBy(?string $processedBy)
 */
class CustomGroupRequest extends Entity implements JsonSerializable {

	public const STATUS_PENDING = 'pending';
	public const STATUS_APPROVED = 'approved';
	public const STATUS_REJECTED = 'rejected';

	protected ?string $groupId = null;
	protected ?string $candidateId = null;
	protected ?string $requesterId = null;
	protected ?string $status = self::STATUS_PENDING;
	protected ?DateTime $createdAt = null;
	protected ?DateTime $updatedAt = null;
	protected ?string $processedBy = null;

	public function __construct() {
		$this->addType('groupId', 'string');
		$this->addType('candidateId', 'string');
		$this->addType('requesterId', 'string');
		$this->addType('status', 'string');
		$this->addType('createdAt', 'datetime');
		$this->addType('updatedAt', 'datetime');
		$this->addType('processedBy', 'string');
	}

	#[\ReturnTypeWillChange]
	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'group_id' => $this->groupId,
			'candidate_id' => $this->candidateId,
			'requester_id' => $this->requesterId,
			'status' => $this->status,
			'created_at' => $this->createdAt?->format(DateTime::ATOM),
			'updated_at' => $this->updatedAt?->format(DateTime::ATOM),
			'processed_by' => $this->processedBy,
		];
	}
}

