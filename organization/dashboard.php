<?php
// /Business_only3/organization/dashboard.php
declare(strict_types=1);

require_once __DIR__ . '/includes/session_org.php';
require_once __DIR__ . '/includes/org_context.php';
require_once __DIR__ . '/includes/org_manager_guard.php';

org_require_manager();

require_once __DIR__ . '/includes/org_public_publish.php';
require_once __DIR__ . '/../admin/includes/org_admin_helpers.php';
require_once __DIR__ . '/../public_user/includes/platform_rent.php';

// ✅ DB connection (org_context.php usually sets $dbh; keep safe fallback)
if (!isset($dbh) || !($dbh instanceof PDO)) {
    require_once __DIR__ . '/../admin/controller.php';
    $controller = new Controller();
    $dbh = $controller->pdo();
}

error_reporting(E_ALL);
ini_set('display_errors', '1');

if (!function_exists('h')) {
    function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}

function clamp_int($v, int $min, int $max, int $default): int {
    if (!is_numeric($v)) return $default;
    $n = (int)$v;
    if ($n < $min) return $min;
    if ($n > $max) return $max;
    return $n;
}
function is_managerish(string $role): bool { return in_array($role, ['admin','manager'], true); }
function post_label(string $type): string {
    switch ($type) {
        case 'announcement': return 'Announcement';
        case 'direction':    return 'Direction';
        case 'update':       return 'Update';
        case 'weekly_update': return 'Weekly Update';
        case 'recognition':  return 'Recognition';
        default:             return ucfirst($type);
    }
}

// -------------------- Org --------------------
$orgId = (int)($ORG['id'] ?? 0);
if ($orgId <= 0 && function_exists('orgActiveOrgId')) $orgId = (int)orgActiveOrgId();
if ($orgId <= 0) die('Invalid organization context.');

// -------------------- Resolve session account -> org_members row --------------------
$accountType = function_exists('orgAccountType') ? (string)orgAccountType() : '';
$accountId   = function_exists('orgAccountId') ? (int)orgAccountId() : 0;

if ($accountId <= 0) $accountId = (int)($_SESSION['org_account_id'] ?? 0);
if ($accountType !== 'manager' && $accountType !== 'staff') {
    if (function_exists('isOrgManager') && isOrgManager()) $accountType = 'manager';
    else $accountType = 'staff';
}
if ($accountId <= 0) die('Invalid org session.');

// -------------------- Resolve session membership (trusted) --------------------
$meMemberId = function_exists('orgMemberId') ? (int)orgMemberId() : 0;
$myRoleId   = function_exists('orgRoleId')   ? (int)orgRoleId()   : 0;
$myJoinedAt = '';

