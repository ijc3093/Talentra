<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/session_user.php';
requireUserLogin();
require_once __DIR__ . '/controller.php';
require_once __DIR__ . '/includes/theme_prefs.php';
require_once __DIR__ . '/includes/account_switch.php';
require_once __DIR__ . '/includes/switch_accounts_ui.php';

$controller = new Controller();
$dbh = $controller->pdo();
$meId = (int)($_SESSION['user_id'] ?? 0);
if ($meId <= 0) {
  header('Location: index.php?session=reset');
  exit;
}

$staffBlocked = account_switch_is_staff_session();
$accounts = ($staffBlocked || $meId <= 0) ? [] : account_switch_list($dbh, $meId);
?>
<!DOCTYPE html>
<html <?= function_exists('app_html_lang_attrs') ? app_html_lang_attrs() : 'lang="en"' ?>>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Switch accounts</title>
  <?php theme_prefs_print_head_bootstrap($dbh, $meId); ?>
</head>
<body class="sa-switch-page" style="margin:0;background:var(--msb-palette-bg,#f6f7fb);color:var(--msb-palette-text,#0b1220);">
<?php msb_render_switch_accounts_picker($accounts, $staffBlocked, true); ?>
</body>
</html>
