<?php
require_once __DIR__ . '/../includes/session_user.php';
requireUserLogin();

require_once __DIR__ . '/../controller.php';
require_once __DIR__ . '/../includes/profile_access.php';
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
    $all = in_array(strtolower(trim((string)($_GET['all'] ?? ''))), ['1', 'true', 'yes'], true);
    echo json_encode(
        app_notification_fetch($dbh, $receivers, $uid, !$all, $all ? 200 : 20),
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'unread' => 0, 'error' => 'Server error']);
}
