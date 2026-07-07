<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/session_user.php';
requireUserLogin();

require_once __DIR__ . '/controller.php';
require_once __DIR__ . '/includes/org_shop.php';
require_once __DIR__ . '/includes/stripe_shop.php';

$sessionId = trim((string)($_GET['session_id'] ?? ''));
if ($sessionId === '') {
    header('Location: my_orders.php');
    exit;
}

$controller = new Controller();
$dbh = $controller->pdo();

$session = stripe_shop_retrieve_session($sessionId);
if ($session) {
    org_shop_fulfill_stripe_session($dbh, $session);
}

header('Location: my_orders.php?session_id=' . rawurlencode($sessionId));
exit;
