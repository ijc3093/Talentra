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

org_require_manager();
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
try {
    $stMe = $dbh->prepare('SELECT friend_code, email FROM users WHERE id = :id AND status = 1 LIMIT 1');
    $stMe->execute([':id' => $publisherUserId]);
    $me = $stMe->fetch(PDO::FETCH_ASSOC) ?: [];
    $meCode = strtoupper(trim((string)($me['friend_code'] ?? '')));
    $meEmail = trim((string)($me['email'] ?? ''));
} catch (Throwable $e) {
    sbc_json(['ok' => false, 'error' => 'Could not resolve seller identity.']);
}
if ($meCode === '') {
    sbc_json(['ok' => false, 'error' => 'Seller friend code missing.']);
}

$mode = strtolower(trim((string)($_GET['mode'] ?? $_POST['mode'] ?? 'history')));
$peerCode = strtoupper(trim((string)($_GET['peer'] ?? $_POST['peer'] ?? $_POST['to'] ?? '')));
if ($peerCode === '' || !preg_match('/^[A-Z]{3}-[A-Z0-9]{4}-[A-Z0-9]{4}$/i', $peerCode)) {
    sbc_json(['ok' => false, 'error' => 'Select a customer to chat with.']);
}

$peerId = commerce_messaging_user_id_by_friend_code($dbh, $peerCode);
if ($peerId <= 0) {
    sbc_json(['ok' => false, 'error' => 'Customer not found.']);
}
if (!commerce_can_dm_pair($dbh, $publisherUserId, $peerId) && !fs_are_friends($dbh, $publisherUserId, $peerId)) {
    sbc_json(['ok' => false, 'error' => 'You can only message customers about products or orders.']);
}

$peerEmail = '';
$peerDisplay = $peerCode;
try {
    $stP = $dbh->prepare("
        SELECT email, COALESCE(NULLIF(TRIM(name), ''), NULLIF(TRIM(username), ''), friend_code) AS display
        FROM users WHERE id = :id LIMIT 1
    ");
    $stP->execute([':id' => $peerId]);
    $p = $stP->fetch(PDO::FETCH_ASSOC) ?: [];
    $peerEmail = trim((string)($p['email'] ?? ''));
    $peerDisplay = trim((string)($p['display'] ?? $peerCode));
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
            ':s' => $meCode,
            ':r' => $peerCode,
            ':msg' => $text,
        ]);
        $id = (int)$dbh->lastInsertId();
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

// history / poll
$after = (int)($_GET['after'] ?? $_POST['after'] ?? 0);
$mark = (int)($_GET['mark'] ?? $_POST['mark'] ?? 1);

try {
    $st = $dbh->prepare("
        SELECT f.id, f.sender, f.receiver, f.feedbackdata, f.created_at, f.is_read
        FROM feedback f
        WHERE f.channel = 'user_user'
          AND f.id > :after
          AND (
                ((f.sender = :meCode OR f.sender = :meEmail)
                 AND (f.receiver = :peerCode OR f.receiver = :peerEmail))
             OR ((f.sender = :peerCode2 OR f.sender = :peerEmail2)
                 AND (f.receiver = :meCode2 OR f.receiver = :meEmail2))
          )
        ORDER BY f.id ASC
        LIMIT 200
    ");
    $st->execute([
        ':after' => $after,
        ':meCode' => $meCode,
        ':meEmail' => $meEmail,
        ':peerCode' => $peerCode,
        ':peerEmail' => $peerEmail,
        ':peerCode2' => $peerCode,
        ':peerEmail2' => $peerEmail,
        ':meCode2' => $meCode,
        ':meEmail2' => $meEmail,
    ]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $items = [];
    $lastId = $after;
    foreach ($rows as $r) {
        $id = (int)($r['id'] ?? 0);
        if ($id > $lastId) {
            $lastId = $id;
        }
        $sender = (string)($r['sender'] ?? '');
        $isMe = (strcasecmp($sender, $meCode) === 0)
            || ($meEmail !== '' && strcasecmp($sender, $meEmail) === 0);
        $created = (string)($r['created_at'] ?? '');
        $ts = $created ? strtotime($created) : 0;
        $items[] = [
            'id' => $id,
            'is_me' => $isMe,
            'text' => (string)($r['feedbackdata'] ?? ''),
            'created_at' => $created,
            'time_label' => $ts ? date('M d, Y h:i A', $ts) : '',
            'sender_name' => $isMe ? 'You' : $peerDisplay,
            'peer_name' => $peerDisplay,
            'is_read' => (int)($r['is_read'] ?? 0),
        ];
    }

    if ($mark === 1) {
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
                ':peerCode' => $peerCode,
                ':peerEmail' => $peerEmail,
            ]);
        } catch (Throwable $e) {
            // ignore
        }
    }

    sbc_json(['ok' => true, 'items' => $items, 'last_id' => $lastId, 'peer_name' => $peerDisplay]);
} catch (Throwable $e) {
    sbc_json(['ok' => false, 'error' => 'Could not load messages.']);
}
