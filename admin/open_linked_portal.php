<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/session_admin.php';
requireAdminLogin();

require_once __DIR__ . '/controller.php';
require_once __DIR__ . '/includes/admin_linked_portal_load.php';
require_once __DIR__ . '/includes/admin_linked_bootstrap_load.php';

$adminId = (int)($_SESSION['admin_id'] ?? 0);
$portal = strtolower(trim((string)($_GET['portal'] ?? '')));

if ($adminId <= 0 || !in_array($portal, admin_linked_portal_allowed(), true)) {
    header('Location: dashboard.php?portal_error=1');
    exit;
}

admin_linked_mark_portal_intent($portal);

$dbh = (new Controller())->pdo();
admin_linked_ensure_provisioned($dbh, $adminId);

header('Location: ' . admin_linked_portal_handoff_url($adminId, $portal));
exit;
