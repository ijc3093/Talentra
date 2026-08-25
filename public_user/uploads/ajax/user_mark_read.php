<?php
// /Business_only3/ajax/user_mark_read.php
require_once __DIR__ . '/../includes/session_user.php';
requireUserLogin();

require_once __DIR__ . '/../controller.php';

header('Content-Type: application/json; charset=utf-8');
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");

try {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['ok'=>false,'error'=>'Missing id']);
        exit;
    }

    $receivers = array_values(array_unique(array_filter([
        trim((string)($_SESSION['user_login'] ?? '')),
        trim((string)($_SESSION['user_email'] ?? '')),
    ], static function ($value) {
        return $value !== '';
    })));
    if (empty($receivers)) {
        echo json_encode(['ok'=>false,'error'=>'Missing session']);
        exit;
    }

    $controller = new Controller();
    $dbh = $controller->pdo();
    $receiverPh = implode(',', array_fill(0, count($receivers), '?'));

    $st = $dbh->prepare("
        UPDATE notification
        SET is_read = 1
        WHERE id = ?
          AND notireceiver IN ($receiverPh)
          AND notitype NOT LIKE ?
          AND notitype NOT LIKE ?
          AND notitype NOT LIKE ?
        LIMIT 1
    ");

    $st->execute(array_merge([$id], $receivers, ['New chat message%', 'Internal Chat%', 'New internal message%']));

    echo json_encode(['ok'=>true]);
} catch (Throwable $e) {
    echo json_encode(['ok'=>false,'error'=>'Server error']);
}
