<?php
require_once __DIR__ . '/../includes/session_admin.php';
requireAdminLogin();

require_once __DIR__ . '/../controller.php';
require_once __DIR__ . '/../includes/identity.php';

header('Content-Type: application/json; charset=utf-8');
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Invalid id']);
    exit;
}

try {
    $controller = new Controller();
    $dbh = $controller->pdo();

    $me   = myUsername();   // username in session
    $role = myRoleId();     // 1=Admin, 2=Manager, 3=Gospel, 4=Staff

    if ($me === '') {
        echo json_encode(['ok' => false, 'error' => 'Missing session username']);
        exit;
    }

    // ✅ IMPORTANT:
    // If your notifications are stored per-username, then everyone uses $me.
    // Admin may ALSO have old notifications stored under 'Admin' (legacy).
    $receiverKeys = ($role === 1) ? ['Admin', $me] : [$me];
    $ph = implode(',', array_fill(0, count($receiverKeys), '?'));

    $sql = "
        UPDATE notification
        SET is_read = 1, read_at = NOW()
        WHERE id = ?
          AND notireceiver IN ($ph)
          AND is_read = 0
    ";

    $stmt = $dbh->prepare($sql);
    $stmt->execute(array_merge([$id], $receiverKeys));

    $updated = (int)$stmt->rowCount();

    if ($updated === 0) {
        // Nothing updated: either already read, or receiver mismatch, or wrong id
        echo json_encode([
            'ok' => false,
            'error' => 'Not updated. Either already read or not your notification.',
            'debug' => ['id' => $id, 'keys' => $receiverKeys]
        ]);
        exit;
    }

    echo json_encode(['ok' => true, 'updated' => $updated]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => 'Server error']);
}
