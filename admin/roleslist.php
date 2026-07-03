<?php
/**
 * roleslist.php
 * ✅ Add-role Bootstrap MODAL
 * ✅ Admin-only
 * ✅ card-body fixed
 * ✅ ONLY table ROWS scroll (tbody area)
 * ✅ Search moved to top header (professional)
 */

require_once __DIR__ . '/includes/session_admin.php';
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

/* =============================
   DELETE ROLE
============================= */
if (isset($_POST['delete_role'])) {
    $rid = (int)($_POST['delete_idrole'] ?? 0);

    if ($rid <= 0) {
        $error = "Invalid role.";
    } elseif (in_array($rid, [1,2,3,4], true)) {
        $error = "Default roles cannot be deleted.";
    } else {
        $stCnt = $dbh->prepare("SELECT COUNT(*) FROM admin WHERE role = :rid");
        $stCnt->execute([':rid' => $rid]);
        $cnt = (int)$stCnt->fetchColumn();

        if ($cnt > 0) {
            $error = "Role is assigned to {$cnt} admin(s).";
        } else {
            $dbh->prepare("DELETE FROM role WHERE idrole=:id")->execute([':id'=>$rid]);
            $msg = "Role deleted successfully.";
        }
    }
}

/* =============================
   CREATE ROLE
============================= */
if (isset($_POST['create_role'])) {
    $name = trim($_POST['role_name'] ?? '');
    $inh  = (int)($_POST['inherits_from'] ?? 0);
    $st   = (int)($_POST['status'] ?? 1);

    if ($name === '') {
        $error = "Role name required.";
    } else {
        try {
            $dbh->prepare("
                INSERT INTO role(name,inherits_from,status)
                VALUES(:n,:i,:s)
            ")->execute([
                ':n'=>$name,
                ':i'=>($inh > 0 ? $inh : null),
                ':s'=>($st === 1 ? 1 : 0),
            ]);
            $msg = "Role created.";
        } catch (PDOException $e) {
            $error = "Role already exists.";
        }
    }
}

/* =============================
   UPDATE ROLE
============================= */
if (isset($_POST['update_role'])) {
    $rid  = (int)($_POST['idrole'] ?? 0);
    $name = trim($_POST['role_name'] ?? '');

    if ($rid <= 0 || $name === '') {
        $error = "Invalid input.";
    } elseif (in_array($rid,[1,2,3,4],true)) {
        $error = "Default roles cannot be renamed.";
    } else {
        $dbh->prepare("UPDATE role SET name=:n WHERE idrole=:i")
            ->execute([':n'=>$name,':i'=>$rid]);
        $msg = "Role updated.";
    }
}

