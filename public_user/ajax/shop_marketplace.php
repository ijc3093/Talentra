<?php
declare(strict_types=1);

/**
 * JSON catalog for the mobile Shop screen.
 * Same marketplace query, filters, and cart as public_user/shop.php.
 */

require_once __DIR__ . '/../includes/session_user.php';
require_once __DIR__ . '/../controller.php';
require_once __DIR__ . '/../includes/org_shop.php';
require_once __DIR__ . '/../includes/org_cart.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $controller = new Controller();
    $dbh = $controller->pdo();
    $meId = (int)($_SESSION['user_id'] ?? $_SESSION['id'] ?? $_SESSION['userid'] ?? 0);

    require_once __DIR__ . '/../includes/shop_filter_context.php';
    $products = $shopAllProducts;

    if ($shopSearchQ !== '') {
        $shopSearchNeedle = mb_strtolower($shopSearchQ);
        $products = array_values(array_filter($products, static function (array $p) use ($shopSearchNeedle): bool {
            $haystack = mb_strtolower(implode(' ', [
                (string)($p['title'] ?? ''),
                (string)($p['sku'] ?? ''),
                (string)($p['category'] ?? ''),
                (string)($p['selling_type'] ?? ''),
                (string)($p['description'] ?? ''),
                (string)($p['bullet_points'] ?? ''),
                (string)($p['search_keywords'] ?? ''),
                (string)($p['attributes_json'] ?? ''),
                (string)($p['publisher_name'] ?? ''),
                (string)($p['publisher_username'] ?? ''),
                (string)($p['seller_name'] ?? ''),
                (string)($p['commerce_brand_name'] ?? ''),
            ]));
            return strpos($haystack, $shopSearchNeedle) !== false;
        }));
    }

    if ($shopHasFilters) {
        $products = array_values(array_filter($products, static function (array $p) use (
            $dbh,
            $shopFilterPickup,
            $shopFilterBrand,
            $shopFilterCommerceBrand,
            $shopFilterPrice,
            $shopFilterRating,
            $shopFilterType,
            $shopLocationActive,
            $shopBuyerLocation
        ): bool {
            $stock = $p['stock_qty'];
            $inStock = !($stock !== null && $stock !== '' && (int)$stock <= 0);
            if ($shopFilterPickup && !$inStock) {
                return false;
            }
            if ($shopFilterCommerceBrand !== '') {
                $cslug = trim((string)($p['commerce_brand_slug'] ?? ''));
                if ($cslug === '' || strcasecmp($cslug, $shopFilterCommerceBrand) !== 0) {
                    return false;
                }
            }
            $brand = shop_product_brand($p);
            if ($shopFilterBrand !== '' && strcasecmp($brand, $shopFilterBrand) !== 0) {
                return false;
            }
            $pickupOnly = !empty($p['pickup_enabled']) && empty($p['delivery_enabled']);
            if (
                $pickupOnly
                && $shopLocationActive
                && $shopFilterCommerceBrand === ''
                && !shop_location_product_in_range($p, $shopBuyerLocation)
            ) {
                return false;
            }
            if ($shopFilterType !== '') {
                $pCategory = trim((string)($p['category'] ?? ''));
                $pSellingType = trim((string)($p['selling_type'] ?? ''));
                if (
                    strcasecmp($pCategory, $shopFilterType) !== 0
                    && strcasecmp($pSellingType, $shopFilterType) !== 0
                ) {
                    return false;
                }
            }
            $priceCents = (int)($p['price_cents'] ?? 0);
            if ($shopFilterPrice === 'under10' && $priceCents >= 1000) {
                return false;
            }
            if ($shopFilterPrice === '10-25' && ($priceCents < 1000 || $priceCents > 2500)) {
                return false;
            }
            if ($shopFilterPrice === '25-50' && ($priceCents < 2500 || $priceCents > 5000)) {
                return false;
            }
            if ($shopFilterPrice === '50plus' && $priceCents < 5000) {
                return false;
            }
            if ($shopFilterRating !== '') {
                $minRating = (int)$shopFilterRating;
                if ($minRating > 0 && shop_product_rating((int)($p['id'] ?? 0)) < $minRating) {
                    return false;
                }
            }
            return true;
        }));
    }

    $sort = strtolower(trim((string)($_GET['sort'] ?? 'relevant')));
    if ($sort === 'price_asc') {
        usort($products, static fn(array $a, array $b): int => ((int)($a['price_cents'] ?? 0)) <=> ((int)($b['price_cents'] ?? 0)));
    } elseif ($sort === 'price_desc') {
        usort($products, static fn(array $a, array $b): int => ((int)($b['price_cents'] ?? 0)) <=> ((int)($a['price_cents'] ?? 0)));
    }

    $perPage = (int)($_GET['per_page'] ?? 120);
    if ($perPage < 1 || $perPage > 120) {
        $perPage = 120;
    }
    $page = max(1, (int)($_GET['page'] ?? 1));
    $total = count($products);
    $pageCount = max(1, (int)ceil($total / $perPage));
    $page = min($page, $pageCount);
    $slice = array_slice($products, ($page - 1) * $perPage, $perPage);

    $mapped = [];
    foreach ($slice as $row) {
        $mapped[] = shop_marketplace_map_product($dbh, $row);
    }

    $cartCount = $meId > 0 ? org_cart_count($dbh, $meId) : 0;
    $cartItems = [];
    if ($meId > 0) {
        foreach (org_cart_list_items($dbh, $meId) as $row) {
            $cartItems[] = shop_marketplace_map_cart_item($dbh, $row);
        }
    }

    echo json_encode([
        'ok' => true,
        'signed_in' => $meId > 0,
        'total' => $total,
        'page' => $page,
        'per_page' => $perPage,
        'page_count' => $pageCount,
        'sort' => $sort,
        'query' => $shopSearchQ,
        'type' => $shopFilterType,
        'categories' => [
            ['id' => 'all', 'title' => 'All', 'type' => ''],
            ['id' => 'electronics', 'title' => 'Electronics', 'type' => 'Electronics'],
            ['id' => 'fashion', 'title' => 'Fashion', 'type' => 'Fashion'],
            ['id' => 'homeLiving', 'title' => 'Home & Living', 'type' => 'Home & Living'],
            ['id' => 'beauty', 'title' => 'Beauty', 'type' => 'Beauty'],
            ['id' => 'health', 'title' => 'Health', 'type' => 'Health'],
            ['id' => 'sports', 'title' => 'Sports', 'type' => 'Sports'],
            ['id' => 'toys', 'title' => 'Toys & Games', 'type' => 'Toys & Games'],
            ['id' => 'books', 'title' => 'Books', 'type' => 'Books'],
            ['id' => 'automotive', 'title' => 'Automotive', 'type' => 'Automotive'],
            ['id' => 'more', 'title' => 'More', 'type' => ''],
        ],
        'brands' => array_values(array_map(static function ($brand): array {
            if (!is_array($brand)) {
                return ['name' => (string)$brand, 'slug' => '', 'color' => '#2563eb'];
            }
            return [
                'name' => (string)($brand['name'] ?? ''),
                'slug' => (string)($brand['slug'] ?? ''),
                'color' => (string)($brand['accent_color'] ?? '#2563eb'),
                'icon' => (string)($brand['icon_letter'] ?? ''),
            ];
        }, $shopCommerceBrandsNav ?? [])),
        'cart_count' => $cartCount,
        'cart' => $cartItems,
        'products' => $mapped,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'message' => 'Unable to load the shop.',
    ]);
}

