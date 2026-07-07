<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/session_user.php';
require_once __DIR__ . '/../controller.php';
require_once __DIR__ . '/../includes/org_shop.php';
require_once __DIR__ . '/../includes/stripe_shop.php';

header('Content-Type: application/json; charset=utf-8');

$controller = new Controller();
$dbh = $controller->pdo();
$meId = (int)($_SESSION['user_id'] ?? $_SESSION['id'] ?? $_SESSION['userid'] ?? 0);

if ($meId <= 0) {
    echo json_encode(['ok' => false, 'message' => 'Please sign in to place an order.']);
    exit;
}

$productId = (int)($_POST['product_id'] ?? 0);
$quantity = max(1, min(99, (int)($_POST['quantity'] ?? 1)));
$buyerNotes = trim((string)($_POST['buyer_notes'] ?? ''));
$deliveryAddress = trim((string)($_POST['delivery_address'] ?? ''));
$buyerPhone = trim((string)($_POST['buyer_phone'] ?? ''));
$returnProfileId = (int)($_POST['profile_id'] ?? 0);

if ($productId <= 0) {
    echo json_encode(['ok' => false, 'message' => 'Invalid product.']);
    exit;
}

$result = org_shop_create_order(
    $dbh,
    $productId,
    $meId,
    $quantity,
    $buyerNotes,
    $deliveryAddress,
    null,
    $buyerPhone,
    null
);

if (empty($result['ok'])) {
    echo json_encode(['ok' => false, 'message' => (string)($result['error'] ?? 'Order failed.')]);
    exit;
}

$orderId = (int)($result['order_id'] ?? 0);
$orderCode = (string)($result['order_code'] ?? '');
$totalCents = (int)($result['total_cents'] ?? 0);
$currency = (string)($result['currency'] ?? 'USD');
$totalLabel = org_shop_format_price($totalCents, $currency);

$product = org_shop_get_product($dbh, $productId);
$productTitle = (string)($product['title'] ?? 'Product');

$stripeEnabled = stripe_shop_is_configured() && $totalCents > 0;
$checkoutUrl = '';

if ($stripeEnabled && $orderId > 0) {
    $cancelUrl = stripe_shop_public_base_url() . '/profile.php?tab=shop';
    if ($returnProfileId > 0) {
        $cancelUrl .= '&id=' . $returnProfileId;
    }
    $cancelUrl .= '&checkout=cancel';

    $stripe = stripe_shop_create_checkout_session(
        $orderId,
        $orderCode,
        $productTitle,
        (int)($product['price_cents'] ?? $totalCents),
        $quantity,
        $currency,
        $meId,
        $cancelUrl
    );

    if (!empty($stripe['ok'])) {
        org_shop_attach_stripe_session($dbh, $orderId, (string)($stripe['session_id'] ?? ''));
        $checkoutUrl = (string)($stripe['checkout_url'] ?? '');
    } else {
        $stripeEnabled = false;
    }
}

if ($checkoutUrl !== '') {
    echo json_encode([
        'ok' => true,
        'stripe' => true,
        'checkout_url' => $checkoutUrl,
        'order_id' => $orderId,
        'order_code' => $orderCode,
        'message' => 'Redirecting to secure checkout…',
    ]);
    exit;
}

echo json_encode([
    'ok' => true,
    'stripe' => false,
    'message' => 'Order placed! Code: ' . $orderCode . ' — ' . $totalLabel . '. The seller will confirm payment.',
    'order_id' => $orderId,
    'order_code' => $orderCode,
    'total_cents' => $totalCents,
    'currency' => $currency,
]);
