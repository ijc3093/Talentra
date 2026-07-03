<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/session_user.php';
require_once __DIR__ . '/controller.php';
require_once __DIR__ . '/includes/publisher_accounts.php';
require_once __DIR__ . '/includes/publisher_authority.php';

header('Content-Type: application/json; charset=UTF-8');

$name = publisher_registry_normalize_name((string)($_GET['name'] ?? ''));
if ($name === '') {
    echo json_encode(['ok' => false, 'error' => 'empty_name']);
    exit;
}

$controller = new Controller();
$dbh = $controller->pdo();

$status = publisher_authority_request_status($dbh, $name);
$approved = $status === 'approved';

echo json_encode([
    'ok' => true,
    'name' => $name,
    'status' => $status,
    'approved' => $approved,
    'requires_approval' => publisher_registry_requires_authority($dbh, $name),
]);
