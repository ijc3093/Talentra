<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/session_user.php';
requireUserLogin();

require_once __DIR__ . '/controller.php';
require_once __DIR__ . '/includes/staff_publisher_access.php';

if (!staff_pub_is_staff_session()) {
    header('Location: feed.php');
    exit;
}

$controller = new Controller();
$dbh = $controller->pdo();

$staffId = staff_pub_staff_account_id();
$orgId = (int)($_GET['org_id'] ?? 0);
if ($orgId <= 0) {
    $orgId = staff_pub_org_id();
}

if ($orgId <= 0 || !staff_pub_staff_can_access_org($dbh, $staffId, $orgId)) {
    header('Location: feed.php');
    exit;
}

if (!staff_pub_begin_org_session($dbh, $staffId, $orgId)) {
    header('Location: feed.php');
    exit;
}

header('Location: ../organization/feed.php');
exit;
