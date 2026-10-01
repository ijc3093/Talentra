<?php
declare(strict_types=1);

/**
 * Buyer order detail JSON — same tabs as order_detail.php (Order / Payment / Seller).
 */

require_once __DIR__ . '/../includes/session_user.php';
require_once __DIR__ . '/../controller.php';
require_once __DIR__ . '/../includes/org_shop.php';

header('Content-Type: application/json; charset=utf-8');


function shop_order_detail_track_url(string $carrier, string $tracking): string
{
    $tracking = trim($tracking);
    if ($tracking === '') {
        return '';
    }
    $carrier = strtolower(trim($carrier));
    if (str_contains($carrier, 'ups')) {
        return 'https://www.ups.com/track?tracknum=' . rawurlencode($tracking);
    }
    if (str_contains($carrier, 'usps') || str_contains($carrier, 'postal')) {
        return 'https://tools.usps.com/go/TrackConfirmAction?tLabels=' . rawurlencode($tracking);
    }
    if (str_contains($carrier, 'fedex')) {
        return 'https://www.fedex.com/fedextrack/?trknbr=' . rawurlencode($tracking);
    }
    return 'https://www.google.com/search?q=' . rawurlencode('track package ' . trim($carrier . ' ' . $tracking));
}


try {
    $controller = new Controller();
    $dbh = $controller->pdo();
    $meId = (int)($_SESSION['user_id'] ?? $_SESSION['id'] ?? $_SESSION['userid'] ?? 0);
    if ($meId <= 0) {
        $shippedAtLabel = '';
    if ($shippedAt !== '') {
        $tShipLabel = strtotime($shippedAt);
        $shippedAtLabel = $tShipLabel ? date('D, M j, Y', $tShipLabel) : $fmtShort($shippedAt);
    }

    echo json_encode(['ok' => false, 'message' => 'Please sign in.']);
        exit;
    }

    $orderId = (int)($_GET['order_id'] ?? $_POST['order_id'] ?? 0);
    $orderCode = trim((string)($_GET['order_code'] ?? $_POST['order_code'] ?? ''));
    $order = null;
    if ($orderId > 0) {
        $order = org_shop_get_buyer_order($dbh, $meId, $orderId);
    } elseif ($orderCode !== '') {
        $order = org_shop_find_buyer_order_by_code($dbh, $meId, $orderCode);
    }
    if ($order === null) {
        echo json_encode(['ok' => false, 'message' => 'Order not found.']);
        exit;
    }

    $orderId = (int)($order['id'] ?? 0);
    $status = strtolower(trim((string)($order['status'] ?? '')));
    $currency = strtoupper((string)($order['currency'] ?? 'USD'));
    $qty = max(1, (int)($order['quantity'] ?? 1));
    $unitCents = (int)($order['unit_price_cents'] ?? 0);
    $totalCents = (int)($order['total_cents'] ?? 0);
    $productId = (int)($order['product_id'] ?? 0);
    $createdAt = (string)($order['created_at'] ?? '');
    $paidAt = (string)($order['paid_at'] ?? '');
    $shippedAt = (string)($order['shipped_at'] ?? '');
    $deliveredAt = (string)($order['delivered_at'] ?? '');
    $tracking = trim((string)($order['tracking_number'] ?? ''));
    $carrier = trim((string)($order['carrier'] ?? ''));
    if ($tracking !== '' && $carrier !== '' && !in_array($status, ['shipped', 'delivered'], true)) {
        $status = 'shipped';
    }

    $seller = trim((string)($order['seller_name'] ?? 'Shop'));
    $cover = org_shop_cover_url((string)($order['cover_image_path'] ?? ''));
    $category = trim((string)($order['category'] ?? 'Product'));
    $receiptCode = trim((string)($order['receipt_code'] ?? ''));
    if ($receiptCode === '' && $orderId > 0) {
        $receiptCode = 'RCP-' . strtoupper(substr(md5((string)$orderId), 0, 2)) . '-' . strtoupper(substr(md5((string)$orderId . 'r'), 0, 8));
    }

    $fmtLong = static function (?string $dt): string {
        if (!$dt) {
            return '—';
        }
        $t = strtotime($dt);
        return $t ? date('M j, Y \a\t g:i A', $t) : '—';
    };
    $fmtShort = static function (?string $dt): string {
        if (!$dt) {
            return '';
        }
        $t = strtotime($dt);
        return $t ? date('M j', $t) : '';
    };
    $fmtPay = static function (?string $dt): string {
        if (!$dt) {
            return '';
        }
        $t = strtotime($dt);
        return $t ? date('M j \a\t g:i A', $t) : '';
    };

    $headline = 'Order in progress';
    if ($status === 'delivered') {
        $headline = 'Delivered';
    } elseif ($status === 'shipped') {
        $shipWhen = $fmtLong($shippedAt);
        if ($shipWhen !== '—' && $shippedAt !== '') {
            $tShip = strtotime($shippedAt);
            $headline = $tShip ? ('Shipped on ' . date('D, M j, Y', $tShip)) : 'Shipped — on the way';
        } else {
            $headline = 'Shipped — on the way';
        }
    } elseif ($status === 'paid') {
        $headline = 'Paid — preparing shipment';
    } elseif ($status === 'cancelled') {
        $headline = 'Order cancelled';
    } elseif ($status === 'pending' || $status === '') {
        $headline = 'Awaiting seller payment confirmation';
    }

    $paidDone = in_array($status, ['paid', 'shipped', 'delivered'], true);
    $trackDone = in_array($status, ['shipped', 'delivered'], true) || $tracking !== '';
    $delivDone = ($status === 'delivered');

    $discountCents = max(0, (int)($order['discount_cents'] ?? 0));
    $merchCents = max(0, $unitCents * $qty);
    $itemCents = max(0, $merchCents - $discountCents);
    $discountPercent = 0.0;
    if ($merchCents > 0 && $discountCents > 0) {
        $discountPercent = round(($discountCents / $merchCents) * 100, 2);
    }
    $promoCode = strtoupper(trim((string)($order['promo_code'] ?? '')));
    if ($promoCode !== '' && $discountPercent <= 0) {
        $sellerOrgId = (int)($order['publisher_org_id'] ?? $order['org_id'] ?? 0);
        if ($sellerOrgId <= 0 && function_exists('org_shop_get_product')) {
            $prod = org_shop_get_product($dbh, $productId);
            $sellerOrgId = (int)($prod['org_id'] ?? 0);
        }
        if ($sellerOrgId > 0 && function_exists('org_shop_promo_percent')) {
            $discountPercent = (float)org_shop_promo_percent($dbh, $sellerOrgId, $promoCode);
        }
    }
    $taxCents = max(0, (int)($order['tax_cents'] ?? 0));
    $shippingCents = max(0, (int)($order['shipping_fee_cents'] ?? 0));
    $serviceFeeCents = max(0, (int)($order['service_fee_cents'] ?? 0));
    if ($serviceFeeCents <= 0 && $totalCents > ($itemCents + $shippingCents + $taxCents)) {
        $leftover = max(0, $totalCents - $itemCents - $shippingCents - $taxCents);
        if ($leftover === org_shop_buyer_service_fee_cents($dbh, $meId)) {
            $serviceFeeCents = $leftover;
        }
    }
    if ($taxCents <= 0 && $totalCents > ($itemCents + $shippingCents + $serviceFeeCents)) {
        $taxCents = max(0, $totalCents - $itemCents - $shippingCents - $serviceFeeCents);
    }

    $taxRate = function_exists('org_shop_sales_tax_rate') ? (float)org_shop_sales_tax_rate() : 0.0825;

    $payMethod = strtolower(trim((string)($order['payment_method'] ?? '')));
    $payLabel = 'Manual payment';
    if ($payMethod === 'test_cost') {
        $payLabel = 'Manual payment';
    } elseif ($payMethod === 'stripe') {
        $payLabel = 'Card payment';
    } elseif ($payMethod !== '') {
        $payLabel = ucwords(str_replace('_', ' ', $payMethod));
    }

    $buyerName = trim((string)($order['buyer_name'] ?? ''));
    $shipAddr = trim((string)($order['delivery_address'] ?? ''));
    $addrLines = array_values(array_filter(array_map('trim', preg_split('/\r\n|\n|\r/', $shipAddr) ?: [])));

    echo json_encode([
        'ok' => true,
        'order' => [
            'id' => $orderId,
            'order_code' => (string)($order['order_code'] ?? ''),
            'status' => $status,
            'delivery_headline' => $headline,
            'time_placed' => $fmtLong($createdAt),
            'time_placed_short' => $fmtShort($createdAt !== '' ? $createdAt : $paidAt),
            'paid_at_label' => $fmtPay($paidAt !== '' ? $paidAt : $createdAt),
            'quantity' => $qty,
            'total_cents' => $totalCents,
            'total_label' => org_shop_format_price($totalCents, $currency),
            'item_label' => $qty === 1 ? '1 item' : ($qty . ' items'),
            'seller' => $seller,
            'seller_email' => trim((string)($order['seller_email'] ?? '')),
            'seller_phone' => trim((string)($order['seller_phone'] ?? '')),
            'seller_address' => trim((string)($order['seller_address'] ?? '')),
            'publisher_id' => (int)($order['publisher_user_id'] ?? 0),
            'product_id' => $productId,
            'product_title' => (string)($order['product_title'] ?? ''),
            'product_category' => $category,
            'cover_url' => $cover,
            'unit_price_label' => org_shop_format_price($unitCents, $currency),
            'steps' => [
                ['id' => 'paid', 'label' => 'Paid', 'done' => $paidDone, 'date' => $paidDone ? $fmtShort($createdAt) : ''],
                ['id' => 'tracking', 'label' => 'Tracking available', 'done' => $trackDone, 'date' => $trackDone ? $fmtShort($shippedAt !== '' ? $shippedAt : $createdAt) : ''],
                ['id' => 'delivered', 'label' => 'Delivered', 'done' => $delivDone, 'date' => $delivDone ? $fmtShort($deliveredAt) : ''],
            ],
            'payment' => [
                'method_label' => $payLabel,
                'amount_label' => org_shop_format_price($totalCents, $currency),
                'paid_at_label' => $fmtPay($paidAt !== '' ? $paidAt : $createdAt),
                'buyer_name' => $buyerName,
                'address_lines' => $addrLines,
                'item_cents' => $merchCents,
                'item_label' => org_shop_format_price($merchCents, $currency),
                'discount_cents' => $discountCents,
                'discount_label' => $discountCents > 0
                    ? ('-' . org_shop_format_price($discountCents, $currency))
                    : org_shop_format_price(0, $currency),
                'discount_percent' => $discountPercent,
                'promo_code' => $promoCode,
                'shipping_cents' => $shippingCents,
                'shipping_label' => org_shop_format_price($shippingCents, $currency),
                'tax_cents' => $taxCents,
                'tax_label' => org_shop_format_price($taxCents, $currency),
                'tax_rate' => $taxRate,
                'service_fee_cents' => $serviceFeeCents,
                'service_fee_label' => org_shop_format_price($serviceFeeCents, $currency),
                'receipt_code' => $receiptCode,
            ],
            'tracking_number' => $tracking,
            'carrier' => $carrier,
            'track_url' => $tracking !== '' ? shop_order_detail_track_url($carrier, $tracking) : '',
            'shipped_at' => $shippedAt,
            'shipped_at_label' => $shippedAtLabel,
            'delivered_at' => $deliveredAt,
            'delivered_at_label' => $fmtShort($deliveredAt),
            'actions' => [
                'can_return' => in_array($status, ['delivered', 'shipped'], true),
                'can_cancel' => in_array($status, ['pending', 'paid'], true),
                'view_product_label' => $category !== '' ? ('View product · ' . $category) : 'View product',
            ],
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Unable to load order.']);
}
