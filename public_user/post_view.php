<?php
// /Business_only3/public_user/post_view.php
declare(strict_types=1);

require_once __DIR__ . '/includes/session_user.php';
requireUserLogin();

require_once __DIR__ . '/controller.php';
$controller = new Controller();
$dbh = $controller->pdo();

if (!function_exists('h')) {
  function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}

$postId = (int)($_GET['id'] ?? 0);
if ($postId <= 0) { header("Location: feed.php"); exit; }

// Load post
$post = null;
try {
  $st = $dbh->prepare("SELECT * FROM public_posts WHERE id = :id LIMIT 1");
  $st->execute([':id' => $postId]);
  $post = $st->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable $e) {}

if (!$post) { header("Location: feed.php"); exit; }

$title = trim((string)($post['title'] ?? ''));
$desc  = trim((string)($post['description'] ?? ''));
$body  = trim((string)($post['body'] ?? ''));

// Load attachments
$attachments = [];
try {
  $st = $dbh->prepare("SHOW TABLES LIKE 'public_post_attachments'");
  $st->execute();
  $has = (bool)$st->fetchColumn();
  if ($has) {
    $st = $dbh->prepare("
      SELECT
        COALESCE(NULLIF(type,''),'file') AS type,
        COALESCE(NULLIF(file_path,''),'') AS file_path,
        COALESCE(NULLIF(thumb_path,''),'') AS thumb_path
      FROM public_post_attachments
      WHERE post_id = :id
      ORDER BY id ASC
    ");
    $st->execute([':id' => $postId]);
    $attachments = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }
} catch (Throwable $e) { $attachments = []; }

// Pick first attachment as default view
$activeIndex = 0;
if (isset($_GET['a']) && ctype_digit((string)$_GET['a'])) {
  $activeIndex = max(0, (int)$_GET['a']);
}
if ($activeIndex >= count($attachments)) $activeIndex = 0;

$active = $attachments[$activeIndex] ?? null;

function file_name_from_path(string $p): string {
  $p = trim($p);
  if ($p === '') return 'Download';
  $p = str_replace('\\', '/', $p);
  $name = basename($p);
  return $name !== '' ? $name : 'Download';
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
  <title>Post</title>
  <?php
  require_once __DIR__ . '/includes/theme_prefs.php';
  theme_prefs_print_head_bootstrap($dbh, theme_prefs_viewer_user_id());
  ?>

  <link href="../lib/font-awesome/css/font-awesome.css" rel="stylesheet">
  <link href="../lib/Ionicons/css/ionicons.css" rel="stylesheet">
  <link rel="stylesheet" href="../css/shamcey.css">
  <link rel="stylesheet" href="assets/ui_best.css">
  <script defer src="assets/ui_best.js"></script>

  <style>
    .pv-wrap{max-width:980px;margin:0 auto;padding:0px 0px 0px;padding-top: 110px;}
    .pv-card{background:#fff;border:1px solid rgba(15,23,42,.08);overflow:hidden;}
    .pv-head{display:flex;align-items:center;justify-content:space-between;padding:14px 16px;border-bottom:1px solid rgba(15,23,42,.08);}
    .pv-title{margin:0;font-size:16px;font-weight:900;color:#0b1220;}
    .pv-body{padding:16px;}

    .pv-meta{margin:6px 0 14px;color:#667085;font-weight:700;font-size:13px;line-height:1.4;}
    .pv-meta b{color:#0b1220}

    .pv-media{width:100%;border-radius:0px;overflow:hidden;background:#eef2f7;border:1px solid rgba(15,23,42,.06);margin-bottom:12px;}
    .pv-media img{width:100%;height:auto;display:block;}
    .pv-media video{width:100%;height:auto;display:block;background:#000;}
    .pv-media iframe{width:100%;height:70vh;border:0;display:block;background:#fff;}

    .pv-switch{display:none;gap:8px;flex-wrap:wrap;margin:10px 0 16px;}
    .pv-chip{
      display:inline-flex;align-items:center;gap:8px;
      height:32px;padding:0 12px;border-radius:999px;
      border:1px solid rgba(15,23,42,.12);
      background:#fff;font-weight:900;font-size:12px;color:#0b1220;text-decoration:none;
    }
    .pv-chip.active{background:#0ea5e9;border-color:#0ea5e9;color:#fff;}
    .pv-chip i{font-size:14px}

    .pv-text{white-space:pre-wrap;line-height:1.65;color:#0b1220;font-size:14px;}
    .pv-actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:14px;}
    .pv-btn{display:inline-flex;align-items:center;gap:8px;height:34px;padding:0 12px;border-radius:10px;border:1px solid rgba(15,23,42,.12);background:#fff;font-weight:900;color:#0b1220;text-decoration:none;}
  </style>
</head>
<body>
<?php $skipHeaderThemeBootstrap = true; include __DIR__ . '/includes/header.php'; ?>

<div class="pv-wrap">
  <div class="pv-card">
    <div class="pv-head">
      <h1 class="pv-title"><?php echo h($title !== '' ? $title : 'Post'); ?></h1>
      <a class="pv-btn" href="profile.php"><i class="icon ion-ios-arrow-back"></i>Back</a>
    </div>

    <div class="pv-body">

      <?php if ($desc !== ''): ?>
        <div class="pv-meta"><b>Description:</b> <?php echo h($desc); ?></div>
      <?php endif; ?>

      <?php if (!empty($attachments)): ?>
        <div class="pv-switch">
          <?php foreach ($attachments as $i => $a):
            $t = (string)($a['type'] ?? 'file');
            $icon = 'ion-folder';
            if ($t === 'image') $icon = 'ion-image';
            else if ($t === 'video') $icon = 'ion-play';
            else if ($t === 'pdf') $icon = 'ion-document-text';
          ?>
            <a class="pv-chip <?php echo $i === $activeIndex ? 'active' : ''; ?>"
               href="post_view.php?id=<?php echo (int)$postId; ?>&a=<?php echo (int)$i; ?>">
              <i class="icon <?php echo h($icon); ?>"></i> <?php echo h(strtoupper($t)); ?> <?php echo (int)($i+1); ?>
            </a>
          <?php endforeach; ?>
        </div>

        <?php if ($active): ?>
          <?php
            $t = (string)($active['type'] ?? 'file');
            $fp = trim((string)($active['file_path'] ?? ''));
            $tp = trim((string)($active['thumb_path'] ?? ''));
          ?>
          <div class="pv-media">
            <?php if ($t === 'image' && $fp !== ''): ?>
              <img src="<?php echo h($fp); ?>" alt="Image">
            <?php elseif ($t === 'video' && $fp !== ''): ?>
              <video controls playsinline src="<?php echo h($fp); ?>"></video>
            <?php elseif ($t === 'pdf' && $fp !== ''): ?>
              <iframe src="<?php echo h($fp); ?>"></iframe>
            <?php else: ?>
              <div style="padding:16px;">
                <div style="font-weight:900;color:#0b1220;margin-bottom:8px;">File</div>
                <?php if ($fp !== ''): ?>
                  <a class="pv-btn" href="<?php echo h($fp); ?>" download><i class="icon ion-android-download"></i><?php echo h(file_name_from_path($fp)); ?></a>
                <?php else: ?>
                  <div style="color:#667085;font-weight:800;">No file path.</div>
                <?php endif; ?>
              </div>
            <?php endif; ?>
          </div>

          <?php if ($t === 'pdf' && $fp !== ''): ?>
            <a class="pv-btn" href="<?php echo h($fp); ?>" target="_blank"><i class="icon ion-android-open"></i>Open PDF</a>
          <?php endif; ?>

        <?php endif; ?>
      <?php endif; ?>

      <?php if ($body !== ''): ?>
        <div class="pv-text"><?php echo h($body); ?></div>
      <?php else: ?>
        <div class="pv-text" style="color:#667085;font-weight:800;">No long description.</div>
      <?php endif; ?>

      <div class="pv-actions">
        <a class="pv-btn" href="feed.php"><i class="icon ion-ios-paper"></i>Open in Feed</a>
        <a class="pv-btn" href="timeline.php"><i class="icon ion-ios-time"></i>Timeline</a>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>