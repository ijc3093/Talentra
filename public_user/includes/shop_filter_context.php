<?php
declare(strict_types=1);

if (!isset($dbh) || !($dbh instanceof PDO)) {
    return;
}

require_once __DIR__ . '/org_shop.php';
require_once __DIR__ . '/org_commerce_brands.php';
require_once __DIR__ . '/shop_location.php';
require_once __DIR__ . '/buyer_shipping.php';

$shopAllProducts = org_shop_list_marketplace_products($dbh, 120);
$shopAllProducts = shop_location_attach_seller_addresses($dbh, $shopAllProducts);
$shopCommerceBrandsNav = org_commerce_brands_list_for_shop($dbh);
$shopRatingDbh = $dbh;

$shopMeIdForLoc = (int)($_SESSION['user_id'] ?? $_SESSION['id'] ?? $_SESSION['userid'] ?? 0);
$shopBuyerLocation = shop_location_seed_from_buyer($dbh, $shopMeIdForLoc);
// Lazy-geocode buyer location once so radius filtering can use coordinates.
if (
    (trim((string)($shopBuyerLocation['city'] ?? '')) !== '' || trim((string)($shopBuyerLocation['label'] ?? '')) !== '')
    && ($shopBuyerLocation['lat'] === null || $shopBuyerLocation['lng'] === null)
) {
    $geoQ = trim((string)($shopBuyerLocation['label'] ?? ''));
    if ($geoQ === '') {
        $geoQ = trim(($shopBuyerLocation['city'] ?? '') . ' ' . ($shopBuyerLocation['state'] ?? '') . ' ' . ($shopBuyerLocation['postal'] ?? ''));
    }
    $geo = shop_location_geocode_query($geoQ);
    if ($geo) {
        $shopBuyerLocation = shop_location_save_session(array_merge($shopBuyerLocation, [
            'lat' => $geo['lat'],
            'lng' => $geo['lng'],
            'city' => $shopBuyerLocation['city'] !== '' ? $shopBuyerLocation['city'] : $geo['city'],
            'state' => $shopBuyerLocation['state'] !== '' ? $shopBuyerLocation['state'] : $geo['state'],
            'country' => $shopBuyerLocation['country'] !== '' ? $shopBuyerLocation['country'] : $geo['country'],
            'label' => shop_location_format_label(
                $shopBuyerLocation['city'] !== '' ? $shopBuyerLocation['city'] : $geo['city'],
                $shopBuyerLocation['state'] !== '' ? $shopBuyerLocation['state'] : $geo['state'],
                $shopBuyerLocation['country'] !== '' ? $shopBuyerLocation['country'] : $geo['country']
            ),
        ]));
    }
}
$shopLocationSummary = shop_location_summary_text($shopBuyerLocation);
$shopLocationActive = trim((string)($shopBuyerLocation['city'] ?? '')) !== ''
    || trim((string)($shopBuyerLocation['label'] ?? '')) !== '';

$shopFilterBrands = [];
$shopFilterTypes = [];

// Same source as seller "What things are you selling" / Category on sales_management.php#products.
if (!function_exists('org_product_type_platform_selling_labels')) {
    require_once __DIR__ . '/org_product_type_schemas.php';
}
if (function_exists('org_product_type_platform_selling_labels')) {
    foreach (org_product_type_platform_selling_labels() as $label) {
        $label = trim((string)$label);
        if ($label !== '') {
            $shopFilterTypes[$label] = $label;
        }
    }
}

$shopFacetOrgIds = [];
foreach ($shopAllProducts as $shopFacetRow) {
    $shopFacetBrand = trim((string)($shopFacetRow['publisher_name'] ?? ''))
        ?: trim((string)($shopFacetRow['publisher_username'] ?? ''))
        ?: trim((string)($shopFacetRow['seller_name'] ?? ''));
    if ($shopFacetBrand !== '') {
        $shopFilterBrands[$shopFacetBrand] = $shopFacetBrand;
    }
    foreach (['selling_type', 'category'] as $shopFacetField) {
        $shopFacetType = trim((string)($shopFacetRow[$shopFacetField] ?? ''));
        if ($shopFacetType !== '') {
            $shopFilterTypes[$shopFacetType] = $shopFacetType;
        }
    }
    $facetOrgId = (int)($shopFacetRow['org_id'] ?? 0);
    if ($facetOrgId > 0) {
        $shopFacetOrgIds[$facetOrgId] = $facetOrgId;
    }
}

