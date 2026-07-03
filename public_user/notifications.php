<?php
require_once __DIR__ . '/includes/session_user.php';
requireUserLogin();

require_once __DIR__ . '/controller.php';

$controller = new Controller();
$dbh = $controller->pdo();

$receivers = array_values(array_unique(array_filter([
    trim((string)($_SESSION['user_login'] ?? '')),
    trim((string)($_SESSION['user_email'] ?? '')),
], static function ($value) {
    return $value !== '';
})));

$message = '';
$error = '';

if (empty($receivers)) {
    $error = 'Missing session receiver for notifications.';
}

function notifications_parse_meta(string $type): array {
    $type = trim($type);
    $liveId = 0;
    $route = '';
    $postId = 0;
    $commentId = 0;

    while (preg_match('/\s\[(live|r|p|c):([^\]]+)\]\s*$/', $type, $m)) {
        $key = trim((string)($m[1] ?? ''));
        $value = trim((string)($m[2] ?? ''));
        if ($key === 'live') {
            $liveId = (int)$value;
        } elseif ($key === 'r') {
            $route = preg_replace('/[^a-z]/i', '', $value) ?? '';
        } elseif ($key === 'p') {
            $postId = (int)$value;
        } elseif ($key === 'c') {
            $commentId = (int)$value;
        }
        $type = trim((string)preg_replace('/\s\[(?:live|r|p|c):[^\]]+\]\s*$/', '', $type, 1));
    }

    $url = '';
    if ($liveId > 0) {
        $url = 'live_watch.php?live=' . $liveId;
    } elseif ($postId > 0) {
        $page = 'feed.php';
        if ($route === 'pf') {
            $page = 'profile.php';
        } elseif ($route === 'pb') {
            $page = 'public.php';
        }
        $params = ['open_post' => $postId];
        if ($commentId > 0) {
            $params['open_comment'] = $commentId;
        }
        $url = $page . '?' . http_build_query($params);
    }

    return [
        'text' => $type,
        'url' => $url,
        'live_id' => $liveId,
    ];
}

function notifications_fmt_dt(?string $dt): string {
    if (!$dt) return '';
    $ts = strtotime($dt);
    return $ts ? date('M d, Y h:i A', $ts) : '';
}

function notifications_time_ago(?string $dt): string {
    if (!$dt) return '';
    $ts = strtotime($dt);
    if (!$ts) return '';
    $diff = time() - $ts;
    if ($diff < 60) return 'just now';
    if ($diff < 3600) {
        $mins = (int)floor($diff / 60);
        return $mins . ' minute' . ($mins === 1 ? '' : 's') . ' ago';
    }
    if ($diff < 86400) {
        $hours = (int)floor($diff / 3600);
        return $hours . ' hour' . ($hours === 1 ? '' : 's') . ' ago';
    }
    $days = (int)floor($diff / 86400);
    if ($days < 7) return $days . ' day' . ($days === 1 ? '' : 's') . ' ago';
    return date('M j', $ts);
}

function notifications_category(string $text): string {
    $text = strtolower(trim($text));
    $peopleWords = ['friend', 'request', 'comment', 'reply', 'like', 'love', 'react', 'mention', 'follow'];
    foreach ($peopleWords as $word) {
        if (strpos($text, $word) !== false) return 'people';
    }
    return 'other';
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && empty($error)) {
    $action = trim((string)($_POST['action'] ?? ''));
    $receiverPh = implode(',', array_fill(0, count($receivers), '?'));

    try {
        if ($action === 'mark_all') {
            $st = $dbh->prepare("
                UPDATE notification
                SET is_read = 1
                WHERE notireceiver IN ($receiverPh)
                  AND is_read = 0
                  AND notitype NOT LIKE ?
                  AND notitype NOT LIKE ?
                  AND notitype NOT LIKE ?
            ");
            $st->execute(array_merge($receivers, ['New chat message%', 'Internal Chat%', 'New internal message%']));
            $message = 'All notifications marked as read.';
        } elseif ($action === 'mark_one') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                $st = $dbh->prepare("
                    UPDATE notification
                    SET is_read = 1
                    WHERE id = ?
                      AND notireceiver IN ($receiverPh)
                      AND notitype NOT LIKE ?
                      AND notitype NOT LIKE ?
                      AND notitype NOT LIKE ?
                    LIMIT 1
                ");
                $st->execute(array_merge([$id], $receivers, ['New chat message%', 'Internal Chat%', 'New internal message%']));
                $message = 'Notification marked as read.';
            }
        }
    } catch (Throwable $e) {
        $error = 'Could not update notifications right now.';
    }
}

