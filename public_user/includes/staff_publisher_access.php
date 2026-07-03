<?php
declare(strict_types=1);

/**
 * Organization staff act as the linked publisher in public_user (view-only).
 * Staff credentials never open personal user accounts.
 */

require_once __DIR__ . '/publisher_organization_bridge.php';

function staff_pub_clear_session_flags(): void
{
    unset(
        $_SESSION['staff_publisher_mode'],
        $_SESSION['staff_account_id'],
        $_SESSION['staff_org_id'],
        $_SESSION['staff_readonly'],
        $_SESSION['staff_display_name']
    );
}

function staff_pub_is_staff_session(): bool
{
    return !empty($_SESSION['staff_publisher_mode'])
        && (int)($_SESSION['staff_account_id'] ?? 0) > 0;
}

function staff_pub_is_readonly(): bool
{
    return staff_pub_is_staff_session() && !empty($_SESSION['staff_readonly']);
}

function staff_pub_staff_account_id(): int
{
    return staff_pub_is_staff_session() ? (int)($_SESSION['staff_account_id'] ?? 0) : 0;
}

function staff_pub_org_id(): int
{
    return staff_pub_is_staff_session() ? (int)($_SESSION['staff_org_id'] ?? 0) : 0;
}

function staff_pub_publisher_user_id(): int
{
    return staff_pub_is_staff_session() ? (int)($_SESSION['user_id'] ?? 0) : 0;
}

function staff_pub_display_name(): string
{
    if (!staff_pub_is_staff_session()) {
        return '';
    }
    $name = trim((string)($_SESSION['staff_display_name'] ?? ''));
    return $name !== '' ? $name : 'Staff';
}

