<?php

declare(strict_types=1);

namespace OCA\UserGroupsHzs\Controller;

use DateTime;
use Exception;
use OCA\UserGroupsHzs\Db\CustomGroupActivity;
use OCA\UserGroupsHzs\Db\CustomGroupActivityMapper;
use OCA\UserGroupsHzs\Db\CustomGroupDelegation;
use OCA\UserGroupsHzs\Db\CustomGroupDelegationMapper;
use OCA\UserGroupsHzs\Db\CustomGroupMapper;
use OCA\UserGroupsHzs\Db\CustomGroupRequest;
use OCA\UserGroupsHzs\Db\CustomGroupRequestMapper;
use OCA\UserGroupsHzs\Service\AuditService;
use OCA\UserGroupsHzs\Service\SettingsService;
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
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Share\Exceptions\ShareNotFound;
use OCP\Share\IManager;
use OCP\Share\IShare;

class GroupApiController extends Controller {

	public function __construct(
		string $appName,
		IRequest $request,
		private CustomGroupMapper $mapper,
		private CustomGroupDelegationMapper $delegationMapper,
		private CustomGroupRequestMapper $requestMapper,
		private CustomGroupActivityMapper $activityMapper,
		private AuditService $auditService,
		private SettingsService $settingsService,
		private IUserManager $userManager,
		private IGroupManager $groupManager,
		private IEventDispatcher $eventDispatcher,
		private IManager $shareManager,
		private ?string $userId,
		private ?IL10N $l10n = null,
	) {
		parent::__construct($appName, $request);
	}

	private function t(string $text, array $parameters = []): string {
		return $this->l10n !== null ? $this->l10n->t($text, $parameters) : (empty($parameters) ? $text : vsprintf($text, $parameters));
	}

	private function checkAccess(): ?JSONResponse {
		if ($this->userId === null) {
			return new JSONResponse(['error' => $this->t('Authentication required')], Http::STATUS_UNAUTHORIZED);
		}
		if (!$this->settingsService->isUserAccessAllowed($this->userId)) {
			return new JSONResponse(['error' => $this->t('Application access is restricted by administrator')], Http::STATUS_FORBIDDEN);
		}
		return null;
	}

