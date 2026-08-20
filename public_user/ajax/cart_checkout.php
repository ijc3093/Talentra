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

$checkoutUrls = [];
$pendingPayments = 0;
$cancelUrl = stripe_shop_public_base_url() . '/cart.php?checkout=cancel';

if (stripe_shop_is_configured() && $orders !== []) {
    /** @var array<string, list<array{order_id:int,order_code:string,title:string,unit_cents:int,quantity:int,currency:string}>> $byCurrency */
    $byCurrency = [];
    foreach ($orders as $order) {
        $orderId = (int)($order['order_id'] ?? 0);
        $orderCode = (string)($order['order_code'] ?? '');
        $totalCents = (int)($order['total_cents'] ?? 0);
        $currency = strtoupper(trim((string)($order['currency'] ?? 'USD')) ?: 'USD');
        if ($orderId <= 0 || $totalCents <= 0) {
            continue;
        }
        $st = $dbh->prepare('SELECT product_title, quantity FROM org_orders WHERE id = :id LIMIT 1');
        $st->execute([':id' => $orderId]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $qty = max(1, (int)($row['quantity'] ?? 1));
        $unitForStripe = (int)max(1, (int)round($totalCents / $qty));
        if (!isset($byCurrency[$currency])) {
            $byCurrency[$currency] = [];
        }
        $byCurrency[$currency][] = [
            'order_id' => $orderId,
            'order_code' => $orderCode,
            'title' => (string)($row['product_title'] ?? ('Order ' . $orderCode)),
            'unit_cents' => $unitForStripe,
            'quantity' => $qty,
            'currency' => $currency,
        ];
    }

    foreach ($byCurrency as $currency => $lines) {
        if ($lines === []) {
            continue;
        }
        if (count($lines) === 1) {
            $line = $lines[0];
            $stripe = stripe_shop_create_checkout_session(
                (int)$line['order_id'],
                (string)$line['order_code'],
                (string)$line['title'],
                (int)$line['unit_cents'],
                (int)$line['quantity'],
                (string)$line['currency'],
                $meId,
                $cancelUrl
            );
            if (!empty($stripe['ok'])) {
                org_shop_attach_stripe_session($dbh, (int)$line['order_id'], (string)($stripe['session_id'] ?? ''));
                $url = trim((string)($stripe['checkout_url'] ?? ''));
                if ($url !== '') {
                    $checkoutUrls[] = $url;
                }
            } else {
                $pendingPayments += count($lines);
            }
            continue;
        }

        $stripe = stripe_shop_create_multi_order_checkout_session($lines, $meId, $cancelUrl);
        if (!empty($stripe['ok'])) {
            $sid = (string)($stripe['session_id'] ?? '');
            $url = trim((string)($stripe['checkout_url'] ?? ''));
            foreach ($lines as $line) {
                org_shop_attach_stripe_session($dbh, (int)$line['order_id'], $sid);
            }
            if ($url !== '') {
                $checkoutUrls[] = $url;
            }
        } else {
            $pendingPayments += count($lines);
        }
    }
}

$checkoutUrl = $checkoutUrls[0] ?? '';
$remainingUrls = array_values(array_slice($checkoutUrls, 1));

$message = count($codes) === 1
    ? 'Order placed! Code: ' . $codes[0]
    : count($codes) . ' orders placed: ' . implode(', ', $codes);

if ($warnings) {
    $message .= ' Some items could not be ordered.';
}
if (count($checkoutUrls) > 1) {
    $message .= ' You will complete ' . count($checkoutUrls) . ' secure checkouts (one per currency).';
} elseif ($checkoutUrl !== '' && count($orders) > 1) {
    $message .= ' Complete payment for all items in one checkout.';
}
if ($pendingPayments > 0) {
    $message .= ' Complete remaining payments from My Orders.';
}

echo json_encode([
    'ok' => true,
    'message' => $message,
    'order_codes' => $codes,
    'checkout_url' => $checkoutUrl,
    'checkout_urls' => $checkoutUrls,
    'remaining_checkout_urls' => $remainingUrls,
    'pending_payments' => $pendingPayments,
    'count' => (int)($result['count'] ?? 0),
]);
