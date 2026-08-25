<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/session_user.php';
requireUserLogin();
require_once __DIR__ . '/../controller.php';
require_once __DIR__ . '/../includes/profile_access.php';
require_once __DIR__ . '/../includes/profile_cover_slides.php';

header('Content-Type: application/json; charset=utf-8');

$controller = new Controller();
$dbh = $controller->pdo();
$userId = profile_session_owner_user_id();
profile_require_edit_access($dbh, $userId);

$action = strtolower(trim((string)($_POST['action'] ?? $_GET['action'] ?? '')));
if ($action === 'list') {
    echo json_encode([
        'ok' => true,
        'slides' => profile_cover_slides_for_user($dbh, $userId, ''),
    ]);
    exit;
}
if ($action !== 'delete') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Invalid action']);
    exit;
}

$ids = $_POST['ids'] ?? $_POST['id'] ?? [];
if (!is_array($ids)) {
    $ids = [$ids];
}
$slides = profile_cover_slides_delete_many($dbh, $userId, $ids);
echo json_encode([
    'ok' => true,
    'slides' => $slides,
]);
