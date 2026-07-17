<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/session_org.php';
require_once __DIR__ . '/../includes/org_context.php';
require_once __DIR__ . '/../../public_user/includes/org_product_type_schemas.php';

if ((int)orgActiveOrgId() <= 0) {
    echo json_encode(['ok' => false, 'message' => 'Sign in required.']);
    exit;
}

$sellingType = trim((string)($_GET['selling_type'] ?? ''));
$schema = org_product_type_schema_for_selling_type($sellingType);

if (!$schema) {
    echo json_encode(['ok' => true, 'slug' => '', 'label' => '', 'fields' => []]);
    exit;
}

echo json_encode([
    'ok' => true,
    'slug' => $schema['slug'],
    'label' => $schema['label'],
    'fields' => $schema['fields'],
    'selling_type' => $sellingType,
]);
