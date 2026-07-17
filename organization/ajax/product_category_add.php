<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/session_org.php';
require_once __DIR__ . '/../includes/org_context.php';
require_once __DIR__ . '/../includes/org_ecommerce.php';

$orgId = (int)orgActiveOrgId();
if ($orgId <= 0) {
    echo json_encode(['ok' => false, 'message' => 'Sign in required.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'message' => 'Invalid request.']);
    exit;
}

$name = trim((string)($_POST['name'] ?? ''));
$result = org_ecommerce_add_custom_category($dbh, $orgId, $name);

$brandCategories = [];
try {
    require_once __DIR__ . '/../../public_user/includes/org_commerce_brands.php';
    $commerceBrand = org_commerce_brands_get_for_org($dbh, $orgId);
    $brandSystem = $commerceBrand ? org_commerce_brands_parse_system($commerceBrand) : [];
    $brandCategories = is_array($brandSystem['menu_categories'] ?? null) ? $brandSystem['menu_categories'] : [];
} catch (Throwable $e) {
    $brandCategories = [];
}

$categories = !empty($result['ok'])
    ? org_ecommerce_product_category_options($dbh, $orgId, $brandCategories)
    : [];

echo json_encode([
    'ok' => !empty($result['ok']),
    'message' => !empty($result['ok'])
        ? 'Category saved for your shop only.'
        : (string)($result['error'] ?? 'Could not save category.'),
    'name' => (string)($result['name'] ?? $name),
    'categories' => array_values($categories),
]);
