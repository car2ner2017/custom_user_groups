<?php

declare(strict_types=1);

namespace OCA\CustomUserGroups\Controller;

use DateTime;
use Exception;
use OCA\CustomUserGroups\Db\CustomGroupMapper;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Group\Events\GroupChangedEvent;
use OCP\Group\Events\GroupCreatedEvent;
use OCP\Group\Events\UserAddedEvent;
use OCP\Group\Events\UserRemovedEvent;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;

class GroupApiController extends Controller {

	public function __construct(
		string $appName,
		IRequest $request,
		private CustomGroupMapper $mapper,
		private IUserManager $userManager,
		private IGroupManager $groupManager,
		private IEventDispatcher $eventDispatcher,
		private ?string $userId,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/groups')]
	public function getGroups(string $search = ''): JSONResponse {
		if ($this->userId === null) {
			return new JSONResponse(['error' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
		}

		$isAdmin = $this->groupManager->isAdmin($this->userId);
		$rawGroups = $this->mapper->getAllGroups($this->userId, $isAdmin, $search);

		$groups = [];
		foreach ($rawGroups as $g) {
			$groups[] = $this->enrichGroup($g, $isAdmin);
		}

		return new JSONResponse([
			'groups' => $groups,
			'isAdmin' => $isAdmin,
			'currentUserId' => $this->userId,
		]);
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/groups')]
	public function createGroup(string $name, array $memberIds = [], ?string $groupId = null): JSONResponse {
		if ($this->userId === null) {
			return new JSONResponse(['error' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
		}

		$name = trim($name);
		if ($name === '') {
			return new JSONResponse(['error' => 'Group name cannot be empty'], Http::STATUS_BAD_REQUEST);
		}

		// Filter valid users in Nextcloud
		$validMemberIds = [];
		foreach ($memberIds as $uid) {
			$uid = trim((string)$uid);
			if ($uid !== '' && $this->userManager->userExists($uid)) {
				$validMemberIds[] = $uid;
			}
		}

		// Determine groupId
		if ($groupId !== null && trim($groupId) !== '') {
			$cleanGid = preg_replace('/[^a-zA-Z0-9_\-]/', '_', trim($groupId));
			if ($cleanGid === null || $cleanGid === '') {
				$cleanGid = 'cug_' . substr(md5($name . microtime()), 0, 8);
			}
		} else {
			// Generate safe unique groupId
			$cleanGid = 'cug_' . substr(md5($name . microtime()), 0, 8);
		}

		// Ensure uniqueness
		$finalGid = $cleanGid;
		$i = 1;
		while ($this->mapper->groupExists($finalGid) || $this->groupManager->groupExists($finalGid)) {
			$finalGid = $cleanGid . '_' . $i;
			$i++;
		}

		try {
			$this->mapper->createGroup($finalGid, $name, $this->userId, $validMemberIds, new DateTime());
			$created = $this->mapper->getGroupDetails($finalGid);
			if ($created === null) {
				return new JSONResponse(['error' => 'Failed to create group'], Http::STATUS_INTERNAL_SERVER_ERROR);
			}

			// Dispatch Nextcloud group events so files_sharing and other apps register the group and members
			$group = $this->groupManager->get($finalGid);
			if ($group instanceof IGroup) {
				$this->eventDispatcher->dispatchTyped(new GroupCreatedEvent($group));
				foreach ($validMemberIds as $uid) {
					$user = $this->userManager->get($uid);
					if ($user instanceof IUser) {
						$this->eventDispatcher->dispatchTyped(new UserAddedEvent($group, $user));
					}
				}
			}

			$isAdmin = $this->groupManager->isAdmin($this->userId);
			return new JSONResponse($this->enrichGroup($created, $isAdmin), Http::STATUS_CREATED);
		} catch (Exception $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'PUT', url: '/api/v1/groups/{groupId}')]
	public function updateGroup(string $groupId, string $name, array $memberIds = []): JSONResponse {
		if ($this->userId === null) {
			return new JSONResponse(['error' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
		}

		$existing = $this->mapper->getGroupDetails($groupId);
		if ($existing === null) {
			return new JSONResponse(['error' => 'Group not found'], Http::STATUS_NOT_FOUND);
		}

		// Strict permission check: only creator or admin can edit
		$isAdmin = $this->groupManager->isAdmin($this->userId);
		$isCreator = ($existing['creator_id'] === $this->userId);
		if (!$isCreator && !$isAdmin) {
			return new JSONResponse(
				['error' => 'Only the group creator or an Administrator can edit this group'],
				Http::STATUS_FORBIDDEN
			);
		}

		$name = trim($name);
		if ($name === '') {
			return new JSONResponse(['error' => 'Group name cannot be empty'], Http::STATUS_BAD_REQUEST);
		}

		$validMemberIds = [];
		foreach ($memberIds as $uid) {
			$uid = trim((string)$uid);
			if ($uid !== '' && $this->userManager->userExists($uid)) {
				$validMemberIds[] = $uid;
			}
		}

		try {
			$oldMembers = $existing['member_ids'];
			$toAdd = array_values(array_diff($validMemberIds, $oldMembers));
			$toRemove = array_values(array_diff($oldMembers, $validMemberIds));
			$nameChanged = ($existing['name'] !== $name);

			$this->mapper->updateGroup($groupId, $name, $validMemberIds);
			$updated = $this->mapper->getGroupDetails($groupId);
			if ($updated === null) {
				return new JSONResponse(['error' => 'Failed to update group'], Http::STATUS_INTERNAL_SERVER_ERROR);
			}

			// Dispatch Nextcloud core group events so files_sharing updates mounts and permissions
			$group = $this->groupManager->get($groupId);
			if ($group instanceof IGroup) {
				if ($nameChanged) {
					$this->eventDispatcher->dispatchTyped(new GroupChangedEvent($group, 'displayName', $name, $existing['name']));
				}
				foreach ($toRemove as $uid) {
					$user = $this->userManager->get($uid);
					if ($user instanceof IUser) {
						$this->eventDispatcher->dispatchTyped(new UserRemovedEvent($group, $user));
					}
				}
				foreach ($toAdd as $uid) {
					$user = $this->userManager->get($uid);
					if ($user instanceof IUser) {
						$this->eventDispatcher->dispatchTyped(new UserAddedEvent($group, $user));
					}
				}
			}

			return new JSONResponse($this->enrichGroup($updated, $isAdmin));
		} catch (Exception $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'DELETE', url: '/api/v1/groups/{groupId}')]
	public function deleteGroup(string $groupId): JSONResponse {
		if ($this->userId === null) {
			return new JSONResponse(['error' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
		}

		$existing = $this->mapper->getGroupDetails($groupId);
		if ($existing === null) {
			return new JSONResponse(['error' => 'Group not found'], Http::STATUS_NOT_FOUND);
		}

		// Strict permission check: only creator or admin can delete
		$isAdmin = $this->groupManager->isAdmin($this->userId);
		$isCreator = ($existing['creator_id'] === $this->userId);
		if (!$isCreator && !$isAdmin) {
			return new JSONResponse(
				['error' => 'Only the group creator or an Administrator can delete this group'],
				Http::STATUS_FORBIDDEN
			);
		}

		try {
			$group = $this->groupManager->get($groupId);
			if ($group instanceof IGroup) {
				$group->delete();
			} else {
				$this->mapper->deleteGroup($groupId);
			}
			return new JSONResponse(['success' => true]);
		} catch (Exception $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/users')]
	public function searchUsers(string $search = '', int $limit = 50): JSONResponse {
		if ($this->userId === null) {
			return new JSONResponse(['error' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
		}

		$limit = min(max(1, $limit), 100);
		$users = $this->userManager->search($search, $limit);

		$result = [];
		foreach ($users as $user) {
			if ($user instanceof IUser) {
				$result[] = [
					'uid' => $user->getUID(),
					'displayName' => $user->getDisplayName(),
				];
			}
		}

		return new JSONResponse(['users' => $result]);
	}

	/**
	 * Enrich group record with creator display name, detailed members, and permission flag
	 *
	 * @param array{group_id: string, name: string, creator_id: string, created_at: string, member_ids: string[]} $group
	 * @return array<string, mixed>
	 */
	private function enrichGroup(array $group, bool $isAdmin): array {
		$creatorUser = $this->userManager->get($group['creator_id']);
		$creatorDisplayName = $creatorUser ? $creatorUser->getDisplayName() : $group['creator_id'];

		$members = [];
		foreach ($group['member_ids'] as $uid) {
			$user = $this->userManager->get($uid);
			$members[] = [
				'uid' => $uid,
				'displayName' => $user ? $user->getDisplayName() : $uid,
			];
		}

		$canEdit = ($group['creator_id'] === $this->userId) || $isAdmin;

		return [
			'group_id' => $group['group_id'],
			'name' => $group['name'],
			'creator_id' => $group['creator_id'],
			'creator_displayName' => $creatorDisplayName,
			'created_at' => $group['created_at'],
			'member_ids' => $group['member_ids'],
			'members' => $members,
			'member_count' => count($members),
			'can_edit' => $canEdit,
			'is_creator' => ($group['creator_id'] === $this->userId),
		];
	}
}

