<?php
require_once __DIR__ . '/includes/session_user.php';
require_once __DIR__ . '/controller.php';

error_reporting(E_ALL);
ini_set('display_errors', '0');

$controller = new Controller();
$error = '';
$msg = '';
$login = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login = trim((string)($_POST['login'] ?? ''));

    if ($login === '') {
        $error = 'Please enter your username or email.';
    } else {
        try {
            $result = $controller->createUserReset($login);

            if (!empty($result['email']) && !empty($result['token']) && !empty($result['username'])) {
                $sent = $controller->sendPasswordResetEmail(
                    'user',
                    (string)$result['email'],
                    (string)$result['username'],
                    (string)$result['token']
                );

                if (!$sent) {
                    error_log('Password reset email could not be sent for public user account: ' . (string)$result['username']);
                }
            }

            $msg = 'If an active account matches that username or email, a reset link has been sent to the registered email address.';
            $login = '';
        } catch (Throwable $e) {
            $error = 'Unable to start password reset right now. Please try again later.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Forgot Password</title>
    <link href="./lib/font-awesome/css/font-awesome.css" rel="stylesheet">
    <link href="./lib/Ionicons/css/ionicons.css" rel="stylesheet">
    <link rel="stylesheet" href="./css/shamcey.css">
    <link rel="stylesheet" href="assets/ui_best.css">
  </head>
  <body class="bg-gray-900">
    <div class="signpanel-wrapper">
      <div class="signbox">
        <div class="signbox-header">
          <h2>Reset Password</h2>
          <p class="mg-b-0">Enter your username or email to receive a reset link.</p>
        </div>

        <?php if ($error !== ''): ?>
          <div class="alert alert-danger mg-b-0"><?php echo htmlentities($error); ?></div>
        <?php endif; ?>

        <?php if ($msg !== ''): ?>
          <div class="alert alert-success mg-b-0"><?php echo htmlentities($msg); ?></div>
        <?php endif; ?>

        <div class="signbox-body">
          <form method="post" autocomplete="off">
            <?php echo csrfInput(); ?>
            <div class="form-group">
              <label class="form-control-label">Username or Email:</label>
              <input
                type="text"
                name="login"
                value="<?php echo htmlentities($login); ?>"
                placeholder="Enter username or email"
                class="form-control"
                required
              >
            </div>
            <button class="btn btn-success btn-block" type="submit">Send Reset Link</button>
          </form>
          <div class="tx-center bg-white bd pd-10 mg-t-40">
            Remembered your password? <a href="index.php">Back to sign in</a>
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
