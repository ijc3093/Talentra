<?php
// /Business_only3/public_user/ajax/user_chat_send.php
// Option A (folder): save upload to /public_user/attachment/ and store URL + metadata in DB.
//
// IMPORTANT:
// - A HTTP 413 ("Request Entity Too Large") happens BEFORE PHP runs.
//   Fix it in server config (php.ini + Apache/Nginx) and restart the server.

declare(strict_types=1);

require_once __DIR__ . '/../includes/session_user.php';
requireUserLogin();

require_once __DIR__ . '/../controller.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

// Keep JSON clean (avoid HTML warnings in response)
error_reporting(0);
@ini_set('display_errors', '0');

function j(array $a): void { echo json_encode($a); exit; }

function resolve_existing_attachment_url(string $url): array {
    $url = trim($url);
    if ($url === '' || preg_match('#^https?://#i', $url)) {
        return ['ok' => false];
    }

    $path = preg_replace('#^public_user/#', '', $url);
    $path = str_replace('\\', '/', $path);
    $path = ltrim($path, '/');
    if ($path === '' || strpos($path, '..') !== false) {
        return ['ok' => false];
    }

    $base = realpath(dirname(__DIR__));
    if ($base === false) {
        return ['ok' => false];
    }

    $full = $base . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
    $real = realpath($full);
    if ($real === false || !is_file($real) || strpos($real, $base) !== 0) {
        return ['ok' => false];
    }

    $rel = ltrim(str_replace('\\', '/', substr($real, strlen($base))), '/');
    if (!preg_match('#^(uploads/|attachment/)#i', $rel)) {
        return ['ok' => false];
    }

    return ['ok' => true, 'url' => $rel, 'path' => $real];
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

$controller = new Controller();
$dbh = $controller->pdo();

/**
 * messages.php uses friend_code as sender/receiver.
 * This endpoint MUST send using friend_code.
 */
$meCode = '';
try {
    if (function_exists('userFriendCode')) {
        $meCode = strtoupper(trim((string)userFriendCode()));
    }
} catch (Throwable $e) {}

if ($meCode === '') {
    $meCode = strtoupper(trim((string)($_SESSION['friend_code'] ?? $_SESSION['user_friend_code'] ?? '')));
}
if ($meCode === '') j(['ok' => false, 'error' => 'Invalid session (missing friend_code)']);

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

$peerCode = strtoupper(trim((string)($_POST['to'] ?? $_POST['peer'] ?? '')));
$text     = trim((string)($_POST['message'] ?? ''));
$gifUrl   = trim((string)($_POST['gif_url'] ?? ''));
$gifTitle = trim((string)($_POST['gif_title'] ?? 'GIF'));
$replyMessageId = (int)($_POST['reply_message_id'] ?? 0);
$replyPreviewAuthor = trim((string)($_POST['reply_preview_author'] ?? ''));
$replyPreviewText = trim((string)($_POST['reply_preview_text'] ?? ''));

if ($peerCode === '') j(['ok' => false, 'error' => 'Missing receiver (to)']);

// ---------------------------
// Attachment handling (Option A: folder)
// ---------------------------
$attachment         = ''; // filename stored in /attachment/
$attachmentType     = '';
$attachmentOriginal = '';
$attachmentUrl      = '';

// Friendly upload error handling
if (!empty($_FILES['attachment']) && isset($_FILES['attachment']['error'])) {
    $err = (int)($_FILES['attachment']['error'] ?? 0);

    if ($err !== UPLOAD_ERR_OK && $err !== UPLOAD_ERR_NO_FILE) {
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
            j(['ok' => false, 'error' => 'File too large for server limits (php.ini / web server).']);
        }
        j(['ok' => false, 'error' => 'Upload failed. Error code: ' . $err]);
    }
}

