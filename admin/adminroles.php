<?php
/**
 * admin/adminroles.php
 * Admin accounts & roles — viewport-fit UI matching userlist.php.
 * Preserves delete one/all, block/unblock, fries menu, DataTables search.
 */
require_once __DIR__ . '/includes/session_admin.php';
requireAdminLogin();
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/controller.php';
require_once __DIR__ . '/includes/admin_account_helpers_load.php';

$adminLogin = $_SESSION['admin_login'] ?? '';
$adminRole  = (int)($_SESSION['userRole'] ?? 0);
$isAdmin    = ($adminRole === 1);
$currentAdminId = (int)($_SESSION['admin_id'] ?? 0);

$controller = new Controller();
$dbh = $controller->pdo();

if (!$isAdmin) {
    header('Location: dashboard.php');
    exit;
}

$msg = trim((string)($_GET['msg'] ?? ''));
$error = '';
$createdFriendCode = trim((string)($_GET['fc'] ?? ''));

if (isset($_POST['delete_admin'])) {
    $id = (int)($_POST['delete_id'] ?? 0);
    $result = admin_account_delete_one($dbh, $id, $currentAdminId);
    if (!empty($result['ok'])) {
        $msg = 'Admin account deleted successfully.';
    } else {
        $error = (string)($result['error'] ?? 'Delete failed.');
    }
}

if (isset($_POST['delete_all'])) {
    $result = admin_account_delete_all($dbh, $currentAdminId);
    if (!empty($result['ok'])) {
        $msg = 'All other admin accounts deleted. Your account was kept.';
    } else {
        $error = (string)($result['error'] ?? 'Delete all failed.');
    }
}

if (isset($_POST['set_status'])) {
    $aid = (int)($_POST['status_id'] ?? 0);
    $newStatus = (int)($_POST['status_value'] ?? 0) === 1 ? 1 : 0;
    $result = admin_account_set_status($dbh, $aid, $newStatus, $currentAdminId);
    if (!empty($result['ok'])) {
        $msg = $newStatus === 1 ? 'Account unblocked. Admin can sign in again.' : 'Account blocked. Admin cannot sign in.';
    } else {
        $error = (string)($result['error'] ?? 'Status update failed.');
    }
}

$sql = "
    SELECT
        a.idadmin,
        a.fullname,
        a.username,
        a.friend_code,
        a.email,
        a.image,
        a.status,
        a.created_at,
        r.name AS role_name
    FROM admin a
    LEFT JOIN role r ON r.idrole = a.role
    ORDER BY a.idadmin DESC
";
$stmt = $dbh->prepare($sql);
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_OBJ);

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function fmt_created($dt): string {
    $dt = (string)$dt;
    if ($dt === '') return '';
    $ts = strtotime($dt);
    if (!$ts) return $dt;
    return date('M j, Y g:i A', $ts);
}

function initials2(string $name): string {
    $name = trim((string)$name);
    if ($name === '') return '??';
    $name = str_replace(['_', '.', '-', '@'], ' ', $name);
    $name = trim(preg_replace('/\s+/', ' ', $name) ?? $name);
    if ($name === '') return '??';
    $parts = array_values(array_filter(explode(' ', $name), fn($p)=>trim($p) !== ''));
    if (!$parts) return '??';
    $first = mb_strtoupper(mb_substr($parts[0], 0, 1));
    $second = '';
    if (count($parts) > 1) $second = mb_strtoupper(mb_substr($parts[count($parts)-1], 0, 1));
    else $second = mb_strtoupper(mb_substr($parts[0], 1, 1));
    $ini = trim($first . $second);
    return $ini !== '' ? $ini : '??';
}

function avatarColor(string $key): string {
    $key = strtolower(trim($key));
    $hash = crc32($key);
    $palette = ['#2563eb','#7c3aed','#db2777','#ea580c','#16a34a','#0f766e','#0891b2','#475569'];
    return $palette[$hash % count($palette)];
}

function role_slug(string $roleName): string {
    $rk = strtolower(trim($roleName));
    if ($rk === '') return 'unknown';
    $rk = preg_replace('/[^a-z0-9]+/', '-', $rk) ?? $rk;
    return trim($rk, '-') ?: 'unknown';
}

function role_badge_class(string $slug): string {
    if ($slug === 'admin') return 'admin';
    if ($slug === 'manager') return 'manager';
    if ($slug === 'gospel') return 'gospel';
    if ($slug === 'staff') return 'staff';
    return 'unknown';
}

