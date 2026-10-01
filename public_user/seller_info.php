<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/session_user.php';
requireUserLogin();

require_once __DIR__ . '/controller.php';
require_once __DIR__ . '/includes/org_shop.php';
require_once __DIR__ . '/includes/org_commerce_brands.php';
require_once __DIR__ . '/includes/commerce_messaging.php';
require_once __DIR__ . '/includes/theme_prefs.php';
require_once __DIR__ . '/includes/staff_publisher_access.php';
require_once __DIR__ . '/includes/publisher_accounts_load.php';
require_once __DIR__ . '/includes/profile_cover_slides.php';

$controller = new Controller();
$dbh = $controller->pdo();
$meId = (int)($_SESSION['user_id'] ?? $_SESSION['id'] ?? $_SESSION['userid'] ?? 0);
org_shop_ensure_schema($dbh);
$GLOBALS['feedTopDbh'] = $dbh;
$GLOBALS['feedTopMeId'] = $meId;
$canFollowPublishers = publisher_can_follow_as_viewer($dbh, $meId);

if (!function_exists('h')) {
    function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}

$publisherId = (int)($_GET['id'] ?? $_GET['seller'] ?? 0);
$from = strtolower(trim((string)($_GET['from'] ?? '')));
$tab = strtolower(trim((string)($_GET['tab'] ?? 'home')));
if (!in_array($tab, ['home', 'items', 'reviews', 'about', 'policies', 'qa'], true)) {
    $tab = 'home';
}
$backHref = ($from === 'seller-messages')
    ? commerce_message_seller_url($publisherId)
    : 'shop.php';

