<?php

declare(strict_types=1);

namespace OCA\UserGroupsHzs\Group;

use OCA\UserGroupsHzs\Db\CustomGroupMapper;
use OCP\Group\Backend\ABackend;
use OCP\Group\Backend\IAddToGroupBackend;
use OCP\Group\Backend\ICountUsersBackend;
use OCP\Group\Backend\IDeleteGroupBackend;
use OCP\Group\Backend\IGetDisplayNameBackend;
use OCP\Group\Backend\IGroupDetailsBackend;
use OCP\Group\Backend\IRemoveFromGroupBackend;
use OCP\Group\Backend\ISearchableGroupBackend;
use OCP\Group\Backend\ISetDisplayNameBackend;
use OCP\IUser;
use OCP\IUserManager;

class CustomGroupBackend extends ABackend implements
	IGetDisplayNameBackend,
	ISetDisplayNameBackend,
	IGroupDetailsBackend,
	ICountUsersBackend,
	ISearchableGroupBackend,
	IAddToGroupBackend,
	IRemoveFromGroupBackend,
	IDeleteGroupBackend {

	public function __construct(
		private CustomGroupMapper $mapper,
		private IUserManager $userManager,
	) {
	}

	#[\Override]
	public function inGroup($uid, $gid): bool {
		return $this->mapper->isMember((string)$gid, (string)$uid);
	}

	/**
	 * @return list<string>
	 */
	#[\Override]
	public function getUserGroups($uid): array {
		return $this->mapper->getUserGroupIds((string)$uid);
	}

	/**
	 * @return array<string>
	 */
	#[\Override]
	public function getGroups(string $search = '', int $limit = -1, int $offset = 0): array {
		return $this->mapper->getGroupIds($search, $limit, $offset);
	}

	#[\Override]
	public function groupExists($gid): bool {
		return $this->mapper->groupExists((string)$gid);
	}

	/**
	 * @return array<int, string>
	 */
	#[\Override]
	public function usersInGroup($gid, $search = '', $limit = -1, $offset = 0): array {
		return $this->mapper->getMembers((string)$gid, $search, $limit, $offset);
	}

	#[\Override]
	public function getDisplayName(string $gid): string {
		return $this->mapper->getGroupName($gid) ?? $gid;
	}

	#[\Override]
	public function getGroupDetails(string $gid): array {
		$name = $this->mapper->getGroupName($gid);
		return $name !== null ? ['displayName' => $name] : [];
	}

	#[\Override]
	public function countUsersInGroup(string $gid, string $search = ''): int {
		return $this->mapper->countMembers($gid, $search);
	}

	/**
	 * @return array<string, IUser>
	 */
	#[\Override]
	public function searchInGroup(string $gid, string $search = '', int $limit = -1, int $offset = 0): array {
		$memberIds = $this->mapper->getMembers($gid, $search, $limit, $offset);
		$users = [];
		foreach ($memberIds as $uid) {
			$user = $this->userManager->get($uid);
			if ($user instanceof IUser) {
				$users[$uid] = $user;
			}
		}
		return $users;
	}

	#[\Override]
	public function addToGroup(string $uid, string $gid): bool {
		return $this->mapper->addToGroup($gid, $uid);
	}

	#[\Override]
	public function removeFromGroup(string $uid, string $gid): void {
		$this->mapper->removeFromGroup($gid, $uid);
	}

	#[\Override]
	public function deleteGroup(string $gid): bool {
		$this->mapper->deleteGroup($gid);
		return true;
	}

	#[\Override]
	public function setDisplayName(string $gid, string $displayName): bool {
		return $this->mapper->setGroupName($gid, $displayName);
	}
}

