<?php

declare(strict_types=1);

namespace OCA\CustomUserGroups\Settings;

use OCA\CustomUserGroups\AppInfo\Application;
use OCA\CustomUserGroups\Service\SettingsService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\Settings\ISettings;
use OCP\Util;

class AdminSettings implements ISettings {

	public function __construct(
		private IInitialState $initialStateService,
		private SettingsService $settingsService,
		private IUserManager $userManager,
		private IGroupManager $groupManager,
	) {
	}

	#[\Override]
	public function getForm(): TemplateResponse {
		Util::addScript(Application::APP_ID, 'customusergroups-admin-settings');
		Util::addStyle(Application::APP_ID, 'customusergroups-admin-settings');

		$rawSettings = $this->settingsService->getAllSettings();

		$enrichUsers = function (array $uids): array {
			$res = [];
			foreach ($uids as $uid) {
				$user = $this->userManager->get($uid);
				$res[] = [
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

		$this->initialStateService->provideInitialState(
			'customUserGroupsSettings',
			[
				'settings' => $rawSettings,
				'create_allowed_users_details' => $enrichUsers($rawSettings['create_allowed_users']),
				'create_allowed_groups_details' => $enrichGroups($rawSettings['create_allowed_groups']),
				'access_allowed_users_details' => $enrichUsers($rawSettings['access_allowed_users']),
				'access_allowed_groups_details' => $enrichGroups($rawSettings['access_allowed_groups']),
				'access_forbidden_users_details' => $enrichUsers($rawSettings['access_forbidden_users']),
				'access_forbidden_groups_details' => $enrichGroups($rawSettings['access_forbidden_groups']),
			]
		);

		return new TemplateResponse(
			Application::APP_ID,
			'settings-admin',
			[],
			'blank'
		);
	}

	#[\Override]
	public function getSection(): string {
		return Application::APP_ID;
	}

	#[\Override]
	public function getPriority(): int {
		return 80;
	}
}

