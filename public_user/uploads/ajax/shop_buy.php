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
$deliveryOption = trim((string)($_POST['delivery_option'] ?? 'home_delivery'));
$fulfillmentMethod = trim((string)($_POST['fulfillment_method'] ?? ''));
$promoCode = trim((string)($_POST['promo_code'] ?? ''));
$returnProfileId = (int)($_POST['profile_id'] ?? 0);
$paymentMethod = strtolower(trim((string)($_POST['payment_method'] ?? '')));

/** Parse dollars like "121768.26" or "$121,768.26" into cents. */
$parseDollarsToCents = static function (string $raw): int {
    $cleaned = preg_replace('/[^0-9.]/', '', trim($raw)) ?? '';
    if ($cleaned === '' || $cleaned === '.') {
        return 0;
    }
    if (!is_numeric($cleaned)) {
        return 0;
    }
    return max(0, (int)round(((float)$cleaned) * 100));
};

$isTestCostPay = ($paymentMethod === 'test_cost');
$testCostCents = (int)($_POST['test_cost_cents'] ?? 0);
if ($testCostCents <= 0 && $isTestCostPay) {
    $testCostCents = $parseDollarsToCents((string)($_POST['test_cost'] ?? ''));
}

if ($productId <= 0) {
    echo json_encode(['ok' => false, 'message' => 'Invalid product.']);
    exit;
}

if ($isTestCostPay && $testCostCents <= 0) {
    echo json_encode(['ok' => false, 'message' => 'Enter a Cost $ amount greater than zero to place this test order.']);
    exit;
}

if ($isTestCostPay) {
    $testNote = 'Test payment Cost $: ' . org_shop_format_price($testCostCents, 'USD')
        . ' (no real card charged). Seller: confirm and ship.';
    $buyerNotes = $buyerNotes !== '' ? ($buyerNotes . "\n" . $testNote) : $testNote;
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
    null,
    $deliveryOption,
    $fulfillmentMethod !== '' ? $fulfillmentMethod : null,
    $promoCode
);

if (empty($result['ok'])) {
    echo json_encode(['ok' => false, 'message' => (string)($result['error'] ?? 'Order failed.')]);
    exit;
}

$orderId = (int)($result['order_id'] ?? 0);
$orderCode = (string)($result['order_code'] ?? '');
$totalCents = (int)($result['total_cents'] ?? 0);
$currency = (string)($result['currency'] ?? 'USD');
$orgId = (int)($result['org_id'] ?? 0);

// Test Cost $: override order total and mark paid so Revenue MTD updates
// without a real card. Marketplace fees (~15%) are seller-paid via apply_order_fees.
if ($isTestCostPay && $orderId > 0 && $testCostCents > 0) {
    try {
        $st = $dbh->prepare("
            UPDATE org_orders
            SET total_cents = :total,
                status = 'paid',
                paid_at = COALESCE(paid_at, NOW()),
                payment_method = 'test_cost',
                payment_reference = :pref,
                updated_at = NOW()
            WHERE id = :id
            LIMIT 1
        ");
        $st->execute([
            ':total' => $testCostCents,
            ':pref' => 'TEST-' . $orderCode,
            ':id' => $orderId,
        ]);
    } catch (Throwable $e) {
        try {
            $dbh->prepare("
                UPDATE org_orders
                SET total_cents = :total,
                    status = 'paid',
                    updated_at = NOW()
                WHERE id = :id
                LIMIT 1
            ")->execute([':total' => $testCostCents, ':id' => $orderId]);
        } catch (Throwable $e2) {
            // ignore
        }
    }
    $totalCents = $testCostCents;
    org_shop_apply_order_fees($dbh, $orderId);
    if ($orgId > 0) {
        org_shop_issue_receipt($dbh, $orgId, $orderId, 'test_cost', 'TEST-' . $orderCode);
    }

    if ($orgId > 0 && function_exists('org_shop_notify_seller_order_status')) {
        try {
            org_shop_notify_seller_order_status($dbh, $orgId, $meId, 'paid', [$orderCode]);
        } catch (Throwable $e) {
            // ignore
        }
    }
    if ($orgId > 0 && function_exists('org_ecommerce_sync_buyer_to_crm')) {
        try {
            org_ecommerce_sync_buyer_to_crm($dbh, $orgId, $orderId, 0);
        } catch (Throwable $e) {
            // ignore
        }
    }

    $totalLabel = org_shop_format_price($totalCents, $currency);
    echo json_encode([
        'ok' => true,
        'stripe' => false,
        'test_cost' => true,
        'message' => 'Test order placed. Seller can ship from Orders.',
        'order_id' => $orderId,
        'order_code' => $orderCode,
        'total_cents' => $totalCents,
        'currency' => $currency,
    ]);
    exit;
}

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

    $qty = max(1, $quantity);
    $unitForStripe = (int)max(1, (int)round($totalCents / $qty));
    $stripe = stripe_shop_create_checkout_session(
        $orderId,
        $orderCode,
        $productTitle,
        $unitForStripe,
        $qty,
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
