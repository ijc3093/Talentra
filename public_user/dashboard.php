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
require_once __DIR__ . '/includes/post_upload.php';
require_once __DIR__ . '/includes/post_tags.php';

error_reporting(E_ALL);
ini_set('display_errors', '0');

requireUserLogin();
staff_pub_deny_write();

$controller = new Controller();
$dbh = $controller->pdo();

$meId = (int)($_SESSION['user_id'] ?? 0);
$composerName = trim((string)($_SESSION['user_name'] ?? $_SESSION['name'] ?? ''));
$composerUsername = trim((string)($_SESSION['username'] ?? $_SESSION['user_login'] ?? ''));
$composerEmail = trim((string)($_SESSION['user_email'] ?? $_SESSION['email'] ?? ''));
$composerFriendCode = strtoupper(trim((string)($_SESSION['user_friend_code'] ?? $_SESSION['friend_code'] ?? '')));
$composerImage = trim((string)($_SESSION['user_image'] ?? $_SESSION['image'] ?? ''));
if ($meId > 0 && ($composerName === '' || $composerImage === '' || $composerUsername === '' || $composerEmail === '' || $composerFriendCode === '')) {
    try {
        $stMe = $dbh->prepare("SELECT name, username, email, friend_code, COALESCE(NULLIF(image,''), '') AS image FROM users WHERE id = :id LIMIT 1");
        $stMe->execute([':id' => $meId]);
        $meRow = $stMe->fetch(PDO::FETCH_ASSOC) ?: [];
        if ($composerName === '') $composerName = trim((string)($meRow['name'] ?? ''));
        if ($composerUsername === '') $composerUsername = trim((string)($meRow['username'] ?? ''));
        if ($composerEmail === '') $composerEmail = trim((string)($meRow['email'] ?? ''));
        if ($composerFriendCode === '') $composerFriendCode = strtoupper(trim((string)($meRow['friend_code'] ?? '')));
        if ($composerImage === '') $composerImage = trim((string)($meRow['image'] ?? ''));
    } catch (Throwable $eMe) {}
}
if ($composerName === '') $composerName = $composerUsername !== '' ? $composerUsername : 'You';
$composerInitials = '';
foreach (preg_split('/\s+/', $composerName) as $part) {
    if ($part === '') continue;
    $composerInitials .= strtoupper(substr($part, 0, 1));
    if (strlen($composerInitials) >= 2) break;
}
if ($composerInitials === '') $composerInitials = 'U';
/* Same avatar endpoint as header/feed — resolves photo or renders initials SVG. */
$composerAvatarParams = [];
if ($meId > 0) $composerAvatarParams[] = 'u=' . rawurlencode((string)$meId);
if ($composerEmail !== '') $composerAvatarParams[] = 'email=' . rawurlencode($composerEmail);
if ($composerFriendCode !== '') $composerAvatarParams[] = 'friend_code=' . rawurlencode($composerFriendCode);
if ($composerUsername !== '') $composerAvatarParams[] = 'username=' . rawurlencode($composerUsername);
if ($composerName !== '') $composerAvatarParams[] = 'name=' . rawurlencode($composerName);
$composerAvatarParams[] = 's=84';
$composerAvatarUrl = 'avatar.php?' . implode('&', $composerAvatarParams);
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
post_layout_ensure_column($dbh);
publisher_ensure_schema($dbh);
require_once __DIR__ . '/includes/msb_feed_engagement.php';
msb_feed_engagement_ensure_schema($dbh);
$isPublisherAccount = publisher_account_is($dbh, $meId);

