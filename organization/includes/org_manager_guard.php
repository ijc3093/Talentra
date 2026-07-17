<?php
declare(strict_types=1);

/** Redirect non-managers away from manager-only publisher tools. */
function org_require_manager(string $redirect = 'feed.php'): void
{
    if (!isOrgManager()) {
        header('Location: ' . $redirect);
        exit;
    }
}

/**
 * Commerce/OMS tools are for commerce sellers only (not CNN/news media publishers).
 * Call after org_require_manager() on shop, sales, CRM, and brand pages.
 */
function org_require_commerce_seller(string $redirect = 'feed.php'): void
{
    global $dbh;
    if (!($dbh instanceof PDO)) {
        header('Location: ' . $redirect);
        exit;
    }
    require_once dirname(__DIR__) . '/../public_user/includes/org_shop.php';
    $orgId = function_exists('orgActiveOrgId') ? (int)orgActiveOrgId() : (int)($_SESSION['org_active_org_id'] ?? 0);
    if ($orgId <= 0 || !org_is_commerce_seller($dbh, $orgId)) {
        header('Location: ' . $redirect . (strpos($redirect, '?') === false ? '?' : '&') . 'notice=commerce_sellers_only');
        exit;
    }
}

function org_active_is_commerce_seller(?PDO $connection = null): bool
{
    if (!($connection instanceof PDO)) {
        global $dbh;
        $connection = (isset($dbh) && $dbh instanceof PDO) ? $dbh : null;
    }
    if (!($connection instanceof PDO)) {
        return false;
    }
    require_once dirname(__DIR__) . '/../public_user/includes/org_shop.php';
    $orgId = function_exists('orgActiveOrgId') ? (int)orgActiveOrgId() : (int)($_SESSION['org_active_org_id'] ?? 0);
    return $orgId > 0 && org_is_commerce_seller($connection, $orgId);
}
