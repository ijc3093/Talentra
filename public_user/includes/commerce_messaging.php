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
 * All sender/receiver identity strings a user may appear as in feedback.
 *
 * @return list<string>
 */
function commerce_messaging_user_aliases(PDO $dbh, int $userId): array
{
    if ($userId <= 0) {
        return [];
    }
    $out = [];
    try {
        $st = $dbh->prepare('SELECT friend_code, email, username FROM users WHERE id = :id LIMIT 1');
        $st->execute([':id' => $userId]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $fc = strtoupper(trim((string)($row['friend_code'] ?? '')));
        $email = trim((string)($row['email'] ?? ''));
        $username = trim((string)($row['username'] ?? ''));
        if ($fc !== '') {
            $out[] = $fc;
            $out[] = strtolower($fc);
        }
        if ($email !== '') {
            $out[] = $email;
            $out[] = strtolower($email);
        }
        if ($username !== '') {
            $out[] = $username;
            $out[] = strtolower($username);
        }
    } catch (Throwable $e) {
        return [];
    }
    return array_values(array_unique(array_filter($out, static fn($v) => $v !== '')));
}

/** Plain text for a feedback chat body (strips reply wrappers). */
function commerce_messaging_plain_text(string $raw): string
{
    $raw = (string)$raw;
    if (preg_match('/^\[\[reply:([A-Za-z0-9+\/=]+)\]\](.*)$/s', $raw, $m)) {
        return ltrim((string)($m[2] ?? ''));
    }
    return $raw;
}

/**
 * Load buyer↔seller user_user thread by user ids (matches friend_code / email / username).
 * Uses the same identity rules as public_user/ajax/user_chat_poll.php so both sides see one history.
 *
 * @return list<array{id:int,is_me:bool,text:string,created_at:string,time_label:string,sender_name:string,peer_name:string,is_read:int}>
 */
function commerce_messaging_thread_items(
    PDO $dbh,
    int $meUserId,
    int $peerUserId,
    int $afterId = 0,
    int $limit = 300,
    bool $markRead = false
): array {
    if ($meUserId <= 0 || $peerUserId <= 0 || $meUserId === $peerUserId) {
        return [];
    }

    $loadIdentity = static function (PDO $dbh, int $userId): array {
        try {
            $st = $dbh->prepare('
                SELECT friend_code, email, username,
                       COALESCE(NULLIF(TRIM(name), \'\'), NULLIF(TRIM(username), \'\'), friend_code) AS display
                FROM users WHERE id = :id LIMIT 1
            ');
            $st->execute([':id' => $userId]);
            $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return ['code' => '', 'email' => '', 'username' => '', 'display' => 'Customer'];
        }
        return [
            'code' => strtoupper(trim((string)($row['friend_code'] ?? ''))),
            'email' => trim((string)($row['email'] ?? '')),
            'username' => trim((string)($row['username'] ?? '')),
            'display' => trim((string)($row['display'] ?? 'Customer')) ?: 'Customer',
        ];
    };

    $me = $loadIdentity($dbh, $meUserId);
    $peer = $loadIdentity($dbh, $peerUserId);
    $peerName = $peer['display'];

    if ($me['code'] === '' && $me['email'] === '' && $me['username'] === '') {
        return [];
    }
    if ($peer['code'] === '' && $peer['email'] === '' && $peer['username'] === '') {
        return [];
    }

    $limit = max(1, min(2000, $limit));
    $afterId = max(0, $afterId);

    // Build compact OR lists (few placeholders — Hostinger-safe).
    $partySql = static function (string $col, string $prefix, array $id, array &$params): string {
        $parts = [];
        if ($id['code'] !== '') {
            $k = ':' . $prefix . 'c';
            $parts[] = $col . ' = ' . $k;
            $parts[] = 'UPPER(TRIM(' . $col . ')) = ' . $k . 'u';
            $params[$k] = $id['code'];
            $params[$k . 'u'] = $id['code'];
        }
        if ($id['email'] !== '') {
            $k = ':' . $prefix . 'e';
            $parts[] = $col . ' = ' . $k;
            $parts[] = 'LOWER(TRIM(' . $col . ')) = ' . $k . 'l';
            $params[$k] = $id['email'];
            $params[$k . 'l'] = strtolower($id['email']);
        }
        if ($id['username'] !== '') {
            $k = ':' . $prefix . 'u';
            $parts[] = $col . ' = ' . $k;
            $parts[] = 'LOWER(TRIM(' . $col . ')) = ' . $k . 'l';
            $params[$k] = $id['username'];
            $params[$k . 'l'] = strtolower($id['username']);
        }
        return $parts ? '(' . implode(' OR ', $parts) . ')' : '0';
    };

    $params = [':after' => $afterId];
    $meSend = $partySql('f.sender', 'ms', $me, $params);
    $meRecv = $partySql('f.receiver', 'mr', $me, $params);
    $peerSend = $partySql('f.sender', 'ps', $peer, $params);
    $peerRecv = $partySql('f.receiver', 'pr', $peer, $params);

    $rows = [];
    try {
        $st = $dbh->prepare("
            SELECT f.id, f.sender, f.receiver, f.feedbackdata, f.created_at, f.is_read
            FROM feedback f
            WHERE f.channel = 'user_user'
              AND f.id > :after
              AND (
                    ({$meSend} AND {$peerRecv})
                 OR ({$peerSend} AND {$meRecv})
              )
            ORDER BY f.id ASC
            LIMIT {$limit}
        ");
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $rows = [];
    }

    // Fallback identical to buyer Messages poll (friend_code + email only).
    if (!$rows && ($me['code'] !== '' || $me['email'] !== '') && ($peer['code'] !== '' || $peer['email'] !== '')) {
        try {
            $st2 = $dbh->prepare("
                SELECT f.id, f.sender, f.receiver, f.feedbackdata, f.created_at, f.is_read
                FROM feedback f
                WHERE f.channel = 'user_user'
                  AND f.id > :after
                  AND (
                        (
                          (f.sender = :meCode OR f.sender = :meEmail)
                          AND
                          (f.receiver = :peerCode OR f.receiver = :peerEmail)
                        )
                     OR (
                          (f.sender = :peerCode2 OR f.sender = :peerEmail2)
                          AND
                          (f.receiver = :meCode2 OR f.receiver = :meEmail2)
                        )
                  )
                ORDER BY f.id ASC
                LIMIT {$limit}
            ");
            $st2->execute([
                ':after' => $afterId,
                ':meCode' => $me['code'],
                ':meEmail' => $me['email'],
                ':peerCode' => $peer['code'],
                ':peerEmail' => $peer['email'],
                ':peerCode2' => $peer['code'],
                ':peerEmail2' => $peer['email'],
                ':meCode2' => $me['code'],
                ':meEmail2' => $me['email'],
            ]);
            $rows = $st2->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            $rows = [];
        }
    }

    $isMeSender = static function (string $sender) use ($me): bool {
        $sender = trim($sender);
        if ($sender === '') {
            return false;
        }
        if ($me['code'] !== '' && strcasecmp($sender, $me['code']) === 0) {
            return true;
        }
        if ($me['email'] !== '' && strcasecmp($sender, $me['email']) === 0) {
            return true;
        }
        if ($me['username'] !== '' && strcasecmp($sender, $me['username']) === 0) {
            return true;
        }
        return false;
    };

    $items = [];
    foreach ($rows as $r) {
        $sender = (string)($r['sender'] ?? '');
        $isMe = $isMeSender($sender);
        $created = (string)($r['created_at'] ?? '');
        $ts = $created !== '' ? strtotime($created) : false;
        $items[] = [
            'id' => (int)($r['id'] ?? 0),
            'is_me' => $isMe,
            'text' => commerce_messaging_plain_text((string)($r['feedbackdata'] ?? '')),
            'created_at' => $created,
            'time_label' => $ts ? date('M d, Y h:i A', $ts) : '',
            'sender_name' => $isMe ? 'You' : $peerName,
            'peer_name' => $peerName,
            'is_read' => (int)($r['is_read'] ?? 0),
        ];
    }

    if ($markRead && $items) {
        try {
            $stMark = $dbh->prepare("
                UPDATE feedback
                SET is_read = 1
                WHERE channel = 'user_user'
                  AND is_read = 0
                  AND (receiver = :meCode OR receiver = :meEmail OR LOWER(TRIM(receiver)) = :meEmailL OR LOWER(TRIM(receiver)) = :meUserL)
                  AND (sender = :peerCode OR sender = :peerEmail OR LOWER(TRIM(sender)) = :peerEmailL OR LOWER(TRIM(sender)) = :peerUserL)
            ");
            $stMark->execute([
                ':meCode' => $me['code'],
                ':meEmail' => $me['email'],
                ':meEmailL' => strtolower($me['email']),
                ':meUserL' => strtolower($me['username']),
                ':peerCode' => $peer['code'],
                ':peerEmail' => $peer['email'],
                ':peerEmailL' => strtolower($peer['email']),
                ':peerUserL' => strtolower($peer['username']),
            ]);
        } catch (Throwable $e) {
            // ignore
        }
    }

    return $items;
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
 * Buyer ↔ shop-seller conversations live in Shopping Preferences Messages / Support Center,
 * not in personal messages.php. Friends still use personal Messages even if the peer also sells.
 */
function commerce_peer_belongs_in_shop_messages(PDO $dbh, int $viewerUserId, int $peerUserId): bool
{
    if ($viewerUserId <= 0 || $peerUserId <= 0 || $viewerUserId === $peerUserId) {
        return false;
    }
    if (function_exists('fs_are_friends') && fs_are_friends($dbh, $viewerUserId, $peerUserId)) {
        return false;
    }

    try {
        commerce_buyer_seller_contacts_ensure_schema($dbh);
        $st = $dbh->prepare('
            SELECT 1
            FROM buyer_seller_message_contacts
            WHERE buyer_user_id = :b
              AND publisher_user_id = :p
              AND removed_at IS NULL
            LIMIT 1
        ');
        $st->execute([':b' => $viewerUserId, ':p' => $peerUserId]);
        if ($st->fetchColumn()) {
            return true;
        }
    } catch (Throwable $e) {
        // fall through
    }

    $peerHasShop = commerce_messaging_publisher_has_shop($dbh, $peerUserId);
    if (!$peerHasShop && function_exists('org_is_commerce_seller_publisher')) {
        require_once __DIR__ . '/org_shop.php';
        $peerHasShop = org_is_commerce_seller_publisher($dbh, $peerUserId);
    }
    if (!$peerHasShop) {
        return false;
    }

    // Viewer is the buyer (not also acting as a shop seller peer in this lane).
    $viewerIsShopSeller = commerce_messaging_publisher_has_shop($dbh, $viewerUserId);
    if ($viewerIsShopSeller) {
        return false;
    }

    return function_exists('commerce_can_dm_pair')
        ? commerce_can_dm_pair($dbh, $viewerUserId, $peerUserId)
        : true;
}

/** @param list<array<string,mixed>> $threads */
function commerce_filter_out_shop_message_threads(PDO $dbh, int $viewerUserId, array $threads, string $peerIdKey = 'peer_id'): array
{
    if ($viewerUserId <= 0 || !$threads) {
        return $threads;
    }
    $out = [];
    foreach ($threads as $row) {
        if (!is_array($row)) {
            continue;
        }
        $peerId = (int)($row[$peerIdKey] ?? 0);
        if ($peerId <= 0) {
            $code = strtoupper(trim((string)($row['peer_key'] ?? $row['peer_code'] ?? '')));
            if ($code !== '') {
                $peerId = commerce_messaging_user_id_by_friend_code($dbh, $code);
            }
        }
        if ($peerId > 0 && commerce_peer_belongs_in_shop_messages($dbh, $viewerUserId, $peerId)) {
            continue;
        }
        $out[] = $row;
    }
    return $out;
}

/** Buyer-facing seller business profile (Shopping Preferences Messages → Info). */
function commerce_seller_info_url(int $publisherUserId, string $from = ''): string
{
    if ($publisherUserId <= 0) {
        return 'shop.php';
    }
    $q = ['id' => $publisherUserId];
    if ($from !== '') {
        $q['from'] = $from;
    }
    return 'seller_info.php?' . http_build_query($q);
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
 * Persist buyer → seller contact list (appears after first message; stays until customer removes it).
 */
function commerce_buyer_seller_contacts_ensure_schema(PDO $dbh): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    try {
        $dbh->exec("
            CREATE TABLE IF NOT EXISTS buyer_seller_message_contacts (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                buyer_user_id INT UNSIGNED NOT NULL,
                publisher_user_id INT UNSIGNED NOT NULL,
                org_id INT UNSIGNED NOT NULL DEFAULT 0,
                last_about_product_id INT UNSIGNED NOT NULL DEFAULT 0,
                removed_at DATETIME NULL DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_buyer_seller_msg_contact (buyer_user_id, publisher_user_id),
                KEY idx_buyer_seller_msg_active (buyer_user_id, removed_at, updated_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        try {
            $dbh->exec('ALTER TABLE buyer_seller_message_contacts ADD COLUMN last_about_product_id INT UNSIGNED NOT NULL DEFAULT 0');
        } catch (Throwable $e) {
            // column already exists
        }
        $ready = true;
    } catch (Throwable $e) {
        // leave $ready false so we retry later
    }
}

function commerce_buyer_seller_contact_remember(PDO $dbh, int $buyerUserId, int $publisherUserId, int $aboutProductId = 0): bool
{
    if ($buyerUserId <= 0 || $publisherUserId <= 0 || $buyerUserId === $publisherUserId) {
        return false;
    }

    // Shopping Preferences "Messages" is buyer → shop seller only.
    require_once __DIR__ . '/org_shop.php';
    if (!org_is_commerce_seller_publisher($dbh, $publisherUserId)
        && !commerce_messaging_publisher_has_shop($dbh, $publisherUserId)) {
        return false;
    }

    $orgId = 0;
    try {
        $stOrg = $dbh->prepare('
            SELECT id FROM organizations
            WHERE publisher_user_id = :p AND status = 1
              AND (
                (commerce_brand_id IS NOT NULL AND commerce_brand_id > 0
                  AND LOWER(TRIM(COALESCE(publisher_category, \'\'))) IN (\'\', \'commerce\'))
                OR LOWER(TRIM(COALESCE(publisher_category, \'\'))) = \'commerce\'
              )
            ORDER BY id ASC
            LIMIT 1
        ');
        $stOrg->execute([':p' => $publisherUserId]);
        $orgId = (int)($stOrg->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        $orgId = 0;
    }
    if ($orgId <= 0) {
        return false;
    }

    $aboutProductId = max(0, $aboutProductId);
    if ($aboutProductId > 0) {
        $prod = org_shop_get_product($dbh, $aboutProductId, $orgId);
        if ($prod === null) {
            // Still allow contact save; drop invalid product focus.
            $aboutProductId = 0;
        }
    }

    commerce_buyer_seller_contacts_ensure_schema($dbh);
    try {
        $st = $dbh->prepare("
            INSERT INTO buyer_seller_message_contacts (
                buyer_user_id, publisher_user_id, org_id, last_about_product_id, removed_at, created_at, updated_at
            ) VALUES (:b, :p, :o, :prod, NULL, NOW(), NOW())
            ON DUPLICATE KEY UPDATE
                org_id = IF(VALUES(org_id) > 0, VALUES(org_id), org_id),
                last_about_product_id = IF(VALUES(last_about_product_id) > 0, VALUES(last_about_product_id), last_about_product_id),
                removed_at = NULL,
                updated_at = NOW()
        ");
        $st->execute([
            ':b' => $buyerUserId,
            ':p' => $publisherUserId,
            ':o' => $orgId,
            ':prod' => $aboutProductId,
        ]);
        return true;
    } catch (Throwable $e) {
        // Fallback if column missing on older DBs mid-migrate.
        try {
            $st = $dbh->prepare("
                INSERT INTO buyer_seller_message_contacts (buyer_user_id, publisher_user_id, org_id, removed_at, created_at, updated_at)
                VALUES (:b, :p, :o, NULL, NOW(), NOW())
                ON DUPLICATE KEY UPDATE
                    org_id = IF(VALUES(org_id) > 0, VALUES(org_id), org_id),
                    removed_at = NULL,
                    updated_at = NOW()
            ");
            $st->execute([':b' => $buyerUserId, ':p' => $publisherUserId, ':o' => $orgId]);
            return true;
        } catch (Throwable $e2) {
            return false;
        }
    }
}

function commerce_buyer_seller_contact_remove(PDO $dbh, int $buyerUserId, int $publisherUserId): bool
{
    if ($buyerUserId <= 0 || $publisherUserId <= 0) {
        return false;
    }
    commerce_buyer_seller_contacts_ensure_schema($dbh);
    try {
        $st = $dbh->prepare("
            UPDATE buyer_seller_message_contacts
            SET removed_at = NOW(), updated_at = NOW()
            WHERE buyer_user_id = :b AND publisher_user_id = :p AND removed_at IS NULL
            LIMIT 1
        ");
        $st->execute([':b' => $buyerUserId, ':p' => $publisherUserId]);
        return $st->rowCount() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * One-time style backfill: sellers with existing shop DMs get saved contacts
 * (unless the customer already removed them).
 */
function commerce_buyer_seller_contacts_backfill_from_messages(PDO $dbh, int $buyerUserId): void
{
    if ($buyerUserId <= 0) {
        return;
    }
    commerce_buyer_seller_contacts_ensure_schema($dbh);
    $meCode = '';
    $meEmail = '';
    try {
        $stMe = $dbh->prepare('SELECT friend_code, email FROM users WHERE id = :id LIMIT 1');
        $stMe->execute([':id' => $buyerUserId]);
        $me = $stMe->fetch(PDO::FETCH_ASSOC) ?: [];
        $meCode = strtoupper(trim((string)($me['friend_code'] ?? '')));
        $meEmail = trim((string)($me['email'] ?? ''));
    } catch (Throwable $e) {
        return;
    }
    if ($meCode === '' && $meEmail === '') {
        return;
    }
    try {
        $st = $dbh->prepare("
            SELECT DISTINCT u.id AS publisher_user_id
            FROM feedback f
            INNER JOIN users u ON (
                UPPER(u.friend_code) = UPPER(f.sender)
                OR LOWER(u.email) = LOWER(f.sender)
                OR LOWER(u.username) = LOWER(f.sender)
                OR UPPER(u.friend_code) = UPPER(f.receiver)
                OR LOWER(u.email) = LOWER(f.receiver)
                OR LOWER(u.username) = LOWER(f.receiver)
            )
            INNER JOIN organizations org ON org.publisher_user_id = u.id AND org.status = 1
              AND (
                (org.commerce_brand_id IS NOT NULL AND org.commerce_brand_id > 0
                  AND LOWER(TRIM(COALESCE(org.publisher_category, ''))) IN ('', 'commerce'))
                OR LOWER(TRIM(COALESCE(org.publisher_category, ''))) = 'commerce'
              )
            WHERE f.channel = 'user_user'
              AND u.status = 1
              AND u.id <> :buyer
              AND (
                (UPPER(f.sender) = :meCode OR LOWER(f.sender) = LOWER(:meEmail) OR (:meUser <> '' AND LOWER(f.sender) = LOWER(:meUser)))
                OR (UPPER(f.receiver) = :meCode2 OR LOWER(f.receiver) = LOWER(:meEmail2) OR (:meUser2 <> '' AND LOWER(f.receiver) = LOWER(:meUser2)))
              )
            LIMIT 100
        ");
        $meUsername = '';
        try {
            $stU = $dbh->prepare('SELECT username FROM users WHERE id = :id LIMIT 1');
            $stU->execute([':id' => $buyerUserId]);
            $meUsername = trim((string)($stU->fetchColumn() ?: ''));
        } catch (Throwable $e) {
            $meUsername = '';
        }
        $st->execute([
            ':buyer' => $buyerUserId,
            ':meCode' => $meCode,
            ':meEmail' => $meEmail,
            ':meUser' => $meUsername,
            ':meCode2' => $meCode,
            ':meEmail2' => $meEmail,
            ':meUser2' => $meUsername,
        ]);
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $pubId = (int)($row['publisher_user_id'] ?? 0);
            if ($pubId <= 0) {
                continue;
            }
            // Do not revive contacts the customer already removed.
            $chk = $dbh->prepare('SELECT removed_at FROM buyer_seller_message_contacts WHERE buyer_user_id = :b AND publisher_user_id = :p LIMIT 1');
            $chk->execute([':b' => $buyerUserId, ':p' => $pubId]);
            $existing = $chk->fetch(PDO::FETCH_ASSOC);
            if ($existing && $existing['removed_at'] !== null) {
                continue;
            }
            commerce_buyer_seller_contact_remember($dbh, $buyerUserId, $pubId);
        }
    } catch (Throwable $e) {
        // ignore
    }
}

/**
 * Sellers a buyer has messaged from Shopping Preferences / product "Message seller".
 * - Shop sellers only.
 * - Saved after the first message and kept until the customer removes the contact.
 * Deep-links (?seller_msg=) temporarily inject the peer in Your_Shopping_preferences.php
 * so the customer can send the first message.
 *
 * @return list<array{publisher_user_id:int,org_id:int,seller_name:string,friend_code:string,last_message:string,last_at:string,unread:int}>
 */
function commerce_list_buyer_seller_contacts(PDO $dbh, int $buyerUserId): array
{
    if ($buyerUserId <= 0) {
        return [];
    }
    commerce_buyer_seller_contacts_ensure_schema($dbh);
    commerce_buyer_seller_contacts_backfill_from_messages($dbh, $buyerUserId);
    // Drop non-shop contacts that were saved before Messages was seller-only.
    try {
        $dbh->prepare("
            UPDATE buyer_seller_message_contacts c
            LEFT JOIN organizations org ON org.publisher_user_id = c.publisher_user_id AND org.status = 1
              AND (
                (org.commerce_brand_id IS NOT NULL AND org.commerce_brand_id > 0
                  AND LOWER(TRIM(COALESCE(org.publisher_category, ''))) IN ('', 'commerce'))
                OR LOWER(TRIM(COALESCE(org.publisher_category, ''))) = 'commerce'
              )
            SET c.removed_at = NOW(), c.updated_at = NOW()
            WHERE c.buyer_user_id = :buyer
              AND c.removed_at IS NULL
              AND org.id IS NULL
        ")->execute([':buyer' => $buyerUserId]);
    } catch (Throwable $e) {
        // ignore
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

    $list = [];
    try {
        $st = $dbh->prepare("
            SELECT
                c.publisher_user_id,
                c.org_id,
                c.last_about_product_id,
                c.updated_at,
                u.friend_code,
                COALESCE(NULLIF(TRIM(org.name), ''), NULLIF(TRIM(u.name), ''), NULLIF(TRIM(u.username), ''), u.friend_code) AS seller_name
            FROM buyer_seller_message_contacts c
            INNER JOIN users u ON u.id = c.publisher_user_id AND u.status = 1
            INNER JOIN organizations org ON org.publisher_user_id = c.publisher_user_id AND org.status = 1
              AND (
                (org.commerce_brand_id IS NOT NULL AND org.commerce_brand_id > 0
                  AND LOWER(TRIM(COALESCE(org.publisher_category, ''))) IN ('', 'commerce'))
                OR LOWER(TRIM(COALESCE(org.publisher_category, ''))) = 'commerce'
              )
            WHERE c.buyer_user_id = :buyer
              AND c.removed_at IS NULL
            ORDER BY c.updated_at DESC
            LIMIT 100
        ");
        $st->execute([':buyer' => $buyerUserId]);
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $pubId = (int)($row['publisher_user_id'] ?? 0);
            $fc = strtoupper(trim((string)($row['friend_code'] ?? '')));
            if ($pubId <= 0) {
                continue;
            }
            $lastMessage = '';
            $lastAt = (string)($row['updated_at'] ?? '');
            $unread = 0;
            $aboutProductId = (int)($row['last_about_product_id'] ?? 0);
            if ($meCode !== '') {
                try {
                    $stMsg = $dbh->prepare("
                        SELECT feedbackdata, created_at
                        FROM feedback
                        WHERE channel = 'user_user'
                          AND (
                            (UPPER(sender) = :me AND UPPER(receiver) = :peer)
                            OR (UPPER(receiver) = :me2 AND UPPER(sender) = :peer2)
                          )
                        ORDER BY created_at DESC, id DESC
                        LIMIT 1
                    ");
                    $stMsg->execute([
                        ':me' => $meCode,
                        ':peer' => $fc,
                        ':me2' => $meCode,
                        ':peer2' => $fc,
                    ]);
                    if ($m = $stMsg->fetch(PDO::FETCH_ASSOC)) {
                        $lastMessage = trim((string)($m['feedbackdata'] ?? ''));
                        $lastAt = (string)($m['created_at'] ?? $lastAt);
                    }
                    $sellerAliases = commerce_messaging_user_aliases($dbh, $pubId);
                    $buyerAliases = commerce_messaging_user_aliases($dbh, $buyerUserId);
                    if ($sellerAliases !== [] && $buyerAliases !== []) {
                        $sPh = [];
                        $bPh = [];
                        $params = [];
                        foreach ($sellerAliases as $i => $a) {
                            $k = ':s' . $i;
                            $sPh[] = $k;
                            $params[$k] = $a;
                        }
                        foreach ($buyerAliases as $i => $a) {
                            $k = ':b' . $i;
                            $bPh[] = $k;
                            $params[$k] = $a;
                        }
                        $stUn = $dbh->prepare("
                            SELECT COUNT(*) FROM feedback
                            WHERE channel = 'user_user'
                              AND is_read = 0
                              AND sender IN (" . implode(',', $sPh) . ")
                              AND receiver IN (" . implode(',', $bPh) . ")
                        ");
                        $stUn->execute($params);
                        $unread = (int)($stUn->fetchColumn() ?: 0);
                    }
                } catch (Throwable $e) {
                    // keep empty preview
                }
            }
            $list[] = [
                'publisher_user_id' => $pubId,
                'org_id' => (int)($row['org_id'] ?? 0),
                'seller_name' => trim((string)($row['seller_name'] ?? 'Seller')),
                'friend_code' => $fc,
                'last_message' => $lastMessage,
                'last_at' => $lastAt,
                'unread' => $unread,
                'about_product_id' => $aboutProductId,
            ];
        }
    } catch (Throwable $e) {
        return [];
    }

    usort($list, static function (array $a, array $b): int {
        return strcmp((string)($b['last_at'] ?? ''), (string)($a['last_at'] ?? ''));
    });
    return $list;
}

function commerce_buyer_seller_unread_count(PDO $dbh, int $buyerUserId): int
{
    if ($buyerUserId <= 0) {
        return 0;
    }
    // Ensure seller-initiated threads appear in the buyer's contact list / badge.
    try {
        commerce_buyer_seller_contacts_backfill_from_messages($dbh, $buyerUserId);
    } catch (Throwable $e) {
        // ignore
    }

    $buyerAliases = commerce_messaging_user_aliases($dbh, $buyerUserId);
    if ($buyerAliases === []) {
        return 0;
    }

    try {
        $bPh = [];
        $params = [':buyer' => $buyerUserId];
        foreach ($buyerAliases as $i => $a) {
            $k = ':ba' . $i;
            $bPh[] = $k;
            $params[$k] = $a;
        }
        // Unread user_user rows addressed to this buyer from any active shop seller contact.
        $st = $dbh->prepare("
            SELECT COUNT(*) FROM feedback f
            INNER JOIN buyer_seller_message_contacts c
              ON c.buyer_user_id = :buyer AND c.removed_at IS NULL
            INNER JOIN users su ON su.id = c.publisher_user_id AND su.status = 1
            WHERE f.channel = 'user_user'
              AND f.is_read = 0
              AND f.receiver IN (" . implode(',', $bPh) . ")
              AND (
                    UPPER(f.sender) = UPPER(su.friend_code)
                 OR LOWER(f.sender) = LOWER(su.email)
                 OR LOWER(f.sender) = LOWER(su.username)
              )
        ");
        $st->execute($params);
        return max(0, (int)($st->fetchColumn() ?: 0));
    } catch (Throwable $e) {
        $total = 0;
        foreach (commerce_list_buyer_seller_contacts($dbh, $buyerUserId) as $c) {
            $total += max(0, (int)($c['unread'] ?? 0));
        }
        return $total;
    }
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
    $meUsername = '';
    try {
        $stMe = $dbh->prepare('SELECT friend_code, email, username FROM users WHERE id = :id LIMIT 1');
        $stMe->execute([':id' => $publisherUserId]);
        $me = $stMe->fetch(PDO::FETCH_ASSOC) ?: [];
        $meCode = strtoupper(trim((string)($me['friend_code'] ?? '')));
        $meEmail = trim((string)($me['email'] ?? ''));
        $meUsername = trim((string)($me['username'] ?? ''));
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
                'needs_reply' => false,
                'order_code' => trim((string)($row['order_code'] ?? '')),
            ];
        }
    } catch (Throwable $e) {
        // ignore
    }

    if ($meCode !== '' || $meEmail !== '' || $meUsername !== '') {
        try {
            $sellerParty = [];
            $params = [':pub' => $publisherUserId];
            if ($meCode !== '') {
                $sellerParty[] = 'UPPER(f.sender) = :meCode';
                $sellerParty[] = 'UPPER(f.receiver) = :meCode2';
                $params[':meCode'] = $meCode;
                $params[':meCode2'] = $meCode;
            }
            if ($meEmail !== '') {
                $sellerParty[] = 'LOWER(f.sender) = :meEmail';
                $sellerParty[] = 'LOWER(f.receiver) = :meEmail2';
                $params[':meEmail'] = strtolower($meEmail);
                $params[':meEmail2'] = strtolower($meEmail);
            }
            if ($meUsername !== '') {
                $sellerParty[] = 'LOWER(f.sender) = :meUser';
                $sellerParty[] = 'LOWER(f.receiver) = :meUser2';
                $params[':meUser'] = strtolower($meUsername);
                $params[':meUser2'] = strtolower($meUsername);
            }
            $sellerSql = '(' . implode(' OR ', $sellerParty) . ')';
            $unreadSql = '0';
            if ($meCode !== '' || $meEmail !== '' || $meUsername !== '') {
                $unreadParts = [];
                if ($meCode !== '') {
                    $unreadParts[] = 'UPPER(f.receiver) = :uCode';
                    $params[':uCode'] = $meCode;
                }
                if ($meEmail !== '') {
                    $unreadParts[] = 'LOWER(f.receiver) = :uEmail';
                    $params[':uEmail'] = strtolower($meEmail);
                }
                if ($meUsername !== '') {
                    $unreadParts[] = 'LOWER(f.receiver) = :uUser';
                    $params[':uUser'] = strtolower($meUsername);
                }
                $unreadSql = 'SUM(CASE WHEN f.is_read = 0 AND (' . implode(' OR ', $unreadParts) . ') THEN 1 ELSE 0 END)';
            }

            $stT = $dbh->prepare("
                SELECT
                    u.id AS buyer_user_id,
                    u.friend_code,
                    COALESCE(NULLIF(TRIM(u.name), ''), NULLIF(TRIM(u.username), ''), u.friend_code) AS buyer_name,
                    MAX(f.created_at) AS last_at,
                    SUBSTRING_INDEX(GROUP_CONCAT(f.feedbackdata ORDER BY f.created_at DESC SEPARATOR '\\n'), '\\n', 1) AS last_message,
                    SUBSTRING_INDEX(GROUP_CONCAT(f.sender ORDER BY f.created_at DESC SEPARATOR '\\n'), '\\n', 1) AS last_sender,
                    {$unreadSql} AS unread
                FROM feedback f
                INNER JOIN users u ON u.status = 1 AND u.id <> :pub AND (
                    UPPER(u.friend_code) = UPPER(f.sender) OR LOWER(u.email) = LOWER(f.sender) OR LOWER(u.username) = LOWER(f.sender)
                    OR UPPER(u.friend_code) = UPPER(f.receiver) OR LOWER(u.email) = LOWER(f.receiver) OR LOWER(u.username) = LOWER(f.receiver)
                )
                WHERE f.channel = 'user_user'
                  AND {$sellerSql}
                GROUP BY u.id, u.friend_code, buyer_name
                ORDER BY last_at DESC
                LIMIT 100
            ");
            $stT->execute($params);
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
                        'needs_reply' => false,
                        'order_code' => '',
                    ];
                }
                $map[$buyerId]['last_message'] = commerce_messaging_plain_text(trim((string)($row['last_message'] ?? '')));
                $map[$buyerId]['last_at'] = (string)($row['last_at'] ?? $map[$buyerId]['last_at']);
                $map[$buyerId]['unread'] = (int)($row['unread'] ?? 0);
                $lastSender = trim((string)($row['last_sender'] ?? ''));
                $buyerAliases = commerce_messaging_user_aliases($dbh, $buyerId);
                $needsReply = false;
                if ($lastSender !== '' && $buyerAliases) {
                    foreach ($buyerAliases as $alias) {
                        if (strcasecmp($lastSender, (string)$alias) === 0) {
                            $needsReply = true;
                            break;
                        }
                    }
                }
                $map[$buyerId]['needs_reply'] = $needsReply;
            }
        } catch (Throwable $e) {
            // ignore
        }
    }

    $list = array_values($map);

    // Attach last focused product from buyer → seller Messages deep-links.
    if ($list && $publisherUserId > 0) {
        commerce_buyer_seller_contacts_ensure_schema($dbh);
        try {
            $stFocus = $dbh->prepare("
                SELECT buyer_user_id, last_about_product_id
                FROM buyer_seller_message_contacts
                WHERE publisher_user_id = :pub
                  AND removed_at IS NULL
                  AND last_about_product_id > 0
            ");
            $stFocus->execute([':pub' => $publisherUserId]);
            $focusMap = [];
            while ($fr = $stFocus->fetch(PDO::FETCH_ASSOC)) {
                $bid = (int)($fr['buyer_user_id'] ?? 0);
                $pid = (int)($fr['last_about_product_id'] ?? 0);
                if ($bid > 0 && $pid > 0) {
                    $focusMap[$bid] = $pid;
                }
            }
            foreach ($list as &$cRow) {
                $bid = (int)($cRow['buyer_user_id'] ?? 0);
                $cRow['about_product_id'] = (int)($focusMap[$bid] ?? ($cRow['about_product_id'] ?? 0));
            }
            unset($cRow);
        } catch (Throwable $e) {
            foreach ($list as &$cRow) {
                if (!isset($cRow['about_product_id'])) {
                    $cRow['about_product_id'] = 0;
                }
            }
            unset($cRow);
        }
    }

    usort($list, static function (array $a, array $b): int {
        return strcmp((string)($b['last_at'] ?? ''), (string)($a['last_at'] ?? ''));
    });
    return $list;
}

/**
 * Seller Messages hub badge: unread customer messages, or threads awaiting a seller reply.
 */
function commerce_seller_buyer_unread_count(PDO $dbh, int $publisherUserId): int
{
    $unreadSum = 0;
    $attentionThreads = 0;
    foreach (commerce_list_seller_buyer_contacts($dbh, $publisherUserId) as $c) {
        $unread = max(0, (int)($c['unread'] ?? 0));
        $unreadSum += $unread;
        if ($unread > 0 || !empty($c['needs_reply'])) {
            $attentionThreads++;
        }
    }
    // Prefer raw unread message count when present; otherwise count threads needing a reply.
    return $unreadSum > 0 ? $unreadSum : $attentionThreads;
}

/**
 * Optional compose draft for order context only.
 * Product focus is shown in the sticky product card (not the textarea).
 */
function commerce_messaging_compose_draft(PDO $dbh, int $aboutProductId = 0, string $aboutOrder = ''): string
{
    // Signature kept for existing call sites; product context is not placed in the textarea.
    $aboutOrder = trim($aboutOrder);
    if ($aboutOrder === '') {
        return '';
    }
    return 'About order ' . $aboutOrder . "\n\n";
}

/**
 * One-line product marker stored in the thread when focus switches products.
 * Renders as a product bubble in history; sticky card tracks the newest.
 */
function commerce_messaging_product_marker_line(PDO $dbh, int $aboutProductId): string
{
    $aboutProductId = max(0, $aboutProductId);
    if ($aboutProductId <= 0) {
        return '';
    }
    try {
        require_once __DIR__ . '/org_shop.php';
        $p = org_shop_get_product($dbh, $aboutProductId);
        $title = trim((string)($p['title'] ?? ''));
        $code = trim((string)($p['product_code'] ?? ''));
        if ($code === '' && function_exists('org_shop_product_code_from_id')) {
            $code = org_shop_product_code_from_id($aboutProductId);
        }
        $idBit = 'Product ID #' . $aboutProductId;
        if ($code !== '') {
            $idBit .= ' · ' . $code;
        }
        if ($title !== '') {
            return $idBit . ' — ' . $title;
        }
        return $idBit;
    } catch (Throwable $e) {
        return 'Product ID #' . $aboutProductId;
    }
}

/** Last product the buyer↔seller contact was focused on (0 if none). */
function commerce_buyer_seller_last_about_product(PDO $dbh, int $buyerUserId, int $publisherUserId): int
{
    if ($buyerUserId <= 0 || $publisherUserId <= 0) {
        return 0;
    }
    commerce_buyer_seller_contacts_ensure_schema($dbh);
    try {
        $st = $dbh->prepare('
            SELECT last_about_product_id
            FROM buyer_seller_message_contacts
            WHERE buyer_user_id = :b AND publisher_user_id = :p AND removed_at IS NULL
            LIMIT 1
        ');
        $st->execute([':b' => $buyerUserId, ':p' => $publisherUserId]);
        return max(0, (int)($st->fetchColumn() ?: 0));
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * Extract org product id from commerce chat text
 * (draft lines, "product #8", "Product ID #8", etc.).
 */
function commerce_messaging_parse_product_id_from_text(string $text): int
{
    $text = trim($text);
    if ($text === '') {
        return 0;
    }
    if (preg_match('/Product\s*ID\s*#\s*(\d+)/i', $text, $m)) {
        return (int)$m[1];
    }
    if (preg_match('/\bproduct\s*#\s*(\d+)/i', $text, $m)) {
        return (int)$m[1];
    }
    if (preg_match('/\(product\s*#\s*(\d+)\)/i', $text, $m)) {
        return (int)$m[1];
    }
    if (preg_match('/\bPRD-([0-9A-Z]+)\b/i', $text, $m) && function_exists('org_shop_product_code_from_id')) {
        // Best-effort: numeric base36 codes from org_shop_product_code_from_id
        $n = (int)@base_convert(strtoupper($m[1]), 36, 10);
        if ($n > 0) {
            return $n;
        }
    }
    return 0;
}

/**
 * Product focus card for buyer/seller commerce chat (image + id + title).
 *
 * @return array{id:int,code:string,title:string,price:string,cover:string,buyer_href:string,seller_href:string}|null
 */
function commerce_messaging_product_focus(PDO $dbh, int $productId, int $orgId = 0): ?array
{
    if ($productId <= 0) {
        return null;
    }
    try {
        require_once __DIR__ . '/org_shop.php';
        $p = org_shop_get_product($dbh, $productId, $orgId);
        if (!$p) {
            return null;
        }
        $id = (int)($p['id'] ?? $productId);
        $title = trim((string)($p['title'] ?? 'Product'));
        $code = trim((string)($p['product_code'] ?? ''));
        if ($code === '' && function_exists('org_shop_product_code_from_id')) {
            $code = org_shop_product_code_from_id($id);
        }
        $price = function_exists('org_shop_format_price')
            ? org_shop_format_price((int)($p['price_cents'] ?? 0), (string)($p['currency'] ?? 'USD'))
            : '';
        $cover = function_exists('org_shop_cover_url')
            ? org_shop_cover_url((string)($p['cover_image_path'] ?? ''))
            : '';
        return [
            'id' => $id,
            'code' => $code,
            'title' => $title !== '' ? $title : ('Product #' . $id),
            'price' => $price,
            'cover' => $cover,
            'buyer_href' => 'product_detail.php?id=' . $id,
            'seller_href' => 'sales_management.php?inv_product=' . $id . '#inventory-detail',
        ];
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Unread commerce inbox rows (shipping / refund / order alerts) for the buyer.
 */
function commerce_buyer_unread_commerce_inbox_count(PDO $dbh, int $buyerUserId): int
{
    if ($buyerUserId <= 0) {
        return 0;
    }
    try {
        require_once __DIR__ . '/org_shop.php';
        $username = function_exists('org_shop_user_username')
            ? org_shop_user_username($dbh, $buyerUserId)
            : '';
        if ($username === '') {
            return 0;
        }
        require_once __DIR__ . '/app_notification_api.php';
        $st = $dbh->prepare("
            SELECT COUNT(*)
            FROM notification
            WHERE notireceiver = ? AND is_read = 0
            " . app_notification_shop_only_sql() . "
        ");
        $st->execute(array_merge([$username], app_notification_shop_like_patterns()));
        return (int)$st->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * Combined shop-icon badge: order alerts + seller DMs + Support Center + unread shop inbox.
 * Shown on the bag icon and on shop.php → Shopping Preferences entry.
 */
function commerce_buyer_shop_hub_badge_count(PDO $dbh, int $buyerUserId): int
{
    if ($buyerUserId <= 0) {
        return 0;
    }
    $total = 0;
    try {
        require_once __DIR__ . '/org_shop.php';
        if (function_exists('org_shop_buyer_commerce_alerts')) {
            foreach (org_shop_buyer_commerce_alerts($dbh, $buyerUserId) as $alert) {
                $total += max(0, (int)($alert['count'] ?? 0));
            }
        }
    } catch (Throwable $e) {
        // ignore
    }
    try {
        $total += max(0, (int)commerce_buyer_seller_unread_count($dbh, $buyerUserId));
    } catch (Throwable $e) {
        // ignore
    }
    try {
        require_once __DIR__ . '/admin_support_chat.php';
        $email = function_exists('admin_support_user_email')
            ? admin_support_user_email($dbh, $buyerUserId)
            : '';
        if ($email !== '' && function_exists('admin_support_unread_count')) {
            $total += max(0, (int)admin_support_unread_count($dbh, $email));
        }
    } catch (Throwable $e) {
        // ignore
    }
    try {
        $total += max(0, (int)commerce_buyer_unread_commerce_inbox_count($dbh, $buyerUserId));
    } catch (Throwable $e) {
        // ignore
    }
    return max(0, $total);
}