$totalAdmins = count($rows);
$activeAdmins = 0;
$blockedAdmins = 0;
$roleCounts = [];
$new30d = 0;
$cutoff30 = strtotime('-30 days');
foreach ($rows as $r) {
    $st = (int)($r->status ?? 0);
    if ($st === 1) {
        $activeAdmins++;
    } else {
        $blockedAdmins++;
    }
    $slug = role_slug((string)($r->role_name ?? 'Unknown'));
    $label = trim((string)($r->role_name ?? '')) !== '' ? (string)$r->role_name : 'Unknown';
    if (!isset($roleCounts[$slug])) {
        $roleCounts[$slug] = ['label' => $label, 'count' => 0];
    }
    $roleCounts[$slug]['count']++;
    $cts = strtotime((string)($r->created_at ?? ''));
    if ($cts && $cts >= $cutoff30) {
        $new30d++;
    }
}

// Prefer a stable order for known roles, then the rest
$roleOrder = ['admin', 'manager', 'staff', 'gospel'];
$orderedRoles = [];
foreach ($roleOrder as $k) {
    if (isset($roleCounts[$k])) {
        $orderedRoles[$k] = $roleCounts[$k];
        unset($roleCounts[$k]);
    }
}
foreach ($roleCounts as $k => $v) {
    $orderedRoles[$k] = $v;
}

$listRole = strtolower(trim((string)($_GET['role'] ?? 'all')));
if ($listRole !== 'all' && !isset($orderedRoles[$listRole])) {
    $listRole = 'all';
}

