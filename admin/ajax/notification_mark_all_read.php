<?php
require_once __DIR__ . '/../includes/session_admin.php';
requireAdminLogin();

require_once __DIR__ . '/../controller.php';

header('Content-Type: application/json');

try {
    $controller = new Controller();
    $dbh = $controller->pdo();

    $receiver = 'Admin';

    $stmt = $dbh->prepare("
        UPDATE notification
        SET is_read = 1, read_at = NOW()
        WHERE notireceiver = :r AND is_read = 0
    ");
    $stmt->execute([':r' => $receiver]);

    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
