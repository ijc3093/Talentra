<?php
// /Business_only3/admin2/app/index.php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/includes/session_admin.php';
require_once __DIR__ . '/controller.php';

$controller = new Controller();
$dbh = $controller->pdo();

$error = '';

// Already logged in
if (!empty($_SESSION['admin_id']) && !empty($_SESSION['userRole'])) {
    header("Location: dashboard.php");
    exit;
}

// Friendly messages
if (isset($_GET['inactiveRole'])) $error = "ERROR: Your role is invalid. Contact Admin.";
if (isset($_GET['inactive']))     $error = "ERROR: Your account is inactive. Contact Admin.";
if (isset($_GET['locked']))       $error = "ERROR: Your account is temporarily locked. Try again later.";
if (isset($_GET['expired']))      $error = app_session_expired_message();

if (isset($_POST['login'])) {
    $login = trim((string)($_POST['username'] ?? ''));
    $pass  = trim((string)($_POST['password'] ?? ''));

    if ($login === '' || $pass === '') {
        $error = "Please enter username or email and password.";
    } else {
        $st = $dbh->prepare("
            SELECT idadmin, fullname, username, email, password, role, status,
                   force_password_change, failed_login_attempts, locked_until,
                   friend_code, image
            FROM admin
            WHERE (username = :u OR email = :e)
            LIMIT 1
        ");
        $st->execute([':u' => $login, ':e' => $login]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            $error = "Invalid login credentials.";
        } elseif ((int)$row['status'] !== 1) {
            $error = "Your account is inactive. Contact Admin.";
        } elseif (!empty($row['locked_until']) && strtotime((string)$row['locked_until']) > time()) {
            $error = "Account temporarily locked. Try again later.";
        } else {

            // verify password using your Controller helper
            $admin = $controller->adminLogin($login, $pass);

            if (!$admin) {
                $attempts = (int)($row['failed_login_attempts'] ?? 0) + 1;
                $lockedUntil = null;

                if ($attempts >= 5) {
                    $lockedUntil = date('Y-m-d H:i:s', time() + 15 * 60);
                    $attempts = 0;
                }

                $up = $dbh->prepare("
                    UPDATE admin
                    SET failed_login_attempts = :attempts,
                        locked_until = :locked_until
                    WHERE idadmin = :idadmin
                    LIMIT 1
                ");
                $up->execute([
                    ':attempts'     => $attempts,
                    ':locked_until' => $lockedUntil,
                    ':idadmin'      => (int)$row['idadmin'],
                ]);

                $error = "Invalid login credentials.";
            } else {

                // success reset
                $up = $dbh->prepare("
                    UPDATE admin
                    SET failed_login_attempts = 0,
                        locked_until = NULL,
                        last_login_at = NOW()
                    WHERE idadmin = :idadmin
                    LIMIT 1
                ");
                $up->execute([':idadmin' => (int)$row['idadmin']]);

                setAdminSession([
                    'idadmin'     => (int)$row['idadmin'],
                    'username'    => (string)$row['username'],
                    'email'       => (string)$row['email'],
                    'role'        => (int)$row['role'], // ✅ role table idrole
                    'image'       => (string)($row['image'] ?? 'default.jpg'),
                    'friend_code' => (string)($row['friend_code'] ?? ''),
                ]);

                // Force change password
                if ((int)($row['force_password_change'] ?? 0) === 1) {
                    header("Location: change-password.php?force=1");
                    exit;
                }

                header("Location: dashboard.php");
                exit;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
  <head>
    <!-- Required meta tags -->
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">

    <!-- Twitter -->
    <meta name="twitter:site" content="@themepixels">
    <meta name="twitter:creator" content="@themepixels">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="Talentra">
    <meta name="twitter:description" content="Premium Quality and Responsive UI for Dashboard.">
    <meta name="twitter:image" content="http://themepixels.me/shamcey/img/shamcey-social.png">

    <!-- Facebook -->
    <meta property="og:url" content="http://themepixels.me/shamcey">
    <meta property="og:title" content="Talentra">
    <meta property="og:description" content="Premium Quality and Responsive UI for Dashboard.">

    <meta property="og:image" content="http://themepixels.me/shamcey/img/shamcey-social.png">
    <meta property="og:image:secure_url" content="http://themepixels.me/shamcey/img/shamcey-social.png">
    <meta property="og:image:type" content="image/png">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="600">

    <!-- Meta -->
    <meta name="description" content="Premium Quality and Responsive UI for Dashboard.">
    <meta name="author" content="ThemePixels">


    <title>Admin sign in — Talentra</title>

    <!-- Vendor css -->
    <link href="../lib/font-awesome/css/font-awesome.css" rel="stylesheet">
    <link href="../lib/Ionicons/css/ionicons.css" rel="stylesheet">

    <!-- Shamcey CSS -->
    <link rel="stylesheet" href="../css/shamcey.css">
  </head>

  <body class="bg-gray-900">

    <div class="signpanel-wrapper">
      <div class="signbox">
        <div class="signbox-header">
          <h2>Talentra</h2>
          <p class="mg-b-0">Responsive Bootstrap 4 Admin Template</p>
          <?php if ($error !== ''): ?>
                <div class="errorWrap"><strong><?php echo htmlentities($error); ?></strong></div>
              <?php endif; ?>
        </div><!-- signbox-header -->
        <div class="signbox-body">
          <form method="post" autocomplete="off">
            <div class="form-group">
              <label class="form-control-label">Username or Email:</label>
              <input type="text" name="username" placeholder="Enter your username or email" class="form-control" required>
            </div><!-- form-group -->
            <div class="form-group">
              <label class="form-control-label">Password:</label>
              <input type="password" name="password" placeholder="Enter your password" class="form-control" required>
            </div><!-- form-group -->
            <div class="form-group">
              <a href="forget.php">Forgot password?</a>
            </div><!-- form-group -->
            <button class="btn btn-success btn-block" type="submit" name="login">Sign In</button>
            <div class="tx-center bg-white bd pd-10 mg-t-40">Not yet a member? <a href="register.php">Create an account</a></div>
          </form>
        </div><!-- signbox-body -->
      </div><!-- signbox -->
    </div><!-- signpanel-wrapper -->

    <script src="../lib/jquery/jquery.js"></script>
    <script src="../lib/popper.js/popper.js"></script>
    <script src="../lib/bootstrap/bootstrap.js"></script>

    <script src="../js/shamcey.js"></script>
  </body>
</html>
