<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/session_user.php';
requireUserLogin();
require_once __DIR__ . '/../controller.php';
require_once __DIR__ . '/../includes/profile_access.php';
require_once __DIR__ . '/../includes/profile_cover_slides.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$controller = new Controller();
$dbh = $controller->pdo();
$userId = profile_session_owner_user_id();
if ($userId <= 0) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Sign in required']);
    exit;
}

$action = strtolower(trim((string)($_POST['action'] ?? $_GET['action'] ?? 'list')));
if ($action === '' || $action === 'get') {
    $action = 'list';
}

if ($action === 'list') {
    echo json_encode(profile_cover_slides_payload($dbh, $userId), JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action !== 'delete') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Invalid action']);
    exit;
}

profile_require_edit_access($dbh, $userId);

$ids = $_POST['ids'] ?? $_POST['id'] ?? [];
if (!is_array($ids)) {
    $ids = [$ids];
}
profile_cover_slides_delete_many($dbh, $userId, $ids);
echo json_encode(profile_cover_slides_payload($dbh, $userId), JSON_UNESCAPED_SLASHES);
