<?php
declare(strict_types=1);

/**
 * JSON seller storefront — mirrors seller_info.php for the Swift app.
 * GET id|seller=<publisher_user_id>&from=seller-messages
 */

require_once __DIR__ . '/../includes/session_user.php';
requireUserLogin();
require_once __DIR__ . '/../controller.php';
require_once __DIR__ . '/../includes/org_shop.php';
require_once __DIR__ . '/../includes/org_commerce_brands.php';
require_once __DIR__ . '/../includes/commerce_messaging.php';
require_once __DIR__ . '/../includes/staff_publisher_access.php';
require_once __DIR__ . '/../includes/profile_cover_slides.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$controller = new Controller();
$dbh = $controller->pdo();
$meId = (int)($_SESSION['user_id'] ?? $_SESSION['id'] ?? $_SESSION['userid'] ?? 0);
org_shop_ensure_schema($dbh);

$publisherId = (int)($_GET['id'] ?? $_GET['seller'] ?? $_POST['id'] ?? 0);
$from = strtolower(trim((string)($_GET['from'] ?? '')));

if ($publisherId <= 0) {
    echo json_encode(['ok' => false, 'message' => 'Invalid seller.']);
    exit;
}

$canFollow = function_exists('publisher_can_follow_as_viewer')
    ? publisher_can_follow_as_viewer($dbh, $meId)
    : false;

$seller = null;
$orgId = 0;
$brandSlug = '';
$brandName = '';
$brandTagline = '';

$isCommerce = (function_exists('org_is_commerce_seller_publisher') && org_is_commerce_seller_publisher($dbh, $publisherId))
    || (function_exists('commerce_messaging_publisher_has_shop') && commerce_messaging_publisher_has_shop($dbh, $publisherId));

if ($isCommerce) {
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

if (!$seller) {
    echo json_encode(['ok' => false, 'message' => 'This seller profile is not available.', 'not_found' => true]);
    exit;
}

$orgId = (int)($seller['org_id'] ?? 0);
$brandSlug = trim((string)($seller['brand_slug'] ?? ''));
$brandName = trim((string)($seller['brand_name'] ?? ''));
$brandTagline = trim((string)($seller['brand_tagline'] ?? ''));

$info = $orgId > 0
    ? org_shop_seller_pickup_display($dbh, $orgId)
    : ['store_name' => '', 'tagline' => '', 'address' => '', 'phone' => '', 'email' => ''];

$storeName = trim((string)($info['store_name'] ?? ''));
if ($storeName === '') {
    $storeName = trim((string)($seller['org_name'] ?? ''))
        ?: trim((string)($seller['name'] ?? ''))
        ?: trim((string)($seller['username'] ?? 'Seller'));
}
$tagline = trim((string)($info['tagline'] ?? ''));
if ($tagline === '' && $brandTagline !== '') {
    $tagline = $brandTagline;
}
$location = trim((string)($info['address'] ?? ''));
$about = $tagline !== ''
    ? $tagline
    : ($storeName . ' sells products on Talsora Shop. Message the seller about stock, pickup, or delivery.');

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

$fmtSold = static function (int $n): string {
    if ($n >= 1000000) {
        return rtrim(rtrim(number_format($n / 1000000, 1), '0'), '.') . 'M';
    }
    if ($n >= 1000) {
        return rtrim(rtrim(number_format($n / 1000, 1), '0'), '.') . 'K';
    }
    return (string)$n;
};

$timeAgo = static function (string $at): string {
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
};

$buyerDisplay = static function (string $name): string {
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
};

$productCard = static function (array $p): array {
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
        'price_label' => $price,
        'price_cents' => (int)($p['price_cents'] ?? 0),
        'cover' => $cover,
        'cover_url' => $cover,
        'category' => $cat !== '' ? $cat : 'General',
        'condition' => $condition,
        'selling_type' => $condition,
    ];
};

$allItems = array_map($productCard, $products);
$featured = array_slice($allItems, 0, 4);

