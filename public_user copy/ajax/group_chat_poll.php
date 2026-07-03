<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/session_user.php';
requireUserLogin();

require_once __DIR__ . '/../controller.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

function j(array $a): void { echo json_encode($a); exit; }

function poll_db_column_exists(PDO $dbh, string $table, string $column): bool {
    try {
        $st = $dbh->prepare("
            SELECT 1
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = :table_name
              AND COLUMN_NAME = :column_name
            LIMIT 1
        ");
        $st->execute([
            ':table_name' => $table,
            ':column_name' => $column,
        ]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function ensure_chat_group_hidden_messages_table_poll(PDO $dbh): bool {
    try {
        $dbh->exec("
            CREATE TABLE IF NOT EXISTS chat_group_hidden_messages (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
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

function ensure_chat_group_message_reactions_table_poll(PDO $dbh): bool {
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

function load_chat_group_message_reactions_poll(PDO $dbh, array $messageIds): array {
    $ids = array_values(array_filter(array_map('intval', $messageIds), static function (int $id): bool {
        return $id > 0;
    }));
    if (!$ids || !ensure_chat_group_message_reactions_table_poll($dbh)) {
        return [];
    }
    try {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
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

function parse_reply_payload_ajax(string $text): array {
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

$controller = new Controller();
$dbh = $controller->pdo();

$meId = (int)($_SESSION['user_id'] ?? 0);
if ($meId <= 0) j(['ok' => false, 'error' => 'Invalid session']);

$groupId = (int)($_GET['group_id'] ?? 0);
$afterId = (int)($_GET['after'] ?? 0);
$wait = (int)($_GET['wait'] ?? 0);
if ($wait < 0) $wait = 0;
if ($wait > 12) $wait = 12;
if ($groupId <= 0) j(['ok' => false, 'error' => 'Missing group']);

try {
    $hasDeletedForAll = poll_db_column_exists($dbh, 'chat_group_messages', 'deleted_for_all');
    $hasHiddenTable = ensure_chat_group_hidden_messages_table_poll($dbh);

    $stGroup = $dbh->prepare("
        SELECT g.id
        FROM chat_groups g
        JOIN chat_group_members gm
          ON gm.group_id = g.id
         AND gm.user_id = :uid
         AND gm.left_at IS NULL
         AND gm.blocked_at IS NULL
        WHERE g.id = :gid
          AND g.status = 1
        LIMIT 1
    ");
    $stGroup->execute([':uid' => $meId, ':gid' => $groupId]);
    if (!$stGroup->fetch(PDO::FETCH_ASSOC)) {
        j(['ok' => false, 'error' => 'Group not found']);
    }

    $deadline = $wait > 0 ? (microtime(true) + $wait) : microtime(true);
    do {
        $st = $dbh->prepare("
            SELECT
                gm.id,
                gm.body,
                gm.created_at,
                gm.sender_user_id,
                " . ($hasDeletedForAll ? "COALESCE(gm.deleted_for_all, 0)" : "0") . " AS deleted_for_all,
                COALESCE(gm.attachment_url, '') AS attachment_url,
                COALESCE(gm.attachment_type, '') AS attachment_type,
                COALESCE(gm.attachment_original, '') AS attachment_original,
                COALESCE(NULLIF(u.name,''), NULLIF(u.username,''), u.friend_code) AS sender_name,
                COALESCE(u.friend_code, '') AS friend_code
            FROM chat_group_messages gm
            JOIN users u
              ON u.id = gm.sender_user_id
            " . ($hasHiddenTable ? "LEFT JOIN chat_group_hidden_messages ghm ON ghm.group_message_id = gm.id AND ghm.user_id = :hidden_uid" : "") . "
            WHERE gm.group_id = :gid
              AND gm.id > :after_id
              " . ($hasHiddenTable ? "AND ghm.id IS NULL" : "") . "
            ORDER BY gm.id ASC
            LIMIT 100
        ");
        $params = [
            ':gid' => $groupId,
            ':after_id' => $afterId,
        ];
        if ($hasHiddenTable) {
            $params[':hidden_uid'] = $meId;
        }
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        if ($rows || $wait <= 0 || microtime(true) >= $deadline) {
            $reactionMap = load_chat_group_message_reactions_poll($dbh, array_column($rows, 'id'));
            $items = [];
            $lastId = $afterId;
            foreach ($rows as $row) {
                $id = (int)($row['id'] ?? 0);
                if ($id > $lastId) $lastId = $id;
                $createdAt = (string)($row['created_at'] ?? '');
                $ts = strtotime($createdAt) ?: time();
                $replyBits = parse_reply_payload_ajax((string)($row['body'] ?? ''));
                $items[] = [
                    'id' => $id,
                    'is_me' => ((int)($row['sender_user_id'] ?? 0) === $meId),
                    'text' => (string)($replyBits['text'] ?? ''),
                    'created_at' => $createdAt,
                    'time_label' => date('M d, Y h:i A', $ts),
                    'day_key' => date('Y-m-d', $ts),
                    'day_label' => date('M j, Y', $ts),
                    'sender_name' => (string)($row['sender_name'] ?? ''),
                    'friend_code' => (string)($row['friend_code'] ?? ''),
                    'reply_author' => (string)($replyBits['reply_author'] ?? ''),
                    'reply_text' => (string)($replyBits['reply_text'] ?? ''),
                    'reply_message_id' => (int)($replyBits['reply_message_id'] ?? 0),
                    'attachment_url' => (string)($row['attachment_url'] ?? ''),
                    'attachment_type' => (string)($row['attachment_type'] ?? ''),
                    'attachment_original' => (string)($row['attachment_original'] ?? ''),
                    'deleted_for_all' => (int)($row['deleted_for_all'] ?? 0),
                    'reactions' => $reactionMap[$id] ?? [],
                ];
            }

            j([
                'ok' => true,
                'items' => $items,
                'last_id' => $lastId,
            ]);
        }

        usleep(250000);
    } while (microtime(true) < $deadline);

    j([
        'ok' => true,
        'items' => [],
        'last_id' => $afterId,
    ]);
} catch (Throwable $e) {
    j(['ok' => false, 'error' => 'Database error']);
}
