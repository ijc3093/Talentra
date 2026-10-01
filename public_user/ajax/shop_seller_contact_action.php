<?php
declare(strict_types=1);

/**
 * Add or remove a seller from the buyer's Shopping Preferences contact list.
 * POST action=remember|remove&publisher_user_id=123
 */

require_once __DIR__ . '/../includes/session_user.php';
requireUserLogin();
require_once __DIR__ . '/../controller.php';
require_once __DIR__ . '/../includes/commerce_messaging.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$controller = new Controller();
$dbh = $controller->pdo();
$meId = (int)($_SESSION['user_id'] ?? $_SESSION['id'] ?? $_SESSION['userid'] ?? 0);
if ($meId <= 0) {
    echo json_encode(['ok' => false, 'message' => 'Please sign in.']);
    exit;
}

$action = strtolower(trim((string)($_POST['action'] ?? $_GET['action'] ?? '')));
$publisherId = (int)($_POST['publisher_user_id'] ?? $_GET['publisher_user_id'] ?? 0);
$friendCode = strtoupper(trim((string)($_POST['friend_code'] ?? $_GET['friend_code'] ?? '')));

if ($publisherId <= 0 && $friendCode !== '' && function_exists('commerce_messaging_user_id_by_friend_code')) {
    $publisherId = commerce_messaging_user_id_by_friend_code($dbh, $friendCode);
}

if ($publisherId <= 0) {
    echo json_encode(['ok' => false, 'message' => 'Invalid request.']);
    exit;
}

if ($action === 'remember') {
    $ok = commerce_buyer_seller_contact_remember($dbh, $meId, $publisherId);
    echo json_encode([
        'ok' => $ok,
        'message' => $ok
            ? 'Seller saved to your contact list.'
            : 'Could not save that seller contact.',
    ]);
    exit;
}

if ($action === 'remove') {
    $ok = commerce_buyer_seller_contact_remove($dbh, $meId, $publisherId);
    echo json_encode([
        'ok' => $ok,
        'message' => $ok ? 'Seller removed from your contact list.' : 'Could not remove that seller contact.',
    ]);
    exit;
}

echo json_encode(['ok' => false, 'message' => 'Invalid request.']);
