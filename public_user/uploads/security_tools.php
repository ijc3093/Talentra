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

function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$settings = [
  'blocked_users_enabled' => 1,
  'hidden_users_enabled' => 1,
  'mute_users_enabled' => 1,
  'report_history_enabled' => 1,
];
$counts = ['blocked' => 0, 'hidden' => 0, 'muted' => 0, 'reports' => 0];

try {
  $st = $dbh->prepare("SELECT blocked_users_enabled, hidden_users_enabled, mute_users_enabled, report_history_enabled FROM user_profile_settings WHERE user_id = :uid LIMIT 1");
  $st->execute([':uid' => $meId]);
  $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
  foreach ($settings as $k => $v) {
    if (array_key_exists($k, $row) && $row[$k] !== null) { $settings[$k] = (int)$row[$k]; }
  }
} catch (Throwable $e) {}

$possibleTables = [
  'public_user_blocks' => ['key' => 'blocked', 'userCol' => 'user_id'],
  'user_blocks' => ['key' => 'blocked', 'userCol' => 'user_id'],
  'public_hidden_users' => ['key' => 'hidden', 'userCol' => 'user_id'],
  'user_hidden_users' => ['key' => 'hidden', 'userCol' => 'user_id'],
  'public_muted_users' => ['key' => 'muted', 'userCol' => 'user_id'],
  'user_muted_users' => ['key' => 'muted', 'userCol' => 'user_id'],
  'public_user_reports' => ['key' => 'reports', 'userCol' => 'reporter_id'],
  'user_reports' => ['key' => 'reports', 'userCol' => 'reporter_id'],
];
foreach ($possibleTables as $table => $meta) {
  try {
    $chk = $dbh->query("SHOW TABLES LIKE " . $dbh->quote($table));
    if ($chk && $chk->fetchColumn()) {
      $sql = "SELECT COUNT(*) FROM `{$table}` WHERE `{$meta['userCol']}` = :uid";
      $st = $dbh->prepare($sql);
      $st->execute([':uid' => $meId]);
      $counts[$meta['key']] = max($counts[$meta['key']], (int)$st->fetchColumn());
    }
  } catch (Throwable $e) {}
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Security Center</title>
  <?php theme_prefs_print_head_bootstrap($dbh, $meId); ?>
  <link rel="stylesheet" href="css/shamcey.css">
  <style>
    body{background:var(--msb-palette-bg,#f6f7fb);color:var(--msb-palette-text,#0f172a)}.sec-wrap{max-width:980px;margin:24px auto;padding:0 16px}.sec-card{background:var(--msb-palette-surface-2,#fff);border-radius:22px;box-shadow:0 12px 40px rgba(15,23,42,.08);padding:22px;margin-bottom:18px}.sec-head{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}.sec-title{font-size:28px;font-weight:800}.sec-sub{color:var(--msb-palette-text-muted,#667085)}.sec-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px;margin-top:16px}.pill{display:inline-flex;padding:6px 10px;border-radius:999px;background:#eef2ff;color:#4338ca;font-weight:700;font-size:12px}.stat{border:1px solid var(--msb-palette-border,#e5e7eb);border-radius:18px;padding:16px}.stat b{display:block;font-size:24px;margin-top:6px}.list{display:grid;gap:12px;margin-top:14px}.row{display:flex;justify-content:space-between;gap:12px;align-items:center;border:1px solid var(--msb-palette-border,#eceff4);border-radius:16px;padding:14px 16px}.row small{display:block;color:var(--msb-palette-text-muted,#667085)}.btn{display:inline-flex;align-items:center;gap:8px;padding:11px 14px;border-radius:14px;background:#111827;color:#fff;text-decoration:none;font-weight:700}.btn.alt{background:#eef2ff;color:#3730a3}.warn{background:#fff7ed;border:1px solid #fed7aa;color:#9a3412;border-radius:14px;padding:14px}.back{margin-top:8px;display:inline-block}
  </style>
</head>
<body>
<div class="sec-wrap">
  <div class="sec-card">
    <div class="sec-head">
      <div>
        <div class="sec-title">Security and safety</div>
        <div class="sec-sub">One place to review your current safety toggles and open the strongest account-protection actions.</div>
      </div>
      <a class="btn alt" href="profile.php?tab=gear">Back to Gear</a>
    </div>
    <div class="sec-grid">
      <div class="stat"><span class="pill">Blocked users</span><b><?php echo (int)$counts['blocked']; ?></b><small><?php echo $settings['blocked_users_enabled'] ? 'System enabled' : 'System hidden in Gear'; ?></small></div>
      <div class="stat"><span class="pill">Hidden users</span><b><?php echo (int)$counts['hidden']; ?></b><small><?php echo $settings['hidden_users_enabled'] ? 'System enabled' : 'System hidden in Gear'; ?></small></div>
      <div class="stat"><span class="pill">Muted users</span><b><?php echo (int)$counts['muted']; ?></b><small><?php echo $settings['mute_users_enabled'] ? 'System enabled' : 'System hidden in Gear'; ?></small></div>
      <div class="stat"><span class="pill">Report history</span><b><?php echo (int)$counts['reports']; ?></b><small><?php echo $settings['report_history_enabled'] ? 'System enabled' : 'System hidden in Gear'; ?></small></div>
    </div>
  </div>

  <div class="sec-card" id="login-security">
    <div class="sec-title" style="font-size:22px">Login / security section</div>
    <div class="list">
      <div class="row"><div><strong>Change password</strong><small>Open your existing password page to protect the account quickly.</small></div><a class="btn" href="change-password.php">Open</a></div>
      <div class="row"><div><strong>Logout now</strong><small>Signs out of this current browser session immediately.</small></div><a class="btn" href="logout.php">Logout</a></div>
      <div class="row"><div><strong>Logout all devices</strong><small>Your project now uses the shared SQL session table, so you can manage active devices and revoke other sessions safely.</small></div><a class="btn alt" href="account_tools.php?action=logout_all">Open tool</a></div>
    </div>
  </div>

  <div class="sec-card">
    <div class="sec-title" style="font-size:22px">Safety notes</div>
    <div class="warn">Your Gear tab now controls whether blocked-user, hidden-user, mute-user, and report-history tools stay visible. When you later add full SQL tables for those lists, this page will automatically start showing real counts from them.</div>
    <a class="back" href="profile.php?tab=gear">← Back to Gear</a>
  </div>
</div>
</body>
</html>