try {
    $stPeer = $dbh->prepare("SELECT id FROM users WHERE UPPER(friend_code) = :c AND status = 1 LIMIT 1");
    $stPeer->execute([':c' => $peerCode]);
    $peerId = (int)($stPeer->fetchColumn() ?: 0);
    if ($peerId <= 0) {
        j(['ok' => false, 'error' => 'Receiver not found']);
    }

    try {
        $stBlock = $dbh->prepare("
            SELECT 1
            FROM user_blocks
            WHERE (user_id = :me AND blocked_user_id = :peer)
               OR (user_id = :peer2 AND blocked_user_id = :me2)
            LIMIT 1
        ");
        $stBlock->execute([
            ':me' => (int)($_SESSION['user_id'] ?? 0),
            ':peer' => $peerId,
            ':peer2' => $peerId,
            ':me2' => (int)($_SESSION['user_id'] ?? 0),
        ]);
        if ($stBlock->fetchColumn()) {
            j(['ok' => false, 'error' => 'This conversation is blocked']);
        }
    } catch (Throwable $e) {
        // keep chat working if blocks table is not installed yet
    }

    if (!empty($_FILES['attachment']['name'] ?? '')) {

        $tmpPath  = (string)($_FILES['attachment']['tmp_name'] ?? '');
        $origName = (string)($_FILES['attachment']['name'] ?? '');
        $size     = (int)($_FILES['attachment']['size'] ?? 0);

        if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
            j(['ok' => false, 'error' => 'Invalid upload (tmp file missing)']);
        }

        // ✅ App-level safety cap (adjust as you like; server must allow >= this)
        $maxBytes = 250 * 1024 * 1024; // 250 MB
        if ($size > 0 && $size > $maxBytes) {
            j(['ok'=>false, 'error'=>'File too large. Max 250MB allowed by app.']);
        }

        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

        // ✅ allowed types (images, videos, docs, common code files)
        $allowed = [
            'jpg','jpeg','png','webp','gif',
            'mp4','m4v','webm','ogg','mov',
            'pdf','doc','docx','xls','xlsx','ppt','pptx','txt',
            'zip','rar',
            'sql','java','js','ts','php','html','css','json','xml','yml','yaml','md'
        ];
        if (!in_array($ext, $allowed, true)) {
            j(['ok' => false, 'error' => 'Invalid attachment type']);
        }

        // detect mime
        $mime = '';
        try {
            if (class_exists('finfo')) {
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime = (string)$finfo->file($tmpPath);
            }
        } catch (Throwable $e) {
            $mime = '';
        }
        if ($mime === '') $mime = (string)($_FILES['attachment']['type'] ?? '');
        if ($mime === '') $mime = 'application/octet-stream';

        $attachmentType     = $mime;      // DB column should be VARCHAR(255)
        $attachmentOriginal = $origName;

        // Safe unique filename (avoid collisions)
        $base = preg_replace('/[^a-zA-Z0-9-_]/', '-', pathinfo($origName, PATHINFO_FILENAME));
        $base = trim((string)$base, '-');
        if ($base === '') $base = 'file';

        // limit base length to keep filename short
        if (strlen($base) > 60) $base = substr($base, 0, 60);

        $final = strtolower($base . '-' . time() . '-' . bin2hex(random_bytes(3)) . '.' . $ext);

        // Save into /public_user/attachment/
        $dir = dirname(__DIR__) . '/attachment';
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
                j(['ok' => false, 'error' => 'Attachment folder missing and could not be created']);
            }
        }

        $dest = $dir . '/' . $final;

        if (!@move_uploaded_file($tmpPath, $dest)) {
            j(['ok' => false, 'error' => 'Attachment upload failed']);
        }

        $attachment    = $final;
        $attachmentUrl = 'attachment/' . $final;
    } elseif ($gifUrl !== '') {
        $gifParts = @parse_url($gifUrl);
        $gifScheme = strtolower((string)($gifParts['scheme'] ?? ''));
        $gifHost = trim((string)($gifParts['host'] ?? ''));
        if (!in_array($gifScheme, ['http', 'https'], true) || $gifHost === '') {
            j(['ok' => false, 'error' => 'Invalid GIF URL']);
        }

        $attachmentType = 'image/gif';
        $attachmentOriginal = ($gifTitle !== '' ? $gifTitle : 'GIF') . '.gif';
        $attachmentUrl = $gifUrl;
        $attachment = '';
    }
} catch (Throwable $e) {
    j(['ok' => false, 'error' => 'Attachment handling error']);
}