$notifications = [];
$unreadCount = 0;

if (empty($error) && !empty($receivers)) {
    try {
        $receiverPh = implode(',', array_fill(0, count($receivers), '?'));
        $st = $dbh->prepare("
            SELECT id, notiuser, notitype, created_at, is_read
            FROM notification
            WHERE notireceiver IN ($receiverPh)
              AND notitype NOT LIKE ?
              AND notitype NOT LIKE ?
              AND notitype NOT LIKE ?
            ORDER BY created_at DESC, id DESC
            LIMIT 200
        ");
        $st->execute(array_merge($receivers, ['New chat message%', 'Internal Chat%', 'New internal message%']));
        $notifications = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($notifications as $row) {
            if ((int)($row['is_read'] ?? 0) === 0) {
                $unreadCount++;
            }
        }
    } catch (Throwable $e) {
        $error = 'Could not load notifications right now.';
        $notifications = [];
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Notifications</title>
  <?php
  require_once __DIR__ . '/includes/theme_prefs.php';
  theme_prefs_print_head_bootstrap($dbh, theme_prefs_viewer_user_id());
  ?>
  <link rel="stylesheet" href="css/font-awesome.min.css">
  <link rel="stylesheet" href="css/bootstrap.min.css">
  <link rel="stylesheet" href="css/style.css">
  <link href="./lib/font-awesome/css/font-awesome.css" rel="stylesheet">
  <link href="./lib/Ionicons/css/ionicons.css" rel="stylesheet">
  <link href="./lib/perfect-scrollbar/css/perfect-scrollbar.css" rel="stylesheet">
  <link rel="stylesheet" href="./css/shamcey.css">
  <link rel="stylesheet" href="assets/ui_best.css">
  <link rel="stylesheet" href="assets/layout-fixed.css">
  <script defer src="assets/layout-fixed.js"></script>
  <script src="./js/shamcey.js"></script>
  <script src="./js/dashboard.js"></script>
  <script src="./lib/jquery/jquery.js"></script>
  <script src="./lib/popper.js/popper.js"></script>
  <script src="./lib/bootstrap/bootstrap.js"></script>
  <script src="./lib/jquery-ui/jquery-ui.js"></script>
  <script src="./lib/perfect-scrollbar/js/perfect-scrollbar.jquery.js"></script>
  <script src="./lib/moment/moment.js"></script>
  <script src="assets/ui_best.js" defer></script>
  <style>
    body.notifications-page #globalLiveModal:not(.is-open){
      display:none !important;
      visibility:hidden !important;
      opacity:0 !important;
      pointer-events:none !important;
    }

    body.notifications-page #globalLiveModal:not(.is-open) .global-live-modal-dialog,
    body.notifications-page #globalLiveModal:not(.is-open) iframe,
    body.notifications-page #globalLiveModal:not(.is-open) video,
    body.notifications-page #globalLiveModal:not(.is-open) img,
    body.notifications-page #globalLiveModal:not(.is-open) aside{
      display:none !important;
    }

    body.notifications-page{
      background:#eef2f7;
    }

    .notifications-shell{
      max-width: 920px;
      margin: 0 auto;
      background:#fff;
      border:1px solid rgba(15,23,42,.08);
      border-radius:0;
      box-shadow:0 8px 24px rgba(15,23,42,.08);
      overflow:hidden;
    }

    .notifications-topbar{
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap:12px;
      padding:18px 22px 14px;
      border-bottom:1px solid #dbe2ea;
      background:linear-gradient(180deg, #ffffff, #fbfdff);
    }

    .notifications-title{
      font-size:23px;
      font-weight:800;
      color:#111827;
      margin:0;
    }

    .notifications-top-actions{
      display:flex;
      align-items:center;
      gap:10px;
      flex-wrap:wrap;
    }

    .notifications-top-link{
      border:none;
      background:transparent;
      color:#4b74c9;
      font-weight:700;
      font-size:14px;
      padding:6px 8px;
      cursor:pointer;
    }

    .notifications-top-link[disabled]{
      opacity:.55;
      cursor:default;
    }

    .notifications-tabs{
      display:flex;
      align-items:center;
      background:#fff;
      border-bottom:1px solid #dbe2ea;
    }

    .notifications-tab{
      flex:1 1 0;
      text-align:center;
      padding:14px 10px 13px;
      font-size:15px;
      font-weight:800;
      color:#2f3c4f;
      border-bottom:3px solid transparent;
      cursor:pointer;
      user-select:none;
    }

    .notifications-tab.is-active{
      color:#4b74c9;
      border-bottom-color:#4b74c9;
    }

    .notifications-summary{
      display:inline-flex;
      align-items:center;
      gap:8px;
      padding:10px 18px;
      margin:14px 20px;
      border-radius:999px;
      background:#f1f5fb;
      color:#55708f;
      font-weight:700;
      font-size:13px;
    }

    .notifications-group{
      border-top:1px solid #e3e8ef;
    }

    .notifications-group-label{
      padding:10px 22px;
      font-size:12px;
      font-weight:900;
      letter-spacing:.08em;
      text-transform:uppercase;
      color:#7b8794;
      background:#fbfcfe;
      border-bottom:1px solid #e3e8ef;
    }

    .notifications-list{
      display:block;
    }

    .notification-card{
      display:block;
      background:#fff;
      border-bottom:1px solid #e3e8ef;
      padding:0 18px;
    }

    .notification-card.is-unread{
      background:#edf4ff;
    }

    .notification-row{
      display:flex;
      align-items:center;
      gap:14px;
      padding:14px 0;
    }

    .notification-avatar{
      width:62px;
      height:62px;
      border-radius:999px;
      background:linear-gradient(135deg, #5c7cfa, #23408e);
      color:#fff;
      display:inline-flex;
      align-items:center;
      justify-content:center;
      font-weight:800;
      font-size:18px;
      flex:0 0 auto;
    }

    .notification-main{
      min-width:0;
      flex:1 1 auto;
    }

    .notification-text{
      color:#1f2937;
      font-size:17px;
      line-height:1.32;
      margin:0 0 8px;
    }

    .notification-from{
      font-weight:400;
    }

    .notification-strong{
      font-weight:800;
      color:#111827;
    }

    .notification-subrow{
      display:flex;
      align-items:center;
      gap:10px;
      flex-wrap:wrap;
      color:#7b8794;
      font-size:13px;
      font-weight:700;
    }

    .notification-mini-ico{
      width:18px;
      text-align:center;
      color:#6f86b1;
      font-size:16px;
    }

    .notification-time{
      color:#7b8794;
    }

    .notification-actions{
      display:flex;
      align-items:center;
      gap:8px;
      flex:0 0 auto;
      margin-left:auto;
    }

    .notification-btn{
      border:none;
      background:#eef3fb;
      color:#4b74c9;
      font-size:13px;
      font-weight:800;
      border-radius:999px;
      padding:8px 12px;
      cursor:pointer;
      white-space:nowrap;
    }

    .notification-btn.primary{
      background:#4b74c9;
      color:#fff;
    }

    .notification-empty{
      background:#fff;
      padding:40px 24px;
      text-align:center;
      color:#64748b;
      font-weight:700;
    }

    @media (max-width: 767.98px){
      .notifications-topbar,
      .notification-row{
        flex-direction:column;
        align-items:flex-start;
      }

      .notification-actions{
        width:100%;
        justify-content:flex-start;
        margin-left:0;
      }
    }
  </style>
</head>
<body class="notifications-page">

<?php $forceFeedRail = true; $skipHeaderThemeBootstrap = true; include __DIR__ . '/includes/header.php'; ?>

<div class="sh-mainpanel">
  <div class="sh-pagebody">
    <div class="container-fluid pd-20">
      <div class="notifications-shell">
        <div class="notifications-topbar">
          <h1 class="notifications-title">Notifications</h1>
          <div class="notifications-top-actions">
            <button type="button" class="notifications-top-link" disabled><i class="fa fa-volume-off"></i> Mute</button>
            <form method="post" class="mb-0">
              <input type="hidden" name="action" value="mark_all">
              <button type="submit" class="notifications-top-link"<?php echo empty($notifications) ? ' disabled' : ''; ?>>Mark all as read</button>
            </form>
            <button type="button" class="notifications-top-link" disabled>Settings</button>
          </div>
        </div>
        <div class="notifications-tabs">
          <div class="notifications-tab is-active" data-tab="all">All</div>
          <div class="notifications-tab" data-tab="people">People</div>
          <div class="notifications-tab" data-tab="other">Other</div>
        </div>

        <?php if ($error): ?>
          <div class="alert alert-danger"><?php echo htmlentities($error); ?></div>
        <?php endif; ?>
        <?php if ($message): ?>
          <div class="alert alert-success"><?php echo htmlentities($message); ?></div>
        <?php endif; ?>

        <div class="notifications-summary">
          <i class="icon ion-ios-bell-outline"></i>
          <span><?php echo $unreadCount > 0 ? ($unreadCount . ' unread notifications') : 'All caught up'; ?></span>
        </div>

        <?php if (empty($notifications)): ?>
          <div class="notification-empty">No notifications yet. New alerts will appear here.</div>
        <?php else: ?>
          <?php
            $todayRows = [];
            $earlierRows = [];
            foreach ($notifications as $item) {
                $created = strtotime((string)($item['created_at'] ?? ''));
                if ($created && date('Y-m-d', $created) === date('Y-m-d')) {
                    $todayRows[] = $item;
                } else {
                    $earlierRows[] = $item;
                }
            }
            $groups = [
                'Today' => $todayRows,
                'Earlier' => $earlierRows,
            ];
          ?>
          <?php foreach ($groups as $groupLabel => $groupItems): ?>
            <?php if (empty($groupItems)) continue; ?>
            <section class="notifications-group">
              <div class="notifications-group-label"><?php echo htmlentities($groupLabel); ?></div>
              <div class="notifications-list">
                <?php foreach ($groupItems as $item): ?>
                  <?php
                    $meta = notifications_parse_meta((string)($item['notitype'] ?? 'sent a notification'));
                    $sender = trim((string)($item['notiuser'] ?? 'Someone'));
                    $text = (string)($meta['text'] ?? 'sent a notification');
                    $url = trim((string)($meta['url'] ?? ''));
                    $isUnread = ((int)($item['is_read'] ?? 0) === 0);
                    $initials = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $sender), 0, 2));
                    $category = notifications_category($text);
                    if ($initials === '') $initials = 'NT';
                  ?>
                  <div class="notification-card<?php echo $isUnread ? ' is-unread' : ''; ?>" data-tab-card="<?php echo htmlentities($category); ?>">
                    <div class="notification-row">
                      <div class="notification-avatar"><?php echo htmlentities($initials); ?></div>
                      <div class="notification-main">
                        <p class="notification-text">
                          <span class="notification-strong"><?php echo htmlentities($sender); ?></span>
                          <span class="notification-from"><?php echo htmlentities(' ' . $text); ?></span>
                        </p>
                        <div class="notification-subrow">
                          <span class="notification-mini-ico"><i class="fa fa-calendar"></i></span>
                          <span class="notification-time"><?php echo htmlentities(notifications_time_ago((string)($item['created_at'] ?? ''))); ?></span>
                        </div>
                      </div>
                      <div class="notification-actions">
                        <?php if ($url !== ''): ?>
                          <a class="notification-btn primary" href="<?php echo htmlentities($url); ?>">Open</a>
                        <?php endif; ?>
                        <?php if ($isUnread): ?>
                          <form method="post" class="mb-0">
                            <input type="hidden" name="action" value="mark_one">
                            <input type="hidden" name="id" value="<?php echo (int)($item['id'] ?? 0); ?>">
                            <button type="submit" class="notification-btn">Mark read</button>
                          </form>
                        <?php endif; ?>
                      </div>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            </section>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<script>
setTimeout(function(){ $('.alert-success,.alert-danger').fadeOut(); }, 2500);
(function(){
  var tabs = Array.prototype.slice.call(document.querySelectorAll('.notifications-tab'));
  var cards = Array.prototype.slice.call(document.querySelectorAll('[data-tab-card]'));
  if(!tabs.length || !cards.length) return;

  tabs.forEach(function(tab){
    tab.addEventListener('click', function(){
      var mode = tab.getAttribute('data-tab') || 'all';
      tabs.forEach(function(item){ item.classList.toggle('is-active', item === tab); });
      cards.forEach(function(card){
        var cardMode = card.getAttribute('data-tab-card') || 'other';
        card.style.display = (mode === 'all' || mode === cardMode) ? '' : 'none';
      });
    });
  });
})();
</script>
</body>
</html>
