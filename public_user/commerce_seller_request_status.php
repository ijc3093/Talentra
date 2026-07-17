<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/session_user.php';
require_once __DIR__ . '/controller.php';
require_once __DIR__ . '/includes/publisher_accounts.php';
require_once __DIR__ . '/includes/publisher_authority.php';
require_once __DIR__ . '/includes/org_commerce_brands.php';

header('Content-Type: application/json; charset=UTF-8');

$brandId = (int)($_GET['commerce_brand_id'] ?? $_GET['brand_id'] ?? 0);
$email = strtolower(trim((string)($_GET['email'] ?? '')));

if ($brandId <= 0 || $email === '') {
    echo json_encode(['ok' => false, 'error' => 'missing_params']);
    exit;
}

$controller = new Controller();
$dbh = $controller->pdo();

$status = publisher_authority_commerce_request_status($dbh, $brandId, $email);
$brand = org_commerce_brands_get($dbh, $brandId);

echo json_encode([
    'ok' => true,
    'brand_id' => $brandId,
    'brand_name' => (string)($brand['name'] ?? ''),
    'email' => $email,
    'status' => $status,
    'approved' => $status === 'approved',
]);
