<?php
declare(strict_types=1);

/**
 * Enterprise handoff receiver — starts a clean PHPSESSID org session.
 * Must NOT include public_user session_user.php (BUSINESS_ONLY_USER).
 */
require_once __DIR__ . '/../admin/includes/admin_linked_bootstrap_load.php';
require_once __DIR__ . '/../admin/controller.php';
require_once __DIR__ . '/../public_user/includes/publisher_organization_bridge.php';
require_once __DIR__ . '/../public_user/includes/org_shop.php';
require_once __DIR__ . '/../public_user/includes/account_display_helpers.php';

$handoff = publisher_org_verify_enterprise_handoff();
if (!$handoff) {
    header('Location: login.php?e=enterprise');
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
$managerId = (int)$handoff['manager_id'];
$orgId = (int)$handoff['org_id'];
$publisherUserId = (int)$handoff['publisher_user_id'];

// Re-check access in this request (token alone is not enough if membership changed).
if (!publisher_org_public_user_can_access($dbh, $publisherUserId, $orgId)) {
    header('Location: login.php?e=enterprise_access');
    exit;
}
if (publisher_org_manager_for_publisher($dbh, $publisherUserId) !== $managerId) {
    header('Location: login.php?e=enterprise_manager');
    exit;
}

try {
    publisher_org_ensure_manager_membership($dbh, $orgId, $managerId);
} catch (Throwable $e) {
    header('Location: login.php?e=enterprise_membership');
    exit;
}

if (!publisher_org_apply_enterprise_session($handoff)) {
    header('Location: login.php?e=enterprise_session');
    exit;
}

if (function_exists('account_portal_staff_role_label_from_linked_user')) {
    $portalRole = account_portal_staff_role_label_from_linked_user($dbh, $publisherUserId);
    if ($portalRole !== '') {
        $_SESSION['portal_staff_role_label'] = $portalRole;
    }
}

$dest = 'feed.php';
if (org_is_commerce_seller($dbh, $orgId)) {
    $dest = 'commerce.php';
}

header('Location: ' . $dest);
exit;