$reviewRows = [];
foreach ($reviews as $r) {
    $rawCover = trim((string)($r['product_cover'] ?? ''));
    $prodCover = $rawCover;
    if ($prodCover !== '' && strpos($prodCover, 'http') !== 0 && strpos($prodCover, '/') !== 0) {
        $prodCover = org_shop_cover_url($prodCover);
    }
    $buyerName = $buyerDisplay((string)($r['buyer_name'] ?? 'Buyer'));
    $reviewRows[] = [
        'rating' => (int)($r['rating'] ?? 0),
        'review_text' => trim((string)($r['review_text'] ?? '')),
        'created_at' => (string)($r['created_at'] ?? ''),
        'time_ago' => $timeAgo((string)($r['created_at'] ?? '')),
        'buyer_name' => $buyerName,
        'product_id' => (int)($r['product_id'] ?? 0),
        'product_title' => trim((string)($r['product_title'] ?? 'Product')),
        'product_cover' => $prodCover,
        'cover_url' => $prodCover,
    ];
}

$avatarUrl = 'avatar.php?u=' . $publisherId . '&s=160&name=' . rawurlencode($storeName);
$coverUrl = '';
if (function_exists('profile_cover_slides_payload')) {
    $coverPayload = profile_cover_slides_payload($dbh, $publisherId);
    $coverUrl = trim((string)($coverPayload['cover_url'] ?? ''));
}

$categoryRows = [];
foreach ($categories as $catName => $catCount) {
    $categoryRows[] = [
        'name' => (string)$catName,
        'count' => (int)$catCount,
    ];
}

$isFollowing = false;
if ($canFollow && $meId > 0 && $publisherId > 0) {
    try {
        $stF = $dbh->prepare('SELECT 1 FROM follows WHERE follower_id = :me AND following_id = :peer LIMIT 1');
        $stF->execute([':me' => $meId, ':peer' => $publisherId]);
        $isFollowing = (bool)$stF->fetchColumn();
    } catch (Throwable $e) {
        try {
            $stF = $dbh->prepare('SELECT 1 FROM publisher_follows WHERE follower_user_id = :me AND publisher_user_id = :peer LIMIT 1');
            $stF->execute([':me' => $meId, ':peer' => $publisherId]);
            $isFollowing = (bool)$stF->fetchColumn();
        } catch (Throwable $e2) {
            $isFollowing = false;
        }
    }
}

echo json_encode([
    'ok' => true,
    'from' => $from,
    'seller' => [
        'publisher_user_id' => $publisherId,
        'org_id' => $orgId,
        'friend_code' => strtoupper(trim((string)($seller['friend_code'] ?? ''))),
        'name' => $storeName,
        'store_name' => $storeName,
        'username' => trim((string)($seller['username'] ?? '')),
        'seller_type' => 'Business seller',
        'tagline' => $tagline,
        'short_desc' => $tagline !== '' ? $tagline : 'For my business',
        'about' => $about,
        'location' => $location,
        'address' => $location,
        'member_since' => $memberSince,
        'member_since_label' => $memberSince !== '' ? ('Member since ' . $memberSince) : '',
        'phone' => trim((string)($info['phone'] ?? '')),
        'email' => trim((string)($info['email'] ?? $seller['email'] ?? '')),
        'avatar_url' => $avatarUrl,
        'cover_url' => $coverUrl,
        'verified' => true,
        'active_now' => true,
        'badge_official' => 'Official manufacturer',
        'badge_live' => 'Active now',
        'brand_slug' => $brandSlug,
        'brand_name' => $brandName,
        'items_sold' => $itemsSold,
        'items_sold_label' => $fmtSold($itemsSold),
        'positive_feedback_pct' => $positivePct,
        'positive_feedback_label' => $positivePct !== null ? ((string)$positivePct . '%') : '—',
        'response_time' => '1–2 days',
        'response_time_label' => '1–2 days',
        'shipping_avg' => '2–5 days',
        'shipping_avg_label' => '2–5 days',
        'active_listings' => $activeListings,
        'can_follow' => $canFollow,
        'is_following' => $isFollowing,
    ],
    'featured' => $featured,
    'items' => $allItems,
    'categories' => $categoryRows,
    'reviews' => $reviewRows,
    'recent_reviews' => array_slice($reviewRows, 0, 6),
    'policies_text' => 'Contact this seller about returns, pickup, delivery windows, and order changes. Use Message for product or order questions only.',
    'qa_empty' => 'No public Q&A yet. Message the seller about a product or order.',
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
