<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/session_user.php';
require_once __DIR__ . '/../controller.php';
require_once __DIR__ . '/../includes/org_shop.php';

header('Content-Type: application/json; charset=utf-8');

$controller = new Controller();
$dbh = $controller->pdo();
$meId = (int)($_SESSION['user_id'] ?? $_SESSION['id'] ?? $_SESSION['userid'] ?? 0);

if ($meId <= 0) {
    echo json_encode(['ok' => false, 'message' => 'Please sign in.']);
    exit;
}

$orderId = (int)($_POST['order_id'] ?? 0);
$rating = (int)($_POST['rating'] ?? 5);
$reviewText = trim((string)($_POST['review_text'] ?? ''));

$result = org_shop_submit_review($dbh, $orderId, $meId, $rating, $reviewText);
echo json_encode([
    'ok' => !empty($result['ok']),
    'message' => !empty($result['ok']) ? 'Thank you for your review.' : (string)($result['error'] ?? 'Could not save review.'),
]);
