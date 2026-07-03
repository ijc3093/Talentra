<?php
// /Business_only3/compose.php
declare(strict_types=1);

require_once __DIR__ . '/includes/session_user.php';
requireUserLogin();

require_once __DIR__ . '/controller.php';

error_reporting(E_ALL);
ini_set('display_errors', '0');

$controller = new Controller();
$dbh = $controller->pdo();

$meId    = myUserId();
$meCode  = function_exists('userFriendCode') ? trim((string)userFriendCode()) : trim((string)($_SESSION['user_friend_code'] ?? ''));
$myRole  = function_exists('userRoleId') ? (int)userRoleId() : (int)($_SESSION['user_role'] ?? 0);

if ($meId <= 0 || $meCode === '') {
    clearUserSession();
    header("Location: index.php?session=reset");
    exit;
}

$error = '';
$prefillTo = trim((string)($_GET['to'] ?? ''));

/**
 * Friend-code ONLY recipient resolver
 * Returns: ok, peerCode
 */
function resolveRecipientFriendCode(PDO $dbh, string $peerCode, string $meCode, int $myRole): array
{
    $peerCode = trim($peerCode);

    if ($peerCode === '') {
        return ['ok' => false, 'error' => 'Friend code is required.'];
    }

    // Basic format guard
    if (!preg_match('/^[A-Z]{3}-[A-Z0-9]{4}-[A-Z0-9]{4}$/i', $peerCode)) {
        return ['ok' => false, 'error' => 'Invalid friend code format (example: USR-AB12-CD34).'];
    }

    // Prevent self
    if (strcasecmp($peerCode, $meCode) === 0) {
        return ['ok' => false, 'error' => 'You cannot message yourself.'];
    }

    $st = $dbh->prepare("SELECT id, friend_code, role, status FROM users WHERE friend_code = ? LIMIT 1");
    $st->execute([$peerCode]);
    $u = $st->fetch(PDO::FETCH_ASSOC);

    if (!$u) return ['ok' => false, 'error' => 'Friend code not found.'];
    if ((int)($u['status'] ?? 0) !== 1) return ['ok' => false, 'error' => 'User account is inactive.'];

    // OPTIONAL rule: same role only (keep if you want)
    if ((int)($u['role'] ?? 0) !== $myRole) {
        return ['ok' => false, 'error' => 'You can only chat with users in your same role.'];
    }

    return ['ok' => true, 'peerCode' => (string)$u['friend_code']];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $to = trim((string)($_POST['to'] ?? ''));

    $res = resolveRecipientFriendCode($dbh, $to, $meCode, $myRole);

    if (!$res['ok']) {
        $error = (string)($res['error'] ?? 'Invalid recipient.');
    } else {
        // ✅ IMPORTANT: send friend_code to sendreply
        header("Location: user_sendreply.php?to=" . urlencode($res['peerCode']));

        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en" class="compose-page">
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


    <title>Talentra</title>
    <?php
    require_once __DIR__ . '/includes/theme_prefs.php';
    theme_prefs_print_head_bootstrap($dbh, $meId);
    ?>

    <!-- vendor css -->
    <link href="./lib/font-awesome/css/font-awesome.css" rel="stylesheet">
    <link href="./lib/Ionicons/css/ionicons.css" rel="stylesheet">
    <link href="./lib/perfect-scrollbar/css/perfect-scrollbar.css" rel="stylesheet">
    <link href="./lib/select2/css/select2.min.css" rel="stylesheet">

    <!-- Shamcey CSS -->
    <link rel="stylesheet" href="./css/shamcey.css">
    <link rel="stylesheet" href="assets/ui_best.css">

    <script src="assets/ui_best.js" defer></script>
    <script src="./lib/jquery/jquery.js"></script>
    <script src="./lib/popper.js/popper.js"></script>
    <script src="./lib/bootstrap/bootstrap.js"></script>
    <script src="./lib/perfect-scrollbar/js/perfect-scrollbar.jquery.js"></script>
    <script src="./lib/select2/js/select2.min.js"></script>
    <script src="./lib/parsleyjs/parsley.js"></script>
    <script src="./js/shamcey.js"></script>
  </head>
  <style>
    html.compose-page,
    body.compose-page{
      height:100%;
      overflow:hidden !important;
    }
    body.compose-page .sh-mainpanel{
      height:100vh;
      max-height:100vh;
      overflow:hidden;
      display:flex;
      flex-direction:column;
    }
    body.compose-page .sh-pagebody{
      flex:1 1 auto;
      min-height:0;
      overflow:hidden;
      display:flex;
      align-items:center;
      justify-content:center;
      padding:24px !important;
    }
    body.compose-page .compose-card-wrap{
      width:min(100%, 640px);
      margin:0 auto;
    }
    body.compose-page .compose-card-wrap .card{
      margin:0;
    }
  </style>
  <body class="compose-page">
    <!-- header -->
    <?php $forceFeedRail = true; $skipHeaderThemeBootstrap = true; include __DIR__ . '/includes/header.php'; ?>
    <div class="sh-mainpanel">

      <div class="sh-pagebody">

      <?php if ($error): ?>
            <div class="p-3 mb-3 text-sm text-red-600 bg-red-50 rounded"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <div class="compose-card-wrap">
        <div class="card bd-primary">
          <div class="card-header bg-primary tx-white">Required Input Validation</div>
          <div class="card-body pd-sm-30">
            <p class="mg-b-20 mg-sm-b-30">This is a demo of a required field that must not leave empty.</p>

            <form method="post" autocomplete="off">
              <div class="wd-300">
                <div class="d-md-flex mg-b-30">
                  <div class="form-group mg-b-0">
                    <label>To: <span class="tx-danger">*</span></label>
                    <input type="text" name="to" class="form-control wd-200 wd-sm-250"
                    value="<?php echo htmlspecialchars($prefillTo, ENT_QUOTES, 'UTF-8'); ?>"
                           placeholder="Friend code only (ex: USR-AB12-CD34)" required>
                  </div><!-- form-group -->
                </div><!-- d-flex -->
                <p class="mg-b-20 mg-sm-b-30">Allowed: Friend code only.</p>
                <button type="submit" class="btn btn-success">Start Chat</button>
                <a class="btn btn-success" href="contacts.php" style="margin-left:8px;">View Friends</a>
              </div>
            </form>
          </div><!-- card-body -->
        </div><!-- card -->
        </div><!-- compose-card-wrap -->

      </div><!-- sh-pagebody -->
      <!-- <div class="sh-footer">
        <div>Copyright &copy; 2017. All Rights Reserved. Talentra</div>
        <div class="mg-t-10 mg-md-t-0">Designed by: <a href="http://themepixels.me">ThemePixels</a></div>
      </div> -->
      <!-- sh-footer -->
    </div><!-- sh-mainpanel -->

<!-- <?php include __DIR__ . '/includes/footer.php'; ?> -->
</body>
</html>
