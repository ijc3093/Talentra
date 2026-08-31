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
$action = trim((string)($_GET['action'] ?? ''));
$message = '';
$error = '';
$redirect = '';

function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$settings = [
  'allow_download_data' => 1,
  'allow_deactivate_account' => 1,
  'allow_delete_account' => 1,
  'allow_logout_all_devices' => 1,
];
try {
  $st = $dbh->prepare("SELECT allow_download_data, allow_deactivate_account, allow_delete_account, allow_logout_all_devices FROM user_profile_settings WHERE user_id = :uid LIMIT 1");
  $st->execute([':uid' => $meId]);
  $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
  foreach ($settings as $k => $v) {
    if (array_key_exists($k, $row) && $row[$k] !== null) { $settings[$k] = (int)$row[$k]; }
  }
} catch (Throwable $e) {}

if ($action === 'download') {
  if (!$settings['allow_download_data']) {
    $error = 'Download my data is turned off in Gear.';
  } else {
    $payload = ['exported_at' => date('c'), 'user_id' => $meId, 'profile' => null, 'about' => null, 'settings' => null, 'posts' => []];
    try {
      $st = $dbh->prepare("SELECT id, name, username, friend_code, email, gender, mobile, designation, status, created_at, last_seen FROM users WHERE id = :uid LIMIT 1");
      $st->execute([':uid' => $meId]);
      $payload['profile'] = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {}
    try {
      $st = $dbh->prepare("SELECT * FROM user_backgrounds WHERE user_id = :uid LIMIT 1");
      $st->execute([':uid' => $meId]);
      $payload['about'] = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {}
    try {
      $st = $dbh->prepare("SELECT * FROM user_profile_settings WHERE user_id = :uid LIMIT 1");
      $st->execute([':uid' => $meId]);
      $payload['settings'] = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {}
    try {
      $st = $dbh->prepare("SELECT id, title, description, body, created_at FROM public_posts WHERE user_id = :uid ORDER BY created_at DESC, id DESC LIMIT 500");
      $st->execute([':uid' => $meId]);
      $payload['posts'] = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {}

    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="talentra-data-' . $meId . '.json"');
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    try {
      require_once __DIR__ . '/includes/account_admin_events.php';
      account_admin_event_notify($dbh, $meId, 'export_data');
    } catch (Throwable $eNotify) {
    }
    exit;
  }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $postAction = trim((string)($_POST['action'] ?? ''));
  $confirm = trim((string)($_POST['confirm_text'] ?? ''));
  if ($postAction === 'deactivate') {
    if (!$settings['allow_deactivate_account']) {
      $error = 'Deactivate account is turned off in Gear.';
    } elseif (strcasecmp($confirm, 'DEACTIVATE') !== 0) {
      $error = 'Type DEACTIVATE to confirm.';
    } else {
      try {
        $st = $dbh->prepare("UPDATE users SET status = 0 WHERE id = :uid LIMIT 1");
        $st->execute([':uid' => $meId]);
        try {
          require_once __DIR__ . '/includes/account_admin_events.php';
          account_admin_event_notify($dbh, $meId, 'deactivate');
        } catch (Throwable $eNotify) {
        }
        session_unset(); session_destroy();
        $message = 'Account deactivated. You are signed out.';
        $redirect = 'index.php?deactivated=1';
        if (trim((string)($_POST['ajax'] ?? '')) !== 'deactivate') {
          header('Location: index.php?deactivated=1');
          exit;
        }
      } catch (Throwable $e) { $error = $e->getMessage(); }
    }
  } elseif ($postAction === 'delete') {
    if (!$settings['allow_delete_account']) {
      $error = 'Delete account is turned off in Gear.';
    } elseif (strcasecmp($confirm, 'DELETE') !== 0) {
      $error = 'Type DELETE to confirm.';
    } else {
      $error = 'Protected delete flow is not completed yet in this project schema. Keep using Deactivate account for now.';
      try {
        require_once __DIR__ . '/includes/account_admin_events.php';
        account_admin_event_notify($dbh, $meId, 'delete');
      } catch (Throwable $eNotify) {
      }
    }
  } elseif ($postAction === 'logout_all') {
    if (!$settings['allow_logout_all_devices']) {
      $error = 'Logout all devices is turned off in Gear.';
    } else {
      $affected = revokeAllUserSessions($meId, session_id(), $dbh);
      if ($affected === -1) {
        $error = 'Session table is not installed yet. Run sql_user_sessions.sql first, then try again.';
      } elseif ($affected > 0) {
        $message = 'Logged out ' . $affected . ' other device session' . ($affected === 1 ? '' : 's') . '. Your current device stays signed in.';
        try {
          require_once __DIR__ . '/includes/account_admin_events.php';
          account_admin_event_notify($dbh, $meId, 'remove_access', ['sessions' => $affected]);
        } catch (Throwable $eNotify) {
        }
      } else {
        $message = 'No other active device sessions were found right now.';
      }
    }
  } elseif ($postAction === 'reset_settings') {
    if (strcasecmp($confirm, 'RESET') !== 0) {
      $error = 'Type RESET to confirm.';
    } else {
      $defaults = [
        'profile_visibility' => 'public',
        'about_visibility' => 'friends',
        'gallery_visibility' => 'friends',
        'post_visibility' => 'friends',
        'story_visibility' => 'friends',
        'reel_visibility' => 'friends',
        'post_hide_from' => '[]',
        'story_hide_from' => '[]',
        'reel_hide_from' => '[]',
        'comment_permission' => 'friends',
        'friend_request_permission' => 'public',
        'message_permission' => 'friends',
        'timeline_visit_approval' => 1,
        'show_tags_tab' => 1,
        'show_about_tab' => 1,
        'show_saved_tab' => 0,
        'auto_show_timeline' => 1,
        'resurface_old_memories' => 1,
        'show_timeline_reactions' => 1,
        'show_timeline_comments' => 1,
        'archive_memory_enabled' => 0,
        'pin_memory_enabled' => 0,
        'email_notifications' => 1,
        'friend_request_notifications' => 1,
        'comment_notifications' => 1,
        'reaction_notifications' => 1,
        'share_notifications' => 1,
        'tagged_notifications' => 1,
        'saved_notifications' => 1,
        'birthday_notifications' => 1,
        'followed_notifications' => 1,
        'event_reminder_notifications' => 1,
        'memory_notifications' => 1,
        'blocked_users_enabled' => 1,
        'hidden_users_enabled' => 1,
        'mute_users_enabled' => 1,
        'report_history_enabled' => 1,
        'appearance_mode' => 'system',
        'theme_auto_enabled' => 1,
        'gallery_grid_size' => 'medium',
        'autoplay_videos' => 1,
        'sound_enabled' => 1,
        'app_language' => 'English',
        'date_format' => 'F j, Y',
        'theme_color' => 'indigo',
      ];
      try {
        $cols = [];
        $colSt = $dbh->query('SHOW COLUMNS FROM user_profile_settings');
        foreach ($colSt as $col) {
          $name = (string)($col['Field'] ?? '');
          if ($name !== '' && $name !== 'user_id' && array_key_exists($name, $defaults)) {
            $cols[] = $name;
          }
        }
        if ($cols === []) {
          $error = 'No resettable Gear columns were found.';
        } else {
          $sets = [];
          $params = [':uid' => $meId];
          foreach ($cols as $col) {
            $sets[] = '`' . str_replace('`', '', $col) . '` = :' . $col;
            $params[':' . $col] = $defaults[$col];
          }
          $sql = 'UPDATE user_profile_settings SET ' . implode(', ', $sets) . ' WHERE user_id = :uid LIMIT 1';
          $up = $dbh->prepare($sql);
          $up->execute($params);
          if ($up->rowCount() === 0) {
            $ins = $dbh->prepare('INSERT INTO user_profile_settings (user_id) VALUES (:uid)');
            $ins->execute([':uid' => $meId]);
            $up->execute($params);
          }
          $message = 'Account settings were reset to defaults. Posts, friends, and media were not changed.';
          try {
            require_once __DIR__ . '/includes/account_admin_events.php';
            account_admin_event_notify($dbh, $meId, 'reset_settings');
          } catch (Throwable $eNotify) {
          }
        }
      } catch (Throwable $e) {
        $error = $e->getMessage();
      }
    }
  }
}

$ajaxAction = trim((string)($_REQUEST['ajax'] ?? ''));
if (in_array($ajaxAction, ['logout_all', 'reset_settings', 'delete', 'deactivate'], true)) {
  header('Content-Type: application/json; charset=utf-8');
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Not allowed']);
    exit;
  }
  echo json_encode([
    'ok' => ($error === ''),
    'message' => $message,
    'error' => $error,
    'redirect' => $redirect,
  ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Account tools</title>
  <?php theme_prefs_print_head_bootstrap($dbh, $meId); ?>
  <link rel="stylesheet" href="css/shamcey.css">
  <style>
    body{background:var(--msb-palette-bg,#f6f7fb);color:var(--msb-palette-text,#0f172a)}.acc-wrap{max-width:980px;margin:24px auto;padding:0 16px}.card{background:var(--msb-palette-surface-2,#fff);border-radius:22px;box-shadow:0 12px 40px rgba(15,23,42,.08);padding:22px;margin-bottom:18px}.title{font-size:28px;font-weight:800}.sub{color:var(--msb-palette-text-muted,#667085);margin-top:6px}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px;margin-top:16px}.tool{border:1px solid var(--msb-palette-border,#e5e7eb);border-radius:18px;padding:16px}.tool strong{display:block;margin-bottom:6px}.btn{display:inline-flex;align-items:center;gap:8px;padding:11px 14px;border-radius:14px;background:#111827;color:#fff;text-decoration:none;font-weight:700;border:none;cursor:pointer}.btn.alt{background:#eef2ff;color:#3730a3}.btn.danger{background:#b91c1c}.form{display:grid;gap:12px;margin-top:14px}.input{width:100%;padding:12px 14px;border:1px solid var(--msb-palette-border,#d0d5dd);border-radius:14px;background:var(--msb-palette-bg,#fff);color:var(--msb-palette-text,#0f172a)}.msg{border-radius:14px;padding:14px;margin-top:14px}.ok{background:#ecfdf3;color:#166534;border:1px solid #bbf7d0}.err{background:#fef2f2;color:#991b1b;border:1px solid #fecaca}.note{background:#fff7ed;color:#9a3412;border:1px solid #fed7aa;border-radius:14px;padding:14px}.top-actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:16px}
  </style>
</head>
<body>
<div class="acc-wrap">
  <div class="card">
    <div class="title">Account tools</div>
    <div class="sub">Use the protected tools below without mixing them into your About details.</div>
    <div class="top-actions">
      <a class="btn alt" href="profile.php?tab=gear">Back to Gear</a>
      <a class="btn" href="account_tools.php?action=download">Download my data</a>
      <a class="btn alt" href="manage_devices.php">Manage devices</a>
      <a class="btn alt" href="logout.php">Logout now</a>
    </div>
    <?php if ($message !== ''): ?><div class="msg ok"><?php echo h($message); ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="msg err"><?php echo h($error); ?></div><?php endif; ?>
  </div>

  <div class="card">
    <div class="grid">
      <div class="tool"><strong>Download my data</strong>Exports your profile, About details, saved settings, and post summary into one JSON file.<br><br><a class="btn" href="account_tools.php?action=download">Download</a></div>
      <div class="tool"><strong>Deactivate account</strong>Sets <code>users.status = 0</code> and signs you out after confirmation.<br><br><a class="btn alt" href="account_tools.php?action=deactivate">Open</a></div>
      <div class="tool"><strong>Delete account</strong>Protected placeholder flow with a hard warning until your schema has a full irreversible delete plan.<br><br><a class="btn danger" href="account_tools.php?action=delete">Open</a></div>
      <div class="tool"><strong>Logout all devices</strong>Signs out every other browser session using the shared SQL session table while keeping this current device signed in.<br><br><a class="btn alt" href="account_tools.php?action=logout_all">Open</a></div>
      <div class="tool"><strong>Manage devices</strong>See current and recent device sessions, last active time, IP, browser, and revoke one device at a time.<br><br><a class="btn alt" href="manage_devices.php">Open</a></div>
      <div class="tool"><strong>Reset account settings</strong>Restores Gear privacy, notifications, and appearance defaults. Does not delete posts or friends.<br><br><a class="btn alt" href="account_tools.php?action=reset_settings">Open</a></div>
    </div>
  </div>

  <?php if ($action === 'deactivate'): ?>
    <div class="card">
      <div class="title" style="font-size:22px">Deactivate account</div>
      <div class="note">Type <b>DEACTIVATE</b> below. This pauses the account by setting your status to 0, then signs out the current session.</div>
      <form class="form" method="post">
        <input type="hidden" name="action" value="deactivate">
        <input class="input" type="text" name="confirm_text" placeholder="Type DEACTIVATE">
        <button class="btn" type="submit">Deactivate now</button>
      </form>
    </div>
  <?php elseif ($action === 'delete'): ?>
    <div class="card">
      <div class="title" style="font-size:22px">Delete account</div>
      <div class="note">This project does not yet have a complete irreversible delete workflow across posts, comments, files, friends, and timeline history. Type <b>DELETE</b> if you want to reach the protected placeholder, but the page will still stop before destructive deletion.</div>
      <form class="form" method="post">
        <input type="hidden" name="action" value="delete">
        <input class="input" type="text" name="confirm_text" placeholder="Type DELETE">
        <button class="btn danger" type="submit">Continue</button>
      </form>
    </div>
  <?php elseif ($action === 'logout_all'): ?>
    <div class="card">
      <div class="title" style="font-size:22px">Logout all devices</div>
      <div class="note">This tool now uses the shared <code>user_sessions</code> SQL table. When you continue, every other active browser session for this account is revoked immediately, while this current device stays signed in.</div>
      <form class="form" method="post"><input type="hidden" name="action" value="logout_all"><button class="btn" type="submit">Logout all other devices now</button></form><div class="top-actions"><a class="btn alt" href="logout.php">Logout this device now</a></div>
    </div>
  <?php elseif ($action === 'reset_settings'): ?>
    <div class="card">
      <div class="title" style="font-size:22px">Reset account settings</div>
      <div class="note">Type <b>RESET</b> below. This restores Gear privacy, notifications, and appearance to defaults. Posts, friends, and media are not deleted.</div>
      <form class="form" method="post">
        <input type="hidden" name="action" value="reset_settings">
        <input class="input" type="text" name="confirm_text" placeholder="Type RESET">
        <button class="btn" type="submit">Reset settings now</button>
      </form>
    </div>
  <?php endif; ?>
</div>
</body>
</html>
