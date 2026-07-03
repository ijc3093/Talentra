<?php
// /Business_only3/public_user/index.php
require_once __DIR__ . '/includes/session_user.php';
require_once __DIR__ . '/includes/deleted_user_registry.php';
require_once __DIR__ . '/controller.php';
require_once __DIR__ . '/includes/staff_publisher_access.php';
require_once __DIR__ . '/../admin/includes/admin_linked_accounts_load.php';

error_reporting(E_ALL);
ini_set('display_errors', '0');

$error = '';
$usernameValue = '';
$accountType = strtolower(trim((string)($_GET['account_type'] ?? 'personal')));
if (!in_array($accountType, ['personal', 'publisher'], true)) {
    $accountType = 'personal';
}

function login_user_is_publisher(array $user): bool
{
    $accountKind = strtolower(trim((string)($user['account_kind'] ?? 'personal')));
    $friendCode = strtoupper(trim((string)($user['friend_code'] ?? '')));
    return $accountKind === 'publisher' || str_starts_with($friendCode, 'PUB-');
}

function login_bump_last_seen(Controller $controller): void
{
    try {
        $uid = (int)($_SESSION['user_id'] ?? 0);
        if ($uid > 0) {
            $stSeen = $controller->pdo()->prepare('UPDATE users SET last_seen = NOW() WHERE id = :id LIMIT 1');
            $stSeen->execute([':id' => $uid]);
        }
    } catch (Throwable $e) {
        // ignore presence update failures
    }
}

if (isset($_GET['deactivated']) && (string)$_GET['deactivated'] === '1') {
    $error = user_account_deactivated_message();
}

if (isset($_GET['deleted']) && (string)$_GET['deleted'] === '1') {
    $error = user_account_deleted_message();
}

if (isset($_GET['expired']) && (string)$_GET['expired'] === '1') {
    $error = app_session_expired_message();
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && array_key_exists('session', $_GET)) {
    header("Location: index.php");
    exit;
}

if (empty($_SESSION['user_id']) || empty($_SESSION['user_login'])) {
    try {
        require_once __DIR__ . '/../admin/includes/admin_linked_bootstrap_load.php';
        if (admin_linked_verify_portal_handoff()) {
            $query = (string)($_SERVER['QUERY_STRING'] ?? '');
            header('Location: linked_portal_enter.php' . ($query !== '' ? '?' . $query : ''));
            exit;
        }
        if (admin_linked_sync_public_user_from_admin_intent((new Controller())->pdo())) {
            header('Location: feed.php');
            exit;
        }
    } catch (Throwable $e) {
        // show login form
    }
}