$visibleSeed = $listRole === 'all'
    ? $totalAdmins
    : (int)($orderedRoles[$listRole]['count'] ?? 0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
  <title>Roles &amp; Accounts</title>
  <link href="../lib/font-awesome/css/font-awesome.css" rel="stylesheet">
  <link href="../lib/Ionicons/css/ionicons.css" rel="stylesheet">
  <link href="../lib/perfect-scrollbar/css/perfect-scrollbar.css" rel="stylesheet">
  <link href="../lib/datatables/jquery.dataTables.css" rel="stylesheet">
  <link href="../lib/select2/css/select2.min.css" rel="stylesheet">
  <link rel="stylesheet" href="../css/shamcey.css">
  <link rel="stylesheet" href="css/admin-tables-shamcey.css?v=6">
  <style>
    html,body{height:100%;overflow:hidden;}
    .sh-mainpanel{height:100vh;display:flex;flex-direction:column;overflow:hidden;}
    .sh-mainpanel > .sh-pagebody{
      overflow:hidden !important;display:flex !important;flex-direction:column !important;min-height:0 !important;
      padding-top:8px !important;padding-bottom:8px !important;flex:1 1 auto;background:#f4f6fb;
    }
    .ar-wrap{
      flex:1 1 auto;min-height:0;width:100%;max-width:100%;
      display:flex;flex-direction:column;gap:8px;overflow:hidden;padding:0 2px;box-sizing:border-box;
    }
    .ar-top{flex:0 0 auto;display:flex;align-items:center;justify-content:flex-end;gap:10px;min-width:0;}
    .ar-actions{display:flex;gap:6px;flex-wrap:wrap;align-items:center;}
    .ar-btn{
      height:30px;padding:0 10px;border-radius:8px;border:1px solid #e2e8f0;background:#fff;
      font-size:11px;font-weight:700;color:#334155;display:inline-flex;align-items:center;gap:5px;
      text-decoration:none;cursor:pointer;white-space:nowrap;
    }
    .ar-btn:hover{background:#f8fafc;text-decoration:none;color:#0f172a;}
    .ar-btn.primary{background:#2563eb;border-color:#2563eb;color:#fff;}
    .ar-btn.primary:hover{background:#1d4ed8;color:#fff;}
    .ar-btn.danger{background:#fff;border-color:#fecaca;color:#b91c1c;}
    .ar-btn.danger:hover{background:#fef2f2;}
    .ar-btn:disabled{opacity:.45;pointer-events:none;}

    .ar-cards{
      flex:0 0 auto;display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:8px;
    }
    .ar-card{
      background:#fff;border:1px solid #eef2f7;border-radius:12px;padding:10px 12px;
      box-shadow:0 1px 2px rgba(15,23,42,.04);min-width:0;
    }
    .ar-card-top{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:6px;}
    .ar-card-top .lab{font-size:11px;font-weight:700;color:#64748b;}
    .ar-card-top .delta{font-size:10px;font-weight:800;color:#94a3b8;}
    .ar-ico{
      width:28px;height:28px;border-radius:999px;display:flex;align-items:center;justify-content:center;font-size:12px;flex:0 0 auto;
    }
    .ar-ico.purple{background:#f5f3ff;color:#7c3aed;}
    .ar-ico.green{background:#f0fdf4;color:#16a34a;}
    .ar-ico.blue{background:#dbeafe;color:#2563eb;}
    .ar-ico.orange{background:#fff7ed;color:#ea580c;}
    .ar-ico.red{background:#fef2f2;color:#dc2626;}
    .ar-ico.cyan{background:#ecfeff;color:#0891b2;}
    .ar-card .val{font-size:20px;font-weight:800;color:#0f172a;line-height:1;}
    .ar-card .sub{font-size:10px;color:#94a3b8;font-weight:600;margin-top:4px;}
    .ar-card.is-kind{cursor:pointer;transition:border-color .15s, box-shadow .15s;}
    .ar-card.is-kind:hover{border-color:#bfdbfe;}
    .ar-card.is-kind.is-active{border-color:#2563eb;box-shadow:0 0 0 1px #2563eb inset;}

    .ar-kinds{
      flex:0 0 auto;display:flex;gap:0;background:#fff;border:1px solid #eef2f7;border-radius:10px;
      padding:0 4px;overflow:hidden;min-width:0;
    }
    .ar-kinds a{
      flex:0 0 auto;padding:8px 14px;font-size:12px;font-weight:800;color:#64748b;text-decoration:none;
      border-bottom:2px solid transparent;white-space:nowrap;
    }
    .ar-kinds a .cnt{font-weight:700;color:#94a3b8;margin-left:4px;}
    .ar-kinds a.is-active{color:#2563eb;border-bottom-color:#2563eb;}
    .ar-kinds a:hover{color:#0f172a;text-decoration:none;}

    .ar-main{
      flex:1 1 auto;min-height:0;min-width:0;
      background:#fff;border:1px solid #eef2f7;border-radius:12px;overflow:hidden;
      box-shadow:0 1px 2px rgba(15,23,42,.04);
      display:flex;flex-direction:column;
    }
    .ar-filters{
      flex:0 0 auto;display:flex;gap:6px;flex-wrap:wrap;align-items:center;
      padding:10px 12px;border-bottom:1px solid #eef2f7;background:#fafbfc;
    }
    .ar-search{position:relative;flex:1 1 180px;min-width:140px;max-width:280px;}
    .ar-search i{position:absolute;left:9px;top:50%;transform:translateY(-50%);color:#94a3b8;font-size:12px;}
    .ar-search input,.ar-filters select{
      height:30px;border:1px solid #e2e8f0;border-radius:8px;padding:0 9px;font-size:11px;background:#fff;color:#0f172a;
    }
    .ar-search input{width:100%;padding-left:28px;}
    .ar-clear{font-size:11px;font-weight:700;color:#2563eb;text-decoration:none;display:inline-flex;align-items:center;gap:4px;margin-left:auto;}
    .ar-clear:hover{text-decoration:underline;}

    .ar-table-wrap{flex:1 1 auto;min-height:0;overflow-x:hidden;overflow-y:auto;overscroll-behavior:contain;}
    .ar-table{width:100%;table-layout:fixed;border-collapse:separate;border-spacing:0;min-width:0;}
    .ar-table th{
      text-align:left;font-size:9px;font-weight:800;letter-spacing:.03em;text-transform:uppercase;
      color:#64748b;padding:8px 6px;border-bottom:1px solid #eef2f7;background:#fff;
      position:sticky;top:0;z-index:3;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;
    }
    .ar-table td{
      padding:8px 6px;border-bottom:1px solid #f1f5f9;vertical-align:middle;font-size:11px;color:#0f172a;overflow:hidden;
    }
    .ar-table tr:hover td{background:#f8fafc;}
    .ar-table th:nth-child(1),.ar-table td:nth-child(1){width:28px;}
    .ar-table th:nth-child(2),.ar-table td:nth-child(2){width:56px;}
    .ar-table th:nth-child(3),.ar-table td:nth-child(3){width:22%;}
    .ar-table th:nth-child(4),.ar-table td:nth-child(4){width:18%;}
    .ar-table th:nth-child(5),.ar-table td:nth-child(5){width:12%;}
    .ar-table th:nth-child(6),.ar-table td:nth-child(6){width:90px;}
    .ar-table th:nth-child(7),.ar-table td:nth-child(7){width:90px;}
    .ar-table th:nth-child(8),.ar-table td:nth-child(8){width:14%;}
    .ar-table th:nth-child(9),.ar-table td:nth-child(9){width:40px;}

    .ar-id{color:#2563eb;font-weight:800;text-decoration:none;white-space:nowrap;}
    .ar-id:hover{text-decoration:underline;}
    .ar-user{display:flex;align-items:center;gap:8px;min-width:0;}
    .ar-av{
      width:28px;height:28px;border-radius:999px;color:#fff;font-size:10px;font-weight:800;
      display:flex;align-items:center;justify-content:center;flex:0 0 28px;object-fit:cover;
    }
    .ar-user .nm{font-weight:800;font-size:11px;color:#0f172a;line-height:1.2;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
    .ar-user .un{font-size:10px;color:#64748b;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
    .ar-email{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:#334155;}
    .ar-mono{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace;font-size:10px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
    .ar-role,.ar-status{
      display:inline-flex;align-items:center;gap:4px;padding:2px 7px;border-radius:999px;font-size:10px;font-weight:800;white-space:nowrap;
    }
    .ar-role.admin{background:#dbeafe;color:#1d4ed8;}
    .ar-role.manager{background:#dcfce7;color:#15803d;}
    .ar-role.gospel{background:#cffafe;color:#0e7490;}
    .ar-role.staff{background:#ffedd5;color:#c2410c;}
    .ar-role.unknown{background:#f1f5f9;color:#475569;}
    .ar-status.active{background:#dcfce7;color:#15803d;}
    .ar-status.blocked{background:#fee2e2;color:#b91c1c;}
    .ar-status .dot{width:6px;height:6px;border-radius:999px;background:currentColor;}
    .ar-when{font-size:10px;color:#475569;line-height:1.25;}
    .ar-alert{flex:0 0 auto;padding:7px 9px;border-radius:8px;font-size:12px;font-weight:700;}
    .ar-alert.ok{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0;}
    .ar-alert.bad{background:#fef2f2;color:#991b1b;border:1px solid #fecaca;}
    .ar-foot{
      flex:0 0 auto;display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;
      padding:10px 12px;border-top:1px solid #eef2f7;background:#fff;
    }
    .ar-foot .muted{font-size:11px;color:#64748b;font-weight:600;}
    .dataTables_wrapper .dataTables_paginate{float:none;text-align:center;padding:0;}
    .dataTables_wrapper .dataTables_paginate .paginate_button{
      min-width:28px !important;height:28px !important;padding:0 7px !important;margin:0 2px !important;
      border-radius:7px !important;border:1px solid #e2e8f0 !important;background:#fff !important;
      font-size:11px !important;font-weight:700 !important;line-height:26px !important;box-sizing:border-box;
    }
    .dataTables_wrapper .dataTables_paginate .paginate_button.current{
      background:#2563eb !important;border-color:#2563eb !important;color:#fff !important;
    }
    .dataTables_wrapper .dataTables_info{display:none;}
    .dataTables_wrapper .dataTables_length,.dataTables_wrapper .dataTables_filter{display:none !important;}
    .dataTables_wrapper .top,.dataTables_wrapper .bottom{display:none;}
    #datatable1_wrapper{display:contents;}
    #datatable1{width:100% !important;margin:0 !important;}
    @media (max-width:1100px){
      .ar-wrap{overflow:auto;}
      .ar-cards{grid-template-columns:repeat(2,minmax(0,1fr));}
    }
  </style>
</head>
<body>
<?php
$adminChromePageIntro = [
    'title' => 'Roles & Accounts',
    'description' => 'Manage admin-side accounts, assign roles, and control sign-in access.',
];
include('includes/leftbar.php');
include('includes/header.php');
?>

<div class="sh-mainpanel">
  <div class="sh-pagebody">
    <div class="ar-wrap">

      <?php if ($error): ?>
        <div class="ar-alert bad"><?php echo h($error); ?></div>
      <?php elseif ($msg): ?>
        <div class="ar-alert ok">
          <?php echo h($msg); ?>
          <?php if ($createdFriendCode !== ''): ?>
            <span style="margin-left:8px;">Friend code: <b><?php echo h($createdFriendCode); ?></b></span>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <div class="ar-top">
        <div class="ar-actions">
          <button type="button" class="ar-btn" title="Export coming soon"><i class="fa fa-download"></i> Export</button>
          <button type="button" class="ar-btn" onclick="document.getElementById('arFilters').scrollIntoView({behavior:'smooth',block:'center'})"><i class="fa fa-sliders"></i> Filters</button>
          <a class="ar-btn" href="roleslist.php"><i class="fa fa-id-badge"></i> Roles &amp; Permissions</a>
          <a class="ar-btn primary" href="admin_form.php"><i class="fa fa-plus"></i> Add Admin</a>
          <button type="button" class="ar-btn danger" <?php echo ($totalAdmins <= 1) ? 'disabled' : ''; ?> data-toggle="modal" data-target="#deleteAllModal"><i class="fa fa-trash"></i> Delete All</button>
        </div>
      </div>

      <div class="ar-cards">
        <div class="ar-card is-kind<?php echo $listRole === 'all' ? ' is-active' : ''; ?>" data-role="all" role="button" tabindex="0">
          <div class="ar-card-top">
            <div style="display:flex;align-items:center;gap:8px;">
              <div class="ar-ico purple"><i class="fa fa-users"></i></div>
              <div class="lab">Total Admins</div>
            </div>
            <div class="delta">• all</div>
          </div>
          <div class="val"><?php echo number_format($totalAdmins); ?></div>
          <div class="sub">All roles</div>
        </div>
        <div class="ar-card">
          <div class="ar-card-top">
            <div style="display:flex;align-items:center;gap:8px;">
              <div class="ar-ico green"><i class="fa fa-user-plus"></i></div>
              <div class="lab">New (30d)</div>
            </div>
            <div class="delta">↑ 30d</div>
          </div>
          <div class="val"><?php echo number_format($new30d); ?></div>
          <div class="sub">Created recently</div>
        </div>
        <div class="ar-card">
          <div class="ar-card-top">
            <div style="display:flex;align-items:center;gap:8px;">
              <div class="ar-ico blue"><i class="fa fa-check-circle"></i></div>
              <div class="lab">Active</div>
            </div>
            <div class="delta">• live</div>
          </div>
          <div class="val"><?php echo number_format($activeAdmins); ?></div>
          <div class="sub">Can sign in</div>
        </div>
        <div class="ar-card">
          <div class="ar-card-top">
            <div style="display:flex;align-items:center;gap:8px;">
              <div class="ar-ico red"><i class="fa fa-ban"></i></div>
              <div class="lab">Blocked</div>
            </div>
            <div class="delta">• status</div>
          </div>
          <div class="val"><?php echo number_format($blockedAdmins); ?></div>
          <div class="sub">Cannot sign in</div>
        </div>
        <?php
          $cardRoles = array_slice($orderedRoles, 0, 2, true);
          $cardIcons = ['admin' => 'blue', 'manager' => 'green', 'staff' => 'orange', 'gospel' => 'cyan', 'unknown' => 'purple'];
          $cardFa = ['admin' => 'fa-shield', 'manager' => 'fa-briefcase', 'staff' => 'fa-id-badge', 'gospel' => 'fa-book', 'unknown' => 'fa-user'];
          foreach ($cardRoles as $slug => $info):
            $ico = $cardIcons[$slug] ?? 'purple';
            $fa = $cardFa[$slug] ?? 'fa-user';
        ?>
          <div class="ar-card is-kind<?php echo $listRole === $slug ? ' is-active' : ''; ?>" data-role="<?php echo h($slug); ?>" role="button" tabindex="0">
            <div class="ar-card-top">
              <div style="display:flex;align-items:center;gap:8px;">
                <div class="ar-ico <?php echo h($ico); ?>"><i class="fa <?php echo h($fa); ?>"></i></div>
                <div class="lab"><?php echo h((string)$info['label']); ?></div>
              </div>
              <div class="delta">• role</div>
            </div>
            <div class="val"><?php echo number_format((int)$info['count']); ?></div>
            <div class="sub">Filter by role</div>
          </div>
        <?php endforeach; ?>
        <?php if (count($cardRoles) < 2): ?>
          <div class="ar-card">
            <div class="ar-card-top">
              <div style="display:flex;align-items:center;gap:8px;">
                <div class="ar-ico purple"><i class="fa fa-id-badge"></i></div>
                <div class="lab">Roles</div>
              </div>
              <div class="delta">• defs</div>
            </div>
            <div class="val"><?php echo number_format(count($orderedRoles)); ?></div>
            <div class="sub"><a href="roleslist.php" style="color:#2563eb;text-decoration:none;font-weight:700;">Manage roles</a></div>
          </div>
        <?php endif; ?>
      </div>

      <nav class="ar-kinds" id="arRoleTabs" aria-label="Admin role">
        <a href="?role=all" data-role="all" class="<?php echo $listRole === 'all' ? 'is-active' : ''; ?>">All <span class="cnt">(<?php echo (int)$totalAdmins; ?>)</span></a>
        <?php foreach ($orderedRoles as $slug => $info): ?>
          <a href="?role=<?php echo rawurlencode($slug); ?>" data-role="<?php echo h($slug); ?>" class="<?php echo $listRole === $slug ? 'is-active' : ''; ?>">
            <?php echo h((string)$info['label']); ?> <span class="cnt">(<?php echo (int)$info['count']; ?>)</span>
          </a>
        <?php endforeach; ?>
      </nav>

      <div class="ar-main">
        <div class="ar-filters" id="arFilters">
          <div class="ar-search">
            <i class="fa fa-search"></i>
            <input type="search" id="arSearchInput" placeholder="Search name, username, email, friend code..." autocomplete="off">
          </div>
          <select id="arStatusFilter" aria-label="Status">
            <option value="all">All Status</option>
            <option value="active">Active</option>
            <option value="blocked">Blocked</option>
          </select>
          <select id="arRoleFilter" aria-label="Role">
            <option value="all"<?php echo $listRole === 'all' ? ' selected' : ''; ?>>All Roles (<?php echo (int)$totalAdmins; ?>)</option>
            <?php foreach ($orderedRoles as $slug => $info): ?>
              <option value="<?php echo h($slug); ?>"<?php echo $listRole === $slug ? ' selected' : ''; ?>>
                <?php echo h((string)$info['label']); ?> (<?php echo (int)$info['count']; ?>)
              </option>
            <?php endforeach; ?>
          </select>
          <select id="arPageLen" aria-label="Per page">
            <option value="10" selected>10 / page</option>
            <option value="25">25 / page</option>
            <option value="50">50 / page</option>
            <option value="100">100 / page</option>
          </select>
          <a href="#" class="ar-clear" id="arClearFilters"><i class="fa fa-refresh"></i> Clear Filters</a>
        </div>

        <div class="ar-table-wrap">
          <table id="datatable1" class="ar-table display" style="width:100%;">
            <thead>
              <tr>
                <th><input type="checkbox" disabled title="Bulk select coming soon"></th>
                <th>Admin ID</th>
                <th>Account</th>
                <th>Email</th>
                <th>Friend Code</th>
                <th>Role</th>
                <th>Status</th>
                <th>Created</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($rows as $row):
                $aid = (int)($row->idadmin ?? 0);
                $img = !empty($row->image) ? (string)$row->image : '';
                $full = (string)($row->fullname ?? '');
                $uname = (string)($row->username ?? '');
                $email = (string)($row->email ?? '');
                $fcode = (string)($row->friend_code ?? '');
                $roleName = trim((string)($row->role_name ?? '')) !== '' ? (string)$row->role_name : 'Unknown';
                $slug = role_slug($roleName);
                $badge = role_badge_class($slug);
                $status = (int)($row->status ?? 0);
                $isActive = ($status === 1);
                $isSelf = ($currentAdminId > 0 && $aid === $currentAdminId);
                $imgPath = $img !== '' ? 'images/' . $img : '';
                $labelForIni = $full !== '' ? $full : ($uname !== '' ? $uname : 'Admin');
                $ini = initials2($labelForIni);
                $bg = avatarColor($email !== '' ? $email : ($full !== '' ? $full : (string)$aid));
                $statusKey = $isActive ? 'active' : 'blocked';
              ?>
              <tr data-role="<?php echo h($slug); ?>" data-status="<?php echo h($statusKey); ?>">
                <td><input type="checkbox" disabled></td>
                <td><a class="ar-id" href="admin_form.php?admin_id=<?php echo $aid; ?>">#<?php echo $aid; ?></a></td>
                <td>
                  <div class="ar-user">
                    <?php if ($imgPath !== ''): ?>
                      <img class="ar-av" src="<?php echo h($imgPath); ?>" alt=""
                           onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
                      <span class="ar-av" style="display:none;background:<?php echo h($bg); ?>;"><?php echo h($ini); ?></span>
                    <?php else: ?>
                      <span class="ar-av" style="background:<?php echo h($bg); ?>;"><?php echo h($ini); ?></span>
                    <?php endif; ?>
                    <div style="min-width:0;">
                      <div class="nm"><?php echo h($full !== '' ? $full : '—'); ?><?php if ($isSelf): ?> <span style="color:#2563eb;font-size:9px;">(you)</span><?php endif; ?></div>
                      <div class="un"><?php echo h($uname !== '' ? '@' . $uname : '—'); ?></div>
                    </div>
                  </div>
                </td>
                <td><div class="ar-email" title="<?php echo h($email); ?>"><?php echo h($email); ?></div></td>
                <td><div class="ar-mono" title="<?php echo h($fcode); ?>"><?php echo h($fcode !== '' ? $fcode : '—'); ?></div></td>
                <td><span class="ar-role <?php echo h($badge); ?>"><i class="fa fa-shield"></i> <?php echo h($roleName); ?></span></td>
                <td>
                  <?php if ($isActive): ?>
                    <span class="ar-status active"><span class="dot"></span> Active</span>
                  <?php else: ?>
                    <span class="ar-status blocked"><span class="dot"></span> Blocked</span>
                  <?php endif; ?>
                </td>
                <td><div class="ar-when"><?php echo h(fmt_created($row->created_at ?? '')); ?></div></td>
                <td>
                  <div class="fries-menu">
                    <button type="button" class="fries-toggle" title="Actions" aria-label="Actions" aria-haspopup="true">
                      <span class="fries-icon" aria-hidden="true"></span>
                    </button>
                    <div class="fries-dropdown" role="menu">
                      <a class="fries-item" role="menuitem" href="admin_form.php?admin_id=<?php echo $aid; ?>">
                        <i class="fa fa-pencil"></i> Edit
                      </a>
                      <button type="button"
                              class="fries-item"
                              role="menuitem"
                              data-id="<?php echo $aid; ?>"
                              data-email="<?php echo h($email); ?>"
                              data-name="<?php echo h($full !== '' ? $full : $uname); ?>"
                              data-status="<?php echo $isActive ? '0' : '1'; ?>"
                              <?php echo $isSelf ? 'disabled' : ''; ?>
                              onclick="openStatusModal(this);">
                        <i class="fa <?php echo $isActive ? 'fa-ban' : 'fa-check'; ?>"></i>
                        <?php echo $isActive ? 'Block' : 'Unblock'; ?>
                      </button>
                      <button type="button"
                              class="fries-item fries-item-danger"
                              role="menuitem"
                              data-id="<?php echo $aid; ?>"
                              data-email="<?php echo h($email); ?>"
                              data-name="<?php echo h($full !== '' ? $full : $uname); ?>"
                              <?php echo $isSelf ? 'disabled' : ''; ?>
                              onclick="openDeleteModal(this);">
                        <i class="fa fa-trash"></i> Delete
                      </button>
                    </div>
                  </div>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <div class="ar-foot">
          <div class="muted" id="arShowing">Showing 0 admins</div>
          <div id="arPagerHost"></div>
          <div class="muted"><span id="visibleAdminCount"><?php echo (int)$visibleSeed; ?></span> in this view</div>
        </div>
      </div>

    </div>
  </div>

  <div class="modal fade" id="deleteAdminModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
      <div class="modal-content" style="border-radius:16px;overflow:hidden;">
        <form method="post">
          <div class="modal-header" style="background:rgba(239,68,68,.10);border-bottom:1px solid rgba(17,24,39,.10);">
            <h4 class="modal-title" style="font-weight:900;">
              <i class="fa fa-exclamation-triangle text-danger mg-r-6"></i> Confirm Delete
            </h4>
            <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
          </div>
          <div class="modal-body">
            <p style="margin-bottom:6px;">Delete this admin account permanently?</p>
            <p style="margin:0;"><b id="delAdminName"></b></p>
            <p class="ar-mono" style="opacity:.75;margin-top:6px;" id="delAdminEmail"></p>
            <input type="hidden" name="delete_id" id="delAdminId" value="">
          </div>
          <div class="modal-footer" style="border-top:1px solid rgba(17,24,39,.10);">
            <button type="button" class="btn btn-light" data-dismiss="modal">Cancel</button>
            <button type="submit" name="delete_admin" class="btn btn-danger">
              <i class="fa fa-trash mg-r-6"></i> Delete
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <div class="modal fade" id="deleteAllModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
      <div class="modal-content" style="border-radius:16px;overflow:hidden;">
        <form method="post">
          <div class="modal-header" style="background:rgba(239,68,68,.10);border-bottom:1px solid rgba(17,24,39,.10);">
            <h4 class="modal-title" style="font-weight:900;">
              <i class="fa fa-exclamation-triangle text-danger mg-r-6"></i> Delete ALL Other Admins
            </h4>
            <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
          </div>
          <div class="modal-body">
            <p style="font-weight:800;">You are about to delete every admin account except your own.</p>
            <p style="color:#b91c1c;font-weight:800;">This cannot be undone.</p>
          </div>
          <div class="modal-footer" style="border-top:1px solid rgba(17,24,39,.10);">
            <button type="button" class="btn btn-light" data-dismiss="modal">Cancel</button>
            <button type="submit" name="delete_all" class="btn btn-danger">
              <i class="fa fa-trash mg-r-6"></i> Delete All Others
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <div class="modal fade" id="statusModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
      <div class="modal-content" style="border-radius:16px;overflow:hidden;">
        <form method="post">
          <div class="modal-header" style="background:rgba(37,99,235,.10);border-bottom:1px solid rgba(17,24,39,.10);">
            <h4 class="modal-title" id="statusModalTitle" style="font-weight:900;">Block Account</h4>
            <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
          </div>
          <div class="modal-body">
            <p id="statusModalText" style="margin:0;"></p>
            <p class="ar-mono" style="opacity:.75;margin-top:6px;" id="statusModalEmail"></p>
            <input type="hidden" name="status_id" id="statusAdminId" value="">
            <input type="hidden" name="status_value" id="statusValue" value="">
          </div>
          <div class="modal-footer" style="border-top:1px solid rgba(17,24,39,.10);">
            <button type="button" class="btn btn-light" data-dismiss="modal">Cancel</button>
            <button type="submit" name="set_status" class="btn btn-primary" id="statusGoBtn">Continue</button>
          </div>
        </form>
      </div>
    </div>
  </div>

</div>

<script src="../lib/jquery/jquery.js"></script>
<script src="../lib/popper.js/popper.js"></script>
<script src="../lib/bootstrap/bootstrap.js"></script>
<script src="../lib/perfect-scrollbar/js/perfect-scrollbar.jquery.js"></script>
<script src="../lib/datatables/jquery.dataTables.js"></script>
<script src="../lib/select2/js/select2.min.js"></script>
<script src="../js/shamcey.js"></script>

<script>
  function openDeleteModal(btn){
    var id = btn.getAttribute('data-id');
    var email = btn.getAttribute('data-email') || '';
    var name = btn.getAttribute('data-name') || 'Admin';
    document.getElementById('delAdminName').textContent = name;
    document.getElementById('delAdminEmail').textContent = email;
    document.getElementById('delAdminId').value = id;
    $('#deleteAdminModal').modal('show');
  }

  function openStatusModal(btn){
    var id = btn.getAttribute('data-id');
    var email = btn.getAttribute('data-email') || '';
    var name = btn.getAttribute('data-name') || 'Admin';
    var st = btn.getAttribute('data-status');
    document.getElementById('statusAdminId').value = id;
    document.getElementById('statusValue').value = st;
    if (st === '1') {
      document.getElementById('statusModalTitle').textContent = 'Unblock Account';
      document.getElementById('statusModalText').textContent = 'Allow sign-in again for: ' + name + '?';
      document.getElementById('statusGoBtn').className = 'btn btn-primary';
      document.getElementById('statusGoBtn').textContent = 'Unblock';
    } else {
      document.getElementById('statusModalTitle').textContent = 'Block Account';
      document.getElementById('statusModalText').textContent = 'Block sign-in for: ' + name + '?';
      document.getElementById('statusGoBtn').className = 'btn btn-warning';
      document.getElementById('statusGoBtn').textContent = 'Block';
    }
    document.getElementById('statusModalEmail').textContent = email;
    $('#statusModal').modal('show');
  }

  $(function() {
    'use strict';
    var activeRole = <?php echo json_encode($listRole, JSON_UNESCAPED_SLASHES); ?>;
    var activeStatus = 'all';

    $.fn.dataTable.ext.search.push(function(settings, data, dataIndex) {
      if (settings.nTable.id !== 'datatable1') return true;
      var row = settings.aoData[dataIndex].nTr;
      if (!row) return true;
      if (activeRole !== 'all' && row.getAttribute('data-role') !== activeRole) return false;
      if (activeStatus !== 'all' && row.getAttribute('data-status') !== activeStatus) return false;
      return true;
    });

    var dt = $('#datatable1').DataTable({
      paging: true,
      pageLength: 10,
      lengthChange: false,
      info: true,
      responsive: false,
      autoWidth: false,
      scrollX: false,
      order: [[1, 'desc']],
      columnDefs: [
        { orderable: false, targets: [0, 8] }
      ],
      dom: 'tp',
      language: {
        searchPlaceholder: 'Search...',
        sSearch: '',
        paginate: { previous: '‹', next: '›' }
      },
      drawCallback: function() {
        var api = this.api();
        var info = api.page.info();
        var from = info.recordsDisplay === 0 ? 0 : (info.start + 1);
        var to = info.end;
        $('#arShowing').text('Showing ' + from + ' to ' + to + ' of ' + info.recordsDisplay + ' admins.');
        $('#visibleAdminCount').text(info.recordsDisplay);
        var $pag = $(api.table().container()).find('.dataTables_paginate');
        if ($pag.length) {
          $('#arPagerHost').empty().append($pag);
        }
      }
    });

    setTimeout(function() {
      var $pag = $('#datatable1_paginate');
      if ($pag.length) $('#arPagerHost').empty().append($pag);
    }, 0);

    function syncRoleUi() {
      $('#arRoleFilter').val(activeRole);
      $('#arRoleTabs a').each(function() {
        $(this).toggleClass('is-active', $(this).attr('data-role') === activeRole);
      });
      $('.ar-card.is-kind').each(function() {
        $(this).toggleClass('is-active', $(this).attr('data-role') === activeRole);
      });
    }

    function setRole(role) {
      if (!role) return;
      activeRole = role;
      applyFilters();
    }

    function applyFilters() {
      dt.draw();
      var url = new URL(window.location.href);
      if (activeRole === 'all') url.searchParams.delete('role');
      else url.searchParams.set('role', activeRole);
      window.history.replaceState({}, '', url.toString());
      syncRoleUi();
    }

    $('#arSearchInput').on('input', function() {
      dt.search(this.value).draw();
    });
    $('#arStatusFilter').on('change', function() {
      activeStatus = this.value;
      applyFilters();
    });
    $('#arRoleFilter').on('change', function() {
      setRole(this.value);
    });
    $('#arRoleTabs').on('click', 'a', function(e) {
      e.preventDefault();
      setRole($(this).attr('data-role'));
    });
    $('.ar-card.is-kind').on('click', function() {
      setRole($(this).attr('data-role'));
    }).on('keydown', function(e) {
      if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        setRole($(this).attr('data-role'));
      }
    });
    $('#arPageLen').on('change', function() {
      dt.page.len(parseInt(this.value, 10) || 10).draw();
    });
    $('#arClearFilters').on('click', function(e) {
      e.preventDefault();
      activeStatus = 'all';
      $('#arStatusFilter').val('all');
      $('#arSearchInput').val('');
      dt.search('');
      applyFilters();
    });

    applyFilters();
  });
</script>
</body>
</html>
