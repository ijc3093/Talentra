<?php
// /Business_only3/admin/ajax/chat_poll.php
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

/**
 * feedback_admin primary key column is not always named `id` in your database.
 * This helper detects the real PK column so our queries work on any schema.
 */
function feedback_admin_id_col(PDO $dbh): string {
    static $col = null;
    if ($col) return $col;

    // 1) Try PRIMARY KEY
    try {
        $st = $dbh->query("SHOW KEYS FROM feedback_admin WHERE Key_name = 'PRIMARY'");
        $row = $st ? $st->fetch(PDO::FETCH_ASSOC) : false;
        if ($row && !empty($row['Column_name'])) {
            $col = (string)$row['Column_name'];
            return $col;
        }
    } catch (Throwable $e) { /* ignore */ }

    // 2) Fallback: common id-like names
    $candidates = ['id_feedback_admin','feedback_id','idfeedback','id_feedback','idfeedback_admin','feedback_admin_id'];
    try {
        $st = $dbh->query("SHOW COLUMNS FROM feedback_admin");
        $cols = $st ? $st->fetchAll(PDO::FETCH_COLUMN, 0) : [];
        foreach ($candidates as $c) {
            if (in_array($c, $cols, true)) { $col = $c; return $col; }
        }
        // last resort: first column
        if (!empty($cols[0])) { $col = (string)$cols[0]; return $col; }
    } catch (Throwable $e) { /* ignore */ }

    $col = 'id_feedback_admin';
    return $col;
}

$meUser = myUsername();
$meRole = myRoleId();

if ($meUser === '' || $meRole <= 0) {
    echo json_encode(['ok'=>false,'error'=>'Invalid session']);
    exit;
}

$peer   = trim((string)($_GET['peer'] ?? ''));   // peer USERNAME
$lastId = (int)($_GET['last_id'] ?? 0);

if ($peer === '') {
    echo json_encode(['ok'=>false,'error'=>'Invalid peer']);
    exit;
}

// peer role => channel
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
    echo json_encode(['ok'=>false,'error'=>'Channel not allowed']);
    exit;
}

try {
    // mark unread peer->me as read
    $mk = $dbh->prepare("
        UPDATE feedback_admin
        SET is_read = 1, read_at = NOW()
        WHERE channel = :ch
          AND sender = :peer
          AND receiver = :me
          AND is_read = 0
    ");
    $mk->execute([':ch'=>$channel, ':peer'=>$peer, ':me'=>$meUser]);

    // fetch new messages
    $q = $dbh->prepare("
        SELECT id_feedback_admin AS id, sender, receiver, channel, feedbackdata, attachment, created_at
        FROM feedback_admin
        WHERE channel = :ch
          AND (
                (sender = :me AND receiver = :peer)
             OR (sender = :peer2 AND receiver = :me2)
          )
          AND id > :lastId
        ORDER BY id ASC
        LIMIT 200
    ");
    $q->execute([
        ':ch' => $channel,
        ':me' => $meUser,
        ':peer' => $peer,
        ':peer2' => $peer,
        ':me2' => $meUser,
        ':lastId' => $lastId
    ]);

    echo json_encode(['ok'=>true, 'messages'=>$q->fetchAll(PDO::FETCH_ASSOC)]);
    exit;

} catch (Throwable $e) {
    echo json_encode(['ok'=>false,'error'=>'Server error']);
    exit;
}