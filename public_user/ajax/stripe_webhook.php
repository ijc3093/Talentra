<?php
declare(strict_types=1);

require_once __DIR__ . '/../controller.php';
require_once __DIR__ . '/../includes/org_shop.php';
require_once __DIR__ . '/../includes/stripe_shop.php';

$payload = file_get_contents('php://input');
$sig = (string)($_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '');

http_response_code(400);

if ($payload === '' || !stripe_shop_verify_webhook($payload, $sig)) {
    echo 'invalid';
    exit;
}

$event = json_decode($payload, true);
if (!is_array($event)) {
    echo 'invalid json';
    exit;
}

$type = (string)($event['type'] ?? '');
if ($type !== 'checkout.session.completed') {
    http_response_code(200);
    echo 'ignored';
    exit;
}

$session = $event['data']['object'] ?? null;
if (!is_array($session)) {
    echo 'no session';
    exit;
}

$controller = new Controller();
$dbh = $controller->pdo();
org_shop_fulfill_stripe_session($dbh, $session);

http_response_code(200);
echo 'ok';
