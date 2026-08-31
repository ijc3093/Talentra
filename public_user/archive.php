<?php
declare(strict_types=1);

/**
 * Private archive — story circles (feed-style) + story grid + archived posts list.
 */
require_once __DIR__ . '/includes/session_user.php';
requireUserLogin();
require_once __DIR__ . '/controller.php';
require_once __DIR__ . '/includes/theme_prefs.php';
require_once __DIR__ . '/includes/device_profile.php';
require_once __DIR__ . '/includes/post_layout.php';
require_once __DIR__ . '/includes/archive_posts.php';
require_once __DIR__ . '/includes/staff_publisher_access.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$controller = new Controller();
$dbh = $controller->pdo();

$meId = (int)($_SESSION['user_id'] ?? 0);
try {
    $viewerId = theme_prefs_viewer_user_id();
    if ($viewerId > 0) {
        $meId = $viewerId;
    }
} catch (Throwable $e) {
    // keep session user id
}
if ($meId <= 0) {
    header('Location: index.php?session=reset');
    exit;
}

device_profile_ensure_post_columns($dbh);
$posts = msb_archive_fetch_posts($dbh, $meId, 200);
$backUrl = 'profile.php?tab=gear';
$staffReadonly = function_exists('staff_pub_is_readonly') && staff_pub_is_readonly();

$meUser = ['id' => $meId, 'name' => '', 'username' => '', 'image' => ''];
try {
    $stU = $dbh->prepare('SELECT id, name, username, image FROM users WHERE id = :id LIMIT 1');
    $stU->execute([':id' => $meId]);
    $row = $stU->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $meUser = $row;
    }
} catch (Throwable $e) {
    // keep defaults
}

$view = msb_archive_prepare_view($posts, $meUser);
$storyCircles = $view['storyCircles'];
$hasStories = $view['hasStories'];
$feedPosts = $view['feedPosts'];
$avatarUrl = $view['avatarUrl'];
$displayName = trim((string)($meUser['name'] ?? '')) !== ''
    ? trim((string)$meUser['name'])
    : trim((string)($meUser['username'] ?? 'You'));
