<?php
declare(strict_types=1);

/**
 * Signed handoff from Admin Inventory → organization product detail.
 * Establishes org session with admin oversight (no organization login form).
 */
require_once __DIR__ . '/../admin/includes/admin_linked_bootstrap_load.php';
require_once __DIR__ . '/../admin/includes/admin_linked_portal_load.php';
require_once __DIR__ . '/../admin/controller.php';

$handoff = admin_linked_verify_product_oversight_handoff();
if (!$handoff) {
    header('Location: login.php');
    exit;
}

$adminId = (int)$handoff['admin_id'];
$productId = (int)$handoff['product_id'];
$from = strtolower(trim((string)($_GET['from'] ?? 'sales')));
if ($from === '') {
    $from = 'sales';
}

$dbh = (new Controller())->pdo();

if (!admin_linked_establish_org_product_oversight($dbh, $adminId, $productId)) {
    header('Location: login.php');
    exit;
}

$target = 'products_detail.php?id=' . $productId . '&from=' . rawurlencode($from);
header('Location: ' . $target);
exit;
