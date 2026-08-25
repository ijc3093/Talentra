<?php
// /Business_only3/ajax/user_chat_typing_poll.php
declare(strict_types=1);

require_once __DIR__ . '/../includes/session_user.php';
requireUserLogin();

require_once __DIR__ . '/../controller.php';

header('Content-Type: application/json; charset=utf-8');
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

$meCode = function_exists('userFriendCode') ? userFriendCode() : trim((string)($_SESSION['user_friend_code'] ?? ''));
$meCode = trim($meCode);

$peer = trim((string)($_GET['peer'] ?? ''));

if ($meCode === '' || $peer === '') {
    echo json_encode(['ok'=>false]);
    exit;
}

if (!preg_match('/^[A-Z]{3}-[A-Z0-9]{4}-[A-Z0-9]{4}$/i', $peer)) {
    echo json_encode(['ok'=>false]);
    exit;
}

try {
    $controller = new Controller();
    $dbh = $controller->pdo();

    // typing is true if peer updated within last 2 seconds and is_typing=1
    // NOTE: older schema variants used last_typing_at; keep compatibility.
    $q = $dbh->prepare("
        SELECT is_typing, updated_at, last_typing_at
        FROM chat_typing
        WHERE sender_code = :peer
          AND receiver_code = :me
        ORDER BY COALESCE(updated_at, last_typing_at) DESC
        LIMIT 1
    ");
    $q->execute([':peer'=>$peer, ':me'=>$meCode]);
    $row = $q->fetch(PDO::FETCH_ASSOC);

    $typing = false;
    if ($row) {
        $is = (int)($row['is_typing'] ?? 0);
        $ts = 0;
        if (!empty($row['updated_at'])) {
            $ts = strtotime((string)$row['updated_at']) ?: 0;
        } elseif (!empty($row['last_typing_at'])) {
            $ts = strtotime((string)$row['last_typing_at']) ?: 0;
        }
        if ($is === 1 && $ts > 0 && (time() - $ts) <= 2) $typing = true;
    }

    echo json_encode(['ok'=>true,'typing'=>$typing]);
    exit;

} catch (Throwable $e) {
    echo json_encode(['ok'=>false,'typing'=>false]);
    exit;
}
