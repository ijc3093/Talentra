<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/session_user.php';
requireUserLogin();

require_once __DIR__ . '/controller.php';
require_once __DIR__ . '/includes/stripe_shop.php';
require_once __DIR__ . '/includes/buyer_membership.php';

$sessionId = trim((string)($_GET['session_id'] ?? ''));
if ($sessionId === '') {
    header('Location: Your_Shopping_preferences.php#membership');
    exit;
}

$controller = new Controller();
$dbh = $controller->pdo();
$session = stripe_shop_retrieve_session($sessionId);
if ($session) {
    buyer_membership_fulfill_stripe_session($dbh, $session);
}

header('Location: Your_Shopping_preferences.php?membership=1#membership');
exit;
