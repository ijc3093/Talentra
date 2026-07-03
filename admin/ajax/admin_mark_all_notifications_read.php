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
    // ✅ Receiver keys based on role (Admin sees all; Manager only Manager; Staff only Staff)
    $receiverKeys = myNotificationReceiverKeys();

    // ✅ Hard safety: only allow exact role labels (prevents emails/junk/injection)
    $allowedReceivers = ['Admin', 'Manager', 'Gospel', 'Staff'];
    $receiverKeys = array_values(array_intersect($receiverKeys, $allowedReceivers));

    if (empty($receiverKeys)) {
        echo json_encode(['ok' => false, 'error' => 'Invalid receiver keys']);
        exit;
    }

    $controller = new Controller();
    $dbh = $controller->pdo();

    $ph = implode(',', array_fill(0, count($receiverKeys), '?'));

    // ✅ Update only notifications visible to THIS role
    $st = $dbh->prepare("
        UPDATE notification
        SET is_read = 1
        WHERE notireceiver IN ($ph)
          AND is_read = 0
    ");
    $st->execute($receiverKeys);

    echo json_encode(['ok' => true, 'updated' => (int)$st->rowCount()]);
} catch (Throwable $e) {
    // optionally log $e->getMessage()
    echo json_encode(['ok' => false, 'error' => 'Server error']);
}
