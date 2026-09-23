<?php

declare(strict_types=1);

use OCP\Util;

Util::addScript(OCA\UserGroupsHzs\AppInfo\Application::APP_ID, OCA\UserGroupsHzs\AppInfo\Application::APP_ID . '-main');
Util::addStyle(OCA\UserGroupsHzs\AppInfo\Application::APP_ID, OCA\UserGroupsHzs\AppInfo\Application::APP_ID . '-main');

?>

<div id="user_groups_hzs"></div>
