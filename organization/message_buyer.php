<?php
declare(strict_types=1);

/**
 * Seller → buyer commerce chat bridge.
 * Opens Sales Management customer chat for the order's buyer.
 */
require_once __DIR__ . '/includes/session_org.php';
require_once __DIR__ . '/includes/org_context.php';
require_once __DIR__ . '/includes/org_manager_guard.php';
require_once __DIR__ . '/includes/org_sales.php';
require_once __DIR__ . '/../public_user/includes/commerce_messaging.php';

org_require_manager();
org_require_commerce_seller();
org_ecommerce_ensure_schema($dbh);

$orgId = (int)orgActiveOrgId();
$orderId = (int)($_GET['order_id'] ?? $_GET['id'] ?? 0);
$order = org_sales_order($dbh, $orgId, $orderId);
if (!$order) {
    $_SESSION['org_flash'] = 'Order not found.';
    header('Location: orders.php');
    exit;
}

$buyerUserId = (int)($order['buyer_user_id'] ?? 0);
if ($buyerUserId <= 0) {
    $_SESSION['org_flash'] = 'This order has no linked buyer account to message.';
    header('Location: order_details.php?id=' . $orderId);
    exit;
}

$orderCode = (string)($order['order_code'] ?? '');
$productId = (int)($order['product_id'] ?? 0);
header('Location: ' . commerce_message_buyer_sales_url($buyerUserId, $productId, $orderCode));
exit;
