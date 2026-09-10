<?php
require_once __DIR__ . '/../includes/session_user.php';
requireUserLogin();

require_once __DIR__ . '/../controller.php';
require_once __DIR__ . '/../includes/app_notification_api.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

try {
    $controller = new Controller();
    $dbh = $controller->pdo();
    $uid = (int)($_SESSION['user_id'] ?? 0);
    $receivers = app_notification_receivers($dbh, $uid, [
        (string)($_SESSION['user_login'] ?? ''),
        (string)($_SESSION['user_email'] ?? ''),
    ]);
    if ($receivers === []) {
        echo json_encode(['ok' => false, 'error' => 'Missing session']);
        exit;
    }
    app_notification_mark($dbh, $receivers, 0, true);
    echo json_encode(['ok' => true, 'unread' => 0]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => 'Server error']);
}
