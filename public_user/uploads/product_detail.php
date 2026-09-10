<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/session_user.php';
requireUserLogin();

require_once __DIR__ . '/controller.php';
require_once __DIR__ . '/includes/org_shop.php';
require_once __DIR__ . '/includes/stripe_shop.php';
require_once __DIR__ . '/includes/commerce_messaging.php';
require_once __DIR__ . '/includes/theme_prefs.php';
require_once __DIR__ . '/includes/staff_publisher_access.php';
require_once __DIR__ . '/includes/publisher_accounts_load.php';

$controller = new Controller();
$dbh = $controller->pdo();
$meId = (int)($_SESSION['user_id'] ?? $_SESSION['id'] ?? $_SESSION['userid'] ?? 0);
$GLOBALS['feedTopDbh'] = $dbh;
$GLOBALS['feedTopMeId'] = $meId;
$canFollowPublishers = publisher_can_follow_as_viewer($dbh, $meId);

require_once __DIR__ . '/includes/shop_filter_context.php';

$productId = (int)($_GET['id'] ?? 0);
$product = org_shop_get_marketplace_product($dbh, $productId);

if (!function_exists('h')) {
    function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}

$shopStripeEnabled = stripe_shop_is_configured();
$notFound = ($product === null);

if (!$notFound) {
    $cover = org_shop_cover_url((string)($product['cover_image_path'] ?? ''));
    $price = org_shop_format_price((int)($product['price_cents'] ?? 0), (string)($product['currency'] ?? 'USD'));
    $publisherId = (int)($product['publisher_user_id'] ?? 0);
    $sellerLabel = trim((string)($product['publisher_name'] ?? ''))
        ?: trim((string)($product['publisher_username'] ?? ''))
        ?: trim((string)($product['seller_name'] ?? 'Shop'));
    $stock = $product['stock_qty'];
    $outOfStock = ($stock !== null && $stock !== '' && (int)$stock <= 0);
    $pageTitle = (string)($product['title'] ?? 'Product');
    $sku = trim((string)($product['sku'] ?? ''));
    $productCode = trim((string)($product['product_code'] ?? ''));
    if ($productCode === '') {
        $productCode = org_shop_ensure_product_code(
            $dbh,
            (int)($product['org_id'] ?? 0),
            $productId,
            ''
        );
    }
    $category = trim((string)($product['category'] ?? ''));
    $description = trim((string)($product['description'] ?? ''));
    $priceCents = (int)($product['price_cents'] ?? 0);
    $currency = (string)($product['currency'] ?? 'USD');
    $inStock = !$outOfStock;
    $stockCount = ($stock !== null && $stock !== '') ? (int)$stock : null;
    $deliveryDate = (new DateTimeImmutable('now'))->modify('+3 days');
    $deliveryByShort = $deliveryDate->format('M. j');
    $deliveryByLong = $deliveryDate->format('F j');
    $installmentLabel = org_shop_format_price((int)max(1, (int)round($priceCents / 4)), $currency);
    $ratingStats = org_shop_product_rating_stats($dbh, $productId);
    $rating = $ratingStats['count'] > 0
        ? max(1, min(5, (int)round($ratingStats['rating'])))
        : shop_product_rating($productId);
    $reviewCount = $ratingStats['count'] > 0 ? $ratingStats['count'] : (3 + ($productId % 8));
    $bulletPoints = org_shop_parse_bullet_points((string)($product['bullet_points'] ?? ''));
    $featureLines = $bulletPoints;
    if (!$featureLines) {
        $featureLines = array_values(array_filter(array_map('trim', preg_split('/\r\n|\n|\r|(?:\s*•\s*)|(?:\s*-\s+)/', $description))));
    }
    if (!$featureLines && $description !== '') {
        $featureLines = [$description];
    }
    if (!$featureLines) {
        $featureLines = array_filter([
            'Sold by ' . $sellerLabel,
            $category !== '' ? 'Category: ' . $category : '',
            $sku !== '' ? 'SKU: ' . $sku : '',
        ]);
    }
    $productFulfillment = strtolower(trim((string)($product['fulfillment_method'] ?? 'fbm')));
    if (!in_array($productFulfillment, ['fba', 'fbm'], true)) {
        $productFulfillment = 'fbm';
    }
    $sellingType = trim((string)($product['selling_type'] ?? ''));
    $productFacts = org_product_type_buyer_facts(
        isset($product['attributes_json']) ? (string)$product['attributes_json'] : null,
        $sellingType,
        8
    );
    $productSpecRows = $productFacts['rows'];
    $productCondition = $productFacts['condition'];
    $productTypeLabel = $productFacts['type_label'] !== '' ? $productFacts['type_label'] : $sellingType;
    $brandShopUrl = 'shop.php?' . http_build_query(array_filter([
        'brand' => $sellerLabel,
        'q' => $shopSearchQ ?? '',
        'pickup' => !empty($shopFilterPickup) ? '1' : '',
        'location' => $shopFilterLocation ?? '',
        'price' => $shopFilterPrice ?? '',
        'rating' => $shopFilterRating ?? '',
        'type' => $shopFilterType ?? '',
    ], static fn($v) => $v !== '' && $v !== null));
    $messageSellerUrl = $publisherId > 0
        ? commerce_message_seller_url($publisherId, $productId)
        : '';
    $sellerOrgId = (int)($product['org_id'] ?? 0);
    $sellerPublicInfo = $sellerOrgId > 0
        ? org_shop_seller_pickup_display($dbh, $sellerOrgId)
        : ['store_name' => '', 'full_name' => '', 'tagline' => '', 'address' => '', 'phone' => '', 'email' => '', 'has_address' => false, 'text' => ''];
    if (trim((string)($sellerPublicInfo['store_name'] ?? '')) === '' && $sellerLabel !== '') {
        $sellerPublicInfo['store_name'] = $sellerLabel;
    }
} else {
    $cover = '';
    $price = '';
    $publisherId = 0;
    $sellerLabel = '';
    $outOfStock = true;
    $pageTitle = 'Product not found';
    $messageSellerUrl = '';
    $sellerPublicInfo = ['store_name' => '', 'full_name' => '', 'tagline' => '', 'address' => '', 'phone' => '', 'email' => '', 'has_address' => false, 'text' => ''];
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

if (!function_exists('pd_render_stars')) {
    function pd_render_stars(int $rating): string
    {
        $rating = max(0, min(5, $rating));
        $html = '<span class="pd-stars">';
        for ($i = 1; $i <= 5; $i++) {
            $html .= '<i class="fa fa-star' . ($i <= $rating ? '' : '-o') . '" aria-hidden="true"></i>';
        }
        return $html . '</span>';
    }
}

if (!function_exists('pd_strip_bullet_prefix')) {
    function pd_strip_bullet_prefix(string $line): string
    {
        $line = trim($line);
        if (preg_match('/^(?:[•\-\*\+]|\d+[.)])\s+(.+)$/u', $line, $m)) {
            return trim((string)$m[1]);
        }
        return $line;
    }
}

if (!function_exists('pd_render_bullet_list')) {
    /** @param list<string> $items */
    function pd_render_bullet_list(array $items): string
    {
        $items = array_values(array_filter(array_map('trim', $items), static fn($v) => $v !== ''));
        if (!$items) {
            return '';
        }
        $html = '<ul class="pd-bullets">';
        foreach ($items as $item) {
            $html .= '<li>' . htmlspecialchars($item, ENT_QUOTES, 'UTF-8') . '</li>';
        }
        return $html . '</ul>';
    }
}

if (!function_exists('pd_render_description_html')) {
    /** Description with + bullets and hanging-indent alignment. */
    function pd_render_description_html(string $description): string
    {
        $description = trim($description);
        if ($description === '') {
            return '';
        }
        $blocks = preg_split('/\r\n\r\n|\n\n/', $description) ?: [$description];
        $html = '';
        foreach ($blocks as $block) {
            $block = trim((string)$block);
            if ($block === '') {
                continue;
            }
            $lines = preg_split('/\r\n|\n|\r/', $block) ?: [];
            $bulletItems = [];
            $plainParts = [];
            $flushBullets = static function () use (&$html, &$bulletItems): void {
                if ($bulletItems) {
                    $html .= pd_render_bullet_list($bulletItems);
                    $bulletItems = [];
                }
            };
            $flushPlain = static function () use (&$html, &$plainParts): void {
                if ($plainParts) {
                    $para = implode("\n", $plainParts);
                    $html .= '<p>' . nl2br(htmlspecialchars($para, ENT_QUOTES, 'UTF-8')) . '</p>';
                    $plainParts = [];
                }
            };
            foreach ($lines as $line) {
                $trimmed = trim((string)$line);
                if ($trimmed === '') {
                    continue;
                }
                if (preg_match('/^(?:[•\-\*\+]|\d+[.)])\s+.+/u', $trimmed)) {
                    $flushPlain();
                    $bulletItems[] = pd_strip_bullet_prefix($trimmed);
                } else {
                    $flushBullets();
                    $plainParts[] = $trimmed;
                }
            }
            $flushPlain();
            $flushBullets();
        }
        return $html;
    }
}

$priceParts = !$notFound ? shop_price_parts($price) : ['symbol' => '$', 'main' => '0', 'cents' => '00'];
$priceDisplay = !$notFound ? trim($price . ' ' . strtoupper($currency)) : '';
$galleryImages = !$notFound ? org_shop_product_gallery_urls($dbh, $product) : [];
if (!$notFound && !$galleryImages && $cover !== '') {
    $galleryImages = [$cover];
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= h($pageTitle) ?></title>
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
    .pd-back{display:inline-flex;align-items:center;gap:6px;color:var(--shop-link,var(--msb-palette-link,#2563eb));text-decoration:none;font-size:13px;font-weight:600;margin:0;}
    .pd-back:hover{text-decoration:underline;}
    .pd-page{
      display:grid;
      grid-template-columns:minmax(260px,1.05fr) minmax(300px,0.95fr);
      gap:32px 36px;
      align-items:stretch;
      max-width:1160px;
      flex:1;
      min-height:0;
      overflow:hidden;
    }
    .pd-gallery-col{
      min-width:0;
      min-height:0;
      display:flex;
      flex-direction:column;
      align-self:stretch;
      container-type:inline-size;
      container-name:pd-gallery;
    }
    .pd-gallery-body{flex:1 1 auto;min-height:0;width:100%;}
    .pd-gallery-back{
      flex:0 0 auto;
      margin-top:auto;
      padding-top:16px;
      border-top:1px solid var(--shop-border,var(--msb-palette-border,#e5e7eb));
      margin-bottom:40px;
    }
    .pd-gallery-main{
      position:relative;
      width:100%;
      min-height:0;
      /* margin-left: -20%; */
    }
    .pd-gallery-stage{
      width:100%;
      max-width:100%;
      /* height:min(100cqw,min(540px,calc(100vh - 280px))); */
      max-height:min(540px,calc(100vh - 280px));
      display:flex;
      align-items:center;
      justify-content:center;
      overflow:hidden;
      margin:0;
    }
    .pd-gallery-stage img{width:100%;height:150%;object-fit:contain;}
    .pd-gallery-fallback{font-size:64px;color:var(--shop-text-muted,#cbd5e1);}
    .pd-gallery-nav{
      position:absolute;
      top:50%;
      transform:translateY(-50%);
      z-index:2;
      width:40px;
      height:40px;
      border:0;
      border-radius:4px;
      background:rgba(15,23,42,.45);
      color:#fff;
      font-size:26px;
      line-height:1;
      cursor:pointer;
      padding:0;
      display:flex;
      align-items:center;
      justify-content:center;
    }
    .pd-gallery-nav:first-of-type{left:10px;}
    .pd-gallery-nav:last-of-type{right:10px;}
    .pd-gallery-nav:hover{background:rgba(15,23,42,.65);color:#fff;}
    .pd-gallery-nav:disabled{opacity:.25;cursor:default;}
    .pd-gallery-stage{cursor:zoom-in;}
    .pd-gallery-stage img{cursor:zoom-in;}
    .pd-photo-count{
      position:absolute;
      top:12px;
      left:12px;
      z-index:3;
      display:inline-flex;
      align-items:center;
      gap:7px;
      margin:0;
      padding:7px 12px;
      border:0;
      border-radius:999px;
      background:rgba(15,23,42,.62);
      color:#fff;
      font-size:13px;
      font-weight:600;
      letter-spacing:.02em;
      line-height:1;
      cursor:pointer;
      backdrop-filter:blur(4px);
      -webkit-backdrop-filter:blur(4px);
    }
    .pd-photo-count:hover{background:rgba(15,23,42,.78);color:#fff;}
    .pd-photo-count .fa{font-size:14px;opacity:.95;}
    .pd-lightbox{
      position:fixed;
      inset:0;
      z-index:21000;
      display:none;
      padding:0;
      background:rgba(8,12,20,.88);
    }
    .pd-lightbox.is-open{display:block;}
    .pd-lightbox-inner{
      position:absolute;
      inset:56px 64px 40px;
      display:flex;
      align-items:center;
      justify-content:center;
    }
    .pd-lightbox-img{
      max-width:100%;
      max-height:100%;
      width:auto;
      height:auto;
      object-fit:contain;
      border-radius:4px;
      box-shadow:0 16px 48px rgba(0,0,0,.45);
    }
    .pd-lightbox-close{
      position:absolute;
      top:16px;
      right:16px;
      z-index:3;
      width:44px;
      height:44px;
      border:0;
      border-radius:50%;
      background:rgba(15,23,42,.7);
      color:#fff;
      font-size:22px;
      line-height:1;
      cursor:pointer;
      display:flex;
      align-items:center;
      justify-content:center;
    }
    .pd-lightbox-close:hover{background:rgba(15,23,42,.9);color:#fff;}
    .pd-lightbox-nav{
      position:absolute;
      top:50%;
      transform:translateY(-50%);
      z-index:3;
      width:48px;
      height:48px;
      border:0;
      border-radius:50%;
      background:rgba(15,23,42,.7);
      color:#fff;
      font-size:28px;
      line-height:1;
      cursor:pointer;
      display:flex;
      align-items:center;
      justify-content:center;
      padding:0;
    }
    .pd-lightbox-nav:hover{background:rgba(15,23,42,.9);color:#fff;}
    .pd-lightbox-nav:disabled{opacity:.3;cursor:default;}
    .pd-lightbox-prev{left:16px;}
    .pd-lightbox-next{right:16px;}
    .pd-lightbox .pd-photo-count{
      position:absolute;
      top:16px;
      left:16px;
      z-index:3;
      pointer-events:none;
      cursor:default;
    }
    @media (max-width:640px){
      .pd-lightbox-inner{inset:52px 12px 24px;}
      .pd-lightbox-nav{width:40px;height:40px;font-size:24px;}
      .pd-lightbox-prev{left:8px;}
      .pd-lightbox-next{right:8px;}
      .pd-lightbox-close{top:10px;right:10px;width:40px;height:40px;}
    }
    body.product-detail-page .pd-gallery-thumbs{
      display:flex !important;
      flex-direction:row !important;
      flex-wrap:nowrap !important;
      gap:10px;
      margin-top:14px;
      width:100%;
      max-width:100%;
      overflow-x:auto !important;
      overflow-y:hidden !important;
      -webkit-overflow-scrolling:touch;
      overscroll-behavior-x:contain;
      padding-bottom:6px;
      scrollbar-width:thin;
    }
    body.product-detail-page .pd-gallery-thumb{
      flex:0 0 72px !important;
      width:72px !important;
      min-width:72px !important;
      max-width:72px !important;
      height:72px;
      border:1px solid var(--shop-border,var(--msb-palette-border,#e2e8f0));
      border-radius:2px;
      padding:4px;
      background:var(--shop-card-bg,var(--msb-palette-bg,#fff));
      cursor:pointer;
      overflow:hidden;
      box-sizing:border-box;
    }
    .pd-gallery-thumb.is-active{border-color:var(--shop-text,var(--msb-palette-text,#111827));border-width:2px;padding:3px;}
    .pd-gallery-thumb img{width:100%;height:100%;object-fit:contain;}
    .pd-info-col{
      min-width:0;
      min-height:0;
      display:flex;
      flex-direction:column;
      overflow:hidden;
      color:var(--shop-text,var(--msb-palette-text,#1f2937));
    }
    .pd-info-head{flex:0 0 auto;}
    .pd-tab-scroll{
      flex:1 1 auto;
      min-height:0;
      overflow-y:auto;
      overflow-x:hidden;
      -webkit-overflow-scrolling:touch;
      padding-bottom:8px;
    }
    .pd-info-foot{flex:0 0 auto;padding-top:8px;margin-bottom:40px;}
    .pd-head-row{
      display:flex;
      align-items:flex-start;
      justify-content:space-between;
      gap:16px;
      margin-bottom:10px;
    }
    .pd-title{
      margin:0 0 8px;
      font-size:18px;
      line-height:1.35;
      font-weight:700;
      letter-spacing:.06em;
      text-transform:uppercase;
      color:var(--shop-text,var(--msb-palette-text,#374151));
    }
    .pd-rating-row{display:flex;align-items:center;flex-wrap:wrap;gap:8px;font-size:13px;color:var(--shop-text-muted,var(--msb-palette-text-muted,#6b7280));}
    .pd-stars{color:#556b45;font-size:12px;letter-spacing:2px;}
    .pd-stars .fa-star-o{color:var(--shop-border,var(--msb-palette-border,#cbd5e1));}
    .pd-wishlist{
      flex:0 0 auto;
      border:0;
      background:transparent;
      color:var(--shop-text-muted,var(--msb-palette-text-muted,#6b7280));
      font-size:12px;
      cursor:pointer;
      padding:0;
      white-space:nowrap;
    }
    .pd-wishlist:hover{color:var(--shop-text,var(--msb-palette-text,#374151));}
    .pd-wishlist .fa{margin-right:4px;}
    .pd-price{
      margin:18px 0 20px;
      font-size:16px;
      font-weight:400;
      color:var(--shop-text,var(--msb-palette-text,#374151));
    }
    .pd-purchase{margin-bottom:24px;}
    .pd-qty-label{
      display:block;
      font-size:11px;
      font-weight:700;
      letter-spacing:.08em;
      color:var(--shop-text-muted,var(--msb-palette-text-muted,#6b7280));
      margin-bottom:8px;
    }
    .pd-purchase-row{display:flex;align-items:stretch;gap:12px;flex-wrap:wrap;}
    .pd-qty{
      display:inline-flex;
      align-items:center;
      border:1px solid var(--shop-border,var(--msb-palette-border,#d1d5db));
      background:var(--shop-input-bg,var(--shop-card-bg,#fff));
    }
    .pd-qty-btn{
      width:36px;height:42px;
      border:0;
      background:transparent;
      font-size:18px;
      cursor:pointer;
      color:var(--shop-text-muted,var(--msb-palette-text-muted,#6b7280));
    }
    .pd-qty-btn:hover{color:var(--shop-text,var(--msb-palette-text,#374151));}
    .pd-qty-input{
      width:40px;height:42px;
      border:0;
      border-left:1px solid var(--shop-border,var(--msb-palette-border,#d1d5db));
      border-right:1px solid var(--shop-border,var(--msb-palette-border,#d1d5db));
      text-align:center;
      font-size:14px;
      font-weight:600;
      background:var(--shop-card-bg,var(--msb-palette-bg,#fff));
      color:var(--shop-text,var(--msb-palette-text,#111827));
    }
    .pd-add-cart{
      flex:1 1 140px;
      min-width:140px;
      border:1px solid var(--shop-border,var(--msb-palette-border,rgba(177,188,206,.55)));
      border-radius:0;
      background:var(--shop-btn-filled-bg,var(--msb-palette-btn-bg,#3d4a32));
      color:var(--shop-btn-filled-text,var(--msb-palette-btn-text,#fff));
      font-size:12px;
      font-weight:700;
      letter-spacing:.1em;
      text-transform:uppercase;
      padding:12px 20px;
      cursor:pointer;
    }
    .pd-add-cart:hover:not(:disabled){filter:brightness(1.08);}
    .pd-add-cart:disabled{opacity:.5;cursor:not-allowed;}
    .pd-buy-now{
      flex:1 1 140px;
      min-width:140px;
      border:1px solid var(--shop-btn-outline-border,var(--shop-border,var(--msb-palette-border-strong,#374151)));
      border-radius:0;
      background:var(--shop-btn-outline-bg,var(--shop-card-bg,var(--msb-palette-bg,#fff)));
      color:var(--shop-btn-outline-text,var(--shop-text,var(--msb-palette-text,#111827)));
      font-size:12px;
      font-weight:700;
      letter-spacing:.1em;
      text-transform:uppercase;
      padding:12px 20px;
      cursor:pointer;
    }
    .pd-buy-now:hover{filter:brightness(1.04);}
    .pd-stock-note{margin:0 0 12px;font-size:13px;color:var(--shop-text-soft,#166534);}
    .pd-stock-note.is-out{color:#f87171;}
    .pd-tabs{
      display:flex;
      flex-wrap:wrap;
      gap:0;
      border-top:1px solid var(--shop-border,var(--msb-palette-border,#e5e7eb));
      border-bottom:1px solid var(--shop-border,var(--msb-palette-border,#e5e7eb));
      margin-bottom:0;
    }
    .pd-tab{
      flex:1 1 auto;
      min-width:0;
      border:0;
      background:transparent;
      color:var(--shop-text-muted,var(--msb-palette-text-muted,#6b7280));
      font-size:10px;
      font-weight:700;
      letter-spacing:.06em;
      text-transform:uppercase;
      padding:14px 8px;
      cursor:pointer;
      border-bottom:2px solid transparent;
      margin-bottom:-1px;
    }
    .pd-tab.is-active{
      color:var(--shop-text,var(--msb-palette-text,#374151));
      border-bottom-color:var(--shop-text,var(--msb-palette-text,#374151));
    }
    .pd-tab-panel{
      display:none;
      padding:18px 0 0;
      font-size:14px;
      line-height:1.65;
      color:var(--shop-text-soft,var(--shop-text-muted,#4b5563));
    }
    .pd-tab-panel.is-active{display:block;}
    .pd-tab-panel p{margin:0 0 14px;}
    .pd-tab-panel p:last-child{margin-bottom:0;}
    .pd-tab-panel em{font-style:italic;}
    .pd-tab-panel ul{margin:0;padding:0 0 0 18px;}
    .pd-tab-panel li{margin-bottom:8px;}
    .pd-tab-panel a{color:var(--shop-link,var(--msb-palette-link,#2563eb));}
    .pd-bullets{
      list-style:none;
      margin:0 0 16px;
      padding:0;
    }
    .pd-bullets > li{
      position:relative;
      margin:0 0 10px;
      padding:0 0 0 1.2em;
      line-height:1.55;
      text-align:left;
      text-indent:0;
    }
    .pd-bullets > li:last-child{margin-bottom:0;}
    .pd-bullets > li::before{
      content:'+';
      position:absolute;
      left:0;
      top:0;
      width:1em;
      font-weight:700;
      line-height:1.55;
      color:var(--shop-text,var(--msb-palette-text,#111827));
    }
    .pd-bullets-heading{
      margin:4px 0 10px;
      font-size:13px;
      font-weight:700;
      letter-spacing:.04em;
      text-transform:uppercase;
      color:var(--shop-text-muted,var(--msb-palette-text-muted,#64748b));
    }
    .pd-type-meta{
      display:flex;flex-wrap:wrap;align-items:center;gap:8px;
      margin:0 0 12px;
    }
    .pd-type-pill,.pd-condition-pill{
      display:inline-flex;align-items:center;
      padding:4px 10px;border-radius:999px;font-size:12px;font-weight:700;
      line-height:1.2;
    }
    .pd-type-pill{
      background:var(--shop-hover-bg,var(--msb-palette-surface-2,#f3f4f6));
      color:var(--shop-text,var(--msb-palette-text,#111827));
    }
    .pd-condition-pill{
      background:#ecfdf5;color:#047857;
      border:1px solid #a7f3d0;
    }
    .pd-condition-pill.is-used{
      background:#fff7ed;color:#c2410c;
      border-color:#fed7aa;
    }
    .pd-spec-table{width:100%;border-collapse:collapse;margin:0;}
    .pd-spec-table th,.pd-spec-table td{
      text-align:left;padding:8px 0;border-bottom:1px solid var(--shop-border,#e5e7eb);
      font-size:14px;vertical-align:top;
    }
    .pd-spec-table th{
      width:38%;font-weight:600;color:var(--shop-text-muted,#4b5563);
      padding-right:12px;
    }
    .pd-spec-table tr:last-child th,.pd-spec-table tr:last-child td{border-bottom:0;}
    .pd-seller-info{margin:0 0 14px;}
    .pd-seller-info p{margin:0 0 10px;}
    .pd-seller-tagline{margin:0 0 12px;font-size:14px;color:var(--shop-text-muted,#6b7280);}
    .pd-seller-table{width:100%;border-collapse:collapse;margin:0 0 14px;}
    .pd-seller-table th,.pd-seller-table td{
      text-align:left;padding:8px 0;border-bottom:1px solid var(--shop-border,#e5e7eb);
      font-size:14px;vertical-align:top;
    }
    .pd-seller-table th{
      width:34%;font-weight:600;color:var(--shop-text-muted,#4b5563);
      padding-right:12px;
    }
    .pd-seller-table tr:last-child th,.pd-seller-table tr:last-child td{border-bottom:0;}
    .pd-seller-address{white-space:pre-line;margin:0;}
    .pd-seller-actions{margin:0;padding:0;list-style:none;}
    .pd-seller-actions li{margin:0 0 8px;}
    .pd-badges{
      display:grid;
      grid-template-columns:repeat(4,minmax(0,1fr));
      gap:12px;
      margin:0;
      padding-top:16px;
      border-top:1px solid var(--shop-border,var(--msb-palette-border,#e5e7eb));
    }
    .pd-badge{
      text-align:center;
      font-size:9px;
      font-weight:700;
      letter-spacing:.04em;
      text-transform:uppercase;
      color:var(--shop-text-muted,var(--msb-palette-text-muted,#6b7280));
      line-height:1.35;
    }
    .pd-badge-ic{
      width:36px;height:36px;
      margin:0 auto 8px;
      border-radius:50%;
      border:1px solid var(--shop-border,var(--msb-palette-border,#d1d5db));
      display:flex;
      align-items:center;
      justify-content:center;
      font-size:14px;
      color:var(--shop-text-soft,var(--msb-palette-text-muted,#556b45));
    }
    .pd-empty{text-align:center;padding:56px 20px;color:var(--shop-text-muted,#64748b);}
    .pd-toast{
      position:fixed;bottom:24px;left:50%;transform:translateX(-50%) translateY(12px);
      background:#0f172a;color:#fff;padding:12px 20px;border-radius:999px;
      font-size:14px;font-weight:600;z-index:20000;opacity:0;pointer-events:none;
      transition:opacity .2s ease,transform .2s ease;box-shadow:0 8px 30px rgba(15,23,42,.25);
    }
    .pd-toast.is-visible{opacity:1;transform:translateX(-50%) translateY(0);}
    .shop-buy-modal{
      position:fixed;inset:0;z-index:19000;display:none;align-items:center;justify-content:center;
      padding:20px;background:rgba(15,23,42,.45);
    }
    .shop-buy-modal.is-open{display:flex;}
    .shop-buy-card{
      width:min(440px,100%);background:var(--shop-card-bg,#fff);border-radius:20px;
      box-shadow:0 24px 60px rgba(15,23,42,.2);overflow:hidden;
      color:var(--shop-text,#0f172a);
    }
    .shop-buy-head{padding:20px 22px 6px;font-size:20px;font-weight:800;}
    .shop-buy-sub{padding:0 22px 8px;color:var(--shop-text-muted,#64748b);font-size:15px;font-weight:600;}
    .shop-buy-msg{padding:0 22px 12px;font-size:13px;color:#b45309;}
    .shop-buy-body{padding:0 22px 16px;display:grid;gap:12px;}
    .shop-buy-body label{display:block;font-size:13px;font-weight:600;margin-bottom:4px;}
    .shop-buy-body input,.shop-buy-body textarea{
      width:100%;border:1px solid var(--shop-border,#e2e8f0);border-radius:10px;padding:10px 12px;font-size:14px;box-sizing:border-box;
      background:var(--shop-input-bg,#fff);color:var(--shop-text,inherit);
    }
    .shop-buy-foot{display:flex;gap:10px;padding:0 22px 22px;}
    .shop-buy-foot button{flex:1;border:0;border-radius:12px;padding:12px;font-weight:700;cursor:pointer;}
    .shop-buy-cancel{background:var(--shop-hover-bg,#f1f5f9);color:var(--shop-text,#334155);}
    .shop-buy-submit{background:var(--shop-btn-filled-bg,#2563eb);color:var(--shop-btn-filled-text,#fff);}
    .shop-buy-success{padding:28px 22px;text-align:center;}
    .shop-buy-success-ic{
      width:52px;height:52px;margin:0 auto 14px;border-radius:50%;
      background:#dcfce7;color:#16a34a;display:flex;align-items:center;justify-content:center;font-size:22px;
    }
    .shop-buy-success h3{margin:0 0 8px;font-size:18px;}
    .shop-buy-success p{margin:0 0 18px;font-size:14px;color:var(--shop-text-muted,#64748b);line-height:1.5;}
    .shop-page-head-mobile .shop-page-title{font-size:22px;font-weight:800;padding:8px 0 0;margin:0;color:var(--shop-text,var(--msb-palette-text,#111827));}
    .shop-page-head-mobile .shop-page-sub{padding:4px 0 0;color:var(--shop-text-muted,var(--msb-palette-text-muted,#6b7280));font-size:14px;margin:0;}
    @media (max-width:900px){
      .pd-page{grid-template-columns:1fr;grid-template-rows:auto minmax(0,1fr);gap:20px;}
      .pd-gallery-stage{height:min(100cqw,min(360px,calc(100vh - 380px)));max-height:min(360px,calc(100vh - 380px));}
      .pd-badges{grid-template-columns:repeat(2,minmax(0,1fr));}
    }
    @media (max-width:640px){
      .pd-tabs{flex-direction:column;border-bottom:0;}
      .pd-tab{border-bottom:1px solid var(--shop-border,#e5e7eb);text-align:left;}
      .pd-head-row{flex-direction:column;}
    }
  </style>
</head>
<body class="shop-page feed-page feed-insta-ui product-detail-page">

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
      <?php $feedTopShopActive = true; $feedTopShopOnly = true; include __DIR__ . '/includes/feed_top_actions.php'; ?>
    </div>

    <div class="shop-page-shell">
      <div class="shop-page-head-mobile">
        <h1 class="shop-page-title">Shop</h1>
        <p class="shop-page-sub">Browse products from publishers and buy securely.</p>
      </div>

      <?php if ($notFound): ?>
        <div class="pd-empty">
          <i class="icon ion-bag" style="font-size:42px;display:block;margin-bottom:10px;"></i>
          This product is not available.
        </div>
        <a href="shop.php" class="pd-back" style="margin-top:16px;"><i class="icon ion-ios-arrow-left"></i> Back to shop</a>
      <?php else: ?>
        <div class="pd-page">
          <div class="pd-gallery-col">
            <div class="pd-gallery-body">
            <div class="pd-gallery-main">
              <button type="button" class="pd-gallery-nav" id="pdGalleryPrev" aria-label="Previous image"<?= count($galleryImages) <= 1 ? ' disabled' : '' ?>>‹</button>
              <div class="pd-gallery-stage" id="pdGalleryStage"<?= $galleryImages ? ' role="button" tabindex="0" aria-label="Open photo viewer"' : '' ?>>
                <?php if ($galleryImages): ?>
                  <img src="<?= h($galleryImages[0]) ?>" alt="<?= h((string)$product['title']) ?>" id="pdGalleryImage">
                <?php else: ?>
                  <span class="pd-gallery-fallback"><i class="icon ion-bag"></i></span>
                <?php endif; ?>
              </div>
              <?php if ($galleryImages): ?>
                <button type="button" class="pd-photo-count" id="pdPhotoCount" aria-label="Open photo viewer">
                  <i class="fa fa-camera" aria-hidden="true"></i>
                  <span id="pdPhotoCountLabel">1 / <?= (int)count($galleryImages) ?></span>
                </button>
              <?php endif; ?>
              <button type="button" class="pd-gallery-nav" id="pdGalleryNext" aria-label="Next image"<?= count($galleryImages) <= 1 ? ' disabled' : '' ?>>›</button>
            </div>
            <?php if ($galleryImages): ?>
              <div class="pd-gallery-thumbs" id="pdGalleryThumbs">
                <?php foreach ($galleryImages as $gi => $imgUrl): ?>
                  <button type="button" class="pd-gallery-thumb<?= $gi === 0 ? ' is-active' : '' ?>" data-index="<?= (int)$gi ?>" aria-label="View image <?= (int)$gi + 1 ?>">
                    <img src="<?= h($imgUrl) ?>" alt="">
                  </button>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
            </div>
            <a href="shop.php" class="pd-back pd-gallery-back"><i class="icon ion-ios-arrow-left"></i> Back to shop</a>
          </div>

          <div class="pd-info-col">
            <div class="pd-info-head">
              <div class="pd-head-row">
                <div>
                  <h1 class="pd-title"><?= h((string)$product['title']) ?></h1>
                  <div class="pd-rating-row">
                    <?= pd_render_stars($rating) ?>
                    <span>Based on <?= (int)$reviewCount ?> review<?= (int)$reviewCount === 1 ? '' : 's' ?></span>
                  </div>
                </div>
                <button type="button" class="pd-wishlist" id="pdWishlistBtn" title="Save for later"><i class="fa fa-heart-o" aria-hidden="true"></i> Add to Wishlist</button>
              </div>

              <div class="pd-price" id="pdPrice" data-unit-cents="<?= (int)$priceCents ?>" data-currency="<?= h(strtoupper($currency)) ?>"><?= h($priceDisplay) ?></div>

              <?php if ($productTypeLabel !== '' || $productCondition !== ''): ?>
                <div class="pd-type-meta">
                  <?php if ($productTypeLabel !== ''): ?>
                    <span class="pd-type-pill"><?= h($productTypeLabel) ?></span>
                  <?php endif; ?>
                  <?php if ($productCondition !== ''): ?>
                    <span class="pd-condition-pill<?= stripos($productCondition, 'used') !== false ? ' is-used' : '' ?>"><?= h($productCondition) ?></span>
                  <?php endif; ?>
                </div>
              <?php endif; ?>

              <?php if ($inStock): ?>
                <p class="pd-stock-note">
                  Free delivery by <?= h($deliveryByLong) ?>
                  <?php if ($stockCount !== null): ?> · <?= (int)$stockCount ?> in stock<?php endif; ?>
                </p>
                <div class="pd-purchase">
                  <span class="pd-qty-label">QTY</span>
                  <div class="pd-purchase-row">
                    <div class="pd-qty" aria-label="Quantity">
                      <button type="button" class="pd-qty-btn" id="pdQtyMinus" aria-label="Decrease quantity">−</button>
                      <input type="number" class="pd-qty-input" id="pdQty" name="quantity" min="1" max="99" value="1" aria-label="Quantity">
                      <button type="button" class="pd-qty-btn" id="pdQtyPlus" aria-label="Increase quantity">+</button>
                    </div>
                    <button type="button" class="pd-add-cart" id="pdAddCartBtn">Add to cart</button>
                    <button type="button" class="pd-buy-now" id="pdBuyNowBtn">Buy now</button>
                  </div>
                </div>
              <?php else: ?>
                <p class="pd-stock-note is-out">Out of stock — check back later.</p>
                <div class="pd-purchase">
                  <button type="button" class="pd-add-cart" disabled>Add to cart</button>
                </div>
              <?php endif; ?>

              <nav class="pd-tabs" role="tablist" aria-label="Product information">
                <button type="button" class="pd-tab is-active" role="tab" aria-selected="true" data-tab="description" id="pdTabDescription">Description</button>
                <button type="button" class="pd-tab" role="tab" aria-selected="false" data-tab="details" id="pdTabDetails">Details</button>
                <button type="button" class="pd-tab" role="tab" aria-selected="false" data-tab="guarantee" id="pdTabGuarantee">Guarantee</button>
                <button type="button" class="pd-tab" role="tab" aria-selected="false" data-tab="seller" id="pdTabSeller">Seller</button>
                <button type="button" class="pd-tab" role="tab" aria-selected="false" data-tab="store" id="pdTabStore">Find in store</button>
              </nav>
            </div>

            <div class="pd-tab-scroll" id="pdFeatures">
            <div class="pd-tab-panel is-active" role="tabpanel" id="pdPanelDescription" aria-labelledby="pdTabDescription">
              <?php if ($description !== ''): ?>
                <?= pd_render_description_html($description) ?>
              <?php else: ?>
                <p>Discover <?= h((string)$product['title']) ?> from <?= h($sellerLabel) ?>.</p>
              <?php endif; ?>
              <?php if ($bulletPoints): ?>
                <p class="pd-bullets-heading">What sets this apart?</p>
                <?= pd_render_bullet_list($bulletPoints) ?>
              <?php endif; ?>
            </div>

            <div class="pd-tab-panel" role="tabpanel" id="pdPanelDetails" aria-labelledby="pdTabDetails">
              <?php if ($productSpecRows): ?>
                <table class="pd-spec-table">
                  <tbody>
                    <?php if ($productCode !== ''): ?>
                      <tr><th scope="row">ID</th><td><code><?= h($productCode) ?></code></td></tr>
                    <?php endif; ?>
                    <?php if ($productTypeLabel !== ''): ?>
                      <tr><th scope="row">Product type</th><td><?= h($productTypeLabel) ?></td></tr>
                    <?php endif; ?>
                    <?php if ($category !== ''): ?>
                      <tr><th scope="row">Category</th><td><?= h($category) ?></td></tr>
                    <?php endif; ?>
                    <?php if ($sku !== ''): ?>
                      <tr><th scope="row">SKU</th><td><?= h($sku) ?></td></tr>
                    <?php endif; ?>
                    <?php foreach ($productSpecRows as $specRow): ?>
                      <tr>
                        <th scope="row"><?= h($specRow['label']) ?></th>
                        <td><?= h($specRow['value']) ?></td>
                      </tr>
                    <?php endforeach; ?>
                    <tr>
                      <th scope="row">Fulfillment</th>
                      <td><?= $productFulfillment === 'fba' ? 'FBA (platform warehouse)' : 'FBM (seller ships)' ?></td>
                    </tr>
                  </tbody>
                </table>
              <?php else: ?>
                <ul>
                  <?php if ($productCode !== ''): ?><li>ID: <code><?= h($productCode) ?></code></li><?php endif; ?>
                  <?php if ($sku !== ''): ?><li>SKU: <?= h($sku) ?></li><?php endif; ?>
                  <?php if ($category !== ''): ?><li>Category: <?= h($category) ?></li><?php endif; ?>
                  <?php if ($sellingType !== ''): ?><li>Product type: <?= h($sellingType) ?></li><?php endif; ?>
                  <li>Fulfillment: <?= $productFulfillment === 'fba' ? 'FBA (platform warehouse)' : 'FBM (seller ships)' ?></li>
                  <?php foreach ($featureLines as $featureLine): ?>
                    <li><?= h($featureLine) ?></li>
                  <?php endforeach; ?>
                </ul>
              <?php endif; ?>
            </div>

            <div class="pd-tab-panel" role="tabpanel" id="pdPanelGuarantee" aria-labelledby="pdTabGuarantee">
              <p>
                <?= $shopStripeEnabled
                    ? 'Secure checkout powered by Stripe. Your payment is processed safely and your order is confirmed by the seller.'
                    : 'Orders are placed directly with the seller, who confirms payment and fulfillment.' ?>
              </p>
              <p>Delivery estimate: <?= h($deliveryByLong) ?> · Pay in 4 installments of <?= h($installmentLabel) ?>.</p>
            </div>

            <div class="pd-tab-panel" role="tabpanel" id="pdPanelSeller" aria-labelledby="pdTabSeller">
              <?php
                $sellerDisplayName = trim((string)($sellerPublicInfo['store_name'] ?? '')) !== ''
                  ? (string)$sellerPublicInfo['store_name']
                  : $sellerLabel;
                $sellerFullName = trim((string)($sellerPublicInfo['full_name'] ?? ''));
                $sellerTagline = trim((string)($sellerPublicInfo['tagline'] ?? ''));
                $sellerUsername = trim((string)($product['publisher_username'] ?? ''));
                if ($sellerUsername === '' && $publisherId > 0 && function_exists('org_shop_user_username')) {
                    $sellerUsername = org_shop_user_username($dbh, $publisherId);
                }
                $sellerPhone = trim((string)($sellerPublicInfo['phone'] ?? ''));
                $sellerAddress = trim((string)($sellerPublicInfo['address'] ?? ''));
              ?>
              <div class="pd-seller-info">
                <p>Sold by <a href="profile.php?tab=shop&amp;id=<?= $publisherId ?>"><?= h($sellerDisplayName) ?></a>.</p>
                <?php if ($sellerTagline !== ''): ?>
                  <p class="pd-seller-tagline"><?= h($sellerTagline) ?></p>
                <?php endif; ?>
                <?php if ($sellerFullName !== '' || $sellerUsername !== '' || $sellerPhone !== '' || $sellerAddress !== ''): ?>
                  <table class="pd-seller-table">
                    <tbody>
                      <?php if ($sellerFullName !== ''): ?>
                        <tr><th scope="row">Contact name</th><td><?= h($sellerFullName) ?></td></tr>
                      <?php endif; ?>
                      <?php if ($sellerUsername !== ''): ?>
                        <tr>
                          <th scope="row">Username</th>
                          <td>
                            <?php if ($messageSellerUrl !== ''): ?>
                              <a href="<?= h($messageSellerUrl) ?>" title="Message this seller">@<?= h($sellerUsername) ?></a>
                            <?php else: ?>
                              @<?= h($sellerUsername) ?>
                            <?php endif; ?>
                          </td>
                        </tr>
                      <?php elseif ($messageSellerUrl !== ''): ?>
                        <tr>
                          <th scope="row">Username</th>
                          <td><a href="<?= h($messageSellerUrl) ?>" title="Message this seller">Message seller</a></td>
                        </tr>
                      <?php endif; ?>
                      <?php if ($sellerPhone !== ''): ?>
                        <tr><th scope="row">Phone</th><td><a href="tel:<?= h(preg_replace('/\s+/', '', $sellerPhone) ?? $sellerPhone) ?>"><?= h($sellerPhone) ?></a></td></tr>
                      <?php endif; ?>
                      <?php if ($sellerAddress !== ''): ?>
                        <tr><th scope="row">Address</th><td><p class="pd-seller-address"><?= h($sellerAddress) ?></p></td></tr>
                      <?php endif; ?>
                    </tbody>
                  </table>
                <?php else: ?>
                  <p>Seller contact details are not listed yet.</p>
                <?php endif; ?>
                <ul class="pd-seller-actions">
                  <?php if ($messageSellerUrl !== ''): ?>
                    <li><a href="<?= h($messageSellerUrl) ?>">Message seller about this product</a></li>
                  <?php endif; ?>
                  <li><a href="<?= h($brandShopUrl) ?>">Shop all <?= h($sellerDisplayName) ?> products</a></li>
                </ul>
              </div>
            </div>

            <div class="pd-tab-panel" role="tabpanel" id="pdPanelStore" aria-labelledby="pdTabStore">
              <p>Browse more in the marketplace or filter by brand and category.</p>
              <p><a href="<?= h($brandShopUrl) ?>">View <?= h($sellerLabel) ?> in shop</a>
                <?php if ($category !== ''): ?>
                  · <a href="shop.php?type=<?= urlencode($category) ?>"><?= h($category) ?> category</a>
                <?php endif; ?>
              </p>
            </div>

            </div>

            <div class="pd-info-foot">
              <div class="pd-badges">
                <div class="pd-badge">
                  <div class="pd-badge-ic"><i class="fa fa-truck" aria-hidden="true"></i></div>
                  Free delivery
                </div>
                <div class="pd-badge">
                  <div class="pd-badge-ic"><i class="fa fa-lock" aria-hidden="true"></i></div>
                  Secure checkout
                </div>
                <div class="pd-badge">
                  <div class="pd-badge-ic"><i class="fa fa-check" aria-hidden="true"></i></div>
                  <?= $inStock ? 'In stock' : 'Out of stock' ?>
                </div>
                <div class="pd-badge">
                  <div class="pd-badge-ic"><i class="fa fa-user" aria-hidden="true"></i></div>
                  Sold by seller
                </div>
              </div>
            </div>
          </div>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="pd-toast" id="pdToast" role="status" aria-live="polite"></div>

<?php if (!$notFound && $galleryImages): ?>
<div class="pd-lightbox" id="pdLightbox" hidden aria-hidden="true" role="dialog" aria-modal="true" aria-label="Product photos">
  <button type="button" class="pd-lightbox-close" id="pdLightboxClose" aria-label="Close photo viewer">&times;</button>
  <button type="button" class="pd-photo-count" tabindex="-1" aria-hidden="true">
    <i class="fa fa-camera" aria-hidden="true"></i>
    <span id="pdLightboxCountLabel">1 / <?= (int)count($galleryImages) ?></span>
  </button>
  <button type="button" class="pd-lightbox-nav pd-lightbox-prev" id="pdLightboxPrev" aria-label="Previous photo"<?= count($galleryImages) <= 1 ? ' disabled' : '' ?>>‹</button>
  <div class="pd-lightbox-inner">
    <img src="<?= h($galleryImages[0]) ?>" alt="<?= h((string)$product['title']) ?>" class="pd-lightbox-img" id="pdLightboxImage">
  </div>
  <button type="button" class="pd-lightbox-nav pd-lightbox-next" id="pdLightboxNext" aria-label="Next photo"<?= count($galleryImages) <= 1 ? ' disabled' : '' ?>>›</button>
</div>
<?php endif; ?>

<?php if (!$notFound && !empty($galleryImages)): ?>
<script>
(function(){
  var galleryImages = <?= json_encode(array_values($galleryImages), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  if (!galleryImages.length) return;

  var galleryIndex = 0;
  var lightbox = document.getElementById('pdLightbox');
  var lightboxImg = document.getElementById('pdLightboxImage');
  var countLabel = document.getElementById('pdPhotoCountLabel');
  var lightboxCountLabel = document.getElementById('pdLightboxCountLabel');
  var body = document.body;
  var lastFocus = null;

  function updateCountLabels(){
    var label = (galleryIndex + 1) + ' / ' + galleryImages.length;
    if (countLabel) countLabel.textContent = label;
    if (lightboxCountLabel) lightboxCountLabel.textContent = label;
  }

  function setGalleryIndex(nextIndex){
    galleryIndex = (nextIndex + galleryImages.length) % galleryImages.length;
    var img = document.getElementById('pdGalleryImage');
    if (img) img.src = galleryImages[galleryIndex];
    if (lightboxImg) lightboxImg.src = galleryImages[galleryIndex];
    document.querySelectorAll('.pd-gallery-thumb').forEach(function(btn){
      var idx = parseInt(btn.getAttribute('data-index') || '0', 10);
      btn.classList.toggle('is-active', idx === galleryIndex);
    });
    updateCountLabels();
  }

  function openLightbox(){
    if (!lightbox) return;
    lastFocus = document.activeElement;
    lightbox.hidden = false;
    lightbox.classList.add('is-open');
    lightbox.setAttribute('aria-hidden', 'false');
    body.style.overflow = 'hidden';
    if (lightboxImg) lightboxImg.src = galleryImages[galleryIndex];
    updateCountLabels();
    var closeBtn = document.getElementById('pdLightboxClose');
    if (closeBtn) closeBtn.focus();
  }

  function closeLightbox(){
    if (!lightbox) return;
    lightbox.classList.remove('is-open');
    lightbox.hidden = true;
    lightbox.setAttribute('aria-hidden', 'true');
    body.style.overflow = '';
    if (lastFocus && typeof lastFocus.focus === 'function') {
      try { lastFocus.focus(); } catch (e) {}
    }
  }

  function isLightboxOpen(){
    return !!(lightbox && lightbox.classList.contains('is-open'));
  }

  var galleryPrev = document.getElementById('pdGalleryPrev');
  var galleryNext = document.getElementById('pdGalleryNext');
  if (galleryPrev) galleryPrev.addEventListener('click', function(e){ e.stopPropagation(); setGalleryIndex(galleryIndex - 1); });
  if (galleryNext) galleryNext.addEventListener('click', function(e){ e.stopPropagation(); setGalleryIndex(galleryIndex + 1); });

  document.querySelectorAll('.pd-gallery-thumb').forEach(function(btn){
    btn.addEventListener('click', function(){
      setGalleryIndex(parseInt(btn.getAttribute('data-index') || '0', 10));
    });
  });

  var stage = document.getElementById('pdGalleryStage');
  if (stage) {
    stage.addEventListener('click', function(){ openLightbox(); });
    stage.addEventListener('keydown', function(e){
      if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        openLightbox();
      }
    });
  }

  var photoCountBtn = document.getElementById('pdPhotoCount');
  if (photoCountBtn) {
    photoCountBtn.addEventListener('click', function(e){
      e.stopPropagation();
      openLightbox();
    });
  }

  var lightboxPrev = document.getElementById('pdLightboxPrev');
  var lightboxNext = document.getElementById('pdLightboxNext');
  var lightboxClose = document.getElementById('pdLightboxClose');
  if (lightboxPrev) lightboxPrev.addEventListener('click', function(e){ e.stopPropagation(); setGalleryIndex(galleryIndex - 1); });
  if (lightboxNext) lightboxNext.addEventListener('click', function(e){ e.stopPropagation(); setGalleryIndex(galleryIndex + 1); });
  if (lightboxClose) lightboxClose.addEventListener('click', function(e){ e.stopPropagation(); closeLightbox(); });

  if (lightbox) {
    lightbox.addEventListener('click', function(e){
      if (e.target === lightbox) closeLightbox();
    });
  }

  document.addEventListener('keydown', function(e){
    if (!isLightboxOpen()) return;
    if (e.key === 'Escape') {
      e.preventDefault();
      closeLightbox();
    } else if (e.key === 'ArrowLeft') {
      e.preventDefault();
      setGalleryIndex(galleryIndex - 1);
    } else if (e.key === 'ArrowRight') {
      e.preventDefault();
      setGalleryIndex(galleryIndex + 1);
    }
  });

  updateCountLabels();
})();
</script>
<?php endif; ?>

<?php if (!$notFound && !$outOfStock): ?>
<script>
(function(){
  const qtyEl = document.getElementById('pdQty');
  const minusBtn = document.getElementById('pdQtyMinus');
  const plusBtn = document.getElementById('pdQtyPlus');
  const priceEl = document.getElementById('pdPrice');
  const addBtn = document.getElementById('pdAddCartBtn');
  const buyNowBtn = document.getElementById('pdBuyNowBtn');
  const toastEl = document.getElementById('pdToast');
  const fulfillmentMethod = <?= json_encode($productFulfillment) ?>;
  const unitPriceCents = priceEl ? parseInt(priceEl.getAttribute('data-unit-cents') || '0', 10) : 0;
  const priceCurrency = priceEl ? String(priceEl.getAttribute('data-currency') || 'USD').toUpperCase() : 'USD';
  let toastTimer = null;

  const panelMap = {
    description: 'pdPanelDescription',
    details: 'pdPanelDetails',
    guarantee: 'pdPanelGuarantee',
    seller: 'pdPanelSeller',
    store: 'pdPanelStore'
  };

  document.querySelectorAll('.pd-tab').forEach(function(tab){
    tab.addEventListener('click', function(){
      const key = tab.getAttribute('data-tab') || 'description';
      document.querySelectorAll('.pd-tab').forEach(function(t){
        t.classList.remove('is-active');
        t.setAttribute('aria-selected', 'false');
      });
      document.querySelectorAll('.pd-tab-panel').forEach(function(panel){
        panel.classList.remove('is-active');
      });
      tab.classList.add('is-active');
      tab.setAttribute('aria-selected', 'true');
      const panelId = panelMap[key];
      const panel = panelId ? document.getElementById(panelId) : null;
      if (panel) panel.classList.add('is-active');
      const tabScroll = document.querySelector('.pd-tab-scroll');
      if (tabScroll) tabScroll.scrollTop = 0;
    });
  });

  const wishlistBtn = document.getElementById('pdWishlistBtn');
  if (wishlistBtn) {
    wishlistBtn.addEventListener('click', function(){
      showToast('Wishlist saved for later.');
    });
  }

  function clampQty(value){
    return Math.max(1, Math.min(99, parseInt(String(value || '1'), 10) || 1));
  }

  function getQty(){
    return qtyEl ? clampQty(qtyEl.value) : 1;
  }

  function formatMoney(cents){
    const amount = (Math.max(0, cents) / 100).toFixed(2);
    if (priceCurrency === 'USD') return '$' + amount;
    return amount + ' ' + priceCurrency;
  }

  function formatPriceDisplay(cents){
    return formatMoney(cents) + ' ' + priceCurrency;
  }

  function updatePriceForQty(qty){
    const safeQty = clampQty(qty == null ? getQty() : qty);
    const totalCents = unitPriceCents * safeQty;
    if (priceEl) priceEl.textContent = formatPriceDisplay(totalCents);
    return safeQty;
  }

  function syncQtyAndPrice(nextQty){
    const safeQty = updatePriceForQty(nextQty);
    if (qtyEl) qtyEl.value = String(safeQty);
    return safeQty;
  }

  function showToast(message){
    if (!toastEl) return;
    toastEl.textContent = message;
    toastEl.classList.add('is-visible');
    if (toastTimer) clearTimeout(toastTimer);
    toastTimer = setTimeout(function(){
      toastEl.classList.remove('is-visible');
    }, 3200);
  }

  function openBuyDoor(){
    const qty = getQty();
    const url = 'shop_buy_door.php?embed=1&product_id=<?= (int)$productId ?>&quantity=' + encodeURIComponent(String(qty)) + '&profile_id=<?= (int)$publisherId ?>';
    if (window.TTLiveRight && typeof window.TTLiveRight.open === 'function') {
      window.TTLiveRight.open(url);
      return;
    }
    window.location.href = url.replace('&embed=1', '');
  }

  if (qtyEl) {
    qtyEl.addEventListener('input', function(){
      syncQtyAndPrice(qtyEl.value);
    });
    qtyEl.addEventListener('change', function(){
      syncQtyAndPrice(qtyEl.value);
    });
  }
  if (minusBtn && qtyEl) {
    minusBtn.addEventListener('click', function(){
      syncQtyAndPrice(getQty() - 1);
    });
  }
  if (plusBtn && qtyEl) {
    plusBtn.addEventListener('click', function(){
      syncQtyAndPrice(getQty() + 1);
    });
  }

  updatePriceForQty(1);

  if (addBtn) {
    addBtn.addEventListener('click', async function(){
      addBtn.disabled = true;
      try {
        const body = new URLSearchParams();
        body.set('action', 'add');
        body.set('product_id', '<?= (int)$productId ?>');
        body.set('quantity', String(getQty()));
        body.set('delivery_option', 'home_delivery');
        body.set('fulfillment_method', fulfillmentMethod);
        const res = await fetch('ajax/cart_action.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: body.toString(),
          credentials: 'same-origin'
        });
        const data = await res.json();
        const badge = document.getElementById('feedTopCartBadge');
        if (badge && data.count > 0) badge.textContent = String(data.count);
        showToast(data.message || (data.ok ? 'Added to cart.' : 'Could not add to cart.'));
      } catch (e) {
        showToast('Could not add to cart.');
      } finally {
        addBtn.disabled = false;
      }
    });
  }

  if (buyNowBtn) {
    buyNowBtn.addEventListener('click', openBuyDoor);
  }
})();
</script>
<?php endif; ?>

<script src="./lib/jquery/jquery.js"></script>
<script src="./js/shamcey.js"></script>
<script>
(function(){
  const scrollMain = document.querySelector('.product-detail-page .pd-tab-scroll');
  document.querySelectorAll('a[href="#pdFeatures"]').forEach(link => {
    link.addEventListener('click', function(e){
      const target = document.getElementById('pdFeatures');
      if (!scrollMain || !target) return;
      e.preventDefault();
      scrollMain.scrollTo({ top: 0, behavior: 'smooth' });
      const descTab = document.getElementById('pdTabDescription');
      if (descTab) descTab.click();
    });
  });

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
