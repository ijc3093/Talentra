<?php
declare(strict_types=1);

/**
 * Shop → Notifications opened: mark the buyer's shop commerce alerts read.
 * Social bell rows (notifications.php) are never touched here.
 */
require_once __DIR__ . '/../includes/session_user.php';
require_once __DIR__ . '/../controller.php';
require_once __DIR__ . '/../includes/org_shop.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$meId = (int)($_SESSION['user_id'] ?? $_SESSION['id'] ?? $_SESSION['userid'] ?? 0);
if ($meId <= 0) {
    $remote = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $isLoopback = in_array($remote, ['127.0.0.1', '::1'], true) || str_starts_with($remote, '127.');
    if ($isLoopback) {
        $meId = (int)($_SERVER['HTTP_X_SHOP_USER_ID'] ?? $_POST['user_id'] ?? $_GET['user_id'] ?? 0);
    }
}
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}
if ($meId <= 0) {
    echo json_encode(['ok' => false, 'message' => 'Please sign in.']);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    echo json_encode(['ok' => false, 'message' => 'POST required.']);
    exit;
}

try {
    $dbh = (new Controller())->pdo();
    $marked = org_shop_mark_commerce_inbox_read($dbh, $meId);
    echo json_encode(['ok' => true, 'marked' => $marked]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'message' => 'Server error']);
}
