<?php
// /Business_only3/admin/register.php
declare(strict_types=1);

require_once __DIR__ . '/includes/session_admin.php';
requireAdminLogin();

require_once __DIR__ . '/includes/identity.php';
require_once __DIR__ . '/controller.php';

error_reporting(E_ALL);
ini_set('display_errors', '1');

if (!isAdmin()) {
    header("Location: dashboard.php");
    exit;
}

$controller = new Controller();

$error = '';
$msg = '';
$createdTempPassword = '';
$createdFriendCode = '';

if (isset($_POST['submit'])) {
    $fullname = trim((string)($_POST['fullname'] ?? ''));
    $username = trim((string)($_POST['username'] ?? ''));
    $email    = trim((string)($_POST['email'] ?? ''));
    $role     = (int)($_POST['role'] ?? 0);
    $status   = (int)($_POST['status'] ?? 1);

    $result = $controller->createInternalAccountWithInvite([
        'fullname' => $fullname,
        'username' => $username,
        'email'    => $email,
        'role'     => $role,
        'status'   => $status
    ]);

    if (!$result || empty($result['ok'])) {
        $error = (string)($result['error'] ?? 'Failed');
    } else {
        $msg = "Account created successfully and invite email sent.";
        $createdFriendCode = (string)($result['friend_code'] ?? '');
        $createdTempPassword = (string)($result['temp_password'] ?? '');
    }
}

