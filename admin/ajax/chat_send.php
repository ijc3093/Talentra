<?php
// /Business_only3/admin/ajax/chat_send.php
declare(strict_types=1);

require_once __DIR__ . '/../includes/session_admin.php';
requireAdminLogin();

require_once __DIR__ . '/../includes/identity.php';
require_once __DIR__ . '/../controller.php';

header('Content-Type: application/json; charset=utf-8');
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

$controller = new Controller();
$dbh = $controller->pdo();

$meUser = myUsername(); // internal sender is username
$meRole = myRoleId();

if ($meUser === '' || $meRole <= 0) {
    echo json_encode(['ok'=>false,'error'=>'Invalid session']);
    exit;
}

$peer = trim((string)($_POST['peer'] ?? ''));        // peer USERNAME (not email)
$text = trim((string)($_POST['message'] ?? ''));

if ($peer === '') {
    echo json_encode(['ok'=>false,'error'=>'Invalid peer']);
    exit;
}

$attachment = null;

// shared attachment folder: /Business_only3/attachment/
try {
    $folder = dirname(__DIR__, 2) . "/attachment/";
    if (!is_dir($folder)) mkdir($folder, 0755, true);

    if (!empty($_FILES['attachment']['name'])) {
        $file     = (string)$_FILES['attachment']['name'];
        $file_loc = (string)$_FILES['attachment']['tmp_name'];

        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $allowed = ['jpg','jpeg','png','pdf','doc','docx'];

        if (!in_array($ext, $allowed, true)) {
            echo json_encode(['ok'=>false,'error'=>'Invalid attachment type']);
            exit;
        }

        $base = preg_replace('/[^a-zA-Z0-9-_]/', '-', pathinfo($file, PATHINFO_FILENAME));
        $final = strtolower($base . '-' . time() . '.' . $ext);

        if (!move_uploaded_file($file_loc, $folder . $final)) {
            echo json_encode(['ok'=>false,'error'=>'Attachment upload failed']);
            exit;
        }

        $attachment = $final;
    }
} catch (Throwable $e) {
    echo json_encode(['ok'=>false,'error'=>'Attachment handling error']);
    exit;
}

if ($text === '' && !$attachment) {
    echo json_encode(['ok'=>false,'error'=>'Message cannot be empty']);
    exit;
}

// load peer role to compute correct channel
$st = $dbh->prepare("SELECT username, role, status FROM admin WHERE username = :u LIMIT 1");
$st->execute([':u' => $peer]);
$peerRow = $st->fetch(PDO::FETCH_ASSOC);

if (!$peerRow || (int)$peerRow['status'] !== 1) {
    echo json_encode(['ok'=>false,'error'=>'Peer not found/inactive']);
    exit;
}

$peerRole = (int)$peerRow['role'];
$channel  = channelForAdminRoles($meRole, $peerRole);

if ($channel === '') {
    echo json_encode(['ok'=>false,'error'=>'You cannot chat with this role']);
    exit;
}

try {
    $ins = $dbh->prepare("
        INSERT INTO feedback_admin (sender, receiver, channel, title, feedbackdata, attachment, is_read)
        VALUES (:s, :r, :ch, 'Internal Chat', :d, :a, 0)
    ");
    $ins->execute([
        ':s'  => $meUser,
        ':r'  => $peer,
        ':ch' => $channel,
        ':d'  => $text,
        ':a'  => $attachment
    ]);

    $id = (int)$dbh->lastInsertId();

    echo json_encode([
        'ok' => true,
        'message' => [
            'id' => $id,
            'sender' => $meUser,
            'receiver' => $peer,
            'channel' => $channel,
            'feedbackdata' => $text,
            'attachment' => $attachment,
            'created_at' => date('Y-m-d H:i:s')
        ]
    ]);
    exit;

} catch (Throwable $e) {
    echo json_encode(['ok'=>false,'error'=>'Database error']);
    exit;
}
