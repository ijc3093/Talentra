<?php
// /Business_only3/admin/includes/session_admin.php
declare(strict_types=1);

require_once __DIR__ . '/role_helpers.php';
require_once __DIR__ . '/app_session_lifetime_load.php';

/** Current admin session cookie name (v2 — avoids clashing with old path-scoped cookies). */
if (!defined('ADMIN_SESSION_NAME')) {
  define('ADMIN_SESSION_NAME', 'TALENTRA_ADMIN');
}

if (!function_exists('admin_expire_old_session_cookies')) {
  /**
   * Remove pre-v2 BUSINESS_ONLY_ADMIN cookies that still sit on /myStoryBook or /MyStoryBook.
   * Those duplicates make PHP pick an empty session and look like an instant sign-out.
   */
  function admin_expire_old_session_cookies(): void {
    static $done = false;
    if ($done || headers_sent()) {
      return;
    }
    $done = true;

    if (!isset($_COOKIE['BUSINESS_ONLY_ADMIN'])) {
      return;
    }

    if (!function_exists('admin_linked_expire_session_cookie_on_paths')) {
      return;
    }

    $paths = ['/'];
    if (function_exists('admin_linked_legacy_session_cookie_paths')) {
      $paths = array_values(array_unique(array_merge($paths, admin_linked_legacy_session_cookie_paths())));
    }

    admin_linked_expire_session_cookie_on_paths('BUSINESS_ONLY_ADMIN', $paths);
  }
}

if (!function_exists('admin_emit_session_cookie')) {
  /**
   * Force-send the live session cookie. Uses a session cookie (Expires=0 / no Max-Age)
   * so browsers keep it reliably across refresh; server still enforces the 12h cap.
   */
  function admin_emit_session_cookie(): void {
    if (headers_sent()) {
      return;
    }
    $id = session_id();
    if ($id === '') {
      return;
    }
    $name = session_name();
    $params = session_get_cookie_params();
    $domain = (string)($params['domain'] ?? '');
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');

    if (PHP_VERSION_ID >= 70300) {
      setcookie($name, $id, [
        'expires' => 0,
        'path' => '/',
        'domain' => $domain,
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
      ]);
    } else {
      setcookie($name, $id, 0, '/', $domain, $secure, true);
    }
  }
}

if (!function_exists('admin_session_bootstrap')) {
  function admin_session_bootstrap(): void {
    $bootstrapLoad = __DIR__ . '/admin_linked_bootstrap_load.php';
    if (is_file($bootstrapLoad)) {
      require_once $bootstrapLoad;
    }

    $lifetime = app_session_lifetime_seconds();
    @ini_set('session.gc_maxlifetime', (string)$lifetime);
    @ini_set('session.cookie_lifetime', '0');
    @ini_set('session.use_only_cookies', '1');
    // Keep strict mode off: a missing session file after GC must not reject the cookie id.

    $savePath = '/Applications/MAMP/tmp/php';
    if (is_dir($savePath) && is_writable($savePath)) {
      session_save_path($savePath);
    }

    session_name(ADMIN_SESSION_NAME);
    if (function_exists('admin_linked_apply_session_cookie_path')) {
      admin_linked_apply_session_cookie_path();
    }
    if (session_status() !== PHP_SESSION_ACTIVE) {
      session_start();
    }

    admin_expire_old_session_cookies();
  }
}

if (session_status() === PHP_SESSION_NONE) {
  admin_session_bootstrap();
} elseif (session_name() !== ADMIN_SESSION_NAME) {
  if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
  }
  admin_session_bootstrap();
}

if (!function_exists('sendNoCacheHeaders')) {
  function sendNoCacheHeaders(): void {
    if (headers_sent()) return;
    header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
    header("Pragma: no-cache");
    header("Expires: 0");
  }
}

