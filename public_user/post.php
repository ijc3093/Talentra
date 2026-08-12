<?php
declare(strict_types=1);

/**
 * Canonical single-post deep link.
 * Multi-media / slide-caption posts use a presentation layout:
 * media carousel (left) + title/body (right), synced per slide.
 */
require_once __DIR__ . '/includes/session_user.php';
requireUserLogin();
require_once __DIR__ . '/controller.php';
require_once __DIR__ . '/includes/theme_prefs.php';
require_once __DIR__ . '/includes/post_layout.php';
require_once __DIR__ . '/includes/staff_publisher_access.php';
if (is_file(__DIR__ . '/includes/friend_system.php')) {
    require_once __DIR__ . '/includes/friend_system.php';
}
if (is_file(__DIR__ . '/includes/post_upload.php')) {
    require_once __DIR__ . '/includes/post_upload.php';
}

$postId = (int)($_GET['id'] ?? $_GET['post'] ?? 0);
if ($postId <= 0) {
    header('Location: public.php');
    exit;
}

$controller = new Controller();
$dbh = $controller->pdo();
$meId = (int)($_SESSION['user_id'] ?? 0);
try {
    $viewerId = theme_prefs_viewer_user_id();
    if ($viewerId > 0) {
        $meId = $viewerId;
    }
} catch (Throwable $e) {
}

$error = '';
$post = null;
$attachments = [];

