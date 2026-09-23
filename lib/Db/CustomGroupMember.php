<?php

declare(strict_types=1);

namespace OCA\UserGroupsHzs\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * @method string|null getGroupId()
 * @method void setGroupId(string $groupId)
 * @method string|null getName()
 * @method void setName(string $name)
 * @method string|null getCreatorId()
 * @method void setCreatorId(string $creatorId)
 * @method string|null getOwnerId()
 * @method void setOwnerId(?string $ownerId)
 * @method string|null getMemberId()
 * @method void setMemberId(?string $memberId)
 * @method DateTime|null getCreatedAt()
 * @method void setCreatedAt(?DateTime $createdAt)
 */
class CustomGroupMember extends Entity implements JsonSerializable {

	protected ?string $groupId = null;
	protected ?string $name = null;
	protected ?string $creatorId = null;
	protected ?string $ownerId = null;
	protected ?string $memberId = null;
	protected ?DateTime $createdAt = null;

	public function __construct() {
		$this->addType('groupId', 'string');
		$this->addType('name', 'string');
		$this->addType('creatorId', 'string');
		$this->addType('ownerId', 'string');
		$this->addType('memberId', 'string');
		$this->addType('createdAt', 'datetime');
	}

	#[\ReturnTypeWillChange]
	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'group_id' => $this->groupId,
			'name' => $this->name,
			'creator_id' => $this->creatorId,
			'owner_id' => $this->ownerId ?? $this->creatorId,
			'member_id' => $this->memberId,
			'created_at' => $this->createdAt?->format(DateTime::ATOM),
		];
	}
}

