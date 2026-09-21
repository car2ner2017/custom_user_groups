<?php

declare(strict_types=1);

namespace OCA\CustomUserGroups\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * @method string|null getGroupId()
 * @method void setGroupId(string $groupId)
 * @method string|null getActionType()
 * @method void setActionType(string $actionType)
 * @method string|null getActorId()
 * @method void setActorId(string $actorId)
 * @method string|null getTargetId()
 * @method void setTargetId(?string $targetId)
 * @method string|null getDetails()
 * @method void setDetails(?string $details)
 * @method DateTime|null getCreatedAt()
 * @method void setCreatedAt(?DateTime $createdAt)
 */
class CustomGroupActivity extends Entity implements JsonSerializable {

	public const ACTION_MEMBER_ADD = 'member_add';
	public const ACTION_MEMBER_REMOVE = 'member_remove';
	public const ACTION_DELEGATION_ASSIGN = 'delegation_assign';
	public const ACTION_DELEGATION_REVOKE = 'delegation_revoke';
	public const ACTION_NAME_CHANGE = 'name_change';
	public const ACTION_OWNER_TRANSFER = 'owner_transfer';
	public const ACTION_SHARE_UNSHARE = 'share_unshare';

	protected ?string $groupId = null;
	protected ?string $actionType = null;
	protected ?string $actorId = null;
	protected ?string $targetId = null;
	protected ?string $details = null;
	protected ?DateTime $createdAt = null;

	public function __construct() {
		$this->addType('groupId', 'string');
		$this->addType('actionType', 'string');
		$this->addType('actorId', 'string');
		$this->addType('targetId', 'string');
		$this->addType('details', 'string');
		$this->addType('createdAt', 'datetime');
	}

	#[\ReturnTypeWillChange]
	public function jsonSerialize(): array {
		$decodedDetails = null;
		if ($this->details !== null && $this->details !== '') {
			$decodedDetails = json_decode($this->details, true);
		}

		return [
			'id' => $this->id,
			'group_id' => $this->groupId,
			'action_type' => $this->actionType,
			'actor_id' => $this->actorId,
			'target_id' => $this->targetId,
			'details' => $decodedDetails,
			'created_at' => $this->createdAt?->format(DateTime::ATOM),
		];
	}
}

