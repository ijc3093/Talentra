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

$storyPosts = [];
$feedPosts = [];
foreach ($posts as $post) {
    // Split by where Archive was clicked: story door → Stories; card fries → Posts.
    if (!empty($post['archived_as_story'])) {
        $storyPosts[] = $post;
    } else {
        $feedPosts[] = $post;
    }
}

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

if (!function_exists('msb_archive_avatar_url')) {
    function msb_archive_avatar_url(array $user, int $size = 96): string
    {
        if (function_exists('user_avatar_url')) {
            return (string)user_avatar_url($user, $size);
        }
        $img = trim((string)($user['image'] ?? ''));
        if ($img !== '' && $img !== 'default.jpg') {
            if (preg_match('~^(https?:)?//~i', $img) || (isset($img[0]) && $img[0] === '/')) {
                return $img;
            }
            return './' . ltrim($img, './');
        }
        return 'avatar.php?id=' . (int)($user['id'] ?? 0) . '&s=' . $size;
    }
}

/**
 * @return array{day:string,month:string}
 */
function msb_archive_date_badge(string $dt): array
{
    $ts = strtotime(trim($dt));
    if ($ts === false) {
        return ['day' => '', 'month' => ''];
    }
    $day = date('j', $ts);
    $month = date('Y', $ts) === date('Y')
        ? date('M', $ts)
        : date('M Y', $ts);
    return ['day' => $day, 'month' => $month];
}

$avatarUrl = msb_archive_avatar_url($meUser, 96);
$displayName = trim((string)($meUser['name'] ?? '')) !== ''
    ? trim((string)$meUser['name'])
    : trim((string)($meUser['username'] ?? 'You'));

