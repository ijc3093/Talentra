<?php
// /Business_only3/public_user/contacts.php
declare(strict_types=1);

require_once __DIR__ . '/includes/session_user.php';
requireUserLogin();

require_once __DIR__ . '/controller.php';
require_once __DIR__ . '/includes/user_identity.php';

error_reporting(E_ALL);
ini_set('display_errors', '0');

$controller = new Controller();
$dbh = $controller->pdo();

$meId = (int)userId();
$msg = '';
$error = '';

if ($meId <= 0) {
    clearUserSession();
    header("Location: index.php?session=reset");
    exit;
}

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function normalizeFriendCodeInput(string $value): string {
    $value = strtoupper(trim($value));
    $value = preg_replace('/\s+/', '', $value) ?? $value;
    if (strpos($value, 'URS-') === 0) {
        $value = 'USR-' . substr($value, 4);
    }
    return $value;
}

function findUserByFriendCode(PDO $dbh, string $value): ?array {
    $code = normalizeFriendCodeInput($value);
    if ($code === '') return null;

    $st = $dbh->prepare("SELECT id, name, username, email, friend_code, status FROM users WHERE UPPER(friend_code) = ? LIMIT 1");
    $st->execute([$code]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * ✅ Header-matching avatars
 * - 2-letter initials: first + last word (John Katy => JK)
 * - Stable color by INITIALS (JK/RV/EE always same everywhere)
 * - Same gradient style as header.php
 */

// ---------- Avatar helpers (match header.php behavior) ----------
if (!function_exists('normalize_avatar_key')) {
    /** Keep case; normalize ONLY by trimming and collapsing spaces. */
    function normalize_avatar_key(string $s): string {
        $s = trim($s);
        $s = preg_replace('/\s+/', ' ', $s) ?? $s;
        return $s;
    }
}

if (!function_exists('initials_from_name')) {
    function initials_from_name(string $name, string $fallback = 'ME'): string {
        $name = normalize_avatar_key($name);
        if ($name === '') return $fallback;

        $parts = preg_split('/\s+/', $name) ?: [];
        $parts = array_values(array_filter($parts, function ($x) {
            return trim((string)$x) !== '';
        }));
        if (!$parts) return $fallback;

        // ✅ first + last word
        $a = mb_substr($parts[0], 0, 1);
        $b = (count($parts) > 1) ? mb_substr($parts[count($parts)-1], 0, 1) : '';

        $ini = mb_strtoupper($a . $b);

        if ($ini === '') $ini = mb_strtoupper(mb_substr($name, 0, 2));
        return $ini ?: $fallback;
    }
}

if (!function_exists('color_from_string')) {
    /**
     * Stable color: SAME normalized key => SAME color (always)
     * Palette matches header.php.
     */
    function color_from_string(string $str): string {
        $colors = ['#4f46e5','#0ea5e9','#10b981','#f59e0b','#ef4444','#8b5cf6','#14b8a6','#f43f5e','#6366f1'];

        $str = normalize_avatar_key((string)$str);
        if ($str === '') $str = 'User';

        // ✅ header-like hashing (stable)
        $hash = 0;
        $len = strlen($str);
        for ($i = 0; $i < $len; $i++) {
            $hash = (($hash << 5) - $hash) + ord($str[$i]);
            $hash &= 0xFFFFFFFF;
        }

        // unsigned modulo palette size
        $idx = (int)(($hash < 0 ? $hash + 0x100000000 : $hash) % count($colors));
        return $colors[$idx] ?? $colors[0];
    }
}

if (!function_exists('avatar_gradient_style')) {
    function avatar_gradient_style(string $baseHex): string {
        $baseHex = trim($baseHex) ?: '#4f46e5';
        // ✅ stop at 40% (matches your header-style look)
        return "background: radial-gradient(circle at 30% 25%, rgba(255,255,255,.35), rgba(255,255,255,0) 40%), linear-gradient(135deg, {$baseHex}, #111827);";
    }
}

// Delete contact
if (isset($_GET['del'])) {
    $id = (int)($_GET['del'] ?? 0);
    if ($id > 0) {
        try {
            $st = $dbh->prepare("DELETE FROM user_contacts WHERE id = :id AND owner_user_id = :me");
            $st->execute([':id' => $id, ':me' => $meId]);
            $msg = "Friend removed.";
        } catch (Throwable $e) {
            $error = "Delete failed.";
        }
    }
}

// Load contacts
$st = $dbh->prepare("
  SELECT
    uc.id,
    uc.display_name,
    u.id AS friend_user_id,
    u.friend_code,
    u.email AS friend_email
  FROM user_contacts uc
  LEFT JOIN users u ON u.id = uc.friend_user_id
  WHERE uc.owner_user_id = :me
    AND NULLIF(TRIM(uc.display_name), '') IS NOT NULL
  ORDER BY uc.display_name ASC, uc.id DESC
");
$st->execute([':me' => $meId]);
$rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

if (isset($_GET['ajax']) && (string)$_GET['ajax'] === 'list') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');

    $items = [];
    foreach ($rows as $c) {
        $id = (int)($c['id'] ?? 0);
        $label = trim((string)($c['display_name'] ?? ''));
        $code = trim((string)($c['friend_code'] ?? ''));
        $email = trim((string)($c['friend_email'] ?? ''));
        $sub = $code !== '' ? $code : $email;
        $fallback = $sub !== '' ? mb_strtoupper(mb_substr($sub, 0, 2)) : 'CT';
        $initials = initials_from_name($label !== '' ? $label : $sub, $fallback);
        $uniqueKey = $code !== '' ? $code : ($email !== '' ? $email : ($label !== '' ? $label : $initials));

        $items[] = [
            'id' => $id,
            'display_name' => $label,
            'friend_user_id' => (int)($c['friend_user_id'] ?? 0),
            'friend_code' => $code,
            'friend_email' => $email,
            'subtitle' => $email,
            'initials' => $initials,
            'color' => color_from_string($uniqueKey),
            'avatar_url' => 'avatar.php?friend_code=' . urlencode($code) . '&email=' . urlencode($email) . '&name=' . urlencode($label !== '' ? $label : $sub),
            'profile_url' => ((int)($c['friend_user_id'] ?? 0) > 0)
                ? ('profile.php?id=' . (int)$c['friend_user_id'] . '&tab=gallery')
                : ($code !== '' ? ('profile.php?friend_code=' . urlencode($code) . '&tab=gallery') : ''),
            'message_url' => 'user_sendreply.php?to=' . urlencode($code !== '' ? $code : $email),
            'timeline_url' => ((int)($c['friend_user_id'] ?? 0) > 0) ? ('timeline.php?u=' . (int)$c['friend_user_id']) : '',
        ];
    }

    echo json_encode([
        'ok' => true,
        'count' => count($items),
        'blocked_count' => 0,
        'items' => $items,
    ]);
    exit;
}

if (isset($_POST['ajax']) && (string)$_POST['ajax'] === 'delete') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');

    $id = (int)($_POST['contact_id'] ?? $_POST['id'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['ok' => false, 'error' => 'Invalid contact.']);
        exit;
    }

    try {
        $del = $dbh->prepare("DELETE FROM user_contacts WHERE id = :id AND owner_user_id = :me");
        $del->execute([':id' => $id, ':me' => $meId]);
        echo json_encode(['ok' => $del->rowCount() > 0, 'message' => $del->rowCount() > 0 ? 'Friend removed.' : 'Contact not found.']);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => 'Delete failed.']);
    }
    exit;
}

