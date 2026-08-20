<?php
/**
 * roleslist.php — Roles & Permissions (viewport-fit UI).
 */
require_once __DIR__ . '/includes/session_admin.php';
requireAdminLogin();
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/controller.php';

$adminLogin = $_SESSION['admin_login'] ?? '';
$adminRole  = (int)($_SESSION['userRole'] ?? 0);
$isAdmin    = ($adminRole === 1);

$controller = new Controller();
$dbh = $controller->pdo();

if (!$isAdmin) {
    header('Location: dashboard.php');
    exit;
}

$msg = '';
$error = '';

/** System / default roles that cannot be deleted or renamed. */
$lockedRoleIds = [1, 2, 3, 4];

/** @return list<array{key:string,label:string,actions:list<string>}> */
function roles_permission_modules(): array
{
    return [
        ['key' => 'dashboard', 'label' => 'Dashboard', 'actions' => ['view']],
        ['key' => 'users', 'label' => 'User Management', 'actions' => ['view', 'create', 'edit', 'delete']],
        ['key' => 'content', 'label' => 'Content', 'actions' => ['view', 'create', 'edit', 'delete', 'manage']],
        ['key' => 'comments', 'label' => 'Comments', 'actions' => ['view', 'create', 'edit', 'delete']],
        ['key' => 'reports', 'label' => 'Reports', 'actions' => ['view', 'edit', 'delete', 'manage']],
        ['key' => 'analytics', 'label' => 'Analytics', 'actions' => ['view', 'manage']],
        ['key' => 'settings', 'label' => 'Settings', 'actions' => ['view', 'edit', 'manage']],
        ['key' => 'verifications', 'label' => 'Verifications', 'actions' => ['view', 'create', 'edit', 'delete', 'manage']],
        ['key' => 'messages', 'label' => 'Messages', 'actions' => ['view', 'edit', 'delete', 'manage']],
        ['key' => 'monetization', 'label' => 'Monetization', 'actions' => ['view', 'edit', 'manage']],
        ['key' => 'system_logs', 'label' => 'System Logs', 'actions' => ['view', 'manage']],
        ['key' => 'roles', 'label' => 'Roles & Accounts', 'actions' => ['view', 'create', 'edit', 'delete', 'manage']],
        ['key' => 'commerce', 'label' => 'Commerce', 'actions' => ['view', 'edit', 'manage']],
        ['key' => 'publisher', 'label' => 'Publisher', 'actions' => ['view', 'edit', 'manage']],
        ['key' => 'help', 'label' => 'Help', 'actions' => ['view', 'edit', 'manage']],
    ];
}

function roles_all_perm_keys(): array
{
    $keys = [];
    foreach (roles_permission_modules() as $mod) {
        foreach ($mod['actions'] as $act) {
            $keys[] = $mod['key'] . '.' . $act;
        }
    }
    return $keys;
}

function roles_role_description(string $name, bool $isSystem): string
{
    $n = strtolower(trim($name));
    $map = [
        'admin' => 'Manage users, content, reports and settings.',
        'manager' => 'Manage organizations, staff and day-to-day operations.',
        'gospel' => 'Review gospel content and related moderation queues.',
        'staff' => 'Handle assigned support and operational tasks.',
        'teacher' => 'Manage teaching-related content and learners.',
    ];
    if (isset($map[$n])) {
        return $map[$n];
    }
    return $isSystem
        ? 'System role with platform access.'
        : 'Custom role created by admins.';
}

function h($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

/* =============================
   DELETE ROLE
============================= */
if (isset($_POST['delete_role'])) {
    $rid = (int)($_POST['delete_idrole'] ?? 0);

    if ($rid <= 0) {
        $error = 'Invalid role.';
    } elseif (in_array($rid, $lockedRoleIds, true)) {
        $error = 'Default roles cannot be deleted.';
    } else {
        $stCnt = $dbh->prepare('SELECT COUNT(*) FROM admin WHERE role = :rid');
        $stCnt->execute([':rid' => $rid]);
        $cnt = (int)$stCnt->fetchColumn();

        if ($cnt > 0) {
            $error = "Role is assigned to {$cnt} admin(s).";
        } else {
            $dbh->prepare('DELETE FROM role WHERE idrole=:id')->execute([':id' => $rid]);
            $msg = 'Role deleted successfully.';
        }
    }
}

/* =============================
   CREATE ROLE
============================= */
if (isset($_POST['create_role'])) {
    $name = trim((string)($_POST['role_name'] ?? ''));
    $inh  = (int)($_POST['inherits_from'] ?? 0);
    $st   = (int)($_POST['status'] ?? 1);

    if ($name === '') {
        $error = 'Role name required.';
    } else {
        try {
            $dbh->prepare('
                INSERT INTO role(name,inherits_from,status)
                VALUES(:n,:i,:s)
            ')->execute([
                ':n' => $name,
                ':i' => ($inh > 0 ? $inh : null),
                ':s' => ($st === 1 ? 1 : 0),
            ]);
            $msg = 'Role created.';
        } catch (PDOException $e) {
            $error = 'Role already exists.';
        }
    }
}

