<?php

declare(strict_types=1);

namespace OCA\CustomUserGroups\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * @method string|null getGroupId()
 * @method void setGroupId(string $groupId)
 * @method string|null getUserId()
 * @method void setUserId(string $userId)
 * @method string|null getLevel()
 * @method void setLevel(string $level)
 * @method DateTime|null getCreatedAt()
 * @method void setCreatedAt(?DateTime $createdAt)
 */
class CustomGroupDelegation extends Entity implements JsonSerializable {

	public const LEVEL_MANAGE = 'manage';
	public const LEVEL_MODERATE = 'moderate';

	protected ?string $groupId = null;
	protected ?string $userId = null;
	protected ?string $level = null;
	protected ?DateTime $createdAt = null;

	public function __construct() {
		$this->addType('groupId', 'string');
		$this->addType('userId', 'string');
		$this->addType('level', 'string');
		$this->addType('createdAt', 'datetime');
	}

	#[\ReturnTypeWillChange]
	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'group_id' => $this->groupId,
			'user_id' => $this->userId,
			'level' => $this->level,
			'created_at' => $this->createdAt?->format(DateTime::ATOM),
		];
	}
}

