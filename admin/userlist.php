<?php
/**
 * admin/userlist.php
 * ✅ Admin-only
 * ✅ Fixed page (no scroll)
 * ✅ card-body fixed
 * ✅ ONLY table rows scroll
 * ✅ Two-letter avatar per row (fallback)
 * ✅ Delete ONE + Delete ALL with modals
 * ✅ Confirm/Unconfirm modal
 */
require_once __DIR__ . '/includes/session_admin.php';
requireAdminLogin();

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/controller.php';
require_once __DIR__ . '/includes/user_admin_helpers_load.php';

$adminLogin = $_SESSION['admin_login'] ?? '';
$adminRole  = (int)($_SESSION['userRole'] ?? 0);
$isAdmin    = ($adminRole === 1);

$controller = new Controller();
$dbh = $controller->pdo();

if (!$isAdmin) {
    header('Location: dashboard.php');
    exit;
}

$msg = trim((string)($_GET['msg'] ?? ''));
$error = '';
$createdFriendCode = trim((string)($_GET['fc'] ?? ''));

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function fmt_dt($dt): string {
    if (!$dt) return 'N/A';
    $ts = strtotime((string)$dt);
    if (!$ts) return (string)$dt;
    return date('M j, Y g:i A', $ts);
}

function initials2(string $name): string {
    $name = trim(preg_replace('/\s+/', ' ', $name) ?? $name);
    if ($name === '') return '??';
    $name = str_replace(['_', '.', '-', '@'], ' ', $name);
    $parts = array_values(array_filter(explode(' ', $name), fn($p)=>trim($p) !== ''));
    if (!$parts) return '??';
    $first = mb_strtoupper(mb_substr($parts[0], 0, 1));
    $second = '';
    if (count($parts) > 1) $second = mb_strtoupper(mb_substr($parts[count($parts)-1], 0, 1));
    else $second = mb_strtoupper(mb_substr($parts[0], 1, 1));
    $ini = trim($first.$second);
    return $ini !== '' ? $ini : '??';
}

function avatarColor(string $key): string {
    $key = strtolower(trim($key));
    $hash = crc32($key);
    $palette = ['#2563eb','#7c3aed','#db2777','#ea580c','#16a34a','#0f766e','#0891b2','#475569'];
    return $palette[$hash % count($palette)];
}

function userlist_row_is_publisher(object $row): bool
{
    $accountKind = strtolower(trim((string)($row->account_kind ?? 'personal')));
    $friendCode = strtoupper(trim((string)($row->friend_code ?? '')));
    return $accountKind === 'publisher' || strpos($friendCode, 'PUB-') === 0;
}

// -----------------------------
// DELETE ONE USER (POST from modal)
// -----------------------------
if (isset($_POST['delete_user'])) {
    $id   = (int)($_POST['delete_id'] ?? 0);
    $email = trim((string)($_POST['delete_email'] ?? ''));
    $username = trim((string)($_POST['delete_username'] ?? ''));

    $result = user_admin_delete_one($dbh, $id, $email);
    if (!empty($result['ok'])) {
        $msg = 'User deleted successfully.';
    } else {
        $error = (string)($result['error'] ?? 'Delete failed.');
    }
}

