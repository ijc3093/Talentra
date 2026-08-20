<?php
/**
 * ==========================================================
 * ADMIN HEADER (GLOBAL SECURITY GATE)
 * File: /Business_only3/admin/includes/header.php
 * ==========================================================
 */

require_once __DIR__ . '/session_admin.php';
requireAdminLogin();

require_once __DIR__ . '/../controller.php';
require_once __DIR__ . '/role_helpers.php';

$controller = new Controller();
$dbh = $controller->pdo();

/* ==========================================================
   ✅ GLOBAL ACCOUNT GATE
========================================================== */
$currentPage = basename($_SERVER['PHP_SELF'] ?? '');

$adminId = (int)($_SESSION['admin_id'] ?? 0);
if ($adminId <= 0) {
  clearAdminSession();
  header("Location: index.php");
  exit;
}

$allowWhenForced = ['change-password.php', 'logout.php'];

$stGate = $dbh->prepare("
  SELECT status, force_password_change
  FROM admin
  WHERE idadmin = :id
  LIMIT 1
");
$stGate->execute([':id' => $adminId]);
$gate = $stGate->fetch(PDO::FETCH_ASSOC);

if (!$gate || (int)$gate['status'] !== 1) {
    clearAdminSession();
    header("Location: index.php");
    exit;
}

if ((int)$gate['force_password_change'] === 1 && !in_array($currentPage, $allowWhenForced, true)) {
    header("Location: change-password.php?force=1");
    exit;
}

/* ==========================================================
   ROLE MAP
========================================================== */
$roleMap = [
    1 => 'Admin',
    2 => 'Manager',
    3 => 'Gospel',
    4 => 'Staff'
];

/* ==========================================================
   LOAD ADMIN PROFILE (SAFE: by ID)
========================================================== */
$stmt = $dbh->prepare("
  SELECT idadmin, fullname, username, email, image, role
  FROM admin
  WHERE idadmin = :id
  LIMIT 1
");
$stmt->execute([':id' => $adminId]);
$user = $stmt->fetch(PDO::FETCH_OBJ);

$adminLogin  = $user->fullname ?? '';
$adminRoleId = (int)($user->role ?? 1);
$roleName    = $roleMap[$adminRoleId] ?? 'Admin';

$rawRoleId    = (int)($_SESSION['userRole'] ?? 0);
$displayRole  = ucfirst(roleNameRaw($dbh, $rawRoleId));
$baseRole     = baseRoleName($dbh, $rawRoleId);

/* ==========================================================
   SAFE HTML ESCAPER
========================================================== */
if (!function_exists('h')) {
    function h(string $s): string {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }
}

/* ==========================================================
   AVATAR HELPERS (file avatars + initials fallback)
   (Defined once here to avoid redeclare)
========================================================== */
if (!function_exists('avatar_initials')) {
    function avatar_initials(string $name): string {
        $name = trim(preg_replace('/\s+/', ' ', $name) ?? $name);
        if ($name === '') return '??';

        $name = str_replace(['_', '.', '-', '@'], ' ', $name);
        $parts = array_values(array_filter(explode(' ', $name), fn($p)=>trim($p) !== ''));

        if (!$parts) return '??';

        $first = mb_strtoupper(mb_substr($parts[0], 0, 1));
        $second = '';

        if (count($parts) > 1) {
            $second = mb_strtoupper(mb_substr($parts[count($parts)-1], 0, 1));
        } else {
            $second = mb_strtoupper(mb_substr($parts[0], 1, 1));
        }

        $ini = trim($first.$second);
        return $ini !== '' ? $ini : '??';
    }
}

if (!function_exists('avatar_short_name')) {
    function avatar_short_name(string $name): string {
        $name = trim(preg_replace('/\s+/', ' ', $name) ?? $name);
        if ($name === '') return 'Admin';

        $name = str_replace(['_', '.', '-', '@'], ' ', $name);
        $parts = array_values(array_filter(explode(' ', $name), fn($p)=>trim($p) !== ''));

        if (!$parts) return 'Admin';

        $first = $parts[0];
        if (mb_strlen($first) > 10) $first = mb_substr($first, 0, 10) . '…';
        return $first;
    }
}

if (!function_exists('avatar_color')) {
    function avatar_color(string $key): string {
        $key = strtolower(trim($key));
        $hash = crc32($key);
        $palette = ['#2563eb','#7c3aed','#db2777','#ea580c','#16a34a','#0f766e','#0891b2','#475569'];
        return $palette[$hash % count($palette)];
    }
}

if (!function_exists('render_avatar_html')) {
    /**
     * Render avatar:
     * - if $imgUrl exists -> <img>
     * - else -> light circle + blue initials (MO style)
     */
    function render_avatar_html(string $label, string $key, ?string $imgUrl, int $size = 50): string {
        $sz = (int)$size;
        $wrap = "width:{$sz}px;height:{$sz}px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;flex:0 0 {$sz}px;";
        $txt  = "font-weight:800;color:#1d4ed8;font-size:" . max(12,(int)round($sz*0.38)) . "px;letter-spacing:.5px;";

        $imgUrl = $imgUrl ? trim($imgUrl) : '';
        if ($imgUrl !== '') {
            return '<img src="'.h($imgUrl).'" style="'.$wrap.'object-fit:cover;border:2px solid rgba(255,255,255,.35);" alt="">';
        }

        $ini = avatar_initials($label);

        // MO-style light background + border
        $bg = '#eaf0ff';
        $border = '#bcd0ff';

        return '<div style="'.$wrap.'background:'.$bg.';border:3px solid '.$border.';"><span style="'.$txt.'">'.h($ini).'</span></div>';
    }
}

/* ==========================================================
   AVATAR (FILE) - IMPORTANT: start as EMPTY so initials show
========================================================== */
$avatarWeb = ''; // ✅ empty means "use initials"

if ($user && !empty($user->image)) {
    $imgPath = __DIR__ . '/../images/' . $user->image; // ✅ correct path
    if (file_exists($imgPath)) {
        $avatarWeb = '../images/' . $user->image;
    }
}

$displayName = trim((string)($user->fullname ?? ''));
if ($displayName === '') $displayName = trim((string)($user->username ?? 'Admin'));

$shortName    = avatar_short_name($displayName);
$displayEmail = trim((string)($user->email ?? ''));

// stable key for color hashing
$avatarKey = trim((string)($user->username ?? (string)$adminId));

// Shared Azia logo + header (same as dashboard) for every page that includes header.php
require_once __DIR__ . '/admin_chrome.php';
$pageLabel = preg_replace('/\.php$/i', '', admin_layout_current_page()) ?: 'Admin';
$pageLabel = ucwords(str_replace(['-', '_'], ' ', (string)$pageLabel));
$chromeLabels = [
    'overview' => 'Overview',
    'device_activity' => 'Device Activity',
    'login_activity' => 'Login Activity',
    'audience' => 'Audience',
    'trends' => 'Trends',
    'userlist' => 'User List',
    'adminroles' => 'Roles & Accounts',
    'publisher_requests' => 'Publisher Requests',
    'security-log' => 'Security Logs',
    'feedback' => 'Help',
    'notification' => 'Notifications',
    'change-password' => 'Change Password',
    'mailbox' => 'Mailbox',
    'register' => 'Register',
    'roleslist' => 'Roles & Permissions',
    'compose' => 'Compose',
];
$key = strtolower(str_replace('.php', '', admin_layout_current_page()));
if (isset($chromeLabels[$key])) {
    $pageLabel = $chromeLabels[$key];
}

admin_chrome_logo();
$adminChromePageIntro = (isset($adminChromePageIntro) && is_array($adminChromePageIntro))
    ? $adminChromePageIntro
    : null;
admin_chrome_header($pageLabel, $adminChromePageIntro);
?>
<script>
document.body && document.body.classList.add('azia-admin');
window.addEventListener("pageshow", function (event) {
  if (event.persisted) window.location.reload();
});
</script>