$seller = null;
$orgId = 0;
$brandSlug = '';
$brandName = '';
if ($publisherId > 0 && (org_is_commerce_seller_publisher($dbh, $publisherId) || commerce_messaging_publisher_has_shop($dbh, $publisherId))) {
    try {
        $st = $dbh->prepare("
            SELECT
                u.id,
                u.name,
                u.username,
                u.friend_code,
                u.email,
                u.image,
                u.created_at,
                org.id AS org_id,
                org.name AS org_name,
                org.commerce_brand_id,
                cb.slug AS brand_slug,
                cb.name AS brand_name,
                cb.tagline AS brand_tagline
            FROM users u
            INNER JOIN organizations org ON org.publisher_user_id = u.id AND org.status = 1
              AND (
                (org.commerce_brand_id IS NOT NULL AND org.commerce_brand_id > 0
                  AND LOWER(TRIM(COALESCE(org.publisher_category, ''))) IN ('', 'commerce'))
                OR LOWER(TRIM(COALESCE(org.publisher_category, ''))) = 'commerce'
              )
            LEFT JOIN commerce_brands cb ON cb.id = org.commerce_brand_id AND cb.is_active = 1
            WHERE u.id = :id AND u.status = 1
            ORDER BY org.id ASC
            LIMIT 1
        ");
        $st->execute([':id' => $publisherId]);
        $seller = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
        $seller = null;
    }
}

if ($seller) {
    $orgId = (int)($seller['org_id'] ?? 0);
    $brandSlug = trim((string)($seller['brand_slug'] ?? ''));
    $brandName = trim((string)($seller['brand_name'] ?? ''));
}

$info = $orgId > 0
    ? org_shop_seller_pickup_display($dbh, $orgId)
    : ['store_name' => '', 'full_name' => '', 'tagline' => '', 'address' => '', 'phone' => '', 'email' => '', 'has_address' => false, 'text' => ''];

$storeName = trim((string)($info['store_name'] ?? ''));
if ($storeName === '' && $seller) {
    $storeName = trim((string)($seller['org_name'] ?? ''))
        ?: trim((string)($seller['name'] ?? ''))
        ?: trim((string)($seller['username'] ?? 'Seller'));
}
$tagline = trim((string)($info['tagline'] ?? ''));
if ($tagline === '' && $brandName !== '') {
    $tagline = trim((string)($seller['brand_tagline'] ?? ''));
}
$location = trim((string)($info['address'] ?? ''));
$about = $tagline;
if ($about === '') {
    $about = $storeName . ' sells products on Talsora Shop. Message the seller about stock, pickup, or delivery.';
}

$memberSince = '';
if (!empty($seller['created_at'])) {
    $ts = strtotime((string)$seller['created_at']);
    if ($ts) {
        $memberSince = date('M Y', $ts);
    }
}

$itemsSold = 0;
$positivePct = null;
$activeListings = 0;
$products = [];
$categories = [];
$reviews = [];
if ($orgId > 0) {
    try {
        $stSold = $dbh->prepare("
            SELECT COALESCE(SUM(GREATEST(COALESCE(quantity,1),1)), 0)
            FROM org_orders
            WHERE org_id = :org AND status IN ('paid','shipped','delivered','confirmed')
        ");
        $stSold->execute([':org' => $orgId]);
        $itemsSold = (int)($stSold->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        $itemsSold = 0;
    }
    try {
        $stRev = $dbh->prepare('
            SELECT AVG(rating) AS avg_rating, COUNT(*) AS cnt
            FROM org_product_reviews
            WHERE org_id = :org
        ');
        $stRev->execute([':org' => $orgId]);
        $rev = $stRev->fetch(PDO::FETCH_ASSOC) ?: [];
        $cnt = (int)($rev['cnt'] ?? 0);
        if ($cnt > 0) {
            $avg = (float)($rev['avg_rating'] ?? 0);
            $positivePct = (int)round(max(0, min(100, ($avg / 5) * 100)));
        }
    } catch (Throwable $e) {
        $positivePct = null;
    }
    $products = org_shop_list_products($dbh, $orgId, true);
    $activeListings = count($products);
    foreach ($products as $p) {
        $cat = trim((string)($p['category'] ?? ''));
        if ($cat === '') {
            $cat = 'General';
        }
        if (!isset($categories[$cat])) {
            $categories[$cat] = 0;
        }
        $categories[$cat]++;
    }
    arsort($categories);
    try {
        $stReviews = $dbh->prepare('
            SELECT
                r.rating,
                r.review_text,
                r.created_at,
                r.product_id,
                COALESCE(NULLIF(TRIM(u.name), \'\'), u.username, \'Buyer\') AS buyer_name,
                COALESCE(NULLIF(TRIM(p.title), \'\'), \'Product\') AS product_title,
                p.cover_image_path AS product_cover
            FROM org_product_reviews r
            LEFT JOIN users u ON u.id = r.buyer_user_id
            LEFT JOIN org_products p ON p.id = r.product_id
            WHERE r.org_id = :org
            ORDER BY r.created_at DESC
            LIMIT 40
        ');
        $stReviews->execute([':org' => $orgId]);
        $reviews = $stReviews->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $reviews = [];
    }
}

function seller_info_fmt_sold(int $n): string
{
    if ($n >= 1000000) {
        return rtrim(rtrim(number_format($n / 1000000, 1), '0'), '.') . 'M';
    }
    if ($n >= 1000) {
        return rtrim(rtrim(number_format($n / 1000, 1), '0'), '.') . 'K';
    }
    return (string)$n;
}

function seller_info_product_card(array $p): array
{
    $id = (int)($p['id'] ?? 0);
    $title = trim((string)($p['title'] ?? 'Product'));
    $price = org_shop_format_price((int)($p['price_cents'] ?? 0), (string)($p['currency'] ?? 'USD'));
    $cover = org_shop_cover_url((string)($p['cover_image_path'] ?? ''));
    $cat = trim((string)($p['category'] ?? ''));
    $condition = trim((string)($p['selling_type'] ?? ''));
    if ($condition === '') {
        $condition = 'New';
    }
    return [
        'id' => $id,
        'title' => $title,
        'price' => $price,
        'price_cents' => (int)($p['price_cents'] ?? 0),
        'cover' => $cover,
        'category' => $cat !== '' ? $cat : 'General',
        'condition' => $condition,
        'href' => 'product_detail.php?id=' . $id,
    ];
}

$featured = array_slice(array_map('seller_info_product_card', $products), 0, 4);
$allItems = array_map('seller_info_product_card', $products);
$moreFromSeller = array_slice($allItems, 0, 4);

/** Prefer live buyer reviews; no sample/fake feedback once customers have reviewed. */
$recentReviews = array_slice($reviews, 0, 6);
$reviewsAreSample = false;

function seller_info_time_ago(string $at): string
{
    $ts = strtotime($at);
    if (!$ts) {
        return '';
    }
    $diff = max(0, time() - $ts);
    if ($diff < 3600) {
        return max(1, (int)floor($diff / 60)) . ' min ago';
    }
    if ($diff < 86400) {
        return max(1, (int)floor($diff / 3600)) . ' hours ago';
    }
    if ($diff < 86400 * 14) {
        $d = (int)floor($diff / 86400);
        return $d . ' day' . ($d === 1 ? '' : 's') . ' ago';
    }
    if ($diff < 86400 * 60) {
        $w = (int)floor($diff / (86400 * 7));
        return $w . ' week' . ($w === 1 ? '' : 's') . ' ago';
    }
    return date('M j, Y', $ts);
}

function seller_info_stars(int $rating): string
{
    $rating = max(0, min(5, $rating));
    $html = '';
    for ($i = 1; $i <= 5; $i++) {
        $html .= $i <= $rating
            ? '<i class="fa fa-star" aria-hidden="true"></i>'
            : '<i class="fa fa-star-o" aria-hidden="true"></i>';
    }
    return $html;
}

function seller_info_buyer_display_name(string $name): string
{
    $name = trim($name);
    if ($name === '') {
        return 'Buyer';
    }
    $parts = preg_split('/\s+/u', $name) ?: [];
    $parts = array_values(array_filter($parts, static fn($p) => trim((string)$p) !== ''));
    if (count($parts) >= 2) {
        $first = (string)$parts[0];
        $last = (string)$parts[count($parts) - 1];
        $initial = strtoupper(substr(preg_replace('/[^\p{L}]/u', '', $last) ?: '', 0, 1));
        return $initial !== '' ? ($first . ' ' . $initial . '.') : $first;
    }
    return $name;
}

function seller_info_render_review_card(array $r): void
{
    $buyerName = seller_info_buyer_display_name((string)($r['buyer_name'] ?? 'Buyer'));
    $initial = strtoupper(substr(preg_replace('/[^\p{L}]/u', '', $buyerName) ?: 'B', 0, 1));
    $prodTitle = trim((string)($r['product_title'] ?? 'Product'));
    $rawCover = trim((string)($r['product_cover'] ?? ($r['cover'] ?? '')));
    $prodCover = $rawCover;
    if ($prodCover !== '' && strpos($prodCover, 'http') !== 0 && strpos($prodCover, '/') !== 0 && strpos($prodCover, 'avatar.php') !== 0) {
        $prodCover = org_shop_cover_url($prodCover);
    }
    $prodId = (int)($r['product_id'] ?? 0);
    $prodHref = $prodId > 0 ? ('product_detail.php?id=' . $prodId) : '#';
    $helpful = (int)($r['helpful'] ?? 0);
    $when = seller_info_time_ago((string)($r['created_at'] ?? ''));
    $reviewText = trim((string)($r['review_text'] ?? ''));
    ?>
    <article class="si-review-card">
      <div class="si-review-top">
        <div class="si-review-ava"><?= h($initial) ?></div>
        <div class="si-review-who">
          <strong><?= h($buyerName) ?></strong>
          <div class="si-review-rate">
            <span class="si-stars"><?= seller_info_stars((int)($r['rating'] ?? 0)) ?></span>
            <?php if ($when !== ''): ?><span class="si-review-when"><?= h($when) ?></span><?php endif; ?>
          </div>
        </div>
      </div>
      <?php if ($reviewText !== ''): ?>
        <p><?= h($reviewText) ?></p>
      <?php else: ?>
        <p class="si-review-empty-text">Rated <?= (int)($r['rating'] ?? 0) ?> out of 5.</p>
      <?php endif; ?>
      <a class="si-review-prod" href="<?= h($prodHref) ?>">
        <?php if ($prodCover !== ''): ?>
          <img src="<?= h($prodCover) ?>" alt="">
        <?php else: ?>
          <img src="avatar.php?name=<?= rawurlencode($prodTitle) ?>" alt="">
        <?php endif; ?>
        <span><?= h($prodTitle) ?></span>
      </a>
      <div class="si-review-actions">
        <span class="si-helpful"><i class="fa fa-thumbs-o-up" aria-hidden="true"></i> Helpful<?= $helpful > 0 ? ' (' . $helpful . ')' : '' ?></span>
        <button type="button" class="si-review-more" aria-label="More"><i class="fa fa-ellipsis-h" aria-hidden="true"></i></button>
      </div>
    </article>
    <?php
}

$avatarUrl = $seller ? ('avatar.php?u=' . (int)$publisherId . '&s=160&name=' . rawurlencode($storeName)) : '';
$coverUrl = '';
if ($publisherId > 0) {
    $coverPayload = profile_cover_slides_payload($dbh, $publisherId);
    $coverUrl = trim((string)($coverPayload['cover_url'] ?? ''));
}
$messageHref = commerce_message_seller_url($publisherId);
$shopHref = $brandSlug !== '' ? org_commerce_brands_shop_url($brandSlug) : 'shop.php';
$pageTitle = $storeName !== '' ? ($storeName . ' · Seller') : 'Seller info';
$notFound = $seller === null;
$shortDesc = $tagline !== '' ? $tagline : '';
$tabBase = 'seller_info.php?' . http_build_query(array_filter([
    'id' => $publisherId,
    'from' => $from !== '' ? $from : null,
]));
?>
<!doctype html>
<html <?= app_html_lang_attrs() ?>>
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
  <script defer src="assets/layout-fixed.js"></script>
  <style>
    /* No top shop/feed header on seller Info */
    body.seller-info-page{
      --shop-header-h:0px !important;
      --shop-top:0px !important;
      --shop-side:0px !important;
      --shop-outer:12px !important;
      --shop-gutter:0px !important;
      --shop-left-chrome:12px !important;
      --msb-top-header-pad-top:0px !important;
      --msb-top-header-pad-bottom:0px !important;
      --msb-top-story-item:0px !important;
      --shop-left-rail-head-top:0px !important;
      --shop-left-rail-head-height:0px !important;
      --feed-left-rail-top:0px !important;
      overflow:hidden !important;
      height:100vh !important;
      max-height:100vh !important;
    }
    html:has(body.seller-info-page){
      overflow:hidden !important;
      height:100% !important;
    }
    body.seller-info-page,
    body.seller-info-page .sh-mainpanel,
    body.seller-info-page .sh-pagebody{
      background:var(--shop-surface,var(--msb-palette-bg,#f3f5f9)) !important;
      color:var(--shop-text,var(--msb-palette-text,#0f172a));
    }
    body.seller-info-page .ig-feed-header,
    body.seller-info-page .sh-headpanel,
    body.seller-info-page .sh-logopanel,
    body.seller-info-page .sh-pagetitle,
    body.seller-info-page .shop-page-head-mobile,
    body.seller-info-page .shop-header-search-wrap,
    body.seller-info-page .shop-header-search,
    body.seller-info-page .feed-left-rail-page-head{
      display:none !important;
      visibility:hidden !important;
      height:0 !important;
      max-height:0 !important;
      min-height:0 !important;
      margin:0 !important;
      padding:0 !important;
      border:0 !important;
      box-shadow:none !important;
      overflow:hidden !important;
      pointer-events:none !important;
    }
    body.seller-info-page .sh-pagebody,
    body.seller-info-page .sh-mainpanel,
    body.seller-info-page .shop-page-shell{
      margin-top:0 !important;
      padding-top:0 !important;
      border-top:0 !important;
      box-shadow:none !important;
    }
    body.seller-info-page.shop-page.feed-insta-ui .sh-mainpanel,
    body.seller-info-page .sh-mainpanel{
      margin-left:var(--feedRailW, 84px) !important;
      width:calc(100% - var(--feedRailW, 84px)) !important;
      max-width:calc(100% - var(--feedRailW, 84px)) !important;
      padding:0 !important;
      height:100vh !important;
      max-height:100vh !important;
      overflow:hidden !important;
    }
    body.seller-info-page.shop-page.feed-insta-ui .sh-pagebody,
    body.seller-info-page .sh-pagebody{
      margin:0 !important;
      margin-right:0 !important;
      padding:0 !important;
      width:100% !important;
      max-width:none !important;
      height:100% !important;
      max-height:100% !important;
      overflow:hidden !important;
    }
    body.seller-info-page .shop-page-shell,
    body.seller-info-page.shop-page.feed-insta-ui .shop-page-shell{
      max-width:none !important;
      width:100% !important;
      margin:0 !important;
      margin-left:0 !important;
      margin-right:0 !important;
      padding:6px 8px 8px !important;
      padding-left:8px !important;
      padding-right:8px !important;
      overflow:hidden !important;
      box-sizing:border-box !important;
      height:100vh !important;
      max-height:100vh !important;
    }
    body.seller-info-page .app-footer{display:none !important;}
    .si-fit{
      width:100%;overflow:hidden;display:flex;flex-direction:column;
      height:100%;max-height:100%;
    }
    .si-fit-inner{
      width:100%;max-width:none;margin:0;box-sizing:border-box;padding:0;
      transform:none !important;
      display:flex;flex-direction:column;flex:1 1 auto;min-height:0;height:100%;
    }
    .si-layout{
      display:grid;grid-template-columns:minmax(0,1fr) 280px;gap:12px;align-items:stretch;
      width:100%;flex:1 1 auto;min-height:0;height:100%;
    }
    .si-main{min-width:0;display:flex;flex-direction:column;gap:4px;min-height:0;height:100%;}
    .si-side{display:flex;flex-direction:column;gap:8px;min-height:0;overflow:auto;scrollbar-width:none;-ms-overflow-style:none;}
    .si-side::-webkit-scrollbar{width:0;height:0;display:none;}
    .si-card{
      background:var(--shop-card-bg,var(--msb-palette-bg,#fff));
      border:1px solid var(--shop-border,var(--msb-palette-border,#e5e7eb));
      border-radius:10px;box-shadow:0 1px 2px rgba(15,23,42,.04);
      color:var(--shop-text,var(--msb-palette-text,#0f172a));
    }
    .si-content{
      display:flex;flex-direction:column;
      flex:1 1 auto;min-height:0;margin-top:-2px;
    }
    .si-content .si-panel{flex:1 1 auto;min-height:0;overflow:auto;scrollbar-width:none;-ms-overflow-style:none;}
    .si-content .si-panel::-webkit-scrollbar{width:0;height:0;display:none;}
    .si-hero{position:relative;overflow:hidden;border-radius:10px;flex:0 0 auto;}
    .si-banner{position:relative;height:180px;}
    .si-banner img{width:100%;height:100%;object-fit:cover;object-position:center center;display:block;}
    .si-profile{
      display:grid;grid-template-columns:68px minmax(0,1fr) auto;gap:10px;align-items:start;
      padding:0 12px 8px;margin-top:-30px;position:relative;z-index:2;
    }
    .si-avatar{
      width:68px;height:68px;border-radius:999px;overflow:hidden;
      border:3px solid var(--shop-card-bg,var(--msb-palette-bg,#fff));
      background:var(--shop-card-raised,var(--msb-palette-surface-2,#e2e8f0));
      box-shadow:0 3px 10px rgba(15,23,42,.16);
    }
    .si-avatar img{width:100%;height:100%;object-fit:cover;display:block;}
    .si-id{min-width:0;padding-top:32px;}
    .si-name{margin:0;font-size:18px;font-weight:800;letter-spacing:-.02em;display:flex;align-items:center;gap:5px;flex-wrap:wrap;color:var(--shop-text,var(--msb-palette-text,#0f172a));line-height:1.1;}
    .si-name .si-check{color:var(--shop-link,var(--msb-palette-action,#2563eb));font-size:14px;}
    .si-desc{margin:2px 0 0;font-size:11.5px;line-height:1.3;color:var(--shop-text-muted,var(--msb-palette-text-muted,#64748b));}
    .si-desc .si-role{font-weight:700;color:var(--shop-text-soft,var(--msb-palette-text-muted,#475569));}
    .si-meta{margin:5px 0 0;display:flex;flex-wrap:wrap;gap:5px 9px;align-items:center;font-size:11px;color:var(--shop-text-muted,var(--msb-palette-text-muted,#64748b));font-weight:600;}
    .si-meta > span{display:inline-flex;align-items:center;gap:4px;}
    .si-meta i{color:var(--shop-text-muted,var(--msb-palette-text-muted,#94a3b8));width:11px;text-align:center;font-size:10px;}
    .si-badge{
      display:inline-flex;align-items:center;gap:4px;padding:2px 7px;border-radius:999px;
      font-size:10.5px;font-weight:750;
      background:var(--shop-card-raised,var(--msb-palette-surface-2,#f1f5f9));
      color:var(--shop-text,var(--msb-palette-text,#334155));
    }
    .si-badge.is-live{background:var(--msb-palette-action-soft,rgba(34,197,94,.12));color:#15803d;}
    .si-badge.is-live i{font-size:6px;color:#22c55e;}
    .si-badge.is-official{
      background:var(--msb-palette-action-soft,rgba(37,99,235,.12));
      color:var(--shop-link,var(--msb-palette-action,#1d4ed8));
    }
    .si-badge.is-official i{color:var(--shop-link,var(--msb-palette-action,#2563eb));}
    .si-actions{display:flex;align-items:center;gap:6px;padding-top:32px;}
    .si-btn{
      display:inline-flex;align-items:center;justify-content:center;gap:5px;min-height:30px;padding:0 10px;
      border-radius:7px;font-size:12px;font-weight:800;text-decoration:none;cursor:pointer;border:1px solid transparent;
    }
    .si-btn-primary{
      background:var(--shop-btn-filled-bg,var(--msb-palette-btn-bg,var(--msb-palette-action,#2563eb)));
      color:var(--shop-btn-filled-text,var(--msb-palette-btn-text,#fff));
      border-color:var(--shop-btn-filled-bg,var(--msb-palette-action,#2563eb));
    }
    .si-btn-primary:hover{
      background:var(--msb-palette-action-strong,var(--msb-palette-action,#1d4ed8));
      color:var(--shop-btn-filled-text,var(--msb-palette-btn-text,#fff));
      text-decoration:none;
    }
    .si-btn-outline{
      background:var(--shop-btn-outline-bg,var(--shop-card-bg,var(--msb-palette-bg,#fff)));
      color:var(--shop-btn-outline-text,var(--shop-text,var(--msb-palette-text,#334155)));
      border-color:var(--shop-border,var(--msb-palette-border,#d1d5db));
    }
    .si-btn-outline:hover{background:var(--shop-hover-bg,var(--msb-palette-hover-bg,#f8fafc));text-decoration:none;}
    .si-btn-icon{width:30px;padding:0;}
    .si-stats{
      display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:7px;
      padding:0 10px 6px;background:var(--shop-card-bg,var(--msb-palette-bg,#fff));
    }
    .si-stat{
      display:flex;align-items:center;gap:7px;padding:7px 8px;
      border:1px solid var(--shop-border,var(--msb-palette-border,#e8edf3));
      border-radius:8px;background:var(--shop-card-raised,var(--msb-palette-surface-2,#fafbfc));min-width:0;
    }
    .si-stat-ico{
      width:26px;height:26px;border-radius:999px;
      background:var(--msb-palette-action-soft,rgba(37,99,235,.12));
      color:var(--shop-link,var(--msb-palette-action,#2563eb));
      display:inline-flex;align-items:center;justify-content:center;flex:0 0 auto;font-size:11px;
    }
    .si-stat-ico.is-star{background:rgba(245,158,11,.14);color:#f59e0b;}
    .si-stat-ico.is-clock,.si-stat-ico.is-truck{
      background:var(--shop-card-raised,var(--msb-palette-surface-2,#f1f5f9));
      color:var(--shop-text-muted,var(--msb-palette-text-muted,#64748b));
    }
    .si-stat-ico.is-bag{
      background:var(--msb-palette-action-soft,rgba(37,99,235,.12));
      color:var(--shop-link,var(--msb-palette-action,#2563eb));
    }
    .si-stat strong{display:block;font-size:12px;font-weight:800;color:var(--shop-text,var(--msb-palette-text,#0f172a));line-height:1.15;}
    .si-stat span{display:block;margin-top:0;font-size:10px;font-weight:600;color:var(--shop-text-muted,var(--msb-palette-text-muted,#64748b));}
    .si-tabs{
      display:flex;align-items:stretch;gap:0;padding:0 6px;
      border-bottom:1px solid var(--shop-border,var(--msb-palette-border,#e5e7eb));
      background:var(--shop-card-bg,var(--msb-palette-bg,#fff));overflow:auto;border-radius:10px 10px 0 0;
    }
    .si-tab{
      flex:0 0 auto;padding:8px 11px;border:0;background:transparent;
      color:var(--shop-text-muted,var(--msb-palette-text-muted,#64748b));font-size:12px;font-weight:800;
      text-decoration:none;border-bottom:2px solid transparent;white-space:nowrap;
    }
    .si-tab:hover{color:var(--shop-text,var(--msb-palette-text,#0f172a));text-decoration:none;}
    .si-tab.is-active{
      color:var(--shop-link,var(--msb-palette-action,#2563eb));
      border-bottom-color:var(--shop-link,var(--msb-palette-action,#2563eb));
    }
    .si-tab-back{
      margin-left:auto;flex:0 0 auto;align-self:center;padding:8px 10px;
      font-size:12px;font-weight:800;color:var(--shop-link,var(--msb-palette-action,#2563eb));text-decoration:none;white-space:nowrap;
      border:0;background:transparent;
    }
    .si-tab-back:hover{color:var(--msb-palette-action-strong,var(--msb-palette-action,#1d4ed8));text-decoration:underline;}
    .si-tab-back i{margin-right:4px;font-size:10px;}
    .si-panel{padding:10px 10px 12px;}
    .si-sec-head{display:flex;align-items:center;justify-content:space-between;gap:8px;margin:0 0 7px;}
    .si-sec-head h2{margin:0;font-size:13px;font-weight:800;color:var(--shop-text,var(--msb-palette-text,#0f172a));}
    .si-sec-head a{font-size:11.5px;font-weight:700;color:var(--shop-link,var(--msb-palette-action,#2563eb));text-decoration:none;}
    .si-sec-head a:hover{text-decoration:underline;}
    .si-featured{
      display:grid;grid-template-columns:repeat(auto-fill,minmax(136px,152px));gap:8px;margin-bottom:10px;
      justify-content:start;
    }
    .si-grid{
      display:grid;grid-template-columns:repeat(auto-fill,minmax(136px,152px));gap:8px;
      justify-content:start;
    }
    .si-item{
      display:flex;flex-direction:column;
      border:1px solid var(--shop-border,var(--msb-palette-border,#e8edf3));
      border-radius:8px;overflow:hidden;
      background:var(--shop-card-bg,var(--msb-palette-bg,#fff));
      text-decoration:none;color:inherit;min-width:0;max-width:152px;width:100%;position:relative;
    }
    .si-item:hover{border-color:var(--shop-border-strong,var(--msb-palette-border-strong,#cbd5e1));text-decoration:none;color:inherit;}
    .si-item-media{position:relative;height:92px;background:var(--shop-card-raised,var(--msb-palette-surface-2,#f1f5f9));overflow:hidden;}
    .si-item-media img{width:100%;height:100%;object-fit:cover;object-position:center;display:block;}
    .si-item-heart{
      position:absolute;top:5px;right:5px;width:20px;height:20px;border-radius:999px;border:0;
      background:color-mix(in srgb, var(--shop-card-bg,var(--msb-palette-bg,#fff)) 94%, transparent);
      color:var(--shop-text-muted,var(--msb-palette-text-muted,#64748b));display:inline-flex;align-items:center;justify-content:center;font-size:9.5px;
      box-shadow:0 1px 2px rgba(15,23,42,.12);
    }
    .si-item-body{padding:6px 7px 7px;}
    .si-item-title{margin:0;font-size:10.5px;font-weight:650;line-height:1.25;color:var(--shop-text-soft,var(--msb-palette-text,#334155));
      display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;min-height:2.5em;}
    .si-item-price{margin:3px 0 0;font-size:12px;font-weight:800;color:var(--shop-text,var(--msb-palette-text,#0f172a));}
    .si-item-tags{display:flex;flex-wrap:wrap;gap:3px;margin-top:5px;}
    .si-item-tags span{
      display:inline-flex;padding:1px 6px;border-radius:999px;
      background:var(--shop-card-raised,var(--msb-palette-surface-2,#f1f5f9));
      color:var(--shop-text-muted,var(--msb-palette-text-muted,#64748b));font-size:9px;font-weight:700;line-height:1.5;
    }
    .si-toolbar{display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin:0 0 8px;}
    .si-toolbar-search{position:relative;flex:1 1 160px;min-width:120px;}
    .si-toolbar-search i{position:absolute;left:9px;top:50%;transform:translateY(-50%);color:var(--shop-text-muted,var(--msb-palette-text-muted,#94a3b8));font-size:11px;}
    .si-toolbar-search input{
      width:100%;height:30px;border:1px solid var(--shop-border,var(--msb-palette-border,#e2e8f0));border-radius:7px;
      padding:0 9px 0 26px;font-size:12px;background:var(--shop-input-bg,var(--msb-palette-input-bg,#f8fafc));
      color:var(--shop-text,var(--msb-palette-text,#0f172a));outline:none;
    }
    .si-toolbar select,.si-view-toggle{
      height:30px;border:1px solid var(--shop-border,var(--msb-palette-border,#e2e8f0));border-radius:7px;
      background:var(--shop-card-bg,var(--msb-palette-bg,#fff));padding:0 7px;font-size:11.5px;font-weight:700;
      color:var(--shop-text,var(--msb-palette-text,#334155));
    }
    .si-view-toggle{display:inline-flex;align-items:center;padding:0;overflow:hidden;}
    .si-view-toggle button{width:28px;height:28px;border:0;background:transparent;color:var(--shop-text-muted,var(--msb-palette-text-muted,#64748b));cursor:pointer;}
    .si-view-toggle button.is-active{
      background:var(--msb-palette-action-soft,rgba(37,99,235,.12));
      color:var(--shop-link,var(--msb-palette-action,#2563eb));
    }
    .si-side-card{padding:9px 10px;}
    .si-side-card h3{margin:0 0 6px;font-size:12.5px;font-weight:800;color:var(--shop-text,var(--msb-palette-text,#0f172a));}
    .si-side-card p{margin:0 0 7px;font-size:11px;line-height:1.35;color:var(--shop-text-muted,var(--msb-palette-text-muted,#64748b));}
    .si-reviews{
      display:grid;grid-template-columns:1fr;gap:0;
      border:1px solid var(--shop-border,var(--msb-palette-border,#e8edf3));border-radius:8px;overflow:hidden;
      background:var(--shop-card-bg,var(--msb-palette-bg,#fff));
      max-width:100%;
    }
    .si-side-reviews{padding-bottom:8px;}
    .si-side-reviews .si-side-head{margin-bottom:6px;}
    .si-review-card{
      border:0;border-radius:0;padding:7px 8px;background:var(--shop-card-bg,var(--msb-palette-bg,#fff));min-width:0;
    }
    .si-review-card + .si-review-card{border-left:0;border-top:1px solid var(--shop-border,var(--msb-palette-border,#e8edf3));}
    .si-review-top{display:flex;align-items:flex-start;gap:5px;}
    .si-review-ava{
      width:20px;height:20px;border-radius:999px;
      background:var(--shop-card-raised,var(--msb-palette-surface-2,#e5e7eb));
      color:var(--shop-text-muted,var(--msb-palette-text-muted,#475569));font-size:9px;font-weight:800;
      display:inline-flex;align-items:center;justify-content:center;flex:0 0 auto;
    }
    .si-review-who{min-width:0;flex:1 1 auto;}
    .si-review-who strong{display:block;font-size:10.5px;font-weight:800;color:var(--shop-text,var(--msb-palette-text,#0f172a));line-height:1.15;}
    .si-review-rate{display:flex;align-items:center;gap:4px;margin-top:1px;flex-wrap:wrap;}
    .si-review-who .si-stars{color:#f59e0b;font-size:8px;letter-spacing:.4px;}
    .si-review-when{font-size:9px;color:var(--shop-text-muted,var(--msb-palette-text-muted,#94a3b8));font-weight:600;white-space:nowrap;}
    .si-review-card > p{
      margin:4px 0 0;font-size:10px;line-height:1.3;color:var(--shop-text-soft,var(--msb-palette-text-muted,#475569));
      display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;
    }
    .si-review-empty-text{color:var(--shop-text-muted,var(--msb-palette-text-muted,#94a3b8))!important;font-style:italic;-webkit-line-clamp:1!important;}
    .si-review-prod{
      display:inline-flex;align-items:center;gap:5px;margin-top:5px;max-width:100%;
      text-decoration:none;color:var(--shop-link,var(--msb-palette-action,#2563eb));min-width:0;
    }
    .si-review-prod:hover{text-decoration:underline;color:var(--msb-palette-action-strong,var(--msb-palette-action,#1d4ed8));}
    .si-review-prod img{width:18px;height:18px;border-radius:4px;object-fit:cover;background:var(--shop-card-raised,var(--msb-palette-surface-2,#f1f5f9));flex:0 0 auto;}
    .si-review-prod span{font-size:9.5px;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:var(--shop-link,var(--msb-palette-action,#2563eb));}
    .si-review-actions{display:flex;align-items:center;justify-content:flex-end;gap:6px;margin-top:5px;}
    .si-helpful{
      display:inline-flex;align-items:center;gap:3px;border:0;background:transparent;padding:0;
      font-size:9.5px;font-weight:700;color:var(--shop-link,var(--msb-palette-action,#2563eb));cursor:default;
    }
    .si-review-more{
      width:18px;height:18px;border:0;background:transparent;color:var(--shop-text-muted,var(--msb-palette-text-muted,#94a3b8));cursor:pointer;
      display:inline-flex;align-items:center;justify-content:center;border-radius:5px;padding:0;font-size:10px;
    }
    .si-review-more:hover{background:var(--shop-hover-bg,var(--msb-palette-hover-bg,#f1f5f9));color:var(--shop-text-muted,var(--msb-palette-text-muted,#64748b));}
    .si-review-foot{display:none;}
    .si-review-list{
      display:grid;gap:0;
      border:1px solid var(--shop-border,var(--msb-palette-border,#e8edf3));
      border-radius:8px;overflow:hidden;max-width:460px;
      background:var(--shop-card-bg,var(--msb-palette-bg,#fff));
    }
    .si-review-list .si-review-card + .si-review-card{border-left:0;border-top:1px solid var(--shop-border,var(--msb-palette-border,#e8edf3));}
    .si-contact-head{display:flex;align-items:flex-start;gap:7px;margin-bottom:7px;}
    .si-contact-head i{
      width:26px;height:26px;border-radius:7px;
      background:var(--msb-palette-action-soft,rgba(37,99,235,.12));
      color:var(--shop-link,var(--msb-palette-action,#2563eb));
      display:inline-flex;align-items:center;justify-content:center;flex:0 0 auto;font-size:12px;
    }
    .si-contact-head strong{display:block;font-size:12px;font-weight:800;color:var(--shop-text,var(--msb-palette-text,#0f172a));}
    .si-contact-head span{display:block;margin-top:1px;font-size:11px;line-height:1.3;color:var(--shop-text-muted,var(--msb-palette-text-muted,#64748b));}
    .si-info-list,.si-side-list{list-style:none;margin:0;padding:0;display:grid;gap:6px;}
    .si-info-list li,.si-side-list li{display:flex;align-items:flex-start;gap:6px;font-size:11px;font-weight:650;color:var(--shop-text,var(--msb-palette-text,#334155));line-height:1.25;}
    .si-info-list i,.si-side-list i{width:12px;margin-top:1px;text-align:center;color:var(--shop-link,var(--msb-palette-action,#2563eb));flex:0 0 auto;font-size:11px;}
    .si-info-list .fa-star,.si-side-list .fa-star{color:#f59e0b;}
    .si-side-head{display:flex;align-items:center;justify-content:space-between;gap:6px;margin:0 0 4px;}
    .si-side-head h3{margin:0;}
    .si-side-head a{font-size:11px;font-weight:700;color:var(--shop-link,var(--msb-palette-action,#2563eb));text-decoration:none;}
    .si-btn-block{width:100%;}
    .si-cat-list{list-style:none;margin:0;padding:0;}
    .si-cat-list a{
      display:grid;grid-template-columns:12px minmax(0,1fr) auto auto;gap:5px;align-items:center;
      padding:5px 0;border-top:1px solid var(--shop-border,var(--msb-palette-border,#f1f5f9));
      text-decoration:none;color:var(--shop-text,var(--msb-palette-text,#334155));font-size:11px;font-weight:650;
    }
    .si-cat-list li:first-child a{border-top:0;padding-top:0;}
    .si-cat-list a:hover{color:var(--shop-link,var(--msb-palette-action,#2563eb));text-decoration:none;}
    .si-cat-list i.fa-tag{color:var(--shop-text-muted,var(--msb-palette-text-muted,#94a3b8));font-size:10px;}
    .si-cat-list .si-cat-count{color:var(--shop-text-muted,var(--msb-palette-text-muted,#94a3b8));font-size:10.5px;font-weight:700;}
    .si-cat-list .fa-angle-right{color:var(--shop-text-muted,var(--msb-palette-text-muted,#cbd5e1));font-size:11px;}
    .si-loc-link{display:flex;align-items:center;gap:7px;text-decoration:none;color:inherit;padding:0;}
    .si-loc-link:hover{text-decoration:none;color:inherit;}
    .si-loc-link > i.fa-map-marker{
      width:24px;height:24px;border-radius:7px;
      background:var(--msb-palette-action-soft,rgba(37,99,235,.12));
      color:var(--shop-link,var(--msb-palette-action,#2563eb));
      display:inline-flex;align-items:center;justify-content:center;flex:0 0 auto;font-size:11px;
    }
    .si-loc-link span{flex:1 1 auto;min-width:0;font-size:11px;font-weight:700;color:var(--shop-text,var(--msb-palette-text,#334155));line-height:1.25;}
    .si-loc-link .fa-angle-right{color:var(--shop-text-muted,var(--msb-palette-text-muted,#cbd5e1));}
    .si-empty{padding:18px 10px;text-align:center;color:var(--shop-text-muted,var(--msb-palette-text-muted,#64748b));font-size:12px;}
    .si-banner{
      background:linear-gradient(120deg,
        color-mix(in srgb, var(--msb-palette-action,#1e3a8a) 55%, #0f172a),
        var(--msb-palette-action,#2563eb) 55%,
        color-mix(in srgb, var(--msb-palette-action,#3b82f6) 70%, #93c5fd)
      ) !important;
    }
    @media (max-width:720px){
      .si-layout{grid-template-columns:1fr;}
      .si-featured,.si-grid{grid-template-columns:repeat(auto-fill,minmax(128px,144px));}
      .si-item{max-width:144px;}
      .si-stats{grid-template-columns:repeat(2,minmax(0,1fr));}
      .si-review-card + .si-review-card{border-left:0;border-top:1px solid var(--shop-border,var(--msb-palette-border,#e8edf3));}
      .si-reviews{grid-template-columns:1fr;max-width:100%;}
    }
    @media (max-width:520px){
      .si-profile{grid-template-columns:56px minmax(0,1fr);gap:8px;}
      .si-avatar{width:56px;height:56px;}
      .si-id,.si-actions{padding-top:28px;}
      .si-actions{grid-column:1/-1;width:100%;padding-top:0;}
      .si-actions .si-btn-primary,.si-actions .si-btn-outline{flex:1 1 auto;}
      .si-banner{height:140px;}
      .si-featured,.si-grid{grid-template-columns:repeat(3,minmax(0,1fr));gap:6px;}
      .si-item{max-width:none;}
      .si-item-media{height:84px;}
      .si-reviews,.si-stats{grid-template-columns:1fr;}
    }

    /* Force Seller Info whites → Dark auto / Progress / Appearance palette */
    html.dark-auto body.seller-info-page,
    html[data-theme="dark"] body.seller-info-page,
    html[data-msb-appearance] body.seller-info-page,
    html.msb-palette-active body.seller-info-page{
      --shop-surface:var(--msb-palette-bg,#171d24);
      --shop-card-bg:var(--msb-palette-bg,#171d24);
      --shop-card-raised:var(--msb-palette-surface-2,var(--msb-palette-bg,#1e2733));
      --shop-text:var(--msb-palette-text,#f3f6fb);
      --shop-text-muted:var(--msb-palette-text-muted,#cbd5e1);
      --shop-text-soft:var(--msb-palette-text-muted,#cbd5e1);
      --shop-border:var(--msb-palette-border,rgba(255,255,255,.12));
      --shop-border-strong:var(--msb-palette-border-strong,rgba(255,255,255,.18));
      --shop-input-bg:var(--msb-palette-input-bg,var(--msb-palette-surface-2,#1e2733));
      --shop-link:var(--msb-palette-link,var(--msb-palette-action,#60a5fa));
      --shop-btn-filled-bg:var(--msb-palette-btn-bg,var(--msb-palette-action,#2563eb));
      --shop-btn-filled-text:var(--msb-palette-btn-text,#fff);
      --shop-btn-outline-bg:var(--msb-palette-surface-2,var(--msb-palette-bg,#1e2733));
      --shop-btn-outline-text:var(--msb-palette-text,#f3f6fb);
      --shop-hover-bg:var(--msb-palette-hover-bg,rgba(255,255,255,.06));
      background:var(--shop-surface)!important;
      color:var(--shop-text)!important;
    }
    html.dark-auto body.seller-info-page .sh-mainpanel,
    html.dark-auto body.seller-info-page .sh-pagebody,
    html.dark-auto body.seller-info-page .shop-page-shell,
    html.dark-auto body.seller-info-page .si-fit,
    html.dark-auto body.seller-info-page .si-fit-inner,
    html[data-theme="dark"] body.seller-info-page .sh-mainpanel,
    html[data-theme="dark"] body.seller-info-page .sh-pagebody,
    html[data-theme="dark"] body.seller-info-page .shop-page-shell,
    html[data-msb-appearance] body.seller-info-page .sh-mainpanel,
    html[data-msb-appearance] body.seller-info-page .sh-pagebody,
    html[data-msb-appearance] body.seller-info-page .shop-page-shell,
    html[data-msb-appearance] body.seller-info-page .si-fit,
    html[data-msb-appearance] body.seller-info-page .si-fit-inner,
    html.msb-palette-active body.seller-info-page .sh-mainpanel,
    html.msb-palette-active body.seller-info-page .sh-pagebody,
    html.msb-palette-active body.seller-info-page .shop-page-shell{
      background:var(--shop-surface,var(--msb-palette-bg,#171d24))!important;
      background-color:var(--shop-surface,var(--msb-palette-bg,#171d24))!important;
      color:var(--shop-text,var(--msb-palette-text,#f3f6fb))!important;
    }
    html.dark-auto body.seller-info-page .si-card,
    html.dark-auto body.seller-info-page .si-item,
    html.dark-auto body.seller-info-page .si-stats,
    html.dark-auto body.seller-info-page .si-tabs,
    html.dark-auto body.seller-info-page .si-reviews,
    html.dark-auto body.seller-info-page .si-review-card,
    html.dark-auto body.seller-info-page .si-review-list,
    html.dark-auto body.seller-info-page .si-toolbar select,
    html.dark-auto body.seller-info-page .si-view-toggle,
    html.dark-auto body.seller-info-page .si-btn-outline,
    html[data-theme="dark"] body.seller-info-page .si-card,
    html[data-theme="dark"] body.seller-info-page .si-item,
    html[data-theme="dark"] body.seller-info-page .si-stats,
    html[data-theme="dark"] body.seller-info-page .si-tabs,
    html[data-theme="dark"] body.seller-info-page .si-reviews,
    html[data-theme="dark"] body.seller-info-page .si-review-card,
    html[data-theme="dark"] body.seller-info-page .si-review-list,
    html[data-msb-appearance] body.seller-info-page .si-card,
    html[data-msb-appearance] body.seller-info-page .si-item,
    html[data-msb-appearance] body.seller-info-page .si-stats,
    html[data-msb-appearance] body.seller-info-page .si-tabs,
    html[data-msb-appearance] body.seller-info-page .si-reviews,
    html[data-msb-appearance] body.seller-info-page .si-review-card,
    html[data-msb-appearance] body.seller-info-page .si-review-list,
    html[data-msb-appearance] body.seller-info-page .si-toolbar select,
    html[data-msb-appearance] body.seller-info-page .si-view-toggle,
    html[data-msb-appearance] body.seller-info-page .si-btn-outline,
    html.msb-palette-active body.seller-info-page .si-card,
    html.msb-palette-active body.seller-info-page .si-item,
    html.msb-palette-active body.seller-info-page .si-stats,
    html.msb-palette-active body.seller-info-page .si-tabs,
    html.msb-palette-active body.seller-info-page .si-reviews,
    html.msb-palette-active body.seller-info-page .si-review-card,
    html.msb-palette-active body.seller-info-page .si-review-list,
    html.msb-palette-active body.seller-info-page .si-toolbar select,
    html.msb-palette-active body.seller-info-page .si-view-toggle,
    html.msb-palette-active body.seller-info-page .si-btn-outline{
      background:var(--shop-card-bg,var(--msb-palette-bg,#171d24))!important;
      background-color:var(--shop-card-bg,var(--msb-palette-bg,#171d24))!important;
      border-color:var(--shop-border,var(--msb-palette-border,rgba(255,255,255,.12)))!important;
      color:var(--shop-text,var(--msb-palette-text,#f3f6fb))!important;
      box-shadow:none!important;
    }
    html.dark-auto body.seller-info-page .si-stat,
    html.dark-auto body.seller-info-page .si-item-media,
    html.dark-auto body.seller-info-page .si-item-tags span,
    html.dark-auto body.seller-info-page .si-badge:not(.is-live):not(.is-official),
    html.dark-auto body.seller-info-page .si-review-ava,
    html.dark-auto body.seller-info-page .si-toolbar-search input,
    html[data-theme="dark"] body.seller-info-page .si-stat,
    html[data-msb-appearance] body.seller-info-page .si-stat,
    html[data-msb-appearance] body.seller-info-page .si-item-media,
    html[data-msb-appearance] body.seller-info-page .si-item-tags span,
    html[data-msb-appearance] body.seller-info-page .si-badge:not(.is-live):not(.is-official),
    html[data-msb-appearance] body.seller-info-page .si-review-ava,
    html[data-msb-appearance] body.seller-info-page .si-toolbar-search input,
    html.msb-palette-active body.seller-info-page .si-stat,
    html.msb-palette-active body.seller-info-page .si-item-media,
    html.msb-palette-active body.seller-info-page .si-item-tags span,
    html.msb-palette-active body.seller-info-page .si-badge:not(.is-live):not(.is-official),
    html.msb-palette-active body.seller-info-page .si-review-ava,
    html.msb-palette-active body.seller-info-page .si-toolbar-search input{
      background:var(--shop-card-raised,var(--msb-palette-surface-2,#1e2733))!important;
      background-color:var(--shop-card-raised,var(--msb-palette-surface-2,#1e2733))!important;
      border-color:var(--shop-border,var(--msb-palette-border,rgba(255,255,255,.12)))!important;
      color:var(--shop-text,var(--msb-palette-text,#f3f6fb))!important;
    }
    html.dark-auto body.seller-info-page .si-name,
    html.dark-auto body.seller-info-page .si-stat strong,
    html.dark-auto body.seller-info-page .si-sec-head h2,
    html.dark-auto body.seller-info-page .si-side-card h3,
    html.dark-auto body.seller-info-page .si-item-price,
    html.dark-auto body.seller-info-page .si-review-who strong,
    html.dark-auto body.seller-info-page .si-contact-head strong,
    html.dark-auto body.seller-info-page .si-info-list li,
    html.dark-auto body.seller-info-page .si-side-list li,
    html.dark-auto body.seller-info-page .si-cat-list a,
    html.dark-auto body.seller-info-page .si-loc-link span,
    html[data-msb-appearance] body.seller-info-page .si-name,
    html[data-msb-appearance] body.seller-info-page .si-stat strong,
    html[data-msb-appearance] body.seller-info-page .si-sec-head h2,
    html[data-msb-appearance] body.seller-info-page .si-side-card h3,
    html[data-msb-appearance] body.seller-info-page .si-item-price,
    html[data-msb-appearance] body.seller-info-page .si-review-who strong,
    html[data-msb-appearance] body.seller-info-page .si-contact-head strong,
    html[data-msb-appearance] body.seller-info-page .si-info-list li,
    html[data-msb-appearance] body.seller-info-page .si-side-list li,
    html[data-msb-appearance] body.seller-info-page .si-cat-list a,
    html[data-msb-appearance] body.seller-info-page .si-loc-link span,
    html.msb-palette-active body.seller-info-page .si-name,
    html.msb-palette-active body.seller-info-page .si-stat strong,
    html.msb-palette-active body.seller-info-page .si-sec-head h2,
    html.msb-palette-active body.seller-info-page .si-side-card h3,
    html.msb-palette-active body.seller-info-page .si-item-price,
    html.msb-palette-active body.seller-info-page .si-review-who strong,
    html.msb-palette-active body.seller-info-page .si-contact-head strong{
      color:var(--shop-text,var(--msb-palette-text,#f3f6fb))!important;
    }
    html.dark-auto body.seller-info-page .si-desc,
    html.dark-auto body.seller-info-page .si-meta,
    html.dark-auto body.seller-info-page .si-stat span,
    html.dark-auto body.seller-info-page .si-tab,
    html.dark-auto body.seller-info-page .si-side-card p,
    html.dark-auto body.seller-info-page .si-review-when,
    html.dark-auto body.seller-info-page .si-review-card > p,
    html.dark-auto body.seller-info-page .si-contact-head span,
    html.dark-auto body.seller-info-page .si-empty,
    html.dark-auto body.seller-info-page .si-item-title,
    html[data-msb-appearance] body.seller-info-page .si-desc,
    html[data-msb-appearance] body.seller-info-page .si-meta,
    html[data-msb-appearance] body.seller-info-page .si-stat span,
    html[data-msb-appearance] body.seller-info-page .si-tab,
    html[data-msb-appearance] body.seller-info-page .si-side-card p,
    html[data-msb-appearance] body.seller-info-page .si-review-when,
    html[data-msb-appearance] body.seller-info-page .si-review-card > p,
    html[data-msb-appearance] body.seller-info-page .si-contact-head span,
    html[data-msb-appearance] body.seller-info-page .si-empty,
    html[data-msb-appearance] body.seller-info-page .si-item-title,
    html.msb-palette-active body.seller-info-page .si-desc,
    html.msb-palette-active body.seller-info-page .si-meta,
    html.msb-palette-active body.seller-info-page .si-stat span,
    html.msb-palette-active body.seller-info-page .si-tab,
    html.msb-palette-active body.seller-info-page .si-side-card p,
    html.msb-palette-active body.seller-info-page .si-review-when,
    html.msb-palette-active body.seller-info-page .si-review-card > p,
    html.msb-palette-active body.seller-info-page .si-empty,
    html.msb-palette-active body.seller-info-page .si-item-title{
      color:var(--shop-text-muted,var(--msb-palette-text-muted,#cbd5e1))!important;
    }
    html.dark-auto body.seller-info-page .si-tab.is-active,
    html.dark-auto body.seller-info-page .si-tab-back,
    html.dark-auto body.seller-info-page .si-name .si-check,
    html.dark-auto body.seller-info-page .si-sec-head a,
    html.dark-auto body.seller-info-page .si-side-head a,
    html.dark-auto body.seller-info-page .si-helpful,
    html.dark-auto body.seller-info-page .si-review-prod,
    html.dark-auto body.seller-info-page .si-review-prod span,
    html[data-msb-appearance] body.seller-info-page .si-tab.is-active,
    html[data-msb-appearance] body.seller-info-page .si-tab-back,
    html[data-msb-appearance] body.seller-info-page .si-name .si-check,
    html[data-msb-appearance] body.seller-info-page .si-sec-head a,
    html[data-msb-appearance] body.seller-info-page .si-side-head a,
    html[data-msb-appearance] body.seller-info-page .si-helpful,
    html[data-msb-appearance] body.seller-info-page .si-review-prod,
    html[data-msb-appearance] body.seller-info-page .si-review-prod span,
    html.msb-palette-active body.seller-info-page .si-tab.is-active,
    html.msb-palette-active body.seller-info-page .si-tab-back,
    html.msb-palette-active body.seller-info-page .si-name .si-check,
    html.msb-palette-active body.seller-info-page .si-sec-head a,
    html.msb-palette-active body.seller-info-page .si-side-head a,
    html.msb-palette-active body.seller-info-page .si-helpful,
    html.msb-palette-active body.seller-info-page .si-review-prod,
    html.msb-palette-active body.seller-info-page .si-review-prod span{
      color:var(--shop-link,var(--msb-palette-action,#60a5fa))!important;
    }
    html.dark-auto body.seller-info-page .si-tab.is-active,
    html[data-msb-appearance] body.seller-info-page .si-tab.is-active,
    html.msb-palette-active body.seller-info-page .si-tab.is-active{
      border-bottom-color:var(--shop-link,var(--msb-palette-action,#60a5fa))!important;
    }
    html.dark-auto body.seller-info-page .si-btn-primary,
    html[data-msb-appearance] body.seller-info-page .si-btn-primary,
    html.msb-palette-active body.seller-info-page .si-btn-primary{
      background:var(--shop-btn-filled-bg,var(--msb-palette-btn-bg,var(--msb-palette-action,#2563eb)))!important;
      border-color:var(--shop-btn-filled-bg,var(--msb-palette-action,#2563eb))!important;
      color:var(--shop-btn-filled-text,var(--msb-palette-btn-text,#fff))!important;
    }
    html.dark-auto body.seller-info-page .si-avatar,
    html[data-msb-appearance] body.seller-info-page .si-avatar,
    html.msb-palette-active body.seller-info-page .si-avatar{
      border-color:var(--shop-card-bg,var(--msb-palette-bg,#171d24))!important;
      background:var(--shop-card-raised,var(--msb-palette-surface-2,#1e2733))!important;
    }
  </style>
</head>
<body class="shop-page feed-page feed-insta-ui seller-info-page">

<?php
  $GLOBALS['msb_skip_header_leftbar'] = true;
  $skipHeaderThemeBootstrap = true;
  include __DIR__ . '/includes/header.php';
?>

<div class="sh-mainpanel">
  <?php include __DIR__ . '/includes/leftbar.php'; ?>
  <?php include __DIR__ . '/includes/stories_right_door.php'; ?>
  <div class="sh-pagebody">
    <div class="shop-page-shell">
      <div class="si-fit"><div class="si-fit-inner">
      <?php if ($notFound): ?>
        <div class="si-card si-empty">
          <p>This seller profile is not available.</p>
          <p><a href="shop.php">Back to Shop</a></p>
        </div>
      <?php else: ?>
        <div class="si-layout">
          <div class="si-main">
            <section class="si-card si-hero">
              <div class="si-banner">
                <?php if ($coverUrl !== ''): ?>
                  <img src="<?= h($coverUrl) ?>" alt="">
                <?php endif; ?>
              </div>
              <div class="si-profile">
                <div class="si-avatar">
                  <?php if ($avatarUrl !== ''): ?><img src="<?= h($avatarUrl) ?>" alt=""><?php endif; ?>
                </div>
                <div class="si-id">
                  <h1 class="si-name">
                    <?= h($storeName) ?>
                    <i class="fa fa-check-circle si-check" title="Verified business" aria-label="Verified"></i>
                  </h1>
                  <p class="si-desc"><span class="si-role">Business seller</span><?php if ($shortDesc !== ''): ?> · <?= h($shortDesc) ?><?php endif; ?></p>
                  <div class="si-meta">
                    <?php if ($location !== ''): ?>
                      <span><i class="fa fa-map-marker" aria-hidden="true"></i><?= h($location) ?></span>
                    <?php endif; ?>
                    <?php if ($memberSince !== ''): ?>
                      <span><i class="fa fa-calendar-o" aria-hidden="true"></i>Member since <?= h($memberSince) ?></span>
                    <?php endif; ?>
                    <span class="si-badge is-live"><i class="fa fa-circle" aria-hidden="true"></i> Active now</span>
                    <span class="si-badge is-official"><i class="fa fa-certificate" aria-hidden="true"></i> Official manufacturer</span>
                  </div>
                </div>
                <div class="si-actions">
                  <a class="si-btn si-btn-primary" href="<?= h($messageHref) ?>"><i class="fa fa-commenting-o" aria-hidden="true"></i> Message</a>
                  <?php if ($canFollowPublishers): ?>
                    <button type="button" class="si-btn si-btn-outline" id="siFollowBtn" data-publisher-id="<?= (int)$publisherId ?>">
                      <i class="fa fa-heart-o" aria-hidden="true"></i> Follow
                    </button>
                  <?php endif; ?>
                  <a class="si-btn si-btn-outline si-btn-icon" href="<?= h($backHref) ?>" aria-label="More"><i class="fa fa-ellipsis-h" aria-hidden="true"></i></a>
                </div>
              </div>
              <div class="si-stats" aria-label="Seller stats">
                <div class="si-stat">
                  <span class="si-stat-ico is-bag" aria-hidden="true"><i class="fa fa-shopping-bag"></i></span>
                  <div>
                    <strong><?= h(seller_info_fmt_sold($itemsSold)) ?></strong>
                    <span>Items sold</span>
                  </div>
                </div>
                <div class="si-stat">
                  <span class="si-stat-ico is-star" aria-hidden="true"><i class="fa fa-star"></i></span>
                  <div>
                    <strong><?= $positivePct !== null ? h((string)$positivePct) . '%' : '—' ?></strong>
                    <span>Positive feedback</span>
                  </div>
                </div>
                <div class="si-stat">
                  <span class="si-stat-ico is-clock" aria-hidden="true"><i class="fa fa-clock-o"></i></span>
                  <div>
                    <strong>1–2 days</strong>
                    <span>Avg. response time</span>
                  </div>
                </div>
                <div class="si-stat">
                  <span class="si-stat-ico is-truck" aria-hidden="true"><i class="fa fa-truck"></i></span>
                  <div>
                    <strong>2–5 days</strong>
                    <span>Shipping (avg.)</span>
                  </div>
                </div>
              </div>
            </section>

            <section class="si-card si-content">
              <nav class="si-tabs" aria-label="Seller sections">
                <a class="si-tab<?= $tab === 'home' ? ' is-active' : '' ?>" href="<?= h($tabBase . '&tab=home') ?>">Home</a>
                <a class="si-tab<?= $tab === 'items' ? ' is-active' : '' ?>" href="<?= h($tabBase . '&tab=items') ?>">Items</a>
                <a class="si-tab<?= $tab === 'reviews' ? ' is-active' : '' ?>" href="<?= h($tabBase . '&tab=reviews') ?>">Reviews</a>
                <a class="si-tab<?= $tab === 'about' ? ' is-active' : '' ?>" href="<?= h($tabBase . '&tab=about') ?>">About</a>
                <a class="si-tab<?= $tab === 'policies' ? ' is-active' : '' ?>" href="<?= h($tabBase . '&tab=policies') ?>">Policies</a>
                <a class="si-tab<?= $tab === 'qa' ? ' is-active' : '' ?>" href="<?= h($tabBase . '&tab=qa') ?>">Q&amp;A</a>
                <a class="si-tab-back" href="<?= h($messageHref) ?>"><i class="fa fa-angle-left" aria-hidden="true"></i>Back</a>
              </nav>

              <div class="si-panel">
                <?php if ($tab === 'home'): ?>
                  <?php if ($featured): ?>
                    <div class="si-sec-head">
                      <h2>Featured items</h2>
                      <a href="<?= h($tabBase . '&tab=items') ?>">View all</a>
                    </div>
                    <div class="si-featured">
                      <?php foreach ($featured as $item): ?>
                        <a class="si-item" href="<?= h($item['href']) ?>">
                          <div class="si-item-media">
                            <?php if ($item['cover'] !== ''): ?>
                              <img src="<?= h($item['cover']) ?>" alt="">
                            <?php else: ?>
                              <img src="avatar.php?name=<?= rawurlencode($item['title']) ?>" alt="">
                            <?php endif; ?>
                            <span class="si-item-heart" aria-hidden="true"><i class="fa fa-heart-o"></i></span>
                          </div>
                          <div class="si-item-body">
                            <p class="si-item-title"><?= h($item['title']) ?></p>
                            <p class="si-item-price"><?= h($item['price']) ?></p>
                            <div class="si-item-tags">
                              <span><?= h($item['condition']) ?></span>
                              <span><?= h($item['category']) ?></span>
                            </div>
                          </div>
                        </a>
                      <?php endforeach; ?>
                    </div>
                  <?php else: ?>
                    <div class="si-empty">No featured items yet.</div>
                  <?php endif; ?>

                <?php elseif ($tab === 'items'): ?>
                  <div class="si-sec-head">
                    <h2>All items (<?= (int)$activeListings ?>)</h2>
                  </div>
                  <div class="si-toolbar">
                    <div class="si-toolbar-search">
                      <i class="fa fa-search" aria-hidden="true"></i>
                      <input type="search" id="siItemSearch" placeholder="Search in this seller's items..." autocomplete="off">
                    </div>
                    <select id="siItemCategory" aria-label="Category">
                      <option value="">Category</option>
                      <?php foreach ($categories as $catName => $catCount): ?>
                        <option value="<?= h(strtolower($catName)) ?>"><?= h($catName) ?> (<?= (int)$catCount ?>)</option>
                      <?php endforeach; ?>
                    </select>
                    <select id="siItemSort" aria-label="Sort">
                      <option value="match">Sort: Best Match</option>
                      <option value="price-asc">Price: Low to High</option>
                      <option value="price-desc">Price: High to Low</option>
                    </select>
                    <div class="si-view-toggle" role="group" aria-label="View">
                      <button type="button" class="is-active" aria-label="Grid view"><i class="fa fa-th-large"></i></button>
                      <button type="button" aria-label="List view"><i class="fa fa-list"></i></button>
                    </div>
                  </div>

                  <?php if (!$allItems): ?>
                    <div class="si-empty">No active listings yet.</div>
                  <?php else: ?>
                    <div class="si-grid" id="siItemGrid">
                      <?php foreach ($allItems as $item): ?>
                        <a class="si-item"
                          href="<?= h($item['href']) ?>"
                          data-title="<?= h(strtolower($item['title'])) ?>"
                          data-category="<?= h(strtolower($item['category'])) ?>"
                          data-price="<?= (int)$item['price_cents'] ?>"
                        >
                          <div class="si-item-media">
                            <?php if ($item['cover'] !== ''): ?>
                              <img src="<?= h($item['cover']) ?>" alt="">
                            <?php else: ?>
                              <img src="avatar.php?name=<?= rawurlencode($item['title']) ?>" alt="">
                            <?php endif; ?>
                            <span class="si-item-heart" aria-hidden="true"><i class="fa fa-heart-o"></i></span>
                          </div>
                          <div class="si-item-body">
                            <p class="si-item-title"><?= h($item['title']) ?></p>
                            <p class="si-item-price"><?= h($item['price']) ?></p>
                            <div class="si-item-tags">
                              <span><?= h($item['condition']) ?></span>
                              <span><?= h($item['category']) ?></span>
                            </div>
                          </div>
                        </a>
                      <?php endforeach; ?>
                    </div>
                  <?php endif; ?>

                <?php elseif ($tab === 'about'): ?>
                  <h2 style="margin:0 0 10px;font-size:16px;font-weight:800;">About this seller</h2>
                  <p style="margin:0 0 14px;font-size:14px;line-height:1.55;color:var(--shop-text-muted,var(--msb-palette-text-muted,#475569));"><?= h($about) ?></p>
                  <ul class="si-side-list">
                    <li><i class="fa fa-shopping-bag" aria-hidden="true"></i> <?= $activeListings > 0 ? h((string)$activeListings . ' active listing' . ($activeListings === 1 ? '' : 's')) : 'Shop seller' ?></li>
                    <li><i class="fa fa-shield" aria-hidden="true"></i> Verified business</li>
                    <?php if ($location !== ''): ?>
                      <li><i class="fa fa-map-marker" aria-hidden="true"></i> <?= h($location) ?></li>
                    <?php endif; ?>
                    <li><i class="fa fa-clock-o" aria-hidden="true"></i> Usually responds in 1–2 days</li>
                  </ul>

                <?php elseif ($tab === 'reviews'): ?>
                  <div class="si-review-list">
                    <?php if ($reviews): ?>
                      <?php foreach ($reviews as $r): ?>
                        <?php seller_info_render_review_card($r); ?>
                      <?php endforeach; ?>
                    <?php else: ?>
                      <div class="si-empty">No buyer reviews yet. Reviews appear after a delivered order is rated.</div>
                    <?php endif; ?>
                  </div>

                <?php elseif ($tab === 'policies'): ?>
                  <h2 style="margin:0 0 10px;font-size:16px;font-weight:800;">Seller policies</h2>
                  <p style="margin:0;font-size:14px;line-height:1.55;color:var(--shop-text-muted,var(--msb-palette-text-muted,#475569));">
                    Contact this seller about returns, pickup, delivery windows, and order changes.
                    Use <strong>Message</strong> for product or order questions only.
                  </p>

                <?php else: ?>
                  <div class="si-empty">No public Q&amp;A yet. Message the seller about a product or order.</div>
                <?php endif; ?>
              </div>
            </section>
          </div>

          <aside class="si-side" aria-label="Seller sidebar">
            <div class="si-card si-side-card">
              <div class="si-contact-head">
                <i class="fa fa-commenting" aria-hidden="true"></i>
                <div>
                  <strong>Contact seller</strong>
                  <span>Have a question? Send a message to <?= h($storeName) ?>. Seller contact only — ask about products, orders, pickup, or delivery.</span>
                </div>
              </div>
              <a class="si-btn si-btn-primary si-btn-block" href="<?= h($messageHref) ?>"><i class="fa fa-commenting-o" aria-hidden="true"></i> Message seller</a>
            </div>

            <div class="si-card si-side-card">
              <h3>Seller information</h3>
              <ul class="si-info-list">
                <li><i class="fa fa-certificate" aria-hidden="true"></i> Official manufacturer</li>
                <li><i class="fa fa-shield" aria-hidden="true"></i> Verified business</li>
                <?php if ($location !== ''): ?>
                  <li><i class="fa fa-map-marker" aria-hidden="true"></i> <?= h($location) ?></li>
                <?php endif; ?>
                <?php if ($memberSince !== ''): ?>
                  <li><i class="fa fa-calendar-o" aria-hidden="true"></i> Member since <?= h($memberSince) ?></li>
                <?php endif; ?>
                <li><i class="fa fa-clock-o" aria-hidden="true"></i> Typically responds within 1–2 days</li>
                <li><i class="fa fa-shopping-bag" aria-hidden="true"></i> <?= h(seller_info_fmt_sold($itemsSold)) ?> items sold</li>
                <li><i class="fa fa-star" aria-hidden="true"></i> <?= $positivePct !== null ? h((string)$positivePct) . '%' : '—' ?> positive feedback</li>
              </ul>
            </div>

            <div class="si-card si-side-card">
              <div class="si-side-head">
                <h3>Shop categories</h3>
                <a href="<?= h($tabBase . '&tab=items') ?>">View all</a>
              </div>
              <?php if (!$categories): ?>
                <p>No categories yet.</p>
              <?php else: ?>
                <ul class="si-cat-list">
                  <?php foreach ($categories as $catName => $catCount): ?>
                    <li>
                      <a href="<?= h($tabBase . '&tab=items') ?>#siItemGrid" data-si-cat="<?= h(strtolower((string)$catName)) ?>">
                        <i class="fa fa-tag" aria-hidden="true"></i>
                        <span><?= h((string)$catName) ?></span>
                        <span class="si-cat-count"><?= (int)$catCount ?></span>
                        <i class="fa fa-angle-right" aria-hidden="true"></i>
                      </a>
                    </li>
                  <?php endforeach; ?>
                </ul>
              <?php endif; ?>
            </div>

            <?php if ($location !== ''): ?>
              <div class="si-card si-side-card">
                <a class="si-loc-link" href="<?= h($tabBase . '&tab=about') ?>">
                  <i class="fa fa-map-marker" aria-hidden="true"></i>
                  <span><?= h($location) ?></span>
                  <i class="fa fa-angle-right" aria-hidden="true"></i>
                </a>
              </div>
            <?php endif; ?>

            <div class="si-card si-side-card si-side-reviews">
              <div class="si-side-head">
                <h3>Recent reviews</h3>
                <a href="<?= h($tabBase . '&tab=reviews') ?>">View all</a>
              </div>
              <div class="si-reviews">
                <?php if ($recentReviews): ?>
                  <?php foreach ($recentReviews as $r): ?>
                    <?php seller_info_render_review_card($r); ?>
                  <?php endforeach; ?>
                <?php else: ?>
                  <div class="si-empty" style="padding:12px 8px;font-size:12px;">No customer reviews yet.</div>
                <?php endif; ?>
              </div>
            </div>
          </aside>
        </div>
      <?php endif; ?>
      </div></div>
    </div>
  </div>
</div>

<?php // Footer nav hidden on seller storefront (Info) page. ?>
<script>
(function () {
  var followBtn = document.getElementById('siFollowBtn');
  if (followBtn) {
    followBtn.addEventListener('click', function () {
      var pid = followBtn.getAttribute('data-publisher-id') || '0';
      var body = new URLSearchParams();
      body.set('publisher_id', pid);
      body.set('action', 'follow');
      fetch('ajax/follow.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body.toString(),
        credentials: 'same-origin'
      }).then(function (r) { return r.json(); }).then(function (data) {
        if (data && data.ok) {
          followBtn.innerHTML = '<i class="fa fa-heart" aria-hidden="true"></i> Following';
          followBtn.disabled = true;
        }
      }).catch(function () {});
    });
  }

  var grid = document.getElementById('siItemGrid');
  var search = document.getElementById('siItemSearch');
  var cat = document.getElementById('siItemCategory');
  var sort = document.getElementById('siItemSort');
  var items = grid ? Array.prototype.slice.call(grid.querySelectorAll('.si-item')) : [];

  function applyFilters() {
    if (!grid) return;
    var q = String(search && search.value || '').trim().toLowerCase();
    var c = String(cat && cat.value || '').trim().toLowerCase();
    items.forEach(function (el) {
      var title = String(el.getAttribute('data-title') || '');
      var category = String(el.getAttribute('data-category') || '');
      var ok = (!q || title.indexOf(q) !== -1) && (!c || category === c);
      el.style.display = ok ? '' : 'none';
    });
    if (!sort) return;
    var mode = String(sort.value || 'match');
    if (mode === 'match') return;
    var visible = items.filter(function (el) { return el.style.display !== 'none'; });
    visible.sort(function (a, b) {
      var pa = parseInt(a.getAttribute('data-price') || '0', 10);
      var pb = parseInt(b.getAttribute('data-price') || '0', 10);
      return mode === 'price-asc' ? (pa - pb) : (pb - pa);
    });
    visible.forEach(function (el) { grid.appendChild(el); });
  }

  if (search) search.addEventListener('input', applyFilters);
  if (cat) cat.addEventListener('change', applyFilters);
  if (sort) sort.addEventListener('change', applyFilters);

  Array.prototype.slice.call(document.querySelectorAll('[data-si-cat]')).forEach(function (a) {
    a.addEventListener('click', function (e) {
      var v = String(a.getAttribute('data-si-cat') || '');
      if (!v || !cat || !grid) return;
      e.preventDefault();
      cat.value = v;
      applyFilters();
      grid.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
  });
})();
</script>
</body>
</html>
