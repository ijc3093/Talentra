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
$result = org_ecommerce_add_custom_selling_type($dbh, $orgId, $name);

echo json_encode([
    'ok' => !empty($result['ok']),
    'message' => !empty($result['ok'])
        ? 'Saved for your shop only.'
        : (string)($result['error'] ?? 'Could not save.'),
    'name' => (string)($result['name'] ?? $name),
    'selling_types' => array_values($result['selling_types'] ?? []),
    'categories' => array_values($result['categories'] ?? []),
]);
