<?php
declare(strict_types=1);

/**
 * Canonical single-post deep link.
 * Copy-link / pasted URLs land here on exactly one post card — no feed scroll hunting.
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
        $stA = $dbh->prepare("
          SELECT id, type, file_path, thumb_path
          FROM public_post_attachments
          WHERE post_id = :pid
          ORDER BY id ASC
        ");
        $stA->execute([':pid' => $postId]);
        $attachments = $stA->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $attachments = [];
    }
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

$caption = '';
$authorName = 'User';
$authorUser = '';
$avatarUrl = 'avatar.php?s=96';
$createdLabel = '';
$backUrl = 'public.php';
$openInFeedUrl = 'public.php';

if ($post) {
    $caption = trim((string)(($post['body'] ?? '') !== '' ? $post['body'] : ($post['description'] ?? '')));
    if ($caption === '') {
        $caption = trim((string)($post['title'] ?? ''));
    }
    if (function_exists('post_strip_layout_marker')) {
        $caption = post_strip_layout_marker($caption);
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

    // Deep-link Open in feed → exact card by ID (feed / public / reel).
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
      --p-bg:var(--msb-palette-bg,#f4f6f8);
      --p-surface:var(--msb-palette-surface,#fff);
      --p-text:var(--msb-palette-text,#0f172a);
      --p-muted:var(--msb-palette-text-muted,#64748b);
      --p-line:var(--msb-palette-border,rgba(148,163,184,.35));
    }
    *{box-sizing:border-box}
    html,body{margin:0;padding:0;min-height:100%}
    body{
      font-family:"Figtree",ui-sans-serif,system-ui,sans-serif;
      background:var(--p-bg);color:var(--p-text);
      -webkit-font-smoothing:antialiased;
    }
    .post-page{
      max-width:560px;margin:0 auto;min-height:100dvh;
      display:flex;flex-direction:column;
      padding:calc(12px + env(safe-area-inset-top)) 14px calc(24px + env(safe-area-inset-bottom));
    }
    .post-head{
      display:flex;align-items:center;gap:10px;margin-bottom:14px;
    }
    .post-back{
      width:40px;height:40px;border:0;border-radius:999px;background:transparent;color:inherit;
      display:inline-flex;align-items:center;justify-content:center;cursor:pointer;text-decoration:none;
    }
    .post-back:hover{background:rgba(127,127,127,.12)}
    .post-back svg{width:22px;height:22px}
    .post-head h1{margin:0;font-size:18px;font-weight:800}
    .post-card{
      background:var(--p-surface);border:1px solid var(--p-line);border-radius:18px;overflow:hidden;
      box-shadow:0 10px 30px rgba(15,23,42,.06);
    }
    .post-author{
      display:flex;align-items:center;gap:10px;padding:14px 14px 10px;
    }
    .post-author img{width:42px;height:42px;border-radius:50%;object-fit:cover;background:#e2e8f0}
    .post-author-meta{min-width:0}
    .post-author-name{font-size:15px;font-weight:800;line-height:1.2}
    .post-author-sub{font-size:12px;font-weight:600;color:var(--p-muted);margin-top:2px}
    .post-media{background:#0b1220;display:grid;gap:2px}
    .post-media img,.post-media video{
      width:100%;max-height:min(72vh,720px);object-fit:contain;display:block;background:#0b1220;
    }
    .post-caption{
      padding:14px;font-size:15px;font-weight:600;line-height:1.45;white-space:pre-wrap;word-break:break-word;
    }
    .post-actions{
      display:flex;gap:8px;padding:0 14px 14px;flex-wrap:wrap;
    }
    .post-actions a,.post-actions button{
      display:inline-flex;align-items:center;gap:7px;height:38px;padding:0 14px;border-radius:999px;
      border:1px solid var(--p-line);background:transparent;color:inherit;font:inherit;font-weight:800;font-size:13px;
      text-decoration:none;cursor:pointer;
    }
    .post-empty{
      margin:auto;text-align:center;padding:40px 20px;color:var(--p-muted);
    }
    .post-empty strong{display:block;color:var(--p-text);font-size:18px;margin-bottom:8px}
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
    <?php else: ?>
      <article class="post-card" data-post-id="<?= (int)$postId ?>" id="post-<?= (int)$postId ?>">
        <div class="post-author">
          <img src="<?= msb_post_h($avatarUrl) ?>" alt="">
          <div class="post-author-meta">
            <div class="post-author-name"><?= msb_post_h($authorName) ?></div>
            <div class="post-author-sub">
              <?php if ($authorUser !== ''): ?>@<?= msb_post_h($authorUser) ?> · <?php endif; ?>
              <?= msb_post_h($createdLabel) ?>
            </div>
          </div>
        </div>

        <?php if ($attachments): ?>
          <div class="post-media">
            <?php foreach ($attachments as $att): ?>
              <?php
                $type = strtolower(trim((string)($att['type'] ?? '')));
                $src = msb_post_media_url((string)(($att['file_path'] ?? '') !== '' ? $att['file_path'] : ($att['thumb_path'] ?? '')));
                if ($src === '') {
                    continue;
                }
              ?>
              <?php if ($type === 'video'): ?>
                <video src="<?= msb_post_h($src) ?>" controls playsinline preload="metadata"></video>
              <?php else: ?>
                <img src="<?= msb_post_h($src) ?>" alt="">
              <?php endif; ?>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <?php if ($caption !== ''): ?>
          <div class="post-caption"><?= nl2br(msb_post_h($caption)) ?></div>
        <?php endif; ?>

        <div class="post-actions">
          <a href="<?= msb_post_h($openInFeedUrl) ?>">Open in feed</a>
          <button type="button" id="postCopyLinkBtn"><i class="fa fa-link" aria-hidden="true"></i> Copy link</button>
        </div>
      </article>
    <?php endif; ?>
  </div>
  <script>
  (function(){
    var btn = document.getElementById('postCopyLinkBtn');
    if(!btn) return;
    btn.addEventListener('click', function(){
      var url = window.location.href.split('#')[0];
      var done = function(){ btn.textContent = 'Copied'; setTimeout(function(){ btn.innerHTML = '<i class="fa fa-link" aria-hidden="true"></i> Copy link'; }, 1200); };
      if(navigator.clipboard && navigator.clipboard.writeText){
        navigator.clipboard.writeText(url).then(done).catch(function(){});
        return;
      }
      try{
        var ta = document.createElement('textarea');
        ta.value = url; document.body.appendChild(ta); ta.select(); document.execCommand('copy'); ta.remove(); done();
      }catch(e){}
    });
  })();
  </script>
</body>
</html>
