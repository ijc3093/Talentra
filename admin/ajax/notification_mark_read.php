<?php
require_once __DIR__ . '/../includes/session_admin.php';
requireAdminLogin();

require_once __DIR__ . '/../controller.php';

header('Content-Type: application/json');

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Invalid id']);
    exit;
}

$controller = new Controller();
$dbh = $controller->pdo();

$receiver = 'Admin';

$stmt = $dbh->prepare("
    UPDATE notification
    SET is_read = 1, read_at = NOW()
    WHERE id = :id AND notireceiver = :r
");
$stmt->execute([':id' => $id, ':r' => $receiver]);

echo json_encode(['ok' => true]);
