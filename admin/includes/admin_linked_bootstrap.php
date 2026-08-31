<?php
declare(strict_types=1);

if (!function_exists('admin_linked_web_base_path')) {

function admin_linked_web_base_path(): string
{
    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    if ($script === '') {
        return '/';
    }

    if (preg_match('#^(.*)/admin(?:/|$)#i', $script, $m)) {
        $base = rtrim((string)$m[1], '/');
        return $base !== '' ? $base : '/';
    }
    if (preg_match('#^(.*)/public_user(?:/|$)#i', $script, $m)) {
        $base = rtrim((string)$m[1], '/');
        return $base !== '' ? $base : '/';
    }
    if (preg_match('#^(.*)/organization(?:/|$)#i', $script, $m)) {
        $base = rtrim((string)$m[1], '/');
        return $base !== '' ? $base : '/';
    }

    return '/';
}

function admin_linked_absolute_app_url(string $relativePath): string
{
    $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
    $relativePath = preg_replace('#^\.\./#', '', $relativePath) ?? $relativePath;

    $base = admin_linked_web_base_path();
    if ($base === '/') {
        return '/' . $relativePath;
    }

    return rtrim($base, '/') . '/' . $relativePath;
}

/**
 * Legacy cookie paths that must be expired.
 * Cookie paths are case-sensitive: /myStoryBook and /MyStoryBook are different to the browser
 * even when the OS filesystem is not — which caused admin "instant sign-out" on nav.
 *
 * @return list<string>
 */
function admin_linked_legacy_session_cookie_paths(): array
{
    $paths = [];

    $fromScript = admin_linked_web_base_path();
    if ($fromScript !== '' && $fromScript !== '/') {
        $paths[] = $fromScript;
        $paths[] = '/' . strtolower(ltrim($fromScript, '/'));
    }

    $docRoot = realpath((string)($_SERVER['DOCUMENT_ROOT'] ?? ''));
    $appRoot = realpath(dirname(__DIR__, 2));
    if ($docRoot && $appRoot && strpos($appRoot, $docRoot) === 0) {
        $rel = str_replace('\\', '/', substr($appRoot, strlen($docRoot)));
        if ($rel !== '' && $rel !== '/') {
            if ($rel[0] !== '/') {
                $rel = '/' . $rel;
            }
            $paths[] = $rel;
            $paths[] = '/' . strtolower(ltrim($rel, '/'));
            // Common typed variant on macOS/MAMP (folder myStoryBook, URL MyStoryBook).
            if (preg_match('#^/([a-z])(.*)$#', $rel, $m)) {
                $paths[] = '/' . strtoupper($m[1]) . $m[2];
            }
        }
    }

    return array_values(array_unique(array_filter($paths, static function ($p) {
        return is_string($p) && $p !== '' && $p !== '/';
    })));
}

function admin_linked_expire_session_cookie_on_paths(string $cookieName, array $paths): void
{
    if ($cookieName === '' || headers_sent()) {
        return;
    }

    $params = session_get_cookie_params();
    $domain = (string)($params['domain'] ?? '');
    $secure = !empty($params['secure']);

    foreach ($paths as $path) {
        if (!is_string($path) || $path === '') {
            continue;
        }
        // Use header(..., false) so multiple Set-Cookie lines for the same name
        // (different paths) are kept. setcookie() + session_start() can collapse them.
        $parts = [
            rawurlencode($cookieName) . '=deleted',
            'Expires=Thu, 01 Jan 1970 00:00:00 GMT',
            'Max-Age=0',
            'Path=' . $path,
            'HttpOnly',
            'SameSite=Lax',
        ];
        if ($domain !== '') {
            $parts[] = 'Domain=' . $domain;
        }
        if ($secure) {
            $parts[] = 'Secure';
        }
        header('Set-Cookie: ' . implode('; ', $parts), false);
    }
}

function admin_linked_apply_session_cookie_path(): void
{
    static $paramsApplied = false;
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $lifetime = app_session_lifetime_seconds();
    @ini_set('session.gc_maxlifetime', (string)$lifetime);

    if ($paramsApplied) {
        return;
    }
    $paramsApplied = true;

    // Keep the session cookie across refresh. Sign Out is the only logout path.
    $cookieLifetime = app_session_lifetime_seconds();
    $path = '/';

    $params = session_get_cookie_params();
    $domain = (string)($params['domain'] ?? '');
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');

    if (PHP_VERSION_ID >= 70300) {
        session_set_cookie_params([
            'lifetime' => $cookieLifetime,
            'path' => $path,
            'domain' => $domain,
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    } else {
        session_set_cookie_params($cookieLifetime, $path, $domain, $secure, true);
    }
}

/** @param callable():mixed $fn */
function admin_linked_with_admin_session(callable $fn)
{
    if (headers_sent()) {
        return null;
    }

    $previousName = session_name();
    $previousId = session_id();
    $wasActive = session_status() === PHP_SESSION_ACTIVE;

    // Never abandon an active public_user session if we cannot restore its id.
    if ($wasActive && $previousId === '') {
        return null;
    }

    if ($wasActive) {
        session_write_close();
    }

    admin_linked_apply_session_cookie_path();
    session_name(defined('ADMIN_SESSION_NAME') ? ADMIN_SESSION_NAME : 'TALSORA_ADMIN');
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    try {
        return $fn();
    } finally {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        session_name($previousName !== '' ? $previousName : 'BUSINESS_ONLY_USER');
        if ($previousId !== '') {
            session_id($previousId);
        }
        if ($wasActive || session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
    }
}

function admin_linked_mark_portal_intent(string $portal): void
{
    $portal = strtolower(trim($portal));
    if (!in_array($portal, ['personal', 'publisher', 'organization'], true)) {
        return;
    }

    $_SESSION['admin_linked_portal_kind'] = $portal;
    $_SESSION['admin_linked_portal_expires'] = time() + 900;
}

function admin_linked_signing_key(): string
{
    if (!defined('APP_SIGNING_KEY')) {
        $cfg = dirname(__DIR__, 2) . '/config.php';
        if (is_file($cfg)) {
            require_once $cfg;
        }
    }

    if (defined('APP_SIGNING_KEY')) {
        return (string)APP_SIGNING_KEY;
    }

    return 'talentra-admin-linked-fallback';
}

function admin_linked_portal_handoff_query(int $adminId, string $portal): string
{
    $portal = strtolower(trim($portal));
    if ($adminId <= 0 || !in_array($portal, ['personal', 'publisher', 'organization'], true)) {
        return '';
    }

    $ts = time();
    $payload = $adminId . '|' . $portal . '|' . $ts;
    $sig = hash_hmac('sha256', $payload, admin_linked_signing_key());

    return http_build_query([
        'admin_linked' => $portal,
        'aid' => $adminId,
        'ts' => $ts,
        'sig' => $sig,
    ]);
}

function admin_linked_absolute_portal_url(string $relativePath, int $adminId, string $portal): string
{
    $portal = strtolower(trim($portal));
    if ($portal === 'personal' || $portal === 'publisher') {
        $relativePath = 'public_user/linked_portal_enter.php';
    } elseif ($portal === 'organization') {
        $relativePath = 'organization/linked_portal_enter.php';
    }

    $url = admin_linked_absolute_app_url($relativePath);
    $query = admin_linked_portal_handoff_query($adminId, $portal);
    if ($query === '') {
        return $url;
    }

    return $url . (strpos($url, '?') !== false ? '&' : '?') . $query;
}

/** @return array{admin_id:int,kind:string}|null */
function admin_linked_verify_portal_handoff(): ?array
{
    $portal = strtolower(trim((string)($_GET['admin_linked'] ?? '')));
    $adminId = (int)($_GET['aid'] ?? 0);
    $ts = (int)($_GET['ts'] ?? 0);
    $sig = (string)($_GET['sig'] ?? '');

    if (!in_array($portal, ['personal', 'publisher', 'organization'], true)) {
        return null;
    }
    if ($adminId <= 0 || $ts <= 0 || $sig === '') {
        return null;
    }
    if ($ts + 900 < time()) {
        return null;
    }

    $payload = $adminId . '|' . $portal . '|' . $ts;
    $expected = hash_hmac('sha256', $payload, admin_linked_signing_key());
    if (!hash_equals($expected, $sig)) {
        return null;
    }

    return ['admin_id' => $adminId, 'kind' => $portal];
}

/** @return array{admin_id:int,kind:string}|null */
function admin_linked_resolve_portal_intent(): ?array
{
    $handoff = admin_linked_verify_portal_handoff();
    if ($handoff) {
        return $handoff;
    }

    return admin_linked_read_admin_portal_intent();
}

/** @return array{admin_id:int,kind:string}|null */
function admin_linked_read_admin_portal_intent(): ?array
{
    return admin_linked_with_admin_session(static function (): ?array {
        $adminId = (int)($_SESSION['admin_id'] ?? 0);
        if ($adminId <= 0 || trim((string)($_SESSION['admin_login'] ?? '')) === '') {
            return null;
        }
        if ((int)($_SESSION['userRole'] ?? 0) <= 0) {
            return null;
        }

        $kind = strtolower(trim((string)($_SESSION['admin_linked_portal_kind'] ?? '')));
        $expires = (int)($_SESSION['admin_linked_portal_expires'] ?? 0);
        if ($expires > 0 && $expires < time()) {
            unset($_SESSION['admin_linked_portal_kind'], $_SESSION['admin_linked_portal_expires']);
            return null;
        }

        if (!in_array($kind, ['personal', 'publisher', 'organization'], true)) {
            return null;
        }

        return ['admin_id' => $adminId, 'kind' => $kind];
    });
}

function admin_linked_sync_public_user_from_admin_intent(?PDO $dbh = null): bool
{
    $intent = admin_linked_resolve_portal_intent();
    if (!$intent || !in_array($intent['kind'], ['personal', 'publisher'], true)) {
        return !empty($_SESSION['user_id']) && !empty($_SESSION['user_login']);
    }

    require_once __DIR__ . '/admin_linked_portal_load.php';

    if (!$dbh instanceof PDO) {
        require_once dirname(__DIR__) . '/controller.php';
        $dbh = (new Controller())->pdo();
    }

    admin_linked_ensure_provisioned($dbh, $intent['admin_id']);
    $targetUser = admin_linked_portal_user($dbh, $intent['admin_id'], $intent['kind']);
    if (!$targetUser) {
        return !empty($_SESSION['user_id']) && !empty($_SESSION['user_login']);
    }

    $targetId = (int)($targetUser['id'] ?? 0);
    $currentId = (int)($_SESSION['user_id'] ?? 0);
    $needsSwitch = $currentId !== $targetId;

    if ($intent['kind'] === 'publisher') {
        $sessionKind = strtolower(trim((string)($_SESSION['user_account_kind'] ?? '')));
        $owner = (int)($_SESSION['publisher_session_owner'] ?? 0);
        if ($currentId === $targetId && ($sessionKind !== 'publisher' || $owner !== 1)) {
            $needsSwitch = true;
        }
    } elseif ($intent['kind'] === 'personal' && $currentId === $targetId) {
        $sessionKind = strtolower(trim((string)($_SESSION['user_account_kind'] ?? '')));
        if ($sessionKind === 'publisher') {
            $needsSwitch = true;
        }
    }

    if (!$needsSwitch && $currentId > 0) {
        return true;
    }

    if (!function_exists('setUserSession')) {
        require_once dirname(__DIR__, 2) . '/public_user/includes/session_user.php';
    }

    setUserSession($targetUser);

    if ($intent['kind'] === 'publisher' && $targetId > 0) {
        require_once dirname(__DIR__, 2) . '/public_user/includes/publisher_accounts_load.php';
        publisher_repair_user_as_publisher(
            $dbh,
            $targetId,
            trim((string)($targetUser['publisher_category'] ?? 'news'))
        );
        publisher_session_bind_owner($dbh, $targetId);
    }

    return !empty($_SESSION['user_id']) && !empty($_SESSION['user_login']);
}

function admin_linked_try_bootstrap_public_user(?PDO $dbh = null): bool
{
    return admin_linked_sync_public_user_from_admin_intent($dbh);
}

function admin_linked_try_bootstrap_org(?PDO $dbh = null): bool
{
    $intent = admin_linked_resolve_portal_intent();
    if (!$intent || $intent['kind'] !== 'organization') {
        return !empty($_SESSION['org_auth']) && !empty($_SESSION['org_account_id']);
    }

    require_once __DIR__ . '/admin_linked_portal_load.php';

    if (!$dbh instanceof PDO) {
        require_once dirname(__DIR__) . '/controller.php';
        $dbh = (new Controller())->pdo();
    }

    return admin_linked_start_org_session($dbh, $intent['admin_id']);
}

/**
 * Platform admin (role 1) may open organization pages without a separate org login.
 * Used for marketplace oversight (e.g. inventory → products_detail).
 *
 * @return array{admin_id:int,role:int}|null
 */
function admin_linked_platform_admin_snapshot(): ?array
{
    return admin_linked_with_admin_session(static function (): ?array {
        $adminId = (int)($_SESSION['admin_id'] ?? 0);
        $role = (int)($_SESSION['userRole'] ?? 0);
        if ($adminId <= 0 || $role !== 1) {
            return null;
        }
        if (trim((string)($_SESSION['admin_login'] ?? '')) === '') {
            return null;
        }
        return ['admin_id' => $adminId, 'role' => $role];
    });
}

function admin_linked_mark_org_admin_oversight(int $adminId): void
{
    if ($adminId <= 0) {
        return;
    }
    $_SESSION['admin_product_oversight'] = 1;
    $_SESSION['admin_product_oversight_admin_id'] = $adminId;
}

function admin_linked_is_org_admin_oversight(): bool
{
    return !empty($_SESSION['admin_product_oversight'])
        && (int)($_SESSION['admin_product_oversight_admin_id'] ?? 0) > 0;
}

/**
 * Admin Inventory link → open product detail with admin authority (no org login).
 */
function admin_linked_product_oversight_enter_url(int $adminId, int $productId, string $from = 'sales'): string
{
    $productId = max(0, $productId);
    if ($productId <= 0) {
        return admin_linked_absolute_app_url('admin/inventory.php');
    }

    $from = strtolower(trim($from)) !== '' ? strtolower(trim($from)) : 'sales';

    return admin_linked_absolute_app_url('admin/open_product_detail.php')
        . '?' . http_build_query([
            'id' => $productId,
            'from' => $from,
        ]);
}

/**
 * @return array{admin_id:int,product_id:int}|null
 */
function admin_linked_verify_product_oversight_handoff(): ?array
{
    $adminId = (int)($_GET['aid'] ?? 0);
    $productId = (int)($_GET['pid'] ?? 0);
    $ts = (int)($_GET['ts'] ?? 0);
    $sig = (string)($_GET['sig'] ?? '');

    if ($adminId <= 0 || $productId <= 0 || $ts <= 0 || $sig === '') {
        return null;
    }
    if ($ts + 900 < time()) {
        return null;
    }

    $payload = $adminId . '|product_oversight|' . $productId . '|' . $ts;
    $expected = hash_hmac('sha256', $payload, admin_linked_signing_key());
    if (!hash_equals($expected, $sig)) {
        return null;
    }

    return ['admin_id' => $adminId, 'product_id' => $productId];
}

/**
 * Establish org PHPSESSID for platform-admin product oversight (no org login form).
 */
function admin_linked_establish_org_product_oversight(PDO $dbh, int $adminId, int $productId): bool
{
    if ($adminId <= 0 || $productId <= 0) {
        return false;
    }

    $productOrgId = 0;
    try {
        $st = $dbh->prepare('
            SELECT org_id
            FROM org_products
            WHERE id = :id AND COALESCE(is_deleted, 0) = 0
            LIMIT 1
        ');
        $st->execute([':id' => $productId]);
        $productOrgId = (int)($st->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        $productOrgId = 0;
    }

    return admin_linked_establish_org_oversight_session($dbh, $adminId, $productOrgId);
}

/**
 * Establish org PHPSESSID for platform-admin order oversight (no org login form).
 */
function admin_linked_establish_org_order_oversight(PDO $dbh, int $adminId, int $orderId): bool
{
    if ($adminId <= 0 || $orderId <= 0) {
        return false;
    }

    $orderOrgId = 0;
    try {
        $st = $dbh->prepare('
            SELECT org_id
            FROM org_orders
            WHERE id = :id
            LIMIT 1
        ');
        $st->execute([':id' => $orderId]);
        $orderOrgId = (int)($st->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        $orderOrgId = 0;
    }

    return admin_linked_establish_org_oversight_session($dbh, $adminId, $orderOrgId);
}

/**
 * Shared org-session bootstrap for admin marketplace oversight.
 */
function admin_linked_establish_org_oversight_session(PDO $dbh, int $adminId, int $orgId = 0): bool
{
    if ($adminId <= 0) {
        return false;
    }

    require_once __DIR__ . '/admin_linked_portal_load.php';
    require_once __DIR__ . '/admin_linked_accounts_load.php';

    admin_linked_ensure_provisioned($dbh, $adminId);

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    admin_linked_apply_session_cookie_path();
    session_name('PHPSESSID');
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $managerId = admin_linked_manager_id($dbh, $adminId);
    if ($managerId > 0) {
        $_SESSION['org_auth'] = 1;
        $_SESSION['org_account_type'] = 'manager';
        $_SESSION['org_account_id'] = $managerId;
        unset($_SESSION['org_publisher_user_id'], $_SESSION['org_member_id'], $_SESSION['org_role_id']);
        try {
            require_once dirname(__DIR__, 2) . '/organization/includes/org_publisher_access.php';
            if (function_exists('org_manager_is_registered_publisher')
                && org_manager_is_registered_publisher($dbh, $managerId)
                && function_exists('org_manager_apply_registered_publisher_login')) {
                org_manager_apply_registered_publisher_login($dbh, $managerId);
                if (function_exists('publisher_session_establish_for_manager')) {
                    publisher_session_establish_for_manager($dbh, $managerId);
                }
            }
        } catch (Throwable $e) {
            // Non-fatal for oversight.
        }
    } else {
        $_SESSION['org_auth'] = 1;
        $_SESSION['org_account_type'] = 'manager';
        $_SESSION['org_account_id'] = max(1, $adminId);
        unset($_SESSION['org_publisher_user_id'], $_SESSION['org_member_id'], $_SESSION['org_role_id']);
    }

    if ($orgId > 0) {
        $_SESSION['org_active_org_id'] = $orgId;
    }

    admin_linked_mark_org_admin_oversight($adminId);

    if (function_exists('app_session_login_mark')) {
        app_session_login_mark();
    }

    return !empty($_SESSION['org_auth']) && !empty($_SESSION['org_account_id']);
}

/**
 * If a platform admin is logged in, start (or reuse) an org manager session for oversight.
 */
function admin_linked_try_bootstrap_org_admin_oversight(?PDO $dbh = null): bool
{
    $snap = admin_linked_platform_admin_snapshot();
    if (!$snap) {
        return false;
    }

    require_once __DIR__ . '/admin_linked_portal_load.php';
    require_once __DIR__ . '/admin_linked_accounts_load.php';

    if (!$dbh instanceof PDO) {
        require_once dirname(__DIR__) . '/controller.php';
        $dbh = (new Controller())->pdo();
    }

    $adminId = (int)$snap['admin_id'];
    admin_linked_ensure_provisioned($dbh, $adminId);

    if (empty($_SESSION['org_auth']) || empty($_SESSION['org_account_id'])) {
        if (!admin_linked_start_org_session($dbh, $adminId)) {
            // Still allow platform admin oversight without a linked manager row.
            $_SESSION['org_auth'] = 1;
            $_SESSION['org_account_type'] = 'manager';
            $_SESSION['org_account_id'] = max(1, admin_linked_manager_id($dbh, $adminId) ?: $adminId);
            unset($_SESSION['org_publisher_user_id']);
        }
    }

    if (empty($_SESSION['org_auth']) || empty($_SESSION['org_account_id'])) {
        return false;
    }

    admin_linked_mark_org_admin_oversight($adminId);
    return true;
}

}
