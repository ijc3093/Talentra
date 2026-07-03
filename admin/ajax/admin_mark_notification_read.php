<?php
require_once __DIR__ . '/../includes/session_admin.php';
requireAdminLogin();

require_once __DIR__ . '/../includes/identity.php';
require_once __DIR__ . '/../controller.php';

header('Content-Type: application/json; charset=utf-8');
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");

error_reporting(E_ALL);
ini_set('display_errors', 1);

try {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['ok' => false, 'error' => 'Missing id']);
        exit;
    }

    // ✅ Receiver keys based on current role
    $receiverKeys = myNotificationReceiverKeys();

    // ✅ Hard safety: only allow exact role labels (prevents injection / emails / junk)
    $allowedReceivers = ['Admin', 'Manager', 'Gospel', 'Staff'];
    $receiverKeys = array_values(array_intersect($receiverKeys, $allowedReceivers));

    if (empty($receiverKeys)) {
        echo json_encode(['ok' => false, 'error' => 'Invalid receiver keys']);
        exit;
    }

    $controller = new Controller();
    $dbh = $controller->pdo();

    // Build placeholders for IN (...)
    $ph = implode(',', array_fill(0, count($receiverKeys), '?'));

    // ✅ Update only notifications visible to THIS role
    $st = $dbh->prepare("
        UPDATE notification
        SET is_read = 1
        WHERE id = ?
          AND notireceiver IN ($ph)
        LIMIT 1
    ");

    $st->execute(array_merge([$id], $receiverKeys));

    // ✅ If rowCount = 0, either:
    // - invalid id, OR
    // - user tried to mark someone else's notification
    if ($st->rowCount() < 1) {
        echo json_encode(['ok' => false, 'error' => 'Not allowed']);
        exit;
    }

    echo json_encode(['ok' => true]);

} catch (Throwable $e) {
    // optionally log $e->getMessage() to a file
    echo json_encode(['ok' => false, 'error' => 'Server error']);
}
