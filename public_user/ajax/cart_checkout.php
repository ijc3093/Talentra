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

$result = org_cart_checkout($dbh, $meId, $deliveryAddress, $buyerNotes, $buyerPhone);

if (empty($result['ok'])) {
    echo json_encode(['ok' => false, 'message' => (string)($result['error'] ?? 'Checkout failed.')]);
    exit;
}

$orders = $result['orders'] ?? [];
$warnings = $result['errors'] ?? [];
$codes = array_map(static fn($o) => (string)($o['order_code'] ?? ''), $orders);
$codes = array_values(array_filter($codes));

$checkoutUrl = '';
if (count($orders) === 1 && stripe_shop_is_configured()) {
    $order = $orders[0];
    $orderId = (int)($order['order_id'] ?? 0);
    $orderCode = (string)($order['order_code'] ?? '');
    if ($orderId > 0) {
        $st = $dbh->prepare('SELECT product_title, unit_price_cents, quantity, currency FROM org_orders WHERE id = :id LIMIT 1');
        $st->execute([':id' => $orderId]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $stripe = stripe_shop_create_checkout_session(
            $orderId,
            $orderCode,
            (string)($row['product_title'] ?? 'Order'),
            (int)($row['unit_price_cents'] ?? 0),
            (int)($row['quantity'] ?? 1),
            (string)($row['currency'] ?? 'USD'),
            $meId,
            stripe_shop_public_base_url() . '/cart.php?checkout=cancel'
        );
        if (!empty($stripe['ok'])) {
            org_shop_attach_stripe_session($dbh, $orderId, (string)($stripe['session_id'] ?? ''));
            $checkoutUrl = (string)($stripe['checkout_url'] ?? '');
        }
    }
}

$message = count($codes) === 1
    ? 'Order placed! Code: ' . $codes[0]
    : count($codes) . ' orders placed: ' . implode(', ', $codes);

if ($warnings) {
    $message .= ' Some items could not be ordered.';
}

echo json_encode([
    'ok' => true,
    'message' => $message,
    'order_codes' => $codes,
    'checkout_url' => $checkoutUrl,
    'count' => (int)($result['count'] ?? 0),
]);
