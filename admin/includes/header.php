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
?>

<script>
window.addEventListener("pageshow", function (event) {
  if (event.persisted) window.location.reload();
});
</script>

<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Mailbox</title>

    <link href="../lib/font-awesome/css/font-awesome.css" rel="stylesheet">
    <link href="../lib/Ionicons/css/ionicons.css" rel="stylesheet">
    <link href="../lib/perfect-scrollbar/css/perfect-scrollbar.css" rel="stylesheet">
    <link href="../lib/summernote/summernote-bs4.css" rel="stylesheet">
    <link href="../lib/bootstrap/bootstrap.css" rel="stylesheet">
    <link rel="stylesheet" href="../css/shamcey.css">
    <?php require_once __DIR__ . '/admin_layout.php'; admin_layout_head_assets(); ?>

    <style>
      .note-modal-backdrop { z-index:10550!important; }
      .note-modal { z-index:10560!important; }
      .note-modal .modal-dialog { z-index:10570!important; }
      .modal-backdrop { z-index:10540!important; }
      .modal { z-index:10560!important; }

      .mailboxbody {
          position: absolute;
          top: 0;
          right: 250px;
          bottom: 0;
          padding: 5px;
          overflow-y: auto;
          display: none;
          z-index: 50;
          background-color: #dbd0d6;
      }

      @media (min-width: 992px) {
        .mailbox-right { width: 250px; }
      }

      .mailbox-right {
        position: absolute;
        padding: 10px;
        height: 100%;
        width: 233px;
        top: 0;
        right: 0;
        z-index: 40;
      }

      .inputgroup {
        position: relative;
        display: flex;
        width: 100%;
        padding: 5px;
      }

      .note-toolbar{
        position:sticky;
        top:0;
        z-index:20;
        background:#fff;
      }
      .note-editable{ max-height:180px; overflow-y:auto; }

      .mailboxlist{
        bottom: 0;
        position: absolute;
        top: 71px;
        left: 0;
        padding: 10px;
        right: 0;
        bottom: 0;
        overflow-y: auto;
      }

      .dropdown-profile .dropdown-link { display:flex; align-items:center; }
    </style>
  </head>

  <body>
    <div class="sh-logopanel">
      <a href="" class="sh-logo-text">Private App</a>
      <a id="navicon" href="" class="sh-navicon d-none d-xl-block"><i class="icon ion-navicon"></i></a>
      <a id="naviconMobile" href="" class="sh-navicon d-xl-none"><i class="icon ion-navicon"></i></a>
    </div>

    <div class="sh-headpanel">
      <div class="sh-headpanel-left">
        <a href="" class="sh-icon-link">
          <div><i class="icon ion-ios-folder-outline"></i><span>Directory</span></div>
        </a>
        <a href="" class="sh-icon-link">
          <div><i class="icon ion-ios-calendar-outline"></i><span>Events</span></div>
        </a>
        <a href="" class="sh-icon-link">
          <div><i class="icon ion-ios-gear-outline"></i><span>Settings</span></div>
        </a>
      </div>

      <div class="sh-headpanel-right">

        <div class="dropdown dropdown-notification">
          <a href="mailbox.php" data-toggle="dropdown" class="dropdown-link dropdown-link-notification">
            <i class="icon ion-ios-filing-outline tx-24"></i>
            <span id="chatBadge" style="display:none;position:absolute;top:1px;right:2px;background:red;color:#fff;border-radius:10px;padding:2px 6px;font-size:11px;font-weight:700;"></span>
          </a>
          <div class="dropdown-menu dropdown-menu-right">
            <div class="dropdown-menu-header">
              <label>Mailbox</label>
              <a href="">Mark All as Read</a>
            </div>
            <div class="media-list">
              <div class="media-list-footer">
                <a href="mailbox.php" class="tx-12"><i class="fa fa-angle-down mg-r-5"></i> Show All Messages</a>
              </div>
            </div>
          </div>
        </div>

        <div class="dropdown dropdown-notification">
          <a href="" data-toggle="dropdown" class="dropdown-link dropdown-link-notification">
            <i class="icon ion-ios-bell-outline tx-24"></i>
            <span id="notiBadge" style="display:none;position:absolute;top:1px;right:2px;background:red;color:#fff;border-radius:10px;padding:2px 6px;font-size:11px;font-weight:700;"></span>
          </a>
          <div class="dropdown-menu dropdown-menu-right">
            <div class="dropdown-menu-header">
              <label>Notifications</label>
              <a href="">Mark All as Read</a>
            </div>
            <div class="media-list">
              <div class="media-list-footer">
                <a href="notification.php" class="tx-12"><i class="fa fa-angle-down mg-r-5"></i> Show All Notifications</a>
              </div>
            </div>
          </div>
        </div>

        <div class="dropdown dropdown-profile">
          <a href="" data-toggle="dropdown" class="dropdown-link">
            <?php echo render_avatar_html($displayName, $avatarKey, $avatarWeb, 46); ?>
          </a>

          <div class="dropdown-menu dropdown-menu-right">
            <div class="media align-items-center">
              <?php echo render_avatar_html($displayName, $avatarKey, $avatarWeb, 56); ?>
              <div class="media-body" style="margin-left:12px;">
                <h6 class="tx-inverse tx-15 mg-b-5"><?php echo h($shortName); ?></h6>
                <p class="mg-b-0 tx-12"><?php echo h($displayEmail); ?></p>
              </div>
            </div>
            <hr>
            <ul class="dropdown-profile-nav">
              <li><a href=""><i class="icon ion-ios-person"></i> Edit Profile</a></li>
              <li><a href=""><i class="icon ion-ios-gear"></i> Settings</a></li>
              <li><a href=""><i class="icon ion-ios-download"></i> Downloads</a></li>
              <li><a href=""><i class="icon ion-ios-star"></i> Favorites</a></li>
              <li><a href="logout.php"><i class="icon ion-power"></i> Sign Out</a></li>
            </ul>
          </div>
        </div>

      </div>
    </div>

<script>
(function(){
  function setBadge(el, n){
    if(!el) return;
    n = parseInt(n||0,10);
    if(n>0){
      el.style.display='inline-block';
      el.textContent = n>99?'99+':n;
    }else{
      el.style.display='none';
    }
  }

  async function pollNotifications(){
    try{
      const r = await fetch('ajax/notifications_poll.php',{cache:'no-store'});
      const d = await r.json();
      if(d && d.ok) setBadge(document.getElementById('notiBadge'), d.unread);
    }catch(e){}
  }

  async function pollChat(){
    try{
      const r = await fetch('ajax/chat_unread_poll.php',{cache:'no-store'});
      const d = await r.json();
      if(d && d.ok) setBadge(document.getElementById('chatBadge'), d.unread);
    }catch(e){}
  }

  pollNotifications();
  pollChat();
  setInterval(pollNotifications,5000);
  setInterval(pollChat,4000);
})();
</script>
