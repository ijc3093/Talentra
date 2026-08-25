<?php
// /Business_only3/public_user/ajax/attachment_view.php
// Serve attachments stored as BLOB in feedback table (Option A).
declare(strict_types=1);
error_reporting(0);

require_once __DIR__ . '/../includes/session_user.php';
requireUserLogin();

require_once __DIR__ . '/../includes/user_identity.php';
require_once __DIR__ . '/../controller.php';

$controller = new Controller();
$dbh = $controller->pdo();

$meCode  = strtoupper(trim((string)userFriendCode()));
$meEmail = trim((string)userEmail());
if ($meCode === '' && $meEmail === '') {
    http_response_code(403);
    exit;
}

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(404);
    exit;
}

try {
    // Only allow sender/receiver to access.
    $st = $dbh->prepare("
        SELECT sender, receiver,
               attachment_type, attachment_original, attachment_blob
        FROM feedback
        WHERE id = :id
          AND channel = 'user_user'
        LIMIT 1
    ");
    $st->execute([':id' => $id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        http_response_code(404);
        exit;
    }

    $sender   = strtoupper((string)($row['sender'] ?? ''));
    $receiver = strtoupper((string)($row['receiver'] ?? ''));
    $ok = ($sender === strtoupper($meCode)) || ($receiver === strtoupper($meCode));
    if (!$ok && $meEmail !== '') {
        $ok = (strcasecmp((string)($row['sender'] ?? ''), $meEmail) === 0) || (strcasecmp((string)($row['receiver'] ?? ''), $meEmail) === 0);
    }
    if (!$ok) {
        http_response_code(403);
        exit;
    }

    $blob = $row['attachment_blob'] ?? null;
    if ($blob === null || $blob === '') {
        http_response_code(404);
        exit;
    }

    $type = (string)($row['attachment_type'] ?? 'application/octet-stream');
    $name = (string)($row['attachment_original'] ?? 'attachment');

    header('Content-Type: ' . $type);
    header('Cache-Control: private, max-age=86400');
    header('X-Content-Type-Options: nosniff');

    // inline for images/video/pdf; attachment for everything else
    $inline = (strpos($type, 'image/') === 0) || (strpos($type, 'video/') === 0) || ($type === 'application/pdf');
    $disp = $inline ? 'inline' : 'attachment';
    header('Content-Disposition: ' . $disp . '; filename="' . str_replace('"', '', $name) . '"');

    echo $blob;
    exit;

} catch (Throwable $e) {
    http_response_code(500);
    exit;
}