/* =============================
   UPDATE ROLE
============================= */
if (isset($_POST['update_role'])) {
    $rid  = (int)($_POST['idrole'] ?? 0);
    $name = trim((string)($_POST['role_name'] ?? ''));
    $st   = isset($_POST['status']) ? ((int)$_POST['status'] === 1 ? 1 : 0) : null;

    if ($rid <= 0) {
        $error = 'Invalid input.';
    } elseif (in_array($rid, $lockedRoleIds, true) && $name !== '') {
        // Allow status-only updates for system roles; block rename.
        if ($st === null) {
            $error = 'Default roles cannot be renamed.';
        } else {
            $dbh->prepare('UPDATE role SET status=:s WHERE idrole=:i')->execute([':s' => $st, ':i' => $rid]);
            $msg = 'Role status updated.';
        }
    } elseif ($name === '') {
        $error = 'Invalid input.';
    } else {
        if ($st === null) {
            $dbh->prepare('UPDATE role SET name=:n WHERE idrole=:i')->execute([':n' => $name, ':i' => $rid]);
        } else {
            $dbh->prepare('UPDATE role SET name=:n, status=:s WHERE idrole=:i')
                ->execute([':n' => $name, ':s' => $st, ':i' => $rid]);
        }
        $msg = 'Role updated.';
    }
}

/* =============================
   SAVE PERMISSIONS
============================= */
if (isset($_POST['save_permissions'])) {
    $rid = (int)($_POST['perm_role_id'] ?? 0);
    $allowed = roles_all_perm_keys();
    $posted = $_POST['perms'] ?? [];
    if (!is_array($posted)) {
        $posted = [];
    }
    if ($rid <= 0) {
        $error = 'Select a role first.';
    } else {
        try {
            $dbh->beginTransaction();
            $dbh->prepare('DELETE FROM role_permissions WHERE role_id = :r')->execute([':r' => $rid]);
            $ins = $dbh->prepare('INSERT INTO role_permissions (role_id, perm) VALUES (:r, :p)');
            $saved = 0;
            foreach ($posted as $perm) {
                $perm = strtolower(trim((string)$perm));
                if (!in_array($perm, $allowed, true)) {
                    continue;
                }
                $ins->execute([':r' => $rid, ':p' => $perm]);
                $saved++;
            }
            $dbh->commit();
            $msg = 'Permissions saved (' . $saved . ').';
        } catch (Throwable $e) {
            if ($dbh->inTransaction()) {
                $dbh->rollBack();
            }
            $error = 'Could not save permissions.';
        }
    }
}

/* =============================
   DATA
============================= */
$roles = $dbh->query('SELECT idrole, name, inherits_from, status FROM role ORDER BY idrole')->fetchAll(PDO::FETCH_OBJ);
$baseRoles = $dbh->query('SELECT idrole, name FROM role WHERE status=1 ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);

// First-time seed: empty role_permissions looks like “all dashes / No Access”.
try {
    $permCount = (int)$dbh->query('SELECT COUNT(*) FROM role_permissions')->fetchColumn();
    if ($permCount === 0 && $roles) {
        $allKeys = roles_all_perm_keys();
        $ins = $dbh->prepare('INSERT IGNORE INTO role_permissions (role_id, perm) VALUES (:r, :p)');
        foreach ($roles as $r) {
            $rid = (int)$r->idrole;
            $name = strtolower(trim((string)$r->name));
            $keys = [];
            if ($rid === 1 || $name === 'admin') {
                $keys = $allKeys; // full access
            } elseif ($rid === 2 || $name === 'manager') {
                foreach ($allKeys as $k) {
                    if (preg_match('/\.(view|create|edit|manage)$/', $k) && strpos($k, 'roles.') !== 0) {
                        $keys[] = $k;
                    }
                }
            } elseif ($rid === 3 || $name === 'gospel') {
                foreach ($allKeys as $k) {
                    if (preg_match('/^(content|comments|reports|publisher|dashboard)\.(view|edit|manage)$/', $k)) {
                        $keys[] = $k;
                    }
                }
            } else {
                // Staff / custom: view-focused defaults
                foreach ($allKeys as $k) {
                    if (substr($k, -5) === '.view') {
                        $keys[] = $k;
                    }
                }
            }
            foreach ($keys as $perm) {
                $ins->execute([':r' => $rid, ':p' => $perm]);
            }
        }
    }
} catch (Throwable $e) {
    // seeding is best-effort
}

$userCounts = [];
try {
    $q = $dbh->query('SELECT role, COUNT(*) AS c FROM admin GROUP BY role');
    if ($q) {
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $userCounts[(int)$row['role']] = (int)$row['c'];
        }
    }
} catch (Throwable $e) {
    $userCounts = [];
}

