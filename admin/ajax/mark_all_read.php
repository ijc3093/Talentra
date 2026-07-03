<?php
require_once __DIR__ . '/../includes/session_admin.php';
requireAdminLogin();

require_once __DIR__ . '/../controller.php';
require_once __DIR__ . '/../includes/identity.php';

header('Content-Type: application/json; charset=utf-8');
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");

try {
    $controller = new Controller();
    $dbh = $controller->pdo();

    $me   = myUsername();
    $role = myRoleId();

    if ($me === '') {
        echo json_encode(['ok' => false, 'error' => 'Missing session username']);
        exit;
    }

    // Admin may have legacy rows saved under 'Admin' + new rows saved under username
    $receiverKeys = ($role === 1) ? ['Admin', $me] : [$me];
    $ph = implode(',', array_fill(0, count($receiverKeys), '?'));

    $sql = "
        UPDATE notification
        SET is_read = 1, read_at = NOW()
        WHERE notireceiver IN ($ph)
          AND is_read = 0
    ";

    $stmt = $dbh->prepare($sql);
    $stmt->execute($receiverKeys);

    $updated = (int)$stmt->rowCount();

    if ($updated === 0) {
        echo json_encode([
            'ok' => false,
            'error' => 'No rows updated (already read OR receiver mismatch).',
            'debug' => ['keys' => $receiverKeys]
        ]);
        exit;
    }

    echo json_encode(['ok' => true, 'updated' => $updated]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => 'Server error']);
}