$composerSoundId = (int)($_GET['sound_id'] ?? 0);
$composerStitchOf = (int)($_GET['stitch'] ?? 0);
$composerDuetOf = (int)($_GET['duet'] ?? 0);
$composerSoundTitlePrefill = '';
$composerSoundArtistPrefill = '';
if ($composerSoundId > 0) {
    try {
        $stSnd = $dbh->prepare('SELECT title, artist FROM public_sounds WHERE id = :id LIMIT 1');
        $stSnd->execute([':id' => $composerSoundId]);
        $sndRow = $stSnd->fetch(PDO::FETCH_ASSOC) ?: [];
        $composerSoundTitlePrefill = (string)($sndRow['title'] ?? '');
        $composerSoundArtistPrefill = (string)($sndRow['artist'] ?? '');
    } catch (Throwable $eSnd) {}
}
$composerCommerceOrgId = 0;
$composerProducts = [];
if ($isPublisherAccount && is_file(__DIR__ . '/includes/org_shop.php')) {
    require_once __DIR__ . '/includes/org_shop.php';
    if (function_exists('org_shop_org_id_for_publisher')) {
        try {
            $composerCommerceOrgId = (int)org_shop_org_id_for_publisher($dbh, $meId);
            if ($composerCommerceOrgId > 0) {
                $stProd = $dbh->prepare("
                    SELECT id, title FROM org_products
                    WHERE org_id = :org AND COALESCE(is_deleted,0) = 0 AND LOWER(COALESCE(status,'')) IN ('','active','published','on')
                    ORDER BY updated_at DESC, id DESC
                    LIMIT 40
                ");
                $stProd->execute([':org' => $composerCommerceOrgId]);
                $composerProducts = $stProd->fetchAll(PDO::FETCH_ASSOC) ?: [];
            }
        } catch (Throwable $eOrg) {
            $composerProducts = [];
        }
    }
}

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
$editAttachments = [];
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
$editTaggedUsers = [];
if ($editPost) {
    $editBodyText = trim((string)($editPost['body'] ?? ''));
    if ($editBodyText === '') {
        $editBodyText = strip_layout_override_marker((string)($editPost['description'] ?? ''));
    }
    try {
        msb_post_tags_ensure_schema($dbh);
        $stTg = $dbh->prepare("
          SELECT u.id, u.username, u.name, COALESCE(NULLIF(u.image,''), '') AS image
          FROM public_post_tags t
          INNER JOIN users u ON u.id = t.tagged_user_id
          WHERE t.post_id = :pid
          ORDER BY u.username ASC
        ");
        $stTg->execute([':pid' => (int)$editPost['id']]);
        $editTaggedUsers = $stTg->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $editTaggedUsers = [];
    }
    try {
        if (function_exists('post_attachments_ensure_slide_columns')) {
            post_attachments_ensure_slide_columns($dbh);
        }
        $stAtt = $dbh->prepare("SELECT id, type, file_path, thumb_path, slide_title, slide_body FROM public_post_attachments WHERE post_id = :pid ORDER BY id ASC");
        $stAtt->execute([':pid' => (int)$editPost['id']]);
        $editAttachments = $stAtt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        try {
            $stAtt = $dbh->prepare("SELECT id, type, file_path, thumb_path FROM public_post_attachments WHERE post_id = :pid ORDER BY id ASC");
            $stAtt->execute([':pid' => (int)$editPost['id']]);
            $editAttachments = $stAtt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e2) {
            $editAttachments = [];
        }
    }
    $editAttachmentCount = count($editAttachments);
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
<html <?= app_html_lang_attrs() ?><?php echo $isModalCreate ? $modalAppearanceAttr : ''; ?>>
  <head>
    <!-- Required meta tags -->
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">

    <meta name="twitter:site" content="@themepixels">
    <meta name="twitter:creator" content="@themepixels">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="Talsora">
    <meta name="twitter:description" content="Premium Quality and Responsive UI for Dashboard.">
    <meta name="twitter:image" content="http://themepixels.me/shamcey/img/shamcey-social.png">

    <meta property="og:url" content="http://themepixels.me/shamcey">
    <meta property="og:title" content="Talsora">
    <meta property="og:description" content="Premium Quality and Responsive UI for Dashboard.">
    <meta property="og:image" content="http://themepixels.me/shamcey/img/shamcey-social.png">
    <meta property="og:image:secure_url" content="http://themepixels.me/shamcey/img/shamcey-social.png">
    <meta property="og:image:type" content="image/png">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="600">

    <meta name="description" content="Premium Quality and Responsive UI for Dashboard.">
    <meta name="author" content="ThemePixels">

    <title>Talsora</title>

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

      /* Create-post modal iframe: no 100vh empty bottom (DevTools purple waste) */
      html body.dashboard-modal-page,
      html body.dashboard-page.dashboard-modal-page{
        height:auto !important;
        min-height:0 !important;
        overflow:auto !important;
      }
      html body.dashboard-modal-page .sh-mainpanel,
      html body.dashboard-page.dashboard-modal-page .sh-mainpanel{
        height:auto !important;
        min-height:0 !important;
        max-height:none !important;
        display:block !important;
        overflow:visible !important;
        padding-bottom:0 !important;
        margin-bottom:0 !important;
      }
      html body.dashboard-modal-page .sh-pagebody,
      html body.dashboard-page.dashboard-modal-page .sh-pagebody{
        flex:none !important;
        height:auto !important;
        min-height:0 !important;
        overflow:visible !important;
        padding-bottom:0 !important;
        margin-bottom:0 !important;
      }
      html body.dashboard-modal-page .sh-footer,
      html body.dashboard-page.dashboard-modal-page .sh-footer,
      html body.dashboard-modal-page .app-footer,
      html body.dashboard-page.dashboard-modal-page .app-footer{
        display:none !important;
        height:0 !important;
        margin:0 !important;
        padding:0 !important;
        overflow:hidden !important;
      }
      html body.dashboard-modal-page .card,
      html body.dashboard-modal-page .card-body,
      html body.dashboard-page.dashboard-modal-page .card,
      html body.dashboard-page.dashboard-modal-page .card-body{
        flex:none !important;
        height:auto !important;
        min-height:0 !important;
        margin-bottom:0 !important;
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
        padding: 5px;
      }
    </style>
  
<style>
/* ===============================
   DASHBOARD RESPONSIVE FIX
   CSS ONLY — NO PHP/JS CHANGED
================================ */
.create-post-slides-panel{
  display:flex;
  flex-direction:column;
  gap:8px;
  margin:0 0 10px;
}
.create-post-slides-shell{
  border:1px solid rgba(15,23,42,.12);
  border-radius:10px;
  background:rgba(15,23,42,.02);
  padding:8px 6px;
  margin:0;
  flex:1 1 auto;
  min-height:0;
  max-height:148px;
  display:flex;
  flex-direction:column;
  overflow:hidden;
}
.create-post-slides{
  display:flex;
  flex-direction:column;
  gap:6px;
  flex:1 1 auto;
  min-height:0;
  max-height:100%;
  overflow-x:hidden;
  overflow-y:auto;
  -webkit-overflow-scrolling:touch;
  padding:8px 4px 8px 2px;
  scrollbar-width:thin;
  overscroll-behavior:contain;
}
.create-post-slide{
  display:grid;
  grid-template-columns:44px minmax(0,1fr);
  grid-template-rows:auto auto;
  gap:6px;
  align-items:start;
  padding:8px 6px 6px;
  border:1px solid rgba(15,23,42,.12);
  border-radius:8px;
  background:#fff;
  flex:0 0 auto;
  scroll-margin-top:12px;
  scroll-margin-bottom:6px;
}
.create-post-slide-head{
  grid-column:1 / -1;
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:8px;
  min-height:22px;
}
.create-post-slide-label{
  display:block !important;
  font-size:12px;
  font-weight:800;
  line-height:1.35;
  margin:0;
  padding:2px 0 0;
  color:#0f172a !important;
  opacity:1 !important;
  letter-spacing:.02em;
  overflow:visible;
  flex:1 1 auto;
  min-width:0;
}
.create-post-slide-remove{
  width:22px;
  height:22px;
  min-width:22px;
  border:0;
  border-radius:999px;
  background:rgba(15,23,42,.1);
  color:#0f172a;
  font-size:16px;
  line-height:1;
  padding:0;
  cursor:pointer;
  flex:0 0 auto;
  display:inline-flex;
  align-items:center;
  justify-content:center;
}
.create-post-slide-remove:hover{
  background:rgba(185,28,28,.14);
  color:#b91c1c;
}
.create-post-slide-actions{
  display:flex;
  align-items:center;
  gap:6px;
  flex:0 0 auto;
}
.create-post-slide-replace{
  height:22px;
  padding:0 8px;
  border:0;
  border-radius:999px;
  background:rgba(15,23,42,.1);
  color:#0f172a;
  font-size:11px;
  font-weight:700;
  line-height:22px;
  cursor:pointer;
  white-space:nowrap;
}
.create-post-slide-replace:hover{
  background:rgba(37,99,235,.16);
  color:#1d4ed8;
}
.create-post-slide-media{
  width:44px;
  height:44px;
  min-width:44px;
  min-height:44px;
  max-width:44px;
  max-height:44px;
  border-radius:6px;
  overflow:hidden;
  background:#0b1220;
  display:flex;
  align-items:center;
  justify-content:center;
  flex:0 0 44px;
  align-self:start;
  grid-row:2;
}
.create-post-slide-media img,
.create-post-slide-media video{width:100%;height:100%;object-fit:cover;display:block;}
.create-post-slide-file{color:#fff;font-size:10px;font-weight:800;letter-spacing:.04em;}
.create-post-slide-fields{
  grid-row:2;
  min-width:0;
}
.create-post-slide-fields textarea{resize:vertical;min-height:48px;}
.create-post-slides-actions{
  position:relative;
  z-index:3;
  flex:0 0 auto;
  background:inherit;
}
body.dashboard-modal-page .create-post-slides-panel{
  margin-bottom:0;
}
body.dashboard-modal-page .create-post-slides-shell{
  max-height:148px;
}
body.dashboard-modal-page .create-post-slides-actions{
  position:sticky;
  bottom:0;
  z-index:5;
  margin-top:0;
  margin-bottom:0;
  padding:6px 0 2px;
  background:var(--msb-palette-bg, #fff);
  box-shadow:0 -6px 12px -8px rgba(15,23,42,.12);
}
body.dashboard-modal-page .sh-pagebody{
  padding-bottom:0 !important;
}
body.dashboard-modal-page #createPostForm .form-group:last-child{
  margin-bottom:0 !important;
}
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
  height:auto !important;
  min-height:0 !important;
}
html body.dashboard-page.dashboard-modal-page{
  height:auto !important;
  max-height:none !important;
  min-height:0 !important;
  overflow-x:hidden !important;
  overflow-y:auto !important;
  -webkit-overflow-scrolling:touch;
}
html body.dashboard-page.dashboard-modal-page .sh-mainpanel{
  min-height:0 !important;
  height:auto !important;
  max-height:none !important;
  padding-bottom:0 !important;
  margin-bottom:0 !important;
}
html body.dashboard-page.dashboard-modal-page .sh-pagebody{
  min-height:0 !important;
  height:auto !important;
  padding-bottom:0 !important;
  margin-bottom:0 !important;
}
html body.dashboard-page.dashboard-modal-page .sh-footer,
html body.dashboard-page.dashboard-modal-page .app-footer{
  display:none !important;
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

/* Compact create-post — short inputs, small text/icons (perfect modal scale) */
html body.dashboard-page.dashboard-modal-page .form-control,
html body.dashboard-page.dashboard-modal-page select.form-control,
html body.dashboard-page.dashboard-modal-page .msb-readonly-field,
html body.dashboard-page.dashboard-modal-page #createPostForm .form-control,
html body.dashboard-page.dashboard-modal-page #createPostForm select.form-control{
  height:30px !important;
  min-height:30px !important;
  max-height:30px !important;
  padding:4px 10px !important;
  font-size:12px !important;
  line-height:1.3 !important;
  box-sizing:border-box !important;
  border-radius:6px !important;
}
html body.dashboard-page.dashboard-modal-page textarea.form-control,
html body.dashboard-page.dashboard-modal-page #createPostForm textarea.form-control{
  height:auto !important;
  min-height:56px !important;
  max-height:none !important;
  padding:6px 10px !important;
  font-size:12px !important;
}
html body.dashboard-page.dashboard-modal-page .form-control-sm,
html body.dashboard-page.dashboard-modal-page select.form-control-sm{
  height:28px !important;
  min-height:28px !important;
  max-height:28px !important;
  font-size:11px !important;
  padding:3px 8px !important;
}
html body.dashboard-page.dashboard-modal-page textarea.form-control-sm{
  height:auto !important;
  min-height:48px !important;
  max-height:none !important;
}
html body.dashboard-page.dashboard-modal-page label,
html body.dashboard-page.dashboard-modal-page .form-check-label{
  font-size:11px !important;
  margin-bottom:3px !important;
}
html body.dashboard-page.dashboard-modal-page .text-muted,
html body.dashboard-page.dashboard-modal-page small,
html body.dashboard-page.dashboard-modal-page .form-text,
html body.dashboard-page.dashboard-modal-page .small{
  font-size:10px !important;
  line-height:1.3 !important;
}
html body.dashboard-page.dashboard-modal-page .btn{
  font-size:11px !important;
  padding:4px 10px !important;
  min-height:28px !important;
  border-radius:6px !important;
}
html body.dashboard-page.dashboard-modal-page .btn .icon,
html body.dashboard-page.dashboard-modal-page .btn .fa,
html body.dashboard-page.dashboard-modal-page .btn i{
  font-size:12px !important;
}

body.dashboard-page{
  font-size:12px !important;
  line-height:1.35 !important;
}
body.dashboard-page .sh-pagebody{
  padding:8px 10px !important;
}
body.dashboard-page .card-body{
  padding:8px 10px !important;
}
body.dashboard-page .card-title,
body.dashboard-page h6{
  font-size:13px !important;
  font-weight:700 !important;
  margin-bottom:6px !important;
}
body.dashboard-page label,
body.dashboard-page .form-check-label{
  font-size:11px !important;
  font-weight:600 !important;
  margin-bottom:3px !important;
  line-height:1.25 !important;
}
body.dashboard-page .form-group{
  margin-bottom:8px !important;
}
body.dashboard-page .form-row > [class*="col-"] > .form-group,
body.dashboard-page .form-row .form-group{
  margin-bottom:8px !important;
}
body.dashboard-page .text-muted,
body.dashboard-page small,
body.dashboard-page .form-text,
body.dashboard-page .small{
  font-size:10px !important;
  line-height:1.3 !important;
  margin-top:2px !important;
}
body.dashboard-page .form-control,
body.dashboard-page select.form-control,
body.dashboard-page .msb-readonly-field{
  height:30px !important;
  min-height:30px !important;
  max-height:30px !important;
  padding:4px 10px !important;
  font-size:12px !important;
  line-height:1.3 !important;
  border-radius:6px !important;
}
body.dashboard-page select.form-control{
  padding-right:24px !important;
}
body.dashboard-page textarea.form-control{
  height:auto !important;
  min-height:56px !important;
  max-height:none !important;
  padding:6px 10px !important;
  font-size:12px !important;
  line-height:1.35 !important;
}
body.dashboard-page .form-control-sm,
body.dashboard-page select.form-control-sm,
body.dashboard-page textarea.form-control-sm{
  height:28px !important;
  min-height:28px !important;
  max-height:28px !important;
  padding:3px 8px !important;
  font-size:11px !important;
}
body.dashboard-page textarea.form-control-sm{
  height:auto !important;
  min-height:48px !important;
  max-height:none !important;
}
body.dashboard-page .btn{
  font-size:11px !important;
  line-height:1.2 !important;
  padding:4px 10px !important;
  border-radius:6px !important;
  height:auto !important;
  min-height:28px !important;
}
body.dashboard-page .btn-sm{
  font-size:11px !important;
  padding:3px 8px !important;
  min-height:26px !important;
}
body.dashboard-page .btn .icon,
body.dashboard-page .btn .fa,
body.dashboard-page .btn i,
body.dashboard-page label .icon,
body.dashboard-page label .fa,
body.dashboard-page .card-title .icon,
body.dashboard-page .card-title .fa{
  font-size:12px !important;
  line-height:1 !important;
  vertical-align:middle;
}
body.dashboard-page #createPostSubmitBtn{
  width:28px !important;
  min-width:28px !important;
  height:28px !important;
  min-height:28px !important;
  padding:0 !important;
  display:inline-flex !important;
  align-items:center;
  justify-content:center;
}
body.dashboard-page #createPostSubmitBtn .icon{
  font-size:14px !important;
}
body.dashboard-page #createPostAddSlideBtn .fa{
  font-size:11px !important;
}
body.dashboard-page .create-post-slides-panel{
  margin-bottom:8px !important;
}
body.dashboard-page .create-post-slides-shell{
  margin-bottom:0 !important;
  max-height:150px !important;
  overflow:hidden !important;
}
body.dashboard-page .create-post-slides{
  max-height:100% !important;
  overflow-y:auto !important;
  gap:6px !important;
}
body.dashboard-page .create-post-slide{
  grid-template-columns:48px minmax(0,1fr) !important;
  gap:6px !important;
  align-items:start !important;
  padding:6px !important;
  background:var(--msb-palette-bg, #fff) !important;
}
body.dashboard-page .create-post-slide-media{
  width:48px !important;
  height:48px !important;
  min-width:48px !important;
  min-height:48px !important;
  max-width:48px !important;
  max-height:48px !important;
  border-radius:6px !important;
  flex:0 0 48px !important;
  align-self:start !important;
}
body.dashboard-page .create-post-slides-actions{
  position:relative !important;
  z-index:3 !important;
  flex:0 0 auto !important;
  gap:8px !important;
}

/* ===== Modal create-post composer (match locked screenshot UI) ===== */
body.dashboard-page.dashboard-modal-page .card,
body.dashboard-page.dashboard-modal-page .card.mb-3{
  border:0 !important;
  box-shadow:none !important;
  background:transparent !important;
  margin:0 !important;
}
body.dashboard-page.dashboard-modal-page .card-body{
  padding:12px 14px 8px !important;
  background:transparent !important;
}
body.dashboard-page.dashboard-modal-page .msb-composer{
  display:flex;
  flex-direction:column;
  gap:12px;
  margin:0;
  padding:0 0 6px;
  flex:0 0 auto;
  height:auto !important;
  min-height:0 !important;
  max-height:none !important;
}
body.dashboard-page.dashboard-modal-page .msb-composer .form-group{
  margin-bottom:0 !important;
}
body.dashboard-page.dashboard-modal-page .msb-composer-identity,
body.dashboard-page.dashboard-modal-page .msb-composer-main,
body.dashboard-page.dashboard-modal-page .msb-composer-panel,
body.dashboard-page.dashboard-modal-page .msb-composer-addbar,
body.dashboard-page.dashboard-modal-page .msb-composer-footer{
  flex:0 0 auto !important;
}
body.dashboard-page.dashboard-modal-page .msb-composer-identity{
  display:flex;
  align-items:center;
  gap:12px;
  padding:2px 0 0;
}
body.dashboard-page.dashboard-modal-page .msb-composer-identity-left{
  display:flex;
  align-items:center;
  gap:12px;
  min-width:0;
  flex:1 1 auto;
}
body.dashboard-page.dashboard-modal-page .msb-composer-avatar{
  width:42px;
  height:42px;
  border-radius:999px;
  overflow:hidden;
  flex:0 0 42px;
  background:rgba(15,23,42,.1);
  display:flex;
  align-items:center;
  justify-content:center;
  font-size:13px;
  font-weight:800;
  color:#0f172a;
}
body.dashboard-page.dashboard-modal-page .msb-composer-avatar img{
  width:100%;
  height:100%;
  object-fit:cover;
  display:block;
}
body.dashboard-page.dashboard-modal-page .msb-composer-name{
  font-size:15px;
  font-weight:800;
  color:#0f172a;
  line-height:1.2;
}
body.dashboard-page.dashboard-modal-page .msb-composer-audience-pill{
  display:inline-flex;
  align-items:center;
  gap:6px;
  margin:0 0 0 auto;
  padding:4px 10px;
  border-radius:999px;
  background:rgba(15,23,42,.08);
  color:#0f172a;
  font-size:12px;
  font-weight:700;
  cursor:pointer;
  flex:0 0 auto;
}
body.dashboard-page.dashboard-modal-page .msb-composer-audience-select{
  border:0 !important;
  background:transparent !important;
  color:inherit !important;
  font-size:12px !important;
  font-weight:700 !important;
  height:auto !important;
  min-height:0 !important;
  max-height:none !important;
  padding:0 !important;
  box-shadow:none !important;
  cursor:pointer;
}
body.dashboard-page.dashboard-modal-page .msb-composer-main{
  min-height:0;
}
body.dashboard-page.dashboard-modal-page .msb-composer-title-field{
  position:relative;
  margin:0 0 8px;
}
body.dashboard-page.dashboard-modal-page .msb-composer-title-label{
  display:block;
  font-size:12px;
  font-weight:700;
  color:#64748b;
  margin:0 0 6px;
}
body.dashboard-page.dashboard-modal-page .msb-composer-title-input{
  border:1px solid rgba(15,23,42,.12) !important;
  border-radius:12px !important;
  background:#fff !important;
  box-shadow:none !important;
  height:44px !important;
  padding:10px 64px 10px 14px !important;
  font-size:14px !important;
  font-weight:600 !important;
  color:#0f172a !important;
}
body.dashboard-page.dashboard-modal-page .msb-composer-title-input:focus{
  border-color:rgba(37,99,235,.45) !important;
  outline:none !important;
  box-shadow:0 0 0 3px rgba(37,99,235,.12) !important;
}
body.dashboard-page.dashboard-modal-page .msb-composer-title-count,
body.dashboard-page.dashboard-modal-page .msb-composer-body-count{
  position:absolute;
  right:12px;
  font-size:11px;
  font-weight:700;
  color:#94a3b8;
  pointer-events:none;
}
body.dashboard-page.dashboard-modal-page .msb-composer-title-count{ bottom:10px; top:auto; transform:none; }
body.dashboard-page.dashboard-modal-page .msb-composer-body-shell{
  position:relative;
  border:1px solid rgba(15,23,42,.12);
  border-radius:14px;
  background:#fff;
  min-height:128px;
  padding:8px 10px 28px;
}
body.dashboard-page.dashboard-modal-page .msb-composer-body{
  border:0 !important;
  background:transparent !important;
  box-shadow:none !important;
  resize:none !important;
  min-height:96px !important;
  max-height:180px !important;
  height:auto !important;
  padding:4px 36px 4px 4px !important;
  font-size:17px !important;
  line-height:1.45 !important;
  color:#0f172a !important;
  flex:0 0 auto !important;
}
body.dashboard-page.dashboard-modal-page .msb-composer-body-emoji{
  position:absolute;
  top:8px;
  right:8px;
  width:32px;
  height:32px;
  border:0;
  border-radius:999px;
  background:transparent;
  color:#64748b;
  font-size:18px;
  display:inline-flex;
  align-items:center;
  justify-content:center;
  cursor:pointer;
}
body.dashboard-page.dashboard-modal-page .msb-composer-body-emoji:hover{ color:#0f172a; background:rgba(15,23,42,.06); }
body.dashboard-page.dashboard-modal-page .msb-composer-body-count{ bottom:8px; }
body.dashboard-page.dashboard-modal-page .msb-composer-body::placeholder{
  color:#94a3b8 !important;
  opacity:1 !important;
}
body.dashboard-page.dashboard-modal-page .card,
body.dashboard-page.dashboard-modal-page .card-body,
body.dashboard-page.dashboard-modal-page .sh-pagebody,
body.dashboard-page.dashboard-modal-page .sh-mainpanel{
  height:auto !important;
  min-height:0 !important;
  max-height:none !important;
}
body.dashboard-page.dashboard-modal-page .msb-composer-body:focus{
  outline:none !important;
  box-shadow:none !important;
}
body.dashboard-page.dashboard-modal-page .msb-composer-panel{
  border:1px solid rgba(15,23,42,.12);
  border-radius:12px;
  padding:10px;
  background:rgba(15,23,42,.03);
}
body.dashboard-page.dashboard-modal-page .msb-composer-panel[hidden]{
  display:none !important;
}
body.dashboard-page.dashboard-modal-page .msb-composer-panel-head{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:8px;
  margin-bottom:8px;
  font-size:12px;
  color:#0f172a;
}
body.dashboard-page.dashboard-modal-page .msb-composer-panel-close{
  width:24px;
  height:24px;
  border:0;
  border-radius:999px;
  background:rgba(15,23,42,.08);
  color:#0f172a;
  font-size:16px;
  line-height:1;
  cursor:pointer;
}
body.dashboard-page.dashboard-modal-page .msb-composer-addbar{
  position:relative;
  display:flex;
  align-items:stretch;
  justify-content:center;
  gap:10px;
  padding:6px 4px 2px;
  border:0;
  border-radius:0;
  background:transparent;
  flex-wrap:wrap;
}
body.dashboard-page.dashboard-modal-page .msb-composer-addbar-label{ display:none !important; }
body.dashboard-page.dashboard-modal-page .msb-composer-addbar-icons{
  display:flex;
  align-items:flex-start;
  justify-content:space-between;
  gap:8px;
  width:100%;
  flex-wrap:wrap;
}
body.dashboard-page.dashboard-modal-page .msb-composer-tool{
  width:auto;
  min-width:58px;
  height:auto;
  border:0;
  border-radius:0;
  background:transparent;
  color:#0f172a;
  font-size:11px;
  font-weight:700;
  display:inline-flex;
  flex-direction:column;
  align-items:center;
  justify-content:flex-start;
  gap:6px;
  cursor:pointer;
  padding:4px 2px;
}
body.dashboard-page.dashboard-modal-page .msb-composer-tool-icon{
  width:44px;
  height:44px;
  border-radius:999px;
  display:inline-flex;
  align-items:center;
  justify-content:center;
  font-size:18px;
  color:#fff;
  box-shadow:0 6px 14px rgba(15,23,42,.12);
}
body.dashboard-page.dashboard-modal-page .msb-composer-tool[data-open-panel="media"] .msb-composer-tool-icon{ background:#22c55e; }
body.dashboard-page.dashboard-modal-page .msb-composer-tool[data-emoji-picker] .msb-composer-tool-icon,
body.dashboard-page.dashboard-modal-page .msb-composer-tool[data-open-panel="feeling"] .msb-composer-tool-icon{ background:#f59e0b; }
body.dashboard-page.dashboard-modal-page .msb-feeling-tabs{
  display:flex;
  gap:8px;
  margin:4px 0 10px;
}
body.dashboard-page.dashboard-modal-page .msb-feeling-tab{
  flex:1 1 auto;
  border:1px solid rgba(15,23,42,.1);
  background:#f8fafc;
  color:#64748b;
  font-size:13px;
  font-weight:800;
  border-radius:999px;
  padding:8px 12px;
  cursor:pointer;
}
body.dashboard-page.dashboard-modal-page .msb-feeling-tab.is-active{
  background:#eff6ff;
  border-color:rgba(37,99,235,.28);
  color:#1d4ed8;
}
body.dashboard-page.dashboard-modal-page .msb-feeling-list{
  max-height:280px;
  overflow:auto;
  border:1px solid rgba(15,23,42,.08);
  border-radius:14px;
  background:#fff;
  padding:4px;
}
body.dashboard-page.dashboard-modal-page .msb-feeling-list[hidden]{ display:none !important; }
body.dashboard-page.dashboard-modal-page .msb-feeling-item{
  width:100%;
  display:flex;
  align-items:center;
  gap:12px;
  border:0;
  background:transparent;
  text-align:left;
  padding:10px 12px;
  border-radius:10px;
  color:#0f172a;
  font-size:14.5px;
  font-weight:600;
  cursor:pointer;
}
body.dashboard-page.dashboard-modal-page .msb-feeling-item:hover{
  background:#f8fafc;
}
body.dashboard-page.dashboard-modal-page .msb-feeling-item.is-selected{
  background:#eef4ff;
}
body.dashboard-page.dashboard-modal-page .msb-feeling-emoji{
  flex:0 0 auto;
  width:28px;
  text-align:center;
  font-size:22px;
  line-height:1;
}
body.dashboard-page.dashboard-modal-page .msb-feeling-text{
  flex:1 1 auto;
  min-width:0;
}
body.dashboard-page.dashboard-modal-page .msb-feeling-check{
  flex:0 0 auto;
  width:22px;
  height:22px;
  border-radius:999px;
  background:#2563eb;
  color:#fff;
  display:none;
  align-items:center;
  justify-content:center;
  font-size:11px;
}
body.dashboard-page.dashboard-modal-page .msb-feeling-item.is-selected .msb-feeling-check{
  display:inline-flex;
}
body.dashboard-page.dashboard-modal-page .msb-feeling-clear{
  margin-top:10px;
  border:0;
  background:transparent;
  color:#64748b;
  font-size:13px;
  font-weight:700;
  padding:6px 2px;
  cursor:pointer;
}
body.dashboard-page.dashboard-modal-page .msb-feeling-clear[hidden]{ display:none !important; }
body.dashboard-page.dashboard-modal-page .msb-feeling-clear:hover{ color:#1d4ed8; }
body.dashboard-page.dashboard-modal-page .msb-composer-tool[data-open-panel="location"] .msb-composer-tool-icon{ background:#ef4444; }
body.dashboard-page.dashboard-modal-page .msb-loc-search{ margin-top:2px; }
body.dashboard-page.dashboard-modal-page .msb-loc-input-wrap{
  position:relative;
  display:flex;
  align-items:center;
}
body.dashboard-page.dashboard-modal-page .msb-loc-input-ic{
  position:absolute;
  left:12px;
  color:#ef4444;
  font-size:15px;
  pointer-events:none;
  z-index:1;
}
body.dashboard-page.dashboard-modal-page .msb-loc-input{
  padding-left:34px !important;
  padding-right:36px !important;
}
body.dashboard-page.dashboard-modal-page .msb-loc-clear{
  position:absolute;
  right:8px;
  width:28px;
  height:28px;
  border:0;
  border-radius:999px;
  background:transparent;
  color:#64748b;
  font-size:18px;
  line-height:1;
  cursor:pointer;
}
body.dashboard-page.dashboard-modal-page .msb-loc-clear:hover{ background:rgba(15,23,42,.06); color:#0f172a; }
body.dashboard-page.dashboard-modal-page .msb-loc-hint{
  margin-top:8px;
  font-size:12px;
  color:#64748b;
  line-height:1.35;
}
body.dashboard-page.dashboard-modal-page .msb-loc-status{
  margin-top:8px;
  font-size:12.5px;
  font-weight:600;
  color:#475569;
}
body.dashboard-page.dashboard-modal-page .msb-loc-status.is-error{ color:#b91c1c; }
body.dashboard-page.dashboard-modal-page .msb-loc-results{
  list-style:none;
  margin:8px 0 0;
  padding:4px;
  max-height:220px;
  overflow:auto;
  border:1px solid rgba(15,23,42,.1);
  border-radius:12px;
  background:#fff;
  box-shadow:0 10px 24px rgba(15,23,42,.08);
}
body.dashboard-page.dashboard-modal-page .msb-loc-results[hidden]{ display:none !important; }
body.dashboard-page.dashboard-modal-page .msb-loc-result{
  display:flex;
  align-items:flex-start;
  gap:10px;
  width:100%;
  border:0;
  background:transparent;
  text-align:left;
  padding:10px 10px;
  border-radius:10px;
  cursor:pointer;
  color:#0f172a;
}
body.dashboard-page.dashboard-modal-page .msb-loc-result:hover,
body.dashboard-page.dashboard-modal-page .msb-loc-result.is-active{
  background:#f1f5f9;
}
body.dashboard-page.dashboard-modal-page .msb-loc-result-ic{
  flex:0 0 auto;
  width:28px;
  height:28px;
  border-radius:999px;
  display:inline-flex;
  align-items:center;
  justify-content:center;
  background:#fee2e2;
  color:#ef4444;
  margin-top:1px;
}
body.dashboard-page.dashboard-modal-page .msb-loc-result-text{ min-width:0; flex:1 1 auto; }
body.dashboard-page.dashboard-modal-page .msb-loc-result-title{
  display:block;
  font-size:13.5px;
  font-weight:800;
  line-height:1.25;
}
body.dashboard-page.dashboard-modal-page .msb-loc-result-sub{
  display:block;
  margin-top:2px;
  font-size:12px;
  color:#64748b;
  line-height:1.3;
  white-space:nowrap;
  overflow:hidden;
  text-overflow:ellipsis;
}
body.dashboard-page.dashboard-modal-page .msb-loc-selected{
  margin-top:10px;
  display:inline-flex;
  align-items:center;
  gap:8px;
  max-width:100%;
  padding:7px 12px;
  border-radius:999px;
  background:#fff7ed;
  border:1px solid rgba(239,68,68,.18);
  color:#9a3412;
  font-size:13px;
  font-weight:700;
}
body.dashboard-page.dashboard-modal-page .msb-loc-selected i{ color:#ef4444; }
body.dashboard-page.dashboard-modal-page .msb-loc-selected span{
  min-width:0;
  overflow:hidden;
  text-overflow:ellipsis;
  white-space:nowrap;
}
body.dashboard-page.dashboard-modal-page .msb-link-preview-card{
  margin-top:10px;
  border:1px solid var(--msb-palette-border-strong, rgba(15,23,42,.1));
  border-radius:16px;
  overflow:hidden;
  background:var(--msb-palette-bg, #fff);
  box-shadow:0 8px 24px rgba(15,23,42,.05);
}
body.dashboard-page.dashboard-modal-page .msb-link-preview-card[hidden]{ display:none !important; }
body.dashboard-page.dashboard-modal-page .msb-link-preview-main{
  display:flex;
  align-items:stretch;
  min-height:140px;
}
body.dashboard-page.dashboard-modal-page .msb-link-preview-media{
  flex:0 0 42%;
  width:42%;
  max-width:220px;
  background:#e2e8f0;
  overflow:hidden;
}
body.dashboard-page.dashboard-modal-page .msb-link-preview-media[hidden]{ display:none !important; }
body.dashboard-page.dashboard-modal-page .msb-link-preview-media.is-logo{
  display:flex;
  align-items:center;
  justify-content:center;
  background:
    radial-gradient(120% 120% at 20% 10%, rgba(255,255,255,.9) 0%, rgba(255,255,255,0) 55%),
    linear-gradient(145deg, #eff6ff 0%, #e2e8f0 48%, #dbeafe 100%);
}
body.dashboard-page.dashboard-modal-page .msb-link-preview-media img{
  width:100%;
  height:100%;
  object-fit:cover;
  display:block;
  min-height:140px;
}
body.dashboard-page.dashboard-modal-page .msb-link-preview-media.is-logo img{
  width:48%;
  height:48%;
  max-width:96px;
  max-height:96px;
  min-height:0;
  object-fit:contain;
}
body.dashboard-page.dashboard-modal-page .msb-link-preview-side{
  flex:1 1 auto;
  min-width:0;
  display:flex;
  flex-direction:column;
  background:var(--msb-palette-bg, #fff);
}
body.dashboard-page.dashboard-modal-page .msb-link-preview-body{
  flex:1 1 auto;
  min-width:0;
  padding:14px 16px 10px;
}
body.dashboard-page.dashboard-modal-page .msb-link-preview-top{
  display:flex;
  align-items:center;
  gap:8px;
  margin-bottom:6px;
}
body.dashboard-page.dashboard-modal-page .msb-link-preview-host{
  flex:1 1 auto;
  min-width:0;
  font-size:12px;
  font-weight:600;
  color:var(--msb-palette-text-muted, #94a3b8);
  overflow:hidden;
  text-overflow:ellipsis;
  white-space:nowrap;
}
body.dashboard-page.dashboard-modal-page .msb-link-preview-info{
  flex:0 0 auto;
  color:var(--msb-palette-text-muted, #94a3b8);
  font-size:14px;
}
body.dashboard-page.dashboard-modal-page .msb-link-preview-title{
  font-size:16px;
  font-weight:800;
  color:var(--msb-palette-text, #0f172a);
  line-height:1.28;
}
body.dashboard-page.dashboard-modal-page .msb-link-preview-desc{
  margin-top:6px;
  font-size:13px;
  color:var(--msb-palette-text-muted, #64748b);
  line-height:1.4;
}
body.dashboard-page.dashboard-modal-page .msb-link-preview-desc:empty{ display:none; }
body.dashboard-page.dashboard-modal-page .msb-link-preview-chips{
  display:flex;
  flex-wrap:wrap;
  gap:6px;
  margin-top:10px;
}
body.dashboard-page.dashboard-modal-page .msb-link-preview-chips[hidden]{ display:none !important; }
body.dashboard-page.dashboard-modal-page .msb-link-preview-chip{
  display:inline-flex;
  align-items:center;
  padding:5px 10px;
  border-radius:999px;
  background:var(--msb-palette-soft, #eef2f7);
  color:var(--msb-palette-text, #334155);
  font-size:11.5px;
  font-weight:700;
}
body.dashboard-page.dashboard-modal-page .msb-link-preview-cta{
  display:flex;
  align-items:center;
  gap:8px;
  margin-top:auto;
  padding:11px 16px;
  background:color-mix(in srgb, var(--msb-palette-action, #2563eb) 12%, var(--msb-palette-bg, #fff));
  color:var(--msb-palette-action, #1d4ed8);
  font-size:13px;
  font-weight:800;
  border-top:1px solid color-mix(in srgb, var(--msb-palette-action, #2563eb) 16%, transparent);
}
body.dashboard-page.dashboard-modal-page .msb-link-preview-cta .fa-chevron-right{ margin-left:auto; font-size:12px; }
body.dashboard-page.dashboard-modal-page .msb-link-edit-fields{
  margin-top:12px;
  display:grid;
  gap:6px;
}
body.dashboard-page.dashboard-modal-page .msb-link-edit-fields[hidden]{ display:none !important; }
body.dashboard-page.dashboard-modal-page .msb-link-edit-label{
  margin:4px 0 0;
  font-size:12px;
  font-weight:700;
  color:#64748b;
}
body.dashboard-page.dashboard-modal-page .msb-link-edit-fields .form-control{
  border-radius:10px;
}
body.dashboard-page.dashboard-modal-page .msb-composer-tool[data-open-panel="music"] .msb-composer-tool-icon{ background:#8b5cf6; }
body.dashboard-page.dashboard-modal-page .msb-composer-tool[data-open-panel="tag"] .msb-composer-tool-icon{ background:#3b82f6; }
body.dashboard-page.dashboard-modal-page .msb-composer-tool[data-open-panel="link"] .msb-composer-tool-icon{ background:#0891b2; }
body.dashboard-page.dashboard-modal-page .msb-composer-tool[data-open-panel="more"] .msb-composer-tool-icon{ background:#94a3b8; }
body.dashboard-page.dashboard-modal-page .msb-composer-tool[data-open-panel="product"] .msb-composer-tool-icon{ background:#0f766e; }
body.dashboard-page.dashboard-modal-page .msb-composer-tool-label{
  font-size:11px;
  font-weight:700;
  color:#475569;
  line-height:1.15;
  text-align:center;
  max-width:72px;
}
body.dashboard-page.dashboard-modal-page .msb-composer-tool.is-active .msb-composer-tool-icon{
  box-shadow:0 0 0 3px rgba(37,99,235,.22), 0 6px 14px rgba(15,23,42,.12);
}
body.dashboard-page.dashboard-modal-page .msb-composer-tool .msb-composer-aa{ color:#fff; font-weight:800; }
body.dashboard-page.dashboard-modal-page .msb-composer-emoji-picker{
  position:fixed;
  z-index:120000;
  width:min(300px, calc(100vw - 24px));
  display:flex;
  flex-direction:column;
  border:1px solid rgba(255,255,255,.16);
  border-radius:14px;
  background:rgba(48,48,52,.96);
  backdrop-filter:saturate(160%) blur(18px);
  -webkit-backdrop-filter:saturate(160%) blur(18px);
  box-shadow:0 18px 40px rgba(0,0,0,.38);
  color:#f5f5f7;
  overflow:hidden;
}
body.dashboard-page.dashboard-modal-page .msb-composer-emoji-picker[hidden]{
  display:none !important;
}
body.dashboard-page.dashboard-modal-page .msb-composer-emoji-chrome{
  display:flex;
  align-items:center;
  justify-content:space-between;
  padding:8px 10px 0;
  flex:0 0 auto;
}
body.dashboard-page.dashboard-modal-page .msb-composer-emoji-close,
body.dashboard-page.dashboard-modal-page .msb-composer-emoji-expand{
  width:18px;
  height:18px;
  border:0;
  border-radius:999px;
  background:rgba(255,255,255,.14);
  color:rgba(255,255,255,.85);
  font-size:12px;
  line-height:1;
  display:inline-flex;
  align-items:center;
  justify-content:center;
  cursor:pointer;
  padding:0;
}
body.dashboard-page.dashboard-modal-page .msb-composer-emoji-search-wrap{
  position:relative;
  margin:8px 10px 6px;
  flex:0 0 auto;
}
body.dashboard-page.dashboard-modal-page .msb-composer-emoji-search-wrap i{
  position:absolute;
  left:10px;
  top:50%;
  transform:translateY(-50%);
  color:rgba(255,255,255,.45);
  font-size:12px;
  pointer-events:none;
}
body.dashboard-page.dashboard-modal-page .msb-composer-emoji-search{
  width:100%;
  height:28px;
  border:0;
  border-radius:8px;
  background:rgba(0,0,0,.28);
  color:#f5f5f7;
  font-size:13px;
  padding:0 10px 0 28px;
  outline:none;
}
body.dashboard-page.dashboard-modal-page .msb-composer-emoji-search::placeholder{
  color:rgba(255,255,255,.42);
}
body.dashboard-page.dashboard-modal-page .msb-composer-emoji-body{
  flex:1 1 auto;
  min-height:0;
  max-height:180px;
  overflow:auto;
  padding:2px 8px 6px;
}
body.dashboard-page.dashboard-modal-page .msb-composer-emoji-section-label{
  font-size:11px;
  font-weight:600;
  color:rgba(255,255,255,.55);
  padding:4px 4px 6px;
}
body.dashboard-page.dashboard-modal-page .msb-composer-emoji-grid{
  display:grid;
  grid-template-columns:repeat(6, minmax(0, 1fr));
  gap:2px;
}
body.dashboard-page.dashboard-modal-page .msb-composer-emoji-grid button{
  width:100%;
  aspect-ratio:1;
  border:0;
  border-radius:8px;
  background:transparent;
  font-size:26px;
  line-height:1;
  cursor:pointer;
  padding:0;
}
body.dashboard-page.dashboard-modal-page .msb-composer-emoji-grid button:hover,
body.dashboard-page.dashboard-modal-page .msb-composer-emoji-grid button:focus{
  background:rgba(255,255,255,.12);
  outline:none;
}
body.dashboard-page.dashboard-modal-page .msb-composer-emoji-cats{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:2px;
  padding:6px 8px 8px;
  border-top:1px solid rgba(255,255,255,.08);
  background:rgba(0,0,0,.12);
  flex:0 0 auto;
}
body.dashboard-page.dashboard-modal-page .msb-composer-emoji-cat{
  width:26px;
  height:26px;
  border:0;
  border-radius:999px;
  background:transparent;
  color:rgba(255,255,255,.55);
  font-size:13px;
  display:inline-flex;
  align-items:center;
  justify-content:center;
  cursor:pointer;
  padding:0;
}
body.dashboard-page.dashboard-modal-page .msb-composer-emoji-cat.is-active{
  background:#0a84ff;
  color:#fff;
}
body.dashboard-page.dashboard-modal-page .msb-composer-emoji-cat:hover:not(.is-active){
  color:rgba(255,255,255,.9);
  background:rgba(255,255,255,.08);
}
body.dashboard-page.dashboard-modal-page .msb-composer-emoji-empty{
  padding:18px 8px;
  text-align:center;
  font-size:12px;
  color:rgba(255,255,255,.5);
}
body.dashboard-page.dashboard-modal-page .msb-composer-tool:hover{
  background:transparent;
}
body.dashboard-page.dashboard-modal-page .msb-composer-tool:hover .msb-composer-tool-icon{
  transform:translateY(-1px);
}
body.dashboard-page.dashboard-modal-page .msb-composer-aa{
  font-size:14px;
  font-weight:800;
  line-height:1;
}
body.dashboard-page.dashboard-modal-page .msb-composer-footer{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:10px;
  position:sticky;
  bottom:0;
  z-index:60;
  margin-top:8px;
  margin-bottom:0;
  padding:10px 0 14px;
  border-top:1px solid rgba(15,23,42,.08);
  background:var(--msb-palette-bg, #fff);
  box-shadow:0 -10px 18px -12px rgba(15,23,42,.2);
}
body.dashboard-page.dashboard-modal-page .msb-composer-settings-btn{
  display:inline-flex;
  align-items:center;
  gap:8px;
  border:0;
  background:transparent;
  color:#475569;
  font-size:13px;
  font-weight:700;
  padding:6px 4px;
  cursor:pointer;
}
body.dashboard-page.dashboard-modal-page .msb-composer-settings-btn i{ font-size:16px; color:#64748b; }
body.dashboard-page.dashboard-modal-page .msb-composer-footer-actions{
  display:inline-flex;
  align-items:center;
  gap:8px;
  margin-left:auto;
}
body.dashboard-page.dashboard-modal-page .msb-composer-schedule-btn{
  height:40px !important;
  min-height:40px !important;
  border-radius:999px !important;
  font-size:13px !important;
  font-weight:700 !important;
  padding:0 14px !important;
  background:#f1f5f9 !important;
  border:1px solid rgba(15,23,42,.08) !important;
  color:#334155 !important;
  display:inline-flex !important;
  align-items:center;
  gap:6px;
}
body.dashboard-page.dashboard-modal-page .msb-composer-post-btn{
  flex:0 0 auto;
  min-width:96px;
  height:40px !important;
  min-height:40px !important;
  max-height:none !important;
  border-radius:999px !important;
  font-size:15px !important;
  font-weight:800 !important;
  background:#2563eb !important;
  border-color:#2563eb !important;
  color:#fff !important;
  padding:0 22px !important;
}
body.dashboard-page.dashboard-modal-page .msb-composer-cancel-btn{
  display:none !important;
}
body.dashboard-page.dashboard-modal-page .msb-composer-dropzone{
  display:flex !important;
  flex-direction:row !important;
  flex-wrap:nowrap !important;
  align-items:center !important;
  gap:10px !important;
  padding:8px 10px !important;
  border:1.5px dashed rgba(59,130,246,.45);
  border-radius:14px;
  background:rgba(59,130,246,.04);
  min-height:0 !important;
}
body.dashboard-page.dashboard-modal-page .msb-composer-dropzone-copy{
  flex:0 1 200px;
  min-width:140px;
  max-width:210px;
  display:flex;
  flex-direction:column;
  justify-content:center;
  align-items:flex-start;
  gap:4px;
  margin:0 !important;
  padding:0 !important;
}
body.dashboard-page.dashboard-modal-page .msb-composer-dropzone-title{
  display:flex;
  align-items:center;
  gap:6px;
  font-size:13px;
  font-weight:800;
  color:#0f172a;
  line-height:1.2;
  margin:0;
}
body.dashboard-page.dashboard-modal-page .msb-composer-dropzone-title i{ color:#2563eb; font-size:15px; }
body.dashboard-page.dashboard-modal-page .msb-composer-dropzone-hint{
  font-size:11px;
  color:#64748b;
  line-height:1.25;
  margin:0;
}
body.dashboard-page.dashboard-modal-page .msb-composer-dropzone-actions{
  display:flex;
  flex-wrap:wrap;
  gap:6px;
  margin-top:2px !important;
  margin-bottom:0 !important;
}
body.dashboard-page.dashboard-modal-page .msb-composer-choose-btn{
  height:30px !important;
  min-height:30px !important;
  border-radius:999px !important;
  font-size:11px !important;
  font-weight:800 !important;
  padding:0 12px !important;
  background:#2563eb !important;
  border-color:#2563eb !important;
  color:#fff !important;
}
body.dashboard-page.dashboard-modal-page .msb-composer-cloud-btn{
  height:30px !important;
  min-height:30px !important;
  border-radius:999px !important;
  font-size:11px !important;
  font-weight:700 !important;
  padding:0 10px !important;
  background:#eff6ff !important;
  border:1px solid rgba(37,99,235,.25) !important;
  color:#1d4ed8 !important;
  display:inline-flex !important;
  align-items:center;
  gap:5px;
}
body.dashboard-page.dashboard-modal-page .msb-composer-dropzone-thumbs{
  flex:1 1 auto;
  min-width:0;
  display:flex !important;
  flex-direction:row !important;
  align-items:center !important;
  gap:8px;
  overflow-x:auto;
  overflow-y:hidden;
  margin:0 !important;
  padding:0 !important;
}
body.dashboard-page.dashboard-modal-page .msb-composer-dropzone .create-post-slides-shell{
  display:flex !important;
  flex-direction:row !important;
  flex-wrap:nowrap !important;
  align-items:center !important;
  gap:8px !important;
  max-height:none !important;
  overflow-x:auto !important;
  overflow-y:hidden !important;
  width:100%;
  margin:0 !important;
  padding:0 !important;
}
body.dashboard-page.dashboard-modal-page .msb-composer-dropzone .create-post-slides{
  display:flex !important;
  flex-direction:row !important;
  flex-wrap:nowrap !important;
  align-items:center !important;
  gap:8px !important;
  margin:0 !important;
  padding:0 !important;
  max-height:none !important;
  overflow-x:auto !important;
  overflow-y:hidden !important;
  flex:0 1 auto;
  min-width:0;
}
body.dashboard-page.dashboard-modal-page .msb-composer-dropzone .create-post-slide{
  width:72px !important;
  min-width:72px !important;
  max-width:72px !important;
  height:72px !important;
  border-radius:10px !important;
  overflow:hidden;
  position:relative;
  background:#e2e8f0;
  border:1px solid rgba(15,23,42,.08) !important;
  padding:0 !important;
  display:block !important;
  flex:0 0 72px !important;
  grid-template-columns:none !important;
  grid-template-rows:none !important;
  margin:0 !important;
}
body.dashboard-page.dashboard-modal-page .msb-composer-dropzone .create-post-slide-head{
  position:absolute !important;
  top:4px !important;
  right:4px !important;
  left:auto !important;
  width:auto !important;
  grid-column:auto !important;
  z-index:2;
  margin:0 !important;
  display:flex !important;
  justify-content:flex-end !important;
  background:transparent !important;
}
body.dashboard-page.dashboard-modal-page .msb-composer-dropzone .create-post-slide-label,
body.dashboard-page.dashboard-modal-page .msb-composer-dropzone .create-post-slide-replace,
body.dashboard-page.dashboard-modal-page .msb-composer-dropzone .create-post-slide-fields{
  display:none !important;
}
body.dashboard-page.dashboard-modal-page .msb-composer-dropzone .create-post-slide-remove{
  width:22px !important;
  height:22px !important;
  border-radius:999px !important;
  background:rgba(15,23,42,.72) !important;
  color:#fff !important;
  border:0 !important;
  line-height:22px !important;
  font-size:14px !important;
  padding:0 !important;
}
body.dashboard-page.dashboard-modal-page .msb-composer-dropzone .create-post-slide-media{
  width:100% !important;
  height:72px !important;
  min-width:100% !important;
  min-height:72px !important;
  max-width:none !important;
  max-height:none !important;
  margin:0 !important;
  border-radius:0 !important;
  flex:none !important;
  align-self:stretch !important;
  display:block !important;
}
body.dashboard-page.dashboard-modal-page .msb-composer-dropzone .create-post-slide-media img,
body.dashboard-page.dashboard-modal-page .msb-composer-dropzone .create-post-slide-media video{
  width:100% !important;
  height:100% !important;
  object-fit:cover !important;
  display:block !important;
}
body.dashboard-page.dashboard-modal-page .msb-composer-add-more{
  width:72px;
  min-width:72px;
  height:72px;
  border:1.5px dashed rgba(37,99,235,.4);
  border-radius:10px;
  background:rgba(255,255,255,.7);
  color:#2563eb;
  display:inline-flex;
  flex-direction:column;
  align-items:center;
  justify-content:center;
  gap:2px;
  font-size:18px;
  cursor:pointer;
  flex:0 0 72px;
  padding:0;
  margin:0;
}
body.dashboard-page.dashboard-modal-page .msb-composer-add-more span{
  font-size:11px;
  font-weight:700;
  line-height:1.1;
}
body.dashboard-page.dashboard-modal-page .msb-composer-dropzone.is-drag{
  border-color:#2563eb;
  background:rgba(37,99,235,.08);
}
body.dashboard-page.dashboard-modal-page .msb-composer-dropzone .create-post-slide.is-selected{
  box-shadow:0 0 0 2px #2563eb, 0 0 0 4px rgba(37,99,235,.2);
}
body.dashboard-page.dashboard-modal-page .msb-composer-dropzone .create-post-slide{
  cursor:pointer;
}
body.dashboard-page.dashboard-modal-page .msb-composer-slide-caption{
  margin:8px 0 4px;
}
body.dashboard-page.dashboard-modal-page .msb-composer-slide-caption[hidden]{
  display:none !important;
}
body.dashboard-page.dashboard-modal-page .msb-composer-slide-caption-head{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:8px;
  margin:0 0 6px;
}
body.dashboard-page.dashboard-modal-page .msb-composer-slide-caption-label{
  font-size:12px;
  font-weight:700;
  color:#64748b;
}
body.dashboard-page.dashboard-modal-page .msb-composer-slide-caption-close{
  width:24px;
  height:24px;
  border:0;
  border-radius:999px;
  background:rgba(15,23,42,.08);
  color:#0f172a;
  font-size:16px;
  line-height:1;
  cursor:pointer;
}
body.dashboard-page.dashboard-modal-page .msb-composer-slide-caption-shell{
  min-height:0;
}
body.dashboard-page.dashboard-modal-page .msb-composer-slide-caption-shell .msb-composer-body{
  min-height:72px !important;
  height:auto !important;
  padding-bottom:28px !important;
}
body.dashboard-page.dashboard-modal-page .msb-composer-panel[data-panel="media"] .msb-composer-panel-head{ display:none !important; }
body.dashboard-page.dashboard-modal-page .msb-composer-panel[data-panel="media"]{
  border:0 !important;
  background:transparent !important;
  padding:0 !important;
}
body.dashboard-page.dashboard-modal-page .msb-composer-panel[data-panel="title"]{ display:none !important; }
body.dashboard-page.dashboard-modal-page .create-post-slides-shell{
  max-height:none !important;
  border:0 !important;
  background:transparent !important;
  padding:0 !important;
  overflow:visible !important;
}
body.dashboard-page.dashboard-modal-page .msb-composer-dropzone .create-post-slides-actions{
  display:none !important;
}
body.dashboard-page.dashboard-modal-page .msb-composer .create-post-slides-actions{
  box-shadow:none !important;
  padding:6px 0 0 !important;
  position:relative !important;
}

/* Modal: Add slide / status / submit stay outside; only slides scroll */
body.dashboard-page.dashboard-modal-page{
  padding-bottom:0 !important;
}
body.dashboard-page.dashboard-modal-page .sh-pagebody{
  padding-bottom:0 !important;
}
body.dashboard-page.dashboard-modal-page .card-body{
  padding-bottom:12px !important;
  margin-bottom:0 !important;
}
body.dashboard-page.dashboard-modal-page .create-post-slides-panel{
  margin-bottom:0 !important;
  gap:0 !important;
  min-height:0 !important;
}
body.dashboard-page.dashboard-modal-page .create-post-slides-panel.msb-composer-dropzone{
  flex-direction:row !important;
}
body.dashboard-page.dashboard-modal-page .create-post-slides-shell{
  max-height:none !important;
}
body.dashboard-page.dashboard-modal-page .create-post-slides-shell.is-editing-multi{
  max-height:none !important;
}
body.dashboard-page.dashboard-modal-page .create-post-slides-actions{
  position:sticky !important;
  bottom:0 !important;
  z-index:40 !important;
  flex:0 0 auto !important;
  margin-top:0 !important;
  margin-bottom:0 !important;
  padding:6px 0 2px !important;
  background:var(--msb-palette-bg, #fff) !important;
  box-shadow:0 -10px 18px -10px rgba(15,23,42,.22) !important;
}
body.dashboard-page.dashboard-modal-page #createPostForm{
  padding-bottom:0 !important;
  margin-bottom:0 !important;
}
body.dashboard-page.dashboard-modal-page #createPostForm .form-group:last-child{
  margin-bottom:0 !important;
  padding-bottom:0 !important;
}
body.dashboard-page.dashboard-modal-page #createPostUploadProgress{
  margin-top:4px !important;
  margin-bottom:0 !important;
}
body.dashboard-page.dashboard-modal-page .create-post-slide-fields textarea.form-control,
body.dashboard-page.dashboard-modal-page .create-post-slide-fields textarea.form-control-sm{
  min-height:36px !important;
}
body.dashboard-page.dashboard-modal-page #createPostUploadProgress{
  margin-bottom:2px !important;
  font-size:10px !important;
}
body.dashboard-page .create-post-slide-fields,
body.dashboard-page .create-post-slide-card{
  font-size:11px !important;
}
body.dashboard-page .create-post-slide-fields textarea.form-control,
body.dashboard-page .create-post-slide-fields textarea.form-control-sm{
  min-height:48px !important;
  font-size:11px !important;
}
body.dashboard-page .create-post-slide-file{
  font-size:9px !important;
}
body.dashboard-page .create-post-slide-head{
  grid-column:1 / -1 !important;
  display:flex !important;
  align-items:center !important;
  justify-content:space-between !important;
  gap:8px !important;
}
body.dashboard-page .create-post-slide-label{
  display:block !important;
  font-size:12px !important;
  font-weight:800 !important;
  line-height:1.35 !important;
  margin:0 !important;
  padding:2px 0 0 !important;
  color:#0f172a !important;
  opacity:1 !important;
  visibility:visible !important;
  overflow:visible !important;
  flex:1 1 auto !important;
}
body.dashboard-page .create-post-slide-actions{
  display:flex !important;
  align-items:center !important;
  gap:6px !important;
  flex:0 0 auto !important;
}
body.dashboard-page .create-post-slide-replace{
  height:22px !important;
  padding:0 8px !important;
  border:0 !important;
  border-radius:999px !important;
  background:rgba(15,23,42,.1) !important;
  color:#0f172a !important;
  font-size:11px !important;
  font-weight:700 !important;
  line-height:22px !important;
  cursor:pointer !important;
  white-space:nowrap !important;
}
body.dashboard-page .create-post-slide-remove{
  width:22px !important;
  height:22px !important;
  min-width:22px !important;
  border:0 !important;
  border-radius:999px !important;
  background:rgba(15,23,42,.1) !important;
  color:#0f172a !important;
  font-size:16px !important;
  line-height:1 !important;
  padding:0 !important;
  cursor:pointer !important;
  flex:0 0 auto !important;
  display:inline-flex !important;
  align-items:center !important;
  justify-content:center !important;
}
body.dashboard-page .msb-tag-people{
  font-size:11px !important;
}
body.dashboard-page .msb-tag-people .form-control,
body.dashboard-page .msb-tag-people input{
  height:30px !important;
  min-height:30px !important;
  font-size:12px !important;
}
body.dashboard-page .alert{
  font-size:11px !important;
  padding:6px 8px !important;
  margin-bottom:8px !important;
}
body.dashboard-page .custom-file-label,
body.dashboard-page .custom-control-label{
  font-size:11px !important;
}
body.dashboard-page .progress{
  height:4px !important;
}
body.dashboard-page .alert{
  font-size:11px !important;
  padding:6px 8px !important;
  margin-bottom:8px !important;
}
body.dashboard-page .custom-file-label,
body.dashboard-page .custom-control-label{
  font-size:11px !important;
}
body.dashboard-page .progress{
  height:4px !important;
}
@media (max-width: 575.98px){
  body.dashboard-page #createPostForm .btn,
  body.dashboard-page #createPostForm button{
    width:auto !important;
  }
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

                <div class="card mb-3" hidden aria-hidden="true">
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

                <!-- ✅ HOW TO CREATE A POST (Instructions) — UI hidden; auto-detect + selector stay active -->
                <div class="alert alert-info create-post-type-box" hidden aria-hidden="true" style="border-left:4px solid #0861bc;background:<?php echo $isModalCreate ? $modalPageBgCss : 'var(--msb-palette-bg, #f5f7fb)'; ?> !important;color:<?php echo $isModalCreate ? $modalPageTextCss : 'var(--msb-palette-text, #0f172a)'; ?> !important;">
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

                <form id="createPostForm" class="<?= $isModalCreate ? 'msb-composer' : '' ?>" action="post_save.php" method="post" enctype="multipart/form-data" data-modal="<?= $isModalCreate ? '1' : '0' ?>" onsubmit="return false;">
                  <?php echo csrfInput(); ?>
                  <input type="hidden" name="ajax" value="1">
                  <input type="hidden" name="post_id" id="createPostId" value="<?= (int)($editPost['id'] ?? 0) ?>">
                  <input type="hidden" name="device_label" value="">
                  <input type="hidden" name="device_viewport" value="">
                  <input type="hidden" name="return_to" id="createPostReturnTo" value="home.php?tab=for-you">
                  <input type="hidden" name="sound_id" id="createPostSoundId" value="<?= (int)$composerSoundId ?>">
                  <input type="hidden" name="stitch_of_post_id" id="createPostStitchOf" value="<?= (int)$composerStitchOf ?>">
                  <input type="hidden" name="duet_of_post_id" id="createPostDuetOf" value="<?= (int)$composerDuetOf ?>">
                  <input type="hidden" name="commerce_org_id" value="<?= (int)$composerCommerceOrgId ?>">
                  <div id="pendingUploadTokens"></div>
                  <div id="removedAttachmentIds"></div>
                  <?php if ($isPublisherAccount): ?>
                  <input type="hidden" name="publisher_account" value="1">
                  <?php endif; ?>

                  <?php if ($isStoryCreate): ?>
                  <input type="hidden" name="layout_override" value="story">
                  <?php endif; ?>
                  <?php if ($editPost): ?>
                  <div class="alert alert-secondary py-2 px-3 mb-3 msb-composer-edit-note" style="font-size:13px;">
                    Editing post #<?= (int)$editPost['id'] ?><?php
                      if ($editAttachmentCount > 0) {
                        echo ' · ' . (int)$editAttachmentCount . ' media file' . ($editAttachmentCount === 1 ? '' : 's') . '. Replace, delete, or add more before you save.';
                      }
                    ?>.
                    Change <strong>Private</strong> / <strong>Friends</strong> / <strong>Public</strong> to move this post.
                  </div>
                  <?php endif; ?>

                  <?php if ($isModalCreate): ?>
                  <div class="msb-composer-identity">
                    <div class="msb-composer-identity-left">
                      <div class="msb-composer-avatar" aria-hidden="true">
                        <img src="<?= h($composerAvatarUrl) ?>" alt="" data-live-avatar="1" data-avatar-base="<?= h($composerAvatarUrl) ?>" onerror="this.style.display='none';var s=this.nextElementSibling;if(s){s.hidden=false;}">
                        <span hidden><?= h($composerInitials) ?></span>
                      </div>
                      <div class="msb-composer-identity-meta">
                        <div class="msb-composer-name"><?= h($composerName) ?></div>
                      </div>
                    </div>
                    <?php $vis = (string)($editPost['visibility'] ?? ($isPublisherAccount ? 'public' : 'friends')); ?>
                    <label class="msb-composer-audience-pill" for="createPostVisibility">
                      <i class="fa <?= $vis === 'public' ? 'fa-globe' : ($vis === 'private' ? 'fa-lock' : 'fa-users') ?>" aria-hidden="true"></i>
                      <select name="visibility" id="createPostVisibility" class="msb-composer-audience-select" aria-label="<?= $isStoryCreate ? 'Story audience' : 'Post destination' ?>">
                        <option value="private" <?= $vis==='private'?'selected':'' ?>><?= $isPublisherAccount ? 'Private room' : 'Private' ?></option>
                        <option value="friends" <?= $vis==='friends'?'selected':'' ?>><?= $isPublisherAccount ? 'Friends' : 'Friends' ?></option>
                        <option value="public" <?= $vis==='public'?'selected':'' ?>><?= $isPublisherAccount ? 'Public' : 'Public' ?></option>
                      </select>
                      <i class="fa fa-caret-down" aria-hidden="true"></i>
                    </label>
                  </div>
                  <?php endif; ?>

                  <div class="msb-composer-main form-group">
                    <?php if ($isModalCreate): ?>
                    <div class="msb-composer-title-field">
                      <label class="msb-composer-title-label" for="createPostTitle">Title or subject (optional)</label>
                      <input type="text" name="title" id="createPostTitle" class="form-control msb-composer-title-input" maxlength="100" data-msb-mention="1"
                        value="<?= h((string)($editPost['title'] ?? '')) ?>" placeholder="Add a title to your post..." aria-label="Title or subject (optional)">
                      <span class="msb-composer-title-count" id="createPostTitleCount">0/100</span>
                    </div>
                    <div class="msb-composer-body-shell">
                      <textarea name="body" id="createPostBody" class="form-control msb-composer-body" rows="4" placeholder="<?= h("What's on your mind, " . explode(' ', $composerName)[0] . '?') ?>" data-msb-mention="1" maxlength="5000"><?= h($editBodyText) ?></textarea>
                      <button type="button" class="msb-composer-body-emoji" data-emoji-picker="1" title="Emoji" aria-label="Emoji" aria-expanded="false" aria-controls="msbComposerEmojiPicker"><i class="fa fa-smile-o" aria-hidden="true"></i></button>
                      <span class="msb-composer-body-count" id="createPostBodyCount">0/5,000</span>
                    </div>
                    <?php else: ?>
                    <label>Introduction (optional)</label>
                    <textarea name="body" id="createPostBody" class="form-control msb-composer-body" rows="3" placeholder="Write an introduction under the title… Use @username to tag people" data-msb-mention="1"><?= h($editBodyText) ?></textarea>
                    <small class="text-muted">Without slides, this is your post caption. With slides, it stays fixed under the title as the introduction. Type @ to tag friends.</small>
                    <?php endif; ?>
                  </div>

                  <?php
                    $visClassic = (string)($editPost['visibility'] ?? ($isPublisherAccount ? 'public' : 'friends'));
                    $titleVal = (string)($editPost['title'] ?? '');
                    $musicTitleVal = (string)($editPost['music_title'] ?? '');
                    $musicArtistVal = (string)($editPost['music_artist'] ?? '');
                    if ($musicTitleVal === '' && $composerSoundTitlePrefill !== '') $musicTitleVal = $composerSoundTitlePrefill;
                    if ($musicArtistVal === '' && $composerSoundArtistPrefill !== '') $musicArtistVal = $composerSoundArtistPrefill;
                    $hasTitlePanel = $titleVal !== '';
                    $hasMusicPanel = ($musicTitleVal !== '' || $musicArtistVal !== '' || $composerSoundId > 0);
                    $hasTagPanel = !empty($editTaggedUsers);
                    $hasMediaPanel = true; /* always show media dropzone in modal */
                    if (!$isModalCreate) {
                      $hasMediaPanel = !empty($editAttachments) || !empty($editPost);
                    }
                    $hasProductPanel = !empty($composerProducts);
                  ?>

                  <?php if (!$isModalCreate): ?>
                  <div class="msb-composer-panel<?= ($isModalCreate && !$hasTitlePanel) ? '' : ' is-open' ?>" data-panel="title"<?= ($isModalCreate && !$hasTitlePanel) ? ' hidden' : '' ?>>
                    <div class="msb-composer-panel-head">
                      <strong>Title</strong>
                    </div>
                    <div class="form-row">
                      <div class="form-group col-md-6 mb-0">
                        <label>Title</label>
                        <input type="text" name="title" id="createPostTitle" class="form-control" maxlength="120" data-msb-mention="1"
                          value="<?= h($titleVal) ?>" placeholder="<?= $isStoryCreate ? 'e.g., My story moment…' : 'Add a title…' ?>">
                        <small class="text-muted"><?= $isStoryCreate ? 'Optional for stories. Add a title, description, photo, or video.' : 'Super title for the post. With slides, this stays fixed at the top while each slide has its own subtitle. Type @ to tag people.' ?></small>
                      </div>
                      <div class="form-group col-md-6 mb-0">
                        <label><?= $isStoryCreate ? 'Story Audience' : 'Post Destination' ?></label>
                        <select name="visibility" id="createPostVisibility" class="form-control">
                          <option value="private" <?= $visClassic==='private'?'selected':'' ?>><?= $isPublisherAccount ? 'Private room' : 'Private' ?></option>
                          <option value="friends" <?= $visClassic==='friends'?'selected':'' ?>><?= $isPublisherAccount ? 'Friends room (Circle)' : 'Friends' ?></option>
                          <option value="public" <?= $visClassic==='public'?'selected':'' ?>><?= $isPublisherAccount ? 'Public room (Discover)' : 'Public' ?></option>
                        </select>
                        <small class="text-muted"><strong>Private</strong> → only you. <strong>Friends</strong> → friends. <strong>Public</strong> → public feed.</small>
                      </div>
                    </div>
                  </div>
                  <?php endif; ?>

                  <div class="msb-composer-panel<?= ($isModalCreate && !$hasMusicPanel) ? '' : ' is-open' ?>" data-panel="music"<?= ($isModalCreate && !$hasMusicPanel) ? ' hidden' : '' ?>>
                    <div class="msb-composer-panel-head">
                      <strong>Music</strong>
                      <?php if ($isModalCreate): ?><button type="button" class="msb-composer-panel-close" data-close-panel="music" aria-label="Hide music">&times;</button><?php endif; ?>
                    </div>
                    <?php if ($composerStitchOf > 0 || $composerDuetOf > 0): ?>
                    <div class="alert alert-light py-2 px-3 mb-2" style="font-size:12px;">
                      <?= $composerStitchOf > 0 ? ('Stitch of post #' . (int)$composerStitchOf) : ('Duet with post #' . (int)$composerDuetOf) ?>
                    </div>
                    <?php endif; ?>
                    <div class="form-group mb-2">
                      <input type="search" id="msbSoundSearch" class="form-control form-control-sm" placeholder="Search sounds…" autocomplete="off">
                      <div id="msbSoundSearchResults" class="mt-1" style="max-height:120px;overflow:auto;font-size:12px;"></div>
                    </div>
                    <div class="form-row">
                      <div class="form-group col-md-6">
                        <?php if (!$isModalCreate): ?><label>Music title (optional)</label><?php endif; ?>
                        <input type="text" name="music_title" id="createPostMusicTitle" class="form-control" maxlength="120"
                          value="<?= h($musicTitleVal) ?>" placeholder="Music title (optional)">
                      </div>
                      <div class="form-group col-md-6 mb-0">
                        <?php if (!$isModalCreate): ?><label>Music artist (optional)</label><?php endif; ?>
                        <input type="text" name="music_artist" id="createPostMusicArtist" class="form-control" maxlength="120"
                          value="<?= h($musicArtistVal) ?>" placeholder="Artist (optional)">
                      </div>
                    </div>
                  </div>

                  <?php if ($isModalCreate && !empty($composerProducts)): ?>
                  <div class="msb-composer-panel" data-panel="product" hidden>
                    <div class="msb-composer-panel-head">
                      <strong>Tag products</strong>
                      <button type="button" class="msb-composer-panel-close" data-close-panel="product" aria-label="Hide products">&times;</button>
                    </div>
                    <div class="msb-composer-product-list" style="max-height:140px;overflow:auto;font-size:13px;">
                      <?php foreach ($composerProducts as $cp): ?>
                        <label class="d-flex align-items-center mb-1" style="gap:8px;cursor:pointer;">
                          <input type="checkbox" name="product_ids[]" value="<?= (int)($cp['id'] ?? 0) ?>">
                          <span><?= h((string)($cp['title'] ?? ('Product #' . (int)($cp['id'] ?? 0)))) ?></span>
                        </label>
                      <?php endforeach; ?>
                    </div>
                  </div>
                  <?php endif; ?>

                  <?php if (!$isStoryCreate): ?>
                  <div class="form-group" hidden aria-hidden="true">
                    <label>Special Feed Layout (optional)</label>
                    <select name="layout_override" class="form-control">
                      <option value="">Standard auto layout</option>
                      <option value="image_bottom" <?= $currentLayoutOverride==='image_bottom' ? 'selected' : '' ?>>Image only: description at bottom of image</option>
                      <option value="media_reel_bottom" <?= $currentLayoutOverride==='media_reel_bottom' ? 'selected' : '' ?>>Reel: single image or video with description at bottom</option>
                    </select>
                  </div>
                  <?php endif; ?>
                  <div class="form-group" hidden aria-hidden="true">
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
                  </div>
                  <input type="hidden" name="description" value="">

                  <div class="msb-composer-panel<?= ($isModalCreate && !$hasTagPanel) ? '' : ' is-open' ?>" data-panel="tag"<?= ($isModalCreate && !$hasTagPanel) ? ' hidden' : '' ?>>
                    <div class="msb-composer-panel-head">
                      <strong>Tag people</strong>
                      <?php if ($isModalCreate): ?><button type="button" class="msb-composer-panel-close" data-close-panel="tag" aria-label="Hide tags">&times;</button><?php endif; ?>
                    </div>
                    <div class="form-group mb-0">
                      <?php if (!$isModalCreate): ?><label>Tag people</label><?php endif; ?>
                      <input type="text" id="createPostTagPeopleInput" class="form-control" placeholder="Search a name or @username" autocomplete="off" data-msb-mention="0">
                      <input type="hidden" name="tagged_user_ids" id="createPostTaggedUserIds" value="">
                      <div class="msb-tag-people" id="createPostTagPeopleChips" aria-live="polite"></div>
                    </div>
                  </div>

                  <div class="msb-composer-panel<?= ($isModalCreate || $hasMediaPanel) ? ' is-open' : '' ?>" data-panel="media"<?= (!$isModalCreate && !$hasMediaPanel) ? ' hidden' : '' ?>>
                    <div class="msb-composer-panel-head">
                      <strong>Photos / videos</strong>
                      <?php if ($isModalCreate): ?><button type="button" class="msb-composer-panel-close" data-close-panel="media" aria-label="Hide media">&times;</button><?php endif; ?>
                    </div>
                    <div class="form-group mb-0">
                      <?php if (!$isModalCreate): ?>
                      <label>Slides (media + subtitle + summary)</label>
                      <p class="small text-muted mb-2">Optional. Add slides for a presentation. Scroll media inside the box; Add slide / Submit stay below.</p>
                      <?php endif; ?>
                      <div class="create-post-slides-panel<?= $isModalCreate ? ' msb-composer-dropzone' : '' ?>">
                      <?php if ($isModalCreate): ?>
                      <div class="msb-composer-dropzone-copy">
                        <div class="msb-composer-dropzone-title"><i class="fa fa-image" aria-hidden="true"></i> Add photos or videos</div>
                        <p class="msb-composer-dropzone-hint">Drag and drop files here, or choose from your device.</p>
                        <div class="msb-composer-dropzone-actions">
                          <button type="button" class="btn btn-primary btn-sm msb-composer-choose-btn" id="createPostChooseFilesBtn">Choose files</button>
                          <button type="button" class="btn btn-sm msb-composer-cloud-btn" id="createPostCloudBtn" title="Upload from cloud"><i class="fa fa-cloud-upload" aria-hidden="true"></i> Upload from cloud <i class="fa fa-caret-down" aria-hidden="true"></i></button>
                        </div>
                      </div>
                      <div class="msb-composer-dropzone-thumbs">
                      <?php endif; ?>
                      <div class="create-post-slides-shell<?= $editAttachmentCount >= 2 ? ' is-editing-multi' : '' ?>">
                        <?php if ($isModalCreate): ?>
                        <button type="button" class="msb-composer-add-more" id="createPostAddMoreBtn" aria-label="Add slide" title="Add slide">
                          <i class="fa fa-plus" aria-hidden="true"></i>
                          <span>Add slide</span>
                        </button>
                        <?php endif; ?>
                        <div id="createPostSlides" class="create-post-slides" aria-label="Slides list">
                          <?php foreach ($editAttachments as $idx => $att): ?>
                            <?php
                              $aid = (int)($att['id'] ?? 0);
                              $fp = preg_replace('#^public_user/#', '', (string)($att['file_path'] ?? ''));
                              $atype = strtolower((string)($att['type'] ?? 'file'));
                              $stitle = (string)($att['slide_title'] ?? '');
                              $sbody = (string)($att['slide_body'] ?? '');
                            ?>
                            <div class="create-post-slide" data-existing-id="<?= $aid ?>">
                              <div class="create-post-slide-head">
                                <div class="create-post-slide-label">Slide <?= (int)$idx + 1 ?></div>
                                <div class="create-post-slide-actions">
                                  <button type="button" class="create-post-slide-replace" aria-label="Replace slide <?= (int)$idx + 1 ?>" title="Replace this photo or video">Replace</button>
                                  <button type="button" class="create-post-slide-remove" aria-label="Remove slide <?= (int)$idx + 1 ?>" title="Remove slide">&times;</button>
                                </div>
                              </div>
                              <div class="create-post-slide-media">
                                <?php if ($atype === 'video'): ?>
                                  <video src="<?= h($fp) ?>" muted playsinline preload="metadata"></video>
                                <?php elseif ($atype === 'image'): ?>
                                  <img src="<?= h($fp) ?>" alt="">
                                <?php else: ?>
                                  <div class="create-post-slide-file"><?= h(strtoupper($atype) ?: 'FILE') ?></div>
                                <?php endif; ?>
                              </div>
                              <div class="create-post-slide-fields">
                                <input type="text" class="form-control form-control-sm mb-1" name="existing_slide_title[<?= $aid ?>]" value="<?= h($stitle) ?>" placeholder="Subtitle (optional)">
                                <textarea class="form-control form-control-sm" name="existing_slide_body[<?= $aid ?>]" rows="2" placeholder="Summary for this slide (one idea per line = bullets)…"><?= h($sbody) ?></textarea>
                              </div>
                            </div>
                          <?php endforeach; ?>
                        </div>
                      </div>
                      <?php if ($isModalCreate): ?></div><?php endif; ?>
                      <input type="file" id="createPostAttachments" name="attachments[]" class="form-control" multiple accept="image/*,video/*,application/pdf,.pdf,.gif,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.txt,.zip" style="display:none;">
                      <input type="file" id="createPostReplaceFile" accept="image/*,video/*,application/pdf,.pdf,.gif,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.txt,.zip" style="display:none;">
                      <div class="create-post-slides-actions">
                      <div class="d-flex align-items-center justify-content-between flex-wrap" style="gap:8px;">
                        <div class="d-flex align-items-center flex-wrap" style="gap:8px;min-width:0;flex:1 1 auto;">
                          <button type="button" class="btn btn-outline-primary btn-sm" id="createPostAddSlideBtn"><i class="fa fa-plus" aria-hidden="true"></i> Add media</button>
                          <div id="createPostUploadStatus" class="small text-muted mb-0" aria-live="polite" style="line-height:1.3;"><?php
                            if ($editPost && $editAttachmentCount > 0) {
                                echo h($editAttachmentCount . ' slide' . ($editAttachmentCount === 1 ? '' : 's') . ' on this post. Replace, delete, or add more.');
                            }
                          ?></div>
                        </div>
                        <?php if (!$isModalCreate): ?>
                        <div class="d-flex align-items-center">
                          <button type="submit" id="createPostSubmitBtn" class="btn btn-primary mr-2"><i class="icon ion-arrow-up-a" aria-hidden="true"></i></button>
                          <?php if ($editPost): ?>
                            <a href="dashboard.php<?= $editPost ? ('?edit=' . (int)$editPost['id']) : '' ?>" class="btn btn-outline-secondary">Cancel</a>
                          <?php endif; ?>
                          <a href="feed.php" class="btn btn-outline-primary ml-2<?= $isPublisherAccount ? '' : ' mr-2' ?>"><?= $isPublisherAccount ? 'Back to Feed' : 'Go to Feed' ?></a>
                          <?php if (!$isPublisherAccount): ?>
                          <a href="public.php" class="btn btn-outline-dark">Go to Public</a>
                          <?php endif; ?>
                        </div>
                        <?php endif; ?>
                      </div>
                      <div id="createPostUploadProgress" class="progress mt-2" style="height:6px;display:none;overflow:hidden;border-radius:999px;background:rgba(15,23,42,.08);">
                        <div id="createPostUploadBar" class="progress-bar" role="progressbar" style="width:0%;height:100%;transition:width .08s linear;background:#2563eb;"></div>
                      </div>
                      </div>
                      </div>
                      <?php if ($isModalCreate): ?>
                      <div class="msb-composer-slide-caption" id="createPostSlideCaptionWrap" hidden>
                        <div class="msb-composer-slide-caption-head">
                          <span class="msb-composer-slide-caption-label">Subtitle for this slide <span id="createPostSlideCaptionIndex"></span></span>
                          <button type="button" class="msb-composer-slide-caption-close" id="createPostSlideCaptionClose" aria-label="Close subtitle">&times;</button>
                        </div>
                        <div class="msb-composer-body-shell msb-composer-slide-caption-shell">
                          <textarea id="createPostSlideCaption" class="form-control msb-composer-body" rows="3" maxlength="500" placeholder="<?= h("What's on your mind, " . explode(' ', $composerName)[0] . '?') ?>" data-msb-mention="1"></textarea>
                          <span class="msb-composer-body-count" id="createPostSlideCaptionCount">0/500</span>
                        </div>
                      </div>
                      <?php endif; ?>
                    </div>
                  </div>

                  <?php if ($isModalCreate): ?>
                  <div class="msb-composer-panel" data-panel="location" hidden>
                    <div class="msb-composer-panel-head">
                      <strong>Location</strong>
                      <button type="button" class="msb-composer-panel-close" data-close-panel="location" aria-label="Hide location">&times;</button>
                    </div>
                    <div class="msb-loc-search" id="createPostLocationSearch">
                      <div class="msb-loc-input-wrap">
                        <i class="fa fa-map-marker msb-loc-input-ic" aria-hidden="true"></i>
                        <input type="text" id="createPostLocation" class="form-control msb-loc-input" maxlength="240" placeholder="Search a place or paste a Google Maps / Apple Maps link" autocomplete="off" value="<?= h((string)($editPost['location_label'] ?? '')) ?>" aria-autocomplete="list" aria-controls="createPostLocationResults" aria-expanded="false">
                        <button type="button" class="msb-loc-clear" id="createPostLocationClear" aria-label="Clear location"<?= trim((string)($editPost['location_label'] ?? '')) === '' ? ' hidden' : '' ?>>&times;</button>
                      </div>
                      <input type="hidden" name="location_label" id="createPostLocationLabel" value="<?= h((string)($editPost['location_label'] ?? '')) ?>">
                      <input type="hidden" name="location_lat" id="createPostLocationLat" value="">
                      <input type="hidden" name="location_lng" id="createPostLocationLng" value="">
                      <input type="hidden" name="location_url" id="createPostLocationUrl" value="">
                      <div class="msb-loc-hint">Type a city or landmark, then pick from the list — or paste a map link from your browser.</div>
                      <div class="msb-loc-status" id="createPostLocationStatus" hidden></div>
                      <ul class="msb-loc-results" id="createPostLocationResults" role="listbox" hidden></ul>
                      <div class="msb-loc-selected" id="createPostLocationSelected"<?= trim((string)($editPost['location_label'] ?? '')) === '' ? ' hidden' : '' ?>>
                        <i class="fa fa-map-marker" aria-hidden="true"></i>
                        <span id="createPostLocationSelectedText"><?= h((string)($editPost['location_label'] ?? '')) ?></span>
                      </div>
                    </div>
                  </div>
                  <div class="msb-composer-panel" data-panel="feeling" hidden>
                    <div class="msb-composer-panel-head">
                      <strong>Feeling / Activity</strong>
                      <button type="button" class="msb-composer-panel-close" data-close-panel="feeling" aria-label="Hide feeling">&times;</button>
                    </div>
                    <?php
                      $feelingOptions = [
                        ['value' => 'happy', 'emoji' => '😊', 'label' => 'Feeling happy'],
                        ['value' => 'blessed', 'emoji' => '😄', 'label' => 'Feeling blessed'],
                        ['value' => 'excited', 'emoji' => '😁', 'label' => 'Feeling excited'],
                        ['value' => 'relaxed', 'emoji' => '😌', 'label' => 'Feeling relaxed'],
                        ['value' => 'tired', 'emoji' => '😔', 'label' => 'Feeling tired'],
                        ['value' => 'grateful', 'emoji' => '😀', 'label' => 'Feeling grateful'],
                        ['value' => 'loved', 'emoji' => '❤️', 'label' => 'Feeling loved'],
                        ['value' => 'motivated', 'emoji' => '✨', 'label' => 'Feeling motivated'],
                        ['value' => 'sick', 'emoji' => '🤢', 'label' => 'Feeling sick'],
                        ['value' => 'angry', 'emoji' => '😡', 'label' => 'Feeling angry'],
                        ['value' => 'sad', 'emoji' => '☹️', 'label' => 'Feeling sad'],
                      ];
                      $activityOptions = [
                        ['value' => 'celebrating', 'emoji' => '🎉', 'label' => 'Celebrating'],
                        ['value' => 'watching', 'emoji' => '📺', 'label' => 'Watching'],
                        ['value' => 'eating', 'emoji' => '🍕', 'label' => 'Eating'],
                        ['value' => 'drinking', 'emoji' => '☕', 'label' => 'Drinking'],
                        ['value' => 'traveling', 'emoji' => '✈️', 'label' => 'Traveling'],
                        ['value' => 'listening', 'emoji' => '🎧', 'label' => 'Listening to music'],
                        ['value' => 'working', 'emoji' => '💼', 'label' => 'Working'],
                        ['value' => 'thinking', 'emoji' => '🤔', 'label' => 'Thinking'],
                      ];
                      $editFeelingRaw = trim((string)($editPost['feeling_label'] ?? ''));
                      $editFeelingNorm = strtolower(preg_replace('/^\s*feeling\s+/i', '', $editFeelingRaw) ?? $editFeelingRaw);
                      $editFeelingNorm = trim(preg_replace('/[^\p{L}\p{N}\s]/u', '', $editFeelingNorm) ?? $editFeelingNorm);
                    ?>
                    <input type="hidden" name="feeling_label" id="createPostFeeling" value="<?= h($editFeelingRaw) ?>">
                    <div class="msb-feeling-tabs" role="tablist" aria-label="Feeling or activity">
                      <button type="button" class="msb-feeling-tab is-active" data-feeling-tab="feelings" role="tab" aria-selected="true">Feelings</button>
                      <button type="button" class="msb-feeling-tab" data-feeling-tab="activities" role="tab" aria-selected="false">Activities</button>
                    </div>
                    <div class="msb-feeling-list" id="createPostFeelingListFeelings" role="listbox" aria-label="Feelings">
                      <?php foreach ($feelingOptions as $opt):
                        $isOn = ($editFeelingNorm !== '' && (
                          $editFeelingNorm === $opt['value']
                          || str_contains($editFeelingNorm, $opt['value'])
                          || str_contains(strtolower($editFeelingRaw), strtolower($opt['label']))
                        ));
                      ?>
                      <button
                        type="button"
                        class="msb-feeling-item<?= $isOn ? ' is-selected' : '' ?>"
                        role="option"
                        aria-selected="<?= $isOn ? 'true' : 'false' ?>"
                        data-feeling-value="<?= h($opt['value']) ?>"
                        data-feeling-emoji="<?= h($opt['emoji']) ?>"
                        data-feeling-kind="feeling"
                      >
                        <span class="msb-feeling-emoji" aria-hidden="true"><?= $opt['emoji'] ?></span>
                        <span class="msb-feeling-text"><?= h($opt['label']) ?></span>
                        <span class="msb-feeling-check" aria-hidden="true"><i class="fa fa-check"></i></span>
                      </button>
                      <?php endforeach; ?>
                    </div>
                    <div class="msb-feeling-list" id="createPostFeelingListActivities" role="listbox" aria-label="Activities" hidden>
                      <?php foreach ($activityOptions as $opt):
                        $isOn = ($editFeelingNorm !== '' && (
                          $editFeelingNorm === $opt['value']
                          || str_contains($editFeelingNorm, $opt['value'])
                          || str_contains(strtolower($editFeelingRaw), strtolower($opt['label']))
                        ));
                      ?>
                      <button
                        type="button"
                        class="msb-feeling-item<?= $isOn ? ' is-selected' : '' ?>"
                        role="option"
                        aria-selected="<?= $isOn ? 'true' : 'false' ?>"
                        data-feeling-value="<?= h($opt['value']) ?>"
                        data-feeling-emoji="<?= h($opt['emoji']) ?>"
                        data-feeling-kind="activity"
                      >
                        <span class="msb-feeling-emoji" aria-hidden="true"><?= $opt['emoji'] ?></span>
                        <span class="msb-feeling-text"><?= h($opt['label']) ?></span>
                        <span class="msb-feeling-check" aria-hidden="true"><i class="fa fa-check"></i></span>
                      </button>
                      <?php endforeach; ?>
                    </div>
                    <button type="button" class="msb-feeling-clear" id="createPostFeelingClear"<?= $editFeelingRaw === '' ? ' hidden' : '' ?>>Clear selection</button>
                  </div>
                  <div class="msb-composer-panel" data-panel="link" hidden>
                    <div class="msb-composer-panel-head">
                      <strong>Link</strong>
                      <button type="button" class="msb-composer-panel-close" data-close-panel="link" aria-label="Hide link">&times;</button>
                    </div>
                    <div class="msb-link-attach" id="createPostLinkAttach">
                      <div class="msb-loc-input-wrap">
                        <i class="fa fa-link msb-loc-input-ic" aria-hidden="true" style="color:#0891b2"></i>
                        <input type="url" id="createPostLinkInput" class="form-control msb-loc-input" maxlength="500" placeholder="Paste a website link from your browser" autocomplete="off" value="<?= h((string)($editPost['link_url'] ?? '')) ?>">
                        <button type="button" class="msb-loc-clear" id="createPostLinkClear" aria-label="Clear link"<?= trim((string)($editPost['link_url'] ?? '')) === '' ? ' hidden' : '' ?>>&times;</button>
                      </div>
                      <input type="hidden" name="link_url" id="createPostLinkUrl" value="<?= h((string)($editPost['link_url'] ?? '')) ?>">
                      <input type="hidden" name="link_image" id="createPostLinkImage" value="<?= h((string)($editPost['link_image'] ?? '')) ?>">
                      <div class="msb-loc-hint">Paste a full URL, then edit the title, description, and topic chips so friends see clear details on the card.</div>
                      <div class="msb-loc-status" id="createPostLinkStatus" hidden></div>
                      <div class="msb-link-preview-card" id="createPostLinkPreview"<?= trim((string)($editPost['link_url'] ?? '')) === '' ? ' hidden' : '' ?>>
                        <div class="msb-link-preview-main">
                          <?php
                            $editLinkUrl = trim((string)($editPost['link_url'] ?? ''));
                            $editLinkHost = '';
                            if ($editLinkUrl !== '') {
                              $hp = parse_url($editLinkUrl);
                              $editLinkHost = strtolower((string)($hp['host'] ?? ''));
                              $editLinkHost = preg_replace('/^www\./i', '', $editLinkHost) ?: $editLinkHost;
                              if ($editLinkHost !== '') {
                                $editLinkHost = 'www.' . $editLinkHost;
                              }
                            }
                            $editLinkImage = trim((string)($editPost['link_image'] ?? ''));
                            $editLinkTitleRaw = trim((string)($editPost['link_title'] ?? ''));
                            if ($editLinkTitleRaw !== '' && preg_match('/request has been blocked|access denied|just a moment|forbidden|captcha|unusual traffic/i', $editLinkTitleRaw)) {
                              $editLinkTitleRaw = '';
                            }
                            $editLinkDescRaw = trim((string)($editPost['link_description'] ?? ''));
                            if ($editLinkDescRaw !== '' && preg_match('/request has been blocked|access denied|just a moment|forbidden|captcha|unusual traffic/i', $editLinkDescRaw)) {
                              $editLinkDescRaw = '';
                            }
                            $editLinkTagsRaw = trim((string)($editPost['link_tags'] ?? ''));
                            $editLinkTagsList = $editLinkTagsRaw !== '' ? preg_split('/[,|;]+/', $editLinkTagsRaw) : [];
                            $editLinkTagsList = array_values(array_filter(array_map('trim', is_array($editLinkTagsList) ? $editLinkTagsList : [])));
                          ?>
                          <div class="msb-link-preview-media<?= $editLinkImage !== '' && (str_contains($editLinkImage, 'unavatar.io') || str_contains($editLinkImage, 'google.com/s2/favicons')) ? ' is-logo' : '' ?>" id="createPostLinkPreviewMedia"<?= $editLinkImage === '' ? ' hidden' : '' ?>>
                            <img id="createPostLinkPreviewImg" src="<?= h($editLinkImage) ?>" alt="" loading="lazy" decoding="async" referrerpolicy="no-referrer">
                          </div>
                          <div class="msb-link-preview-side">
                            <div class="msb-link-preview-body">
                              <div class="msb-link-preview-top">
                                <div class="msb-link-preview-host" id="createPostLinkHost"><?= h($editLinkHost) ?></div>
                                <span class="msb-link-preview-info" title="Website preview" aria-hidden="true"><i class="fa fa-info-circle"></i></span>
                              </div>
                              <div class="msb-link-preview-title" id="createPostLinkPreviewTitle"><?= h($editLinkTitleRaw !== '' ? $editLinkTitleRaw : ($editLinkHost !== '' ? $editLinkHost : 'Visit Website')) ?></div>
                              <div class="msb-link-preview-desc" id="createPostLinkPreviewDesc"><?= h($editLinkDescRaw !== '' ? $editLinkDescRaw : 'Open this website to see the full page, details, and latest updates.') ?></div>
                              <div class="msb-link-preview-chips" id="createPostLinkPreviewChips"<?= $editLinkTagsList === [] ? ' hidden' : '' ?>>
                                <?php foreach (array_slice($editLinkTagsList, 0, 4) as $chip): ?>
                                  <span class="msb-link-preview-chip"><?= h($chip) ?></span>
                                <?php endforeach; ?>
                              </div>
                            </div>
                            <div class="msb-link-preview-cta"><i class="fa fa-link" aria-hidden="true"></i><span>Visit Website</span><i class="fa fa-chevron-right" aria-hidden="true"></i></div>
                          </div>
                        </div>
                      </div>
                      <div class="msb-link-edit-fields" id="createPostLinkEditFields"<?= trim((string)($editPost['link_url'] ?? '')) === '' ? ' hidden' : '' ?>>
                        <label class="msb-link-edit-label" for="createPostLinkTitle">Title</label>
                        <input type="text" name="link_title" id="createPostLinkTitle" class="form-control" maxlength="180" placeholder="Link title" value="<?= h($editLinkTitleRaw) ?>" autocomplete="off">
                        <label class="msb-link-edit-label" for="createPostLinkDescription">Description</label>
                        <textarea name="link_description" id="createPostLinkDescription" class="form-control" maxlength="280" rows="2" placeholder="Short description friends will see"><?= h($editLinkDescRaw) ?></textarea>
                        <label class="msb-link-edit-label" for="createPostLinkTags">Topics (comma separated)</label>
                        <input type="text" name="link_tags" id="createPostLinkTags" class="form-control" maxlength="280" placeholder="Things to Do, Hotels, Dining, Travel Tips" value="<?= h($editLinkTagsRaw) ?>" autocomplete="off">
                      </div>
                    </div>
                  </div>
                  <div class="msb-composer-addbar" role="toolbar" aria-label="Add to your post">
                    <span class="msb-composer-addbar-label">Add to your post</span>
                    <div class="msb-composer-addbar-icons">
                      <button type="button" class="msb-composer-tool" data-open-panel="media" title="Photo / video" aria-label="Photo or video">
                        <span class="msb-composer-tool-icon"><i class="fa fa-image" aria-hidden="true"></i></span>
                        <span class="msb-composer-tool-label">Photo/Video</span>
                      </button>
                      <button type="button" class="msb-composer-tool" data-open-panel="feeling" title="Feeling / Activity" aria-label="Feeling or activity">
                        <span class="msb-composer-tool-icon"><i class="fa fa-smile-o" aria-hidden="true"></i></span>
                        <span class="msb-composer-tool-label">Feeling</span>
                      </button>
                      <button type="button" class="msb-composer-tool" data-open-panel="location" title="Location" aria-label="Location">
                        <span class="msb-composer-tool-icon"><i class="fa fa-map-marker" aria-hidden="true"></i></span>
                        <span class="msb-composer-tool-label">Location</span>
                      </button>
                      <button type="button" class="msb-composer-tool" data-open-panel="music" title="Music" aria-label="Music">
                        <span class="msb-composer-tool-icon"><i class="fa fa-music" aria-hidden="true"></i></span>
                        <span class="msb-composer-tool-label">Music</span>
                      </button>
                      <button type="button" class="msb-composer-tool" data-open-panel="tag" title="Tag people" aria-label="Tag people">
                        <span class="msb-composer-tool-icon"><i class="fa fa-user-plus" aria-hidden="true"></i></span>
                        <span class="msb-composer-tool-label">Tag People</span>
                      </button>
                      <button type="button" class="msb-composer-tool" data-open-panel="link" title="Add a link" aria-label="Add a link">
                        <span class="msb-composer-tool-icon"><i class="fa fa-link" aria-hidden="true"></i></span>
                        <span class="msb-composer-tool-label">Link</span>
                      </button>
                      <?php if (!empty($composerProducts)): ?>
                      <button type="button" class="msb-composer-tool" data-open-panel="product" title="Tag product" aria-label="Tag product">
                        <span class="msb-composer-tool-icon"><i class="fa fa-shopping-bag" aria-hidden="true"></i></span>
                        <span class="msb-composer-tool-label">Product</span>
                      </button>
                      <?php else: ?>
                      <button type="button" class="msb-composer-tool" data-open-panel="more" title="More" aria-label="More options">
                        <span class="msb-composer-tool-icon"><i class="fa fa-ellipsis-h" aria-hidden="true"></i></span>
                        <span class="msb-composer-tool-label">More</span>
                      </button>
                      <?php endif; ?>
                    </div>
                  </div>
                  <div class="msb-composer-emoji-picker" id="msbComposerEmojiPicker" hidden role="dialog" aria-label="Emoji & Symbols">
                    <div class="msb-composer-emoji-chrome">
                      <button type="button" class="msb-composer-emoji-close" id="msbComposerEmojiClose" aria-label="Close">&times;</button>
                      <button type="button" class="msb-composer-emoji-expand" id="msbComposerEmojiExpand" aria-label="Expand" title="More"><i class="fa fa-th" aria-hidden="true"></i></button>
                    </div>
                    <div class="msb-composer-emoji-search-wrap">
                      <i class="fa fa-search" aria-hidden="true"></i>
                      <input type="search" class="msb-composer-emoji-search" id="msbComposerEmojiSearch" placeholder="Search" autocomplete="off" spellcheck="false">
                    </div>
                    <div class="msb-composer-emoji-body">
                      <div class="msb-composer-emoji-section-label" id="msbComposerEmojiLabel">Frequently Used</div>
                      <div class="msb-composer-emoji-grid" id="msbComposerEmojiGrid"></div>
                      <div class="msb-composer-emoji-empty" id="msbComposerEmojiEmpty" hidden>No emoji found</div>
                    </div>
                    <div class="msb-composer-emoji-cats" id="msbComposerEmojiCats" role="tablist" aria-label="Emoji categories"></div>
                  </div>
                  <div class="msb-composer-footer">
                    <button type="button" class="msb-composer-settings-btn" id="createPostSettingsBtn" title="Post settings">
                      <i class="fa fa-cog" aria-hidden="true"></i>
                      <span>Post settings</span>
                    </button>
                    <div class="msb-composer-footer-actions">
                      <button type="button" class="btn msb-composer-schedule-btn" id="createPostScheduleBtn" title="Schedule post"><i class="fa fa-calendar" aria-hidden="true"></i> Schedule</button>
                      <button type="submit" id="createPostSubmitBtn" class="btn btn-primary msb-composer-post-btn">Post</button>
                    </div>
                    <button type="button" class="btn btn-outline-secondary msb-composer-cancel-btn" onclick="try{if(window.parent&&window.parent.MSBCreatePostModal){window.parent.MSBCreatePostModal.close();}}catch(_e){}">Cancel</button>
                  </div>
                  <?php endif; ?>
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
window.__MSB_CREATE_POST_CSRF = <?php echo json_encode(csrfToken(), JSON_UNESCAPED_SLASHES); ?>;
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
  const replaceFileInput = document.getElementById('createPostReplaceFile');
  let replaceTargetCard = null;
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

  const isStoryCreateForm = !!(f.querySelector('input[name="layout_override"][value="story"]'));
  const fromProfileCreate = /(?:\?|&)from=profile(?:&|$)/i.test(String(window.location.search || ''));
  function syncReturnToFromVisibility(){
    if (!returnToInput) return;
    const vis = visibilitySel ? String(visibilitySel.value || 'friends') : 'friends';
    const audienceIcon = visibilitySel && visibilitySel.closest('.msb-composer-audience-pill')
      ? visibilitySel.closest('.msb-composer-audience-pill').querySelector('i.fa:not(.fa-caret-down)')
      : null;
    if (audienceIcon) {
      audienceIcon.classList.remove('fa-lock', 'fa-users', 'fa-globe');
      audienceIcon.classList.add(vis === 'private' ? 'fa-lock' : (vis === 'public' ? 'fa-globe' : 'fa-users'));
    }
    // Profile story "+" stays on profile. Private → Gallery Private tab.
    // Other story "+" → feed/public. Left-nav "+" → post card surface.
    if (vis === 'private') {
      returnToInput.value = 'profile.php?tab=posts';
      return;
    }
    if (isStoryCreateForm && fromProfileCreate) {
      returnToInput.value = 'profile.php?story=1';
      return;
    }
    if (vis === 'public') {
      returnToInput.value = isStoryCreateForm ? 'home.php?tab=discover&story=1' : 'home.php?tab=discover';
    } else {
      returnToInput.value = isStoryCreateForm ? 'home.php?tab=for-you&story=1' : 'home.php?tab=for-you';
    }
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

  function setProgress(pct, show){
    if (!progressWrap || !progressBar) return;
    if (!show) {
      progressWrap.style.display = 'none';
      progressBar.style.width = '0%';
      return;
    }
    progressWrap.style.display = 'block';
    // Snap the bar to real byte progress — no slow easing lag.
    progressBar.style.width = Math.max(0, Math.min(100, Number(pct) || 0)) + '%';
  }

  function syncSubmitEnabled(){
    if (!submitBtn) return;
    submitBtn.disabled = uploading;
    submitBtn.style.opacity = uploading ? '0.7' : '';
  }

  function addPendingTokenInput(token){
    if (!tokenBox || !token) return;
    const input = document.createElement('input');
    input.type = 'hidden';
    input.name = 'pending_tokens[]';
    input.value = token;
    input.dataset.pendingToken = '1';
    tokenBox.appendChild(input);
    pendingCount++;
  }

  function addToken(token, fileMeta){
    if (!token) return;
    addPendingTokenInput(token);
    addSlideCardForToken(token, fileMeta || null);
  }

  function queueRemovedAttachmentId(existingId){
    existingId = parseInt(existingId || '0', 10) || 0;
    if (existingId <= 0) return;
    let removeBox = document.getElementById('removedAttachmentIds');
    if (!removeBox && f) {
      removeBox = document.createElement('div');
      removeBox.id = 'removedAttachmentIds';
      f.appendChild(removeBox);
    }
    if (!removeBox) return;
    const inp = document.createElement('input');
    inp.type = 'hidden';
    inp.name = 'remove_attachment_ids[]';
    inp.value = String(existingId);
    removeBox.appendChild(inp);
  }

  function dropPendingToken(token){
    token = String(token || '').trim();
    if (!token || !tokenBox) return;
    Array.prototype.slice.call(tokenBox.querySelectorAll('input[data-pending-token="1"]')).forEach(function(inp){
      if (String(inp.value || '') === token) {
        if (inp.parentNode) inp.parentNode.removeChild(inp);
        pendingCount = Math.max(0, pendingCount - 1);
      }
    });
  }

  function paintSlideMedia(media, fileMeta){
    if (!media) return;
    const type = String((fileMeta && fileMeta.type) || '').toLowerCase();
    const previewUrl = String((fileMeta && (fileMeta.previewUrl || fileMeta.web)) || '');
    const objectUrl = String((fileMeta && fileMeta.objectUrl) || '');
    const fileType = String((fileMeta && fileMeta.fileType) || '').toLowerCase();
    if (previewUrl && type.indexOf('video') === 0) {
      media.innerHTML = '<video src="'+previewUrl.replace(/"/g,'&quot;')+'" muted playsinline preload="metadata"></video>';
    } else if (previewUrl && type.indexOf('image') === 0) {
      media.innerHTML = '<img src="'+previewUrl.replace(/"/g,'&quot;')+'" alt="">';
    } else if (objectUrl && fileType.indexOf('video') === 0) {
      media.innerHTML = '<video src="'+objectUrl.replace(/"/g,'&quot;')+'" muted playsinline preload="metadata"></video>';
    } else if (objectUrl && fileType.indexOf('image') === 0) {
      media.innerHTML = '<img src="'+objectUrl.replace(/"/g,'&quot;')+'" alt="">';
    } else {
      media.innerHTML = '<div class="create-post-slide-file">FILE</div>';
    }
  }

  function applyReplaceOnCard(card, token, fileMeta){
    if (!card || !token) return;
    const existingId = parseInt(card.getAttribute('data-existing-id') || '0', 10) || 0;
    const oldToken = String(card.getAttribute('data-token') || '').trim();
    if (existingId > 0) queueRemovedAttachmentId(existingId);
    if (oldToken) dropPendingToken(oldToken);
    addPendingTokenInput(token);
    card.setAttribute('data-token', token);
    card.removeAttribute('data-existing-id');
    paintSlideMedia(card.querySelector('.create-post-slide-media'), fileMeta || null);
    const titleInp = card.querySelector('input[name^="existing_slide_title"], input[name^="slide_title"]');
    const bodyInp = card.querySelector('textarea[name^="existing_slide_body"], textarea[name^="slide_body"]');
    if (titleInp) titleInp.name = 'slide_title[' + token + ']';
    if (bodyInp) bodyInp.name = 'slide_body[' + token + ']';
    syncPendingStatusMessage();
    syncSubmitEnabled();
    try {
      if (typeof window.msbComposerOpenPanel === 'function') window.msbComposerOpenPanel('media');
    } catch (_open) {}
  }

  function nextSlideNumber(){
    const box = document.getElementById('createPostSlides');
    if (!box) return 1;
    return box.querySelectorAll('.create-post-slide').length + 1;
  }

  function renumberSlideCards(){
    const box = document.getElementById('createPostSlides');
    if (!box) return;
    const cards = box.querySelectorAll('.create-post-slide');
    cards.forEach(function(card, idx){
      const label = card.querySelector('.create-post-slide-label');
      const btn = card.querySelector('.create-post-slide-remove');
      const n = idx + 1;
      if (label) label.textContent = 'Slide ' + n;
      if (btn) {
        btn.setAttribute('aria-label', 'Remove slide ' + n);
        btn.setAttribute('title', 'Remove slide');
      }
    });
  }

  function syncPendingStatusMessage(){
    if (uploading) return;
    if (pendingCount > 0) {
      setStatus(pendingCount + ' file' + (pendingCount > 1 ? 's' : '') + ' ready — click submit.', false);
    } else {
      const box = document.getElementById('createPostSlides');
      const remaining = box ? box.querySelectorAll('.create-post-slide').length : 0;
      if (remaining > 0) {
        setStatus(remaining + ' slide' + (remaining > 1 ? 's' : '') + ' on this post.', false);
      } else {
        setStatus('', false);
      }
    }
    try {
      if (window.parent && window.parent !== window) {
        window.parent.postMessage({ type: 'msb-create-post-fit', force: 1 }, '*');
      }
    } catch (_fitDel) {}
  }

  function removeSlideCard(card){
    if (!card) return;
    const token = String(card.getAttribute('data-token') || '').trim();
    const existingId = parseInt(card.getAttribute('data-existing-id') || '0', 10) || 0;

    try {
      card.querySelectorAll('img[src^="blob:"], video[src^="blob:"]').forEach(function(el){
        try { URL.revokeObjectURL(el.getAttribute('src') || ''); } catch (_r) {}
      });
    } catch (_blob) {}

    if (token) dropPendingToken(token);
    if (existingId > 0) queueRemovedAttachmentId(existingId);

    if (card.parentNode) card.parentNode.removeChild(card);
    renumberSlideCards();
    syncPendingStatusMessage();
    syncSubmitEnabled();
    try {
      if (typeof window.msbRefreshSlideCaption === 'function') window.msbRefreshSlideCaption(card);
    } catch (_cap) {}
  }

  function scrollSlidesToLatest(card){
    const box = document.getElementById('createPostSlides');
    if (!box) return;
    const go = function(){
      try {
        /* Keep the full "Slide N" label in view (not clipped at the top). */
        if (card && box.contains(card)) {
          var pad = 12;
          box.scrollTop = Math.max(0, card.offsetTop - pad);
        } else {
          box.scrollTop = box.scrollHeight;
        }
      } catch (_e) {
        box.scrollTop = box.scrollHeight;
      }
      if (window.parent && window.parent !== window) {
        try { window.parent.postMessage({ type: 'msb-create-post-fit' }, '*'); } catch (_m) {}
      }
    };
    if (typeof requestAnimationFrame === 'function') {
      requestAnimationFrame(function(){ requestAnimationFrame(go); });
    } else {
      setTimeout(go, 0);
    }
  }

  function addSlideCardForToken(token, fileMeta){
    const box = document.getElementById('createPostSlides');
    if (!box || !token) return;
    const n = nextSlideNumber();
    const card = document.createElement('div');
    card.className = 'create-post-slide';
    card.dataset.token = token;

    const label = document.createElement('div');
    label.className = 'create-post-slide-label';
    label.textContent = 'Slide ' + n;

    const removeBtn = document.createElement('button');
    removeBtn.type = 'button';
    removeBtn.className = 'create-post-slide-remove';
    removeBtn.setAttribute('aria-label', 'Remove slide ' + n);
    removeBtn.title = 'Remove slide';
    removeBtn.innerHTML = '&times;';

    const replaceBtn = document.createElement('button');
    replaceBtn.type = 'button';
    replaceBtn.className = 'create-post-slide-replace';
    replaceBtn.setAttribute('aria-label', 'Replace slide ' + n);
    replaceBtn.title = 'Replace this photo or video';
    replaceBtn.textContent = 'Replace';

    const actions = document.createElement('div');
    actions.className = 'create-post-slide-actions';
    actions.appendChild(replaceBtn);
    actions.appendChild(removeBtn);

    const head = document.createElement('div');
    head.className = 'create-post-slide-head';
    head.appendChild(label);
    head.appendChild(actions);

    const media = document.createElement('div');
    media.className = 'create-post-slide-media';
    const type = String((fileMeta && fileMeta.type) || '').toLowerCase();
    const previewUrl = String((fileMeta && (fileMeta.previewUrl || fileMeta.web)) || '');
    if (previewUrl && type.indexOf('video') === 0) {
      media.innerHTML = '<video src="'+previewUrl.replace(/"/g,'&quot;')+'" muted playsinline preload="metadata"></video>';
    } else if (previewUrl && type.indexOf('image') === 0) {
      media.innerHTML = '<img src="'+previewUrl.replace(/"/g,'&quot;')+'" alt="">';
    } else if (fileMeta && fileMeta.objectUrl) {
      if (String(fileMeta.fileType || '').indexOf('video') === 0) {
        media.innerHTML = '<video src="'+String(fileMeta.objectUrl).replace(/"/g,'&quot;')+'" muted playsinline preload="metadata"></video>';
      } else if (String(fileMeta.fileType || '').indexOf('image') === 0) {
        media.innerHTML = '<img src="'+String(fileMeta.objectUrl).replace(/"/g,'&quot;')+'" alt="">';
      } else {
        media.innerHTML = '<div class="create-post-slide-file">FILE</div>';
      }
    } else {
      media.innerHTML = '<div class="create-post-slide-file">FILE</div>';
    }
    const fields = document.createElement('div');
    fields.className = 'create-post-slide-fields';
    fields.innerHTML =
      '<input type="text" class="form-control form-control-sm mb-1" name="slide_title['+token+']" placeholder="Subtitle (optional)">' +
      '<textarea class="form-control form-control-sm" name="slide_body['+token+']" rows="2" placeholder="Summary for this slide (one idea per line = bullets)…"></textarea>';
    card.appendChild(head);
    card.appendChild(media);
    card.appendChild(fields);
    box.appendChild(card);
    try {
      if (typeof window.msbComposerOpenPanel === 'function') {
        window.msbComposerOpenPanel('media');
      }
    } catch (_openMedia) {}
    scrollSlidesToLatest(card);
    try {
      if (typeof window.msbSelectCreatePostSlide === 'function') {
        window.msbSelectCreatePostSlide(card);
      }
    } catch (_sel) {}
  }

  function formatBytes(n){
    n = Number(n || 0);
    if (n < 1024) return n + ' B';
    if (n < 1024 * 1024) return (n / 1024).toFixed(0) + ' KB';
    return (n / (1024 * 1024)).toFixed(1) + ' MB';
  }

  const maxUploadBytes = <?= (int)post_upload_max_bytes() ?>;

  // Compress large photos before upload. Videos/PDFs pass through instantly.
  function compressImageFile(file){
    return new Promise(function(resolve){
      try {
        if (!file || !/^image\/(jpeg|jpg|png|webp)$/i.test(file.type || '')) {
          resolve(file);
          return;
        }
        // Keep already-small photos as-is (faster than canvas encode).
        if (file.size < 1024 * 1024) {
          resolve(file);
          return;
        }

        function finishFromBitmap(bitmap, revoke){
          try {
            const maxEdge = 1600;
            let w = bitmap.width || 0;
            let h = bitmap.height || 0;
            if (!w || !h) {
              if (revoke) revoke();
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
              if (revoke) revoke();
              resolve(file);
              return;
            }
            ctx.drawImage(bitmap, 0, 0, w, h);
            if (revoke) revoke();
            canvas.toBlob(function(blob){
              if (!blob || blob.size >= file.size * 0.92) {
                resolve(file);
                return;
              }
              const base = String(file.name || 'photo').replace(/\.[^.]+$/, '');
              resolve(new File([blob], base + '.jpg', { type: 'image/jpeg', lastModified: Date.now() }));
            }, 'image/jpeg', 0.8);
          } catch (_e) {
            if (revoke) revoke();
            resolve(file);
          }
        }

        if (typeof createImageBitmap === 'function') {
          createImageBitmap(file).then(function(bitmap){
            finishFromBitmap(bitmap, function(){
              try { bitmap.close(); } catch (_c) {}
            });
          }).catch(function(){
            resolve(file);
          });
          return;
        }

        const url = URL.createObjectURL(file);
        const img = new Image();
        img.onload = function(){
          finishFromBitmap(img, function(){ URL.revokeObjectURL(url); });
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

  function humanUploadError(code, message){
    const msg = String(message || '').trim();
    if (msg) return msg;
    const map = {
      too_large: 'File is too large (max <?= h(post_upload_max_label()) ?>).',
      unsupported_type: 'That file type is not supported. Use JPG, PNG, GIF, WEBP, MP4, MOV, or PDF.',
      heic_unsupported: 'HEIC/HEIF photos are not supported. Export as JPG or PNG and try again.',
      mime_mismatch: 'File type did not match its contents. Try another export.',
      move_failed: 'Could not save the file on the server. Try again.',
      dir_not_writable: 'Upload folder is not writable on the server.',
      invalid_tmp: 'Upload did not arrive completely. Try again.',
      partial: 'Upload was interrupted. Try again.',
      csrf: 'Session expired. Refresh the page and try again.',
      auth: 'Please sign in again, then retry the upload.',
      readonly: 'This account cannot upload media.',
      no_files: 'No file was received. Try choosing the file again.',
      upload_failed: 'Upload failed. Try again or submit to upload on save.',
      http_403: 'Upload was blocked. Refresh the page and try again.',
      http_409: 'Session expired. Refresh the page and try again.'
    };
    const key = String(code || '').trim();
    return map[key] || ('Upload failed' + (key ? (' (' + key + ')') : '') + '.');
  }

  function parseUploadJson(raw){
    raw = String(raw || '').replace(/^\uFEFF/, '').trim();
    if (!raw) return null;
    try { return JSON.parse(raw); } catch (_e) {}
    const start = raw.indexOf('{');
    const end = raw.lastIndexOf('}');
    if (start >= 0 && end > start) {
      try { return JSON.parse(raw.slice(start, end + 1)); } catch (_e2) {}
    }
    return null;
  }

  function createPostCsrf(){
    let token = csrfInput ? String(csrfInput.value || '') : '';
    if (!token) token = String(window.__MSB_CREATE_POST_CSRF || '');
    if (!token) {
      try {
        if (window.parent && window.parent !== window && window.parent.__MSB_CSRF_TOKEN) {
          token = String(window.parent.__MSB_CSRF_TOKEN || '');
        }
      } catch (_e) {}
    }
    return token;
  }

  function mediaUploadUrl(){
    try {
      const path = String(window.location.pathname || '');
      const dir = path.replace(/\/[^/]*$/, '/');
      return (dir || './') + 'ajax/post_media_upload.php';
    } catch (_e) {
      return 'ajax/post_media_upload.php';
    }
  }

  function uploadOneFile(file, onByteProgress){
    return new Promise(function(resolve){
      const csrf = createPostCsrf();
      const fd = new FormData();
      fd.append('csrf_token', csrf);
      fd.append('attachments[]', file, file.name || 'upload.bin');

      const xhr = new XMLHttpRequest();
      xhr.open('POST', mediaUploadUrl(), true);
      xhr.withCredentials = true;
      xhr.responseType = 'text';
      try { xhr.setRequestHeader('Accept', 'application/json'); } catch (_a) {}
      try { xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest'); } catch (_x) {}
      if (csrf) {
        try { xhr.setRequestHeader('X-CSRF-Token', csrf); } catch (_h) {}
      }
      xhr.upload.onprogress = function(ev){
        if (!ev.lengthComputable) return;
        if (typeof onByteProgress === 'function') onByteProgress(ev.loaded, ev.total, false);
      };
      xhr.upload.onload = function(){
        // Bytes left the browser — server is still saving. Keep bar near done.
        if (typeof onByteProgress === 'function') {
          const total = Math.max(1, Number(file && file.size) || 1);
          onByteProgress(total, total, true);
        }
      };
      xhr.onload = function(){
        const data = parseUploadJson(xhr.responseText);
        if (!data || !data.ok || !Array.isArray(data.files) || !data.files.length) {
          let err = data && data.error ? String(data.error) : ('http_' + String(xhr.status || 0));
          if (data && Array.isArray(data.errors) && data.errors[0] && data.errors[0].error) {
            err = String(data.errors[0].error);
          }
          const message = data && data.message ? String(data.message) : '';
          resolve({ ok: false, error: err, message: message });
          return;
        }
        resolve({ ok: true, files: data.files });
      };
      xhr.onerror = function(){ resolve({ ok: false, error: 'network', message: 'Network error while uploading.' }); };
      xhr.send(fd);
    });
  }

  function uploadSelectedFiles(fileList, opts){
    opts = opts || {};
    const replaceCard = opts.replaceCard || null;
    if (!fileList || !fileList.length) return;
    const files = Array.prototype.slice.call(fileList).map(function(f){
      try { f.__objectUrl = URL.createObjectURL(f); } catch (_e) { f.__objectUrl = ''; }
      return f;
    });
    if (replaceCard && files.length > 1) files.splice(1);
    uploading = true;
    activeUploads = files.length;
    syncSubmitEnabled();
    setProgress(3, true);
    setStatus('Uploading ' + files.length + ' slide' + (files.length > 1 ? 's' : '') + '…', false);

    // Clear input immediately so picking the same file again still works,
    // and submit never re-sends original bytes.
    if (fileInput) fileInput.value = '';

    const loaded = files.map(function(){ return 0; });
    const totals = files.map(function(f){ return Math.max(1, Number(f && f.size) || 1); });
    const saving = files.map(function(){ return false; });
    const done = files.map(function(){ return false; });

    function refreshProgress(){
      let bytesLoaded = 0;
      let bytesTotal = 0;
      let savingCount = 0;
      for (let i = 0; i < files.length; i++) {
        bytesTotal += totals[i];
        bytesLoaded += Math.min(totals[i], loaded[i]);
        if (saving[i] && !done[i]) savingCount++;
      }
      const frac = bytesTotal > 0 ? (bytesLoaded / bytesTotal) : 0;
      // Reserve the last 8% for server save so the bar never freezes at ~90%.
      let pct = Math.round(frac * 92);
      if (savingCount > 0) pct = Math.max(pct, 94);
      setProgress(Math.min(99, Math.max(3, pct)), true);
      const activeIdx = saving.findIndex(function(v, i){ return !done[i]; });
      const labelIdx = activeIdx >= 0 ? activeIdx : 0;
      if (savingCount > 0) {
        setStatus('Saving ' + (labelIdx + 1) + '/' + files.length + '…', false);
      } else {
        setStatus('Uploading ' + Math.min(files.length, labelIdx + 1) + '/' + files.length + ' (' + formatBytes(totals[labelIdx] || 0) + ')…', false);
      }
    }

    function runOne(idx){
      const file = files[idx];
      const isImage = /^image\/(jpeg|jpg|png|webp)$/i.test(String(file.type || ''));
      const prep = isImage && file.size >= 1024 * 1024
        ? (setStatus('Optimizing ' + (idx + 1) + '/' + files.length + '…', false), compressImageFile(file))
        : Promise.resolve(file);

      return prep.then(function(out){
        if (out && Number(out.size || 0) > maxUploadBytes) {
          return {
            ok: false,
            error: 'too_large',
            message: 'File is too large (max ' + formatBytes(maxUploadBytes) + ').'
          };
        }
        totals[idx] = Math.max(1, Number(out && out.size) || Number(file.size) || 1);
        loaded[idx] = 0;
        refreshProgress();
        return uploadOneFile(out, function(byteLoaded, byteTotal, isSaving){
          totals[idx] = Math.max(1, byteTotal || totals[idx]);
          loaded[idx] = Math.min(totals[idx], byteLoaded || 0);
          saving[idx] = !!isSaving;
          refreshProgress();
        }).then(function(res){
          loaded[idx] = totals[idx];
          saving[idx] = false;
          done[idx] = true;
          refreshProgress();
          const tokens = [];
          if (res && res.ok && res.files) {
            res.files.forEach(function(item){
              if (item && item.token) {
                const meta = {
                  type: item.type || '',
                  web: item.web || '',
                  previewUrl: item.web || '',
                  objectUrl: (files[idx] && files[idx].__objectUrl) ? files[idx].__objectUrl : '',
                  fileType: String((files[idx] && files[idx].type) || '')
                };
                if (replaceCard) applyReplaceOnCard(replaceCard, String(item.token), meta);
                else addToken(String(item.token), meta);
                tokens.push(String(item.token));
              }
            });
          }
          return {
            ok: tokens.length > 0,
            error: (res && !res.ok && res.error) ? String(res.error) : '',
            message: (res && !res.ok && res.message) ? String(res.message) : ''
          };
        });
      });
    }

    // Parallel uploads (session lock released on server). Cap concurrency to 3.
    const concurrency = Math.min(3, files.length);
    let nextIdx = 0;
    let okFiles = 0;
    let lastError = '';
    let lastMessage = '';

    function worker(){
      if (nextIdx >= files.length) return Promise.resolve();
      const idx = nextIdx++;
      return runOne(idx).then(function(result){
        if (result && result.ok) {
          okFiles++;
        } else if (result && (result.error || result.message)) {
          lastError = String(result.error || '');
          lastMessage = String(result.message || '');
        }
        return worker();
      });
    }

    const workers = [];
    for (let w = 0; w < concurrency; w++) workers.push(worker());

    Promise.all(workers).then(function(){
      uploading = false;
      activeUploads = 0;
      syncSubmitEnabled();
      if (okFiles <= 0) {
        setStatus(humanUploadError(lastError, lastMessage) + ' You can still submit and files will upload then.', true);
        setTimeout(function(){ setProgress(0, false); }, 700);
        return;
      }
      setProgress(100, true);
      setStatus(pendingCount + ' file' + (pendingCount > 1 ? 's' : '') + ' ready — click submit.', false);
      setTimeout(function(){ setProgress(0, false); }, 450);
      try {
        if (window.parent && window.parent !== window) {
          window.parent.postMessage({ type: 'msb-create-post-fit', force: 1 }, '*');
        }
      } catch (_fitReady) {}
      setTimeout(function(){
        try {
          if (window.parent && window.parent !== window) {
            window.parent.postMessage({ type: 'msb-create-post-fit', force: 1 }, '*');
          }
        } catch (_fitReady2) {}
      }, 120);
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
  const addSlideBtn = document.getElementById('createPostAddSlideBtn');
  if (addSlideBtn && fileInput) {
    addSlideBtn.addEventListener('click', function(){
      replaceTargetCard = null;
      fileInput.click();
    });
  }
  if (replaceFileInput) {
    replaceFileInput.addEventListener('change', function(){
      if (replaceFileInput.files && replaceFileInput.files.length) {
        const card = replaceTargetCard;
        replaceTargetCard = null;
        uploadSelectedFiles(replaceFileInput.files, { replaceCard: card });
      }
      replaceFileInput.value = '';
    });
  }

  const slidesBoxEl = document.getElementById('createPostSlides');
  if (slidesBoxEl) {
    slidesBoxEl.addEventListener('click', function(ev){
      const replaceBtn = ev.target && ev.target.closest ? ev.target.closest('.create-post-slide-replace') : null;
      if (replaceBtn && slidesBoxEl.contains(replaceBtn)) {
        ev.preventDefault();
        ev.stopPropagation();
        const card = replaceBtn.closest('.create-post-slide');
        if (!card) return;
        replaceTargetCard = card;
        if (replaceFileInput) replaceFileInput.click();
        return;
      }
      const btn = ev.target && ev.target.closest ? ev.target.closest('.create-post-slide-remove') : null;
      if (!btn || !slidesBoxEl.contains(btn)) return;
      ev.preventDefault();
      ev.stopPropagation();
      const card = btn.closest('.create-post-slide');
      if (card) removeSlideCard(card);
    });
  }

  // Editing an existing post with many slides: keep the latest in front.
  (function(){
    const box = document.getElementById('createPostSlides');
    if (!box) return;
    const cards = box.querySelectorAll('.create-post-slide');
    if (!cards.length) return;
    scrollSlidesToLatest(cards[cards.length - 1]);
  })();

  f.addEventListener('submit', function(ev){
    ev.preventDefault();
    ev.stopPropagation();
    if (typeof ev.stopImmediatePropagation === 'function') ev.stopImmediatePropagation();
    if (!qaValidatePost(f)) {
      return;
    }

    if (uploading) {
      setStatus('Please wait for media upload to finish…', true);
      return;
    }

    if (window.MSBDeviceProfile && typeof window.MSBDeviceProfile.bindForm === 'function') {
      window.MSBDeviceProfile.bindForm(f);
    }
    syncReturnToFromVisibility();

    const fd = new FormData(f);
    fd.set('ajax', '1');
    // Guarantee people tags are posted (chips → hidden → FormData).
    try {
      var tagger = window.__msbCreatePostTagger;
      if (tagger && typeof tagger.syncHidden === 'function') tagger.syncHidden();
      var tagHidden = document.getElementById('createPostTaggedUserIds');
      var tagIds = tagHidden ? String(tagHidden.value || '').trim() : '';
      if (!tagIds && tagger && typeof tagger.getIds === 'function') {
        tagIds = tagger.getIds().join(',');
      }
      fd.set('tagged_user_ids', tagIds);
    } catch (_tagFd) {}
    // Guarantee edit id is sent even if the hidden field was wiped by a partial DOM refresh.
    const postIdInput = document.getElementById('createPostId') || f.querySelector('input[name="post_id"]');
    const editPostId = postIdInput ? Number(postIdInput.value || 0) : 0;
    if (editPostId > 0) {
      fd.set('post_id', String(editPostId));
    }
    // If files were pre-uploaded, never re-send the picker files (that can drop extras under max_file_uploads).
    if (pendingCount > 0) {
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
      headers: {
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest'
      }
    }).then(function(res){
      return res.json().catch(function(){ return null; }).then(function(data){
        return { res: res, data: data };
      });
    }).then(function(payload){
      clearInterval(tick);
      setProgress(100, true);
      const data = payload && payload.data;
      if (data && data.ok) {
        const postId = Number(data.post_id || 0);
        const visOut = String(data.visibility || '').toLowerCase();
        let target = String(data.redirect || '');
        if (!target) {
          if (visOut === 'private') {
            target = 'profile.php?tab=posts&post=' + String(postId || '') + '&fresh=1';
          } else if (visOut === 'public') {
            target = 'home.php?tab=discover&post=' + String(postId || '') + '&fresh=1';
          } else {
            target = 'home.php?tab=for-you&post=' + String(postId || '') + '&fresh=1';
          }
        }
        // Private posts always land on Profile → Posts (never Gallery grid).
        if (visOut === 'private' && !data.story) {
          target = 'profile.php?tab=posts&post=' + String(postId || '') + '&fresh=1';
        }
        /* Prefer unified home tabs when server still returns legacy feed/public. */
        try {
          if (/^(feed|public)\.php/i.test(target)) {
            var mapped = new URL(target, window.location.href);
            var isPublicDest = /^public\.php/i.test(mapped.pathname.split('/').pop() || '');
            var storyKey = mapped.searchParams.has('story_post');
            var idVal = mapped.searchParams.get(storyKey ? 'story_post' : 'post') || String(postId || '');
            var freshVal = mapped.searchParams.get('fresh') || '1';
            target = 'home.php?tab=' + (isPublicDest ? 'discover' : 'for-you')
              + '&' + (storyKey ? 'story_post' : 'post') + '=' + encodeURIComponent(idVal)
              + '&fresh=' + encodeURIComponent(freshVal);
          }
        } catch (_map) {}
        setStatus('Posted!', false);
        // Modal: soft-refresh parent feed (no full page reload) for ~2–3s publish.
        try {
          if (isModal && window.parent && window.parent !== window) {
            try { window.parent.__msbCreatePostNavigating = true; } catch (_navFlag) {}
            window.parent.postMessage({
              type: 'msb-create-post-done',
              postId: postId,
              redirect: target,
              story: !!(data.story),
              visibility: visOut || ((returnToInput && /tab=discover/i.test(String(returnToInput.value || ''))) ? 'public' : 'friends'),
              surface: String(data.surface || '')
            }, '*');
            // If parent handled it, this iframe is torn down. Otherwise hard-navigate.
            setTimeout(function(){
              try {
                if (window.parent && window.parent.__msbCreatePostNavigating) return;
              } catch (_handled) {}
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
<?php include __DIR__ . '/includes/mention_autocomplete.js.php'; ?>
<script>
(function(){
  if (!window.MSBMentionAC) return;
  window.MSBMentionAC.bindRoot(document);
  var tagger = window.MSBMentionAC.mountTagPeople({
    wrap: document.getElementById('createPostTagPeopleChips'),
    hidden: document.getElementById('createPostTaggedUserIds'),
    input: document.getElementById('createPostTagPeopleInput')
  });
  window.__msbCreatePostTagger = tagger || null;
  var seed = <?php echo json_encode(array_map(static function ($u) {
    return [
      'id' => (int)($u['id'] ?? 0),
      'username' => (string)($u['username'] ?? ''),
      'name' => (string)($u['name'] ?? ''),
      'image' => (string)($u['image'] ?? ''),
    ];
  }, $editTaggedUsers ?? []), JSON_UNESCAPED_SLASHES); ?>;
  if (tagger && Array.isArray(seed)) {
    seed.forEach(function(u){ if (u && u.id) tagger.addUser(u); });
  }
  // Keep Tag People panel open when chips already exist (edit / restore).
  try {
    if (tagger && typeof tagger.getIds === 'function' && tagger.getIds().length) {
      var tagPanel = document.querySelector('.msb-composer-panel[data-panel="tag"]');
      if (tagPanel) {
        tagPanel.hidden = false;
        tagPanel.classList.add('is-open');
      }
    }
  } catch (_tagOpen) {}
})();
</script>
<?php if ($isModalCreate): ?>
<script>
(function(){
  var form = document.getElementById('createPostForm');
  if (!form || !form.classList.contains('msb-composer')) return;

  function fitParent(){
    try {
      if (window.parent && window.parent !== window) {
        window.parent.postMessage({ type: 'msb-create-post-fit', force: 1 }, '*');
      }
    } catch (_e) {}
  }

  function syncTools(){
    form.querySelectorAll('.msb-composer-tool[data-open-panel]').forEach(function(btn){
      var key = btn.getAttribute('data-open-panel');
      var panel = form.querySelector('.msb-composer-panel[data-panel="'+key+'"]');
      var open = !!(panel && !panel.hasAttribute('hidden'));
      btn.classList.toggle('is-active', open);
    });
  }

  function openPanel(key, opts){
    opts = opts || {};
    var panel = form.querySelector('.msb-composer-panel[data-panel="'+key+'"]');
    if (!panel) return;
    panel.hidden = false;
    panel.classList.add('is-open');
    syncTools();
    fitParent();
    if (key === 'media' && opts.pickFile) {
      var addBtn = document.getElementById('createPostAddSlideBtn');
      if (addBtn) setTimeout(function(){ addBtn.click(); }, 30);
    }
    if (key === 'tag') {
      var tagInput = document.getElementById('createPostTagPeopleInput');
      if (tagInput) setTimeout(function(){ tagInput.focus(); }, 40);
    }
    if (key === 'title') {
      var titleInput = document.getElementById('createPostTitle');
      if (titleInput) setTimeout(function(){ titleInput.focus(); }, 40);
    }
    if (key === 'music') {
      var musicInput = form.querySelector('input[name="music_title"]');
      if (musicInput) setTimeout(function(){ musicInput.focus(); }, 40);
    }
    if (key === 'feeling') {
      var feelingList = document.querySelector('.msb-feeling-item.is-selected') || document.querySelector('.msb-feeling-item');
      if (feelingList) setTimeout(function(){ try { feelingList.focus(); } catch (_e) {} }, 40);
    }
    if (key === 'location') {
      var locationInput = document.getElementById('createPostLocation');
      if (locationInput) setTimeout(function(){ locationInput.focus(); }, 40);
    }
    if (key === 'link') {
      var linkInput = document.getElementById('createPostLinkInput');
      if (linkInput) setTimeout(function(){ linkInput.focus(); }, 40);
    }
  }

  function closePanel(key){
    var panel = form.querySelector('.msb-composer-panel[data-panel="'+key+'"]');
    if (!panel) return;
    /* Keep media dropzone always visible in the redesigned modal. */
    if (key === 'media') return;
    panel.hidden = true;
    panel.classList.remove('is-open');
    syncTools();
    fitParent();
  }

  window.msbComposerOpenPanel = function(key){ openPanel(key, {}); };

  /* Always keep media dropzone open in the redesigned modal. */
  openPanel('media', {});

  var editIdEl = document.getElementById('createPostId');
  if (editIdEl && Number(editIdEl.value || 0) > 0) {
    openPanel('media', {});
  }
  var feelingPrefill = document.getElementById('createPostFeeling');
  if (feelingPrefill && String(feelingPrefill.value || '').trim()) openPanel('feeling', {});
  var locationLabelPrefill = document.getElementById('createPostLocationLabel');
  if (locationLabelPrefill && String(locationLabelPrefill.value || '').trim()) openPanel('location', {});
  var linkUrlPrefill = document.getElementById('createPostLinkUrl');
  if (linkUrlPrefill && String(linkUrlPrefill.value || '').trim()) openPanel('link', {});

  /* Feeling / Activity picker */
  (function wireCreatePostFeeling(){
    var input = document.getElementById('createPostFeeling');
    var clearBtn = document.getElementById('createPostFeelingClear');
    var feelingsList = document.getElementById('createPostFeelingListFeelings');
    var activitiesList = document.getElementById('createPostFeelingListActivities');
    var tabs = document.querySelectorAll('.msb-feeling-tab');
    if (!input) return;

    function allItems(){
      return Array.prototype.slice.call(document.querySelectorAll('.msb-feeling-item'));
    }

    function setSelected(btn){
      allItems().forEach(function(el){
        var on = el === btn;
        el.classList.toggle('is-selected', on);
        el.setAttribute('aria-selected', on ? 'true' : 'false');
      });
      if (!btn) {
        input.value = '';
        if (clearBtn) clearBtn.hidden = true;
        fitParent();
        return;
      }
      var value = String(btn.getAttribute('data-feeling-value') || '').trim();
      var emoji = String(btn.getAttribute('data-feeling-emoji') || '').trim();
      input.value = (emoji ? (emoji + ' ') : '') + value;
      if (clearBtn) clearBtn.hidden = !input.value;
      fitParent();
    }

    allItems().forEach(function(btn){
      btn.addEventListener('click', function(){
        if (btn.classList.contains('is-selected')) {
          setSelected(null);
          return;
        }
        setSelected(btn);
      });
    });

    tabs.forEach(function(tab){
      tab.addEventListener('click', function(){
        var key = String(tab.getAttribute('data-feeling-tab') || 'feelings');
        tabs.forEach(function(t){
          var on = t === tab;
          t.classList.toggle('is-active', on);
          t.setAttribute('aria-selected', on ? 'true' : 'false');
        });
        if (feelingsList) feelingsList.hidden = key !== 'feelings';
        if (activitiesList) activitiesList.hidden = key !== 'activities';
        fitParent();
      });
    });

    if (clearBtn) {
      clearBtn.addEventListener('click', function(ev){
        ev.preventDefault();
        setSelected(null);
      });
    }

    var selected = document.querySelector('.msb-feeling-item.is-selected');
    if (selected && String(selected.getAttribute('data-feeling-kind') || '') === 'activity') {
      var actTab = document.querySelector('.msb-feeling-tab[data-feeling-tab="activities"]');
      if (actTab) actTab.click();
    }
  })();

  /* Attach website link (paste from browser) */
  (function wireCreatePostLink(){
    var input = document.getElementById('createPostLinkInput');
    var urlEl = document.getElementById('createPostLinkUrl');
    var titleEl = document.getElementById('createPostLinkTitle');
    var descEl = document.getElementById('createPostLinkDescription');
    var tagsEl = document.getElementById('createPostLinkTags');
    var imageEl = document.getElementById('createPostLinkImage');
    var statusEl = document.getElementById('createPostLinkStatus');
    var clearBtn = document.getElementById('createPostLinkClear');
    var preview = document.getElementById('createPostLinkPreview');
    var editFields = document.getElementById('createPostLinkEditFields');
    var hostEl = document.getElementById('createPostLinkHost');
    var titlePrev = document.getElementById('createPostLinkPreviewTitle');
    var descPrev = document.getElementById('createPostLinkPreviewDesc');
    var chipsPrev = document.getElementById('createPostLinkPreviewChips');
    var mediaWrap = document.getElementById('createPostLinkPreviewMedia');
    var mediaImg = document.getElementById('createPostLinkPreviewImg');
    if (!input || !urlEl) return;

    var timer = null;
    var seq = 0;
    var endpoint = 'ajax/post_link_preview.php';
    var defaultDesc = 'Open this website to see the full page, details, and latest updates.';

    function setStatus(msg, isError){
      if (!statusEl) return;
      if (!msg) {
        statusEl.hidden = true;
        statusEl.textContent = '';
        statusEl.classList.remove('is-error');
        return;
      }
      statusEl.hidden = false;
      statusEl.textContent = msg;
      statusEl.classList.toggle('is-error', !!isError);
    }

    function parseTags(raw){
      var out = [];
      String(raw || '').split(/[,|;]+/).forEach(function(t){
        t = String(t || '').replace(/\s+/g, ' ').trim();
        if (!t || t.length > 32) return;
        if (out.indexOf(t) === -1) out.push(t);
      });
      return out.slice(0, 4);
    }

    function renderChips(tags){
      if (!chipsPrev) return;
      tags = Array.isArray(tags) ? tags : [];
      chipsPrev.innerHTML = '';
      if (!tags.length) {
        chipsPrev.hidden = true;
        return;
      }
      tags.forEach(function(tag){
        var span = document.createElement('span');
        span.className = 'msb-link-preview-chip';
        span.textContent = tag;
        chipsPrev.appendChild(span);
      });
      chipsPrev.hidden = false;
    }

    function syncPreviewFromFields(){
      var title = titleEl ? String(titleEl.value || '').trim() : '';
      var desc = descEl ? String(descEl.value || '').trim() : '';
      var tags = tagsEl ? parseTags(tagsEl.value) : [];
      if (titlePrev) titlePrev.textContent = title || (hostEl ? hostEl.textContent : '') || 'Visit Website';
      if (descPrev) descPrev.textContent = desc || defaultDesc;
      renderChips(tags);
    }

    function hidePreview(){
      if (preview) preview.hidden = true;
      if (editFields) editFields.hidden = true;
      if (hostEl) hostEl.textContent = '';
      if (titlePrev) titlePrev.textContent = '';
      if (descPrev) descPrev.textContent = '';
      renderChips([]);
      if (mediaImg) mediaImg.removeAttribute('src');
      if (mediaWrap) {
        mediaWrap.hidden = true;
        mediaWrap.classList.remove('is-logo');
      }
    }

    function clearSavedLink(){
      urlEl.value = '';
      if (titleEl) titleEl.value = '';
      if (descEl) descEl.value = '';
      if (tagsEl) tagsEl.value = '';
      if (imageEl) imageEl.value = '';
    }

    function clearLink(){
      clearSavedLink();
      input.value = '';
      hidePreview();
      if (clearBtn) clearBtn.hidden = true;
      setStatus('');
      input.focus();
      fitParent();
    }

    function looksLikeUrl(raw){
      raw = String(raw || '').trim();
      if (!raw) return false;
      if (/\s/.test(raw)) return false;
      if (/^https?:\/\//i.test(raw)) return true;
      return /^(?:www\.)?[a-z0-9-]+(?:\.[a-z0-9-]+)+(?:[/:?#].*)?$/i.test(raw);
    }

    function applyLink(link, partial){
      if (!link || !link.url) return;
      urlEl.value = String(link.url || '').slice(0, 500);
      var title = String(link.title || '').slice(0, 180);
      var desc = String(link.description || '').slice(0, 280) || defaultDesc;
      var tagsCsv = String(link.tags_csv || '').trim();
      if (!tagsCsv && Array.isArray(link.tags)) tagsCsv = link.tags.join(', ');
      if (titleEl) titleEl.value = title;
      if (descEl) descEl.value = desc;
      if (tagsEl) tagsEl.value = tagsCsv.slice(0, 280);
      var image = String(link.image || '').trim().slice(0, 500);
      var imageFallback = String(link.image_fallback || '').trim();
      var isLogo = !!link.is_logo;
      if (imageEl) imageEl.value = image;
      input.value = urlEl.value;
      if (hostEl) hostEl.textContent = String(link.host ? ((/^www\./i.test(link.host) ? link.host : ('www.' + link.host))) : '');
      syncPreviewFromFields();
      if (image && mediaImg && mediaWrap) {
        mediaImg.onerror = null;
        if (imageFallback && imageFallback !== image) {
          mediaImg.onerror = function(){
            mediaImg.onerror = null;
            mediaImg.src = imageFallback;
            mediaWrap.classList.add('is-logo');
          };
        }
        mediaImg.src = image;
        mediaWrap.hidden = false;
        mediaWrap.classList.toggle('is-logo', isLogo);
      } else {
        if (mediaImg) {
          mediaImg.onerror = null;
          mediaImg.removeAttribute('src');
        }
        if (mediaWrap) {
          mediaWrap.hidden = true;
          mediaWrap.classList.remove('is-logo');
        }
      }
      if (preview) preview.hidden = false;
      if (editFields) editFields.hidden = false;
      if (clearBtn) clearBtn.hidden = false;
      setStatus(partial
        ? 'Link attached. Edit the title, description, and topics below so friends see clear details.'
        : 'Link attached. You can edit the title, description, and topics below.');
      fitParent();
    }

    function rejectInvalid(msg){
      clearSavedLink();
      hidePreview();
      if (clearBtn) clearBtn.hidden = !String(input.value || '').trim();
      setStatus(msg || 'Paste a full website URL (example: https://www.apple.com).', true);
      fitParent();
    }

    function fetchPreview(raw){
      raw = String(raw || '').trim();
      if (!raw) {
        clearLink();
        return;
      }
      if (!looksLikeUrl(raw)) {
        rejectInvalid('That does not look like a valid link. Paste a full URL from your browser.');
        return;
      }
      var mySeq = ++seq;
      setStatus('Fetching link preview…');
      if (clearBtn) clearBtn.hidden = false;
      fetch(endpoint + '?url=' + encodeURIComponent(raw), {
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json' }
      })
        .then(function(r){ return r.json(); })
        .then(function(data){
          if (mySeq !== seq) return;
          if (!data || !data.ok || !data.link) {
            rejectInvalid((data && data.error) || 'Could not read that link.');
            return;
          }
          applyLink(data.link, !!data.preview_partial);
        })
        .catch(function(){
          if (mySeq !== seq) return;
          rejectInvalid('Could not fetch that link right now. Try again.');
        });
    }

    function schedule(){
      clearTimeout(timer);
      var raw = String(input.value || '').trim();
      if (!raw) {
        clearSavedLink();
        hidePreview();
        if (clearBtn) clearBtn.hidden = true;
        setStatus('');
        return;
      }
      if (clearBtn) clearBtn.hidden = false;
      if (!looksLikeUrl(raw)) {
        clearSavedLink();
        hidePreview();
        if (raw.length >= 3) {
          setStatus('Paste a full website URL (example: https://www.apple.com).', true);
        } else {
          setStatus('');
        }
        return;
      }
      timer = setTimeout(function(){ fetchPreview(raw); }, 280);
    }

    input.addEventListener('input', schedule);
    input.addEventListener('paste', function(){ setTimeout(schedule, 0); });
    input.addEventListener('keydown', function(ev){
      if (ev.key === 'Enter') {
        ev.preventDefault();
        fetchPreview(input.value);
      }
    });
    if (clearBtn) {
      clearBtn.addEventListener('click', function(ev){
        ev.preventDefault();
        clearLink();
      });
    }
    ['input', 'change', 'keyup'].forEach(function(evt){
      if (titleEl) titleEl.addEventListener(evt, syncPreviewFromFields);
      if (descEl) descEl.addEventListener(evt, syncPreviewFromFields);
      if (tagsEl) tagsEl.addEventListener(evt, syncPreviewFromFields);
    });
    if (urlEl.value) {
      if (editFields) editFields.hidden = false;
      syncPreviewFromFields();
    }
  })();

  /* Location typeahead + map-link paste */
  (function wireCreatePostLocationSearch(){
    var input = document.getElementById('createPostLocation');
    var labelEl = document.getElementById('createPostLocationLabel');
    var latEl = document.getElementById('createPostLocationLat');
    var lngEl = document.getElementById('createPostLocationLng');
    var urlEl = document.getElementById('createPostLocationUrl');
    var resultsEl = document.getElementById('createPostLocationResults');
    var statusEl = document.getElementById('createPostLocationStatus');
    var clearBtn = document.getElementById('createPostLocationClear');
    var selectedWrap = document.getElementById('createPostLocationSelected');
    var selectedText = document.getElementById('createPostLocationSelectedText');
    if (!input || !labelEl || !resultsEl) return;

    var timer = null;
    var seq = 0;
    var activeIdx = -1;
    var places = [];
    var endpoint = 'ajax/post_location_search.php';

    function esc(s){
      return String(s == null ? '' : s)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
    }

    function looksLikeUrl(q){
      q = String(q || '').trim();
      return /^https?:\/\//i.test(q) || /(?:maps\.google|google\.[^/\s]+\/maps|maps\.app\.goo\.gl|goo\.gl\/maps|maps\.apple\.com|openstreetmap\.org)/i.test(q);
    }

    function setStatus(msg, isError){
      if (!statusEl) return;
      if (!msg) {
        statusEl.hidden = true;
        statusEl.textContent = '';
        statusEl.classList.remove('is-error');
        return;
      }
      statusEl.hidden = false;
      statusEl.textContent = msg;
      statusEl.classList.toggle('is-error', !!isError);
    }

    function hideResults(){
      resultsEl.hidden = true;
      resultsEl.innerHTML = '';
      input.setAttribute('aria-expanded', 'false');
      activeIdx = -1;
      places = [];
    }

    function paintSelected(label){
      label = String(label || '').trim();
      if (selectedWrap) selectedWrap.hidden = !label;
      if (selectedText) selectedText.textContent = label;
      if (clearBtn) clearBtn.hidden = !label && !String(input.value || '').trim();
    }

    function applyPlace(place, sourceUrl){
      if (!place) return;
      var label = String(place.label || place.display_name || '').trim();
      if (!label) return;
      labelEl.value = label.slice(0, 120);
      if (latEl) latEl.value = place.lat != null ? String(place.lat) : '';
      if (lngEl) lngEl.value = place.lng != null ? String(place.lng) : '';
      if (urlEl) urlEl.value = String(sourceUrl || '').trim();
      input.value = label;
      paintSelected(label);
      hideResults();
      setStatus('');
      fitParent();
    }

    function clearLocation(){
      labelEl.value = '';
      if (latEl) latEl.value = '';
      if (lngEl) lngEl.value = '';
      if (urlEl) urlEl.value = '';
      input.value = '';
      paintSelected('');
      hideResults();
      setStatus('');
      input.focus();
      fitParent();
    }

    function renderResults(list){
      places = Array.isArray(list) ? list : [];
      activeIdx = places.length ? 0 : -1;
      if (!places.length) {
        hideResults();
        return;
      }
      var html = '';
      places.forEach(function(p, i){
        var title = String(p.label || p.name || '').trim();
        var sub = String(p.display_name || '').trim();
        if (sub && sub === title) sub = [p.city, p.state, p.country].filter(Boolean).join(', ');
        html += '<li role="none">'+
          '<button type="button" class="msb-loc-result'+(i === activeIdx ? ' is-active' : '')+'" role="option" data-idx="'+i+'" aria-selected="'+(i === activeIdx ? 'true' : 'false')+'">'+
            '<span class="msb-loc-result-ic"><i class="fa fa-map-marker" aria-hidden="true"></i></span>'+
            '<span class="msb-loc-result-text">'+
              '<span class="msb-loc-result-title">'+esc(title)+'</span>'+
              (sub ? '<span class="msb-loc-result-sub">'+esc(sub)+'</span>' : '')+
            '</span>'+
          '</button>'+
        '</li>';
      });
      resultsEl.innerHTML = html;
      resultsEl.hidden = false;
      input.setAttribute('aria-expanded', 'true');
      fitParent();
    }

    function setActive(idx){
      if (!places.length) return;
      activeIdx = Math.max(0, Math.min(places.length - 1, idx));
      var buttons = resultsEl.querySelectorAll('.msb-loc-result');
      buttons.forEach(function(btn, i){
        var on = i === activeIdx;
        btn.classList.toggle('is-active', on);
        btn.setAttribute('aria-selected', on ? 'true' : 'false');
        if (on && typeof btn.scrollIntoView === 'function') {
          try { btn.scrollIntoView({ block: 'nearest' }); } catch (_e) {}
        }
      });
    }

    function searchNow(q){
      q = String(q || '').trim();
      if (!q) {
        hideResults();
        setStatus('');
        if (!labelEl.value) paintSelected('');
        return;
      }
      if (!looksLikeUrl(q) && q.length < 2) {
        hideResults();
        setStatus('Keep typing a place name…');
        return;
      }
      var mySeq = ++seq;
      setStatus(looksLikeUrl(q) ? 'Reading map link…' : 'Searching places…');
      fetch(endpoint + '?q=' + encodeURIComponent(q), {
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json' }
      })
        .then(function(r){ return r.json(); })
        .then(function(data){
          if (mySeq !== seq) return;
          if (!data || !data.ok || !Array.isArray(data.places) || !data.places.length) {
            hideResults();
            setStatus((data && data.error) || 'No places found.', true);
            return;
          }
          setStatus(data.places.length === 1 ? 'Select the place below.' : (data.places.length + ' places found — pick one.'));
          renderResults(data.places);
          // Auto-select single URL reverse-geocode hit for convenience, still show in list.
          if (looksLikeUrl(q) && data.places.length === 1) {
            /* keep list visible so user confirms */
          }
        })
        .catch(function(){
          if (mySeq !== seq) return;
          hideResults();
          setStatus('Could not search right now. Try again.', true);
        });
    }

    function scheduleSearch(){
      var q = String(input.value || '');
      if (clearBtn) clearBtn.hidden = !q.trim() && !String(labelEl.value || '').trim();
      // Typing after a selected place clears the committed selection until they pick again.
      if (String(labelEl.value || '') && q.trim() !== String(labelEl.value || '').trim()) {
        labelEl.value = '';
        if (latEl) latEl.value = '';
        if (lngEl) lngEl.value = '';
        if (urlEl) urlEl.value = '';
        paintSelected('');
      }
      clearTimeout(timer);
      timer = setTimeout(function(){ searchNow(q); }, looksLikeUrl(q) ? 180 : 280);
    }

    input.addEventListener('input', scheduleSearch);
    input.addEventListener('paste', function(){
      setTimeout(scheduleSearch, 0);
    });
    input.addEventListener('keydown', function(ev){
      if (resultsEl.hidden) {
        if (ev.key === 'Enter' && String(input.value || '').trim()) {
          ev.preventDefault();
          searchNow(input.value);
        }
        return;
      }
      if (ev.key === 'ArrowDown') {
        ev.preventDefault();
        setActive(activeIdx + 1);
      } else if (ev.key === 'ArrowUp') {
        ev.preventDefault();
        setActive(activeIdx - 1);
      } else if (ev.key === 'Enter') {
        if (activeIdx >= 0 && places[activeIdx]) {
          ev.preventDefault();
          applyPlace(places[activeIdx], looksLikeUrl(input.value) ? String(input.value).trim() : '');
        }
      } else if (ev.key === 'Escape') {
        hideResults();
        setStatus('');
      }
    });

    resultsEl.addEventListener('click', function(ev){
      var btn = ev.target && ev.target.closest ? ev.target.closest('.msb-loc-result') : null;
      if (!btn || !resultsEl.contains(btn)) return;
      ev.preventDefault();
      var idx = Number(btn.getAttribute('data-idx') || -1);
      if (idx >= 0 && places[idx]) {
        applyPlace(places[idx], looksLikeUrl(input.value) ? String(input.value).trim() : '');
      }
    });

    if (clearBtn) {
      clearBtn.addEventListener('click', function(ev){
        ev.preventDefault();
        clearLocation();
      });
    }

    document.addEventListener('click', function(ev){
      var wrap = document.getElementById('createPostLocationSearch');
      if (!wrap || !ev.target) return;
      if (wrap.contains(ev.target)) return;
      hideResults();
    });

    if (String(labelEl.value || '').trim()) {
      paintSelected(labelEl.value);
    }
  })();

  function wirePick(id){
    var el = document.getElementById(id);
    if (!el) return;
    el.addEventListener('click', function(ev){
      ev.preventDefault();
      openPanel('media', { pickFile: true });
    });
  }
  wirePick('createPostChooseFilesBtn');
  wirePick('createPostAddMoreBtn');

  var cloudBtn = document.getElementById('createPostCloudBtn');
  if (cloudBtn) {
    cloudBtn.addEventListener('click', function(ev){
      ev.preventDefault();
      openPanel('media', { pickFile: true });
    });
  }

  var settingsBtn = document.getElementById('createPostSettingsBtn');
  if (settingsBtn) {
    settingsBtn.addEventListener('click', function(ev){
      ev.preventDefault();
      var audience = document.getElementById('createPostVisibility');
      if (audience) audience.focus();
    });
  }
  var scheduleBtn = document.getElementById('createPostScheduleBtn');
  if (scheduleBtn) {
    scheduleBtn.addEventListener('click', function(ev){
      ev.preventDefault();
      try { window.alert('Scheduling is coming soon.'); } catch (_e) {}
    });
  }

  function bindCounter(inputId, countId, max, pretty){
    var input = document.getElementById(inputId);
    var count = document.getElementById(countId);
    if (!input || !count) return;
    function paint(){
      var n = String(input.value || '').length;
      count.textContent = pretty ? (n.toLocaleString() + '/' + max.toLocaleString()) : (n + '/' + max);
    }
    input.addEventListener('input', paint);
    paint();
  }
  bindCounter('createPostTitle', 'createPostTitleCount', 100, false);
  bindCounter('createPostBody', 'createPostBodyCount', 5000, true);

  /* Click a thumbnail → edit that slide's subtitle in a "What's on your mind" box. */
  (function(){
    var slidesBox = document.getElementById('createPostSlides');
    var wrap = document.getElementById('createPostSlideCaptionWrap');
    var field = document.getElementById('createPostSlideCaption');
    var countEl = document.getElementById('createPostSlideCaptionCount');
    var indexEl = document.getElementById('createPostSlideCaptionIndex');
    var closeBtn = document.getElementById('createPostSlideCaptionClose');
    if (!slidesBox || !wrap || !field) return;

    var selectedCard = null;

    function slideTitleInput(card){
      if (!card) return null;
      return card.querySelector('input[name^="slide_title["], input[name^="existing_slide_title["]');
    }

    function slideIndex(card){
      var cards = slidesBox.querySelectorAll('.create-post-slide');
      for (var i = 0; i < cards.length; i++) {
        if (cards[i] === card) return i + 1;
      }
      return 0;
    }

    function paintCount(){
      if (!countEl) return;
      countEl.textContent = String(field.value || '').length + '/500';
    }

    function clearSelection(){
      selectedCard = null;
      slidesBox.querySelectorAll('.create-post-slide.is-selected').forEach(function(el){
        el.classList.remove('is-selected');
      });
      wrap.hidden = true;
      field.value = '';
      paintCount();
      if (indexEl) indexEl.textContent = '';
      fitParent();
    }

    function selectCard(card, opts){
      opts = opts || {};
      if (!card || !slidesBox.contains(card)) {
        clearSelection();
        return;
      }
      slidesBox.querySelectorAll('.create-post-slide.is-selected').forEach(function(el){
        el.classList.remove('is-selected');
      });
      selectedCard = card;
      card.classList.add('is-selected');
      var titleInput = slideTitleInput(card);
      field.value = titleInput ? String(titleInput.value || '') : '';
      paintCount();
      if (indexEl) {
        var n = slideIndex(card);
        indexEl.textContent = n ? ('#' + n) : '';
      }
      wrap.hidden = false;
      fitParent();
      if (!opts.skipFocus) {
        setTimeout(function(){
          try { field.focus(); } catch (_f) {}
        }, 40);
      }
    }

    window.msbSelectCreatePostSlide = function(card){ selectCard(card, { skipFocus: false }); };
    window.msbRefreshSlideCaption = function(removedCard){
      if (removedCard && selectedCard === removedCard) {
        var next = slidesBox.querySelector('.create-post-slide');
        if (next) selectCard(next, { skipFocus: true });
        else clearSelection();
        return;
      }
      if (selectedCard && !slidesBox.contains(selectedCard)) {
        clearSelection();
      } else if (selectedCard) {
        selectCard(selectedCard, { skipFocus: true });
      }
    };

    slidesBox.addEventListener('click', function(ev){
      if (ev.target.closest('.create-post-slide-remove, .create-post-slide-replace')) return;
      var card = ev.target.closest('.create-post-slide');
      if (!card || !slidesBox.contains(card)) return;
      ev.preventDefault();
      if (card.classList.contains('is-selected') && !wrap.hidden) {
        /* Keep open; focus caption again. */
        selectCard(card);
        return;
      }
      selectCard(card);
    });

    field.addEventListener('input', function(){
      paintCount();
      if (!selectedCard) return;
      var titleInput = slideTitleInput(selectedCard);
      if (titleInput) titleInput.value = field.value;
    });

    if (closeBtn) {
      closeBtn.addEventListener('click', function(ev){
        ev.preventDefault();
        clearSelection();
      });
    }
  })();

  /* Drop files onto dashed zone */
  var dropzone = form.querySelector('.msb-composer-dropzone');
  if (dropzone) {
    ['dragenter','dragover'].forEach(function(evt){
      dropzone.addEventListener(evt, function(e){ e.preventDefault(); e.stopPropagation(); dropzone.classList.add('is-drag'); });
    });
    ['dragleave','drop'].forEach(function(evt){
      dropzone.addEventListener(evt, function(e){ e.preventDefault(); e.stopPropagation(); dropzone.classList.remove('is-drag'); });
    });
    dropzone.addEventListener('drop', function(e){
      var files = e.dataTransfer && e.dataTransfer.files ? e.dataTransfer.files : null;
      var input = document.getElementById('createPostAttachments');
      if (!files || !files.length || !input) return;
      try {
        var dt = new DataTransfer();
        Array.prototype.forEach.call(files, function(f){ dt.items.add(f); });
        input.files = dt.files;
        input.dispatchEvent(new Event('change', { bubbles: true }));
      } catch (_err) {
        openPanel('media', { pickFile: true });
      }
    });
  }

  form.addEventListener('click', function(ev){
    var openBtn = ev.target.closest('[data-open-panel]');
    if (openBtn && form.contains(openBtn)) {
      try { closeEmojiPicker(); } catch (_ce) {}
      ev.preventDefault();
      var key = openBtn.getAttribute('data-open-panel');
      if (key === 'more') {
        openPanel('music', {});
        return;
      }
      var panel = form.querySelector('.msb-composer-panel[data-panel="'+key+'"]');
      /* Media dropzone stays open in modal — Photo/Video always picks files. */
      if (key === 'media') {
        openPanel(key, { pickFile: true });
        return;
      }
      if (panel && !panel.hasAttribute('hidden')) {
        closePanel(key);
        return;
      }
      openPanel(key, {});
      return;
    }
    var closeBtn = ev.target.closest('[data-close-panel]');
    if (closeBtn && form.contains(closeBtn)) {
      ev.preventDefault();
      closePanel(closeBtn.getAttribute('data-close-panel'));
    }
  });


  /* Emoji picker → insert into description or title (fixed so full box stays visible) */
  var emojiBtn = form.querySelector('[data-emoji-picker]');
  var emojiPicker = document.getElementById('msbComposerEmojiPicker');
  var emojiGrid = document.getElementById('msbComposerEmojiGrid');
  var emojiLabel = document.getElementById('msbComposerEmojiLabel');
  var emojiEmpty = document.getElementById('msbComposerEmojiEmpty');
  var emojiCats = document.getElementById('msbComposerEmojiCats');
  var emojiSearch = document.getElementById('msbComposerEmojiSearch');
  var emojiClose = document.getElementById('msbComposerEmojiClose');
  var emojiExpand = document.getElementById('msbComposerEmojiExpand');
  var emojiHost = form.querySelector('.msb-composer-addbar') || form;
  var bodyField = document.getElementById('createPostBody');
  var titleField = document.getElementById('createPostTitle');
  var lastTextField = bodyField;
  var activeCat = 'recents';
  var RECENT_KEY = 'msbComposerEmojiRecents';
  var DEFAULT_RECENTS = ["👍", "😂", "❤️", "😍", "😊", "🙏", "🔥", "👏", "😅", "😎", "🥰", "😭", "😮", "🤔", "🎉", "💯", "✨", "👀", "🙌", "💪", "🫡", "😴", "😇", "😜"];
  var CATEGORIES = [
    { id:"recents", label:"Frequently Used", icon:"fa-clock-o", emojis:null },
    { id:"smileys", label:"Smileys & People", icon:"fa-smile-o", emojis:["😀", "😃", "😄", "😁", "😆", "😅", "😂", "🤣", "😊", "😇", "🙂", "🙃", "😉", "😌", "😍", "🥰", "😘", "😗", "😙", "😚", "😋", "😛", "😝", "😜", "🤪", "🤨", "🧐", "🤓", "😎", "🥳", "😏", "😒", "😞", "😔", "😟", "😕", "🙁", "☹️", "😣", "😖", "😫", "😩", "🥺", "😢", "😭", "😤", "😠", "😡", "🤬", "🤯", "😳", "🥵", "🥶", "😱", "😨", "😰", "😥", "😓", "🤗", "🤔", "🫡", "🤭", "🤫", "🤥", "😶", "😐", "😑", "😬", "🙄", "😯", "😦", "😧", "😮", "😲", "🥱", "😴", "🤤", "😪", "😵", "🤐", "🥴", "🤢", "🤮", "🤧", "😷", "🤒", "🤕", "🤑", "🤠", "😈", "👿", "👻", "💀", "☠️", "👽", "🤖", "🎃", "👋", "🤚", "🖐️", "✋", "🖖", "👌", "🤌", "🤏", "✌️", "🤞", "🤟", "🤘", "🤙", "👈", "👉", "👆", "🖕", "👇", "☝️", "👍", "👎", "✊", "👊", "🤛", "🤜", "👏", "🙌", "👐", "🤲", "🤝", "🙏", "💪", "👀", "👁️", "👅", "👄", "💋", "👶", "🧒", "👦", "👧", "🧑", "👨", "👩", "🧓", "👴", "👵"] },
    { id:"animals", label:"Animals & Nature", icon:"fa-paw", emojis:["🐶", "🐱", "🐭", "🐹", "🐰", "🦊", "🐻", "🐼", "🐨", "🐯", "🦁", "🐮", "🐷", "🐸", "🐵", "🙈", "🙉", "🙊", "🐒", "🐔", "🐧", "🐦", "🐤", "🦆", "🦅", "🦉", "🦇", "🐺", "🐗", "🐴", "🦄", "🐝", "🐛", "🦋", "🐌", "🐞", "🐜", "🐢", "🐍", "🦎", "🦖", "🦕", "🐙", "🦑", "🦐", "🦞", "🦀", "🐡", "🐠", "🐟", "🐬", "🐳", "🐋", "🦈", "🐊", "🐅", "🐆", "🦓", "🦍", "🐘", "🦛", "🦏", "🐪", "🦒", "🦘", "🐄", "🐎", "🐖", "🐏", "🐑", "🦙", "🐐", "🦌", "🐕", "🐈", "🐓", "🦃", "🦚", "🦜", "🦢", "🦩", "🐇", "🦝", "🦨", "🦡", "🦦", "🦥", "🌵", "🎄", "🌲", "🌳", "🌴", "🌱", "🌿", "☘️", "🍀", "🍃", "🍂", "🍁", "🍄", "💐", "🌷", "🌹", "🥀", "🌺", "🌸", "🌼", "🌻", "🌞", "🌝", "🌙", "⭐", "🌟", "✨", "⚡", "💥", "🔥", "🌈", "☀️", "☁️", "⛅", "🌧️", "⛈️", "❄️", "☃️", "⛄", "💨", "💧", "💦", "☔", "🌊"] },
    { id:"food", label:"Food & Drink", icon:"fa-apple", emojis:["🍎", "🍐", "🍊", "🍋", "🍌", "🍉", "🍇", "🍓", "🫐", "🍈", "🍒", "🍑", "🥭", "🍍", "🥥", "🥝", "🍅", "🍆", "🥑", "🥦", "🥬", "🥒", "🌶️", "🫑", "🌽", "🥕", "🫒", "🧄", "🧅", "🥔", "🍠", "🥐", "🥯", "🍞", "🥖", "🥨", "🧀", "🥚", "🍳", "🧈", "🥞", "🧇", "🥓", "🥩", "🍗", "🍖", "🌭", "🍔", "🍟", "🍕", "🫓", "🥪", "🥙", "🧆", "🌮", "🌯", "🥗", "🍝", "🍜", "🍲", "🍛", "🍣", "🍱", "🥟", "🍤", "🍙", "🍚", "🍘", "🍥", "🍦", "🍧", "🍨", "🍩", "🍪", "🎂", "🍰", "🧁", "🥧", "🍫", "🍬", "🍭", "🍮", "🍯", "🥛", "☕", "🍵", "🧃", "🥤", "🍶", "🍺", "🍻", "🥂", "🍷", "🥃", "🍸", "🍹", "🍾", "🧊"] },
    { id:"activity", label:"Activity", icon:"fa-futbol-o", emojis:["⚽", "🏀", "🏈", "⚾", "🥎", "🎾", "🏐", "🏉", "🥏", "🎱", "🏓", "🏸", "🏒", "🏑", "🥍", "🏏", "🥅", "⛳", "🏹", "🎣", "🥊", "🥋", "🎽", "🛹", "⛸️", "🎿", "🏂", "🏋️", "🤸", "🤺", "🏌️", "🏇", "🧘", "🏄", "🏊", "🚣", "🚴", "🏆", "🥇", "🥈", "🥉", "🏅", "🎖️", "🎯", "🎮", "🕹️", "🎰", "🎲", "🧩", "♟️", "🎭", "🎨", "🎼", "🎤", "🎧", "🎷", "🎸", "🎹", "🎺", "🎻", "🥁", "🎬"] },
    { id:"travel", label:"Travel & Places", icon:"fa-car", emojis:["🚗", "🚕", "🚙", "🚌", "🚎", "🏎️", "🚓", "🚑", "🚒", "🚐", "🛻", "🚚", "🚛", "🚜", "🛴", "🚲", "🛵", "🏍️", "🛺", "🚨", "🚔", "🚍", "🚘", "🚖", "✈️", "🛫", "🛬", "🚀", "🛸", "🚁", "⛵", "🚤", "🚢", "⚓", "⛽", "🚧", "🚦", "🚥", "🚏", "🗺️", "🗽", "🗼", "🏰", "🏯", "🏟️", "🎡", "🎢", "🎠", "⛲", "⛺", "🏠", "🏡", "🏢", "🏬", "🏥", "🏦", "🏨", "🏪", "🏫", "⛪", "🕌", "🕍", "⛩️", "🕋", "🌁", "🌃", "🏙️", "🌄", "🌅", "🌆", "🌇", "🌉"] },
    { id:"objects", label:"Objects", icon:"fa-lightbulb-o", emojis:["⌚", "📱", "💻", "⌨️", "🖥️", "🖨️", "🖱️", "🕹️", "💽", "💾", "💿", "📀", "📷", "🎥", "📞", "☎️", "📺", "📻", "⏰", "⌛", "⏳", "📡", "🔋", "🔌", "💡", "🔦", "💸", "💵", "💴", "💶", "💷", "💰", "💳", "💎", "🧰", "🔧", "🔨", "🔩", "⚙️", "💣", "🔫", "🔮", "🔭", "🔬", "💊", "💉", "🧹", "🛒", "🎁", "🎀", "🎈", "🎉", "🎊", "✉️", "📧", "📦", "📁", "📂", "📝", "📒", "📕", "📗", "📘", "📙", "📚", "📌", "📍", "✂️", "🖊️", "🖋️", "🔑", "🔒", "🔓"] },
    { id:"symbols", label:"Symbols", icon:"fa-heart-o", emojis:["❤️", "🧡", "💛", "💚", "💙", "💜", "🖤", "🤍", "🤎", "💔", "❣️", "💕", "💞", "💓", "💗", "💖", "💘", "💝", "💟", "☮️", "✝️", "☪️", "🕉️", "☸️", "✡️", "☯️", "⚛️", "❌", "⭕", "🛑", "⛔", "📛", "🚫", "💯", "💢", "♨️", "❗", "❕", "❓", "❔", "‼️", "⁉️", "⚠️", "✅", "❎", "♻️", "🔵", "🟢", "🟡", "🟠", "🔴", "🟣", "🟤", "⚫", "⚪", "🟥", "🟧", "🟨", "🟩", "🟦", "🟪", "⬛", "⬜"] },
    { id:"flags", label:"Flags", icon:"fa-flag", emojis:["🏳️", "🏴", "🏁", "🚩", "🏳️‍🌈", "🇺🇳", "🇺🇸", "🇨🇦", "🇲🇽", "🇧🇷", "🇦🇷", "🇬🇧", "🇮🇪", "🇫🇷", "🇩🇪", "🇮🇹", "🇪🇸", "🇵🇹", "🇳🇱", "🇧🇪", "🇨🇭", "🇸🇪", "🇳🇴", "🇩🇰", "🇫🇮", "🇵🇱", "🇺🇦", "🇷🇺", "🇹🇷", "🇬🇷", "🇮🇳", "🇨🇳", "🇯🇵", "🇰🇷", "🇹🇭", "🇻🇳", "🇵🇭", "🇮🇩", "🇲🇾", "🇸🇬", "🇦🇺", "🇳🇿", "🇿🇦", "🇪🇬", "🇳🇬", "🇮🇱", "🇸🇦", "🇦🇪"] }
  ];
  var SEARCH_NAMES = {"👍": "thumbs up like", "👎": "thumbs down", "😂": "joy laugh crying", "❤️": "heart red love", "😍": "heart eyes love", "😊": "smile blush", "🙏": "pray thanks please", "🔥": "fire lit", "👏": "clap applause", "😅": "sweat smile", "😎": "cool sunglasses", "🥰": "smiling hearts", "😭": "sob cry", "😮": "wow open mouth", "🤔": "thinking", "🎉": "party tada", "💯": "hundred", "✨": "sparkles", "👀": "eyes look", "🙌": "raised hands", "💪": "muscle strong", "🫡": "salute", "😴": "sleep", "😇": "angel halo", "😜": "wink tongue", "😀": "grin", "🤣": "rofl", "😘": "kiss", "🥳": "party", "🥺": "pleading", "😡": "rage", "👻": "ghost", "🐶": "dog", "🐱": "cat", "🍎": "apple", "🍕": "pizza", "🍔": "burger", "⚽": "soccer", "🏀": "basketball", "🎮": "game", "🚗": "car", "✈️": "airplane", "🚀": "rocket", "🏠": "house", "📱": "phone", "💻": "laptop", "💡": "bulb", "🇺🇸": "usa", "🇨🇦": "canada", "🇬🇧": "uk", "🇫🇷": "france", "🇩🇪": "germany", "🇯🇵": "japan", "🇰🇷": "korea", "🇨🇳": "china", "🇮🇳": "india", "🇦🇺": "australia"};

  function loadRecents(){
    try {
      var raw = JSON.parse(localStorage.getItem(RECENT_KEY) || '[]');
      if (Array.isArray(raw) && raw.length) {
        return raw.filter(function(e){ return typeof e === 'string' && e; }).slice(0, 48);
      }
    } catch (_e) {}
    return DEFAULT_RECENTS.slice();
  }

  function saveRecent(emoji){
    if (!emoji) return;
    var list = loadRecents().filter(function(e){ return e !== emoji; });
    list.unshift(emoji);
    try { localStorage.setItem(RECENT_KEY, JSON.stringify(list.slice(0, 48))); } catch (_e) {}
  }

  function catById(id){
    for (var i = 0; i < CATEGORIES.length; i++) {
      if (CATEGORIES[i].id === id) return CATEGORIES[i];
    }
    return CATEGORIES[0];
  }

  function currentEmojis(){
    var q = ((emojiSearch && emojiSearch.value) || '').trim().toLowerCase();
    if (q) {
      var list = [];
      CATEGORIES.forEach(function(cat){
        var src = cat.id === 'recents' ? loadRecents() : (cat.emojis || []);
        src.forEach(function(e){
          if (!e || list.indexOf(e) !== -1) return;
          var name = (SEARCH_NAMES[e] || '').toLowerCase();
          if (name.indexOf(q) !== -1 || e.indexOf(q) !== -1) list.push(e);
        });
      });
      return list;
    }
    var cat = catById(activeCat);
    if (cat.id === 'recents') return loadRecents();
    return cat.emojis || [];
  }

  function renderEmojiGrid(){
    if (!emojiGrid) return;
    var list = currentEmojis().filter(Boolean);
    var searching = !!(emojiSearch && emojiSearch.value.trim());
    if (emojiLabel) {
      emojiLabel.textContent = searching ? 'Search Results' : (catById(activeCat).label || 'Emojis');
    }
    if (!list.length) {
      emojiGrid.innerHTML = '';
      if (emojiEmpty) emojiEmpty.hidden = false;
      return;
    }
    if (emojiEmpty) emojiEmpty.hidden = true;
    emojiGrid.innerHTML = list.map(function(e){
      return '<button type="button" data-emoji="'+e+'" title="'+(SEARCH_NAMES[e] || 'Emoji')+'" aria-label="Insert emoji">'+e+'</button>';
    }).join('');
  }

  function renderCats(){
    if (!emojiCats) return;
    emojiCats.innerHTML = CATEGORIES.map(function(cat){
      return '<button type="button" class="msb-composer-emoji-cat'+(cat.id === activeCat ? ' is-active' : '')+'" data-cat="'+cat.id+'" title="'+cat.label+'" aria-label="'+cat.label+'"><i class="fa '+cat.icon+'" aria-hidden="true"></i></button>';
    }).join('');
  }

  function setActiveCat(id){
    activeCat = id || 'recents';
    if (emojiSearch) emojiSearch.value = '';
    renderCats();
    renderEmojiGrid();
    placeEmojiPicker();
  }

  function placeEmojiPicker(){
    if (!emojiPicker || emojiPicker.hidden || !emojiBtn) return;
    var btnRect = emojiBtn.getBoundingClientRect();
    var vw = window.innerWidth || document.documentElement.clientWidth || 320;
    var vh = window.innerHeight || document.documentElement.clientHeight || 480;
    var pad = 8;
    emojiPicker.style.visibility = 'hidden';
    emojiPicker.style.display = 'flex';
    var pw = Math.min(300, vw - pad * 2);
    emojiPicker.style.width = pw + 'px';
    emojiPicker.style.left = '0px';
    emojiPicker.style.top = '0px';
    var chrome = 78;
    var catsH = 40;
    var desiredBody = 180;
    var spaceAbove = Math.max(0, btnRect.top - pad);
    var spaceBelow = Math.max(0, vh - btnRect.bottom - pad);
    var placeAbove = spaceAbove >= 220 || spaceAbove >= spaceBelow;
    var avail = placeAbove ? spaceAbove : spaceBelow;
    var maxBox = Math.max(160, Math.min(340, avail - 4));
    var bodyMax = Math.max(72, maxBox - chrome - catsH);
    var bodyEl = emojiPicker.querySelector('.msb-composer-emoji-body');
    if (bodyEl) bodyEl.style.maxHeight = bodyMax + 'px';
    emojiPicker.style.maxHeight = maxBox + 'px';
    var ph = emojiPicker.offsetHeight || maxBox;
    var left = Math.round(btnRect.left + (btnRect.width / 2) - (pw / 2));
    left = Math.max(pad, Math.min(vw - pw - pad, left));
    var top;
    if (placeAbove) {
      top = Math.round(btnRect.top - ph - 8);
      if (top < pad) top = pad;
    } else {
      top = Math.round(btnRect.bottom + 8);
      if (top + ph > vh - pad) top = Math.max(pad, vh - ph - pad);
    }
    emojiPicker.style.left = left + 'px';
    emojiPicker.style.top = top + 'px';
    emojiPicker.style.visibility = 'visible';
  }

  function closeEmojiPicker(){
    if (!emojiPicker) return;
    emojiPicker.hidden = true;
    emojiPicker.style.visibility = '';
    if (emojiHost && emojiPicker.parentNode !== emojiHost) {
      try { emojiHost.appendChild(emojiPicker); } catch (_e) {}
    }
    if (emojiBtn) {
      emojiBtn.classList.remove('is-active');
      emojiBtn.setAttribute('aria-expanded', 'false');
    }
  }

  function openEmojiPicker(){
    if (!emojiPicker) return;
    try { document.body.appendChild(emojiPicker); } catch (_e) {}
    emojiPicker.hidden = false;
    if (emojiBtn) {
      emojiBtn.classList.add('is-active');
      emojiBtn.setAttribute('aria-expanded', 'true');
    }
    renderCats();
    renderEmojiGrid();
    requestAnimationFrame(function(){
      placeEmojiPicker();
      requestAnimationFrame(placeEmojiPicker);
    });
    if (emojiSearch) setTimeout(function(){ try { emojiSearch.focus(); } catch (_e2) {} }, 40);
  }

  function toggleEmojiPicker(){
    if (!emojiPicker) return;
    if (emojiPicker.hidden) openEmojiPicker();
    else closeEmojiPicker();
  }

  function emojiTargetField(){
    var active = document.activeElement;
    if (active === bodyField || active === titleField) return active;
    if (lastTextField === titleField || lastTextField === bodyField) return lastTextField;
    var titlePanel = form.querySelector('.msb-composer-panel[data-panel="title"]');
    if (titlePanel && !titlePanel.hasAttribute('hidden') && titleField) return titleField;
    return bodyField || titleField;
  }

  function insertEmojiAtCursor(el, emoji){
    if (!el || !emoji) return;
    var start = typeof el.selectionStart === 'number' ? el.selectionStart : (el.value || '').length;
    var end = typeof el.selectionEnd === 'number' ? el.selectionEnd : start;
    var val = String(el.value || '');
    el.value = val.slice(0, start) + emoji + val.slice(end);
    var pos = start + emoji.length;
    try { el.setSelectionRange(pos, pos); } catch (_e) {}
    el.focus();
    try { el.dispatchEvent(new Event('input', { bubbles: true })); } catch (_e2) {}
  }

  if (bodyField) bodyField.addEventListener('focus', function(){ lastTextField = bodyField; });
  if (titleField) titleField.addEventListener('focus', function(){ lastTextField = titleField; });

  renderCats();
  renderEmojiGrid();

  if (emojiBtn) {
    emojiBtn.addEventListener('click', function(ev){
      ev.preventDefault();
      ev.stopPropagation();
      toggleEmojiPicker();
    });
  }
  if (emojiClose) {
    emojiClose.addEventListener('click', function(ev){
      ev.preventDefault();
      ev.stopPropagation();
      closeEmojiPicker();
    });
  }
  if (emojiExpand) {
    emojiExpand.addEventListener('click', function(ev){
      ev.preventDefault();
      ev.stopPropagation();
      var body = emojiPicker && emojiPicker.querySelector('.msb-composer-emoji-body');
      if (!body) return;
      var expanded = body.getAttribute('data-expanded') === '1';
      body.setAttribute('data-expanded', expanded ? '0' : '1');
      body.style.maxHeight = expanded ? '' : '260px';
      placeEmojiPicker();
    });
  }
  if (emojiCats) {
    emojiCats.addEventListener('click', function(ev){
      var btn = ev.target.closest('[data-cat]');
      if (!btn || !emojiCats.contains(btn)) return;
      ev.preventDefault();
      ev.stopPropagation();
      setActiveCat(btn.getAttribute('data-cat'));
    });
  }
  if (emojiSearch) {
    emojiSearch.addEventListener('input', function(){ renderEmojiGrid(); placeEmojiPicker(); });
    emojiSearch.addEventListener('keydown', function(ev){
      if (ev.key === 'Escape') {
        ev.preventDefault();
        closeEmojiPicker();
      }
    });
  }
  if (emojiGrid) {
    emojiGrid.addEventListener('click', function(ev){
      var btn = ev.target.closest('button[data-emoji]');
      if (!btn || !emojiGrid.contains(btn)) return;
      ev.preventDefault();
      ev.stopPropagation();
      var emoji = btn.getAttribute('data-emoji') || btn.textContent || '';
      saveRecent(emoji);
      insertEmojiAtCursor(emojiTargetField(), emoji);
      if (activeCat === 'recents' && !(emojiSearch && emojiSearch.value.trim())) renderEmojiGrid();
    });
  }

  document.addEventListener('click', function(ev){
    if (!emojiPicker || emojiPicker.hidden) return;
    if (emojiPicker.contains(ev.target)) return;
    if (emojiBtn && emojiBtn.contains(ev.target)) return;
    closeEmojiPicker();
  });
  window.addEventListener('resize', function(){
    if (emojiPicker && !emojiPicker.hidden) placeEmojiPicker();
  });


  syncTools();
  setTimeout(fitParent, 80);

  /* Sound search → fill music fields + sound_id */
  (function(){
    var search = document.getElementById('msbSoundSearch');
    var results = document.getElementById('msbSoundSearchResults');
    var soundIdEl = document.getElementById('createPostSoundId');
    var titleEl = document.getElementById('createPostMusicTitle');
    var artistEl = document.getElementById('createPostMusicArtist');
    if (!search || !results) return;
    var t = null;
    function render(items){
      if (!items || !items.length) {
        results.innerHTML = '<div class="text-muted px-1 py-1">No sounds found</div>';
        return;
      }
      results.innerHTML = items.map(function(it){
        return '<button type="button" class="btn btn-link btn-sm text-left d-block w-100 msb-sound-pick" data-id="'+String(it.id||0)+'" data-title="'+String(it.title||'').replace(/"/g,'&quot;')+'" data-artist="'+String(it.artist||'').replace(/"/g,'&quot;')+'">'+(it.title||'Sound')+(it.artist ? (' · '+it.artist) : '')+' <span class="text-muted">('+String(it.use_count||0)+')</span></button>';
      }).join('');
    }
    function run(q){
      fetch('feed_api.php?ajax=sounds_search&q='+encodeURIComponent(q||'')+'&limit=20', { credentials:'same-origin' })
        .then(function(r){ return r.json(); })
        .then(function(j){ render((j && j.items) || []); })
        .catch(function(){ results.innerHTML = ''; });
    }
    search.addEventListener('input', function(){
      clearTimeout(t);
      t = setTimeout(function(){ run(search.value || ''); }, 220);
    });
    search.addEventListener('focus', function(){ if (!results.childElementCount) run(''); });
    results.addEventListener('click', function(ev){
      var btn = ev.target.closest('.msb-sound-pick');
      if (!btn) return;
      ev.preventDefault();
      if (soundIdEl) soundIdEl.value = btn.getAttribute('data-id') || '0';
      if (titleEl) titleEl.value = btn.getAttribute('data-title') || '';
      if (artistEl) artistEl.value = btn.getAttribute('data-artist') || '';
      results.innerHTML = '';
      search.value = '';
    });
  })();
})();
</script>
<script>
(function(){
  function scrubWaste(){
    try {
      document.querySelectorAll('.sh-footer, .app-footer').forEach(function(el){
        el.setAttribute('hidden', 'hidden');
        el.style.setProperty('display', 'none', 'important');
        el.style.setProperty('height', '0', 'important');
        if (el.parentNode) el.parentNode.removeChild(el);
      });
      var main = document.querySelector('.sh-mainpanel');
      var page = document.querySelector('.sh-pagebody');
      if (main) {
        main.style.setProperty('height', 'auto', 'important');
        main.style.setProperty('min-height', '0', 'important');
      }
      if (page) {
        page.style.setProperty('height', 'auto', 'important');
        page.style.setProperty('min-height', '0', 'important');
        page.style.setProperty('padding-bottom', '0', 'important');
      }
      document.documentElement.style.setProperty('height', 'auto', 'important');
      document.body.style.setProperty('height', 'auto', 'important');
      document.body.style.setProperty('min-height', '0', 'important');
      if (window.parent && window.parent !== window) {
        window.parent.postMessage({ type: 'msb-create-post-fit' }, '*');
      }
    } catch (_e) {}
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', scrubWaste);
  else scrubWaste();
  window.addEventListener('load', scrubWaste);
  setTimeout(scrubWaste, 120);
  setTimeout(scrubWaste, 400);
})();
</script>
<?php endif; ?>

</body>
</html>
