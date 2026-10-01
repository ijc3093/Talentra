<?php
declare(strict_types=1);

/**
 * JSON product page for the mobile Shop detail screen.
 * Same product, gallery, price, stock, tabs, and seller as product_detail.php.
 */

require_once __DIR__ . '/../includes/session_user.php';
require_once __DIR__ . '/../controller.php';
require_once __DIR__ . '/../includes/org_shop.php';
require_once __DIR__ . '/../includes/stripe_shop.php';
require_once __DIR__ . '/../includes/org_wishlist.php';
require_once __DIR__ . '/../includes/commerce_messaging.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $controller = new Controller();
    $dbh = $controller->pdo();
    $meId = (int)($_SESSION['user_id'] ?? $_SESSION['id'] ?? $_SESSION['userid'] ?? 0);
    $productId = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

    require_once __DIR__ . '/../includes/shop_filter_context.php';

    $product = org_shop_get_marketplace_product($dbh, $productId);
    if ($product === null) {
        echo json_encode(['ok' => false, 'message' => 'Product not found.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $priceCents = (int)($product['price_cents'] ?? 0);
    $currency = strtoupper((string)($product['currency'] ?? 'USD'));
    $publisherId = (int)($product['publisher_user_id'] ?? 0);
    $sellerLabel = trim((string)($product['publisher_name'] ?? ''))
        ?: trim((string)($product['publisher_username'] ?? ''))
        ?: trim((string)($product['seller_name'] ?? 'Shop'));
    $stock = $product['stock_qty'];
    $outOfStock = ($stock !== null && $stock !== '' && (int)$stock <= 0);
    $stockCount = ($stock !== null && $stock !== '') ? (int)$stock : null;
    $sku = trim((string)($product['sku'] ?? ''));
    $productCode = trim((string)($product['product_code'] ?? ''));
    if ($productCode === '') {
        $productCode = org_shop_ensure_product_code($dbh, (int)($product['org_id'] ?? 0), $productId, '');
    }
    $category = trim((string)($product['category'] ?? ''));
    $description = trim((string)($product['description'] ?? ''));
    $deliveryByLong = (new DateTimeImmutable('now'))->modify('+3 days')->format('F j');
    $installmentCents = (int)max(1, (int)round($priceCents / 4));
    $installmentLabel = org_shop_format_price($installmentCents, $currency);
    $ratingStats = org_shop_product_rating_stats($dbh, $productId);
    $reviewCount = (int)($ratingStats['count'] ?? 0);
    $ratingValue = $reviewCount > 0 ? (float)($ratingStats['rating'] ?? 0) : 0.0;
    $rating = $reviewCount > 0
        ? max(1, min(5, (int)round($ratingValue)))
        : shop_product_rating($productId);
    if ($reviewCount <= 0) {
        $reviewCount = 3 + ($productId % 8);
    }
    $bulletPoints = org_shop_parse_bullet_points((string)($product['bullet_points'] ?? ''));
    $sellingType = trim((string)($product['selling_type'] ?? ''));
    $productFacts = org_product_type_buyer_facts(
        isset($product['attributes_json']) ? (string)$product['attributes_json'] : null,
        $sellingType,
        8
    );
    $productFulfillment = strtolower(trim((string)($product['fulfillment_method'] ?? 'fbm')));
    if (!in_array($productFulfillment, ['fba', 'fbm'], true)) {
        $productFulfillment = 'fbm';
    }
    $fulfillmentLabel = $productFulfillment === 'fba' ? 'FBA (platform warehouse)' : 'FBM (seller ships)';
    $typeLabel = trim((string)($productFacts['type_label'] ?? ''));
    if ($typeLabel === '') {
        $typeLabel = $sellingType;
    }
    $condition = trim((string)($productFacts['condition'] ?? ''));

    $specs = [];
    if ($productCode !== '') {
        $specs[] = ['label' => 'ID', 'value' => $productCode];
    }
    if ($typeLabel !== '') {
        $specs[] = ['label' => 'Product type', 'value' => $typeLabel];
    }
    if ($category !== '') {
        $specs[] = ['label' => 'Category', 'value' => $category];
    }
    if ($sku !== '') {
        $specs[] = ['label' => 'SKU', 'value' => $sku];
    }
    foreach (($productFacts['rows'] ?? []) as $specRow) {
        if (!is_array($specRow)) {
            continue;
        }
        $label = trim((string)($specRow['label'] ?? ''));
        $value = trim((string)($specRow['value'] ?? ''));
        if ($label === '' || $value === '') {
            continue;
        }
        $specs[] = ['label' => $label, 'value' => $value];
    }
    $specs[] = ['label' => 'Fulfillment', 'value' => $fulfillmentLabel];

    $sellerOrgId = (int)($product['org_id'] ?? 0);
    $sellerPublicInfo = $sellerOrgId > 0
        ? org_shop_seller_pickup_display($dbh, $sellerOrgId)
        : ['store_name' => '', 'full_name' => '', 'tagline' => '', 'address' => '', 'phone' => '', 'email' => ''];
    $sellerDisplayName = trim((string)($sellerPublicInfo['store_name'] ?? ''));
    if ($sellerDisplayName === '') {
        $sellerDisplayName = $sellerLabel;
    }
    $sellerUsername = trim((string)($product['publisher_username'] ?? ''));
    if ($sellerUsername === '' && $publisherId > 0 && function_exists('org_shop_user_username')) {
        $sellerUsername = org_shop_user_username($dbh, $publisherId);
    }
    $messageURL = $publisherId > 0 ? commerce_message_seller_url($publisherId, $productId) : '';
    $sellerFriendCode = '';
    if ($publisherId > 0) {
        try {
            $stFC = $dbh->prepare('SELECT friend_code FROM users WHERE id = :id LIMIT 1');
            $stFC->execute([':id' => $publisherId]);
            $sellerFriendCode = strtoupper(trim((string)($stFC->fetchColumn() ?: '')));
        } catch (Throwable $e) {
            $sellerFriendCode = '';
        }
    }
    $brandColor = trim((string)($product['commerce_brand_color'] ?? '#2563eb'));
    $brandIcon = trim((string)($product['commerce_brand_icon'] ?? ''));
    if ($brandIcon === '' && $sellerDisplayName !== '') {
        $brandIcon = mb_substr($sellerDisplayName, 0, 1);
    }

    $stripeOn = stripe_shop_is_configured();
    $guarantee = [
        $stripeOn
            ? 'Secure checkout powered by Stripe. Your payment is processed safely and your order is confirmed by the seller.'
            : 'Orders are placed directly with the seller, who confirms payment and fulfillment.',
        'Delivery estimate: ' . $deliveryByLong . ' · Pay in 4 installments of ' . $installmentLabel . '.',
    ];

    $gallery = org_shop_product_gallery_urls($dbh, $product);
    $cover = org_shop_cover_url((string)($product['cover_image_path'] ?? ''));
    if (!$gallery && $cover !== '') {
        $gallery = [$cover];
    }

    $shipping = org_shop_product_shipping_badge($dbh, $product);
    if ($shipping['mode'] === 'free') {
        $shippingLead = 'Free delivery by ' . $deliveryByLong;
        $shippingBadge = 'Free delivery';
    } elseif ($shipping['mode'] === 'pickup') {
        $shippingLead = $shipping['pickup_address'] !== ''
            ? 'Pick up at ' . $shipping['pickup_address']
            : 'Pick up only';
        $shippingBadge = 'Pick up only';
    } else {
        $shippingLead = ($shipping['shipping_fee_label'] !== '' ? $shipping['shipping_fee_label'] . ' shipping' : 'Shipping at checkout')
            . ' · delivery by ' . $deliveryByLong;
        $shippingBadge = $shipping['shipping_fee_label'] !== '' ? $shipping['shipping_fee_label'] . ' shipping' : 'Paid shipping';
    }
    $stockNote = $outOfStock
        ? 'Out of stock — check back later.'
        : ($shippingLead . ($stockCount !== null ? ' · ' . $stockCount . ' in stock' : ''));

    $wishlistSaved = ($meId > 0) ? org_wishlist_has($dbh, $meId, $productId) : false;

    // Clean bullet prefixes so clients can render their own markers.
    $bulletsClean = [];
    foreach ($bulletPoints as $bp) {
        $line = trim((string)$bp);
        $line = preg_replace('/^[\*\x{2022}\-–—]+\s*/u', '', $line) ?? $line;
        if ($line !== '') {
            $bulletsClean[] = $line;
        }
    }

    // Buyer's non-cancelled purchase for this product (Invoice banner on product_detail.php).
    $buyerOrderPayload = null;
    if ($meId > 0 && $productId > 0) {
        foreach (org_shop_list_buyer_orders($dbh, $meId, 100) as $buyerOrderRow) {
            if ((int)($buyerOrderRow['product_id'] ?? 0) !== $productId) {
                continue;
            }
            $buyerOrderStatus = strtolower(trim((string)($buyerOrderRow['status'] ?? '')));
            if ($buyerOrderStatus === 'cancelled') {
                continue;
            }
            $statusLabel = ucwords(str_replace('_', ' ', $buyerOrderStatus !== '' ? $buyerOrderStatus : 'pending'));
            $orderCode = trim((string)($buyerOrderRow['order_code'] ?? ''));
            $orderId = (int)($buyerOrderRow['id'] ?? 0);
            if ($orderCode === '' && $orderId > 0) {
                $orderCode = '#' . $orderId;
            }
            $buyerOrderPayload = [
                'id' => $orderId,
                'order_code' => $orderCode,
                'status' => $buyerOrderStatus !== '' ? $buyerOrderStatus : 'pending',
                'status_label' => $statusLabel,
            ];
            break;
        }
    }

    // Include Condition in specs (same as product_detail.php Details table).
    if ($condition !== '') {
        $hasCondition = false;
        foreach ($specs as $specRow) {
            if (strcasecmp((string)($specRow['label'] ?? ''), 'Condition') === 0) {
                $hasCondition = true;
                break;
            }
        }
        if (!$hasCondition) {
            array_splice($specs, min(1, count($specs)), 0, [['label' => 'Condition', 'value' => $condition]]);
        }
    }

    echo json_encode([
        'ok' => true,
        'signed_in' => $meId > 0,
        'product' => [
            'id' => $productId,
            'title' => (string)($product['title'] ?? 'Product'),
            'product_code' => $productCode,
            'price_cents' => $priceCents,
            'currency' => $currency,
            'rating' => $rating,
            'rating_value' => $ratingValue,
            'review_count' => $reviewCount,
            'review_label' => 'Based on ' . $reviewCount . ' review' . ($reviewCount === 1 ? '' : 's'),
            'type_label' => $typeLabel,
            'condition' => $condition,
            'in_stock' => !$outOfStock,
            'stock_count' => $stockCount,
            'stock_note' => $stockNote,
            'description' => $description !== '' ? $description : ('Discover ' . (string)($product['title'] ?? 'this product') . ' from ' . $sellerLabel . '.'),
            'bullets' => array_values($bulletsClean),
            'specs' => $specs,
            'guarantee' => $guarantee,
            'fulfillment' => $productFulfillment,
            'category' => $category,
            'seller_name' => $sellerDisplayName,
            'seller' => [
                'name' => $sellerDisplayName,
                'tagline' => trim((string)($sellerPublicInfo['tagline'] ?? '')),
                'full_name' => trim((string)($sellerPublicInfo['full_name'] ?? '')),
                'username' => $sellerUsername,
                'phone' => trim((string)($sellerPublicInfo['phone'] ?? '')),
                'address' => trim((string)($sellerPublicInfo['address'] ?? '')),
                'email' => trim((string)($sellerPublicInfo['email'] ?? '')),
                'publisher_id' => $publisherId,
                'friend_code' => $sellerFriendCode,
                'message_path' => $messageURL,
                'color' => $brandColor !== '' ? $brandColor : '#2563eb',
                'icon' => $brandIcon,
            ],
            'store' => [
                'intro' => 'Browse more in the marketplace or filter by brand and category.',
                'brand_label' => 'View ' . $sellerLabel . ' in shop',
                'brand' => $sellerLabel,
                'category' => $category,
                'category_label' => $category !== '' ? ($category . ' category') : '',
            ],
            'free_shipping' => $shipping['free_shipping'],
            'shipping_mode' => $shipping['mode'],
            'shipping_fee_cents' => $shipping['shipping_fee_cents'],
            'shipping_fee_label' => $shipping['shipping_fee_label'],
            'pickup_enabled' => $shipping['pickup_enabled'],
            'pickup_only' => $shipping['pickup_only'],
            'pickup_address' => $shipping['pickup_address'],
            'badges' => [
                $shippingBadge,
                'Secure checkout',
                $outOfStock ? 'Out of stock' : 'In stock',
                'Sold by seller',
            ],
            'gallery' => array_values($gallery),
            'wishlist_saved' => $wishlistSaved,
            'publisher_id' => $publisherId,
            'buyer_order' => $buyerOrderPayload,
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Unable to load this product.']);
}
