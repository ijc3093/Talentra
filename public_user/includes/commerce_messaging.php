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
        return 'messages.php';
    }
    $q = ['id' => $publisherUserId, 'commerce' => '1'];
    if ($productId > 0) {
        $q['about_product'] = $productId;
    }
    if ($orderCode !== '') {
        $q['about_order'] = $orderCode;
    }
    return 'messages.php?' . http_build_query($q);
}

function commerce_message_buyer_org_url(int $orderId): string
{
    if ($orderId <= 0) {
        return 'orders.php';
    }
    return 'message_buyer.php?order_id=' . $orderId;
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
