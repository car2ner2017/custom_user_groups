<?php

declare(strict_types=1);

namespace OCA\CustomUserGroups\Service;

use OCA\CustomUserGroups\AppInfo\Application;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IUserManager;

class SettingsService {

	public function __construct(
		private IConfig $config,
		private IGroupManager $groupManager,
		private IUserManager $userManager,
	) {
	}

	public function isCreateRestrictionEnabled(): bool {
		return $this->config->getAppValue(Application::APP_ID, 'create_restriction_enabled', 'no') === 'yes';
	}

	/**
	 * @return list<string>
	 */
	public function getCreateAllowedUsers(): array {
		$raw = $this->config->getAppValue(Application::APP_ID, 'create_allowed_users', '[]');
		$decoded = json_decode($raw, true);
		return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
	}

	/**
	 * @return list<string>
	 */
	public function getCreateAllowedGroups(): array {
		$raw = $this->config->getAppValue(Application::APP_ID, 'create_allowed_groups', '[]');
		$decoded = json_decode($raw, true);
		return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
	}

	public function isAccessRestrictionEnabled(): bool {
		return $this->config->getAppValue(Application::APP_ID, 'access_restriction_enabled', 'no') === 'yes';
	}

	/**
	 * @return list<string>
	 */
	public function getAccessAllowedUsers(): array {
		$raw = $this->config->getAppValue(Application::APP_ID, 'access_allowed_users', '[]');
		$decoded = json_decode($raw, true);
		return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
	}

	/**
	 * @return list<string>
	 */
	public function getAccessAllowedGroups(): array {
		$raw = $this->config->getAppValue(Application::APP_ID, 'access_allowed_groups', '[]');
		$decoded = json_decode($raw, true);
		return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
	}

	/**
	 * @return list<string>
	 */
	public function getAccessForbiddenUsers(): array {
		$raw = $this->config->getAppValue(Application::APP_ID, 'access_forbidden_users', '[]');
		$decoded = json_decode($raw, true);
		return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
	}

	/**
	 * @return list<string>
	 */
	public function getAccessForbiddenGroups(): array {
		$raw = $this->config->getAppValue(Application::APP_ID, 'access_forbidden_groups', '[]');
		$decoded = json_decode($raw, true);
		return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
	}

	public function canUserCreateGroups(?string $userId): bool {
		if ($userId === null || $userId === '') {
			return false;
		}

		// Admins are always allowed to create groups
		if ($this->groupManager->isAdmin($userId)) {
			return true;
		}

		if (!$this->isCreateRestrictionEnabled()) {
			return true;
		}

		$allowedUsers = $this->getCreateAllowedUsers();
		if (in_array($userId, $allowedUsers, true)) {
			return true;
		}

		$allowedGroups = $this->getCreateAllowedGroups();
		if (!empty($allowedGroups)) {
			$user = $this->userManager->get($userId);
			if ($user !== null) {
				$userGroupIds = $this->groupManager->getUserGroupIds($user);
				if (count(array_intersect($userGroupIds, $allowedGroups)) > 0) {
					return true;
				}
			}
		}

		return false;
	}

	public function isUserAccessAllowed(?string $userId): bool {
		if ($userId === null || $userId === '') {
			return false;
		}

		// Admins always have access to the app
		if ($this->groupManager->isAdmin($userId)) {
			return true;
		}

		if (!$this->isAccessRestrictionEnabled()) {
			return true;
		}

		// Top-to-bottom rule evaluation:
		// 1. Allowed users
		$allowedUsers = $this->getAccessAllowedUsers();
		if (in_array($userId, $allowedUsers, true)) {
			return true;
		}

		// 2. Allowed groups
		$user = null;
		$userGroupIds = null;
		$allowedGroups = $this->getAccessAllowedGroups();
		if (!empty($allowedGroups)) {
			$user = $this->userManager->get($userId);
			if ($user !== null) {
				$userGroupIds = $this->groupManager->getUserGroupIds($user);
				if (count(array_intersect($userGroupIds, $allowedGroups)) > 0) {
					return true;
				}
			}
		}

		// 3. Forbidden users
		$forbiddenUsers = $this->getAccessForbiddenUsers();
		if (in_array($userId, $forbiddenUsers, true)) {
			return false;
		}

		// 4. Forbidden groups
		$forbiddenGroups = $this->getAccessForbiddenGroups();
		if (!empty($forbiddenGroups)) {
			if ($user === null) {
				$user = $this->userManager->get($userId);
			}
			if ($user !== null) {
				if ($userGroupIds === null) {
					$userGroupIds = $this->groupManager->getUserGroupIds($user);
				}
				if (count(array_intersect($userGroupIds, $forbiddenGroups)) > 0) {
					return false;
				}
			}
		}

		return true;
	}

