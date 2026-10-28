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
use OCP\Share\Events\BeforeShareDeletedEvent;
use OCP\Share\IShare;
use Override;

/**
 * @template-implements IEventListener<BeforeShareDeletedEvent>
 */
class ShareDeletedListener implements IEventListener {

	/** @var array<string, int> */
	public static array $recentlyLoggedShares = [];

	public function __construct(
		private CustomGroupMapper $groupMapper,
		private CustomGroupActivityMapper $activityMapper,
		private IUserManager $userManager,
		private IUserSession $userSession,
	) {
	}

	#[Override]
	public function handle(Event $event): void {
		if (!$event instanceof BeforeShareDeletedEvent) {
			return;
		}

		$share = $event->getShare();
		if ($share->getShareType() !== IShare::TYPE_GROUP) {
			return;
		}

		$shareId = (string)$share->getId();
		if (isset(self::$recentlyLoggedShares[$shareId]) && (time() - self::$recentlyLoggedShares[$shareId]) < 5) {
			return;
		}
		self::$recentlyLoggedShares[$shareId] = time();

		$groupId = (string)$share->getSharedWith();
		if ($groupId === '') {
			return;
		}

		$group = $this->groupMapper->getGroupDetails($groupId);
		if ($group === null) {
			return;
		}

		// Snapshot current members affected by the share revocation
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

		$currentUid = '';
		if ($this->userSession->getUser()) {
			$currentUid = $this->userSession->getUser()->getUID();
		}
		if ($currentUid === '') {
			$currentUid = $share->getSharedBy() ?: ($share->getShareOwner() ?: ($group['owner_id'] ?? $group['creator_id']));
		}

		$this->activityMapper->logActivity(
			$groupId,
			CustomGroupActivity::ACTION_SHARE_UNSHARE,
			(string)$currentUid,
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
