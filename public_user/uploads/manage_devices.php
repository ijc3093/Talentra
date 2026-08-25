<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/session_user.php';
requireUserLogin();
require_once __DIR__ . '/controller.php';
require_once __DIR__ . '/includes/profile_access.php';
require_once __DIR__ . '/includes/theme_prefs.php';
$controller = new Controller();
$dbh = $controller->pdo();
$meId = theme_prefs_viewer_user_id();
profile_require_edit_access($dbh, $meId, false);
$currentSid = (string)session_id();
$message = '';
$error = '';

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function shortUa(string $ua): string {
  $ua = trim($ua);
  if ($ua === '') return 'Unknown browser/device';
  return mb_strlen($ua) > 120 ? mb_substr($ua, 0, 120) . '…' : $ua;
}

$allowManage = 1;
try {
  $st = $dbh->prepare("SELECT allow_logout_all_devices FROM user_profile_settings WHERE user_id = :uid LIMIT 1");
  $st->execute([':uid' => $meId]);
  $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
  if (array_key_exists('allow_logout_all_devices', $row) && $row['allow_logout_all_devices'] !== null) {
    $allowManage = (int)$row['allow_logout_all_devices'];
  }
} catch (Throwable $e) {}

if (!$allowManage) {
  $error = 'Manage devices is hidden in Gear because Allow logout all devices is turned off.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error === '') {
  $action = trim((string)($_POST['action'] ?? ''));
  if ($action === 'revoke_one') {
    $rowId = (int)($_POST['session_id'] ?? 0);
    if ($rowId <= 0) {
      $error = 'Invalid device session.';
    } else {
      $ok = revokeOneUserSession($meId, $rowId, $currentSid, $dbh);
      $message = $ok ? 'The selected device session was signed out.' : 'That session could not be revoked. It may already be inactive or it may be this current device.';
    }
  } elseif ($action === 'revoke_others') {
    $affected = revokeAllUserSessions($meId, $currentSid, $dbh);
    if ($affected === -1) {
      $error = 'Session table is not installed yet. Run sql_user_sessions.sql first.';
    } elseif ($affected > 0) {
      $message = 'Logged out ' . $affected . ' other device session' . ($affected === 1 ? '' : 's') . '.';
    } else {
      $message = 'No other active device sessions were found.';
    }
  }
}

$hasSessionTable = userSessionTableExists($dbh);
$sessions = [];
if ($hasSessionTable) {
  try {
    $st = $dbh->prepare("SELECT id, php_session_id, ip_address, user_agent, created_at, last_seen_at, revoked_at FROM user_sessions WHERE user_id = :uid ORDER BY (revoked_at IS NULL) DESC, last_seen_at DESC, created_at DESC LIMIT 50");
    $st->execute([':uid' => $meId]);
    $sessions = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  } catch (Throwable $e) {
    $error = $error !== '' ? $error : $e->getMessage();
  }
}

