<?php
require_once __DIR__ . '/includes/session_user.php';
require_once __DIR__ . '/controller.php';

error_reporting(E_ALL);
ini_set('display_errors', '0');

$controller = new Controller();
$token = trim((string)($_POST['token'] ?? ($_GET['token'] ?? '')));

$error = '';
$msg = '';

if ($token === '' || !preg_match('/^[a-f0-9]{64}$/', $token)) {
    $error = 'Invalid reset link.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error === '') {
    $new = trim((string)($_POST['newpassword'] ?? ''));
    $confirm = trim((string)($_POST['confirmpassword'] ?? ''));

    if ($new === '' || $confirm === '') {
        $error = 'Please fill both password fields.';
    } elseif ($new !== $confirm) {
        $error = 'Passwords do not match.';
    } elseif (strlen($new) < 8) {
        $error = 'Password must be at least 8 characters.';
    } else {
        $result = $controller->resetPasswordWithToken('user', $token, $new);
        if (!$result['ok']) {
            $error = $result['error'] ?? 'Reset failed.';
        } else {
            $msg = 'Password reset successful. You can sign in now.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Reset Password</title>
    <link href="./lib/font-awesome/css/font-awesome.css" rel="stylesheet">
    <link href="./lib/Ionicons/css/ionicons.css" rel="stylesheet">
    <link rel="stylesheet" href="./css/shamcey.css">
    <link rel="stylesheet" href="assets/ui_best.css">
  </head>
  <body class="bg-gray-900">
    <div class="signpanel-wrapper">
      <div class="signbox">
        <div class="signbox-header">
          <h2>Create New Password</h2>
          <p class="mg-b-0">Choose a new password for your account.</p>
        </div>

        <?php if ($error !== ''): ?>
          <div class="alert alert-danger mg-b-0"><?php echo htmlentities($error); ?></div>
        <?php endif; ?>

        <?php if ($msg !== ''): ?>
          <div class="alert alert-success mg-b-0"><?php echo htmlentities($msg); ?></div>
        <?php endif; ?>

        <div class="signbox-body">
          <?php if ($msg === '' && $error !== 'Invalid reset link.'): ?>
            <form method="post" autocomplete="off">
              <?php echo csrfInput(); ?>
              <input type="hidden" name="token" value="<?php echo htmlentities($token); ?>">
              <div class="form-group">
                <label class="form-control-label">New Password:</label>
                <input type="password" name="newpassword" placeholder="Enter new password" class="form-control" required>
              </div>
              <div class="form-group">
                <label class="form-control-label">Confirm Password:</label>
                <input type="password" name="confirmpassword" placeholder="Confirm new password" class="form-control" required>
              </div>
              <button class="btn btn-success btn-block" type="submit">Reset Password</button>
            </form>
          <?php endif; ?>

          <div class="tx-center bg-white bd pd-10 mg-t-40">
            <a href="index.php">Back to sign in</a>
          </div>
        </div>
      </div>
    </div>

    <script src="./lib/jquery/jquery.js"></script>
    <script src="./lib/popper.js/popper.js"></script>
    <script src="./lib/bootstrap/bootstrap.js"></script>
    <script src="./js/shamcey.js"></script>
  </body>
</html>
