<?php
declare(strict_types=1);

/**
 * Live Sales Management hub badge (orders, products, notifications, customer DMs, support, disputes).
 */
require_once __DIR__ . '/../includes/session_org.php';
require_once __DIR__ . '/../includes/org_context.php';
require_once __DIR__ . '/../includes/org_manager_guard.php';
require_once __DIR__ . '/../includes/org_sales.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function sab_json(array $a): void
{
    echo json_encode($a, JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isOrgManager() || !org_active_is_commerce_seller($dbh)) {
    sab_json(['ok' => true, 'total' => 0, 'counts' => []]);
}

$orgId = (int)orgActiveOrgId();
$counts = [
    'total' => 0,
    'orders' => 0,
    'delivery' => 0,
    'products' => 0,
    'inventory_low' => 0,
    'inventory_out' => 0,
    'customers' => 0,
    'returns' => 0,
    'notification' => 0,
    'messages' => 0,
    'support' => 0,
    'disputes' => 0,
];

try {
    if ($orgId > 0) {
        $counts = org_sales_attention_counts($dbh, $orgId);
    }
} catch (Throwable $e) {
    // keep zeros
}

sab_json([
    'ok' => true,
    'total' => max(0, (int)($counts['total'] ?? 0)),
    'counts' => $counts,
]);
