<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/session_user.php';
require_once __DIR__ . '/../controller.php';
require_once __DIR__ . '/../includes/buyer_membership.php';
require_once __DIR__ . '/../includes/stripe_shop.php';

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
if (!stripe_shop_is_configured()) {
    echo json_encode(['ok' => false, 'message' => 'Online membership payment is not configured yet. Please try again later or contact Admin.']);
    exit;
}

$dbh = (new Controller())->pdo();
buyer_membership_ensure_schema($dbh);
$months = max(1, min(12, (int)($_POST['months'] ?? 1)));
$base = stripe_shop_public_base_url();
$checkout = stripe_shop_create_membership_checkout_session(
    $meId,
    $months,
    $base . '/membership_success.php?session_id={CHECKOUT_SESSION_ID}',
    $base . '/Your_Shopping_preferences.php?membership=cancel#membership'
);

echo json_encode([
    'ok' => !empty($checkout['ok']) && !empty($checkout['checkout_url']),
    'checkout_url' => (string)($checkout['checkout_url'] ?? ''),
    'message' => !empty($checkout['ok'])
        ? 'Opening secure checkout…'
        : (string)($checkout['error'] ?? 'Could not start membership checkout.'),
]);
