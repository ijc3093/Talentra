<?php
// /Business_only3/public_user/messages.php
declare(strict_types=1);

require_once __DIR__ . '/includes/session_user.php';
requireUserLogin();

require_once __DIR__ . '/controller.php';
require_once __DIR__ . '/includes/friend_system.php';
require_once __DIR__ . '/includes/group_video_call_lib.php';

error_reporting(E_ALL);
ini_set('display_errors', '0');

$controller = new Controller();
$dbh = $controller->pdo();

// ✅ bump MY last_seen whenever page loads
try {
    $sid = (int)($_SESSION['user_id'] ?? 0);
    if ($sid > 0) {
        $stSeen = $dbh->prepare("UPDATE users SET last_seen = NOW() WHERE id = :id LIMIT 1");
        $stSeen->execute([':id' => $sid]);
    }
} catch (Throwable $e) { /* ignore */ }

// ---------------- helpers ----------------
if (!function_exists('h')) {
    function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}

function fmt_time_short(string $dt): string {
    if ($dt === '') return '';
    $ts = strtotime($dt);
    return $ts ? date('h:i A', $ts) : '';
}

function fmt_time_full(string $dt): string {
    if ($dt === '') return '';
    $ts = strtotime($dt);
    return $ts ? date('M d, Y h:i A', $ts) : '';
}

function day_key(string $dt): string {
    $ts = strtotime($dt);
    return $ts ? date('Y-m-d', $ts) : '';
}

function day_label(string $dt): string {
    $ts = strtotime($dt);
    if (!$ts) return '';
    $today = date('Y-m-d');
    $d = date('Y-m-d', $ts);
    if ($d === $today) return 'Today';
    if ($d === date('Y-m-d', strtotime('-1 day'))) return 'Yesterday';
    return date('M j, Y', $ts);
}

function db_table_exists(PDO $dbh, string $table): bool {
    try {
        $st = $dbh->prepare("SHOW TABLES LIKE ?");
        $st->execute([$table]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function db_column_exists(PDO $dbh, string $table, string $column): bool {
    try {
        $st = $dbh->prepare("SHOW COLUMNS FROM `{$table}` LIKE ?");
        $st->execute([$column]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function ensure_user_chat_hidden_messages_table(PDO $dbh): bool {
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

function seconds_ago_label(int $sec): string {
    if ($sec < 0) $sec = 0;
    if ($sec < 60) return $sec . ' seconds ago';
    $m = (int) floor($sec / 60);
    if ($m < 60) return $m . ' minutes ago';
    $h = (int) floor($m / 60);
    if ($h < 24) return $h . ' hours ago';
    $d = (int) floor($h / 24);
    if ($d < 7) return $d . ' days ago';
    $w = (int) floor($d / 7);
    if ($w < 5) return $w . ' week' . ($w === 1 ? '' : 's') . ' ago';
    $mo = (int) floor($d / 30);
    if ($mo < 12) return $mo . ' month' . ($mo === 1 ? '' : 's') . ' ago';
    $y = (int) floor($d / 365);
    return $y . ' year' . ($y === 1 ? '' : 's') . ' ago';
}

function fmt_thread_time(string $dt): string {
    if ($dt === '') return '';
    $ts = strtotime($dt);
    if (!$ts) return '';
    $diff = max(0, time() - $ts);
    if ($diff < 60) return 'now';
    if ($diff < 3600) return (string)max(1, (int)floor($diff / 60)) . 'm';
    if ($diff < 86400) return (string)max(1, (int)floor($diff / 3600)) . 'h';
    if ($diff < 604800) return (string)max(1, (int)floor($diff / 86400)) . 'd';
    if ($diff < 2592000) return (string)max(1, (int)floor($diff / 604800)) . 'w';
    if ($diff < 31536000) return (string)max(1, (int)floor($diff / 2592000)) . 'mo';
    return (string)max(1, (int)floor($diff / 31536000)) . 'y';
}

function parse_reply_payload(string $text): array {
    $text = (string)$text;
    $replyAuthor = '';
    $replyText = '';
    $replyMessageId = 0;
    $remaining = $text;

    while (preg_match('/^\[\[reply:([A-Za-z0-9+\/=]+)\]\](.*)$/s', $remaining, $m)) {
        $raw = base64_decode((string)$m[1], true);
        $payload = is_string($raw) ? json_decode($raw, true) : null;
        if (is_array($payload) && $replyText === '') {
            $replyAuthor = trim((string)($payload['author'] ?? ''));
            $replyText = trim((string)($payload['text'] ?? ''));
            $replyMessageId = (int)($payload['message_id'] ?? 0);
        }
        $remaining = ltrim((string)($m[2] ?? ''));
    }

    return [
        'text' => $remaining,
        'reply_author' => $replyAuthor,
        'reply_text' => $replyText,
        'reply_message_id' => $replyMessageId,
    ];
}

function is_attachment_placeholder_text(string $text): bool {
    $text = trim((string)$text);
    if ($text === '') {
        return false;
    }
    return (bool)preg_match('/^\[(attachment|image|photo|file)\]$/i', $text)
        || (bool)preg_match('/^(attachment|image|photo|file)$/i', $text);
}

function call_event_possessive_name(string $name): string {
    $clean = trim($name);
    if ($clean === '') return 'their';
    return preg_match('/s$/i', $clean) ? $clean . "'" : $clean . "'s";
}

function call_event_display_text(string $text, bool $isMe): string {
    $trimmed = trim($text);
    $prefix = '[[MSB_CALL_EVENT:';
    if ($trimmed === '' || substr($trimmed, 0, strlen($prefix)) !== $prefix || substr($trimmed, -2) !== ']]') {
        return $text;
    }

    $json = substr($trimmed, strlen($prefix), -2);
    $payload = json_decode($json, true);
    if (!is_array($payload)) return $text;

    $action = strtolower(trim((string)($payload['action'] ?? '')));
    $actor = trim((string)($payload['actor'] ?? ''));
    $target = call_event_possessive_name((string)($payload['target'] ?? ''));
    if ($actor === '') $actor = 'They';

    if (in_array($action, ['deny', 'denied', 'decline', 'declined'], true)) {
        return $isMe ? ('You denied ' . $target . ' call') : ($actor . ' denied your call');
    }
    if (in_array($action, ['miss', 'missed', 'unavailable'], true)) {
        return $isMe ? ('You missed ' . $target . ' call') : ($actor . ' is not avalible yet. Please call me later');
    }
    if (in_array($action, ['end', 'ended'], true)) {
        return $isMe ? ('You ended ' . $target . ' call') : ($actor . ' ended your call');
    }

    return $text;
}

/** Online/Offline helper based on users.last_seen */
function online_info(?string $lastSeen, int $thresholdSeconds = 300, ?int $ageSeconds = null): array {
    if ($ageSeconds !== null) {
        $online = ($ageSeconds <= $thresholdSeconds);
        return [
            'online' => $online,
            'label' => ($online ? 'Online' : seconds_ago_label((int)$ageSeconds)),
            'last_seen_label' => (string)($lastSeen ?? ''),
            'age_seconds' => $ageSeconds,
        ];
    }

    $lastSeen = (string)($lastSeen ?? '');
    if ($lastSeen === '') {
        return ['online' => false, 'label' => 'Offline', 'last_seen_label' => '', 'age_seconds' => null];
    }

    $ts = strtotime($lastSeen);
    if (!$ts) {
        return ['online' => false, 'label' => 'Offline', 'last_seen_label' => '', 'age_seconds' => null];
    }

    $age = time() - $ts;
    $online = ($age <= $thresholdSeconds);

    return [
        'online' => $online,
        'label' => ($online ? 'Online' : seconds_ago_label((int)$age)),
        'last_seen_label' => date('M j, Y g:i A', $ts),
        'age_seconds' => $age,
    ];
}

/**
 * ✅ Avatar helpers (MATCH header.php)
 * - 2-letter initials (first + last)
 * - Stable color hashing (same key => same color across all pages)
 * - Same gradient style as header avatar
 */
function normalize_avatar_key(string $s): string {
    $s = trim($s);
    // collapse whitespace, DO NOT lowercase (must match JS/header)
    $s = preg_replace('/\s+/', ' ', $s);
    return $s;
}

function initials_from_name(string $name, string $fallback = 'U'): string {
    $name = trim(preg_replace('/\s+/', ' ', $name));
    if ($name === '') return strtoupper(mb_substr($fallback, 0, 2));
    $parts = explode(' ', $name);

    $a = strtoupper(mb_substr($parts[0] ?? '', 0, 1));
    $b = strtoupper(mb_substr($parts[1] ?? '', 0, 1));

    $out = $a . $b;
    if (trim($out) === '') {
        $out = strtoupper(mb_substr($name, 0, 2));
    }
    return $out;
}

function color_from_string(string $str): string {
    $colors = ['#4f46e5','#0ea5e9','#10b981','#f59e0b','#ef4444','#8b5cf6','#14b8a6','#f43f5e','#6366f1'];

    $str = normalize_avatar_key((string)$str);
    if ($str === '') $str = 'User';

    $hash = 0;
    $len = strlen($str);
    for ($i = 0; $i < $len; $i++) {
        $hash = ord($str[$i]) + (($hash << 5) - $hash);
        $hash = $hash & 0xFFFFFFFF;
    }
    $idx = abs((int)$hash) % count($colors);
    return $colors[$idx];
}

function avatar_gradient_style(string $baseHex): string {
    $baseHex = trim($baseHex) ?: '#4f46e5';
    return "background: radial-gradient(circle at 30% 25%, rgba(255,255,255,.35), rgba(255,255,255,0) 40%), linear-gradient(135deg, {$baseHex}, #111827);";
}

// ---------------- session identity ----------------
$meId    = function_exists('userId') ? (int)userId() : (int)($_SESSION['user_id'] ?? 0);
$meCode  = strtoupper(trim((string)(function_exists('userFriendCode') ? userFriendCode() : ($_SESSION['friend_code'] ?? ''))));
$meEmail = trim((string)(function_exists('userEmail') ? userEmail() : ($_SESSION['email'] ?? '')));
$meName  = trim((string)(function_exists('myUserName') ? myUserName() : ($_SESSION['name'] ?? '')));

if ($meCode === '' && $meEmail === '') {
    header("Location: index.php?session=reset");
    exit;
}

$meDisplay = $meName !== '' ? $meName : 'You';

$chatType = strtolower(trim((string)($_GET['chat_type'] ?? 'private')));
if (!in_array($chatType, ['private', 'group'], true)) {
    $chatType = 'private';
}
$isGroupChatView = ($chatType === 'group');

// ---------------- peer resolve ----------------
function resolvePeerByCode(PDO $dbh, int $meId, string $peerCode): array {
    $peerCode = strtoupper(trim($peerCode));
    if ($peerCode === '') return ['ok' => false];

    $st = $dbh->prepare("
        SELECT
            u.id, u.name, u.username, u.email, u.friend_code, u.status, u.last_seen,
            COALESCE(NULLIF(uc.display_name,''), u.friend_code) AS display
        FROM users u
        LEFT JOIN user_contacts uc
          ON uc.owner_user_id = :meId
         AND uc.friend_user_id = u.id
        WHERE UPPER(u.friend_code) = :c
        LIMIT 1
    ");
    $st->execute([':c' => $peerCode, ':meId' => $meId]);
    $u = $st->fetch(PDO::FETCH_ASSOC);

    if (!$u) return ['ok' => false];
    if ((int)($u['status'] ?? 1) !== 1) return ['ok' => false];

    return [
        'ok' => true,
        'peer' => $u,
        'peerEmail' => (string)$u['email'],
        'peerCode' => (string)$u['friend_code'],
        'peerDisplay' => (string)$u['display'],
    ];
}

// ---------------- thread list ----------------
function listThreads(PDO $dbh, int $meId, string $meCode, string $meEmail): array {
    $sql = "
        SELECT
            u.id AS peer_id,
            u.friend_code AS peer_key,
            u.last_seen AS peer_last_seen,
            TIMESTAMPDIFF(SECOND, u.last_seen, NOW()) AS peer_age_seconds,
            COALESCE(NULLIF(uc.display_name,''), u.friend_code) AS peer_display,
            CASE WHEN (uc.display_name IS NULL OR uc.display_name = '') THEN 1 ELSE 0 END AS is_unknown,

            (
                SELECT f2.feedbackdata
                FROM feedback f2
                WHERE f2.channel = 'user_user'
                  AND (
                        (f2.sender IN (?, ?) AND f2.receiver IN (u.friend_code, u.email))
                     OR (f2.receiver IN (?, ?) AND f2.sender IN (u.friend_code, u.email))
                  )
                ORDER BY f2.created_at DESC, f2.id DESC
                LIMIT 1
            ) AS last_message,

            (
                SELECT f2.sender
                FROM feedback f2
                WHERE f2.channel = 'user_user'
                  AND (
                        (f2.sender IN (?, ?) AND f2.receiver IN (u.friend_code, u.email))
                     OR (f2.receiver IN (?, ?) AND f2.sender IN (u.friend_code, u.email))
                  )
                ORDER BY f2.created_at DESC, f2.id DESC
                LIMIT 1
            ) AS last_sender,

            (
                SELECT f2.created_at
                FROM feedback f2
                WHERE f2.channel = 'user_user'
                  AND (
                        (f2.sender IN (?, ?) AND f2.receiver IN (u.friend_code, u.email))
                     OR (f2.receiver IN (?, ?) AND f2.sender IN (u.friend_code, u.email))
                  )
                ORDER BY f2.created_at DESC, f2.id DESC
                LIMIT 1
            ) AS last_time,

            (
                SELECT COUNT(*)
                FROM feedback f3
                WHERE f3.channel = 'user_user'
                  AND f3.is_read = 0
                  AND f3.receiver IN (?, ?)
                  AND f3.sender IN (u.friend_code, u.email)
            ) AS unread_count

        FROM users u
        LEFT JOIN user_contacts uc
          ON uc.owner_user_id = ?
         AND uc.friend_user_id = u.id
        WHERE u.status = 1
          AND UPPER(u.friend_code) <> ?
          AND EXISTS (
                SELECT 1
                FROM feedback f
                WHERE f.channel = 'user_user'
                  AND (
                        (f.sender IN (?, ?) AND f.receiver IN (u.friend_code, u.email))
                     OR (f.receiver IN (?, ?) AND f.sender IN (u.friend_code, u.email))
                  )
          )
        ORDER BY last_time DESC
    ";

    $params = [
        $meCode, $meEmail,  $meCode, $meEmail,
        $meCode, $meEmail,  $meCode, $meEmail,
        $meCode, $meEmail,  $meCode, $meEmail,
        $meCode, $meEmail,
        $meId,
        $meCode,
        $meCode, $meEmail,  $meCode, $meEmail,
    ];

    try {
        $st = $dbh->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

// ---------------- history + read ----------------
function loadHistory(PDO $dbh, int $meId, string $meCode, string $meEmail, string $peerCode, string $peerEmail): array {
    $hasEditedAt = db_column_exists($dbh, 'feedback', 'edited_at');
    $hasDeletedForAll = db_column_exists($dbh, 'feedback', 'deleted_for_all');
    $hasHiddenTable = ensure_user_chat_hidden_messages_table($dbh);

    $sql = "
        SELECT f.id, f.sender, f.receiver, f.feedbackdata,
               f.attachment, f.attachment_type, f.attachment_original, f.attachment_url,
               f.created_at, f.is_read,
               " . ($hasEditedAt ? "COALESCE(f.edited_at, NULL)" : "NULL") . " AS edited_at,
               " . ($hasDeletedForAll ? "COALESCE(f.deleted_for_all, 0)" : "0") . " AS deleted_for_all
        FROM feedback f
        " . ($hasHiddenTable ? "LEFT JOIN user_chat_hidden_messages uhm ON uhm.feedback_id = f.id AND uhm.user_id = :meId" : "") . "
        WHERE f.channel = 'user_user'
          " . ($hasHiddenTable ? "AND uhm.id IS NULL" : "") . "
          AND (
                (f.sender IN (?, ?) AND f.receiver IN (?, ?))
             OR (f.sender IN (?, ?) AND f.receiver IN (?, ?))
          )
        ORDER BY f.created_at ASC, f.id ASC
        LIMIT 1000
    ";
    $params = [];
    if ($hasHiddenTable) {
        $params[':meId'] = $meId;
    }
    $params[] = $meCode;
    $params[] = $meEmail;
    $params[] = $peerCode;
    $params[] = $peerEmail;
    $params[] = $peerCode;
    $params[] = $peerEmail;
    $params[] = $meCode;
    $params[] = $meEmail;
    try {
        $st = $dbh->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function ensure_message_reactions_table(PDO $dbh): bool {
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

function load_message_reactions(PDO $dbh, array $messageIds): array {
    $ids = array_values(array_unique(array_map('intval', $messageIds)));
    $ids = array_values(array_filter($ids, static function (int $id): bool {
        return $id > 0;
    }));
    if (!$ids || !ensure_message_reactions_table($dbh)) {
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
            if ($feedbackId <= 0 || $reaction === '') {
                continue;
            }
            if (!isset($map[$feedbackId])) {
                $map[$feedbackId] = [];
            }
            $map[$feedbackId][] = $reaction;
        }
        return $map;
    } catch (Throwable $e) {
        return [];
    }
}

function ensure_chat_group_message_reactions_table(PDO $dbh): bool {
    try {
        $dbh->exec("
            CREATE TABLE IF NOT EXISTS chat_group_message_reactions (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                group_message_id BIGINT UNSIGNED NOT NULL,
                user_id INT NOT NULL,
                reaction VARCHAR(32) NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_group_message_user (group_message_id, user_id),
                KEY idx_group_message_updated (group_message_id, updated_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function load_chat_group_message_reactions(PDO $dbh, array $messageIds): array {
    $ids = array_values(array_unique(array_map('intval', $messageIds)));
    $ids = array_values(array_filter($ids, static function (int $id): bool {
        return $id > 0;
    }));
    if (!$ids || !ensure_chat_group_message_reactions_table($dbh)) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    try {
        $st = $dbh->prepare("
            SELECT group_message_id, reaction
            FROM chat_group_message_reactions
            WHERE group_message_id IN ($placeholders)
            ORDER BY updated_at ASC, id ASC
        ");
        $st->execute($ids);
        $map = [];
        foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
            $messageId = (int)($row['group_message_id'] ?? 0);
            $reaction = trim((string)($row['reaction'] ?? ''));
            if ($messageId <= 0 || $reaction === '') {
                continue;
            }
            if (!isset($map[$messageId])) {
                $map[$messageId] = [];
            }
            $map[$messageId][] = $reaction;
        }
        return $map;
    } catch (Throwable $e) {
        return [];
    }
}

function markRead(PDO $dbh, string $meCode, string $meEmail, string $peerCode, string $peerEmail): void {
    try {
        $st = $dbh->prepare("
            UPDATE feedback
            SET is_read = 1, read_at = NOW()
            WHERE channel='user_user'
              AND receiver IN (?, ?)
              AND sender IN (?, ?)
              AND is_read = 0
        ");
        $st->execute([$meCode, $meEmail, $peerCode, $peerEmail]);
    } catch (Throwable $e) { /* ignore */ }
}

function ensure_chat_group_tables(PDO $dbh): bool {
    if (!db_table_exists($dbh, 'chat_groups')) return false;
    if (!db_table_exists($dbh, 'chat_group_members')) return false;
    if (!db_table_exists($dbh, 'chat_group_messages')) return false;
    ensure_chat_group_hidden_messages_table($dbh);

    // Older MySQL variants or permissions can make column introspection flaky.
    // At this point we already know the core tables exist, which is enough for the UI.
    return true;
}

function normalize_member_ids(array $ids, int $meId): array {
    $ids = array_values(array_unique(array_map('intval', $ids)));
    $ids = array_values(array_filter($ids, static function (int $id) use ($meId): bool {
        return $id > 0 && $id !== $meId;
    }));
    return $ids;
}

function list_group_friend_options(PDO $dbh, int $meId): array {
    try {
        $st = $dbh->prepare("
            SELECT
                u.id,
                u.friend_code,
                COALESCE(NULLIF(uc.display_name,''), NULLIF(u.name,''), NULLIF(u.username,''), u.friend_code) AS display_name
            FROM user_contacts uc
            JOIN users u
              ON u.id = uc.friend_user_id
            WHERE uc.owner_user_id = :me
              AND u.status = 1
            ORDER BY display_name ASC
        ");
        $st->execute([':me' => $meId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function list_chat_groups(PDO $dbh, int $meId): array {
    try {
        $st = $dbh->prepare("
            SELECT
                g.id,
                g.name,
                g.created_by_user_id,
                g.created_at,
                g.updated_at,
                gm.role AS my_role,
                (
                    SELECT COUNT(*)
                    FROM chat_group_members gm2
                    WHERE gm2.group_id = g.id AND gm2.left_at IS NULL
                ) AS member_count
            FROM chat_groups g
            JOIN chat_group_members gm
              ON gm.group_id = g.id
             AND gm.user_id = :me
             AND gm.left_at IS NULL
             AND gm.blocked_at IS NULL
            WHERE g.status = 1
            ORDER BY g.updated_at DESC, g.id DESC
        ");
        $st->execute([':me' => $meId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function load_chat_group(PDO $dbh, int $groupId, int $meId): ?array {
    if ($groupId <= 0) return null;
    try {
        $st = $dbh->prepare("
            SELECT
                g.id,
                g.name,
                g.created_by_user_id,
                g.created_at,
                g.updated_at,
                gm.role AS my_role
            FROM chat_groups g
            JOIN chat_group_members gm
              ON gm.group_id = g.id
             AND gm.user_id = :me
             AND gm.left_at IS NULL
             AND gm.blocked_at IS NULL
            WHERE g.id = :gid
              AND g.status = 1
            LIMIT 1
        ");
        $st->execute([':gid' => $groupId, ':me' => $meId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

function list_chat_group_members(PDO $dbh, int $groupId): array {
    if ($groupId <= 0) return [];
    try {
        $st = $dbh->prepare("
            SELECT
                gm.user_id,
                gm.role,
                gm.joined_at,
                u.friend_code,
                COALESCE(NULLIF(u.name,''), NULLIF(u.username,''), u.friend_code) AS display_name
            FROM chat_group_members gm
            JOIN users u
              ON u.id = gm.user_id
            WHERE gm.group_id = :gid
              AND gm.left_at IS NULL
              AND gm.blocked_at IS NULL
            ORDER BY FIELD(gm.role, 'owner', 'admin', 'member'), display_name ASC
        ");
        $st->execute([':gid' => $groupId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function ensure_chat_group_hidden_messages_table(PDO $dbh): bool {
    try {
        $dbh->exec("
            CREATE TABLE IF NOT EXISTS chat_group_hidden_messages (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                group_message_id BIGINT UNSIGNED NOT NULL,
                hidden_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_chat_group_hidden_message (user_id, group_message_id),
                KEY idx_chat_group_hidden_user (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function load_chat_group_messages(PDO $dbh, int $groupId, int $meId, int $limit = 250): array {
    if ($groupId <= 0) return [];
    $limit = max(20, min(500, $limit));
    $hasHiddenTable = ensure_chat_group_hidden_messages_table($dbh);
    try {
        $st = $dbh->prepare("
            SELECT
                gm.id,
                gm.group_id,
                gm.sender_user_id,
                gm.body,
                COALESCE(gm.attachment_url, '') AS attachment_url,
                COALESCE(gm.attachment_type, '') AS attachment_type,
                COALESCE(gm.attachment_original, '') AS attachment_original,
                " . (db_column_exists($dbh, 'chat_group_messages', 'edited_at') ? "COALESCE(gm.edited_at, NULL)" : "NULL") . " AS edited_at,
                " . (db_column_exists($dbh, 'chat_group_messages', 'deleted_for_all') ? "COALESCE(gm.deleted_for_all, 0)" : "0") . " AS deleted_for_all,
                gm.created_at,
                u.friend_code,
                COALESCE(NULLIF(u.name,''), NULLIF(u.username,''), u.friend_code) AS sender_name
            FROM chat_group_messages gm
            JOIN users u
              ON u.id = gm.sender_user_id
            " . ($hasHiddenTable ? "LEFT JOIN chat_group_hidden_messages ghm ON ghm.group_message_id = gm.id AND ghm.user_id = :me" : "") . "
            WHERE gm.group_id = :gid
              AND (
                    COALESCE(gm.body, '') <> ''
                 OR COALESCE(gm.attachment_url, '') <> ''
              )
              " . ($hasHiddenTable ? "AND ghm.id IS NULL" : "") . "
            ORDER BY gm.created_at ASC, gm.id ASC
            LIMIT {$limit}
        ");
        $params = [':gid' => $groupId];
        if ($hasHiddenTable) {
            $params[':me'] = $meId;
        }
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function create_chat_group(PDO $dbh, int $meId, string $name, array $memberIds): array {
    $name = trim(preg_replace('/\s+/', ' ', $name));
    if ($meId <= 0) return ['ok' => false, 'message' => 'Invalid user.'];
    if ($name === '') return ['ok' => false, 'message' => 'Enter a group name.'];
    if (mb_strlen($name) > 150) return ['ok' => false, 'message' => 'Group name is too long.'];
    $memberIds = normalize_member_ids($memberIds, $meId);

    try {
        $dbh->beginTransaction();
        $st = $dbh->prepare("
            INSERT INTO chat_groups (name, created_by_user_id, status, created_at, updated_at)
            VALUES (:name, :me, 1, NOW(), NOW())
        ");
        $st->execute([':name' => $name, ':me' => $meId]);
        $groupId = (int)$dbh->lastInsertId();

        $ins = $dbh->prepare("
            INSERT INTO chat_group_members (group_id, user_id, role, added_by_user_id, joined_at, left_at)
            VALUES (:gid, :uid, :role, :added_by, NOW(), NULL)
        ");
        $ins->execute([
            ':gid' => $groupId,
            ':uid' => $meId,
            ':role' => 'owner',
            ':added_by' => $meId,
        ]);

        foreach ($memberIds as $memberId) {
            if (!fs_are_friends($dbh, $meId, $memberId)) {
                continue;
            }
            $ins->execute([
                ':gid' => $groupId,
                ':uid' => $memberId,
                ':role' => 'member',
                ':added_by' => $meId,
            ]);
        }

        $dbh->commit();
        return ['ok' => true, 'group_id' => $groupId, 'message' => 'Group created successfully.'];
    } catch (Throwable $e) {
        if ($dbh->inTransaction()) $dbh->rollBack();
        return ['ok' => false, 'message' => 'Unable to create group right now.'];
    }
}

function rename_chat_group(PDO $dbh, int $groupId, int $meId, string $name): array {
    $name = trim(preg_replace('/\s+/', ' ', $name));
    if ($groupId <= 0) return ['ok' => false, 'message' => 'Select a group first.'];
    if ($name === '') return ['ok' => false, 'message' => 'Enter a group name.'];
    if (mb_strlen($name) > 150) return ['ok' => false, 'message' => 'Group name is too long.'];
    $group = load_chat_group($dbh, $groupId, $meId);
    if (!$group) return ['ok' => false, 'message' => 'Group not found.'];
    if ((string)($group['my_role'] ?? '') !== 'owner') {
        return ['ok' => false, 'message' => 'You cannot rename this group.'];
    }

    try {
        $st = $dbh->prepare("UPDATE chat_groups SET name = :name, updated_at = NOW() WHERE id = :gid LIMIT 1");
        $st->execute([':name' => $name, ':gid' => $groupId]);
        return ['ok' => true, 'message' => 'Group name updated.'];
    } catch (Throwable $e) {
        return ['ok' => false, 'message' => 'Unable to rename this group.'];
    }
}

function add_members_to_chat_group(PDO $dbh, int $groupId, int $meId, array $memberIds): array {
    if ($groupId <= 0) return ['ok' => false, 'message' => 'Select a group first.'];
    $group = load_chat_group($dbh, $groupId, $meId);
    if (!$group) return ['ok' => false, 'message' => 'Group not found.'];
    if ((string)($group['my_role'] ?? '') !== 'owner') {
        return ['ok' => false, 'message' => 'You cannot add members to this group.'];
    }

    $memberIds = normalize_member_ids($memberIds, $meId);
    if (!$memberIds) return ['ok' => false, 'message' => 'Select at least one peer to add.'];

    try {
        $existing = $dbh->prepare("
            SELECT blocked_at
            FROM chat_group_members
            WHERE group_id = :gid AND user_id = :uid
            LIMIT 1
        ");
        $ins = $dbh->prepare("
            INSERT INTO chat_group_members (group_id, user_id, role, added_by_user_id, joined_at, left_at)
            VALUES (:gid, :uid, 'member', :added_by, NOW(), NULL)
            ON DUPLICATE KEY UPDATE
                left_at = NULL,
                role = IF(role = 'owner', role, 'member'),
                added_by_user_id = VALUES(added_by_user_id)
        ");
        $added = 0;
        foreach ($memberIds as $memberId) {
            if (!fs_are_friends($dbh, $meId, $memberId)) {
                continue;
            }
            $existing->execute([':gid' => $groupId, ':uid' => $memberId]);
            $blockedAt = $existing->fetchColumn();
            if ($blockedAt !== false && $blockedAt !== null && (string)$blockedAt !== '') {
                continue;
            }
            $ins->execute([
                ':gid' => $groupId,
                ':uid' => $memberId,
                ':added_by' => $meId,
            ]);
            $added++;
        }
        if ($added > 0) {
            $dbh->prepare("UPDATE chat_groups SET updated_at = NOW() WHERE id = :gid LIMIT 1")->execute([':gid' => $groupId]);
        }
        return $added > 0
            ? ['ok' => true, 'message' => ($added === 1 ? '1 peer added to the group.' : $added . ' peers added to the group.')]
            : ['ok' => false, 'message' => 'No new peers were added.'];
    } catch (Throwable $e) {
        return ['ok' => false, 'message' => 'Unable to add peers right now.'];
    }
}

function send_chat_group_message(
    PDO $dbh,
    int $groupId,
    int $meId,
    string $body,
    ?array $file = null,
    int $replyMessageId = 0,
    string $replyPreviewAuthor = '',
    string $replyPreviewText = ''
): array {
    $body = trim($body);
    if ($groupId <= 0) return ['ok' => false, 'message' => 'Select a group first.'];
    $fileName = trim((string)($file['name'] ?? ''));
    $hasFile = ($fileName !== '' && (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE);
    if ($body === '' && !$hasFile) return ['ok' => false, 'message' => 'Type a message or choose media before sending.'];
    if (mb_strlen($body) > 5000) return ['ok' => false, 'message' => 'Message is too long.'];
    $group = load_chat_group($dbh, $groupId, $meId);
    if (!$group) return ['ok' => false, 'message' => 'Group not found.'];

    $storedBody = $body;
    $replyPreviewAuthor = trim($replyPreviewAuthor);
    $replyPreviewText = trim($replyPreviewText);
    if ($replyPreviewText !== '') {
        $replyPayload = base64_encode((string)json_encode([
            'message_id' => $replyMessageId,
            'author' => $replyPreviewAuthor !== '' ? $replyPreviewAuthor : 'message',
            'text' => $replyPreviewText,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $storedBody = '[[reply:' . $replyPayload . ']]' . $body;
    }

    $attachmentUrl = '';
    $attachmentType = '';
    $attachmentOriginal = '';
    if ($hasFile) {
        if ((int)($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'message' => 'Media upload failed.'];
        }
        $tmp = (string)($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            return ['ok' => false, 'message' => 'Invalid media upload.'];
        }
        $attachmentOriginal = $fileName;
        $attachmentType = trim((string)($file['type'] ?? ''));
        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        $allowed = ['jpg','jpeg','png','gif','webp','mp4','webm','ogg','mov','pdf'];
        if (!in_array($ext, $allowed, true)) {
            return ['ok' => false, 'message' => 'Unsupported media type.'];
        }
        $uploadDir = __DIR__ . '/attachment/';
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
            return ['ok' => false, 'message' => 'Upload folder is not ready.'];
        }
        $safeBase = preg_replace('/[^A-Za-z0-9_-]+/', '-', pathinfo($fileName, PATHINFO_FILENAME));
        $safeBase = trim((string)$safeBase, '-');
        if ($safeBase === '') $safeBase = 'group-media';
        $finalName = strtolower($safeBase . '-' . time() . '-' . mt_rand(1000, 9999) . '.' . $ext);
        if (!move_uploaded_file($tmp, $uploadDir . $finalName)) {
            return ['ok' => false, 'message' => 'Could not save uploaded media.'];
        }
        $attachmentUrl = 'attachment/' . $finalName;
    }

    try {
        if ($attachmentUrl !== '') {
            $st = $dbh->prepare("
                INSERT INTO chat_group_messages (group_id, sender_user_id, body, attachment_url, attachment_type, attachment_original, created_at)
                VALUES (:gid, :uid, :body, :attachment_url, :attachment_type, :attachment_original, NOW())
            ");
            $st->execute([
                ':gid' => $groupId,
                ':uid' => $meId,
                ':body' => $storedBody,
                ':attachment_url' => $attachmentUrl,
                ':attachment_type' => $attachmentType,
                ':attachment_original' => $attachmentOriginal,
            ]);
        } else {
            $st = $dbh->prepare("
                INSERT INTO chat_group_messages (group_id, sender_user_id, body, created_at)
                VALUES (:gid, :uid, :body, NOW())
            ");
            $st->execute([
                ':gid' => $groupId,
                ':uid' => $meId,
                ':body' => $storedBody,
            ]);
        }
        $messageId = (int)$dbh->lastInsertId();
        $dbh->prepare("UPDATE chat_groups SET updated_at = NOW() WHERE id = :gid LIMIT 1")->execute([':gid' => $groupId]);
        if ($messageId > 0) {
            $stMessage = $dbh->prepare("
                SELECT
                    gm.id,
                    gm.body,
                    gm.created_at,
                    COALESCE(NULLIF(u.name,''), NULLIF(u.username,''), u.friend_code) AS sender_name,
                    COALESCE(u.friend_code, '') AS friend_code
                FROM chat_group_messages gm
                JOIN users u
                  ON u.id = gm.sender_user_id
                WHERE gm.id = :mid
                LIMIT 1
            ");
            $stMessage->execute([':mid' => $messageId]);
            $messageRow = $stMessage->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($messageRow) {
                $ts = strtotime((string)($messageRow['created_at'] ?? '')) ?: time();
                broadcast_group_call_chat_message($dbh, $groupId, $meId, [
                    'id' => (int)($messageRow['id'] ?? 0),
                    'is_me' => false,
                    'text' => (string)($body !== '' ? $body : ($messageRow['body'] ?? '')),
                    'created_at' => (string)($messageRow['created_at'] ?? ''),
                    'time_label' => date('M d, Y h:i A', $ts),
                    'day_key' => date('Y-m-d', $ts),
                    'day_label' => date('M j, Y', $ts),
                    'sender_name' => (string)($messageRow['sender_name'] ?? ''),
                    'friend_code' => (string)($messageRow['friend_code'] ?? ''),
                ]);
            }
        }
        return ['ok' => true, 'message' => 'Message sent.'];
    } catch (Throwable $e) {
        if ($attachmentUrl !== '' && stripos((string)$e->getMessage(), 'attachment_') !== false) {
            return ['ok' => false, 'message' => 'Group media columns are missing or not readable in chat_group_messages.'];
        }
        return ['ok' => false, 'message' => 'Unable to send the message right now.'];
    }
}

function edit_chat_group_message(PDO $dbh, int $groupId, int $meId, int $messageId, string $body): array {
    $body = trim($body);
    if ($groupId <= 0 || $messageId <= 0) return ['ok' => false, 'message' => 'Message not found.'];
    if ($body === '') return ['ok' => false, 'message' => 'Message text cannot be empty.'];
    try {
        $hasEditedAt = db_column_exists($dbh, 'chat_group_messages', 'edited_at');
        $st = $dbh->prepare("
            UPDATE chat_group_messages
            SET body = :body" . ($hasEditedAt ? ", edited_at = NOW()" : "") . "
            WHERE id = :mid
              AND group_id = :gid
              AND sender_user_id = :uid
            LIMIT 1
        ");
        $st->execute([':body' => $body, ':mid' => $messageId, ':gid' => $groupId, ':uid' => $meId]);
        return $st->rowCount() > 0
            ? ['ok' => true, 'message' => 'Message updated.']
            : ['ok' => false, 'message' => 'Only your own group message can be edited.'];
    } catch (Throwable $e) {
        return ['ok' => false, 'message' => 'Unable to edit this group message.'];
    }
}

function unsend_chat_group_message_for_me(PDO $dbh, int $groupId, int $meId, int $messageId): array {
    if ($groupId <= 0 || $messageId <= 0) return ['ok' => false, 'message' => 'Message not found.'];
    if (!ensure_chat_group_hidden_messages_table($dbh)) {
        return ['ok' => false, 'message' => 'Hidden message storage is unavailable.'];
    }
    $group = load_chat_group($dbh, $groupId, $meId);
    if (!$group) {
        return ['ok' => false, 'message' => 'Group not found.'];
    }
    try {
        $st = $dbh->prepare("
            INSERT INTO chat_group_hidden_messages (user_id, group_message_id, hidden_at)
            SELECT :uid, gm.id, NOW()
            FROM chat_group_messages gm
            WHERE gm.id = :mid
              AND gm.group_id = :gid
            ON DUPLICATE KEY UPDATE hidden_at = hidden_at
        ");
        $st->execute([':uid' => $meId, ':mid' => $messageId, ':gid' => $groupId]);
        return $st->rowCount() > 0
            ? ['ok' => true, 'message' => 'Message hidden for you.']
            : ['ok' => false, 'message' => 'Group message could not be hidden.'];
    } catch (Throwable $e) {
        return ['ok' => false, 'message' => 'Unable to hide this group message.'];
    }
}

function unsend_chat_group_message_for_everyone(PDO $dbh, int $groupId, int $meId, int $messageId): array {
    if ($groupId <= 0 || $messageId <= 0) return ['ok' => false, 'message' => 'Message not found.'];
    try {
        $hasDeletedForAll = db_column_exists($dbh, 'chat_group_messages', 'deleted_for_all');
        $hasEditedAt = db_column_exists($dbh, 'chat_group_messages', 'edited_at');
        $setParts = [
            "body = ''",
            "attachment_url = NULL",
            "attachment_type = NULL",
            "attachment_original = NULL"
        ];
        if ($hasDeletedForAll) {
            $setParts[] = "deleted_for_all = 1";
        }
        if ($hasEditedAt) {
            $setParts[] = "edited_at = NULL";
        }
        $st = $dbh->prepare("
            UPDATE chat_group_messages
            SET " . implode(", ", $setParts) . "
            WHERE id = :mid
              AND group_id = :gid
              AND sender_user_id = :uid
            LIMIT 1
        ");
        $st->execute([':mid' => $messageId, ':gid' => $groupId, ':uid' => $meId]);
        return $st->rowCount() > 0
            ? ['ok' => true, 'message' => 'Message unsent for everyone.']
            : ['ok' => false, 'message' => 'Only your own group message can be unsent for everyone.'];
    } catch (Throwable $e) {
        return ['ok' => false, 'message' => 'Unable to unsend this group message.'];
    }
}

function delete_chat_group(PDO $dbh, int $groupId, int $meId): array {
    $group = load_chat_group($dbh, $groupId, $meId);
    if (!$group) return ['ok' => false, 'message' => 'Group not found.'];
    if ((string)($group['my_role'] ?? '') !== 'owner') {
        return ['ok' => false, 'message' => 'Only the group owner can delete this group.'];
    }
    try {
        $st = $dbh->prepare("UPDATE chat_groups SET status = 0, updated_at = NOW() WHERE id = :gid LIMIT 1");
        $st->execute([':gid' => $groupId]);
        return ['ok' => true, 'message' => 'Group deleted.'];
    } catch (Throwable $e) {
        return ['ok' => false, 'message' => 'Unable to delete this group.'];
    }
}

function remove_chat_group_member(PDO $dbh, int $groupId, int $ownerId, int $memberId): array {
    $group = load_chat_group($dbh, $groupId, $ownerId);
    if (!$group) return ['ok' => false, 'message' => 'Group not found.'];
    if ((string)($group['my_role'] ?? '') !== 'owner') {
        return ['ok' => false, 'message' => 'Only the group owner can remove members.'];
    }
    if ($memberId <= 0 || $memberId === $ownerId) {
        return ['ok' => false, 'message' => 'Invalid member.'];
    }
    try {
        $st = $dbh->prepare("
            UPDATE chat_group_members
            SET left_at = NOW()
            WHERE group_id = :gid
              AND user_id = :uid
              AND role <> 'owner'
              AND blocked_at IS NULL
              AND left_at IS NULL
            LIMIT 1
        ");
        $st->execute([':gid' => $groupId, ':uid' => $memberId]);
        return $st->rowCount() > 0
            ? ['ok' => true, 'message' => 'Member removed from the group.']
            : ['ok' => false, 'message' => 'Member could not be removed.'];
    } catch (Throwable $e) {
        return ['ok' => false, 'message' => 'Unable to remove this member.'];
    }
}

function block_chat_group_member(PDO $dbh, int $groupId, int $ownerId, int $memberId): array {
    $group = load_chat_group($dbh, $groupId, $ownerId);
    if (!$group) return ['ok' => false, 'message' => 'Group not found.'];
    if ((string)($group['my_role'] ?? '') !== 'owner') {
        return ['ok' => false, 'message' => 'Only the group owner can block members.'];
    }
    if ($memberId <= 0 || $memberId === $ownerId) {
        return ['ok' => false, 'message' => 'Invalid member.'];
    }
    try {
        $st = $dbh->prepare("
            UPDATE chat_group_members
            SET left_at = NOW(), blocked_at = NOW(), blocked_by_user_id = :owner
            WHERE group_id = :gid
              AND user_id = :uid
              AND role <> 'owner'
            LIMIT 1
        ");
        $st->execute([':gid' => $groupId, ':uid' => $memberId, ':owner' => $ownerId]);
        return $st->rowCount() > 0
            ? ['ok' => true, 'message' => 'Member blocked from the group.']
            : ['ok' => false, 'message' => 'Member could not be blocked.'];
    } catch (Throwable $e) {
        return ['ok' => false, 'message' => 'Unable to block this member.'];
    }
}

function list_chat_group_blocked_user_ids(PDO $dbh, int $groupId): array {
    if ($groupId <= 0) return [];
    try {
        $st = $dbh->prepare("
            SELECT user_id
            FROM chat_group_members
            WHERE group_id = :gid
              AND blocked_at IS NOT NULL
        ");
        $st->execute([':gid' => $groupId]);
        return array_values(array_unique(array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN) ?: [])));
    } catch (Throwable $e) {
        return [];
    }
}

// ---------------- selected peer ----------------
/**
 * ✅ Peer selection supports BOTH:
 *   1) messages.php?peer=FRIEND_CODE   (original)
 *   2) messages.php?id=USER_ID        (new: open chat by user id)
 *   3) messages.php?peer_id=USER_ID   (alias)
 *
 * This makes dropdown "Message" buttons in feed.php able to deep-link by user id.
 */
$peerRaw = strtoupper(trim((string)($_GET['peer'] ?? '')));

// ✅ If peer code not provided, allow numeric user id -> friend_code lookup
if ($peerRaw === '') {
    $peerId = (int)($_GET['id'] ?? ($_GET['peer_id'] ?? 0));
    if ($peerId > 0) {
        try {
            $st = $dbh->prepare("SELECT friend_code FROM users WHERE id = :id LIMIT 1");
            $st->execute([':id' => $peerId]);
            $fc = (string)($st->fetchColumn() ?: '');
            if ($fc !== '') {
                $peerRaw = strtoupper(trim($fc));
            }
        } catch (Throwable $e) {
            // ignore (keep $peerRaw empty)
        }
    }
}
$peerRow = null;
$peerCode = '';
$peerEmail = '';
$peerDisplay = '';
$peerOnlineInfo = ['online' => false, 'label' => 'Offline'];

if ($peerRaw !== '') {
    $pr = resolvePeerByCode($dbh, $meId, $peerRaw);
    if (!empty($pr['ok'])) {
        $peerRow     = $pr['peer'];
        $peerCode    = (string)$pr['peerCode'];
        $peerEmail   = (string)$pr['peerEmail'];
        $peerDisplay = (string)$pr['peerDisplay'];

        if (strtoupper($peerCode) === strtoupper($meCode)) {
            $peerRow = null; $peerCode=''; $peerEmail=''; $peerDisplay='';
        }

        if ($peerRow && !fs_are_friends($dbh, $meId, (int)($peerRow['id'] ?? 0))) {
            $peerRow = null; $peerCode=''; $peerEmail=''; $peerDisplay='';
        }

        if ($peerRow) {
            $peerOnlineInfo = online_info((string)($peerRow['last_seen'] ?? ''), 300, null);
        }
    }
}

// ---------------- page data ----------------
$groupFeatureReady = $isGroupChatView;
$groupNoticeType = strtolower(trim((string)($_GET['group_notice_type'] ?? '')));
$groupNotice = trim((string)($_GET['group_notice'] ?? ''));
if (!in_array($groupNoticeType, ['success', 'error'], true)) {
    $groupNoticeType = '';
}

if ($isGroupChatView && $_SERVER['REQUEST_METHOD'] === 'POST' && $groupFeatureReady) {
    $groupAction = trim((string)($_POST['group_action'] ?? ''));
    $redirectGroupId = (int)($_POST['group_id'] ?? 0);
    $result = ['ok' => false, 'message' => 'Unknown action.'];
    $groupSilentSuccess = false;

    if ($groupAction === 'create_group') {
        $result = create_chat_group(
            $dbh,
            $meId,
            (string)($_POST['group_name'] ?? ''),
            isset($_POST['member_ids']) && is_array($_POST['member_ids']) ? $_POST['member_ids'] : []
        );
        if (!empty($result['group_id'])) {
            $redirectGroupId = (int)$result['group_id'];
        }
    } elseif ($groupAction === 'rename_group') {
        $result = rename_chat_group($dbh, $redirectGroupId, $meId, (string)($_POST['group_name'] ?? ''));
    } elseif ($groupAction === 'add_group_members') {
        $result = add_members_to_chat_group(
            $dbh,
            $redirectGroupId,
            $meId,
            isset($_POST['member_ids']) && is_array($_POST['member_ids']) ? $_POST['member_ids'] : []
        );
    } elseif ($groupAction === 'send_group_message') {
        $result = send_chat_group_message(
            $dbh,
            $redirectGroupId,
            $meId,
            (string)($_POST['group_message'] ?? ''),
            $_FILES['attachment'] ?? null,
            (int)($_POST['reply_message_id'] ?? 0),
            (string)($_POST['reply_preview_author'] ?? ''),
            (string)($_POST['reply_preview_text'] ?? '')
        );
        if (!empty($result['ok'])) {
            header('Location: messages.php?chat_type=group&group_id=' . $redirectGroupId);
            exit;
        }
    } elseif ($groupAction === 'edit_group_message') {
        $result = edit_chat_group_message(
            $dbh,
            $redirectGroupId,
            $meId,
            (int)($_POST['group_message_id'] ?? 0),
            (string)($_POST['group_message_text'] ?? '')
        );
        $groupSilentSuccess = true;
    } elseif ($groupAction === 'unsend_group_message_me') {
        $result = unsend_chat_group_message_for_me(
            $dbh,
            $redirectGroupId,
            $meId,
            (int)($_POST['group_message_id'] ?? 0)
        );
        $groupSilentSuccess = true;
    } elseif ($groupAction === 'unsend_group_message_everyone') {
        $result = unsend_chat_group_message_for_everyone(
            $dbh,
            $redirectGroupId,
            $meId,
            (int)($_POST['group_message_id'] ?? 0)
        );
        $groupSilentSuccess = true;
    } elseif ($groupAction === 'delete_group') {
        $result = delete_chat_group($dbh, $redirectGroupId, $meId);
        if (!empty($result['ok'])) {
            $redirectGroupId = 0;
        }
    } elseif ($groupAction === 'remove_group_member') {
        $result = remove_chat_group_member($dbh, $redirectGroupId, $meId, (int)($_POST['member_user_id'] ?? 0));
    } elseif ($groupAction === 'block_group_member') {
        $result = block_chat_group_member($dbh, $redirectGroupId, $meId, (int)($_POST['member_user_id'] ?? 0));
    }

    $location = 'messages.php?chat_type=group';
    if ($redirectGroupId > 0) {
        $location .= '&group_id=' . $redirectGroupId;
    }
    if (!$groupSilentSuccess || empty($result['ok'])) {
        $location .= '&group_notice_type=' . urlencode(!empty($result['ok']) ? 'success' : 'error');
        $location .= '&group_notice=' . urlencode((string)($result['message'] ?? 'Update completed.'));
    }
    header('Location: ' . $location);
    exit;
}

$threads = listThreads($dbh, $meId, $meCode, $meEmail);
$groupId = $isGroupChatView ? (int)($_GET['group_id'] ?? 0) : 0;
$groupThreads = $groupFeatureReady ? list_chat_groups($dbh, $meId) : [];
if ($isGroupChatView && $groupId <= 0 && !empty($groupThreads)) {
    $groupId = (int)($groupThreads[0]['id'] ?? 0);
}
$selectedGroup = ($groupFeatureReady && $groupId > 0) ? load_chat_group($dbh, $groupId, $meId) : null;
$groupMembers = ($groupFeatureReady && $selectedGroup) ? list_chat_group_members($dbh, (int)$selectedGroup['id']) : [];
$groupMessages = ($groupFeatureReady && $selectedGroup) ? load_chat_group_messages($dbh, (int)$selectedGroup['id'], $meId) : [];
if (!empty($groupMessages)) {
    $groupReactionMap = load_chat_group_message_reactions($dbh, array_column($groupMessages, 'id'));
    foreach ($groupMessages as &$groupMessageRow) {
        $groupMessageRow['reactions'] = $groupReactionMap[(int)($groupMessageRow['id'] ?? 0)] ?? [];
    }
    unset($groupMessageRow);
}
$friendOptions = $groupFeatureReady ? list_group_friend_options($dbh, $meId) : [];
$groupMemberUserIds = array_values(array_unique(array_map('intval', array_column($groupMembers, 'user_id'))));
$groupBlockedUserIds = ($groupFeatureReady && $selectedGroup) ? list_chat_group_blocked_user_ids($dbh, (int)$selectedGroup['id']) : [];
$groupAddableFriends = array_values(array_filter($friendOptions, static function (array $friend) use ($groupMemberUserIds): bool {
    $friendId = (int)($friend['id'] ?? 0);
    return $friendId > 0 && !in_array($friendId, $groupMemberUserIds, true);
}));
$groupAddableFriends = array_values(array_filter($groupAddableFriends, static function (array $friend) use ($groupBlockedUserIds): bool {
    $friendId = (int)($friend['id'] ?? 0);
    return $friendId > 0 && !in_array($friendId, $groupBlockedUserIds, true);
}));

$messages = [];
$lastId = 0;
if ($peerRow && $peerCode !== '') {
    markRead($dbh, $meCode, $meEmail, $peerCode, $peerEmail);
    $messages = loadHistory($dbh, $meId, $meCode, $meEmail, $peerCode, $peerEmail);
    if (!empty($messages)) {
        $reactionMap = load_message_reactions($dbh, array_column($messages, 'id'));
        foreach ($messages as &$messageRow) {
            $messageRow['reactions'] = $reactionMap[(int)($messageRow['id'] ?? 0)] ?? [];
        }
        unset($messageRow);
    }
    if (!empty($messages)) {
        $last = end($messages);
        $lastId = (int)($last['id'] ?? 0);
        reset($messages);
    }
}

if ($isGroupChatView) {
    $peerRow = null;
    $peerCode = '';
    $peerEmail = '';
    $peerDisplay = '';
    $peerOnlineInfo = ['online' => false, 'label' => 'Offline'];
    $messages = [];
    $lastId = 0;
}

$privateChatUrl = 'messages.php' . ($peerRaw !== '' ? '?peer=' . urlencode($peerRaw) : '');
$groupChatUrl = 'messages.php?chat_type=group';

$attachmentItems = [];
if (!empty($messages)) {
    foreach (array_reverse($messages) as $m) {
        $attUrl = trim((string)($m['attachment_url'] ?? ''));
        if ($attUrl === '' || (int)($m['deleted_for_all'] ?? 0) === 1) {
            continue;
        }
        $orig = trim((string)($m['attachment_original'] ?? ''));
        if ($orig === '') {
            $orig = basename((string)(parse_url($attUrl, PHP_URL_PATH) ?? $attUrl));
        }
        $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
        $attachmentItems[] = [
            'url' => $attUrl,
            'name' => $orig !== '' ? $orig : 'Attachment',
            'ext' => $ext,
            'time' => (string)($m['created_at'] ?? ''),
        ];
        if (count($attachmentItems) >= 4) {
            break;
        }
    }
}

$callSidebarMessages = [];
if (!empty($messages)) {
    $recentMessages = array_slice($messages, -8);
    foreach ($recentMessages as $sidebarMessage) {
        $sidebarId = (int)($sidebarMessage['id'] ?? 0);
        $sidebarSender = strtoupper((string)($sidebarMessage['sender'] ?? ''));
        $sidebarIsMe = (
            $sidebarSender !== '' && (
                strtoupper($meCode) === $sidebarSender ||
                ($meEmail !== '' && strtoupper($meEmail) === $sidebarSender)
            )
        );
        $sidebarReply = parse_reply_payload((string)($sidebarMessage['feedbackdata'] ?? ''));
        $sidebarText = trim(call_event_display_text((string)($sidebarReply['text'] ?? ''), $sidebarIsMe));
        $sidebarDeleted = (int)($sidebarMessage['deleted_for_all'] ?? 0) === 1;
        $sidebarAttachmentUrl = trim((string)($sidebarMessage['attachment_url'] ?? ''));
        $sidebarAttachmentOriginal = trim((string)($sidebarMessage['attachment_original'] ?? ''));
        if ($sidebarDeleted) {
            $sidebarText = $sidebarIsMe ? 'You unsent a message.' : 'This message was unsent.';
        } elseif ($sidebarText === '' && $sidebarAttachmentUrl !== '') {
            $sidebarText = $sidebarAttachmentOriginal !== '' ? 'Attachment: ' . $sidebarAttachmentOriginal : 'Attachment';
        }
        if ($sidebarText === '') {
            continue;
        }
        $callSidebarMessages[] = [
            'id' => $sidebarId,
            'is_me' => $sidebarIsMe,
            'text' => $sidebarText,
            'meta' => $sidebarIsMe ? 'You' : $peerDisplay,
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
  <title>Messages</title>
  <script>
    (function () {
      var MODE_KEY = 'theme_mode';
      var AUTO_KEY = 'theme_auto_enabled';
      var MANUAL_KEY = 'theme_manual_mode';
      function prefersDark(){ try { return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches; } catch(e){ return false; } }
      function supportsPrefersColorScheme(){ try { return !!(window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').media !== 'not all'); } catch(e){ return false; } }
      function isNight(){ var h = new Date().getHours(); return (h >= 20 || h < 6); }
      var legacy = 'auto', autoRaw = null, manual = 'dark';
      try {
        legacy = localStorage.getItem(MODE_KEY) || 'auto';
        autoRaw = localStorage.getItem(AUTO_KEY);
        manual = localStorage.getItem(MANUAL_KEY) || '';
      } catch(e){}
      if (manual !== 'light' && manual !== 'dark') manual = (legacy === 'light' || legacy === 'dark') ? legacy : 'dark';
      var autoEnabled = (autoRaw === '1' || autoRaw === '0') ? (autoRaw === '1') : (legacy === 'auto');
      var on = autoEnabled ? (supportsPrefersColorScheme() ? prefersDark() : isNight()) : (manual === 'dark');
      if (on) {
        document.documentElement.classList.add('dark-auto');
        document.addEventListener('DOMContentLoaded', function(){
          document.body.classList.add('dark-auto');
        }, { once:true });
      } else {
        document.documentElement.classList.remove('dark-auto');
      }
      document.documentElement.setAttribute('data-theme', on ? 'dark' : 'light');
      document.documentElement.style.colorScheme = on ? 'dark' : 'light';
    })();
  </script>

  <link href="./lib/font-awesome/css/font-awesome.css" rel="stylesheet">
  <link href="./lib/Ionicons/css/ionicons.css" rel="stylesheet">
  <link href="./lib/perfect-scrollbar/css/perfect-scrollbar.css" rel="stylesheet">
  
  <!-- css -->
  <link rel="stylesheet" href="./css/dark-auto.css">
  <script src="./js/dark-auto.js" defer></script>
  <link rel="stylesheet" href="./css/shamcey.css">

  <!-- Script -->
  <script src="./lib/jquery/jquery.js"></script>
  <script src="./lib/popper.js/popper.js"></script>
  <script src="./lib/bootstrap/bootstrap.js"></script>
  <script src="./lib/perfect-scrollbar/js/perfect-scrollbar.jquery.js"></script>
  <script src="./js/shamcey.js"></script>
  <style>
    :root{
      --bg:#f4f6fb;
      --card:#fff;
      --text:#0f172a;
      --muted:rgba(17,24,39,.65);
      --border:rgba(17,24,39,.10);
      --brand:#1e40af;
      --brand2:#2563eb;
      --shadow:0 12px 40px rgba(15,23,42,.08);
      --shadow2:0 10px 28px rgba(15,23,42,.10);
      --radius:16px;
    }

    html,body{height:100%;}
    .sh-pagebody{background:var(--bg);}

    /* two-panels fixed height like org/messages.php */
    .chat-layout{align-items:stretch; margin-top: 20px;}
    .chat-card{
      height: calc(90vh - 260px);
      display:flex;
      flex-direction:column;
      border:1px solid var(--border);
      /* border-radius: var(--radius); */
      /* box-shadow: var(--shadow); */
      overflow:hidden;
      background:var(--card);
      width: 300px; 
      height: 500px;
      margin-left: 5px;
      /* border-color: #e1dddd; */
    }
    .chat-card-right{
      height: calc(90vh - 260px);
      display:flex;
      flex-direction:column;
      border:1px solid var(--border);
      /* border-radius: var(--radius); */
      /* box-shadow: var(--shadow); */
      overflow:hidden;
      /* background:var(--card); */
      width: 300px; 
      height: 500px;
      margin-left: 15px;
      /* border-color: #e1dddd; */
      /* width: 790px; */
    }
    .chat-card-body{flex:1;min-height:0;display:flex;flex-direction:column; padding:1px;}
    .chat-left-col,.chat-right-col{display:flex;}
    .chat-left-col .chat-card-body{padding:1px;}
    .chat-right-col .chat-card-body{padding:0;}

    /* Left: header */
    .chat-left-head{
      padding:14px;
      /* color:#fff; */
      font-weight:900;
      background: linear-gradient(135deg,var(--brand),var(--brand2));
      border-bottom:1px solid rgba(255,255,255,.18);
    }

    /* search */
    #chatSearch{
      height:42px;
      /* border-radius:14px; */
      border:1px solid var(--border);
      padding:10px 12px;
      outline:none;
    }
    #chatSearch:focus{
      border-color: rgba(37,99,235,.45);
      box-shadow:0 0 0 4px rgba(37,99,235,.12);
    }

    /* chat list */
    .chat-list{
      margin-top:10px;
      border:1px solid var(--border);
      /* border-radius:14px; */
      overflow:auto;
      background:#fff;
      flex:1;
      min-height:0;
    }
    .chat-item{
      display:flex; align-items:center; justify-content:space-between;
      gap:10px;
      padding:12px;
      border-bottom:1px solid rgba(17,24,39,.08);
      /* color:var(--text); */
      text-decoration:none;
      transition: background .15s ease;
    }
    .chat-item:hover{ background:rgba(37,99,235,.06); }
    .chat-item.active{ background:rgba(37,99,235,.10); }

    .chat-left{
      display:flex; align-items:center; gap:10px;
      min-width:0; flex:1 1 auto;
    }

    .avatar{
      width:40px;height:40px;border-radius:50%;
      display:flex;align-items:center;justify-content:center;
      font-weight:900;color:#fff;
      background: linear-gradient(135deg,var(--brand),var(--brand2));
      box-shadow:0 10px 26px rgba(37,99,235,.18);
      position:relative;
      flex:0 0 auto;
      user-select:none;
    }
    .presenceDot{
      position:absolute; right:-3px; bottom:-3px;
      width:12px;height:12px;border-radius:50%;
      background:#9aa0a6;
      box-shadow:0 0 0 3px rgba(255,255,255,.95);
      border:1px solid rgba(0,0,0,.08);
    }
    .presenceDot.on{ background:#22c55e; }
    .chat-name{font-weight:900;font-size:14px;line-height:1.1;}
    .chat-meta{font-size:12px;color:var(--muted);}
    .chatLastMsg{font-size:12px;color:rgba(17,24,39,.72);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:260px;}

    .unreadBadge{
      display:inline-flex; align-items:center; justify-content:center;
      min-width:20px;height:20px;padding:0 7px;
      border-radius:999px;background:#ef4444;color:#fff;
      font-weight:900;font-size:11px;
      box-shadow:0 0 0 4px rgba(239,68,68,.15);
    }

    /* Right: top bar */
    .chat-topbar{
      padding:14px;
      background:#fff;
      border-bottom:1px solid var(--border);
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap:12px;
    }
    .peerTitle{
      font-weight:900;font-size:16px;
      display:flex; align-items:center; gap:10px;
      color:var(--text);
      min-width:0;
    }
    .peerAvatar img{width:100%;height:100%;object-fit:cover;border-radius:inherit;display:block;}
    .peerAvatar{
      width:38px;height:38px;border-radius:50%;
      display:flex;align-items:center;justify-content:center;
      font-weight:900;color:#fff;
      background: linear-gradient(135deg,var(--brand),var(--brand2));
      box-shadow:0 10px 26px rgba(37,99,235,.18);
    }
    .peerSub{font-size:12px;display:flex;gap:8px;align-items:center;margin-top:2px;}
    .peerOnlineDot{
      width:10px;height:10px;border-radius:50%;
      background:#9aa0a6;
      box-shadow:0 0 0 4px rgba(154,160,166,.18);
    }
    .peerOnlineDot.on{
      background:#22c55e;
      box-shadow:0 0 0 4px rgba(34,197,94,.18);
    }

    .iconbar a{text-decoration:none;}
    .iconbar i{
      font-size:22px;padding:8px;border-radius:12px;
      color:rgba(5, 65, 195, 0.78);
      transition: background .15s ease;
    }
    .iconbar i:hover{ background:rgba(37,99,235,.08); }

    /* chat box */
    #chatBox{
      background:transparent !important;
      padding:14px 22px 10px !important;
      flex:1 1 auto;
      min-height:0 !important;
      max-height:none !important;
      overflow-y: auto !important;
      overflow-x:hidden !important;
      -webkit-overflow-scrolling: touch !important;
    }

    .day-divider{
      position:sticky; top:0; z-index:5;
      text-align:center;
      margin:14px auto;
      padding:8px 10px;
      color:rgba(17,24,39,.70);
      font-weight:900; font-size:12px;
      background:rgba(248,250,252,.92);
      backdrop-filter: blur(6px);
      border-radius:999px;
      border:1px solid rgba(17,24,39,.10);
      box-shadow:0 10px 26px rgba(15,23,42,.06);
      width: fit-content;
      max-width: calc(100% - 24px);
    }

    .msg-row{ margin:10px 0; display:flex; }
    .msg-row.me{ justify-content:flex-end; }

    .row-sm {
      margin-left: 1%;
      margin-right: 2%;
    }
    .row {
    display: flex;
    flex-wrap: wrap;
    /* margin-right: -15px; */
      /* margin-left: -15px; */
    }
    .row-sm {
      /* margin-left: -10px;
      margin-right: -10px; */
    }
    .row-sm > div {
      padding-left: 1px;
      padding-right: 1px;
    }

    .bubble{
      display:inline-flex;
      flex-direction:column;
      gap:6px;
      max-width:min(680px,78%);
      width: fit-content;
      padding:10px 12px;
      border-radius:16px;
      background:#ebeef2;
      border:1px solid rgba(118, 152, 226, 0.1);
      box-shadow:0 10px 30px rgba(15,23,42,.06);
      overflow-wrap:anywhere;
      word-break:break-word;
    }
    .bubble.me{
      background:linear-gradient(135deg,var(--brand),var(--brand2));
      color:#fff;
      border-color:rgba(255,255,255,.12);
      box-shadow:0 12px 32px rgba(37,99,235,.20);
    }
    .msgText{white-space:pre-wrap;line-height:1.35; color:#8f9696;}
    .msg-meta{font-size:11px;color:rgba(17,24,39,.60);display:flex;gap:8px;align-items:center;flex-wrap:wrap;}
    .msg-meta.me{color:rgba(255,255,255,.85);justify-content:flex-end;}
    .msgTicks{font-weight:900;letter-spacing:1px;}

    .bubble img,.bubble video{max-width:320px;max-height:320px;border-radius:14px;display:block;}

    /* composer pinned like org/messages.php */
    .composer{
      border-top:1px solid var(--border);
      background:#fff;
      padding:12px;
    }
    
    #msgPlusBtn,#emojiBtn{
      width:60px;height:60px;
      /* border-radius:14px; */
      border:1px solid var(--border);
      background:#fff;font-weight:900;
      box-shadow: var(--shadow2);
    }
    #messageInput{
      height: 45px;
      width:100%;
      resize:none;
      min-height:42px;
      max-height:120px;
      padding:10px 12px;
      border:1px solid var(--border);
      /* border-radius:14px; */
      outline:none;
    }
    #messageInput:focus{
      border-color: rgba(37,99,235,.45);
      box-shadow:0 0 0 4px rgba(37,99,235,.12);
    }
    #sendForm button[type="submit"]{
      width:60px;height:60px;
      /* border-radius:60px; */
      display:inline-flex;align-items:center;justify-content:center;
      box-shadow:0 12px 30px rgba(37,99,235,.20);
    }

    /* inline preview */
    #msgPreviewInline{
      display:none;
      align-items:center;
      gap:10px;
      border:1px solid var(--border);
      background:rgba(15,23,42,.03);
      padding:8px 10px;
      border-radius:14px;
      max-width:320px;
      box-shadow:0 10px 24px rgba(15,23,42,.06);
    }
    #msgPreviewInline img{
      width:42px;height:42px;object-fit:cover;border-radius:12px;border:1px solid rgba(17,24,39,.10);
    }

    /* NOTE: popup menus are appended to <body> */
    .floatMenu{
      position:fixed;
      z-index:999999;
      background:#fff;
      border:1px solid var(--border);
      /* border-radius:16px; */
      box-shadow:0 18px 55px rgba(15,23,42,.18);
      display:none;
    }
    #plusMenuBody{
      padding:10px;
      display:flex;
      gap:10px;
    }
    #plusMenuBody button{
      width:46px;height:46px;border-radius:16px;
      border:1px solid var(--border);
      background:rgba(15,23,42,.03);
      font-size:18px; cursor:pointer;
    }

    #emojiMenuBodyHead{
      padding:10px 12px;
      border-bottom:1px solid rgba(17,24,39,.08);
      display:flex;justify-content:space-between;align-items:center;
    }
    #emojiGrid{
      display:grid;
      grid-template-columns:repeat(8,1fr);
      gap:10px;
      padding:12px;
      max-height:260px;
      overflow:auto;
    }
    #emojiGrid button{
      border:1px solid rgba(17,24,39,.08);
      background:rgba(15,23,42,.03);
      border-radius:14px;
      font-size:20px;
      height:42px;
      cursor:pointer;
    }
    #emojiGrid button:hover{ background: rgba(37,99,235,.08); }

    @media (max-width: 575.98px){
      .chat-card{height:auto;}
      .chat-list{max-height:46vh;}
      #chatBox{max-height:52vh;}
    }
  

  /* ===== Added: Fixed/Sticky Header (like dashboard.php/feed.php) ===== */
  html, body { height: 100%; }
  body { overflow: hidden; }

  

  /* Sticky page title/header */
  .sh-pagetitle{
    position: sticky;
    top: 100px;
    z-index: 1100;
    background: #fff;
    border-bottom: 1px solid rgba(0,0,0,.08);
    box-shadow: 0 2px 10px rgba(0,0,0,.05);
    flex: 0 0 auto;
  }

  /* Page body fills remaining space; content scrolls while header stays fixed */
  .sh-pagebody{
    flex: 1 1 auto;
    min-height: 0;
    overflow: auto;
    padding-top: 10px;
  }

  .mailbox-body {
  position: absolute;
  top: 0;
  right: 0;
  bottom: 0;
  /* padding: 15px; */
  overflow-y: auto;
  display: none;
  z-index: 50;
  background-color: #fff;
}
  /* ===== End added styles ===== */



/* ===== Mobile fixes (iPhone) ===== */
@media (max-width: 575.98px){
  html, body { height: auto !important; }
  body { overflow: auto !important; }

  /* Undo desktop fixed panel layout on small screens */
  .sh-mainpanel{ height:auto !important; overflow: visible !important; margin-left: 0 !important; }
  .sh-pagetitle{ top: 0 !important; }
  .sh-pagebody{ overflow: visible !important; }

  /* Stack chat panels and use full width */
  .chat-layout{ flex-direction: column !important; }
  .chat-card, .chat-card-right{
    width: 100% !important;
    margin-left: 0 !important;
    height: auto !important;
  }
  .chat-list{ max-height: 44vh !important; }
  #chatBox{ max-height: 50vh !important; }

  /* Prevent horizontal scrolling */
  .container, .container-fluid, .row{ max-width:100% !important; }
  img, video, iframe{ max-width:100% !important; height:auto !important; }
}


/* =========================
   MOBILE + FLEX SCROLL FIX (2026-02-28)
   Goal:
   - No fixed px widths/heights
   - Conversation (#chatBox) scrolls
   - Input stays visible
   - Left list + right chat fit screen
========================= */

/* Make this page a fixed-height app shell (scroll ONLY inside panels) */
html, body { height: 100% !important; }
body { overflow: hidden !important; }

/* Main panel should fill viewport and not create page scroll */
.sh-mainpanel{
  height: 100dvh !important;
  display: flex !important;
  flex-direction: column !important;
  overflow: hidden !important;
  margin-left: 340px;
}

/* Page body takes remaining height */
.sh-pagebody{
  flex: 1 1 auto !important;
  min-height: 0 !important;
  overflow: hidden !important;
  padding-bottom: env(safe-area-inset-bottom) !important;
}

/* Row becomes full-height */
.chat-layout{
  height: 100% !important;
  margin-top: 10px !important;
  gap: 12px !important;
  flex-wrap: nowrap !important;
}

/* Columns must be full height for internal scrolling */
.chat-left-col,
.chat-right-col{
  display: flex !important;
  flex-direction: column !important;
  height: 100% !important;
  min-height: 0 !important;
}

/* Remove fixed card sizes; cards fill their column */
.chat-card,
.chat-card-right{
  width: 100% !important;
  height: 90% !important;
  margin-left: 0 !important;
}

/* Desktop proportions: left narrower, right wider */
@media (min-width: 576px){
  .chat-left-col{
    flex: 0 0 32% !important;
    max-width: 360px !important;
    min-width: 260px !important;
  }
  .chat-right-col{
    flex: 1 1 auto !important;
    min-width: 0 !important; /* allow flex shrink without overflow */
  }
}

/* Ensure right chat card is flex column and the chat box scrolls */
.chat-card-right{
  display: flex !important;
  flex-direction: column !important;
  overflow: hidden !important;
  min-width: 0 !important;
}



/* Keep compose/input visible */
.chat-compose, .chat-input, .chat-footer, .chatSendRow{
  flex: 0 0 auto !important;
}

/* Prevent horizontal overflow */
.container, .container-fluid, .row { max-width: 100% !important; }
img, video, iframe { max-width: 100% !important; height: auto !important; }
* { box-sizing: border-box; }
.chat-card-right, .chat-card { min-width: 0 !important; }

/* MOBILE: stack panels and reserve space for footer nav (if present) */
@media (max-width: 575.98px){
  /* Hide big desktop left menu if it exists */
  .sh-sideleft-menu, .sh-sideleft { display: none !important; }
  .sh-mainpanel{ margin-left: 0 !important; }

  .chat-layout{
    flex-direction: column !important;
    gap: 10px !important;
    margin-top: 8px !important;
  }

  .chat-left-col,
  .chat-right-col{
    max-width: 100% !important;
  }

  /* Let list take top part; chat takes bottom part */
  .chat-card{ height: 38dvh !important; }
  .chat-card-right{ height: 58dvh !important; }

  /* Make sure list and messages scroll internally */
  .chat-list{ max-height: 100% !important; overflow-y: auto !important; -webkit-overflow-scrolling: touch !important; }
  #chatBox{ padding: 12px !important; }

  /* If you have bottom nav, reduce heights a bit (prevents input being cut) */
  .chat-card{ height: calc(70dvh - 8px) !important; }
  .chat-card-right{ height: calc(58dvh - 8px) !important; }
}

</style>

<style>
/* === FORCE both list + conversation to show on Tablet/Mobile === */
@media (max-width: 991.98px){
  .chat-layout{ flex-direction: column !important; }
  .chat-left-col, .chat-right-col{ display: flex !important; width: 100% !important; max-width: 100% !important; }
  .chat-card, .chat-card-right{ width: 100% !important; margin-left: 0 !important; }
  /* Let the page scroll naturally so user can see both sections */
  html, body{ height: auto !important; }
  body{ overflow-y: auto !important; overflow-x: hidden !important; }
  .sh-mainpanel{ height: auto !important; overflow: visible !important; margin-left: 0 !important; }
  .sh-pagebody{ overflow: visible !important; }
}
</style>


<style>
/* =====================================================
   MOBILE/TABLET "MESSENGER" MODE (List <-> Chat)
   ✅ Minimal CSS/JS, keeps PHP logic unchanged
===================================================== */
@media (max-width: 991.98px){
  /* remove fixed widths/heights that cause hiding */
  .chat-card-left, .chat-card-right{
    width: 100% !important;
    height: auto !important;
    min-height: 0 !important;
    margin-left: 0 !important;
  }
  html, body{
    overflow-x: hidden !important;
    overflow-y: auto !important;
    height: auto !important;
  }
  /* Default: list mode */
  body.m-mode-list .chat-left-col{ display:flex !important; }
  body.m-mode-list .chat-right-col{ display:none !important; }

  /* Chat mode */
  body.m-mode-chat .chat-left-col{ display:none !important; }
  body.m-mode-chat .chat-right-col{ display:flex !important; }

  /* Make columns full width in mobile */
  .chat-left-col, .chat-right-col{
    flex: 0 0 100% !important;
    max-width: 100% !important;
    width: 100% !important;
  }

  /* Back button (shown only in chat mode) */
  .mBackBtn{
    display: none;
    width: 42px;
    height: 42px;
    border-radius: 999px;
    border: 0;
    background: rgba(17,24,39,.14);
    color: #7c3aed; /* purple arrow like your example */
    align-items: center;
    justify-content: center;
    margin-right: 10px;
    cursor: pointer;
  }
  body.m-mode-chat .mBackBtn{ display: inline-flex; }
  .mBackBtn:active{ transform: scale(.98); }
  .mBackBtn svg{ width: 22px; height: 22px; }
  .messages-shell-tools{
    width:100%;
    justify-content:flex-end;
    flex-wrap:wrap;
  }
  .messages-shell-tabs{
    order:3;
    width:100%;
    justify-content:flex-end;
  }
}
</style>

<style>
  :root{
    --msg-bg:#f4f4f6;
    --msg-panel:#ffffff;
    --msg-panel-2:#fafafa;
    --msg-panel-3:#f2f4f7;
    --msg-border:#e7e7eb;
    --msg-text:#1f1f1f;
    --msg-muted:#757b85;
    --msg-muted-2:#a1a7b3;
    --msg-accent:#635bff;
    --msg-accent-2:#6f67ff;
    --msg-blue:#635bff;
    --msg-green:#22a66f;
  }

  body{
    background:var(--msg-bg) !important;
    color:var(--msg-text);
  }
  .sh-pagebody{
    background:var(--msg-bg) !important;
    padding:34px 18px 16px !important;
  }
  .messages-shell{
    background:var(--msg-panel);
    border:1px solid var(--msg-border);
    /* border-radius:34px; */
    overflow:hidden;
    height:calc(100dvh - 138px);
    min-height:680px;
    display:flex;
    flex-direction:column;
  }
  .messages-shell-head{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:16px;
    padding:22px 28px;
    border-bottom:1px solid var(--msg-border);
    background:var(--msg-panel);
  }
  .messages-shell-title{
    font-size:20px;
    font-weight:900;
    color:#222;
  }
  .messages-shell-tools{
    display:flex;
    align-items:center;
    gap:14px;
  }
  .messages-shell-action-btn{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    min-height:40px;
    padding:0 16px;
    border:1px solid var(--msg-border);
    border-radius:999px;
    background:#fff;
    color:#111827;
    font-size:13px;
    font-weight:800;
    text-decoration:none;
    cursor:pointer;
  }
  .messages-shell-action-btn:hover{
    background:#f8f8fb;
  }
  .messages-shell-tabs{
    display:inline-flex;
    align-items:center;
    gap:6px;
    padding:4px;
    border-radius:999px;
    background:#f5f6fa;
    border:1px solid var(--msg-border);
  }
  .messages-shell-tab{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    min-height:34px;
    padding:0 14px;
    border-radius:999px;
    font-size:13px;
    font-weight:800;
    color:var(--msg-muted);
    text-decoration:none;
    transition:background .18s ease, color .18s ease, box-shadow .18s ease;
  }
  .messages-shell-tab:hover{
    color:#1f2937;
    background:rgba(99,91,255,.08);
  }
  .messages-shell-tab.active{
    background:var(--msg-accent);
    color:#fff;
    box-shadow:0 8px 18px rgba(99,91,255,.22);
  }
  .messages-shell-search{
    display:flex;
    align-items:center;
    gap:12px;
    width:min(540px, 44vw);
    min-height:42px;
    border-radius:999px;
    background:#f5f5f5;
    padding:0 18px;
    color:var(--msg-muted);
  }
  .messages-shell-search input{
    flex:1 1 auto;
    border:0;
    background:transparent;
    outline:none;
    font-size:14px;
    color:#30343a;
  }
  .messages-shell-search input::placeholder{color:#8a9099;}
  .messages-shell-icon,
  .messages-shell-avatar{
    width:40px;
    height:40px;
    border-radius:999px;
    background:#f6f6f6;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    color:#202124;
    flex:0 0 auto;
  }
  .messages-shell-avatar img{
    width:100%;
    height:100%;
    object-fit:cover;
    border-radius:inherit;
    display:block;
  }
  .chat-mode-empty{
    display:flex;
    flex-direction:column;
    align-items:flex-start;
    justify-content:center;
    gap:10px;
    min-height:100%;
    padding:32px 28px;
  }
  .chat-mode-empty h3{
    margin:0;
    font-size:24px;
    font-weight:900;
    color:#1f2937;
  }
  .chat-mode-empty p{
    margin:0;
    max-width:520px;
    font-size:14px;
    line-height:1.6;
    color:var(--msg-muted);
  }
  .group-list-empty,
  .group-notice,
  .group-panel-card{
    border:1px solid var(--msg-border);
    border-radius:18px;
    background:#fff;
  }
  .group-list-empty{
    margin:14px;
    padding:14px;
    color:var(--msg-muted);
  }
  .group-notice{
    margin:16px 22px 0;
    padding:12px 14px;
    font-size:13px;
    font-weight:700;
  }
  .group-notice.success{
    color:#12633d;
    background:#ecfdf3;
    border-color:#b7ebc9;
  }
  .group-notice.error{
    color:#9f1239;
    background:#fff1f2;
    border-color:#fecdd3;
  }
  .group-panel-wrap{
    padding:22px;
    display:flex;
    flex-direction:column;
    gap:18px;
  }
  .group-panel-card{
    padding:18px;
  }
  .group-panel-card h3,
  .group-panel-card h4{
    margin:0 0 12px;
    font-weight:900;
    color:#111827;
  }
  .group-panel-copy{
    margin:0 0 14px;
    font-size:14px;
    line-height:1.6;
    color:var(--msg-muted);
  }
  .group-form-grid{
    display:grid;
    gap:12px;
  }
  .group-form-grid input[type="text"]{
    width:100%;
    min-height:44px;
    border:1px solid var(--msg-border);
    border-radius:14px;
    padding:10px 14px;
    outline:none;
  }
  .group-form-grid input[type="text"]:focus{
    border-color:rgba(99,91,255,.42);
    box-shadow:0 0 0 4px rgba(99,91,255,.10);
  }
  .group-member-picker{
    display:grid;
    gap:10px;
    max-height:240px;
    overflow:auto;
    padding-right:4px;
  }
  .group-member-option{
    display:flex;
    align-items:center;
    gap:10px;
    padding:10px 12px;
    border:1px solid var(--msg-border);
    border-radius:14px;
    background:#fafafa;
  }
  .group-member-option input{
    margin:0;
  }
  .group-member-option strong{
    display:block;
    font-size:14px;
    color:#111827;
  }
  .group-member-option span{
    display:block;
    font-size:12px;
    color:var(--msg-muted);
  }
  .group-form-actions{
    display:flex;
    align-items:center;
    gap:10px;
    flex-wrap:wrap;
  }
  .group-meta-line{
    display:flex;
    gap:10px;
    flex-wrap:wrap;
    margin-bottom:14px;
    color:var(--msg-muted);
    font-size:13px;
    font-weight:700;
  }
  .group-member-grid{
    display:grid;
    gap:10px;
  }
  .group-member-chip{
    display:flex;
    align-items:center;
    gap:12px;
    padding:12px 14px;
    border:1px solid var(--msg-border);
    border-radius:16px;
    background:#fafafa;
  }
  .group-member-avatar{
    width:42px;
    height:42px;
    border-radius:999px;
    overflow:hidden;
    background:#f1f5f9;
    flex:0 0 auto;
  }
  .group-member-avatar img{
    width:100%;
    height:100%;
    object-fit:cover;
    display:block;
  }
  .group-member-text{
    min-width:0;
  }
  .group-member-text strong{
    display:block;
    font-size:14px;
    color:#111827;
  }
  .group-member-text span{
    display:block;
    font-size:12px;
    color:var(--msg-muted);
  }
  .group-member-row-actions{
    margin-left:auto;
    display:flex;
    align-items:center;
    gap:8px;
    flex-wrap:wrap;
  }
  .group-member-row-actions form{
    margin:0;
  }
  .group-member-row-actions button{
    border:1px solid var(--msg-border);
    background:#fff;
    border-radius:999px;
    padding:6px 10px;
    font-size:11px;
    font-weight:800;
    cursor:pointer;
  }
  .group-member-row-actions button.danger{
    color:#b91c1c;
    border-color:#fecaca;
    background:#fff5f5;
  }
  .group-inline-actions{
    display:flex;
    align-items:center;
    align-self:center;
    gap:14px;
    color:#8a9099;
    flex:0 0 auto;
    padding:0;
    opacity:0;
    visibility:hidden;
    transform:translateX(4px);
    transition:opacity .18s ease, transform .18s ease, visibility .18s ease;
  }
  .msg-row:hover .group-inline-actions,
  .msg-row:focus-within .group-inline-actions{
    opacity:1;
    visibility:visible;
    transform:translateX(0);
  }
  .group-inline-actions button{
    width:24px;
    height:24px;
    border:0;
    border-radius:999px;
    background:transparent;
    color:inherit;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    font-size:13px;
    padding:0;
    cursor:pointer;
  }
  .group-chat-stream{
    display:flex;
    flex-direction:column;
    gap:12px;
    padding:22px;
  }
  .group-chat-empty{
    padding:36px 24px;
    color:var(--msg-muted);
    text-align:center;
    font-size:14px;
  }
  .group-chat-composer{
    padding:16px 22px 22px;
    border-top:1px solid var(--msg-border);
    background:#fff;
  }
  .group-chat-form{
    display:flex;
    align-items:flex-end;
    gap:12px;
  }
  .group-chat-form textarea{
    flex:1 1 auto;
    min-height:54px;
    max-height:180px;
    border:1px solid var(--msg-border);
    border-radius:18px;
    padding:14px 16px;
    resize:vertical;
    outline:none;
  }
  .group-chat-form textarea:focus{
    border-color:rgba(99,91,255,.42);
    box-shadow:0 0 0 4px rgba(99,91,255,.10);
  }
  .group-create-modal{
    position:fixed;
    inset:0;
    z-index:1000000;
    display:none;
    align-items:center;
    justify-content:center;
    padding:20px;
    background:rgba(15,23,42,.56);
  }
  .group-create-modal.is-open{
    display:flex;
  }
  .group-create-dialog{
    width:min(620px, 96vw);
    max-height:min(86vh, 860px);
    overflow:auto;
    background:#fff;
    border-radius:24px;
    box-shadow:0 24px 60px rgba(15,23,42,.22);
    padding:22px;
  }
  .group-create-head{
    display:flex;
    align-items:flex-start;
    justify-content:space-between;
    gap:12px;
    margin-bottom:16px;
  }
  .group-create-head h3{
    margin:0;
    font-size:22px;
    font-weight:900;
    color:#111827;
  }
  .group-create-head p{
    margin:6px 0 0;
    color:var(--msg-muted);
    font-size:14px;
  }
  .group-create-close{
    width:40px;
    height:40px;
    border-radius:999px;
    border:1px solid var(--msg-border);
    background:#fff;
    cursor:pointer;
  }
  .chat-layout{
    gap:0 !important;
    margin-top:0 !important;
    flex:1 1 auto;
    min-height:0;
  }
  .chat-left-col{flex:0 0 22% !important;max-width:22% !important;min-width:260px !important;}
  .chat-right-col{flex:1 1 auto !important;min-width:0 !important;}
  .chat-card,
  .chat-card-right{
    background:var(--msg-panel) !important;
    border:0 !important;
    border-radius:0 !important;
    box-shadow:none !important;
    height:100% !important;
    min-height:0 !important;
    margin-left:0 !important;
  }
  .chat-left-head{
    background:var(--msg-panel) !important;
    border-bottom:1px solid var(--msg-border) !important;
    padding:16px 20px 12px !important;
  }
  .chat-left-head-row{
    display:none;
  }
  .chat-search-wrap{
    display:flex;
    align-items:center;
    gap:12px;
    background:#f7f7f7;
    border-radius:12px;
    padding:0 16px;
    height:48px;
    color:var(--msg-muted);
  }
  .chat-search-wrap i{font-size:18px;}
  #chatSearch{
    background:transparent !important;
    border:0 !important;
    box-shadow:none !important;
    color:#30343a !important;
    height:100% !important;
    padding:0 !important;
    font-size:14px;
  }
  #chatSearch::placeholder{color:#9ba1ab;}
  .chat-filter-tabs{display:none;}
  .chat-card-body{
    background:transparent !important;
    padding:0 !important;
  }
  .chat-list{
    margin-top:0 !important;
    border:0 !important;
    border-radius:0 !important;
    background:transparent !important;
    padding:6px 0 0;
  }
  .chat-item{
    padding:14px 18px !important;
    border-radius:0;
    border-bottom:1px solid #efeff2 !important;
    color:#202124 !important;
    margin-bottom:0;
    position:relative;
  }
  .chat-item:hover{background:#fafbff !important;}
  .chat-item.active{background:#f8f9ff !important;}
  .chat-item.active::after{
    content:"";
    position:absolute;
    top:16px;
    right:0;
    bottom:16px;
    width:4px;
    border-radius:999px 0 0 999px;
    background:#635bff;
  }
  .avatar{
    width:46px;height:46px;
    box-shadow:none !important;
    background:#d8dce3 !important;
  }
  .avatar img,.msg-avatar-mini img,.peerAvatar img{
    width:100%;
    height:100%;
    object-fit:cover !important;
    border-radius:inherit;
    display:block;
  }
  .presenceDot{
    width:12px;height:12px;
    right:-1px;bottom:1px;
    background:#c0c4cc;
    box-shadow:0 0 0 3px #fff;
    border:0;
  }
  .presenceDot.on{background:#22a66f;}
  .chat-name{
    font-size:15px;
    font-weight:500;
    color:#222 !important;
  }
  .chat-meta,
  .chatLastMsg{
    color:var(--msg-muted) !important;
    font-size:12px;
  }
  .chatLastMsg{
    max-width:none !important;
    font-size:13px;
    margin-top:4px;
  }
  .chat-item-right{
    display:flex;
    align-items:center;
    gap:10px;
    flex:0 0 auto;
  }
  .chat-list-dot{
    width:10px;height:10px;border-radius:999px;
    background:#635bff;
    opacity:0;
  }
  .chat-list-dot.is-visible{opacity:1;}
  .unreadBadge{
    min-width:22px;
    height:22px;
    box-shadow:none;
    background:#635bff;
    font-size:11px;
  }
  .chat-card-right{
    display:flex;
    flex-direction:column;
    min-height:0;
    position:relative;
  }
  .chat-stage{
    display:grid;
    grid-template-columns:minmax(0, 1fr) 280px;
    min-height:100%;
    height:100%;
    overflow:hidden;
    transition:grid-template-columns .22s ease;
  }
  .chat-stage.is-info-collapsed{
    grid-template-columns:minmax(0, 1fr);
  }
  .chat-main-pane{
    display:flex;
    flex-direction:column;
    min-width:0;
    min-height:0;
    position:relative;
    border-left:1px solid var(--msg-border);
    border-right:1px solid var(--msg-border);
    overflow:hidden;
  }
  .chat-empty-state{
    flex:1 1 auto;
    display:flex;
    align-items:center;
    justify-content:center;
    padding:46px 20px;
    text-align:center;
    color:var(--muted);
  }
  .chat-empty-state-title{
    font-size:20px;
    font-weight:900;
    margin-bottom:8px;
  }
  .chat-empty-state-copy{
    max-width:520px;
    margin:0 auto;
  }
  .chat-empty-plus{
    position:absolute;
    right:0;
    bottom:24px;
    width:58px;
    height:58px;
    border-radius:50%;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    background:linear-gradient(135deg, #6d66ff 0%, #5f59f5 100%);
    color:#ffffff !important;
    box-shadow:0 16px 32px rgba(95, 89, 245, .28);
    font-size:24px;
    text-decoration:none !important;
    z-index:2;
  }
  .chat-empty-plus:hover{
    color:#ffffff !important;
    transform:translateY(-1px);
  }
  .chat-topbar{
    background:var(--msg-panel) !important;
    border-bottom:1px solid var(--msg-border) !important;
    padding:12px 18px !important;
  }
  .peerAvatar{
    width:42px;height:42px;
    box-shadow:none !important;
    background:#d8dce3 !important;
  }
  .peerTitle,
  .peerTitle span{
    color:#1f1f1f !important;
    font-size:15px;
    font-weight:800;
  }
  .peerSub{
    color:var(--msg-green) !important;
    font-size:12px;
    margin-top:2px;
  }
  .peerOnlineDot{display:none;}
  .iconbar i{
    color:#635bff !important;
    background:transparent !important;
    font-size:22px !important;
    padding:4px !important;
  }
  .iconbar .chat-info-toggle{
    border:0;
    background:transparent;
    padding:0;
    line-height:1;
    display:inline-flex;
    align-items:center;
    justify-content:center;
  }
  .iconbar .chat-info-toggle.is-active i{
    color:#3f38d8 !important;
  }
 
  #chatStream{
    padding-bottom:8px;
  }
  #chatStream{
    padding-bottom:140px !important;
  }
  .day-divider{
    position:relative !important;
    background:transparent !important;
    border:0 !important;
    box-shadow:none !important;
    color:#7b8088 !important;
    font-size:12px !important;
    padding:6px 0 !important;
    margin:20px auto !important;
    text-align:center;
  }
  .day-divider::before,
  .day-divider::after{
    content:"";
    position:absolute;
    top:50%;
    width:38%;
    height:1px;
    background:#e9e9ef;
  }
  .day-divider::before{left:0;}
  .day-divider::after{right:0;}
  #chatStream{
    display:flex;
    flex-direction:column;
    gap:8px;
  }
  .msg-row{
    margin:8px 0 !important;
    display:flex !important;
    align-items:flex-start;
    gap:10px;
    max-width:100%;
    min-width:0;
  }
  .msg-row:last-child{
    margin-bottom:28px !important;
  }
  .msg-row.is-pending .bubble{
    opacity:.78;
  }
  .msg-row.is-pending .msg-meta{
    opacity:.82;
  }
  .msg-row.reply-target-flash .bubble{
    box-shadow:0 0 0 2px rgba(93,167,255,.55), 0 0 0 10px rgba(93,167,255,.12) !important;
  }
  .msg-row:not(.me){
    justify-content:flex-start !important;
    flex-wrap:nowrap !important;
  }
  .msg-row.me{justify-content:flex-end !important;}
  .msg-peer-wrap{
    display:flex !important;
    align-items:center !important;
    gap:10px;
    flex:0 1 auto;
    min-width:0;
    max-width:100%;
  }
  .msg-bubble-stack{
    display:inline-flex;
    flex-direction:column;
    align-items:flex-start;
    flex:0 0 auto;
    width:auto;
    min-width:0;
    max-width:100%;
    position:relative;
    padding-bottom:0;
  }
  .msg-row.me .msg-bubble-stack{
    align-items:flex-end;
    max-width:min(100%, 520px);
  }
  .msg-side-actions{
    display:flex;
    align-items:center;
    gap:6px;
    color:#8a9099;
    flex:0 0 auto;
    padding:2px 0 0;
    opacity:0;
    visibility:hidden;
    transform:translateX(-4px);
    transition:opacity .18s ease, transform .18s ease, visibility .18s ease;
  }
  .msg-side-actions.left{
    order:0;
  }
  .msg-side-actions.is-media-actions,
  .msg-peer-actions.is-media-actions{
    align-self:center;
    padding:0;
  }
  .msg-peer-actions{
    display:flex !important;
    align-items:center;
    gap:6px;
    color:#8a9099;
    flex:0 0 auto;
    padding:2px 0 0;
    order:2;
    opacity:0;
    visibility:hidden;
    transform:translateX(4px);
    transition:opacity .18s ease, transform .18s ease, visibility .18s ease;
  }
  .msg-row:hover .msg-side-actions,
  .msg-row:hover .msg-peer-actions,
  .msg-row:focus-within .msg-side-actions,
  .msg-row:focus-within .msg-peer-actions{
    opacity:1 !important;
    visibility:visible !important;
    transform:translateX(0);
  }
  .msg-side-btn{
    width:24px;
    height:24px;
    border:0;
    border-radius:999px;
    background:transparent;
    color:inherit;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    font-size:13px;
    padding:0;
  }
  .msg-side-btn:hover{
    color:#4b5563;
    background:#eef2f7;
  }
  .msg-avatar-mini{
    width:36px;height:36px;border-radius:999px;
    overflow:hidden;flex:0 0 36px;
    background:#d8dce3;
    order:0;
    display:block !important;
    visibility:visible !important;
    opacity:1 !important;
  }
  .msg-row:not(.me)::before{
    content:attr(data-author);
    display:none;
  }
  .bubble{
    display:flex;
    flex-direction:column;
    position:relative;
    max-width:min(460px,72%) !important;
    width:auto;
    min-width:64px;
    border:0 !important;
    border-radius:12px !important;
    background:#f5f5f5 !important;
    box-shadow:none !important;
    padding:10px 14px !important;
    color:#202124 !important;
    order:1;
    visibility:visible !important;
    opacity:1 !important;
  }
  .bubble.has-reply{
    min-width:min(220px, 100%);
  }
  .bubble.has-media{
    max-width:min(100%, 260px) !important;
    padding:0 !important;
    background:transparent !important;
    box-shadow:none !important;
    border:0 !important;
    align-items:flex-start;
  }
  .bubble.me.has-media{
    background:transparent !important;
    color:#202124 !important;
    align-items:flex-end;
  }
  .bubble.has-media .btn,
  .bubble.has-media .btn-outline-secondary,
  .bubble.has-media .mediaOpen{
    background:transparent !important;
    box-shadow:none !important;
    border-color:transparent !important;
  }
  .bubble.has-media > div[style*="margin-top:6px"]{
    margin-top:0 !important;
  }
  .msg-row:not(.me) .bubble{
    order:1;
  }
  .bubble.me{
    background:linear-gradient(135deg, #6a63ff 0%, #5953ef 100%) !important;
    color:#fff !important;
    border-top-right-radius:12px !important;
  }
  .msgText{
    color:inherit !important;
    font-size:13px;
    line-height:1.4;
    white-space:normal !important;
    overflow-wrap:break-word;
    word-break:normal;
  }
  .msgText.is-unsent{
    opacity:.72;
    font-style:italic;
  }
  .msg-edited{
    margin-top:6px;
    font-size:11px;
    color:#9aa0a9;
    font-weight:700;
  }
  .msg-reply-line{
    margin-bottom:6px;
    padding:6px 8px;
    border-radius:10px;
    background:rgba(255,255,255,.08);
    border-left:3px solid rgba(255,255,255,.35);
    cursor:pointer;
  }
  .msg-reply-line-title{
    font-size:11px;
    font-weight:900;
    color:rgba(255,255,255,.92);
    line-height:1.2;
  }
  .msg-reply-line-text{
    margin-top:3px;
    font-size:11px;
    color:rgba(255,255,255,.72);
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis;
  }
  .msg-reactions{
    order:2;
    position:static;
    width:100%;
    margin-top:2px;
    display:flex;
    align-items:center;
    gap:4px;
    flex-wrap:wrap;
    z-index:2;
  }
  .msg-row.me .msg-reactions{
    justify-content:flex-end;
  }
  .msg-row:not(.me) .msg-reactions{
    justify-content:flex-start;
  }
  .msg-bubble-stack .bubble.has-media + .msg-reactions{
    margin-top:4px;
  }
  .msg-reaction-pill{
    min-width:22px;
    height:22px;
    padding:0 6px;
    border-radius:999px;
    border:1px solid #ececf2;
    background:#fff;
    box-shadow:none;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    font-size:15px;
    line-height:1;
  }
  .msg-dot-btn{
    width:28px;
    height:28px;
    border:0;
    border-radius:999px;
    background:transparent;
    color:rgba(255,255,255,.72);
    display:inline-flex;
    align-items:center;
    justify-content:center;
    font-size:18px;
  }
  .msg-menu{
    position:fixed;
    z-index:1000002;
    min-width:220px;
    background:#fff;
    border:1px solid #e8e8ef;
    border-radius:18px;
    box-shadow:0 22px 60px rgba(0,0,0,.42);
    padding:8px;
    display:none;
  }
  .msg-menu button{
    width:100%;
    border:0;
    background:transparent;
    color:#202124;
    text-align:left;
    padding:11px 12px;
    border-radius:12px;
    font-weight:800;
  }
  .msg-menu button:hover{
    background:#f6f7fb;
  }
  .msg-menu button.danger{color:#fca5a5;}
  .msg-react-picker{
    position:fixed;
    z-index:1000003;
    display:none;
    align-items:center;
    gap:8px;
    padding:10px 12px;
    border-radius:999px;
    background:#fff;
    border:1px solid #e8e8ef;
    box-shadow:0 22px 60px rgba(0,0,0,.42);
  }
  .msg-react-picker button{
    width:44px;
    height:44px;
    border:0;
    border-radius:999px;
    background:transparent;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    font-size:30px;
    line-height:1;
    padding:0;
  }
  .msg-react-picker button:hover{
    background:#f6f7fb;
  }
  .msg-meta{display:none !important;}
  .bubble img,.bubble video{
    border-radius:12px !important;
    border:0 !important;
    max-width:100% !important;
    max-height:180px !important;
    width:auto;
    height:auto;
    object-fit:contain;
    display:block;
  }
  .bubble.has-media img,
  .bubble.has-media video{
    width:min(100%) !important;
    max-width:100% !important;
    height:auto !important;
    max-height:180px !important;
    background:transparent !important;
    box-shadow:none !important;
  }
  .msg-row.me .bubble.has-media{
    margin-right:0;
  }
  .composer{
    background:#f7f7f9 !important;
    border-top:1px solid var(--msg-border) !important;
    padding:10px 18px !important;
    flex:0 0 auto;
    position:sticky;
    bottom:0;
    z-index:3;
    margin-top:auto;
  }
  .chat-card-right,
  .chat-stage,
  .chat-main-pane{
    height:100% !important;
  }
  .chat-card-right{
    min-height:0 !important;
  }
  .chat-stage{
    align-items:stretch !important;
  }
  .chat-main-pane{
    justify-content:flex-start !important;
  }
  #chatBox{
    flex:1 1 auto !important;
    min-height:0 !important;
    padding-bottom:20px !important;
  }
  .composer{
    flex:0 0 auto !important;
    width:100% !important;
    position:relative !important;
    left:auto;
    right:auto;
    bottom:auto;
  }
  #chatBox > .composer{
    position:sticky !important;
    bottom:-20px;
    z-index:3;
    margin-top:auto;
  }
  .reply-preview{
    display:none;
    align-items:flex-start;
    justify-content:space-between;
    gap:12px;
    padding:10px 2px 12px;
    color:#202124;
  }
  .reply-preview.is-open{
    display:flex;
  }
  .reply-preview-main{
    min-width:0;
    flex:1 1 auto;
  }
  .reply-preview-title{
    font-size:15px;
    font-weight:900;
    line-height:1.2;
  }
  .reply-preview-text{
    margin-top:4px;
    font-size:13px;
    color:#7f8792;
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis;
  }
  .reply-preview-close{
    width:32px;
    height:32px;
    border:0;
    border-radius:999px;
    background:transparent;
    color:#202124;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    font-size:18px;
    padding:0;
    flex:0 0 auto;
  }
  .reply-preview-close:hover{
    background:#eceff5;
  }
  #sendForm{
    display:flex;
    flex-direction:column;
    align-items:stretch !important;
    gap:10px !important;
  }
  .group-send-form{
    display:flex;
    flex-direction:column;
    align-items:stretch !important;
    gap:10px !important;
  }

  .composer-bar{
    display:flex;
    align-items:center;
    gap:10px;
    background:#fff;
    border:1px solid #ececf2;
    border-radius:999px;
    padding:6px 12px;
    min-height:52px;
  }
  .composer-left,
  .composer-right{
    display:flex;
    align-items:center;
    gap:8px;
    flex:0 0 auto;
  }
  .composer-input-wrap{
    flex:1 1 auto;
    min-width:0;
  }
  #msgPlusBtn,#emojiBtn,#gifBtn,#composerLocationBtn,#composerMicBtn,
  #sendForm button[type="submit"],
  .group-composer-btn,
  .group-send-form button[type="submit"]{
    width:32px !important;
    height:32px !important;
    border:0 !important;
    background:transparent !important;
    box-shadow:none !important;
    color:#202124 !important;
    font-size:17px;
    padding:0 !important;
  }
  .group-send-form button[type="submit"]{
    width:68px !important;
    height:68px !important;
    border-radius:999px !important;
    background:linear-gradient(135deg, #6a63ff 0%, #5953ef 100%) !important;
    color:#fff !important;
    box-shadow:0 12px 30px rgba(89,83,239,.24) !important;
    display:inline-flex !important;
    align-items:center !important;
    justify-content:center !important;
  }
  .group-send-form button[type="submit"] i{
    color:#fff !important;
    font-size:24px !important;
  }
  #gifBtn{display:none !important;}
  #composerMicBtn{
    color:#202124 !important;
    font-size:18px;
  }
  #composerLocationBtn{
    font-size:18px;
  }
  #messageInput,
  .group-message-input{
    background:transparent !important;
    border:0 !important;
    border-radius:0 !important;
    color:#202124 !important;
    min-height:36px !important;
    max-height:92px !important;
    padding:8px 4px !important;
    font-size:14px;
    box-shadow:none !important;
    width:100%;
    resize:none;
  }
  #messageInput::placeholder,
  .group-message-input::placeholder{color:#969ca6;}
  #msgPreviewInline{
    background:#fff !important;
    border:1px solid var(--msg-border) !important;
    color:#202124;
    border-radius:18px;
    padding:10px 12px;
  }
  #sendForm button[type="submit"]{
    background:#635bff !important;
    color:#fff !important;
    border-radius:999px !important;
    width:34px !important;
    height:34px !important;
    font-size:13px;
  }
  .chat-info-panel{
    background:var(--msg-panel);
    padding:20px 16px;
    overflow:auto;
  }
  .chat-stage.is-info-collapsed .chat-info-panel{
    display:none;
  }
  .chat-info-head{
    display:flex;
    align-items:center;
    justify-content:flex-end;
    margin:-6px 0 8px;
  }
  .chat-info-close{
    width:34px;
    height:34px;
    border:0;
    border-radius:999px;
    background:#eef1f6;
    color:#4d5561;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    padding:0;
  }
  .chat-info-avatar{
    width:88px;
    height:88px;
    border-radius:999px;
    overflow:hidden;
    margin:4px auto 14px;
    background:#e8ebf0;
  }
  .chat-info-avatar img{
    width:100%;
    height:100%;
    object-fit:cover;
    display:block;
  }
  .chat-info-name{
    text-align:center;
    font-size:16px;
    font-weight:900;
    color:#202124;
  }
  .chat-info-handle{
    margin-top:4px;
    text-align:center;
    color:#6f7681;
    font-size:12px;
    font-weight:700;
  }
  .chat-info-section{
    margin-top:24px;
  }
  .chat-info-section-head{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
    margin-bottom:14px;
    font-size:14px;
    font-weight:900;
    color:#4d5561;
  }
  .chat-file-list,
  .chat-member-list{
    display:flex;
    flex-direction:column;
    gap:14px;
  }
  .chat-file-item,
  .chat-member-item{
    display:flex;
    align-items:center;
    gap:14px;
    color:#202124;
  }
  .chat-file-icon{
    width:36px;
    height:36px;
    border-radius:999px;
    background:#eef1ff;
    color:#635bff;
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:15px;
    flex:0 0 auto;
  }
  .chat-file-copy,
  .chat-member-copy{
    min-width:0;
  }
  .chat-file-name,
  .chat-member-name{
    font-size:13px;
    font-weight:800;
    color:#202124;
    line-height:1.25;
    word-break:break-word;
  }
  .chat-file-meta,
  .chat-member-meta{
    margin-top:3px;
    font-size:11px;
    color:#8a9099;
  }
  .chat-member-avatar{
    width:32px;
    height:32px;
    border-radius:999px;
    overflow:hidden;
    background:#e8ebf0;
    flex:0 0 auto;
  }
  .chat-member-avatar img{
    width:100%;
    height:100%;
    object-fit:cover;
    display:block;
  }
  .chat-add-member{
    color:#635bff;
    font-weight:800;
    font-size:13px;
  }
  .chat-view-all{
    display:inline-block;
    margin-top:14px;
    color:#635bff;
    font-weight:800;
    font-size:13px;
  }
  .floatMenu{
    background:#fff !important;
    border:1px solid var(--msg-border) !important;
  }
  #plusMenuBody button,
  #emojiGrid button{
    background:#fff !important;
    border:1px solid var(--msg-border) !important;
    color:#202124 !important;
  }
  #emojiMenuBodyHead{border-bottom:1px solid var(--msg-border) !important;color:#202124;}
  #conversationPanel .mBackBtn{
    background:#f0f2f6 !important;
    color:#202124 !important;
  }

  @media (max-width: 991.98px){
    .sh-pagebody{padding:22px 8px 12px !important;}
    .messages-shell{
      border-radius:20px;
      height:calc(100dvh - 108px);
      min-height:0;
    }
    .messages-shell-head{
      padding:16px;
      flex-wrap:wrap;
      align-items:flex-start;
    }
    .messages-shell-search{
      width:100%;
      min-width:0;
    }
    .chat-left-col,.chat-right-col{max-width:100% !important;min-width:0 !important;}
    .chat-card,.chat-card-right{
      height:auto !important;
      min-height:0 !important;
      border-radius:0 !important;
    }
    body.m-mode-list .chat-card{height:calc(100dvh - 160px) !important;}
    body.m-mode-chat .chat-card-right{height:calc(100dvh - 160px) !important;}
    .chat-search-wrap{height:46px;}
    .chat-item{padding:14px 16px !important;}
    .avatar{width:54px;height:54px;}
    .chat-stage{grid-template-columns:1fr;}
    .chat-info-panel{display:none;}
    .chat-main-pane{border-right:0;}
    .chat-empty-plus{
      right:0;
      bottom:18px;
      width:52px;
      height:52px;
    }
    #chatBox{padding:14px 16px 10px !important;}
    .bubble{max-width:86% !important;}
    .bubble.has-media{max-width:min(58vw, 216px) !important;}
    .msg-side-actions,
    .msg-peer-actions{
      opacity:1 !important;
      visibility:visible !important;
      transform:none !important;
    }
    .composer{padding:10px 12px !important;}
    .composer-bar{padding:8px 10px;}
    #messageInput{min-height:48px !important;}
  }

  @media (max-height: 860px){
    .messages-shell{
      height:calc(100dvh - 118px);
      /* min-height:0; */
    }
    .messages-shell-head{
      padding:16px 22px;
    }
    .chat-card,
    .chat-card-right{
      height:100% !important;
    }
    #chatBox{
      padding:14px 24px 10px !important;
    }
    .composer{
      padding:10px 20px !important;
    }
    .composer-bar{
      min-height:54px;
    }
  }

  #videoCallOverlay{
    position:fixed;
    inset:0;
    z-index:1000000;
    padding:12px;
    background:
      radial-gradient(1200px 720px at 50% -10%, rgba(59,130,246,.14), transparent 58%),
      linear-gradient(180deg, rgba(10,14,24,.96), rgba(7,10,18,.98));
    backdrop-filter:blur(12px);
  }
  .vcall-shell{
    position:relative;
    width:min(1360px, 100%);
    height:calc(100dvh - 24px);
    margin:0 auto;
    display:flex;
    flex-direction:column;
    color:#fff;
    /* border-radius:28px; */
    overflow:hidden;
    border:1px solid rgba(255,255,255,.08);
    background:#0b1626;
    box-shadow:0 28px 90px rgba(0,0,0,.42);
  }
  .vcall-shell.voice-mode .vcall-stage{
    background:
      radial-gradient(520px 320px at 50% 18%, rgba(59,130,246,.18), transparent 60%),
      linear-gradient(180deg, rgba(31,33,37,.98), rgba(12,14,18,.98));
  }
  
  .vcall-shell.voice-mode .vcall-local,
  .vcall-shell.voice-mode .vcall-stage-actions,
  .vcall-shell.voice-mode .vcall-reaction-bar{
    display:none !important;
  }
  .vcall-shell.group-mode .vcall-remote{
    display:none;
  }
  .vcall-shell.group-mode .vcall-remote-grid{
    display:grid;
  }
  .vcall-shell.group-mode{
    background:#00765b;
    border:0;
    box-shadow:none;
  }
  .vcall-shell.group-mode .vcall-header{
    position:absolute;
    inset:18px 18px auto 18px;
    z-index:9;
    height:auto;
    padding:0;
    background:transparent;
    border:0;
    pointer-events:none;
  }
  .vcall-shell.group-mode .vcall-meeting{
    gap:14px;
    pointer-events:auto;
  }
  .vcall-shell.group-mode .vcall-meeting-badge{
    width:auto;
    min-width:130px;
    height:48px;
    justify-content:flex-start;
    padding:0 18px;
    border-radius:0;
    background:transparent;
    box-shadow:none;
    font-family:Georgia, "Times New Roman", serif;
    font-size:32px;
    font-weight:900;
    letter-spacing:-.08em;
  }
  .vcall-shell.group-mode .vcall-meeting-badge i{
    display:none;
  }
  .vcall-shell.group-mode .vcall-meeting-badge::before{
    content:"Talentra";
  }
  .vcall-shell.group-mode .vcall-meta{
    height:48px;
    display:flex;
    align-items:center;
    gap:20px;
    padding:0 18px;
    border-radius:9px;
    background:#003d37;
    box-shadow:0 10px 28px rgba(0,0,0,.22);
  }
  .vcall-shell.group-mode .vcall-kicker{
    display:none;
  }
  .vcall-shell.group-mode .vcall-name{
    margin:0;
    max-width:min(36vw, 380px);
    font-size:21px;
    font-weight:900;
    letter-spacing:-.02em;
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis;
  }
  .vcall-shell.group-mode .vcall-name::before{
    content:"";
    display:inline-block;
    width:10px;
    height:10px;
    margin-right:12px;
    border-radius:999px;
    background:#ff765d;
    box-shadow:0 0 0 2px rgba(255,118,93,.16);
    vertical-align:middle;
  }
  .vcall-shell.group-mode .vcall-stage-status{
    margin:0;
    font-size:17px;
    font-weight:900;
    color:#fff;
  }
  .vcall-shell.group-mode .vcall-stage-status::before{
    content:"\f023";
    font-family:FontAwesome;
    margin-right:16px;
    font-size:23px;
  }
  .vcall-shell.group-mode .vcall-stage-status::after{
    content:"  \f0c0";
    font-family:FontAwesome;
    margin-left:16px;
    font-size:21px;
  }
  .vcall-shell.group-mode .vcall-top-right{
    pointer-events:auto;
  }
  .vcall-shell.group-mode .vcall-clock{
    display:none;
  }
  .vcall-shell.group-mode .vcall-close{
    width:48px;
    height:48px;
    border-radius:9px;
    background:#003d37;
    color:#fff;
    font-size:24px;
    box-shadow:0 10px 28px rgba(0,0,0,.22);
  }
  .vcall-shell.group-mode .vcall-close i::before{
    content:"\f013";
  }
  .vcall-shell.group-mode .vcall-body{
    display:block;
  }
  .vcall-shell.group-mode .vcall-sidebar{
    display:none;
  }
  .vcall-shell.group-mode .vcall-stage-wrap{
    height:100%;
    padding:116px 96px 122px;
    background:#00765b;
  }
  .vcall-shell.group-mode .vcall-stage{
    border:0;
    border-radius:0;
    background:transparent;
    box-shadow:none;
    overflow:visible;
  }
  .vcall-shell.group-mode .vcall-stage-top{
    display:none;
  }
  .vcall-shell.group-mode.host-layout .vcall-stage{
    background:transparent;
  }
  .vcall-shell.voice-mode #vcallCameraBtn{
    display:none !important;
  }
  .vcall-header{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:18px;
    padding:16px 18px;
    background:#0a1930;
    border-bottom:1px solid rgba(255,255,255,.06);
  }
  .vcall-meeting{
    display:flex;
    align-items:center;
    gap:10px;
  }
  .vcall-meeting-badge{
    width:34px;
    height:34px;
    border-radius:10px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    background:linear-gradient(135deg, #1d80ff, #53b5ff);
    color:#fff;
    font-size:15px;
    box-shadow:0 10px 24px rgba(29,128,255,.26);
  }
  .vcall-meta{min-width:0;}
  .vcall-kicker{
    font-size:11px;
    line-height:1.1;
    color:rgba(255,255,255,.58);
    font-weight:700;
  }
  .vcall-name{
    margin-top:2px;
    font-size:22px;
    font-weight:800;
    letter-spacing:-.03em;
    line-height:1.1;
  }
  .vcall-stage-status{
    margin-top:3px;
    font-size:12px;
    color:rgba(255,255,255,.72);
    font-weight:600;
  }
  .vcall-top-right{
    display:flex;
    align-items:center;
    gap:12px;
    margin-left:auto;
  }
  .vcall-clock{
    min-width:64px;
    text-align:center;
    font-size:12px;
    font-weight:800;
    letter-spacing:.08em;
    color:rgba(255,255,255,.75);
  }
  .vcall-close{
    width:34px;
    height:34px;
    border:0;
    border-radius:10px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    background:rgba(255,255,255,.08);
    color:#fff;
    font-size:14px;
  }
  .vcall-body{
    flex:1 1 auto;
    min-height:0;
    display:grid;
    grid-template-columns:minmax(0, 1fr) 320px;
  }
  .vcall-body.is-sidebar-collapsed{
    grid-template-columns:minmax(0, 1fr);
  }
  .vcall-stage-wrap{
    position:relative;
    min-width:0;
    padding:12px;
    background:#6d7c91;
  }
  .vcall-stage{
    position:relative;
    height:100%;
    min-height:0;
    border-radius:20px;
    overflow:hidden;
    background:linear-gradient(180deg, rgba(8,14,24,.88), rgba(6,10,18,.98));
    border:1px solid rgba(255,255,255,.08);
    box-shadow:0 24px 80px rgba(0,0,0,.35);
  }
  .vcall-remote,
  .vcall-local{
    background:#0b0f16;
    object-fit:cover;
  }
  .vcall-remote{
    position:absolute;
    inset:0;
    width:100%;
    height:100%;
    display:block;
  }
  .vcall-remote-grid{
    position:absolute;
    inset:0;
    z-index:1;
    display:none;
    grid-template-columns:repeat(auto-fit, minmax(220px, 1fr));
    gap:14px;
    padding:74px 18px 98px;
    align-content:stretch;
  }
  .vcall-shell.group-mode.host-layout .vcall-remote-grid{
    grid-template-columns:repeat(2, minmax(0, 1fr));
    grid-auto-rows:auto;
    gap:14px;
    padding:0;
    align-content:center;
    align-items:center;
    justify-items:center;
    background:transparent;
    overflow:auto;
  }
  .vcall-shell.group-mode.host-layout .vcall-remote-grid[data-tile-count="1"]{
    grid-template-columns:minmax(0, min(460px, 100%));
    justify-content:center;
    align-content:center;
  }
  .vcall-shell.group-mode.host-layout .vcall-remote-grid[data-tile-count="2"],
  .vcall-shell.group-mode.host-layout .vcall-remote-grid[data-tile-count="3"],
  .vcall-shell.group-mode.host-layout .vcall-remote-grid[data-tile-count="4"]{
    grid-template-columns:repeat(2, minmax(0, 1fr));
  }
  .vcall-shell.group-mode.host-layout .vcall-remote-grid[data-tile-count="5"],
  .vcall-shell.group-mode.host-layout .vcall-remote-grid[data-tile-count="6"],
  .vcall-shell.group-mode.host-layout .vcall-remote-grid[data-tile-count="7"],
  .vcall-shell.group-mode.host-layout .vcall-remote-grid[data-tile-count="8"],
  .vcall-shell.group-mode.host-layout .vcall-remote-grid[data-tile-count="9"]{
    grid-template-columns:repeat(3, minmax(0, 1fr));
  }
  .vcall-shell.group-mode.host-layout .vcall-remote-grid[data-tile-count="10"],
  .vcall-shell.group-mode.host-layout .vcall-remote-grid[data-tile-count="11"],
  .vcall-shell.group-mode.host-layout .vcall-remote-grid[data-tile-count="12"]{
    grid-template-columns:repeat(4, minmax(0, 1fr));
  }
  .vcall-shell.group-mode.pinned-host-layout .vcall-remote-grid{
    grid-auto-rows:auto;
    align-content:center;
    justify-content:center;
    place-items:center;
  }
  .vcall-shell.group-mode.pinned-host-layout .vcall-remote-grid[data-primary-count="1"]{
    grid-template-columns:minmax(0, 1fr);
    grid-template-rows:minmax(0, 1fr);
  }
  .vcall-shell.group-mode.pinned-host-layout .vcall-remote-grid[data-primary-count="2"]{
    grid-template-columns:minmax(0, 1fr);
    grid-template-rows:repeat(2, minmax(0, 1fr));
  }
  .vcall-shell.group-mode.pinned-host-layout .vcall-remote-grid[data-primary-count="3"]{
    grid-template-columns:repeat(2, minmax(0, 1fr));
    grid-template-rows:repeat(2, minmax(0, 1fr));
  }
  .vcall-shell.group-mode.pinned-host-layout .vcall-remote-grid[data-primary-count="3"] .vcall-primary-1{
    grid-column:1 / -1;
  }
  .vcall-remote-tile{
    position:relative;
    min-height:180px;
    border-radius:18px;
    overflow:hidden;
    background:rgba(8,14,24,.72);
    border:1px solid rgba(255,255,255,.08);
    box-shadow:0 18px 44px rgba(0,0,0,.22);
  }
  .vcall-shell.group-mode.host-layout .vcall-remote-tile{
    width:100%;
    aspect-ratio:1 / 1;
    min-height:0;
    height:auto;
    border-radius:8px;
    background:#004538;
    border:2px solid rgba(255,255,255,.28);
    box-shadow:0 16px 36px rgba(0,0,0,.18);
  }
  .vcall-shell.group-mode.host-layout .vcall-remote-tile:first-child{
    grid-column:auto;
    grid-row:auto;
    min-height:0;
  }
  .vcall-shell.group-mode.host-layout .vcall-remote-tile:nth-child(n+2){
    grid-row:auto;
    height:auto;
  }
  .vcall-shell.group-mode .vcall-local-tile .vcall-local{
    position:absolute;
    inset:0;
    width:100%;
    max-width:none;
    height:100%;
    aspect-ratio:auto;
    border:0;
    border-radius:0;
    box-shadow:none;
    z-index:0;
    object-fit:contain;
  }
  .vcall-shell.group-mode.pinned-host-layout .vcall-host-tile{
    position:absolute;
    right:18px;
    bottom:18px;
    width:min(30vw, 190px);
    height:auto;
    aspect-ratio:1 / 1;
    min-height:0;
    z-index:7;
    border:2px solid rgba(255,255,255,.36);
    box-shadow:0 18px 46px rgba(0,0,0,.38);
  }
  .vcall-shell.group-mode.pinned-host-layout .vcall-primary-tile{
    width:min(100%, calc(100dvh - 260px));
    max-width:100%;
  }
  .vcall-shell.group-mode.pinned-host-layout .vcall-remote-grid[data-primary-count="2"] .vcall-primary-tile,
  .vcall-shell.group-mode.pinned-host-layout .vcall-remote-grid[data-primary-count="3"] .vcall-primary-tile{
    width:min(100%, calc((100dvh - 280px) / 2));
  }
  .vcall-remote-tile video{
    position:absolute;
    inset:0;
    width:100%;
    height:100%;
    object-fit:cover;
    background:#0b0f16;
  }
  .vcall-remote-snapshot{
    position:absolute;
    inset:0;
    width:100%;
    height:100%;
    object-fit:contain;
    background:#0b0f16;
    display:none;
  }
  .vcall-remote-tile.is-voice video{
    display:none;
  }
  .vcall-remote-card{
    position:absolute;
    inset:0;
    display:flex;
    flex-direction:column;
    align-items:center;
    justify-content:center;
    gap:12px;
    padding:18px;
    text-align:center;
    background:
      radial-gradient(420px 220px at 50% 18%, rgba(59,130,246,.16), transparent 60%),
      linear-gradient(180deg, rgba(11,22,38,.96), rgba(7,13,24,.98));
  }
  .vcall-remote-avatar{
    width:72px;
    height:72px;
    border-radius:999px;
    overflow:hidden;
    border:2px solid rgba(255,255,255,.18);
    box-shadow:0 14px 30px rgba(0,0,0,.24);
    background:rgba(255,255,255,.08);
  }
  .vcall-remote-avatar img{
    width:100%;
    height:100%;
    object-fit:cover;
    display:block;
  }
  .vcall-remote-name{
    font-size:15px;
    font-weight:800;
    color:#f8fafc;
  }
  .vcall-remote-status{
    font-size:12px;
    font-weight:700;
    color:rgba(255,255,255,.7);
  }
  .vcall-remote-tile.has-video .vcall-remote-card{
    opacity:0;
    pointer-events:none;
  }
  .vcall-shell.group-mode.host-layout .vcall-remote-tile.has-video .vcall-remote-card{
    background:linear-gradient(180deg, rgba(15,23,42,0) 0%, rgba(0,0,0,.42) 100%);
    opacity:1;
    justify-content:flex-end;
    align-items:flex-start;
    gap:4px;
    padding:8px;
    text-align:left;
  }
  .vcall-shell.group-mode.host-layout .vcall-remote-tile.has-video .vcall-remote-avatar{
    display:none;
  }
  .vcall-shell.group-mode.host-layout .vcall-remote-tile.has-video .vcall-remote-status{
    display:none;
  }
  .vcall-shell.group-mode.host-layout .vcall-remote-tile.has-video .vcall-remote-name{
    padding:4px 7px;
    border-radius:4px;
    background:rgba(0,0,0,.55);
    font-size:16px;
    line-height:1;
    font-weight:800;
    text-shadow:0 2px 10px rgba(0,0,0,.45);
  }
  .vcall-local{
    position:absolute;
    inset:auto 20px 96px auto;
    width:min(19vw, 210px);
    max-width:30%;
    aspect-ratio:16 / 10;
    border-radius:20px;
    border:2px solid rgba(255,255,255,.16);
    box-shadow:0 18px 40px rgba(0,0,0,.32);
    z-index:3;
  }
  .vcall-stage-top{
    position:absolute;
    inset:16px 16px auto 16px;
    z-index:5;
    display:flex;
    align-items:flex-start;
    justify-content:space-between;
    gap:14px;
  }
  .vcall-stage-left{
    display:flex;
    align-items:flex-start;
    gap:10px;
    flex-wrap:wrap;
  }
  .vcall-timer-chip,
  .vcall-stage-chip{
    display:inline-flex;
    align-items:center;
    gap:8px;
    padding:8px 12px;
    border-radius:999px;
    background:rgba(14,18,25,.72);
    border:1px solid rgba(255,255,255,.08);
    box-shadow:0 8px 26px rgba(0,0,0,.22);
    backdrop-filter:blur(8px);
    font-size:12px;
    font-weight:700;
    color:#f8fafc;
  }
  .vcall-stage-name{
    display:inline-flex;
    align-items:center;
    gap:8px;
    padding:7px 10px;
    border-radius:999px;
    background:rgba(14,18,25,.72);
    border:1px solid rgba(255,255,255,.08);
    font-size:12px;
    font-weight:700;
    color:#f8fafc;
    backdrop-filter:blur(8px);
  }
  .vcall-stage-actions{
    display:flex;
    align-items:center;
    gap:8px;
  }
  .vcall-stage-action{
    width:32px;
    height:32px;
    border:0;
    border-radius:10px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    background:rgba(14,18,25,.72);
    color:#fff;
    font-size:13px;
    border:1px solid rgba(255,255,255,.08);
    box-shadow:0 8px 24px rgba(0,0,0,.22);
  }
  .vcall-stage-local-label{
    position:absolute;
    right:28px;
    bottom:316px;
    z-index:5;
    padding:6px 10px;
    border-radius:999px;
    background:rgba(7,11,18,.58);
    border:1px solid rgba(255,255,255,.08);
    font-size:12px;
    font-weight:700;
    color:#f8fafc;
    backdrop-filter:blur(8px);
  }
  .vcall-empty{
    position:absolute;
    inset:0;
    display:flex;
    align-items:center;
    justify-content:center;
    pointer-events:none;
    padding:34px;
    text-align:center;
  }
  .vcall-empty-card{
    display:flex;
    flex-direction:column;
    align-items:center;
    gap:14px;
    max-width:340px;
    color:#fff;
  }
  .vcall-empty-avatar{
    width:92px;
    height:92px;
    border-radius:999px;
    overflow:hidden;
    border:3px solid rgba(255,255,255,.2);
    box-shadow:0 18px 36px rgba(0,0,0,.3);
    background:rgba(255,255,255,.08);
  }
  .vcall-empty-avatar img{
    width:100%;
    height:100%;
    object-fit:cover;
    display:block;
  }
  .vcall-empty-title{
    font-size:34px;
    font-weight:800;
    letter-spacing:-.04em;
  }
  .vcall-empty-text{
    font-size:15px;
    color:rgba(255,255,255,.68);
    font-weight:500;
  }
  .vcall-controls{
    position:absolute;
    left:50%;
    bottom:22px;
    transform:translateX(-50%);
    z-index:6;
    display:flex;
    align-items:center;
    justify-content:center;
    gap:14px;
    padding:10px 18px;
    border-radius:999px;
    background:rgba(9,15,24,.9);
    border:1px solid rgba(255,255,255,.08);
    box-shadow:0 18px 40px rgba(0,0,0,.28);
    backdrop-filter:blur(10px);
  }
  .vcall-control-item{
    display:inline-flex;
    align-items:center;
    justify-content:center;
  }
  .vcall-control-item span{
    display:none;
  }
  .vcall-icon-btn,
  .vcall-end-btn{
    border:0;
    border-radius:50px;
    width:48px;
    height:48px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    font-size:18px;
    color:#fff;
    background:#111d31;
    box-shadow:0 10px 26px rgba(0,0,0,.22);
  }
  .vcall-icon-btn.is-off{
    background:#243246;
    color:#b7c6d9;
  }
  .vcall-end-btn{
    background:#ff2323;
    color:#fff;
    transform:rotate(135deg);
  }
  .vcall-shell.group-mode .vcall-controls{
    bottom:-96px;
    gap:18px;
    padding:0;
    border-radius:0;
    background:transparent;
    border:0;
    box-shadow:none;
    backdrop-filter:none;
  }
  .vcall-shell.group-mode .vcall-control-item{
    display:flex;
    flex-direction:column;
    align-items:center;
    gap:10px;
    color:#fff;
    font-size:17px;
    font-weight:800;
    letter-spacing:-.02em;
  }
  .vcall-shell.group-mode .vcall-control-item span{
    display:block;
  }
  .vcall-shell.group-mode .vcall-icon-btn,
  .vcall-shell.group-mode .vcall-end-btn{
    width:66px;
    height:66px;
    border-radius:14px;
    background:#003d37;
    color:#fff;
    font-size:27px;
    box-shadow:0 13px 28px rgba(0,0,0,.22);
  }
  .vcall-shell.group-mode .vcall-icon-btn.is-off{
    background:#0d544a;
    color:#cfe4df;
  }
  .vcall-shell.group-mode .vcall-end-btn{
    background:#003d37;
    color:#ff866c;
    transform:none;
  }
  .vcall-incoming{
    position:absolute;
    left:50%;
    top:50%;
    transform:translate(-50%, -50%);
    width:min(420px, calc(100% - 40px));
    background:rgba(15,23,42,.84);
    border:1px solid rgba(255,255,255,.10);
    border-radius:24px;
    padding:22px;
    text-align:center;
    z-index:3;
    box-shadow:0 25px 70px rgba(0,0,0,.38);
  }
  .vcall-incoming-text{font-size:24px;font-weight:900;margin-bottom:16px;}
  .vcall-actions{
    display:flex;
    align-items:center;
    justify-content:center;
    gap:12px;
  }
  .vcall-btn{
    border:0;
    border-radius:999px;
    padding:12px 20px;
    font-weight:900;
  }
  .vcall-btn.accept{background:#22c55e;color:#04130a;}
  .vcall-btn.decline{background:#ef4444;color:#fff;}
  .vcall-join-requests{
    position:absolute;
    left:24px;
    right:24px;
    top:72px;
    z-index:5;
    display:none;
    flex-direction:column;
    gap:10px;
    max-width:420px;
  }
  .vcall-join-request{
    display:flex;
    align-items:center;
    gap:10px;
    padding:10px 12px;
    border-radius:16px;
    background:rgba(4,7,12,.86);
    border:1px solid rgba(255,255,255,.08);
    color:#fff;
    box-shadow:0 14px 35px rgba(0,0,0,.26);
  }
  .vcall-join-request-name{
    font-size:13px;
    font-weight:900;
    min-width:0;
    flex:1;
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis;
  }
  .vcall-join-request button{
    width:32px;
    height:32px;
    border:0;
    border-radius:999px;
    color:#fff;
    font-weight:900;
    cursor:pointer;
    display:inline-flex;
    align-items:center;
    justify-content:center;
  }
  .vcall-join-request .deny{background:#ef4444;}
  .vcall-join-request .accept{background:#22c55e;color:#04130a;}
  .vcall-sidebar{
    display:flex;
    flex-direction:column;
    min-height:0;
    background:#ffffff;
    color:#1f2937;
    border-left:1px solid rgba(15,23,42,.08);
    /* background:#f6f8fb; */
    /* color:#1f2937; */
    /* border-left:1px solid rgba(8,16,30,.06); */
  }
  .vcall-body.is-sidebar-collapsed .vcall-sidebar{
    display:none;
  }
  .vcall-sidebar-head{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:10px;
    padding:16px 16px 12px;
  }
  .vcall-sidebar-title{
    font-size:18px;
    font-weight:800;
    letter-spacing:-.03em;
  }
  .vcall-sidebar-subtitle{
    padding:0 16px 10px;
    font-size:12px;
    color:#94a3b8;
    font-weight:700;
  }
  .vcall-sidebar-close{
    width:28px;
    height:28px;
    border:0;
    border-radius:8px;
    background:#eef2f7;
    /* color:#475569; */
    display:inline-flex;
    align-items:center;
    justify-content:center;
  }
  .vcall-tabs{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:6px;
    margin:0 16px 14px;
    padding:4px;
    border-radius:12px;
    background:#edf1f6;
  }
  .vcall-tab{
    border:0;
    border-radius:9px;
    padding:8px 10px;
    font-size:12px;
    font-weight:700;
    color:#475569;
    background:transparent;
  }
  .vcall-tab.active{
    background:#fff;
    color:#2580ff;
    box-shadow:0 4px 14px rgba(37,128,255,.14);
  }
  .vcall-tabs.single-tab{
    grid-template-columns:1fr;
  }
  .vcall-chat{
    flex:1 1 auto;
    min-height:0;
    overflow:auto;
    padding:10px 16px 16px;
  }
  .vcall-chat-group{
    display:flex;
    flex-direction:column;
    gap:12px;
  }
  .vcall-chat-row{
    display:flex;
    align-items:flex-end;
    gap:8px;
  }
  .vcall-chat-row.me{
    justify-content:flex-end;
  }
  .vcall-chat-avatar{
    width:28px;
    height:28px;
    border-radius:999px;
    overflow:hidden;
    flex:0 0 28px;
    background:#dbe5f4;
  }
  .vcall-chat-avatar img{
    width:100%;
    height:100%;
    object-fit:cover;
    display:block;
  }
  .vcall-chat-bubble{
    max-width:82%;
    padding:10px 12px;
    border-radius:16px;
    background:#fff;
    color:#334155;
    font-size:12px;
    line-height:1.45;
    box-shadow:0 10px 26px rgba(15,23,42,.06);
  }
  .vcall-chat-row.me .vcall-chat-bubble{
    background:#2e84ff;
    color:#fff;
  }
  .vcall-chat-meta{
    display:block;
    margin-top:5px;
    font-size:10px;
    opacity:.64;
    font-weight:700;
  }
  .vcall-compose{
    padding:1px 1px 1px;
    border-top:1px solid rgba(15,23,42,.08);
    background:#fff;
  }
  .vcall-compose-box{
    display:flex;
    align-items:flex-end;
    gap:2px;
    padding:10px 12px;
    background:#f2f5fa;
  }
  .vcall-compose-box textarea{
    flex:1 1 auto;
    border:0;
    background:transparent;
    outline:none;
    font-size:13px;
    color:#334155;
    min-height:38px;
    max-height:110px;
    resize:none;
    overflow-y:auto;
    line-height:1.4;
    padding:8px 0;
  }
  .vcall-compose-send{
    width:30px;
    height:30px;
    border:0;
    border-radius:10px;
    background:#2580ff;
    color:#fff;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    font-size:13px;
  }

  .gif-picker-overlay{
    position:fixed;
    inset:0;
    z-index:1000001;
    display:none;
    align-items:center;
    justify-content:center;
    background:rgba(4,6,10,.72);
    backdrop-filter:blur(10px);
    padding:18px;
  }
  .gif-picker{
    width:min(560px, 96vw);
    height:min(760px, 88vh);
    background:linear-gradient(180deg, rgba(31,33,37,.98), rgba(20,21,26,.98));
    border:1px solid rgba(255,255,255,.08);
    border-radius:24px;
    box-shadow:0 30px 90px rgba(0,0,0,.42);
    overflow:hidden;
    display:flex;
    flex-direction:column;
  }
  .gif-picker-head{
    padding:16px 16px 12px;
    border-bottom:1px solid rgba(255,255,255,.06);
  }
  .gif-search{
    display:flex;
    align-items:center;
    gap:10px;
    background:rgba(255,255,255,.08);
    border-radius:999px;
    height:58px;
    padding:0 18px;
    color:rgba(255,255,255,.62);
  }
  .gif-search i{font-size:18px;}
  .gif-search input{
    flex:1 1 auto;
    border:0;
    background:transparent;
    color:#fff;
    font-size:16px;
    outline:none;
  }
  .gif-search input::placeholder{color:rgba(255,255,255,.55);}
  .gif-grid{
    flex:1 1 auto;
    min-height:0;
    overflow:auto;
    padding:8px;
    display:grid;
    grid-template-columns:repeat(2, minmax(0, 1fr));
    gap:8px;
  }
  .gif-card{
    position:relative;
    border:0;
    background:#0f1014;
    border-radius:0;
    overflow:hidden;
    padding:0;
    aspect-ratio: 1 / 1;
    cursor:pointer;
  }
  .gif-card img{
    width:100%;
    height:100%;
    object-fit:cover;
    display:block;
  }
  .gif-card span{
    position:absolute;
    left:0;
    right:0;
    bottom:0;
    padding:10px 10px 12px;
    color:#fff;
    font-weight:900;
    font-size:12px;
    letter-spacing:.04em;
    text-transform:uppercase;
    background:linear-gradient(to top, rgba(0,0,0,.78), rgba(0,0,0,0));
    text-shadow:0 2px 12px rgba(0,0,0,.5);
  }
  .gif-picker-empty{
    padding:24px;
    color:rgba(255,255,255,.62);
    text-align:center;
    font-weight:700;
  }

  @media (max-width: 991.98px){
    #videoCallOverlay{padding:0;}
    .vcall-shell{
      width:100%;
      height:100dvh;
      border-radius:0;
    }
    .vcall-header{padding:12px 14px;}
    .vcall-body{grid-template-columns:minmax(0, 1fr);}
    .vcall-sidebar{display:none;}
    .vcall-stage-wrap{padding:10px;}
    .vcall-stage{border-radius:18px;}
    .vcall-name{font-size:18px;}
    .vcall-clock{font-size:11px;min-width:auto;}
    .vcall-local{
      right:14px;
      bottom:92px;
      width:min(34vw, 150px);
      max-width:42%;
      border-radius:16px;
    }
    .vcall-shell.group-mode.host-layout .vcall-remote-grid[data-tile-count="10"],
    .vcall-shell.group-mode.host-layout .vcall-remote-grid[data-tile-count="11"],
    .vcall-shell.group-mode.host-layout .vcall-remote-grid[data-tile-count="12"]{
      grid-template-columns:repeat(3, minmax(0, 1fr));
    }
    .vcall-shell.group-mode.pinned-host-layout .vcall-host-tile{
      right:14px;
      bottom:104px;
      width:min(36vw, 150px);
      height:clamp(82px, 18vh, 112px);
    }
    .vcall-stage-local-label{
      right:22px;
      bottom:252px;
      font-size:11px;
    }
    .vcall-empty-title{font-size:26px;}
    .vcall-reaction-bar{display:none;}
    .vcall-controls{
      gap:10px;
      bottom:16px;
      padding:8px 12px;
    }
    .vcall-icon-btn,.vcall-end-btn{width:44px;height:44px;font-size:16px;border-radius:12px;}
    .gif-picker{
      width:100%;
      height:min(82vh, 720px);
      border-radius:20px;
    }
    .gif-search{height:52px;}
  }

  a:not([href]):not([tabindex]) {
    /* color: #eef1f5; */
    text-decoration: none;
  }

  .btn-primary {
    /* color: #fff; */
    background-color: #0866C6;
    border-color: #0866C6;
  }

  a {
  /* color: #0866C6; */
  text-decoration: none;
  background-color: transparent;
  -webkit-text-decoration-skip: objects;
  }
  a:hover {
    color: #1a69b9;
    text-decoration: none;
  }
</style>

</head>

<body>
<?php $forceFeedRail = true; include __DIR__ . '/includes/header.php'; ?>
  <!-- <div class="sh-pagetitle">
    <div class="input-group"></div>
      <div class="sh-pagetitle-left">
        <div class="sh-pagetitle-icon"><i class="icon ion-ios-chatbubble"></i></div>
        <div class="sh-pagetitle-title">
          <span>Messages</span>
          <h2>Chat</h2>
        </div>
      </div>
  </div> -->


<div class="sh-mainpanel">
  <div class="sh-pagebody">
    <div class="messages-shell">
      <div class="messages-shell-head">
        <div class="messages-shell-title">Message</div>
        <div class="messages-shell-tools">
          <div class="messages-shell-tabs" aria-label="Chat type tabs">
            <a href="<?php echo h($privateChatUrl); ?>" class="messages-shell-tab <?php echo !$isGroupChatView ? 'active' : ''; ?>">Private Chat</a>
            <a href="<?php echo h($groupChatUrl); ?>" class="messages-shell-tab <?php echo $isGroupChatView ? 'active' : ''; ?>">Group Chat</a>
          </div>
          <?php if ($isGroupChatView): ?>
            <button type="button" class="messages-shell-action-btn" id="openGroupCreateBtn">Create Group Name</button>
          <?php endif; ?>
          <span class="messages-shell-icon" aria-hidden="true"><i class="fa fa-bell"></i></span>
          <span class="messages-shell-avatar">
            <img src="avatar.php?friend_code=<?php echo urlencode((string)$meCode); ?>&name=<?php echo urlencode((string)$meDisplay); ?>" alt="Your avatar">
          </span>
        </div>
      </div>
    <div class="row row-sm chat-layout">

      <!-- LEFT -->
      <div class="col-12 col-sm-4 col-md-4 col-lg-3 chat-left-col">
        <div class="chat-card">
          <div class="chat-left-head">
            <div class="chat-left-head-row">
              <div class="chat-left-title">Chats</div>
              <div class="chat-left-tools">
                <button type="button" class="chat-round-btn" aria-label="More"><i class="fa fa-ellipsis-h"></i></button>
                <button type="button" class="chat-round-btn" aria-label="Compose"><i class="fa fa-edit"></i></button>
              </div>
            </div>
            <div class="chat-search-wrap">
              <i class="fa fa-search"></i>
              <input id="chatSearch" type="text" class="form-control" placeholder="Search Messenger" autocomplete="off">
            </div>
            <div class="chat-filter-tabs" aria-label="Filters">
              <button type="button" class="chat-filter-tab active">All</button>
              <button type="button" class="chat-filter-tab">Unread</button>
              <button type="button" class="chat-filter-tab">Groups</button>
              <button type="button" class="chat-filter-tab">Communities</button>
            </div>
          </div>
          <div class="chat-card-body">
            <div class="chat-list" id="chatList">
              <div id="noChatsMatch" style="display:none;padding:10px 12px;color:var(--muted);">No chats match your search.</div>
              <?php if ($isGroupChatView): ?>
                <?php if (!empty($groupThreads)): ?>
                  <?php foreach ($groupThreads as $groupThread): ?>
                    <?php
                      $groupThreadId = (int)($groupThread['id'] ?? 0);
                      $groupThreadName = trim((string)($groupThread['name'] ?? 'Untitled Group'));
                      $groupThreadMembers = (int)($groupThread['member_count'] ?? 0);
                      $groupThreadRole = trim((string)($groupThread['my_role'] ?? 'member'));
                      $groupActive = ($selectedGroup && (int)($selectedGroup['id'] ?? 0) === $groupThreadId);
                      $groupAvatarName = $groupThreadName !== '' ? $groupThreadName : 'Group';
                      $groupAvatarStyle = avatar_gradient_style(color_from_string(normalize_avatar_key($groupAvatarName)));
                    ?>
                    <a
                      href="messages.php?chat_type=group&amp;group_id=<?php echo $groupThreadId; ?>"
                      class="chat-item chatItem <?php echo $groupActive ? 'active' : ''; ?>"
                      data-name="<?php echo h(mb_strtolower($groupThreadName)); ?>"
                      data-code="<?php echo h((string)$groupThreadId); ?>"
                      data-lastmsg="<?php echo h(mb_strtolower($groupThreadRole . ' ' . $groupThreadMembers . ' members')); ?>"
                    >
                      <div class="chat-left">
                        <div class="avatar" style="<?php echo h($groupAvatarStyle); ?>">
                          <img src="avatar.php?friend_code=<?php echo urlencode('GROUP-' . (string)$groupThreadId); ?>&name=<?php echo urlencode($groupAvatarName); ?>" alt="Group avatar">
                        </div>
                        <div style="min-width:0;">
                          <div class="chat-name chatName"><?php echo h($groupThreadName); ?></div>
                          <div class="chat-meta">
                            <div class="chatLastMsg"><?php echo h($groupThreadMembers . ' member' . ($groupThreadMembers === 1 ? '' : 's')); ?></div>
                          </div>
                        </div>
                      </div>
                      <div class="chat-item-right">
                        <span style="font-size:11px;font-weight:800;color:var(--msg-muted);text-transform:capitalize;"><?php echo h($groupThreadRole); ?></span>
                      </div>
                    </a>
                  <?php endforeach; ?>
                <?php else: ?>
                  <div class="group-list-empty">No group chat yet. Create one on the right.</div>
                <?php endif; ?>
              <?php else: ?>
	              <?php foreach ($threads as $t):
	                $tPeerKey   = strtoupper((string)($t['peer_key'] ?? ''));
	                $tDisp      = (string)($t['peer_display'] ?? $tPeerKey);
	                $tLastMsg   = (string)($t['last_message'] ?? '');
	                $tReplyBits = parse_reply_payload($tLastMsg);
	                $tLastRawText = (string)($tReplyBits['text'] ?? '');
	                $tLastSender = strtoupper(trim((string)($t['last_sender'] ?? '')));
	                $isUnknown  = (int)($t['is_unknown'] ?? 1);
	                $tLastTime  = (string)($t['last_time'] ?? '');
	                $tUnread    = (int)($t['unread_count'] ?? 0);
	                $isActive   = ($peerRaw !== '' && strtoupper($peerRaw) === $tPeerKey);
	                $lastTs     = $tLastTime ? (int)strtotime($tLastTime) : 0;
	                $isMyLastMessage = (
	                  $tLastSender !== '' && (
	                    $tLastSender === strtoupper($meCode) ||
	                    ($meEmail !== '' && $tLastSender === strtoupper($meEmail))
	                  )
	                );
	                $tLastMsg = call_event_display_text($tLastRawText, $isMyLastMessage);
	                $tIsCallEventPreview = trim($tLastRawText) !== trim($tLastMsg);
	                $tPreview = trim($tLastMsg) !== '' ? $tLastMsg : 'Sent an attachment.';
	                $tTimeShort = fmt_thread_time($tLastTime);

                $ageSec = (int)($t['peer_age_seconds'] ?? 999999);
                $isOnline = ($ageSec >= 0 && $ageSec <= 300);
                $tNameForAvatar = (string)($tDisp !== '' ? $tDisp : $tPeerKey);
                                $peerKey = normalize_avatar_key($tNameForAvatar);
                                if ($peerKey === '') $peerKey = normalize_avatar_key((string)$tPeerKey);
                                $avatarText = initials_from_name($tNameForAvatar, 'U');
                                $avatarStyle = avatar_gradient_style(color_from_string($peerKey));
              ?>
                <a
                  href="messages.php?peer=<?php echo urlencode($tPeerKey); ?>"
                  class="chat-item chatItem <?php echo $isActive ? 'active' : ''; ?>"
                  data-key="<?php echo h($tPeerKey); ?>"
                  data-name="<?php echo h(mb_strtolower($tDisp)); ?>"
                  data-code="<?php echo h(mb_strtolower($tPeerKey)); ?>"
                  data-lastmsg="<?php echo h(mb_strtolower($tLastMsg)); ?>"
                  data-lastts="<?php echo (int)$lastTs; ?>"
                  data-orig-name="<?php echo h($tDisp); ?>"
                  data-orig-code="<?php echo h($tPeerKey); ?>"
                  data-orig-lastmsg="<?php echo h($tLastMsg); ?>"
                >
                  <div class="chat-left">
                    <div class="avatar" style="<?php echo h($avatarStyle); ?>" title="<?php echo $isOnline ? 'Online' : 'Offline'; ?>">
                      <img src="avatar.php?friend_code=<?php echo urlencode($tPeerKey); ?>&name=<?php echo urlencode($tNameForAvatar); ?>" data-live-avatar="1" data-avatar-base="avatar.php?friend_code=<?php echo urlencode($tPeerKey); ?>&name=<?php echo urlencode($tNameForAvatar); ?>" alt="Avatar">
                      <span class="presenceDot <?php echo $isOnline ? 'on' : ''; ?>"></span>
                    </div>

                    <div style="min-width:0;">
                      <div class="chat-name chatName"><?php echo h($tDisp); ?></div>
                      <div class="chat-meta">
                        <!-- <span class="chatCode"><?php echo h($tPeerKey); ?></span> -->
	                          <div class="chatLastMsg chatLastMsg" style="display:flex;align-items:center;gap:6px;min-width:0;">
	                            <span style="min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
	                              <?php if ($isMyLastMessage && !$tIsCallEventPreview): ?>You: <?php endif; ?><?php echo h($tPreview); ?>
	                            </span>
                            <?php if ($tLastTime): ?>
                              <span style="opacity:.6;">•</span>
                              <span class="chatLastTime" title="<?php echo h(fmt_time_full($tLastTime)); ?>">
                                <?php echo h($tTimeShort); ?>
                              </span>
                            <?php endif; ?>
                          </div>
                        <?php if ($isUnknown === 1): ?>
                          <span style="margin-left:6px;padding:2px 8px;border-radius:999px;background:rgba(17,24,39,.06);font-weight:800;font-size:11px;">Unknown</span>
                        <?php endif; ?>
                        
                      </div>
                      
                    </div>
                  </div>

                  <div class="chat-item-right">
                    <?php if ($tUnread > 0): ?>
                      <span class="unreadBadge"><?php echo h($tUnread > 99 ? '99+' : (string)$tUnread); ?></span>
                    <?php endif; ?>
                    <span class="chat-list-dot <?php echo $tUnread > 0 ? 'is-visible' : ''; ?>"></span>
                  </div>
                </a>
              <?php endforeach; ?>

              <?php if (empty($threads)): ?>
                <div style="padding:12px;color:var(--muted);">No chats yet.</div>
              <?php endif; ?>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>

      <!-- RIGHT -->
      <div class="col-12 col-sm-8 col-md-8 col-lg-9 chat-right-col">
        <div class="chat-card-right" id="conversationPanel">
          <div class="chat-stage<?php echo ($peerRow || ($isGroupChatView && $selectedGroup)) ? ' is-info-collapsed' : ''; ?>" id="chatStage">
          <div class="chat-main-pane">
          <div class="chat-topbar">
            <div style="min-width:0;">
              <div class="peerTitle" id="peerTitle">
                <?php if ($isGroupChatView): ?>
                  <?php if ($selectedGroup): ?>
                    <?php
                      $groupTitleName = (string)($selectedGroup['name'] ?? 'Group Chat');
                      $groupTitleStyle = avatar_gradient_style(color_from_string(normalize_avatar_key($groupTitleName)));
                    ?>
                    <span class="peerAvatar" style="<?php echo h($groupTitleStyle); ?>">
                      <img src="avatar.php?friend_code=<?php echo urlencode('GROUP-' . (string)($selectedGroup['id'] ?? 0)); ?>&name=<?php echo urlencode($groupTitleName); ?>" alt="Group avatar">
                    </span>
                    <span style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?php echo h($groupTitleName); ?></span>
                  <?php else: ?>
                    Group Chat
                  <?php endif; ?>
                  <span class="peerOnlineDot"></span>
                <?php elseif ($peerRow): ?>
                  <?php
                        $peerKey = normalize_avatar_key((string)$peerDisplay);
                        if ($peerKey === '') $peerKey = normalize_avatar_key((string)($peerCode ?? ''));
                        if ($peerKey === '') $peerKey = normalize_avatar_key((string)($peerEmail ?? ''));
                        $peerInit = initials_from_name((string)$peerDisplay, (string)($peerCode ?? 'U'));
                        $peerAvStyle = avatar_gradient_style(color_from_string($peerKey));
                      ?>
                  <span class="peerAvatar" style="<?php echo h($peerAvStyle); ?>"><img src="avatar.php?friend_code=<?php echo urlencode((string)$peerCode); ?>&name=<?php echo urlencode((string)$peerDisplay); ?>" data-live-avatar="1" data-avatar-base="avatar.php?friend_code=<?php echo urlencode((string)$peerCode); ?>&name=<?php echo urlencode((string)$peerDisplay); ?>" alt="Avatar"></span>
                  <span style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?php echo h($peerDisplay); ?></span>
                  <span class="peerOnlineDot <?php echo (!empty($peerOnlineInfo['online'])) ? 'on' : ''; ?>"></span>
                <?php else: ?>
                  Select a chat
                  <span class="peerOnlineDot"></span>
                <?php endif; ?>
              </div>
              <div class="peerSub">
                <span><?php echo $isGroupChatView ? ($selectedGroup ? h(count($groupMembers) . ' member' . (count($groupMembers) === 1 ? '' : 's')) : 'Create and manage your group conversations here.') : ($peerRow ? h($peerCode) : ''); ?></span>
                <?php if ($isGroupChatView && $selectedGroup): ?>
                  <span style="opacity:.55;">•</span>
                  <span style="text-transform:capitalize;"><?php echo h((string)($selectedGroup['my_role'] ?? 'member')); ?></span>
                <?php endif; ?>
                <?php if ($peerRow && !$isGroupChatView): ?>
                  <span style="opacity:.55;">•</span>
                  <span id="peerOnlineBadge"><?php echo h($peerOnlineInfo['online'] ? 'Online' : $peerOnlineInfo['label']); ?></span>
                <?php endif; ?>
              </div>
            </div>

            <div class="iconbar" style="display:flex;gap:6px;">
              <?php if ($peerRow || ($isGroupChatView && $selectedGroup)): ?>
                <a href="javascript:void(0)" id="btnVoiceCall" title="Call"><i class="ion ion-ios-telephone"></i></a>
                <a href="javascript:void(0)" id="btnVideoCall" title="Video"><i class="ion ion-ios-videocam"></i></a>
              <?php endif; ?>
              <?php if ($peerRow || ($isGroupChatView && $selectedGroup)): ?>
                <a type="button" id="peerInfoToggle" class="chat-info-toggle" title="<?php echo h($isGroupChatView && $selectedGroup ? 'Group details' : 'Peer details'); ?>" aria-label="<?php echo h($isGroupChatView && $selectedGroup ? 'Open group details' : 'Open peer details'); ?>" aria-expanded="false">
                  <i class="icon ion-information-circled"></i>
                </a>
              <?php endif; ?>
            </div>
          </div>

          <div id="chatBox">
            <?php if ($isGroupChatView): ?>
              <?php if ($groupNotice !== '' && $groupNoticeType !== ''): ?>
                <div class="group-notice <?php echo h($groupNoticeType); ?>"><?php echo h($groupNotice); ?></div>
              <?php endif; ?>
              <?php if ($selectedGroup): ?>
                <div id="chatStream">
                  <?php if (!empty($groupMessages)): ?>
                    <?php
                      $lastGroupDayKey = '';
                      foreach ($groupMessages as $groupMessage):
                        $groupMsgId = (int)($groupMessage['id'] ?? 0);
                        $groupMsgDt = (string)($groupMessage['created_at'] ?? '');
                        $groupMsgDayKey = $groupMsgDt ? day_key($groupMsgDt) : '';
                        if ($groupMsgDayKey !== '' && $groupMsgDayKey !== $lastGroupDayKey):
                          $lastGroupDayKey = $groupMsgDayKey;
                    ?>
                      <div class="day-divider dayDivider" data-day="<?php echo h($groupMsgDayKey); ?>"><?php echo h(day_label($groupMsgDt)); ?></div>
                    <?php endif; ?>
                    <?php
                        $groupSenderId = (int)($groupMessage['sender_user_id'] ?? 0);
                        $groupIsMe = ($groupSenderId === $meId);
                        $groupSenderName = (string)($groupMessage['sender_name'] ?? ($groupIsMe ? $meDisplay : 'Member'));
                        $groupSenderCode = (string)($groupMessage['friend_code'] ?? '');
                        $groupReplyBits = parse_reply_payload((string)($groupMessage['body'] ?? ''));
                        $groupBodyText = (string)($groupReplyBits['text'] ?? '');
                        $groupReplyAuthor = (string)($groupReplyBits['reply_author'] ?? '');
                        $groupReplyText = (string)($groupReplyBits['reply_text'] ?? '');
                        $groupReplyMessageId = (int)($groupReplyBits['reply_message_id'] ?? 0);
                        $groupAttachmentUrl = (string)($groupMessage['attachment_url'] ?? '');
                        $groupAttachmentType = strtolower(trim((string)($groupMessage['attachment_type'] ?? '')));
                        $groupAttachmentOriginal = (string)($groupMessage['attachment_original'] ?? '');
                        if ($groupAttachmentOriginal === '' && $groupAttachmentUrl !== '') {
                          $groupAttachmentOriginal = basename((string)(parse_url($groupAttachmentUrl, PHP_URL_PATH) ?? $groupAttachmentUrl));
                        }
                        if ($groupAttachmentUrl !== '' && is_attachment_placeholder_text($groupBodyText)) {
                          $groupBodyText = '';
                        }
                        $groupAttachmentExt = strtolower(pathinfo(parse_url($groupAttachmentUrl, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));
                        $groupIsImg = $groupAttachmentUrl !== '' && ((strpos($groupAttachmentType, 'image/') === 0) || in_array($groupAttachmentExt, ['png','jpg','jpeg','gif','webp'], true));
                        $groupIsVid = $groupAttachmentUrl !== '' && ((strpos($groupAttachmentType, 'video/') === 0) || in_array($groupAttachmentExt, ['mp4','webm','ogg','mov'], true));
                        $groupIsPdf = $groupAttachmentUrl !== '' && (($groupAttachmentType === 'application/pdf') || ($groupAttachmentExt === 'pdf'));
                        $groupReactions = is_array($groupMessage['reactions'] ?? null)
                          ? array_values(array_filter(array_map('strval', (array)$groupMessage['reactions']), static function (string $value): bool {
                              return trim($value) !== '';
                            }))
                          : [];
                    ?>
                    <div class="msg-row msgRow <?php echo $groupIsMe ? 'me' : ''; ?>" data-id="<?php echo $groupMsgId; ?>" data-day="<?php echo h($groupMsgDayKey); ?>" data-author="<?php echo h($groupIsMe ? $meDisplay : $groupSenderName); ?>">
                      <?php if (!$groupIsMe): ?>
                        <span class="msg-avatar-mini"><img src="avatar.php?friend_code=<?php echo urlencode($groupSenderCode); ?>&name=<?php echo urlencode($groupSenderName); ?>" alt="Avatar"></span>
                        <div class="msg-peer-wrap">
                      <?php endif; ?>
                      <?php if ($groupIsMe): ?>
                        <div class="msg-side-actions left<?php echo ($groupAttachmentUrl !== '') ? ' is-media-actions' : ''; ?>" data-message-id="<?php echo $groupMsgId; ?>">
                          <a type="button" class="msg-side-btn group-msg-dot-btn" data-message-id="<?php echo $groupMsgId; ?>" data-group-id="<?php echo (int)($selectedGroup['id'] ?? 0); ?>" data-is-me="1" aria-label="More actions"><i class="fa fa-ellipsis-v"></i></a>
                          <a type="button" class="msg-side-btn group-msg-reply-btn msg-reply-btn" data-message-id="<?php echo $groupMsgId; ?>" aria-label="Reply"><i class="fa fa-reply"></i></a>
                          <a type="button" class="msg-side-btn group-msg-react-btn msg-react-btn" data-message-id="<?php echo $groupMsgId; ?>" aria-label="React"><i class="fa fa-smile-o"></i></a>
                        </div>
                      <?php endif; ?>
                      <div class="msg-bubble-stack">
                        <div class="bubble <?php echo $groupIsMe ? 'me' : ''; ?> <?php echo $groupAttachmentUrl !== '' ? 'has-media' : ''; ?> <?php echo $groupReplyText !== '' ? 'has-reply' : ''; ?>" data-is-me="<?php echo $groupIsMe ? '1' : '0'; ?>">
                          <?php if ($groupReplyText !== ''): ?>
                            <div class="msg-reply-line" data-reply-target-id="<?php echo (int)$groupReplyMessageId; ?>">
                              <div class="msg-reply-line-title">Replying to <?php echo h($groupReplyAuthor !== '' ? $groupReplyAuthor : ($groupIsMe ? 'yourself' : $groupSenderName)); ?></div>
                              <div class="msg-reply-line-text"><?php echo h($groupReplyText); ?></div>
                            </div>
                          <?php endif; ?>
                          <?php if (trim($groupBodyText) !== ''): ?>
                            <div class="msgText"><?php echo nl2br(h($groupBodyText)); ?></div>
                          <?php endif; ?>
                          <?php if ($groupAttachmentUrl !== ''): ?>
                            <div style="margin-top:6px;">
                              <?php if ($groupIsImg): ?>
                                <a href="javascript:void(0);" class="mediaOpen"
                                  data-url="<?php echo h($groupAttachmentUrl); ?>"
                                  data-type="<?php echo h($groupAttachmentType ?: 'image/*'); ?>"
                                  data-orig="<?php echo h($groupAttachmentOriginal); ?>">
                                  <img src="<?php echo h($groupAttachmentUrl); ?>" alt="<?php echo h($groupAttachmentOriginal); ?>" style="cursor:pointer;">
                                </a>
                              <?php elseif ($groupIsVid): ?>
                                <div class="mediaOpen"
                                  data-url="<?php echo h($groupAttachmentUrl); ?>"
                                  data-type="<?php echo h($groupAttachmentType ?: 'video/mp4'); ?>"
                                  data-orig="<?php echo h($groupAttachmentOriginal); ?>"
                                  style="cursor:pointer;">
                                  <video controls>
                                    <source src="<?php echo h($groupAttachmentUrl); ?>" type="<?php echo h($groupAttachmentType ?: 'video/mp4'); ?>">
                                  </video>
                                </div>
                              <?php elseif ($groupIsPdf): ?>
                                <button type="button" class="btn btn-outline-secondary btn-sm mediaOpen"
                                  data-url="<?php echo h($groupAttachmentUrl); ?>"
                                  data-type="application/pdf"
                                  data-orig="<?php echo h($groupAttachmentOriginal); ?>"
                                  style="border-radius:12px;">View PDF</button>
                                <div style="margin-top:6px;font-size:12px;opacity:.85;"><?php echo h($groupAttachmentOriginal); ?></div>
                              <?php else: ?>
                                <a href="javascript:void(0);" class="mediaOpen"
                                  data-url="<?php echo h($groupAttachmentUrl); ?>"
                                  data-type="<?php echo h($groupAttachmentType); ?>"
                                  data-orig="<?php echo h($groupAttachmentOriginal); ?>"
                                  style="text-decoration:underline;">
                                  <i class="fa fa-paperclip"></i> <?php echo h($groupAttachmentOriginal); ?>
                                </a>
                                <a href="<?php echo h($groupAttachmentUrl); ?>" target="_blank" rel="noopener"
                                  style="font-size:12px;margin-left:8px;text-decoration:underline;opacity:.85;">Open</a>
                              <?php endif; ?>
                            </div>
                          <?php endif; ?>
                          <div class="msg-meta <?php echo $groupIsMe ? 'me' : ''; ?>">
                            <?php echo h($groupIsMe ? $meDisplay : $groupSenderName); ?>
                            <span style="opacity:.6;">•</span>
                            <?php echo h(fmt_time_full($groupMsgDt)); ?>
                          </div>
                        </div>
                        <?php if (!empty($groupReactions)): ?>
                          <div class="msg-reactions">
                            <?php foreach ($groupReactions as $groupReactionEmoji): ?>
                              <span class="msg-reaction-pill"><?php echo h($groupReactionEmoji); ?></span>
                            <?php endforeach; ?>
                          </div>
                        <?php endif; ?>
                      </div>
                      <?php if (!$groupIsMe): ?>
                        <div class="msg-peer-actions<?php echo ($groupAttachmentUrl !== '') ? ' is-media-actions' : ''; ?>" data-message-id="<?php echo $groupMsgId; ?>">
                          <a type="button" class="msg-side-btn group-msg-react-btn msg-react-btn" data-message-id="<?php echo $groupMsgId; ?>" aria-label="React"><i class="fa fa-smile-o"></i></a>
                          <a type="button" class="msg-side-btn group-msg-reply-btn msg-reply-btn" data-message-id="<?php echo $groupMsgId; ?>" aria-label="Reply"><i class="fa fa-reply"></i></a>
                          <a type="button" class="msg-side-btn group-msg-dot-btn" data-message-id="<?php echo $groupMsgId; ?>" data-group-id="<?php echo (int)($selectedGroup['id'] ?? 0); ?>" data-is-me="0" aria-label="More actions"><i class="fa fa-ellipsis-v"></i></a>
                        </div>
                        </div>
                      <?php endif; ?>
                    </div>
                  <?php endforeach; ?>
                  <?php else: ?>
                    <div class="chat-empty-state">
                      <div>
                        <div class="chat-empty-state-title">Group Chat</div>
                        <div class="chat-empty-state-copy">No group messages yet. Send the first message to start chatting together.</div>
                      </div>
                    </div>
                  <?php endif; ?>
                </div>
                <div class="composer">
                  <form method="post" class="group-send-form" enctype="multipart/form-data">
                    <div id="replyPreview" class="reply-preview" aria-hidden="true">
                      <div class="reply-preview-main">
                        <div id="replyPreviewTitle" class="reply-preview-title">Replying</div>
                        <div id="replyPreviewText" class="reply-preview-text"></div>
                      </div>
                      <button type="button" id="replyPreviewClose" class="reply-preview-close" aria-label="Cancel reply"><i class="fa fa-times"></i></button>
                    </div>
                    <div id="msgPreviewInline">
                      <img id="msgPreviewThumb" src="" alt="">
                      <div style="min-width:0;">
                        <div id="msgPreviewName" style="font-size:12px;font-weight:900;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:200px;"></div>
                        <div style="font-size:11px;color:rgba(17,24,39,.60);font-weight:700;">Ready to send</div>
                      </div>
                      <a type="button" id="msgPreviewRemove" class="btn btn-link btn-sm" style="padding:0 6px;">✕</a>
                    </div>
                    <input type="hidden" name="group_action" value="send_group_message">
                    <input type="hidden" name="group_id" value="<?php echo (int)($selectedGroup['id'] ?? 0); ?>">
                    <input type="hidden" id="replyMessageId" name="reply_message_id" value="">
                    <input type="hidden" id="replyPreviewAuthorInput" name="reply_preview_author" value="">
                    <input type="hidden" id="replyPreviewTextInput" name="reply_preview_text" value="">
                    <div class="composer-bar">
                      <div class="composer-left">
                        <button type="button" id="composerMicBtn" class="group-composer-btn" title="Voice"><i class="fa fa-microphone"></i></button>
                      </div>
                      <div class="composer-input-wrap">
                        <textarea id="messageInput" name="group_message" class="group-message-input" placeholder="Type your message here"></textarea>
                      </div>
                      <div class="composer-right">
                        <a type="button" id="msgPlusBtn" class="group-composer-btn" title="Attach"><i class="fa fa-picture-o"></i></a>
                        <a type="button" id="emojiBtn" class="group-composer-btn" title="Emoji"><i class="fa fa-smile-o"></i></a>
                        <button type="button" id="composerLocationBtn" class="group-composer-btn" title="Location"><i class="fa fa-map-marker"></i></button>
                        <button type="submit" class="btn btn-primary" title="Send" aria-label="Send">
                          <i class="fa fa-send"></i>
                        </button>
                      </div>
                    </div>
                    <input type="file" id="msgFileAny" name="attachment" style="display:none;">
                  </form>
                </div>
              <?php else: ?>
                <div class="group-panel-wrap">
                  <div class="group-panel-card">
                    <h3>Group Chat</h3>
                    <p class="group-panel-copy">Click <strong>Create Group Name</strong> at the top right to start a new group, then select it from the left to begin chatting.</p>
                  </div>
                </div>
              <?php endif; ?>
            <?php elseif (!$peerRow): ?>
              <div class="chat-empty-state">
                <div>
                  <div class="chat-empty-state-title">Messages</div>
                  <div class="chat-empty-state-copy">
                  Choose a user from the left to start messaging.
                  </div>
                </div>
              </div>
            <?php else: ?>

              <div id="chatStream">
                <?php
                  $lastDayKey = '';
                  foreach ($messages as $m):
                    $id     = (int)($m['id'] ?? 0);
                    $sender = strtoupper((string)($m['sender'] ?? ''));
                    $dt     = (string)($m['created_at'] ?? '');
                    $isRead = (int)($m['is_read'] ?? 0);
                    $isMe   = (
                      $sender !== '' && (
                        strtoupper($meCode) === $sender ||
                        ($meEmail !== '' && strtoupper($meEmail) === $sender)
                      )
                    );

                    $dk = $dt ? day_key($dt) : '';
                    if ($dk !== '' && $dk !== $lastDayKey):
                      $lastDayKey = $dk;
                ?>
                  <div class="day-divider dayDivider" data-day="<?php echo h($dk); ?>"><?php echo h(day_label($dt)); ?></div>
                <?php endif; ?>

                <?php
	                  $msg = (string)($m['feedbackdata'] ?? '');
	                  $replyBits = parse_reply_payload($msg);
	                  $msg = call_event_display_text((string)($replyBits['text'] ?? ''), $isMe);
                  $replyAuthor = (string)($replyBits['reply_author'] ?? '');
                  $replyText = (string)($replyBits['reply_text'] ?? '');
                  $replyMessageId = (int)($replyBits['reply_message_id'] ?? 0);
                  $attUrl = (string)($m['attachment_url'] ?? '');
                  $atype = strtolower(trim((string)($m['attachment_type'] ?? '')));
                  $orig = (string)($m['attachment_original'] ?? '');
                  if ($orig === '' && $attUrl !== '') $orig = basename($attUrl);
                  $isDeletedForAll = (int)($m['deleted_for_all'] ?? 0) === 1;
                  $editedAt = (string)($m['edited_at'] ?? '');
                  $reactions = is_array($m['reactions'] ?? null)
                    ? array_values(array_filter(array_map('strval', (array)$m['reactions']), static function (string $value): bool {
                        return trim($value) !== '';
                      }))
                    : [];

                  $ext = strtolower(pathinfo(parse_url($attUrl, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));
                  $isImg = $attUrl !== '' && ((strpos($atype, 'image/') === 0) || in_array($ext, ['png','jpg','jpeg','webp','gif'], true));
                  $isVid = $attUrl !== '' && ((strpos($atype, 'video/') === 0) || in_array($ext, ['mp4','webm','ogg','mov'], true));
                  $isPdf = $attUrl !== '' && (($atype === 'application/pdf') || ($ext === 'pdf'));
                ?>

                  <div class="msg-row msgRow <?php echo $isMe ? 'me' : ''; ?>" data-id="<?php echo $id; ?>" data-day="<?php echo h($dk); ?>">
                    <?php if (!$isMe): ?>
                      <span class="msg-avatar-mini"><img src="avatar.php?friend_code=<?php echo urlencode((string)$peerCode); ?>&name=<?php echo urlencode((string)$peerDisplay); ?>" alt="Avatar"></span>
                      <div class="msg-peer-wrap">
                    <?php endif; ?>
                    <?php if ($isMe): ?>
                      <div class="msg-side-actions left<?php echo (!$isDeletedForAll && $attUrl !== '') ? ' is-media-actions' : ''; ?>" data-message-id="<?php echo $id; ?>">
                        <a type="button" class="msg-side-btn msg-dot-btn" data-message-id="<?php echo $id; ?>" data-is-me="<?php echo $isMe ? '1' : '0'; ?>" data-peer="<?php echo h($peerCode); ?>" aria-label="Message actions"><i class="fa fa-ellipsis-v"></i></a>
                        <a type="button" class="msg-side-btn msg-reply-btn" data-message-id="<?php echo $id; ?>" aria-label="Reply"><i class="fa fa-reply"></i></a>
                        <a type="button" class="msg-side-btn msg-react-btn" data-message-id="<?php echo $id; ?>" aria-label="React"><i class="fa fa-smile-o"></i></a>
                      </div>
                    <?php endif; ?>
                    <div class="msg-bubble-stack">
                    <div class="bubble <?php echo $isMe ? 'me' : ''; ?> <?php echo $replyText !== '' ? 'has-reply' : ''; ?> <?php echo (!$isDeletedForAll && $attUrl !== '') ? 'has-media' : ''; ?>" data-message-id="<?php echo $id; ?>" data-is-me="<?php echo $isMe ? '1' : '0'; ?>" data-peer="<?php echo h($peerCode); ?>">
                      <?php if (!$isDeletedForAll && $replyText !== ''): ?>
                        <div class="msg-reply-line" data-reply-target-id="<?php echo (int)$replyMessageId; ?>">
                          <div class="msg-reply-line-title">Replying to <?php echo h($replyAuthor !== '' ? $replyAuthor : ($isMe ? 'yourself' : $peerDisplay)); ?></div>
                          <div class="msg-reply-line-text"><?php echo h($replyText); ?></div>
                        </div>
                      <?php endif; ?>
                      <?php if ($isDeletedForAll): ?>
                        <div class="msgText is-unsent"><?php echo $isMe ? 'You unsent a message.' : 'This message was unsent.'; ?></div>
                      <?php elseif ($msg !== ''): ?>
                        <div class="msgText"><?php echo nl2br(h($msg)); ?></div>
                      <?php endif; ?>

                      <?php if (!$isDeletedForAll && $attUrl !== ''): ?>
                        <div style="margin-top:6px;">
                          <?php if ($isImg): ?>
                            <a href="javascript:void(0);" class="mediaOpen"
                              data-url="<?php echo h($attUrl); ?>"
                              data-type="<?php echo h($atype ?: 'image/*'); ?>"
                              data-orig="<?php echo h($orig); ?>">
                              <img src="<?php echo h($attUrl); ?>" alt="<?php echo h($orig); ?>" style="cursor:pointer;">
                            </a>
                          <?php elseif ($isVid): ?>
                            <div class="mediaOpen"
                              data-url="<?php echo h($attUrl); ?>"
                              data-type="<?php echo h($atype ?: 'video/mp4'); ?>"
                              data-orig="<?php echo h($orig); ?>"
                              style="cursor:pointer;">
                              <video controls>
                                <source src="<?php echo h($attUrl); ?>" type="<?php echo h($atype ?: 'video/mp4'); ?>">
                              </video>
                            </div>
                          <?php elseif ($isPdf): ?>
                            <button type="button" class="btn btn-outline-secondary btn-sm mediaOpen"
                              data-url="<?php echo h($attUrl); ?>"
                              data-type="application/pdf"
                              data-orig="<?php echo h($orig); ?>"
                              style="border-radius:12px;">View PDF</button>
                            <div style="margin-top:6px;font-size:12px;opacity:.85;"><?php echo h($orig); ?></div>
                          <?php else: ?>
                            <a href="javascript:void(0);" class="mediaOpen"
                              data-url="<?php echo h($attUrl); ?>"
                              data-type="<?php echo h($atype); ?>"
                              data-orig="<?php echo h($orig); ?>"
                              style="text-decoration:underline;">
                              <i class="fa fa-paperclip"></i> <?php echo h($orig); ?>
                            </a>
                            <a href="<?php echo h($attUrl); ?>" target="_blank" rel="noopener"
                              style="font-size:12px;margin-left:8px;text-decoration:underline;opacity:.85;">Open</a>
                          <?php endif; ?>
                        </div>
                      <?php endif; ?>

                      <?php if (!$isDeletedForAll && $editedAt !== ''): ?>
                        <div class="msg-edited">Edited</div>
                      <?php endif; ?>

                      <div class="msg-meta <?php echo $isMe ? 'me' : ''; ?>">
                        <?php if ($isMe): ?>
                          <?php echo h($meDisplay); ?>
                          <span style="opacity:.6;">•</span>
                          <?php echo h(fmt_time_full($dt)); ?>
                          <span style="opacity:.6;">•</span>
                          <span class="msgTicks" data-msgid="<?php echo (int)$id; ?>"><?php echo $isRead ? '✓✓' : '✓'; ?></span>
                        <?php else: ?>
                          <?php echo h($peerDisplay); ?>
                          <span style="opacity:.6;">•</span>
                          <?php echo h(fmt_time_full($dt)); ?>
                        <?php endif; ?>
                      </div>

                    </div>
                    <?php if (!$isDeletedForAll && !empty($reactions)): ?>
                      <div class="msg-reactions">
                        <?php foreach ($reactions as $reactionEmoji): ?>
                          <span class="msg-reaction-pill"><?php echo h($reactionEmoji); ?></span>
                        <?php endforeach; ?>
                      </div>
                    <?php endif; ?>
                    </div>
                    <?php if (!$isMe): ?>
                      <div class="msg-peer-actions<?php echo (!$isDeletedForAll && $attUrl !== '') ? ' is-media-actions' : ''; ?>" data-message-id="<?php echo $id; ?>">
                        <a type="button" class="msg-side-btn msg-react-btn" data-message-id="<?php echo $id; ?>" aria-label="React"><i class="fa fa-smile-o"></i></a>
                        <a type="button" class="msg-side-btn msg-reply-btn" data-message-id="<?php echo $id; ?>" aria-label="Reply"><i class="fa fa-reply"></i></butaton>
                        <a type="button" class="msg-side-btn msg-dot-btn" data-message-id="<?php echo $id; ?>" data-is-me="0" data-peer="<?php echo h($peerCode); ?>" aria-label="Message actions"><i class="fa fa-ellipsis-v"></i></a>
                      </div>
                      </div>
                    <?php endif; ?>
                  </div>

                <?php endforeach; ?>
              </div>

            <?php endif; ?>
          </div>

          <?php if ($peerRow): ?>
          <div class="composer">
            <div id="replyPreview" class="reply-preview" aria-hidden="true">
              <div class="reply-preview-main">
                <div id="replyPreviewTitle" class="reply-preview-title">Replying</div>
                <div id="replyPreviewText" class="reply-preview-text"></div>
              </div>
              <button type="button" id="replyPreviewClose" class="reply-preview-close" aria-label="Cancel reply"><i class="fa fa-times"></i></button>
            </div>
            <form id="sendForm" method="post" action="" enctype="multipart/form-data">
              <div id="msgPreviewInline">
                <img id="msgPreviewThumb" src="" alt="">
                <div style="min-width:0;">
                  <div id="msgPreviewName" style="font-size:12px;font-weight:900;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:200px;"></div>
                  <div style="font-size:11px;color:rgba(17,24,39,.60);font-weight:700;">Ready to send</div>
                </div>
                <a type="button" id="msgPreviewRemove" class="btn btn-link btn-sm" style="padding:0 6px;">✕</a>
              </div>

              <div class="composer-bar">
                <div class="composer-left">
                  <button type="button" id="composerMicBtn" title="Voice"><i class="fa fa-microphone"></i></button>
                </div>
                <div class="composer-input-wrap">
                  <textarea id="messageInput" name="message" placeholder="Type your message here"></textarea>
                </div>
                <div class="composer-right">
                  <a type="button" id="msgPlusBtn" title="Attach"><i class="fa fa-picture-o"></i></a>
                  <a type="button" id="emojiBtn" title="Emoji"><i class="fa fa-smile-o"></i></a>
                  <button type="button" id="composerLocationBtn" title="Location"><i class="fa fa-map-marker"></i></button>
                  <button type="submit" class="btn btn-primary" title="Send" aria-label="Send">
                    <i class="fa fa-send"></i>
                  </button>
                </div>
              </div>
              <input type="hidden" id="replyMessageId" name="reply_message_id" value="">
              <a type="button" id="gifBtn" title="GIF" style="display:none;"><span style="font-weight:900;font-size:14px;letter-spacing:.02em;">GIF</span></a>

              <input type="file" id="msgFileAny" name="attachment" style="display:none;">
            </form>
          </div>
          <?php endif; ?>
          </div>

          <aside class="chat-info-panel" id="chatInfoPanel">
            <?php if ($isGroupChatView && $selectedGroup): ?>
              <?php $isGroupOwner = ((string)($selectedGroup['my_role'] ?? '') === 'owner'); ?>
              <div class="chat-info-head">
                <button type="button" class="chat-info-close" id="chatInfoClose" aria-label="Close details">
                  <i class="fa fa-times"></i>
                </button>
              </div>
              <div class="chat-info-avatar">
                <img src="avatar.php?friend_code=<?php echo urlencode('GROUP-' . (string)($selectedGroup['id'] ?? 0)); ?>&name=<?php echo urlencode((string)($selectedGroup['name'] ?? 'Group Chat')); ?>" alt="<?php echo h((string)($selectedGroup['name'] ?? 'Group Chat')); ?>">
              </div>
              <div class="chat-info-name"><?php echo h((string)($selectedGroup['name'] ?? 'Group Chat')); ?></div>
              <div class="chat-info-handle"><?php echo h(count($groupMembers) . ' member' . (count($groupMembers) === 1 ? '' : 's')); ?> • <?php echo h(ucfirst((string)($selectedGroup['my_role'] ?? 'member'))); ?></div>

              <div class="chat-info-section">
                <div class="chat-info-section-head">
                  <span>Group Settings</span>
                  <i class="fa fa-angle-down" aria-hidden="true"></i>
                </div>
                <?php if ($isGroupOwner): ?>
                  <form method="post" class="group-form-grid" style="margin-top:12px;">
                    <input type="hidden" name="group_action" value="rename_group">
                    <input type="hidden" name="group_id" value="<?php echo (int)($selectedGroup['id'] ?? 0); ?>">
                    <input type="text" name="group_name" value="<?php echo h((string)($selectedGroup['name'] ?? '')); ?>" maxlength="150" required>
                    <div class="group-form-actions">
                      <button type="submit" class="btn btn-outline-primary btn-sm">Edit Group Name</button>
                    </div>
                  </form>
                  <form method="post" onsubmit="return confirm('Delete this group?');" style="margin-top:12px;">
                    <input type="hidden" name="group_action" value="delete_group">
                    <input type="hidden" name="group_id" value="<?php echo (int)($selectedGroup['id'] ?? 0); ?>">
                    <button type="submit" class="btn btn-danger btn-sm">Delete Group</button>
                  </form>
                <?php else: ?>
                  <div class="group-panel-copy" style="margin-top:12px;">Only the group owner can edit or delete this group.</div>
                <?php endif; ?>
              </div>

              <div class="chat-info-section">
                <div class="chat-info-section-head">
                  <span>Add Peer</span>
                  <i class="fa fa-angle-down" aria-hidden="true"></i>
                </div>
                <?php if ($isGroupOwner): ?>
                  <form method="post" class="group-form-grid" style="margin-top:12px;">
                    <input type="hidden" name="group_action" value="add_group_members">
                    <input type="hidden" name="group_id" value="<?php echo (int)($selectedGroup['id'] ?? 0); ?>">
                    <div class="group-member-picker">
                      <?php if (!empty($groupAddableFriends)): ?>
                        <?php foreach ($groupAddableFriends as $friendOption): ?>
                          <label class="group-member-option">
                            <input type="checkbox" name="member_ids[]" value="<?php echo (int)($friendOption['id'] ?? 0); ?>">
                            <span>
                              <strong><?php echo h((string)($friendOption['display_name'] ?? 'Friend')); ?></strong>
                              <span><?php echo h((string)($friendOption['friend_code'] ?? '')); ?></span>
                            </span>
                          </label>
                        <?php endforeach; ?>
                      <?php else: ?>
                        <div class="group-list-empty" style="margin:0;">No available peers to add.</div>
                      <?php endif; ?>
                    </div>
                    <div class="group-form-actions">
                      <button type="submit" class="btn btn-primary btn-sm">Add Selected Peers</button>
                    </div>
                  </form>
                <?php else: ?>
                  <div class="group-panel-copy" style="margin-top:12px;">Only the group owner can add peers.</div>
                <?php endif; ?>
              </div>

              <div class="chat-info-section">
                <div class="chat-info-section-head">
                  <span>Members</span>
                  <i class="fa fa-angle-down" aria-hidden="true"></i>
                </div>
                <div class="group-member-grid" style="margin-top:12px;">
                  <?php foreach ($groupMembers as $groupMember): ?>
                    <?php
                      $memberName = (string)($groupMember['display_name'] ?? 'Member');
                      $memberUserId = (int)($groupMember['user_id'] ?? 0);
                      $canModerateMember = $isGroupOwner && $memberUserId > 0 && $memberUserId !== $meId;
                    ?>
                    <div class="group-member-chip">
                      <span class="group-member-avatar">
                        <img src="avatar.php?friend_code=<?php echo urlencode((string)($groupMember['friend_code'] ?? '')); ?>&name=<?php echo urlencode($memberName); ?>" alt="<?php echo h($memberName); ?>">
                      </span>
                      <span class="group-member-text">
                        <strong><?php echo h($memberName); ?></strong>
                        <span><?php echo h((string)($groupMember['friend_code'] ?? '')); ?> • <?php echo h(ucfirst((string)($groupMember['role'] ?? 'member'))); ?></span>
                      </span>
                      <?php if ($canModerateMember): ?>
                        <span class="group-member-row-actions">
                          <form method="post" onsubmit="return confirm('Remove this peer from the group?');">
                            <input type="hidden" name="group_action" value="remove_group_member">
                            <input type="hidden" name="group_id" value="<?php echo (int)($selectedGroup['id'] ?? 0); ?>">
                            <input type="hidden" name="member_user_id" value="<?php echo $memberUserId; ?>">
                            <button type="submit">Remove</button>
                          </form>
                          <form method="post" onsubmit="return confirm('Block this peer from the group?');">
                            <input type="hidden" name="group_action" value="block_group_member">
                            <input type="hidden" name="group_id" value="<?php echo (int)($selectedGroup['id'] ?? 0); ?>">
                            <input type="hidden" name="member_user_id" value="<?php echo $memberUserId; ?>">
                            <button type="submit" class="danger">Block</button>
                          </form>
                        </span>
                      <?php endif; ?>
                    </div>
                  <?php endforeach; ?>
                </div>
              </div>
            <?php elseif ($peerRow): ?>
              <div class="chat-info-head">
                <button type="button" class="chat-info-close" id="chatInfoClose" aria-label="Close details">
                  <i class="fa fa-times"></i>
                </button>
              </div>
              <div class="chat-info-avatar">
                <img src="avatar.php?friend_code=<?php echo urlencode((string)$peerCode); ?>&name=<?php echo urlencode((string)$peerDisplay); ?>" alt="<?php echo h($peerDisplay); ?>">
              </div>
              <div class="chat-info-name"><?php echo h($peerDisplay); ?></div>
              <div class="chat-info-handle">@<?php echo h(strtolower(str_replace(' ', '_', $peerDisplay))); ?></div>

              <div class="chat-info-section">
                <div class="chat-info-section-head">
                  <span>Attachments</span>
                  <i class="fa fa-angle-down" aria-hidden="true"></i>
                </div>
                <div class="chat-file-list">
                  <?php if (!empty($attachmentItems)): ?>
                    <?php foreach ($attachmentItems as $item): ?>
                      <?php
                        $ext = strtoupper((string)($item['ext'] !== '' ? $item['ext'] : 'FILE'));
                        $icon = 'fa-file-o';
                        if (in_array(strtolower((string)$item['ext']), ['pdf'], true)) $icon = 'fa-file-pdf-o';
                        elseif (in_array(strtolower((string)$item['ext']), ['doc','docx'], true)) $icon = 'fa-file-word-o';
                        elseif (in_array(strtolower((string)$item['ext']), ['xls','xlsx','csv'], true)) $icon = 'fa-file-excel-o';
                        elseif (in_array(strtolower((string)$item['ext']), ['ppt','pptx'], true)) $icon = 'fa-file-powerpoint-o';
                      ?>
                      <a class="chat-file-item" href="<?php echo h((string)$item['url']); ?>" target="_blank" rel="noopener">
                        <span class="chat-file-icon"><i class="fa <?php echo h($icon); ?>"></i></span>
                        <span class="chat-file-copy">
                          <span class="chat-file-name"><?php echo h((string)$item['name']); ?></span>
                          <span class="chat-file-meta"><?php echo h($ext); ?> file<?php echo $item['time'] !== '' ? ' • ' . h(fmt_time_full((string)$item['time'])) : ''; ?></span>
                        </span>
                      </a>
                    <?php endforeach; ?>
                  <?php else: ?>
                    <div class="chat-file-meta">No attachments yet.</div>
                  <?php endif; ?>
                </div>
                <?php if (count($attachmentItems) > 0): ?>
                  <a class="chat-view-all" href="javascript:void(0)">View all</a>
                <?php endif; ?>
              </div>

              <div class="chat-info-section">
                <div class="chat-info-section-head">
                  <span>Members</span>
                  <i class="fa fa-angle-down" aria-hidden="true"></i>
                </div>
                <div class="chat-member-list">
                  <div class="chat-add-member">+ Add Member</div>
                  <div class="chat-member-item">
                    <span class="chat-member-avatar"><img src="avatar.php?friend_code=<?php echo urlencode((string)$peerCode); ?>&name=<?php echo urlencode((string)$peerDisplay); ?>" alt="<?php echo h($peerDisplay); ?>"></span>
                    <span class="chat-member-copy">
                      <span class="chat-member-name"><?php echo h($peerDisplay); ?></span>
                      <span class="chat-member-meta"><?php echo h($peerOnlineInfo['online'] ? 'online' : $peerOnlineInfo['label']); ?></span>
                    </span>
                  </div>
                  <div class="chat-member-item">
                    <span class="chat-member-avatar"><img src="avatar.php?friend_code=<?php echo urlencode((string)$meCode); ?>&name=<?php echo urlencode((string)$meDisplay); ?>" alt="<?php echo h($meDisplay); ?>"></span>
                    <span class="chat-member-copy">
                      <span class="chat-member-name"><?php echo h($meDisplay); ?></span>
                      <span class="chat-member-meta">you</span>
                    </span>
                  </div>
                </div>
              </div>
            <?php endif; ?>
          </aside>
          </div>
          <?php if (!$peerRow && !$isGroupChatView): ?>
            <a href="compose.php" class="chat-empty-plus" aria-label="Start a new message" title="Start a new message"><i class="fa fa-plus"></i></a>
          <?php endif; ?>

    </div>
  </div>
  </div>
  <!-- <div class="sh-footer">
    <div>Copyright &copy; 2017. All Rights Reserved.</div>
    <div class="mg-t-10 mg-md-t-0">Designed by: ThemePixels</div>
  </div> -->

</div>

<?php if ($isGroupChatView && $groupFeatureReady): ?>
<div class="group-create-modal" id="groupCreateModal" aria-hidden="true">
  <div class="group-create-dialog" role="dialog" aria-modal="true" aria-label="Create group">
    <div class="group-create-head">
      <div>
        <h3>Create Group Name</h3>
        <p>Create a new group, add peers, and open it directly in the group chat thread.</p>
      </div>
      <button type="button" class="group-create-close" id="closeGroupCreateBtn" aria-label="Close"><i class="fa fa-times"></i></button>
    </div>
    <form method="post" class="group-form-grid">
      <input type="hidden" name="group_action" value="create_group">
      <input type="text" name="group_name" placeholder="Enter group name" maxlength="150" required>
      <div class="group-member-picker">
        <?php if (!empty($friendOptions)): ?>
          <?php foreach ($friendOptions as $friendOption): ?>
            <label class="group-member-option">
              <input type="checkbox" name="member_ids[]" value="<?php echo (int)($friendOption['id'] ?? 0); ?>">
              <span>
                <strong><?php echo h((string)($friendOption['display_name'] ?? 'Friend')); ?></strong>
                <span><?php echo h((string)($friendOption['friend_code'] ?? '')); ?></span>
              </span>
            </label>
          <?php endforeach; ?>
        <?php else: ?>
          <div class="group-list-empty" style="margin:0;">Add friends first before creating a group.</div>
        <?php endif; ?>
      </div>
      <div class="group-form-actions">
        <button type="submit" class="btn btn-primary">Create</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<div id="emojiMenu" class="floatMenu" aria-hidden="true" style="width:360px; max-width:92vw; overflow:hidden;">
  <div id="emojiMenuBodyHead">
    <strong style="font-weight:900;">Emoji</strong>
    <button type="button" id="emojiClose" class="btn btn-link btn-sm" style="font-weight:800;">Close</button>
  </div>
  <div id="emojiGrid"></div>
</div>

<div id="gifPickerOverlay" class="gif-picker-overlay" aria-hidden="true">
  <div class="gif-picker" role="dialog" aria-modal="true" aria-label="GIF picker">
    <div class="gif-picker-head">
      <div class="gif-search">
        <i class="fa fa-search"></i>
        <input type="text" id="gifSearchInput" placeholder="Search">
      </div>
    </div>
    <div class="gif-grid" id="gifGrid"></div>
    <div class="gif-picker-empty" id="gifEmpty" style="display:none;">No GIFs match your search.</div>
  </div>
</div>

<!-- ✅ Media Viewer Modal -->
<div id="mediaModal" style="display:none; position:fixed; inset:0; z-index:999999;" aria-hidden="true">
  <div id="mediaBackdrop" style="position:absolute; inset:0; background:rgba(0,0,0,.7);"></div>
  <div style="position:relative; z-index:1; width:min(980px, 95vw); height:min(86vh, 720px); margin:6vh auto 0 auto;">
    <div style="background:#fff; border-radius:16px; box-shadow:0 20px 70px rgba(0,0,0,.35); overflow:hidden; height:100%; display:flex; flex-direction:column;">
      <div style="padding:12px 14px; border-bottom:1px solid #eee; display:flex; align-items:center; justify-content:space-between; gap:10px;">
        <div style="min-width:0;">
          <div id="mediaTitle" style="font-weight:900; font-size:14px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">Attachment</div>
          <div id="mediaMeta" style="font-size:12px; color:#666; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;"></div>
        </div>
        <div style="display:flex; align-items:center; gap:10px;">
          <a id="mediaOpenNewTab" href="#" target="_blank" rel="noopener" class="btn btn-outline-secondary btn-sm" style="border-radius:12px; padding:6px 10px; text-decoration:none;">Open</a>
          <button type="button" id="mediaClose" class="btn btn-light btn-sm" style="border-radius:12px; padding:6px 12px;">Close</button>
        </div>
      </div>
      <div id="mediaBody" style="flex:1; min-height:0; background:#0b0b0b; display:flex; align-items:center; justify-content:center; padding:10px;"></div>
    </div>
  </div>
</div>

<div id="videoCallOverlay" style="display:none;" aria-hidden="true">
  <div class="vcall-shell">
      <div class="vcall-header">
        <div class="vcall-meeting">
          <div class="vcall-meeting-badge"><i class="fa fa-video-camera"></i></div>
          <div class="vcall-meta">
          <div class="vcall-kicker">Thursday, 30 July 2023</div>
          <div class="vcall-name" id="vcallMeetingName">Product design meeting</div>
          <div class="vcall-stage-status" id="vcallStatus">Connecting…</div>
          </div>
        </div>
        <div class="vcall-top-right">
        <div class="vcall-clock" id="vcallClock">00:00</div>
        <button type="button" class="vcall-close" id="vcallCloseBtn" aria-label="End call"><i class="fa fa-times"></i></button>
        </div>
      </div>

    <div class="vcall-body">
      <div class="vcall-stage-wrap">
        <div class="vcall-stage">
          <div class="vcall-stage-top">
            <div class="vcall-stage-left">
              <div class="vcall-timer-chip"><i class="fa fa-dot-circle-o"></i> <span id="vcallTimerLabel">Call time 00:00</span></div>
              <div class="vcall-stage-name"><i class="fa fa-user-circle-o"></i> <span id="vcallPeerName"><?php echo h($peerDisplay ?: 'Johan'); ?></span></div>
            </div>
            <div class="vcall-stage-actions">
              <button type="button" class="vcall-stage-action" aria-label="Grid"><i class="fa fa-camera"></i></button>
              <button type="button" class="vcall-stage-action" aria-label="Expand"><i class="fa fa-th-large"></i></button>
            </div>
          </div>

          <video id="vcallRemoteVideo" class="vcall-remote" autoplay playsinline></video>
          <img id="vcallRemoteSnapshot" class="vcall-remote" alt="Remote video" style="display:none;object-fit:cover;width:100%;height:100%;">
          <div class="vcall-remote-grid" id="vcallRemoteGrid"></div>
          <div class="vcall-empty" id="vcallRemoteEmpty">
            <div class="vcall-empty-card">
              <div class="vcall-empty-avatar">
                <img src="avatar.php?friend_code=<?php echo urlencode((string)$peerCode); ?>&name=<?php echo urlencode((string)$peerDisplay); ?>" alt="<?php echo h($peerDisplay ?: 'Participant'); ?>">
              </div>
              <div class="vcall-empty-title" id="vcallStagePeerName"><?php echo h($peerDisplay ?: 'Johan'); ?></div>
              <div class="vcall-empty-text" id="vcallRemoteEmptyText">Waiting for video…</div>
            </div>
          </div>

          <video id="vcallLocalVideo" class="vcall-local" autoplay muted playsinline></video>
          <!-- <div class="vcall-stage-local-label">Johan</div> -->

          <div class="vcall-incoming" id="vcallIncomingBox" style="display:none;">
            <div class="vcall-incoming-text" id="vcallIncomingText">Incoming video call</div>
            <div class="vcall-actions">
              <button type="button" class="vcall-btn decline" id="vcallDeclineBtn">Decline</button>
              <button type="button" class="vcall-btn accept" id="vcallAcceptBtn">Accept</button>
            </div>
          </div>

          <div class="vcall-join-requests" id="vcallJoinRequests"></div>

          <div class="vcall-controls">
            <div class="vcall-control-item">
              <button type="button" class="vcall-icon-btn" id="vcallCameraBtn" aria-label="Camera"><i class="fa fa-video-camera"></i></button>
              <span>Cam</span>
            </div>
            <div class="vcall-control-item">
              <button type="button" class="vcall-icon-btn" id="vcallMuteBtn" aria-label="Mute"><i class="fa fa-microphone"></i></button>
              <span>Mic</span>
            </div>
            <div class="vcall-control-item">
              <button type="button" class="vcall-icon-btn" aria-label="Share screen"><i class="fa fa-desktop"></i></button>
              <span>Share</span>
            </div>
            <div class="vcall-control-item">
              <button type="button" class="vcall-icon-btn" aria-label="Record"><i class="fa fa-dot-circle-o"></i></button>
              <span>Rec</span>
            </div>
            <div class="vcall-control-item">
              <button type="button" class="vcall-icon-btn" id="vcallMessagesBtn" aria-label="Messages"><i class="fa fa-commenting-o"></i></button>
              <span>Chat</span>
            </div>
            <div class="vcall-control-item">
              <button type="button" class="vcall-icon-btn" aria-label="Participants"><i class="fa fa-users"></i></button>
              <span>People</span>
            </div>
            <div class="vcall-control-item">
              <button type="button" class="vcall-end-btn" id="vcallEndBtn" aria-label="End"><i class="fa fa-hand-paper-o"></i></button>
              <span>Leave</span>
            </div>
          </div>
        </div>
      </div>

      <aside class="vcall-sidebar">
        <div class="vcall-sidebar-head">
          <div class="vcall-sidebar-title" id="vcallSidebarTitle">Direct Chat</div>
          <button type="button" class="vcall-sidebar-close" id="vcallSidebarCloseBtn" aria-label="Close details"><i class="fa fa-times"></i></button>
        </div>
        <div class="vcall-sidebar-subtitle" id="vcallSidebarSubtitle">One-to-one conversation with <?php echo h($peerDisplay ?: 'this contact'); ?></div>
        <div class="vcall-tabs single-tab">
          <button type="button" class="vcall-tab active" id="vcallSidebarTab">Private chat</button>
        </div>
        <div class="vcall-chat" id="vcallChatList">
          <div class="vcall-chat-group" id="vcallChatGroup">
            <?php if ($isGroupChatView && $selectedGroup): ?>
              <?php if (!empty($groupMessages)): ?>
                <?php foreach ($groupMessages as $sidebarGroupMessage): ?>
                  <?php
                    $sidebarSenderId = (int)($sidebarGroupMessage['sender_user_id'] ?? 0);
                    $sidebarIsMe = ($sidebarSenderId === $meId);
                    $sidebarSenderName = (string)($sidebarGroupMessage['sender_name'] ?? ($sidebarIsMe ? $meDisplay : 'Member'));
                    $sidebarSenderCode = (string)($sidebarGroupMessage['friend_code'] ?? '');
                    $sidebarReplyBits = parse_reply_payload((string)($sidebarGroupMessage['body'] ?? ''));
                    $sidebarBodyText = trim((string)($sidebarReplyBits['text'] ?? ''));
                    if ($sidebarBodyText === '') {
                      $sidebarBodyText = trim((string)($sidebarGroupMessage['body'] ?? ''));
                    }
                  ?>
                  <div class="vcall-chat-row<?php echo $sidebarIsMe ? ' me' : ''; ?>" data-id="<?php echo (int)($sidebarGroupMessage['id'] ?? 0); ?>">
                    <?php if (!$sidebarIsMe): ?>
                      <div class="vcall-chat-avatar">
                        <img src="avatar.php?friend_code=<?php echo urlencode($sidebarSenderCode); ?>&name=<?php echo urlencode($sidebarSenderName); ?>" alt="<?php echo h($sidebarSenderName); ?>">
                      </div>
                    <?php endif; ?>
                    <div class="vcall-chat-bubble">
                      <?php echo nl2br(h($sidebarBodyText)); ?>
                      <span class="vcall-chat-meta"><?php echo h($sidebarIsMe ? 'You' : $sidebarSenderName); ?></span>
                    </div>
                  </div>
                <?php endforeach; ?>
              <?php else: ?>
                <div class="vcall-chat-row">
                  <div class="vcall-chat-avatar">
                    <img src="avatar.php?friend_code=<?php echo urlencode('GROUP-' . (string)($selectedGroup['id'] ?? 0)); ?>&name=<?php echo urlencode((string)($selectedGroup['name'] ?? 'Group Chat')); ?>" alt="<?php echo h((string)($selectedGroup['name'] ?? 'Group Chat')); ?>">
                  </div>
                  <div class="vcall-chat-bubble">
                    Chat with everyone in <?php echo h((string)($selectedGroup['name'] ?? 'this group')); ?> while you are on the call.
                    <span class="vcall-chat-meta"><?php echo h((string)($selectedGroup['name'] ?? 'Group Chat')); ?></span>
                  </div>
                </div>
              <?php endif; ?>
            <?php else: ?>
              <?php if (!empty($callSidebarMessages)): ?>
                <?php foreach ($callSidebarMessages as $sidebarChat): ?>
                  <div class="vcall-chat-row<?php echo !empty($sidebarChat['is_me']) ? ' me' : ''; ?>">
                    <?php if (empty($sidebarChat['is_me'])): ?>
                      <div class="vcall-chat-avatar">
                        <img src="avatar.php?friend_code=<?php echo urlencode((string)$peerCode); ?>&name=<?php echo urlencode((string)$peerDisplay); ?>" alt="<?php echo h($peerDisplay ?: 'Contact'); ?>">
                      </div>
                    <?php endif; ?>
                    <div class="vcall-chat-bubble">
                      <?php echo nl2br(h((string)($sidebarChat['text'] ?? ''))); ?>
                      <span class="vcall-chat-meta"><?php echo h((string)($sidebarChat['meta'] ?? '')); ?></span>
                    </div>
                  </div>
                <?php endforeach; ?>
              <?php else: ?>
                <div class="vcall-chat-row">
                  <div class="vcall-chat-avatar">
                    <img src="avatar.php?friend_code=<?php echo urlencode((string)$peerCode); ?>&name=<?php echo urlencode((string)$peerDisplay); ?>" alt="<?php echo h($peerDisplay ?: 'Contact'); ?>">
                  </div>
                  <div class="vcall-chat-bubble">
                    Start a private chat with <?php echo h($peerDisplay ?: 'this contact'); ?> while you are on the call.
                    <span class="vcall-chat-meta"><?php echo h($peerDisplay ?: 'Contact'); ?></span>
                  </div>
                </div>
              <?php endif; ?>
            <?php endif; ?>
          </div>
        </div>
        <div class="vcall-compose">
          <div class="vcall-compose-box">
            <textarea id="vcallComposeInput" rows="1" placeholder="Message <?php echo h($peerDisplay ?: 'contact'); ?>..."></textarea>
            <button type="button" class="vcall-compose-send" id="vcallComposeSend" aria-label="Send"><i class="fa fa-paper-plane"></i></button>
          </div>
        </div>
      </aside>
    </div>
  </div>
</div>

<div id="msgActionMenu" class="msg-menu" aria-hidden="true"></div>
<div id="msgReactionPicker" class="msg-react-picker" aria-hidden="true">
  <button type="button" data-emoji="❤️" aria-label="Heart">❤️</button>
  <button type="button" data-emoji="😆" aria-label="Laugh">😆</button>
  <button type="button" data-emoji="😮" aria-label="Wow">😮</button>
  <button type="button" data-emoji="😢" aria-label="Sad">😢</button>
  <button type="button" data-emoji="😠" aria-label="Angry">😠</button>
  <button type="button" data-emoji="👍" aria-label="Like">👍</button>
</div>
<form id="groupMessageActionForm" method="post" style="display:none;">
  <input type="hidden" name="group_action" value="">
  <input type="hidden" name="group_id" value="<?php echo (int)($selectedGroup['id'] ?? 0); ?>">
  <input type="hidden" name="group_message_id" value="">
  <input type="hidden" name="group_message_text" value="">
</form>

<script>
(function(){
  const peerCode = <?php echo $peerRow ? json_encode($peerCode) : '""'; ?>;
  const peerDisplay = <?php echo $peerRow ? json_encode($peerDisplay) : '""'; ?>;
  const meDisplay = <?php echo json_encode($meDisplay); ?>;
  const currentUserId = <?php echo (int)$meId; ?>;
  const isGroupChatView = <?php echo $isGroupChatView ? 'true' : 'false'; ?>;
  const selectedGroupId = <?php echo (int)($selectedGroup['id'] ?? 0); ?>;
  const selectedGroupName = <?php echo json_encode((string)($selectedGroup['name'] ?? 'Group Call')); ?>;
  let pendingAutoAcceptCallId = <?php echo max(0, (int)($_GET['accept_call'] ?? 0)); ?>;
  let lastGroupVideoChatId = <?php
    $initialGroupLastId = 0;
    if (!empty($groupMessages) && is_array($groupMessages)) {
        foreach ($groupMessages as $groupMessageItem) {
            $gid = (int)($groupMessageItem['id'] ?? 0);
            if ($gid > $initialGroupLastId) $initialGroupLastId = $gid;
        }
    }
    echo (int)$initialGroupLastId;
  ?>;
  const groupCallMembers = <?php echo json_encode(array_values(array_map(static function(array $member): array {
      return [
          'user_id' => (int)($member['user_id'] ?? 0),
          'display_name' => (string)($member['display_name'] ?? ''),
          'friend_code' => (string)($member['friend_code'] ?? ''),
      ];
  }, $groupMembers ?? []))); ?>;
  const peerAvatarUrl = <?php echo $peerRow ? json_encode('avatar.php?friend_code=' . urlencode((string)$peerCode) . '&name=' . urlencode((string)$peerDisplay)) : '""'; ?>;
  let lastId = <?php echo (int)$lastId; ?>;

  const chatBox = document.getElementById('chatBox');
  const chatStream = document.getElementById('chatStream');
  const vcallChatList = document.getElementById('vcallChatList');
  const vcallChatGroup = document.getElementById('vcallChatGroup');
  const vcallComposeInput = document.getElementById('vcallComposeInput');
  const vcallComposeSend = document.getElementById('vcallComposeSend');
  const groupMemberDirectory = new Map((groupCallMembers || []).map(member => [Number(member.user_id || 0), member]));

  function isNearBottom(el){
    if(!el) return true;
    return (el.scrollHeight - el.scrollTop - el.clientHeight) < 140;
  }
  function scrollToBottom(){
    if(!chatBox) return;
    chatBox.scrollTop = chatBox.scrollHeight;
  }
  function scrollVideoCallChatToBottom(){
    if(!vcallChatList) return;
    vcallChatList.scrollTop = vcallChatList.scrollHeight;
  }
  function setVideoCallSidebarOpen(isOpen){
    if(!vcallBody) return;
    vcallBody.classList.toggle('is-sidebar-collapsed', !isOpen);
    if(isOpen){
      setTimeout(scrollVideoCallChatToBottom, 20);
      if(vcallComposeInput){
        setTimeout(function(){
          try{ vcallComposeInput.focus(); }catch(_err){}
        }, 40);
      }
    }
  }

  function updateGroupHostLayout(){
    if(!vcallShell || !vcallRemoteGrid) return;
    const tiles = Array.from(vcallRemoteGrid.querySelectorAll('.vcall-remote-tile')).slice(0, 12);
    const tileCount = Math.min(12, tiles.length);
    const hostUserId = Number(callState.startedByUserId || currentUserId || 0);
    let hostTile = null;

    tiles.forEach(function(tile){
      tile.classList.remove('vcall-host-tile', 'vcall-primary-tile', 'vcall-primary-1', 'vcall-primary-2', 'vcall-primary-3');
      const tileUserId = Number(tile.getAttribute('data-user-id') || 0);
      if(!hostTile && hostUserId > 0 && tileUserId === hostUserId){
        hostTile = tile;
      }
    });

    if(!hostTile){
      hostTile = tiles.find(function(tile){ return tile.classList.contains('vcall-local-tile'); }) || null;
    }
    if(hostTile){
      hostTile.classList.add('vcall-host-tile');
    }

    const primaryTiles = tiles.filter(function(tile){ return tile !== hostTile; });
    primaryTiles.slice(0, 3).forEach(function(tile, index){
      tile.classList.add('vcall-primary-tile', 'vcall-primary-' + String(index + 1));
    });

    if(tileCount > 0){
      vcallRemoteGrid.setAttribute('data-tile-count', String(tileCount));
    } else {
      vcallRemoteGrid.removeAttribute('data-tile-count');
    }
    if(primaryTiles.length > 0){
      vcallRemoteGrid.setAttribute('data-primary-count', String(Math.min(3, primaryTiles.length)));
    } else {
      vcallRemoteGrid.removeAttribute('data-primary-count');
    }
    const useHostLayout = callState.scope === 'group' && callState.mode !== 'voice' && tileCount > 0;
    const usePinnedHostLayout = useHostLayout && !!hostTile && primaryTiles.length >= 1 && primaryTiles.length <= 3;
    vcallShell.classList.toggle('host-layout', useHostLayout);
    vcallShell.classList.toggle('pinned-host-layout', usePinnedHostLayout);
    refreshGroupRoomCount();
  }

  function ensureLocalGroupTile(){
    if(!vcallRemoteGrid || !vcallLocalVideo || callState.scope !== 'group' || callState.mode === 'voice') return null;
    if(!vcallLocalTile){
      const tile = document.createElement('div');
      tile.className = 'vcall-remote-tile vcall-local-tile has-video';
      tile.setAttribute('data-user-id', String(currentUserId || 'me'));

      const card = document.createElement('div');
      card.className = 'vcall-remote-card';
      card.innerHTML =
        '<div class="vcall-remote-avatar"><img src="' + avatarUrlForPerson('', meDisplay || 'You') + '" alt="' + esc(meDisplay || 'You') + '"></div>' +
        '<div class="vcall-remote-name">You</div>' +
        '<div class="vcall-remote-status">In call</div>';

      tile.appendChild(card);
      vcallLocalTile = tile;
    }
    if(vcallLocalVideo.parentNode !== vcallLocalTile){
      vcallLocalTile.insertBefore(vcallLocalVideo, vcallLocalTile.firstChild);
    }
    if(!vcallLocalTile.isConnected){
      vcallRemoteGrid.insertBefore(vcallLocalTile, vcallRemoteGrid.firstChild);
    }
    vcallLocalTile.classList.toggle('is-voice', callState.mode === 'voice');
    vcallLocalTile.classList.add('has-video');
    updateGroupHostLayout();
    return vcallLocalTile;
  }

  function restoreLocalVideoStage(){
    if(!vcallLocalVideo || !vcallLocalHome) return;
    if(vcallLocalVideo.parentNode !== vcallLocalHome){
      if(vcallLocalNextSibling && vcallLocalNextSibling.parentNode === vcallLocalHome){
        vcallLocalHome.insertBefore(vcallLocalVideo, vcallLocalNextSibling);
      } else {
        vcallLocalHome.appendChild(vcallLocalVideo);
      }
    }
    if(vcallLocalTile && vcallLocalTile.parentNode){
      vcallLocalTile.parentNode.removeChild(vcallLocalTile);
    }
    vcallLocalTile = null;
  }
  if(peerCode) setTimeout(scrollToBottom, 60);
  if(peerCode) setTimeout(scrollVideoCallChatToBottom, 60);

  function esc(s){
    return String(s ?? '')
      .replace(/&/g,'&amp;')
      .replace(/</g,'&lt;')
      .replace(/>/g,'&gt;')
      .replace(/"/g,'&quot;')
      .replace(/'/g,'&#039;');
  }

  function nl2brSafe(s){
    return esc(String(s ?? '')).replace(/\n/g, '<br>');
  }

  function detectAttachmentKind(url, type){
    const u = String(url || '');
    const t = String(type || '').toLowerCase();
    if(!u) return '';
    if(t.startsWith('image/') || /\.(png|jpe?g|gif|webp)$/i.test(u)) return 'image';
    if(t.startsWith('video/') || /\.(mp4|webm|ogg|mov)$/i.test(u)) return 'video';
    if(t === 'application/pdf' || /\.pdf$/i.test(u)) return 'pdf';
    return 'file';
  }

  function normalizeMessageTextForAttachment(text, attachmentUrl){
    const body = String(text || '').trim();
    const hasAttachment = String(attachmentUrl || '').trim() !== '';
    if(!hasAttachment) return body;
    if(/^\[(attachment|image|photo|file)\]$/i.test(body)) return '';
    if(/^(attachment|image|photo|file)$/i.test(body)) return '';
    return body;
  }

  function ensureDayDivider(dayKey, dayLabel){
    if(!chatStream || !dayKey) return;
    const lastDivider = chatStream.querySelector('.dayDivider:last-of-type');
    const lastDay = lastDivider ? String(lastDivider.getAttribute('data-day') || '') : '';
    if(lastDay === String(dayKey)) return;
    const div = document.createElement('div');
    div.className = 'day-divider dayDivider';
    div.setAttribute('data-day', String(dayKey));
    div.textContent = String(dayLabel || '');
    chatStream.appendChild(div);
  }

  function buildAttachmentHtml(item){
    const url = String(item.attachment_url || '');
    const type = String(item.attachment_type || '');
    const orig = String(item.attachment_original || '').trim() || (url ? url.split('/').pop() : 'Attachment');
    if(!url) return '';
    const kind = detectAttachmentKind(url, type);
    if(kind === 'image'){
      return '<div style="margin-top:6px;"><a href="javascript:void(0);" class="mediaOpen" data-url="'+esc(url)+'" data-type="'+esc(type || 'image/*')+'" data-orig="'+esc(orig)+'"><img src="'+esc(url)+'" alt="'+esc(orig)+'" style="cursor:pointer;border:1px solid rgba(0,0,0,.12);"></a></div>';
    }
    if(kind === 'video'){
      return '<div style="margin-top:6px;"><div class="mediaOpen" data-url="'+esc(url)+'" data-type="'+esc(type || 'video/mp4')+'" data-orig="'+esc(orig)+'" style="cursor:pointer;"><video controls style="border:1px solid rgba(0,0,0,.12);"><source src="'+esc(url)+'" type="'+esc(type || 'video/mp4')+'"></video></div></div>';
    }
    if(kind === 'pdf'){
      return '<div style="margin-top:6px;"><button type="button" class="btn btn-outline-secondary btn-sm mediaOpen" data-url="'+esc(url)+'" data-type="application/pdf" data-orig="'+esc(orig)+'" style="border-radius:12px;">View PDF</button><div style="margin-top:6px;font-size:12px;opacity:.85;">'+esc(orig)+'</div></div>';
    }
    return '<div style="margin-top:6px;"><a href="javascript:void(0);" class="mediaOpen" data-url="'+esc(url)+'" data-type="'+esc(type)+'" data-orig="'+esc(orig)+'" style="text-decoration:underline;"><i class="fa fa-paperclip"></i> '+esc(orig)+'</a><a href="'+esc(url)+'" target="_blank" rel="noopener" style="font-size:12px;margin-left:8px;text-decoration:underline;opacity:.85;">Open</a></div>';
  }

  function buildReplyLineHtml(item, isMe){
    const replyText = String(item.reply_text || '').trim();
    if(!replyText || Number(item.deleted_for_all || 0) === 1) return '';
    const replyAuthor = String(item.reply_author || '').trim() || (isMe ? 'yourself' : peerDisplay);
    return '<div class="msg-reply-line" data-reply-target-id="'+String(Number(item.reply_message_id || 0))+'"><div class="msg-reply-line-title">Replying to '+esc(replyAuthor)+'</div><div class="msg-reply-line-text">'+esc(replyText)+'</div></div>';
  }

  function normalizeReactions(input){
    if(!Array.isArray(input)) return [];
    return input.map(function(value){
      return String(value || '').trim();
    }).filter(function(value){
      return value !== '';
    });
  }

  function buildReactionsHtml(reactions){
    const list = normalizeReactions(reactions);
    if(!list.length) return '';
    return '<div class="msg-reactions">' + list.map(function(emoji){
      return '<span class="msg-reaction-pill">'+esc(emoji)+'</span>';
    }).join('') + '</div>';
  }

  function buildMessageActionRail(id, isMe, peer, hasMedia){
    const peerVal = String(peer || peerCode || '');
    return '<div class="msg-side-actions '+(isMe ? 'left' : 'right')+(hasMedia ? ' is-media-actions' : '')+'" data-message-id="'+esc(String(id || ''))+'">' +
      '<a type="button" class="msg-side-btn msg-dot-btn" data-message-id="'+esc(String(id || ''))+'" data-is-me="'+(isMe ? '1' : '0')+'" data-peer="'+esc(peerVal)+'" aria-label="Message actions"><i class="fa fa-ellipsis-v"></i></a>' +
      '<a type="button" class="msg-side-btn msg-reply-btn" data-message-id="'+esc(String(id || ''))+'" aria-label="Reply"><i class="fa fa-reply"></i></a>' +
      '<a type="button" class="msg-side-btn msg-react-btn" data-message-id="'+esc(String(id || ''))+'" aria-label="React"><i class="fa fa-smile-o"></i></a>' +
    '</div>';
  }

  function messageDisplayText(item, isMe){
    if(Number(item.deleted_for_all || 0) === 1){
      return isMe ? 'You unsent a message.' : 'This message was unsent.';
    }
    const callEventText = callEventDisplayText(item.text || '', isMe);
    if(callEventText !== null) return callEventText;
    return String(item.text || '').trim();
  }

  const callEventPrefix = '[[MSB_CALL_EVENT:';

  function callEventPayloadFromText(text){
    const raw = String(text || '').trim();
    if(!raw || raw.indexOf(callEventPrefix) !== 0 || raw.slice(-2) !== ']]') return null;
    try{
      const payload = JSON.parse(raw.slice(callEventPrefix.length, -2));
      return payload && typeof payload === 'object' ? payload : null;
    }catch(_e){
      return null;
    }
  }

  function callEventPossessiveName(name){
    const cleaned = String(name || '').trim();
    if(!cleaned) return 'their';
    return /s$/i.test(cleaned) ? cleaned + "'" : cleaned + "'s";
  }

  function callEventDisplayText(text, isMe){
    const payload = callEventPayloadFromText(text);
    if(!payload) return null;
    const action = String(payload.action || '').trim().toLowerCase();
    const actor = String(payload.actor || '').trim() || 'They';
    const target = callEventPossessiveName(payload.target || '');
    if(action === 'deny' || action === 'denied' || action === 'decline' || action === 'declined'){
      return isMe ? ('You denied ' + target + ' call') : (actor + ' denied your call');
    }
    if(action === 'miss' || action === 'missed' || action === 'unavailable'){
      return isMe ? ('You missed ' + target + ' call') : (actor + ' is not avalible yet. Please call me later');
    }
    if(action === 'end' || action === 'ended'){
      return isMe ? ('You ended ' + target + ' call') : (actor + ' ended your call');
    }
    return null;
  }

  function privateCallEventMessage(action){
    return callEventPrefix + JSON.stringify({
      action: String(action || ''),
      actor: String(meDisplay || 'You'),
      target: String(peerDisplay || 'this contact')
    }) + ']]';
  }

  let pendingMessageSeq = 0;

  function nextPendingMessageId(){
    pendingMessageSeq -= 1;
    return pendingMessageSeq;
  }

  function nowDayKey(dateObj){
    const d = dateObj instanceof Date ? dateObj : new Date();
    const y = d.getFullYear();
    const m = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    return y + '-' + m + '-' + day;
  }

  function nowDayLabel(dateObj){
    const d = dateObj instanceof Date ? dateObj : new Date();
    return d.toLocaleDateString(undefined, { month:'short', day:'numeric', year:'numeric' });
  }

  function nowTimeLabel(dateObj){
    const d = dateObj instanceof Date ? dateObj : new Date();
    return d.toLocaleString(undefined, {
      month:'short',
      day:'2-digit',
      year:'numeric',
      hour:'2-digit',
      minute:'2-digit'
    });
  }

  function buildPendingDirectMessageItem(messageText, options){
    const opts = options || {};
    const now = new Date();
    const attachmentFile = opts.attachmentFile || null;
    const gifUrl = String(opts.gifUrl || '');
    const gifTitle = String(opts.gifTitle || 'GIF');
    const skipReply = !!opts.skipReply;
    let attachmentUrl = '';
    let attachmentType = '';
    let attachmentOriginal = '';

    if(attachmentFile){
      attachmentUrl = URL.createObjectURL(attachmentFile);
      attachmentType = String(attachmentFile.type || '');
      attachmentOriginal = String(attachmentFile.name || 'Attachment');
    } else if(gifUrl){
      attachmentUrl = gifUrl;
      attachmentType = 'image/gif';
      attachmentOriginal = gifTitle !== '' ? (gifTitle + '.gif') : 'GIF.gif';
    }

    return {
      id: nextPendingMessageId(),
      is_me: true,
      text: String(messageText || '').trim(),
      created_at: now.toISOString(),
      time_label: 'Sending...',
      day_key: nowDayKey(now),
      day_label: nowDayLabel(now),
      is_read: 0,
      reply_author: (!skipReply && activeReplyPreviewData) ? String(activeReplyPreviewData.author || '') : '',
      reply_text: (!skipReply && activeReplyPreviewData) ? String(activeReplyPreviewData.text || '') : '',
      reply_message_id: skipReply ? 0 : (activeReplyMessageId || 0),
      attachment_url: attachmentUrl,
      attachment_type: attachmentType,
      attachment_original: attachmentOriginal,
      deleted_for_all: 0,
      edited_at: '',
      reactions: [],
      pending: true
    };
  }

  function buildPendingGroupMessageItem(messageText, options){
    const opts = options || {};
    const now = new Date();
    const attachmentFile = opts.attachmentFile || null;
    let attachmentUrl = '';
    let attachmentType = '';
    let attachmentOriginal = '';

    if(attachmentFile){
      attachmentUrl = URL.createObjectURL(attachmentFile);
      attachmentType = String(attachmentFile.type || '');
      attachmentOriginal = String(attachmentFile.name || 'Attachment');
    }

    return {
      id: nextPendingMessageId(),
      is_me: true,
      text: String(messageText || '').trim(),
      created_at: now.toISOString(),
      time_label: 'Sending...',
      day_key: nowDayKey(now),
      day_label: nowDayLabel(now),
      sender_name: meDisplay,
      friend_code: '',
      reply_author: activeReplyPreviewData ? String(activeReplyPreviewData.author || '') : '',
      reply_text: activeReplyPreviewData ? String(activeReplyPreviewData.text || '') : '',
      reply_message_id: activeReplyMessageId || 0,
      attachment_url: attachmentUrl,
      attachment_type: attachmentType,
      attachment_original: attachmentOriginal,
      pending: true
    };
  }

  function cleanupPendingItem(item){
    if(!item) return;
    const attachmentUrl = String(item.attachment_url || '');
    if(attachmentUrl.indexOf('blob:') === 0){
      try{ URL.revokeObjectURL(attachmentUrl); }catch(_e){}
    }
  }

  function appendMessageItem(item){
    if(!chatStream || !item) return;
    const id = Number(item.id || 0);
    if(id && chatStream.querySelector('.msgRow[data-id="'+id+'"]')) return;

    const isMe = !!item.is_me;
    const hasMedia = Number(item.deleted_for_all || 0) !== 1 && String(item.attachment_url || '').trim() !== '';
    const hasReply = String(item.reply_text || '').trim() !== '' && Number(item.deleted_for_all || 0) !== 1;
    const dayKey = String(item.day_key || '');
    const dayLabel = String(item.day_label || '');
    ensureDayDivider(dayKey, dayLabel);

    const row = document.createElement('div');
    row.className = 'msg-row msgRow' + (isMe ? ' me' : '') + (item.pending ? ' is-pending' : '');
    row.setAttribute('data-id', String(id || ''));
    row.setAttribute('data-day', dayKey);

    let html = '';
    if(!isMe){
      html += '<span class="msg-avatar-mini"><img src="'+esc(peerAvatarUrl)+'" alt="Avatar"></span>';
      html += '<div class="msg-peer-wrap">';
    }
    if(isMe){
      html += buildMessageActionRail(id, true, peerCode, hasMedia);
    }
    html += '<div class="msg-bubble-stack">';
    html += '<div class="bubble'+(isMe ? ' me' : '')+(hasReply ? ' has-reply' : '')+(hasMedia ? ' has-media' : '')+'" data-message-id="'+String(id || '')+'" data-is-me="'+(isMe ? '1' : '0')+'" data-peer="'+esc(peerCode)+'">';
    html += buildReplyLineHtml(item, isMe);
    const displayText = messageDisplayText(item, isMe);
    if(displayText !== ''){
      html += '<div class="msgText'+(Number(item.deleted_for_all || 0) === 1 ? ' is-unsent' : '')+'">'+nl2brSafe(displayText)+'</div>';
    }
    if(Number(item.deleted_for_all || 0) !== 1){
      html += buildAttachmentHtml(item);
    }
    if(Number(item.deleted_for_all || 0) !== 1 && String(item.edited_at || '').trim() !== ''){
      html += '<div class="msg-edited">Edited</div>';
    }
    html += '<div class="msg-meta'+(isMe ? ' me' : '')+'">';
    if(isMe){
      html += esc(meDisplay) + ' <span style="opacity:.6;">•</span> ' + esc(item.time_label || '') + (item.pending ? '' : (' <span style="opacity:.6;">•</span> <span class="msgTicks" data-msgid="'+String(id || '')+'">' + ((Number(item.is_read || 0) > 0) ? '✓✓' : '✓') + '</span>'));
    }else{
      html += esc(peerDisplay) + ' <span style="opacity:.6;">•</span> ' + esc(item.time_label || '');
    }
    html += '</div></div>';
    if(Number(item.deleted_for_all || 0) !== 1){
      html += buildReactionsHtml(item.reactions || []);
    }
    html += '</div>';
    if(!isMe){
      html += '<div class="msg-peer-actions'+(hasMedia ? ' is-media-actions' : '')+'" data-message-id="'+String(id || '')+'">' +
        '<a type="button" class="msg-side-btn msg-react-btn" data-message-id="'+String(id || '')+'" aria-label="React"><i class="fa fa-smile-o"></i></a>' +
        '<a type="button" class="msg-side-btn msg-reply-btn" data-message-id="'+String(id || '')+'" aria-label="Reply"><i class="fa fa-reply"></i></a>' +
        '<a type="button" class="msg-side-btn msg-dot-btn" data-message-id="'+String(id || '')+'" data-is-me="0" data-peer="'+esc(peerCode)+'" aria-label="Message actions"><i class="fa fa-ellipsis-v"></i></a>' +
      '</div>';
      html += '</div>';
    }
    row.innerHTML = html;
    chatStream.appendChild(row);
    if(id > lastId) lastId = id;
    appendVideoCallChatItem(item);
  }

  function appendVideoCallChatItem(item){
    if(!vcallChatGroup || !item) return;
    const id = Number(item.id || 0);
    if(id && vcallChatGroup.querySelector('.vcall-chat-row[data-id="'+String(id)+'"]')) return;

    const isMe = !!item.is_me;
    const displayText = String(messageDisplayText(item, isMe) || '').trim();
    const attachmentUrl = String(item.attachment_url || '').trim();
    const attachmentOriginal = String(item.attachment_original || '').trim();
    const replyText = String(item.reply_text || '').trim();
    let body = displayText;

    if(!body && attachmentUrl){
      body = attachmentOriginal ? 'Attachment: ' + attachmentOriginal : 'Attachment';
    }
    if(!body) return;

    const row = document.createElement('div');
    row.className = 'vcall-chat-row' + (isMe ? ' me' : '');
    row.setAttribute('data-id', String(id || ''));

    const senderName = String(item.sender_name || (isMe ? 'You' : (peerDisplay || 'Contact')));
    const senderFriendCode = String(item.friend_code || '');
    const sidebarAvatarUrl = avatarUrlForPerson(
      senderFriendCode || (isMe ? '' : peerCode),
      senderName || 'Contact'
    );

    let html = '';
    if(!isMe){
      html += '<div class="vcall-chat-avatar"><img src="'+esc(sidebarAvatarUrl)+'" alt="'+esc(senderName || 'Contact')+'"></div>';
    }
    html += '<div class="vcall-chat-bubble">';
    if(replyText){
      html += '<div style="font-size:11px;font-weight:700;opacity:.72;margin-bottom:4px;">Reply: '+esc(replyText)+'</div>';
    }
    html += nl2brSafe(body);
    html += '<span class="vcall-chat-meta">'+esc(isMe ? 'You' : senderName)+'</span>';
    html += '</div>';
    row.innerHTML = html;
    vcallChatGroup.appendChild(row);
    setTimeout(scrollVideoCallChatToBottom, 20);
  }

  function appendGroupChatMessageItem(item){
    if(!chatStream || !item || !isGroupChatView || !selectedGroupId) return;
    const id = Number(item.id || 0);
    if(id && chatStream.querySelector('.msgRow[data-id="'+String(id)+'"]')) return;

    const isMe = !!item.is_me;
    const hasMedia = String(item.attachment_url || '').trim() !== '';
    const hasReply = String(item.reply_text || '').trim() !== '';
    const dayKey = String(item.day_key || '');
    const dayLabel = String(item.day_label || '');
    ensureDayDivider(dayKey, dayLabel);

    const senderName = String(item.sender_name || (isMe ? meDisplay : 'Member'));
    const senderFriendCode = String(item.friend_code || '');
    const text = normalizeMessageTextForAttachment(item.text || '', item.attachment_url || '');
    if(!text && !hasMedia) return;

    const row = document.createElement('div');
    row.className = 'msg-row msgRow' + (isMe ? ' me' : '') + (item.pending ? ' is-pending' : '');
    row.setAttribute('data-id', String(id || ''));
    row.setAttribute('data-day', dayKey);
    row.setAttribute('data-author', senderName);

    let html = '';
    if(!isMe){
      html += '<span class="msg-avatar-mini"><img src="' + esc(avatarUrlForPerson(senderFriendCode, senderName)) + '" alt="' + esc(senderName) + '"></span>';
      html += '<div class="msg-peer-wrap">';
    }
    if(isMe){
      html += '<div class="msg-side-actions left'+(hasMedia ? ' is-media-actions' : '')+'" data-message-id="'+String(id || '')+'">' +
        '<a type="button" class="msg-side-btn group-msg-dot-btn" data-message-id="'+String(id || '')+'" data-group-id="'+esc(String(selectedGroupId || ''))+'" data-is-me="1" aria-label="More actions"><i class="fa fa-ellipsis-v"></i></a>' +
        '<a type="button" class="msg-side-btn group-msg-reply-btn msg-reply-btn" data-message-id="'+String(id || '')+'" aria-label="Reply"><i class="fa fa-reply"></i></a>' +
        '<a type="button" class="msg-side-btn group-msg-react-btn msg-react-btn" data-message-id="'+String(id || '')+'" aria-label="React"><i class="fa fa-smile-o"></i></a>' +
      '</div>';
    }
    html += '<div class="msg-bubble-stack">';
    html += '<div class="bubble' + (isMe ? ' me' : '') + (hasMedia ? ' has-media' : '') + (hasReply ? ' has-reply' : '') + '" data-is-me="' + (isMe ? '1' : '0') + '">';
    html += buildReplyLineHtml(item, isMe);
    if(text !== ''){
      html += '<div class="msgText">' + nl2brSafe(text) + '</div>';
    }
    html += buildAttachmentHtml(item);
    html += '<div class="msg-meta' + (isMe ? ' me' : '') + '">' + esc(senderName) + ' <span style="opacity:.6;">•</span> ' + esc(item.time_label || '') + '</div>';
    html += '</div>';
    html += buildReactionsHtml(item.reactions || []);
    html += '</div>';
    if(!isMe){
      html += '<div class="msg-peer-actions'+(hasMedia ? ' is-media-actions' : '')+'" data-message-id="'+String(id || '')+'">' +
        '<a type="button" class="msg-side-btn group-msg-react-btn msg-react-btn" data-message-id="'+String(id || '')+'" aria-label="React"><i class="fa fa-smile-o"></i></a>' +
        '<a type="button" class="msg-side-btn group-msg-reply-btn msg-reply-btn" data-message-id="'+String(id || '')+'" aria-label="Reply"><i class="fa fa-reply"></i></a>' +
        '<a type="button" class="msg-side-btn group-msg-dot-btn" data-message-id="'+String(id || '')+'" data-group-id="'+esc(String(selectedGroupId || ''))+'" data-is-me="0" aria-label="More actions"><i class="fa fa-ellipsis-v"></i></a>' +
      '</div>';
      html += '</div>';
    }

    row.innerHTML = html;
    chatStream.appendChild(row);
    setTimeout(scrollToBottom, 20);
  }

  async function sendDirectMessage(messageText, options){
    const opts = options || {};
    const text = String(messageText || '').trim();
    const attachmentFile = opts.attachmentFile || null;
    const gifUrl = String(opts.gifUrl || '');
    const gifTitle = String(opts.gifTitle || '');
    const hasFile = !!attachmentFile;
    const hasGif = gifUrl !== '';
    if(!peerCode || (!text && !hasFile && !hasGif)) return { ok:false, error:'Empty message' };

    const fd = new FormData();
    fd.append('to', peerCode);
    fd.append('message', text);
    if(!opts.skipReply && activeReplyMessageId){
      fd.append('reply_message_id', String(activeReplyMessageId));
      if(activeReplyPreviewData){
        fd.append('reply_preview_author', String(activeReplyPreviewData.author || ''));
        fd.append('reply_preview_text', String(activeReplyPreviewData.text || ''));
      }
    }
    if(hasFile) fd.append('attachment', attachmentFile);
    if(hasGif){
      fd.append('gif_url', gifUrl);
      fd.append('gif_title', gifTitle || 'GIF');
    }

    const pendingItem = buildPendingDirectMessageItem(text, opts);
    appendMessageItem(pendingItem);
    scrollToBottom();

    let data = null;
    try{
      const res = await fetch('ajax/user_chat_send.php', { method:'POST', body: fd });
      data = await res.json();
    } finally {
      removeMessageRow(pendingItem.id);
      cleanupPendingItem(pendingItem);
    }

    if(data && data.ok && data.item){
      appendMessageItem({
        id: data.item.id,
        is_me: true,
        text: data.item.text || data.item.feedbackdata || '',
        created_at: data.item.created_at || '',
        time_label: data.item.time_label || '',
        day_key: data.item.day_key || '',
        day_label: data.item.day_label || '',
        is_read: data.item.is_read || 0,
        reply_author: data.item.reply_author || '',
        reply_text: data.item.reply_text || '',
        reply_message_id: data.item.reply_message_id || 0,
        attachment_url: data.item.attachment_url || '',
        attachment_type: data.item.attachment_type || '',
        attachment_original: data.item.attachment_original || '',
        deleted_for_all: data.item.deleted_for_all || 0,
        edited_at: data.item.edited_at || '',
        reactions: Array.isArray(data.item.reactions) ? data.item.reactions : []
      });
    }

    return data;
  }

  async function postPrivateCallEventMessage(action){
    if(!peerCode || isGroupCallContext()) return;
    try{
      await sendDirectMessage(privateCallEventMessage(action), { skipReply:true });
    }catch(_e){}
  }

  async function sendGroupVideoCallMessage(messageText, options){
    const opts = options || {};
    const text = String(messageText || '').trim();
    const attachmentFile = opts.attachmentFile || null;
    if(!selectedGroupId || (!text && !attachmentFile)) return { ok:false, error:'Empty message' };

    const fd = new FormData();
    fd.append('group_id', String(selectedGroupId));
    fd.append('message', text);
    if(activeReplyMessageId){
      fd.append('reply_message_id', String(activeReplyMessageId));
      if(activeReplyPreviewData){
        fd.append('reply_preview_author', String(activeReplyPreviewData.author || ''));
        fd.append('reply_preview_text', String(activeReplyPreviewData.text || ''));
      }
    }
    if(attachmentFile) fd.append('attachment', attachmentFile);

    const pendingItem = buildPendingGroupMessageItem(text, opts);
    appendGroupChatMessageItem(pendingItem);
    scrollToBottom();

    let data = null;
    try{
      const res = await fetch('ajax/group_chat_send.php', { method:'POST', body: fd });
      data = await res.json();
    } finally {
      removeMessageRow(pendingItem.id);
    }
    if(data && data.ok && data.item){
      appendGroupChatMessageItem(data.item);
      appendVideoCallChatItem(data.item);
      if(Number(data.item.id || 0) > lastGroupVideoChatId){
        lastGroupVideoChatId = Number(data.item.id || 0);
      }
    }
    return data;
  }

  function setCallStatus(text){
    if(vcallStatus){
      if(callState.scope === 'group' && callState.mode !== 'voice'){
        vcallStatus.textContent = groupRoomCountLabel();
      } else {
        vcallStatus.textContent = String(text || '');
      }
    }
    if(vcallRemoteEmptyText) vcallRemoteEmptyText.textContent = String(text || '');
  }

  function groupRoomCountLabel(){
    const tileCount = vcallRemoteGrid ? Math.min(12, vcallRemoteGrid.querySelectorAll('.vcall-remote-tile').length) : 0;
    const count = tileCount || (callState.acceptedGroupCall ? 1 : 0);
    return String(count) + '/50';
  }

  function refreshGroupRoomCount(){
    if(vcallStatus && callState.scope === 'group' && callState.mode !== 'voice'){
      vcallStatus.textContent = groupRoomCountLabel();
    }
  }

  function isGroupCallContext(){
    return !!(isGroupChatView && selectedGroupId > 0);
  }

  function avatarUrlForPerson(friendCode, displayName){
    return 'avatar.php?friend_code=' + encodeURIComponent(String(friendCode || '')) + '&name=' + encodeURIComponent(String(displayName || 'Participant'));
  }

  function getCallParticipantMeta(userId){
    const uid = Number(userId || 0);
    if(uid === currentUserId){
      return {
        user_id: currentUserId,
        display_name: meDisplay || 'You',
        friend_code: ''
      };
    }
    return callState.participantDirectory.get(uid) || groupMemberDirectory.get(uid) || {
      user_id: uid,
      display_name: 'Participant',
      friend_code: ''
    };
  }

  function updateGroupParticipantDirectory(participants){
    callState.participantDirectory = new Map();
    (participants || []).forEach(function(participant){
      const uid = Number(participant.user_id || 0);
      if(!uid) return;
      callState.participantDirectory.set(uid, {
        user_id: uid,
        display_name: String(participant.display_name || 'Participant'),
        friend_code: String(participant.friend_code || '')
      });
    });
  }

  function renderGroupJoinRequests(participants, call){
    if(!vcallJoinRequests) return;
    const starterId = Number((call && call.started_by_user_id) || callState.startedByUserId || 0);
    callState.startedByUserId = starterId;
    const canManage = callState.scope === 'group' && starterId > 0 && starterId === currentUserId;
    const requests = (participants || []).filter(function(participant){
      return String(participant.invite_status || '').toLowerCase() === 'requested'
        && Number(participant.user_id || 0) !== currentUserId;
    });

    if(!canManage || !requests.length){
      vcallJoinRequests.style.display = 'none';
      vcallJoinRequests.innerHTML = '';
      return;
    }

    vcallJoinRequests.innerHTML = requests.slice(0, 4).map(function(participant){
      const uid = Number(participant.user_id || 0);
      const name = String(participant.display_name || 'Member');
      return '<div class="vcall-join-request" data-user-id="'+uid+'">'
        + '<span class="vcall-join-request-name">'+esc(name)+' wants to join</span>'
        + '<button type="button" class="deny" data-action="deny" aria-label="Deny"><i class="fa fa-times"></i></button>'
        + '<button type="button" class="accept" data-action="accept" aria-label="Accept"><i class="fa fa-check"></i></button>'
        + '</div>';
    }).join('');
    vcallJoinRequests.style.display = 'flex';
  }

  async function respondGroupJoinRequest(userId, action){
    const uid = Number(userId || 0);
    if(!uid || callState.scope !== 'group' || !callState.callId) return;
    const signalType = action === 'accept' ? 'join_approved' : 'join_denied';
    try{
      const data = await sendCallSignal(signalType, { at: Date.now() }, uid);
      const participants = Array.isArray(data && data.participants) ? data.participants : (callState.incomingParticipants || []);
      callState.incomingParticipants = participants;
      updateGroupParticipantDirectory(participants);
      renderGroupJoinRequests(participants, { started_by_user_id: callState.startedByUserId });
      setCallStatus(action === 'accept' ? 'Join request accepted' : 'Join request denied');
    }catch(err){
      setCallStatus((err && err.message) ? err.message : 'Could not update join request');
    }
  }

  function ensureRemoteTile(userId){
    if(!vcallRemoteGrid) return null;
    const uid = Number(userId || 0);
    if(!uid) return null;
    if(uid === currentUserId) return ensureLocalGroupTile();
    const existing = callState.remoteParticipants.get(uid);
    if(existing && existing.tile && existing.tile.isConnected) return existing;
    if(callState.scope === 'group' && callState.remoteParticipants.size >= 11) return null;

    const meta = getCallParticipantMeta(uid);
    const tile = document.createElement('div');
    tile.className = 'vcall-remote-tile' + (callState.mode === 'voice' ? ' is-voice' : '');
    tile.setAttribute('data-user-id', String(uid));

    const video = document.createElement('video');
    video.autoplay = true;
    video.playsInline = true;

    const snapshot = document.createElement('img');
    snapshot.className = 'vcall-remote-snapshot';
    snapshot.alt = meta.display_name || 'Remote video';

    const card = document.createElement('div');
    card.className = 'vcall-remote-card';
    card.innerHTML =
      '<div class="vcall-remote-avatar"><img src="' + avatarUrlForPerson(meta.friend_code || '', meta.display_name || 'Participant') + '" alt="' + esc(meta.display_name || 'Participant') + '"></div>' +
      '<div class="vcall-remote-name">' + esc(meta.display_name || 'Participant') + '</div>' +
      '<div class="vcall-remote-status">Connecting…</div>';

    tile.appendChild(video);
    tile.appendChild(snapshot);
    tile.appendChild(card);
    vcallRemoteGrid.appendChild(tile);

    const remote = {
      userId: uid,
      tile: tile,
      video: video,
      snapshot: snapshot,
      card: card,
      statusEl: card.querySelector('.vcall-remote-status'),
      stream: new MediaStream(),
      pc: null
    };
    video.srcObject = remote.stream;
    callState.remoteParticipants.set(uid, remote);
    updateGroupHostLayout();
    return remote;
  }

  function updateRemoteTileStatus(userId, text, hasVideo){
    const remote = ensureRemoteTile(userId);
    if(!remote) return;
    if(remote.statusEl) remote.statusEl.textContent = String(text || '');
    remote.tile.classList.toggle('has-video', !!hasVideo);
    remote.tile.classList.toggle('is-voice', callState.mode === 'voice');
  }

  function removeRemoteTile(userId){
    const uid = Number(userId || 0);
    const remote = callState.remoteParticipants.get(uid);
    if(!remote) return;
    if(remote.pc){
      try{ remote.pc.close(); }catch(_e){}
    }
    stopMediaStream(remote.stream);
    if(remote.tile && remote.tile.parentNode){
      remote.tile.parentNode.removeChild(remote.tile);
    }
    callState.remoteParticipants.delete(uid);
    callState.peerConnections.delete(uid);
    callState.connectedPeerIds.delete(uid);
    callState.pendingOffers.delete(uid);
    updateGroupHostLayout();
  }

  function updateRemoteStageState(){
    if(callState.scope === 'group'){
      const hasTiles = !!(vcallRemoteGrid && vcallRemoteGrid.children.length);
      if(vcallRemoteEmpty) vcallRemoteEmpty.style.display = hasTiles ? 'none' : '';
      return;
    }
    if(vcallRemoteSnapshot && vcallRemoteSnapshot.style.display !== 'none'){
      if(vcallRemoteEmpty) vcallRemoteEmpty.style.display = 'none';
      return;
    }
    if(vcallRemoteEmpty) vcallRemoteEmpty.style.display = '';
  }

  function isIosSnapshotPayload(payload){
    return !!(payload && typeof payload === 'object' && String(payload.type || '').toLowerCase() === 'ios_snapshot' && payload.image);
  }

  function isIosMobileCallPayload(payload){
    return !!(payload && typeof payload === 'object' && String(payload.platform || '').toLowerCase() === 'ios');
  }

  function isMobileAppCallPayload(payload){
    if(!payload || typeof payload !== 'object') return false;
    const platform = String(payload.platform || '').toLowerCase();
    return platform === 'ios' || platform === 'web';
  }

  function showIosRemoteSnapshot(payload){
    if(!isIosSnapshotPayload(payload) || !vcallRemoteSnapshot) return false;
    const mime = String(payload.mime || 'image/jpeg');
    const rawImage = String(payload.image || '');
    vcallRemoteSnapshot.src = rawImage.indexOf('data:') === 0 ? rawImage : ('data:' + mime + ';base64,' + rawImage);
    vcallRemoteSnapshot.style.display = 'block';
    if(vcallRemoteVideo) vcallRemoteVideo.style.display = 'none';
    if(vcallRemoteEmpty) vcallRemoteEmpty.style.display = 'none';
    if(vcallIncomingBox) vcallIncomingBox.style.display = 'none';
    if(!callState.connectedAt) callState.connectedAt = Date.now();
    startCallClock();
    setCallStatus(callState.mode === 'voice' ? 'Connected' : 'Connected');
    return true;
  }

  function showGroupRemoteSnapshot(userId, payload){
    if(!isIosSnapshotPayload(payload)) return false;
    const remote = ensureRemoteTile(userId);
    if(!remote || !remote.snapshot) return false;
    const mime = String(payload.mime || 'image/jpeg');
    const rawImage = String(payload.image || '');
    remote.snapshot.src = rawImage.indexOf('data:') === 0 ? rawImage : ('data:' + mime + ';base64,' + rawImage);
    remote.snapshot.style.display = 'block';
    if(remote.video) remote.video.style.display = 'none';
    callState.connectedPeerIds.add(Number(userId || 0));
    updateRemoteTileStatus(userId, 'Connected', true);
    if(!callState.connectedAt) callState.connectedAt = Date.now();
    startCallClock();
    updateRemoteStageState();
    return true;
  }

  function stopBrowserSnapshotLoop(){
    if(callState.browserSnapshotTimer){
      clearInterval(callState.browserSnapshotTimer);
      callState.browserSnapshotTimer = null;
    }
    callState.browserSnapshotBusy = false;
  }

  function startBrowserSnapshotLoop(){
    if(callState.mode === 'voice' || !vcallLocalVideo) return;
    if(callState.browserSnapshotTimer) return;
    sendBrowserSnapshotFrame().catch(function(){});
    callState.browserSnapshotTimer = setInterval(function(){
      sendBrowserSnapshotFrame().catch(function(){});
    }, 320);
  }

  async function sendBrowserSnapshotFrame(){
    if(!callState.callId || callState.mode === 'voice' || !vcallLocalVideo || !callState.localStream) return;
    if(callState.browserSnapshotBusy) return;
    callState.browserSnapshotBusy = true;
    try{
      const videoTrack = callState.localStream.getVideoTracks ? callState.localStream.getVideoTracks()[0] : null;
      if(!videoTrack || !videoTrack.enabled) return;
      const videoWidth = Number(vcallLocalVideo.videoWidth || 0);
      const videoHeight = Number(vcallLocalVideo.videoHeight || 0);
      if(videoWidth <= 0 || videoHeight <= 0) return;
      const maxSide = 640;
      const scale = Math.min(1, maxSide / Math.max(videoWidth, videoHeight));
      const canvas = callState.browserSnapshotCanvas || document.createElement('canvas');
      callState.browserSnapshotCanvas = canvas;
      canvas.width = Math.max(1, Math.round(videoWidth * scale));
      canvas.height = Math.max(1, Math.round(videoHeight * scale));
      const ctx = canvas.getContext('2d');
      if(!ctx) return;
      ctx.drawImage(vcallLocalVideo, 0, 0, canvas.width, canvas.height);
      const dataUrl = canvas.toDataURL('image/jpeg', 0.58);
      const image = dataUrl.indexOf(',') >= 0 ? dataUrl.split(',')[1] : dataUrl;
      await sendCallSignal('ice', {
        type: 'ios_snapshot',
        platform: 'web',
        mime: 'image/jpeg',
        image: image,
        sent_at: Date.now()
      });
    }finally{
      callState.browserSnapshotBusy = false;
    }
  }

  function refreshCallOverlayMeta(){
    const isGroupScope = callState.scope === 'group';
    const title = isGroupScope ? (selectedGroupName || 'Group call') : (peerDisplay || (callState.mode === 'voice' ? 'Voice call' : 'Video call'));
    if(vcallMeetingName) vcallMeetingName.textContent = isGroupScope ? (selectedGroupName || 'Group call') : 'Private call';
    if(vcallPeerName) vcallPeerName.textContent = title;
    if(vcallStagePeerName) vcallStagePeerName.textContent = isGroupScope ? (selectedGroupName || 'Group members') : (peerDisplay || 'Remote participant');
    if(vcallSidebarTitle) vcallSidebarTitle.textContent = isGroupScope ? 'Group Chat' : 'Direct Chat';
    if(vcallSidebarSubtitle){
      vcallSidebarSubtitle.textContent = isGroupScope
        ? ('In-call messages for ' + (selectedGroupName || 'this group'))
        : ('One-to-one conversation with ' + (peerDisplay || 'this contact'));
    }
    if(vcallSidebarTab) vcallSidebarTab.textContent = isGroupScope ? 'Group chat' : 'Private chat';
    if(vcallComposeInput){
      vcallComposeInput.placeholder = isGroupScope
        ? ('Message ' + (selectedGroupName || 'group') + '...')
        : ('Message ' + (peerDisplay || 'contact') + '...');
    }
    if(vcallIncomingText){
      vcallIncomingText.textContent = callState.mode === 'voice'
        ? (isGroupScope ? 'Incoming group voice call' : 'Incoming voice call')
        : (isGroupScope ? 'Incoming group video call' : 'Incoming video call');
    }
    if(vcallShell){
      vcallShell.classList.toggle('group-mode', isGroupScope);
      vcallShell.classList.toggle('voice-mode', callState.mode === 'voice');
    }
    updateGroupHostLayout();
  }

  function formatCallClock(totalSeconds){
    const sec = Math.max(0, Number(totalSeconds || 0));
    const h = Math.floor(sec / 3600);
    const m = Math.floor((sec % 3600) / 60);
    const s = sec % 60;
    if(h > 0){
      return String(h).padStart(2, '0') + ':' + String(m).padStart(2, '0') + ':' + String(s).padStart(2, '0');
    }
    return String(m).padStart(2, '0') + ':' + String(s).padStart(2, '0');
  }

  function updateCallClock(){
    const now = Date.now();
    const startedAt = Number(callState.connectedAt || 0);
    const totalSeconds = startedAt ? Math.floor((now - startedAt) / 1000) : 0;
    if(vcallClock) vcallClock.textContent = formatCallClock(totalSeconds);
    if(vcallTimerLabel){
      vcallTimerLabel.textContent = 'Call time ' + formatCallClock(totalSeconds);
    }
  }

  function startCallClock(){
    if(callState.clockTimer) clearInterval(callState.clockTimer);
    updateCallClock();
    callState.clockTimer = setInterval(updateCallClock, 1000);
  }

  function stopCallClock(){
    if(callState.clockTimer){
      clearInterval(callState.clockTimer);
      callState.clockTimer = null;
    }
    if(vcallClock) vcallClock.textContent = '00:00';
    if(vcallTimerLabel) vcallTimerLabel.textContent = 'Call time 00:00';
  }

  function syncCallButtons(){
    const audioTrack = callState.localStream && callState.localStream.getAudioTracks ? callState.localStream.getAudioTracks()[0] : null;
    const videoTrack = callState.localStream && callState.localStream.getVideoTracks ? callState.localStream.getVideoTracks()[0] : null;
    if(vcallMuteBtn){
      const enabled = !audioTrack || audioTrack.enabled;
      vcallMuteBtn.classList.toggle('is-off', !enabled);
      vcallMuteBtn.innerHTML = enabled ? '<i class="fa fa-microphone"></i>' : '<i class="fa fa-microphone-slash"></i>';
    }
    if(vcallCameraBtn){
      const enabled = !videoTrack || videoTrack.enabled;
      vcallCameraBtn.classList.toggle('is-off', !enabled);
      vcallCameraBtn.innerHTML = enabled ? '<i class="fa fa-video-camera"></i>' : '<i class="fa fa-ban"></i>';
    }
  }

  function showVideoOverlay(){
    if(!videoCallOverlay) return;
    videoCallOverlay.style.display = 'block';
    videoCallOverlay.setAttribute('aria-hidden', 'false');
    setVideoCallSidebarOpen(false);
    if(!callState.visibleAt) callState.visibleAt = Date.now();
    refreshCallOverlayMeta();
    updateGroupHostLayout();
    if(vcallRemoteEmptyText) vcallRemoteEmptyText.textContent = (callState.mode === 'voice') ? 'Voice call in progress…' : 'Connecting video…';
    updateRemoteStageState();
    updateCallClock();
    syncCallButtons();
  }

  function hideVideoOverlay(){
    if(!videoCallOverlay) return;
    videoCallOverlay.style.display = 'none';
    videoCallOverlay.setAttribute('aria-hidden', 'true');
    if(vcallIncomingBox) vcallIncomingBox.style.display = 'none';
    if(vcallRemoteEmpty) vcallRemoteEmpty.style.display = '';
    if(vcallBody) vcallBody.classList.remove('is-sidebar-collapsed');
    if(vcallShell) vcallShell.classList.remove('host-layout', 'pinned-host-layout');
  }

  function stopMediaStream(stream){
    try{
      (stream && stream.getTracks ? stream.getTracks() : []).forEach(track => {
        try{ track.stop(); }catch(_e){}
      });
    }catch(_e){}
  }

  function cleanupCallUi(){
    const keepAfterSignalId = callState.afterSignalId;
    if(callState.disconnectTimer){
      clearTimeout(callState.disconnectTimer);
      callState.disconnectTimer = null;
    }
    stopBrowserSnapshotLoop();
    if(callState.pc){
      try{ callState.pc.close(); }catch(_e){}
    }
    callState.peerConnections.forEach(function(pc){
      try{ pc.close(); }catch(_e){}
    });
    Array.from(callState.remoteParticipants.keys()).forEach(removeRemoteTile);
    callState.pc = null;
    stopMediaStream(callState.localStream);
    stopMediaStream(callState.remoteStream);
    callState.localStream = null;
    callState.remoteStream = null;
    callState.incomingOffer = null;
    callState.incomingCall = null;
    callState.incomingParticipants = [];
    callState.startedByUserId = 0;
    callState.callId = 0;
    callState.afterSignalId = keepAfterSignalId;
    callState.isCaller = false;
    callState.scope = isGroupCallContext() ? 'group' : 'private';
    callState.mode = 'video';
    callState.visibleAt = 0;
    callState.connectedAt = 0;
    callState.acceptedGroupCall = false;
    callState.peerConnections = new Map();
    callState.remoteParticipants = new Map();
    callState.pendingOffers = new Map();
    callState.connectedPeerIds = new Set();
    callState.remoteIsMobileApp = false;
    restoreLocalVideoStage();
    if(vcallLocalVideo) vcallLocalVideo.srcObject = null;
    if(vcallRemoteVideo) vcallRemoteVideo.srcObject = null;
    if(vcallRemoteVideo) vcallRemoteVideo.style.display = '';
    if(vcallRemoteSnapshot){
      vcallRemoteSnapshot.removeAttribute('src');
      vcallRemoteSnapshot.style.display = 'none';
    }
    if(vcallRemoteGrid) vcallRemoteGrid.innerHTML = '';
    if(vcallJoinRequests){
      vcallJoinRequests.style.display = 'none';
      vcallJoinRequests.innerHTML = '';
    }
    updateGroupHostLayout();
    stopCallClock();
    syncCallButtons();
    hideVideoOverlay();
  }

  async function sendCallSignal(type, payload, targetUserId){
    if(!callState.callId) return;
    const fd = new FormData();
    fd.append('call_id', String(callState.callId));
    fd.append('signal_type', String(type));
    fd.append('scope', callState.scope === 'group' ? 'group' : 'private');
    if(callState.scope === 'group' && targetUserId){
      fd.append('target_user_id', String(targetUserId));
    }
    if(payload !== undefined && payload !== null){
      fd.append('payload', (typeof payload === 'string') ? payload : JSON.stringify(payload));
    }
    const res = await fetch('ajax/video_call_signal.php', { method:'POST', body: fd });
    let data = null;
    try{
      data = await res.json();
    }catch(_e){
      data = null;
    }
    if(!res.ok || !data || !data.ok){
      throw new Error((data && data.error) ? data.error : 'Could not send call signal.');
    }
    return data;
  }

  function normalizeRtcSessionDescription(desc){
    let candidate = desc;
    if(typeof candidate === 'string'){
      try{
        candidate = JSON.parse(candidate);
      }catch(_e){
        candidate = null;
      }
    }
    if(!candidate || typeof candidate !== 'object') return null;
    const type = String(candidate.type || '').trim().toLowerCase();
    const sdp = String(candidate.sdp || '').trim();
    if(!type || !sdp) return null;
    return { type, sdp };
  }

  async function fetchIncomingOfferForCurrentCall(){
    if(isGroupCallContext() || !peerCode || !callState.callId) return null;
    const qs = new URLSearchParams({
      peer: peerCode,
      call_id: String(callState.callId || 0),
      after_signal_id: '0',
      wait: '0'
    });
    const res = await fetch('ajax/video_call_poll.php?' + qs.toString(), { cache:'no-store' });
    const data = await res.json();
    if(!res.ok || !data || !data.ok){
      throw new Error((data && data.error) ? data.error : 'Could not load incoming call offer.');
    }
    const call = data && data.call && typeof data.call === 'object' ? data.call : null;
    let embeddedPayload = null;
    try{
      embeddedPayload = call && call.latest_offer_payload
        ? (typeof call.latest_offer_payload === 'string' ? JSON.parse(call.latest_offer_payload) : call.latest_offer_payload)
        : null;
    }catch(_e){
      embeddedPayload = null;
    }
    if(isIosMobileCallPayload(embeddedPayload)) return embeddedPayload;
    const embeddedOffer = normalizeRtcSessionDescription(embeddedPayload);
    if(embeddedOffer) return embeddedOffer;
    const signals = Array.isArray(data.signals) ? data.signals : [];
    for(let i = signals.length - 1; i >= 0; i -= 1){
      const sig = signals[i];
      if(String(sig && sig.signal_type || '') !== 'offer') continue;
      try{
        const payload = sig && sig.payload ? JSON.parse(sig.payload) : null;
        if(isIosMobileCallPayload(payload)) return payload;
        const parsed = normalizeRtcSessionDescription(payload);
        if(parsed) return parsed;
      }catch(_e){}
    }
    return null;
  }

  async function optimizePeerConnectionSenders(pc){
    if(!pc || !pc.getSenders) return;
    const senders = pc.getSenders() || [];
    for(const sender of senders){
      if(!sender || !sender.track || !sender.getParameters || !sender.setParameters) continue;
      const track = sender.track;
      if(track.kind === 'video'){
        try{ track.contentHint = 'motion'; }catch(_e){}
        try{
          const params = sender.getParameters() || {};
          if(!params.encodings || !params.encodings.length) params.encodings = [{}];
          params.encodings[0].maxBitrate = 500000;
          params.encodings[0].maxFramerate = 20;
          params.encodings[0].scaleResolutionDownBy = 1.0;
          await sender.setParameters(params);
        }catch(_e){}
      } else if(track.kind === 'audio'){
        try{
          const params = sender.getParameters() || {};
          if(!params.encodings || !params.encodings.length) params.encodings = [{}];
          params.encodings[0].maxBitrate = 32000;
          await sender.setParameters(params);
        }catch(_e){}
      }
    }
  }

  function shouldInitiateGroupOffer(remoteUserId){
    const remoteId = Number(remoteUserId || 0);
    return currentUserId > 0 && remoteId > 0 && currentUserId < remoteId;
  }

  function closeGroupPeerConnection(remoteUserId){
    const uid = Number(remoteUserId || 0);
    const pc = callState.peerConnections.get(uid);
    if(pc){
      try{ pc.close(); }catch(_e){}
    }
    removeRemoteTile(uid);
  }

  function ensureGroupPeerConnection(remoteUserId){
    const uid = Number(remoteUserId || 0);
    if(!uid) return null;
    const existing = callState.peerConnections.get(uid);
    if(existing) return existing;

    const remote = ensureRemoteTile(uid);
    const pc = new RTCPeerConnection({
      iceServers: [{ urls: ['stun:stun.l.google.com:19302'] }]
    });
    callState.peerConnections.set(uid, pc);
    if(remote){
      remote.pc = pc;
    }

    pc.onicecandidate = function(ev){
      if(ev.candidate){
        sendCallSignal('ice', ev.candidate.toJSON(), uid).catch(function(){});
      }
    };
    pc.ontrack = function(ev){
      const peerRemote = ensureRemoteTile(uid);
      if(!peerRemote) return;
      if(!peerRemote.stream) peerRemote.stream = new MediaStream();
      (ev.streams && ev.streams[0] ? ev.streams[0].getTracks() : []).forEach(function(track){
        try{ peerRemote.stream.addTrack(track); }catch(_e){}
      });
      if(peerRemote.video) peerRemote.video.srcObject = peerRemote.stream;
      if(peerRemote.video) peerRemote.video.style.display = '';
      if(peerRemote.snapshot) peerRemote.snapshot.style.display = 'none';
      callState.connectedPeerIds.add(uid);
      updateRemoteTileStatus(uid, 'Connected', true);
      if(!callState.connectedAt) callState.connectedAt = Date.now();
      startCallClock();
      updateRemoteStageState();
    };
    pc.onconnectionstatechange = function(){
      const state = String(pc.connectionState || '');
      if(state === 'connected'){
        callState.connectedPeerIds.add(uid);
        updateRemoteTileStatus(uid, 'Connected', true);
        if(!callState.connectedAt) callState.connectedAt = Date.now();
        startCallClock();
      } else if(state === 'connecting'){
        updateRemoteTileStatus(uid, 'Connecting…', false);
      } else if(state === 'disconnected' || state === 'failed' || state === 'closed'){
        callState.connectedPeerIds.delete(uid);
        if(state === 'closed'){
          removeRemoteTile(uid);
        } else {
          updateRemoteTileStatus(uid, 'Disconnected', false);
        }
        updateRemoteStageState();
      }
    };
    if(callState.localStream){
      callState.localStream.getTracks().forEach(function(track){
        try{ pc.addTrack(track, callState.localStream); }catch(_e){}
      });
      optimizePeerConnectionSenders(pc).catch(function(){});
    }
    updateRemoteTileStatus(uid, 'Connecting…', false);
    return pc;
  }

  async function createGroupOffer(remoteUserId){
    const uid = Number(remoteUserId || 0);
    if(!uid || !callState.callId || !callState.acceptedGroupCall) return;
    const pc = ensureGroupPeerConnection(uid);
    if(!pc || callState.pendingOffers.has(uid)) return;
    callState.pendingOffers.set(uid, true);
    try{
      const offer = await pc.createOffer();
      await pc.setLocalDescription(offer);
      await sendCallSignal('offer', offer, uid);
      updateRemoteTileStatus(uid, callState.mode === 'voice' ? 'Voice calling…' : 'Ringing…', false);
    }catch(_e){
      closeGroupPeerConnection(uid);
    }finally{
      callState.pendingOffers.delete(uid);
    }
  }

  async function syncGroupPeerConnections(participants){
    if(callState.scope !== 'group' || !callState.callId || !callState.acceptedGroupCall) return;
    ensureLocalGroupTile();
    const joinedPeers = (participants || []).filter(function(participant){
      const uid = Number(participant.user_id || 0);
      return uid > 0 && uid !== currentUserId && String(participant.invite_status || '') === 'joined';
    }).slice(0, 11);
    const joinedPeerIds = new Set(joinedPeers.map(function(participant){ return Number(participant.user_id || 0); }));

    Array.from(callState.peerConnections.keys()).forEach(function(uid){
      if(!joinedPeerIds.has(Number(uid))) closeGroupPeerConnection(Number(uid));
    });

    for(const participant of joinedPeers){
      const remoteUserId = Number(participant.user_id || 0);
      ensureRemoteTile(remoteUserId);
      const existingPc = callState.peerConnections.get(remoteUserId);
      if(shouldInitiateGroupOffer(remoteUserId) && !existingPc){
        await createGroupOffer(remoteUserId);
      } else {
        ensureGroupPeerConnection(remoteUserId);
      }
    }
    updateRemoteStageState();
  }

  function ensurePeerConnection(){
    if(callState.pc) return callState.pc;
    const pc = new RTCPeerConnection({
      iceServers: [{ urls: ['stun:stun.l.google.com:19302'] }]
    });
    callState.pc = pc;
    callState.remoteStream = new MediaStream();
    if(vcallRemoteVideo) vcallRemoteVideo.srcObject = callState.remoteStream;

    pc.onicecandidate = function(ev){
      if(ev.candidate){
        sendCallSignal('ice', ev.candidate.toJSON()).catch(function(){});
      }
    };
    pc.ontrack = function(ev){
      if(!callState.remoteStream) callState.remoteStream = new MediaStream();
      (ev.streams && ev.streams[0] ? ev.streams[0].getTracks() : []).forEach(track => {
        try{ callState.remoteStream.addTrack(track); }catch(_e){}
      });
      if(vcallRemoteVideo) vcallRemoteVideo.srcObject = callState.remoteStream;
      if(vcallRemoteEmpty) vcallRemoteEmpty.style.display = 'none';
      if(!callState.connectedAt) callState.connectedAt = Date.now();
      startCallClock();
      setCallStatus('Connected');
    };
    pc.onconnectionstatechange = function(){
      const s = String(pc.connectionState || '');
      if(s === 'connected'){
        if(callState.disconnectTimer){
          clearTimeout(callState.disconnectTimer);
          callState.disconnectTimer = null;
        }
        if(!callState.connectedAt) callState.connectedAt = Date.now();
        startCallClock();
        setCallStatus('Connected');
        if(vcallRemoteEmpty) vcallRemoteEmpty.style.display = 'none';
      }else if(s === 'connecting'){
        if(callState.disconnectTimer){
          clearTimeout(callState.disconnectTimer);
          callState.disconnectTimer = null;
        }
        setCallStatus('Connecting…');
      }else if(s === 'disconnected' && !callState.endedLocally){
        setCallStatus('Reconnecting…');
        try{
          if(pc.restartIce) pc.restartIce();
        }catch(_e){}
        if(callState.disconnectTimer) clearTimeout(callState.disconnectTimer);
        callState.disconnectTimer = setTimeout(function(){
          const currentState = String(pc.connectionState || '');
          if((currentState === 'disconnected' || currentState === 'failed') && !callState.endedLocally){
            setCallStatus('Call ended');
            cleanupCallUi();
          }
        }, 12000);
      }else if((s === 'failed' || s === 'closed') && !callState.endedLocally){
        setCallStatus('Call ended');
        setTimeout(cleanupCallUi, 900);
      }
    };
    if(callState.localStream){
      callState.localStream.getTracks().forEach(track => pc.addTrack(track, callState.localStream));
      optimizePeerConnectionSenders(pc).catch(function(){});
    }
    return pc;
  }

  async function ensureLocalMedia(mode){
    if(callState.localStream) return callState.localStream;
    const isVoice = String(mode || callState.mode || 'video') === 'voice';
    const stream = await navigator.mediaDevices.getUserMedia({
      video: isVoice ? false : {
        width: { ideal: 640, max: 960 },
        height: { ideal: 360, max: 540 },
        frameRate: { ideal: 18, max: 24 },
        facingMode: 'user'
      },
      audio: {
        echoCancellation: true,
        noiseSuppression: true,
        autoGainControl: true
      }
    });
    callState.localStream = stream;
    if(vcallLocalVideo){
      vcallLocalVideo.srcObject = stream;
      try{
        const playPromise = vcallLocalVideo.play ? vcallLocalVideo.play() : null;
        if(playPromise && playPromise.catch) playPromise.catch(function(){});
      }catch(_e){}
    }
    try{
      const videoTrack = stream.getVideoTracks ? stream.getVideoTracks()[0] : null;
      if(videoTrack) videoTrack.contentHint = 'motion';
    }catch(_e){}
    syncCallButtons();
    return stream;
  }

  async function startVideoCall(mode){
    if(!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia){
      alert('Call is not supported in this browser.');
      return;
    }
    try{
      const callMode = (String(mode || 'video') === 'voice') ? 'voice' : 'video';
      callState.endedLocally = false;
      callState.mode = callMode;
      callState.scope = isGroupCallContext() ? 'group' : 'private';
      showVideoOverlay();
      setCallStatus(callMode === 'voice'
        ? (callState.scope === 'group' ? 'Starting group voice call…' : 'Starting voice call…')
        : (callState.scope === 'group' ? 'Starting group video call…' : 'Starting video call…'));
      await ensureLocalMedia(callMode);
      const fd = new FormData();
      if(callState.scope === 'group'){
        fd.append('group_id', String(selectedGroupId || 0));
      } else {
        if(!peerCode) return;
        fd.append('peer', peerCode);
      }
      fd.append('mode', callMode);
      const res = await fetch('ajax/video_call_start.php', { method:'POST', body: fd });
      const data = await res.json();
      if(!data || !data.ok || !data.call_id) throw new Error((data && data.error) ? data.error : 'Could not start call');
      callState.callId = Number(data.call_id || 0);
      callState.mode = String(data.call_mode || callMode || 'video') === 'voice' ? 'voice' : 'video';
      callState.isCaller = true;
      if(callState.callId) callState.answeredCallIds.add(callState.callId);
      if(callState.scope === 'group'){
        callState.startedByUserId = Number(data.started_by_user_id || currentUserId || 0);
        updateGroupParticipantDirectory(Array.isArray(data.participants) ? data.participants : []);
        callState.incomingParticipants = Array.isArray(data.participants) ? data.participants : [];
        renderGroupJoinRequests(callState.incomingParticipants, { started_by_user_id: callState.startedByUserId });
        callState.acceptedGroupCall = true;
        refreshCallOverlayMeta();
        await sendCallSignal('join', { at: Date.now() });
        await syncGroupPeerConnections(callState.incomingParticipants);
        startBrowserSnapshotLoop();
        setCallStatus(callState.mode === 'voice' ? 'Group voice call started' : 'Group video call started');
      } else {
        const pc = ensurePeerConnection();
        const offer = await pc.createOffer();
        await pc.setLocalDescription(offer);
        await sendCallSignal('offer', offer);
        setCallStatus(callState.mode === 'voice' ? 'Voice calling…' : 'Ringing…');
      }
    }catch(err){
      cleanupCallUi();
      alert((err && err.message) ? err.message : 'Could not start call.');
    }
  }

  async function acceptIncomingCall(){
    if(!callState.incomingCall) return;
    try{
      callState.endedLocally = false;
      callState.callId = Number(callState.incomingCall.id || 0);
      if(callState.callId) callState.answeredCallIds.add(callState.callId);
      callState.mode = String(callState.incomingCall.call_mode || 'video') === 'voice' ? 'voice' : 'video';
      callState.isCaller = false;
      callState.scope = callState.incomingCall && Number(callState.incomingCall.group_id || 0) > 0 ? 'group' : 'private';
      showVideoOverlay();
      if(vcallIncomingBox) vcallIncomingBox.style.display = 'none';
      setCallStatus('Connecting…');
      await ensureLocalMedia(callState.mode);
      if(callState.scope === 'group'){
        callState.startedByUserId = Number(callState.incomingCall && callState.incomingCall.started_by_user_id ? callState.incomingCall.started_by_user_id : callState.startedByUserId || 0);
        updateGroupParticipantDirectory(callState.incomingParticipants || []);
        const joinResult = await sendCallSignal('join', { at: Date.now() });
        const joinedParticipants = Array.isArray(joinResult && joinResult.participants) ? joinResult.participants : (callState.incomingParticipants || []);
        callState.incomingParticipants = joinedParticipants;
        updateGroupParticipantDirectory(joinedParticipants);
        renderGroupJoinRequests(joinedParticipants, { started_by_user_id: callState.startedByUserId });
        const joinedStatus = String((joinResult && (joinResult.my_status || '')) || '').toLowerCase();
        if((joinResult && joinResult.requires_approval) || joinedStatus === 'requested'){
          callState.acceptedGroupCall = false;
          setCallStatus('Request sent. Waiting for host…');
          return;
        }
        callState.acceptedGroupCall = true;
        await syncGroupPeerConnections(joinedParticipants);
        startBrowserSnapshotLoop();
      } else {
        let incomingMobileOffer = isIosMobileCallPayload(callState.incomingOffer) ? callState.incomingOffer : null;
        let incomingOffer = normalizeRtcSessionDescription(callState.incomingOffer);
        if(!incomingOffer && !incomingMobileOffer){
          const fetchedOffer = await fetchIncomingOfferForCurrentCall();
          if(isIosMobileCallPayload(fetchedOffer)){
            incomingMobileOffer = fetchedOffer;
          } else {
            incomingOffer = normalizeRtcSessionDescription(fetchedOffer);
          }
        }
        if(!incomingOffer && !incomingMobileOffer){
          throw new Error('Incoming call offer is missing. Please try calling again.');
        }
        if(incomingMobileOffer){
          callState.remoteIsMobileApp = true;
          if(!callState.connectedAt) callState.connectedAt = Date.now();
          startCallClock();
          await sendCallSignal('answer', {
            type: 'answer',
            platform: 'web',
            mode: callState.mode,
            accepted_at: Date.now()
          });
          setCallStatus('Connected');
          if(vcallRemoteEmptyText) vcallRemoteEmptyText.textContent = callState.mode === 'voice' ? 'Voice call in progress…' : 'Receiving iPhone video…';
          startBrowserSnapshotLoop();
        } else {
          const pc = ensurePeerConnection();
          await pc.setRemoteDescription(new RTCSessionDescription(incomingOffer));
          const answer = await pc.createAnswer();
          await pc.setLocalDescription(answer);
          await sendCallSignal('answer', answer);
        }
      }
      callState.incomingOffer = null;
      callState.incomingCall = null;
      callState.incomingParticipants = [];
    }catch(err){
      alert((err && err.message) ? err.message : 'Could not accept call.');
      cleanupCallUi();
    }
  }

  async function endCurrentCall(signalType){
    callState.endedLocally = true;
    const endingCallId = Number(callState.callId || (callState.incomingCall && callState.incomingCall.id) || 0);
    if(endingCallId){
      callState.dismissedCallIds.add(endingCallId);
    }
    try{
      if(callState.callId && signalType){
        await sendCallSignal(signalType, { at: Date.now() });
      }
    }catch(_e){}
    if(!isGroupCallContext()){
      if(signalType === 'end'){
        postPrivateCallEventMessage('end');
      } else if(signalType === 'decline'){
        postPrivateCallEventMessage('deny');
      }
    }
    cleanupCallUi();
  }

  async function processCallSignals(signals, call){
    for(const sig of (signals || [])){
      const type = String(sig.signal_type || '');
      let payload = null;
      try{ payload = sig.payload ? JSON.parse(sig.payload) : null; }catch(_e){ payload = null; }
      const signalCallId = Number(call && call.id ? call.id : callState.callId || 0);
      const signalFromUserId = Number(sig.from_user_id || 0);
      const isGroupScope = !!(call && Number(call.group_id || 0) > 0);

      if(isGroupScope){
        callState.scope = 'group';
        if(type === 'offer'){
          if(!callState.acceptedGroupCall) continue;
          const pc = ensureGroupPeerConnection(signalFromUserId);
          const remoteOffer = normalizeRtcSessionDescription(payload);
          if(pc && remoteOffer){
            try{
              await pc.setRemoteDescription(new RTCSessionDescription(remoteOffer));
              const answer = await pc.createAnswer();
              await pc.setLocalDescription(answer);
              await sendCallSignal('answer', answer, signalFromUserId);
              updateRemoteTileStatus(signalFromUserId, 'Connected', false);
            }catch(_e){}
          }
        } else if(type === 'answer'){
          const pc = callState.peerConnections.get(signalFromUserId);
          const remoteAnswer = normalizeRtcSessionDescription(payload);
          if(pc && remoteAnswer){
            try{
              await pc.setRemoteDescription(new RTCSessionDescription(remoteAnswer));
              updateRemoteTileStatus(signalFromUserId, 'Connected', false);
            }catch(_e){}
          } else if(isIosMobileCallPayload(payload)){
            updateRemoteTileStatus(signalFromUserId, 'Connected', false);
          }
        } else if(type === 'ice'){
          if(isIosSnapshotPayload(payload)){
            showGroupRemoteSnapshot(signalFromUserId, payload);
            continue;
          }
          const pc = callState.peerConnections.get(signalFromUserId) || ensureGroupPeerConnection(signalFromUserId);
          if(pc && payload){
            try{ await pc.addIceCandidate(new RTCIceCandidate(payload)); }catch(_e){}
          }
        } else if(type === 'chat_message'){
          if(payload && typeof payload === 'object'){
            appendGroupChatMessageItem(payload);
            appendVideoCallChatItem(payload);
            if(Number(payload.id || 0) > lastGroupVideoChatId){
              lastGroupVideoChatId = Number(payload.id || 0);
            }
          }
        } else if(type === 'join_request'){
          const requester = getCallParticipantMeta(signalFromUserId);
          setCallStatus((requester.display_name || 'Someone') + ' requested to join');
          renderGroupJoinRequests(callState.incomingParticipants || [], call);
        } else if(type === 'join_approved'){
          callState.acceptedGroupCall = true;
          if(callState.callId) callState.answeredCallIds.add(callState.callId);
          if(vcallIncomingBox) vcallIncomingBox.style.display = 'none';
          setCallStatus('Connected');
          await syncGroupPeerConnections(callState.incomingParticipants || []);
        } else if(type === 'join_denied'){
          if(signalCallId) callState.dismissedCallIds.add(signalCallId);
          setCallStatus('Join request denied');
          setTimeout(cleanupCallUi, 900);
        } else if(type === 'decline' || type === 'end'){
          closeGroupPeerConnection(signalFromUserId);
        }
        continue;
      }

      if(type === 'offer'){
        if(signalCallId && (callState.dismissedCallIds.has(signalCallId) || callState.answeredCallIds.has(signalCallId))){
          continue;
        }
        callState.callId = Number(call && call.id ? call.id : 0);
        callState.incomingCall = call || null;
        callState.mode = (call && String(call.call_mode || 'video') === 'voice') ? 'voice' : 'video';
        callState.incomingOffer = normalizeRtcSessionDescription(payload) || (isIosMobileCallPayload(payload) ? payload : null);
        showVideoOverlay();
        setCallStatus(callState.mode === 'voice' ? 'Incoming voice call…' : 'Incoming video call…');
        if(vcallIncomingBox) vcallIncomingBox.style.display = 'block';
        if(pendingAutoAcceptCallId && signalCallId && Number(pendingAutoAcceptCallId) === Number(signalCallId)){
          pendingAutoAcceptCallId = 0;
          if(window.history && typeof window.history.replaceState === 'function'){
            const nextUrl = new URL(window.location.href);
            nextUrl.searchParams.delete('accept_call');
            window.history.replaceState({}, document.title, nextUrl.toString());
          }
          setTimeout(function(){ acceptIncomingCall(); }, 80);
        }
      } else if(type === 'answer'){
        const remoteAnswer = normalizeRtcSessionDescription(payload);
        if(callState.pc && remoteAnswer){
          if(signalCallId) callState.answeredCallIds.add(signalCallId);
          if(vcallIncomingBox) vcallIncomingBox.style.display = 'none';
          await callState.pc.setRemoteDescription(new RTCSessionDescription(remoteAnswer));
          setCallStatus('Connected');
        } else if(isMobileAppCallPayload(payload)){
          if(signalCallId) callState.answeredCallIds.add(signalCallId);
          if(vcallIncomingBox) vcallIncomingBox.style.display = 'none';
          if(!callState.connectedAt) callState.connectedAt = Date.now();
          startCallClock();
          callState.remoteIsMobileApp = true;
          setCallStatus('Connected');
          if(vcallRemoteEmptyText) vcallRemoteEmptyText.textContent = callState.mode === 'voice' ? 'Voice call in progress…' : 'Connected. Receiving iPhone video…';
          startBrowserSnapshotLoop();
        }
      } else if(type === 'ice'){
        if(showIosRemoteSnapshot(payload)){
          if(signalCallId) callState.answeredCallIds.add(signalCallId);
          continue;
        }
        if(callState.pc && payload){
          try{ await callState.pc.addIceCandidate(new RTCIceCandidate(payload)); }catch(_e){}
        }
      } else if(type === 'decline'){
        if(signalCallId) callState.dismissedCallIds.add(signalCallId);
        setCallStatus('Call denied');
        setTimeout(cleanupCallUi, 900);
      } else if(type === 'end'){
        if(signalCallId) callState.dismissedCallIds.add(signalCallId);
        setCallStatus('Call ended');
        setTimeout(cleanupCallUi, 900);
      }
    }
  }

  async function pollVideoCalls(){
    if((!peerCode && !isGroupCallContext()) || callState.pollBusy) return;
    callState.pollBusy = true;
    const fastGroupVideoPoll = callState.scope === 'group'
      && callState.mode === 'video'
      && !!callState.callId
      && !!callState.acceptedGroupCall;
    try{
      const qs = new URLSearchParams({
        after_signal_id: String(callState.afterSignalId || 0),
        call_id: String(callState.callId || 0),
        wait: fastGroupVideoPoll ? '0' : '10'
      });
      if(isGroupCallContext()){
        qs.set('group_id', String(selectedGroupId || 0));
      } else {
        qs.set('peer', peerCode);
      }
      const res = await fetch('ajax/video_call_poll.php?' + qs.toString(), { cache:'no-store' });
      const data = await res.json();
      if(data && data.ok){
        const call = data.call || null;
        const participants = Array.isArray(data.participants) ? data.participants : [];
        const currentPolledCallId = Number(call && call.id ? call.id : 0);
        if(call && call.call_mode){
          callState.mode = String(call.call_mode || 'video') === 'voice' ? 'voice' : 'video';
        }
        if(call && call.latest_offer_payload){
          const latestOffer = normalizeRtcSessionDescription(call.latest_offer_payload);
          if(latestOffer){
            callState.incomingOffer = latestOffer;
          }
        }
        if(call && Number(call.group_id || 0) > 0){
          callState.scope = 'group';
          callState.callId = currentPolledCallId || callState.callId;
          callState.startedByUserId = Number(call.started_by_user_id || callState.startedByUserId || 0);
          updateGroupParticipantDirectory(participants);
          callState.incomingParticipants = participants;
          renderGroupJoinRequests(participants, call);
          const myParticipant = participants.find(function(participant){
            return Number(participant.user_id || 0) === currentUserId;
          }) || null;
          const myStatus = myParticipant ? String(myParticipant.invite_status || '') : '';
          if(myStatus === 'invited' && currentPolledCallId && !callState.dismissedCallIds.has(currentPolledCallId) && !callState.answeredCallIds.has(currentPolledCallId)){
            callState.incomingCall = call;
            callState.incomingParticipants = participants;
            showVideoOverlay();
            setCallStatus(callState.mode === 'voice' ? 'Incoming group voice call…' : 'Incoming group video call…');
            if(vcallIncomingBox) vcallIncomingBox.style.display = 'block';
            if(pendingAutoAcceptCallId && Number(pendingAutoAcceptCallId) === Number(currentPolledCallId)){
              pendingAutoAcceptCallId = 0;
              if(window.history && typeof window.history.replaceState === 'function'){
                const nextUrl = new URL(window.location.href);
                nextUrl.searchParams.delete('accept_call');
                window.history.replaceState({}, document.title, nextUrl.toString());
              }
              setTimeout(function(){ acceptIncomingCall(); }, 80);
            }
          }
          if(myStatus === 'requested'){
            callState.acceptedGroupCall = false;
            callState.incomingCall = call;
            callState.incomingParticipants = participants;
            showVideoOverlay();
            if(vcallIncomingBox) vcallIncomingBox.style.display = 'none';
            setCallStatus('Request sent. Waiting for host…');
          }
          if(myStatus === 'joined'){
            callState.acceptedGroupCall = true;
            callState.answeredCallIds.add(currentPolledCallId);
            showVideoOverlay();
            if(vcallIncomingBox) vcallIncomingBox.style.display = 'none';
            await syncGroupPeerConnections(participants);
            startBrowserSnapshotLoop();
            if(callState.connectedPeerIds.size > 0){
              setCallStatus('Connected');
            } else {
              setCallStatus(callState.mode === 'voice' ? 'Waiting for members…' : 'Waiting for members…');
            }
          }
        }
        if(Number(data.last_signal_id || 0) > callState.afterSignalId){
          callState.afterSignalId = Number(data.last_signal_id || 0);
        }
        if(Array.isArray(data.signals) && data.signals.length){
          await processCallSignals(data.signals, call);
        }
        if(call && callState.scope !== 'group' && String(call.status || '') === 'active' && currentPolledCallId){
          callState.callId = currentPolledCallId;
          callState.answeredCallIds.add(currentPolledCallId);
          showVideoOverlay();
          if(vcallIncomingBox) vcallIncomingBox.style.display = 'none';
        }
        if(call && (String(call.status || '') === 'ended' || String(call.status || '') === 'declined' || String(call.status || '') === 'missed' || String(call.status || '') === 'failed')){
          if(callState.callId && Number(call.id || 0) === Number(callState.callId || 0)){
            callState.dismissedCallIds.add(Number(call.id || 0));
            const terminalStatus = String(call.status || '');
            setCallStatus(terminalStatus === 'missed' ? 'Call missed' : (terminalStatus === 'declined' ? 'Call denied' : 'Call ended'));
            setTimeout(cleanupCallUi, 900);
          }
        }
      }
    }catch(_e){
    }finally{
      callState.pollBusy = false;
      const nextPollDelay = (callState.scope === 'group'
        && callState.mode === 'video'
        && !!callState.callId
        && !!callState.acceptedGroupCall) ? 340 : 1200;
      setTimeout(pollVideoCalls, nextPollDelay);
    }
  }

  // ===== FIXED: Attach + Emoji popups =====
  const emojiBtn = document.getElementById('emojiBtn');
  const emojiMenu = document.getElementById('emojiMenu');
  const emojiGrid = document.getElementById('emojiGrid');
  const emojiClose = document.getElementById('emojiClose');
  const msgText = document.getElementById('messageInput');

  const fileAny = document.getElementById('msgFileAny');
  const plusBtn = document.getElementById('msgPlusBtn');

  const previewWrap = document.getElementById('msgPreviewInline');
  const previewThumb = document.getElementById('msgPreviewThumb');
  const previewName = document.getElementById('msgPreviewName');
  const previewRemove = document.getElementById('msgPreviewRemove');
  let autosizeMessageInput = function(){};
  const gifBtn = document.getElementById('gifBtn');
  const gifPickerOverlay = document.getElementById('gifPickerOverlay');
  const gifSearchInput = document.getElementById('gifSearchInput');
  const gifGrid = document.getElementById('gifGrid');
  const gifEmpty = document.getElementById('gifEmpty');
  const msgActionMenu = document.getElementById('msgActionMenu');
  const msgReactionPicker = document.getElementById('msgReactionPicker');
  const groupMessageActionForm = document.getElementById('groupMessageActionForm');
  const replyPreview = document.getElementById('replyPreview');
  const replyPreviewTitle = document.getElementById('replyPreviewTitle');
  const replyPreviewText = document.getElementById('replyPreviewText');
  const replyPreviewClose = document.getElementById('replyPreviewClose');
  const replyMessageIdInput = document.getElementById('replyMessageId');
  const replyPreviewAuthorInput = document.getElementById('replyPreviewAuthorInput');
  const replyPreviewTextInput = document.getElementById('replyPreviewTextInput');
  const btnVoiceCall = document.getElementById('btnVoiceCall');
  const btnVideoCall = document.getElementById('btnVideoCall');
  const videoCallOverlay = document.getElementById('videoCallOverlay');
  const vcallShell = videoCallOverlay ? videoCallOverlay.querySelector('.vcall-shell') : null;
  const vcallBody = videoCallOverlay ? videoCallOverlay.querySelector('.vcall-body') : null;
  const vcallMeetingName = document.getElementById('vcallMeetingName');
  const vcallPeerName = document.getElementById('vcallPeerName');
  const vcallStagePeerName = document.getElementById('vcallStagePeerName');
  const vcallStatus = document.getElementById('vcallStatus');
  const vcallClock = document.getElementById('vcallClock');
  const vcallTimerLabel = document.getElementById('vcallTimerLabel');
  const vcallRemoteVideo = document.getElementById('vcallRemoteVideo');
  const vcallRemoteSnapshot = document.getElementById('vcallRemoteSnapshot');
  const vcallRemoteGrid = document.getElementById('vcallRemoteGrid');
  const vcallLocalVideo = document.getElementById('vcallLocalVideo');
  const vcallLocalHome = vcallLocalVideo ? vcallLocalVideo.parentNode : null;
  const vcallLocalNextSibling = vcallLocalVideo ? vcallLocalVideo.nextSibling : null;
  let vcallLocalTile = null;
  const vcallRemoteEmpty = document.getElementById('vcallRemoteEmpty');
  const vcallRemoteEmptyText = document.getElementById('vcallRemoteEmptyText');
  const vcallIncomingBox = document.getElementById('vcallIncomingBox');
  const vcallIncomingText = document.getElementById('vcallIncomingText');
  const vcallJoinRequests = document.getElementById('vcallJoinRequests');
  const vcallAcceptBtn = document.getElementById('vcallAcceptBtn');
  const vcallDeclineBtn = document.getElementById('vcallDeclineBtn');
  const vcallCloseBtn = document.getElementById('vcallCloseBtn');
  const vcallEndBtn = document.getElementById('vcallEndBtn');
  const vcallMuteBtn = document.getElementById('vcallMuteBtn');
  const vcallCameraBtn = document.getElementById('vcallCameraBtn');
  const vcallMessagesBtn = document.getElementById('vcallMessagesBtn');
  const vcallSidebarCloseBtn = document.getElementById('vcallSidebarCloseBtn');
  const vcallSidebarTitle = document.getElementById('vcallSidebarTitle');
  const vcallSidebarSubtitle = document.getElementById('vcallSidebarSubtitle');
  const vcallSidebarTab = document.getElementById('vcallSidebarTab');

  const callState = {
    callId: 0,
    afterSignalId: 0,
    scope: 'private',
    groupId: selectedGroupId || 0,
    isCaller: false,
    pc: null,
    localStream: null,
    remoteStream: null,
    incomingOffer: null,
    incomingCall: null,
    incomingParticipants: [],
    startedByUserId: 0,
    pollBusy: false,
    endedLocally: false,
    mode: 'video',
    dismissedCallIds: new Set(),
    answeredCallIds: new Set(),
    disconnectTimer: null,
    visibleAt: 0,
    connectedAt: 0,
    clockTimer: null,
    participantDirectory: new Map(),
    peerConnections: new Map(),
    remoteParticipants: new Map(),
    pendingOffers: new Map(),
    connectedPeerIds: new Set(),
    acceptedGroupCall: false,
    browserSnapshotTimer: null,
    browserSnapshotBusy: false,
    browserSnapshotCanvas: null,
    remoteIsMobileApp: false
  };
  let activeMessageMenuId = 0;
  let activeReactionMessageId = 0;
  let activeReplyMessageId = 0;
  let activeReplyPreviewData = null;

  const GIF_ITEMS = [
    { title:'Happy Wednesday', url:'https://media.giphy.com/media/3o7aD2saalBwwftBIY/giphy.gif', keywords:'happy wednesday dance' },
    { title:'Great Day', url:'https://media.giphy.com/media/l0MYt5jPR6QX5pnqM/giphy.gif', keywords:'great day flowers happy wednesday' },
    { title:'Mimimii', url:'https://media.giphy.com/media/l4FGpP4lxGGgK5CBW/giphy.gif', keywords:'funny cat meme mimimii' },
    { title:'Need It', url:'https://media.giphy.com/media/3o6ZtpxSZbQRRnwCKQ/giphy.gif', keywords:'spongebob need it' },
    { title:'Okay', url:'https://media.giphy.com/media/QTrG6mjkHEkpFR3DqX/giphy.gif', keywords:'okay stitch cute' },
    { title:'Laughing', url:'https://media.giphy.com/media/10tIjpzIu8fe0/giphy.gif', keywords:'laugh reaction funny' },
    { title:'Love You', url:'https://media.giphy.com/media/26FLdmIp6wJr91JAI/giphy.gif', keywords:'love heart cute' },
    { title:'Good Job', url:'https://media.giphy.com/media/111ebonMs90YLu/giphy.gif', keywords:'good job clap success' },
    { title:'Wow', url:'https://media.giphy.com/media/3kzJvEciJa94SMW3hN/giphy.gif', keywords:'wow reaction' },
    { title:'Thank You', url:'https://media.giphy.com/media/3oz8xIsloV7zOmt81G/giphy.gif', keywords:'thank you appreciation' }
  ];

  function hideMenus(){
    if(emojiMenu) emojiMenu.style.display='none';
    if(msgActionMenu){
      msgActionMenu.style.display = 'none';
      msgActionMenu.setAttribute('aria-hidden', 'true');
      msgActionMenu.innerHTML = '';
    }
    if(msgReactionPicker){
      msgReactionPicker.style.display = 'none';
      msgReactionPicker.setAttribute('aria-hidden', 'true');
    }
    activeMessageMenuId = 0;
    activeReactionMessageId = 0;
  }

  function findMessageRow(messageId){
    if(!chatStream || !messageId) return null;
    return chatStream.querySelector('.msgRow[data-id="'+String(messageId)+'"]');
  }

  function updateMessageBubble(messageId, patch){
    const row = findMessageRow(messageId);
    if(!row) return;
    const bubble = row.querySelector('.bubble');
    if(!bubble) return;
    const bubbleStack = row.querySelector('.msg-bubble-stack');
    const isMe = bubble.getAttribute('data-is-me') === '1';
    const msgTextEl = bubble.querySelector('.msgText');
    const editedEl = bubble.querySelector('.msg-edited');
    let replyLine = bubble.querySelector('.msg-reply-line');
    let reactionWrap = bubbleStack ? bubbleStack.querySelector('.msg-reactions') : null;
    const attachmentWraps = Array.from(bubble.children).filter(function(node){
      if(!(node instanceof HTMLElement)) return false;
      if(node.classList.contains('msgText')) return false;
      if(node.classList.contains('msg-edited')) return false;
      if(node.classList.contains('msg-meta')) return false;
      if(node.classList.contains('msg-dot-btn')) return false;
      return true;
    });

    if(patch.deleted_for_all){
      if(msgTextEl){
        msgTextEl.classList.add('is-unsent');
        msgTextEl.innerHTML = nl2brSafe(messageDisplayText({ deleted_for_all: 1 }, isMe));
      } else {
        const div = document.createElement('div');
        div.className = 'msgText is-unsent';
        div.innerHTML = nl2brSafe(messageDisplayText({ deleted_for_all: 1 }, isMe));
        const metaEl = bubble.querySelector('.msg-meta');
        bubble.insertBefore(div, metaEl || null);
      }
      attachmentWraps.forEach(function(node){ node.remove(); });
      if(editedEl) editedEl.remove();
      if(replyLine) replyLine.remove();
      if(reactionWrap) reactionWrap.remove();
      return;
    }

    if(Object.prototype.hasOwnProperty.call(patch, 'text')){
      const text = String(patch.text || '').trim();
      if(msgTextEl){
        msgTextEl.classList.remove('is-unsent');
        msgTextEl.innerHTML = nl2brSafe(text);
      } else if(text !== '') {
        const div = document.createElement('div');
        div.className = 'msgText';
        div.innerHTML = nl2brSafe(text);
        const metaEl = bubble.querySelector('.msg-meta');
        bubble.insertBefore(div, metaEl || null);
      }
    }

    if(patch.edited){
      if(!editedEl){
        const div = document.createElement('div');
        div.className = 'msg-edited';
        div.textContent = 'Edited';
        const metaEl = bubble.querySelector('.msg-meta');
        bubble.insertBefore(div, metaEl || null);
      }
    }

    if(Object.prototype.hasOwnProperty.call(patch, 'reaction') || Object.prototype.hasOwnProperty.call(patch, 'reactions')){
      const reactions = Object.prototype.hasOwnProperty.call(patch, 'reactions')
        ? normalizeReactions(patch.reactions)
        : normalizeReactions([patch.reaction]);
      if(!reactions.length){
        if(reactionWrap) reactionWrap.remove();
      } else {
        if(!reactionWrap){
          reactionWrap = document.createElement('div');
          reactionWrap.className = 'msg-reactions';
          if(bubble && bubble.parentNode){
            bubble.insertAdjacentElement('afterend', reactionWrap);
          } else if(bubbleStack){
            bubbleStack.appendChild(reactionWrap);
          } else {
            row.appendChild(reactionWrap);
          }
        } else if(bubble && reactionWrap.previousElementSibling !== bubble && bubble.parentNode){
          bubble.insertAdjacentElement('afterend', reactionWrap);
        }
        reactionWrap.innerHTML = reactions.map(function(emoji){
          return '<span class="msg-reaction-pill">'+esc(emoji)+'</span>';
        }).join('');
      }
    }
  }

  function removeMessageRow(messageId){
    const row = findMessageRow(messageId);
    if(!row) return;
    const day = String(row.getAttribute('data-day') || '');
    row.remove();
    if(!chatStream || !day) return;
    pruneDanglingDayDividers();
  }

  function pruneDanglingDayDividers(){
    if(!chatStream) return;
    const children = Array.from(chatStream.children);
    let activeDay = '';
    children.forEach(function(node){
      if(!(node instanceof HTMLElement)) return;
      if(node.classList.contains('msgRow')){
        activeDay = String(node.getAttribute('data-day') || '');
        return;
      }
      if(!node.classList.contains('dayDivider')) return;
      const day = String(node.getAttribute('data-day') || '');
      let nextMessageDay = '';
      let next = node.nextElementSibling;
      while(next){
        if(next instanceof HTMLElement && next.classList.contains('msgRow')){
          nextMessageDay = String(next.getAttribute('data-day') || '');
          break;
        }
        if(next instanceof HTMLElement && next.classList.contains('dayDivider')){
          break;
        }
        next = next.nextElementSibling;
      }
      if(!day || nextMessageDay !== day || activeDay === day){
        node.remove();
        return;
      }
      activeDay = day;
    });

    let trailing = chatStream.lastElementChild;
    while(trailing && trailing.classList && trailing.classList.contains('dayDivider')){
      const prev = trailing.previousElementSibling;
      trailing.remove();
      trailing = prev;
    }
  }

  async function performMessageAction(action, payload){
    const fd = new FormData();
    fd.append('action', String(action || ''));
    if(payload && payload.messageId){
      fd.append('message_id', String(payload.messageId));
    }
    if(payload && payload.peer){
      fd.append('peer', String(payload.peer));
    }
    if(payload && Object.prototype.hasOwnProperty.call(payload, 'text')){
      fd.append('text', String(payload.text || ''));
    }
    const res = await fetch('ajax/user_chat_message_action.php', { method:'POST', body: fd });
    return res.json();
  }

  async function performGroupMessageAction(action, payload){
    const fd = new FormData();
    fd.append('action', String(action || ''));
    fd.append('group_id', String(selectedGroupId || 0));
    if(payload && payload.messageId){
      fd.append('message_id', String(payload.messageId));
    }
    if(payload && Object.prototype.hasOwnProperty.call(payload, 'text')){
      fd.append('text', String(payload.text || ''));
    }
    const res = await fetch('ajax/group_chat_message_action.php', { method:'POST', body: fd });
    return res.json();
  }

  function closeMessageActionMenu(){
    if(msgActionMenu){
      msgActionMenu.style.display = 'none';
      msgActionMenu.setAttribute('aria-hidden', 'true');
      msgActionMenu.innerHTML = '';
    }
    activeMessageMenuId = 0;
  }

  function submitGroupMessageAction(action, messageId, text){
    if(!groupMessageActionForm) return;
    const actionInput = groupMessageActionForm.querySelector('input[name="group_action"]');
    const messageIdInput = groupMessageActionForm.querySelector('input[name="group_message_id"]');
    const textInput = groupMessageActionForm.querySelector('input[name="group_message_text"]');
    if(actionInput) actionInput.value = String(action || '');
    if(messageIdInput) messageIdInput.value = String(messageId || '');
    if(textInput) textInput.value = typeof text === 'string' ? text : '';
    groupMessageActionForm.submit();
  }

  function closeReplyPreview(){
    activeReplyMessageId = 0;
    activeReplyPreviewData = null;
    if(replyMessageIdInput) replyMessageIdInput.value = '';
    if(replyPreviewAuthorInput) replyPreviewAuthorInput.value = '';
    if(replyPreviewTextInput) replyPreviewTextInput.value = '';
    if(replyPreviewText) replyPreviewText.textContent = '';
    if(replyPreviewTitle) replyPreviewTitle.textContent = 'Replying';
    if(replyPreview){
      replyPreview.classList.remove('is-open');
      replyPreview.setAttribute('aria-hidden', 'true');
    }
  }

  function openMessageReactionPicker(btn){
    if(!btn || !msgReactionPicker) return;
    const messageId = Number(btn.getAttribute('data-message-id') || 0);
    if(activeReactionMessageId === messageId && msgReactionPicker.style.display === 'flex'){
      msgReactionPicker.style.display = 'none';
      msgReactionPicker.setAttribute('aria-hidden', 'true');
      activeReactionMessageId = 0;
      return;
    }
    hideMenus();
    activeReactionMessageId = messageId;
    msgReactionPicker.setAttribute('data-message-id', String(messageId));
    positionMenu(btn, msgReactionPicker);
    msgReactionPicker.style.display = 'flex';
    msgReactionPicker.setAttribute('aria-hidden', 'false');
  }

  function handleReplyAction(messageId){
    const row = findMessageRow(messageId);
    const textEl = row ? row.querySelector('.msgText') : null;
    const snippet = textEl ? String(textEl.textContent || '').trim().replace(/\s+/g, ' ') : '';
    const bubble = row ? row.querySelector('.bubble') : null;
    const isMe = bubble ? bubble.getAttribute('data-is-me') === '1' : false;
    const rowAuthor = row ? String(row.getAttribute('data-author') || '').trim() : '';
    activeReplyMessageId = Number(messageId || 0);
    activeReplyPreviewData = {
      author: isMe ? 'yourself' : (rowAuthor || peerDisplay || 'message'),
      text: snippet || 'Attachment'
    };
    if(replyMessageIdInput) replyMessageIdInput.value = String(activeReplyMessageId || '');
    if(replyPreviewAuthorInput) replyPreviewAuthorInput.value = String(activeReplyPreviewData.author || '');
    if(replyPreviewTextInput) replyPreviewTextInput.value = String(activeReplyPreviewData.text || '');
    if(replyPreviewTitle) replyPreviewTitle.textContent = 'Replying to ' + activeReplyPreviewData.author;
    if(replyPreviewText) replyPreviewText.textContent = activeReplyPreviewData.text;
    if(replyPreview){
      replyPreview.classList.add('is-open');
      replyPreview.setAttribute('aria-hidden', 'false');
    }
    if(msgText){
      msgText.focus();
      autosizeMessageInput();
    }
  }

  function handleReactAction(messageId, btn){
    if(!messageId || !btn) return;
    openMessageReactionPicker(btn);
  }

  async function applyMessageReaction(messageId, emoji){
    if(!messageId) return;
    try{
      const data = isGroupChatView
        ? await performGroupMessageAction('react', { messageId: messageId, text: emoji })
        : await performMessageAction('react', { messageId: messageId, text: emoji });
      if(data && data.ok){
        updateMessageBubble(messageId, { reactions: Array.isArray(data.reactions) ? data.reactions : [] });
      } else {
        alert((data && data.error) ? data.error : 'Could not update reaction');
      }
    }catch(_err){
      alert('Could not update reaction');
    }finally{
      if(msgReactionPicker){
        msgReactionPicker.style.display = 'none';
        msgReactionPicker.setAttribute('aria-hidden', 'true');
      }
      activeReactionMessageId = 0;
    }
  }

  function jumpToReplyTarget(targetId, fallbackText){
    const id = Number(targetId || 0);
    if(!chatBox || !chatStream) return;
    let row = null;
    if(id){
      row = findMessageRow(id);
    }
    if(!row){
      const needle = String(fallbackText || '').trim().replace(/\s+/g, ' ');
      if(needle){
        row = Array.from(chatStream.querySelectorAll('.msgRow')).find(function(candidate){
          const textEl = candidate.querySelector('.msgText');
          const txt = textEl ? String(textEl.textContent || '').trim().replace(/\s+/g, ' ') : '';
          return txt !== '' && (txt === needle || txt.indexOf(needle) !== -1 || needle.indexOf(txt) !== -1);
        }) || null;
      }
    }
    if(!row) return;
    row.scrollIntoView({ behavior:'smooth', block:'center' });
    row.classList.add('reply-target-flash');
    setTimeout(function(){ row.classList.remove('reply-target-flash'); }, 1800);
  }

  function buildMessageMenuItems(messageId, isMine, peer){
    const items = [];
    if(isMine){
      items.push({ action:'edit', label:'Edit' });
      items.push({ action:'unsend_everyone', label:'Unsend for everyone', danger:true });
    } else {
      items.push({ action:'report', label:'Report', danger:true, peer:peer });
    }
    items.push({ action:'unsend_me', label:'Unsend for me', danger:!isMine });
    return items.map(function(item){
      return '<button type="button" class="'+(item.danger ? 'danger' : '')+'" data-action="'+esc(item.action)+'" data-message-id="'+esc(String(messageId || ''))+'" data-peer="'+esc(String(item.peer || peer || ''))+'">'+esc(item.label)+'</button>';
    }).join('');
  }

  function buildGroupMessageMenuItems(messageId, isMine){
    const items = [];
    if(isMine){
      items.push({ action:'edit_group_message', label:'Edit' });
      items.push({ action:'unsend_group_message_everyone', label:'Unsend for everyone', danger:true });
    }
    items.push({ action:'unsend_group_message_me', label:'Unsend for me', danger:!isMine });
    return items.map(function(item){
      return '<button type="button" class="'+(item.danger ? 'danger' : '')+'" data-context="group" data-action="'+esc(item.action)+'" data-message-id="'+esc(String(messageId || ''))+'">'+esc(item.label)+'</button>';
    }).join('');
  }

  function openMessageActionMenu(btn){
    if(!btn || !msgActionMenu) return;
    const messageId = Number(btn.getAttribute('data-message-id') || 0);
    const isMine = btn.getAttribute('data-is-me') === '1';
    const peer = btn.getAttribute('data-peer') || peerCode || '';

    if(activeMessageMenuId === messageId && msgActionMenu.style.display === 'block'){
      closeMessageActionMenu();
      return;
    }

    activeMessageMenuId = messageId;
    msgActionMenu.innerHTML = buildMessageMenuItems(messageId, isMine, peer);
    msgActionMenu.setAttribute('aria-hidden', 'false');
    positionMenu(btn, msgActionMenu);
    msgActionMenu.style.display = 'block';
  }

  function openGroupMessageActionMenu(btn){
    if(!btn || !msgActionMenu) return;
    const messageId = Number(btn.getAttribute('data-message-id') || 0);
    const isMine = btn.getAttribute('data-is-me') === '1';

    if(activeMessageMenuId === messageId && msgActionMenu.style.display === 'block'){
      closeMessageActionMenu();
      return;
    }

    activeMessageMenuId = messageId;
    msgActionMenu.innerHTML = buildGroupMessageMenuItems(messageId, isMine);
    msgActionMenu.setAttribute('aria-hidden', 'false');
    positionMenu(btn, msgActionMenu);
    msgActionMenu.style.display = 'block';
  }

  async function handleMessageMenuAction(btn){
    const action = String(btn.getAttribute('data-action') || '');
    const messageId = Number(btn.getAttribute('data-message-id') || 0);
    const peer = String(btn.getAttribute('data-peer') || peerCode || '');

    if(
      (isGroupChatView && (action === 'edit' || action === 'unsend_me' || action === 'unsend_everyone')) ||
      action.indexOf('edit_group_') === 0 ||
      action.indexOf('unsend_group_') === 0
    ){
      handleGroupMessageMenuAction(btn);
      return;
    }

    closeMessageActionMenu();

    if(!action) return;

    if(action === 'edit'){
      const row = findMessageRow(messageId);
      const textEl = row ? row.querySelector('.msgText') : null;
      const currentText = textEl ? String(textEl.textContent || '').trim() : '';
      const nextText = window.prompt('Edit message', currentText);
      if(nextText === null) return;
      if(String(nextText).trim() === ''){
        alert('Message text cannot be empty');
        return;
      }
      try{
        const data = await performMessageAction('edit', { messageId: messageId, text: nextText });
        if(data && data.ok){
          updateMessageBubble(messageId, { text: nextText, edited: true });
        } else {
          alert((data && data.error) ? data.error : 'Edit failed');
        }
      }catch(_err){
        alert('Edit failed');
      }
      return;
    }

    if(action === 'unsend_me'){
      try{
        const data = await performMessageAction('unsend_me', { messageId: messageId });
        if(data && data.ok){
          removeMessageRow(messageId);
        } else {
          alert((data && data.error) ? data.error : 'Could not remove message');
        }
      }catch(_err){
        alert('Could not remove message');
      }
      return;
    }

    if(action === 'unsend_everyone'){
      if(!window.confirm('Unsend this message for everyone?')) return;
      try{
        const data = await performMessageAction('unsend_everyone', { messageId: messageId });
        if(data && data.ok){
          updateMessageBubble(messageId, { deleted_for_all: true });
        } else {
          alert((data && data.error) ? data.error : 'Could not unsend message');
        }
      }catch(_err){
        alert('Could not unsend message');
      }
      return;
    }

    if(action === 'block'){
      if(!peer){
        alert('Missing user to block');
        return;
      }
      if(!window.confirm('Block this user?')) return;
      try{
        const data = await performMessageAction('block', { peer: peer });
        if(data && data.ok){
          alert('User blocked');
          if(msgText) msgText.disabled = true;
          if(form){
            const sendBtn = form.querySelector('[type="submit"]');
            if(sendBtn) sendBtn.disabled = true;
          }
        } else {
          alert((data && data.error) ? data.error : 'Could not block user');
        }
      }catch(_err){
        alert('Could not block user');
      }
      return;
    }

    if(action === 'report'){
      alert('Report feature coming soon');
    }
  }

  function handleGroupMessageMenuAction(btn){
    const rawAction = String(btn.getAttribute('data-action') || '');
    const messageId = Number(btn.getAttribute('data-message-id') || 0);
    closeMessageActionMenu();

    if(!rawAction || !messageId) return;

    let action = rawAction;
    if(action === 'edit'){
      action = 'edit_group_message';
    } else if(action === 'unsend_me'){
      action = 'unsend_group_message_me';
    } else if(action === 'unsend_everyone'){
      action = 'unsend_group_message_everyone';
    }

    if(action === 'edit_group_message'){
      const row = findMessageRow(messageId);
      const textEl = row ? row.querySelector('.msgText') : null;
      const currentText = textEl ? String(textEl.textContent || '').trim() : '';
      const nextText = window.prompt('Edit message', currentText);
      if(nextText === null) return;
      if(String(nextText).trim() === ''){
        alert('Message text cannot be empty');
        return;
      }
      submitGroupMessageAction(action, messageId, String(nextText));
      return;
    }

    if(action === 'unsend_group_message_everyone'){
      if(!window.confirm('Unsend this message for everyone?')) return;
      submitGroupMessageAction(action, messageId, '');
      return;
    }

    if(action === 'unsend_group_message_me'){
      submitGroupMessageAction(action, messageId, '');
    }
  }

  function openGifPicker(){
    if(!gifPickerOverlay) return;
    gifPickerOverlay.style.display = 'flex';
    gifPickerOverlay.setAttribute('aria-hidden', 'false');
    if(gifSearchInput){
      gifSearchInput.value = '';
      setTimeout(function(){ try{ gifSearchInput.focus(); }catch(_e){} }, 20);
    }
    renderGifGrid('');
  }

  function closeGifPicker(){
    if(!gifPickerOverlay) return;
    gifPickerOverlay.style.display = 'none';
    gifPickerOverlay.setAttribute('aria-hidden', 'true');
  }

  function renderGifGrid(query){
    if(!gifGrid) return;
    const q = String(query || '').trim().toLowerCase();
    const items = GIF_ITEMS.filter(function(item){
      const hay = (item.title + ' ' + item.keywords).toLowerCase();
      return q === '' || hay.indexOf(q) !== -1;
    });
    gifGrid.innerHTML = items.map(function(item){
      return '<button type="button" class="gif-card" data-gif-url="'+esc(item.url)+'" data-gif-title="'+esc(item.title)+'">' +
        '<img src="'+esc(item.url)+'" alt="'+esc(item.title)+'">' +
        '<span>'+esc(item.title)+'</span>' +
      '</button>';
    }).join('');
    if(gifEmpty) gifEmpty.style.display = items.length ? 'none' : '';
  }

  async function sendGifItem(url, title){
    if(!peerCode) return;
    try{
      const data = await sendDirectMessage('', { gifUrl: url, gifTitle: title });
      if(data && data.ok){
        closeGifPicker();
        scrollToBottom();
      } else {
        alert((data && data.error) ? data.error : 'GIF send failed');
      }
    } catch(_err){
      alert('GIF send failed');
    }
  }

  function positionMenu(btn, menu){
    if(!btn || !menu) return;
    // show temporarily to measure
    menu.style.display = 'block';
    menu.style.visibility = 'hidden';

    requestAnimationFrame(() => {
      const r = btn.getBoundingClientRect();
      const mw = menu.offsetWidth || 320;
      const mh = menu.offsetHeight || 200;

      let left = r.left;
      let top  = r.top - mh - 10;

      // keep in screen
      left = Math.min(window.innerWidth - mw - 10, Math.max(10, left));
      top  = Math.max(10, top);

      menu.style.left = left + 'px';
      menu.style.top  = top + 'px';
      menu.style.visibility = 'visible';
    });
  }

  function toggleEmoji(){
    if(!emojiMenu || !emojiBtn) return;
    const open = emojiMenu.style.display !== 'block';
    hideMenus();
    if(open){
      positionMenu(emojiBtn, emojiMenu);
      emojiMenu.style.display = 'block';
    }
  }

  if(plusBtn) plusBtn.addEventListener('click', (e)=>{
    e.preventDefault();
    e.stopPropagation();
    hideMenus();
    if(fileAny) fileAny.click();
  });
  if(gifBtn) gifBtn.addEventListener('click', (e)=>{
    e.preventDefault();
    e.stopPropagation();
    hideMenus();
    openGifPicker();
  });
  if(emojiBtn) emojiBtn.addEventListener('click', (e)=>{ e.preventDefault(); e.stopPropagation(); toggleEmoji(); });

  if(emojiClose) emojiClose.addEventListener('click', (e)=>{ e.preventDefault(); e.stopPropagation(); if(emojiMenu) emojiMenu.style.display='none'; });

  document.addEventListener('click', (e)=>{
    const groupDotBtn = e.target.closest('.group-msg-dot-btn');
    if(groupDotBtn){
      e.preventDefault();
      e.stopPropagation();
      openGroupMessageActionMenu(groupDotBtn);
      return;
    }
    const dotBtn = e.target.closest('.msg-dot-btn');
    if(dotBtn){
      e.preventDefault();
      e.stopPropagation();
      openMessageActionMenu(dotBtn);
      return;
    }
    const replyBtn = e.target.closest('.msg-reply-btn');
    if(replyBtn){
      e.preventDefault();
      e.stopPropagation();
      hideMenus();
      handleReplyAction(Number(replyBtn.getAttribute('data-message-id') || 0));
      return;
    }
    const reactBtn = e.target.closest('.msg-react-btn');
    if(reactBtn){
      e.preventDefault();
      e.stopPropagation();
      handleReactAction(Number(reactBtn.getAttribute('data-message-id') || 0), reactBtn);
      return;
    }
    const reactPickBtn = e.target.closest('#msgReactionPicker button');
    if(reactPickBtn){
      e.preventDefault();
      e.stopPropagation();
      applyMessageReaction(
        Number((msgReactionPicker && msgReactionPicker.getAttribute('data-message-id')) || 0),
        reactPickBtn.getAttribute('data-emoji') || ''
      );
      return;
    }
    const replyLine = e.target.closest('.msg-reply-line');
    if(replyLine){
      e.preventDefault();
      e.stopPropagation();
      const replyTextEl = replyLine.querySelector('.msg-reply-line-text');
      jumpToReplyTarget(
        Number(replyLine.getAttribute('data-reply-target-id') || 0),
        replyTextEl ? String(replyTextEl.textContent || '') : ''
      );
      return;
    }
    const menuBtn = e.target.closest('#msgActionMenu button');
    if(menuBtn){
      e.preventDefault();
      e.stopPropagation();
      const menuAction = String(menuBtn.getAttribute('data-action') || '');
      if(
        menuBtn.getAttribute('data-context') === 'group' ||
        menuAction.indexOf('edit_group_') === 0 ||
        menuAction.indexOf('unsend_group_') === 0 ||
        isGroupChatView
      ){
        handleGroupMessageMenuAction(menuBtn);
        return;
      }
      handleMessageMenuAction(menuBtn);
      return;
    }
    const insideEmoji = emojiMenu && emojiMenu.contains(e.target);
    const clickedEmojiBtn = emojiBtn && emojiBtn.contains(e.target);
    if(!insideEmoji && !clickedEmojiBtn){
      hideMenus();
    }
    if(gifPickerOverlay && e.target === gifPickerOverlay){
      closeGifPicker();
    }
  });

  pruneDanglingDayDividers();

  if(gifSearchInput) gifSearchInput.addEventListener('input', function(){
    renderGifGrid(gifSearchInput.value || '');
  });
  if(gifGrid) gifGrid.addEventListener('click', function(e){
    const card = e.target.closest('.gif-card');
    if(!card) return;
    e.preventDefault();
    sendGifItem(card.getAttribute('data-gif-url') || '', card.getAttribute('data-gif-title') || 'GIF');
  });

  window.addEventListener('resize', hideMenus);

  // Emoji grid
  const EMOJIS = "😀 😃 😄 😁 😆 😅 😂 🤣 😊 😇 🙂 🙃 😉 😌 😍 🥰 😘 😗 😙 😚 😋 😛 😝 😜 🤪 🤨 🧐 🤓 😎 🥳 😏 😒 😞 😔 😟 😕 🙁 ☹️ 😣 😖 😫 😩 🥺 😢 😭 😤 😠 😡 🤬 🤯 😳 🥵 🥶 😱 😨 😰 😥 😓 🤗 🤔 🫣 🤭 🤫 🤥 😶 😶‍🌫️ 😐 😑 😬 🙄 😯 😦 😧 😮 😲 🥱 😴 🤤 😪 😵 😵‍💫 🤐 🥴 🤢 🤮 🤧 😷 🤒 🤕 🤑 🤠 😈 👿 👻 💀 ☠️ 👽 🤖 🎃 ❤️ 🧡 💛 💚 💙 💜 🤎 🖤 🤍 💔 👍 👎 👏 🙌 🤝 🙏".split(" ");
  if(emojiGrid){
    emojiGrid.innerHTML = EMOJIS.map(e=>`<button type="button">${e}</button>`).join('');
    emojiGrid.addEventListener('click', (ev)=>{
      const b = ev.target.closest('button');
      if(!b || !msgText) return;
      msgText.value = (msgText.value || '') + b.textContent;
      msgText.focus();
    });
  }

  // Attach
  function clearPreview(){
    if(previewWrap) previewWrap.style.display='none';
    if(previewThumb) { previewThumb.src=''; previewThumb.style.display=''; }
    if(previewName) previewName.textContent='';
    if(fileAny) fileAny.value='';
  }
  if(previewRemove) previewRemove.addEventListener('click', (e)=>{ e.preventDefault(); clearPreview(); });
  if(replyPreviewClose) replyPreviewClose.addEventListener('click', (e)=>{ e.preventDefault(); closeReplyPreview(); });

  function showPreview(file){
    if(!file || !previewWrap) return;
    previewWrap.style.display='flex';
    if(previewName) previewName.textContent = file.name;

    if(previewThumb){
      if(file.type && file.type.startsWith('image/')){
        previewThumb.src = URL.createObjectURL(file);
        previewThumb.style.display = '';
      } else {
        previewThumb.style.display = 'none';
      }
    }
  }

  if(fileAny){
    fileAny.addEventListener('change', ()=>{
      const f = fileAny.files && fileAny.files[0] ? fileAny.files[0] : null;
      if(!f) return;
      showPreview(f);
    });
  }

  // autosize textarea
  if(msgText){
    autosizeMessageInput = ()=>{
      // msgText.style.height='auto';
      // msgText.style.height = Math.min(120, msgText.scrollHeight) + 'px';
    };
    msgText.addEventListener('input', autosizeMessageInput);
    msgText.addEventListener('keydown', (e)=>{
      if(e.key === 'Enter' && !e.shiftKey){
        e.preventDefault();
        if(isGroupChatView && groupForm){
          if(!groupSendBusy) groupForm.requestSubmit();
        } else if(form){
          if(!directSendBusy) form.requestSubmit();
        }
      }
    });
    setTimeout(autosizeMessageInput, 50);
  }

  if(btnVoiceCall){
    btnVoiceCall.addEventListener('click', (e)=>{
      e.preventDefault();
      startVideoCall('voice');
    });
  }
  if(btnVideoCall){
    btnVideoCall.addEventListener('click', (e)=>{
      e.preventDefault();
      startVideoCall('video');
    });
  }
  if(vcallAcceptBtn){
    vcallAcceptBtn.addEventListener('click', (e)=>{
      e.preventDefault();
      acceptIncomingCall();
    });
  }
  if(vcallDeclineBtn){
    vcallDeclineBtn.addEventListener('click', (e)=>{
      e.preventDefault();
      endCurrentCall('decline');
    });
  }
  if(vcallJoinRequests){
    vcallJoinRequests.addEventListener('click', (e)=>{
      const btn = e.target.closest('button[data-action]');
      if(!btn) return;
      e.preventDefault();
      const row = btn.closest('.vcall-join-request');
      const uid = row ? Number(row.getAttribute('data-user-id') || 0) : 0;
      respondGroupJoinRequest(uid, String(btn.getAttribute('data-action') || ''));
    });
  }
  if(vcallCloseBtn){
    vcallCloseBtn.addEventListener('click', (e)=>{
      e.preventDefault();
      if(callState.scope === 'group'){
        refreshGroupRoomCount();
        return;
      }
      endCurrentCall('end');
    });
  }
  if(vcallEndBtn){
    vcallEndBtn.addEventListener('click', (e)=>{
      e.preventDefault();
      endCurrentCall('end');
    });
  }
  if(vcallMuteBtn){
    vcallMuteBtn.addEventListener('click', ()=>{
      const track = callState.localStream && callState.localStream.getAudioTracks ? callState.localStream.getAudioTracks()[0] : null;
      if(!track) return;
      track.enabled = !track.enabled;
      syncCallButtons();
    });
  }
  if(vcallCameraBtn){
    vcallCameraBtn.addEventListener('click', ()=>{
      const track = callState.localStream && callState.localStream.getVideoTracks ? callState.localStream.getVideoTracks()[0] : null;
      if(!track) return;
      track.enabled = !track.enabled;
      syncCallButtons();
    });
  }
  if(vcallSidebarCloseBtn){
    vcallSidebarCloseBtn.addEventListener('click', (e)=>{
      e.preventDefault();
      setVideoCallSidebarOpen(false);
    });
  }
  if(vcallMessagesBtn){
    vcallMessagesBtn.addEventListener('click', (e)=>{
      e.preventDefault();
      const isCollapsed = !!(vcallBody && vcallBody.classList.contains('is-sidebar-collapsed'));
      setVideoCallSidebarOpen(isCollapsed);
    });
  }

  // ===== Media viewer =====
  const modal = document.getElementById('mediaModal');
  const body  = document.getElementById('mediaBody');
  const title = document.getElementById('mediaTitle');
  const meta  = document.getElementById('mediaMeta');
  const openA = document.getElementById('mediaOpenNewTab');
  const closeBtn = document.getElementById('mediaClose');
  const backdrop = document.getElementById('mediaBackdrop');

  function openMedia(url, type, orig){
    if(!modal || !body) return;
    const u = String(url || '');
    const t = String(type || '').toLowerCase();
    const o = String(orig || 'Attachment');

    if(title) title.textContent = o;
    if(meta) meta.textContent = t || '';
    if(openA) openA.href = u;

    body.innerHTML = '';

    const isImg = t.startsWith('image/') || /\.(png|jpe?g|gif|webp)$/i.test(u);
    const isVid = t.startsWith('video/') || /\.(mp4|webm|ogg|mov)$/i.test(u);
    const isPdf = t === 'application/pdf' || /\.pdf$/i.test(u);

    if(isImg){
      body.innerHTML = `<img src="${esc(u)}" alt="${esc(o)}" style="max-width:100%; max-height:100%; border-radius:14px;">`;
    } else if(isVid){
      body.innerHTML = `
        <video controls autoplay style="max-width:100%; max-height:100%; border-radius:14px; background:#000;">
          <source src="${esc(u)}" type="${esc(t || 'video/mp4')}">
        </video>
      `;
    } else if(isPdf){
      body.innerHTML = `<iframe src="${esc(u)}" style="width:100%; height:100%; border:0; border-radius:14px; background:#fff;"></iframe>`;
    } else {
      body.innerHTML = `
        <div style="color:#fff; text-align:center; padding:20px;">
          <div style="font-size:18px; font-weight:900; margin-bottom:8px;">${esc(o)}</div>
          <div style="opacity:.8; margin-bottom:14px;">${esc(t || 'file')}</div>
          <a href="${esc(u)}" target="_blank" rel="noopener" class="btn btn-light btn-sm">Open</a>
        </div>
      `;
    }

    modal.style.display='block';
    modal.setAttribute('aria-hidden','false');
  }

  function closeMedia(){
    if(!modal || !body) return;
    modal.style.display='none';
    modal.setAttribute('aria-hidden','true');
    body.innerHTML='';
  }

  document.addEventListener('click', (e)=>{
    const el = e.target.closest('.mediaOpen');
    if(!el) return;
    e.preventDefault();
    const url = el.getAttribute('data-url') || '';
    const type = el.getAttribute('data-type') || '';
    const orig = el.getAttribute('data-orig') || 'Attachment';
    openMedia(url, type, orig);
  });

  if(closeBtn) closeBtn.addEventListener('click', closeMedia);
  if(backdrop) backdrop.addEventListener('click', closeMedia);
  document.addEventListener('keydown', (e)=>{ if(e.key === 'Escape') closeMedia(); });

  // ===== Sidebar search (quick) =====
  const chatSearch = document.getElementById('chatSearch');
  const noChatsMatch = document.getElementById('noChatsMatch');

  function items(){ return Array.from(document.querySelectorAll('a.chatItem')); }

  function applySearch(){
    const q = (chatSearch ? (chatSearch.value || '') : '').trim().toLowerCase();
    let visible = 0;
    for(const a of items()){
      const name = (a.getAttribute('data-name') || '');
      const code = (a.getAttribute('data-code') || '');
      const last = (a.getAttribute('data-lastmsg') || '');
      const ok = (q === '' || name.includes(q) || code.includes(q) || last.includes(q));
      a.style.display = ok ? '' : 'none';
      if(ok) visible++;
    }
    if(noChatsMatch) noChatsMatch.style.display = (q !== '' && visible === 0) ? '' : 'none';
  }

  if(chatSearch) chatSearch.addEventListener('input', applySearch);
  applySearch();

  // ===== Send (AJAX to your existing endpoint) =====
  const form = document.getElementById('sendForm');
  const groupForm = document.querySelector('.group-send-form');
  let directSendBusy = false;
  let groupSendBusy = false;
  if(form){
    form.addEventListener('submit', async (e)=>{
      if(!peerCode || !msgText) return;
      e.preventDefault();
      if(directSendBusy) return;

      const msg = (msgText.value || '').trim();
      const hasFile = !!(fileAny && fileAny.files && fileAny.files.length > 0);
      if(!msg && !hasFile) return;
      const attachmentFile = hasFile ? fileAny.files[0] : null;

      const btn = form.querySelector('button[type="submit"]');
      directSendBusy = true;
      if(btn) btn.disabled = true;

      try{
        msgText.value = '';
        if(fileAny) fileAny.value = '';
        clearPreview();
        closeReplyPreview();
        autosizeMessageInput();
        scrollToBottom();

        const data = await sendDirectMessage(msg, { attachmentFile: attachmentFile });

        if(data && data.ok){
          scrollToBottom();
        } else {
          alert((data && data.error) ? data.error : 'Send failed');
        }
      } catch(err){
        alert('Send failed (network/server).');
      } finally {
        directSendBusy = false;
        if(btn) btn.disabled = false;
      }
    });
  }
  if(groupForm){
    groupForm.addEventListener('submit', async (e)=>{
      if(!selectedGroupId || !msgText) return;
      e.preventDefault();
      if(groupSendBusy) return;

      const msg = (msgText.value || '').trim();
      const hasFile = !!(fileAny && fileAny.files && fileAny.files.length > 0);
      if(!msg && !hasFile) return;
      const attachmentFile = hasFile ? fileAny.files[0] : null;

      const btn = groupForm.querySelector('button[type="submit"]');
      groupSendBusy = true;
      if(btn) btn.disabled = true;

      try{
        msgText.value = '';
        if(fileAny) fileAny.value = '';
        clearPreview();
        closeReplyPreview();
        autosizeMessageInput();
        scrollToBottom();

        const data = await sendGroupVideoCallMessage(msg, { attachmentFile: attachmentFile });
        if(data && data.ok){
          scrollToBottom();
        } else {
          alert((data && data.error) ? data.error : 'Send failed');
        }
      } catch(_err){
        alert('Send failed (network/server).');
      } finally {
        groupSendBusy = false;
        if(btn) btn.disabled = false;
      }
    });
  }

  async function handleVideoCallSidebarSend(){
    if(!vcallComposeInput || !vcallComposeSend) return;
    const msg = String(vcallComposeInput.value || '').trim();
    if(!msg) return;
    vcallComposeSend.disabled = true;
    try{
      const data = (callState.scope === 'group')
        ? await sendGroupVideoCallMessage(msg)
        : await sendDirectMessage(msg);
      if(data && data.ok){
        vcallComposeInput.value = '';
        vcallComposeInput.style.height = '38px';
        if(callState.scope !== 'group'){
          scrollToBottom();
        }
        scrollVideoCallChatToBottom();
      } else {
        alert((data && data.error) ? data.error : 'Send failed');
      }
    }catch(_err){
      alert('Send failed (network/server).');
    }finally{
      vcallComposeSend.disabled = false;
    }
  }

  function autosizeVideoCallCompose(){
    if(!vcallComposeInput) return;
    vcallComposeInput.style.height = '38px';
    vcallComposeInput.style.height = Math.min(110, vcallComposeInput.scrollHeight) + 'px';
  }

  if(vcallComposeSend){
    vcallComposeSend.addEventListener('click', (e)=>{
      e.preventDefault();
      handleVideoCallSidebarSend();
    });
  }
  if(vcallComposeInput){
    autosizeVideoCallCompose();
    vcallComposeInput.addEventListener('input', autosizeVideoCallCompose);
    vcallComposeInput.addEventListener('keydown', (e)=>{
      if(e.key === 'Enter' && !e.shiftKey){
        e.preventDefault();
        handleVideoCallSidebarSend();
      }
    });
  }

  let pollBusy = false;
  const CHAT_POLL_WAIT_SECONDS = 0;
  const CHAT_POLL_INTERVAL_MS = 250;
  async function pollMessages(){
    if(!peerCode || !chatStream || pollBusy) return;
    pollBusy = true;
    try{
      const url = 'ajax/user_chat_poll.php?peer=' + encodeURIComponent(peerCode) + '&after=' + encodeURIComponent(String(lastId || 0)) + '&wait=' + encodeURIComponent(String(CHAT_POLL_WAIT_SECONDS)) + '&mark=1';
      const res = await fetch(url, { cache:'no-store' });
      const data = await res.json();
      if(data && data.ok){
        const nearBottom = isNearBottom(chatBox);
        const items = Array.isArray(data.items) ? data.items : [];
        items.forEach(appendMessageItem);
        if(Number(data.last_id || 0) > lastId) lastId = Number(data.last_id || 0);
        if(items.length && nearBottom) setTimeout(scrollToBottom, 40);
      }
    }catch(_err){
    }finally{
      pollBusy = false;
      setTimeout(pollMessages, CHAT_POLL_INTERVAL_MS);
    }
  }
  let groupVideoChatPollBusy = false;
  async function pollGroupVideoChatMessages(){
    if(!selectedGroupId || groupVideoChatPollBusy) return;
    groupVideoChatPollBusy = true;
    try{
      const url = 'ajax/group_chat_poll.php?group_id=' + encodeURIComponent(String(selectedGroupId))
        + '&after=' + encodeURIComponent(String(lastGroupVideoChatId || 0))
        + '&wait=' + encodeURIComponent(String(CHAT_POLL_WAIT_SECONDS));
      const res = await fetch(url, { cache:'no-store' });
      const data = await res.json();
      if(data && data.ok){
        const items = Array.isArray(data.items) ? data.items : [];
        items.forEach(function(item){
          appendGroupChatMessageItem(item);
          appendVideoCallChatItem(item);
        });
        if(Number(data.last_id || 0) > lastGroupVideoChatId){
          lastGroupVideoChatId = Number(data.last_id || 0);
        }
        if(items.length){
          setTimeout(scrollVideoCallChatToBottom, 40);
        }
      }
    }catch(_err){
    }finally{
      groupVideoChatPollBusy = false;
      setTimeout(pollGroupVideoChatMessages, CHAT_POLL_INTERVAL_MS);
    }
  }
  if(peerCode && chatStream) setTimeout(pollMessages, 120);
  if(selectedGroupId) setTimeout(pollGroupVideoChatMessages, 120);
  if(peerCode || isGroupCallContext()) setTimeout(pollVideoCalls, 1200);
})();
</script>


<script>
/* Mobile/Tablet UX: when a thread is selected, auto-scroll to the conversation panel
   ✅ Minimal JS only (does not change PHP logic) */
document.addEventListener('DOMContentLoaded', function () {
  var hasPeer = <?php echo ($peerRow ? 'true' : 'false'); ?>;
  if (!hasPeer) return;
  if (!window.matchMedia('(max-width: 991.98px)').matches) return;

  var panel = document.getElementById('conversationPanel');
  if (!panel) return;

  // Small delay ensures layout is painted (prevents scrolling to wrong offset)
  setTimeout(function () {
    try {
      panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
    } catch (e) {
      panel.scrollIntoView(true);
    }
  }, 80);
});
</script>

<script>
(function(){
  function isDesktop(){
    return window.matchMedia && window.matchMedia('(min-width: 992px)').matches;
  }

  document.addEventListener('DOMContentLoaded', function(){
    var stage = document.getElementById('chatStage');
    var toggle = document.getElementById('peerInfoToggle');
    var closeBtn = document.getElementById('chatInfoClose');
    if(!stage || !toggle) return;

    function syncState(){
      var open = !stage.classList.contains('is-info-collapsed');
      toggle.classList.toggle('is-active', open);
      toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
      toggle.setAttribute('aria-label', open ? 'Close peer details' : 'Open peer details');
    }

    function openPanel(){
      if(!isDesktop()) return;
      stage.classList.remove('is-info-collapsed');
      syncState();
    }

    function closePanel(){
      stage.classList.add('is-info-collapsed');
      syncState();
    }

    toggle.addEventListener('click', function(){
      if(stage.classList.contains('is-info-collapsed')){
        openPanel();
      }else{
        closePanel();
      }
    });

    if(closeBtn){
      closeBtn.addEventListener('click', closePanel);
    }

    window.addEventListener('resize', function(){
      if(!isDesktop()){
        stage.classList.remove('is-info-collapsed');
      }else if(!toggle.classList.contains('is-active')){
        stage.classList.add('is-info-collapsed');
      }
      syncState();
    });

    syncState();
  });
})();
</script>

<script>
(function(){
  document.addEventListener('DOMContentLoaded', function(){
    var openBtn = document.getElementById('openGroupCreateBtn');
    var modal = document.getElementById('groupCreateModal');
    var closeBtn = document.getElementById('closeGroupCreateBtn');
    var chatBox = document.getElementById('chatBox');
    var hasSelectedGroup = <?php echo ($isGroupChatView && $selectedGroup) ? 'true' : 'false'; ?>;

    if(chatBox && hasSelectedGroup){
      chatBox.scrollTop = chatBox.scrollHeight;
    }

    if(!openBtn || !modal) return;

    function openModal(){
      modal.classList.add('is-open');
      modal.setAttribute('aria-hidden', 'false');
      var firstInput = modal.querySelector('input[name="group_name"]');
      if(firstInput) firstInput.focus();
    }

    function closeModal(){
      modal.classList.remove('is-open');
      modal.setAttribute('aria-hidden', 'true');
    }

    openBtn.addEventListener('click', openModal);
    if(closeBtn){
      closeBtn.addEventListener('click', closeModal);
    }
    modal.addEventListener('click', function(event){
      if(event.target === modal){
        closeModal();
      }
    });
    document.addEventListener('keydown', function(event){
      if(event.key === 'Escape' && modal.classList.contains('is-open')){
        closeModal();
      }
    });
  });
})();
</script>


<script>
/* =====================================================
   MOBILE/TABLET "MESSENGER" MODE (List <-> Chat)
   ✅ Does NOT touch PHP logic. Only toggles visibility.
===================================================== */
(function(){
  function isMobileOrTablet(){
    return window.matchMedia && window.matchMedia('(max-width: 991.98px)').matches;
  }
  function hasPeerSelected(){
    try{
      var sp = new URLSearchParams(window.location.search || '');
      var p1 = sp.get('peer_id');
      var p2 = sp.get('peer');
      return (p1 && String(p1).trim() !== '' && String(p1) !== '0') ||
             (p2 && String(p2).trim() !== '' && String(p2) !== '0');
    }catch(e){ return false; }
  }
  function setMode(){
    if(!isMobileOrTablet()){
      document.body.classList.remove('m-mode-list','m-mode-chat');
      return;
    }
    if(hasPeerSelected()){
      document.body.classList.add('m-mode-chat');
      document.body.classList.remove('m-mode-list');
    }else{
      document.body.classList.add('m-mode-list');
      document.body.classList.remove('m-mode-chat');
    }
  }
  function ensureBackButton(){
    if(!isMobileOrTablet()) return;
    if(!hasPeerSelected()) return;

    var topbar = document.querySelector('#conversationPanel .chat-topbar') || document.querySelector('.chat-topbar');
    if(!topbar) return;

    if(topbar.querySelector('.mBackBtn')) return;

    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'mBackBtn';
    btn.setAttribute('aria-label','Back to chats');
    btn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M15 18l-6-6 6-6" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/></svg>';

    btn.addEventListener('click', function(){
      // Go back to list (same page without peer params)
      try{
        var url = new URL(window.location.href);
        url.searchParams.delete('peer_id');
        url.searchParams.delete('peer');
        window.location.href = url.pathname + (url.searchParams.toString() ? ('?' + url.searchParams.toString()) : '');
      }catch(e){
        window.location.href = 'messages.php';
      }
    });

    // Insert at beginning of topbar
    topbar.insertBefore(btn, topbar.firstChild);
  }

  document.addEventListener('DOMContentLoaded', function(){
    setMode();
    ensureBackButton();
  });

  window.addEventListener('resize', function(){
    setMode();
  });
})();
</script>

</body>
</html>