	/**
	 * @return array{
	 *     create_restriction_enabled: bool,
	 *     create_allowed_users: list<string>,
	 *     create_allowed_groups: list<string>,
	 *     access_restriction_enabled: bool,
	 *     access_allowed_users: list<string>,
	 *     access_allowed_groups: list<string>,
	 *     access_forbidden_users: list<string>,
	 *     access_forbidden_groups: list<string>
	 * }
	 */
	public function getAllSettings(): array {
		return [
			'create_restriction_enabled' => $this->isCreateRestrictionEnabled(),
			'create_allowed_users' => $this->getCreateAllowedUsers(),
			'create_allowed_groups' => $this->getCreateAllowedGroups(),
			'access_restriction_enabled' => $this->isAccessRestrictionEnabled(),
			'access_allowed_users' => $this->getAccessAllowedUsers(),
			'access_allowed_groups' => $this->getAccessAllowedGroups(),
			'access_forbidden_users' => $this->getAccessForbiddenUsers(),
			'access_forbidden_groups' => $this->getAccessForbiddenGroups(),
		];
	}

	/**
	 * @param array{
	 *     create_restriction_enabled?: bool,
	 *     create_allowed_users?: list<string>,
	 *     create_allowed_groups?: list<string>,
	 *     access_restriction_enabled?: bool,
	 *     access_allowed_users?: list<string>,
	 *     access_allowed_groups?: list<string>,
	 *     access_forbidden_users?: list<string>,
	 *     access_forbidden_groups?: list<string>
	 * } $settings
	 */
	public function saveSettings(array $settings): void {
		if (isset($settings['create_restriction_enabled'])) {
			$this->config->setAppValue(
				Application::APP_ID,
				'create_restriction_enabled',
				$settings['create_restriction_enabled'] ? 'yes' : 'no'
			);
		}

		if (isset($settings['create_allowed_users']) && is_array($settings['create_allowed_users'])) {
			$clean = array_values(array_unique(array_filter($settings['create_allowed_users'], 'is_string')));
			$this->config->setAppValue(Application::APP_ID, 'create_allowed_users', json_encode($clean));
		}

		if (isset($settings['create_allowed_groups']) && is_array($settings['create_allowed_groups'])) {
			$clean = array_values(array_unique(array_filter($settings['create_allowed_groups'], 'is_string')));
			$this->config->setAppValue(Application::APP_ID, 'create_allowed_groups', json_encode($clean));
		}

		if (isset($settings['access_restriction_enabled'])) {
			$this->config->setAppValue(
				Application::APP_ID,
				'access_restriction_enabled',
				$settings['access_restriction_enabled'] ? 'yes' : 'no'
			);
		}

		if (isset($settings['access_allowed_users']) && is_array($settings['access_allowed_users'])) {
			$clean = array_values(array_unique(array_filter($settings['access_allowed_users'], 'is_string')));
			$this->config->setAppValue(Application::APP_ID, 'access_allowed_users', json_encode($clean));
		}

		if (isset($settings['access_allowed_groups']) && is_array($settings['access_allowed_groups'])) {
			$clean = array_values(array_unique(array_filter($settings['access_allowed_groups'], 'is_string')));
			$this->config->setAppValue(Application::APP_ID, 'access_allowed_groups', json_encode($clean));
		}

		if (isset($settings['access_forbidden_users']) && is_array($settings['access_forbidden_users'])) {
			$clean = array_values(array_unique(array_filter($settings['access_forbidden_users'], 'is_string')));
			$this->config->setAppValue(Application::APP_ID, 'access_forbidden_users', json_encode($clean));
		}

		if (isset($settings['access_forbidden_groups']) && is_array($settings['access_forbidden_groups'])) {
			$clean = array_values(array_unique(array_filter($settings['access_forbidden_groups'], 'is_string')));
			$this->config->setAppValue(Application::APP_ID, 'access_forbidden_groups', json_encode($clean));
		}
	}
}

