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

function admin_linked_apply_session_cookie_path(): void
{
    static $applied = false;
    if ($applied || session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $applied = true;

    $path = admin_linked_web_base_path();
    if ($path === '') {
        $path = '/';
    }

    $lifetime = app_session_lifetime_seconds();
    @ini_set('session.gc_maxlifetime', (string)$lifetime);

    $params = session_get_cookie_params();
    session_set_cookie_params(
        $lifetime,
        $path,
        (string)($params['domain'] ?? ''),
        (bool)($params['secure'] ?? false),
        (bool)($params['httponly'] ?? true)
    );
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

    if ($wasActive) {
        session_write_close();
    }

    admin_linked_apply_session_cookie_path();
    session_name('BUSINESS_ONLY_ADMIN');
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    try {
        return $fn();
    } finally {
        session_write_close();
        session_name($previousName !== '' ? $previousName : 'BUSINESS_ONLY_USER');
        if ($previousId !== '') {
            session_id($previousId);
        }
        if ($wasActive || session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
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

}
