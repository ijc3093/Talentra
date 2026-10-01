<?php
declare(strict_types=1);

/**
 * Buy-now preview quote — same numbers/options as shop_buy_door.php.
 */

require_once __DIR__ . '/../includes/session_user.php';
require_once __DIR__ . '/../controller.php';
require_once __DIR__ . '/../includes/org_shop.php';
require_once __DIR__ . '/../includes/stripe_shop.php';
require_once __DIR__ . '/../includes/buyer_shipping.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $controller = new Controller();
    $dbh = $controller->pdo();
    $meId = (int)($_SESSION['user_id'] ?? $_SESSION['id'] ?? $_SESSION['userid'] ?? 0);
    $productId = (int)($_GET['product_id'] ?? $_POST['product_id'] ?? 0);
    $quantity = max(1, min(99, (int)($_GET['quantity'] ?? $_POST['quantity'] ?? 1)));
    $deliveryOption = strtolower(trim((string)($_GET['delivery_option'] ?? $_POST['delivery_option'] ?? 'home_delivery')));
    if (!in_array($deliveryOption, ['pickup', 'home_delivery'], true)) {
        $deliveryOption = 'home_delivery';
    }

    $product = org_shop_get_marketplace_product($dbh, $productId);
    if ($product === null) {
        echo json_encode(['ok' => false, 'message' => 'Product not found.']);
        exit;
    }

    $priceCents = (int)($product['price_cents'] ?? 0);
    $currency = strtoupper((string)($product['currency'] ?? 'USD'));
    $merchandiseCents = org_shop_resolve_unit_price_cents($product, $quantity);
    $receive = org_shop_product_receive_options($product);
    if ($deliveryOption === 'pickup' && empty($receive['pickup_enabled'])) {
        $deliveryOption = !empty($receive['delivery_enabled']) ? 'home_delivery' : 'pickup';
    }
    if ($deliveryOption === 'home_delivery' && empty($receive['delivery_enabled'])) {
        $deliveryOption = !empty($receive['pickup_enabled']) ? 'pickup' : 'home_delivery';
    }

    $shippingCents = ($deliveryOption === 'pickup') ? 0 : max(0, (int)($receive['shipping_fee_cents'] ?? 0));
    $promoCode = strtoupper(trim((string)($_GET['promo_code'] ?? $_POST['promo_code'] ?? '')));
    $discountCents = 0;
    $promoValid = null;
    $discountPercent = 0.0;
    if ($promoCode !== '') {
        $sellerOrgIdForPromo = (int)($product['org_id'] ?? 0);
        $discountCents = org_shop_promo_discount_cents($dbh, $sellerOrgIdForPromo, $promoCode, $merchandiseCents);
        if ($discountCents < 0) {
            $promoValid = false;
            $discountCents = 0;
        } else {
            $promoValid = true;
            if (function_exists('org_shop_promo_percent')) {
                $discountPercent = (float)org_shop_promo_percent($dbh, $sellerOrgIdForPromo, $promoCode);
            }
            if ($discountPercent <= 0 && $merchandiseCents > 0 && $discountCents > 0) {
                $discountPercent = round(($discountCents / $merchandiseCents) * 100, 2);
            }
        }
    }
    $merchandiseAfterDiscount = max(0, $merchandiseCents - $discountCents);
    $taxableCents = $merchandiseAfterDiscount + $shippingCents;
    $taxCents = org_shop_sales_tax_cents($taxableCents);
    $serviceFeeCents = org_shop_buyer_service_fee_cents($dbh, $meId);
    $totalCents = $taxableCents + $taxCents + $serviceFeeCents;

    $sellerOrgId = (int)($product['org_id'] ?? 0);
    $sellerLabel = trim((string)($product['commerce_brand_name'] ?? ''))
        ?: trim((string)($product['publisher_name'] ?? ''))
        ?: trim((string)($product['publisher_username'] ?? ''))
        ?: trim((string)($product['seller_name'] ?? 'Shop'));
    $pickup = $sellerOrgId > 0 ? org_shop_seller_pickup_display($dbh, $sellerOrgId) : [];
    $pickupName = trim((string)($pickup['store_name'] ?? '')) ?: $sellerLabel;
    $pickupAddress = trim((string)($pickup['text'] ?? ''));
    if ($pickupAddress === '' && $sellerOrgId > 0) {
        $pickupAddress = org_shop_seller_pickup_address_text($dbh, $sellerOrgId);
    }

    $carrierHint = !empty($receive['carrier_labels'])
        ? implode(', ', $receive['carrier_labels'])
        : "Seller's own trip";
    if (!empty($receive['delivery_enabled'])) {
        $carrierHint .= $shippingCents > 0 || $deliveryOption === 'home_delivery'
            ? (' · ' . ($shippingCents > 0 ? org_shop_format_price(max(0, (int)($receive['shipping_fee_cents'] ?? 0)), $currency) : 'Free trip'))
            : '';
    }
    // Match buy-door delivery card label style used for this product.
    $deliveryCardSub = !empty($receive['carrier_labels'])
        ? (implode(', ', $receive['carrier_labels']) . ' · ' . org_shop_format_price(max(0, (int)($receive['shipping_fee_cents'] ?? 0)), $currency))
        : ("Seller's own trip · " . org_shop_format_price(max(0, (int)($receive['shipping_fee_cents'] ?? 0)), $currency));
    if ((int)($receive['shipping_fee_cents'] ?? 0) <= 0) {
        $deliveryCardSub = !empty($receive['carrier_labels'])
            ? (implode(', ', $receive['carrier_labels']) . ' · Free trip')
            : "Seller's own trip · Free trip";
    }

    $buyerAddress = '';
    $buyerPhone = '';
    $buyerName = '';
    if ($meId > 0) {
        $row = buyer_shipping_default_row($dbh, $meId);
        $buyerAddress = $row ? buyer_shipping_format_door_text($row) : '';
        $buyerPhone = $row ? trim((string)($row['phone'] ?? '')) : '';
        if ($buyerPhone === '') {
            $buyerPhone = buyer_shipping_default_phone($dbh, $meId);
        }
        if ($buyerPhone === '') {
            try {
                $st = $dbh->prepare('SELECT mobile, name FROM users WHERE id = :id LIMIT 1');
                $st->execute([':id' => $meId]);
                $u = $st->fetch(PDO::FETCH_ASSOC) ?: [];
                $buyerPhone = trim((string)($u['mobile'] ?? ''));
                $buyerName = trim((string)($u['name'] ?? ''));
            } catch (Throwable $e) {
            }
        } else {
            try {
                $st = $dbh->prepare('SELECT name FROM users WHERE id = :id LIMIT 1');
                $st->execute([':id' => $meId]);
                $buyerName = trim((string)($st->fetchColumn() ?: ''));
            } catch (Throwable $e) {
            }
        }
    }

    $stripeOn = stripe_shop_is_configured();
    $paymentMethods = [
        [
            'id' => 'test_cost',
            'label' => 'Cost $ (test)',
            'badge' => 'TEST',
            'sub' => 'Type the amount below. Pay less than the order total and the order stays Pending — shipping starts only after full payment.',
            'default' => false,
        ],
    ];
    if ($stripeOn) {
        $paymentMethods[] = [
            'id' => 'stripe',
            'label' => 'Pay with card',
            'badge' => '',
            'sub' => 'Secure checkout powered by Stripe.',
            'default' => true,
        ];
    } else {
        $paymentMethods[] = [
            'id' => 'manual',
            'label' => 'Pay seller directly',
            'badge' => 'Manual',
            'sub' => 'The seller confirms payment after you place the order.',
            'default' => true,
        ];
    }
    $defaultPay = 'manual';
    foreach ($paymentMethods as $pm) {
        if (!empty($pm['default'])) {
            $defaultPay = (string)$pm['id'];
            break;
        }
    }

    $fulfillment = strtolower(trim((string)($product['fulfillment_method'] ?? 'fbm')));
    if (!in_array($fulfillment, ['fba', 'fbm'], true)) {
        $fulfillment = 'fbm';
    }

    echo json_encode([
        'ok' => true,
        'signed_in' => $meId > 0,
        'product' => [
            'id' => $productId,
            'title' => (string)($product['title'] ?? 'Product'),
            'price_cents' => $priceCents,
            'price_label' => org_shop_format_price($priceCents, $currency),
            'currency' => $currency,
            'cover_url' => org_shop_cover_url((string)($product['cover_image_path'] ?? '')),
            'seller' => $sellerLabel,
            'fulfillment_method' => $fulfillment,
            'publisher_id' => (int)($product['publisher_user_id'] ?? 0),
        ],
        'quantity' => $quantity,
        'delivery_option' => $deliveryOption,
        'receive' => [
            'delivery_enabled' => !empty($receive['delivery_enabled']),
            'pickup_enabled' => !empty($receive['pickup_enabled']),
            'delivery_label' => $deliveryCardSub,
            'pickup_label' => $pickupName,
            'pickup_address' => $pickupAddress,
            'shipping_fee_cents' => max(0, (int)($receive['shipping_fee_cents'] ?? 0)),
        ],
        'buyer' => [
            'name' => $buyerName,
            'address' => $buyerAddress,
            'phone' => $buyerPhone !== '' ? $buyerPhone : 'N/A',
        ],
        'payment_methods' => $paymentMethods,
        'default_payment_method' => $defaultPay,
        'summary' => [
            'item_cents' => $merchandiseCents,
            'item_label' => org_shop_format_price($merchandiseCents, $currency),
            'discount_cents' => $discountCents,
            'discount_label' => $discountCents > 0
                ? ('-' . org_shop_format_price($discountCents, $currency))
                : org_shop_format_price(0, $currency),
            'discount_percent' => $discountPercent,
            'promo_code' => $promoCode,
            'promo_valid' => $promoValid,
            'shipping_cents' => $shippingCents,
            'shipping_label' => $shippingCents > 0 ? org_shop_format_price($shippingCents, $currency) : 'Free',
            'tax_cents' => $taxCents,
            'tax_label' => org_shop_format_price($taxCents, $currency),
            'taxable_cents' => $taxableCents,
            'taxable_label' => org_shop_format_price($taxableCents, $currency),
            'service_fee_cents' => $serviceFeeCents,
            'service_fee_label' => org_shop_format_price($serviceFeeCents, $currency),
            'total_cents' => $totalCents,
            'total_label' => org_shop_format_price($totalCents, $currency),
            'tax_rate' => org_shop_sales_tax_rate(),
            'currency' => $currency,
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Unable to load checkout.']);
}
