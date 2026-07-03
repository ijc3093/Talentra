<?php
// /Business_only3/organization/settings.php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors','1');

require_once __DIR__ . '/includes/session_org.php';
require_once __DIR__ . '/includes/org_context.php';

if (!isOrgManager()) {
    header("Location: dashboard.php");
    exit;
}

if (!function_exists('h')) {
    function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}

$err = '';
$ok  = '';

$orgId = (int)orgActiveOrgId();

// Load current json into arrays
$theme = [];
$a11y  = [];
if (!empty($ORG_SETTINGS['theme_json'])) $theme = json_decode((string)$ORG_SETTINGS['theme_json'], true) ?: [];
if (!empty($ORG_SETTINGS['a11y_json']))  $a11y  = json_decode((string)$ORG_SETTINGS['a11y_json'], true) ?: [];

// ---- DEFAULTS ----
$DEF_BG          = '#f5f7fb';
$DEF_ACCENT      = '#0861bc';
$DEF_HEADER      = '#0861bc';
$DEF_LEFTBAR     = '#111a24';
$DEF_PANEL       = '#ffffff';
$DEF_FOOTER      = '#ffffff';
$DEF_PAGETITLE   = '#ffffff';   // ✅ white
$DEF_TEXT        = '#111827';
$DEF_INPUT_BG    = '#ffffff';
$DEF_INPUT_TEXT  = '#111827';
$DEF_INPUT_BORDER= '#d1d5db';
$DEF_MEMBERS_BG  = '#ffffff';
$DEF_MSGBOX_BG   = '#ffffff';

$curBg          = (string)($theme['bg'] ?? $DEF_BG);
$curAccent      = (string)($theme['accent'] ?? $DEF_ACCENT);
$curHeaderBg    = (string)($theme['headerBg'] ?? $DEF_HEADER);
$curLeftbarBg   = (string)($theme['leftbarBg'] ?? $DEF_LEFTBAR);
$curPanelBg     = (string)($theme['panelBg'] ?? $DEF_PANEL);
$curFooterBg    = (string)($theme['footerBg'] ?? $DEF_FOOTER);
$curPagetitleBg = (string)($theme['pagetitleBg'] ?? $DEF_PAGETITLE);

$curTextColor   = (string)($theme['textColor'] ?? $DEF_TEXT);
$curInputBg     = (string)($theme['inputBg'] ?? $DEF_INPUT_BG);
$curInputText   = (string)($theme['inputText'] ?? $DEF_INPUT_TEXT);
$curInputBorder = (string)($theme['inputBorder'] ?? $DEF_INPUT_BORDER);

$curMembersBg   = (string)($theme['membersBg'] ?? $DEF_MEMBERS_BG);
$curMsgBoxBg    = (string)($theme['msgBoxBg']  ?? $DEF_MSGBOX_BG);

// Accessibility
$curFont = (string)($a11y['fontSize'] ?? '16px');

// Logo settings
$curLogoType  = (string)($ORG_SETTINGS['logo_type'] ?? 'text');
$curLogoText  = (string)($ORG_SETTINGS['logo_text'] ?? '');
$curLogoPath  = (string)($ORG_SETTINGS['logo_image_path'] ?? '');
$curLogoIcon  = (string)($ORG_SETTINGS['logo_icon'] ?? 'ion-ios-briefcase');
$curLogoColor = (string)($ORG_SETTINGS['logo_color'] ?? $DEF_ACCENT);

