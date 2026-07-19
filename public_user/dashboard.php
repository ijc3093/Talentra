<?php
require_once __DIR__ . '/includes/session_user.php';
require_once __DIR__ . '/controller.php';
require_once __DIR__ . '/includes/post_categories.php';
require_once __DIR__ . '/includes/post_layout.php';
require_once __DIR__ . '/includes/device_profile.php';
require_once __DIR__ . '/includes/publisher_accounts.php';
require_once __DIR__ . '/includes/staff_publisher_access.php';
require_once __DIR__ . '/includes/theme_prefs.php';

error_reporting(E_ALL);
ini_set('display_errors', '0');

requireUserLogin();
staff_pub_deny_write();

$controller = new Controller();
$dbh = $controller->pdo();

$meId = (int)($_SESSION['user_id'] ?? 0);
$isModalCreate = (string)($_GET['modal'] ?? '') === '1';
$isStoryCreate = ((string)($_GET['story'] ?? '') === '1');
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
<html lang="en">
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
  background:#171d24 !important;
  color:var(--msb-palette-text, #b1bcce) !important;
  border-color:var(--msb-palette-border, rgba(177,188,206,.18)) !important;
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
  background:#171d24 !important;
  border-color:rgba(177,188,206,.22) !important;
  color:var(--msb-palette-text, #b1bcce) !important;
}

<?php if ($isModalCreate): ?>
html, body{
  height:100% !important;
  min-height:0 !important;
  background:#171d24 !important;
  color:var(--msb-palette-text, #b1bcce) !important;
}

html[data-theme="light"]:not([data-msb-appearance]) body.dashboard-modal-page,
html[data-theme="light"]:not([data-msb-appearance]) body.dashboard-modal-page .sh-mainpanel,
html[data-theme="light"]:not([data-msb-appearance]) body.dashboard-modal-page .sh-pagebody,
html[data-theme="light"]:not([data-msb-appearance]) body.dashboard-modal-page .card,
html[data-theme="light"]:not([data-msb-appearance]) body.dashboard-modal-page .card-body{
  background:#171d24 !important;
  color:var(--msb-palette-text, #b1bcce) !important;
  border-color:var(--msb-palette-border, rgba(177,188,206,.18)) !important;
}

html[data-theme="light"]:not([data-msb-appearance]) body.dashboard-modal-page label,
html[data-theme="light"]:not([data-msb-appearance]) body.dashboard-modal-page .card-title,
html[data-theme="light"]:not([data-msb-appearance]) body.dashboard-modal-page h6{
  color:var(--msb-palette-text, #0f172a) !important;
}

html[data-theme="light"]:not([data-msb-appearance]) body.dashboard-modal-page .text-muted,
html[data-theme="light"]:not([data-msb-appearance]) body.dashboard-modal-page small{
  color:var(--msb-palette-text-muted, #64748b) !important;
}

html[data-theme="light"]:not([data-msb-appearance]) body.dashboard-modal-page .form-control{
  background:#171d24 !important;
  color:var(--msb-palette-text, #b1bcce) !important;
  border-color:var(--msb-palette-border, rgba(177,188,206,.22)) !important;
}

html[data-theme="light"]:not([data-msb-appearance]) body.dashboard-modal-page .alert-info{
  background:#171d24 !important;
  border-color:rgba(177,188,206,.22) !important;
  color:var(--msb-palette-text, #b1bcce) !important;
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
                <div class="alert alert-info" style="border-left:4px solid #0861bc;">
                  <!-- <div style="font-weight:900; font-size:15px; margin-bottom:6px;">
                    How to create a new post (Mobile/Tablet Feed)
                  </div> -->

                  <div style="font-size:13px; margin-bottom:10px;">
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

                <form action="post_save.php" method="post" enctype="multipart/form-data"<?php echo $isModalCreate ? ' target="_top"' : ''; ?>>
                  <?php echo csrfInput(); ?>
                  <input type="hidden" name="post_id" value="<?= (int)($editPost['id'] ?? 0) ?>">
                  <input type="hidden" name="device_label" value="">
                  <input type="hidden" name="device_viewport" value="">
                  <?php if ($isPublisherAccount): ?>
                  <input type="hidden" name="return_to" value="feed.php">
                  <input type="hidden" name="publisher_account" value="1">
                  <?php endif; ?>

                  <?php if ($isStoryCreate): ?>
                  <input type="hidden" name="layout_override" value="story">
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
                        <div class="form-control msb-readonly-field" style="font-weight:700">Public audience — visible on public.php, news.php, and in followers’ feeds</div>
                        <small class="text-muted">Publisher workspace: one public post from here appears on <strong>feed.php</strong>, <strong>public.php</strong>, and <strong>news.php</strong> at the same time. Personal users cannot post to news.php. After submit, you return to feed.php.</small>
                      <?php else: ?>
                      <?php $vis = (string)($editPost['visibility'] ?? 'friends'); ?>
                      <select name="visibility" class="form-control">
                        <option value="friends" <?= $vis==='friends'?'selected':'' ?>><?= $isStoryCreate ? 'Friends (story circle on feed.php)' : 'Friends to Friends (goes to feed.php only)' ?></option>
                        <option value="public" <?= $vis==='public'?'selected':'' ?>><?= $isStoryCreate ? 'Public (story circle on public.php)' : 'Public (goes to public.php only)' ?></option>
                      </select>
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
                    <textarea name="body" class="form-control" rows="4" placeholder="Write your post…"><?= h((string)($editPost['body'] ?? '')) ?></textarea>
                  </div>

                  <div class="form-group">
                    <label>Upload Media / Files (optional)</label>
                    <input type="file" name="attachments[]" class="form-control" multiple accept="image/*,video/*,application/pdf,.pdf,.gif,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.txt,.zip">
                    <small class="text-muted">You can upload more than one file. Images, videos, GIFs, PDFs, and common office files.</small>
                  </div>

                  <div class="d-flex align-items-center">
                    <!-- Edit/Post -->
                    <button type="submit" class="btn btn-primary mr-2"><?= $editPost ? '' : '' ?><i class="icon ion-arrow-up-a" style="font-size:20px;"></i></button>
                    <?php if ($editPost): ?>
                      <a href="dashboard.php<?php echo $isModalCreate ? ('?modal=1' . ($isStoryCreate ? '&story=1' : '')) : ''; ?>" class="btn btn-outline-secondary">Cancel</a>
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
// attach to first form
document.addEventListener('DOMContentLoaded', function(){
  const f = document.querySelector('form[action="post_save.php"]');
  if(f && !f.dataset.qaBound){
    f.dataset.qaBound = "1";
    f.addEventListener('submit', function(ev){
      if(!qaValidatePost(f)){ ev.preventDefault(); ev.stopPropagation(); }
    });
  }
  if(window.MSBDeviceProfile && typeof window.MSBDeviceProfile.bindForm === 'function'){
    window.MSBDeviceProfile.bindForm(f);
  }
});
</script>

</body>
</html>