// -----------------------------
// DELETE ALL USERS (POST from modal)
// -----------------------------
if (isset($_POST['delete_all'])) {
    try {
        $registryPath = __DIR__ . '/../public_user/includes/deleted_user_registry.php';
        if (is_file($registryPath)) {
            require_once $registryPath;
            user_deleteduser_ensure_schema($dbh);
        }

        $all = $dbh->query('
            SELECT email, username, friend_code, name, mobile,
                   COALESCE(account_kind, \'personal\') AS account_kind
            FROM users
        ')->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($all) && org_admin_table_exists($dbh, 'deleteduser')) {
            foreach ($all as $row) {
                $em = trim((string)($row['email'] ?? ''));
                $un = trim((string)($row['username'] ?? ''));
                $fc = trim((string)($row['friend_code'] ?? ''));
                $dn = trim((string)($row['name'] ?? ''));
                $mobile = trim((string)($row['mobile'] ?? ''));
                $kind = strtolower(trim((string)($row['account_kind'] ?? 'personal')));
                if ($em === '' && $un === '' && $fc === '' && $dn === '' && $mobile === '') {
                    continue;
                }
                if (function_exists('user_record_deleted_account')) {
                    user_record_deleted_account($dbh, $em, $un, $fc, $dn, $mobile, $kind);
                } elseif ($em !== '') {
                    $ins = $dbh->prepare('INSERT INTO deleteduser (email) VALUES (:email)');
                    $ins->execute([':email' => $em]);
                }
            }
        }

        $dbh->beginTransaction();

        $delAll = $dbh->prepare('DELETE FROM users');
        $delAll->execute();

        $dbh->commit();
        $msg = 'All users deleted successfully.';
    } catch (Throwable $e) {
        if ($dbh->inTransaction()) $dbh->rollBack();
        $error = 'Database error: ' . $e->getMessage();
    }
}

// -----------------------------
// CONFIRM / UNCONFIRM (POST from modal)
// status: 1 = confirmed, 0 = unconfirmed (your existing logic)
// -----------------------------
if (isset($_POST['set_status'])) {
    $uid = (int)($_POST['status_id'] ?? 0);
    $newStatus = (int)($_POST['status_value'] ?? 0) === 1 ? 1 : 0;

    $result = user_admin_set_user_status($dbh, $uid, $newStatus);
    if (!empty($result['ok'])) {
        $msg = $newStatus === 1 ? 'Account unblocked. User can sign in again.' : 'Account blocked. User cannot sign in.';
    } else {
        $error = (string)($result['error'] ?? 'Status update failed.');
    }
}

// -----------------------------
// FETCH USERS
// -----------------------------
try {
    $sql = "SELECT id, name, username, email, gender, mobile, designation, image, status,
                   account_kind, friend_code, created_at
            FROM users
            ORDER BY created_at DESC";
    $query = $dbh->prepare($sql);
    $query->execute();
    $results = $query->fetchAll(PDO::FETCH_OBJ);
} catch (Throwable $e) {
    $results = [];
    $error = "Database error: " . $e->getMessage();
}

$personalCount = 0;
$publisherCount = 0;
foreach ($results as $countRow) {
    if (userlist_row_is_publisher($countRow)) {
        $publisherCount++;
    } else {
        $personalCount++;
    }
}

$listKind = strtolower(trim((string)($_GET['kind'] ?? 'personal')));
if (!in_array($listKind, ['personal', 'publisher'], true)) {
    $listKind = 'personal';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
  <title>Users</title>

  <!-- vendor css -->
  <link href="../lib/font-awesome/css/font-awesome.css" rel="stylesheet">
  <link href="../lib/Ionicons/css/ionicons.css" rel="stylesheet">
  <link href="../lib/perfect-scrollbar/css/perfect-scrollbar.css" rel="stylesheet">
  <link href="../lib/datatables/jquery.dataTables.css" rel="stylesheet">
  <link href="../lib/select2/css/select2.min.css" rel="stylesheet">

  <!-- Shamcey CSS -->
  <link rel="stylesheet" href="../css/shamcey.css">

  <style>
    :root{
      --bg:#f4f6fb;
      --card:#fff;
      --border: rgba(17,24,39,.10);
      --text:#0f172a;
      --muted: rgba(17,24,39,.62);
      --shadow: 0 14px 44px rgba(15,23,42,.10);
      --shadow2: 0 10px 26px rgba(15,23,42,.08);
      --radius: 16px;
      --brand:#2563eb;
      --brand2:#1e40af;
      --ok:#22c55e;
      --bad:#ef4444;
    }

    /* ✅ FIXED PAGE */
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

    .users-card{
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
      padding: 16px 18px;
      font-weight: 900;
      letter-spacing:.2px;
      border-bottom: 1px solid rgba(255,255,255,.18);
      flex:0 0 auto;
    }
    .card-header .sub{
      font-size:12px;
      opacity:.92;
      margin-top:4px;
      font-weight:700;
    }

    .pro-tools{
      flex:0 0 auto;
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap:12px;
      flex-wrap:wrap;
      padding: 14px 18px;
      border-bottom:1px solid rgba(17,24,39,.06);
      background: rgba(248,250,252,.90);
    }
    .pro-tools .hint{
      color: var(--muted);
      font-size: 12px;
      font-weight: 700;
    }
    .pro-tools-left{
      display:flex;
      align-items:center;
      gap:14px;
      flex-wrap:wrap;
      min-width:0;
    }
    .account-kind-switch{
      display:inline-flex;
      align-items:stretch;
      border:1px solid rgba(17,24,39,.12);
      border-radius:999px;
      overflow:hidden;
      background:#fff;
      box-shadow: var(--shadow2);
    }
    .account-kind-switch button{
      border:0;
      background:transparent;
      color:#334155;
      font-size:12px;
      font-weight:800;
      padding:8px 14px;
      cursor:pointer;
      transition: background .15s ease, color .15s ease;
      white-space:nowrap;
    }
    .account-kind-switch button + button{
      border-left:1px solid rgba(17,24,39,.10);
    }
    .account-kind-switch button.is-active{
      background: linear-gradient(135deg, var(--brand2), var(--brand));
      color:#fff;
    }
    .account-kind-switch button:not(.is-active):hover{
      background: rgba(37,99,235,.08);
    }
    .btn{ border-radius: 14px; font-weight: 900; }

    .card-body-fixed{
      flex:1 1 auto;
      min-height:0;
      overflow:hidden;          /* ✅ fixed */
      display:flex;
      flex-direction:column;
    }

    /* ✅ ONLY SCROLL AREA */
    .table-scroll{
      flex:1 1 auto;
      min-height:0;
      overflow:auto;
      /* padding: 14px 18px 18px 18px; */
    }

    /* ✅ two-letter avatar */
    .ava2{
      width:44px;height:44px;border-radius:999px;
      display:inline-flex;
      align-items:center;
      justify-content:center;
      font-weight: 900;
      color:#fff;
      border:1px solid rgba(17,24,39,.10);
      box-shadow: var(--shadow2);
      flex:0 0 auto;
    }

    .mono{
      font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono","Courier New", monospace;
      font-size:12px;
    }

    .pill{
      display:inline-flex;
      align-items:center;
      gap:8px;
      padding: 6px 10px;
      border-radius: 999px;
      border:1px solid rgba(17,24,39,.10);
      background: rgba(17,24,39,.04);
      font-weight:900;
      font-size: 12px;
      white-space: nowrap;
    }
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
      box-shadow: var(--shadow2);
    }
    .icon-btn:hover{ background: rgba(37,99,235,.08); }
    .icon-btn:active{ transform: translateY(1px); }
    .icon-btn.danger:hover{ background: rgba(239,68,68,.10); }
    .icon-btn.danger i{ color: #ef4444; }
    .icon-btn.primary i{ color: #2563eb; }

    /* sticky header so only rows move */
    #datatable1 thead th{
      position: sticky;
      top: 0;
      background: #fff;
      z-index: 5;
      border-bottom: 1px solid rgba(17,24,39,.12) !important;
      font-weight: 900;
      color: rgba(17,24,39,.78);
    }
    #datatable1 tbody td{
      vertical-align: middle;
      border-bottom: 1px solid rgba(17,24,39,.06);
    }

    /* DataTables controls style */
    .dataTables_wrapper .dataTables_filter input{
      border-radius: 14px !important;
      border: 1px solid var(--border) !important;
      height: 40px;
      padding: 6px 10px;
      box-shadow: none !important;
      background:#fff;
    }
    .dataTables_wrapper .dataTables_length select{
      border-radius: 14px !important;
      border: 1px solid var(--border) !important;
      height: 40px;
      box-shadow: none !important;
      background:#fff;
    }
    .dt-tools{
      display:flex;
      gap:10px;
      align-items:center;
      flex-wrap:wrap;
    }
    .dt-tools .dt-length,
    .dt-tools .dt-search{
      display:flex;
      align-items:center;
      gap:8px;
    }
    .dt-tools label{ margin:0; font-weight:800; font-size:12px; color: rgba(17,24,39,.72); }
    .dt-tools .dataTables_filter label,
    .dt-tools .dataTables_length label{ margin:0; }


    /* =========================
    FIX: sticky header overlap
    (Adjust --fixed-top-offset if needed)
    ========================= */
    :root{
      --fixed-top-offset: 0px; /* ✅ header + sh-pagetitle + pro-tools height */
    }

    /* only table header sticks */
    #datatable1 thead th{
      position: sticky;
      top: var(--fixed-top-offset);
      background: #fff;
      z-index: 20; /* higher than buttons */
      box-shadow: 0 2px 0 rgba(0,0,0,.06);
    }
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
        <span>Users</span>
        <h2>User List</h2>
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

    <div class="users-card">
      <!-- <div class="card-header pro">
        <div style="font-size:16px;">All Public User-side Accounts</div>
        <div class="sub">Manage users, status, and safe deletions with confirmation modals.</div>
      </div> -->

      <div class="pro-tools">
        <div class="pro-tools-left">
          <div class="hint">
            <b>Total Users:</b>
            <span id="visibleUserCount"><?php echo $listKind === 'publisher' ? (int)$publisherCount : (int)$personalCount; ?></span>
            <span style="opacity:.6;">•</span>
            Search by name/email/phone/designation.
          </div>

          <div class="account-kind-switch" role="group" aria-label="Account type filter">
            <button type="button"
                    class="account-kind-btn<?php echo $listKind === 'personal' ? ' is-active' : ''; ?>"
                    data-kind="personal">
              Personal (<?php echo (int)$personalCount; ?>)
            </button>
            <button type="button"
                    class="account-kind-btn<?php echo $listKind === 'publisher' ? ' is-active' : ''; ?>"
                    data-kind="publisher">
              Publisher (<?php echo (int)$publisherCount; ?>)
            </button>
          </div>
        </div>

        <div class="dt-tools">
          <div class="dt-length" id="dtLen"></div>
          <div class="dt-search" id="dtSearch"></div>

          <button type="button"
                  class="btn btn-primary btn-sm"
                  onclick="window.location.href='user_form.php<?php echo $listKind === 'publisher' ? '?account_kind=publisher' : ''; ?>';">
            <i class="fa fa-plus"></i> Add User
          </button>

          <button type="button"
                  class="btn btn-danger btn-sm"
                  <?php echo (count($results) === 0) ? 'disabled' : ''; ?>
                  data-toggle="modal"
                  data-target="#deleteAllModal">
            <i class="fa fa-trash"></i> Delete All
          </button>
        </div>
      </div>

      <div class="card-body-fixed">
        <div class="table-scroll">
          <table id="datatable1" class="table display responsive nowrap" style="width:100%;">
            <thead>
              <tr>
                <th style="width:70px;">ID</th>
                <th style="width:300px;">Full Name</th>
                <th style="width:210px;">Email</th>
                <th style="width:110px;">Type</th>
                <th style="width:120px;">Gender</th>
                <th style="width:140px;">Phone</th>
                <th style="width:170px;">Designation</th>
                <th style="width:170px;">Created</th>
                <th style="width:150px;">Account</th>
                <th style="width:170px;">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($results as $r):
                $uid = (int)($r->id ?? 0);
                $name = (string)($r->name ?? '');
                $email = (string)($r->email ?? '');
                $designation = (string)($r->designation ?? '');
                $uname = (string)($r->username ?? '');
                $status = (int)($r->status ?? 0);
                $isConfirmed = ($status === 1);
                $accountKind = strtolower(trim((string)($r->account_kind ?? 'personal')));
                $isPublisher = userlist_row_is_publisher($r);
                $rowKind = $isPublisher ? 'publisher' : 'personal';

                $labelForIni = $name !== '' ? $name : ($email !== '' ? $email : 'User');
                $ini = initials2($labelForIni);
                $bg = avatarColor($email !== '' ? $email : ($name !== '' ? $name : (string)$uid));
              ?>
              <tr data-account-kind="<?php echo h($rowKind); ?>">
                <td class="mono"><?php echo $uid; ?></td>

                <td>
                  <div style="display:flex;align-items:center;gap:12px;min-width:260px;">
                    <span class="ava2" style="background:<?php echo h($bg); ?>;"><?php echo h($ini); ?></span>
                    <div style="min-width:0;">
                      <div style="font-weight:900;color:#0f172a;line-height:1.15;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:260px;">
                        <?php echo h($name); ?>
                      </div>
                      <div class="mono" style="color:rgba(17,24,39,.62);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:280px;">
                        <?php echo h($designation); ?>
                      </div>
                    </div>
                  </div>
                </td>

                <td class="mono"><?php echo h($email); ?></td>
                <td>
                  <?php if ($isPublisher): ?>
                    <span class="pill" style="background:rgba(37,99,235,.10);border-color:rgba(37,99,235,.18);color:#1d4ed8;">Publisher</span>
                  <?php else: ?>
                    <span class="pill">Personal</span>
                  <?php endif; ?>
                </td>
                <td><?php echo h($r->gender ?? ''); ?></td>
                <td class="mono"><?php echo h($r->mobile ?? ''); ?></td>
                <td><?php echo h($designation); ?></td>
                <td class="mono"><?php echo h(fmt_dt($r->created_at ?? '')); ?></td>

                <td>
                  <?php if ($isConfirmed): ?>
                    <span class="pill ok"><i class="fa fa-check-circle"></i> Active</span>
                  <?php else: ?>
                    <span class="pill bad"><i class="fa fa-ban"></i> Blocked</span>
                  <?php endif; ?>
                </td>

                <td>
                  <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                    <a class="icon-btn"
                       href="user_form.php?user_id=<?php echo $uid; ?>"
                       title="Edit user">
                      <i class="fa fa-pencil"></i>
                    </a>

                    <a class="icon-btn primary"
                       href="<?php echo h(user_admin_public_profile_href($uid)); ?>"
                       target="_blank"
                       rel="noopener"
                       title="Open public profile">
                      <i class="fa fa-eye"></i>
                    </a>

                    <button type="button"
                            class="icon-btn"
                            title="<?php echo $isConfirmed ? 'Block account (disable login)' : 'Unblock account'; ?>"
                            data-id="<?php echo $uid; ?>"
                            data-email="<?php echo h($email); ?>"
                            data-name="<?php echo h($name); ?>"
                            data-status="<?php echo $isConfirmed ? '0' : '1'; ?>"
                            onclick="openStatusModal(this);">
                      <i class="fa <?php echo $isConfirmed ? 'fa-ban' : 'fa-check'; ?>"></i>
                    </button>

                    <button type="button"
                            class="icon-btn danger"
                            title="Delete user"
                            data-id="<?php echo $uid; ?>"
                            data-email="<?php echo h($email); ?>"
                            data-username="<?php echo h($uname); ?>"
                            data-name="<?php echo h($name); ?>"
                            onclick="openDeleteModal(this);">
                      <i class="fa fa-trash"></i>
                    </button>
                  </div>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>

          <?php if (count($results) === 0): ?>
            <div class="alert alert-info" style="margin-top:12px;">No users found.</div>
          <?php endif; ?>
        </div>
      </div>

    </div><!-- users-card -->

  </div><!-- sh-pagebody -->

  <!-- ✅ Delete ONE modal -->
  <div class="modal fade" id="deleteUserModal" tabindex="-1" role="dialog" aria-hidden="true">
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
            <p style="margin-bottom:6px;">Delete this user permanently?</p>
            <p style="margin:0;"><b id="delUserName"></b></p>
            <p class="mono" style="opacity:.75;margin-top:6px;" id="delUserEmail"></p>
            <input type="hidden" name="delete_id" id="delUserId" value="">
            <input type="hidden" name="delete_email" id="delUserEmailHidden" value="">
            <input type="hidden" name="delete_username" id="delUserUsernameHidden" value="">
          </div>
          <div class="modal-footer" style="border-top:1px solid rgba(17,24,39,.10);">
            <button type="button" class="btn btn-light" data-dismiss="modal">Cancel</button>
            <button type="submit" name="delete_user" class="btn btn-danger">
              <i class="fa fa-trash mg-r-6"></i> Delete
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- ✅ Delete ALL modal -->
  <div class="modal fade" id="deleteAllModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
      <div class="modal-content" style="border-radius:16px;overflow:hidden;">
        <form method="post">
          <div class="modal-header" style="background:rgba(239,68,68,.10);border-bottom:1px solid rgba(17,24,39,.10);">
            <h4 class="modal-title" style="font-weight:900;">
              <i class="fa fa-exclamation-triangle text-danger mg-r-6"></i> Delete ALL Users
            </h4>
            <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
          </div>
          <div class="modal-body">
            <p style="font-weight:800;">You are about to delete <b><?php echo (int)count($results); ?></b> user(s).</p>
            <p style="color:#b91c1c;font-weight:800;">This cannot be undone.</p>
          </div>
          <div class="modal-footer" style="border-top:1px solid rgba(17,24,39,.10);">
            <button type="button" class="btn btn-light" data-dismiss="modal">Cancel</button>
            <button type="submit" name="delete_all" class="btn btn-danger">
              <i class="fa fa-trash mg-r-6"></i> Delete All
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- ✅ Confirm/Unconfirm modal -->
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

            <input type="hidden" name="status_id" id="statusUserId" value="">
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
    <div class="mg-t-10 mg-md-t-0">Designed by: ThemePixels</div>
  </div>

