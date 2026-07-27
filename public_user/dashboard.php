<?php
require_once __DIR__ . '/includes/session_user.php';
require_once __DIR__ . '/controller.php';
require_once __DIR__ . '/includes/post_categories.php';
require_once __DIR__ . '/includes/post_layout.php';
require_once __DIR__ . '/includes/device_profile.php';
require_once __DIR__ . '/includes/publisher_accounts.php';
require_once __DIR__ . '/includes/staff_publisher_access.php';
require_once __DIR__ . '/includes/theme_prefs.php';
require_once __DIR__ . '/includes/appearance_palettes.php';

error_reporting(E_ALL);
ini_set('display_errors', '0');

requireUserLogin();
staff_pub_deny_write();

$controller = new Controller();
$dbh = $controller->pdo();

$meId = (int)($_SESSION['user_id'] ?? 0);
$isModalCreate = (string)($_GET['modal'] ?? '') === '1';
$isStoryCreate = ((string)($_GET['story'] ?? '') === '1');

/* Modal create-post: resolve Gear Appearance color (parent may pass live selection) */
$modalAppearanceMode = appearance_palette_normalize_mode((string)($_GET['appearance'] ?? ''));
if ($modalAppearanceMode === 'system' || $modalAppearanceMode === '') {
    $modalAppearanceMode = theme_prefs_appearance_mode($dbh, $meId);
}
$modalAppearanceIsNamed = !in_array($modalAppearanceMode, ['system', 'light', 'dark'], true);
$modalAutoEnabled = appearance_bridge_theme_auto_enabled($dbh, $meId);
$modalPageBg = '#171d24';
$modalPageText = '#b1bcce';
$modalPageMuted = '#94a3b8';
$modalInputBg = '#ffffff';
if ($modalAppearanceIsNamed) {
    $modalPageBg = appearance_palette_unified_bg_hex($modalAppearanceMode);
    $usesDarkChrome = appearance_palette_uses_dark_chrome($modalAppearanceMode);
    $modalPageText = $usesDarkChrome ? '#f3f6fb' : appearance_palette_chromatic_text_hex($modalAppearanceMode);
    $modalPageMuted = $usesDarkChrome ? '#cbd5e1' : appearance_palette_chromatic_muted_hex($modalAppearanceMode);
    $modalInputBg = $modalPageBg;
} elseif ($modalAppearanceMode === 'light') {
    $modalPageBg = '#f5f7fb';
    $modalPageText = '#0f172a';
    $modalPageMuted = '#64748b';
    $modalInputBg = '#ffffff';
} elseif ($modalAppearanceMode === 'dark') {
    $modalPageBg = '#171d24';
    $modalPageText = '#b1bcce';
    $modalPageMuted = '#94a3b8';
    $modalInputBg = '#1f2937';
} elseif (!$modalAutoEnabled || !appearance_bridge_is_night_now()) {
    /* system + day (or auto off) → light canvas */
    $modalPageBg = '#f5f7fb';
    $modalPageText = '#0f172a';
    $modalPageMuted = '#64748b';
    $modalInputBg = '#ffffff';
}
/* Parent live canvas color (from Gear Appearance) — wins for create-post iframe */
$parentPaletteBg = trim((string)($_GET['palette_bg'] ?? ''));
if (preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', $parentPaletteBg)) {
    if (strlen($parentPaletteBg) === 4) {
        $parentPaletteBg = '#' . $parentPaletteBg[1] . $parentPaletteBg[1] . $parentPaletteBg[2] . $parentPaletteBg[2] . $parentPaletteBg[3] . $parentPaletteBg[3];
    }
    $modalPageBg = strtolower($parentPaletteBg);
    if ($modalAppearanceIsNamed) {
        $modalInputBg = $modalPageBg;
    }
}
$modalPageBgCss = htmlspecialchars($modalPageBg, ENT_QUOTES, 'UTF-8');
$modalPageTextCss = htmlspecialchars($modalPageText, ENT_QUOTES, 'UTF-8');
$modalPageMutedCss = htmlspecialchars($modalPageMuted, ENT_QUOTES, 'UTF-8');
$modalInputBgCss = htmlspecialchars($modalInputBg, ENT_QUOTES, 'UTF-8');
$modalAppearanceAttr = $modalAppearanceIsNamed
    ? ' data-msb-appearance="' . htmlspecialchars($modalAppearanceMode, ENT_QUOTES, 'UTF-8') . '"'
    : (($modalAppearanceMode === 'light' || ($modalAppearanceMode === 'system' && (!$modalAutoEnabled || !appearance_bridge_is_night_now())))
        ? ' data-msb-org-light="1"'
        : '');

ensurePostCategorySchema($dbh);
device_profile_ensure_post_columns($dbh);
publisher_ensure_schema($dbh);
$isPublisherAccount = publisher_account_is($dbh, $meId);

$categoryFlash = (string)($_GET['cat'] ?? '');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && (string)($_POST['action'] ?? '') === 'create_category') {
    $categoryName = trim((string)($_POST['category_name'] ?? ''));
    $categoryType = trim((string)($_POST['category_type'] ?? 'topic'));
    if (!in_array($categoryType, ['video', 'photo', 'topic', 'mixed', 'file'], true)) {
        $categoryType = 'topic';
    }
    $params = [];
    if ($isModalCreate) {
        $params['modal'] = 1;
    }
    if ($isStoryCreate) {
        $params['story'] = 1;
    }
    if ($modalAppearanceIsNamed) {
        $params['appearance'] = $modalAppearanceMode;
    }
    if ((int)($_GET['edit'] ?? 0) > 0) {
        $params['edit'] = (int)($_GET['edit'] ?? 0);
    }
    if ($categoryName !== '') {
        try {
            createUserPostCategory($dbh, $meId, $categoryName, $categoryType);
            $params['cat'] = 'saved';
        } catch (Throwable $e) {
            $params['cat'] = 'error';
        }
    } else {
        $params['cat'] = 'empty';
    }
    header('Location: dashboard.php' . (!empty($params) ? ('?' . http_build_query($params)) : ''));
    exit;
}

$postCategories = fetchUserPostCategories($dbh, $meId);

// edit mode
$editId = (int)($_GET['edit'] ?? 0);
$editPost = null;
$editAttachmentCount = 0;
$editBodyText = '';
if ($editId > 0 && $meId > 0) {
    try {
        $stE = $dbh->prepare("SELECT * FROM public_posts WHERE id = :id AND user_id = :uid AND is_deleted = 0 LIMIT 1");
        $stE->execute([':id' => $editId, ':uid' => $meId]);
        $editPost = $stE->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) { $editPost = null; }
}

