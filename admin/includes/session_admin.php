<?php
// /Business_only3/admin/includes/session_admin.php
declare(strict_types=1);

require_once __DIR__ . '/role_helpers.php';
require_once __DIR__ . '/app_session_lifetime_load.php';

if (session_status() === PHP_SESSION_NONE) {
  $bootstrapLoad = __DIR__ . '/admin_linked_bootstrap_load.php';
  if (is_file($bootstrapLoad)) {
    require_once $bootstrapLoad;
    admin_linked_apply_session_cookie_path();
  }
  session_name('BUSINESS_ONLY_ADMIN');
  session_start();
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
      $params = session_get_cookie_params();
      setcookie(session_name(), '', time() - 42000, $params['path'] ?? '/', $params['domain'] ?? '', (bool)($params['secure'] ?? false), true);
    }
    session_destroy();
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
    sendNoCacheHeaders();

    if (empty($_SESSION['admin_id']) || empty($_SESSION['userRole'])) {
      header("Location: index.php");
      exit;
    }

    if (app_session_is_expired()) {
      clearAdminSession();
      app_session_redirect_with_expired('index.php');
    }

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
    session_regenerate_id(true);

    $_SESSION['admin_id'] = (int)($admin['idadmin'] ?? 0);
    $_SESSION['admin_login'] = (string)($admin['username'] ?? '');
    $_SESSION['admin_email'] = (string)($admin['email'] ?? '');
    $_SESSION['userRole'] = (int)($admin['role'] ?? 0);
    $_SESSION['admin_image'] = (string)($admin['image'] ?? 'default.jpg');
    $_SESSION['admin_friend_code'] = (string)($admin['friend_code'] ?? '');
    app_session_login_mark();
  }
}