// ---- POST ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // ✅ Reset to default theme (keeps logo settings)
    if (isset($_POST['reset_defaults'])) {
        try {
            $stR = $dbh->prepare("
                UPDATE org_settings
                SET theme_json = NULL,
                    a11y_json = NULL,
                    updated_at = NOW()
                WHERE org_id = :org
                LIMIT 1
            ");
            $stR->execute([':org' => $orgId]);

            $ok = "Reset to default. Refresh any org page to see changes.";

            // reset UI values to defaults
            $theme = [];
            $a11y  = [];
            $curBg          = $DEF_BG;
            $curAccent      = $DEF_ACCENT;
            $curHeaderBg    = $DEF_HEADER;
            $curLeftbarBg   = $DEF_LEFTBAR;
            $curPanelBg     = $DEF_PANEL;
            $curFooterBg    = $DEF_FOOTER;
            $curPagetitleBg = '#ffffff';

            $curTextColor   = $DEF_TEXT;
            $curInputBg     = $DEF_INPUT_BG;
            $curInputText   = $DEF_INPUT_TEXT;
            $curInputBorder = $DEF_INPUT_BORDER;

            $curMembersBg   = $DEF_MEMBERS_BG;
            $curMsgBoxBg    = $DEF_MSGBOX_BG;

            $curFont = '16px';
        } catch (Throwable $e) {
            $err = "Reset failed: " . $e->getMessage();
        }
    } else {

        $isHex = static function(string $c): bool {
            return (bool)preg_match('/^#[0-9a-fA-F]{6}$/', $c);
        };

        $doReset = !empty($_POST['reset']);

        // ---- Logo Icon ----
        $logoIcon = trim((string)($_POST['logo_icon'] ?? $curLogoIcon));
        $allowedIcons = [
            'ion-ios-home',
            'ion-ios-people',
            'ion-ios-briefcase',
            'ion-ios-heart',
            'ion-ios-book',
            'ion-ios-star',
        ];
        if (!in_array($logoIcon, $allowedIcons, true)) $logoIcon = 'ion-ios-briefcase';

        $logoColor = trim((string)($_POST['logo_color'] ?? $curLogoColor));
        if (!$isHex($logoColor)) $logoColor = $DEF_ACCENT;

        $logoType = (string)($_POST['logo_type'] ?? $curLogoType);
        if ($logoType !== 'text' && $logoType !== 'image') $logoType = 'text';

        $logoText = trim((string)($_POST['logo_text'] ?? $curLogoText));
        if ($logoText !== '' && strlen($logoText) > 40) $logoText = substr($logoText, 0, 40);

        // ---- Colors ----
        if ($doReset) {
            $bg          = $DEF_BG;
            $accent      = $DEF_ACCENT;
            $headerBg    = $DEF_BG;
            $leftbarBg   = $DEF_BG;
            $panelBg     = $DEF_PANEL;
            $footerBg    = $DEF_BG;
            $textColor   = $DEF_TEXT;
            $inputBg     = $DEF_INPUT_BG;
            $inputText   = $DEF_INPUT_TEXT;
            $inputBorder = $DEF_INPUT_BORDER;
            $membersBg   = $DEF_MEMBERS_BG;
            $msgBoxBg    = $DEF_MSGBOX_BG;
            $pagetitleBg = $DEF_PAGETITLE;
        } else {
            $bg          = trim((string)($_POST['bg'] ?? $curBg));
            $accent      = trim((string)($_POST['accent'] ?? $curAccent));
            $headerBg    = trim((string)($_POST['header_bg'] ?? $curHeaderBg));
            $leftbarBg   = trim((string)($_POST['leftbar_bg'] ?? $curLeftbarBg));
            $panelBg     = trim((string)($_POST['panel_bg'] ?? $curPanelBg));
            $footerBg    = trim((string)($_POST['footer_bg'] ?? $curFooterBg));
            $textColor   = trim((string)($_POST['text_color'] ?? $curTextColor));
            $inputBg     = trim((string)($_POST['input_bg'] ?? $curInputBg));
            $inputText   = trim((string)($_POST['input_text'] ?? $curInputText));
            $inputBorder = trim((string)($_POST['input_border'] ?? $curInputBorder));
            $membersBg   = trim((string)($_POST['members_bg'] ?? $curMembersBg));
            $msgBoxBg    = trim((string)($_POST['msgbox_bg'] ?? $curMsgBoxBg));
            $pagetitleBg = trim((string)($_POST['pagetitle_bg'] ?? $curPagetitleBg));
        }

        if (!$isHex($pagetitleBg)) $pagetitleBg = '#0861bc';
        if (!$isHex($inputBg))     $inputBg     = '#ffffff';
        if (!$isHex($inputText))   $inputText   = '#111827';
        if (!$isHex($inputBorder)) $inputBorder = '#d1d5db';

        // validate hex
        if (!$isHex($bg))          $bg = $DEF_BG;
        if (!$isHex($accent))      $accent = $DEF_ACCENT;
        if (!$isHex($headerBg))    $headerBg = $bg;
        if (!$isHex($leftbarBg))   $leftbarBg = $bg;
        if (!$isHex($panelBg))     $panelBg = $DEF_PANEL;
        if (!$isHex($footerBg))    $footerBg = $bg;
        if (!$isHex($textColor))   $textColor = $DEF_TEXT;
        if (!$isHex($inputBg))     $inputBg = $DEF_INPUT_BG;
        if (!$isHex($inputText))   $inputText = $DEF_INPUT_TEXT;
        if (!$isHex($inputBorder)) $inputBorder = $DEF_INPUT_BORDER;
        if (!$isHex($membersBg))   $membersBg = $DEF_MEMBERS_BG;
        if (!$isHex($msgBoxBg))    $msgBoxBg = $DEF_MSGBOX_BG;

        // ---- Font ----
        $fontPx = (int)($_POST['font_px'] ?? (int)str_replace('px','',$curFont));
        if ($fontPx < 12) $fontPx = 12;
        if ($fontPx > 22) $fontPx = 22;

        $themeJson = json_encode([
            'bg'           => $bg,
            'accent'       => $accent,
            'headerBg'     => $headerBg,
            'leftbarBg'    => $leftbarBg,
            'panelBg'      => $panelBg,
            'footerBg'     => $footerBg,
            'textColor'    => $textColor,
            'inputBg'      => $inputBg,
            'inputText'    => $inputText,
            'inputBorder'  => $inputBorder,
            'membersBg'    => $membersBg,
            'msgBoxBg'     => $msgBoxBg,
            'pagetitleBg'  => $pagetitleBg,
        ], JSON_UNESCAPED_SLASHES);

        $a11yJson = json_encode(['fontSize' => $fontPx . 'px'], JSON_UNESCAPED_SLASHES);

        try {
            $st = $dbh->prepare("
                UPDATE org_settings
                SET logo_type = :lt,
                    logo_text = :txt,
                    logo_icon = :li,
                    logo_color = :lc,
                    theme_json = :t,
                    a11y_json = :a,
                    updated_at = NOW()
                WHERE org_id = :org
                LIMIT 1
            ");
            $st->execute([
                ':lt'  => $logoType,
                ':txt' => ($logoText === '' ? null : $logoText),
                ':li'  => $logoIcon,
                ':lc'  => $logoColor,
                ':t'   => $themeJson,
                ':a'   => $a11yJson,
                ':org' => $orgId,
            ]);

            $ok = $doReset
                ? 'Defaults restored. Refresh any org page.'
                : 'Saved. Refresh any org page to see changes.';

            // Refresh values for UI
            $curLogoType  = $logoType;
            $curLogoText  = $logoText;
            $curLogoIcon  = $logoIcon;
            $curLogoColor = $logoColor;

            $curBg          = $bg;
            $curAccent      = $accent;
            $curHeaderBg    = $headerBg;
            $curLeftbarBg   = $leftbarBg;
            $curPanelBg     = $panelBg;
            $curFooterBg    = $footerBg;
            $curTextColor   = $textColor;
            $curInputBg     = $inputBg;
            $curInputText   = $inputText;
            $curInputBorder = $inputBorder;
            $curMembersBg   = $membersBg;
            $curMsgBoxBg    = $msgBoxBg;
            $curPagetitleBg = $pagetitleBg;

            $curFont      = $fontPx . 'px';
        } catch (Throwable $e) {
            $err = 'Save failed: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once __DIR__ . '/includes/org_theme_head.php'; ?>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
  <title><?= h((string)($ORG['name'] ?? 'Organization')) ?> - Settings</title>

  <link href="../lib/font-awesome/css/font-awesome.css" rel="stylesheet">
  <link href="../lib/Ionicons/css/ionicons.css" rel="stylesheet">
  <link href="../lib/perfect-scrollbar/css/perfect-scrollbar.css" rel="stylesheet">
  <link href="../lib/bootstrap/bootstrap.css" rel="stylesheet">
  <link rel="stylesheet" href="../css/shamcey.css">
  <?php require_once __DIR__ . '/includes/org_layout.php'; org_layout_head_assets(); ?>

  <style>
    /* ✅ FIXED PAGE */
    html,body{ height:100%; overflow:hidden; }

    .sh-mainpanel{
      height:100vh;
      display:flex;
      flex-direction:column;
      overflow:hidden;
    }

    .sh-pagetitle{ flex:0 0 auto; }

    /* page body never scrolls */
    .sh-pagebody{
      flex:1 1 auto;
      overflow:hidden;
      padding-bottom:0!important;
      display:flex;
      flex-direction:column;
      min-height:0;
    }

    /* card fills available space */
    .settings-card{
      flex:1 1 auto;
      min-height:0;
      display:flex;
      flex-direction:column;
      overflow:hidden;
    }

    /* this is the fixed card layout */
    .card-body-fixed{
      flex:1 1 auto;
      min-height:0;
      overflow:hidden;
      display:flex;
      flex-direction:column;
    }

    /* ✅ ONLY THIS SCROLLS (form fields) */
    .rows-scroll{
      flex:1 1 auto;
      min-height:0;
      overflow:auto;
      padding: 15px;
    }

    /* ✅ fixed action bar (Save / Reset / Cancel) */
    .actions-fixed{
      flex:0 0 auto;
      padding: 12px 15px;
      border-top: 1px solid rgba(17,24,39,.10);
      background: rgba(248,250,252,.96);
      display:flex;
      gap:10px;
      flex-wrap:wrap;
      justify-content:flex-end;
      align-items:center;
    }

    .actions-fixed .btn{ font-weight:800; }
  </style>
</head>
<body>

<?php include __DIR__ . '/includes/header.php'; ?>
<?php include __DIR__ . '/includes/leftbar.php'; ?>

<div class="sh-mainpanel">

  <div class="sh-pagebody">

    <div class="card bd-0 settings-card">
      <div class="card-body card-body-fixed">

        <!-- ✅ The form includes BOTH the scroll area + fixed action bar -->
        <form method="post" style="height:100%;display:flex;flex-direction:column;min-height:0;">

          <!-- ✅ ONLY THIS scrolls -->
          <div class="rows-scroll">

            <?php if ($err): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endif; ?>
            <?php if ($ok): ?><div class="alert alert-success"><?= h($ok) ?></div><?php endif; ?>

            <div class="row">

              <div class="col-lg-6">
                <div class="form-group">
                  <label>Workspace Icon</label>
                  <select name="logo_icon" class="form-control">
                    <option value="ion-ios-home" <?= $curLogoIcon==='ion-ios-home'?'selected':'' ?>>Home</option>
                    <option value="ion-ios-people" <?= $curLogoIcon==='ion-ios-people'?'selected':'' ?>>Family / Group</option>
                    <option value="ion-ios-briefcase" <?= $curLogoIcon==='ion-ios-briefcase'?'selected':'' ?>>Company</option>
                    <option value="ion-ios-heart" <?= $curLogoIcon==='ion-ios-heart'?'selected':'' ?>>Care</option>
                    <option value="ion-ios-book" <?= $curLogoIcon==='ion-ios-book'?'selected':'' ?>>Church / Study</option>
                    <option value="ion-ios-star" <?= $curLogoIcon==='ion-ios-star'?'selected':'' ?>>Club</option>
                  </select>

                  <label class="mt-3">Icon Color</label>
                  <input type="color" name="logo_color" value="<?= h($curLogoColor) ?>" class="form-control" style="height:42px;">
                </div>

                <div class="form-group">
                  <label>Logo Text</label>
                  <input type="text" name="logo_text" maxlength="40" class="form-control" value="<?= h($curLogoText) ?>">
                </div>

                <div class="form-group">
                  <label>Page Title Background (sh-pagetitle)</label>
                  <input type="color" name="pagetitle_bg" class="form-control"
                        value="<?= h($curPagetitleBg) ?>" style="height:42px;">
                </div>

                <hr>

                <div class="form-group">
                  <label>Input/Textfield Background</label>
                  <input type="color" name="input_bg" class="form-control"
                        value="<?= h($curInputBg) ?>" style="height:42px;">
                </div>

                <div class="form-group">
                  <label>Input/Textfield Text Color</label>
                  <input type="color" name="input_text" class="form-control"
                        value="<?= h($curInputText) ?>" style="height:42px;">
                </div>

                <div class="form-group">
                  <label>Input/Textfield Border Color</label>
                  <input type="color" name="input_border" class="form-control"
                        value="<?= h($curInputBorder) ?>" style="height:42px;">
                </div>
              </div>

              <div class="col-lg-6">

                <div class="form-group">
                  <label>Body Background</label>
                  <input type="color" name="bg" class="form-control" value="<?= h($curBg) ?>" style="height:42px;">
                </div>

                <div class="form-group">
                  <label>Accent Color</label>
                  <input type="color" name="accent" class="form-control" value="<?= h($curAccent) ?>" style="height:42px;">
                </div>

                <div class="form-group">
                  <label>Header Background</label>
                  <input type="color" name="header_bg" class="form-control" value="<?= h($curHeaderBg) ?>" style="height:42px;">
                </div>

                <div class="form-group">
                  <label>Left Sidebar Background</label>
                  <input type="color" name="leftbar_bg" class="form-control" value="<?= h($curLeftbarBg) ?>" style="height:42px;">
                </div>

                <div class="form-group">
                  <label>Main Panel Background</label>
                  <input type="color" name="panel_bg" class="form-control" value="<?= h($curPanelBg) ?>" style="height:42px;">
                </div>

                <div class="form-group">
                  <label>Footer Background</label>
                  <input type="color" name="footer_bg" class="form-control" value="<?= h($curFooterBg) ?>" style="height:42px;">
                </div>

                <hr>

                <div class="form-group">
                  <label>Text Color (Global)</label>
                  <input type="color" name="text_color" class="form-control" value="<?= h($curTextColor) ?>" style="height:42px;">
                </div>

                <hr>

                <div class="form-group">
                  <label>Messages: Members List Background</label>
                  <input type="color" name="members_bg" class="form-control" value="<?= h($curMembersBg) ?>" style="height:42px;">
                </div>

                <div class="form-group">
                  <label>Messages: Conversation Box Background</label>
                  <input type="color" name="msgbox_bg" class="form-control" value="<?= h($curMsgBoxBg) ?>" style="height:42px;">
                </div>

                <div class="form-group">
                  <label>Font Size</label>
                  <?php $fontVal = (int)str_replace('px','',$curFont); ?>
                  <input type="number" name="font_px" class="form-control" min="12" max="22" value="<?= (int)$fontVal ?>">
                  <small class="form-text text-muted">Recommended: 16–18px</small>
                </div>
              </div>

            </div><!-- /row -->
          </div><!-- /rows-scroll -->

          <!-- ✅ FIXED buttons (do not scroll) -->
          <div class="actions-fixed">
            <a href="dashboard.php" class="btn btn-light">Cancel</a>
            <button type="submit" name="reset_defaults" value="1" class="btn btn-outline-secondary">Reset to Default</button>
            <button type="submit" class="btn btn-primary">Save Settings</button>
          </div>

        </form>
      </div>
    </div>

  </div>

  <!-- ✅ Footer is OUTSIDE scroll and fixed (not scrolling) -->
  <?php include __DIR__ . '/includes/footer.php'; ?>
</div>

</body>
</html>
