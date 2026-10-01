<?php
declare(strict_types=1);

/**
 * Seller ↔ buyer commerce chat for sales_management.php#message.
 * Sends/receives as the organization publisher identity in feedback (user_user).
 */

require_once __DIR__ . '/../includes/session_org.php';
require_once __DIR__ . '/../includes/org_context.php';
require_once __DIR__ . '/../includes/org_manager_guard.php';
require_once __DIR__ . '/../../public_user/includes/staff_publisher_access.php';
require_once __DIR__ . '/../../public_user/includes/commerce_messaging.php';
require_once __DIR__ . '/../../public_user/includes/friend_system.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function sbc_json(array $a): void
{
    echo json_encode($a, JSON_UNESCAPED_UNICODE);
    exit;
}

// Staff sellers may chat with customers (same as Sales Management page).
org_require_commerce_seller();

$orgId = (int)orgActiveOrgId();
$publisherUserId = staff_pub_org_publisher_user_id($dbh, $orgId);
if ($publisherUserId <= 0) {
    $publisherUserId = (int)($_SESSION['org_publisher_user_id'] ?? 0);
}
if ($publisherUserId <= 0) {
    sbc_json(['ok' => false, 'error' => 'No publisher account linked to this shop.']);
}

$meCode = '';
$meEmail = '';
$meUsername = '';
try {
    $stMe = $dbh->prepare('SELECT friend_code, email, username FROM users WHERE id = :id AND status = 1 LIMIT 1');
    $stMe->execute([':id' => $publisherUserId]);
    $me = $stMe->fetch(PDO::FETCH_ASSOC) ?: [];
    $meCode = strtoupper(trim((string)($me['friend_code'] ?? '')));
    $meEmail = trim((string)($me['email'] ?? ''));
    $meUsername = trim((string)($me['username'] ?? ''));
} catch (Throwable $e) {
    sbc_json(['ok' => false, 'error' => 'Could not resolve seller identity.']);
}
if ($meCode === '' && $meEmail === '' && $meUsername === '') {
    sbc_json(['ok' => false, 'error' => 'Seller chat identity missing.']);
}

$mode = strtolower(trim((string)($_GET['mode'] ?? $_POST['mode'] ?? 'history')));
$peerCode = strtoupper(trim((string)($_GET['peer'] ?? $_POST['peer'] ?? $_POST['to'] ?? '')));
$peerUserIdParam = (int)($_GET['buyer_id'] ?? $_POST['buyer_id'] ?? 0);

if ($mode === 'product') {
    $productId = (int)($_GET['product_id'] ?? $_POST['product_id'] ?? 0);
    if ($productId <= 0) {
        sbc_json(['ok' => false, 'error' => 'Missing product.']);
    }
    $focus = function_exists('commerce_messaging_product_focus')
        ? commerce_messaging_product_focus($dbh, $productId, $orgId)
        : null;
    if (!$focus) {
        sbc_json(['ok' => false, 'error' => 'Product not found.']);
    }
    sbc_json(['ok' => true, 'product' => $focus]);
}

$peerId = 0;
if ($peerUserIdParam > 0) {
    $peerId = $peerUserIdParam;
} elseif ($peerCode !== '' && preg_match('/^[A-Z]{3}-[A-Z0-9]{4}-[A-Z0-9]{4}$/i', $peerCode)) {
    $peerId = commerce_messaging_user_id_by_friend_code($dbh, $peerCode);
}