// One circle per archived story (hide/archive adds the next circle in the rail).
$storyCircles = [];
foreach ($storyPosts as $post) {
    $pid = (int)($post['id'] ?? 0);
    if ($pid <= 0) {
        continue;
    }
    $previewSrc = (string)($post['preview_src'] ?? '');
    $thumbType = strtolower(trim((string)($post['thumb_type'] ?? '')));
    $isVideo = ($thumbType === 'video');
    $caption = trim((string)($post['preview_text'] ?? ''));
    $badge = msb_archive_date_badge((string)($post['updated_at'] ?? $post['created_at'] ?? ''));
    $label = trim($badge['day'] . ' ' . $badge['month']);
    if ($label === '') {
        $label = $caption !== '' ? $caption : ('Story #' . $pid);
    }
    $storyCircles[] = [
        'postId' => $pid,
        'src' => $previewSrc,
        'type' => $isVideo ? 'video' : ($previewSrc !== '' ? 'image' : 'text'),
        'caption' => $caption !== '' ? $caption : ('Story #' . $pid),
        'label' => $label,
        'ringSrc' => $previewSrc !== '' ? $previewSrc : $avatarUrl,
        'createdAt' => (string)($post['updated_at'] ?? $post['created_at'] ?? ''),
    ];
}
$hasStories = count($storyCircles) > 0;
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
    .ig-archive-tile:focus-visible,
    .ig-story-item[data-story-key]:focus-visible{
      outline:2px solid #0095f6;
      outline-offset:2px;
      z-index:1;
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
<div class="ig-archive">
  <header class="ig-archive-top">
    <div class="ig-archive-head">
      <a class="ig-archive-back" href="<?= msb_archive_h($backUrl) ?>" aria-label="Back">
        <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
          <path d="M15.5 4.5 8 12l7.5 7.5" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
      </a>
      <h1 class="ig-archive-title">Archive</h1>
    </div>

    <div class="ig-archive-stories-block">
      <div class="ig-archive-stories-label">Stories</div>
      <div class="ig-stories-wrap">
      <div class="ig-stories-bar<?= $hasStories ? '' : ' is-empty' ?>" aria-label="Archived stories">
        <div class="ig-stories-track<?= $hasStories ? '' : ' is-empty' ?>" id="igStoriesTrack">
          <?php if (!$hasStories): ?>
            <div class="ig-story-item ig-story-empty" role="status" aria-label="No archived stories">
              <div class="ig-story-ring ig-story-ring-empty">
                <span class="ig-story-empty-icon" aria-hidden="true"><i class="icon ion-ios-book-outline"></i></span>
              </div>
            </div>
          <?php else: ?>
            <?php foreach ($storyCircles as $circle): ?>
              <?php
                $cid = (int)$circle['postId'];
                $cSrc = (string)$circle['src'];
                $cType = (string)$circle['type'];
                $cCap = (string)$circle['caption'];
                $cLabel = (string)$circle['label'];
                $cRing = (string)$circle['ringSrc'];
                $slideJson = json_encode([[
                    'postId' => $cid,
                    'src' => $cSrc,
                    'type' => $cType,
                    'caption' => $cCap,
                ]], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
              ?>
              <button
                type="button"
                class="ig-story-item"
                data-story-key="s<?= $cid ?>"
                data-post-id="<?= $cid ?>"
                data-src="<?= msb_archive_h($cSrc) ?>"
                data-type="<?= msb_archive_h($cType) ?>"
                data-caption="<?= msb_archive_h($cCap) ?>"
                data-story-slides="<?= msb_archive_h((string)$slideJson) ?>"
                aria-label="Open archived story <?= msb_archive_h($cLabel) ?>"
              >
                <div class="ig-story-ring">
                  <?php if ($cType === 'video' && $cSrc !== ''): ?>
                    <video class="ig-story-thumb" src="<?= msb_archive_h($cSrc) ?>" muted playsinline preload="metadata"></video>
                  <?php elseif ($cSrc !== ''): ?>
                    <img class="ig-story-thumb" src="<?= msb_archive_h($cSrc) ?>" alt="">
                  <?php elseif ($cRing !== '' && $cRing !== $cSrc): ?>
                    <img class="ig-story-thumb" src="<?= msb_archive_h($cRing) ?>" alt="">
                  <?php else: ?>
                    <span class="ig-story-ring-text"><?= msb_archive_h(function_exists('mb_substr') ? (string)mb_substr($cCap, 0, 18) : substr($cCap, 0, 18)) ?></span>
                  <?php endif; ?>
                </div>
                <span class="ig-story-name"><?= msb_archive_h($cLabel) ?></span>
              </button>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
        <?php if ($hasStories): ?>
          <button type="button" class="ig-stories-next" aria-label="Next stories" id="igStoriesNext">
            <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M9 5l7 7-7 7" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
          </button>
        <?php endif; ?>
      </div>
      </div>
      <p class="ig-archive-note ig-archive-note--stories">Only you can see archived stories. Each hide from the story door fries menu adds the next circle here.</p>
    </div>
  </header>

  <?php if ($feedPosts || (!$hasStories && !$feedPosts)): ?>
  <div class="ig-archive-body">
  <?php if ($feedPosts): ?>
    <section class="ig-archive-section" aria-label="Archived posts">
    <div class="ig-archive-posts-meta">
      <div class="ig-archive-section-title">Posts</div>
      <p class="ig-archive-note" style="margin-top:0;margin-bottom:12px;">Archived from the feed or public post-card fries menu stay here — separate from story circles above.</p>
    </div>
    <div class="ig-archive-grid-scroll">
    <div class="ig-archive-grid" id="archivePostList">
      <?php foreach ($feedPosts as $post): ?>
        <?php
          $pid = (int)($post['id'] ?? 0);
          if ($pid <= 0) {
              continue;
          }
          $previewSrc = (string)($post['preview_src'] ?? '');
          $thumbType = strtolower(trim((string)($post['thumb_type'] ?? '')));
          $isVideo = ($thumbType === 'video');
          $caption = trim((string)($post['preview_text'] ?? ''));
          $title = trim((string)($post['title'] ?? ''));
          if ($title === '' || strcasecmp($title, 'post') === 0) {
              $title = $caption !== '' ? $caption : ('Post #' . $pid);
          }
          $badge = msb_archive_date_badge((string)($post['updated_at'] ?? $post['created_at'] ?? ''));
          $openCaption = $caption !== '' ? $caption : $title;
        ?>
        <button
          type="button"
          class="ig-archive-tile"
          data-post-id="<?= $pid ?>"
          data-src="<?= msb_archive_h($previewSrc) ?>"
          data-type="<?= msb_archive_h($isVideo ? 'video' : ($previewSrc !== '' ? 'image' : 'text')) ?>"
          data-caption="<?= msb_archive_h($openCaption) ?>"
          data-kind="post"
          aria-label="Archived post from <?= msb_archive_h(trim($badge['day'] . ' ' . $badge['month'])) ?>"
        >
          <?php if ($badge['day'] !== ''): ?>
            <span class="ig-archive-date">
              <span class="ig-archive-date-day"><?= msb_archive_h($badge['day']) ?></span>
              <span class="ig-archive-date-month"><?= msb_archive_h($badge['month']) ?></span>
            </span>
          <?php endif; ?>
          <?php if ($isVideo && $previewSrc !== ''): ?>
            <span class="ig-archive-video-mark" aria-hidden="true">
              <svg viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg>
            </span>
            <video class="ig-archive-media" src="<?= msb_archive_h($previewSrc) ?>" muted playsinline preload="metadata"></video>
          <?php elseif ($previewSrc !== ''): ?>
            <img class="ig-archive-media" src="<?= msb_archive_h($previewSrc) ?>" alt="">
          <?php else: ?>
            <div class="ig-archive-fallback"><span><?= msb_archive_h($openCaption) ?></span></div>
          <?php endif; ?>
        </button>
      <?php endforeach; ?>
    </div>
    </div>
    </section>
  <?php endif; ?>

  <?php if (!$hasStories && !$feedPosts): ?>
    <div class="ig-archive-empty" role="status">
      <strong>No archived items</strong>
      <p>Archive a story from the story door fries menu (adds a circle above), or archive a post from For You / Discover (shows under Posts).</p>
    </div>
  <?php endif; ?>
  </div>
  <?php endif; ?>
</div>

<div class="ig-archive-viewer" id="archiveViewer" aria-hidden="true">
  <div class="ig-archive-sheet" role="dialog" aria-modal="true" aria-label="Archived item">
    <div class="ig-archive-sheet-preview" id="archiveViewerPreview"></div>
    <div class="ig-archive-sheet-actions">
      <button type="button" class="ig-archive-sheet-btn is-danger" id="archiveUnarchiveBtn">Unarchive</button>
      <button type="button" class="ig-archive-sheet-btn" id="archiveViewerClose">Cancel</button>
    </div>
  </div>
</div>
<div class="ig-archive-toast" id="archiveToast" role="status" aria-live="polite"></div>

<script>
(function(){
  var viewer = document.getElementById('archiveViewer');
  var preview = document.getElementById('archiveViewerPreview');
  var unBtn = document.getElementById('archiveUnarchiveBtn');
  var closeBtn = document.getElementById('archiveViewerClose');
  var toastEl = document.getElementById('archiveToast');
  var nextBtn = document.getElementById('igStoriesNext');
  var track = document.getElementById('igStoriesTrack');
  var activeId = 0;
  var toastTimer = 0;

  function toast(msg){
    if(!toastEl) return;
    toastEl.textContent = String(msg || '');
    toastEl.classList.add('is-on');
    if(toastTimer) window.clearTimeout(toastTimer);
    toastTimer = window.setTimeout(function(){ toastEl.classList.remove('is-on'); }, 2200);
  }

  function closeViewer(){
    activeId = 0;
    if(viewer){
      viewer.classList.remove('is-open');
      viewer.setAttribute('aria-hidden', 'true');
    }
    if(preview) preview.innerHTML = '';
    if(unBtn) unBtn.disabled = false;
  }

  function openMedia(src, type, caption, postId){
    activeId = Number(postId || 0);
    if(!viewer || !preview || !activeId) return;
    preview.innerHTML = '';
    src = String(src || '');
    type = String(type || 'text');
    caption = String(caption || '');
    if(type === 'video' && src){
      var v = document.createElement('video');
      v.src = src; v.controls = true; v.playsInline = true; v.autoplay = true; v.muted = true;
      preview.appendChild(v);
    } else if(src){
      var img = document.createElement('img');
      img.src = src; img.alt = '';
      preview.appendChild(img);
    } else {
      var fall = document.createElement('div');
      fall.className = 'ig-archive-fallback';
      fall.innerHTML = '<span></span>';
      fall.querySelector('span').textContent = caption || 'Story';
      preview.appendChild(fall);
    }
    viewer.classList.add('is-open');
    viewer.setAttribute('aria-hidden', 'false');
  }

  function openStoryCircle(btn){
    if(!btn) return;
    var src = String(btn.getAttribute('data-src') || '');
    var type = String(btn.getAttribute('data-type') || 'text');
    var caption = String(btn.getAttribute('data-caption') || '');
    var postId = Number(btn.getAttribute('data-post-id') || 0);
    if(postId > 0 && (src || caption)){
      openMedia(src, type, caption, postId);
      return;
    }
    var raw = btn.getAttribute('data-story-slides') || '[]';
    var slides = [];
    try{ slides = JSON.parse(raw) || []; }catch(e){ slides = []; }
    if(!slides.length) return;
    var first = slides[0] || {};
    openMedia(first.src || '', first.type || 'text', first.caption || '', first.postId || 0);
  }

  function showStoriesEmpty(){
    if(!track) return;
    track.innerHTML = ''
      + '<div class="ig-story-item ig-story-empty" role="status" aria-label="No archived stories">'
      + '<div class="ig-story-ring ig-story-ring-empty"><span class="ig-story-empty-icon" aria-hidden="true"><i class="icon ion-ios-book-outline"></i></span></div>'
      + '</div>';
    track.classList.add('is-empty');
    track.classList.remove('has-create');
    var bar = track.closest('.ig-stories-bar');
    if(bar) bar.classList.add('is-empty');
    if(nextBtn) nextBtn.style.display = 'none';
  }

  function removePostEverywhere(postId){
    postId = String(postId || '');
    document.querySelectorAll('.ig-story-item[data-post-id="'+postId+'"]').forEach(function(el){
      try{ el.remove(); }catch(e){}
    });
    document.querySelectorAll('#archivePostList .ig-archive-tile[data-post-id="'+postId+'"]').forEach(function(el){
      try{ el.remove(); }catch(e){}
    });

    if(track && !track.querySelector('.ig-story-item[data-story-key]')){
      showStoriesEmpty();
    }

    var postList = document.getElementById('archivePostList');
    if(postList && !postList.querySelector('.ig-archive-tile')){
      var postSection = postList.closest('.ig-archive-section');
      if(postSection) postSection.remove();
      else {
        var ptitle = postList.previousElementSibling;
        if(ptitle && ptitle.classList.contains('ig-archive-section-title')) ptitle.remove();
        postList.remove();
      }
    }
  }

  function unarchive(postId){
    postId = Number(postId || 0);
    if(!postId) return;
    if(unBtn) unBtn.disabled = true;
    var body = new URLSearchParams({ ajax:'archive', post_id:String(postId), archived:'0' });
    fetch('feed_api.php', {
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
      credentials:'same-origin',
      body: body
    }).then(function(r){ return r.json(); }).then(function(res){
      if(!res || res.ok === false){
        if(unBtn) unBtn.disabled = false;
        toast((res && res.error) ? String(res.error) : 'Could not unarchive.');
        return;
      }
      removePostEverywhere(postId);
      closeViewer();
      toast('Restored to your feed.');
    }).catch(function(){
      if(unBtn) unBtn.disabled = false;
      toast('Network error. Try again.');
    });
  }

  document.addEventListener('click', function(e){
    var storyCircle = e.target && e.target.closest ? e.target.closest('.ig-story-item[data-story-key]') : null;
    if(storyCircle){
      e.preventDefault();
      openStoryCircle(storyCircle);
      return;
    }
    var tile = e.target && e.target.closest ? e.target.closest('.ig-archive-tile, .ig-archive-post-open') : null;
    if(tile){
      e.preventDefault();
      openMedia(
        tile.getAttribute('data-src'),
        tile.getAttribute('data-type'),
        tile.getAttribute('data-caption'),
        tile.getAttribute('data-post-id')
      );
      return;
    }
    var un = e.target && e.target.closest ? e.target.closest('.js-unarchive') : null;
    if(un){
      e.preventDefault();
      unarchive(un.getAttribute('data-post-id'));
    }
  });

  if(nextBtn && track){
    nextBtn.addEventListener('click', function(){
      track.scrollBy({ left: 140, behavior: 'smooth' });
    });
  }
  if(closeBtn) closeBtn.addEventListener('click', closeViewer);
  if(unBtn) unBtn.addEventListener('click', function(){ unarchive(activeId); });
  if(viewer){
    viewer.addEventListener('click', function(e){
      if(e.target === viewer) closeViewer();
    });
  }
  document.addEventListener('keydown', function(e){
    if(e.key === 'Escape' && viewer && viewer.classList.contains('is-open')) closeViewer();
  });
})();
</script>
</body>
</html>
