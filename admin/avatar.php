<?php
// /Business_only3/admin/includes/avatar.php
declare(strict_types=1);

require_once __DIR__ . '/session_admin.php';
requireAdminLogin();

require_once __DIR__ . '/../controller.php';

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

$controller = new Controller();
$dbh = $controller->pdo();

$adminId = (int)($_SESSION['admin_id'] ?? 0);
if ($adminId <= 0) {
    http_response_code(404);
    exit;
}

$stmt = $dbh->prepare("
    SELECT image_blob, image_type
    FROM admin
    WHERE idadmin = :id
    LIMIT 1
");
$stmt->execute([':id' => $adminId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row || empty($row['image_blob'])) {
    $default = __DIR__ . '/../../images/default.jpg';
    header("Content-Type: image/jpeg");
    if (is_file($default)) readfile($default);
    exit;
}

$type = !empty($row['image_type']) ? (string)$row['image_type'] : 'image/jpeg';
header("Content-Type: " . $type);
echo $row['image_blob'];
exit;
