<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/session_user.php';
requireUserLogin();

require_once __DIR__ . '/../controller.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

function j(array $a): void { echo json_encode($a); exit; }
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

function load_message_reactions(PDO $dbh, int $messageId): array {
    if ($messageId <= 0 || !ensure_message_reactions_table($dbh)) {
        return [];
    }
    try {
        $st = $dbh->prepare("
            SELECT reaction
            FROM user_message_reactions
            WHERE feedback_id = :mid
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
$meCode = strtoupper(trim((string)($_SESSION['friend_code'] ?? $_SESSION['user_friend_code'] ?? '')));
if ($meId <= 0 || $meCode === '') j(['ok' => false, 'error' => 'Invalid session']);

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

$action = strtolower(trim((string)($_POST['action'] ?? '')));
$messageId = (int)($_POST['message_id'] ?? 0);
$peerCode = strtoupper(trim((string)($_POST['peer'] ?? '')));
$text = trim((string)($_POST['text'] ?? ''));

if (!in_array($action, ['edit','unsend_me','unsend_everyone','block','react'], true)) {
    j(['ok' => false, 'error' => 'Invalid action']);
}
if ($action !== 'block' && $messageId <= 0) {
    j(['ok' => false, 'error' => 'Missing message id']);
}

try {
    $hasEditedAt = column_exists($dbh, 'feedback', 'edited_at');
    $hasDeletedForAll = column_exists($dbh, 'feedback', 'deleted_for_all');
    $hasDeletedForAllAt = column_exists($dbh, 'feedback', 'deleted_for_all_at');
    $hasHiddenTable = ensure_user_chat_hidden_messages_table($dbh);
    $hasBlocksTable = table_exists($dbh, 'user_blocks');

    if ($action === 'block') {
        if (!$hasBlocksTable) j(['ok' => false, 'error' => 'Block table not installed yet']);
        if ($peerCode === '') j(['ok' => false, 'error' => 'Missing peer']);
        $stPeer = $dbh->prepare("SELECT id FROM users WHERE UPPER(friend_code) = :c AND status = 1 LIMIT 1");
        $stPeer->execute([':c' => $peerCode]);
        $peerId = (int)($stPeer->fetchColumn() ?: 0);
        if ($peerId <= 0) j(['ok' => false, 'error' => 'Peer not found']);

        $st = $dbh->prepare("
            INSERT INTO user_blocks (user_id, blocked_user_id, created_at)
            VALUES (:uid, :bid, NOW())
            ON DUPLICATE KEY UPDATE created_at = created_at
        ");
        $st->execute([':uid' => $meId, ':bid' => $peerId]);
        j(['ok' => true, 'action' => 'block']);
    }

    $stMsg = $dbh->prepare("
        SELECT id, sender, receiver, feedbackdata,
               " . ($hasDeletedForAll ? "COALESCE(deleted_for_all, 0)" : "0") . " AS deleted_for_all
        FROM feedback
        WHERE id = :id
          AND channel = 'user_user'
        LIMIT 1
    ");
    $stMsg->execute([':id' => $messageId]);
    $msg = $stMsg->fetch(PDO::FETCH_ASSOC);
    if (!$msg) j(['ok' => false, 'error' => 'Message not found']);

    $sender = strtoupper(trim((string)($msg['sender'] ?? '')));
    $receiver = strtoupper(trim((string)($msg['receiver'] ?? '')));
    $isMeSender = ($sender === $meCode);
    $isMine = ($sender === $meCode || $receiver === $meCode);
    if (!$isMine) j(['ok' => false, 'error' => 'Forbidden']);

    if ($action === 'edit') {
        if (!$isMeSender) j(['ok' => false, 'error' => 'Only your message can be edited']);
        if ((int)($msg['deleted_for_all'] ?? 0) === 1) j(['ok' => false, 'error' => 'Message already unsent']);
        if ($text === '') j(['ok' => false, 'error' => 'Message text cannot be empty']);

        $sql = "
            UPDATE feedback
            SET feedbackdata = :txt" . ($hasEditedAt ? ", edited_at = NOW()" : "") . "
            WHERE id = :id
            LIMIT 1
        ";
        $st = $dbh->prepare($sql);
        $st->execute([':txt' => $text, ':id' => $messageId]);
        j(['ok' => true, 'action' => 'edit', 'text' => $text]);
    }

    if ($action === 'react') {
        if ((int)($msg['deleted_for_all'] ?? 0) === 1) {
            j(['ok' => false, 'error' => 'Message already unsent']);
        }
        $emoji = trim($text);
        if (!ensure_message_reactions_table($dbh)) {
            j(['ok' => false, 'error' => 'Reaction storage unavailable']);
        }

        $stCurrent = $dbh->prepare("
            SELECT reaction
            FROM user_message_reactions
            WHERE feedback_id = :mid AND user_id = :uid
            LIMIT 1
        ");
        $stCurrent->execute([':mid' => $messageId, ':uid' => $meId]);
        $currentReaction = trim((string)($stCurrent->fetchColumn() ?: ''));

        if ($emoji === '' || $emoji === $currentReaction) {
            $stDelete = $dbh->prepare("
                DELETE FROM user_message_reactions
                WHERE feedback_id = :mid AND user_id = :uid
                LIMIT 1
            ");
            $stDelete->execute([':mid' => $messageId, ':uid' => $meId]);
        } else {
            $stUpsert = $dbh->prepare("
                INSERT INTO user_message_reactions (feedback_id, user_id, reaction)
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
            'reactions' => load_message_reactions($dbh, $messageId),
        ]);
    }

    if ($action === 'unsend_me') {
        if (!$hasHiddenTable) j(['ok' => false, 'error' => 'Delete-for-me table not installed yet']);
        $st = $dbh->prepare("
            INSERT INTO user_chat_hidden_messages (user_id, feedback_id, hidden_at)
            VALUES (:uid, :mid, NOW())
            ON DUPLICATE KEY UPDATE hidden_at = hidden_at
        ");
        $st->execute([':uid' => $meId, ':mid' => $messageId]);
        j(['ok' => true, 'action' => 'unsend_me']);
    }

    if ($action === 'unsend_everyone') {
        if (!$isMeSender) j(['ok' => false, 'error' => 'Only your message can be unsent for everyone']);
        $setParts = [
            "feedbackdata = ''",
            "attachment = NULL",
            "attachment_type = NULL",
            "attachment_original = NULL",
            "attachment_url = NULL"
        ];
        if ($hasDeletedForAll) {
            $setParts[] = "deleted_for_all = 1";
        }
        if ($hasDeletedForAllAt) {
            $setParts[] = "deleted_for_all_at = NOW()";
        }
        if ($hasEditedAt) {
            $setParts[] = "edited_at = NULL";
        }
        $st = $dbh->prepare("
            UPDATE feedback
            SET " . implode(",\n                ", $setParts) . "
            WHERE id = :id
            LIMIT 1
        ");
        $st->execute([':id' => $messageId]);
        if (ensure_message_reactions_table($dbh)) {
            $stReactDelete = $dbh->prepare("DELETE FROM user_message_reactions WHERE feedback_id = :mid");
            $stReactDelete->execute([':mid' => $messageId]);
        }
        j(['ok' => true, 'action' => 'unsend_everyone']);
    }

    j(['ok' => false, 'error' => 'Unsupported action']);
} catch (Throwable $e) {
    j(['ok' => false, 'error' => 'Database error']);
}
