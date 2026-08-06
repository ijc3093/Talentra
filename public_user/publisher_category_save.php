<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/session_user.php';
require_once __DIR__ . '/controller.php';
require_once __DIR__ . '/includes/publisher_accounts.php';

header('Content-Type: application/json; charset=UTF-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed', 'message' => 'POST required.']);
    exit;
}

$label = (string)($_POST['label'] ?? $_POST['category'] ?? '');
$controller = new Controller();
$dbh = $controller->pdo();
$createdBy = (int)($_SESSION['user_id'] ?? 0);

$result = publisher_category_add($dbh, $label, $createdBy > 0 ? $createdBy : null);

if (empty($result['ok'])) {
    echo json_encode([
        'ok' => false,
        'error' => (string)($result['error'] ?? 'save_failed'),
        'message' => (string)($result['message'] ?? 'Unable to add that category.'),
    ]);
    exit;
}

echo json_encode([
    'ok' => true,
    'slug' => (string)($result['slug'] ?? ''),
    'label' => (string)($result['label'] ?? $label),
    'existing' => !empty($result['existing']),
    'message' => !empty($result['existing'])
        ? 'That category already exists — selected for you.'
        : 'Category added. Your posts will appear under this tab on public.php.',
]);
