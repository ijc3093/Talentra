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
$isMediaSolo = false;
$isTextSolo = false;
$showSlideShow = false;
$initialTitle = '';
$initialBody = '';
$initialSlideTitle = '';
$initialSlideBody = '';

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

    // Magazine split when media is paired with title/description (or slide captions).
    $hasCopy = ($postTitle !== '' || $postBody !== '');
    $useSplitLayout = ($mediaCount >= 1 && ($hasCopy || $slidePresentation));
    $isMediaSolo = (!$useSplitLayout && $mediaCount >= 1 && !$hasCopy);
    $isTextSolo = (!$useSplitLayout && $mediaCount < 1 && $hasCopy);
    $showSlideShow = ($mediaCount >= 1 || $hasCopy);

    // Fixed layer always comes from the post; slide layer is separate.
    $initialTitle = $postTitle;
    $initialBody = $postBody;
    $initialSlideTitle = '';
    $initialSlideBody = '';
    if ($slidePresentation && $mediaCount > 0) {
        $initialSlideTitle = trim((string)($attachments[0]['slide_title'] ?? ''));
        $initialSlideBody = trim((string)($attachments[0]['slide_body'] ?? ''));
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
      --p-bg:#e8eef4;
      --p-surface:#fff;
      --p-text:#111827;
      --p-muted:#6b7280;
      --p-line:rgba(15,23,42,.08);
      --p-accent:#1d9bf0;
      --p-nav:#1e3a5f;
      --p-paper:#f2f1e8;
      --p-stage-ink:#1a1a1a;
    }
    *{box-sizing:border-box}
    html,body{margin:0;padding:0;min-height:100%}
    body{
      font-family:"Figtree",ui-sans-serif,system-ui,sans-serif;
      background:var(--p-bg);color:var(--p-text);
      -webkit-font-smoothing:antialiased;
    }
    body:has(.post-stage){background:#050505}
    .post-page{
      max-width:560px;margin:0 auto;min-height:100dvh;
      display:flex;flex-direction:column;
      padding:calc(10px + env(safe-area-inset-top)) 14px calc(24px + env(safe-area-inset-bottom));
    }
    .post-page:has(.post-stage){
      max-width:min(1320px,96vw);
      width:100%;
      padding:calc(12px + env(safe-area-inset-top)) max(16px, env(safe-area-inset-left)) max(24px, env(safe-area-inset-bottom)) max(16px, env(safe-area-inset-right));
      justify-content:flex-start;
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
    body:has(.post-stage) .post-back,
    body:has(.post-stage) .post-head h1{color:#f3f4f6}
    body:has(.post-stage) .post-back:hover{background:rgba(255,255,255,.08)}
    .post-empty{
      margin:auto;text-align:center;padding:40px 20px;color:var(--p-muted);
    }
    .post-empty strong{display:block;color:var(--p-text);font-size:18px;margin-bottom:8px}

    /* —— Match gallery modal (#pvOverlay) frame size —— */
    .post-stage{
      flex:0 1 auto;
      align-self:center;
      width:min(1320px,96vw);
      max-width:100%;
      display:flex;
      align-items:stretch;
      background:#000;
      border-radius:12px;
      overflow:hidden;
      height:min(720px,88vh);
      max-height:min(720px,88vh);
      box-shadow:0 30px 90px rgba(0,0,0,.45);
    }
    .post-stage-media{
      position:relative;
      flex:1 1 0;
      min-width:0;min-height:0;
      background:#000;
      display:flex;align-items:center;justify-content:center;
      overflow:hidden;
    }
    .post-carousel{
      position:relative;
      width:100%;height:100%;min-height:0;
      display:flex;align-items:center;justify-content:center;
      padding:0;
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
      object-fit:contain;object-position:center center;display:block;
    }
    .post-nav{
      position:absolute;top:50%;transform:translateY(-50%);
      width:36px;height:36px;border:0;border-radius:999px;
      background:rgba(40,40,40,.55);color:#fff;
      display:inline-flex;align-items:center;justify-content:center;
      cursor:pointer;z-index:3;padding:0;
      transition:background .15s ease, opacity .15s ease;
    }
    .post-nav:hover{background:rgba(40,40,40,.75)}
    .post-nav[disabled]{opacity:.25;cursor:default;pointer-events:none}
    .post-nav-prev{left:10px}
    .post-nav-next{right:10px}
    .post-nav svg{width:16px;height:16px;display:block}
    .post-dots{
      position:absolute;left:0;right:0;bottom:12px;
      display:flex;justify-content:center;gap:6px;z-index:3;pointer-events:none;
    }
    .post-dot{
      width:7px;height:7px;border-radius:999px;border:0;padding:0;
      background:rgba(255,255,255,.55);
      pointer-events:auto;cursor:pointer;
    }
    .post-dot.is-active{background:var(--p-accent)}

    /* Caption panel ≈ gallery .pv-mid / .pv-right width */
    .post-stage-copy{
      flex:0 0 min(380px,38vw);
      width:min(380px,38vw);
      min-width:280px;
      max-width:min(380px,38vw);
      display:flex;flex-direction:column;
      padding:22px 20px 16px;
      min-height:0;
      background:var(--msb-palette-bg, var(--p-paper));
      color:var(--msb-palette-text, var(--p-stage-ink));
      overflow:hidden;
      box-sizing:border-box;
    }
    .post-stage-author{
      display:flex;align-items:center;gap:10px;
      margin:0 0 14px;flex-shrink:0;
    }
    .post-stage-author img{
      width:34px;height:34px;border-radius:50%;object-fit:cover;background:#d9d9c8;flex-shrink:0;
    }
    .post-stage-author-name{font-size:13px;font-weight:800;line-height:1.15;color:var(--msb-palette-text, var(--p-stage-ink))}
    .post-stage-author-sub{font-size:11px;font-weight:600;color:var(--msb-palette-text-muted, #7a7a72);margin-top:2px}
    .post-stage-title{
      margin:0 0 10px;flex-shrink:0;
      font-size:clamp(22px,2.4vw,28px);
      font-weight:800;line-height:1.15;letter-spacing:-.02em;
      color:var(--msb-palette-text, var(--p-stage-ink));word-break:break-word;
    }
    .post-stage-title:empty{display:none}
    .post-stage-copy-main{
      flex:1 1 auto;min-height:0;
      overflow-x:hidden;
      overflow-y:auto;
      -webkit-overflow-scrolling:touch;
      overscroll-behavior:contain;
      display:flex;flex-direction:column;
      padding-right:2px;
      scrollbar-width:thin;
      scrollbar-color:rgba(26,26,26,.28) transparent;
    }
    .post-stage-copy-main::-webkit-scrollbar{width:6px}
    .post-stage-copy-main::-webkit-scrollbar-thumb{
      background:rgba(26,26,26,.28);
      border-radius:999px;
    }
    .post-stage-copy-main::-webkit-scrollbar-track{background:transparent}
    .post-stage-intro{
      margin:0;
      flex:0 0 auto;
      font-size:14px;font-weight:500;line-height:1.55;
      color:var(--msb-palette-text-muted, #3a3a35);white-space:pre-wrap;word-break:break-word;
    }
    .post-stage-intro:empty{display:none}
    .post-stage-subtitle{
      margin:10px 0 6px;flex-shrink:0;
      font-size:clamp(16px,1.8vw,20px);
      font-weight:700;line-height:1.25;letter-spacing:-.01em;
      color:var(--msb-palette-text, #2a2a26);word-break:break-word;
    }
    .post-stage-subtitle:empty{display:none}
    .post-stage-summary{
      margin:0;flex:0 0 auto;
      font-size:13px;font-weight:500;line-height:1.5;color:var(--msb-palette-text-muted, #55554c);
    }
    .post-stage-summary:empty{display:none}
    .post-stage-summary .post-slide-summary-p{margin:0}
    .post-stage-summary .post-slide-summary-list{
      margin:0;padding-left:1.15em;list-style:disc;
    }
    .post-stage-summary .post-slide-summary-list li{margin:0 0 .4em}
    .post-stage-summary .post-slide-summary-list li:last-child{margin-bottom:0}
    .post-stage-actions{
      display:flex;align-items:center;justify-content:space-between;gap:10px;
      padding-top:14px;margin-top:auto;flex-shrink:0;
    }
    .post-stage-actions-left{display:flex;gap:8px;flex-wrap:wrap;min-width:0}
    .post-stage-actions a,.post-stage-actions-left button{
      display:inline-flex;align-items:center;gap:7px;height:34px;padding:0 12px;border-radius:999px;
      border:1px solid rgba(26,26,26,.12);background:rgba(255,255,255,.45);color:var(--p-stage-ink);
      font:inherit;font-weight:700;font-size:12px;text-decoration:none;cursor:pointer;
    }
    .post-stage-slideshow-btn{
      flex:0 0 auto;margin-left:auto;
      height:auto;padding:6px 2px;border:0;border-radius:0;
      background:transparent;color:var(--p-stage-ink);
      font:inherit;font-weight:800;font-size:13px;letter-spacing:-.01em;
      cursor:pointer;text-decoration:none;
    }
    .post-stage-slideshow-btn:hover{opacity:.72}

    /* Fullscreen audience slideshow — CSS overlay (no browser fullscreen flash) */
    .post-ss{
      position:fixed;inset:0;z-index:10000;
      display:flex;flex-direction:column;
      background:#050505;color:#f8fafc;
      opacity:0;
      visibility:hidden;
      pointer-events:none;
      transition:opacity .28s ease, visibility 0s linear .28s;
      will-change:opacity;
    }
    .post-ss.is-open{
      opacity:1;
      visibility:visible;
      pointer-events:auto;
      transition:opacity .28s ease, visibility 0s linear 0s;
    }
    html.post-ss-lock,
    html.post-ss-lock body{
      overflow:hidden !important;
    }
    .post-ss-bar{
      flex:0 0 auto;
      display:flex;align-items:center;justify-content:space-between;gap:12px;
      padding:12px 16px;
      background:rgba(0,0,0,.55);
      opacity:0;
      transform:translateY(-6px);
      transition:opacity .28s ease .04s, transform .28s ease .04s;
    }
    .post-ss.is-open .post-ss-bar{
      opacity:1;
      transform:none;
    }
    .post-ss-bar-title{font-size:13px;font-weight:800;letter-spacing:.02em;opacity:.9}
    .post-ss-counter{font-size:12px;font-weight:700;opacity:.7}
    .post-ss-close{
      width:40px;height:40px;border:0;border-radius:999px;
      background:rgba(255,255,255,.12);color:#fff;
      display:inline-flex;align-items:center;justify-content:center;
      cursor:pointer;font-size:22px;line-height:1;
    }
    .post-ss-close:hover{background:rgba(255,255,255,.2)}
    .post-ss-main{
      flex:1 1 auto;min-height:0;
      position:relative;
      display:flex;align-items:flex-start;justify-content:center;
      padding:18px 64px 48px;
    }
    .post-ss-stage{
      display:flex;align-items:stretch;
      height:min(100%, calc(100dvh - 120px));
      max-height:calc(100dvh - 120px);
      width:fit-content;
      max-width:min(1280px,100%);
      margin:0 auto;
      border-radius:4px;
      overflow:hidden;
      background:#000;
      box-shadow:0 20px 60px rgba(0,0,0,.45);
      opacity:0;
      transform:translateY(12px) scale(.985);
      transition:opacity .32s ease .05s, transform .32s cubic-bezier(.22,1,.36,1) .05s;
      will-change:opacity, transform;
    }
    .post-ss.is-open .post-ss-stage{
      opacity:1;
      transform:none;
    }
    .post-ss-media{
      position:relative;
      flex:0 1 auto;
      width:auto;
      min-width:0;min-height:0;
      background:#000;
      display:flex;align-items:center;justify-content:center;
    }
    .post-ss-media img,
    .post-ss-media video{
      max-width:min(72vw,900px);
      max-height:100%;
      width:auto;height:100%;
      object-fit:contain;display:block;
    }
    .post-ss-nav{
      position:absolute;top:50%;transform:translateY(-50%);
      width:52px;height:52px;border:0;border-radius:999px;
      background:rgba(40,40,40,.55);color:#fff;
      display:inline-flex;align-items:center;justify-content:center;
      cursor:pointer;z-index:5;padding:0;
    }
    .post-ss-nav:hover{background:rgba(40,40,40,.75)}
    .post-ss-nav[disabled]{opacity:.25;cursor:default;pointer-events:none}
    .post-ss-nav-prev{left:12px}
    .post-ss-nav-next{right:12px}
    .post-ss-nav svg{width:22px;height:22px;display:block}
    .post-ss-copy{
      flex:0 0 min(380px,36vw);
      width:min(380px,36vw);
      min-width:260px;
      max-width:min(380px,36vw);
      background:var(--msb-palette-bg, var(--p-paper));
      color:var(--msb-palette-text, var(--p-stage-ink));
      display:flex;flex-direction:column;
      padding:28px 26px 22px;
      min-height:0;overflow:hidden;
      box-sizing:border-box;
    }
    .post-ss-copy-main{
      flex:1 1 auto;min-height:0;
      overflow:auto;
      -webkit-overflow-scrolling:touch;
      scrollbar-width:thin;
    }
    .post-ss-title{
      margin:0 0 12px;
      font-size:clamp(26px,3vw,36px);
      font-weight:800;line-height:1.15;letter-spacing:-.02em;
    }
    .post-ss-title:empty{display:none}
    .post-ss-intro{
      margin:0 0 12px;
      font-size:15px;font-weight:500;line-height:1.6;
      white-space:pre-wrap;word-break:break-word;color:var(--msb-palette-text-muted, #3a3a35);
    }
    .post-ss-intro:empty{display:none}
    .post-ss-subtitle{
      margin:0 0 8px;
      font-size:clamp(18px,2vw,24px);
      font-weight:700;line-height:1.25;
      color:var(--msb-palette-text, inherit);
    }
    .post-ss-subtitle:empty{display:none}
    .post-ss-summary{
      margin:0;
      font-size:14px;font-weight:500;line-height:1.55;color:var(--msb-palette-text-muted, #55554c);
    }
    .post-ss-summary:empty{display:none}
    .post-ss-summary .post-slide-summary-list{margin:0;padding-left:1.15em;list-style:disc}
    .post-ss-summary .post-slide-summary-list li{margin:0 0 .4em}
    .post-ss-hint{
      flex:0 0 auto;margin-top:16px;
      font-size:11px;font-weight:700;color:#8a8a82;
    }
    @media (max-width:900px){
      .post-ss-main{padding:12px 12px 28px}
      .post-ss-stage{
        flex-direction:column;
        width:100%;
        max-width:100%;
        height:auto;
        max-height:100%;
      }
      .post-ss-media{
        flex:0 0 auto;
        width:100%;
        max-height:min(52vh,420px);
      }
      .post-ss-media img,
      .post-ss-media video{
        width:100%;height:auto;
        max-height:min(52vh,420px);
      }
      .post-ss-copy{
        flex:1 1 auto;
        width:100%;max-width:none;min-width:0;
        padding:18px 16px;
        max-height:min(40vh,320px);
      }
      .post-ss-nav{width:42px;height:42px}
      .post-ss-nav-prev{left:8px}
      .post-ss-nav-next{right:8px}
    }

    /* Portrait: hug media width (gallery .pv-is-portrait) */
    @media (min-width:901px){
      .post-stage.post-is-portrait{
        width:fit-content;
        max-width:min(1320px,96vw);
      }
      .post-stage.post-is-portrait .post-stage-media{
        flex:0 1 auto;
        width:auto;
        max-width:min(56vh,520px);
      }
      .post-stage.post-is-portrait .post-carousel,
      .post-stage.post-is-portrait .post-carousel-track{
        width:auto;max-width:100%;
      }
      .post-stage.post-is-portrait .post-slide.is-active{
        width:auto;
      }
      .post-stage.post-is-portrait .post-slide img,
      .post-stage.post-is-portrait .post-slide video{
        width:auto;height:100%;
        max-width:100%;max-height:100%;
      }
      .post-stage.post-is-landscape{
        width:min(1320px,96vw);
      }
      .post-stage.post-is-landscape .post-stage-media{
        flex:1 1 0;
        max-width:none;
      }
    }

    /* Compact single-column (media-only or text-only) — gallery-like centered card */
    .post-page:has(.post-card){
      max-width:640px;
      width:100%;
      justify-content:flex-start;
    }
    .post-page:has(.post-card--media-solo){
      max-width:min(980px,96vw);
    }
    .post-page:has(.post-card--text-solo){
      max-width:640px;
    }
    .post-card{
      width:100%;
      max-width:560px;
      margin:8px auto 0;
      background:var(--p-surface);
      border:1px solid rgba(15,23,42,.06);
      border-radius:18px;
      overflow:hidden;
      box-shadow:
        0 1px 2px rgba(15,23,42,.04),
        0 12px 40px rgba(15,23,42,.08);
    }
    .post-card--media-solo{
      max-width:min(900px,100%);
      margin:auto 0;
      box-shadow:
        0 2px 8px rgba(15,23,42,.06),
        0 16px 48px rgba(15,23,42,.12),
        0 32px 80px rgba(15,23,42,.1);
    }
    .post-card--text-solo{
      max-width:520px;
      margin:8px auto 0;
    }
    .post-author{
      display:flex;align-items:center;gap:12px;
      padding:16px 16px 12px;
    }
    .post-card--media-solo .post-author{padding:18px 18px 14px}
    .post-author img{
      width:44px;height:44px;border-radius:50%;
      object-fit:cover;background:#e2e8f0;flex-shrink:0;
    }
    .post-author-name{
      font-size:15px;font-weight:800;letter-spacing:-.01em;line-height:1.2;
    }
    .post-author-sub{
      font-size:12px;font-weight:600;color:var(--p-muted);margin-top:3px;line-height:1.3;
    }
    .post-card-media{
      position:relative;
      background:#000;
      display:flex;align-items:center;justify-content:center;
    }
    .post-card-media img,
    .post-card-media video{
      display:block;width:100%;height:auto;
      max-height:min(56vh,520px);
      object-fit:contain;background:#000;
    }
    .post-card--media-solo .post-card-media img,
    .post-card--media-solo .post-card-media video{
      max-height:min(72vh,720px);
    }
    .post-card-carousel{position:relative}
    .post-card-carousel .post-carousel-track{
      position:relative;
      background:#000;
    }
    .post-card-carousel .post-slide{display:none}
    .post-card-carousel .post-slide.is-active{display:block}
    .post-card-carousel .post-nav{
      position:absolute;top:50%;transform:translateY(-50%);
      width:36px;height:36px;border:0;border-radius:999px;
      background:rgba(40,40,40,.5);color:#fff;
      display:inline-flex;align-items:center;justify-content:center;
      cursor:pointer;z-index:2;padding:0;
    }
    .post-card-carousel .post-nav:hover{background:rgba(40,40,40,.7)}
    .post-card-carousel .post-nav-prev{left:10px}
    .post-card-carousel .post-nav-next{right:10px}
    .post-card-carousel .post-nav svg{width:16px;height:16px;display:block}
    .post-card-carousel .post-dots{
      position:absolute;left:0;right:0;bottom:12px;
      display:flex;justify-content:center;gap:6px;z-index:2;
    }
    .post-card-carousel .post-dot{
      width:7px;height:7px;border-radius:999px;border:0;padding:0;
      background:rgba(255,255,255,.55);cursor:pointer;
    }
    .post-card-carousel .post-dot.is-active{background:var(--p-accent)}
    .post-card-copy{padding:14px 16px 6px}
    .post-card-title{
      margin:0 0 8px;font-size:20px;font-weight:800;
      letter-spacing:-.015em;line-height:1.2;
    }
    .post-card-body{
      margin:0;font-size:15px;font-weight:500;line-height:1.55;
      white-space:pre-wrap;word-break:break-word;color:#374151;
    }
    .post-card-actions{
      display:flex;align-items:center;justify-content:space-between;gap:10px;
      padding:14px 16px 16px;flex-wrap:wrap;
    }
    .post-card-actions-left{display:flex;gap:10px;flex-wrap:wrap;min-width:0}
    .post-card--media-solo .post-card-actions{padding:16px 18px 18px}
    .post-card-actions-left a,.post-card-actions-left button{
      display:inline-flex;align-items:center;gap:7px;height:38px;padding:0 16px;border-radius:999px;
      border:1px solid rgba(15,23,42,.12);background:#fff;color:var(--p-nav);
      font:inherit;font-weight:800;font-size:13px;
      text-decoration:none;cursor:pointer;
      transition:background .15s ease, border-color .15s ease;
    }
    .post-card-actions-left a:hover,.post-card-actions-left button:hover{
      background:#f8fafc;border-color:rgba(15,23,42,.18);
    }
    .post-card-actions-left a{color:var(--p-accent)}
    .post-card-actions-left button{color:var(--p-text)}
    .post-card-slideshow-btn{
      flex:0 0 auto;margin-left:auto;
      height:auto;padding:6px 2px;border:0;background:transparent;
      color:var(--p-text);font:inherit;font-weight:800;font-size:13px;
      letter-spacing:-.01em;cursor:pointer;
    }
    .post-card-slideshow-btn:hover{opacity:.72}
    .post-ss-stage.is-media-only .post-ss-copy{display:none}
    .post-ss-stage.is-media-only .post-ss-media{
      max-width:min(92vw,980px);
    }
    .post-ss-stage.is-media-only .post-ss-media img,
    .post-ss-stage.is-media-only .post-ss-media video{
      max-width:min(92vw,980px);
      max-height:calc(100dvh - 120px);
      height:auto;
      width:auto;
    }
    .post-ss-stage.is-text-only{
      width:min(560px,92vw);
      max-width:min(560px,92vw);
      height:auto;
      max-height:calc(100dvh - 120px);
      background:var(--msb-palette-bg, var(--p-paper));
    }
    .post-ss-stage.is-text-only .post-ss-media{display:none}
    .post-ss-stage.is-text-only .post-ss-copy{
      flex:1 1 auto;
      width:100%;
      max-width:none;
      min-width:0;
      padding:clamp(28px,4vw,48px);
      max-height:calc(100dvh - 120px);
    }
    .post-ss-stage.is-text-only .post-ss-title{
      font-size:clamp(28px,3.4vw,40px);
    }
    .post-ss-stage.is-text-only .post-ss-intro{
      font-size:16px;line-height:1.65;
    }
    .post-ss-nav.is-hidden{display:none !important}

    @media (max-width:900px){
      body:has(.post-stage){background:var(--p-bg)}
      body:has(.post-stage) .post-back,
      body:has(.post-stage) .post-head h1{color:var(--p-nav)}
      body:has(.post-stage) .post-back:hover{background:rgba(30,58,95,.08)}
      .post-stage{
        flex-direction:column;
        width:100%;
        height:auto;
        max-height:none;
        border-radius:14px;
        box-shadow:0 10px 28px rgba(15,23,42,.08);
      }
      .post-stage-media{flex:0 0 auto;width:100%;max-width:none}
      .post-carousel{min-height:42vw;max-height:min(52vh,420px)}
      .post-stage-copy{
        flex:1 1 auto;
        width:100%;
        max-width:none;
        min-width:0;
        min-height:0;
        padding:18px 16px 14px;
        max-height:min(42vh,320px);
        overflow:hidden;
        display:flex;
        flex-direction:column;
      }
      .post-stage-copy-main{
        flex:1 1 auto;
        min-height:0;
        overflow-y:auto;
      }
      .post-stage-title{font-size:22px}
      .post-stage-author{margin-bottom:12px}
      .post-card{margin:12px auto 0;border-radius:14px}
      .post-card-media img,
      .post-card-media video{max-height:min(48vh,420px)}
      .post-card--media-solo .post-card-media img,
      .post-card--media-solo .post-card-media video{max-height:min(62vh,560px)}
    }
  </style>
</head>
<body>
  <div class="post-page">
    <header class="post-head">
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
          <div class="post-stage-copy-main">
            <h2 class="post-stage-title" id="postStageTitle"><?= msb_post_h($initialTitle) ?></h2>
            <div class="post-stage-intro" id="postStageIntro"><?= nl2br(msb_post_h($initialBody)) ?></div>
            <?php if ($slidePresentation): ?>
              <h3 class="post-stage-subtitle" id="postStageSubtitle"><?= msb_post_h($initialSlideTitle) ?></h3>
              <div class="post-stage-summary" id="postStageSummary"><?= post_slide_summary_html($initialSlideBody) ?></div>
            <?php endif; ?>
          </div>
          <div class="post-stage-actions">
            <div class="post-stage-actions-left">
              <a href="<?= msb_post_h($openInFeedUrl) ?>">Open in feed</a>
              <button type="button" id="postCopyLinkBtn"><i class="fa fa-link" aria-hidden="true"></i> Copy link</button>
            </div>
            <button type="button" class="post-stage-slideshow-btn" id="postSlideShowBtn" aria-label="Start slide show">Slide Show</button>
          </div>
        </div>
      </article>
    <?php else: ?>
      <article
        class="post-card<?= !empty($isMediaSolo) ? ' post-card--media-solo' : '' ?><?= !empty($isTextSolo) ? ' post-card--text-solo' : '' ?>"
        data-post-id="<?= (int)$postId ?>"
        id="post-<?= (int)$postId ?>"
        data-legacy-title="<?= msb_post_h($postTitle) ?>"
        data-legacy-body="<?= msb_post_h($postBody) ?>"
        data-slide-presentation="<?= $slidePresentation ? '1' : '0' ?>"
      >
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

        <?php if ($attachments): ?>
          <div class="post-card-media<?= $mediaCount > 1 ? ' post-card-carousel js-post-carousel' : '' ?>"<?= $mediaCount > 1 ? ' data-index="0" data-count="' . (int)$mediaCount . '"' : '' ?>>
            <?php if ($mediaCount > 1): ?>
              <div class="post-carousel-track">
                <?php foreach ($attachments as $i => $att): ?>
                  <?php
                    $type = strtolower(trim((string)($att['type'] ?? '')));
                    $src = msb_post_media_url((string)(($att['file_path'] ?? '') !== '' ? $att['file_path'] : ($att['thumb_path'] ?? '')));
                    if ($src === '') {
                        continue;
                    }
                  ?>
                  <div class="post-slide<?= $i === 0 ? ' is-active' : '' ?>" data-slide-index="<?= (int)$i ?>">
                    <?php if ($type === 'video'): ?>
                      <video src="<?= msb_post_h($src) ?>" controls playsinline preload="metadata"></video>
                    <?php else: ?>
                      <img src="<?= msb_post_h($src) ?>" alt="">
                    <?php endif; ?>
                  </div>
                <?php endforeach; ?>
              </div>
              <button type="button" class="post-nav post-nav-prev" data-post-prev aria-label="Previous">
                <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M15 5 8 12l7 7" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
              </button>
              <button type="button" class="post-nav post-nav-next" data-post-next aria-label="Next">
                <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M9 5l7 7-7 7" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
              </button>
              <div class="post-dots" role="tablist" aria-label="Slides">
                <?php for ($d = 0; $d < $mediaCount; $d++): ?>
                  <button type="button" class="post-dot<?= $d === 0 ? ' is-active' : '' ?>" data-post-dot="<?= (int)$d ?>" aria-label="Slide <?= (int)($d + 1) ?>"></button>
                <?php endfor; ?>
              </div>
            <?php else: ?>
              <?php
                $att = $attachments[0];
                $type = strtolower(trim((string)($att['type'] ?? '')));
                $src = msb_post_media_url((string)(($att['file_path'] ?? '') !== '' ? $att['file_path'] : ($att['thumb_path'] ?? '')));
              ?>
              <?php if ($src !== ''): ?>
                <?php if ($type === 'video'): ?>
                  <video src="<?= msb_post_h($src) ?>" controls playsinline preload="metadata"></video>
                <?php else: ?>
                  <img src="<?= msb_post_h($src) ?>" alt="">
                <?php endif; ?>
              <?php endif; ?>
            <?php endif; ?>
          </div>
        <?php endif; ?>

        <div class="post-card-actions">
          <div class="post-card-actions-left">
            <a href="<?= msb_post_h($openInFeedUrl) ?>">Open in feed</a>
            <button type="button" id="postCopyLinkBtn"><i class="fa fa-link" aria-hidden="true"></i> Copy link</button>
          </div>
          <?php if (!empty($showSlideShow)): ?>
            <button type="button" class="post-card-slideshow-btn" id="postSlideShowBtn" aria-label="Start slide show">Slide Show</button>
          <?php endif; ?>
        </div>
      </article>
    <?php endif; ?>

    <?php if ($post && !empty($showSlideShow)): ?>
      <div class="post-ss" id="postSlideShow" aria-hidden="true" role="dialog" aria-modal="true" aria-label="Slide show">
        <div class="post-ss-bar">
          <div>
            <div class="post-ss-bar-title">Slide Show</div>
            <div class="post-ss-counter" id="postSsCounter">1 / 1</div>
          </div>
          <button type="button" class="post-ss-close" id="postSsClose" aria-label="Exit slide show">&times;</button>
        </div>
        <div class="post-ss-main">
          <button type="button" class="post-ss-nav post-ss-nav-prev" id="postSsPrev" aria-label="Previous">
            <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M15 5 8 12l7 7" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
          </button>
          <div class="post-ss-stage" id="postSsStage">
            <div class="post-ss-media" id="postSsMedia"></div>
            <aside class="post-ss-copy" id="postSsCopy">
              <div class="post-ss-copy-main">
                <h2 class="post-ss-title" id="postSsTitle"></h2>
                <div class="post-ss-intro" id="postSsIntro"></div>
                <h3 class="post-ss-subtitle" id="postSsSubtitle"></h3>
                <div class="post-ss-summary" id="postSsSummary"></div>
              </div>
              <div class="post-ss-hint">← → to change slides · Esc to exit</div>
            </aside>
          </div>
          <button type="button" class="post-ss-nav post-ss-nav-next" id="postSsNext" aria-label="Next">
            <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M9 5l7 7-7 7" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
          </button>
        </div>
      </div>
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
    function slideSummaryHtml(text){
      var raw = String(text || '').replace(/\r\n/g,'\n').replace(/\r/g,'\n').trim();
      if(!raw) return '';
      var lines = raw.split('\n').map(function(line){
        return String(line || '').trim().replace(/^(?:[•\-\*]|\d+[\.\)])\s+/, '');
      }).filter(Boolean);
      if(!lines.length) return '';
      if(lines.length === 1){
        return '<div class="post-slide-summary"><p class="post-slide-summary-p">'+escHtml(lines[0])+'</p></div>';
      }
      return '<div class="post-slide-summary"><ul class="post-slide-summary-list">'
        + lines.map(function(line){ return '<li>'+escHtml(line)+'</li>'; }).join('')
        + '</ul></div>';
    }

    var stage = document.querySelector('.post-stage');
    var carousel = document.querySelector('.js-post-carousel');
    var subtitleEl = document.getElementById('postStageSubtitle');
    var summaryEl = document.getElementById('postStageSummary');

    function syncStageOrientation(){
      if(!stage) return;
      var media = null;
      if(carousel){
        var active = carousel.querySelector('.post-slide.is-active');
        media = active ? (active.querySelector('video, img') || null) : null;
      }
      if(!media){
        media = stage.querySelector('.post-slide.is-active video, .post-slide.is-active img, .post-stage-media video, .post-stage-media img');
      }
      var w = 0, h = 0;
      if(media){
        if(media.tagName === 'VIDEO'){
          w = Number(media.videoWidth || 0);
          h = Number(media.videoHeight || 0);
        } else {
          w = Number(media.naturalWidth || 0);
          h = Number(media.naturalHeight || 0);
        }
      }
      var isPortrait = (w > 0 && h > 0 && h > w);
      var isLandscape = (w > 0 && h > 0 && w >= h);
      stage.classList.toggle('post-is-portrait', !!isPortrait);
      stage.classList.toggle('post-is-landscape', !!isLandscape && !isPortrait);
    }

    function bindMediaOrientation(el){
      if(!el) return;
      var run = function(){ syncStageOrientation(); };
      if(el.tagName === 'VIDEO'){
        el.addEventListener('loadedmetadata', run);
        el.addEventListener('loadeddata', run);
        if(el.readyState >= 1) run();
      } else {
        el.addEventListener('load', run);
        if(el.complete) run();
      }
    }

    function setIndex(next){
      if(!carousel) return;
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

      // Super title + introduction stay fixed; only subtitle/summary sync.
      if(stage && stage.getAttribute('data-slide-presentation') === '1'){
        var active = slides[idx];
        var t = active ? String(active.getAttribute('data-slide-title') || '').trim() : '';
        var b = active ? String(active.getAttribute('data-slide-body') || '').trim() : '';
        if(subtitleEl){
          subtitleEl.textContent = t;
          subtitleEl.style.display = t ? '' : 'none';
        }
        if(summaryEl){
          summaryEl.innerHTML = b ? slideSummaryHtml(b) : '';
          summaryEl.style.display = b ? '' : 'none';
        }
      }
      syncStageOrientation();
      if(ssOpen) renderSlideShow(idx);
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
      carousel.querySelectorAll('video, img').forEach(bindMediaOrientation);
      setIndex(0);
    } else if(stage){
      stage.querySelectorAll('video, img').forEach(bindMediaOrientation);
      syncStageOrientation();
    }

    /* —— Fullscreen Slide Show presentation —— */
    var postCard = document.querySelector('.post-card');
    var ssRoot = document.getElementById('postSlideShow');
    var ssBtn = document.getElementById('postSlideShowBtn');
    var ssStageEl = document.getElementById('postSsStage');
    var ssMedia = document.getElementById('postSsMedia');
    var ssTitle = document.getElementById('postSsTitle');
    var ssIntro = document.getElementById('postSsIntro');
    var ssSubtitle = document.getElementById('postSsSubtitle');
    var ssSummary = document.getElementById('postSsSummary');
    var ssCounter = document.getElementById('postSsCounter');
    var ssPrev = document.getElementById('postSsPrev');
    var ssNext = document.getElementById('postSsNext');
    var ssClose = document.getElementById('postSsClose');
    var ssOpen = false;
    var ssBusy = false;
    var ssLockPad = 0;
    var ssSource = stage || postCard;
    var ssFixedTitle = ssSource ? String(ssSource.getAttribute('data-legacy-title') || '').trim() : '';
    var ssFixedIntro = ssSource ? String(ssSource.getAttribute('data-legacy-body') || '').trim() : '';
    var ssIsPresentation = ssSource && ssSource.getAttribute('data-slide-presentation') === '1';

    function collectSlides(){
      var list = [];
      if(carousel){
        Array.prototype.forEach.call(carousel.querySelectorAll('.post-slide'), function(slide){
          var media = slide.querySelector('video, img');
          if(!media) return;
          list.push({
            type: media.tagName === 'VIDEO' ? 'video' : 'image',
            src: media.getAttribute('src') || '',
            title: String(slide.getAttribute('data-slide-title') || '').trim(),
            body: String(slide.getAttribute('data-slide-body') || '').trim()
          });
        });
        if(list.length) return list;
      }
      var root = stage || postCard;
      if(root){
        var mediaSolo = root.querySelector('.post-stage-media video, .post-stage-media img, .post-card-media video, .post-card-media img');
        if(mediaSolo){
          list.push({
            type: mediaSolo.tagName === 'VIDEO' ? 'video' : 'image',
            src: mediaSolo.getAttribute('src') || '',
            title: '',
            body: ''
          });
        }
      }
      if(!list.length && (ssFixedTitle || ssFixedIntro)){
        list.push({ type:'text', src:'', title:'', body:'' });
      }
      return list;
    }

    function pausePageMedia(){
      var root = stage || postCard;
      if(!root) return;
      Array.prototype.forEach.call(root.querySelectorAll('video'), function(v){
        try{ v.pause(); }catch(e){}
      });
    }

    function lockPageScroll(){
      ssLockPad = Math.max(0, window.innerWidth - document.documentElement.clientWidth);
      document.documentElement.classList.add('post-ss-lock');
      if(ssLockPad > 0){
        document.body.style.paddingRight = ssLockPad + 'px';
      }
    }

    function unlockPageScroll(){
      document.documentElement.classList.remove('post-ss-lock');
      document.body.style.paddingRight = '';
      ssLockPad = 0;
    }

    function renderSlideShow(idx){
      if(!ssRoot || !ssMedia) return;
      var slides = collectSlides();
      if(!slides.length) return;
      var count = slides.length;
      idx = ((Number(idx) % count) + count) % count;
      var slide = slides[idx];
      var existing = ssMedia.querySelector('img, video');
      var isTextSlide = slide.type === 'text' || (!slide.src && slide.type !== 'video' && slide.type !== 'image');
      var same = !isTextSlide && existing
        && ((slide.type === 'video' && existing.tagName === 'VIDEO') || (slide.type === 'image' && existing.tagName === 'IMG'))
        && String(existing.getAttribute('src') || '') === slide.src;

      if(isTextSlide){
        if(existing){
          if(existing.tagName === 'VIDEO'){ try{ existing.pause(); }catch(e){} }
          existing.remove();
        }
      } else if(!same){
        if(existing){
          if(existing.tagName === 'VIDEO'){ try{ existing.pause(); }catch(e){} }
          existing.remove();
        }
        var el;
        if(slide.type === 'video'){
          el = document.createElement('video');
          el.src = slide.src;
          el.controls = true;
          el.setAttribute('playsinline', '');
          el.setAttribute('preload', 'auto');
        } else {
          el = document.createElement('img');
          el.src = slide.src;
          el.alt = '';
          el.decoding = 'async';
        }
        ssMedia.appendChild(el);
        if(slide.type === 'video'){
          var playTry = function(){ el.play().catch(function(){}); };
          if(el.readyState >= 2) playTry();
          else el.addEventListener('loadeddata', playTry, { once:true });
        }
      } else if(existing && existing.tagName === 'VIDEO'){
        existing.play().catch(function(){});
      }

      var st = ssIsPresentation ? slide.title : '';
      var sb = ssIsPresentation ? slide.body : '';
      var hasCopy = !!(ssFixedTitle || ssFixedIntro || st || sb);
      var hasMedia = !isTextSlide && !!slide.src;
      if(ssStageEl){
        ssStageEl.classList.toggle('is-media-only', hasMedia && !hasCopy);
        ssStageEl.classList.toggle('is-text-only', hasCopy && !hasMedia);
      }
      if(ssPrev) ssPrev.classList.toggle('is-hidden', count <= 1);
      if(ssNext) ssNext.classList.toggle('is-hidden', count <= 1);

      if(ssTitle){
        ssTitle.textContent = ssFixedTitle;
        ssTitle.style.display = ssFixedTitle ? '' : 'none';
      }
      if(ssIntro){
        ssIntro.innerHTML = ssFixedIntro ? escHtml(ssFixedIntro).replace(/\n/g, '<br>') : '';
        ssIntro.style.display = ssFixedIntro ? '' : 'none';
      }
      if(ssSubtitle){
        ssSubtitle.textContent = st;
        ssSubtitle.style.display = st ? '' : 'none';
      }
      if(ssSummary){
        ssSummary.innerHTML = sb ? slideSummaryHtml(sb) : '';
        ssSummary.style.display = sb ? '' : 'none';
      }
      if(ssCounter) ssCounter.textContent = (idx + 1) + ' / ' + count;
      if(ssPrev) ssPrev.disabled = count <= 1;
      if(ssNext) ssNext.disabled = count <= 1;
    }

    function openSlideShow(){
      if(!ssRoot || ssOpen || ssBusy) return;
      var slides = collectSlides();
      if(!slides.length) return;
      ssBusy = true;
      var start = carousel ? Number(carousel.getAttribute('data-index') || 0) : 0;
      pausePageMedia();
      renderSlideShow(start);
      lockPageScroll();
      ssRoot.setAttribute('aria-hidden', 'false');
      requestAnimationFrame(function(){
        requestAnimationFrame(function(){
          ssOpen = true;
          ssRoot.classList.add('is-open');
          ssBusy = false;
          if(ssClose) ssClose.focus({ preventScroll:true });
        });
      });
    }

    function closeSlideShow(){
      if(!ssRoot || !ssOpen || ssBusy) return;
      ssBusy = true;
      ssOpen = false;
      ssRoot.classList.remove('is-open');
      ssRoot.setAttribute('aria-hidden', 'true');
      var vid = ssMedia ? ssMedia.querySelector('video') : null;
      if(vid){ try{ vid.pause(); }catch(e){} }

      var finished = false;
      var finish = function(){
        if(finished) return;
        finished = true;
        unlockPageScroll();
        ssBusy = false;
        if(ssBtn) ssBtn.focus({ preventScroll:true });
      };
      var onEnd = function(e){
        if(e.target !== ssRoot) return;
        ssRoot.removeEventListener('transitionend', onEnd);
        finish();
      };
      ssRoot.addEventListener('transitionend', onEnd);
      setTimeout(finish, 360);
    }

    if(ssBtn){
      ssBtn.addEventListener('click', function(){ openSlideShow(); });
    }
    if(ssClose){
      ssClose.addEventListener('click', function(){ closeSlideShow(); });
    }
    if(ssPrev){
      ssPrev.addEventListener('click', function(){
        var slides = collectSlides();
        if(slides.length <= 1) return;
        var cur = carousel ? Number(carousel.getAttribute('data-index') || 0) : 0;
        var nextIdx = ((cur - 1) % slides.length + slides.length) % slides.length;
        if(carousel) setIndex(nextIdx);
        else renderSlideShow(nextIdx);
      });
    }
    if(ssNext){
      ssNext.addEventListener('click', function(){
        var slides = collectSlides();
        if(slides.length <= 1) return;
        var cur = carousel ? Number(carousel.getAttribute('data-index') || 0) : 0;
        var nextIdx = (cur + 1) % slides.length;
        if(carousel) setIndex(nextIdx);
        else renderSlideShow(nextIdx);
      });
    }
    document.addEventListener('keydown', function(e){
      if(!ssOpen) return;
      if(e.key === 'Escape'){ e.preventDefault(); closeSlideShow(); return; }
      if(e.key === 'ArrowLeft'){ e.preventDefault(); if(ssPrev) ssPrev.click(); return; }
      if(e.key === 'ArrowRight' || e.key === ' '){ e.preventDefault(); if(ssNext) ssNext.click(); }
    });

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
