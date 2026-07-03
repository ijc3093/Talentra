<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/session_user.php';
requireUserLogin();

require_once __DIR__ . '/../controller.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

function j(array $a): void { echo json_encode($a); exit; }

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

function load_chat_group_message_reactions(PDO $dbh, int $messageId): array {
    if ($messageId <= 0 || !ensure_chat_group_message_reactions_table($dbh)) {
        return [];
    }
    try {
        $st = $dbh->prepare("
            SELECT reaction
            FROM chat_group_message_reactions
            WHERE group_message_id = :mid
            ORDER BY updated_at ASC, id ASC
        ");
        $st->execute([':mid' => $messageId]);
        $items = [];
        foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
            $reaction = trim((string)($row['reaction'] ?? ''));
            if ($reaction !== '') {
                $items[] = $reaction;
            }
        }
        return $items;
    } catch (Throwable $e) {
        return [];
    }
}

$controller = new Controller();
$dbh = $controller->pdo();

$meId = (int)($_SESSION['user_id'] ?? 0);
if ($meId <= 0) {
    j(['ok' => false, 'error' => 'Invalid session']);
}

$action = trim((string)($_POST['action'] ?? ''));
$groupId = (int)($_POST['group_id'] ?? 0);
$messageId = (int)($_POST['message_id'] ?? 0);
$emoji = trim((string)($_POST['text'] ?? ''));

if ($action !== 'react') {
    j(['ok' => false, 'error' => 'Unsupported action']);
}
if ($groupId <= 0 || $messageId <= 0) {
    j(['ok' => false, 'error' => 'Message not found']);
}
if ($emoji === '' || mb_strlen($emoji) > 16) {
    j(['ok' => false, 'error' => 'Invalid reaction']);
}
if (!ensure_chat_group_message_reactions_table($dbh)) {
    j(['ok' => false, 'error' => 'Could not prepare reactions']);
}

try {
    $st = $dbh->prepare("
        SELECT gm.id
        FROM chat_group_messages gm
        JOIN chat_group_members gmem
          ON gmem.group_id = gm.group_id
         AND gmem.user_id = :uid
         AND gmem.left_at IS NULL
         AND gmem.blocked_at IS NULL
        WHERE gm.id = :mid
          AND gm.group_id = :gid
        LIMIT 1
    ");
    $st->execute([
        ':uid' => $meId,
        ':mid' => $messageId,
        ':gid' => $groupId,
    ]);
    if (!$st->fetch(PDO::FETCH_ASSOC)) {
        j(['ok' => false, 'error' => 'Message not found']);
    }

    $stExisting = $dbh->prepare("
        SELECT reaction
        FROM chat_group_message_reactions
        WHERE group_message_id = :mid AND user_id = :uid
        LIMIT 1
    ");
    $stExisting->execute([
        ':mid' => $messageId,
        ':uid' => $meId,
    ]);
    $existingReaction = trim((string)($stExisting->fetchColumn() ?: ''));

    if ($existingReaction !== '' && $existingReaction === $emoji) {
        $stDelete = $dbh->prepare("
            DELETE FROM chat_group_message_reactions
            WHERE group_message_id = :mid AND user_id = :uid
            LIMIT 1
        ");
        $stDelete->execute([
            ':mid' => $messageId,
            ':uid' => $meId,
        ]);
    } else {
        $stUpsert = $dbh->prepare("
            INSERT INTO chat_group_message_reactions (group_message_id, user_id, reaction)
            VALUES (:mid, :uid, :reaction)
            ON DUPLICATE KEY UPDATE reaction = VALUES(reaction), updated_at = CURRENT_TIMESTAMP
        ");
        $stUpsert->execute([
            ':mid' => $messageId,
            ':uid' => $meId,
            ':reaction' => $emoji,
        ]);
    }

    j([
        'ok' => true,
        'action' => 'react',
        'reactions' => load_chat_group_message_reactions($dbh, $messageId),
    ]);
} catch (Throwable $e) {
    j(['ok' => false, 'error' => 'Could not update reaction']);
}