// already logged in -> feed (unless account was blocked or deleted)
if (!empty($_SESSION['user_login']) && !empty($_SESSION['user_id'])) {
    try {
        $controller = new Controller();
        $uid = (int)($_SESSION['user_id'] ?? 0);
        $loginId = trim((string)($_SESSION['user_login'] ?? ''));
        if ($uid > 0 && user_is_account_removed($controller->pdo(), $uid)) {
            redirectUserLoginDeleted();
        }
        if ($loginId !== '' && user_login_identifier_was_deleted($controller->pdo(), $loginId)) {
            redirectUserLoginDeleted();
        }
        if ($uid > 0 && user_is_account_deactivated($controller->pdo(), $uid)) {
            redirectUserLoginDeactivated();
        }
    } catch (Throwable $e) {
        // fall through to feed redirect
    }
    header("Location: feed.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $usernameValue = trim($_POST['username'] ?? '');
    $username = $usernameValue;
    $password = trim($_POST['password'] ?? '');
    $postedAccountType = strtolower(trim((string)($_POST['account_type'] ?? 'personal')));
    if (in_array($postedAccountType, ['personal', 'publisher'], true)) {
        $accountType = $postedAccountType;
    }

    if ($username === '' || $password === '') {
        $error = 'Please enter username or email and password.';
    } else {
        try {
            $controller = new Controller();

            $loginResult = $controller->userLoginAttempt($username, $password);
            $loginReason = (string)($loginResult['reason'] ?? '');

            if ($loginReason === 'deleted') {
                $error = user_account_deleted_message();
            } elseif ($loginReason === 'deactivated') {
                $error = user_account_deactivated_message();
            } elseif (
                $loginReason === 'not_found'
                && user_login_identifier_was_deleted($controller->pdo(), $username)
            ) {
                $error = user_account_deleted_message();
            } else {
                $user = !empty($loginResult['ok']) ? $loginResult['user'] : null;
                $isPublisherLogin = ($accountType === 'publisher');

                if ($user && $isPublisherLogin && !login_user_is_publisher($user)) {
                    $error = 'This is a personal account. Switch to Personal at the top to sign in.';
                } elseif ($user && !$isPublisherLogin && login_user_is_publisher($user)) {
                    $error = 'This is a publisher account. Switch to Publisher at the top to sign in.';
                } elseif ($user) {
                    setUserSession($user);
                    login_bump_last_seen($controller);
                    header('Location: feed.php');
                    exit;
                } elseif ($isPublisherLogin) {
                    $staffAuth = staff_pub_authenticate($controller->pdo(), $username, $password);
                    if ($staffAuth) {
                        staff_pub_set_session(
                            $controller->pdo(),
                            (array)$staffAuth['staff'],
                            (array)$staffAuth['publisher']
                        );
                        header('Location: feed.php');
                        exit;
                    }
                }

                if ($error === '') {
                    $adminAuth = admin_linked_verify_credentials($controller->pdo(), $username, $password);
                    if ($adminAuth) {
                        $adminId = (int)($adminAuth['idadmin'] ?? 0);
                        admin_linked_ensure_provisioned($controller->pdo(), $adminId, $password);
                        $linkedUser = admin_linked_portal_user(
                            $controller->pdo(),
                            $adminId,
                            $isPublisherLogin ? 'publisher' : 'personal'
                        );
                        if ($linkedUser) {
                            setUserSession($linkedUser);
                            login_bump_last_seen($controller);
                            header('Location: feed.php');
                            exit;
                        }
                    }
                }

                if ($error === '') {
                    $error = $isPublisherLogin
                        ? 'Invalid publisher credentials or account inactive.'
                        : 'Invalid login credentials or account inactive.';
                }
            }
        } catch (Throwable $e) {
            $error = 'Unable to sign in right now. Please try again.';
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


    <title>Sign in — Talentra</title>

    <!-- Vendor css -->
    <link href="./lib/font-awesome/css/font-awesome.css" rel="stylesheet">
    <link href="./lib/Ionicons/css/ionicons.css" rel="stylesheet">

    <!-- Shamcey CSS -->
    <link rel="stylesheet" href="./css/shamcey.css">
    <link rel="stylesheet" href="assets/ui_best.css">
    <style>
      .signbox-alert{
        margin: 0 25px 12px;
        padding: 12px 14px;
        border-radius: 10px;
        background: #fef2f2;
        border: 1px solid #fecaca;
        color: #991b1b;
        font-size: 14px;
        line-height: 1.45;
        font-weight: 600;
      }
      .acct-type-row{
        display:flex;
        gap:10px;
        margin-bottom:16px;
      }
      .acct-type-row label{
        flex:1;
        border:1px solid #d1d5db;
        border-radius:10px;
        padding:10px 12px;
        cursor:pointer;
        text-align:center;
        font-weight:700;
        margin:0;
        background:#fff;
        transition:border-color .15s ease, background-color .15s ease;
      }
      .acct-type-row input{
        position:absolute;
        opacity:0;
        pointer-events:none;
      }
      .acct-type-row label:has(input:checked){
        border-color:#2563eb;
        background:#eff6ff;
      }
      .acct-type-row .tx-12{
        margin-top:4px;
        font-size:11px;
        font-weight:600;
        color:#64748b;
      }
      .login-mode-note{
        margin:0 0 14px;
        font-size:13px;
        line-height:1.45;
        color:#475569;
      }
      body.is-publisher-login .login-mode-personal{display:none}
      body.is-publisher-login .login-mode-publisher{display:block}
      .login-mode-publisher{display:none}
    </style>

    <!-- Script -->
    <script src="assets/ui_best.js" defer></script>
    <script src="./lib/jquery/jquery.js"></script>
    <script src="./lib/popper.js/popper.js"></script>
    <script src="./lib/bootstrap/bootstrap.js"></script>
    <script src="./js/shamcey.js"></script>
  </head>

  <body class="bg-gray-900<?php echo $accountType === 'publisher' ? ' is-publisher-login' : ''; ?>">

    <div class="signpanel-wrapper">
      <div class="signbox">
        <div class="signbox-header">
          <h2>Private App</h2>
          <p class="mg-b-0 login-mode-personal">Sign in to your personal account</p>
          <p class="mg-b-0 login-mode-publisher">Sign in as a publisher brand or staff</p>
        </div><!-- signbox-header -->

         <?php if ($error !== ''): ?>
            <div class="signbox-alert" role="alert">
                <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
            </div>
        <?php endif; ?>

        <form method="post" autocomplete="off">
            <?php echo csrfInput(); ?>
            <div class="signbox-body">
              <div class="acct-type-row" role="group" aria-label="Account type">
                <label>
                  <input type="radio" name="account_type" value="personal"<?php echo $accountType === 'personal' ? ' checked' : ''; ?>>
                  <span>Personal</span>
                  <div class="tx-12">Friends &amp; family</div>
                </label>
                <label>
                  <input type="radio" name="account_type" value="publisher"<?php echo $accountType === 'publisher' ? ' checked' : ''; ?>>
                  <span>Publisher</span>
                  <div class="tx-12">CNN, Fox, ABC…</div>
                </label>
              </div>

              <p class="login-mode-note login-mode-personal">Use your personal username or email.</p>
              <p class="login-mode-note login-mode-publisher">Use your publisher or organization staff credentials.</p>

              <div class="form-group">
                <label class="form-control-label login-mode-personal">Username or Email:</label>
                <label class="form-control-label login-mode-publisher">Publisher username, email, or staff login:</label>
                <input type="text" name="username" value="<?php echo htmlspecialchars($usernameValue, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Enter username or email" class="form-control" required>
              </div><!-- form-group -->
              <div class="form-group">
                <label class="form-control-label">Password:</label>
                <input type="password" name="password" placeholder="Enter your password" class="form-control" required>
              </div><!-- form-group -->
              <div class="form-group">
                <a href="forget.php">Forgot password?</a>
              </div><!-- form-group -->
              <button name="login" type="submit" value="1" class="btn btn-success btn-block" id="loginSubmitBtn">Sign In</button>
              <div class="tx-center bg-white bd pd-10 mg-t-40 login-mode-personal">Not yet a member? <a href="register.php">Create personal account</a></div>
              <div class="tx-center bg-white bd pd-10 mg-t-40 login-mode-publisher">Not yet a publisher? <a href="register.php?account_type=publisher">Create publisher account</a></div>
            </div><!-- signbox-body -->
        </form>

      </div><!-- signbox -->
    </div><!-- signpanel-wrapper -->
    <script>
      (function () {
        var radios = document.querySelectorAll('input[name="account_type"]');
        var submitBtn = document.getElementById('loginSubmitBtn');

        function syncLoginMode() {
          var publisher = document.querySelector('input[name="account_type"][value="publisher"]');
          var isPublisher = !!(publisher && publisher.checked);
          document.body.classList.toggle('is-publisher-login', isPublisher);
          if (submitBtn) {
            submitBtn.textContent = isPublisher ? 'Sign In as Publisher' : 'Sign In';
          }
        }

        radios.forEach(function (radio) {
          radio.addEventListener('change', syncLoginMode);
        });
        syncLoginMode();
      })();
    </script>
  </body>
</html>