function shop_marketplace_seller(array $p): string
{
    return trim((string)($p['commerce_brand_name'] ?? ''))
        ?: trim((string)($p['publisher_name'] ?? ''))
        ?: trim((string)($p['publisher_username'] ?? ''))
        ?: trim((string)($p['seller_name'] ?? ''))
        ?: 'Shop';
}

function shop_marketplace_map_product(PDO $dbh, array $p): array
{
    $id = (int)($p['id'] ?? 0);
    $stats = function_exists('org_shop_product_rating_stats')
        ? org_shop_product_rating_stats($dbh, $id)
        : ['rating' => 0.0, 'count' => 0];
    $reviewCount = (int)($stats['count'] ?? 0);
    $rating = $reviewCount > 0
        ? (float)($stats['rating'] ?? 0)
        : (float)org_shop_product_display_rating($dbh, $id);
    $currency = (string)($p['currency'] ?? 'USD');
    $priceCents = (int)($p['price_cents'] ?? 0);
    $description = trim((string)($p['description'] ?? ''));
    if ($description === '') {
        $description = trim((string)($p['category'] ?? ''));
    }
    $shipping = org_shop_product_shipping_badge($dbh, $p);

    return [
        'id' => $id,
        'title' => (string)($p['title'] ?? 'Product'),
        'description' => $description,
        'price_label' => org_shop_format_price($priceCents, $currency),
        'price_cents' => $priceCents,
        'currency' => $currency,
        'seller' => shop_marketplace_seller($p),
        'seller_color' => trim((string)($p['commerce_brand_color'] ?? '#2563eb')) ?: '#2563eb',
        'publisher_user_id' => (int)($p['publisher_user_id'] ?? 0),
        'category' => trim((string)($p['category'] ?? '')),
        'selling_type' => trim((string)($p['selling_type'] ?? '')),
        'cover_url' => org_shop_cover_url((string)($p['cover_image_path'] ?? '')),
        'rating' => $rating,
        'review_count' => $reviewCount,
        'free_shipping' => $shipping['free_shipping'],
        'shipping_mode' => $shipping['mode'],
        'shipping_fee_cents' => $shipping['shipping_fee_cents'],
        'shipping_fee_label' => $shipping['shipping_fee_label'],
        'pickup_enabled' => $shipping['pickup_enabled'],
        'pickup_only' => $shipping['pickup_only'],
        'pickup_address' => $shipping['pickup_address'],
        'sku' => trim((string)($p['sku'] ?? '')),
    ];
}

