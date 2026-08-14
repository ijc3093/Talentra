<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/session_user.php';
requireUserLogin();

require_once __DIR__ . '/controller.php';
require_once __DIR__ . '/includes/staff_publisher_access.php';

if (!staff_pub_is_staff_session()) {
    header('Location: home.php?tab=for-you');
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
    header('Location: home.php?tab=for-you');
    exit;
}

if (!staff_pub_begin_org_session($dbh, $staffId, $orgId)) {
    header('Location: home.php?tab=for-you');
    exit;
}

$query = staff_pub_enterprise_handoff_query($staffId, $orgId);
if ($query === '') {
    header('Location: home.php?tab=for-you');
    exit;
}

header('Location: ../organization/staff_enter.php?' . $query);
exit;