$selectedRoleId = (int)($_GET['role'] ?? $_POST['perm_role_id'] ?? 0);
if ($selectedRoleId <= 0 && $roles) {
    $selectedRoleId = (int)$roles[0]->idrole;
}
$validRoleIds = array_map(static fn($r) => (int)$r->idrole, $roles);
if (!in_array($selectedRoleId, $validRoleIds, true) && $validRoleIds) {
    $selectedRoleId = $validRoleIds[0];
}

$permView = strtolower(trim((string)($_GET['view'] ?? 'role')));
if (!in_array($permView, ['module', 'role'], true)) {
    $permView = 'role';
}

$selectedPerms = [];
if ($selectedRoleId > 0) {
    try {
        $st = $dbh->prepare('SELECT perm FROM role_permissions WHERE role_id = :r');
        $st->execute([':r' => $selectedRoleId]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $p) {
            $selectedPerms[(string)$p] = true;
        }
    } catch (Throwable $e) {
        $selectedPerms = [];
    }
}

$modules = roles_permission_modules();
$totalPermKeys = count(roles_all_perm_keys());
$totalRoles = count($roles);
$activeRoles = 0;
$customRoles = 0;
foreach ($roles as $r) {
    if ((int)($r->status ?? 0) === 1) {
        $activeRoles++;
    }
    if (!in_array((int)$r->idrole, $lockedRoleIds, true)) {
        $customRoles++;
    }
}
$systemModules = count($modules);

