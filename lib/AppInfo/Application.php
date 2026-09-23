<?php

declare(strict_types=1);

namespace OCA\UserGroupsHzs\AppInfo;

use OCA\UserGroupsHzs\Group\CustomGroupBackend;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\IGroupManager;

class Application extends App implements IBootstrap {
	public const APP_ID = 'user_groups_hzs';

	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct() {
		parent::__construct(self::APP_ID);
	}

	public function register(IRegistrationContext $context): void {
		$context->registerEventListener(
			\OCP\Navigation\Events\LoadAdditionalEntriesEvent::class,
			\OCA\UserGroupsHzs\Listener\NavigationListener::class
		);
	}

	public function boot(IBootContext $context): void {
		$context->injectFn(function (
			IGroupManager $groupManager,
			CustomGroupBackend $customGroupBackend,
		): void {
			$groupManager->addBackend($customGroupBackend);
		});
	}
}
