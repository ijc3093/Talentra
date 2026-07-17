<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/session_user.php';
require_once __DIR__ . '/controller.php';
require_once __DIR__ . '/includes/publisher_accounts.php';
require_once __DIR__ . '/includes/publisher_authority.php';
require_once __DIR__ . '/includes/org_commerce_brands.php';

header('Content-Type: application/json; charset=UTF-8');

$brandName = publisher_registry_normalize_name((string)($_GET['name'] ?? ''));
$email = strtolower(trim((string)($_GET['email'] ?? '')));

if ($brandName === '' || $email === '') {
    echo json_encode(['ok' => false, 'error' => 'missing_params']);
    exit;
}

$controller = new Controller();
$dbh = $controller->pdo();

$meta = publisher_authority_commerce_brand_name_request_meta($dbh, $brandName, $email);
$status = (string)($meta['status'] ?? 'none');
$brandId = (int)($meta['brand_id'] ?? 0);
$brand = $brandId > 0 ? org_commerce_brands_get($dbh, $brandId) : null;

echo json_encode([
    'ok' => true,
    'name' => $brandName,
    'brand_id' => $brandId,
    'brand_name' => (string)($brand['name'] ?? $brandName),
    'email' => $email,
    'status' => $status,
    'approved' => $status === 'approved',
]);
