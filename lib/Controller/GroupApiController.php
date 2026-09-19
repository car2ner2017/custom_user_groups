<?php

declare(strict_types=1);

namespace OCA\CustomUserGroups\Controller;

use DateTime;
use Exception;
use OCA\CustomUserGroups\Db\CustomGroupDelegation;
use OCA\CustomUserGroups\Db\CustomGroupDelegationMapper;
use OCA\CustomUserGroups\Db\CustomGroupMapper;
use OCA\CustomUserGroups\Db\CustomGroupRequest;
use OCA\CustomUserGroups\Db\CustomGroupRequestMapper;
use OCA\CustomUserGroups\Service\AuditService;
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
		private CustomGroupDelegationMapper $delegationMapper,
		private CustomGroupRequestMapper $requestMapper,
		private AuditService $auditService,
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
	public function createGroup(string $name, array $memberIds = []): JSONResponse {
		if ($this->userId === null) {
			return new JSONResponse(['error' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
		}

		$name = trim($name);
		if ($name === '') {
			return new JSONResponse(['error' => 'Название группы не может быть пустым'], Http::STATUS_BAD_REQUEST);
		}

		// Filter valid users in Nextcloud
		$validMemberIds = [];
		foreach ($memberIds as $uid) {
			$uid = trim((string)$uid);
			if ($uid !== '' && $this->userManager->userExists($uid)) {
				$validMemberIds[] = $uid;
			}
		}

		// Auto-generate group ID exclusively
		$cleanGid = 'cug_' . substr(md5($name . microtime()), 0, 8);
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
				return new JSONResponse(['error' => 'Не удалось создать группу'], Http::STATUS_INTERNAL_SERVER_ERROR);
			}

			// Nextcloud Audit log
			$this->auditService->auditGroupCreated($finalGid, $name, $this->userId);
			foreach ($validMemberIds as $mUid) {
				$this->auditService->auditMemberAdded($finalGid, $name, $mUid, $this->userId);
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
			return new JSONResponse(['error' => 'Группа не найдена'], Http::STATUS_NOT_FOUND);
		}

		$name = trim($name);
		if ($name === '') {
			return new JSONResponse(['error' => 'Название группы не может быть пустым'], Http::STATUS_BAD_REQUEST);
		}

		$isAdmin = $this->groupManager->isAdmin($this->userId);
		$canManage = $this->canManageGroup($existing, $this->userId);
		$canModerate = $this->canModerateGroup($existing, $this->userId);

		// Permission check for modifying group
		if (!$canModerate) {
			return new JSONResponse(
				['error' => 'У вас нет прав для изменения этой группы'],
				Http::STATUS_FORBIDDEN
			);
		}

		$nameChanged = ($existing['name'] !== $name);
		if ($nameChanged && !$canManage) {
			return new JSONResponse(
				['error' => 'Изменение названия группы доступно только создателю, администратору или управляющему'],
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

		try {
			$oldMembers = $existing['member_ids'];
			$toAdd = array_values(array_diff($validMemberIds, $oldMembers));
			$toRemove = array_values(array_diff($oldMembers, $validMemberIds));

			$this->mapper->updateGroup($groupId, $name, $validMemberIds);
			$updated = $this->mapper->getGroupDetails($groupId);
			if ($updated === null) {
				return new JSONResponse(['error' => 'Не удалось обновить группу'], Http::STATUS_INTERNAL_SERVER_ERROR);
			}

			// Audit logs
			if ($nameChanged) {
				$this->auditService->auditGroupRenamed($groupId, $existing['name'], $name, $this->userId);
			}
			foreach ($toRemove as $uid) {
				$this->auditService->auditMemberRemoved($groupId, $name, $uid, $this->userId);
			}
			foreach ($toAdd as $uid) {
				$this->auditService->auditMemberAdded($groupId, $name, $uid, $this->userId);
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
	#[FrontpageRoute(verb: 'DELETE', url: '/api/v1/groups/{groupId}')]
	public function deleteGroup(string $groupId): JSONResponse {
		if ($this->userId === null) {
			return new JSONResponse(['error' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
		}

		$existing = $this->mapper->getGroupDetails($groupId);
		if ($existing === null) {
			return new JSONResponse(['error' => 'Группа не найдена'], Http::STATUS_NOT_FOUND);
		}

		// Only creator, admin, or manage level can delete group
		if (!$this->canManageGroup($existing, $this->userId)) {
			return new JSONResponse(
				['error' => 'Удаление группы доступно только создателю, администратору или управляющему'],
				Http::STATUS_FORBIDDEN
			);
		}

		try {
			// Audit log before deletion
			$this->auditService->auditGroupDeleted($groupId, $existing['name'], $this->userId);

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
					'email' => $user->getEMailAddress() ?: '',
				];
			}
		}

		return new JSONResponse(['users' => $result]);
	}

	// ==========================================
	// DELEGATION ENDPOINTS
	// ==========================================

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/groups/{groupId}/delegations')]
	public function getDelegations(string $groupId): JSONResponse {
		if ($this->userId === null) {
			return new JSONResponse(['error' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
		}

		$group = $this->mapper->getGroupDetails($groupId);
		if ($group === null) {
			return new JSONResponse(['error' => 'Группа не найдена'], Http::STATUS_NOT_FOUND);
		}

		$delegations = $this->fetchEnrichedDelegations($groupId);
		return new JSONResponse(['delegations' => $delegations]);
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/groups/{groupId}/delegations')]
	public function addOrUpdateDelegation(string $groupId, string $userId, string $level): JSONResponse {
		if ($this->userId === null) {
			return new JSONResponse(['error' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
		}

		$group = $this->mapper->getGroupDetails($groupId);
		if ($group === null) {
			return new JSONResponse(['error' => 'Группа не найдена'], Http::STATUS_NOT_FOUND);
		}

		// Only creator or admin can manage delegations
		if (!$this->canManageDelegations($group, $this->userId)) {
			return new JSONResponse(
				['error' => 'Управление делегированием прав доступно только создателю группы или администратору'],
				Http::STATUS_FORBIDDEN
			);
		}

		$userId = trim($userId);
		if (!$this->userManager->userExists($userId)) {
			return new JSONResponse(['error' => 'Пользователь не найден в системе'], Http::STATUS_NOT_FOUND);
		}

		// User MUST be a current member of the group
		if (!$this->mapper->isMember($groupId, $userId)) {
			return new JSONResponse(
				['error' => 'Делегирование прав возможно только действующим участникам данной группы'],
				Http::STATUS_BAD_REQUEST
			);
		}

		if (!in_array($level, [CustomGroupDelegation::LEVEL_MANAGE, CustomGroupDelegation::LEVEL_MODERATE], true)) {
			return new JSONResponse(['error' => 'Недопустимый уровень делегирования'], Http::STATUS_BAD_REQUEST);
		}

		$oldDelegation = $this->delegationMapper->getDelegation($groupId, $userId);
		$delegation = $this->delegationMapper->setDelegation($groupId, $userId, $level);

		// Audit logging
		if ($oldDelegation !== null && $oldDelegation->getLevel() !== $level) {
			$this->auditService->auditDelegationChanged(
				$groupId,
				$group['name'],
				$userId,
				(string)$oldDelegation->getLevel(),
				$level,
				$this->userId
			);
		} else {
			$this->auditService->auditDelegationAssigned(
				$groupId,
				$group['name'],
				$userId,
				$level,
				$this->userId
			);
		}

		$targetUser = $this->userManager->get($userId);
		return new JSONResponse([
			'id' => $delegation->getId(),
			'group_id' => $groupId,
			'user_id' => $userId,
			'displayName' => $targetUser ? $targetUser->getDisplayName() : $userId,
			'email' => ($targetUser && $targetUser->getEMailAddress()) ? $targetUser->getEMailAddress() : '',
			'level' => $delegation->getLevel(),
			'created_at' => $delegation->getCreatedAt()?->format(DateTime::ATOM),
		]);
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'DELETE', url: '/api/v1/groups/{groupId}/delegations/{userId}')]
	public function revokeDelegation(string $groupId, string $userId): JSONResponse {
		if ($this->userId === null) {
			return new JSONResponse(['error' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
		}

		$group = $this->mapper->getGroupDetails($groupId);
		if ($group === null) {
			return new JSONResponse(['error' => 'Группа не найдена'], Http::STATUS_NOT_FOUND);
		}

		// Only creator or admin can manage delegations
		if (!$this->canManageDelegations($group, $this->userId)) {
			return new JSONResponse(
				['error' => 'Управление делегированием прав доступно только создателю группы или администратору'],
				Http::STATUS_FORBIDDEN
			);
		}

		$this->delegationMapper->removeDelegation($groupId, $userId);
		$this->auditService->auditDelegationRevoked($groupId, $group['name'], $userId, $this->userId);

		return new JSONResponse(['success' => true]);
	}

	// ==========================================
	// MEMBERSHIP REQUEST ENDPOINTS
	// ==========================================

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/groups/{groupId}/requests')]
	public function getRequests(string $groupId, ?string $status = null): JSONResponse {
		if ($this->userId === null) {
			return new JSONResponse(['error' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
		}

		$group = $this->mapper->getGroupDetails($groupId);
		if ($group === null) {
			return new JSONResponse(['error' => 'Группа не найдена'], Http::STATUS_NOT_FOUND);
		}

		if (!$this->canModerateGroup($group, $this->userId)) {
			return new JSONResponse(
				['error' => 'Просмотр заявок доступен только модераторам, управляющим или администратору'],
				Http::STATUS_FORBIDDEN
			);
		}

		$rawRequests = $this->requestMapper->getRequests($groupId, $status);
		$requests = [];
		foreach ($rawRequests as $r) {
			$candUser = $this->userManager->get((string)$r->getCandidateId());
			$reqUser = $this->userManager->get((string)$r->getRequesterId());
			$requests[] = [
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
			];
		}

		return new JSONResponse(['requests' => $requests]);
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/groups/{groupId}/requests')]
	public function submitRequest(string $groupId, string $candidateId): JSONResponse {
		if ($this->userId === null) {
			return new JSONResponse(['error' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
		}

		$group = $this->mapper->getGroupDetails($groupId);
		if ($group === null) {
			return new JSONResponse(['error' => 'Группа не найдена'], Http::STATUS_NOT_FOUND);
		}

		// Check permission to submit request (group member, creator, or admin)
		if (!$this->canRequestMember($group, $this->userId)) {
			return new JSONResponse(
				['error' => 'Отправка заявок на добавление доступна только участникам группы'],
				Http::STATUS_FORBIDDEN
			);
		}

		$candidateId = trim($candidateId);
		if (!$this->userManager->userExists($candidateId)) {
			return new JSONResponse(['error' => 'Указанный пользователь не существует в Nextcloud'], Http::STATUS_NOT_FOUND);
		}

		// Cannot request if already a member
		if ($this->mapper->isMember($groupId, $candidateId)) {
			return new JSONResponse(['error' => 'Пользователь уже является участником данной группы'], Http::STATUS_BAD_REQUEST);
		}

		// Prevent duplicate pending requests
		if ($this->requestMapper->hasPendingRequest($groupId, $candidateId)) {
			return new JSONResponse(
				['error' => 'Запрос на добавление этого пользователя уже находится на рассмотрении'],
				Http::STATUS_CONFLICT
			);
		}

		$request = $this->requestMapper->createRequest($groupId, $candidateId, $this->userId);
		$this->auditService->auditRequestSubmitted($groupId, $group['name'], $candidateId, $this->userId);

		$candUser = $this->userManager->get($candidateId);
		$reqUser = $this->userManager->get($this->userId);

		return new JSONResponse([
			'id' => $request->getId(),
			'group_id' => $groupId,
			'candidate_id' => $candidateId,
			'candidate_displayName' => $candUser ? $candUser->getDisplayName() : $candidateId,
			'candidate_email' => ($candUser && $candUser->getEMailAddress()) ? $candUser->getEMailAddress() : '',
			'requester_id' => $this->userId,
			'requester_displayName' => $reqUser ? $reqUser->getDisplayName() : $this->userId,
			'status' => $request->getStatus(),
			'created_at' => $request->getCreatedAt()?->format(DateTime::ATOM),
			'updated_at' => $request->getUpdatedAt()?->format(DateTime::ATOM),
			'processed_by' => null,
		], Http::STATUS_CREATED);
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/groups/{groupId}/requests/{requestId}/approve')]
	public function approveRequest(string $groupId, int $requestId): JSONResponse {
		if ($this->userId === null) {
			return new JSONResponse(['error' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
		}

		$group = $this->mapper->getGroupDetails($groupId);
		if ($group === null) {
			return new JSONResponse(['error' => 'Группа не найдена'], Http::STATUS_NOT_FOUND);
		}

		if (!$this->canModerateGroup($group, $this->userId)) {
			return new JSONResponse(
				['error' => 'Одобрение заявок доступно только модераторам, управляющим или администратору'],
				Http::STATUS_FORBIDDEN
			);
		}

		$request = $this->requestMapper->getRequest($requestId);
		if ($request === null || $request->getGroupId() !== $groupId) {
			return new JSONResponse(['error' => 'Заявка не найдена'], Http::STATUS_NOT_FOUND);
		}

		if ($request->getStatus() !== CustomGroupRequest::STATUS_PENDING) {
			return new JSONResponse(['error' => 'Заявка уже была обработана ранее'], Http::STATUS_BAD_REQUEST);
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

		$updated = $this->requestMapper->updateStatus($requestId, CustomGroupRequest::STATUS_APPROVED, $this->userId);

		// Audit logging
		$this->auditService->auditRequestApproved($groupId, $group['name'], $candidateId, $this->userId);
		$this->auditService->auditMemberAdded($groupId, $group['name'], $candidateId, $this->userId);

		return new JSONResponse($updated);
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/groups/{groupId}/requests/{requestId}/reject')]
	public function rejectRequest(string $groupId, int $requestId): JSONResponse {
		if ($this->userId === null) {
			return new JSONResponse(['error' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
		}

		$group = $this->mapper->getGroupDetails($groupId);
		if ($group === null) {
			return new JSONResponse(['error' => 'Группа не найдена'], Http::STATUS_NOT_FOUND);
		}

		if (!$this->canModerateGroup($group, $this->userId)) {
			return new JSONResponse(
				['error' => 'Отклонение заявок доступно только модераторам, управляющим или администратору'],
				Http::STATUS_FORBIDDEN
			);
		}

		$request = $this->requestMapper->getRequest($requestId);
		if ($request === null || $request->getGroupId() !== $groupId) {
			return new JSONResponse(['error' => 'Заявка не найдена'], Http::STATUS_NOT_FOUND);
		}

		if ($request->getStatus() !== CustomGroupRequest::STATUS_PENDING) {
			return new JSONResponse(['error' => 'Заявка уже была обработана ранее'], Http::STATUS_BAD_REQUEST);
		}

		$candidateId = (string)$request->getCandidateId();
		$updated = $this->requestMapper->updateStatus($requestId, CustomGroupRequest::STATUS_REJECTED, $this->userId);

		// Audit logging
		$this->auditService->auditRequestRejected($groupId, $group['name'], $candidateId, $this->userId);

		return new JSONResponse($updated);
	}

	// ==========================================
	// PERMISSION HELPERS & DATA ENRICHMENT
	// ==========================================

	private function canManageGroup(array $group, string $userId): bool {
		if ($group['creator_id'] === $userId || $this->groupManager->isAdmin($userId)) {
			return true;
		}

		// Manage level delegate
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
		return ($group['creator_id'] === $userId) || $this->groupManager->isAdmin($userId);
	}

	private function canRequestMember(array $group, string $userId): bool {
		return $this->mapper->isMember($group['group_id'], $userId)
			|| ($group['creator_id'] === $userId)
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
	 * Enrich group record with creator display name and email, detailed members with email,
	 * permissions, delegations, and pending request count
	 *
	 * @param array{group_id: string, name: string, creator_id: string, created_at: string, member_ids: string[]} $group
	 * @return array<string, mixed>
	 */
	private function enrichGroup(array $group, bool $isAdmin): array {
		$creatorUser = $this->userManager->get($group['creator_id']);
		$creatorDisplayName = $creatorUser ? $creatorUser->getDisplayName() : $group['creator_id'];
		$creatorEmail = ($creatorUser && $creatorUser->getEMailAddress()) ? $creatorUser->getEMailAddress() : '';

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
		$isOwner = ($group['creator_id'] === $currentUserId);
		$userDelegationLevel = $this->getDelegationLevel($group['group_id'], $currentUserId);

		$canManage = $isOwner || $isAdmin || ($userDelegationLevel === CustomGroupDelegation::LEVEL_MANAGE);
		$canModerate = $canManage || ($userDelegationLevel === CustomGroupDelegation::LEVEL_MODERATE);
		$canDelegate = $isOwner || $isAdmin;
		$isMember = $this->mapper->isMember($group['group_id'], $currentUserId);
		$canRequest = $isMember || $isOwner || $isAdmin;
		$pendingCount = $this->requestMapper->countPendingRequests($group['group_id']);

		return [
			'group_id' => $group['group_id'],
			'name' => $group['name'],
			'creator_id' => $group['creator_id'],
			'creator_displayName' => $creatorDisplayName,
			'creator_email' => $creatorEmail,
			'created_at' => $group['created_at'],
			'member_ids' => $group['member_ids'],
			'members' => $members,
			'member_count' => count($members),
			'delegations' => $delegations,
			'delegates_manage' => $delegatesManage,
			'delegates_moderate' => $delegatesModerate,
			'pending_requests_count' => $pendingCount,
			'can_edit' => $canModerate,
			'is_creator' => $isOwner,
			'permissions' => [
				'can_edit_name' => $canManage,
				'can_edit_members' => $canModerate,
				'can_delete' => $canManage,
				'can_delegate' => $canDelegate,
				'can_moderate_requests' => $canModerate,
				'can_request_member' => $canRequest,
				'delegation_level' => $userDelegationLevel,
			],
		];
	}
}
