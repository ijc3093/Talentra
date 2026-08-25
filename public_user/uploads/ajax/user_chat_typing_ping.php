<?php
declare(strict_types=1);
error_reporting(0);

require_once __DIR__ . '/../includes/session_user.php';
requireUserLogin();

require_once __DIR__ . '/../controller.php';

header('Content-Type: application/json; charset=utf-8');
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

$peer = strtoupper(trim((string)($_GET['peer'] ?? '')));
$meCode = userFriendCode();

if ($meCode === '' || $peer === '') {
    echo json_encode(['ok'=>false]);
    exit;
}

try {
    $controller = new Controller();
    $dbh = $controller->pdo();

    // typing is true if peer updated within last 2 seconds and is_typing=1
    $st = $dbh->prepare("
        SELECT ct.is_typing, ct.updated_at,
               COALESCE(NULLIF(u.name,''), NULLIF(u.username,''), u.friend_code) AS peer_name
        FROM chat_typing ct
        JOIN users u ON u.friend_code = ct.sender_code
        WHERE ct.sender_code = :peer AND ct.receiver_code = :me
        ORDER BY ct.updated_at DESC
        LIMIT 1
    ");
    $st->execute([':peer'=>$peer, ':me'=>$meCode]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    $typing = false;
    $peerName = '';

    if ($row) {
        $peerName = (string)($row['peer_name'] ?? '');
        $is = (int)($row['is_typing'] ?? 0);
        $ts = strtotime((string)($row['updated_at'] ?? '')) ?: 0;
        if ($is === 1 && $ts > 0 && (time() - $ts) <= 2) $typing = true;
    }

    echo json_encode(['ok'=>true,'typing'=>$typing,'peer_name'=>$peerName]);
} catch (Throwable $e) {
    echo json_encode(['ok'=>false]);
}
