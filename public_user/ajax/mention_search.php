<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/session_user.php';
requireUserLogin();
require_once __DIR__ . '/../controller.php';
require_once __DIR__ . '/../includes/post_tags.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$controller = new Controller();
$dbh = $controller->pdo();

$meId = (int)($_SESSION['user_id'] ?? 0);
if ($meId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'auth', 'users' => []]);
    exit;
}

$q = trim((string)($_GET['q'] ?? $_POST['q'] ?? ''));
$limit = (int)($_GET['limit'] ?? $_POST['limit'] ?? 8);

try {
    $users = msb_mention_search_users($dbh, $meId, $q, $limit);
    echo json_encode(['ok' => true, 'users' => $users], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => 'server', 'users' => []]);
}
