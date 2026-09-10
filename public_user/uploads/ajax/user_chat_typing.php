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
$typing = (int)($_GET['typing'] ?? 0);

$meCode = userFriendCode();
if ($meCode === '' || $peer === '') {
    echo json_encode(['ok'=>false]);
    exit;
}

try {
    $controller = new Controller();
    $dbh = $controller->pdo();

    // Create table once (see SQL below). If already exists, it works.
    $st = $dbh->prepare("
        INSERT INTO chat_typing (sender_code, receiver_code, is_typing, updated_at)
        VALUES (:s,:r,:t,NOW())
        ON DUPLICATE KEY UPDATE is_typing=:t2, updated_at=NOW()
    ");
    $st->execute([
        ':s'=>$meCode,
        ':r'=>$peer,
        ':t'=>$typing ? 1 : 0,
        ':t2'=>$typing ? 1 : 0,
    ]);

    echo json_encode(['ok'=>true]);
} catch (Throwable $e) {
    echo json_encode(['ok'=>false]);
}
