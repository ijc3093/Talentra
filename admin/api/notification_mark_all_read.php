<?php

require_once __DIR__ . '/../includes/session_user.php';
requireUserLogin();

require_once __DIR__ . '/../admin/controller.php';

header('Content-Type: application/json');

try {
    $controller = new Controller();
    $dbh = $controller->pdo();

    $email = $_SESSION['user_login'];

    $stmt = $dbh->prepare("
        UPDATE notification
        SET is_read = 1, read_at = NOW()
        WHERE notireceiver = :email AND is_read = 0
    ");
    $stmt->execute([':email' => $email]);

    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
