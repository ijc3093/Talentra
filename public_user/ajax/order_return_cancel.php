<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/session_user.php';
require_once __DIR__ . '/../controller.php';
require_once __DIR__ . '/../includes/org_shop.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$meId = (int)($_SESSION['user_id'] ?? $_SESSION['id'] ?? $_SESSION['userid'] ?? 0);
if ($meId <= 0) {
    echo json_encode(['ok' => false, 'message' => 'Please sign in.']);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    echo json_encode(['ok' => false, 'message' => 'POST required.']);
    exit;
}

$dbh = (new Controller())->pdo();
$returnId = (int)($_POST['return_id'] ?? 0);
$result = org_shop_buyer_cancel_return($dbh, $returnId, $meId);
echo json_encode([
    'ok' => !empty($result['ok']),
    'message' => !empty($result['ok']) ? 'Return request cancelled.' : (string)($result['error'] ?? 'Could not cancel the return request.'),
]);
