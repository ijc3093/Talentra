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
$shopPerPageOptions = [10, 20, 30, 40, 50];
$shopProductsPerPage = (int)($_GET['per_page'] ?? 10);
if (!in_array($shopProductsPerPage, $shopPerPageOptions, true)) {
    $shopProductsPerPage = 10;
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
<html <?= app_html_lang_attrs() ?>>
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
  <link rel="stylesheet" href="./css/shop-page.css?v=14">
    <link rel="stylesheet" href="./css/shop-storefront.css?v=107">
  <style><?php include __DIR__ . '/includes/feed_rails.css.php'; ?></style>
  <style><?php include __DIR__ . '/includes/feed_header_chrome.css.php'; ?></style>
  <link rel="stylesheet" href="./css/shop-mock-fit.css?v=22">
  <script defer src="assets/layout-fixed.js"></script>
  <style>
    @view-transition { navigation: none; }
    .shop-page-head-mobile .shop-page-title{font-size:22px;font-weight:800;padding:8px 0 0;margin:0;color:var(--shop-text, var(--msb-palette-text, #111827));}
    .shop-page-head-mobile .shop-page-sub{padding:4px 0 0;color:var(--shop-text-muted, var(--msb-palette-text-muted, #6b7280));font-size:14px;margin:0;}
    body.shop-page .shop-page-head-mobile{display:none !important;}
    body.shop-page .ig-feed-top-lead.shop-header-lead{align-items:center;max-width:min(34vw, 320px);min-width:0;}
    html body.shop-page .ig-feed-header .shop-header-title{margin:0 !important;padding:0 !important;font-size:clamp(24px, 2.6vw, 32px) !important;font-weight:800 !important;line-height:1 !important;white-space:nowrap;text-align:left !important;color:#3067ea !important;-webkit-text-fill-color:#3067ea !important;}
    @media (max-width:767px){
      html body.shop-page .ig-feed-header .shop-header-title{font-size:clamp(22px, 6vw, 28px) !important;}
    }
    @media (min-width:1025px){
      body.shop-page.feed-insta-ui{
        --shop-header-h:calc(var(--msb-top-header-pad-top, 16px) + var(--msb-top-story-ring, 44px) + 4px + (var(--msb-top-story-name-size, 11px) * 1.2) + var(--msb-top-header-pad-bottom, 14px) + 1px) !important;
        --shop-left-rail-head-height:0px !important;
        --feed-left-rail-top:calc(var(--shop-header-h) + var(--shop-top)) !important;
      }
      html body.shop-page.feed-insta-ui .sh-pagebody > .ig-feed-header,
      html body.shop-page.feed-insta-ui .ig-feed-header{
        height:var(--shop-header-h) !important;
        min-height:var(--shop-header-h) !important;
        max-height:var(--shop-header-h) !important;
        padding-top:0 !important;
        padding-bottom:0 !important;
        align-items:center !important;
        box-sizing:border-box !important;
      }
      body.shop-page.feed-insta-ui .feed-left-rail{
        top:calc(var(--shop-header-h) + var(--shop-top)) !important;
        margin-top:13px !important;
        border-top:1px solid var(--shop-line) !important;
        border-radius:12px !important;
        padding-top:10px !important;
      }
    }
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
      border:1px solid var(--shop-border, var(--msb-palette-border, #e5e7eb));
      border-radius:999px;
      background:var(--shop-card-bg, var(--msb-palette-bg, #eceff3));
      color:var(--shop-text, var(--msb-palette-text, #111827));
      font-size:16px;
      line-height:1;
      text-decoration:none;
      box-sizing:border-box;
    }
    .shop-product-page-arrow:hover{
      background:var(--shop-card-raised, var(--msb-palette-surface-2, #e2e6ec));
      color:var(--shop-text, var(--msb-palette-text, #111827));
      text-decoration:none;
    }
    .shop-product-page-arrow.is-disabled{
      color:var(--shop-text-muted, var(--msb-palette-text-muted, #c5cad3));
      background:var(--shop-card-raised, var(--msb-palette-surface-2, #f3f4f6));
      pointer-events:none;
    }
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
      color:var(--shop-text-muted, var(--msb-palette-text-muted, #9ca3af));
      font-size:14px;
      font-weight:500;
      line-height:1;
      text-decoration:none;
      box-sizing:border-box;
    }
    .shop-product-page-num:hover{color:var(--shop-text, var(--msb-palette-text, #111827));text-decoration:none;}
    .shop-product-page-num.is-active{
      color:var(--shop-text, var(--msb-palette-text, #111827));
      font-weight:800;
      border-bottom-color:var(--shop-text, var(--msb-palette-text, #111827));
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
      color:var(--shop-text-muted, var(--msb-palette-text-muted, #6b7280));
      font-size:13px;
      font-weight:500;
      white-space:nowrap;
    }
    .shop-product-per-page select{
      height:32px;
      min-width:64px;
      padding:0 28px 0 12px;
      border:1px solid var(--shop-border, var(--msb-palette-border, #d1d5db));
      border-radius:8px;
      background:var(--shop-card-bg, var(--msb-palette-bg, #fff)) url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath fill='%236b7280' d='M1 1l5 5 5-5'/%3E%3C/svg%3E") no-repeat right 10px center;
      color:var(--shop-text, var(--msb-palette-text, #111827));
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
      height:auto;
      background:var(--shop-card-bg, var(--msb-palette-bg, #fff));
      border:1px solid var(--shop-border, var(--msb-palette-border, #e5e7eb));
      border-radius:12px;
      overflow:hidden;
      display:flex;
      flex-direction:column;
      box-shadow:none;
      min-width:0;
      color:var(--shop-text, var(--msb-palette-text, #111827));
    }
    .shop-market-cover{
      display:flex;
      align-items:center;
      justify-content:center;
      width:100%;
      height:100%;
      background:var(--shop-card-raised, var(--msb-palette-surface-2, var(--msb-palette-bg, #fff)));
      text-decoration:none;
      color:inherit;
      padding:14px;
      box-sizing:border-box;
      overflow:hidden;
    }
    .shop-market-cover img{
      display:block;
      width:100%;
      height:100%;
      max-width:100%;
      max-height:100%;
      object-fit:contain;
      object-position:center;
    }
    .shop-cover-missing{
      display:none;
      flex-direction:column;
      align-items:center;
      justify-content:center;
      width:100%;
      height:100%;
      min-height:72px;
      color:#202124;
      background:#e8eaed;
      border-radius:inherit;
      box-sizing:border-box;
    }
    .is-cover-missing > img{
      display:none !important;
    }
    .is-cover-missing > .shop-cover-missing,
    .shop-market-cover:not(:has(img)) > .shop-cover-missing,
    .shop-cart-preview-thumb:not(:has(img)) > .shop-cover-missing,
    .shop-deal-thumb:not(:has(img)) > .shop-cover-missing{
      display:flex;
    }
    .shop-cover-missing svg{
      width:40px;
      height:40px;
      display:block;
      flex:0 0 auto;
    }
    .shop-cart-preview-thumb .shop-cover-missing svg,
    .shop-deal-thumb .shop-cover-missing svg{
      width:22px;
      height:22px;
    }
    .is-cover-missing.shop-market-cover,
    .is-cover-missing.shop-cart-preview-thumb,
    .is-cover-missing.shop-deal-thumb{
      background:#e8eaed;
    }
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
      display:flex;
      flex-direction:column;
      grid-template-columns:none;
      gap:10px;
    }
    .shop-market-grid.is-list-view .shop-market-card{
      display:grid;
      grid-template-columns:88px minmax(0,1fr);
      align-items:center;
      gap:14px;
      height:auto;
      padding:12px 14px 12px 12px;
      position:relative;
    }
    .shop-market-grid.is-list-view .shop-market-media{
      width:88px;
      height:88px;
      flex:0 0 88px;
      border-radius:10px;
      overflow:hidden;
    }
    .shop-market-grid.is-list-view .shop-market-cover{
      width:100%;
      max-width:100%;
      height:100%;
      min-height:0;
      padding:6px;
      border:0;
    }
    .shop-market-grid.is-list-view .shop-market-body{
      width:100%;
      height:auto;
      padding:0;
      min-width:0;
    }
    .shop-market-grid.is-list-view .shop-market-actions-wrap{
      align-items:center;
    }
    @media (max-width:640px){
      .shop-market-grid.is-list-view .shop-market-card{
        grid-template-columns:72px minmax(0,1fr);
        padding:10px 12px 10px 10px;
      }
      .shop-market-grid.is-list-view .shop-market-media,
      .shop-market-grid.is-list-view .shop-market-cover{
        width:72px;
        max-width:72px;
        height:72px;
        min-height:0;
      }
      .shop-market-grid.is-list-view .shop-market-body{
        width:100%;
        height:auto;
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
  $feedLeftRailPageHeadTitle = '';
  $feedLeftRailPageHeadSub = '';
  include __DIR__ . '/includes/feed_left_rail.php';
  $shopHeaderSub = function_exists('app_t') ? app_t('Browse products from publishers and buy securely.') : 'Browse products from publishers and buy securely.';
?>

<div class="sh-mainpanel">
  <?php include __DIR__ . '/includes/leftbar.php'; ?>
  <?php include __DIR__ . '/includes/stories_right_door.php'; ?>
  <div class="sh-pagebody">
    <div class="ig-feed-header">
      <div class="ig-feed-top-lead shop-header-lead">
        <div class="shop-header-title" role="heading" aria-level="1">Shop</div>
      </div>
      <?php include __DIR__ . '/includes/shop_header_search.php'; ?>
      <?php $feedTopShopActive = true; $feedTopShopOnly = true; $feedTopShopViewToggle = true; include __DIR__ . '/includes/feed_top_actions.php'; ?>
    </div>

    <div class="shop-page-shell">
    <div class="shop-page-head-mobile">
      <h1 class="shop-page-title">Shop</h1>
      <p class="shop-page-sub"><?= h($shopHeaderSub) ?></p>
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
        <div class="shop-category-carousel">
        <nav class="shop-category-strip" id="shopCategoryStrip" aria-label="Shop categories">
          <?php
            $shopCategoryCatalog = [
              ['label' => 'All', 'type' => '', 'icon' => 'ion-grid'],
              ['label' => 'Electronics', 'type' => 'Electronics', 'icon' => 'ion-ios-monitor-outline'],
              ['label' => 'Fashion', 'type' => 'Fashion', 'icon' => 'ion-tshirt-outline'],
              ['label' => 'Home & Living', 'type' => 'Home & Living', 'icon' => 'ion-ios-home-outline'],
              ['label' => 'Beauty', 'type' => 'Beauty', 'icon' => 'ion-ios-flower-outline'],
              ['label' => 'Health', 'type' => 'Health', 'icon' => 'ion-ios-medkit-outline'],
              ['label' => 'Sports', 'type' => 'Sports', 'icon' => 'ion-ios-basketball-outline'],
              ['label' => 'Toys & Games', 'type' => 'Toys & Games', 'icon' => 'ion-ios-game-controller-b-outline'],
              ['label' => 'Books', 'type' => 'Books', 'icon' => 'ion-ios-book-outline'],
              ['label' => 'Automotive', 'type' => 'Automotive', 'icon' => 'ion-model-s'],
              ['label' => 'More', 'type' => '', 'icon' => 'ion-ios-more'],
            ];
            $shopActiveType = trim((string)($shopFilterType ?? ''));
          ?>
          <?php foreach ($shopCategoryCatalog as $shopCategoryIndex => $shopCategoryItem): ?>
            <?php
              $shopCatType = (string)$shopCategoryItem['type'];
              $shopCatHref = $shopCatType !== ''
                ? shop_filter_build_url(['type' => $shopCatType])
                : shop_filter_build_url([], ['type']);
              $shopCatActive = ($shopCatType === '' && $shopActiveType === '' && $shopCategoryIndex === 0)
                || ($shopCatType !== '' && strcasecmp($shopActiveType, $shopCatType) === 0);
            ?>
            <a class="shop-category-tile<?= $shopCatActive ? ' is-active' : '' ?>" href="<?= h($shopCatHref) ?>">
              <span><i class="icon <?= h((string)$shopCategoryItem['icon']) ?>"></i></span>
              <strong><?= h(function_exists('app_t') ? app_t((string)$shopCategoryItem['label']) : (string)$shopCategoryItem['label']) ?></strong>
            </a>
          <?php endforeach; ?>
        </nav>
        </div>

        <section class="shop-featured-section" id="featuredProducts">
          <header class="shop-section-head">
            <h2><?= h(function_exists('app_t') ? app_t('Featured Products') : 'Featured Products') ?></h2>
            <label class="shop-sort-wrap">
              <span class="sr-only"><?= h(function_exists('app_t') ? app_t('Sort') : 'Sort') ?></span>
              <select class="shop-sort-select" aria-label="<?= h(function_exists('app_t') ? app_t('Most Relevant') : 'Most Relevant') ?>">
                <option selected><?= h(function_exists('app_t') ? app_t('Most Relevant') : 'Most Relevant') ?></option>
                <option><?= h(function_exists('app_t') ? app_t('Price: Low to High') : 'Price: Low to High') ?></option>
                <option><?= h(function_exists('app_t') ? app_t('Price: High to Low') : 'Price: High to Low') ?></option>
                <option><?= h(function_exists('app_t') ? app_t('Newest') : 'Newest') ?></option>
              </select>
            </label>
          </header>
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
            <div class="shop-market-media">
              <a href="<?= h($productUrl) ?>" class="shop-market-cover<?= $cover === '' ? ' is-cover-missing' : '' ?>">
                <?php echo org_shop_cover_img_html($cover, (string)$p['title']); ?>
              </a>
              <button type="button" class="shop-market-wish" aria-label="<?= h(function_exists('app_t') ? app_t('Save') : 'Save') ?>"><i class="icon ion-ios-heart"></i></button>
            </div>
            <figcaption class="shop-market-body">
              <h3 class="shop-market-title">
                <a href="<?= h($productUrl) ?>"><?= h((string)$p['title']) ?></a>
              </h3>
              <div class="shop-market-price" aria-label="<?= h($price) ?>">
                <?php if ($priceParts['symbol'] !== ''): ?>
                  <span class="shop-market-price-symbol"><?= h($priceParts['symbol']) ?></span>
                <?php endif; ?>
                <span class="shop-market-price-main"><?= h($priceParts['main']) ?></span>
                <?php if ($priceParts['cents'] !== ''): ?>
                  <span class="shop-market-price-cents">.<?= h($priceParts['cents']) ?></span>
                <?php endif; ?>
              </div>
              <div class="shop-market-seller-row">
                <span class="shop-market-seller-avatar" aria-hidden="true"><?= h(mb_strtoupper(mb_substr($sellerLabel, 0, 1))) ?></span>
                <a class="shop-market-seller-name" href="profile.php?tab=shop&amp;id=<?= $publisherId ?>"><?= h($sellerLabel) ?></a>
              </div>
              <div class="shop-market-meta-row">
                <span class="shop-market-rating"><i class="icon ion-ios-star"></i> <?= number_format((float)max(1, shop_product_rating($productId) ?: 5), 1) ?></span>
                <?php $cardShipping = org_shop_product_shipping_badge($dbh, $p); ?>
                <?php if ($cardShipping['mode'] === 'free'): ?>
                  <span class="shop-market-ship"><i class="icon ion-android-car"></i> <?= h(function_exists('app_t') ? app_t('Free Shipping') : 'Free Shipping') ?></span>
                <?php elseif ($cardShipping['mode'] === 'pickup'): ?>
                  <span class="shop-market-ship is-pickup" title="<?= h($cardShipping['pickup_address']) ?>"><i class="icon ion-location"></i> <?= h($cardShipping['pickup_address'] !== '' ? $cardShipping['pickup_address'] : (function_exists('app_t') ? app_t('Pick up only') : 'Pick up only')) ?></span>
                <?php elseif ($cardShipping['shipping_fee_label'] !== ''): ?>
                  <span class="shop-market-ship is-paid"><i class="icon ion-android-car"></i> <?= h($cardShipping['shipping_fee_label']) ?> <?= h(function_exists('app_t') ? app_t('shipping') : 'shipping') ?></span>
                <?php endif; ?>
              </div>
              <?php if (!$outOfStock): ?>
                <div class="shop-market-actions-wrap">
                  <div class="shop-market-actions">
                    <button type="button" class="shop-market-add-cart shop-add-cart" data-cart-add="<?= $productId ?>"><i class="icon ion-ios-cart"></i> <?= h(function_exists('app_t') ? app_t('Add to cart') : 'Add to cart') ?></button>
                    <button type="button" class="shop-market-buy-now js-open-shop-buy-door" data-shop-buy="<?= $productId ?>" data-shop-title="<?= h((string)$p['title']) ?>" data-shop-price="<?= h($price) ?>" data-shop-profile="<?= $publisherId ?>"><?= h(function_exists('app_t') ? app_t('Buy now') : 'Buy now') ?></button>
                    <a href="<?= h($productUrl) ?>" class="shop-market-fit-link"><?= h(function_exists('app_t') ? app_t('View details') : 'View details') ?></a>
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
          <label for="shopPerPage"><?= h(function_exists('app_t') ? app_t('Items Per Page') : 'Items Per Page') ?></label>
          <select id="shopPerPage" name="per_page" onchange="this.form.submit()">
            <?php foreach ($shopPerPageOptions as $shopPerPageOpt): ?>
              <option value="<?= (int)$shopPerPageOpt ?>"<?= (int)$shopPerPageOpt === (int)$shopProductsPerPage ? ' selected' : '' ?>><?= (int)$shopPerPageOpt ?></option>
            <?php endforeach; ?>
          </select>
        </form>
      </nav>
        <?php endif; ?>
      <section class="shop-service-strip" aria-label="Shopping benefits">
        <div><i class="icon ion-android-car"></i><span><strong><?= h(app_t('Free Shipping')) ?></strong><small><?= h(app_t('On eligible orders')) ?></small></span></div>
        <div><i class="icon ion-android-refresh"></i><span><strong><?= h(app_t('Easy Returns')) ?></strong><small><?= h(app_t('Simple return process')) ?></small></span></div>
        <div><i class="icon ion-card"></i><span><strong><?= h(app_t('Secure Payments')) ?></strong><small><?= h(app_t('Protected checkout')) ?></small></span></div>
        <div><i class="icon ion-help-buoy"></i><span><strong><?= h(app_t('Support')) ?></strong><small><?= h(app_t("We're here to help")) ?></small></span></div>
      </section>

      </main>

      <aside class="shop-storefront-aside" aria-label="Shopping summary">
        <section class="shop-side-card shop-cart-preview">
          <header><h2><?= h(app_t('Your Cart')) ?> (<?= (int)$shopCartCount ?>)</h2><a href="cart.php"><?= h(app_t('View Cart')) ?></a></header>
          <?php if ($shopCartItems): ?>
            <div class="shop-cart-preview-list">
              <?php foreach (array_slice($shopCartItems, 0, 3) as $shopCartItem): ?>
                <?php
                  $shopCartCover = org_shop_cover_url((string)($shopCartItem['cover_image_path'] ?? ''));
                  $shopCartQty = max(1, (int)($shopCartItem['quantity'] ?? 1));
                  $shopCartProductId = (int)($shopCartItem['product_id'] ?? 0);
                ?>
                <div class="shop-cart-preview-item">
                  <a class="shop-cart-preview-thumb<?= $shopCartCover === '' ? ' is-cover-missing' : '' ?>" href="<?= h(shop_product_detail_url($shopCartProductId)) ?>"><?php echo org_shop_cover_img_html($shopCartCover); ?></a>
                  <div class="shop-cart-preview-meta">
                    <a href="<?= h(shop_product_detail_url($shopCartProductId)) ?>"><strong><?= h((string)($shopCartItem['title'] ?? 'Product')) ?></strong></a>
                    <b><?= h(org_shop_format_price((int)($shopCartItem['price_cents'] ?? 0), (string)($shopCartItem['currency'] ?? 'USD'))) ?></b>
                    <div class="shop-cart-preview-qty">
                      <button type="button" class="shop-cart-qty-btn" data-cart-qty="dec" data-product-id="<?= $shopCartProductId ?>" aria-label="Decrease">−</button>
                      <span><?= $shopCartQty ?></span>
                      <button type="button" class="shop-cart-qty-btn" data-cart-qty="inc" data-product-id="<?= $shopCartProductId ?>" aria-label="Increase">+</button>
                      <button type="button" class="shop-cart-remove-btn" data-cart-remove="<?= $shopCartProductId ?>" aria-label="Remove"><i class="icon ion-ios-trash-outline"></i></button>
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
            <div class="shop-cart-preview-total"><span>Subtotal</span><strong><?= h(org_shop_format_price($shopCartSubtotal, 'USD')) ?></strong></div>
            <a class="shop-cart-checkout" href="cart.php"><i class="icon ion-ios-locked"></i> Checkout</a>
          <?php else: ?>
            <div class="shop-cart-preview-empty"><i class="icon ion-ios-cart-outline"></i><p><?= h(app_t('Your cart is ready for something great.')) ?></p><a href="#featuredProducts"><?= h(app_t('Start shopping')) ?></a></div>
          <?php endif; ?>
        </section>

        <?php if ($shopHeroProduct): ?>
          <?php $shopDealCover = org_shop_cover_url((string)($shopHeroProduct['cover_image_path'] ?? '')); ?>
          <section class="shop-side-card shop-deal-card">
            <header><h2><?= h(app_t("Today's Pick")) ?></h2><span><?= h(app_t('Limited offer')) ?></span></header>
            <a class="shop-deal-link" href="<?= h(shop_product_detail_url((int)$shopHeroProduct['id'])) ?>">
              <span class="shop-deal-thumb<?= $shopDealCover === '' ? ' is-cover-missing' : '' ?>"><?php echo org_shop_cover_img_html($shopDealCover); ?></span>
              <span class="shop-deal-copy">
                <strong><?= h((string)$shopHeroProduct['title']) ?></strong>
                <b><?= h(org_shop_format_price((int)($shopHeroProduct['price_cents'] ?? 0), (string)($shopHeroProduct['currency'] ?? 'USD'))) ?></b>
                <span class="shop-deal-cta"><?= h(app_t('View Details')) ?></span>
              </span>
            </a>
          </section>
        <?php endif; ?>

        <section class="shop-side-card shop-confidence-card">
          <h2><?= h(app_t('Shop with Confidence')) ?></h2>
          <div><i class="icon ion-ios-checkmark-outline"></i><span><strong><?= h(app_t('Trusted Sellers')) ?></strong><small><?= h(app_t('Verified marketplace brands')) ?></small></span></div>
          <div><i class="icon ion-ios-locked-outline"></i><span><strong><?= h(app_t('Secure & Safe')) ?></strong><small><?= h(app_t('Your checkout is protected')) ?></small></span></div>
          <div><i class="icon ion-shield"></i><span><strong><?= h(app_t('Buyer Protection')) ?></strong><small><?= h(app_t('Help with order issues')) ?></small></span></div>
          <div><i class="icon ion-android-refresh"></i><span><strong><?= h(app_t('Easy Returns')) ?></strong><small><?= h(app_t('Simple return process')) ?></small></span></div>
        </section>
      </aside>
    </div>
    </div>
    </div>
  </div>
</div>

<style id="shop-cart-added-dialog-css">
html body dialog.shop-cart-added-dialog{
  position:fixed!important;inset:0!important;top:0!important;right:0!important;bottom:0!important;left:0!important;
  width:min(360px,calc(100vw - 32px))!important;max-width:360px!important;height:max-content!important;min-height:0!important;
  max-height:calc(100dvh - 32px)!important;margin:auto!important;padding:20px 18px 16px!important;overflow:auto!important;
  transform:none!important;border:1px solid var(--msb-palette-border,rgba(148,163,184,.28))!important;border-radius:14px!important;
  background:var(--msb-palette-surface,var(--msb-palette-bg,#171d24))!important;color:var(--msb-palette-text,#f3f6fb)!important;
  box-shadow:0 18px 48px rgba(0,0,0,.28)!important;text-align:center!important;box-sizing:border-box!important;z-index:2147483647!important;
}
.shop-cart-added-dialog::backdrop{background:rgba(15,23,42,.62);backdrop-filter:blur(5px);-webkit-backdrop-filter:blur(5px);}
html body dialog.shop-cart-added-dialog:not([open]){display:none!important;}
html body dialog.shop-cart-added-dialog[open]{display:block!important;}
html body .shop-cart-added-close{
  position:absolute!important;top:10px!important;right:10px!important;width:28px!important;height:28px!important;
  margin:0!important;padding:0!important;border:0!important;border-radius:50%!important;background:transparent!important;
  color:var(--msb-palette-text-muted,#94a3b8)!important;font-size:18px!important;line-height:28px!important;
  cursor:pointer!important;display:inline-flex!important;align-items:center!important;justify-content:center!important;
}
.shop-cart-added-close:hover{background:var(--msb-palette-hover-bg,rgba(148,163,184,.14));color:var(--msb-palette-text,#f3f6fb);}
html body .shop-cart-added-icon{
  display:grid!important;place-items:center!important;width:40px!important;height:40px!important;
  margin:0 auto 10px!important;border-radius:50%!important;background:rgba(34,197,94,.14)!important;color:#22c55e!important;font-size:18px!important;
}
html body .shop-cart-added-dialog.is-error .shop-cart-added-icon{
  background:rgba(239,68,68,.14)!important;color:#ef4444!important;
}
html body .shop-cart-added-dialog h2{
  margin:0 28px 6px!important;padding:0!important;color:inherit!important;
  font-size:15px!important;font-weight:700!important;line-height:1.3!important;
}
html body .shop-cart-added-dialog > p{
  margin:0 0 16px!important;padding:0!important;
  color:var(--msb-palette-text-muted,#94a3b8)!important;font-size:13px!important;line-height:1.45!important;
}
html body .shop-cart-added-actions{
  display:flex!important;gap:8px!important;width:100%!important;margin:0!important;padding:0!important;
}
.shop-cart-added-actions a,
.shop-cart-added-actions button{
  flex:1 1 0;height:34px;border-radius:999px;font-size:13px;font-weight:600;cursor:pointer;
  display:inline-flex;align-items:center;justify-content:center;text-decoration:none;box-sizing:border-box;
}
.shop-cart-added-continue{
  border:1px solid var(--msb-palette-border,rgba(148,163,184,.38));
  background:var(--msb-palette-hover-bg,rgba(148,163,184,.12));
  color:var(--msb-palette-text,#f3f6fb);
}
.shop-cart-added-view{
  border:1px solid var(--msb-palette-action,#2563eb);
  background:var(--msb-palette-btn-bg,var(--msb-palette-action,#2563eb));
  color:var(--msb-palette-btn-text,#fff);
}
.shop-cart-added-view:hover{text-decoration:none;color:var(--msb-palette-btn-text,#fff);}
html body .shop-cart-added-dialog.is-error .shop-cart-added-view{
  border-color:#dc2626;background:#dc2626;
}
</style>
<dialog class="shop-cart-added-dialog" id="shopCartAddedDialog" aria-labelledby="shopCartAddedTitle">
  <button type="button" class="shop-cart-added-close" data-close-cart-added aria-label="Close">&times;</button>
  <div class="shop-cart-added-icon" id="shopCartAddedIcon" aria-hidden="true"><i class="fa fa-shopping-cart"></i></div>
  <h2 id="shopCartAddedTitle">Added to cart</h2>
  <p id="shopCartAddedCopy">Your item is in the cart. Continue shopping or check out when you are ready.</p>
  <div class="shop-cart-added-actions">
    <button type="button" class="shop-cart-added-continue" data-close-cart-added>Continue</button>
    <a class="shop-cart-added-view" id="shopCartAddedView" href="cart.php">View cart</a>
  </div>
</dialog>

<script src="./lib/jquery/jquery.js?v=unload3"></script>
<script src="./lib/perfect-scrollbar/js/perfect-scrollbar.jquery.js"></script>
<script src="./js/shamcey.js?v=ps1"></script>
<script>
(function(){
  function hideBrokenShopImg(img){
    if (!img || img.getAttribute('data-shop-cover-failed') === '1') return;
    img.setAttribute('data-shop-cover-failed', '1');
    img.hidden = true;
    var host = img.closest('.shop-market-cover, .shop-cart-preview-thumb, .shop-deal-thumb');
    if (host) host.classList.add('is-cover-missing');
  }
  document.querySelectorAll('body.shop-page .shop-market-cover img, body.shop-page .shop-cart-preview-thumb img, body.shop-page .shop-deal-thumb img').forEach(function(img){
    img.addEventListener('error', function(){ hideBrokenShopImg(img); });
    if (img.complete && img.naturalWidth === 0 && (img.currentSrc || img.getAttribute('src'))) {
      hideBrokenShopImg(img);
    }
  });
})();
</script>
<script>
(function(){
  var dialog = document.getElementById('shopCartAddedDialog');
  var titleEl = document.getElementById('shopCartAddedTitle');
  var copyEl = document.getElementById('shopCartAddedCopy');
  var viewEl = document.getElementById('shopCartAddedView');
  var iconEl = document.getElementById('shopCartAddedIcon');

  function closeCartAdded(){
    if (!dialog) return;
    if (typeof dialog.close === 'function') dialog.close();
    else dialog.removeAttribute('open');
  }

  function openCartAdded(ok, message){
    if (!dialog) {
      window.alert(message || (ok ? 'Added to cart.' : 'Could not add to cart.'));
      return;
    }
    dialog.classList.toggle('is-error', !ok);
    if (titleEl) titleEl.textContent = ok ? 'Added to cart' : 'Could not add';
    if (copyEl) {
      copyEl.textContent = ok
        ? (message && message !== 'Added to cart.' ? message : 'Your item is in the cart. Continue shopping or check out when you are ready.')
        : (message || 'Something went wrong. Please try again.');
    }
    if (iconEl) iconEl.innerHTML = ok ? '<i class="fa fa-shopping-cart"></i>' : '<i class="fa fa-exclamation-triangle"></i>';
    if (viewEl) viewEl.style.display = ok ? '' : 'none';
    if (typeof dialog.showModal === 'function') dialog.showModal();
    else dialog.setAttribute('open', 'open');
  }

  if (dialog) {
    dialog.querySelectorAll('[data-close-cart-added]').forEach(function(el){
      el.addEventListener('click', closeCartAdded);
    });
    dialog.addEventListener('click', function(e){
      if (e.target === dialog) closeCartAdded();
    });
    dialog.addEventListener('cancel', function(e){
      e.preventDefault();
      closeCartAdded();
    });
  }

  document.querySelectorAll('[data-cart-add]').forEach(function(btn){
    btn.addEventListener('click', async function(){
      var productId = parseInt(btn.getAttribute('data-cart-add') || '0', 10);
      if (!productId) return;
      btn.disabled = true;
      try {
        var body = new URLSearchParams();
        body.set('action', 'add');
        body.set('product_id', String(productId));
        body.set('quantity', '1');
        var res = await fetch('ajax/cart_action.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: body.toString(), credentials:'same-origin' });
        var data = await res.json();
        var badge = document.getElementById('feedTopCartBadge');
        if (!badge && data.count > 0) {
          var cartLink = document.querySelector('.ig-top-cart');
          if (cartLink) {
            badge = document.createElement('span');
            badge.className = 'ig-top-cart-badge';
            badge.id = 'feedTopCartBadge';
            cartLink.appendChild(badge);
          }
        }
        if (badge && data.count > 0) badge.textContent = String(data.count);
        openCartAdded(!!data.ok, data.message || (data.ok ? 'Added to cart.' : 'Failed.'));
      } catch (e) {
        openCartAdded(false, 'Could not add to cart.');
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

</script>
</body>
</html>
