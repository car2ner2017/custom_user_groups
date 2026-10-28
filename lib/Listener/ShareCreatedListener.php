<?php

declare(strict_types=1);

namespace OCA\UserGroupsHzs\Listener;

use OCA\UserGroupsHzs\Db\CustomGroupActivity;
use OCA\UserGroupsHzs\Db\CustomGroupActivityMapper;
use OCA\UserGroupsHzs\Db\CustomGroupMapper;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Share\Events\ShareCreatedEvent;
use OCP\Share\IShare;
use Override;

/**
 * @template-implements IEventListener<ShareCreatedEvent>
 */
class ShareCreatedListener implements IEventListener {

	public function __construct(
		private CustomGroupMapper $groupMapper,
		private CustomGroupActivityMapper $activityMapper,
		private IUserManager $userManager,
		private IUserSession $userSession,
	) {
	}

	#[Override]
	public function handle(Event $event): void {
		if (!$event instanceof ShareCreatedEvent) {
			return;
		}

		$share = $event->getShare();
		if ($share->getShareType() !== IShare::TYPE_GROUP) {
			return;
		}

		$groupId = (string)$share->getSharedWith();
		if ($groupId === '') {
			return;
		}

		$group = $this->groupMapper->getGroupDetails($groupId);
		if ($group === null) {
			return;
		}

		// Snapshot current members at the time of share creation
		$memberSnapshot = [];
		foreach ($group['member_ids'] as $uid) {
			$u = $this->userManager->get($uid);
			$memberSnapshot[] = [
				'uid' => $uid,
				'displayName' => $u instanceof IUser ? $u->getDisplayName() : $uid,
				'email' => ($u instanceof IUser && $u->getEMailAddress()) ? $u->getEMailAddress() : '',
			];
		}

		$nodeName = '';
		$itemType = $share->getNodeType();
		try {
			$node = $share->getNode();
			$nodeName = $node ? $node->getName() : '';
		} catch (\Throwable) {
			$nodeName = $share->getTarget() ?: 'resource';
		}
		if ($nodeName === '') {
			$nodeName = $share->getTarget() ?: 'resource';
		}

		$actorId = $share->getSharedBy() ?: $share->getShareOwner();
		if (!$actorId && $this->userSession->getUser()) {
			$actorId = $this->userSession->getUser()->getUID();
		}
		if (!$actorId) {
			$actorId = $group['owner_id'] ?? $group['creator_id'];
		}

		$this->activityMapper->logActivity(
			$groupId,
			CustomGroupActivity::ACTION_SHARE_ASSIGN,
			(string)$actorId,
			null,
			[
				'share_id' => $share->getId(),
				'item_type' => $itemType,
				'name' => $nodeName,
				'owner' => $share->getShareOwner(),
				'initiator' => $share->getSharedBy(),
				'members' => $memberSnapshot,
			]
		);
	}
}

