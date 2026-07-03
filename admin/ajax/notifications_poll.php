<?php
require_once __DIR__ . '/../includes/session_admin.php';
requireAdminLogin();

require_once __DIR__ . '/../includes/identity.php';
require_once __DIR__ . '/../controller.php';

header('Content-Type: application/json; charset=utf-8');
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");

try {
    $controller = new Controller();
    $dbh = $controller->pdo();

    $keys = myNotificationReceiverKeys();
    if (empty($keys)) {
        echo json_encode(['ok' => false, 'unread' => 0, 'error' => 'No receiver keys']);
        exit;
    }

    $ph = implode(',', array_fill(0, count($keys), '?'));

    $st = $dbh->prepare("
        SELECT COUNT(*)
        FROM notification
        WHERE notireceiver IN ($ph)
          AND is_read = 0
    ");
    $st->execute($keys);

    echo json_encode(['ok' => true, 'unread' => (int)$st->fetchColumn()]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'unread' => 0, 'error' => 'Server error']);
}
