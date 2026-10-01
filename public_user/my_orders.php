<?php
declare(strict_types=1);

/**
 * Legacy My Orders entry — lives in Shopping Preferences (#order-history).
 * Preserve query params (Stripe session_id, checkout=cancel, paid, etc.).
 */
require_once __DIR__ . '/includes/session_user.php';
requireUserLogin();

$params = $_GET;
$params['view'] = 'order-history';
unset($params['format']);
$qs = http_build_query($params);
header('Location: Your_Shopping_preferences.php' . ($qs !== '' ? ('?' . $qs) : '') . '#order-history');
exit;
