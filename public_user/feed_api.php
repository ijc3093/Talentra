<?php
// /Business_only3/public_user/feed_api.php
declare(strict_types=1);

require_once __DIR__ . '/includes/session_user.php';
require_once __DIR__ . '/controller.php';
require_once __DIR__ . '/includes/friend_system.php';
require_once __DIR__ . '/includes/publisher_accounts.php';
require_once __DIR__ . '/includes/device_profile.php';
require_once __DIR__ . '/includes/post_layout.php';
require_once __DIR__ . '/includes/post_card_actions_menu.php';
require_once __DIR__ . '/includes/staff_publisher_access.php';

requireUserLogin();

$controller = new Controller();
$dbh = $controller->pdo();

// DEBUG mode (only when you add ?debug=1)
$DEBUG = (isset($_GET['debug']) && (string)$_GET['debug'] === '1');

if ($DEBUG) {
  error_reporting(E_ALL);
  ini_set('display_errors', '0');
} else {
  error_reporting(0);
  ini_set('display_errors', '0');
}

header('Content-Type: application/json; charset=UTF-8');

function jexit(array $data): void {
  $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
  if ($json === false) {
    $json = json_encode([
      'ok' => false,
      'error' => 'Unable to encode server response.',
      'me_id' => (int)($data['me_id'] ?? 0),
    ], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{"ok":false,"error":"Unable to encode server response."}';
  }
  echo $json;
  exit;
}

// Try to detect your session user id key (some of your files use different names)
$meId = (int)($_SESSION['user_id'] ?? $_SESSION['id'] ?? $_SESSION['userid'] ?? 0);
if ($meId <= 0) {
  jexit(['ok' => false, 'error' => 'Invalid session (missing user id in session)']);
}

$ajax = (string)($_GET['ajax'] ?? $_POST['ajax'] ?? '');

function staff_pub_api_deny_write(int $meId): void
{
    if (staff_pub_is_readonly()) {
        jexit(['ok' => false, 'error' => 'Staff accounts are view-only.', 'me_id' => $meId]);
    }
}

function clamp_int($v, int $min, int $max, int $default): int {
  if (!is_numeric($v)) return $default;
  $n = (int)$v;
  if ($n < $min) return $min;
  if ($n > $max) return $max;
  return $n;
}

function feedUserRow(PDO $dbh, int $userId): array {
  static $cache = [];
  if ($userId <= 0) return [];
  if (isset($cache[$userId])) return $cache[$userId];

  try {
    $st = $dbh->prepare("
      SELECT id, username, COALESCE(NULLIF(name,''), username) AS display_name
      FROM users
      WHERE id = :id
      LIMIT 1
    ");
    $st->execute([':id' => $userId]);
    $cache[$userId] = $st->fetch(PDO::FETCH_ASSOC) ?: [];
  } catch (Throwable $e) {
    $cache[$userId] = [];
  }

  return $cache[$userId];
}

function feedNotificationPrefs(PDO $dbh, int $userId): array {
  static $cache = [];
  if ($userId <= 0) {
    return [
      'comment_notifications' => 1,
      'reaction_notifications' => 1,
    ];
  }
  if (isset($cache[$userId])) return $cache[$userId];

  $prefs = [
    'comment_notifications' => 1,
    'reaction_notifications' => 1,
  ];

  try {
    $st = $dbh->prepare("
      SELECT comment_notifications, reaction_notifications
      FROM user_profile_settings
      WHERE user_id = :uid
      LIMIT 1
    ");
    $st->execute([':uid' => $userId]);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    if ($row) {
      $prefs['comment_notifications'] = (int)($row['comment_notifications'] ?? 1);
      $prefs['reaction_notifications'] = (int)($row['reaction_notifications'] ?? 1);
    }
  } catch (Throwable $e) {}

  $cache[$userId] = $prefs;
  return $prefs;
}

function feedExtractLiveId(string ...$parts): int {
  foreach ($parts as $part) {
    $text = trim((string)$part);
    if ($text === '') continue;
    if (preg_match('/\[\[live_post:(\d+)\]\]/i', $text, $m)) {
      return (int)($m[1] ?? 0);
    }
    if (preg_match('/(?:live_watch|watch_live)\.php\?live=(\d+)/i', $text, $m)) {
      return (int)($m[1] ?? 0);
    }
  }
  return 0;
}

function feedLiveSnapshotVersion(int $liveId): string {
  if ($liveId <= 0) return '';
  $snapshot = __DIR__ . '/storage/live_snapshots/' . $liveId . '.jpg';
  if (!is_file($snapshot)) return '';
  $mtime = @filemtime($snapshot);
  return $mtime ? ('?v=' . $mtime) : '';
}

function feedFetchLiveMeta(PDO $dbh, int $liveId, int $meId): ?array {
  if ($liveId <= 0 || $meId <= 0) return null;
  try {
    $st = $dbh->prepare("
      SELECT
        l.id,
        l.user_id,
        COALESCE(l.title, '') AS title,
        COALESCE(l.description, '') AS description,
        COALESCE(l.status, 'draft') AS status,
        COALESCE(l.visibility, 'private') AS visibility,
        COALESCE(l.viewer_count, 0) AS viewer_count,
        COALESCE(l.share_count, 0) AS share_count,
        COALESCE(l.started_at, l.created_at) AS started_at,
        COALESCE(l.device_label, '') AS device_label,
        COALESCE(l.device_viewport, '') AS device_viewport,
        COALESCE(u.name, u.username) AS host_name,
        COALESCE(u.username, '') AS username
      FROM user_video_lives l
      JOIN users u ON u.id = l.user_id
      WHERE l.id = :id
      LIMIT 1
    ");
    $st->execute([':id' => $liveId]);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$row) return null;

    $status = strtolower(trim((string)($row['status'] ?? '')));
    if ($status !== 'live') return null;

    $hostId = (int)($row['user_id'] ?? 0);
    $visibility = strtolower(trim((string)($row['visibility'] ?? 'private')));
    if ($visibility === 'friends' && $hostId !== $meId && !fs_are_friends($dbh, $meId, $hostId)) {
      return null;
    }
    if ($visibility === 'private' && $hostId !== $meId) {
      return null;
    }

    $reactionCount = 0;
    try {
      $stR = $dbh->prepare("SELECT COUNT(*) FROM user_video_live_reactions WHERE live_id = :id");
      $stR->execute([':id' => $liveId]);
      $reactionCount = (int)($stR->fetchColumn() ?: 0);
    } catch (Throwable $e) {
      $reactionCount = 0;
    }

    $watchUrl = 'live_watch.php?live=' . $liveId;
    $embedUrl = $watchUrl . '&embed=1';
    $snapshotUrl = '';
    $snapshotPath = __DIR__ . '/storage/live_snapshots/' . $liveId . '.jpg';
    if (is_file($snapshotPath)) {
      $snapshotUrl = 'storage/live_snapshots/' . $liveId . '.jpg' . feedLiveSnapshotVersion($liveId);
    }

    return [
      'id' => $liveId,
      'user_id' => $hostId,
      'title' => (string)($row['title'] ?? ''),
      'description' => (string)($row['description'] ?? ''),
      'status' => $status,
      'visibility' => $visibility,
      'viewer_count' => (int)($row['viewer_count'] ?? 0),
      'share_count' => (int)($row['share_count'] ?? 0),
      'reaction_count' => $reactionCount,
      'started_at' => (string)($row['started_at'] ?? ''),
      'device_label' => (string)($row['device_label'] ?? ''),
      'device_viewport' => (string)($row['device_viewport'] ?? ''),
      'host_name' => (string)($row['host_name'] ?? ''),
      'username' => (string)($row['username'] ?? ''),
      'watch_url' => $watchUrl,
      'embed_url' => $embedUrl,
      'snapshot_url' => $snapshotUrl,
    ];
  } catch (Throwable $e) {
    return null;
  }
}

function feedAllowsNotification(PDO $dbh, int $receiverId, string $kind): bool {
  $prefs = feedNotificationPrefs($dbh, $receiverId);
  if ($kind === 'reaction') {
    return (int)($prefs['reaction_notifications'] ?? 1) === 1;
  }
  if ($kind === 'comment') {
    return (int)($prefs['comment_notifications'] ?? 1) === 1;
  }
  return true;
}

function feedRouteForPostOwner(int $receiverId, int $postOwnerId, string $visibility): string {
  if ($receiverId > 0 && $receiverId === $postOwnerId) return 'pf';
  return strtolower(trim($visibility)) === 'public' ? 'pb' : 'fd';
}

function feedAllowedPostReactions(): array {
  return ['like', 'love', 'smile', 'laugh', 'wow', 'sad', 'angry'];
}

function feedNormalizePostReaction(string $reaction): string {
  $reaction = strtolower(trim($reaction));
  return in_array($reaction, feedAllowedPostReactions(), true) ? $reaction : '';
}

function feedReactionNotificationMessage(string $reaction): string {
  $reaction = feedNormalizePostReaction($reaction);
  if ($reaction === 'love') return 'loved your post';
  if ($reaction === 'smile') return 'smiled at your post';
  if ($reaction === 'laugh') return 'laughed at your post';
  if ($reaction === 'like') return 'liked your post';
  return 'reacted to your post';
}

function feedFetchPostReactionCounts(PDO $dbh, int $postId, int $meId): array {
  try {
    $st = $dbh->prepare("
      SELECT
        (SELECT COUNT(*) FROM public_post_reactions WHERE post_id = ? AND reaction <> 'love') AS like_count,
        (SELECT COUNT(*) FROM public_post_reactions WHERE post_id = ? AND reaction = 'love') AS love_count,
        (SELECT reaction FROM public_post_reactions WHERE post_id = ? AND user_id = ? LIMIT 1) AS my_reaction
    ");
    $st->execute([$postId, $postId, $postId, $meId]);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    return [
      'like_count' => (int)($row['like_count'] ?? 0),
      'love_count' => (int)($row['love_count'] ?? 0),
      'my_reaction' => ($row['my_reaction'] ?? null) !== null ? trim((string)$row['my_reaction']) : null,
    ];
  } catch (Throwable $e) {
    return ['like_count' => 0, 'love_count' => 0, 'my_reaction' => null];
  }
}

function feedCommentReactionColumnExists(PDO $dbh): bool {
  static $cache = null;
  if ($cache !== null) return $cache;
  try {
    $st = $dbh->query("SHOW COLUMNS FROM public_comment_likes LIKE 'reaction'");
    $cache = (bool)($st && $st->fetch(PDO::FETCH_ASSOC));
  } catch (Throwable $e) {
    $cache = false;
  }
  return $cache;
}

function feedCommentReactionNotificationMessage(string $reaction): string {
  $reaction = feedNormalizePostReaction($reaction);
  if ($reaction === 'love') return 'loved your comment';
  if ($reaction === 'smile') return 'smiled at your comment';
  if ($reaction === 'laugh') return 'laughed at your comment';
  if ($reaction === 'like') return 'liked your comment';
  return 'reacted to your comment';
}

function feedBuildNotificationType(string $message, array $meta = []): string {
  $type = trim($message);
  $route = trim((string)($meta['route'] ?? ''));
  $postId = (int)($meta['post_id'] ?? 0);
  $commentId = (int)($meta['comment_id'] ?? 0);

  if ($route !== '') $type .= ' [r:' . preg_replace('/[^a-z]/i', '', $route) . ']';
  if ($postId > 0) $type .= ' [p:' . $postId . ']';
  if ($commentId > 0) $type .= ' [c:' . $commentId . ']';

  return mb_substr($type, 0, 100);
}

function feedAddNotification(PDO $dbh, int $senderId, int $receiverId, string $message, string $kind, array $meta = []): void {
  if ($senderId <= 0 || $receiverId <= 0 || $senderId === $receiverId) return;
  if ($message === '' || !feedAllowsNotification($dbh, $receiverId, $kind)) return;

  $sender = feedUserRow($dbh, $senderId);
  $receiver = feedUserRow($dbh, $receiverId);

  $senderLabel = trim((string)($sender['display_name'] ?? $sender['username'] ?? ''));
  $receiverUsername = trim((string)($receiver['username'] ?? ''));
  if ($senderLabel === '' || $receiverUsername === '') return;

  try {
    $st = $dbh->prepare("
      INSERT INTO notification (notiuser, notireceiver, notitype, is_read)
      VALUES (:sender, :receiver, :type, 0)
    ");
    $st->execute([
      ':sender' => $senderLabel,
      ':receiver' => $receiverUsername,
      ':type' => feedBuildNotificationType($message, $meta),
    ]);
  } catch (Throwable $e) {
    // Notification failure must not break the main action.
  }
}

try {

  publisher_ensure_schema($dbh);

  // ---------------------------
  // LIST
  // ---------------------------
  // Supports:
  // - filter=all|unread|mine|author
  // - author_id=123 (when filter=author)
  // - q=search text
  // - limit=60 (max 200)
  if ($ajax === 'list') {
    $filter   = (string)($_GET['filter'] ?? 'all'); // all|unread|mine|author
    $q        = trim((string)($_GET['q'] ?? ''));
    $authorId = (int)($_GET['author_id'] ?? 0);
    $limit    = clamp_int($_GET['limit'] ?? 60, 1, 200, 60);
    $pageMode = strtolower(trim((string)($_GET['page'] ?? 'feed'))); // feed|public|news
    $excludeStories = (int)($_GET['exclude_stories'] ?? 0) === 1;

    $order    = (string)($_GET['order'] ?? 'recent'); // recent|views

    $where  = "p.is_deleted = 0";
    $params = [];

    if ($filter === 'author' && $authorId > 0) {
      $where .= " AND p.user_id = :author";
      $params[':author'] = $authorId;
      $where .= ' AND ' . publisher_profile_author_posts_scope_sql($dbh, $meId, $authorId);
      $params = array_merge($params, publisher_profile_author_posts_scope_params($dbh, $meId, $authorId));
    } elseif ($pageMode === 'public') {
      $where .= " AND p.visibility = 'public'";
      $where .= ' AND ' . publisher_public_surface_scope_sql($dbh, $meId, false);
      $params = array_merge($params, publisher_public_surface_scope_params($dbh, $meId, false));
    } elseif ($pageMode === 'news') {
      $where .= " AND p.visibility = 'public'";
      $where .= ' AND ' . publisher_public_surface_scope_sql($dbh, $meId, true);
      $params = array_merge($params, publisher_public_surface_scope_params($dbh, $meId, true));
    } else {
      $where .= ' AND ' . publisher_feed_list_scope_sql_for($dbh, $meId);
      $params = array_merge($params, publisher_feed_list_scope_params_for($dbh, $meId));
    }

    // ✅ mine = always by meId (ID-based, not name-based)
    if ($filter === 'mine') {
      $where .= " AND p.user_id = :me";
      $params[':me'] = $meId;
    } elseif ($filter === 'author') {
      if ($authorId <= 0) {
        jexit(['ok' => false, 'error' => 'Missing author_id for filter=author', 'me_id' => $meId]);
      }
    } elseif ($filter === 'unread') {
      $where .= " AND (r.last_seen_at IS NULL OR COALESCE(p.updated_at, p.created_at) > r.last_seen_at)";
    }

    if ($q !== '') {
      $where .= " AND (p.title LIKE :qTitle OR p.description LIKE :qDesc OR u.username LIKE :qUser OR u.name LIKE :qName)";
      $qLike = '%' . $q . '%';
      $params[':qTitle'] = $qLike;
      $params[':qDesc'] = $qLike;
      $params[':qUser'] = $qLike;
      $params[':qName'] = $qLike;
    }

    // ✅ ordering — newest first (read/unread must not push fresh posts below older unread items)
    $orderBy = "COALESCE(p.updated_at, p.created_at) DESC, p.id DESC";
    if ($order === 'views') {
      // top viewed
      $orderBy = "COALESCE(p.views_count,0) DESC, COALESCE(p.updated_at, p.created_at) DESC, p.id DESC";
    }

    $layoutSelect = post_layout_select_sql($dbh);

    $sql = "
      SELECT
        p.id,
        p.user_id,
        COALESCE(p.views_count, 0) AS views_count,
        COALESCE(p.title,'') AS title,
        COALESCE(p.description,'') AS description,
        COALESCE(p.body,'') AS body,
        {$layoutSelect}
        COALESCE(p.device_label,'') AS device_label,
        COALESCE(p.device_viewport,'') AS device_viewport,
        LENGTH(TRIM(COALESCE(p.body,''))) AS body_len,
        p.created_at,
        COALESCE(p.updated_at, p.created_at) AS updated_at,
        u.username,
        COALESCE(u.name, u.username) AS display_name,
        COALESCE(u.friend_code,'') AS friend_code,
        COALESCE(u.account_kind, 'personal') AS account_kind,
        EXISTS(SELECT 1 FROM public_follows pf WHERE pf.follower_id = :meFollow AND pf.following_id = p.user_id) AS is_following,
        r.last_seen_at,
        CASE
          WHEN r.last_seen_at IS NULL THEN 1
          WHEN COALESCE(p.updated_at, p.created_at) > r.last_seen_at THEN 1
          ELSE 0
        END AS is_unread,
        (SELECT a.file_path FROM public_post_attachments a WHERE a.post_id = p.id ORDER BY a.id ASC LIMIT 1) AS preview_path,
        (SELECT a.thumb_path FROM public_post_attachments a WHERE a.post_id = p.id ORDER BY a.id ASC LIMIT 1) AS preview_thumb_path,
        (SELECT a.type FROM public_post_attachments a WHERE a.post_id = p.id ORDER BY a.id ASC LIMIT 1) AS preview_type,
        (SELECT COUNT(*) FROM public_post_attachments a WHERE a.post_id = p.id) AS attachment_count,
        (SELECT COUNT(*) FROM public_post_attachments a WHERE a.post_id = p.id) AS media_count,
        (SELECT COUNT(*) FROM public_post_comments c WHERE c.post_id = p.id AND c.is_deleted = 0) AS comment_count,
        (SELECT COUNT(*) FROM public_post_reactions rx WHERE rx.post_id = p.id AND rx.reaction <> 'love') AS like_count,
        (SELECT COUNT(*) FROM public_post_reactions rx WHERE rx.post_id = p.id AND rx.reaction = 'love') AS love_count,
        (SELECT reaction FROM public_post_reactions rx WHERE rx.post_id = p.id AND rx.user_id = :meReaction LIMIT 1) AS my_reaction,
        (SELECT COUNT(*) FROM public_post_shares s WHERE s.post_id = p.id) AS share_count,
        (SELECT COUNT(*) FROM public_post_saves s WHERE s.post_id = p.id) AS save_count,
        EXISTS(SELECT 1 FROM public_post_shares s WHERE s.post_id = p.id AND s.user_id = :meShared) AS my_shared,
        EXISTS(SELECT 1 FROM public_post_saves s WHERE s.post_id = p.id AND s.user_id = :meSaved) AS my_saved
      FROM public_posts p
      JOIN users u ON u.id = p.user_id
      LEFT JOIN public_post_reads r ON r.post_id = p.id AND r.user_id = :meRead
      WHERE {$where}
      ORDER BY {$orderBy}
      LIMIT {$limit}
    ";

    $params[':meRead'] = $meId;
    $params[':meReaction'] = $meId;
    $params[':meShared'] = $meId;
    $params[':meSaved'] = $meId;
    $params[':meFollow'] = $meId;

    $st = $dbh->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $filteredRows = [];
    foreach ($rows as $r) {
      $liveId = feedExtractLiveId((string)($r['body'] ?? ''), (string)($r['description'] ?? ''), (string)($r['title'] ?? ''));
      if ($liveId > 0) {
        continue;
      }
      $r['live_meta'] = null;

      if (!empty($r['preview_path'])) {
        $r['preview_path'] = preg_replace('#^public_user/#', '', (string)$r['preview_path']);
      }
      if (!empty($r['preview_thumb_path'])) {
        $r['preview_thumb_path'] = preg_replace('#^public_user/#', '', (string)$r['preview_thumb_path']);
      }

      $deviceMeta = device_profile_card_meta(
        (string)($r['device_label'] ?? ''),
        (string)($r['device_viewport'] ?? '')
      );
      $r['device_frame'] = (string)($deviceMeta['device_frame'] ?? '');
      $r['phone_shot'] = !empty($deviceMeta['phone_shot']) ? 1 : 0;
      $r['tablet_shot'] = !empty($deviceMeta['tablet_shot']) ? 1 : 0;
      $r['device_style'] = (string)($deviceMeta['style'] ?? '');
      $r['media_shape'] = device_profile_media_shape(
        (string)($r['preview_type'] ?? ''),
        (string)($r['preview_path'] ?? ''),
        (string)($r['preview_thumb_path'] ?? ''),
        (int)($r['attachment_count'] ?? 0)
      );
      if ($r['media_shape'] === '' && (int)($r['attachment_count'] ?? 0) === 1) {
        $r['media_shape'] = 'single-square';
      }

      // ✅ Convenience flags for UI
      $bodyLen = (int)($r['body_len'] ?? 0);
      $attCnt  = (int)($r['attachment_count'] ?? 0);
      $r['has_long_body'] = ($bodyLen >= 160) ? 1 : 0; // heuristic
      $r['is_media_only'] = ($bodyLen === 0 && $attCnt > 0) ? 1 : 0;

      // ✅ Give UI stable IDs to use (no name matching)
      $r['author_id'] = (int)($r['user_id'] ?? 0);
      $r['me_id']     = $meId; // optional per-row; UI can also use top-level me_id

      // ensure integer for views
      $r['views_count'] = (int)($r['views_count'] ?? 0);
      $r['comment_count'] = (int)($r['comment_count'] ?? 0);
      $r['like_count'] = (int)($r['like_count'] ?? 0);
      $r['love_count'] = (int)($r['love_count'] ?? 0);
      $r['share_count'] = (int)($r['share_count'] ?? 0);
      $r['save_count'] = (int)($r['save_count'] ?? 0);
      $r['my_reaction'] = ($r['my_reaction'] ?? null) !== null ? trim((string)$r['my_reaction']) : '';
      $r['my_shared'] = !empty($r['my_shared']) ? 1 : 0;
      $r['my_saved'] = !empty($r['my_saved']) ? 1 : 0;
      if (trim((string)($r['declared_layout'] ?? '')) === '') {
        $r['declared_layout'] = post_declared_layout($r);
      }
      $r['is_story'] = post_is_story_only($r) ? 1 : 0;
      $authorId = (int)($r['user_id'] ?? 0);
      $r['friend_status'] = ($authorId > 0 && $authorId !== $meId)
        ? fs_friend_status($dbh, $meId, $authorId)
        : 'self';
      $contactRow = post_card_contact_for_peer($dbh, $meId, $authorId);
      $r['contact_id'] = (int)($contactRow['contact_id'] ?? 0);
      $r['contact_name'] = (string)($contactRow['display_name'] ?? '');
      $r['is_publisher'] = publisher_user_row_looks_like_publisher($dbh, $r) ? 1 : 0;
      if (!empty($r['is_publisher'])) {
        $r['account_kind'] = 'publisher';
      }
      if ($excludeStories && post_is_story_only($r)) {
        continue;
      }
      $filteredRows[] = $r;
    }
    $rows = array_values($filteredRows);

    // ✅ unread_count for badge (overall)
    $unreadWhere = "p.is_deleted = 0 AND (r.last_seen_at IS NULL OR COALESCE(p.updated_at, p.created_at) > r.last_seen_at)";
    $unreadParams = [':meRead' => $meId];
    $unreadJoin = '';
    if ($pageMode === 'public' || $pageMode === 'news') {
      $unreadJoin = ' JOIN users u ON u.id = p.user_id';
      $unreadWhere .= " AND p.visibility = 'public'";
      $unreadWhere .= ' AND ' . publisher_public_surface_scope_sql($dbh, $meId, $pageMode === 'news');
      $unreadParams = array_merge($unreadParams, publisher_public_surface_scope_params($dbh, $meId, $pageMode === 'news'));
    } else {
      $unreadWhere .= ' AND ' . publisher_feed_unread_scope_sql_for($dbh, $meId);
      $unreadParams = array_merge($unreadParams, publisher_feed_unread_scope_params_for($dbh, $meId));
    }
    $stU = $dbh->prepare("
      SELECT COUNT(*)
      FROM public_posts p{$unreadJoin}
      LEFT JOIN public_post_reads r ON r.post_id = p.id AND r.user_id = :meRead
      WHERE {$unreadWhere}
    ");
    $stU->execute($unreadParams);
    $unreadCount = (int)($stU->fetchColumn() ?: 0);

    jexit([
      'ok' => true,
      'me_id' => $meId,
      'items' => $rows,
      'unread_count' => $unreadCount
    ]);
  }

  // ---------------------------
  // VIEW
  // ---------------------------
  if ($ajax === 'view') {
    $postId = (int)($_GET['id'] ?? 0);
    if ($postId <= 0) $postId = (int)($_GET['post_id'] ?? 0);
    if ($postId <= 0) jexit(['ok' => false, 'error' => 'Missing post id', 'me_id' => $meId]);

    $st = $dbh->prepare("
      SELECT p.*, u.username, COALESCE(u.name, u.username) AS display_name, COALESCE(u.friend_code,'') AS friend_code,
        COALESCE(u.account_kind, 'personal') AS account_kind,
        COALESCE(u.publisher_category, '') AS publisher_category,
        EXISTS(SELECT 1 FROM public_follows pf WHERE pf.follower_id = :meFollow AND pf.following_id = p.user_id) AS is_following
      FROM public_posts p
      JOIN users u ON u.id = p.user_id
      WHERE p.id = :id AND p.is_deleted = 0
      LIMIT 1
    ");
    $st->execute([':id' => $postId, ':meFollow' => $meId]);
    $post = $st->fetch(PDO::FETCH_ASSOC);
    if (!$post) jexit(['ok' => false, 'error' => 'Post not found', 'me_id' => $meId]);

    $post['is_publisher'] = publisher_user_row_looks_like_publisher($dbh, $post) ? 1 : 0;
    if (!empty($post['is_publisher'])) {
      $post['account_kind'] = 'publisher';
    }
    $post['is_following'] = !empty($post['is_following']) ? 1 : 0;

    $post['author_id'] = (int)($post['user_id'] ?? 0);
    $liveId = feedExtractLiveId((string)($post['body'] ?? ''), (string)($post['description'] ?? ''), (string)($post['title'] ?? ''));
    if ($liveId > 0) {
      $liveMeta = feedFetchLiveMeta($dbh, $liveId, $meId);
      if (!$liveMeta) {
        jexit(['ok' => false, 'error' => 'This live is no longer available.', 'me_id' => $meId]);
      }
      $post['live_meta'] = $liveMeta;
    } else {
      $post['live_meta'] = null;
    }

    $vis = (string)($post['visibility'] ?? 'public');
    $authorId = (int)($post['user_id'] ?? 0);
    if ($vis === 'friends' && $authorId !== $meId && !fs_are_friends($dbh, $meId, $authorId)) {
      jexit(['ok' => false, 'error' => 'You do not have access to this post.', 'me_id' => $meId]);
    }
    if (!publisher_can_view_post($dbh, $meId, $post)) {
      $denyMsg = publisher_workspace_viewer($dbh, $meId)
        ? 'Personal account posts are not available in the publisher workspace.'
        : 'This post is not available.';
      jexit([
        'ok' => false,
        'error' => $denyMsg,
        'me_id' => $meId,
      ]);
    }

    $countView = (string)($_GET['count_view'] ?? $_POST['count_view'] ?? '');

    // ✅ VIEW COUNT (unique per user):
    // - only increments when count_view=1
    // - does NOT count author views
    // - counts at most once per (post_id, user_id) via public_post_views table
    // - updates public_posts.views_count cached column for fast list sorting
    // - updates daily stats table public_post_view_daily for analytics
    try {
      $authorId = (int)($post['user_id'] ?? 0);

      if ($countView === '1' && $authorId > 0 && $authorId !== $meId) {
        // Create a unique view row (1 per user per post)
        $dbh->beginTransaction();

        $stIns = $dbh->prepare("
          INSERT IGNORE INTO public_post_views (post_id, user_id, viewed_at)
          VALUES (:pid, :uid, NOW())
        ");
        $stIns->execute([':pid' => $postId, ':uid' => $meId]);

        $didInsert = (int)$stIns->rowCount(); // 1 if new unique view, 0 if already viewed

        if ($didInsert > 0) {
          // cached counter
          $dbh->prepare("UPDATE public_posts SET views_count = COALESCE(views_count,0) + 1 WHERE id = :id LIMIT 1")
              ->execute([':id' => $postId]);

          // daily analytics
          $dbh->prepare("
            INSERT INTO public_post_view_daily (post_id, view_date, views)
            VALUES (:pid, CURDATE(), 1)
            ON DUPLICATE KEY UPDATE views = views + 1
          ")->execute([':pid' => $postId]);
        }

        $dbh->commit();
      }

      // fetch latest cached count so UI updates immediately
      $stV = $dbh->prepare("SELECT COALESCE(views_count,0) FROM public_posts WHERE id = :id LIMIT 1");
      $stV->execute([':id' => $postId]);
      $post['views_count'] = (int)($stV->fetchColumn() ?: 0);

    } catch (Throwable $e) {
      // If tables/columns don't exist yet, keep safe
      try { if ($dbh->inTransaction()) $dbh->rollBack(); } catch (Throwable $e2) {}
      $post['views_count'] = (int)($post['views_count'] ?? 0);
    }


    $stA = $dbh->prepare("
      SELECT id, type, file_path, thumb_path, created_at
      FROM public_post_attachments
      WHERE post_id = :pid
      ORDER BY id ASC
    ");
    $stA->execute([':pid' => $postId]);
    $atts = $stA->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($atts as &$a) {
      $fp = preg_replace('#^public_user/#', '', (string)($a['file_path'] ?? ''));
      $tp = preg_replace('#^public_user/#', '', (string)($a['thumb_path'] ?? ''));

      $a['file_path']  = $fp;
      $a['thumb_path'] = $tp;
      $a['url']        = $fp;
      $a['thumb_url']  = $tp;
    }
    unset($a);

    // ✅ counts (positional placeholders = no HY093)
    $counts = feedFetchPostReactionCounts($dbh, $postId, $meId);
    $lite = ((string)($_GET['lite'] ?? $_POST['lite'] ?? '') === '1');

    try {
      $stCc = $dbh->prepare("SELECT COUNT(*) FROM public_post_comments WHERE post_id = ? AND is_deleted = 0");
      $stCc->execute([$postId]);
      $counts['comment_count'] = (int)($stCc->fetchColumn() ?: 0);
    } catch (Throwable $e) {
      $counts['comment_count'] = 0;
    }

    try {
      $stSc = $dbh->prepare("SELECT COUNT(*) FROM public_post_shares WHERE post_id = ?");
      $stSc->execute([$postId]);
      $counts['share_count'] = (int)($stSc->fetchColumn() ?: 0);
      $stMs = $dbh->prepare("SELECT 1 FROM public_post_shares WHERE post_id = ? AND user_id = ? LIMIT 1");
      $stMs->execute([$postId, $meId]);
      $counts['is_shared'] = (int)($stMs->fetchColumn() ? 1 : 0);
    } catch (Throwable $e) {
      $counts['share_count'] = 0;
      $counts['is_shared'] = 0;
    }

    try {
      $stSv = $dbh->prepare("SELECT COUNT(*) FROM public_post_saves WHERE post_id = ?");
      $stSv->execute([$postId]);
      $counts['save_count'] = (int)($stSv->fetchColumn() ?: 0);
      $stMv = $dbh->prepare("SELECT 1 FROM public_post_saves WHERE post_id = ? AND user_id = ? LIMIT 1");
      $stMv->execute([$postId, $meId]);
      $counts['is_saved'] = (int)($stMv->fetchColumn() ? 1 : 0);
    } catch (Throwable $e) {
      $counts['save_count'] = 0;
      $counts['is_saved'] = 0;
    }

    $comments = [];
    if (!$lite) {
    $stCom = $dbh->prepare("
      SELECT c.id, c.post_id, c.user_id, c.parent_id, c.comment_text, c.created_at,
             u.username, COALESCE(u.name,u.username) AS display_name
      FROM public_post_comments c
      JOIN users u ON u.id = c.user_id
      WHERE c.post_id = :pid AND c.is_deleted = 0
      ORDER BY c.created_at ASC
      LIMIT 1000
    ");
    $stCom->execute([':pid' => $postId]);
    $comments = $stCom->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Attach comment like counts (TikTok-style hearts)
    $commentIds = array_values(array_filter(array_map(static function($r){
      return isset($r['id']) ? (int)$r['id'] : 0;
    }, $comments)));
    $likeCounts = [];
    $myLikes = [];
    $myReactions = [];
    $hasCommentReactionColumn = feedCommentReactionColumnExists($dbh);

    if (!empty($commentIds)) {
      $in = implode(',', array_fill(0, count($commentIds), '?'));

      // counts
      try {
        $stLc = $dbh->prepare("SELECT comment_id, COUNT(*) AS cnt FROM public_comment_likes WHERE comment_id IN ($in) GROUP BY comment_id");
        $stLc->execute($commentIds);
        foreach ($stLc->fetchAll(PDO::FETCH_ASSOC) as $rowLc) {
          $likeCounts[(int)$rowLc['comment_id']] = (int)$rowLc['cnt'];
        }

        // my likes
        $params = $commentIds;
        array_unshift($params, $meId);
        if ($hasCommentReactionColumn) {
          $stMl = $dbh->prepare("SELECT comment_id, reaction FROM public_comment_likes WHERE user_id = ? AND comment_id IN ($in)");
          $stMl->execute($params);
          foreach ($stMl->fetchAll(PDO::FETCH_ASSOC) as $rowMl) {
            $cid = (int)($rowMl['comment_id'] ?? 0);
            $reaction = feedNormalizePostReaction((string)($rowMl['reaction'] ?? ''));
            if ($cid > 0 && $reaction !== '') {
              $myLikes[$cid] = 1;
              $myReactions[$cid] = $reaction;
            }
          }
        } else {
          $stMl = $dbh->prepare("SELECT comment_id FROM public_comment_likes WHERE user_id = ? AND comment_id IN ($in)");
          $stMl->execute($params);
          foreach ($stMl->fetchAll(PDO::FETCH_COLUMN, 0) as $cid) {
            $cid = (int)$cid;
            $myLikes[$cid] = 1;
            $myReactions[$cid] = 'like';
          }
        }
      } catch (Throwable $e) {
        // table may not exist yet; keep zeros
      }

      foreach ($comments as &$c) {
        $cid = (int)($c['id'] ?? 0);
        $c['like_count'] = (int)($likeCounts[$cid] ?? 0);
        $c['me_liked']   = (int)($myLikes[$cid] ?? 0);
        $c['my_reaction'] = (string)($myReactions[$cid] ?? '');
      }
      unset($c);
    }
    }

    jexit([
      'ok'=>true,
      'me_id'=>$meId,
      'post'=>$post,
      'attachments'=>$atts,
      'counts'=>$counts,
      'comments'=>$comments
    ]);
  }

  // ---------------------------
  // MARK READ
  // ---------------------------
  if ($ajax === 'mark_read') {
    $postId = (int)($_POST['post_id'] ?? 0);
    if ($postId <= 0) jexit(['ok'=>false,'error'=>'Missing post id', 'me_id'=>$meId]);

    $st = $dbh->prepare("
      INSERT INTO public_post_reads (post_id, user_id, last_seen_at)
      VALUES (:pid, :uid, NOW())
      ON DUPLICATE KEY UPDATE last_seen_at = NOW()
    ");
    $st->execute([':pid'=>$postId, ':uid'=>$meId]);

    jexit(['ok'=>true, 'me_id'=>$meId]);
  }

  // ---------------------------
  // REACT
  // ---------------------------
  if ($ajax === 'react') {
    staff_pub_api_deny_write($meId);
    $postId   = (int)($_POST['post_id'] ?? 0);
    $reaction = strtolower(trim((string)($_POST['reaction'] ?? '')));
    if ($postId <= 0) jexit(['ok'=>false,'error'=>'Missing post id', 'me_id'=>$meId]);
    if ($reaction !== 'none' && feedNormalizePostReaction($reaction) === '') {
      jexit(['ok'=>false,'error'=>'Bad reaction', 'me_id'=>$meId]);
    }

    $postOwnerId = 0;
    $postVisibility = 'friends';
    $previousReaction = '';
    try {
      $stPost = $dbh->prepare("SELECT user_id, visibility FROM public_posts WHERE id = :pid AND is_deleted = 0 LIMIT 1");
      $stPost->execute([':pid' => $postId]);
      $postRow = $stPost->fetch(PDO::FETCH_ASSOC) ?: [];
      $postOwnerId = (int)($postRow['user_id'] ?? 0);
      $postVisibility = trim((string)($postRow['visibility'] ?? 'friends')) ?: 'friends';

      $stPrev = $dbh->prepare("SELECT reaction FROM public_post_reactions WHERE post_id = :pid AND user_id = :uid LIMIT 1");
      $stPrev->execute([':pid' => $postId, ':uid' => $meId]);
      $previousReaction = trim((string)($stPrev->fetchColumn() ?: ''));
    } catch (Throwable $e) {}

    if ($postOwnerId > 0 && !publisher_post_interaction_allowed($dbh, $meId, ['user_id' => $postOwnerId, 'visibility' => $postVisibility])) {
      jexit(['ok'=>false,'error'=>'Follow this publisher to react and comment. Their posts appear in your Feed after you follow.','me_id'=>$meId]);
    }

    if ($reaction === 'none') {
      $st = $dbh->prepare("DELETE FROM public_post_reactions WHERE post_id = :pid AND user_id = :uid");
      $st->execute([':pid'=>$postId, ':uid'=>$meId]);
    } else {
      $st = $dbh->prepare("
        INSERT INTO public_post_reactions (post_id, user_id, reaction, reacted_at)
        VALUES (:pid, :uid, :r, NOW())
        ON DUPLICATE KEY UPDATE reaction = VALUES(reaction), reacted_at = NOW()
      ");
      $st->execute([':pid'=>$postId, ':uid'=>$meId, ':r'=>feedNormalizePostReaction($reaction)]);
    }

    if ($reaction !== 'none' && $reaction !== $previousReaction) {
      feedAddNotification(
        $dbh,
        $meId,
        $postOwnerId,
        feedReactionNotificationMessage($reaction),
        'reaction',
        [
          'route' => feedRouteForPostOwner($postOwnerId, $postOwnerId, $postVisibility),
          'post_id' => $postId,
        ]
      );
    }

    $counts = feedFetchPostReactionCounts($dbh, $postId, $meId);

    jexit(['ok'=>true,'me_id'=>$meId,'counts'=>$counts]);
  }

  // ---------------------------
  // TRACK COUNTS (share/save)
  // ---------------------------
  if ($ajax === 'track_counts') {
    $postId = (int)($_GET['post_id'] ?? $_GET['id'] ?? 0);
    if ($postId <= 0) jexit(['ok'=>false,'error'=>'Missing post id', 'me_id'=>$meId]);

    // share count + my shared
    $shareCount = 0;
    $myShared   = 0;
    try {
      $st = $dbh->prepare("SELECT COUNT(*) FROM public_post_shares WHERE post_id = ?");
      $st->execute([$postId]);
      $shareCount = (int)($st->fetchColumn() ?: 0);

      $st = $dbh->prepare("SELECT 1 FROM public_post_shares WHERE post_id = ? AND user_id = ? LIMIT 1");
      $st->execute([$postId, $meId]);
      $myShared = (int)($st->fetchColumn() ? 1 : 0);
    } catch (Throwable $e) {
      $shareCount = 0;
      $myShared = 0;
    }

    // save count + my saved
    $saveCount = 0;
    $mySaved   = 0;
    try {
      $st = $dbh->prepare("SELECT COUNT(*) FROM public_post_saves WHERE post_id = ?");
      $st->execute([$postId]);
      $saveCount = (int)($st->fetchColumn() ?: 0);

      $st = $dbh->prepare("SELECT 1 FROM public_post_saves WHERE post_id = ? AND user_id = ? LIMIT 1");
      $st->execute([$postId, $meId]);
      $mySaved = (int)($st->fetchColumn() ? 1 : 0);
    } catch (Throwable $e) {
      $saveCount = 0;
      $mySaved = 0;
    }

    jexit([
      'ok' => true,
      'me_id' => $meId,
      'share_count' => $shareCount,
      'save_count' => $saveCount,
      'state' => [ 'shared' => $myShared, 'saved' => $mySaved ]
    ]);
  }

  // ---------------------------
  // SHARE (one-time per user per post)
  // ---------------------------
  if ($ajax === 'share') {
    staff_pub_api_deny_write($meId);
    $postId = (int)($_POST['post_id'] ?? 0);
    if ($postId <= 0) jexit(['ok'=>false,'error'=>'Missing post id', 'me_id'=>$meId]);

    try {
      $stAccess = $dbh->prepare("SELECT user_id, visibility FROM public_posts WHERE id = :pid AND is_deleted = 0 LIMIT 1");
      $stAccess->execute([':pid' => $postId]);
      $accessRow = $stAccess->fetch(PDO::FETCH_ASSOC) ?: [];
      if (!$accessRow || !publisher_post_interaction_allowed($dbh, $meId, $accessRow)) {
        jexit(['ok'=>false,'error'=>'Follow this publisher to interact. Their posts appear in your Feed after you follow.','me_id'=>$meId]);
      }

      $st = $dbh->prepare("SELECT 1 FROM public_post_shares WHERE post_id = :pid AND user_id = :uid LIMIT 1");
      $st->execute([':pid'=>$postId, ':uid'=>$meId]);
      $exists = (bool)$st->fetchColumn();

      if ($exists) {
        $st = $dbh->prepare("DELETE FROM public_post_shares WHERE post_id = :pid AND user_id = :uid LIMIT 1");
        $st->execute([':pid'=>$postId, ':uid'=>$meId]);
      } else {
        $st = $dbh->prepare("INSERT INTO public_post_shares (post_id, user_id, shared_at) VALUES (:pid,:uid,NOW())");
        $st->execute([':pid'=>$postId, ':uid'=>$meId]);
      }
    } catch (Throwable $e) {
      jexit(['ok'=>false,'error'=>'Missing table public_post_shares (run SQL)', 'me_id'=>$meId]);
    }

    $shareCount = 0; $saveCount = 0; $myShared = 0; $mySaved = 0;
    try {
      $st = $dbh->prepare("SELECT COUNT(*) FROM public_post_shares WHERE post_id = ?");
      $st->execute([$postId]);
      $shareCount = (int)($st->fetchColumn() ?: 0);
      $st = $dbh->prepare("SELECT 1 FROM public_post_shares WHERE post_id = ? AND user_id = ? LIMIT 1");
      $st->execute([$postId, $meId]);
      $myShared = (int)($st->fetchColumn() ? 1 : 0);
    } catch (Throwable $e) {}
    try {
      $st = $dbh->prepare("SELECT COUNT(*) FROM public_post_saves WHERE post_id = ?");
      $st->execute([$postId]);
      $saveCount = (int)($st->fetchColumn() ?: 0);
      $st = $dbh->prepare("SELECT 1 FROM public_post_saves WHERE post_id = ? AND user_id = ? LIMIT 1");
      $st->execute([$postId, $meId]);
      $mySaved = (int)($st->fetchColumn() ? 1 : 0);
    } catch (Throwable $e) {}

    jexit([
      'ok' => true,
      'me_id' => $meId,
      'share_count' => $shareCount,
      'save_count' => $saveCount,
      'state' => [ 'shared' => $myShared, 'saved' => $mySaved ]
    ]);
  }

  // ---------------------------
  // SAVE (one-time per user per post)
  // ---------------------------
  if ($ajax === 'save') {
    staff_pub_api_deny_write($meId);
    $postId = (int)($_POST['post_id'] ?? 0);
    if ($postId <= 0) jexit(['ok'=>false,'error'=>'Missing post id', 'me_id'=>$meId]);

    try {
      $stAccess = $dbh->prepare("SELECT user_id, visibility FROM public_posts WHERE id = :pid AND is_deleted = 0 LIMIT 1");
      $stAccess->execute([':pid' => $postId]);
      $accessRow = $stAccess->fetch(PDO::FETCH_ASSOC) ?: [];
      if (!$accessRow || !publisher_post_interaction_allowed($dbh, $meId, $accessRow)) {
        jexit(['ok'=>false,'error'=>'Follow this publisher to interact. Their posts appear in your Feed after you follow.','me_id'=>$meId]);
      }

      $st = $dbh->prepare("SELECT 1 FROM public_post_saves WHERE post_id = :pid AND user_id = :uid LIMIT 1");
      $st->execute([':pid'=>$postId, ':uid'=>$meId]);
      $exists = (bool)$st->fetchColumn();

      if ($exists) {
        $st = $dbh->prepare("DELETE FROM public_post_saves WHERE post_id = :pid AND user_id = :uid LIMIT 1");
        $st->execute([':pid'=>$postId, ':uid'=>$meId]);
      } else {
        $st = $dbh->prepare("INSERT INTO public_post_saves (post_id, user_id, saved_at) VALUES (:pid,:uid,NOW())");
        $st->execute([':pid'=>$postId, ':uid'=>$meId]);
      }
    } catch (Throwable $e) {
      jexit(['ok'=>false,'error'=>'Missing table public_post_saves (run SQL)', 'me_id'=>$meId]);
    }

    $shareCount = 0; $saveCount = 0; $myShared = 0; $mySaved = 0;
    try {
      $st = $dbh->prepare("SELECT COUNT(*) FROM public_post_shares WHERE post_id = ?");
      $st->execute([$postId]);
      $shareCount = (int)($st->fetchColumn() ?: 0);
      $st = $dbh->prepare("SELECT 1 FROM public_post_shares WHERE post_id = ? AND user_id = ? LIMIT 1");
      $st->execute([$postId, $meId]);
      $myShared = (int)($st->fetchColumn() ? 1 : 0);
    } catch (Throwable $e) {}
    try {
      $st = $dbh->prepare("SELECT COUNT(*) FROM public_post_saves WHERE post_id = ?");
      $st->execute([$postId]);
      $saveCount = (int)($st->fetchColumn() ?: 0);
      $st = $dbh->prepare("SELECT 1 FROM public_post_saves WHERE post_id = ? AND user_id = ? LIMIT 1");
      $st->execute([$postId, $meId]);
      $mySaved = (int)($st->fetchColumn() ? 1 : 0);
    } catch (Throwable $e) {}

    jexit([
      'ok' => true,
      'me_id' => $meId,
      'share_count' => $shareCount,
      'save_count' => $saveCount,
      'state' => [ 'shared' => $myShared, 'saved' => $mySaved ]
    ]);
  }

  // ---------------------------
  // DELETE POST
  // ---------------------------
  if ($ajax === "delete" || $ajax === "delete_post" || (isset($_POST["action"]) && (string)$_POST["action"] === "delete_post") || (isset($_POST["action"]) && (string)$_POST["action"] === "delete")) {
    staff_pub_api_deny_write($meId);
    $postId = (int)($_POST["post_id"] ?? $_POST["id"] ?? 0);
    if ($postId <= 0) jexit(["ok"=>false,"error"=>"Missing post id", "me_id"=>$meId]);

    // Only owner can delete in public_user
    $stP = $dbh->prepare("SELECT id, user_id FROM public_posts WHERE id = :id AND is_deleted = 0 LIMIT 1");
    $stP->execute([":id"=>$postId]);
    $p = $stP->fetch(PDO::FETCH_ASSOC);
    if (!$p) jexit(["ok"=>false,"error"=>"Post not found", "me_id"=>$meId]);
    if ((int)$p["user_id"] !== $meId) jexit(["ok"=>false,"error"=>"Not allowed", "me_id"=>$meId]);

    // Gather attachment paths for disk cleanup
    $stA = $dbh->prepare("SELECT file_path, thumb_path FROM public_post_attachments WHERE post_id = :pid");
    $stA->execute([":pid"=>$postId]);
    $files = $stA->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $dbh->beginTransaction();

    // Delete children first
    $dbh->prepare("DELETE FROM public_post_comments WHERE post_id = :pid")->execute([":pid"=>$postId]);
    $dbh->prepare("DELETE FROM public_post_reactions WHERE post_id = :pid")->execute([":pid"=>$postId]);
    $dbh->prepare("DELETE FROM public_post_reads WHERE post_id = :pid")->execute([":pid"=>$postId]);
    $dbh->prepare("DELETE FROM public_post_views WHERE post_id = :pid")->execute([":pid"=>$postId]);
    $dbh->prepare("DELETE FROM public_post_view_daily WHERE post_id = :pid")->execute([":pid"=>$postId]);
    $dbh->prepare("DELETE FROM public_post_attachments WHERE post_id = :pid")->execute([":pid"=>$postId]);

    // Delete post permanently
    $dbh->prepare("DELETE FROM public_posts WHERE id = :id")->execute([":id"=>$postId]);

    $dbh->commit();

    // Best-effort delete files from disk
    foreach ($files as $f) {
      foreach (["file_path","thumb_path"] as $k) {
        $pp = (string)($f[$k] ?? "");
        if ($pp === "") continue;
        $pp = preg_replace("#^public_user/#", "", $pp);
        $full = __DIR__ . "/" . ltrim($pp, "/");
        if (is_file($full)) { @unlink($full); }
      }
    }

    jexit(["ok"=>true, "me_id"=>$meId, "post_id"=>$postId]);
  }

  // COMMENT LIKE (heart)
  // ---------------------------
  if ($ajax === 'comment_like') {
    staff_pub_api_deny_write($meId);
    $postId    = (int)($_POST['post_id'] ?? 0);
    $commentId = (int)($_POST['comment_id'] ?? 0);
    $requestedReaction = strtolower(trim((string)($_POST['reaction'] ?? 'like')));
    $reaction  = $requestedReaction === 'none'
      ? 'none'
      : (feedNormalizePostReaction($requestedReaction) ?: 'like');

    if ($postId <= 0 || $commentId <= 0) jexit(['ok'=>false,'error'=>'Missing ids','me_id'=>$meId]);

    $postOwnerId = 0;
    $postVisibility = 'friends';
    try {
      $stPost = $dbh->prepare("SELECT user_id, visibility FROM public_posts WHERE id = :pid AND is_deleted = 0 LIMIT 1");
      $stPost->execute([':pid' => $postId]);
      $postRow = $stPost->fetch(PDO::FETCH_ASSOC) ?: [];
      $postOwnerId = (int)($postRow['user_id'] ?? 0);
      $postVisibility = trim((string)($postRow['visibility'] ?? 'friends')) ?: 'friends';
    } catch (Throwable $e) {}

    if ($postOwnerId <= 0 || !publisher_post_interaction_allowed($dbh, $meId, ['user_id' => $postOwnerId, 'visibility' => $postVisibility])) {
      jexit(['ok'=>false,'error'=>'Follow this publisher to react and comment. Their posts appear in your Feed after you follow.','me_id'=>$meId]);
    }

    // Ensure comment belongs to post
    $stC = $dbh->prepare("SELECT id, user_id FROM public_post_comments WHERE id = :cid AND post_id = :pid AND is_deleted = 0 LIMIT 1");
    $stC->execute([':cid'=>$commentId, ':pid'=>$postId]);
    $commentRow = $stC->fetch(PDO::FETCH_ASSOC) ?: [];
    if (!$commentRow) jexit(['ok'=>false,'error'=>'Comment not found','me_id'=>$meId]);
    $commentOwnerId = (int)($commentRow['user_id'] ?? 0);

    $hasReactionColumn = feedCommentReactionColumnExists($dbh);
    $previousReaction = '';
    $stHas = $dbh->prepare($hasReactionColumn
      ? "SELECT reaction FROM public_comment_likes WHERE comment_id = :cid AND user_id = :uid LIMIT 1"
      : "SELECT 1 FROM public_comment_likes WHERE comment_id = :cid AND user_id = :uid LIMIT 1"
    );
    try {
      $stHas->execute([':cid'=>$commentId, ':uid'=>$meId]);
      $prev = $stHas->fetchColumn();
      if ($hasReactionColumn) {
        $previousReaction = feedNormalizePostReaction((string)($prev ?? ''));
        $has = $previousReaction !== '';
      } else {
        $has = (bool)$prev;
        $previousReaction = $has ? 'like' : '';
      }
    } catch (Throwable $e) {
      jexit(['ok'=>false,'error'=>'Missing table public_comment_likes (run SQL)','me_id'=>$meId]);
    }

    if ($reaction === 'none') {
      $dbh->prepare("
        DELETE FROM public_comment_likes
        WHERE comment_id = :cid AND user_id = :uid
        LIMIT 1
      ")->execute([':cid' => $commentId, ':uid' => $meId]);
      $liked = 0;
      $savedReaction = '';
    } elseif ($hasReactionColumn) {
      $dbh->prepare("
        INSERT INTO public_comment_likes (comment_id, user_id, reaction, created_at)
        VALUES (:cid,:uid,:reaction,NOW())
        ON DUPLICATE KEY UPDATE reaction = VALUES(reaction), created_at = VALUES(created_at)
      ")->execute([':cid'=>$commentId, ':uid'=>$meId, ':reaction'=>$reaction]);
      $liked = 1;
      $savedReaction = $reaction;
    } else {
      if (!$has) {
        $dbh->prepare("INSERT IGNORE INTO public_comment_likes (comment_id, user_id, created_at) VALUES (:cid,:uid,NOW())")
            ->execute([':cid'=>$commentId, ':uid'=>$meId]);
      }
      $liked = 1;
      $savedReaction = 'like';
    }

    if ($liked === 1 && $savedReaction !== $previousReaction) {
      feedAddNotification($dbh, $meId, $commentOwnerId, feedCommentReactionNotificationMessage($savedReaction), 'reaction', [
        'route' => feedRouteForPostOwner($commentOwnerId, $postOwnerId, $postVisibility),
        'post_id' => $postId,
        'comment_id' => $commentId,
      ]);
    }

    $stCnt = $dbh->prepare("SELECT COUNT(*) FROM public_comment_likes WHERE comment_id = :cid");
    $stCnt->execute([':cid'=>$commentId]);
    $cnt = (int)($stCnt->fetchColumn() ?: 0);

    jexit(['ok'=>true,'me_id'=>$meId,'comment_id'=>$commentId,'liked'=>$liked,'count'=>$cnt,'my_reaction'=>$savedReaction]);
  }

  // COMMENT
  // ---------------------------
  if ($ajax === 'comment') {
    staff_pub_api_deny_write($meId);
    $postId   = (int)($_POST['post_id'] ?? 0);
    $parentId = (int)($_POST['parent_id'] ?? 0);
    $text     = trim((string)($_POST['comment_text'] ?? ''));

    if ($postId <= 0) jexit(['ok'=>false,'error'=>'Missing post id', 'me_id'=>$meId]);
    if ($text === '') jexit(['ok'=>false,'error'=>'Empty comment', 'me_id'=>$meId]);

    $postOwnerId = 0;
    $postVisibility = 'friends';
    $parentOwnerId = 0;
    try {
      $stPost = $dbh->prepare("SELECT user_id, visibility FROM public_posts WHERE id = :pid AND is_deleted = 0 LIMIT 1");
      $stPost->execute([':pid' => $postId]);
      $postRow = $stPost->fetch(PDO::FETCH_ASSOC) ?: [];
      $postOwnerId = (int)($postRow['user_id'] ?? 0);
      $postVisibility = trim((string)($postRow['visibility'] ?? 'friends')) ?: 'friends';
    } catch (Throwable $e) {}

    if ($postOwnerId <= 0 || !publisher_post_interaction_allowed($dbh, $meId, ['user_id' => $postOwnerId, 'visibility' => $postVisibility])) {
      jexit(['ok'=>false,'error'=>'Follow this publisher to react and comment. Their posts appear in your Feed after you follow.','me_id'=>$meId]);
    }

    if ($parentId > 0) {
      $stP = $dbh->prepare("SELECT id, user_id FROM public_post_comments WHERE id = :cid AND post_id = :pid AND is_deleted = 0 LIMIT 1");
      $stP->execute([':cid'=>$parentId, ':pid'=>$postId]);
      $parentRow = $stP->fetch(PDO::FETCH_ASSOC) ?: [];
      if (!$parentRow) {
        $parentId = 0;
      } else {
        $parentOwnerId = (int)($parentRow['user_id'] ?? 0);
      }
    }

    $st = $dbh->prepare("
      INSERT INTO public_post_comments (post_id, user_id, parent_id, comment_text, created_at, is_deleted)
      VALUES (:pid, :uid, :parent, :txt, NOW(), 0)
    ");
    $st->execute([
      ':pid'    => $postId,
      ':uid'    => $meId,
      ':parent' => ($parentId > 0 ? $parentId : null),
      ':txt'    => $text
    ]);
    $newCommentId = (int)($dbh->lastInsertId() ?: 0);

    $dbh->prepare("UPDATE public_posts SET updated_at = NOW() WHERE id = :pid LIMIT 1")
        ->execute([':pid' => $postId]);

    if ($parentId > 0) {
      feedAddNotification($dbh, $meId, $parentOwnerId, 'replied to your comment', 'comment', [
        'route' => feedRouteForPostOwner($parentOwnerId, $postOwnerId, $postVisibility),
        'post_id' => $postId,
        'comment_id' => $newCommentId,
      ]);
      if ($postOwnerId > 0 && $postOwnerId !== $meId && $postOwnerId !== $parentOwnerId) {
        feedAddNotification($dbh, $meId, $postOwnerId, 'commented on your post', 'comment', [
          'route' => feedRouteForPostOwner($postOwnerId, $postOwnerId, $postVisibility),
          'post_id' => $postId,
          'comment_id' => $newCommentId,
        ]);
      }
    } else {
      feedAddNotification($dbh, $meId, $postOwnerId, 'commented on your post', 'comment', [
        'route' => feedRouteForPostOwner($postOwnerId, $postOwnerId, $postVisibility),
        'post_id' => $postId,
        'comment_id' => $newCommentId,
      ]);
    }

    jexit(['ok'=>true, 'me_id'=>$meId]);
  }


  // ---------------------------
  // VIEWERS LIST (owner only)
  // ---------------------------
  if ($ajax === 'viewers') {
    $postId = (int)($_GET['post_id'] ?? $_GET['id'] ?? 0);
    if ($postId <= 0) jexit(['ok'=>false,'error'=>'Missing post id','me_id'=>$meId]);

    // ensure owner
    $stO = $dbh->prepare("SELECT user_id, COALESCE(views_count,0) AS views_count FROM public_posts WHERE id = :id AND is_deleted = 0 LIMIT 1");
    $stO->execute([':id'=>$postId]);
    $rowO = $stO->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$rowO) jexit(['ok'=>false,'error'=>'Post not found','me_id'=>$meId]);
    if ((int)$rowO['user_id'] !== $meId) jexit(['ok'=>false,'error'=>'Not allowed','me_id'=>$meId]);

    $limit = clamp_int($_GET['limit'] ?? 200, 1, 500, 200);

    try {
      $stV = $dbh->prepare("
        SELECT v.user_id, v.viewed_at,
               u.username, COALESCE(u.name,u.username) AS display_name
        FROM public_post_views v
        JOIN users u ON u.id = v.user_id
        WHERE v.post_id = :pid
        ORDER BY v.viewed_at DESC
        LIMIT {$limit}
      ");
      $stV->execute([':pid'=>$postId]);
      $viewers = $stV->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
      $viewers = [];
    }

    jexit([
      'ok'=>true,
      'me_id'=>$meId,
      'post_id'=>$postId,
      'views_count'=>(int)($rowO['views_count'] ?? 0),
      'viewers'=>$viewers
    ]);
  }

  // ---------------------------
  // VIEW STATS (owner only)
  // ---------------------------
  if ($ajax === 'view_stats') {
    $postId = (int)($_GET['post_id'] ?? $_GET['id'] ?? 0);
    if ($postId <= 0) jexit(['ok'=>false,'error'=>'Missing post id','me_id'=>$meId]);

    // ensure owner
    $stO = $dbh->prepare("SELECT user_id, COALESCE(views_count,0) AS views_count FROM public_posts WHERE id = :id AND is_deleted = 0 LIMIT 1");
    $stO->execute([':id'=>$postId]);
    $rowO = $stO->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$rowO) jexit(['ok'=>false,'error'=>'Post not found','me_id'=>$meId]);
    if ((int)$rowO['user_id'] !== $meId) jexit(['ok'=>false,'error'=>'Not allowed','me_id'=>$meId]);

    $days = clamp_int($_GET['days'] ?? 30, 1, 90, 30);

    $series = [];
    $sum7 = 0;
    $sum30 = 0;

    try {
      $stS = $dbh->prepare("
        SELECT view_date, views
        FROM public_post_view_daily
        WHERE post_id = :pid
          AND view_date >= DATE_SUB(CURDATE(), INTERVAL :days DAY)
        ORDER BY view_date ASC
      ");
      // MySQL doesn't allow binding interval directly in all modes; bind as int and use it in expression:
      // We'll run a safe fallback if needed.
      $stS->execute([':pid'=>$postId, ':days'=>$days]);
      $rows = $stS->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
      // fallback without binding in INTERVAL
      try {
        $days = (int)$days;
        $stS = $dbh->prepare("
          SELECT view_date, views
          FROM public_post_view_daily
          WHERE post_id = :pid
            AND view_date >= DATE_SUB(CURDATE(), INTERVAL {$days} DAY)
          ORDER BY view_date ASC
        ");
        $stS->execute([':pid'=>$postId]);
        $rows = $stS->fetchAll(PDO::FETCH_ASSOC) ?: [];
      } catch (Throwable $e2) {
        $rows = [];
      }
    }

    // build series & sums
    foreach ($rows as $r) {
      $d = (string)($r['view_date'] ?? '');
      $v = (int)($r['views'] ?? 0);
      $series[] = ['date'=>$d, 'views'=>$v];
      // crude sums (last 7 and last 30 based on date compare)
      // we'll compute via SQL too for accuracy
    }

    try {
      $st7 = $dbh->prepare("SELECT COALESCE(SUM(views),0) FROM public_post_view_daily WHERE post_id = ? AND view_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)");
      $st7->execute([$postId]);
      $sum7 = (int)($st7->fetchColumn() ?: 0);

      $st30 = $dbh->prepare("SELECT COALESCE(SUM(views),0) FROM public_post_view_daily WHERE post_id = ? AND view_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)");
      $st30->execute([$postId]);
      $sum30 = (int)($st30->fetchColumn() ?: 0);
    } catch (Throwable $e) {
      $sum7 = 0; $sum30 = 0;
    }

    jexit([
      'ok'=>true,
      'me_id'=>$meId,
      'post_id'=>$postId,
      'views_total'=>(int)($rowO['views_count'] ?? 0),
      'views_7d'=>$sum7,
      'views_30d'=>$sum30,
      'series'=>$series
    ]);
  }


  jexit(['ok'=>false,'error'=>'Unknown action', 'me_id'=>$meId]);

} catch (Throwable $e) {
  if ($DEBUG) {
    jexit([
      'ok' => false,
      'error' => 'Server error (debug)',
      'details' => $e->getMessage(),
      'file' => basename($e->getFile()),
      'line' => $e->getLine(),
      'me_id' => $meId
    ]);
  }
  jexit(['ok'=>false,'error'=>'Server error', 'me_id'=>$meId]);
}
