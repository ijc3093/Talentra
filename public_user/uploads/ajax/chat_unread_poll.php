<?php
require_once __DIR__ . '/../includes/session_user.php';
requireUserLogin();

header('Content-Type: application/json; charset=utf-8');
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");

require_once __DIR__ . '/../controller.php';

try {
    $controller = new Controller();
    $dbh = $controller->pdo();

    $email = $_SESSION['user_login'];

    $stmt = $dbh->prepare("
        SELECT COUNT(*)
        FROM feedback
        WHERE receiver = :me
          AND sender = 'Admin'
          AND is_read = 0
    ");
    $stmt->execute([':me' => $email]);

    echo json_encode([
        'ok' => true,
        'unread' => (int)$stmt->fetchColumn()
    ]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => 'Server error']);
}
