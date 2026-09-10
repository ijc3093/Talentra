<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/session_user.php';
require_once __DIR__ . '/controller.php';
require_once __DIR__ . '/includes/publisher_accounts.php';
require_once __DIR__ . '/includes/publisher_authority.php';

header('Content-Type: application/json; charset=UTF-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

$name = publisher_registry_normalize_name((string)($_POST['name'] ?? ''));
$category = strtolower(trim((string)($_POST['publisher_category'] ?? 'news')));
$authority = publisher_authority_payload_from_request($_POST);

$controller = new Controller();
$dbh = $controller->pdo();

$result = publisher_authority_submit_request($dbh, $name, $category, $authority);

if (empty($result['ok'])) {
    $messages = [
        'empty_name' => 'Enter a publisher name.',
        'name_too_short' => 'Publisher name is too short.',
        'already_registered' => 'That publisher name is already registered.',
        'authority_invalid' => 'Complete the request form before submitting.',
        'save_failed' => 'Unable to submit publisher name request right now.',
    ];
    $error = (string)($result['error'] ?? 'save_failed');
    $message = $messages[$error] ?? 'Unable to submit publisher name request.';
    if ($error === 'authority_invalid' && !empty($result['messages']) && is_array($result['messages'])) {
        $message = implode(' ', $result['messages']);
    }
    echo json_encode([
        'ok' => false,
        'error' => $error,
        'message' => $message,
        'messages' => $result['messages'] ?? [],
    ]);
    exit;
}

$status = (string)($result['status'] ?? 'pending');
$userMessage = $status === 'approved'
    ? 'Publisher name is approved — you can create your account.'
    : 'Request submitted. Waiting for admin approval before you can create your account.';

echo json_encode([
    'ok' => true,
    'name' => (string)($result['name'] ?? $name),
    'category' => (string)($result['category'] ?? $category),
    'source' => (string)($result['source'] ?? 'custom'),
    'status' => $status,
    'message' => $userMessage,
    'request_id' => (int)($result['request_id'] ?? 0),
]);
