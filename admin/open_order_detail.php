<?php
declare(strict_types=1);

/**
 * admin/open_order_detail.php
 * Admin Orders → org order detail with platform-admin authority (no org login).
 */
require_once __DIR__ . '/includes/session_admin.php';
require_once __DIR__ . '/includes/admin_linked_bootstrap_load.php';
require_once __DIR__ . '/controller.php';

requireAdminLogin();

$adminId = (int)($_SESSION['admin_id'] ?? 0);
$role = (int)($_SESSION['userRole'] ?? 0);
$orderId = (int)($_GET['id'] ?? $_GET['oid'] ?? 0);

if ($adminId <= 0 || $role !== 1 || $orderId <= 0) {
    header('Location: Orders.php');
    exit;
}

$dbh = (new Controller())->pdo();

if (!function_exists('admin_linked_establish_org_order_oversight')
    || !admin_linked_establish_org_order_oversight($dbh, $adminId, $orderId)) {
    header('Location: Orders.php');
    exit;
}

$target = '../organization/order_details.php?id=' . $orderId;
header('Location: ' . $target);
exit;
