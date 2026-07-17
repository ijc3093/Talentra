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
$reason = trim((string)($_POST['reason'] ?? ''));

$result = org_shop_buyer_cancel_order($dbh, $orderId, $meId, $reason);
echo json_encode([
    'ok' => !empty($result['ok']),
    'message' => !empty($result['ok'])
        ? 'Order cancelled. The seller has been updated automatically.'
        : (string)($result['error'] ?? 'Could not cancel the order.'),
]);