$dbh = $controller->pdo();
$roles = $dbh->query("SELECT idrole, name FROM role WHERE status = 1 ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
  <title>Register Management</title>

  <link href="../lib/font-awesome/css/font-awesome.css" rel="stylesheet">
  <link href="../lib/Ionicons/css/ionicons.css" rel="stylesheet">
  <link href="../lib/bootstrap/bootstrap.css" rel="stylesheet">
  <link rel="stylesheet" href="../css/shamcey.css">

  <style>
    /* ✅ settings.php style fixed page */
    html,body{height:100%;overflow:hidden;}
    .sh-mainpanel{height:100vh;display:flex;flex-direction:column;overflow:hidden;}
    .sh-pagetitle{flex:0 0 auto;}
    .sh-pagebody{
      flex:1 1 auto;
      min-height:0;
      overflow:hidden;
      display:flex;
      flex-direction:column;
      padding-bottom:0!important;
    }

    .fixed-card{
      flex:1 1 auto;
      min-height:0;
      overflow:hidden;
      display:flex;
      flex-direction:column;
      border:1px solid rgba(0,0,0,.08);
      border-radius:10px;
    }

    .card-header.flex-head{
      flex:0 0 auto;
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap:12px;
    }

    .card-body.flex-body{
      flex:1 1 auto;
      min-height:0;
      overflow:auto; /* ✅ only form area scrolls if needed */
      padding:20px;
      background:#fff;
    }

    .note-box{
      border:1px solid rgba(0,0,0,.10);
      border-radius:10px;
      padding:12px;
      background:rgba(8,97,188,.06);
      margin-bottom:15px;
    }

    .codebox{
      border:1px dashed rgba(0,0,0,.25);
      border-radius:10px;
      padding:12px;
      background:#fff;
      font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
    }

    .form-hint{
      font-size:12px;
      opacity:.75;
    }
  </style>
</head>

<body>

<?php include __DIR__ . '/includes/leftbar.php'; ?>
<?php include __DIR__ . '/includes/header.php'; ?>

<div class="sh-mainpanel">

  <div class="sh-pagetitle">
    <div class="input-group"></div>
    <div class="sh-pagetitle-left">
      <div class="sh-pagetitle-icon"><i class="icon ion-ios-people"></i></div>
      <div class="sh-pagetitle-title">
        <span>Admin</span>
        <h2>Register Management</h2>
      </div>
    </div>
  </div>

  <!-- optional top message area like settings.php -->
  <?php if ($error): ?>
    <div class="alert alert-danger" style="margin:10px 12px 0 12px;"><?= h($error) ?></div>
  <?php elseif ($msg): ?>
    <div class="alert alert-success" style="margin:10px 12px 0 12px;"><?= h($msg) ?></div>
  <?php endif; ?>

  <div class="sh-pagebody">

    <div class="card bd-0 fixed-card" style="margin:12px;">
      <div class="card-header bg-primary tx-white flex-head">
        <div style="display:flex;align-items:center;gap:10px;">
          <i class="icon ion-compose" style="font-size:20px;"></i>
          <div>
            <div style="font-weight:700;">Create New Internal Account</div>
            <div style="font-size:12px;opacity:.9;">Generates Friend Code + Temporary Password and sends invite email</div>
          </div>
        </div>
        <div style="font-size:12px;opacity:.9;">
          Admin only
        </div>
      </div>

      <div class="card-body flex-body">

        <?php if ($msg && $createdTempPassword !== '' && $createdFriendCode !== ''): ?>
          <div class="note-box">
            <div style="font-weight:800;margin-bottom:8px;">Share these credentials with the user</div>
            <div class="codebox">
              <div><b>Friend Code:</b> <?= h($createdFriendCode) ?></div>
              <div style="margin-top:6px;"><b>Temporary Password:</b> <?= h($createdTempPassword) ?></div>
              <div class="form-hint" style="margin-top:8px;">
                They can login and will be forced to change password.
              </div>
            </div>
          </div>
        <?php endif; ?>

        <form method="post" autocomplete="off">

          <div class="form-group">
            <label class="form-control-label">Full Name</label>
            <input type="text" name="fullname" class="form-control" placeholder="Type full name">
          </div>

          <div class="row row-xs">
            <div class="col-sm-6">
              <div class="form-group">
                <label class="form-control-label">Username</label>
                <input type="text" name="username" class="form-control" placeholder="Type username">
              </div>
            </div>
            <div class="col-sm-6">
              <div class="form-group">
                <label class="form-control-label">Email Address</label>
                <input type="email" name="email" class="form-control" placeholder="Type email">
              </div>
            </div>
          </div>

          <!-- NOTE: password input removed from logic (you generate temp password in Controller) -->
          <div class="note-box" style="background:rgba(40,167,69,.06);">
            <div style="font-weight:700;">Password</div>
            <div class="form-hint">
              A temporary password is generated automatically and emailed to the user.
            </div>
          </div>

          <div class="row row-xs">
            <div class="col-sm-6">
              <div class="form-group">
                <label class="form-control-label">Role</label>
                <select name="role" class="form-control" required>
                  <?php foreach ($roles as $r): ?>
                    <option value="<?= (int)$r['idrole']; ?>"><?= h((string)$r['name']); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>

            <div class="col-sm-6">
              <div class="form-group">
                <label class="form-control-label">Status</label>
                <select name="status" class="form-control">
                  <option value="1" selected>Active</option>
                  <option value="0">Inactive</option>
                </select>
              </div>
            </div>
          </div>

          <div class="form-group mg-b-20 tx-12">
            By clicking <b>Sign Up</b>, you agree to the <a href="#">Terms of Use</a> and <a href="#">Privacy Policy</a>.
          </div>

          <button type="submit" class="btn btn-success" name="submit" style="min-width:180px;">
            <i class="fa fa-check"></i> Sign Up
          </button>

        </form>

      </div><!-- /card-body -->
    </div><!-- /card -->

    <?php include __DIR__ . '/includes/footer.php'; ?>

  </div><!-- /sh-pagebody -->
</div><!-- /sh-mainpanel -->

<script src="../lib/jquery/jquery.js"></script>
<script src="../lib/popper.js/popper.js"></script>
<script src="../lib/bootstrap/bootstrap.js"></script>
<script src="../js/shamcey.js"></script>

</body>
</html>
