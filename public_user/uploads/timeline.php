<?php
// /Business_only3/public_user/timeline.php
declare(strict_types=1);

require_once __DIR__ . '/includes/session_user.php';
require_once __DIR__ . '/controller.php';

requireUserLogin();

error_reporting(E_ALL);
ini_set('display_errors', '0');

$controller = new Controller();
$dbh = $controller->pdo();

$meId = (int)($_SESSION['user_id'] ?? 0);
if ($meId <= 0) { header("Location: index.php?session=reset"); exit; }

// ---------------- helpers ----------------
if (!function_exists('h')) {
  function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}
function clamp_str(string $v, array $allowed, string $default): string {
  return in_array($v, $allowed, true) ? $v : $default;
}
// ---------------- access/visit approvals ----------------
function init_access_table(PDO $dbh): void {
  // Only for timeline/about private visit approvals
  $dbh->exec("
    CREATE TABLE IF NOT EXISTS public_profile_access (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      owner_id BIGINT UNSIGNED NOT NULL,
      viewer_id BIGINT UNSIGNED NOT NULL,
      status ENUM('pending','approved','denied') NOT NULL DEFAULT 'pending',
      message VARCHAR(300) DEFAULT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      responded_at DATETIME NULL DEFAULT NULL,
      UNIQUE KEY uq_owner_viewer (owner_id, viewer_id),
      KEY idx_owner_status (owner_id, status),
      KEY idx_viewer_status (viewer_id, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  ");
}
function get_access_status(PDO $dbh, int $ownerId, int $viewerId): array {
  if ($ownerId === $viewerId) return ['status' => 'approved', 'row' => null];
  $st = $dbh->prepare("SELECT * FROM public_profile_access WHERE owner_id=:o AND viewer_id=:v LIMIT 1");
  $st->execute([':o'=>$ownerId, ':v'=>$viewerId]);
  $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
  return ['status' => (string)($row['status'] ?? 'none'), 'row' => $row];
}

init_access_table($dbh);

// ---------------- resolve owner & scale ----------------
$ownerId = (int)($_GET['u'] ?? $meId);
if ($ownerId <= 0) $ownerId = $meId;

$scale = clamp_str((string)($_GET['scale'] ?? 'year'), ['day','week','month','year'], 'year');
$isOwner = ($ownerId === $meId);

// ---------------- load owner profile (your users table) ----------------
$stU = $dbh->prepare("SELECT id, name, username, image, designation FROM users WHERE id=:id LIMIT 1");
$stU->execute([':id'=>$ownerId]);
$owner = $stU->fetch(PDO::FETCH_ASSOC);

if (!$owner) {
  http_response_code(404);
  echo "<h2 style='font-family:Arial;padding:20px'>User not found</h2>";
  exit;
}

$ownerName = trim((string)($owner['name'] ?? ''));
$ownerUsername = trim((string)($owner['username'] ?? ''));
$ownerDesignation = trim((string)($owner['designation'] ?? ''));

// ---------------- handle visit requests/decisions ----------------
$flash = '';
$access = get_access_status($dbh, $ownerId, $meId);
$isApproved = ($access['status'] === 'approved');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'request_access' && !$isOwner) {
      $msg = trim((string)($_POST['message'] ?? ''));
      if (mb_strlen($msg) > 300) $msg = mb_substr($msg, 0, 300);

      $st = $dbh->prepare("
        INSERT INTO public_profile_access (owner_id, viewer_id, status, message, created_at)
        VALUES (:o, :v, 'pending', :m, NOW())
        ON DUPLICATE KEY UPDATE status='pending', message=VALUES(message), created_at=NOW(), responded_at=NULL
      ");
      $st->execute([':o'=>$ownerId, ':v'=>$meId, ':m'=>$msg]);

      $flash = 'Request sent. Waiting for approval.';
    }

    if ($action === 'decide_access' && $isOwner) {
      $viewerId = (int)($_POST['viewer_id'] ?? 0);
      $decision = (string)($_POST['decision'] ?? '');
      if ($viewerId > 0 && in_array($decision, ['approved','denied'], true)) {
        $st = $dbh->prepare("
          UPDATE public_profile_access
          SET status=:s, responded_at=NOW()
          WHERE owner_id=:o AND viewer_id=:v
          LIMIT 1
        ");
        $st->execute([':s'=>$decision, ':o'=>$ownerId, ':v'=>$viewerId]);
        $flash = ($decision === 'approved') ? 'Access approved.' : 'Access denied.';
      }
    }

  // refresh
  $access = get_access_status($dbh, $ownerId, $meId);
  $isApproved = ($access['status'] === 'approved');
}

// ---------------- lock gate (private room door) ----------------
if (!$isOwner && !$isApproved) {
  $status = $access['status']; // none|pending|denied
  ?>
  <!DOCTYPE html>
  <html lang="en">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Life Timeline</title>
    <?php
    require_once __DIR__ . '/includes/theme_prefs.php';
    theme_prefs_print_head_bootstrap($dbh, theme_prefs_viewer_user_id());
    ?>

    <link href="./lib/font-awesome/css/font-awesome.css" rel="stylesheet">
    <link href="./lib/Ionicons/css/ionicons.css" rel="stylesheet">
    <link href="./lib/perfect-scrollbar/css/perfect-scrollbar.css" rel="stylesheet">
    <link rel="stylesheet" href="../css/shamcey.css">
    <link rel="stylesheet" href="assets/ui_best.css">
    <script src="assets/ui_best.js" defer></script>

    <style>
      body{ background:var(--msb-palette-bg,#f5f7fb); color:var(--msb-palette-text,#0f172a); }
      .door-wrap{ max-width:920px; margin:36px auto; padding:0 16px; }
      .door-card{ background:var(--msb-palette-surface-2,#fff); border-radius:18px; box-shadow:0 10px 30px rgba(0,0,0,.08); overflow:hidden; }
      .door-head{ display:flex; align-items:center; gap:14px; padding:18px 18px; border-bottom:1px solid var(--msb-palette-border,rgba(0,0,0,.06)); }
      .door-head img{ width:56px; height:56px; border-radius:50%; object-fit:cover; }
      .door-title{ margin:0; font-weight:900; letter-spacing:.2px; }
      .door-sub{ margin:3px 0 0; color:#6b7280; font-weight:700; font-size:13px; }
      .door-body{ padding:18px; }
      .note{ background:#fff7db; border:1px solid rgba(202,164,0,.25); padding:12px 12px; border-radius:12px; color:#7a5a00; font-weight:800; }
      .msgbox{ width:100%; border:1px solid rgba(0,0,0,.12); border-radius:12px; padding:10px 12px; }
      .actions{ display:flex; gap:10px; flex-wrap:wrap; margin-top:12px; }
      .btn-gold{ background:#ffc400; border:0; color:#111; font-weight:900; padding:10px 14px; border-radius:12px; }
      .btn-ghost{ background:#fff; border:1px solid rgba(0,0,0,.12); color:#111; font-weight:900; padding:10px 14px; border-radius:12px; text-decoration:none; }
      .flash{ margin:12px 0 0; background:#eafff8; border:1px solid rgba(15,118,110,.18); padding:10px 12px; border-radius:12px; color:#0f766e; font-weight:900; }
    </style>
  </head>
  <body>
    <?php $forceFeedRail = true; $skipHeaderThemeBootstrap = true; include __DIR__ . '/includes/header.php'; ?>

    <div class="sh-mainpanel">
      <div class="sh-pagebody">
        <div class="door-wrap">
      <div class="door-card">
        <div class="door-head">
          <img src="avatar.php?u=<?= (int)$ownerId ?>" data-live-avatar="1" data-avatar-base="avatar.php?u=<?= (int)$ownerId ?>" alt="">
          <div>
            <h3 class="door-title"><?= h($ownerName) ?>’s Private Room</h3>
            <div class="door-sub">Life Timeline is protected. Request approval to enter.</div>
          </div>
        </div>

        <div class="door-body">
          <?php if ($flash !== ''): ?><div class="flash"><?= h($flash) ?></div><?php endif; ?>

          <?php if ($status === 'pending'): ?>
            <div class="note"><i class="fa fa-clock-o"></i> Your request is pending approval.</div>
            <div class="actions">
              <a class="btn-ghost" href="feed.php"><i class="fa fa-rss"></i> Back to Feed</a>
              <a class="btn-ghost" href="timeline.php"><i class="fa fa-user"></i> My Timeline</a>
            </div>

          <?php else: ?>
            <div class="note">
              <i class="fa fa-lock"></i>
              <?= $status === 'denied' ? 'Access denied. You can request again.' : 'Click below to request access.' ?>
            </div>

            <form method="post" style="margin-top:12px;">
              <input type="hidden" name="action" value="request_access">

              <label style="display:block;font-weight:900;margin:8px 0 6px;">Message (optional)</label>
              <textarea class="msgbox" name="message" rows="3" placeholder="Say something meaningful: business, sports, music, family, collaboration…"></textarea>

              <div class="actions">
                <button class="btn-gold" type="submit"><i class="fa fa-key"></i> Request Visit</button>
                <a class="btn-ghost" href="feed.php">Back to Feed</a>
              </div>
            </form>
          <?php endif; ?>
        </div>
      </div>
        </div>
      </div>
    </div>
  </body>
  </html>
  <?php
  exit;
}

// ---------------- fetch posts for owner timeline (auto from dashboard) ----------------
// IMPORTANT: Your dashboard.php creates posts -> public_posts.
// Timeline reads from same table (ASC = past to present).
$stP = $dbh->prepare("
  SELECT id, user_id, title, description, body, visibility, created_at
  FROM public_posts
  WHERE user_id=:uid AND COALESCE(is_deleted,0)=0
  ORDER BY created_at ASC
");
$stP->execute([':uid'=>$ownerId]);
$posts = $stP->fetchAll(PDO::FETCH_ASSOC) ?: [];

$postIds = array_map(static fn($r) => (int)$r['id'], $posts);

// attachments
$attachmentsByPost = [];
if ($postIds) {
  $in = implode(',', array_fill(0, count($postIds), '?'));
  $stA = $dbh->prepare("
    SELECT post_id, type, file_path, thumb_path
    FROM public_post_attachments
    WHERE post_id IN ($in)
    ORDER BY id ASC
  ");
  $stA->execute($postIds);
  while ($a = $stA->fetch(PDO::FETCH_ASSOC)) {
    $pid = (int)$a['post_id'];
    $attachmentsByPost[$pid] ??= [];
    $attachmentsByPost[$pid][] = $a;
  }
}

// reactions (like/love totals)
$reactByPost = [];
if ($postIds) {
  $in = implode(',', array_fill(0, count($postIds), '?'));
  $stR = $dbh->prepare("
    SELECT
      post_id,
      COUNT(*) AS total,
      SUM(reaction='like') AS likes,
      SUM(reaction='love') AS loves
    FROM public_post_reactions
    WHERE post_id IN ($in)
    GROUP BY post_id
  ");
  $stR->execute($postIds);
  while ($r = $stR->fetch(PDO::FETCH_ASSOC)) {
    $pid = (int)$r['post_id'];
    $reactByPost[$pid] = [
      'total' => (int)($r['total'] ?? 0),
      'likes' => (int)($r['likes'] ?? 0),
      'loves' => (int)($r['loves'] ?? 0),
    ];
  }
}

// comments meta
$commentMetaByPost = [];
if ($postIds) {
  $in = implode(',', array_fill(0, count($postIds), '?'));
  $stC = $dbh->prepare("
    SELECT post_id, COUNT(*) AS total, MAX(created_at) AS last_at
    FROM public_post_comments
    WHERE post_id IN ($in) AND COALESCE(is_deleted,0)=0
    GROUP BY post_id
  ");
  $stC->execute($postIds);
  while ($c = $stC->fetch(PDO::FETCH_ASSOC)) {
    $pid = (int)$c['post_id'];
    $commentMetaByPost[$pid] = [
      'total' => (int)($c['total'] ?? 0),
      'last_at' => (string)($c['last_at'] ?? ''),
    ];
  }
}

// latest 2 comments preview per post
$commentPreviewByPost = [];
if ($postIds) {
  $in = implode(',', array_fill(0, count($postIds), '?'));
  $stCP = $dbh->prepare("
    SELECT c.post_id, c.user_id, c.comment_text, c.created_at, u.name
    FROM public_post_comments c
    JOIN users u ON u.id = c.user_id
    WHERE c.post_id IN ($in) AND COALESCE(c.is_deleted,0)=0
    ORDER BY c.created_at DESC
  ");
  $stCP->execute($postIds);
  $cnt = [];
  while ($row = $stCP->fetch(PDO::FETCH_ASSOC)) {
    $pid = (int)$row['post_id'];
    $cnt[$pid] = $cnt[$pid] ?? 0;
    if ($cnt[$pid] >= 2) continue;
    $cnt[$pid]++;
    $commentPreviewByPost[$pid][] = $row;
  }
}

// shares (optional)
$shareByPost = [];
if ($postIds) {
  try {
    $in = implode(',', array_fill(0, count($postIds), '?'));
    $stS = $dbh->prepare("
      SELECT post_id, COUNT(*) AS total
      FROM public_post_shares
      WHERE post_id IN ($in)
      GROUP BY post_id
    ");
    $stS->execute($postIds);
    while ($s = $stS->fetch(PDO::FETCH_ASSOC)) {
      $shareByPost[(int)$s['post_id']] = (int)($s['total'] ?? 0);
    }
  } catch (Throwable $e) {
    // table doesn't exist -> ignore
  }
}

// ---------------- group into chapters (day/week/month/year) ----------------
function group_key(string $dt, string $scale): string {
  $ts = strtotime($dt);
  if (!$ts) return 'Unknown';
  if ($scale === 'day')   return date('Y-m-d', $ts);
  if ($scale === 'month') return date('Y-m', $ts);
  if ($scale === 'week')  return date('o-\WW', $ts); // ISO week
  return date('Y', $ts);
}
function group_title(string $key, string $scale): string {
  if ($scale === 'day') {
    $ts = strtotime($key);
    return $ts ? date('D, M j, Y', $ts) : $key;
  }
  if ($scale === 'month') {
    $ts = strtotime($key . '-01');
    return $ts ? date('F Y', $ts) : $key;
  }
  if ($scale === 'week') return $key;
  return $key;
}

$groups = [];
foreach ($posts as $p) {
  $k = group_key((string)$p['created_at'], $scale);
  $groups[$k] ??= [];
  $groups[$k][] = $p;
}

// Step numbers 01..N across entire timeline
$step = 1;
foreach ($groups as $gk => $arr) {
  foreach ($arr as $i => $p) {
    $groups[$gk][$i]['_step'] = str_pad((string)$step, 2, '0', STR_PAD_LEFT);
    $step++;
  }
}

// Owner pending request list
$pendingRequests = [];
if ($isOwner) {
  $stPR = $dbh->prepare("
    SELECT a.viewer_id, a.status, a.message, a.created_at, u.name, u.username
    FROM public_profile_access a
    JOIN users u ON u.id = a.viewer_id
    WHERE a.owner_id=:o AND a.status='pending'
    ORDER BY a.created_at DESC
    LIMIT 50
  ");
  $stPR->execute([':o'=>$ownerId]);
  $pendingRequests = $stPR->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

// URL helper for scale links
$base = 'timeline.php' . ($ownerId !== $meId ? '?u='.(int)$ownerId.'&' : '?');
$mkScale = static fn(string $s) => $base . 'scale=' . urlencode($s);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
  <title>Life Timeline</title>
  <?php
  require_once __DIR__ . '/includes/theme_prefs.php';
  theme_prefs_print_head_bootstrap($dbh, theme_prefs_viewer_user_id());
  ?>

  <link href="./lib/font-awesome/css/font-awesome.css" rel="stylesheet">
  <link href="./lib/Ionicons/css/ionicons.css" rel="stylesheet">
  <link href="./lib/perfect-scrollbar/css/perfect-scrollbar.css" rel="stylesheet">

  <!-- css -->
  <link rel="stylesheet" href="./css/shamcey.css">
  <link rel="stylesheet" href="assets/ui_best.css">

  <!-- script -->
  <script src="assets/ui_best.js" defer></script>

  <style>
    body{ background:#f5f7fb; color:#0f172a; }
    .tl-wrap{ max-width:1250px; margin:18px auto; padding:0 16px; }

    /* header like your screenshot */
    .room-head{
      background:#fff; border-radius:18px; overflow:hidden;
      box-shadow:0 10px 30px rgba(0,0,0,.08);
    }
    .room-cover{
      height:170px;
      background:linear-gradient(90deg,#111 0%,#111 40%,#ffc400 40%,#ffc400 100%);
      position:relative;
    }
    .room-title{
      position:absolute; left:20px; top:48px;
      background:#fff; color:#111;
      padding:10px 14px; border-radius:14px;
      font-weight:900; letter-spacing:4px; text-transform:uppercase;
      font-size:24px;
    }
    .room-cover:after{
      content:""; position:absolute; left:40%; top:52px; right:20px; height:4px;
      background:rgba(17,17,17,.25);
    }
    .room-meta{
      display:flex; gap:14px; align-items:center;
      padding:14px 16px 16px; border-top:1px solid rgba(0,0,0,.05);
    }
    .room-meta img{ width:62px; height:62px; border-radius:50%; object-fit:cover; background:#fff; }
    .room-name{ margin:0; font-weight:900; }
    .room-sub{ margin:2px 0 0; color:#6b7280; font-weight:800; font-size:13px; }
    .room-actions{ margin-left:auto; display:flex; gap:10px; flex-wrap:wrap; }
    .btn-gold{ background:#ffc400; border:0; color:#111; font-weight:900; padding:10px 14px; border-radius:12px; text-decoration:none; }
    .btn-ghost{ background:#fff; border:1px solid rgba(0,0,0,.12); color:#111; font-weight:900; padding:10px 14px; border-radius:12px; text-decoration:none; }

    .flash{
      margin:12px 0; background:#eafff8; border:1px solid rgba(15,118,110,.18);
      padding:10px 12px; border-radius:12px; color:#0f766e; font-weight:900;
    }

    .scale-bar{ display:flex; gap:8px; flex-wrap:wrap; margin:14px 0; }
    .chip{
      display:inline-flex; align-items:center; gap:8px;
      padding:8px 12px; border-radius:999px;
      border:1px solid rgba(0,0,0,.12);
      background:#fff;
      font-weight:900; color:#111;
      text-decoration:none;
    }
    .chip.active{ background:#111; color:#fff; border-color:#111; }

    /* ==== IMPORTANT: no scrolling timeline (chapter slider) ==== */
    .life-strip{
      background:#fff; border-radius:18px;
      box-shadow:0 10px 30px rgba(0,0,0,.08);
      padding:16px 16px 14px;
      overflow:hidden;
    }
    .tl-nav{ display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; margin-bottom:12px; }
    .statpill{
      display:inline-flex; align-items:center; gap:8px;
      padding:7px 10px; border-radius:999px;
      border:1px solid rgba(0,0,0,.12);
      background:#fff; font-weight:900; color:#111;
    }

    .chapter-slider{ overflow:hidden; border-radius:16px; }
    .chapter-track{
      display:flex;
      transition:transform .35s ease;
      will-change:transform;
    }
    .chapter-slide{
      flex:0 0 100%;
      padding:6px 6px 0;
    }

    /* The “exact screenshot feel”: 5 columns in one view (no vertical scroll) */
    .grid-5{
      display:flex;
      gap:18px;
      align-items:stretch;
      justify-content:space-between;
    }
    .step-col{
      flex:1 1 0;
      min-width:0;
      border-radius:14px;
      overflow:hidden;
      border:1px solid rgba(0,0,0,.08);
      box-shadow:0 10px 22px rgba(0,0,0,.06);
      background:#fff;
      display:flex;
      flex-direction:column;
    }
    .step-top{
      padding:14px 14px 12px;
      min-height:148px;
      position:relative;
      background:#fff;
    }
    .step-num{
      font-weight:900;
      font-size:34px;
      color:#ffc400;
      line-height:1;
      margin-bottom:6px;
    }
    .step-title{
      font-weight:900;
      font-size:18px;
      margin:0 0 6px;
      color:#111;
    }
    .step-desc{
      font-size:12px;
      color:#6b7280;
      font-weight:700;
      margin:0;
      line-height:1.45;
      max-height:72px;
      overflow:hidden;
    }

    .tl-readmore{
      color:#111;
      font-weight:900;
      text-decoration:none;
      margin-left:4px;
      white-space:nowrap;
    }

    .step-icon{
      width:56px; height:56px;
      display:flex; align-items:center; justify-content:center;
      background:#ffc400;
      border-radius:12px;
      position:absolute;
      left:14px;
      bottom:-28px;
      color:#111;
      font-size:22px;
      box-shadow:0 10px 20px rgba(0,0,0,.12);
    }
    .step-media{
      height:185px;
      background:#f3f4f6;
      margin-top:18px;
      position:relative;
    }
    .step-media img, .step-media video{
      width:100%;
      height:100%;
      object-fit:cover;
      filter:grayscale(100%);
      display:block;
    }
    .step-bottom{
      padding:10px 12px 12px;
      border-top:1px solid rgba(0,0,0,.06);
      background:#fff;
    }
    .mini-stats{
      display:flex; gap:8px; flex-wrap:wrap;
      font-size:12px;
      font-weight:900;
      color:#111;
    }
    .mini-stats span{
      display:inline-flex; gap:6px; align-items:center;
      padding:6px 9px;
      background:#f5f7fb;
      border:1px solid rgba(0,0,0,.08);
      border-radius:999px;
      white-space:nowrap;
    }
    .year-tag{
      margin-top:8px;
      font-weight:900;
      color:#111;
      font-size:14px;
    }

    /* If a chapter has less than 5 posts, show empty placeholders (still 5 columns) */
    .placeholder{
      opacity:.35;
      border-style:dashed;
      box-shadow:none;
    }
    .placeholder .step-icon{ display:none; }

    /* Preview strip (allowed to scroll) */
    .chapter-previews{
      display:flex;
      gap:10px;
      margin-top:14px;
      overflow-x:auto;
      padding-bottom:6px;
    }
    .pv-item{
      flex:0 0 220px;
      border:1px solid rgba(0,0,0,.10);
      background:#fff;
      border-radius:14px;
      overflow:hidden;
      text-align:left;
      padding:0;
      cursor:pointer;
    }
    .pv-item.active{
      outline:3px solid rgba(255,196,0,.55);
      border-color:rgba(255,196,0,.65);
    }
    .pv-top{ padding:10px 10px 8px; }
    .pv-title{ font-weight:900; font-size:13px; color:#111; margin-bottom:2px; }
    .pv-count{ font-size:12px; color:#6b7280; font-weight:800; }
    .pv-item img{
      width:100%;
      height:88px;
      object-fit:cover;
      filter:grayscale(100%);
      background:#f3f4f6;
    }
    .pv-empty{
      height:88px;
      display:flex;
      align-items:center;
      justify-content:center;
      background:#f3f4f6;
      color:#6b7280;
      font-size:20px;
    }

    /* Owner requests panel */
    .req-box{
      background:#fff; border-radius:18px;
      box-shadow:0 10px 30px rgba(0,0,0,.08);
      padding:16px 16px 12px;
      margin-top:14px;
    }
    .req-title{ margin:0 0 10px; font-weight:900; }
    .req-item{
      display:flex; align-items:flex-start; gap:12px;
      padding:10px; border-radius:14px;
      border:1px solid rgba(0,0,0,.08);
      margin-bottom:10px;
      background:#f9fafb;
    }
    .req-item img{ width:42px; height:42px; border-radius:50%; object-fit:cover; }
    .req-main{ flex:1; }
    .req-main .nm{ margin:0; font-weight:900; }
    .req-main .msg{ margin:3px 0 0; color:#374151; font-size:13px; }
    .req-btns{ display:flex; gap:8px; }
    .btn-mini{ border-radius:10px; padding:8px 10px; font-weight:900; border:0; }
    .btn-ok{ background:#111; color:#fff; }
    .btn-no{ background:#fff; border:1px solid rgba(0,0,0,.12); color:#111; }

    .empty{
      padding:16px; text-align:center; color:#6b7280; font-weight:900;
    }

    @media (max-width: 1100px){
      .grid-5{ flex-wrap:wrap; }
      .step-col{ flex:1 1 calc(50% - 10px); }
    }
    @media (max-width: 620px){
      .step-col{ flex:1 1 100%; }
      .room-title{ font-size:18px; letter-spacing:3px; }
    }
  </style>
</head>

<body>
  <?php $forceFeedRail = true; $skipHeaderThemeBootstrap = true; include __DIR__ . '/includes/header.php'; ?>

  <div class="sh-mainpanel">
    <div class="sh-pagebody">
      <div class="tl-wrap">
    <?php if ($flash !== ''): ?><div class="flash"><?= h($flash) ?></div><?php endif; ?>

    <div class="room-head">
      <div class="room-cover">
        <div class="room-title">NEW TIMELINE PROJECT</div>
      </div>

      <div class="room-meta">
        <img src="avatar.php?u=<?= (int)$ownerId ?>" data-live-avatar="1" data-avatar-base="avatar.php?u=<?= (int)$ownerId ?>" alt="">
        <div>
          <h3 class="room-name"><?= h($ownerName) ?></h3>
          <div class="room-sub">
            <?= $ownerUsername !== '' ? '@'.h($ownerUsername).' · ' : '' ?>
            <?= $ownerDesignation !== '' ? h($ownerDesignation).' · ' : '' ?>
            Life Timeline (auto from Dashboard posts)
          </div>
        </div>

        <div class="room-actions">
          <a class="btn-ghost" href="dashboard.php"><i class="fa fa-plus"></i> New Post</a>
          <a class="btn-ghost" href="feed.php"><i class="fa fa-rss"></i> Feed</a>
          <a class="btn-gold" href="about.php<?= $ownerId !== $meId ? '?u='.(int)$ownerId : '' ?>"><i class="fa fa-user"></i> About</a>
        </div>
      </div>
    </div>

    <div class="scale-bar">
      <a class="chip <?= $scale==='day'?'active':'' ?>"   href="<?= h($mkScale('day')) ?>"><i class="fa fa-calendar"></i> Day</a>
      <a class="chip <?= $scale==='week'?'active':'' ?>"  href="<?= h($mkScale('week')) ?>"><i class="fa fa-calendar-o"></i> Week</a>
      <a class="chip <?= $scale==='month'?'active':'' ?>" href="<?= h($mkScale('month')) ?>"><i class="fa fa-calendar"></i> Month</a>
      <a class="chip <?= $scale==='year'?'active':'' ?>"  href="<?= h($mkScale('year')) ?>"><i class="fa fa-clock-o"></i> Year</a>
    </div>

    <div class="life-strip">
      <?php if (!$groups): ?>
        <div class="empty">
          No life events yet. Create a post in <a href="dashboard.php">dashboard.php</a> and it will appear here automatically.
        </div>
      <?php else: ?>
        <?php
          $groupKeys = array_keys($groups);
          $totalChapters = count($groupKeys);
        ?>

        <div class="tl-nav">
          <button type="button" class="btn-ghost" id="btnPrev"><i class="fa fa-angle-left"></i> Prev</button>

          <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
            <div class="statpill"><strong id="chapterLabel"></strong></div>
            <div class="statpill"><span id="chapterIndex">1</span> / <?= (int)$totalChapters ?></div>
          </div>

          <button type="button" class="btn-ghost" id="btnNext">Next <i class="fa fa-angle-right"></i></button>
        </div>

        <div class="chapter-slider">
          <div class="chapter-track" id="chapterTrack">

            <?php foreach ($groups as $gk => $arr): ?>
              <?php
                // Build up to 5 columns like your screenshot (no vertical scroll).
                // If more than 5 posts in a chapter, we show first 5 + a “+N more” note inside the last column.
                $postsInChapter = $arr;
                $extraCount = max(0, count($postsInChapter) - 5);
                $postsInChapter = array_slice($postsInChapter, 0, 5);

                // Choose icons per column (feel like your screenshot)
                $icons = ['fa-lightbulb-o','fa-comments','fa-map-marker','fa-play','fa-credit-card'];
              ?>
              <section class="chapter-slide" data-key="<?= h((string)$gk) ?>" data-title="<?= h(group_title((string)$gk, $scale)) ?>">
                <div class="grid-5">

                  <?php for ($i=0; $i<5; $i++): ?>
                    <?php
                      $p = $postsInChapter[$i] ?? null;

                      if (!$p) {
                        // placeholder column to keep 5 layout
                        ?>
                        <div class="step-col placeholder">
                          <div class="step-top">
                            <div class="step-num">--</div>
                            <h4 class="step-title">Empty</h4>
                            <p class="step-desc">No post in this time chapter.</p>
                          </div>
                          <div class="step-media"></div>
                          <div class="step-bottom">
                            <div class="mini-stats">
                              <span><i class="fa fa-thumbs-up"></i> Like 0</span>
                              <span><i class="fa fa-heart"></i> Love 0</span>
                            </div>
                            <div class="year-tag"><?= h(group_title((string)$gk, $scale)) ?></div>
                          </div>
                        </div>
                        <?php
                        continue;
                      }

                      $pid = (int)$p['id'];
                      $title = trim((string)($p['title'] ?? ''));
                      $desc  = trim((string)($p['description'] ?? ''));
                      $body  = trim((string)($p['body'] ?? ''));
                      $dt    = (string)($p['created_at'] ?? '');
                      $pretty = $dt ? date('M j, Y', strtotime($dt)) : '';

                      $atts = $attachmentsByPost[$pid] ?? [];
                      $firstImg = '';
                      $firstVid = '';
                      foreach ($atts as $a) {
                        if ($firstImg === '' && (string)$a['type'] === 'image' && !empty($a['file_path'])) $firstImg = (string)$a['file_path'];
                        if ($firstVid === '' && (string)$a['type'] === 'video' && !empty($a['file_path'])) $firstVid = (string)$a['file_path'];
                      }

                      $react = $reactByPost[$pid] ?? ['total'=>0,'likes'=>0,'loves'=>0];
                      $cm    = $commentMetaByPost[$pid] ?? ['total'=>0,'last_at'=>''];
                      $shares = (int)($shareByPost[$pid] ?? 0);

                      // nicer title fallback
                      $t = $title !== '' ? $title : 'Life Event';
                      $d = $desc !== '' ? $desc : ($body !== '' ? mb_substr($body, 0, 120) : 'A moment in this chapter.');
                      $fullText = ($desc !== '' ? $desc : $body);
                      if ($fullText === '') $fullText = $d;

                      $icon = $icons[$i] ?? 'fa-star';
                    ?>

                    <div class="step-col">
                      <div class="step-top">
                        <div class="step-num"><?= h((string)$p['_step']) ?></div>
                        <h4 class="step-title"><?= h($t) ?></h4>
                        <p class="step-desc" data-full="<?= h($fullText) ?>"><?= h($fullText) ?></p>

                        <div class="step-icon"><i class="fa <?= h($icon) ?>"></i></div>
                      </div>

                      <div class="step-media">
                        <?php if ($firstImg !== ''): ?>
                          <img src="<?= h($firstImg) ?>" alt="">
                        <?php elseif ($firstVid !== ''): ?>
                          <video src="<?= h($firstVid) ?>" preload="metadata" controls></video>
                        <?php else: ?>
                          <div class="pv-empty" style="height:100%;border-radius:0;"><i class="fa fa-image"></i></div>
                        <?php endif; ?>
                      </div>

                      <div class="step-bottom">
                        <div class="mini-stats">
                          <span title="Like"><i class="fa fa-thumbs-up"></i> <?= (int)$react['likes'] ?></span>
                          <span title="Love"><i class="fa fa-heart"></i> <?= (int)$react['loves'] ?></span>
                          <span title="Reactions"><i class="fa fa-bolt"></i> <?= (int)$react['total'] ?></span>
                          <span title="Comments"><i class="fa fa-comments"></i> <?= (int)$cm['total'] ?></span>
                          <span title="Shares"><i class="fa fa-share"></i> <?= (int)$shares ?></span>
                        </div>

                        <div class="year-tag">
                          <?= h(group_title((string)$gk, $scale)) ?>
                          <?php if ($pretty !== ''): ?>
                            <span style="color:#6b7280;font-weight:800;font-size:12px;"> · <?= h($pretty) ?></span>
                          <?php endif; ?>
                        </div>

                        <?php if ($i === 4 && $extraCount > 0): ?>
                          <div style="margin-top:8px;color:#6b7280;font-weight:900;font-size:12px;">
                            +<?= (int)$extraCount ?> more posts in this <?= h($scale) ?>.
                          </div>
                        <?php endif; ?>
                      </div>
                    </div>

                  <?php endfor; ?>

                </div>
              </section>
            <?php endforeach; ?>

          </div>
        </div>

        <!-- Preview strip (click to jump chapters) -->
        <div class="chapter-previews" id="chapterPreviews">
          <?php foreach ($groups as $gk => $arr): ?>
            <?php
              $previewImg = '';
              foreach ($arr as $p0) {
                $pid0 = (int)$p0['id'];
                $atts0 = $attachmentsByPost[$pid0] ?? [];
                foreach ($atts0 as $a0) {
                  if ((string)$a0['type'] === 'image' && !empty($a0['file_path'])) {
                    $previewImg = (string)$a0['file_path'];
                    break 2;
                  }
                }
              }
            ?>
            <button type="button" class="pv-item" data-key="<?= h((string)$gk) ?>">
              <div class="pv-top">
                <div class="pv-title"><?= h(group_title((string)$gk, $scale)) ?></div>
                <div class="pv-count"><?= (int)count($arr) ?> posts</div>
              </div>
              <?php if ($previewImg !== ''): ?>
                <img src="<?= h($previewImg) ?>" alt="">
              <?php else: ?>
                <div class="pv-empty"><i class="fa fa-image"></i></div>
              <?php endif; ?>
            </button>
          <?php endforeach; ?>
        </div>

      <?php endif; ?>
    </div>

    <!-- Owner approvals panel (still timeline-only) -->
    <?php if ($isOwner): ?>
      <div class="req-box">
        <h4 class="req-title"><i class="fa fa-lock"></i> Visit Requests</h4>

        <?php if (!$pendingRequests): ?>
          <div class="empty" style="padding:10px 0 4px;text-align:left;">No pending requests.</div>
        <?php else: ?>
          <?php foreach ($pendingRequests as $r): ?>
            <div class="req-item">
              <img src="avatar.php?u=<?= (int)$r['viewer_id'] ?>" data-live-avatar="1" data-avatar-base="avatar.php?u=<?= (int)$r['viewer_id'] ?>" alt="">
              <div class="req-main">
                <p class="nm">
                  <?= h((string)$r['name']) ?>
                  <?php if (!empty($r['username'])): ?>
                    <span style="color:#6b7280;font-weight:800">@<?= h((string)$r['username']) ?></span>
                  <?php endif; ?>
                </p>

                <div class="msg"><?= !empty($r['message']) ? h((string)$r['message']) : 'No message.' ?></div>

                <div class="msg" style="color:#6b7280;font-size:12px;margin-top:4px;">
                  Requested: <?= h(date('M j, Y g:i A', strtotime((string)$r['created_at']))) ?>
                </div>
              </div>

              <div class="req-btns">
                <form method="post" style="display:inline;">
                  <input type="hidden" name="action" value="decide_access">
                  <input type="hidden" name="viewer_id" value="<?= (int)$r['viewer_id'] ?>">
                  <input type="hidden" name="decision" value="approved">
                  <button class="btn-mini btn-ok" type="submit"><i class="fa fa-check"></i> Approve</button>
                </form>

                <form method="post" style="display:inline;">
                  <input type="hidden" name="action" value="decide_access">
                  <input type="hidden" name="viewer_id" value="<?= (int)$r['viewer_id'] ?>">
                  <input type="hidden" name="decision" value="denied">
                  <button class="btn-mini btn-no" type="submit"><i class="fa fa-times"></i> Deny</button>
                </form>
              </div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    <?php endif; ?>

  </div>

  <script>
  (function(){
    const track = document.getElementById('chapterTrack');
    if (!track) return;

    const slides = Array.from(track.querySelectorAll('.chapter-slide'));
    const btnPrev = document.getElementById('btnPrev');
    const btnNext = document.getElementById('btnNext');
    const label = document.getElementById('chapterLabel');
    const idxEl = document.getElementById('chapterIndex');
    const previews = document.getElementById('chapterPreviews');

    if (slides.length === 0) return;

    let idx = 0;

    function render(){
      track.style.transform = `translateX(${-idx * 100}%)`;

      const s = slides[idx];
      const title = (s && s.getAttribute('data-title')) ? s.getAttribute('data-title') : '';
      if (label) label.textContent = title || '';

      if (idxEl) idxEl.textContent = String(idx + 1);

      if (btnPrev) btnPrev.disabled = (idx === 0);
      if (btnNext) btnNext.disabled = (idx === slides.length - 1);

      if (previews){
        const items = Array.from(previews.querySelectorAll('.pv-item'));
        items.forEach((b, i) => b.classList.toggle('active', i === idx));
        const active = previews.querySelector('.pv-item.active');
        if (active) active.scrollIntoView({behavior:'smooth', inline:'center', block:'nearest'});
      }
    }

    function go

      // refresh mobile helpers
      try{ applyReadMore(); }catch(e){}
      try{ setupVideoAutopause(); }catch(e){}
    }

    function go(n){
      idx = Math.max(0, Math.min(slides.length - 1, n));
      render();
    }

    if (btnPrev) btnPrev.addEventListener('click', () => go(idx - 1));
    if (btnNext) btnNext.addEventListener('click', () => go(idx + 1));

    if (previews){
      previews.addEventListener('click', (e) => {
        const btn = e.target.closest('.pv-item');
        if (!btn) return;
        const items = Array.from(previews.querySelectorAll('.pv-item'));
        const i = items.indexOf(btn);
        if (i >= 0) go(i);
      });
    }

    // Keyboard next/prev
    document.addEventListener('keydown', (e) => {
      if (e.key === 'ArrowLeft') go(idx - 1);
      if (e.key === 'ArrowRight') go(idx + 1);
    });

    // ✅ Mobile/Tablet: Swipe left/right to change chapter (day/week/month/year)
    (function(){
      const slider = document.querySelector('.chapter-slider');
      if (!slider) return;

      let x0 = 0, y0 = 0, t0 = 0;
      slider.addEventListener('touchstart', (e) => {
        if (!e.touches || !e.touches[0]) return;
        x0 = e.touches[0].clientX;
        y0 = e.touches[0].clientY;
        t0 = Date.now();
      }, {passive:true});

      slider.addEventListener('touchend', (e) => {
        const dt = Date.now() - t0;
        const touch = (e.changedTouches && e.changedTouches[0]) ? e.changedTouches[0] : null;
        if (!touch) return;
        const dx = touch.clientX - x0;
        const dy = touch.clientY - y0;

        // ignore vertical gestures
        if (Math.abs(dy) > Math.abs(dx)) return;
        if (Math.abs(dx) < 50) return;
        if (dt > 900) return;

        if (dx < 0) go(idx + 1);
        else go(idx - 1);
      }, {passive:true});
    })();

    // ✅ Mobile/Tablet: "Read more" only if 10+ sentences
    function applyReadMore(){
      if (!window.matchMedia || !window.matchMedia('(max-width: 1024px)').matches) return;

      const els = document.querySelectorAll('.step-desc[data-full]');
      els.forEach((el) => {
        if (el.dataset.rmDone === '1') return;
        const full = (el.getAttribute('data-full') || '').trim();
        if (!full) { el.dataset.rmDone = '1'; return; }

        const sentences = full.split(/[.!?]+/).map(s => s.trim()).filter(Boolean);
        if (sentences.length < 10) { el.textContent = full; el.dataset.rmDone = '1'; return; }

        const short = sentences.slice(0, 3).join('. ') + '.';
        el.innerHTML = '';
        const span = document.createElement('span');
        span.textContent = short + ' ';
        const a = document.createElement('a');
        a.href = 'javascript:void(0)';
        a.className = 'tl-readmore';
        a.textContent = 'Read more';
        a.addEventListener('click', () => {
          const expanded = el.dataset.expanded === '1';
          if (!expanded){
            el.textContent = full + ' ';
            const b = document.createElement('a');
            b.href = 'javascript:void(0)';
            b.className = 'tl-readmore';
            b.textContent = 'Read less';
            b.addEventListener('click', () => { el.dataset.expanded = '0'; el.dataset.rmDone = '0'; applyReadMore(); });
            el.appendChild(b);
            el.dataset.expanded = '1';
          }
        });

        el.appendChild(span);
        el.appendChild(a);
        el.dataset.rmDone = '1';
      });
    }

    // ✅ Timeline: auto-pause videos when not visible (one video at a time)
    function setupVideoAutopause(){
      const vids = Array.from(document.querySelectorAll('video'));
      if (!('IntersectionObserver' in window) || vids.length === 0) return;

      // pause others when one plays
      vids.forEach(v => {
        v.addEventListener('play', () => {
          vids.forEach(o => { if (o !== v) { try{o.pause();}catch(e){} } });
        });
      });

      const io = new IntersectionObserver((entries) => {
        entries.forEach(ent => {
          const v = ent.target;
          if (!ent.isIntersecting || ent.intersectionRatio < 0.55) {
            try{ v.pause(); }catch(e){}
          }
        });
      }, {threshold:[0,0.25,0.55,0.75,1]});

      vids.forEach(v => io.observe(v));
    }


    render();
    // apply after first render
    applyReadMore();
    setupVideoAutopause();
  })();
  </script>
    </div>
  </div>
</div>
</body>
</html>