// my recent posts
$myPosts = [];
if ($meId > 0) {
    try {
        $stP = $dbh->prepare("
            SELECT p.*,
              (SELECT a.file_path FROM public_post_attachments a WHERE a.post_id = p.id ORDER BY a.id DESC LIMIT 1) AS preview_path,
              (SELECT a.type FROM public_post_attachments a WHERE a.post_id = p.id ORDER BY a.id DESC LIMIT 1) AS preview_type,
              (SELECT COUNT(*) FROM public_post_comments c WHERE c.post_id = p.id AND c.is_deleted = 0) AS comment_count
            FROM public_posts p
            WHERE p.user_id = :uid AND p.is_deleted = 0
            ORDER BY COALESCE(p.updated_at, p.created_at) DESC
            LIMIT 25
        ");
        $stP->execute([':uid' => $meId]);
        $myPosts = $stP->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) { $myPosts = []; }
}

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function extract_layout_override_marker(string $description): string {
    if (preg_match('/\[\[layout:([a-z0-9_]+)\]\]/i', $description, $m)) {
        return strtolower(trim((string)($m[1] ?? '')));
    }
    return '';
}
function strip_layout_override_marker(string $description): string {
    return trim((string)preg_replace('/\[\[layout:[a-z0-9_]+\]\]/i', '', $description));
}
$loggedEmail = $_SESSION['user_login'];
$currentLayoutOverride = '';
$currentCategoryId = 0;
if ($editPost) {
    $editBodyText = trim((string)($editPost['body'] ?? ''));
    if ($editBodyText === '') {
        $editBodyText = strip_layout_override_marker((string)($editPost['description'] ?? ''));
    }
    try {
        $stAtt = $dbh->prepare("SELECT COUNT(*) FROM public_post_attachments WHERE post_id = :pid");
        $stAtt->execute([':pid' => (int)$editPost['id']]);
        $editAttachmentCount = (int)($stAtt->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        $editAttachmentCount = 0;
    }
    foreach (['layout_type','layout','post_type','type'] as $k) {
        if (!empty($editPost[$k])) {
            $currentLayoutOverride = trim((string)$editPost[$k]);
            break;
        }
    }
    if ($currentLayoutOverride === '') {
        $currentLayoutOverride = extract_layout_override_marker((string)($editPost['description'] ?? ''));
    }
    $currentCategoryId = (int)($editPost['category_id'] ?? 0);
    if (post_is_story_only($editPost)) {
        $isStoryCreate = true;
        $currentLayoutOverride = 'story';
    }
}
?>
<!DOCTYPE html>
<html lang="en"<?php echo $isModalCreate ? $modalAppearanceAttr : ''; ?>>
  <head>
    <!-- Required meta tags -->
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">

    <meta name="twitter:site" content="@themepixels">
    <meta name="twitter:creator" content="@themepixels">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="Talentra">
    <meta name="twitter:description" content="Premium Quality and Responsive UI for Dashboard.">
    <meta name="twitter:image" content="http://themepixels.me/shamcey/img/shamcey-social.png">

    <meta property="og:url" content="http://themepixels.me/shamcey">
    <meta property="og:title" content="Talentra">
    <meta property="og:description" content="Premium Quality and Responsive UI for Dashboard.">
    <meta property="og:image" content="http://themepixels.me/shamcey/img/shamcey-social.png">
    <meta property="og:image:secure_url" content="http://themepixels.me/shamcey/img/shamcey-social.png">
    <meta property="og:image:type" content="image/png">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="600">

    <meta name="description" content="Premium Quality and Responsive UI for Dashboard.">
    <meta name="author" content="ThemePixels">

    <title>Talentra</title>

    <!-- Vendor css -->
    <link href="./lib/font-awesome/css/font-awesome.css" rel="stylesheet">
    <link href="./lib/Ionicons/css/ionicons.css" rel="stylesheet">
    <link href="./lib/perfect-scrollbar/css/perfect-scrollbar.css" rel="stylesheet">

    <!-- Shamcey CSS -->
    <link rel="stylesheet" href="./css/shamcey.css">
    <link rel="stylesheet" href="assets/ui_best.css">
    <?php theme_prefs_print_head_bootstrap($dbh, $meId); ?>

    <!-- Script -->
    <script src="assets/ui_best.js" defer></script>
    <script src="./lib/jquery/jquery.js"></script>
    <script src="./lib/popper.js/popper.js"></script>
    <script src="./lib/bootstrap/bootstrap.js"></script>
    <script src="./lib/jquery-ui/jquery-ui.js"></script>
    <script src="./lib/perfect-scrollbar/js/perfect-scrollbar.jquery.js"></script>
    <script src="./lib/moment/moment.js"></script>
    <script src="./lib/Flot/jquery.flot.js"></script>
    <script src="./lib/Flot/jquery.flot.resize.js"></script>
    <script src="./lib/flot-spline/jquery.flot.spline.js"></script>
    <script src="./js/shamcey.js"></script>
    <script src="./js/dashboard.js"></script>

    <style>
      /* ===== Fixed/Sticky Header (like feed.php) ===== */
      html, body { height: 100%; }
      body { overflow: hidden; }

      .sh-mainpanel{
        height: 100vh;
        display: flex;
        flex-direction: column;
        overflow: hidden;
      }

      .sh-pagetitle{
        position: sticky;
        top: 100px;
        z-index: 1100;
        background: var(--msb-palette-bg, #171d24);
        border-bottom: 1px solid var(--msb-palette-border, rgba(0,0,0,.08));
        box-shadow: 0 2px 10px rgba(0,0,0,.05);
        flex: 0 0 auto;
      }

      .sh-pagebody{
        flex: 1 1 auto;
        min-height: 0;
        overflow: auto; /* dashboard content scrolls, header stays fixed */
        padding-top: 10px;
      }

      /* Optional: make the modal list feel nicer */
      .rp-item{ border-bottom:1px solid rgba(0,0,0,.06); padding:10px 8px; }
      .rp-item:last-child{ border-bottom:none; }
      .rp-thumb{ width:56px; height:56px; 
      /* border-radius:12px; object-fit:cover;  */
      background:#171d24; display:inline-block; }

      .row-sm {
        margin-left: 10px;
        margin-right: 10px;
      }

      .card-body {
        flex: 1 1 auto;
        padding: 10px;
      }
    </style>
  
<style>
/* ===============================
   DASHBOARD RESPONSIVE FIX
   CSS ONLY — NO PHP/JS CHANGED
================================ */
@media (max-width: 991.98px) {
  body {
    margin-left: 0 !important;
    padding-left: 12px !important;
    padding-right: 12px !important;
    overflow-x: hidden !important;
    overflow-y: auto !important;
  }
  .content,
  .main-content,
  .dashboard-wrapper,
  .dashboard-container {
    margin-left: 0 !important;
    width: 100% !important;
    max-width: 100% !important;
  }
  .card,
  .dashboard-card,
  .post-card {
    width: 100% !important;
    max-width: 100% !important;
  }
  .row {
    flex-direction: column !important;
  }
  [class*="col-"] {
    width: 100% !important;
    max-width: 100% !important;
  }
}
@media (max-width: 575.98px) {
  body {
    padding: 8px !important;
  }
  .card,
  .dashboard-card {
    padding: 12px !important;
  }
  button,
  .btn {
    width: 100%;
  }
}
</style>


<style>
/* ===== Strong responsive overrides for Shamcey (public_user/dashboard.php) ===== */
@media (max-width: 991.98px){
  /* Main layout containers used by Shamcey */
  .sh-mainpanel{ margin-left:0 !important; width:100% !important; max-width:100% !important; }
  .sh-pagebody{ padding: 12px !important; }
  /* Some templates use fixed/min widths */
  .sh-mainpanel *, .sh-pagebody *{ max-width:100%; }
  /* If left sidebar is fixed and pushes layout, keep it off-canvas by default */
  .sh-sideleft-menu{ left:-260px !important; }
  body.show-leftbar .sh-sideleft-menu,
  body.sh-sideleft-show .sh-sideleft-menu,
  body.sideleft-show .sh-sideleft-menu{
    left:0 !important;
  }
}
@media (max-width: 575.98px){
  .sh-pagebody{ padding: 8px !important; }
}

/* Keep the shared live modal fully hidden on dashboard until JS explicitly opens it. */
body.dashboard-page #globalLiveModal:not(.is-open){
  display:none !important;
  visibility:hidden !important;
  opacity:0 !important;
  pointer-events:none !important;
}

body.dashboard-page #globalLiveModal:not(.is-open) .global-live-modal-dialog,
body.dashboard-page #globalLiveModal:not(.is-open) iframe,
body.dashboard-page #globalLiveModal:not(.is-open) video,
body.dashboard-page #globalLiveModal:not(.is-open) img,
body.dashboard-page #globalLiveModal:not(.is-open) aside{
  display:none !important;
}

html:not([data-msb-appearance]):not([data-msb-org-light]) body.dashboard-page{
  background:#171d24 !important;
  color:var(--msb-palette-text, #b1bcce) !important;
}

html[data-msb-org-light] body.dashboard-page{
  background:#ffffff !important;
  color:#111827 !important;
}

html[data-theme="light"]:not([data-msb-appearance]) body.dashboard-page,
html[data-theme="light"]:not([data-msb-appearance]) body.dashboard-page .sh-mainpanel,
html[data-theme="light"]:not([data-msb-appearance]) body.dashboard-page .sh-pagebody,
html[data-theme="light"]:not([data-msb-appearance]) body.dashboard-page .sh-pagetitle,
html[data-theme="light"]:not([data-msb-appearance]) body.dashboard-page .row,
html[data-theme="light"]:not([data-msb-appearance]) body.dashboard-page .row-sm,
html[data-theme="light"]:not([data-msb-appearance]) body.dashboard-page .col-lg-12,
html[data-theme="light"]:not([data-msb-appearance]) body.dashboard-page .card,
html[data-theme="light"]:not([data-msb-appearance]) body.dashboard-page .card-body,
html[data-theme="light"]:not([data-msb-appearance]) body.dashboard-page .card-header,
html[data-theme="light"]:not([data-msb-appearance]) body.dashboard-page .card-footer,
html[data-theme="light"]:not([data-msb-appearance]) body.dashboard-page .card.mb-3{
  background:var(--msb-palette-bg, #f5f7fb) !important;
  color:var(--msb-palette-text, #0f172a) !important;
  border-color:var(--msb-palette-border, rgba(15,23,42,.12)) !important;
}

html[data-theme="light"]:not([data-msb-appearance]) body.dashboard-page label,
html[data-theme="light"]:not([data-msb-appearance]) body.dashboard-page .card-title,
html[data-theme="light"]:not([data-msb-appearance]) body.dashboard-page h6{
  color:var(--msb-palette-text, #0f172a) !important;
}

html[data-theme="light"]:not([data-msb-appearance]) body.dashboard-page .text-muted,
html[data-theme="light"]:not([data-msb-appearance]) body.dashboard-page small{
  color:var(--msb-palette-text-muted, #64748b) !important;
}

html[data-theme="light"]:not([data-msb-appearance]) body.dashboard-page .alert-info{
  background:var(--msb-palette-bg, #f5f7fb) !important;
  border-color:var(--msb-palette-border, rgba(15,23,42,.12)) !important;
  color:var(--msb-palette-text, #0f172a) !important;
}

<?php if ($isModalCreate): ?>
html, body{
  height:100% !important;
  min-height:0 !important;
}

/* Default dark canvas only when no Gear appearance color / light canvas */
html:not([data-msb-appearance]):not([data-msb-org-light]),
html:not([data-msb-appearance]):not([data-msb-org-light]) body,
html:not([data-msb-appearance]):not([data-msb-org-light]) body.dashboard-modal-page,
html:not([data-msb-appearance]):not([data-msb-org-light]) body.dashboard-modal-page .sh-mainpanel,
html:not([data-msb-appearance]):not([data-msb-org-light]) body.dashboard-modal-page .sh-pagebody,
html:not([data-msb-appearance]):not([data-msb-org-light]) body.dashboard-modal-page .card,
html:not([data-msb-appearance]):not([data-msb-org-light]) body.dashboard-modal-page .card-body,
html:not([data-msb-appearance]):not([data-msb-org-light]) body.dashboard-modal-page .alert-info{
  background:var(--msb-palette-bg, #171d24) !important;
  background-color:var(--msb-palette-bg, #171d24) !important;
  color:var(--msb-palette-text, #b1bcce) !important;
  border-color:var(--msb-palette-border, rgba(177,188,206,.18)) !important;
}

html:not([data-msb-appearance]):not([data-msb-org-light]) body.dashboard-modal-page .form-control,
html:not([data-msb-appearance]):not([data-msb-org-light]) body.dashboard-modal-page select.form-control,
html:not([data-msb-appearance]):not([data-msb-org-light]) body.dashboard-modal-page textarea.form-control{
  background:var(--msb-palette-input-bg, var(--msb-palette-bg, #171d24)) !important;
  color:var(--msb-palette-text, #b1bcce) !important;
  border-color:var(--msb-palette-border, rgba(177,188,206,.22)) !important;
}

/* Gear Appearance color — paint create-post iframe to selected palette */
html[data-msb-appearance],
html[data-msb-appearance] body,
html[data-msb-appearance] body.dashboard-modal-page,
html[data-msb-appearance] body.dashboard-modal-page .sh-mainpanel,
html[data-msb-appearance] body.dashboard-modal-page .sh-pagebody,
html[data-msb-appearance] body.dashboard-modal-page .row,
html[data-msb-appearance] body.dashboard-modal-page .row-sm,
html[data-msb-appearance] body.dashboard-modal-page .col-lg-12,
html[data-msb-appearance] body.dashboard-modal-page .card,
html[data-msb-appearance] body.dashboard-modal-page .card-body,
html[data-msb-appearance] body.dashboard-modal-page .card.mb-3,
html[data-msb-appearance] body.dashboard-modal-page .alert,
html[data-msb-appearance] body.dashboard-modal-page .alert-info,
html[data-msb-appearance] body.dashboard-modal-page #instructionBox{
  background:var(--msb-palette-bg) !important;
  background-color:var(--msb-palette-bg) !important;
  background-image:none !important;
  color:var(--msb-palette-text) !important;
  border-color:var(--msb-palette-border, rgba(15,23,42,.12)) !important;
}

html[data-msb-appearance] body.dashboard-modal-page label,
html[data-msb-appearance] body.dashboard-modal-page .card-title,
html[data-msb-appearance] body.dashboard-modal-page h6,
html[data-msb-appearance] body.dashboard-modal-page .form-check-label{
  color:var(--msb-palette-text) !important;
}

html[data-msb-appearance] body.dashboard-modal-page .text-muted,
html[data-msb-appearance] body.dashboard-modal-page small,
html[data-msb-appearance] body.dashboard-modal-page .form-text{
  color:var(--msb-palette-text-muted, var(--msb-palette-text)) !important;
}

html[data-msb-appearance] body.dashboard-modal-page .form-control,
html[data-msb-appearance] body.dashboard-modal-page select.form-control,
html[data-msb-appearance] body.dashboard-modal-page textarea.form-control,
html[data-msb-appearance] body.dashboard-modal-page .msb-readonly-field{
  background:var(--msb-palette-input-bg, var(--msb-palette-surface-2, var(--msb-palette-bg))) !important;
  color:var(--msb-palette-text) !important;
  border-color:var(--msb-palette-border-strong, var(--msb-palette-border)) !important;
}

html[data-msb-org-light]:not([data-msb-appearance]):not(.dark-auto),
html[data-msb-org-light]:not([data-msb-appearance]):not(.dark-auto) body,
html[data-msb-org-light]:not([data-msb-appearance]):not(.dark-auto) body.dashboard-modal-page,
html[data-msb-org-light]:not([data-msb-appearance]):not(.dark-auto) body.dashboard-modal-page .sh-mainpanel,
html[data-msb-org-light]:not([data-msb-appearance]):not(.dark-auto) body.dashboard-modal-page .sh-pagebody,
html[data-msb-org-light]:not([data-msb-appearance]):not(.dark-auto) body.dashboard-modal-page .card,
html[data-msb-org-light]:not([data-msb-appearance]):not(.dark-auto) body.dashboard-modal-page .card-body{
  background:#ffffff !important;
  background-color:#ffffff !important;
  color:#111827 !important;
  border-color:rgba(15,23,42,.12) !important;
}

body.dashboard-page{
  overflow:auto !important;
  padding-top:0 !important;
  min-height:0 !important;
}

body.dashboard-page .sh-mainpanel{
  margin-left:0 !important;
  min-height:0 !important;
  height:auto !important;
  overflow:visible !important;
}

body.dashboard-page .sh-pagebody{
  overflow:visible !important;
  padding:16px !important;
  flex:0 0 auto !important;
  min-height:0 !important;
  height:auto !important;
}

body.dashboard-page .row-sm,
body.dashboard-page .row-sm > [class*="col-"],
body.dashboard-page .card,
body.dashboard-page .card .card-body{
  flex:0 0 auto !important;
  height:auto !important;
  min-height:0 !important;
}

body.dashboard-page textarea.form-control{
  min-height:0 !important;
  height:auto !important;
  resize:none;
}

body.dashboard-page .row-sm{
  margin-left:0 !important;
  margin-right:0 !important;
}

body.dashboard-page .card{
  box-shadow:none !important;
}
<?php endif; ?>

/* Dashboard canvas — dark #171d24 unless Gear Dark auto ON (white) or Gear appearance color */
html.dark-auto:not([data-msb-appearance]):not([data-msb-org-light]) body.dashboard-page,
html.dark-auto:not([data-msb-appearance]):not([data-msb-org-light]) body.dashboard-page .sh-mainpanel,
html.dark-auto:not([data-msb-appearance]):not([data-msb-org-light]) body.dashboard-page .sh-pagebody,
html.dark-auto:not([data-msb-appearance]):not([data-msb-org-light]) body.dashboard-page .sh-pagetitle,
html.dark-auto:not([data-msb-appearance]):not([data-msb-org-light]) body.dashboard-page .row,
html.dark-auto:not([data-msb-appearance]):not([data-msb-org-light]) body.dashboard-page .row-sm,
html.dark-auto:not([data-msb-appearance]):not([data-msb-org-light]) body.dashboard-page .col-lg-12,
html.dark-auto:not([data-msb-appearance]):not([data-msb-org-light]) body.dashboard-page .card,
html.dark-auto:not([data-msb-appearance]):not([data-msb-org-light]) body.dashboard-page .card-body,
html.dark-auto:not([data-msb-appearance]):not([data-msb-org-light]) body.dashboard-page .card-header,
html.dark-auto:not([data-msb-appearance]):not([data-msb-org-light]) body.dashboard-page .card-footer,
html.dark-auto:not([data-msb-appearance]):not([data-msb-org-light]) body.dashboard-page .card.mb-3,
html:not([data-msb-appearance]):not([data-msb-org-light]) body.dashboard-page .form-control,
html:not([data-msb-appearance]):not([data-msb-org-light]) body.dashboard-page select.form-control,
html:not([data-msb-appearance]):not([data-msb-org-light]) body.dashboard-page textarea.form-control,
html:not([data-msb-appearance]):not([data-msb-org-light]) body.dashboard-page .msb-readonly-field,
html:not([data-msb-appearance]):not([data-msb-org-light]) body.dashboard-page .alert,
html:not([data-msb-appearance]):not([data-msb-org-light]) body.dashboard-page .alert-info,
html:not([data-msb-appearance]):not([data-msb-org-light]) body.dashboard-page .alert-success,
html:not([data-msb-appearance]):not([data-msb-org-light]) body.dashboard-page .alert-warning,
html:not([data-msb-appearance]):not([data-msb-org-light]) body.dashboard-page .alert-danger,
html:not([data-msb-appearance]):not([data-msb-org-light]) body.dashboard-page .modal-content,
html:not([data-msb-appearance]):not([data-msb-org-light]) body.dashboard-page .modal-body,
html:not([data-msb-appearance]):not([data-msb-org-light]) body.dashboard-page #instructionBox {
  background:#171d24 !important;
  background-color:#171d24 !important;
  background-image:none !important;
}

html[data-msb-org-light] body.dashboard-page,
html[data-msb-org-light] body.dashboard-page .sh-mainpanel,
html[data-msb-org-light] body.dashboard-page .sh-pagebody,
html[data-msb-org-light] body.dashboard-page .card,
html[data-msb-org-light] body.dashboard-page .card-body,
html[data-msb-org-light] body.dashboard-page .form-control,
html[data-msb-org-light] body.dashboard-page select.form-control,
html[data-msb-org-light] body.dashboard-page textarea.form-control,
html[data-msb-org-light] body.dashboard-page .msb-readonly-field,
html[data-msb-org-light] body.dashboard-page .alert,
html[data-msb-org-light] body.dashboard-page .alert-info,
html[data-msb-org-light] body.dashboard-page #instructionBox {
  background:#ffffff !important;
  background-color:#ffffff !important;
  color:#111827 !important;
  border-color:rgba(15,23,42,0.12) !important;
}

html[data-msb-org-light] body.dashboard-page label,
html[data-msb-org-light] body.dashboard-page .card-title,
html[data-msb-org-light] body.dashboard-page h6,
html[data-msb-org-light] body.dashboard-page .form-check-label {
  color:#111827 !important;
}

html[data-msb-org-light] body.dashboard-page .text-muted,
html[data-msb-org-light] body.dashboard-page small,
html[data-msb-org-light] body.dashboard-page .form-text {
  color:#64748b !important;
}

html[data-msb-appearance] body.dashboard-page,
html[data-msb-appearance] body.dashboard-page .sh-mainpanel,
html[data-msb-appearance] body.dashboard-page .sh-pagebody,
html[data-msb-appearance] body.dashboard-page .sh-pagetitle,
html[data-msb-appearance] body.dashboard-page .row,
html[data-msb-appearance] body.dashboard-page .row-sm,
html[data-msb-appearance] body.dashboard-page .col-lg-12,
html[data-msb-appearance] body.dashboard-page .card,
html[data-msb-appearance] body.dashboard-page .card-body,
html[data-msb-appearance] body.dashboard-page .card-header,
html[data-msb-appearance] body.dashboard-page .card-footer,
html[data-msb-appearance] body.dashboard-page .alert,
html[data-msb-appearance] body.dashboard-page .alert-info,
html[data-msb-appearance] body.dashboard-page #instructionBox {
  background-color:var(--msb-palette-bg) !important;
  background-image:none !important;
  color:var(--msb-palette-text) !important;
  border-color:var(--msb-palette-border, rgba(15,23,42,0.12)) !important;
}

html[data-msb-appearance] body.dashboard-page label,
html[data-msb-appearance] body.dashboard-page .card-title,
html[data-msb-appearance] body.dashboard-page h6,
html[data-msb-appearance] body.dashboard-page .form-check-label {
  color:var(--msb-palette-text) !important;
}

html[data-msb-appearance] body.dashboard-page .text-muted,
html[data-msb-appearance] body.dashboard-page small,
html[data-msb-appearance] body.dashboard-page .form-text {
  color:var(--msb-palette-text-muted, #64748b) !important;
}

html[data-msb-appearance] body.dashboard-page .form-control,
html[data-msb-appearance] body.dashboard-page select.form-control,
html[data-msb-appearance] body.dashboard-page textarea.form-control,
html[data-msb-appearance] body.dashboard-page .msb-readonly-field {
  background-color:var(--msb-palette-input-bg, var(--msb-palette-surface, var(--msb-palette-bg))) !important;
  color:var(--msb-palette-text) !important;
  border-color:var(--msb-palette-border-strong, var(--msb-palette-border, rgba(15,23,42,0.18))) !important;
}

/* Gear Dark auto ON — white dashboard wins over appearance color */
html[data-msb-org-light] body.dashboard-page,
html[data-msb-org-light] body.dashboard-page .sh-mainpanel,
html[data-msb-org-light] body.dashboard-page .sh-pagebody,
html[data-msb-org-light] body.dashboard-page .card,
html[data-msb-org-light] body.dashboard-page .card-body,
html[data-msb-org-light] body.dashboard-page .form-control,
html[data-msb-org-light] body.dashboard-page select.form-control,
html[data-msb-org-light] body.dashboard-page textarea.form-control,
html[data-msb-org-light] body.dashboard-page .msb-readonly-field,
html[data-msb-org-light] body.dashboard-page .alert,
html[data-msb-org-light] body.dashboard-page .alert-info,
html[data-msb-org-light] body.dashboard-page #instructionBox {
  background:#ffffff !important;
  background-color:#ffffff !important;
  color:#111827 !important;
  border-color:rgba(15,23,42,0.12) !important;
}

html[data-msb-org-light] body.dashboard-page label,
html[data-msb-org-light] body.dashboard-page .card-title,
html[data-msb-org-light] body.dashboard-page h6,
html[data-msb-org-light] body.dashboard-page .form-check-label {
  color:#111827 !important;
}

html[data-msb-org-light] body.dashboard-page .text-muted,
html[data-msb-org-light] body.dashboard-page small,
html[data-msb-org-light] body.dashboard-page .form-text {
  color:#64748b !important;
}

<?php if ($isModalCreate): ?>
/* Final paint: Gear Appearance color for create-post modal (literal hex wins over dark defaults) */
html,
html[data-theme="light"],
html[data-theme="dark"],
html.dark-auto,
html:not([data-msb-appearance]),
html[data-msb-appearance],
html[data-msb-org-light] {
  --msb-palette-bg: <?php echo $modalPageBgCss; ?>;
  --msb-palette-text: <?php echo $modalPageTextCss; ?>;
  --msb-palette-text-muted: <?php echo $modalPageMutedCss; ?>;
  --msb-palette-input-bg: <?php echo $modalInputBgCss; ?>;
}
html body.dashboard-page.dashboard-modal-page,
html[data-theme="light"] body.dashboard-page.dashboard-modal-page,
html[data-theme="dark"] body.dashboard-page.dashboard-modal-page,
html.dark-auto body.dashboard-page.dashboard-modal-page,
html:not([data-msb-appearance]) body.dashboard-page.dashboard-modal-page,
html[data-theme="light"]:not([data-msb-appearance]) body.dashboard-page.dashboard-modal-page,
html[data-msb-appearance] body.dashboard-page.dashboard-modal-page,
html[data-msb-org-light] body.dashboard-page.dashboard-modal-page,
html body.dashboard-page.dashboard-modal-page .sh-mainpanel,
html body.dashboard-page.dashboard-modal-page .sh-pagebody,
html body.dashboard-page.dashboard-modal-page .row,
html body.dashboard-page.dashboard-modal-page .row-sm,
html body.dashboard-page.dashboard-modal-page .col-lg-12,
html body.dashboard-page.dashboard-modal-page .card,
html body.dashboard-page.dashboard-modal-page .card-body,
html body.dashboard-page.dashboard-modal-page .card-header,
html body.dashboard-page.dashboard-modal-page .card-footer,
html body.dashboard-page.dashboard-modal-page .card.mb-3,
html body.dashboard-page.dashboard-modal-page .alert,
html body.dashboard-page.dashboard-modal-page .alert.alert-info,
html body.dashboard-page.dashboard-modal-page .alert.alert-info.create-post-type-box,
html body.dashboard-page.dashboard-modal-page #instructionBox,
html[data-theme="light"]:not([data-msb-appearance]) body.dashboard-page.dashboard-modal-page .card,
html[data-theme="light"]:not([data-msb-appearance]) body.dashboard-page.dashboard-modal-page .card-body,
html[data-theme="light"]:not([data-msb-appearance]) body.dashboard-page.dashboard-modal-page .alert-info,
html:not([data-msb-appearance]):not([data-msb-org-light]) body.dashboard-page.dashboard-modal-page .alert-info {
  background: <?php echo $modalPageBgCss; ?> !important;
  background-color: <?php echo $modalPageBgCss; ?> !important;
  background-image: none !important;
  color: <?php echo $modalPageTextCss; ?> !important;
}
html body.dashboard-page.dashboard-modal-page label,
html body.dashboard-page.dashboard-modal-page .card-title,
html body.dashboard-page.dashboard-modal-page h6,
html body.dashboard-page.dashboard-modal-page .form-check-label,
html body.dashboard-page.dashboard-modal-page .alert.alert-info,
html body.dashboard-page.dashboard-modal-page .alert.alert-info strong,
html[data-theme="light"]:not([data-msb-appearance]) body.dashboard-page.dashboard-modal-page label {
  color: <?php echo $modalPageTextCss; ?> !important;
}
html body.dashboard-page.dashboard-modal-page .text-muted,
html body.dashboard-page.dashboard-modal-page small,
html body.dashboard-page.dashboard-modal-page .form-text {
  color: <?php echo $modalPageMutedCss; ?> !important;
}
html body.dashboard-page.dashboard-modal-page .form-control,
html body.dashboard-page.dashboard-modal-page select.form-control,
html body.dashboard-page.dashboard-modal-page textarea.form-control,
html body.dashboard-page.dashboard-modal-page .msb-readonly-field,
html[data-theme="light"]:not([data-msb-appearance]) body.dashboard-page.dashboard-modal-page .form-control {
  background: <?php echo $modalInputBgCss; ?> !important;
  background-color: <?php echo $modalInputBgCss; ?> !important;
  color: <?php echo $modalPageTextCss; ?> !important;
  border-color: rgba(15,23,42,.18) !important;
}
<?php endif; ?>
</style>

<script src="./js/device_profile.js"></script>

</head>

  <body class="dashboard-page<?php echo $isModalCreate ? ' dashboard-modal-page' : ''; ?>">
    <?php if (!$isModalCreate): ?>
    <?php $forceFeedRail = true; $skipHeaderThemeBootstrap = true; include __DIR__ . '/includes/header.php'; ?>
    <?php endif; ?>
      <!-- <div class="sh-pagetitle">
        <div class="input-group" style="width: 400px;">
          <small class="text-muted mr-2" style="margin-right:5px;margin-top:10px;font-size:25px;"><?= count($myPosts) ?></small>
          <button type="button" class="btn btn-sm btn-outline-primary" data-toggle="modal" data-target="#recentPostsModal" style="margin-right:5px;">
           My Recent Posts
          </button>
          <input type="search" class="form-control" placeholder="Search">
          <span class="input-group-btn">
            <button class="btn"><i class="fa fa-search"></i></button>
          </span>
        </div>     
        <div class="sh-pagetitle-left">
          <div class="sh-pagetitle-icon"><i class="icon ion-ios-home"></i></div>
          <div class="sh-pagetitle-title">
            <h2>Create Post</h2>
            <span>Upload image/video or write text. description shows in the Feed sidebar.</span>
            
          </div>
        </div>
      </div> -->
    <div class="sh-mainpanel">

      <div class="sh-pagebody">

        <!-- ✅ Public Posts: Create / Edit -->
        <div class="row row-sm">
          <div class="col-lg-12">
            <div class="card bd-primary">
              <!-- <div class="card-header">
                <h6 class="card-title mb-0"><?= $editPost ? 'Edit Post' : 'Create Post' ?></h6>
                <small class="text-muted">Upload image/video or write text. description shows in the Feed sidebar.</small>
              </div> -->

              <!-- ✅ IMPORTANT: removed id="recentPostsList" from here -->
              <div class="card-body">
                <?php if (!empty($_GET['err'])): ?>
                  <div class="alert alert-danger">
                    <?php if (($_GET['err'] ?? '') === 'upload'): ?>
                      Could not attach your file. Try JPG, PNG, MP4, or WebM under 50MB. If the filename extension does not match the file type, rename it and try again.
                    <?php else: ?>
                      Could not save post. Please try again.
                    <?php endif; ?>
                  </div>
                <?php endif; ?>
                <?php if ($categoryFlash === 'saved'): ?>
                  <div class="alert alert-success">New category created. It is now available in the post category dropdown.</div>
                <?php elseif ($categoryFlash === 'empty'): ?>
                  <div class="alert alert-warning">Category name is required.</div>
                <?php elseif ($categoryFlash === 'error'): ?>
                  <div class="alert alert-danger">Category could not be created. Please try again.</div>
                <?php endif; ?>

                <div class="card mb-3">
                  <div class="card-body">
                    <!-- <h6 class="mb-1">Create New Category / Folder</h6>
                    <small class="text-muted d-block mb-3">Create folders for videos, photos, descriptions/topics, mixed media, or files. New categories appear immediately in the post category dropdown below and in Gallery.</small> -->
                    <form action="dashboard.php<?php
                      $formParams = [];
                      if ($isModalCreate) $formParams['modal'] = 1;
                      if ($editPost) $formParams['edit'] = (int)$editPost['id'];
                      echo !empty($formParams) ? ('?' . http_build_query($formParams)) : '';
                    ?>" method="post">
                      <?php echo csrfInput(); ?>
                      <input type="hidden" name="action" value="create_category">
                      <div class="form-row">
                        <div class="form-group col-md-6">
                          <label>Category Name</label>
                          <input type="text" name="category_name" class="form-control" maxlength="120" placeholder="e.g., Sports Clips, Family Photos, Daily Thoughts">
                        </div>
                        <div class="form-group col-md-4">
                          <label>Category Type</label>
                          <select name="category_type" class="form-control">
                            <option value="video">Video</option>
                            <option value="photo">Photo</option>
                            <option value="topic">Description / Topic</option>
                            <option value="mixed">Mixed Media</option>
                            <option value="file">Files</option>
                          </select>
                        </div>
                        <div class="form-group col-md-2 d-flex align-items-end">
                          <button type="submit" class="btn btn-outline-primary btn-block">Add Category</button>
                        </div>
                      </div>
                    </form>
                  </div>
                </div>

                <!-- ✅ HOW TO CREATE A POST (Instructions) -->
                <div class="alert alert-info create-post-type-box" style="border-left:4px solid #0861bc;background:<?php echo $isModalCreate ? $modalPageBgCss : 'var(--msb-palette-bg, #f5f7fb)'; ?> !important;color:<?php echo $isModalCreate ? $modalPageTextCss : 'var(--msb-palette-text, #0f172a)'; ?> !important;">
                  <!-- <div style="font-weight:900; font-size:15px; margin-bottom:6px;">
                    How to create a new post (Mobile/Tablet Feed)
                  </div> -->

                  <div style="font-size:13px; margin-bottom:10px;color:inherit;">
                    Select a post type so your Feed card layout looks correct:
                  </div>

                  <select id="postTypeSelector" class="form-control form-control-sm" style="max-width:620px;">
                    <option value="">Select an option…</option>
                    <option value="1">1) Title + Media/File only</option>
                    <option value="2">2) Title + Media/File + Long description (Book layout)</option>
                    <option value="3">3) Media/File with optional Title + optional Long description</option>
                    <option value="4">4) Media/File only (Title optional)</option>
                    <option value="5">5) Long description only (text post)</option>
                    <option value="6">6) Title only</option>
                    <option value="7">7) Reel post: single image or video with description at bottom</option>
                  </select>


                  <div class="form-check mt-2" style="font-size:12.5px;">
                    <input class="form-check-input" type="checkbox" id="postTypeAutoDetect" checked>
                    <label class="form-check-label" for="postTypeAutoDetect">
                      Auto-detect post type from Title / Body / Attachments (recommended)
                    </label>
                  </div>
                  <div id="instructionBox" class="mt-2" style="font-size:13px; line-height:1.55; white-space:pre-line;"></div>

                  <div class="mt-2" style="font-size:12px;">
                    <strong>Read more (Mobile/Tablet):</strong>
                    shows only when your <strong>Body</strong> has <strong>10+ sentences</strong>. It appears inline at the end of the truncated text (no modal).
                  </div>
                </div>

                <script>
                document.addEventListener('DOMContentLoaded', function(){
                  var sel = document.getElementById('postTypeSelector');
                  var box = document.getElementById('instructionBox');
                  var auto = document.getElementById('postTypeAutoDetect');
                  if(!sel || !box) return;

                  var i = {
                    1: "✅ Fill: Title\n✅ Upload: Image / Video / GIF / PDF / Doc / Ppt / any file\n✅ Leave empty: Body\n➡️ Feed card (mobile/tablet): Title at top, Media/File full width, actions under the media.",
                    2: "✅ Fill: Title\n✅ Upload: Image / Video / GIF / PDF / Doc / any file\n✅ Write: Long description in Body\n➡️ Feed card (mobile/tablet): Title top, Media center, Body under media, actions under body.",
                    3: "✅ Upload: Media/File (Title optional)\n✅ Body optional\n➡️ Feed card (mobile/tablet): Title (if provided) at top, Media/File full width, Body (if provided) under media, actions under content.",
                    4: "✅ Upload: Media/File only\n✅ Title optional\n✅ Leave empty: Body\n➡️ Feed card (mobile/tablet): Media/File full width, actions under media (Title if provided appears at top).",
                    5: "✅ Write: Body (text post)\n✅ Title optional\n✅ No attachments\n➡️ Feed card (mobile/tablet): Title top (if any), Body full, actions under body.\n\n📌 Read more appears only if Body has 10+ sentences.",
                    6: "✅ Write: Title only\n✅ No attachments required\n➡️ Feed card: simple title/text style without media.",
                    7: "✅ Upload: One image OR one video\n✅ Title optional\n✅ Body optional\n✅ Choose Reel in the special feed layout below\n➡️ Public reel: media keeps the tall reel layout and the description appears at the bottom of the image/video."
                  };

                  function render(){
                    var v = String(sel.value || '');
                    box.textContent = (i[v] || "");
                  }

                  // --- auto-detect helpers ---
                  function q(selStr){ return document.querySelector(selStr); }
                  var layoutSel = document.querySelector('select[name="layout_override"]');
                  function valByName(name){
                    var el = document.querySelector('[name="'+name+'"]');
                    return el ? String(el.value||'').trim() : '';
                  }
                  function filesCount(){
                    var f = document.querySelector('input[type="file"][name="attachments[]"]');
                    return (f && f.files) ? (f.files.length||0) : 0;
                  }
                  function firstFileKind(){
                    var f = document.querySelector('input[type="file"][name="attachments[]"]');
                    if(!(f && f.files && f.files.length)) return '';
                    var file = f.files[0];
                    var type = String((file && file.type) || '').toLowerCase();
                    var name = String((file && file.name) || '').toLowerCase();
                    if(type.indexOf('image/') === 0 || /\.(jpg|jpeg|png|gif|webp|bmp|svg)$/i.test(name)) return 'image';
                    if(type.indexOf('video/') === 0 || /\.(mp4|webm|ogg|mov|m4v)$/i.test(name)) return 'video';
                    return 'file';
                  }
                  function syncLayoutOverride(){
                    if(!layoutSel) return;
                    if(String(sel.value || '') === '7'){
                      layoutSel.value = 'media_reel_bottom';
                    }else if(auto && auto.checked){
                      layoutSel.value = '';
                    }
                  }
                  function detectType(){
                    var title = valByName('title');
                    var body  = valByName('body');
                    var count = filesCount();
                    var hasFiles = count > 0;
                    var hasBody  = body.length > 0;
                    var hasTitle = title.length > 0;
                    var onlyTitle = (title.length > 0 && !hasFiles && !hasBody);
                    var kind = firstFileKind();

                    if(count === 1 && (kind === 'image' || kind === 'video') && hasBody){
                      return '7';
                    }
                    if(hasFiles && hasBody){
                      return hasTitle ? '2' : '3';
                    }
                    if(hasFiles && !hasBody){
                      return hasTitle ? '1' : '4';
                    }
                    if(!hasFiles && hasBody){
                      return '5';
                    }
                    if(onlyTitle){
                      return '6';
                    }
                    return '';
                  }

                  function applyAuto(){
                    if(auto && !auto.checked) return;
                    var t = detectType();
                    if(t && sel.value !== t){
                      sel.value = t;
                    }
                    render();
                    syncLayoutOverride();
                  }

                  sel.addEventListener('change', function(){
                    if(auto) auto.checked = false; // manual override
                    render();
                    syncLayoutOverride();
                  });

                  if(auto){
                    auto.addEventListener('change', function(){
                      if(auto.checked) applyAuto();
                    });
                  }
                  if(layoutSel){
                    layoutSel.addEventListener('change', function(){
                      if(String(layoutSel.value || '') === 'media_reel_bottom'){
                        sel.value = '7';
                        render();
                      }
                    });
                  }

                  // watch relevant fields
                  ['title','body'].forEach(function(n){
                    var el = document.querySelector('[name="'+n+'"]');
                    if(el){
                      el.addEventListener('input', applyAuto);
                      el.addEventListener('change', applyAuto);
                    }
                  });
                  var fileEl = document.querySelector('input[type="file"][name="attachments[]"]');
                  if(fileEl){
                    fileEl.addEventListener('change', applyAuto);
                  }

                  // initial render + auto
                  render();
                  applyAuto();
                });
                </script>

                <form id="createPostForm" action="post_save.php" method="post" enctype="multipart/form-data" data-modal="<?= $isModalCreate ? '1' : '0' ?>">
                  <?php echo csrfInput(); ?>
                  <input type="hidden" name="ajax" value="1">
                  <input type="hidden" name="post_id" id="createPostId" value="<?= (int)($editPost['id'] ?? 0) ?>">
                  <input type="hidden" name="device_label" value="">
                  <input type="hidden" name="device_viewport" value="">
                  <input type="hidden" name="return_to" id="createPostReturnTo" value="feed.php">
                  <div id="pendingUploadTokens"></div>
                  <?php if ($isPublisherAccount): ?>
                  <input type="hidden" name="publisher_account" value="1">
                  <?php endif; ?>

                  <?php if ($isStoryCreate): ?>
                  <input type="hidden" name="layout_override" value="story">
                  <?php endif; ?>
                  <?php if ($editPost): ?>
                  <div class="alert alert-secondary py-2 px-3 mb-3" style="font-size:13px;">
                    Editing post #<?= (int)$editPost['id'] ?><?= $editAttachmentCount > 0 ? (' · ' . $editAttachmentCount . ' existing media file' . ($editAttachmentCount === 1 ? '' : 's') . ' kept unless you add new ones') : '' ?>.
                    Change <strong>Friends</strong> / <strong>Public</strong> above to move this post between feed and public.
                  </div>
                  <?php endif; ?>
                  <div class="form-row">
                    <div class="form-group col-md-6">
                      <label>Title</label>
                      <input type="text" name="title" class="form-control" maxlength="120"
                        value="<?= h((string)($editPost['title'] ?? '')) ?>" placeholder="<?= $isStoryCreate ? 'e.g., My story moment…' : 'e.g., Today’s thought…' ?>">
                      <small class="text-muted"><?= $isStoryCreate ? 'Optional for stories. Add a title, description, photo, or video.' : 'Tip: For media-only posts (image/pdf/video/file), use a clear title. description is optional.' ?></small>
                    </div>
                  <div class="form-group col-md-6">
                      <label><?= $isStoryCreate ? 'Story Audience' : 'Post Destination' ?></label>
                      <?php if ($isPublisherAccount): ?>
                        <input type="hidden" name="visibility" value="public">
                        <div class="form-control msb-readonly-field" style="font-weight:700">Public — publisher posts</div>
                        <small class="text-muted"><strong>feed.php</strong> = your publisher feed &amp; followers. <strong>public.php</strong> = discovery for people who have not followed you. <strong>news.php</strong> = publisher news browse (not a personal post destination). After submit you return to feed.php.</small>
                      <?php else: ?>
                      <?php $vis = (string)($editPost['visibility'] ?? 'friends'); ?>
                      <select name="visibility" id="createPostVisibility" class="form-control">
                        <option value="friends" <?= $vis==='friends'?'selected':'' ?>>Friends</option>
                        <option value="public" <?= $vis==='public'?'selected':'' ?>>Public</option>
                      </select>
                      <small class="text-muted"><strong>feed.php</strong> = friends posts. <strong>public.php</strong> = public posts for everyone. <strong>news.php</strong> = publisher news browse only (not a create destination).</small>
                      <?php endif; ?>
                    </div>
                  </div>
                  <div class="form-row">
                    <div class="form-group col-md-6">
                      <label>Music title (optional)</label>
                      <input type="text" name="music_title" class="form-control" maxlength="120"
                        value="<?= h((string)($editPost['music_title'] ?? '')) ?>" placeholder="e.g., Me &amp; My Jesus">
                      <small class="text-muted">Shown under your name on feed and public post cards.</small>
                    </div>
                    <div class="form-group col-md-6">
                      <label>Music artist (optional)</label>
                      <input type="text" name="music_artist" class="form-control" maxlength="120"
                        value="<?= h((string)($editPost['music_artist'] ?? '')) ?>" placeholder="e.g., Noël Mio">
                    </div>
                  </div>
                  <?php if (!$isStoryCreate): ?>
                  <div class="form-group">
                    <label>Special Feed Layout (optional)</label>
                    <select name="layout_override" class="form-control">
                      <option value="">Standard auto layout</option>
                      <option value="image_bottom" <?= $currentLayoutOverride==='image_bottom' ? 'selected' : '' ?>>Image only: description at bottom of image</option>
                      <option value="media_reel_bottom" <?= $currentLayoutOverride==='media_reel_bottom' ? 'selected' : '' ?>>Reel: single image or video with description at bottom</option>
                    </select>
                    <small class="text-muted">Choose Reel when you want a single image or video to open in the style reel layout on public posts, with the caption anchored at the bottom of the media.</small>
                  </div>
                  <?php endif; ?>
                  <div class="form-group">
                    <label>Category / Folder</label>
                    <select name="category_id" class="form-control">
                      <option value="0">Auto category by post type</option>
                      <?php foreach ($postCategories as $cat): ?>
                        <?php $catId = (int)($cat['id'] ?? 0); ?>
                        <option value="<?= $catId ?>" <?= $currentCategoryId === $catId ? 'selected' : '' ?>>
                          <?= h((string)($cat['name'] ?? 'Category')) ?> (<?= h(postCategoryTypeLabel((string)($cat['category_type'] ?? 'topic'))) ?>)
                        </option>
                      <?php endforeach; ?>
                    </select>
                    <small class="text-muted">Auto puts video posts into Video Category, photo posts into Photo Category, text/description posts into Topic Category, and mixed uploads into Mixed Category.</small>
                  </div>
                  <input type="hidden" name="description" value="">
<div class="form-group">
                    <label>Body (optional — leave empty for media-only posts)</label>
                    <textarea name="body" class="form-control" rows="4" placeholder="Write your post…"><?= h($editBodyText) ?></textarea>
                  </div>

                  <div class="form-group">
                    <label>Upload Media / Files (optional)</label>
                    <input type="file" id="createPostAttachments" name="attachments[]" class="form-control" multiple accept="image/*,video/*,application/pdf,.pdf,.gif,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.txt,.zip">
                    <div id="createPostUploadStatus" class="small text-muted mt-1" aria-live="polite"><?php
                      if ($editPost && $editAttachmentCount > 0) {
                          echo h($editAttachmentCount . ' file' . ($editAttachmentCount === 1 ? '' : 's') . ' already on this post. New uploads are added; existing media stays.');
                      }
                    ?></div>
                    <div id="createPostUploadProgress" class="progress mt-2" style="height:6px;display:none;overflow:hidden;border-radius:999px;background:rgba(15,23,42,.08);">
                      <div id="createPostUploadBar" class="progress-bar" role="progressbar" style="width:0%;height:100%;transition:width .18s linear;background:#2563eb;"></div>
                    </div>
                    <small class="text-muted">Images are optimized and upload as soon as you pick them so Submit is fast.</small>
                  </div>

                  <div class="d-flex align-items-center">
                    <!-- Edit/Post -->
                    <button type="submit" id="createPostSubmitBtn" class="btn btn-primary mr-2"><?= $editPost ? '' : '' ?><i class="icon ion-arrow-up-a" style="font-size:20px;"></i></button>
                    <?php if ($editPost || $isModalCreate): ?>
                      <a href="dashboard.php<?php echo $isModalCreate ? ('?modal=1' . ($isStoryCreate ? '&story=1' : '') . ($editPost ? ('&edit=' . (int)$editPost['id']) : '')) : ''; ?>" class="btn btn-outline-secondary"<?= $isModalCreate ? ' onclick="try{if(window.parent&&window.parent.MSBCreatePostModal){window.parent.MSBCreatePostModal.close();return false;}}catch(_e){}"' : '' ?>>Cancel</a>
                    <?php endif; ?>
                    <?php if (!$isModalCreate): ?>
                      <a href="feed.php" class="btn btn-outline-primary ml-auto<?= $isPublisherAccount ? '' : ' mr-2' ?>"><?= $isPublisherAccount ? 'Back to Feed' : 'Go to Feed' ?></a>
                      <?php if (!$isPublisherAccount): ?>
                      <a href="public.php" class="btn btn-outline-dark">Go to Public</a>
                      <?php endif; ?>
                    <?php endif; ?>
                  </div>
                </form>
              </div>
            </div>
          </div>
        </div>

        <!-- ✅ My Recent Posts (NO LIST ON PAGE; Modal only) -->
        <!-- <div class="row row-sm mg-t-20">
          <div class="col-lg-12">
            <div class="card"> -->
              <!-- <div class="card-header d-flex align-items-center justify-content-between">
                <h6 class="card-title mb-0">My Recent Posts</h6>

                <div class="d-flex align-items-center">
                  <small class="text-muted mr-2"><?= count($myPosts) ?> shown</small>
                  <button type="button" class="btn btn-sm btn-outline-primary" data-toggle="modal" data-target="#recentPostsModal">
                    <i class="fa fa-eye"></i> Open
                  </button>
                </div>
              </div> -->

              <!-- ✅ No list here -->
              <!-- <div class="card-body">
                <small class="text-muted">
                  Click <strong>Open</strong> to view your recent posts.
                </small>
              </div> -->
            <!-- </div>
          </div>
        </div> -->

      </div><!-- sh-pagebody -->

      <!-- <div class="sh-footer">
        <div>Copyright &copy; 2017. All Rights Reserved. Talentra</div>
        <div class="mg-t-10 mg-md-t-0">Designed by: <a href="http://themepixels.me">ThemePixels</a></div>
      </div> -->
    </div><!-- sh-mainpanel -->

    

    <!-- ✅ Modal: My Recent Posts (LIST IS HERE ONLY) -->
    <div class="modal fade" id="recentPostsModal" tabindex="-1" role="dialog" aria-labelledby="recentPostsModalLabel" aria-hidden="true">
      <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">

          <div class="modal-header">
            <h5 class="modal-title" id="recentPostsModalLabel">My Recent Posts</h5>
            <button type="button" class="close" data-dismiss="modal" aria-label="Close">
              <span aria-hidden="true">&times;</span>
            </button>
          </div>

          <div class="modal-body" style="max-height:70vh; overflow:auto;">

            <?php if (!$myPosts): ?>
              <div class="p-3 text-muted">No posts yet. Create your first post above.</div>
            <?php else: ?>
              <?php foreach ($myPosts as $p): ?>
                <div class="rp-item">
                  <div class="d-flex">
                    <div class="mr-3">
                      <?php if (!empty($p['preview_path']) && $p['preview_type']==='image'): ?>
                        <img class="rp-thumb" src="<?= h((string)$p['preview_path']) ?>" alt="">
                      <?php elseif (!empty($p['preview_path']) && $p['preview_type']==='video'): ?>
                        <div class="d-flex align-items-center justify-content-center rp-thumb">
                          <i class="icon ion-ios-videocam" style="font-size:18px;"></i>
                        </div>
                      <?php else: ?>
                        <div class="d-flex align-items-center justify-content-center rp-thumb">
                          <i class="icon ion-ios-paper" style="font-size:18px;"></i>
                        </div>
                      <?php endif; ?>
                    </div>

                    <div class="flex-grow-1">
                      <div class="d-flex align-items-start justify-content-between">
                        <div>
                          <div style="font-weight:600;"><?= h((string)($p['title'] ?: '(Untitled)')) ?></div>
                          <div class="text-muted" style="font-size:13px;"><?= h(strip_layout_override_marker((string)($p['description'] ?? ''))) ?></div>
                        </div>
                        <small class="text-muted"><?= h((string)substr((string)($p['updated_at'] ?? $p['created_at'] ?? ''), 0, 16)) ?></small>
                      </div>

                      <div class="mt-2 d-flex align-items-center">
                        <span class="text-muted mr-3" style="font-size:13px;">
                          <?= (int)($p['comment_count'] ?? 0) ?> comments
                        </span>
                        <a class="btn btn-sm btn-outline-secondary mr-2" href="dashboard.php?edit=<?= (int)$p['id'] ?>">Edit</a>
                        <a class="btn btn-sm btn-outline-primary" href="feed.php?post=<?= (int)$p['id'] ?>">Open in Feed</a>
                      </div>
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>

          </div>

          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
            <a href="feed.php" class="btn btn-primary">Go to Feed</a>
          </div>
        </div>
      </div>
    </div>
    <!-- ✅ No "copy list into modal" JS needed anymore -->
  
<script>
function qaValidatePost(form){
  // Title is OPTIONAL for all post types.
  return true;
}

document.addEventListener('DOMContentLoaded', function(){
  const f = document.getElementById('createPostForm') || document.querySelector('form[action="post_save.php"]');
  if (!f) return;

  if (!f.dataset.qaBound) {
    f.dataset.qaBound = '1';
  }
  if (window.MSBDeviceProfile && typeof window.MSBDeviceProfile.bindForm === 'function') {
    window.MSBDeviceProfile.bindForm(f);
  }

  const fileInput = document.getElementById('createPostAttachments') || f.querySelector('input[type="file"][name="attachments[]"]');
  const tokenBox = document.getElementById('pendingUploadTokens');
  const statusEl = document.getElementById('createPostUploadStatus');
  const progressWrap = document.getElementById('createPostUploadProgress');
  const progressBar = document.getElementById('createPostUploadBar');
  const submitBtn = document.getElementById('createPostSubmitBtn') || f.querySelector('button[type="submit"]');
  const csrfInput = f.querySelector('input[name="csrf_token"]');
  const visibilitySel = document.getElementById('createPostVisibility') || f.querySelector('select[name="visibility"]');
  const returnToInput = document.getElementById('createPostReturnTo') || f.querySelector('input[name="return_to"]');
  const isModal = f.getAttribute('data-modal') === '1';
  let pendingCount = 0;
  let uploading = false;
  let activeUploads = 0;
  let progressDisplay = 0;
  let progressTarget = 0;
  let progressRaf = 0;

  function syncReturnToFromVisibility(){
    if (!returnToInput) return;
    // Publisher accounts keep feed.php. Personal: public → public.php, friends → feed.php.
    const hasPublisherFlag = !!(f.querySelector('input[name="publisher_account"]'));
    if (hasPublisherFlag) {
      returnToInput.value = 'feed.php';
      return;
    }
    const vis = visibilitySel ? String(visibilitySel.value || 'friends') : 'friends';
    returnToInput.value = (vis === 'public') ? 'public.php' : 'feed.php';
  }
  syncReturnToFromVisibility();
  if (visibilitySel) {
    visibilitySel.addEventListener('change', syncReturnToFromVisibility);
  }

  function setStatus(msg, isError){
    if (!statusEl) return;
    statusEl.textContent = msg || '';
    statusEl.style.color = isError ? '#b91c1c' : '';
  }

  function paintProgress(){
    progressRaf = 0;
    const diff = progressTarget - progressDisplay;
    if (Math.abs(diff) < 0.4) {
      progressDisplay = progressTarget;
    } else {
      progressDisplay += diff * 0.35;
      progressRaf = requestAnimationFrame(paintProgress);
    }
    if (!progressWrap || !progressBar) return;
    const show = progressTarget > 0 || uploading;
    progressWrap.style.display = show ? 'block' : 'none';
    progressBar.style.width = Math.max(0, Math.min(100, progressDisplay)) + '%';
  }

  function setProgress(pct, show){
    if (!show) {
      progressTarget = 0;
      progressDisplay = 0;
      if (progressWrap) progressWrap.style.display = 'none';
      if (progressBar) progressBar.style.width = '0%';
      return;
    }
    progressTarget = Math.max(0, Math.min(100, pct || 0));
    if (!progressRaf) progressRaf = requestAnimationFrame(paintProgress);
  }

  function syncSubmitEnabled(){
    if (!submitBtn) return;
    submitBtn.disabled = uploading;
    submitBtn.style.opacity = uploading ? '0.7' : '';
  }

  function addToken(token){
    if (!tokenBox || !token) return;
    const input = document.createElement('input');
    input.type = 'hidden';
    input.name = 'pending_tokens[]';
    input.value = token;
    input.dataset.pendingToken = '1';
    tokenBox.appendChild(input);
    pendingCount++;
  }

  function formatBytes(n){
    n = Number(n || 0);
    if (n < 1024) return n + ' B';
    if (n < 1024 * 1024) return (n / 1024).toFixed(0) + ' KB';
    return (n / (1024 * 1024)).toFixed(1) + ' MB';
  }

  // Compress large photos before upload so the progress bar finishes in seconds.
  function compressImageFile(file){
    return new Promise(function(resolve){
      try {
        if (!file || !/^image\/(jpeg|jpg|png|webp)$/i.test(file.type || '')) {
          resolve(file);
          return;
        }
        // Skip tiny files and animated gif.
        if (file.size < 450 * 1024) {
          resolve(file);
          return;
        }
        const url = URL.createObjectURL(file);
        const img = new Image();
        img.onload = function(){
          URL.revokeObjectURL(url);
          const maxEdge = 1600;
          let w = img.naturalWidth || img.width || 0;
          let h = img.naturalHeight || img.height || 0;
          if (!w || !h) {
            resolve(file);
            return;
          }
          const scale = Math.min(1, maxEdge / Math.max(w, h));
          w = Math.max(1, Math.round(w * scale));
          h = Math.max(1, Math.round(h * scale));
          const canvas = document.createElement('canvas');
          canvas.width = w;
          canvas.height = h;
          const ctx = canvas.getContext('2d', { alpha: false });
          if (!ctx) {
            resolve(file);
            return;
          }
          ctx.drawImage(img, 0, 0, w, h);
          canvas.toBlob(function(blob){
            if (!blob || blob.size >= file.size * 0.95) {
              resolve(file);
              return;
            }
            const base = String(file.name || 'photo').replace(/\.[^.]+$/, '');
            resolve(new File([blob], base + '.jpg', { type: 'image/jpeg', lastModified: Date.now() }));
          }, 'image/jpeg', 0.78);
        };
        img.onerror = function(){
          URL.revokeObjectURL(url);
          resolve(file);
        };
        img.src = url;
      } catch (_e) {
        resolve(file);
      }
    });
  }

  function uploadOneFile(file, onByteProgress){
    return new Promise(function(resolve){
      const csrf = csrfInput ? String(csrfInput.value || '') : '';
      const fd = new FormData();
      if (csrf) fd.append('csrf_token', csrf);
      fd.append('attachments[]', file, file.name);

      const xhr = new XMLHttpRequest();
      xhr.open('POST', 'ajax/post_media_upload.php', true);
      xhr.responseType = 'json';
      xhr.upload.onprogress = function(ev){
        if (!ev.lengthComputable) return;
        if (typeof onByteProgress === 'function') onByteProgress(ev.loaded, ev.total);
      };
      xhr.onload = function(){
        let data = xhr.response;
        if (!data || typeof data !== 'object') {
          try { data = JSON.parse(xhr.responseText || '{}'); } catch (_e) { data = null; }
        }
        if (!data || !data.ok || !Array.isArray(data.files) || !data.files.length) {
          resolve({ ok: false });
          return;
        }
        resolve({ ok: true, files: data.files });
      };
      xhr.onerror = function(){ resolve({ ok: false }); };
      xhr.send(fd);
    });
  }

  function uploadSelectedFiles(fileList){
    if (!fileList || !fileList.length) return;
    const files = Array.prototype.slice.call(fileList);
    uploading = true;
    activeUploads = files.length;
    syncSubmitEnabled();
    progressDisplay = 0;
    progressTarget = 2;
    setProgress(2, true);
    setStatus('Preparing ' + files.length + ' file' + (files.length > 1 ? 's' : '') + '…', false);

    // Clear input immediately so picking the same file again still works,
    // and submit never re-sends original bytes.
    if (fileInput) fileInput.value = '';

    const sizes = files.map(function(){ return 1; });
    const loaded = files.map(function(){ return 0; });
    const totals = files.map(function(){ return 1; });

    // Sequential uploads avoid PHP session-lock stalls; images are pre-compressed so each is quick.
    let chain = Promise.resolve({ okFiles: 0 });
    files.forEach(function(file, idx){
      chain = chain.then(function(acc){
        setStatus('Optimizing ' + (idx + 1) + '/' + files.length + '…', false);
        setProgress(Math.max(8, 8 + (idx / files.length) * 10), true);
        return compressImageFile(file).then(function(out){
          sizes[idx] = out.size || file.size || 1;
          totals[idx] = sizes[idx];
          loaded[idx] = 0;
          setStatus('Uploading ' + (idx + 1) + '/' + files.length + ' (' + formatBytes(out.size) + ')…', false);
          return uploadOneFile(out, function(byteLoaded, byteTotal){
            totals[idx] = Math.max(1, byteTotal || sizes[idx]);
            loaded[idx] = Math.min(totals[idx], byteLoaded || 0);
            // Weight each file equally in the bar so multi-file progress feels even.
            let fileFrac = 0;
            for (let i = 0; i < files.length; i++) {
              const t = Math.max(1, totals[i]);
              const l = (i < idx) ? t : (i === idx ? loaded[i] : 0);
              fileFrac += (l / t) / files.length;
            }
            setProgress(12 + Math.round(fileFrac * 80), true);
          }).then(function(res){
            loaded[idx] = totals[idx];
            if (res && res.ok && res.files) {
              res.files.forEach(function(item){
                if (item && item.token) {
                  addToken(String(item.token));
                  acc.okFiles++;
                }
              });
            }
            return acc;
          });
        });
      });
    });

    chain.then(function(acc){
      uploading = false;
      activeUploads = 0;
      syncSubmitEnabled();
      if (!acc || acc.okFiles <= 0) {
        setStatus('Upload failed. You can still submit and files will upload then.', true);
        setTimeout(function(){ setProgress(0, false); }, 700);
        return;
      }
      progressTarget = 100;
      setProgress(100, true);
      setStatus(pendingCount + ' file' + (pendingCount > 1 ? 's' : '') + ' ready — click submit.', false);
      setTimeout(function(){ setProgress(0, false); }, 450);
    }).catch(function(){
      uploading = false;
      activeUploads = 0;
      syncSubmitEnabled();
      setStatus('Network error while uploading. Try again or submit to upload on save.', true);
      setProgress(0, false);
    });
  }

  if (fileInput) {
    fileInput.addEventListener('change', function(){
      if (fileInput.files && fileInput.files.length) {
        uploadSelectedFiles(fileInput.files);
      }
    });
  }

  f.addEventListener('submit', function(ev){
    if (!qaValidatePost(f)) {
      ev.preventDefault();
      ev.stopPropagation();
      return;
    }
    // Always use AJAX save so modal can return to feed without a slow full reload mid-upload.
    ev.preventDefault();
    ev.stopPropagation();

    if (uploading) {
      setStatus('Please wait for media upload to finish…', true);
      return;
    }

    if (window.MSBDeviceProfile && typeof window.MSBDeviceProfile.bindForm === 'function') {
      window.MSBDeviceProfile.bindForm(f);
    }
    syncReturnToFromVisibility();

    const fd = new FormData(f);
    // Guarantee edit id is sent even if the hidden field was wiped by a partial DOM refresh.
    const postIdInput = document.getElementById('createPostId') || f.querySelector('input[name="post_id"]');
    const editPostId = postIdInput ? Number(postIdInput.value || 0) : 0;
    if (editPostId > 0) {
      fd.set('post_id', String(editPostId));
    }
    // If files were pre-uploaded, do not send empty file fields.
    if (pendingCount > 0 && fileInput && (!fileInput.files || !fileInput.files.length)) {
      fd.delete('attachments[]');
    }

    if (submitBtn) {
      submitBtn.disabled = true;
      submitBtn.style.opacity = '0.7';
    }
    setStatus('Publishing…', false);
    setProgress(30, true);

    const publishStarted = Date.now();
    const tick = setInterval(function(){
      const elapsed = Date.now() - publishStarted;
      setProgress(Math.min(90, 30 + elapsed / 40), true);
    }, 80);

    fetch('post_save.php', {
      method: 'POST',
      body: fd,
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json' }
    }).then(function(res){
      return res.json().catch(function(){ return null; }).then(function(data){
        return { res: res, data: data };
      });
    }).then(function(payload){
      clearInterval(tick);
      setProgress(100, true);
      const data = payload && payload.data;
      if (data && data.ok) {
        const target = String(data.redirect || ('feed.php?post=' + String(data.post_id || '') + '&fresh=1'));
        const postId = Number(data.post_id || 0);
        setStatus('Posted!', false);
        // Modal: soft-refresh parent feed (no full page reload) for ~2–3s publish.
        try {
          if (isModal && window.parent && window.parent !== window) {
            window.parent.postMessage({
              type: 'msb-create-post-done',
              postId: postId,
              redirect: target,
              story: !!(data.story),
              visibility: String(data.visibility || (returnToInput && returnToInput.value === 'public.php' ? 'public' : 'friends')),
              surface: String(data.surface || '')
            }, '*');
            // If parent handled it, this iframe is torn down. Otherwise hard-navigate.
            setTimeout(function(){
              try {
                if (window.top && window.top !== window) window.top.location.replace(target);
                else window.location.replace(target);
              } catch (_fb) {
                try { window.location.replace(target); } catch (_e2) {}
              }
            }, 1500);
            return;
          }
        } catch (_e) {}
        try { window.location.replace(target); } catch (_e3) { window.location.href = target; }
        return;
      }
      setProgress(0, false);
      if (submitBtn) {
        submitBtn.disabled = false;
        submitBtn.style.opacity = '';
      }
      setStatus((data && data.error) ? ('Could not save (' + data.error + ').') : 'Could not save post. Please try again.', true);
    }).catch(function(){
      clearInterval(tick);
      setProgress(0, false);
      if (submitBtn) {
        submitBtn.disabled = false;
        submitBtn.style.opacity = '';
      }
      setStatus('Network error while publishing. Please try again.', true);
    });
  });
});
</script>

</body>
</html>