	private function checkAdmin(): ?JSONResponse {
		$authErr = $this->checkAccess();
		if ($authErr !== null) {
			return $authErr;
		}
		if (!$this->groupManager->isAdmin((string)$this->userId)) {
			return new JSONResponse(['error' => $this->t('Access allowed only for system administrators')], Http::STATUS_FORBIDDEN);
		}
		return null;
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/groups')]
	public function getGroups(string $search = ''): JSONResponse {
		if ($err = $this->checkAccess()) {
			return $err;
		}

		$isAdmin = $this->groupManager->isAdmin((string)$this->userId);
		$canCreateGroups = $this->settingsService->canUserCreateGroups($this->userId);
		$rawGroups = $this->mapper->getAllGroups((string)$this->userId, $isAdmin, $search);

		$groups = [];
		foreach ($rawGroups as $g) {
			$groups[] = $this->enrichGroup($g, $isAdmin);
		}

		return new JSONResponse([
			'groups' => $groups,
			'isAdmin' => $isAdmin,
			'canCreateGroups' => $canCreateGroups,
			'currentUserId' => $this->userId,
		]);
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/groups')]
	public function createGroup(string $name, array $memberIds = []): JSONResponse {
		if ($err = $this->checkAccess()) {
			return $err;
		}

		if (!$this->settingsService->canUserCreateGroups($this->userId)) {
			return new JSONResponse(
				['error' => $this->t('Creation of custom groups is restricted by administrator')],
				Http::STATUS_FORBIDDEN
			);
		}

		$name = trim($name);
		if ($name === '') {
			return new JSONResponse(['error' => $this->t('Group name cannot be empty')], Http::STATUS_BAD_REQUEST);
		}

		// Filter valid users in Nextcloud
		$validMemberIds = [];
		foreach ($memberIds as $uid) {
			$uid = trim((string)$uid);
			if ($uid !== '' && $this->userManager->userExists($uid)) {
				$validMemberIds[] = $uid;
			}
		}
		$validMemberIds = array_values(array_unique($validMemberIds));

		// Auto-generate group ID exclusively
		$cleanGid = 'cug_' . substr(md5($name . microtime()), 0, 8);
		$finalGid = $cleanGid;
		$i = 1;
		while ($this->mapper->groupExists($finalGid) || $this->groupManager->groupExists($finalGid)) {
			$finalGid = $cleanGid . '_' . $i;
			$i++;
		}

		try {
			$this->mapper->createGroup($finalGid, $name, (string)$this->userId, $validMemberIds, new DateTime());
			$created = $this->mapper->getGroupDetails($finalGid);
			if ($created === null) {
				return new JSONResponse(['error' => $this->t('Failed to create group')], Http::STATUS_INTERNAL_SERVER_ERROR);
			}

			// Nextcloud Audit log
			$this->auditService->auditGroupCreated($finalGid, $name, (string)$this->userId);
			foreach ($validMemberIds as $mUid) {
				$this->auditService->auditMemberAdded($finalGid, $name, $mUid, (string)$this->userId);
			}

			// Dispatch Nextcloud core group events
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

			$isAdmin = $this->groupManager->isAdmin((string)$this->userId);
			return new JSONResponse($this->enrichGroup($created, $isAdmin), Http::STATUS_CREATED);
		} catch (Exception $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'PUT', url: '/api/v1/groups/{groupId}')]
	public function updateGroup(string $groupId, string $name, array $memberIds = [], ?string $newOwnerId = null): JSONResponse {
		if ($err = $this->checkAccess()) {
			return $err;
		}

		$existing = $this->mapper->getGroupDetails($groupId);
		if ($existing === null) {
			return new JSONResponse(['error' => $this->t('Group not found')], Http::STATUS_NOT_FOUND);
		}

		$name = trim($name);
		if ($name === '') {
			return new JSONResponse(['error' => $this->t('Group name cannot be empty')], Http::STATUS_BAD_REQUEST);
		}

		$currentUid = (string)$this->userId;
		$isAdmin = $this->groupManager->isAdmin($currentUid);
		$currentOwnerId = $existing['owner_id'] ?? $existing['creator_id'];
		$isOwner = ($currentOwnerId === $currentUid);
		$canManage = $this->canManageGroup($existing, $currentUid);
		$canModerate = $this->canModerateGroup($existing, $currentUid);

		// Permission check for modifying group
		if (!$canModerate) {
			return new JSONResponse(
				['error' => $this->t('You do not have permission to modify this group')],
				Http::STATUS_FORBIDDEN
			);
		}

		$nameChanged = ($existing['name'] !== $name);
		if ($nameChanged && !$canManage) {
			return new JSONResponse(
				['error' => $this->t('Changing group name is only allowed for creator, administrator, or manager')],
				Http::STATUS_FORBIDDEN
			);
		}

		$validMemberIds = [];
		foreach ($memberIds as $uid) {
			$uid = trim((string)$uid);
			if ($uid !== '' && $this->userManager->userExists($uid)) {
				$validMemberIds[] = $uid;
			}
		}
		$validMemberIds = array_values(array_unique($validMemberIds));

		// Ownership transfer validation
		$newOwnerId = ($newOwnerId !== null) ? trim($newOwnerId) : null;
		$transferOwnership = ($newOwnerId !== null && $newOwnerId !== '' && $newOwnerId !== $currentOwnerId);
		if ($transferOwnership) {
			if (!$isOwner && !$isAdmin) {
				return new JSONResponse(
					['error' => $this->t('Ownership transfer is only allowed for the current group owner or administrator')],
					Http::STATUS_FORBIDDEN
				);
			}

			if (!$this->userManager->userExists($newOwnerId)) {
				return new JSONResponse(
					['error' => $this->t('Specified new owner was not found in the system')],
					Http::STATUS_BAD_REQUEST
				);
			}

			if (!in_array($newOwnerId, $validMemberIds, true)) {
				return new JSONResponse(
					['error' => $this->t('New owner must be a member of the group')],
					Http::STATUS_BAD_REQUEST
				);
			}
		}

		try {
			$oldMembers = $existing['member_ids'];
			$toAdd = array_values(array_diff($validMemberIds, $oldMembers));
			$toRemove = array_values(array_diff($oldMembers, $validMemberIds));

			$finalOwnerId = $transferOwnership ? $newOwnerId : null;
			$this->mapper->updateGroup($groupId, $name, $validMemberIds, $finalOwnerId);
			$updated = $this->mapper->getGroupDetails($groupId);
			if ($updated === null) {
				return new JSONResponse(['error' => $this->t('Failed to update group')], Http::STATUS_INTERNAL_SERVER_ERROR);
			}

			// Audit logs (system audit + group activity)
			if ($nameChanged) {
				$this->auditService->auditGroupRenamed($groupId, $existing['name'], $name, $currentUid);
				$this->activityMapper->logActivity($groupId, CustomGroupActivity::ACTION_NAME_CHANGE, $currentUid, null, [
					'old_name' => $existing['name'],
					'new_name' => $name,
				]);
			}
			if ($transferOwnership) {
				$this->delegationMapper->removeDelegation($groupId, $newOwnerId);
				$this->auditService->auditOwnershipTransferred(
					$groupId,
					$existing['name'],
					$currentOwnerId,
					$newOwnerId,
					$currentUid
				);
				$this->activityMapper->logActivity($groupId, CustomGroupActivity::ACTION_OWNER_TRANSFER, $currentUid, $newOwnerId, [
					'previous_owner' => $currentOwnerId,
					'new_owner' => $newOwnerId,
				]);
			}
			foreach ($toRemove as $uid) {
				// Clean up any delegations if a user is removed from group
				$this->delegationMapper->removeDelegation($groupId, $uid);
				$this->auditService->auditMemberRemoved($groupId, $existing['name'], $uid, $currentUid);
				$this->activityMapper->logActivity($groupId, CustomGroupActivity::ACTION_MEMBER_REMOVE, $currentUid, $uid);
			}
			foreach ($toAdd as $uid) {
				$this->auditService->auditMemberAdded($groupId, $existing['name'], $uid, $currentUid);
				$this->activityMapper->logActivity($groupId, CustomGroupActivity::ACTION_MEMBER_ADD, $currentUid, $uid);
			}

			// Dispatch Nextcloud core group events
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
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/groups/{groupId}/transfer-ownership')]
	public function transferOwnership(string $groupId, string $newOwnerId): JSONResponse {
		if ($err = $this->checkAccess()) {
			return $err;
		}

		$existing = $this->mapper->getGroupDetails($groupId);
		if ($existing === null) {
			return new JSONResponse(['error' => $this->t('Group not found')], Http::STATUS_NOT_FOUND);
		}

		$currentUid = (string)$this->userId;
		$isAdmin = $this->groupManager->isAdmin($currentUid);
		$currentOwnerId = $existing['owner_id'] ?? $existing['creator_id'];
		$isOwner = ($currentOwnerId === $currentUid);

		if (!$isOwner && !$isAdmin) {
			return new JSONResponse(
				['error' => $this->t('Ownership transfer is only allowed for the current group owner or administrator')],
				Http::STATUS_FORBIDDEN
			);
		}

		$newOwnerId = trim($newOwnerId);
		if ($newOwnerId === '' || !$this->userManager->userExists($newOwnerId)) {
			return new JSONResponse(['error' => $this->t('Specified new owner was not found in the system')], Http::STATUS_BAD_REQUEST);
		}

		if (!$this->mapper->isMember($groupId, $newOwnerId)) {
			return new JSONResponse(['error' => $this->t('New owner must be an active member of the group')], Http::STATUS_BAD_REQUEST);
		}

		if ($newOwnerId === $currentOwnerId) {
			return new JSONResponse(['error' => $this->t('User is already the owner of this group')], Http::STATUS_BAD_REQUEST);
		}

		try {
			$this->mapper->transferOwnership($groupId, $newOwnerId);
			$this->delegationMapper->removeDelegation($groupId, $newOwnerId);
			$this->auditService->auditOwnershipTransferred(
				$groupId,
				$existing['name'],
				$currentOwnerId,
				$newOwnerId,
				$currentUid
			);
			$this->activityMapper->logActivity($groupId, CustomGroupActivity::ACTION_OWNER_TRANSFER, $currentUid, $newOwnerId, [
				'previous_owner' => $currentOwnerId,
				'new_owner' => $newOwnerId,
			]);

			$updated = $this->mapper->getGroupDetails($groupId);
			return new JSONResponse([
				'success' => true,
				'group' => $updated ? $this->enrichGroup($updated, $isAdmin) : null,
			]);
		} catch (Exception $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'DELETE', url: '/api/v1/groups/{groupId}')]
	public function deleteGroup(string $groupId): JSONResponse {
		if ($err = $this->checkAccess()) {
			return $err;
		}

		$existing = $this->mapper->getGroupDetails($groupId);
		if ($existing === null) {
			return new JSONResponse(['error' => $this->t('Group not found')], Http::STATUS_NOT_FOUND);
		}

		$currentUid = (string)$this->userId;
		// Only creator, admin, or manage level can delete group
		if (!$this->canManageGroup($existing, $currentUid)) {
			return new JSONResponse(
				['error' => $this->t('Deleting group is only allowed for creator, administrator, or manager')],
				Http::STATUS_FORBIDDEN
			);
		}

		try {
			// Audit log before deletion
			$this->auditService->auditGroupDeleted($groupId, $existing['name'], $currentUid);

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

	/**
	 * @return list<array{id: string, uid: string, user: string, displayName: string, subname: string, email: string}>
	 */
	private function findUsers(string $search, int $limit): array {
		$search = trim($search);
		$limit = min(max(1, $limit), 10000);

		$matchedUsers = [];

		if ($search === '') {
			$users = $this->userManager->search('', $limit);
			foreach ($users ?: [] as $user) {
				if ($user instanceof IUser && $user->isEnabled()) {
					$matchedUsers[$user->getUID()] = $user;
				}
			}
		} else {
			// 1. Search by UID
			$byUid = $this->userManager->search($search, $limit);
			foreach ($byUid ?: [] as $user) {
				if ($user instanceof IUser && $user->isEnabled()) {
					$matchedUsers[$user->getUID()] = $user;
				}
			}

			// 2. Search by Display Name
			if (count($matchedUsers) < $limit) {
				$byDisplayName = $this->userManager->searchDisplayName($search, $limit);
				foreach ($byDisplayName ?: [] as $user) {
					if ($user instanceof IUser && $user->isEnabled()) {
						$matchedUsers[$user->getUID()] = $user;
					}
				}
			}

			// 3. Search by Email if query contains @
			if (count($matchedUsers) < $limit && str_contains($search, '@')) {
				$byEmail = $this->userManager->getByEmail($search);
				foreach ($byEmail ?: [] as $user) {
					if ($user instanceof IUser && $user->isEnabled()) {
						$matchedUsers[$user->getUID()] = $user;
					}
				}
			}
		}

		$result = [];
		foreach ($matchedUsers as $user) {
			$uid = $user->getUID();
			$displayName = $user->getDisplayName() ?: $uid;
			$email = $user->getEMailAddress() ?: '';
			$subname = $email !== '' ? $email : ($displayName !== $uid ? '@' . $uid : '');

			$result[] = [
				'id' => $uid,
				'uid' => $uid,
				'user' => $uid,
				'displayName' => $displayName,
				'subname' => $subname,
				'email' => $email,
			];
			if (count($result) >= $limit) {
				break;
			}
		}

		return $result;
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/users')]
	public function searchUsers(string $search = '', int $limit = 5000): JSONResponse {
		if ($err = $this->checkAccess()) {
			return $err;
		}

		return new JSONResponse(['users' => $this->findUsers($search, $limit)]);
	}

	// ==========================================
	// DELEGATION ENDPOINTS
	// ==========================================

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/groups/{groupId}/delegations')]
	public function getDelegations(string $groupId): JSONResponse {
		if ($err = $this->checkAccess()) {
			return $err;
		}

		$group = $this->mapper->getGroupDetails($groupId);
		if ($group === null) {
			return new JSONResponse(['error' => $this->t('Group not found')], Http::STATUS_NOT_FOUND);
		}

		$delegations = $this->fetchEnrichedDelegations($groupId);
		return new JSONResponse(['delegations' => $delegations]);
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/groups/{groupId}/delegations')]
	public function addOrUpdateDelegation(string $groupId, string $userId, string $level): JSONResponse {
		if ($err = $this->checkAccess()) {
			return $err;
		}

		$group = $this->mapper->getGroupDetails($groupId);
		if ($group === null) {
			return new JSONResponse(['error' => $this->t('Group not found')], Http::STATUS_NOT_FOUND);
		}

		$currentUid = (string)$this->userId;
		if (!$this->canManageDelegations($group, $currentUid)) {
			return new JSONResponse(
				['error' => $this->t('Delegation is only allowed for group owner, manager, or administrator')],
				Http::STATUS_FORBIDDEN
			);
		}

		$ownerId = $group['owner_id'] ?? $group['creator_id'];
		$isOwner = ($ownerId === $currentUid);
		$isAdmin = $this->groupManager->isAdmin($currentUid);
		$userDelegationLevel = $this->getDelegationLevel($groupId, $currentUid);
		$isManagerOnly = (!$isOwner && !$isAdmin && $userDelegationLevel === CustomGroupDelegation::LEVEL_MANAGE);

		// If user is a manager (not owner/admin), they can only assign LEVEL_MODERATE
		if ($isManagerOnly) {
			if ($level !== CustomGroupDelegation::LEVEL_MODERATE) {
				return new JSONResponse(
					['error' => $this->t('Manager can only assign rights with Moderator role')],
					Http::STATUS_FORBIDDEN
				);
			}

			$existing = $this->delegationMapper->getDelegation($groupId, $userId);
			if ($existing !== null && $existing->getLevel() === CustomGroupDelegation::LEVEL_MANAGE) {
				return new JSONResponse(
					['error' => $this->t('Manager cannot change rights of other managers')],
					Http::STATUS_FORBIDDEN
				);
			}
		}

		// Strictly only active members of this group can be delegates
		if (!$this->mapper->isMember($groupId, $userId)) {
			return new JSONResponse(
				['error' => $this->t('Delegation is only allowed for active group members')],
				Http::STATUS_BAD_REQUEST
			);
		}

		// Owner cannot be assigned a delegation
		$currentOwnerId = $group['owner_id'] ?? $group['creator_id'];
		if ($userId === $currentOwnerId) {
			return new JSONResponse(
				['error' => $this->t('Group owner already has all management rights')],
				Http::STATUS_BAD_REQUEST
			);
		}

		if (!in_array($level, [CustomGroupDelegation::LEVEL_MANAGE, CustomGroupDelegation::LEVEL_MODERATE], true)) {
			return new JSONResponse(
				['error' => $this->t('Invalid delegation level')],
				Http::STATUS_BAD_REQUEST
			);
		}

		$existing = $this->delegationMapper->getDelegation($groupId, $userId);
		$oldLevel = $existing ? $existing->getLevel() : null;

		$delegation = $this->delegationMapper->setDelegation($groupId, $userId, $level);

		// Audit logging
		if ($oldLevel === null) {
			$this->auditService->auditDelegationAssigned($groupId, $group['name'], $userId, $level, $currentUid);
		} elseif ($oldLevel !== $level) {
			$this->auditService->auditDelegationChanged($groupId, $group['name'], $userId, $oldLevel, $level, $currentUid);
		}
		$this->activityMapper->logActivity($groupId, CustomGroupActivity::ACTION_DELEGATION_ASSIGN, $currentUid, $userId, [
			'level' => $level,
			'old_level' => $oldLevel,
		]);

		$user = $this->userManager->get($userId);
		return new JSONResponse([
			'id' => $delegation->getId(),
			'group_id' => $groupId,
			'user_id' => $userId,
			'displayName' => $user ? $user->getDisplayName() : $userId,
			'email' => ($user && $user->getEMailAddress()) ? $user->getEMailAddress() : '',
			'level' => $delegation->getLevel(),
			'created_at' => $delegation->getCreatedAt()?->format(DateTime::ATOM),
		]);
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'DELETE', url: '/api/v1/groups/{groupId}/delegations/{userId}')]
	public function revokeDelegation(string $groupId, string $userId): JSONResponse {
		if ($err = $this->checkAccess()) {
			return $err;
		}

		$group = $this->mapper->getGroupDetails($groupId);
		if ($group === null) {
			return new JSONResponse(['error' => $this->t('Group not found')], Http::STATUS_NOT_FOUND);
		}

		$currentUid = (string)$this->userId;
		if (!$this->canManageDelegations($group, $currentUid)) {
			return new JSONResponse(
				['error' => $this->t('Revoking rights is only allowed for group owner, manager, or administrator')],
				Http::STATUS_FORBIDDEN
			);
		}

		$ownerId = $group['owner_id'] ?? $group['creator_id'];
		$isOwner = ($ownerId === $currentUid);
		$isAdmin = $this->groupManager->isAdmin($currentUid);
		$userDelegationLevel = $this->getDelegationLevel($groupId, $currentUid);
		$isManagerOnly = (!$isOwner && !$isAdmin && $userDelegationLevel === CustomGroupDelegation::LEVEL_MANAGE);

		$existing = $this->delegationMapper->getDelegation($groupId, $userId);
		if ($existing !== null) {
			if ($isManagerOnly && $existing->getLevel() !== CustomGroupDelegation::LEVEL_MODERATE) {
				return new JSONResponse(
					['error' => $this->t('Manager can only revoke rights from moderators')],
					Http::STATUS_FORBIDDEN
				);
			}

			$this->delegationMapper->revokeDelegation($groupId, $userId);
			$this->auditService->auditDelegationRevoked($groupId, $group['name'], $userId, $existing->getLevel(), $currentUid);
			$this->activityMapper->logActivity($groupId, CustomGroupActivity::ACTION_DELEGATION_REVOKE, $currentUid, $userId, [
				'level' => $existing->getLevel(),
			]);
		}

		return new JSONResponse(['success' => true]);
	}

	// ==========================================
	// REQUESTS ENDPOINTS
	// ==========================================

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/groups/{groupId}/requests')]
	public function getRequests(string $groupId, ?string $status = null): JSONResponse {
		if ($err = $this->checkAccess()) {
			return $err;
		}

		$group = $this->mapper->getGroupDetails($groupId);
		if ($group === null) {
			return new JSONResponse(['error' => $this->t('Group not found')], Http::STATUS_NOT_FOUND);
		}

		$currentUid = (string)$this->userId;
		if (!$this->canModerateGroup($group, $currentUid)) {
			return new JSONResponse(
				['error' => $this->t('Viewing requests is only allowed for moderators, managers, or administrator')],
				Http::STATUS_FORBIDDEN
			);
		}

		$rawRequests = $this->requestMapper->getRequests($groupId, $status);
		$requests = array_map(fn($r) => $this->enrichRequest($r), $rawRequests);

		return new JSONResponse(['requests' => $requests]);
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/groups/{groupId}/requests')]
	public function submitRequest(string $groupId, string $candidateId): JSONResponse {
		if ($err = $this->checkAccess()) {
			return $err;
		}

		$group = $this->mapper->getGroupDetails($groupId);
		if ($group === null) {
			return new JSONResponse(['error' => $this->t('Group not found')], Http::STATUS_NOT_FOUND);
		}

		$candidateId = trim($candidateId);
		if ($candidateId === '' || !$this->userManager->userExists($candidateId)) {
			return new JSONResponse(['error' => $this->t('Specified user was not found')], Http::STATUS_BAD_REQUEST);
		}

		$currentUid = (string)$this->userId;
		if (!$this->canRequestMember($group, $currentUid)) {
			return new JSONResponse(
				['error' => $this->t('Suggesting members is only allowed for active group members, creator, or administrator')],
				Http::STATUS_FORBIDDEN
			);
		}

		if ($this->mapper->isMember($groupId, $candidateId)) {
			return new JSONResponse(['error' => $this->t('User is already a member of the group')], Http::STATUS_BAD_REQUEST);
		}

		if ($this->requestMapper->hasActiveRequest($groupId, $candidateId)) {
			return new JSONResponse(['error' => $this->t('A request to add this user is already pending')], Http::STATUS_BAD_REQUEST);
		}

		// If requester has Moderate/Manage permission or is Creator/Admin, auto-add directly!
		if ($this->canModerateGroup($group, $currentUid)) {
			$this->mapper->addToGroup($groupId, $candidateId);
			$ncGroup = $this->groupManager->get($groupId);
			if ($ncGroup instanceof IGroup) {
				$candUser = $this->userManager->get($candidateId);
				if ($candUser instanceof IUser) {
					$this->eventDispatcher->dispatchTyped(new UserAddedEvent($ncGroup, $candUser));
				}
			}
			$this->auditService->auditMemberAdded($groupId, $group['name'], $candidateId, $currentUid);
			$this->activityMapper->logActivity($groupId, CustomGroupActivity::ACTION_MEMBER_ADD, $currentUid, $candidateId);
			return new JSONResponse(['success' => true, 'added_directly' => true], Http::STATUS_CREATED);
		}

		$request = $this->requestMapper->createRequest($groupId, $candidateId, $currentUid);
		$this->auditService->auditRequestCreated($groupId, $group['name'], $candidateId, $currentUid);

		return new JSONResponse([
			'success' => true,
			'added_directly' => false,
			'request' => $this->enrichRequest($request),
		], Http::STATUS_CREATED);
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/groups/{groupId}/requests/{requestId}/approve')]
	public function approveRequest(string $groupId, int $requestId): JSONResponse {
		if ($err = $this->checkAccess()) {
			return $err;
		}

		$group = $this->mapper->getGroupDetails($groupId);
		if ($group === null) {
			return new JSONResponse(['error' => $this->t('Group not found')], Http::STATUS_NOT_FOUND);
		}

		$currentUid = (string)$this->userId;
		if (!$this->canModerateGroup($group, $currentUid)) {
			return new JSONResponse(
				['error' => $this->t('Approving requests is only allowed for moderators, managers, or administrator')],
				Http::STATUS_FORBIDDEN
			);
		}

		$request = $this->requestMapper->getRequest($requestId);
		if ($request === null || $request->getGroupId() !== $groupId) {
			return new JSONResponse(['error' => $this->t('Request not found')], Http::STATUS_NOT_FOUND);
		}

		if ($request->getStatus() !== CustomGroupRequest::STATUS_PENDING) {
			return new JSONResponse(['error' => $this->t('Request has already been processed')], Http::STATUS_BAD_REQUEST);
		}

		$candidateId = (string)$request->getCandidateId();
		$this->mapper->addToGroup($groupId, $candidateId);

		// Dispatch core event
		$ncGroup = $this->groupManager->get($groupId);
		if ($ncGroup instanceof IGroup) {
			$candUser = $this->userManager->get($candidateId);
			if ($candUser instanceof IUser) {
				$this->eventDispatcher->dispatchTyped(new UserAddedEvent($ncGroup, $candUser));
			}
		}

		$updated = $this->requestMapper->updateStatus($requestId, CustomGroupRequest::STATUS_APPROVED, $currentUid);

		// Audit logging
		$this->auditService->auditRequestApproved($groupId, $group['name'], $candidateId, $currentUid);
		$this->auditService->auditMemberAdded($groupId, $group['name'], $candidateId, $currentUid);
		$this->activityMapper->logActivity($groupId, CustomGroupActivity::ACTION_MEMBER_ADD, $currentUid, $candidateId, ['via_request' => true]);

		return new JSONResponse([
			'success' => true,
			'request' => $this->enrichRequest($updated),
		]);
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/groups/{groupId}/requests/{requestId}/reject')]
	public function rejectRequest(string $groupId, int $requestId): JSONResponse {
		if ($err = $this->checkAccess()) {
			return $err;
		}

		$group = $this->mapper->getGroupDetails($groupId);
		if ($group === null) {
			return new JSONResponse(['error' => $this->t('Group not found')], Http::STATUS_NOT_FOUND);
		}

		$currentUid = (string)$this->userId;
		if (!$this->canModerateGroup($group, $currentUid)) {
			return new JSONResponse(
				['error' => $this->t('Rejecting requests is only allowed for moderators, managers, or administrator')],
				Http::STATUS_FORBIDDEN
			);
		}

		$request = $this->requestMapper->getRequest($requestId);
		if ($request === null || $request->getGroupId() !== $groupId) {
			return new JSONResponse(['error' => $this->t('Request not found')], Http::STATUS_NOT_FOUND);
		}

		if ($request->getStatus() !== CustomGroupRequest::STATUS_PENDING) {
			return new JSONResponse(['error' => $this->t('Request has already been processed')], Http::STATUS_BAD_REQUEST);
		}

		$candidateId = (string)$request->getCandidateId();
		$updated = $this->requestMapper->updateStatus($requestId, CustomGroupRequest::STATUS_REJECTED, $currentUid);

		// Audit logging
		$this->auditService->auditRequestRejected($groupId, $group['name'], $candidateId, $currentUid);

		return new JSONResponse([
			'success' => true,
			'request' => $this->enrichRequest($updated),
		]);
	}

	// ==========================================
	// ADMIN SETTINGS ENDPOINTS
	// ==========================================

	#[FrontpageRoute(verb: 'GET', url: '/api/v1/admin/settings')]
	public function getAdminSettings(): JSONResponse {
		if ($err = $this->checkAdmin()) {
			return $err;
		}

		$rawSettings = $this->settingsService->getAllSettings();

		$enrichUsers = function (array $uids): array {
			$res = [];
			foreach ($uids as $uid) {
				$user = $this->userManager->get($uid);
				$res[] = [
					'id' => $uid,
					'uid' => $uid,
					'displayName' => $user ? $user->getDisplayName() : $uid,
					'email' => ($user && $user->getEMailAddress()) ? $user->getEMailAddress() : '',
				];
			}
			return $res;
		};

		$enrichGroups = function (array $gids): array {
			$res = [];
			foreach ($gids as $gid) {
				$group = $this->groupManager->get($gid);
				$res[] = [
					'id' => $gid,
					'name' => $group ? $group->getDisplayName() : $gid,
					'is_cug' => str_starts_with($gid, 'cug_'),
				];
			}
			return $res;
		};

		$response = new JSONResponse([
			'settings' => $rawSettings,
			'create_allowed_users_details' => $enrichUsers($rawSettings['create_allowed_users']),
			'create_allowed_groups_details' => $enrichGroups($rawSettings['create_allowed_groups']),
			'access_allowed_users_details' => $enrichUsers($rawSettings['access_allowed_users'] ?? []),
			'access_allowed_groups_details' => $enrichGroups($rawSettings['access_allowed_groups'] ?? []),
			'access_forbidden_users_details' => $enrichUsers($rawSettings['access_forbidden_users']),
			'access_forbidden_groups_details' => $enrichGroups($rawSettings['access_forbidden_groups']),
		]);
		$response->addHeader('Cache-Control', 'no-cache, no-store, must-revalidate');
		$response->addHeader('Pragma', 'no-cache');
		$response->addHeader('Expires', '0');
		return $response;
	}

	#[FrontpageRoute(verb: 'POST', url: '/api/v1/admin/settings')]
	public function saveAdminSettings(
		bool $create_restriction_enabled = false,
		array $create_allowed_users = [],
		array $create_allowed_groups = [],
		bool $access_restriction_enabled = false,
		array $access_allowed_users = [],
		array $access_allowed_groups = [],
		array $access_forbidden_users = [],
		array $access_forbidden_groups = []
	): JSONResponse {
		if ($err = $this->checkAdmin()) {
			return $err;
		}

		$params = [
			'create_restriction_enabled' => $create_restriction_enabled,
			'create_allowed_users' => $create_allowed_users,
			'create_allowed_groups' => $create_allowed_groups,
			'access_restriction_enabled' => $access_restriction_enabled,
			'access_allowed_users' => $access_allowed_users,
			'access_allowed_groups' => $access_allowed_groups,
			'access_forbidden_users' => $access_forbidden_users,
			'access_forbidden_groups' => $access_forbidden_groups,
		];

		$rawInput = file_get_contents('php://input');
		if ($rawInput !== '' && $rawInput !== false) {
			$jsonInput = json_decode($rawInput, true);
			if (is_array($jsonInput)) {
				foreach ($params as $k => $v) {
					if (array_key_exists($k, $jsonInput)) {
						$params[$k] = $jsonInput[$k];
					}
				}
			}
		}

		$requestParams = $this->request->getParams();
		if (is_array($requestParams)) {
			foreach ($params as $k => $v) {
				if (array_key_exists($k, $requestParams)) {
					$params[$k] = $requestParams[$k];
				}
			}
		}

		$this->settingsService->saveSettings($params);

		return $this->getAdminSettings();
	}

	#[FrontpageRoute(verb: 'GET', url: '/api/v1/admin/groups-list')]
	public function getAdminGroupsList(string $search = '', int $limit = 5000): JSONResponse {
		if ($err = $this->checkAdmin()) {
			return $err;
		}

		$search = trim($search);
		$limit = min(max(1, $limit), 10000);
		$groups = $this->groupManager->search($search, $limit);
		$result = [];
		foreach ($groups ?: [] as $group) {
			$result[] = [
				'id' => $group->getGID(),
				'name' => $group->getDisplayName(),
				'is_cug' => str_starts_with($group->getGID(), 'cug_'),
			];
		}

		return new JSONResponse(['groups' => $result]);
	}

	#[FrontpageRoute(verb: 'GET', url: '/api/v1/admin/users-search')]
	public function searchAdminUsers(string $search = '', int $limit = 5000): JSONResponse {
		if ($err = $this->checkAdmin()) {
			return $err;
		}

		return new JSONResponse(['users' => $this->findUsers($search, $limit)]);
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/groups/{groupId}/activities')]
	public function getActivities(string $groupId): JSONResponse {
		if ($err = $this->checkAccess()) {
			return $err;
		}

		$group = $this->mapper->getGroupDetails($groupId);
		if ($group === null) {
			return new JSONResponse(['error' => $this->t('Group not found')], Http::STATUS_NOT_FOUND);
		}

		$currentUid = (string)$this->userId;
		$ownerId = $group['owner_id'] ?? $group['creator_id'];
		$isOwner = ($ownerId === $currentUid);
		$isAdmin = $this->groupManager->isAdmin($currentUid);
		$delegationLevel = $this->getDelegationLevel($groupId, $currentUid);

		if (!$isOwner && !$isAdmin && $delegationLevel !== CustomGroupDelegation::LEVEL_MANAGE) {
			return new JSONResponse(
				['error' => $this->t('Viewing activity log is only allowed for group owner, manager, or administrator')],
				Http::STATUS_FORBIDDEN
			);
		}

		$activities = $this->activityMapper->getActivities($groupId, 200);
		return new JSONResponse(['activities' => $activities]);
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/groups/{groupId}')]
	public function getGroup(string $groupId): JSONResponse {
		if ($err = $this->checkAccess()) {
			return $err;
		}

		$currentUid = (string)$this->userId;
		$isAdmin = $this->groupManager->isAdmin($currentUid);

		$group = $this->mapper->getGroupDetails($groupId);
		if ($group === null) {
			return new JSONResponse(['error' => $this->t('Group not found')], Http::STATUS_NOT_FOUND);
		}

		$ownerId = $group['owner_id'] ?? $group['creator_id'];
		$isMember = $this->mapper->isMember($groupId, $currentUid);
		$isOwner = ($ownerId === $currentUid);

		if (!$isAdmin && !$isOwner && !$isMember) {
			return new JSONResponse(['error' => $this->t('Access denied')], Http::STATUS_FORBIDDEN);
		}

		return new JSONResponse(['group' => $this->enrichGroup($group, $isAdmin)]);
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/groups/{groupId}/shares')]
	public function getGroupShares(string $groupId): JSONResponse {
		if ($err = $this->checkAccess()) {
			return $err;
		}

		$group = $this->mapper->getGroupDetails($groupId);
		if ($group === null) {
			return new JSONResponse(['error' => $this->t('Group not found')], Http::STATUS_NOT_FOUND);
		}

		$currentUid = (string)$this->userId;
		if (!$this->canManageGroupShares($group, $currentUid)) {
			return new JSONResponse(
				['error' => $this->t('Viewing group resources is only allowed for group owner, manager, or administrator')],
				Http::STATUS_FORBIDDEN
			);
		}

		$rawShares = $this->mapper->getGroupShares($groupId);
		$shares = [];
		foreach ($rawShares as $s) {
			$ownerUser = $this->userManager->get($s['uid_owner']);
			$ownerDisplayName = $ownerUser instanceof IUser ? $ownerUser->getDisplayName() : $s['uid_owner'];
			$ownerEmail = $ownerUser instanceof IUser ? ($ownerUser->getEMailAddress() ?: '') : '';

			$initiatorUser = $this->userManager->get($s['uid_initiator']);
			$initiatorDisplayName = $initiatorUser instanceof IUser ? $initiatorUser->getDisplayName() : $s['uid_initiator'];
			$initiatorEmail = $initiatorUser instanceof IUser ? ($initiatorUser->getEMailAddress() ?: '') : '';

			$cleanPath = $s['file_path'] ?? $s['file_target'];
			if ($cleanPath !== null && str_starts_with($cleanPath, 'files/')) {
				$cleanPath = substr($cleanPath, 6);
			}

			$name = $s['file_name'] ?: basename($s['file_target'] ?: 'resource');

			$shares[] = [
				'id' => $s['id'],
				'item_type' => $s['item_type'],
				'name' => $name,
				'path' => $cleanPath ?: $name,
				'file_source' => $s['file_source'],
				'permissions' => $s['permissions'],
				'stime' => $s['stime'],
				'created_at' => (new DateTime())->setTimestamp($s['stime'])->format(DateTime::ATOM),
				'uid_owner' => $s['uid_owner'],
				'owner_displayName' => $ownerDisplayName,
				'owner_email' => $ownerEmail,
				'uid_initiator' => $s['uid_initiator'],
				'initiator_displayName' => $initiatorDisplayName,
				'initiator_email' => $initiatorEmail,
			];
		}

		return new JSONResponse(['shares' => $shares]);
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'DELETE', url: '/api/v1/groups/{groupId}/shares/{shareId}')]
	public function unshareGroupShare(string $groupId, int $shareId): JSONResponse {
		if ($err = $this->checkAccess()) {
			return $err;
		}

		$group = $this->mapper->getGroupDetails($groupId);
		if ($group === null) {
			return new JSONResponse(['error' => $this->t('Group not found')], Http::STATUS_NOT_FOUND);
		}

		$currentUid = (string)$this->userId;
		if (!$this->canManageGroupShares($group, $currentUid)) {
			return new JSONResponse(
				['error' => $this->t('Revoking resource access is only allowed for group owner, manager, or administrator')],
				Http::STATUS_FORBIDDEN
			);
		}

		try {
			$share = $this->resolveShare($shareId);
		} catch (ShareNotFound|Exception) {
			return new JSONResponse(['error' => $this->t('Shared resource not found')], Http::STATUS_NOT_FOUND);
		}

		if ($share->getShareType() !== IShare::TYPE_GROUP || $share->getSharedWith() !== $groupId) {
			return new JSONResponse(['error' => $this->t('Specified resource is not associated with this group')], Http::STATUS_BAD_REQUEST);
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

		$ownerId = $share->getShareOwner();
		$initiatorId = $share->getSharedBy();

		try {
			$this->shareManager->deleteShare($share);
		} catch (Exception $e) {
			return new JSONResponse(['error' => $this->t('Failed to revoke access: %s', [$e->getMessage()])], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		// Log activity in group audit trail
		$this->activityMapper->logActivity(
			$groupId,
			CustomGroupActivity::ACTION_SHARE_UNSHARE,
			$currentUid,
			null,
			[
				'share_id' => $shareId,
				'item_type' => $itemType,
				'name' => $nodeName,
				'owner' => $ownerId,
				'initiator' => $initiatorId,
			]
		);

		// Log in Nextcloud system audit
		$this->auditService->auditGroupShareRevoked($groupId, $group['name'], $nodeName, $currentUid);

		return new JSONResponse(['success' => true]);
	}

	/**
	 * Resolves an IShare instance by numeric ID or composite provider ID.
	 *
	 * @throws ShareNotFound
	 */
	private function resolveShare(string|int $shareId): IShare {
		$idStr = (string)$shareId;
		if (str_contains($idStr, ':')) {
			return $this->shareManager->getShareById($idStr);
		}

		$providers = [
			'ocinternal',
			'ocCircleShare',
			'ocMailShare',
			'ocRoomShare',
			'deck',
		];
		foreach ($providers as $prefix) {
			try {
				return $this->shareManager->getShareById($prefix . ':' . $idStr);
			} catch (ShareNotFound) {
				continue;
			} catch (Exception) {
				continue;
			}
		}

		throw new ShareNotFound();
	}

	// ==========================================
	// PERMISSION HELPERS & DATA ENRICHMENT
	// ==========================================

	private function canManageGroup(array $group, string $userId): bool {
		$ownerId = $group['owner_id'] ?? $group['creator_id'];
		if ($ownerId === $userId || $this->groupManager->isAdmin($userId)) {
			return true;
		}

		// Manage level delegate
		return $this->getDelegationLevel($group['group_id'], $userId) === CustomGroupDelegation::LEVEL_MANAGE;
	}

	private function canManageGroupShares(array $group, string $userId): bool {
		$ownerId = $group['owner_id'] ?? $group['creator_id'];
		if ($ownerId === $userId || $this->groupManager->isAdmin($userId)) {
			return true;
		}

		return $this->getDelegationLevel($group['group_id'], $userId) === CustomGroupDelegation::LEVEL_MANAGE;
	}

	private function canModerateGroup(array $group, string $userId): bool {
		if ($this->canManageGroup($group, $userId)) {
			return true;
		}

		// Moderate level delegate
		return $this->getDelegationLevel($group['group_id'], $userId) === CustomGroupDelegation::LEVEL_MODERATE;
	}

	private function canManageDelegations(array $group, string $userId): bool {
		$ownerId = $group['owner_id'] ?? $group['creator_id'];
		if ($ownerId === $userId || $this->groupManager->isAdmin($userId)) {
			return true;
		}

		return $this->getDelegationLevel($group['group_id'], $userId) === CustomGroupDelegation::LEVEL_MANAGE;
	}

	private function canRequestMember(array $group, string $userId): bool {
		$ownerId = $group['owner_id'] ?? $group['creator_id'];
		return $this->mapper->isMember($group['group_id'], $userId)
			|| ($ownerId === $userId)
			|| $this->groupManager->isAdmin($userId);
	}

	private function getDelegationLevel(string $groupId, string $userId): ?string {
		// Delegation is strictly invalid if user is not currently a member
		if (!$this->mapper->isMember($groupId, $userId)) {
			return null;
		}

		$del = $this->delegationMapper->getDelegation($groupId, $userId);
		return $del ? $del->getLevel() : null;
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function fetchEnrichedDelegations(string $groupId): array {
		$raw = $this->delegationMapper->getDelegations($groupId);
		$list = [];
		foreach ($raw as $del) {
			$uid = (string)$del->getUserId();
			// Only include if user is still a member of the group
			if (!$this->mapper->isMember($groupId, $uid)) {
				continue;
			}
			$user = $this->userManager->get($uid);
			$list[] = [
				'id' => $del->getId(),
				'group_id' => $groupId,
				'user_id' => $uid,
				'displayName' => $user ? $user->getDisplayName() : $uid,
				'email' => ($user && $user->getEMailAddress()) ? $user->getEMailAddress() : '',
				'level' => $del->getLevel(),
				'created_at' => $del->getCreatedAt()?->format(DateTime::ATOM),
			];
		}
		return $list;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function enrichRequest(CustomGroupRequest $r): array {
		$candUser = $this->userManager->get((string)$r->getCandidateId());
		$reqUser = $this->userManager->get((string)$r->getRequesterId());
		$procUser = $r->getProcessedBy() ? $this->userManager->get((string)$r->getProcessedBy()) : null;

		return [
			'id' => $r->getId(),
			'group_id' => $r->getGroupId(),
			'candidate_id' => $r->getCandidateId(),
			'candidate_displayName' => $candUser ? $candUser->getDisplayName() : $r->getCandidateId(),
			'candidate_email' => ($candUser && $candUser->getEMailAddress()) ? $candUser->getEMailAddress() : '',
			'requester_id' => $r->getRequesterId(),
			'requester_displayName' => $reqUser ? $reqUser->getDisplayName() : $r->getRequesterId(),
			'status' => $r->getStatus(),
			'created_at' => $r->getCreatedAt()?->format(DateTime::ATOM),
			'updated_at' => $r->getUpdatedAt()?->format(DateTime::ATOM),
			'processed_by' => $r->getProcessedBy(),
			'processed_by_displayName' => $procUser ? $procUser->getDisplayName() : ($r->getProcessedBy() ?? ''),
			'processed_by_email' => ($procUser && $procUser->getEMailAddress()) ? $procUser->getEMailAddress() : '',
		];
	}

	/**
	 * Enrich group record with creator display name and email, detailed members with email,
	 * permissions, delegations, and pending request count
	 *
	 * @param array{group_id: string, name: string, creator_id: string, owner_id?: string, created_at: string, member_ids: string[]} $group
	 * @return array<string, mixed>
	 */
	private function enrichGroup(array $group, bool $isAdmin): array {
		$creatorId = $group['creator_id'];
		$creatorUser = $this->userManager->get($creatorId);
		$creatorDisplayName = $creatorUser ? $creatorUser->getDisplayName() : $creatorId;
		$creatorEmail = ($creatorUser && $creatorUser->getEMailAddress()) ? $creatorUser->getEMailAddress() : '';

		$ownerId = $group['owner_id'] ?? $creatorId;
		$ownerUser = $this->userManager->get($ownerId);
		$ownerDisplayName = $ownerUser ? $ownerUser->getDisplayName() : $ownerId;
		$ownerEmail = ($ownerUser && $ownerUser->getEMailAddress()) ? $ownerUser->getEMailAddress() : '';

		$members = [];
		foreach ($group['member_ids'] as $uid) {
			$user = $this->userManager->get($uid);
			$members[] = [
				'uid' => $uid,
				'displayName' => $user ? $user->getDisplayName() : $uid,
				'email' => ($user && $user->getEMailAddress()) ? $user->getEMailAddress() : '',
			];
		}

		$delegations = $this->fetchEnrichedDelegations($group['group_id']);
		$delegatesManage = [];
		$delegatesModerate = [];
		foreach ($delegations as $d) {
			if ($d['level'] === CustomGroupDelegation::LEVEL_MANAGE) {
				$delegatesManage[] = $d;
			} else {
				$delegatesModerate[] = $d;
			}
		}

		$currentUserId = $this->userId ?? '';
		$isOwner = ($ownerId === $currentUserId);
		$isCreator = ($creatorId === $currentUserId);
		$userDelegationLevel = $this->getDelegationLevel($group['group_id'], $currentUserId);

		$canManage = $isOwner || $isAdmin || ($userDelegationLevel === CustomGroupDelegation::LEVEL_MANAGE);
		$canModerate = $canManage || ($userDelegationLevel === CustomGroupDelegation::LEVEL_MODERATE);
		$canDelegate = $isOwner || $isAdmin || ($userDelegationLevel === CustomGroupDelegation::LEVEL_MANAGE);
		$isMember = $this->mapper->isMember($group['group_id'], $currentUserId);
		$canRequest = $isMember || $isOwner || $isAdmin;
		$canViewHistory = $isOwner || $isAdmin || ($userDelegationLevel === CustomGroupDelegation::LEVEL_MANAGE);
		$canManageShares = $isOwner || $isAdmin || ($userDelegationLevel === CustomGroupDelegation::LEVEL_MANAGE);
		$pendingCount = $this->requestMapper->countPendingRequests($group['group_id']);

		return [
			'group_id' => $group['group_id'],
			'name' => $group['name'],
			'creator_id' => $creatorId,
			'creator_displayName' => $creatorDisplayName,
			'creator_email' => $creatorEmail,
			'owner_id' => $ownerId,
			'owner_displayName' => $ownerDisplayName,
			'owner_email' => $ownerEmail,
			'created_at' => $group['created_at'],
			'member_ids' => $group['member_ids'],
			'members' => $members,
			'member_count' => count($members),
			'delegations' => $delegations,
			'delegates_manage' => $delegatesManage,
			'delegates_moderate' => $delegatesModerate,
			'pending_requests_count' => $pendingCount,
			'can_edit' => $canModerate,
			'is_owner' => $isOwner,
			'is_creator' => $isCreator,
			'permissions' => [
				'can_edit_name' => $canManage,
				'can_edit_members' => $canModerate,
				'can_delete' => $canManage,
				'can_delegate' => $canDelegate,
				'can_moderate_requests' => $canModerate,
				'can_request_member' => $canRequest,
				'can_transfer_ownership' => ($isOwner || $isAdmin),
				'can_view_history' => $canViewHistory,
				'can_manage_shares' => $canManageShares,
				'delegation_level' => $userDelegationLevel,
			],
		];
	}
}