if ($meMemberId <= 0) {
    $st = $dbh->prepare("
        SELECT id, role_id, joined_at
        FROM org_members
        WHERE org_id = :org
          AND member_type = :mt
          AND member_id = :mid
        LIMIT 1
    ");
    $st->execute([':org'=>$orgId, ':mt'=>$accountType, ':mid'=>$accountId]);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    $meMemberId = (int)($row['id'] ?? 0);
    $myRoleId   = (int)($row['role_id'] ?? 0);
    $myJoinedAt = (string)($row['joined_at'] ?? '');
}

if ($meMemberId <= 0) {
    header("Location: select_org.php");
    exit;
}

// Resolve role name via org_roles
$meRole = ($accountType === 'manager') ? 'manager' : 'staff';
try {
    if ($myRoleId > 0) {
        $stR = $dbh->prepare("SELECT name FROM org_roles WHERE id = :id AND org_id = :org LIMIT 1");
        $stR->execute([':id'=>$myRoleId, ':org'=>$orgId]);
        $roleName = (string)($stR->fetchColumn() ?: '');
        $roleNameLower = strtolower($roleName);
        if ($roleNameLower === 'manager') $meRole = 'manager';
        elseif ($roleNameLower === 'staff') $meRole = 'staff';
        elseif ($roleNameLower === 'admin') $meRole = 'admin';
    }
} catch (Throwable $e) {
    // keep fallback
}

// -------------------- Self-heal organization_users --------------------
try {
    $stChk = $dbh->prepare("SELECT role FROM organization_users WHERE org_id=:o AND user_id=:u LIMIT 1");
    $stChk->execute([':o'=>$orgId, ':u'=>$meMemberId]);
    $have = (string)($stChk->fetchColumn() ?: '');
    if ($have === '') {
        $ins = $dbh->prepare("
            INSERT INTO organization_users (org_id, user_id, role, joined_at)
            VALUES (:o, :u, :r, NOW())
            ON DUPLICATE KEY UPDATE role = VALUES(role)
        ");
        $ins->execute([':o'=>$orgId, ':u'=>$meMemberId, ':r'=>$meRole]);
    } else {
        $meRole = $have;
    }
} catch (Throwable $e) {
    // ignore
}

// -------------------- Resolve my fullname --------------------
$myFullname = 'Member';
try {
    $stN = $dbh->prepare("
        SELECT COALESCE(m.fullname, s.fullname, 'Member') AS fullname
        FROM org_members om
        LEFT JOIN managers m
          ON om.member_type = 'manager' AND m.id = om.member_id
        LEFT JOIN staff_accounts s
          ON om.member_type = 'staff' AND s.id = om.member_id
        WHERE om.org_id = :org AND om.id = :omid
        LIMIT 1
    ");
    $stN->execute([':org'=>$orgId, ':omid'=>$meMemberId]);
    $myFullname = (string)($stN->fetchColumn() ?: 'Member');
} catch (Throwable $e) {
    $myFullname = 'Member';
}

// -------------------- CSRF --------------------
if (empty($_SESSION['csrf_org_dash'])) {
    $_SESSION['csrf_org_dash'] = bin2hex(random_bytes(16));
}
$csrf = (string)$_SESSION['csrf_org_dash'];
function csrf_ok(): bool {
    return isset($_POST['csrf'], $_SESSION['csrf_org_dash'])
        && is_string($_POST['csrf'])
        && hash_equals((string)$_SESSION['csrf_org_dash'], (string)$_POST['csrf']);
}


// -------------------- Feed first-visit NEW window setting (stored in org_settings.theme_json) --------------------
$feedFirstVisitDays = 180; // default fallback
try {
    $st = $dbh->prepare("SELECT theme_json FROM org_settings WHERE org_id = :oid LIMIT 1");
    $st->execute([':oid'=>$orgId]);
    $tjson = (string)($st->fetchColumn() ?: '');
    if ($tjson !== '') {
        $theme = json_decode($tjson, true);
        if (is_array($theme) && isset($theme['feed_first_visit_new_window_days'])) {
            $v = (int)$theme['feed_first_visit_new_window_days'];
            if ($v >= 0 && $v <= 3650) $feedFirstVisitDays = $v;
        }
    }
} catch (Throwable $e) { /* keep default */ }

// -------------------- Actions --------------------
$flashOk  = '';
$flashErr = '';

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!csrf_ok()) {
            throw new RuntimeException('Security check failed. Please refresh and try again.');
        }

        $action = (string)($_POST['action'] ?? '');

        
        if ($action === 'save_feed_window') {
            if (!is_managerish($meRole)) throw new RuntimeException('Only Manager/Admin can change this setting.');

            $days = (int)($_POST['feed_first_visit_new_window_days'] ?? 180);
            if ($days < 0) $days = 0;
            if ($days > 3650) $days = 3650;

            $stU = $dbh->prepare("
                UPDATE org_settings
                SET theme_json = JSON_SET(COALESCE(theme_json, '{}'), '$.feed_first_visit_new_window_days', :days),
                    updated_at = NOW()
                WHERE org_id = :oid
                LIMIT 1
            ");
            $stU->execute([':days'=>$days, ':oid'=>$orgId]);

            $feedFirstVisitDays = $days;
            $flashOk = 'Feed NEW window updated.';
        }
    }
} catch (Throwable $e) {
    $flashErr = $e->getMessage();
}

// -------------------- Staff: unread updates counter --------------------
$unreadCount = 0;
$unreadPreview = [];
try {
    if (!is_managerish($meRole)) {
        $since = $myJoinedAt !== '' ? $myJoinedAt : date('Y-m-d H:i:s', time() - 30*86400);

        $stU = $dbh->prepare("
            SELECT COUNT(*)
            FROM org_posts p
            WHERE p.org_id = :org
              AND p.created_at >= :since
              AND NOT EXISTS (
                SELECT 1
                FROM org_post_acknowledgements a
                WHERE a.post_id = p.id AND a.user_id = :me
              )
        ");
        $stU->execute([':org'=>$orgId, ':since'=>$since, ':me'=>$meMemberId]);
        $unreadCount = (int)($stU->fetchColumn() ?: 0);

        $stP = $dbh->prepare("
            SELECT p.id, p.post_type, p.title, p.created_at
            FROM org_posts p
            WHERE p.org_id = :org
              AND p.created_at >= :since
              AND NOT EXISTS (
                SELECT 1 FROM org_post_acknowledgements a
                WHERE a.post_id = p.id AND a.user_id = :me
              )
            ORDER BY p.created_at DESC
            LIMIT 3
        ");
        $stP->execute([':org'=>$orgId, ':since'=>$since, ':me'=>$meMemberId]);
        $unreadPreview = $stP->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
} catch (Throwable $e) {
    // ignore
}

// -------------------- Pulse stats --------------------
$pulse = ['posts_7d'=>0,'comments_7d'=>0,'acks_7d'=>0];
try {
    $stP = $dbh->prepare("
        SELECT
          (SELECT COUNT(*) FROM org_posts p
           WHERE p.org_id = :org_id AND p.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
          ) AS posts_7d,

          (SELECT COUNT(*) FROM org_post_comments c
           JOIN org_posts p2 ON p2.id = c.post_id
           WHERE p2.org_id = :org_id AND c.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
          ) AS comments_7d,

          (SELECT COUNT(*) FROM org_post_acknowledgements a
           JOIN org_posts p3 ON p3.id = a.post_id
           WHERE p3.org_id = :org_id AND a.acknowledged_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
          ) AS acks_7d
    ");
    $stP->execute([':org_id' => $orgId]);
    $rowPulse = $stP->fetch(PDO::FETCH_ASSOC);
    if (is_array($rowPulse)) {
        $pulse['posts_7d']    = (int)($rowPulse['posts_7d'] ?? 0);
        $pulse['comments_7d'] = (int)($rowPulse['comments_7d'] ?? 0);
        $pulse['acks_7d']     = (int)($rowPulse['acks_7d'] ?? 0);
    }
} catch (Throwable $e) {
    // keep defaults
}

// -------------------- Manager compliance view --------------------
$staffTotal = 0;
$complianceRows = [];
try {
    if (is_managerish($meRole)) {
        $stT = $dbh->prepare("
            SELECT COUNT(*)
            FROM org_members
            WHERE org_id = :org
              AND member_type = 'staff'
              AND status = 1
        ");
        $stT->execute([':org'=>$orgId]);
        $staffTotal = (int)($stT->fetchColumn() ?: 0);

        $stC = $dbh->prepare("
            SELECT
              p.id,
              p.post_type,
              p.title,
              p.created_at,
              (
                SELECT COUNT(*)
                FROM org_post_acknowledgements a
                JOIN org_members om ON om.id = a.user_id
                WHERE a.post_id = p.id
                  AND om.org_id = p.org_id
                  AND om.member_type = 'staff'
                  AND om.status = 1
              ) AS staff_ack_count
            FROM org_posts p
            WHERE p.org_id = :org
              AND p.created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)
            ORDER BY p.created_at DESC
            LIMIT 20
        ");
        $stC->execute([':org'=>$orgId]);
        $complianceRows = $stC->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
} catch (Throwable $e) {
    // ignore
}

$preview = array_slice($complianceRows, 0, 1);

// -------------------- Linked publisher (public brand) --------------------
$publisherUserId = org_public_publish_publisher_user_id($dbh);
$rentSnapshot = ($orgId > 0) ? platform_rent_org_snapshot($dbh, $orgId) : null;
$shopRentVisible = $rentSnapshot ? !empty($rentSnapshot['shop_visible']) : true;
$publisherStats = [
    'name' => '',
    'username' => '',
    'posts' => 0,
    'followers' => 0,
    'posts_7d' => 0,
];
if ($publisherUserId > 0) {
    try {
        $stPub = $dbh->prepare('SELECT name, username FROM users WHERE id = :id LIMIT 1');
        $stPub->execute([':id' => $publisherUserId]);
        $pubRow = $stPub->fetch(PDO::FETCH_ASSOC) ?: [];
        $publisherStats['name'] = trim((string)($pubRow['name'] ?? ''));
        $publisherStats['username'] = trim((string)($pubRow['username'] ?? ''));
    } catch (Throwable $e) {
        // ignore
    }
    $publisherStats['posts'] = org_admin_user_post_count($dbh, $publisherUserId);
    $publisherStats['followers'] = org_admin_user_follower_count($dbh, $publisherUserId);
    try {
        $st7 = $dbh->prepare('
            SELECT COUNT(*) FROM public_posts
            WHERE user_id = :uid AND (is_deleted = 0 OR is_deleted IS NULL)
              AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        ');
        $st7->execute([':uid' => $publisherUserId]);
        $publisherStats['posts_7d'] = (int)($st7->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        // ignore
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once __DIR__ . '/includes/org_theme_head.php'; ?>

  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
  <title><?= h((string)($ORG['name'] ?? 'Organization')) ?> - Dashboard</title>

  <link href="../lib/font-awesome/css/font-awesome.css" rel="stylesheet">
  <link href="../lib/Ionicons/css/ionicons.css" rel="stylesheet">
  <link href="../lib/perfect-scrollbar/css/perfect-scrollbar.css" rel="stylesheet">
  <link href="../lib/bootstrap/bootstrap.css" rel="stylesheet">
  <link rel="stylesheet" href="../css/shamcey.css">
  <?php require_once __DIR__ . '/includes/org_layout.php'; org_layout_head_assets(); ?>

  <style>
    html,body{ height:100%; overflow:hidden; }
    .sh-mainpanel{ height:100vh; display:flex; flex-direction:column; overflow:hidden; }
    .sh-pagetitle{ flex:0 0 auto; }
    .sh-pagebody{
      flex:1 1 auto; overflow:hidden; display:flex; flex-direction:column;
      min-height:0; padding-bottom:0!important;
    }
    .dashboard-card{ flex:1 1 auto; min-height:0; display:flex; flex-direction:column; overflow:hidden; }
    .card-body-fixed{ flex:1 1 auto; min-height:0; overflow:hidden; display:flex; flex-direction:column; }
    .rows-scroll{ flex:1 1 auto; min-height:0; overflow:auto; padding: 8px 10px; }

    .dash-toprow{
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap:6px;
      flex-wrap:wrap;
      margin: 8px 0 4px;
    }
    .dash-toprow .left-actions{ display:flex; gap:6px; flex-wrap:wrap; align-items:center; }
    .dash-toprow .right-stats{ margin-left:auto; display:flex; gap:6px; flex-wrap:wrap; align-items:center; justify-content:flex-end; }

    .stat-pill{
      display:inline-flex;
      align-items:center;
      gap:5px;
      padding:4px 8px;
      border-radius:999px;
      border:1px solid var(--msb-palette-border-strong, var(--org-border, #d1d5db));
      background:var(--msb-palette-bg, var(--org-surface, #fff));
      font-size:11px;
      line-height:1;
      white-space:nowrap;
    }
    .stat-pill .icon{
      width:22px; height:22px; border-radius:999px;
      display:inline-flex; align-items:center; justify-content:center;
      background:var(--msb-palette-action-soft, #eef2ff);
      color:var(--msb-palette-action, #2563eb);
      font-size:11px;
    }
    .stat-pill .num{ font-weight:800; color:var(--msb-palette-text, var(--org-text, #111827)); }
    .stat-pill .lbl{ color:var(--msb-palette-text-muted, #4b5563); font-weight:700; }
    .stat-pill .sub{ color:var(--msb-palette-text-muted, #9ca3af); font-weight:700; margin-left:4px; font-size:10px; }

    .publisher-hub{
      border:1px solid var(--msb-palette-action, #2563eb);
      border-radius:8px;
      padding:8px 10px;
      margin:8px 0 4px;
      background:var(--msb-palette-bg, #fff);
    }
    .publisher-hub-head{
      display:flex; align-items:flex-start; justify-content:space-between;
      gap:8px; flex-wrap:wrap; margin-bottom:6px;
    }
    .publisher-hub-title{
      font-size:12px; font-weight:800; color:#1e3a8a; margin:0;
    }
    .publisher-hub-sub{
      font-size:10px; color:#4b5563; font-weight:600; margin-top:2px;
    }
    .publisher-hub-actions{ display:flex; gap:6px; flex-wrap:wrap; }
    .publisher-hub-stats{ display:flex; gap:6px; flex-wrap:wrap; margin-top:6px; }
    .publisher-stat{
      display:inline-flex; align-items:center; gap:4px;
      padding:3px 7px; border-radius:999px;
      border:1px solid #bfdbfe; background:#fff;
      font-size:10px; font-weight:700; color:#1e40af;
    }
    .publisher-check{
      display:flex; align-items:flex-start; gap:6px;
      padding:6px 8px; border-radius:6px;
      border:1px dashed #93c5fd; background:#f8fbff;
      margin-bottom:6px;
    }
    .publisher-check label{ margin:0; font-size:11px; font-weight:700; color:#1e40af; cursor:pointer; }
    .publisher-check .hint{ display:block; font-size:10px; font-weight:600; color:#6b7280; margin-top:1px; }

    .feed-card{ border:1px solid #6b7280; border-radius:8px; padding:8px 10px; margin:8px 0; background:#fff; }
    .feed-meta{ display:flex; gap:6px; flex-wrap:wrap; align-items:center; font-size:11px; color:#6b7280; margin-bottom:6px; }
    .feed-badge{ font-size:11px; padding:4px 8px; border-radius:999px; background:#eef2f7; color:#374151; font-weight:700; }
    .mini-muted{ color:#6b7280; font-size:11px; font-weight:600; }

    .compliance-head{
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap:8px;
      flex-wrap:wrap;
      margin-top:10px;
      margin-bottom:6px;
    }
    .compliance-head-left{ display:flex; gap:8px; align-items:center; flex-wrap:wrap; }

    .compliance-table{ width:100%; border-collapse:collapse; margin-top:6px; }
    .compliance-table th,.compliance-table td{
      border-bottom:1px solid #eef0f4;
      padding:6px 8px;
      font-size:11px;
      vertical-align:middle;
    }
    .compliance-table th{ color:#111827; font-weight:800; }
    .pill{
      display:inline-block;
      padding:3px 8px;
      border-radius:999px;
      border:1px solid #e5e7eb;
      background:#fff;
      font-size:10px;
      font-weight:700;
      color:#6b7280;
    }

    /* ✅ modal like your screenshot */
    .modal-compliance .modal-dialog{ max-width:1120px; }
    .modal-compliance .modal-content{
      border:2px solid #6b7280;
      border-radius:18px;
      box-shadow:none;
    }
    .modal-compliance .modal-header{
      border:0;
      padding:18px 22px 10px 22px;
      display:flex;
      align-items:center;
      justify-content:space-between;
    }
    .modal-compliance .modal-body{
      padding:6px 22px 18px 22px;
      max-height:70vh;
      overflow:auto;
    }
    .comp-head{ display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
    .comp-badge{
      display:inline-flex; align-items:center;
      padding:3px 8px;
      border-radius:999px;
      background:#eef2f7;
      color:#374151;
      font-weight:700;
      font-size:10px;
    }
    .comp-sub{ color:#6b7280; font-weight:700; font-size:11px; }
    .btn-close-x{
      width:28px; height:28px; border-radius:999px;
      border:1px solid #e5e7eb; background:#fff;
      display:flex; align-items:center; justify-content:center;
      cursor:pointer;
    }
    .btn-close-x:hover{ background:#f3f4f6; }

    .feed-tabs{ display:flex; gap:6px; margin: 10px 0 6px; }
    .feed-tabs a{
      padding:5px 9px;
      border-radius:6px;
      text-decoration:none;
      border:1px solid #d1d5db;
      color:#6b7280;
      font-weight:700;
      background:#fff;
      font-size:11px;
    }
    .feed-tabs a.active{
      background: var(--org-btn-filled-bg, #0b5ed7);
      color: var(--org-btn-filled-text, #fff);
      border-color: var(--org-btn-filled-bg, #0b5ed7);
    }

    /* dark */
    html.dark-auto:not([data-msb-appearance]) .stat-pill{ background:#171d24; border-color:#334155; }
    html.dark-auto:not([data-msb-appearance]) .stat-pill .num{ color:#b1bcce; }
    html.dark-auto:not([data-msb-appearance]) .stat-pill .lbl{ color:#b1bcce; }
    html.dark-auto:not([data-msb-appearance]) .stat-pill .sub{ color:#b1bcce; }
    html.dark-auto:not([data-msb-appearance]) .stat-pill .icon{ background:#111827; color:#b1bcce; }

    html.dark-auto:not([data-msb-appearance]) .feed-card{ background:#171d24; border-color:#334155; }
    html.dark-auto:not([data-msb-appearance]) .feed-badge{ background:#111827; color:#b1bcce; }
    html.dark-auto:not([data-msb-appearance]) .mini-muted{ color:#b1bcce; }
    html.dark-auto:not([data-msb-appearance]) .compliance-table th{ color:#b1bcce; }
    html.dark-auto:not([data-msb-appearance]) .compliance-table td{ color:#b1bcce; border-bottom-color:#334155; }
    html.dark-auto:not([data-msb-appearance]) .pill{ background:#171d24; border-color:#334155; color:#b1bcce; }

    html.dark-auto:not([data-msb-appearance]) .modal-compliance .modal-content{ background:#171d24; border-color:#334155; }
    html.dark-auto:not([data-msb-appearance]) .comp-badge{ background:#111827; color:#b1bcce; }
    html.dark-auto:not([data-msb-appearance]) .comp-sub{ color:#b1bcce; }
    html.dark-auto:not([data-msb-appearance]) .btn-close-x{ background:#171d24; border-color:#334155; color:#b1bcce; }
    html.dark-auto:not([data-msb-appearance]) .btn-close-x:hover{ background:#111827; }
  </style>
</head>

<body class="org-app org-page-dashboard">
<?php include __DIR__ . '/includes/header.php'; ?>
<?php include __DIR__ . '/includes/leftbar.php'; ?>

<div class="sh-mainpanel">

  <!-- <div class="sh-pagetitle" style="border-bottom:1px solid #4a535c;">
    <div class="input-group"></div>

    <div class="sh-pagetitle-left">
      <div class="sh-pagetitle-icon"><i class="icon ion-ios-home"></i></div>
      <div class="sh-pagetitle-title"><h2>Home Page</h2></div>
    </div>
  </div> -->

  <?php org_page_body_open(); ?>
    <div class="card bd-0 dashboard-card">
      <div class="card-body card-body-fixed">
        <div class="rows-scroll">

          <!-- <h4 class="tx-gray-800 mg-b-10"><?= h((string)($ORG['name'] ?? 'Organization')) ?></h4> -->

          <p class="tx-gray-600 mg-b-0">
            Welcome, <strong><?= h($myFullname) ?></strong>
            <span class="mini-muted">(<?= h($meRole) ?>)</span>
            · Org Code: <strong><?= h((string)($ORG['org_code'] ?? '')) ?></strong>
          </p>

          <?php if ($flashOk): ?>
            <div class="alert alert-success" style="margin-top:12px;"><?= h($flashOk) ?></div>
          <?php endif; ?>
          <?php if ($flashErr): ?>
            <div class="alert alert-danger" style="margin-top:12px;"><?= h($flashErr) ?></div>
          <?php endif; ?>

          <?php if ($rentSnapshot && platform_rent_org_is_shop($rentSnapshot)): ?>
            <?php
              $rentLive = (string)($rentSnapshot['rent_status_live'] ?? $rentSnapshot['rent_status'] ?? 'trial');
              $rentUntil = trim((string)($rentSnapshot['rent_paid_until'] ?? ''));
              if ($rentUntil === '') {
                  $rentUntil = trim((string)($rentSnapshot['rent_trial_ends_at'] ?? ''));
              }
            ?>
            <?php if (!$shopRentVisible): ?>
            <div class="alert alert-danger" style="margin-top:12px;">
              <strong>Shop hidden from customers.</strong>
              Monthly platform rent is <?= h($rentLive) ?>.
              Contact the platform admin to pay rent and restore your public shop.
            </div>
            <?php elseif ($rentLive === 'trial'): ?>
            <div class="alert alert-warning" style="margin-top:12px;">
              <strong>Free trial.</strong>
              Your shop is live<?= $rentUntil !== '' ? (' until ' . h(date('M j, Y', strtotime($rentUntil)))) : '' ?>.
              After trial, pay monthly rent to keep selling on the public feed.
            </div>
            <?php elseif ($rentLive === 'active' && $rentUntil !== ''): ?>
            <div class="alert alert-success" style="margin-top:12px;">
              <strong>Rent active</strong> until <?= h(date('M j, Y', strtotime($rentUntil))) ?>.
              Your shop is visible to customers.
            </div>
            <?php endif; ?>
          <?php endif; ?>

          <?php if ($publisherUserId > 0): ?>
            <div class="publisher-hub">
              <div class="publisher-hub-head">
                <div>
                  <h3 class="publisher-hub-title">Public Publisher</h3>
                  <div class="publisher-hub-sub">
                    Your team workspace is linked to
                    <strong><?= h($publisherStats['name'] !== '' ? $publisherStats['name'] : 'your brand page') ?></strong>
                    on the public feed.
                  </div>
                </div>
                <div class="publisher-hub-actions">
                  <?php if ($shopRentVisible): ?>
                  <a href="publisher_public_enter.php?to=compose" class="btn btn-primary btn-sm">
                    <i class="fa fa-pencil mg-r-5"></i> Publish to Public
                  </a>
                  <a href="publisher_public_enter.php?to=feed" class="btn btn-outline-primary btn-sm">
                    <i class="fa fa-globe mg-r-5"></i> View Public Feed
                  </a>
                  <?php else: ?>
                  <span class="btn btn-outline-secondary btn-sm disabled" title="Pay platform rent to restore shop">Shop hidden — rent overdue</span>
                  <?php endif; ?>
                  <?php if ($publisherStats['username'] !== ''): ?>
                    <a href="publisher_public_enter.php?to=profile" class="btn btn-outline-secondary btn-sm">
                      <i class="fa fa-user mg-r-5"></i> Brand Profile
                    </a>
                  <?php endif; ?>
                </div>
              </div>
              <div class="publisher-hub-stats">
                <span class="publisher-stat" title="Total public posts">
                  <i class="fa fa-file-text-o"></i>
                  <?= (int)$publisherStats['posts'] ?> posts
                </span>
                <span class="publisher-stat" title="Public posts in last 7 days">
                  <i class="fa fa-clock-o"></i>
                  <?= (int)$publisherStats['posts_7d'] ?> this week
                </span>
                <span class="publisher-stat" title="Followers">
                  <i class="fa fa-users"></i>
                  <?= (int)$publisherStats['followers'] ?> followers
                </span>
              </div>
            </div>
          <?php endif; ?>

          <!-- ✅ Buttons left + pills right -->
          <div class="dash-toprow">
            <div class="left-actions">
              <a href="feed.php" class="btn btn-outline-secondary btn-sm">
                <i class="fa fa-home"></i> Home
              </a>
              <a href="posts.php?tab=work" class="btn btn-outline-secondary btn-sm">
                <i class="fa fa-list mg-r-5"></i> Posts List
              </a>

              <?php if (is_managerish($meRole)): ?>
                <a href="create_staff.php" class="btn btn-outline-secondary btn-sm">
                  <i class="ion-person-add mg-r-5"></i> Create Staff
                </a>
                <a href="settings.php" class="btn btn-outline-secondary btn-sm">
                  <i class="ion-ios-gear mg-r-5"></i> Org Settings
                </a>
                <!-- <a href="messages.php" class="btn btn-outline-secondary">
                  <i class="ion-chatboxes mg-r-5"></i> Messages
                </a> -->
              <?php else: ?>
                <a href="messages.php" class="btn btn-outline-secondary btn-sm">
                  <i class="ion-chatboxes mg-r-5"></i> Messages
                </a>
                <a href="members.php" class="btn btn-outline-secondary btn-sm">
                  <i class="ion-ios-people mg-r-5"></i> Members
                </a>
              <?php endif; ?>
            </div>

            <div class="right-stats">
              <span class="stat-pill" title="Posts in last 7 days">
                <span class="icon"><i class="fa fa-pencil"></i></span>
                <span class="num"><?= (int)$pulse['posts_7d'] ?></span>
                <span class="lbl">Posts</span>
                <span class="sub">7d</span>
              </span>

              <span class="stat-pill" title="Responses in last 7 days">
                <span class="icon"><i class="fa fa-comments"></i></span>
                <span class="num"><?= (int)$pulse['comments_7d'] ?></span>
                <span class="lbl">Responses</span>
                <span class="sub">7d</span>
              </span>

              <span class="stat-pill" title="Acknowledgements in last 7 days">
                <span class="icon"><i class="fa fa-check-circle"></i></span>
                <span class="num"><?= (int)$pulse['acks_7d'] ?></span>
                <span class="lbl">Acks</span>
                <span class="sub">7d</span>
              </span>
            </div>

          <?php if (is_managerish($meRole)): ?>
            <!-- ✅ Feed NEW window setting (first-visit baseline) -->
            <div class="feed-card" style="margin-top:16px; border:1px dashed rgba(255,255,255,.18);">
              <div class="feed-meta" style="display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap;">
                <div>
                  <span class="feed-badge" style="background:rgba(11,94,215,.12); border-color:rgba(11,94,215,.25); color:#0b5ed7;">
                    Feed “NEW” Window
                  </span>
                  <div class="mini-muted" style="margin-top:6px;">
                    First-time visitors will see posts as <strong>NEW</strong> if created within this window.
                  </div>
                </div>
                <div class="mini-muted">
                  Current: <strong><?= (int)$feedFirstVisitDays ?></strong> day(s)
                </div>
              </div>

              <form method="post" action="" style="margin-top:12px;">
                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                <input type="hidden" name="action" value="save_feed_window">

                <div class="form-row">
                  <div class="form-group col-md-4">
                    <label class="mini-muted">Quick select</label>
                    <select id="ffvPreset" class="form-control">
                      <option value="0">Disable (0)</option>
                      <option value="1">Last 24 hours (1)</option>
                      <option value="7">Last 7 days (7)</option>
                      <option value="30">Last 30 days (30)</option>
                      <option value="180">Last 180 days (180)</option>
                    </select>
                  </div>

                  <div class="form-group col-md-4">
                    <label class="mini-muted">Days (0 - 3650)</label>
                    <input
                      type="number"
                      min="0"
                      max="3650"
                      step="1"
                      name="feed_first_visit_new_window_days"
                      id="ffvDays"
                      class="form-control"
                      value="<?= (int)$feedFirstVisitDays ?>"
                      required>
                  </div>

                  <div class="form-group col-md-4" style="display:flex; align-items:flex-end; gap:10px;">
                    <button class="btn btn-outline-primary" type="submit">
                      <i class="fa fa-save mg-r-5"></i> Save
                    </button>
                    <a class="btn btn-outline-secondary" href="feed.php" title="View feed">
                      <i class="fa fa-eye mg-r-5"></i> Preview
                    </a>
                  </div>
                </div>

                <div class="mini-muted" style="margin-top:-6px;">
                  Tip: 7 days is usually best for active orgs. 180 days is better if posts are occasional.
                </div>
              </form>
            </div>

            <script>
              (function(){
                var preset = document.getElementById('ffvPreset');
                var days = document.getElementById('ffvDays');
                if (!preset || !days) return;

                // set preset to current if it matches
                var current = String(days.value || '');
                for (var i=0; i<preset.options.length; i++){
                  if (preset.options[i].value === current) { preset.selectedIndex = i; break; }
                }

                preset.addEventListener('change', function(){
                  days.value = preset.value;
                });
              })();
            </script>
          <?php endif; ?>

          </div>

          <?php if (is_managerish($meRole)): ?>
            <div class="feed-card" style="margin-top:16px;">
              <div class="feed-meta">
                <span class="feed-badge">Organization feed</span>
                <span class="mini-muted">Post announcements to your team feed (not the public social feed).</span>
              </div>
              <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:10px;">
                <a href="compose_post.php" class="btn btn-primary btn-sm">
                  <i class="fa fa-paper-plane mg-r-5"></i> New announcement
                </a>
                <a href="feed.php" class="btn btn-outline-secondary btn-sm">
                  <i class="fa fa-home mg-r-5"></i> View feed
                </a>
              </div>
            </div>

            <!-- ✅ Compliance header + preview + modal button -->
            <div class="compliance-head">
              <div class="compliance-head-left">
                <span class="feed-badge">Acknowledgement Compliance</span>
                <span class="mini-muted">
                  Staff total: <strong><?= (int)$staffTotal ?></strong> · last 14 days
                </span>
              </div>

              <button type="button" class="btn btn-outline-secondary"
                data-toggle="modal" data-target="#complianceModal">
                <i class="fa fa-bar-chart mg-r-5"></i> View Details
              </button>
            </div>

            <!-- <div class="feed-card" style="margin-top:10px;">
              <?php if (!$complianceRows): ?>
                <div class="mini-muted">No recent posts.</div>
              <?php else: ?>
                <div class="mini-muted" style="margin-bottom:8px;">Preview (latest):</div>

                <table class="compliance-table">
                  <thead>
                    <tr>
                      <th style="width:260px;">Date</th>
                      <th>Post</th>
                      <th style="width:320px; text-align:right;">Staff Ack</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($preview as $r): ?>
                      <?php
                        $cpid = (int)$r['id'];
                        $ack = (int)$r['staff_ack_count'];
                        $pct = ($staffTotal > 0) ? (int)round(($ack / $staffTotal) * 100) : 0;
                        $pending = max(0, $staffTotal - $ack);
                        $label = post_label((string)$r['post_type']);
                      ?>
                      <tr>
                        <td><?= h((string)$r['created_at']) ?></td>
                        <td>
                          <strong style="color:#2563eb;"><?= h($label) ?></strong>
                        </td>
                        <td style="text-align:right;">
                          <span class="pill"><?= $ack ?>/<?= (int)$staffTotal ?> (<?= $pct ?>%)</span>
                          <span class="pill" style="margin-left:10px;">Pending: <?= (int)$pending ?></span>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>

              <?php endif; ?>
            </div> -->
          <?php endif; ?>

          <!-- ✅ Dashboard should not list posts: tabs go to posts.php -->
          <div class="feed-tabs">
            <a class="feed-tab-link <?= $tab==='work'?'active':'' ?>" href="posts.php?tab=work">Work Updates</a>
            <a class="feed-tab-link" href="posts.php?tab=culture">Culture & Wins</a>
          </div>

        </div>
      </div>
    </div>
  </div>

  <?php include __DIR__ . '/includes/footer.php'; ?>
</div>

<!-- ✅ Compliance Modal -->
<div class="modal fade modal-compliance" id="complianceModal" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog" role="document">
    <div class="modal-content">

      <div class="modal-header">
        <div class="comp-head">
          <span class="comp-badge">Acknowledgement Compliance</span>
          <span class="comp-sub">Staff total: <strong><?= (int)$staffTotal ?></strong> · last 14 days</span>
        </div>

        <button type="button" class="btn-close-x" data-dismiss="modal" aria-label="Close">
          <span aria-hidden="true" style="font-size:16px; font-weight:700;">&times;</span>
        </button>
      </div>

      <div class="modal-body">
        <?php if (!$complianceRows): ?>
          <div class="mini-muted">No recent posts.</div>
        <?php else: ?>
          <table class="compliance-table">
            <thead>
              <tr>
                <th style="width:260px;">Date</th>
                <th>Post</th>
                <th style="width:320px; text-align:right;">Staff Ack</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($complianceRows as $r): ?>
                <?php
                  $cpid = (int)$r['id'];
                  $ack = (int)$r['staff_ack_count'];
                  $pct = ($staffTotal > 0) ? (int)round(($ack / $staffTotal) * 100) : 0;
                  $pending = max(0, $staffTotal - $ack);
                  $label = post_label((string)$r['post_type']);
                ?>
                <tr>
                  <td><?= h((string)$r['created_at']) ?></td>
                  <td>
                    <a href="feed.php?id=<?= $cpid ?>" style="font-weight:900; color:#2563eb; text-decoration:none;">
                      <?= h($label) ?>
                    </a>
                  </td>
                  <td style="text-align:right;">
                    <span class="pill"><?= $ack ?>/<?= (int)$staffTotal ?> (<?= $pct ?>%)</span>
                    <span class="pill" style="margin-left:10px;">Pending: <?= (int)$pending ?></span>
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

<!-- ✅ Modal JS (remove if already loaded in footer.php) -->
<script>
  // Load jQuery/Bootstrap only if they are missing
  (function () {
    function loadScript(src, cb){
      var s=document.createElement('script');
      s.src=src; s.onload=function(){cb && cb();};
      document.body.appendChild(s);
    }

    function ensureBootstrap(){
      if (window.jQuery && window.jQuery.fn && window.jQuery.fn.modal) return;
      if (!window.jQuery) {
        loadScript('../lib/jquery/jquery.js', function(){
          loadScript('../lib/bootstrap/bootstrap.js');
        });
      } else {
        loadScript('../lib/bootstrap/bootstrap.js');
      }
    }

    ensureBootstrap();
  })();
</script>

<?php require_once __DIR__ . '/includes/org_layout.php'; org_layout_footer_assets(); ?>
</body>
</html>
