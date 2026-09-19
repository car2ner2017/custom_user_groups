<?php

declare(strict_types=1);

namespace OCA\CustomUserGroups\Controller;

use OCA\CustomUserGroups\AppInfo\Application;
use OCA\CustomUserGroups\Db\CustomGroupMapper;
use OCA\CustomUserGroups\Service\SettingsService;
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

			$groups[] = [
				'group_id' => $g['group_id'],
				'name' => $g['name'],
				'creator_id' => $g['creator_id'],
				'creator_displayName' => $creatorUser ? $creatorUser->getDisplayName() : $g['creator_id'],
				'owner_id' => $ownerId,
				'owner_displayName' => $ownerUser ? $ownerUser->getDisplayName() : $ownerId,
				'created_at' => $g['created_at'],
				'member_ids' => $g['member_ids'],
				'members' => $members,
				'member_count' => count($members),
				'can_edit' => $isOwner || $isAdmin,
				'is_owner' => $isOwner,
				'is_creator' => $isCreator,
			];
		}

		$state = [
			'current_user_id' => $this->userId,
			'is_admin' => $isAdmin,
			'can_create_groups' => $canCreateGroups,
			'groups' => $groups,
		];

		$this->initialStateService->provideInitialState('customusergroups-state', $state);

		return new TemplateResponse(
			Application::APP_ID,
			'index',
		);
	}
}