// Include seller custom names saved in shop_json (Add name… on product form).
if ($shopFacetOrgIds) {
    try {
        $orgIdList = array_values($shopFacetOrgIds);
        $placeholders = implode(',', array_fill(0, count($orgIdList), '?'));
        $stShopJson = $dbh->prepare("SELECT shop_json FROM org_settings WHERE org_id IN ($placeholders)");
        $stShopJson->execute($orgIdList);
        while ($rawJson = $stShopJson->fetchColumn()) {
            $decoded = json_decode((string)$rawJson, true);
            if (!is_array($decoded)) {
                continue;
            }
            foreach (['custom_selling_types', 'custom_categories'] as $listKey) {
                $list = $decoded[$listKey] ?? [];
                if (!is_array($list)) {
                    continue;
                }
                foreach ($list as $customName) {
                    $customName = trim((string)$customName);
                    if ($customName === '') {
                        continue;
                    }
                    $shopFilterTypes[$customName] = $customName;
                }
            }
        }
    } catch (Throwable $e) {
        // ignore — facets still work from products + platform labels
    }
}

natcasesort($shopFilterBrands);
natcasesort($shopFilterTypes);
$shopFilterBrands = array_values($shopFilterBrands);
$shopFilterLocations = []; // legacy unused — location is session-based geo now
$shopFilterTypes = array_values($shopFilterTypes);

$shopSearchQ = trim((string)($_GET['q'] ?? ''));
$shopFilterPickup = ((string)($_GET['pickup'] ?? '') === '1');
$shopFilterBrand = trim((string)($_GET['brand'] ?? ''));
$shopFilterCommerceBrand = trim((string)($_GET['cbrand'] ?? ''));
$shopFilterLocation = ''; // no longer brand-name location facet
$shopFilterPrice = trim((string)($_GET['price'] ?? ''));
$shopFilterRating = trim((string)($_GET['rating'] ?? ''));
$shopFilterType = trim((string)($_GET['type'] ?? ''));
$shopActiveCommerceBrand = $shopFilterCommerceBrand !== ''
    ? org_commerce_brands_get_by_slug($dbh, $shopFilterCommerceBrand)
    : null;
$shopHasFilters = $shopFilterPickup
    || $shopFilterBrand !== ''
    || $shopFilterCommerceBrand !== ''
    || $shopFilterPrice !== ''
    || $shopFilterRating !== ''
    || $shopFilterType !== ''
    || $shopLocationActive;

if (!function_exists('shop_product_rating')) {
    function shop_product_rating(int $productId): int
    {
        global $shopRatingDbh;
        if (isset($shopRatingDbh) && $shopRatingDbh instanceof PDO) {
            return org_shop_product_display_rating($shopRatingDbh, $productId);
        }
        return 4 + ($productId % 2);
    }
}

if (!function_exists('shop_product_brand')) {
    function shop_product_brand(array $product): string
    {
        return trim((string)($product['publisher_name'] ?? ''))
            ?: trim((string)($product['publisher_username'] ?? ''))
            ?: trim((string)($product['seller_name'] ?? ''));
    }
}

if (!function_exists('shop_filter_build_url')) {
    function shop_filter_build_url(array $overrides = [], array $remove = []): string
    {
        global $shopSearchQ, $shopFilterPickup, $shopFilterBrand, $shopFilterCommerceBrand;
        global $shopFilterPrice, $shopFilterRating, $shopFilterType;

        $params = [
            'q' => $shopSearchQ ?? '',
            'pickup' => !empty($shopFilterPickup) ? '1' : '',
            'brand' => (string)($shopFilterBrand ?? ''),
            'cbrand' => (string)($shopFilterCommerceBrand ?? ''),
            'price' => (string)($shopFilterPrice ?? ''),
            'rating' => (string)($shopFilterRating ?? ''),
            'type' => (string)($shopFilterType ?? ''),
        ];

        foreach ($remove as $key) {
            unset($params[$key]);
        }
        foreach ($overrides as $key => $value) {
            if ($value === null || $value === '') {
                unset($params[$key]);
            } else {
                $params[$key] = $value;
            }
        }

        $params = array_filter($params, static fn($v) => $v !== '' && $v !== null);
        $query = http_build_query($params);
        return 'shop.php' . ($query !== '' ? '?' . $query : '');
    }
}

if (!function_exists('shop_product_detail_url')) {
    function shop_product_detail_url(int $productId): string
    {
        global $shopFilterCommerceBrand;
        $params = ['id' => $productId];
        if (!empty($shopFilterCommerceBrand)) {
            $params['cbrand'] = (string)$shopFilterCommerceBrand;
        }
        return 'product_detail.php?' . http_build_query($params);
    }
}
