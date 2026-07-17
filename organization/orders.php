<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/includes/session_org.php';
require_once __DIR__ . '/includes/org_context.php';
require_once __DIR__ . '/../public_user/includes/org_shop.php';
require_once __DIR__ . '/includes/org_ecommerce.php';
require_once __DIR__ . '/includes/org_manager_guard.php';

org_require_manager();

org_require_commerce_seller();
org_ecommerce_ensure_schema($dbh);

$orgId = (int)orgActiveOrgId();
$memberId = (int)orgMemberId();
$err = '';
$ok = '';

$statusFilter = strtolower(trim((string)($_GET['status'] ?? 'all')));
$allowedFilters = ['all', 'pending', 'confirmed', 'paid', 'shipped', 'delivered', 'cancelled'];
if (!in_array($statusFilter, $allowedFilters, true)) {
    $statusFilter = 'all';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $orderId = (int)($_POST['order_id'] ?? 0);

    if (isset($_POST['oms_cancel_action'])) {
        $cancelReason = trim((string)($_POST['cancel_reason'] ?? ''));
        $cancelRes = org_shop_seller_cancel_customer_batch($dbh, $orgId, $orderId, $cancelReason);
        if (!empty($cancelRes['ok'])) {
            $n = (int)($cancelRes['cancelled'] ?? 0);
            $ok = $n === 1
                ? 'Order cancelled. Buyer was notified.'
                : ($n . ' order lines cancelled. Buyer was notified.');
        } else {
            $err = (string)($cancelRes['error'] ?? 'Could not cancel order.');
        }
    } else {
        $newStatus = strtolower(trim((string)($_POST['status'] ?? '')));
        $sellerNotes = trim((string)($_POST['seller_notes'] ?? ''));
        $tracking = trim((string)($_POST['tracking_number'] ?? ''));
        $carrier = trim((string)($_POST['carrier'] ?? ''));

        if (isset($_POST['sync_crm'])) {
            if (org_ecommerce_sync_buyer_to_crm($dbh, $orgId, $orderId, $memberId)) {
                $ok = 'Buyer synced to CRM.';
            } else {
                $err = 'Could not sync buyer to CRM.';
            }
        } elseif (org_ecommerce_update_fulfillment($dbh, $orgId, $orderId, $newStatus, $sellerNotes, $tracking, $carrier)) {
            $ok = 'Order updated.';
        } else {
            $err = 'Could not update order.';
        }
    }
}

$omsBaseUrl = 'orders.php';
$omsHash = '';
$omsShowCommerceHub = true;

$pageTitle = 'Orders';
require_once __DIR__ . '/includes/org_page_shell.php';
org_page_shell_open($pageTitle);
?>
<?php org_page_body_open(); ?>
<?php require __DIR__ . '/includes/org_oms_orders_panel.php'; ?>
</div>
<?php require_once __DIR__ . '/includes/org_order_details_door.php'; ?>
<?php org_page_shell_close(); ?>