$selectedRoleName = 'Role';
$selectedRoleLocked = false;
$selectedRoleDesc = '';
foreach ($roles as $r) {
    if ((int)$r->idrole === $selectedRoleId) {
        $selectedRoleName = (string)$r->name;
        $selectedRoleLocked = in_array((int)$r->idrole, $lockedRoleIds, true);
        $selectedRoleDesc = roles_role_description($selectedRoleName, $selectedRoleLocked);
        break;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
  <title>Roles &amp; Permissions</title>
  <link href="../lib/font-awesome/css/font-awesome.css" rel="stylesheet">
  <link href="../lib/Ionicons/css/ionicons.css" rel="stylesheet">
  <link href="../lib/perfect-scrollbar/css/perfect-scrollbar.css" rel="stylesheet">
  <link href="../lib/datatables/jquery.dataTables.css" rel="stylesheet">
  <link rel="stylesheet" href="../css/shamcey.css">
  <link rel="stylesheet" href="css/admin-tables-shamcey.css?v=8">
  <style>
    html,body{height:100%;overflow:hidden;}
    .sh-mainpanel{height:100vh;display:flex;flex-direction:column;overflow:hidden;}
    .sh-mainpanel > .sh-pagebody{
      overflow:hidden !important;display:flex !important;flex-direction:column !important;min-height:0 !important;
      padding-top:8px !important;padding-bottom:8px !important;flex:1 1 auto;background:#f4f6fb;
    }
    .rp-wrap{flex:1 1 auto;min-height:0;display:flex;flex-direction:column;gap:8px;overflow:hidden;padding:0 2px;box-sizing:border-box;}
    .rp-cards{flex:0 0 auto;display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:8px;}
    .rp-card{background:#fff;border:1px solid #eef2f7;border-radius:12px;padding:10px 12px;box-shadow:0 1px 2px rgba(15,23,42,.04);min-width:0;}
    .rp-card-top{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:6px;}
    .rp-card-top .lab{font-size:11px;font-weight:700;color:#64748b;}
    .rp-card-top .delta{font-size:10px;font-weight:800;color:#94a3b8;}
    .rp-ico{width:28px;height:28px;border-radius:999px;display:flex;align-items:center;justify-content:center;font-size:12px;}
    .rp-ico.blue{background:#dbeafe;color:#2563eb;}
    .rp-ico.green{background:#f0fdf4;color:#16a34a;}
    .rp-ico.purple{background:#f5f3ff;color:#7c3aed;}
    .rp-ico.orange{background:#fff7ed;color:#ea580c;}
    .rp-ico.red{background:#fef2f2;color:#dc2626;}
    .rp-card .val{font-size:20px;font-weight:800;color:#0f172a;line-height:1;}
    .rp-card .sub{font-size:10px;color:#94a3b8;font-weight:600;margin-top:4px;}

    .rp-grid{flex:1 1 auto;min-height:0;display:grid;grid-template-columns:minmax(0,1.05fr) minmax(0,1fr);gap:8px;}
    .rp-panel{
      min-height:0;background:#fff;border:1px solid #eef2f7;border-radius:12px;overflow:hidden;
      box-shadow:0 1px 2px rgba(15,23,42,.04);display:flex;flex-direction:column;
    }
    .rp-panel-head{
      flex:0 0 auto;display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap;
      padding:10px 12px;border-bottom:1px solid #eef2f7;background:#fafbfc;
    }
    .rp-panel-head h3{margin:0;font-size:13px;font-weight:800;color:#0f172a;}
    .rp-panel-head .muted{font-size:11px;color:#64748b;font-weight:600;}
    .rp-search{position:relative;min-width:160px;max-width:220px;flex:1 1 160px;}
    .rp-search i{position:absolute;left:9px;top:50%;transform:translateY(-50%);color:#94a3b8;font-size:12px;}
    .rp-search input,.rp-panel-head select{
      height:30px;border:1px solid #e2e8f0;border-radius:8px;padding:0 9px;font-size:11px;background:#fff;color:#0f172a;
    }
    .rp-search input{width:100%;padding-left:28px;}
    .rp-btn{
      height:30px;padding:0 10px;border-radius:8px;border:1px solid #e2e8f0;background:#fff;
      font-size:11px;font-weight:700;color:#334155;display:inline-flex;align-items:center;gap:5px;cursor:pointer;text-decoration:none;
    }
    .rp-btn.primary{background:#2563eb;border-color:#2563eb;color:#fff;}
    .rp-btn.primary:hover{background:#1d4ed8;color:#fff;text-decoration:none;}
    .rp-btn:hover{background:#f8fafc;text-decoration:none;color:#0f172a;}

    .rp-tabs{display:flex;gap:0;}
    .rp-tabs a{
      padding:6px 10px;font-size:11px;font-weight:800;color:#64748b;text-decoration:none;
      border-bottom:2px solid transparent;
    }
    .rp-tabs a.is-active{color:#2563eb;border-bottom-color:#2563eb;}
    .rp-role-meta{
      flex:0 0 auto;display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-wrap:wrap;
      padding:10px 12px;border-bottom:1px solid #eef2f7;background:#fff;
    }
    .rp-role-meta label{display:block;font-size:10px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.04em;margin-bottom:4px;}
    .rp-role-meta select{
      height:32px;min-width:200px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:12px;font-weight:700;background:#fff;color:#0f172a;
    }
    .rp-role-desc{max-width:280px;}
    .rp-role-desc .txt{font-size:12px;font-weight:600;color:#334155;line-height:1.35;}

    .rp-access{
      display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border:0;background:transparent;
      cursor:pointer;padding:0;border-radius:999px;
    }
    .rp-access:hover{background:#f1f5f9;}
    .rp-access.is-full{color:#2563eb;font-size:16px;}
    .rp-access.is-part{color:#60a5fa;font-size:15px;}
    .rp-access.is-none{color:#94a3b8;font-size:14px;}
    .rp-access:disabled{opacity:.4;cursor:default;}
    .rp-access input{position:absolute;opacity:0;pointer-events:none;width:0;height:0;}
    .rp-legend .fa{margin-right:4px;}
    .rp-legend .fa-check-circle{color:#2563eb;}
    .rp-legend .fa-circle-o{color:#60a5fa;}
    .rp-legend .fa-minus{color:#94a3b8;}

    .rp-body{flex:1 1 auto;min-height:0;overflow:auto;}
    .rp-table{width:100%;border-collapse:separate;border-spacing:0;table-layout:fixed;}
    .rp-table th{
      text-align:left;font-size:9px;font-weight:800;letter-spacing:.03em;text-transform:uppercase;
      color:#64748b;padding:8px 10px;border-bottom:1px solid #eef2f7;background:#fff;position:sticky;top:0;z-index:2;
    }
    .rp-table td{padding:10px;border-bottom:1px solid #f1f5f9;font-size:12px;color:#0f172a;vertical-align:middle;}
    .rp-table tr:hover td{background:#f8fafc;}
    .rp-table tr.is-selected td{background:#eff6ff;}
    .rp-table tr{cursor:pointer;}
    .rp-role-name{font-weight:800;color:#0f172a;}
    .rp-role-tag{display:inline-block;margin-left:6px;font-size:9px;font-weight:800;color:#64748b;background:#f1f5f9;border-radius:999px;padding:1px 6px;vertical-align:middle;}
    .rp-desc{font-size:11px;color:#64748b;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
    .rp-status{display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:999px;font-size:10px;font-weight:800;}
    .rp-status.active{background:#dcfce7;color:#15803d;}
    .rp-status.inactive{background:#f1f5f9;color:#64748b;}
    .rp-acts{display:flex;align-items:center;gap:4px;}
    .rp-act{
      width:28px;height:28px;border-radius:8px;border:1px solid #e2e8f0;background:#fff;color:#64748b;
      display:inline-flex;align-items:center;justify-content:center;text-decoration:none;cursor:pointer;
    }
    .rp-act:hover{background:#f8fafc;color:#0f172a;text-decoration:none;}

    .rp-perm-table th:nth-child(1),.rp-perm-table td:nth-child(1){width:28%;}
    .rp-perm-table th,.rp-perm-table td{text-align:center;}
    .rp-perm-table th:first-child,.rp-perm-table td:first-child{text-align:left;}
    .rp-mod{font-weight:700;color:#0f172a;font-size:12px;}
    .rp-toggle{
      appearance:none;-webkit-appearance:none;width:34px;height:18px;border-radius:999px;border:0;
      background:#cbd5e1;position:relative;cursor:pointer;vertical-align:middle;outline:none;
    }
    .rp-toggle:checked{background:#2563eb;}
    .rp-toggle::after{
      content:'';position:absolute;top:2px;left:2px;width:14px;height:14px;border-radius:50%;background:#fff;
      transition:left .15s ease;box-shadow:0 1px 2px rgba(15,23,42,.2);
    }
    .rp-toggle:checked::after{left:18px;}
    .rp-toggle:disabled{opacity:.35;cursor:not-allowed;}
    .rp-legend{flex:0 0 auto;display:flex;gap:12px;flex-wrap:wrap;padding:8px 12px;border-top:1px solid #eef2f7;font-size:10px;color:#64748b;font-weight:700;}
    .rp-dot{width:8px;height:8px;border-radius:999px;display:inline-block;margin-right:4px;vertical-align:middle;}
    .rp-dot.full{background:#1d4ed8;}
    .rp-dot.part{background:#60a5fa;}
    .rp-dot.none{background:#cbd5e1;}
    .rp-foot{flex:0 0 auto;padding:8px 12px;border-top:1px solid #eef2f7;font-size:11px;color:#64748b;font-weight:600;}
    .rp-alert{flex:0 0 auto;padding:7px 9px;border-radius:8px;font-size:12px;font-weight:700;}
    .rp-alert.ok{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0;}
    .rp-alert.bad{background:#fef2f2;color:#991b1b;border:1px solid #fecaca;}
    @media (max-width:1100px){
      .rp-wrap{overflow:auto;}
      .rp-cards{grid-template-columns:repeat(2,minmax(0,1fr));}
      .rp-grid{grid-template-columns:1fr;min-height:auto;}
      .rp-panel{min-height:320px;}
    }
  </style>
</head>
<body>
<?php
$adminChromePageIntro = [
    'title' => 'Roles & Permissions',
    'description' => 'Manage roles, set permissions, and control access to platform features.',
];
include __DIR__ . '/includes/leftbar.php';
include __DIR__ . '/includes/header.php';
?>

<div class="sh-mainpanel">
  <div class="sh-pagebody">
    <div class="rp-wrap">

      <?php if ($error !== ''): ?>
        <div class="rp-alert bad"><?= h($error) ?></div>
      <?php elseif ($msg !== ''): ?>
        <div class="rp-alert ok"><?= h($msg) ?></div>
      <?php endif; ?>

      <div class="rp-cards">
        <div class="rp-card">
          <div class="rp-card-top">
            <div style="display:flex;align-items:center;gap:8px;">
              <div class="rp-ico blue"><i class="fa fa-users"></i></div>
              <div class="lab">Total Roles</div>
            </div>
            <div class="delta">• all</div>
          </div>
          <div class="val"><?= number_format($totalRoles) ?></div>
          <div class="sub">All system roles</div>
        </div>
        <div class="rp-card">
          <div class="rp-card-top">
            <div style="display:flex;align-items:center;gap:8px;">
              <div class="rp-ico green"><i class="fa fa-user"></i></div>
              <div class="lab">Active Roles</div>
            </div>
            <div class="delta">• live</div>
          </div>
          <div class="val"><?= number_format($activeRoles) ?></div>
          <div class="sub">Enabled roles</div>
        </div>
        <div class="rp-card">
          <div class="rp-card-top">
            <div style="display:flex;align-items:center;gap:8px;">
              <div class="rp-ico purple"><i class="fa fa-key"></i></div>
              <div class="lab">Permissions</div>
            </div>
            <div class="delta">• keys</div>
          </div>
          <div class="val"><?= number_format($totalPermKeys) ?></div>
          <div class="sub">Total permissions</div>
        </div>
        <div class="rp-card">
          <div class="rp-card-top">
            <div style="display:flex;align-items:center;gap:8px;">
              <div class="rp-ico orange"><i class="fa fa-shield"></i></div>
              <div class="lab">System Modules</div>
            </div>
            <div class="delta">• apps</div>
          </div>
          <div class="val"><?= number_format($systemModules) ?></div>
          <div class="sub">Platform modules</div>
        </div>
        <div class="rp-card">
          <div class="rp-card-top">
            <div style="display:flex;align-items:center;gap:8px;">
              <div class="rp-ico red"><i class="fa fa-user-plus"></i></div>
              <div class="lab">Custom Roles</div>
            </div>
            <div class="delta">• admin</div>
          </div>
          <div class="val"><?= number_format($customRoles) ?></div>
          <div class="sub">Created by admins</div>
        </div>
      </div>

      <div class="rp-grid">
        <section class="rp-panel">
          <div class="rp-panel-head">
            <h3>Roles</h3>
            <div class="rp-search">
              <i class="fa fa-search"></i>
              <input type="search" id="rolesSearchBox" placeholder="Search roles..." autocomplete="off">
            </div>
            <button type="button" class="rp-btn primary" data-toggle="modal" data-target="#addRoleModal">
              <i class="fa fa-plus"></i> Create Role
            </button>
          </div>
          <div class="rp-body">
            <table class="rp-table" id="rolesTable">
              <thead>
                <tr>
                  <th style="width:22%;">Role Name</th>
                  <th style="width:10%;">Users</th>
                  <th>Description</th>
                  <th style="width:12%;">Status</th>
                  <th style="width:12%;">Actions</th>
                </tr>
              </thead>
              <tbody>
              <?php foreach ($roles as $r):
                  $rid = (int)$r->idrole;
                  $locked = in_array($rid, $lockedRoleIds, true);
                  $isActive = (int)($r->status ?? 0) === 1;
                  $usersN = (int)($userCounts[$rid] ?? 0);
                  $desc = roles_role_description((string)$r->name, $locked);
              ?>
                <tr class="<?= $rid === $selectedRoleId ? 'is-selected' : '' ?>" data-role-id="<?= $rid ?>" data-role-name="<?= h((string)$r->name) ?>">
                  <td>
                    <span class="rp-role-name"><?= h((string)$r->name) ?></span>
                    <span class="rp-role-tag"><?= $locked ? 'system' : 'custom' ?></span>
                  </td>
                  <td><?= number_format($usersN) ?></td>
                  <td><div class="rp-desc" title="<?= h($desc) ?>"><?= h($desc) ?></div></td>
                  <td>
                    <span class="rp-status <?= $isActive ? 'active' : 'inactive' ?>">
                      <?= $isActive ? 'Active' : 'Inactive' ?>
                    </span>
                  </td>
                  <td onclick="event.stopPropagation();">
                    <div class="rp-acts">
                      <?php if (!$locked): ?>
                        <button type="button" class="rp-act" title="Edit"
                          data-roleid="<?= $rid ?>"
                          data-rolename="<?= h((string)$r->name) ?>"
                          data-status="<?= $isActive ? '1' : '0' ?>"
                          onclick="openEditModal(this)">
                          <i class="fa fa-pencil"></i>
                        </button>
                      <?php else: ?>
                        <button type="button" class="rp-act" title="Toggle status"
                          data-roleid="<?= $rid ?>"
                          data-rolename="<?= h((string)$r->name) ?>"
                          data-status="<?= $isActive ? '1' : '0' ?>"
                          onclick="openEditModal(this)">
                          <i class="fa fa-pencil"></i>
                        </button>
                      <?php endif; ?>
                      <div class="fries-menu">
                        <button type="button" class="fries-toggle" title="Actions" aria-label="Actions" aria-haspopup="true">
                          <span class="fries-icon" aria-hidden="true"></span>
                        </button>
                        <div class="fries-dropdown" role="menu">
                          <a class="fries-item" role="menuitem" href="roleslist.php?view=role&amp;role=<?= $rid ?>">
                            <i class="fa fa-key"></i> Permissions
                          </a>
                          <a class="fries-item" role="menuitem" href="adminroles.php?role=<?= rawurlencode(strtolower((string)$r->name)) ?>">
                            <i class="fa fa-users"></i> View accounts
                          </a>
                          <?php if (!$locked): ?>
                            <button type="button" class="fries-item fries-item-danger" role="menuitem"
                              data-roleid="<?= $rid ?>"
                              data-rolename="<?= h((string)$r->name) ?>"
                              onclick="openDeleteModal(this)">
                              <i class="fa fa-trash"></i> Delete
                            </button>
                          <?php endif; ?>
                        </div>
                      </div>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <div class="rp-foot">Showing <?= number_format($totalRoles) ?> of <?= number_format($totalRoles) ?> roles</div>
        </section>

        <section class="rp-panel">
          <form method="post" id="permForm" class="rp-panel" style="border:0;box-shadow:none;border-radius:0;min-height:0;">
            <div class="rp-panel-head">
              <div>
                <h3>Permissions</h3>
              </div>
              <div class="rp-tabs" aria-label="Permissions view">
                <a href="roleslist.php?view=module&amp;role=<?= (int)$selectedRoleId ?>" class="<?= $permView === 'module' ? 'is-active' : '' ?>">By Module</a>
                <a href="roleslist.php?view=role&amp;role=<?= (int)$selectedRoleId ?>" class="<?= $permView === 'role' ? 'is-active' : '' ?>">By Role</a>
              </div>
              <select aria-label="Module filter" id="moduleFilter">
                <option value="all">All Modules</option>
                <?php foreach ($modules as $mod): ?>
                  <option value="<?= h($mod['key']) ?>"><?= h($mod['label']) ?></option>
                <?php endforeach; ?>
              </select>
              <button type="submit" name="save_permissions" class="rp-btn primary"><i class="fa fa-save"></i> Save</button>
            </div>

            <?php if ($permView === 'role'): ?>
              <div class="rp-role-meta">
                <div>
                  <label for="roleSelect">Select Role</label>
                  <select id="roleSelect" aria-label="Select Role">
                    <?php foreach ($roles as $r):
                        $rid = (int)$r->idrole;
                        $locked = in_array($rid, $lockedRoleIds, true);
                        $label = (string)$r->name . ' (' . ($locked ? 'system' : 'custom') . ')';
                    ?>
                      <option value="<?= $rid ?>" <?= $rid === $selectedRoleId ? 'selected' : '' ?>><?= h($label) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="rp-role-desc">
                  <label>Role Description</label>
                  <div class="txt"><?= h($selectedRoleDesc !== '' ? $selectedRoleDesc : '—') ?></div>
                </div>
              </div>
            <?php else: ?>
              <div class="rp-role-meta">
                <div class="muted" style="font-size:12px;font-weight:600;color:#64748b;">
                  Editing module matrix for <b style="color:#0f172a;"><?= h($selectedRoleName) ?></b>
                  · use <a href="roleslist.php?view=role&amp;role=<?= (int)$selectedRoleId ?>">By Role</a> to switch roles.
                </div>
              </div>
            <?php endif; ?>

            <input type="hidden" name="perm_role_id" value="<?= (int)$selectedRoleId ?>">
            <input type="hidden" name="perm_view" value="<?= h($permView) ?>">
            <div class="rp-body">
              <table class="rp-table rp-perm-table" id="permTable">
                <thead>
                  <tr>
                    <th>Module / Permission</th>
                    <th>View</th>
                    <th>Create</th>
                    <th>Edit</th>
                    <th>Delete</th>
                    <th>Manage</th>
                  </tr>
                </thead>
                <tbody>
                <?php
                  $actionCols = ['view', 'create', 'edit', 'delete', 'manage'];
                  foreach ($modules as $mod):
                    $supportedActs = $mod['actions'];
                    $onCount = 0;
                    $supportCount = count($supportedActs);
                    foreach ($supportedActs as $sa) {
                        if (!empty($selectedPerms[$mod['key'] . '.' . $sa])) {
                            $onCount++;
                        }
                    }
                ?>
                  <tr data-module="<?= h($mod['key']) ?>">
                    <td><span class="rp-mod"><?= h($mod['label']) ?></span></td>
                    <?php foreach ($actionCols as $act):
                        $supported = in_array($act, $supportedActs, true);
                        $permKey = $mod['key'] . '.' . $act;
                        $on = $supported && !empty($selectedPerms[$permKey]);
                        $state = 'none';
                        $icon = 'fa-minus';
                        if ($supported && $on) {
                            $state = 'full';
                            $icon = 'fa-check-circle';
                        } elseif ($supported && !$on && $supportCount > 1 && $onCount > 0 && $onCount < $supportCount) {
                            $state = 'part';
                            $icon = 'fa-circle-o';
                        }
                    ?>
                      <td>
                        <?php if ($supported): ?>
                          <label class="rp-access is-<?= h($state) ?>" title="<?= h(ucfirst($act)) ?>">
                            <input type="checkbox" name="perms[]" value="<?= h($permKey) ?>" <?= $on ? 'checked' : '' ?>>
                            <i class="fa <?= h($icon) ?>" aria-hidden="true"></i>
                          </label>
                        <?php else: ?>
                          <span class="rp-access is-none" title="Not available" aria-hidden="true"><i class="fa fa-minus"></i></span>
                        <?php endif; ?>
                      </td>
                    <?php endforeach; ?>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            </div>
            <div class="rp-legend">
              <span><i class="fa fa-check-circle"></i> Full Access</span>
              <span><i class="fa fa-circle-o"></i> Partial Access</span>
              <span><i class="fa fa-minus"></i> No Access</span>
            </div>
          </form>
        </section>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="addRoleModal" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog" role="document">
    <form method="post" class="modal-content" style="border-radius:16px;overflow:hidden;">
      <div class="modal-header">
        <h4 class="modal-title" style="font-weight:900;">Create Role</h4>
        <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
      </div>
      <div class="modal-body">
        <label style="font-size:12px;font-weight:700;color:#64748b;">Role name</label>
        <input name="role_name" class="form-control" placeholder="Role name" required>
        <label class="mg-t-10" style="font-size:12px;font-weight:700;color:#64748b;">Inherits from</label>
        <select name="inherits_from" class="form-control">
          <option value="0">No inheritance</option>
          <?php foreach ($baseRoles as $b): ?>
            <option value="<?= (int)$b['idrole'] ?>"><?= h((string)$b['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <label class="mg-t-10" style="font-size:12px;font-weight:700;color:#64748b;">Status</label>
        <select name="status" class="form-control">
          <option value="1" selected>Active</option>
          <option value="0">Inactive</option>
        </select>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-light" data-dismiss="modal">Cancel</button>
        <button name="create_role" class="btn btn-primary" type="submit">Save</button>
      </div>
    </form>
  </div>
</div>

<div class="modal fade" id="editRoleModal" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog" role="document">
    <form method="post" class="modal-content" style="border-radius:16px;overflow:hidden;">
      <div class="modal-header">
        <h4 class="modal-title" style="font-weight:900;">Edit Role</h4>
        <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="idrole" id="editRoleId" value="">
        <label style="font-size:12px;font-weight:700;color:#64748b;">Role name</label>
        <input name="role_name" id="editRoleName" class="form-control" required>
        <label class="mg-t-10" style="font-size:12px;font-weight:700;color:#64748b;">Status</label>
        <select name="status" id="editRoleStatus" class="form-control">
          <option value="1">Active</option>
          <option value="0">Inactive</option>
        </select>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-light" data-dismiss="modal">Cancel</button>
        <button name="update_role" class="btn btn-primary" type="submit">Save</button>
      </div>
    </form>
  </div>
</div>

<div class="modal fade" id="deleteRoleModal" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog" role="document">
    <form method="post" class="modal-content" style="border-radius:16px;overflow:hidden;">
      <div class="modal-header" style="background:rgba(239,68,68,.10);">
        <h4 class="modal-title" style="font-weight:900;">Confirm Delete</h4>
        <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
      </div>
      <div class="modal-body">
        <p style="margin-bottom:6px;">Delete this role?</p>
        <b id="deleteRoleName"></b>
        <input type="hidden" name="delete_idrole" id="deleteRoleId">
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-light" data-dismiss="modal">Cancel</button>
        <button name="delete_role" class="btn btn-danger" type="submit">Delete</button>
      </div>
    </form>
  </div>
</div>

<script src="../lib/jquery/jquery.js"></script>
<script src="../lib/popper.js/popper.js"></script>
<script src="../lib/bootstrap/bootstrap.js"></script>
<script src="../js/shamcey.js"></script>
<script src="js/admin-fries-menu.js?v=1"></script>
<script>
function openDeleteModal(el){
  $('#deleteRoleId').val(el.dataset.roleid);
  $('#deleteRoleName').text(el.dataset.rolename);
  $('#deleteRoleModal').modal('show');
}
function openEditModal(el){
  var locked = [1,2,3,4].indexOf(parseInt(el.dataset.roleid, 10)) !== -1;
  $('#editRoleId').val(el.dataset.roleid);
  $('#editRoleName').val(el.dataset.rolename).prop('readonly', locked);
  $('#editRoleStatus').val(el.dataset.status || '1');
  $('#editRoleModal').modal('show');
}
$(function(){
  var permView = <?= json_encode($permView) ?>;
  function roleUrl(id) {
    return 'roleslist.php?view=' + encodeURIComponent(permView) + '&role=' + encodeURIComponent(id);
  }
  function syncAccessIcon(label) {
    var $label = $(label);
    var $cb = $label.find('input[type="checkbox"]');
    var $icon = $label.find('i');
    if (!$cb.length || !$icon.length) return;
    if ($cb.is(':checked')) {
      $label.removeClass('is-none is-part').addClass('is-full');
      $icon.attr('class', 'fa fa-check-circle');
    } else {
      $label.removeClass('is-full is-part').addClass('is-none');
      $icon.attr('class', 'fa fa-minus');
    }
  }
  $('#rolesSearchBox').on('input', function(){
    var q = String(this.value || '').toLowerCase();
    $('#rolesTable tbody tr').each(function(){
      var name = String($(this).data('role-name') || '').toLowerCase();
      $(this).toggle(!q || name.indexOf(q) !== -1);
    });
  });
  $('#rolesTable tbody').on('click', 'tr', function(){
    var id = $(this).data('role-id');
    if (!id) return;
    window.location.href = roleUrl(id);
  });
  $('#roleSelect').on('change', function(){
    window.location.href = roleUrl(this.value);
  });
  $('#moduleFilter').on('change', function(){
    var v = this.value;
    $('#permTable tbody tr').each(function(){
      $(this).toggle(v === 'all' || $(this).data('module') === v);
    });
  });
  $('#permTable').on('change', '.rp-access input[type="checkbox"]', function(){
    syncAccessIcon($(this).closest('.rp-access'));
  });
});
</script>
</body>
</html>
