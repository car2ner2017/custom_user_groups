<?php

declare(strict_types=1);

namespace OCA\UserGroupsHzs\Controller;

use DateTime;
use OCA\UserGroupsHzs\AppInfo\Application;
use OCA\UserGroupsHzs\Db\CustomGroupDelegation;
use OCA\UserGroupsHzs\Db\CustomGroupDelegationMapper;
use OCA\UserGroupsHzs\Db\CustomGroupMapper;
use OCA\UserGroupsHzs\Db\CustomGroupRequestMapper;
use OCA\UserGroupsHzs\Service\SettingsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserManager;

/**
 * @psalm-suppress UnusedClass
 */
class PageController extends Controller {

	public function __construct(
		string $appName,
		IRequest $request,
		private IInitialState $initialStateService,
		private IGroupManager $groupManager,
		private CustomGroupMapper $mapper,
		private CustomGroupDelegationMapper $delegationMapper,
		private CustomGroupRequestMapper $requestMapper,
		private IUserManager $userManager,
		private SettingsService $settingsService,
		private ?string $userId,
	) {
		parent::__construct($appName, $request);
	}

	#[NoCSRFRequired]
	#[NoAdminRequired]
	#[OpenAPI(OpenAPI::SCOPE_IGNORE)]
	#[FrontpageRoute(verb: 'GET', url: '/')]
	public function index(): TemplateResponse {
		if ($this->userId !== null && !$this->settingsService->isUserAccessAllowed($this->userId)) {
			$response = new TemplateResponse(
				Application::APP_ID,
				'forbidden',
				[],
				TemplateResponse::RENDER_AS_USER
			);
			$response->setStatus(Http::STATUS_FORBIDDEN);
			return $response;
		}

		$isAdmin = ($this->userId !== null) && $this->groupManager->isAdmin($this->userId);
		$canCreateGroups = $this->settingsService->canUserCreateGroups($this->userId);
		$rawGroups = ($this->userId !== null) ? $this->mapper->getAllGroups($this->userId, $isAdmin) : [];

		$groups = [];
		foreach ($rawGroups as $g) {
			$creatorUser = $this->userManager->get($g['creator_id']);
			$ownerId = $g['owner_id'] ?? $g['creator_id'];
			$ownerUser = $this->userManager->get($ownerId);
			$members = [];
			foreach ($g['member_ids'] as $uid) {
				$user = $this->userManager->get($uid);
				$members[] = [
					'uid' => $uid,
					'displayName' => $user ? $user->getDisplayName() : $uid,
				];
			}

			$isOwner = ($ownerId === $this->userId);
			$isCreator = ($g['creator_id'] === $this->userId);
			$currentUid = (string)($this->userId ?? '');
			$delegation = $this->delegationMapper->getDelegation($g['group_id'], $currentUid);
			$isMember = in_array($currentUid, $g['member_ids'], true);
			$userDelegationLevel = ($isMember && $delegation) ? $delegation->getLevel() : null;

			$canManage = $isOwner || $isAdmin || ($userDelegationLevel === 'manage');
			$canModerate = $canManage || ($userDelegationLevel === 'moderate');
			$canDelegate = $isOwner || $isAdmin || ($userDelegationLevel === 'manage');
			$canRequest = $isMember || $isOwner || $isAdmin;
			$canViewHistory = $isOwner || $isAdmin || ($userDelegationLevel === 'manage');
			$canManageShares = $isOwner || $isAdmin || ($userDelegationLevel === 'manage');

			$rawDelegations = $this->delegationMapper->getDelegations($g['group_id']);
			$delegations = [];
			$delegatesManage = [];
			$delegatesModerate = [];
			foreach ($rawDelegations as $del) {
				$delUid = (string)$del->getUserId();
				if (!in_array($delUid, $g['member_ids'], true)) {
					continue;
				}
				$delUser = $this->userManager->get($delUid);
				$delData = [
					'id' => $del->getId(),
					'group_id' => $g['group_id'],
					'user_id' => $delUid,
					'displayName' => $delUser ? $delUser->getDisplayName() : $delUid,
					'email' => ($delUser && $delUser->getEMailAddress()) ? $delUser->getEMailAddress() : '',
					'level' => $del->getLevel(),
					'created_at' => $del->getCreatedAt()?->format(DateTime::ATOM),
				];
				$delegations[] = $delData;
				if ($del->getLevel() === CustomGroupDelegation::LEVEL_MANAGE) {
					$delegatesManage[] = $delData;
				} elseif ($del->getLevel() === CustomGroupDelegation::LEVEL_MODERATE) {
					$delegatesModerate[] = $delData;
				}
			}

			$pendingCount = 0;
			if ($canModerate) {
				$pendingCount = $this->requestMapper->countPendingRequests($g['group_id']);
			}

			$groups[] = [
				'group_id' => $g['group_id'],
				'name' => $g['name'],
				'creator_id' => $g['creator_id'],
				'creator_displayName' => $creatorUser ? $creatorUser->getDisplayName() : $g['creator_id'],
				'creator_email' => ($creatorUser && $creatorUser->getEMailAddress()) ? $creatorUser->getEMailAddress() : '',
				'owner_id' => $ownerId,
				'owner_displayName' => $ownerUser ? $ownerUser->getDisplayName() : $ownerId,
				'owner_email' => ($ownerUser && $ownerUser->getEMailAddress()) ? $ownerUser->getEMailAddress() : '',
				'created_at' => $g['created_at'],
				'member_ids' => $g['member_ids'],
				'members' => $members,
				'member_count' => count($members),
				'can_edit' => $canModerate,
				'is_owner' => $isOwner,
				'is_creator' => $isCreator,
				'delegations' => $delegations,
				'delegates_manage' => $delegatesManage,
				'delegates_moderate' => $delegatesModerate,
				'pending_requests_count' => $pendingCount,
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

		$currentUser = ($this->userId !== null) ? $this->userManager->get($this->userId) : null;
		$state = [
			'current_user_id' => $this->userId,
			'current_user_displayName' => $currentUser ? $currentUser->getDisplayName() : $this->userId,
			'current_user_email' => ($currentUser && $currentUser->getEMailAddress()) ? $currentUser->getEMailAddress() : '',
			'is_admin' => $isAdmin,
			'can_create_groups' => $canCreateGroups,
			'groups' => $groups,
		];

		$this->initialStateService->provideInitialState('user_groups_hzs-state', $state);

		return new TemplateResponse(
			Application::APP_ID,
			'index',
		);
	}
}
