<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

if (!function_exists('h')) {
    function h(string $s): string {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }
}

require_once __DIR__ . '/../admin/controller.php';
$controller = new Controller();
$dbh = $controller->pdo();
require_once __DIR__ . '/includes/org_publisher_access.php';
require_once __DIR__ . '/../admin/includes/admin_linked_accounts_load.php';

require_once __DIR__ . '/../admin/includes/admin_linked_bootstrap_load.php';
admin_linked_apply_session_cookie_path();
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}


$err = '';
if (isset($_GET['expired']) && (string)$_GET['expired'] === '1') {
    $err = app_session_expired_message();
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $u = trim((string)($_POST['username'] ?? ''));
    $p = (string)($_POST['password'] ?? '');

    $type = '';
    $id = 0;

    $auth = publisher_org_authenticate_login($dbh, $u, $p);
    if ($auth) {
        $type = (string)($auth['account_type'] ?? '');
        $id = (int)($auth['account_id'] ?? 0);
    } else {
        // Staff accounts (publisher public_user login handled above).
        $st2 = $dbh->prepare('
            SELECT id, org_id, password, status
            FROM staff_accounts
            WHERE (username = :u OR email = :e)
            LIMIT 1
        ');
        $st2->execute([':u' => $u, ':e' => $u]);

        $row2 = $st2->fetch(PDO::FETCH_ASSOC);

        if ($row2 && (int)$row2['status'] === 1 && password_verify($p, (string)$row2['password'])) {
            $type = 'staff';
            $id = (int)$row2['id'];
            $_SESSION['org_active_org_id'] = (int)$row2['org_id'];
        }
    }

    if ($type === '' || $id <= 0) {
        $adminAuth = admin_linked_verify_credentials($dbh, $u, $p);
        if ($adminAuth) {
            $adminId = (int)($adminAuth['idadmin'] ?? 0);
            admin_linked_ensure_provisioned($dbh, $adminId, $p);
            $managerId = admin_linked_manager_id($dbh, $adminId);
            if ($managerId > 0) {
                $type = 'manager';
                $id = $managerId;
            }
        }
    }

    if ($type === '' || $id <= 0) {
        $err = 'Invalid login.';
    } else {
        $_SESSION['org_auth'] = 1;
        $_SESSION['org_account_type'] = $type;
        $_SESSION['org_account_id']   = $id;
        unset($_SESSION['org_publisher_user_id']);
        app_session_login_mark();

        // Managers: registered publishers skip org picker; bootstrap managers use select_org.php
        if ($type === 'manager') {
            if (org_manager_is_registered_publisher($dbh, $id)) {
                org_manager_apply_registered_publisher_login($dbh, $id);
                publisher_session_establish_for_manager($dbh, $id);
                header('Location: feed.php');
                exit;
            }

            header('Location: select_org.php');
            exit;
        }

        $staffOrgId = (int)($_SESSION['org_active_org_id'] ?? 0);
        if ($staffOrgId > 0) {
            require_once __DIR__ . '/../public_user/includes/staff_publisher_access.php';
            require_once __DIR__ . '/../public_user/includes/account_display_helpers.php';
            $pubUid = staff_pub_org_publisher_user_id($dbh, $staffOrgId);
            if ($pubUid > 0) {
                $_SESSION['org_publisher_user_id'] = $pubUid;
            }
            if ($type === 'staff') {
                $portalRole = account_org_staff_role_label($dbh, $id, $staffOrgId);
                if ($portalRole !== '') {
                    $_SESSION['portal_staff_role_label'] = $portalRole;
                }
            }
        }

        header("Location: feed.php");
        exit;
    }
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
  <title>Organization Login</title>

  <link href="../lib/font-awesome/css/font-awesome.css" rel="stylesheet">
  <link href="../lib/Ionicons/css/ionicons.css" rel="stylesheet">
  <link href="../lib/bootstrap/bootstrap.css" rel="stylesheet">
  <link rel="stylesheet" href="../css/shamcey.css">
</head>
<body>

  <div class="sh-signup-wrapper">
    <div class="signpanel-wrapper">
      <div class="signbox">
        <div class="signbox-header">
          <h2>Organization Login</h2>
          <p class="mg-b-0">Manager, staff, or publisher accounts registered on Talentra can sign in here.</p>
        </div><!-- signbox-header -->

         <?php if ($err): ?>
            <div class="alert alert-danger"><?= h($err) ?></div>
          <?php endif; ?>

        <form method="post" autocomplete="off">
            <div class="signbox-body">
              <div class="form-group">
                <label class="form-control-label">Username:</label>
                <input type="text" name="username" placeholder="Username or Email" class="form-control" required>
              </div><!-- form-group -->
              <div class="form-group">
                <label class="form-control-label">Password:</label>
                <input type="password" name="password" placeholder="Password" class="form-control" required>
              </div><!-- form-group -->
              <div class="form-group">
                <a href="forget.php">Forgot password?</a>
              </div><!-- form-group -->
              <button class="btn btn-success btn-block">Sign In</button>
              <div class="tx-center bg-white bd pd-10 mg-t-40">Not yet a member? <a href="register.php">Create an account</a></div>
            </div><!-- signbox-body -->
         </div>

      </div><!-- signbox -->
    </div><!-- signpanel-wrapper -->
  </div>

  <script src="../lib/jquery/jquery.js"></script>
  <script src="../lib/popper.js/popper.js"></script>
  <script src="../lib/bootstrap/bootstrap.js"></script>
</body>
</html>
