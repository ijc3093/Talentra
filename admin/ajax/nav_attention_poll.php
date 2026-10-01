<?php
declare(strict_types=1);

/**
 * Live sidebar attention counts (Help badge + workspace aggregates).
 */
require_once __DIR__ . '/../includes/session_admin.php';
requireAdminLogin();

require_once __DIR__ . '/../includes/admin_layout.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

try {
    $dbh = adminDbh();
    $counts = admin_nav_attention_counts($dbh);
    echo json_encode([
        'ok' => true,
        'counts' => $counts,
        'help' => (int)($counts['help_total'] ?? 0),
    ]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => 'Server error']);
}
