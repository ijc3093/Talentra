<?php
declare(strict_types=1);

/**
 * Staff Enterprise handoff — clean PHPSESSID org session.
 * Must NOT include public_user session_user.php.
 */
require_once __DIR__ . '/../admin/includes/admin_linked_bootstrap_load.php';
require_once __DIR__ . '/../admin/controller.php';
require_once __DIR__ . '/../public_user/includes/staff_publisher_access.php';
require_once __DIR__ . '/../public_user/includes/account_display_helpers.php';

$handoff = staff_pub_verify_enterprise_handoff();
if (!$handoff) {
    header('Location: login.php?e=staff_enterprise');
    exit;
}

if (function_exists('admin_linked_apply_session_cookie_path')) {
    admin_linked_apply_session_cookie_path();
}
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('PHPSESSID');
    session_start();
}

$dbh = (new Controller())->pdo();
$staffId = (int)$handoff['staff_id'];
$orgId = (int)$handoff['org_id'];

if (!staff_pub_staff_can_access_org($dbh, $staffId, $orgId)) {
    header('Location: login.php?e=staff_access');
    exit;
}

if (function_exists('session_regenerate_id')) {
    @session_regenerate_id(true);
}

$_SESSION['org_auth'] = 1;
$_SESSION['org_account_type'] = 'staff';
$_SESSION['org_account_id'] = $staffId;
$_SESSION['org_active_org_id'] = $orgId;
unset($_SESSION['org_member_id'], $_SESSION['org_role_id']);

$publisherUserId = staff_pub_org_publisher_user_id($dbh, $orgId);
if ($publisherUserId > 0) {
    $_SESSION['org_publisher_user_id'] = $publisherUserId;
} else {
    unset($_SESSION['org_publisher_user_id']);
}

$portalRole = account_org_staff_role_label($dbh, $staffId, $orgId);
if ($portalRole !== '') {
    $_SESSION['portal_staff_role_label'] = $portalRole;
}

if (function_exists('app_session_login_mark')) {
    app_session_login_mark();
}

header('Location: feed.php');
exit;