try {
    $layoutSelect = post_layout_select_sql($dbh);
    $st = $dbh->prepare("
      SELECT
        p.id,
        p.user_id,
        COALESCE(p.title,'') AS title,
        COALESCE(p.description,'') AS description,
        COALESCE(p.body,'') AS body,
        COALESCE(p.visibility,'public') AS visibility,
        {$layoutSelect}
        COALESCE(p.is_archived,0) AS is_archived,
        COALESCE(p.is_deleted,0) AS is_deleted,
        p.created_at,
        COALESCE(u.name,'') AS author_name,
        COALESCE(u.username,'') AS author_username,
        COALESCE(u.friend_code,'') AS friend_code,
        COALESCE(u.image,'') AS author_image
      FROM public_posts p
      JOIN users u ON u.id = p.user_id
      WHERE p.id = :id AND COALESCE(p.is_deleted,0) = 0
      LIMIT 1
    ");
    $st->execute([':id' => $postId]);
    $post = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($post && trim((string)($post['declared_layout'] ?? '')) === '') {
        $post['declared_layout'] = post_declared_layout($post);
    }
} catch (Throwable $e) {
    $post = null;
}

if (!$post) {
    $error = 'Post not found.';
} else {
    $authorId = (int)($post['user_id'] ?? 0);
    if (!empty($post['is_archived']) && $authorId !== $meId) {
        $error = 'Post not found.';
        $post = null;
    } else {
        $vis = strtolower(trim((string)($post['visibility'] ?? 'public')));
        if ($vis === 'friends' && $authorId !== $meId) {
            $areFriends = false;
            if (function_exists('fs_are_friends')) {
                $areFriends = fs_are_friends($dbh, $meId, $authorId);
            } else {
                try {
                    $stF = $dbh->prepare("
                      SELECT 1 FROM friendships
                      WHERE status = 'accepted'
                        AND ((user_id = :a AND friend_id = :b) OR (user_id = :b2 AND friend_id = :a2))
                      LIMIT 1
                    ");
                    $stF->execute([':a' => $meId, ':b' => $authorId, ':a2' => $meId, ':b2' => $authorId]);
                    $areFriends = (bool)$stF->fetchColumn();
                } catch (Throwable $e) {
                    $areFriends = false;
                }
            }
            if (!$areFriends) {
                $error = 'You do not have access to this post.';
                $post = null;
            }
        }
    }
}

if ($post) {
    try {
        if (function_exists('post_attachments_ensure_slide_columns')) {
            post_attachments_ensure_slide_columns($dbh);
        }
        $stA = $dbh->prepare("
          SELECT id, type, file_path, thumb_path, slide_title, slide_body
          FROM public_post_attachments
          WHERE post_id = :pid
          ORDER BY id ASC
        ");
        $stA->execute([':pid' => $postId]);
        $attachments = $stA->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        try {
            $stA = $dbh->prepare("
              SELECT id, type, file_path, thumb_path
              FROM public_post_attachments
              WHERE post_id = :pid
              ORDER BY id ASC
            ");
            $stA->execute([':pid' => $postId]);
            $attachments = $stA->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e2) {
            $attachments = [];
        }
    }
    foreach ($attachments as &$attRow) {
        $attRow['slide_title'] = (string)($attRow['slide_title'] ?? '');
        $attRow['slide_body'] = (string)($attRow['slide_body'] ?? '');
    }
    unset($attRow);
}

function msb_post_h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function msb_post_media_url(string $path): string
{
    $path = trim($path);
    if ($path === '') {
        return '';
    }
    if (preg_match('~^(https?:)?//~i', $path) || (isset($path[0]) && $path[0] === '/')) {
        return $path;
    }
    return './' . ltrim($path, './');
}

$postTitle = '';
$postBody = '';
$authorName = 'User';
$authorUser = '';
$avatarUrl = 'avatar.php?s=96';
$createdLabel = '';
$backUrl = 'public.php';
$openInFeedUrl = 'public.php';
$slidePresentation = false;
$mediaCount = 0;
$useSplitLayout = false;
$initialTitle = '';
$initialBody = '';

if ($post) {
    $postTitle = trim((string)($post['title'] ?? ''));
    $postBody = trim((string)(($post['body'] ?? '') !== '' ? $post['body'] : ($post['description'] ?? '')));
    if (function_exists('post_strip_layout_marker')) {
        $postBody = post_strip_layout_marker($postBody);
        $postTitle = post_strip_layout_marker($postTitle);
    }

    $authorName = trim((string)($post['author_name'] ?? ''));
    $authorUser = trim((string)($post['author_username'] ?? ''));
    if ($authorName === '') {
        $authorName = $authorUser !== '' ? $authorUser : 'User';
    }
    $avatarUrl = 'avatar.php?u=' . (int)$post['user_id']
        . '&name=' . rawurlencode($authorName)
        . '&s=96';
    $ts = strtotime((string)($post['created_at'] ?? ''));
    $createdLabel = $ts ? date('M j, Y · g:i A', $ts) : '';
    $vis = strtolower(trim((string)($post['visibility'] ?? 'public')));
    $backUrl = ($vis === 'friends') ? 'feed.php' : 'public.php';
    if ((int)($post['user_id'] ?? 0) === $meId) {
        $backUrl = 'profile.php?tab=posts';
    }

    $declaredLayout = strtolower(trim((string)($post['declared_layout'] ?? '')));
    $mediaCount = is_array($attachments) ? count($attachments) : 0;
    $firstAttType = $mediaCount > 0
        ? strtolower(trim((string)($attachments[0]['type'] ?? '')))
        : '';
    $isSingleMedia = ($mediaCount === 1);
    $isVideoReel = ($declaredLayout === 'media_reel_bottom' && $isSingleMedia && $firstAttType === 'video');

    if ($isVideoReel && $vis !== 'friends') {
        $openInFeedUrl = 'reel.php?post=' . (int)$postId;
    } elseif ($vis === 'friends') {
        $openInFeedUrl = 'feed.php?post=' . (int)$postId;
    } else {
        $openInFeedUrl = 'public.php?post=' . (int)$postId . '#post-' . (int)$postId;
    }

    foreach ($attachments as $a) {
        if (trim((string)($a['slide_title'] ?? '')) !== '' || trim((string)($a['slide_body'] ?? '')) !== '') {
            $slidePresentation = true;
            break;
        }
    }

    // Split presentation UI when there is media (especially multi-slide / captioned).
    $useSplitLayout = ($mediaCount >= 1);

    if ($slidePresentation && $mediaCount > 0) {
        $initialTitle = trim((string)($attachments[0]['slide_title'] ?? ''));
        $initialBody = trim((string)($attachments[0]['slide_body'] ?? ''));
    } else {
        $initialTitle = $postTitle;
        $initialBody = $postBody;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title><?= $post ? msb_post_h($authorName . ' · Post') : 'Post' ?></title>
  <?php theme_prefs_print_head_bootstrap($dbh, $meId); ?>
  <link rel="stylesheet" href="lib/Ionicons/css/ionicons.css">
  <link href="./lib/font-awesome/css/font-awesome.css" rel="stylesheet">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Figtree:wght@500;600;700;800&display=swap" rel="stylesheet">
  <style>
    :root{
      --p-bg:#ecece4;
      --p-surface:#fff;
      --p-text:#111827;
      --p-muted:#6b7280;
      --p-line:rgba(15,23,42,.08);
      --p-accent:#1d9bf0;
      --p-nav:#1e3a5f;
    }
    *{box-sizing:border-box}
    html,body{margin:0;padding:0;min-height:100%}
    body{
      font-family:"Figtree",ui-sans-serif,system-ui,sans-serif;
      background:var(--p-bg);color:var(--p-text);
      -webkit-font-smoothing:antialiased;
    }
    .post-page{
      max-width:1180px;margin:0 auto;min-height:100dvh;
      display:flex;flex-direction:column;
      padding:calc(10px + env(safe-area-inset-top)) 16px calc(20px + env(safe-area-inset-bottom));
    }
    .post-head{
      display:flex;align-items:center;gap:8px;margin-bottom:12px;flex-shrink:0;
    }
    .post-back{
      width:40px;height:40px;border:0;border-radius:999px;background:transparent;color:var(--p-nav);
      display:inline-flex;align-items:center;justify-content:center;cursor:pointer;text-decoration:none;
    }
    .post-back:hover{background:rgba(30,58,95,.08)}
    .post-back svg{width:22px;height:22px}
    .post-head h1{margin:0;font-size:20px;font-weight:800;color:var(--p-nav);letter-spacing:-.01em}
    .post-empty{
      margin:auto;text-align:center;padding:40px 20px;color:var(--p-muted);
    }
    .post-empty strong{display:block;color:var(--p-text);font-size:18px;margin-bottom:8px}

    /* —— Presentation stage (matches uploaded split UI) —— */
    .post-stage{
      flex:1;min-height:0;
      display:grid;
      grid-template-columns:minmax(0,1.55fr) minmax(280px,.95fr);
      background:var(--p-bg);
      border:1px solid var(--p-line);
      border-radius:16px;
      overflow:hidden;
      min-height:min(78vh,820px);
    }
    .post-stage-media{
      position:relative;
      background:var(--p-bg);
      display:flex;flex-direction:column;min-width:0;min-height:0;
      border-right:1px solid rgba(15,23,42,.06);
    }
    .post-stage-author{
      display:flex;align-items:center;gap:10px;
      padding:14px 16px 8px;flex-shrink:0;
    }
    .post-stage-author img{
      width:40px;height:40px;border-radius:50%;object-fit:cover;background:#d9d9c8;flex-shrink:0;
    }
    .post-stage-author-name{font-size:15px;font-weight:800;line-height:1.15}
    .post-stage-author-sub{font-size:12px;font-weight:600;color:var(--p-muted);margin-top:2px}

    .post-carousel{
      position:relative;flex:1;min-height:280px;
      display:flex;align-items:center;justify-content:center;
      padding:8px 48px 40px;
    }
    .post-carousel-track{
      width:100%;height:100%;
      display:flex;align-items:center;justify-content:center;
      position:relative;
    }
    .post-slide{
      display:none;width:100%;height:100%;
      align-items:center;justify-content:center;
    }
    .post-slide.is-active{display:flex}
    .post-slide img,.post-slide video{
      max-width:100%;max-height:100%;
      width:auto;height:auto;
      object-fit:contain;display:block;
      border-radius:2px;
    }
    .post-nav{
      position:absolute;top:50%;transform:translateY(-50%);
      width:25px;height:25px;border:0;border-radius:999px;
      background:rgba(40,40,40,.45);color:#fff;
      display:inline-flex;align-items:center;justify-content:center;
      cursor:pointer;z-index:3;padding:0;
      transition:background .15s ease, opacity .15s ease;
    }
    .post-nav:hover{background:rgba(40,40,40,.65)}
    .post-nav[disabled]{opacity:.25;cursor:default;pointer-events:none}
    .post-nav-prev{left:10px}
    .post-nav-next{right:10px}
    .post-nav svg{width:18px;height:18px;display:block}
    .post-dots{
      position:absolute;left:0;right:0;bottom:14px;
      display:flex;justify-content:center;gap:7px;z-index:3;pointer-events:none;
    }
    .post-dot{
      width:8px;height:8px;border-radius:999px;border:0;padding:0;
      background:#fff;box-shadow:0 0 0 1px rgba(0,0,0,.12);
      pointer-events:auto;cursor:pointer;
    }
    .post-dot.is-active{background:var(--p-accent);box-shadow:none}

    .post-stage-copy{
      display:flex;flex-direction:column;
      padding:28px 28px 20px;
      min-width:0;background:var(--p-bg);
    }
    .post-stage-title{
      margin:0 0 14px;
      font-size:clamp(28px,3.2vw,42px);
      font-weight:800;line-height:1.1;letter-spacing:-.02em;
      color:var(--p-text);word-break:break-word;
    }
    .post-stage-title:empty{display:none}
    .post-stage-body{
      margin:0;flex:1;
      font-size:15px;font-weight:500;line-height:1.55;
      color:#374151;white-space:pre-wrap;word-break:break-word;
    }
    .post-stage-body:empty{display:none}
    .post-stage-actions{
      display:flex;gap:8px;flex-wrap:wrap;padding-top:18px;margin-top:auto;
    }
    .post-stage-actions a,.post-stage-actions button{
      display:inline-flex;align-items:center;gap:7px;height:36px;padding:0 14px;border-radius:999px;
      border:1px solid var(--p-line);background:rgba(255,255,255,.55);color:inherit;
      font:inherit;font-weight:700;font-size:13px;text-decoration:none;cursor:pointer;
    }

    /* Compact single-column fallback (no media) */
    .post-card{
      max-width:560px;width:100%;margin:0 auto;
      background:var(--p-surface);border:1px solid var(--p-line);border-radius:16px;overflow:hidden;
    }
    .post-author{display:flex;align-items:center;gap:10px;padding:14px}
    .post-author img{width:42px;height:42px;border-radius:50%;object-fit:cover;background:#e2e8f0}
    .post-author-name{font-size:15px;font-weight:800}
    .post-author-sub{font-size:12px;font-weight:600;color:var(--p-muted);margin-top:2px}
    .post-card-copy{padding:4px 16px 16px}
    .post-card-title{margin:0 0 8px;font-size:22px;font-weight:800}
    .post-card-body{margin:0;font-size:15px;font-weight:500;line-height:1.5;white-space:pre-wrap;word-break:break-word;color:#374151}
    .post-card-actions{display:flex;gap:8px;padding:0 14px 14px;flex-wrap:wrap}
    .post-card-actions a,.post-card-actions button{
      display:inline-flex;align-items:center;gap:7px;height:38px;padding:0 14px;border-radius:999px;
      border:1px solid var(--p-line);background:transparent;color:inherit;font:inherit;font-weight:800;font-size:13px;
      text-decoration:none;cursor:pointer;
    }

    @media (max-width:860px){
      .post-stage{
        grid-template-columns:1fr;
        min-height:0;
      }
      .post-stage-media{border-right:0;border-bottom:1px solid rgba(15,23,42,.06)}
      .post-carousel{min-height:52vw;padding:4px 44px 36px}
      .post-stage-copy{padding:20px 18px 16px;min-height:180px}
      .post-stage-title{font-size:28px}
    }
  </style>
</head>
<body>
  <div class="post-page">
    <header class="post-head">
      <a class="post-back" href="<?= msb_post_h($backUrl) ?>" aria-label="Back">
        <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
          <path d="M15.5 4.5 8 12l7.5 7.5" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
      </a>
      <h1>Post</h1>
    </header>

    <?php if (!$post): ?>
      <div class="post-empty" role="alert">
        <strong><?= msb_post_h($error !== '' ? $error : 'Post unavailable') ?></strong>
        <p>This link may be private, deleted, or no longer available.</p>
        <p><a href="<?= msb_post_h($backUrl) ?>">Go back</a></p>
      </div>
    <?php elseif ($useSplitLayout): ?>
      <article
        class="post-stage"
        data-post-id="<?= (int)$postId ?>"
        id="post-<?= (int)$postId ?>"
        data-slide-presentation="<?= $slidePresentation ? '1' : '0' ?>"
        data-legacy-title="<?= msb_post_h($postTitle) ?>"
        data-legacy-body="<?= msb_post_h($postBody) ?>"
      >
        <div class="post-stage-media">
          <div class="post-stage-author">
            <img src="<?= msb_post_h($avatarUrl) ?>" alt="">
            <div>
              <div class="post-stage-author-name"><?= msb_post_h($authorName) ?></div>
              <div class="post-stage-author-sub">
                <?php if ($authorUser !== ''): ?>@<?= msb_post_h($authorUser) ?> · <?php endif; ?>
                <?= msb_post_h($createdLabel) ?>
              </div>
            </div>
          </div>

          <div class="post-carousel js-post-carousel" data-index="0" data-count="<?= (int)$mediaCount ?>">
            <div class="post-carousel-track">
              <?php foreach ($attachments as $i => $att): ?>
                <?php
                  $type = strtolower(trim((string)($att['type'] ?? '')));
                  $src = msb_post_media_url((string)(($att['file_path'] ?? '') !== '' ? $att['file_path'] : ($att['thumb_path'] ?? '')));
                  if ($src === '') {
                      continue;
                  }
                  $sTitle = trim((string)($att['slide_title'] ?? ''));
                  $sBody = trim((string)($att['slide_body'] ?? ''));
                ?>
                <div
                  class="post-slide<?= $i === 0 ? ' is-active' : '' ?>"
                  data-slide-index="<?= (int)$i ?>"
                  data-slide-title="<?= msb_post_h($sTitle) ?>"
                  data-slide-body="<?= msb_post_h($sBody) ?>"
                >
                  <?php if ($type === 'video'): ?>
                    <video src="<?= msb_post_h($src) ?>" controls playsinline preload="metadata"></video>
                  <?php else: ?>
                    <img src="<?= msb_post_h($src) ?>" alt="">
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
            </div>

            <?php if ($mediaCount > 1): ?>
              <button type="button" class="post-nav post-nav-prev" data-post-prev aria-label="Previous">
                <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                  <path d="M15 5 8 12l7 7" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
              </button>
              <button type="button" class="post-nav post-nav-next" data-post-next aria-label="Next">
                <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                  <path d="M9 5l7 7-7 7" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
              </button>
              <div class="post-dots" role="tablist" aria-label="Slides">
                <?php for ($d = 0; $d < $mediaCount; $d++): ?>
                  <button type="button" class="post-dot<?= $d === 0 ? ' is-active' : '' ?>" data-post-dot="<?= (int)$d ?>" aria-label="Slide <?= (int)($d + 1) ?>"></button>
                <?php endfor; ?>
              </div>
            <?php endif; ?>
          </div>
        </div>

        <div class="post-stage-copy">
          <h2 class="post-stage-title" id="postStageTitle"><?= msb_post_h($initialTitle) ?></h2>
          <div class="post-stage-body" id="postStageBody"><?= nl2br(msb_post_h($initialBody)) ?></div>
          <div class="post-stage-actions">
            <a href="<?= msb_post_h($openInFeedUrl) ?>">Open in feed</a>
            <button type="button" id="postCopyLinkBtn"><i class="fa fa-link" aria-hidden="true"></i> Copy link</button>
          </div>
        </div>
      </article>
    <?php else: ?>
      <article class="post-card" data-post-id="<?= (int)$postId ?>" id="post-<?= (int)$postId ?>">
        <div class="post-author">
          <img src="<?= msb_post_h($avatarUrl) ?>" alt="">
          <div>
            <div class="post-author-name"><?= msb_post_h($authorName) ?></div>
            <div class="post-author-sub">
              <?php if ($authorUser !== ''): ?>@<?= msb_post_h($authorUser) ?> · <?php endif; ?>
              <?= msb_post_h($createdLabel) ?>
            </div>
          </div>
        </div>
        <?php if ($postTitle !== '' || $postBody !== ''): ?>
          <div class="post-card-copy">
            <?php if ($postTitle !== ''): ?><h2 class="post-card-title"><?= msb_post_h($postTitle) ?></h2><?php endif; ?>
            <?php if ($postBody !== ''): ?><div class="post-card-body"><?= nl2br(msb_post_h($postBody)) ?></div><?php endif; ?>
          </div>
        <?php endif; ?>
        <div class="post-card-actions">
          <a href="<?= msb_post_h($openInFeedUrl) ?>">Open in feed</a>
          <button type="button" id="postCopyLinkBtn"><i class="fa fa-link" aria-hidden="true"></i> Copy link</button>
        </div>
      </article>
    <?php endif; ?>
  </div>
  <script>
  (function(){
    function escHtml(s){
      return String(s || '')
        .replace(/&/g,'&amp;')
        .replace(/</g,'&lt;')
        .replace(/>/g,'&gt;')
        .replace(/"/g,'&quot;');
    }
    function bodyHtml(s){
      return escHtml(s).replace(/\r\n/g,'\n').replace(/\n/g,'<br>');
    }

    var stage = document.querySelector('.post-stage');
    var carousel = document.querySelector('.js-post-carousel');
    var titleEl = document.getElementById('postStageTitle');
    var bodyEl = document.getElementById('postStageBody');

    function setIndex(next){
      if(!carousel || !stage) return;
      var slides = Array.prototype.slice.call(carousel.querySelectorAll('.post-slide'));
      if(!slides.length) return;
      var count = slides.length;
      var idx = ((Number(next) % count) + count) % count;
      carousel.setAttribute('data-index', String(idx));
      slides.forEach(function(slide, i){
        slide.classList.toggle('is-active', i === idx);
        var vid = slide.querySelector('video');
        if(vid){
          try{
            if(i === idx){ vid.play().catch(function(){}); }
            else { vid.pause(); }
          }catch(e){}
        }
      });
      carousel.querySelectorAll('.post-dot').forEach(function(dot, i){
        dot.classList.toggle('is-active', i === idx);
      });
      var prevBtn = carousel.querySelector('[data-post-prev]');
      var nextBtn = carousel.querySelector('[data-post-next]');
      if(prevBtn) prevBtn.disabled = count <= 1;
      if(nextBtn) nextBtn.disabled = count <= 1;

      var active = slides[idx];
      var presentation = stage.getAttribute('data-slide-presentation') === '1';
      var t = '';
      var b = '';
      if(presentation && active){
        t = String(active.getAttribute('data-slide-title') || '').trim();
        b = String(active.getAttribute('data-slide-body') || '').trim();
      } else {
        t = String(stage.getAttribute('data-legacy-title') || '').trim();
        b = String(stage.getAttribute('data-legacy-body') || '').trim();
      }
      if(titleEl){
        titleEl.textContent = t;
        titleEl.style.display = t ? '' : 'none';
      }
      if(bodyEl){
        bodyEl.innerHTML = b ? bodyHtml(b) : '';
        bodyEl.style.display = b ? '' : 'none';
      }
    }

    if(carousel){
      var prev = carousel.querySelector('[data-post-prev]');
      var next = carousel.querySelector('[data-post-next]');
      if(prev){
        prev.addEventListener('click', function(){
          setIndex(Number(carousel.getAttribute('data-index') || 0) - 1);
        });
      }
      if(next){
        next.addEventListener('click', function(){
          setIndex(Number(carousel.getAttribute('data-index') || 0) + 1);
        });
      }
      carousel.querySelectorAll('[data-post-dot]').forEach(function(dot){
        dot.addEventListener('click', function(){
          setIndex(Number(dot.getAttribute('data-post-dot') || 0));
        });
      });
      setIndex(0);
    }

    var btn = document.getElementById('postCopyLinkBtn');
    if(btn){
      btn.addEventListener('click', function(){
        var url = window.location.href.split('#')[0];
        var done = function(){
          btn.textContent = 'Copied';
          setTimeout(function(){
            btn.innerHTML = '<i class="fa fa-link" aria-hidden="true"></i> Copy link';
          }, 1200);
        };
        if(navigator.clipboard && navigator.clipboard.writeText){
          navigator.clipboard.writeText(url).then(done).catch(function(){});
          return;
        }
        try{
          var ta = document.createElement('textarea');
          ta.value = url; document.body.appendChild(ta); ta.select(); document.execCommand('copy'); ta.remove(); done();
        }catch(e){}
      });
    }
  })();
  </script>
</body>
</html>
