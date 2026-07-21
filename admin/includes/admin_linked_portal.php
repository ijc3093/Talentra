<?php
declare(strict_types=1);

require_once __DIR__ . '/admin_linked_accounts_load.php';
require_once __DIR__ . '/admin_linked_bootstrap_load.php';

function admin_linked_portal_allowed(): array
{
    return ['personal', 'publisher', 'organization'];
}

/** @return array<string,mixed> */
function admin_linked_portal_summary(PDO $dbh, int $adminId): array
{
    admin_linked_ensure_provisioned($dbh, $adminId);

    $admin = admin_linked_fetch_admin($dbh, $adminId);
    $personalId = (int)($admin['linked_personal_user_id'] ?? 0);
    $publisherId = (int)($admin['linked_publisher_user_id'] ?? 0);
    $managerId = (int)($admin['linked_manager_id'] ?? 0);

    $personalUser = $personalId > 0 ? user_admin_get_user_full($dbh, $personalId) : null;
    $publisherUser = $publisherId > 0 ? user_admin_get_user_full($dbh, $publisherId) : null;

    $orgName = '';
    if ($managerId > 0) {
        try {
            $st = $dbh->prepare('
                SELECT o.name
                FROM organizations o
                INNER JOIN org_members om ON om.org_id = o.id
                WHERE om.member_type = \'manager\'
                  AND om.member_id = :mid
                  AND om.status = 1
                ORDER BY o.is_publisher_org DESC, o.id ASC
                LIMIT 1
            ');
            $st->execute([':mid' => $managerId]);
            $orgName = trim((string)($st->fetchColumn() ?: ''));
        } catch (Throwable $e) {
            $orgName = '';
        }
    }

    return [
        'personal' => [
            'ready' => $personalUser && (int)($personalUser['status'] ?? 0) === 1,
            'name' => (string)($personalUser['name'] ?? ''),
            'username' => (string)($personalUser['username'] ?? ''),
            'url' => admin_linked_portal_handoff_url($adminId, 'personal'),
        ],
        'publisher' => [
            'ready' => $publisherUser && (int)($publisherUser['status'] ?? 0) === 1,
            'name' => (string)($publisherUser['name'] ?? ''),
            'username' => (string)($publisherUser['username'] ?? ''),
            'url' => admin_linked_portal_handoff_url($adminId, 'publisher'),
        ],
        'organization' => [
            'ready' => $managerId > 0,
            'name' => $orgName !== '' ? $orgName : 'My organization',
            'manager_id' => $managerId,
            'url' => admin_linked_portal_handoff_url($adminId, 'organization'),
        ],
    ];
}

function admin_linked_portal_handoff_url(int $adminId, string $portal): string
{
    $portal = strtolower(trim($portal));
    if ($adminId <= 0 || !in_array($portal, admin_linked_portal_allowed(), true)) {
        return 'open_linked_portal.php?portal=' . rawurlencode($portal);
    }

    if ($portal === 'organization') {
        return admin_linked_absolute_portal_url('organization/linked_portal_enter.php', $adminId, $portal);
    }

    return admin_linked_absolute_portal_url('public_user/linked_portal_enter.php', $adminId, $portal);
}

function admin_linked_start_public_session(PDO $dbh, int $adminId, string $accountKind): bool
{
    $user = admin_linked_portal_user($dbh, $adminId, $accountKind);
    if (!$user) {
        return false;
    }

    $previousName = session_name();
    $previousId = session_id();
    $wasActive = session_status() === PHP_SESSION_ACTIVE;
    if ($wasActive) {
        session_write_close();
    }

    admin_linked_apply_session_cookie_path();
    session_name('BUSINESS_ONLY_USER');
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    if (!function_exists('setUserSession')) {
        require_once dirname(__DIR__, 2) . '/public_user/includes/session_user.php';
    }
    setUserSession($user);

    session_write_close();

    session_name($previousName !== '' ? $previousName : (defined('ADMIN_SESSION_NAME') ? ADMIN_SESSION_NAME : 'TALENTRA_ADMIN'));
    if ($previousId !== '') {
        session_id($previousId);
    }
    if ($wasActive || session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    return true;
}

function admin_linked_start_org_session(PDO $dbh, int $adminId): bool
{
    $managerId = admin_linked_manager_id($dbh, $adminId);
    if ($managerId <= 0) {
        return false;
    }

    $previousName = session_name();
    $previousId = session_id();
    $wasActive = session_status() === PHP_SESSION_ACTIVE;
    if ($wasActive) {
        session_write_close();
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    admin_linked_apply_session_cookie_path();
    session_name('PHPSESSID');
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $_SESSION['org_auth'] = 1;
    $_SESSION['org_account_type'] = 'manager';
    $_SESSION['org_account_id'] = $managerId;
    unset($_SESSION['org_publisher_user_id'], $_SESSION['org_active_org_id']);

    require_once dirname(__DIR__, 2) . '/organization/includes/org_publisher_access.php';
    if (org_manager_is_registered_publisher($dbh, $managerId)) {
        org_manager_apply_registered_publisher_login($dbh, $managerId);
        publisher_session_establish_for_manager($dbh, $managerId);
    }

    app_session_login_mark();

    session_write_close();

    session_name($previousName !== '' ? $previousName : (defined('ADMIN_SESSION_NAME') ? ADMIN_SESSION_NAME : 'TALENTRA_ADMIN'));
    if ($previousId !== '') {
        session_id($previousId);
    }
    if ($wasActive || session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    return true;
}

function admin_linked_open_portal(PDO $dbh, int $adminId, string $portal): ?string
{
    $portal = strtolower(trim($portal));
    if (!in_array($portal, admin_linked_portal_allowed(), true)) {
        return null;
    }

    admin_linked_ensure_provisioned($dbh, $adminId);

    if ($portal === 'personal') {
        if (!admin_linked_start_public_session($dbh, $adminId, 'personal')) {
            return null;
        }
        return 'public_user/feed.php';
    }

    if ($portal === 'publisher') {
        if (!admin_linked_start_public_session($dbh, $adminId, 'publisher')) {
            return null;
        }
        return 'public_user/feed.php';
    }

    if ($portal === 'organization') {
        if (!admin_linked_start_org_session($dbh, $adminId)) {
            return null;
        }
        require_once dirname(__DIR__, 2) . '/organization/includes/org_publisher_access.php';
        if (org_manager_is_registered_publisher($dbh, admin_linked_manager_id($dbh, $adminId))) {
            return 'organization/feed.php';
        }
        return 'organization/select_org.php';
    }

    return null;
}
