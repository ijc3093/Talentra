<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/session_user.php';
require_once __DIR__ . '/../controller.php';
require_once __DIR__ . '/../includes/org_cart.php';
require_once __DIR__ . '/../includes/org_shop.php';
require_once __DIR__ . '/../includes/stripe_shop.php';

header('Content-Type: application/json; charset=utf-8');

$controller = new Controller();
$dbh = $controller->pdo();
$meId = (int)($_SESSION['user_id'] ?? $_SESSION['id'] ?? $_SESSION['userid'] ?? 0);

if ($meId <= 0) {
    echo json_encode(['ok' => false, 'message' => 'Please sign in to checkout.']);
    exit;
}

$deliveryAddress = trim((string)($_POST['delivery_address'] ?? ''));
$buyerNotes = trim((string)($_POST['buyer_notes'] ?? ''));
$buyerPhone = trim((string)($_POST['buyer_phone'] ?? ''));
$promoCode = trim((string)($_POST['promo_code'] ?? ''));
$deliveryOption = trim((string)($_POST['delivery_option'] ?? 'home_delivery'));
$productIds = null;
if (array_key_exists('product_ids', $_POST)) {
    $rawProductIds = $_POST['product_ids'];
    if (is_array($rawProductIds)) {
        $productIds = array_map('intval', $rawProductIds);
    } elseif (is_string($rawProductIds)) {
        $productIds = $rawProductIds === ''
            ? []
            : array_map('intval', explode(',', $rawProductIds));
    } else {
        $productIds = [];
    }
}

$result = org_cart_checkout(
    $dbh,
    $meId,
    $deliveryAddress,
    $buyerNotes,
    $buyerPhone,
    $productIds,
    $promoCode,
    $deliveryOption
);

if (empty($result['ok'])) {
    echo json_encode(['ok' => false, 'message' => (string)($result['error'] ?? 'Checkout failed.')]);
    exit;
}

$orders = $result['orders'] ?? [];
$warnings = $result['errors'] ?? [];
$codes = array_map(static fn($o) => (string)($o['order_code'] ?? ''), $orders);
$codes = array_values(array_filter($codes));

$checkoutUrl = '';
$pendingPayments = 0;
if (stripe_shop_is_configured()) {
    foreach ($orders as $idx => $order) {
        $orderId = (int)($order['order_id'] ?? 0);
        $orderCode = (string)($order['order_code'] ?? '');
        $totalCents = (int)($order['total_cents'] ?? 0);
        $currency = (string)($order['currency'] ?? 'USD');
        if ($orderId <= 0 || $totalCents <= 0) {
            continue;
        }
        $st = $dbh->prepare('SELECT product_title, quantity FROM org_orders WHERE id = :id LIMIT 1');
        $st->execute([':id' => $orderId]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $qty = max(1, (int)($row['quantity'] ?? 1));
        // Charge the discounted order total (unit ≈ total/qty).
        $unitForStripe = (int)max(1, (int)round($totalCents / $qty));
        $stripe = stripe_shop_create_checkout_session(
            $orderId,
            $orderCode,
            (string)($row['product_title'] ?? 'Order'),
            $unitForStripe,
            $qty,
            $currency,
            $meId,
            stripe_shop_public_base_url() . '/cart.php?checkout=cancel'
        );
        if (!empty($stripe['ok'])) {
            org_shop_attach_stripe_session($dbh, $orderId, (string)($stripe['session_id'] ?? ''));
            if ($checkoutUrl === '') {
                $checkoutUrl = (string)($stripe['checkout_url'] ?? '');
            } else {
                $pendingPayments++;
            }
        } else {
            $pendingPayments++;
        }
    }
}

$message = count($codes) === 1
    ? 'Order placed! Code: ' . $codes[0]
    : count($codes) . ' orders placed: ' . implode(', ', $codes);

if ($warnings) {
    $message .= ' Some items could not be ordered.';
}
if ($pendingPayments > 0) {
    $message .= ' Complete remaining payments from My Orders.';
}

echo json_encode([
    'ok' => true,
    'message' => $message,
    'order_codes' => $codes,
    'checkout_url' => $checkoutUrl,
    'pending_payments' => $pendingPayments,
    'count' => (int)($result['count'] ?? 0),
]);
