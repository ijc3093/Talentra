<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/session_user.php';
require_once __DIR__ . '/../controller.php';
require_once __DIR__ . '/../includes/org_wishlist.php';

header('Content-Type: application/json; charset=utf-8');

$controller = new Controller();
$dbh = $controller->pdo();
$meId = (int)($_SESSION['user_id'] ?? $_SESSION['id'] ?? $_SESSION['userid'] ?? 0);

if ($meId <= 0) {
    echo json_encode(['ok' => false, 'message' => 'Please sign in to use wishlist.']);
    exit;
}

$action = strtolower(trim((string)($_POST['action'] ?? $_GET['action'] ?? 'toggle')));
$productId = (int)($_POST['product_id'] ?? $_GET['product_id'] ?? 0);

if ($productId <= 0) {
    echo json_encode(['ok' => false, 'message' => 'Missing product.']);
    exit;
}

if ($action === 'add') {
    $res = org_wishlist_add($dbh, $meId, $productId);
} elseif ($action === 'remove') {
    $res = org_wishlist_remove($dbh, $meId, $productId);
} else {
    $res = org_wishlist_toggle($dbh, $meId, $productId);
}

echo json_encode([
    'ok' => !empty($res['ok']),
    'saved' => !empty($res['saved']),
    'message' => (string)(($res['ok'] ?? false) ? ($res['message'] ?? 'Updated.') : ($res['error'] ?? 'Failed.')),
    'count' => org_wishlist_count($dbh, $meId),
]);
