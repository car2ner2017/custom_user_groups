<?php

declare(strict_types=1);

use OCP\Util;

Util::addScript(OCA\CustomUserGroups\AppInfo\Application::APP_ID, OCA\CustomUserGroups\AppInfo\Application::APP_ID . '-main');
Util::addStyle(OCA\CustomUserGroups\AppInfo\Application::APP_ID, OCA\CustomUserGroups\AppInfo\Application::APP_ID . '-main');

?>

<div id="customusergroups"></div>
