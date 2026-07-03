<?php

require_once __DIR__ . '/../includes/session_user.php';
requireUserLogin();

header('Content-Type: application/json');
header('Cache-Control: no-store');

require_once __DIR__ . '/../admin/controller.php';
$controller = new Controller();
$dbh = $controller->pdo();

$email = $_SESSION['user_login'];

try {
    // Mark all unread messages sent by Admin to this user as read
    $stmt = $dbh->prepare("
        UPDATE feedback_admin
        SET is_read = 1, read_at = NOW()
        WHERE sender = 'Admin' AND receiver = :email AND is_read = 0
    ");
    $stmt->execute([':email' => $email]);

    echo json_encode(['ok' => true, 'updated' => $stmt->rowCount()]);
} catch (PDOException $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
