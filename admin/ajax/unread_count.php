<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/session_admin.php';
requireAdminLogin();

require_once __DIR__ . '/../controller.php';

header('Content-Type: application/json; charset=utf-8');
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

$controller = new Controller();
$dbh = $controller->pdo();

/**
 * Admin identity
 */
$meEmail = trim($_SESSION['admin_email'] ?? '');
$meId    = (int)($_SESSION['admin_id'] ?? 0);

if ($meEmail === '' && $meId > 0) {
    $q = $dbh->prepare("SELECT email FROM admin WHERE idadmin = :id LIMIT 1");
    $q->execute([':id' => $meId]);
    $meEmail = trim((string)$q->fetchColumn());
}

if ($meEmail === '') {
    echo json_encode(['ok' => false]);
    exit;
}

/**
 * Count unread admin messages
 * (internal admin chat + user_admin support)
 */
$stmt = $dbh->prepare("
    SELECT COUNT(*) 
    FROM feedback_admin
    WHERE receiver = :me
      AND is_read = 0
      AND channel IN ('admin_internal', 'user_admin')
");
$stmt->execute([':me' => $meEmail]);

$count = (int)$stmt->fetchColumn();

echo json_encode([
    'ok' => true,
    'count' => $count
]);
