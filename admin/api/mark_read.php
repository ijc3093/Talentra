<?php

require_once __DIR__ . '/../includes/session_user.php';
requireUserLogin();

require_once __DIR__ . '/../admin/controller.php';

header('Content-Type: application/json; charset=utf-8');

$id = (int)($_POST['id'] ?? 0);
$email = $_SESSION['user_login'];

if ($id <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Invalid id']);
    exit;
}

try {
    $controller = new Controller();
    $dbh = $controller->pdo();

    $stmt = $dbh->prepare("
        UPDATE notification
        SET is_read = 1, read_at = NOW()
        WHERE id = :id
          AND notireceiver = :email
    ");
    $stmt->execute([
        ':id' => $id,
        ':email' => $email
    ]);

    echo json_encode(['ok' => true, 'updated' => $stmt->rowCount()]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => 'Server error']);
}
