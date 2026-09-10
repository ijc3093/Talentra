<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/session_user.php';
requireUserLogin();

require_once __DIR__ . '/../controller.php';
require_once __DIR__ . '/../includes/group_video_call_lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

function j(array $a): void { echo json_encode($a); exit; }

function ensure_chat_group_message_reactions_table_send(PDO $dbh): bool {
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

function load_chat_group_message_reactions_send(PDO $dbh, int $messageId): array {
    if ($messageId <= 0 || !ensure_chat_group_message_reactions_table_send($dbh)) {
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
if ($meId <= 0) {
    j(['ok' => false, 'error' => 'Invalid session']);
}

$groupId = (int)($_POST['group_id'] ?? 0);
$body = trim((string)($_POST['message'] ?? ''));
$replyMessageId = (int)($_POST['reply_message_id'] ?? 0);
$replyPreviewAuthor = trim((string)($_POST['reply_preview_author'] ?? ''));
$replyPreviewText = trim((string)($_POST['reply_preview_text'] ?? ''));
$file = $_FILES['attachment'] ?? null;
$fileName = trim((string)($file['name'] ?? ''));
$hasFile = ($fileName !== '' && (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE);
if ($groupId <= 0) j(['ok' => false, 'error' => 'Select a group first.']);
if ($body === '' && !$hasFile) j(['ok' => false, 'error' => 'Type a message or choose media before sending.']);
if (mb_strlen($body) > 5000) j(['ok' => false, 'error' => 'Message is too long.']);

try {
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
        j(['ok' => false, 'error' => 'Group not found.']);
    }

    $storedBody = $body;
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
            j(['ok' => false, 'error' => 'Media upload failed.']);
        }
        $tmp = (string)($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            j(['ok' => false, 'error' => 'Invalid media upload.']);
        }
        $attachmentOriginal = $fileName;
        $attachmentType = trim((string)($file['type'] ?? ''));
        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        $allowed = ['jpg','jpeg','png','gif','webp','mp4','webm','ogg','mov','pdf'];
        if (!in_array($ext, $allowed, true)) {
            j(['ok' => false, 'error' => 'Unsupported media type.']);
        }
        $uploadDir = dirname(__DIR__) . '/attachment/';
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
            j(['ok' => false, 'error' => 'Upload folder is not ready.']);
        }
        $safeBase = preg_replace('/[^A-Za-z0-9_-]+/', '-', pathinfo($fileName, PATHINFO_FILENAME));
        $safeBase = trim((string)$safeBase, '-');
        if ($safeBase === '') $safeBase = 'group-media';
        $finalName = strtolower($safeBase . '-' . time() . '-' . mt_rand(1000, 9999) . '.' . $ext);
        if (!move_uploaded_file($tmp, $uploadDir . $finalName)) {
            j(['ok' => false, 'error' => 'Could not save uploaded media.']);
        }
        $attachmentUrl = 'attachment/' . $finalName;
    }

    if ($attachmentUrl !== '') {
        $stIns = $dbh->prepare("
            INSERT INTO chat_group_messages (group_id, sender_user_id, body, attachment_url, attachment_type, attachment_original, created_at)
            VALUES (:gid, :uid, :body, :attachment_url, :attachment_type, :attachment_original, NOW())
        ");
        $stIns->execute([
            ':gid' => $groupId,
            ':uid' => $meId,
            ':body' => $storedBody,
            ':attachment_url' => $attachmentUrl,
            ':attachment_type' => $attachmentType,
            ':attachment_original' => $attachmentOriginal,
        ]);
    } else {
        $stIns = $dbh->prepare("
            INSERT INTO chat_group_messages (group_id, sender_user_id, body, created_at)
            VALUES (:gid, :uid, :body, NOW())
        ");
        $stIns->execute([
            ':gid' => $groupId,
            ':uid' => $meId,
            ':body' => $storedBody,
        ]);
    }
    $messageId = (int)$dbh->lastInsertId();

    $dbh->prepare("UPDATE chat_groups SET updated_at = NOW() WHERE id = :gid LIMIT 1")
        ->execute([':gid' => $groupId]);

    $stMsg = $dbh->prepare("
        SELECT
            gm.id,
            gm.body,
            gm.created_at,
            COALESCE(gm.attachment_url, '') AS attachment_url,
            COALESCE(gm.attachment_type, '') AS attachment_type,
            COALESCE(gm.attachment_original, '') AS attachment_original,
            COALESCE(NULLIF(u.name,''), NULLIF(u.username,''), u.friend_code) AS sender_name,
            COALESCE(u.friend_code, '') AS friend_code
        FROM chat_group_messages gm
        JOIN users u
          ON u.id = gm.sender_user_id
        WHERE gm.id = :mid
        LIMIT 1
    ");
    $stMsg->execute([':mid' => $messageId]);
    $item = $stMsg->fetch(PDO::FETCH_ASSOC);
    if (!$item) {
        j(['ok' => false, 'error' => 'Could not load sent message.']);
    }
    $replyBits = parse_reply_payload_ajax((string)($item['body'] ?? ''));

    $signalMessageItem = [
        'id' => (int)($item['id'] ?? 0),
        'is_me' => false,
        'text' => (string)($replyBits['text'] ?? ''),
        'created_at' => (string)($item['created_at'] ?? ''),
        'time_label' => (string)date('M d, Y h:i A', strtotime((string)($item['created_at'] ?? 'now'))),
        'day_key' => (string)date('Y-m-d', strtotime((string)($item['created_at'] ?? 'now'))),
        'day_label' => (string)date('M j, Y', strtotime((string)($item['created_at'] ?? 'now'))),
        'sender_name' => (string)($item['sender_name'] ?? ''),
        'friend_code' => (string)($item['friend_code'] ?? ''),
        'reply_author' => (string)($replyBits['reply_author'] ?? ''),
        'reply_text' => (string)($replyBits['reply_text'] ?? ''),
        'reply_message_id' => (int)($replyBits['reply_message_id'] ?? 0),
        'attachment_url' => (string)($item['attachment_url'] ?? ''),
        'attachment_type' => (string)($item['attachment_type'] ?? ''),
        'attachment_original' => (string)($item['attachment_original'] ?? ''),
    ];
    broadcast_group_call_chat_message($dbh, $groupId, $meId, $signalMessageItem);

    j([
        'ok' => true,
        'item' => [
            'id' => (int)($item['id'] ?? 0),
            'is_me' => true,
            'text' => (string)($replyBits['text'] ?? ''),
            'created_at' => (string)($item['created_at'] ?? ''),
            'time_label' => (string)date('M d, Y h:i A', strtotime((string)($item['created_at'] ?? 'now'))),
            'day_key' => (string)date('Y-m-d', strtotime((string)($item['created_at'] ?? 'now'))),
            'day_label' => (string)date('M j, Y', strtotime((string)($item['created_at'] ?? 'now'))),
            'sender_name' => (string)($item['sender_name'] ?? ''),
            'friend_code' => (string)($item['friend_code'] ?? ''),
            'reply_author' => (string)($replyBits['reply_author'] ?? ''),
            'reply_text' => (string)($replyBits['reply_text'] ?? ''),
            'reply_message_id' => (int)($replyBits['reply_message_id'] ?? 0),
            'attachment_url' => (string)($item['attachment_url'] ?? ''),
            'attachment_type' => (string)($item['attachment_type'] ?? ''),
            'attachment_original' => (string)($item['attachment_original'] ?? ''),
            'deleted_for_all' => 0,
            'reactions' => load_chat_group_message_reactions_send($dbh, (int)($item['id'] ?? 0)),
        ],
    ]);
} catch (Throwable $e) {
    j(['ok' => false, 'error' => 'Unable to send the message right now.']);
}
