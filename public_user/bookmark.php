<?php
declare(strict_types=1);

/**
 * Private bookmarks — stories + posts saved from feed / public / reel / profile.
 */
require_once __DIR__ . '/includes/session_user.php';
requireUserLogin();
require_once __DIR__ . '/controller.php';
require_once __DIR__ . '/includes/theme_prefs.php';
require_once __DIR__ . '/includes/device_profile.php';
require_once __DIR__ . '/includes/post_layout.php';
require_once __DIR__ . '/includes/bookmark_posts.php';
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

$posts = msb_bookmark_fetch_posts($dbh, $meId, 200);
$backUrl = 'profile.php?tab=gear';

$storyPosts = [];
$feedPosts = [];
foreach ($posts as $post) {
    if (!empty($post['saved_as_story']) || !empty($post['is_story'])) {
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

if (!function_exists('msb_bookmark_avatar_url')) {
    function msb_bookmark_avatar_url(array $user, int $size = 96): string
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

$avatarUrl = msb_bookmark_avatar_url($meUser, 96);

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
    $badge = msb_bookmark_date_badge((string)($post['saved_at'] ?? $post['created_at'] ?? ''));
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
    ];
}
$hasStories = count($storyCircles) > 0;
$totalCount = count($posts);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta http-equiv="Cache-Control" content="no-store, no-cache, must-revalidate">
  <title>Bookmarks</title>
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
    html[data-theme="dark"], html.dark-auto, html[data-msb-appearance].msb-appearance-dark{
      --ig-bg:var(--msb-palette-bg, #000);
      --ig-text:var(--msb-palette-text, #f5f5f5);
      --ig-muted:var(--msb-palette-text-muted, #a8a8a8);
      --ig-line:var(--msb-palette-border, #262626);
      --ig-tile:#121212;
      --ig-sheet:var(--msb-palette-bg, #1a1a1a);
    }
    *{box-sizing:border-box}
    html,body{margin:0;padding:0;height:100%;overflow:hidden}
    body{
      font-family:"Figtree",ui-sans-serif,system-ui,sans-serif;
      background:var(--ig-bg);
      color:var(--ig-text);
      -webkit-font-smoothing:antialiased;
    }
    html[data-msb-appearance] body,
    html[data-theme="dark"] body,
    html.dark-auto body,
    html[data-msb-appearance] .ig-bookmark,
    html[data-theme="dark"] .ig-bookmark,
    html.dark-auto .ig-bookmark{
      background:var(--msb-palette-bg, var(--ig-bg)) !important;
      color:var(--msb-palette-text, var(--ig-text));
    }
    a{color:inherit;text-decoration:none}
    button{font:inherit}

    .ig-bookmark{
      max-width:540px;margin:0 auto;height:100%;height:100dvh;
      display:flex;flex-direction:column;overflow:hidden;background:var(--ig-bg);
    }
    .ig-bookmark-top{
      flex:0 0 auto;z-index:20;background:var(--ig-bg);
      padding:calc(10px + env(safe-area-inset-top)) 0 0;
    }
    .ig-bookmark-head{
      display:flex;align-items:center;gap:10px;min-height:44px;padding:0 16px 8px;
    }
    .ig-bookmark-back{
      width:40px;height:40px;display:inline-flex;align-items:center;justify-content:center;
      border:0;background:transparent;color:var(--ig-text);border-radius:999px;cursor:pointer;padding:0;
    }
    .ig-bookmark-back:hover{background:rgba(127,127,127,.12)}
    .ig-bookmark-back svg{width:24px;height:24px;display:block}
    .ig-bookmark-title{font-size:22px;font-weight:700;letter-spacing:-.02em;line-height:1.1}
    .ig-bookmark-count{
      margin-left:auto;font-size:12px;font-weight:700;color:var(--ig-muted);
    }

    .ig-bookmark-stories-label,
    .ig-bookmark-section-title{
      margin:0 16px 8px;font-size:13px;font-weight:800;letter-spacing:.04em;
      text-transform:uppercase;color:var(--ig-muted);
    }
    .ig-bookmark-note{
      margin:8px 16px 0;font-size:12px;font-weight:500;line-height:1.45;color:var(--ig-muted);
    }
    .ig-stories-wrap{position:relative;padding:8px 0 14px}
    .ig-stories-bar{display:flex;align-items:center;padding:0 8px}
    .ig-stories-track{
      display:flex;align-items:flex-start;gap:14px;overflow-x:auto;overflow-y:hidden;
      flex:1 1 auto;min-width:0;scrollbar-width:none;padding:2px 6px 4px;
    }
    .ig-stories-track::-webkit-scrollbar{display:none}
    .ig-story-item{
      flex:0 0 auto;width:var(--msb-top-story-item);text-align:center;cursor:pointer;
      border:0;padding:0;background:transparent;font:inherit;color:inherit;
    }
    .ig-story-ring{
      width:var(--msb-top-story-ring);height:var(--msb-top-story-ring);margin:0 auto 6px;padding:2px;
      border-radius:50%;background:linear-gradient(45deg,#f58529,#dd2a7b,#8134af,#515bd4);box-sizing:border-box;
    }
    .ig-story-thumb{
      display:block;width:100%;height:100%;border-radius:50%;border:2px solid var(--ig-bg);
      object-fit:cover;background:#efefef;box-sizing:border-box;
    }
    .ig-story-ring-text{
      display:flex;align-items:center;justify-content:center;width:100%;height:100%;
      border-radius:50%;border:2px solid var(--ig-bg);padding:6px;font-size:10px;font-weight:700;
      text-align:center;color:var(--ig-text);background:rgba(127,127,127,.14);overflow:hidden;box-sizing:border-box;
    }
    .ig-story-name{
      display:block;max-width:var(--msb-top-story-item);margin:0 auto;font-size:12px;line-height:1.2;
      white-space:nowrap;overflow:hidden;text-overflow:ellipsis;
    }
    .ig-stories-track.is-empty{justify-content:center;min-height:74px}
    .ig-story-empty{cursor:default;pointer-events:none}
    .ig-story-ring-empty{background:rgba(127,127,127,.16)!important;background-image:none!important}
    .ig-story-empty-icon{
      display:flex;align-items:center;justify-content:center;width:100%;height:100%;
      border-radius:50%;border:2px solid var(--ig-bg);background:#f2f4f7;color:#98a2b3;font-size:26px;box-sizing:border-box;
    }

    .ig-bookmark-body{
      flex:1 1 auto;min-height:0;display:flex;flex-direction:column;overflow:hidden;
      margin-top:18px;padding-top:18px;border-top:8px solid var(--ig-line);
    }
    .ig-bookmark-section{flex:1 1 auto;min-height:0;display:flex;flex-direction:column}
    .ig-bookmark-posts-meta{flex:0 0 auto;background:var(--ig-bg)}
    .ig-bookmark-grid-scroll{
      flex:1 1 auto;min-height:0;overflow-x:hidden;overflow-y:auto;
      -webkit-overflow-scrolling:touch;padding-bottom:calc(16px + env(safe-area-inset-bottom));
    }
    .ig-bookmark-grid{
      display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:2px;padding:0 1px 8px;
    }
    .ig-bookmark-tile{
      position:relative;aspect-ratio:1/1;background:var(--ig-tile);overflow:hidden;
      border:0;padding:0;cursor:pointer;color:#fff;text-align:left;
    }
    .ig-bookmark-media{
      position:absolute;inset:0;width:100%;height:100%;object-fit:cover;display:block;background:#1a1a1a;
    }
    .ig-bookmark-fallback{
      position:absolute;inset:0;display:flex;align-items:flex-end;padding:12px 10px;
      background:linear-gradient(180deg,#2a2a2a 0%,#111 100%);
      color:#fff;font-size:12px;font-weight:600;line-height:1.35;
    }
    .ig-bookmark-fallback span{
      display:-webkit-box;-webkit-line-clamp:4;-webkit-box-orient:vertical;overflow:hidden;
    }
    .ig-bookmark-date{
      position:absolute;top:8px;left:8px;z-index:2;min-width:42px;padding:5px 7px 4px;
      border-radius:6px;background:var(--ig-badge);color:var(--ig-badge-text);
      box-shadow:0 1px 2px rgba(0,0,0,.18);line-height:1.05;pointer-events:none;
    }
    .ig-bookmark-date-day{display:block;font-size:15px;font-weight:800}
    .ig-bookmark-date-month{display:block;font-size:9px;font-weight:700;text-transform:uppercase;opacity:.85;margin-top:1px}
    .ig-bookmark-video-mark{
      position:absolute;top:8px;right:8px;z-index:2;width:22px;height:22px;border-radius:999px;
      background:rgba(0,0,0,.45);display:grid;place-items:center;pointer-events:none;
    }
    .ig-bookmark-video-mark svg{width:11px;height:11px;fill:#fff}
    .ig-bookmark-empty{
      padding:48px 28px 40px;text-align:center;color:var(--ig-muted);
    }
    .ig-bookmark-empty strong{display:block;color:var(--ig-text);font-size:18px;font-weight:700;margin-bottom:8px}
    .ig-bookmark-empty p{margin:0;font-size:13px;font-weight:500;line-height:1.5}

    .ig-bookmark-viewer{
      position:fixed;inset:0;z-index:1000;display:none;align-items:flex-end;justify-content:center;
      background:rgba(0,0,0,.55);padding:16px 12px calc(16px + env(safe-area-inset-bottom));
    }
    .ig-bookmark-viewer.is-open{display:flex}
    .ig-bookmark-sheet{
      width:fit-content;max-width:min(100%,420px);min-width:min(100%,260px);
      background:var(--ig-sheet);color:var(--ig-text);border-radius:18px;overflow:hidden;
      box-shadow:0 18px 50px rgba(0,0,0,.35);
    }
    .ig-bookmark-sheet-preview{width:auto;max-width:100%;margin:0;line-height:0}
    .ig-bookmark-sheet-preview img,
    .ig-bookmark-sheet-preview video{
      width:auto;height:auto;max-width:min(92vw,420px);max-height:min(72svh,720px);
      object-fit:contain;display:block;background:transparent;
    }
    .ig-bookmark-sheet-preview .ig-bookmark-fallback{
      width:min(92vw,420px);min-height:160px;font-size:15px;padding:18px;box-sizing:border-box;
    }
    .ig-bookmark-sheet-actions{display:flex;flex-direction:column;width:100%}
    .ig-bookmark-sheet-btn{
      width:100%;border:0;background:transparent;color:var(--ig-text);
      padding:15px 16px;font-size:15px;font-weight:600;border-top:1px solid var(--ig-line);
      cursor:pointer;text-align:center;
    }
    .ig-bookmark-sheet-btn.is-danger{color:var(--ig-danger)}
    .ig-bookmark-sheet-btn:disabled{opacity:.55;cursor:wait}
    .ig-bookmark-toast{
      position:fixed;left:50%;bottom:28px;transform:translateX(-50%);
      background:#262626;color:#fff;padding:11px 16px;border-radius:999px;
      font-size:13px;font-weight:600;z-index:1100;opacity:0;pointer-events:none;
      transition:opacity .2s ease;max-width:min(92vw,360px);text-align:center;
    }
    .ig-bookmark-toast.is-on{opacity:1}
    @media (min-width:700px){
      .ig-bookmark{max-width:720px;border-left:1px solid var(--ig-line);border-right:1px solid var(--ig-line)}
      .ig-bookmark-grid{gap:3px;padding:0 2px 18px}
    }
    @media (min-width:1024px){
      .ig-bookmark-viewer{align-items:center;padding:32px}
      .ig-bookmark-sheet{max-width:min(680px,70vw);border-radius:22px}
      .ig-bookmark-sheet-preview img,
      .ig-bookmark-sheet-preview video{max-width:min(70vw,680px);max-height:min(78svh,960px)}
    }
  </style>
</head>
<body>
<div class="ig-bookmark">
  <header class="ig-bookmark-top">
    <div class="ig-bookmark-head">
      <a class="ig-bookmark-back" href="<?= msb_bookmark_h($backUrl) ?>" aria-label="Back to Settings">
        <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
          <path d="M15.5 4.5 8 12l7.5 7.5" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
      </a>
      <h1 class="ig-bookmark-title">Bookmarks</h1>
      <span class="ig-bookmark-count"><?= (int)$totalCount ?> saved</span>
    </div>

    <div class="ig-bookmark-stories-block">
      <div class="ig-bookmark-stories-label">Stories</div>
      <div class="ig-stories-wrap">
        <div class="ig-stories-bar<?= $hasStories ? '' : ' is-empty' ?>" aria-label="Bookmarked stories">
          <div class="ig-stories-track<?= $hasStories ? '' : ' is-empty' ?>" id="igBookmarkStoriesTrack">
            <?php if (!$hasStories): ?>
              <div class="ig-story-item ig-story-empty" role="status" aria-label="No bookmarked stories">
                <div class="ig-story-ring ig-story-ring-empty">
                  <span class="ig-story-empty-icon" aria-hidden="true"><i class="icon ion-ios-bookmarks-outline"></i></span>
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
                ?>
                <button
                  type="button"
                  class="ig-story-item"
                  data-story-key="s<?= $cid ?>"
                  data-post-id="<?= $cid ?>"
                  data-src="<?= msb_bookmark_h($cSrc) ?>"
                  data-type="<?= msb_bookmark_h($cType) ?>"
                  data-caption="<?= msb_bookmark_h($cCap) ?>"
                  aria-label="Open bookmarked story <?= msb_bookmark_h($cLabel) ?>"
                >
                  <div class="ig-story-ring">
                    <?php if ($cType === 'video' && $cSrc !== ''): ?>
                      <video class="ig-story-thumb" src="<?= msb_bookmark_h($cSrc) ?>" muted playsinline preload="metadata"></video>
                    <?php elseif ($cSrc !== ''): ?>
                      <img class="ig-story-thumb" src="<?= msb_bookmark_h($cSrc) ?>" alt="">
                    <?php else: ?>
                      <span class="ig-story-ring-text"><?= msb_bookmark_h(function_exists('mb_substr') ? (string)mb_substr($cCap, 0, 18) : substr($cCap, 0, 18)) ?></span>
                    <?php endif; ?>
                  </div>
                  <span class="ig-story-name"><?= msb_bookmark_h($cLabel) ?></span>
                </button>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <p class="ig-bookmark-note">Bookmark a story from the story door menu to keep it here. Only you can see these.</p>
    </div>
  </header>

  <div class="ig-bookmark-body">
    <?php if ($feedPosts): ?>
      <section class="ig-bookmark-section" aria-label="Bookmarked posts">
        <div class="ig-bookmark-posts-meta">
          <div class="ig-bookmark-section-title">Posts</div>
          <p class="ig-bookmark-note" style="margin-top:0;margin-bottom:12px;">Saved from For You, Discover, Reels, or Profile post menus.</p>
        </div>
        <div class="ig-bookmark-grid-scroll">
          <div class="ig-bookmark-grid" id="bookmarkPostList">
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
                $badge = msb_bookmark_date_badge((string)($post['saved_at'] ?? $post['created_at'] ?? ''));
                $openCaption = $caption !== '' ? $caption : $title;
              ?>
              <button
                type="button"
                class="ig-bookmark-tile"
                data-post-id="<?= $pid ?>"
                data-src="<?= msb_bookmark_h($previewSrc) ?>"
                data-type="<?= msb_bookmark_h($isVideo ? 'video' : ($previewSrc !== '' ? 'image' : 'text')) ?>"
                data-caption="<?= msb_bookmark_h($openCaption) ?>"
                aria-label="Bookmarked post"
              >
                <?php if ($badge['day'] !== ''): ?>
                  <span class="ig-bookmark-date">
                    <span class="ig-bookmark-date-day"><?= msb_bookmark_h($badge['day']) ?></span>
                    <span class="ig-bookmark-date-month"><?= msb_bookmark_h($badge['month']) ?></span>
                  </span>
                <?php endif; ?>
                <?php if ($isVideo && $previewSrc !== ''): ?>
                  <span class="ig-bookmark-video-mark" aria-hidden="true">
                    <svg viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg>
                  </span>
                  <video class="ig-bookmark-media" src="<?= msb_bookmark_h($previewSrc) ?>" muted playsinline preload="metadata"></video>
                <?php elseif ($previewSrc !== ''): ?>
                  <img class="ig-bookmark-media" src="<?= msb_bookmark_h($previewSrc) ?>" alt="">
                <?php else: ?>
                  <div class="ig-bookmark-fallback"><span><?= msb_bookmark_h($openCaption) ?></span></div>
                <?php endif; ?>
              </button>
            <?php endforeach; ?>
          </div>
        </div>
      </section>
    <?php elseif (!$hasStories): ?>
      <div class="ig-bookmark-empty" role="status">
        <strong>No bookmarks yet</strong>
        <p>Use Bookmark on a post card or story menu in For You, Discover, Reels, or Profile. Find them again here under Settings.</p>
      </div>
    <?php else: ?>
      <div class="ig-bookmark-empty" role="status">
        <strong>No bookmarked posts</strong>
        <p>Your bookmarked stories are above. Save a post card to see it in this grid.</p>
      </div>
    <?php endif; ?>
  </div>
</div>

<div class="ig-bookmark-viewer" id="bookmarkViewer" aria-hidden="true">
  <div class="ig-bookmark-sheet" role="dialog" aria-modal="true" aria-label="Bookmarked item">
    <div class="ig-bookmark-sheet-preview" id="bookmarkViewerPreview"></div>
    <div class="ig-bookmark-sheet-actions">
      <button type="button" class="ig-bookmark-sheet-btn is-danger" id="bookmarkRemoveBtn">Remove bookmark</button>
      <button type="button" class="ig-bookmark-sheet-btn" id="bookmarkViewerClose">Cancel</button>
    </div>
  </div>
</div>
<div class="ig-bookmark-toast" id="bookmarkToast" role="status" aria-live="polite"></div>

<script>
(function(){
  var viewer = document.getElementById('bookmarkViewer');
  var preview = document.getElementById('bookmarkViewerPreview');
  var removeBtn = document.getElementById('bookmarkRemoveBtn');
  var closeBtn = document.getElementById('bookmarkViewerClose');
  var toastEl = document.getElementById('bookmarkToast');
  var track = document.getElementById('igBookmarkStoriesTrack');
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
    if(removeBtn) removeBtn.disabled = false;
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
      fall.className = 'ig-bookmark-fallback';
      fall.innerHTML = '<span></span>';
      fall.querySelector('span').textContent = caption || 'Saved item';
      preview.appendChild(fall);
    }
    viewer.classList.add('is-open');
    viewer.setAttribute('aria-hidden', 'false');
  }

  function removeEverywhere(postId){
    postId = String(postId || '');
    document.querySelectorAll('.ig-story-item[data-post-id="'+postId+'"], .ig-bookmark-tile[data-post-id="'+postId+'"]').forEach(function(el){
      try{ el.remove(); }catch(e){}
    });
    if(track && !track.querySelector('.ig-story-item[data-story-key]')){
      track.innerHTML = ''
        + '<div class="ig-story-item ig-story-empty" role="status" aria-label="No bookmarked stories">'
        + '<div class="ig-story-ring ig-story-ring-empty"><span class="ig-story-empty-icon" aria-hidden="true"><i class="icon ion-ios-bookmarks-outline"></i></span></div>'
        + '</div>';
      track.classList.add('is-empty');
      var bar = track.closest('.ig-stories-bar');
      if(bar) bar.classList.add('is-empty');
    }
    var postList = document.getElementById('bookmarkPostList');
    if(postList && !postList.querySelector('.ig-bookmark-tile')){
      var section = postList.closest('.ig-bookmark-section');
      if(section) section.remove();
    }
    var countEl = document.querySelector('.ig-bookmark-count');
    if(countEl){
      var left = document.querySelectorAll('.ig-story-item[data-story-key], .ig-bookmark-tile[data-post-id]').length;
      countEl.textContent = left + ' saved';
    }
  }

  function removeBookmark(postId){
    postId = Number(postId || 0);
    if(!postId) return;
    if(removeBtn) removeBtn.disabled = true;
    var body = new URLSearchParams({ ajax:'save', post_id:String(postId) });
    fetch('feed_api.php', {
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
      credentials:'same-origin',
      body: body
    }).then(function(r){ return r.json(); }).then(function(res){
      if(!res || res.ok === false){
        if(removeBtn) removeBtn.disabled = false;
        toast((res && res.error) ? String(res.error) : 'Could not remove bookmark.');
        return;
      }
      var stillSaved = Number(res.state && res.state.saved != null ? res.state.saved : 0) === 1;
      if(stillSaved){
        if(removeBtn) removeBtn.disabled = false;
        toast('Could not remove bookmark.');
        return;
      }
      removeEverywhere(postId);
      closeViewer();
      toast('Bookmark removed.');
    }).catch(function(){
      if(removeBtn) removeBtn.disabled = false;
      toast('Network error. Try again.');
    });
  }

  document.addEventListener('click', function(e){
    var story = e.target && e.target.closest ? e.target.closest('.ig-story-item[data-story-key]') : null;
    if(story){
      e.preventDefault();
      openMedia(story.getAttribute('data-src'), story.getAttribute('data-type'), story.getAttribute('data-caption'), story.getAttribute('data-post-id'));
      return;
    }
    var tile = e.target && e.target.closest ? e.target.closest('.ig-bookmark-tile') : null;
    if(tile){
      e.preventDefault();
      openMedia(tile.getAttribute('data-src'), tile.getAttribute('data-type'), tile.getAttribute('data-caption'), tile.getAttribute('data-post-id'));
    }
  });

  if(closeBtn) closeBtn.addEventListener('click', closeViewer);
  if(removeBtn) removeBtn.addEventListener('click', function(){ removeBookmark(activeId); });
  if(viewer){
    viewer.addEventListener('click', function(e){
      if(e.target === viewer) closeViewer();
    });
  }
  document.addEventListener('keydown', function(e){
    if(e.key === 'Escape') closeViewer();
  });
})();
</script>
</body>
</html>