</div><!-- sh-mainpanel -->

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
    var username = btn.getAttribute('data-username') || '';
    var name = btn.getAttribute('data-name') || 'User';

    document.getElementById('delUserName').textContent = name;
    document.getElementById('delUserEmail').textContent = email;
    document.getElementById('delUserId').value = id;
    document.getElementById('delUserEmailHidden').value = email;
    document.getElementById('delUserUsernameHidden').value = username;

    $('#deleteUserModal').modal('show');
  }

  function openStatusModal(btn){
    var id = btn.getAttribute('data-id');
    var email = btn.getAttribute('data-email') || '';
    var name = btn.getAttribute('data-name') || 'User';
    var st = btn.getAttribute('data-status'); // "1" confirm, "0" unconfirm

    document.getElementById('statusUserId').value = id;
    document.getElementById('statusValue').value = st;

    if (st === '1') {
      document.getElementById('statusModalTitle').textContent = 'Unblock Account';
      document.getElementById('statusModalText').textContent = 'Allow sign-in again for: ' + name + '?';
      document.getElementById('statusGoBtn').className = 'btn btn-primary';
      document.getElementById('statusGoBtn').textContent = 'Unblock';
    } else {
      document.getElementById('statusModalTitle').textContent = 'Block Account';
      document.getElementById('statusModalText').textContent = 'Block sign-in and end active sessions for: ' + name + '?';
      document.getElementById('statusGoBtn').className = 'btn btn-warning';
      document.getElementById('statusGoBtn').textContent = 'Block';
    }

    document.getElementById('statusModalEmail').textContent = email;
    $('#statusModal').modal('show');
  }

  $(function() {
    'use strict';

    var activeKind = <?php echo json_encode($listKind, JSON_UNESCAPED_SLASHES); ?>;

    $.fn.dataTable.ext.search.push(function(settings, data, dataIndex) {
      if (settings.nTable.id !== 'datatable1') {
        return true;
      }
      var row = settings.aoData[dataIndex].nTr;
      if (!row) {
        return true;
      }
      return row.getAttribute('data-account-kind') === activeKind;
    });

    // ✅ DataTable: scroll is CSS only
    var dt = $('#datatable1').DataTable({
      paging: false,
      info: false,
      responsive: false,
      autoWidth: false,
      scrollX: true,
      language: {
        searchPlaceholder: 'Search users...',
        sSearch: '',
        lengthMenu: '_MENU_',
      }
    });

    function updateVisibleCount() {
      var visible = dt.rows({ search: 'applied' }).count();
      $('#visibleUserCount').text(visible);
    }

    function setAccountKind(kind) {
      if (kind !== 'personal' && kind !== 'publisher') {
        return;
      }
      activeKind = kind;
      $('.account-kind-btn').removeClass('is-active');
      $('.account-kind-btn[data-kind="' + kind + '"]').addClass('is-active');
      dt.draw();
      updateVisibleCount();

      var url = new URL(window.location.href);
      url.searchParams.set('kind', kind);
      window.history.replaceState({}, '', url.toString());
    }

    $('.account-kind-btn').on('click', function() {
      setAccountKind($(this).data('kind'));
    });

    dt.on('draw', updateVisibleCount);
    updateVisibleCount();

    // ✅ Move DT controls into pro-tools (fixed)
    $('#dtLen').append($('#datatable1_length'));
    $('#dtSearch').append($('#datatable1_filter'));

    $('.dataTables_length select').select2({ minimumResultsForSearch: Infinity });
  });
</script>



</body>
</html>
