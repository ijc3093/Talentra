<?php
declare(strict_types=1);

/**
 * admin/open_product_detail.php
 * Admin Inventory → org product detail with platform-admin authority (no org login).
 */
require_once __DIR__ . '/includes/session_admin.php';
require_once __DIR__ . '/includes/admin_linked_bootstrap_load.php';
require_once __DIR__ . '/controller.php';

requireAdminLogin();

$adminId = (int)($_SESSION['admin_id'] ?? 0);
$role = (int)($_SESSION['userRole'] ?? 0);
$productId = (int)($_GET['id'] ?? $_GET['pid'] ?? 0);
$from = strtolower(trim((string)($_GET['from'] ?? 'sales')));
if ($from === '') {
    $from = 'sales';
}

if ($adminId <= 0 || $role !== 1 || $productId <= 0) {
    header('Location: inventory.php');
    exit;
}

$dbh = (new Controller())->pdo();

if (!function_exists('admin_linked_establish_org_product_oversight')
    || !admin_linked_establish_org_product_oversight($dbh, $adminId, $productId)) {
    header('Location: inventory.php');
    exit;
}

// Land on the real org product URL the admin asked for.
$target = '../organization/products_detail.php?id=' . $productId . '&from=' . rawurlencode($from);
header('Location: ' . $target);
exit;
