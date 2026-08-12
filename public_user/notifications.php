<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/session_user.php';
requireUserLogin();

require_once __DIR__ . '/controller.php';
require_once __DIR__ . '/includes/user_identity.php';
require_once __DIR__ . '/includes/friend_system.php';

$controller = new Controller();
$dbh = $controller->pdo();

$meId = (int)userId();
if ($meId <= 0) {
    clearUserSession();
    header('Location: index.php?session=reset');
    exit;
}

if (!function_exists('h')) {
    function h(string $s): string {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }
}

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
        } elseif ($route === 'shop') {
            $page = 'shop.php';
        } elseif ($route === 'orgsales') {
            $page = 'org_shop.php';
        }
        $params = ['open_post' => $postId];
        if ($commentId > 0) {
            $params['open_comment'] = $commentId;
        }
        $typeLower = strtolower($type);
        if (strpos($typeLower, 'mention') !== false || strpos($typeLower, 'tagged you') !== false) {
            $params['hide_nav'] = 1;
        }
        $url = $page . '?' . http_build_query($params);
    }

    return [
        'text' => $type,
        'url' => $url,
        'live_id' => $liveId,
    ];
}

function notifications_time_ago(?string $dt): string {
    if (!$dt) return '';
    $ts = strtotime($dt);
    if (!$ts) return '';
    $diff = time() - $ts;
    if ($diff < 60) return 'just now';
    if ($diff < 3600) {
        $mins = (int)floor($diff / 60);
        return $mins . 'm';
    }
    if ($diff < 86400) {
        $hours = (int)floor($diff / 3600);
        return $hours . 'h';
    }
    $days = (int)floor($diff / 86400);
    if ($days < 7) return $days . 'd';
    return date('M j', $ts);
}

function notifications_date_label(?string $dt): string {
    if (!$dt) return '';
    $ts = strtotime($dt);
    if (!$ts) return '';
    return date('M j', $ts);
}

/** Tab bucket: all | mentions */
function notifications_tab(string $text): string {
    $text = strtolower(trim($text));
    if (strpos($text, 'mention') !== false
      || strpos($text, 'tagged you') !== false
      || strpos($text, '@') !== false) {
        return 'mentions';
    }
    return 'all';
}