if (isset($_POST['ajax']) && (string)$_POST['ajax'] === 'update') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');

    $id = (int)($_POST['contact_id'] ?? $_POST['id'] ?? 0);
    $display = trim((string)($_POST['display_name'] ?? $_POST['full_name'] ?? ''));
    $friendCode = trim((string)($_POST['friend_code'] ?? ''));

    if ($id <= 0) {
        echo json_encode(['ok' => false, 'error' => 'Invalid contact.']);
        exit;
    }
    if ($display === '') {
        echo json_encode(['ok' => false, 'error' => 'Enter full name.']);
        exit;
    }
    if ($friendCode === '') {
        echo json_encode(['ok' => false, 'error' => 'Enter friend code.']);
        exit;
    }

    $friend = findUserByFriendCode($dbh, $friendCode);
    if (!$friend) {
        echo json_encode(['ok' => false, 'error' => 'User not found. Check friend code.']);
        exit;
    }
    if ((int)($friend['status'] ?? 0) !== 1) {
        echo json_encode(['ok' => false, 'error' => 'This user account is inactive.']);
        exit;
    }
    if ((int)($friend['id'] ?? 0) === $meId) {
        echo json_encode(['ok' => false, 'error' => 'You cannot save yourself as a contact.']);
        exit;
    }

    try {
        $existing = $dbh->prepare("SELECT id FROM user_contacts WHERE owner_user_id = :me AND friend_user_id = :friend_id AND id <> :id LIMIT 1");
        $existing->execute([
            ':me' => $meId,
            ':friend_id' => (int)$friend['id'],
            ':id' => $id,
        ]);
        if ((int)($existing->fetchColumn() ?: 0) > 0) {
            echo json_encode(['ok' => false, 'error' => 'This friend is already in your contacts.']);
            exit;
        }

        $up = $dbh->prepare("
            UPDATE user_contacts
            SET display_name = :display_name,
                friend_user_id = :friend_id
            WHERE id = :id AND owner_user_id = :me
            LIMIT 1
        ");
        $up->execute([
            ':display_name' => $display,
            ':friend_id' => (int)$friend['id'],
            ':id' => $id,
            ':me' => $meId,
        ]);

        echo json_encode([
            'ok' => $up->rowCount() >= 0,
            'message' => 'Contact updated.',
            'contact' => [
                'id' => $id,
                'display_name' => $display,
                'friend_user_id' => (int)$friend['id'],
                'friend_code' => trim((string)($friend['friend_code'] ?? '')),
                'friend_email' => trim((string)($friend['email'] ?? '')),
            ],
        ]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => 'Unable to update contact.']);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
  <title>Friends</title>
  <?php
  require_once __DIR__ . '/includes/theme_prefs.php';
  theme_prefs_print_head_bootstrap($dbh, $meId);
  ?>

  <!-- vendor css -->
  <link href="./lib/font-awesome/css/font-awesome.css" rel="stylesheet">
  <link href="./lib/Ionicons/css/ionicons.css" rel="stylesheet">
  <link href="./lib/perfect-scrollbar/css/perfect-scrollbar.css" rel="stylesheet">

  <!-- Shamcey CSS -->
  <link rel="stylesheet" href="./css/shamcey.css">

  <!-- script -->
  <script src="./lib/jquery/jquery.js"></script>
  <script src="./lib/popper.js/popper.js"></script>
  <script src="./lib/bootstrap/bootstrap.js"></script>
  <script src="./lib/perfect-scrollbar/js/perfect-scrollbar.jquery.js"></script>
  <script src="./js/shamcey.js"></script>

  <style>
    /* ✅ FIXED PAGE LIKE settings.php */
    html, body { height: 100%; overflow: hidden; }

    .sh-mainpanel{
      height: 80vh;
      display: flex;
      flex-direction: column;
      overflow: hidden;
      margin-left: 340px;
      margin-top: 20px;
    }
    .sh-pagetitle{ flex: 0 0 auto; }

    .sh-pagebody{
      /* padding-top: 20px; */
      flex: 1 1 auto;
      min-height: 0;
      overflow: hidden;
      display: flex;
      flex-direction: column;
      /* padding-bottom: 0 !important; */
    }

    /* ✅ Fixed “top tools area”, scroll only the table area */
    .contacts-shell{
      flex: 1 1 auto;
      min-height: 0;
      overflow: hidden;
      display: flex;
      flex-direction: column;
      padding: 12px;
      /* height: 500px; */
      border: solid;
      margin-bottom: 5px;
      border-color: #353333;
      margin-left: 10px;
      margin-right: 10px;
    }

    .contacts-fixed{
      flex: 0 0 auto;
      position: sticky;
      top: 0;
      z-index: 5;
      background: inherit;
    }

    .contacts-scroll{
      flex: 1 1 auto;
      min-height: 0;
      overflow: auto;
      -webkit-overflow-scrolling: touch;
    }

    /* ---------- Your Pro Friends UI (kept) ---------- */
    .contacts-head{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap;}
    .contacts-title h2{margin:0;font-size:20px;font-weight:800;}
    .contacts-title p{margin:4px 0 0;opacity:.75;font-size:13px;}

    .contacts-tools{display:flex;gap:10px;align-items:center;flex-wrap:wrap;}
    .contacts-search{min-width:260px;max-width:380px;width:100%;}
    .contacts-search .form-control{border-radius:999px;padding-left:38px;}
    .contacts-search .search-ico{position:absolute;left:14px;top:50%;transform:translateY(-50%);opacity:.65;}

    .btn-soft{
      background:#fff;border:1px solid rgba(0,0,0,.12);
      border-radius:12px;font-weight:800;
    }
    .btn-soft:hover{background:#f8fafc;}

    .table-pro{
      border:1px solid rgba(0,0,0,.08);
      overflow:visible;
      position:relative;
      background:#fff;
    }
    .table-pro .table-responsive{
      overflow:visible;
    }
    .table-pro thead th{
      background:#f8fafc;border-bottom:1px solid rgba(0,0,0,.08);
      font-size:12px;letter-spacing:.02em;text-transform:uppercase;opacity:.75;
      white-space:nowrap;
      position: sticky;
      top: 0;
      z-index: 4;
      box-shadow: 0 1px 0 rgba(0,0,0,.08);
    }
    .table-pro tbody tr:hover{background:rgba(99,102,241,.05);}
    .name{font-weight:800;margin:0;line-height:1.1;}
    .sub{margin:2px 0 0;font-size:12px;opacity:.7;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:280px;}

    /* ✅ Initials avatar (header-like) */
    .avatar-circle img{width:100%;height:100%;object-fit:cover;border-radius:inherit;display:block;}
    .avatar-circle{
      width:40px;height:40px;border-radius:50%;
      display:flex;align-items:center;justify-content:center;
      font-weight:900;letter-spacing:.02em;
      font-size:14px;flex:0 0 auto;
      color:#fff;
      border:2px solid rgba(255,255,255,.95);
      box-shadow:0 10px 22px rgba(0,0,0,.18), inset 0 1px 0 rgba(255,255,255,.35);
      position:relative;
      user-select:none;
    }
    .avatar-circle::after{
      content:'';
      position:absolute; inset:0;
      border-radius:50%;
      pointer-events:none;
      background: radial-gradient(circle at 30% 25%, rgba(255,255,255,.35), rgba(255,255,255,0) 45%);
      mix-blend-mode: screen;
    }

    .mono{font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;}
    .actions{
      display:flex;
      justify-content:flex-end;
      align-items:center;
    }
    .row-menu{
      position:relative;
      display:inline-flex;
      justify-content:flex-end;
    }
    .row-menu-toggle{
      display:inline-flex;
      align-items:center;
      justify-content:center;
      width:40px;
      height:36px;
      border-radius:10px;
      border:1px solid rgba(0,0,0,.12);
      background:#fff;
      color:#374151;
      font-size:18px;
      line-height:1;
      cursor:pointer;
    }
    .row-menu-toggle:hover,
    .row-menu-toggle:focus{
      background:#f8fafc;
      outline:none;
    }
    .row-menu-toggle::after{display:none;}
    .row-menu .dropdown-menu{
      min-width:220px;
      margin-top:8px;
      border-radius:12px;
      border:1px solid rgba(0,0,0,.08);
      box-shadow:0 18px 40px rgba(15,23,42,.18);
      padding:8px 0;
    }
    .row-menu .dropdown-item{
      display:flex;
      align-items:center;
      gap:10px;
      padding:10px 16px;
      font-weight:700;
      color:#1f2937;
    }
    .row-menu .dropdown-item i{
      width:18px;
      text-align:center;
      color:#64748b;
    }
    .row-menu .dropdown-item.text-danger,
    .row-menu .dropdown-item.text-danger i{
      color:#dc2626 !important;
    }
    .row-menu .dropdown-divider{
      margin:8px 0;
      border-top:1px solid rgba(0,0,0,.08);
    }

    .badge-mini{
      display:inline-flex;align-items:center;gap:6px;border-radius:999px;
      padding:5px 10px;font-size:12px;font-weight:800;background:rgba(0,0,0,.06);
    }

    @media (max-width: 768px){
      .actions{justify-content:flex-start;}
      .table-pro thead{display:none;}
      .table-pro table, .table-pro tbody, .table-pro tr, .table-pro td{display:block;width:100%;}
      .table-pro tr{border-bottom:1px solid rgba(0,0,0,.08);padding:10px 12px;}
      .table-pro td{border:0;padding:6px 0;}
      .table-pro td[data-label]::before{
        content: attr(data-label);
        display:block;font-size:11px;opacity:.65;text-transform:uppercase;letter-spacing:.02em;
        margin-bottom:3px;
      }
      .actions{margin-top:8px;}
    }
    .sh-pagetitle{ position:sticky; top:100px; z-index:1100; background:#fff; border-bottom:1px solid rgba(0,0,0,.08); box-shadow:0 2px 10px rgba(0,0,0,.05); flex:0 0 auto;}
  
    /* =========================
       ✅ RESPONSIVE (mobile/tablet)
       - Fix huge left blank area (margin-left:340px)
       - Allow natural scrolling on small screens
       - Keep desktop layout unchanged
    ========================== */

    /* Tablets + phones */
    @media (max-width: 991.98px) {
      html, body { height: auto !important; overflow: auto !important; }
      .sh-mainpanel{
        margin-left: 0 !important;
        margin-top: 0 !important;
        height: auto !important;
        min-height: 100vh;
      }
      .sh-pagebody{
        overflow: visible !important;
      }

      .contacts-shell{
        margin-left: 10px !important;
        margin-right: 10px !important;
        padding: 12px !important;
        height: auto !important;
      }

      /* Make the scroll area scroll (instead of clipping) */
      .contacts-scroll{
        overflow: auto;
        -webkit-overflow-scrolling: touch;
      }

      /* Let the “tools/search/add” wrap nicely */
      .contacts-head{align-items:stretch;}
      .contacts-tools{width:100%; min-width:0;}
      .contacts-search{width:100%;}
      .contacts-search input{width:100% !important;}
      .contacts-actions{width:100%; justify-content:flex-start; flex-wrap:wrap;}
      .contacts-actions a{width:fit-content; max-width:100%;}
    }

    /* Small phones */
    @media (max-width: 575.98px) {
      .contacts-title h2{font-size:18px;}
      .contacts-title p{font-size:12px;}
      .contacts-shell{padding:10px !important;}
      .contacts-actions a{width:100%;} /* big tap target */
      .table-pro .table-responsive{overflow-x:auto;}
      .table{min-width:720px;} /* keep columns readable */
    }

    /* Tiny phones */
    @media (max-width: 360px) {
      .contacts-actions a{font-size:13px; padding:10px 12px;}
      .contacts-search input{font-size:13px;}
    }

</style>
</head>

<body class="contacts-page">

<?php $forceFeedRail = true; $skipHeaderThemeBootstrap = true; include __DIR__ . '/includes/header.php'; ?>
  <!-- <div class="sh-pagetitle">
    <div class="input-group">
      <input type="search" class="form-control" placeholder="Search">
      <span class="input-group-btn">
        <button class="btn"><i class="fa fa-search"></i></button>
      </span>
    </div>
    <div class="sh-pagetitle-left">
      <div class="sh-pagetitle-icon"><i class="icon ion-person-add"></i></div>
      <div class="sh-pagetitle-title">
        <span>Form Styles</span>
        <h2>Friends</h2>
      </div>
    </div>
  </div> -->

<div class="sh-mainpanel">
  <div class="sh-pagebody">
    <div class="contacts-shell">
      <div class="contacts-fixed">
        <?php if ($error): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>
        <?php if ($msg): ?><div class="alert alert-success"><?= h($msg) ?></div><?php endif; ?>
        <div class="contacts-head">
          <div class="contacts-title">
            <p>Search, message, rename, and manage your friends.</p>
          </div>
          <div class="contacts-tools">
            <div class="position-relative">
              <input type="search" id="contactSearch" class="form-control" placeholder="Search name, friend code, email...">
            </div>
            <a href="add_contact.php" class="btn btn-primary" style="font-weight:800;">
              <i class="ion ion-person-add"></i> Add Friend
            </a>
            <a href="contact_requests.php" class="btn btn-soft" style="font-weight:800;">
              <i class="ion ion-person-stalker"></i> Friend Requests
            </a>
          </div>
        </div>
        <div style="height:12px;"></div>
      </div>

      <div class="contacts-scroll">
        <div class="table-pro">
          <div class="table-responsive">
            <?php if (empty($rows)): ?>
              <div class="p-3">
                <div class="alert alert-info mb-0"><b>No friends yet.</b> Click <b>Add Friend</b> or accept a request.</div>
              </div>
            <?php else: ?>
              <table class="table mb-0">
                <thead>
                  <tr>
                    <th style="width:34%;">Friend</th>
                    <th style="width:28%;">Friend Code / Email</th>
                    <th style="width:13%;">Quick</th>
                    <th class="text-right" style="width:25%;">Actions</th>
                  </tr>
                </thead>
                <tbody id="contactsTbody">
                  <?php foreach ($rows as $c): ?>
                    <?php
                      $id    = (int)($c['id'] ?? 0);
                      $label = trim((string)($c['display_name'] ?? ''));
                      $code  = trim((string)($c['friend_code'] ?? ''));
                      $email = trim((string)($c['friend_email'] ?? ''));
                      $sub   = $code !== '' ? $code : $email;
                      $toParam = $code !== '' ? $code : $email;
                      $profileUrl = '';
                      if (!empty($c['friend_user_id'])) {
                        $profileUrl = 'profile.php?id=' . (int)$c['friend_user_id'] . '&tab=gallery';
                      } elseif ($code !== '') {
                        $profileUrl = 'profile.php?friend_code=' . urlencode($code) . '&tab=gallery';
                      }

                      $searchHay = strtolower($label . ' ' . $sub . ' ' . $email);

                      // ✅ initials + stable color key by initials
                      $fallback = $sub !== '' ? mb_strtoupper(mb_substr($sub, 0, 2)) : 'CT';
                      $ini = initials_from_name($label !== '' ? $label : $sub, $fallback);

                      // ✅ Unique key per PERSON (prevents same-initials collisions)
                      $uniqueKey = $code !== '' ? $code : ($email !== '' ? $email : ($label !== '' ? $label : $ini));
                      $peerKey   = normalize_avatar_key($uniqueKey);

                      $peerColor = color_from_string($peerKey);
                      $peerGrad  = avatar_gradient_style($peerColor);
                    ?>
                    <tr class="contact-row"
                        data-id="<?= $id ?>"
                        data-hay="<?= h($searchHay) ?>">
                      <td data-label="Friend">
                        <div style="display:flex;gap:10px;align-items:center;">
                          <div class="avatar-circle" data-avatar-key="<?= h($peerKey) ?>" style="<?= h($peerGrad) ?>"><img src="avatar.php?friend_code=<?= urlencode($code) ?>&email=<?= urlencode($email) ?>&name=<?= urlencode($label !== "" ? $label : $sub) ?>" data-live-avatar="1" data-avatar-base="avatar.php?friend_code=<?= urlencode($code) ?>&email=<?= urlencode($email) ?>&name=<?= urlencode($label !== "" ? $label : $sub) ?>" alt="Avatar"></div>
                          <div style="min-width:0;">
                            <p class="name" id="nameText-<?= $id ?>"><?= h($label) ?></p>
                            <p class="sub"><?= h($email) ?></p>
                          </div>
                        </div>
                      </td>

                      <td data-label="Friend Code / Email">
                        <div class="mono" id="codeText-<?= $id ?>" style="font-weight:800;letter-spacing:.02em;word-break:break-all;">
                          <?= h($sub) ?>
                        </div>
                      </td>

                      <td data-label="Quick">
                        <span class="badge-mini">Friend</span>
                      </td>

                      <td data-label="Actions" class="text-right">
                        <div class="actions">
                          <div class="dropdown row-menu">
                            <button class="btn dropdown-toggle row-menu-toggle" type="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false" title="More actions">
                              <i class="fa fa-ellipsis-v"></i>
                            </button>
                            <div class="dropdown-menu dropdown-menu-right">
                              <?php if ($profileUrl !== ''): ?>
                                <a class="dropdown-item" href="<?= h($profileUrl) ?>">
                                  <i class="fa fa-user"></i> View
                                </a>
                              <?php endif; ?>
                              <a class="dropdown-item" href="user_sendreply.php?to=<?= urlencode($toParam) ?>">
                                <i class="fa fa-comments"></i> Message
                              </a>
                              <a class="dropdown-item" href="add_contact.php?edit=1&id=<?= $id ?>">
                                <i class="fa fa-edit"></i> Edit
                              </a>
                              <?php if (!empty($c['friend_user_id'])): ?>
                                <a class="dropdown-item" href="timeline.php?u=<?= (int)$c['friend_user_id'] ?>">
                                  <i class="icon ion-ios-locked"></i> Timeline
                                </a>
                              <?php endif; ?>
                              <button class="dropdown-item" type="button"
                                      data-undo-id="<?= $id ?>">
                                <i class="fa fa-undo"></i> Undo Rename
                              </button>
                              <button class="dropdown-item" type="button"
                                      data-rename-id="<?= $id ?>"
                                      data-rename-name="<?= h($label) ?>">
                                <i class="fa fa-pencil"></i> Rename
                              </button>
                              <div class="dropdown-divider"></div>
                              <a class="dropdown-item text-danger"
                                 href="contacts.php?del=<?= $id ?>"
                                 onclick="return confirm('Delete this contact?');">
                                <i class="fa fa-trash"></i> Delete
                              </a>
                            </div>
                          </div>
                        </div>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            <?php endif; ?>
          </div>
        </div>
      </div>

    </div>
  </div>

  <!-- <div class="sh-footer">
    <div>Copyright &copy; <?= date('Y') ?>. All Rights Reserved.</div>
    <div class="mg-t-10 mg-md-t-0">Designed by: <a href="http://themepixels.me">ThemePixels</a></div>
  </div> -->
</div>

<!-- Rename Modal -->
<div class="modal fade" id="renameModal" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog" role="document" style="max-width:520px;">
    <div class="modal-content" style="border-radius:14px;">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fa fa-pencil"></i> Rename Friend</h5>
        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="renameId" value="0">
        <label style="font-weight:800;">Display Name</label>
        <input id="renameInput" class="form-control" placeholder="Enter new name..." autocomplete="off">
        <small class="d-block mt-2" style="opacity:.75;">
          This only changes how it appears in your Friends list.
        </small>
        <div id="renameErr" class="alert alert-danger mt-3" style="display:none;"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary" id="renameSaveBtn"><i class="fa fa-save"></i> Save</button>
      </div>
    </div>
  </div>
</div>



<script>
(function(){
  // Search filter
  var searchEl = document.getElementById('contactSearch');
  var rows = Array.prototype.slice.call(document.querySelectorAll('.contact-row'));

  function applySearch(){
    var q = (searchEl && searchEl.value ? searchEl.value.trim().toLowerCase() : '');
    rows.forEach(function(r){
      var hay = (r.getAttribute('data-hay') || '');
      r.style.display = (!q || hay.indexOf(q) !== -1) ? '' : 'none';
    });
  }

  if (searchEl) searchEl.addEventListener('input', applySearch);

  // Copy friend code/email
  document.addEventListener('click', async function(e){
    var b = e.target.closest ? e.target.closest('[data-copy]') : null;
    if (!b) return;
    var text = b.getAttribute('data-copy') || '';
    try{
      await navigator.clipboard.writeText(text);
      b.innerHTML = '<i class="fa fa-check"></i>';
      setTimeout(function(){ b.innerHTML = '<i class="fa fa-copy"></i>'; }, 900);
    }catch(err){}
  });

  // Rename modal open
  document.addEventListener('click', function(e){
    var btn = e.target.closest ? e.target.closest('[data-rename-id]') : null;
    if (!btn) return;
    var id = parseInt(btn.getAttribute('data-rename-id') || '0', 10) || 0;
    var name = btn.getAttribute('data-rename-name') || '';
    if (!id) return;

    document.getElementById('renameId').value = String(id);
    document.getElementById('renameInput').value = name;
    document.getElementById('renameErr').style.display = 'none';

    if (window.jQuery && jQuery.fn && jQuery.fn.modal) {
      jQuery('#renameModal').modal('show');
      setTimeout(function(){ document.getElementById('renameInput').focus(); }, 250);
    }
  });

  // Rename save
  var saveBtn = document.getElementById('renameSaveBtn');
  if (saveBtn){
    saveBtn.addEventListener('click', async function(){
      var id = parseInt(document.getElementById('renameId').value || '0', 10) || 0;
      var newName = (document.getElementById('renameInput').value || '').trim();
      var errBox = document.getElementById('renameErr');

      errBox.style.display = 'none';
      errBox.textContent = '';

      if (!id || !newName){
        errBox.textContent = 'Name is required.';
        errBox.style.display = 'block';
        return;
      }

      try{
        var res = await fetch('ajax/contact_rename.php', {
          method: 'POST',
          headers: {'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
          body: new URLSearchParams({ contact_id: String(id), display_name: newName })
        });
        var data = await res.json().catch(function(){ return {}; });

        if (!data.ok){
          throw new Error(data.error || 'Rename failed.');
        }

        var nameEl = document.getElementById('nameText-' + id);
        if (nameEl) nameEl.textContent = newName;

        var row = document.querySelector('.contact-row[data-id="' + id + '"]');
        if (row){
          var code = (document.getElementById('codeText-' + id)?.textContent || '');
          row.setAttribute('data-hay', (newName + ' ' + code).toLowerCase());
        }

        var renameBtn = document.querySelector('[data-rename-id="' + id + '"]');
        if (renameBtn) renameBtn.setAttribute('data-rename-name', newName);

        if (window.jQuery && jQuery.fn && jQuery.fn.modal) {
          jQuery('#renameModal').modal('hide');
        }

      }catch(ex){
        errBox.textContent = ex && ex.message ? ex.message : 'Rename failed.';
        errBox.style.display = 'block';
      }
    });
  }

  // Undo rename
  document.addEventListener('click', async function(e){
    var btn = e.target.closest ? e.target.closest('[data-undo-id]') : null;
    if (!btn) return;

    var id = parseInt(btn.getAttribute('data-undo-id') || '0', 10) || 0;
    if (!id) return;

    try{
      var res = await fetch('ajax/contact_undo_rename.php', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
        body: new URLSearchParams({ contact_id: String(id) })
      });
      var data = await res.json().catch(function(){ return {}; });

      if (!data.ok){
        throw new Error(data.error || 'Nothing to undo.');
      }

      var label = (data.display_name || '').toString();

      var nameEl = document.getElementById('nameText-' + id);
      if (nameEl) nameEl.textContent = label;

      var renameBtn = document.querySelector('[data-rename-id="' + id + '"]');
      if (renameBtn) renameBtn.setAttribute('data-rename-name', label);

      var row = document.querySelector('.contact-row[data-id="' + id + '"]');
      if (row){
        var code = (document.getElementById('codeText-' + id)?.textContent || '');
        row.setAttribute('data-hay', (label + ' ' + code).toLowerCase());
      }

    }catch(ex){}
  });

})();
</script>
<!-- <?php include __DIR__ . '/includes/footer.php'; ?> -->
</body>
</html>