$msbArchiveEmbed = false;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta http-equiv="Cache-Control" content="no-store, no-cache, must-revalidate">
  <title>Archive</title>
  <?php theme_prefs_print_head_bootstrap($dbh, $meId); ?>
  <link rel="stylesheet" href="lib/Ionicons/css/ionicons.css">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Figtree:wght@500;600;700;800&display=swap" rel="stylesheet">
  <style>
    :root{
      --ig-bg:var(--msb-palette-bg, #fff);
      --ig-text:var(--msb-palette-text, #0f0f0f);
      --ig-muted:var(--msb-palette-text-muted, #8e8e8e);
      --ig-line:var(--msb-palette-border, #dbdbdb);
      --ig-badge:#fff;
      --ig-badge-text:#0f0f0f;
      --ig-tile:#1a1a1a;
      --ig-sheet:var(--msb-palette-bg, #fff);
      --ig-danger:#ed4956;
      --msb-top-story-item:50px;
      --msb-top-story-ring:44px;
    }
    html[data-theme="dark"],
    html.dark-auto,
    html[data-msb-appearance].msb-appearance-dark{
      --ig-bg:var(--msb-palette-bg, #000);
      --ig-text:var(--msb-palette-text, #f5f5f5);
      --ig-muted:var(--msb-palette-text-muted, #a8a8a8);
      --ig-line:var(--msb-palette-border, #262626);
      --ig-badge:#fff;
      --ig-badge-text:#0f0f0f;
      --ig-tile:#121212;
      --ig-sheet:var(--msb-palette-bg, #1a1a1a);
    }
    *{box-sizing:border-box}
    html,body{margin:0;padding:0;height:100%;overflow:hidden}
    body{
      font-family:"Figtree",ui-sans-serif,system-ui,sans-serif;
      background:var(--ig-bg);
      background-color:var(--ig-bg);
      color:var(--ig-text);
      -webkit-font-smoothing:antialiased;
    }
    /* Follow Appearance / Progress color / Dark auto on the whole Archive canvas */
    html[data-msb-appearance] body,
    html[data-theme="dark"] body,
    html.dark-auto body,
    html[data-msb-appearance] .ig-archive,
    html[data-theme="dark"] .ig-archive,
    html.dark-auto .ig-archive,
    html[data-msb-appearance] .ig-archive-top,
    html[data-theme="dark"] .ig-archive-top,
    html.dark-auto .ig-archive-top,
    html[data-msb-appearance] .ig-archive-posts-meta,
    html[data-theme="dark"] .ig-archive-posts-meta,
    html.dark-auto .ig-archive-posts-meta,
    html[data-msb-appearance] .ig-archive-grid-scroll,
    html[data-theme="dark"] .ig-archive-grid-scroll,
    html.dark-auto .ig-archive-grid-scroll,
    html[data-msb-appearance] .ig-archive-sheet,
    html[data-theme="dark"] .ig-archive-sheet,
    html.dark-auto .ig-archive-sheet{
      background:var(--msb-palette-bg, var(--ig-bg)) !important;
      background-color:var(--msb-palette-bg, var(--ig-bg)) !important;
      color:var(--msb-palette-text, var(--ig-text));
    }
    html[data-msb-appearance] .ig-archive-title,
    html[data-theme="dark"] .ig-archive-title,
    html.dark-auto .ig-archive-title,
    html[data-msb-appearance] .ig-archive-section-title,
    html[data-theme="dark"] .ig-archive-section-title,
    html.dark-auto .ig-archive-section-title,
    html[data-msb-appearance] .ig-story-name,
    html[data-theme="dark"] .ig-story-name,
    html.dark-auto .ig-story-name{
      color:var(--msb-palette-text, var(--ig-text));
    }
    html[data-msb-appearance] .ig-archive-note,
    html[data-theme="dark"] .ig-archive-note,
    html.dark-auto .ig-archive-note,
    html[data-msb-appearance] .ig-archive-empty,
    html[data-theme="dark"] .ig-archive-empty,
    html.dark-auto .ig-archive-empty,
    html[data-msb-appearance] .ig-archive-section-title,
    html[data-theme="dark"] .ig-archive-section-title,
    html.dark-auto .ig-archive-section-title,
    html[data-msb-appearance] .ig-archive-stories-label,
    html[data-theme="dark"] .ig-archive-stories-label,
    html.dark-auto .ig-archive-stories-label{
      color:var(--msb-palette-text-muted, var(--ig-muted));
    }
    a{color:inherit;text-decoration:none}
    button{font:inherit}

    .ig-archive{
      max-width:540px;
      margin:0 auto;
      height:100%;
      height:100dvh;
      display:flex;
      flex-direction:column;
      overflow:hidden;
      background:var(--ig-bg);
      padding:0;
    }

    .ig-archive-top{
      flex:0 0 auto;
      z-index:20;
      background:var(--ig-bg);
      padding:calc(10px + env(safe-area-inset-top)) 0 0;
    }
    .ig-archive-head{
      display:flex;
      align-items:center;
      gap:10px;
      min-height:44px;
      padding:0 16px 8px;
    }
    .ig-archive-back{
      width:40px;height:40px;
      display:inline-flex;align-items:center;justify-content:center;
      border:0;background:transparent;color:var(--ig-text);
      border-radius:999px;cursor:pointer;padding:0;flex:0 0 auto;
    }
    .ig-archive-back:hover{background:rgba(127,127,127,.12)}
    .ig-archive-back svg{width:24px;height:24px;display:block}
    .ig-archive-title{
      font-size:22px;font-weight:700;letter-spacing:-.02em;line-height:1.1;
    }

    .ig-stories-wrap{
      position:relative;
      padding:8px 0 14px;
      border-bottom:0;
    }
    .ig-archive-stories-block{
      padding:0 0 6px;
    }
    .ig-archive-stories-label{
      margin:0 16px 8px;
      font-size:13px;font-weight:800;letter-spacing:.04em;
      text-transform:uppercase;color:var(--ig-muted);
    }
    .ig-archive-note{
      margin:8px 16px 0;
      font-size:12px;font-weight:500;line-height:1.45;
      color:var(--ig-muted);
    }
    .ig-archive-note--stories{
      margin-bottom:4px;
    }
    .ig-archive-body{
      flex:1 1 auto;
      min-height:0;
      display:flex;
      flex-direction:column;
      overflow:hidden;
      margin-top:18px;
      padding-top:18px;
      border-top:8px solid var(--ig-line);
    }
    html[data-theme="dark"] .ig-archive-body,
    html.dark-auto .ig-archive-body,
    html[data-msb-appearance].msb-appearance-dark .ig-archive-body{
      border-top-color:var(--msb-palette-border, var(--ig-line));
    }
    .ig-archive-section-title{
      margin:0 16px 12px;
      font-size:13px;font-weight:800;letter-spacing:.04em;
      text-transform:uppercase;color:var(--ig-muted);
    }
    .ig-archive-section{
      flex:1 1 auto;
      min-height:0;
      display:flex;
      flex-direction:column;
      padding-bottom:0;
    }
    .ig-archive-posts-meta{
      flex:0 0 auto;
      background:var(--ig-bg);
    }
    .ig-archive-grid-scroll{
      flex:1 1 auto;
      min-height:0;
      overflow-x:hidden;
      overflow-y:auto;
      -webkit-overflow-scrolling:touch;
      overscroll-behavior:contain;
      padding-bottom:calc(16px + env(safe-area-inset-bottom));
    }
    .ig-archive-section + .ig-archive-section{
      margin-top:22px;
      padding-top:18px;
      border-top:1px solid var(--ig-line);
    }
    .ig-stories-bar{
      display:flex;
      align-items:center;
      gap:0;
      padding:0 8px;
    }
    .ig-stories-track{
      display:flex;
      align-items:flex-start;
      gap:14px;
      overflow-x:auto;
      overflow-y:hidden;
      scroll-behavior:smooth;
      flex:1 1 auto;
      min-width:0;
      scrollbar-width:none;
      -ms-overflow-style:none;
      padding:2px 6px 4px;
    }
    .ig-stories-track::-webkit-scrollbar{display:none}
    .ig-story-item{
      flex:0 0 auto;
      width:var(--msb-top-story-item);
      min-width:var(--msb-top-story-item);
      text-align:center;
      cursor:pointer;
      user-select:none;
      border:0;
      padding:0;
      background:transparent;
      font:inherit;
      color:inherit;
      -webkit-tap-highlight-color:transparent;
    }
    .ig-story-ring{
      width:var(--msb-top-story-ring);
      height:var(--msb-top-story-ring);
      margin:0 auto 6px;
      padding:2px;
      border-radius:50%;
      background:linear-gradient(45deg,#f58529,#dd2a7b,#8134af,#515bd4);
      box-sizing:border-box;
    }
    .ig-story-ring img,
    .ig-story-ring video,
    .ig-story-thumb{
      display:block;
      width:100%;
      height:100%;
      border-radius:50%;
      border:2px solid var(--ig-bg);
      object-fit:cover;
      background:#efefef;
      box-sizing:border-box;
    }
    .ig-story-ring-text{
      display:flex;
      align-items:center;
      justify-content:center;
      width:100%;
      height:100%;
      border-radius:50%;
      border:2px solid var(--ig-bg);
      box-sizing:border-box;
      padding:6px;
      font-size:10px;
      font-weight:700;
      line-height:1.15;
      text-align:center;
      color:var(--ig-text);
      background:rgba(127,127,127,.14);
      overflow:hidden;
    }
    .ig-story-name{
      display:block;
      max-width:var(--msb-top-story-item);
      margin:0 auto;
      font-size:12px;
      line-height:1.2;
      color:var(--ig-text);
      white-space:nowrap;
      overflow:hidden;
      text-overflow:ellipsis;
    }
    .ig-story-create{text-decoration:none;color:inherit}
    .ig-story-create .ig-story-ring-create{
      background:var(--ig-bg);
      border:2px solid var(--ig-line);
      padding:0;
      display:flex;
      align-items:center;
      justify-content:center;
      box-sizing:border-box;
      background-image:none;
    }
    .ig-story-create .ig-story-ring-create i{
      font-size:26px;
      color:var(--ig-text);
      line-height:1;
    }
    .ig-stories-track.is-empty{
      justify-content:center;
      align-items:flex-start;
      min-height:74px;
    }
    .ig-stories-track.has-create.is-empty{justify-content:flex-start}
    .ig-story-empty{
      width:auto;
      min-width:var(--msb-top-story-item);
      max-width:118px;
      cursor:default;
      pointer-events:none;
    }
    .ig-story-ring-empty{
      background:var(--msb-palette-hover-bg, rgba(127,127,127,.16)) !important;
      background-image:none !important;
    }
    .ig-story-empty-icon{
      display:flex;
      align-items:center;
      justify-content:center;
      width:100%;
      height:100%;
      border-radius:50%;
      border:2px solid var(--ig-bg);
      background:var(--msb-palette-hover-bg, #f2f4f7);
      box-sizing:border-box;
      color:var(--msb-palette-text-muted, #98a2b3);
      font-size:26px;
      line-height:1;
    }
    .ig-stories-next{
      flex:0 0 auto;
      width:28px;height:28px;
      margin-left:4px;
      border:0;border-radius:999px;
      background:rgba(0,0,0,.08);
      color:var(--ig-text);
      display:none;
      align-items:center;justify-content:center;
      cursor:pointer;
    }
    .ig-stories-bar:not(.is-empty) .ig-stories-next{display:inline-flex}
    .ig-stories-next svg{width:12px;height:12px}

    .ig-archive-grid{
      display:grid;
      grid-template-columns:repeat(3, minmax(0, 1fr));
      gap:2px;
      padding:0 1px 8px;
    }
    .ig-archive-tile{
      position:relative;
      aspect-ratio:1 / 1;
      background:var(--ig-tile);
      overflow:hidden;
      border:0;padding:0;cursor:pointer;
      color:#fff;text-align:left;
      -webkit-tap-highlight-color:transparent;
    }
    .ig-archive-tile:focus-visible{
      outline:2px solid #0095f6;
      outline-offset:2px;
      z-index:1;
    }
    .ig-archive-tile .react-overlay{
      position:absolute;inset:0;z-index:6;
      background:rgba(2,8,23,.58);
      opacity:0;pointer-events:none;
      transition:opacity .16s ease;
      display:flex;align-items:center;justify-content:center;
      gap:10px;padding:10px;color:#fff;
    }
    .ig-archive-tile:hover .react-overlay,
    .ig-archive-tile:focus-visible .react-overlay{opacity:1}
    .ig-archive-tile .react-btn{
      display:flex;align-items:center;gap:7px;
      padding:8px 10px;border-radius:999px;
      background:rgba(255,255,255,.16);color:#fff;
      font-weight:900;font-size:12px;
      border:1px solid rgba(255,255,255,.14);
      pointer-events:none;
    }
    .ig-archive-tile .react-btn i{font-size:16px}
    .ig-story-item:focus,
    .ig-story-item:focus-visible{
      outline:none;
    }
    .ig-archive-media{
      position:absolute;inset:0;
      width:100%;height:100%;
      object-fit:cover;display:block;
      background:#1a1a1a;
    }
    .ig-archive-fallback{
      position:absolute;inset:0;
      display:flex;align-items:flex-end;
      padding:12px 10px;
      background:linear-gradient(180deg,#2a2a2a 0%,#111 100%);
      color:#fff;font-size:12px;font-weight:600;line-height:1.35;
    }
    .ig-archive-fallback span{
      display:-webkit-box;-webkit-line-clamp:4;-webkit-box-orient:vertical;overflow:hidden;
    }
    .ig-archive-date{
      position:absolute;top:8px;left:8px;z-index:2;
      min-width:42px;
      padding:5px 7px 4px;
      border-radius:6px;
      background:var(--ig-badge);
      color:var(--ig-badge-text);
      box-shadow:0 1px 2px rgba(0,0,0,.18);
      line-height:1.05;
      pointer-events:none;
    }
    .ig-archive-date-day{display:block;font-size:15px;font-weight:800;letter-spacing:-.02em}
    .ig-archive-date-month{
      display:block;font-size:9px;font-weight:700;letter-spacing:.02em;
      text-transform:uppercase;opacity:.85;margin-top:1px;
    }
    .ig-archive-video-mark{
      position:absolute;top:8px;right:8px;z-index:2;
      width:22px;height:22px;border-radius:999px;
      background:rgba(0,0,0,.45);
      display:grid;place-items:center;
      pointer-events:none;
    }
    .ig-archive-video-mark svg{width:11px;height:11px;fill:#fff}

    .ig-archive-empty{
      padding:48px 28px 40px;
      text-align:center;
      color:var(--ig-muted);
    }
    .ig-archive-empty strong{
      display:block;color:var(--ig-text);
      font-size:18px;font-weight:700;margin-bottom:8px;
    }
    .ig-archive-empty p{margin:0;font-size:13px;font-weight:500;line-height:1.5}

    .ig-archive-viewer{
      position:fixed;inset:0;z-index:1000;
      display:none;align-items:flex-end;justify-content:center;
      background:rgba(0,0,0,.55);
      padding:16px 12px calc(16px + env(safe-area-inset-bottom));
    }
    .ig-archive-viewer.is-open{display:flex}
    .ig-archive-sheet{
      width:fit-content;
      max-width:min(100%, 420px);
      min-width:min(100%, 260px);
      background:var(--ig-sheet);
      color:var(--ig-text);
      border-radius:18px;
      overflow:hidden;
      box-shadow:0 18px 50px rgba(0,0,0,.35);
      animation:igSheetIn .18s ease;
    }
    @keyframes igSheetIn{
      from{transform:translateY(12px);opacity:.6}
      to{transform:translateY(0);opacity:1}
    }
    .ig-archive-sheet-preview{
      width:auto;
      max-width:100%;
      margin:0;
      background:transparent;
      position:relative;
      overflow:hidden;
      line-height:0;
    }
    .ig-archive-sheet-preview img,
    .ig-archive-sheet-preview video{
      width:auto;
      height:auto;
      max-width:min(92vw, 420px);
      max-height:min(72svh, 720px);
      object-fit:contain;
      display:block;
      background:transparent;
      vertical-align:top;
    }
    .ig-archive-sheet-preview .ig-archive-fallback{
      width:min(92vw, 420px);
      min-height:160px;
      font-size:15px;
      padding:18px;
      line-height:1.35;
      box-sizing:border-box;
    }
    .ig-archive-sheet-actions{display:flex;flex-direction:column;width:100%}
    .ig-archive-sheet-btn{
      width:100%;border:0;background:transparent;color:var(--ig-text);
      padding:15px 16px;font-size:15px;font-weight:600;
      border-top:1px solid var(--ig-line);cursor:pointer;text-align:center;
    }
    .ig-archive-sheet-btn.is-danger{color:var(--ig-danger)}
    .ig-archive-sheet-btn:disabled{opacity:.55;cursor:wait}

    .ig-archive-toast{
      position:fixed;left:50%;bottom:28px;transform:translateX(-50%);
      background:#262626;color:#fff;padding:11px 16px;border-radius:999px;
      font-size:13px;font-weight:600;z-index:1100;opacity:0;
      pointer-events:none;transition:opacity .2s ease;
      max-width:min(92vw,360px);text-align:center;
    }
    .ig-archive-toast.is-on{opacity:1}

    @media (min-width:700px){
      .ig-archive{max-width:720px;border-left:1px solid var(--ig-line);border-right:1px solid var(--ig-line)}
      .ig-archive-grid{gap:3px;padding:0 2px 18px}
    }

    /* Tablet: modal matches tablet media proportions */
    @media (min-width:768px) and (max-width:1023.98px){
      .ig-archive-viewer{
        align-items:center;
        padding:24px;
      }
      .ig-archive-sheet{
        max-width:min(560px, 92vw);
        border-radius:20px;
      }
      .ig-archive-sheet-preview img,
      .ig-archive-sheet-preview video{
        max-width:min(92vw, 560px);
        max-height:min(76svh, 860px);
      }
      .ig-archive-sheet-preview .ig-archive-fallback{
        width:min(92vw, 560px);
      }
    }

    /* Desktop / laptop: modal matches wider feed media card */
    @media (min-width:1024px){
      .ig-archive-viewer{
        align-items:center;
        padding:32px;
      }
      .ig-archive-sheet{
        max-width:min(680px, 70vw);
        border-radius:22px;
      }
      .ig-archive-sheet-preview img,
      .ig-archive-sheet-preview video{
        max-width:min(70vw, 680px);
        max-height:min(78svh, 960px);
      }
      .ig-archive-sheet-preview .ig-archive-fallback{
        width:min(70vw, 680px);
      }
    }
  </style>
</head>
<body>
<?php
$msbArchiveEmbed = false;
include __DIR__ . "/includes/archive_view.php";
include __DIR__ . "/includes/archive_view.js.php";
?>
</body>
</html>

