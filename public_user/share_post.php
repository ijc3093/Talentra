<?php
declare(strict_types=1);

/**
 * Share landing page — Open Graph preview for Talsora / X / etc.,
 * then send people into the real post viewer.
 */
require_once __DIR__ . '/includes/session_user.php';
require_once __DIR__ . '/controller.php';
require_once __DIR__ . '/includes/post_layout.php';

$postId = (int)($_GET['id'] ?? $_GET['post'] ?? 0);
if ($postId <= 0) {
    header('Location: public.php');
    exit;
}

$controller = new Controller();
$dbh = $controller->pdo();

$title = 'Talsora post';
$description = 'Shared from Talsora';
$image = '';
$mediaType = 'website';
$author = '';

try {
    $st = $dbh->prepare("
      SELECT
        p.id,
        COALESCE(p.title,'') AS title,
        COALESCE(p.description,'') AS description,
        COALESCE(p.body,'') AS body,
        COALESCE(u.name,'') AS author_name,
        COALESCE(u.username,'') AS author_username,
        (
          SELECT aa.file_path
          FROM public_post_attachments aa
          WHERE aa.post_id = p.id
          ORDER BY
            CASE
              WHEN aa.type IN ('image','gif') THEN 0
              WHEN aa.type = 'video' THEN 1
              ELSE 2
            END,
            aa.id ASC
          LIMIT 1
        ) AS media_file,
        (
          SELECT aa.thumb_path
          FROM public_post_attachments aa
          WHERE aa.post_id = p.id
          ORDER BY
            CASE
              WHEN aa.type IN ('image','gif') THEN 0
              WHEN aa.type = 'video' THEN 1
              ELSE 2
            END,
            aa.id ASC
          LIMIT 1
        ) AS media_thumb,
        (
          SELECT aa.type
          FROM public_post_attachments aa
          WHERE aa.post_id = p.id
          ORDER BY
            CASE
              WHEN aa.type IN ('image','gif') THEN 0
              WHEN aa.type = 'video' THEN 1
              ELSE 2
            END,
            aa.id ASC
          LIMIT 1
        ) AS media_type
      FROM public_posts p
      LEFT JOIN users u ON u.id = p.user_id
      WHERE p.id = :id AND COALESCE(p.is_deleted,0) = 0
      LIMIT 1
    ");
    $st->execute([':id' => $postId]);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable $e) {
    $row = null;
}

if (!$row) {
    header('Location: public.php');
    exit;
}

$author = trim((string)($row['author_name'] ?? ''));
if ($author === '') {
    $author = trim((string)($row['author_username'] ?? ''));
}
$caption = trim((string)(($row['body'] ?? '') !== '' ? $row['body'] : ($row['description'] ?? '')));
if ($caption === '') {
    $caption = trim((string)($row['title'] ?? ''));
}
if (function_exists('post_strip_layout_marker')) {
    $caption = post_strip_layout_marker($caption);
}
if ($caption !== '') {
    $title = function_exists('mb_substr') ? (string)mb_substr($caption, 0, 90) : substr($caption, 0, 90);
    $description = function_exists('mb_substr') ? (string)mb_substr($caption, 0, 180) : substr($caption, 0, 180);
} elseif ($author !== '') {
    $title = $author . ' on Talsora';
    $description = 'See this post from ' . $author;
}

$mediaPath = trim((string)(($row['media_thumb'] ?? '') !== '' ? $row['media_thumb'] : ($row['media_file'] ?? '')));
$rawType = strtolower(trim((string)($row['media_type'] ?? '')));
if ($rawType === 'video') {
    $mediaType = 'video.other';
}

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
$scriptDir = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
$base = $scheme . '://' . $host . ($scriptDir !== '' ? $scriptDir : '');

if ($mediaPath !== '') {
    if (preg_match('~^(https?:)?//~i', $mediaPath)) {
        $image = $mediaPath;
        if (strpos($image, '//') === 0) {
            $image = $scheme . ':' . $image;
        }
    } elseif (isset($mediaPath[0]) && $mediaPath[0] === '/') {
        $image = $scheme . '://' . $host . $mediaPath;
    } else {
        $image = $base . '/' . ltrim($mediaPath, './');
    }
}

$shareUrl = $base . '/share_post.php?id=' . $postId;
$viewUrl = $base . '/post.php?id=' . $postId;

// Social crawlers need the HTML meta tags; humans go to the post.
$ua = strtolower((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
$isBot = (bool)preg_match(
    '~facebookexternalhit|facebot|twitterbot|linkedinbot|slackbot|discordbot|text|telegrambot|pinterest|embedly|quora link preview|outbrain|vkshare|redditbot|applebot|bingpreview|google.*snippet~i',
    $ua
);

if (!$isBot && !isset($_GET['preview'])) {
    header('Location: ' . $viewUrl, true, 302);
    exit;
}

function h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= h($title) ?></title>
  <meta name="description" content="<?= h($description) ?>">
  <link rel="canonical" href="<?= h($shareUrl) ?>">

  <meta property="og:type" content="<?= h($mediaType === 'video.other' ? 'video.other' : 'article') ?>">
  <meta property="og:site_name" content="Talsora">
  <meta property="og:title" content="<?= h($title) ?>">
  <meta property="og:description" content="<?= h($description) ?>">
  <meta property="og:url" content="<?= h($shareUrl) ?>">
  <?php if ($image !== ''): ?>
  <meta property="og:image" content="<?= h($image) ?>">
  <meta property="og:image:secure_url" content="<?= h($image) ?>">
  <meta name="twitter:card" content="summary_large_image">
  <meta name="twitter:image" content="<?= h($image) ?>">
  <?php else: ?>
  <meta name="twitter:card" content="summary">
  <?php endif; ?>
  <meta name="twitter:title" content="<?= h($title) ?>">
  <meta name="twitter:description" content="<?= h($description) ?>">

  <meta http-equiv="refresh" content="0;url=<?= h($viewUrl) ?>">
  <style>
    body{font-family:system-ui,sans-serif;margin:0;min-height:100vh;display:grid;place-items:center;background:#0f172a;color:#fff;text-align:center;padding:24px}
    a{color:#93c5fd}
  </style>
</head>
<body>
  <div>
    <h1><?= h($title) ?></h1>
    <p><?= h($description) ?></p>
    <?php if ($image !== ''): ?>
      <p><img src="<?= h($image) ?>" alt="" style="max-width:min(92vw,420px);border-radius:14px"></p>
    <?php endif; ?>
    <p><a href="<?= h($viewUrl) ?>">Open post</a></p>
  </div>
</body>
</html>
