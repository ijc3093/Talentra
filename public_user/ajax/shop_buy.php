<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/session_user.php';
require_once __DIR__ . '/../controller.php';
require_once __DIR__ . '/../includes/org_shop.php';
require_once __DIR__ . '/../includes/org_cart.php';
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

// Clear purchased line from cart when buying from cart / buy door.
try {
    org_cart_remove_item($dbh, $meId, $productId);
} catch (Throwable $e) {
    // non-fatal
}

$orderId = (int)($result['order_id'] ?? 0);
$orderCode = (string)($result['order_code'] ?? '');
$totalCents = (int)($result['total_cents'] ?? 0);
$currency = (string)($result['currency'] ?? 'USD');
$orgId = (int)($result['org_id'] ?? 0);

// Test Cost $: record what the customer entered. Keep the real order total.
// Full amount → Paid (seller can ship). Short amount → Pending (shipping held).
if ($isTestCostPay && $orderId > 0 && $testCostCents > 0) {
    $requiredCents = max(0, $totalCents);
    $amountPaidCents = max(0, $testCostCents);
    $paymentComplete = $requiredCents <= 0 || $amountPaidCents >= $requiredCents;
    $shortfallCents = max(0, $requiredCents - $amountPaidCents);
    $paidLabel = org_shop_format_price($amountPaidCents, $currency);
    $dueLabel = org_shop_format_price($requiredCents, $currency);
    $shortLabel = org_shop_format_price($shortfallCents, $currency);

    if ($paymentComplete) {
        $testNote = 'Test payment Cost $: ' . $paidLabel
            . ' (full order total ' . $dueLabel . '; no real card charged). Seller: confirm and ship.';
    } else {
        $testNote = 'Payment incomplete: paid ' . $paidLabel
            . ' of ' . $dueLabel
            . ' (short ' . $shortLabel
            . '). Shipping will not start until the full amount is paid. Test Cost $ — no real card charged.';
    }
    $notesWithPay = $buyerNotes !== '' ? ($buyerNotes . "\n" . $testNote) : $testNote;
    $payRef = 'TEST-' . $orderCode . '|paid:' . $amountPaidCents . '|due:' . $requiredCents;

    // Persist paid amount first (core columns). Optional payment_* columns added separately
    // so a missing column cannot wipe the incomplete-payment record.
    try {
        if ($paymentComplete) {
            $dbh->prepare("
                UPDATE org_orders
                SET status = 'paid',
                    paid_at = COALESCE(paid_at, NOW()),
                    amount_paid_cents = :paid,
                    buyer_notes = :notes,
                    updated_at = NOW()
                WHERE id = :id
                LIMIT 1
            ")->execute([
                ':paid' => $amountPaidCents,
                ':notes' => $notesWithPay,
                ':id' => $orderId,
            ]);
        } else {
            $dbh->prepare("
                UPDATE org_orders
                SET status = 'pending',
                    paid_at = NULL,
                    amount_paid_cents = :paid,
                    buyer_notes = :notes,
                    updated_at = NOW()
                WHERE id = :id
                LIMIT 1
            ")->execute([
                ':paid' => $amountPaidCents,
                ':notes' => $notesWithPay,
                ':id' => $orderId,
            ]);
        }
    } catch (Throwable $e) {
        try {
            $dbh->prepare("
                UPDATE org_orders
                SET status = :st,
                    buyer_notes = :notes,
                    updated_at = NOW()
                WHERE id = :id
                LIMIT 1
            ")->execute([
                ':st' => $paymentComplete ? 'paid' : 'pending',
                ':notes' => $notesWithPay,
                ':id' => $orderId,
            ]);
        } catch (Throwable $e2) {
            // ignore
        }
    }
    try {
        $dbh->prepare("
            UPDATE org_orders
            SET payment_method = 'test_cost',
                payment_reference = :pref,
                updated_at = NOW()
            WHERE id = :id
            LIMIT 1
        ")->execute([
            ':pref' => $payRef,
            ':id' => $orderId,
        ]);
    } catch (Throwable $e) {
        // payment_* columns may still be missing on older DBs
    }

    if ($paymentComplete) {
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

        echo json_encode([
            'ok' => true,
            'stripe' => false,
            'test_cost' => true,
            'payment_complete' => true,
            'message' => 'Test order placed and fully paid. Seller can ship from Orders.',
            'order_id' => $orderId,
            'order_code' => $orderCode,
            'total_cents' => $requiredCents,
            'amount_paid_cents' => $amountPaidCents,
            'shortfall_cents' => 0,
            'currency' => $currency,
        ]);
        exit;
    }

    if ($orgId > 0 && function_exists('org_shop_notify_seller_order_status')) {
        try {
            $extra = 'Paid ' . $paidLabel . ' of ' . $dueLabel . ' (short ' . $shortLabel . ')';
            org_shop_notify_seller_order_status($dbh, $orgId, $meId, 'pending', [$orderCode], $extra);
        } catch (Throwable $e) {
            // ignore
        }
    }
    if ($orgId > 0 && function_exists('org_shop_notify_buyer_payment_incomplete')) {
        try {
            org_shop_notify_buyer_payment_incomplete(
                $dbh,
                $orgId,
                $meId,
                $orderCode,
                $amountPaidCents,
                $requiredCents,
                $currency
            );
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

    echo json_encode([
        'ok' => true,
        'stripe' => false,
        'test_cost' => true,
        'payment_complete' => false,
        'message' => 'Order placed, but payment is incomplete. You paid '
            . $paidLabel . ' of ' . $dueLabel
            . '. Shipping will not start until the remaining ' . $shortLabel . ' is paid.',
        'order_id' => $orderId,
        'order_code' => $orderCode,
        'total_cents' => $requiredCents,
        'amount_paid_cents' => $amountPaidCents,
        'shortfall_cents' => $shortfallCents,
        'currency' => $currency,
    ]);
    exit;
}

$totalLabel = org_shop_format_price($totalCents, $currency);

if ($paymentMethod === 'manual' && $orderId > 0) {
    try {
        $dbh->prepare("
            UPDATE org_orders
            SET payment_method = 'manual',
                updated_at = NOW()
            WHERE id = :id
            LIMIT 1
        ")->execute([':id' => $orderId]);
    } catch (Throwable $e) {
        // ignore
    }
}

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
