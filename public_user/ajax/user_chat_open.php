<?php
// Fast private-chat open: peer meta + history as JSON (no full page HTML).
declare(strict_types=1);
error_reporting(0);

require_once __DIR__ . '/../includes/session_user.php';
requireUserLogin();

require_once __DIR__ . '/../includes/user_identity.php';
require_once __DIR__ . '/../controller.php';
require_once __DIR__ . '/../includes/friend_system.php';
require_once __DIR__ . '/../includes/commerce_messaging.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

function j(array $arr): void {
    echo json_encode($arr);
    exit;
}

function open_parse_reply_payload(string $text): array {
    $text = (string)$text;
    if (!preg_match('/^\[\[reply:([A-Za-z0-9+\/=]+)\]\](.*)$/s', $text, $m)) {
        return ['text' => $text, 'reply_author' => '', 'reply_text' => '', 'reply_message_id' => 0];
    }
    $raw = base64_decode((string)$m[1], true);
    $payload = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($payload)) {
        return ['text' => $text, 'reply_author' => '', 'reply_text' => '', 'reply_message_id' => 0];
    }
    return [
        'text' => ltrim((string)($m[2] ?? '')),
        'reply_author' => trim((string)($payload['author'] ?? '')),
        'reply_text' => trim((string)($payload['text'] ?? '')),
        'reply_message_id' => (int)($payload['message_id'] ?? 0),
    ];
}