/* =============================
   DATA
============================= */
$roles = $dbh->query("SELECT idrole,name FROM role ORDER BY idrole")->fetchAll(PDO::FETCH_OBJ);
$baseRoles = $dbh->query("SELECT idrole,name FROM role WHERE status=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Roles</title>

<link href="../lib/font-awesome/css/font-awesome.css" rel="stylesheet">
<link href="../lib/datatables/jquery.dataTables.css" rel="stylesheet">
<link rel="stylesheet" href="../css/shamcey.css">

<style>
/* =========================
   FIXED PAGE (no scrolling)
========================= */
html,body{ height:100%; overflow:hidden; }

/* main panel fixed */
.sh-mainpanel{
  height:100vh;
  display:flex;
  flex-direction:column;
  overflow:hidden;
}
.sh-pagetitle{ flex:0 0 auto; }

/* pagebody fixed */
.sh-pagebody{
  flex:1 1 auto;
  overflow:hidden;
  padding-bottom:0!important;
  display:flex;
  flex-direction:column;
}

/* roles card fills space */
.roles-card{
  flex:1 1 auto;
  min-height:0;
  display:flex;
  flex-direction:column;
}
.roles-card .card-header{ flex:0 0 auto; }

/* ✅ card-body fixed (NOT scroll) */
.roles-card .card-body{
  flex:1 1 auto;
  min-height:0;
  overflow:hidden;
  display:flex;
  flex-direction:column;
}

/* ✅ ONLY this scrolls (the rows area) */
.table-scroll{
  flex:1 1 auto;
  min-height:0;
  overflow:auto;
  border-top:1px solid rgba(0,0,0,.06);
}

/* table polish */
.role-pill{
  padding:6px 10px;
  border-radius:999px;
  font-weight:900;
  background:#e0e7ff;
}
.locked-pill{
  padding:6px 10px;
  border-radius:999px;
  background:#eee;
  font-weight:900;
}
.icon-btn{
  width:40px;height:40px;
  border-radius:12px;
  border:1px solid #ddd;
  background:#fff;
}
.icon-btn.danger{ color:#e11d48; }

/* ✅ sticky header so only rows move */
#datatable1 thead th{
  position: sticky;
  top: 0;
  background: #fff;
  z-index: 5;
}

/* DataTables: keep controls from forcing page scroll */
.dataTables_wrapper{ width:100%; }

/* ✅ hide default DT filter (we move it to header) */
.dataTables_wrapper .dataTables_filter{
  display:none !important;
}

/* =========================
   PRO HEADER CONTROLS
========================= */
.roles-topbar{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:12px;
  flex-wrap:wrap;
}

.roles-topbar .left{
  display:flex;
  align-items:center;
  gap:12px;
  flex-wrap:wrap;
}

.roles-title{
  font-weight:900;
  letter-spacing:.2px;
}

.top-search{
  display:flex;
  align-items:center;
  gap:8px;
  padding:6px 10px;
  border:1px solid rgba(17,24,39,.12);
  border-radius:14px;
  background:#fff;
  height:40px;
  min-width:260px;
}

.top-search i{ color:rgba(17,24,39,.55); }

.top-search input{
  border:0 !important;
  outline:none !important;
  box-shadow:none !important;
  width:220px;
  font-weight:700;
}

@media (max-width: 576px){
  .top-search{ width:100%; min-width:unset; }
  .top-search input{ width:100%; }
}
</style>
</head>

<body>

<?php include('includes/leftbar.php'); ?>
<?php include('includes/header.php'); ?>

<div class="sh-mainpanel">
  <div class="sh-pagetitle">
    <div class="input-group"></div>
    <div class="sh-pagetitle-left">
      <div class="sh-pagetitle-icon"><i class="icon ion-ios-people"></i></div>
      <div class="sh-pagetitle-title">
        <span>Admin</span>
        <h2>Roles Management</h2>
      </div>
    </div>
  </div>

  <div class="sh-pagebody">

    <?php if($error): ?><div class="alert alert-danger"><?php echo htmlentities($error); ?></div><?php endif; ?>
    <?php if($msg): ?><div class="alert alert-success"><?php echo htmlentities($msg); ?></div><?php endif; ?>

    <div class="card roles-card">
      <div class="card-header">
        <div class="roles-topbar">
          <div class="left">
            <span class="roles-title">Roles</span>

            <!-- ✅ Search moved here -->
            <div class="top-search">
              <i class="fa fa-search"></i>
              <input id="rolesSearchBox" type="text" placeholder="Search roles..." autocomplete="off">
            </div>
          </div>

          <button class="btn btn-primary btn-sm" data-toggle="modal" data-target="#addRoleModal">
            <i class="fa fa-plus"></i> Add Role
          </button>
        </div>
      </div>

      <div class="card-body" style="padding: 40px;">
        <div class="table-scroll" id="rolesTableScroll">
          <table id="datatable1" class="table" style="width:100%; margin:0;">
            <thead>
              <tr>
                <th style="width:80px;">#</th>
                <th style="width:260px;">Role</th>
                <th style="width:520px;">Edit</th>
                <th style="width:140px;">Actions</th>
              </tr>
            </thead>
            <tbody>
            <?php $i=1; foreach($roles as $r):
              $locked=in_array((int)$r->idrole,[1,2,3,4],true);
            ?>
              <tr>
                <td><?php echo (int)$i++; ?></td>
                <td>
                  <?php if($locked): ?>
                    <span class="locked-pill"><?php echo htmlentities($r->name); ?></span>
                  <?php else: ?>
                    <span class="role-pill"><?php echo htmlentities($r->name); ?></span>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if(!$locked): ?>
                  <form method="post" style="display:flex;gap:6px;align-items:center;margin:0;">
                    <input type="hidden" name="idrole" value="<?php echo (int)$r->idrole; ?>">
                    <input type="text" name="role_name" value="<?php echo htmlentities($r->name); ?>" class="form-control" style="height:40px;max-width:420px;">
                    <button name="update_role" class="icon-btn" type="submit" title="Save">
                      <i class="fa fa-save"></i>
                    </button>
                  </form>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if(!$locked): ?>
                  <button class="icon-btn danger"
                          type="button"
                          data-roleid="<?php echo (int)$r->idrole; ?>"
                          data-rolename="<?php echo htmlentities($r->name); ?>"
                          onclick="openDeleteModal(this)">
                    <i class="fa fa-trash"></i>
                  </button>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>

    </div>
  </div>
</div>

<!-- ADD ROLE MODAL -->
<div class="modal fade" id="addRoleModal" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog" role="document">
    <form method="post" class="modal-content">
      <div class="modal-header">
        <h4 class="modal-title">Add Role</h4>
        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>

      <div class="modal-body">
        <input name="role_name" class="form-control" placeholder="Role name" required>

        <select name="inherits_from" class="form-control mg-t-10">
          <option value="0">No inheritance</option>
          <?php foreach($baseRoles as $b): ?>
          <option value="<?php echo (int)$b['idrole']; ?>"><?php echo htmlentities($b['name']); ?></option>
          <?php endforeach; ?>
        </select>

        <select name="status" class="form-control mg-t-10">
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

<!-- DELETE MODAL -->
<div class="modal fade" id="deleteRoleModal" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog" role="document">
    <form method="post" class="modal-content">
      <div class="modal-header">
        <h4 class="modal-title">Confirm Delete</h4>
        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
          <span aria-hidden="true">&times;</span>
        </button>
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
<script src="../lib/datatables/jquery.dataTables.js"></script>

<script>
function openDeleteModal(el){
  $('#deleteRoleId').val(el.dataset.roleid);
  $('#deleteRoleName').text(el.dataset.rolename);
  $('#deleteRoleModal').modal('show');
}

$(document).ready(function(){
  var dt = $('#datatable1').DataTable({
    paging: false,
    info: false,
    autoWidth: false
  });

  // ✅ connect custom header search to DataTables
  $('#rolesSearchBox').on('keyup change', function(){
    dt.search(this.value).draw();
  });
});
</script>

</body>
</html>
