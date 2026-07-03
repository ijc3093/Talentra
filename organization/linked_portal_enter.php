<?php
declare(strict_types=1);

require_once __DIR__ . '/../admin/includes/admin_linked_bootstrap_load.php';
require_once __DIR__ . '/../admin/includes/admin_linked_portal_load.php';
require_once __DIR__ . '/../admin/controller.php';

$intent = admin_linked_verify_portal_handoff();
if (!$intent || ($intent['kind'] ?? '') !== 'organization') {
    header('Location: login.php');
    exit;
}

$dbh = (new Controller())->pdo();
if (!admin_linked_start_org_session($dbh, (int)$intent['admin_id'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/includes/org_publisher_access.php';
$managerId = admin_linked_manager_id($dbh, (int)$intent['admin_id']);
if ($managerId > 0 && org_manager_is_registered_publisher($dbh, $managerId)) {
    header('Location: ' . admin_linked_absolute_app_url('organization/feed.php'));
    exit;
}

header('Location: ' . admin_linked_absolute_app_url('organization/select_org.php'));
exit;