function staff_pub_org_publisher_user_id(PDO $dbh, int $orgId): int
{
    if ($orgId <= 0) {
        return 0;
    }

    publisher_org_ensure_schema($dbh);

    try {
        $st = $dbh->prepare('
            SELECT publisher_user_id
            FROM organizations
            WHERE id = :id
              AND status = 1
              AND is_publisher_org = 1
              AND publisher_user_id IS NOT NULL
            LIMIT 1
        ');
        $st->execute([':id' => $orgId]);
        return (int)($st->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        return 0;
    }
}

function staff_pub_staff_row(PDO $dbh, int $staffId): ?array
{
    if ($staffId <= 0) {
        return null;
    }

    try {
        $st = $dbh->prepare('
            SELECT id, org_id, username, email, password, fullname, status
            FROM staff_accounts
            WHERE id = :id
            LIMIT 1
        ');
        $st->execute([':id' => $staffId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row || (int)($row['status'] ?? 0) !== 1) {
            return null;
        }
        return $row;
    } catch (Throwable $e) {
        return null;
    }
}

function staff_pub_staff_can_access_org(PDO $dbh, int $staffId, int $orgId): bool
{
    if ($staffId <= 0 || $orgId <= 0) {
        return false;
    }

    try {
        $st = $dbh->prepare('
            SELECT 1
            FROM staff_accounts
            WHERE id = :id
              AND org_id = :org
              AND status = 1
            LIMIT 1
        ');
        $st->execute([':id' => $staffId, ':org' => $orgId]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function staff_pub_fetch_publisher_user(PDO $dbh, int $publisherUserId): ?array
{
    if ($publisherUserId <= 0) {
        return null;
    }

    $userCols = ['id', 'name', 'username', 'email', 'password', 'image', 'role', 'status', 'friend_code'];
    if (publisher_db_column_exists($dbh, 'users', 'account_kind')) {
        $userCols[] = 'account_kind';
    }
    if (publisher_db_column_exists($dbh, 'users', 'publisher_category')) {
        $userCols[] = 'publisher_category';
    }

    try {
        $st = $dbh->prepare('SELECT ' . implode(', ', $userCols) . ' FROM users WHERE id = :id LIMIT 1');
        $st->execute([':id' => $publisherUserId]);
        $publisher = $st->fetch(PDO::FETCH_ASSOC);
        if (!$publisher || (int)($publisher['status'] ?? 0) !== 1) {
            return null;
        }
        if (!publisher_user_row_looks_like_publisher($dbh, $publisher)) {
            return null;
        }
        return $publisher;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * @return array{staff:array,publisher:array,org_id:int}|null
 */
function staff_pub_authenticate(PDO $dbh, string $login, string $password): ?array
{
    $login = trim($login);
    if ($login === '' || $password === '') {
        return null;
    }

    try {
        $st = $dbh->prepare('
            SELECT id, org_id, username, email, password, fullname, status
            FROM staff_accounts
            WHERE (username = :u OR email = :e)
            LIMIT 1
        ');
        $st->execute([':u' => $login, ':e' => $login]);
        $staff = $st->fetch(PDO::FETCH_ASSOC);
        if (
            !$staff
            || (int)($staff['status'] ?? 0) !== 1
            || !password_verify($password, (string)($staff['password'] ?? ''))
        ) {
            return null;
        }

        $orgId = (int)($staff['org_id'] ?? 0);
        $publisherUserId = staff_pub_org_publisher_user_id($dbh, $orgId);
        if ($publisherUserId <= 0) {
            return null;
        }

        $publisher = staff_pub_fetch_publisher_user($dbh, $publisherUserId);
        if (!$publisher) {
            return null;
        }

        return [
            'staff' => $staff,
            'publisher' => $publisher,
            'org_id' => $orgId,
        ];
    } catch (Throwable $e) {
        return null;
    }
}

function staff_pub_set_session(PDO $dbh, array $staffRow, array $publisherUser): void
{
    session_regenerate_id(true);

    staff_pub_clear_session_flags();

    $publisherUserId = (int)($publisherUser['id'] ?? 0);
    $staffId = (int)($staffRow['id'] ?? 0);
    $orgId = (int)($staffRow['org_id'] ?? 0);

    $_SESSION['user_id'] = $publisherUserId;
    $_SESSION['user_login'] = trim((string)($publisherUser['username'] ?? ''));
    $_SESSION['user_email'] = trim((string)($publisherUser['email'] ?? ''));
    $_SESSION['user_friend_code'] = trim((string)($publisherUser['friend_code'] ?? ''));
    $_SESSION['user_name'] = (string)($publisherUser['name'] ?? '');
    $_SESSION['user_image'] = (string)($publisherUser['image'] ?? 'default.jpg');
    $_SESSION['user_role'] = (int)($publisherUser['role'] ?? 0);
    $_SESSION['user_status'] = (int)($publisherUser['status'] ?? 1);
    $_SESSION['user_account_kind'] = 'publisher';
    $_SESSION['staff_publisher_mode'] = 1;
    $_SESSION['staff_account_id'] = $staffId;
    $_SESSION['staff_org_id'] = $orgId;
    $_SESSION['staff_readonly'] = 1;
    $_SESSION['staff_display_name'] = trim((string)($staffRow['fullname'] ?? $staffRow['username'] ?? 'Staff'));

    require_once __DIR__ . '/account_display_helpers.php';
    $portalRole = account_org_staff_role_label($dbh, $staffId, $orgId);
    if ($portalRole === '') {
        $portalRole = 'Staff';
    }
    $_SESSION['portal_staff_role_label'] = $portalRole;
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

    publisher_session_bind_staff($publisherUserId, $staffId, $orgId);

    try {
        ensureUserSessionRecord($publisherUserId, $dbh);
    } catch (Throwable $e) {
        // ignore session table failures during login
    }
}

/**
 * Left-menu publisher company entry for the active session viewer.
 *
 * @return list<array{user_id:int,name:string,username:string,org_id:int,is_self:bool,href:string}>
 */
function staff_pub_menu_for_viewer(PDO $dbh, int $viewerUserId): array
{
    if (staff_pub_is_staff_session()) {
        $orgId = staff_pub_org_id();
        $publisherUserId = staff_pub_publisher_user_id();
        if ($orgId <= 0 || $publisherUserId <= 0) {
            return [];
        }

        $publisher = staff_pub_fetch_publisher_user($dbh, $publisherUserId);
        if (!$publisher) {
            return [];
        }

        $name = publisher_registry_normalize_name((string)($publisher['name'] ?? ''));
        if ($name === '') {
            return [];
        }

        return [[
            'user_id' => $publisherUserId,
            'name' => $name,
            'username' => trim((string)($publisher['username'] ?? '')),
            'org_id' => $orgId,
            'is_self' => true,
            'href' => 'staff_org_portal.php?org_id=' . $orgId,
        ]];
    }

    return publisher_menu_own_company($dbh, $viewerUserId);
}

/** Block page writes (create/edit/delete posts). */
function staff_pub_deny_write(): void
{
    if (!staff_pub_is_readonly()) {
        return;
    }

    header('Location: feed.php');
    exit;
}

/** Keep staff on publisher view surfaces only (no personal account areas). */
function staff_pub_enforce_allowed_page(): void
{
    if (!staff_pub_is_readonly()) {
        return;
    }

    $script = strtolower(basename((string)($_SERVER['PHP_SELF'] ?? '')));
    $allowed = [
        'feed.php',
        'public.php',
        'news.php',
        'feed_api.php',
        'logout.php',
        'profile.php',
        'staff_org_portal.php',
        'staff_publisher_portal.php',
        'avatar.php',
        'index.php',
    ];

    if (!in_array($script, $allowed, true)) {
        header('Location: feed.php');
        exit;
    }
}

/** Open organization session for staff linked to publisher org. */
function staff_pub_begin_org_session(PDO $dbh, int $staffId, int $orgId): bool
{
    if ($staffId <= 0 || $orgId <= 0) {
        return false;
    }
    if (!staff_pub_staff_can_access_org($dbh, $staffId, $orgId)) {
        return false;
    }
    if (staff_pub_org_publisher_user_id($dbh, $orgId) <= 0) {
        return false;
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    session_name('PHPSESSID');
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
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

    require_once __DIR__ . '/account_display_helpers.php';
    $portalRole = account_org_staff_role_label($dbh, $staffId, $orgId);
    if ($portalRole !== '') {
        $_SESSION['portal_staff_role_label'] = $portalRole;
    }

    return true;
}

/** Open public_user publisher session for org staff (view-only). */
function staff_pub_begin_public_session(PDO $dbh, int $staffId, int $orgId): bool
{
    $staffRow = staff_pub_staff_row($dbh, $staffId);
    if (!$staffRow || (int)($staffRow['org_id'] ?? 0) !== $orgId) {
        return false;
    }

    $publisherUserId = staff_pub_org_publisher_user_id($dbh, $orgId);
    if ($publisherUserId <= 0) {
        return false;
    }

    $publisher = staff_pub_fetch_publisher_user($dbh, $publisherUserId);
    if (!$publisher) {
        return false;
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    session_name('BUSINESS_ONLY_USER');
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    staff_pub_set_session($dbh, $staffRow, $publisher);

    return true;
}
