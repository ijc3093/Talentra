<?php
/**
 * admin/adminroles.php
 * ✅ Admin-only
 * ✅ Fixed page (no body scroll)
 * ✅ Only table rows scroll (vertical + horizontal when needed)
 * ✅ Header row stays sticky (NOT scrolling away)
 * ✅ Horizontal scroll enabled so right columns are never clipped
 * ✅ DataTables search/length moved to top tools
 * ✅ Avatar fallback always 2 letters
 * ✅ Tooltips show full Email/FriendCode/Username
 * ✅ Header alignment stays correct on resize
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
  <title>Admin Accounts & Roles</title>

  <link href="../lib/font-awesome/css/font-awesome.css" rel="stylesheet">
  <link href="../lib/Ionicons/css/ionicons.css" rel="stylesheet">
  <link href="../lib/perfect-scrollbar/css/perfect-scrollbar.css" rel="stylesheet">
  <link href="../lib/datatables/jquery.dataTables.css" rel="stylesheet">
  <link href="../lib/datatables-responsive/dataTables.responsive.css" rel="stylesheet">
  <link href="../lib/select2/css/select2.min.css" rel="stylesheet">
  <link rel="stylesheet" href="../css/shamcey.css">

  <style>
    :root{
      --bg:#f4f6fb; --card:#fff; --border: rgba(17,24,39,.10);
      --text:#0f172a; --muted: rgba(17,24,39,.62);
      --shadow: 0 14px 44px rgba(15,23,42,.10);
      --shadow2: 0 10px 26px rgba(15,23,42,.08);
      --radius: 16px; --brand:#2563eb; --brand2:#1e40af;
    }

    html,body{ height:100%; overflow:hidden; }

    .sh-mainpanel{
      height:100vh;
      display:flex;
      flex-direction:column;
      overflow:hidden;
    }
    .sh-pagetitle{ flex:0 0 auto; }
    .sh-pagebody{
      flex:1 1 auto;
      overflow:hidden;
      padding-bottom:0!important;
      display:flex;
      flex-direction:column;
      background: var(--bg);
    }

    .accounts-card{
      flex:1 1 auto;
      min-height:0;
      display:flex;
      flex-direction:column;
      border:1px solid var(--border);
      /* border-radius: var(--radius); */
      box-shadow: var(--shadow);
      overflow:hidden;
      background: var(--card);
    }

    .card-header.pro{
      background: linear-gradient(135deg, var(--brand2), var(--brand));
      color:#fff;
      padding:16px 18px;
      flex:0 0 auto;
      border-bottom:1px solid rgba(255,255,255,.18);
      font-weight:900;
    }
    .card-header.pro .sub{
      font-size:12px; opacity:.92; margin-top:4px; font-weight:700;
    }

    .pro-tools{
      flex:0 0 auto;
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap:12px;
      flex-wrap:wrap;
      padding:14px 18px;
      border-bottom:1px solid rgba(17,24,39,.06);
      background: rgba(248,250,252,.92);
    }
    .pro-tools .hint{ color: var(--muted); font-size:12px; font-weight:700; }
    .pro-tools .btn{ border-radius:14px; font-weight:900; }

    .card-body-fixed{
      flex:1 1 auto;
      min-height:0;
      overflow:hidden;
      display:flex;
      flex-direction:column;
    }

    /* ✅ scroll area: vertical + horizontal when table is wider than viewport */
    .table-scroll{
      flex:1 1 auto;
      min-height:0;
      overflow:auto;
      background:#fff;
      position:relative;
      -webkit-overflow-scrolling:touch;
    }

    #datatable1{
      width:100% !important;
      min-width:1320px;
      table-layout:auto !important;
      border-collapse: separate !important;
      border-spacing: 0;
    }

    table.dataTable{
      width:100% !important;
      min-width:1320px;
      table-layout:auto !important;
      border-collapse: separate !important;
      border-spacing: 0;
    }

    /* ✅ sticky header row */
    #datatable1 thead th{
      position: sticky;
      top: 0;
      z-index: 30;
      background:#fff;
      border-bottom: 1px solid rgba(17,24,39,.12) !important;
      box-shadow: 0 2px 0 rgba(0,0,0,.06);
      font-weight: 900;
      color: rgba(17,24,39,.78);
    }

    table.dataTable tbody td{
      vertical-align: middle;
      border-bottom: 1px solid rgba(17,24,39,.06);
      background:#fff;
      overflow:hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }

    /* Avatar */
    .avatarWrap{ display:flex; align-items:center; gap:12px; min-width:0; }
    .avatar{ width:42px;height:42px;border-radius:999px;object-fit:cover;border:1px solid rgba(17,24,39,.10);box-shadow:var(--shadow2);background:#fff;flex:0 0 auto; }
    .avatarFallback{
      width:42px;height:42px;border-radius:999px;display:inline-flex;align-items:center;justify-content:center;
      background: rgba(37,99,235,.12); color: rgba(30,64,175,1);
      border:1px solid rgba(37,99,235,.18); font-weight:900; box-shadow:var(--shadow2); flex:0 0 auto; letter-spacing:.5px;
    }
    .nameBlock{ min-width:0; }
    .nameBlock .full{
      font-weight:900; color:var(--text); line-height:1.15;
      white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
      max-width: 240px;
    }
    .nameBlock .small{
      color: var(--muted); font-size:12px; font-weight:700;
      white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
      max-width: 260px;
    }

    .pill{
      display:inline-flex; align-items:center; gap:8px;
      padding:6px 10px; border-radius:999px;
      border:1px solid rgba(17,24,39,.10); background: rgba(17,24,39,.04);
      font-weight:900; font-size:12px; white-space:nowrap;
    }
    .pill.role-admin{ background: rgba(37,99,235,.10); border-color: rgba(37,99,235,.18); color: rgba(30,64,175,1); }
    .pill.role-manager{ background: rgba(34,197,94,.10); border-color: rgba(34,197,94,.18); color: rgba(22,101,52,1); }
    .pill.role-gospel{ background: rgba(6,182,212,.10); border-color: rgba(6,182,212,.18); color: rgba(14,116,144,1); }
    .pill.role-staff{ background: rgba(245,158,11,.12); border-color: rgba(245,158,11,.22); color: rgba(146,64,14,1); }
    .pill.role-unknown{ background: rgba(148,163,184,.15); border-color: rgba(148,163,184,.25); color: rgba(51,65,85,1); }

    .pill.ok{ background: rgba(34,197,94,.10); border-color: rgba(34,197,94,.18); color: rgba(22,101,52,1); }
    .pill.bad{ background: rgba(239,68,68,.10); border-color: rgba(239,68,68,.18); color: rgba(153,27,27,1); }

    .icon-btn{
      width:38px;height:38px;
      border-radius: 12px;
      border:1px solid rgba(17,24,39,.10);
      background:#fff;
      display:inline-flex;
      align-items:center;
      justify-content:center;
      cursor:pointer;
      transition: transform .05s ease, background .15s ease;
      box-shadow: 0 10px 26px rgba(15,23,42,.08);
      color:inherit;
      text-decoration:none;
    }
    .icon-btn:hover{ background: rgba(37,99,235,.08); text-decoration:none; color:inherit; }
    .icon-btn:active{ transform: translateY(1px); }
    .icon-btn.primary:hover{ background: rgba(37,99,235,.12); }
    .icon-btn.primary i{ color: #2563eb; }
    .icon-btn.danger:hover{ background: rgba(239,68,68,.10); }
    .icon-btn.danger i{ color: #ef4444; }

    .mono{ font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono","Courier New", monospace; font-size:12px; }

    .dt-tools{ display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
    .dt-tools .dt-length, .dt-tools .dt-search{ display:flex; align-items:center; gap:8px; }
    .dt-tools label{ margin:0; font-weight:800; font-size:12px; color: rgba(17,24,39,.72); }

    .dataTables_wrapper{ width:100%; min-width:0; overflow:visible; }
    .dataTables_wrapper .dataTables_filter input,
    .dataTables_wrapper .dataTables_length select{
      border-radius:14px !important;
      border:1px solid var(--border) !important;
      height:40px;
      box-shadow:none !important;
      background:#fff;
    }

    /* ✅ Make tooltip text visible on hover via native title */
    .cell-ellip{ display:block; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
  </style>
</head>

<body>

<?php include('includes/leftbar.php'); ?>
<?php include('includes/header.php'); ?>

<div class="sh-mainpanel">

  <!-- <div class="sh-pagetitle">
    <div class="input-group"></div>
    <div class="sh-pagetitle-left">
      <div class="sh-pagetitle-icon"><i class="icon ion-ios-people"></i></div>
      <div class="sh-pagetitle-title">
        <span>Admin</span>
        <h2>Accounts & Roles</h2>
      </div>
    </div>
  </div> -->

  <div class="sh-pagebody">

    <?php if ($error): ?>
      <div class="alert alert-danger" style="margin:0 0 10px 0;"><?php echo h($error); ?></div>
    <?php elseif ($msg): ?>
      <div class="alert alert-success" style="margin:0 0 10px 0;">
        <?php echo h($msg); ?>
        <?php if ($createdFriendCode !== ''): ?>
          <span class="mono" style="margin-left:8px;">Friend code: <b><?php echo h($createdFriendCode); ?></b></span>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <div class="accounts-card">
      <!-- <div class="card-header pro">
        <div style="font-size:16px;">List Roles & Accounts</div>
        <div class="sub">All admin-side accounts with photo, role, status, and created date.</div>
      </div> -->

      <div class="pro-tools">
        <div class="hint">
          <b>Total Admins:</b> <?php echo (int)count($rows); ?>
          <span style="opacity:.6;">•</span>
          Search by name, username, email, or friend code.
        </div>

        <div class="dt-tools">
          <div class="dt-length" id="dtLen"></div>
          <div class="dt-search" id="dtSearch"></div>

          <a href="roleslist.php" class="btn btn-outline-primary btn-sm">
            <i class="fa fa-id-badge mg-r-6"></i> Manage Roles
          </a>
          <button type="button"
                  class="btn btn-primary btn-sm"
                  onclick="window.location.href='admin_form.php';">
            <i class="fa fa-plus"></i> Add Admin
          </button>
          <button type="button"
                  class="btn btn-danger btn-sm"
                  <?php echo (count($rows) <= 1) ? 'disabled' : ''; ?>
                  data-toggle="modal"
                  data-target="#deleteAllModal">
            <i class="fa fa-trash"></i> Delete All
          </button>
        </div>
      </div>

      <div class="card-body-fixed">
        <div class="table-scroll" id="tableScroll">
          <table id="datatable1" class="table display" style="width:100%;">
            <thead>
              <tr>
                <th style="min-width:70px;">ID</th>
                <th style="min-width:250px;">Account</th>
                <th style="min-width:210px;">Email</th>
                <th style="min-width:150px;">Friend Code</th>
                <th style="min-width:130px;">Role</th>
                <th style="min-width:110px;">Status</th>
                <th style="min-width:180px;">Created</th>
                <th style="min-width:170px;">Actions</th>
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
                $roleName = (string)($row->role_name ?? 'Unknown');
                $status = (int)($row->status ?? 0);
                $isActive = ($status === 1);
                $statusText = $isActive ? 'Active' : 'Inactive';
                $isSelf = ($currentAdminId > 0 && $aid === $currentAdminId);

                $rk = strtolower(trim($roleName));
                $roleClass = 'pill role-unknown';
                if ($rk === 'admin') $roleClass = 'pill role-admin';
                elseif ($rk === 'manager') $roleClass = 'pill role-manager';
                elseif ($rk === 'gospel') $roleClass = 'pill role-gospel';
                elseif ($rk === 'staff') $roleClass = 'pill role-staff';

                $statusClass = $isActive ? 'pill ok' : 'pill bad';

                $imgPath = $img !== '' ? 'images/' . $img : '';
                $ini = initials2($full !== '' ? $full : ($uname !== '' ? $uname : 'Admin'));
              ?>
              <tr>
                <td class="mono"><span class="cell-ellip" title="<?php echo h((string)$row->idadmin); ?>"><?php echo (int)$row->idadmin; ?></span></td>

                <td>
                  <div class="avatarWrap">
                    <?php if ($imgPath !== ''): ?>
                      <img class="avatar"
                           src="<?php echo h($imgPath); ?>"
                           alt="avatar"
                           onerror="this.style.display='none'; this.nextElementSibling.style.display='inline-flex';">
                      <span class="avatarFallback" style="display:none;"><?php echo h($ini); ?></span>
                    <?php else: ?>
                      <span class="avatarFallback"><?php echo h($ini); ?></span>
                    <?php endif; ?>

                    <div class="nameBlock">
                      <div class="full" title="<?php echo h($full !== '' ? $full : $uname); ?>">
                        <?php echo h($full !== '' ? $full : $uname); ?>
                      </div>
                      <div class="small">
                        <span class="mono" title="<?php echo h('@'.$uname); ?>">@<?php echo h($uname); ?></span>
                      </div>
                    </div>
                  </div>
                </td>

                <td class="mono">
                  <span class="cell-ellip" title="<?php echo h($email); ?>"><?php echo h($email); ?></span>
                </td>

                <td class="mono">
                  <span class="cell-ellip" title="<?php echo h($fcode); ?>"><?php echo h($fcode); ?></span>
                </td>

                <td>
                  <span class="<?php echo h($roleClass); ?>" title="<?php echo h($roleName); ?>">
                    <i class="fa fa-shield"></i> <?php echo h($roleName); ?>
                  </span>
                </td>

                <td>
                  <span class="<?php echo h($statusClass); ?>" title="<?php echo h($statusText); ?>">
                    <i class="fa fa-circle"></i> <?php echo h($statusText); ?>
                  </span>
                </td>

                <td class="mono">
                  <span class="cell-ellip" title="<?php echo h(fmt_created($row->created_at ?? '')); ?>">
                    <?php echo h(fmt_created($row->created_at ?? '')); ?>
                  </span>
                </td>

                <td>
                  <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                    <a class="icon-btn"
                       href="admin_form.php?admin_id=<?php echo $aid; ?>"
                       title="Edit admin">
                      <i class="fa fa-pencil"></i>
                    </a>

                    <button type="button"
                            class="icon-btn"
                            title="<?php echo $isActive ? 'Block account (disable login)' : 'Unblock account'; ?>"
                            data-id="<?php echo $aid; ?>"
                            data-email="<?php echo h($email); ?>"
                            data-name="<?php echo h($full !== '' ? $full : $uname); ?>"
                            data-status="<?php echo $isActive ? '0' : '1'; ?>"
                            <?php echo $isSelf ? 'disabled' : ''; ?>
                            onclick="openStatusModal(this);">
                      <i class="fa <?php echo $isActive ? 'fa-ban' : 'fa-check'; ?>"></i>
                    </button>

                    <button type="button"
                            class="icon-btn danger"
                            title="Delete admin"
                            data-id="<?php echo $aid; ?>"
                            data-email="<?php echo h($email); ?>"
                            data-username="<?php echo h($uname); ?>"
                            data-name="<?php echo h($full !== '' ? $full : $uname); ?>"
                            <?php echo $isSelf ? 'disabled' : ''; ?>
                            onclick="openDeleteModal(this);">
                      <i class="fa fa-trash"></i>
                    </button>
                  </div>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>

          <?php if (count($rows) === 0): ?>
            <div class="alert alert-info" style="margin-top:12px;">No admin accounts found.</div>
          <?php endif; ?>
        </div>
      </div>

    </div>

  </div>

  <!-- Delete ONE modal -->
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
            <p class="mono" style="opacity:.75;margin-top:6px;" id="delAdminEmail"></p>
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

  <!-- Delete ALL modal -->
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

  <!-- Block/Unblock modal -->
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
            <p class="mono" style="opacity:.75;margin-top:6px;" id="statusModalEmail"></p>
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

  <div class="sh-footer">
    <div>Copyright &copy; 2017. All Rights Reserved.</div>
    <div class="mg-t-10 mg-md-t-0">Designed by ThemePixels</div>
  </div>
</div>

<script src="../lib/jquery/jquery.js"></script>
<script src="../lib/popper.js/popper.js"></script>
<script src="../lib/bootstrap/bootstrap.js"></script>
<script src="../lib/perfect-scrollbar/js/perfect-scrollbar.jquery.js"></script>
<script src="../lib/datatables/jquery.dataTables.js"></script>
<script src="../lib/datatables-responsive/dataTables.responsive.js"></script>
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

    var dt = $('#datatable1').DataTable({
      paging: false,
      info: false,
      responsive: false,
      autoWidth: false,
      scrollX: false,
      ordering: true,
      language: {
        searchPlaceholder: 'Search name, username, email, friend code...',
        sSearch: '',
        lengthMenu: '_MENU_',
      }
    });

    // Move DT controls into pro-tools
    $('#dtLen').append($('#datatable1_length'));
    $('#dtSearch').append($('#datatable1_filter'));
    $('.dataTables_length select').select2({ minimumResultsForSearch: Infinity });

    // ✅ Keep header aligned after DataTables finishes
    setTimeout(function(){ dt.columns.adjust().draw(false); }, 50);

    // ✅ Keep header aligned on resize
    $(window).on('resize', function(){
      dt.columns.adjust();
    });

    // ✅ If scroll container size changes, re-adjust (safe)
    $('#tableScroll').on('scroll', function(){ /* no-op; keeps sticky stable */ });
  });
</script>

</body>
</html>
