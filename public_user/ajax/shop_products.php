<?php
declare(strict_types=1);

require_once __DIR__ . '/../controller.php';
require_once __DIR__ . '/../includes/org_shop.php';
require_once __DIR__ . '/../includes/publisher_accounts.php';

header('Content-Type: application/json; charset=utf-8');

$controller = new Controller();
$dbh = $controller->pdo();

$publisherId = (int)($_GET['publisher_id'] ?? $_GET['user_id'] ?? 0);
$orgId = (int)($_GET['org_id'] ?? 0);

if ($publisherId > 0 && !publisher_is_publisher_user($dbh, $publisherId)) {
    echo json_encode(['ok' => false, 'products' => [], 'message' => 'Not a publisher profile.']);
    exit;
}

if ($orgId <= 0 && $publisherId > 0) {
    $orgId = org_shop_org_id_for_publisher($dbh, $publisherId);
}

if ($orgId <= 0) {
    echo json_encode(['ok' => false, 'products' => [], 'message' => 'Shop not found.']);
    exit;
}

if (!org_is_commerce_seller($dbh, $orgId)) {
    echo json_encode(['ok' => true, 'visible' => false, 'products' => [], 'message' => 'This publisher is not a commerce seller.']);
    exit;
}

if (!platform_rent_shop_is_visible($dbh, $orgId)) {
    echo json_encode(['ok' => true, 'visible' => false, 'products' => [], 'message' => 'Shop is temporarily unavailable.']);
    exit;
}

$rows = org_shop_list_products($dbh, $orgId, true);
$products = [];
foreach ($rows as $p) {
    $products[] = [
        'id' => (int)$p['id'],
        'title' => (string)$p['title'],
        'description' => (string)($p['description'] ?? ''),
        'price_cents' => (int)($p['price_cents'] ?? 0),
        'price_label' => org_shop_format_price((int)($p['price_cents'] ?? 0), (string)($p['currency'] ?? 'USD')),
        'currency' => (string)($p['currency'] ?? 'USD'),
        'stock_qty' => $p['stock_qty'] === null ? null : (int)$p['stock_qty'],
        'category' => (string)($p['category'] ?? ''),
        'cover_url' => org_shop_cover_url((string)($p['cover_image_path'] ?? '')),
    ];
}

echo json_encode([
    'ok' => true,
    'visible' => true,
    'org_id' => $orgId,
    'products' => $products,
]);
