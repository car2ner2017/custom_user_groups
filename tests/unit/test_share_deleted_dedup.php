<?php

declare(strict_types=1);

namespace OCA\UserGroupsHzs\Tests;

require_once '/var/www/nextcloud/3rdparty/autoload.php';

spl_autoload_register(function ($class) {
    if (str_starts_with($class, 'OCP\\')) {
        $path = '/var/www/nextcloud/lib/public/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (file_exists($path)) {
            require_once $path;
            return;
        }
    }
    if (str_starts_with($class, 'OC\\')) {
        $path = '/var/www/nextcloud/lib/private/' . str_replace('\\', '/', substr($class, 3)) . '.php';
        if (file_exists($path)) {
            require_once $path;
            return;
        }
    }
});

require_once __DIR__ . '/../../lib/Db/CustomGroupActivity.php';
require_once __DIR__ . '/../../lib/Db/CustomGroupActivityMapper.php';
require_once __DIR__ . '/../../lib/Db/CustomGroupMapper.php';
require_once __DIR__ . '/../../lib/Listener/ShareDeletedListener.php';

use OCA\UserGroupsHzs\Db\CustomGroupActivity;
use OCA\UserGroupsHzs\Db\CustomGroupActivityMapper;
use OCA\UserGroupsHzs\Db\CustomGroupMapper;
use OCA\UserGroupsHzs\Listener\ShareDeletedListener;
use OCP\EventDispatcher\Event;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Share\Events\BeforeShareDeletedEvent;
use OCP\Share\IShare;

// Simple test runner
$loggedEvents = [];

// Mock activity mapper
require_once '/var/www/nextcloud/lib/private/User/Manager.php';
require_once '/var/www/nextcloud/lib/private/User/Session.php';

$mockMapperClass = new class extends CustomGroupActivityMapper {
    public static array $events = [];
    public function __construct() {}
    public function logActivity(string $groupId, string $actionType, string $actorId, ?string $targetId = null, ?array $details = null): CustomGroupActivity {
        self::$events[] = [
            'group_id' => $groupId,
            'action' => $actionType,
            'actor' => $actorId,
            'details' => $details,
        ];
        return new CustomGroupActivity();
    }
};
$activityMapper = (new \ReflectionClass($mockMapperClass::class))->newInstanceWithoutConstructor();

// Mock group mapper
$groupMapper = new class extends CustomGroupMapper {
    public function __construct() {}
    public function getGroupDetails(string $groupId): ?array {
        return [
            'group_id' => $groupId,
            'name' => 'Test Group',
            'member_ids' => ['user1', 'user2'],
            'owner_id' => 'admin',
            'creator_id' => 'admin',
        ];
    }
};

$userManager = new class extends \OC\User\Manager {
    public function __construct() {}
    public function get($uid, array $excludeBackends = []): ?\OCP\IUser { return null; }
};
$userSession = new class extends \OC\User\Session {
    public function __construct() {}
    public function getUser(): ?\OCP\IUser { return null; }
};

require_once '/var/www/nextcloud/lib/private/Share20/Share.php';

function createMockShare(string $id, string $target): \OC\Share20\Share {
    $share = (new \ReflectionClass(\OC\Share20\Share::class))->newInstanceWithoutConstructor();
    $share->setId($id);
    $share->setShareType(IShare::TYPE_GROUP);
    $share->setSharedWith('test_group');
    $share->setNodeType('folder');
    $share->setTarget($target);
    $share->setShareOwner('owner_uid');
    $share->setSharedBy('sharer_uid');
    return $share;
}

$listener = new ShareDeletedListener($groupMapper, $activityMapper, $userManager, $userSession);

echo "--- Test 1: Deletion from GroupApiController ---\n";
$share1 = createMockShare('101', 'folder_a');
ShareDeletedListener::$recentlyLoggedShares = [];
$mockMapperClass::$events = [];

// Simulate GroupApiController logic:
// 1. GroupApiController sets recentlyLoggedShares before deleteShare
ShareDeletedListener::$recentlyLoggedShares['101'] = time();

// 2. deleteShare triggers BeforeShareDeletedEvent
$event1 = new BeforeShareDeletedEvent($share1);
$listener->handle($event1);

// ShareDeletedListener should NOT have logged anything!
if (count($mockMapperClass::$events) !== 0) {
    echo "FAIL: ShareDeletedListener logged when it should have suppressed! Count: " . count($mockMapperClass::$events) . "\n";
    exit(1);
}

// 3. GroupApiController logs the activity once
$activityMapper->logActivity('test_group', CustomGroupActivity::ACTION_SHARE_UNSHARE, 'admin', null, ['name' => 'folder_a']);

if (count($mockMapperClass::$events) !== 1) {
    echo "FAIL: Expected exactly 1 logged event, got " . count($mockMapperClass::$events) . "\n";
    exit(1);
}
echo "PASS: Exactly 1 event logged during GroupApiController revocation.\n";

echo "--- Test 2: Deletion from Files app (external) ---\n";
$share2 = createMockShare('102', 'folder_b');
$mockMapperClass::$events = [];

// External delete: GroupApiController is NOT involved, recentlyLoggedShares is empty
$event2 = new BeforeShareDeletedEvent($share2);
$listener->handle($event2);

if (count($mockMapperClass::$events) !== 1) {
    echo "FAIL: Expected exactly 1 logged event for external delete, got " . count($mockMapperClass::$events) . "\n";
    exit(1);
}

// Second trigger for the same share within 5 seconds should be ignored
$listener->handle($event2);
if (count($mockMapperClass::$events) !== 1) {
    echo "FAIL: Duplicate trigger was not suppressed! Got " . count($mockMapperClass::$events) . "\n";
    exit(1);
}
echo "PASS: Exactly 1 event logged for external delete, duplicate suppressed.\n";

echo "ALL TESTS PASSED!\n";
