<?php
declare(strict_types=1);

/**
 * Buyer shop live-sync fingerprint (web shop pages + iOS app poll this every few seconds).
 * Each part is a short hash that changes whenever that area changes on either client:
 * orders, returns, reviews, cart, wishlist, addresses, inbox, messages, profile.
 * GET since=<version> → { changed:false } when nothing moved.
 */
require_once __DIR__ . '/../includes/session_user.php';
require_once __DIR__ . '/../controller.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$meId = (int)($_SESSION['user_id'] ?? $_SESSION['id'] ?? $_SESSION['userid'] ?? 0);
if ($meId <= 0) {
    // Local MAMP / Simulator: same loopback fallback as ajax/shop_shopping_preferences.php.
    $remote = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $isLoopback = in_array($remote, ['127.0.0.1', '::1'], true) || str_starts_with($remote, '127.');
    if ($isLoopback) {
        $meId = (int)($_SERVER['HTTP_X_SHOP_USER_ID'] ?? $_GET['user_id'] ?? 0);
    }
}
// Polling must never hold the PHP session lock (other tabs / app requests would queue behind it).
$sessionCache = is_array($_SESSION['shop_sync_cache'] ?? null) ? $_SESSION['shop_sync_cache'] : [];
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}
if ($meId <= 0) {
    echo json_encode(['ok' => false, 'message' => 'Please sign in.']);
    exit;
}

$dbh = (new Controller())->pdo();

$row = static function (string $sql, array $params) use ($dbh): array {
    try {
        $st = $dbh->prepare($sql);
        $st->execute($params);
        return $st->fetch(PDO::FETCH_NUM) ?: [];
    } catch (Throwable $e) {
        return [];
    }
};
$sig = static function (array $values): string {
    return substr(sha1(implode('|', array_map('strval', $values))), 0, 12);
};
$uid = [':u' => $meId];

$parts = [];
$parts['orders'] = $sig($row(
    "SELECT COUNT(*), MAX(id), MAX(updated_at),
            SUM(CRC32(CONCAT_WS('|', id, status, amount_paid_cents, total_cents, IFNULL(tracking_number, ''),
                IFNULL(carrier, ''), IFNULL(shipped_at, ''), IFNULL(delivered_at, ''), IFNULL(buyer_hidden_at, ''))))
     FROM org_orders WHERE buyer_user_id = :u",
    $uid
));
$parts['returns'] = $sig($row(
    "SELECT COUNT(*), MAX(r.id), SUM(CRC32(CONCAT_WS('|', r.id, r.status, IFNULL(r.updated_at, ''))))
     FROM org_order_returns r INNER JOIN org_orders o ON o.id = r.order_id
     WHERE o.buyer_user_id = :u",
    $uid
));
$parts['reviews'] = $sig($row(
    'SELECT COUNT(*), MAX(id) FROM org_product_reviews WHERE buyer_user_id = :u',
    $uid
));
$cartRow = $row(
    "SELECT COUNT(*), COALESCE(SUM(quantity), 0), SUM(CRC32(CONCAT_WS(':', product_id, quantity, IFNULL(updated_at, created_at))))
     FROM org_cart_items WHERE user_id = :u",
    $uid
);
$parts['cart'] = $sig($cartRow);
$parts['wishlist'] = $sig($row(
    'SELECT COUNT(*), MAX(id), SUM(product_id) FROM org_wishlist_items WHERE user_id = :u',
    $uid
));
try {
    $st = $dbh->prepare('SELECT * FROM buyer_shipping_addresses WHERE user_id = :u ORDER BY id');
    $st->execute($uid);
    $parts['addresses'] = substr(sha1(json_encode($st->fetchAll(PDO::FETCH_ASSOC) ?: [])), 0, 12);
} catch (Throwable $e) {
    $parts['addresses'] = '0';
}
$profile = [];
try {
    $st = $dbh->prepare('SELECT name, username, email, mobile FROM users WHERE id = :u LIMIT 1');
    $st->execute($uid);
    $profile = $st->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $profile = [];
}
$parts['profile'] = substr(sha1(json_encode($profile)), 0, 12);
$username = trim((string)($profile['username'] ?? ''));
require_once __DIR__ . '/../includes/app_notification_api.php';
$parts['inbox'] = $username !== ''
    ? $sig($row(
        'SELECT COUNT(*), MAX(id), COALESCE(SUM(is_read), 0) FROM notification WHERE notireceiver = ?'
            . app_notification_shop_only_sql(),
        array_merge([$username], app_notification_shop_like_patterns())
    ))
    : '0';

// Badge + DM unread are heavier (alias lookups); recompute when shop data moved or every 15s.
$cheapKey = $parts['orders'] . $parts['returns'] . $parts['inbox'];
$now = time();
$hub = (int)($sessionCache['hub'] ?? -1);
$msgSig = (string)($sessionCache['messages'] ?? '');
if ($hub < 0 || ($sessionCache['key'] ?? '') !== $cheapKey || ($now - (int)($sessionCache['at'] ?? 0)) >= 15) {
    require_once __DIR__ . '/../includes/commerce_messaging.php';
    require_once __DIR__ . '/../includes/admin_support_chat.php';
    $sellerUnread = 0;
    $supportUnread = 0;
    try {
        $sellerUnread = function_exists('commerce_buyer_seller_unread_count')
            ? (int)commerce_buyer_seller_unread_count($dbh, $meId)
            : 0;
    } catch (Throwable $e) {
    }
    try {
        $email = function_exists('admin_support_user_email') ? admin_support_user_email($dbh, $meId) : '';
        $supportUnread = ($email !== '' && function_exists('admin_support_unread_count'))
            ? (int)admin_support_unread_count($dbh, $email)
            : 0;
    } catch (Throwable $e) {
    }
    try {
        $hub = function_exists('commerce_buyer_shop_hub_badge_count')
            ? (int)commerce_buyer_shop_hub_badge_count($dbh, $meId)
            : 0;
    } catch (Throwable $e) {
        $hub = 0;
    }
    $msgSig = $sig([$sellerUnread, $supportUnread]);
    if (session_status() !== PHP_SESSION_ACTIVE && !headers_sent()) {
        @session_start();
        $_SESSION['shop_sync_cache'] = ['hub' => $hub, 'messages' => $msgSig, 'key' => $cheapKey, 'at' => $now];
        session_write_close();
    }
}
$parts['messages'] = $msgSig !== '' ? $msgSig : '0';

ksort($parts);
$version = $sig(array_values($parts));
$since = trim((string)($_GET['since'] ?? ''));

echo json_encode([
    'ok' => true,
    'changed' => $since === '' || $since !== $version,
    'version' => $version,
    'parts' => $parts,
    'badges' => [
        'hub' => max(0, $hub),
        'cart' => max(0, (int)($cartRow[1] ?? 0)),
        'cart_lines' => max(0, (int)($cartRow[0] ?? 0)),
    ],
    'poll_ms' => 3000,
    'server_time' => $now,
], JSON_UNESCAPED_UNICODE);
