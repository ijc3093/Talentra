<?php
// /Business_only3/includes/session_user.php

if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool
    {
        if ($needle === '') {
            return true;
        }
        return strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}

if (session_status() === PHP_SESSION_NONE) {
    $bootstrapLoad = dirname(__DIR__, 2) . '/admin/includes/admin_linked_bootstrap_load.php';
    if (is_file($bootstrapLoad)) {
        require_once $bootstrapLoad;
    }
    session_name('BUSINESS_ONLY_USER');
    if (function_exists('admin_linked_apply_session_cookie_path')) {
        admin_linked_apply_session_cookie_path();
    }
    session_start();
}

function enforceProductionErrorSettings(): void
{
    @ini_set('display_errors', '0');
    @ini_set('display_startup_errors', '0');
    @ini_set('html_errors', '0');
    @ini_set('log_errors', '1');
}

enforceProductionErrorSettings();

function isUnsafeHttpMethod(?string $method = null): bool
{
    $method = strtoupper(trim((string)($method ?? ($_SERVER['REQUEST_METHOD'] ?? 'GET'))));
    return in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true);
}

function csrfToken(): string
{
    $token = trim((string)($_SESSION['csrf_token'] ?? ''));
    if ($token === '') {
        $token = bin2hex(random_bytes(32));
        $_SESSION['csrf_token'] = $token;
    }
    return $token;
}

function csrfInput(): string
{
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') . '">';
}

function requestHasValidCsrf(): bool
{
    return true;
}

function rejectInvalidCsrf(): void
{
    exit;
}

function requireValidCsrf(): void
{
    return;
}

function userSessionTableExists(?PDO $dbh = null): bool
{
    static $cached = null;
    if ($cached !== null) return $cached;

    try {
        if (!$dbh) {
            require_once __DIR__ . '/../controller.php';
            $controller = new Controller();
            $dbh = $controller->pdo();
        }
        $st = $dbh->query("SHOW TABLES LIKE 'user_sessions'");
        $cached = (bool)($st && $st->fetchColumn());
        return $cached;
    } catch (Throwable $e) {
        $cached = false;
        return false;
    }
}

function userSessionClientIp(): string
{
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $key) {
        $raw = trim((string)($_SERVER[$key] ?? ''));
        if ($raw === '') continue;
        if ($key === 'HTTP_X_FORWARDED_FOR') {
            $raw = trim(explode(',', $raw)[0] ?? '');
        }
        return mb_substr($raw, 0, 45);
    }
    return '';
}

