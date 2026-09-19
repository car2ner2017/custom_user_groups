<?php

declare(strict_types=1);

namespace OCA\CustomUserGroups\Settings;

use OCA\CustomUserGroups\AppInfo\Application;
use OCA\CustomUserGroups\Service\SettingsService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\Settings\ISettings;
use OCP\Util;

class AdminSettings implements ISettings {

	public function __construct(
		private IInitialState $initialStateService,
		private SettingsService $settingsService,
	) {
	}

	#[\Override]
	public function getForm(): TemplateResponse {
		Util::addScript(Application::APP_ID, 'customusergroups-admin-settings');

		$this->initialStateService->provideInitialState(
			'customUserGroupsSettings',
			$this->settingsService->getAllSettings()
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