if ($peerId <= 0) {
    sbc_json(['ok' => false, 'error' => 'Select a customer to chat with.']);
}
if (!commerce_can_dm_pair($dbh, $publisherUserId, $peerId) && !fs_are_friends($dbh, $publisherUserId, $peerId)) {
    // Still allow if they share order lines for this org (covers edge identity cases).
    $hasOrder = false;
    try {
        $stOrd = $dbh->prepare('
            SELECT 1 FROM org_orders
            WHERE org_id = :org AND buyer_user_id = :buyer
            LIMIT 1
        ');
        $stOrd->execute([':org' => $orgId, ':buyer' => $peerId]);
        $hasOrder = (bool)$stOrd->fetchColumn();
    } catch (Throwable $e) {
        $hasOrder = false;
    }
    if (!$hasOrder) {
        sbc_json(['ok' => false, 'error' => 'You can only message customers about products or orders.']);
    }
}

$peerEmail = '';
$peerDisplay = $peerCode !== '' ? $peerCode : ('Customer #' . $peerId);
$peerFriendCode = '';
try {
    $stP = $dbh->prepare("
        SELECT email, friend_code, username,
               COALESCE(NULLIF(TRIM(name), ''), NULLIF(TRIM(username), ''), friend_code) AS display
        FROM users WHERE id = :id LIMIT 1
    ");
    $stP->execute([':id' => $peerId]);
    $p = $stP->fetch(PDO::FETCH_ASSOC) ?: [];
    $peerEmail = trim((string)($p['email'] ?? ''));
    $peerFriendCode = strtoupper(trim((string)($p['friend_code'] ?? '')));
    $peerDisplay = trim((string)($p['display'] ?? $peerDisplay)) ?: $peerDisplay;
    if ($peerCode === '' && $peerFriendCode !== '') {
        $peerCode = $peerFriendCode;
    }
} catch (Throwable $e) {
    // keep defaults
}

if ($mode === 'send') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sbc_json(['ok' => false, 'error' => 'POST required.']);
    }
    $text = trim((string)($_POST['message'] ?? ''));
    if ($text === '') {
        sbc_json(['ok' => false, 'error' => 'Message cannot be empty.']);
    }
    $receiver = $peerFriendCode !== '' ? $peerFriendCode : $peerCode;
    if ($receiver === '') {
        sbc_json(['ok' => false, 'error' => 'Customer friend code missing.']);
    }
    $sender = $meCode !== '' ? $meCode : ($meUsername !== '' ? $meUsername : $meEmail);
    $aboutProductId = (int)($_POST['about_product'] ?? $_GET['about_product'] ?? 0);
    try {
        $st = $dbh->prepare("
            INSERT INTO feedback
                (sender, receiver, channel, title, feedbackdata,
                 attachment, attachment_type, attachment_original, attachment_url,
                 is_read, created_at)
            VALUES
                (:s, :r, 'user_user', '', :msg,
                 NULL, NULL, NULL, NULL,
                 0, NOW())
        ");
        $st->execute([
            ':s' => $sender,
            ':r' => $receiver,
            ':msg' => $text,
        ]);
        $id = (int)$dbh->lastInsertId();
        // Keep buyer Messages + shop-hub badge in sync when seller initiates/replies.
        if (function_exists('commerce_buyer_seller_contact_remember')) {
            commerce_buyer_seller_contact_remember($dbh, $peerId, $publisherUserId, $aboutProductId);
        }
        $createdAt = date('Y-m-d H:i:s');
        $ts = strtotime($createdAt) ?: time();
        sbc_json([
            'ok' => true,
            'item' => [
                'id' => $id,
                'is_me' => true,
                'text' => $text,
                'created_at' => $createdAt,
                'time_label' => date('M d, Y h:i A', $ts),
                'sender_name' => 'You',
                'peer_name' => $peerDisplay,
            ],
        ]);
    } catch (Throwable $e) {
        sbc_json(['ok' => false, 'error' => 'Could not send message.']);
    }
}

// history / poll — same identity rules as buyer Messages (user_chat_poll.php)
$after = (int)($_GET['after'] ?? $_POST['after'] ?? 0);
$mark = (int)($_GET['mark'] ?? $_POST['mark'] ?? 1);
$historyLimit = $after > 0 ? 300 : 2000;

try {
    $items = commerce_messaging_thread_items(
        $dbh,
        $publisherUserId,
        $peerId,
        $after,
        $historyLimit,
        $mark === 1
    );

    // Belt-and-suspenders: if helper returns nothing, run the exact buyer poll SQL.
    if (!$items && $after <= 0) {
        $stHist = $dbh->prepare("
            SELECT f.id, f.sender, f.receiver, f.feedbackdata, f.created_at, f.is_read
            FROM feedback f
            WHERE f.channel = 'user_user'
              AND f.id > 0
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
            LIMIT {$historyLimit}
        ");
        $stHist->execute([
            ':meCode' => $meCode,
            ':meEmail' => $meEmail,
            ':peerCode' => $peerFriendCode !== '' ? $peerFriendCode : $peerCode,
            ':peerEmail' => $peerEmail,
            ':peerCode2' => $peerFriendCode !== '' ? $peerFriendCode : $peerCode,
            ':peerEmail2' => $peerEmail,
            ':meCode2' => $meCode,
            ':meEmail2' => $meEmail,
        ]);
        $raw = $stHist->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($raw as $r) {
            $sender = (string)($r['sender'] ?? '');
            $isMe = ($meCode !== '' && strcasecmp($sender, $meCode) === 0)
                || ($meEmail !== '' && strcasecmp($sender, $meEmail) === 0)
                || ($meUsername !== '' && strcasecmp($sender, $meUsername) === 0);
            $created = (string)($r['created_at'] ?? '');
            $ts = $created !== '' ? strtotime($created) : false;
            $items[] = [
                'id' => (int)($r['id'] ?? 0),
                'is_me' => $isMe,
                'text' => commerce_messaging_plain_text((string)($r['feedbackdata'] ?? '')),
                'created_at' => $created,
                'time_label' => $ts ? date('M d, Y h:i A', $ts) : '',
                'sender_name' => $isMe ? 'You' : $peerDisplay,
                'peer_name' => $peerDisplay,
                'is_read' => (int)($r['is_read'] ?? 0),
            ];
        }
        if ($mark === 1 && $items) {
            try {
                $stMark = $dbh->prepare("
                    UPDATE feedback
                    SET is_read = 1
                    WHERE channel = 'user_user'
                      AND is_read = 0
                      AND (receiver = :meCode OR receiver = :meEmail)
                      AND (sender = :peerCode OR sender = :peerEmail)
                ");
                $stMark->execute([
                    ':meCode' => $meCode,
                    ':meEmail' => $meEmail,
                    ':peerCode' => $peerFriendCode !== '' ? $peerFriendCode : $peerCode,
                    ':peerEmail' => $peerEmail,
                ]);
            } catch (Throwable $e) {
                // ignore
            }
        }
    }

    $lastId = $after;
    foreach ($items as $it) {
        $id = (int)($it['id'] ?? 0);
        if ($id > $lastId) {
            $lastId = $id;
        }
    }
    sbc_json([
        'ok' => true,
        'items' => $items,
        'last_id' => $lastId,
        'peer_name' => $peerDisplay,
        'peer_code' => $peerFriendCode !== '' ? $peerFriendCode : $peerCode,
        'buyer_id' => $peerId,
        'publisher_id' => $publisherUserId,
        'item_count' => count($items),
    ]);
} catch (Throwable $e) {
    sbc_json(['ok' => false, 'error' => 'Could not load messages.']);
}
