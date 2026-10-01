<?php
declare(strict_types=1);

/**
 * Lightweight shop-icon badge poll (order alerts + seller DMs + Support Center + shop inbox).
 */
require_once __DIR__ . '/../includes/session_user.php';
requireUserLogin();
require_once __DIR__ . '/../controller.php';
require_once __DIR__ . '/../includes/commerce_messaging.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$meId = (int)($_SESSION['user_id'] ?? $_SESSION['id'] ?? $_SESSION['userid'] ?? 0);
if ($meId <= 0 && function_exists('myUserId')) {
    $meId = (int)myUserId();
}

$count = 0;
try {
    $dbh = (new Controller())->pdo();
    if ($meId > 0 && function_exists('commerce_buyer_shop_hub_badge_count')) {
        $count = commerce_buyer_shop_hub_badge_count($dbh, $meId);
    }
} catch (Throwable $e) {
    $count = 0;
}

echo json_encode([
    'ok' => true,
    'count' => max(0, (int)$count),
    // Bag opens shop; Shopping Preferences on shop.php carries the same hub count.
    'href' => 'shop.php',
], JSON_UNESCAPED_UNICODE);
