<?php
require_once __DIR__ . '/../includes/session_user.php';
requireUserLogin();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

require_once __DIR__ . '/../controller.php';
require_once __DIR__ . '/../includes/app_notification_api.php';

try {
    $controller = new Controller();
    $dbh = $controller->pdo();

    $email = $_SESSION['user_login'] ?? '';
    if ($email === '') {
        echo json_encode(['ok' => false, 'error' => 'Not logged in']);
        exit;
    }

    $stmt = $dbh->prepare("
        SELECT COUNT(*)
        FROM notification
        WHERE notireceiver = ?
          AND is_read = 0
          " . app_notification_social_exclude_sql() . "
    ");
    $stmt->execute(array_merge([$email], app_notification_social_exclude_patterns()));

    echo json_encode([
        'ok' => true,
        'unread' => (int)$stmt->fetchColumn()
    ]);
    exit;

} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => 'Server error']);
    exit;
}