function shop_marketplace_map_cart_item(PDO $dbh, array $row): array
{
    $shipping = org_shop_product_shipping_badge($dbh, $row);
    $currency = (string)($row['currency'] ?? 'USD');
    $priceCents = (int)($row['price_cents'] ?? 0);
    $description = trim((string)($row['description'] ?? ''));
    return [
        'id' => (int)($row['product_id'] ?? 0),
        'title' => (string)($row['title'] ?? 'Product'),
        'description' => $description,
        'seller' => shop_marketplace_seller($row),
        'price_cents' => $priceCents,
        'price_label' => org_shop_format_price($priceCents, $currency),
        'quantity' => max(1, (int)($row['quantity'] ?? 1)),
        'cover_url' => org_shop_cover_url((string)($row['cover_image_path'] ?? '')),
        'free_shipping' => $shipping['free_shipping'],
        'shipping_mode' => $shipping['mode'],
        'shipping_fee_cents' => $shipping['shipping_fee_cents'],
        'shipping_fee_label' => $shipping['shipping_fee_label'],
        'pickup_only' => $shipping['pickup_only'],
        'pickup_address' => $shipping['pickup_address'],
        'category' => trim((string)($row['category'] ?? '')),
        // Web cart.php data-profile-id — needed for shop_buy.php profile_id.
        'publisher_user_id' => (int)($row['publisher_user_id'] ?? 0),
        'publisher_id' => (int)($row['publisher_user_id'] ?? 0),
        'stock_qty' => isset($row['stock_qty']) ? (int)$row['stock_qty'] : null,
    ];
}
