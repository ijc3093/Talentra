<?php
// /Business_only3/includes/header.php
require_once __DIR__ . '/session_user.php';
requireUserLogin();

require_once __DIR__ . '/user_identity.php';
require_once __DIR__ . '/publisher_accounts.php';
require_once __DIR__ . '/../controller.php';

$controller = new Controller();
$dbh = $controller->pdo();

require_once __DIR__ . '/theme_prefs.php';
require_once __DIR__ . '/staff_publisher_access.php';
require_once __DIR__ . '/publisher_organization_bridge.php';
$headerStaffReadonly = staff_pub_is_readonly();
$headerStaffRoleLabel = trim((string)($_SESSION['portal_staff_role_label'] ?? 'Staff'));
if ($headerStaffRoleLabel === '') {
    $headerStaffRoleLabel = 'Staff';
}
$meId = theme_prefs_viewer_user_id();
$headerCanLiveStudio = live_studio_user_can_access($dbh, $meId);

if ($meId > 0 && function_exists('publisher_session_ensure_owner_binding')) {
  try {
    publisher_session_ensure_owner_binding($dbh);
    $meId = theme_prefs_viewer_user_id();
  } catch (Throwable $e) {
    // ignore
  }
}
$msbPublisherAccountUi = ($meId > 0 && function_exists('publisher_account_is') && publisher_account_is($dbh, $meId));

// ---------- helpers ----------
if (!function_exists('h')) {
  function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}

if (!function_exists('normalize_avatar_key')) {
  /**
   * ✅ IMPORTANT:
   * We DO NOT lowercase anymore, because feed.php (JS) hashes "John K" with original casing.
   * If PHP lowercases, it becomes "john k" => different hash => different color.
   *
   * So we normalize ONLY by trimming and collapsing spaces.
   */
  function normalize_avatar_key(string $s): string {
    $s = trim($s);
    $s = preg_replace('/\s+/', ' ', $s) ?? $s;
    return $s; // <-- keep original case
  }
}

if (!function_exists('initials_from_name')) {
  function initials_from_name(string $name, string $fallback = 'ME'): string {
    $name = trim($name);
    if ($name === '') return $fallback;

    $parts = preg_split('/\s+/', $name) ?: [];
    $parts = array_values(array_filter($parts, function ($x) {
      return trim((string)$x) !== '';
    }));
    if (!$parts) return $fallback;

    $a = mb_substr($parts[0], 0, 1);
    $b = (count($parts) > 1) ? mb_substr($parts[count($parts)-1], 0, 1) : '';
    $ini = mb_strtoupper($a . $b);

    if ($ini === '') $ini = mb_strtoupper(mb_substr($name, 0, 2));
    return $ini ?: $fallback;
  }
}

if (!function_exists('color_from_string')) {
  /**
   * ✅ Stable color: SAME normalized string => SAME color (always)
   * Palette MUST match what you use in feed.php JS.
   */
  function color_from_string(string $str): string {
    $colors = ['#4f46e5','#0ea5e9','#10b981','#f59e0b','#ef4444','#8b5cf6','#14b8a6','#f43f5e','#6366f1'];

    $str = normalize_avatar_key((string)$str);
    if ($str === '') $str = 'User';

    $hash = 0;
    $len = strlen($str);
    for ($i=0; $i<$len; $i++) {
      $hash = ord($str[$i]) + (($hash << 5) - $hash);
      $hash = $hash & 0xFFFFFFFF;
    }
    $idx = abs((int)$hash) % count($colors);
    return $colors[$idx];
  }
}

if (!function_exists('avatar_gradient_style')) {
  function avatar_gradient_style(string $baseHex): string {
    $baseHex = trim($baseHex) ?: '#4f46e5';
    return "background: radial-gradient(circle at 30% 25%, rgba(255,255,255,.35), rgba(255,255,255,0) 40%), linear-gradient(135deg, {$baseHex}, #111827);";
  }
}

if (!function_exists('parse_notification_meta')) {
  function parse_notification_meta(string $type): array {
    $type = trim($type);
    $liveId = 0;
    $route = '';
    $postId = 0;
    $commentId = 0;
    $isStory = false;

    while (preg_match('/\s\[(live|r|p|c|story):([^\]]+)\]\s*$/', $type, $m)) {
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
      } elseif ($key === 'story') {
        $isStory = ((int)$value === 1) || strtolower($value) === '1' || strtolower($value) === 'true';
      }
      $type = trim((string)preg_replace('/\s\[(?:live|r|p|c|story):[^\]]+\]\s*$/', '', $type, 1));
    }
    if (!$isStory && (stripos($type, ' in a story') !== false || stripos($type, 'story') !== false && (stripos($type, 'tagged') !== false || stripos($type, 'mentioned') !== false))) {
      $isStory = stripos($type, ' in a story') !== false;
    }

    $url = '';
    if ($liveId > 0) {
      $url = 'live_watch.php?live=' . $liveId;
    } elseif ($route === 'shop') {
      $url = 'Your_Shopping_preferences.php#notifications';
    } elseif ($route === 'orgsales') {
      $url = '../organization/sales_management.php#notification';
    } elseif ($postId > 0 && $isStory) {
      $url = 'home.php?tab=for-you&story_post=' . $postId;
    } elseif ($postId > 0) {
      $page = 'feed.php';
      if ($route === 'pf') {
        $page = 'profile.php';
      } elseif ($route === 'pb') {
        $page = 'public.php';
      } elseif ($route === 'fd') {
        $page = 'home.php';
      }
      $params = ['open_post' => $postId];
      if ($page === 'home.php') {
        $params['tab'] = 'for-you';
      }
      if ($commentId > 0) $params['open_comment'] = $commentId;
      // Mention / tag alerts open a single post — hide gallery prev/next.
      $typeLower = strtolower($type);
      if (strpos($typeLower, 'mention') !== false || strpos($typeLower, 'tagged you') !== false) {
        $params['hide_nav'] = 1;
      }
      $url = $page . '?' . http_build_query($params);
    }

    return [
      'text' => $type,
      'live_id' => $liveId,
      'post_id' => $postId,
      'is_story' => $isStory ? 1 : 0,
      'url' => $url
    ];
  }
}

// ---------- session identity ----------
$meCode = '';
if (function_exists('userFriendCode')) $meCode = trim((string) userFriendCode());
if ($meCode === '') $meCode = trim((string)($_SESSION['user_friend_code'] ?? ($_SESSION['friend_code'] ?? '')));

$meEmail = '';
if (function_exists('userEmail')) $meEmail = trim((string) userEmail());
if ($meEmail === '') $meEmail = trim((string)($_SESSION['user_email'] ?? ($_SESSION['email'] ?? '')));

$meName = '';
if (function_exists('myUserName')) $meName = trim((string) myUserName());
if ($meName === '') $meName = trim((string)($_SESSION['name'] ?? ($_SESSION['user_name'] ?? '')));
if ($meName === '') $meName = 'My Account';

require_once __DIR__ . '/account_display_helpers.php';
$meMenuIsPublisher = strtolower(trim((string)($_SESSION['user_account_kind'] ?? ''))) === 'publisher';
$meMenuNameParts = account_display_name_parts($meName, $meMenuIsPublisher, $dbh);
$meMenuDisplayName = (string)($meMenuNameParts['display_name'] ?? $meName);
$meMenuAccountBadge = (string)($meMenuNameParts['badge'] ?? '');

$meInitials = initials_from_name($meName, 'ME');
$meAvatarUrl = 'avatar.php?u=' . (int)$meId . '&email=' . rawurlencode($meEmail) . '&friend_code=' . rawurlencode($meCode) . '&name=' . rawurlencode($meName);

// ✅ stable color key (based on initials so JK/RV/EE stay consistent across pages)
$meKey   = normalize_avatar_key($meInitials);
$meColor = color_from_string($meKey);
$meGrad  = avatar_gradient_style($meColor);
$__currentPage = strtolower(basename((string)($_SERVER['PHP_SELF'] ?? '')));
$autoOpenLiveWatchId = (int)($_GET['open_live_watch'] ?? 0);
$isFeedPage = ($__currentPage === 'feed.php');
$showFeedRail = ($__currentPage !== 'live_studio.php');

$railIsMessages = in_array($__currentPage, ['messages.php', 'chat.php'], true);
$railIsAlerts = in_array($__currentPage, ['dashboard.php', 'timeline.php', 'notifications.php'], true);
$railIsPublic = in_array($__currentPage, ['public.php', 'public_live.php'], true);
$railIsReel = ($__currentPage === 'reel.php');
$railIsStudio = ($__currentPage === 'live_studio.php');
$railIsCompose = in_array($__currentPage, ['compose.php', 'post_view.php'], true);
$railIsRequests = in_array($__currentPage, ['contact_requests.php', 'contacts.php', 'add_contact.php'], true);

/**
 * Fetch unread chat threads ONLY
 * - show nickname from user_contacts if you saved it
 * - otherwise show friend_code (NOT real name)
 */
$chatThreads = [];

if ($meCode !== '' || $meEmail !== '') {
  try {
    $st = $dbh->prepare("
      SELECT
        u.friend_code AS peer_code,
        uc.display_name AS contact_name,
        COALESCE(NULLIF(uc.display_name,''), u.friend_code) AS peer_display,
        MAX(f.created_at) AS last_time,
        SUBSTRING_INDEX(GROUP_CONCAT(f.feedbackdata ORDER BY f.created_at DESC SEPARATOR '\n'), '\n', 1) AS last_message,
        COUNT(*) AS unread_count
      FROM feedback f
      JOIN users u
        ON (u.friend_code = f.sender OR u.email = f.sender)
      LEFT JOIN user_contacts uc
        ON uc.owner_user_id = :meId
      AND uc.friend_user_id = u.id
      WHERE f.channel = 'user_user'
        AND f.is_read = 0
        AND (f.receiver = :meCode OR f.receiver = :meEmail)
      GROUP BY u.friend_code, peer_display, uc.display_name
      ORDER BY last_time DESC
      LIMIT 8
    ");
    $st->execute([
      ':meId' => $meId,
      ':meCode' => $meCode,
      ':meEmail' => $meEmail
    ]);

    $chatThreads = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  } catch (Throwable $e) {
    $chatThreads = [];
  }
}

// Split threads:
$unknownUnread = 0;
$namedChatThreads = [];
$totalUnread = 0;

foreach ($chatThreads as $t) {
  $cnt = (int)($t['unread_count'] ?? 0);
  $totalUnread += $cnt;

  $contactName = trim((string)($t['contact_name'] ?? ''));
  if ($contactName === '') $unknownUnread += $cnt;
  else $namedChatThreads[] = $t;
}

$pendingFriendRequestCount = 0;
try {
  $stFriendReq = $dbh->prepare("
    SELECT COUNT(*)
    FROM contact_requests
    WHERE to_user_id = :me
      AND status = 'pending'
  ");
  $stFriendReq->execute([':me' => $meId]);
  $pendingFriendRequestCount = (int)($stFriendReq->fetchColumn() ?: 0);
} catch (Throwable $e) {
  $pendingFriendRequestCount = 0;
}

$notificationUsername = trim((string)($_SESSION['user_login'] ?? ''));
$notificationEmail = trim((string)($_SESSION['user_email'] ?? ''));
$notificationReceivers = array_values(array_unique(array_filter([$notificationUsername, $notificationEmail], static function ($value) {
  return trim((string)$value) !== '';
})));
try {
  if ($meId > 0 && isset($dbh) && $dbh instanceof PDO) {
    $stNotiUser = $dbh->prepare('SELECT username, email FROM users WHERE id = :id LIMIT 1');
    $stNotiUser->execute([':id' => $meId]);
    $notiUserRow = $stNotiUser->fetch(PDO::FETCH_ASSOC) ?: [];
    foreach (['username', 'email'] as $notiKey) {
      $notiVal = trim((string)($notiUserRow[$notiKey] ?? ''));
      if ($notiVal !== '') {
        $notificationReceivers[] = $notiVal;
      }
    }
    $notificationReceivers = array_values(array_unique(array_filter($notificationReceivers, static function ($value) {
      return trim((string)$value) !== '';
    })));
  }
} catch (Throwable $e) {
  // keep session receivers
}
$headerNotifications = [];
$headerNotificationUnread = 0;
if (!empty($notificationReceivers)) {
  try {
    $receiverPh = implode(',', array_fill(0, count($notificationReceivers), '?'));
    $stUnread = $dbh->prepare("
      SELECT COUNT(*)
      FROM notification
      WHERE notireceiver IN ($receiverPh)
        AND is_read = 0
        AND notitype NOT LIKE 'New chat message%'
        AND notitype NOT LIKE 'Internal Chat%'
        AND notitype NOT LIKE 'New internal message%'
    ");
    $stUnread->execute($notificationReceivers);
    $headerNotificationUnread = (int)$stUnread->fetchColumn();

    $stNoti = $dbh->prepare("
      SELECT id, notiuser, notitype, created_at, is_read
      FROM notification
      WHERE notireceiver IN ($receiverPh)
        AND notitype NOT LIKE 'New chat message%'
        AND notitype NOT LIKE 'Internal Chat%'
        AND notitype NOT LIKE 'New internal message%'
      ORDER BY created_at DESC, id DESC
      LIMIT 20
    ");
    $stNoti->execute($notificationReceivers);
    $headerNotifications = $stNoti->fetchAll(PDO::FETCH_ASSOC) ?: [];
  } catch (Throwable $e) {
    $headerNotifications = [];
    $headerNotificationUnread = 0;
  }
}

$headerNotificationItemsJs = [];
foreach ($headerNotifications as $notiRow) {
  $notiMetaJs = function_exists('parse_notification_meta')
    ? parse_notification_meta((string)($notiRow['notitype'] ?? 'sent a notification'))
    : ['text' => (string)($notiRow['notitype'] ?? 'sent a notification'), 'live_id' => 0, 'post_id' => 0, 'is_story' => 0, 'url' => ''];
  $headerNotificationItemsJs[] = [
    'id' => (int)($notiRow['id'] ?? 0),
    'sender' => trim((string)($notiRow['notiuser'] ?? 'Someone')),
    'text' => (string)($notiMetaJs['text'] ?? 'sent a notification'),
    'live_id' => (int)($notiMetaJs['live_id'] ?? 0),
    'post_id' => (int)($notiMetaJs['post_id'] ?? 0),
    'is_story' => (int)($notiMetaJs['is_story'] ?? 0) === 1 ? 1 : 0,
    'url' => (string)($notiMetaJs['url'] ?? ''),
    'created_at' => (string)($notiRow['created_at'] ?? ''),
    'is_read' => (int)($notiRow['is_read'] ?? 0),
  ];
}

if (!function_exists('render_header_chat_panel_inner')) {
  function render_header_chat_panel_inner(array $namedChatThreads, int $unknownUnread, int $totalUnread): string {
    $namedThreadCount = count($namedChatThreads);
    $summaryText = $totalUnread > 0
      ? ($totalUnread . ' unread message' . ($totalUnread === 1 ? '' : 's'))
      : 'Inbox is clear';
    $detailText = $namedThreadCount . ' named conversation' . ($namedThreadCount === 1 ? '' : 's');
    $unknownText = $unknownUnread > 0
      ? ('Unknown friend codes: ' . $unknownUnread)
      : 'All messages are named';

    ob_start();
    ?>
      <div class="tt-messages-summary">
        <div class="tt-messages-summary-main" data-chat-summary><?php echo h($summaryText); ?></div>
        <div class="tt-messages-summary-sub" data-chat-detail><?php echo h($detailText); ?></div>
        <div class="tt-messages-summary-sub<?php echo $unknownUnread > 0 ? ' is-warn' : ''; ?>" data-chat-unknown><?php echo h($unknownText); ?></div>
      </div>
      <div class="tt-messages-divider"></div>
      <div class="tt-messages-list" data-chat-dropdown-list>
        <?php if (empty($namedChatThreads) && $unknownUnread <= 0): ?>
          <div class="dropdown-bestchat-empty">
            No new messages. Unread conversations will appear here when someone writes to you.
          </div>
        <?php else: ?>
          <?php if ($unknownUnread > 0): ?>
            <a class="bestchat-menu-item bestchat-menu-item-unknown" href="messages.php">
              <span class="bestchat-menu-icon" aria-hidden="true"><i class="icon ion-alert-circled"></i></span>
              <span class="bestchat-menu-body">
                <span class="bestchat-menu-name">Unknown friend codes</span>
                <span class="bestchat-menu-text">You have <?php echo (int)$unknownUnread; ?> unread message(s). Open Messages to name them.</span>
              </span>
            </a>
          <?php endif; ?>
          <?php foreach ($namedChatThreads as $t):
            $peerCode = (string)($t['peer_code'] ?? '');
            $peerDisp = (string)($t['peer_display'] ?? $peerCode);
            $lastMsg = trim((string)($t['last_message'] ?? ''));
            $lastTime = (string)($t['last_time'] ?? '');
            $unread = (int)($t['unread_count'] ?? 0);
            $peerKey = normalize_avatar_key($peerDisp !== '' ? $peerDisp : $peerCode);
            $peerColor = color_from_string($peerKey);
            $peerGrad = avatar_gradient_style($peerColor);
            $peerAvatarUrl = 'avatar.php?friend_code=' . rawurlencode($peerCode) . '&name=' . rawurlencode($peerDisp !== '' ? $peerDisp : $peerCode);
            $lastStamp = $lastTime !== '' ? strtotime($lastTime) : false;
            $lastTimeLabel = $lastStamp ? date('M d, g:i A', $lastStamp) : $lastTime;
          ?>
            <a href="messages.php?peer=<?php echo urlencode($peerCode); ?>" class="bestchat-menu-item">
              <span class="bestchat-menu-avatar" data-avatar-key="<?php echo h($peerKey); ?>" style="<?php echo h($peerGrad); ?>" aria-hidden="true">
                <img src="<?php echo h($peerAvatarUrl); ?>" alt="">
              </span>
              <span class="bestchat-menu-body">
                <span class="bestchat-menu-head">
                  <span class="bestchat-menu-name"><?php echo h($peerDisp); ?></span>
                  <?php if ($unread > 0): ?><span class="bestchat-badge"><?php echo ($unread > 99 ? '99+' : (string)$unread); ?></span><?php endif; ?>
                </span>
                <span class="bestchat-menu-sub"><?php echo h($peerCode . ($lastTimeLabel !== '' ? ' • ' . $lastTimeLabel : '')); ?></span>
                <span class="bestchat-menu-text"><?php echo h($lastMsg !== '' ? $lastMsg : 'Open conversation'); ?></span>
              </span>
            </a>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
      <div class="tt-messages-divider"></div>
      <ul class="tt-messages-footer">
        <li class="tt-messages-footer-row">
          <a href="messages.php"><i class="icon ion-chatboxes"></i> Open Inbox</a>
          <a href="compose.php" class="tt-messages-plus" aria-label="New message" title="New message"><i class="fa fa-plus" aria-hidden="true"></i></a>
        </li>
      </ul>
    <?php
    return (string)ob_get_clean();
  }
}

if (!function_exists('render_header_chat_dropdown')) {
  function render_header_chat_dropdown(array $namedChatThreads, int $unknownUnread, int $totalUnread): string {
    return render_header_chat_panel_inner($namedChatThreads, $unknownUnread, $totalUnread);
  }
}

if (!function_exists('render_header_notification_panel_inner')) {
  function render_header_notification_panel_inner(array $headerNotifications, int $headerNotificationUnread, string $subId, string $listId, string $markAllId): string {
    $notificationCount = count($headerNotifications);
    $summaryText = $headerNotificationUnread > 0 ? ($headerNotificationUnread . ' unread') : 'All caught up';
    $detailText = $notificationCount > 0
      ? ($notificationCount . ' recent alert' . ($notificationCount === 1 ? '' : 's'))
      : 'Recent alerts will appear here';

    ob_start();
    ?>
      <div class="tt-notifications-summary">
        <div class="tt-notifications-summary-main"><span id="<?php echo h($subId); ?>"><?php echo h($summaryText); ?></span></div>
        <div class="tt-notifications-summary-sub" data-notification-detail><?php echo h($detailText); ?></div>
      </div>
      <div class="tt-noti-door-tabs" role="tablist" aria-label="Notification filters">
        <button type="button" class="tt-noti-door-tab is-active" data-noti-door-tab="all" role="tab" aria-selected="true">All</button>
        <button type="button" class="tt-noti-door-tab" data-noti-door-tab="mentions" role="tab" aria-selected="false">Mentions</button>
      </div>
      <div class="tt-notifications-divider"></div>
      <div id="<?php echo h($listId); ?>" class="tt-notifications-list" data-noti-door-list="1">
        <?php if (empty($headerNotifications)): ?>
          <div class="dropdown-bestnoti-empty">No notifications yet. New alerts will appear here.</div>
        <?php else: ?>
          <?php foreach ($headerNotifications as $noti): ?>
            <?php
              $sender = trim((string)($noti['notiuser'] ?? 'Someone'));
              $notiMeta = parse_notification_meta((string)($noti['notitype'] ?? 'sent a notification'));
              $type = (string)($notiMeta['text'] ?? 'sent a notification');
              $notiLiveId = (int)($notiMeta['live_id'] ?? 0);
              $notiUrl = (string)($notiMeta['url'] ?? '');
              $notiPostId = (int)($notiMeta['post_id'] ?? 0);
              $notiIsStory = (int)($notiMeta['is_story'] ?? 0) === 1;
              $notiTime = (string)($noti['created_at'] ?? '');
              $typeLower = strtolower($type);
              $isMention = (strpos($typeLower, 'mention') !== false
                || strpos($typeLower, 'tagged you') !== false
                || strpos($type, '@') !== false);
            ?>
            <a href="<?php echo h($notiUrl !== '' ? $notiUrl : '#'); ?>" class="dropdown-bestnoti-item<?php echo ((int)($noti['is_read'] ?? 0) === 0 ? ' is-unread' : ''); ?>" data-notification-id="<?php echo (int)($noti['id'] ?? 0); ?>" data-notification-url="<?php echo h($notiUrl); ?>" data-live-id="<?php echo $notiLiveId; ?>" data-post-id="<?php echo $notiPostId; ?>" data-is-story="<?php echo $notiIsStory ? '1' : '0'; ?>" data-noti-kind="<?php echo $isMention ? 'mentions' : 'all'; ?>">
              <div class="bestnoti-avatar"><?php echo h(initials_from_name($sender, 'NT')); ?></div>
              <div class="bestnoti-mid">
                <div class="bestnoti-text"><strong><?php echo h($sender); ?></strong> <?php echo h($type); ?></div>
                <div class="bestnoti-time"><?php echo h($notiTime ? date('M d, Y h:i A', strtotime($notiTime)) : ''); ?></div>
              </div>
            </a>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
      <div class="tt-notifications-divider"></div>
      <ul class="tt-notifications-footer">
        <li><a href="notifications.php"><i class="icon ion-ios-bell-outline"></i> View All Notifications</a></li>
        <li><a href="notifications.php?tab=mentions"><i class="icon ion-at"></i> Mentions</a></li>
        <li><a href="#" id="<?php echo h($markAllId); ?>">Mark All as Read</a></li>
      </ul>
    <?php
    return (string)ob_get_clean();
  }
}

if (!function_exists('render_header_notification_dropdown')) {
  function render_header_notification_dropdown(array $headerNotifications, int $headerNotificationUnread, string $subId, string $listId, string $markAllId): string {
    return render_header_notification_panel_inner($headerNotifications, $headerNotificationUnread, $subId, $listId, $markAllId);
  }
}

if (!function_exists('render_header_profile_dropdown')) {
  function render_header_profile_dropdown(string $meKey, string $meGrad, string $meAvatarUrl, string $meName, string $meEmail, string $meCode, array $items, string $meAccountBadge = ''): string {
    ob_start();
    ?>
    <div class="dropdown-menu dropdown-menu-right dropdown-bestprofile-menu">
      <div class="bestprofile-top">
        <span class="bestprofile-avatar big" data-avatar-key="<?php echo h($meKey); ?>" style="<?php echo h($meGrad); ?>" aria-hidden="true"><img src="<?php echo h($meAvatarUrl); ?>" data-live-avatar="1" data-avatar-base="<?php echo h($meAvatarUrl); ?>" alt="Avatar"></span>
        <div class="bestprofile-meta">
          <div class="bestprofile-name"><?php echo h($meName); ?></div>
          <?php if ($meAccountBadge !== ''): ?>
            <div class="bestprofile-badge"><?php echo h($meAccountBadge); ?></div>
          <?php endif; ?>
          <?php if ($meEmail !== ''): ?>
            <div class="bestprofile-email"><?php echo h($meEmail); ?></div>
          <?php endif; ?>
          <?php if ($meCode !== ''): ?>
            <div class="bestprofile-code">Code: <b><?php echo h($meCode); ?></b></div>
          <?php endif; ?>
        </div>
      </div>
      <div class="bestprofile-divider"></div>
      <ul class="dropdown-profile-nav bestprofile-nav">
        <?php foreach ($items as $item):
          $href = trim((string)($item['href'] ?? '#'));
          $icon = trim((string)($item['icon'] ?? 'ion-ios-arrow-right'));
          $label = trim((string)($item['label'] ?? 'Open'));
        ?>
          <li><a href="<?php echo h($href !== '' ? $href : '#'); ?>"><i class="icon <?php echo h($icon); ?>"></i> <?php echo h($label); ?></a></li>
        <?php endforeach; ?>
      </ul>
    </div>
    <?php
    return (string)ob_get_clean();
  }
}

$railProfileHref = 'profile.php';
if ($meId > 0) {
  if ($meCode !== '') {
    $railProfileHref .= '?friend_code=' . rawurlencode($meCode);
  } else {
    $railProfileHref .= '?id=' . (int)$meId;
  }
}

$railProfileMenuItems = [
  ['href' => $railProfileHref, 'icon' => 'ion-ios-person', 'label' => 'Profile'],
  ['href' => 'my_orders.php', 'icon' => 'ion-bag', 'label' => 'My Orders'],
  ['href' => 'cart.php', 'icon' => 'ion-ios-cart', 'label' => 'Cart'],
  ['href' => 'timeline.php', 'icon' => 'ion-ios-locked', 'label' => 'Timeline'],
  ['href' => 'change-password.php', 'icon' => 'ion-ios-gear', 'label' => 'Settings'],
  ['href' => 'logout.php', 'icon' => 'ion-power', 'label' => 'Sign Out'],
];

$topProfileMenuItems = [
  ['href' => 'profile.php?tab=about', 'icon' => 'ion-ios-person', 'label' => 'Edit Profile'],
  ['href' => 'change-password.php', 'icon' => 'ion-ios-gear', 'label' => 'Settings'],
  ['href' => 'logout.php', 'icon' => 'ion-power', 'label' => 'Sign Out'],
];
?>

<script>
window.__MSB_CSRF_TOKEN = <?php echo json_encode(csrfToken(), JSON_UNESCAPED_SLASHES); ?>;
</script>
<script>
(function () {
  var token = window.__MSB_CSRF_TOKEN || '';
  if (!token) return;

  function isUnsafe(method) {
    method = String(method || 'GET').toUpperCase();
    return method === 'POST' || method === 'PUT' || method === 'PATCH' || method === 'DELETE';
  }

  function isSameOrigin(url) {
    try {
      var resolved = new URL(url, window.location.href);
      return resolved.origin === window.location.origin;
    } catch (e) {
      return true;
    }
  }

  function appendTokenToForm(form) {
    if (!form || String(form.method || 'GET').toUpperCase() === 'GET') return;
    var existing = form.querySelector('input[name="csrf_token"]');
    if (!existing) {
      existing = document.createElement('input');
      existing.type = 'hidden';
      existing.name = 'csrf_token';
      form.appendChild(existing);
    }
    existing.value = token;
  }

  document.addEventListener('submit', function (event) {
    appendTokenToForm(event.target);
  }, true);

  document.querySelectorAll('form').forEach(appendTokenToForm);

  if (window.jQuery && window.jQuery.ajaxSetup) {
    window.jQuery.ajaxSetup({
      beforeSend: function (xhr, settings) {
        var method = settings && settings.type ? settings.type : 'GET';
        var url = settings && settings.url ? settings.url : window.location.href;
        if (isUnsafe(method) && isSameOrigin(url)) {
          xhr.setRequestHeader('X-CSRF-Token', token);
        }
      }
    });
  }

  if (window.fetch) {
    var nativeFetch = window.fetch.bind(window);
    window.fetch = function (input, init) {
      init = init || {};
      var method = String(init.method || (input && input.method) || 'GET').toUpperCase();
      var url = typeof input === 'string' ? input : ((input && input.url) || window.location.href);

      if (isUnsafe(method) && isSameOrigin(url)) {
        var headers = new Headers(init.headers || (input && input.headers) || {});
        headers.set('X-CSRF-Token', token);
        init.headers = headers;

        if (init.body instanceof FormData && !init.body.has('csrf_token')) {
          init.body.append('csrf_token', token);
        }
      }

      return nativeFetch(input, init);
    };
  }
})();
</script>

<?php if (empty($skipHeaderThemeBootstrap)): ?>
<!-- ✅ AUTO DARK MODE (Public User) — per-account theme prefs -->
<?php theme_prefs_print_head_bootstrap($dbh, $meId); ?>
<?php if (!defined('MSB_THEME_DARK_CSS')): ?>
<link rel="stylesheet" href="./css/dark-auto.css?v=40">
<?php define('MSB_THEME_DARK_CSS', true); endif; ?>
<?php if (!defined('MSB_APPEARANCE_PALETTE_CSS')): ?>
<link rel="stylesheet" href="./css/appearance-palette.css?v=109">
<?php define('MSB_APPEARANCE_PALETTE_CSS', true); endif; ?>
<?php if (!defined('MSB_THEME_DARK_JS')): ?>
<script src="./js/dark-auto.js?v=6" defer></script>
<?php define('MSB_THEME_DARK_JS', true); endif; ?>
<?php if (!defined('MSB_POST_ENGAGEMENT_JS')): ?>
<script src="./js/post-engagement-sync.js?v=7"></script>
<script src="./js/post-video-loop.js?v=1"></script>
<style>
video.msb-clean-loop-video::-webkit-media-controls,
video.msb-clean-loop-video::-webkit-media-controls-enclosure,
video.msb-clean-loop-video::-webkit-media-controls-panel,
video.msb-clean-loop-video::-webkit-media-controls-play-button,
video.msb-clean-loop-video::-webkit-media-controls-start-playback-button{
  display:none !important;
  -webkit-appearance:none !important;
  opacity:0 !important;
  pointer-events:none !important;
}
video.msb-clean-loop-video{
  cursor:default !important;
}
</style>
<?php define('MSB_POST_ENGAGEMENT_JS', true); endif; ?>
<?php endif; ?>

<!-- ✅ Brand Fonts (Logo + UI) -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@500;600;700&family=Playfair+Display:wght@600;700;800&family=Poppins:wght@400;500;600;700;800&display=optional" rel="stylesheet">

<style>
/* ===== Brand typography (Talentra) ===== */
:root{
  --msb-font-ui: 'Poppins', system-ui, -apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, 'Noto Sans', 'Liberation Sans', sans-serif;
  --msb-font-logo: 'Cormorant Garamond', 'Playfair Display', ui-serif, Georgia, 'Times New Roman', Times, serif;
  --msb-logo-gold: #e8c98a;
  --msb-logo-mist: #9fd6c8;
  --msb-logo-ink: #05090f;
  --msb-logo-my: #ffffff;
  --msb-logo-story: #c7d2fe; /* soft indigo */
  --msb-logo-book: #7dd3fc;  /* soft sky */
  --msb-dd-surface: #ffffff;
  --msb-dd-surface-strong: #f8fafc;
  --msb-dd-border: #c0c2c4;
  --msb-dd-divider: #c0c2c4;
  --msb-dd-text: #0f172a;
  --msb-dd-muted: #64748b;
  --msb-dd-soft: #334155;
  --msb-dd-hover: rgba(37,99,235,.06);
  --msb-dd-unread: #eff6ff;
  --msb-dd-warn: #92400e;
  --msb-dd-warn-hover: rgba(245,158,11,.14);
  --msb-dd-shadow: 0 22px 70px rgba(15,23,42,.18);
  --msb-dd-head-bg: linear-gradient(135deg, rgba(2,6,23,1), rgba(30,41,59,1));
  --msb-dd-head-text: #ffffff;
  --msb-rx-surface: rgba(255,255,255,.96);
  --msb-rx-border: #c0c2c4;
  --msb-rx-shadow: 0 18px 48px rgba(15,23,42,.22);
  --msb-rx-text: #0f172a;
}

html.dark-auto,
html[data-theme="dark"]{
  --msb-dd-surface: #1f2937;
  --msb-dd-surface-strong: #243041;
  --msb-dd-border: #34383c;
  --msb-dd-divider: #34383c;
  --msb-dd-text: #e5edf5;
  --msb-dd-muted: #9fb0c2;
  --msb-dd-soft: #c6d3e1;
  --msb-dd-hover: rgba(96,165,250,.14);
  --msb-dd-unread: rgba(37,99,235,.22);
  --msb-dd-warn: #fcd34d;
  --msb-dd-warn-hover: rgba(146,64,14,.34);
  --msb-dd-shadow: 0 24px 70px rgba(2,6,23,.46);
  --msb-rx-surface: rgba(15,23,42,.96);
  --msb-rx-border: #34383c;
  --msb-rx-shadow: 0 22px 54px rgba(2,6,23,.5);
  --msb-rx-text: #e5edf5;
}

/* Keep typography scoped to header so other page fonts remain untouched */
.sh-logopanel, .sh-headpanel{ font-family: var(--msb-font-ui); }
/* =========================
   Talentra Logo (TOP-LEFT) Responsive
   ========================= */

/* Make sure the logo panel is always left aligned */
.sh-logopanel{
  display:flex;
  align-items:center;
  justify-content:flex-start;
  padding: 10px 14px;
  gap: 10px;
  width: 100%;
}

/* The logo link itself (desktop + tablet + mobile) */
.sh-logo-text{
  display:inline-flex;
  align-items:center;
  justify-content:flex-start;
  text-decoration:none !important;

  font-family: var(--msb-font-logo);
  font-weight: 800;
  font-size: clamp(18px, 2.2vw, 28px);
  line-height: 1;
  letter-spacing: .2px;

  white-space: nowrap;
  max-width: 100%;
  overflow: hidden;
  text-overflow: ellipsis;
}
.sh-logo-text:hover{ text-decoration:none; }

/* If you ever re-enable the icon */
.sh-logo-text .logo-icon{ font-size: 1em; opacity: .95; }

/* If you ever re-enable the 3-part logo */
.sh-logo-text .logo-my{ color: var(--msb-logo-my); }
.sh-logo-text .logo-story{ color: var(--msb-logo-story); }
.sh-logo-text .logo-book{ color: white; font-size: 35px;
    font-family: cursive;}

/* Mobile dropdown logo text (uses same styling, but no weird centering) */
.dropdown-app-list .dropdown-link .logo-book{
  /* font-family: var(--msb-font-logo); */
  font-family:fantasy;
  font-weight: 800;
  color: #fff;
  font-size: clamp(18px, 3.2vw, 22px);
  line-height: 1;
  letter-spacing: .2px;
  margin: 0;
  padding: 0;
  white-space: nowrap;
}

/* --- Device-focused breakpoints (covers iPads, Surface Pro, Zenbook Fold, Nest Hub) --- */

/* Nest Hub Max / large tablets landscape / small laptops */
@media (max-width: 1280px){
  .sh-logopanel{ padding: 10px 12px; }
}

/* iPad landscape / Nest Hub / Surface Pro-ish widths */
@media (max-width: 1024px){
  .sh-logopanel{ padding: 10px 12px; }
  .sh-logo-text{ font-size: clamp(18px, 2.6vw, 26px); }
}

/* Surface Pro 7 portrait (often 912px CSS width), Zenbook Fold cases */
@media (max-width: 912px){
  .sh-logo-text{ font-size: clamp(18px, 3.0vw, 24px); }
}

/* iPad Air/Mini portrait (820/768 widths) */
@media (max-width: 820px){
  .sh-logo-text{ font-size: clamp(18px, 3.2vw, 23px); }
}
@media (max-width: 768px){
  .sh-logo-text{ font-size: clamp(18px, 3.6vw, 22px); }
}

/* Phones / narrow layouts */
@media (max-width: 600px){
  .sh-logopanel{ padding: 10px 10px; }
  .sh-logo-text{ font-size: 20px; }
}
@media (max-width: 480px){
  .sh-logo-text{ font-size: 19px; }
}
@media (max-width: 360px){
  .sh-logo-text{ font-size: 18px; }
}

/* Nest Hub / small-height landscape devices (avoid vertical squish) */
@media (max-height: 600px){
  .sh-logopanel{ padding-top: 8px; padding-bottom: 8px; }
}

/* ===== Force logo visibility inside TOP header on tablets (iPad Mini/Air, Surface Pro, Nest Hub) ===== */
@media (max-width: 991.98px){
  /* Shamcey sometimes hides the left area; force it back */
  .sh-headpanel-left{ display:flex !important; align-items:center !important; }

  /* Ensure the mobile logo dropdown is visible (this is your top-left logo on iPad/tablet) */
  .dropdown-app-list{ display:block !important; }
  .dropdown-app-list .dropdown-link{
    display:inline-flex !important;
    align-items:center !important;
    justify-content:flex-start !important;
    gap:10px;
    padding: 0;
    text-decoration:none !important;
  }

  /* Keep the sidebar logo panel from being the only logo source on tablets */
  .sh-logopanel{ display:none !important; }
}


/* ===== Feed header top icons (messages, notifications, profile) ===== */
/*
  IMPORTANT:
  Some pages (like feed.php) may have page-level CSS that accidentally hides .sh-headpanel-right on desktop.
  So we force it to stay visible on ALL breakpoints.
*/
.sh-headpanel-right{
  display:flex !important;
  align-items:center !important;
  gap:10px;
  margin-left:auto;
  visibility:visible !important;
  opacity:1 !important;
  pointer-events:auto !important;
  z-index:1200;
}
.sh-headpanel-right .topicon-btn{
  width:44px;height:44px;border-radius:999px;
  display:flex;align-items:center;justify-content:center;
  background:rgba(0,0,0,.14);
  position:relative;
}
.sh-headpanel-right .topicon-btn .icon{line-height:1;}
.sh-headpanel-right .topicon-btn:hover{background:rgba(0,0,0,.20);text-decoration:none;}
.sh-headpanel-right .chatBadge{
  position:absolute;top:-6px;right:-6px;
  min-width:20px;height:20px;padding:0 6px;
  border-radius:999px;
  display:inline-flex;align-items:center;justify-content:center;
  background:#ef4444;color:#fff;
  font-size:12px;font-weight:700;
  border:2px solid rgba(255,255,255,.9);
}
.sh-headpanel-right .dropdown-notification .square-8{
  position:absolute;top:10px;right:12px;
  width:10px;height:10px;border-radius:999px;
  background:#ef4444;border:2px solid rgba(255,255,255,.9);
}
.sh-headpanel-right .bestprofile-avatar{
  overflow:hidden;
  width:52px;height:52px;border-radius:999px;
  display:inline-flex;align-items:center;justify-content:center;
  color:#fff;font-weight:800;letter-spacing:.5px;
  border:3px solid rgba(255,255,255,.95);
  box-shadow:0 6px 14px rgba(0,0,0,.18);
}
@media (max-width: 991.98px){
  /* keep header clean on mobile/tablet */
  .sh-headpanel-left .sh-icon-link{display:none !important;}
  /* ✅ ensure top-right icons are visible on mobile */
  .sh-headpanel{left:0 !important; right:0 !important; z-index:1105 !important;}
  .sh-headpanel-right{display:flex !important; align-items:center !important;}
}

/* ===== Shared responsive guardrails for app pages ===== */
html, body{
  max-width:100%;
  overflow-x:hidden;
}
body,
body *{
  box-sizing:border-box;
}
img, video, canvas, svg{
  max-width:100%;
  height:auto;
}
iframe{
  max-width:100%;
}
.container,
.container-fluid,
.card,
.panel,
.modal-dialog,
.table-responsive,
.row,
[class*="col-"]{
  max-width:100%;
}
.table-responsive{
  -webkit-overflow-scrolling:touch;
}

@media (max-width: 1199.98px){
  body .sh-mainpanel{
    margin-left:0 !important;
    width:100% !important;
    max-width:100% !important;
  }
  body .sh-pagebody{
    margin-right:0 !important;
    padding-right:12px !important;
    padding-left:12px !important;
  }
  body .sh-mainpanel,
  body .sh-pagebody,
  body .contacts-shell,
  body .requests-shell,
  body .notifications-shell,
  body .profile-shell{
    overflow-x:hidden !important;
  }
}

@media (max-width: 991.98px){
  html, body{
    height:auto !important;
    min-height:100%;
    overflow-x:hidden !important;
    overflow-y:auto !important;
  }
  body .sh-mainpanel{
    margin-top:0 !important;
    margin-left:0 !important;
    width:100% !important;
    min-height:100vh !important;
    height:auto !important;
    overflow:visible !important;
  }
  body .sh-pagebody{
    display:block !important;
    min-height:auto !important;
    height:auto !important;
    overflow:visible !important;
    padding:12px !important;
    margin:0 !important;
  }
  body .container,
  body .container-fluid{
    padding-left:12px !important;
    padding-right:12px !important;
  }
  body .row{
    margin-left:-6px !important;
    margin-right:-6px !important;
  }
  body .row > [class*="col-"]{
    padding-left:6px !important;
    padding-right:6px !important;
  }
  body .contacts-shell,
  body .requests-shell{
    height:auto !important;
    min-height:0 !important;
    overflow:visible !important;
    margin-left:0 !important;
    margin-right:0 !important;
    padding:10px !important;
  }
  body .contacts-scroll,
  body .requests-scroll{
    overflow:auto !important;
    min-height:0 !important;
  }
  body .contacts-fixed,
  body .requests-fixed{
    position:sticky !important;
    top:0 !important;
    z-index:8 !important;
  }
  body .table-responsive{
    border-radius:14px;
  }
  body .table{
    min-width:680px;
  }
  body .modal-dialog{
    width:calc(100vw - 24px) !important;
    max-width:calc(100vw - 24px) !important;
    margin:12px auto !important;
  }
}

@media (max-width: 767.98px){
  body .sh-pagebody{
    padding:10px !important;
  }
  body .card,
  body .panel{
    border-radius:16px !important;
  }
  body .card-header,
  body .panel-heading,
  body .card-body,
  body .panel-body{
    padding-left:12px !important;
    padding-right:12px !important;
  }
  body .btn,
  body .btn-soft,
  body .iconbtn{
    min-height:40px;
  }
  body .dropdown-menu{
    max-width:min(92vw, 360px);
  }
}

@media (max-width: 575.98px){
  body .sh-pagebody{
    padding:8px !important;
  }
  body .container,
  body .container-fluid{
    padding-left:8px !important;
    padding-right:8px !important;
  }
  body .table{
    min-width:620px;
  }
  body .feed-ig-rail .dropdown-bestchat-menu,
  body .feed-ig-rail .dropdown-bestnoti-menu,
  body .feed-ig-rail .dropdown-bestprofile-menu{
    min-width:0 !important;
    width:min(92vw, 320px) !important;
    max-width:min(92vw, 320px) !important;
  }
}

@media (max-width: 430px){
  body .sh-pagebody{
    padding:6px !important;
  }
  body .container,
  body .container-fluid{
    padding-left:6px !important;
    padding-right:6px !important;
  }
  body .table{
    min-width:560px;
  }
  body .modal-dialog{
    width:calc(100vw - 12px) !important;
    max-width:calc(100vw - 12px) !important;
    margin:6px auto !important;
  }
}

</style>




<!-- header -->

<?php if ($showFeedRail): ?>
<?php if (!empty($msbPublisherAccountUi)): ?>
<script>document.documentElement.classList.add('msb-publisher-account');</script>
<style>
  /* Publisher: keep overlay doors above feed chrome; left text nav stays visible */
  html.msb-publisher-account #ttLeftbarOverlays{
    z-index:1295;
  }
  html.msb-publisher-account body.public-leftbar-open{
    overflow-x:hidden;
  }
</style>
<?php endif; ?>
<style>
  :root{ --feedRailW:84px; }
  body{ background:var(--msb-palette-bg, #f5f7fb); }
  .feed-ig-rail{
    position:fixed; left:0; top:0; bottom:0; width:var(--feedRailW); z-index:1200;
    background-color:var(--msb-palette-bg, #f5f7fb);
    color:var(--msb-palette-text-on-nav, var(--msb-palette-text, #111827));
    border-right:1px solid var(--msb-palette-border-strong, #d1d5db) !important; border-radius:0;
    display:flex; flex-direction:column;
    align-items:center; padding:18px 10px 16px; gap:14px;
    --msb-feed-chrome-size:40px;
    --msb-feed-chrome-font:14px;
    --msb-feed-chrome-icon:16px;
    --msb-feed-chrome-circle:50%;
  }
  .feed-ig-logo{
    position:relative;
    --msb-logo-size:58px;
    width:var(--msb-logo-size); height:var(--msb-logo-size);
    min-width:var(--msb-logo-size); min-height:var(--msb-logo-size);
    border-radius:var(--msb-feed-chrome-circle);
    display:grid; place-items:center;
    isolation:isolate;
    overflow:hidden;
    text-decoration:none !important;
    /* Match entry.php orb — dark ink + gold monogram */
    background:
      radial-gradient(circle at 35% 30%, rgba(255,255,255,.14), transparent 55%),
      radial-gradient(circle at 50% 58%, rgba(159,214,200,.14), transparent 70%),
      linear-gradient(165deg, #05090f 0%, #0b1622 52%, #08131c 100%);
    box-shadow:
      0 0 0 1px rgba(232,201,138,.22),
      0 8px 18px rgba(5,9,15,.22);
    color:transparent;
    font-size:0;
    line-height:0;
  }
  .feed-ig-logo-ring{
    position:absolute;inset:0;
    border-radius:inherit;
    pointer-events:none;
    background:
      conic-gradient(from 210deg, rgba(232,201,138,0), rgba(232,201,138,.9), rgba(159,214,200,.75), rgba(232,201,138,0));
    -webkit-mask:radial-gradient(farthest-side, transparent calc(100% - 1.6px), #000 calc(100% - 0.6px));
            mask:radial-gradient(farthest-side, transparent calc(100% - 1.6px), #000 calc(100% - 0.6px));
    opacity:.92;
  }
  .feed-ig-logo-glow{
    position:absolute;inset:18%;
    border-radius:50%;
    pointer-events:none;
    background:radial-gradient(circle at 40% 35%, rgba(255,255,255,.16), transparent 60%);
    opacity:.85;
  }
  .feed-ig-logo-mark{
    position:relative;z-index:1;
    font-family:var(--msb-font-logo);
    font-size:calc(var(--msb-logo-size) * 0.64);
    font-weight:700;
    line-height:1;
    letter-spacing:0;
    background:linear-gradient(180deg, #f8e7c2 0%, var(--msb-logo-gold, #e8c98a) 42%, #b8924a 100%);
    -webkit-background-clip:text;
    background-clip:text;
    color:transparent;
    filter:drop-shadow(0 0 8px rgba(232,201,138,.28));
  }
  .feed-ig-logo:hover,
  .feed-ig-logo:focus{
    transform:translateY(-1px);
    box-shadow:
      0 0 0 1px rgba(232,201,138,.34),
      0 10px 22px rgba(5,9,15,.28);
    outline:none;
  }
  .feed-ig-logo-label{
    display:block;
    font-family:var(--msb-font-logo);
    font-size:12px;font-weight:600;
    letter-spacing:.02em;
    color:var(--msb-palette-text-on-nav, var(--msb-palette-text, #0f172a));
    line-height:1.15;text-align:center;margin-top:2px;
    max-width:72px;width:100%;
    text-decoration:none !important;
  }
  .feed-ig-logo-label:hover,
  .feed-ig-logo-label:focus{
    color:var(--msb-logo-gold, #e8c98a);
    text-decoration:none !important;
  }
  .feed-ig-avatar{margin-top:2px}
  .feed-ig-avatar .bestprofile-avatar{
    width:var(--msb-feed-chrome-size);height:var(--msb-feed-chrome-size);font-size:11px;
    box-shadow:none;border-radius:var(--msb-feed-chrome-circle);
  }
  .feed-ig-nav{display:flex;flex-direction:column;gap:8px;width:100%;align-items:center}
  .feed-ig-btn, .feed-ig-link, .feed-ig-rail .ig-link{
    width:var(--msb-feed-chrome-size);height:var(--msb-feed-chrome-size);
    min-width:var(--msb-feed-chrome-size);min-height:var(--msb-feed-chrome-size);
    border-radius:var(--msb-feed-chrome-circle);
    display:flex;align-items:center;justify-content:center;
    color:var(--msb-palette-text-on-nav, var(--msb-palette-text, #111827));background:transparent;border:0;position:relative; text-decoration:none !important;
    transition:background .18s ease, transform .18s ease, color .18s ease;
    cursor:pointer;
    padding:0;
    font-size:var(--msb-feed-chrome-icon);
    line-height:1;
  }
  .feed-ig-btn:hover, .feed-ig-link:hover, .feed-ig-btn:focus, .feed-ig-link:focus,
  .feed-ig-rail .ig-link:hover, .feed-ig-rail .ig-link:focus,
  .feed-ig-btn.active, .feed-ig-link.active, .feed-ig-rail .ig-link.active{
    background:var(--msb-palette-nav-hover, #f3f4f6); color:var(--msb-palette-text-on-nav-hover, var(--msb-palette-text, #000)); outline:none;
  }
  .feed-ig-btn .icon, .feed-ig-link .icon, .feed-ig-rail .ig-link .icon,
  .feed-ig-btn .fa, .feed-ig-link .fa, .feed-ig-rail .ig-link .fa{
    font-size:var(--msb-feed-chrome-icon); line-height:1;
    color:inherit;
  }
  .feed-ig-reels{
    width:34px !important;
    height:34px !important;
    min-width:34px !important;
    min-height:34px !important;
    border-radius:50% !important;
    background:#e53935 !important;
    color:#fff !important;
    box-shadow:0 6px 16px rgba(229,57,53,.32);
  }
  .feed-ig-reels:hover,
  .feed-ig-reels:focus,
  .feed-ig-reels.active{
    background:#d32f2f !important;
    color:#fff !important;
    box-shadow:0 8px 18px rgba(211,47,47,.38);
  }
  .feed-ig-reels .fa,
  .feed-ig-reels .icon{
    font-size:14px !important;
    line-height:1 !important;
    margin-left:2px;
    color:#fff !important;
  }
  /* Appearance gear color → play triangle (circle stays light for contrast) */
  html[data-msb-appearance] .feed-ig-reels,
  html[data-msb-appearance] .feed-ig-reels:hover,
  html[data-msb-appearance] .feed-ig-reels:focus,
  html[data-msb-appearance] .feed-ig-reels.active{
    background:var(--msb-palette-surface-2, #fff) !important;
    color:var(--msb-palette-action) !important;
    border-color:var(--msb-palette-border-strong, var(--msb-palette-border, transparent)) !important;
    box-shadow:0 6px 16px color-mix(in srgb, var(--msb-palette-action) 28%, transparent) !important;
  }
  html[data-msb-appearance] .feed-ig-reels .fa,
  html[data-msb-appearance] .feed-ig-reels .fa.fa-play,
  html[data-msb-appearance] .feed-ig-reels .icon{
    color:var(--msb-palette-action) !important;
  }
  .feed-ig-dot{position:absolute; right:11px; top:11px; width:8px; height:8px; border-radius:50%; background:#ff3040}
  .feed-ig-badge{
    position:absolute;
    top:-2px;
    right:1px;
    min-width:18px;
    height:18px;
    padding:0 5px;
    border-radius:999px;
    background:#ef4444;
    color:#fff;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    font-size:11px;
    font-weight:800;
    line-height:1;
    border:2px solid #fff;
    box-shadow:0 6px 14px rgba(239,68,68,.28);
  }
  .feed-ig-spacer{flex:1 1 auto}
  .feed-ig-rail .dropdown{display:flex;justify-content:center;width:100%}
  .feed-ig-rail .dropdown-menu{
    left:75px !important; right:auto !important; top:0 !important; transform:none !important;
    margin-top:0 !important;
    border-color: var(--msb-dd-border);
  }
  .feed-ig-rail .dropdown-bestchat-menu,
  .feed-ig-rail .dropdown-bestnoti-menu{width:min(280px, calc(100vw - 28px)); min-width:280px; max-width:calc(100vw - 28px)}
  .feed-ig-rail .dropdown-bestprofile-menu{width:min(280px, calc(100vw - 28px)); min-width:280px; max-width:calc(100vw - 28px)}
  body .sh-logopanel, body .sh-headpanel{display:none !important}
  html, body{overflow-x:hidden !important;}
  body .sh-mainpanel{
    margin-top:0 !important;
    margin-left:calc(var(--feedRailW) + 16px) !important;
    width:calc(100% - var(--feedRailW) - 16px) !important;
    max-width:calc(100% - var(--feedRailW) - 16px) !important;
    min-height:100vh;
    overflow-x:hidden !important;
  }
  /* body .sh-pagebody{padding-top:18px !important} */
  body #ttNavLeftbar.sh-sideleft-menu{
    left:calc(var(--feedRailW) + 10px) !important; top:18px !important; width:255px !important;
    height:calc(100vh - 36px) !important; border:1px solid #e5e7eb !important; border-radius:22px !important;
    background:#fff !important; box-shadow:0 14px 38px rgba(15,23,42,.06) !important; padding:18px 16px !important;
  }
  body #ttNavLeftbar .sh-sidebar-label{display:block; margin:0 0 12px !important; padding:0 10px !important; font-size:11px; letter-spacing:.14em; color:#94a3b8}
  body #ttNavLeftbar .nav{margin:0 !important; display:flex !important; flex-direction:column; gap:8px}
  body #ttNavLeftbar .nav-item{width:100%}
  body #ttNavLeftbar .nav-link{
    display:flex !important; align-items:center; gap:12px; min-height:48px; border-radius:16px;
    padding:12px 14px !important; color:#0f172a !important; font-weight:600; letter-spacing:0;
  }
  body #ttNavLeftbar .nav-link:hover, body #ttNavLeftbar .nav-link.active{background:#f3f4f6 !important; color:#000 !important}
  html.dark-auto:not([data-msb-appearance]) body #ttNavLeftbar.sh-sideleft-menu,
  html[data-theme="dark"]:not([data-msb-appearance]) body #ttNavLeftbar.sh-sideleft-menu{
    background:#171d24 !important;
    border-color:rgba(177,188,206,.22) !important;
    box-shadow:0 14px 38px rgba(0,0,0,.28) !important;
  }
  html.dark-auto:not([data-msb-appearance]) body #ttNavLeftbar .sh-sidebar-label,
  html[data-theme="dark"]:not([data-msb-appearance]) body #ttNavLeftbar .sh-sidebar-label{
    color:#b1bcce !important;
  }
  html.dark-auto:not([data-msb-appearance]) body #ttNavLeftbar .nav-link,
  html[data-theme="dark"]:not([data-msb-appearance]) body #ttNavLeftbar .nav-link{
    color:#b1bcce !important;
  }
  html.dark-auto:not([data-msb-appearance]) body #ttNavLeftbar .nav-link:hover,
  html.dark-auto:not([data-msb-appearance]) body #ttNavLeftbar .nav-link.active,
  html[data-theme="dark"]:not([data-msb-appearance]) body #ttNavLeftbar .nav-link:hover,
  html[data-theme="dark"]:not([data-msb-appearance]) body #ttNavLeftbar .nav-link.active{
    background:#2f3a4a !important;
    color:#e8edf5 !important;
  }
  html.dark-auto:not([data-msb-appearance]) body #ttNavLeftbar .nav-link .icon,
  html[data-theme="dark"]:not([data-msb-appearance]) body #ttNavLeftbar .nav-link .icon{
    color:inherit !important;
  }
  body #ttNavLeftbar .nav-link .icon{font-size:22px; width:22px; text-align:center}
  @media (max-width: 991.98px){
    :root{ --feedRailW:0px; }
    .feed-ig-rail{left:0; right:0; top:auto; bottom:0; width:auto; height:66px; border-right:none; border-top:1px solid #e5e7eb; padding:6px 10px; flex-direction:row; justify-content:space-between; gap:6px}
    .feed-ig-logo, .feed-ig-logo-label, .feed-ig-avatar, .feed-ig-spacer{display:none !important}
    .feed-ig-nav{flex-direction:row; justify-content:space-between; gap:4px; margin:0}
    .feed-ig-btn, .feed-ig-link, .feed-ig-rail .ig-link{
      flex:1;
      width:auto;
      height:var(--msb-feed-chrome-size);
      min-height:var(--msb-feed-chrome-size);
      border-radius:var(--msb-feed-chrome-circle);
    }
    .feed-ig-rail .dropdown-menu{left:auto !important; right:8px !important; top:auto !important; bottom:72px !important}
    body .sh-mainpanel{
      margin-left:0 !important;
      width:100% !important;
      max-width:100% !important;
    }
  }

  .ig-link {
    width: var(--msb-feed-chrome-size, 40px);
    height: var(--msb-feed-chrome-size, 40px);
    border-radius: var(--msb-feed-chrome-circle, 50%);
    display: flex;
    align-items: center;
    justify-content: center;
    color: #111827;
    font-size: var(--msb-feed-chrome-icon, 16px);
    position: relative;
    text-decoration:none !important;
    transition:background .18s ease, transform .18s ease, color .18s ease;
  }
  .ig-link:hover, .ig-link:focus, .ig-link.active{
    background:var(--msb-palette-nav-hover, #f3f4f6);
    color:var(--msb-palette-text-on-nav-hover, #000);
    outline:none;
  }
  html.dark-auto:not([data-msb-appearance]) .ig-link:hover,
  html.dark-auto:not([data-msb-appearance]) .ig-link:focus,
  html.dark-auto:not([data-msb-appearance]) .ig-link.active,
  html[data-theme="dark"]:not([data-msb-appearance]) .ig-link:hover,
  html[data-theme="dark"]:not([data-msb-appearance]) .ig-link:focus,
  html[data-theme="dark"]:not([data-msb-appearance]) .ig-link.active{
    background:#2f3a4a !important;
    color:#e8edf5 !important;
  }
  html.dark-auto:not([data-msb-appearance]) body #ttNavLeftbar.sh-sideleft-menu,
  html[data-theme="dark"]:not([data-msb-appearance]) body #ttNavLeftbar.sh-sideleft-menu{
    background:#171d24 !important;
    border-color:rgba(177,188,206,.22) !important;
  }
  html.dark-auto:not([data-msb-appearance]) body #ttNavLeftbar .nav-link,
  html[data-theme="dark"]:not([data-msb-appearance]) body #ttNavLeftbar .nav-link{
    color:#b1bcce !important;
  }
  html.dark-auto:not([data-msb-appearance]) body #ttNavLeftbar .nav-link:hover,
  html.dark-auto:not([data-msb-appearance]) body #ttNavLeftbar .nav-link.active,
  html[data-theme="dark"]:not([data-msb-appearance]) body #ttNavLeftbar .nav-link:hover,
  html[data-theme="dark"]:not([data-msb-appearance]) body #ttNavLeftbar .nav-link.active{
    background:#2f3a4a !important;
    color:#e8edf5 !important;
  }
  html.dark-auto:not([data-msb-appearance]) .ig-link,
  html[data-theme="dark"]:not([data-msb-appearance]) .ig-link{
    color:#b1bcce !important;
  }
  html.dark-auto:not([data-msb-appearance]) .ig-link:hover,
  html.dark-auto:not([data-msb-appearance]) .ig-link:focus,
  html.dark-auto:not([data-msb-appearance]) .ig-link.active,
  html[data-theme="dark"]:not([data-msb-appearance]) .ig-link:hover,
  html[data-theme="dark"]:not([data-msb-appearance]) .ig-link:focus,
  html[data-theme="dark"]:not([data-msb-appearance]) .ig-link.active{
    background:#2f3a4a !important;
    color:#e8edf5 !important;
  }
</style>
<style><?php include __DIR__ . '/feed_page_chrome.css.php'; ?></style>
<?php endif; ?>

<?php if ($showFeedRail): ?>
<aside class="feed-ig-rail" aria-label="Feed navigation">
  <a href="home.php?tab=for-you" class="feed-ig-logo" aria-label="Talentra">
    <span class="feed-ig-logo-ring" aria-hidden="true"></span>
    <span class="feed-ig-logo-glow" aria-hidden="true"></span>
    <span class="feed-ig-logo-mark">t</span>
  </a>
  <a href="home.php?tab=for-you" class="feed-ig-logo-label">Talentra</a>

  <div class="feed-ig-avatar">
    <button type="button" class="feed-ig-btn js-open-profile-door" aria-label="Profile" title="Profile">
      <span class="bestprofile-avatar" data-avatar-key="<?php echo h($meKey); ?>" style="<?php echo h($meGrad); ?>" aria-hidden="true"><img src="<?php echo h($meAvatarUrl); ?>" data-live-avatar="1" data-avatar-base="<?php echo h($meAvatarUrl); ?>" alt=""></span>
    </button>
  </div>

  <nav class="feed-ig-nav">
    <button type="button" class="feed-ig-btn ig-stories-menu-btn feed-ig-menu-mobile js-open-menu-door" aria-label="Menu" title="Menu">
      <i class="icon ion-navicon"></i>
    </button>
    <button type="button" class="feed-ig-btn js-open-messages-door<?php echo $railIsMessages ? ' active' : ''; ?>" aria-label="Messages" title="Messages">
      <i class="icon ion-chatboxes"></i>
      <span data-chat-badge class="chatBadge"<?php echo $totalUnread > 0 ? '' : ' style="display:none;"'; ?>><?php echo $totalUnread > 99 ? '99+' : (string)$totalUnread; ?></span>
    </button>

    <button type="button" class="feed-ig-btn js-open-notifications-door<?php echo $railIsAlerts ? ' active' : ''; ?>" aria-label="Notifications" title="Notifications">
      <i class="icon ion-ios-bell-outline"></i>
      <span id="headerNotificationDot" class="feed-ig-dot"<?php echo $headerNotificationUnread > 0 ? '' : ' style="display:none;"'; ?>></span>
      <span id="headerNotificationBadge" class="feed-ig-badge"<?php echo $headerNotificationUnread > 0 ? '' : ' style="display:none;"'; ?>><?php echo $headerNotificationUnread > 99 ? '99+' : (string)$headerNotificationUnread; ?></span>
    </button>

    <a class="feed-ig-link" href="dashboard.php?modal=1" id="headerCreatePostTrigger" data-create-post-modal="1" title="Create Post" aria-label="Create Post"><i class="icon ion-plus-round"></i></a>
    <?php /* Public/world icon hidden — Discover tab already covers public.php */ ?>
    <?php if ($meId > 0): ?>
    <button type="button" class="feed-ig-link js-open-live-studio-browse<?php echo $railIsStudio ? ' active' : ''; ?>" title="Live" aria-label="Live"><i class="icon ion-ios-videocam"></i></button>
    <?php if ($headerCanLiveStudio): ?>
    <button type="button" class="feed-ig-link js-open-live-software-browse" title="Streaming software" aria-label="Streaming software"><i class="icon ion-wand"></i></button>
    <?php endif; ?>
    <?php endif; ?>
    <?php if (!$headerStaffReadonly): ?>
    <a class="feed-ig-link<?php echo $railIsCompose ? ' active' : ''; ?>" href="compose.php" title="New Compose"><i class="icon ion-compose"></i></a>
    <?php endif; ?>
    <button type="button" class="feed-ig-link js-open-friend-requests-door" title="Friend Requests" aria-label="Friend Requests">
      <i class="icon ion-person-add"></i>
      <?php if ($pendingFriendRequestCount > 0): ?>
        <span class="feed-ig-badge"><?php echo $pendingFriendRequestCount > 99 ? '99+' : (string)$pendingFriendRequestCount; ?></span>
      <?php endif; ?>
    </button>
    <a class="feed-ig-link feed-ig-reels<?php echo !empty($railIsReel) ? ' active' : ''; ?>" href="reel.php" title="Reels" aria-label="Open Reels">
      <i class="fa fa-play" aria-hidden="true"></i>
    </a>
  </nav>

  <div class="feed-ig-spacer"></div>
</aside>
<?php
  if (empty($GLOBALS['msb_leftbar_included']) && empty($GLOBALS['msb_skip_header_leftbar'])) {
    include __DIR__ . '/leftbar.php';
  }
  $__notiBootJson = json_encode(
    is_array($headerNotificationItemsJs ?? null) ? $headerNotificationItemsJs : [],
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
  );
  echo '<script>window.__MSB_HEADER_NOTIFICATIONS=' . ($__notiBootJson !== false ? $__notiBootJson : '[]') . ';</script>';
?>
<?php else: ?>
<div class="sh-logopanel">
  <a href="" class="sh-logo-text" aria-label="Talentra">
    <span class="logo-book">Talentra.</span>
  </a>
</div><!-- sh-logopanel -->

<div class="sh-headpanel">
  <div class="sh-headpanel-left">
    <!-- <a href="" class="sh-icon-link"><div><i class="icon ion-ios-folder-outline"></i><span>Directory</span></div></a> -->
    <!-- <a href="" class="sh-icon-link"><div><i class="icon ion-ios-calendar-outline"></i><span>Events</span></div></a> -->
    <!-- <a href="change-password.php" class="sh-icon-link"><div><i class="icon ion-ios-gear-outline"></i><span>Settings</span></div></a> -->
    <?php if (!$headerStaffReadonly): ?>
    <a href="compose.php" class="sh-icon-link"><div><i class="icon ion-compose"></i><span>New Compose</span></div></a>
    <?php endif; ?>
    <div class="dropdown dropdown-app-list">
      <a href="" data-toggle="dropdown" class="dropdown-link"><span class="logo-book">Talentra.</span></a>
      <div class="dropdown-menu"><div class="row no-gutters"><div class="col-4"><a href="" class="dropdown-menu-link"><div><i class="icon ion-ios-folder-outline"></i><span>Directory</span></div></a></div><div class="col-4"><a href="" class="dropdown-menu-link"><div><i class="icon ion-ios-calendar-outline"></i><span>Events</span></div></a></div><div class="col-4"><a href="change-password.php" class="dropdown-menu-link"><div><i class="icon ion-ios-gear-outline"></i><span>Settings</span></div></a></div></div></div>
    </div>
  </div><!-- sh-headpanel-left -->

  <div class="sh-headpanel-right">
    <button type="button" class="dropdown-link dropdown-link-notification dropdown-bestchat-link topicon-btn js-open-messages-door" aria-label="Messages" title="Messages"><i class="icon ion-chatboxes tx-24"></i><span data-chat-badge class="chatBadge"<?php echo $totalUnread > 0 ? '' : ' style="display:none;"'; ?>><?php echo $totalUnread > 99 ? '99+' : (string)$totalUnread; ?></span></button>
    <button type="button" class="dropdown-link dropdown-link-notification topicon-btn js-open-notifications-door" aria-label="Notifications" title="Notifications"><i class="icon ion-ios-bell-outline tx-24"></i><span id="headerNotificationSquare" class="square-8"<?php echo $headerNotificationUnread > 0 ? '' : ' style="display:none;"'; ?>></span><span id="headerNotificationTopBadge" class="chatBadge"<?php echo $headerNotificationUnread > 0 ? '' : ' style="display:none;"'; ?>><?php echo $headerNotificationUnread > 99 ? '99+' : (string)$headerNotificationUnread; ?></span></button>
    <div class="dropdown dropdown-profile dropdown-bestprofile"><a href="" data-toggle="dropdown" class="dropdown-link dropdown-bestprofile-link topicon-btn" aria-haspopup="true" aria-expanded="false"><span class="bestprofile-avatar" data-avatar-key="<?php echo h($meKey); ?>" style="<?php echo h($meGrad); ?>" aria-hidden="true"><img src="<?php echo h($meAvatarUrl); ?>" data-live-avatar="1" data-avatar-base="<?php echo h($meAvatarUrl); ?>" alt="Avatar"></span></a><?php echo render_header_profile_dropdown($meKey, $meGrad, $meAvatarUrl, $meMenuDisplayName, $meEmail, $meCode, $topProfileMenuItems, $meMenuAccountBadge); ?></div>
  </div><!-- sh-headpanel-right -->
</div><!-- sh-headpanel -->
<?php
  $msbMessagesDoorStandalone = true;
  echo '<div class="msb-messages-door-host" id="msbMessagesDoorHost">';
  include __DIR__ . '/messages_door.php';
  echo '</div>';
  $msbNotificationsDoorStandalone = true;
  echo '<div class="msb-notifications-door-host" id="msbNotificationsDoorHost">';
  include __DIR__ . '/notifications_door.php';
  echo '</div>';
  $__notiBootJson = json_encode(
    is_array($headerNotificationItemsJs ?? null) ? $headerNotificationItemsJs : [],
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
  );
  echo '<script>window.__MSB_HEADER_NOTIFICATIONS=' . ($__notiBootJson !== false ? $__notiBootJson : '[]') . ';</script>';
  $msbLiveDoorStandalone = true;
  $msbLiveDoorCanStudio = $headerCanLiveStudio;
  echo '<div class="msb-live-door-host" id="msbLiveDoorHost">';
  include __DIR__ . '/live_door.php';
  echo '</div>';
?>
<?php endif; ?>

<style>
  .msb-global-call-banner{
    position:fixed;
    top:14px;
    left:50%;
    transform:translate(-50%,-18px);
    width:min(520px, calc(100vw - 28px));
    min-height:78px;
    z-index:2147482000;
    display:flex;
    align-items:center;
    gap:12px;
    padding:12px 14px;
    border-radius:20px;
    background:rgba(14,18,24,.92);
    color:#fff;
    box-shadow:0 18px 45px rgba(0,0,0,.28);
    border:1px solid rgba(255,255,255,.13);
    opacity:0;
    pointer-events:none;
    transition:opacity .2s ease, transform .2s ease;
    backdrop-filter:blur(18px);
  }
  .msb-global-call-banner.is-visible{
    opacity:1;
    pointer-events:auto;
    transform:translate(-50%,0);
  }
  .msb-global-call-avatar{
    width:48px;
    height:48px;
    border-radius:50%;
    display:grid;
    place-items:center;
    flex:0 0 auto;
    overflow:hidden;
    color:#fff;
    font-weight:900;
    background:linear-gradient(135deg,#4f46e5,#0ea5e9);
  }
  .msb-global-call-avatar img{width:100%;height:100%;object-fit:cover;display:none;}
  .msb-global-call-main{
    min-width:0;
    flex:1 1 auto;
    border:0;
    padding:0;
    background:transparent;
    color:inherit;
    text-align:left;
    cursor:pointer;
  }
  .msb-global-call-name{
    font-size:17px;
    line-height:1.18;
    font-weight:900;
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis;
  }
  .msb-global-call-sub{
    margin-top:3px;
    font-size:13px;
    line-height:1.18;
    font-weight:700;
    color:rgba(255,255,255,.72);
  }
  .msb-global-call-actions{display:flex;align-items:center;gap:9px;flex:0 0 auto;}
  .msb-global-call-action{
    width:44px;
    height:44px;
    border:0;
    border-radius:50%;
    display:grid;
    place-items:center;
    color:#fff;
    cursor:pointer;
    font-size:20px;
    box-shadow:none;
  }
  .msb-global-call-action.decline{background:#ef2f2f;}
  .msb-global-call-action.accept{background:#26c55e;}
  .msb-global-call-action:disabled{opacity:.55;cursor:default;}
  @media (max-width:640px){
    .msb-global-call-banner{
      top:max(10px, env(safe-area-inset-top));
      width:calc(100vw - 20px);
      border-radius:18px;
      padding:11px 12px;
    }
    .msb-global-call-avatar{width:44px;height:44px;}
    .msb-global-call-name{font-size:16px;}
    .msb-global-call-action{width:42px;height:42px;}
  }
</style>
<style id="msb-modal-fouc-guard">
/* Must load before modal markup is parsed — prevents live/create modal flash on refresh/nav. */
.global-live-modal:not(.is-open),
#createPostModal:not(.is-open){
  visibility:hidden !important;
  pointer-events:none !important;
}
.global-live-modal:not(.is-open){
  display:none !important;
  opacity:0 !important;
}
.global-live-modal:not(.is-open) .global-live-modal-dialog,
.global-live-modal:not(.is-open) iframe,
.global-live-modal:not(.is-open) video,
.global-live-modal:not(.is-open) img,
.global-live-modal:not(.is-open) aside,
#createPostModal:not(.is-open) iframe{
  display:none !important;
}
.msb-global-call-banner[aria-hidden="true"]{
  display:none !important;
  visibility:hidden !important;
  pointer-events:none !important;
}
</style>
<div
  class="msb-global-call-banner"
  id="msbGlobalCallBanner"
  data-me-name="<?php echo h($meName !== '' ? $meName : $meCode); ?>"
  aria-live="polite"
  aria-hidden="true"
>
  <div class="msb-global-call-avatar" id="msbGlobalCallAvatar" aria-hidden="true">
    <span id="msbGlobalCallInitials">U</span>
    <img id="msbGlobalCallAvatarImage" src="" alt="">
  </div>
  <button type="button" class="msb-global-call-main" id="msbGlobalCallOpen">
    <div class="msb-global-call-name" id="msbGlobalCallName">Incoming call</div>
    <div class="msb-global-call-sub" id="msbGlobalCallSub">Incoming video call</div>
  </button>
  <div class="msb-global-call-actions">
    <button type="button" class="msb-global-call-action decline" id="msbGlobalCallDecline" aria-label="Decline call">
      <i class="fa fa-phone" style="transform:rotate(135deg);" aria-hidden="true"></i>
    </button>
    <button type="button" class="msb-global-call-action accept" id="msbGlobalCallAccept" aria-label="Accept call">
      <i class="fa fa-phone" aria-hidden="true"></i>
    </button>
  </div>
</div>

<div class="global-live-modal" id="globalLiveModal" aria-hidden="true">
    <div class="global-live-modal-dialog global-live-modern" role="dialog" aria-modal="true" aria-label="Live session">
      <div class="global-live-modal-top">
        <div class="global-live-modern-title">
          <div class="global-live-modern-owner-avatar" id="globalLiveOwnerAvatar" aria-hidden="true">LV</div>
          <div class="global-live-modern-title-row">
            <div class="global-live-modern-title-text" id="globalLiveTopTitle">Live session</div>
            <div class="global-live-modern-live-badge">Live</div>
          </div>
          <div class="global-live-modern-subtitle">Started now • <span id="globalLiveParticipantCount">0</span> viewers</div>
        </div>
      <div class="global-live-top-actions">
        <div class="global-live-modal-top-center">Watching live now</div>
        <button type="button" class="global-live-speaker-btn" id="globalLiveSpeakerTop" aria-label="Speaker view">
          <i class="icon ion-grid"></i>
        </button>

        
        <button type="button" class="global-live-speaker-btn global-live-join-top-btn" id="globalLiveJoinRequestButton" aria-label="Request to join" style="display:none;">
          <i class="icon ion-person-add"></i>
          <span>Request to Join</span>
        </button>
        <!-- <button type="button" class="global-live-top-close" id="globalLiveTopClose" aria-label="Leave live">Leave</button> -->
      </div>
    </div>
    <div class="global-live-modal-body">
      <div class="global-live-modal-stage">
        <div class="global-live-modal-surface" id="globalLiveModalSurface">
          <iframe class="global-live-modal-frame" id="globalLiveModalFrame" title="Live session viewer" src="about:blank" allow="autoplay; fullscreen; picture-in-picture; camera; microphone"></iframe>
          <video class="global-live-modal-direct-video" id="globalLiveModalDirectVideo" autoplay playsinline muted></video>
          <img class="global-live-modal-snapshot" id="globalLiveModalSnapshot" alt="Live preview" aria-hidden="true">
          <div class="global-live-camera-off-stage" id="globalLiveCameraOffStage" aria-hidden="true">
            <div class="global-live-camera-off-icon" aria-hidden="true">
              <i class="fa fa-video-camera"></i>
            </div>
          </div>
        </div>
        <div class="global-live-stage-comments" id="globalLiveStageComments" aria-hidden="true"></div>
        <div class="global-live-stage-reactions" id="globalLiveStageReactions" aria-hidden="true"></div>
      </div>
      <aside class="global-live-sidebar" id="globalLiveSidebar" aria-label="Live chat sidebar">
        <div class="global-live-side-head">
          <div class="global-live-side-title"><strong id="globalLiveSidebarTitleText">Comments</strong><span id="globalLiveSidebarTitleCount">0</span></div>
          <!-- <button type="button" class="global-live-side-close" id="globalLiveSidebarClose" aria-label="Close chat sidebar">&times;</button> -->
        </div>
        <div class="global-live-side-stats">
          <div class="global-live-side-stat"><span id="globalLiveReactionCount">0</span> Reactions</div>
          <div class="global-live-side-stat"><span id="globalLiveCommentCount">0</span> Comments</div>
          <div class="global-live-side-stat"><span id="globalLiveViewCount">0</span> Views</div>
        </div>
        <div class="global-live-side-scroll">
          <div class="global-live-chat-panel" id="globalLiveChatPanel">
            <div class="global-live-comments-box" id="globalLiveCommentsBox">
              <div class="global-live-comments-list" id="globalLiveCommentList">Comments will appear here when viewers join the live session.</div>
            </div>
          </div>
          <div class="global-live-reaction-panel" id="globalLiveReactionPanel">
            <div class="global-live-reaction-tabs" id="globalLiveReactionTabs"></div>
            <div class="global-live-reaction-list" id="globalLiveReactionList"></div>
          </div>
          <div class="global-live-description-panel" id="globalLiveDescriptionPanel">
            <div class="global-live-description-card">
              <div class="global-live-description-head">
                <!-- <div class="global-live-description-avatar" id="globalLiveDescriptionAvatar">LV</div> -->
                <div class="global-live-description-meta">
                  <h3 class="global-live-description-title" id="globalLiveDescriptionTitle">Live session</h3>
                  <div class="global-live-description-sub" id="globalLiveDescriptionSub">Host • Started now</div>
                </div>
              </div>
              <div class="global-live-description-divider"></div>
              <div class="global-live-description-scroll">
                <p class="global-live-description-body" id="globalLiveDescriptionBody">Description will appear here.</p>
              </div>
            </div>
          </div>
          <div class="global-live-settings-panel" id="globalLiveSettingsPanel" aria-hidden="true">
            <div class="global-live-settings-body">
              <div class="global-live-settings-item">
                <label for="globalLiveSettingsCameraDevice">Camera device</label>
                <select id="globalLiveSettingsCameraDevice">
                  <option value="">System default camera</option>
                </select>
                <div class="global-live-settings-note">Used for your local host preview or when you join this live on camera.</div>
              </div>
              <div class="global-live-settings-item">
                <label for="globalLiveSettingsMicDevice">Microphone device</label>
                <select id="globalLiveSettingsMicDevice">
                  <option value="">System default microphone</option>
                </select>
                <div class="global-live-settings-note">Used for your local host preview or when you join this live on camera.</div>
              </div>
              <div class="global-live-settings-item">
                <label for="globalLiveSettingsSpeakerDevice">Speaker / output</label>
                <select id="globalLiveSettingsSpeakerDevice">
                  <option value="">System default output</option>
                </select>
                <div class="global-live-settings-note">Changes the output for live playback when the browser supports it.</div>
              </div>
              <div class="global-live-settings-item">
                <label class="global-live-settings-toggle" for="globalLiveSettingsAudio">
                  <span>Microphone on</span>
                  <input type="checkbox" id="globalLiveSettingsAudio">
                </label>
                <div class="global-live-settings-note">Mute or unmute your local microphone or the live audio playback.</div>
              </div>
              <div class="global-live-settings-item">
                <label class="global-live-settings-toggle" for="globalLiveSettingsCameraEnabled">
                  <span>Camera on</span>
                  <input type="checkbox" id="globalLiveSettingsCameraEnabled">
                </label>
                <div class="global-live-settings-note">Hide or show your local camera, or pause the live video locally.</div>
              </div>
              <div class="global-live-settings-item">
                <label class="global-live-settings-toggle" for="globalLiveSettingsMirror">
                  <span>Mirror camera</span>
                  <input type="checkbox" id="globalLiveSettingsMirror">
                </label>
                <div class="global-live-settings-note">Only affects your own preview if you join this live on camera.</div>
              </div>
              <div class="global-live-settings-item">
                <label for="globalLiveSettingsQuality">Video quality</label>
                <select id="globalLiveSettingsQuality">
                  <option value="auto">Auto</option>
                  <option value="720p">720p</option>
                  <option value="1080p">1080p</option>
                </select>
                <div class="global-live-settings-note">Used for your local host preview or when you join this live on camera.</div>
              </div>
              <div class="global-live-settings-item">
                <label for="globalLiveSettingsFrameRate">Frame rate</label>
                <select id="globalLiveSettingsFrameRate">
                  <option value="24">24 fps</option>
                  <option value="30">30 fps</option>
                </select>
                <div class="global-live-settings-note">Use 24 fps for stability or 30 fps for smoother motion.</div>
              </div>
            </div>
          </div>
        </div>
        <div class="global-live-compose" id="globalLiveCompose">
          <div class="global-live-compose-row">
            <div class="global-live-compose-inputwrap">
              <textarea id="globalLiveCommentInput" placeholder="Add comment..."></textarea>
              <a type="button" class="global-live-compose-tool" aria-label="Mention">@</a>
              <a type="button" class="global-live-compose-tool" aria-label="Emoji"><i class="fa fa-smile-o" aria-hidden="true"></i></a>
            </div>
            <a type="button" class="global-live-send-btn" id="globalLiveSendButton" aria-label="Send"><i class="fa fa-arrow-up" aria-hidden="true"></i></a>
          </div>
          <div class="global-live-compose-feedback" id="globalLiveComposeFeedback"></div>
        </div>
      </aside>
    </div>
    <div class="global-live-modal-bottom">
      <div class="global-live-controls">
        <a type="button" class="global-live-control" id="globalLiveMicToggle" aria-label="Turn microphone on">
            <i class="fa fa-microphone has-off-slash" aria-hidden="true"></i>
          <span class="studio-live-control-label">Microphone</span>
        </a>
        <a type="button" class="global-live-control" id="globalLiveCameraToggle" aria-label="Turn camera off">
            <i class="fa fa-video-camera" aria-hidden="true"></i>
          <span class="studio-live-control-label">Camera</span>
        </a>
        <a type="button" class="global-live-control" aria-label="Share">
          <i class="icon ion-monitor"></i>
          <span class="global-live-control-label">Share</span>
        </a>
        <a type="button" class="global-live-control" id="globalLiveSettingsToggle" aria-label="Settings">
          <i class="icon ion-gear-a"></i>
          <span class="global-live-control-label">Settings</span>
        </a>
        
        <a type="button" class="global-live-control" id="globalLiveReactionToggle" aria-label="React">
          <i class="fa fa-smile-o" aria-hidden="true"></i>
          <span class="studio-live-control-label">React</span>
        </a>

        <a type="button" class="global-live-control" id="globalLiveChatToggle" aria-label="Chat">
          <i class="icon ion-chatbubble"></i>
          <span class="global-live-control-label">Chat <span class="global-live-control-count" id="globalLiveToolbarCommentCount">0</span></span>
        </a>

        <a type="button" class="global-live-control" id="globalLiveDescriptionToggle" aria-label="Description">
          <i class="fa fa-book"></i>
          <span class="global-live-control-label">Description</span>
        </a>

      </div>
      <div class="global-live-controls-right">
        <div class="global-live-quick-reactions" id="globalLiveQuickReactions" aria-label="Quick reactions">
          <a type="button" class="global-live-quick-reaction" data-live-room-reaction="like" aria-label="Like">👍</a>
          <a type="button" class="global-live-quick-reaction is-love" data-live-room-reaction="love" aria-label="Love"><span class="live-love-heart" aria-hidden="true">&#10084;</span></a>
          <a type="button" class="global-live-quick-reaction" data-live-room-reaction="clap" aria-label="Clap">🥰</a>
          <a type="button" class="global-live-quick-reaction" data-live-room-reaction="wow" aria-label="Wow">😮</a>
          <a type="button" class="global-live-quick-reaction" data-live-room-reaction="fire" aria-label="Fire">😡</a>
        </div>
        <a type="button" class="global-live-end" id="globalLiveModalClose" aria-label="Close live modal">
          <i class="icon ion-close-circled"></i>
          <span class="global-live-control-label">Leave</span>
        </a>
      </div>
    </div>
    <div class="global-live-confirm" id="globalLiveConfirm" aria-hidden="true">
      <div class="global-live-confirm-card" role="alertdialog" aria-modal="true" aria-labelledby="globalLiveConfirmTitle" aria-describedby="globalLiveConfirmText">
        <h3 id="globalLiveConfirmTitle">Leave Live</h3>
        <p id="globalLiveConfirmText">Are you sure you want to leave this live video?</p>
        <div class="global-live-confirm-actions">
          <button type="button" class="global-live-confirm-btn" id="globalLiveConfirmCancel">Cancel</button>
          <button type="button" class="global-live-confirm-btn confirm" id="globalLiveConfirmOk">OK</button>
        </div>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/msb_toast.php'; ?>
<?php include __DIR__ . '/mention_autocomplete.js.php'; ?>

<div class="create-post-modal" id="createPostModal" aria-hidden="true">
  <div id="createPostAccordion" class="create-post-dialog create-post-accordion" role="dialog" aria-modal="true" aria-label="Create post">
    <h3 class="create-post-topbar">
      <span class="create-post-title">Create new post</span>
      <button type="button" class="create-post-close" id="createPostModalClose" aria-label="Close">&times;</button>
    </h3>
    <div class="create-post-body">
      <div class="create-post-readonly" id="createPostReadonlyNotice" hidden>
        <div class="create-post-readonly-icon" aria-hidden="true"><i class="icon ion-ios-locked-outline"></i></div>
        <h3>View-only access</h3>
        <p id="createPostReadonlyText">Organization staff can browse publisher content but cannot create posts from this account.</p>
        <button type="button" class="btn btn-primary btn-sm" id="createPostReadonlyClose">OK</button>
      </div>
      <iframe class="create-post-frame" id="createPostModalFrame" title="Create post form" src="about:blank"></iframe>
    </div>
  </div>
</div>

<style>

@media (min-width: 1200px) {
  .sh-headpanel {
      left: 340px;
  }
}

.hide-left .sh-headpanel {
  left: 155px;
}

.sh-headpanel {
    position: fixed;
    top: 0;
    /* left: 55px; */
    right: 0;
    height: 100px;
    background-color: #697077;
    display: flex;
    justify-content: space-between;
    transition: none;
    z-index: 1105;
}

.global-live-modal{
  position:fixed;
  inset:0;
  display:none;
  align-items:center;
  justify-content:center;
  padding:18px;
  background:rgba(7,10,18,.72);
  z-index:2100;
}

.create-post-modal{
  position:fixed;
  left:0;
  right:0;
  top:0;
  bottom:0;
  /* Above gallery/post viewers (.pv-overlay ~9999) and post-card menu portals (~100000). */
  z-index:110000;
  display:block;
  visibility:hidden;
  pointer-events:none;
  padding:0;
  margin:0;
  background:transparent !important;
  background-color:transparent !important;
  background-image:none !important;
  box-sizing:border-box;
  overflow:hidden;
}

.create-post-modal.is-open{
  visibility:visible;
  pointer-events:none;
  background:transparent !important;
  background-color:transparent !important;
}

.create-post-modal.is-open .create-post-dialog,
.create-post-modal.is-open .create-post-dialog *{
  pointer-events:auto;
}

/* Beat appearance-palette / theme-bootstrap dim overlay on Create Post. */
html[data-msb-appearance] #createPostModal.create-post-modal,
html[data-msb-appearance] #createPostModal.create-post-modal.is-open,
html #createPostModal.create-post-modal,
html #createPostModal.create-post-modal.is-open{
  background:transparent !important;
  background-color:transparent !important;
  background-image:none !important;
}

/* jQuery UI Accordion shell — expands body height like https://jqueryui.com/accordion/ */
.create-post-dialog.create-post-accordion{
  position:absolute;
  top:0;
  left:50%;
  transform:translateX(-50%);
  width:min(1180px, calc(100vw - 24px));
  max-width:1180px;
  /* Short until form is measured — never flash tall blank shell. */
  height:36px;
  min-height:36px;
  max-height:36px;
  margin:0;
  padding:0;
  background:var(--msb-palette-bg, #f5f7fb);
  box-shadow:
    0 18px 48px rgba(15,23,42,.16),
    0 8px 24px rgba(15,23,42,.12);
  overflow:hidden;
  border-radius:0 0 14px 14px;
  margin-bottom:10px;
  border:1px solid var(--msb-palette-border, rgba(15,23,42,.12));
  border-top:0;
  box-sizing:border-box;
}

/* While opening: allow height animation (do not freeze at 36px). */
.create-post-modal.is-opening .create-post-dialog.create-post-accordion,
.create-post-modal.is-form-ready .create-post-dialog.create-post-accordion{
  max-height:none;
}
.create-post-modal.is-closing .create-post-dialog.create-post-accordion{
  overflow:hidden;
}

.create-post-accordion.ui-accordion,
.create-post-accordion.ui-widget,
.create-post-accordion.ui-helper-reset{
  font-family:inherit;
  font-size:inherit;
}

.create-post-accordion > .create-post-topbar,
.create-post-accordion > .ui-accordion-header{
  display:flex !important;
  align-items:center;
  justify-content:space-between;
  gap:10px;
  margin:0 !important;
  padding:0 12px !important;
  height:36px;
  min-height:36px;
  max-height:36px;
  box-sizing:border-box;
  border:0 !important;
  border-bottom:1px solid var(--msb-palette-border, rgba(15,23,42,.12)) !important;
  border-radius:0 !important;
  background:var(--msb-palette-bg, #f5f7fb) !important;
  color:#0f172a !important;
  font-size:13px !important;
  font-weight:700 !important;
  line-height:1.2 !important;
  cursor:default;
  outline:none !important;
  box-shadow:none !important;
  /* Open/close only via + / X — not header toggle. */
  pointer-events:none;
}

.create-post-accordion > .create-post-topbar .create-post-close,
.create-post-accordion > .ui-accordion-header .create-post-close{
  pointer-events:auto;
}

.create-post-accordion > .ui-accordion-header .ui-accordion-header-icon,
.create-post-accordion > .ui-accordion-header .ui-icon{
  display:none !important;
}

.create-post-accordion > .create-post-body,
.create-post-accordion > .ui-accordion-content{
  margin:0 !important;
  padding:0 !important;
  border:0 !important;
  border-radius:0 !important;
  background:var(--msb-palette-bg, #f5f7fb) !important;
  box-sizing:border-box;
  overflow:hidden !important;
  min-height:0 !important;
  flex:1 1 auto !important;
}
/* Keep panel visible during our height animation (accordion must not display:none). */
.create-post-modal.is-open .create-post-accordion > .create-post-body,
.create-post-modal.is-opening .create-post-accordion > .create-post-body,
.create-post-modal.is-closing .create-post-accordion > .create-post-body{
  display:block !important;
}

.create-post-frame{
  width:100%;
  height:100%;
  min-height:0;
  flex:1 1 auto;
  border:0;
  display:block;
  background:var(--msb-palette-bg, #f5f7fb);
}

.create-post-topbar{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:10px;
  padding:0 12px;
  height:36px;
  min-height:36px;
  background:var(--msb-palette-bg, #f5f7fb);
  color:#0f172a !important;
}

.create-post-title{
  display:inline-flex;
  align-items:center;
  gap:6px;
  font-size:13px;
  font-weight:700;
  color:#0f172a !important;
  opacity:1 !important;
}
/* Never show compose/edit icon beside Create new post title. */
.create-post-title .icon,
.create-post-title i,
.create-post-title .fa,
.create-post-topbar > .ui-accordion-header-icon,
.create-post-topbar > .ui-icon,
.create-post-accordion > .ui-accordion-header > .ui-accordion-header-icon,
.create-post-accordion > .ui-accordion-header > .ui-icon{
  display:none !important;
  width:0 !important;
  height:0 !important;
  margin:0 !important;
  padding:0 !important;
  visibility:hidden !important;
  font-size:0 !important;
}

.create-post-close{
  width:28px;
  height:28px;
  border:0;
  border-radius:999px;
  background:rgba(15,23,42,.1) !important;
  color:#0f172a !important;
  font-size:20px;
  line-height:1;
  cursor:pointer;
  flex:0 0 auto;
  opacity:1 !important;
}

.create-post-body{
  min-height:0;
  height:0;
  overflow:hidden;
  background:var(--msb-palette-bg, #f5f7fb);
  display:flex;
  flex-direction:column;
}
.create-post-accordion > .create-post-body.ui-accordion-content-active{
  display:flex !important;
  flex-direction:column;
}

.create-post-modal.is-readonly-notice .create-post-frame{
  display:none;
}
.create-post-readonly{
  display:flex;
  flex-direction:column;
  align-items:center;
  justify-content:center;
  gap:12px;
  min-height:320px;
  padding:28px 24px;
  text-align:center;
  color:#0f172a;
}
.create-post-readonly[hidden]{
  display:none !important;
}
.create-post-modal.is-readonly-notice .create-post-readonly{
  display:flex;
}
.create-post-readonly-icon{
  width:56px;
  height:56px;
  border-radius:999px;
  display:flex;
  align-items:center;
  justify-content:center;
  background:rgba(79,70,229,.12);
  color:#4f46e5;
  font-size:28px;
}
.create-post-readonly h3{
  margin:0;
  font-size:18px;
  font-weight:800;
}
.create-post-readonly p{
  margin:0;
  max-width:360px;
  font-size:14px;
  line-height:1.5;
  color:#64748b;
}

@media (max-width: 860px){
  .create-post-dialog{
    border:0;
  }

  .create-post-frame{
    min-height:0;
  }
}

.global-live-modal.is-open{
  display:flex;
}

.global-live-modal-dialog{
  position:relative;
  width:min(1380px, 96vw);
  height:min(92vh, 860px);
  background:#111317;
  border-radius:0;
  overflow:hidden;
  box-shadow:0 28px 90px rgba(0,0,0,.38);
  display:grid;
  grid-template-rows:56px minmax(0,1fr) 108px;
  row-gap:10px;
}

.global-live-modal-top{
  display:grid;
  grid-template-columns:1fr auto 1fr;
  align-items:center;
  padding:0 18px;
  background:#050505;
  color:#fff;
  font-weight:800;
  font-size:13px;
}

.global-live-modal-top-center{
  justify-self:center;
  font-size:14px;
}

.global-live-top-actions{
  justify-self:end;
  display:inline-flex;
  align-items:center;
  gap:10px;
}

.global-live-speaker-btn{
  display:inline-flex;
  align-items:center;
  gap:8px;
  height:40px;
  padding:0 14px;
  border:0;
  border-radius:8px;
  background:#242424;
  color:#fff;
  font-weight:800;
  cursor:pointer;
}

.global-live-join-top-btn{
  background:#2b6fd0;
}

.global-live-join-top-btn[disabled]{
  opacity:.7;
  cursor:default;
}

.global-live-modal-body{
  display:grid;
  grid-template-columns:minmax(0,1fr) 0;
  min-height:0;
  height:100%;
  background:#111317;
  transition:grid-template-columns .22s ease;
}

.global-live-modal-dialog.has-chat .global-live-modal-body{
  grid-template-columns:minmax(0,1fr) 474px;
}

.global-live-modal-stage{
  position:relative;
  padding:20px 22px 0;
  background:#111317;
  min-height:0;
  height:100%;
  display:flex;
  overflow:hidden;
}

.global-live-modal-stage.has-snapshot-stage .global-live-modal-snapshot{
  opacity:1;
}

.global-live-modal-stage.use-snapshot-stage .global-live-modal-frame{
  opacity:0;
}

.global-live-modal-stage.is-direct-stage .global-live-modal-frame{
  opacity:0;
  pointer-events:none;
}

.global-live-modal-stage.is-direct-stage.use-snapshot-stage .global-live-modal-direct-video{
  opacity:0;
}

.global-live-modal-surface{
  position:relative;
  flex:1 1 auto;
  min-width:0;
  min-height:0;
  display:block;
}

.global-live-camera-off-stage{
  position:absolute;
  inset:0;
  z-index:4;
  display:none;
  align-items:center;
  justify-content:center;
  background:#000;
  pointer-events:none;
}

.global-live-modal-stage.is-camera-off .global-live-camera-off-stage{
  display:flex;
}

.global-live-camera-off-stage::before{
  content:'';
  position:absolute;
  left:50%;
  top:50%;
  width:min(86%, 1080px);
  height:4px;
  border-radius:999px;
  background:#ff4d3b;
  transform:translate(-50%, -50%) rotate(-28deg);
  box-shadow:0 0 0 1px rgba(255,77,59,.12);
}

.global-live-camera-off-icon{
  position:relative;
  width:120px;
  height:88px;
  color:#f2f5fb;
  display:grid;
  place-items:center;
  filter:drop-shadow(0 10px 24px rgba(0,0,0,.42));
}

.global-live-camera-off-icon .fa{
  font-size:78px;
  line-height:1;
}

.global-live-modal-frame,
.global-live-modal-direct-video,
.global-live-modal-snapshot{
  position:absolute;
  inset:0;
  width:100%;
  height:100%;
  display:block;
  background:#000;
}

.global-live-modal-frame{
  flex:1 1 auto;
  min-width:0;
  min-height:0;
  border:1px solid #2b2d31;
  z-index:0;
  transition:opacity .18s ease;
}

.global-live-modal-direct-video{
  border:1px solid #2b2d31;
  object-fit:cover;
  opacity:0;
  pointer-events:none;
  z-index:1;
  transition:opacity .18s ease;
}

.global-live-modal-stage.is-direct-stage .global-live-modal-direct-video{
  opacity:1;
}

.global-live-modal-stage.is-camera-off .global-live-modal-frame,
.global-live-modal-stage.is-camera-off .global-live-modal-direct-video,
.global-live-modal-stage.is-camera-off .global-live-modal-snapshot{
  opacity:0;
  pointer-events:none;
}

.global-live-modal-snapshot{
  border:1px solid #2b2d31;
  object-fit:cover;
  opacity:0;
  pointer-events:none;
  z-index:2;
  transition:opacity .18s ease;
}

.global-live-stage-comments,
.global-live-stage-reactions{
  position:absolute;
  inset:0;
  pointer-events:none;
  overflow:hidden;
}

.global-live-stage-comments{
  z-index:2;
}

.global-live-stage-reactions{
  z-index:3;
}

.global-live-stage-comment{
  position:absolute;
  left:24px;
  width:min(420px, calc(100% - 48px));
  display:grid;
  grid-template-columns:46px minmax(0,1fr);
  gap:12px;
  align-items:start;
  animation:globalLiveCommentFloat 5s ease-out forwards;
  filter:drop-shadow(0 16px 24px rgba(0,0,0,.24));
}

.global-live-stage-comment-avatar{
  width:46px;
  height:46px;
  border-radius:50%;
  display:grid;
  place-items:center;
  color:#fff;
  font-size:18px;
  font-weight:900;
  border:2px solid rgba(255,255,255,.26);
}

.global-live-stage-comment-card{
  min-width:0;
  padding:14px 18px;
  border-radius:22px;
  background:rgba(61,64,71,.82);
  backdrop-filter:blur(10px);
  color:#fff;
}

.global-live-stage-comment-author{
  font-size:17px;
  font-weight:900;
  line-height:1.15;
  margin-bottom:6px;
}

.global-live-stage-comment-body{
  font-size:16px;
  line-height:1.35;
  color:rgba(255,255,255,.95);
  word-break:break-word;
}

.global-live-stage-reaction{
  position:absolute;
  right:34px;
  bottom:80px;
  width:68px;
  height:68px;
  border-radius:50%;
  display:grid;
  place-items:center;
  font-size:42px;
  line-height:1;
  background:radial-gradient(circle at 35% 30%, rgba(255,255,255,.96), rgba(255,255,255,.72));
  box-shadow:0 14px 28px rgba(0,0,0,.18);
  animation:globalLiveReactionFloat 5s ease-out forwards;
  filter:drop-shadow(0 12px 18px rgba(255,255,255,.15));
}
.global-live-stage-reaction.is-love{
  color:#7c3aed;
}

@keyframes globalLiveCommentFloat{
  0%{ opacity:0; transform:translate3d(0, 24px, 0) scale(.96); filter:blur(6px); }
  10%{ opacity:1; transform:translate3d(0, 0, 0) scale(1); filter:blur(0); }
  82%{ opacity:1; transform:translate3d(18px, -26px, 0) scale(1); filter:blur(0); }
  100%{ opacity:0; transform:translate3d(32px, -44px, 0) scale(1.02); filter:blur(7px); }
}

@keyframes globalLiveReactionFloat{
  0%{ opacity:0; transform:translate3d(0, 18px, 0) scale(.78); filter:blur(5px); }
  12%{ opacity:1; transform:translate3d(0, 0, 0) scale(1); filter:blur(0); }
  82%{ opacity:1; transform:translate3d(-10px, -126px, 0) scale(1.04); filter:blur(0); }
  100%{ opacity:0; transform:translate3d(-18px, -168px, 0) scale(1.08); filter:blur(8px); }
}

.global-live-sidebar{
  min-width:0;
  background:#15181d;
  border-left:1px solid #2d3137;
  color:#fff;
  display:none;
  grid-template-rows:auto auto minmax(0,1fr) auto;
}

.global-live-modal-dialog.has-chat .global-live-sidebar{
  display:grid;
}

.global-live-side-head{
  display:grid;
  grid-template-columns:auto minmax(0,1fr) auto;
  align-items:center;
  gap:14px;
  padding:18px 24px;
  border-bottom:1px solid #2a2d33;
}

.global-live-side-avatar{
  width:58px;
  height:58px;
  border-radius:50%;
  border:3px solid rgba(255,255,255,.14);
  display:grid;
  place-items:center;
  color:#fff;
  font-weight:900;
  font-size:24px;
  background:linear-gradient(135deg,#2563eb,#0f172a);
}

.global-live-side-title{
  min-width:0;
  font-size:18px;
  font-weight:800;
  line-height:1.25;
}

.global-live-side-badge{
  display:inline-flex;
  align-items:center;
  justify-content:center;
  min-height:42px;
  padding:0 22px;
  border-radius:999px;
  border:1px solid rgba(255,255,255,.14);
  background:rgba(255,255,255,.06);
  color:rgba(255,255,255,.92);
  font-size:13px;
  font-weight:900;
  letter-spacing:.08em;
  text-transform:uppercase;
  white-space:nowrap;
}

.global-live-side-close{
  width:48px;
  height:48px;
  border-radius:50%;
  border:1px solid rgba(255,255,255,.16);
  background:rgba(255,255,255,.05);
  color:#fff;
  font-size:28px;
  line-height:1;
  cursor:pointer;
}

.global-live-side-stats{
  display:grid;
  grid-template-columns:repeat(3,minmax(0,1fr));
  border-bottom:1px solid #2a2d33;
}

.global-live-side-stat{
  padding:15px 8px;
  text-align:center;
  font-weight:800;
  color:rgba(255,255,255,.78);
  font-size:14px;
}

.global-live-side-scroll{
  min-height:0;
  overflow-y:auto;
  padding:18px 22px;
}

.global-live-request-card{
  background:#fbfcff;
  border-radius:22px;
  padding:20px 18px;
  color:#1a2130;
  box-shadow:0 14px 28px rgba(0,0,0,.16);
  margin-bottom:18px;
}

.global-live-request-card h4{
  margin:0 0 10px;
  font-size:16px;
  font-weight:900;
}

.global-live-request-card p{
  margin:0;
  color:#6f7d95;
  line-height:1.45;
  font-size:14px;
}

.global-live-request-card .accent-word{
  color:#ef6e60;
  font-weight:800;
}

.global-live-request-empty{
  margin-top:22px;
  border:1px dashed #c8d5ef;
  border-radius:20px;
  padding:18px;
  color:#6f7d95;
  font-size:14px;
  background:#fff;
}

.global-live-comments-box{
  border:1px dashed rgba(255,255,255,.18);
  border-radius:22px;
  background:rgba(255,255,255,.02);
  min-height:96px;
  padding:18px 20px;
  color:rgba(255,255,255,.64);
  font-size:14px;
  line-height:1.5;
}

.global-live-comments-box.has-comments{
  color:#fff;
  padding:0;
  background:transparent;
  border-style:solid;
  overflow:hidden;
}

.global-live-comments-list{
  max-height:420px;
  overflow-y:auto;
  padding:12px;
  display:grid;
  gap:12px;
}

.global-live-comment-card{
  background:rgba(255,255,255,.04);
  border:1px solid rgba(255,255,255,.08);
  border-radius:16px;
  padding:12px 14px;
}

.global-live-comment-author{
  font-size:13px;
  font-weight:900;
  margin-bottom:5px;
  color:#fff;
}

.global-live-comment-body{
  font-size:14px;
  color:rgba(255,255,255,.78);
  line-height:1.45;
  word-break:break-word;
}

.global-live-compose{
  padding:18px 22px 22px;
  border-top:1px solid #2a2d33;
  background:#152033;
}

.global-live-compose h4{
  margin:0 0 12px;
  color:rgba(255,255,255,.82);
  font-size:16px;
  font-weight:800;
}

.global-live-compose textarea{
  width:100%;
  min-height:94px;
  border-radius:18px;
  border:3px solid #244f98;
  background:#1f2329;
  color:#fff;
  padding:18px 18px;
  resize:none;
  font:inherit;
  outline:none;
  box-shadow:inset 0 0 0 1px rgba(255,255,255,.06);
}

.global-live-compose textarea::placeholder{ color:rgba(255,255,255,.45); }

.global-live-compose-row{
  margin-top:18px;
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:12px;
}

.global-live-compose-feedback{
  /* min-height:18px; */
  color:rgba(255,255,255,.75);
  font-size:12px;
}

.global-live-compose-feedback.error{ color:#ff8d83; }
.global-live-compose-feedback.success{ color:#90deac; }

.global-live-send-btn{
  min-width:122px;
  height:48px;
  border:0;
  border-radius:14px;
  background:linear-gradient(180deg,#2e7be7 0%, #2b6fd0 100%);
  color:#fff;
  font-size:15px;
  font-weight:900;
  cursor:pointer;
  box-shadow:0 10px 22px rgba(43,111,208,.32);
}

.global-live-modal-bottom{
  position:relative;
  display:flex;
  align-items:center;
  justify-content:space-between;
  padding:0 28px;
  background:#1c1c1c;
  color:#fff;
  border-top:1px solid #2a2d33;
}

.global-live-controls,
.global-live-controls-right{
  display:flex;
  align-items:center;
  gap:34px;
}

.global-live-quick-reactions{
  display:flex;
  align-items:center;
  gap:14px;
  padding:0 6px 0 0;
}

.global-live-quick-reaction{
  width:68px;
  height:68px;
  border:0;
  border-radius:50%;
  display:inline-flex;
  align-items:center;
  justify-content:center;
  font-size:38px;
  line-height:1;
  cursor:pointer;
  background:rgba(255,255,255,.06);
  box-shadow:inset 0 0 0 1px rgba(255,255,255,.08);
  transition:transform .16s ease, box-shadow .16s ease, filter .16s ease;
}
.global-live-quick-reaction .live-love-heart{
  color:#ec4899;
  font-size:.92em;
  line-height:1;
}

.global-live-quick-reaction:hover{
  transform:translateY(-2px) scale(1.04);
  box-shadow:0 10px 22px rgba(0,0,0,.24), inset 0 0 0 1px rgba(255,255,255,.12);
}

.global-live-quick-reaction.is-active{
  box-shadow:0 0 0 3px rgba(255,255,255,.18), 0 14px 26px rgba(0,0,0,.28);
  filter:saturate(1.15);
}

.global-live-control{
  border:0;
  background:transparent;
  color:#fff;
  display:grid;
  justify-items:center;
  gap:8px;
  font-weight:700;
  cursor:pointer;
  min-width:78px;
}

.global-live-control .icon{
  font-size:26px;
  line-height:1;
}

.global-live-control-label{
  font-size:12px;
  line-height:1.1;
  text-align:center;
}

.global-live-control-count{
  display:block;
  margin-top:4px;
  font-size:11px;
  color:rgba(255,255,255,.82);
}

.global-live-control.is-active{
  color:#4c95ff;
}

.global-live-control i{
  position:relative;
  display:inline-block;
}

.global-live-control i.has-off-slash::after{
  content:'';
  position:absolute;
  top:-2px;
  left:50%;
  width:3px;
  height:22px;
  border-radius:999px;
  background:#ff5c5c;
  transform:translateX(-50%) rotate(42deg);
  box-shadow:0 0 0 1px rgba(12,16,24,.2);
}

.global-live-end{
  border:0;
  background:transparent;
  color:#ff6d61;
  display:grid;
  justify-items:center;
  gap:8px;
  font-weight:800;
  cursor:pointer;
  min-width:110px;
}

.global-live-settings-panel{
  display:none;
  min-height:100%;
  background:#252a30;
}

.global-live-settings-body{
  display:grid;
  gap:14px;
  padding:16px 18px 18px;
}

.global-live-settings-item{
  display:grid;
  gap:6px;
}

.global-live-settings-item label,
.global-live-settings-toggle{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:12px;
  color:#f8fafc;
  font-size:14px;
  font-weight:700;
}

.global-live-settings-item select{
  width:100%;
  border:1px solid rgba(255,255,255,.12);
  border-radius:12px;
  background:rgba(255,255,255,.06);
  color:#fff;
  padding:10px 12px;
  font-size:14px;
  font-weight:700;
  outline:none;
}

.global-live-settings-item input[type="checkbox"]{
  width:18px;
  height:18px;
  accent-color:#4c95ff;
  flex:0 0 auto;
}

.global-live-settings-note{
  color:rgba(217,222,234,.78);
  font-size:12px;
  line-height:1.45;
}

.global-live-end .icon{
  font-size:28px;
  line-height:1;
}

.global-live-end .global-live-control-label{
  color:#ff6d61;
}

.global-live-confirm{
  position:absolute;
  inset:56px 0 92px;
  display:none;
  align-items:center;
  justify-content:center;
  padding:24px;
  background:rgba(12,16,28,.52);
  z-index:8;
}

.global-live-confirm.is-open{
  display:flex;
}

.global-live-confirm-card{
  width:min(500px, calc(100vw - 48px));
  border-radius:24px;
  background:#fff;
  padding:30px 34px 24px;
  text-align:center;
  box-shadow:0 22px 50px rgba(0,0,0,.28);
}

.global-live-confirm-card h3{
  margin:0;
  font-size:28px;
  line-height:1.15;
  color:#0e1b36;
  font-weight:900;
}

.global-live-confirm-card p{
  margin:18px 0 0;
  font-size:16px;
  line-height:1.5;
  color:#596882;
}

.global-live-confirm-actions{
  margin-top:26px;
  display:flex;
  align-items:center;
  justify-content:center;
  gap:14px;
}

.global-live-confirm-btn{
  min-width:146px;
  height:56px;
  border-radius:16px;
  border:1px solid #d6dde9;
  background:#fff;
  color:#33445f;
  font-size:16px;
  font-weight:900;
  cursor:pointer;
}

.global-live-confirm-btn.confirm{
  border-color:#f44343;
  background:#f44343;
  color:#fff;
}

.global-live-modal-dialog.global-live-modern{
  width:100vw;
  height:100vh;
  border:0;
  border-radius:0;
  background:#11131a;
}

.global-live-modern .global-live-modal-top{
  grid-template-columns:minmax(0, 1fr) auto;
  gap:18px;
  padding:0 12px;
  height:60px;
  background:#171822;
}

.global-live-modern-title{
  min-width:0;
  display:grid;
  grid-template-columns:48px minmax(0,1fr);
  column-gap:14px;
  row-gap:2px;
  align-items:center;
}
.global-live-modern-owner-avatar{
  grid-row:1 / span 2;
  width:48px;
  height:48px;
  border-radius:50%;
  border:2px solid rgba(255,255,255,.22);
  display:grid;
  place-items:center;
  color:#fff;
  font-size:21px;
  font-weight:900;
  background:linear-gradient(135deg,#2563eb,#111827);
  overflow:hidden;
}
.global-live-modern-title-row{display:flex;align-items:center;gap:10px;min-width:0;}
.global-live-modern-title-text{
  font-size:27px;
  font-weight:800;
  letter-spacing:-.03em;
  white-space:nowrap;
  overflow:hidden;
  text-overflow:ellipsis;
  color:#fff;
}
.global-live-modern-live-badge{
  display:inline-flex;align-items:center;justify-content:center;height:22px;padding:0 10px;border-radius:999px;
  background:#ff5c3d;color:#fff;font-size:11px;font-weight:900;text-transform:uppercase;letter-spacing:.04em;
}
.global-live-modern-subtitle{font-size:13px;color:rgba(255,255,255,.7);}
.global-live-modern .global-live-modal-top-center{font-size:12px;color:rgba(255,255,255,.72);font-weight:700;}
.global-live-modern .global-live-speaker-btn{
  width:42px;height:42px;min-height:42px;padding:0;border-radius:10px;background:rgba(255,255,255,.07);font-size:16px;
}
.global-live-modern .global-live-speaker-btn span,
.global-live-modern .global-live-speaker-btn .icon:last-child{display:none;}
.global-live-modern .global-live-join-top-btn{width:auto;padding:0 16px;font-size:13px;}
.global-live-modern .global-live-join-top-btn span{display:inline;}
.global-live-top-close{
  display:inline-flex;align-items:center;justify-content:center;min-height:42px;padding:0 18px;border:0;border-radius:12px;
  background:#6f3c2f;color:#fff;font-size:14px;font-weight:800;cursor:pointer;
}
.global-live-modern .global-live-modal-body{grid-template-columns:minmax(0, 1fr) 0;background:#0e1118;height:100%;}
.global-live-modern.has-chat .global-live-modal-body{grid-template-columns:minmax(0, 1fr) 360px;}
.global-live-modern .global-live-modal-stage{padding:0;background:#0d1016;height:100%;display:flex;min-height:0;}
.global-live-modern .global-live-modal-surface{flex:1 1 auto;min-width:0;min-height:0;}
.global-live-modern .global-live-modal-frame{border:0;min-width:0;min-height:0;}
.global-live-modern .global-live-modal-snapshot{border:0;object-fit:contain;}
.global-live-modern .global-live-stage-comment{
  left:26px;
  width:min(390px, calc(100% - 52px));
}
.global-live-modern .global-live-stage-reaction{
  right:28px;
  bottom:72px;
}
.global-live-modern .global-live-sidebar{
  min-width:0;
  background:#050505;
  border-left:1px solid rgba(255,255,255,.14);
  color:#f5f5f5;
  display:none;
  grid-template-rows:auto minmax(0,1fr) auto;
  height:100%;
  overflow:hidden;
}
.global-live-modern.has-chat .global-live-sidebar{display:grid;}
.global-live-modern.sidebar-mode-reactions #globalLiveChatPanel,
.global-live-modern.sidebar-mode-reactions #globalLiveCompose,
.global-live-modern.sidebar-mode-reactions #globalLiveDescriptionPanel{display:none;}
.global-live-modern.sidebar-mode-reactions #globalLiveReactionPanel{display:flex;}
.global-live-modern.sidebar-mode-chat #globalLiveReactionPanel,
.global-live-modern.sidebar-mode-chat #globalLiveDescriptionPanel{display:none;}
.global-live-modern.sidebar-mode-description #globalLiveChatPanel,
.global-live-modern.sidebar-mode-description #globalLiveReactionPanel,
.global-live-modern.sidebar-mode-description #globalLiveSettingsPanel,
.global-live-modern.sidebar-mode-description #globalLiveCompose{display:none;}
.global-live-modern.sidebar-mode-description #globalLiveDescriptionPanel{display:flex;}
.global-live-modern.sidebar-mode-settings #globalLiveChatPanel,
.global-live-modern.sidebar-mode-settings #globalLiveReactionPanel,
.global-live-modern.sidebar-mode-settings #globalLiveDescriptionPanel,
.global-live-modern.sidebar-mode-settings #globalLiveCompose{display:none;}
.global-live-modern.sidebar-mode-settings #globalLiveSettingsPanel{display:block;}
.global-live-modern.sidebar-mode-description .global-live-side-stats{display:none;}
.global-live-modern.sidebar-mode-settings .global-live-side-stats{display:none;}
.global-live-modern.sidebar-mode-description .global-live-side-scroll{
  padding:0;
  background:#252a30;
}
.global-live-modern.sidebar-mode-settings .global-live-side-scroll{
  padding:0;
  background:#252a30;
  overflow-y:auto;
}
.global-live-modern.sidebar-mode-description .global-live-side-head{
  position:relative;
  min-height:0;
  padding:0;
  border-bottom:0;
  background:transparent;
}
.global-live-modern.sidebar-mode-settings .global-live-side-head{
  position:relative;
  min-height:0;
  padding:20px 24px 14px;
  border-bottom:0;
  background:#252a30;
}
.global-live-modern.sidebar-mode-description .global-live-side-close{
  position:absolute;
  top:36px;
  right:24px;
  z-index:3;
}
.global-live-modern.sidebar-mode-description .global-live-side-title{display:none;}
.global-live-modern.sidebar-mode-settings .global-live-side-title{display:flex;}
.global-live-modern .global-live-side-head{
  display:grid;
  grid-template-columns:minmax(0,1fr) auto;
  align-items:start;
  gap:14px;
  padding:26px 28px 18px;
  border-bottom:1px solid rgba(255,255,255,.08);
  background:#050505;
}
.global-live-modern .global-live-side-avatar{display:none;}
.global-live-modern .global-live-side-title{
  min-width:0;
  display:flex;
  align-items:flex-end;
  gap:10px;
  font-size:19px;
  font-weight:900;
  line-height:1.1;
}
.global-live-modern .global-live-side-title strong{
  display:block;
  color:#f8fafc;
  font-size:22px;
  font-weight:900;
  letter-spacing:-.02em;
}
.global-live-modern .global-live-side-title span{color:rgba(255,255,255,.38);font-size:18px;font-weight:800;}
.global-live-modern .global-live-side-badge{display:none;}
.global-live-modern .global-live-side-close{
  width:56px;
  height:56px;
  border-radius:50%;
  border:0;
  background:rgba(255,255,255,.12);
  color:#f8fafc;
  font-size:30px;
  line-height:1;
  cursor:pointer;
}
.global-live-modern .global-live-side-stats{display:none;}
.global-live-modern .global-live-side-stat{padding:16px 8px 14px;text-align:center;font-weight:800;color:rgba(218,224,235,.8);font-size:13px;}
.global-live-modern .global-live-side-stat strong{color:rgba(255,255,255,.92);font-size:15px;margin-right:4px;}
.global-live-modern .global-live-side-scroll{
  flex:1 1 auto;
  min-height:0;
  padding:14px 18px 0 28px;
  background:#050505;
  display:flex;
  flex-direction:column;
  overflow:hidden;
}
.global-live-modern .global-live-chat-panel{display:flex;flex-direction:column;min-height:0;flex:1 1 auto;}
.global-live-modern .global-live-comments-box{
  margin:0;
  border:0;
  border-radius:0;
  background:transparent;
  min-height:96px;
  padding:10px 0 16px;
  color:#858b99;
  font-size:14px;
  line-height:1.5;
  margin-bottom:0;
  flex:1 1 auto;
  min-height:0;
  display:flex;
  flex-direction:column;
}
.global-live-modern .global-live-comments-box.has-comments{
  color:#111827;
  padding:0;
  background:transparent;
  border-style:solid;
  overflow:hidden;
}
.global-live-modern .global-live-comments-list{
  overflow-y:auto;
  padding:2px 10px 22px 0;
  display:grid;
  gap:30px;
  /* flex:1 1 auto; */
  min-height:0;
}
.global-live-modern .global-live-reaction-panel{display:none;flex-direction:column;min-height:0;flex:1 1 auto;padding:4px 10px 18px 0;}
.global-live-modern .global-live-description-panel{
  display:none;
  flex-direction:column;
  min-height:0;
  flex:1 1 auto;
  padding:0;
  background:#252a30;
}
.global-live-modern .global-live-description-card{
  min-height:0;
  height:100%;
  display:grid;
  grid-template-rows:auto auto minmax(0,1fr);
  background:#252a30;
}
.global-live-modern .global-live-description-head{
  display:grid;
  grid-template-columns:auto minmax(0,1fr);
  align-items:center;
  gap:16px;
  padding:12px 10px 18px 10px;
}
.global-live-modern .global-live-description-avatar{width:58px;height:58px;border-radius:50%;display:grid;place-items:center;font-size:22px;font-weight:900;color:#f8fafc;background:radial-gradient(circle at 30% 30%, #44d1c3 0%, #1e8b98 45%, #103848 100%);}
.global-live-modern .global-live-description-meta{min-width:0;}
.global-live-modern .global-live-description-title{margin:0;font-size:18px;line-height:1.08;font-weight:900;color:#f8fafc;letter-spacing:-.03em;word-break:break-word;}
.global-live-modern .global-live-description-sub{margin-top:6px;font-size:12px;line-height:1.35;color:rgba(255,255,255,.38);word-break:break-word;}
.global-live-modern .global-live-description-divider{height:1px;background:rgba(255,255,255,.06);}
.global-live-modern .global-live-description-scroll{
  min-height:0;
  overflow-y:auto;
  padding:18px 10px 28px 10px;
}
.global-live-modern .global-live-description-body{margin:0;font-size:10px;line-height:1.62;color:rgba(255,255,255,.92);white-space:pre-wrap;word-break:break-word;}
.global-live-modern .global-live-reaction-tabs{display:flex;gap:10px;flex-wrap:wrap;padding:4px 0 16px;border-bottom:1px solid rgba(255,255,255,.08);margin-bottom:16px;}
.global-live-modern .global-live-reaction-tab{border:0;background:transparent;color:rgba(255,255,255,.7);font-size:14px;font-weight:800;cursor:pointer;padding:0 0 8px;display:inline-flex;align-items:center;gap:8px;border-bottom:3px solid transparent;}
.global-live-modern .global-live-reaction-tab.is-active{color:#3b82f6;border-bottom-color:#3b82f6;}
.global-live-modern .global-live-reaction-list{min-height:0;overflow-y:auto;display:grid;gap:16px;padding-right:4px;}
.global-live-modern .global-live-reaction-item{display:grid;grid-template-columns:56px minmax(0,1fr) auto;gap:14px;align-items:center;}
.global-live-modern .global-live-reaction-avatar{width:56px;height:56px;border-radius:999px;display:flex;align-items:center;justify-content:center;color:#fff;font-size:20px;font-weight:900;position:relative;box-shadow:0 10px 30px rgba(0,0,0,.35);}
.global-live-modern .global-live-reaction-badge{position:absolute;right:-2px;bottom:-2px;width:24px;height:24px;border-radius:999px;background:#11131a;display:grid;place-items:center;font-size:16px;}
.global-live-modern .global-live-reaction-main{min-width:0;}
.global-live-modern .global-live-reaction-name{color:#f8fafc;font-size:17px;font-weight:800;line-height:1.2;word-break:break-word;}
.global-live-modern .global-live-reaction-time{margin-top:4px;color:rgba(255,255,255,.46);font-size:13px;font-weight:700;}
.global-live-modern .global-live-reaction-time .global-live-reaction-type{color:rgba(255,255,255,.74);margin-right:8px;}
.global-live-modern .global-live-reaction-action{border:0;border-radius:14px;min-width:144px;height:54px;padding:0 18px;background:rgba(255,255,255,.14);color:#f3f4f6;font-size:15px;font-weight:800;cursor:pointer;}
.global-live-modern .global-live-reaction-action[disabled]{opacity:.72;cursor:default;}
.global-live-modern .global-live-reaction-action.is-static{background:rgba(255,255,255,.08);color:rgba(255,255,255,.76);}
.global-live-modern .global-live-reaction-empty{color:rgba(255,255,255,.56);font-size:14px;font-weight:700;padding:8px 0;}
.global-live-modern .global-live-comment-card{border:0;background:transparent;border-radius:0;padding:0;display:grid;grid-template-columns:52px minmax(0,1fr);gap:14px;align-items:start;}
.global-live-modern .global-live-comment-avatar{width:40px;height:40px;border-radius:999px;display:flex;align-items:center;justify-content:center;color:#fff;font-size:18px;font-weight:900;box-shadow:0 10px 30px rgba(0,0,0,.35);}
.global-live-modern .global-live-comment-main{min-width:0;}
.global-live-modern .global-live-comment-author{display:flex;align-items:center;gap:3px;font-size:15px;color:rgba(255,255,255,.62);margin-bottom:5px;}
.global-live-modern .global-live-comment-body{font-size:10px;color:#f8fafc;line-height:1.42;letter-spacing:-.02em;word-break:break-word;}
.global-live-modern .global-live-comment-meta{display:flex;align-items:center;gap:18px;margin-top:12px;color:rgba(255,255,255,.46);font-size:12px;}
.global-live-modern .global-live-comment-reply{border:0;background:transparent;color:rgba(255,255,255,.64);padding:0;font:inherit;cursor:pointer;}
.global-live-modern .global-live-comment-like{margin-left:auto;display:inline-flex;align-items:center;gap:8px;border:0;background:transparent;padding:0;font:inherit;appearance:none;color:rgba(255,255,255,.64);cursor:pointer;}
.global-live-modern .global-live-comment-like i{font-size:12px;}
.global-live-modern .global-live-comment-like-count{font-size:12px;font-weight:800;line-height:1;}
.global-live-modern .global-live-comment-like.is-liked{color:#ec4899;}
.global-live-modern .global-live-comment-like.is-liked i::before{content:"\f004";}
.global-live-modern .global-live-comment-likes{margin-top:5px;color:rgba(255,255,255,.46);font-size:13px;font-weight:700;line-height:1.4;}
.global-live-chat-tabs{display:none;}
.global-live-chat-tab{
  position:relative;border:0;background:transparent;color:#5f6475;font-size:13px;font-weight:700;cursor:pointer;padding:10px 0 4px;
}
.global-live-chat-tab.is-active{color:#31374a;}
.global-live-chat-tab.is-active::after{content:"";position:absolute;left:0;right:0;bottom:-1px;height:2px;border-radius:999px;background:#4868d8;}
.global-live-modern .global-live-compose{padding:12px 20px 22px;border-top:1px solid rgba(255,255,255,.08);background:#050505;}
.global-live-modern .global-live-compose h4{display:none;}
.global-live-modern .global-live-compose textarea{
  min-height:32px;max-height:96px;border:0;border-radius:0;padding:4px 0 0;background:transparent;color:#f8fafc;resize:none;font-size:18px;line-height:24px;align-self:center;margin:0;
}
.global-live-modern .global-live-compose textarea::placeholder{color:rgba(255,255,255,.34);}
.global-live-modern .global-live-compose-row{display:grid;grid-template-columns:minmax(0,1fr) 52px;gap:12px;align-items:center;margin-top:0;}
.global-live-modern .global-live-send-btn{width:52px;height:52px;min-width:52px;border-radius:999px;border:0;display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:28px;box-shadow:0 10px 28px rgba(0,0,0,.32);}
.global-live-modern .global-live-send-btn{background:linear-gradient(180deg,#7f1d1d 0%,#991b1b 100%);color:#fda4af;}
.global-live-modern .global-live-compose-inputwrap{display:flex;align-items:center;gap:12px;min-height:52px;padding:0 14px 0 18px;border-radius:999px;background:#202020;border:1px solid rgba(255,255,255,.06);}
.global-live-modern .global-live-compose-tool{border:0;background:transparent;color:rgba(255,255,255,.88);width:32px;height:32px;display:grid;place-items:center;font-size:20px;cursor:pointer;padding:0;}
.global-live-modern .global-live-compose-feedback{color:rgba(255,255,255,.54);}
.global-live-modern .global-live-modal-bottom{padding:0 16px;min-height:66px;background:#171822;box-shadow:0 -10px 24px rgba(0,0,0,.18);}
.global-live-modern .global-live-controls,
.global-live-modern .global-live-controls-right{gap:8px;}
.global-live-modern .global-live-controls-right{
  margin-left:auto;
  justify-content:flex-end;
  flex-wrap:nowrap;
  min-width:max-content;
}
.global-live-modern .global-live-quick-reactions{
  display:flex;
  align-items:center;
  gap:10px;
  padding:0;
}
.global-live-modern .global-live-quick-reaction{
  width:56px;
  height:56px;
  font-size:32px;
  background:rgba(255,255,255,.05);
  box-shadow:inset 0 0 0 1px rgba(255,255,255,.08);
}
.global-live-modern .global-live-quick-reaction .live-love-heart{
  color:#ec4899;
}
.global-live-modern .global-live-control{
  min-width:62px;height:48px;padding:6px 10px;border-radius:10px;gap:3px;background:rgba(255,255,255,.04);color:#d9deea;
}
.global-live-modern .global-live-control .icon{font-size:16px;}
.global-live-modern .global-live-control-label{font-size:11px;font-weight:700;}
.global-live-modern .global-live-control-count{display:inline;margin-top:0;}
.global-live-modern .global-live-end{
  min-width:132px;height:44px;padding:0 18px;border-radius:12px;background:#6f3c2f;color:#fff;display:inline-flex;align-items:center;justify-content:center;gap:8px;
}
.global-live-modern .global-live-end .global-live-control-label{color:#fff;}

@media (max-width: 860px){
  .global-live-modal{
    padding:0;
  }

  .global-live-modal-dialog{
    width:100vw;
    height:100vh;
    border-radius:0;
    grid-template-rows:56px minmax(0,1fr) auto;
  }

  .global-live-modal-body,
  .global-live-modal-dialog.has-chat .global-live-modal-body{
    grid-template-columns:1fr;
  }

  .global-live-confirm{
    inset:56px 0 0;
  }

  .global-live-sidebar{
    border-left:0;
    border-top:1px solid #2d3137;
  }

  .global-live-modal-stage{
    padding:14px 14px 0;
  }

  .global-live-stage-comment{
    left:14px;
    width:min(320px, calc(100% - 28px));
    grid-template-columns:40px minmax(0,1fr);
    gap:10px;
  }

  .global-live-stage-comment-avatar{
    width:40px;
    height:40px;
    font-size:15px;
  }

  .global-live-stage-comment-card{
    padding:12px 14px;
    border-radius:18px;
  }

  .global-live-stage-comment-author{
    font-size:15px;
  }

  .global-live-stage-comment-body{
    font-size:14px;
  }

  .global-live-stage-reaction{
    right:14px;
    width:56px;
    height:56px;
    font-size:34px;
  }

  .global-live-modal-bottom{
    padding:18px 20px 20px;
    flex-direction:column;
    align-items:stretch;
    gap:18px;
  }

  .global-live-controls,
  .global-live-controls-right{
    justify-content:space-between;
    gap:16px;
    flex-wrap:wrap;
  }
}

.sh-logopanel {
    position: fixed;
    z-index: 1000;
    top: 0;
    /* left: -185px; */
    width: 340px;
    height: 100px;
    background-color: #697077;
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0 20px;
    transition: none;
}
/* ---------- badge (clean) ---------- */
[data-chat-badge].chatBadge{
  position:absolute;
  top: 0px;
  right: 0px;
  transform: translate(30%, -20%);
  background:#ef4444;
  color:#fff;
  border-radius: 999px;
  padding: 3px 7px;
  font-size: 11px;
  line-height: 1;
  min-width: 18px;
  text-align:center;
  font-weight: 900;
  box-shadow: 0 0 0 3px rgba(239,68,68,.14);
}
.dropdown-bestchat-link{
  position: relative;
  border-radius: 12px;
}
.dropdown-bestchat-link:hover{
  background: var(--msb-dd-hover);
}

/* ---------- dropdown: business messages ---------- */
.dropdown-bestchat-menu{
  width:320px;
  min-width:320px;
  max-width:92vw;
  padding:0;
  background:var(--msb-dd-surface);
  color:var(--msb-dd-text);
}
.dropdown-bestnoti-menu{
  width:280px;
  min-width:280px;
  max-width:92vw;
  padding:0;
  background:var(--msb-dd-surface);
  color:var(--msb-dd-text);
}
.bestnoti-profile-top{
  background: var(--msb-dd-head-bg);
  color: var(--msb-dd-head-text);
}
.bestnoti-top-avatar{
  align-items:center;
  background:linear-gradient(135deg, #2563eb, #0f172a);
}
.bestnoti-top-avatar .icon{
  font-size:28px;
  line-height:1;
}
.bestnoti-detail{
  display:block;
  margin-top:6px;
}
.dropdown-bestnoti-list{
  max-height:320px;
  overflow:auto;
  background:var(--msb-dd-surface);
  padding:10px 0;
}
.dropdown-bestnoti-empty{
  padding:16px 14px;
  font-size:13px;
  line-height:1.45;
  background:var(--msb-dd-surface);
  color:var(--msb-dd-muted);
  font-weight:600;
}
.dropdown-bestnoti-item{
  display:flex;
  align-items:flex-start;
  gap:10px;
  padding:10px 14px;
  text-decoration:none;
  color:var(--msb-dd-text);
  background:var(--msb-dd-surface);
}
.dropdown-bestnoti-item + .dropdown-bestnoti-item{
  border-top:1px solid var(--msb-dd-divider);
}
.dropdown-bestnoti-item:hover{ background:var(--msb-dd-hover); color:var(--msb-dd-text); }
.dropdown-bestnoti-item.is-unread{ background:var(--msb-dd-unread); }
.bestnoti-avatar{
  width:32px;
  height:32px;
  border-radius:999px;
  display:flex;
  align-items:center;
  justify-content:center;
  background:linear-gradient(135deg, #2563eb, #0f172a);
  color:#fff;
  font-weight:700;
  font-size:11px;
  flex:0 0 32px;
}
.bestnoti-mid{ min-width:0; flex:1 1 auto; }
.bestnoti-text{
  font-size:13px;
  line-height:1.35;
  color:var(--msb-dd-text);
}
.bestnoti-time{
  margin-top:3px;
  font-size:11px;
  color:var(--msb-dd-muted);
}
.bestnoti-footer-nav{
  padding:8px 0;
}
.bestnoti-footer-nav li a{
  color:var(--msb-dd-text);
}
.bestnoti-footer-nav li a:hover{
  color:var(--msb-dd-text);
}
#headerNotificationBadge.feed-ig-badge{
  right:-4px;
  top:-2px;
}
#headerNotificationTopBadge.chatBadge{
  top:-4px;
  right:-7px;
}
.bestchat-profile-top{
  background: var(--msb-dd-head-bg);
  color: var(--msb-dd-head-text);
}
.bestchat-top-avatar{
  align-items:center;
  background:linear-gradient(135deg, #2563eb, #0f172a);
}
.bestchat-top-avatar .icon{
  font-size:28px;
  line-height:1;
}
.bestchat-detail{
  display:block;
  margin-top:6px;
}
.bestchat-detail.is-warn{
  color:#fde68a;
}
.dropdown-bestchat-list{
  max-height:320px;
  overflow:auto;
  background:var(--msb-dd-surface);
  padding:10px 0;
}
.dropdown-bestchat-empty{
  padding:16px 14px;
  font-size:13px;
  line-height:1.45;
  background:var(--msb-dd-surface);
  color:var(--msb-dd-muted);
}
.bestchat-menu-item{
  display:flex;
  align-items:flex-start;
  gap:10px;
  padding:10px 14px;
  text-decoration:none;
  color:var(--msb-dd-text);
}
.bestchat-menu-item + .bestchat-menu-item{
  border-top:1px solid var(--msb-dd-divider);
}
.bestchat-menu-item:hover{
  background:var(--msb-dd-hover);
  color:var(--msb-dd-text);
}
.bestchat-menu-item-unknown{
  color:var(--msb-dd-warn);
}
.bestchat-menu-item-unknown .bestchat-menu-text{
  color:inherit;
}
.bestchat-menu-item-unknown:hover{
  background:var(--msb-dd-warn-hover);
  color:var(--msb-dd-warn);
}
.bestchat-menu-icon,
.bestchat-menu-avatar{
  overflow:hidden;
  width:38px;
  height:38px;
  border-radius:999px;
  display:flex;
  align-items:center;
  justify-content:center;
  flex: 0 0 auto;
}
.bestchat-menu-icon{
  background:linear-gradient(135deg, #f59e0b, #92400e);
  color:#fff;
  font-size:18px;
}
.bestchat-menu-avatar{
  border:2px solid rgba(255,255,255,.92);
  box-shadow:0 10px 22px rgba(0,0,0,.14), inset 0 1px 0 rgba(255,255,255,.28);
}
.bestchat-menu-avatar img{
  width:100%;
  height:100%;
  object-fit:cover;
  display:block;
  border-radius:inherit;
}
.bestchat-menu-body{
  flex:1;
  min-width:0;
}
.bestchat-menu-head{
  display:flex;
  align-items:center;
  gap:10px;
}
.bestchat-menu-name{
  font-weight: 900;
  font-size: 13px;
  white-space:nowrap;
  overflow:hidden;
  text-overflow:ellipsis;
  flex:1 1 auto;
}
.bestchat-menu-sub{
  display:block;
  margin-top:2px;
  font-size:11px;
  color:var(--msb-dd-muted);
  white-space:nowrap;
  overflow:hidden;
  text-overflow:ellipsis;
}
.bestchat-menu-text{
  display:block;
  margin-top:3px;
  font-size:12px;
  color:var(--msb-dd-soft);
  white-space:nowrap;
  overflow:hidden;
  text-overflow:ellipsis;
}
.bestchat-badge{
  margin-left:auto;
  background:#ef4444;
  color:#fff;
  border-radius:999px;
  padding:2px 7px;
  font-size:11px;
  font-weight:900;
  min-width:18px;
  text-align:center;
  box-shadow:0 0 0 3px rgba(239,68,68,.14);
}
.bestchat-footer-nav{
  padding:8px 0;
}
.bestchat-footer-nav li a{
  color:var(--msb-dd-text);
}
.bestchat-footer-nav li a:hover{
  color:var(--msb-dd-text);
}
@media (max-width: 575.98px){
  .dropdown-bestchat-menu,
  .dropdown-bestnoti-menu,
  .dropdown-bestprofile-menu{
    width:min(320px, 92vw);
    min-width:0;
  }
  .feed-ig-rail .dropdown-bestchat-menu,
  .feed-ig-rail .dropdown-bestnoti-menu,
  .feed-ig-rail .dropdown-bestprofile-menu{
    min-width:0;
  }
}

/* ---------- Profile avatar + menu ---------- */
.dropdown-bestprofile-link{ border-radius: 14px; }
.dropdown-bestprofile-link:hover{ background: var(--msb-dd-hover); }

.bestprofile-avatar{
  overflow:hidden;
  width: 38px;
  height: 38px;
  border-radius: 999px;
  color:#fff;
  display:inline-flex;
  /* align-items:center; */
  justify-content:center;
  font-weight: 900;
  /* letter-spacing: .6px; */
  border: 2px solid rgba(255,255,255,.92);
  box-shadow: 0 10px 22px rgba(0,0,0,.14), inset 0 1px 0 rgba(255,255,255,.28);
  user-select:none;
}
.bestprofile-avatar.big{
  overflow:hidden;
  width: 40px;
  height: 40px;
  border-radius: 999px;
  font-size: 13px;
  margin-right: 10px;
  box-shadow:none;
}

.dropdown-bestprofile-menu{
  width:280px;
  max-width:92vw;
  padding: 0;
  overflow: hidden;
  border: 1px solid var(--msb-dd-border);
  box-shadow: var(--msb-dd-shadow);
  min-width: 280px;
  background:var(--msb-dd-surface);
  color:var(--msb-dd-text);
}

.bestprofile-top{
  display:flex;
  align-items:center;
  gap: 10px;
  padding: 12px;
  background: var(--msb-dd-head-bg);
  color:var(--msb-dd-head-text);
}
.bestprofile-meta{ min-width:0; }
.bestprofile-name{
  font-weight: 700;
  font-size: 13px;
  line-height: 1.2;
  white-space:nowrap;
  overflow:hidden;
  text-overflow:ellipsis;
}
.bestprofile-badge{
  margin-top:2px;
  font-size:12px;
  font-weight:600;
  line-height:1.2;
  color:var(--msb-dd-head-text, #101828);
}
.bestprofile-email{
  margin-top: 4px;
  font-size: 12px;
  opacity: .85;
  white-space:nowrap;
  overflow:hidden;
  text-overflow:ellipsis;
}
.bestprofile-code{
  margin-top: 4px;
  font-size: 12px;
  opacity: .85;
}
.bestprofile-divider{
  height: 1px;
  background: var(--msb-dd-divider);
}
.bestprofile-nav{
  background:var(--msb-dd-surface);
  padding: 4px 0;
}
.bestprofile-nav li + li{
  border-top:1px solid var(--msb-dd-divider);
}
.bestprofile-nav li a{
  display:flex;
  align-items:center;
  gap: 8px;
  min-height:34px;
  padding: 8px 12px;
  color:var(--msb-dd-text);
  font-weight:500;
  font-size:13px;
}
.bestprofile-nav li a:hover{
  background: var(--msb-dd-hover);
  color:var(--msb-dd-text);
}
.bestprofile-nav li a .icon{
  width:14px;
  min-width:14px;
  text-align:center;
  font-size:13px;
  line-height:1;
}
.msb-reaction-glyph{
  display:inline-flex !important;
  align-items:center;
  justify-content:center;
  width:16px;
  height:16px;
  min-width:16px;
  min-height:16px;
  flex:0 0 16px;
  line-height:1 !important;
  font-style:normal !important;
  font-family:"Apple Color Emoji","Segoe UI Emoji","Noto Color Emoji","Segoe UI Symbol",sans-serif !important;
  font-size:16px !important;
  background:transparent !important;
  -webkit-mask:none !important;
  mask:none !important;
  text-shadow:none !important;
  filter:var(--msb-pact-contrast-filter, drop-shadow(0 0 1.35px rgba(255,255,255,.98)) drop-shadow(0 1px 2px rgba(0,0,0,.55)));
  vertical-align:middle;
}
.mf-act .msb-reaction-glyph,
.reel-act .msb-reaction-glyph,
.pv-act .msb-reaction-glyph,
.standard-text-btn .msb-reaction-glyph,
.standard-media-btn .msb-reaction-glyph,
.action-btn .msb-reaction-glyph,
.reel-inline-btn .msb-reaction-glyph,
.public-live-action-btn .msb-reaction-glyph,
.js-react-love .msb-reaction-glyph{
  display:inline-flex !important;
  visibility:visible !important;
  opacity:1 !important;
  color:inherit;
}
/* Selected clap uses emoji glyph; face reactions use custom icons below */
.has-rx-icon[data-selected-reaction]:not([data-selected-reaction=""]) .msb-reaction-glyph,
.has-rx-icon[data-selected-reaction="clap"] .msb-reaction-glyph{
  width:16px !important;
  height:16px !important;
  min-width:16px !important;
  min-height:16px !important;
  display:inline-flex !important;
  align-items:center !important;
  justify-content:center !important;
  border:0 !important;
  border-radius:0 !important;
  background:transparent !important;
  color:inherit !important;
  font-size:16px !important;
  filter:var(--msb-pact-contrast-filter, drop-shadow(0 0 1.35px rgba(255,255,255,.98)) drop-shadow(0 1px 2px rgba(0,0,0,.55))) !important;
  box-shadow:none !important;
}
/* Face reaction icons (smile / laugh / wow / sad / angry) — img or CSS fallback */
.msb-rx-smile,
.msb-rx-laugh,
.msb-rx-wow,
.msb-rx-sad,
.msb-rx-angry,
img.msb-rx-face{
  width:16px !important;
  height:16px !important;
  min-width:16px !important;
  min-height:16px !important;
  display:inline-block !important;
  border:0 !important;
  border-radius:0 !important;
  -webkit-mask:none !important;
  mask:none !important;
  filter:var(--msb-pact-contrast-filter, drop-shadow(0 0 1.35px rgba(255,255,255,.98)) drop-shadow(0 1px 2px rgba(0,0,0,.55))) !important;
  box-shadow:none !important;
  object-fit:contain;
  vertical-align:middle;
}
img.msb-rx-face{
  font-size:0 !important;
  background:transparent !important;
  color:transparent !important;
}
.msb-rx-smile{
  background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'%3E%3Ccircle cx='12' cy='12' r='11' fill='%23FACC15'/%3E%3Cpath d='M7 10.6c.9-1.5 2.6-1.5 3.5 0' fill='none' stroke='%23111111' stroke-width='1.7' stroke-linecap='round'/%3E%3Cpath d='M13.5 10.6c.9-1.5 2.6-1.5 3.5 0' fill='none' stroke='%23111111' stroke-width='1.7' stroke-linecap='round'/%3E%3Cpath d='M8 14.2c1.35 2.5 6.65 2.5 8 0' fill='none' stroke='%23111111' stroke-width='1.75' stroke-linecap='round'/%3E%3C/svg%3E") !important;
  background-size:contain !important;
  background-repeat:no-repeat !important;
  background-position:center !important;
  font-size:0 !important;
  color:transparent !important;
}
.msb-rx-laugh{
  background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'%3E%3Ccircle cx='12' cy='12' r='11' fill='%23FACC15'/%3E%3Cpath d='M6.2 8.2l3.2 2.3-3.2 2.3' fill='none' stroke='%23111111' stroke-width='1.75' stroke-linecap='round' stroke-linejoin='round'/%3E%3Cpath d='M17.8 8.2l-3.2 2.3 3.2 2.3' fill='none' stroke='%23111111' stroke-width='1.75' stroke-linecap='round' stroke-linejoin='round'/%3E%3Cpath d='M7.4 13.8c.5 4.4 8.7 4.4 9.2 0-.3-1.15-2.3-1.95-4.6-1.95s-4.3.8-4.6 1.95z' fill='%23111111'/%3E%3Cellipse cx='12' cy='16.85' rx='3.1' ry='1.55' fill='%23EF4444'/%3E%3C/svg%3E") !important;
  background-size:contain !important;
  background-repeat:no-repeat !important;
  background-position:center !important;
  font-size:0 !important;
  color:transparent !important;
}
.msb-rx-wow{
  background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'%3E%3Ccircle cx='12' cy='12' r='11' fill='%23FACC15'/%3E%3Cpath d='M6.4 7.4c1.1-1.35 3-1.35 4.1 0' fill='none' stroke='%23111111' stroke-width='1.45' stroke-linecap='round'/%3E%3Cpath d='M13.5 7.4c1.1-1.35 3-1.35 4.1 0' fill='none' stroke='%23111111' stroke-width='1.45' stroke-linecap='round'/%3E%3Ccircle cx='8.6' cy='10.7' r='1.45' fill='%23111111'/%3E%3Ccircle cx='15.4' cy='10.7' r='1.45' fill='%23111111'/%3E%3Cellipse cx='12' cy='16.2' rx='2.15' ry='2.85' fill='%23111111'/%3E%3C/svg%3E") !important;
  background-size:contain !important;
  background-repeat:no-repeat !important;
  background-position:center !important;
  font-size:0 !important;
  color:transparent !important;
}
.msb-rx-sad{
  background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'%3E%3Ccircle cx='12' cy='12' r='11' fill='%23FACC15'/%3E%3Cpath d='M6.3 8.1c1.2-1.2 3.2-.85 4.1.45' fill='none' stroke='%23111111' stroke-width='1.4' stroke-linecap='round'/%3E%3Cpath d='M13.6 8.55c.9-1.3 2.9-1.65 4.1-.45' fill='none' stroke='%23111111' stroke-width='1.4' stroke-linecap='round'/%3E%3Ccircle cx='8.7' cy='11' r='1.2' fill='%23111111'/%3E%3Ccircle cx='15.3' cy='11' r='1.2' fill='%23111111'/%3E%3Cpath d='M8.6 16.4c1.2-1.7 5.6-1.7 6.8 0' fill='none' stroke='%23111111' stroke-width='1.55' stroke-linecap='round'/%3E%3Cpath d='M16.4 14.8c1.35 1.1 1.55 2.85.15 3.85-1.55-.55-2.05-2.05-.15-3.85z' fill='%2360A5FA'/%3E%3C/svg%3E") !important;
  background-size:contain !important;
  background-repeat:no-repeat !important;
  background-position:center !important;
  font-size:0 !important;
  color:transparent !important;
}
.msb-rx-angry{
  background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'%3E%3Cdefs%3E%3ClinearGradient id='ag' x1='12' y1='1' x2='12' y2='23' gradientUnits='userSpaceOnUse'%3E%3Cstop stop-color='%23DC2626'/%3E%3Cstop offset='1' stop-color='%23FBBF24'/%3E%3C/linearGradient%3E%3C/defs%3E%3Ccircle cx='12' cy='12' r='11' fill='url(%23ag)'/%3E%3Cpath d='M5.8 8.8l4.4 1.7' fill='none' stroke='%23111111' stroke-width='1.85' stroke-linecap='round'/%3E%3Cpath d='M18.2 8.8l-4.4 1.7' fill='none' stroke='%23111111' stroke-width='1.85' stroke-linecap='round'/%3E%3Ccircle cx='8.6' cy='11.6' r='1.15' fill='%23111111'/%3E%3Ccircle cx='15.4' cy='11.6' r='1.15' fill='%23111111'/%3E%3Cpath d='M9.4 16.3c.9-.7 4.3-.7 5.2 0' fill='none' stroke='%23111111' stroke-width='1.7' stroke-linecap='round'/%3E%3C/svg%3E") !important;
  background-size:contain !important;
  background-repeat:no-repeat !important;
  background-position:center !important;
  font-size:0 !important;
  color:transparent !important;
}
.has-rx-icon[data-selected-reaction="smile"] .msb-reaction-glyph,
.has-rx-icon[data-selected-reaction="laugh"] .msb-reaction-glyph,
.has-rx-icon[data-selected-reaction="wow"] .msb-reaction-glyph,
.has-rx-icon[data-selected-reaction="sad"] .msb-reaction-glyph,
.has-rx-icon[data-selected-reaction="angry"] .msb-reaction-glyph{
  display:none !important;
}
/* Like selected: blue thumb icon only — no yellow emoji */
.has-rx-icon[data-selected-reaction="like"] .msb-pact-thumb,
.is-like .msb-pact-thumb,
.msb-pact-thumb.is-active{
  width:16px !important;
  height:16px !important;
  min-width:16px !important;
  min-height:16px !important;
  display:inline-block !important;
  border:0 !important;
  border-radius:0 !important;
  background:currentColor !important;
  color:var(--msb-rx-like, #2563eb) !important;
  -webkit-text-fill-color:var(--msb-rx-like, #2563eb) !important;
  -webkit-mask:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='%23000'%3E%3Cpath d='M14 9V5a3 3 0 0 0-3-3l-4 9v11h11.28a2 2 0 0 0 2-1.7l1.38-9a2 2 0 0 0-2-2.3H14zM7 22H4a2 2 0 0 1-2-2v-7a2 2 0 0 1 2-2h3'/%3E%3C/svg%3E") center / contain no-repeat !important;
  mask:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='%23000'%3E%3Cpath d='M14 9V5a3 3 0 0 0-3-3l-4 9v11h11.28a2 2 0 0 0 2-1.7l1.38-9a2 2 0 0 0-2-2.3H14zM7 22H4a2 2 0 0 1-2-2v-7a2 2 0 0 1 2-2h3'/%3E%3C/svg%3E") center / contain no-repeat !important;
  filter:var(--msb-pact-contrast-filter, drop-shadow(0 0 1.35px rgba(255,255,255,.98)) drop-shadow(0 1px 2px rgba(0,0,0,.55))) !important;
  box-shadow:none !important;
}
.has-rx-icon[data-selected-reaction="like"] .msb-reaction-glyph{
  display:none !important;
}
/* Dislike selected: dark grey thumb-down icon only — no yellow emoji */
.has-rx-icon[data-selected-reaction="dislike"] .msb-pact-thumb-down,
.msb-pact-thumb-down.is-active{
  width:16px !important;
  height:16px !important;
  min-width:16px !important;
  min-height:16px !important;
  display:inline-block !important;
  border:0 !important;
  border-radius:0 !important;
  background:currentColor !important;
  color:var(--msb-rx-dislike, #475569) !important;
  -webkit-text-fill-color:var(--msb-rx-dislike, #475569) !important;
  -webkit-mask:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='%23000'%3E%3Cpath d='M10 15v4a3 3 0 0 0 3 3l4-9V2H5.72a2 2 0 0 0-2 1.7l-1.38 9a2 2 0 0 0 2 2.3H10zm7-13h3a2 2 0 0 1 2 2v7a2 2 0 0 1-2 2h-3'/%3E%3C/svg%3E") center / contain no-repeat !important;
  mask:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='%23000'%3E%3Cpath d='M10 15v4a3 3 0 0 0 3 3l4-9V2H5.72a2 2 0 0 0-2 1.7l-1.38 9a2 2 0 0 0 2 2.3H10zm7-13h3a2 2 0 0 1 2 2v7a2 2 0 0 1-2 2h-3'/%3E%3C/svg%3E") center / contain no-repeat !important;
  filter:var(--msb-pact-contrast-filter, drop-shadow(0 0 1.35px rgba(255,255,255,.98)) drop-shadow(0 1px 2px rgba(0,0,0,.55))) !important;
  box-shadow:none !important;
}
.has-rx-icon[data-selected-reaction="dislike"] .msb-reaction-glyph{
  display:none !important;
}
/* Default love heart: icon only — no black circular disc */
.has-rx-icon[data-selected-reaction=""] .msb-pact-heart{
  width:16px !important;
  height:16px !important;
  min-width:16px !important;
  min-height:16px !important;
  display:inline-block !important;
  border:0 !important;
  border-radius:0 !important;
  background:currentColor !important;
  color:inherit !important;
  -webkit-mask:var(--msb-pact-mask) center / contain no-repeat !important;
  mask:var(--msb-pact-mask) center / contain no-repeat !important;
  filter:var(--msb-pact-contrast-filter, drop-shadow(0 0 1.35px rgba(255,255,255,.98)) drop-shadow(0 1px 2px rgba(0,0,0,.55))) !important;
  box-shadow:none !important;
}
.has-rx-icon[data-selected-reaction=""] .msb-pact-heart::before{
  content:none !important;
  display:none !important;
}
/* Love selected (popup heart): pink heart icon only — no red circle */
.has-rx-icon[data-selected-reaction="love"] .msb-pact-heart,
.is-love .msb-pact-heart,
.msb-pact-heart.is-active{
  width:16px !important;
  height:16px !important;
  min-width:16px !important;
  min-height:16px !important;
  display:inline-block !important;
  border:0 !important;
  border-radius:0 !important;
  background:currentColor !important;
  color:var(--msb-rx-love, #ff4d6d) !important;
  -webkit-text-fill-color:var(--msb-rx-love, #ff4d6d) !important;
  -webkit-mask:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='%23000'%3E%3Cpath d='M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z'/%3E%3C/svg%3E") center / contain no-repeat !important;
  mask:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='%23000'%3E%3Cpath d='M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z'/%3E%3C/svg%3E") center / contain no-repeat !important;
  filter:var(--msb-pact-contrast-filter, drop-shadow(0 0 1.35px rgba(255,255,255,.98)) drop-shadow(0 1px 2px rgba(0,0,0,.55))) !important;
  box-shadow:none !important;
}
.has-rx-icon[data-selected-reaction="love"] .msb-pact-heart::before,
.is-love .msb-pact-heart::before,
.msb-pact-heart.is-active::before{
  content:none !important;
  display:none !important;
}
.mf-act .fa.fa-thumbs-up,
.mf-act .fa.fa-thumbs-o-up,
.mf-act .fa.fa-thumbs-down,
.mf-act .fa.fa-thumbs-o-down,
.reel-act .fa.fa-thumbs-up,
.reel-act .fa.fa-thumbs-o-up,
.reel-act .fa.fa-thumbs-down,
.reel-act .fa.fa-thumbs-o-down,
.standard-text-btn .fa.fa-thumbs-up,
.standard-text-btn .fa.fa-thumbs-o-up,
.standard-text-btn .fa.fa-thumbs-down,
.standard-text-btn .fa.fa-thumbs-o-down,
.standard-media-btn .fa.fa-thumbs-up,
.standard-media-btn .fa.fa-thumbs-o-up,
.standard-media-btn .fa.fa-thumbs-down,
.standard-media-btn .fa.fa-thumbs-o-down{
  font-size:20px;
  line-height:1;
  filter:var(--msb-pact-contrast-filter, drop-shadow(0 0 1.35px rgba(255,255,255,.98)) drop-shadow(0 1px 2px rgba(0,0,0,.55)));
}
.msb-reaction-picker{
  position:fixed;
  left:0;
  top:0;
  z-index:12050;
  display:flex;
  align-items:center;
  gap:4px;
  padding:8px 10px;
  max-width:calc(100vw - 16px);
  overflow:visible;
  border:2px solid rgba(255,255,255,.1);
  border-radius:999px;
  background:rgba(28,30,34,.98);
  box-shadow:0 14px 38px rgba(0,0,0,.42), inset 0 1px 0 rgba(255,255,255,.07);
  backdrop-filter:blur(20px);
  -webkit-backdrop-filter:blur(20px);
  opacity:0;
  transform:translateY(10px) scale(.92);
  pointer-events:none;
  transition:opacity .16s ease, transform .16s ease;
}
/* Post viewer locks body with position:fixed — keep picker above the overlay stage. */
#pvOverlay.show .msb-reaction-picker,
body:has(#pvOverlay.show) .msb-reaction-picker{
  z-index:130000;
}
.msb-reaction-picker.is-open{
  opacity:1;
  transform:translateY(0) scale(1);
  pointer-events:auto;
}
.msb-reaction-picker[hidden]{
  display:none;
}
.msb-reaction-picker-item{
  width:38px;
  height:38px;
  border:0;
  border-radius:999px;
  background:transparent !important;
  color:#fff;
  display:inline-flex;
  align-items:center;
  justify-content:center;
  padding:0;
  cursor:pointer;
  position:relative;
  box-shadow:none !important;
  flex:0 0 auto;
  transition:transform .18s cubic-bezier(.2,.8,.2,1), filter .18s ease;
}
.msb-reaction-picker-item[data-reaction="like"],
.msb-reaction-picker-item[data-reaction="love"],
.msb-reaction-picker-item[data-reaction="dislike"],
.msb-reaction-picker-item[data-reaction="smile"],
.msb-reaction-picker-item[data-reaction="laugh"],
.msb-reaction-picker-item[data-reaction="clap"],
.msb-reaction-picker-item[data-reaction="wow"],
.msb-reaction-picker-item[data-reaction="sad"],
.msb-reaction-picker-item[data-reaction="angry"]{
  background:transparent !important;
  box-shadow:none !important;
}
.msb-reaction-picker-item:hover,
.msb-reaction-picker-item:focus-visible{
  transform:translateY(-7px) scale(1.14);
  box-shadow:none !important;
  filter:saturate(1.08) brightness(1.06);
  outline:none;
  background:transparent !important;
  z-index:2;
}
html.dark-auto .msb-reaction-picker-item:hover,
html.dark-auto .msb-reaction-picker-item:focus-visible,
html[data-theme="dark"] .msb-reaction-picker-item:hover,
html[data-theme="dark"] .msb-reaction-picker-item:focus-visible{
  box-shadow:none !important;
}
.msb-reaction-picker-item.is-selected{
  outline:2px solid #fff;
  outline-offset:2px;
  border-radius:999px;
  background:transparent !important;
  z-index:2;
}
.msb-reaction-picker-item[data-reaction="love"].is-selected,
.msb-reaction-picker-item[data-reaction="love"]:hover,
.msb-reaction-picker-item[data-reaction="love"]:focus-visible{
  background:transparent !important;
}
html.dark-auto .msb-reaction-picker-item.is-selected,
html[data-theme="dark"] .msb-reaction-picker-item.is-selected{
  outline-color:#fff;
}
.msb-reaction-picker-emoji{
  display:block;
  font-size:23px;
  line-height:1;
  transform:translateZ(0);
  filter:drop-shadow(0 1px 1px rgba(0,0,0,.16));
}
.msb-reaction-picker-face{
  width:24px !important;
  height:24px !important;
  min-width:24px !important;
  min-height:24px !important;
  display:inline-flex !important;
  align-items:center;
  justify-content:center;
  background:transparent !important;
  -webkit-mask:none !important;
  mask:none !important;
  filter:drop-shadow(0 1px 1px rgba(0,0,0,.2));
}
.msb-reaction-picker-face svg{
  width:24px;
  height:24px;
  display:block;
}
span.msb-rx-face{
  width:16px !important;
  height:16px !important;
  min-width:16px !important;
  min-height:16px !important;
  display:inline-flex !important;
  align-items:center;
  justify-content:center;
  background:transparent !important;
  -webkit-mask:none !important;
  mask:none !important;
}
span.msb-rx-face svg{
  width:16px;
  height:16px;
  display:block;
}
.msb-reaction-picker-item[data-reaction="like"] .msb-reaction-picker-emoji{
  width:24px;
  height:24px;
  font-size:0 !important;
  color:var(--msb-rx-like, #2563eb) !important;
  -webkit-text-fill-color:var(--msb-rx-like, #2563eb) !important;
  background:currentColor !important;
  -webkit-mask:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='%23000'%3E%3Cpath d='M14 9V5a3 3 0 0 0-3-3l-4 9v11h11.28a2 2 0 0 0 2-1.7l1.38-9a2 2 0 0 0-2-2.3H14zM7 22H4a2 2 0 0 1-2-2v-7a2 2 0 0 1 2-2h3'/%3E%3C/svg%3E") center / contain no-repeat !important;
  mask:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='%23000'%3E%3Cpath d='M14 9V5a3 3 0 0 0-3-3l-4 9v11h11.28a2 2 0 0 0 2-1.7l1.38-9a2 2 0 0 0-2-2.3H14zM7 22H4a2 2 0 0 1-2-2v-7a2 2 0 0 1 2-2h3'/%3E%3C/svg%3E") center / contain no-repeat !important;
  filter:drop-shadow(0 1px 1px rgba(0,0,0,.2));
}
.msb-reaction-picker-item[data-reaction="dislike"] .msb-reaction-picker-emoji{
  width:24px;
  height:24px;
  font-size:0 !important;
  color:var(--msb-rx-dislike, #475569) !important;
  -webkit-text-fill-color:var(--msb-rx-dislike, #475569) !important;
  background:currentColor !important;
  -webkit-mask:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='%23000'%3E%3Cpath d='M10 15v4a3 3 0 0 0 3 3l4-9V2H5.72a2 2 0 0 0-2 1.7l-1.38 9a2 2 0 0 0 2 2.3H10zm7-13h3a2 2 0 0 1 2 2v7a2 2 0 0 1-2 2h-3'/%3E%3C/svg%3E") center / contain no-repeat !important;
  mask:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='%23000'%3E%3Cpath d='M10 15v4a3 3 0 0 0 3 3l4-9V2H5.72a2 2 0 0 0-2 1.7l-1.38 9a2 2 0 0 0 2 2.3H10zm7-13h3a2 2 0 0 1 2 2v7a2 2 0 0 1-2 2h-3'/%3E%3C/svg%3E") center / contain no-repeat !important;
  filter:drop-shadow(0 1px 1px rgba(0,0,0,.2));
}
.msb-reaction-picker-item[data-reaction="smile"] .msb-reaction-picker-emoji,
.msb-reaction-picker-item[data-reaction="laugh"] .msb-reaction-picker-emoji,
.msb-reaction-picker-item[data-reaction="wow"] .msb-reaction-picker-emoji,
.msb-reaction-picker-item[data-reaction="sad"] .msb-reaction-picker-emoji,
.msb-reaction-picker-item[data-reaction="angry"] .msb-reaction-picker-emoji{
  width:24px;
  height:24px;
  font-size:0 !important;
  background:transparent !important;
  -webkit-mask:none !important;
  mask:none !important;
  filter:drop-shadow(0 1px 1px rgba(0,0,0,.2));
}
.msb-reaction-picker-item[data-reaction="love"] .msb-reaction-picker-emoji{
  color:var(--msb-rx-love, #ff4d6d) !important;
  -webkit-text-fill-color:var(--msb-rx-love, #ff4d6d) !important;
  font-family:Arial,sans-serif;
  font-size:27px;
  font-weight:900;
  text-shadow:0 1px 2px rgba(0,0,0,.22) !important;
}
.msb-reaction-picker-label{
  position:absolute;
  left:50%;
  bottom:calc(100% + 10px);
  transform:translate(-50%, 6px) scale(.92);
  white-space:nowrap;
  padding:5px 9px;
  border-radius:999px;
  background:#050505;
  color:#fff !important;
  -webkit-text-fill-color:#fff !important;
  text-shadow:0 1px 2px rgba(0,0,0,.7) !important;
  font-size:12px;
  font-weight:800;
  letter-spacing:.01em;
  line-height:1;
  opacity:0;
  pointer-events:none;
  box-shadow:0 6px 16px rgba(0,0,0,.35);
  transition:opacity .16s ease, transform .16s ease;
  z-index:5;
  display:block;
  max-width:none;
  overflow:visible;
  text-overflow:clip;
  text-align:center;
}
.msb-reaction-picker-item:hover .msb-reaction-picker-label,
.msb-reaction-picker-item:focus-visible .msb-reaction-picker-label,
.msb-reaction-picker-item.is-selected .msb-reaction-picker-label{
  opacity:1;
  transform:translate(-50%, 0) scale(1);
}
@media (max-width:575.98px){
  .msb-reaction-picker{gap:3px;padding:6px 8px;}
  .msb-reaction-picker-item{width:34px;height:34px;}
  .msb-reaction-picker-emoji{font-size:21px;}
  .msb-reaction-picker-label{font-size:11px;padding:4px 8px;bottom:calc(100% + 8px);}
  .msb-reaction-picker-item[data-reaction="like"] .msb-reaction-picker-emoji{width:22px;height:22px;}
  .msb-reaction-picker-item[data-reaction="dislike"] .msb-reaction-picker-emoji{width:22px;height:22px;}
  .msb-reaction-picker-item[data-reaction="smile"] .msb-reaction-picker-emoji,
  .msb-reaction-picker-item[data-reaction="laugh"] .msb-reaction-picker-emoji,
  .msb-reaction-picker-item[data-reaction="wow"] .msb-reaction-picker-emoji,
  .msb-reaction-picker-item[data-reaction="sad"] .msb-reaction-picker-emoji,
  .msb-reaction-picker-item[data-reaction="angry"] .msb-reaction-picker-emoji{width:22px;height:22px;}
  .msb-reaction-picker-face{width:22px !important;height:22px !important;min-width:22px !important;min-height:22px !important;}
  .msb-reaction-picker-face svg{width:22px;height:22px;}
  .msb-reaction-picker-item[data-reaction="love"] .msb-reaction-picker-emoji{font-size:25px;}
}
</style>

<script>
(function(){
  // Force fresh reaction-picker build (bust stale cached picker DOM)
  try { if (window.MSBReactions && window.MSBReactions.__pickerVersion !== 'rx9-faces-v5') { window.MSBReactions = null; } } catch (eKill) {}
  if (window.MSBReactions) return;

  const PICKER_VERSION = 'rx9-faces-v5';
  /* Inline SVG faces — no external files needed (works on Hostinger without asset upload) */
  const faceSvg = {
    smile: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" aria-hidden="true"><circle cx="12" cy="12" r="11" fill="#FACC15"/><path d="M7 10.6c.9-1.5 2.6-1.5 3.5 0" fill="none" stroke="#111" stroke-width="1.7" stroke-linecap="round"/><path d="M13.5 10.6c.9-1.5 2.6-1.5 3.5 0" fill="none" stroke="#111" stroke-width="1.7" stroke-linecap="round"/><path d="M8 14.2c1.35 2.5 6.65 2.5 8 0" fill="none" stroke="#111" stroke-width="1.75" stroke-linecap="round"/></svg>',
    laugh: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" aria-hidden="true"><circle cx="12" cy="12" r="11" fill="#FACC15"/><path d="M6.2 8.2l3.2 2.3-3.2 2.3" fill="none" stroke="#111" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"/><path d="M17.8 8.2l-3.2 2.3 3.2 2.3" fill="none" stroke="#111" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"/><path d="M7.4 13.8c.5 4.4 8.7 4.4 9.2 0-.3-1.15-2.3-1.95-4.6-1.95s-4.3.8-4.6 1.95z" fill="#111"/><ellipse cx="12" cy="16.85" rx="3.1" ry="1.55" fill="#EF4444"/></svg>',
    wow: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" aria-hidden="true"><circle cx="12" cy="12" r="11" fill="#FACC15"/><path d="M6.4 7.4c1.1-1.35 3-1.35 4.1 0" fill="none" stroke="#111" stroke-width="1.45" stroke-linecap="round"/><path d="M13.5 7.4c1.1-1.35 3-1.35 4.1 0" fill="none" stroke="#111" stroke-width="1.45" stroke-linecap="round"/><circle cx="8.6" cy="10.7" r="1.45" fill="#111"/><circle cx="15.4" cy="10.7" r="1.45" fill="#111"/><ellipse cx="12" cy="16.2" rx="2.15" ry="2.85" fill="#111"/></svg>',
    sad: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" aria-hidden="true"><circle cx="12" cy="12" r="11" fill="#FACC15"/><path d="M6.3 8.1c1.2-1.2 3.2-.85 4.1.45" fill="none" stroke="#111" stroke-width="1.4" stroke-linecap="round"/><path d="M13.6 8.55c.9-1.3 2.9-1.65 4.1-.45" fill="none" stroke="#111" stroke-width="1.4" stroke-linecap="round"/><circle cx="8.7" cy="11" r="1.2" fill="#111"/><circle cx="15.3" cy="11" r="1.2" fill="#111"/><path d="M8.6 16.4c1.2-1.7 5.6-1.7 6.8 0" fill="none" stroke="#111" stroke-width="1.55" stroke-linecap="round"/><path d="M16.4 14.8c1.35 1.1 1.55 2.85.15 3.85-1.55-.55-2.05-2.05-.15-3.85z" fill="#60A5FA"/></svg>',
    angry: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" aria-hidden="true"><defs><linearGradient id="msbAg" x1="12" y1="1" x2="12" y2="23" gradientUnits="userSpaceOnUse"><stop stop-color="#DC2626"/><stop offset="1" stop-color="#FBBF24"/></linearGradient></defs><circle cx="12" cy="12" r="11" fill="url(#msbAg)"/><path d="M5.8 8.8l4.4 1.7" fill="none" stroke="#111" stroke-width="1.85" stroke-linecap="round"/><path d="M18.2 8.8l-4.4 1.7" fill="none" stroke="#111" stroke-width="1.85" stroke-linecap="round"/><circle cx="8.6" cy="11.6" r="1.15" fill="#111"/><circle cx="15.4" cy="11.6" r="1.15" fill="#111"/><path d="M9.4 16.3c.9-.7 4.3-.7 5.2 0" fill="none" stroke="#111" stroke-width="1.7" stroke-linecap="round"/></svg>'
  };

  const defs = {
    love:    { key:'love',    label:'Love',    emoji:'❤️', color:'#ff4d6d' },
    like:    { key:'like',    label:'Like',    emoji:'👍', color:'#2563eb' },
    dislike: { key:'dislike', label:'Dislike', emoji:'👎', color:'#475569' },
    smile:   { key:'smile',   label:'Smile',   emoji:'☺', color:'#FACC15' },
    laugh:   { key:'laugh',   label:'Laugh',   emoji:'😂', color:'#f97316' },
    clap:    { key:'clap',    label:'Clap',    emoji:'👏', color:'#eab308' },
    wow:     { key:'wow',     label:'Wow',     emoji:'😮', color:'#facc15' },
    sad:     { key:'sad',     label:'Sad',     emoji:'😢', color:'#60a5fa' },
    angry:   { key:'angry',   label:'Angry',   emoji:'😡', color:'#ef4444' }
  };
  const pickerOrder = ['like', 'love', 'dislike', 'smile', 'laugh', 'wow', 'sad', 'angry', 'clap'];
  const delegates = [];
  const suppressClickUntil = new WeakMap();
  let picker = null;
  let pickerAnchor = null;
  let pickerSelect = null;
  let holdTimer = 0;
  let holdMatch = null;
  let holdStartX = 0;
  let holdStartY = 0;

  function normalize(reaction){
    const key = String(reaction || '').trim().toLowerCase();
    if (key === 'none' || key === 'unlike' || key === 'remove') return 'none';
    return Object.prototype.hasOwnProperty.call(defs, key) ? key : '';
  }

  function isLikeLane(reaction){
    const key = normalize(reaction);
    return key !== '' && key !== 'none' && key !== 'love';
  }

  function likeDisplayReaction(reaction){
    const key = normalize(reaction);
    if (!key || key === 'none' || key === 'love') return 'like';
    return key;
  }

  function label(reaction){
    const key = normalize(reaction);
    if (!key || key === 'none') return 'Love';
    return defs[key].label;
  }

  function emoji(reaction){
    const key = normalize(reaction);
    if (!key || key === 'none') return defs.love.emoji;
    return defs[key].emoji;
  }

  function ensureIcon(button){
    if (!button || !button.querySelector) return null;
    let icon = button.querySelector('.msb-reaction-glyph, i.msb-pact, i.msb-reaction-host, .msb-pact, i.fa');
    if (icon) return icon;
    const svg = button.querySelector('svg.pv-ico, svg');
    if (svg && svg.parentNode === button) {
      icon = document.createElement('span');
      icon.className = 'msb-reaction-host';
      icon.setAttribute('aria-hidden', 'true');
      svg.parentNode.replaceChild(icon, svg);
      return icon;
    }
    icon = button.querySelector('i');
    if (icon) return icon;
    icon = document.createElement('span');
    icon.className = 'msb-reaction-host';
    icon.setAttribute('aria-hidden', 'true');
    const firstCount = button.querySelector('span.mf-num, span.reel-act-count, span.action-count, span.pv-n, [data-count]');
    if (firstCount && firstCount.parentNode === button) button.insertBefore(icon, firstCount);
    else button.insertBefore(icon, button.firstChild || null);
    return icon;
  }

  function clearReactionHosts(button, keepNode){
    if (!button) return;
    Array.prototype.slice.call(button.children).forEach(function(node){
      if (keepNode && node === keepNode) return;
      if (!node || !node.matches) return;
      if (node.matches('svg.pv-ico, svg, i, img.msb-rx-face, span.msb-rx-face, .msb-pact, .msb-reaction-glyph, .msb-reaction-host, .msb-rx-smile, .msb-rx-laugh, .msb-rx-wow, .msb-rx-sad, .msb-rx-angry')) {
        try { node.remove(); } catch (e) { if (node.parentNode) node.parentNode.removeChild(node); }
      }
    });
  }

  function paintIcon(icon, reaction, active, outline){
    // Back-compat no-op path; applyReactionVisual owns the DOM swap.
    if (!icon) return;
    const button = icon.closest ? icon.closest('button, a, .mf-act, .pv-act, .reel-act, .js-react-love, .standard-text-btn, .standard-media-btn, .action-btn, .reel-inline-btn, .public-live-action-btn') : null;
    if (button) applyReactionVisual(button, reaction, active);
  }

  function applyReactionVisual(button, reaction, active){
    if (!button) return null;
    const key = normalize(reaction);
    const show = (!key || key === 'none') ? 'love' : key;
    const on = !!active && show !== '';
    button.classList.add('has-rx-icon');
    button.setAttribute('data-rx', on ? show : (show === 'love' ? 'love' : show));

    const countEl = button.querySelector('span.mf-num, span.reel-act-count, span.action-count, span.pv-n, [data-count], .mf-num, .action-count, .pv-n');
    clearReactionHosts(button, null);

    let el;
    if (show === 'love') {
      el = document.createElement('i');
      el.className = 'msb-pact msb-pact-heart' + (on ? ' is-active' : '');
      el.setAttribute('aria-hidden', 'true');
      if (on) {
        el.style.setProperty('color', defs.love.color, 'important');
        el.style.setProperty('-webkit-text-fill-color', defs.love.color, 'important');
      }
    } else if (show === 'like') {
      el = document.createElement('i');
      el.className = 'msb-pact msb-pact-thumb' + (on ? ' is-active' : '');
      el.setAttribute('aria-hidden', 'true');
      if (on) {
        el.style.setProperty('color', defs.like.color, 'important');
        el.style.setProperty('-webkit-text-fill-color', defs.like.color, 'important');
      }
    } else if (show === 'dislike') {
      el = document.createElement('i');
      el.className = 'msb-pact msb-pact-thumb-down' + (on ? ' is-active' : '');
      el.setAttribute('aria-hidden', 'true');
      if (on) {
        el.style.setProperty('color', defs.dislike.color, 'important');
        el.style.setProperty('-webkit-text-fill-color', defs.dislike.color, 'important');
      }
    } else if (show === 'smile' || show === 'laugh' || show === 'wow' || show === 'sad' || show === 'angry') {
      el = document.createElement('span');
      el.className = 'msb-rx-face msb-rx-' + show + (on ? ' is-active' : '');
      el.setAttribute('aria-hidden', 'true');
      el.innerHTML = faceSvg[show] || '';
    } else {
      // Use <span>, not <i> — icon fonts often hide/replace text on <i>
      el = document.createElement('span');
      el.className = 'msb-reaction-glyph';
      el.setAttribute('aria-hidden', 'true');
      el.textContent = defs[show] ? defs[show].emoji : '👍';
      if (on && defs[show]) {
        el.style.setProperty('color', defs[show].color, 'important');
        el.style.setProperty('-webkit-text-fill-color', defs[show].color, 'important');
      }
    }

    if (countEl && countEl.parentNode === button) button.insertBefore(el, countEl);
    else button.insertBefore(el, button.firstChild || null);
    return el;
  }

  function applyLikeButton(button, reaction){
    if (!button) return;
    const key = normalize(reaction);
    const display = likeDisplayReaction(key === 'none' ? '' : key);
    const active = isLikeLane(key);
    applyReactionVisual(button, display, active);
    button.classList.toggle('is-like', active);
    button.classList.toggle('is-love', false);
    button.classList.toggle('is-reacted', active);
    button.setAttribute('data-selected-reaction', key === 'none' ? '' : key);
    button.setAttribute('data-reaction-display', display);
    button.setAttribute('title', active ? defs[display].label : 'Like');
    button.setAttribute('aria-label', active ? defs[display].label : 'Like');
  }

  function applyReactionButton(button, reaction, fallbackReaction){
    if (!button) return;
    const fallback = normalize(fallbackReaction) || 'love';
    const key = normalize(reaction);
    const active = !!key && key !== 'none';
    const display = active ? key : (fallback === 'none' ? 'love' : fallback);
    applyReactionVisual(button, display, active);
    button.classList.toggle('is-love', key === 'love');
    button.classList.toggle('is-reacted', active && key !== 'love');
    button.classList.toggle('is-like', key === 'like');
    button.setAttribute('data-selected-reaction', active ? key : '');
    button.setAttribute('data-reaction-display', display);
    button.setAttribute('title', active ? defs[display].label : defs[fallback === 'none' ? 'love' : fallback].label);
    button.setAttribute('aria-label', active ? defs[display].label : defs[fallback === 'none' ? 'love' : fallback].label);
    const labelNode = button.querySelector('[data-reaction-label]');
    if (labelNode) labelNode.textContent = active ? defs[display].label : defs[fallback === 'none' ? 'love' : fallback].label;
  }

  function applyLoveButton(button, reaction){
    applyReactionButton(button, reaction, 'love');
  }

  function buildPicker(){
    // Rebuild if an older picker (without face icons) is still in the DOM
    if (picker && picker.getAttribute('data-picker-version') !== PICKER_VERSION) {
      try { picker.remove(); } catch (eRem) {}
      picker = null;
    }
    if (picker) return picker;
    try {
      document.querySelectorAll('.msb-reaction-picker').forEach(function(node){
        if (node.getAttribute('data-picker-version') !== PICKER_VERSION) {
          try { node.remove(); } catch (eOld) {}
        }
      });
    } catch (eQ) {}
    picker = document.createElement('div');
    picker.className = 'msb-reaction-picker';
    picker.hidden = true;
    picker.setAttribute('role', 'dialog');
    picker.setAttribute('aria-label', 'Choose a reaction');
    picker.setAttribute('data-picker-version', PICKER_VERSION);
    picker.innerHTML = pickerOrder.map(function(key){
      var inner;
      if (faceSvg[key]) {
        inner = '<span class="msb-reaction-picker-emoji msb-reaction-picker-face" aria-hidden="true">' + faceSvg[key] + '</span>';
      } else if (key === 'love') {
        inner = '<span class="msb-reaction-picker-emoji" aria-hidden="true">&#9829;</span>';
      } else if (key === 'like' || key === 'dislike') {
        inner = '<span class="msb-reaction-picker-emoji" aria-hidden="true"></span>';
      } else {
        inner = '<span class="msb-reaction-picker-emoji" aria-hidden="true">' + defs[key].emoji + '</span>';
      }
      return '' +
        '<button type="button" class="msb-reaction-picker-item" data-reaction="' + key + '" aria-label="' + defs[key].label + '">' +
          inner +
          '<span class="msb-reaction-picker-label">' + defs[key].label + '</span>' +
        '</button>';
    }).join('');
    picker.addEventListener('click', function(e){
      const item = e.target.closest('.msb-reaction-picker-item[data-reaction]');
      if (!item || !pickerSelect || !pickerAnchor) return;
      e.preventDefault();
      e.stopPropagation();
      if (typeof e.stopImmediatePropagation === 'function') e.stopImmediatePropagation();
      const reaction = normalize(item.getAttribute('data-reaction') || '');
      if (!reaction || reaction === 'none') return;
      const current = normalize(pickerAnchor.getAttribute('data-selected-reaction') || '');
      const anchor = pickerAnchor;
      // Block fall-through clicks on the love button after picking from the modal
      suppressClickUntil.set(anchor, Date.now() + 900);
      // Re-selecting the same reaction removes it
      pickerSelect(anchor, reaction === current ? 'none' : reaction);
      closePicker();
    });
    mountPickerHost();
    return picker;
  }

  function mountPickerHost(){
    if (!picker) return;
    var ov = document.getElementById('pvOverlay');
    // Body is position:fixed while the post viewer is open — mount on <html>
    // so fixed positioning stays viewport-relative and the tray stays visible.
    var host = (ov && ov.classList.contains('show')) ? document.documentElement : document.body;
    if (picker.parentNode !== host) {
      try { host.appendChild(picker); } catch (eMount) {
        try { document.body.appendChild(picker); } catch (eBody) {}
      }
    }
  }

  function positionPicker(anchor){
    if (!picker || !anchor || picker.hidden) return;
    mountPickerHost();
    const rect = anchor.getBoundingClientRect();
    const pickerRect = picker.getBoundingClientRect();
    const margin = 12;
    let left = rect.left + (rect.width / 2) - (pickerRect.width / 2);
    left = Math.max(margin, Math.min(window.innerWidth - pickerRect.width - margin, left));

    let top = rect.top - pickerRect.height - 14;
    if (top < margin) top = rect.bottom + 14;
    top = Math.max(margin, Math.min(window.innerHeight - pickerRect.height - margin, top));

    picker.style.left = left + 'px';
    picker.style.top = top + 'px';
  }

  function renderSelection(reaction){
    if (!picker) return;
    const current = normalize(reaction);
    picker.querySelectorAll('.msb-reaction-picker-item').forEach(function(item){
      item.classList.toggle('is-selected', normalize(item.getAttribute('data-reaction') || '') === current);
    });
  }

  function openPicker(anchor, onSelect){
    if (!anchor || typeof onSelect !== 'function') return;
    buildPicker();
    mountPickerHost();
    pickerAnchor = anchor;
    pickerSelect = onSelect;
    renderSelection(anchor.getAttribute('data-selected-reaction') || '');
    picker.hidden = false;
    picker.classList.remove('is-open');
    positionPicker(anchor);
    window.requestAnimationFrame(function(){
      if (picker) picker.classList.add('is-open');
    });
  }

  function openPickerFor(anchor){
    if (!anchor || !anchor.closest) return false;
    const match = matchDelegate(anchor) || matchDelegate(anchor.querySelector ? (anchor.querySelector('.msb-pact, .msb-reaction-glyph, i, svg') || anchor) : anchor);
    if (!match) return false;
    openPicker(match.element, match.delegate.onSelect);
    return true;
  }

  function closePicker(){
    if (!picker) return;
    picker.classList.remove('is-open');
    picker.hidden = true;
    pickerAnchor = null;
    pickerSelect = null;
  }

  function clearHold(){
    if (holdTimer) window.clearTimeout(holdTimer);
    holdTimer = 0;
    holdMatch = null;
  }

  function matchDelegate(target){
    if (!target || !target.closest) return null;
    for (let i = 0; i < delegates.length; i += 1) {
      const delegate = delegates[i];
      let element = target.closest(delegate.selector);
      if (!element) continue;
      // Count spans must never be the picker anchor
      if (element.classList && element.classList.contains('mf-num')) {
        const act = element.closest('.mf-act, [data-post-id], button, a');
        if (act) element = act;
      }
      return { delegate: delegate, element: element };
    }
    return null;
  }

  function bindLikePicker(selector, onSelect){
    if (!selector || typeof onSelect !== 'function') return;
    delegates.push({ selector: selector, onSelect: onSelect });
  }

  document.addEventListener('pointerdown', function(e){
    if (e.button && e.button !== 0) return;
    const match = matchDelegate(e.target);
    if (!match) return;
    clearHold();
    holdMatch = match;
    holdStartX = Number(e.clientX || 0);
    holdStartY = Number(e.clientY || 0);
    holdTimer = window.setTimeout(function(){
      openPicker(match.element, match.delegate.onSelect);
      holdTimer = 0;
    }, 320);
  }, true);

  document.addEventListener('pointermove', function(e){
    if (!holdTimer) return;
    const dx = Math.abs(Number(e.clientX || 0) - holdStartX);
    const dy = Math.abs(Number(e.clientY || 0) - holdStartY);
    if (dx > 10 || dy > 10) clearHold();
  }, true);

  document.addEventListener('pointerup', function(){
    if (picker && !picker.hidden && pickerAnchor) {
      suppressClickUntil.set(pickerAnchor, Date.now() + 600);
    }
    clearHold();
  }, true);
  document.addEventListener('pointercancel', clearHold, true);

  document.addEventListener('contextmenu', function(e){
    const match = matchDelegate(e.target);
    if (!match) return;
    e.preventDefault();
    clearHold();
    suppressClickUntil.set(match.element, Date.now() + 900);
    openPicker(match.element, match.delegate.onSelect);
  }, true);

  document.addEventListener('click', function(e){
    const match = matchDelegate(e.target);
    if (match) {
      const until = Number(suppressClickUntil.get(match.element) || 0);
      if (until > Date.now()) {
        e.preventDefault();
        e.stopPropagation();
        if (typeof e.stopImmediatePropagation === 'function') e.stopImmediatePropagation();
        return;
      }
      // Press love/react → open picker (select love / thumbs / smile / etc.)
      e.preventDefault();
      e.stopPropagation();
      if (typeof e.stopImmediatePropagation === 'function') e.stopImmediatePropagation();
      clearHold();
      openPicker(match.element, match.delegate.onSelect);
      return;
    }
    if (picker && !picker.hidden && !e.target.closest('.msb-reaction-picker')) closePicker();
  }, true);

  document.addEventListener('keydown', function(e){
    if (e.key === 'Escape') closePicker();
  });

  document.addEventListener('scroll', function(){
    clearHold();
    if (picker && !picker.hidden) positionPicker(pickerAnchor);
  }, true);

  window.addEventListener('resize', function(){
    if (picker && !picker.hidden) positionPicker(pickerAnchor);
  });

  window.MSBReactions = {
    defs: defs,
    __pickerVersion: PICKER_VERSION,
    normalize: normalize,
    label: label,
    emoji: emoji,
    isLikeLane: isLikeLane,
    likeDisplayReaction: likeDisplayReaction,
    applyLikeButton: applyLikeButton,
    applyReactionButton: applyReactionButton,
    applyLoveButton: applyLoveButton,
    bindLikePicker: bindLikePicker,
    openPicker: openPicker,
    openPickerFor: openPickerFor,
    closePicker: closePicker
  };
})();
</script>

<script>
(function () {
  const createPostTrigger = document.getElementById('headerCreatePostTrigger');
  const createPostModal = document.getElementById('createPostModal');
  const createPostModalFrame = document.getElementById('createPostModalFrame');
  const createPostModalClose = document.getElementById('createPostModalClose');
  const createPostReadonlyNotice = document.getElementById('createPostReadonlyNotice');
  const createPostReadonlyText = document.getElementById('createPostReadonlyText');
  const createPostReadonlyClose = document.getElementById('createPostReadonlyClose');
  const STAFF_READONLY = <?= $headerStaffReadonly ? 'true' : 'false' ?>;
  const STAFF_ROLE_LABEL = <?= json_encode($headerStaffRoleLabel, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
  const notifDot = document.getElementById('headerNotificationDot');
  const notifBadge = document.getElementById('headerNotificationBadge');
  const notifTopBadge = document.getElementById('headerNotificationTopBadge');
  const notifSquare = document.getElementById('headerNotificationSquare');
  const notifSub = document.getElementById('headerNotificationSub');
  const notifSubTop = document.getElementById('headerNotificationSubTop');
  const notifMarkAll = document.getElementById('headerNotificationMarkAll');
  const notifMarkAllTop = document.getElementById('headerNotificationMarkAllTop');
  const notifItems = () => Array.from(document.querySelectorAll('.dropdown-bestnoti-item[data-notification-id]'));
  let latestNotificationItems = Array.isArray(window.__MSB_HEADER_NOTIFICATIONS)
    ? window.__MSB_HEADER_NOTIFICATIONS.slice()
    : [];
  let notificationDoorTab = 'all';

  function notificationEsc(s){
    return String(s || '').replace(/[&<>"']/g, function(m){
      return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]);
    });
  }

  function notificationInitials(nameOrCode){
    const t = String(nameOrCode || '').trim();
    if(!t) return '??';
    const parts = t.split(/\s+/).filter(Boolean);
    if(parts.length === 1) return parts[0].substring(0,2).toUpperCase();
    return (parts[0].substring(0,1) + parts[parts.length-1].substring(0,1)).toUpperCase();
  }

  function notificationTimeLabel(value){
    const raw = String(value || '').trim();
    if (!raw) return '';
    const parsed = new Date(raw.replace(' ', 'T'));
    if (Number.isNaN(parsed.getTime())) return raw;
    try {
      return parsed.toLocaleString([], {
        month: 'short',
        day: '2-digit',
        hour: 'numeric',
        minute: '2-digit'
      });
    } catch (eTime) {
      return raw;
    }
  }

  function notificationListTargets(){
    const nodes = [];
    const seen = {};
    [
      document.getElementById('headerNotificationList'),
      document.getElementById('headerNotificationListTop')
    ].forEach(function(node){
      if(!node || seen[node.id]) return;
      seen[node.id] = true;
      nodes.push(node);
    });
    document.querySelectorAll('[data-noti-door-list]').forEach(function(node){
      const key = node.id || ('anon-' + nodes.length);
      if(seen[key]) return;
      seen[key] = true;
      nodes.push(node);
    });
    return nodes;
  }

  function hydrateNotificationsFromDom(){
    const items = [];
    const seen = {};
    document.querySelectorAll('#headerNotificationList .dropdown-bestnoti-item[data-notification-id], [data-noti-door-list] .dropdown-bestnoti-item[data-notification-id]').forEach((node) => {
      const id = parseInt(node.getAttribute('data-notification-id') || '0', 10) || 0;
      if (id > 0 && seen[id]) return;
      if (id > 0) seen[id] = true;
      const textNode = node.querySelector('.bestnoti-text');
      let sender = 'Someone';
      let text = 'sent a notification';
      if (textNode) {
        const strong = textNode.querySelector('strong');
        sender = strong ? String(strong.textContent || '').trim() || sender : sender;
        const full = String(textNode.textContent || '').trim();
        text = strong ? full.replace(strong.textContent || '', '').trim() || text : full || text;
      }
      items.push({
        id: id,
        sender: sender,
        text: text,
        live_id: parseInt(node.getAttribute('data-live-id') || '0', 10) || 0,
        post_id: parseInt(node.getAttribute('data-post-id') || '0', 10) || 0,
        is_story: parseInt(node.getAttribute('data-is-story') || '0', 10) === 1 ? 1 : 0,
        url: String(node.getAttribute('data-notification-url') || node.getAttribute('href') || ''),
        created_at: '',
        is_read: node.classList.contains('is-unread') ? 0 : 1
      });
    });
    if (items.length && !latestNotificationItems.length) {
      latestNotificationItems = items;
    }
  }
  hydrateNotificationsFromDom();

  function isMentionNotificationText(text){
    const t = String(text || '').toLowerCase();
    return t.indexOf('mention') !== -1 || t.indexOf('tagged you') !== -1 || t.indexOf('@') !== -1;
  }
  const liveModal = document.getElementById('globalLiveModal');
  const liveModalDialog = liveModal ? liveModal.querySelector('.global-live-modal-dialog') : null;
  const liveModalFrame = document.getElementById('globalLiveModalFrame');
  const liveModalDirectVideo = document.getElementById('globalLiveModalDirectVideo');
  const liveModalStage = liveModal ? liveModal.querySelector('.global-live-modal-stage') : null;
  const liveModalSurface = document.getElementById('globalLiveModalSurface');
  const liveModalSnapshot = document.getElementById('globalLiveModalSnapshot');
  const liveModalClose = document.getElementById('globalLiveModalClose');
  const liveTopClose = document.getElementById('globalLiveTopClose');
  const liveParticipantCount = document.getElementById('globalLiveParticipantCount');
  const liveTopTitle = document.getElementById('globalLiveTopTitle');
  const liveSidebar = document.getElementById('globalLiveSidebar');
  const liveSidebarClose = document.getElementById('globalLiveSidebarClose');
  const liveSettingsToggle = document.getElementById('globalLiveSettingsToggle');
  const liveSettingsPanel = document.getElementById('globalLiveSettingsPanel');
  const liveSettingsCameraDevice = document.getElementById('globalLiveSettingsCameraDevice');
  const liveSettingsMicDevice = document.getElementById('globalLiveSettingsMicDevice');
  const liveSettingsSpeakerDevice = document.getElementById('globalLiveSettingsSpeakerDevice');
  const liveSettingsAudio = document.getElementById('globalLiveSettingsAudio');
  const liveSettingsCameraEnabled = document.getElementById('globalLiveSettingsCameraEnabled');
  const liveSettingsMirror = document.getElementById('globalLiveSettingsMirror');
  const liveSettingsQuality = document.getElementById('globalLiveSettingsQuality');
  const liveSettingsFrameRate = document.getElementById('globalLiveSettingsFrameRate');
  const liveMicToggle = document.getElementById('globalLiveMicToggle');
  const liveCameraToggle = document.getElementById('globalLiveCameraToggle');
  const liveChatToggle = document.getElementById('globalLiveChatToggle');
  const liveReactionToggle = document.getElementById('globalLiveReactionToggle');
  const liveDescriptionToggle = document.getElementById('globalLiveDescriptionToggle');
  const liveVisibilityBadge = document.getElementById('globalLiveVisibilityBadge');
  const liveOwnerAvatar = document.getElementById('globalLiveOwnerAvatar');
  const liveOwnerTitle = document.getElementById('globalLiveOwnerTitle');
  const liveReactionCount = document.getElementById('globalLiveReactionCount');
  const liveCommentCount = document.getElementById('globalLiveCommentCount');
  const liveViewCount = document.getElementById('globalLiveViewCount');
  const liveDockViewCount = document.getElementById('globalLiveDockViewCount');
  const liveToolbarCommentCount = document.getElementById('globalLiveToolbarCommentCount');
  const liveToolbarReactionCount = document.getElementById('globalLiveToolbarReactionCount');
  const liveQuickReactionButtons = Array.from(document.querySelectorAll('[data-live-room-reaction]'));
  const liveCommentsBox = document.getElementById('globalLiveCommentsBox');
  const liveCommentList = document.getElementById('globalLiveCommentList');
  const liveCommentHeaderCount = document.getElementById('globalLiveCommentHeaderCount');
  const liveSidebarTitleText = document.getElementById('globalLiveSidebarTitleText');
  const liveSidebarTitleCount = document.getElementById('globalLiveSidebarTitleCount');
  const liveReactionTabs = document.getElementById('globalLiveReactionTabs');
  const liveReactionList = document.getElementById('globalLiveReactionList');
  const liveDescriptionAvatar = document.getElementById('globalLiveDescriptionAvatar');
  const liveDescriptionTitle = document.getElementById('globalLiveDescriptionTitle');
  const liveDescriptionSub = document.getElementById('globalLiveDescriptionSub');
  const liveDescriptionBody = document.getElementById('globalLiveDescriptionBody');
  const liveStageComments = document.getElementById('globalLiveStageComments');
  const liveStageReactions = document.getElementById('globalLiveStageReactions');
  const liveCommentInput = document.getElementById('globalLiveCommentInput');
  const liveComposeFeedback = document.getElementById('globalLiveComposeFeedback');
  const liveSendButton = document.getElementById('globalLiveSendButton');
  const liveReactButton = document.getElementById('globalLiveReactButton');
  const liveJoinRequestButton = document.getElementById('globalLiveJoinRequestButton');
  const liveConfirm = document.getElementById('globalLiveConfirm');
  const liveConfirmCancel = document.getElementById('globalLiveConfirmCancel');
  const liveConfirmOk = document.getElementById('globalLiveConfirmOk');

  const chatBadges = () => Array.from(document.querySelectorAll('[data-chat-badge]'));
  const chatLists = () => Array.from(document.querySelectorAll('[data-chat-dropdown-list]'));
  const chatSummaryNodes = () => Array.from(document.querySelectorAll('[data-chat-summary]'));
  const chatDetailNodes = () => Array.from(document.querySelectorAll('[data-chat-detail]'));
  const chatUnknownNodes = () => Array.from(document.querySelectorAll('[data-chat-unknown]'));
  const currentHeaderUserId = <?php echo (int)$meId; ?>;
  let liveModalId = 0;
  let liveModalPollTimer = null;
  let liveModalSnapshotTimer = null;
  let liveModalStageSyncTimer = null;
  let liveModalReaction = '';
  let liveJoinRequestStatus = '';
  let liveOwnerId = 0;
  let liveCanRequestJoin = false;
  let liveSnapshotLiveActive = false;
  let liveSnapshotVersion = '';
  let liveSnapshotLoadToken = 0;
  let liveEmbedVideoCurrentTime = 0;
  let liveEmbedVideoLastAdvanceAt = 0;
  let liveStageInitialized = false;
  let liveHeaderMicEnabled = false;
  let liveHeaderCameraEnabled = true;
  let liveHeaderHostCameraEnabled = true;
  let liveHeaderIsOwnerView = false;
  let liveHeaderMirrorSelfView = false;
  let liveHeaderVideoQuality = 'auto';
  let liveHeaderFrameRatePreference = 24;
  let liveHeaderSelectedCameraDeviceId = '';
  let liveHeaderSelectedMicDeviceId = '';
  let liveHeaderSelectedSpeakerDeviceId = '';
  let liveHeaderCameraDevices = [];
  let liveHeaderMicDevices = [];
  let liveHeaderSpeakerDevices = [];
  let liveSeenCommentIds = new Set();
  let liveLastReactionCounts = { love:0, like:0, fire:0, wow:0, clap:0 };
  let liveSidebarMode = 'chat';
  let liveReactionFilter = 'all';
  let liveReactionUsers = [];
  let liveDescriptionState = createDefaultGlobalLiveDescriptionState();
  let suspendedLiveEmbedFrames = [];
  let liveDirectStageStream = null;
  const AV_COLORS = ['#4f46e5','#0ea5e9','#10b981','#f59e0b','#ef4444','#8b5cf6','#14b8a6','#f43f5e','#6366f1'];

  function createDefaultGlobalLiveDescriptionState(){
    return {
      owner_name: 'Host',
      title: 'Live session',
      description: 'Join the room, follow the comments, and react in real time as this live session runs.',
      started_at_label: 'Started now'
    };
  }

  function normalizeKey(s){
    s = String(s || '').trim();
    s = s.replace(/\s+/g, ' ');
    return s;
  }
  function colorFromString(str){
    str = normalizeKey(str);
    if(!str) str = 'User';
    let hash = 0;
    for(let i=0;i<str.length;i++){
      hash = str.charCodeAt(i) + ((hash << 5) - hash);
      hash = hash >>> 0;
    }
    const idx = Math.abs(hash) % AV_COLORS.length;
    return AV_COLORS[idx];
  }
  function gradStyle(baseHex){
    baseHex = String(baseHex || '#4f46e5').trim() || '#4f46e5';
    return `background: radial-gradient(circle at 30% 25%, rgba(255,255,255,.35), rgba(255,255,255,0) 40%), linear-gradient(135deg, ${baseHex}, #111827);`;
  }

  function setBadge(count) {
    count = parseInt(count || 0, 10);
    const text = count > 99 ? '99+' : String(count);
    chatBadges().forEach((badge) => {
      if (count > 0) {
        badge.textContent = text;
        badge.style.display = 'inline-flex';
      } else {
        badge.textContent = '';
        badge.style.display = 'none';
      }
    });
  }

  function setNotificationBadge(count){
    count = parseInt(count || 0, 10);
    const text = count > 99 ? '99+' : String(count);
    if (notifDot) notifDot.style.display = count > 0 ? 'block' : 'none';
    if (notifSquare) notifSquare.style.display = count > 0 ? 'block' : 'none';
    if (notifBadge) {
      notifBadge.textContent = text;
      notifBadge.style.display = count > 0 ? 'inline-flex' : 'none';
    }
    if (notifTopBadge) {
      notifTopBadge.textContent = text;
      notifTopBadge.style.display = count > 0 ? 'inline-block' : 'none';
    }
    document.querySelectorAll('#headerNotificationSub, #headerNotificationSubTop').forEach((node) => {
      node.textContent = count > 0 ? `${count} unread` : 'All caught up';
    });
    document.querySelectorAll('[data-notification-detail]').forEach((node) => {
      const n = latestNotificationItems.length;
      node.textContent = n > 0
        ? (n + ' recent alert' + (n === 1 ? '' : 's'))
        : 'Recent alerts will appear here';
    });
  }

  function renderNotificationList(items){
    const nextItems = Array.isArray(items) ? items.slice() : [];
    latestNotificationItems = nextItems;
    const targets = notificationListTargets();
    if(!targets.length) return false;

    const tab = notificationDoorTab === 'mentions' ? 'mentions' : 'all';
    const filtered = latestNotificationItems.filter((item) => {
      if (tab !== 'mentions') return true;
      return isMentionNotificationText(item && item.text);
    });

    document.querySelectorAll('[data-noti-door-tab]').forEach((btn) => {
      const on = btn.getAttribute('data-noti-door-tab') === tab;
      btn.classList.toggle('is-active', on);
      btn.setAttribute('aria-selected', on ? 'true' : 'false');
    });

    if(!filtered.length){
      const empty = tab === 'mentions'
        ? '<div class="dropdown-bestnoti-empty">No mentions yet. Tags and @mentions will show here.</div>'
        : '<div class="dropdown-bestnoti-empty">No notifications yet. New alerts will appear here.</div>';
      targets.forEach((node) => { node.innerHTML = empty; });
      return true;
    }

    try {
      const html = filtered.map((item) => {
        const sender = notificationEsc(item.sender || 'Someone');
        const text = notificationEsc(item.text || 'sent a notification');
        const time = notificationEsc(notificationTimeLabel(item.created_at || ''));
        const url = notificationEsc(item.url || '#');
        const unread = parseInt(item.is_read || 0, 10) === 0;
        const initials = notificationEsc(notificationInitials(item.sender || 'NT'));
        const kind = isMentionNotificationText(item.text) ? 'mentions' : 'all';
        const postId = parseInt(item.post_id || 0, 10) || 0;
        const isStory = parseInt(item.is_story || 0, 10) === 1 ? 1 : 0;
        return ''
          + '<a href="' + url + '" class="dropdown-bestnoti-item' + (unread ? ' is-unread' : '') + '"'
          + ' data-notification-id="' + (parseInt(item.id || 0, 10) || 0) + '"'
          + ' data-notification-url="' + (url === '#' ? '' : url) + '"'
          + ' data-live-id="' + (parseInt(item.live_id || 0, 10) || 0) + '"'
          + ' data-post-id="' + postId + '"'
          + ' data-is-story="' + isStory + '"'
          + ' data-noti-kind="' + kind + '">'
          + '<div class="bestnoti-avatar">' + initials + '</div>'
          + '<div class="bestnoti-mid">'
          + '<div class="bestnoti-text"><strong>' + sender + '</strong> ' + text + '</div>'
          + '<div class="bestnoti-time">' + time + '</div>'
          + '</div>'
          + '</a>';
      }).join('');

      targets.forEach((node) => {
        node.innerHTML = html;
      });
      return true;
    } catch (eRender) {
      return false;
    }
  }

  window.__msbLiveBrowseFingerprint = window.__msbLiveBrowseFingerprint || '';

  function relayHubLiveRefreshToDoorFrames(){
    ['ttLiveDoorFrame', 'ttLiveRightDoorFrame'].forEach(function(frameId){
      const frame = document.getElementById(frameId);
      if(!frame || !frame.contentWindow) return;
      try { frame.contentWindow.postMessage({ type: 'msb-hub-live-refresh' }, '*'); } catch(err) {}
    });
  }

  async function pollActiveLiveRooms(){
    try {
      const res = await fetch('ajax/live_door_browse.php', { cache: 'no-store', credentials: 'same-origin' });
      const data = await res.json();
      if (!data || !data.ok) return;
      const fingerprint = String(data.fingerprint || (data.lives || []).map(function(live){ return live.id; }).join(','));
      if (fingerprint !== window.__msbLiveBrowseFingerprint) {
        window.__msbLiveBrowseFingerprint = fingerprint;
        relayHubLiveRefreshToDoorFrames();
      }
    } catch(e) {}
  }

  async function pollNotificationCount(){
    try{
      const res = await fetch('ajax/user_notifications_poll.php', { cache: 'no-store', credentials: 'same-origin' });
      const data = await res.json();
      if (data && data.ok) {
        const incoming = Array.isArray(data.items) ? data.items.slice() : [];
        // Never wipe a good list with an empty payload while unread is still > 0.
        if (incoming.length || !(parseInt(data.unread || 0, 10) > 0 && latestNotificationItems.length)) {
          latestNotificationItems = incoming;
        }
        setNotificationBadge(data.unread || 0);
        renderNotificationList(latestNotificationItems);
      }
    } catch(e) {}
    return latestNotificationItems;
  }

  // Keep the door list in sync with the badge as soon as scripts load.
  try { renderNotificationList(latestNotificationItems); } catch (eHydrate) {}

  window.MSBNotificationsUI = window.MSBNotificationsUI || {};
  window.MSBNotificationsUI.refresh = pollNotificationCount;
  window.MSBNotificationsUI.paint = function(){
    return renderNotificationList(latestNotificationItems);
  };
  window.MSBNotificationsUI.setTab = function(tab){
    notificationDoorTab = (tab === 'mentions') ? 'mentions' : 'all';
    renderNotificationList(latestNotificationItems);
  };

  document.addEventListener('click', function(e){
    const tabBtn = e.target && e.target.closest ? e.target.closest('[data-noti-door-tab]') : null;
    if (!tabBtn) return;
    e.preventDefault();
    e.stopPropagation();
    window.MSBNotificationsUI.setTab(tabBtn.getAttribute('data-noti-door-tab') || 'all');
  }, true);

  async function markNotificationRead(id, item){
    try{
      const fd = new FormData();
      fd.append('id', String(id));
      const res = await fetch('ajax/user_mark_read.php', { method:'POST', body:fd, credentials:'same-origin' });
      const data = await res.json();
      if (data && data.ok) {
        if (item) item.classList.remove('is-unread');
        pollNotificationCount();
        return true;
      }
    } catch(e) {}
    return false;
  }

  async function markAllNotificationsRead(){
    try{
      const res = await fetch('ajax/user_mark_all_read.php', { method:'POST', credentials:'same-origin' });
      const data = await res.json();
      if (data && data.ok) {
        notifItems().forEach((item) => item.classList.remove('is-unread'));
        setNotificationBadge(0);
      }
    } catch(e) {}
  }

  async function pollUnreadCount() {
    try {
      const res = await fetch('ajax/user_chat_unread_poll.php', { cache: 'no-store' });
      const data = await res.json();
      if (data && data.ok) setBadge(data.unread || 0);
    } catch (e) {}
  }

  function isLiveModalUrl(url){
    return /(^|\/)live_watch\.php\?/.test(String(url || ''));
  }

  function createPostModalSrc(src){
    var nextSrc = String(src || 'dashboard.php?modal=1').trim() || 'dashboard.php?modal=1';
    try {
      var url = new URL(nextSrc, window.location.href);
      if (!url.searchParams.get('modal')) url.searchParams.set('modal', '1');
      var appearance = document.documentElement.getAttribute('data-msb-appearance') || '';
      if (!appearance) {
        try {
          var prefs = (window.MSBTheme && typeof window.MSBTheme.getPrefs === 'function')
            ? window.MSBTheme.getPrefs()
            : (window.__MSBThemePrefs || null);
          var mode = prefs && prefs.appearanceMode ? String(prefs.appearanceMode) : '';
          if (mode && mode !== 'system' && mode !== 'light' && mode !== 'dark') {
            appearance = mode;
          }
        } catch (_prefsErr) {}
      }
      if (appearance) {
        url.searchParams.set('appearance', appearance);
      } else {
        url.searchParams.delete('appearance');
      }
      try {
        var cs = window.getComputedStyle(document.documentElement);
        var paletteBg = (cs.getPropertyValue('--msb-palette-bg') || '').trim();
        if (/^#([0-9a-f]{3}|[0-9a-f]{6})$/i.test(paletteBg)) {
          url.searchParams.set('palette_bg', paletteBg);
        } else {
          url.searchParams.delete('palette_bg');
        }
      } catch (_bgErr) {
        url.searchParams.delete('palette_bg');
      }
      url.searchParams.set('_t', String(Date.now()));
      var rel = url.pathname.split('/').pop() || 'dashboard.php';
      return rel + url.search + url.hash;
    } catch (_e) {
      return nextSrc;
    }
  }

  var CREATE_POST_ACCORDION_MS = 680;
  var CREATE_POST_OPEN_ESTIMATE = 340;
  var createPostFormReady = false;
  var createPostTargetBodyH = 0;
  var createPostAnimToken = 0;

  function getCreatePostAccordion(){
    return document.getElementById('createPostAccordion');
  }

  function ensureCreatePostAccordion(){
    var $ = window.jQuery;
    var el = getCreatePostAccordion();
    if (!$ || !el || typeof $.fn.accordion !== 'function') return null;
    var $acc = $(el);
    if ($acc.data('ui-accordion')) return $acc;
    $acc.accordion({
      collapsible: true,
      active: false,
      animate: false,
      heightStyle: 'content',
      icons: false,
      header: '> h3.create-post-topbar'
    });
    return $acc;
  }

  function createPostAnimEasing(){
    var $ = window.jQuery;
    if ($ && $.easing && typeof $.easing.easeOutCubic === 'function') return 'easeOutCubic';
    return 'swing';
  }

  function parkCreatePostDoorShort(){
    var dialog = getCreatePostAccordion() || (createPostModal && createPostModal.querySelector('.create-post-dialog'));
    if (!dialog) return;
    var bodyEl = dialog.querySelector('.create-post-body');
    var frame = dialog.querySelector('.create-post-frame');
    if (window.jQuery) {
      try { window.jQuery(bodyEl).stop(true, false); } catch (_s) {}
    }
    dialog.style.height = '36px';
    dialog.style.minHeight = '36px';
    dialog.style.maxHeight = 'none';
    if (bodyEl) {
      bodyEl.style.height = '0px';
      bodyEl.style.minHeight = '0px';
      bodyEl.style.maxHeight = 'none';
      bodyEl.style.overflow = 'hidden';
      bodyEl.style.opacity = '1';
      bodyEl.style.padding = '0';
      bodyEl.style.display = 'block';
    }
    if (frame) {
      frame.style.height = '0px';
      frame.style.minHeight = '0px';
      frame.style.maxHeight = 'none';
      frame.style.opacity = '1';
    }
    createPostTargetBodyH = 0;
  }

  function applyCreatePostDoorHeights(bodyH){
    var dialog = getCreatePostAccordion() || (createPostModal && createPostModal.querySelector('.create-post-dialog'));
    if (!dialog) return;
    var bodyEl = dialog.querySelector('.create-post-body');
    var frame = dialog.querySelector('.create-post-frame') || createPostModalFrame;
    var topbarH = 36;
    var h = Math.max(0, Math.round(bodyH || 0));
    createPostTargetBodyH = h;
    if (bodyEl) {
      bodyEl.style.height = h + 'px';
      bodyEl.style.minHeight = h + 'px';
      bodyEl.style.maxHeight = 'none';
      bodyEl.style.overflow = 'auto';
      bodyEl.style.opacity = '1';
      bodyEl.style.display = 'block';
    }
    if (frame) {
      frame.style.height = h + 'px';
      frame.style.minHeight = h + 'px';
      frame.style.maxHeight = 'none';
      frame.style.opacity = '1';
    }
    dialog.style.height = (h + topbarH) + 'px';
    dialog.style.minHeight = (h + topbarH) + 'px';
    dialog.style.maxHeight = 'none';
  }

  function animateCreatePostDoor(toBodyH, opts){
    opts = opts || {};
    var dialog = getCreatePostAccordion() || (createPostModal && createPostModal.querySelector('.create-post-dialog'));
    var bodyEl = dialog ? dialog.querySelector('.create-post-body') : null;
    var frame = dialog ? (dialog.querySelector('.create-post-frame') || createPostModalFrame) : null;
    if (!dialog || !bodyEl) {
      if (typeof opts.done === 'function') opts.done(false);
      return false;
    }
    var $ = window.jQuery;
    var topbarH = 36;
    var target = Math.max(0, Math.round(toBodyH || 0));
    var duration = (typeof opts.duration === 'number') ? opts.duration : CREATE_POST_ACCORDION_MS;
    var token = ++createPostAnimToken;
    createPostTargetBodyH = target;

    if (createPostModal) {
      createPostModal.classList.remove('is-form-loading');
      if (target > 0) {
        createPostModal.classList.add('is-opening');
        createPostModal.classList.remove('is-closing');
      } else {
        createPostModal.classList.add('is-closing');
        createPostModal.classList.remove('is-opening');
      }
    }

    bodyEl.style.display = 'block';
    bodyEl.style.overflow = 'auto';
    bodyEl.style.opacity = '1';
    bodyEl.style.maxHeight = 'none';
    if (frame) {
      frame.style.opacity = '1';
      frame.style.maxHeight = 'none';
    }
    dialog.style.maxHeight = 'none';

    var startH = Math.round(bodyEl.getBoundingClientRect().height || parseFloat(bodyEl.style.height) || 0);
    if (!$ || typeof $.fn.animate !== 'function') {
      applyCreatePostDoorHeights(target);
      if (createPostModal) {
        createPostModal.classList.remove('is-opening', 'is-closing');
        if (target > 0) createPostModal.classList.add('is-form-ready');
      }
      if (typeof opts.done === 'function') opts.done(true);
      return true;
    }

    /* Avoid zero-duration snap when already there. */
    if (Math.abs(startH - target) < 2) {
      applyCreatePostDoorHeights(target);
      if (createPostModal) {
        createPostModal.classList.remove('is-opening', 'is-closing');
        if (target > 0) createPostModal.classList.add('is-form-ready');
      }
      if (typeof opts.done === 'function') opts.done(true);
      return true;
    }

    $(bodyEl).stop(true, false).css({ height: startH }).animate({ height: target }, {
      duration: duration,
      easing: createPostAnimEasing(),
      step: function(now){
        if (token !== createPostAnimToken) return;
        var n = Math.max(0, Math.round(now));
        bodyEl.style.minHeight = n + 'px';
        if (frame) {
          frame.style.height = n + 'px';
          frame.style.minHeight = n + 'px';
        }
        dialog.style.height = (n + topbarH) + 'px';
        dialog.style.minHeight = (n + topbarH) + 'px';
      },
      complete: function(){
        if (token !== createPostAnimToken) return;
        applyCreatePostDoorHeights(target);
        if (createPostModal) {
          createPostModal.classList.remove('is-opening', 'is-closing');
          if (target > 0) {
            createPostModal.classList.add('is-form-ready');
            createPostFormReady = true;
          }
        }
        var $acc = ensureCreatePostAccordion();
        if ($acc) {
          try {
            $acc.accordion('option', 'active', target > 0 ? 0 : false);
            $acc.accordion('refresh');
          } catch (_acc) {}
        }
        if (typeof opts.done === 'function') opts.done(true);
      }
    });
    return true;
  }

  function syncCreatePostDropGeometry(){
    if (!createPostModal) return;
    var dialog = getCreatePostAccordion() || createPostModal.querySelector('.create-post-dialog');
    if (!dialog) return;
    try {
      var headerEl =
        document.querySelector('.ig-feed-header') ||
        document.querySelector('.ig-stories-wrap') ||
        document.querySelector('.ig-stories-bar');

      var topPad = 0;
      if (headerEl) {
        var hr = headerEl.getBoundingClientRect();
        if (hr && hr.height >= 8) topPad = Math.max(0, Math.round(hr.bottom));
      }

      var railW = 0;
      var rail = document.querySelector('.feed-ig-rail');
      if (rail && window.matchMedia && window.matchMedia('(min-width: 1025px)').matches) {
        var rr = rail.getBoundingClientRect();
        if (rr && rr.width > 0) railW = Math.round(rr.width);
      }

      var sidePad = 16;
      var hostW = Math.max(320, Math.round(window.innerWidth - railW));
      var avail = Math.max(320, hostW - (sidePad * 2));
      var width = Math.min(1180, avail);
      var hostH = Math.max(280, Math.round(window.innerHeight - topPad));

      createPostModal.style.top = topPad + 'px';
      createPostModal.style.left = railW + 'px';
      createPostModal.style.right = '0';
      createPostModal.style.bottom = '0';
      createPostModal.style.width = hostW + 'px';
      createPostModal.style.height = hostH + 'px';
      createPostModal.style.padding = '0';
      createPostModal.style.setProperty('background', 'transparent', 'important');
      createPostModal.style.setProperty('background-color', 'transparent', 'important');
      createPostModal.style.setProperty('background-image', 'none', 'important');

      dialog.style.position = 'absolute';
      dialog.style.top = '0';
      dialog.style.left = '50%';
      dialog.style.right = 'auto';
      dialog.style.bottom = 'auto';
      dialog.style.width = width + 'px';
      dialog.style.maxWidth = width + 'px';
      dialog.style.margin = '0';
      dialog.style.marginBottom = '10px';
      dialog.style.borderRadius = '0 0 14px 14px';
      dialog.style.transform = 'translateX(-50%)';

      var frameEl = dialog.querySelector('.create-post-frame');
      if (frameEl) {
        frameEl.style.width = '100%';
        frameEl.style.display = 'block';
      }

      /* Keep width/position only; height is owned by open/close animation. */
      if (!createPostModal.classList.contains('is-open')) {
        parkCreatePostDoorShort();
      } else if (createPostFormReady && createPostTargetBodyH > 0
        && !createPostModal.classList.contains('is-opening')
        && !createPostModal.classList.contains('is-closing')) {
        applyCreatePostDoorHeights(createPostTargetBodyH);
      }

      var $acc = ensureCreatePostAccordion();
      if ($acc) {
        try { $acc.accordion('refresh'); } catch (_ref) {}
      }
    } catch (_geoErr) {}
  }

  function measureCreatePostFormHeight(doc){
    if (!doc) return 0;
    try {
      var form = doc.getElementById('createPostForm');
      var card = doc.querySelector('.card');
      var page = doc.querySelector('.sh-pagebody');
      var cardBody = doc.querySelector('.card-body');
      if (!form && !cardBody && !card) return 0;

      var scrollY = (doc.defaultView && doc.defaultView.pageYOffset)
        || doc.documentElement.scrollTop
        || doc.body.scrollTop
        || 0;

      function isVisible(el){
        if (!el) return false;
        if (el.hasAttribute && el.hasAttribute('hidden')) return false;
        if (el.closest && el.closest('[hidden]')) return false;
        try {
          var st = doc.defaultView.getComputedStyle(el);
          if (!st || st.display === 'none' || st.visibility === 'hidden') return false;
        } catch (_cs) {}
        return (el.offsetParent !== null) || (el.getClientRects && el.getClientRects().length > 0);
      }

      /* Prefer composer Post/Cancel footer; else visible slides actions. */
      var endEl =
        (isVisible(doc.querySelector('.msb-composer-footer')) && doc.querySelector('.msb-composer-footer')) ||
        (isVisible(doc.querySelector('.create-post-slides-actions')) && doc.querySelector('.create-post-slides-actions')) ||
        form ||
        cardBody ||
        card;

      if (endEl) {
        var bottom = endEl.getBoundingClientRect().bottom + scrollY;
        var progress = doc.getElementById('createPostUploadProgress');
        if (progress && isVisible(progress)) {
          bottom = Math.max(bottom, progress.getBoundingClientRect().bottom + scrollY);
        }
        var tight = Math.ceil(bottom + 10);
        if (tight >= 160) {
          var cap = Math.max(280, Math.round(window.innerHeight * 0.88));
          return Math.min(tight, cap);
        }
      }

      var el = form || cardBody || card;
      var h = Math.ceil(Math.max(el.scrollHeight || 0, el.offsetHeight || 0));
      if (h < 160) return 0;
      var cap2 = Math.max(280, Math.round(window.innerHeight * 0.88));
      if (h > cap2) h = cap2;
      return h;
    } catch (_m) {
      return 0;
    }
  }

  function resolveCreatePostBodyHeight(){
    var frame = createPostModalFrame;
    if (!frame) return 0;
    var hostH = parseInt(createPostModal && createPostModal.style.height, 10) || Math.max(280, Math.round(window.innerHeight - 80));
    var panelMax = Math.max(220, hostH - 36);
    try {
      var doc = frame.contentDocument;
      if (!doc || !doc.body) return 0;
      Array.prototype.forEach.call(doc.querySelectorAll('.sh-footer, .app-footer'), function(el){
        el.style.setProperty('display', 'none', 'important');
        el.style.setProperty('height', '0', 'important');
        el.style.setProperty('margin', '0', 'important');
        el.style.setProperty('padding', '0', 'important');
        el.setAttribute('hidden', 'hidden');
      });
      var main = doc.querySelector('.sh-mainpanel');
      var page = doc.querySelector('.sh-pagebody');
      var cardBody = doc.querySelector('.card-body');
      if (main) {
        main.style.setProperty('height', 'auto', 'important');
        main.style.setProperty('min-height', '0', 'important');
        main.style.setProperty('padding-bottom', '0', 'important');
        main.style.setProperty('margin-bottom', '0', 'important');
      }
      if (page) {
        page.style.setProperty('height', 'auto', 'important');
        page.style.setProperty('min-height', '0', 'important');
        page.style.setProperty('padding-bottom', '0', 'important');
        page.style.setProperty('margin-bottom', '0', 'important');
      }
      if (cardBody) {
        cardBody.style.setProperty('padding-bottom', '4px', 'important');
        cardBody.style.setProperty('margin-bottom', '0', 'important');
      }
      if (doc.documentElement) doc.documentElement.style.setProperty('height', 'auto', 'important');
      doc.body.style.setProperty('height', 'auto', 'important');
      doc.body.style.setProperty('min-height', '0', 'important');
      doc.body.style.setProperty('overflow', 'hidden', 'important');
      doc.body.style.setProperty('padding-bottom', '0', 'important');
      doc.body.style.setProperty('margin-bottom', '0', 'important');

      var contentH = measureCreatePostFormHeight(doc);
      if (!contentH) contentH = CREATE_POST_OPEN_ESTIMATE;
      /* No extra floor / fat padding — cut at the action bar. */
      return Math.min(panelMax, contentH);
    } catch (_r) {
      return 0;
    }
  }

  function fitCreatePostDoorToForm(opts){
    opts = opts || {};
    if (!createPostModal || !createPostModal.classList.contains('is-open')) return false;
    if (createPostModal.classList.contains('is-closing')) return false;
    var next = resolveCreatePostBodyHeight();
    if (!next) return false;

    createPostFormReady = true;
    createPostModal.classList.add('is-form-ready');
    createPostModal.classList.remove('is-form-loading');

    /* Grow/shrink door so Add slide + Submit stay visible (never clip them). */
    if (createPostModal.classList.contains('is-opening') || opts.forceAnimate || Math.abs((createPostTargetBodyH || 0) - next) > 12) {
      animateCreatePostDoor(next, { duration: opts.duration || Math.round(CREATE_POST_ACCORDION_MS * 0.65) });
    } else {
      applyCreatePostDoorHeights(next);
    }
    return true;
  }

  var createPostCloseTimer = 0;
  function clearCreatePostCloseTimer(){
    if (createPostCloseTimer) {
      clearTimeout(createPostCloseTimer);
      createPostCloseTimer = 0;
    }
  }

  function isCreatePostModalOpen(){
    return !!(createPostModal && createPostModal.classList.contains('is-open')
      && createPostModal.getAttribute('aria-hidden') !== 'true');
  }

  function openCreatePostModal(src){
    if (!createPostModal) return;
    clearCreatePostCloseTimer();
    createPostFormReady = false;
    createPostModal.classList.remove('is-form-ready', 'is-closing');
    createPostModal.classList.add('is-opening');
    if (STAFF_READONLY) {
      if (createPostReadonlyText) {
        createPostReadonlyText.textContent = 'As Publisher ' + String(STAFF_ROLE_LABEL || 'Staff') + ', you can browse this organization feed but cannot create posts. Ask your manager if you need posting access.';
      }
      if (createPostReadonlyNotice) createPostReadonlyNotice.removeAttribute('hidden');
      syncCreatePostDropGeometry();
      createPostModal.classList.add('is-open', 'is-readonly-notice');
      createPostModal.setAttribute('aria-hidden', 'false');
      createPostFormReady = true;
      createPostModal.classList.add('is-form-ready');
      parkCreatePostDoorShort();
      animateCreatePostDoor(Math.min(360, CREATE_POST_OPEN_ESTIMATE), { duration: CREATE_POST_ACCORDION_MS });
      if (createPostModalFrame) createPostModalFrame.setAttribute('src', 'about:blank');
      document.body.style.overflow = 'hidden';
      return;
    }
    if (!createPostModalFrame) return;
    createPostModal.classList.remove('is-readonly-notice');
    if (createPostReadonlyNotice) createPostReadonlyNotice.setAttribute('hidden', 'hidden');
    var nextSrc = createPostModalSrc(src);
    var titleEl = createPostModal.querySelector('.create-post-title');
    var dialogEl = getCreatePostAccordion() || createPostModal.querySelector('.create-post-dialog');
    var isEdit = /(?:^|[?&#])edit=\d+/i.test(String(nextSrc)) || /(?:^|[?&#])edit=\d+/i.test(String(src || ''));
    if (titleEl) {
      titleEl.textContent = isEdit ? 'Edit post' : 'Create new post';
      Array.prototype.forEach.call(titleEl.querySelectorAll('i, .icon, .fa'), function(el){
        if (el && el.parentNode) el.parentNode.removeChild(el);
      });
    }
    var topbarStrip = createPostModal.querySelector('.create-post-topbar');
    if (topbarStrip) {
      Array.prototype.forEach.call(topbarStrip.querySelectorAll('.ui-accordion-header-icon, .ui-icon, i.icon.ion-compose, .ion-compose'), function(el){
        if (el && !el.closest('.create-post-close') && el.parentNode) el.parentNode.removeChild(el);
      });
    }
    if (dialogEl) {
      dialogEl.setAttribute('aria-label', isEdit ? 'Edit post' : 'Create post');
    }
    syncCreatePostDropGeometry();
    parkCreatePostDoorShort();
    createPostModal.classList.add('is-open');
    createPostModal.setAttribute('aria-hidden', 'false');
    try {
      var cs = window.getComputedStyle(document.documentElement);
      var bg = (cs.getPropertyValue('--msb-palette-bg') || '').trim();
      var text = (cs.getPropertyValue('--msb-palette-text') || '').trim();
      var dialog = getCreatePostAccordion() || createPostModal.querySelector('.create-post-dialog');
      var body = createPostModal.querySelector('.create-post-body');
      var frame = createPostModal.querySelector('.create-post-frame');
      var topbar = createPostModal.querySelector('.create-post-topbar');
      if (bg) {
        if (dialog) dialog.style.setProperty('background', bg, 'important');
        if (body) body.style.setProperty('background', bg, 'important');
        if (frame) frame.style.setProperty('background', bg, 'important');
        if (topbar) topbar.style.setProperty('background', bg, 'important');
      }
      /* Keep title/icon/close dark on the light create-post bar. */
      if (topbar) {
        topbar.style.setProperty('color', '#0f172a', 'important');
        var titleNode = topbar.querySelector('.create-post-title');
        var closeNode = topbar.querySelector('.create-post-close');
        if (titleNode) titleNode.style.setProperty('color', '#0f172a', 'important');
        if (closeNode) {
          closeNode.style.setProperty('color', '#0f172a', 'important');
          closeNode.style.setProperty('background', 'rgba(15,23,42,.1)', 'important');
        }
      }
    } catch (_styleErr) {}
    ensureCreatePostAccordion();
    try {
      var $accOpen = ensureCreatePostAccordion();
      if ($accOpen) {
        $accOpen.accordion('option', 'animate', false);
        $accOpen.accordion('option', 'active', 0);
        $accOpen.accordion('refresh');
      }
    } catch (_accShow) {}
    parkCreatePostDoorShort();
    /* Smooth open starts immediately — no freeze waiting on iframe. */
    animateCreatePostDoor(CREATE_POST_OPEN_ESTIMATE, { duration: CREATE_POST_ACCORDION_MS });
    createPostModalFrame.setAttribute('src', nextSrc);
    document.body.style.overflow = 'hidden';
  }

  function resetCreatePostGeometry(){
    if (!createPostModal) return;
    try {
      var dialog = getCreatePostAccordion() || createPostModal.querySelector('.create-post-dialog');
      createPostModal.style.justifyContent = '';
      createPostModal.style.paddingLeft = '';
      createPostModal.style.paddingRight = '';
      createPostModal.style.paddingTop = '';
      createPostModal.style.padding = '';
      createPostModal.style.top = '';
      createPostModal.style.left = '';
      createPostModal.style.right = '';
      createPostModal.style.bottom = '';
      createPostModal.style.width = '';
      createPostModal.style.height = '';
      if (dialog) {
        dialog.style.position = '';
        dialog.style.inset = '';
        dialog.style.top = '';
        dialog.style.left = '';
        dialog.style.right = '';
        dialog.style.bottom = '';
        dialog.style.width = '';
        dialog.style.maxWidth = '';
        dialog.style.maxHeight = '';
        dialog.style.height = '';
        dialog.style.minHeight = '';
        dialog.style.margin = '';
        dialog.style.marginLeft = '';
        dialog.style.marginRight = '';
        dialog.style.borderRadius = '';
        dialog.style.borderTop = '';
        dialog.style.animation = '';
        dialog.style.opacity = '';
        dialog.style.transition = '';
        dialog.style.transform = '';
        var bodyEl = dialog.querySelector('.create-post-body');
        if (bodyEl) {
          bodyEl.style.height = '';
          bodyEl.style.maxHeight = '';
          bodyEl.style.minHeight = '';
        }
      }
    } catch (_clr) {}
  }

  function closeCreatePostModal(){
    if (!createPostModal) return;
    if (!createPostModal.classList.contains('is-open') && createPostModal.getAttribute('aria-hidden') === 'true') {
      return;
    }
    if (createPostModal.classList.contains('is-closing')) return;
    clearCreatePostCloseTimer();
    createPostFormReady = false;
    createPostModal.classList.add('is-closing');
    createPostModal.classList.remove('is-opening', 'is-form-ready', 'is-form-loading');
    animateCreatePostDoor(0, {
      duration: CREATE_POST_ACCORDION_MS,
      done: function(){
        createPostCloseTimer = 0;
        createPostFormReady = false;
        createPostTargetBodyH = 0;
        createPostModal.classList.remove('is-open', 'is-readonly-notice', 'is-form-ready', 'is-form-loading', 'is-opening', 'is-closing');
        createPostModal.setAttribute('aria-hidden', 'true');
        if (createPostReadonlyNotice) createPostReadonlyNotice.setAttribute('hidden', 'hidden');
        document.body.style.overflow = '';
        if (createPostModalFrame) createPostModalFrame.setAttribute('src', 'about:blank');
        parkCreatePostDoorShort();
        resetCreatePostGeometry();
      }
    });
  }

  function liveVisibilityText(value){
    value = String(value || '').toLowerCase();
    if (value === 'public') return 'PUBLIC ROOM';
    if (value === 'friends') return 'FRIENDS ONLY';
    return 'PRIVATE ROOM';
  }

  function syncGlobalLiveDescription(live){
    if (live && typeof live === 'object') {
      const nextState = createDefaultGlobalLiveDescriptionState();
      const ownerName = String(live.owner_name || '').trim();
      const title = String(live.title || '').trim();
      const description = String(live.description || '').trim();
      const started = String(live.started_at_label || '').trim();
      liveDescriptionState = {
        owner_name: ownerName || nextState.owner_name,
        title: title || nextState.title,
        description: description || nextState.description,
        started_at_label: started || nextState.started_at_label
      };
    }
    const currentLive = liveDescriptionState || createDefaultGlobalLiveDescriptionState();
    const ownerName = String(currentLive.owner_name || 'Host');
    const title = String(currentLive.title || 'Live session');
    const description = String(currentLive.description || 'Join the room, follow the comments, and react in real time as this live session runs.');
    const started = String(currentLive.started_at_label || 'Started now');
    if (liveDescriptionAvatar) {
      liveDescriptionAvatar.textContent = initialsFrom(ownerName);
      liveDescriptionAvatar.style.cssText = gradStyle(colorFromString(ownerName));
    }
    if (liveDescriptionTitle) liveDescriptionTitle.textContent = title;
    if (liveDescriptionSub) liveDescriptionSub.textContent = ownerName + ' • ' + started;
    if (liveDescriptionBody) liveDescriptionBody.textContent = description;
  }

  function setGlobalLiveSidebarMode(mode){
    if (!liveModalDialog) return;
    const nextMode = mode === 'reactions'
      ? 'reactions'
      : (mode === 'description' ? 'description' : (mode === 'settings' ? 'settings' : (mode === 'chat' ? 'chat' : '')));
    liveSidebarMode = nextMode;
    liveModalDialog.classList.toggle('has-chat', nextMode !== '');
    liveModalDialog.classList.toggle('sidebar-mode-chat', nextMode === 'chat');
    liveModalDialog.classList.toggle('sidebar-mode-reactions', nextMode === 'reactions');
    liveModalDialog.classList.toggle('sidebar-mode-description', nextMode === 'description');
    liveModalDialog.classList.toggle('sidebar-mode-settings', nextMode === 'settings');
    if (liveSettingsPanel) {
      liveSettingsPanel.setAttribute('aria-hidden', nextMode === 'settings' ? 'false' : 'true');
    }
    if (liveSettingsToggle) {
      liveSettingsToggle.classList.toggle('is-active', nextMode === 'settings');
      liveSettingsToggle.setAttribute('aria-pressed', nextMode === 'settings' ? 'true' : 'false');
    }
    if (liveChatToggle) {
      liveChatToggle.classList.toggle('is-active', nextMode === 'chat');
      liveChatToggle.setAttribute('aria-pressed', nextMode === 'chat' ? 'true' : 'false');
    }
    if (liveReactionToggle) {
      liveReactionToggle.classList.toggle('is-active', nextMode === 'reactions');
      liveReactionToggle.setAttribute('aria-pressed', nextMode === 'reactions' ? 'true' : 'false');
    }
    if (liveDescriptionToggle) {
      liveDescriptionToggle.classList.toggle('is-active', nextMode === 'description');
      liveDescriptionToggle.setAttribute('aria-pressed', nextMode === 'description' ? 'true' : 'false');
    }
    if (nextMode === 'settings') {
      syncGlobalLiveDeviceControls();
    }
    if (nextMode === 'description') {
      syncGlobalLiveDescription();
    }
    syncGlobalLiveSidebarHeader();
    window.requestAnimationFrame(syncGlobalLiveFrameSize);
  }

  function setGlobalLiveSidebarOpen(isOpen){
    setGlobalLiveSidebarMode(isOpen ? 'chat' : '');
  }

  function setGlobalLiveComposeFeedback(message, kind){
    if (!liveComposeFeedback) return;
    liveComposeFeedback.textContent = message || '';
    liveComposeFeedback.className = 'global-live-compose-feedback' + (kind ? ' ' + kind : '');
  }

  function setGlobalLiveConfirmOpen(isOpen){
    if (!liveConfirm) return;
    liveConfirm.classList.toggle('is-open', !!isOpen);
    liveConfirm.setAttribute('aria-hidden', isOpen ? 'false' : 'true');
  }

  function parseLiveId(url){
    try{
      const absolute = new URL(url, window.location.href);
      return parseInt(absolute.searchParams.get('live') || '0', 10) || 0;
    } catch (e) {
      const match = String(url || '').match(/[?&]live=(\d+)/);
      return match ? parseInt(match[1], 10) || 0 : 0;
    }
  }

  function toEmbedLiveUrl(url){
    const absolute = new URL(url, window.location.href);
    absolute.searchParams.set('embed', '1');
    absolute.searchParams.set('header_modal', '1');
    return absolute.toString();
  }

  function liveWatchUrlFromId(liveId){
    const id = parseInt(liveId || 0, 10) || 0;
    return id > 0 ? ('live_watch.php?live=' + encodeURIComponent(String(id))) : '';
  }

  function detectHubSurfaceForLiveDoor(){
    const path = String(window.location.pathname || '').toLowerCase();
    if (path.endsWith('/feed.php') || path.includes('/feed.php')) return 'feed';
    return 'public';
  }

  function resolveHeaderFriendWatchDoor(meta){
    meta = meta || {};
    const hostDoor = String(meta.host_door || meta.hostLiveDoor || meta.watch_door || '').toLowerCase();
    const studioSource = String(meta.studio_source || meta.studioSource || '').toLowerCase();
    if (hostDoor === 'right' || (studioSource === 'software' && hostDoor !== 'left')) {
      return 'right';
    }
    if (hostDoor === 'left') return 'left';
    return hostDoor !== '' ? hostDoor : 'left';
  }

  function buildHeaderLeftDoorWatchUrl(liveId, meta, surface){
    liveId = parseInt(liveId || 0, 10) || 0;
    meta = meta || {};
    surface = String(surface || detectHubSurfaceForLiveDoor());
    const url = new URL('live_door_hub.php', window.location.href);
    url.searchParams.set('hub_surface', surface);
    url.searchParams.set('can_studio', '1');
    url.searchParams.set('hub_door', 'left');
    url.searchParams.set('hub_tab', 'public');
    if (liveId > 0) url.searchParams.set('watch_live', String(liveId));
    if (meta.visibility) url.searchParams.set('watch_visibility', String(meta.visibility));
    if (meta.host) url.searchParams.set('watch_host', String(meta.host));
    if (meta.title) url.searchParams.set('watch_title', String(meta.title));
    if (meta.snapshot_version) url.searchParams.set('watch_snapshot', String(meta.snapshot_version));
    if (meta.host_door) url.searchParams.set('watch_host_door', String(meta.host_door));
    if (meta.studio_source) url.searchParams.set('watch_studio_source', String(meta.studio_source));
    const ownerId = parseInt(meta.user_id || meta.owner_id || meta.ownerId || 0, 10) || 0;
    if (ownerId > 0) url.searchParams.set('watch_owner', String(ownerId));
    return url.href;
  }

  function buildHeaderRightDoorWatchUrl(liveId, meta, surface){
    liveId = parseInt(liveId || 0, 10) || 0;
    meta = meta || {};
    surface = String(surface || detectHubSurfaceForLiveDoor());
    const url = new URL('live_door_hub.php', window.location.href);
    url.searchParams.set('hub_surface', surface);
    url.searchParams.set('can_studio', '1');
    url.searchParams.set('hub_door', 'right');
    url.searchParams.set('hub_tab', 'public');
    if (liveId > 0) url.searchParams.set('watch_live', String(liveId));
    if (meta.visibility) url.searchParams.set('watch_visibility', String(meta.visibility));
    if (meta.host) url.searchParams.set('watch_host', String(meta.host));
    if (meta.title) url.searchParams.set('watch_title', String(meta.title));
    if (meta.snapshot_version) url.searchParams.set('watch_snapshot', String(meta.snapshot_version));
    if (meta.host_door) url.searchParams.set('watch_host_door', String(meta.host_door));
    if (meta.studio_source) url.searchParams.set('watch_studio_source', String(meta.studio_source));
    const rightOwnerId = parseInt(meta.user_id || meta.owner_id || meta.ownerId || 0, 10) || 0;
    if (rightOwnerId > 0) url.searchParams.set('watch_owner', String(rightOwnerId));
    return url.href;
  }

  async function fetchHeaderLiveWatchMeta(liveId){
    liveId = parseInt(liveId || 0, 10) || 0;
    if (liveId <= 0) return null;
    try {
      const res = await fetch('ajax/live_watch_meta.php?live_id=' + encodeURIComponent(String(liveId)), {
        cache: 'no-store',
        credentials: 'same-origin'
      });
      const data = await res.json();
      if (!data || !data.ok || !data.live) return null;
      return data.live;
    } catch (error) {
      return null;
    }
  }

  async function openHeaderLiveDoorWatch(liveId, partialMeta){
    liveId = parseInt(liveId || 0, 10) || 0;
    if (liveId <= 0) return false;
    let meta = partialMeta && typeof partialMeta === 'object' ? Object.assign({}, partialMeta) : {};
    if (!meta.host_door && !meta.studio_source && !meta.watch_door) {
      const fetched = await fetchHeaderLiveWatchMeta(liveId);
      if (fetched) meta = Object.assign({}, fetched, meta);
    }
    const surface = detectHubSurfaceForLiveDoor();
    const watchDoor = resolveHeaderFriendWatchDoor(meta);
    const watchUrl = watchDoor === 'right'
      ? buildHeaderRightDoorWatchUrl(liveId, meta, surface)
      : buildHeaderLeftDoorWatchUrl(liveId, meta, surface);
    if (watchDoor === 'right') {
      if (window.TTLiveRight && typeof window.TTLiveRight.open === 'function') {
        window.TTLiveRight.open(watchUrl);
        return true;
      }
      try {
        window.postMessage({ type: 'msb-live-right-door-open', url: watchUrl }, '*');
        return true;
      } catch (error) {}
      return false;
    }
    if (window.TTLive && typeof window.TTLive.open === 'function') {
      window.TTLive.open(watchUrl);
      return true;
    }
    try {
      window.postMessage({ type: 'msb-live-door-open', url: watchUrl }, '*');
      return true;
    } catch (error) {}
    return false;
  }

  window.openHeaderLiveDoorWatch = openHeaderLiveDoorWatch;

  function syncGlobalLiveFrameSize(){
    if (!liveModalFrame || !liveModalSurface) return;
    liveModalFrame.style.width = '100%';
    liveModalFrame.style.height = '100%';
    if (liveModalSnapshot) {
      liveModalSnapshot.style.width = '100%';
      liveModalSnapshot.style.height = '100%';
    }
  }

  function stopGlobalLivePolling(){
    if (liveModalPollTimer) {
      clearInterval(liveModalPollTimer);
      liveModalPollTimer = null;
    }
  }

  function readGlobalLiveEmbedStageState(){
    if (!liveModalFrame) return null;
    try {
      const doc = liveModalFrame.contentDocument || (liveModalFrame.contentWindow && liveModalFrame.contentWindow.document);
      if (!doc) return null;
      const stage = doc.querySelector('.stage-screen');
      if (!stage) return null;
      const video = doc.getElementById('watchStageVideo');
      const isCompositeLayout = Array.from(stage.classList || []).some(function(className){
        return /^has-(?:dual|three|four|five|six|seven|eight|nine|ten|eleven|twelve|thirteen|fourteen|fifteen|sixteen|seventeen|eighteen|nineteen|twenty|twentyone|twentytwo|twentythree|twentyfour|twentyfive|twentyplus|gallery|host-layout)/.test(String(className || ''));
      });
      return {
        hasWebRtc: stage.classList.contains('has-webrtc'),
        hasSnapshot: stage.classList.contains('has-snapshot'),
        currentTime: video ? Number(video.currentTime || 0) : 0,
        paused: !!(video && video.paused),
        ended: !!(video && video.ended),
        readyState: video ? Number(video.readyState || 0) : 0,
        videoWidth: video ? Number(video.videoWidth || 0) : 0,
        videoStream: video ? (video.srcObject || null) : null,
        isCompositeLayout: isCompositeLayout
      };
    } catch (error) {
      return null;
    }
  }

  function clearGlobalLiveDirectStage(){
    liveDirectStageStream = null;
    if (liveModalStage) {
      liveModalStage.classList.remove('is-direct-stage');
    }
    if (!liveModalDirectVideo) {
      return false;
    }
    try {
      liveModalDirectVideo.pause();
      liveModalDirectVideo.srcObject = null;
    } catch (error) {}
    return false;
  }

  function setGlobalLiveDeviceButtonState(button, enabled, type) {
    if (!button) return;
    const icon = button.querySelector('i');
    button.classList.toggle('is-active', !!enabled);
    button.setAttribute('aria-pressed', enabled ? 'true' : 'false');
    if (type === 'microphone') {
      button.setAttribute('aria-label', enabled ? 'Turn microphone off' : 'Turn microphone on');
      if (icon) {
        icon.className = enabled ? 'fa fa-microphone' : 'fa fa-microphone has-off-slash';
      }
    } else {
      button.setAttribute('aria-label', enabled ? 'Turn camera off' : 'Turn camera on');
      if (icon) {
        icon.className = enabled ? 'fa fa-video-camera' : 'fa fa-video-camera has-off-slash';
      }
    }
  }

  function syncGlobalLiveCameraOffStage() {
    if (!liveModalStage) return;
    const shouldShowCameraOff = liveHeaderIsOwnerView
      ? !liveHeaderCameraEnabled
      : false;
    liveModalStage.classList.toggle('is-camera-off', shouldShowCameraOff);
  }

  function getGlobalLiveEmbedControlApi() {
    if (!liveModalFrame || !liveModalFrame.contentWindow) return null;
    try {
      const api = liveModalFrame.contentWindow.MSBLiveWatchControls;
      if (!api || typeof api.getState !== 'function') {
        return null;
      }
      return api;
    } catch (error) {
      return null;
    }
  }

  function syncGlobalLiveDeviceControls() {
    const api = getGlobalLiveEmbedControlApi();
    if (api) {
      try {
        if (typeof api.sync === 'function') {
          api.sync();
        }
        const state = api.getState() || {};
        liveHeaderIsOwnerView = !!state.isOwnerView;
        liveHeaderHostCameraEnabled = state.hostCameraEnabled !== false;
        liveHeaderMicEnabled = !!state.micEnabled;
        liveHeaderCameraEnabled = !!state.cameraEnabled;
        liveHeaderMirrorSelfView = !!state.mirrorSelfView;
        liveHeaderVideoQuality = String(state.videoQuality || 'auto');
        liveHeaderFrameRatePreference = Number(state.frameRatePreference || 24) >= 30 ? 30 : 24;
        liveHeaderSelectedCameraDeviceId = String(state.selectedCameraDeviceId || '');
        liveHeaderSelectedMicDeviceId = String(state.selectedMicDeviceId || '');
        liveHeaderSelectedSpeakerDeviceId = String(state.selectedSpeakerDeviceId || '');
        liveHeaderCameraDevices = Array.isArray(state.cameraDevices) ? state.cameraDevices : [];
        liveHeaderMicDevices = Array.isArray(state.micDevices) ? state.micDevices : [];
        liveHeaderSpeakerDevices = Array.isArray(state.speakerDevices) ? state.speakerDevices : [];
      } catch (error) {}
    }
    setGlobalLiveDeviceButtonState(liveMicToggle, liveHeaderMicEnabled, 'microphone');
    setGlobalLiveDeviceButtonState(liveCameraToggle, liveHeaderCameraEnabled, 'camera');
    syncGlobalLiveCameraOffStage();
    if (liveSettingsCameraDevice) {
      liveSettingsCameraDevice.innerHTML = '<option value="">System default camera</option>' + liveHeaderCameraDevices.map(function(device) {
        const selected = String(device.deviceId || '') === liveHeaderSelectedCameraDeviceId ? ' selected' : '';
        return '<option value="' + esc(String(device.deviceId || '')) + '"' + selected + '>' + esc(String(device.label || 'Camera')) + '</option>';
      }).join('');
    }
    if (liveSettingsMicDevice) {
      liveSettingsMicDevice.innerHTML = '<option value="">System default microphone</option>' + liveHeaderMicDevices.map(function(device) {
        const selected = String(device.deviceId || '') === liveHeaderSelectedMicDeviceId ? ' selected' : '';
        return '<option value="' + esc(String(device.deviceId || '')) + '"' + selected + '>' + esc(String(device.label || 'Microphone')) + '</option>';
      }).join('');
    }
    if (liveSettingsSpeakerDevice) {
      liveSettingsSpeakerDevice.innerHTML = '<option value="">System default output</option>' + liveHeaderSpeakerDevices.map(function(device) {
        const selected = String(device.deviceId || '') === liveHeaderSelectedSpeakerDeviceId ? ' selected' : '';
        return '<option value="' + esc(String(device.deviceId || '')) + '"' + selected + '>' + esc(String(device.label || 'Output')) + '</option>';
      }).join('');
    }
    if (liveSettingsAudio) {
      liveSettingsAudio.checked = !!liveHeaderMicEnabled;
    }
    if (liveSettingsCameraEnabled) {
      liveSettingsCameraEnabled.checked = !!liveHeaderCameraEnabled;
    }
    if (liveSettingsMirror) {
      liveSettingsMirror.checked = !!liveHeaderMirrorSelfView;
    }
    if (liveSettingsQuality) {
      liveSettingsQuality.value = liveHeaderVideoQuality;
    }
    if (liveSettingsFrameRate) {
      liveSettingsFrameRate.value = String(liveHeaderFrameRatePreference);
    }
  }

  function invokeGlobalLiveEmbedControl(action) {
    const api = getGlobalLiveEmbedControlApi();
    if (!api) {
      syncGlobalLiveDeviceControls();
      return;
    }
    try {
      const result = action === 'camera'
        ? (typeof api.toggleCamera === 'function' ? api.toggleCamera() : null)
        : (typeof api.toggleMicrophone === 'function' ? api.toggleMicrophone() : null);
      if (result && typeof result === 'object') {
        liveHeaderMicEnabled = !!result.micEnabled;
        liveHeaderCameraEnabled = !!result.cameraEnabled;
      }
    } catch (error) {}
    syncGlobalLiveDeviceControls();
  }

  function applyGlobalLiveEmbedSettings() {
    const api = getGlobalLiveEmbedControlApi();
    if (!api || typeof api.applySettings !== 'function') {
      syncGlobalLiveDeviceControls();
      return;
    }
    try {
      api.applySettings({
        audioEnabled: !!liveHeaderMicEnabled,
        cameraEnabled: !!liveHeaderCameraEnabled,
        mirrorSelfView: !!liveHeaderMirrorSelfView,
        videoQuality: liveHeaderVideoQuality,
        frameRatePreference: liveHeaderFrameRatePreference,
        selectedCameraDeviceId: liveHeaderSelectedCameraDeviceId,
        selectedMicDeviceId: liveHeaderSelectedMicDeviceId,
        selectedSpeakerDeviceId: liveHeaderSelectedSpeakerDeviceId
      });
      if (typeof api.applyOutputDevice === 'function') {
        api.applyOutputDevice(liveHeaderSelectedSpeakerDeviceId);
      }
      if (typeof api.restartLocalCapture === 'function') {
        api.restartLocalCapture();
      }
    } catch (error) {}
    syncGlobalLiveDeviceControls();
  }

  function syncGlobalLiveDirectStage(embedState){
    return clearGlobalLiveDirectStage();
  }

  function syncGlobalLiveStageSurface(){
    if (!liveModalStage) return;
    liveModalStage.classList.remove('use-snapshot-stage');
    liveModalStage.classList.remove('is-direct-stage');
    syncGlobalLiveCameraOffStage();
  }

  function stopGlobalLiveStageSync(){
    if (liveModalStageSyncTimer) {
      clearInterval(liveModalStageSyncTimer);
      liveModalStageSyncTimer = null;
    }
    liveEmbedVideoCurrentTime = 0;
    liveEmbedVideoLastAdvanceAt = 0;
    if (liveModalStage) {
      liveModalStage.classList.remove('use-snapshot-stage');
      liveModalStage.classList.remove('is-direct-stage');
      liveModalStage.classList.remove('is-camera-off');
    }
    clearGlobalLiveDirectStage();
  }

  function restartGlobalLiveStageSync(){
    stopGlobalLiveStageSync();
  }

  function setGlobalLiveSnapshotVisible(isVisible){
    if (!liveModalStage || !liveModalSnapshot) return;
    liveModalStage.classList.remove('has-snapshot-stage');
    liveModalSnapshot.removeAttribute('src');
    delete liveModalSnapshot.dataset.loadToken;
  }

  function setGlobalLiveSnapshotSource(url, onError){
    if (!liveModalSnapshot || !url) return;
    liveSnapshotLoadToken += 1;
    const token = 'global-live-snapshot-' + String(liveSnapshotLoadToken);
    liveModalSnapshot.dataset.loadToken = token;
    const loader = new Image();
    loader.onload = function(){
      if (liveModalSnapshot.dataset.loadToken !== token) return;
      liveModalSnapshot.src = url;
      setGlobalLiveSnapshotVisible(true);
    };
    loader.onerror = function(){
      if (liveModalSnapshot.dataset.loadToken !== token) return;
      if (typeof onError === 'function') onError();
    };
    loader.src = url;
  }

  function pullGlobalLiveSnapshotFrame(){
    setGlobalLiveSnapshotVisible(false);
  }

  function stopGlobalLiveSnapshotLoop(){
    if (liveModalSnapshotTimer) {
      clearInterval(liveModalSnapshotTimer);
      liveModalSnapshotTimer = null;
    }
    setGlobalLiveSnapshotVisible(false);
  }

  function restartGlobalLiveSnapshotLoop(){
    stopGlobalLiveSnapshotLoop();
  }

  function suspendBackgroundLiveEmbeds(){
    restoreBackgroundLiveEmbeds();
    suspendedLiveEmbedFrames = Array.from(document.querySelectorAll('iframe[src*="live_watch.php"]')).filter(function(frame){
      if (!frame || frame === liveModalFrame) return false;
      const currentSrc = String(frame.getAttribute('src') || '').trim();
      if (!currentSrc || currentSrc === 'about:blank') return false;
      return currentSrc.indexOf('embed=1') !== -1;
    }).map(function(frame){
      const src = String(frame.getAttribute('src') || '').trim();
      frame.setAttribute('src', 'about:blank');
      return { frame: frame, src: src };
    });
  }

  function restoreBackgroundLiveEmbeds(){
    if (!Array.isArray(suspendedLiveEmbedFrames) || !suspendedLiveEmbedFrames.length) {
      suspendedLiveEmbedFrames = [];
      return;
    }
    suspendedLiveEmbedFrames.forEach(function(entry){
      if (!entry || !entry.frame || !entry.src) return;
      entry.frame.setAttribute('src', entry.src);
    });
    suspendedLiveEmbedFrames = [];
  }

  function resetGlobalLiveStageEffects(){
    liveStageInitialized = false;
    liveSeenCommentIds = new Set();
    liveLastReactionCounts = { love:0, like:0, fire:0, wow:0, clap:0 };
    if (liveStageComments) liveStageComments.innerHTML = '';
    if (liveStageReactions) liveStageReactions.innerHTML = '';
  }

  function spawnGlobalLiveStageComment(item){
    if (!liveStageComments || !item) return;
    const author = String(item.author || 'User');
    const body = String(item.body || '').trim();
    if (!body) return;
    const wrap = document.createElement('div');
    wrap.className = 'global-live-stage-comment';
    wrap.style.top = `${20 + Math.random() * 42}%`;

    const avatar = document.createElement('div');
    avatar.className = 'global-live-stage-comment-avatar';
    avatar.textContent = initialsFrom(author);
    avatar.style.cssText += ';' + gradStyle(colorFromString(author));

    const card = document.createElement('div');
    card.className = 'global-live-stage-comment-card';

    const authorNode = document.createElement('div');
    authorNode.className = 'global-live-stage-comment-author';
    authorNode.textContent = author;

    const bodyNode = document.createElement('div');
    bodyNode.className = 'global-live-stage-comment-body';
    bodyNode.textContent = body;

    card.appendChild(authorNode);
    card.appendChild(bodyNode);
    wrap.appendChild(avatar);
    wrap.appendChild(card);
    liveStageComments.appendChild(wrap);
    window.setTimeout(() => wrap.remove(), 5200);
  }

  function liveReactionEmoji(reaction){
    const map = {
      like: '👍',
      love: '♥',
      clap: '🥰',
      wow: '😮',
      fire: '😡'
    };
    return map[String(reaction || '').toLowerCase()] || '♥';
  }

  function liveReactionBadgeHtml(reaction){
    const key = String(reaction || '').toLowerCase();
    if (key === 'love') return '<span class="live-love-heart" aria-hidden="true">&#10084;</span>';
    return esc(liveReactionEmoji(key));
  }

  function liveReactionLabel(reaction){
    const key = String(reaction || '').toLowerCase();
    if (key === 'like') return 'Like';
    if (key === 'love') return 'Love';
    if (key === 'clap') return 'Care';
    if (key === 'wow') return 'Wow';
    if (key === 'fire') return 'Angry';
    return 'Reaction';
  }

  function liveFriendActionMeta(status){
    const value = String(status || 'none');
    if (value === 'friends' || value === 'self') return { label: '', disabled: true, className: 'is-static' };
    if (value === 'outgoing_pending') return { label: 'Request sent', disabled: true, className: 'is-static' };
    if (value === 'incoming_pending') return { label: 'Accept request', disabled: true, className: 'is-static' };
    return { label: 'Add friend', disabled: false, className: '' };
  }

  function syncGlobalLiveSidebarHeader(){
    if (liveSidebarMode === 'reactions') {
      if (liveSidebarTitleText) liveSidebarTitleText.textContent = 'Reactions';
      if (liveSidebarTitleCount) {
        const total = ['love','like','fire','wow','clap'].reduce((sum, key) => sum + (parseInt(liveLastReactionCounts[key] || 0, 10) || 0), 0);
        liveSidebarTitleCount.textContent = String(total);
      }
      return;
    }
    if (liveSidebarMode === 'description') {
      if (liveSidebarTitleText) liveSidebarTitleText.textContent = 'Description';
      if (liveSidebarTitleCount) liveSidebarTitleCount.textContent = '';
      return;
    }
    if (liveSidebarMode === 'settings') {
      if (liveSidebarTitleText) liveSidebarTitleText.textContent = 'Settings';
      if (liveSidebarTitleCount) liveSidebarTitleCount.textContent = '';
      return;
    }
    if (liveSidebarTitleText) liveSidebarTitleText.textContent = 'Comments';
    if (liveSidebarTitleCount && liveCommentCount) liveSidebarTitleCount.textContent = liveCommentCount.textContent || '0';
  }

  function renderGlobalLiveReactionPanel(){
    if (!liveReactionTabs || !liveReactionList) return;
    const counts = liveLastReactionCounts || { love:0, like:0, fire:0, wow:0, clap:0 };
    const tabOrder = ['all','like','love','wow','clap','fire'];
    const total = ['love','like','fire','wow','clap'].reduce((sum, key) => sum + (parseInt(counts[key] || 0, 10) || 0), 0);
    liveReactionTabs.innerHTML = tabOrder.map((key) => {
      const count = key === 'all' ? total : (parseInt(counts[key] || 0, 10) || 0);
      const label = key === 'all' ? 'All' : (liveReactionBadgeHtml(key) + ' ' + esc(String(count)));
      return `<button type="button" class="global-live-reaction-tab${liveReactionFilter === key ? ' is-active' : ''}" data-reaction-filter="${key}">${label}${key === 'all' ? ` <span>${esc(String(count))}</span>` : ''}</button>`;
    }).join('');
    const filtered = liveReactionUsers.filter((item) => liveReactionFilter === 'all' ? true : String(item.reaction || '') === liveReactionFilter);
    if (!filtered.length) {
      liveReactionList.innerHTML = '<div class="global-live-reaction-empty">No reactions yet.</div>';
      syncGlobalLiveSidebarHeader();
      return;
    }
    liveReactionList.innerHTML = filtered.map((item) => {
      const name = esc(item.name || 'User');
      const initials = esc(initialsFrom(item.name || 'User'));
      const tone = globalCommentTone(item.name || 'User');
      const action = liveFriendActionMeta(item.friend_status);
      const actionHtml = action.label
        ? `<button type="button" class="global-live-reaction-action ${action.className}" data-reactor-action="friend" data-user-id="${parseInt(item.user_id || 0, 10) || 0}"${action.disabled ? ' disabled' : ''}><i class="fa fa-user-plus" aria-hidden="true"></i> ${esc(action.label)}</button>`
        : '';
      return `
        <div class="global-live-reaction-item" data-reaction-user="${parseInt(item.user_id || 0, 10) || 0}">
          <div class="global-live-reaction-avatar" style="background:linear-gradient(135deg, hsl(${tone} 80% 62%), hsl(${(tone + 38) % 360} 78% 54%));">${initials}<span class="global-live-reaction-badge">${liveReactionBadgeHtml(item.reaction)}</span></div>
          <div class="global-live-reaction-main">
            <div class="global-live-reaction-name">${name}</div>
            <div class="global-live-reaction-time"><span class="global-live-reaction-type">${esc(liveReactionLabel(item.reaction))}</span>${esc(item.created_at_label || 'Now')}</div>
          </div>
          ${actionHtml}
        </div>
      `;
    }).join('');
    syncGlobalLiveSidebarHeader();
  }

  async function sendGlobalLiveFriendRequest(peerId){
    const fd = new FormData();
    fd.append('peer_id', String(peerId));
    const res = await fetch('ajax/friend_action.php', {
      method: 'POST',
      body: fd,
      credentials: 'same-origin'
    });
    const data = await res.json();
    if (!data || !data.ok) {
      throw new Error(data && data.message ? data.message : 'Unable to send friend request.');
    }
    liveReactionUsers = liveReactionUsers.map((item) => {
      if ((parseInt(item.user_id || 0, 10) || 0) !== (parseInt(peerId || 0, 10) || 0)) return item;
      return Object.assign({}, item, { friend_status: String(data.status || 'outgoing_pending') });
    });
    renderGlobalLiveReactionPanel();
    return data;
  }

  function spawnGlobalLiveStageReaction(reaction){
    if (!liveStageReactions) return;
    const bubble = document.createElement('div');
    bubble.className = 'global-live-stage-reaction';
    if (String(reaction || '').toLowerCase() === 'love') {
      bubble.classList.add('is-love');
    }
    bubble.textContent = liveReactionEmoji(reaction);
    bubble.style.right = `${24 + Math.random() * 86}px`;
    bubble.style.bottom = `${60 + Math.random() * 34}px`;
    liveStageReactions.appendChild(bubble);
    window.setTimeout(() => bubble.remove(), 5200);
  }

  function renderGlobalLivePayload(data){
    const live = data && data.live ? data.live : {};
    const comments = Array.isArray(data && data.comments) ? data.comments : [];
    const counts = data && data.reaction_counts ? data.reaction_counts : {};
    let total = 0;
    ['love','like','fire','wow','clap'].forEach((key) => {
      total += parseInt(counts[key] || 0, 10) || 0;
    });

    const previousSnapshotVersion = liveSnapshotVersion;
    const previousSnapshotActive = liveSnapshotLiveActive;
    liveModalReaction = String(data && data.my_reaction ? data.my_reaction : '');
    liveJoinRequestStatus = String(data && data.join_request_status ? data.join_request_status : '');
    liveCanRequestJoin = !!(data && data.can_request_join);
    liveOwnerId = parseInt(live.owner_id || 0, 10) || 0;
    liveSnapshotVersion = String(live.snapshot_version || '');
    liveSnapshotLiveActive = String(live.status || '').toLowerCase() === 'live' && liveSnapshotVersion !== '';
    if (!liveSnapshotLiveActive) {
      stopGlobalLiveSnapshotLoop();
    } else if (liveModalSnapshotTimer && (liveSnapshotVersion !== previousSnapshotVersion || !previousSnapshotActive)) {
      restartGlobalLiveSnapshotLoop();
    }
    if (liveParticipantCount) liveParticipantCount.textContent = String(parseInt(live.viewer_count || 0, 10) || 0);
    if (liveViewCount) liveViewCount.textContent = String(parseInt(live.viewer_count || 0, 10) || 0);
    if (liveDockViewCount) liveDockViewCount.textContent = String(parseInt(live.viewer_count || 0, 10) || 0);
    if (liveReactionCount) liveReactionCount.textContent = String(total);
    if (liveCommentCount) liveCommentCount.textContent = String(comments.length);
    if (liveCommentHeaderCount) liveCommentHeaderCount.textContent = String(comments.length);
    if (liveSidebarTitleCount && liveSidebarMode !== 'reactions' && liveSidebarMode !== 'description') liveSidebarTitleCount.textContent = String(comments.length);
    if (liveToolbarCommentCount) liveToolbarCommentCount.textContent = String(comments.length);
    if (liveToolbarReactionCount) liveToolbarReactionCount.textContent = String(total);
    liveReactionUsers = Array.isArray(data && data.reaction_users) ? data.reaction_users : [];
    if (liveVisibilityBadge) liveVisibilityBadge.setAttribute('title', liveVisibilityText(live.visibility || 'private'));
    syncGlobalLiveDescription(live);
    if (liveOwnerTitle) {
      liveOwnerTitle.textContent = 'Chat';
      if (liveTopTitle) liveTopTitle.textContent = String(live.title || 'Live session');
    }
    if (liveOwnerAvatar) {
      const ownerName = String(live.owner_name || 'Live');
      const initials = initialsFrom(ownerName);
      liveOwnerAvatar.textContent = initials;
      liveOwnerAvatar.style.cssText = gradStyle(colorFromString(ownerName));
    }
    if (liveReactButton) {
      liveReactButton.classList.toggle('is-active', liveModalReaction === 'love');
    }
    if (liveQuickReactionButtons.length) {
      liveQuickReactionButtons.forEach((button) => {
        const reaction = String(button.getAttribute('data-live-room-reaction') || '');
        button.classList.toggle('is-active', reaction !== '' && reaction === liveModalReaction);
      });
    }
    if (liveJoinRequestButton) {
      const isOwnerView = currentHeaderUserId === liveOwnerId;
      liveJoinRequestButton.style.display = (!isOwnerView && liveCanRequestJoin) ? '' : 'none';
      liveJoinRequestButton.disabled = isOwnerView || !liveCanRequestJoin || String(live.status || '').toLowerCase() !== 'live' || liveJoinRequestStatus === 'requested';
      if (liveJoinRequestStatus === 'approved') {
        liveJoinRequestButton.textContent = 'Joined Live';
      } else if (liveJoinRequestStatus === 'requested') {
        liveJoinRequestButton.textContent = 'Request Sent';
      } else {
        liveJoinRequestButton.textContent = 'Request to Join';
      }
    }

    const nextCommentIds = new Set();
    comments.forEach((item) => {
      const id = parseInt(item && item.id ? item.id : 0, 10) || 0;
      if (id > 0) nextCommentIds.add(id);
      if (!liveStageInitialized) return;
      if (id > 0 && !liveSeenCommentIds.has(id)) {
        spawnGlobalLiveStageComment(item);
      }
    });

    ['love','like','fire','wow','clap'].forEach((key) => {
      const nextCount = parseInt(counts[key] || 0, 10) || 0;
      const prevCount = parseInt(liveLastReactionCounts[key] || 0, 10) || 0;
      if (liveStageInitialized && nextCount > prevCount) {
        const burst = Math.min(nextCount - prevCount, 3);
        for (let i = 0; i < burst; i += 1) {
          window.setTimeout(() => spawnGlobalLiveStageReaction(key), i * 220);
        }
      }
      liveLastReactionCounts[key] = nextCount;
    });
    renderGlobalLiveReactionPanel();
    syncGlobalLiveStageSurface();

    liveSeenCommentIds = nextCommentIds;
    liveStageInitialized = true;

    if (!liveCommentsBox || !liveCommentList) return;
    const shouldStickToBottom = (liveCommentList.scrollHeight - liveCommentList.scrollTop - liveCommentList.clientHeight) <= 48;
    if (!comments.length) {
      liveCommentsBox.classList.remove('has-comments');
      liveCommentList.innerHTML = 'Comments will appear here when viewers join the live session.';
      return;
    }

    liveCommentsBox.classList.add('has-comments');
    liveCommentList.innerHTML = comments.map((item) => {
      const author = esc(item.author || 'User');
      const body = esc(item.body || '');
      const initials = esc(initialsFrom(item.author || 'User'));
      const tone = globalCommentTone(item.author || 'User');
      const meta = esc(item.created_at_label || 'Now');
      const likeCount = parseInt(item.like_count || 0, 10) || 0;
      const likedByLabel = esc(item.liked_by_label || '');
      return `
        <div class="global-live-comment-card" data-comment-id="${parseInt(item.id || 0, 10) || 0}" data-comment-author="${author}">
          <div class="global-live-comment-avatar" style="background:linear-gradient(135deg, hsl(${tone} 80% 62%), hsl(${(tone + 38) % 360} 78% 54%));">${initials}</div>
          <div class="global-live-comment-main">
            <div class="global-live-comment-author">${author}</div>
            <div class="global-live-comment-body">${body}</div>
            <div class="global-live-comment-meta"><span>${meta}</span><a type="button" class="global-live-comment-reply">Reply</a><a type="button" class="global-live-comment-like${item.liked_by_me ? ' is-liked' : ''}" aria-label="Like comment" title="${likedByLabel}"><i class="fa fa-heart-o" aria-hidden="true"></i>${likeCount > 0 ? `<span class="global-live-comment-like-count">${likeCount}</span>` : ''}</a></div>
            ${likedByLabel ? `<div class="global-live-comment-likes">${likedByLabel}</div>` : ''}
          </div>
        </div>
      `;
    }).join('');
    if (shouldStickToBottom) {
      liveCommentList.scrollTop = liveCommentList.scrollHeight;
    }
  }

  async function pollGlobalLiveRoom(){
    if (!liveModalId) return;
    try{
      const res = await fetch('ajax/live_watch_room.php?live=' + encodeURIComponent(String(liveModalId)), {
        cache:'no-store',
        credentials:'same-origin'
      });
      const data = await res.json();
      if (data && data.ok) renderGlobalLivePayload(data);
    } catch(e){}
  }

  async function sendGlobalLiveComment(){
    if (!liveModalId || !liveCommentInput) return;
    const body = String(liveCommentInput.value || '').trim();
    if (!body) {
      setGlobalLiveComposeFeedback('Type a comment before sending.', 'error');
      return;
    }
    try{
      const fd = new FormData();
      fd.append('live_id', String(liveModalId));
      fd.append('action', 'send_comment');
      fd.append('comment_body', body);
      const res = await fetch('ajax/live_watch_room.php', {
        method:'POST',
        body:fd,
        credentials:'same-origin'
      });
      const data = await res.json();
      if (!data || !data.ok) throw new Error(data && data.error ? data.error : 'Unable to send comment');
      liveCommentInput.value = '';
      renderGlobalLivePayload(data);
      setGlobalLiveComposeFeedback('');
    } catch(e){
      setGlobalLiveComposeFeedback((e && e.message) || 'Unable to send comment', 'error');
    }
  }

  async function reactGlobalLive(reactionType){
    if (!liveModalId) return;
    try{
      const fd = new FormData();
      fd.append('live_id', String(liveModalId));
      fd.append('action', 'react_live');
      fd.append('reaction', String(reactionType || 'love'));
      const res = await fetch('ajax/live_watch_room.php', {
        method:'POST',
        body:fd,
        credentials:'same-origin'
      });
      const data = await res.json();
      if (!data || !data.ok) throw new Error(data && data.error ? data.error : 'Unable to save reaction');
      renderGlobalLivePayload(data);
    } catch(e){}
  }

  async function toggleGlobalLiveCommentLike(commentId){
    if (!liveModalId) return;
    try{
      const fd = new FormData();
      fd.append('live_id', String(liveModalId));
      fd.append('action', 'toggle_comment_like');
      fd.append('comment_id', String(commentId));
      const res = await fetch('ajax/live_watch_room.php', {
        method:'POST',
        body:fd,
        credentials:'same-origin'
      });
      const data = await res.json();
      if (!data || !data.ok) throw new Error(data && data.error ? data.error : 'Unable to update comment like');
      renderGlobalLivePayload(data);
    } catch(e){
      setGlobalLiveComposeFeedback((e && e.message) || 'Unable to update comment like', 'error');
    }
  }

  async function reactGlobalLiveLove(){
    return reactGlobalLive('love');
  }

  async function requestJoinGlobalLive(){
    if (!liveModalId || !liveJoinRequestButton) return;
    try{
      const fd = new FormData();
      fd.append('live_id', String(liveModalId));
      fd.append('action', 'request_join');
      const res = await fetch('ajax/live_watch_room.php', {
        method:'POST',
        body:fd,
        credentials:'same-origin'
      });
      const data = await res.json();
      if (!data || !data.ok) throw new Error(data && data.error ? data.error : 'Unable to send request');
      renderGlobalLivePayload(data);
      setGlobalLiveComposeFeedback('Join request sent.', 'success');
    } catch(e){
      setGlobalLiveComposeFeedback((e && e.message) || 'Unable to send request', 'error');
    }
  }

  function openHeaderLiveModal(url, explicitLiveId){
    if (!liveModal || !liveModalFrame) return false;
    const fallbackLiveId = parseInt(explicitLiveId || 0, 10) || 0;
    liveModalId = parseLiveId(url);
    if (!liveModalId && fallbackLiveId > 0) {
      liveModalId = fallbackLiveId;
      url = liveWatchUrlFromId(liveModalId);
    }
    if (!url || !liveModalId) return false;
    const embedUrl = new URL(toEmbedLiveUrl(url), window.location.href);
    embedUrl.searchParams.set('modal_instance', String(Date.now()));
    suspendBackgroundLiveEmbeds();
    clearGlobalLiveDirectStage();
    liveModalFrame.src = embedUrl.toString();
    resetGlobalLiveStageEffects();
    liveDescriptionState = createDefaultGlobalLiveDescriptionState();
    liveSnapshotLiveActive = false;
    liveSnapshotVersion = '';
    liveHeaderMicEnabled = false;
    liveHeaderCameraEnabled = true;
    liveHeaderHostCameraEnabled = true;
    liveHeaderIsOwnerView = false;
    liveHeaderMirrorSelfView = false;
    liveHeaderVideoQuality = 'auto';
    liveHeaderFrameRatePreference = 24;
    liveHeaderSelectedCameraDeviceId = '';
    liveHeaderSelectedMicDeviceId = '';
    liveHeaderSelectedSpeakerDeviceId = '';
    liveHeaderCameraDevices = [];
    liveHeaderMicDevices = [];
    liveHeaderSpeakerDevices = [];
    syncGlobalLiveDescription();
    liveModal.classList.add('is-open');
    liveModal.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
    setGlobalLiveSidebarMode('chat');
    setGlobalLiveConfirmOpen(false);
    setGlobalLiveComposeFeedback('');
    restartGlobalLiveStageSync();
    restartGlobalLiveSnapshotLoop();
    syncGlobalLiveDeviceControls();
    window.requestAnimationFrame(syncGlobalLiveFrameSize);
    window.setTimeout(syncGlobalLiveFrameSize, 50);
    window.setTimeout(syncGlobalLiveFrameSize, 180);
    pollGlobalLiveRoom();
    stopGlobalLivePolling();
    liveModalPollTimer = window.setInterval(pollGlobalLiveRoom, 4000);
    return true;
  }

  function closeHeaderLiveModal(){
    if (!liveModal || !liveModalFrame) return;
    stopGlobalLivePolling();
    stopGlobalLiveStageSync();
    liveSnapshotLiveActive = false;
    liveSnapshotVersion = '';
    stopGlobalLiveSnapshotLoop();
    liveModalId = 0;
    liveModalReaction = '';
    liveHeaderMicEnabled = false;
    liveHeaderCameraEnabled = true;
    liveHeaderHostCameraEnabled = true;
    liveHeaderIsOwnerView = false;
    liveHeaderMirrorSelfView = false;
    liveHeaderVideoQuality = 'auto';
    liveHeaderFrameRatePreference = 24;
    liveHeaderSelectedCameraDeviceId = '';
    liveHeaderSelectedMicDeviceId = '';
    liveHeaderSelectedSpeakerDeviceId = '';
    liveHeaderCameraDevices = [];
    liveHeaderMicDevices = [];
    liveHeaderSpeakerDevices = [];
    resetGlobalLiveStageEffects();
    liveModal.classList.remove('is-open');
    liveModal.setAttribute('aria-hidden', 'true');
    liveModalFrame.src = 'about:blank';
    liveModalFrame.style.width = '';
    liveModalFrame.style.height = '';
    clearGlobalLiveDirectStage();
    restoreBackgroundLiveEmbeds();
    document.body.style.overflow = '';
    setGlobalLiveSidebarMode('');
    setGlobalLiveConfirmOpen(false);
    setGlobalLiveComposeFeedback('');
    syncGlobalLiveDeviceControls();
  }

  window.openHeaderLiveModal = openHeaderLiveModal;
  window.closeHeaderLiveModal = closeHeaderLiveModal;

  function esc(s) {
    return String(s || '').replace(/[&<>"']/g, (m) => ({
      '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'
    }[m]));
  }

  function initialsFrom(nameOrCode){
    const t = String(nameOrCode || '').trim();
    if(!t) return '??';
    const parts = t.split(/\s+/).filter(Boolean);
    if(parts.length === 1) return parts[0].substring(0,2).toUpperCase();
    return (parts[0].substring(0,1) + parts[parts.length-1].substring(0,1)).toUpperCase();
  }

  function globalCommentTone(name){
    const raw = String(name || '');
    let total = 0;
    for (let i = 0; i < raw.length; i += 1) total += raw.charCodeAt(i);
    return total % 360;
  }

  function formatChatTime(value){
    const raw = String(value || '').trim();
    if (!raw) return '';
    const parsed = new Date(raw.replace(' ', 'T'));
    if (Number.isNaN(parsed.getTime())) return raw;
    return parsed.toLocaleString([], {
      month: 'short',
      day: '2-digit',
      hour: 'numeric',
      minute: '2-digit'
    });
  }

  function updateChatMeta(totalUnread, namedCount, unknownCount){
    totalUnread = parseInt(totalUnread || 0, 10);
    namedCount = parseInt(namedCount || 0, 10);
    unknownCount = parseInt(unknownCount || 0, 10);
    const summary = totalUnread > 0
      ? `${totalUnread} unread message${totalUnread === 1 ? '' : 's'}`
      : 'Inbox is clear';
    const detail = `${namedCount} named conversation${namedCount === 1 ? '' : 's'}`;
    const unknownText = unknownCount > 0
      ? `Unknown friend codes: ${unknownCount}`
      : 'All messages are named';

    chatSummaryNodes().forEach((node) => { node.textContent = summary; });
    chatDetailNodes().forEach((node) => { node.textContent = detail; });
    chatUnknownNodes().forEach((node) => {
      node.textContent = unknownText;
      node.classList.toggle('is-warn', unknownCount > 0);
    });
  }

  function chatEmptyHtml(){
    return '<div class="dropdown-bestchat-empty">No new messages. Unread conversations will appear here when someone writes to you.</div>';
  }

  function chatUnknownHtml(unknownCount){
    if (unknownCount <= 0) return '';
    return `
      <a href="messages.php" class="bestchat-menu-item bestchat-menu-item-unknown">
        <span class="bestchat-menu-icon" aria-hidden="true"><i class="icon ion-alert-circled"></i></span>
        <span class="bestchat-menu-body">
          <span class="bestchat-menu-name">Unknown friend codes</span>
          <span class="bestchat-menu-text">You have ${unknownCount} unread message(s). Open Messages to name them.</span>
        </span>
      </a>
    `;
  }

  function renderDropdown(items, unknownCount, totalUnread) {
    const lists = chatLists();
    const named = (items || []).filter(it => String(it.contact_name || '').trim() !== '');

    updateChatMeta(totalUnread, named.length, unknownCount);
    if (!lists.length) return;

    if ((!named || named.length === 0) && unknownCount <= 0) {
      const html = chatEmptyHtml();
      lists.forEach((list) => { list.innerHTML = html; });
      return;
    }

    const unknownBanner = chatUnknownHtml(unknownCount);

    const html = unknownBanner + named.map(it => {
      const peerCode = esc(it.peer_code);
      const display  = (it.peer_display || it.peer_code || '');
      const name     = esc(display);
      const msg      = esc(it.last_message || 'Open conversation');
      const time     = esc(formatChatTime(it.last_time || ''));
      const unread   = parseInt(it.unread_count || 0, 10);
      const base     = colorFromString(display);
      const style    = esc(gradStyle(base));
      const href     = 'messages.php?peer=' + encodeURIComponent(it.peer_code || '');
      const avatarUrl = 'avatar.php?friend_code=' + encodeURIComponent(it.peer_code || '') + '&name=' + encodeURIComponent(display || it.peer_code || '');
      const subline  = esc(peerCode + (time ? ` • ${time}` : ''));

      return `
        <a href="${href}" class="bestchat-menu-item">
          <span class="bestchat-menu-avatar" style="${style}" aria-hidden="true"><img src="${esc(avatarUrl)}" alt=""></span>
          <span class="bestchat-menu-body">
            <span class="bestchat-menu-head">
              <span class="bestchat-menu-name">${name}</span>
              ${unread > 0 ? `<span class="bestchat-badge">${unread > 99 ? '99+' : unread}</span>` : ``}
            </span>
            <span class="bestchat-menu-sub">${subline}</span>
            <span class="bestchat-menu-text">${msg}</span>
          </span>
        </a>
      `;
    }).join('');

    lists.forEach((list) => {
      list.innerHTML = html;
    });
  }

  let busy = false;
  async function pollUnreadThreads() {
    if (busy) return;
    busy = true;
    try {
      const res = await fetch('ajax/user_chat_poll.php?mode=unread_threads', { cache: 'no-store' });
      const data = await res.json();
      if (data && data.ok) {
        renderDropdown(data.items || [], data.unknown_unread || 0, data.total_unread || 0);
        setBadge(data.total_unread || 0);
      }
    } catch (e) {
    } finally {
      busy = false;
    }
  }

  pollUnreadCount();
  pollUnreadThreads();
  pollNotificationCount();
  pollActiveLiveRooms();
  setInterval(() => { pollUnreadCount(); pollUnreadThreads(); pollNotificationCount(); pollActiveLiveRooms(); }, 4000);

  document.addEventListener('click', function(e){
    const createTrigger = e.target.closest('[data-create-post-modal]');
    if (createTrigger) {
      e.preventDefault();
      var modalSrc = createTrigger.getAttribute('href') || createTrigger.getAttribute('data-modal-src') || '';
      if (!modalSrc || !/[?&]edit=\d+/i.test(modalSrc)) {
        var card = createTrigger.closest('[data-edit-url], .public-post-card, .mf-card');
        var fromCard = card ? String(card.getAttribute('data-edit-url') || '') : '';
        if (fromCard) modalSrc = fromCard;
        if ((!modalSrc || !/[?&]edit=\d+/i.test(modalSrc)) && createTrigger.classList.contains('pcm-edit') && card) {
          var pid = parseInt(card.getAttribute('data-post-id') || card.getAttribute('data-id') || '0', 10) || 0;
          if (pid > 0) modalSrc = 'dashboard.php?modal=1&edit=' + String(pid);
        }
      }
      var isEditOpen = /(?:^|[?&#])edit=\d+/i.test(String(modalSrc || ''));
      /* Header "+" toggles: open slowly, click again to close slowly. */
      if (!isEditOpen && isCreatePostModalOpen()) {
        closeCreatePostModal();
        return;
      }
      openCreatePostModal(modalSrc || 'dashboard.php?modal=1');
      return;
    }

    const item = e.target.closest('.dropdown-bestnoti-item[data-notification-id]');
    if (item) {
      const url = String(item.getAttribute('data-notification-url') || '').trim();
      const liveId = parseInt(item.getAttribute('data-live-id') || '0', 10) || 0;
      const id = String(item.getAttribute('data-notification-id') || '').trim();
      if (url || liveId > 0) {
        if (isLiveModalUrl(url) || liveId > 0) {
          e.preventDefault();
          if (id && navigator.sendBeacon) {
            try {
              const fd = new FormData();
              fd.append('id', id);
              navigator.sendBeacon('ajax/user_mark_read.php', fd);
            } catch (err) {}
          }
          if (item) item.classList.remove('is-unread');
          openHeaderLiveDoorWatch(liveId).then(function(opened){
            if (!opened) {
              openHeaderLiveModal(url || liveWatchUrlFromId(liveId), liveId);
            }
          });
          return;
        }

        // Tagged / mentioned story: open the story circle door.
        let openPostId = parseInt(item.getAttribute('data-post-id') || '0', 10) || 0;
        if (!openPostId && url) {
          try {
            const u = new URL(url, window.location.href);
            openPostId = parseInt(u.searchParams.get('story_post') || u.searchParams.get('open_post') || u.searchParams.get('post') || '0', 10) || 0;
          } catch (eUrl) {}
        }
        let isStoryNoti = String(item.getAttribute('data-is-story') || '') === '1';
        if (!isStoryNoti && url) {
          try {
            const uStory = new URL(url, window.location.href);
            isStoryNoti = !!uStory.searchParams.get('story_post');
          } catch (eStoryUrl) {}
        }
        if (!isStoryNoti) {
          try {
            var textProbe = '';
            var textNodeProbe = item.querySelector('.bestnoti-text');
            if (textNodeProbe) textProbe = String(textNodeProbe.textContent || '').toLowerCase();
            isStoryNoti = textProbe.indexOf(' in a story') !== -1;
          } catch (eText) {}
        }
        if (openPostId > 0 && isStoryNoti) {
          e.preventDefault();
          e.stopPropagation();
          if (id && navigator.sendBeacon) {
            try {
              const fd = new FormData();
              fd.append('id', id);
              navigator.sendBeacon('ajax/user_mark_read.php', fd);
            } catch (err) {}
          }
          if (item) item.classList.remove('is-unread');
          try {
            if (window.TTNotifications && typeof window.TTNotifications.close === 'function') {
              window.TTNotifications.close();
            }
          } catch (eClose) {}
          var openedStory = false;
          try {
            if (window.TTStories && typeof window.TTStories.openByPostId === 'function') {
              openedStory = !!window.TTStories.openByPostId(openPostId);
            }
          } catch (eOpenStory) {
            openedStory = false;
          }
          if (!openedStory) {
            var storyDest = url && url !== '#' ? url : ('home.php?tab=for-you&story_post=' + openPostId);
            try { window.location.href = storyDest; } catch (eNavStory) {}
          }
          return;
        }

        // Tagged / post alerts: open View-the-post modal (same as fries → View the post).
        if (!openPostId && url) {
          try {
            const u = new URL(url, window.location.href);
            openPostId = parseInt(u.searchParams.get('open_post') || u.searchParams.get('post') || '0', 10) || 0;
          } catch (eUrl2) {}
        }
        if (openPostId > 0) {
          e.preventDefault();
          e.stopPropagation();
          if (id && navigator.sendBeacon) {
            try {
              const fd = new FormData();
              fd.append('id', id);
              navigator.sendBeacon('ajax/user_mark_read.php', fd);
            } catch (err) {}
          }
          if (item) item.classList.remove('is-unread');
          try {
            if (window.TTNotifications && typeof window.TTNotifications.close === 'function') {
              window.TTNotifications.close();
            }
          } catch (eClose) {}
          var hideNav = false;
          try {
            var notiKind = String(item.getAttribute('data-noti-kind') || '');
            var notiText = '';
            var textNode = item.querySelector('.bestnoti-text');
            if (textNode) notiText = String(textNode.textContent || '').toLowerCase();
            if (notiKind === 'mentions'
              || notiText.indexOf('tagged you') !== -1
              || notiText.indexOf('mentioned you') !== -1
              || notiText.indexOf('mention') !== -1) {
              hideNav = true;
            }
            if (!hideNav && url) {
              var uNav = new URL(url, window.location.href);
              hideNav = uNav.searchParams.get('hide_nav') === '1';
            }
          } catch (eHide) {}
          var openOpts = hideNav ? { hideNav: true } : {};
          var opened = false;
          try {
            if (window.MSBPostCardMenu && typeof window.MSBPostCardMenu.openViewPost === 'function') {
              opened = !!window.MSBPostCardMenu.openViewPost(openPostId, openOpts);
            }
            if (!opened && typeof window.pvOpenById === 'function') {
              var pvRes = window.pvOpenById(openPostId, openOpts);
              var ov = document.getElementById('pvOverlay');
              opened = (pvRes !== false) && !!(ov && ov.classList.contains('show'));
            }
            if (!opened) {
              var pcmOv = document.getElementById('pcmViewPostOverlay');
              opened = !!(pcmOv && pcmOv.classList.contains('is-open'));
            }
          } catch (eOpen) {
            opened = false;
          }
          if (!opened) {
            var dest = url && url !== '#' ? url : ('home.php?tab=for-you&open_post=' + openPostId + (hideNav ? '&hide_nav=1' : ''));
            try { window.location.href = dest; } catch (eNav) {}
          }
          return;
        }

        if (id && navigator.sendBeacon) {
          try {
            const fd = new FormData();
            fd.append('id', id);
            navigator.sendBeacon('ajax/user_mark_read.php', fd);
          } catch (err) {}
        }
        if (item) item.classList.remove('is-unread');
        return;
      }
      e.preventDefault();
      markNotificationRead(id, item);
      return;
    }
    const markAllTrigger = e.target.closest('#headerNotificationMarkAll, #headerNotificationMarkAllTop');
    if (markAllTrigger) {
      e.preventDefault();
      markAllNotificationsRead();
      return;
    }

    if (createPostModal && createPostModal.classList.contains('is-open')) {
      const insideDialog = e.target.closest('.create-post-dialog');
      if (!insideDialog) {
        e.preventDefault();
        closeCreatePostModal();
        return;
      }
    }
  });

  if (createPostModalClose) {
    createPostModalClose.addEventListener('click', function(){
      closeCreatePostModal();
    });
  }

  if (createPostReadonlyClose) {
    createPostReadonlyClose.addEventListener('click', function(){
      closeCreatePostModal();
    });
  }

  if (createPostModalFrame) {
    createPostModalFrame.addEventListener('load', function(){
      if (!createPostModal || !createPostModal.classList.contains('is-open')) return;
      if (createPostModal.classList.contains('is-closing')) return;
      try {
        /* Do not park/reset mid-open — that caused freeze. Only refine height to the form. */
        setTimeout(function(){ fitCreatePostDoorToForm(); }, 30);
        setTimeout(function(){ fitCreatePostDoorToForm(); }, 180);
        setTimeout(function(){ fitCreatePostDoorToForm(); }, 450);
        const doc = createPostModalFrame.contentDocument;
        const win = createPostModalFrame.contentWindow;
        if (!doc || !doc.documentElement) return;
        doc.documentElement.scrollTop = 0;
        if (doc.body) doc.body.scrollTop = 0;

        /* Keep create-post iframe in sync with Gear Appearance color on the parent page */
        const parentRoot = document.documentElement;
        const childRoot = doc.documentElement;
        const appearance = parentRoot.getAttribute('data-msb-appearance') || '';
        const orgLight = parentRoot.getAttribute('data-msb-org-light');
        const themeAuto = parentRoot.getAttribute('data-msb-theme-auto');
        if (appearance) {
          childRoot.setAttribute('data-msb-appearance', appearance);
          childRoot.classList.add('msb-palette-active', 'msb-palette-unified');
          childRoot.removeAttribute('data-msb-org-light');
        } else {
          childRoot.removeAttribute('data-msb-appearance');
          childRoot.classList.remove('msb-palette-active', 'msb-palette-unified');
          if (orgLight) childRoot.setAttribute('data-msb-org-light', orgLight);
          else childRoot.removeAttribute('data-msb-org-light');
        }
        if (themeAuto) childRoot.setAttribute('data-msb-theme-auto', themeAuto);
        else childRoot.removeAttribute('data-msb-theme-auto');
        if (parentRoot.classList.contains('dark-auto')) childRoot.classList.add('dark-auto');
        else childRoot.classList.remove('dark-auto');
        const parentTheme = parentRoot.getAttribute('data-theme');
        if (parentTheme) childRoot.setAttribute('data-theme', parentTheme);

        const cs = window.getComputedStyle(parentRoot);
        for (let i = 0; i < cs.length; i++) {
          const prop = cs[i];
          if (!prop || prop.indexOf('--') !== 0) continue;
          if (prop.indexOf('--msb-') === 0
            || prop.indexOf('--bg-') === 0
            || prop.indexOf('--org-') === 0
            || prop.indexOf('--public-') === 0
            || prop.indexOf('--feed-') === 0
            || prop === '--blue'
            || prop === '--text-primary'
            || prop === '--shadow'
            || prop === '--shadow-strong') {
            childRoot.style.setProperty(prop, cs.getPropertyValue(prop));
          }
        }

        if (win && win.__MSBThemeCore && typeof win.__MSBThemeCore.applyThemeFromPrefs === 'function') {
          try {
            const prefs = (typeof win.__MSBThemeCore.readPrefs === 'function')
              ? win.__MSBThemeCore.readPrefs()
              : null;
            if (prefs && appearance) {
              prefs.appearanceMode = appearance;
              prefs.autoEnabled = false;
            }
            win.__MSBThemeCore.applyThemeFromPrefs(prefs || undefined);
            if (typeof win.__MSBThemeCore.refreshThemePaint === 'function') {
              win.__MSBThemeCore.refreshThemePaint();
            }
          } catch (_themeErr) {}
        }
      } catch (_e) {}
    });
  }

  if (liveModalClose) {
    liveModalClose.addEventListener('click', function(){
      setGlobalLiveConfirmOpen(true);
    });
  }

  if (liveTopClose && liveModalClose) {
    liveTopClose.addEventListener('click', function(){
      liveModalClose.click();
    });
  }

  document.addEventListener('keydown', function(e){
    if (e.key === 'Escape' && createPostModal && createPostModal.classList.contains('is-open')) {
      closeCreatePostModal();
    }
  });

  window.MSBCreatePostModal = {
    open: openCreatePostModal,
    close: closeCreatePostModal
  };

  window.addEventListener('resize', function(){
    if (createPostModal && createPostModal.classList.contains('is-open')) {
      syncCreatePostDropGeometry();
    }
  });

  // After create/edit: close modal, then land by entry + Friends/Public:
  // story circle "+" → story circle; left-nav "+" → post card.
  window.addEventListener('message', function(ev){
    var data = ev && ev.data;
    if (data && data.type === 'msb-create-post-fit') {
      try {
        fitCreatePostDoorToForm({ forceAnimate: !!(data && data.force), duration: 420 });
      } catch (_fitMsg) {}
      return;
    }
    if (!data || data.type !== 'msb-create-post-done') return;
    try { closeCreatePostModal(); } catch (err) {}
    var postId = Number(data.postId || 0);
    var redirect = String(data.redirect || '');
    var visibility = String(data.visibility || '').toLowerCase();
    var isStory = !!data.story;
    var redirectPath = '';
    try {
      redirectPath = String((new URL(redirect, window.location.href)).pathname || '').split('/').pop() || '';
    } catch (errUrl) {
      redirectPath = redirect.split('?')[0].split('/').pop() || '';
    }
    if (!redirectPath && data.surface) {
      redirectPath = String(data.surface);
    }
    // Rebuild destination if parent/server redirect is missing or on the wrong surface.
    var pathNowEarly = String(window.location.pathname || '');
    var onProfile = /profile\.php$/i.test(pathNowEarly);
    if (isStory && onProfile) {
      try {
        var profileUrl = new URL(window.location.href);
        profileUrl.searchParams.set('story_post', String(postId || ''));
        profileUrl.searchParams.set('fresh', '1');
        profileUrl.searchParams.set('tab', 'gallery');
        if (visibility === 'private') {
          profileUrl.searchParams.set('gallery_vis', 'private');
        }
        redirect = profileUrl.pathname.split('/').pop() + '?' + profileUrl.searchParams.toString();
        redirectPath = 'profile.php';
      } catch (errProfile) {
        redirectPath = 'profile.php';
        redirect = visibility === 'private'
          ? ('profile.php?tab=gallery&gallery_vis=private&story_post=' + encodeURIComponent(String(postId || '')) + '&fresh=1')
          : ('profile.php?tab=gallery&story_post=' + encodeURIComponent(String(postId || '')) + '&fresh=1');
      }
    } else if (isStory) {
      if (visibility === 'private') {
        redirectPath = 'profile.php';
        redirect = 'profile.php?tab=gallery&gallery_vis=private&story_post=' + encodeURIComponent(String(postId || '')) + '&fresh=1';
      } else if (visibility === 'public') {
        redirectPath = 'home.php';
        redirect = 'home.php?tab=discover&story_post=' + encodeURIComponent(String(postId || '')) + '&fresh=1';
      } else {
        redirectPath = 'home.php';
        redirect = 'home.php?tab=for-you&story_post=' + encodeURIComponent(String(postId || '')) + '&fresh=1';
      }
    } else if (visibility === 'private') {
      redirectPath = 'profile.php';
      redirect = 'profile.php?tab=gallery&gallery_vis=private&post=' + encodeURIComponent(String(postId || '')) + '&fresh=1';
    } else if (visibility === 'public' && !/home\.php$/i.test(redirectPath) && !/public\.php$/i.test(redirectPath) && !/news\.php$/i.test(redirectPath)) {
      redirectPath = 'home.php';
      redirect = 'home.php?tab=discover&post=' + encodeURIComponent(String(postId || '')) + '&fresh=1';
    } else if (visibility === 'friends' && !/home\.php$/i.test(redirectPath) && !/feed\.php$/i.test(redirectPath)) {
      redirectPath = 'home.php';
      redirect = 'home.php?tab=for-you&post=' + encodeURIComponent(String(postId || '')) + '&fresh=1';
    }

    var pathNow = String(window.location.pathname || '');
    var onFeed = /feed\.php$/i.test(pathNow) || (/home\.php$/i.test(pathNow) && (function(){
      try { return (new URL(window.location.href).searchParams.get('tab') || 'for-you') === 'for-you'; }
      catch (e) { return false; }
    })());
    var onPublic = /public\.php$/i.test(pathNow) || (/home\.php$/i.test(pathNow) && (function(){
      try {
        var t = new URL(window.location.href).searchParams.get('tab') || 'for-you';
        return t !== 'for-you';
      } catch (e) { return false; }
    })());
    var onProfile = /profile\.php$/i.test(pathNow);
    var wantsFeed = /feed\.php$/i.test(redirectPath) || (/home\.php$/i.test(redirectPath) && /tab=for-you/i.test(redirect));
    var wantsPublic = /public\.php$/i.test(redirectPath) || (/home\.php$/i.test(redirectPath) && /tab=discover/i.test(redirect));
    var wantsProfile = /profile\.php$/i.test(redirectPath);
    var wantsNews = /news\.php$/i.test(redirectPath) || (/home\.php$/i.test(redirectPath) && /tab=news/i.test(redirect));
    var switchingSurface = (onFeed && !wantsFeed) || (onPublic && !wantsPublic) || (onProfile && !wantsProfile) || (wantsNews && !/news\.php$/i.test(pathNow) && !(/home\.php$/i.test(pathNow) && /tab=news/i.test(window.location.search || ''))) || (wantsProfile && !onProfile);

    // Moving Friends ↔ Public: drop the card on the current page, then open the destination.
    if (switchingSurface && postId > 0) {
      try {
        document.querySelectorAll('.mf-card[data-id="' + String(postId) + '"]').forEach(function(node){ node.remove(); });
        document.querySelectorAll('.public-post-card[data-post-id="' + String(postId) + '"]').forEach(function(node){ node.remove(); });
      } catch (errRm) {}
    }

    // A newly created Friends post must be visible immediately. The former
    // soft-insert path could miss the post while its media/list row was still
    // settling, leaving For You stale until a manual refresh. Use the server's
    // fresh/pinned destination so the parent refreshes automatically and the
    // new card is guaranteed to be present at the top.
    if (onFeed && wantsFeed && !switchingSurface && !data.story && typeof window.MSBFeedOnPostCreated === 'function') {
      var freshFeedTarget = redirect || ('home.php?tab=for-you&post=' + encodeURIComponent(String(postId || '')) + '&fresh=1');
      try { window.location.replace(freshFeedTarget); }
      catch (err2) { window.location.href = freshFeedTarget; }
      return;
    }

    if (redirect) {
      try { window.location.replace(redirect); } catch (err3) { window.location.href = redirect; }
      return;
    }
    if (wantsPublic) {
      window.location.replace('home.php?tab=discover&post=' + encodeURIComponent(String(postId || '')) + '&fresh=1');
    } else if (wantsNews) {
      window.location.replace('home.php?tab=news&post=' + encodeURIComponent(String(postId || '')) + '&fresh=1');
    } else {
      window.location.replace('home.php?tab=for-you&post=' + encodeURIComponent(String(postId || '')) + '&fresh=1');
    }
  });

  if (liveConfirmCancel) {
    liveConfirmCancel.addEventListener('click', function(){
      setGlobalLiveConfirmOpen(false);
    });
  }

  if (liveConfirmOk) {
    liveConfirmOk.addEventListener('click', function(){
      setGlobalLiveConfirmOpen(false);
      closeHeaderLiveModal();
    });
  }

  if (liveSidebarClose) {
    liveSidebarClose.addEventListener('click', function(){ setGlobalLiveSidebarMode(''); });
  }

  const liveSpeakerTop = document.getElementById('globalLiveSpeakerTop');
  if (liveSpeakerTop) {
    liveSpeakerTop.addEventListener('click', function(){ setGlobalLiveSidebarOpen(false); });
  }

  if (liveChatToggle) {
    liveChatToggle.addEventListener('click', function(){
      const isChatOpen = !!(liveModalDialog && liveModalDialog.classList.contains('has-chat') && liveSidebarMode === 'chat');
      setGlobalLiveSidebarMode(isChatOpen ? '' : 'chat');
    });
  }

  if (liveMicToggle) {
    liveMicToggle.addEventListener('click', function(event){
      event.preventDefault();
      invokeGlobalLiveEmbedControl('microphone');
    });
  }

  if (liveCameraToggle) {
    liveCameraToggle.addEventListener('click', function(event){
      event.preventDefault();
      invokeGlobalLiveEmbedControl('camera');
    });
  }

  if (liveSettingsToggle) {
    liveSettingsToggle.addEventListener('click', function(event){
      event.preventDefault();
      const isOpen = !!(liveModalDialog && liveModalDialog.classList.contains('has-chat') && liveSidebarMode === 'settings');
      setGlobalLiveSidebarMode(isOpen ? '' : 'settings');
      syncGlobalLiveDeviceControls();
    });
  }

  if (liveSettingsAudio) {
    liveSettingsAudio.addEventListener('change', function(){
      liveHeaderMicEnabled = !!liveSettingsAudio.checked;
      applyGlobalLiveEmbedSettings();
    });
  }

  if (liveSettingsCameraEnabled) {
    liveSettingsCameraEnabled.addEventListener('change', function(){
      liveHeaderCameraEnabled = !!liveSettingsCameraEnabled.checked;
      applyGlobalLiveEmbedSettings();
    });
  }

  if (liveSettingsMirror) {
    liveSettingsMirror.addEventListener('change', function(){
      liveHeaderMirrorSelfView = !!liveSettingsMirror.checked;
      applyGlobalLiveEmbedSettings();
    });
  }

  if (liveSettingsQuality) {
    liveSettingsQuality.addEventListener('change', function(){
      liveHeaderVideoQuality = String(liveSettingsQuality.value || 'auto');
      applyGlobalLiveEmbedSettings();
    });
  }

  if (liveSettingsFrameRate) {
    liveSettingsFrameRate.addEventListener('change', function(){
      liveHeaderFrameRatePreference = Number(liveSettingsFrameRate.value || 24) >= 30 ? 30 : 24;
      applyGlobalLiveEmbedSettings();
    });
  }

  if (liveSettingsCameraDevice) {
    liveSettingsCameraDevice.addEventListener('change', function(){
      liveHeaderSelectedCameraDeviceId = String(liveSettingsCameraDevice.value || '');
      applyGlobalLiveEmbedSettings();
    });
  }

  if (liveSettingsMicDevice) {
    liveSettingsMicDevice.addEventListener('change', function(){
      liveHeaderSelectedMicDeviceId = String(liveSettingsMicDevice.value || '');
      applyGlobalLiveEmbedSettings();
    });
  }

  if (liveSettingsSpeakerDevice) {
    liveSettingsSpeakerDevice.addEventListener('change', function(){
      liveHeaderSelectedSpeakerDeviceId = String(liveSettingsSpeakerDevice.value || '');
      applyGlobalLiveEmbedSettings();
    });
  }

  if (liveReactionToggle) {
    liveReactionToggle.addEventListener('click', function(){
      const isReactionOpen = !!(liveModalDialog && liveModalDialog.classList.contains('has-chat') && liveSidebarMode === 'reactions');
      setGlobalLiveSidebarMode(isReactionOpen ? '' : 'reactions');
    });
  }

  if (liveDescriptionToggle) {
    liveDescriptionToggle.addEventListener('click', function(){
      const isDescriptionOpen = !!(liveModalDialog && liveModalDialog.classList.contains('has-chat') && liveSidebarMode === 'description');
      setGlobalLiveSidebarMode(isDescriptionOpen ? '' : 'description');
    });
  }

  if (liveSendButton) {
    liveSendButton.addEventListener('click', sendGlobalLiveComment);
  }

  if (liveCommentInput) {
    liveCommentInput.addEventListener('keydown', function(event){
      if (event.key === 'Enter' && !event.shiftKey) {
        event.preventDefault();
        sendGlobalLiveComment();
      }
    });
  }

  if (liveCommentList) {
    liveCommentList.addEventListener('click', function(event){
      const replyButton = event.target.closest('.global-live-comment-reply');
      if (replyButton) {
        const comment = replyButton.closest('[data-comment-author]');
        const author = comment ? String(comment.getAttribute('data-comment-author') || '').trim() : '';
        if (liveCommentInput) {
          const prefix = author ? ('@' + author + ' ') : '';
          liveCommentInput.value = prefix;
          liveCommentInput.focus();
          try {
            liveCommentInput.setSelectionRange(liveCommentInput.value.length, liveCommentInput.value.length);
          } catch (error) {}
        }
        return;
      }

      const likeButton = event.target.closest('.global-live-comment-like');
      if (likeButton) {
        const comment = likeButton.closest('[data-comment-id]');
        const commentId = parseInt(comment ? comment.getAttribute('data-comment-id') || '0' : '0', 10) || 0;
        if (commentId > 0) {
          toggleGlobalLiveCommentLike(commentId);
        }
      }
    });
  }

  if (liveReactionTabs) {
    liveReactionTabs.addEventListener('click', function(event){
      const tab = event.target.closest('[data-reaction-filter]');
      if (!tab) return;
      liveReactionFilter = String(tab.getAttribute('data-reaction-filter') || 'all');
      renderGlobalLiveReactionPanel();
    });
  }

  if (liveReactionList) {
    liveReactionList.addEventListener('click', function(event){
      const actionButton = event.target.closest('[data-reactor-action="friend"][data-user-id]');
      if (!actionButton || actionButton.disabled) return;
      const peerId = parseInt(actionButton.getAttribute('data-user-id') || '0', 10) || 0;
      if (peerId <= 0) return;
      sendGlobalLiveFriendRequest(peerId).catch((error) => {
        setGlobalLiveComposeFeedback((error && error.message) || 'Unable to send friend request.', 'error');
      });
    });
  }

  if (liveReactButton) {
    liveReactButton.addEventListener('click', reactGlobalLiveLove);
  }

  if (liveQuickReactionButtons.length) {
    liveQuickReactionButtons.forEach((button) => {
      button.addEventListener('click', function(event){
        event.preventDefault();
        event.stopPropagation();
        const reaction = String(button.getAttribute('data-live-room-reaction') || '').trim();
        if (!reaction) return;
        reactGlobalLive(reaction);
      });
    });
  }

  if (liveJoinRequestButton) {
    liveJoinRequestButton.addEventListener('click', function(event){
      event.preventDefault();
      event.stopPropagation();
      requestJoinGlobalLive();
    });
  }

  if (liveModal) {
    liveModal.addEventListener('click', function(event){
      if (event.target === liveModal) {
        closeHeaderLiveModal();
      }
    });
  }

  window.addEventListener('resize', syncGlobalLiveFrameSize);

  if (liveModalFrame) {
    liveModalFrame.addEventListener('load', function(){
      liveEmbedVideoCurrentTime = 0;
      liveEmbedVideoLastAdvanceAt = 0;
      clearGlobalLiveDirectStage();
      syncGlobalLiveDeviceControls();
      window.setTimeout(syncGlobalLiveFrameSize, 80);
      window.setTimeout(syncGlobalLiveFrameSize, 320);
      window.setTimeout(syncGlobalLiveDeviceControls, 500);
    });
  }

  if (liveConfirm) {
    liveConfirm.addEventListener('click', function(event){
      if (event.target === liveConfirm) {
        setGlobalLiveConfirmOpen(false);
      }
    });
  }

  document.addEventListener('keydown', function(event){
    if (event.key === 'Escape' && liveConfirm && liveConfirm.classList.contains('is-open')) {
      setGlobalLiveConfirmOpen(false);
      return;
    }
    if (event.key === 'Escape' && liveModal && liveModal.classList.contains('is-open')) {
      closeHeaderLiveModal();
    }
  });

  const autoOpenLiveWatchId = <?php echo (int)$autoOpenLiveWatchId; ?>;
  if (autoOpenLiveWatchId > 0) {
    window.setTimeout(function(){
      openHeaderLiveDoorWatch(autoOpenLiveWatchId).then(function(opened){
        if (!opened) {
          openHeaderLiveModal('live_watch.php?live=' + encodeURIComponent(String(autoOpenLiveWatchId)));
        }
        if (window.history && typeof window.history.replaceState === 'function') {
          const nextUrl = new URL(window.location.href);
          nextUrl.searchParams.delete('open_live_watch');
          window.history.replaceState({}, document.title, nextUrl.toString());
        }
      });
    }, 180);
  }
})();
</script>

<script>
(function(){
  const banner = document.getElementById('msbGlobalCallBanner');
  if(!banner || !window.fetch) return;

  const openBtn = document.getElementById('msbGlobalCallOpen');
  const declineBtn = document.getElementById('msbGlobalCallDecline');
  const acceptBtn = document.getElementById('msbGlobalCallAccept');
  const nameEl = document.getElementById('msbGlobalCallName');
  const subEl = document.getElementById('msbGlobalCallSub');
  const initialsEl = document.getElementById('msbGlobalCallInitials');
  const avatarImg = document.getElementById('msbGlobalCallAvatarImage');
  const meName = String(banner.getAttribute('data-me-name') || 'You').trim() || 'You';
  const dismissed = new Set();
  let active = null;
  let busy = false;
  let actionBusy = false;
  let afterSignalId = 0;
  let groupAfterSignalId = 0;
  let timer = null;

  function initials(name){
    const clean = String(name || '').trim();
    if(!clean) return 'U';
    const parts = clean.split(/\s+/).filter(Boolean);
    if(parts.length === 1) return parts[0].slice(0,2).toUpperCase();
    return (parts[0].charAt(0) + parts[parts.length - 1].charAt(0)).toUpperCase();
  }

  function isGroupItem(item){
    return String(item && item.scope || '').toLowerCase() === 'group' || !!(item && item.group);
  }

  function callModeLabel(item){
    const call = item && item.call;
    const mode = String(call && call.call_mode || 'video') === 'voice' ? 'voice' : 'video';
    if(isGroupItem(item)){
      const starter = String(item && item.group && item.group.starter_name || '').trim();
      return starter ? (starter + ' started a group ' + mode + ' call') : ('Incoming group ' + mode + ' call');
    }
    return mode === 'voice' ? 'Incoming voice call' : 'Incoming video call';
  }

  function peerCodeOf(item){
    return String(item && item.peer && (item.peer.peer_code || item.peer.friend_code || item.peer.caller_code) || '').trim();
  }

  function groupIdOf(item){
    return String(item && item.group && item.group.group_id || item && item.call && item.call.group_id || '').trim();
  }

  function callIdOf(item){
    return Number(item && item.call && (item.call.id || item.call.call_id) || 0);
  }

  function displayNameOf(item){
    if(isGroupItem(item)){
      return String(item && item.group && (item.group.name || item.group.group_name) || 'Group call').trim();
    }
    const peer = item.peer || {};
    return String(peer.display_name || peer.name || peer.peer_code || 'Incoming call').trim();
  }

  function avatarUrlOf(item){
    if(isGroupItem(item)) return String(item && item.group && item.group.avatar_url || '').trim();
    return String(item && item.peer && item.peer.avatar_url || '').trim();
  }

  function showBanner(item){
    active = item;
    const display = displayNameOf(item);
    nameEl.textContent = display;
    subEl.textContent = callModeLabel(item);
    initialsEl.textContent = initials(display);
    if(avatarImg){
      const avatar = avatarUrlOf(item);
      if(avatar){
        avatarImg.src = avatar;
        avatarImg.style.display = 'block';
      }else{
        avatarImg.removeAttribute('src');
        avatarImg.style.display = 'none';
      }
    }
    banner.setAttribute('aria-hidden', 'false');
    banner.classList.add('is-visible');
  }

  function hideBanner(){
    active = null;
    banner.classList.remove('is-visible');
    banner.setAttribute('aria-hidden', 'true');
  }

  function schedule(delay){
    if(timer) clearTimeout(timer);
    timer = setTimeout(poll, delay);
  }

  async function fetchCallPoll(params){
    const qs = new URLSearchParams(params);
    const res = await fetch('ajax/video_call_poll.php?' + qs.toString(), { cache:'no-store' });
    return await res.json().catch(()=>null);
  }

  function isDisplayableCall(item){
    const id = callIdOf(item);
    const status = String(item && item.call && item.call.status || '').toLowerCase();
    if(!id || dismissed.has((isGroupItem(item) ? 'group:' : 'private:') + id)) return false;
    if(isGroupItem(item)){
      const myStatus = String(item && item.call && (item.call.my_status || item.call.invite_status) || '').toLowerCase();
      return (status === 'initiated' || status === 'ringing' || status === 'active') && (!myStatus || myStatus === 'invited');
    }
    return status === 'initiated' || status === 'ringing';
  }

  async function poll(){
    if(busy){
      schedule(900);
      return;
    }
    busy = true;
    try{
      const results = await Promise.all([
        fetchCallPoll({
          global: '1',
          after_signal_id: String(afterSignalId || 0),
          wait: '2'
        }),
        fetchCallPoll({
          global: '1',
          scope: 'group',
          after_signal_id: String(groupAfterSignalId || 0),
          wait: '2'
        })
      ]);
      const privateData = results[0];
      const groupData = results[1];
      if(privateData && privateData.ok){
        afterSignalId = Math.max(afterSignalId, Number(privateData.last_signal_id || 0));
      }
      if(groupData && groupData.ok){
        groupAfterSignalId = Math.max(groupAfterSignalId, Number(groupData.last_signal_id || 0));
      }
      const next = [privateData, groupData].find(isDisplayableCall) || null;
      if(next){
        showBanner(next);
      }else if(active){
        hideBanner();
      }
    }catch(_e){
      // Poll again quietly; page browsing should not be interrupted by a transient call check.
    }finally{
      busy = false;
      schedule(active ? 1200 : 650);
    }
  }

  function openActiveCall(){
    if(!active) return;
    const callId = callIdOf(active);
    if(isGroupItem(active)){
      const groupId = groupIdOf(active);
      if(!groupId) return;
      if(callId) dismissed.add('group:' + callId);
      const url = 'messages.php?chat_type=group&group_id=' + encodeURIComponent(groupId) + (callId ? '&accept_call=' + encodeURIComponent(String(callId)) : '');
      window.location.href = url;
      return;
    }
    const peerCode = peerCodeOf(active);
    if(!peerCode) return;
    if(callId) dismissed.add('private:' + callId);
    const url = 'messages.php?peer=' + encodeURIComponent(peerCode) + (callId ? '&accept_call=' + encodeURIComponent(String(callId)) : '');
    window.location.href = url;
  }

  function callEventMarker(action){
    const target = String(active && active.peer && (active.peer.display_name || active.peer.peer_code) || 'this contact').trim();
    return '[[MSB_CALL_EVENT:' + JSON.stringify({
      action: String(action || ''),
      actor: meName,
      target: target || 'this contact'
    }) + ']]';
  }

  async function declineActiveCall(){
    if(!active || actionBusy) return;
    actionBusy = true;
    const callId = callIdOf(active);
    const peerCode = peerCodeOf(active);
    const isGroup = isGroupItem(active);
    if(callId) dismissed.add((isGroup ? 'group:' : 'private:') + callId);
    const declined = active;
    hideBanner();

    try{
      if(callId){
        const fd = new FormData();
        fd.append('call_id', String(callId));
        fd.append('scope', isGroup ? 'group' : 'private');
        fd.append('signal_type', 'decline');
        await fetch('ajax/video_call_signal.php', { method:'POST', body:fd });
      }
      if(!isGroup && peerCode){
        const msg = new FormData();
        msg.append('to', peerCode);
        active = declined;
        msg.append('message', callEventMarker('deny'));
        await fetch('ajax/user_chat_send.php', { method:'POST', body:msg });
        active = null;
      }
    }catch(_e){
      active = null;
    }finally{
      actionBusy = false;
    }
  }

  if(openBtn) openBtn.addEventListener('click', openActiveCall);
  if(acceptBtn) acceptBtn.addEventListener('click', openActiveCall);
  if(declineBtn) declineBtn.addEventListener('click', declineActiveCall);

  poll();
})();
</script>

<script>
(function(){
  const candidates = [
    'ajax/me_presence_heartbeat.php',
    '../ajax/me_presence_heartbeat.php'
  ];

  async function heartbeat(){
    for (const url of candidates) {
      try {
        const res = await fetch(url, {
          cache: 'no-store',
          credentials: 'same-origin',
          headers: { 'Accept': 'application/json' }
        });
        if (!res.ok) continue;
        const data = await res.json().catch(()=>null);
        if (data && data.ok) return true;
      } catch(e) {}
    }
    return false;
  }

  heartbeat();
  setInterval(heartbeat, 20000);

  let t=null;
  function bumpSoon(){
    if (t) return;
    t=setTimeout(()=>{ t=null; heartbeat(); }, 1500);
  }
  ['mousemove','keydown','touchstart','scroll','click'].forEach(ev=>{
    window.addEventListener(ev, bumpSoon, { passive:true });
  });
})();
</script>
