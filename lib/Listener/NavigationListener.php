<?php

declare(strict_types=1);

namespace OCA\UserGroupsHzs\Listener;

use OCA\UserGroupsHzs\AppInfo\Application;
use OCA\UserGroupsHzs\Service\SettingsService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IL10N;
use OCP\INavigationManager;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Navigation\Events\LoadAdditionalEntriesEvent;
use Override;

/**
 * @template-implements IEventListener<LoadAdditionalEntriesEvent>
 */
class NavigationListener implements IEventListener {

	public function __construct(
		private INavigationManager $navigationManager,
		private IUserSession $userSession,
		private SettingsService $settingsService,
		private IURLGenerator $urlGenerator,
		private IL10N $l,
	) {
	}

	#[Override]
	public function handle(Event $event): void {
		if (!$event instanceof LoadAdditionalEntriesEvent) {
			return;
		}

		if (!$this->userSession->isLoggedIn()) {
			return;
		}

		$user = $this->userSession->getUser();
		if (!$user instanceof IUser) {
			return;
		}

		$userId = $user->getUID();
		if (!$this->settingsService->isUserAccessAllowed($userId)) {
			return;
		}

		$this->navigationManager->add([
			'id' => Application::APP_ID,
			'order' => 10,
			'href' => $this->urlGenerator->linkToRoute(Application::APP_ID . '.page.index'),
			'icon' => $this->urlGenerator->imagePath(Application::APP_ID, 'app-dark.svg'),
			'name' => $this->l->t('HZS User Groups'),
			'type' => INavigationManager::TYPE_APPS,
			'app' => Application::APP_ID,
		]);
	}
}

