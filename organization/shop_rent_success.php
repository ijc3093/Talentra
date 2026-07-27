<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/session_org.php';
require_once __DIR__ . '/includes/org_context.php';
require_once __DIR__ . '/includes/org_manager_guard.php';
require_once __DIR__ . '/../public_user/includes/platform_rent.php';
require_once __DIR__ . '/../public_user/includes/stripe_shop.php';

org_require_manager();
org_require_commerce_seller();

$sessionId = trim((string)($_GET['session_id'] ?? ''));
if ($sessionId === '') {
    header('Location: shop_rent.php');
    exit;
}

$session = stripe_shop_retrieve_session($sessionId);
if ($session) {
    platform_rent_fulfill_stripe_session($dbh, $session);
}

header('Location: shop_rent.php?paid=1');
exit;
