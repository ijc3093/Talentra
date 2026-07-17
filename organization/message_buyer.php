<?php
declare(strict_types=1);

/**
 * Seller → buyer commerce chat bridge.
 * Establishes the org publisher's public session, then opens messages.php with the buyer.
 */
require_once __DIR__ . '/includes/session_org.php';
require_once __DIR__ . '/includes/org_context.php';
require_once __DIR__ . '/includes/org_manager_guard.php';
require_once __DIR__ . '/includes/org_sales.php';
require_once __DIR__ . '/../public_user/includes/staff_publisher_access.php';
require_once __DIR__ . '/../public_user/includes/publisher_accounts.php';
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

$publisherUserId = staff_pub_org_publisher_user_id($dbh, $orgId);
if ($publisherUserId <= 0) {
    $publisherUserId = (int)($_SESSION['org_publisher_user_id'] ?? 0);
}
if ($publisherUserId <= 0) {
    $_SESSION['org_flash'] = 'No publisher account is linked to this organization.';
    header('Location: order_details.php?id=' . $orderId);
    exit;
}

$accountType = (string)($_SESSION['org_account_type'] ?? '');
$accountId = (int)($_SESSION['org_account_id'] ?? 0);

if ($accountType === 'staff' && $accountId > 0) {
    if (!staff_pub_begin_public_session($dbh, $accountId, $orgId)) {
        $_SESSION['org_flash'] = 'Could not open the seller inbox as this organization publisher.';
        header('Location: order_details.php?id=' . $orderId);
        exit;
    }
} elseif ($accountType === 'manager' && $accountId > 0) {
    publisher_session_establish_for_manager($dbh, $accountId);
} else {
    $_SESSION['org_flash'] = 'Sign in as an organization manager or staff member to message the buyer.';
    header('Location: order_details.php?id=' . $orderId);
    exit;
}

$orderCode = (string)($order['order_code'] ?? '');
$productId = (int)($order['product_id'] ?? 0);
$q = [
    'id' => $buyerUserId,
    'commerce' => '1',
    'about_order' => $orderCode,
];
if ($productId > 0) {
    $q['about_product'] = $productId;
}

header('Location: ../public_user/messages.php?' . http_build_query($q));
exit;
