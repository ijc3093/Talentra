<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/session_user.php';
require_once __DIR__ . '/../controller.php';
require_once __DIR__ . '/../includes/org_cart.php';

header('Content-Type: application/json; charset=utf-8');

$controller = new Controller();
$dbh = $controller->pdo();
$meId = (int)($_SESSION['user_id'] ?? $_SESSION['id'] ?? $_SESSION['userid'] ?? 0);

if ($meId <= 0) {
    echo json_encode(['ok' => false, 'message' => 'Please sign in.']);
    exit;
}

$action = strtolower(trim((string)($_POST['action'] ?? $_GET['action'] ?? '')));
$productId = (int)($_POST['product_id'] ?? $_GET['product_id'] ?? 0);
$quantity = (int)($_POST['quantity'] ?? $_GET['quantity'] ?? 1);

switch ($action) {
    case 'add':
        $result = org_cart_add_item($dbh, $meId, $productId, $quantity);
        echo json_encode([
            'ok' => !empty($result['ok']),
            'message' => !empty($result['ok']) ? 'Added to cart.' : (string)($result['error'] ?? 'Failed.'),
            'count' => (int)($result['count'] ?? org_cart_count($dbh, $meId)),
        ]);
        break;

    case 'update':
        $result = org_cart_update_quantity($dbh, $meId, $productId, $quantity);
        echo json_encode([
            'ok' => !empty($result['ok']),
            'message' => !empty($result['ok']) ? 'Cart updated.' : (string)($result['error'] ?? 'Failed.'),
            'count' => (int)($result['count'] ?? org_cart_count($dbh, $meId)),
        ]);
        break;

    case 'remove':
        $result = org_cart_remove_item($dbh, $meId, $productId);
        echo json_encode([
            'ok' => !empty($result['ok']),
            'message' => !empty($result['ok']) ? 'Removed from cart.' : (string)($result['error'] ?? 'Failed.'),
            'count' => (int)($result['count'] ?? org_cart_count($dbh, $meId)),
        ]);
        break;

    case 'count':
        echo json_encode(['ok' => true, 'count' => org_cart_count($dbh, $meId)]);
        break;

    default:
        echo json_encode(['ok' => false, 'message' => 'Unknown action.']);
}
