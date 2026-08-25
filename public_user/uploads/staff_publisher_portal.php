<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('PHPSESSID');
    session_start();
}

require_once __DIR__ . '/../admin/controller.php';
require_once __DIR__ . '/includes/staff_publisher_access.php';

$controller = new Controller();
$dbh = $controller->pdo();

if (empty($_SESSION['org_auth']) || (string)($_SESSION['org_account_type'] ?? '') !== 'staff') {
    header('Location: ../organization/login.php');
    exit;
}

$staffId = (int)($_SESSION['org_account_id'] ?? 0);
$orgId = (int)($_GET['org_id'] ?? $_SESSION['org_active_org_id'] ?? 0);

if ($staffId <= 0 || $orgId <= 0 || !staff_pub_staff_can_access_org($dbh, $staffId, $orgId)) {
    header('Location: ../organization/feed.php');
    exit;
}

if (!staff_pub_begin_public_session($dbh, $staffId, $orgId)) {
    header('Location: ../organization/feed.php');
    exit;
}

header('Location: feed.php');
exit;
