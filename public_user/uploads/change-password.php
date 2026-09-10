<?php
require_once __DIR__ . '/includes/session_user.php';
requireUserLogin();

error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once __DIR__ . '/controller.php';
require_once __DIR__ . '/includes/theme_prefs.php';

$controller = new Controller();
$dbh = $controller->pdo();
$meId = theme_prefs_viewer_user_id();

$msg = '';
$error = '';

$userEmail = $_SESSION['user_login'] ?? '';
if ($userEmail === '') {
    clearUserSession();
    header('Location: index.php');
    exit;
}

if (!function_exists('h')) {
    function h($v): string
    {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }
}

function verifyPassword(string $plain, string $dbHash): bool
{
    if (password_get_info($dbHash)['algo'] !== 0) {
        return password_verify($plain, $dbHash);
    }
    return md5($plain) === $dbHash;
}

if (isset($_POST['submit'])) {
    $currentRaw = (string)($_POST['password'] ?? '');
    $newRaw = (string)($_POST['newpassword'] ?? '');
    $confirmRaw = (string)($_POST['confirmpassword'] ?? '');

    if ($newRaw !== $confirmRaw) {
        $error = 'New password and confirm password do not match.';
    } elseif (strlen($newRaw) < 6) {
        $error = 'Password must be at least 6 characters.';
    } else {
        $stmt = $dbh->prepare('SELECT id, password FROM users WHERE email = :email LIMIT 1');
        $stmt->execute([':email' => $userEmail]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            clearUserSession();
            header('Location: index.php');
            exit;
        }

        $userId = (int)$row['id'];
        $dbHash = (string)$row['password'];

        if (!verifyPassword($currentRaw, $dbHash)) {
            $error = 'Your current password is not valid.';
        } else {
            $newHash = password_hash($newRaw, PASSWORD_DEFAULT);
            $upd = $dbh->prepare('UPDATE users SET password = :pass WHERE id = :id');
            $upd->execute([
                ':pass' => $newHash,
                ':id' => $userId,
            ]);
            $msg = 'Your password was changed successfully.';
        }
    }
}