$activeCount = 0;
foreach ($sessions as $s) {
  if (empty($s['revoked_at'])) $activeCount++;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Manage devices</title>
  <?php theme_prefs_print_head_bootstrap($dbh, $meId); ?>
  <link rel="stylesheet" href="css/shamcey.css">
  <style>
    body{background:var(--msb-palette-bg,#f6f7fb);color:var(--msb-palette-text,#0f172a)}.wrap{max-width:1080px;margin:24px auto;padding:0 16px}.card{background:var(--msb-palette-surface-2,#fff);border-radius:22px;box-shadow:0 12px 40px rgba(15,23,42,.08);padding:22px;margin-bottom:18px}.title{font-size:28px;font-weight:800}.sub{color:var(--msb-palette-text-muted,#667085);margin-top:6px}.top{display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:center}.btn{display:inline-flex;align-items:center;gap:8px;padding:11px 14px;border-radius:14px;background:#111827;color:#fff;text-decoration:none;font-weight:700;border:none;cursor:pointer}.btn.alt{background:#eef2ff;color:#3730a3}.btn.danger{background:#b91c1c}.msg{border-radius:14px;padding:14px;margin-top:14px}.ok{background:#ecfdf3;color:#166534;border:1px solid #bbf7d0}.err{background:#fef2f2;color:#991b1b;border:1px solid #fecaca}.note{background:#fff7ed;color:#9a3412;border:1px solid #fed7aa;border-radius:14px;padding:14px}.stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px;margin-top:16px}.stat{border:1px solid var(--msb-palette-border,#e5e7eb);border-radius:18px;padding:16px}.stat strong{display:block;font-size:24px;margin-top:6px}.list{display:grid;gap:14px}.device{border:1px solid var(--msb-palette-border,#e5e7eb);border-radius:18px;padding:16px}.row{display:flex;justify-content:space-between;gap:14px;align-items:flex-start;flex-wrap:wrap}.meta{display:grid;gap:6px;color:var(--msb-palette-text-muted,#475467);font-size:14px;margin-top:10px}.badge{display:inline-flex;padding:6px 10px;border-radius:999px;font-weight:700;font-size:12px}.active{background:#ecfdf3;color:#166534}.revoked{background:#f3f4f6;color:#374151}.current{background:#eef2ff;color:#3730a3}.actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:16px}.ua{font-weight:700;color:var(--msb-palette-text,#111827)}.small{font-size:13px;color:var(--msb-palette-text-muted,#667085)}@media (max-width:640px){.title{font-size:24px}}
  </style>
</head>
<body>
<div class="wrap">
  <div class="card">
    <div class="top">
      <div>
        <div class="title">Manage devices</div>
        <div class="sub">Review current and recent device sessions, then sign out one device or every other device safely.</div>
      </div>
      <div class="actions">
        <a class="btn alt" href="profile.php?tab=gear">Back to Gear</a>
        <a class="btn alt" href="security_tools.php#login-security">Security</a>
        <a class="btn alt" href="account_tools.php?action=logout_all">Logout all devices</a>
      </div>
    </div>
    <?php if ($message !== ''): ?><div class="msg ok"><?php echo h($message); ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="msg err"><?php echo h($error); ?></div><?php endif; ?>
    <?php if (!$hasSessionTable): ?>
      <div class="note">The <code>user_sessions</code> table was not found. Run <code>sql_user_sessions.sql</code> first, then reload this page.</div>
    <?php else: ?>
      <div class="stats">
        <div class="stat"><span class="small">Current device</span><strong>1</strong><div class="small">This browser session stays signed in unless you logout here.</div></div>
        <div class="stat"><span class="small">Active sessions</span><strong><?php echo (int)$activeCount; ?></strong><div class="small">Sessions with no revoke time.</div></div>
        <div class="stat"><span class="small">Tracked sessions</span><strong><?php echo count($sessions); ?></strong><div class="small">Recent active and revoked sessions in SQL.</div></div>
      </div>
      <form method="post" class="actions">
        <input type="hidden" name="action" value="revoke_others">
        <button class="btn" type="submit">Logout all other devices</button>
      </form>
    <?php endif; ?>
  </div>

  <?php if ($hasSessionTable): ?>
  <div class="card">
    <div class="title" style="font-size:22px">Your device sessions</div>
    <div class="list" style="margin-top:16px;">
      <?php if (!$sessions): ?>
        <div class="note">No device sessions were found yet.</div>
      <?php endif; ?>
      <?php foreach ($sessions as $row): ?>
        <?php
          $isCurrent = ((string)($row['php_session_id'] ?? '') === $currentSid);
          $isActive = empty($row['revoked_at']);
        ?>
        <div class="device">
          <div class="row">
            <div style="flex:1 1 560px;min-width:260px;">
              <div class="ua"><?php echo h(shortUa((string)($row['user_agent'] ?? ''))); ?></div>
              <div class="actions" style="margin-top:10px;">
                <span class="badge <?php echo $isActive ? 'active' : 'revoked'; ?>"><?php echo $isActive ? 'Active' : 'Revoked'; ?></span>
                <?php if ($isCurrent): ?><span class="badge current">Current device</span><?php endif; ?>
              </div>
              <div class="meta">
                <div><strong>IP:</strong> <?php echo h((string)($row['ip_address'] ?? '') ?: 'Unknown'); ?></div>
                <div><strong>Created:</strong> <?php echo h((string)($row['created_at'] ?? '')); ?></div>
                <div><strong>Last active:</strong> <?php echo h((string)($row['last_seen_at'] ?? '')); ?></div>
                <div><strong>Revoked:</strong> <?php echo h((string)($row['revoked_at'] ?? '') ?: 'Not revoked'); ?></div>
              </div>
            </div>
            <div style="min-width:200px;">
              <?php if ($isCurrent): ?>
                <div class="note">This is the device you are using right now.</div>
              <?php elseif ($isActive): ?>
                <form method="post">
                  <input type="hidden" name="action" value="revoke_one">
                  <input type="hidden" name="session_id" value="<?php echo (int)$row['id']; ?>">
                  <button class="btn danger" type="submit">Logout this device</button>
                </form>
              <?php else: ?>
                <div class="small">This session was already signed out.</div>
              <?php endif; ?>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>
</div>
</body>
</html>
