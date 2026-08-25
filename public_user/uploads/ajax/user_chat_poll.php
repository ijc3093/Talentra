<?php
// /Business_only3/ajax/user_chat_poll.php
// - Long-poll chat messages: ?peer=USR-XXXX-YYYY&after=123&wait=20
// - Header dropdown unread threads: ?mode=unread_threads

declare(strict_types=1);
error_reporting(0);

require_once __DIR__ . '/../includes/session_user.php';
requireUserLogin();

require_once __DIR__ . '/../includes/user_identity.php';
require_once __DIR__ . '/../controller.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

function j(array $arr): void {
    echo json_encode($arr);
    exit;
}

function parse_reply_payload(string $text): array {
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

function table_exists(PDO $dbh, string $table): bool {
    try {
        $st = $dbh->prepare("SHOW TABLES LIKE ?");
        $st->execute([$table]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function column_exists(PDO $dbh, string $table, string $column): bool {
    try {
        $st = $dbh->prepare("SHOW COLUMNS FROM `{$table}` LIKE ?");
        $st->execute([$column]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        return false;
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

function load_message_reaction_map(PDO $dbh, array $messageIds): array {
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

$mode = trim((string)($_GET['mode'] ?? ''));

// ---------------------------------------------------------------------
// MODE: unread_threads (for header dropdown)
// ---------------------------------------------------------------------
if ($mode === 'unread_threads') {
    try {
        $st = $dbh->prepare("
            SELECT
                u.friend_code AS peer_code,
                uc.display_name AS contact_name,
                COALESCE(NULLIF(uc.display_name,''), u.friend_code) AS peer_display,
                MAX(f.created_at) AS last_time,
                SUBSTRING_INDEX(
                    GROUP_CONCAT(f.feedbackdata ORDER BY f.created_at DESC SEPARATOR '\n'),
                    '\n',
                    1
                ) AS last_message,
                COUNT(*) AS unread_count
            FROM feedback f
            JOIN users u
              ON (u.friend_code = f.sender OR u.email = f.sender)
            LEFT JOIN user_contacts uc
              ON uc.owner_user_id = :meId
             AND uc.friend_user_id = u.id
            WHERE f.channel = 'user_user'
              AND (f.receiver = :meCode OR f.receiver = :meEmail)
              AND f.is_read = 0
            GROUP BY u.friend_code, peer_display
            ORDER BY last_time DESC
            LIMIT 12
        ");
        $st->execute([
            ':meId'   => $meId,
            ':meCode' => $meCode,
            ':meEmail'=> $meEmail,
        ]);
        $items = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $total = 0;
        $unknown = 0;
        foreach ($items as $it) {
            $c = (int)($it['unread_count'] ?? 0);
            $total += $c;
            if (trim((string)($it['contact_name'] ?? '')) === '') {
                $unknown += $c;
            }
        }

        j(['ok' => true, 'items' => $items, 'total_unread' => $total, 'unknown_unread' => $unknown]);
    } catch (Throwable $e) {
        j(['ok' => false, 'error' => 'Server error']);
    }
}

// ---------------------------------------------------------------------
// MODE: chat message long-poll
// ---------------------------------------------------------------------
$peerCode = strtoupper(trim((string)($_GET['peer'] ?? '')));
if ($peerCode === '') {
    $peerCode = strtoupper(trim((string)($_GET['reply'] ?? '')));
}

$after = (int)($_GET['after'] ?? 0);
$wait  = (int)($_GET['wait'] ?? 0);
if ($wait < 0) $wait = 0;
if ($wait > 25) $wait = 25;

$markRead = (int)($_GET['mark'] ?? 0);

if ($peerCode === '') j(['ok' => false, 'error' => 'Missing peer']);
if (!preg_match('/^[A-Z]{3}-[A-Z0-9]{4}-[A-Z0-9]{4}$/i', $peerCode)) {
    j(['ok' => false, 'error' => 'Friend code required']);
}

try {
    $hasEditedAt = column_exists($dbh, 'feedback', 'edited_at');
    $hasDeletedForAll = column_exists($dbh, 'feedback', 'deleted_for_all');
    $hasHiddenTable = ensure_user_chat_hidden_messages_table($dbh);

    // Resolve peer
    $st = $dbh->prepare("
        SELECT id, email, friend_code,
               COALESCE(NULLIF(name,''), NULLIF(username,''), friend_code) AS db_display
        FROM users
        WHERE UPPER(friend_code) = :c
          AND status = 1
        LIMIT 1
    ");
    $st->execute([':c' => strtoupper($peerCode)]);
    $peer = $st->fetch(PDO::FETCH_ASSOC);

    if (!$peer) j(['ok' => false, 'error' => 'Friend not found']);

    $peerId    = (int)($peer['id'] ?? 0);
    $peerEmail = (string)($peer['email'] ?? '');

    // Prefer nickname, otherwise friend code
    $peerDisplay = $peerCode;
    try {
        $ns = $dbh->prepare("SELECT display_name FROM user_contacts WHERE owner_user_id = :me AND friend_user_id = :fid LIMIT 1");
        $ns->execute([':me' => $meId, ':fid' => $peerId]);
        $nick = trim((string)$ns->fetchColumn());
        if ($nick !== '') $peerDisplay = $nick;
    } catch (Throwable $e) {}

    $deadline = $wait > 0 ? (microtime(true) + $wait) : microtime(true);

    $items = [];
    $lastId = $after;

    do {
        $q = $dbh->prepare("
            SELECT f.id, f.sender, f.receiver, f.feedbackdata, f.created_at, f.is_read,
                f.attachment, f.attachment_type, f.attachment_original, f.attachment_url,
                " . ($hasEditedAt ? "COALESCE(f.edited_at, NULL)" : "NULL") . " AS edited_at,
                " . ($hasDeletedForAll ? "COALESCE(f.deleted_for_all, 0)" : "0") . " AS deleted_for_all,
                (f.attachment_blob IS NOT NULL) AS has_blob
            FROM feedback f
            " . ($hasHiddenTable ? "LEFT JOIN user_chat_hidden_messages uhm ON uhm.feedback_id = f.id AND uhm.user_id = :meId" : "") . "
            WHERE f.channel = 'user_user'
            AND f.id > :after
            " . ($hasHiddenTable ? "AND uhm.id IS NULL" : "") . "
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
            LIMIT 200
        ");
        $params = [
            ':after'      => $after,
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
        $q->execute($params);
        $rows = $q->fetchAll(PDO::FETCH_ASSOC) ?: [];

        if ($rows) {
            foreach ($rows as $r) {
                $id = (int)($r['id'] ?? 0);
                if ($id > $lastId) $lastId = $id;

                $sender = (string)($r['sender'] ?? '');
                $isMe = (strcasecmp($sender, $meCode) === 0) || ($meEmail !== '' && strcasecmp($sender, $meEmail) === 0);

                $created = (string)($r['created_at'] ?? '');
                $ts = $created ? strtotime($created) : 0;

                // ✅ attachments per row
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

                $dayKey = $ts ? date('Y-m-d', $ts) : '';
                $dayLbl = $ts ? date('M j, Y', $ts) : '';
                $replyBits = parse_reply_payload((string)($r['feedbackdata'] ?? ''));

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
                    'day_key' => $dayKey,
                    'day_label' => $dayLbl,
                    'is_read' => (int)($r['is_read'] ?? 0),
                    'edited_at' => (string)($r['edited_at'] ?? ''),
                    'deleted_for_all' => (int)($r['deleted_for_all'] ?? 0),

                    'attachment' => $attFile,
                    'attachment_type' => $attType,
                    'attachment_original' => $attOrig,
                    'attachment_url' => $attUrl,
                ];
            }
            break;
        }

        if ($wait <= 0) break;
        usleep(250000);
    } while (microtime(true) < $deadline);

    if (!empty($items)) {
        $reactionMap = load_message_reaction_map($dbh, array_column($items, 'id'));
        foreach ($items as &$item) {
            $item['reactions'] = $reactionMap[(int)($item['id'] ?? 0)] ?? [];
        }
        unset($item);
    }

    if ($markRead === 1) {
        $mk = $dbh->prepare("
            UPDATE feedback
            SET is_read = 1, read_at = NOW()
            WHERE channel = 'user_user'
            AND (
                    (receiver = :meCode OR receiver = :meEmail)
                )
            AND (
                    (sender = :peerCode OR sender = :peerEmail)
                )
            AND is_read = 0
        ");

        $mk->execute([
            ':meCode'    => $meCode,
            ':meEmail'   => $meEmail,
            ':peerCode'  => $peerCode,
            ':peerEmail' => $peerEmail,
        ]);
    }

    j(['ok' => true, 'items' => $items, 'last_id' => $lastId, 'peer_display' => $peerDisplay]);

} catch (Throwable $e) {
    j(['ok' => false, 'error' => 'Server error']);
}
