<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/session_user.php';
require_once __DIR__ . '/controller.php';
require_once __DIR__ . '/includes/publisher_accounts.php';
require_once __DIR__ . '/includes/publisher_authority.php';

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

$brandName = publisher_registry_normalize_name((string)($_POST['name'] ?? ''));
$username = trim((string)($_POST['username'] ?? ''));
$email = strtolower(trim((string)($_POST['email'] ?? '')));
$authority = publisher_authority_payload_from_request($_POST);

$controller = new Controller();
$dbh = $controller->pdo();

$result = publisher_authority_submit_commerce_brand_name_request($dbh, $brandName, $username, $email, $authority);

if (empty($result['ok'])) {
    $message = 'Unable to submit commerce brand request.';
    if (!empty($result['messages']) && is_array($result['messages'])) {
        $message = (string)$result['messages'][0];
    } elseif (($result['error'] ?? '') === 'already_exists') {
        $message = 'That brand is already in the list. Choose it from Commerce brand system instead.';
    } elseif (($result['error'] ?? '') === 'already_registered') {
        $message = 'That email or username is already registered.';
    }
    echo json_encode([
        'ok' => false,
        'error' => (string)($result['error'] ?? 'save_failed'),
        'message' => $message,
    ]);
    exit;
}

echo json_encode([
    'ok' => true,
    'name' => (string)($result['name'] ?? $brandName),
    'brand_id' => (int)($result['brand_id'] ?? 0),
    'status' => (string)($result['status'] ?? 'pending'),
    'approved' => ((string)($result['status'] ?? '') === 'approved'),
]);
