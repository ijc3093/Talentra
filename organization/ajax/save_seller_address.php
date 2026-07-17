<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/session_org.php';
require_once __DIR__ . '/../includes/org_context.php';
require_once __DIR__ . '/../includes/org_manager_guard.php';
require_once __DIR__ . '/../includes/org_ecommerce.php';

header('Content-Type: application/json; charset=utf-8');

org_require_manager();
org_require_commerce_seller();

$orgId = (int)orgActiveOrgId();
if ($orgId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Invalid shop.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'POST required.']);
    exit;
}

$result = org_ecommerce_save_seller_address_from_post($dbh, $orgId, $_POST);
echo json_encode($result, JSON_UNESCAPED_UNICODE);
