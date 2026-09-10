<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/session_user.php';
requireUserLogin();

require_once __DIR__ . '/../controller.php';

header('Content-Type: application/json; charset=utf-8');
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

$controller = new Controller();
$dbh = $controller->pdo();

$meEmail = myUserEmail(); // ✅ correct session key: user_login
$peer    = trim((string)($_GET['peer'] ?? ''));
$lastId  = (int)($_GET['last_id'] ?? 0);

if ($meEmail === '' || !filter_var($meEmail, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['ok' => false, 'error' => 'Missing/invalid session identity']);
    exit;
}
if ($peer === '' || !filter_var($peer, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['ok' => false, 'error' => 'Invalid peer email']);
    exit;
}

try {
    // Mark unread from peer -> me as read
    $mk = $dbh->prepare("
        UPDATE feedback
        SET is_read = 1, read_at = NOW()
        WHERE channel = 'user_user'
          AND sender = :peer
          AND receiver = :me
          AND is_read = 0
    ");
    $mk->execute([':peer' => $peer, ':me' => $meEmail]);

    // Fetch only new messages
    $st = $dbh->prepare("
        SELECT id, sender, receiver, feedbackdata, attachment, created_at
        FROM feedback
        WHERE channel = 'user_user'
          AND (
                (sender = :me AND receiver = :peer)
             OR (sender = :peer2 AND receiver = :me2)
          )
          AND id > :lastId
        ORDER BY id ASC
        LIMIT 200
    ");
    $st->execute([
        ':me' => $meEmail,
        ':peer' => $peer,
        ':peer2' => $peer,
        ':me2' => $meEmail,
        ':lastId' => $lastId
    ]);

    echo json_encode(['ok' => true, 'messages' => $st->fetchAll(PDO::FETCH_ASSOC)]);
    exit;

} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => 'Server error']);
    exit;
}
