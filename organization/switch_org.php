<?php
// /Business_only3/organization/switch_org.php
declare(strict_types=1);

require_once __DIR__ . '/includes/session_org.php';
require_once __DIR__ . '/includes/org_publisher_access.php';

if (!isOrgManager()) {
    header("Location: dashboard.php");
    exit;
}

$orgId = (int)($_GET['org'] ?? 0);
if ($orgId <= 0) {
    header("Location: dashboard.php");
    exit;
}

// verify access + active org
if (org_manager_can_access_org($dbh, $orgId, orgAccountId())) {
    $row = ['id' => $orgId];
} else {
    $row = false;
}

if ($row) {
    // switch
    $_SESSION['org_active_org_id'] = $orgId;

    // force reload membership/role for selected org on next request
    unset($_SESSION['org_member_id'], $_SESSION['org_role_id']);

    require_once __DIR__ . '/../public_user/includes/staff_publisher_access.php';
    $pubUid = staff_pub_org_publisher_user_id($dbh, $orgId);
    if ($pubUid <= 0 && function_exists('publisher_org_manager_publisher_user_id')) {
        $pubUid = publisher_org_manager_publisher_user_id($dbh, orgAccountId());
    }
    if ($pubUid > 0) {
        $_SESSION['org_publisher_user_id'] = $pubUid;
    } else {
        unset($_SESSION['org_publisher_user_id']);
    }

    // optional success flash via query string
    header("Location: dashboard.php?m=switched");
    exit;
}

// invalid org requested
header("Location: select_org.php?e=invalid_org");
exit;