function open_column_exists(PDO $dbh, string $table, string $column): bool {
    try {
        $st = $dbh->prepare("SHOW COLUMNS FROM `{$table}` LIKE ?");
        $st->execute([$column]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function open_ensure_hidden_table(PDO $dbh): bool {
    try {
        $dbh->exec("
            CREATE TABLE IF NOT EXISTS user_chat_hidden_messages (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                feedback_id INT NOT NULL,
                hidden_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_user_chat_hidden_message (user_id, feedback_id),
                KEY idx_user_chat_hidden_user (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function open_ensure_reactions_table(PDO $dbh): bool {
    try {
        $dbh->exec("
            CREATE TABLE IF NOT EXISTS user_message_reactions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                feedback_id INT NOT NULL,
                user_id INT NOT NULL,
                reaction VARCHAR(32) NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_feedback_user (feedback_id, user_id),
                KEY idx_feedback_updated (feedback_id, updated_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function open_day_label(int $ts): string {
    if ($ts <= 0) return '';
    $today = date('Y-m-d');
    $d = date('Y-m-d', $ts);
    if ($d === $today) return 'Today';
    if ($d === date('Y-m-d', strtotime('-1 day'))) return 'Yesterday';
    return date('M j, Y', $ts);
}

function open_load_reactions(PDO $dbh, array $messageIds): array {
    $ids = array_values(array_unique(array_map('intval', $messageIds)));
    $ids = array_values(array_filter($ids, static function (int $id): bool {
        return $id > 0;
    }));
    if (!$ids || !open_ensure_reactions_table($dbh)) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    try {
        $st = $dbh->prepare("
            SELECT feedback_id, reaction
            FROM user_message_reactions
            WHERE feedback_id IN ($placeholders)
            ORDER BY updated_at ASC, id ASC
        ");
        $st->execute($ids);
        $map = [];
        foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
            $feedbackId = (int)($row['feedback_id'] ?? 0);
            $reaction = trim((string)($row['reaction'] ?? ''));
            if ($feedbackId <= 0 || $reaction === '') continue;
            if (!isset($map[$feedbackId])) $map[$feedbackId] = [];
            $map[$feedbackId][] = $reaction;
        }
        return $map;
    } catch (Throwable $e) {
        return [];
    }
}

$controller = new Controller();
$dbh = $controller->pdo();

$meId    = function_exists('userId') ? (int)userId() : (int)($_SESSION['user_id'] ?? 0);
$meCode  = trim((string)userFriendCode());
$meEmail = trim((string)userEmail());
$meName  = trim((string)myUserName());

if ($meId <= 0 || ($meCode === '' && $meEmail === '')) {
    j(['ok' => false, 'error' => 'Not logged in']);
}

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

$peerCode = strtoupper(trim((string)($_GET['peer'] ?? '')));
if ($peerCode === '' || !preg_match('/^[A-Z]{3}-[A-Z0-9]{4}-[A-Z0-9]{4}$/i', $peerCode)) {
    j(['ok' => false, 'error' => 'Friend code required']);
}
if (strcasecmp($peerCode, $meCode) === 0) {
    j(['ok' => false, 'error' => 'Invalid peer']);
}

try {
    $st = $dbh->prepare("
        SELECT id, email, friend_code, last_seen,
               COALESCE(NULLIF(name,''), NULLIF(username,''), friend_code) AS db_display
        FROM users
        WHERE UPPER(friend_code) = :c
          AND status = 1
        LIMIT 1
    ");
    $st->execute([':c' => $peerCode]);
    $peer = $st->fetch(PDO::FETCH_ASSOC);
    if (!$peer) {
        j(['ok' => false, 'error' => 'Friend not found']);
    }

    $peerId    = (int)($peer['id'] ?? 0);
    $peerEmail = (string)($peer['email'] ?? '');
    $peerCode  = strtoupper((string)($peer['friend_code'] ?? $peerCode));

    $areFriends = function_exists('fs_are_friends') && fs_are_friends($dbh, $meId, $peerId);
    $canCommerce = function_exists('commerce_can_dm_pair') && commerce_can_dm_pair($dbh, $meId, $peerId);
    if (!$areFriends && !$canCommerce) {
        j(['ok' => false, 'error' => 'Not allowed']);
    }

    $peerDisplay = $peerCode;
    try {
        $ns = $dbh->prepare("SELECT display_name FROM user_contacts WHERE owner_user_id = :me AND friend_user_id = :fid LIMIT 1");
        $ns->execute([':me' => $meId, ':fid' => $peerId]);
        $nick = trim((string)$ns->fetchColumn());
        if ($nick !== '') {
            $peerDisplay = $nick;
        } else {
            $dbDisp = trim((string)($peer['db_display'] ?? ''));
            if ($dbDisp !== '') $peerDisplay = $dbDisp;
        }
    } catch (Throwable $e) {}

    $lastSeen = (string)($peer['last_seen'] ?? '');
    $ageSec = null;
    $online = false;
    $onlineLabel = 'Offline';
    if ($lastSeen !== '') {
        $ts = strtotime($lastSeen);
        if ($ts) {
            $ageSec = max(0, time() - $ts);
            $online = ($ageSec <= 300);
            if ($online) {
                $onlineLabel = 'Online';
            } else {
                if ($ageSec < 60) $onlineLabel = $ageSec . ' seconds ago';
                elseif ($ageSec < 3600) $onlineLabel = (int)floor($ageSec / 60) . ' minutes ago';
                elseif ($ageSec < 86400) $onlineLabel = (int)floor($ageSec / 3600) . ' hours ago';
                else $onlineLabel = (int)floor($ageSec / 86400) . ' days ago';
            }
        }
    }

    $hasEditedAt = open_column_exists($dbh, 'feedback', 'edited_at');
    $hasDeletedForAll = open_column_exists($dbh, 'feedback', 'deleted_for_all');
    $hasHiddenTable = open_ensure_hidden_table($dbh);

    $sql = "
        SELECT f.id, f.sender, f.receiver, f.feedbackdata, f.created_at, f.is_read,
               f.attachment, f.attachment_type, f.attachment_original, f.attachment_url,
               " . ($hasEditedAt ? "COALESCE(f.edited_at, NULL)" : "NULL") . " AS edited_at,
               " . ($hasDeletedForAll ? "COALESCE(f.deleted_for_all, 0)" : "0") . " AS deleted_for_all,
               (f.attachment_blob IS NOT NULL) AS has_blob
        FROM feedback f
        " . ($hasHiddenTable ? "LEFT JOIN user_chat_hidden_messages uhm ON uhm.feedback_id = f.id AND uhm.user_id = :meId" : "") . "
        WHERE f.channel = 'user_user'
          " . ($hasHiddenTable ? "AND uhm.id IS NULL" : "") . "
          AND (
                (
                  (f.sender = :meCode OR f.sender = :meEmail)
                  AND (f.receiver = :peerCode OR f.receiver = :peerEmail)
                )
             OR (
                  (f.sender = :peerCode2 OR f.sender = :peerEmail2)
                  AND (f.receiver = :meCode2 OR f.receiver = :meEmail2)
                )
          )
        ORDER BY f.id ASC
        LIMIT 1000
    ";
    $params = [
        ':meCode'     => $meCode,
        ':meEmail'    => $meEmail,
        ':peerCode'   => $peerCode,
        ':peerEmail'  => $peerEmail,
        ':peerCode2'  => $peerCode,
        ':peerEmail2' => $peerEmail,
        ':meCode2'    => $meCode,
        ':meEmail2'   => $meEmail,
    ];
    if ($hasHiddenTable) {
        $params[':meId'] = $meId;
    }

    $q = $dbh->prepare($sql);
    $q->execute($params);
    $rows = $q->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $items = [];
    $lastId = 0;
    foreach ($rows as $r) {
        $id = (int)($r['id'] ?? 0);
        if ($id > $lastId) $lastId = $id;

        $sender = (string)($r['sender'] ?? '');
        $isMe = (strcasecmp($sender, $meCode) === 0) || ($meEmail !== '' && strcasecmp($sender, $meEmail) === 0);

        $created = (string)($r['created_at'] ?? '');
        $ts = $created ? strtotime($created) : 0;

        $attUrl  = (string)($r['attachment_url'] ?? '');
        $attType = (string)($r['attachment_type'] ?? '');
        $attOrig = (string)($r['attachment_original'] ?? '');
        $attFile = (string)($r['attachment'] ?? '');
        if ($attUrl === '' && $attFile !== '') {
            $attUrl = 'attachment/' . $attFile;
        }
        if ($attUrl === '' && (int)($r['has_blob'] ?? 0) === 1) {
            $attUrl = 'ajax/attachment_view.php?id=' . $id;
        }

        $replyBits = open_parse_reply_payload((string)($r['feedbackdata'] ?? ''));

        $items[] = [
            'id' => $id,
            'is_me' => $isMe,
            'sender_name' => $isMe ? (($meName !== '') ? $meName : 'You') : $peerDisplay,
            'peer_name' => $peerDisplay,
            'text' => (string)($replyBits['text'] ?? ''),
            'reply_author' => (string)($replyBits['reply_author'] ?? ''),
            'reply_text' => (string)($replyBits['reply_text'] ?? ''),
            'reply_message_id' => (int)($replyBits['reply_message_id'] ?? 0),
            'created_at' => $created,
            'time_label' => $ts ? date('M d, Y h:i A', $ts) : '',
            'day_key' => $ts ? date('Y-m-d', $ts) : '',
            'day_label' => open_day_label((int)$ts),
            'is_read' => (int)($r['is_read'] ?? 0),
            'edited_at' => (string)($r['edited_at'] ?? ''),
            'deleted_for_all' => (int)($r['deleted_for_all'] ?? 0),
            'attachment' => $attFile,
            'attachment_type' => $attType,
            'attachment_original' => $attOrig,
            'attachment_url' => $attUrl,
        ];
    }

    if ($items) {
        $reactionMap = open_load_reactions($dbh, array_column($items, 'id'));
        foreach ($items as &$item) {
            $item['reactions'] = $reactionMap[(int)($item['id'] ?? 0)] ?? [];
        }
        unset($item);
    }

    try {
        $mk = $dbh->prepare("
            UPDATE feedback
            SET is_read = 1, read_at = NOW()
            WHERE channel = 'user_user'
              AND (receiver = :meCode OR receiver = :meEmail)
              AND (sender = :peerCode OR sender = :peerEmail)
              AND is_read = 0
        ");
        $mk->execute([
            ':meCode' => $meCode,
            ':meEmail' => $meEmail,
            ':peerCode' => $peerCode,
            ':peerEmail' => $peerEmail,
        ]);
    } catch (Throwable $e) {}

    j([
        'ok' => true,
        'peer_code' => $peerCode,
        'peer_display' => $peerDisplay,
        'peer_avatar_url' => 'avatar.php?friend_code=' . rawurlencode($peerCode) . '&name=' . rawurlencode($peerDisplay),
        'online' => $online,
        'online_label' => $onlineLabel,
        'is_commerce' => (!$areFriends && $canCommerce),
        'last_id' => $lastId,
        'items' => $items,
    ]);
} catch (Throwable $e) {
    j(['ok' => false, 'error' => 'Server error']);
}