if (!function_exists('clearAdminSession')) {
  function clearAdminSession(): void {
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
      $name = session_name();
      if (PHP_VERSION_ID >= 70300) {
        setcookie($name, '', [
          'expires' => time() - 42000,
          'path' => '/',
          'domain' => '',
          'secure' => false,
          'httponly' => true,
          'samesite' => 'Lax',
        ]);
      } else {
        setcookie($name, '', time() - 42000, '/', '', false, true);
      }
      if (function_exists('admin_linked_expire_session_cookie_on_paths')
          && function_exists('admin_linked_legacy_session_cookie_paths')) {
        $paths = array_values(array_unique(array_merge(['/'], admin_linked_legacy_session_cookie_paths())));
        admin_linked_expire_session_cookie_on_paths($name, $paths);
        admin_linked_expire_session_cookie_on_paths('BUSINESS_ONLY_ADMIN', $paths);
      }
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
      session_destroy();
    }
  }
}

if (!function_exists('currentAdminScript')) {
  function currentAdminScript(): string {
    return basename(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '');
  }
}

if (!function_exists('fetchAdminSessionRow')) {
  function fetchAdminSessionRow(PDO $dbh, int $adminId): ?array {
    $st = $dbh->prepare("
      SELECT idadmin, username, email, role, status,
             COALESCE(force_password_change,0) AS force_password_change,
             COALESCE(locked_until,'') AS locked_until
      FROM admin
      WHERE idadmin = :id
      LIMIT 1
    ");
    $st->execute([':id' => $adminId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
  }
}

if (!function_exists('requireAdminLogin')) {
  function requireAdminLogin(): void {
    // After HTML has started, Location redirects cannot work — avoid wiping the session.
    if (headers_sent()) {
      if (empty($_SESSION['admin_id']) || empty($_SESSION['userRole'])) {
        echo '<script>window.location.href="index.php";</script>';
        exit;
      }
      return;
    }

    sendNoCacheHeaders();

    if (empty($_SESSION['admin_id']) || empty($_SESSION['userRole'])) {
      header("Location: index.php");
      exit;
    }

    if (app_session_is_expired()) {
      clearAdminSession();
      app_session_redirect_with_expired('index.php');
    }

    if (function_exists('app_session_touch_activity')) {
      app_session_touch_activity();
    }

    // Refresh cookie so it survives browser refresh.
    admin_emit_session_cookie();

    $adminId = (int)$_SESSION['admin_id'];
    $rawRoleId = (int)$_SESSION['userRole'];

    if ($adminId <= 0 || $rawRoleId <= 0) {
      clearAdminSession();
      header("Location: index.php");
      exit;
    }

    $dbh = adminDbh();

    if (!isActiveRole($dbh, $rawRoleId)) {
      clearAdminSession();
      header("Location: index.php?inactiveRole=1");
      exit;
    }

    $row = fetchAdminSessionRow($dbh, $adminId);
    if (!$row) {
      clearAdminSession();
      header("Location: index.php");
      exit;
    }

    if ((int)$row['status'] !== 1) {
      clearAdminSession();
      header("Location: index.php?inactive=1");
      exit;
    }

    if (!empty($row['locked_until']) && strtotime((string)$row['locked_until']) > time()) {
      clearAdminSession();
      header("Location: index.php?locked=1");
      exit;
    }

    $_SESSION['admin_login'] = (string)$row['username'];
    $_SESSION['admin_email'] = (string)$row['email'];
    $_SESSION['userRole'] = (int)$row['role'];

    $script = currentAdminScript();
    $allowedDuringForce = ['change-password.php', 'logout.php', 'index.php'];

    if ((int)$row['force_password_change'] === 1 && !in_array($script, $allowedDuringForce, true)) {
      header("Location: change-password.php?force=1");
      exit;
    }
  }
}

if (!function_exists('setAdminSession')) {
  function setAdminSession(array $admin): void {
    // Keep old session data until the new id is established (avoid refresh races).
    if (session_status() === PHP_SESSION_ACTIVE) {
      session_regenerate_id(false);
    }

    $_SESSION['admin_id'] = (int)($admin['idadmin'] ?? 0);
    $_SESSION['admin_login'] = (string)($admin['username'] ?? '');
    $_SESSION['admin_email'] = (string)($admin['email'] ?? '');
    $_SESSION['userRole'] = (int)($admin['role'] ?? 0);
    $_SESSION['admin_image'] = (string)($admin['image'] ?? 'default.jpg');
    $_SESSION['admin_friend_code'] = (string)($admin['friend_code'] ?? '');
    app_session_login_mark();
    admin_emit_session_cookie();
  }
}
