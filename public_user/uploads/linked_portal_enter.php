<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/session_user.php';
require_once __DIR__ . '/controller.php';
require_once __DIR__ . '/../admin/includes/admin_linked_bootstrap_load.php';
require_once __DIR__ . '/../admin/includes/admin_linked_portal_load.php';

$intent = admin_linked_verify_portal_handoff();
if (!$intent || !in_array($intent['kind'], ['personal', 'publisher'], true)) {
    $kind = strtolower(trim((string)($intent['kind'] ?? '')));
    $qs = 'session=reset';
    if ($kind === 'publisher') {
        $qs .= '&account_type=publisher';
    }
    header('Location: index.php?' . $qs);
    exit;
}

$controller = new Controller();
$dbh = $controller->pdo();

admin_linked_ensure_provisioned($dbh, (int)$intent['admin_id']);
$user = admin_linked_portal_user($dbh, (int)$intent['admin_id'], (string)$intent['kind']);
if (!$user) {
    $qs = 'session=reset';
    if ($intent['kind'] === 'publisher') {
        $qs .= '&account_type=publisher';
    }
    header('Location: index.php?' . $qs);
    exit;
}

setUserSession($user);

header('Location: ' . admin_linked_absolute_app_url('public_user/feed.php'));
exit;
