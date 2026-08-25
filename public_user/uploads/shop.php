<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/session_user.php';
requireUserLogin();

require_once __DIR__ . '/controller.php';
require_once __DIR__ . '/includes/org_shop.php';
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
        // Brand group pages (cbrand=…) show the full brand catalog — don't hide items by radius.
        // Location radius still applies on the general marketplace browse.
        if ($shopLocationActive && $shopFilterCommerceBrand === '' && !shop_location_product_in_range($p, $shopBuyerLocation)) {
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
    .shop-market-card{
      background:var(--shop-card-bg, var(--msb-palette-bg, #fff));
      border:1px solid var(--shop-border, var(--msb-palette-border, #e5e7eb));
      border-radius:4px;
      overflow:hidden;
      display:flex;
      flex-direction:column;
      box-shadow:0 1px 2px rgba(15,23,42,.04);
      min-width:0;
      color:var(--shop-text, var(--msb-palette-text, #111827));
    }
    .shop-market-cover{
      aspect-ratio:1/1;
      width:100%;
      background:var(--shop-card-raised, var(--msb-palette-surface-2, var(--msb-palette-bg, #fff)));
      display:flex;
      align-items:center;
      justify-content:center;
      text-decoration:none;
      color:inherit;
      padding:8px;
      box-sizing:border-box;
      border-bottom:1px solid var(--shop-border, var(--msb-palette-border, #f3f4f6));
    }
    .shop-market-cover img{
      width:100%;
      height:100%;
      max-width:100%;
      max-height:100%;
      object-fit:contain;
      object-position:center;
    }
    .shop-market-cover-fallback{font-size:48px;color:var(--shop-text-muted, var(--msb-palette-text-muted, #d1d5db));}
    .shop-market-body{padding:12px 14px 14px;display:flex;flex-direction:column;flex:1;min-width:0;}
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
      width:16px;
      height:16px;
      border-radius:3px;
      background:#f97316;
      color:#fff;
      font-size:9px;
      font-weight:800;
      display:inline-flex;
      align-items:center;
      justify-content:center;
      flex-shrink:0;
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
      flex-direction:row;
      align-items:stretch;
    }
    .shop-market-grid.is-list-view .shop-market-cover{
      width:148px;
      max-width:148px;
      aspect-ratio:auto;
      height:auto;
      min-height:148px;
      border-bottom:0;
      border-right:1px solid var(--shop-border, var(--msb-palette-border, #f3f4f6));
    }
    .shop-market-grid.is-list-view .shop-market-body{
      padding:12px 14px;
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
        min-height:0;
        aspect-ratio:1/1;
        border-right:0;
        border-bottom:1px solid var(--shop-border, var(--msb-palette-border, #f3f4f6));
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
        <?php foreach ($products as $p): ?>
          <?php
            $cover = org_shop_cover_url((string)($p['cover_image_path'] ?? ''));
            $price = org_shop_format_price((int)($p['price_cents'] ?? 0), (string)($p['currency'] ?? 'USD'));
            $priceParts = shop_price_parts($price);
            $publisherId = (int)($p['publisher_user_id'] ?? 0);
            $sellerLabel = trim((string)($p['publisher_name'] ?? '')) ?: trim((string)($p['publisher_username'] ?? '')) ?: trim((string)($p['seller_name'] ?? 'Shop'));
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
            $cardSpecBits = [];
            foreach ($productFacts['highlight'] as $specRow) {
                $cardSpecBits[] = $specRow['label'] . ': ' . $specRow['value'];
            }
            $deliveryBy = (new DateTimeImmutable('now'))->modify('+3 days')->format('F j');
            $cBrandName = trim((string)($p['commerce_brand_name'] ?? ''));
            $cBrandSlug = trim((string)($p['commerce_brand_slug'] ?? ''));
            $cBrandColor = trim((string)($p['commerce_brand_color'] ?? '#2563eb'));
            $cBrandIcon = trim((string)($p['commerce_brand_icon'] ?? ($cBrandName !== '' ? mb_substr($cBrandName, 0, 1) : '')));
            $productUrl = shop_product_detail_url($productId);
          ?>
          <article class="shop-market-card">
            <a href="<?= h($productUrl) ?>" class="shop-market-cover">
              <?php if ($cover !== ''): ?>
                <img src="<?= h($cover) ?>" alt="<?= h((string)$p['title']) ?>">
              <?php else: ?>
                <span class="shop-market-cover-fallback"><i class="icon ion-bag"></i></span>
              <?php endif; ?>
            </a>
            <div class="shop-market-body">
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
            </div>
          </article>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
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
        const badge = document.getElementById('feedTopCartBadge');
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
</script>
</body>
</html>
