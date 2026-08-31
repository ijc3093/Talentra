<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/session_user.php';
requireUserLogin();

require_once __DIR__ . '/controller.php';
require_once __DIR__ . '/includes/org_shop.php';
require_once __DIR__ . '/includes/org_cart.php';
require_once __DIR__ . '/includes/stripe_shop.php';
require_once __DIR__ . '/includes/theme_prefs.php';
require_once __DIR__ . '/includes/staff_publisher_access.php';
require_once __DIR__ . '/includes/publisher_accounts_load.php';

$controller = new Controller();
$dbh = $controller->pdo();
$meId = (int)($_SESSION['user_id'] ?? $_SESSION['id'] ?? $_SESSION['userid'] ?? 0);
$GLOBALS['feedTopDbh'] = $dbh;
$GLOBALS['feedTopMeId'] = $meId;
$staffReadonly = staff_pub_is_readonly();
$canLiveStudio = function_exists('live_studio_user_can_access') ? live_studio_user_can_access($dbh, $meId) : false;
$canFollowPublishers = publisher_can_follow_as_viewer($dbh, $meId);

require_once __DIR__ . '/includes/shop_filter_context.php';
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
        ]));
        return strpos($haystack, $shopSearchNeedle) !== false;
    }));
}

if ($shopHasFilters) {
    $products = array_values(array_filter($products, static function (array $p) use (
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
        // Delivery listings are available beyond the buyer's local radius. Only
        // pickup-only products must be close to the selected shop location.
        // Brand group pages (cbrand=…) continue to show the full brand catalog.
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
$shopStripeEnabled = stripe_shop_is_configured();
$shopCartItems = org_cart_list_items($dbh, $meId);
$shopCartSubtotal = org_cart_subtotal_cents($shopCartItems);
$shopCartCount = org_cart_count($dbh, $meId);
$shopHeroProduct = $products[0] ?? ($shopAllProducts[0] ?? null);
$shopPromoCovers = [];
foreach (array_merge($products, $shopAllProducts) as $shopPromoCandidate) {
    $shopPromoCover = org_shop_cover_url((string)($shopPromoCandidate['cover_image_path'] ?? ''));
    if ($shopPromoCover === '' || in_array($shopPromoCover, $shopPromoCovers, true)) {
        continue;
    }
    $shopPromoCovers[] = $shopPromoCover;
    if (count($shopPromoCovers) >= 4) {
        break;
    }
}
while (count($shopPromoCovers) > 0 && count($shopPromoCovers) < 4) {
    $shopPromoCovers[] = $shopPromoCovers[count($shopPromoCovers) - 1];
}
$shopPromoSlides = [
    ['kicker' => 'SHOP DAILY', 'title' => 'Deals worth opening.', 'sub' => 'Handpicked items with secure checkout.'],
    ['kicker' => 'NEW COLLECTION', 'title' => 'Style for every moment.', 'sub' => 'Discover top picks loved by thousands.'],
    ['kicker' => 'FEATURED PICKS', 'title' => 'Great products. Great stories.', 'sub' => 'Shop trusted brands and independent sellers in one place.'],
    ['kicker' => 'JUST IN', 'title' => 'Find your next favorite.', 'sub' => 'Fresh listings from sellers you can trust.'],
];
$shopPerPageOptions = [12, 24, 36, 48, 60];
$shopProductsPerPage = (int)($_GET['per_page'] ?? 12);
if (!in_array($shopProductsPerPage, $shopPerPageOptions, true)) {
    $shopProductsPerPage = 12;
}
$shopProductTotal = count($products);
$shopProductPageCount = max(1, (int)ceil($shopProductTotal / $shopProductsPerPage));
$shopProductPage = max(1, (int)($_GET['page'] ?? 1));
$shopProductPage = min($shopProductPage, $shopProductPageCount);
$shopPageWindow = 9;
if ($shopProductPageCount <= $shopPageWindow) {
    $shopVisiblePages = range(1, $shopProductPageCount);
} else {
    $shopPageStart = max(1, $shopProductPage - (int)floor($shopPageWindow / 2));
    $shopPageEnd = min($shopProductPageCount, $shopPageStart + $shopPageWindow - 1);
    $shopPageStart = max(1, $shopPageEnd - $shopPageWindow + 1);
    $shopVisiblePages = range($shopPageStart, $shopPageEnd);
}
$shopPagedProducts = array_slice(
    $products,
    ($shopProductPage - 1) * $shopProductsPerPage,
    $shopProductsPerPage
);
if (!function_exists('h')) {
    function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}

if (!function_exists('shop_price_parts')) {
    /** @return array{symbol:string,main:string,cents:string} */
    function shop_price_parts(string $formatted): array
    {
        $formatted = trim($formatted);
        if (preg_match('/^([^\d]*?)([\d,]+)(?:[.,](\d{2}))?\s*$/', $formatted, $m)) {
            return [
                'symbol' => $m[1] !== '' ? $m[1] : '$',
                'main' => str_replace(',', '', $m[2]),
                'cents' => isset($m[3]) && $m[3] !== '' ? $m[3] : '00',
            ];
        }
        return ['symbol' => '', 'main' => $formatted, 'cents' => ''];
    }
}

if (!function_exists('shop_card_spec_bits')) {
    /**
     * Compact card facts. Size dumps with many options or duplicate tokens are omitted.
     *
     * @param list<array{key?:string,label?:string,value?:string}> $highlight
     * @return list<string>
     */
    function shop_card_spec_bits(array $highlight): array
    {
        $bits = [];
        $seenKeys = [];
        foreach ($highlight as $specRow) {
            $key = strtolower(trim((string)($specRow['key'] ?? '')));
            $label = trim((string)($specRow['label'] ?? ''));
            $value = trim((string)($specRow['value'] ?? ''));
            if ($label === '' || $value === '') {
                continue;
            }
            if ($key !== '' && isset($seenKeys[$key])) {
                continue;
            }
            $isSize = $key === 'size'
                || $key === 'size_unit'
                || preg_match('/\bsize\b/i', $label) === 1;
            if ($isSize) {
                $tokens = preg_split('/\s*[,;\/|]+\s*/', $value) ?: [];
                $unique = [];
                foreach ($tokens as $token) {
                    $token = trim((string)$token);
                    if ($token === '') {
                        continue;
                    }
                    $norm = function_exists('mb_strtolower') ? mb_strtolower($token) : strtolower($token);
                    if (!isset($unique[$norm])) {
                        $unique[$norm] = $token;
                    }
                }
                $uniqueList = array_values($unique);
                if (count($uniqueList) > 3 || (count($tokens) >= 5 && count($uniqueList) < count($tokens))) {
                    if ($key !== '') {
                        $seenKeys[$key] = true;
                    }
                    continue;
                }
                if ($uniqueList) {
                    $value = implode(', ', $uniqueList);
                }
            }
            if ($key !== '') {
                $seenKeys[$key] = true;
            }
            $bits[] = $label . ': ' . $value;
        }
        return $bits;
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Shop</title>
  <?php theme_prefs_print_head_bootstrap($dbh, $meId); ?>
  <link href="./lib/font-awesome/css/font-awesome.css" rel="stylesheet">
  <link href="./lib/Ionicons/css/ionicons.css" rel="stylesheet">
  <link rel="stylesheet" href="./css/shamcey.css">
  <link rel="stylesheet" href="assets/ui_best.css">
  <link rel="stylesheet" href="assets/layout-fixed.css">
  <link rel="stylesheet" href="./css/shop-page.css?v=10">
    <link rel="stylesheet" href="./css/shop-storefront.css?v=90">
  <style><?php include __DIR__ . '/includes/feed_rails.css.php'; ?></style>
  <style><?php include __DIR__ . '/includes/feed_header_chrome.css.php'; ?></style>
  <script defer src="assets/layout-fixed.js"></script>
  <style>
    .shop-page-head-mobile .shop-page-title{font-size:22px;font-weight:800;padding:8px 0 0;margin:0;color:var(--shop-text, var(--msb-palette-text, #111827));}
    .shop-page-head-mobile .shop-page-sub{padding:4px 0 0;color:var(--shop-text-muted, var(--msb-palette-text-muted, #6b7280));font-size:14px;margin:0;}
    .shop-market-grid{
      display:grid;
      grid-template-columns:repeat(auto-fill,minmax(240px,1fr));
      gap:14px;
      padding:0 0 24px;
      width:100%;
      max-width:100%;
      margin:16px 0 0;
    }
    .shop-product-pagination{
      display:flex;
      align-items:center;
      justify-content:center;
      position:relative;
      z-index:1;
      flex:0 0 auto;
      width:100%;
      max-width:100%;
      margin:8px 0 0;
      padding:8px 0 4px;
      border:0;
      border-radius:0;
      background:transparent;
      box-shadow:none;
      gap:0;
    }
    .shop-product-pagination-pages{
      display:flex;
      align-items:center;
      justify-content:center;
      gap:4px;
    }
    .shop-product-page-arrow{
      display:inline-flex;
      align-items:center;
      justify-content:center;
      width:32px;
      height:32px;
      min-width:32px;
      padding:0;
      border:0;
      border-radius:50%;
      background:#eceff3;
      color:#111827;
      font-size:16px;
      line-height:1;
      text-decoration:none;
      box-sizing:border-box;
    }
    .shop-product-page-arrow:hover{background:#e2e6ec;color:#111827;text-decoration:none;}
    .shop-product-page-arrow.is-disabled{color:#c5cad3;background:#f3f4f6;pointer-events:none;}
    .shop-product-page-num{
      display:inline-flex;
      align-items:center;
      justify-content:center;
      min-width:22px;
      height:28px;
      padding:0 6px;
      border:0;
      border-bottom:2px solid transparent;
      background:transparent;
      color:#9ca3af;
      font-size:14px;
      font-weight:500;
      line-height:1;
      text-decoration:none;
      box-sizing:border-box;
    }
    .shop-product-page-num:hover{color:#111827;text-decoration:none;}
    .shop-product-page-num.is-active{
      color:#111827;
      font-weight:800;
      border-bottom-color:#111827;
    }
    .shop-product-per-page{
      position:absolute;
      right:0;
      top:50%;
      transform:translateY(-50%);
      display:flex;
      align-items:center;
      gap:10px;
      margin:0;
    }
    .shop-product-per-page label{
      margin:0;
      color:#6b7280;
      font-size:13px;
      font-weight:500;
      white-space:nowrap;
    }
    .shop-product-per-page select{
      height:32px;
      min-width:64px;
      padding:0 28px 0 12px;
      border:1px solid #d1d5db;
      border-radius:8px;
      background:var(--shop-card-bg, #fff) url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath fill='%236b7280' d='M1 1l5 5 5-5'/%3E%3C/svg%3E") no-repeat right 10px center;
      color:#111827;
      font-size:13px;
      font-weight:600;
      appearance:none;
      -webkit-appearance:none;
      cursor:pointer;
    }
    @media (max-width:720px){
      .shop-product-pagination{flex-direction:column;gap:10px;padding-bottom:8px;}
      .shop-product-per-page{position:static;transform:none;}
    }
    figure.shop-market-card{
      margin:0;
      width:100%;
      height:270px;
      background:var(--shop-card-bg, var(--msb-palette-bg, #fff));
      border:1px solid var(--shop-border, var(--msb-palette-border, #e5e7eb));
      border-radius:4px;
      overflow:hidden;
      display:grid;
      grid-template-rows:48% 52%;
      box-shadow:0 1px 2px rgba(15,23,42,.04);
      min-width:0;
      color:var(--shop-text, var(--msb-palette-text, #111827));
    }
    .shop-market-cover{
      display:block;
      width:100%;
      height:48%;
      background:var(--shop-card-raised, var(--msb-palette-surface-2, var(--msb-palette-bg, #fff)));
      text-decoration:none;
      color:inherit;
      padding:0;
      box-sizing:border-box;
      overflow:hidden;
    }
    .shop-market-cover img,
    .shop-market-cover .shop-market-cover-fallback{
      display:block;
      width:100%;
      height:100%;
      max-width:100%;
      max-height:100%;
      object-fit:cover;
      object-position:center;
    }
    .shop-market-cover-fallback{
      display:flex;
      align-items:center;
      justify-content:center;
    }
    .shop-market-cover-fallback{font-size:48px;color:var(--shop-text-muted, var(--msb-palette-text-muted, #d1d5db));}
    figcaption.shop-market-body{
      display:flex;
      flex-direction:column;
      width:100%;
      height:52%;
      padding:4% 5% 5%;
      box-sizing:border-box;
      min-width:0;
      min-height:0;
    }
    .shop-market-title{
      margin:0 0 5px;
      font-size:14px;
      line-height:1.32;
      font-weight:800;
      color:var(--shop-text, var(--msb-palette-text, #111827));
      display:-webkit-box;
      -webkit-line-clamp:2;
      -webkit-box-orient:vertical;
      overflow:hidden;
    }
    .shop-market-title a{color:inherit;text-decoration:none;}
    .shop-market-title a:hover{text-decoration:underline;}
    .shop-market-specs{
      margin:0 0 8px;
      font-size:12px;
      line-height:1.4;
      color:var(--shop-text-soft, var(--msb-palette-text-muted, #4b5563));
    }
    .shop-market-specs-type{
      display:flex;flex-wrap:wrap;align-items:center;gap:6px;
      margin:0 0 4px;
    }
    .shop-market-type-pill,.shop-market-condition-pill{
      display:inline-flex;align-items:center;
      padding:2px 8px;border-radius:999px;font-size:11px;font-weight:700;
    }
    .shop-market-type-pill{
      background:var(--shop-hover-bg, var(--msb-palette-surface-2, #f3f4f6));
      color:var(--shop-text, var(--msb-palette-text, #111827));
    }
    .shop-market-condition-pill{
      background:#ecfdf5;color:#047857;
    }
    .shop-market-condition-pill.is-used{
      background:#fff7ed;color:#c2410c;
    }
    .shop-market-specs-bits{
      margin:0;
      display:-webkit-box;
      -webkit-line-clamp:2;
      -webkit-box-orient:vertical;
      overflow:hidden;
    }
    .shop-market-ids{display:none;}
    .shop-market-trust{
      display:flex;
      align-items:center;
      flex-wrap:wrap;
      gap:5px 10px;
      margin:0 0 8px;
      font-size:11px;
      color:var(--shop-text-soft, var(--msb-palette-text-muted, #374151));
    }
    .shop-market-trust-foot{
      display:flex;
      justify-content:flex-end;
      align-items:center;
      flex:0 0 auto;
      font-size:11px;
      color:#374151;
      min-width:0;
    }
    .shop-market-trust-foot .shop-market-warranty{
      justify-content:flex-end;
      text-align:right;
      white-space:nowrap;
    }
    .shop-market-trust-foot .shop-market-seller a{
      color:var(--shop-text, var(--msb-palette-text, #111827));
      text-decoration:underline;
      font-weight:600;
    }
    .shop-market-warranty{
      display:inline-flex;
      align-items:center;
      gap:5px;
      font-weight:600;
    }
    .shop-market-warranty-ic{
      width:14px;
      height:14px;
      min-width:14px;
      min-height:14px;
      max-width:14px;
      max-height:14px;
      border-radius:3px;
      background:#f97316;
      color:#fff;
      font-size:10px;
      line-height:1;
      font-weight:800;
      display:inline-flex;
      align-items:center;
      justify-content:center;
      flex:0 0 14px;
      overflow:hidden;
      box-sizing:border-box;
    }
    .shop-market-seller{font-size:11px;color:var(--shop-text-soft, var(--msb-palette-text-muted, #374151));min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
    .shop-market-seller a{color:var(--shop-text, var(--msb-palette-text, #111827));text-decoration:underline;font-weight:600;}
    .shop-market-fit{display:none;}
    .shop-market-price{
      display:flex;
      align-items:flex-start;
      gap:0;
      margin:0 0 10px;
      color:var(--shop-text, var(--msb-palette-text, #111827));
      font-weight:800;
      line-height:1;
    }
    .shop-market-price-symbol{font-size:18px;margin-right:1px;}
    .shop-market-price-main{font-size:28px;letter-spacing:-.02em;}
    .shop-market-price-cents{font-size:13px;margin-top:3px;margin-left:1px;}
    .shop-market-fulfill{display:block;margin:0 0 12px;font-size:12px;line-height:1.35;color:var(--shop-text-soft, var(--msb-palette-text-muted, #374151));}
    .shop-market-fulfill-row{display:flex;gap:7px;align-items:center;}
    .shop-market-fulfill-row-split{
      flex-wrap:wrap;
      gap:6px 10px;
      width:100%;
    }
    .shop-market-fulfill-delivery,
    .shop-market-fulfill-stock{
      display:inline-flex;
      gap:7px;
      align-items:center;
      min-width:0;
    }
    .shop-market-fulfill-stock{flex-shrink:0;margin-left:4px;}
    .shop-market-fulfill-ic{width:15px;flex-shrink:0;text-align:center;color:var(--shop-text-muted, var(--msb-palette-text-muted, #6b7280));font-size:13px;line-height:1.2;}
    .shop-market-fulfill-ok{color:#15803d;font-weight:700;}
    .shop-market-fulfill-bad{color:#dc2626;font-weight:700;}
    .shop-market-actions-wrap{
      display:flex;
      flex-wrap:wrap;
      align-items:center;
      justify-content:space-between;
      gap:6px 10px;
      margin-top:auto;
    }
    .shop-market-actions{
      display:flex;
      flex-wrap:wrap;
      gap:6px;
      flex:0 1 auto;
      min-width:0;
      align-items:center;
    }
    .shop-market-add-cart{
      flex:0 0 auto;
      border:1px solid var(--shop-border, var(--msb-palette-border, rgba(177,188,206,.55)));
      border-radius:4px;
      background:var(--shop-btn-filled-bg, var(--msb-palette-btn-bg, var(--msb-palette-action, #111827)));
      color:var(--shop-btn-filled-text, var(--msb-palette-btn-text, #fff));
      font-weight:800;
      font-size:11px;
      letter-spacing:.02em;
      text-transform:uppercase;
      line-height:1.2;
      white-space:nowrap;
      padding:7px 12px;
      cursor:pointer;
      transition:background .15s ease;
    }
    .shop-market-add-cart:hover{background:var(--msb-palette-btn-hover-bg, var(--shop-btn-filled-bg, #374151));}
    .shop-market-add-cart:disabled{opacity:.55;cursor:not-allowed;}
    .shop-market-buy-now{
      flex:0 0 auto;
      border:1px solid var(--shop-btn-outline-border, var(--msb-palette-border-strong, #111827));
      border-radius:4px;
      background:var(--shop-btn-outline-bg, var(--msb-palette-surface-2, var(--msb-palette-bg, #fff)));
      color:var(--shop-btn-outline-text, var(--msb-palette-text, #111827));
      font-size:11px;
      font-weight:700;
      line-height:1.2;
      white-space:nowrap;
      text-decoration:none;
      cursor:pointer;
      padding:7px 12px;
      text-align:center;
    }
    .shop-market-buy-now:hover{background:var(--shop-hover-bg, var(--msb-palette-hover-bg, #f3f4f6));}
    .shop-market-fit-link{
      flex:0 0 auto;
      align-self:center;
      font-size:11px;
      font-weight:600;
      color:var(--shop-link, var(--msb-palette-link, var(--msb-palette-action, #111827)));
      text-decoration:underline;
      white-space:nowrap;
      padding:0 2px;
    }
    .shop-market-fit-link:hover{color:var(--shop-text, var(--msb-palette-text, #374151));}
    @media (min-width:1280px){
      .shop-market-grid{grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:14px;}
    }
    @media (max-width:640px){
      .shop-market-grid{grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;}
      .shop-market-cover{padding:6px;}
    }
    .shop-market-grid.is-list-view{
      grid-template-columns:1fr;
      gap:12px;
    }
    .shop-market-grid.is-list-view .shop-market-card{
      display:flex;
      flex-direction:row;
      align-items:stretch;
      height:auto;
    }
    .shop-market-grid.is-list-view .shop-market-cover{
      width:22%;
      max-width:22%;
      height:auto;
      min-height:148px;
      border-bottom:0;
      border-right:1px solid var(--shop-border, var(--msb-palette-border, #f3f4f6));
    }
    .shop-market-grid.is-list-view .shop-market-body{
      width:78%;
      height:auto;
      padding:3% 4%;
    }
    .shop-market-grid.is-list-view .shop-market-actions-wrap{
      align-items:center;
    }
    @media (max-width:640px){
      .shop-market-grid.is-list-view .shop-market-card{
        flex-direction:column;
      }
      .shop-market-grid.is-list-view .shop-market-cover{
        width:100%;
        max-width:100%;
        height:48%;
        min-height:0;
        border-right:0;
        border-bottom:1px solid var(--shop-border, var(--msb-palette-border, #f3f4f6));
      }
      .shop-market-grid.is-list-view .shop-market-body{
        width:100%;
        height:52%;
      }
    }
    .shop-market-empty{text-align:center;padding:48px 16px;color:var(--shop-text-muted, var(--msb-palette-text-muted, #6b7280));}
    .shop-buy-modal{position:fixed;inset:0;z-index:12000;display:none;align-items:center;justify-content:center;padding:16px;background:rgba(15,23,42,.45);}
    .shop-buy-modal.is-open{display:flex;}
    .shop-buy-card{width:min(420px,100%);background:var(--shop-card-bg, var(--msb-palette-bg, #fff));border-radius:18px;box-shadow:0 24px 60px rgba(0,0,0,.18);overflow:hidden;color:var(--shop-text, var(--msb-palette-text, #111827));}
    .shop-buy-head{padding:18px 20px 8px;font-size:18px;font-weight:700;color:var(--shop-text, var(--msb-palette-text, #111827));}
    .shop-buy-sub{padding:0 20px 12px;color:var(--shop-text-muted, var(--msb-palette-text-muted, #6b7280));font-size:14px;}
    .shop-buy-body{padding:0 20px 16px;display:grid;gap:12px;}
    .shop-buy-body label{display:block;font-size:13px;font-weight:600;margin-bottom:4px;color:var(--shop-text, var(--msb-palette-text, #111827));}
    .shop-buy-body input,.shop-buy-body textarea{width:100%;border:1px solid var(--shop-border-strong, var(--msb-palette-border-strong, rgba(15,23,42,.14)));border-radius:10px;padding:10px 12px;font-size:14px;box-sizing:border-box;background:var(--shop-input-bg, var(--msb-palette-input-bg, #fff));color:var(--shop-text, var(--msb-palette-text, #111827));}
    .shop-buy-foot{display:flex;gap:10px;padding:0 20px 18px;}
    .shop-buy-foot button{flex:1;border:0;border-radius:10px;padding:12px;font-weight:700;cursor:pointer;}
    .shop-buy-cancel{background:var(--shop-card-raised, var(--msb-palette-surface-2, #f3f4f6));color:var(--shop-text, var(--msb-palette-text, #111827));}
    .shop-buy-submit{background:var(--shop-btn-filled-bg, var(--msb-palette-btn-bg, #111827));color:var(--shop-btn-filled-text, var(--msb-palette-btn-text, #fff));}
  </style>
</head>
<body class="shop-page feed-page feed-insta-ui">

<?php
  $GLOBALS['msb_skip_header_leftbar'] = true;
  $skipHeaderThemeBootstrap = true;
  include __DIR__ . '/includes/header.php';
?>
<?php
  $feedLeftRailActive = 'shop.php';
  $feedLeftRailCanFollow = $canFollowPublishers;
  $feedLeftRailShopOnly = true;
  $feedLeftRailShopFilters = true;
  $feedLeftRailPageHeadTitle = 'Shop';
  $feedLeftRailPageHeadSub = 'Browse products from publishers and buy securely.';
  include __DIR__ . '/includes/feed_left_rail.php';
?>

<div class="sh-mainpanel">
  <?php include __DIR__ . '/includes/leftbar.php'; ?>
  <?php include __DIR__ . '/includes/stories_right_door.php'; ?>
  <div class="sh-pagebody">
    <div class="ig-feed-header">
      <?php include __DIR__ . '/includes/feed_top_user_lead.php'; ?>
      <?php include __DIR__ . '/includes/shop_header_search.php'; ?>
      <?php $feedTopShopActive = true; $feedTopShopOnly = true; $feedTopShopViewToggle = true; include __DIR__ . '/includes/feed_top_actions.php'; ?>
    </div>

    <div class="shop-page-shell">
    <div class="shop-page-head-mobile">
      <h1 class="shop-page-title">Shop</h1>
      <p class="shop-page-sub">Browse products from publishers and buy securely.</p>
    </div>

    <?php if ($shopActiveCommerceBrand): ?>
      <div class="shop-brand-banner" style="--shop-brand-accent: <?= h((string)($shopActiveCommerceBrand['accent_color'] ?? '#2563eb')) ?>">
        <div class="shop-brand-banner-icon" aria-hidden="true"><?= h((string)($shopActiveCommerceBrand['icon_letter'] ?? mb_substr((string)$shopActiveCommerceBrand['name'], 0, 1))) ?></div>
        <div class="shop-brand-banner-text">
          <strong><?= h((string)$shopActiveCommerceBrand['name']) ?></strong>
          <span><?= h((string)($shopActiveCommerceBrand['tagline'] ?? 'Browse sellers on this brand marketplace.')) ?></span>
        </div>
        <a href="<?= h(shop_filter_build_url([], ['cbrand'])) ?>" class="shop-brand-banner-clear">All brands</a>
      </div>
    <?php endif; ?>

    <div class="shop-page-scroll">
    <div class="shop-storefront-layout">
      <main class="shop-storefront-main">
        <div class="shop-storefront-scroll">
        <section class="shop-promo-hero" id="shopPromoHero" aria-label="Featured shopping promotion">
          <div class="shop-promo-slides">
            <?php foreach ($shopPromoSlides as $shopPromoIndex => $shopPromoSlide): ?>
              <article class="shop-promo-slide<?= $shopPromoIndex === 0 ? ' is-active' : '' ?>" data-shop-promo-slide="<?= (int)$shopPromoIndex ?>">
                <div class="shop-promo-copy">
                  <span class="shop-promo-kicker"><?= h($shopPromoSlide['kicker']) ?></span>
                  <h2><?= h($shopPromoSlide['title']) ?></h2>
                  <p><?= h($shopPromoSlide['sub']) ?></p>
                  <a class="shop-promo-cta" href="<?= $shopHeroProduct ? h(shop_product_detail_url((int)$shopHeroProduct['id'])) : '#featuredProducts' ?>">Shop Now</a>
                </div>
                <figure class="shop-promo-art">
                  <?php $shopPromoHeroSrc = $shopPromoCovers[$shopPromoIndex % max(1, count($shopPromoCovers))] ?? ''; ?>
                  <?php if ($shopPromoHeroSrc !== ''): ?>
                    <img class="shop-promo-hero-img" src="<?= h($shopPromoHeroSrc) ?>" alt="">
                  <?php else: ?>
                    <span class="shop-promo-placeholder"><i class="icon ion-bag"></i></span>
                  <?php endif; ?>
                </figure>
              </article>
            <?php endforeach; ?>
          </div>
          <div class="shop-promo-dots" role="tablist" aria-label="Promotion slides">
            <?php foreach ($shopPromoSlides as $shopPromoIndex => $_slide): ?>
              <button type="button" class="shop-promo-dot<?= $shopPromoIndex === 0 ? ' is-active' : '' ?>" data-shop-promo-dot="<?= (int)$shopPromoIndex ?>" aria-label="Go to slide <?= (int)$shopPromoIndex + 1 ?>"></button>
            <?php endforeach; ?>
          </div>
        </section>

        <div class="shop-category-carousel">
        <nav class="shop-category-strip" id="shopCategoryStrip" aria-label="Shop categories">
          <?php
            $shopCategoryIcons = ['ion-ios-monitor-outline','ion-tshirt-outline','ion-ios-home-outline','ion-ios-flower-outline','ion-ios-basketball-outline','ion-ios-game-controller-b-outline','ion-ios-book-outline'];
            $shopCategoryItems = array_slice(array_values($shopFilterTypes ?? []), 0, 7);
          ?>
          <?php foreach ($shopCategoryItems as $shopCategoryIndex => $shopCategoryName): ?>
            <a class="shop-category-tile" href="<?= h(shop_filter_build_url(['type' => $shopCategoryName])) ?>">
              <span><i class="icon <?= h($shopCategoryIcons[$shopCategoryIndex] ?? 'ion-grid') ?>"></i></span>
              <strong><?= h($shopCategoryName) ?></strong>
            </a>
          <?php endforeach; ?>
          <a class="shop-category-tile" href="<?= h(shop_filter_build_url([], ['type'])) ?>">
            <span><i class="icon ion-grid"></i></span><strong>View all</strong>
          </a>
        </nav>
        </div>

        <section class="shop-featured-section" id="featuredProducts">
          <header class="shop-section-head"><h2>Featured Products</h2><a href="<?= h(shop_filter_build_url([], ['q','type','price','rating','brand','cbrand','pickup'])) ?>">View all</a></header>
    <?php if (!$products): ?>
      <div class="shop-market-empty">
        <i class="icon ion-bag" style="font-size:42px;display:block;margin-bottom:10px;"></i>
        <?php if ($shopSearchQ !== '' || $shopHasFilters): ?>
          <?php if ($shopActiveCommerceBrand && $shopSearchQ === ''): ?>
            No products listed for <?= h((string)$shopActiveCommerceBrand['name']) ?> yet. Sellers on this brand may still be setting up their menu.
          <?php elseif ($shopLocationActive && ($shopFilterCommerceBrand ?? '') === ''): ?>
            No products near <?= h($shopLocationSummary) ?>. Tap the location link to search a different place or widen the radius.
          <?php else: ?>
            No products match your current search or filters.
          <?php endif; ?>
        <?php else: ?>
          No products available right now. Check back when publishers list items.
        <?php endif; ?>
      </div>
    <?php else: ?>
      <div class="shop-market-grid" id="shopMarketGrid">
        <?php foreach ($shopPagedProducts as $p): ?>
          <?php
            $cover = org_shop_cover_url((string)($p['cover_image_path'] ?? ''));
            $price = org_shop_format_price((int)($p['price_cents'] ?? 0), (string)($p['currency'] ?? 'USD'));
            $priceParts = shop_price_parts($price);
            $publisherId = (int)($p['publisher_user_id'] ?? 0);
            $sellerLabel = trim((string)($p['commerce_brand_name'] ?? '')) ?: trim((string)($p['publisher_name'] ?? '')) ?: trim((string)($p['publisher_username'] ?? '')) ?: trim((string)($p['seller_name'] ?? 'Shop'));
            $stock = $p['stock_qty'];
            $outOfStock = ($stock !== null && $stock !== '' && (int)$stock <= 0);
            $productId = (int)$p['id'];
            $sku = trim((string)($p['sku'] ?? ''));
            $category = trim((string)($p['category'] ?? ''));
            $sellingType = trim((string)($p['selling_type'] ?? ''));
            $productFacts = org_product_type_buyer_facts(
                isset($p['attributes_json']) ? (string)$p['attributes_json'] : null,
                $sellingType,
                4
            );
            $cardTypeLabel = $productFacts['type_label'] !== '' ? $productFacts['type_label'] : $sellingType;
            $cardCondition = $productFacts['condition'];
            $cardSpecBits = shop_card_spec_bits($productFacts['highlight']);
            $deliveryBy = (new DateTimeImmutable('now'))->modify('+3 days')->format('F j');
            $cBrandName = trim((string)($p['commerce_brand_name'] ?? ''));
            $cBrandSlug = trim((string)($p['commerce_brand_slug'] ?? ''));
            $cBrandColor = trim((string)($p['commerce_brand_color'] ?? '#2563eb'));
            $cBrandIcon = trim((string)($p['commerce_brand_icon'] ?? ($cBrandName !== '' ? mb_substr($cBrandName, 0, 1) : '')));
            $productUrl = shop_product_detail_url($productId);
          ?>
          <figure class="shop-market-card">
            <a href="<?= h($productUrl) ?>" class="shop-market-cover">
              <?php if ($cover !== ''): ?>
                <img src="<?= h($cover) ?>" alt="<?= h((string)$p['title']) ?>">
              <?php else: ?>
                <span class="shop-market-cover-fallback"><i class="icon ion-bag"></i></span>
              <?php endif; ?>
            </a>
            <figcaption class="shop-market-body">
              <!-- <?php if ($cBrandName !== '' && $cBrandSlug !== ''): ?>
                <a href="<?= h(org_commerce_brands_shop_url($cBrandSlug)) ?>" class="shop-market-brand-pill" style="--shop-brand-accent: <?= h($cBrandColor) ?>">
                  <span class="shop-market-brand-pill-icon" aria-hidden="true"><?= h($cBrandIcon) ?></span>
                  <?= h($cBrandName) ?>
                </a>
              <?php endif; ?> -->
              <h3 class="shop-market-title">
                <a href="<?= h($productUrl) ?>"><?= h((string)$p['title']) ?></a>
              </h3>
              <?php if ($cardTypeLabel !== '' || $cardCondition !== '' || $cardSpecBits): ?>
                <div class="shop-market-specs">
                  <?php if ($cardTypeLabel !== '' || $cardCondition !== ''): ?>
                    <div class="shop-market-specs-type">
                      <?php if ($cardTypeLabel !== ''): ?>
                        <span class="shop-market-type-pill"><?= h($cardTypeLabel) ?></span>
                      <?php endif; ?>
                      <?php if ($cardCondition !== ''): ?>
                        <span class="shop-market-condition-pill<?= stripos($cardCondition, 'used') !== false ? ' is-used' : '' ?>"><?= h($cardCondition) ?></span>
                      <?php endif; ?>
                    </div>
                  <?php endif; ?>
                  <?php if ($cardSpecBits): ?>
                    <p class="shop-market-specs-bits"><?= h(implode(' · ', array_slice($cardSpecBits, 0, 3))) ?></p>
                  <?php endif; ?>
                </div>
              <?php endif; ?>
              <p class="shop-market-ids">
                Part #<?= $productId ?><?php if ($sku !== ''): ?> | SKU #<?= h($sku) ?><?php endif; ?>
              </p>
              <div class="shop-market-price" aria-label="<?= h($price) ?>">
                <?php if ($priceParts['symbol'] !== ''): ?>
                  <span class="shop-market-price-symbol"><?= h($priceParts['symbol']) ?></span>
                <?php endif; ?>
                <span class="shop-market-price-main"><?= h($priceParts['main']) ?></span>
                <?php if ($priceParts['cents'] !== ''): ?>
                  <span class="shop-market-price-cents"><?= h($priceParts['cents']) ?></span>
                <?php endif; ?>
              </div>
              <div class="shop-market-fulfill">
                <div class="shop-market-fulfill-row shop-market-fulfill-row-split">
                  <span class="shop-market-fulfill-delivery">
                    <span class="shop-market-fulfill-ic"><i class="icon ion-ios-box"></i></span>
                    <span><span class="shop-market-fulfill-ok">Free delivery</span> · by <?= h($deliveryBy) ?></span>
                  </span>
                  <span class="shop-market-fulfill-stock">
                    <span class="shop-market-fulfill-ic"><i class="icon ion-ios-home"></i></span>
                    <span>
                      <?php if ($outOfStock): ?>
                        <span class="shop-market-fulfill-bad">Out of stock</span>
                      <?php elseif ($stock !== null && $stock !== ''): ?>
                        <span class="shop-market-fulfill-ok"><?= (int)$stock ?> in stock</span>
                      <?php else: ?>
                        <span class="shop-market-fulfill-ok">In stock</span>
                      <?php endif; ?>
                    </span>
                  </span>
                </div>
              </div>
              <?php if (!$outOfStock): ?>
                <div class="shop-market-actions-wrap">
                  <div class="shop-market-actions">
                    <button type="button" class="shop-market-add-cart shop-add-cart" data-cart-add="<?= $productId ?>">Add to cart</button>
                    <button type="button" class="shop-market-buy-now js-open-shop-buy-door" data-shop-buy="<?= $productId ?>" data-shop-title="<?= h((string)$p['title']) ?>" data-shop-price="<?= h($price) ?>" data-shop-profile="<?= $publisherId ?>">Buy now</button>
                    <a href="<?= h($productUrl) ?>" class="shop-market-fit-link">View details<?php
                      if ($cardTypeLabel !== ''): ?> · <?= h($cardTypeLabel) ?><?php
                      elseif ($category !== ''): ?> · <?= h($category) ?><?php
                      endif; ?></a>
                  </div>
                  <div class="shop-market-trust-foot">
                    <span class="shop-market-warranty">
                      <span class="shop-market-warranty-ic" aria-hidden="true">S</span>
                      Secure checkout by <span class="shop-market-seller"><a href="profile.php?tab=shop&amp;id=<?= $publisherId ?>"><?= h($sellerLabel) ?></a></span>
                    </span>
                  </div>
                </div>
              <?php else: ?>
                <button type="button" class="shop-market-add-cart" disabled>Out of stock</button>
              <?php endif; ?>
            </figcaption>
          </figure>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
        </section>
        </div>
        <?php if ($products): ?>
      <nav class="shop-product-pagination" aria-label="Featured product pages">
        <div class="shop-product-pagination-pages">
          <?php if ($shopProductPage > 1): ?>
            <a class="shop-product-page-arrow" href="<?= h(shop_filter_build_url(['page' => $shopProductPage - 1]) . '#featuredProducts') ?>" rel="prev" aria-label="Previous page">‹</a>
          <?php else: ?>
            <span class="shop-product-page-arrow is-disabled" aria-disabled="true" aria-label="Previous page">‹</span>
          <?php endif; ?>
          <?php foreach ($shopVisiblePages as $shopPageNum): ?>
            <?php if ((int)$shopPageNum === (int)$shopProductPage): ?>
              <span class="shop-product-page-num is-active" aria-current="page"><?= (int)$shopPageNum ?></span>
            <?php else: ?>
              <a class="shop-product-page-num" href="<?= h(shop_filter_build_url(['page' => (int)$shopPageNum]) . '#featuredProducts') ?>"><?= (int)$shopPageNum ?></a>
            <?php endif; ?>
          <?php endforeach; ?>
          <?php if ($shopProductPage < $shopProductPageCount): ?>
            <a class="shop-product-page-arrow" href="<?= h(shop_filter_build_url(['page' => $shopProductPage + 1]) . '#featuredProducts') ?>" rel="next" aria-label="Next page">›</a>
          <?php else: ?>
            <span class="shop-product-page-arrow is-disabled" aria-disabled="true" aria-label="Next page">›</span>
          <?php endif; ?>
        </div>
        <form class="shop-product-per-page" method="get" action="shop.php">
          <?php foreach (['q','pickup','brand','cbrand','price','rating','type'] as $shopKeepKey): ?>
            <?php $shopKeepVal = trim((string)($_GET[$shopKeepKey] ?? '')); ?>
            <?php if ($shopKeepVal !== ''): ?>
              <input type="hidden" name="<?= h($shopKeepKey) ?>" value="<?= h($shopKeepVal) ?>">
            <?php endif; ?>
          <?php endforeach; ?>
          <label for="shopPerPage">Items Per Page</label>
          <select id="shopPerPage" name="per_page" onchange="this.form.submit()">
            <?php foreach ($shopPerPageOptions as $shopPerPageOpt): ?>
              <option value="<?= (int)$shopPerPageOpt ?>"<?= (int)$shopPerPageOpt === (int)$shopProductsPerPage ? ' selected' : '' ?>><?= (int)$shopPerPageOpt ?></option>
            <?php endforeach; ?>
          </select>
        </form>
      </nav>
        <?php endif; ?>
      <section class="shop-service-strip" aria-label="Shopping benefits">
        <div><i class="icon ion-android-car"></i><span><strong>Free Shipping</strong><small>On eligible orders</small></span></div>
        <div><i class="icon ion-android-refresh"></i><span><strong>Easy Returns</strong><small>Simple return process</small></span></div>
        <div><i class="icon ion-card"></i><span><strong>Secure Payments</strong><small>Protected checkout</small></span></div>
        <div><i class="icon ion-help-buoy"></i><span><strong>Support</strong><small>We're here to help</small></span></div>
      </section>

      </main>

      <aside class="shop-storefront-aside" aria-label="Shopping summary">
        <section class="shop-side-card shop-cart-preview">
          <header><h2>Your Cart (<?= (int)$shopCartCount ?>)</h2><a href="cart.php">View Cart</a></header>
          <?php if ($shopCartItems): ?>
            <div class="shop-cart-preview-list">
              <?php foreach (array_slice($shopCartItems, 0, 2) as $shopCartItem): ?>
                <?php $shopCartCover = org_shop_cover_url((string)($shopCartItem['cover_image_path'] ?? '')); ?>
                <a class="shop-cart-preview-item" href="<?= h(shop_product_detail_url((int)$shopCartItem['product_id'])) ?>">
                  <span class="shop-cart-preview-thumb"><?php if ($shopCartCover !== ''): ?><img src="<?= h($shopCartCover) ?>" alt=""><?php else: ?><i class="icon ion-bag"></i><?php endif; ?></span>
                  <span><strong><?= h((string)($shopCartItem['title'] ?? 'Product')) ?></strong><small>Qty <?= (int)($shopCartItem['quantity'] ?? 1) ?></small><b><?= h(org_shop_format_price((int)($shopCartItem['price_cents'] ?? 0), (string)($shopCartItem['currency'] ?? 'USD'))) ?></b></span>
                </a>
              <?php endforeach; ?>
            </div>
            <div class="shop-cart-preview-total"><span>Subtotal</span><strong><?= h(org_shop_format_price($shopCartSubtotal, 'USD')) ?></strong></div>
            <a class="shop-cart-checkout" href="cart.php">Checkout</a>
          <?php else: ?>
            <div class="shop-cart-preview-empty"><i class="icon ion-ios-cart-outline"></i><p>Your cart is ready for something great.</p><a href="#featuredProducts">Start shopping</a></div>
          <?php endif; ?>
        </section>

        <?php if ($shopHeroProduct): ?>
          <?php $shopDealCover = org_shop_cover_url((string)($shopHeroProduct['cover_image_path'] ?? '')); ?>
          <section class="shop-side-card shop-deal-card">
            <header><h2>Today's Pick</h2><span>Limited offer</span></header>
            <a href="<?= h(shop_product_detail_url((int)$shopHeroProduct['id'])) ?>">
              <span class="shop-deal-thumb"><?php if ($shopDealCover !== ''): ?><img src="<?= h($shopDealCover) ?>" alt=""><?php else: ?><i class="icon ion-bag"></i><?php endif; ?></span>
              <span><strong><?= h((string)$shopHeroProduct['title']) ?></strong><small>Featured from our marketplace</small><b><?= h(org_shop_format_price((int)($shopHeroProduct['price_cents'] ?? 0), (string)($shopHeroProduct['currency'] ?? 'USD'))) ?></b></span>
            </a>
          </section>
        <?php endif; ?>

        <section class="shop-side-card shop-confidence-card">
          <h2>Shop with Confidence</h2>
          <div><i class="icon ion-ios-locked-outline"></i><span><strong>Trusted Sellers</strong><small>Verified marketplace brands</small></span></div>
          <div><i class="icon ion-shield"></i><span><strong>Secure &amp; Safe</strong><small>Your checkout is protected</small></span></div>
          <div><i class="icon ion-ios-heart-outline"></i><span><strong>Buyer Protection</strong><small>Help with order issues</small></span></div>
        </section>
      </aside>
    </div>
    </div>
    </div>
  </div>
</div>

<script src="./lib/jquery/jquery.js"></script>
<script src="./js/shamcey.js"></script>
<script>
(function(){
  document.querySelectorAll('[data-cart-add]').forEach(btn => {
    btn.addEventListener('click', async function(){
      const productId = parseInt(btn.getAttribute('data-cart-add') || '0', 10);
      if (!productId) return;
      btn.disabled = true;
      try {
        const body = new URLSearchParams();
        body.set('action', 'add');
        body.set('product_id', String(productId));
        body.set('quantity', '1');
        const res = await fetch('ajax/cart_action.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: body.toString(), credentials:'same-origin' });
        const data = await res.json();
        let badge = document.getElementById('feedTopCartBadge');
        if (!badge && data.count > 0) {
          const cartLink = document.querySelector('.ig-top-cart');
          if (cartLink) {
            badge = document.createElement('span');
            badge.className = 'ig-top-cart-badge';
            badge.id = 'feedTopCartBadge';
            cartLink.appendChild(badge);
          }
        }
        if (badge && data.count > 0) badge.textContent = String(data.count);
        window.alert(data.message || (data.ok ? 'Added to cart.' : 'Failed.'));
      } catch (e) {
        window.alert('Could not add to cart.');
      } finally {
        btn.disabled = false;
      }
    });
  });
})();

(function(){
  const grid = document.getElementById('shopMarketGrid');
  const buttons = document.querySelectorAll('.ig-shop-view-btn[data-shop-view]');
  if (!grid || !buttons.length) return;

  const storageKey = 'msbShopViewMode';

  function applyView(mode){
    const isList = mode === 'list';
    grid.classList.toggle('is-list-view', isList);
    buttons.forEach(btn => {
      const active = btn.getAttribute('data-shop-view') === mode;
      btn.classList.toggle('is-active', active);
      btn.setAttribute('aria-pressed', active ? 'true' : 'false');
    });
    try { localStorage.setItem(storageKey, mode); } catch (e) {}
  }

  let saved = 'grid';
  try {
    saved = localStorage.getItem(storageKey) || 'grid';
  } catch (e) {}
  applyView(saved === 'list' ? 'list' : 'grid');

  buttons.forEach(btn => {
    btn.addEventListener('click', function(){
      applyView(btn.getAttribute('data-shop-view') || 'grid');
    });
  });
})();

(function(){
  document.querySelectorAll('.shop-nav-filter').forEach(filter => {
    const toggle = filter.querySelector('.shop-nav-filter-toggle');
    const panel = filter.querySelector('.shop-nav-filter-panel');
    if (!toggle || !panel) return;

    toggle.addEventListener('click', function(){
      const isOpen = filter.classList.toggle('is-open');
      toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
      panel.hidden = !isOpen;
    });
  });
})();

(function(){
  const hero = document.getElementById('shopPromoHero');
  if (!hero) return;
  const slides = Array.from(hero.querySelectorAll('[data-shop-promo-slide]'));
  const dots = Array.from(hero.querySelectorAll('[data-shop-promo-dot]'));
  if (!slides.length) return;
  let index = 0;
  let timer = null;
  const delay = 4500;
  function show(next){
    index = (next + slides.length) % slides.length;
    slides.forEach((slide, i) => slide.classList.toggle('is-active', i === index));
    dots.forEach((dot, i) => dot.classList.toggle('is-active', i === index));
  }
  function stop(){
    if (timer) {
      clearInterval(timer);
      timer = null;
    }
  }
  function start(){
    stop();
    if (slides.length < 2) return;
    if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
    timer = setInterval(function(){ show(index + 1); }, delay);
  }
  dots.forEach(dot => {
    dot.addEventListener('click', function(){
      show(parseInt(dot.getAttribute('data-shop-promo-dot') || '0', 10));
      start();
    });
  });
  hero.addEventListener('mouseenter', stop);
  hero.addEventListener('mouseleave', start);
  document.addEventListener('visibilitychange', function(){
    if (document.hidden) stop();
    else start();
  });
  start();
})();
</script>
</body>
</html>
