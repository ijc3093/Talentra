<?php
declare(strict_types=1);

/**
 * Buyer ↔ seller (publisher) product/order messaging helpers.
 * Social DMs still require friendship; commerce pairs may DM without friending PUB- accounts.
 */

require_once __DIR__ . '/publisher_accounts_load.php';

function commerce_messaging_user_id_by_friend_code(PDO $dbh, string $friendCode): int
{
    $friendCode = strtoupper(trim($friendCode));
    if ($friendCode === '') {
        return 0;
    }
    try {
        $st = $dbh->prepare('SELECT id FROM users WHERE UPPER(friend_code) = :c LIMIT 1');
        $st->execute([':c' => $friendCode]);
        return (int)($st->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        return 0;
    }
}

function commerce_messaging_user_id_by_username(PDO $dbh, string $username): int
{
    $username = trim($username);
    if ($username === '') {
        return 0;
    }
    try {
        $st = $dbh->prepare('SELECT id FROM users WHERE username = :u AND status = 1 LIMIT 1');
        $st->execute([':u' => $username]);
        return (int)($st->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        return 0;
    }
}

function commerce_messaging_publisher_has_shop(PDO $dbh, int $publisherUserId): bool
{
    if ($publisherUserId <= 0) {
        return false;
    }
    require_once __DIR__ . '/org_shop.php';
    if (!org_is_commerce_seller_publisher($dbh, $publisherUserId)) {
        return false;
    }
    try {
        $st = $dbh->prepare('
            SELECT 1
            FROM organizations o
            INNER JOIN org_products p ON p.org_id = o.id AND p.is_deleted = 0 AND p.status = \'active\'
            WHERE o.publisher_user_id = :uid
              AND o.status = 1
              AND o.commerce_brand_id IS NOT NULL
              AND o.commerce_brand_id > 0
              AND LOWER(TRIM(COALESCE(o.publisher_category, \'\'))) IN (\'\', \'commerce\')
            LIMIT 1
        ');
        $st->execute([':uid' => $publisherUserId]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function commerce_messaging_have_order_pair(PDO $dbh, int $buyerUserId, int $publisherUserId): bool
{
    if ($buyerUserId <= 0 || $publisherUserId <= 0) {
        return false;
    }
    try {
        $st = $dbh->prepare('
            SELECT 1
            FROM org_orders o
            INNER JOIN organizations org ON org.id = o.org_id AND org.status = 1
            WHERE o.buyer_user_id = :buyer
              AND org.publisher_user_id = :pub
            LIMIT 1
        ');
        $st->execute([':buyer' => $buyerUserId, ':pub' => $publisherUserId]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function commerce_messaging_have_history(PDO $dbh, string $meCode, string $peerCode): bool
{
    $meCode = strtoupper(trim($meCode));
    $peerCode = strtoupper(trim($peerCode));
    if ($meCode === '' || $peerCode === '') {
        return false;
    }
    try {
        $st = $dbh->prepare("
            SELECT 1 FROM feedback
            WHERE channel = 'user_user'
              AND (
                (UPPER(sender) = :me AND UPPER(receiver) = :peer)
                OR (UPPER(sender) = :peer2 AND UPPER(receiver) = :me2)
              )
            LIMIT 1
        ");
        $st->execute([
            ':me' => $meCode,
            ':peer' => $peerCode,
            ':peer2' => $peerCode,
            ':me2' => $meCode,
        ]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Allow DM without friendship when the pair has a shop/order relationship.
 */
function commerce_can_dm_pair(PDO $dbh, int $meId, int $peerId): bool
{
    if ($meId <= 0 || $peerId <= 0 || $meId === $peerId) {
        return false;
    }

    if (function_exists('fs_are_friends') && fs_are_friends($dbh, $meId, $peerId)) {
        return true;
    }

    $meIsPub = publisher_is_publisher_user($dbh, $meId);
    $peerIsPub = publisher_is_publisher_user($dbh, $peerId);

    // Existing commerce thread continues even if policies change.
    try {
        $st = $dbh->prepare('SELECT friend_code FROM users WHERE id IN (:a, :b)');
        // PDO may not support IN with named duplicates well — fetch separately.
    } catch (Throwable $e) {
        // fall through
    }
    $meCode = '';
    $peerCode = '';
    try {
        $st = $dbh->prepare('SELECT id, friend_code FROM users WHERE id IN (' . (int)$meId . ',' . (int)$peerId . ')');
        $st->execute();
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            if ((int)$row['id'] === $meId) {
                $meCode = (string)($row['friend_code'] ?? '');
            }
            if ((int)$row['id'] === $peerId) {
                $peerCode = (string)($row['friend_code'] ?? '');
            }
        }
    } catch (Throwable $e) {
        // ignore
    }
    if ($meCode !== '' && $peerCode !== '' && commerce_messaging_have_history($dbh, $meCode, $peerCode)) {
        return true;
    }

    // Buyer ↔ publisher with a shared order (either direction).
    if ($meIsPub && !$peerIsPub && commerce_messaging_have_order_pair($dbh, $peerId, $meId)) {
        return true;
    }
    if ($peerIsPub && !$meIsPub && commerce_messaging_have_order_pair($dbh, $meId, $peerId)) {
        return true;
    }

    // Pre-purchase product question: buyer may message an active shop publisher.
    if ($peerIsPub && !$meIsPub && commerce_messaging_publisher_has_shop($dbh, $peerId)) {
        return true;
    }

    return false;
}

function commerce_message_seller_url(int $publisherUserId, int $productId = 0, string $orderCode = ''): string
{
    if ($publisherUserId <= 0) {
        return 'Your_Shopping_preferences.php#seller-messages';
    }
    $q = ['seller_msg' => $publisherUserId];
    if ($productId > 0) {
        $q['about_product'] = $productId;
    }
    if ($orderCode !== '') {
        $q['about_order'] = $orderCode;
    }
    return 'Your_Shopping_preferences.php?' . http_build_query($q) . '#seller-messages';
}

/**
 * Public @handle for buyer → seller chat (account username, not contact email).
 */
function commerce_seller_chat_username(PDO $dbh, int $publisherUserId, string $hintUsername = ''): string
{
    $hintUsername = trim($hintUsername);
    $username = '';
    $name = '';
    $friendCode = '';
    if ($publisherUserId > 0) {
        try {
            $st = $dbh->prepare('SELECT username, name, friend_code FROM users WHERE id = :id AND status = 1 LIMIT 1');
            $st->execute([':id' => $publisherUserId]);
            $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
            $username = trim((string)($row['username'] ?? ''));
            $name = trim((string)($row['name'] ?? ''));
            $friendCode = trim((string)($row['friend_code'] ?? ''));
        } catch (Throwable $e) {
            // ignore
        }
    }
    if ($hintUsername !== '' && !commerce_value_looks_like_email($hintUsername)) {
        return $hintUsername;
    }
    if ($username !== '' && !commerce_value_looks_like_email($username)) {
        return $username;
    }
    if ($name !== '') {
        $slug = strtolower(trim(preg_replace('/[^a-z0-9]+/', '', strtolower($name)) ?? ''));
        if ($slug !== '') {
            return $slug;
        }
        return $name;
    }
    if ($friendCode !== '') {
        return $friendCode;
    }
    return $username;
}

function commerce_value_looks_like_email(string $value): bool
{
    $value = trim($value);
    return $value !== '' && str_contains($value, '@') && filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Sellers a buyer can message from Shopping Preferences (orders + shop publishers with history).
 *
 * @return list<array{publisher_user_id:int,org_id:int,seller_name:string,friend_code:string,last_message:string,last_at:string,unread:int}>
 */
function commerce_list_buyer_seller_contacts(PDO $dbh, int $buyerUserId): array
{
    if ($buyerUserId <= 0) {
        return [];
    }
    $meCode = '';
    $meEmail = '';
    try {
        $stMe = $dbh->prepare('SELECT friend_code, email FROM users WHERE id = :id LIMIT 1');
        $stMe->execute([':id' => $buyerUserId]);
        $me = $stMe->fetch(PDO::FETCH_ASSOC) ?: [];
        $meCode = strtoupper(trim((string)($me['friend_code'] ?? '')));
        $meEmail = trim((string)($me['email'] ?? ''));
    } catch (Throwable $e) {
        return [];
    }

    $map = [];
    try {
        $st = $dbh->prepare("
            SELECT
                org.publisher_user_id,
                org.id AS org_id,
                COALESCE(NULLIF(TRIM(org.name), ''), NULLIF(TRIM(u.name), ''), NULLIF(TRIM(u.username), ''), u.friend_code) AS seller_name,
                u.friend_code,
                MAX(o.created_at) AS last_order_at
            FROM org_orders o
            INNER JOIN organizations org ON org.id = o.org_id AND org.status = 1
            INNER JOIN users u ON u.id = org.publisher_user_id AND u.status = 1
            WHERE o.buyer_user_id = :buyer
              AND org.publisher_user_id IS NOT NULL
              AND org.publisher_user_id > 0
            GROUP BY org.publisher_user_id, org.id, seller_name, u.friend_code
            ORDER BY last_order_at DESC
            LIMIT 100
        ");
        $st->execute([':buyer' => $buyerUserId]);
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $pubId = (int)($row['publisher_user_id'] ?? 0);
            $fc = strtoupper(trim((string)($row['friend_code'] ?? '')));
            if ($pubId <= 0 || $fc === '') {
                continue;
            }
            $map[$pubId] = [
                'publisher_user_id' => $pubId,
                'org_id' => (int)($row['org_id'] ?? 0),
                'seller_name' => trim((string)($row['seller_name'] ?? 'Seller')),
                'friend_code' => $fc,
                'last_message' => '',
                'last_at' => (string)($row['last_order_at'] ?? ''),
                'unread' => 0,
            ];
        }
    } catch (Throwable $e) {
        // ignore
    }

    // Include existing commerce DM threads with publishers even without an order yet.
    if ($meCode !== '') {
        try {
            $stT = $dbh->prepare("
                SELECT
                    u.id AS publisher_user_id,
                    u.friend_code,
                    COALESCE(NULLIF(TRIM(u.name), ''), NULLIF(TRIM(u.username), ''), u.friend_code) AS seller_name,
                    MAX(f.created_at) AS last_at,
                    SUBSTRING_INDEX(GROUP_CONCAT(f.feedbackdata ORDER BY f.created_at DESC SEPARATOR '\\n'), '\\n', 1) AS last_message,
                    SUM(CASE WHEN f.is_read = 0 AND (f.receiver = :meCode OR f.receiver = :meEmail) THEN 1 ELSE 0 END) AS unread
                FROM feedback f
                INNER JOIN users u ON (
                    (UPPER(u.friend_code) = UPPER(f.sender) OR u.email = f.sender)
                    OR (UPPER(u.friend_code) = UPPER(f.receiver) OR u.email = f.receiver)
                )
                WHERE f.channel = 'user_user'
                  AND (
                    (UPPER(f.sender) = :meCode2 OR f.sender = :meEmail2)
                    OR (UPPER(f.receiver) = :meCode3 OR f.receiver = :meEmail3)
                  )
                  AND u.id <> :buyer
                GROUP BY u.id, u.friend_code, seller_name
                ORDER BY last_at DESC
                LIMIT 100
            ");
            $stT->execute([
                ':meCode' => $meCode,
                ':meEmail' => $meEmail,
                ':meCode2' => $meCode,
                ':meEmail2' => $meEmail,
                ':meCode3' => $meCode,
                ':meEmail3' => $meEmail,
                ':buyer' => $buyerUserId,
            ]);
            while ($row = $stT->fetch(PDO::FETCH_ASSOC)) {
                $pubId = (int)($row['publisher_user_id'] ?? 0);
                $fc = strtoupper(trim((string)($row['friend_code'] ?? '')));
                if ($pubId <= 0 || $fc === '') {
                    continue;
                }
                if (!commerce_can_dm_pair($dbh, $buyerUserId, $pubId)) {
                    continue;
                }
                if (!isset($map[$pubId])) {
                    $map[$pubId] = [
                        'publisher_user_id' => $pubId,
                        'org_id' => 0,
                        'seller_name' => trim((string)($row['seller_name'] ?? 'Seller')),
                        'friend_code' => $fc,
                        'last_message' => '',
                        'last_at' => '',
                        'unread' => 0,
                    ];
                }
                $map[$pubId]['last_message'] = trim((string)($row['last_message'] ?? ''));
                $map[$pubId]['last_at'] = (string)($row['last_at'] ?? $map[$pubId]['last_at']);
                $map[$pubId]['unread'] = (int)($row['unread'] ?? 0);
            }
        } catch (Throwable $e) {
            // ignore
        }
    }

    $list = array_values($map);
    usort($list, static function (array $a, array $b): int {
        return strcmp((string)($b['last_at'] ?? ''), (string)($a['last_at'] ?? ''));
    });
    return $list;
}

function commerce_buyer_seller_unread_count(PDO $dbh, int $buyerUserId): int
{
    $total = 0;
    foreach (commerce_list_buyer_seller_contacts($dbh, $buyerUserId) as $c) {
        $total += max(0, (int)($c['unread'] ?? 0));
    }
    return $total;
}

function commerce_message_buyer_org_url(int $orderId): string
{
    if ($orderId <= 0) {
        return 'sales_management.php#message';
    }
    return 'message_buyer.php?order_id=' . $orderId;
}

/**
 * Deep-link into seller Sales Management customer chat.
 */
function commerce_message_buyer_sales_url(int $buyerUserId, int $productId = 0, string $orderCode = ''): string
{
    if ($buyerUserId <= 0) {
        return 'sales_management.php#message';
    }
    $q = ['buyer_msg' => $buyerUserId];
    if ($productId > 0) {
        $q['about_product'] = $productId;
    }
    if ($orderCode !== '') {
        $q['about_order'] = $orderCode;
    }
    return 'sales_management.php?' . http_build_query($q) . '#message';
}

/**
 * Buyers a seller publisher can message (orders + existing DM threads).
 *
 * @return list<array{buyer_user_id:int,buyer_name:string,friend_code:string,last_message:string,last_at:string,unread:int,order_code:string}>
 */
function commerce_list_seller_buyer_contacts(PDO $dbh, int $publisherUserId): array
{
    if ($publisherUserId <= 0) {
        return [];
    }
    $meCode = '';
    $meEmail = '';
    try {
        $stMe = $dbh->prepare('SELECT friend_code, email FROM users WHERE id = :id LIMIT 1');
        $stMe->execute([':id' => $publisherUserId]);
        $me = $stMe->fetch(PDO::FETCH_ASSOC) ?: [];
        $meCode = strtoupper(trim((string)($me['friend_code'] ?? '')));
        $meEmail = trim((string)($me['email'] ?? ''));
    } catch (Throwable $e) {
        return [];
    }

    $map = [];
    try {
        $st = $dbh->prepare("
            SELECT
                o.buyer_user_id,
                COALESCE(NULLIF(TRIM(u.name), ''), NULLIF(TRIM(u.username), ''), u.friend_code) AS buyer_name,
                u.friend_code,
                MAX(o.created_at) AS last_order_at,
                SUBSTRING_INDEX(GROUP_CONCAT(o.order_code ORDER BY o.created_at DESC SEPARATOR ','), ',', 1) AS order_code
            FROM org_orders o
            INNER JOIN organizations org ON org.id = o.org_id AND org.status = 1
            INNER JOIN users u ON u.id = o.buyer_user_id AND u.status = 1
            WHERE org.publisher_user_id = :pub
              AND o.buyer_user_id IS NOT NULL
              AND o.buyer_user_id > 0
            GROUP BY o.buyer_user_id, buyer_name, u.friend_code
            ORDER BY last_order_at DESC
            LIMIT 100
        ");
        $st->execute([':pub' => $publisherUserId]);
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $buyerId = (int)($row['buyer_user_id'] ?? 0);
            $fc = strtoupper(trim((string)($row['friend_code'] ?? '')));
            if ($buyerId <= 0 || $fc === '') {
                continue;
            }
            $map[$buyerId] = [
                'buyer_user_id' => $buyerId,
                'buyer_name' => trim((string)($row['buyer_name'] ?? 'Customer')),
                'friend_code' => $fc,
                'last_message' => '',
                'last_at' => (string)($row['last_order_at'] ?? ''),
                'unread' => 0,
                'order_code' => trim((string)($row['order_code'] ?? '')),
            ];
        }
    } catch (Throwable $e) {
        // ignore
    }

    if ($meCode !== '') {
        try {
            $stT = $dbh->prepare("
                SELECT
                    u.id AS buyer_user_id,
                    u.friend_code,
                    COALESCE(NULLIF(TRIM(u.name), ''), NULLIF(TRIM(u.username), ''), u.friend_code) AS buyer_name,
                    MAX(f.created_at) AS last_at,
                    SUBSTRING_INDEX(GROUP_CONCAT(f.feedbackdata ORDER BY f.created_at DESC SEPARATOR '\\n'), '\\n', 1) AS last_message,
                    SUM(CASE WHEN f.is_read = 0 AND (f.receiver = :meCode OR f.receiver = :meEmail) THEN 1 ELSE 0 END) AS unread
                FROM feedback f
                INNER JOIN users u ON (
                    (UPPER(u.friend_code) = UPPER(f.sender) OR u.email = f.sender)
                    OR (UPPER(u.friend_code) = UPPER(f.receiver) OR u.email = f.receiver)
                )
                WHERE f.channel = 'user_user'
                  AND (
                    (UPPER(f.sender) = :meCode2 OR f.sender = :meEmail2)
                    OR (UPPER(f.receiver) = :meCode3 OR f.receiver = :meEmail3)
                  )
                  AND u.id <> :pub
                GROUP BY u.id, u.friend_code, buyer_name
                ORDER BY last_at DESC
                LIMIT 100
            ");
            $stT->execute([
                ':meCode' => $meCode,
                ':meEmail' => $meEmail,
                ':meCode2' => $meCode,
                ':meEmail2' => $meEmail,
                ':meCode3' => $meCode,
                ':meEmail3' => $meEmail,
                ':pub' => $publisherUserId,
            ]);
            while ($row = $stT->fetch(PDO::FETCH_ASSOC)) {
                $buyerId = (int)($row['buyer_user_id'] ?? 0);
                $fc = strtoupper(trim((string)($row['friend_code'] ?? '')));
                if ($buyerId <= 0 || $fc === '') {
                    continue;
                }
                if (!commerce_can_dm_pair($dbh, $publisherUserId, $buyerId)) {
                    continue;
                }
                if (!isset($map[$buyerId])) {
                    $map[$buyerId] = [
                        'buyer_user_id' => $buyerId,
                        'buyer_name' => trim((string)($row['buyer_name'] ?? 'Customer')),
                        'friend_code' => $fc,
                        'last_message' => '',
                        'last_at' => '',
                        'unread' => 0,
                        'order_code' => '',
                    ];
                }
                $map[$buyerId]['last_message'] = trim((string)($row['last_message'] ?? ''));
                $map[$buyerId]['last_at'] = (string)($row['last_at'] ?? $map[$buyerId]['last_at']);
                $map[$buyerId]['unread'] = (int)($row['unread'] ?? 0);
            }
        } catch (Throwable $e) {
            // ignore
        }
    }

    $list = array_values($map);
    usort($list, static function (array $a, array $b): int {
        return strcmp((string)($b['last_at'] ?? ''), (string)($a['last_at'] ?? ''));
    });
    return $list;
}

function commerce_seller_buyer_unread_count(PDO $dbh, int $publisherUserId): int
{
    $total = 0;
    foreach (commerce_list_seller_buyer_contacts($dbh, $publisherUserId) as $c) {
        $total += max(0, (int)($c['unread'] ?? 0));
    }
    return $total;
}

/** Build an optional first-message draft for product/order context. */
function commerce_messaging_compose_draft(PDO $dbh, int $aboutProductId = 0, string $aboutOrder = ''): string
{
    $parts = [];
    $aboutOrder = trim($aboutOrder);
    if ($aboutOrder !== '') {
        $parts[] = 'About order ' . $aboutOrder;
    }
    if ($aboutProductId > 0) {
        try {
            require_once __DIR__ . '/org_shop.php';
            $p = org_shop_get_product($dbh, $aboutProductId);
            $title = trim((string)($p['title'] ?? ''));
            if ($title !== '') {
                $parts[] = 'Regarding product: ' . $title;
            } else {
                $parts[] = 'Regarding product #' . $aboutProductId;
            }
        } catch (Throwable $e) {
            $parts[] = 'Regarding product #' . $aboutProductId;
        }
    }
    if (!$parts) {
        return '';
    }
    return implode(' — ', $parts) . "\n\n";
}