$returnUrl = trim((string)($_GET['return'] ?? ''));
if ($returnUrl === '' || strpos($returnUrl, '://') !== false || str_starts_with($returnUrl, '//')) {
    $returnUrl = 'profile.php?tab=gear';
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Change Password</title>
    <?php theme_prefs_print_head_bootstrap($dbh, $meId); ?>
    <link href="./lib/font-awesome/css/font-awesome.css" rel="stylesheet">
    <link href="./lib/Ionicons/css/ionicons.css" rel="stylesheet">
    <link rel="stylesheet" href="./css/shamcey.css">
    <style>
      body.change-password-page{
        background:var(--msb-palette-bg,#f6f7fb);
        color:var(--msb-palette-text,#0f172a);
      }
      body.change-password-page .sh-pagebody{
        padding:24px 16px 40px;
      }
      .pwd-wrap{
        max-width:560px;
        margin:0 auto;
      }
      .pwd-card{
        background:var(--msb-palette-surface-2,#fff);
        border:1px solid var(--msb-palette-border,rgba(15,23,42,.08));
        border-radius:22px;
        box-shadow:0 12px 40px rgba(15,23,42,.08);
        padding:24px;
      }
      .pwd-top{
        display:flex;
        align-items:flex-start;
        justify-content:space-between;
        gap:14px;
        flex-wrap:wrap;
        margin-bottom:20px;
      }
      .pwd-icon{
        width:52px;
        height:52px;
        border-radius:16px;
        display:flex;
        align-items:center;
        justify-content:center;
        background:var(--msb-palette-hover-bg,#eef2ff);
        color:var(--msb-palette-link,#4338ca);
        flex:0 0 52px;
      }
      .pwd-icon i{font-size:24px;}
      .pwd-title{
        font-size:28px;
        font-weight:800;
        line-height:1.15;
        margin:0 0 6px;
        color:var(--msb-palette-text,#0f172a);
      }
      .pwd-sub{
        margin:0;
        font-size:14px;
        line-height:1.55;
        color:var(--msb-palette-text-muted,#667085);
        font-weight:600;
      }
      .pwd-back{
        display:inline-flex;
        align-items:center;
        gap:8px;
        padding:10px 14px;
        border-radius:14px;
        background:var(--msb-palette-hover-bg,#eef2ff);
        color:var(--msb-palette-link,#3730a3);
        text-decoration:none;
        font-size:13px;
        font-weight:800;
        border:1px solid var(--msb-palette-border-strong,rgba(79,70,229,.18));
        white-space:nowrap;
      }
      .pwd-back:hover,.pwd-back:focus{
        text-decoration:none;
        background:var(--msb-palette-nav-hover,#e0e7ff);
        color:var(--msb-palette-link,#3730a3);
      }
      .pwd-alert{
        border-radius:14px;
        padding:12px 14px;
        margin:0 0 18px;
        font-size:14px;
        font-weight:700;
        line-height:1.45;
      }
      .pwd-alert.error{
        background:#fef2f2;
        color:#991b1b;
        border:1px solid #fecaca;
      }
      .pwd-alert.success{
        background:#ecfdf3;
        color:#166534;
        border:1px solid #bbf7d0;
      }
      .pwd-form{display:grid;gap:16px;}
      .pwd-field{display:grid;gap:8px;}
      .pwd-label{
        font-size:13px;
        font-weight:800;
        color:var(--msb-palette-text,#0f172a);
      }
      .pwd-input{
        width:100%;
        height:46px;
        border-radius:12px;
        border:1px solid var(--msb-palette-border-strong,rgba(15,23,42,.14));
        background:var(--msb-palette-bg,#fff);
        color:var(--msb-palette-text,#0f172a);
        font-size:14px;
        font-weight:600;
        padding:0 14px;
        outline:none;
        box-sizing:border-box;
      }
      .pwd-input:focus{
        border-color:#4f46e5;
        box-shadow:0 0 0 4px rgba(79,70,229,.12);
      }
      .pwd-hint{
        font-size:12px;
        color:var(--msb-palette-text-muted,#667085);
        font-weight:600;
        line-height:1.45;
      }
      .pwd-actions{
        display:flex;
        gap:10px;
        flex-wrap:wrap;
        margin-top:4px;
      }
      .pwd-btn{
        display:inline-flex;
        align-items:center;
        justify-content:center;
        gap:8px;
        min-height:44px;
        padding:0 18px;
        border:0;
        border-radius:14px;
        background:#111827;
        color:#fff;
        font-size:14px;
        font-weight:800;
        cursor:pointer;
      }
      .pwd-btn:hover,.pwd-btn:focus{
        background:#0f172a;
        color:#fff;
      }
      .pwd-note{
        margin-top:18px;
        padding:14px 16px;
        border-radius:14px;
        background:var(--msb-palette-surface-2,#f8faff);
        border:1px dashed var(--msb-palette-border-strong,rgba(79,70,229,.24));
        color:var(--msb-palette-text-muted,#667085);
        font-size:13px;
        font-weight:600;
        line-height:1.5;
      }
      @media (max-width:640px){
        .pwd-title{font-size:24px;}
        .pwd-card{padding:18px;}
        .pwd-top{align-items:stretch;}
        .pwd-back{width:100%;justify-content:center;}
      }
    </style>
    <script>
    function validPasswordForm(){
      if (document.chngpwd.newpassword.value !== document.chngpwd.confirmpassword.value) {
        alert('New password and confirm password do not match.');
        document.chngpwd.confirmpassword.focus();
        return false;
      }
      return true;
    }
    </script>
</head>
<body class="change-password-page">
<?php $forceFeedRail = true; $skipHeaderThemeBootstrap = true; include __DIR__ . '/includes/header.php'; ?>

<div class="sh-mainpanel">
  <div class="sh-pagebody">
    <div class="pwd-wrap">
      <div class="pwd-card">
        <div class="pwd-top">
          <div style="display:flex;gap:14px;align-items:flex-start;min-width:0;flex:1 1 auto;">
            <div class="pwd-icon"><i class="icon ion-key"></i></div>
            <div>
              <h1 class="pwd-title">Change Password</h1>
              <p class="pwd-sub">Update your account password. Use at least 6 characters and keep it private.</p>
            </div>
          </div>
          <a class="pwd-back" href="<?php echo h($returnUrl); ?>"><i class="icon ion-arrow-left-c"></i> Back</a>
        </div>

        <?php if ($error !== ''): ?>
          <div class="pwd-alert error"><?php echo h($error); ?></div>
        <?php elseif ($msg !== ''): ?>
          <div class="pwd-alert success"><?php echo h($msg); ?></div>
        <?php endif; ?>

        <form method="post" name="chngpwd" class="pwd-form" onsubmit="return validPasswordForm();">
          <div class="pwd-field">
            <label class="pwd-label" for="password">Current password</label>
            <input type="password" class="pwd-input" name="password" id="password" autocomplete="current-password" required>
          </div>

          <div class="pwd-field">
            <label class="pwd-label" for="newpassword">New password</label>
            <input type="password" class="pwd-input" name="newpassword" id="newpassword" autocomplete="new-password" minlength="6" required>
            <div class="pwd-hint">Use at least 6 characters.</div>
          </div>

          <div class="pwd-field">
            <label class="pwd-label" for="confirmpassword">Confirm new password</label>
            <input type="password" class="pwd-input" name="confirmpassword" id="confirmpassword" autocomplete="new-password" minlength="6" required>
          </div>

          <div class="pwd-actions">
            <button class="pwd-btn" name="submit" type="submit"><i class="icon ion-checkmark"></i> Save changes</button>
          </div>
        </form>

        <div class="pwd-note">
          After saving, your new password is used the next time you sign in on any device.
        </div>
      </div>
    </div>
  </div>
</div>

<script>
(function(){
  var ok = document.querySelector('.pwd-alert.success');
  if (!ok) return;
  window.setTimeout(function(){
    ok.style.transition = 'opacity .35s ease';
    ok.style.opacity = '0';
  }, 3200);
})();
</script>
</body>
</html>