function notifications_icon(string $text, int $liveId = 0): array {
    $t = strtolower(trim($text));
    if ($liveId > 0 || strpos($t, 'live') !== false) {
        return ['fa-video-camera', 'is-live'];
    }
    if (preg_match('/\b(like|liked|love|loved|react|reaction)\b/', $t)) {
        return ['fa-heart', 'is-like'];
    }
    if (preg_match('/\b(comment|commented|reply|replied)\b/', $t) && strpos($t, 'mentioned') === false && strpos($t, 'tagged') === false) {
        return ['fa-comment', 'is-comment'];
    }
    if (strpos($t, 'mention') !== false || strpos($t, 'tagged you') !== false) {
        return ['fa-at', 'is-mention'];
    }
    if (preg_match('/\b(follow|friend|request)\b/', $t)) {
        return ['fa-user-plus', 'is-follow'];
    }
    return ['fa-bell', 'is-bell'];
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

$activeTab = strtolower(trim((string)($_GET['tab'] ?? 'all')));
if ($activeTab !== 'all' && $activeTab !== 'mentions') {
    $activeTab = 'all';
}

$unreadLabel = $unreadCount > 0
    ? ($unreadCount . ' unread')
    : 'All caught up';

// What’s happening — recent public posts from publishers
$happeningItems = [];
try {
    require_once __DIR__ . '/includes/publisher_accounts.php';
    $blockSql = function_exists('fs_block_exclude_author_sql')
        ? (' AND ' . fs_block_exclude_author_sql('p.user_id', ':happenBlockMe', ':happenBlockMe2'))
        : '';
    $happenSt = $dbh->prepare("
      SELECT
        p.id,
        COALESCE(NULLIF(TRIM(p.title), ''), '') AS title,
        COALESCE(NULLIF(TRIM(p.description), ''), '') AS description,
        COALESCE(NULLIF(TRIM(p.body), ''), '') AS body,
        COALESCE(u.name, u.username, CONCAT('Publisher ', u.id)) AS publisher_name,
        LOWER(TRIM(COALESCE(u.publisher_category, ''))) AS publisher_category
      FROM public_posts p
      INNER JOIN users u ON u.id = p.user_id
      WHERE p.is_deleted = 0
        AND COALESCE(p.is_archived, 0) = 0
        AND LOWER(COALESCE(NULLIF(TRIM(p.visibility), ''), 'public')) = 'public'
        AND COALESCE(u.account_kind, 'personal') = 'publisher'
        AND u.status = 1
        {$blockSql}
      ORDER BY COALESCE(p.updated_at, p.created_at) DESC
      LIMIT 5
    ");
    $happenParams = [];
    if ($blockSql !== '') {
        $happenParams[':happenBlockMe'] = $meId;
        $happenParams[':happenBlockMe2'] = $meId;
    }
    $happenSt->execute($happenParams);
    $happenRows = $happenSt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $catLabels = function_exists('publisher_categories_builtin') ? publisher_categories_builtin() : [];
    foreach ($happenRows as $hp) {
        $postId = (int)($hp['id'] ?? 0);
        if ($postId <= 0) continue;
        $title = trim((string)($hp['title'] ?? ''));
        if ($title === '') {
            $title = trim(preg_replace('/\s+/', ' ', strip_tags((string)($hp['description'] ?? ''))) ?? '');
        }
        if ($title === '') {
            $title = trim(preg_replace('/\s+/', ' ', strip_tags((string)($hp['body'] ?? ''))) ?? '');
        }
        if ($title === '') $title = 'New post';
        if (mb_strlen($title) > 72) {
            $title = rtrim(mb_substr($title, 0, 69)) . '…';
        }
        $publisherName = trim((string)($hp['publisher_name'] ?? 'Publisher'));
        $catKey = trim((string)($hp['publisher_category'] ?? ''));
        $catLabel = ($catKey !== '' && isset($catLabels[$catKey]))
            ? (string)$catLabels[$catKey]
            : ($catKey !== '' ? ucwords(str_replace('-', ' ', $catKey)) : 'Publisher');
        $happeningItems[] = [
            'id' => $postId,
            'title' => $title,
            'meta' => $catLabel . ' · ' . $publisherName,
            'href' => 'public.php?post=' . $postId,
        ];
    }
} catch (Throwable $e) {
    $happeningItems = [];
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
  <script src="./lib/perfect-scrollbar/js/perfect-scrollbar.jquery.js"></script>
  <script src="assets/ui_best.js" defer></script>
  <style>
    :root{
      --x-text: var(--msb-palette-text, #0f1419);
      --x-muted: var(--msb-palette-text-muted, #536471);
      --x-border: var(--msb-palette-border, #eff3f4);
      --x-hover: var(--msb-palette-hover-bg, rgba(15,20,25,.03));
      --x-bg: var(--msb-palette-bg, #fff);
      --x-accent: var(--msb-palette-action, #1d9bf0);
      --x-unread: color-mix(in srgb, var(--x-accent) 8%, var(--x-bg));
    }

    html, body.notifications-page {
      height: 100%;
      overflow: hidden;
      background: var(--x-bg) !important;
      color: var(--x-text);
    }

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

    .sh-mainpanel{
      height: 80vh;
      display: flex;
      flex-direction: column;
      overflow: hidden;
      margin-left: 340px;
      margin-top: 20px;
      background: transparent !important;
    }
    .sh-pagebody{
      flex: 1 1 auto;
      min-height: 0;
      overflow: hidden;
      display: flex;
      flex-direction: column;
      background: transparent !important;
      padding: 0 !important;
    }

    .noti-layout{
      flex: 1 1 auto;
      min-height: 0;
      display: flex;
      align-items: stretch;
      gap: 16px;
      padding: 0 12px 8px 0;
      box-sizing: border-box;
      overflow: hidden;
    }

    .noti-shell{
      flex: 1 1 auto;
      min-width: 0;
      min-height: 0;
      overflow: hidden;
      display: flex;
      flex-direction: column;
      margin: 0;
      max-width: 860px;
      width: 100%;
      background: var(--x-bg) !important;
      color: var(--x-text);
      box-sizing: border-box;
    }

    .noti-fixed{
      flex: 0 0 auto;
      position: sticky;
      top: 0;
      z-index: 5;
      background: color-mix(in srgb, var(--x-bg) 88%, transparent);
      backdrop-filter: blur(12px);
      -webkit-backdrop-filter: blur(12px);
    }
    .noti-head-chrome{
      border-bottom: 1px solid var(--x-border);
    }

    .noti-scroll{
      flex: 1 1 auto;
      min-height: 0;
      overflow: auto;
      -webkit-overflow-scrolling: touch;
      border-left: 1px solid var(--x-border);
      border-right: 1px solid var(--x-border);
      border-bottom: 1px solid var(--x-border);
    }

    .x-topbar{
      display:flex;
      align-items:center;
      gap:12px;
      padding:10px 16px;
      min-height:53px;
      box-sizing:border-box;
    }
    .x-top-meta{min-width:0;flex:1 1 auto;}
    .x-top-name{
      margin:0;
      font-size:20px;
      font-weight:800;
      line-height:1.2;
      letter-spacing:-.02em;
      color:var(--x-text);
    }
    .x-top-sub{
      margin:2px 0 0;
      font-size:13px;
      line-height:1.2;
      color:var(--x-muted);
    }
    .x-settings-btn{
      display:inline-flex;
      align-items:center;
      justify-content:center;
      width:36px;
      height:36px;
      border:0;
      border-radius:999px;
      background:transparent;
      color:var(--x-text);
      cursor:pointer;
      padding:0;
      flex:0 0 auto;
      transition:background .15s ease;
    }
    .x-settings-btn:hover{background:var(--x-hover);}
    .x-settings-btn:disabled{opacity:.45;cursor:default;}
    .x-settings-btn i{font-size:18px;}

    .x-tabs{
      display:flex;
      width:100%;
    }
    .x-tab{
      flex:1 1 0;
      display:flex;
      align-items:center;
      justify-content:center;
      min-height:53px;
      padding:12px 8px;
      border:0;
      background:transparent;
      color:var(--x-muted);
      font-size:15px;
      font-weight:600;
      text-decoration:none;
      position:relative;
      box-sizing:border-box;
      transition:background .15s ease,color .15s ease;
      cursor:pointer;
      font-family:inherit;
      appearance:none;
      -webkit-appearance:none;
    }
    .x-tab:hover{background:var(--x-hover);color:var(--x-text);text-decoration:none;}
    .x-tab.is-active{
      color:var(--x-text);
      font-weight:800;
    }
    .x-tab.is-active::after{
      content:'';
      position:absolute;
      left:50%;
      bottom:0;
      transform:translateX(-50%);
      width:56px;
      max-width:70%;
      height:4px;
      border-radius:999px;
      background:var(--x-accent);
    }

    .noti-shell .alert{
      margin:12px 16px;
      border-radius:12px;
    }

    .x-noti-row[data-href]{cursor:pointer;}
    .x-noti-row{
      display:flex;
      align-items:flex-start;
      gap:12px;
      width:100%;
      padding:14px 16px;
      border-bottom:1px solid var(--x-border);
      box-sizing:border-box;
      background:var(--x-bg);
      transition:background .12s ease;
      text-decoration:none;
      color:inherit;
      position:relative;
    }
    .x-noti-row:hover{background:var(--x-hover);text-decoration:none;color:inherit;}
    .x-noti-row.is-unread{background:var(--x-unread);}
    .x-noti-row.is-unread:hover{background:color-mix(in srgb, var(--x-accent) 12%, var(--x-bg));}
    .x-noti-row[hidden]{display:none !important;}

    .x-noti-icon{
      flex:0 0 auto;
      width:28px;
      padding-top:6px;
      text-align:center;
      color:var(--x-accent);
      font-size:18px;
      line-height:1;
    }
    .x-noti-icon.is-like{color:#f91880;}
    .x-noti-icon.is-comment{color:var(--x-accent);}
    .x-noti-icon.is-mention{color:#00ba7c;}
    .x-noti-icon.is-follow{color:var(--x-accent);}
    .x-noti-icon.is-live{color:#f4212e;}
    .x-noti-icon.is-bell{color:var(--x-accent);}

    .x-noti-avatar{
      flex:0 0 auto;
      width:40px;
      height:40px;
      border-radius:999px;
      overflow:hidden;
      display:inline-flex;
      align-items:center;
      justify-content:center;
      font-weight:800;
      font-size:14px;
      color:#fff;
      background:#536471;
    }
    .x-noti-avatar img{
      width:100%;
      height:100%;
      object-fit:cover;
      display:block;
    }

    .x-noti-main{min-width:0;flex:1 1 auto;}
    .x-noti-head{
      display:flex;
      align-items:baseline;
      gap:6px;
      flex-wrap:wrap;
      margin:0 0 4px;
      font-size:15px;
      line-height:1.3;
      color:var(--x-text);
      padding-right:36px;
    }
    .x-noti-name{
      font-weight:800;
      color:var(--x-text);
    }
    .x-noti-sep{color:var(--x-muted);}
    .x-noti-time{color:var(--x-muted);font-weight:500;font-size:14px;}
    .x-noti-body{
      margin:0;
      font-size:15px;
      line-height:1.35;
      color:var(--x-text);
      word-break:break-word;
    }

    .x-noti-more{
      position:absolute;
      top:10px;
      right:10px;
    }
    .x-noti-more .dropdown-toggle{
      width:34px;
      height:34px;
      border:0;
      border-radius:999px;
      background:transparent;
      color:var(--x-muted);
      display:inline-flex;
      align-items:center;
      justify-content:center;
      padding:0;
      cursor:pointer;
    }
    .x-noti-more .dropdown-toggle:hover,
    .x-noti-more .dropdown-toggle:focus{
      background:rgba(29,155,240,.1);
      color:var(--x-accent);
      outline:none;
      box-shadow:none;
    }
    .x-noti-more .dropdown-toggle::after{display:none;}
    .x-noti-more .dropdown-menu{
      border-radius:12px;
      border:1px solid var(--x-border);
      box-shadow:0 8px 28px rgba(15,20,25,.12);
      min-width:160px;
      padding:6px 0;
    }
    .x-noti-more .dropdown-item{
      font-size:14px;
      font-weight:600;
      color:var(--x-text);
      padding:10px 14px;
    }
    .x-noti-more .dropdown-item:hover{background:var(--x-hover);}
    .x-noti-more form{margin:0;}
    .x-noti-more button.dropdown-item{
      width:100%;
      text-align:left;
      background:transparent;
      border:0;
      cursor:pointer;
    }

    .x-empty{
      padding:48px 24px;
      text-align:center;
    }
    .x-empty h3{
      margin:0 0 8px;
      font-size:28px;
      font-weight:900;
      letter-spacing:-.02em;
      color:var(--x-text);
    }
    .x-empty p{
      margin:0;
      font-size:15px;
      color:var(--x-muted);
      line-height:1.4;
    }

    /* Right rail */
    .x-right-rail{
      flex: 0 0 350px;
      width: 350px;
      max-width: 350px;
      min-height: 0;
      align-self: stretch;
      display: flex;
      flex-direction: column;
      overflow: auto;
      -webkit-overflow-scrolling: touch;
      padding: 4px 0 24px;
      box-sizing: border-box;
      scrollbar-width: thin;
    }
    .x-rail-search{
      position: sticky;
      top: 0;
      z-index: 2;
      padding: 10px 0 12px;
      background: color-mix(in srgb, var(--x-bg) 92%, transparent);
      backdrop-filter: blur(10px);
      -webkit-backdrop-filter: blur(10px);
    }
    .x-rail-search-field{position: relative;}
    .x-rail-search-field .fa-search{
      position: absolute;
      right: 14px;
      top: 50%;
      transform: translateY(-50%);
      color: var(--x-muted);
      font-size: 14px;
      pointer-events: none;
    }
    .x-rail-search-input{
      width: 100%;
      height: 44px;
      border-radius: 999px;
      border: 1px solid var(--x-border);
      background: var(--msb-palette-input-bg, var(--msb-palette-surface-2, #eff3f4));
      color: var(--x-text);
      padding: 0 42px 0 16px;
      font-size: 15px;
      outline: none;
      box-sizing: border-box;
    }
    .x-rail-search-input::placeholder{color: var(--x-muted);}
    .x-rail-search-input:focus{
      background: var(--x-bg);
      border-color: var(--x-accent);
    }

    .x-rail-card{
      background: var(--x-bg);
      border: 1px solid var(--x-border);
      border-radius: 16px;
      margin-bottom: 16px;
      overflow: hidden;
      box-sizing: border-box;
    }
    .x-rail-card-pad{padding: 14px 16px 8px;}
    .x-rail-card-title{
      margin: 0;
      font-size: 20px;
      font-weight: 900;
      letter-spacing: -.02em;
      color: var(--x-text);
      line-height: 1.2;
    }
    .x-trend{
      display: block;
      width: 100%;
      padding: 12px 16px;
      border: 0;
      background: transparent;
      text-align: left;
      text-decoration: none;
      color: inherit;
      box-sizing: border-box;
      position: relative;
      transition: background .12s ease;
    }
    .x-trend:hover{background: var(--x-hover); text-decoration: none; color: inherit;}
    .x-trend-meta{
      display: block;
      font-size: 13px;
      color: var(--x-muted);
      line-height: 1.2;
      margin-bottom: 2px;
    }
    .x-trend-title{
      display: block;
      font-size: 15px;
      font-weight: 800;
      color: var(--x-text);
      line-height: 1.25;
      padding-right: 28px;
    }
    .x-trend-more{
      position: absolute;
      top: 10px;
      right: 12px;
      width: 30px;
      height: 30px;
      border-radius: 999px;
      color: var(--x-muted);
      display: inline-flex;
      align-items: center;
      justify-content: center;
    }
    .x-rail-show-more{
      display: block;
      padding: 14px 16px;
      color: var(--x-accent);
      font-size: 15px;
      font-weight: 500;
      text-decoration: none;
    }
    .x-rail-show-more:hover{background: var(--x-hover); text-decoration: none; color: var(--x-accent);}

    .x-rail-main{
      margin-top: 56px;
      flex: 0 0 auto;
      width: 100%;
    }
    .x-rail-footer{
      margin-top: auto;
      padding: 16px 8px 0;
      font-size: 13px;
      line-height: 1.5;
      color: var(--x-muted);
      flex: 0 0 auto;
    }
    .x-rail-footer a{
      color: var(--x-muted);
      text-decoration: none;
    }
    .x-rail-footer a:hover{text-decoration: underline;}
    .x-rail-footer-sep{margin: 0 4px; opacity: .7;}

    body.notifications-page.feed-insta-ui .x-right-rail .feed-right-rail{
      display:block !important;
      position:static !important;
      left:auto !important;
      top:auto !important;
      right:auto !important;
      bottom:auto !important;
      width:100% !important;
      max-width:none !important;
      height:auto !important;
      max-height:none !important;
      margin:0 0 16px !important;
      padding:0 !important;
      overflow:visible !important;
      z-index:auto !important;
      background:transparent !important;
      border:0 !important;
      box-shadow:none !important;
    }
    body.notifications-page.feed-insta-ui .x-right-rail .sfy-panel{
      margin:0 !important;
      padding:12px 14px 8px !important;
      border:1px solid var(--x-border) !important;
      border-radius:16px !important;
      background:var(--x-bg) !important;
      height:auto !important;
      max-height:min(420px, 55vh) !important;
      min-height:0 !important;
      box-sizing:border-box !important;
    }
    body.notifications-page.feed-insta-ui .x-right-rail .sfy-panel-head{margin:0 0 8px !important;}
    body.notifications-page.feed-insta-ui .x-right-rail .sfy-title{
      font-size:20px !important;
      font-weight:900 !important;
      letter-spacing:-.02em !important;
      color:var(--x-text) !important;
    }
    body.notifications-page.feed-insta-ui .x-right-rail .sfy-panel-body{
      padding-right:4px !important;
      max-height:min(340px, 48vh) !important;
    }

    html[data-msb-appearance] body.notifications-page,
    html[data-msb-appearance] body.notifications-page .sh-mainpanel,
    html[data-msb-appearance] body.notifications-page .sh-pagebody,
    html[data-msb-appearance] body.notifications-page .noti-shell,
    html[data-msb-appearance] body.notifications-page .noti-fixed,
    html[data-msb-appearance] body.notifications-page .noti-scroll,
    html[data-msb-appearance] body.notifications-page .x-right-rail,
    html[data-msb-appearance] body.notifications-page .x-rail-card{
      background-color: var(--msb-palette-bg) !important;
      color: var(--msb-palette-text) !important;
    }

    @media (max-width: 1280px) {
      .x-right-rail{flex-basis: 300px; width: 300px; max-width: 300px;}
    }
    @media (max-width: 1100px) {
      .x-right-rail{display: none;}
      .noti-layout{padding-right: 0;}
      .noti-shell{max-width: none;}
    }
    @media (max-width: 991.98px) {
      html, body.notifications-page { height: auto !important; overflow: auto !important; }
      .sh-mainpanel{
        margin-left: 0 !important;
        margin-top: 0 !important;
        height: auto !important;
        min-height: 100vh;
      }
      .sh-pagebody{ overflow: visible !important; }
      .noti-layout{
        display: block;
        overflow: visible;
        padding: 0;
      }
      .noti-shell{
        margin: 0 !important;
        max-width: none;
        height: auto !important;
      }
      .noti-scroll{ overflow: visible; border-left:0; border-right:0; }
    }
  </style>
</head>
<body class="notifications-page feed-insta-ui">

<?php $forceFeedRail = true; $skipHeaderThemeBootstrap = true; include __DIR__ . '/includes/header.php'; ?>

<div class="sh-mainpanel">
  <div class="sh-pagebody">
    <div class="noti-layout">
      <div class="noti-shell">
        <div class="noti-fixed">
          <div class="noti-head-chrome">
            <div class="x-topbar">
              <div class="x-top-meta">
                <h1 class="x-top-name">Notifications</h1>
                <p class="x-top-sub"><?= h($unreadLabel) ?></p>
              </div>
              <form method="post" class="mb-0">
                <input type="hidden" name="action" value="mark_all">
                <button type="submit" class="x-settings-btn" title="Mark all as read" aria-label="Mark all as read"<?= empty($notifications) || $unreadCount <= 0 ? ' disabled' : '' ?>>
                  <i class="fa fa-cog" aria-hidden="true"></i>
                </button>
              </form>
            </div>
            <nav class="x-tabs" aria-label="Notification filters">
              <button type="button" class="x-tab<?= $activeTab === 'all' ? ' is-active' : '' ?>" data-noti-tab="all"<?= $activeTab === 'all' ? ' aria-current="page"' : '' ?>>All</button>
              <button type="button" class="x-tab<?= $activeTab === 'mentions' ? ' is-active' : '' ?>" data-noti-tab="mentions"<?= $activeTab === 'mentions' ? ' aria-current="page"' : '' ?>>Mentions</button>
            </nav>
          </div>
          <?php if ($error): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>
          <?php if ($message): ?><div class="alert alert-success"><?= h($message) ?></div><?php endif; ?>
        </div>

        <div class="noti-scroll">
          <?php if (empty($notifications)): ?>
            <div class="x-empty">
              <h3>Nothing to see here — yet</h3>
              <p>From likes to mentions and more, this is where you’ll find all your notifications.</p>
            </div>
          <?php else: ?>
            <div class="x-noti-list" id="notiList">
              <?php foreach ($notifications as $item): ?>
                <?php
                  $meta = notifications_parse_meta((string)($item['notitype'] ?? 'sent a notification'));
                  $sender = trim((string)($item['notiuser'] ?? 'Someone'));
                  $text = (string)($meta['text'] ?? 'sent a notification');
                  $url = trim((string)($meta['url'] ?? ''));
                  $liveId = (int)($meta['live_id'] ?? 0);
                  $isUnread = ((int)($item['is_read'] ?? 0) === 0);
                  $tab = notifications_tab($text);
                  [$iconFa, $iconClass] = notifications_icon($text, $liveId);
                  $initials = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $sender) ?: 'NT', 0, 2));
                  $peerKey = function_exists('normalize_avatar_key') ? normalize_avatar_key($sender) : $sender;
                  $peerColor = function_exists('color_from_string') ? color_from_string($peerKey) : '#536471';
                  $peerGrad = function_exists('avatar_gradient_style') ? avatar_gradient_style($peerColor) : ('background:' . $peerColor);
                  $avatarUrl = 'avatar.php?name=' . rawurlencode($sender);
                  $dateLabel = notifications_date_label((string)($item['created_at'] ?? ''));
                  $timeAgo = notifications_time_ago((string)($item['created_at'] ?? ''));
                  $nid = (int)($item['id'] ?? 0);
                  $rowHidden = ($activeTab === 'mentions' && $tab !== 'mentions');
                ?>
                <article class="x-noti-row<?= $isUnread ? ' is-unread' : '' ?>"
                         data-noti-card="<?= h($tab) ?>"
                         data-id="<?= $nid ?>"
                         <?php if ($url !== ''): ?>data-href="<?= h($url) ?>" role="link" tabindex="0"<?php endif; ?>
                         <?= $rowHidden ? 'hidden' : '' ?>>
                  <div class="x-noti-icon <?= h($iconClass) ?>" aria-hidden="true">
                    <i class="fa <?= h($iconFa) ?>"></i>
                  </div>
                  <div class="x-noti-avatar" data-avatar-key="<?= h($peerKey) ?>" style="<?= h($peerGrad) ?>">
                    <img src="<?= h($avatarUrl) ?>" alt="" data-live-avatar="1" data-avatar-base="<?= h($avatarUrl) ?>">
                  </div>
                  <div class="x-noti-main">
                    <div class="x-noti-head">
                      <span class="x-noti-name"><?= h($sender) ?></span>
                      <?php if ($dateLabel !== ''): ?>
                        <span class="x-noti-sep" aria-hidden="true">·</span>
                        <span class="x-noti-time" title="<?= h($timeAgo) ?>"><?= h($dateLabel) ?></span>
                      <?php endif; ?>
                    </div>
                    <p class="x-noti-body"><?= h($text) ?></p>
                  </div>
                  <div class="x-noti-more dropdown">
                    <button type="button" class="dropdown-toggle" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false" aria-label="More">
                      <i class="fa fa-ellipsis-h" aria-hidden="true"></i>
                    </button>
                    <div class="dropdown-menu dropdown-menu-right">
                      <?php if ($url !== ''): ?>
                        <a class="dropdown-item" href="<?= h($url) ?>">Open</a>
                      <?php endif; ?>
                      <?php if ($isUnread): ?>
                        <form method="post">
                          <input type="hidden" name="action" value="mark_one">
                          <input type="hidden" name="id" value="<?= $nid ?>">
                          <button type="submit" class="dropdown-item">Mark as read</button>
                        </form>
                      <?php endif; ?>
                      <?php if ($url === '' && !$isUnread): ?>
                        <span class="dropdown-item text-muted" style="cursor:default;">No actions</span>
                      <?php endif; ?>
                    </div>
                  </div>
                </article>
              <?php endforeach; ?>
            </div>
            <div class="x-empty" id="notiEmptyMentions" hidden>
              <h3>Nothing in Mentions</h3>
              <p>When someone mentions you, it’ll show up here.</p>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <aside class="x-right-rail" aria-label="Explore">
        <div class="x-rail-search">
          <form class="x-rail-search-field" action="public.php" method="get" role="search">
            <i class="fa fa-search" aria-hidden="true"></i>
            <input class="x-rail-search-input" type="search" name="q" placeholder="Search" autocomplete="off">
          </form>
        </div>

        <div class="x-rail-main">
          <div class="x-rail-card">
            <div class="x-rail-card-pad">
              <h2 class="x-rail-card-title">What’s happening</h2>
            </div>
            <?php if ($happeningItems): ?>
              <?php foreach ($happeningItems as $item): ?>
                <a class="x-trend" href="<?= h((string)$item['href']) ?>">
                  <span class="x-trend-meta"><?= h((string)$item['meta']) ?></span>
                  <span class="x-trend-title"><?= h((string)$item['title']) ?></span>
                  <span class="x-trend-more" aria-hidden="true"><i class="fa fa-ellipsis-h"></i></span>
                </a>
              <?php endforeach; ?>
              <a class="x-rail-show-more" href="public.php">Show more</a>
            <?php else: ?>
              <p class="x-trend" style="cursor:default;pointer-events:none;">
                <span class="x-trend-meta">Publishers</span>
                <span class="x-trend-title" style="font-weight:600;color:var(--x-muted);">No publisher posts yet.</span>
              </p>
              <a class="x-rail-show-more" href="public.php">Explore public</a>
            <?php endif; ?>
          </div>

          <?php
            require_once __DIR__ . '/includes/staff_publisher_access.php';
            $suggestedForYouStaffReadonly = staff_pub_is_readonly();
            $suggestedForYouMaxFriends = 6;
            $suggestedForYouMaxFollow = 0;
            $suggestedForYouMaxAdvertise = 0;
            include __DIR__ . '/includes/suggested_for_you.php';
          ?>
        </div>

        <div class="x-rail-footer">
          <a href="#">Terms</a><span class="x-rail-footer-sep">·</span>
          <a href="#">Privacy</a><span class="x-rail-footer-sep">·</span>
          <a href="#">Cookies</a><span class="x-rail-footer-sep">·</span>
          <a href="#">Accessibility</a><span class="x-rail-footer-sep">·</span>
          <a href="#">Ads info</a><span class="x-rail-footer-sep">·</span>
          <a href="#">More...</a>
          <div style="margin-top:4px;">© <?= date('Y') ?> Talentra</div>
        </div>
      </aside>
    </div>
  </div>
</div>

<script>
setTimeout(function(){ $('.alert-success,.alert-danger').fadeOut(); }, 2500);
(function(){
  var tabs = Array.prototype.slice.call(document.querySelectorAll('[data-noti-tab]'));
  var cards = Array.prototype.slice.call(document.querySelectorAll('[data-noti-card]'));
  var emptyMentions = document.getElementById('notiEmptyMentions');
  if (!tabs.length) return;

  function syncEmpty(mode){
    if (!emptyMentions) return;
    if (mode !== 'mentions') {
      emptyMentions.hidden = true;
      return;
    }
    var visible = cards.some(function(card){
      return card.getAttribute('data-noti-card') === 'mentions' && !card.hidden;
    });
    emptyMentions.hidden = visible || cards.length === 0;
  }

  function setTab(mode){
    mode = mode === 'mentions' ? 'mentions' : 'all';
    tabs.forEach(function(tab){
      var on = (tab.getAttribute('data-noti-tab') || 'all') === mode;
      tab.classList.toggle('is-active', on);
      if (on) tab.setAttribute('aria-current', 'page');
      else tab.removeAttribute('aria-current');
    });
    cards.forEach(function(card){
      var cardMode = card.getAttribute('data-noti-card') || 'all';
      card.hidden = !(mode === 'all' || cardMode === mode);
    });
    syncEmpty(mode);
    try {
      var url = new URL(window.location.href);
      if (mode === 'all') url.searchParams.delete('tab');
      else url.searchParams.set('tab', mode);
      history.replaceState({}, '', url.pathname + url.search);
    } catch (e) {}
  }

  tabs.forEach(function(tab){
    tab.addEventListener('click', function(){
      setTab(tab.getAttribute('data-noti-tab') || 'all');
    });
  });

  function openNotiRow(row){
    if (!row) return;
    var href = String(row.getAttribute('data-href') || '').trim();
    if (!href) return;
    var openPostId = 0;
    try {
      var u = new URL(href, window.location.href);
      openPostId = parseInt(u.searchParams.get('open_post') || u.searchParams.get('post') || '0', 10) || 0;
    } catch (eUrl) {}
    if (openPostId > 0 && typeof window.pvOpenById === 'function') {
      var hideNav = false;
      try {
        var u = new URL(href, window.location.href);
        hideNav = u.searchParams.get('hide_nav') === '1';
      } catch (eNav) {}
      try { window.pvOpenById(openPostId, hideNav ? { hideNav: true } : {}); } catch (eOpen) {}
      return;
    }
    window.location.href = href;
  }

  document.addEventListener('click', function(e){
    var row = e.target && e.target.closest ? e.target.closest('.x-noti-row[data-href]') : null;
    if (!row) return;
    if (e.target.closest('.x-noti-more, a, button, form, input')) return;
    e.preventDefault();
    openNotiRow(row);
  });
  document.addEventListener('keydown', function(e){
    if (e.key !== 'Enter' && e.key !== ' ') return;
    var row = e.target && e.target.closest ? e.target.closest('.x-noti-row[data-href]') : null;
    if (!row || e.target !== row) return;
    e.preventDefault();
    openNotiRow(row);
  });

  syncEmpty(<?= json_encode($activeTab) ?>);
})();
</script>
</body>
</html>
