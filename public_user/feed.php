<?php

if (!function_exists('sentence_count')){
function sentence_count(string $text): int {
    $text=trim($text);
    if($text==='')return 0;
    $s=preg_split('/[.!?]+/',$text);
    $s=array_filter($s,fn($v)=>trim($v)!=='');
    return count($s);
}}

require_once __DIR__ . '/includes/session_user.php';
require_once __DIR__ . '/controller.php';
require_once __DIR__ . '/includes/post_media_stage.css.php';
require_once __DIR__ . '/includes/live_post_card.css.php';
require_once __DIR__ . '/includes/publisher_accounts_load.php';
require_once __DIR__ . '/includes/publisher_organization_bridge.php';
require_once __DIR__ . '/includes/staff_publisher_access.php';
require_once __DIR__ . '/includes/theme_prefs.php';
require_once __DIR__ . '/includes/post_card_actions_menu.php';
require_once __DIR__ . '/includes/post_action_thin_icons.php';

error_reporting(E_ALL);
ini_set('display_errors', '0');

requireUserLogin();

$controller = new Controller();
$dbh = $controller->pdo();

$loggedEmail = $_SESSION['user_login'];
$meId = theme_prefs_viewer_user_id();
publisher_ensure_schema($dbh);
$isPublisherAccount = publisher_account_is($dbh, $meId);
$canFollowPublishers = publisher_can_follow_as_viewer($dbh, $meId);
$staffReadonly = staff_pub_is_readonly();
$canLiveStudio = live_studio_user_can_access($dbh, $meId);
$feedLeftRailPublicPublishers = staff_pub_menu_for_viewer($dbh, $meId);

$feedAlertPostId = (int)($_GET['open_post'] ?? $_GET['post'] ?? 0);
$feedAlertCommentId = (int)($_GET['open_comment'] ?? 0);
$feedStoryPostId = (int)($_GET['story_post'] ?? 0);
$feedUploadWarn = (string)($_GET['upload_warn'] ?? '') === '1';
$feedSearchQ = trim((string)($_GET['q'] ?? ''));
$feedAppearanceMode = theme_prefs_appearance_mode($dbh, $meId);

// ✅ Fetch my display name (for "Find me" button)
$meDisplayName = '';
$meUsername    = '';
try {
  if ($meId > 0) {
    $st = $dbh->prepare("SELECT fullname, username FROM users WHERE id = :id LIMIT 1");
    $st->execute([':id' => $meId]);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    $meDisplayName = trim((string)($row['fullname'] ?? ''));
    $meUsername    = trim((string)($row['username'] ?? ''));
  }
} catch (Throwable $e) {
  $meDisplayName = '';
  $meUsername = '';
}
if ($meDisplayName === '') $meDisplayName = $meUsername;
if ($meDisplayName === '') $meDisplayName = (string)$loggedEmail; // fallback
?>
<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Feed</title>

    <?php theme_prefs_print_head_bootstrap($dbh, $meId); ?>
    <link rel="stylesheet" href="./css/dark-auto.css?v=10">
    <script src="./js/dark-auto.js?v=6" defer></script>
    <style>
      html, body { background: var(--msb-palette-bg, var(--feed-page-bg, #f5f7fb)); }
      html.dark-auto:not([data-msb-appearance]),
      html.dark-auto:not([data-msb-appearance]) body,
      html[data-theme="dark"]:not([data-msb-appearance]),
      html[data-theme="dark"]:not([data-msb-appearance]) body {
        background:#171d24 !important;
      }
      html[data-theme="light"]:not([data-msb-appearance]),
      html[data-theme="light"]:not([data-msb-appearance]) body {
        background:var(--msb-palette-bg, #f5f7fb) !important;
        color:var(--msb-palette-text, #0f172a) !important;
      }
      html[data-msb-appearance] body,
      html[data-msb-appearance] body.feed-insta-ui {
        background: var(--msb-palette-bg) !important;
        background-image: none !important;
      }
    </style>

    <link href="./lib/font-awesome/css/font-awesome.css" rel="stylesheet">
    <link href="./lib/Ionicons/css/ionicons.css" rel="stylesheet">
    <link href="./lib/perfect-scrollbar/css/perfect-scrollbar.css" rel="stylesheet">
    <link rel="stylesheet" href="./css/shamcey.css">

    <!-- css -->
    <link rel="stylesheet" href="assets/ui_best.css">
    <link rel="stylesheet" href="assets/layout-fixed.css">
    
    <!-- script -->
    <script defer src="assets/layout-fixed.js"></script>
    <script src="./js/shamcey.js"></script>
    <script src="./js/dashboard.js"></script>
    <script src="./js/device_profile.js"></script>
    <script src="./lib/jquery/jquery.js"></script>
    <script src="./lib/popper.js/popper.js"></script>
    <script src="./lib/bootstrap/bootstrap.js"></script>
    <script src="./lib/jquery-ui/jquery-ui.js"></script>
    <script src="./lib/perfect-scrollbar/js/perfect-scrollbar.jquery.js"></script>
    <script src="./lib/moment/moment.js"></script>
    <script src="assets/ui_best.js" defer></script>
  <style>
  /* ✅ NEW circular action icons (ONLY for underbar actions; does NOT touch book layout) */
  .ig-underbar .ig-actions-icons{
    display:flex;
    align-items:center;
    gap:26px;
    padding:10px 2px 14px;
    flex-wrap:wrap;
  }
  .ig-underbar .ig-act{
    display:flex;
    align-items:center;
    gap:1px;
    padding:0;
    border:0;
    background:transparent;
    cursor:pointer;
    text-decoration:none;
    line-height:1;
  }
  .ig-underbar .ig-act:focus{ outline:none; }
  .ig-underbar .ig-circle{
    width:30px;
    height:30px;
    border-radius:999px;
    display:flex;
    align-items:center;
    justify-content:center;

    /* ✅ exact colors like your screenshot */
    /* background:#1f252b; */
    /* border:2px solid #343a40; */
    /* box-shadow:inset 0 0 0 1px rgba(255,255,255,.04),0 2px 6px rgba(0,0,0,.35); */

    transition: transform .16s ease, background .16s ease, border-color .16s ease;
  }
  .ig-underbar .ig-circle{border-color:#3f464d; transform: scale(1.06); }
  .ig-underbar .ig-circle i{ font-size:17px; }
  .ig-underbar .ig-count{
    font-size:20px;
    font-weight:600;
    color:#cfd6dd;
    min-width:14px;
    opacity:.9;
  }

  /* Light backgrounds */
  .sh-mainpanel .card .ig-underbar .ig-circle{
    /* background:#1f252b; */
    /* border-color:#343a40; */
    /* box-shadow:inset 0 0 0 1px rgba(255,255,255,.04),0 2px 6px rgba(0,0,0,.35); */
  }
  .sh-mainpanel .card .ig-underbar .ig-count{
    color:#cfd6dd;
    opacity:.9;
  }

  /* Dark-auto compatibility */
  body.dark-mode .ig-underbar .ig-circle,
  body.dark-auto .ig-underbar .ig-circle,
  body.dark .ig-underbar .ig-circle{
    background:#1f252b;
    border-color:#343a40;
    box-shadow:
      inset 0 0 0 1px rgba(255,255,255,.04),
      0 2px 6px rgba(0,0,0,.35);
  }
  body.dark-mode .ig-underbar .ig-count,
  body.dark-auto .ig-underbar .ig-count,
  body.dark .ig-underbar .ig-count{
    color:#cfd6dd;
    opacity:.9;
  }

  /* ===============================
   FLAT ACTION BAR (EXACT DESIGN)
   =============================== */

.mini-actions{
  display:flex;
  align-items:center;
  gap:34px;
  padding:12px 6px;
}

.mini-act{
  display:flex;
  align-items:center;
  gap:12px;
  border:0;
  background:transparent;
  cursor:pointer;
}

.mini-act i{
  font-size:28px;
  color:#111111; /* black icons */
}

.mini-count{
  font-size:30px;
  font-weight:700;
  color:#c9ced6; /* grey numbers */
}

/* ONLY COMMENT HAS BACKGROUND */
  .mini-comment{
  padding:12px 18px;
  border-radius:999px;
  background:#eef0f2;
}

  .yt-pagebar{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:18px;
    min-height:72px;
    padding:16px 18px 14px;
    /* background:#272b31; */
    /* border-bottom:1px solid rgba(255,255,255,.08); */
    position:fixed;
    top:0;
    left:84px;
    right:0;
    z-index:120;
  }

  .yt-topbar-left,
  .yt-topbar-right{
    display:flex;
    align-items:center;
    gap:14px;
    flex:0 0 auto;
  }
  .yt-topbar-center{
    flex:1 1 auto;
    display:flex;
    align-items:center;
    justify-content:center;
    min-width:0;
  }
  .yt-icon-btn{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    width:44px;
    height:44px;
    border-radius:50%;
    color:#fff;
    font-size:24px;
    background:transparent;
    border:0;
    cursor:pointer;
  }
  .yt-brand{
    display:inline-flex;
    align-items:center;
    gap:10px;
    color:#fff;
    font-size:24px;
    font-weight:900;
    letter-spacing:-.03em;
  }
  .search-card{
    width:min(100%, 840px);
    border:0;
    border-radius:0;
    background:transparent;
    padding:0;
    margin:0;
  }
  .search-row{
    display:flex;
    align-items:center;
    gap:10px;
    width:100%;
  }
  .yt-search-shell{
    display:flex;
    align-items:center;
    width:min(100%, 840px);
    min-width:0;
  }
  .search-input{
    flex:1;
    height:52px;
    border:1px solid #3a3a3a;
    border-right:0;
    border-radius:999px 0 0 999px;
    padding:0 22px;
    font-size:15px;
    outline:none;
    background:#121212;
    color:#fff;
  }
  .search-btn{
    flex:0 0 auto;
    width:88px;
    height:52px;
    border:1px solid #3a3a3a;
    border-radius:0 999px 999px 0;
    padding:0;
    font-weight:800;
    color:#fff;
    background:#222;
    cursor:pointer;
    white-space:nowrap;
    font-size:24px;
  }
  .yt-mic-btn{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    width:48px;
    height:48px;
    border-radius:50%;
    border:0;
    background:#181818;
    color:#fff;
    font-size:24px;
    cursor:pointer;
  }
  .yt-signin{
    display:inline-flex;
    align-items:center;
    gap:8px;
    min-height:48px;
    padding:0 18px;
    border-radius:999px;
    border:1px solid rgba(255,255,255,.12);
    color:#fff;
    font-size:18px;
    font-weight:800;
  }

  /* ✅ Make NAV look like your screenshot (centered items, no weird margins) */
  /* #ttNavLeftbar .sh-sidebar-label{
    display:block !important;
    padding:12px 18px !important;
    margin:0 !important;
    letter-spacing:.08em;
  }
  #ttNavLeftbar{
    width: 340px;
    display: flex;
    flex-wrap: wrap;
    padding-left: 0;
    margin-bottom: 0;
    list-style: none;
  } */

  /* #ttNavLeftbar .nav-item{ display:block; }
  #ttNavLeftbar .nav-link{
    display:flex;
    align-items:center;
    gap:10px;
    
    border-radius:6px;
  } */
  /* #ttNavLeftbar .nav-link i{ font-size:20px; } */
  /* ✅ Make room for NAV on desktop */
  /* @media (min-width: 992px){
    .sh-pagebody{ margin-left:340px; }
  } */

/* =========================================================
   MOBILE/TABLET ONLY: HIDE TOP-RIGHT BACK ARROW + COMMENT ICON
   (targets your real IDs in feed.php: #btnBackPost and #btnOpenCommentsDrawer)
========================================================= */
@media (max-width:1024px){
  .yt-pagebar{
    left:0;
    flex-wrap:wrap;
    padding:12px;
  }
  .yt-topbar-center{
    order:3;
    width:100%;
  }
  .search-card,
  .yt-search-shell{
    width:100%;
  }
  #btnOpenCommentsDrawer{
    display:none!important;
    visibility:hidden!important;
    opacity:0!important;
    pointer-events:none!important;
  }
}


        /* ✅ Viewers overlay */
        .vw-overlay{ position:fixed; inset:0; background:rgba(15,23,42,.58); z-index:99997; display:flex; align-items:center; justify-content:center; padding:14px; backdrop-filter: blur(8px); }
        .vw-modal{ width:min(720px, 96vw); max-height: 86vh; background:#fff; border-radius:18px; box-shadow:0 20px 70px rgba(0,0,0,.35); overflow:hidden; display:flex; flex-direction:column; }
        .vw-topbar{ display:flex; align-items:center; justify-content:space-between; padding:12px 14px; border-bottom:1px solid rgba(0,0,0,.08); }
        .vw-title{ font-weight:800; display:flex; align-items:center; gap:8px; }
        .vw-close{ width:36px; height:36px; border:none; border-radius:999px; background:rgba(0,0,0,.06); font-size:22px; line-height:1; cursor:pointer; }
        .vw-close:hover{ background:rgba(0,0,0,.10); }
        .vw-stats{ display:flex; gap:10px; padding:10px 14px; border-bottom:1px solid rgba(0,0,0,.06); }
        .vw-stat{ flex:1; background:#f8fafc; border:1px solid rgba(0,0,0,.06); border-radius:14px; padding:10px; }
        .vw-stat-k{ font-size:12px; color:#64748b; }
        .vw-stat-v{ font-size:18px; font-weight:900; color:#0f172a; }
        .vw-body{ padding:10px 14px 14px; overflow:auto; }
        .vw-subtitle{ font-weight:800; margin-bottom:8px; }
        .vw-item{ display:flex; align-items:center; justify-content:space-between; padding:10px 10px; border-radius:12px; border:1px solid rgba(0,0,0,.06); margin-bottom:8px; }
        .vw-left{ display:flex; align-items:center; gap:10px; min-width:0; }
        .vw-av{ width:38px; height:38px; border-radius:999px; display:flex; align-items:center; justify-content:center; color:#fff; font-weight:900; box-shadow:0 6px 14px rgba(0,0,0,.20); flex:0 0 auto; }
        .vw-name{ font-weight:800; font-size:13px; line-height:1.2; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width: 360px; }
        .vw-user{ font-size:12px; color:#64748b; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width: 360px; }
        .vw-when{ font-size:12px; color:#64748b; flex:0 0 auto; margin-left:10px; }
        .vw-empty{ color:#64748b; padding:12px; text-align:center; }

      
    .mf-friend-btn{
      border:1px solid rgba(29,155,240,.18);
      background:#eff8ff;
      color:#175cd3;
      border-radius:999px;
      padding:8px 12px;
      font-weight:800;
      font-size:12px;
      line-height:1;
      white-space:nowrap;
      margin-left:auto;
      margin-right:8px;
    }
    .mf-media-shell{
      position:relative;
      width:100%;
    }
    .mf-media-shell > .mf-media-top-actions{
      position:absolute;
      top:12px;
      right:12px;
      z-index:25;
      display:flex;
      align-items:center;
      gap:8px;
      pointer-events:none;
    }
    .mf-media-shell > .mf-media-top-actions .mf-friend-btn{
      pointer-events:auto;
      margin:0;
      box-shadow:0 4px 14px rgba(15,23,42,.24);
    }
    .mf-card.mf-card-reel .mf-media-shell > .mf-media-top-actions{
      top:14px;
      right:14px;
    }
    .mf-feed .mf-head .mf-friend-btn.mf-media-follow-btn{
      display:none !important;
    }
    .mf-feed .mf-card:has(.mf-media-shell) .mf-head .mf-friend-btn{
      display:none !important;
    }
    .mf-feed .mf-media-shell > .mf-media-top-actions{
      display:flex !important;
    }
    .mf-feed .mf-media-shell > .mf-media-top-actions .mf-friend-btn{
      display:inline-flex !important;
    }
    .mf-feed .mf-media-shell > .mf-media-top-actions .mf-friend-btn.is-friends,
    .mf-feed .mf-head .mf-friend-btn.is-friends,
    .mf-feed .mf-media-shell > .mf-media-top-actions .mf-friend-btn.mf-publisher-follow.is-friends,
    .mf-feed .mf-head .mf-friend-btn.mf-publisher-follow.is-friends{
      display:none !important;
    }
    .mf-feed .mf-media-shell > .mf-media-top-actions.is-friend-only-hidden{
      display:none !important;
    }
    .mf-friend-btn.mf-media-follow-btn{
      margin:0;
    }
    .mf-media,
    .mf-media.media-stage{
      position:relative;
    }
    .mf-friend-btn.is-friends{background:#eefaf1;color:#067647;border-color:rgba(6,118,71,.14);}
    .mf-friend-btn.is-pending:not(.mf-media-action-circle){background:#fff7ed;color:#b54708;border-color:rgba(181,71,8,.14);}
    .mf-friend-btn.is-accept:not(.mf-media-action-circle){background:#eff6ff;color:#175cd3;border-color:rgba(23,92,211,.16);}
</style>


<style>
/* =========================================
   BOOK LAYOUT — ACTION COLOR STATES (EXACT)
   Matches your screenshot:
   - Heart: #7c3aed (purple)
   - Like : #1877F2 (blue)
   - Save : #f5c518 (yellow)
   - Share: #555555 (dark grey)
========================================= */

/* Default icon color (black) */
.ig-act i,
.vrail-btn i{
  /* color:#000; */
  transition:color .18s ease;
}

/* ❤️ HEART */
.ig-act.is-love i,
.vrail-btn.is-love i{
  color:var(--msb-love-color, #7c3aed) !important;
}

/* 👍 LIKE */
.ig-act.is-like i,
.vrail-btn.is-like i{
  color:#1877F2 !important;
}

/* 🔖 SAVE */
.ig-act.is-save i,
.vrail-btn.is-save i{
  color:#f5c518 !important;
}

/* 🔗 SHARE */
.ig-act.is-share i,
.vrail-btn.is-share i{
  /* color:#555555 !important; */
}

/* ---------- Premium micro-interactions ---------- */
@media (prefers-reduced-motion: reduce){
  .ig-act i, .vrail-btn i { transition:none; }
  .ig-act.anim-pop i, .vrail-btn.anim-pop i,
  .ig-act.anim-ripple::after, .vrail-btn.anim-ripple::after { animation:none !important; }
}

/* hover feedback */
.ig-act:hover i, .vrail-btn:hover i{
  filter: brightness(0.95);
}

/* Heart pop */
@keyframes igPop {
  0%   { transform: scale(1); }
  35%  { transform: scale(1.25); }
  70%  { transform: scale(0.95); }
  100% { transform: scale(1); }
}
.ig-act.anim-pop i, .vrail-btn.anim-pop i{
  animation: igPop 260ms ease;
  transform-origin: 50% 60%;
}

/* Like ripple (subtle ring) */
.ig-act, .vrail-btn{ position: relative; }
@keyframes igRipple {
  0%   { opacity: .35; transform: translate(-50%, -50%) scale(.7); }
  100% { opacity: 0;   transform: translate(-50%, -50%) scale(1.6); }
}
.ig-act.anim-ripple::after, .vrail-btn.anim-ripple::after{
  content:"";
  position:absolute;
  left:50%; top:50%;
  width:36px; height:36px;
  border-radius:999px;
  border:2px solid currentColor;
  animation: igRipple 360ms ease-out;
  pointer-events:none;
}

/* Save slide */
@keyframes igSlide {
  0%   { transform: translateY(0); }
  40%  { transform: translateY(-3px); }
  100% { transform: translateY(0); }
}
.ig-act.anim-slide i, .vrail-btn.anim-slide i{
  animation: igSlide 220ms ease;
}

/* Share nudge */
@keyframes igNudge {
  0%   { transform: translateX(0); }
  35%  { transform: translateX(3px); }
  70%  { transform: translateX(-2px); }
  100% { transform: translateX(0); }
}
.ig-act.anim-nudge i, .vrail-btn.anim-nudge i{
  animation: igNudge 240ms ease;
}

/* =========================================================
   MOBILE/TABLET ONLY: HIDE TOP-RIGHT BACK ARROW + COMMENT ICON
   (targets your real IDs in feed.php: #btnBackPost and #btnOpenCommentsDrawer)
========================================================= */
@media (max-width:1024px){
  #btnOpenCommentsDrawer{
    display:none!important;
    visibility:hidden!important;
    opacity:0!important;
    pointer-events:none!important;
  }
}

</style>


<style>
/* ===============================
   FEED RESPONSIVE FIX (v2)
   ✅ CSS ONLY — PHP/JS untouched
   Targets Shamcey layout: .sh-mainpanel, .sh-pagebody, .sh-mainpanel-left, etc.
================================ */

/* Safety */
html, body { max-width: 100%; }

/* -------- Tablet + Mobile -------- */
@media (max-width: 991.98px){

  /* allow scroll */
  html, body{
    overflow-x: hidden !important;
    overflow-y: auto !important;
    height: auto !important;
  }

  /* remove left push from layout */
  body{
    margin-left: 0 !important;
    padding-left: 0 !important;
    padding-right: 0 !important;
  }

  /* Shamcey main panel/page body should take full width */
  .sh-mainpanel,
  .sh-pagebody,
  .sh-mainpanel-left,
  .sh-mainpanel-right,
  .content,
  .main-content{
    margin-left: 0 !important;
    left: 0 !important;
    width: 100% !important;
    max-width: 100% !important;
  }

  /* any inline/pixel widths on containers */
  [style*="width:"],
  [style*="margin-left:"],
  [style*="left:"]{
    /* keep, but prevent breaking layout */
  }

  /* Feed layout: stack columns */
  .row{ flex-wrap: wrap !important; }
  [class*="col-"]{
    width: 100% !important;
    max-width: 100% !important;
    flex: 0 0 100% !important;
  }

  /* Right sidebar should move below feed on smaller screens */
  .right-sidebar,
  .feed-right,
  .sidebar-right,
  #rightSidebar,
  .sh-sidebar-right{
    position: relative !important;
    top: auto !important;
    height: auto !important;
    max-height: none !important;
    overflow: visible !important;
    margin-top: 12px !important;
  }

  /* Left nav/leftbar overlays: ensure they don't steal width */
  .navLeftbar,
  #navLeftbar,
  .leftbar,
  #leftbar,
  .readmoreLeftbar,
  #readmoreLeftbar{
    max-width: 92vw !important;
  }

  /* Cards/media fit */
  img, video, iframe{
    max-width: 100% !important;
    height: auto !important;
  }
  .card, .post-card, .feed-card{
    width: 100% !important;
    max-width: 100% !important;
  }
}

/* -------- Mobile -------- */
@media (max-width: 575.98px){

  /* tighter padding */
  .sh-pagebody,
  .container,
  .container-fluid{
    padding-left: 10px !important;
    padding-right: 10px !important;
  }

  /* buttons/toolbars wrap */
  .toolbar, .header-tools, .actions, .feed-actions{
    flex-wrap: wrap !important;
    gap: 8px !important;
  }

  /* make small icon buttons easier */
  .btn, button{
    max-width: 100%;
  }
}

/* -------- Very small phones -------- */
@media (max-width: 360px){
  .sh-pagebody,
  .container,
  .container-fluid{
    padding-left: 8px !important;
    padding-right: 8px !important;
  }
}

/* =========================================================
   MOBILE/TABLET ONLY: HIDE TOP-RIGHT BACK ARROW + COMMENT ICON
   (targets your real IDs in feed.php: #btnBackPost and #btnOpenCommentsDrawer)
========================================================= */
@media (max-width:1024px){
  #btnOpenCommentsDrawer{
    display:none!important;
    visibility:hidden!important;
    opacity:0!important;
    pointer-events:none!important;
  }
}

</style>


<style>
/* =====================================================
   FEED MOBILE/TABLET "MESSENGER" MODE (Rows list ↔ Post)
   ✅ Fixed back arrow always visible in post mode
===================================================== */
@media (max-width: 991.98px){
  html, body{ overflow-x:hidden !important; overflow-y:auto !important; height:auto !important; }

  /* ✅ Mobile/Tablet: hide right sidebar + show viewer full width */
  .feedSidebarCol{ display:none !important; }
  .feedViewerCol{ display:block !important; }

  body.f-mode-list .feedViewerCol{ display:none !important; }
  body.f-mode-list .feedSidebarCol{ display:block !important; }

  body.f-mode-post .feedViewerCol{ display:block !important; }
  body.f-mode-post .feedSidebarCol{ display:none !important; }

  .feedViewerCol, .feedSidebarCol{
    width:100% !important; max-width:100% !important; flex:0 0 100% !important;
  }

  .feedBackFixed:active{ transform: scale(.98); }
}
@media (max-width: 575.98px){
  .feedBackFixed{ top: 88px; left: 10px; }
}

/* =========================================================
   MOBILE/TABLET ONLY: HIDE TOP-RIGHT BACK ARROW + COMMENT ICON
   (targets your real IDs in feed.php: #btnBackPost and #btnOpenCommentsDrawer)
========================================================= */
@media (max-width:1024px){
  #btnOpenCommentsDrawer{
    display:none!important;
    visibility:hidden!important;
    opacity:0!important;
    pointer-events:none!important;
  }
}

</style>


<style>
/* [FEED_MOBILE_FACEBOOK_STYLE] */
/* =====================================================
   MOBILE/TABLET: "Facebook-like" media post viewer
   - Desktop keeps Book layout unchanged
   - Mobile/Tablet: full-width card, header always visible, media never covers header
===================================================== */
@media (max-width: 991.98px){

  /* Card look */
  .ig-post-card{
    border-radius: 14px !important;
    overflow: hidden !important;
    box-shadow: 0 10px 30px rgba(0,0,0,.08) !important;
  }

  /* Ensure column stack: header -> media -> details/actions */
  body.f-mode-post .ig-layout{
    display: flex !important;
    flex-direction: column !important;
    height: auto !important;
    min-height: 0 !important;
  }

  /* Header (title/date/avatar) */
  body.f-mode-post .ig-topbar{
    position: relative !important;
    /* z-index: 5 !important; */
    background: #fff !important;
    padding: 12px 14px !important;
    border-bottom: 1px solid rgba(0,0,0,.06) !important;
  }

  /* Media area: full width, no overlay */
  body.f-mode-post .ig-media{
    position: relative !important;
    z-index: 1 !important;
    order: 2 !important;
    width: 100% !important;
    height: auto !important;     /* ✅ allow tall images like Facebook */
    flex: 0 0 auto !important;
    background: transparent;
  }

  /* Images/videos fit container */
  body.f-mode-post .ig-media-main img,
  body.f-mode-post .ig-media-main video,
  body.f-mode-post .ig-media-main iframe{
    width: 100% !important;
    height: auto !important;
    max-width: 100% !important;
    display: block !important;
  }

  /* Videos: keep reasonable height */
  body.f-mode-post .ig-media-main video{
    max-height: 70vh !important;
  }

  /* Details below media */
  body.f-mode-post .ig-details{
    order: 3 !important;
    width: 100% !important;
    height: auto !important;
    background: #fff !important;
  }

  /* Actions bar: looks like FB bottom strip */
  body.f-mode-post .ig-actions,
  body.f-mode-post .post-actions,
  body.f-mode-post .feed-actions{
    /* padding: 10px 14px !important; */
    border-top: 1px solid rgba(0,0,0,.06) !important;
    /* background: #fff !important; */
    margin-top: 20px;
  }

  /* Neutralize fixed heights from media modes */
  body.f-mode-post .ig-post-card .card-body.full-media,
  body.f-mode-post .ig-post-card .card-body.media-only{
    height: auto !important;
  }
}

/* =========================================================
   MOBILE/TABLET ONLY: HIDE TOP-RIGHT BACK ARROW + COMMENT ICON
   (targets your real IDs in feed.php: #btnBackPost and #btnOpenCommentsDrawer)
========================================================= */
@media (max-width:1024px){
  #btnOpenCommentsDrawer{
    display:none!important;
    visibility:hidden!important;
    opacity:0!important;
    pointer-events:none!important;
  }
}

</style>


<style>
/* [FEED_TIKTOK_SCROLL_CSS] */
/* =====================================================
   TikTok-style reel navigation (mobile/tablet)
   - Only active when .ig-viewer-body.mode-reel is present
===================================================== */
@media (max-width: 991.98px){
  .ig-viewer-body.mode-reel{
    background:transparent;
  }
  .ig-viewer-body.mode-reel .ig-post-card{
    border-radius:0 !important;
    box-shadow:none !important;
  }
  .ig-viewer-body.mode-reel.reel-anim-up{ animation: reelSlideUp .18s ease-out; }
  .ig-viewer-body.mode-reel.reel-anim-down{ animation: reelSlideDown .18s ease-out; }
  @keyframes reelSlideUp{
    from{ transform: translateY(18px); opacity:.85; }
    to{ transform: translateY(0); opacity:1; }
  }
  @keyframes reelSlideDown{
    from{ transform: translateY(-18px); opacity:.85; }
    to{ transform: translateY(0); opacity:1; }
  }
}

/* =========================================================
   MOBILE/TABLET ONLY: HIDE TOP-RIGHT BACK ARROW + COMMENT ICON
   (targets your real IDs in feed.php: #btnBackPost and #btnOpenCommentsDrawer)
========================================================= */
@media (max-width:1024px){
  #btnOpenCommentsDrawer{
    display:none!important;
    visibility:hidden!important;
    opacity:0!important;
    pointer-events:none!important;
  }
}

</style>
<style>
/* [FEED_DESKTOP_FACEBOOK_SCROLL_CSS] */
/* =====================================================
   Desktop: center post card scrolls independently
   - Center post card column scrolls up/down
   - Right sidebar stays separate
   - Post layout modes (book/media/text/title) untouched
===================================================== */
@media (min-width: 992px){
  #feedPostScrollCol,
  .row.row-sm > .col-lg-8.feedViewerCol{
    flex:1 1 0 !important;
    min-height:0 !important;
    max-height:100% !important;
    height:auto !important;
    overflow-y:auto !important;
    overflow-x:hidden !important;
    -webkit-overflow-scrolling:touch;
    overscroll-behavior:contain;
    scroll-behavior:auto;
    isolation:isolate;
    touch-action:pan-y;
  }

  .row.row-sm > .col-lg-4{
    flex:0 0 33.333333% !important;
    max-width:33.333333% !important;
    min-height:0 !important;
    overflow:visible !important;
    align-self:flex-start !important;
  }

  #feedPostScrollCol .ig-post-shell,
  .row.row-sm > .col-lg-8.feedViewerCol .ig-post-shell{
    flex:0 0 auto;
    min-height:min-content;
    height:auto;
  }

  #feedPostScrollCol .ig-post-card,
  .row.row-sm > .col-lg-8.feedViewerCol .ig-post-card{
    overflow:visible !important;
  }

  .rightSidebarCard .rightSidebarList{
    overscroll-behavior:contain;
  }
}
</style>
<style>
/* [IG_INSTA_POST_CARD_UI] */
/* Instagram-style post card (reference screenshot) */
  .ig-insta-card{
    max-width:470px;
    margin-left:auto;
    margin-right:auto;
    border:1px solid #dbdbdb !important;
    border-radius:4px !important;
    background:#fff !important;
    box-shadow:none !important;
    overflow:hidden;
  }
  .ig-insta-card .ig-insta-header{
    background:#fff !important;
    border-bottom:0 !important;
    padding:14px 16px 10px !important;
    min-height:0;
  }
  .ig-insta-card .ig-insta-avatar.feed-avatar{
    width:32px !important;
    height:32px !important;
    border:0 !important;
    box-shadow:none !important;
    padding:2px;
    background:linear-gradient(45deg,#f58529,#dd2a7b,#8134af,#515bd4) !important;
  }
  .ig-insta-card .ig-insta-avatar.feed-avatar img{
    border:2px solid #fff;
    border-radius:50%;
  }
  .ig-insta-card .ig-insta-username,
  .ig-insta-card .ig-insta-username a{
    font-size:14px;
    font-weight:600;
    color:#262626 !important;
    text-decoration:none;
  }
  .ig-insta-card .ig-insta-dot{
    color:#8e8e8e;
    margin:0 4px;
    font-weight:400;
  }
  .ig-insta-card .ig-insta-time{
    font-size:14px;
    color:#8e8e8e;
    font-weight:400;
  }
  .ig-insta-card .ig-insta-more{
    border:0;
    background:transparent;
    color:#262626;
    font-size:18px;
    line-height:1;
    padding:4px 2px;
    cursor:pointer;
  }
  .ig-insta-card .ig-insta-tools-flyout{
    display:none;
    position:absolute;
    right:0;
    top:calc(100% + 6px);
    min-width:210px;
    background:#fff;
    border:1px solid #dbdbdb;
    border-radius:8px;
    box-shadow:0 8px 24px rgba(0,0,0,.12);
    padding:6px;
    z-index:20;
  }
  .ig-insta-card .ig-insta-tools-flyout.is-open{ display:block; }
  .ig-insta-card .ig-insta-tools-flyout a{
    display:flex;
    align-items:center;
    gap:8px;
    padding:8px 10px;
    color:#262626 !important;
    text-decoration:none;
    font-size:14px;
    border-radius:6px;
  }
  .ig-insta-card .ig-insta-tools-flyout a:hover{ background:#fafafa; }
  .ig-insta-card .ig-insta-header-actions{ position:relative; }

  .ig-insta-card .ig-viewer-body{
    height:auto !important;
    min-height:0 !important;
  }
  .ig-insta-card .ig-viewer-body .ig-layout{
    display:flex !important;
    flex-direction:column !important;
    height:auto !important;
    min-height:0 !important;
  }
  .ig-insta-card .ig-details{
    width:100% !important;
    flex:0 0 auto !important;
    height:auto !important;
    min-height:0 !important;
  }
  .ig-insta-card .ig-insta-media-stage{
    position:relative;
    width:100%;
    background:transparent;
  }
  .ig-insta-card .ig-media{
    flex:0 0 auto !important;
    width:100% !important;
    height:auto !important;
    min-height:0 !important;
    aspect-ratio:4/5;
    max-height:none !important;
    background:transparent;
    border-bottom:0;
  }
  .ig-insta-card .ig-media-main{
    width:100% !important;
    height:100% !important;
    object-fit:cover !important;
    display:block;
    background:transparent;
  }
  .ig-insta-card .ig-viewer-body.mode-image-overlay .ig-underbar,
  .ig-insta-card .ig-viewer-body.mode-book .ig-underbar{
    display:flex !important;
  }
  .ig-insta-card .ig-viewer-body.mode-image-overlay .ig-media::after,
  .ig-insta-card .ig-viewer-body.mode-image-overlay #pvMedia .ig-image-overlay-actions,
  .ig-insta-card .ig-viewer-body.mode-image-overlay #pvMedia .ig-image-overlay-text{
    display:none !important;
  }
  .ig-insta-card #bookVrail{
    display:none !important;
  }
  .ig-insta-card .ig-insta-carousel-btn{
    position:absolute;
    top:50%;
    transform:translateY(-50%);
    width:28px;
    height:28px;
    border:0;
    border-radius:50%;
    background:rgba(255,255,255,.92);
    color:#262626;
    display:flex;
    align-items:center;
    justify-content:center;
    box-shadow:0 1px 4px rgba(0,0,0,.18);
    cursor:pointer;
    z-index:4;
    font-size:18px;
    line-height:1;
    padding:0;
  }
  .ig-insta-card .ig-insta-carousel-next{ right:12px; }
  .ig-insta-card .ig-insta-carousel-prev{ left:12px; }

  .ig-insta-card .ig-insta-dots-host{
    display:flex;
    justify-content:center;
    padding:10px 0 4px;
    background:#fff;
    min-height:22px;
  }
  .ig-insta-card .ig-insta-dots-host:empty{ display:none; padding:0; min-height:0; }
  .ig-insta-card .ig-insta-strip{
    position:static !important;
    transform:none !important;
    left:auto !important;
    bottom:auto !important;
    background:transparent !important;
    padding:0 !important;
    gap:5px !important;
    max-width:none !important;
  }
  .ig-insta-card .ig-insta-strip .ig-strip-item{
    width:6px !important;
    height:6px !important;
    background:#c7c7c7 !important;
    transform:none !important;
  }
  .ig-insta-card .ig-insta-strip .ig-strip-item.active{
    background:#0095f6 !important;
    transform:none !important;
  }

  .ig-insta-card .ig-insta-actionbar{
    border-bottom:0 !important;
    padding:4px 12px 0 !important;
    display:flex;
    align-items:center;
    justify-content:space-between;
  }
  .ig-insta-card .ig-insta-actions-left{
    display:flex;
    align-items:center;
    gap:14px !important;
    padding:0 !important;
    flex-wrap:nowrap;
  }
  .ig-insta-card .ig-insta-actions-left .ig-act{
    gap:6px;
  }
  .ig-insta-card .ig-insta-actions-left .ig-circle{
    width:auto !important;
    height:auto !important;
    border:0 !important;
    background:transparent !important;
    box-shadow:none !important;
    transform:none !important;
  }
  .ig-insta-card .ig-insta-actions-left .ig-circle i{
    font-size:24px !important;
    color:#262626 !important;
  }
  .ig-insta-card .ig-insta-actions-left .ig-count{
    font-size:14px !important;
    font-weight:600 !important;
    color:#262626 !important;
    opacity:1 !important;
    min-width:0;
  }
  .ig-insta-card .ig-insta-actions-left #viewCountLink,
  .ig-insta-card .ig-insta-actions-left #btnLike,
  .ig-insta-card .ig-insta-actions-right #saveCount{
    display:none !important;
  }
  .ig-insta-card .ig-insta-actions-left #btnShare .ig-count,
  .ig-insta-card .ig-insta-actions-right .ig-count{
    display:none !important;
  }
  .ig-insta-card .ig-insta-actions-right .ig-act .ig-circle{
    width:auto !important;
    height:auto !important;
    border:0 !important;
    background:transparent !important;
    box-shadow:none !important;
  }
  .ig-insta-card .ig-insta-actions-right .ig-circle i{
    font-size:24px !important;
    color:#262626 !important;
  }
  .ig-insta-card .ig-insta-actions-right .ig-count,
  .ig-insta-card .ig-insta-actions-right #saveCount{
    display:none !important;
  }

  .ig-insta-card .ig-insta-caption-block{
    padding:8px 16px 14px;
    background:#fff;
  }
  .ig-insta-card .ig-insta-likes-row{
    font-size:14px;
    font-weight:600;
    color:#262626;
    margin-bottom:6px;
  }
  .ig-insta-card .ig-insta-caption-block .ig-title{
    font-size:14px;
    font-weight:400;
    color:#262626;
    margin:0 0 4px;
    padding:0;
    line-height:1.4;
  }
  .ig-insta-card .ig-insta-cap-user{
    font-weight:600;
    color:#262626;
  }
  .ig-insta-card #btnLove.rx-love .ig-insta-ico-heart.fa-heart,
  .ig-insta-card #btnLove.rx-active .ig-insta-ico-heart.fa-heart{
    color:#ed4956 !important;
  }
  .ig-insta-card #btnSave.is-save .ig-insta-ico-save.fa-bookmark{
    color:#262626 !important;
  }
  .ig-insta-card .ig-insta-caption-block .ig-title:empty{ display:none; }
  .ig-insta-card .ig-insta-caption-block .ig-body{
    font-size:14px;
    color:#262626;
    line-height:1.45;
    margin:0;
  }
  .ig-insta-card .ig-topbar{
    border-bottom:0 !important;
    padding:0 !important;
  }
  .ig-insta-card .ig-post-shell > .ig-post-footer-card{
    display:none !important;
  }
  .ig-insta-card .ig-layout.is-media-only .ig-media,
  .ig-insta-card .ig-layout.is-full-media .ig-media{
    width:100% !important;
    height:auto !important;
    aspect-ratio:4/5 !important;
  }
  .ig-insta-card .card-body.media-only,
  .ig-insta-card .card-body.full-media{
    height:auto !important;
  }
</style>
<style>
/* =====================================================
   ✅ MOBILE/TABLET: REEL-ONLY LAYOUT
   - Reel shows (TikTok style)
   - Center post card + right sidebar hidden
   - NavLeftbar hidden
===================================================== */
@media (max-width: 991.98px){
  /* Hide desktop nav leftbar + right sidebar card on mobile/tablet */
  #ttNavLeftbar{ display:none !important; }
  .col-lg-4{ display:none !important; }

  /* Viewer takes full width */
  .col-lg-8{ flex:0 0 100% !important; max-width:100% !important; }

  /* When reel mode is active, make viewer full screen and remove desktop spacing */
  body.mt-reel-only .sh-pagebody{ margin-left:0 !important; padding-left:0 !important; padding-right:0 !important; }
  body.mt-reel-only .row.row-sm{ margin-left:0 !important; margin-right:0 !important; }
  body.mt-reel-only .ig-post-card{
    border-radius:0 !important;
    box-shadow:none !important;
    border:0 !important;
  }
  body.mt-reel-only .ig-post-card .card-header{ display:none !important; } /* hide title/date top (video should not be covered) */
  body.mt-reel-only .ig-post-card .card-body{ padding:0 !important; }

  /* Reel media fills full viewport */
  body.mt-reel-only .ig-viewer-body.mode-reel{
    height: calc(var(--vh, 1vh) * 100) !important;
    min-height: calc(var(--vh, 1vh) * 100) !important;
  }
  body.mt-reel-only .ig-viewer-body.mode-reel .ig-layout{
    height:100% !important;
    min-height:100% !important;
  }
  body.mt-reel-only .ig-viewer-body.mode-reel .ig-media{
    height: 100% !important;
    min-height: 100% !important;
  }

  /* Reel leftbar */
  #reelLeftbar{
    position:fixed;
    left:10px;
    top:76px; /* below top menu */
    z-index: 9999;
    display:none;
    flex-direction:column;
    gap:12px;
    pointer-events:auto;
  }
  body.mt-reel-only #reelLeftbar{ display:flex !important; }

  #reelLeftbar .rlb-btn{
    width:46px;
    height:46px;
    border-radius:14px;
    border:0;
    background: rgba(0,0,0,.48);
    color:#fff;
    display:flex;
    align-items:center;
    justify-content:center;
    box-shadow: 0 6px 18px rgba(0,0,0,.35);
  }
  #reelLeftbar .rlb-btn i{ font-size:24px; }
  #reelLeftbar .rlb-btn:active{ transform: scale(.96); }
  #reelLeftbar .rlb-link{ text-decoration:none; }
}

/* =========================================================
   MOBILE/TABLET ONLY: HIDE TOP-RIGHT BACK ARROW + COMMENT ICON
   (targets your real IDs in feed.php: #btnBackPost and #btnOpenCommentsDrawer)
========================================================= */
@media (max-width:1024px){
  #btnOpenCommentsDrawer{
    display:none!important;
    visibility:hidden!important;
    opacity:0!important;
    pointer-events:none!important;
  }
}

</style>



<style>
/* ===== Reel vertical rail: RIGHT + CENTERED + LOWER (mobile/tablet) ===== */
@media (max-width:1024px){
  body.mt-reel-only #reelLeftbar,
  body.reel-mode #reelLeftbar{
    left:auto !important;
    right:12px !important;
    top:50% !important;
    bottom:auto !important;
    transform: translateY(calc(-50% + 64px)) !important; /* move DOWN more */
    z-index:99999 !important;
  }
}

/* =========================================================
   MOBILE/TABLET ONLY: HIDE TOP-RIGHT BACK ARROW + COMMENT ICON
   (targets your real IDs in feed.php: #btnBackPost and #btnOpenCommentsDrawer)
========================================================= */
@media (max-width:1024px){
  #btnOpenCommentsDrawer{
    display:none!important;
    visibility:hidden!important;
    opacity:0!important;
    pointer-events:none!important;
  }
}

</style>


<style>
/* ==========================================
   REMOVE REEL ACTION RAIL ON ALL DEVICES
   (desktop + mobile + tablet)
   ========================================== */
#reelLeftbar{
  display:none !important;
}

/* =========================================================
   MOBILE/TABLET ONLY: HIDE TOP-RIGHT BACK ARROW + COMMENT ICON
   (targets your real IDs in feed.php: #btnBackPost and #btnOpenCommentsDrawer)
========================================================= */
@media (max-width:1024px){
  #btnOpenCommentsDrawer{
    display:none!important;
    visibility:hidden!important;
    opacity:0!important;
    pointer-events:none!important;
  }
}

</style>


<style>
/* =====================================================
   MOBILE/TABLET: React (comment/love/like/share/save)
   - Apply to ALL layouts (Book + Reel + others)
   - Vertical stack on the right
   - Overlay on top of media/card area
   ===================================================== */
@media (max-width: 1024px){

  /* Make sure the card/layout can anchor absolute overlay */
  .ig-layout{ position: relative !important; }

  /* Underbar becomes an overlay (no background strip) */
  .ig-underbar{
    position: absolute !important;
    right: 12px !important;
    top: 50% !important;
    transform: translateY(calc(-50% + 56px)) !important; /* centered + a little down */
    z-index: 9999 !important;

    padding: 0 !important;
    margin: 0 !important;
    background: transparent !important;
    border: 0 !important;
    box-shadow: none !important;
  }

  /* Make action icons vertical */
  .ig-underbar .ig-actions-icons{
    display: flex !important;
    flex-direction: column !important;
    align-items: center !important;
    justify-content: center !important;
    gap: 14px !important;
    padding: 0 !important;
    flex-wrap: nowrap !important;
  }

  /* Each action: icon then count */
  .ig-underbar .ig-act{
    display: flex !important;
    flex-direction: column !important;
    align-items: center !important;
    justify-content: center !important;
    gap: 1px !important;
    padding: 0 !important;
    border: 0 !important;
    background: transparent !important;
    line-height: 1 !important;
  }

  /* Round dark pill behind icon so it shows on any media */
  .ig-underbar .ig-circle{
    width: 44px !important;
    height: 44px !important;
    border-radius: 999px !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    background: rgba(0,0,0,.45) !important;
    border: 1px solid rgba(255,255,255,.14) !important;
    box-shadow: 0 10px 24px rgba(0,0,0,.18) !important;
  }

  /* Icon color visibility */
  .ig-underbar .ig-circle i,
  .ig-underbar .ig-circle svg{
    color: #fff !important;
    fill: #fff !important;
    font-size: 20px !important;
  }

  /* Count under icon */
  .ig-underbar .ig-count{
    color: rgba(255,255,255,.92) !important;
    font-weight: 700 !important;
    font-size: 12px !important;
    line-height: 1 !important;
    text-align: center !important;
  }

  /* Keep the vertical stack clickable */
  .ig-underbar, .ig-underbar *{ pointer-events: auto !important; }
}

/* =========================================================
   MOBILE/TABLET ONLY: HIDE TOP-RIGHT BACK ARROW + COMMENT ICON
   (targets your real IDs in feed.php: #btnBackPost and #btnOpenCommentsDrawer)
========================================================= */
@media (max-width:1024px){
  #btnOpenCommentsDrawer{
    display:none!important;
    visibility:hidden!important;
    opacity:0!important;
    pointer-events:none!important;
  }
}

</style>


<style>
/* =====================================================
   MOBILE/TABLET: React overlay tuning (NEW FILE)
   - Smaller buttons for small screens
   - Move the whole vertical stack slightly DOWN
   ===================================================== */
@media (max-width: 1024px){

  /* move stack down a little more */
  .ig-underbar{
    transform: translateY(calc(-50% + 84px)) !important; /* centered then moved down */
  }

  /* smaller spacing */
  .ig-underbar .ig-actions-icons{ gap: 10px !important; }

  /* smaller pill */
  .ig-underbar .ig-circle{
    width: 36px !important;
    height: 36px !important;
  }

  /* smaller icon */
  .ig-underbar .ig-circle i,
  .ig-underbar .ig-circle svg{
    font-size: 17px !important;
  }

  /* smaller count */
  .ig-underbar .ig-count{
    font-size: 11px !important;
  }
}

/* =========================================================
   MOBILE/TABLET ONLY: HIDE TOP-RIGHT BACK ARROW + COMMENT ICON
   (targets your real IDs in feed.php: #btnBackPost and #btnOpenCommentsDrawer)
========================================================= */
@media (max-width:1024px){
  #btnOpenCommentsDrawer{
    display:none!important;
    visibility:hidden!important;
    opacity:0!important;
    pointer-events:none!important;
  }
}

</style>


<style>
/* =========================================================
   MOBILE/TABLET FEED (NEW)
   - Separate from desktop/laptop
   - Shows vertical post cards (full width)
========================================================= */
.mobile-only{ display:none; }
.desktop-only{ display:block; }
.mf-feed.mf-hydrating{
  visibility:hidden;
  min-height:calc(100vh - 180px);
  pointer-events:none;
}
.mf-feed.mf-hydrating .mf-card{
  transition:none !important;
}

.mf-feed-empty{
  display:flex;
  flex-direction:column;
  align-items:center;
  justify-content:center;
  min-height:min(520px, calc(100vh - 320px));
  padding:48px 24px 56px;
  text-align:center;
  color:#667085;
}
.mf-feed-empty i{
  display:block;
  font-size:56px;
  line-height:1;
  margin:0 auto 16px;
  color:#98a2b3;
}
.mf-feed-empty .mf-feed-empty-title{
  font-size:17px;
  font-weight:700;
  color:#344054;
  margin:0;
  letter-spacing:-0.01em;
}

html.dark-auto:not([data-msb-appearance]) .mf-feed-empty,
html[data-theme="dark"]:not([data-msb-appearance]) .mf-feed-empty{
  color:#b1bcce !important;
}
html.dark-auto:not([data-msb-appearance]) .mf-feed-empty i,
html[data-theme="dark"]:not([data-msb-appearance]) .mf-feed-empty i{
  color:#b1bcce !important;
  opacity:.85;
}
html.dark-auto:not([data-msb-appearance]) .mf-feed-empty .mf-feed-empty-title,
html[data-theme="dark"]:not([data-msb-appearance]) .mf-feed-empty .mf-feed-empty-title{
  color:#e8edf5 !important;
}

@media (max-width:1024.98px){
  .mobile-only{ display:block !important; }
  .desktop-only{ display:none !important; }
}

@media (max-width:767px){
  /* Give page some breathing room */
  .mf-feed{ padding: 10px 10px 80px; margin-top: 5%;}
  .mf-feed.mf-hydrating{
    visibility:hidden;
    min-height:calc(100svh - 180px);
    pointer-events:none;
  }

  .mf-feed .mf-card{
    margin-left: auto;
    margin-right: auto;
    max-width: 100%;
    border:0 !important;
    border-bottom:1px solid var(--feed-post-divider, var(--feed-border-strong, rgba(177, 188, 206, 0.22))) !important;
    border-radius:0 !important;
    box-shadow:none !important;
    margin-bottom:0 !important;
  }

  body.feed-insta-ui .feed-desktop-center{
    border-left:1px solid var(--feed-post-column-border, var(--feed-border-strong, rgba(177, 188, 206, 0.22)));
    border-right:1px solid var(--feed-post-column-border, var(--feed-border-strong, rgba(177, 188, 206, 0.22)));
    box-sizing:border-box;
  }

  .mf-feed .mf-media-shell > .mf-media-top-actions{
    top:12px;
    right:12px;
    z-index:25;
  }

  .mf-feed .mf-media-shell > .mf-media-top-actions .mf-friend-btn{
    display:inline-flex !important;
    align-items:center;
    justify-content:center;
  }

  .mf-feed .mf-head .mf-friend-btn.mf-media-follow-btn{
    display:none !important;
  }
  .mf-feed .mf-card:has(.mf-media-shell) .mf-head .mf-friend-btn{
    display:none !important;
  }

  .mf-card{
    background:var(--feed-surface, #fff);
    box-shadow:none;
    border:0;
    border-bottom:1px solid var(--feed-post-divider, rgba(15,23,42,.12));
    border-radius:0;
    overflow:hidden;
    margin: 0 auto;
  }
  body .mf-feed .mf-card:has(.mf-head--on-media){
    padding:8px 40px!important;
    box-sizing:border-box!important;
    overflow:visible !important;
  }
  .mf-head:not(.mf-head--on-media){
    padding:18px 22px 14px;
    display:flex;
    align-items:center;
    gap:12px;
  }
  .mf-avatar{
    width:52px;height:52px;border-radius:999px;
    display:flex;align-items:center;justify-content:center;
    font-weight:800;color:#fff;flex:0 0 auto;overflow:hidden;
    padding:2px;
    background:linear-gradient(135deg, #0ea5e9 0%, #2563eb 58%, #f8fafc 100%);
  }
  .mf-avatar img{
    width:100%;height:100%;display:block;object-fit:cover;border-radius:50%;
    border:2px solid #fff;
    background:#fff;
  }
  .mf-meta{ min-width:0; flex:1 1 auto; margin-left:-10px; }
  .mf-name-row{
    display:flex;
    align-items:center;
    gap:5px;
    min-width:0;
    flex-wrap:nowrap;
  }

  /* --- Mobile/Tablet 3-dots menu (legacy non-post-card menus only) --- */
  .mf-menu-wrap{ position:relative; flex:0 0 auto; margin-left:auto; }
  .mf-menu-btn:not(.post-card-menu-btn){
    width:38px;height:38px;
    border:0;
    background:transparent;
    border-radius:999px;
    display:flex;align-items:center;justify-content:center;
    color:#101828;
  }
  .mf-menu-btn:not(.post-card-menu-btn):active{ background:rgba(0,0,0,.06); }
  .mf-menu:not(.post-card-menu){
    position:absolute;
    top:38px;
    right:0;
    min-width:170px;
    background:#fff;
    border:1px solid rgba(0,0,0,.10);
    border-radius:14px;
    box-shadow: 0 14px 34px rgba(16,24,40,.16);
    padding:6px;
    z-index:50;
    display:none;
  }
  .mf-menu:not(.post-card-menu).open{ display:block; }
  .mf-menu:not(.post-card-menu) a, .mf-menu:not(.post-card-menu) button{
    width:100%;
    display:flex;
    align-items:center;
    gap:10px;
    padding:10px 10px;
    border:0;
    background:transparent;
    color:#111;
    text-decoration:none;
    font-weight:700;
    font-size:13px;
    border-radius:12px;
  }
  .mf-menu:not(.post-card-menu) a:hover, .mf-menu:not(.post-card-menu) button:hover{ background:rgba(8,97,188,.08); }
  .mf-menu:not(.post-card-menu) .mf-del{ color:#b42318; }
  .mf-menu:not(.post-card-menu) .mf-del:hover{ background:rgba(180,35,24,.10); }
  .mf-menu:not(.post-card-menu) .mf-unfriend{ color:#b42318; }
  .mf-menu:not(.post-card-menu) .mf-unfriend:hover{ background:rgba(180,35,24,.10); }
  .mf-menu:not(.post-card-menu) .mf-publisher-unfollow{ color:#b42318; }
  .mf-menu:not(.post-card-menu) .mf-publisher-unfollow:hover{ background:rgba(180,35,24,.10); }

  .mf-name{
    font-weight:700;
    font-size:13px;
    line-height:1.2;
    margin:0;
    color:#101828;
    min-width:0;
    flex:1 1 auto;
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis;
  }
  .mf-peer-link,.pv-peer-link{display:inline-flex;align-items:flex-start;gap:8px;color:inherit;text-decoration:none;min-width:0;flex:1 1 auto;}
  .mf-peer-link:hover,.pv-peer-link:hover{color:inherit;text-decoration:none;opacity:.92;}
  .mf-dot{
    color:#98a2b3;
    font-size:12px;
    line-height:1;
    flex:0 0 auto;
  }
  .mf-time{
    font-size:12px;
    color:#667085;
    margin:0;
    font-weight:500;
    flex:0 0 auto;
    white-space:nowrap;
  }
  .mf-music-row{
    display:flex;
    align-items:center;
    gap:4px;
    min-width:0;
    max-width:100%;
    margin-top:1px;
    margin-left:0;
    padding-left:0;
    font-size:11px;
    line-height:1.2;
    color:#667085;
    font-weight:500;
    overflow:hidden;
  }
  .mf-music-ic{
    flex:0 0 auto;
    font-size:10px;
    line-height:1;
    color:#667085;
  }
  .mf-music-title,
  .mf-music-artist{
    min-width:0;
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis;
  }
  .mf-music-title{ flex:1 1 auto; }
  .mf-music-artist{ flex:0 1 auto; max-width:46%; }
  .mf-music-dot{
    flex:0 0 auto;
    color:#98a2b3;
    font-size:11px;
    line-height:1;
  }
  .mf-verified{
    color:#1d9bf0;
    font-size:12px;
    line-height:1;
    flex:0 0 auto;
  }

  .mf-title{
    padding:0 22px 12px;
    font-size:18px;
    font-weight:900;
    line-height:1.3;
    word-break:break-word;
    color:#101828;
  }

  .mf-media:not(.media-stage){
    position:relative;
    background:transparent;
    padding:0 22px;
    overflow:hidden;
  }
  .mf-media-carousel,
  .media-carousel{
    position:relative;
    width:100%;
    overflow:hidden;
    border-radius:8px;
    background:transparent;
  }
  .mf-media-slides,
  .media-slides{
    display:flex;
    width:100%;
    flex-wrap:nowrap !important;
    flex-direction:row !important;
    transition:transform .28s ease;
  }
  .mf-media-slide,
  .media-slide{
    flex:0 0 100%;
    width:100%;
    max-width:100%;
    display:flex;
    align-items:center;
    justify-content:center;
    overflow:hidden;
    background:transparent;
  }
  .mf-media-slide > img,
  .mf-media-slide > video,
  .media-slide > img,
  .media-slide > video{
    width:100%;
    height:auto;
    display:block;
    background:transparent;
    flex:0 0 auto;
  }
  .mf-media-slide > img,
  .media-slide > img{ object-fit:cover; object-position:center center; }
  .mf-media-slide > video,
  .media-slide > video{ object-fit:contain; object-position:center center; }
  .mf-media-nav,
  .media-nav{
    position:absolute;
    top:50%;
    transform:translateY(-50%);
    width:46px;
    height:46px;
    border:none;
    border-radius:999px;
    background:rgba(255,255,255,.82);
    color:#1f2937;
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:20px;
    cursor:pointer;
    box-shadow:0 8px 24px rgba(0,0,0,.18);
    z-index:4;
  }
  .mf-media-nav:hover,
  .media-nav:hover{ background:#fff; }
  .mf-media-nav.prev,
  .media-nav.prev{ left:12px; }
  .mf-media-nav.next,
  .media-nav.next{ right:12px; }
  .mf-media-dots,
  .media-dots{
    position:absolute;
    left:50%;
    bottom:18px;
    transform:translateX(-50%);
    display:flex;
    align-items:center;
    justify-content:center;
    gap:8px;
    padding:8px 14px;
    border-radius:999px;
    background:rgba(17,24,39,.5);
    z-index:5;
  }
  .mf-media-dot,
  .media-dot{
    width:10px !important;
    height:10px !important;
    min-width:10px !important;
    min-height:10px !important;
    flex:0 0 10px !important;
    display:block !important;
    border:none !important;
    border-radius:50% !important;
    padding:0 !important;
    margin:0 !important;
    background:rgba(255,255,255,.45) !important;
    cursor:pointer;
    appearance:none;
    -webkit-appearance:none;
    box-shadow:none !important;
    font-size:0 !important;
    line-height:0 !important;
    color:transparent !important;
    text-indent:-9999px !important;
    overflow:hidden !important;
  }
  .mf-media-dot.is-active,
  .media-dot.is-active{
    background:#fff !important;
    transform:scale(1.08);
  }
  .mf-card.mf-card-live .mf-title,
  .mf-card.mf-card-live .mf-body,
  .mf-card.mf-card-live .mf-head{
    display:none;
  }
  .mf-card.mf-card-live{
    position:relative;
    width:100%;
    max-width:100%;
    margin:0 auto;
    background:transparent;
    border:0;
    box-shadow:none;
    overflow:visible;
  }
  .mf-card.mf-card-live .mf-media{
    padding:0;
    margin:0 auto;
    background:transparent;
  }
  .mf-live-stage,
  a.mf-live-stage{
    position:relative;
    overflow:hidden;
    display:block;
    text-decoration:none;
    color:inherit;
    background:
      radial-gradient(circle at top left, rgba(255,255,255,.22), transparent 28%),
      linear-gradient(180deg, #d8dee8 0%, #9ba7b9 40%, #1d2430 100%);
    box-shadow:0 24px 56px rgba(0,0,0,.28);
  }
  .mf-live-open-hit{
    position:absolute;
    inset:0;
    z-index:1;
    display:block;
    text-decoration:none;
    color:transparent;
  }
  .mf-live-stage img{
    position:absolute;
    inset:0;
    width:100%;
    height:100%;
    object-fit:cover;
    object-position:center center;
    border-radius:0;
  }
  .mf-live-placeholder{
    position:absolute;
    inset:0;
    z-index:1;
    display:flex;
    align-items:center;
    justify-content:center;
    padding:24px;
    background:
      radial-gradient(circle at top left, rgba(255,255,255,.18), transparent 30%),
      linear-gradient(180deg, #d7dde7 0%, #a5b0c2 38%, #293243 100%);
  }
  .mf-live-placeholder-inner{
    display:flex;
    flex-direction:column;
    align-items:center;
    gap:12px;
    text-align:center;
    color:#fff;
  }
  .mf-live-placeholder-avatar{
    width:74px;
    height:74px;
    border-radius:50%;
    padding:4px;
    background:linear-gradient(135deg,#1d4ed8 0%, #60a5fa 52%, #ffffff 100%);
    box-shadow:0 14px 28px rgba(0,0,0,.18);
  }
  .mf-live-placeholder-avatar img{
    position:static;
    inset:auto;
    width:100%;
    height:100%;
    border-radius:50%;
    border:2px solid rgba(255,255,255,.94);
    object-fit:cover;
  }
  .mf-live-placeholder-title{
    margin:0;
    font-size:22px;
    font-weight:900;
    line-height:1.1;
    text-shadow:0 3px 14px rgba(0,0,0,.24);
  }
  .mf-live-placeholder-sub{
    margin:0;
    max-width:240px;
    font-size:14px;
    line-height:1.45;
    color:rgba(255,255,255,.92);
    text-shadow:0 2px 10px rgba(0,0,0,.24);
  }
  .mf-live-overlay{
    position:absolute;
    inset:0;
    display:block;
    padding:0;
    z-index:2;
    background:
      linear-gradient(180deg, rgba(15,23,42,.10) 0%, rgba(15,23,42,.02) 32%, rgba(15,23,42,.42) 72%, rgba(15,23,42,.82) 100%);
    color:#fff;
    pointer-events:none;
  }
  .mf-live-top,
  .mf-live-bottom,
  .mf-live-footer,
  .mf-live-actionbar,
  .mf-live-action-btn,
  .mf-live-comments-link,
  .mf-live-cta{
    pointer-events:auto;
  }
  .mf-live-top{
    position:absolute;
    top:16px;
    left:16px;
    right:16px;
    display:flex;
    align-items:flex-start;
    justify-content:space-between;
    gap:8px;
    z-index:3;
  }
  .mf-live-host{
    display:flex;
    align-items:center;
    gap:10px;
    min-width:0;
    max-width:calc(100% - 132px);
  }
  .mf-live-host-avatar{
    width:46px;
    height:46px;
    border-radius:50%;
    padding:3px;
    flex:0 0 auto;
    background:linear-gradient(135deg,#1d4ed8 0%, #60a5fa 55%, #ffffff 100%);
    box-shadow:0 8px 24px rgba(0,0,0,.18);
  }
  .mf-live-host-avatar img{
    position:static;
    inset:auto;
    width:100%;
    height:100%;
    border-radius:50%;
    border:2px solid rgba(255,255,255,.94);
    object-fit:cover;
    object-position:center center;
  }
  .mf-live-host-meta{
    min-width:0;
    display:flex;
    flex-direction:column;
    gap:3px;
  }
  .mf-live-host-name{
    margin:0;
    color:#fff;
    font-size:17px;
    font-weight:900;
    line-height:1.1;
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis;
    text-shadow:0 3px 14px rgba(0,0,0,.24);
  }
  .mf-live-host-sub{
    margin:0;
    color:rgba(255,255,255,.9);
    font-size:13px;
    line-height:1.2;
    text-shadow:0 2px 10px rgba(0,0,0,.24);
  }
  .mf-live-top-pills{
    display:flex;
    gap:8px;
    flex:0 0 auto;
  }
  .mf-live-pill{
    display:inline-flex;
    align-items:center;
    gap:6px;
    min-height:38px;
    padding:8px 12px;
    border-radius:12px;
    background:rgba(17,24,39,.58);
    backdrop-filter:blur(8px);
    color:#fff;
    font-weight:800;
    font-size:14px;
    line-height:1;
    box-shadow:0 10px 24px rgba(0,0,0,.18);
  }
  .mf-live-pill i{ font-size:18px; }
  .mf-live-bottom{
    position:absolute;
    left:16px;
    right:16px;
    bottom:14px;
    display:flex;
    flex-direction:column;
    gap:12px;
    transform:none;
    z-index:3;
  }
  .mf-live-chip{
    align-self:flex-start;
    display:inline-flex;
    align-items:center;
    gap:8px;
    padding:8px 14px;
    border-radius:999px;
    background:rgba(239,68,68,.92);
    color:#fff;
    font-size:14px;
    font-weight:900;
    letter-spacing:.01em;
    text-transform:uppercase;
  }
  .mf-live-copy{
    display:flex;
    flex-direction:column;
    gap:6px;
    order:2;
  }
  .mf-live-title{
    margin:0;
    color:#fff;
    font-size:22px;
    line-height:1.1;
    font-weight:900;
    text-shadow:0 4px 16px rgba(0,0,0,.22);
  }
  .mf-live-desc{
    margin:0;
    color:rgba(255,255,255,.94);
    font-size:13px;
    line-height:1.4;
    text-shadow:0 2px 12px rgba(0,0,0,.22);
  }
  .mf-live-footer{
    display:flex;
    align-items:flex-end;
    justify-content:flex-start;
    gap:14px;
    order:1;
  }
  .mf-live-cta{
    display:inline-flex;
    align-items:center;
    gap:10px;
    padding:12px 18px;
    border-radius:999px;
    background:rgba(255,255,255,.96);
    color:#0f172a;
    font-size:16px;
    font-weight:900;
    text-decoration:none;
    box-shadow:0 16px 30px rgba(15,23,42,.18);
    pointer-events:auto;
  }
  .mf-live-cta i{ font-size:20px; }
  .mf-live-actionbar{
    display:flex;
    align-items:flex-end;
    justify-content:space-between;
    gap:14px;
    margin-top:2px;
    order:3;
    pointer-events:auto;
  }
  .mf-live-action-left{
    display:flex;
    flex-direction:column;
    gap:10px;
    min-width:0;
    flex:1 1 auto;
  }
  .mf-live-action-row{
    display:flex;
    align-items:center;
    gap:18px;
    flex-wrap:wrap;
  }
  .mf-live-action-spacer{
    margin-left:auto;
    display:inline-flex;
    align-items:flex-end;
  }
  .mf-live-action-btn{
    background:none;
    border:none;
    padding:0;
    color:#fff;
    display:flex;
    align-items:center;
    gap:6px;
    font-size:14px;
    line-height:1;
    cursor:pointer;
    text-decoration:none;
    text-shadow:0 2px 12px rgba(0,0,0,.32);
  }
  .mf-live-action-btn i{ color:#fff !important; }
  .mf-live-action-btn .mf-num{
    color:#fff;
    font-size:14px;
    font-weight:800;
    line-height:1;
    text-shadow:0 2px 12px rgba(0,0,0,.32);
  }
  .mf-live-comments-link{
    color:rgba(255,255,255,.95);
    font-size:14px;
    line-height:1.3;
    cursor:pointer;
    text-shadow:0 2px 12px rgba(0,0,0,.32);
  }

  /* NEW: separate video and reel card layouts only */
  .mf-card.mf-card-video{ background:var(--feed-surface, #f8fafc); border:1px solid var(--feed-border, rgba(15,23,42,.08)); }
  .mf-card.mf-card-video .mf-title{ display:none; }
  .mf-card.mf-card-video .mf-media{ background:transparent; }
  .mf-card.mf-card-reel .mf-media{ background:transparent; }
  .mf-card.mf-card-video .mf-media,
  .mf-card.mf-card-video .mf-video-shell{ position:relative; }
  .mf-card.mf-card-video .mf-video-shell{
    margin:14px 16px 0;
    border-radius:18px;
    overflow:hidden;
    background:transparent;
    box-shadow:0 18px 40px rgba(0,0,0,.35);
  }
  .mf-card.mf-card-video .mf-media video,
  .mf-card.mf-card-reel .mf-media video{ width:100%; display:block; background:transparent; }
  .mf-card.mf-card-video:not(.mf-card-single-video) .mf-media video{ aspect-ratio:16/9; object-fit:contain; max-height:none; }
  .mf-card.mf-card-single-video .mf-media:not(.phone-shot) video,
  .mf-card.mf-card-single-image .mf-media:not(.phone-shot) img{
    aspect-ratio:auto;
    height:auto;
    max-height:min(78vh,960px);
    object-fit:contain;
  }
  .mf-card.mf-card-single-video .mf-media.phone-shot video,
  .mf-card.mf-card-phone-shot .mf-media.phone-shot video,
  .mf-card.mf-card-phone-shot .mf-media.media-stage.standard-video-stage > video,
  .mf-card.mf-card-single-image .mf-media.phone-shot img,
  .mf-card.mf-card-phone-shot .mf-media.phone-shot img,
  .mf-card.mf-card-phone-shot .mf-media.media-stage.standard-image-stage > img{
    width:100%;
    height:auto;
    max-height:min(78vh,960px);
    object-fit:contain;
  }
  .mf-card.mf-card-video.mf-card-device-portrait .mf-media video{
    aspect-ratio:9/13;
    max-height:min(78vh, 850px);
  }
  .mf-card.mf-card-video.mf-card-device-square .mf-media video{
    aspect-ratio:1/1;
    max-height:min(78vh, 720px);
  }
  .mf-card.mf-card-video.mf-card-device-landscape .mf-media video{
    aspect-ratio:auto;
    max-height:min(78vh, 960px);
  }
  .mf-card.mf-card-video .mf-video-rail{
    position:absolute; left:14px; top:50%; transform:translateY(-50%); z-index:3;
    display:flex; flex-direction:column; gap:10px; justify-content:center;
  }
  .mf-card.mf-card-video .mf-video-rail .mf-act,
  .mf-card.mf-card-reel .mf-reel-rail .mf-act{
    width:44px; min-height:44px; padding:8px 4px; border-radius:999px;
    background:rgba(0,0,0,.45); color:#fff; display:flex; flex-direction:column;
    align-items:center; justify-content:center; gap:2px;
    backdrop-filter: blur(4px);
  }
  .mf-card.mf-card-video .mf-video-rail .mf-act i,
  .mf-card.mf-card-reel .mf-reel-rail .mf-act i{ color:inherit; font-size:18px; }
  .mf-card.mf-card-video .mf-video-rail .mf-act .mf-num,
  .mf-card.mf-card-reel .mf-reel-rail .mf-act .mf-num{ color:#fff; font-size:11px; line-height:1.1; }
  .mf-card.mf-card-video .mf-body{ padding-top:14px; }
  .mf-card.mf-card-video .mf-video-meta{ padding:14px 18px 18px; }
  .mf-card.mf-card-video .mf-video-title{ font-size:16px; font-weight:800; line-height:1.35; color:#f5f7fb; margin:0 0 8px; }
  .mf-card.mf-card-video .mf-video-body{ color:#c8d0db; font-size:13px; line-height:1.6; }
  .mf-card.mf-card-video .mf-video-body .mf-readmore{ color:#fff; text-decoration:underline; margin-left:6px; }

  .mf-card.mf-card-reel{
    --mf-reel-ar-w: 9;
    --mf-reel-ar-h: 16;
    --mf-reel-side-gap: 24px;
    --mf-reel-top-gap: 28px;
    --mf-reel-bottom-gap: 104px;
    --mf-reel-max-height: calc(100dvh - var(--mf-reel-top-gap) - var(--mf-reel-bottom-gap));
    --mf-reel-frame-height: min(820px, max(420px, var(--mf-reel-max-height)));
    --mf-reel-frame-width: min(calc(var(--mf-reel-frame-height) * var(--mf-reel-ar-w) / var(--mf-reel-ar-h)), calc(100vw - var(--mf-reel-side-gap)));
    position:relative;
    width:min(100%, var(--mf-reel-frame-width));
    max-width:min(100%, var(--mf-reel-frame-width));
    margin-left:auto;
    margin-right:auto;
    border-radius:22px;
    background:transparent;
    border:0;
    box-shadow:none;
    overflow:hidden;
  }
  .mf-card.mf-card-reel.mf-card-phone-shot{
    width:auto;
    max-width:100%;
    --mf-reel-frame-width:100%;
  }
  .mf-card.mf-card-reel.mf-card-phone-shot .mf-media{
    width:100%;
    min-height:0;
    aspect-ratio:auto;
  }
  .mf-card.mf-card-reel.mf-card-phone-shot .mf-media video{
    width:100%;
    height:auto;
    max-height:min(78vh, 960px);
    aspect-ratio:auto;
    object-fit:contain;
  }
  .mf-card.mf-card-reel .mf-head{
    position:absolute;
    left:14px;
    right:14px;
    top:14px;
    z-index:4;
    padding:0;
    background:transparent;
    color:#fff;
  }
  .mf-card.mf-card-reel .mf-head .mf-name,
  .mf-card.mf-card-reel .mf-head .mf-time,
  .mf-card.mf-card-reel .mf-head .mf-music-row,
  .mf-card.mf-card-reel .mf-head .mf-music-ic,
  .mf-card.mf-card-reel .mf-head .mf-music-title,
  .mf-card.mf-card-reel .mf-head .mf-music-artist,
  .mf-card.mf-card-reel .mf-head .mf-music-dot,
  .mf-card.mf-card-reel .mf-head .mf-peer-link,
  .mf-card.mf-card-reel .mf-head .mf-peer-link:hover{
    color:#fff;
  }
  .mf-card.mf-card-reel .mf-head .mf-menu-btn{
    color:#fff;
    background:rgba(0,0,0,.38);
    border-radius:999px;
  }
  .mf-card.mf-card-reel .mf-head .mf-menu-btn:active{ background:rgba(0,0,0,.48); }
  .mf-card.mf-card-reel .mf-head .mf-friend-btn{ display:none; }
  .mf-card.mf-card-reel .mf-media{
    position:relative;
    width:100%;
    aspect-ratio:var(--mf-reel-ar-w) / var(--mf-reel-ar-h);
    min-height:min(820px, max(420px, var(--mf-reel-max-height)));
    background:transparent;
    border-radius:22px;
    overflow:hidden;
  }
  .mf-card.mf-card-reel .mf-media video{
    width:100%;
    height:100%;
    aspect-ratio:auto;
    object-fit:cover;
    max-height:none;
    background:transparent;
  }
  .mf-card.mf-card-reel .mf-reel-title{
    position:absolute; left:14px; right:64px; top:70px; z-index:3;
    color:#fff; font-weight:900; font-size:16px; line-height:1.3;
    text-shadow:0 2px 10px rgba(0,0,0,.55);
  }
  .mf-card.mf-card-reel .mf-reel-body{
    position:absolute; left:14px; right:64px; bottom:14px; z-index:3;
    color:#fff; font-size:14px; line-height:1.5; word-break:break-word;
    text-shadow:0 2px 10px rgba(0,0,0,.55);
  }
  .mf-card.mf-card-reel .mf-reel-body .mf-readmore{ color:#fff; text-decoration:underline; }
  .mf-card.mf-card-reel .mf-reel-rail{
    position:absolute; right:10px; bottom:14px; z-index:3;
    display:flex; flex-direction:column; gap:10px; align-items:center;
  }
  .mf-card.mf-card-reel .mf-media::after{
    content:""; position:absolute; inset:0;
    background:linear-gradient(to top, rgba(0,0,0,.55), rgba(0,0,0,.08) 35%, rgba(0,0,0,.18));
    pointer-events:none;
  }
  @media (max-width:360px){
    .mf-card.mf-card-reel{
      --mf-reel-side-gap: 20px;
      --mf-reel-top-gap: 20px;
      --mf-reel-bottom-gap: 92px;
      width:100%;
      max-width:100%;
      border-radius:0;
    }
    .mf-card.mf-card-reel .mf-media{
      min-height:auto;
      border-radius:0;
    }
    .mf-card.mf-card-reel .mf-media video{
      object-fit:contain;
      object-position:center center;
    }
  }
  @media (min-width:361px) and (max-width:430px){
    .mf-card.mf-card-reel{
      --mf-reel-side-gap: 20px;
      --mf-reel-top-gap: 20px;
      --mf-reel-bottom-gap: 92px;
      --mf-reel-frame-height: min(760px, max(500px, var(--mf-reel-max-height)));
      width:100%;
      max-width:100%;
      border-radius:0;
    }
    .mf-card.mf-card-reel .mf-media{
      min-height:auto;
      border-radius:0;
    }
    .mf-card.mf-card-reel .mf-media video{
      object-fit:contain;
      object-position:center center;
    }
  }
  @media (min-width:431px) and (max-width:575.98px){
    .mf-card.mf-card-reel{
      --mf-reel-side-gap: 22px;
      --mf-reel-top-gap: 20px;
      --mf-reel-bottom-gap: 96px;
      --mf-reel-frame-height: min(780px, max(520px, var(--mf-reel-max-height)));
      width:min(100%, var(--mf-reel-frame-width));
      max-width:min(100%, var(--mf-reel-frame-width));
      border-radius:18px;
    }
    .mf-card.mf-card-reel .mf-media{
      min-height:min(780px, max(520px, var(--mf-reel-max-height)));
      border-radius:18px;
    }
    .mf-card.mf-card-reel .mf-media video{
      object-fit:contain;
      object-position:center center;
    }
  }
  @media (min-width:576px) and (max-width:767.98px){
    .mf-card.mf-card-reel{
      --mf-reel-side-gap: 24px;
      --mf-reel-top-gap: 24px;
      --mf-reel-bottom-gap: 96px;
      --mf-reel-frame-height: min(760px, max(500px, var(--mf-reel-max-height)));
    }
    .mf-card.mf-card-reel .mf-media video{
      object-fit:contain;
      object-position:center center;
    }
  }
  @media (min-width:768px) and (max-width:834px){
    .mf-card.mf-card-reel{
      --mf-reel-side-gap: 28px;
      --mf-reel-top-gap: 24px;
      --mf-reel-bottom-gap: 104px;
      --mf-reel-frame-height: min(780px, max(560px, var(--mf-reel-max-height)));
    }
    .mf-card.mf-card-reel .mf-media video{
      object-fit:contain;
      object-position:center center;
    }
  }
  @media (min-width:835px) and (max-width:1024px){
    .mf-card.mf-card-reel{
      --mf-reel-side-gap: 32px;
      --mf-reel-top-gap: 28px;
      --mf-reel-bottom-gap: 112px;
      --mf-reel-frame-height: min(820px, max(580px, var(--mf-reel-max-height)));
    }
    .mf-card.mf-card-reel .mf-media video{
      object-fit:contain;
      object-position:center center;
    }
  }
  @media (min-width:1025px) and (max-width:1366px){
    .mf-card.mf-card-reel{
      --mf-reel-side-gap: 40px;
      --mf-reel-top-gap: 28px;
      --mf-reel-bottom-gap: 112px;
      --mf-reel-frame-height: min(860px, max(600px, var(--mf-reel-max-height)));
    }
  }
  @media (min-width:1367px){
    .mf-card.mf-card-reel{
      --mf-reel-side-gap: 48px;
      --mf-reel-top-gap: 32px;
      --mf-reel-bottom-gap: 116px;
      --mf-reel-frame-height: min(920px, max(620px, var(--mf-reel-max-height)));
    }
  }
  @media (max-height: 780px){
    .mf-card.mf-card-reel{
      --mf-reel-bottom-gap: 88px;
      --mf-reel-frame-height: min(720px, max(400px, var(--mf-reel-max-height)));
    }
  }
  @media (min-width:768px) and (max-height:900px){
    .mf-card.mf-card-reel{
      --mf-reel-frame-height: min(760px, max(520px, var(--mf-reel-max-height)));
    }
  }
  @media (min-width:1025px) and (max-height:860px){
    .mf-card.mf-card-reel{
      --mf-reel-frame-height: min(760px, max(520px, var(--mf-reel-max-height)));
    }
  }

  .mf-file{
    padding: 14px 12px;
    display:flex; align-items:center; gap:12px;
  }
  .mf-file .mf-file-ic{
    width:44px; height:44px; border-radius:12px;
    display:flex; align-items:center; justify-content:center;
    background: rgba(8,97,188,.10);
    color:#0861bc;
    font-size:20px;
    flex:0 0 auto;
  }
  .mf-file .mf-file-main{ min-width:0; }
  .mf-file a{ font-weight:800; text-decoration:none; }
  .mf-file .mf-file-sub{ font-size:12px; color:rgba(0,0,0,.55); margin-top:2px; }

  .mf-body{
    padding: 12px 12px 6px;
    font-size:14px;
    line-height:1.7;
    word-break:break-word;
    text-align:left;
  }
  .mf-body .mf-body-formatted{
    text-align:left;
  }
  .mf-body .post-card-paragraph{
    margin:0 0 12px;
    text-align:left;
    white-space:normal;
    word-break:break-word;
    display:block;
  }
  .mf-body .post-card-paragraph:last-child{
    margin-bottom:0;
  }
  .mf-body .mf-body-formatted.is-clamped{
    max-height:14em;
    overflow:hidden;
  }
  .mf-body .mf-readmore{
    font-weight:900;
    margin-left:6px;
    text-decoration:none;
    white-space:nowrap;
  }

  .mf-actions{
    padding: 12px 22px 18px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:10px;
    border-top:0;
  }
  .mf-actions .mf-left{
    display:flex; gap:20px; align-items:center;
  }
  .mf-actions .mf-right{
    display:flex; align-items:center; margin-left:auto;
  }
  .mf-act{
    border:0;background:transparent;
    display:flex;align-items:center;gap:6px;
    padding:0; cursor:pointer;
    color:#101828;
  }
  .mf-act i{ font-size:20px; }
  .mf-act .mf-num{ font-size:14px; font-weight:800; color:#101828; }
  .mf-act.mf-save .mf-num,
  .mf-act.mf-share .mf-num{ display:none; }

  /* Use the same exact active colors you already approved */
  .mf-act.is-love i{ color:var(--msb-love-color, #7c3aed) !important; }
  .mf-act.is-like i{ color:#1877F2 !important; }
  .mf-act.is-save i{ color:#f5c518 !important; }
  .mf-act.is-share i{ color:#555555 !important; }

  .mf-more-badge{ display:none; }
}

@media (min-width:1025px){
  .mobile-only{ display:block !important; }
  .desktop-only{ display:none !important; }

  .mf-feed{
    max-width: 980px;
    margin: 0 auto 0;
    padding: 0 20px 88px;
  }

  .mf-card{
    border-radius: 0;
    border: 0;
    border-bottom: 1px solid var(--feed-post-divider, rgba(15,23,42,.12));
    box-shadow:none;
    margin: 0 auto;
    overflow: hidden;
    background: var(--feed-surface, #fff);
    max-width: 100%;
  }

  body .mf-feed .mf-card:has(.mf-head--on-media){
    padding:8px 40px!important;
    box-sizing:border-box!important;
    overflow: visible !important;
  }

  .mf-head:not(.mf-head--on-media){
    padding: 18px 22px 14px;
    gap: 14px;
    align-items: center;
  }

  .mf-avatar{
    width: 56px;
    height: 56px;
    flex: 0 0 56px;
  }

  .mf-meta{
    min-width: 0;
    margin-left: -10px;
  }

  .mf-peer-link{
    gap: 8px;
    align-items: flex-start;
  }

  .mf-name{
    font-size: 13px;
    font-weight: 700;
    line-height: 1.2;
    margin: 0;
    color: #111827;
    min-width: 0;
    flex: 1 1 auto;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  .mf-time{
    font-size: 12px;
    color: #667085;
    margin: 0;
    flex: 0 0 auto;
    white-space: nowrap;
  }

  .mf-title{
    padding: 2px 22px 14px;
    font-size: 24px;
    line-height: 1.25;
    font-weight: 900;
    color: #101828;
  }

  .mf-body,
  .mf-video-body{
    padding: 16px 22px 20px;
    font-size: 15px;
    line-height: 1.75;
    color: #344054;
    text-align:left;
  }

  .mf-media{
    background: transparent;
  }

  .mf-card.is-single-video-post .mf-media,
  .mf-card.is-single-image-post .mf-media,
  .mf-card.is-single-video-post .media-stage,
  .mf-card.is-single-image-post .media-stage,
  .mf-card.is-single-video-post .media-stage video,
  .mf-card.is-single-image-post .media-stage img{
    background:transparent !important;
  }

  .mf-card.is-single-video-post,
  .mf-card.is-single-image-post{
    width:min(100%, var(--post-media-card-width, 100%));
    max-width:100%;
    margin-inline:auto;
  }

  .mf-card.is-single-video-post:not(.mf-video-ready),
  .mf-card.is-single-video-post.mf-video-error,
  .mf-card.is-single-image-post:not(.mf-image-ready),
  .mf-card.is-single-image-post.mf-image-error{
    display:none !important;
  }

  .mf-card.is-single-video-post .media-stage.standard-video-stage:not(.mf-media-sized){
    display:none !important;
  }

  .mf-card.is-single-video-post .media-stage.standard-video-stage > video{
    width:100%;
    height:auto;
    max-height:min(78vh, 960px);
    display:block;
    object-fit:contain;
    background:transparent;
  }

  .mf-media img,
  .mf-media video{
    display: block;
    width: 100%;
  }

  @media (max-width:767.98px){
    .mf-card.mf-card-phone-shot .mf-media:not(.standard-video-stage):not(.standard-image-stage),
    .mf-card .mf-media.media-stage.phone-shot:not(.standard-video-stage):not(.standard-image-stage){
      width:min(calc(100% - 44px),430px);
      max-width:100%;
      margin-inline:auto;
      border-radius:28px;
      box-shadow:none;
      max-height:none;
      overflow:hidden;
      background:transparent;
    }
    .mf-card.mf-card-phone-shot,
    .mf-card.is-single-video-post:has(.media-stage.phone-shot),
    .mf-card.is-single-image-post:has(.media-stage.phone-shot){
      width:auto;
      max-width:100%;
      margin-inline:auto;
    }
  }
  @media (min-width:768px){
    .mf-card.mf-card-phone-shot .mf-media.standard-video-stage,
    .mf-card .mf-media.media-stage.phone-shot.standard-video-stage,
    .mf-card.mf-card-phone-shot .mf-media.standard-image-stage,
    .mf-card .mf-media.media-stage.phone-shot.standard-image-stage{
      width:100%;
      max-width:100%;
      margin-inline:auto;
      border:0;
      border-radius:18px;
      box-shadow:none;
      max-height:none;
      overflow:visible;
      background:transparent;
      aspect-ratio:auto;
    }
    .mf-card.mf-card-phone-shot .mf-media.standard-video-stage > video,
    .mf-card .mf-media.media-stage.phone-shot.standard-video-stage > video,
    .mf-card.mf-card-phone-shot .mf-media.standard-image-stage > img,
    .mf-card .mf-media.media-stage.phone-shot.standard-image-stage > img{
      width:100%;
      height:auto;
      max-height:min(78vh,960px);
      object-fit:contain;
      background:transparent;
    }
    .mf-card.mf-card-phone-shot.is-single-video-post,
    .mf-card.mf-card-phone-shot.is-single-image-post,
    .mf-card.mf-card-phone-shot:not(.is-multi-media-post){
      width:min(100%,var(--post-media-card-width,680px));
      max-width:100%;
      margin-inline:auto;
    }
  }

  .mf-card.mf-card-video{
    border-radius: 24px;
    border-color: rgba(255,255,255,.08);
    box-shadow: 0 24px 54px rgba(2,8,23,.28);
  }

  .mf-card.mf-card-video .mf-video-shell{
    margin: 18px 18px 0;
    border-radius: 20px;
  }

  .mf-card.mf-card-video .mf-video-meta{
    padding: 18px 22px 22px;
  }

  .mf-card.mf-card-video .mf-video-title{
    font-size: 20px;
    line-height: 1.3;
  }

  .mf-card.mf-card-reel{
    border-radius: 28px;
  }

  .mf-card.mf-card-reel .mf-media{
    /* border-radius: 28px; */
  }

  .mf-actions{
    padding: 14px 22px 20px;
    border-top: 0;
  }

  .mf-act{
    min-width: 28px;
  }

  .mf-menu-btn{
    /* width: 40px; */
    height: 40px;
    border-radius: 999px;
    color: #111827;
  }

  .mf-menu-btn:hover{
    background: rgba(15,23,42,.06);
  }

  .mf-menu{
    border-radius: 16px;
    box-shadow: 0 20px 46px rgba(15,23,42,.16);
  }
}
</style>

<style>
body{
  --feed-page-bg:#f5f7fb;
  --feed-surface:#f5f7fb;
  --feed-surface-alt:#eef3fb;
  --feed-surface-strong:#eef3fb;
  --feed-post-divider:rgba(15,23,42,.12);
  --feed-post-column-border:rgba(15,23,42,.12);
  --feed-border:rgba(15,23,42,.12);
  --feed-border-strong:rgba(15,23,42,.16);
  --feed-text:#132033;
  --feed-muted:#5f6c7c;
  --feed-soft-text:#7a8797;
  --feed-topbar-bg:rgba(255,255,255,.88);
  --feed-topbar-text:#112033;
  --feed-control-bg:#ffffff;
  --feed-control-soft:#eef3fb;
  --feed-control-border:rgba(15,23,42,.14);
  --feed-control-placeholder:#667085;
  --feed-accent:#0d61bc;
  --feed-accent-soft:rgba(13,97,188,.10);
  --feed-accent-strong:#0b4a86;
  background:#f5f7fb;
  background-image:none;
  color:var(--feed-text);
}
html[data-theme="light"]:not([data-msb-appearance]) body,
html[data-theme="light"]:not([data-msb-appearance]) body.feed-insta-ui,
html[data-theme="light"]:not([data-msb-appearance]) body.feed-page{
  --feed-surface:var(--msb-palette-bg, #f5f7fb);
  --feed-surface-alt:#eef3fb;
  --feed-surface-strong:#eef3fb;
  background:var(--msb-palette-bg, #f5f7fb) !important;
  background-image:none !important;
  color:var(--msb-palette-text, var(--feed-text)) !important;
}
html[data-msb-appearance] body,
html[data-msb-appearance] body.feed-insta-ui,
html[data-msb-appearance] body.feed-page,
html[data-msb-appearance][data-theme="dark"] body,
html[data-msb-appearance][data-theme="dark"] body.feed-insta-ui,
html[data-msb-appearance][data-theme="dark"] body.feed-page{
  --feed-page-bg:var(--msb-palette-bg);
  --feed-topbar-bg:var(--msb-palette-bg);
  --feed-control-bg:var(--msb-palette-bg);
  --feed-control-soft:var(--msb-palette-bg);
  --feed-surface:var(--msb-palette-bg);
  --feed-surface-alt:var(--msb-palette-surface-2, var(--msb-palette-bg));
  --feed-surface-strong:var(--msb-palette-surface, var(--msb-palette-bg));
  --feed-accent:var(--msb-palette-action);
  --feed-accent-soft:var(--msb-palette-action-soft);
  --feed-accent-strong:var(--msb-palette-action-strong);
  background:var(--msb-palette-bg) !important;
  background-image:none !important;
}
html[data-theme="dark"] body{
  --feed-surface:#171d24;
  --feed-surface-alt:#1d2530;
  --feed-surface-strong:#111821;
  --feed-border:rgba(255,255,255,.08);
  --feed-border-strong:rgba(255,255,255,.14);
  --feed-text:#eef4ff;
  --feed-muted:#9ba8b8;
  --feed-soft-text:#c2cbd7;
  --feed-topbar-bg:rgba(23,29,36,.88);
  --feed-topbar-text:#f4f7fb;
  --feed-control-bg:#10161e;
  --feed-control-soft:#1a222c;
  --feed-control-border:rgba(255,255,255,.12);
  --feed-control-placeholder:#8f9baa;
  --feed-accent:#7cb2ff;
  --feed-accent-soft:rgba(124,178,255,.16);
  --feed-accent-strong:#b9d7ff;
  --feed-post-divider:rgba(255,255,255,.14);
  --feed-post-column-border:rgba(255,255,255,.14);
  background:#171d24 !important;
  background-image:none !important;
}
html.dark-auto:not([data-msb-appearance]) body,
html.dark-auto:not([data-msb-appearance]) body.feed-page,
html.dark-auto:not([data-msb-appearance]) body.feed-insta-ui{
  --feed-post-divider:rgba(177, 188, 206, 0.22);
  --feed-post-column-border:rgba(177, 188, 206, 0.22);
}
html.dark-auto:not([data-msb-appearance]) body.feed-page.feed-insta-ui .sh-pagebody,
html[data-theme="dark"]:not([data-msb-appearance]) body.feed-page.feed-insta-ui .sh-pagebody,
html.dark-auto:not([data-msb-appearance]) body.feed-page.feed-insta-ui .ig-feed-header,
html[data-theme="dark"]:not([data-msb-appearance]) body.feed-page.feed-insta-ui .ig-feed-header,
html.dark-auto:not([data-msb-appearance]) body.feed-page.feed-insta-ui .feed-top-search,
html[data-theme="dark"]:not([data-msb-appearance]) body.feed-page.feed-insta-ui .feed-top-search{
  background:var(--feed-surface, #171d24) !important;
  background-image:none !important;
}
html.dark-auto:not([data-msb-appearance]) body.feed-page.feed-insta-ui .ig-stories-brand,
html[data-theme="dark"]:not([data-msb-appearance]) body.feed-page.feed-insta-ui .ig-stories-brand,
html.dark-auto:not([data-msb-appearance]) body.feed-page.feed-insta-ui .ig-top-act,
html[data-theme="dark"]:not([data-msb-appearance]) body.feed-page.feed-insta-ui .ig-top-act{
  color:var(--feed-text, #eef4ff);
}
html.dark-auto:not([data-msb-appearance]) body.feed-page.feed-insta-ui .ig-story-empty-icon,
html[data-theme="dark"]:not([data-msb-appearance]) body.feed-page.feed-insta-ui .ig-story-empty-icon{
  background:var(--feed-control-soft, #1a222c);
  border-color:var(--feed-border, rgba(255,255,255,.08));
  color:var(--feed-muted, #9ba8b8);
}
.sh-mainpanel,
.sh-pagebody,
.sh-pagetitle,
.yt-pagebar,
.ig-post-card,
.ig-post-footer-card,
.rightSidebarCard,
.card.mg-t-15,
.mf-card,
.rm-modal,
.c-modal,
.vw-modal,
.pv-modal,
.pv-right{
  color:var(--feed-text);
}
.sh-mainpanel,
.sh-pagebody{
  background:transparent;
}
.sh-pagetitle,
.yt-pagebar{
  background:var(--feed-topbar-bg);
  border-bottom:1px solid var(--feed-border);
  box-shadow:0 10px 28px rgba(15,23,42,.08);
  backdrop-filter:blur(14px);
}
.yt-icon-btn,
.yt-brand,
.yt-signin{
  color:var(--feed-topbar-text);
}
.yt-signin{
  border-color:var(--feed-control-border);
  background:var(--feed-control-soft);
}
.search-input{
  background:var(--feed-control-bg);
  color:var(--feed-topbar-text);
  border-color:var(--feed-control-border);
}
.search-input::placeholder{
  color:var(--feed-control-placeholder);
}
.search-btn,
.yt-mic-btn{
  background:var(--feed-control-soft);
  color:var(--feed-topbar-text);
  border-color:var(--feed-control-border);
}
.card.bd-primary,
.ig-post-card,
.ig-post-footer-card,
.rightSidebarCard,
.card.mg-t-15,
.mf-card:not(.mf-card-video):not(.mf-card-reel){
  background:var(--feed-surface);
  border-color:var(--feed-border) !important;
  box-shadow:0 16px 36px rgba(15,23,42,.08);
}
.ig-post-card .card-header,
.rightSidebarCard .card-header{
  background:linear-gradient(180deg, var(--feed-surface-alt) 0%, var(--feed-surface) 100%);
  border-bottom:1px solid var(--feed-border);
}
.rightSidebarBody{
  background:linear-gradient(180deg, var(--feed-surface-alt) 0%, var(--feed-surface) 100%);
}
.sidebar-tools{
  border-bottom-color:var(--feed-border);
}
.sidebar-pill{
  background:var(--feed-control-bg);
  color:var(--feed-text);
  border-color:var(--feed-border);
  box-shadow:none;
}
.pl-item{
  border-color:transparent;
}
.pl-item:hover{
  background:var(--feed-surface-alt);
}
.pl-item.active{
  background:var(--feed-accent-soft);
  box-shadow:inset 0 0 0 1px rgba(13,97,188,.10);
}
html[data-theme="dark"] .pl-item.active{
  box-shadow:inset 0 0 0 1px rgba(124,178,255,.18);
}
.pl-chip{
  background:var(--feed-surface-strong);
  color:var(--feed-muted);
  border-color:var(--feed-border);
}
.pl-title,
.card-title,
.ig-title,
.ig-cap-title,
#pvAuthor,
#pvTitle,
.rm-author,
.c-author,
.mf-name,
.mf-title,
.rm-title,
.c-title,
.vw-title,
.pv-cap,
.pv-com .t{
  color:var(--feed-text);
}
.pl-desc,
.ig-desc,
#pvMeta,
.ig-cap-meta,
.mf-time,
.mf-file .mf-file-sub,
.rm-sub,
.c-sub,
.vw-stat-k,
.vw-user,
.vw-when,
.card.mg-t-15 .text-muted,
.sh-mainpanel .text-muted{
  color:var(--feed-muted) !important;
}
.pl-readmore,
.ig-cap-readmore,
.mf-body .mf-readmore,
.mf-file a,
.pv-readmore,
.pv-com .m .link{
  color:var(--feed-accent);
}
.ig-body,
.ig-cap-text,
.mf-body,
.rm-body,
.c-list .cmt .txt,
.pv-cap{
  color:var(--feed-text);
}
.ig-media-caption{
  background:var(--feed-surface-strong);
  color:var(--feed-text);
}
.ig-underbar,
.ig-layout.is-media-only .ig-topbar.pv-top-detached,
.ig-layout.is-full-media .ig-topbar.pv-top-detached{
  border-bottom-color:var(--feed-border);
  background:var(--feed-surface);
}
.ig-underbar .ig-circle{
  background:var(--feed-surface-alt);
  border:1px solid var(--feed-border);
  box-shadow:none;
}
.ig-underbar .ig-count,
.mf-act .mf-num{
  color:var(--feed-soft-text);
}
.ig-post-footer-card .ig-post-footer{
  background:var(--feed-surface-alt);
  box-shadow:none;
}
.ig-post-progress{
  background:rgba(15,23,42,.08);
}
html[data-theme="dark"] .ig-post-progress{
  background:rgba(255,255,255,.10);
}
.ig-post-footer-act,
.ig-post-footer-link{
  color:var(--feed-text);
}
.ig-post-footer-act:hover,
.ig-post-footer-link:hover{
  color:var(--feed-accent-strong);
}
#refreshBtn,
#btnFindMe,
#btnFindAuthor,
#btnOpenMedia,
#btnFullscreenMedia{
  color:var(--feed-text);
}
#filterSel,
#searchBox,
.rightSidebarBody .form-control,
.c-footer .form-control,
.pv-input input,
.pv-input textarea{
  background:var(--feed-control-bg);
  color:var(--feed-text);
  border-color:var(--feed-control-border);
}
#searchBox::placeholder,
.c-footer .form-control::placeholder,
.pv-input input::placeholder,
.pv-input textarea::placeholder{
  color:var(--feed-control-placeholder);
}
.mf-card{
  border-color:var(--feed-border);
}
.mf-menu{
  background:var(--feed-surface);
  border-color:var(--feed-border);
}
.mf-menu a,
.mf-menu button,
.mf-file .mf-file-main,
.mf-body{
  color:var(--feed-text);
}
.mf-menu-btn{
  color:var(--feed-muted);
}
.mf-menu a:hover,
.mf-menu button:hover{
  background:var(--feed-accent-soft);
}
.mf-media{
  background:transparent;
}
.mf-card.is-single-video-post .mf-media,
.mf-card.is-single-image-post .mf-media,
.mf-card.is-single-video-post .media-stage,
.mf-card.is-single-image-post .media-stage,
.mf-card.is-single-video-post video,
.mf-card.is-single-image-post img{
  background:transparent !important;
}
.mf-file .mf-file-ic{
  background:var(--feed-accent-soft);
  color:var(--feed-accent);
}
.mf-actions{
  border-top-color:var(--feed-border);
}
.rm-modal,
.c-modal,
.vw-modal,
.pv-modal,
.pv-right{
  background:var(--feed-surface);
}
.rm-section,
.vw-stat,
.c-list .cmt,
.c-list .cmt.reply{
  background:var(--feed-surface-alt);
  border-color:var(--feed-border);
}
.c-bodywrap,
.c-footer,
.rm-footer,
.vw-body,
.pv-actions,
.pv-input{
  background:var(--feed-surface);
  border-color:var(--feed-border);
}
.rm-x,
.c-x,
.vw-close,
.pv-act{
  background:var(--feed-surface-alt);
  border-color:var(--feed-border);
  color:var(--feed-text);
}
.vw-stat-v{
  color:var(--feed-text);
}

/* Keep the shared live modal fully hidden on feed until JS explicitly opens it. */
body.feed-page #globalLiveModal:not(.is-open){
  display:none !important;
  visibility:hidden !important;
  opacity:0 !important;
  pointer-events:none !important;
}

body.feed-page #globalLiveModal:not(.is-open) .global-live-modal-dialog,
body.feed-page #globalLiveModal:not(.is-open) iframe,
body.feed-page #globalLiveModal:not(.is-open) video,
body.feed-page #globalLiveModal:not(.is-open) img,
body.feed-page #globalLiveModal:not(.is-open) aside{
  display:none !important;
}

/* Friends Feed uses the mfFeed card list. Keep the old selected-post viewer
   hidden so it cannot flash a media-only rectangle during Ajax refresh. */
body.feed-page.feed-insta-ui #feedPostScrollCol,
body.feed-page.feed-insta-ui #feedPostScrollCol .ig-post-shell,
body.feed-page.feed-insta-ui #feedPostScrollCol .ig-post-card{
  display:none !important;
  visibility:hidden !important;
  opacity:0 !important;
  pointer-events:none !important;
}
</style>
<style>
/* [IG_INSTA_FORCE_OVERRIDE] — must win over legacy book/side-by-side rules */
body.feed-insta-ui .desktop-only .ig-insta-card.ig-post-card{
  background:#fff !important;
  color:#262626 !important;
  border:1px solid #dbdbdb !important;
  border-radius:4px !important;
  box-shadow:none !important;
}
body.feed-insta-ui .ig-insta-card .ig-viewer-body,
body.feed-insta-ui .ig-insta-card .ig-viewer-body.mode-book,
body.feed-insta-ui .ig-insta-card .ig-viewer-body.mode-image-overlay,
body.feed-insta-ui .ig-insta-card .ig-viewer-body.mode-insta-feed{
  height:auto !important;
  max-height:none !important;
}
body.feed-insta-ui .ig-insta-card .ig-layout,
body.feed-insta-ui .ig-insta-card .ig-layout.is-media-only,
body.feed-insta-ui .ig-insta-card .ig-layout.is-full-media{
  display:flex !important;
  flex-direction:column !important;
  height:auto !important;
}
body.feed-insta-ui .ig-insta-card .ig-insta-media-stage,
body.feed-insta-ui .ig-insta-card .ig-media,
body.feed-insta-ui .ig-insta-card .ig-layout.is-media-only .ig-media,
body.feed-insta-ui .ig-insta-card .ig-layout.is-full-media .ig-media{
  flex:0 0 auto !important;
  width:100% !important;
  height:auto !important;
  min-height:0 !important;
  aspect-ratio:4/5 !important;
  max-height:none !important;
}
body.feed-insta-ui .ig-insta-card .ig-details{
  width:100% !important;
  flex:0 0 auto !important;
  height:auto !important;
}
body.feed-insta-ui .ig-insta-card .ig-insta-header,
body.feed-insta-ui .ig-insta-card .ig-insta-header .ig-insta-username a{
  color:#262626 !important;
}
body.feed-insta-ui .ig-insta-card .card-body.media-only,
body.feed-insta-ui .ig-insta-card .card-body.full-media{
  height:auto !important;
}
</style>

<style>
/* [IG_STORIES_BAR_UI] — lead left; stories centered over post card (visual only) */
.ig-feed-header{
  position:relative;
  display:flex;
  justify-content:center;
  align-items:flex-start;
  width:100%;
  margin:0;
  padding:16px 16px 14px;
  background:var(--feed-surface, var(--msb-palette-bg, var(--feed-page-bg, var(--feed-topbar-bg, #fff))));
  border-bottom:1px solid var(--feed-post-divider, var(--feed-border-strong, rgba(177, 188, 206, 0.22)));
  box-sizing:border-box;
}
.ig-feed-top-lead{
  position:absolute;
  left:16px;
  top:50%;
  transform:translateY(-50%);
  display:flex;
  align-items:center;
  gap:10px;
  z-index:2;
  padding:0;
  box-sizing:border-box;
  max-width:min(72vw, 520px);
}
.ig-feed-top-actions{
  position:absolute;
  right:16px;
  top:50%;
  transform:translateY(-50%);
  display:flex;
  align-items:center;
  gap:10px;
  z-index:2;
  padding:0;
  box-sizing:border-box;
  max-width:min(52vw, 520px);
}
.ig-feed-account-badge{
  display:inline-flex;
  align-items:center;
  max-width:min(22vw, 160px);
  padding:0 10px;
  min-height:32px;
  border-radius:999px;
  background:var(--feed-control-soft, #eef2f7);
  border:1px solid var(--feed-control-border, #dbe3ee);
  color:var(--feed-text, #1e293b);
  font-size:12px;
  font-weight:700;
  letter-spacing:.02em;
  white-space:nowrap;
  overflow:hidden;
  text-overflow:ellipsis;
  flex-shrink:1;
}
.ig-stories-wrap{
  width:100%;
  max-width:614px;
  margin:0 auto;
  padding:0;
  box-sizing:border-box;
}
.ig-feed-center{
  width:100%;
  max-width:470px;
  margin:0 auto;
}
.ig-stories-bar{
  display:flex !important;
  align-items:flex-start;
  gap:6px;
  width:100%;
  margin:0 auto;
  padding:0;
  background:transparent;
  border-bottom:0;
  visibility:visible !important;
  opacity:1 !important;
  box-sizing:border-box;
}
.ig-stories-menu-btn{
  display:inline-flex;
  align-items:center;
  justify-content:center;
  width:40px;
  height:40px;
  padding:0;
  border:0;
  border-radius:8px;
  background:transparent;
  color:#262626;
  font-size:18px;
  line-height:1;
  cursor:pointer;
  flex-shrink:0;
}
.ig-stories-menu-btn .fa,
.ig-stories-menu-btn .icon{
  font-size:18px;
  line-height:1;
}
.ig-stories-menu-btn:hover{background:#f5f5f5;}
.ig-stories-brand{
  display:inline-flex;
  align-items:center;
  height:40px;
  font-size:18px;
  font-weight:800;
  color:var(--feed-text, #262626);
  text-decoration:none;
  letter-spacing:-.02em;
  line-height:1;
  white-space:nowrap;
  flex-shrink:0;
}
.ig-stories-brand:hover{color:var(--feed-text, #000);text-decoration:none;}
.ig-top-act{
  display:inline-flex;
  align-items:center;
  justify-content:center;
  padding:0;
  border:0;
  background:transparent;
  color:var(--feed-text, #1e293b);
  cursor:pointer;
  flex-shrink:0;
  line-height:1;
  text-decoration:none;
  box-sizing:border-box;
  transition:background .15s ease,opacity .15s ease;
}
.ig-top-act:hover{opacity:.85;}
.ig-top-mic,
.ig-top-shop{
  width:44px;
  height:44px;
  border-radius:50%;
  background:var(--feed-control-soft, #eef2f7);
  font-size:18px;
}
.ig-top-mic:hover,
.ig-top-shop:hover{background:var(--feed-surface-alt, #e2e8f0);opacity:1;}
.ig-top-live{
  gap:8px;
  min-height:44px;
  padding:0 18px;
  border-radius:999px;
  background:var(--feed-control-soft, #eef2f7);
  border:1px solid var(--feed-control-border, #dbe3ee);
  font-size:15px;
  font-weight:800;
  letter-spacing:-.01em;
  color:var(--feed-text, #1e293b);
}
.ig-top-live i{font-size:16px;}
.ig-top-live:hover{background:var(--feed-surface-alt, #e2e8f0);opacity:1;color:var(--feed-text, #1e293b);}
.ig-top-more{
  width:36px;
  height:44px;
  font-size:20px;
  color:#1e293b;
}
.ig-top-more:hover{background:#f5f5f5;border-radius:8px;opacity:1;}
.ig-stories-track{
  display:flex;
  align-items:flex-start;
  gap:18px;
  flex:1;
  min-width:0;
  overflow-x:auto;
  overflow-y:hidden;
  scroll-behavior:smooth;
  scrollbar-width:none;
  -ms-overflow-style:none;
  padding:0 2px 2px;
}
.ig-stories-track::-webkit-scrollbar{display:none;}
.ig-story-item{
  flex:0 0 auto;
  width:72px;
  text-align:center;
  cursor:pointer;
  user-select:none;
  border:0;
  padding:0;
  background:transparent;
  font:inherit;
  color:inherit;
}
.ig-story-ring{
  width:66px;
  height:66px;
  margin:0 auto 6px;
  padding:2px;
  border-radius:50%;
  background:linear-gradient(45deg,#f58529,#dd2a7b,#8134af,#515bd4);
  box-sizing:border-box;
}
.ig-story-ring img,
.ig-story-thumb{
  display:block;
  width:100%;
  height:100%;
  border-radius:50%;
  border:2px solid #fff;
  object-fit:cover;
  background:#efefef;
  box-sizing:border-box;
}
.ig-story-name{
  display:block;
  max-width:72px;
  font-size:12px;
  line-height:1.2;
  color:#262626;
  white-space:nowrap;
  overflow:hidden;
  text-overflow:ellipsis;
}
.ig-story-create{
  text-decoration:none;
  color:inherit;
}
.ig-story-create .ig-story-ring-create{
  background:#fafafa;
  border:2px solid #dbdbdb;
  padding:0;
  display:flex;
  align-items:center;
  justify-content:center;
  box-sizing:border-box;
}
.ig-story-create .ig-story-ring-create i{
  font-size:26px;
  color:#262626;
  line-height:1;
}
.ig-story-create:hover .ig-story-ring-create,
.ig-story-create:focus-visible .ig-story-ring-create{
  background:#f0f0f0;
  border-color:#c7c7c7;
}
.ig-story-create:focus-visible{
  outline:2px solid #0095f6;
  outline-offset:2px;
  border-radius:8px;
}
.ig-stories-track.is-empty{
  justify-content:center;
  align-items:flex-start;
  min-height:74px;
}
.ig-stories-track.has-create.is-empty{
  justify-content:flex-start;
}
.ig-story-empty{
  width:auto;
  min-width:72px;
  max-width:118px;
  cursor:default;
  pointer-events:none;
}
.ig-story-ring-empty{
  background:var(--feed-surface-alt, #e4e7ec) !important;
}
.ig-story-empty-icon{
  display:flex;
  align-items:center;
  justify-content:center;
  width:100%;
  height:100%;
  border-radius:50%;
  border:2px solid var(--feed-surface, #fff);
  background:var(--feed-control-soft, #f2f4f7);
  box-sizing:border-box;
  color:var(--feed-muted, #98a2b3);
  font-size:26px;
  line-height:1;
}
.ig-story-empty .ig-story-name{
  max-width:118px;
  white-space:normal;
  color:var(--feed-muted, #667085);
  font-weight:600;
  font-size:11px;
  line-height:1.25;
}
.ig-stories-bar.is-empty .ig-stories-next{
  display:none;
}
.ig-stories-next{
  flex:0 0 auto;
  width:24px;
  height:24px;
  margin-top:18px;
  padding:0;
  border:0;
  border-radius:50%;
  background:#fff;
  color:#262626;
  box-shadow:0 0 4px rgba(0,0,0,.12);
  cursor:pointer;
  display:flex;
  align-items:center;
  justify-content:center;
  font-size:12px;
  line-height:1;
}
.ig-stories-next:hover{background:#fafafa;}
html.dark-auto:not([data-msb-appearance]) .ig-stories-next,
html[data-theme="dark"]:not([data-msb-appearance]) .ig-stories-next{
  background:#252f3d !important;
  color:#e8edf5 !important;
  border:1px solid rgba(177,188,206,.42);
  box-shadow:none !important;
}
html.dark-auto:not([data-msb-appearance]) .ig-stories-next:hover,
html[data-theme="dark"]:not([data-msb-appearance]) .ig-stories-next:hover{
  background:#2f3a4a !important;
  color:#ffffff !important;
}
.ig-feed-center .ig-post-shell{
  margin-top:0;
}
.feed-top-search{
  width:100%;
  padding:12px 16px 8px;
  box-sizing:border-box;
  position:sticky;
  top:0;
  z-index:105;
  background:var(--msb-palette-bg, var(--feed-page-bg, var(--feed-topbar-bg, #f5f7fb)));
  flex:0 0 auto;
}
body.feed-insta-ui .feed-desktop-center > .feed-top-search,
body.feed-page.feed-insta-ui .feed-desktop-center > .feed-top-search{
  position:sticky;
  top:0;
  z-index:105;
  width:100%;
  margin:0;
}
.feed-top-search-form{
  width:100%;
  max-width:614px;
  margin:0 auto;
}
body.feed-insta-ui .feed-desktop-center > .feed-top-search .feed-top-search-form,
body.feed-page.feed-insta-ui .feed-desktop-center > .feed-top-search .feed-top-search-form{
  max-width:100%;
}
.feed-top-search-field{
  position:relative;
  width:100%;
}
.feed-top-search-input{
  width:100%;
  min-width:0;
  height:42px;
  border:1px solid var(--feed-control-border, rgba(15,23,42,.14));
  border-radius:999px;
  padding:0 44px 0 16px;
  font-size:14px;
  background:var(--feed-control-bg, #fff);
  color:var(--feed-topbar-text, #0d0d0d);
  outline:none;
  box-sizing:border-box;
}
.feed-top-search-input::placeholder{
  color:var(--feed-control-placeholder, #667085);
}
.feed-top-search-input:focus{
  border-color:var(--msb-palette-action, var(--feed-accent, #2563eb));
  box-shadow:0 0 0 3px var(--msb-palette-action-soft, var(--feed-accent-soft, rgba(37,99,235,.12)));
}
.feed-top-search-icon{
  position:absolute;
  right:6px;
  top:50%;
  transform:translateY(-50%);
  width:32px;
  height:32px;
  border:0;
  border-radius:50%;
  padding:0;
  display:inline-flex;
  align-items:center;
  justify-content:center;
  background:transparent;
  color:var(--msb-palette-action, var(--feed-accent, #2563eb));
  cursor:pointer;
  line-height:1;
}
.feed-top-search-icon i{
  font-size:15px;
  line-height:1;
}
.feed-top-search-icon:hover,
.feed-top-search-icon:focus{
  background:var(--msb-palette-action-soft, var(--feed-accent-soft, rgba(37,99,235,.12)));
  outline:none;
}

/* Full-width horizontal post dividers — spans feed column; card/media sizing unchanged */
body.feed-insta-ui .feed-desktop-center{
  border-left:1px solid var(--feed-post-column-border, var(--feed-border-strong, rgba(177, 188, 206, 0.22)));
  border-right:1px solid var(--feed-post-column-border, var(--feed-border-strong, rgba(177, 188, 206, 0.22)));
  box-sizing:border-box;
}
body.feed-insta-ui .feed-desktop-center .mf-feed{
  container-type:inline-size;
  padding-left:0 !important;
  padding-right:0 !important;
}
body.feed-insta-ui .feed-desktop-center .mf-feed .mf-card{
  position:relative !important;
  border-bottom:0 !important;
  overflow:visible !important;
}
body.feed-insta-ui .feed-desktop-center .mf-feed .mf-card::after{
  content:'';
  position:absolute;
  left:50%;
  bottom:0;
  transform:translateX(-50%);
  width:100cqw;
  border-bottom:1px solid var(--feed-post-divider, var(--feed-border-strong, rgba(177, 188, 206, 0.22)));
  pointer-events:none;
  z-index:1;
}
@media (min-width:1025px){
  body.feed-insta-ui .ig-feed-header{
    display:flex !important;
    padding-left:16px;
    padding-right:16px;
  }
  body.feed-insta-ui .ig-stories-wrap{
    display:block !important;
    max-width:614px;
    width:100%;
    margin:0 auto;
  }
  body.feed-insta-ui .ig-stories-bar{
    display:flex !important;
    width:100%;
  }
  body.feed-insta-ui .ig-feed-top-lead{
    left:16px;
  }
  body.feed-insta-ui .ig-feed-top-actions{
    right:16px;
  }
}
@media (min-width:768px) and (max-width:1024px){
  body.feed-insta-ui .ig-stories-wrap{
    max-width:470px;
  }
  body.feed-insta-ui .ig-feed-top-lead{
    left:16px;
  }
}
@media (max-width:767px){
  body.feed-insta-ui .ig-feed-header{
    flex-direction:column;
    align-items:stretch;
    padding:12px 10px 14px;
  }
  body.feed-insta-ui .ig-feed-top-lead{
    position:static;
    transform:none;
    margin-bottom:10px;
  }
  body.feed-insta-ui .ig-feed-top-actions{
    position:static;
    transform:none;
    justify-content:flex-end;
    margin-top:8px;
    padding:0 2px;
    max-width:none;
  }
  body.feed-insta-ui .ig-feed-account-badge{
    max-width:min(38vw, 140px);
    font-size:11px;
  }
  body.feed-insta-ui .ig-top-live{
    padding:0 14px;
    font-size:14px;
  }
  body.feed-insta-ui .ig-stories-wrap{
    max-width:100%;
  }
}

/* [FEED_LEFT_RAIL_UI] — desktop left nav panel beside icon rail (visual only) */
.feed-left-rail{display:none;}
@media (min-width:1025px){
  body.feed-insta-ui{
    --feed-left-nav-box-h:min(340px, calc(100vh - 280px));
  }
  body.feed-insta-ui .feed-left-rail{
    display:flex;
    flex-direction:column;
    position:fixed;
    left:calc(var(--feedRailW, 84px) + 40px);
    top:var(--feed-left-rail-top, 220px);
    width:236px;
    height:var(--feed-left-nav-box-h);
    max-height:var(--feed-left-nav-box-h);
    overflow:hidden;
    z-index:90;
    padding:4px 0 8px;
    box-sizing:border-box;
  }
  body.feed-insta-ui .feed-left-nav{
    display:flex;
    flex-direction:column;
    gap:2px;
    flex:1 1 auto;
    min-height:0;
    height:100%;
    max-height:100%;
    overflow-y:auto;
    overflow-x:hidden;
    padding:0 2px 0 0;
    -webkit-overflow-scrolling:touch;
    overscroll-behavior:contain;
    touch-action:pan-y;
    scrollbar-width:thin;
    scrollbar-color:rgba(0,0,0,.18) transparent;
  }
  body.feed-insta-ui .feed-left-nav::-webkit-scrollbar{width:5px;}
  body.feed-insta-ui .feed-left-nav::-webkit-scrollbar-thumb{
    background:rgba(0,0,0,.18);
    border-radius:999px;
  }
  body.feed-insta-ui .feed-left-rail-label{
    padding:0 12px 10px;
    font-size:11px;
    font-weight:700;
    letter-spacing:.14em;
    text-transform:uppercase;
    color:#94a3b8;
  }
  body.feed-insta-ui .feed-left-nav-item{
    display:flex;
    align-items:center;
    gap:12px;
    min-height:42px;
    padding:8px 12px;
    border-radius:10px;
    color:var(--msb-palette-text-on-nav, #0d0d0d);
    font-size:14px;
    font-weight:500;
    line-height:1.2;
    text-decoration:none;
    transition:background .15s ease,color .15s ease;
    box-sizing:border-box;
  }
  body.feed-insta-ui .feed-left-nav-item:hover,
  body.feed-insta-ui .feed-left-nav-item:focus{
    background:var(--msb-palette-nav-hover, #d0d8e4);
    color:var(--msb-palette-text-on-nav-hover, #0a0a0a);
    box-shadow:inset 0 0 0 1px rgba(15,23,42,.14);
    text-decoration:none;
    outline:none;
  }
  body.feed-insta-ui .feed-left-nav-item.is-active{
    background:var(--msb-palette-nav-active-bg, #bdc4cd);
    color:var(--msb-palette-nav-active-text, #787c87);
    font-weight:600;
  }
  body.feed-insta-ui .feed-left-nav-ic{
    flex:0 0 20px;
    width:20px;
    height:20px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    color:inherit;
  }
  body.feed-insta-ui .feed-left-nav-ic svg{
    display:block;
    width:18px;
    height:18px;
    stroke:currentColor;
    fill:none;
    stroke-width:1.75;
    stroke-linecap:round;
    stroke-linejoin:round;
  }
  body.feed-insta-ui .feed-left-nav-label{
    flex:1 1 auto;
    min-width:0;
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis;
  }
  body.feed-insta-ui .feed-left-nav-badge{
    flex:0 0 auto;
    margin-left:8px;
    padding:3px 8px;
    border-radius:999px;
    background:#f3f4f6;
    color:#6b7280;
    font-size:10px;
    font-weight:700;
    letter-spacing:.04em;
    line-height:1;
  }
  body.feed-insta-ui .feed-left-nav-section{
    padding:14px 12px 4px;
    font-size:11px;
    font-weight:700;
    letter-spacing:.12em;
    text-transform:uppercase;
    color:#94a3b8;
  }
  body.feed-insta-ui .feed-left-nav-item-company .feed-left-nav-label,
  body.feed-insta-ui .feed-left-nav-item-publisher .feed-left-nav-label{
    font-weight:600;
  }
  body.feed-insta-ui .feed-left-nav-item-under-public{
    /* margin-left:12px; */
    /* padding-left:20px; */
    min-height:38px;
    font-size:13px;
  }
}

/* [FEED_RIGHT_RAIL_UI] — desktop right sidebar nav (visual only) */
.feed-desktop-layout{
  display:block;
  width:100%;
}
.feed-right-rail{
  display:none;
}
@media (min-width:1025px){
  body.feed-insta-ui .feed-desktop-layout{
    display:block;
    width:100%;
    max-width:none;
    margin:0;
    padding:0;
    box-sizing:border-box;
  }
  body.feed-insta-ui .feed-desktop-center{
    width:614px;
    max-width:614px;
    margin:0 auto;
    min-width:0;
  }
  body.feed-insta-ui .feed-desktop-layout .mf-feed{
    max-width:100% !important;
    width:100% !important;
    margin-left:0 !important;
    margin-right:0 !important;
  }
  body.feed-insta-ui .feed-right-rail{
    display:block;
    position:fixed;
    right:24px;
    top:auto;
    top:250px;
    width:248px;
    z-index:90;
    padding:0;
    box-sizing:border-box;
  }
  body.feed-insta-ui .feed-right-nav{
    display:flex;
    flex-direction:column;
    gap:2px;
    margin:0;
    padding:0;
    list-style:none;
  }
  body.feed-insta-ui .feed-right-nav-item{
    display:flex;
    align-items:center;
    gap:12px;
    min-height:42px;
    padding:8px 12px;
    border-radius:10px;
    color:var(--msb-palette-text-on-nav, #0d0d0d);
    font-size:14px;
    font-weight:500;
    line-height:1.2;
    text-decoration:none;
    transition:background .15s ease,color .15s ease;
    box-sizing:border-box;
  }
  body.feed-insta-ui .feed-right-nav-item.is-active{
    background:var(--msb-palette-nav-active-bg, #f3f4f6);
    color:var(--msb-palette-nav-active-text, #0f172a);
    font-weight:700;
  }
  body.feed-insta-ui .feed-right-nav-item:hover,
  body.feed-insta-ui .feed-right-nav-item:focus{
    background:var(--msb-palette-nav-hover, #d0d8e4);
    color:var(--msb-palette-text-on-nav-hover, #0a0a0a);
    box-shadow:inset 0 0 0 1px rgba(15,23,42,.14);
    text-decoration:none;
    outline:none;
  }
  body.feed-insta-ui .feed-right-nav-ic{
    flex:0 0 20px;
    width:20px;
    height:20px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    color:inherit;
  }
  body.feed-insta-ui .feed-right-nav-ic svg{
    display:block;
    width:18px;
    height:18px;
    stroke:currentColor;
    fill:none;
    stroke-width:1.75;
    stroke-linecap:round;
    stroke-linejoin:round;
  }
  body.feed-insta-ui .feed-right-nav-label{
    flex:1 1 auto;
    min-width:0;
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis;
  }
  body.feed-insta-ui .feed-right-nav-badge{
    flex:0 0 auto;
    margin-left:8px;
    padding:3px 8px;
    border-radius:999px;
    background:#f3f4f6;
    color:#6b7280;
    font-size:10px;
    font-weight:700;
    letter-spacing:.04em;
    line-height:1;
  }

  /* [FEED_DESKTOP_SCROLL_UI] — pin header/stories; scroll feed only */
  html:has(body.feed-insta-ui),
  body.feed-insta-ui{
    overflow:hidden !important;
    height:100vh !important;
    max-height:100vh !important;
  }
  body.feed-insta-ui .sh-mainpanel{
    height:100vh !important;
    max-height:100vh !important;
    overflow:hidden !important;
    display:flex !important;
    flex-direction:column !important;
  }
  body.feed-insta-ui .sh-pagebody{
    flex:1 1 auto !important;
    min-height:0 !important;
    overflow:hidden !important;
    display:flex !important;
    flex-direction:column !important;
    padding:0 !important;
    background:var(--feed-surface, var(--msb-palette-bg, #fff)) !important;
  }
  body.feed-insta-ui .ig-feed-header{
    flex:0 0 auto !important;
    position:relative !important;
    top:auto !important;
    z-index:110 !important;
    margin:0 !important;
    background:var(--feed-surface, var(--msb-palette-bg, var(--feed-page-bg, var(--feed-topbar-bg, #fff)))) !important;
    border-bottom:1px solid var(--feed-post-divider, var(--feed-border-strong, rgba(177, 188, 206, 0.22))) !important;
  }
  body.feed-insta-ui .feed-top-search{
    flex:0 0 auto !important;
    position:relative !important;
    top:auto !important;
    z-index:105 !important;
    background:var(--msb-palette-bg, var(--feed-page-bg, var(--feed-topbar-bg, #f5f7fb))) !important;
    padding:12px 16px 8px !important;
  }
  body.feed-insta-ui .feed-desktop-center > .feed-top-search{
    position:sticky !important;
    top:0 !important;
    z-index:105 !important;
    flex:0 0 auto !important;
    width:100% !important;
    margin:0 !important;
    background:var(--msb-palette-bg, var(--feed-page-bg, var(--feed-topbar-bg, #f5f7fb))) !important;
  }
  body.feed-insta-ui .feed-desktop-layout{
    flex:1 1 auto !important;
    min-height:0 !important;
    overflow:hidden !important;
    width:100% !important;
    background:var(--msb-palette-bg, var(--feed-page-bg, transparent)) !important;
  }
  body.feed-insta-ui .feed-desktop-center{
    height:100% !important;
    max-height:100% !important;
    overflow-y:auto !important;
    overflow-x:hidden !important;
    -webkit-overflow-scrolling:touch;
    overscroll-behavior:contain;
    scrollbar-width:thin;
    scrollbar-color:rgba(0,0,0,.22) transparent;
    background:var(--msb-palette-bg, var(--feed-page-bg, transparent)) !important;
  }
  body.feed-insta-ui .feed-desktop-center::-webkit-scrollbar{
    width:6px;
  }
  body.feed-insta-ui .feed-desktop-center::-webkit-scrollbar-thumb{
    background:rgba(0,0,0,.22);
    border-radius:999px;
  }
  body.feed-insta-ui .feed-desktop-layout .mf-feed{
    margin-top:0 !important;
    padding-bottom:96px !important;
  }
}
@media (max-width:1024px){
  html:has(body.feed-page.feed-insta-ui),
  body.feed-page.feed-insta-ui{
    overflow:hidden !important;
    height:100vh !important;
    max-height:100vh !important;
  }
  body.feed-page.feed-insta-ui .sh-mainpanel{
    height:100vh !important;
    max-height:100vh !important;
    overflow:hidden !important;
    display:flex !important;
    flex-direction:column !important;
  }
  body.feed-page.feed-insta-ui .sh-pagebody{
    flex:1 1 auto !important;
    min-height:0 !important;
    overflow:hidden !important;
    display:flex !important;
    flex-direction:column !important;
    padding:0 !important;
  }
  body.feed-page.feed-insta-ui .ig-feed-header,
  body.feed-page.feed-insta-ui .feed-top-search{
    flex:0 0 auto !important;
  }
  body.feed-page.feed-insta-ui .feed-top-search{
    z-index:105 !important;
    background:var(--msb-palette-bg, var(--feed-page-bg, var(--feed-topbar-bg, #f5f7fb))) !important;
  }
  body.feed-page.feed-insta-ui .feed-desktop-layout{
    flex:1 1 auto !important;
    min-height:0 !important;
    overflow:hidden !important;
    width:100% !important;
  }
  body.feed-page.feed-insta-ui .feed-desktop-center{
    height:100% !important;
    max-height:100% !important;
    overflow-y:auto !important;
    overflow-x:hidden !important;
    -webkit-overflow-scrolling:touch;
    overscroll-behavior:contain;
  }
}
</style>
<style><?php include __DIR__ . '/includes/feed_page_chrome.css.php'; ?></style>

</head>
  <body class="feed-page feed-insta-ui">
  <?php $GLOBALS['msb_skip_header_leftbar'] = true; $skipHeaderThemeBootstrap = true; include __DIR__.'/includes/header.php'; ?>
  <?php $feedLeftRailActive = 'feed.php'; $feedLeftRailCanFollow = $canFollowPublishers; include __DIR__.'/includes/feed_left_rail.php'; ?>
    
    <!-- ✅ Leftbar overlay (used on mobile/tablet drawer mode) -->
    <div id="lbOverlay" class="lb-overlay" style="display:none;"></div>
        <div class="sh-mainpanel">
          <!-- ✅ LEFT SIDEBAR (NAV + COMMENTS) -->
          <!-- <div class="sh-pagetitle">
            <div class="input-group">
              <input type="search" class="form-control" placeholder="Search">
              <span class="input-group-btn">
                <button class="btn"><i class="fa fa-search"></i></button>
              </span>
            </div>
            <div class="sh-pagetitle-left">
              <div class="sh-pagetitle-icon"><i class="icon ion-person-add"></i></div>
              <div class="sh-pagetitle-title">
                <span>Form Styles</span>
                <h2>Feed Your Thoughts Here</h2>
              </div>
            </div>
          </div> -->
          <!-- ✅ NAV LEFTBAR (always visible at far left like your screenshot)
               IMPORTANT: navleftbar.php prints only label + <ul class="nav">.
               We must wrap it with .sh-sideleft-menu to anchor it on the left.
               This does NOT change comment/read-more drawers (they remain overlays). -->
          
          <?php include __DIR__.'/includes/leftbar.php'; ?>
          <?php include __DIR__.'/includes/stories_right_door.php'; ?>
          <div class="sh-pagebody">
            <div class="ig-feed-header">
              <?php include __DIR__ . '/includes/feed_top_user_lead.php'; ?>
              <div class="ig-stories-wrap">
                <div class="ig-stories-bar is-empty" aria-label="Stories">
                  <div class="ig-stories-track is-empty<?= $staffReadonly ? '' : ' has-create' ?>" id="igStoriesTrack">
                    <?php if (!$staffReadonly): ?>
                    <a class="ig-story-item ig-story-create" href="dashboard.php?modal=1&amp;story=1" data-create-post-modal="1" aria-label="Create a story">
                      <div class="ig-story-ring ig-story-ring-create"><i class="icon ion-plus" aria-hidden="true"></i></div>
                    </a>
                    <?php endif; ?>
                    <div class="ig-story-item ig-story-empty" role="status" aria-label="No stories available">
                      <div class="ig-story-ring ig-story-ring-empty">
                        <span class="ig-story-empty-icon" aria-hidden="true"><i class="icon ion-ios-book-outline"></i></span>
                      </div>
                      <span class="ig-story-name"></span>
                    </div>
                  </div>
                <a type="button" class="ig-stories-next" aria-label="Next stories" onclick="var t=document.getElementById('igStoriesTrack');if(t){t.scrollBy({left:140,behavior:'smooth'});}"><i class="fa fa-chevron-right"></i></a>
                </div>
              </div>
              <div class="ig-feed-top-actions" aria-label="Header actions">
                <?php include __DIR__ . '/includes/feed_top_actions.php'; ?>
              </div>
            </div>
            <div class="feed-desktop-layout">
              <div class="feed-desktop-center">
                <div class="feed-top-search" aria-label="Search feed">
                  <form id="feedTopSearchForm" class="feed-top-search-form" method="get" action="feed.php">
                    <div class="feed-top-search-field">
                      <input
                        type="search"
                        id="feedTopSearchInput"
                        name="q"
                        class="feed-top-search-input"
                        value="<?= htmlspecialchars($feedSearchQ, ENT_QUOTES, 'UTF-8') ?>"
                        placeholder="Search posts and publishers…"
                        autocomplete="off"
                        enterkeyhint="search"
                      >
                      <button type="submit" class="feed-top-search-icon" aria-label="Search">
                        <i class="fa fa-search" aria-hidden="true"></i>
                      </button>
                    </div>
                  </form>
                </div>
                <div id="mfFeed" class="mf-feed mobile-only mf-hydrating" aria-label="Mobile/Tablet feed"></div>
              </div>
            </div>
            <?php $suggestedForYouMode = 'none'; include __DIR__ . '/includes/suggested_for_you.php'; ?>
            <div class="row row-sm desktop-only">
            <!-- LEFT: Viewer -->
            <div class="col-lg-8 feedViewerCol" id="feedPostScrollCol">
              <div class="ig-feed-center">
              <div class="ig-post-shell">
              <div class="card bd-primary ig-post-card ig-insta-card">
                <div class="card-header ig-insta-header d-flex align-items-center justify-content-between">
                  <div class="d-flex align-items-center flex-grow-1 min-width-0">
                    <div id="pvAvatar" class="feed-avatar ig-insta-avatar mr-2">P</div>
                    <div class="ig-insta-userline min-width-0">
                      <div class="d-flex align-items-center flex-wrap">
                        <h6 id="pvAuthor" class="ig-insta-username card-title mb-0">Post</h6>
                        <span class="ig-insta-dot">·</span>
                        <span id="pvTimeAgo" class="ig-insta-time">Just now</span>
                      </div>
                      <small id="pvMeta" class="text-muted d-none">Select a post from the right.</small>
                      <div id="pvMetaPills" class="pv-meta-pills d-none">
                        <span class="pv-pill"><i class="icon ion-clock"></i> <span id="pvTimeAgoPill">Just now</span></span>
                      </div>
                    </div>
                  </div>
                  <div class="ig-insta-header-actions">
                    <button type="button" class="ig-insta-more" id="btnPostCardMore" aria-label="More options"><i class="fa fa-ellipsis-h"></i></button>
                    <div class="ig-insta-tools-flyout" id="postCardToolsFlyout">
                      <a href="#" id="btnFindMe" title="Find my posts"><i class="icon ion-pinpoint"></i> Find my posts</a>
                      <a href="#" id="btnFindAuthor" title="Find author posts"><i class="icon ion-compass"></i> Find author</a>
                      <a href="javascript:void(0)" id="btnOpenCommentsDrawer" title="Comments" style="display:none;"><i class="icon ion-chatbubbles"></i> Comments</a>
                      <a href="javascript:void(0)" id="btnOpenMedia" title="View media"><i class="icon ion-ios-eye"></i> View media</a>
                      <a href="javascript:void(0)" id="btnFullscreenMedia" title="Fullscreen"><i class="icon ion-ios-expand"></i> Fullscreen</a>
                      <?php if (!$staffReadonly): ?>
                      <a id="btnCreateEdit" href="dashboard.php?modal=1" data-create-post-modal="1" title="Create New Post"><i id="btnCreateEditIcon" class="fa fa-plus"></i> <span id="btnCreateEditLabel">Create post</span></a>
                      <?php endif; ?>
                    </div>
                  </div>
                </div>

                <!-- ✅ Viewer -->
                <div class="card-body p-0 ig-viewer-body">
                  <div class="ig-layout">
                    <div class="ig-insta-media-stage">
                    <!-- Media -->
                    <div class="ig-media" id="pvMedia">
                      <div class="ig-media-empty">
                        <i class="icon ion-ios-photos-outline"></i>
                        <div class="mt-2">Select a post to view media</div>
                      </div>

                      <!-- ✅ BOOK CAPTION (shows only in book layout) -->
                      <div class="ig-media-caption" id="pvCap" style="display:none;">
                        <div class="ig-cap-title" id="pvCapTitle"></div>
                        <div class="ig-cap-meta" id="pvCapMeta"></div>
                        <div class="d-flex align-items-center justify-content-between mb-2">
                          <div class="ig-cap-text feed-desc clamp-2" id="pvCapText"></div>
                          <a href="#" class="ig-cap-readmore" id="pvCapReadMore">Read more</a>
                        </div>
                      </div>
                    </div>
                    <button type="button" class="ig-insta-carousel-btn ig-insta-carousel-prev" id="btnInstaMediaPrev" aria-label="Previous slide" style="display:none;"><i class="fa fa-angle-left"></i></button>
                    <button type="button" class="ig-insta-carousel-btn ig-insta-carousel-next" id="btnInstaMediaNext" aria-label="Next slide" style="display:none;"><i class="fa fa-angle-right"></i></button>
                    </div>
                    <div class="ig-insta-dots-host" id="igInstaDotsHost"></div>

                    <!-- Details -->
                    <div class="ig-details">
                      <!-- ✅ TOP AREA -->
                      <div class="ig-topbar">

                        <!-- ✅ Instagram action bar -->
                        <div class="ig-underbar ig-insta-actionbar">
                          <div class="ig-insta-actions-left ig-actions ig-actions-icons">
                            <a id="btnLove" class="ig-act" type="button" title="Love">
                              <span class="ig-circle"><i class="fa fa-heart-o ig-insta-ico-heart"></i></span>
                              <span id="loveCount" class="ig-count">0</span>
                            </a>
                            <a href="javascript:void(0)" id="commentCountLink" class="ig-act" title="Comments">
                              <span class="ig-circle"><i class="fa fa-comment-o ig-insta-ico-comment"></i></span>
                              <span id="commentCount" class="ig-count">0</span>
                            </a>
                            <a id="btnShare" class="ig-act" type="button" title="Share">
                              <span class="ig-circle"><i class="fa fa-upload ig-insta-ico-share"></i></span>
                            </a>
                            <a id="viewCountLink" class="ig-act" type="button" title="Views" style="cursor:default;">
                              <span class="ig-circle"><i class="icon ion-ios-eye"></i></span>
                              <span id="viewCount" class="ig-count">0</span>
                            </a>
                            <a id="btnLike" class="ig-act" type="button" title="Like">
                              <span class="ig-circle"><i class="fa fa-thumbs-o-up"></i></span>
                              <span id="likeCount" class="ig-count">0</span>
                            </a>
                          </div>
                          <div class="ig-insta-actions-right">
                            <a id="btnSave" class="ig-act" type="button" title="Save">
                              <span class="ig-circle"><i class="fa fa-bookmark-o ig-insta-ico-save"></i></span>
                              <span id="saveCount" class="ig-count">0</span>
                            </a>
                          </div>
                        </div>

                        <div class="ig-insta-caption-block">
                        <div class="ig-insta-likes-row"><span id="loveCountLikes">0</span> likes</div>
                        <h4 id="pvTitle" class="ig-title"></h4>
                        <!-- ✅ BOOK / TEXT MODE: show long body beside media (7 lines) -->
                        <!-- ✅ BOOK ONLY: Vertical action rail (Book layout only) -->
                        <div class="ig-vrail" id="bookVrail" aria-label="Book actions">
                          <button id="btnLoveV" type="button" class="vrail-btn" title="Love">
                            <span class="vrail-ico"><i class="fa fa-heart"></i></span>
                            <span id="loveCountV" class="vrail-count">0</span>
                          </button>

                          <!-- <button id="btnLikeV" type="button" class="vrail-btn" title="Like">
                            <span class="vrail-ico"><i class="fa fa-thumbs-up"></i></span>
                            <span id="likeCountV" class="vrail-count">0</span>
                          </button> -->

                          <a href="javascript:void(0)" id="commentCountLinkV" class="vrail-btn vrail-link" title="Comments" style="text-decoration:none;">
                            <span class="vrail-ico"><i class="fa fa-comment"></i></span>
                            <span id="commentCountV" class="vrail-count">0</span>
                          </a>

                          <!-- <a href="javascript:void(0)" id="viewCountLinkV" class="vrail-btn vrail-link" title="Views" style="text-decoration:none;">
                            <span class="vrail-ico"><i class="icon ion-ios-eye"></i></span>
                            <span id="viewCountV" class="vrail-count">0</span>
                          </a> -->

                          <button id="btnSaveV" type="button" class="vrail-btn" title="Save">
                            <span class="vrail-ico"><i class="fa fa-bookmark"></i></span>
                            <span id="saveCountV" class="vrail-count">0</span>
                          </button>

                          <button id="btnShareV" type="button" class="vrail-btn" title="Share">
                            <span class="vrail-ico"><i class="fa fa-share"></i></span>
                            <span id="shareCountV" class="vrail-count">0</span>
                          </button>
                        </div>
                        <div id="pvBody" class="ig-body feed-desc clamp-2"></div>
                        </div>
                      </div>

                      <!-- ✅ TEXT AREA (filled by JS depending on post type) -->
                      <div id="pvTextBelow" class="pv-text-below" style="display:none;"></div>

                      
                    </div><!-- ig-details -->
                  </div><!-- ig-layout -->
                </div><!-- card-body -->
              </div>
              <div class="card ig-post-footer-card">
                <div class="card-footer ig-post-footer">
                  <div class="ig-post-progress" aria-hidden="true">
                    <div id="pvAutoProgressBar" class="ig-post-progress-bar"></div>
                  </div>
                  <div class="ig-post-footer-row">
                    <div class="ig-post-footer-left">
                      <a id="btnFooterLove" class="ig-post-footer-act" href="#" title="Love">
                        <i class="fa fa-heart"></i><span id="loveCountF">0</span>
                      </a>
                      <a id="btnFooterLike" class="ig-post-footer-act" href="#" title="Like">
                        <i class="fa fa-thumbs-up"></i><span id="likeCountF">0</span>
                      </a>
                      <a id="btnFooterComment" class="ig-post-footer-act" href="#" title="Comments">
                        <i class="fa fa-comment-o"></i><span id="commentCountF">0</span>
                      </a>
                      <a id="btnFooterShare" class="ig-post-footer-act" href="#" title="Share">
                        <i class="fa fa-share-square-o"></i><span id="shareCountF">0</span>
                      </a>
                    </div>
                    <div class="ig-post-footer-right">
                      <a id="btnFooterSave" class="ig-post-footer-act" href="#" title="Save">
                        <i class="fa fa-bookmark"></i><span id="saveCountF">0</span>
                      </a>
                    </div>
                  </div>
                  <div class="ig-post-footer-row ig-post-footer-row-meta">
                    <div class="ig-post-footer-left">
                      <a id="btnFooterViewComments" class="ig-post-footer-link" href="#">View all <span id="commentCountTextF">0</span> comments</a>
                    </div>
                    <div class="ig-post-footer-right">
                      <span id="viewCountTextF" class="ig-post-footer-link ig-post-footer-views">0 views</span>
                    </div>
                  </div>
                </div>
              </div>
              </div><!-- /.ig-feed-center -->
            </div>

            <!-- RIGHT: Sidebar list -->
            <div class="col-lg-4 mg-t-20 mg-lg-t-0">
              <div class="card rightSidebarCard">
                <div class="card-header d-flex align-items-center justify-content-between">
                  <div class="d-flex align-items-center">
                    <h6 class="card-title mb-0 mr-2">Peer Posts</h6>
                    <span id="unreadBadge" class="badge badge-danger" style="display:none;">Unread: 0</span>
                  </div>
                  <a id="refreshBtn" href="#" role="button" aria-label="Refresh peer posts" class="icon ion-loop" style="font-size:30px;cursor:pointer;"></a>
                </div>

                <div class="card-body p-2 rightSidebarBody">
                  <div class="sidebar-tools">
                    <div class="d-flex align-items-center justify-content-between mb-2">
                      <span class="sidebar-pill"><i class="icon ion-funnel"></i> Filter</span>
                      <span class="sidebar-pill"><i class="icon ion-ios-search-strong"></i> Search</span>
                    </div>

                    <div class="d-flex mb-2">
                      <select id="filterSel" class="form-control form-control-sm mr-2">
                        <option value="all">All</option>
                        <option value="unread">Unread</option>
                        <option value="mine">My Posts</option>
                        <option value="top">Top Viewed</option>
                      </select>
                      <input id="searchBox" class="form-control form-control-sm" placeholder="Search by title or name...">
                    </div>
                  </div>

                  <div id="postList" class="rightSidebarList"></div>
                </div>
              </div>

              <div class="card mg-t-15">
                <div class="card-body">
                  <small class="text-muted">
                    Tip: Unread posts show a <strong>NEW</strong> badge. Clicking a post marks it as read. Sidebar rows auto-hide after 24 hours.
                  </small>
                    </div>
                  </div>
                </div>
              </div>
        </div>

      <!-- ✅ BEAUTIFUL "Read more" MODAL -->
      <div id="rmOverlay" class="rm-overlay" style="display:none;">
        <div class="rm-modal" role="dialog" aria-modal="true" aria-labelledby="rmTitleText">

          <div class="rm-topbar">
            <div class="rm-left">
              <div id="rmAvatar" class="rm-avatar">P</div>

              <div class="rm-headtxt">
                <div id="rmTitleText" class="rm-title"></div>

                <div class="rm-sub">
                  <span id="rmAuthor" class="rm-author"></span>
                  <span class="rm-dot">•</span>
                  <span id="rmDate" class="rm-date"></span>
                </div>
              </div>
            </div>

            <button type="button" id="rmCloseX" class="rm-x" aria-label="Close">&times;</button>
          </div>

          <div class="rm-content">
            <div class="rm-section">
              <div id="rmBody" class="rm-body"></div>
            </div>
          </div>

          <div class="rm-footer">
            <button type="button" id="rmCloseBtn" class="btn btn-sm btn-outline-secondary">Close</button>
          </div>

        </div>
      </div>

      <!-- ✅ COMMENTS VIEW MODAL -->
      <div id="cOverlay" class="c-overlay" style="display:none;">
        <div class="c-modal" role="dialog" aria-modal="true" aria-labelledby="cTitleText">

          <div class="c-topbar">
            <div class="c-left">
              <div id="cAvatar" class="c-avatar">P</div>
              <div class="c-headtxt">
                <div id="cTitleText" class="c-title">Comments</div>
                <div class="c-sub">
                  <span id="cAuthor" class="c-author"></span>
                  <span class="c-dot">•</span>
                  <span id="cDate" class="c-date"></span>
                </div>
              </div>
            </div>

            <button type="button" id="cCloseX" class="c-x" aria-label="Close">&times;</button>
          </div>

          <div class="c-bodywrap">
            <div class="c-list" id="cCommentsList"></div>
          </div>

          <div class="c-footer">
            <form id="cCommentForm" class="m-0 w-100">
              <input type="hidden" id="cPostId" value="0">
              <input type="hidden" id="cParentId" value="0">

              <div class="d-flex align-items-center justify-content-between mb-2">
                <small id="cReplyingTo" class="text-muted" style="display:none;"></small>
                <button type="button" id="cCancelReply" class="btn btn-sm btn-outline-secondary" style="display:none;">Cancel reply</button>
              </div>

              <div class="input-group">
                <input type="text" id="cCommentText" class="form-control" placeholder="Write a comment...">
                <div class="input-group-append">
                  <button class="btn btn-primary" type="submit"><i class="icon ion-arrow-up-a"></i></button>
                </div>
              </div>
            </form>
          </div>

        </div>
      </div>

      <!-- ✅ NEW: FULL-WIDTH MEDIA VIEWER MODAL (for ion-ios-eye) -->
      <div id="mvOverlay" class="mv-overlay" style="display:none;">
        <div class="mv-modal" role="dialog" aria-modal="true" aria-label="Media Viewer">
          <button type="button" id="mvClose" class="mv-close" aria-label="Close">&times;</button>
          <div class="mv-stage">
            <div id="mvContent" class="mv-content"></div>
          </div>
        </div>
      </div>

      <!-- ✅ NEW: VIEWERS + ANALYTICS (owner only) -->
      <div id="vwOverlay" class="vw-overlay" style="display:none;">
        <div class="vw-modal" role="dialog" aria-modal="true" aria-label="Viewers">
          <div class="vw-topbar">
            <div class="vw-title">
              <i class="icon ion-ios-eye"></i>
              <span>Views</span>
            </div>
            <button type="button" id="vwClose" class="vw-close" aria-label="Close">&times;</button>
          </div>

          <div class="vw-stats">
            <div class="vw-stat">
              <div class="vw-stat-k">Total</div>
              <div id="vwTotal" class="vw-stat-v">0</div>
            </div>
            <div class="vw-stat">
              <div class="vw-stat-k">7 days</div>
              <div id="vw7d" class="vw-stat-v">0</div>
            </div>
            <div class="vw-stat">
              <div class="vw-stat-k">30 days</div>
              <div id="vw30d" class="vw-stat-v">0</div>
            </div>
          </div>

          <div class="vw-body">
            <div class="vw-subtitle">Who viewed</div>
            <div id="vwList" class="vw-list"></div>
            <div id="vwEmpty" class="vw-empty" style="display:none;">No viewers yet.</div>
          </div>
        </div>
      </div>

      <style>
        .mb-2 {margin-bottom: 0.2rem !important;}
        .align-items-center {align-items: center !important;}
        .justify-content-between {justify-content: space-between !important;}
        html, body { height:auto; min-height:100%; }
        body { overflow:auto; }
        .sh-mainpanel{min-height:100vh;height:auto;display:flex;flex-direction:column;overflow:visible;padding-top:0px;position:relative;padding-top:0px;margin-left:0;transition:all 0.2s ease-in-out;}
        .sh-pagetitle{ position:sticky; z-index:1100; background:#fff; border-bottom:1px solid rgba(0,0,0,.08); box-shadow:0 2px 10px rgba(0,0,0,.05); flex:0 0 auto;}
        .sh-pagebody{ padding: 5px;flex:1 1 auto; min-height:0; overflow:visible; display:flex; flex-direction:column; padding-top:10px; padding-bottom:0 !important; }
        .row.row-sm{ flex:1 1 auto; min-height:0; }

        /* ✅ ICON-ONLY CIRCLE BUTTON (Create/Edit) */
        .pu-icon-btn{
          width: 36px !important;
          height: 36px !important;
          padding: 0 !important;
          border-radius: 999px !important;
          display: inline-flex !important;
          align-items: center !important;
          justify-content: center !important;
          line-height: 1 !important;
        }
        .pu-icon-btn i{
          font-size: 16px;
          line-height: 1;
        }
        .rightSidebarBody{ flex:1 1 auto; min-height:0; display:flex; flex-direction:column; }
        .rightSidebarList{ flex:1 1 auto; min-height:0; overflow-y:auto; overflow-x:hidden; padding-right:4px;margin-bottom: 5px; }
        .rightSidebarList::-webkit-scrollbar{ width:6px; }
        .rightSidebarList::-webkit-scrollbar-thumb{ background:rgba(0,0,0,.2); border-radius:10px; }
        .rightSidebarList::-webkit-scrollbar-thumb:hover{ background:rgba(0,0,0,.35); }

        .pl-item{ cursor:pointer; border-radius:10px; padding:10px; }
        /* ✅ keep the "more" (3-dots) icon pinned to the far-right end of each row */
        .pl-item{ position:relative; padding-right:44px; }
        .pl-more-wrap{ position:absolute; top:10px; right:10px; z-index:6; }
        .pl-more{ border-radius:10px; }
        .pl-more:focus{ box-shadow:none; }
        .rightSidebarCard .dropdown-menu{ z-index:9999; }

        /* ✅ Fix: keep 3-dots dropdown anchored to its button (prevents menu jumping/covering other rows) */
        .rightSidebarCard .pl-scroll{ overflow-x: visible !important; }
        .rightSidebarCard .pl-item{ overflow: visible !important; }
        .rightSidebarCard .pl-more-wrap{ position:absolute !important; top:10px !important; right:10px !important; z-index: 1001 !important; }
        .rightSidebarCard .pl-more-wrap .pl-dropdown-menu{
          position: absolute !important;
          top: 100% !important;
          right: 0 !important;
          left: auto !important;
          margin-top: 8px !important;
          transform: none !important;
          will-change: auto !important;
          min-width: 200px;
        }
        /* ✅ Force Bootstrap/Popper dropdown to stay under the 3-dots (no center-jump) */
        .rightSidebarCard .dropdown-menu[data-bs-popper]{
          top: 100% !important;
          left: auto !important;
          right: 0 !important;
          margin-top: 8px !important;
          transform: none !important;
          inset: auto auto auto auto !important;
        }

        .pl-item:hover{ background:#f7f7f9; }
        .pl-item.active{ background:#e9f2ff; }

        .pl-thumb{ width:48px; height:48px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-weight:900; font-size:13px; color:#fff; letter-spacing:.6px; user-select:none; flex:0 0 auto; border:2px solid rgba(255,255,255,.9); box-shadow:0 6px 16px rgba(0,0,0,.18), inset 0 1px 0 rgba(255,255,255,.35); }
        .pl-item:hover .pl-thumb{ box-shadow:0 10px 22px rgba(0,0,0,.22), 0 0 0 4px rgba(8,97,188,.10), inset 0 1px 0 rgba(255,255,255,.35); }
        .pl-item.active .pl-thumb{ box-shadow:0 10px 22px rgba(0,0,0,.22), 0 0 0 4px rgba(8,97,188,.18), inset 0 1px 0 rgba(255,255,255,.35); }
        .pl-item[data-unread="1"] .pl-thumb{ box-shadow:0 6px 16px rgba(0,0,0,.18), 0 0 0 3px rgba(255,59,48,.35), inset 0 1px 0 rgba(255,255,255,.35); }

        .pl-title{ font-weight:600; font-size:13px; margin:0; line-height:1.2; }
        .pl-desc{
          font-size:12px;
          color:#6c757d;
          margin:2px 0 0 0;
          display:-webkit-box;
          -webkit-box-orient: vertical;
          -webkit-line-clamp: 2;
          overflow:hidden;
          text-overflow: ellipsis;
          line-height: 1.4;
          max-height: calc(1.4em * 2);
        }
        .pl-readmore{ font-size:12px; font-weight:600; color:#0861bc; text-decoration:none; white-space:nowrap; }
        .pl-readmore:hover{ text-decoration:underline; }

        .pl-row-meta{ display:flex; align-items:center; justify-content:space-between; gap:10px; }
        .pl-row-meta-right{ display:flex; align-items:center; gap:10px; margin-left:auto; }
        .badge-new{ background:#ff3b30; color:#fff; }

        .ig-post-card{
          overflow:hidden;
          display:flex;
          flex-direction:column;
        }

        .ig-viewer-body{
          height: 530px;
          display:flex;
          flex-direction:column;
          flex:1 1 auto;
          min-height:0;
        }
        .ig-layout{ display:flex; flex:1 1 auto; height:100%; min-height:0; }

        .ig-media{
          flex:0 0 50%;
          background:#0f1115;
          position:relative;
          display:flex;
          align-items:center;
          justify-content:center;
          height:100%;
          min-height:0;
        }
        .ig-media-main{ width:100%; height:100%; object-fit:contain; background:#0f1115; }

        .ig-strip{ position:absolute; left:50%; bottom:23px; transform:translateX(-50%); display:flex; align-items:center; justify-content:center; gap:8px; overflow:visible; padding:8px 14px; background:rgba(17,24,39,.34); border-radius:999px; max-width:calc(100% - 24px); }
        .ig-strip-item{ width:10px; height:10px; border-radius:999px; overflow:hidden; display:inline-flex; align-items:center; justify-content:center; background:rgba(255,255,255,.45); border:0; flex:0 0 auto; position:relative; text-indent:-9999px; box-shadow:none; }
        .ig-strip-item.active{ background:#fff; transform:scale(1.08); }
        .ig-strip-item img, .ig-strip-vid, .ig-strip-play{ display:none !important; }

        .ig-media-empty{ color:#cbd3da; text-align:center; padding:18px; opacity:.9; }
        .ig-media-empty i{ font-size:44px; opacity:.85; }

        .ig-details{
          flex:1 1 auto;
          display:flex;
          flex-direction:column;
          height:100%;
          min-height:0;
        }

        .ig-topbar{ 
        padding:10px 10px 10px 10px; 
        border-bottom:1px solid rgba(0,0,0,.06); flex:0 0 auto;}
        .ig-underbar{ padding:0 6px 6px 6px; border-bottom:1px solid rgba(0,0,0,.06); flex:0 0 auto; }

        .ig-title{ font-size:18px; margin:0 0 6px 0; font-weight:700; padding-left:1px;}
        .ig-desc{ color:#6c757d; margin-bottom:0px; }
        .ig-body{ white-space:pre-wrap; color:#2b2f33; }

        .ig-actions{ display:flex; align-items:center; gap:1px; flex-wrap:wrap; padding-top:12px; }

        .ig-comments{ padding:12px 16px; overflow:auto; flex:1 1 auto; min-height:0; }
        .ig-commentbox{ padding:12px 16px; border-top:1px solid rgba(0,0,0,.06); flex:0 0 auto; }

        .cmt{ padding:10px; border:1px solid rgba(0,0,0,.06); border-radius:10px; margin-bottom:10px; }
        .cmt.is-alert-focus{ background:rgba(37,99,235,.08); border-color:rgba(37,99,235,.26); box-shadow:0 14px 28px rgba(37,99,235,.14); }
        .cmt.reply{ margin-left:18px; background:#e1ebf2; }
        .cmt .meta{ font-size:12px; color:#6b7280; font-weight:700; }
        .cmt .txt{ margin-top:6px; white-space:pre-wrap; word-break:break-word; }

        /* ✅ TEXT-ONLY POST MODE */
        .ig-layout.is-text-only .ig-media{ display:none !important; }
        .ig-layout.is-text-only .ig-details{ flex:1 1 100%; }
        .ig-layout.is-text-only .ig-topbar{ background: var(--to-bg, #f5f7fb); }
        .ig-layout.is-text-only .ig-title{ font-size:22px; padding-left: 15px;}
        .ig-layout.is-text-only .ig-body{ font-size:14px; line-height:1.6; }

        /* ✅ MEDIA-ONLY POST MODE */
        .ig-post-card .card-body.media-only{ height: 530px !important; }
        .ig-layout.is-media-only{ display:flex; flex-direction:column; height:100%; min-height:0; }
        .ig-layout.is-media-only .ig-topbar.pv-top-detached{ width:100%; border-bottom:1px solid rgba(0,0,0,.06); background:#fff; }
        .ig-layout.is-media-only .ig-media{
          width:100%;
          flex:0 0 auto;
          height: clamp(230px, 38vh, 300px) !important;
          border-bottom:1px solid rgba(0,0,0,.06);
          /* border:solid; */
        }
        .ig-layout.is-media-only .ig-media-main{ width:100%; height:100%; object-fit:contain; }
        .ig-layout.is-media-only .ig-details{ width:100%; flex:1 1 auto; min-height:0; height:auto !important; }
        .ig-layout.is-media-only #pvBody{ display:none !important; }
        .ig-layout.is-media-only .ig-comments{ flex:1 1 auto; min-height:0; }
        .ig-layout.is-media-only .ig-commentbox{ position:sticky; bottom:0; z-index:5; }
        .ig-layout.is-media-only{ position:relative; }

        /* ✅ IMAGE-ONLY OVERLAY MODE
           - Only for normal image/gif post cards
           - Title stays above
           - Reactions move to right vertical on image
           - Description sits at bottom of image
        */
        .ig-viewer-body.mode-image-overlay .ig-underbar{ display:none !important; }
        .ig-viewer-body.mode-image-overlay .ig-media{
          position:relative;
          height: clamp(320px, 62vh, 640px) !important;
          background:#0f1115;
          overflow:hidden;
        }
        .ig-viewer-body.mode-image-overlay .ig-media::after{
          content:"";
          position:absolute;
          inset:0;
          background:
            linear-gradient(to top, rgba(0,0,0,.78), rgba(0,0,0,.26) 26%, rgba(0,0,0,0) 48%);
          pointer-events:none;
          z-index:2;
        }
        .ig-viewer-body.mode-image-overlay .ig-media-main{
          width:100% !important;
          height:100% !important;
          object-fit:cover !important;
          display:block;
        }
        .ig-viewer-body.mode-image-overlay .ig-vrail{ display:none !important; }
        .ig-viewer-body.mode-image-overlay .ig-image-overlay-actions{
          display:none !important;
          position:absolute;
          right: 12px !important;
          top: 200px !important;
          
          transform:translateY(-50%);
          flex-direction:column;
          /* gap:14px; */
          z-index:4;
        }
        .ig-viewer-body.mode-image-overlay .ig-image-overlay-btn{
          width:62px;
          border:0;
          background:transparent;
          padding:0;
          display:flex;
          flex-direction:column;
          align-items:center;
          gap:4px;
          color:#fff;
          cursor:pointer;
        }
        .ig-viewer-body.mode-image-overlay .ig-image-overlay-btn .io-ico{
          width:40px;
          height:40px;
          border-radius:999px;
          display:flex;
          align-items:center;
          justify-content:center;
          background:rgba(0,0,0,.44);
          color:#fff;
          box-shadow:0 8px 18px rgba(0,0,0,.24);
        }
        .ig-viewer-body.mode-image-overlay .ig-image-overlay-btn .io-ico i{
          color:#fff;
          font-size:18px;
        }
        .ig-viewer-body.mode-image-overlay .ig-image-overlay-btn .io-count{
          color:#fff;
          font-weight:800;
          text-shadow:0 2px 8px rgba(0,0,0,.5);
        }
        .ig-viewer-body.mode-image-overlay .ig-image-overlay-btn.is-like .io-ico i{ color:#1d4ed8; }
        .ig-viewer-body.mode-image-overlay .ig-image-overlay-btn.is-love .io-ico i{ color:var(--msb-love-color, #7c3aed); }
        .ig-viewer-body.mode-image-overlay .ig-image-overlay-btn.is-save .io-ico i{ color:#f5c518; }
        .ig-viewer-body.mode-image-overlay .ig-image-overlay-btn.is-share .io-ico i{ color:#555; }
        .ig-viewer-body.mode-image-overlay .ig-image-overlay-text{
          position:absolute;
          left:0;
          right:0;
          bottom:0;
          z-index:4;
          padding:18px 86px 16px 18px;
          color:#fff;
          background:none;
        }
        .ig-viewer-body.mode-image-overlay.mode-media-reel-bottom .ig-image-overlay-text{
          left:10px;
          right:74px;
          bottom:52px;
          padding:10px 14px;
          border-radius:16px;
          background:rgba(0,0,0,.72);
          box-shadow:0 10px 24px rgba(0,0,0,.22);
        }
        .ig-viewer-body.mode-image-overlay.mode-media-reel-bottom .ig-image-overlay-text .pv-long-wrap{
          display:flex;
          align-items:flex-end;
          gap:6px;
        }
        .ig-viewer-body.mode-image-overlay.mode-media-reel-bottom .ig-image-overlay-text .pv-long-preview{
          flex:1 1 auto;
          min-width:0;
          -webkit-line-clamp:1 !important;
          line-height:1.35;
        }
        .ig-viewer-body.mode-image-overlay.mode-media-reel-bottom .ig-image-overlay-text .pv-long-preview.is-expanded{
          display:block !important;
          -webkit-line-clamp:unset !important;
          overflow:auto !important;
          max-height:160px;
          padding-right:6px;
        }
        .ig-viewer-body.mode-image-overlay.mode-media-reel-bottom .ig-image-overlay-text .pv-readmore-inline{
          display:inline-block;
          flex:0 0 auto;
          font-weight:900;
          text-decoration:none;
          white-space:nowrap;
        }
        .ig-viewer-body.mode-image-overlay .ig-image-overlay-text .pv-long-wrap{
          padding:0 !important;
        }
        .ig-viewer-body.mode-image-overlay .ig-image-overlay-text .pv-short,
        .ig-viewer-body.mode-image-overlay .ig-image-overlay-text .pv-long,
        .ig-viewer-body.mode-image-overlay .ig-image-overlay-text .pv-long-preview,
        .ig-viewer-body.mode-image-overlay .ig-image-overlay-text .pv-readmore-inline{
          color:#fff !important;
          text-shadow:0 2px 10px rgba(0,0,0,.55);
        }
        .ig-viewer-body.mode-image-overlay #pvBody{ display:none !important; }

        @media (max-width: 991.98px){
          .ig-viewer-body.mode-image-overlay .ig-media{
            height: clamp(280px, 54vh, 460px) !important;
          }
          .ig-viewer-body.mode-image-overlay .ig-image-overlay-actions{
            right:8px !important;
            gap:12px !important;
          }
          .ig-viewer-body.mode-image-overlay .ig-image-overlay-btn{
            width:56px;
          }
          .ig-viewer-body.mode-image-overlay .ig-image-overlay-btn .io-ico{
            width:40px;
            height:40px;
          }
          .ig-viewer-body.mode-image-overlay .ig-image-overlay-text{
            padding:14px 76px 14px 14px;
          }
          .ig-viewer-body.mode-image-overlay.mode-media-reel-bottom .ig-image-overlay-text{
            left:8px;
            right:68px;
            bottom:8px;
            padding:10px 12px;
          }
        }

        
        /* ✅ FULL-MEDIA POST MODE (media + text, stacked like media-only, but allows body) */
        .ig-layout.is-full-media{ display:flex; flex-direction:column; height:100%; min-height:0; }
        .ig-layout.is-full-media .ig-topbar.pv-top-detached{ width:100%; border-bottom:1px solid rgba(0,0,0,.06); background:#fff; }
        .ig-layout.is-full-media .ig-media{
          width:100%;
          flex:0 0 auto;
          height: clamp(230px, 45vh, 360px) !important;
          border-bottom:1px solid rgba(0,0,0,.06);
        }
        .ig-layout.is-full-media .ig-media-main{ width:100%; height:100%; object-fit:contain; }
        .ig-layout.is-full-media .ig-details{ width:100%; flex:1 1 auto; min-height:0; height:auto !important; }
        .ig-layout.is-full-media .ig-comments{ flex:1 1 auto; min-height:0; }
        .ig-layout.is-full-media .ig-commentbox{ position:sticky; bottom:0; z-index:5; }

        /* ✅ text holder under actions */
        .pv-text-below{
          padding: 10px 14px 0 14px;
          padding-left: 25px;
        }
        .pv-text-below .pv-short{
          font-size:14px; line-height:1.55;
          margin-bottom:8px;
        }
        .pv-text-below .pv-long{
          font-size:14px; line-height:1.65;
          margin-bottom:10px;
        }

        /* ✅ Rule #3: long text with inline "Read more" at the end (right side) */
        .pv-long-wrap{
          /* display:flex; */
          align-items:flex-end;
          justify-content:space-between;
          gap:12px;
          padding: 10px 0 10px 0;
        }
        .pv-long-preview{
          flex:1 1 auto;
          font-size:14px;
          line-height:1.65;
          display:-webkit-box;
          -webkit-box-orient: vertical;
          -webkit-line-clamp: 4;
          overflow:hidden;
          white-space:normal;
          word-break:break-word;
        }
        .pv-readmore-inline{
          flex:0 0 auto;
          font-weight:700;
          text-decoration:none;
          white-space:nowrap;
        }
/* ✅ CLAMPS */
        .feed-desc.clamp-12{
          display:-webkit-box;
          -webkit-box-orient:vertical;
          -webkit-line-clamp:12;
          overflow:hidden;
          white-space:normal;
          word-break:break-word;
        }
        .feed-desc.clamp-2{
          display:-webkit-box;
          -webkit-box-orient:vertical;
          -webkit-line-clamp:2;
          overflow:hidden;
          white-space:normal;
          word-break:break-word;
        }

        .card.bd-primary{ margin-top:12px !important; margin-left:1px; border: solid;border-color: #ccc8c8;}

        .feed-avatar{
          width:46px; height:46px; border-radius:50%;
          display:flex; align-items:center; justify-content:center;
          font-weight:900; font-size:14px; color:#fff; letter-spacing:0.6px;
          flex-shrink:0; user-select:none; overflow:hidden;
          border:2px solid rgba(255,255,255,.95);
          box-shadow:0 10px 24px rgba(0,0,0,.18), inset 0 1px 0 rgba(255,255,255,.35);
          background:#111827; position:relative;
        }
        .feed-avatar::after{
          content:''; position:absolute; width:46px; height:46px; border-radius:50%;
          pointer-events:none;
          background: radial-gradient(circle at 30% 25%, rgba(255,255,255,.35), rgba(255,255,255,0) 45%);
          mix-blend-mode: screen;
        }
        .feed-avatar img{ width:100%; height:100%; object-fit:cover; display:block; border-radius:50%; position:relative; z-index:1; }
        .pl-thumb{ width:54px; height:54px; border-radius:16px; overflow:hidden; background:#111827; box-shadow:0 10px 20px rgba(0,0,0,.14); }
        .pl-thumb img{ width:100%; height:100%; object-fit:cover; display:block; }
        .vw-av{ overflow:hidden; }
        .vw-av img{ width:100%; height:100%; object-fit:cover; display:block; border-radius:999px; }
        .rm-avatar img, .c-avatar img{ width:100%; height:100%; object-fit:cover; display:block; border-radius:50%; }

        /* ✅ NEW: Meta pills (LEFT post card) like your screenshot */
        .pv-meta-pills{
          display:flex;
          margin-left:5px;
          gap:10px;
          flex-wrap:wrap;
          margin-top:15px;
        }
        .pv-pill{
          display:inline-flex;
          align-items:center;
          gap:6px;
          padding:1px 5px;
          border-radius:999px;
          background:#f1f3f6;
          border:1px solid rgba(0,0,0,.08);
          font-weight:800;
          font-size:13px;
          color:#374151;
          user-select:none;
          box-shadow:0 10px 24px rgba(0,0,0,.06);
        }
        .pv-pill i{ font-size:16px; opacity:.85; }

        /* ✅ Read more modal */
        .rm-overlay{ position:fixed; inset:0; background:rgba(15, 23, 42, .58); z-index:99999; display:flex; align-items:center; justify-content:center; padding:14px; backdrop-filter: blur(8px); }
        .rm-modal{ width:min(920px, 96vw); background:#fff;overflow:hidden; box-shadow:0 28px 90px rgba(0,0,0,.45); transform:translateY(6px); animation: rmPop .14s ease-out; }
        @keyframes rmPop{ from{ opacity:.6; transform:translateY(18px) scale(.985);} to{ opacity:1; transform:translateY(6px) scale(1);} }
        .rm-topbar{ display:flex; align-items:center; justify-content:space-between; padding:14px 16px; border-bottom:1px solid rgba(0,0,0,.08); background: linear-gradient(135deg, rgba(8,97,188,.14), rgba(17,24,39,.03)); }
        .rm-left{ display:flex; align-items:center; gap:12px; min-width:0; }
        .rm-avatar{ width:46px; height:46px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-weight:900; font-size:13px; color:#fff; flex:0 0 auto; border:2px solid rgba(255,255,255,.95); box-shadow:0 10px 22px rgba(0,0,0,.18), inset 0 1px 0 rgba(255,255,255,.35); background:#111827; position:relative; }
        .rm-avatar:after{ content:''; position:absolute; inset:0; border-radius:50%; background: radial-gradient(circle at 30% 25%, rgba(255,255,255,.35), rgba(255,255,255,0) 45%); mix-blend-mode: screen; pointer-events:none; }
        .rm-headtxt{ min-width:0; }
        .rm-title{ font-weight:950; font-size:18px; color:#111827; line-height:1.25; margin:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:72vw; }
        .rm-sub{ margin-top:6px; font-size:12px; color:#6b7280; display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
        .rm-author{ font-weight:900; color:#374151; }
        .rm-dot{ opacity:.55; }
        .rm-x{ width:40px; height:40px; border-radius:50px; border:1px solid rgba(0,0,0,.10); background:#fff; font-size:26px; line-height:1; cursor:pointer; color:#111827; display:flex; align-items:center; justify-content:center; }
        .rm-x:hover{ background: rgba(0,0,0,.04); }
        .rm-x:active{ transform: scale(.98); }
        .rm-content{ padding:14px 16px 6px 16px; max-height:78vh; overflow:auto; }
        .rm-section{ background:#f8fafc; border:1px solid rgba(0,0,0,.06); padding:12px; margin-bottom:12px; }
        .rm-body{ white-space:normal; color:#111827; line-height:1.62; word-break:break-word; }
        .rm-footer{ padding:12px 16px; border-top:1px solid rgba(0,0,0,.08); display:flex; justify-content:flex-end; gap:8px; background:#fff; }

        /* ✅ Comments modal */
        .c-overlay{ position:fixed; inset:0; background:rgba(15,23,42,.58); z-index:99998; display:flex; align-items:center; justify-content:center; padding:14px; backdrop-filter: blur(8px); }
        .c-modal{ width:min(860px, 96vw); max-height:92vh; background:#fff; overflow:hidden; box-shadow:0 28px 90px rgba(0,0,0,.45); animation: cPop .14s ease-out; }
        @keyframes cPop{ from{ opacity:.6; transform:translateY(18px) scale(.985); } to{ opacity:1; transform:translateY(0) scale(1); } }
        .c-topbar{ display:flex; align-items:center; justify-content:space-between; padding:14px 16px; border-bottom:1px solid rgba(0,0,0,.08); background: linear-gradient(135deg, rgba(8,97,188,.14), rgba(17,24,39,.03)); }
        .c-left{ display:flex; align-items:center; gap:12px; min-width:0; }
        .c-avatar{ width:46px; height:46px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-weight:900; font-size:13px; color:#fff; border:2px solid rgba(255,255,255,.95); box-shadow:0 10px 22px rgba(0,0,0,.18), inset 0 1px 0 rgba(255,255,255,.35); background:#111827; position:relative; }
        .c-avatar:after{ content:''; position:absolute; inset:0; border-radius:50%; background: radial-gradient(circle at 30% 25%, rgba(255,255,255,.35), rgba(255,255,255,0) 45%); mix-blend-mode: screen; pointer-events:none; }
        .c-headtxt{ min-width:0; }
        .c-title{ font-weight:950; font-size:18px; color:#9cabca;; line-height:1.25; margin:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:72vw; }
        .c-sub{ margin-top:6px; font-size:12px; color:#6b7280; display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
        .c-author{ font-weight:900; color:#374151; }
        .c-dot{ opacity:.55; }
        .c-x{ width:40px; height:40px; border-radius:50px; border:1px solid rgba(0,0,0,.10); background:#fff; font-size:26px; line-height:1; cursor:pointer; color:#111827; display:flex; align-items:center; justify-content:center; }
        .c-x:hover{ background: rgba(0,0,0,.04); }
        .c-bodywrap{ padding:12px 16px; max-height: calc(50vh - 160px); overflow:auto; background:#fff; }
        .c-list .c-node{ position:relative; --c-avatar-size:38px; }
        .c-list .c-node.has-children::after{ content:""; position:absolute; left:calc(var(--c-avatar-size) / 2); top:calc(var(--c-avatar-size) + 2px); bottom:18px; width:2px; background:rgba(148,163,184,.34); border-radius:999px; }
        .c-list .c-node.has-children.is-collapsed::after{ display:none; }
        .c-list .c-children{ margin-left:calc(var(--c-avatar-size) / 2); padding-left:31px; }
        .c-list .c-children.depth-capped{ margin-left:0; padding-left:0; }
        .c-list .c-node.is-reply::before{ content:""; position:absolute; left:-31px; top:2px; width:31px; height:17px; border-left:2px solid rgba(148,163,184,.34); border-bottom:2px solid rgba(148,163,184,.34); border-bottom-left-radius:18px; }
        .c-list .c-node.is-depth-clamped::before{ display:none; }
        .c-list .cmt{ padding:0; border:0; margin:0 0 12px; background:transparent; }
        .c-list .cmt.is-alert-focus{ background:rgba(96,165,250,.18); border-color:rgba(59,130,246,.34); box-shadow:0 14px 28px rgba(37,99,235,.16); }
        .c-list .c-card{ display:flex; gap:10px; align-items:flex-start; }
        .c-list .c-miniava{ width:38px; height:38px; border-radius:999px; overflow:hidden; flex:0 0 38px; background:#dbeafe; box-shadow:0 6px 18px rgba(15,23,42,.12); }
        .c-list .c-miniava img{ width:100%; height:100%; object-fit:cover; display:block; }
        .c-list .c-main{ min-width:0; flex:1; }
        .c-list .c-bubble{ display:inline-block; max-width:min(100%,560px); background:#d3dde7; border:1px solid rgba(0,0,0,.05); border-radius:18px; padding:11px 14px 12px; }
        .c-list .c-name{ font-size:13px; font-weight:900; color:#20314d; margin-bottom:4px; line-height:1.2; }
        .c-list .cmt .txt{ margin-top:0; white-space:pre-wrap; word-break:break-word; color:#324154; font-size:15px; line-height:1.36; }
        .c-list .c-rowmeta{ display:flex; align-items:center; gap:14px; flex-wrap:wrap; padding-left:8px; margin-top:6px; font-size:12px; color:#64748b; }
        .c-list .c-inlinebtn{ border:0; background:transparent; padding:0; color:inherit; font:inherit; font-weight:800; cursor:pointer; }
        .c-list .c-inlinebtn:hover{ color:#0f172a; }
        .c-list .c-replies-toggle{ color:#2563eb; }
        .c-list .clikebtn.liked{ color:#2563eb; }
        .c-list .c-likepill{ display:inline-flex; align-items:center; gap:6px; padding:2px 10px; border-radius:999px; background:rgba(37,99,235,.12); color:#1d4ed8; font-weight:900; }
        .c-list .c-likepill i{ font-size:12px; }
        .c-footer{ padding:12px 16px; border-top:1px solid rgba(0,0,0,.08); background:#fff; }

        /* ✅ Book caption under media */
        .ig-media-caption{ background:#1f2428; color:#fff; padding:16px 18px; }
        .ig-cap-title{ font-size:24px; font-weight:700; line-height:1.2; }
        .ig-cap-meta{ opacity:.85; margin-top:4px; }
        .ig-cap-text{ opacity:.9; margin-top:10px; }
        .ig-cap-readmore{ display:inline-block; margin-top:12px; color:#c9d4ff; font-weight:600; text-decoration:none; }
        .ig-cap-readmore:hover{ text-decoration:underline; }

        /* =====================================================
           ✅ PRO+ UX UPGRADES (Right Sidebar + Post Viewer)
           ===================================================== */
        .rightSidebarCard{
          border:1px solid rgba(0,0,0,.08);
          /* box-shadow:0 16px 44px rgba(0,0,0,.10); */
          overflow:hidden;
          /* border-radius:14px; */
          height:auto !important; 
          display:flex; flex-direction:column; 
          margin-top:14px;
          border: solid;
          border-color: #d6caca;
        }


        .rightSidebarCard .card-header{
          background: linear-gradient(135deg, rgba(8,97,188,.14), rgba(17,24,39,.02));
          border-bottom:1px solid rgba(0,0,0,.08);
        }
        .rightSidebarBody{
          padding:10px !important;
          background: linear-gradient(180deg, rgba(248,250,252,1), rgba(255,255,255,1));
        }
        .rightSidebarBody .sidebar-tools{
          position:sticky;
          top:0;
          z-index:20;
          backdrop-filter: blur(8px);
          padding-bottom:10px;
          border-bottom:1px solid rgba(0,0,0,.06);
          margin: -2px -2px 10px -2px;
          padding-left:2px;
          padding-right:2px;
        }
        .sidebar-pill{
          display:inline-flex; align-items:center; gap:6px;
          padding:6px 10px;
          border-radius:999px;
          border:1px solid rgba(0,0,0,.10);
          background:#fff;
          box-shadow:0 10px 24px rgba(0,0,0,.06);
          font-size:12px;
          font-weight:700;
          color:#111827;
          user-select:none;
        }
        .sidebar-pill i{ font-size:14px; opacity:.85; }

        .pl-item{
          border:1px solid rgba(0,0,0,.06);
          box-shadow:0 10px 24px rgba(0,0,0,.05);
          margin-bottom:10px;
          transition: transform .08s ease, box-shadow .12s ease, background .12s ease;
        }
        .pl-item:hover{
          transform: translateY(-1px);
          box-shadow:0 16px 34px rgba(0,0,0,.10);
        }
        .pl-item.active{
          border-color: rgba(8,97,188,.35);
          box-shadow:0 18px 38px rgba(8,97,188,.18);
        }
        .pl-chips{ display:flex; flex-wrap:wrap; gap:6px; margin-top:6px; }
        .pl-chip{ font-size:11px; padding:4px 8px; border-radius:999px; background:rgba(17,24,39,.06); color:#374151; border:1px solid rgba(0,0,0,.06); font-weight:700; line-height:1; }
        .pl-chip.new{ background:rgba(255,59,48,.14); color:#b42318; border-color:rgba(255,59,48,.18); }
        .pl-chip.media{ background:rgba(8,97,188,.12); color:#0b4a86; border-color:rgba(8,97,188,.16); }

        .ig-post-card{
          border:1px solid rgba(0,0,0,.08);
          /* box-shadow:0 20px 56px rgba(0,0,0,.10); */
          /* border-radius:16px; */
          display:flex;
          flex-direction:column;
        }
        .ig-post-shell{
          display:flex;
          flex-direction:column;
          gap:0px;
          min-height:0;
        }
        .ig-post-card .card-header{
          background: linear-gradient(135deg, rgba(8,97,188,.10), rgba(17,24,39,.02));
          border-bottom:1px solid rgba(0,0,0,.08);
        }
        .ig-post-footer-card{
          border:1px solid rgba(0,0,0,.08);
          overflow:hidden;
        }
        .ig-post-footer-card .ig-post-footer{
          min-height:80px;
          /* background:#2f343c; */
          box-shadow: inset 0 1px 0 rgba(255,255,255,.03);
          /* padding:18px 22px 16px; */
          margin:0;
          border:0;
        }
        .ig-post-progress{
          position:relative;
          height:4px;
          width:100%;
          margin:0 0 14px;
          border-radius:999px;
          background:rgba(255,255,255,.12);
          overflow:hidden;
        }
        .ig-post-progress-bar{
          width:0%;
          height:100%;
          border-radius:inherit;
          background:linear-gradient(90deg, #60a5fa, #f8fafc);
          transition:none;
        }
        .ig-post-footer-row{
          display:flex;
          align-items:center;
          justify-content:space-between;
          gap:18px;
        }
        .ig-post-footer-row + .ig-post-footer-row{ margin-top:12px; }
        .ig-post-footer-left,
        .ig-post-footer-right{
          display:flex;
          align-items:center;
          gap:26px;
          min-width:0;
        }
        .ig-post-footer-right{ margin-left:auto; }
        .ig-post-footer-act{
          display:inline-flex;
          align-items:center;
          gap:10px;
          color:#f8fafc;
          text-decoration:none;
          font-size:18px;
          font-weight:700;
        }
        .ig-post-footer-act:hover{ color:#fff; text-decoration:none; }
        .ig-post-footer-act i{
          font-size:18px;
          width:18px;
          text-align:center;
        }
        .ig-post-footer-act.rx-love i{ color:var(--msb-love-color, #7c3aed) !important; }
        .ig-post-footer-act.rx-like i{ color:#1d4ed8 !important; }
        .ig-post-footer-act.is-share i{ color:#555555 !important; }
        .ig-post-footer-act.is-save i{ color:#f5c518 !important; }
        .ig-post-footer-link{
          color:#f8fafc;
          text-decoration:none;
          font-size:16px;
          line-height:1.35;
        }
        .ig-post-footer-link:hover{ color:#fff; text-decoration:underline; }
        .ig-post-footer-views{
          text-decoration:none !important;
          white-space:nowrap;
        }
        .ig-actions .btn{
          border-radius:999px;
          font-weight:800;
        }
        .ig-actions #commentCountLink{
          padding:1px 1px;
          border-radius:999px;
          font-size:12;
          /* border:1px solid rgba(0,0,0,.10); */
          /* background:#fff; */
          box-shadow:0 10px 24px rgba(0,0,0,.06);
        }
        .ig-media{
          border-right:1px solid rgba(255,255,255,.08);
        }
        .ig-media .ig-media-main.ig-iframe{
          width:100%;
          height:100%;
          border:0;
          background:#0f1115;
        }
        .ig-file-card{
          width: min(560px, 92%);
          border-radius:16px;
          background: rgba(255,255,255,.10);
          border:1px solid rgba(255,255,255,.20);
          padding:16px;
          color:#fff;
          text-align:left;
          box-shadow: 0 18px 50px rgba(0,0,0,.35);
        }
        .ig-file-title{
          font-weight:900;
          font-size:16px;
          margin:0 0 6px 0;
          word-break:break-word;
        }
        .ig-file-meta{
          opacity:.85;
          font-size:12px;
          margin-bottom:12px;
        }
        .ig-file-actions a{
          display:inline-flex;
          align-items:center;
          gap:8px;
          padding:8px 12px;
          border-radius:999px;
          border:1px solid rgba(255,255,255,.28);
          color:#fff;
          text-decoration:none;
          font-weight:900;
          margin-right:8px;
          background: rgba(255,255,255,.08);
        }
        .ig-file-actions a:hover{ background: rgba(255,255,255,.14); }

        /* ✅ NEW: Full-width media viewer modal */
        .mv-overlay{
          position:fixed; inset:0;
          background:rgba(15,23,42,.72);
          z-index:100000;
          display:flex;
          align-items:center;
          justify-content:center;
          padding:14px;
          backdrop-filter: blur(10px);
        }
        .mv-modal{
          width: min(1400px, 80vw);
          height: min(92vh, 500px);
          background:#0b0f14;
          border:1px solid rgba(255,255,255,.12);
          border-radius:16px;
          overflow:hidden;
          box-shadow:0 30px 90px rgba(0,0,0,.55);
          position:relative;
        }
        .mv-close{
          position:absolute;
          top:12px; right:12px;
          width:42px; height:42px;
          border-radius:999px;
          border:1px solid rgba(255,255,255,.18);
          background:rgba(255,255,255,.08);
          color:#fff;
          font-size:28px;
          line-height:1;
          display:flex;
          align-items:center;
          justify-content:center;
          cursor:pointer;
          z-index:3;
        }
        .mv-close:hover{ background:rgba(255,255,255,.14); }
        .mv-stage{
          width:100%;
          height:100%;
          display:flex;
          align-items:center;
          justify-content:center;
        }
        .mv-content{
          width:100%;
          height:100%;
          display:flex;
          align-items:center;
          justify-content:center;
        }
        .mv-content img,
        .mv-content video,
        .mv-content iframe{
          width:100%;
          height:100%;
          object-fit:contain;
          background:#0b0f14;
        }
        .mv-content iframe{ border:0; }

        .row-sm {
          margin-left: 1%;
          margin-right: 9%;
          margin-top: 5%;
          /* display is controlled by media queries */
        }

        /* =========================================================
           ✅ RESPONSIVE (messages.php style)
           - Desktop: body no scroll, panels scroll safely
           - Smaller screens: body scroll, panels auto height
           - Leftbar becomes off-canvas drawer on <= 991px
        ========================================================= */

        /* --- Desktop / Large --- */
        @media (min-width: 992px){

          html, body{ height:auto; min-height:100%; }
          body{ overflow:auto; }

          .sh-mainpanel{
            height:auto;
            min-height:100vh;
            overflow:visible;
            display:flex;
            flex-direction:column;
          }

          .sh-pagebody{
            flex:1 1 auto;
            min-height:0;
            overflow:visible;
            display:flex;
            flex-direction:column;
            padding-bottom:0 !important;
          }

          .row.row-sm{
            display:flex;
            flex:0 0 auto;
            min-height:0;
            overflow:visible;
          }

          .row.row-sm > .col-lg-8{
            flex:0 0 100%;
            max-width:100%;
            height:auto;
            min-height:0;
            display:flex;
            flex-direction:column;
            overflow:visible;
            padding-right:0;
          }

          .row.row-sm > .col-lg-4{
            display:none !important;
          }

          .row.row-sm > .col-lg-8 .ig-post-shell{
            width:min(100%, 1180px);
            margin:0 auto;
            overflow:visible;
          }

          .row.row-sm > .col-lg-8 #btnFindMe,
          .row.row-sm > .col-lg-8 #btnFindAuthor{
            display:none !important;
          }

          .row.row-sm > .col-lg-8 .ig-post-shell{
            flex:0 0 auto;
            height:auto;
            max-height:none;
            min-height:0;
            display:flex;
            flex-direction:column;
            gap:0;
          }

          .row.row-sm > .col-lg-8 .ig-post-card{
            height:auto;
            max-height:none;
            min-height:0;
            flex:0 0 auto;
            overflow:visible;
          }

          .row.row-sm > .col-lg-8 .ig-post-footer-card{
            flex:0 0 auto;
          }

          .ig-viewer-body{
            height: auto !important;
            flex:0 0 auto;
            min-height:0;
            overflow:visible;
            padding-right:0;
          }

          .ig-layout{ height:auto !important; min-height:0; }
          .ig-media, .ig-details{ min-height:0; }

          /* ✅ prevent media overflow */
          .ig-media-main,
          .ig-media-main img,
          .ig-media-main video,
          .ig-media-main iframe{
            max-width:100%;
            max-height:100%;
          }

          /* Leftbar overlay hidden on desktop */
          #lbOverlay{ display:none !important; }
        }

        /* --- Tablet / Mobile --- */
        @media (max-width: 991.98px){

          html, body{ height:auto; min-height:100%; }
          body{ overflow:auto; }

          .sh-mainpanel{
            min-height:0 !important;
            height:auto !important;
            overflow:visible !important;
          }

          .sh-pagebody{
            overflow:visible !important;
            padding-bottom: 90px !important;
          }

          .row.row-sm{ display:block; }

          /* Viewer stacks */
          .ig-viewer-body{ height:auto !important; }
          
          .ig-layout{
            display:flex !important;
            flex-direction:column !important;
            height:auto !important;
            min-height: 550px;
          }

          .ig-media{
            width:100% !important;
            height: min(46vh, 360px) !important;
            flex:0 0 auto !important;
          }

          .ig-details{
            width:100% !important;
            height:auto !important;
          }

          
          .rightSidebarList{ max-height: 340px; }

          /* show drawer button */
          #btnOpenCommentsDrawer{ display:inline-flex !important; }

          /* ✅ LEFTBAR -> off-canvas drawer (messages.php behavior) */
          .lb-overlay{
            position:fixed;
            inset:0;
            background: rgba(0,0,0,.45);
            z-index: 2000;
          }

          /* Robust selectors for your leftbar wrapper */
          #ttLeftbar, .tt-leftbar, #leftbar, .leftbar{
            position:fixed !important;
            top:0 !important;
            left:0 !important;
            height:100vh !important;
            width: min(420px, 92vw) !important;
            max-width: 92vw !important;
            background:#fff;
            z-index: 2100;
            transform: translateX(-105%);
            transition: transform .2s ease;
            box-shadow: 0 20px 60px rgba(0,0,0,.35);
            overflow:hidden !important;
            display:flex !important;
            flex-direction:column !important;
          }

          body.lb-open #ttLeftbar,
          body.lb-open .tt-leftbar,
          body.lb-open #leftbar,
          body.lb-open .leftbar{
            transform: translateX(0);
          }

          body.lb-open #lbOverlay{ display:block !important; }
        }

        /* --- Small phones --- */
        @media (max-width: 575.98px){
          .card-header{ flex-wrap:wrap; gap:10px; }
          .pv-meta-pills{ width:100%; margin-left:0 !important; }
          .pl-thumb{ width:42px; height:42px; }

          .rightSidebarList{ max-height: 300px; }

          .ig-media{ height: min(44vh, 300px) !important; }

          .ig-topbar, .ig-underbar{ padding-left:12px; padding-right:12px; }

          .card.bd-primary{ margin-left:0 !important; margin-right:0 !important; width:100%}
          .row-sm{ margin-left:0 !important; margin-right:0 !important; }
        }



        /* =========================================================
           ✅ BOOK layout ONLY: Vertical action rail (matches "do it..png")
           - Only active when .ig-viewer-body has .mode-book
           - Non-book posts keep the original horizontal actions
        ========================================================= */
        .ig-details{ position:relative; } /* allow rail absolute positioning */

        .ig-vrail{
          position:absolute;
          right:1px;
          top:100px; /* JS aligns this to #pvBody */
          display:none;
          flex-direction:column;
          gap:18px;
          z-index:5;
          user-select:none;
          pointer-events:auto;
        }
        .ig-viewer-body.mode-book .ig-vrail{ display:none !important; }
        .ig-viewer-body.mode-book .ig-underbar{ display:none !important; } /* hide horizontal bar in book */
        .ig-viewer-body.mode-book #pvBody{ padding-right:0; }
        .ig-viewer-body.mode-book .ig-media{
          background:transparent !important;
        }
        .ig-viewer-body.mode-book .ig-media-main{
          background:transparent !important;
        }
        .ig-viewer-body.mode-book .ig-media-main video{
          background:transparent !important;
        }

        .vrail-btn{
          width:76px;
          border:none;
          background:transparent;
          padding:0;
          cursor:pointer;
          display:flex;
          flex-direction:column;
          align-items:center;
          gap:1px;
          color:inherit;
        }
        .vrail-btn:focus{ outline:none; }
        .vrail-ico{
          width:30px;
          height:30px;
          border-radius:50%;
          display:flex;
          align-items:center;
          justify-content:center;
          /* background:rgb(242 242 242 / 77%); */
          box-shadow: inset 0 0 0 1px rgba(255,255,255,.10);
          font-size:22px;
          line-height:1;
        }
        .vrail-ico i{ opacity:.95; }
        .vrail-count{
          font-weight:800;
          /* font-size:20px; */
          opacity:.95;
          letter-spacing:.2px;
        }
        .vrail-link{ color:inherit; }

        @media (max-width: 991.98px){
          /* On smaller screens keep rail but tighter spacing */
          .ig-viewer-body.mode-book #pvBody{ padding-right:0; }
          .ig-vrail{ right:12px; gap:14px; }
          .vrail-ico{ width:50px; height:50px; font-size:20px; }
          /* .vrail-count{ font-size:18px; } */
        }

        /* =========================================================
           ✅ REEL layout (Mobile TikTok-style)
           - Auto-enabled for single-video posts on small screens (<= 768px),
             or when post declares layout/type = "reel".
           - No card background; video fills viewport area; actions as vertical rail.
        ========================================================= */
        .ig-viewer-body.mode-reel{
          --pv-reel-ar-w: 9;
          --pv-reel-ar-h: 16;
          --pv-reel-side-gap: 24px;
          --pv-reel-top-gap: 18px;
          --pv-reel-bottom-gap: 18px;
          --pv-reel-frame-height: min(900px, max(420px, calc(100dvh - var(--pv-reel-top-gap) - var(--pv-reel-bottom-gap))));
          --pv-reel-frame-width: min(calc(var(--pv-reel-frame-height) * var(--pv-reel-ar-w) / var(--pv-reel-ar-h)), calc(100vw - var(--pv-reel-side-gap)));
          position:relative;
          height:100% !important;
          min-height:0 !important;
          overflow:hidden;
        }
        .ig-viewer-body.mode-reel .ig-layout{
          display:flex !important;
          align-items:stretch !important;
          justify-content:center !important;
          height:100% !important;
          min-height:0 !important;
          position:relative !important;
        }
        .ig-viewer-body.mode-reel .ig-media{
          position:relative;
          background:transparent !important;
          border-radius:0 !important;
          width:min(100%, var(--pv-reel-frame-width)) !important;
          max-width:min(100%, var(--pv-reel-frame-width)) !important;
          height:var(--pv-reel-frame-height) !important;
          min-height:0 !important;
          margin:0 auto !important;
          overflow:hidden !important;
        }
        .ig-viewer-body.mode-reel .ig-media-main{
          width:100% !important;
          height:100% !important;
          object-fit:cover !important;
          border-radius:0 !important;
          background:transparent !important;
        }

        /* Hide strip + underbar (we use rail) */
        .ig-viewer-body.mode-reel .ig-strip{ display:none !important; }
        .ig-viewer-body.mode-reel .ig-underbar{ display:none !important; }

        /* Overlay details (transparent) */
        .ig-viewer-body.mode-reel .ig-details{
          position:absolute !important;
          top:0 !important;
          bottom:0 !important;
          left:50% !important;
          transform:translateX(-50%) !important;
          width:min(100%, var(--pv-reel-frame-width)) !important;
          background:transparent !important;
          border-left:0 !important;
          padding:0 !important;
          pointer-events:none;
        }

        /* Show vertical rail in reel mode */
        .ig-viewer-body.mode-reel .ig-vrail{
          display:none !important;
          position:absolute !important;
          right:12px !important;
          top:auto !important;
          bottom:120px !important;
          gap:16px !important;
          pointer-events:auto;
        }
        .ig-viewer-body.mode-reel .ig-vrail .vrail-btn,
        .ig-viewer-body.mode-reel .ig-vrail .vrail-link{
          color:#fff !important;
        }
        .ig-viewer-body.mode-reel .ig-vrail .vrail-ico{
          background:rgba(255,255,255,.08);
          box-shadow:
            inset 0 0 0 1px rgba(255,255,255,.18),
            0 6px 18px rgba(0,0,0,.20);
        }
        .ig-viewer-body.mode-reel .ig-vrail .vrail-ico i{
          color:#fff !important;
          opacity:1;
          text-shadow:0 2px 10px rgba(0,0,0,.45);
        }
        .ig-viewer-body.mode-reel .ig-vrail .vrail-count{
          color:#fff !important;
          text-shadow:0 2px 10px rgba(0,0,0,.45);
        }
        .ig-viewer-body.mode-reel .ig-vrail .vrail-btn.is-love .vrail-ico i,
        .ig-viewer-body.mode-reel .ig-vrail .vrail-btn.rx-love .vrail-ico i{ color:var(--msb-love-color, #7c3aed) !important; }
        .ig-viewer-body.mode-reel .ig-vrail .vrail-btn.is-like .vrail-ico i,
        .ig-viewer-body.mode-reel .ig-vrail .vrail-btn.rx-like .vrail-ico i{ color:#1d4ed8 !important; }
        .ig-viewer-body.mode-reel .ig-vrail .vrail-btn.is-save .vrail-ico i{ color:#f5c518 !important; }
        .ig-viewer-body.mode-reel .ig-vrail .vrail-btn.is-share .vrail-ico i{ color:#555555 !important; }
        .ig-viewer-body.mode-reel #pvBody{ display:none !important; padding-right:0 !important; }
        .ig-viewer-body.mode-reel #pvTextBelow{ display:none !important; }

        /* Caption overlay */
        .ig-viewer-body.mode-reel #pvCap{
          display:block !important;
          position:absolute !important;
          left:0; right:0; bottom:0;
          padding:16px 14px 22px;
          background: linear-gradient(to top, rgba(0,0,0,.70), rgba(0,0,0,0));
          color:#fff;
          z-index:4;
        }

        .card-footer {
          padding-left: 0.7rem;
          padding-right: 0.7rem;
          padding-top: 0.0rem;
          background-color: rgba(0, 0, 0, 0.03);
          border-top: 1px solid rgba(0, 0, 0, 0.125);
        }
        .ig-viewer-body.mode-reel .ig-cap-title{ color:#fff; font-weight:900; }
        .ig-viewer-body.mode-reel .ig-cap-meta{ color:rgba(255,255,255,.85); }
        .ig-viewer-body.mode-reel .ig-cap-text{ color:rgba(255,255,255,.95); }
        .ig-viewer-body.mode-reel .ig-cap-readmore{ color:#fff; text-decoration:underline; }
        .ig-viewer-body.mode-reel.mode-media-reel-bottom #pvCap{
          left:10px;
          right:76px;
          bottom:62px;
          padding:10px 14px;
          border-radius:16px;
          background:rgba(0,0,0,.72);
          box-shadow:0 10px 24px rgba(0,0,0,.22);
        }
        .ig-viewer-body.mode-reel.mode-media-reel-bottom .ig-cap-meta{
          display:none;
        }
        .ig-viewer-body.mode-reel.mode-media-reel-bottom .ig-cap-title{
          font-size:14px;
          line-height:1.25;
          margin:0 0 4px;
          text-transform:none;
        }
        .ig-viewer-body.mode-reel.mode-media-reel-bottom .ig-cap-text{
          display:inline-block;
          max-width:calc(100% - 56px);
          vertical-align:bottom;
          white-space:nowrap;
          overflow:hidden;
          text-overflow:ellipsis;
          line-height:1.35;
        }
        .ig-viewer-body.mode-reel.mode-media-reel-bottom .ig-cap-text.is-expanded{
          display:inline !important;
          max-width:none !important;
          white-space:normal !important;
          overflow:visible !important;
        }
        .ig-viewer-body.mode-reel.mode-media-reel-bottom .ig-cap-readmore{
          display:inline;
          font-weight:900;
          text-decoration:none;
          white-space:nowrap;
        }
        .ig-viewer-body.mode-reel.mode-media-reel-bottom .ig-cap-title:empty{
          display:none;
        }
        .ig-viewer-body.mode-reel.mode-media-reel-bottom .ig-cap-inline .pv-long-wrap{
          display:flex;
          align-items:flex-end;
          gap:6px;
          padding:0 !important;
        }
        .ig-viewer-body.mode-reel.mode-media-reel-bottom .ig-cap-inline .pv-long-preview{
          flex:1 1 auto;
          min-width:0;
          display:-webkit-box;
          -webkit-box-orient:vertical;
          -webkit-line-clamp:3 !important;
          overflow:hidden;
          line-height:1.35;
          color:rgba(255,255,255,.95);
        }
        .ig-viewer-body.mode-reel.mode-media-reel-bottom .ig-cap-inline .pv-long-preview.is-expanded{
          display:block !important;
          -webkit-line-clamp:unset !important;
          overflow:auto !important;
          max-height:160px;
          padding-right:6px;
        }
        .ig-viewer-body.mode-reel.mode-media-reel-bottom .ig-cap-inline .pv-readmore-inline{
          display:inline-block;
          flex:0 0 auto;
          color:#fff;
          font-weight:900;
          text-decoration:none;
          white-space:nowrap;
        }
        .ig-viewer-body.mode-reel .ig-reel-bottom-desc{
          position:absolute;
          left:10px;
          right:76px;
          bottom:62px;
          z-index:5;
          padding:10px 14px;
          border-radius:16px;
          background:rgba(0,0,0,.72);
          box-shadow:0 10px 24px rgba(0,0,0,.22);
        }
        .ig-viewer-body.mode-reel .ig-reel-bottom-desc .pv-long-wrap{
          /* display:flex; */
          align-items:flex-end;
          gap:6px;
          padding:0 !important;
        }
        .ig-viewer-body.mode-reel .ig-reel-bottom-desc .pv-long-preview{
          flex:1 1 auto;
          min-width:0;
          display:-webkit-box;
          -webkit-box-orient:vertical;
          -webkit-line-clamp:3 !important;
          overflow:hidden;
          line-height:1.35;
          color:rgba(255,255,255,.95);
        }
        .ig-viewer-body.mode-reel .ig-reel-bottom-desc .pv-long-preview.is-expanded{
          display:block !important;
          -webkit-line-clamp:unset !important;
          overflow:auto !important;
          max-height:160px;
          padding-right:6px;
        }
        .ig-viewer-body.mode-reel .ig-reel-bottom-desc .pv-readmore-inline{
          display:inline-block;
          flex:0 0 auto;
          color:#fff;
          font-weight:900;
          text-decoration:none;
          white-space:nowrap;
        }

        /* Remove video controls (mobile reels) */
        .ig-viewer-body.mode-reel video.ig-media-main{ outline:none; }
        .ig-viewer-body.mode-reel video.ig-media-main::-webkit-media-controls{ display:none !important; }
        .ig-viewer-body.mode-reel video.ig-media-main::-webkit-media-controls-enclosure{ display:none !important; }

        @media (max-width: 360px){
          .ig-viewer-body.mode-reel{
            --pv-reel-side-gap: 0px;
            --pv-reel-top-gap: 0px;
            --pv-reel-bottom-gap: 0px;
          }
          .ig-viewer-body.mode-reel .ig-media{
            width:100% !important;
            max-width:100% !important;
            height:100% !important;
          }
          .ig-viewer-body.mode-reel .ig-media-main{
            object-fit:contain !important;
            object-position:center center !important;
          }
          .ig-viewer-body.mode-reel .ig-details{
            width:100% !important;
          }
          .ig-viewer-body.mode-reel .ig-vrail{ bottom:110px !important; right:10px !important; }
          .ig-viewer-body.mode-reel #pvCap{ padding-bottom:24px; }
          .ig-viewer-body.mode-reel.mode-media-reel-bottom #pvCap{
            left:8px;
            right:66px;
            bottom:58px;
            padding:10px 12px;
          }
          .ig-viewer-body.mode-reel .ig-reel-bottom-desc{
            left:8px;
            right:66px;
            bottom:58px;
            padding:10px 12px;
          }
        }
        @media (min-width: 361px) and (max-width: 430px){
          .ig-viewer-body.mode-reel{
            --pv-reel-side-gap: 0px;
            --pv-reel-top-gap: 0px;
            --pv-reel-bottom-gap: 0px;
            --pv-reel-frame-height: 100dvh;
            --pv-reel-frame-width: 100vw;
          }
          .ig-viewer-body.mode-reel .ig-media{
            width:100% !important;
            max-width:100% !important;
            height:100% !important;
          }
          .ig-viewer-body.mode-reel .ig-media-main{
            object-fit:contain !important;
            object-position:center center !important;
          }
          .ig-viewer-body.mode-reel .ig-details{
            width:100% !important;
          }
        }
        @media (min-width: 431px) and (max-width: 575.98px){
          .ig-viewer-body.mode-reel{
            --pv-reel-side-gap: 20px;
            --pv-reel-top-gap: 8px;
            --pv-reel-bottom-gap: 8px;
            --pv-reel-frame-height: min(820px, max(560px, calc(100dvh - var(--pv-reel-top-gap) - var(--pv-reel-bottom-gap))));
          }
          .ig-viewer-body.mode-reel .ig-media-main{
            object-fit:contain !important;
            object-position:center center !important;
          }
        }
        @media (min-width: 576px) and (max-width: 767.98px){
          .ig-viewer-body.mode-reel{
            --pv-reel-side-gap: 24px;
            --pv-reel-top-gap: 16px;
            --pv-reel-bottom-gap: 16px;
            --pv-reel-frame-height: min(760px, max(520px, calc(100dvh - var(--pv-reel-top-gap) - var(--pv-reel-bottom-gap))));
          }
          .ig-viewer-body.mode-reel .ig-media-main{
            object-fit:contain !important;
            object-position:center center !important;
          }
        }
        @media (min-width: 768px) and (max-width: 834px){
          .ig-viewer-body.mode-reel{
            --pv-reel-side-gap: 28px;
            --pv-reel-top-gap: 20px;
            --pv-reel-bottom-gap: 20px;
            --pv-reel-frame-height: min(800px, max(560px, calc(100dvh - var(--pv-reel-top-gap) - var(--pv-reel-bottom-gap))));
          }
          .ig-viewer-body.mode-reel .ig-media-main{
            object-fit:contain !important;
            object-position:center center !important;
          }
        }
        @media (min-width: 835px) and (max-width: 1180px){
          .ig-viewer-body.mode-reel{
            --pv-reel-side-gap: 36px;
            --pv-reel-top-gap: 24px;
            --pv-reel-bottom-gap: 24px;
            --pv-reel-frame-height: min(840px, max(600px, calc(100dvh - var(--pv-reel-top-gap) - var(--pv-reel-bottom-gap))));
          }
          .ig-viewer-body.mode-reel .ig-media-main{
            object-fit:contain !important;
            object-position:center center !important;
          }
        }
        @media (min-width: 1181px) and (max-width: 1440px){
          .ig-viewer-body.mode-reel{
            --pv-reel-side-gap: 42px;
            --pv-reel-top-gap: 24px;
            --pv-reel-bottom-gap: 24px;
            --pv-reel-frame-height: min(860px, max(620px, calc(100dvh - var(--pv-reel-top-gap) - var(--pv-reel-bottom-gap))));
          }
        }
        @media (min-width: 1441px){
          .ig-viewer-body.mode-reel{
            --pv-reel-side-gap: 56px;
            --pv-reel-top-gap: 28px;
            --pv-reel-bottom-gap: 28px;
            --pv-reel-frame-height: min(920px, max(640px, calc(100dvh - var(--pv-reel-top-gap) - var(--pv-reel-bottom-gap))));
          }
        }
        @media (max-height: 780px){
          .ig-viewer-body.mode-reel{
            --pv-reel-frame-height: min(720px, max(400px, calc(100dvh - var(--pv-reel-top-gap) - var(--pv-reel-bottom-gap))));
          }
        }
        @media (min-width: 1025px) and (max-height: 860px){
          .ig-viewer-body.mode-reel{
            --pv-reel-frame-height: min(760px, max(480px, calc(10dvh - var(--pv-reel-top-gap) - var(--pv-reel-bottom-gap))));
          }
        }


      .fa {
          display: inline-block;
          font: normal normal normal 14px / 1 FontAwesome;
          font-size: 10px;
          text-rendering: auto;
          -webkit-font-smoothing: antialiased;
          -moz-osx-font-smoothing: grayscale;
      }

/* =========================================================
   MOBILE/TABLET ONLY: HIDE TOP-RIGHT BACK ARROW + COMMENT ICON
   (targets your real IDs in feed.php: #btnBackPost and #btnOpenCommentsDrawer)
========================================================= */
@media (max-width:1024px){
  #btnOpenCommentsDrawer{
    display:none!important;
    visibility:hidden!important;
    opacity:0!important;
    pointer-events:none!important;
  }
}

</style>
    </div>

    <script>
      const ME_ID = <?= (int)$meId ?>;
      window.ME_ID = ME_ID;
      const STAFF_READONLY = <?= $staffReadonly ? 'true' : 'false' ?>;
      const MSB_VIEWER_CAN_FOLLOW_PUBLISHERS = <?= $canFollowPublishers ? 'true' : 'false' ?>;
      const ME_NAME = <?= json_encode((string)$meDisplayName) ?>;
      const FEED_PIN_POST_ID = <?= (int)$feedAlertPostId ?>;
      const FEED_SEARCH_Q = <?= json_encode($feedSearchQ) ?>;
      const API_URL = <?= json_encode(rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\') . '/feed_api.php') ?>;
      const PCM_FRIES_ICON = <?= json_encode(post_card_menu_fries_icon_html(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

      (function(){
        var selectedId = 0;
        var allItems = [];
        var filteredFeedItems = [];
        var currentMediaIdx = 0;

        var showAllForAuthor = false;
        var showAllForMe     = false;

        var currentFullDesc = '';
        var currentFullBody = '';
        var currentTitle = '';
        var currentDate = '';

        var currentAuthor = '';
        var currentAvatarText = 'P';
        var currentAvatarBg = '#111827';

        var currentOwnerId = 0;

        // ✅ Comments tray (leftbar.php) cache
        var currentCommentsCache = [];
        var currentCommentsPostId = 0;
        var pendingAlertCommentId = <?php echo (int)$feedAlertCommentId; ?>;

        function resolveFeedCommentsPostId(explicitPid){
          var pid = Number(explicitPid || 0);
          if(pid){
            $('#curPostId').val(String(pid));
            selectedId = pid;
            return pid;
          }
          pid = Number($('#curPostId').val() || 0);
          if(pid) return pid;
          try{
            var $a = $('#postList .pl-item.active');
            if($a && $a.length) pid = Number($a.data('id') || 0);
            if(!pid){
              var $f = $('#postList .pl-item').first();
              if($f && $f.length) pid = Number($f.data('id') || 0);
            }
            if(pid){
              $('#curPostId').val(String(pid));
              selectedId = pid;
            }
          }catch(e){}
          return pid;
        }

        function openFeedCommentsTray(explicitPid){
          var pid = resolveFeedCommentsPostId(explicitPid);
          if(!pid || !(window.TTComments && typeof window.TTComments.openForPost === 'function')) return;
          if(window.TTComments.isOpen() && window.TTComments.getPostId() === pid){
            window.TTComments.close();
            return;
          }
          var cached = (Number(currentCommentsPostId) === Number(pid) && Array.isArray(currentCommentsCache))
            ? currentCommentsCache
            : null;
          if(window.TTComments && typeof window.TTComments.clearFocusComment === 'function'){
            window.TTComments.clearFocusComment();
          }
          window.TTComments.openForPost(pid, cached, {
            onLoaded: function(comments){
              comments = Array.isArray(comments) ? comments : [];
              currentCommentsCache = comments;
              currentCommentsPostId = pid;
              $('#commentCount, #commentCountV, #commentCountF, #commentCountTextF').text(String(comments.length || 0));
              syncImageOverlayActions();
              refreshViewerFooter();
              $('.mf-card[data-id="'+pid+'"] .mf-cmt').text(String(comments.length || 0));
            }
          });
        }
        window.openFeedCommentsTray = openFeedCommentsTray;

        function openCommentsTray(){
          openFeedCommentsTray(0);
        }


        var isBackNav = false;
        var navStack = [];
        var suppressAutoOpenOnce = false;
        var desiredSidebarScroll = null;
        var autoAdvanceTimer = null;
        var autoAdvanceStartedAt = 0;
        var autoAdvanceDelay = 0;
        var autoAdvanceRemaining = 0;
        var autoAdvanceHoverHold = false;
        var autoAdvanceProgressRaf = 0;

        var lastVideoEl = null;
        var playback = loadPlayback();

        var AV_COLORS = ['#4f46e5','#0ea5e9','#10b981','#f59e0b','#ef4444','#8b5cf6','#14b8a6','#f43f5e','#6366f1'];


        // =====================================================
        // ✅ BOOK layout toggle (vertical action rail)
        // - Only book layout gets .mode-book (rail)
        // - All other layouts keep original horizontal actions
        // =====================================================
        var isBookMode = false;

        function isInstaFeedCard(){
          try{ return !!document.querySelector('.ig-insta-card'); }catch(e){ return false; }
        }

        function setBookMode(on){
          isBookMode = !!on;
          if(isInstaFeedCard()){
            try{
              $('.ig-viewer-body').removeClass('mode-book').addClass('mode-insta-feed');
            }catch(e){}
            return;
          }
          try{
            $('.ig-viewer-body').toggleClass('mode-book', isBookMode);
          }catch(e){}
          if(isBookMode){
            setTimeout(alignBookRail, 0);
            setTimeout(alignBookRail, 80);
          }
        }

        function alignBookRail(){
          try{
            if(!isBookMode) return;
            var viewer = document.querySelector('.ig-viewer-body');
            var rail   = document.getElementById('bookVrail');
            var bodyEl = document.getElementById('pvBody');
            if(!viewer || !rail || !bodyEl) return;

            var top = bodyEl.offsetTop || 0;
            // rail.style.top = Math.max(80, top) + 'px';
          }catch(e){}
	        }
        

        // =====================================================
        // ✅ REEL layout toggle (mobile TikTok-style)
        // - Enabled for single-video posts on small screens (<= 768px),
        //   or when post declares layout/type = "reel".
        // - Uses existing vertical action rail (#bookVrail).
        // =====================================================
        var isReelMode = false;

        function setReelMode(on){
          if(isInstaFeedCard() && !isSmallScreen()){
            isReelMode = false;
            try{
              $('.ig-viewer-body').removeClass('mode-reel').addClass('mode-insta-feed');
              document.body.classList.remove('mt-reel-only');
            }catch(e){}
            return;
          }
          isReelMode = !!on;
          try{
            $('.ig-viewer-body').toggleClass('mode-reel', isReelMode);
            // ✅ Mobile/Tablet reel-only layout
            try{
              if(isReelMode && isSmallScreen()) document.body.classList.add('mt-reel-only');
              else document.body.classList.remove('mt-reel-only');
            }catch(e){}
          }catch(e){}
          if(isReelMode){
            // Reel and Book are mutually exclusive
            try{ setBookMode(false); }catch(e){}
            setTimeout(function(){
              try{ alignBookRail(); }catch(e){}
            }, 0);
          }
        }

        // ✅ make reel toggle callable from outside (Reel Leftbar)
        window.setReelMode = setReelMode;

        function isSmallScreen(){
          try{
            // ✅ Mobile + Tablet (matches your requirement)
            return window.matchMedia && window.matchMedia('(max-width: 991.98px)').matches;
          }catch(e){
            return (window.innerWidth || 0) <= 992;
          }
        }


        
        // ✅ Ensure reel-only class is removed when leaving mobile/tablet
        window.addEventListener('resize', function(){
          try{
            if(!isSmallScreen()) document.body.classList.remove('mt-reel-only');
          }catch(e){}
        });
// Mirror rail clicks to existing controls (so existing logic stays unchanged)
        function bindBookRail(){
          var pairs = [
            ['btnLoveV','btnLove'],
            ['btnLikeV','btnLike'],
            ['btnSaveV','btnSave'],
            ['btnShareV','btnShare'],
            ['commentCountLinkV','commentCountLink']
          ];
          pairs.forEach(function(p){
            var from = document.getElementById(p[0]);
            var to   = document.getElementById(p[1]);
            if(!from || !to) return;
            from.addEventListener('click', function(e){
              e.preventDefault();
              to.click();
            });
          });

          function syncCounts(){
            var map = [
              ['loveCount','loveCountV'],
              ['likeCount','likeCountV'],
              ['commentCount','commentCountV']
            ];
            map.forEach(function(x){
              var a = document.getElementById(x[0]);
              var b = document.getElementById(x[1]);
              if(a && b) b.textContent = a.textContent;
            });
          }

          syncCounts();
          ['loveCount','likeCount','commentCount'].forEach(function(id){
            var el = document.getElementById(id);
            if(!el) return;
            var ob = new MutationObserver(syncCounts);
            ob.observe(el, { childList:true, subtree:true, characterData:true });
          });

          window.addEventListener('resize', alignBookRail);
        }

        $(document).on('click', '#btnFooterLove', function(e){ e.preventDefault(); $('#btnLove').trigger('click'); });
        $(document).on('click', '#btnFooterLike', function(e){ e.preventDefault(); $('#btnLike').trigger('click'); });
        $(document).on('click', '#btnFooterComment, #btnFooterViewComments', function(e){ e.preventDefault(); $('#commentCountLink').trigger('click'); });
        $(document).on('click', '#btnFooterShare', function(e){ e.preventDefault(); $('#btnShare').trigger('click'); });
        $(document).on('click', '#btnFooterSave', function(e){ e.preventDefault(); $('#btnSave').trigger('click'); });

        // =====================================================
        // ✅ Leftbar Drawer (messages.php style) for <= 991px
        // =====================================================
        function openLeftbar(){
          if(autoAdvanceTimer){
            var elapsed = autoAdvanceStartedAt ? (Date.now() - autoAdvanceStartedAt) : 0;
            autoAdvanceRemaining = Math.max(600, Number(autoAdvanceDelay || 0) - elapsed);
            clearTimeout(autoAdvanceTimer);
            autoAdvanceTimer = null;
          }
          stopAutoAdvanceProgress(true);
          document.body.classList.add('lb-open');
          var ov = document.getElementById('lbOverlay');
          if(ov) ov.style.display = 'block';
        }
        function closeLeftbar(){
          document.body.classList.remove('lb-open');
          var ov = document.getElementById('lbOverlay');
          if(ov) ov.style.display = 'none';
          if(!autoAdvanceHoverHold && selectedId && autoAdvanceRemaining > 0){
            scheduleAutoAdvance(Number(selectedId), autoAdvanceRemaining);
          }
        }

        $(document).on('click', '#btnOpenCommentsDrawer', function(e){
          e.preventDefault();
          openLeftbar();
        });
        $(document).on('click', '#lbOverlay', function(){
          closeLeftbar();
        });
        $(document).on('keydown', function(e){
          if(e.key === 'Escape') closeLeftbar();
        });

        // Close leftbar comments/read-more/menu door when clicking outside it.
        $(document).on('click', function(e){
          var target = e.target;
          if(!target || !target.closest) return;

          var menuWrap = document.getElementById('tt-menu-wrap');
          var commentsWrap = document.getElementById('tt-comments-wrap');
          var readWrap = document.getElementById('tt-readmore-wrap');
          var profileWrap = document.getElementById('tt-profile-wrap');
          var storiesWrap = document.getElementById('tt-stories-wrap');
          var menuOpen = !!(menuWrap && menuWrap.classList.contains('is-open'));
          var commentsOpen = !!(commentsWrap && commentsWrap.classList.contains('is-open'));
          var readOpen = !!(readWrap && readWrap.classList.contains('is-open'));
          var profileOpen = !!(profileWrap && profileWrap.classList.contains('is-open'));
          var storiesOpen = !!(storiesWrap && storiesWrap.classList.contains('is-open'));
          if(!menuOpen && !commentsOpen && !readOpen && !profileOpen && !storiesOpen) return;

          if(target.closest('#tt-menu-wrap, #tt-comments-wrap, #tt-readmore-wrap, #tt-profile-wrap, #tt-stories-wrap, #tt-live-right-wrap, #ttMenuClose, #ttCommentsClose, #ttRmClose, #ttProfileClose, #ttStoriesClose')) return;
          if(target.closest('.js-open-menu-door, .ig-story-item, .js-open-profile-door, .js-open-messages-door, .js-open-notifications-door, .js-open-friend-requests-door, .js-open-live-door, .js-open-live-studio-browse, .js-open-live-software-browse, .js-open-live-right-door, .js-open-comments-door, .js-open-readmore-door, .feed-ig-avatar, .mf-comment, .mf-readmore, #commentCountLink, #commentCountLinkV, #btnViewComments, #btnFooterComment, #btnFooterViewComments, .ig-image-overlay-btn[data-act="comment"], #pvCapReadMore, .ig-cap-readmore, #pvFooterReadMore, #pvInlineReadMore, #btnReadMore, #postList .pl-readmore')) return;

          if(menuOpen){
            if(window.TTMenu && typeof window.TTMenu.close === 'function') window.TTMenu.close();
            else if(menuWrap) menuWrap.classList.remove('is-open');
          }
          if(commentsOpen){
            if(window.TTComments && typeof window.TTComments.close === 'function') window.TTComments.close();
            else if(commentsWrap) commentsWrap.classList.remove('is-open');
          }
          if(readOpen){
            if(window.TTReadMore && typeof window.TTReadMore.close === 'function') window.TTReadMore.close();
            else if(readWrap) readWrap.classList.remove('is-open');
          }
          if(profileOpen){
            if(window.TTProfile && typeof window.TTProfile.close === 'function') window.TTProfile.close();
            else if(profileWrap) profileWrap.classList.remove('is-open');
          }
          var storiesWrapClose = document.getElementById('tt-stories-wrap');
          if(storiesWrapClose && storiesWrapClose.classList.contains('is-open')){
            if(window.TTStories && typeof window.TTStories.close === 'function') window.TTStories.close();
            else storiesWrapClose.classList.remove('is-open');
          }
          var liveRightWrapClose = document.getElementById('tt-live-right-wrap');
          if(liveRightWrapClose && liveRightWrapClose.classList.contains('is-open')){
            if(window.TTLiveRight && typeof window.TTLiveRight.close === 'function') window.TTLiveRight.close();
            else liveRightWrapClose.classList.remove('is-open');
          }
          if(document.body.classList.contains('lb-open')) closeLeftbar();
        });
        $(document).on('click', '.ig-story-item[data-story-key]', function(e){
          e.preventDefault();
          e.stopPropagation();
          var key = String($(this).attr('data-story-key') || '');
          if(!key || !window.TTStories) return;
          window.TTStories.openByKey(key);
        });
        document.addEventListener('visibilitychange', function(){
          if(document.hidden){
            if(autoAdvanceTimer){
              var elapsed = autoAdvanceStartedAt ? (Date.now() - autoAdvanceStartedAt) : 0;
              autoAdvanceRemaining = Math.max(600, Number(autoAdvanceDelay || 0) - elapsed);
              clearTimeout(autoAdvanceTimer);
              autoAdvanceTimer = null;
            }
            stopAutoAdvanceProgress(true);
            pauseLastVideo();
            return;
          }
          if(!document.body.classList.contains('lb-open') && selectedId){
            setTimeout(function(){
              try{ loadPost(Number(selectedId), false); }catch(err){}
            }, 80);
          }
        });

        function esc(s){
          return String(s||'').replace(/[&<>"']/g, function(m){
            return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]);
          });
        }

        function parseMoment(dt){
          dt = String(dt || '').trim();
          if(!dt) return null;
          var formats = [
            moment.ISO_8601,
            'YYYY-MM-DD HH:mm:ss',
            'YYYY-MM-DD HH:mm',
            'YYYY-MM-DDTHH:mm:ss',
            'YYYY-MM-DDTHH:mm:ssZ',
            'YYYY-MM-DDTHH:mm:ss.SSSZ'
          ];
          var m = moment(dt, formats, true);
          if(!m.isValid()) m = moment(dt);
          return m.isValid() ? m : null;
        }
        function isWithin24h(dt){
          var m = parseMoment(dt);
          if(!m) return false;
          return moment().diff(m, 'hours', true) < 24;
        }
        function itemDate(it){
          return (it && (it.updated_at || it.created_at)) ? (it.updated_at || it.created_at) : '';
        }
        function sortFeedItemsByRecent(items){
          return (items || []).slice(0).sort(function(a,b){
            var ad = Date.parse(String(itemDate(a) || '').replace(' ', 'T')) || 0;
            var bd = Date.parse(String(itemDate(b) || '').replace(' ', 'T')) || 0;
            if(bd !== ad) return bd - ad;
            return Number(b.id || 0) - Number(a.id || 0);
          });
        }
        function pinFeedItemFirst(items, postId){
          postId = Number(postId || 0);
          if(!postId) return items || [];
          var list = (items || []).slice(0);
          var idx = -1;
          for(var i = 0; i < list.length; i++){
            if(Number(list[i].id || 0) === postId){ idx = i; break; }
          }
          if(idx <= 0) return list;
          var row = list.splice(idx, 1)[0];
          list.unshift(row);
          return list;
        }
        function postDate(p){
          return (p && (p.updated_at || p.created_at)) ? (p.updated_at || p.created_at) : '';
        }

        function mfDefaultDeviceStyle(isPhoneShot, isTabletShot, label, viewport){
          if(window.MSBDeviceProfile && typeof window.MSBDeviceProfile.defaultStyle === 'function'){
            return window.MSBDeviceProfile.defaultStyle(label || '', !!isPhoneShot, !!isTabletShot, viewport || '') || '';
          }
          if(isPhoneShot) return '--device-ar-w:390;--device-ar-h:844;';
          if(isTabletShot) return '--device-ar-w:834;--device-ar-h:1194;';
          return '';
        }

        function mfDeviceCardMeta(it){
          it = it || {};
          var meta = {};
          if(window.MSBDeviceProfile && typeof window.MSBDeviceProfile.cardMeta === 'function'){
            meta = window.MSBDeviceProfile.cardMeta(it.device_label || '', it.device_viewport || '') || {};
          } else {
            var viewport = String(it.device_viewport || '').trim();
            var m = viewport.match(/^(\d{2,5})x(\d{2,5})/);
            var phoneShot = false;
            var tabletShot = false;
            if(m){
              var w = Number(m[1] || 0);
              var h = Number(m[2] || 0);
              var short = Math.min(w, h);
              var long = Math.max(w, h);
              if(short <= 480 && (long / Math.max(short, 1)) >= 1.2) phoneShot = true;
              else if(short > 480 && short < 900) tabletShot = true;
            }
            if(!phoneShot && /iphone|android phone|pixel/i.test(String(it.device_label || ''))) phoneShot = true;
            if(!phoneShot && !tabletShot && /ipad|android tablet|samsung tablet|tablet/i.test(String(it.device_label || ''))) tabletShot = true;
            meta = {
              phone_shot: phoneShot,
              tablet_shot: tabletShot,
              style: m ? ('--device-ar-w:' + m[1] + ';--device-ar-h:' + m[2] + ';') : '',
              label: String(it.device_label || '').trim(),
              viewport: viewport
            };
          }
          if(typeof it.phone_shot !== 'undefined'){
            meta.phone_shot = !!Number(it.phone_shot);
          }
          if(typeof it.tablet_shot !== 'undefined'){
            meta.tablet_shot = !!Number(it.tablet_shot);
          }
          if(meta.phone_shot) meta.tablet_shot = false;
          if(!String(meta.style || '').trim() && String(it.device_style || '').trim()){
            meta.style = String(it.device_style || '').trim();
          }
          if(!String(meta.style || '').trim()){
            meta.style = mfDefaultDeviceStyle(!!meta.phone_shot, !!meta.tablet_shot, meta.label || String(it.device_label || ''), meta.viewport || String(it.device_viewport || ''));
          }
          return meta;
        }

        function mfParseDeviceAspectFromStyle(style){
          style = String(style || '');
          var mw = style.match(/--device-ar-w:\s*(\d+)/);
          var mh = style.match(/--device-ar-h:\s*(\d+)/);
          if(!mw || !mh) return null;
          return { w: Number(mw[1] || 0), h: Number(mh[1] || 0) };
        }

        function mfGetDeviceDimensions(card){
          if(!card) return null;
          var mediaEl = card.querySelector('.mf-media.media-stage, .mf-media');
          var fromStyle = mfParseDeviceAspectFromStyle(mediaEl ? mediaEl.getAttribute('style') : '');
          if(fromStyle && fromStyle.w > 0 && fromStyle.h > 0) return fromStyle;
          var dw = Number(card.getAttribute('data-device-w') || 0);
          var dh = Number(card.getAttribute('data-device-h') || 0);
          if(dw > 0 && dh > 0) return { w: dw, h: dh };
          return null;
        }

        function mfMaxVideoHeight(){
          var viewportH = Math.max(window.innerHeight || 0, 320);
          if(window.matchMedia('(max-width: 767.98px)').matches){
            return Math.max(viewportH - 220, 280);
          }
          return Math.min(Math.round(viewportH * 0.78), 960);
        }

        function mfInitialMediaCardStyleFromDims(dims, isPhoneShot){
          if(!dims || !Number(dims.w || 0) || !Number(dims.h || 0)) return '';
          var aspectW = Number(dims.w || 0);
          var aspectH = Number(dims.h || 0);
          var aspect = aspectW / aspectH;
          var maxVideoH = mfMaxVideoHeight();
          var feed = document.querySelector('.mf-feed');
          var feedWidth = feed ? Math.floor(feed.clientWidth || 0) : Math.min(Math.max(window.innerWidth || 0, 320), 680);
          var availableWidth = Math.max(280, feedWidth || 680);
          var desiredWidth = Math.round(aspect * maxVideoH);
          var maxByShape = aspect < 0.8 ? 520 : (aspect > 1.15 ? 760 : 620);
          if(isPhoneShot && window.matchMedia('(max-width: 767.98px)').matches) maxByShape = 430;
          var safeWidth = Math.max(280, Math.min(desiredWidth, availableWidth, maxByShape));
          if(aspect >= 0.8 && aspect <= 1.15) safeWidth = Math.min(availableWidth, Math.max(safeWidth, 420));
          if(aspect > 1.15) safeWidth = Math.min(availableWidth, Math.max(safeWidth, 560));
          return '--post-media-card-width:'+String(safeWidth)+'px;width:min(100%,'+String(safeWidth)+'px);max-width:100%;margin-left:auto;margin-right:auto;padding:8px 40px;box-sizing:border-box;';
        }

        function mfClearDeviceCardWidth(card){
          if(!card) return;
          card.style.width = '';
          card.style.maxWidth = '';
          card.style.marginLeft = '';
          card.style.marginRight = '';
          card.style.padding = '';
          card.style.boxSizing = '';
          try{ card.style.removeProperty('--post-media-card-width'); }catch(e){}
        }

        // Match profile.php applyPublicMediaCardWidth for feed cards.
        function mfApplyPublicVideoCardWidth(card, aspectW, aspectH){
          if(!card) return;
          aspectW = Number(aspectW || 0);
          aspectH = Number(aspectH || 0);
          if(!aspectW || !aspectH) return;

          var media = card.querySelector('.media-stage.standard-video-stage, .media-stage.standard-image-stage');
          var video = card.querySelector('.media-stage.standard-video-stage > video');
          var image = card.querySelector('.media-stage.standard-image-stage > img');

          var viewportH = Math.max(window.innerHeight || 0, 320);
          var maxVideoH = window.matchMedia('(max-width: 767.98px)').matches
            ? Math.max(viewportH - 220, 280)
            : Math.min(Math.round(viewportH * 0.78), 960);

          var aspect = aspectW / aspectH;
          var feed = card.closest('.mf-feed');
          var feedWidth = feed ? Math.floor(feed.clientWidth) : Math.round(aspect * maxVideoH);
          var availableWidth = Math.max(280, feedWidth);
          var desiredWidth = Math.round(aspect * maxVideoH);
          var maxByShape = aspect < 0.8 ? 520 : (aspect > 1.15 ? 760 : 620);
          if(card.classList.contains('mf-card-phone-shot') && window.matchMedia('(max-width: 767.98px)').matches) maxByShape = 430;
          var safeWidth = Math.max(280, Math.min(desiredWidth, availableWidth, maxByShape));
          if(aspect >= 0.8 && aspect <= 1.15) safeWidth = Math.min(availableWidth, Math.max(safeWidth, 420));
          if(aspect > 1.15) safeWidth = Math.min(availableWidth, Math.max(safeWidth, 560));

          card.style.width = String(safeWidth) + 'px';
          card.style.maxWidth = '100%';
          card.style.marginLeft = 'auto';
          card.style.marginRight = 'auto';
          card.style.setProperty('box-sizing', 'border-box', 'important');
          card.style.setProperty('padding', card.querySelector('.mf-head--on-media') ? '8px 40px' : '20px', 'important');
          card.style.setProperty('--post-media-card-width', String(safeWidth) + 'px');

          if(media){
            media.style.width = '100%';
            media.style.maxWidth = '100%';
            media.style.height = 'auto';
            media.style.aspectRatio = '';
            media.style.background = 'transparent';
            media.style.removeProperty('overflow');
            media.style.marginLeft = '';
            media.style.marginRight = '';
          }
          if(video){
            video.style.width = '100%';
            video.style.height = 'auto';
            video.style.maxHeight = '';
            video.style.objectFit = 'contain';
            video.style.background = 'transparent';
            video.style.removeProperty('padding');
          }
          if(image){
            image.style.width = '100%';
            image.style.height = 'auto';
            image.style.objectFit = 'contain';
            image.style.background = 'transparent';
            image.style.removeProperty('padding');
            image.style.removeProperty('box-sizing');
            image.style.removeProperty('max-height');
          }
        }

        function mfInitialMediaAspect(it, deviceDims){
          if(deviceDims && deviceDims.w > 0 && deviceDims.h > 0) return deviceDims;
          var shape = String((it && it.media_shape) || '').trim();
          if(shape === 'single-portrait') return { w: 9, h: 16 };
          if(shape === 'single-landscape') return { w: 16, h: 9 };
          if(shape === 'single-square') return { w: 1, h: 1 };
          return null;
        }

        function mfPreflightSingleMediaCard(card, it){
          if(!card) return;
          if(card.classList && card.classList.contains('is-single-video-post')) return;
          var dims = mfGetDeviceDimensions(card) || mfInitialMediaAspect(it, null);
          if(!dims || !dims.w || !dims.h) return;
          mfApplyPublicVideoCardWidth(card, dims.w, dims.h);
          var media = card.querySelector('.media-stage.standard-video-stage, .media-stage.standard-image-stage');
          if(media && card.classList.contains('is-single-image-post')){
            media.classList.add('mf-media-sized');
          }
        }

        function mfCountsFromListItem(it){
          it = it || {};
          return {
            comment_count: Number(it.comment_count || 0),
            like_count: Number(it.like_count || 0),
            love_count: Number(it.love_count || 0),
            share_count: Number(it.share_count || 0),
            save_count: Number(it.save_count || 0),
            my_reaction: String(it.my_reaction || ''),
            is_saved: Number(it.my_saved || 0),
            is_shared: Number(it.my_shared || 0)
          };
        }

        function mfApplyListItemCounts($card, it){
          $card = $card && $card.jquery ? $card : $($card);
          if(!$card.length || !it) return;
          mfHydrateCard(Number(it.id || 0), null, mfCountsFromListItem(it), []);
        }

        function mfAfterSingleCardMediaSync($card){
          $card = $card && $card.jquery ? $card : $($card);
          if(!$card.length) return;
          mfApplyMediaShape($card);
          bindFeedReelAspect($card);
          bindMfStandardMediaCardSizing($card[0]);
        }

        function mfHydrateMultiMediaCards(items){
          items = Array.isArray(items) ? items : [];
          if(!items.length) return;
          var index = 0;
          var active = 0;
          var maxConcurrent = 2;

          function pump(){
            while(active < maxConcurrent && index < items.length){
              (function(it){
                active += 1;
                $.getJSON(API_URL, { ajax:'view', id: it.id, count_view:0, lite:1 }, function(res){
                  active -= 1;
                  if(res && res.ok){
                    var $card = $('.mf-card[data-id="'+Number(it.id)+'"]');
                    mfHydrateCard(it.id, res.post || {}, mfCountsFromListItem(it), res.attachments || []);
                    mfRemountFollowOnCard($card, mfMergeListItemAuthorMeta(it, res.post || {}, $card));
                    mfAfterSingleCardMediaSync($card);
                  }
                  pump();
                }).fail(function(){
                  active -= 1;
                  pump();
                });
              })(items[index]);
              index += 1;
            }
          }
          pump();
        }

        function mfDebounce(fn, wait){
          var timer = null;
          return function(){
            var ctx = this;
            var args = arguments;
            if(timer) clearTimeout(timer);
            timer = setTimeout(function(){
              timer = null;
              fn.apply(ctx, args);
            }, wait);
          };
        }

        function mfMediaShapeClass(it){
          var attCount = Number((it && it.attachment_count) || 0);
          if(attCount !== 1) return '';
          var shape = String((it && it.media_shape) || '').trim();
          if(shape === 'single-portrait' || shape === 'single-landscape' || shape === 'single-square') return shape;
          return '';
        }

        function mfBuildMediaClassList(opts){
          opts = opts || {};
          var classes = ['mf-media', 'media-stage'];
          if(!opts.standardVideo && !opts.standardImage){
            if(opts.shapeClass) classes.push(opts.shapeClass);
            else if(opts.isSingleMedia) classes.push('single-square');
          }
          if(opts.isPhoneShot && opts.isSingleMedia && window.matchMedia('(max-width: 767.98px)').matches) classes.push('phone-shot');
          if(opts.standardVideo) classes.push('standard-video-stage');
          if(opts.standardImage) classes.push('standard-image-stage');
          if(opts.isMultiMedia) classes.push('has-carousel', 'js-media-carousel');
          return classes.join(' ');
        }

        function mfShapeFromDimensions(w, h){
          w = Number(w || 0);
          h = Number(h || 0);
          if(w <= 0 || h <= 0) return 'square';
          if(h > w * 1.1) return 'portrait';
          if(w > h * 1.15) return 'landscape';
          return 'square';
        }

        function mfClearMediaShapeClasses($media){
          if(!$media || !$media.length) return;
          $media.removeClass('single-portrait single-landscape single-square phone-shot');
        }

        function mfApplyMediaShape($card){
          $card = $card && $card.jquery ? $card : $($card);
          if(!$card.length) return;
          var $media = $card.find('.mf-media').first();
          if(!$media.length) return;
          if(Number($media.data('shape-ready')) === 1) return;
          if($media.hasClass('phone-shot') && window.matchMedia('(max-width: 767.98px)').matches) return;
          if($media.hasClass('phone-shot') && !window.matchMedia('(max-width: 767.98px)').matches){
            $media.removeClass('phone-shot');
          }
          if($media.hasClass('standard-video-stage') || $media.hasClass('standard-image-stage')) return;

          function apply(w, h){
            mfClearMediaShapeClasses($media);
            var shape = mfShapeFromDimensions(w, h);
            $media.addClass('single-' + (shape === 'portrait' ? 'portrait' : shape === 'landscape' ? 'landscape' : 'square'));
          }

          var $img = $media.find('img').first();
          var $video = $media.find('video').first();
          if($img.length){
            var imgEl = $img[0];
            if(imgEl.complete && imgEl.naturalWidth) apply(imgEl.naturalWidth, imgEl.naturalHeight);
            else $img.one('load', function(){ apply(this.naturalWidth, this.naturalHeight); });
          } else if($video.length){
            var videoEl = $video[0];
            if(videoEl.videoWidth) apply(videoEl.videoWidth, videoEl.videoHeight);
            else $video.one('loadedmetadata', function(){ apply(this.videoWidth, this.videoHeight); });
          }
        }

        function mfSyncAllCardMediaShapes($root){
          ($root || $('#mfFeed')).find('.mf-card').each(function(){
            mfApplyMediaShape($(this));
          });
        }

        function mfDeviceTimeLabel(it, dt){
          return timeAgoShort(dt) || fmtDateShort(dt) || String(dt || '').slice(0, 16);
        }

        function getSidebarScroll(){
          try{
            var el = document.querySelector('.rightSidebarList');
            return el ? Number(el.scrollTop || 0) : 0;
          }catch(e){ return 0; }
        }
        function setSidebarScroll(v){
          try{
            var el = document.querySelector('.rightSidebarList');
            if(el) el.scrollTop = Number(v || 0);
          }catch(e){}
        }

        function getFeedScrollEl(){
          try{
            return document.getElementById('feedPostScrollCol')
              || document.querySelector('.row.row-sm > .col-lg-8.feedViewerCol')
              || document.querySelector('.row.row-sm > .col-lg-8');
          }catch(e){ return null; }
        }
        function resetFeedScrollTop(){
          try{
            var el = getFeedScrollEl();
            if(!el) return;
            var prev = el.style.scrollBehavior;
            el.style.scrollBehavior = 'auto';
            el.scrollTop = 0;
            el.style.scrollBehavior = prev;
          }catch(e){}
        }

        function getSidebarState(){
          return {
            postId: Number(selectedId || 0),
            scrollTop: Number(getSidebarScroll() || 0),
            filter: String($('#filterSel').val() || 'all'),
            search: String($('#searchBox').val() || ''),
            showAllForAuthor: !!showAllForAuthor,
            showAllForMe: !!showAllForMe
          };
        }

        function applySidebarState(st){
          if(!st) return;
          $('#filterSel').val(st.filter || 'all');
          $('#searchBox').val(st.search || '');
          showAllForAuthor = !!st.showAllForAuthor;
          showAllForMe     = !!st.showAllForMe;
        }

        function normalizeKey(str){
          return String(str || '').trim().replace(/\s+/g, ' ');
        }
        function initialsFromName(name){
          name = normalizeKey(name);
          if(!name) return '?';
          var parts = name.split(' ');
          if(parts.length === 1) return parts[0].substring(0,2).toUpperCase();
          return (parts[0].charAt(0) + parts[parts.length - 1].charAt(0)).toUpperCase();
        }
        function colorFromString(str){
          str = normalizeKey(str);
          var hash = 0;
          for(var i=0;i<str.length;i++){
            hash = ((hash << 5) - hash) + str.charCodeAt(i);
            hash |= 0;
          }
          var idx = (hash >>> 0) % AV_COLORS.length;
          return AV_COLORS[idx];
        }
        function gradientFromBase(base){
          return 'radial-gradient(circle at 30% 25%, rgba(255,255,255,.35), rgba(255,255,255,0) 40%), linear-gradient(135deg,' + base + ', #111827)';
        }
        function peerLabel(displayName, username){
          var dn = normalizeKey(displayName);
          var un = normalizeKey(username);
          return dn || un || 'User';
        }
        function loadPlayback(){
          try{
            var raw = localStorage.getItem('pu_feed_playback') || '{}';
            var obj = JSON.parse(raw);
            if(!obj || typeof obj !== 'object') return {};
            return obj;
          }catch(e){ return {}; }
        }
        function savePlayback(){
          try{ localStorage.setItem('pu_feed_playback', JSON.stringify(playback || {})); }catch(e){}
        }

        function updateUnreadBadge(n){
          n = Number(n||0);
          var $b = $('#unreadBadge');
          if(n > 0) $b.text('Unread: ' + n).show();
          else $b.hide();
        }

        function countUnreadSidebarItems(items){
          return (items || []).filter(function(it){
            return Number(it && it.is_unread) === 1;
          }).length;
        }

        // ✅ NEW: Update LEFT post pills like screenshot
        function updateViewerPills(hasMedia, attCount, dt){
          try{
            attCount = Number(attCount || 0);

            var m = parseMoment(dt);
            var ago = (m && m.isValid()) ? m.fromNow() : 'Just now';

            $('#pvTimeAgo').text(timeAgoShort(dt));
            $('#pvTimeAgoPill').text(ago);
            $('#pvMetaPills').hide();
          }catch(e){
            $('#pvMetaPills').hide();
          }
        }

        function refreshViewerFooter(){
          var text = (String(currentFullBody || '').trim().length > 0)
            ? String(currentFullBody || '').trim()
            : String(currentFullDesc || '').trim();
          var preview = firstSentences(text, 3);
          var hasMore = !!text && preview !== text;
          $('#pvFooterDesc').text(preview || '');
          $('#pvFooterReadMore').toggle(hasMore);
          $('#commentCountTextF').text($('#commentCount').text() || '0');
          $('#viewCountTextF').text(String($('#viewCount').text() || '0') + ' views');
        }

        // ✅ Read more modal
        function openReadMore(){
          var content = (String(currentFullBody||'').trim().length>0) ? String(currentFullBody||'') : String(currentFullDesc||'');
          content = formatReadMoreTextPreserve(content);
          if (window.TTReadMore && typeof window.TTReadMore.toggle === 'function') {
            return window.TTReadMore.toggle({
              title: currentTitle || '',
              author: currentAuthor || '',
              date: currentDate || '',
              avatarText: currentAvatarText || 'P',
              avatarBg: currentAvatarBg || '#111827',
              avatarUrl: avatarUrlFor(window.__curPost || {}, 96),
              body: content || ''
            });
          }

          // Fallback: keep old modal if leftbar not loaded
          $('#rmTitleText').text(currentTitle || 'Post');
          $('#rmAuthor').text(currentAuthor || '');
          $('#rmDate').text(currentDate || '');
          $('#rmAvatar').html('<img src="' + esc(avatarUrlFor(window.__curPost || {}, 96)) + '" alt="' + esc(currentAuthor || 'Profile') + '">').css('background', 'transparent');

          var safe = (typeof pvFormatRichText === 'function') ? pvFormatRichText(content) : esc(content).replace(/\n/g, '<br>');
          $('#rmBody').html(safe);
          setTimeout(function(){ try{ document.getElementById('rmCloseX').focus(); }catch(e){} }, 0);
          return true;
        }
        function closeReadMore(){ $('#rmOverlay').css('display','none'); }

        function openFeedReadMoreTray(trigger){
          trigger = trigger || null;
          if(trigger){
            var $card = $(trigger).closest('.mf-card');
            if($card.length){
              var $bodyHost = $(trigger).closest('.mf-body, .mf-reel-body, .mf-video-body');
              var bodyText = $bodyHost.length ? String($bodyHost.attr('data-full') || '') : String($card.attr('data-full-desc') || '');
              mfOpenReadMoreDrawer($card, bodyText);
              return;
            }
          }
          openReadMore();
        }
        window.openFeedReadMoreTray = openFeedReadMoreTray;

        $(document).on('click', '#rmCloseX, #rmCloseBtn', closeReadMore);
        $(document).on('click', '#rmOverlay', function(e){ if(e.target && e.target.id === 'rmOverlay') closeReadMore(); });
        $(document).on('keydown', function(e){ if(e.key === 'Escape') closeReadMore(); });

        function sentenceCount(txt){
          txt = String(txt||'').trim();
          if(!txt) return 0;
          // split by sentence enders; keep it simple and reliable
          var parts = txt.split(/[\.\!\?]+/g).map(function(s){ return String(s||'').trim(); }).filter(function(s){ return s.length > 0; });
          return parts.length;
        }

        // ✅ Read-me logic: show ONLY when there are >= 4 sentences
        function applyReadMeForText(fullText){
          var txt = String(fullText||'').trim();
          var btn = document.getElementById('btnReadMore');
          if(!btn) return;

          var n = sentenceCount(txt);
          btn.style.display = (n >= 4) ? 'inline-block' : 'none';
        }

        // Backward-compatible call (older parts call applyReadMore(desc))
        function applyReadMore(desc){
          // prefer long body if present, else desc
          var bodyTxt = String(currentFullBody || '').trim();
          var descTxt = String(desc || currentFullDesc || '').trim();
          var pick = bodyTxt.length ? bodyTxt : descTxt;
          applyReadMeForText(pick);
        }


        var modalCommentsPostId = 0;
        var modalCommentsCache = [];
        var modalCommentsByParent = {};
        var modalCollapsedReplyIds = new Set();
        var modalMaxReplyCurveDepth = 4;
        function modalReplyActionLabel(depth){
          return depth >= modalMaxReplyCurveDepth ? 'Comment' : 'Reply';
        }
        function modalReplyToggleLabel(count, isOpen){
          var noun = count === 1 ? 'reply' : 'replies';
          return isOpen ? 'Close replies' : ('Open ' + count + ' ' + noun);
        }

        // ✅ COMMENTS MODAL
        function openCommentsModal(){
          var pid = Number($('#curPostId').val() || 0);
          if(!pid) return;

          $('#cPostId').val(String(pid));
          $('#cParentId').val('0');
          $('#cCommentText').val('');
          $('#cCommentText').attr('placeholder', 'Write a comment...');
          $('#cCancelReply').hide();
          $('#cReplyingTo').hide().text('');

          $('#cTitleText').text((currentTitle || 'Post') + ' — Comments');
          $('#cAuthor').text(currentAuthor || '');
          $('#cDate').text(currentDate || '');
          $('#cAvatar').html('<img src="' + esc(avatarUrlFor(window.__curPost || {}, 96)) + '" alt="' + esc(currentAuthor || 'Profile') + '">').css('background', 'transparent');

          $('#cOverlay').css('display','flex');
          loadCommentsForModal(pid);

          setTimeout(function(){
            try{ document.getElementById('cCommentText').focus(); }catch(e){}
          }, 0);
        }

        function closeCommentsModal(){
          $('#cOverlay').css('display','none');
          $('#cParentId').val('0');
          $('#cCommentText').attr('placeholder', 'Write a comment...');
          $('#cCancelReply').hide();
          $('#cReplyingTo').hide().text('');
        }

        function loadCommentsForModal(postId){
          postId = Number(postId || 0);
          if(!postId) return;
          if(postId !== modalCommentsPostId){
            modalCommentsPostId = postId;
            modalCollapsedReplyIds.clear();
          }

          $('#cCommentsList').html('<div class="text-muted">Loading comments...</div>');

          $.getJSON(API_URL, { ajax:'view', id:postId, post_id:postId, count_view:0 }, function(res){
            if(!res || !res.ok){
              $('#cCommentsList').html('<div class="text-muted">Unable to load comments.</div>');
              return;
            }

            try{
              var counts = res.counts || {};
              $('#likeCount, #likeCountV').text(String(counts.like_count || 0));
              $('#loveCount, #loveCountV').text(String(counts.love_count || 0));
              setReactionButtons(counts.my_reaction || '');
            }catch(e){}

            renderCommentsModal(res.comments || []);
          });
        }

        function renderCommentsModal(comments){
          var $c = $('#cCommentsList');
          $c.empty();
          modalCommentsCache = Array.isArray(comments) ? comments : [];

          if(!modalCommentsCache.length){
            $c.append('<div class="text-muted">No comments yet. Be the first.</div>');
            $('#commentCount, #commentCountV, #commentCountF, #commentCountTextF').text('0');
            return;
          }

          var byId = {};
          modalCommentsCache.forEach(function(x){ byId[x.id]=x; x._replies=[]; });

          var roots = [];
          modalCommentsCache.forEach(function(x){
            var pid = Number(x.parent_id||0);
            if(pid && byId[pid]) byId[pid]._replies.push(x);
            else roots.push(x);
          });
          modalCommentsByParent = {};
          Object.keys(byId).forEach(function(id){
            modalCommentsByParent[Number(id)] = Array.isArray(byId[id]._replies) ? byId[id]._replies : [];
          });
          function annotateReplyDepth(node, depth, cappedAncestorId){
            var nextCappedAncestorId = (depth === modalMaxReplyCurveDepth - 1) ? Number(node.id || 0) : cappedAncestorId;
            node._reply_target_id = (depth >= modalMaxReplyCurveDepth && cappedAncestorId > 0) ? cappedAncestorId : Number(node.id || 0);
            node._reply_action_label = modalReplyActionLabel(depth);
            node._replies.forEach(function(child){ annotateReplyDepth(child, depth + 1, nextCappedAncestorId); });
          }
          roots.forEach(function(root){ annotateReplyDepth(root, 0, 0); });

          function cmtHtml(x, depth){
            var who = (x.display_name || x.username || '').toString();
            var when = timeAgo(x.created_at) || (x.created_at||'').toString().slice(0,16).replace("T"," ");
            var liked = Number(x.me_liked || 0) === 1;
            var likeCount = Number(x.like_count || 0);
            var myReaction = String(x.my_reaction || '');
            var reactionLabel = (window.MSBReactions && typeof window.MSBReactions.label === 'function')
              ? window.MSBReactions.label(myReaction || 'love')
              : (myReaction ? myReaction : 'Love');
            var avatar = avatarUrlFor(x, 72);
            var kids = Array.isArray(x._replies) ? x._replies : [];
            var childrenHtml = kids.map(function(rep){ return cmtHtml(rep, depth + 1); }).join('');
            var replyCount = kids.length;
            var repliesOpen = !modalCollapsedReplyIds.has(Number(x.id || 0));
            var depthClamped = depth > modalMaxReplyCurveDepth;
            var childDepthCapped = (depth + 1) > modalMaxReplyCurveDepth;
            var replyActionLabel = String(x._reply_action_label || modalReplyActionLabel(depth));
            var replyTargetId = Number(x._reply_target_id || x.id || 0);

            return ''
              + '<div class="c-node'+(depth > 0 ? ' is-reply' : '')+(replyCount > 0 ? ' has-children' : '')+(replyCount > 0 && !repliesOpen ? ' is-collapsed' : '')+(depthClamped ? ' is-depth-clamped' : '')+'" data-cid="'+esc(x.id)+'">'
              + '  <div class="cmt" data-cid="'+esc(x.id)+'">'
              + '    <div class="c-card">'
              + '      <div class="c-miniava"><img src="'+esc(avatar)+'" alt="'+esc(who)+'"></div>'
              + '      <div class="c-main">'
              + '        <div class="c-bubble">'
              + '          <div class="c-name">'+esc(who)+'</div>'
              + '          <div class="txt">'+esc(x.comment_text||'')+'</div>'
              + '        </div>'
              + '        <div class="c-rowmeta">'
              + '          <span>'+esc(when)+'</span>'
              + '          <button type="button" class="c-inlinebtn clikebtn'+(liked ? ' liked' : '')+'" data-heart="'+esc(x.id)+'" data-reaction="'+esc(myReaction)+'"><i class="fa fa-heart-o"></i><span data-reaction-label>'+esc(liked ? reactionLabel : 'Love')+'</span></button>'
              + '          <button type="button" class="c-inlinebtn replybtn" data-reply="'+esc(replyTargetId)+'" data-name="'+esc(who)+'" data-mode="'+esc(replyActionLabel)+'">'+esc(replyActionLabel)+'</button>'
              +            (replyCount > 0 ? ('<button type="button" class="c-inlinebtn c-replies-toggle" data-toggle-replies="'+esc(x.id)+'">'+esc(modalReplyToggleLabel(replyCount, repliesOpen))+'</button>') : '')
              +            (likeCount > 0 ? ('<span class="c-likepill"><i class="fa fa-thumbs-up"></i><span>'+esc(String(likeCount))+'</span></span>') : '')
              + '        </div>'
              + '      </div>'
              + '    </div>'
              + '  </div>'
              +      (replyCount > 0 && repliesOpen ? ('<div class="c-children'+(childDepthCapped ? ' depth-capped' : '')+'">'+childrenHtml+'</div>') : '')
              + '</div>';
          }

          roots.forEach(function(r){
            $c.append(cmtHtml(r,0));
          });

          $('#commentCount').text(String(comments.length));

          $c.find('[data-reply]').off('click').on('click', function(){
            var cid = Number($(this).attr('data-reply')||0);
            var name = String($(this).attr('data-name')||'').trim();
            var mode = String($(this).attr('data-mode') || 'Reply');
            var isCommentMode = mode === 'Comment';
            $('#cParentId').val(String(cid));
            $('#cCancelReply').show();
            $('#cReplyingTo').text((isCommentMode ? 'Commenting on: ' : 'Replying to: ') + (name || 'comment')).show();
            $('#cCommentText').attr('placeholder', (isCommentMode ? 'Comment on ' : 'Reply to ') + (name || 'comment'));
            $('#cCommentText').focus();
          });
          $c.find('[data-toggle-replies]').off('click').on('click', function(){
            var cid = Number($(this).attr('data-toggle-replies') || 0);
            if(!cid) return;
            if(modalCollapsedReplyIds.has(cid)) modalCollapsedReplyIds.delete(cid);
            else modalCollapsedReplyIds.add(cid);
            renderCommentsModal(modalCommentsCache);
          });
          $c.find('[data-heart]').off('click').on('click', function(){
            var cid = Number($(this).attr('data-heart') || 0);
            var pid = Number($('#cPostId').val() || 0);
            var currentReaction = String($(this).attr('data-reaction') || '');
            if(!pid || !cid) return;
            if(currentReaction === 'love') return;
            $.post(API_URL, { ajax:'comment_like', post_id:pid, comment_id:cid, reaction:'love' }, function(res){
              if(!res || !res.ok) return;
              if(window.TTComments && typeof window.TTComments.refreshCurrent === 'function'){
                window.TTComments.refreshCurrent();
              }else{
                loadCommentsForModal(pid);
              }
            }, 'json');
          });
          if(window.MSBReactions){
            $c.find('.clikebtn').each(function(){
              window.MSBReactions.applyReactionButton(this, $(this).attr('data-reaction') || '', 'love');
            });
          }

          if(pendingAlertCommentId > 0){
            setTimeout(function(){
              if(focusAlertCommentRow(pendingAlertCommentId)){
                pendingAlertCommentId = 0;
              }
            }, 0);
          }
        }

        function focusAlertCommentRow(commentId){
          commentId = Number(commentId || 0);
          if(!commentId) return false;
          var $rows = $('#cCommentsList .cmt');
          $rows.removeClass('is-alert-focus');
          var $row = $('#cCommentsList .cmt[data-cid="'+commentId+'"]').first();
          if(!$row.length) return false;
          $row.addClass('is-alert-focus');
          try{
            var el = $row.get(0);
            if(el && el.scrollIntoView) el.scrollIntoView({ behavior:'smooth', block:'center' });
          }catch(e){}
          return true;
        }

        if(window.MSBReactions){
          window.MSBReactions.bindLikePicker('.clikebtn', function(btn, reaction){
            var cid = Number($(btn).attr('data-heart') || 0);
            var pid = Number($('#cPostId').val() || 0);
            if(!pid || !cid || !reaction) return;
            if(String($(btn).attr('data-reaction') || '') === String(reaction)) return;
            $.post(API_URL, { ajax:'comment_like', post_id:pid, comment_id:cid, reaction:reaction }, function(res){
              if(!res || !res.ok) return;
              if(window.TTComments && typeof window.TTComments.refreshCurrent === 'function'){
                window.TTComments.refreshCurrent();
              }else{
                loadCommentsForModal(pid);
              }
            }, 'json');
          });
        }

        window.loadFeedAlertPost = function(postId){
          loadPost(Number(postId || 0), false);
        };
        window.openFeedAlertComments = function(commentId){
          pendingAlertCommentId = Number(commentId || 0);
          openCommentsModal();
        };
        window.focusFeedAlertComment = function(commentId){
          return focusAlertCommentRow(commentId);
        };

        
        // -----------------------------
        // ✅ Viewers overlay (owner only)
        // -----------------------------
        function closeViewersOverlay(){
          $('#vwOverlay').hide();
        }

        function renderViewersList(viewers){
          viewers = viewers || [];
          var $list = $('#vwList');
          $list.empty();

          if(!viewers.length){
            $('#vwEmpty').show();
            return;
          }
          $('#vwEmpty').hide();

          viewers.forEach(function(v){
            var dn = peerLabel(v.display_name, v.username);
            var un = String(v.username || '').trim();
            var when = String(v.viewed_at || '').replace('T',' ').slice(0,16);

            var avatarUrl = avatarUrlFor(v, 80);

            var $row = $('<div class="vw-item"></div>');
            var $left = $('<div class="vw-left"></div>');
            var $av = $('<div class="vw-av"></div>').append($('<img>', {src: avatarUrl, alt: dn || un || 'User'}));
            var $nmWrap = $('<div style="min-width:0;"></div>');
            var $nm = $('<div class="vw-name"></div>').text(dn);
            var $us = $('<div class="vw-user"></div>').text(un ? ('@' + un) : '');
            $nmWrap.append($nm).append($us);
            $left.append($av).append($nmWrap);

            var $when = $('<div class="vw-when"></div>').text(when);

            $row.append($left).append($when);
            $list.append($row);
          });
        }

        function openViewersOverlay(){
          var post = window.__curPost || {};
          var pid  = Number(post.id || selectedId || 0);
          if(!pid) return;

          // owner only
          if(Number(post.user_id || currentOwnerId || 0) !== Number(ME_ID || 0)){
            return;
          }

          // reset
          $('#vwTotal,#vw7d,#vw30d').text('0');
          $('#vwList').empty();
          $('#vwEmpty').hide();

          $('#vwOverlay').css('display','flex');

          // stats
          $.getJSON(API_URL, { ajax:'view_stats', post_id: pid, days: 30 }, function(res){
            if(!res || !res.ok) return;
            $('#vwTotal').text(String(res.views_total || 0));
            $('#vw7d').text(String(res.views_7d || 0));
            $('#vw30d').text(String(res.views_30d || 0));
          });

          // viewers list
          $.getJSON(API_URL, { ajax:'viewers', post_id: pid, limit: 200 }, function(res){
            if(!res || !res.ok) { $('#vwEmpty').show(); return; }
            // in case cached total differs, prefer API
            if(typeof res.views_count !== 'undefined'){
              $('#vwTotal').text(String(res.views_count || 0));
            }
            renderViewersList(res.viewers || []);
          });
        }

        // Click views icon/count to open the viewers overlay (owner only)
        $(document).on('click', '#viewCountLink, #viewCountLinkV', function(e){
          e.preventDefault();
          openViewersOverlay();
        });
        $(document).on('click', '#vwClose', closeViewersOverlay);
        $(document).on('click', '#vwOverlay', function(e){
          if(e.target && e.target.id === 'vwOverlay') closeViewersOverlay();
        });
        $(document).on('keydown', function(e){
          if(e.key === 'Escape') closeViewersOverlay();
        });


        $(document).on('click', '#btnViewComments, #commentCountLink, #commentCountLinkV', function(e){
          e.preventDefault();
          openCommentsTray();
        });
        $(document).on('click', '#cCloseX', closeCommentsModal);
        $(document).on('click', '#cOverlay', function(e){
          if(e.target && e.target.id === 'cOverlay') closeCommentsModal();
        });
        $(document).on('keydown', function(e){
          if(e.key === 'Escape') closeCommentsModal();
        });
        $('#cCancelReply').on('click', function(){
          $('#cParentId').val('0');
          $('#cCommentText').attr('placeholder', 'Write a comment...');
          $('#cCancelReply').hide();
          $('#cReplyingTo').hide().text('');
        });
        $('#cCommentForm').on('submit', function(e){
          e.preventDefault();

          var pid = Number($('#cPostId').val() || 0);
          if(!pid) return;

          var txt = $('#cCommentText').val();
          if(!txt || !txt.trim()) return;

          $.post(API_URL, {
            ajax:'comment',
            post_id: pid,
            parent_id: Number($('#cParentId').val() || 0),
            comment_text: txt
          }, function(res){
            if(!res || !res.ok) return;

            $('#cCommentText').val('');
            $('#cParentId').val('0');
            $('#cCommentText').attr('placeholder', 'Write a comment...');
            $('#cCancelReply').hide();
            $('#cReplyingTo').hide().text('');

            loadCommentsForModal(pid);
            loadPost(pid, false);
            refreshList(true);
          }, 'json');
        });

        /* =====================================================
           ✅ NEW: FULL-WIDTH MEDIA MODAL (Eye icon)
           ===================================================== */
        function closeMediaModal(){
          $('#mvOverlay').hide();
          try{
            var v = document.querySelector('#mvContent video');
            if(v){ v.pause(); v.currentTime = 0; }
          }catch(e){}
          $('#mvContent').empty();
        }

        function detectKindFromSrc(src){
          var clean = String(src||'').split('?')[0].split('#')[0].toLowerCase();
          if(clean.match(/\.(mp4|webm|ogg)$/)) return 'video';
          if(clean.match(/\.(jpg|jpeg|png|gif|webp|bmp|svg)$/)) return 'image';
          if(clean.match(/\.pdf$/)) return 'pdf';
          return 'file';
        }

        function openMediaModal(src, kind){
          src = String(src||'').trim();
          if(!src) return;

          kind = String(kind||'').toLowerCase().trim();
          if(!kind) kind = detectKindFromSrc(src);

          var $ct = $('#mvContent');
          $ct.empty();

          if(kind === 'video'){
            $ct.html('<video src="'+esc(src)+'" controls autoplay playsinline></video>');
          } else if(kind === 'image'){
            $ct.html('<img src="'+esc(src)+'" alt="">');
          } else if(kind === 'pdf'){
            $ct.html('<iframe src="'+esc(src)+'" title="PDF"></iframe>');
          } else {
            window.open(src, '_blank', 'noopener');
            return;
          }

          $('#mvOverlay').css('display','flex');
          setTimeout(function(){ try{ document.getElementById('mvClose').focus(); }catch(e){} }, 0);
        }

        $(document).on('click', '#mvClose', function(e){ e.preventDefault(); closeMediaModal(); });
        $(document).on('click', '#mvOverlay', function(e){
          if(e.target && e.target.id === 'mvOverlay') closeMediaModal();
        });
        $(document).on('keydown', function(e){
          if(e.key === 'Escape') closeMediaModal();
        });

        /* ========= (Your existing renderList/applySearchFilter/etc stays the same below) ========= */
        /* ---- THE REST OF YOUR ORIGINAL JS CONTINUES UNCHANGED ---- */

        function appendOlderDivider($pl){
          $pl.append(
            '<div class="px-2 pt-2 pb-1 text-uppercase text-muted" style="font-size:11px;letter-spacing:.10em;">Older Posts</div>' +
            '<div class="px-2"><div style="height:1px;background:rgba(0,0,0,.10);"></div></div>'
          );
        }

        function renderList(items){
          var $pl = $('#postList');
          $pl.empty();

          if(!items || !items.length){
            $pl.append(mfFeedEmptyHtml());
            try{ renderMobileFeed(items || []); }catch(e){}
            return;
          }

          var filter = String($('#filterSel').val() || 'all');

          // Right sidebar rows must disappear after 24 hours.
          // Only items already inside the 24-hour window are rendered here.
          var fresh = (items || []).filter(function(it){
            return isWithin24h(itemDate(it));
          });

          function renderOne(it){
            var active = (Number(it.id) === Number(selectedId)) ? ' active' : '';
            var badge = (Number(it.is_unread) === 1) ? ' <span class="badge badge-new ml-1">NEW</span>' : '';
            var authorText = peerLabel(it.display_name, it.username);

            var canDel  = !STAFF_READONLY && ((Number(it.user_id||0) === Number(ME_ID||0)) || (Number(it.can_delete||0) === 1));
            var canEdit = !STAFF_READONLY && ((Number(it.user_id||0) === Number(ME_ID||0)) || (Number(it.can_edit||0) === 1));

            var mediaCount = Number(it.media_count || it.attachments_count || it.has_media || 0);
            var hasMediaChip = (mediaCount > 0);
            var chipNew = (Number(it.is_unread) === 1) ? '<span class="pl-chip new">NEW</span>' : '';
            var chipMedia = hasMediaChip ? '<span class="pl-chip media"><i class="fa fa-paperclip mr-1"></i>'+mediaCount+'</span>' : '';

            var moreId = 'plMore_' + String(it.id);
            var menu = '';
            menu += '<div class="dropdown pl-more-wrap">';
            menu += '  <a class="btn btn-xs btn-light pl-more" type="button" id="'+esc(moreId)+'" data-toggle="dropdown" data-display="static" aria-haspopup="true" aria-expanded="false" title="More">';
            menu += '    <i class="fa fa-ellipsis-v"></i>';
            menu += '  </a>';
            menu += '  <div class="dropdown-menu dropdown-menu-right pl-dropdown-menu" aria-labelledby="'+esc(moreId)+'">';

            if(canEdit){
              menu += '    <a class="dropdown-item pl-edit" href="dashboard.php?modal=1&edit='+esc(it.id)+'" data-id="'+esc(it.id)+'" data-create-post-modal="1" title="Edit Post" aria-label="Edit Post"><i class="fa fa-pencil"></i> Edit</a>';
            }

            var msgHref = '';
            if(Number(it.user_id||0) > 0 && Number(it.user_id||0) !== Number(ME_ID||0)){
              var fc = String(it.friend_code||'').trim();
              msgHref = (fc !== '') ? ('messages.php?peer=' + fc.toUpperCase()) : ('messages.php?id=' + String(it.user_id||''));

              menu += '    <a class="dropdown-item pl-msg" href="'+esc(msgHref)+'" data-user-id="'+esc(it.user_id)+'"><i class="fa fa-envelope mr-2"></i>Message</a>';
            }

            if(canDel){
              menu += '    <div class="dropdown-divider"></div>';
              menu += '    <a class="dropdown-item text-danger pl-del" href="#" data-id="'+esc(it.id)+'"><i class="fa fa-trash mr-2"></i>Delete</a>';
            }

            menu += '  </div>';
            menu += '</div>';

            var avatarUrl = avatarUrlFor(it, 96);
            var thumb = '<div class="pl-thumb"><img src="'+esc(avatarUrl)+'" alt="'+esc(authorText)+'"></div>';

            var html = ''
              + '<div class="pl-item'+active+'" data-id="'+esc(it.id)+'" data-unread="'+esc(it.is_unread)+'">'
              + menu
              + '  <div class="d-flex">'
              + '    <div class="mr-2">'+thumb+'</div>'
              + '    <div class="flex-grow-1">'
              + '      <p class="pl-title">'+esc(it.title || '')+badge+'</p>'
                            + '      <div class="pl-chips">'+(chipNew||'')+(chipMedia||'')+'</div>'
              + '      <div class="pl-row-meta mt-1">'
              // + '        <a href="#" class="pl-readmore" data-id="'+esc(it.id)+'">Read more</a>'
              + '        <div class="pl-row-meta-right">'
              + '          <small class="text-muted">'+esc(authorText)+'</small>'
              + '          <small class="text-muted">'+esc((it.updated_at || it.created_at || '').toString().slice(0,16).replace("T"," "))+'</small>'
              + '        </div>'
              + '      </div>'
              + '    </div>'
              + '  </div>'
              + '</div>';

            $pl.append(html);
          }

          fresh.forEach(renderOne);
        
          // Keep the Friends Feed card list stable on navigation.
          // The old viewer opens only when a user selects a post or an alert deep-link requests it.

          // ✅ Also paint mobile/tablet cards
          try{ renderMobileFeed(items); }catch(e){}
}
        // =========================================================
        // MOBILE/TABLET: Vertical post cards (separate from desktop)
        // =========================================================
        function mfIsMobile(){
          return true;
        }
        function mfSentenceCount(text){
          text = String(text||'').trim();
          if(!text) return 0;
          var parts = text.split(/[.!?]+/).map(function(s){return s.trim();}).filter(Boolean);
          return parts.length;
        }
        function mfTruncate(text, maxSent){
          text = String(text||'').trim();
          if(!text) return { short:'', full:'', truncated:false };
          var parts = text.split(/([.!?]+\s+)/); // keep separators
          // Rebuild sentences
          var out = '';
          var sent = 0;
          for(var i=0;i<parts.length;i++){
            out += parts[i];
            if(/[.!?]+\s*$/.test(parts[i]) || /[.!?]+\s+/.test(parts[i])){
              // heuristic: count when we see punctuation separator chunk
            }
          }
          // Fallback simpler:
          var sents = text.split(/[.!?]+/).map(function(s){return s.trim();}).filter(Boolean);
          if(sents.length <= maxSent) return { short:text, full:text, truncated:false };
          var short = sents.slice(0, maxSent).join('. ') + '.';
          return { short:short, full:text, truncated:true };
        }
        function formatPostCardTextHtml(text){
          text = formatReadMoreTextPreserve(text);
          if(!text) return '';
          return text.split(/\n\s*\n/).map(function(block){
            block = block.trim();
            if(!block) return '';
            var lines = block.split(/\n/).map(function(line){
              return esc(String(line || '').trim());
            }).filter(Boolean).join('<br>');
            return '<p class="post-card-paragraph">'+lines+'</p>';
          }).filter(Boolean).join('');
        }
        function mfBuildBodyHtml(className, text, maxSent){
          className = String(className || 'mf-body');
          text = formatReadMoreTextPreserve(String(text || '').trim());
          if(!text) return '';
          var sc = mfSentenceCount(text);
          var formatted = formatPostCardTextHtml(text);
          if(sc >= maxSent){
            return '<div class="'+esc(className)+' mf-body-has-more" data-full="'+esc(text)+'" data-expanded="0">'+
                     '<div class="mf-body-formatted is-clamped">'+formatted+'</div>'+
                     '<a href="#" class="mf-readmore js-open-readmore-door">Read more</a>'+
                   '</div>';
          }
          return '<div class="'+esc(className)+'"><div class="mf-body-formatted">'+formatted+'</div></div>';
        }
        function firstSentences(text, maxSent){
          text = String(text || '').trim();
          maxSent = Number(maxSent || 3);
          if(!text) return '';
          var t = mfTruncate(text, maxSent);
          return t.short || text;
        }
        function mfAvatarInit(name){
          name = String(name||'').trim();
          if(!name) return '?';
          var words = name.split(/\s+/).filter(Boolean);
          var a = (words[0]||'')[0]||'?';
          var b = (words.length>1 ? (words[1]||'')[0] : (words[0]||'')[1]) || '';
          return (a+b).toUpperCase();
        }
        function mfAvatarColor(key){
          // reuse your existing hashing color if available
          try{
            var k = normalizeKey(key);
            var h = 0;
            for (var i=0;i<k.length;i++){ h = ((h<<5)-h) + k.charCodeAt(i); h |= 0; }
            var hue = Math.abs(h) % 360;
            return 'hsl(' + hue + ', 70%, 45%)';
          }catch(e){
            return '#0861bc';
          }
        }

        function syncFeedReelAspect(video){
          try{
            if(!video) return;
            var vw = Number(video.videoWidth || 0);
            var vh = Number(video.videoHeight || 0);
            var card = video.closest ? video.closest('.mf-card.mf-card-reel') : null;
            if(!card) return;
            if(card.classList.contains('mf-card-phone-shot')) return;
            if(vw <= 0 || vh <= 0) return;

            var viewportH = Math.max(window.innerHeight || 0, 320);
            var maxVideoH = window.matchMedia('(max-width: 767.98px)').matches
              ? Math.max(viewportH - 220, 280)
              : Math.min(Math.round(viewportH * 0.78), 960);

            var targetWidth = Math.round((vw / vh) * maxVideoH);
            var feed = card.closest('.mf-feed');
            var feedWidth = feed ? Math.floor(feed.clientWidth) : targetWidth;
            var sideGap = 100;
            var safeWidth = Math.max(280, Math.min(targetWidth, feedWidth - (sideGap * 2)));

            card.style.setProperty('--mf-reel-ar-w', String(vw));
            card.style.setProperty('--mf-reel-ar-h', String(vh));
            card.style.paddingLeft = String(sideGap) + 'px';
            card.style.paddingRight = String(sideGap) + 'px';
            card.style.setProperty('width', '100%', 'important');
            card.style.setProperty('max-width', '100%', 'important');
            card.style.setProperty('margin-left', 'auto', 'important');
            card.style.setProperty('margin-right', 'auto', 'important');
            card.style.boxSizing = 'border-box';

            var media = card.querySelector('.mf-media');
            if(media){
              media.style.width = String(safeWidth) + 'px';
              media.style.maxWidth = '100%';
              media.style.marginLeft = 'auto';
              media.style.marginRight = 'auto';
              media.style.aspectRatio = String(vw) + ' / ' + String(vh);
              media.style.minHeight = '0';
              media.style.background = 'transparent';
            }

            video.style.width = '100%';
            video.style.height = '100%';
            video.style.maxHeight = String(maxVideoH) + 'px';
            video.style.objectFit = 'contain';
            video.style.objectPosition = 'center center';
            video.style.background = 'transparent';
          }catch(e){}
        }

        function syncFeedReelFit(video){
          try{
            if(!video) return;
            var card = video.closest ? video.closest('.mf-card.mf-card-reel') : null;
            if(!card) return;
            if(card.classList.contains('mf-card-phone-shot')){
              video.style.objectFit = 'contain';
              video.style.objectPosition = 'center center';
              return;
            }
            if(Number(video.videoWidth || 0) > 0 && Number(video.videoHeight || 0) > 0){
              video.style.objectFit = 'contain';
              video.style.objectPosition = 'center center';
              return;
            }
            var isPhoneOrTablet = window.matchMedia('(max-width: 1180px)').matches;
            video.style.objectFit = isPhoneOrTablet ? 'contain' : 'cover';
            video.style.objectPosition = 'center center';
          }catch(e){}
        }

        function syncAllFeedReelAspects(){
          try{
            document.querySelectorAll('.js-mf-reel-video').forEach(function(video){
              syncFeedReelAspect(video);
              syncFeedReelFit(video);
            });
          }catch(e){}
        }

        function syncMfStandardMediaCard(el){
          try{
            if(!el) return;
            var card = el.closest('.mf-card.is-single-video-post, .mf-card.is-single-image-post');
            if(!card) return;

            var w = 0;
            var h = 0;
            if(String(el.tagName || '').toUpperCase() === 'VIDEO'){
              w = Number(el.videoWidth || 0);
              h = Number(el.videoHeight || 0);
            } else {
              w = Number(el.naturalWidth || 0);
              h = Number(el.naturalHeight || 0);
            }
            if(!w || !h) return;

            var stage = el.closest('.media-stage.standard-video-stage, .media-stage.standard-image-stage');
            if(stage) stage.classList.add('mf-media-sized');
            if(card.classList.contains('is-single-video-post')) card.classList.add('mf-video-ready');
            if(card.classList.contains('is-single-image-post')) card.classList.add('mf-image-ready');
            mfApplyPublicVideoCardWidth(card, w, h);
          }catch(e){}
        }

        function syncMfStandardVideoCard(video){
          syncMfStandardMediaCard(video);
        }

        function syncMfStandardImageCard(img){
          syncMfStandardMediaCard(img);
        }

        function mfResetNonPhoneCardWidths(scope){
          try{
            var root = (scope && scope.jquery) ? scope[0] : (scope || document);
            if(!root || !root.querySelectorAll) return;
            root.querySelectorAll('.mf-card:not(.mf-card-phone-shot):not(.is-single-video-post):not(.is-single-image-post)').forEach(function(card){
              mfClearDeviceCardWidth(card);
            });
          }catch(e){}
        }

        function mfPrimeStandardFeedVideo(video){
          if(!video) return;
          var stage = video.closest('.media-stage.standard-video-stage');
          var reveal = function(){
            var card = video.closest('.mf-card.is-single-video-post');
            if(card) card.classList.add('mf-video-error');
          };
          try{
            if(video.getAttribute('preload') === 'none'){
              video.setAttribute('preload', 'metadata');
              video.load();
            }
          }catch(e){}
          video.addEventListener('error', reveal, { once:true });
        }

        function bindMfStandardMediaCardSizing(scope){
          try{
            var root = (scope && scope.jquery) ? scope[0] : (scope || document);
            if(!root || !root.querySelectorAll) return;

            var videoSelector = '.mf-card.is-single-video-post .media-stage.standard-video-stage > video';
            Array.prototype.forEach.call(root.querySelectorAll(videoSelector), function(video){
              var sync = function(){
                syncMfStandardVideoCard(video);
                var stage = video.closest('.media-stage.standard-video-stage');
                if(stage) stage.classList.add('mf-media-sized');
              };
              if(video.dataset.mfStandardMediaSized === '1'){
                if(video.readyState >= 1) sync();
                return;
              }
              video.dataset.mfStandardMediaSized = '1';
              mfPrimeStandardFeedVideo(video);
              video.addEventListener('loadedmetadata', sync);
              video.addEventListener('loadeddata', sync);
              video.addEventListener('resize', sync);
              if(video.readyState >= 1) sync();
            });

            var imageSelector = '.mf-card.is-single-image-post .media-stage.standard-image-stage > img';
            Array.prototype.forEach.call(root.querySelectorAll(imageSelector), function(img){
              var sync = function(){ syncMfStandardImageCard(img); };
              if(img.dataset.mfStandardMediaSized === '1'){
                if(img.complete && img.naturalWidth) sync();
                return;
              }
              img.dataset.mfStandardMediaSized = '1';
              img.addEventListener('load', sync);
              img.addEventListener('error', function(){
                var card = img.closest('.mf-card.is-single-image-post');
                if(card) card.classList.add('mf-image-error');
              }, { once:true });
              if(img.complete && img.naturalWidth) sync();
            });
          }catch(e){}
        }

        function bindMfStandardVideoCardSizing(scope){
          bindMfStandardMediaCardSizing(scope);
        }

        function bindMfStandardImageCardSizing(scope){
          bindMfStandardMediaCardSizing(scope);
        }

        function bindMfDeviceImageCardSizing(scope){
          bindMfStandardMediaCardSizing(scope);
        }

        function syncAllMfStandardMediaCards(){
          try{
            document.querySelectorAll('.mf-card.is-single-video-post .media-stage.standard-video-stage > video').forEach(function(video){
              syncMfStandardVideoCard(video);
            });
            document.querySelectorAll('.mf-card.is-single-image-post .media-stage.standard-image-stage > img').forEach(function(img){
              syncMfStandardImageCard(img);
            });
          }catch(e){}
        }

        function syncAllMfStandardVideoCards(){
          syncAllMfStandardMediaCards();
        }

        function bindFeedReelAspect(scope){
          try{
            var root = (scope && scope.jquery) ? scope[0] : (scope || document);
            if(!root) return;
            var videos = root.querySelectorAll ? root.querySelectorAll('.js-mf-reel-video') : [];
            Array.prototype.forEach.call(videos, function(video){
              if(video.dataset.reelAspectBound === '1'){
                if(video.readyState >= 1){
                  syncFeedReelAspect(video);
                  syncFeedReelFit(video);
                }
                return;
              }
              video.dataset.reelAspectBound = '1';
              var sync = function(){
                syncFeedReelAspect(video);
                syncFeedReelFit(video);
              };
              video.addEventListener('loadedmetadata', sync);
              video.addEventListener('loadeddata', sync);
              video.addEventListener('resize', sync);
              if(video.readyState >= 1) sync();
            });
          }catch(e){}
        }

        window.addEventListener('resize', mfDebounce(function(){
          try{
            document.querySelectorAll('.js-mf-reel-video').forEach(function(video){
              syncFeedReelFit(video);
            });
            syncAllMfStandardVideoCards();
            syncAllFeedReelAspects();
            var viewerVideo = document.querySelector('.js-pv-reel-video');
            if(viewerVideo) syncViewerReelFit(viewerVideo);
          }catch(e){}
        }, 150));

        function mfFeedHasPendingMedia($wrap){
          try{
            var pending = false;
            $wrap.find('.mf-card.is-single-video-post, .mf-card.is-single-image-post').each(function(){
              var card = this;
              if(!card || pending) return;
              if(card.classList.contains('mf-video-error') || card.classList.contains('mf-image-error')) return;
              if(card.classList.contains('is-single-video-post') && !card.classList.contains('mf-video-ready')) pending = true;
              if(card.classList.contains('is-single-image-post') && !card.classList.contains('mf-image-ready')) pending = true;
            });
            return pending;
          }catch(e){
            return false;
          }
        }

        function mfRevealFeedAfterPaint($wrap){
          var attempts = 0;
          function tick(){
            attempts += 1;
            try{
              syncAllMfStandardMediaCards();
              syncAllFeedReelAspects();
            }catch(e){}
            if(!mfFeedHasPendingMedia($wrap) || attempts >= 18){
              $wrap.removeClass('mf-hydrating');
              mfRelocateFollowButtons($wrap);
              if(window.MSBPostCardMenu){
                try{
                  if(typeof window.MSBPostCardMenu.refreshFeedCardMenus === 'function'){
                    window.MSBPostCardMenu.refreshFeedCardMenus($wrap[0] || $wrap);
                  }
                  if(typeof window.MSBPostCardMenu.hydrate === 'function'){
                    window.MSBPostCardMenu.hydrate($wrap[0] || $wrap);
                  }
                  if(typeof window.MSBPostCardMenu.syncOnMediaContrast === 'function'){
                    window.MSBPostCardMenu.syncOnMediaContrast($wrap[0] || $wrap);
                  }
                }catch(e){}
              }
              if(window.__MSBThemeCore && typeof window.__MSBThemeCore.refreshPalettePaint === 'function'){
                window.__MSBThemeCore.refreshPalettePaint();
              }
              return;
            }
            requestAnimationFrame(tick);
          }
          try{
            requestAnimationFrame(tick);
          }catch(e){
            $wrap.removeClass('mf-hydrating');
          }
        }

        function mfFeedEmptyHtml(){
          return ''
            + '<div class="mf-feed-empty" role="status">'
            + '  <i class="icon ion-ios-paper-outline" aria-hidden="true"></i>'
            + '  <div class="mf-feed-empty-title">No Feed Available</div>'
            + '</div>';
        }

        function renderMobileFeed(items){
          if(!mfIsMobile()) return;
          var $wrap = $('#mfFeed');
          if(!$wrap.length) return;
          $wrap.addClass('mf-hydrating');
          $wrap.empty();

          if(!items || !items.length){
            $wrap.append(mfFeedEmptyHtml());
            mfRevealFeedAfterPaint($wrap);
            return;
          }

          // Limit to avoid too many per-card calls
          var maxCards = 30;
          var slice = items.slice(0, maxCards);

          for(var i=0;i<slice.length;i++){
            $wrap.append(mfRenderCardShell(slice[i]));
            try{
              var $card = $wrap.children('.mf-card').last();
              if($card.length){
                mfMountFollowOnMedia($card, slice[i]);
                mfPreflightSingleMediaCard($card[0], slice[i]);
                mfApplyListItemCounts($card, slice[i]);
              }
            }catch(e){}
          }

          mfSyncAllCardMediaShapes($wrap);
          bindFeedReelAspect($wrap);
          mfResetNonPhoneCardWidths($wrap);
          bindMfStandardVideoCardSizing($wrap);
          bindMfDeviceImageCardSizing($wrap);
          mfRevealFeedAfterPaint($wrap);
          mfRelocateFollowButtons($wrap);

          // Multi-media posts still need attachment details; single-media counts come from list API.
          setTimeout(function(){
            mfLoadFriendStates();
            var multiMediaItems = slice.filter(function(it){
              return Number(it.attachment_count || 0) > 1;
            });
            mfHydrateMultiMediaCards(multiMediaItems);
          }, 40);
        }

        function peerProfileHref(it, postId){
          it = it || {};
          var fc = String(it.friend_code || '').trim();
          var un = String(it.username || '').trim();
          var uid = Number(it.user_id || it.id || 0);
          var href = 'profile.php';
          var params = [];
          if(fc){
            params.push('friend_code=' + encodeURIComponent(fc.toUpperCase()));
          }else if(un){
            params.push('username=' + encodeURIComponent(un));
          }else if(uid > 0){
            params.push('id=' + encodeURIComponent(String(uid)));
          }
          if(Number(postId||0) > 0){
            params.push('from=feed');
            params.push('post_id=' + encodeURIComponent(String(Number(postId||0))));
          }
          return href + (params.length ? ('?' + params.join('&')) : '');
        }


        function avatarUrlFor(it, size){
          it = it || {};
          size = Number(size || 96);
          var params = [];
          var uid = Number(it.user_id || it.id || 0);
          var email = String(it.email || '').trim();
          var fc = String(it.friend_code || '').trim();
          var un = String(it.username || '').trim();
          var nm = String(it.display_name || it.name || un || 'User').trim();
          if(uid > 0) params.push('u=' + encodeURIComponent(String(uid)));
          if(email) params.push('email=' + encodeURIComponent(email));
          if(fc) params.push('friend_code=' + encodeURIComponent(fc));
          if(un) params.push('username=' + encodeURIComponent(un));
          if(nm) params.push('name=' + encodeURIComponent(nm));
          params.push('s=' + encodeURIComponent(String(size)));
          return 'avatar.php?' + params.join('&');
        }

        function mfDeclaredLayout(it){
          it = it || {};
          var layout = String(it.layout || it.layout_type || it.post_type || it.type || it.declared_layout || '').toLowerCase().trim();
          if(!layout){
            layout = extractLayoutOverrideMarker(String(it.description || it.body || ''));
          }
          return layout;
        }

        function mfMediaDots(count){
          count = Math.max(0, Number(count || 0));
          if(count <= 1) return '';
          var dots = '';
          for(var i = 0; i < count; i += 1){
            dots += '<button type="button" class="mf-media-dot'+(i === 0 ? ' is-active' : '')+'" data-index="'+i+'" aria-label="Go to media '+(i+1)+'" style="width:10px;height:10px;min-width:10px;min-height:10px;flex:0 0 10px;display:block;border:none;border-radius:50%;padding:0;margin:0;background:'+(i === 0 ? '#fff' : 'rgba(255,255,255,.45)')+';cursor:pointer;-webkit-appearance:none;appearance:none;box-shadow:none;font-size:0;line-height:0;color:transparent;text-indent:-9999px;overflow:hidden;"></button>';
          }
          return '<div class="mf-media-dots" role="tablist" aria-label="Media slides" style="position:absolute;left:50%;bottom:18px;transform:translateX(-50%);display:flex;align-items:center;justify-content:center;gap:8px;padding:8px 14px;border-radius:999px;background:rgba(17,24,39,.5);z-index:5;">' + dots + '</div>';
        }

        function mfFileTileHtml(src, kind){
          return '<div class="mf-file">'+
                   '  <div class="mf-file-ic"><i class="fa fa-file"></i></div>'+
                   '  <div class="mf-file-main">'+
                   '    <a href="'+esc(src)+'" target="_blank" rel="noopener">'+esc(src.split('/').pop()||'Open file')+'</a>'+
                   '    <div class="mf-file-sub">'+esc(String((kind||"file").toUpperCase()))+'</div>'+
                   '  </div>'+
                   '</div>';
        }

        function mfIsOwnFeedItem(it){
          it = it || {};
          if(STAFF_READONLY) return false;
          if(String(mfFriendStatusFromItem(it)) === 'self') return true;
          return Number(it.user_id || it.author_id || 0) === Number(ME_ID || 0);
        }

        function mfFriendStatusFromItem(it){
          it = it || {};
          return String(it.friend_status || it.friendStatus || '').trim();
        }

        function mfIsPublisherItem(it){
          it = it || {};
          if(Number(it.is_publisher || 0) === 1) return true;
          if(String(it.account_kind || '').toLowerCase() === 'publisher') return true;
          if(String(it.publisher_category || '').trim() !== '') return true;
          var code = String(it.friend_code || it.friendCode || '').trim().toUpperCase();
          return code.indexOf('PUB-') === 0;
        }

        function mfIsPublisherCard($card){
          $card = $card && $card.jquery ? $card : $($card);
          if(!$card.length) return false;
          if(String($card.attr('data-is-publisher') || '') === '1') return true;
          if(String($card.attr('data-account-kind') || '').toLowerCase() === 'publisher') return true;
          var code = String($card.attr('data-peer-code') || '').trim().toUpperCase();
          return code.indexOf('PUB-') === 0;
        }

        function mfMergeListItemAuthorMeta(it, post, $card){
          it = it || {};
          post = post || {};
          $card = $card && $card.jquery ? $card : $($card);
          var merged = $.extend({}, post, {
            user_id: Number(post.user_id || it.user_id || $card.attr('data-peer-id') || 0),
            friend_code: String(post.friend_code || it.friend_code || $card.attr('data-peer-code') || ''),
            account_kind: String(post.account_kind || it.account_kind || $card.attr('data-account-kind') || ''),
            publisher_category: String(post.publisher_category || it.publisher_category || ''),
            is_publisher: Number(post.is_publisher != null ? post.is_publisher : (it.is_publisher != null ? it.is_publisher : ($card.attr('data-is-publisher') || 0))),
            is_following: Number(post.is_following != null ? post.is_following : (it.is_following != null ? it.is_following : ($card.attr('data-is-following') || 0)))
          });
          if(mfIsPublisherItem(merged)){
            merged.account_kind = 'publisher';
            merged.is_publisher = 1;
          }
          return merged;
        }

        function mfPublisherFollowingFromItem(it){
          it = it || {};
          return Number(it.is_following != null ? it.is_following : (it.isFollowing || 0)) === 1;
        }

        function mfFriendBtnHtml(it, isOwner){
          if(isOwner || mfIsOwnFeedItem(it)) return '';
          if(mfFriendStatusFromItem(it) === 'friends') return '';
          var isPub = mfIsPublisherItem(it);
          var uid = String(Number(it.user_id || 0));
          var cls = 'mf-friend-btn mf-media-follow-btn';
          if(isPub){
            if(!MSB_VIEWER_CAN_FOLLOW_PUBLISHERS) return '';
            if(mfPublisherFollowingFromItem(it)) return '';
            return '<button type="button" class="'+cls+' mf-publisher-follow publisher-follow-btn mf-publisher-follow-circle mf-media-action-circle primary" data-publisher-id="'+esc(uid)+'" aria-label="Follow" title="Follow"><i class="fa fa-plus" aria-hidden="true"></i></button>';
          }
          return '<button type="button" class="'+cls+' mf-media-action-circle primary" data-peer-id="'+esc(uid)+'" data-peer-code="'+esc(String(it.friend_code || ''))+'" data-status="none" aria-label="Add Friend" title="Add Friend"><i class="fa fa-plus" aria-hidden="true"></i></button>';
        }


        function mfBuildMenuItems(it, isOwner, pid){
          if (window.MSBPostCardMenu && typeof window.MSBPostCardMenu.buildItems === 'function') {
            return window.MSBPostCardMenu.buildItems(it, isOwner, pid, {
              esc: esc,
              profileHref: peerProfileHref,
              isPublisher: mfIsPublisherItem,
              isFollowing: mfPublisherFollowingFromItem,
              friendStatus: mfFriendStatusFromItem
            });
          }
          return '';
        }

        window.MSBFeedMenuHelpers = {
          esc: esc,
          profileHref: peerProfileHref,
          isPublisher: mfIsPublisherItem,
          isFollowing: mfPublisherFollowingFromItem,
          friendStatus: mfFriendStatusFromItem
        };

        window.msbSyncContactDisplayName = function(contactId, displayName){
          contactId = Number(contactId || 0);
          displayName = String(displayName || '');
          if(!contactId) return;
          document.querySelectorAll('.mf-card[data-contact-id="'+String(contactId)+'"]').forEach(function(card){
            card.setAttribute('data-contact-name', displayName);
          });
        };

        function mfBuildMenuWrapHtml(it, isOwner, pid, onMedia){
          onMedia = !!onMedia;
          return ''+
            '<div class="mf-menu-wrap post-card-menu-wrap" data-post-id="'+esc(String(pid))+'" data-peer-id="'+esc(String(it.user_id || ''))+'" data-is-owner="'+(isOwner ? '1' : '0')+'" data-menu-surface="feed">'+
              '<button type="button" class="mf-menu-btn post-card-menu-btn" aria-label="Post menu" title="Menu" aria-haspopup="true" aria-expanded="false">'+PCM_FRIES_ICON+'</button>'+
              '<div class="mf-menu post-card-menu" role="menu">'+
                mfBuildMenuItems(it, isOwner, pid)+
              '</div>'+
            '</div>';
        }

        function mfBuildHeadHtml(it, isOwner, pid, headerFollowHtml, onMedia){
          var name = it.display_name || it.username || '';
          var avatarUrl = avatarUrlFor(it, 96);
          var time = mfDeviceTimeLabel(it, postDate(it));
          headerFollowHtml = onMedia ? '' : String(headerFollowHtml || '');
          var headClass = 'mf-head' + (onMedia ? ' mf-head--on-media' : '');
          return ''+
            '<div class="'+headClass+'">'+
              '<a class="mf-peer-link" href="'+esc(peerProfileHref(it))+'">'+
                '<div class="mf-avatar"><img src="'+esc(avatarUrl)+'" alt="'+esc(name||'User')+'"></div>'+
                '<div class="mf-meta">'+
                  '<div class="mf-name-row">'+
                    '<div class="mf-name">'+esc(name||'')+'</div>'+
                    ((Number(it.is_verified||it.verified||0) === 1) ? '<i class="fa fa-check-circle mf-verified" aria-hidden="true"></i>' : '')+
                    (time ? '<span class="mf-dot"></span><div class="mf-time">'+esc(time||'')+'</div>' : '')+
                  '</div>'+
                  mfMusicRowHtml(it)+
                '</div>'+
              '</a>'+
              headerFollowHtml+
              mfBuildMenuWrapHtml(it, isOwner, pid, onMedia)+
            '</div>';
        }

        function mfMusicRowHtml(it){
          var title = String((it && it.music_title) || '').trim();
          var artist = String((it && it.music_artist) || '').trim();
          if(!title && !artist) return '';
          var html = '<div class="mf-music-row" aria-label="Music"><i class="fa fa-music mf-music-ic" aria-hidden="true"></i>';
          if(title) html += '<span class="mf-music-title">'+esc(title)+'</span>';
          if(title && artist) html += '<span class="mf-music-dot">&middot;</span>';
          if(artist) html += '<span class="mf-music-artist">'+esc(artist)+'</span>';
          html += '</div>';
          return html;
        }

        function mfWrapMediaShell(mediaHtml, headHtml, followHtml){
          mediaHtml = String(mediaHtml || '').trim();
          headHtml = String(headHtml || '').trim();
          followHtml = String(followHtml || '').trim();
          if(!mediaHtml) return mediaHtml;
          if(!headHtml && !followHtml) return mediaHtml;
          if(!headHtml && followHtml) return mfWrapMediaWithFollowShell(mediaHtml, followHtml);
          var followBlock = followHtml
            ? ('<div class="mf-media-top-actions">'+followHtml+'</div>')
            : '';
          return ''+
            '<div class="mf-media-shell" style="position:relative;width:100%;">'+
              mediaHtml+
              headHtml+
              followBlock+
            '</div>';
        }

        function mfWrapMediaWithFollowShell(mediaHtml, followHtml){
          mediaHtml = String(mediaHtml || '').trim();
          followHtml = String(followHtml || '').trim();
          if(!mediaHtml || !followHtml) return mediaHtml;
          return ''+
            '<div class="mf-media-shell" style="position:relative;width:100%;">'+
              mediaHtml+
              '<div class="mf-media-top-actions">'+
                followHtml+
              '</div>'+
            '</div>';
        }

        function mfMountFollowOnMedia($card, it){
          $card = $card && $card.jquery ? $card : $($card);
          if(!$card.length || !it) return;
          var isOwner = mfIsOwnFeedItem(it);
          var knownStatus = String($card.attr('data-friend-status') || mfFriendStatusFromItem(it) || '');
          var isPub = mfIsPublisherItem(it) || mfIsPublisherCard($card);
          var isFollowing = mfPublisherFollowingFromItem(it) || Number($card.attr('data-is-following') || 0) === 1;
          $card.find('.mf-friend-btn').remove();
          $card.find('.mf-media-top-actions').remove();
          if(!isPub && knownStatus === 'friends'){
            mfSyncCardFriendMenu($card, knownStatus);
            return;
          }
          if(isPub && isFollowing){
            mfSyncCardPublisherMenu($card, true);
            return;
          }
          var followHtml = mfFriendBtnHtml(it, isOwner);
          if(!followHtml) return;

          var $media = $card.find('.mf-media, .media-stage.mf-media').first();
          if(!$media.length){
            var $menu = $card.find('.mf-head .mf-menu-wrap').first();
            if($menu.length){
              $(followHtml.replace(' mf-media-follow-btn', '')).insertBefore($menu);
              if(!isPub){
                var knownStatusHead = String($card.attr('data-friend-status') || '');
                if(knownStatusHead){
                  mfApplyFriendState($card.find('.mf-friend-btn'), knownStatusHead);
                }
              }
            }
            return;
          }

          var $shell = $card.find('.mf-media-shell').first();
          if(!$shell.length){
            $media.wrap('<div class="mf-media-shell"></div>');
            $shell = $media.parent();
          }
          $shell.css({ position: 'relative', width: '100%' });
          var $overlay = $('<div class="mf-media-top-actions"></div>').html(followHtml);
          $shell.append($overlay);
          if(!isPub && knownStatus){
            mfApplyFriendState($card.find('.mf-friend-btn'), knownStatus);
          }
        }

        function mfRelocateFollowButtons($root){
          $root = $root && $root.jquery ? $root : $($root || '#mfFeed');
          if(!$root.length) return;
          $root.find('.mf-card').each(function(){
            var $card = $(this);
            var peerId = Number($card.data('peer-id') || $card.attr('data-peer-id') || 0);
            if(String($card.attr('data-friend-status') || '') === 'friends') return;
            if(mfIsPublisherCard($card) && String($card.attr('data-is-following') || '') === '1') return;
            if($card.find('.mf-media-top-actions .mf-friend-btn').length) return;
            if($card.find('.mf-media, .media-stage.mf-media').length){
              var stub = {
                user_id: peerId || Number($card.attr('data-id') || 0),
                account_kind: mfIsPublisherCard($card) ? 'publisher' : String($card.attr('data-account-kind') || 'personal'),
                is_following: Number($card.attr('data-is-following') || 0),
                friend_code: String($card.attr('data-peer-code') || '')
              };
              mfMountFollowOnMedia($card, stub);
            }
          });
        }

        function mfBuildHydratedCarousel(atts){
          atts = Array.isArray(atts) ? atts : [];
          if(atts.length <= 1) return '';
          var slides = '';
          for(var i = 0; i < atts.length; i += 1){
            var a = atts[i] || {};
            var src = srcOf(a);
            var kind = detectKind(src, a.type);
            var inner = '';
            if(kind === 'image' || kind === 'gif'){
              inner = '<img src="'+esc(src)+'" alt="">';
            }else if(kind === 'video'){
              inner = '<video src="'+esc(src)+'" controls playsinline preload="metadata"></video>';
            }else{
              inner = mfFileTileHtml(src, kind);
            }
            slides += '<div class="media-slide mf-media-slide" data-slide-index="'+i+'" style="flex:0 0 100%;width:100%;max-width:100%;display:flex;align-items:center;justify-content:center;overflow:hidden;background:transparent;border-radius:8px;">'+inner+'</div>';
          }
          return ''+
            '<div class="media-carousel mf-media-carousel" data-index="0" style="position:relative;width:100%;overflow:hidden;border-radius:8px;background:transparent;">'+
              '<div class="media-slides mf-media-slides" style="display:flex;flex-wrap:nowrap;flex-direction:row;width:100%;transition:transform .28s ease;">'+slides+'</div>'+
              '<button type="button" class="media-nav mf-media-nav prev js-mf-media-prev" aria-label="Previous media" style="position:absolute;top:50%;left:12px;transform:translateY(-50%);width:20px;height:20px;border:none;border-radius:999px;background:rgba(255,255,255,.82);color:#1f2937;display:flex;align-items:center;justify-content:center;font-size:20px;cursor:pointer;box-shadow:0 8px 24px rgba(0,0,0,.18);z-index:4;"><i class="fa fa-chevron-left"></i></button>'+
              '<button type="button" class="media-nav mf-media-nav next js-mf-media-next" aria-label="Next media" style="position:absolute;top:50%;right:12px;transform:translateY(-50%);width:20px;height:20px;border:none;border-radius:999px;background:rgba(255,255,255,.82);color:#1f2937;display:flex;align-items:center;justify-content:center;font-size:20px;cursor:pointer;box-shadow:0 8px 24px rgba(0,0,0,.18);z-index:4;"><i class="fa fa-chevron-right"></i></button>'+
              mfMediaDots(atts.length).replace('mf-media-dots', 'media-dots mf-media-dots')+
            '</div>';
        }

        function mfSetCarouselIndex($carousel, nextIndex){
          $carousel = $carousel && $carousel.jquery ? $carousel : $($carousel);
          if(!$carousel.length) return;
          var $slides = $carousel.find('.mf-media-slides').first();
          var $items = $slides.children('.mf-media-slide');
          var total = $items.length;
          if(!total) return;
          nextIndex = Number(nextIndex || 0);
          if(nextIndex < 0) nextIndex = total - 1;
          if(nextIndex >= total) nextIndex = 0;
          $carousel.attr('data-index', String(nextIndex));
          $slides.css('transform', 'translateX(' + String(nextIndex * -100) + '%)');
          $carousel.find('.mf-media-dot').removeClass('is-active')
            .filter('[data-index="'+String(nextIndex)+'"]').addClass('is-active');
        }

        function mfRenderCardShell(it){
          var pid = Number(it.id||0);
          var name = it.display_name || it.username || '';
          var avatarUrl = avatarUrlFor(it, 96);
          var avatarText = mfAvatarInit(name);
          var time = mfDeviceTimeLabel(it, postDate(it));
          var title = String(it.title||'').trim();
          var isOwner = mfIsOwnFeedItem(it);
          var liveMeta = (it && typeof it.live_meta === 'object' && it.live_meta) ? it.live_meta : null;

          var psrc = String(it.preview_path||'').trim();
          var pthumb = String(it.preview_thumb_path||'').trim().replace(/^public_user\//,'');
          var pkind = detectKind(psrc, it.preview_type);
          var declaredLayout = mfDeclaredLayout(it);

          var body = formatReadMoreTextPreserve(String(it.body || it.description || '').trim())
            .replace(/\[\[live_post:\d+\]\]/ig, '')
            .replace(/\b(?:live_watch|watch_live)\.php\?live=\d+\b/ig, '')
            .trim();
          var hasBody = body.length > 0;
          var hasMedia = !!psrc;
          var deviceMeta = mfDeviceCardMeta(it);
          var isPhoneShot = !!deviceMeta.phone_shot;
          var isTabletShot = !!deviceMeta.tablet_shot && !isPhoneShot;
          var deviceStyle = String(deviceMeta.style || '').trim();
          if(!deviceStyle) deviceStyle = mfDefaultDeviceStyle(isPhoneShot, isTabletShot, String(it.device_label || deviceMeta.label || ''), String(it.device_viewport || deviceMeta.viewport || ''));
          var deviceDims = mfParseDeviceAspectFromStyle(deviceStyle);
          var deviceDataAttrs = '';
          if(deviceDims && deviceDims.w > 0 && deviceDims.h > 0){
            deviceDataAttrs = ' data-device-w="'+esc(String(deviceDims.w))+'" data-device-h="'+esc(String(deviceDims.h))+'"';
          }
          var mediaStyleAttr = deviceStyle ? (' style="'+esc(deviceStyle)+'"') : '';
          var attCount = Number(it.attachment_count || 0);
          var isSingleMedia = attCount <= 1;
          var isMultiMedia = attCount > 1;
          var shapeClass = mfMediaShapeClass(it);
          var shapeReady = shapeClass ? '1' : '0';
          var isLiveCard = !!(liveMeta && Number(liveMeta.id || 0) > 0);
          if(isLiveCard) return '';
          if(!hasMedia && !title && !hasBody) return '';
          // Same reel rule as public.php: only explicit media_reel_bottom layout.
          var isReelCard = (pkind === 'video' && isSingleMedia && declaredLayout === 'media_reel_bottom');
          var isVideoCard = (pkind === 'video' && !isReelCard);

          var titleHtml = title ? '<div class="mf-title">'+esc(title)+'</div>' : '';
          var bodyHtml = '';
          var mediaHtml = '';
          var actionsHtml = '';
          var cardClass = 'mf-card';
          if(isPhoneShot && isSingleMedia){
            cardClass += ' mf-card-phone-shot';
          }

          if(hasBody){
            bodyHtml = mfBuildBodyHtml('mf-body', body, 4);
          }

          function normalActions(){
            return ''+
              '<div class="mf-actions">'+
                '<div class="mf-left">'+
                  '<a class="mf-act mf-love" type="button" title="Love"><i class="msb-pact msb-pact-heart" aria-hidden="true"></i><span class="mf-num mf-love">0</span></a>'+
                  '<button type="button" class="mf-act mf-comment js-open-comments-door" title="Comment" aria-label="Comment"><i class="msb-pact msb-pact-comment" aria-hidden="true"></i><span class="mf-num mf-cmt">0</span></button>'+
                  '<a class="mf-act mf-share" type="button" title="Share"><i class="msb-pact msb-pact-share" aria-hidden="true"></i><span class="mf-num mf-share">0</span></a>'+
                '</div>'+
                '<div class="mf-right">'+
                  '<a class="mf-act mf-save" type="button" title="Save"><i class="msb-pact msb-pact-bookmark" aria-hidden="true"></i><span class="mf-num mf-save">0</span></a>'+
                '</div>'+
              '</div>';
          }

          if(isLiveCard){
            cardClass += ' mf-card-live';
            var liveTitle = String(liveMeta.title || title || 'Live now').trim();
            var liveDesc = String(liveMeta.description || body || '').trim();
            var liveVisibility = String(liveMeta.visibility || 'friends').trim().toLowerCase();
            var liveVisibilityLabel = (liveVisibility === 'public') ? 'Public' : (liveVisibility === 'friends' ? 'Friends only' : 'Private');
            var liveSnapshot = String(liveMeta.snapshot_url || psrc || '').trim();
            var liveWatchUrl = String(liveMeta.watch_url || ('live_watch.php?live=' + String(Number(liveMeta.id || 0)))).trim();
            var liveViewers = Number(liveMeta.viewer_count || 0);
            var liveReacts = Number(liveMeta.reaction_count || 0);
            var liveHostText = String(liveMeta.host_name || name || '').trim();
            if(!liveHostText) liveHostText = name;
            var liveAvatarUrl = avatarUrlFor(it, 96);
            titleHtml = '';
            bodyHtml = '';
            actionsHtml = '';
            mediaHtml = ''
              + '<div class="mf-media">'
              +   '<div class="mf-live-stage">'
              +     (liveSnapshot
                    ? '<img class="js-mf-live-snapshot" src="'+esc(liveSnapshot)+'" alt="'+esc(liveTitle)+'">'
                    : '<div class="mf-live-placeholder"><div class="mf-live-placeholder-inner">'
                      + '<div class="mf-live-placeholder-avatar"><img src="'+esc(liveAvatarUrl)+'" alt="'+esc(liveHostText)+'"></div>'
                      + '<h3 class="mf-live-placeholder-title">'+esc(liveTitle)+'</h3>'
                      + '<p class="mf-live-placeholder-sub">'+esc(liveHostText)+' is live now. Tap to open the stream.</p>'
                      + '</div></div>')
              +     '<a class="mf-live-open-hit" href="'+esc(liveWatchUrl)+'" aria-label="Watch live"></a>'
              +     '<div class="mf-live-overlay">'
              +       '<div class="mf-live-top">'
              +         '<div class="mf-live-host">'
              +           '<div class="mf-live-host-avatar"><img src="'+esc(liveAvatarUrl)+'" alt="'+esc(liveHostText)+'"></div>'
              +           '<div class="mf-live-host-meta">'
              +             '<p class="mf-live-host-name">'+esc(liveHostText)+'</p>'
              +             '<p class="mf-live-host-sub">Live now</p>'
              +           '</div>'
              +         '</div>'
              +         '<div class="mf-live-top-pills">'
              +           '<span class="mf-live-pill"><i class="icon ion-ios-eye"></i><span>'+liveViewers+'</span></span>'
              +           '<span class="mf-live-pill"><i class="fa fa-heart"></i><span>'+liveReacts+'</span></span>'
              +         '</div>'
              +       '</div>'
              +       '<div class="mf-live-bottom">'
              +         '<div class="mf-live-copy">'
              +           '<span class="mf-live-chip">LIVE NOW · '+esc(liveVisibilityLabel)+'</span>'
              +           '<h3 class="mf-live-title">'+esc(liveTitle)+'</h3>'
              +           (liveDesc ? '<p class="mf-live-desc">'+esc(liveDesc)+'</p>' : '')
              +         '</div>'
              +         '<div class="mf-live-footer">'
              +           '<a class="mf-live-cta" href="'+esc(liveWatchUrl)+'"><i class="fa fa-play"></i>Watch live</a>'
              +         '</div>'
              +         '<div class="mf-live-actionbar">'
              +           '<div class="mf-live-action-left">'
              +             '<div class="mf-live-action-row">'
              +               '<a class="mf-live-action-btn mf-act mf-love" type="button" title="Love"><i class="msb-pact msb-pact-heart" aria-hidden="true"></i><span class="mf-num mf-love">0</span></a>'
              +               '<a class="mf-live-action-btn mf-act mf-like" type="button" title="Like"><i class="fa fa-thumbs-o-up"></i><span class="mf-num mf-like">0</span></a>'
              +               '<button type="button" class="mf-live-action-btn mf-act mf-comment js-open-comments-door" title="Comment" aria-label="Comment"><i class="msb-pact msb-pact-comment" aria-hidden="true"></i><span class="mf-num mf-cmt">0</span></button>'
              +               '<a class="mf-live-action-btn mf-act mf-share" type="button" title="Share"><i class="msb-pact msb-pact-share" aria-hidden="true"></i><span class="mf-num mf-share">0</span></a>'
              +               '<span class="mf-live-action-spacer"><a class="mf-live-action-btn mf-act mf-save" type="button" title="Save"><i class="msb-pact msb-pact-bookmark" aria-hidden="true"></i><span class="mf-num mf-save">0</span></a></span>'
              +             '</div>'
              +             '<div class="mf-live-comments-link">View all 0 comments</div>'
              +           '</div>'
              +         '</div>'
              +       '</div>'
              +     '</div>'
              +   '</div>'
              + '</div>';
          } else if(hasMedia){
            if(pkind === 'image' || pkind === 'gif'){
              if(isMultiMedia) cardClass += ' is-multi-media-post mf-card-multi-media';
              else cardClass += ' is-single-image-post mf-card-single-image';
              var imageMediaClass = mfBuildMediaClassList({
                standardImage: isSingleMedia,
                isSingleMedia: isSingleMedia,
                isPhoneShot: isPhoneShot,
                shapeClass: shapeClass,
                isMultiMedia: isMultiMedia
              });
              mediaHtml = '<div class="'+imageMediaClass+'"'+mediaStyleAttr+' data-shape-ready="1">'+
                          '<img src="'+esc(psrc)+'" alt="">'+
                          mfMediaDots(attCount)+
                          '</div>';
            } else if(pkind === 'video'){
              if(isReelCard){
                cardClass += ' mf-card-reel';
                mediaHtml = ''+
                  '<div class="mf-media">'+
                    '<video class="ig-smart-feed-video js-mf-reel-video" src="'+esc(psrc)+'" playsinline muted loop preload="metadata" data-smart-video="1"></video>'+
                    mfMediaDots(attCount)+
                  '</div>';
                actionsHtml = normalActions();
              } else {
                cardClass += ' is-single-video-post mf-card-single-video';
                if(isMultiMedia) cardClass += ' is-multi-media-post mf-card-multi-media';
                var videoMediaClass = mfBuildMediaClassList({
                  standardVideo: true,
                  isSingleMedia: isSingleMedia,
                  isPhoneShot: isPhoneShot,
                  shapeClass: shapeClass,
                  isMultiMedia: isMultiMedia
                });
                var videoPosterAttr = pthumb ? (' poster="'+esc(pthumb)+'"') : '';
                mediaHtml = ''+
                  '<div class="'+videoMediaClass+'"'+mediaStyleAttr+' data-shape-ready="'+shapeReady+'">'+
                    '<video class="ig-smart-feed-video" src="'+esc(psrc)+'"'+videoPosterAttr+' controls playsinline preload="metadata" data-smart-video="1"></video>'+
                    mfMediaDots(attCount)+
                  '</div>';
                actionsHtml = normalActions();
              }
            } else {
              mediaHtml = mfFileTileHtml(psrc, pkind);
            }
          }

          if(!isVideoCard && !isReelCard && !isLiveCard){
            actionsHtml = normalActions();
          }

          var followHtml = mfFriendBtnHtml(it, isOwner);
          var useHeadOnMedia = hasMedia && !isLiveCard && !isReelCard;
          var headerFollowHtml = (followHtml && !hasMedia && !isLiveCard)
            ? followHtml.replace(' mf-media-follow-btn', '')
            : '';
          if(useHeadOnMedia){
            mediaHtml = mfWrapMediaShell(
              mediaHtml,
              mfBuildHeadHtml(it, isOwner, pid, '', true),
              (followHtml && !isLiveCard) ? followHtml : ''
            );
          } else if(followHtml && hasMedia && !isLiveCard){
            mediaHtml = mfWrapMediaWithFollowShell(mediaHtml, followHtml);
          }

          var cardPeerId = Number(it.user_id || 0);
          var cardAccountKind = mfIsPublisherItem(it) ? 'publisher' : String(it.account_kind || 'personal');
          var cardIsPublisher = mfIsPublisherItem(it) ? '1' : '0';
          var cardIsFollowing = Number(it.is_following || 0) === 1 ? '1' : '0';
          var cardPeerCode = String(it.friend_code || '');
          var cardFriendStatus = mfFriendStatusFromItem(it);
          var cardProfileUrl = peerProfileHref(it, pid);
          var cardPeerUsername = String(it.username || '');

          var initialCardStyle = '';
          if(!isLiveCard && !isReelCard && !isMultiMedia && isSingleMedia && hasMedia && (pkind === 'image' || pkind === 'gif' || pkind === 'video')){
            initialCardStyle = mfInitialMediaCardStyleFromDims(deviceDims || mfInitialMediaAspect(it, null), isPhoneShot);
          }
          var initialCardStyleAttr = initialCardStyle ? (' style="'+esc(initialCardStyle)+'"') : '';

          var cardContactId = Number(it.contact_id || 0);
          var cardContactName = String(it.contact_name || '');

          return ''+
            '<div class="'+cardClass+'" data-id="'+pid+'" data-post-id="'+pid+'" data-peer-id="'+esc(String(cardPeerId))+'" data-post-owner="'+(isOwner ? '1' : '0')+'" data-account-kind="'+esc(cardAccountKind)+'" data-is-publisher="'+esc(cardIsPublisher)+'" data-is-following="'+cardIsFollowing+'" data-my-saved="'+esc(String(Number(it.my_saved || 0)))+'" data-is-archived="'+esc(String(Number(it.is_archived || 0)))+'" data-peer-code="'+esc(cardPeerCode)+'" data-peer-username="'+esc(cardPeerUsername)+'" data-profile-url="'+esc(cardProfileUrl)+'" data-friend-status="'+esc(cardFriendStatus)+'" data-contact-id="'+esc(String(cardContactId))+'" data-contact-name="'+esc(cardContactName)+'" data-title="'+esc(title)+'" data-author="'+esc(name)+'" data-date="'+esc(time)+'" data-avatar-url="'+esc(avatarUrl)+'" data-avatar-text="'+esc(avatarText)+'" data-full-desc="'+esc(body)+'"'+deviceDataAttrs+initialCardStyleAttr+'>'+
              (useHeadOnMedia ? '' : mfBuildHeadHtml(it, isOwner, pid, headerFollowHtml, false))+
              titleHtml+
              mediaHtml+
              bodyHtml+
              actionsHtml+
            '</div>';
        }


        function mfFriendHref(peerId, peerCode, status){
          var code = String(peerCode || '').trim();
          if(status === 'incoming_pending') return 'contact_requests.php';
          if(status === 'outgoing_pending') return 'contact_requests.php';
          if(code) return 'add_contact.php?friend=' + encodeURIComponent(code.toUpperCase());
          return 'add_contact.php?friend=' + encodeURIComponent(String(peerId || ''));
        }

        function mfSyncCardFriendMenu($card, status){
          $card = $card && $card.jquery ? $card : $($card);
          if(!$card.length) return;
          status = String(status || 'none');
          $card.attr('data-friend-status', status);
          var isFriend = status === 'friends';
          $card.find('.mf-menu .mf-unfriend').each(function(){
            this.hidden = !isFriend;
          });
          if(isFriend){
            $card.find('.mf-friend-btn').addClass('is-friends').remove();
            $card.find('.mf-media-top-actions').addClass('is-friend-only-hidden').remove();
          }else{
            $card.find('.mf-media-top-actions').removeClass('is-friend-only-hidden');
          }
        }

        function mfSyncCardPublisherMenu($card, following){
          $card = $card && $card.jquery ? $card : $($card);
          if(!$card.length) return;
          if(window.MSBPostCardMenu && typeof window.MSBPostCardMenu.syncCardPublisher === 'function'){
            window.MSBPostCardMenu.syncCardPublisher($card, following);
          }
        }

        function mfApplyPublisherFollowState($btn, following){
          $btn = $btn && $btn.jquery ? $btn : $($btn);
          if(!$btn.length) return;
          $btn.each(function(){
            if(typeof window.msbApplyPublisherFollowBtnState === 'function'){
              window.msbApplyPublisherFollowBtnState(this, !!following);
            }
          });
        }

        function mfSyncPublisherUiForPub(pubId, following){
          pubId = Number(pubId || 0);
          if(pubId <= 0) return;
          following = !!following;
          if(window.MSBPostCardMenu && typeof window.MSBPostCardMenu.syncPublisherCards === 'function'){
            window.MSBPostCardMenu.syncPublisherCards(pubId, following);
          }
          $('.mf-card[data-peer-id="'+pubId+'"]').each(function(){
            var $card = $(this);
            $card.attr('data-is-following', following ? '1' : '0');
            mfSyncCardPublisherMenu($card, following);
            var $pubBtns = $card.find('.mf-publisher-follow, .publisher-follow-btn[data-publisher-id="'+pubId+'"]');
            if($pubBtns.length){
              mfApplyPublisherFollowState($pubBtns, following);
            } else if(!following){
              mfRemountFollowOnCard($card, {
                user_id: pubId,
                account_kind: 'publisher',
                is_publisher: 1,
                is_following: 0,
                friend_code: String($card.attr('data-peer-code') || '')
              });
            }
          });
          $('.mf-publisher-follow[data-publisher-id="'+pubId+'"], .publisher-follow-btn[data-publisher-id="'+pubId+'"]').each(function(){
            var $card = $(this).closest('.mf-card');
            if($card.length && Number($card.attr('data-peer-id') || 0) === pubId) return;
            mfApplyPublisherFollowState($(this), following);
          });
        }
        window.mfSyncPublisherUiForPub = mfSyncPublisherUiForPub;

        function mfSyncFriendUiForPeer(peerId, status){
          peerId = Number(peerId || 0);
          if(peerId <= 0) return;
          status = String(status || 'none');
          $('.mf-card[data-peer-id="'+peerId+'"]').each(function(){
            var $card = $(this);
            if(mfIsPublisherCard($card)) return;
            mfSyncCardFriendMenu($card, status);
            if(status !== 'friends' && !$card.find('.mf-friend-btn').length){
              mfRemountFollowOnCard($card, {
                user_id: peerId,
                account_kind: String($card.attr('data-account-kind') || 'personal'),
                is_following: Number($card.attr('data-is-following') || 0),
                friend_code: String($card.attr('data-peer-code') || '')
              });
            }
          });
          $('.mf-friend-btn[data-peer-id="'+peerId+'"]').each(function(){
            mfApplyFriendState($(this), status, true);
          });
        }

        function mfApplyFriendState($btn, status, skipMenuSync){
          if(!$btn || !$btn.length) return;
          if($btn.hasClass('mf-publisher-follow') || $btn.data('publisher-id')) return;
          status = String(status || '');
          if(status !== 'friends' && status !== 'incoming_pending' && status !== 'outgoing_pending') return;
          $btn.each(function(){
            if(typeof window.msbApplyFriendActionBtnState === 'function'){
              window.msbApplyFriendActionBtnState(this, status);
            }else if(this.classList && (this.classList.contains('mf-media-action-circle') || this.classList.contains('mf-publisher-follow-circle'))){
              this.classList.remove('is-friends', 'is-pending', 'is-accept', 'primary');
              this.setAttribute('data-status', status);
              if(status === 'friends'){
                this.remove();
              }else if(status === 'incoming_pending'){
                this.innerHTML = '<span class="mf-media-action-label">Accept</span>';
                this.classList.add('is-accept');
              }else if(status === 'outgoing_pending'){
                this.innerHTML = '<span class="mf-media-action-label">Sent</span>';
                this.classList.add('is-pending');
                this.disabled = true;
              }
            }else{
              var $el = $(this);
              $el.removeClass('is-friends is-pending is-accept').attr('data-status', status);
              if(status === 'friends'){
                $el.text('Friends').addClass('is-friends').remove();
                $el.closest('.mf-media-top-actions').remove();
              }else if(status === 'incoming_pending'){
                $el.text('Accept Friend').addClass('is-accept').show();
              }else if(status === 'outgoing_pending'){
                $el.text('Request Sent').addClass('is-pending').show();
              }
            }
          });
          if(!skipMenuSync){
            var $card = $btn.closest('.mf-card');
            if($card.length) mfSyncCardFriendMenu($card, status);
          }
        }

        function mfLoadFriendStates(){
          var seen = {};
          $('.mf-card').each(function(){
            var $card = $(this);
            var peerId = Number($card.attr('data-peer-id') || 0);
            if(peerId <= 0 || peerId === Number(ME_ID || 0) || mfIsPublisherCard($card)) return;
            if(seen[peerId]) return;
            seen[peerId] = 1;
            $.getJSON('ajax/friend_status.php', { peer_id: peerId }, function(res){
              if(!res || !res.ok) return;
              mfSyncFriendUiForPeer(peerId, String(res.status || 'none'));
            });
          });
        }

        $(document).on('click', '.mf-publisher-follow', function(e){
          e.preventDefault();
          e.stopPropagation();
          var $btn = $(this);
          if($btn.prop('disabled') || $btn.hasClass('is-following')) return;
          var pubId = Number($btn.attr('data-publisher-id') || $btn.data('publisher-id') || 0);
          if(!pubId) return;
          $btn.prop('disabled', true);
          var fd = new FormData();
          fd.append('target_id', String(pubId));
          fetch('publisher_follow_toggle.php', { method:'POST', body: fd, cache:'no-store' })
            .then(function(r){ return r.json(); })
            .then(function(res){
              if(!res || !res.ok){
                $btn.prop('disabled', false);
                return;
              }
              mfSyncPublisherUiForPub(pubId, !!res.following);
            })
            .catch(function(){
              $btn.prop('disabled', false);
            });
        });

        $(document).on('click', '.mf-friend-btn', function(e){
          if ($(this).hasClass('mf-publisher-follow') || $(this).data('publisher-id')) return;
          var $btn = $(this);
          var peerId = Number($btn.data('peer-id') || 0);
          var peerCode = String($btn.data('peer-code') || '');
          var status = String($btn.attr('data-status') || 'none');
          if(status === 'friends') return;
          if(status === 'incoming_pending' || status === 'outgoing_pending'){
            window.location.href = mfFriendHref(peerId, peerCode, status);
            return;
          }
          e.preventDefault();
          $.post('ajax/friend_action.php', { action:'send', peer_id: peerId }, function(res){
            if(typeof res === 'string') { try { res = JSON.parse(res); } catch(err){} }
            if(!res) return;
            var next = String(res.status || 'outgoing_pending');
            mfSyncFriendUiForPeer(peerId, next);
          }, 'json');
        });

        function mfHydrateCard(postId, post, counts, atts){
          var $card = $('.mf-card[data-id="'+Number(postId)+'"]');
          if(!$card.length) return;

          // counts
          var c = counts || {};
          $card.find('.mf-cmt').text(String(c.comment_count||0));
          $card.find('.mf-act.mf-love .mf-num').text(String(c.love_count||0));
          $card.find('.mf-act.mf-like .mf-num').text(String(c.like_count||0));
          $card.find('.mf-act.mf-save .mf-num').text(String(c.save_count||0));
          $card.find('.mf-act.mf-share .mf-num').text(String(c.share_count||0));

          // active states
          var my = String(c.my_reaction||'');
          $card.attr('data-my-reaction', my);
          $card.find('.mf-act').removeClass('is-love is-like is-save is-share');
          if(my !== '' && my !== 'like') $card.find('.mf-act.mf-love').addClass('is-love');
          if(my === 'like') $card.find('.mf-act.mf-like').addClass('is-like');
          if(window.MSBReactions){
            $card.find('.mf-act.mf-love').each(function(){ window.MSBReactions.applyReactionButton(this, my !== 'like' ? my : '', 'love'); });
            $card.find('.mf-act.mf-like').each(function(){ window.MSBReactions.applyLikeButton(this, my === 'like' ? my : ''); });
          }

          var saved = Number(c.is_saved||0) === 1;
          var shared = Number(c.is_shared||0) === 1;
          $card.attr('data-my-saved', saved ? '1' : '0');
          if(saved) $card.find('.mf-act.mf-save').addClass('is-save');
          if(shared) $card.find('.mf-act.mf-share').addClass('is-share');
          $card.find('.mf-act.mf-save .msb-pact-bookmark').toggleClass('is-active', saved);

          if(window.MSBPostCardMenu && typeof window.MSBPostCardMenu.syncBookmarkMenuState === 'function'){
            window.MSBPostCardMenu.syncBookmarkMenuState(postId, saved);
          }

          if($card.hasClass('mf-card-reel')){
            var desc = formatReadMoreTextPreserve(String((post && (post.body || post.description || '')) || '').trim());
            $card.attr('data-full-desc', desc);
          }

          atts = Array.isArray(atts) ? atts : [];
          if(!$card.hasClass('mf-card-reel') && atts.length > 1){
            var $shell = $card.find('.mf-media-shell').first();
            var $mediaWrap = $shell.length ? $shell.find('.mf-media').first() : $card.find('.mf-media').first();
            if($mediaWrap.length && !$mediaWrap.find('.mf-media-carousel').length){
              var $followOverlay = ($shell.length ? $shell : $mediaWrap).find('.mf-media-top-actions').detach();
              var $headOverlay = ($shell.length ? $shell : $mediaWrap.parent()).find('.mf-head--on-media').detach();
              $mediaWrap.addClass('media-stage has-carousel js-media-carousel');
              $mediaWrap.html(mfBuildHydratedCarousel(atts));
              var $mountTarget = $shell.length ? $shell : $mediaWrap;
              if($headOverlay.length) $mountTarget.append($headOverlay);
              if($followOverlay.length){
                $mountTarget.append($followOverlay);
              } else if(post){
                mfRemountFollowOnCard($card, post);
              }
              mfSetCarouselIndex($mediaWrap.find('.mf-media-carousel'), 0);
            }
          }
          mfRemountFollowOnCard($card, mfMergeListItemAuthorMeta({}, post || {}, $card));
          mfApplyMediaShape($card);
          var peerId = Number($card.data('peer-id') || $card.attr('data-peer-id') || 0);
          var knownStatus = String($card.attr('data-friend-status') || '');
          if(peerId > 0 && knownStatus){
            mfSyncFriendUiForPeer(peerId, knownStatus);
          }
          if(peerId > 0 && mfIsPublisherCard($card)){
            mfSyncPublisherUiForPub(peerId, Number($card.attr('data-is-following') || 0) === 1);
          }
        }

        function mfRemountFollowOnCard($card, post){
          post = mfMergeListItemAuthorMeta({}, post || {}, $card);
          var stub = {
            user_id: Number(post.user_id || $card.attr('data-peer-id') || 0),
            account_kind: mfIsPublisherItem(post) ? 'publisher' : String(post.account_kind || $card.attr('data-account-kind') || 'personal'),
            is_publisher: mfIsPublisherItem(post) ? 1 : 0,
            is_following: Number(post.is_following != null ? post.is_following : ($card.attr('data-is-following') || 0)),
            friend_code: String(post.friend_code || $card.attr('data-peer-code') || ''),
            friend_status: String(post.friend_status || $card.attr('data-friend-status') || '')
          };
          mfMountFollowOnMedia($card, stub);
        }

        function mfOpenReadMoreDrawer($card, bodyText){
          $card = $card && $card.jquery ? $card : $($card);
          if(!$card.length) return;
          var body = formatReadMoreTextPreserve(String(bodyText || $card.attr('data-full-desc') || '').trim());
          if(!body) return;
          var title = String($card.attr('data-title') || 'Post');
          var author = String($card.attr('data-author') || '');
          var date = String($card.attr('data-date') || '');
          var avatarText = String($card.attr('data-avatar-text') || 'P');
          var avatarUrl = String($card.attr('data-avatar-url') || '');
          if(window.TTReadMore && typeof window.TTReadMore.toggle === 'function'){
            window.TTReadMore.toggle({
              title: title,
              author: author,
              date: date,
              avatarText: avatarText,
              avatarBg: '#111827',
              avatarUrl: avatarUrl,
              body: body
            });
            return;
          }
          try{
            currentTitle = title;
            currentAuthor = author;
            currentDate = date;
            currentAvatarText = avatarText;
            currentFullBody = body;
            currentFullDesc = body;
            openReadMore();
          }catch(e){}
        }

        // Post card 3-dot menu handled by includes/post_card_actions_menu.js.php

        $(document).on('click', '.mf-peer-link, .pv-peer-link, #pvAvatar', function(e){
          e.stopPropagation();
        });

        function mfSetPid(pid){
          pid = Number(pid||0);
          if(!pid) return;
          $('#curPostId').val(String(pid));
          selectedId = pid;
        }
        function mfPost(action, data, cb){
          data = data || {};
          data.ajax = action;
          $.post(API_URL, data, function(res){
            if(typeof cb === 'function') cb(res||{});
          }, 'json').fail(function(){
            if(typeof cb === 'function') cb({ok:false});
          });
        }


        $(document).on('click', '.mf-card .mf-love', function(){
          var $card = $(this).closest('.mf-card');
          var pid = Number($card.data('id')||0);
          if(!pid) return;
          if(String($card.attr('data-my-reaction') || '') === 'love') return;
          mfPost('react', { post_id: pid, reaction: 'love' }, function(res){
            if(!res || !res.ok) return;
            mfHydrateCard(pid, res.post||{}, res.counts||{}, res.attachments||[]);
          });
        });

        $(document).on('click', '.mf-card .mf-like', function(){
          var $card = $(this).closest('.mf-card');
          var pid = Number($card.data('id')||0);
          if(!pid) return;
          if(String($card.attr('data-my-reaction') || '') === 'like') return;
          mfPost('react', { post_id: pid, reaction: 'like' }, function(res){
            if(!res || !res.ok) return;
            mfHydrateCard(pid, res.post||{}, res.counts||{}, res.attachments||[]);
          });
        });

        if(window.MSBReactions){
          window.MSBReactions.bindLikePicker('.mf-card .mf-love', function(btn, reaction){
            var $card = $(btn).closest('.mf-card');
            var pid = Number($card.data('id')||0);
            if(!pid || !reaction) return;
            if(String($card.attr('data-my-reaction') || '') === String(reaction)) return;
            mfPost('react', { post_id: pid, reaction: reaction }, function(res){
              if(!res || !res.ok) return;
              mfHydrateCard(pid, res.post||{}, res.counts||{}, res.attachments||[]);
            });
          });
        }

        $(document).on('click', '.mf-card .mf-save', function(){
          var $card = $(this).closest('.mf-card');
          var pid = Number($card.data('id')||0);
          if(!pid) return;
          mfPost('save', { post_id: pid }, function(res){
            if(!res || !res.ok) return;
            mfHydrateCard(pid, res.post||{}, res.counts||{}, res.attachments||[]);
          });
        });

        $(document).on('click', '.mf-card .mf-share', function(){
          var $card = $(this).closest('.mf-card');
          var pid = Number($card.data('id')||0);
          if(!pid) return;
          mfPost('share', { post_id: pid }, function(res){
            if(!res || !res.ok) return;
            mfHydrateCard(pid, res.post||{}, res.counts||{}, res.attachments||[]);
          });
        });

        $(document).on('click', '.js-mf-media-prev, .js-mf-media-next, .mf-media-dot', function(e){
          e.preventDefault();
          e.stopPropagation();
          var $carousel = $(this).closest('.mf-media-carousel');
          if(!$carousel.length) return;
          var current = Number($carousel.attr('data-index') || 0);
          if($(this).hasClass('js-mf-media-prev')){
            mfSetCarouselIndex($carousel, current - 1);
            return;
          }
          if($(this).hasClass('js-mf-media-next')){
            mfSetCarouselIndex($carousel, current + 1);
            return;
          }
          if($(this).hasClass('mf-media-dot')){
            mfSetCarouselIndex($carousel, Number($(this).attr('data-index') || 0));
          }
        });


        function postDeclaredLayout(it){
          var layout = String((it && (it.declared_layout || it.layout || it.layout_type || it.post_type || it.type)) || '').toLowerCase().trim();
          if(!layout){
            var desc = String((it && it.description) || '');
            var m = desc.match(/\[\[layout:([a-z0-9_]+)\]\]/i);
            if(m) layout = String(m[1] || '').toLowerCase();
          }
          return layout;
        }
        function isStoryPost(it){
          if(!it) return false;
          if(Number(it.is_story || 0) === 1) return true;
          return postDeclaredLayout(it) === 'story';
        }
        function isFeedCardPost(it){
          return !isStoryPost(it);
        }

        function stripLayoutOverrideMarker(txt){
          return String(txt || '').replace(/\s*\[\[layout:[a-z0-9_]+\]\]\s*/ig, ' ').replace(/\s{2,}/g, ' ').trim();
        }

        function formatReadMoreTextPreserve(text){
          text = String(text || '');
          text = text.replace(/\[\[layout:[a-z0-9_]+\]\]/ig, '');
          text = text.replace(/<\/p>\s*<p[^>]*>/ig, '\n\n');
          text = text.replace(/<br\s*\/?>/ig, '\n');
          text = text.replace(/<[^>]+>/g, '');
          text = text.replace(/\r\n/g, '\n').replace(/\r/g, '\n');
          text = text.replace(/[ \t]+\n/g, '\n').replace(/\n[ \t]+/g, '\n');
          text = text.replace(/\n{3,}/g, '\n\n');
          return text.trim();
        }

        function formatStoryTextPreserve(text){
          return formatReadMoreTextPreserve(text);
        }

        function storyCaptionFromItem(it, titleOnly){
          if(!it) return '';
          if(titleOnly){
            return formatStoryTextPreserve(it.title || '');
          }
          var title = formatStoryTextPreserve(it.title || '');
          var description = formatStoryTextPreserve(it.description || '');
          var body = formatStoryTextPreserve(it.body || '');
          if(body){
            if(title && body.indexOf(title) !== 0) return title + '\n\n' + body;
            return body;
          }
          if(description){
            if(title && description !== title) return title + '\n\n' + description;
            return description;
          }
          return title;
        }

        function storyPreviewKind(src, type){
          type = String(type || '').toLowerCase();
          if(type === 'video' || type === 'image' || type === 'gif') return type === 'gif' ? 'image' : type;
          src = String(src || '').split('?')[0].split('#')[0].toLowerCase();
          if(src.match(/\.(mp4|webm|ogg|mov|m4v)$/)) return 'video';
          if(src.match(/\.(jpg|jpeg|png|gif|webp|bmp|svg)$/)) return 'image';
          return 'image';
        }

        function storyMediaSrcFromItem(it){
          it = it || {};
          var src = String(it.preview_path || it.preview_thumb_path || '').trim()
            .replace(/^public_user\//,'')
            .replace(/^\.\//,'');
          return src;
        }

        function buildStoryCatalogFromItems(items){
          items = Array.isArray(items) ? items : [];
          var byUser = {};
          items.forEach(function(it){
            if(!isStoryPost(it)) return;
            var src = storyMediaSrcFromItem(it);
            var uid = Number(it.user_id || 0);
            if(!uid) return;
            var caption = storyCaptionFromItem(it);
            if(!src && !caption) return;
            var key = 'u' + String(uid);
            if(!byUser[key]){
              var storyIsPublisher = String(it.account_kind || '') === 'publisher'
                || String(it.friend_code || '').trim().toUpperCase().indexOf('PUB-') === 0;
              byUser[key] = {
                key: key,
                userId: uid,
                name: peerLabel(it.display_name, it.username) || String(it.username || 'User'),
                username: String(it.username || '').trim(),
                friendCode: String(it.friend_code || '').trim().toUpperCase(),
                verified: Number(it.is_verified || it.verified || 0) === 1,
                isPublisher: storyIsPublisher,
                avatarUrl: avatarUrlFor(it, 96),
                subtitle: '',
                slides: []
              };
            }
            byUser[key].slides.push({
              src: src,
              type: src ? storyPreviewKind(src, it.preview_type) : 'text',
              title: storyCaptionFromItem(it, true),
              caption: caption,
              timeLabel: fmtDateShort(itemDate(it)) || timeAgoShort(itemDate(it)) || '',
              timeAgo: timeAgoShort(itemDate(it)) || '',
              createdAt: String(itemDate(it) || ''),
              postId: Number(it.id || 0),
              myReaction: String(it.my_reaction || ''),
              myShared: Number(it.my_shared || 0) ? 1 : 0,
              mySaved: Number(it.my_saved || 0) ? 1 : 0,
              isArchived: Number(it.is_archived || 0) ? 1 : 0,
              commentCount: Number(it.comment_count || 0),
              loveCount: Number(it.love_count || 0),
              shareCount: Number(it.share_count || 0),
              saveCount: Number(it.save_count || 0),
              friendCode: String(it.friend_code || byUser[key].friendCode || '').trim().toUpperCase(),
              previewType: String(it.preview_type || '').trim()
            });
          });
          return Object.keys(byUser).map(function(k){ return byUser[k]; }).filter(function(story){
            return story.slides && story.slides.length;
          });
        }

        function mfStoriesCreateHtml(){
          if (STAFF_READONLY) return '';
          return ''
            + '<a class="ig-story-item ig-story-create" href="dashboard.php?modal=1&story=1" data-create-post-modal="1" aria-label="Create a story">'
            + '  <div class="ig-story-ring ig-story-ring-create"><i class="icon ion-plus" aria-hidden="true"></i></div>'
            + '</a>';
        }

        function mfStoriesEmptyHtml(){
          return ''
            + '<div class="ig-story-item ig-story-empty" role="status" aria-label="No stories available">'
            + '  <div class="ig-story-ring ig-story-ring-empty">'
            + '    <span class="ig-story-empty-icon" aria-hidden="true"><i class="icon ion-ios-book-outline"></i></span>'
            + '  </div>'
            + '  <span class="ig-story-name"></span>'
            + '</div>';
        }

        function setStoriesBarEmptyState(isEmpty){
          var track = document.getElementById('igStoriesTrack');
          if(!track) return;
          var bar = track.closest('.ig-stories-bar');
          if(bar) bar.classList.toggle('is-empty', !!isEmpty);
          track.classList.toggle('is-empty', !!isEmpty);
          if (!STAFF_READONLY) track.classList.add('has-create');
          else track.classList.remove('has-create');
        }

        function rebuildStoriesBar(items){
          var catalog = buildStoryCatalogFromItems(items);
          if(window.TTStories && typeof window.TTStories.setCatalog === 'function'){
            window.TTStories.setCatalog(catalog);
          }
          var track = document.getElementById('igStoriesTrack');
          if(!track) return;
          if(!catalog.length){
            track.innerHTML = mfStoriesCreateHtml() + mfStoriesEmptyHtml();
            setStoriesBarEmptyState(true);
            return;
          }
          setStoriesBarEmptyState(false);
          var gradients = [
            'linear-gradient(135deg,#667eea,#764ba2)',
            'linear-gradient(135deg,#f093fb,#f5576c)',
            'linear-gradient(135deg,#4facfe,#00f2fe)',
            'linear-gradient(135deg,#43e97b,#38f9d7)',
            'linear-gradient(135deg,#fa709a,#fee140)',
            'linear-gradient(135deg,#a18cd1,#fbc2eb)',
            'linear-gradient(135deg,#ff9a9e,#fecfef)'
          ];
          var html = mfStoriesCreateHtml();
          catalog.forEach(function(story, idx){
            var thumb = String(story.avatarUrl || '');
            var ringInner = thumb
              ? ('<img src="'+esc(thumb)+'" alt="">')
              : ('<span class="ig-story-thumb" style="background:'+gradients[idx % gradients.length]+'"></span>');
            var label = String(story.name || 'Story');
            if(label.length > 11) label = label.slice(0, 10) + '..';
            html += '<a type="button" class="ig-story-item" data-story-key="'+esc(String(story.key))+'" data-story-index="'+String(idx)+'" aria-label="Open story for '+esc(story.name)+'">'
              + '<div class="ig-story-ring">'+ringInner+'</div>'
              // + '<span class="ig-story-name">'+esc(label)+'</span>'
              + '</a>';
          });
          track.innerHTML = html;
        }

        function applySearchFilter(){
          var q = ($('#searchBox').val() || '').trim().toLowerCase();
          $('#feedTopSearchInput').val(($('#searchBox').val() || '').trim());
          var filter = String($('#filterSel').val() || 'all');
          var items = (allItems || []).slice(0);

          if(filter === 'top'){
            // top viewed: do not restrict to 24h; show all, sorted by cached views_count
            items.sort(function(a,b){
              var av = Number(a.views_count||0), bv = Number(b.views_count||0);
              if(bv !== av) return bv - av;
              // tie-breaker: newest updated
              var ad = String(a.updated_at||a.created_at||'');
              var bd = String(b.updated_at||b.created_at||'');
              return bd.localeCompare(ad);
            });
          } else {
            items = sortFeedItemsByRecent(items);
          }

          if(filter === 'mine'){
            items = items.filter(function(it){
              return !STAFF_READONLY && Number(it.user_id || 0) === Number(ME_ID || 0);
            });
          }

          if(q !== ''){
            items = items.filter(function(it){
              var t = (it.title || '').toString().toLowerCase();
              var d = (it.description || it.body || '').toString().toLowerCase();
              var n = (it.display_name || it.name || '').toString().toLowerCase();
              var u = (it.username || '').toString().toLowerCase();
              return t.indexOf(q) !== -1 || d.indexOf(q) !== -1 || n.indexOf(q) !== -1 || u.indexOf(q) !== -1;
            });
          }

          // Right sidebar rows expire after 24 hours for every filter/search mode.
          items = items.filter(function(it){
            return isWithin24h(itemDate(it));
          });

          if(FEED_PIN_POST_ID > 0){
            items = pinFeedItemFirst(items, FEED_PIN_POST_ID);
          }

          filteredFeedItems = (items || []).filter(isFeedCardPost).slice(0);
          try{
            window.feedViewerOrderedIds = filteredFeedItems.map(function(it){
              return Number(it && it.id || 0);
            }).filter(function(id){ return id > 0; });
          }catch(err){}

          updateUnreadBadge(countUnreadSidebarItems(items.filter(isFeedCardPost)));
          renderList(items.filter(isFeedCardPost));
          rebuildStoriesBar(items);

          if(selectedId){
            $('#postList .pl-item').removeClass('active');
            $('#postList .pl-item[data-id="'+selectedId+'"]').addClass('active');
          }

          if(suppressAutoOpenOnce){
            suppressAutoOpenOnce = false;
            return;
          }

          // Do not auto-load the old viewer after the Ajax list refresh; the feed-card list is
          // the primary view, and auto-loading the hidden viewer causes a second visible reflow.
        }

        function refreshList(keepSelection){
          var filter = $('#filterSel').val();
          var keepScroll = (desiredSidebarScroll !== null)
            ? Number(desiredSidebarScroll || 0)
            : Number(getSidebarScroll() || 0);

          var sendFilter = (String(filter||'all') === 'mine' || String(filter||'all') === 'top') ? 'all' : filter;
          var sendOrder  = (String(filter||'all') === 'top') ? 'views' : 'recent';

          $.getJSON(API_URL, { ajax:'list', filter:sendFilter, order:sendOrder, limit:200 }, function(res){
            if(!res || !res.ok){
              var errMsg = (res && res.error) ? String(res.error) : 'Unable to load posts (API not reachable).';
              var $plErr = $('#postList');
              $plErr.empty().append($('<div class="p-2 text-danger"></div>').text(errMsg));
              try{ renderMobileFeed([]); }catch(e){}
              try{ rebuildStoriesBar([]); }catch(e){}
              updateUnreadBadge(0);
              setTimeout(function(){ setSidebarScroll(keepScroll); }, 0);
              desiredSidebarScroll = null;
              return;
            }

            allItems = res.items || [];
            if(res.me_id) window.__MSB_FEED_ME_ID = Number(res.me_id || 0);
            if(!keepSelection) selectedId = 0;

            applySearchFilter();

            setTimeout(function(){ setSidebarScroll(keepScroll); }, 0);
            setTimeout(function(){ setSidebarScroll(keepScroll); }, 120);

            desiredSidebarScroll = null;
          });
        }

        function setReactionButtons(my){
          my = String(my||'');
          window.__myReaction = my;

          var $likeBtn = $('#btnLike, #btnLikeV, #btnFooterLike');
          var $loveBtn = $('#btnLove, #btnLoveV, #btnFooterLove');

          // reset classes
          $likeBtn.removeClass('rx-active rx-like');
          $loveBtn.removeClass('rx-active rx-love');
          if(my === 'like') $likeBtn.addClass('rx-active rx-like');
          if(my !== '' && my !== 'like') $loveBtn.addClass('rx-active rx-love');
          if(window.MSBReactions){
            $likeBtn.each(function(){ window.MSBReactions.applyLikeButton(this, my === 'like' ? my : ''); });
            $loveBtn.each(function(){ window.MSBReactions.applyReactionButton(this, my !== 'like' ? my : '', 'love'); });
          }
          try{
            syncInstaReactionIcons(my, $('#btnSave').hasClass('is-save'));
          }catch(e){}
          syncImageOverlayActions();
        }


        window.refreshList = refreshList;

        function srcOf(a){
          var s = (a && (a.url || a.file_path || a.path || a.src)) ? (a.url || a.file_path || a.path || a.src) : '';
          s = String(s||'').trim().replace(/^public_user\//,'');
          return s;
        }

        function pauseLastVideo(){
          try{ if(lastVideoEl && !lastVideoEl.paused) lastVideoEl.pause(); }catch(e){}
          lastVideoEl = null;
        }

        function clearAutoAdvanceTimer(){
          if(autoAdvanceTimer){
            clearTimeout(autoAdvanceTimer);
            autoAdvanceTimer = null;
          }
          stopAutoAdvanceProgress(false);
          autoAdvanceStartedAt = 0;
          autoAdvanceDelay = 0;
          autoAdvanceRemaining = 0;
        }

        function setAutoAdvanceProgress(pct){
          var bar = document.getElementById('pvAutoProgressBar');
          if(!bar) return;
          pct = Math.max(0, Math.min(100, Number(pct || 0)));
          bar.style.width = pct + '%';
        }

        function stopAutoAdvanceProgress(keepPosition){
          if(autoAdvanceProgressRaf){
            cancelAnimationFrame(autoAdvanceProgressRaf);
            autoAdvanceProgressRaf = 0;
          }
          if(!keepPosition) setAutoAdvanceProgress(0);
        }

        function startAutoAdvanceProgress(){
          stopAutoAdvanceProgress(true);
          if(!autoAdvanceStartedAt || !autoAdvanceDelay) return;
          var tick = function(){
            if(!autoAdvanceStartedAt || !autoAdvanceDelay){
              stopAutoAdvanceProgress(false);
              return;
            }
            var elapsed = Math.max(0, Date.now() - autoAdvanceStartedAt);
            setAutoAdvanceProgress((elapsed / autoAdvanceDelay) * 100);
            if(elapsed >= autoAdvanceDelay){
              autoAdvanceProgressRaf = 0;
              setAutoAdvanceProgress(100);
              return;
            }
            autoAdvanceProgressRaf = requestAnimationFrame(tick);
          };
          autoAdvanceProgressRaf = requestAnimationFrame(tick);
        }

        function visibleSidebarRowIds(){
          return Array.prototype.map.call(
            document.querySelectorAll('#postList .pl-item[data-id]'),
            function(el){ return Number(el.getAttribute('data-id') || 0); }
          ).filter(function(id){ return id > 0; });
        }

        function nextSidebarRowId(currentId){
          var ids = visibleSidebarRowIds();
          var idx = ids.indexOf(Number(currentId || 0));
          if(idx < 0) return ids.length ? ids[0] : 0;
          return ids[idx + 1] || 0;
        }

        function estimatePostReadMs(post, atts){
          post = post || {};
          atts = Array.isArray(atts) ? atts : [];
          var title = String(post.title || '').trim();
          var desc = stripLayoutOverrideMarker(String(post.description || '').trim());
          var body = String(post.body || '').trim();
          var text = [title, body || desc].filter(Boolean).join(' ');
          var words = text ? text.split(/\s+/).filter(Boolean).length : 0;
          var readMs = Math.max(4500, Math.round((words / 3.2) * 1000));
          var mediaBonus = atts.length ? Math.min(6000, atts.length * 1800) : 0;
          return Math.min(30000, readMs + mediaBonus);
        }

        function scheduleAutoAdvance(postId, delayMs){
          clearAutoAdvanceTimer();
          postId = Number(postId || 0);
          delayMs = Math.max(2500, Number(delayMs || 0));
          if(!postId || !delayMs) return;
          if(mfIsMobile && mfIsMobile()) return;
          try{
            if(window.matchMedia && window.matchMedia('(min-width: 992px)').matches) return;
          }catch(e){}
          autoAdvanceDelay = delayMs;
          autoAdvanceRemaining = delayMs;
          if(autoAdvanceHoverHold || document.body.classList.contains('lb-open')) return;
          autoAdvanceStartedAt = Date.now();
          startAutoAdvanceProgress();
          autoAdvanceTimer = setTimeout(function(){
            autoAdvanceTimer = null;
            stopAutoAdvanceProgress(false);
            autoAdvanceStartedAt = 0;
            autoAdvanceDelay = 0;
            autoAdvanceRemaining = 0;
            var nextId = nextSidebarRowId(postId);
            if(!nextId || Number(nextId) === Number(postId || 0)) return;
            desiredSidebarScroll = getSidebarScroll();
            loadPost(Number(nextId), true);
            setTimeout(function(){
              var row = document.querySelector('#postList .pl-item[data-id="' + String(nextId) + '"]');
              if(row && row.scrollIntoView) row.scrollIntoView({ behavior:'smooth', block:'nearest' });
            }, 40);
          }, delayMs);
        }

        function bindAutoAdvanceForMedia(postId, post, atts){
          postId = Number(postId || 0);
          if(!postId) return;
          if(mfIsMobile && mfIsMobile()) return;
          try{
            if(window.matchMedia && window.matchMedia('(min-width: 992px)').matches) return;
          }catch(e){}

          var delayMs = estimatePostReadMs(post, atts);
          var mainVideo = document.querySelector('#pvMedia video.ig-media-main');
          if(mainVideo){
            var applyVideoTiming = function(){
              var duration = Number(mainVideo.duration || 0);
              if(duration && isFinite(duration) && duration > 0){
                delayMs = Math.max(3500, Math.min(45000, Math.round(duration * 1000) + 800));
              }
              scheduleAutoAdvance(postId, delayMs);
            };
            if(mainVideo.readyState >= 1 && Number(mainVideo.duration || 0) > 0){
              applyVideoTiming();
            }else{
              mainVideo.addEventListener('loadedmetadata', applyVideoTiming, { once:true });
              scheduleAutoAdvance(postId, delayMs);
            }
            return;
          }

          scheduleAutoAdvance(postId, delayMs);
        }

        function syncViewerReelAspect(video){
          try{
            var body = document.querySelector('.ig-viewer-body.mode-reel');
            if(!body) return;
            var vw = Number(video && video.videoWidth || 0);
            var vh = Number(video && video.videoHeight || 0);
            if(vw > 0 && vh > 0){
              body.style.setProperty('--pv-reel-ar-w', String(vw));
              body.style.setProperty('--pv-reel-ar-h', String(vh));
            }else{
              body.style.removeProperty('--pv-reel-ar-w');
              body.style.removeProperty('--pv-reel-ar-h');
            }
          }catch(e){}
        }

        function syncViewerReelFit(video){
          try{
            if(!video) return;
            var isPhoneOrTablet = window.matchMedia('(max-width: 1180px)').matches;
            video.style.objectFit = isPhoneOrTablet ? 'contain' : 'cover';
            video.style.objectPosition = 'center center';
          }catch(e){}
        }

        function rememberCurrentVideoTime(){
          try{
            var pid = Number($('#curPostId').val()||0);
            if(!pid) return;
            if(lastVideoEl && !isNaN(lastVideoEl.currentTime)){
              playback[String(pid)] = { idx: Number(currentMediaIdx||0), time: Number(lastVideoEl.currentTime||0) };
              savePlayback();
            }
          }catch(e){}
        }

        function markRead(postId){
          $.post(API_URL, { ajax:'mark_read', post_id:postId }, function(){}, 'json');
        }

        function markReadAfterMediaReady(postId){
          var $m = $('#pvMedia');
          var el = $m.find('.ig-media-main').get(0);
          if(!el){
            markRead(postId);
            setTimeout(function(){ refreshList(true); }, 350);
            return;
          }

          var done = false;
          function finish(){
            if(done) return;
            done = true;
            markRead(postId);
            setTimeout(function(){ refreshList(true); }, 350);
          }

          if(el.tagName === 'IMG'){
            if(el.complete) return finish();
            el.addEventListener('load', finish, { once:true });
            el.addEventListener('error', finish, { once:true });
          } else if(el.tagName === 'VIDEO'){
            if(el.readyState >= 2) return finish();
            el.addEventListener('loadeddata', finish, { once:true });
            el.addEventListener('error', finish, { once:true });
          } else {
            finish();
          }
        }

        function setTextOnlyMode(on, bg){
          try{
            var $layout = $('.ig-layout');
            if(!$layout.length) return;

            if(on){
              $layout.addClass('is-text-only');
              if(bg) $layout.get(0).style.setProperty('--to-bg', String(bg));
              else $layout.get(0).style.removeProperty('--to-bg');
            }else{
              $layout.removeClass('is-text-only');
              $layout.get(0).style.removeProperty('--to-bg');
            }
          }catch(e){}
        }

        function setImageOverlayMode(on){
          if(isInstaFeedCard()){
            try{
              $('.ig-viewer-body').removeClass('mode-image-overlay').addClass('mode-insta-feed');
              $('#pvMedia .ig-image-overlay-actions, #pvMedia .ig-image-overlay-text').remove();
            }catch(e){}
            return;
          }
          try{
            $('.ig-viewer-body').toggleClass('mode-image-overlay', !!on);
            if(!on) $('#pvMedia .ig-image-overlay-actions, #pvMedia .ig-image-overlay-text').remove();
          }catch(e){}
        }

        function setMediaReelBottomMode(on){
          try{
            $('.ig-viewer-body').toggleClass('mode-media-reel-bottom', !!on);
          }catch(e){}
        }

        function buildMediaBottomDescHtml(txt){
          txt = String(txt || '').trim();
          if(!txt) return '';
          var shortTxt = firstSentences(txt, 3);
          var showInline = (shortTxt !== txt);
          return ''
            + '<div class="pv-long-wrap">'
            + '  <div class="pv-long-preview" style="-webkit-line-clamp:3;">' + esc(shortTxt) + (showInline ? '<span class="pv-inline-ellipsis">...</span>' : '') + '</div>'
            + (showInline ? '<a href="javascript:void(0)" class="pv-readmore-inline" id="pvInlineReadMore">See more</a>' : '')
            + '</div>';
        }

        function mountReelCaptionChrome(){
          try{
            if(!isReelMode) return;
            var $media = $('#pvMedia');
            if(!$media.length) return;
            $media.find('#pvCap').remove();
            var isBottomMode = $('.ig-viewer-body').hasClass('mode-media-reel-bottom');
            var inlineHtml = $.trim($('#pvTextBelow').html() || '');

            if(isBottomMode && inlineHtml){
              return;
            }

            var capTitle = String(currentTitle || '').trim();
            var capMeta  = String(currentAuthor || '').trim();
            var capText  = String(currentFullBody || currentFullDesc || '').trim();
            var capHtml = '' +
              '<div class="ig-media-caption" id="pvCap">' +
                '<div class="ig-cap-title" id="pvCapTitle"></div>' +
                '<div class="ig-cap-meta" id="pvCapMeta"></div>' +
                '<div class="d-flex align-items-center justify-content-between mb-2">' +
                  '<div class="ig-cap-text feed-desc clamp-2" id="pvCapText"></div>' +
                  '<a href="#" class="ig-cap-readmore" id="pvCapReadMore">See more</a>' +
                '</div>' +
              '</div>';
            $media.append(capHtml);

            var capNeedsMore = $('.ig-viewer-body').hasClass('mode-media-reel-bottom') && capText.length > 180;
            var capShortText = capNeedsMore ? capText.slice(0, 180).trim() : capText;
            $('#pvCapTitle').text(capTitle || '');
            $('#pvCapMeta').text(capMeta || '');
            if(capNeedsMore){
              $('#pvCapText').text(capShortText + '... ');
              $('#pvCapReadMore').text('See more').show();
            }else{
              $('#pvCapText').text(capText || '');
              if(sentenceCount(capText) >= 4) $('#pvCapReadMore').text('See more').show();
              else $('#pvCapReadMore').hide();
            }
            $('#pvCap').show();
          }catch(e){}
        }

        function mountReelBottomDescOverlay(){
          try{
            if(!isReelMode) return;
            if(!$('.ig-viewer-body').hasClass('mode-media-reel-bottom')) return;
            var $media = $('#pvMedia');
            if(!$media.length) return;
            var inlineHtml = buildMediaBottomDescHtml(String(currentFullBody || currentFullDesc || '').trim());
            $media.find('.ig-reel-bottom-desc').remove();
            if(!inlineHtml) return;
            $media.append('<div class="ig-reel-bottom-desc">'+inlineHtml+'</div>');
            $media.find('#pvCap').hide();
          }catch(e){}
        }

        function mountImageOverlayChrome(){
          try{
            if(!$('.ig-viewer-body').hasClass('mode-image-overlay')) return;
            var $media = $('#pvMedia');
            var textHtml = $.trim($('#pvTextBelow').html() || '');
            $media.find('.ig-image-overlay-actions, .ig-image-overlay-text').remove();
            $media.append(
              '<div class="ig-image-overlay-actions" aria-label="Image reactions">'+
                '<a type="button" class="ig-image-overlay-btn io-comment" data-act="comment"><span class="io-ico"><i class="fa fa-comment"></i></span><span class="io-count">0</span></a>'+
                '<a type="button" class="ig-image-overlay-btn io-love" data-act="love"><span class="io-ico"><i class="fa fa-heart"></i></span><span class="io-count">0</span></a>'+
                '<a type="button" class="ig-image-overlay-btn io-save" data-act="save"><span class="io-ico"><i class="fa fa-bookmark"></i></span><span class="io-count">0</span></a>'+
                '<a type="button" class="ig-image-overlay-btn io-share" data-act="share"><span class="io-ico"><i class="fa fa-share"></i></span><span class="io-count">0</span></a>'+
              '</div>'
            );
            if(textHtml){
              $media.append('<div class="ig-image-overlay-text">'+textHtml+'</div>');
            }
            syncImageOverlayActions();
          }catch(e){}
        }

        function syncImageOverlayActions(){
          try{
            var $wrap = $('#pvMedia .ig-image-overlay-actions');
            if(!$wrap.length) return;
            $wrap.find('.io-like .io-count').text($('#likeCount').text() || '0');
            $wrap.find('.io-comment .io-count').text($('#commentCount').text() || '0');
            $wrap.find('.io-love .io-count').text($('#loveCount').text() || '0');
            $wrap.find('.io-save .io-count').text($('#saveCount').text() || '0');
            $wrap.find('.io-share .io-count').text($('#shareCount').text() || '0');
            $wrap.find('.ig-image-overlay-btn').removeClass('is-like is-love is-save is-share');
            if($('#btnLike').hasClass('rx-like')) $wrap.find('.io-like').addClass('is-like');
            if($('#btnLove').hasClass('rx-love')) $wrap.find('.io-love').addClass('is-love');
            if($('#btnSave').hasClass('is-save')) $wrap.find('.io-save').addClass('is-save');
            if($('#btnShare').hasClass('is-share')) $wrap.find('.io-share').addClass('is-share');
          }catch(e){}
        }

        function setMediaOnlyMode(on){
          try{
            var $layout = $('.ig-layout');
            if(!$layout.length) return;

            var $cardBody = $('.ig-post-card .card-body');

            if(isInstaFeedCard()){
              $layout.toggleClass('is-media-only', !!on);
              $cardBody.toggleClass('media-only', !!on);
              return;
            }

            if(on){
              $layout.addClass('is-media-only');
              $cardBody.addClass('media-only');

              var $top = $('.ig-topbar').first();
              if($top.length && !$top.hasClass('pv-top-detached')){
                $top.addClass('pv-top-detached');
                $top.detach();
                $layout.prepend($top);
              }

              $('#pvDesc').show();
            }else{
              $layout.removeClass('is-media-only');
              $cardBody.removeClass('media-only');

              var $topBack = $('.ig-topbar.pv-top-detached').first();
              if($topBack.length){
                $topBack.removeClass('pv-top-detached');
                $topBack.detach();
                $('.ig-details').prepend($topBack);
              }

              $('#pvDesc').hide();
            }
          }catch(e){}
        }

        function setFullMediaMode(on){
          try{
            var $layout = $('.ig-layout');
            if(!$layout.length) return;

            var $cardBody = $('.ig-post-card .card-body');

            if(isInstaFeedCard()){
              $layout.toggleClass('is-full-media', !!on);
              $cardBody.toggleClass('full-media', !!on);
              if(on){
                $('#pvDesc').hide();
                $('#pvBody').show();
              }
              return;
            }

            if(on){
              $layout.addClass('is-full-media');
              $cardBody.addClass('full-media');

              // detach topbar above media (like media-only)
              var $top = $('.ig-topbar').first();
              if($top.length && !$top.hasClass('pv-top-detached')){
                $top.addClass('pv-top-detached');
                $top.detach();
                $layout.prepend($top);
              }

              // in full-media, we manage text via #pvTextBelow
              $('#pvDesc').hide();
              $('#pvBody').hide();

            }else{
              $layout.removeClass('is-full-media');
              $cardBody.removeClass('full-media');

              // return topbar into details if detached
              var $topBack = $('.ig-topbar.pv-top-detached').first();
              if($topBack.length){
                $topBack.removeClass('pv-top-detached');
                $topBack.detach();
                $('.ig-details').prepend($topBack);
              }
            }
          }catch(e){}
        }


                /* =====================================================
           ✅ MISSING CORE LOGIC (Load + Render post data)
           This section was previously left as a placeholder.
           It loads ALL post data from feed_api.php and updates:
           - Viewer (left big card)
           - Counts (like/love/comments)
           - Comments modal + Left comments panel (leftbar.php)
           ===================================================== */

        // expose API_URL for leftbar.php (it checks window.API_URL)
        try{ window.API_URL = API_URL; }catch(e){}

        // ensure hidden current post id exists (some earlier versions missed it)
        if(!document.getElementById('curPostId')){
          var h = document.createElement('input');
          h.type = 'hidden';
          h.id = 'curPostId';
          h.value = '0';
          document.body.appendChild(h);
        }

        function fmtDateShort(dt){
          var m = parseMoment(dt);
          if(!m) return '';
          return m.format('YYYY-MM-DD HH:mm');
        }
        function timeAgo(dt){
          var m = parseMoment(dt);
          if(!m) return '';
          return m.fromNow();
        }
        function timeAgoShort(dt){
          var m = parseMoment(dt);
          if(!m || !m.isValid()) return '';
          var mins = moment().diff(m, 'minutes');
          if(mins < 1) return 'now';
          if(mins < 60) return mins + 'm';
          var hrs = moment().diff(m, 'hours');
          if(hrs < 24) return hrs + 'h';
          var days = moment().diff(m, 'days');
          if(days < 7) return days + 'd';
          var weeks = moment().diff(m, 'weeks');
          if(weeks < 5) return weeks + 'w';
          return m.format('MMM D');
        }
        function formatCompactCount(n){
          n = Number(n || 0);
          if(!isFinite(n) || n < 0) return '0';
          if(n >= 1000000){
            var mv = n / 1000000;
            return (mv >= 10 ? Math.round(mv) : mv.toFixed(1).replace(/\.0$/, '')) + 'M';
          }
          if(n >= 10000) return Math.round(n / 1000) + 'K';
          if(n >= 1000){
            var kv = n / 1000;
            return kv.toFixed(1).replace(/\.0$/, '') + 'K';
          }
          return String(n);
        }
        function syncInstaPostChrome(counts){
          counts = counts || {};
          var loves = Number(counts.love_count != null ? counts.love_count : ($('#loveCount').text() || 0));
          var comments = Number(counts.comment_count != null ? counts.comment_count : ($('#commentCount').text() || 0));
          var loveText = formatCompactCount(loves);
          var commentText = formatCompactCount(comments);
          $('#loveCount, #loveCountV, #loveCountF').text(loveText);
          $('#loveCountLikes').text(loveText);
          $('#commentCount, #commentCountV, #commentCountF, #commentCountTextF').text(commentText);
        }
        function syncInstaReactionIcons(my, saved){
          my = String(my || '');
          saved = !!saved;
          var loved = (my !== '' && my !== 'like');
          var $heart = $('.ig-insta-ico-heart');
          $heart.removeClass('fa-heart fa-heart-o').addClass(loved ? 'fa-heart' : 'fa-heart-o');
          var $save = $('.ig-insta-ico-save');
          $save.removeClass('fa-bookmark fa-bookmark-o').addClass(saved ? 'fa-bookmark' : 'fa-bookmark-o');
        }

        function setViewerHeader(authorText, dt, post){
          currentAuthor = String(authorText||'').trim();
          currentDate   = fmtDateShort(dt);

          var profileHref = peerProfileHref(post || {});
          $('#pvAuthor').html('<a class="pv-peer-link" href="' + esc(profileHref) + '">' + esc(currentAuthor || 'Post') + '</a>');
          $('#pvMeta').text(currentDate ? ('Posted: ' + currentDate) : ' ');
          $('#pvTimeAgo').text(timeAgoShort(dt) || ' ');
          $('#pvTimeAgoPill').text(timeAgo(dt) || ' ');
          $('#pvMetaPills').hide();

          var ini = initialsFromName(currentAuthor || 'Post');
          var base = colorFromString(ini);
          var bg = gradientFromBase(base);

          currentAvatarText = ini || 'P';
          currentAvatarBg   = bg || '#111827';

          var avatarUrl = avatarUrlFor(post || {}, 96);
          $('#pvAvatar.feed-avatar').html('<img src="' + esc(avatarUrl) + '" alt="' + esc(currentAuthor || 'Profile') + '">').css('background', 'transparent').attr('title', currentAuthor || 'Profile').off('click.peer').on('click.peer', function(ev){ ev.preventDefault(); ev.stopPropagation(); window.location.href = profileHref; });
        }

        function detectKind(path, typeHint){
          // ✅ Important:
          // Older posts may have attachment.type saved as "file" even when it's actually
          // an image/video/pdf. For reliable rendering, we ONLY trust typeHint when it's
          // a specific media kind, otherwise we infer from extension.
          var t = String(typeHint||'').toLowerCase().trim();
          if(t && t !== 'file') return t;
          var clean = String(path||'').split('?')[0].split('#')[0].toLowerCase();
          if(clean.match(/\.(mp4|webm|ogg)$/)) return 'video';
          if(clean.match(/\.(jpg|jpeg|png|gif|webp|bmp|svg)$/)) return 'image';
          if(clean.match(/\.pdf$/)) return 'pdf';
          if(clean.match(/\.(ppt|pptx)$/)) return 'pptx';
          if(clean.match(/\.(doc|docx)$/)) return 'docx';
          if(clean.match(/\.(xls|xlsx)$/)) return 'xlsx';
          return 'file';
        }

        function extractLayoutOverrideMarker(txt){
          var s = String(txt || '');
          var m = s.match(/\[\[layout:([a-z0-9_]+)\]\]/i);
          return m ? String(m[1] || '').toLowerCase().trim() : '';
        }

        function fileChip(kind){
          kind = String(kind||'file').toLowerCase();
          if(kind === 'pdf') return '<span class="badge badge-light">PDF</span>';
          if(kind === 'pptx') return '<span class="badge badge-light">PPTX</span>';
          if(kind === 'docx') return '<span class="badge badge-light">DOCX</span>';
          if(kind === 'xlsx') return '<span class="badge badge-light">XLSX</span>';
          return '<span class="badge badge-light">FILE</span>';
        }

        function renderMainMedia(src, kind){
          src = String(src||'').trim();
          kind = detectKind(src, kind);

          pauseLastVideo();
          try{
            var viewerBody = document.querySelector('.ig-viewer-body');
            if(viewerBody && !isReelMode){
              viewerBody.style.removeProperty('--pv-reel-ar-w');
              viewerBody.style.removeProperty('--pv-reel-ar-h');
            }
          }catch(e){}

          // update eye modal src
          try{ $('#btnOpenMedia').data('src', src); }catch(e){}

          if(!src){
            $('#pvMedia').html(
              '<div class="ig-media-empty">' +
              '  <i class="icon ion-ios-photos-outline"></i>' +
              '  <div class="mt-2">Select a post to view media</div>' +
              '</div>'
            );
            try{
              $('#igInstaDotsHost').empty();
              $('#btnInstaMediaNext, #btnInstaMediaPrev').hide();
              window.__feedMediaAtts = [];
            }catch(e){}
            return;
          }

          if(kind === 'image'){
            $('#pvMedia').html('<img class="ig-media-main" src="'+esc(src)+'" alt="">');
            mountImageOverlayChrome();
          }else if(kind === 'gif'){
            $('#pvMedia').html('<img class="ig-media-main" src="'+esc(src)+'" alt="">');
            mountImageOverlayChrome();
          }else if(kind === 'video'){
            if(isReelMode){
              $('#pvMedia').html('<video class="ig-media-main ig-reel-video js-pv-reel-video" src="'+esc(src)+'" playsinline autoplay loop muted></video>');
              mountReelCaptionChrome();
              mountReelBottomDescOverlay();
            }else{
              $('#pvMedia').html('<video class="ig-media-main" src="'+esc(src)+'" controls playsinline autoplay loop muted></video>');
            }
            try{
              lastVideoEl = document.querySelector('#pvMedia video.ig-media-main');
              if(isReelMode && lastVideoEl){
                var syncReelViewer = function(){
                  syncViewerReelAspect(lastVideoEl);
                  syncViewerReelFit(lastVideoEl);
                };
                lastVideoEl.addEventListener('loadedmetadata', syncReelViewer);
                lastVideoEl.addEventListener('loadeddata', syncReelViewer);
                lastVideoEl.addEventListener('resize', syncReelViewer);
                if(lastVideoEl.readyState >= 1) syncReelViewer();
              }
              // Reel: tap to play/pause, first tap un-mutes
              try{
                if(isReelMode && lastVideoEl){
                  lastVideoEl.addEventListener('click', function(){
                    try{
                      if(lastVideoEl.muted){ lastVideoEl.muted = false; }
                      if(lastVideoEl.paused) lastVideoEl.play();
                      else lastVideoEl.pause();
                    }catch(_e){}
                  }, {passive:true});
                }
              }catch(_e){}
              // restore playback
              var pid = Number($('#curPostId').val()||0);
              var p = playback[String(pid)];
              if(lastVideoEl && p && Number(p.idx) === Number(currentMediaIdx||0) && !isNaN(p.time)){
                lastVideoEl.currentTime = Number(p.time||0);
              }
            }catch(e){}
          }else if(kind === 'pdf'){
            $('#pvMedia').html('<iframe class="ig-media-main" src="'+esc(src)+'" title="PDF" style="border:0;"></iframe>');
          }else{
            // other file types: show file card with open link
            $('#pvMedia').html(
              '<div class="ig-media-empty" style="max-width:92%; text-align:left;">' +
              '  <div class="d-flex align-items-center" style="gap:10px;">' +
              '    <div style="font-size:34px;"><i class="icon ion-document-text"></i></div>' +
              '    <div class="flex-grow-1">' +
              '      <div style="font-weight:800;">Attachment</div>' +
              '      <div class="text-muted" style="font-size:12px;word-break:break-all;">'+esc(src)+'</div>' +
              '      <div class="mt-2">' + fileChip(kind) + ' <a class="btn btn-sm btn-outline-light ml-2" href="'+esc(src)+'" target="_blank" rel="noopener">Open</a></div>' +
              '    </div>' +
              '  </div>' +
              '</div>'
            );
          }
        }

        function instaGoMedia(delta){
          var atts = window.__feedMediaAtts || [];
          if(!atts.length) return;
          var idx = Number(currentMediaIdx || 0) + Number(delta || 0);
          if(idx < 0 || idx >= atts.length) return;
          rememberCurrentVideoTime();
          var a = atts[idx] || {};
          var src = srcOf(a);
          var kind = detectKind(src, a.type);
          currentMediaIdx = idx;
          renderMainMedia(src, kind);
          renderStrip(atts);
        }

        function renderStrip(atts){
          atts = atts || [];
          window.__feedMediaAtts = atts.slice(0);
          $('#igInstaDotsHost').empty();
          var showNav = atts.length > 1;
          $('#btnInstaMediaNext').toggle(showNav && Number(currentMediaIdx||0) < (atts.length - 1));
          $('#btnInstaMediaPrev').toggle(showNav && Number(currentMediaIdx||0) > 0);
          if(!showNav) return;

          var html = '<div class="ig-strip ig-insta-strip" id="pvStrip" role="tablist" aria-label="Media slides">';
          for(var i=0;i<atts.length;i++){
            var a = atts[i] || {};
            var src = srcOf(a);
            var kind = detectKind(src, a.type);
            html += '<a href="#" class="ig-strip-item'+(i===currentMediaIdx?' active':'')+'" data-idx="'+i+'" data-src="'+esc(src)+'" data-kind="'+esc(kind)+'" aria-label="Go to media '+(i+1)+'" title="Media '+(i+1)+'">'+(i+1)+'</a>';
          }
          html += '</div>';

          $('#igInstaDotsHost').html(html);

          $('#pvStrip .ig-strip-item').off('click').on('click', function(e){
            e.preventDefault();
            rememberCurrentVideoTime();

            var idx = Number($(this).data('idx')||0);
            var src = String($(this).data('src')||'');
            var kind = String($(this).data('kind')||'');
            currentMediaIdx = idx;

            renderMainMedia(src, kind);
            renderStrip(atts);
          });
        }

        function applyLayoutMode(post, atts){
          var hasMedia = (atts && atts.length) ? true : false;

          var title = String((post && post.title) || '').trim();
          var shortDesc = ''; // short removed (Rule #2 book UI kept without it)
          var longBody = String((post && post.body) || '').trim();         // long
          var fallbackDesc = stripLayoutOverrideMarker((post && post.description) || '');
          var visibleLongBody = String(longBody || fallbackDesc || '').trim();

          var hasTitle = title.length > 0;
          var hasShort = shortDesc.length > 0;
          var hasLong  = visibleLongBody.length > 0;

          // Reset modes
          setTextOnlyMode(false);
          setMediaOnlyMode(false);
          setFullMediaMode(false);
          setImageOverlayMode(false);
          setMediaReelBottomMode(false);

          // ✅ Book rail OFF by default (only ON for book layout)
          setBookMode(false);
          setReelMode(false);


          // Clear / default visibility
          $('#pvTextBelow').hide().empty();
          $('#pvBody').hide().empty();
          $('#pvDesc').hide().empty();

          // Helper: build inline "Read more" that only appears when >= 4 sentences
          function inlineTextBlock(txt, clampLines){
            txt = String(txt||'').trim();
            if(!txt) return '';
            var nSent = sentenceCount(txt);
            var showInline = (nSent >= 4);
            var inlineBtn = showInline
              ? '<a href="javascript:void(0)" class="pv-readmore-inline" id="pvInlineReadMore">Read more</a>'
              : '';
            var style = '';
            if(clampLines && Number(clampLines) > 0){
              style = ' style="-webkit-line-clamp:'+Number(clampLines)+';"';
            }
            return ''
              + '<div class="pv-long-wrap" style="padding:10px 0 10px 0;">'
              + '  <div class="pv-long-preview"'+style+'>' + esc(txt) + '</div>'
              + '  ' + inlineBtn
              + '</div>';
          }

          function imageOverlayTextBlock(txt){
            txt = String(txt||'').trim();
            if(!txt) return '';
            var charLimit = 180;
            var showInline = (txt.length > charLimit);
            var shortTxt = txt;
            if(showInline){
              shortTxt = txt.slice(0, charLimit).trim();
            }
            return ''
              + '<div class="pv-long-wrap">'
              + '  <div class="pv-long-preview" style="-webkit-line-clamp:2;">' + esc(shortTxt) + (showInline ? '<span class="pv-inline-ellipsis">...</span>' : '') + '</div>'
              + (showInline ? '<a href="javascript:void(0)" class="pv-readmore-inline" id="pvInlineReadMore">See more</a>' : '')
              + '</div>';
          }

          
          var firstAtt = (atts && atts.length) ? (atts[0] || {}) : {};
          var firstSrc = srcOf(firstAtt);
          var firstKind = detectKind(firstSrc, firstAtt.type);
          var isImageOnlyCard = (firstKind === 'image' || firstKind === 'gif');
          var declaredLayout = String((post && (post.layout || post.layout_type || post.post_type || post.type)) || '').toLowerCase().trim();
          if(!declaredLayout){
            declaredLayout = extractLayoutOverrideMarker((post && post.description) || '');
          }
          var wantsImageBottom = (declaredLayout === 'image_bottom' && isImageOnlyCard);
          var wantsMediaReelBottom = (declaredLayout === 'media_reel_bottom' && (firstKind === 'image' || firstKind === 'gif' || firstKind === 'video'));

          // ============================
          // ✅ REEL (declared reel posts only)
          // ============================
          try{
            var isSingleVideoOnlyPost = (firstKind === 'video' && !hasTitle && !hasLong && Number((atts && atts.length) || 0) === 1);
            var wantsReel = (declaredLayout === 'reel' || declaredLayout === 'reels' || declaredLayout === 'tiktok' || (wantsMediaReelBottom && firstKind === 'video') || isSingleVideoOnlyPost);

            if(wantsReel && !(isInstaFeedCard() && !isSmallScreen())){
              setReelMode(true);

              // show caption overlay (title + author + short body preview)
              var capTitle = String(title || '').trim();
              var capMeta  = String(currentAuthor || '').trim();
              var capText  = String(visibleLongBody || '').trim();
              var useBottomReelCaption = (!!capText && (wantsMediaReelBottom || firstKind === 'video'));
              if(useBottomReelCaption) setMediaReelBottomMode(true);

              if(useBottomReelCaption && capText){
                $('#pvTextBelow').html(imageOverlayTextBlock(capText));
              }else{
                $('#pvTextBelow').empty();
              }
              var capShortText = firstSentences(capText, 3);
              var capNeedsMore = useBottomReelCaption && capShortText !== capText;

              $('#pvCapTitle').text(capTitle || '');
              $('#pvCapMeta').text(capMeta || '');
              if(capNeedsMore){
                $('#pvCapText').text(capShortText + '... ');
                $('#pvCapReadMore').text('See more');
              }else{
                $('#pvCapText').text(capText || '');
                $('#pvCapReadMore').text('Read more');
              }

              // Only show Read more if >= 4 sentences
              var n = sentenceCount(capText);
              if((useBottomReelCaption && capNeedsMore) || (!useBottomReelCaption && n >= 4)) $('#pvCapReadMore').show();
              else $('#pvCapReadMore').hide();

              $('#pvCap').show();

              // Reel uses media-only base, but CSS removes background and overlays actions
              setMediaOnlyMode(true);
              return;
            } else {
              $('#pvCap').hide();
            }
          }catch(e){
            try{ $('#pvCap').hide(); }catch(_e){}
          }

// ============================
          // ✅ NO MEDIA (Text-only cards)
          // ============================
          if(!hasMedia){
            setTextOnlyMode(true, '#f5f7fb');

            var html = '';

            // Title stays at the top (already in #pvTitle)
            // Actions row stays under title (already in UI)

            if(hasShort && !hasLong){
              // ✅ CASE 6: short only
              html += inlineTextBlock(shortDesc, 6);
            } else if(!hasShort && hasLong){
              // ✅ CASE 7: long only
              html += inlineTextBlock(longBody, 12);
            } else {
              // ✅ CASE 5: short + long
              if(hasShort) html += '<div class="pv-short">' + esc(shortDesc) + '</div>';
              if(hasLong)  html += inlineTextBlock(longBody, 10);
            }

            if(html){
              $('#pvTextBelow').show().html(html);
            }
            return;
          }

          // ============================
          // ✅ HAS MEDIA
          // ============================

          if(wantsMediaReelBottom && isImageOnlyCard){
            setMediaOnlyMode(true);
            setImageOverlayMode(true);
            setMediaReelBottomMode(true);
            if(hasLong){
              $('#pvTextBelow').show().html(imageOverlayTextBlock(visibleLongBody));
            }else{
              $('#pvTextBelow').hide().empty();
            }
            return;
          }

          if(wantsImageBottom){
            setMediaOnlyMode(true);
            setImageOverlayMode(true);
            if(hasLong){
              $('#pvTextBelow').show().html(imageOverlayTextBlock(visibleLongBody));
            }else{
              $('#pvTextBelow').hide().empty();
            }
            return;
          }

          if(isImageOnlyCard && !hasLong){
            setMediaOnlyMode(true);
            setImageOverlayMode(true);
            $('#pvTextBelow').hide().empty();
            return;
          }

          // ✅ CASE 2: title + short + long + media  => BOOK layout (Title optional)
          // - Media on the left
          // - Long body beside media
          // - Short text under actions
          // - Read more ONLY at end of the long body (inline), when >= 4 sentences
          if(hasLong){
            setBookMode(true);

            // ✅ Rule #2 BOOK layout kept: title + long body + media (short removed)
            $('#pvBody').show().html(inlineTextBlock(longBody, 12));
            alignBookRail();

            // No short text shown
            return;
          }

          // ✅ CASE 1: media + title only
          // - Title top
          // - Media full
          // - Actions under media
          if(hasTitle && !hasShort && !hasLong){
            setMediaOnlyMode(true);
            if(firstKind === 'image' || firstKind === 'gif') setImageOverlayMode(true);
            return;
          }

          // ✅ CASE 4: media + title + short (no long)
          // - Title top
          // - Media full
          // - Actions under media
          // - Short text under actions (and inline read more if >= 4 sentences)
          if(hasTitle && hasShort && !hasLong){
            setMediaOnlyMode(true);
            $('#pvTextBelow').show().html(inlineTextBlock(shortDesc, 6));
            return;
          }

          // ✅ CASE 3: media + long (title optional), no short
          // - Title top
          // - Media full
          // - Actions under media
          // - Long text under actions (inline read more at end)
          if(hasLong){
            setFullMediaMode(true);
            $('#pvTextBelow').show().html(inlineTextBlock(longBody, 8));
            return;
          }

          // Fallback: media only
          setMediaOnlyMode(true);
          if(firstKind === 'image' || firstKind === 'gif') setImageOverlayMode(true);
        }



        function updateOwnerButtons(ownerId, postId){
          currentOwnerId = Number(ownerId||0);

          // create/edit button
          if(!STAFF_READONLY && Number(currentOwnerId) === Number(ME_ID)){
            $('#btnCreateEditIcon').attr('class','fa fa-pencil');
            $('#btnCreateEdit').attr('href', 'dashboard.php?modal=1&edit=' + encodeURIComponent(String(postId)));
            $('#btnCreateEdit').attr('data-create-post-modal', '1');
            $('#btnCreateEdit').attr('title','Edit Post');
            $('#btnCreateEditLabel').text('Edit post');
          }else{
            $('#btnCreateEditIcon').attr('class','fa fa-plus');
            $('#btnCreateEdit').attr('href', 'dashboard.php?modal=1');
            $('#btnCreateEdit').attr('data-create-post-modal', '1');
            $('#btnCreateEdit').attr('title','Create New Post');
            $('#btnCreateEditLabel').text('Create post');
          }
        }

        function loadPost(postId, pushHistory, loadOpts){
          postId = Number(postId||0);
          if(!postId) return;
          loadOpts = loadOpts || {};

          clearAutoAdvanceTimer();
          rememberCurrentVideoTime();
          pauseLastVideo();

          if(pushHistory){
            // save back-stack (for the back arrow button)
            if(selectedId && Number(selectedId) !== Number(postId)){
              navStack.push(Number(selectedId));
              if(navStack.length > 50) navStack.shift();
            }
          }

          selectedId = postId;
          $('#curPostId').val(String(postId));

          $('#postList .pl-item').removeClass('active');
          $('#postList .pl-item[data-id="'+postId+'"]').addClass('active');

          if(!loadOpts.fromFeedScroll){
            // viewer loading state
            $('#pvTitle').text('Loading...');
            $('#pvDesc').text('');
            $('#pvBody').text('');
            $('#commentCount').text('0');
            $('#viewCount, #viewCountV').text('0');
            $('#likeCount').text('0');
            $('#loveCount').text('0');
            $('#pvFooterDesc').text('Loading...');
            $('#loveCountF, #likeCountF, #commentCountF, #commentCountTextF, #shareCountF, #saveCountF').text('0');
            $('#viewCountTextF').text('0 views');
            $('#pvFooterReadMore').hide();
            // share/save counts
            $('#shareCount, #shareCountV').text('0');
            $('#saveCount, #saveCountV').text('0');
            setReactionButtons('');
            setShareSaveButtons({shared:0, saved:0});

            $('#pvMedia').html(
              '<div class="ig-media-empty">' +
              '  <i class="icon ion-load-a"></i>' +
              '  <div class="mt-2">Loading…</div>' +
              '</div>'
            );
          }

          $.getJSON(API_URL, { ajax:'view', id:postId, post_id:postId, count_view:1 }, function(res){
            if(!res || !res.ok){
              $('#pvTitle').text('Unable to load this post.');
              $('#pvMedia').html('<div class="ig-media-empty"><div class="text-muted">Error loading post.</div></div>');
              return;
            }

            var post = res.post || {};
            window.__curPost = post;
            var atts = res.attachments || [];
            var counts = res.counts || {};
            var comments = res.comments || [];

            currentTitle = String(post.title || '').trim() || '';
            currentFullDesc = formatReadMoreTextPreserve(String(post.description || '').trim());
            currentFullBody = formatReadMoreTextPreserve(String(post.body || '').trim());

            setViewerHeader(peerLabel(post.display_name, post.username), postDate(post), post);
            updateOwnerButtons(post.user_id, postId);

            if(currentTitle){
              $('#pvTitle').html('<strong class="ig-insta-cap-user">' + esc(currentAuthor || 'Post') + '</strong> ' + esc(currentTitle));
            }else{
              $('#pvTitle').html('<strong class="ig-insta-cap-user">' + esc(currentAuthor || 'Post') + '</strong>');
            }
            $('#pvDesc').text('');
            $('#pvBody').text(currentFullBody);

            applyReadMore('');
            // counts
            $('#likeCount, #likeCountV').text(String(counts.like_count || 0));
            syncInstaPostChrome({
              love_count: counts.love_count || 0,
              comment_count: comments.length || 0
            });
            $('#viewCount, #viewCountV').text(String(post.views_count || 0));
            $('#likeCountF').text(String(counts.like_count || 0));
            $('#loveCountF').text(String(counts.love_count || 0));
            $('#commentCountF, #commentCountTextF').text(String(comments.length || 0));
            $('#viewCountTextF').text(String(post.views_count || 0) + ' views');

            // cache for leftbar comments tray
            currentCommentsCache = comments || [];
            currentCommentsPostId = postId;
            if(window.TTComments && typeof window.TTComments.render === 'function' && Number(currentCommentsPostId) === Number(postId)){
              // keep count in sync even if tray is open
              try{ window.TTComments.render(currentCommentsCache); }catch(e){}
            }
            setReactionButtons(counts.my_reaction || '');
            syncImageOverlayActions();
            refreshViewerFooter();

            // ✅ share/save counts + state
            loadTrackCounts(postId);

            // layout and media
            applyLayoutMode(post, atts);
            if(isInstaFeedCard()){
              try{ $('.ig-viewer-body').addClass('mode-insta-feed'); }catch(e){}
            }
            currentMediaIdx = 0;

            if(atts && atts.length){
              var first = atts[0] || {};
              var src = srcOf(first);
              var kind = detectKind(src, first.type);
              renderMainMedia(src, kind);
              renderStrip(atts);
              if(isReelMode){
                mountReelCaptionChrome();
                mountReelBottomDescOverlay();
              }
            }else{
              renderMainMedia('', '');
            }

            // mark read after media becomes ready
            try{ markReadAfterMediaReady(postId); }catch(e){}
            try{ bindAutoAdvanceForMedia(postId, post, atts); }catch(e){}

            // ✅ push comments to leftbar (without forcing it open)
            try{
              if(window.TTComments && typeof window.TTComments.setPost === 'function'){
                window.TTComments.setPost(postId, comments, false);
              }
            }catch(e){}

            updateBackButton();
            if(loadOpts.fromFeedScroll || loadOpts.resetFeedScroll){
              requestAnimationFrame(function(){
                requestAnimationFrame(resetFeedScrollTop);
              });
            }
          });
        }
        try{ window.loadPost = loadPost; }catch(e){}

        // -----------------------------
        // SHARE / SAVE: counts + state
        // -----------------------------
        function setShareSaveButtons(state){
          state = state || {};
          var shared = Number(state.shared||0) ? 1 : 0;
          var saved  = Number(state.saved||0)  ? 1 : 0;

          // SHARE color (dark grey)
          $('#btnShare, #btnShareV')
            .toggleClass('is-share', !!shared)
            .attr('data-locked', shared ? '1' : '0');

          // SAVE color (yellow)
          $('#btnSave, #btnSaveV')
            .toggleClass('is-save', !!saved)
            .attr('data-locked', saved ? '1' : '0');
          try{
            syncInstaReactionIcons(window.__myReaction || '', !!saved);
          }catch(e){}
          $('#btnFooterShare')
            .toggleClass('is-share', !!shared)
            .attr('data-locked', shared ? '1' : '0');
          $('#btnFooterSave')
            .toggleClass('is-save', !!saved)
            .attr('data-locked', saved ? '1' : '0');
        }

        function applyTrackCounts(res){
          if(!res || !res.ok) return;
          $('#shareCount, #shareCountV').text(String(res.share_count||0));
          $('#saveCount, #saveCountV').text(String(res.save_count||0));
          $('#shareCountF').text(String(res.share_count||0));
          $('#saveCountF').text(String(res.save_count||0));
          setShareSaveButtons((res.state||{}));
          syncImageOverlayActions();
        }

        function loadTrackCounts(postId){
          postId = Number(postId||0);
          if(!postId) return;
          $.getJSON(API_URL, { ajax:'track_counts', post_id:postId }, function(res){
            applyTrackCounts(res);
          });
        }

        // sidebar click -> load post
        $(document).on('click', '#postList .pl-item', function(e){
          // ignore clicks on dropdown button/menu
          if($(e.target).closest('.pl-more-wrap, .dropdown-menu, .pl-more').length) return;

          var id = Number($(this).data('id') || 0);
          if(!id) return;

          // remember sidebar scroll so Find buttons can return user
          desiredSidebarScroll = getSidebarScroll();
          loadPost(id, true, { fromSidebar:true });
        });

        // read more from sidebar (ensures post is loaded first)
        $(document).on('click', '#postList .pl-readmore', function(e){
          e.preventDefault();
          var id = Number($(this).data('id') || 0);
          if(!id) return;
          if(Number(id) !== Number(selectedId)){
            desiredSidebarScroll = getSidebarScroll();
            loadPost(id, true);
            setTimeout(function(){ openReadMore(); }, 250);
          }else{
            openReadMore();
          }
        });

        // edit
        $(document).on('click', '#postList .pl-edit', function(e){
          e.preventDefault();
          var id = Number($(this).data('id') || 0);
          if(!id) return;
          if(window.MSBCreatePostModal && typeof window.MSBCreatePostModal.open === 'function'){
            window.MSBCreatePostModal.open('dashboard.php?modal=1&edit=' + encodeURIComponent(String(id)));
            return;
          }
          window.location.href = 'dashboard.php?modal=1&edit=' + encodeURIComponent(String(id));
        });

        // delete
        $(document).on('click', '#postList .pl-del', function(e){
          e.preventDefault();
          var id = Number($(this).data('id') || 0);
          if(!id) return;
          if(!confirm('Delete this post?')) return;

          $.post(API_URL, { ajax:'delete_post', post_id:id }, function(res){
            refreshList(false);
            if(Number(selectedId) === Number(id)){
              selectedId = 0;
              $('#curPostId').val('0');
            }
          }, 'json');
        });

        // Find Me (scroll to first of my posts)
        $(document).on('click', '#btnFindMe', function(e){
          e.preventDefault();
          $('#filterSel').val('all');
          showAllForMe = true;
          applySearchFilter();

          setTimeout(function(){
            var $row = $('#postList .pl-item').filter(function(){
              var id = Number($(this).data('id')||0);
              var it = (allItems||[]).find(function(x){ return Number(x.id||0)===id; });
              return it && !STAFF_READONLY && Number(it.user_id||0)===Number(ME_ID||0);
            }).first();
            if($row.length){
              $row.get(0).scrollIntoView({behavior:'smooth', block:'nearest'});
            }
          }, 30);
        });

        // Find Author (scroll to current author's posts)
        $(document).on('click', '#btnFindAuthor', function(e){
          e.preventDefault();
          if(!selectedId) return;

          var cur = (allItems||[]).find(function(x){ return Number(x.id||0)===Number(selectedId); }) || null;
          var authorId = Number((cur && cur.user_id) || currentOwnerId || 0);
          if(!authorId) return;

          $('#filterSel').val('all');
          showAllForAuthor = true;
          applySearchFilter();

          setTimeout(function(){
            var $row = $('#postList .pl-item').filter(function(){
              var id = Number($(this).data('id')||0);
              var it = (allItems||[]).find(function(x){ return Number(x.id||0)===id; });
              return it && Number(it.user_id||0)===Number(authorId);
            }).first();
            if($row.length){
              $row.get(0).scrollIntoView({behavior:'smooth', block:'nearest'});
            }
          }, 30);
        });

        // Filter / search bindings for right sidebar
        $(document).on('change', '#filterSel', function(){
          var mode = String($(this).val() || 'all');
          showAllForMe = (mode === 'mine');
          if(mode !== 'mine') showAllForMe = false;
          if(mode === 'all') showAllForAuthor = false;
          applySearchFilter();
        });

        $(document).on('input keyup search', '#searchBox', function(){
          $('#feedTopSearchInput').val(String($(this).val() || ''));
          applySearchFilter();
        });

        $(document).on('submit', '#feedTopSearchForm', function(e){
          e.preventDefault();
          var q = String($('#feedTopSearchInput').val() || '').trim();
          $('#searchBox').val(q);
          $('#feedTopSearchInput').val(q);
          applySearchFilter();
          try{
            var nextUrl = new URL(window.location.href);
            if(q !== '') nextUrl.searchParams.set('q', q);
            else nextUrl.searchParams.delete('q');
            history.replaceState({}, document.title, nextUrl.pathname + nextUrl.search + nextUrl.hash);
          }catch(err){}
        });

        $(document).on('input search', '#feedTopSearchInput', function(){
          $('#searchBox').val(String($(this).val() || ''));
          applySearchFilter();
        });

        $(document).on('click', '#btnPostCardMore', function(e){
          e.preventDefault();
          e.stopPropagation();
          $('#postCardToolsFlyout').toggleClass('is-open');
        });
        $(document).on('click', function(e){
          if(!$(e.target).closest('.ig-insta-header-actions').length){
            $('#postCardToolsFlyout').removeClass('is-open');
          }
        });
        $(document).on('click', '#btnInstaMediaNext', function(e){
          e.preventDefault();
          instaGoMedia(1);
        });
        $(document).on('click', '#btnInstaMediaPrev', function(e){
          e.preventDefault();
          instaGoMedia(-1);
        });

        // Refresh button
        $(document).on('click', '#refreshBtn', function(e){
          e.preventDefault();
          $(this).addClass('spin');
          refreshList(true);
          var $btn = $(this);
          setTimeout(function(){ $btn.removeClass('spin'); }, 500);
        });

        function applyFeedViewerReactionCounts(counts){
          var c = counts || {};
          $('#likeCount, #likeCountV').text(String(c.like_count||0));
          $('#likeCountF').text(String(c.like_count||0));
          syncInstaPostChrome({ love_count: c.love_count || 0 });
          setReactionButtons(c.my_reaction||'');
        }

        function submitFeedViewerReaction(reaction){
          var pid = Number($('#curPostId').val() || 0);
          if(!pid || !reaction) return;
          $.post(API_URL, { ajax:'react', post_id:pid, reaction:reaction }, function(res){
            if(!res || !res.ok) return;
            applyFeedViewerReactionCounts(res.counts || {});
          }, 'json');
        }

        // Like/Love buttons
        $('#btnLike, #btnLikeV').on('click', function(e){
          e.preventDefault();
          if(String(window.__myReaction||'') === 'like') return;
          submitFeedViewerReaction('like');
        });

        $('#btnLove, #btnLoveV').on('click', function(e){
          e.preventDefault();
          if(String(window.__myReaction||'') === 'love') return;
          submitFeedViewerReaction('love');
        });
        if(window.MSBReactions){
          window.MSBReactions.bindLikePicker('#btnLove, #btnLoveV, #btnFooterLove', function(_btn, reaction){
            if(!reaction || String(window.__myReaction||'') === String(reaction)) return;
            submitFeedViewerReaction(reaction);
          });
        }
// Share / Save (DB-backed, one-time, no alerts)
        function copyShareLink(pid){
          var link = (window.location.origin || '') + (window.location.pathname || '') + '?post=' + encodeURIComponent(String(pid));
          try{
            if(navigator.clipboard && navigator.clipboard.writeText){
              navigator.clipboard.writeText(link).catch(function(){});
            }
          }catch(_e){}
        }

        $('#btnShare, #btnShareV').on('click', function(e){
          e.preventDefault();
          var pid = Number($('#curPostId').val() || 0);
          if(!pid) return;
          // always copy link silently
          copyShareLink(pid);
          // one-time lock
          if(String($(this).attr('data-locked')||'0') === '1') return;
          $.post(API_URL, { ajax:'share', post_id:pid }, function(res){
            applyTrackCounts(res);
          }, 'json');
        });

        $('#btnSave, #btnSaveV').on('click', function(e){
          e.preventDefault();
          var pid = Number($('#curPostId').val() || 0);
          if(!pid) return;
          // one-time lock
          if(String($(this).attr('data-locked')||'0') === '1') return;
          $.post(API_URL, { ajax:'save', post_id:pid }, function(res){
            applyTrackCounts(res);
          }, 'json');
        });

        // keep leftbar and modal in sync after posting from leftbar.php
        window.TTComments = window.TTComments || {};
        window.TTComments.refreshCurrent = function(){
          var pid = Number($('#curPostId').val() || 0);
          if(!pid) return;
          $.getJSON(API_URL, { ajax:'view', id:pid, post_id:pid, count_view:0 }, function(res){
            if(!res || !res.ok) return;
            var comments = res.comments || [];
            $('#commentCount, #commentCountV, #commentCountF, #commentCountTextF').text(String(comments.length || 0));
            syncImageOverlayActions();
            refreshViewerFooter();
            try{ if($('#cOverlay').is(':visible')) renderCommentsModal(comments); }catch(e){}
            try{ if(window.TTComments && typeof window.TTComments.setPost === 'function') window.TTComments.setPost(pid, comments, false); }catch(e){}
            refreshList(true);
          });
        };


// ✅ UPDATED: Eye icon opens modal (image/video/pdf), full width fit
        $('#btnOpenMedia').on('click', function(e){
          e.preventDefault();

          var src = '';
          try{ src = String($(this).data('src') || ''); }catch(_e){ src=''; }

          var kind = '';
          if(!src){
            try{
              var el = document.querySelector('#pvMedia .ig-media-main');
              if(el){
                if(el.tagName === 'IMG' || el.tagName === 'VIDEO' || el.tagName === 'IFRAME'){
                  src = el.getAttribute('src') || '';
                  if(el.tagName === 'VIDEO') kind = 'video';
                  if(el.tagName === 'IMG') kind = 'image';
                  if(el.tagName === 'IFRAME') kind = 'pdf';
                }
              }
            }catch(_e2){}
          }

          if(!src) return;
          openMediaModal(src, kind);
        });

        $('#btnFullscreenMedia').on('click', function(e){
          e.preventDefault();
          try{
            var el = document.querySelector('#pvMedia .ig-media-main');
            if(!el) return;
            if(el.requestFullscreen) el.requestFullscreen();
            else if(el.webkitRequestFullscreen) el.webkitRequestFullscreen();
            else if(el.msRequestFullscreen) el.msRequestFullscreen();
          }catch(_e){}
        });

        $(document).on('click', '.ig-image-overlay-btn', function(e){
          e.preventDefault();
          e.stopPropagation();
          var act = String($(this).data('act') || '');
          if(act === 'comment') $('#commentCountLink').trigger('click');
          else if(act === 'like') $('#btnLike').trigger('click');
          else if(act === 'love') $('#btnLove').trigger('click');
          else if(act === 'save') $('#btnSave').trigger('click');
          else if(act === 'share') $('#btnShare').trigger('click');
        });

        $(function(){
          bindBookRail();
          if(FEED_SEARCH_Q){
            $('#searchBox').val(FEED_SEARCH_Q);
            $('#feedTopSearchInput').val(FEED_SEARCH_Q);
          }
          refreshList(false);
          updateBackButton();
        });

        // ✅ Reel Leftbar buttons (Mobile/Tablet)
        $(function(){
          $('#rlbNext').on('click', function(e){ e.preventDefault(); try{ if(window.reelGoNext) window.reelGoNext(); }catch(_e){} });
          $('#rlbPrev').on('click', function(e){ e.preventDefault(); try{ if(window.reelGoPrev) window.reelGoPrev(); }catch(_e){} });
          $('#rlbComments').on('click', function(e){ e.preventDefault(); try{ $('#btnOpenCommentsDrawer').click(); }catch(_e){} });
          $('#rlbExit').on('click', function(e){ e.preventDefault(); try{ if(window.reelExitReel) window.reelExitReel(); }catch(_e){} });
        });

        window.addEventListener('beforeunload', function(){
          rememberCurrentVideoTime();
          pauseLastVideo();
        });

      })();
    </script>
  
<script>
document.addEventListener('DOMContentLoaded', function(){
  document.querySelectorAll('[data-rule7="1"]').forEach(function(card){
    var actions = card.querySelector('.pv-actions, .post-actions, .tt-post-actions');
    var longRow = card.querySelector('.pv-long-row');
    if(actions && longRow){
      if(longRow.nextElementSibling !== actions){
        longRow.parentNode.insertBefore(actions, longRow.nextSibling);
      }
    }
  });
});
</script>




<script>
/* =========================================
   BOOK ACTION CLICK COLORS + MICRO-ANIMS
   (NO PHP CHANGE)
========================================= */

function igFlashAnim(el, cls){
  el.classList.remove(cls);
  // force reflow to restart animation
  void el.offsetWidth;
  el.classList.add(cls);
  window.setTimeout(() => el.classList.remove(cls), 450);
}

document.addEventListener("click", function(e){
  const el = e.target.closest(
    "#btnLove, #btnLike, #btnSave, #btnShare, #btnLoveV, #btnLikeV, #btnSaveV, #btnShareV"
  );
  if(!el) return;

  const id = el.id || "";

  if(id === "btnLove" || id === "btnLoveV"){
    el.classList.toggle("is-love");
    igFlashAnim(el, "anim-pop");
    return;
  }

  if(id === "btnLike" || id === "btnLikeV"){
    el.classList.toggle("is-like");
    igFlashAnim(el, "anim-ripple");
    return;
  }

  if(id === "btnSave" || id === "btnSaveV"){
    // ✅ state is controlled by DB (do not toggle off)
    igFlashAnim(el, "anim-slide");
    return;
  }

  if(id === "btnShare" || id === "btnShareV"){
    // ✅ state is controlled by DB (do not toggle off)
    igFlashAnim(el, "anim-nudge");
    return;
  }
});


/* ✅ Fix: RIGHT SIDEBAR 3-dots dropdown (anchored + not clipped + ACTIONS WORK)
   ✅ Key fix vs previous version:
   We DO NOT move the real menu out of its row/form (that breaks Edit/Delete submits).
   Instead we:
   1) Keep the ORIGINAL menu in place (so forms/handlers keep working)
   2) Render a PORTAL CLONE into <body> for perfect positioning (no clipping)
   3) When user clicks an item in the CLONE, we forward the click to the matching
      ORIGINAL item, so Edit/Delete works exactly as before.
*/
(function(){
  var OPEN_CLASS   = 'pl-dd-open';
  var PORTAL_CLASS = 'pl-dd-portal';

  var activeWrap = null;
  var activeClone = null;
  var focusIdx = -1;
  function qsa(sel, root){ return Array.prototype.slice.call((root||document).querySelectorAll(sel)); }
  function closest(el, sel){ return el && el.closest ? el.closest(sel) : null; }

  function getMenu(wrap){
    return wrap ? (wrap.querySelector('.pl-dropdown-menu') || wrap.querySelector('.dropdown-menu')) : null;
  }

  function actionableEls(root){
    if(!root) return [];
    // include anchors + buttons inside dropdown
    return qsa('a,button', root).filter(function(el){
      // skip disabled
      if(el.disabled) return false;
      // skip our close X
      if(el.classList && el.classList.contains('pl-dd-x')) return false;
      return true;
    });
  }


function focusableEls(menu){
  if(!menu) return [];
  // prefer dropdown-item; also include anchors/buttons
  var els = qsa('.dropdown-item, a, button', menu).filter(function(el){
    if(el.disabled) return false;
    if(el.classList && el.classList.contains('pl-dd-x')) return false;
    // skip hidden
    if(el.offsetParent === null) return false;
    return true;
  });

  // ensure tabindex for focus
  els.forEach(function(el){
    if(!el.hasAttribute('tabindex')) el.setAttribute('tabindex','-1');
  });
  return els;
}

function setFocus(menu, idx){
  var items = focusableEls(menu);
  if(!items.length) { focusIdx = -1; return; }
  if(typeof idx !== 'number') idx = 0;
  if(idx < 0) idx = 0;
  if(idx > items.length - 1) idx = items.length - 1;

  focusIdx = idx;
  items.forEach(function(it){ it.classList.remove('pl-dd-focus'); });
  var el = items[focusIdx];
  if(el){
    el.classList.add('pl-dd-focus');
    try { el.focus({preventScroll:true}); } catch(_e){ try { el.focus(); } catch(_e2){} }
  }
}

  function ensureCloseX(menu){
    if(!menu) return;
    if(menu.querySelector('.pl-dd-x')) return;

    var x = document.createElement('button');
    x.type = 'button';
    x.className = 'pl-dd-x';
    x.setAttribute('aria-label','Close');
    x.innerHTML = '&times;';
    x.style.cssText = [
      'position:absolute',
      'top:6px',
      'right:8px',
      'width:28px',
      'height:28px',
      'line-height:24px',
      'border:0',
      'background:transparent',
      'font-size:22px',
      'cursor:pointer',
      'color:#666',
      'z-index:100000'
    ].join(';');

    x.addEventListener('click', function(ev){
      ev.preventDefault();
      ev.stopPropagation();
      closeAll(null);
    });

    menu.insertBefore(x, menu.firstChild);
  }

  function positionClone(btn, clone){
    if(!btn || !clone) return;

    // show invisibly to measure
    var prevDisplay = clone.style.display;
    var prevVis = clone.style.visibility;
    clone.style.display = 'block';
    clone.style.visibility = 'hidden';

    var rect = btn.getBoundingClientRect();
    var mw = clone.offsetWidth || 220;
    var mh = clone.offsetHeight || 80;

    var gap = 8;
    var openedUp = false;
    var top = rect.bottom + gap;
    var left = rect.right - mw;

    var vw = Math.max(document.documentElement.clientWidth, window.innerWidth || 0);
    var vh = Math.max(document.documentElement.clientHeight, window.innerHeight || 0);

    if(left < 10) left = 10;
    if(left + mw > vw - 10) left = Math.max(10, vw - mw - 10);

    if(top + mh > vh - 10){
      top = rect.top - gap - mh;
      openedUp = true;
      if(top < 10) top = 10;
    }

    clone.style.top = top + 'px';
    clone.style.left = left + 'px';
    // Smooth animation origin
    clone.style.transformOrigin = openedUp ? 'bottom right' : 'top right';

    clone.style.visibility = prevVis || '';
    clone.style.display = prevDisplay || '';
  }

  function removeClone(){
    if(activeClone && activeClone.parentNode){
      try { activeClone.parentNode.removeChild(activeClone); } catch(_e){}
    }
    activeClone = null;
  }

  function closeAll(exceptWrap){
    qsa('.rightSidebarCard .pl-more-wrap.'+OPEN_CLASS).forEach(function(w){
      if(exceptWrap && w === exceptWrap) return;
      w.classList.remove(OPEN_CLASS);
      var btn = w.querySelector('.pl-more');
      if(btn) btn.setAttribute('aria-expanded','false');

      // restore original menu visibility
      var orig = getMenu(w);
      if(orig){
        orig.classList.remove('show');
        orig.style.display = '';
      }
    });

    removeClone();

    activeWrap = exceptWrap || null;
  }

  function isOpen(wrap){ return wrap && wrap.classList.contains(OPEN_CLASS); }

  function open(wrap){
    if(!wrap) return;

    var btn = wrap.querySelector('.pl-more');
    var orig = getMenu(wrap);
    if(!btn || !orig) return;

    // close others
    closeAll(wrap);

    wrap.classList.add(OPEN_CLASS);
    btn.setAttribute('aria-expanded','true');

    // keep original hidden (stays in DOM so forms/handlers still work)
    orig.classList.add('show');
    orig.style.display = 'none';

    // make clone portal
    var clone = orig.cloneNode(true);
    clone.classList.add(PORTAL_CLASS);
    clone.style.position = 'fixed';
    clone.style.transform = 'none';
    clone.style.willChange = 'auto';
    clone.style.zIndex = '99999';
    clone.style.display = 'block';

    ensureCloseX(clone);

    // forward clicks from clone -> original
    var origItems  = actionableEls(orig);
    var cloneItems = actionableEls(clone);

    cloneItems.forEach(function(ci, idx){
      ci.addEventListener('click', function(ev){
        // allow normal navigation for real external links (but most of yours are inside forms/modals)
        ev.preventDefault();
        ev.stopPropagation();

        if(ci.classList && ci.classList.contains('pl-edit')){
          var href = String(ci.getAttribute('href') || '').trim();
          if(href){
            if(window.MSBCreatePostModal && typeof window.MSBCreatePostModal.open === 'function'){
              window.MSBCreatePostModal.open(href);
            }else{
              window.location.href = href;
            }
          }
          setTimeout(function(){ closeAll(null); }, 0);
          return;
        }

        var oi = origItems[idx];
        if(oi){
          // trigger original action
          try { oi.click(); } catch(_e){}
        }

        // close after click
        setTimeout(function(){ closeAll(null); }, 0);
      }, true);
    });

    document.body.appendChild(clone);
    positionClone(btn, clone);

    // animate in
    clone.classList.add('pl-dd-portal');
    clone.classList.add('pl-dd-anim');
    try { requestAnimationFrame(function(){ clone.classList.add('is-open'); }); } catch(_e){ clone.classList.add('is-open'); }

    // keyboard focus starts at first actionable item
    setTimeout(function(){ setFocus(clone, 0); }, 0);

    activeWrap = wrap;
    activeClone = clone;
  }

  function close(wrap){
    if(!wrap) return;
    wrap.classList.remove(OPEN_CLASS);

    var btn = wrap.querySelector('.pl-more');
    if(btn) btn.setAttribute('aria-expanded','false');

    var orig = getMenu(wrap);
    if(orig){
      orig.classList.remove('show');
      orig.style.display = '';
    }

    removeClone();
    if(activeWrap === wrap) activeWrap = null;
  }

  // Close when mouse moves outside union area (btn + clone)
  document.addEventListener('mousemove', function(e){
    if(!activeClone || !activeWrap) return;

    var btn = activeWrap.querySelector('.pl-more');
    if(!btn) { closeAll(null); return; }

    var r1 = btn.getBoundingClientRect();
    var r2 = activeClone.getBoundingClientRect();
    var pad = 18;

    var left   = Math.min(r1.left,   r2.left)   - pad;
    var right  = Math.max(r1.right,  r2.right)  + pad;
    var top    = Math.min(r1.top,    r2.top)    - pad;
    var bottom = Math.max(r1.bottom, r2.bottom) + pad;

    if(e.clientX < left || e.clientX > right || e.clientY < top || e.clientY > bottom){
      closeAll(null);
    }
  }, {passive:true});

  // Keyboard navigation (ESC / ↑ ↓ / Enter)
document.addEventListener('keydown', function(e){
  if(e.key === 'Escape'){
    closeAll(null);
    return;
  }
  if(!activeClone) return;

  var items = focusableEls(activeClone);
  if(!items.length) return;

  if(e.key === 'ArrowDown'){
    e.preventDefault();
    if(focusIdx < 0) focusIdx = 0;
    setFocus(activeClone, focusIdx + 1);
    return;
  }
  if(e.key === 'ArrowUp'){
    e.preventDefault();
    if(focusIdx < 0) focusIdx = 0;
    setFocus(activeClone, focusIdx - 1);
    return;
  }
  if(e.key === 'Enter'){
    // activate focused item
    if(focusIdx >= 0 && items[focusIdx]){
      e.preventDefault();
      try { items[focusIdx].click(); } catch(_e){}
    }
  }
});

  // Intercept 3-dots click before Bootstrap (prevents Popper jump)
  document.addEventListener('click', function(e){
    var btn = closest(e.target, '.rightSidebarCard .pl-more-wrap > .pl-more');
    if(btn){
      e.preventDefault();
      e.stopPropagation();
      if(e.stopImmediatePropagation) e.stopImmediatePropagation();

      var wrap = closest(btn, '.pl-more-wrap');
      if(isOpen(wrap)) close(wrap);
      else open(wrap);
      return;
    }

    // clicks inside the clone should not close immediately
    if(closest(e.target, '.'+PORTAL_CLASS)) return;
    if(closest(e.target, '.post-card-menu-btn, .post-card-menu-wrap, .pcm-menu-portal')) return;

    closeAll(null);
  }, true);

  

// Mobile long-press opens menu (optional polish)
var pressTimer = null;
document.addEventListener('touchstart', function(e){
  var btn = closest(e.target, '.rightSidebarCard .pl-more-wrap > .pl-more');
  if(!btn) return;
  pressTimer = setTimeout(function(){
    var wrap = closest(btn, '.pl-more-wrap');
    if(wrap && !isOpen(wrap)) open(wrap);
  }, 450);
}, {passive:true});

document.addEventListener('touchend', function(){
  if(pressTimer) clearTimeout(pressTimer);
  pressTimer = null;
}, {passive:true});

// Reposition on resize/scroll
  function reposition(){
    if(!activeWrap || !activeClone) return;
    var btn = activeWrap.querySelector('.pl-more');
    if(btn) positionClone(btn, activeClone);
  }
  window.addEventListener('resize', reposition, {passive:true});
  window.addEventListener('scroll', reposition, {passive:true});
  document.addEventListener('scroll', function(e){
    var list = closest(e.target, '.rightSidebarList, .pl-scroll');
    if(list) reposition();
  }, true);

})();
</script>


<script>
/* ===== Keyboard Next/Prev for Right Sidebar Selected Row -> updates Post Card ===== */
(function(){
  function isTypingTarget(el){
    if(!el) return false;
    const tag = (el.tagName||'').toLowerCase();
    if(tag === 'input' || tag === 'textarea' || tag === 'select') return true;
    if(el.isContentEditable) return true;
    return false;
  }

  function listItems(){
    return Array.prototype.slice.call(document.querySelectorAll('#postList .pl-item'));
  }

  function currentIndex(items){
    if(!items.length) return -1;
    const active = document.querySelector('#postList .pl-item.active');
    if(active){
      const idx = items.indexOf(active);
      if(idx !== -1) return idx;
    }
    // fallback: focused item in list
    const focused = document.activeElement && document.activeElement.closest ? document.activeElement.closest('#postList .pl-item') : null;
    if(focused){
      const idx = items.indexOf(focused);
      if(idx !== -1) return idx;
    }
    return 0;
  }

  function selectIndex(idx, items){
    if(!items.length) return;
    idx = Math.max(0, Math.min(items.length - 1, idx));
    const el = items[idx];

    // keep UI highlight consistent
    items.forEach(function(x){ x.classList.remove('active'); });
    el.classList.add('active');

    // ensure it's visible in sidebar
    try{ el.scrollIntoView({block:'nearest'}); }catch(e){}

    // trigger existing click handler that loads the post card
    // (this preserves your current logic for read/unread, counters, etc.)
    el.click();
  }

  document.addEventListener('keydown', function(e){
    // Don't interfere with dropdown keyboard nav
    if(document.querySelector('.dropdown-floating')) return;

    // Don't hijack typing in search/filter boxes
    if(isTypingTarget(e.target)) return;

    const items = listItems();
    if(!items.length) return;

    const idx = currentIndex(items);

    // NEXT / PREV (updates the post card)
    if(e.key === 'ArrowRight' || e.key === 'PageDown'){
      e.preventDefault();
      selectIndex(idx + 1, items);
      return;
    }
    if(e.key === 'ArrowLeft' || e.key === 'PageUp'){
      e.preventDefault();
      selectIndex(idx - 1, items);
      return;
    }

    // OPTIONAL: If user is focused inside the right sidebar list, allow Up/Down too
    const inSidebar = !!(document.activeElement && document.activeElement.closest && document.activeElement.closest('#postList'));
    if(inSidebar && (e.key === 'ArrowDown' || e.key === 'ArrowUp' || e.key === 'Home' || e.key === 'End')){
      e.preventDefault();
      if(e.key === 'ArrowDown') selectIndex(idx + 1, items);
      else if(e.key === 'ArrowUp') selectIndex(idx - 1, items);
      else if(e.key === 'Home') selectIndex(0, items);
      else if(e.key === 'End') selectIndex(items.length - 1, items);
    }
  }, true);
})();
</script>


<script>
/* ===============================
   POST CARD KEYBOARD NAVIGATION
   - ArrowRight / ArrowDown : next post card
   - ArrowLeft  / ArrowUp   : previous post card
   (Does nothing while typing or when dropdown is open)
   =============================== */
(function(){
  function isTyping(){
    const a = document.activeElement;
    if(!a) return false;
    const tag = (a.tagName || '').toUpperCase();
    return tag === 'INPUT' || tag === 'TEXTAREA' || a.isContentEditable;
  }

  function getPostCards(){
    // Support common card class names used in this project
    const sel = '.post-card, .feed-card, .post-item, .sh-post-card, .card-post, .postCard';
    return Array.from(document.querySelectorAll(sel)).filter(el => el.offsetParent !== null);
  }

  function getActiveIndex(cards){
    const active = document.activeElement;
    let idx = cards.indexOf(active);

    if(idx === -1){
      const mid = window.innerHeight / 2;
      for(let i=0;i<cards.length;i++){
        const r = cards[i].getBoundingClientRect();
        if(r.top < mid && r.bottom > mid) { idx = i; break; }
      }
    }
    if(idx === -1) idx = 0;
    return idx;
  }

  function focusCard(i){
    const cards = getPostCards();
    if(!cards.length) return;

    i = Math.max(0, Math.min(cards.length-1, i));
    const card = cards[i];

    if(!card.hasAttribute('tabindex')) card.setAttribute('tabindex','0');
    card.focus({preventScroll:true});
    card.scrollIntoView({behavior:'smooth', block:'center'});
  }

  document.addEventListener('keydown', function(e){
    if(document.querySelector('.dropdown-floating')) return;
    if(isTyping()) return;

    const cards = getPostCards();
    if(!cards.length) return;

    const idx = getActiveIndex(cards);

    if(e.key === 'ArrowRight' || e.key === 'ArrowDown'){
      e.preventDefault();
      focusCard(idx + 1);
    } else if(e.key === 'ArrowLeft' || e.key === 'ArrowUp'){
      e.preventDefault();
      focusCard(idx - 1);
    }
  });
})();
</script>


<script>
/* =====================================================
   FEED MOBILE/TABLET "MESSENGER" MODE (Rows list ↔ Post)
   - Mobile/Tablet: start list, tap row -> post, back -> list
   - Desktop: unchanged
===================================================== */
function feedBackToList(){
  document.body.classList.remove('f-mode-post');
  document.body.classList.add('f-mode-list');
  try{
    var list = document.querySelector('#postList') || document.querySelector('.rightSidebarCard') || document.querySelector('#rightSidebar');
    if(list && list.scrollIntoView) list.scrollIntoView({behavior:'smooth', block:'start'});
  }catch(e){}
}

function feedGoToPost(){
  document.body.classList.remove('f-mode-list');
  document.body.classList.add('f-mode-post');
  try{
    var v = document.querySelector('.ig-post-card') || document.querySelector('.post-card') || document.querySelector('.feed-card');
    if(v && v.scrollIntoView) v.scrollIntoView({behavior:'smooth', block:'start'});
  }catch(e){}
}
(function(){
  function isMobile(){ return window.matchMedia && window.matchMedia('(max-width: 991.98px)').matches; }

  function markCols(){
    var list = document.querySelector('#postList') || document.querySelector('#rightSidebar') || document.querySelector('.rightSidebarCard');
    if(list){
      var col = list.closest('[class*="col-"]') || list.closest('.col') || null;
      if(col) col.classList.add('feedSidebarCol');
    }
    var viewer = document.querySelector('.ig-post-card') || document.querySelector('.post-card') || document.querySelector('.feed-card');
    if(viewer){
      var vcol = viewer.closest('[class*="col-"]') || viewer.closest('.col') || null;
      if(vcol) vcol.classList.add('feedViewerCol');
    }
  }

  function init(){
    markCols();
    if(isMobile()){
      // ✅ Mobile/Tablet default: show viewer (not list)
      document.body.classList.add('f-mode-post');
      document.body.classList.remove('f-mode-list');
    }else{
      document.body.classList.remove('f-mode-list','f-mode-post');
    }
  }

  document.addEventListener('DOMContentLoaded', function(){
    var topSearch = document.getElementById('feedTopbarSearch');
    var sideSearch = document.getElementById('searchBox');
    var topSearchForm = document.getElementById('feedTopbarSearchForm');
    if(topSearch && sideSearch){
      topSearch.value = sideSearch.value || '';
      topSearch.addEventListener('input', function(){
        sideSearch.value = topSearch.value;
        if (window.jQuery) {
          window.jQuery(sideSearch).trigger('input');
        }
      });
      if(topSearchForm){
        topSearchForm.addEventListener('submit', function(event){
          event.preventDefault();
          sideSearch.value = topSearch.value;
          if (window.jQuery) {
            window.jQuery(sideSearch).trigger('input');
          }
        });
      }
    }
    init();

    // If a post is already shown (e.g., first load), auto switch to post mode on mobile
    if(isMobile()){
      var viewer = document.querySelector('.ig-post-card') || document.querySelector('.post-card') || document.querySelector('.feed-card');
      if(viewer){
        // If it contains media or text blocks, treat as selected
        var hasContent = viewer.querySelector('video, img, iframe, .post-body, .card-body, .book-layout, .reel-layout');
        if(hasContent){
          // keep list by default; don't force post unless list is empty
        }
      }
    }
  });

  window.addEventListener('resize', init);

  // Any click inside the list triggers post mode (covers links/divs/buttons)
  document.addEventListener('click', function(e){
    if(!isMobile()) return;
    var inList = e.target.closest && (e.target.closest('#postList') || e.target.closest('#rightSidebar') || e.target.closest('.rightSidebarCard'));
    if(inList){
      // but only when clicking a row (not search input)
      var row = e.target.closest('.pl-item') || e.target.closest('li') || e.target.closest('[data-post-id]') || e.target.closest('a');
      var isSearch = e.target.closest('input, textarea, select, button');
      if(row && !isSearch){
        feedGoToPost();
      }
    }
  }, true);

  // Also switch to post mode when the main viewer card is clicked (e.g., attachments)
  document.addEventListener('click', function(e){
    if(!isMobile()) return;
    var viewer = e.target.closest && (e.target.closest('.ig-post-card') || e.target.closest('.post-card') || e.target.closest('.feed-card'));
    if(viewer){
      document.body.classList.add('f-mode-post');
      document.body.classList.remove('f-mode-list');
    }
  }, true);

})();
</script>


<script>
/* [FEED_TIKTOK_SCROLL_JS] */
/* =====================================================
   TikTok-style reel scroll (swipe up/down) for mobile/tablet
   ✅ Uses existing loadPost(postId, fromList)
   ✅ Uses #postList order (.pl-item[data-id])
===================================================== */
(function(){
  function isMT(){
    try{ return window.matchMedia && window.matchMedia('(max-width: 991.98px)').matches; }
    catch(e){ return (window.innerWidth||0) <= 992; }
  }
  function inReelMode(){
    var vb = document.querySelector('.ig-viewer-body');
    return !!(vb && vb.classList.contains('mode-reel'));
  }
  function idsInOrder(){
    var ids = [];
    try{
      if(Array.isArray(window.feedViewerOrderedIds) && window.feedViewerOrderedIds.length){
        return window.feedViewerOrderedIds
          .map(function(id){ return Number(id || 0); })
          .filter(function(id){ return id > 0; });
      }
      if(!window.jQuery) return ids;
      jQuery('#postList .pl-item').each(function(){
        var id = Number(jQuery(this).data('id') || 0);
        if(id) ids.push(id);
      });
    }catch(e){}
    return ids;
  }
  function getSelectedId(){
    try{
      if(window.jQuery){
        var v = Number(jQuery('#curPostId').val() || 0);
        if(v) return v;
        var $a = jQuery('#postList .pl-item.active');
        if($a.length) return Number($a.data('id')||0);
        var $f = jQuery('#postList .pl-item').first();
        if($f.length) return Number($f.data('id')||0);
      }
    }catch(e){}
    return 0;
  }
  function animate(dir){
    try{
      var vb = document.querySelector('.ig-viewer-body');
      if(!vb) return;
      vb.classList.remove('reel-anim-up','reel-anim-down');
      vb.classList.add(dir === 'down' ? 'reel-anim-down' : 'reel-anim-up');
      setTimeout(function(){ vb.classList.remove('reel-anim-up','reel-anim-down'); }, 220);
    }catch(e){}
  }
  function goNext(){
    if(typeof window.loadPost !== 'function') return;
    var ids = idsInOrder();
    var cur = getSelectedId();
    if(!ids.length || !cur) return;
    var idx = ids.indexOf(cur); if(idx === -1) idx = 0;
    var next = ids[Math.min(idx+1, ids.length-1)];
    if(next && next !== cur){ animate('up'); window.loadPost(Number(next), true); }
  }
  function goPrev(){
    if(typeof window.loadPost !== 'function') return;
    var ids = idsInOrder();
    var cur = getSelectedId();
    if(!ids.length || !cur) return;
    var idx = ids.indexOf(cur); if(idx === -1) idx = 0;
    var prev = ids[Math.max(idx-1, 0)];
    if(prev && prev !== cur){ animate('down'); window.loadPost(Number(prev), true); }
  }

  // Expose controls for the Reel Leftbar buttons
  window.reelGoNext = goNext;
  window.reelGoPrev = goPrev;
  window.reelExitReel = function(){
    try{ if(typeof window.setReelMode === 'function') window.setReelMode(false); }catch(e){}
    try{ document.body.classList.remove('mt-reel-only'); }catch(e){}
  };

  var startY=0, startX=0, touching=false, lastNavAt=0;

  function onTouchStart(e){
    if(!isMT() || !inReelMode()) return;
    var t = e.touches && e.touches[0]; if(!t) return;
    touching=true; startY=t.clientY; startX=t.clientX;
  }
  function onTouchEnd(e){
    if(!touching) return;
    touching=false;
    if(!isMT() || !inReelMode()) return;

    var now=Date.now();
    if(now-lastNavAt < 380) return;

    var t = (e.changedTouches && e.changedTouches[0]) || null;
    if(!t) return;

    var dy = t.clientY - startY;
    var dx = t.clientX - startX;

    if(Math.abs(dx) > Math.abs(dy)) return;
    if(Math.abs(dy) < 60) return;

    lastNavAt = now;
    if(dy < 0) goNext(); else goPrev();
  }

  var wheelAcc=0, wheelT=null;
  function onWheel(e){
    if(!isMT() || !inReelMode()) return;
    var target = e.target;
    if(target && target.closest && target.closest('#postList')) return;
    if(target && target.closest && target.closest('#tt-stories-wrap, .tt-stories-scroll-panel, .tt-stories-scroll-inner, .tt-stories-text-slide, .tt-stories-caption')) return;

    wheelAcc += (e.deltaY || 0);
    clearTimeout(wheelT);
    wheelT = setTimeout(function(){
      if(Math.abs(wheelAcc) > 120){
        var dir = wheelAcc > 0 ? 'next' : 'prev';
        wheelAcc = 0;
        if(dir === 'next') goNext(); else goPrev();
      }else{
        wheelAcc = 0;
      }
    }, 80);
  }

  document.addEventListener('touchstart', onTouchStart, {passive:true, capture:true});
  document.addEventListener('touchend', onTouchEnd, {passive:true, capture:true});
  document.addEventListener('wheel', onWheel, {passive:true, capture:true});
})();

/* [FEED_DESKTOP_FACEBOOK_SCROLL_JS] */
/* =====================================================
   Desktop: center feed column scrolls independently
   - Wheel/scroll on center post card only (not sidebar)
   - Scroll inside long posts first, then next/prev post
===================================================== */
(function(){
  var WHEEL_IDLE_MS = 240;
  var WHEEL_COMMIT = 30;
  var NAV_COOLDOWN_MS = 380;

  function isDesktop(){
    try{ return window.matchMedia && window.matchMedia('(min-width: 992px)').matches; }
    catch(e){ return (window.innerWidth || 0) >= 992; }
  }

  function getFeedCol(){
    return document.getElementById('feedPostScrollCol')
      || document.querySelector('.row.row-sm > .col-lg-8.feedViewerCol')
      || document.querySelector('.row.row-sm > .col-lg-8');
  }

  function pointerInFeed(e, feedEl){
    if(!feedEl || !e) return false;
    if(e.target && feedEl.contains(e.target)) return true;
    if(typeof e.clientX !== 'number' || typeof e.clientY !== 'number') return false;
    var r = feedEl.getBoundingClientRect();
    return e.clientX >= r.left && e.clientX <= r.right && e.clientY >= r.top && e.clientY <= r.bottom;
  }

  function feedNavIds(){
    var ids = [];
    try{
      Array.prototype.forEach.call(document.querySelectorAll('#postList .pl-item[data-id]'), function(el){
        var id = Number(el.getAttribute('data-id') || 0);
        if(id) ids.push(id);
      });
    }catch(e){}
    return ids;
  }

  function getSelectedId(){
    try{
      if(window.jQuery){
        var v = Number(jQuery('#curPostId').val() || 0);
        if(v) return v;
      }
    }catch(e){}
    return 0;
  }

  function feedHasOverflow(feedEl){
    if(!feedEl) return false;
    return (feedEl.scrollHeight - feedEl.clientHeight) > 8;
  }

  function feedAtBoundary(feedEl, dy){
    if(!feedEl) return true;
    if(!feedHasOverflow(feedEl)) return true;
    var max = feedEl.scrollHeight - feedEl.clientHeight;
    if(dy > 0) return feedEl.scrollTop >= (max - 8);
    return feedEl.scrollTop <= 8;
  }

  function nestedScrollAtBoundary(el, dy){
    if(!el || dy === 0) return true;
    var max = el.scrollHeight - el.clientHeight;
    if(max <= 8) return true;
    if(dy > 0) return el.scrollTop >= (max - 8);
    return el.scrollTop <= 8;
  }

  function scrollDir(dy){
    return dy > 0 ? 'next' : 'prev';
  }

  function resetFeedScroll(){
    try{
      var el = getFeedCol();
      if(!el) return;
      var prev = el.style.scrollBehavior;
      el.style.scrollBehavior = 'auto';
      el.scrollTop = 0;
      el.style.scrollBehavior = prev;
    }catch(e){}
  }

  var lastNavAt = 0;
  var wheelAcc = 0;
  var wheelT = null;
  var navigating = false;

  function goToPost(postId, dir){
    if(typeof window.loadPost !== 'function') return false;
    postId = Number(postId || 0);
    if(!postId || navigating) return false;
    navigating = true;
    resetFeedScroll();
    window.loadPost(postId, false, { fromFeedScroll:true, resetFeedScroll:true });
    setTimeout(function(){ navigating = false; }, 320);
    return true;
  }

  function goNext(){
    var ids = feedNavIds();
    var cur = getSelectedId();
    if(!ids.length || !cur) return false;
    var idx = ids.indexOf(cur);
    if(idx === -1) idx = 0;
    var next = ids[Math.min(idx + 1, ids.length - 1)];
    if(!next || next === cur) return false;
    return goToPost(next, 'next');
  }

  function goPrev(){
    var ids = feedNavIds();
    var cur = getSelectedId();
    if(!ids.length || !cur) return false;
    var idx = ids.indexOf(cur);
    if(idx === -1) idx = 0;
    var prev = ids[Math.max(idx - 1, 0)];
    if(!prev || prev === cur) return false;
    return goToPost(prev, 'prev');
  }

  function tryNavigate(dir){
    var now = Date.now();
    if(now - lastNavAt < NAV_COOLDOWN_MS || navigating) return false;
    var ok = (dir === 'next') ? goNext() : goPrev();
    if(ok) lastNavAt = now;
    return ok;
  }

  function onFeedWheel(e){
    if(!isDesktop() || navigating) return;
    if(document.body.classList.contains('lb-open')) return;
    if(document.body.classList.contains('tt-stories-open')) return;
    if(e.target && e.target.closest && e.target.closest('#tt-stories-wrap, .tt-stories-scroll-panel, .tt-stories-scroll-inner, .tt-stories-text-slide, .tt-stories-caption')) return;
    if(e.target && e.target.closest && e.target.closest('.rightSidebarCard, #postList')) return;
    if(e.target && e.target.closest && e.target.closest('#mvOverlay, #rmOverlay, #cOverlay, #postViewer, #vwOverlay')) return;

    var feedEl = getFeedCol();
    if(!feedEl || !pointerInFeed(e, feedEl)) return;

    var dy = Number(e.deltaY || 0);
    if(Math.abs(dy) < 0.5) return;

    var nested = e.target.closest && e.target.closest('.ig-comments, .c-bodywrap, .rm-content, .vw-body, .pv-body, .pv-desc');
    if(nested && nested !== feedEl && !nestedScrollAtBoundary(nested, dy)) return;

    if(feedHasOverflow(feedEl) && !feedAtBoundary(feedEl, dy)) return;

    e.preventDefault();

    if(feedHasOverflow(feedEl) && feedAtBoundary(feedEl, dy)){
      tryNavigate(scrollDir(dy));
      return;
    }

    wheelAcc += dy;
    clearTimeout(wheelT);
    wheelT = setTimeout(function(){ wheelAcc = 0; }, WHEEL_IDLE_MS);

    if(Math.abs(wheelAcc) < WHEEL_COMMIT) return;

    var dir = scrollDir(wheelAcc);
    wheelAcc = 0;
    clearTimeout(wheelT);
    tryNavigate(dir);
  }

  var touchStartY = 0;
  var touchStartX = 0;
  var touching = false;

  function onFeedTouchStart(e){
    if(!isDesktop() || navigating) return;
    var feedEl = getFeedCol();
    if(!feedEl || !feedEl.contains(e.target)) return;
    if(e.target.closest && e.target.closest('.rightSidebarCard, #postList')) return;
    var t = e.touches && e.touches[0];
    if(!t) return;
    touching = true;
    touchStartY = t.clientY;
    touchStartX = t.clientX;
  }

  function onFeedTouchEnd(e){
    if(!touching) return;
    touching = false;
    if(!isDesktop() || navigating) return;
    var feedEl = getFeedCol();
    if(!feedEl) return;
    var t = (e.changedTouches && e.changedTouches[0]) || null;
    if(!t) return;
    var swipeDy = touchStartY - t.clientY;
    var dx = t.clientX - touchStartX;
    if(Math.abs(dx) > Math.abs(swipeDy) || Math.abs(swipeDy) < 44) return;
    if(feedHasOverflow(feedEl) && !feedAtBoundary(feedEl, swipeDy)) return;
    tryNavigate(scrollDir(swipeDy));
  }

  function bindFeedColumn(){
    var feedEl = getFeedCol();
    if(!feedEl || feedEl.__feedScrollBound) return;
    feedEl.__feedScrollBound = true;
    feedEl.addEventListener('touchstart', onFeedTouchStart, {passive:true});
    feedEl.addEventListener('touchend', onFeedTouchEnd, {passive:true});
  }

  if(!window.__feedDesktopWheelBound){
    window.__feedDesktopWheelBound = true;
    document.addEventListener('wheel', onFeedWheel, {passive:false, capture:true});
  }

  if(document.readyState === 'loading'){
    document.addEventListener('DOMContentLoaded', bindFeedColumn);
  }else{
    bindFeedColumn();
  }
  window.addEventListener('resize', bindFeedColumn);
})();

/* [FEED_VIDEO_SMART_PLAYBACK_JS] =====================================
   Smart feed video behavior for feed.php
   - autoplay muted when visible enough
   - pause when leaving viewport
   - pause other feed videos when one starts playing
   - safe for dynamically loaded cards
=================================================================== */
(function(){
  function smartVideos(){
    return Array.prototype.slice.call(document.querySelectorAll('video.ig-smart-feed-video[data-smart-video="1"]') || []);
  }

  function isAutoPlayable(v){
    if(!v) return false;
    if(v.dataset && v.dataset.disableSmartVideo === '1') return false;
    if(v.closest && (v.closest('#mvOverlay') || v.closest('#postViewer') || v.closest('.ig-viewer-body') || v.closest('.post-viewer'))) return false;
    return true;
  }

  function ensureVideoDefaults(v){
    try{ v.playsInline = true; }catch(e){}
    try{ v.setAttribute('playsinline',''); }catch(e){}
    try{
      if(!v.hasAttribute('preload') || v.getAttribute('preload') === 'none'){
        v.setAttribute('preload', 'none');
      }
    }catch(e){}
    try{ if(!v.dataset.userUnmuted || v.dataset.userUnmuted !== '1') v.muted = true; }catch(e){}
  }

  function primeVideoForPlayback(v){
    if(!v) return;
    try{
      if(v.getAttribute('preload') === 'none' && v.readyState < 1){
        v.setAttribute('preload', 'metadata');
        v.load();
      }
    }catch(e){}
  }

  function pauseAllExcept(except){
    smartVideos().forEach(function(v){
      try{
        if(except && v === except) return;
        if(!v.paused) v.pause();
      }catch(e){}
    });
  }

  function tryAutoplay(v){
    if(!isAutoPlayable(v)) return;
    ensureVideoDefaults(v);
    primeVideoForPlayback(v);
    pauseAllExcept(v);
    try{
      var pr = v.play && v.play();
      if(pr && typeof pr.catch === 'function'){
        pr.catch(function(){});
      }
    }catch(e){}
  }

  function pauseIfNeeded(v){
    try{ if(v && !v.paused) v.pause(); }catch(e){}
  }

  document.addEventListener('play', function(e){
    var v = e && e.target;
    if(!v || !v.matches || !v.matches('video.ig-smart-feed-video[data-smart-video="1"]')) return;
    pauseAllExcept(v);
  }, true);

  document.addEventListener('volumechange', function(e){
    var v = e && e.target;
    if(!v || !v.matches || !v.matches('video.ig-smart-feed-video[data-smart-video="1"]')) return;
    try{ if(v.muted === false || Number(v.volume||0) > 0){ v.dataset.userUnmuted = '1'; } }catch(err){}
  }, true);

  var io = null;
  function observeAll(){
    if(!io) return;
    smartVideos().forEach(function(v){
      try{
        ensureVideoDefaults(v);
        if(v.dataset.smartObserved === '1') return;
        io.observe(v);
        v.dataset.smartObserved = '1';
      }catch(e){}
    });
  }

  function setupObserver(){
    if(io || !('IntersectionObserver' in window)) return;
    io = new IntersectionObserver(function(entries){
      entries.forEach(function(en){
        var v = en && en.target;
        if(!v || !isAutoPlayable(v)) return;
        var ratio = Number(en.intersectionRatio || 0);
        if(en.isIntersecting && ratio >= 0.72){
          tryAutoplay(v);
        }else if(!en.isIntersecting || ratio < 0.45){
          pauseIfNeeded(v);
        }
      });
    }, { root:null, threshold:[0, 0.2, 0.45, 0.72, 0.9, 1] });
    observeAll();
  }

  var ticking = false;
  function fallbackScan(){
    if(io || ticking) return;
    ticking = true;
    requestAnimationFrame(function(){
      ticking = false;
      var vh = window.innerHeight || document.documentElement.clientHeight || 0;
      smartVideos().forEach(function(v){
        try{
          var r = v.getBoundingClientRect();
          var visible = Math.min(r.bottom, vh) - Math.max(r.top, 0);
          var ratio = r.height ? (visible / r.height) : 0;
          if(ratio >= 0.72){
            tryAutoplay(v);
          }else if(ratio < 0.45){
            pauseIfNeeded(v);
          }
        }catch(e){}
      });
    });
  }

  function init(){
    smartVideos().forEach(ensureVideoDefaults);
    setupObserver();
    fallbackScan();
    window.addEventListener('scroll', fallbackScan, {passive:true});
    window.addEventListener('resize', fallbackScan, {passive:true});
    window.addEventListener('orientationchange', fallbackScan, {passive:true});
    try{
      var moPending = false;
      var mo = new MutationObserver(function(mutations){
        var hasMedia = false;
        for(var i = 0; i < mutations.length; i += 1){
          var nodes = mutations[i].addedNodes || [];
          for(var j = 0; j < nodes.length; j += 1){
            var node = nodes[j];
            if(!node || node.nodeType !== 1) continue;
            if((node.matches && node.matches('video.ig-smart-feed-video[data-smart-video="1"]')) ||
               (node.querySelector && node.querySelector('video.ig-smart-feed-video[data-smart-video="1"]'))){
              hasMedia = true;
              break;
            }
          }
          if(hasMedia) break;
        }
        if(!hasMedia || moPending) return;
        moPending = true;
        requestAnimationFrame(function(){
          moPending = false;
          observeAll();
        });
      });
      mo.observe(document.body, { childList:true, subtree:true });
    }catch(e){}
  }

  if(document.readyState === 'loading'){
    document.addEventListener('DOMContentLoaded', init);
  }else{
    init();
  }
})();

</script>



<!-- ✅ Post Viewer Modal (Instagram-style) -->
<div id="pvOverlay" class="pv-overlay" aria-hidden="true">
  <button type="button" class="pv-x" id="pvClose" aria-label="Close"><i class="icon ion-close"></i></button>
  <button type="button" class="pv-nav pv-prev" id="pvPrev" aria-label="Previous"><i class="icon ion-chevron-left"></i></button>
  <button type="button" class="pv-nav pv-next" id="pvNext" aria-label="Next"><i class="icon ion-chevron-right"></i></button>

  <div class="pv-modal" role="dialog" aria-modal="true" aria-label="Post viewer">
    <div class="pv-left">
      <div class="pv-media" id="pvMedia"></div>
    </div>
    <div class="pv-right">
      <div class="pv-head">
        <div class="pv-user">
          <img id="pvAvatar" class="pv-ava" alt="" src="" />
          <div class="pv-namewrap">
            <div id="pvName" class="pv-name">—</div>
            <div id="pvMeta" class="pv-meta">—</div>
          </div>
        </div>
        <button type="button" class="pv-dots" id="pvDots" aria-label="More"><i class="icon ion-android-more-horizontal"></i></button>
      </div>

      <!-- ✅ Scrollable middle (caption + comments) so footer/input never hides on mobile/tablet -->
      <div class="pv-body" id="pvBody">
        <!-- ✅ Post caption (with Read more) -->
        <div class="pv-caption" id="pvCaption" style="display:none;"></div>

        <div class="pv-comments" id="pvComments" aria-label="Comments"></div>
      </div>

      <div class="pv-actions">
        <div class="pv-actrow">
          <button type="button" class="pv-act" id="pvLove" title="Love" aria-label="Love"><i class="icon ion-heart"></i></button>
          <button type="button" class="pv-act" id="pvLike" title="Like" aria-label="Like"><i class="icon ion-thumbsup"></i></button>
          <button type="button" class="pv-act" id="pvComment" title="Comment" aria-label="Comment"><i class="icon ion-chatbubble"></i></button>
          <button type="button" class="pv-act" id="pvShare" title="Share" aria-label="Share"><i class="icon ion-forward"></i></button>
          <div class="pv-sp"></div>
          <button type="button" class="pv-act" id="pvSave" title="Save" aria-label="Save"><i class="icon ion-bookmark"></i></button>
        </div>
        <div class="pv-counts">
          <span class="pv-c" title="Love"><i class="icon ion-heart"></i> <b id="pvLoveN">0</b></span>
          <span class="pv-c" title="Like"><i class="icon ion-thumbsup"></i> <b id="pvLikeN">0</b></span>
          <span class="pv-c" title="Comments"><i class="icon ion-chatbubble"></i> <b id="pvComN">0</b></span>
          <span class="pv-c" title="Share"><i class="icon ion-forward"></i> <b id="pvShareN">0</b></span>
          <span class="pv-c" title="Save"><i class="icon ion-bookmark"></i> <b id="pvSaveN">0</b></span>
          <span class="pv-c" title="Views"><i class="icon ion-eye"></i> <b id="pvViewN">0</b></span>
        </div>
        <div class="pv-replybar" id="pvReplyBar" style="display:none;">
          <span><span id="pvReplyLead">Replying to</span> <b id="pvReplyName">—</b></span>
          <button type="button" class="pv-replyx" id="pvReplyCancel" aria-label="Cancel reply"><i class="icon ion-close"></i></button>
        </div>
        <div class="pv-input">
          <input type="text" id="pvText" placeholder="Add a comment…" autocomplete="off" />
          <button type="button" id="pvPostBtn">Post</button>
        </div>
      </div>
    </div>
  </div>
</div>

<style>
  /* ✅ Modal (instagram-style) */
  .pv-overlay{position:fixed;inset:0;display:none;align-items:center;justify-content:center;background:rgba(15,23,42,.72);z-index:9999;padding:24px;overflow:auto;-webkit-overflow-scrolling:touch;overscroll-behavior:contain;}
  .pv-overlay.show{display:flex;}
  .pv-modal{width:min(1120px,96vw);height:min(720px,88vh);background:#fff;overflow:hidden;display:flex;box-shadow:0 30px 90px rgba(0,0,0,.45);}
  .pv-left{flex:1.15;min-width:0;background:#0b1220;display:flex;align-items:center;justify-content:center;}
  .pv-media{width:100%;height:100%;display:flex;align-items:center;justify-content:center;}
  .pv-media img,.pv-media video,.pv-media iframe{max-width:100%;max-height:100%;width:auto;height:auto;}
  .pv-media video{width:100%;height:100%;object-fit:contain;}
  /* ✅ Mobile/Tablet: allow long LEFT description (no media) to scroll */
  @media (max-width: 900px){
    .pv-left.pv-left-scroll{align-items:stretch !important;justify-content:stretch !important;}
    .pv-left.pv-left-scroll .pv-media{overflow:auto;-webkit-overflow-scrolling:touch;align-items:flex-start !important;justify-content:flex-start !important;}
    .pv-left.pv-left-scroll .pv-media > div{height:auto !important;min-height:100%;align-items:flex-start !important;justify-content:flex-start !important;padding:22px !important;}
  }

  .pv-right{flex:.85;min-width:320px;display:flex;flex-direction:column;background:#fff;min-height:0;}
  .pv-head{padding:14px 14px;border-bottom:1px solid rgba(15,23,42,.08);display:flex;align-items:center;justify-content:space-between;gap:10px;}
  .pv-user{display:flex;align-items:center;gap:10px;min-width:0;}
  .pv-ava{width:38px;height:38px;border-radius:999px;object-fit:cover;background:#eef2ff;}
  .pv-namewrap{min-width:0;}
  .pv-name{font-weight:700;font-size:14px;line-height:1.1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
  .pv-meta{font-size:12px;color:rgba(15,23,42,.55);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
  .pv-dots{border:0;background:transparent;width:36px;height:36px;border-radius:10px;display:flex;align-items:center;justify-content:center;cursor:pointer;}
  .pv-dots:hover{background:rgba(15,23,42,.06);}

  /* ✅ Middle scroll area: prevents input/actions from being pushed off-screen on mobile/tablet */
  .pv-body{flex:1;min-height:0;overflow:auto;-webkit-overflow-scrolling:touch;overscroll-behavior:contain;}
  .pv-comments{padding:12px 14px;}
  /* keep space so last comment never hides behind the footer/input */
  .pv-comments{padding-bottom:160px;}

  /* ✅ Footer stays visible; input is sticky inside footer */
  .pv-actions{position:sticky;bottom:0;background:#fff;z-index:3;}
  /* make input sticky so it never gets hidden by long scroll/keyboard */
  .pv-input{position:sticky;bottom:0;background:#fff;padding:10px 0 calc(10px + env(safe-area-inset-bottom));margin-top:10px;z-index:4;}
  .pv-input::before{content:"";position:absolute;left:0;right:0;top:-10px;height:10px;background:linear-gradient(to top, rgba(255,255,255,1), rgba(255,255,255,0));}

  /* ✅ Mobile/tablet viewport fixes: avoid VH bugs + ensure comment input is visible */
  @media (max-width: 980px){
    .pv-overlay{padding:10px;align-items:stretch;}
    .pv-modal{width:100%;height:calc(var(--vh, 1vh) * 100 - 20px);max-height:none;border-radius:18px;}
  }
  @media (max-width: 640px){
    .pv-overlay{padding:0;}
    .pv-modal{width:100vw;height:calc(var(--vh, 1vh) * 100);border-radius:0;}
  }


  /* ✅ Caption (post text) inside modal right panel */
  .pv-caption{border-bottom:1px solid rgba(15,23,42,.08);padding:10px 14px;max-height:140px;overflow:auto;}
  .pv-cap{font-size:13px;line-height:1.35;color:#0f172a;word-break:break-word;}
  .pv-cap-title{font-size:15px;font-weight:800;line-height:1.25;margin-bottom:6px;}
  .pv-cap-desc{font-size:13px;line-height:1.45;}
  .pv-cap-short,.pv-cap-full{white-space:normal;word-break:break-word;}
  .pv-cap[data-expanded="1"] .pv-cap-desc{max-height:220px;overflow:auto;padding-right:6px;}

  .pv-cap b{font-weight:800;}
  .pv-media-shell{width:100%;height:100%;display:flex;align-items:center;justify-content:center;padding:26px;}
  .pv-media-copy{max-width:640px;color:#fff;text-align:left;max-height:100%;overflow:hidden;}
  .pv-media-copy.is-title-only{text-align:center;}
  .pv-media-text{white-space:normal;word-break:break-word;}
  .pv-media-text[data-expanded="1"]{max-height:min(58vh, 420px);overflow:auto;padding-right:8px;}
  .pv-readmore{margin-left:6px;font-weight:800;color:#2563eb;cursor:pointer;white-space:nowrap;}
  .pv-readmore:hover{text-decoration:underline;}
  .pv-richtext{display:block;}
  .pv-richtext .pv-rich-p{margin:0 0 12px;white-space:normal;word-break:break-word;}
  .pv-richtext .pv-rich-p:last-child{margin-bottom:0;}
  .pv-richtext .pv-rich-list{
    margin:0 0 12px;
    /* padding-left:22px; */
  }
  .pv-richtext .pv-rich-list.is-ordered{list-style:decimal;}
  .pv-richtext .pv-rich-list.is-bullet{list-style:disc;}
  .pv-richtext .pv-rich-li{margin:0 0 6px;}
  .pv-richtext .pv-rich-li:last-child{margin-bottom:0;}
  .pv-cap-desc .pv-richtext,.pv-media-text .pv-richtext,#rmBody .pv-richtext{color:inherit;font:inherit;line-height:inherit;}
  .pv-cap-short .pv-rich-p,.pv-cap-full .pv-rich-p,.pv-media-short .pv-rich-p,.pv-media-full .pv-rich-p{display:block;}
  .pv-rich-ellipsis{display:inline;}
  .pv-node{position:relative;--pv-avatar-size:32px;}
  .pv-node.has-children::after{content:"";position:absolute;left:calc(var(--pv-avatar-size) / 2);top:calc(var(--pv-avatar-size) + 2px);bottom:18px;width:2px;background:rgba(148,163,184,.28);border-radius:999px;}
  .pv-node.has-children.is-collapsed::after{display:none;}
  .pv-children{margin-left:calc(var(--pv-avatar-size) / 2);padding-left:28px;}
  .pv-children.depth-capped{margin-left:0;padding-left:0;}
  .pv-node.is-reply::before{content:"";position:absolute;left:-28px;top:0;width:28px;height:16px;border-left:2px solid rgba(148,163,184,.28);border-bottom:2px solid rgba(148,163,184,.28);border-bottom-left-radius:18px;}
  .pv-node.is-depth-clamped::before{display:none;}
  .pv-com{display:flex;gap:10px;margin-bottom:12px;}
  .pv-com.is-alert-focus{background:rgba(37,99,235,.08);border:1px solid rgba(37,99,235,.24);box-shadow:0 14px 28px rgba(37,99,235,.12);border-radius:16px;padding:10px;}
  .pv-com .a{width:32px;height:32px;border-radius:999px;background:#eef2ff;flex:0 0 32px;overflow:hidden;}
  .pv-com .a img{width:100%;height:100%;object-fit:cover;}
  .pv-com .b{min-width:0;flex:1;}
  .pv-com .bubble{display:inline-block;max-width:min(100%,460px);background:#f3f4f6;border:1px solid rgba(15,23,42,.06);border-radius:18px;padding:10px 14px 11px;}
  .pv-com .t{font-size:13px;line-height:1.3;color:#0f172a;}
  .pv-com .t b{font-weight:700;}
  .pv-com .m{margin-top:4px;font-size:11px;color:rgba(15,23,42,.55);display:flex;gap:12px;align-items:center;flex-wrap:wrap;padding-left:8px;}
  .pv-com .m .link{cursor:pointer;color:#2563eb;}
  .pv-com .m .link:hover{text-decoration:underline;}
  .pv-com .m .replies-toggle{border:0;background:transparent;padding:0;color:#2563eb;font:inherit;font-weight:800;cursor:pointer;}
  .pv-com .m .replies-toggle:hover{text-decoration:underline;}
  .pv-com .m .likebtn{border:0;background:transparent;padding:0;color:inherit;font:inherit;font-weight:800;cursor:pointer;}
  .pv-com .m .likebtn.is-liked{color:#2563eb;}
  .pv-likepill{display:inline-flex;align-items:center;gap:6px;padding:2px 10px;border-radius:999px;background:rgba(37,99,235,.12);color:#1d4ed8;font-weight:800;}
  .pv-likepill i{font-size:12px;}
  html.dark-auto .pv-node.has-children::after,
  html[data-theme="dark"] .pv-node.has-children::after{background:rgba(148,163,184,.38);}
  html.dark-auto .pv-node.is-reply::before,
  html[data-theme="dark"] .pv-node.is-reply::before{border-left-color:rgba(148,163,184,.38);border-bottom-color:rgba(148,163,184,.38);}
  html.dark-auto .pv-com .bubble{background:rgba(255,255,255,.08);border-color:rgba(255,255,255,.08);}
  html.dark-auto .pv-likepill{background:rgba(96,165,250,.18);color:#bfdbfe;}
  html.dark-auto .pv-com.is-alert-focus{background:rgba(96,165,250,.16);border-color:rgba(147,197,253,.34);box-shadow:0 14px 28px rgba(2,6,23,.34);}

  .pv-actions{border-top:1px solid rgba(15,23,42,.08);padding:10px 12px 12px;}
  .pv-actrow{display:flex;align-items:center;gap:6px;}
  .pv-act{border:0;background:transparent;width:38px;height:38px;border-radius:12px;display:flex;align-items:center;justify-content:center;cursor:pointer;color:#111827;}
  .pv-act:hover{background:rgba(15,23,42,.06);}
  .pv-sp{flex:1;}
  /* ✅ HIDE the small reaction counters row under the modal action icons (user request) */
  .pv-counts{display:none !important;}

  /* ✅ toggled colors (match your feed.php request) */
  .pv-act.is-love{color:var(--msb-love-color, #7c3aed);} /* purple */
  .pv-act.is-like{color:#2563eb;} /* blue */
  .pv-act.is-save{color:#f59e0b;} /* yellow */
  .pv-act.is-share{color:#4b5563;} /* dark grey */

  .pv-input{margin-top:10px;display:flex;gap:10px;align-items:center;}
  .pv-input input{flex:1;min-width:0;height:40px;border-radius:12px;border:1px solid rgba(15,23,42,.14);padding:0 12px;outline:none;}
  .pv-input input:focus{border-color:rgba(37,99,235,.45);box-shadow:0 0 0 3px rgba(37,99,235,.12);}
  .pv-input button{height:40px;border:0;border-radius:12px;padding:0 14px;font-weight:700;background:#2563eb;color:#fff;cursor:pointer;}
  .pv-input button:disabled{opacity:.55;cursor:not-allowed;}

  .pv-replybar{margin-top:8px;display:flex;align-items:center;justify-content:space-between;background:rgba(37,99,235,.08);border:1px solid rgba(37,99,235,.18);padding:6px 10px;border-radius:12px;font-size:12px;color:#1e3a8a;}
  .pv-replyx{border:0;background:transparent;width:28px;height:28px;border-radius:10px;display:flex;align-items:center;justify-content:center;cursor:pointer;color:#1e3a8a;}
  .pv-replyx:hover{background:rgba(37,99,235,.12);}

  .pv-x{position:fixed;top:14px;right:14px;z-index:10000;border:0;background:rgba(255,255,255,.12);backdrop-filter: blur(8px);color:#fff;width:42px;height:42px;border-radius:999px;display:flex;align-items:center;justify-content:center;cursor:pointer;}
  .pv-x:hover{background:rgba(255,255,255,.18);}
  .pv-nav{position:fixed;top:50%;transform:translateY(-50%);z-index:10000;border:0;background:rgba(255,255,255,.12);backdrop-filter: blur(8px);color:#fff;width:44px;height:44px;border-radius:999px;display:flex;align-items:center;justify-content:center;cursor:pointer;}
  .pv-nav:hover{background:rgba(255,255,255,.18);}
  .pv-prev{left:14px;}
  .pv-next{right:14px;}

  @media (max-width: 860px){
    .pv-overlay{align-items:stretch;justify-content:flex-start;}
    .pv-modal{flex-direction:column;width:min(720px,96vw);height:min(calc(var(--vh, 1vh) * 92),860px);margin:auto;position:relative;}
    .pv-right{min-width:0;}
    .pv-left{flex:1;min-height:42vh;}

    /* prevent nav from colliding with avatar/header on small screens */
    .pv-nav{position:absolute;top:calc(22vh);transform:translateY(-50%);}
    .pv-prev{left:10px;}
    .pv-next{right:10px;}
  }
  @media (max-width: 520px){
    .pv-overlay{padding:10px;}
    .pv-modal{border-radius:14px;height:calc(var(--vh, 1vh) * 100 - 20px);}
    .pv-head{padding:12px;}
    .pv-comments{padding:10px 12px;padding-bottom:160px;}

    .pv-nav{width:40px;height:40px;}
    .pv-nav i{font-size:18px;}
  }

  body.pv-body-lock{touch-action:none;}

    .rightSidebarCard #refreshBtn.spin{ display:inline-block; animation:rsSpin .5s linear 1; }
    @keyframes rsSpin{ from{ transform:rotate(0deg);} to{ transform:rotate(360deg);} }
</style>

<style>
.sh-mainpanel,
.sh-pagebody,
.sh-pagetitle,
.yt-pagebar,
.ig-post-card,
.ig-post-footer-card,
.rightSidebarCard,
.card.mg-t-15,
.mf-card,
.rm-modal,
.c-modal,
.vw-modal,
.pv-modal,
.pv-right{
  color:var(--feed-text);
}
.sh-mainpanel,
.sh-pagebody{
  background:transparent;
}
.sh-pagetitle,
.yt-pagebar{
  background:var(--feed-topbar-bg);
  border-bottom:1px solid var(--feed-border);
  box-shadow:0 10px 28px rgba(15,23,42,.08);
  backdrop-filter:blur(14px);
}
.yt-icon-btn,
.yt-brand,
.yt-signin{
  color:var(--feed-topbar-text);
}
.yt-signin{
  border-color:var(--feed-control-border);
  background:var(--feed-control-soft);
}
.search-input{
  background:var(--feed-control-bg);
  color:var(--feed-topbar-text);
  border-color:var(--feed-control-border);
}
.search-input::placeholder{
  color:var(--feed-control-placeholder);
}
.search-btn,
.yt-mic-btn{
  background:var(--feed-control-soft);
  color:var(--feed-topbar-text);
  border-color:var(--feed-control-border);
}
.card.bd-primary,
.ig-post-card,
.ig-post-footer-card,
.rightSidebarCard,
.card.mg-t-15,
.mf-card:not(.mf-card-video):not(.mf-card-reel){
  background:var(--feed-surface);
  border-color:var(--feed-border) !important;
  box-shadow:0 16px 36px rgba(15,23,42,.08);
  padding: 30px;
}
.ig-post-card .card-header,
.rightSidebarCard .card-header{
  background:linear-gradient(180deg, var(--feed-surface-alt) 0%, var(--feed-surface) 100%);
  border-bottom:1px solid var(--feed-border);
}
.rightSidebarBody{
  background:linear-gradient(180deg, var(--feed-surface-alt) 0%, var(--feed-surface) 100%);
}
.sidebar-tools{
  border-bottom-color:var(--feed-border);
}
.sidebar-pill{
  background:var(--feed-control-bg);
  color:var(--feed-text);
  border-color:var(--feed-border);
  box-shadow:none;
}
.pl-item{
  border-color:transparent;
}
.pl-item:hover{
  background:var(--feed-surface-alt);
}
.pl-item.active{
  background:var(--feed-accent-soft);
  box-shadow:inset 0 0 0 1px rgba(13,97,188,.10);
}
html[data-theme="dark"] .pl-item.active{
  box-shadow:inset 0 0 0 1px rgba(124,178,255,.18);
}
.pl-chip{
  background:var(--feed-surface-strong);
  color:var(--feed-muted);
  border-color:var(--feed-border);
}
.pl-title,
.card-title,
.ig-title,
.ig-cap-title,
#pvAuthor,
#pvTitle,
.rm-author,
.c-author,
.mf-name,
.mf-title,
.rm-title,
.c-title,
.vw-title,
.pv-cap,
.pv-com .t{
  color:var(--feed-text);
}
.pl-desc,
.ig-desc,
#pvMeta,
.ig-cap-meta,
.mf-time,
.mf-file .mf-file-sub,
.rm-sub,
.c-sub,
.vw-stat-k,
.vw-user,
.vw-when,
.card.mg-t-15 .text-muted,
.sh-mainpanel .text-muted,
.pv-com .m{
  color:var(--feed-muted) !important;
}
.pl-readmore,
.ig-cap-readmore,
.mf-body .mf-readmore,
.mf-file a,
.pv-readmore,
.pv-com .m .link{
  color:var(--feed-accent);
}
.ig-body,
.ig-cap-text,
.mf-body,
.rm-body,
.c-list .cmt .txt,
.pv-cap{
  color:var(--feed-text);
}
.ig-media-caption{
  background:var(--feed-surface-strong);
  color:var(--feed-text);
}
.ig-underbar,
.ig-layout.is-media-only .ig-topbar.pv-top-detached,
.ig-layout.is-full-media .ig-topbar.pv-top-detached{
  border-bottom-color:var(--feed-border);
  background:var(--feed-surface);
}
.ig-underbar .ig-circle{
  background:var(--feed-surface-alt);
  border:1px solid var(--feed-border);
  box-shadow:none;
}
.ig-underbar .ig-count,
.mf-act .mf-num{
  color:var(--feed-soft-text);
}
.ig-post-footer-card .ig-post-footer{
  background:var(--feed-surface-alt);
  box-shadow:none;
}
.ig-post-progress{
  background:rgba(15,23,42,.08);
}
html[data-theme="dark"] .ig-post-progress{
  background:rgba(255,255,255,.10);
}
.ig-post-footer-act,
.ig-post-footer-link{
  color:var(--feed-text);
}
.ig-post-footer-act:hover,
.ig-post-footer-link:hover{
  color:var(--feed-accent-strong);
}
#refreshBtn,
#btnFindMe,
#btnFindAuthor,
#btnOpenMedia,
#btnFullscreenMedia{
  color:var(--feed-text);
}
#filterSel,
#searchBox,
.rightSidebarBody .form-control,
.c-footer .form-control,
.pv-input input,
.pv-input textarea{
  background:var(--feed-control-bg);
  color:var(--feed-text);
  border-color:var(--feed-control-border);
}
#searchBox::placeholder,
.c-footer .form-control::placeholder,
.pv-input input::placeholder,
.pv-input textarea::placeholder{
  color:var(--feed-control-placeholder);
}
.mf-card{
  border-color:var(--feed-border);
}
.mf-menu{
  background:var(--feed-surface);
  border-color:var(--feed-border);
}
.mf-menu a,
.mf-menu button,
.mf-file .mf-file-main,
.mf-body{
  color:var(--feed-text);
}
.mf-menu-btn{
  color:var(--feed-muted);
}
.mf-menu a:hover,
.mf-menu button:hover{
  background:var(--feed-accent-soft);
}
.mf-media{
  background:transparent;
}
.mf-card.is-single-video-post .mf-media,
.mf-card.is-single-image-post .mf-media,
.mf-card.is-single-video-post .media-stage,
.mf-card.is-single-image-post .media-stage,
.mf-card.is-single-video-post video,
.mf-card.is-single-image-post img{
  background:transparent !important;
}
.mf-file .mf-file-ic{
  background:var(--feed-accent-soft);
  color:var(--feed-accent);
}
.mf-actions{
  border-top-color:var(--feed-border);
}
.rm-modal,
.c-modal,
.vw-modal,
.pv-modal,
.pv-right{
  background:var(--feed-surface);
}
.rm-section,
.vw-stat,
.c-list .cmt,
.c-list .cmt.reply{
  background:var(--feed-surface-alt);
  border-color:var(--feed-border);
}
.c-bodywrap,
.c-footer,
.rm-footer,
.vw-body,
.pv-actions,
.pv-input{
  background:var(--feed-surface);
  border-color:var(--feed-border);
}
.rm-x,
.c-x,
.vw-close,
.pv-act{
  background:var(--feed-surface-alt);
  border-color:var(--feed-border);
  color:var(--feed-text);
}
.vw-stat-v{
  color:var(--feed-text);
}
</style>

<style>
@media (min-width:1025px){
  body{
    --mf-desktop-topbar-clearance:10px;
  }

  html,
  body,
  body .sh-mainpanel,
  body .sh-pagebody{
    background:var(--feed-surface) !important;
  }

  body .sh-mainpanel{
    min-height:100vh !important;
    box-shadow:none !important;
  }

  body .sh-pagebody{
    padding:0 0 72px !important;
  }
  body.feed-insta-ui .sh-pagebody{
    padding:0 !important;
  }

  body .mf-feed{
    max-width:614px;
    margin:calc(var(--mf-desktop-topbar-clearance) + 8px) auto 0;
    padding:0 0 96px;
  }
  body.feed-insta-ui .feed-desktop-layout .mf-feed{
    max-width:100% !important;
    margin-top:0 !important;
    padding-top:0 !important;
  }

  body.feed-insta-ui .feed-desktop-center{
    border-left:1px solid var(--feed-post-column-border, var(--feed-border-strong, rgba(177, 188, 206, 0.22)));
    border-right:1px solid var(--feed-post-column-border, var(--feed-border-strong, rgba(177, 188, 206, 0.22)));
    box-sizing:border-box;
  }

  body.feed-insta-ui .feed-top-search{
    border-bottom:1px solid var(--feed-post-divider, var(--feed-border-strong, rgba(177, 188, 206, 0.22)));
  }

  body.feed-page #ttLeftbarOverlays,
  body.feed-page.feed-insta-ui #ttLeftbarOverlays{
    z-index:1295;
  }
  body.feed-page.public-leftbar-open{
    overflow-x:hidden;
  }

  body .mf-feed .mf-card{
    background:var(--feed-surface) !important;
    border:0 !important;
    border-bottom:1px solid var(--feed-post-divider, var(--feed-border-strong, rgba(177, 188, 206, 0.22))) !important;
    border-radius:0 !important;
    box-shadow:none !important;
    margin:0 !important;
    overflow:visible !important;
    max-width:100% !important;
  }

  body .mf-feed .mf-card:last-child{
    border-bottom:1px solid var(--feed-post-divider, var(--feed-border-strong, rgba(177, 188, 206, 0.22))) !important;
  }

  body .mf-feed .mf-card.is-single-video-post,
  body .mf-feed .mf-card.is-single-image-post{
    width:min(100%, var(--post-media-card-width, 100%)) !important;
    max-width:100% !important;
    margin-left:auto !important;
    margin-right:auto !important;
  }

  body .mf-feed .mf-card.is-single-video-post .mf-media,
  body .mf-feed .mf-card.is-single-video-post .media-stage,
  body .mf-feed .mf-card.is-single-video-post .media-stage.standard-video-stage,
  body .mf-feed .mf-card.is-single-video-post video{
    background:transparent !important;
  }

  body .mf-feed .mf-card.is-single-video-post .media-stage.standard-video-stage{
    width:100% !important;
    max-width:100% !important;
    overflow:visible !important;
    min-height:0 !important;
    background:transparent !important;
  }

  body .mf-feed .mf-card.is-single-video-post .media-stage.standard-video-stage > video{
    width:100% !important;
    height:auto !important;
    display:block !important;
    max-height:min(78vh, 960px) !important;
    object-fit:contain !important;
    background:transparent !important;
  }

  body .mf-feed .mf-card.mf-card-phone-shot:not(.is-multi-media-post){
    margin-inline:auto !important;
    box-sizing:border-box !important;
  }

  body .mf-feed .mf-card.mf-card-phone-shot.is-single-video-post,
  body .mf-feed .mf-card.mf-card-phone-shot.is-single-image-post{
    width:min(100%, var(--post-media-card-width, 680px)) !important;
    max-width:100% !important;
  }

  body .mf-feed .mf-card.mf-card-phone-shot .media-stage.phone-shot,
  body .mf-feed .mf-card.mf-card-phone-shot .mf-media.media-stage.phone-shot.standard-video-stage,
  body .mf-feed .mf-card.mf-card-phone-shot .mf-media.media-stage.phone-shot.standard-image-stage{
    width:100% !important;
    max-width:100% !important;
    margin-inline:auto !important;
    border-radius:18px !important;
    box-shadow:none !important;
    max-height:none !important;
    overflow:visible !important;
    background:transparent !important;
  }

  body .mf-feed .mf-card.mf-card-phone-shot .media-stage.phone-shot.standard-video-stage > video,
  body .mf-feed .mf-card.mf-card-phone-shot .media-stage.phone-shot.standard-image-stage > img{
    width:100% !important;
    height:auto !important;
    max-height:min(78vh, 960px) !important;
    object-fit:contain !important;
    background:transparent !important;
  }

  body .mf-feed .mf-card.mf-card-video,
  body .mf-feed .mf-card.mf-card-reel{
    /* border-radius:24px !important; */
    overflow:hidden !important;
  }

  body .mf-feed .mf-card.mf-card-reel:not(.mf-card-phone-shot){
    width:100% !important;
    max-width:100% !important;
    background:var(--feed-surface) !important;
    /* border:3px solid var(--feed-border) !important; */
    box-shadow:none !important;
    
  }

  body .mf-feed .mf-card.mf-card-reel.mf-card-phone-shot{
    max-width:100% !important;
    margin-inline:auto !important;
    background:var(--feed-surface) !important;
    box-shadow:none !important;
  }

  body .mf-feed .mf-card.mf-card-reel .mf-head{
    position:relative !important;
    left:auto !important;
    right:auto !important;
    top:auto !important;
    z-index:auto !important;
    padding:2px 6px 14px !important;
    color:var(--feed-text) !important;
    background:transparent !important;
  }

  body .mf-feed .mf-card.mf-card-reel .mf-head .mf-name,
  body .mf-feed .mf-card.mf-card-reel .mf-head .mf-time,
  body .mf-feed .mf-card.mf-card-reel .mf-head .mf-music-row,
  body .mf-feed .mf-card.mf-card-reel .mf-head .mf-music-ic,
  body .mf-feed .mf-card.mf-card-reel .mf-head .mf-music-title,
  body .mf-feed .mf-card.mf-card-reel .mf-head .mf-music-artist,
  body .mf-feed .mf-card.mf-card-reel .mf-head .mf-music-dot,
  body .mf-feed .mf-card.mf-card-reel .mf-head .mf-peer-link,
  body .mf-feed .mf-card.mf-card-reel .mf-head .mf-peer-link:hover,
  body .mf-feed .mf-card.mf-card-reel .mf-head .mf-menu-btn:not(.post-card-menu-btn){
    color:var(--feed-text) !important;
  }

  body .mf-feed .mf-card.mf-card-reel .mf-head .mf-menu-btn:not(.post-card-menu-btn){
    background:transparent !important;
    border-radius:0 !important;
  }

  body .mf-feed .mf-card.mf-card-reel:not(.mf-card-phone-shot) .mf-media{
    position:relative !important;
    width:auto !important;
    min-height:0 !important;
    margin:0 auto !important;
    background:transparent !important;
    overflow:hidden !important;
    aspect-ratio:auto !important;
  }

  body .mf-feed .mf-card.mf-card-reel.mf-card-phone-shot .mf-media{
    position:relative !important;
    width:100% !important;
    min-height:0 !important;
    margin:0 !important;
    background:transparent !important;
    overflow:hidden !important;
    aspect-ratio:auto !important;
  }

  body .mf-feed .mf-card.mf-card-reel:not(.mf-card-phone-shot) .mf-media video{
    width:100% !important;
    height:100% !important;
    display:block !important;
    background:transparent !important;
    object-fit:contain !important;
  }

  body .mf-feed .mf-card.mf-card-reel.mf-card-phone-shot .mf-media video{
    width:100% !important;
    height:auto !important;
    max-height:min(78vh, 960px) !important;
    display:block !important;
    background:transparent !important;
    object-fit:contain !important;
  }

  body .mf-feed .mf-card.mf-card-reel .mf-reel-title,
  body .mf-feed .mf-card.mf-card-reel .mf-reel-body,
  body .mf-feed .mf-card.mf-card-reel .mf-reel-rail,
  body .mf-feed .mf-card.mf-card-reel .mf-media::after{
    display:none !important;
    content:none !important;
  }

  body .mf-feed .mf-card.mf-card-reel .mf-actions{
    display:flex !important;
  }

  body .mf-feed .mf-card.mf-card-live{
    width:100% !important;
    max-width:100% !important;
    background:transparent !important;
    box-shadow:none !important;
    overflow:visible !important;
  }

  body .mf-feed .mf-card.mf-card-live .mf-media{
    width:100% !important;
    max-width:100% !important;
    margin:0 auto !important;
    background:transparent !important;
  }

  body .mf-feed .mf-head:not(.mf-head--on-media){
    display:flex !important;
    align-items:center !important;
    gap:12px !important;
    padding:2px 6px 14px !important;
    position:relative !important;
  }

  body .mf-feed .mf-peer-link{
    display:flex !important;
    align-items:flex-start !important;
    gap:8px !important;
    min-width:0 !important;
    flex:1 1 auto !important;
    text-decoration:none !important;
    padding-right:0 !important;
  }

  body .mf-feed .mf-media-shell > .mf-head--on-media .mf-peer-link{
    align-items:center !important;
    margin-top:0 !important;
    padding-right:44px !important;
    box-sizing:border-box !important;
  }

  body .mf-feed .mf-media-shell > .mf-head--on-media .mf-meta{
    display:flex !important;
    flex-direction:column !important;
    justify-content:center !important;
    min-height:44px !important;
    margin-top:0 !important;
  }

  body .mf-feed .mf-media-shell > .mf-head--on-media > .post-card-menu-wrap,
  body .mf-feed .mf-media-shell > .mf-head--on-media > .mf-menu-wrap.post-card-menu-wrap{
    position:absolute !important;
    top:var(--pcm-on-media-menu-top, 18px) !important;
    right:var(--pcm-on-media-menu-right, 10px) !important;
    margin:0 !important;
    transform:none !important;
    align-self:auto !important;
    z-index:61 !important;
  }

  body .mf-feed .mf-avatar{
    width:44px !important;
    height:44px !important;
    flex:0 0 44px !important;
    display:flex !important;
    align-items:center !important;
    justify-content:center !important;
    overflow:hidden !important;
    border-radius:999px !important;
    padding:2px !important;
    background:linear-gradient(135deg, #0ea5e9 0%, #2563eb 58%, #f8fafc 100%) !important;
  }

  body .mf-feed .mf-avatar img{
    width:100% !important;
    height:100% !important;
    display:block !important;
    object-fit:cover !important;
    border-radius:50% !important;
    border:2px solid var(--feed-surface) !important;
    background:var(--feed-surface) !important;
  }

  body .mf-feed .mf-meta{
    min-width:0 !important;
    flex:1 1 auto !important;
    margin-left:-5px !important;
    margin-top: 15px;
  }

  body .mf-feed .mf-name-row{
    display:flex !important;
    align-items:center !important;
    gap:5px !important;
    min-width:0 !important;
    flex-wrap:nowrap !important;
    padding-right:4px !important;
    margin-left:0 !important;
  }

  body .mf-feed .mf-media-shell > .mf-head--on-media .mf-name-row{
    gap:3px !important;
    width:auto !important;
    justify-content:flex-start !important;
  }

  body .mf-feed .mf-media-shell > .mf-head--on-media .mf-name{
    flex:0 1 auto !important;
    max-width:calc(100% - 64px) !important;
  }

  body .mf-feed .mf-media-shell > .mf-head--on-media .mf-dot,
  body .mf-feed .mf-media-shell > .mf-head--on-media .mf-time{
    margin-left:0 !important;
  }

  body .mf-feed .mf-name{
    font-size:13px !important;
    line-height:1.2 !important;
    font-weight:700 !important;
    color:var(--feed-text) !important;
    margin:0 !important;
    min-width:0 !important;
    flex:1 1 auto !important;
    white-space:nowrap !important;
    overflow:hidden !important;
    text-overflow:ellipsis !important;
  }

  body .mf-feed .mf-media-shell > .mf-head--on-media .mf-name,
  body .mf-feed .mf-media-shell > .mf-head--on-media .mf-time,
  body .mf-feed .mf-media-shell > .mf-head--on-media .mf-dot,
  body .mf-feed .mf-media-shell > .mf-head--on-media .mf-music-row,
  body .mf-feed .mf-media-shell > .mf-head--on-media .mf-music-ic,
  body .mf-feed .mf-media-shell > .mf-head--on-media .mf-music-title,
  body .mf-feed .mf-media-shell > .mf-head--on-media .mf-music-artist,
  body .mf-feed .mf-media-shell > .mf-head--on-media .mf-music-dot{
    color:#fff !important;
    text-shadow:0 2px 10px rgba(0,0,0,.34) !important;
  }

  body .mf-feed .mf-media-shell > .mf-head--on-media .mf-avatar img{
    border-color:#fff !important;
    background:transparent !important;
  }

  body .mf-feed .mf-verified{
    color:#1d9bf0 !important;
    font-size:12px !important;
    line-height:1 !important;
    flex:0 0 auto !important;
  }

  body .mf-feed .mf-dot{
    color:#98a2b3 !important;
    font-size:12px !important;
    line-height:1 !important;
    margin-left: -5px;
    flex:0 0 auto !important;
  }

  body .mf-feed .mf-media-shell > .mf-head--on-media .mf-dot{
    color:#fff !important;
    text-shadow:0 2px 10px rgba(0,0,0,.34) !important;
  }

  body .mf-feed .mf-time{
    color:var(--feed-muted) !important;
    font-size:12px !important;
    line-height:1.2 !important;
    font-weight:500 !important;
    margin-left: -5px;
    flex:0 0 auto !important;
    white-space:nowrap !important;
  }

  body .mf-feed .mf-music-row{
    font-size:11px !important;
    line-height:1.2 !important;
    font-weight:500 !important;
    margin-top:1px !important;
    margin-left:0 !important;
    padding-left:0 !important;
    gap:4px !important;
    max-width:100% !important;
    overflow:hidden !important;
  }

  body .mf-feed .mf-music-ic{
    font-size:10px !important;
  }

  body .mf-feed .mf-music-title{
    flex:1 1 auto !important;
    min-width:0 !important;
  }

  body .mf-feed .mf-music-artist{
    flex:0 1 auto !important;
    min-width:0 !important;
    max-width:46% !important;
  }

  body .mf-feed .mf-music-dot{
    font-size:11px !important;
  }

  body .mf-feed .mf-media-shell > .mf-head--on-media .mf-time{
    color:#fff !important;
    text-shadow:0 2px 10px rgba(0,0,0,.34) !important;
    flex:0 0 auto !important;
    white-space:nowrap !important;
  }

  body .mf-feed .mf-friend-btn:not(.mf-media-follow-btn){
    margin-left:0 !important;
    margin-right:4px !important;
    padding:8px 12px !important;
    font-size:12px !important;
  }

  body .mf-feed .mf-card:not([data-is-publisher="1"]):not([data-account-kind="publisher"]) .mf-media-shell > .mf-media-top-actions{
    position:absolute !important;
    top:12px !important;
    right:calc(14px + var(--pcm-on-media-circle-size, 36px) + 8px) !important;
    z-index:25 !important;
    display:flex !important;
    align-items:center !important;
    gap:8px !important;
    pointer-events:none !important;
  }

  body .mf-feed .mf-card:not([data-is-publisher="1"]):not([data-account-kind="publisher"]) .mf-media-shell > .mf-media-top-actions .mf-friend-btn:not(.mf-media-action-circle){
    display:inline-flex !important;
    align-items:center !important;
    justify-content:center !important;
    pointer-events:auto !important;
    margin:0 !important;
    padding:8px 12px !important;
    font-size:12px !important;
    box-shadow:0 4px 14px rgba(15,23,42,.24) !important;
  }

  body .mf-feed .mf-media-shell > .mf-media-top-actions .mf-friend-btn.is-friends,
  body .mf-feed .mf-head .mf-friend-btn.is-friends,
  body .mf-feed .mf-media-shell > .mf-media-top-actions .mf-friend-btn.mf-publisher-follow.is-friends,
  body .mf-feed .mf-head .mf-friend-btn.mf-publisher-follow.is-friends{
    display:none !important;
  }

  body .mf-feed .mf-media-shell > .mf-media-top-actions.is-friend-only-hidden{
    display:none !important;
  }

  body .mf-feed .mf-card.mf-card-reel .mf-media-shell > .mf-media-top-actions{
    top:14px !important;
    right:14px !important;
  }

  body .mf-feed .mf-head .mf-friend-btn.mf-media-follow-btn{
    display:none !important;
  }

  body .mf-feed .mf-card:has(.mf-media-shell) .mf-head .mf-friend-btn{
    display:none !important;
  }

  body .mf-feed .mf-head:not(.mf-head--on-media) .mf-menu-wrap,
  body .mf-feed .mf-head:not(.mf-head--on-media) .post-card-menu-wrap{
    position:relative !important;
    display:flex !important;
    align-items:center !important;
    right:auto !important;
    top:auto !important;
    transform:none !important;
    margin-left:auto !important;
    margin-right:0 !important;
    flex:0 0 auto !important;
    z-index:60 !important;
    margin-top:0 !important;
  }

  body .mf-feed .mf-card[data-is-publisher="1"] .mf-media-shell > .mf-head--on-media > .post-card-menu-wrap,
  body .mf-feed .mf-card[data-is-publisher="1"] .mf-media-shell > .mf-head--on-media > .mf-menu-wrap.post-card-menu-wrap,
  body .mf-feed .mf-card[data-account-kind="publisher"] .mf-media-shell > .mf-head--on-media > .post-card-menu-wrap,
  body .mf-feed .mf-card[data-account-kind="publisher"] .mf-media-shell > .mf-head--on-media > .mf-menu-wrap.post-card-menu-wrap{
    position:absolute !important;
    top:var(--pcm-on-media-menu-top, 18px) !important;
    right:var(--pcm-on-media-menu-right, 10px) !important;
    margin:0 !important;
    transform:none !important;
    flex:0 0 auto !important;
    width:auto !important;
    z-index:61 !important;
  }

  body .mf-feed .mf-card[data-is-publisher="1"],
  body .mf-feed .mf-card[data-account-kind="publisher"]{
    overflow:visible !important;
  }

  body .mf-feed .mf-card[data-is-publisher="1"] .mf-media-shell,
  body .mf-feed .mf-card[data-account-kind="publisher"] .mf-media-shell{
    overflow:visible !important;
  }

  body .mf-feed .mf-menu-btn:not(.post-card-menu-btn){
    width:34px !important;
    height:34px !important;
    display:flex !important;
    align-items:center !important;
    justify-content:center !important;
    border:0 !important;
    border-radius:999px !important;
    background:transparent !important;
    color:var(--feed-text) !important;
    cursor:pointer !important;
  }

  body .mf-feed .mf-menu-btn:not(.post-card-menu-btn) i{
    font-size:18px !important;
  }

  body .mf-feed .mf-title{
    padding:0 6px 12px !important;
    font-size:20px !important;
    line-height:1.28 !important;
    font-weight:800 !important;
    color:var(--feed-text) !important;
  }

  body .mf-feed .mf-body,
  body .mf-feed .mf-video-body{
    padding:2px 6px 0 !important;
    font-size:15px !important;
    line-height:1.7 !important;
    color:var(--feed-text) !important;
    text-align:left !important;
  }
  body .mf-feed .mf-body .mf-body-formatted,
  body .mf-feed .mf-body .post-card-paragraph{
    text-align:left !important;
  }
  body .mf-feed .mf-body .post-card-paragraph{
    margin:0 0 12px !important;
    display:block !important;
  }
  body .mf-feed .mf-body .post-card-paragraph:last-child{
    margin-bottom:0 !important;
  }
  body .mf-feed .mf-body-formatted.is-clamped{
    max-height:14em !important;
    overflow:hidden !important;
  }

  body .mf-feed .mf-file{
    padding:14px 6px !important;
  }

  body .mf-feed .mf-actions{
    display:flex !important;
    align-items:center !important;
    justify-content:space-between !important;
    gap:10px !important;
    padding:14px 4px 2px !important;
    margin-top:4px !important;
    border-top:0 !important;
  }

  body .mf-feed .mf-actions .mf-left,
  body .mf-feed .mf-actions .mf-right{
    display:flex !important;
    align-items:center !important;
  }

  body .mf-feed .mf-actions .mf-left{
    gap:20px !important;
  }

  body .mf-feed .mf-actions .mf-right{
    margin-left:auto !important;
  }

  body .mf-feed .mf-act{
    display:inline-flex !important;
    align-items:center !important;
    gap:8px !important;
    min-width:0 !important;
    padding:0 !important;
    border:0 !important;
    background:transparent !important;
    color:var(--feed-text) !important;
  }

  body .mf-feed .mf-act i{
    font-size:20px !important;
    line-height:1 !important;
  }

  body .mf-feed .mf-act .mf-num{
    color:var(--feed-text) !important;
    font-size:13px !important;
    font-weight:600 !important;
    line-height:1 !important;
  }

  body .mf-feed .mf-act.mf-save .mf-num,
  body .mf-feed .mf-act.mf-share .mf-num{
    display:none !important;
  }

  body .mf-feed .mf-friend-btn:not(.mf-media-follow-btn){
    display:none !important;
  }

  /* Post card 3-dot menu: use shared post_card_actions_menu.css (same as public.php) */
  body .mf-feed .mf-menu:not(.post-card-menu){
    display:none !important;
    position:absolute !important;
    top:38px !important;
    right:0 !important;
    min-width:170px !important;
    padding:6px !important;
    z-index:50 !important;
    border-radius:12px !important;
    box-shadow:0 12px 32px rgba(15,23,42,.12) !important;
  }

  body .mf-feed .mf-menu:not(.post-card-menu).open{
    display:block !important;
  }

  body .mf-feed .mf-more-badge{ display:none !important; }
}
</style>

<style id="feed-media-head-overlay-css">
/* Match profile.php Posts tab — grid overlay, no header bar */
.mf-feed .mf-media-shell:has(> .mf-head--on-media){
  display:grid!important;
  grid-template:1fr / 1fr!important;
  background:transparent!important;
}
.mf-feed .mf-media-shell:has(> .mf-head--on-media) > .mf-media,
.mf-feed .mf-media-shell:has(> .mf-head--on-media) > .media-stage,
.mf-feed .mf-media-shell:has(> .mf-head--on-media) > .mf-head--on-media,
.mf-feed .mf-media-shell:has(> .mf-head--on-media) > .mf-media-top-actions{
  grid-area:1 / 1!important;
}
.mf-feed .mf-media-shell:has(> .mf-head--on-media) > .mf-media,
.mf-feed .mf-media-shell:has(> .mf-head--on-media) > .media-stage{
  width:100%!important;
  max-width:100%!important;
  margin:0!important;
  padding:0!important;
  background:transparent!important;
  background-color:transparent!important;
}
.mf-feed .mf-card:has(.mf-head--on-media){
  padding:8px 40px!important;
  box-sizing:border-box!important;
}
.mf-feed .mf-card:has(.mf-head--on-media) > .mf-actions{
  padding:10px 0 8px!important;
}
.mf-feed .mf-card:has(.mf-head--on-media) .media-stage.standard-video-stage,
.mf-feed .mf-card:has(.mf-head--on-media) .media-stage.standard-image-stage,
.mf-feed .mf-card:has(.mf-head--on-media) .media-stage.phone-shot{
  padding:0!important;
}
.mf-feed .mf-card:has(.mf-head--on-media) .media-stage.standard-video-stage > video,
.mf-feed .mf-card:has(.mf-head--on-media) .media-stage.standard-image-stage > img,
.mf-feed .mf-card:has(.mf-head--on-media) video.ig-smart-feed-video{
  width:100%!important;
  height:auto!important;
  max-height:min(78svh, 960px)!important;
  object-fit:contain!important;
  object-position:center center!important;
  display:block!important;
  background:transparent!important;
  background-color:transparent!important;
}
.mf-feed .mf-media-shell > .mf-head--on-media{
  position:relative!important;
  align-self:start!important;
  justify-self:stretch!important;
  z-index:25!important;
  display:flex!important;
  align-items:center!important;
  gap:12px!important;
  padding:22px 14px 12px!important;
  box-sizing:border-box!important;
  width:100%!important;
  pointer-events:none!important;
  background:transparent!important;
  background-color:transparent!important;
  margin:0!important;
  border:0!important;
  box-shadow:none!important;
}
.mf-feed .mf-media-shell > .mf-head--on-media .mf-peer-link,
.mf-feed .mf-media-shell > .mf-head--on-media .mf-meta{
  pointer-events:auto!important;
  background:transparent!important;
  z-index:60!important;
  position:relative!important;
  margin-top:0!important;
}
.mf-feed .mf-media-shell > .mf-head--on-media .mf-peer-link{
  align-items:center!important;
  gap:8px!important;
  flex:1 1 auto!important;
  min-width:0!important;
  max-width:100%!important;
  padding-right:44px!important;
  box-sizing:border-box!important;
}
.mf-feed .mf-media-shell > .mf-head--on-media .mf-name-row{
  padding-right:0!important;
  max-width:100%!important;
  gap:3px!important;
  width:auto!important;
  justify-content:flex-start!important;
}
.mf-feed .mf-media-shell > .mf-head--on-media .mf-name{
  flex:0 1 auto!important;
  min-width:0!important;
  max-width:calc(100% - 64px)!important;
}
.mf-feed .mf-media-shell > .mf-head--on-media .mf-dot{
  margin-left:0!important;
  flex:0 0 auto!important;
}
.mf-feed .mf-media-shell > .mf-head--on-media .mf-time{
  flex:0 0 auto!important;
  white-space:nowrap!important;
  margin-left:0!important;
}
.mf-feed .mf-media-shell > .mf-head--on-media .mf-music-row{
  width:auto!important;
  max-width:100%!important;
  align-self:flex-start!important;
  justify-content:flex-start!important;
}
.mf-feed .mf-media-shell > .mf-head--on-media .mf-music-title{
  flex:0 1 auto!important;
}
.mf-feed .mf-media-shell > .mf-head--on-media .mf-music-artist{
  flex:0 1 auto!important;
  max-width:none!important;
}
.mf-feed .mf-media-shell > .mf-head--on-media .mf-meta{
  margin-left:-5px!important;
  display:flex!important;
  flex-direction:column!important;
  justify-content:center!important;
  min-height:44px!important;
}
.mf-feed .mf-media-shell > .mf-head--on-media > .post-card-menu-wrap,
.mf-feed .mf-media-shell > .mf-head--on-media > .mf-menu-wrap.post-card-menu-wrap{
  position:absolute!important;
  top:var(--pcm-on-media-menu-top, 18px)!important;
  right:var(--pcm-on-media-menu-right, 10px)!important;
  margin:0!important;
  transform:none!important;
  align-self:auto!important;
  z-index:61!important;
}
.mf-feed .mf-card[data-is-publisher="1"],
.mf-feed .mf-card[data-account-kind="publisher"]{
  overflow:visible!important;
}
.mf-feed .mf-card[data-is-publisher="1"] .mf-media-shell,
.mf-feed .mf-card[data-account-kind="publisher"] .mf-media-shell{
  overflow:visible!important;
}
.mf-feed .mf-media-shell > .mf-head--on-media .mf-name,
.mf-feed .mf-media-shell > .mf-head--on-media .mf-time,
.mf-feed .mf-media-shell > .mf-head--on-media .mf-dot,
.mf-feed .mf-media-shell > .mf-head--on-media .mf-menu-btn:not(.post-card-menu-btn),
.mf-feed .mf-media-shell > .mf-head--on-media .mf-menu-btn:not(.post-card-menu-btn) i{
  color:#fff!important;
  text-shadow:0 2px 10px rgba(0,0,0,.34)!important;
}
.mf-feed .mf-media-shell > .mf-head--on-media .mf-avatar img{
  border-color:#fff!important;
}
.mf-feed .mf-card .mf-media-shell:has(.mf-head--on-media) > .mf-media-top-actions{
  align-self:start!important;
  justify-self:end!important;
  position:relative!important;
  top:12px!important;
  right:calc(14px + var(--pcm-on-media-circle-size, 36px) + 8px)!important;
  z-index:40!important;
}
.mf-feed .mf-card .mf-media-shell:has(.mf-head--on-media) > .mf-media-top-actions .mf-friend-btn,
.mf-feed .mf-card .mf-media-shell:has(.mf-head--on-media) > .mf-media-top-actions .mf-media-action-circle,
.mf-feed .mf-card .mf-media-shell:has(.mf-head--on-media) > .mf-media-top-actions .mf-publisher-follow-circle{
  pointer-events:auto!important;
}
</style>
<?php post_card_actions_menu_render_css(); ?>
<?php post_action_thin_icons_render_css(); ?>
<?php post_card_actions_menu_render_js([
  'delete_mode' => 'feed',
  'api_url' => 'feed_api.php',
  'staff_readonly' => $staffReadonly,
  'menu_surface' => 'feed',
  'always_portal' => true,
]); ?>
<style id="feed-post-card-menu-css">
/* feed.php — post card 3-dot menu dropdown (match public.php behavior) */
body .mf-feed .mf-head:not(.mf-head--on-media){
  position:relative;
}
body .mf-feed .post-card-menu-wrap,
body .mf-feed .mf-menu-wrap.post-card-menu-wrap{
  position:relative;
  z-index:80;
}
body .mf-feed .mf-head:not(.mf-head--on-media) .post-card-menu-btn{
  width:auto!important;
  height:auto!important;
  min-width:var(--pcm-menu-btn-size, 28px)!important;
  min-height:var(--pcm-menu-btn-size, 28px)!important;
  padding:6px 4px!important;
  flex:0 0 auto!important;
  border:0!important;
  border-radius:0!important;
  background:transparent!important;
  color:var(--msb-palette-text, #5c3d2e)!important;
  display:inline-flex!important;
  align-items:center!important;
  justify-content:center!important;
  box-shadow:none!important;
  line-height:1!important;
}
body .mf-feed .mf-head:not(.mf-head--on-media) .post-card-menu-btn i,
body .mf-feed .mf-head:not(.mf-head--on-media) .post-card-menu-btn .pcm-fries-icon{
  font-size:16px!important;
  line-height:1!important;
  color:inherit!important;
  text-shadow:none!important;
}
body .mf-feed .mf-head:not(.mf-head--on-media) .post-card-menu-btn:hover,
body .mf-feed .mf-head:not(.mf-head--on-media) .post-card-menu-btn:focus{
  background:transparent!important;
  outline:none!important;
  box-shadow:none!important;
  opacity:.72!important;
}
body .mf-feed .mf-head:has(.post-card-menu.open),
body .mf-feed .mf-head:has(.pcm-wrap-open){
  overflow:visible;
  z-index:90;
}
body .mf-feed .post-card-menu.open:not(.pcm-menu-portal),
body .mf-feed .mf-menu.post-card-menu.open:not(.pcm-menu-portal){
  display:block !important;
  z-index:120 !important;
  pointer-events:auto !important;
}
body .mf-feed .pcm-menu-portal.open{
  z-index:100000 !important;
  pointer-events:auto !important;
}
</style>
<style id="feed-publisher-menu-offset-css">
/* feed.php — on-media header padding (match profile.php) */
body .mf-feed .mf-card .mf-media-shell > .mf-head--on-media{
  padding:22px 14px 12px !important;
}
body .mf-feed .mf-card .mf-media-shell > .mf-head--on-media > .post-card-menu-wrap,
body .mf-feed .mf-card .mf-media-shell > .mf-head--on-media > .mf-menu-wrap.post-card-menu-wrap{
  margin-right:0 !important;
  margin-left:auto !important;
  position:absolute !important;
  top:var(--pcm-on-media-menu-top, 18px) !important;
  right:var(--pcm-on-media-menu-right, 10px) !important;
  margin:0 !important;
  z-index:61 !important;
}
html[data-msb-appearance] body .mf-feed .mf-card .mf-media-shell > .mf-head--on-media{
  padding:22px 14px 12px !important;
}
</style>

<style id="feed-post-media-stage-css">
<?= post_media_stage_css('body .mf-feed') ?>
</style>

<style id="feed-post-media-radius-override">
  body .mf-feed{
    --post-media-radius:18px;
  }
  body .mf-feed .media-stage.standard-video-stage,
  body .mf-feed .media-stage.standard-image-stage,
  body .mf-feed .media-stage{
    overflow:hidden !important;
    border-radius:var(--post-media-radius, 18px) !important;
  }
  body .mf-feed .media-stage.standard-video-stage > video,
  body .mf-feed .media-stage.standard-image-stage > img,
  body .mf-feed video.ig-smart-feed-video{
    border-radius:var(--post-media-radius, 18px) !important;
  }
</style>

<style id="live-post-card-css-feed">
<?= live_post_card_css() ?>
</style>

<script>
// Video thumbnail start
document.querySelectorAll('video.ig-vid').forEach(v => { try { v.currentTime = 0.1; } catch(e){} });

/* Prevent navigating when clicking react UI */
document.addEventListener('click', (e) => {
  if (e.target.closest('.react-btn') || e.target.closest('.react-close')) {
    e.preventDefault();
    e.stopPropagation();
  }
});

// ----------------------------
// ✅ Profile grid -> open post modal (NO post_view.php)
// Re-uses your feed_api.php endpoints (view/react/share/save/comment)
// ----------------------------

function pvIds(){
  return Array.from(document.querySelectorAll('.pl-item[data-id]'))
    .map(el=>parseInt(el.getAttribute('data-id')||'0',10))
    .filter(n=>n>0);
}

let pvIndex = -1;
let pvPostId = 0;
let pvReplyTo = 0;
let pvReplyToName = '';
let pvCommentFocusId = 0;
let pvCommentsCache = [];
const pvCollapsedReplyIds = new Set();
const pvMaxReplyCurveDepth = 4;
let pvReplyToMode = 'Reply';
let pvCurrentReaction = '';
function pvReplyToggleLabel(count, isOpen){
  const noun = count === 1 ? 'reply' : 'replies';
  return isOpen ? 'Close replies' : ('Open ' + count + ' ' + noun);
}
function pvReplyActionLabel(depth){
  return depth >= pvMaxReplyCurveDepth ? 'Comment' : 'Reply';
}


// ✅ Reliable viewport height on mobile (fixes keyboard/VH issues)
function pvSetVh(){
  try{
    const vh = (window.innerHeight || document.documentElement.clientHeight || 0) * 0.01;
    document.documentElement.style.setProperty('--vh', vh + 'px');
  }catch(e){}
}
pvSetVh();
window.addEventListener('resize', pvSetVh, {passive:true});
window.addEventListener('orientationchange', () => setTimeout(pvSetVh, 120), {passive:true});

let pvScrollY = 0;
function pvLockBodyScroll(){
  try{
    pvScrollY = window.scrollY || document.documentElement.scrollTop || 0;
    document.body.classList.add('pv-body-lock');
    // iOS: position fixed prevents background scroll + "scroll freeze"
    document.body.style.position = 'fixed';
    document.body.style.top = (-pvScrollY) + 'px';
    document.body.style.left = '0';
    document.body.style.right = '0';
    document.body.style.width = '100%';
  }catch(e){}
}
function pvUnlockBodyScroll(){
  try{
    document.body.classList.remove('pv-body-lock');
    const top = document.body.style.top;
    document.body.style.position = '';
    document.body.style.top = '';
    document.body.style.left = '';
    document.body.style.right = '';
    document.body.style.width = '';
    const y = top ? Math.abs(parseInt(top, 10)) : (pvScrollY||0);
    window.scrollTo(0, y);
  }catch(e){}
}

const pv = (() => {
  const ov = document.getElementById('pvOverlay');
  return {
    ov,
    media: ov ? ov.querySelector('#pvMedia') : null,
    body: ov ? ov.querySelector('#pvBody') : null,
    caption: ov ? ov.querySelector('#pvCaption') : null,
    comments: ov ? ov.querySelector('#pvComments') : null,
    avatar: ov ? ov.querySelector('#pvAvatar') : null,
    name: ov ? ov.querySelector('#pvName') : null,
    meta: ov ? ov.querySelector('#pvMeta') : null,
    love: ov ? ov.querySelector('#pvLove') : null,
    like: ov ? ov.querySelector('#pvLike') : null,
    share: ov ? ov.querySelector('#pvShare') : null,
    save: ov ? ov.querySelector('#pvSave') : null,
    focusComment: ov ? ov.querySelector('#pvComment') : null,
    text: ov ? ov.querySelector('#pvText') : null,
    postBtn: ov ? ov.querySelector('#pvPostBtn') : null,
    loveN: ov ? ov.querySelector('#pvLoveN') : null,
    likeN: ov ? ov.querySelector('#pvLikeN') : null,
    comN: ov ? ov.querySelector('#pvComN') : null,
    shareN: ov ? ov.querySelector('#pvShareN') : null,
    saveN: ov ? ov.querySelector('#pvSaveN') : null,
    viewN: ov ? ov.querySelector('#pvViewN') : null,
    replyBar: ov ? ov.querySelector('#pvReplyBar') : null,
    replyLead: ov ? ov.querySelector('#pvReplyLead') : null,
    replyName: ov ? ov.querySelector('#pvReplyName') : null,
    replyCancel: ov ? ov.querySelector('#pvReplyCancel') : null,
    close: ov ? ov.querySelector('#pvClose') : null,
    prev: ov ? ov.querySelector('#pvPrev') : null,
    next: ov ? ov.querySelector('#pvNext') : null,
    left: ov ? ov.querySelector('.pv-left') : null,
  };
})();

// ✅ Mobile/tablet: when keyboard opens, keep input visible
if (pv.text) {
  pv.text.addEventListener('focus', () => {
    pvSetVh();
    setTimeout(() => {
      try {
        (pv.postBtn || pv.text).scrollIntoView({ block:'end', behavior:'smooth' });
        if (pv.body) pv.body.scrollTop = pv.body.scrollHeight;
      } catch(e) {}
    }, 180);
  });
  pv.text.addEventListener('blur', () => setTimeout(pvSetVh, 80));
}

function pvEsc(s){
  return (s ?? '').toString()
    .replaceAll('&','&amp;')
    .replaceAll('<','&lt;')
    .replaceAll('>','&gt;')
    .replaceAll('"','&quot;')
    .replaceAll("'",'&#039;');
}

function pvAvatarUrlFor(it, size){
  try{
    if (typeof avatarUrlFor === 'function') return avatarUrlFor(it || {}, size || 96);
  }catch(e){}
  it = it || {};
  size = Number(size || 96);
  var params = [];
  var uid = Number(it.user_id || it.id || 0);
  var email = String(it.email || '').trim();
  var fc = String(it.friend_code || '').trim();
  var un = String(it.username || '').trim();
  var nm = String(it.display_name || it.name || un || 'User').trim();
  if(uid > 0) params.push('u=' + encodeURIComponent(String(uid)));
  if(email) params.push('email=' + encodeURIComponent(email));
  if(fc) params.push('friend_code=' + encodeURIComponent(fc));
  if(un) params.push('username=' + encodeURIComponent(un));
  if(nm) params.push('name=' + encodeURIComponent(nm));
  params.push('s=' + encodeURIComponent(String(size)));
  return 'avatar.php?' + params.join('&');
}


function pvFormatRichText(text){
  const src = String(text == null ? '' : text).replace(/\r\n?/g, '\n').trim();
  if (!src) return '';

  const lines = src.split('\n');
  const out = [];
  let para = [];
  let listStack = [];

  function escLine(s){ return pvEsc(s).replace(/  /g, ' &nbsp;'); }
  function lineIndent(line){ const m = String(line || '').match(/^(\s*)/); return m ? m[1].replace(/\t/g, '    ').length : 0; }
  function listInfo(line){
    const raw = String(line || '');
    const bullet = raw.match(/^(\s*)([-*•◦▪‣])\s+(.*)$/);
    if (bullet) return { type:'ul', indent: Math.floor((bullet[1] || '').replace(/\t/g, '    ').length / 2), text: bullet[3] || '' };
    const ordered = raw.match(/^(\s*)((?:\d+|[A-Za-z]|[ivxlcdmIVXLCDM]+)[\.)])\s+(.*)$/);
    if (ordered) return { type:'ol', indent: Math.floor((ordered[1] || '').replace(/\t/g, '    ').length / 2), marker: ordered[2] || '', text: ordered[3] || '' };
    return null;
  }
  function flushPara(){
    if (!para.length) return;
    out.push('<p class="pv-rich-p">' + para.map(escLine).join('<br>') + '</p>');
    para = [];
  }
  function closeLists(toLevel){
    while (listStack.length > toLevel) {
      out.push('</li></' + listStack.pop() + '>');
    }
  }
  function openList(type){
    out.push('<' + type + ' class="pv-rich-list ' + (type === 'ol' ? 'is-ordered' : 'is-bullet') + '"><li class="pv-rich-li">');
    listStack.push(type);
  }

  lines.forEach(function(line){
    const raw = String(line || '');
    const trimmed = raw.trim();
    const info = listInfo(raw);
    if (!trimmed) {
      flushPara();
      closeLists(0);
      return;
    }
    if (info) {
      flushPara();
      const targetLevel = Math.max(0, info.indent + 1);
      while (listStack.length < targetLevel) openList(info.type);
      while (listStack.length > targetLevel) out.push('</li></' + listStack.pop() + '>');
      if (listStack.length && listStack[listStack.length - 1] !== info.type) {
        out.push('</li></' + listStack.pop() + '>');
        openList(info.type);
      } else if (listStack.length) {
        out.push('</li><li class="pv-rich-li">');
      }
      out.push('<span class="pv-rich-line">' + escLine(info.text) + '</span>');
    } else {
      if (listStack.length) closeLists(0);
      para.push(raw);
    }
  });

  flushPara();
  closeLists(0);
  return '<div class="pv-richtext">' + out.join('') + '</div>';
}

function pvTruncateText(s, limit){
  const txt = (s ?? '').toString().trim();
  const lim = Math.max(20, Number(limit || 160));
  if (!txt) return { short:'', full:'', truncated:false };
  if (txt.length <= lim) return { short:txt, full:txt, truncated:false };
  // ✅ Keep as "incomplete sentence" (hard cut) + add Read more
  const short = txt.slice(0, lim).trimEnd();
  return { short, full:txt, truncated:true };
}


function pvTimeAgo(ts){
  const t = Date.parse(ts || '');
  if (!t) return '';
  const sec = Math.floor((Date.now() - t)/1000);
  if (sec < 60) return sec + 's';
  const m = Math.floor(sec/60); if (m < 60) return m + 'm';
  const h = Math.floor(m/60); if (h < 24) return h + 'h';
  const d = Math.floor(h/24); if (d < 7) return d + 'd';
  const w = Math.floor(d/7); if (w < 4) return w + 'w';
  const mo = Math.floor(d/30); if (mo < 12) return mo + 'mo';
  const y = Math.floor(d/365); return y + 'y';
}

function pvSetReply(parentId, displayName, mode){
  pvReplyTo = parentId || 0;
  pvReplyToName = displayName || '';
  pvReplyToMode = String(mode || 'Reply');
  const isCommentMode = pvReplyToMode === 'Comment';
  if (pvReplyTo > 0) {
    if (pv.replyLead) pv.replyLead.textContent = isCommentMode ? 'Commenting on' : 'Replying to';
    pv.replyName.textContent = pvReplyToName || '—';
    pv.replyBar.style.display = '';
    if (pv.text) pv.text.placeholder = (isCommentMode ? 'Comment on ' : 'Reply to ') + (pvReplyToName || 'comment');
  } else {
    pv.replyBar.style.display = 'none';
    if (pv.replyLead) pv.replyLead.textContent = 'Replying to';
    if (pv.text) pv.text.placeholder = 'Add a comment…';
  }
}

function pvUpdateNavBtns(){
  pv.prev.style.display = (pvIndex > 0) ? '' : 'none';
  pv.next.style.display = (pvIndex >= 0 && pvIndex < pvIds().length - 1) ? '' : 'none';
}

async function pvJson(url, opts){
  const res = await fetch(url, opts);
  const data = await res.json().catch(()=>null);
  if (!data || data.ok === false) {
    const msg = (data && data.error) ? data.error : 'Request failed';
    throw new Error(msg);
  }
  return data;
}

function pvOpenByIndex(idx){
  if (!true || pvIds().length === 0) return;
  if (idx < 0) idx = 0;
  if (idx >= pvIds().length) idx = pvIds().length - 1;
  pvIndex = idx;
  pvPostId = Number((pvIds()[pvIndex]) || 0);
  if (!pvPostId) return;
  pvCollapsedReplyIds.clear();
  pvCommentsCache = [];
  pvCommentFocusId = 0;
  pvSetReply(0, '');
  pvSetVh();
  pv.ov.classList.add('show');
  pv.ov.setAttribute('aria-hidden', 'false');
  pvLockBodyScroll();
  pvLoad(pvPostId);
  pvUpdateNavBtns();
  pvPreloadNeighbors();
}

function pvClose(){
  pv.ov.classList.remove('show');
  pv.ov.setAttribute('aria-hidden', 'true');
  pvUnlockBodyScroll();
  pvSetVh();
  pv.media.innerHTML = '';
  pv.caption.innerHTML = '';
  pv.caption.style.display = 'none';
  pv.comments.innerHTML = '';
  pvCommentsCache = [];
  pvCollapsedReplyIds.clear();
  pvPostId = 0;
  pvIndex = -1;
  pvCommentFocusId = 0;
  pvSetReply(0, '');
}
function pvRenderCaption(post, atts){
  const title = (post?.title || '').toString().trim();
  const desc  = (post?.description || post?.body || '').toString().trim();
  const hasMedia = Array.isArray(atts) && atts.length > 0;

  // ✅ If there is NO media on the left, we show text on the left only.
  // So the right caption should be hidden to avoid duplicate text.
  if (!hasMedia) {
    pv.caption.style.display = 'none';
    pv.caption.innerHTML = '';
    return;
  }

  // Nothing to show
  if (!title && !desc) {
    pv.caption.style.display = 'none';
    pv.caption.innerHTML = '';
    return;
  }

  pv.caption.style.display = '';

  // ✅ Title always stays at the top (no name beside title)
  const titleHtml = title ? `<div class="pv-cap-title">${pvEsc(title)}</div>` : '';

  // ✅ Description goes under the title (Read more / Show less when long)
  if (!desc) {
    pv.caption.innerHTML = `<div class="pv-cap">${titleHtml}</div>`;
    return;
  }

  const t = pvTruncateText(desc, 170);

  if (!t.truncated) {
    pv.caption.innerHTML = `<div class="pv-cap">${titleHtml}<div class="pv-cap-desc">${pvFormatRichText(t.full)}</div></div>`;
    return;
  }

  pv.caption.innerHTML = `
    <div class="pv-cap" data-expanded="0">
      ${titleHtml}
      <div class="pv-cap-desc">
        <span class="pv-cap-short">${pvFormatRichText(t.short)}<span class="pv-rich-ellipsis">&hellip;</span></span>
        <span class="pv-cap-full" style="display:none;">${pvFormatRichText(t.full)}</span>
        <a href="#" class="pv-readmore">Read more</a>
      </div>
    </div>
  `;
}

function pvRenderMedia(post, atts){
  // Show first attachment if any; otherwise show title/body card
  const title = (post?.title || '').trim();
  const desc  = (post?.description || '').trim();
  const body  = (post?.body || '').trim();

  // ✅ Mobile/tablet: enable LEFT scroll only for "no media + has long text"
  try{
    const hasMedia = Array.isArray(atts) && atts.length > 0;
    const textOnly = !hasMedia && ((desc || body || '').trim() !== '');
    const isSmall  = window.matchMedia && window.matchMedia('(max-width: 900px)').matches;
    if (pv.left) pv.left.classList.toggle('pv-left-scroll', !!(isSmall && textOnly));
  }catch(e){}

  if (Array.isArray(atts) && atts.length > 0) {
    const a = atts[0];
    const type = (a?.type || '').toLowerCase();
    const url  = (a?.url || a?.file_path || '').toString();
    const thumb= (a?.thumb_url || a?.thumb_path || '').toString();

    if (type === 'video' || /\.(mp4|webm|ogg|mov|m4v)(\?.*)?$/i.test(url)) {
      pv.media.innerHTML = `<video src="${pvEsc(url)}" controls playsinline preload="metadata"></video>`;
      return;
    }

    // docs / pdf / pptx etc => iframe (best effort)
    if (type === 'pdf' || /\.(pdf|docx|pptx|doc)(\?.*)?$/i.test(url)) {
      pv.media.innerHTML = `<iframe src="${pvEsc(url)}" style="width:100%;height:100%;border:0;"></iframe>`;
      return;
    }

    // image/gif fallback
    const img = thumb || url;
    pv.media.innerHTML = `<img src="${pvEsc(img)}" alt="" />`;
    return;
  }

  // no attachments
  const t = (title || '').trim();
  const text = (desc || body || '').trim();

  // ✅ Title only (no description/body) => center title in the left panel
  if (t && !text) {
    pv.media.innerHTML = `
      <div class="pv-media-shell">
        <div class="pv-media-copy is-title-only">
          <div style="font-weight:800;font-size:24px;line-height:1.25;white-space:normal;word-break:break-word;">${pvEsc(t)}</div>
        </div>
      </div>
    `;
    return;
  }

  const cut = pvTruncateText(text, 220);
  pv.media.innerHTML = `
    <div class="pv-media-shell">
      <div class="pv-media-copy">
        ${t ? `<div style="font-weight:800;font-size:22px;line-height:1.2;">${pvEsc(t)}</div>` : ``}
        ${text ? `
          <div class="pv-media-text" data-expanded="0" style="margin-top:${t ? '10px' : '0'};">
            ${cut.truncated ? `
              <span class="pv-media-short">${pvFormatRichText(cut.short)}<span class="pv-rich-ellipsis">&hellip;</span></span>
              <span class="pv-media-full" style="display:none;">${pvFormatRichText(cut.full)}</span>
              <a href="#" class="pv-readmore">Read more</a>
            ` : `<span>${pvFormatRichText(cut.full)}</span>`}
          </div>
        ` : ''}
      </div>
    </div>
  `;
}

function pvRenderComments(post, comments){
  const items = Array.isArray(comments) ? comments : [];
  pvCommentsCache = items;
  if (items.length === 0) {
    pv.comments.innerHTML = `<div class="t" style="color:rgba(15,23,42,.55);font-size:13px;padding:14px 4px;">No comments yet.</div>`;
    return;
  }
  const byId = {};
  items.forEach((c) => { byId[Number(c?.id || 0)] = Object.assign({}, c, { _replies: [] }); });
  const roots = [];
  Object.values(byId).forEach((c) => {
    const parentId = Number(c?.parent_id || 0);
    if (parentId > 0 && byId[parentId]) byId[parentId]._replies.push(c);
    else roots.push(c);
  });
  function annotateReplyDepth(node, depth, cappedAncestorId){
    const nextCappedAncestorId = (depth === pvMaxReplyCurveDepth - 1) ? Number(node?.id || 0) : cappedAncestorId;
    node._reply_target_id = (depth >= pvMaxReplyCurveDepth && cappedAncestorId > 0) ? cappedAncestorId : Number(node?.id || 0);
    node._reply_action_label = pvReplyActionLabel(depth);
    node._replies.forEach((child) => annotateReplyDepth(child, depth + 1, nextCappedAncestorId));
  }
  roots.forEach((node) => annotateReplyDepth(node, 0, 0));

  function commentHtml(c, depth){
    const cid = Number(c?.id || 0);
    const nm  = (c?.display_name || c?.username || 'User').toString();
    const txt = (c?.comment_text || '').toString();
    const t   = pvTimeAgo(c?.created_at);
    const ava = pvAvatarUrlFor(c || {}, 72);
    const liked = Number(c?.me_liked || 0) === 1;
    const likeCount = Number(c?.like_count || 0);
    const myReaction = String(c?.my_reaction || '');
    const reactionLabel = (window.MSBReactions && typeof window.MSBReactions.label === 'function')
      ? window.MSBReactions.label(myReaction || 'love')
      : (myReaction ? myReaction : 'Love');
    const kids = Array.isArray(c?._replies) ? c._replies : [];
    const replyCount = kids.length;
    const repliesOpen = !pvCollapsedReplyIds.has(cid);
    const childrenHtml = kids.map((child) => commentHtml(child, depth + 1)).join('');
    const depthClamped = depth > pvMaxReplyCurveDepth;
    const childDepthCapped = (depth + 1) > pvMaxReplyCurveDepth;
    const replyActionLabel = String(c?._reply_action_label || pvReplyActionLabel(depth));
    const replyTargetId = Number(c?._reply_target_id || cid);
    return `
      <div class="pv-node${depth > 0 ? ' is-reply' : ''}${replyCount > 0 ? ' has-children' : ''}${replyCount > 0 && !repliesOpen ? ' is-collapsed' : ''}${depthClamped ? ' is-depth-clamped' : ''}" data-cid="${cid}">
        <div class="pv-com" data-cid="${cid}">
          <div class="a"><img src="${pvEsc(ava)}" alt="" /></div>
          <div class="b">
            <div class="bubble">
              <div class="t"><b>${pvEsc(nm)}</b> ${pvEsc(txt)}</div>
            </div>
            <div class="m">
              <span>${pvEsc(t)}</span>
              <button type="button" class="likebtn ${liked ? 'is-liked' : ''} pv-clike" data-cid="${cid}" data-reaction="${pvEsc(myReaction)}"><i class="fa fa-heart-o"></i><span data-reaction-label>${pvEsc(liked ? reactionLabel : 'Love')}</span></button>
              <button type="button" class="link replies-toggle pv-reply" data-cid="${replyTargetId}" data-name="${pvEsc(nm)}" data-mode="${pvEsc(replyActionLabel)}">${pvEsc(replyActionLabel)}</button>
              ${replyCount > 0 ? `<button type="button" class="link replies-toggle pv-toggle-replies" data-toggle-replies="${cid}">${pvEsc(pvReplyToggleLabel(replyCount, repliesOpen))}</button>` : ``}
              ${likeCount > 0 ? `<span class="pv-likepill"><i class="fa fa-thumbs-up"></i><span>${pvEsc(String(likeCount))}</span></span>` : ``}
            </div>
          </div>
        </div>
        ${replyCount > 0 && repliesOpen ? `<div class="pv-children${childDepthCapped ? ' depth-capped' : ''}">${childrenHtml}</div>` : ``}
      </div>
    `;
  }

  pv.comments.innerHTML = roots.map((c) => commentHtml(c, 0)).join('');
  if(window.MSBReactions){
    pv.comments.querySelectorAll('.pv-clike').forEach((btn) => {
      window.MSBReactions.applyReactionButton(btn, btn.getAttribute('data-reaction') || '', 'love');
    });
  }

  if (pvCommentFocusId > 0) {
    setTimeout(() => { pvFocusCommentById(pvCommentFocusId); }, 0);
  }
}

function pvFocusCommentById(commentId){
  commentId = Number(commentId || 0);
  if (!commentId || !pv.comments) return false;
  pv.comments.querySelectorAll('.pv-com.is-alert-focus').forEach((node) => node.classList.remove('is-alert-focus'));
  const row = pv.comments.querySelector(`.pv-com[data-cid="${commentId}"]`);
  if (!row) return false;
  row.classList.add('is-alert-focus');
  try { row.scrollIntoView({ block:'center', behavior:'smooth' }); } catch (e) {}
  return true;
}

function pvApplyCounts(data){
  const post = data?.post || {};
  const counts = data?.counts || {};

  // avatar/name
  const dn = (post.display_name || post.username || '').toString();
  if (dn) {
    pv.name.textContent = dn;
    pv.avatar.src = pvAvatarUrlFor(post || {}, 96);
  }
  if (post.created_at) {
    pv.meta.textContent = 'Posted ' + pvTimeAgo(post.created_at);
  }

  // like/love
  const loveN = Number(counts.love_count || 0);
  const likeN = Number(counts.like_count || 0);
  pv.loveN.textContent = String(loveN);
  pv.likeN.textContent = String(likeN);

  // my reaction
  const my = (counts.my_reaction || '').toString();
  pvCurrentReaction = my;
  if(window.MSBReactions){
    window.MSBReactions.applyReactionButton(pv.love, my !== 'like' ? my : '', 'love');
    window.MSBReactions.applyLikeButton(pv.like, my === 'like' ? my : '');
  }else{
    pv.love.classList.toggle('is-love', my !== '' && my !== 'like');
    pv.like.classList.toggle('is-like', my === 'like');
  }

  // views
  pv.viewN.textContent = String(Number(post.views_count ?? pv.viewN?.textContent ?? 0));
}

function pvCountMeta(){
  return {
    views_count: Number(pv.viewN?.textContent || 0)
  };
}

async function pvLoad(postId){
  pv.media.innerHTML = `<div style="color:#fff;opacity:.8;">Loading…</div>`;
  pv.caption.style.display = 'none';
  pv.caption.innerHTML = '';
  pv.comments.innerHTML = `<div class="t" style="color:rgba(15,23,42,.55);font-size:13px;padding:14px 4px;">Loading…</div>`;
  try {
    // ✅ count_view=1 so analytics/unique views update when user opens in modal
    const view = await pvJson(`feed_api.php?ajax=view&id=${encodeURIComponent(postId)}&count_view=1`, { credentials:'same-origin' });
    pvRenderMedia(view.post, view.attachments);
    pvRenderCaption(view.post, view.attachments);
    pvRenderComments(view.post, view.comments);
    pvApplyCounts(view);
    pv.comN.textContent = String((Array.isArray(view.comments) ? view.comments.length : 0));

    // share/save counts + my flags
    const tc = await pvJson(`feed_api.php?ajax=track_counts&post_id=${encodeURIComponent(postId)}`, { credentials:'same-origin' });
    pv.shareN.textContent = String(Number(tc.share_count || 0));
    pv.saveN.textContent  = String(Number(tc.save_count || 0));
    pv.share.classList.toggle('is-share', Number(tc.my_shared || 0) === 1);
    pv.save.classList.toggle('is-save', Number(tc.my_saved || 0) === 1);

  } catch (e) {
    pv.media.innerHTML = `<div style="color:#fff;opacity:.85;padding:24px;">Failed to load post.</div>`;
    pv.caption.style.display = 'none';
    pv.caption.innerHTML = '';
    pv.comments.innerHTML = `<div style="color:#b91c1c;font-size:13px;padding:14px 4px;">${pvEsc(e?.message || 'Failed')}</div>`;
  }
}

// ✅ "Read more" toggles inside the modal (caption + no-attachment card)
document.addEventListener('click', (e) => {
  const rm = e.target.closest('.pv-readmore');
  if (!rm) return;
  if (!rm.closest('#pvOverlay')) return;
  e.preventDefault();

  // Caption toggle
  const cap = rm.closest('.pv-cap');
  if (cap && cap.querySelector('.pv-cap-short') && cap.querySelector('.pv-cap-full')) {
    const expanded = cap.getAttribute('data-expanded') === '1';
    cap.setAttribute('data-expanded', expanded ? '0' : '1');
    cap.querySelector('.pv-cap-short').style.display = expanded ? '' : 'none';
    cap.querySelector('.pv-cap-full').style.display  = expanded ? 'none' : '';
    rm.textContent = expanded ? 'Read more' : 'Show less';
    const descBox = cap.querySelector('.pv-cap-desc');
    if (descBox) descBox.scrollTop = 0;
    return;
  }

  // No-attachment card text toggle
  const mt = rm.closest('.pv-media-text');
  if (mt && mt.querySelector('.pv-media-short') && mt.querySelector('.pv-media-full')) {
    const expanded = mt.getAttribute('data-expanded') === '1';
    mt.setAttribute('data-expanded', expanded ? '0' : '1');
    mt.querySelector('.pv-media-short').style.display = expanded ? '' : 'none';
    mt.querySelector('.pv-media-full').style.display  = expanded ? 'none' : '';
    rm.textContent = expanded ? 'Read more' : 'Show less';
    mt.scrollTop = 0;
  }
});

// ✅ Preload neighbor grid tiles (fast next/prev feel)
function pvPreloadTileByIndex(idx){
  try {
    const el = document.querySelector(`.ig-item[data-index="${idx}"]`);
    if (!el) return;
    const ph = el.querySelector('.ph');
    if (ph) {
      const bg = (ph.style.backgroundImage || '').toString();
      const m = bg.match(/url\(["']?(.*?)["']?\)/i);
      const src = m && m[1] ? m[1] : '';
      if (src) { const im = new Image(); im.src = src; }
      return;
    }
    const vid = el.querySelector('video.ig-vid');
    if (vid && vid.getAttribute('src')) {
      const v = document.createElement('video');
      v.preload = 'metadata';
      v.muted = true;
      v.playsInline = true;
      v.src = vid.getAttribute('src');
    }
  } catch(e) {}
}
function pvPreloadNeighbors(){
  if (pvIndex < 0) return;
  pvPreloadTileByIndex(pvIndex + 1);
  pvPreloadTileByIndex(pvIndex - 1);
}

// Grid click
document.querySelectorAll('.ig-grid .ig-item').forEach(a => {
  a.addEventListener('click', (e) => {
    e.preventDefault();
    const idx = Number(a.getAttribute('data-index') || 0);
    pvOpenByIndex(idx);
  });
});

// Close by clicking outside
pv.ov.addEventListener('mousedown', (e) => {
  if (e.target === pv.ov) pvClose();
});
pv.close.addEventListener('click', pvClose);

// Prev/Next
pv.prev.addEventListener('click', () => { if (pvIndex > 0) pvOpenByIndex(pvIndex - 1); });
pv.next.addEventListener('click', () => { if (pvIndex < pvIds().length - 1) pvOpenByIndex(pvIndex + 1); });

// Keyboard
document.addEventListener('keydown', (e) => {
  if (!pv.ov.classList.contains('show')) return;
  if (e.key === 'Escape') { e.preventDefault(); pvClose(); }
  if (e.key === 'ArrowLeft') { e.preventDefault(); if (pvIndex > 0) pvOpenByIndex(pvIndex - 1); }
  if (e.key === 'ArrowRight') { e.preventDefault(); if (pvIndex < pvIds().length - 1) pvOpenByIndex(pvIndex + 1); }
});

// ✅ Mobile swipe (left/right) like Instagram
let pvTouchX = 0;
let pvTouchY = 0;
pv.ov.addEventListener('touchstart', (e) => {
  if (!pv.ov.classList.contains('show')) return;
  // Don't hijack scrolling inside comments
  const t = e.target;
  if (t && t.closest && t.closest('.pv-comments')) return;
  const p = e.changedTouches && e.changedTouches[0];
  if (!p) return;
  pvTouchX = p.screenX;
  pvTouchY = p.screenY;
}, { passive: true });

pv.ov.addEventListener('touchend', (e) => {
  if (!pv.ov.classList.contains('show')) return;
  const t = e.target;
  if (t && t.closest && t.closest('.pv-comments')) return;
  const p = e.changedTouches && e.changedTouches[0];
  if (!p) return;
  const dx = p.screenX - pvTouchX;
  const dy = p.screenY - pvTouchY;
  // require mostly horizontal gesture
  if (Math.abs(dx) < 60 || Math.abs(dx) < Math.abs(dy) * 1.2) return;
  if (dx > 0) { if (pvIndex > 0) pvOpenByIndex(pvIndex - 1); }
  else { if (pvIndex < pvIds().length - 1) pvOpenByIndex(pvIndex + 1); }
}, { passive: true });

// Reply click
pv.comments.addEventListener('click', (e) => {
  const toggleBtn = e.target.closest('.pv-toggle-replies');
  if (toggleBtn) {
    const cid = Number(toggleBtn.getAttribute('data-toggle-replies') || 0);
    if (!cid) return;
    if (pvCollapsedReplyIds.has(cid)) pvCollapsedReplyIds.delete(cid);
    else pvCollapsedReplyIds.add(cid);
    pvRenderComments({}, pvCommentsCache);
    return;
  }
  const likeBtn = e.target.closest('.pv-clike');
  if (likeBtn) {
    const cid = Number(likeBtn.getAttribute('data-cid') || 0);
    const currentReaction = String(likeBtn.getAttribute('data-reaction') || '');
    if (!pvPostId || !cid) return;
    if (currentReaction === 'love') return;
    pvCommentFocusId = cid;
    pvJson('feed_api.php?ajax=comment_like', {
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
      body:`post_id=${encodeURIComponent(pvPostId)}&comment_id=${encodeURIComponent(cid)}&reaction=${encodeURIComponent('love')}`,
      credentials:'same-origin'
    }).then(() => pvLoad(pvPostId)).catch(() => {});
    return;
  }
  const r = e.target.closest('.pv-reply');
  if (!r) return;
  const cid = Number(r.getAttribute('data-cid') || 0);
  const nm  = (r.getAttribute('data-name') || '').toString();
  const mode = (r.getAttribute('data-mode') || 'Reply').toString();
  pvSetReply(cid, nm, mode);
  pv.text.focus();
});
pv.replyCancel.addEventListener('click', () => pvSetReply(0,''));

// Focus comment
pv.focusComment.addEventListener('click', () => pv.text.focus());

// React (love/like)
pv.love.addEventListener('click', async () => {
  if (!pvPostId) return;
  if (pvCurrentReaction === 'love') return;
  try {
    const data = await pvJson('feed_api.php?ajax=react', {
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
      body:`post_id=${encodeURIComponent(pvPostId)}&reaction=${encodeURIComponent('love')}`,
      credentials:'same-origin'
    });
    pvApplyCounts({ post: pvCountMeta(), counts: data.counts || {} });
  } catch (e) {}
});

pv.like.addEventListener('click', async () => {
  if (!pvPostId) return;
  if (pvCurrentReaction === 'like') return;
  try {
    const data = await pvJson('feed_api.php?ajax=react', {
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
      body:`post_id=${encodeURIComponent(pvPostId)}&reaction=${encodeURIComponent('like')}`,
      credentials:'same-origin'
    });
    pvApplyCounts({ post: pvCountMeta(), counts: data.counts || {} });
  } catch (e) {}
});

if(window.MSBReactions){
  window.MSBReactions.bindLikePicker('#pvLove', async function(_btn, reaction){
    if (!pvPostId || !reaction || reaction === pvCurrentReaction) return;
    try {
      const data = await pvJson('feed_api.php?ajax=react', {
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
        body:`post_id=${encodeURIComponent(pvPostId)}&reaction=${encodeURIComponent(reaction)}`,
        credentials:'same-origin'
      });
      pvApplyCounts({ post: pvCountMeta(), counts: data.counts || {} });
    } catch (e) {}
  });
  window.MSBReactions.bindLikePicker('.pv-clike', async function(btn, reaction){
    const cid = Number(btn.getAttribute('data-cid') || 0);
    if (!pvPostId || !cid || !reaction) return;
    if (String(btn.getAttribute('data-reaction') || '') === String(reaction)) return;
    pvCommentFocusId = cid;
    try {
      await pvJson('feed_api.php?ajax=comment_like', {
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
        body:`post_id=${encodeURIComponent(pvPostId)}&comment_id=${encodeURIComponent(cid)}&reaction=${encodeURIComponent(reaction)}`,
        credentials:'same-origin'
      });
      pvLoad(pvPostId);
    } catch (e) {}
  });
}

// Share / Save
pv.share.addEventListener('click', async () => {
  if (!pvPostId) return;
  try {
    await pvJson('feed_api.php?ajax=share', {
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
      body:`post_id=${encodeURIComponent(pvPostId)}`,
      credentials:'same-origin'
    });
    const tc = await pvJson(`feed_api.php?ajax=track_counts&post_id=${encodeURIComponent(pvPostId)}`, { credentials:'same-origin' });
    pv.shareN.textContent = String(Number(tc.share_count || 0));
    pv.share.classList.toggle('is-share', Number(tc.my_shared || 0) === 1);
  } catch (e) {}
});

pv.save.addEventListener('click', async () => {
  if (!pvPostId) return;
  try {
    await pvJson('feed_api.php?ajax=save', {
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
      body:`post_id=${encodeURIComponent(pvPostId)}`,
      credentials:'same-origin'
    });
    const tc = await pvJson(`feed_api.php?ajax=track_counts&post_id=${encodeURIComponent(pvPostId)}`, { credentials:'same-origin' });
    pv.saveN.textContent  = String(Number(tc.save_count || 0));
    pv.save.classList.toggle('is-save', Number(tc.my_saved || 0) === 1);
  } catch (e) {}
});

// Post comment / reply
async function pvPostComment(){
  if (!pvPostId) return;
  const text = (pv.text.value || '').trim();
  if (!text) return;
  pv.postBtn.disabled = true;
  try {
    const body = `post_id=${encodeURIComponent(pvPostId)}&comment_text=${encodeURIComponent(text)}${pvReplyTo>0?`&parent_id=${encodeURIComponent(pvReplyTo)}`:''}`;
    await pvJson('feed_api.php?ajax=comment', {
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
      body,
      credentials:'same-origin'
    });
    pv.text.value = '';
    pvSetReply(0,'');
    pvCommentFocusId = 0;
    // reload only comments + counts
    await pvLoad(pvPostId);
    pv.comments.scrollTop = pv.comments.scrollHeight;
  } catch (e) {
    // ignore
  } finally {
    pv.postBtn.disabled = false;
  }
}
pv.postBtn.addEventListener('click', pvPostComment);
pv.text.addEventListener('keydown', (e)=>{
  if (e.key === 'Enter') { e.preventDefault(); pvPostComment(); }
});
</script>

<script>
// Post viewer modal opener (pvOpenById) — card click-to-open removed; use explicit actions only.
(function(){
  window.pvOpenById = function(postId){
    postId = Number(postId||0);
    if(!postId) return;
    try{
      var ids = (typeof pvIds==='function') ? pvIds() : [];
      var idx = Array.isArray(ids) ? ids.indexOf(postId) : -1;
      if (typeof pvOpenByIndex === 'function' && idx >= 0){
        pvOpenByIndex(idx);
        return;
      }
      if (typeof pvSetReply==='function') pvSetReply(0,'');
      pvCollapsedReplyIds.clear();
      pvCommentsCache = [];
      pvCommentFocusId = 0;
      if (typeof pvSetVh==='function') pvSetVh();
      if (window.pv && pv.ov){
        pvIndex = idx;
        pvPostId = postId;
        pv.ov.classList.add('show');
        pv.ov.setAttribute('aria-hidden','false');
        if (typeof pvLockBodyScroll==='function') pvLockBodyScroll();
        if (typeof pvLoad==='function') pvLoad(postId);
        if (typeof pvUpdateNavBtns==='function') pvUpdateNavBtns();
      }
    }catch(e){}
  };
})();
</script>

<script>
(function(){
  var alertPostId = <?php echo (int)$feedAlertPostId; ?>;
  var alertCommentId = <?php echo (int)$feedAlertCommentId; ?>;
  if(!alertPostId) return;

  function clearAlertParams(){
    try{
      var nextUrl = new URL(window.location.href);
      nextUrl.searchParams.delete('open_post');
      nextUrl.searchParams.delete('post');
      nextUrl.searchParams.delete('open_comment');
      history.replaceState({}, document.title, nextUrl.pathname + nextUrl.search + nextUrl.hash);
    }catch(err){}
  }

  function runAlertOpen(){
    try{
      if(typeof window.loadFeedAlertPost === 'function'){
        window.loadFeedAlertPost(alertPostId);
      }
      var row = document.querySelector('#postList .pl-item[data-id="' + String(alertPostId) + '"]');
      if(row && row.scrollIntoView){
        try{ row.scrollIntoView({ behavior:'smooth', block:'center' }); }catch(err){}
      }

      if(alertCommentId > 0){
        var tries = 0;
        (function waitForPost(){
          tries += 1;
          var curInput = document.getElementById('curPostId');
          var currentId = Number((window.__curPost && window.__curPost.id) || (curInput ? curInput.value : 0) || 0);
          if(currentId === alertPostId){
            try{
              if(typeof window.openFeedAlertComments === 'function'){
                window.openFeedAlertComments(alertCommentId);
              }
            }catch(err){}
            return;
          }
          if(tries < 20) setTimeout(waitForPost, 160);
        })();
      }

      clearAlertParams();
    }catch(err){}
  }

  setTimeout(runAlertOpen, 180);
})();
</script>

<script>
(function(){
  var storyPostId = <?php echo (int)$feedStoryPostId; ?>;
  if(!storyPostId) return;

  function clearStoryPostParam(){
    try{
      var nextUrl = new URL(window.location.href);
      nextUrl.searchParams.delete('story_post');
      history.replaceState({}, document.title, nextUrl.pathname + nextUrl.search + nextUrl.hash);
    }catch(err){}
  }

  function openTalentraCircle(){
    var key = 'u' + String(Number(window.ME_ID || <?php echo (int)$meId; ?> || 0));
    if(!key || key === 'u0') return;
    if(window.TTStories && typeof window.TTStories.openByKey === 'function'){
      window.TTStories.openByKey(key);
    }
  }

  var tries = 0;
  (function waitForStories(){
    tries += 1;
    var track = document.getElementById('igStoriesTrack');
    var hasStory = !!(track && track.querySelector('.ig-story-item[data-story-key]'));
    if(hasStory){
      clearStoryPostParam();
      setTimeout(openTalentraCircle, 120);
      return;
    }
    if(tries < 40) setTimeout(waitForStories, 200);
    else clearStoryPostParam();
  })();
})();
</script>

<?php if ($feedUploadWarn): ?>
<script>
(function(){
  function clearUploadWarn(){
    try{
      var nextUrl = new URL(window.location.href);
      nextUrl.searchParams.delete('upload_warn');
      history.replaceState({}, document.title, nextUrl.pathname + nextUrl.search + nextUrl.hash);
    }catch(err){}
  }
  function showUploadWarnToast(){
    if(window.MSBToast && typeof window.MSBToast.show === 'function'){
      window.MSBToast.show({
        type: 'warn',
        title: 'Photo not attached',
        message: 'Your story was saved, but the photo or video could not be attached. Try again with JPG, PNG, or MP4.',
        actionLabel: 'Try again',
        actionHref: 'dashboard.php?modal=1&story=1',
        actionModal: true,
        duration: 10000
      });
      clearUploadWarn();
      return;
    }
    setTimeout(showUploadWarnToast, 120);
  }
  setTimeout(showUploadWarnToast, 280);
})();
</script>
<?php endif; ?>

<?php theme_prefs_print_post_card_tail($dbh, $meId); ?>

<style id="feed-media-head-overlay-tail-css">
html[data-msb-appearance] body .mf-feed .mf-media-shell > .mf-head--on-media,
html[data-msb-appearance] body .mf-feed .mf-media-shell > .mf-head--on-media .mf-peer-link,
html[data-msb-appearance] body .mf-feed .mf-media-shell > .mf-head--on-media .mf-meta,
html[data-msb-appearance] body .mf-feed .mf-media-shell > .mf-head--on-media a:hover,
html[data-msb-appearance] body .mf-feed .mf-media-shell > .mf-head--on-media .mf-peer-link:hover {
  background: transparent !important;
  background-color: transparent !important;
  background-image: none !important;
  border-color: transparent !important;
  box-shadow: none !important;
}
html[data-msb-appearance] body .mf-feed .mf-media-shell > .mf-head--on-media,
html[data-msb-appearance] body .mf-feed .mf-media-shell > .mf-head--on-media .mf-peer-link,
html[data-msb-appearance] body .mf-feed .mf-media-shell > .mf-head--on-media .mf-meta,
html[data-msb-appearance] body .mf-feed .mf-media-shell > .mf-head--on-media a:hover,
html[data-msb-appearance] body .mf-feed .mf-media-shell > .mf-head--on-media .mf-peer-link:hover,
html[data-msb-appearance] body .mf-feed .mf-card:has(.mf-head--on-media) .mf-media-shell,
html[data-msb-appearance] body .mf-feed .mf-card:has(.mf-head--on-media) .mf-media-shell .media-stage,
html[data-msb-appearance] body .mf-feed .mf-card:has(.mf-head--on-media) .mf-media-shell .mf-media,
html[data-msb-appearance] body.dark-auto .mf-feed .mf-card:has(.mf-head--on-media) .mf-media-shell,
html[data-msb-appearance] body.dark-auto .mf-feed .mf-card:has(.mf-head--on-media) .mf-media-shell .media-stage,
html[data-msb-appearance] body.dark-auto .mf-feed .mf-card:has(.mf-head--on-media) .mf-media-shell .mf-media {
  background: transparent !important;
  background-color: transparent !important;
  background-image: none !important;
  border-color: transparent !important;
  box-shadow: none !important;
}
html[data-msb-appearance] body .mf-feed .mf-media-shell > .mf-head--on-media {
  position: relative !important;
  align-self: start !important;
  justify-self: stretch !important;
  z-index: 25 !important;
  pointer-events: none !important;
  margin: 0 !important;
  padding: 22px 14px 12px !important;
  background: transparent !important;
  background-color: transparent !important;
  background-image: none !important;
  box-shadow: none !important;
}
html[data-msb-appearance] body .mf-feed .mf-media-shell > .mf-head--on-media .mf-peer-link,
html[data-msb-appearance] body .mf-feed .mf-media-shell > .mf-head--on-media .mf-menu-wrap,
html[data-msb-appearance] body .mf-feed .mf-media-shell > .mf-head--on-media .post-card-menu-wrap,
html[data-msb-appearance] body .mf-feed .mf-media-shell > .mf-head--on-media .post-card-menu-btn,
html[data-msb-appearance] body .mf-feed .mf-media-shell > .mf-head--on-media .post-card-menu-btn i {
  pointer-events: auto !important;
  z-index: 60 !important;
  margin-top: 0 !important;
}
html[data-msb-appearance] body .mf-feed .mf-media-shell > .mf-head--on-media .mf-name,
html[data-msb-appearance] body .mf-feed .mf-media-shell > .mf-head--on-media .mf-time,
html[data-msb-appearance] body .mf-feed .mf-media-shell > .mf-head--on-media .mf-dot,
html[data-msb-appearance] body .mf-feed .mf-media-shell > .mf-head--on-media .mf-menu-btn:not(.post-card-menu-btn),
html[data-msb-appearance] body .mf-feed .mf-media-shell > .mf-head--on-media .mf-menu-btn:not(.post-card-menu-btn) i,
html[data-msb-appearance] body .mf-feed .mf-media-shell > .mf-head--on-media a:hover .mf-name,
html[data-msb-appearance] body .mf-feed .mf-media-shell > .mf-head--on-media a:hover .mf-time {
  color: #fff !important;
  text-shadow: 0 2px 10px rgba(0, 0, 0, .34) !important;
}
html[data-msb-appearance] body .mf-feed .mf-card[data-is-publisher="1"] .mf-media-shell > .mf-head--on-media,
html[data-msb-appearance] body .mf-feed .mf-card[data-account-kind="publisher"] .mf-media-shell > .mf-head--on-media{
  padding:22px 14px 12px !important;
}
html[data-msb-appearance] body .mf-feed .mf-card[data-is-publisher="1"] .mf-media-shell > .mf-head--on-media > .post-card-menu-wrap,
html[data-msb-appearance] body .mf-feed .mf-card[data-is-publisher="1"] .mf-media-shell > .mf-head--on-media > .mf-menu-wrap.post-card-menu-wrap,
html[data-msb-appearance] body .mf-feed .mf-card[data-account-kind="publisher"] .mf-media-shell > .mf-head--on-media > .post-card-menu-wrap,
html[data-msb-appearance] body .mf-feed .mf-card[data-account-kind="publisher"] .mf-media-shell > .mf-head--on-media > .mf-menu-wrap.post-card-menu-wrap{
  margin-right:0 !important;
}
</style>

<style id="feed-on-media-head-final-css">
/* Beat msb-appearance-palette-inline feed-surface paint on .mf-head.mf-head--on-media */
html[data-msb-appearance] body .mf-feed .mf-card .mf-head.mf-head--on-media,
html[data-msb-appearance] body .mf-feed .mf-card .mf-head.mf-head--on-media .mf-peer-link,
html[data-msb-appearance] body .mf-feed .mf-card .mf-head.mf-head--on-media .mf-meta,
html[data-msb-appearance] body .mf-feed .mf-card .mf-head.mf-head--on-media .mf-menu-wrap,
html[data-msb-appearance] body .mf-feed .mf-card .mf-head.mf-head--on-media .post-card-menu-wrap,
body .mf-feed .mf-card .mf-head.mf-head--on-media,
body .mf-feed .mf-media-shell > .mf-head.mf-head--on-media,
body .mf-feed .mf-head.mf-head--on-media,
body .mf-feed .mf-head.mf-head--on-media .mf-peer-link,
body .mf-feed .mf-head.mf-head--on-media .mf-meta,
body .mf-feed .mf-head.mf-head--on-media .mf-menu-wrap,
body .mf-feed .mf-head.mf-head--on-media .post-card-menu-wrap,
html[data-msb-appearance] body .mf-feed .mf-head.mf-head--on-media,
html[data-msb-appearance] body .mf-feed .mf-media-shell > .mf-head.mf-head--on-media,
html[data-msb-appearance] body .mf-feed .mf-head.mf-head--on-media .mf-peer-link,
html[data-msb-appearance] body .mf-feed .mf-head.mf-head--on-media .mf-meta,
html[data-msb-appearance] body .mf-feed .mf-head.mf-head--on-media .mf-menu-wrap,
html[data-msb-appearance] body .mf-feed .mf-head.mf-head--on-media .post-card-menu-wrap,
html[data-msb-appearance] body .mf-feed .mf-card:has(> .mf-media-shell > .mf-head--on-media) .mf-media-shell,
html[data-msb-appearance] body .mf-feed .mf-card:has(> .mf-media-shell > .mf-head--on-media) .mf-media-shell .media-stage,
html[data-msb-appearance] body .mf-feed .mf-card:has(> .mf-media-shell > .mf-head--on-media) .mf-media-shell .mf-media{
  background:transparent !important;
  background-color:transparent !important;
  background-image:none !important;
  border-color:transparent !important;
  box-shadow:none !important;
}
body .mf-feed .mf-media-shell:has(> .mf-head--on-media){
  display:grid !important;
  grid-template:1fr / 1fr !important;
  background:transparent !important;
}
body .mf-feed .mf-media-shell:has(> .mf-head--on-media) > .mf-media,
body .mf-feed .mf-media-shell:has(> .mf-head--on-media) > .media-stage,
body .mf-feed .mf-media-shell:has(> .mf-head--on-media) > .mf-head.mf-head--on-media,
body .mf-feed .mf-media-shell:has(> .mf-head--on-media) > .mf-media-top-actions{
  grid-area:1 / 1 !important;
}
body .mf-feed .mf-media-shell > .mf-head.mf-head--on-media{
  position:relative !important;
  align-self:start !important;
  justify-self:stretch !important;
  padding:22px 14px 12px !important;
}
body .mf-feed .mf-media-shell > .mf-head.mf-head--on-media .mf-name,
body .mf-feed .mf-media-shell > .mf-head.mf-head--on-media .mf-time,
body .mf-feed .mf-media-shell > .mf-head.mf-head--on-media .mf-dot,
html[data-msb-appearance] body .mf-feed .mf-media-shell > .mf-head.mf-head--on-media .mf-name,
html[data-msb-appearance] body .mf-feed .mf-media-shell > .mf-head.mf-head--on-media .mf-time,
html[data-msb-appearance] body .mf-feed .mf-media-shell > .mf-head.mf-head--on-media .mf-dot{
  color:#fff !important;
  text-shadow:0 2px 10px rgba(0,0,0,.34) !important;
}
</style>

<style id="feed-on-media-fries-placement-css">
/* Pin fries to top-right of on-media header; keep name/music clear of menu. */
body .mf-feed .mf-media-shell > .mf-head.mf-head--on-media > .post-card-menu-wrap,
body .mf-feed .mf-media-shell > .mf-head.mf-head--on-media > .mf-menu-wrap.post-card-menu-wrap,
html[data-msb-appearance] body .mf-feed .mf-media-shell > .mf-head.mf-head--on-media > .post-card-menu-wrap,
html[data-msb-appearance] body .mf-feed .mf-media-shell > .mf-head.mf-head--on-media > .mf-menu-wrap.post-card-menu-wrap{
  position:absolute !important;
  top:var(--pcm-on-media-menu-top, 18px) !important;
  right:var(--pcm-on-media-menu-right, 10px) !important;
  bottom:auto !important;
  left:auto !important;
  margin:0 !important;
  transform:none !important;
  align-self:auto !important;
  z-index:61 !important;
}
body .mf-feed .mf-media-shell > .mf-head.mf-head--on-media .mf-peer-link,
html[data-msb-appearance] body .mf-feed .mf-media-shell > .mf-head.mf-head--on-media .mf-peer-link{
  padding-right:44px !important;
  box-sizing:border-box !important;
}
body .mf-feed .mf-media-shell > .mf-head.mf-head--on-media .mf-name-row,
html[data-msb-appearance] body .mf-feed .mf-media-shell > .mf-head.mf-head--on-media .mf-name-row{
  gap:3px !important;
  width:auto !important;
  justify-content:flex-start !important;
}
body .mf-feed .mf-media-shell > .mf-head.mf-head--on-media .mf-name,
html[data-msb-appearance] body .mf-feed .mf-media-shell > .mf-head.mf-head--on-media .mf-name{
  flex:0 1 auto !important;
  max-width:calc(100% - 64px) !important;
}
body .mf-feed .mf-media-shell > .mf-head.mf-head--on-media .mf-dot,
body .mf-feed .mf-media-shell > .mf-head.mf-head--on-media .mf-time,
html[data-msb-appearance] body .mf-feed .mf-media-shell > .mf-head.mf-head--on-media .mf-dot,
html[data-msb-appearance] body .mf-feed .mf-media-shell > .mf-head.mf-head--on-media .mf-time{
  margin-left:0 !important;
}
body .mf-feed .mf-media-shell > .mf-head.mf-head--on-media .mf-music-row,
html[data-msb-appearance] body .mf-feed .mf-media-shell > .mf-head.mf-head--on-media .mf-music-row{
  width:auto !important;
  max-width:100% !important;
  align-self:flex-start !important;
  justify-content:flex-start !important;
}
body .mf-feed .mf-media-shell > .mf-head.mf-head--on-media .mf-music-title,
html[data-msb-appearance] body .mf-feed .mf-media-shell > .mf-head.mf-head--on-media .mf-music-title{
  flex:0 1 auto !important;
}
body .mf-feed .mf-media-shell > .mf-head.mf-head--on-media .mf-music-artist,
html[data-msb-appearance] body .mf-feed .mf-media-shell > .mf-head.mf-head--on-media .mf-music-artist{
  flex:0 1 auto !important;
  max-width:none !important;
}
</style>

<?php post_card_actions_menu_render_modals(); ?>

</body>
</html>
