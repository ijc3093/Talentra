<?php
require_once __DIR__ . '/../includes/session_user.php';
requireUserLogin();

require_once __DIR__ . '/../admin/controller.php';

header('Content-Type: application/json; charset=utf-8');

$email = $_SESSION['user_login'];

try {
    $controller = new Controller();
    $dbh = $controller->pdo();

    $stmt = $dbh->prepare("
        UPDATE notification
        SET is_read = 1, read_at = NOW()
        WHERE notireceiver = :email
          AND is_read = 0
    ");
    $stmt->execute([':email' => $email]);

    echo json_encode(['ok' => true, 'updated' => $stmt->rowCount()]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => 'Server error']);
}