function userSessionUserAgent(): string
{
    return mb_substr(trim((string)($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 500);
}

function ensureUserSessionRecord(int $userId, ?PDO $dbh = null): void
{
    if ($userId <= 0) return;

    try {
        if (!$dbh) {
            require_once __DIR__ . '/../controller.php';
            $controller = new Controller();
            $dbh = $controller->pdo();
        }
        if (!userSessionTableExists($dbh)) return;

        $phpSessionId = (string)session_id();
        if ($phpSessionId === '') return;

        $token = trim((string)($_SESSION['session_token'] ?? ''));
        if ($token === '') {
            $token = bin2hex(random_bytes(32));
            $_SESSION['session_token'] = $token;
        }

        $ip = userSessionClientIp();
        $ua = userSessionUserAgent();

        $sql = "INSERT INTO user_sessions (user_id, php_session_id, session_token, ip_address, user_agent, created_at, last_seen_at, revoked_at)
                VALUES (:uid, :sid, :token, :ip, :ua, NOW(), NOW(), NULL)
                ON DUPLICATE KEY UPDATE
                    user_id = VALUES(user_id),
                    session_token = VALUES(session_token),
                    ip_address = VALUES(ip_address),
                    user_agent = VALUES(user_agent),
                    last_seen_at = NOW(),
                    revoked_at = NULL";
        $st = $dbh->prepare($sql);
        $st->execute([
            ':uid' => $userId,
            ':sid' => $phpSessionId,
            ':token' => $token,
            ':ip' => $ip,
            ':ua' => $ua,
        ]);
    } catch (Throwable $e) {
        // keep login flow resilient if session logging fails
    }
}

function validateCurrentUserSession(?PDO $dbh = null): bool
{
    $uid = (int)($_SESSION['user_id'] ?? 0);
    if ($uid <= 0) return false;

    try {
        if (!$dbh) {
            require_once __DIR__ . '/../controller.php';
            $controller = new Controller();
            $dbh = $controller->pdo();
        }
        if (!userSessionTableExists($dbh)) return true;

        $phpSessionId = (string)session_id();
        $token = trim((string)($_SESSION['session_token'] ?? ''));
        if ($phpSessionId === '' || $token === '') return false;

        $st = $dbh->prepare("SELECT id FROM user_sessions WHERE user_id = :uid AND php_session_id = :sid AND session_token = :token AND revoked_at IS NULL LIMIT 1");
        $st->execute([
            ':uid' => $uid,
            ':sid' => $phpSessionId,
            ':token' => $token,
        ]);
        $ok = (bool)$st->fetchColumn();
        if ($ok) {
            $up = $dbh->prepare("UPDATE user_sessions SET last_seen_at = NOW() WHERE user_id = :uid AND php_session_id = :sid AND session_token = :token LIMIT 1");
            $up->execute([
                ':uid' => $uid,
                ':sid' => $phpSessionId,
                ':token' => $token,
            ]);
        }
        return $ok;
    } catch (Throwable $e) {
        return true;
    }
}

function revokeCurrentUserSession(?PDO $dbh = null): void
{
    try {
        $uid = (int)($_SESSION['user_id'] ?? 0);
        $phpSessionId = (string)session_id();
        $token = trim((string)($_SESSION['session_token'] ?? ''));
        if ($uid <= 0 || $phpSessionId === '' || $token === '') return;

        if (!$dbh) {
            require_once __DIR__ . '/../controller.php';
            $controller = new Controller();
            $dbh = $controller->pdo();
        }
        if (!userSessionTableExists($dbh)) return;

        $st = $dbh->prepare("UPDATE user_sessions SET revoked_at = NOW(), last_seen_at = NOW() WHERE user_id = :uid AND php_session_id = :sid AND session_token = :token AND revoked_at IS NULL");
        $st->execute([
            ':uid' => $uid,
            ':sid' => $phpSessionId,
            ':token' => $token,
        ]);
    } catch (Throwable $e) {
        // ignore
    }
}

function revokeAllUserSessions(int $userId, ?string $exceptSessionId = null, ?PDO $dbh = null): int
{
    if ($userId <= 0) return 0;

    try {
        if (!$dbh) {
            require_once __DIR__ . '/../controller.php';
            $controller = new Controller();
            $dbh = $controller->pdo();
        }
        if (!userSessionTableExists($dbh)) return -1;

        if ($exceptSessionId !== null && $exceptSessionId !== '') {
            $st = $dbh->prepare("UPDATE user_sessions SET revoked_at = NOW(), last_seen_at = NOW() WHERE user_id = :uid AND revoked_at IS NULL AND php_session_id <> :sid");
            $st->execute([':uid' => $userId, ':sid' => $exceptSessionId]);
        } else {
            $st = $dbh->prepare("UPDATE user_sessions SET revoked_at = NOW(), last_seen_at = NOW() WHERE user_id = :uid AND revoked_at IS NULL");
            $st->execute([':uid' => $userId]);
        }
        return (int)$st->rowCount();
    } catch (Throwable $e) {
        return 0;
    }
}

function revokeOneUserSession(int $userId, int $sessionRowId, ?string $exceptSessionId = null, ?PDO $dbh = null): bool
{
    if ($userId <= 0 || $sessionRowId <= 0) return false;

    try {
        if (!$dbh) {
            require_once __DIR__ . '/../controller.php';
            $controller = new Controller();
            $dbh = $controller->pdo();
        }
        if (!userSessionTableExists($dbh)) return false;

        $params = [':uid' => $userId, ':id' => $sessionRowId];
        $sql = "UPDATE user_sessions SET revoked_at = NOW(), last_seen_at = NOW() WHERE id = :id AND user_id = :uid AND revoked_at IS NULL";
        if ($exceptSessionId !== null && $exceptSessionId !== '') {
            $sql .= " AND php_session_id <> :sid";
            $params[':sid'] = $exceptSessionId;
        }
        $st = $dbh->prepare($sql);
        $st->execute($params);
        return $st->rowCount() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function sendNoCacheHeadersUser(): void
{
    if (headers_sent()) return;
    header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
    header("Cache-Control: post-check=0, pre-check=0", false);
    header("Pragma: no-cache");
    header("Expires: 0");
}

function user_account_deactivated_message(): string
{
    return 'Your account is deactivated temporarily. Please contact your admin for the support center.';
}

function user_account_deleted_message(): string
{
    return 'Your account is no longer available because you have not followed the terms & policy. Please contact your admin for the support center or create a new account.';
}

require_once __DIR__ . '/deleted_user_registry.php';

function user_is_account_removed(PDO $dbh, int $userId): bool
{
    if ($userId <= 0) {
        return false;
    }
    try {
        $st = $dbh->prepare('SELECT 1 FROM users WHERE id = :id LIMIT 1');
        $st->execute([':id' => $userId]);
        return !$st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function user_session_login_was_deleted(PDO $dbh): bool
{
    $login = trim((string)($_SESSION['user_login'] ?? ''));
    if ($login === '') {
        return false;
    }
    return user_login_identifier_was_deleted($dbh, $login);
}

function user_is_account_deactivated(PDO $dbh, int $userId): bool
{
    if ($userId <= 0) {
        return false;
    }
    try {
        $st = $dbh->prepare('SELECT status FROM users WHERE id = :id LIMIT 1');
        $st->execute([':id' => $userId]);
        $status = $st->fetchColumn();
        return $status !== false && (int)$status !== 1;
    } catch (Throwable $e) {
        return false;
    }
}

function redirectUserLoginDeactivated(): void
{
    clearUserSession();
    header('Location: index.php?deactivated=1');
    exit;
}

function redirectUserLoginDeleted(): void
{
    clearUserSession();
    header('Location: index.php?deleted=1');
    exit;
}

function requireUserLogin(): void
{
    sendNoCacheHeadersUser();

    $needsLinkedSync = empty($_SESSION['user_login']) || empty($_SESSION['user_id']);
    if (!$needsLinkedSync) {
        try {
            require_once dirname(__DIR__, 2) . '/admin/includes/admin_linked_bootstrap_load.php';
            if (admin_linked_verify_portal_handoff()) {
                $needsLinkedSync = true;
            }
        } catch (Throwable $e) {
            // ignore
        }
    }

    if ($needsLinkedSync) {
        try {
            require_once dirname(__DIR__, 2) . '/admin/includes/admin_linked_bootstrap_load.php';
            admin_linked_sync_public_user_from_admin_intent();
        } catch (Throwable $e) {
            // fall through to normal auth gate
        }
    }

    if (empty($_SESSION['user_login']) || empty($_SESSION['user_id'])) {
        header("Location: index.php?session=reset");
        exit;
    }

    if (app_session_is_expired()) {
        clearUserSession();
        app_session_redirect_with_expired('index.php');
    }

    try {
        require_once __DIR__ . '/../controller.php';
        $controller = new Controller();
        $dbh = $controller->pdo();
        if (!validateCurrentUserSession($dbh)) {
            ensureUserSessionRecord((int)($_SESSION['user_id'] ?? 0), $dbh);
        }

        require_once __DIR__ . '/publisher_accounts_load.php';
        if (!publisher_session_validate($dbh)) {
            $uid = (int)($_SESSION['user_id'] ?? 0);
            if ($uid > 0 && user_is_account_removed($dbh, $uid)) {
                redirectUserLoginDeleted();
            }
            if (user_session_login_was_deleted($dbh)) {
                redirectUserLoginDeleted();
            }
            if ($uid > 0 && user_is_account_deactivated($dbh, $uid)) {
                redirectUserLoginDeactivated();
            }
            clearUserSession();
            header('Location: index.php?session=reset');
            exit;
        }

        $uid = (int)($_SESSION['user_id'] ?? 0);
        if ($uid > 0 && user_is_account_removed($dbh, $uid)) {
            redirectUserLoginDeleted();
        }
        if (user_session_login_was_deleted($dbh)) {
            redirectUserLoginDeleted();
        }
        if ($uid > 0 && user_is_account_deactivated($dbh, $uid)) {
            redirectUserLoginDeactivated();
        }
    } catch (Throwable $e) {
        // keep auth flow resilient if session validation fails unexpectedly
    }

    try {
        bumpUserLastSeenThrottled();
    } catch (Throwable $e) {
        // ignore presence update failures
    }

    try {
        require_once __DIR__ . '/staff_publisher_access.php';
        staff_pub_enforce_allowed_page();
    } catch (Throwable $e) {
        // ignore staff page guard failures
    }
}

function bumpUserLastSeenThrottled(int $minIntervalSeconds = 20): void
{
    $uid = (int)($_SESSION['user_id'] ?? 0);
    if ($uid <= 0) return;

    $lastPing = (int)($_SESSION['__last_seen_ping_ts'] ?? 0);
    if ($lastPing > 0 && (time() - $lastPing) < $minIntervalSeconds) return;

    require_once __DIR__ . '/../controller.php';
    $controller = new Controller();
    $dbh = $controller->pdo();

    $st = $dbh->prepare("UPDATE users SET last_seen = NOW() WHERE id = :id LIMIT 1");
    $st->execute([':id' => $uid]);

    $_SESSION['__last_seen_ping_ts'] = time();
}

function setUserSession(array $user): void
{
    session_regenerate_id(true);

    if (function_exists('staff_pub_clear_session_flags')) {
        staff_pub_clear_session_flags();
    } else {
        unset(
            $_SESSION['staff_publisher_mode'],
            $_SESSION['staff_account_id'],
            $_SESSION['staff_org_id'],
            $_SESSION['staff_readonly'],
            $_SESSION['staff_display_name']
        );
    }

    $userId = (int)($user['id'] ?? 0);
    try {
        require_once __DIR__ . '/publisher_accounts_load.php';
        require_once __DIR__ . '/../controller.php';
        $controller = new Controller();
        $fresh = publisher_session_load_user_row($controller->pdo(), $userId);
        if ($fresh) {
            $user = array_merge($user, $fresh);
            $userId = (int)($user['id'] ?? 0);
        }
    } catch (Throwable $e) {
        // keep provided login payload if DB reload fails
    }

    $_SESSION['user_id']          = $userId;
    $_SESSION['user_login']       = trim((string)($user['username'] ?? ''));
    $_SESSION['user_email']       = trim((string)($user['email'] ?? ''));
    $_SESSION['user_friend_code'] = trim((string)($user['friend_code'] ?? ''));
    $_SESSION['user_name']        = (string)($user['name'] ?? '');
    $_SESSION['user_image']       = (string)($user['image'] ?? 'default.jpg');
    $_SESSION['user_role']        = (int)($user['role'] ?? 0);
    $_SESSION['user_status']      = (int)($user['status'] ?? 1);
    $_SESSION['user_account_kind'] = strtolower(trim((string)($user['account_kind'] ?? 'personal')));

    unset($_SESSION['portal_staff_role_label']);
    $portalStaffRole = trim((string)($user['portal_staff_role_label'] ?? ''));
    if ($portalStaffRole === '' && $userId > 0) {
        require_once __DIR__ . '/account_display_helpers.php';
        if (function_exists('account_portal_staff_role_label_from_linked_user')) {
            try {
                require_once __DIR__ . '/../controller.php';
                $portalStaffRole = account_portal_staff_role_label_from_linked_user(
                    (new Controller())->pdo(),
                    $userId
                );
            } catch (Throwable $e) {
                $portalStaffRole = '';
            }
        }
    }
    if ($portalStaffRole !== '') {
        $_SESSION['portal_staff_role_label'] = $portalStaffRole;
    }

    $friendCode = strtoupper(trim((string)($user['friend_code'] ?? '')));
    $isPublisher = strtolower(trim((string)($user['account_kind'] ?? ''))) === 'publisher'
        || str_starts_with($friendCode, 'PUB-')
        || trim((string)($user['publisher_category'] ?? '')) !== '';

    if ($isPublisher) {
        $_SESSION['user_account_kind'] = 'publisher';
        try {
            require_once __DIR__ . '/publisher_accounts_load.php';
            require_once __DIR__ . '/../controller.php';
            $dbh = (new Controller())->pdo();
            publisher_repair_user_as_publisher(
                $dbh,
                $userId,
                trim((string)($user['publisher_category'] ?? ''))
            );
            publisher_session_bind_owner($dbh, $userId);
        } catch (Throwable $e) {
            $_SESSION['session_user_id'] = $userId;
            $_SESSION['publisher_session_user_id'] = $userId;
            $_SESSION['publisher_session_owner'] = 1;
        }
    } else {
        try {
            require_once __DIR__ . '/publisher_accounts_load.php';
            publisher_session_clear_identity();
        } catch (Throwable $e) {
            // ignore
        }
        $_SESSION['session_user_id'] = $userId;
        $_SESSION['user_account_kind'] = 'personal';
    }

    $_SESSION['csrf_token']       = bin2hex(random_bytes(32));

    try {
        require_once __DIR__ . '/../controller.php';
        $controller = new Controller();
        ensureUserSessionRecord((int)($_SESSION['user_id'] ?? 0), $controller->pdo());
    } catch (Throwable $e) {
        // ignore session table failures during login
    }

    app_session_login_mark();
}

function clearUserSession(): void
{
    try {
        require_once __DIR__ . '/staff_publisher_access.php';
        staff_pub_clear_session_flags();
    } catch (Throwable $e) {
        // ignore
    }

    try {
        require_once __DIR__ . '/publisher_accounts_load.php';
        publisher_session_clear_identity();
    } catch (Throwable $e) {
        // ignore
    }

    $_SESSION = [];

    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"], $params["secure"], $params["httponly"]
        );
    }

    session_destroy();
}

function myUserId(): int
{
    if (function_exists('publisher_session_canonical_user_id')) {
        return publisher_session_canonical_user_id();
    }
    return (int)($_SESSION['user_id'] ?? 0);
}

function userUsername(): string
{
    return trim((string)($_SESSION['user_login'] ?? ''));
}

function userEmail(): string
{
    return trim((string)($_SESSION['user_email'] ?? ''));
}

function myUserEmail(): string
{
    return userEmail();
}

function userFriendCode(): string
{
    return trim((string)($_SESSION['user_friend_code'] ?? ''));
}

function userRoleId(): int
{
    return (int)($_SESSION['user_role'] ?? 0);
}

function myUserName(): string
{
    return trim((string)($_SESSION['user_name'] ?? ''));
}

function myUserRoleId(): int
{
    return (int)($_SESSION['user_role'] ?? 0);
}