if ($attachmentUrl === '' && trim((string)($_POST['attachment_url'] ?? '')) !== '') {
    $postedAttachUrl = trim((string)($_POST['attachment_url'] ?? ''));
    $postedAttachType = trim((string)($_POST['attachment_type'] ?? ''));
    $postedAttachOriginal = trim((string)($_POST['attachment_original'] ?? ''));
    $resolved = resolve_existing_attachment_url($postedAttachUrl);
    if (empty($resolved['ok'])) {
        j(['ok' => false, 'error' => 'Invalid story media attachment']);
    }
    $attachmentUrl = (string)($resolved['url'] ?? '');
    $attachmentOriginal = $postedAttachOriginal !== '' ? $postedAttachOriginal : basename($attachmentUrl);
    $attachmentType = $postedAttachType;
    if ($attachmentType === '') {
        $mime = '';
        try {
            if (class_exists('finfo')) {
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime = (string)$finfo->file((string)($resolved['path'] ?? ''));
            }
        } catch (Throwable $e) {}
        if ($mime === '') {
            $mime = 'application/octet-stream';
        }
        $attachmentType = $mime;
    }
}

if ($text === '' && $attachment === '' && $attachmentUrl === '') {
    j(['ok' => false, 'error' => 'Message cannot be empty']);
}

$storedText = $text;
if ($replyPreviewText !== '') {
    $replyPayload = base64_encode((string)json_encode([
        'message_id' => $replyMessageId,
        'author' => $replyPreviewAuthor !== '' ? $replyPreviewAuthor : 'message',
        'text' => $replyPreviewText,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $storedText = '[[reply:' . $replyPayload . ']]' . $text;
}

// ---------------------------
// Insert into feedback
// ---------------------------
try {
    $st = $dbh->prepare("
        INSERT INTO feedback
            (sender, receiver, channel, title, feedbackdata,
             attachment, attachment_type, attachment_original, attachment_url,
             is_read, created_at)
        VALUES
            (:s, :r, 'user_user', '', :msg,
             :a, :at, :ao, :au,
             0, NOW())
    ");

    $st->execute([
        ':s'   => $meCode,
        ':r'   => $peerCode,
        ':msg' => $storedText,

        ':a'   => ($attachment !== '' ? $attachment : null),
        ':at'  => ($attachmentType !== '' ? $attachmentType : null),
        ':ao'  => ($attachmentOriginal !== '' ? $attachmentOriginal : null),
        ':au'  => ($attachmentUrl !== '' ? $attachmentUrl : null),
    ]);

    $id = (int)$dbh->lastInsertId();

    $createdAt = date('Y-m-d H:i:s');
    $ts = strtotime($createdAt) ?: time();
    $replyBits = parse_reply_payload($storedText);

    j([
        'ok' => true,
        'item' => [
            'id'                  => $id,
            'sender'              => $meCode,
            'receiver'            => $peerCode,
            'feedbackdata'        => $storedText,
            'text'                => (string)($replyBits['text'] ?? $text),
            'reply_author'        => (string)($replyBits['reply_author'] ?? $replyPreviewAuthor),
            'reply_text'          => (string)($replyBits['reply_text'] ?? $replyPreviewText),
            'reply_message_id'    => (int)($replyBits['reply_message_id'] ?? $replyMessageId),

            'attachment'          => $attachment,
            'attachment_type'     => $attachmentType,
            'attachment_original' => $attachmentOriginal,
            'attachment_url'      => $attachmentUrl,

            'created_at'          => $createdAt,
            'time_label'          => date('M d, Y h:i A', $ts),
            'day_key'             => date('Y-m-d', $ts),
            'day_label'           => date('M j, Y', $ts),
            'is_read'             => 0
        ]
    ]);

} catch (Throwable $e) {
    j(['ok' => false, 'error' => 'Database error']);
}
