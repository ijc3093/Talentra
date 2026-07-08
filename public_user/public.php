<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/session_user.php';
requireUserLogin();
require_once __DIR__ . '/controller.php';
require_once __DIR__ . '/includes/friend_system.php';
require_once __DIR__ . '/includes/publisher_accounts.php';
require_once __DIR__ . '/includes/publisher_organization_bridge.php';
require_once __DIR__ . '/includes/staff_publisher_access.php';
require_once __DIR__ . '/includes/device_profile.php';
require_once __DIR__ . '/includes/post_layout.php';
require_once __DIR__ . '/includes/theme_prefs.php';
require_once __DIR__ . '/includes/post_card_actions_menu.php';
require_once __DIR__ . '/includes/post_action_thin_icons.php';

error_reporting(E_ALL);
ini_set('display_errors', '0');

$controller = new Controller();
$dbh = $controller->pdo();
publisher_ensure_schema($dbh);
device_profile_ensure_post_columns($dbh);
$meId = (int)($_SESSION['user_id'] ?? 0);
$canFollowPublishers = publisher_can_follow_as_viewer($dbh, $meId);
$isPublisherWorkspaceViewer = publisher_workspace_viewer($dbh, $meId);
$canFollowOnPublicMenu = $canFollowPublishers || $isPublisherWorkspaceViewer;
$staffReadonly = staff_pub_is_readonly();
$canLiveStudio = live_studio_user_can_access($dbh, $meId);
$feedLeftRailPublicPublishers = staff_pub_menu_for_viewer($dbh, $meId);
$feedSurface = (defined('MSB_PUBLIC_FEED_SURFACE') && MSB_PUBLIC_FEED_SURFACE === 'news') ? 'news' : 'public';
$selfPage = $feedSurface === 'news' ? 'news.php' : 'public.php';
$pageTitle = $feedSurface === 'news' ? 'News' : 'Public';
$isNewsSurface = ($feedSurface === 'news');
$q = trim((string)($_GET['q'] ?? ''));
$publicAlertPostId = (int)($_GET['open_post'] ?? $_GET['post'] ?? 0);
$publicAlertCommentId = (int)($_GET['open_comment'] ?? 0);
$publicStoryPostId = (int)($_GET['story_post'] ?? 0);
$publicUploadWarn = (string)($_GET['upload_warn'] ?? '') === '1';

if ($q !== '' && $meId > 0 && !$isNewsSurface) {
    try {
        $stMe = $dbh->prepare('
            SELECT id, name, username, friend_code, account_kind, publisher_category
            FROM users
            WHERE id = :id
            LIMIT 1
        ');
        $stMe->execute([':id' => $meId]);
        $meRow = $stMe->fetch(PDO::FETCH_ASSOC) ?: [];
        $myName = publisher_registry_normalize_name((string)($meRow['name'] ?? ''));
        $qName = publisher_registry_normalize_name($q);
        if (
            !$staffReadonly
            && $myName !== ''
            && strcasecmp($myName, $qName) === 0
            && publisher_user_row_looks_like_publisher($dbh, $meRow)
        ) {
            publisher_org_sync_public_user_orgs($dbh, $meId);
            $orgId = (int)(publisher_org_fetch_public_user_orgs($dbh, $meId)[0]['id'] ?? 0);
            header('Location: publisher_org_portal.php' . ($orgId > 0 ? ('?org_id=' . $orgId) : ''));
            exit;
        }
        if (
            $staffReadonly
            && $myName !== ''
            && strcasecmp($myName, $qName) === 0
        ) {
            $orgId = staff_pub_org_id();
            header('Location: staff_org_portal.php' . ($orgId > 0 ? ('?org_id=' . $orgId) : ''));
            exit;
        }
    } catch (Throwable $e) {
        // fall through to public search feed
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'delete_post') {
        if ($staffReadonly) {
            header('Location: ' . $selfPage);
            exit;
        }
        $postId = (int)($_POST['post_id'] ?? 0);
        if ($postId > 0 && $meId > 0) {
            try {
                $stDel = $dbh->prepare("UPDATE public_posts SET is_deleted = 1, updated_at = NOW() WHERE id = :id AND user_id = :uid LIMIT 1");
                $stDel->execute([':id' => $postId, ':uid' => $meId]);
            } catch (Throwable $e) {
                // keep page usable even if delete fails
            }
        }
        $qs = [];
        if ($q !== '') $qs['q'] = $q;
        $url = $selfPage . (!empty($qs) ? ('?' . http_build_query($qs)) : '');
        header('Location: ' . $url);
        exit;
    }
}

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

function user_avatar_label(array $row): string {
    $name = trim((string)($row['display_name'] ?? $row['username'] ?? 'U'));
    $parts = preg_split('/\s+/', $name) ?: [];
    if (count($parts) >= 2) return strtoupper(substr($parts[0], 0, 1) . substr($parts[1], 0, 1));
    return strtoupper(substr($name, 0, 2) ?: 'U');
}

function user_avatar_url(array $row, int $size = 96): string {
    $params = [];
    $userId = (int)($row['user_id'] ?? $row['id'] ?? 0);
    $email = trim((string)($row['email'] ?? ''));
    $friendCode = strtoupper(trim((string)($row['friend_code'] ?? '')));
    $username = trim((string)($row['username'] ?? ''));
    $name = trim((string)($row['display_name'] ?? $row['name'] ?? $username ?? 'User'));
    if ($userId > 0) $params[] = 'u=' . rawurlencode((string)$userId);
    if ($email !== '') $params[] = 'email=' . rawurlencode($email);
    if ($friendCode !== '') $params[] = 'friend_code=' . rawurlencode($friendCode);
    if ($username !== '') $params[] = 'username=' . rawurlencode($username);
    if ($name !== '') $params[] = 'name=' . rawurlencode($name);
    $params[] = 's=' . rawurlencode((string)$size);
    return 'avatar.php?' . implode('&', $params);
}

function media_src(string $path): string {
    $path = trim($path);
    if ($path === '') return '';
    if (preg_match('~^(https?:)?//~i', $path)) return $path;
    if ($path[0] === '/') return $path;
    return './' . ltrim($path, './');
}

function public_profile_href(array $post, int $postId = 0): string {
    $params = [];
    $friendCode = strtoupper(trim((string)($post['friend_code'] ?? '')));
    $username = trim((string)($post['username'] ?? ''));
    $userId = (int)($post['user_id'] ?? 0);

    if ($friendCode !== '') {
        $params[] = 'friend_code=' . rawurlencode($friendCode);
    } elseif ($username !== '') {
        $params[] = 'username=' . rawurlencode($username);
    } elseif ($userId > 0) {
        $params[] = 'id=' . rawurlencode((string)$userId);
    }

    if ($postId > 0) {
        $params[] = 'from=public';
        $params[] = 'post_id=' . rawurlencode((string)$postId);
    }

    return 'profile.php' . ($params ? ('?' . implode('&', $params)) : '');
}

function firstExistingPostLayoutColumn(PDO $dbh): ?string {
    static $cached = false;
    static $found = null;
    if ($cached) return $found;
    $cached = true;
    try {
        $rows = $dbh->query("SHOW COLUMNS FROM public_posts")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $fields = array_map(static fn(array $r): string => (string)($r['Field'] ?? ''), $rows);
        foreach (['layout_type','layout','post_type','type'] as $candidate) {
            if (in_array($candidate, $fields, true)) {
                $found = $candidate;
                break;
            }
        }
    } catch (Throwable $e) {
        $found = null;
    }
    return $found;
}

function extract_layout_override_marker(string $description): string {
    if (preg_match('/\[\[layout:([a-z0-9_]+)\]\]/i', $description, $m)) {
        return strtolower(trim((string)($m[1] ?? '')));
    }
    return '';
}

function public_reaction_is_like_lane(string $reaction): bool {
    return strtolower(trim($reaction)) === 'like';
}

function public_reaction_is_love_lane(string $reaction): bool {
    $reaction = strtolower(trim($reaction));
    return $reaction !== '' && $reaction !== 'like';
}

function strip_layout_override_marker(string $description): string {
    return trim((string)preg_replace('/\[\[layout:[a-z0-9_]+\]\]/i', '', $description));
}

function public_extract_live_id(string ...$parts): int {
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

function public_live_snapshot_version(int $liveId): string {
    if ($liveId <= 0) return '';
    $snapshot = __DIR__ . '/storage/live_snapshots/' . $liveId . '.jpg';
    if (!is_file($snapshot)) return '';
    $mtime = @filemtime($snapshot);
    return $mtime ? ('?v=' . $mtime) : '';
}

function public_fetch_live_meta(PDO $dbh, int $liveId): ?array {
    if ($liveId <= 0) return null;
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

        $visibility = strtolower(trim((string)($row['visibility'] ?? 'private')));
        if ($visibility !== 'public') return null;

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
            $snapshotUrl = 'storage/live_snapshots/' . $liveId . '.jpg' . public_live_snapshot_version($liveId);
        }

        return [
            'id' => $liveId,
            'user_id' => (int)($row['user_id'] ?? 0),
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

function limit_sentences(string $text, int $maxSentences = 3): string {
    $text = trim($text);
    if ($text === '' || $maxSentences < 1) return $text;

    $parts = preg_split('/(?<=[.!?])\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    if (count($parts) <= $maxSentences) {
        return $text;
    }

    return trim(implode(' ', array_slice($parts, 0, $maxSentences)));
}

function public_caption_card_html(string $caption, int $maxChars = 170): string {
    $caption = post_normalize_card_plain_text(trim($caption));
    if ($caption === '') {
        return '';
    }
    $formatted = post_format_card_text_html($caption);
    $needsClamp = mb_strlen($caption) > $maxChars;
    $class = 'post-card-caption-formatted' . ($needsClamp ? ' is-clamped' : '');
    return '<div class="' . $class . '">' . $formatted . '</div>';
}

$where = "p.is_deleted = 0 AND COALESCE(p.is_archived,0) = 0 AND p.visibility = 'public' AND COALESCE(p.updated_at,p.created_at) >= DATE_SUB(NOW(), INTERVAL 24 HOUR)";
$params = [];
$where .= ' AND ' . publisher_public_surface_scope_sql($dbh, $meId, $isNewsSurface);
$params = array_merge($params, publisher_public_surface_scope_params($dbh, $meId, $isNewsSurface));
if (publisher_public_stranger_surface($dbh, $meId)) {
    $where .= " AND (
        COALESCE(u.account_kind, 'personal') <> 'publisher'
        OR p.user_id = :pubBrandOwn
        OR " . publisher_public_discoverable_publisher_sql($dbh, 'u') . '
    )';
    $params[':pubBrandOwn'] = $meId;
}
if ($q !== '') {
    $where .= " AND (COALESCE(p.title,'') LIKE :qTitle OR COALESCE(p.body,'') LIKE :qBody OR COALESCE(u.name,u.username,'') LIKE :qName OR COALESCE(u.username,'') LIKE :qUser)";
    $qLike = '%' . $q . '%';
    $params[':qTitle'] = $qLike;
    $params[':qBody'] = $qLike;
    $params[':qName'] = $qLike;
    $params[':qUser'] = $qLike;
}

$layoutColumn = post_layout_column($dbh);
$layoutSelect = post_layout_select_sql($dbh);

$sql = "
SELECT
  p.id, p.user_id, COALESCE(p.title,'') AS title, COALESCE(p.description,'') AS description, COALESCE(p.body,'') AS body,
  COALESCE(p.views_count,0) AS views_count, p.created_at, COALESCE(p.updated_at,p.created_at) AS updated_at,
  COALESCE(p.device_label,'') AS device_label, COALESCE(p.device_viewport,'') AS device_viewport,
  COALESCE(p.music_title,'') AS music_title, COALESCE(p.music_artist,'') AS music_artist,
  COALESCE(p.is_archived,0) AS is_archived,
  COALESCE(u.name, u.username, CONCAT('User ', u.id)) AS display_name, COALESCE(u.username,'') AS username, COALESCE(u.friend_code,'') AS friend_code,
  COALESCE(u.account_kind, 'personal') AS account_kind,
  EXISTS(SELECT 1 FROM public_follows pf WHERE pf.follower_id = :meFollow AND pf.following_id = p.user_id) AS is_following,
  {$layoutSelect}
  (SELECT COUNT(*) FROM public_post_comments c WHERE c.post_id = p.id AND c.is_deleted = 0) AS comment_count,
  (SELECT COUNT(*) FROM public_post_reactions r WHERE r.post_id = p.id AND r.reaction <> 'love') AS like_count,
  (SELECT COUNT(*) FROM public_post_reactions r WHERE r.post_id = p.id AND r.reaction = 'love') AS love_count,
  (SELECT COUNT(*) FROM public_post_shares s WHERE s.post_id = p.id) AS share_count,
  (SELECT COUNT(*) FROM public_post_saves s WHERE s.post_id = p.id) AS save_count,
  (SELECT reaction FROM public_post_reactions r WHERE r.post_id = p.id AND r.user_id = :me LIMIT 1) AS my_reaction,
  EXISTS(SELECT 1 FROM public_post_shares s WHERE s.post_id = p.id AND s.user_id = :me2) AS my_shared,
  EXISTS(SELECT 1 FROM public_post_saves s WHERE s.post_id = p.id AND s.user_id = :me3) AS my_saved
FROM public_posts p
JOIN users u ON u.id = p.user_id
WHERE {$where}
ORDER BY COALESCE(p.updated_at,p.created_at) DESC
LIMIT 100";
$params[':me'] = $meId;
$params[':me2'] = $meId;
$params[':me3'] = $meId;
$params[':meFollow'] = $meId;

$st = $dbh->prepare($sql);
$st->execute($params);
$posts = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

foreach ($posts as $postIndex => &$post) {
    $pid = (int)$post['id'];
    $stA = $dbh->prepare("SELECT id, type, file_path, thumb_path FROM public_post_attachments WHERE post_id = :pid ORDER BY id ASC");
    $stA->execute([':pid' => $pid]);
    $attachments = $stA->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($attachments as &$a) {
        $a['file_path'] = media_src((string)($a['file_path'] ?? ''));
        $a['thumb_path'] = media_src((string)($a['thumb_path'] ?? ''));
    }
    unset($a);
    $post['attachments'] = $attachments;
    $post['friend_status'] = fs_friend_status($dbh, $meId, (int)$post['user_id']);
    $contactRow = post_card_contact_for_peer($dbh, $meId, (int)$post['user_id']);
    $post['contact_id'] = (int)($contactRow['contact_id'] ?? 0);
    $post['contact_name'] = (string)($contactRow['display_name'] ?? '');
    $liveId = public_extract_live_id(
        (string)($post['body'] ?? ''),
        (string)($post['description'] ?? ''),
        (string)($post['title'] ?? '')
    );
    if ($liveId > 0) {
        unset($posts[$postIndex]);
        continue;
    }
}
unset($post);
$posts = array_values($posts);

$storyPosts = [];
$feedPosts = [];
foreach ($posts as $post) {
    if (trim((string)($post['declared_layout'] ?? '')) === '') {
        $post['declared_layout'] = post_declared_layout($post);
    }
    if (post_is_story_only($post)) {
        $storyPosts[] = $post;
    } else {
        $feedPosts[] = $post;
    }
}
$posts = $feedPosts;

function public_story_time_ago(string $dt): string {
    $dt = trim($dt);
    if ($dt === '') {
        return '';
    }
    $ts = strtotime($dt);
    if ($ts === false) {
        return '';
    }
    $sec = time() - $ts;
    if ($sec < 60) {
        return 'now';
    }
    $mins = (int)floor($sec / 60);
    if ($mins < 60) {
        return $mins . 'm';
    }
    $hrs = (int)floor($sec / 3600);
    if ($hrs < 24) {
        return $hrs . 'h';
    }
    $days = (int)floor($sec / 86400);
    if ($days < 7) {
        return $days . 'd';
    }
    $weeks = (int)floor($days / 7);
    if ($weeks < 5) {
        return $weeks . 'w';
    }
    return date('M j', $ts);
}

require_once __DIR__ . '/includes/story_catalog_build.php';
$publicStoryCatalog = story_catalog_build_from_posts($storyPosts, 'public_story_time_ago');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
  <title><?= h($pageTitle) ?></title>
  <?php theme_prefs_print_head_bootstrap($dbh, $meId); ?>
  <link rel="stylesheet" href="./css/dark-auto.css">
  <script src="./js/dark-auto.js?v=6" defer></script>
  <link href="./lib/font-awesome/css/font-awesome.css" rel="stylesheet">
  <link href="./lib/Ionicons/css/ionicons.css" rel="stylesheet">
  <link rel="stylesheet" href="./css/shamcey.css">
  <script src="./lib/jquery/jquery.js"></script>
  <script src="./lib/popper.js/popper.js"></script>
  <script src="./lib/bootstrap/bootstrap.js"></script>
  <style>
    :root{
      --bg:#f5f7fb; --card:#fff; --line:#dbdbdb; --text:#0f172a; --muted:#64748b;
      --blue:#0095f6; --heart:#7c3aed; --sidew:var(--feedRailW, 84px);
    }
    *{box-sizing:border-box}
    html,body{height:100%}
    body{margin:0;background:var(--msb-palette-bg, var(--bg));color:var(--msb-palette-text, var(--text));font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif}
    a{text-decoration:none}
    body.public-leftbar-open{overflow-x:hidden}
    .js-open-comments{cursor:pointer}
    .js-open-comments:hover{opacity:.82}
    .post.public-post-card.is-alert-focus{box-shadow:0 0 0 3px rgba(59,130,246,.24), 0 22px 50px rgba(37,99,235,.18);}
    html.dark-auto .post.public-post-card.is-alert-focus{box-shadow:0 0 0 3px rgba(147,197,253,.28), 0 22px 50px rgba(2,6,23,.42);}

    .ig-shell{min-height:100vh}
    .ig-sidebar{position:fixed;left:0;top:0;bottom:0;width:var(--sidew);background:#fff;border-right:1px solid var(--line);padding:18px 12px 22px;display:flex;flex-direction:column;align-items:center;gap:14px;z-index:50}
    .ig-logo{width:56px;height:56px;border-radius:18px;background:linear-gradient(135deg,#4f46e5,#0ea5e9);display:flex;align-items:center;justify-content:center;color:#fff;font-weight:900;font-size:26px;box-shadow:0 12px 30px rgba(79,70,229,.22)}
    .ig-logo-label{font-size:11px;font-weight:800;color:#374151;line-height:1.1;text-align:center}
    .ig-nav{display:flex;flex-direction:column;gap:8px;width:100%;margin-top:8px}
    .ig-link{width:100%;height:54px;border-radius:16px;display:flex;align-items:center;justify-content:center;color:#111;font-size:28px;position:relative}
    .ig-link:hover,.ig-link.active{background:#f3f4f6;color:#000}
    .ig-link .dot{position:absolute;right:12px;top:12px;width:8px;height:8px;border-radius:50%;background:#ff3040}
    .ig-avatar-mini{margin-top:auto;width:36px;height:36px;border-radius:50%;background:linear-gradient(135deg,#0ea5e9,#8b5cf6);display:flex;align-items:center;justify-content:center;color:#fff;font-size:12px;font-weight:900}

    .ig-main{margin-left:var(--sidew);display:flex;justify-content:center;padding:100px 18px 110px}
    .ig-feed{width:min(100%,620px)}
    .yt-pagebar{display:flex;align-items:center;justify-content:space-between;gap:18px;min-height:72px;padding:16px 18px 14px;position:fixed;top:0;left:var(--sidew);right:0;z-index:120;background:#272b31;border-bottom:1px solid rgba(255,255,255,.08)}
    .yt-pagebar,
    .yt-pagebar .yt-brand,
    .yt-pagebar .search-input,
    .yt-pagebar .search-btn,
    .yt-pagebar .yt-mic-btn,
    .yt-pagebar .yt-signin{
      font-family:"Roboto","Helvetica Neue",Arial,sans-serif;
    }
    .yt-pagebar{font-size:.875rem;line-height:1.5}
    .yt-topbar-left,.yt-topbar-right{display:flex;align-items:center;gap:14px;flex:0 0 auto}
    .yt-topbar-center{flex:1 1 auto;display:flex;align-items:center;justify-content:center;min-width:0}
    .yt-icon-btn{display:inline-flex;align-items:center;justify-content:center;width:44px;height:44px;border-radius:50%;color:#fff;font-size:24px;background:transparent;border:0;cursor:pointer}
    .yt-brand{display:inline-flex;align-items:center;gap:10px;color:#fff;font-size:24px;font-weight:900;letter-spacing:-.03em}
    .search-card{width:min(100%,840px);border:0;border-radius:0;background:transparent;padding:0;margin:0}
    .search-row{display:flex;align-items:center;gap:10px;width:100%}
    .yt-search-shell{display:flex;align-items:center;width:min(100%,840px);min-width:0}
    .search-input{flex:1;height:52px;border:1px solid #3a3a3a;border-right:0;border-radius:999px 0 0 999px;padding:0 22px;font-size:15px;outline:none;background:#121212;color:#fff}
    .search-btn{flex:0 0 auto;width:88px;height:52px;border:1px solid #3a3a3a;border-radius:0 999px 999px 0;padding:0;font-weight:800;color:#fff;background:#222;cursor:pointer;white-space:nowrap;font-size:24px}
    .yt-mic-btn{display:inline-flex;align-items:center;justify-content:center;width:48px;height:48px;border-radius:50%;border:0;background:#181818;color:#fff;font-size:24px;cursor:pointer}
    .yt-signin{display:inline-flex;align-items:center;gap:8px;min-height:48px;padding:0 18px;border-radius:999px;border:1px solid rgba(255,255,255,.12);color:#fff;font-size:18px;font-weight:800}

    .post{
      background:var(--card);
      /* border:1px solid var(--line); */
      overflow:hidden;
      margin:0 0 26px;
      scroll-margin-top:24px
    }
    /* Feed-style dividers (match look/feel of feed.php):
       - vertical lines: container wrapper
       - horizontal top line: search bar bottom border
       - horizontal lines: each post card divider */
    body.feed-insta-ui .feed-desktop-center{
      border-left:1px solid var(--public-border-strong, rgba(15,23,42,.16));
      border-right:1px solid var(--public-border-strong, rgba(15,23,42,.16));
      box-sizing:border-box;
    }
    body.feed-insta-ui .feed-top-search{
      border-bottom:1px solid var(--public-border-strong, rgba(15,23,42,.16));
    }
    body.feed-insta-ui .post.public-post-card{
      margin:0 !important;
      border:0 !important;
      border-bottom:0 !important;
      border-radius:0 !important;
      /* Reliable 1px bottom divider across different post layouts (reel overlaps etc). */
      box-shadow:inset 0 -1px 0 var(--public-border-strong, rgba(15,23,42,.16)) !important;
      position:relative;
      width:100% !important;
      max-width:100% !important;
      display:block;
      box-sizing:border-box;
    }
    .post.is-single-video-post{
      width:min(100%,460px);
      max-width:100%;
      margin-left:auto;
      margin-right:auto;
    }
    .post.is-single-image-post{
      width:min(100%,460px);
      max-width:100%;
      margin-left:auto;
      margin-right:auto;
    }
    .post.is-multi-media-post{
      width:100%;
      max-width:100%;
      margin-left:auto;
      margin-right:auto;
    }
    .post.public-post-card:not(.is-reel-post){
      position:relative;
      background:var(--public-surface);
      border:1px solid var(--public-border-strong, var(--public-border)) !important;
      box-shadow:none;
    }
    .public-auto-progress{
      position:absolute;
      top:10px;
      left:14px;
      right:14px;
      height:4px;
      border-radius:999px;
      background:rgba(15,23,42,.10);
      overflow:hidden;
      z-index:8;
      pointer-events:none;
    }
    .public-auto-progress-bar{
      width:0%;
      height:100%;
      border-radius:inherit;
      background:linear-gradient(90deg, #60a5fa, #f8fafc);
      transition:none;
    }
    .post.is-reel-post .public-auto-progress{
      top:8px;
      left:10px;
      right:10px;
      background:rgba(255,255,255,.22);
      z-index:9;
    }
    .post.is-live-post .public-auto-progress{
      top:8px;
      left:10px;
      right:10px;
      background:rgba(255,255,255,.22);
      z-index:9;
    }
    .media-stage > .public-auto-progress{
      position:absolute;
      top:8px;
      left:14px;
      right:14px;
      height:4px;
      border-radius:999px;
      background:rgba(255,255,255,.28);
      overflow:hidden;
      z-index:6;
      pointer-events:none;
    }
    .media-stage > .public-auto-progress .public-auto-progress-bar{
      width:0%;
      height:100%;
      border-radius:inherit;
      background:linear-gradient(90deg, #60a5fa, #f8fafc);
      transition:none;
    }
    .post-header{display:flex;align-items:center;gap:12px;padding:14px 16px}
    .post.public-post-card:not(.is-reel-post) .post-header{
      display:none;
    }
    .post-author-link{display:flex;align-items:center;gap:12px;min-width:0;flex:1;color:inherit;text-decoration:none}
    .post-author-link:hover .name{text-decoration:none}
    .post-author-link:focus{outline:none}
    .post-author-link:focus-visible{outline:2px solid rgba(37,99,235,.35);outline-offset:4px;border-radius:14px}
    .avatar{
      width:44px;
      height:44px;
      flex:0 0 44px;
      padding:2px;
      border-radius:50%;
      background:linear-gradient(135deg, #0ea5e9 0%, #2563eb 58%, #f8fafc 100%);
      box-sizing:border-box;
      line-height:0;
    }
    .avatar-thumb,
    .avatar > img{
      display:block;
      width:100%;
      height:100%;
      border-radius:50%;
      border:2px solid #fff;
      object-fit:cover;
      background:#fff;
      box-sizing:border-box;
    }
    .avatar-thumb{
      overflow:hidden;
    }
    .avatar-thumb img{
      display:block;
      width:100%;
      height:100%;
      border-radius:50%;
      object-fit:cover;
      border:0;
    }
    .head-meta{min-width:0;flex:1}
    .head-meta .name-row{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
    .head-meta .name{font-weight:700;color:#111;font-size:14px;max-width:230px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
    .head-meta .time{color:var(--muted);font-size:13px}
    .head-meta .sub{font-size:12px;color:var(--muted);margin-top:2px}
    .post.public-post-card:not(.is-reel-post) .head-meta .name,
    .post.public-post-card:not(.is-reel-post) .post-author-link,
    .post.public-post-card:not(.is-reel-post) .more-btn{
      color:#111;
    }
    .post.public-post-card:not(.is-reel-post) .head-meta .time{
      color:#6b7280;
    }

    .friend-btn{border:1px solid var(--public-border);background:var(--public-surface);color:var(--public-text);border-radius:999px;padding:8px 13px;font-size:12px;font-weight:700;line-height:1;white-space:nowrap}
    .friend-btn.primary{background:var(--blue);border-color:var(--blue);color:#fff}
    .friend-btn.is-friends{background:#eefaf1;border-color:#cce8d1;color:#166534}
    .friend-btn.is-pending{background:#fff7ed;border-color:#fed7aa;color:#9a3412}
    .friend-btn.is-accept{background:#eff6ff;border-color:#bfdbfe;color:#1d4ed8}
    .publisher-follow-btn{border:1px solid var(--public-border);background:var(--public-surface);color:var(--public-text);border-radius:999px;padding:8px 13px;font-size:12px;font-weight:700;line-height:1;white-space:nowrap;cursor:pointer;flex:0 0 auto}
    .publisher-follow-btn.primary{background:var(--blue);border-color:var(--blue);color:#fff}
    .publisher-follow-btn.is-following{background:#111827;border-color:#111827;color:#fff}
    .post-card-head-actions{
      display:flex;
      align-items:center;
      justify-content:flex-end;
      gap:8px;
      flex:0 0 auto;
      margin-left:auto;
    }
    .post-card-head-actions .publisher-follow-btn,
    .post-card-head-actions .friend-btn{
      order:1;
    }
    .post-card-head-actions .more-btn,
    .post-card-head-actions .standard-text-more,
    .post-card-head-actions .standard-media-more,
    .post-card-head-actions .reel-more{
      order:2;
      flex:0 0 auto;
    }
    .post.public-post-card:not(.is-reel-post) .media-stage > .standard-media-top-actions .publisher-follow-btn,
    .post.public-post-card:not(.is-reel-post) .media-stage > .standard-media-top-actions .friend-btn{
      background:rgba(17,24,39,.62);
      border-color:rgba(255,255,255,.24);
      color:#fff;
    }
    .post.public-post-card:not(.is-reel-post) .media-stage > .standard-media-top-actions .publisher-follow-btn.primary,
    .post.public-post-card:not(.is-reel-post) .media-stage > .standard-media-top-actions .friend-btn.primary{
      background:var(--blue);
      border-color:var(--blue);
      color:#fff;
      margin-top: 15px;
      margin-right: 0;
    }

    .more-btn{background:none;border:none;color:#111;font-size:22px;padding:0 0 0 6px;line-height:1}
    .h3-txt{color:var(--public-text);}
    .post-copy{padding:0 16px 12px}
    .post-copy h3{font-size:15px;font-weight:700;margin:0 0 6px;color:var(--public-text)}
    .post-copy p{margin:0;white-space:pre-line;line-height:1.45;font-size:14px;color:var(--public-text)}
    .post.public-post-card:not(.is-reel-post) .post-copy{
      background:var(--public-surface);
      color:var(--public-text);
      padding-top:14px;
      padding-bottom:14px;
    }
    .standard-text-card{
      padding:16px 16px 14px;
      background:var(--public-surface);
      color:var(--public-text);
    }
    .standard-text-topbar{
      display:flex;
      align-items:flex-start;
      justify-content:space-between;
      gap:12px;
      margin-bottom:14px;
    }
    .standard-text-top-actions{
      display:flex;
      align-items:center;
      gap:8px;
      flex:0 0 auto;
      margin-left:auto;
    }
    .standard-text-author{
      display:flex;
      align-items:center;
      gap:10px;
      flex:1 1 auto;
      min-width:0;
      color:var(--public-text);
      text-decoration:none;
    }
    .standard-text-author:hover{color:var(--public-text);text-decoration:none}
    .standard-text-meta{
      min-width:0;
      display:flex;
      align-items:center;
      gap:8px;
      flex-wrap:wrap;
    }
    .standard-text-name{
      color:var(--public-text);
      font-size:16px;
      font-weight:800;
      line-height:1.2;
      max-width:170px;
      white-space:nowrap;
      overflow:hidden;
      text-overflow:ellipsis;
    }
    .standard-text-time{
      color:var(--public-muted);
      font-size:14px;
      line-height:1.2;
    }
    .standard-text-more{
      width:44px;
      height:44px;
      border:none;
      border-radius:999px;
      background:var(--public-surface-alt);
      color:var(--public-text);
      display:flex;
      align-items:center;
      justify-content:center;
      font-size:22px;
      flex:0 0 auto;
    }
    .standard-text-copy{
      color:var(--public-text);
    }
    .standard-text-title{
      margin:0 0 6px;
      color:var(--public-text);
      font-size:16px;
      font-weight:800;
      line-height:1.3;
    }
    .standard-text-caption{
      font-size:15px;
      line-height:1.5;
      color:var(--public-text);
      word-break:break-word;
      text-align:left;
    }
    .post-card-paragraph{
      margin:0 0 12px;
      text-align:left;
      white-space:normal;
      word-break:break-word;
      display:block;
    }
    .post-card-paragraph:last-child{
      margin-bottom:0;
    }
    .post-card-caption-formatted{
      text-align:left;
    }
    .post-card-caption-formatted.is-clamped{
      max-height:14em;
      overflow:hidden;
    }
    .standard-text-copy .open-inline{
      color:var(--public-muted);
      font-weight:800;
    }
    .standard-text-actions{
      display:flex;
      align-items:flex-start;
      justify-content:space-between;
      gap:16px;
      margin-top:14px;
      width:100%;
    }
    .standard-text-left,
    .standard-text-right{
      display:flex;
      align-items:flex-start;
      gap:18px;
      flex-wrap:wrap;
    }
    .standard-text-left{
      flex:1 1 auto;
      min-width:0;
      flex-direction:column;
      gap:10px;
    }
    .standard-text-row{
      display:flex;
      align-items:center;
      gap:18px;
      flex-wrap:wrap;
    }
    .standard-text-right{
      flex:0 0 auto;
      flex-direction:column;
      align-items:flex-end;
      gap:10px;
      text-align:right;
      margin-left:auto;
      min-width:max-content;
    }
    .standard-text-btn{
      background:none;
      border:none;
      padding:0;
      color:var(--public-text);
      display:inline-flex;
      align-items:center;
      gap:6px;
      font-size:14px;
      line-height:1;
      cursor:pointer;
    }
    .standard-text-btn i{color:var(--public-text) !important}
    .standard-text-btn.is-love i{color:var(--msb-love-color, #7c3aed) !important}
    .standard-text-btn.is-like i{color:#2563eb !important}
    .standard-text-btn.is-share i{color:#9ca3af !important}
    .standard-text-btn.is-save i{color:#f59e0b !important}
    .standard-text-btn .action-count{
      color:var(--public-muted);
      font-size:14px;
      font-weight:800;
      line-height:1;
    }
    .standard-text-comments,
    .standard-text-views{
      color:var(--public-muted);
      font-size:14px;
      line-height:1.3;
    }
    .standard-text-comments{cursor:pointer}
    .post.public-post-card:not(.is-reel-post) .post-copy h3,
    .post.public-post-card:not(.is-reel-post) .post-copy p,
    .post.public-post-card:not(.is-reel-post) .h3-txt{
      color:var(--public-text);
    }

    .media-stage{position:relative;background:transparent;overflow:hidden}
    .post.public-post-card:not(.is-reel-post) .media-stage::before{
      display:none;
    }
    .post.public-post-card:not(.is-reel-post) .media-stage.has-carousel .media-carousel,
    .post.public-post-card:not(.is-reel-post) .media-stage.has-carousel .media-slides,
    .post.public-post-card:not(.is-reel-post) .media-stage.has-carousel .media-slide{
      height:100%;
    }
    .media-stage.standard-video-stage{
      background:transparent;
      aspect-ratio:auto;
      max-height:none;
      overflow:visible;
    }
    .media-stage.standard-image-stage{
      background:transparent;
      aspect-ratio:auto;
      max-height:none;
      overflow:visible;
    }
    .media-stage video,.media-stage img{display:block;width:100%;height:auto;max-height:840px;background:transparent}
    .media-stage.standard-video-stage > video{
      width:100%;
      height:auto;
      max-height:min(78vh, 960px);
      background:transparent;
      border-radius:0;
    }
    .media-stage.standard-image-stage > img{
      width:100%;
      height:auto;
      max-height:min(78vh, 960px);
      background:transparent;
      border-radius:0;
      object-fit:contain;
      object-position:center center;
    }
    .media-stage video{object-fit:contain;object-position:center center}
    .media-stage img{object-fit:cover;object-position:center center}
    .post.public-post-card:not(.is-reel-post) .media-stage.standard-video-stage > video::-webkit-media-controls,
    .post.public-post-card:not(.is-reel-post) .media-stage.standard-video-stage > video::-webkit-media-controls-enclosure,
    .post.public-post-card:not(.is-reel-post) .media-stage .media-slide > video::-webkit-media-controls,
    .post.public-post-card:not(.is-reel-post) .media-stage .media-slide > video::-webkit-media-controls-enclosure{
      display:none !important;
      opacity:0 !important;
      pointer-events:none !important;
    }
    .single-portrait{aspect-ratio:9/13;max-height:850px;overflow:hidden}
    .single-portrait img,.single-portrait video{height:100%;width:100%}
    .single-portrait img{object-fit:cover;object-position:center center}
    .single-portrait video{object-fit:contain;object-position:center center}
    .single-landscape{
      /* aspect-ratio:4/3; */
    overflow:hidden}
    .single-landscape img,.single-landscape video{height:100%;width:100%}
    .single-landscape img{object-fit:cover;object-position:center center}
    .single-landscape video{object-fit:contain;object-position:center center}
    .single-square{
      /* aspect-ratio:1/1; */
      overflow:hidden}
    .single-square img,.single-square video{height:100%;width:100%}
    .single-square img{object-fit:cover;object-position:center center}
    .single-square video{object-fit:contain;object-position:center center}
    .media-stage.phone-shot.standard-image-stage > img{
      width:100%;
      height:auto;
      max-height:min(78vh, 960px);
      object-fit:contain;
      border-radius:0;
      background:transparent;
    }
    @media (max-width:767.98px){
      .media-stage.phone-shot{
        width:min(calc(100% - 44px), 300px);
        margin-inline:auto;
        overflow:hidden;
        max-height:460px;
        background:transparent;
        border-radius:28px;
        box-shadow:0 20px 44px rgba(0,0,0,.22);
        aspect-ratio:var(--device-ar-w, 375) / var(--device-ar-h, 667);
      }
      .media-stage.phone-shot img,
      .media-stage.phone-shot video{
        width:100%;
        height:100%;
        max-height:none;
      }
      .media-stage.phone-shot img{ object-fit:cover; }
      .media-stage.phone-shot video{ object-fit:contain; }
    }
    @media (min-width:768px){
      .media-stage.phone-shot{
        width:100%;
        max-width:100%;
        margin-inline:0;
        overflow:visible;
        max-height:none;
        background:transparent;
        border-radius:var(--post-media-radius, 0);
        box-shadow:none;
        aspect-ratio:auto;
      }
      .media-stage.phone-shot.standard-video-stage,
      .media-stage.phone-shot.standard-image-stage{
        overflow:visible;
        aspect-ratio:auto;
        border-radius:var(--post-media-radius, 0);
        box-shadow:none;
        max-height:none;
      }
      .media-stage.phone-shot.standard-video-stage > video,
      .media-stage.phone-shot.standard-image-stage > img{
        width:100%;
        height:auto;
        max-height:min(78vh, 960px);
        object-fit:contain;
        border-radius:var(--post-media-radius, 0);
        background:transparent;
      }
    }

    .media-carousel{position:relative;width:100%;height:100%}
    .media-slides{display:flex;width:100%;height:100%;transition:transform .28s ease}
    .media-slide{flex:0 0 100%;width:100%;height:100%;background:transparent;display:flex;align-items:center;justify-content:center}
    .media-slide > img,.media-slide > video{width:100%;height:100%;background:transparent}
    .media-slide > img{object-fit:cover;object-position:center center}
    .media-slide > video{object-fit:contain;object-position:center center}
    .media-stage.single-landscape .media-slide > img,.media-stage.single-landscape .media-slide > video,.media-stage.single-square .media-slide > img,.media-stage.single-square .media-slide > video,.media-stage.single-portrait .media-slide > img,.media-stage.single-portrait .media-slide > video{height:100%}
    .public-live-frame-wrap{
      padding:0 0 14px;
    }
    .public-live-frame{
      position:relative;
      display:block;
      overflow:hidden;
      background:
        radial-gradient(circle at top left, rgba(255,255,255,.22), transparent 28%),
        linear-gradient(180deg, #d8dee8 0%, #9ba7b9 40%, #1d2430 100%);
      box-shadow:0 24px 56px rgba(0,0,0,.22);
    }
    .public-live-open-hit{
      position:absolute;
      inset:0;
      z-index:1;
      display:block;
      text-decoration:none;
      color:transparent;
    }
    .public-live-frame img{
      position:absolute;
      inset:0;
      width:100%;
      height:100%;
      object-fit:cover;
      object-position:center center;
    }
    .public-live-placeholder{
      position:absolute;
      inset:0;
      z-index:1;
      display:flex;
      align-items:center;
      justify-content:center;
      padding:24px;
      background:
        radial-gradient(circle at top left, rgba(255,255,255,.18), transparent 30%),
        linear-gradient(180deg, #d7dde7 0%, #a5b0c2 38%, #293243 100%);
    }
    .public-live-placeholder-inner{
      display:flex;
      flex-direction:column;
      align-items:center;
      gap:12px;
      text-align:center;
      color:#fff;
    }
    .public-live-placeholder-avatar{
      width:74px;
      height:74px;
      border-radius:50%;
      padding:4px;
      background:linear-gradient(135deg,#1d4ed8 0%, #60a5fa 52%, #ffffff 100%);
      box-shadow:0 14px 28px rgba(0,0,0,.18);
    }
    .public-live-placeholder-avatar img{
      position:static;
      inset:auto;
      width:100%;
      height:100%;
      border-radius:50%;
      border:2px solid rgba(255,255,255,.94);
      object-fit:cover;
    }
    .public-live-placeholder-title{
      margin:0;
      font-size:22px;
      font-weight:900;
      line-height:1.1;
      text-shadow:0 3px 14px rgba(0,0,0,.24);
    }
    .public-live-placeholder-sub{
      margin:0;
      max-width:240px;
      font-size:14px;
      line-height:1.45;
      color:rgba(255,255,255,.92);
      text-shadow:0 2px 10px rgba(0,0,0,.24);
    }
    .public-live-overlay{
      position:absolute;
      inset:0;
      z-index:2;
      padding:0;
      display:block;
      color:#fff;
      pointer-events:none;
      background:
        linear-gradient(180deg, rgba(15,23,42,.08) 0%, rgba(15,23,42,.02) 32%, rgba(15,23,42,.42) 72%, rgba(15,23,42,.82) 100%);
    }
    .public-live-top,
    .public-live-bottom,
    .public-live-footer,
    .public-live-actionbar,
    .public-live-action-btn,
    .public-live-comments-link,
    .public-live-cta{
      pointer-events:auto;
    }
    .public-live-top{
      position:absolute;
      top:16px;
      left:16px;
      right:16px;
      display:flex;
      align-items:flex-start;
      justify-content:space-between;
      gap:12px;
      z-index:3;
    }
    .public-live-host{
      display:flex;
      align-items:center;
      gap:8px;
      min-width:0;
      max-width:calc(100% - 120px);
    }
    .public-live-host-avatar{
      width:46px;
      height:46px;
      border-radius:50%;
      padding:3px;
      flex:0 0 auto;
      background:linear-gradient(135deg,#1d4ed8 0%, #60a5fa 55%, #ffffff 100%);
      box-shadow:0 8px 24px rgba(0,0,0,.18);
    }
    .public-live-host-avatar img{
      position:static;
      inset:auto;
      width:100%;
      height:100%;
      border-radius:50%;
      border:2px solid rgba(255,255,255,.94);
      object-fit:cover;
    }
    .public-live-host-meta{
      min-width:0;
      display:flex;
      flex-direction:column;
      gap:3px;
    }
    .public-live-host-name{
      margin:0;
      color:#fff;
      font-size:18px;
      font-weight:900;
      line-height:1.1;
      white-space:nowrap;
      overflow:hidden;
      text-overflow:ellipsis;
      text-shadow:0 3px 14px rgba(0,0,0,.24);
    }
    .public-live-host-sub{
      margin:0;
      color:rgba(255,255,255,.88);
      font-size:13px;
      line-height:1.2;
      text-shadow:0 2px 10px rgba(0,0,0,.24);
    }
    .public-live-top-pills{
      display:flex;
      gap:8px;
      flex:0 0 auto;
    }
    .public-live-pill{
      display:inline-flex;
      align-items:center;
      gap:6px;
      min-height:38px;
      padding:8px 12px;
      border-radius:12px;
      background:rgba(17,24,39,.58);
      backdrop-filter:blur(8px);
      color:#fff;
      font-weight:800;
      font-size:14px;
      line-height:1;
      box-shadow:0 10px 24px rgba(0,0,0,.18);
    }
    .public-live-pill i{ font-size:18px; }
    .public-live-bottom{
      position:absolute;
      left:16px;
      right:16px;
      bottom:14px;
      display:flex;
      flex-direction:column;
      gap:12px;
      z-index:3;
    }
    .public-live-chip{
      align-self:flex-start;
      display:inline-flex;
      align-items:center;
      gap:8px;
      padding:8px 14px;
      border-radius:999px;
      background:rgba(239,68,68,.92);
      color:#fff;
      font-size:14px;
      font-weight:900;
      letter-spacing:.01em;
      text-transform:uppercase;
    }
    .public-live-copy{
      display:flex;
      flex-direction:column;
      gap:6px;
      order:2;
    }
    .public-live-title{
      margin:0;
      color:#fff;
      font-size:22px;
      line-height:1.1;
      font-weight:900;
      text-shadow:0 4px 16px rgba(0,0,0,.22);
    }
    .public-live-desc{
      margin:0;
      color:rgba(255,255,255,.94);
      font-size:13px;
      line-height:1.4;
      text-shadow:0 2px 12px rgba(0,0,0,.22);
    }
    .public-live-footer{
      display:flex;
      align-items:flex-end;
      justify-content:flex-start;
      gap:14px;
      order:1;
    }
    .public-live-cta{
      display:inline-flex;
      align-items:center;
      gap:10px;
      padding:12px 18px;
      border-radius:999px;
      background:rgba(255,255,255,.96);
      color:#0f172a;
      font-size:16px;
      font-weight:900;
      text-decoration:none;
      box-shadow:0 16px 30px rgba(15,23,42,.18);
      pointer-events:auto;
    }
    .public-live-cta i{ font-size:20px; }
    .public-live-actionbar{
      display:flex;
      align-items:flex-end;
      justify-content:space-between;
      gap:14px;
      margin-top:2px;
      order:3;
      pointer-events:auto;
    }
    .public-live-action-left{
      display:flex;
      flex-direction:column;
      gap:10px;
      min-width:0;
      flex:1 1 auto;
    }
    .public-live-action-row{
      display:flex;
      align-items:center;
      gap:18px;
      flex-wrap:wrap;
    }
    .public-live-action-spacer{
      margin-left:auto;
      display:inline-flex;
      align-items:flex-end;
    }
    .public-live-action-btn{
      background:none;
      border:none;
      padding:0;
      color:#fff;
      display:inline-flex;
      align-items:center;
      gap:6px;
      font-size:14px;
      line-height:1;
      cursor:pointer;
      text-decoration:none;
      text-shadow:0 2px 12px rgba(0,0,0,.32);
    }
    .public-live-action-btn i{ color:#fff !important; }
    .public-live-action-btn.is-love i{ color:var(--msb-love-color, #7c3aed) !important; }
    .public-live-action-btn.is-like i{ color:#60a5fa !important; }
    .public-live-action-btn.is-share i{ color:#d1d5db !important; }
    .public-live-action-btn.is-save i{ color:#fbbf24 !important; }
    .public-live-action-btn .action-count{
      color:#fff;
      font-size:14px;
      font-weight:800;
      line-height:1;
      text-shadow:0 2px 12px rgba(0,0,0,.32);
    }
    .public-live-comments-link{
      color:rgba(255,255,255,.94);
      font-size:14px;
      line-height:1.3;
      cursor:pointer;
      text-shadow:0 2px 12px rgba(0,0,0,.32);
    }

    .standard-media-topbar{
      position:absolute;
      left:0;
      right:0;
      top:0;
      z-index:5;
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap:10px;
      width:100%;
      box-sizing:border-box;
      padding:2px 5px 12px;
      pointer-events:none;
      background:transparent;
    }
    .standard-media-author{
      display:flex;
      align-items:center;
      gap:10px;
      min-width:0;
      flex:1 1 auto;
      color:#fff;
      text-decoration:none;
      pointer-events:auto;
      overflow:hidden;
    }
    .standard-media-author:hover{color:#fff;text-decoration:none}
    .standard-media-meta{
      min-width:0;
      flex:1 1 auto;
      display:flex;
      flex-direction:column;
      align-items:flex-start;
      justify-content:center;
      gap:0;
      overflow:hidden;
    }
    .standard-media-name-row{
      display:flex;
      align-items:center;
      gap:6px;
      min-width:0;
      max-width:100%;
      flex-wrap:nowrap;
    }
    .standard-media-name{
      color:#fff;
      font-size:15px;
      font-weight:800;
      line-height:1.2;
      min-width:0;
      flex:0 1 auto;
      max-width:none;
      white-space:nowrap;
      overflow:hidden;
      text-overflow:ellipsis;
      text-shadow:0 2px 10px rgba(0,0,0,.34);
    }
    .standard-media-time{
      color:rgba(255,255,255,.88);
      font-size:13px;
      line-height:1.2;
      flex:0 0 auto;
      white-space:nowrap;
      text-shadow:0 2px 10px rgba(0,0,0,.34);
    }
    .standard-media-topbar .mf-music-row{
      display:flex;
      align-items:center;
      gap:4px;
      min-width:0;
      max-width:100%;
      margin-top:1px;
      font-size:11px;
      line-height:1.2;
      font-weight:500;
      color:rgba(255,255,255,.88);
      text-shadow:0 2px 10px rgba(0,0,0,.34);
      overflow:hidden;
    }
    .standard-media-topbar .mf-music-ic{font-size:10px;flex:0 0 auto}
    .standard-media-topbar .mf-music-title,
    .standard-media-topbar .mf-music-artist{
      min-width:0;
      white-space:nowrap;
      overflow:hidden;
      text-overflow:ellipsis;
    }
    .standard-media-topbar .mf-music-title{flex:1 1 auto}
    .standard-media-topbar .mf-music-artist{flex:0 1 auto;max-width:46%}
    .standard-media-topbar .mf-music-dot{flex:0 0 auto;font-size:11px;opacity:.85}
    .standard-media-top-actions{
      display:flex;
      align-items:center;
      justify-content:flex-end;
      gap:8px;
      pointer-events:auto;
      flex:0 0 auto;
      margin-left:0;
    }
    .standard-media-topbar > .standard-media-more{
      flex:0 0 32px;
      margin-left:auto;
      pointer-events:auto;
      margin-right: -35px;
    }
    .standard-media-topbar > .post-card-menu-wrap{
      flex:0 0 32px;
      margin-left:auto;
      pointer-events:auto;
      margin-right: 0;
    }
    .post.public-post-card:not(.is-reel-post) .media-stage > .standard-media-top-actions{
      position:absolute;
      top:22px;
      right:calc(14px + 34px + 8px);
      z-index:6;
      pointer-events:auto;
    }
    .post.public-post-card:not(.is-reel-post) .media-stage > .standard-media-top-actions .publisher-follow-btn,
    .post.public-post-card:not(.is-reel-post) .media-stage > .standard-media-top-actions .friend-btn{
      padding:7px 12px;
      font-size:11px;
      line-height:1;
      flex-shrink:0;
      background:rgba(17,24,39,.62);
      border-color:rgba(255,255,255,.24);
      color:#fff;
    }
    .post.public-post-card:not(.is-reel-post) .media-stage > .standard-media-top-actions .publisher-follow-btn.primary,
    .post.public-post-card:not(.is-reel-post) .media-stage > .standard-media-top-actions .friend-btn.primary{
      background:var(--blue);
      border-color:var(--blue);
      color:#fff;
      margin-top: 15px;
      margin-right: 0;
    }
    .standard-media-topbar .standard-media-more{
      width:32px !important;
      height:32px !important;
      min-width:32px !important;
      min-height:32px !important;
      flex:0 0 32px !important;
      display:flex !important;
      align-items:center !important;
      justify-content:center !important;
      padding:0 !important;
      color:#fff !important;
      text-shadow:0 2px 10px rgba(0,0,0,.34);
    }
    .standard-media-topbar .standard-media-more i{
      font-size:20px !important;
      line-height:1 !important;
      color:#fff !important;
    }
    .post.public-post-card:not(.is-reel-post) .media-stage .standard-media-topbar{
      border-radius:var(--post-media-radius, 18px) var(--post-media-radius, 18px) 0 0;
    }
   
    .standard-media-bottom{
      position:absolute;
      left:0;
      right:0;
      bottom:0;
      z-index:5;
      padding:120px 18px 18px;
      background:none;
      color:#fff;
    }
    .standard-media-copy{
      color:#fff;
      text-shadow:0 2px 10px rgba(0,0,0,.34);
    }
    .standard-media-title{
      margin:0 0 6px;
      color:#fff;
      font-size:16px;
      font-weight:800;
      line-height:1.3;
    }
    .standard-media-caption{
      font-size:15px;
      line-height:1.45;
      color:#fff;
      word-break:break-word;
      text-align:left;
    }
    .standard-media-copy .open-inline{
      color:#fff;
      opacity:.92;
      font-weight:800;
      margin-left:6px;
    }
    .standard-media-actions{
      display:flex;
      align-items:flex-start;
      justify-content:space-between;
      gap:16px;
      margin-top:0px;
      width:100%;
      padding-top: 0px;
    }
    .standard-media-left,
    .standard-media-right{
      display:flex;
      align-items:flex-start;
      gap:18px;
      flex-wrap:wrap;
    }
    .standard-media-left{
      flex:1 1 auto;
      min-width:0;
      flex-direction:column;
      gap:10px;
    }
    .standard-media-row{
      display:flex;
      align-items:center;
      gap:18px;
      flex-wrap:wrap;
    }
    .standard-media-right{
      flex:0 0 auto;
      flex-direction:column;
      align-items:flex-end;
      gap:10px;
      text-align:right;
      margin-left:auto;
      min-width:max-content;
    }
    .standard-media-btn{
      background:none;
      border:none;
      padding:0;
      color:#fff;
      display:inline-flex;
      align-items:center;
      gap:6px;
      font-size:14px;
      line-height:1;
      cursor:pointer;
      text-shadow:0 1px 2px rgba(0,0,0,.55);
    }
    .standard-media-btn i{color:#fff !important}
    .standard-media-btn.is-love i{color:var(--msb-love-color, #7c3aed) !important}
    .standard-media-btn.is-like i{color:#2563eb !important}
    .standard-media-btn.is-share i{color:#9ca3af !important}
    .standard-media-btn.is-save i{color:#f59e0b !important}
    .standard-media-btn .action-count{
      color:#fff;
      font-size:14px;
      font-weight:800;
      line-height:1;
    }
    .standard-media-comments,
    .standard-media-views{
      color:#fff;
      font-size:14px;
      line-height:1.3;
      text-shadow:0 1px 2px rgba(0,0,0,.55);
    }
    .standard-media-comments{opacity:.92;cursor:pointer}
    .standard-media-views{opacity:.92}
    .media-nav{position:absolute;top:50%;transform:translateY(-50%);width:46px;height:46px;border:none;border-radius:999px;background:rgba(255,255,255,.82);color:#1f2937;display:flex;align-items:center;justify-content:center;font-size:20px;cursor:pointer;box-shadow:0 8px 24px rgba(0,0,0,.18);z-index:3}
    .media-nav:hover{background:#fff}
    .media-nav.prev{left:12px}
    .media-nav.next{right:12px}
    .media-dots{position:absolute;left:50%;bottom:8px;transform:translateX(-50%);display:flex;align-items:center;justify-content:center;gap:8px;padding:8px 14px;border-radius:999px;background:rgba(17,24,39,.34);z-index:3}
    .media-dot{
      width:10px !important;
      height:10px !important;
      min-width:10px !important;
      min-height:10px !important;
      flex:0 0 10px !important;
      display:block !important;
      border:none !important;
      border-radius:50% !important;
      padding:0 !important;
      margin:0 !important;
      background:rgba(255,255,255,.45) !important;
      cursor:pointer;
      appearance:none;
      -webkit-appearance:none;
      box-shadow:none !important;
      font-size:0 !important;
      line-height:0 !important;
      color:transparent !important;
      text-indent:-9999px !important;
      overflow:hidden !important;
    }
    .media-dot.is-active{background:#fff;transform:scale(1.08)}
    .post.public-post-card:not(.is-reel-post) .media-dots{
      bottom:18px;
      z-index:5;
      background:rgba(17,24,39,.5);
    }
    .file-tile{display:flex;align-items:center;justify-content:center;min-height:420px;color:#fff;padding:24px;text-align:center;width:100%;height:100%}

    .actions{padding:12px 16px 14px}
    .post.public-post-card:not(.is-reel-post) .actions{
      background:var(--public-surface);
      border-top:1px solid var(--public-border);
    }
    .action-row{display:flex;align-items:center;justify-content:space-between;gap:10px}
    .action-left,.action-right{display:flex;align-items:center;gap:26px}
    .action-btn{background:none;border:none;padding:0;color:var(--public-text);font-size:15px;line-height:1;display:inline-flex;align-items:center;justify-content:center;gap:7px;cursor:pointer}
    .action-btn:hover{opacity:.78}
    .action-btn i{
      color:#fff !important;
      text-shadow:0 1px 2px rgba(0,0,0,.55);
    }
    .action-btn .action-count{font-size:13px;font-weight:700;color:var(--public-muted);line-height:1}
    .action-btn.is-love i{color:var(--msb-love-color, #7c3aed) !important}
    .action-btn.is-like i{color:#2563eb !important}
    .action-btn.is-share i{color:#6b7280 !important}
    .action-btn.is-save i{color:#f59e0b !important}
    .likes-line,.caption-line,.comments-line,.meta-line{font-size:14px;line-height:1.45;color:var(--public-text);margin-top:8px}
    .likes-line strong,.caption-line strong{font-weight:700}
    .comments-line,.meta-line{color:var(--muted)}
    .meta-line{font-size:11px;text-transform:uppercase;letter-spacing:.06em}
    .caption-clamp{display:-webkit-box;-webkit-box-orient:vertical;-webkit-line-clamp:2;overflow:hidden}
    .open-inline{color:var(--muted);font-weight:600;margin-left:6px;cursor:pointer;text-decoration:none}
    .open-inline:hover{text-decoration:underline}
    .post.public-post-card:not(.is-reel-post) .likes-line,
    .post.public-post-card:not(.is-reel-post) .caption-line,
    .post.public-post-card:not(.is-reel-post) .comments-line,
    .post.public-post-card:not(.is-reel-post) .meta-line{
      color:var(--public-text);
    }
    .post.public-post-card:not(.is-reel-post) .comments-line,
    .post.public-post-card:not(.is-reel-post) .meta-line{
      color:var(--public-muted);
    }
    .post.public-post-card:not(.is-reel-post) .open-inline{
      color:var(--public-muted);
      font-weight:800;
    }

    /* reel */
    .post.is-reel-post{
      position:relative;
      background:var(--public-post-card-surface);
      border:1px solid var(--public-post-card-border);
      border-radius:0;
      overflow:hidden;
      box-shadow:none;
      color:var(--public-text);
    }
    .post.is-live-post{
      position:relative;
      background:var(--public-post-card-surface);
      border:1px solid var(--public-post-card-border);
      border-radius:0;
      overflow:visible;
      box-shadow:none;
      color:var(--public-text);
    }
    .post.is-reel-post .post-header{
      display:none;
    }
    .post.is-live-post .post-header{
      display:none;
    }
    .reel-topbar{
      position:relative;
      padding:18px 24px 14px;
      z-index:4;
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap:16px;
      pointer-events:auto;
      color:var(--public-text);
    }
    .reel-top-left{
      display:flex;
      align-items:center;
      gap:10px;
      min-width:0;
      flex:1 1 auto;
      pointer-events:auto;
    }
    .reel-stage{
      position:relative;
      width:calc(100% - 48px);
      margin:0 24px;
      min-height:auto;
      max-height:840px;
      background:transparent;
      overflow:hidden;
      border-radius:18px;
    }
    .reel-video{
      width:100%;
      height:auto;
      display:block;
      background:transparent;
    }
    video.reel-video{
      object-fit:contain;
      object-position:center center;
    }
    img.reel-video{
      object-fit:contain;
      object-position:center center;
    }
    .reel-top-author{
      display:flex;
      align-items:center;
      gap:10px;
      min-width:0;
      color:var(--public-text);
      text-decoration:none;
    }
    .reel-top-author:hover{color:var(--public-text);text-decoration:none}
    .reel-top-meta{
      min-width:0;
      display:flex;
      align-items:center;
      gap:8px;
      flex-wrap:wrap;
    }
    .reel-top-name{
      color:var(--public-text);
      font-size:18px;
      font-weight:900;
      line-height:1.2;
      max-width:240px;
      white-space:nowrap;
      overflow:hidden;
      text-overflow:ellipsis;
    }
    .reel-top-time{
      color:var(--public-muted);
      font-size:15px;
      line-height:1.2;
    }
    .reel-controls{ display:none !important; }
    .reel-top-right{
      display:flex;
      align-items:center;
      gap:10px;
      pointer-events:auto;
      flex:0 0 auto;
    }
    .reel-more{
      pointer-events:auto;
      width:28px;
      height:28px;
      border:none;
      border-radius:0;
      background:transparent;
      color:var(--public-text);
      display:flex;
      align-items:center;
      justify-content:center;
      font-size:22px;
    }
    .reel-side-actions{ display:none !important; }

    .reel-bottom{
      position:relative;
      z-index:4;
      padding:16px 24px 22px;
      background:transparent;
      color:var(--public-text);
    }
    .reel-caption{
      font-size:15px;
      line-height:1.45;
      color:var(--public-text);
      word-break:break-word;
      margin-bottom:10px;
      text-align:left;
    }
    .reel-caption.has-more .reel-caption-text{
      display:block;
    }
    .reel-caption .open-inline{
      color:var(--public-text);
      font-weight:800;
      margin-left:6px;
    }
    .reel-caption-text{
      display:block;
    }
    .reel-inline-actions{
      display:flex;
      align-items:flex-start;
      justify-content:space-between;
      gap:16px;
      margin-top:0;
      width:100%;
    }
    .reel-inline-left,
    .reel-inline-right{
      display:flex;
      align-items:flex-start;
      gap:18px;
      flex-wrap:wrap;
    }
    .reel-inline-left{
      flex:1 1 auto;
      min-width:0;
      flex-direction:column;
      gap:14px;
    }
    .reel-inline-row{
      display:flex;
      align-items:center;
      gap:18px;
      flex-wrap:wrap;
    }
    .reel-inline-right{
      flex:0 0 auto;
      flex-direction:column;
      align-items:flex-end;
      justify-content:flex-start;
      gap:14px;
      text-align:right;
      margin-left:auto;
      min-width:max-content;
      padding-right:0;
    }
    .reel-inline-btn{
      background:none;
      border:none;
      padding:0;
      color:var(--public-text);
      display:inline-flex;
      align-items:center;
      gap:6px;
      font-size:14px;
      line-height:1;
      cursor:pointer;
    }
    .reel-inline-btn i{color:var(--public-text) !important}
    .reel-inline-btn.is-love i{color:var(--msb-love-color, #7c3aed) !important}
    .reel-inline-btn.is-like i{color:#2563eb !important}
    .reel-inline-btn.is-share i{color:#9ca3af !important}
    .reel-inline-btn.is-save i{color:#f59e0b !important}
    .reel-inline-btn .action-count{
      color:var(--public-text);
      font-size:14px;
      font-weight:800;
      line-height:1;
    }
    .reel-inline-comments,
    .reel-inline-views{
      color:var(--public-muted);
      font-size:14px;
      line-height:1.3;
      cursor:pointer;
    }
    .reel-inline-right .reel-inline-btn{
      justify-content:flex-end;
      width:100%;
    }
    .reel-copy{
      background:#2f343a;
      color:#fff;
      padding:0 18px 6px;
      margin-top:-42px;
      position:relative;
      z-index:6;
    }
    .reel-copy .caption-line{
      color:#fff;
      margin-top:0;
    }
    .reel-copy .open-inline{
      color:#fff;
      font-weight:800;
    }
    .post.is-reel-post .actions{display:none}
    .post.is-live-post .actions{display:none}
    .msg-pill{position:fixed;right:24px;bottom:26px;background:#fff;border:1px solid rgba(0,0,0,.08);box-shadow:0 12px 28px rgba(0,0,0,.14);border-radius:999px;padding:16px 22px;display:flex;align-items:center;gap:14px;z-index:40;color:#111}
    .msg-pill .fa-paper-plane-o{font-size:28px}
    .msg-pill .txt{font-size:18px;font-weight:700}
    .toggle-bubbles{display:flex;align-items:center;gap:8px}
    .toggle-bubbles span{display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:50%;background:#f3f4f6;color:#777;font-size:10px;font-weight:700}
    .toggle-bubbles .on{width:22px;height:22px;background:linear-gradient(135deg,#7c3aed,#3b82f6);border:2px solid #fff;box-shadow:0 0 0 2px #d1d5db}

    .jump-rail{position:fixed;right:16px;top:50%;transform:translateY(-50%);display:flex;flex-direction:column;gap:10px;z-index:35}
    .jump-rail button{width:44px;height:44px;border:none;border-radius:50%;background:#111;color:#fff;box-shadow:0 10px 24px rgba(0,0,0,.18)}
    .mf-feed-empty{
      display:flex;
      flex-direction:column;
      align-items:center;
      justify-content:center;
      min-height:min(520px, calc(100vh - 320px));
      padding:48px 24px 56px;
      text-align:center;
      color:#667085;
    }
    .mf-feed-empty i{
      display:block;
      font-size:56px;
      line-height:1;
      margin:0 auto 16px;
      color:#98a2b3;
    }
    .mf-feed-empty .mf-feed-empty-title{
      font-size:17px;
      font-weight:700;
      color:#344054;
      margin:0;
      letter-spacing:-0.01em;
    }

    .post-sheet .modal-content,.confirm-sheet .modal-content{border:none;border-radius:18px;overflow:hidden;box-shadow:0 24px 60px rgba(0,0,0,.18)}
    .post-sheet .modal-dialog,.confirm-sheet .modal-dialog{max-width:420px}
    .sheet-list{padding:10px 0;background:#fff}
    .sheet-btn{width:100%;display:flex;align-items:center;justify-content:center;gap:10px;padding:16px 22px;border:none;background:#fff;color:#111;font-size:17px;font-weight:700;border-bottom:1px solid #f1f5f9}
    .sheet-btn:last-child{border-bottom:none}
    .sheet-btn:hover{background:#f8fafc}
    .sheet-btn.primary{color:var(--blue)}
    .sheet-btn.is-friends{color:#166534}
    .sheet-btn.is-pending{color:#9a3412}
    .sheet-btn.is-accept{color:#1d4ed8}
    .sheet-btn.danger{color:#dc2626}
    .sheet-cancel{background:#f8fafc;color:#374151}
    .confirm-sheet .modal-body{padding:28px 22px 14px;text-align:center}
    .confirm-sheet .confirm-title{font-size:20px;font-weight:800;color:#111;margin-bottom:8px}
    .confirm-sheet .confirm-copy{font-size:14px;color:#6b7280;line-height:1.5;margin:0}
    .confirm-sheet .modal-footer{border-top:none;padding:0 16px 16px;display:grid;grid-template-columns:1fr 1fr;gap:12px}
    .confirm-sheet .btn{height:46px;border-radius:12px;font-weight:700}

    @media (max-width: 1199.98px){
      .msg-pill{right:16px;bottom:18px;padding:13px 18px}
      .msg-pill .txt{font-size:16px}
    }
    @media (max-width: 1024px){
      .yt-pagebar{left:0;flex-wrap:wrap;padding:12px}
      .yt-topbar-center{order:3;width:100%}
      .search-card,.yt-search-shell{width:100%}
    }
    @media (max-width: 991.98px){
      .ig-main{padding:100px 12px 110px}
      .msg-pill{display:none}
    }
    @media (max-width: 767.98px){
      :root{--sidew:0px}
      .ig-sidebar{left:0;right:0;top:auto;bottom:0;width:auto;height:66px;border-right:none;border-top:1px solid var(--line);padding:6px 10px;flex-direction:row;justify-content:space-between;gap:6px}
      .ig-logo,.ig-logo-label,.ig-avatar-mini{display:none}
      .ig-nav{flex-direction:row;justify-content:space-between;align-items:center;gap:4px;margin:0;width:100%}
      .ig-link{height:48px;font-size:26px;border-radius:12px;flex:1}
      .ig-link .dot{right:18px;top:8px}
      .ig-main{margin-left:0;padding:100px 0 86px}
      .yt-pagebar{left:0}
      .ig-feed{width:100%}
      .yt-pagebar{padding:12px}
      .yt-brand{font-size:20px}
      .yt-topbar-right .yt-icon-btn:nth-child(1){display:none}
      .post{border-left:none;border-right:none;border-radius:0;margin-bottom:14px}
      .post.is-single-video-post,
      .post.is-single-image-post,
      .post.is-multi-media-post{width:100%}
      .media-stage.standard-video-stage > video{
        max-height:none;
        border-radius:0;
      }
      .media-stage.standard-image-stage > img{
        max-height:none;
        border-radius:0;
      }
      .standard-media-topbar{
        left:0;
        right:0;
        top:0;
        padding:22px 12px 10px;
        gap:8px;
      }
      .standard-media-name{
        font-size:14px;
      }
      .standard-media-time{
        font-size:12px;
      }
      .post.public-post-card:not(.is-reel-post) .media-stage > .standard-media-top-actions .publisher-follow-btn,
      .post.public-post-card:not(.is-reel-post) .media-stage > .standard-media-top-actions .friend-btn{
        padding:6px 10px;
        font-size:10px;
      }
      .standard-media-bottom{
        padding:104px 14px 14px;
      }
      .post.public-post-card:not(.is-reel-post) .media-dots{
        bottom:100px;
      }
      .standard-text-card{
        padding:14px 12px 12px;
      }
      .standard-text-name{
        font-size:15px;
        max-width:140px;
      }
      .standard-text-time{
        font-size:13px;
      }
      .post-header{padding:12px}
      .post-copy{padding:0 12px 10px}
      .actions{padding:12px}
      .jump-rail{right:10px;top:auto;bottom:94px;transform:none}
      .jump-rail button{width:40px;height:40px}

      .post.is-reel-post{
        margin-bottom:14px;
        box-shadow:none;
      }
      .reel-stage{
        aspect-ratio:9/16;
        min-height:0;
        max-height:none;
        height:auto;
      }
      .reel-topbar{
        left:12px;
        right:12px;
        top:12px;
      }
      .reel-top-left{
        gap:10px;
      }
      .reel-top-name{
        font-size:15px;
        max-width:140px;
      }
      .reel-top-time{
        font-size:13px;
      }
      .reel-controls{
        gap:8px;
      }
      .reel-control-btn{
        width:40px;
        height:40px;
        font-size:16px;
      }
      .reel-side-actions{
        right:10px;
        bottom:106px;
        gap:18px;
      }
      .reel-action-btn i{
        font-size:28px;
      }
      .reel-action-btn .action-count{
        font-size:14px;
      }
      .reel-bottom{
        padding:104px 68px 14px 14px;
      }
    }
    @media (min-width: 768px) and (max-width: 1199.98px){
      .ig-feed{width:min(100%,760px)}
      .post.is-single-video-post{width:min(100%,420px)}
      .post.is-single-image-post{width:min(100%,420px)}
      .post.is-multi-media-post{width:100%}
      .post.public-post-card:not(.is-reel-post) .media-stage.has-carousel{
        max-height:min(78vh, 760px);
      }
      .post.public-post-card:not(.is-reel-post) .media-dots{
        bottom:104px;
      }
      .standard-media-bottom{padding:116px 18px 18px;}
      .post.is-reel-post{
        width:min(100%,calc((82vh - 8px) * 9 / 16));
        min-width:320px;
        max-width:540px;
        margin-left:auto;
        margin-right:auto;
      }
      .reel-stage{
        max-height:840px;
      }
      .reel-side-actions{
        right:18px;
        bottom:168px;
      }
      .reel-bottom{padding:128px 88px 18px 22px;}
    }
    @media (min-width: 1200px){
      .ig-feed{width:min(100%,980px)}
      .post.is-single-video-post{width:min(100%,460px)}
      .post.is-single-image-post{width:min(100%,460px)}
      .post.is-multi-media-post{width:100%}
      .post.public-post-card:not(.is-reel-post) .media-stage.has-carousel{
        max-height:min(82vh, 900px);
      }
      .post.public-post-card:not(.is-reel-post) .media-dots{
        bottom:0px;
      }
      .standard-media-bottom{padding:120px 20px 18px;}
      .post.is-reel-post{
        width:100%;
        min-width:0;
        max-width:none;
        margin-left:0;
        margin-right: -29px;
      }
      .reel-stage{
        max-height:840px;
      }
      .reel-topbar{
        padding:18px 24px 14px;
      }
      .reel-bottom{padding:16px 24px 22px;}
    }
  </style>
  <style>
    body{
      --public-surface:#f5f7fb;
      --public-surface-alt:#eef3fb;
      --public-surface-strong:#eef3fb;
      --public-post-card-surface:#f5f7fb;
      /* Reel posts use this variable for their outer border. */
      --public-post-card-border:var(--public-border-strong, rgba(15,23,42,.16));
      --public-border:rgba(15,23,42,.12);
      --public-border-strong:rgba(15,23,42,.16);
      --public-text:#132033;
      --public-muted:#5f6c7c;
      --public-soft-text:#7a8797;
      --public-topbar-bg:rgba(255,255,255,.88);
      --public-topbar-text:#112033;
      --public-sidebar-bg:rgba(255,255,255,.92);
      --public-sidebar-hover:#eef3fb;
      --public-control-bg:#ffffff;
      --public-control-soft:#eef3fb;
      --public-control-border:rgba(15,23,42,.14);
      --public-control-placeholder:#667085;
      --public-accent:#0d61bc;
      --public-accent-soft:rgba(13,97,188,.10);
      --public-accent-strong:#0b4a86;
      background:#f5f7fb;
      background-image:none;
      color:var(--public-text);
    }
    html[data-theme="light"]:not([data-msb-appearance]) body,
    html[data-theme="light"]:not([data-msb-appearance]) body.public-page,
    html[data-theme="light"]:not([data-msb-appearance]) body.news-page{
      --public-surface:var(--msb-palette-bg, #f5f7fb);
      --public-surface-alt:#eef3fb;
      --public-surface-strong:#eef3fb;
      --public-post-card-surface:var(--msb-palette-bg, #f5f7fb);
      background:var(--msb-palette-bg, #f5f7fb) !important;
      background-image:none !important;
      color:var(--msb-palette-text, var(--public-text)) !important;
    }
    html[data-msb-appearance] body,
    html[data-msb-appearance] body.public-page,
    html[data-msb-appearance] body.news-page{
      --public-accent:var(--msb-palette-action);
      --public-accent-soft:var(--msb-palette-action-soft);
      --public-accent-strong:var(--msb-palette-action-strong);
      background:var(--msb-palette-bg) !important;
      background-image:none !important;
    }
    html[data-theme="dark"] body{
      --public-surface:#171d24;
      --public-surface-alt:#1d2530;
      --public-surface-strong:#111821;
      --public-border:rgba(255,255,255,.08);
      --public-border-strong:rgba(255,255,255,.14);
      --public-text:#eef4ff;
      --public-muted:#9ba8b8;
      --public-soft-text:#c2cbd7;
      --public-topbar-bg:#171d24;
      --public-topbar-text:#f4f7fb;
      --public-sidebar-bg:rgba(18,24,31,.94);
      --public-sidebar-hover:#1d2530;
      --public-control-bg:#10161e;
      --public-control-soft:#1a222c;
      --public-control-border:rgba(255,255,255,.12);
      --public-control-placeholder:#8f9baa;
      --public-accent:#7cb2ff;
      --public-accent-soft:rgba(124,178,255,.16);
      --public-accent-strong:#b9d7ff;
      background:#171d24 !important;
      background-image:none !important;
    }
    html.dark-auto:not([data-msb-appearance]) body.public-page.feed-insta-ui .sh-pagebody,
    html[data-theme="dark"]:not([data-msb-appearance]) body.public-page.feed-insta-ui .sh-pagebody,
    html.dark-auto:not([data-msb-appearance]) body.public-page.feed-insta-ui .ig-feed-header,
    html[data-theme="dark"]:not([data-msb-appearance]) body.public-page.feed-insta-ui .ig-feed-header,
    html.dark-auto:not([data-msb-appearance]) body.public-page.feed-insta-ui .feed-top-search,
    html[data-theme="dark"]:not([data-msb-appearance]) body.public-page.feed-insta-ui .feed-top-search{
      background:var(--public-surface, #171d24) !important;
      background-image:none !important;
    }
    html.dark-auto:not([data-msb-appearance]) body.public-page.feed-insta-ui .ig-stories-menu-btn,
    html[data-theme="dark"]:not([data-msb-appearance]) body.public-page.feed-insta-ui .ig-stories-menu-btn,
    html.dark-auto:not([data-msb-appearance]) body.public-page.feed-insta-ui .ig-stories-brand,
    html[data-theme="dark"]:not([data-msb-appearance]) body.public-page.feed-insta-ui .ig-stories-brand,
    html.dark-auto:not([data-msb-appearance]) body.public-page.feed-insta-ui .ig-top-act,
    html[data-theme="dark"]:not([data-msb-appearance]) body.public-page.feed-insta-ui .ig-top-act{
      color:var(--public-text, #eef4ff);
    }
    html.dark-auto:not([data-msb-appearance]) body.public-page.feed-insta-ui .ig-story-empty-icon,
    html[data-theme="dark"]:not([data-msb-appearance]) body.public-page.feed-insta-ui .ig-story-empty-icon{
      background:var(--public-control-soft, #1a222c);
      border-color:var(--public-border, rgba(255,255,255,.08));
      color:var(--public-muted, #9ba8b8);
    }
    .ig-main,
    .ig-feed,
    .post,
    .mf-feed-empty,
    .msg-pill,
    .post-sheet .modal-content,
    .confirm-sheet .modal-content{
      color:var(--public-text);
    }
    .ig-sidebar{
      background:var(--public-sidebar-bg);
      border-right-color:var(--public-border);
      box-shadow:0 14px 30px rgba(15,23,42,.08);
      backdrop-filter:blur(14px);
    }
    .ig-logo-label{
      color:var(--public-muted);
    }
    .ig-link{
      color:var(--public-text);
    }
    .ig-link:hover,
    .ig-link.active{
      background:var(--public-sidebar-hover);
      color:var(--public-text);
    }
    .yt-pagebar{
      background:var(--public-topbar-bg);
      border-bottom:1px solid var(--public-border);
      box-shadow:0 10px 28px rgba(15,23,42,.08);
      backdrop-filter:blur(14px);
    }
    .yt-icon-btn,
    .yt-brand,
    .yt-signin{
      color:var(--public-topbar-text);
    }
    .yt-signin{
      border-color:var(--public-control-border);
      background:var(--public-control-soft);
    }
    .search-input{
      background:var(--public-control-bg);
      color:var(--public-topbar-text);
      border-color:var(--public-control-border);
    }
    .search-input::placeholder{
      color:var(--public-control-placeholder);
    }
    .search-btn,
    .yt-mic-btn{
      background:var(--public-control-soft);
      color:var(--public-topbar-text);
      border-color:var(--public-control-border);
    }
    .post.public-post-card:not(.is-reel-post),
    .post.public-post-card:not(.is-reel-post) .post-copy,
    .standard-text-card,
    .post.public-post-card:not(.is-reel-post) .actions,
    .post.public-post-card:not(.is-reel-post) .post-header{
      background:var(--public-surface);
      color:var(--public-text);
      border-color:var(--public-border);
    }
    .post.public-post-card:not(.is-reel-post){
      box-shadow:0 16px 36px rgba(15,23,42,.08);
    }
    .post.public-post-card:not(.is-reel-post) .actions{
      border-top-color:var(--public-border);
    }
    .post.public-post-card:not(.is-reel-post) .head-meta .name,
    .post.public-post-card:not(.is-reel-post) .post-author-link,
    .post.public-post-card:not(.is-reel-post) .more-btn,
    .post.public-post-card:not(.is-reel-post) .post-copy h3,
    .post.public-post-card:not(.is-reel-post) .post-copy p,
    .post.public-post-card:not(.is-reel-post) .h3-txt,
    .standard-text-author,
    .standard-text-author:hover,
    .standard-text-name,
    .standard-text-copy,
    .standard-text-title,
    .standard-text-caption,
    .post.public-post-card:not(.is-reel-post) .likes-line,
    .post.public-post-card:not(.is-reel-post) .caption-line{
      color:var(--public-text);
    }
    .post.public-post-card:not(.is-reel-post) .head-meta .time,
    .standard-text-time,
    .post.public-post-card:not(.is-reel-post) .comments-line,
    .post.public-post-card:not(.is-reel-post) .meta-line,
    .standard-text-comments,
    .standard-text-views,
    .action-btn .action-count,
    .standard-text-btn .action-count{
      color:var(--public-muted);
    }
    .standard-text-more{
      background:var(--public-surface-alt);
      color:var(--public-text);
    }
    .post.public-post-card:not(.is-reel-post) .open-inline,
    .standard-text-copy .open-inline{
      color:var(--public-accent);
      font-weight:800;
    }
    .post.public-post-card:not(.is-reel-post) .action-btn,
    .standard-text-btn{
      color:var(--public-text);
    }
    .post.public-post-card:not(.is-reel-post) .action-btn i,
    .standard-text-btn i{
      color:var(--public-text) !important;
      text-shadow:none;
    }
    .post.public-post-card:not(.is-reel-post) .action-btn.is-love i,
    .standard-text-btn.is-love i{
      color:#ef2b7b !important;
    }
    .post.public-post-card:not(.is-reel-post) .action-btn.is-like i,
    .standard-text-btn.is-like i{
      color:#2563eb !important;
    }
    .post.public-post-card:not(.is-reel-post) .action-btn.is-share i,
    .standard-text-btn.is-share i{
      color:#6b7280 !important;
    }
    .post.public-post-card:not(.is-reel-post) .action-btn.is-save i,
    .standard-text-btn.is-save i{
      color:#f59e0b !important;
    }
    .post.public-post-card:not(.is-reel-post) > .post-header .friend-btn{
      background:var(--public-surface-alt);
      color:var(--public-text);
      border-color:var(--public-border);
    }
    .msg-pill{
      background:var(--public-surface);
      border-color:var(--public-border);
      box-shadow:0 14px 30px rgba(15,23,42,.12);
      color:var(--public-text);
    }
    .toggle-bubbles span{
      background:var(--public-surface-alt);
      color:var(--public-muted);
    }
    .jump-rail button{
      background:var(--public-surface-strong);
      color:var(--public-text);
      box-shadow:0 10px 24px rgba(15,23,42,.18);
    }
    .mf-feed-empty{
      color:var(--public-muted);
    }
    .mf-feed-empty i{
      color:var(--public-muted);
      opacity:.85;
    }
    .mf-feed-empty .mf-feed-empty-title{
      color:var(--public-text);
    }
    .ig-story-ring-empty{
      background:rgba(148,163,184,.28) !important;
    }
    .ig-story-empty-icon{
      background:var(--public-surface-alt);
      border-color:var(--public-surface);
      color:var(--public-muted);
    }
    .ig-story-empty .ig-story-name{
      color:var(--public-muted);
    }
    .post-sheet .modal-content,
    .confirm-sheet .modal-content,
    .sheet-list{
      background:var(--public-surface);
    }
    .sheet-btn{
      background:var(--public-surface);
      color:var(--public-text);
      border-bottom-color:var(--public-border);
    }
    .sheet-btn:hover{
      background:var(--public-surface-alt);
    }
    .sheet-cancel{
      background:var(--public-surface-alt);
      color:var(--public-text);
    }
    .confirm-sheet .confirm-title{
      color:var(--public-text);
    }
    .confirm-sheet .confirm-copy{
      color:var(--public-muted);
    }
    .confirm-sheet .btn-light{
      background:var(--public-surface-alt);
      border-color:var(--public-border);
      color:var(--public-text);
    }
    @media (max-width: 767.98px){
      .ig-sidebar{
        border-top-color:var(--public-border);
      }
    }
  </style>
  <style>
    @media (min-width:1025px){
      body{
        --public-post-card-surface:var(--public-surface);
      }

      body .ig-main{
        padding:0 18px 110px;
        background:var(--public-surface);
      }

      body .ig-feed{
        width:min(100%, 614px);
        margin-top:104px;
      }
      body.feed-insta-ui .ig-feed{
        width:100%;
        max-width:100%;
        margin-top:0;
      }
      body.feed-insta-ui .sh-pagebody{
        padding:0 !important;
        justify-content:flex-start !important;
        align-items:stretch !important;
      }

      body .post.public-post-card:not(.is-reel-post){
        background:var(--public-post-card-surface);
        /* border:1px solid var(--public-border-strong, var(--public-border)) !important; */
        border-radius:0;
        box-shadow:none;
        /* margin:0 0 0px; */
        overflow:visible;
      }

      body .standard-text-card{
        padding:2px 6px 0;
        /* background:transparent; */
        color:var(--public-text);
        padding: 30px;
      }

      body .standard-text-topbar{
        display:flex;
        align-items:center;
        justify-content:space-between;
        gap:12px;
        position:relative;
        margin-bottom:0;
        padding:0 0 14px;
      }

      body .standard-media-topbar{
        display:flex;
        align-items:center;
        justify-content:space-between;
        gap:10px;
        grid-area:stack;
        align-self:start;
        justify-self:stretch;
        width:calc(100% - 12px);
        margin:20px 15px 20px;
        padding:2px 5px;
        box-sizing:border-box;
        z-index:5;
        pointer-events:none;
        background:transparent;
      }

      body .post.public-post-card:not(.is-reel-post) .media-stage > .public-auto-progress{
        grid-area:stack;
        align-self:start;
        justify-self:stretch;
        position:relative;
        top:auto;
        left:auto;
        right:auto;
        width:calc(100% - 12px);
        margin:15px 10px 0;
        z-index:6;
      }

      body .standard-media-top-actions{
        display:flex;
        align-items:center;
        justify-content:flex-end;
        gap:10px;
        flex:0 0 auto;
        position:static;
        right:auto;
        top:auto;
        transform:none;
        margin-left:0;
        pointer-events:auto;
      }

      body .standard-media-topbar > .standard-media-more,
      body .standard-media-topbar > .post-card-menu-wrap{
        margin-left:auto;
        pointer-events:auto;
      }
      body .standard-media-topbar > .post-card-menu-wrap{
        margin-right: 0 !important;
      }

      body .post.public-post-card:not(.is-reel-post) .media-stage > .standard-media-top-actions{
        grid-area:stack;
        align-self:start;
        justify-self:end;
        position:relative;
        top:12px;
        right:calc(14px + 34px + 8px);
        z-index:40;
        margin:0;
        margin-right: -20px;
        pointer-events:auto;
      }

      body .post.public-post-card:not(.is-reel-post) .media-stage > .standard-media-top-actions .publisher-follow-btn,
      body .post.public-post-card:not(.is-reel-post) .media-stage > .standard-media-top-actions .friend-btn{
        background:rgba(17,24,39,.62);
        border-color:rgba(255,255,255,.24);
        color:#fff;
      }
      body .post.public-post-card:not(.is-reel-post) .media-stage > .standard-media-top-actions .publisher-follow-btn.primary,
      body .post.public-post-card:not(.is-reel-post) .media-stage > .standard-media-top-actions .friend-btn.primary{
        background:var(--blue);
        border-color:var(--blue);
        color:#fff;
        margin-top:15px;
        margin-right: 0;
      }

      body .standard-text-author{
        display:flex;
        align-items:center;
        gap:12px;
        min-width:0;
        flex:1 1 auto;
        color:var(--public-text);
        text-decoration:none;
        padding-right:0;
      }

      body .standard-text-author:hover{
        color:var(--public-text);
        text-decoration:none;
      }

      body .standard-media-topbar .standard-media-author{
        display:flex;
        align-items:center;
        gap:5px;
        min-width:0;
        flex:1 1 auto;
        color:#fff;
        text-decoration:none;
        padding-right:8px;
        pointer-events:auto;
        overflow:hidden;
        margin-left: -10px;
      }

      body .standard-media-topbar .standard-media-author:hover{
        color:#fff;
        text-decoration:none;
      }

      body .standard-text-meta{
        min-width:0;
        display:flex;
        align-items:center;
        gap:8px;
        flex-wrap:wrap;
      }

      body .standard-media-topbar .standard-media-meta{
        min-width:0;
        flex:1 1 auto;
        display:flex;
        flex-direction:column;
        align-items:flex-start;
        justify-content:center;
        overflow:hidden;
      }

      body .standard-media-topbar .standard-media-name-row{
        display:flex;
        align-items:center;
        gap:6px;
        min-width:0;
        max-width:100%;
        flex-wrap:nowrap;
      }

      body .standard-media-topbar .standard-media-name{
        color:#fff;
        font-size:15px;
        font-weight:800;
        line-height:1.2;
        min-width:0;
        flex:0 1 auto;
        max-width:none;
        text-shadow:0 2px 10px rgba(0,0,0,.34);
        margin-left: -1px;
      }

      body .standard-text-time{
        color:var(--public-muted);
        font-size:15px;
        line-height:1.2;
      }

      body .standard-media-topbar .standard-media-time{
        color:rgba(255,255,255,.88);
        font-size:13px;
        line-height:1.2;
        flex:0 0 auto;
        white-space:nowrap;
        text-shadow:0 2px 10px rgba(0,0,0,.34);
        margin-left: -3px;
      }

      body .standard-text-name{
        color:var(--public-text);
        font-size:18px;
        font-weight:700;
        line-height:1.15;
        max-width:220px;
      }

      body .standard-text-more{
        width:28px !important;
        height:28px !important;
        min-width:28px !important;
        min-height:28px !important;
        flex:0 0 28px !important;
        border:0 !important;
        border-radius:999px !important;
        background:transparent !important;
        color:var(--public-text) !important;
        display:flex !important;
        align-items:center !important;
        justify-content:center !important;
        font-size:16px !important;
        line-height:1 !important;
        box-shadow:none !important;
        padding:0 !important;
      }

      body .standard-media-topbar .standard-media-more{
        width:32px !important;
        height:32px !important;
        min-width:32px !important;
        min-height:32px !important;
        flex:0 0 32px !important;
        border:0 !important;
        border-radius:999px !important;
        background:transparent !important;
        color:#fff !important;
        display:flex !important;
        align-items:center !important;
        justify-content:center !important;
        font-size:16px !important;
        line-height:1 !important;
        box-shadow:none !important;
        padding:0 !important;
        pointer-events:auto;
        text-shadow:0 2px 10px rgba(0,0,0,.34);
        margin-right: -32px;
      }

      body .standard-text-more i{
        font-size:22px !important;
        line-height:1 !important;
      }

      body .standard-media-topbar .standard-media-more i{
        font-size:20px !important;
        line-height:1 !important;
        color:#fff !important;
      }

      body .post.public-post-card:not(.is-reel-post) .media-stage > .standard-media-top-actions .publisher-follow-btn,
      body .post.public-post-card:not(.is-reel-post) .media-stage > .standard-media-top-actions .friend-btn{
        padding:7px 12px;
        font-size:11px;
        line-height:1;
        flex-shrink:0;
        margin-top:15px;
        margin-right: 0;
      }

      body .standard-text-title,
      body .standard-media-title{
        margin:0 0 8px;
        color:var(--public-text);
        font-size:20px;
        font-weight:800;
        line-height:1.28;
      }

      body .standard-text-copy,
      body .standard-media-copy{
        color:var(--public-text);
      }

      body .standard-text-caption,
      body .standard-media-caption{
        color:var(--public-text);
        font-size:15px;
        line-height:1.7;
        word-break:break-word;
        text-shadow:none;
        text-align:left;
      }
      body .standard-text-caption .post-card-paragraph,
      body .standard-media-caption .post-card-paragraph,
      body .reel-caption-text .post-card-paragraph{
        margin:0 0 12px;
        text-align:left;
        display:block;
      }
      body .post-card-caption-formatted.is-clamped{
        max-height:14em;
        overflow:hidden;
      }

      body .standard-text-copy .open-inline,
      body .standard-media-copy .open-inline{
        color:var(--public-accent);
        font-weight:800;
      }

      body .standard-text-actions,
      body .standard-media-actions{
        display:flex;
        align-items:flex-start;
        justify-content:space-between;
        gap:16px;
        margin-top:0px;
        width:100%;
      }

      body .standard-text-left,
      body .standard-text-right,
      body .standard-media-left,
      body .standard-media-right{
        display:flex;
        align-items:flex-start;
        gap:10px;
      }

      body .standard-text-left,
      body .standard-media-left{
        flex:1 1 auto;
        min-width:0;
        flex-direction:column;
      }

      body .standard-text-right,
      body .standard-media-right{
        flex:0 0 auto;
        flex-direction:column;
        align-items:flex-end;
        margin-left:auto;
        min-width:max-content;
      }

      body .standard-text-row,
      body .standard-media-row{
        display:flex;
        align-items:center;
        gap:20px;
        flex-wrap:wrap;
      }

      body .standard-text-btn,
      body .standard-media-btn{
        background:none;
        border:none;
        padding:0;
        color:var(--public-text);
        display:inline-flex;
        align-items:center;
        gap:8px;
        font-size:14px;
        line-height:1;
        cursor:pointer;
      }

      body .standard-text-btn i,
      body .standard-media-btn i{
        color:var(--public-text) !important;
        font-size:20px;
        text-shadow:none;
      }

      body .standard-text-btn .action-count,
      body .standard-media-btn .action-count{
        color:var(--public-text);
        font-size:13px;
        font-weight:600;
        line-height:1;
      }

      body .standard-text-comments,
      body .standard-text-views,
      body .standard-media-comments,
      body .standard-media-views{
        color:var(--public-muted);
        font-size:14px;
        line-height:1.3;
        text-shadow:none;
      }

      body .post.public-post-card:not(.is-reel-post) .media-stage{
        display:grid;
        grid-template-areas:
          "stack"
          "bottom";
        background:transparent;
        overflow:visible;
        max-height:none;
        padding:30px;
        position:relative;
      }

      body .post.public-post-card:not(.is-reel-post) .media-stage > :first-child{
        grid-area:stack;
        margin:0 6px;
        border-radius:var(--post-media-radius, 18px);
        overflow:hidden;
      }

      body .post.public-post-card:not(.is-reel-post) .media-stage.phone-shot > :first-child{
        width:min(calc(100% - 12px), 430px);
        margin:0 auto;
        border-radius:28px;
      }

      body .post.public-post-card:not(.is-reel-post) .media-stage.phone-shot.standard-video-stage > :first-child,
      body .post.public-post-card:not(.is-reel-post) .media-stage.phone-shot.standard-image-stage > :first-child{
        width:100%;
        max-width:100%;
        margin:0 auto;
        border-radius:0;
      }

      @media (min-width:768px){
        body .post.public-post-card:not(.is-reel-post) .media-stage.phone-shot > :first-child,
        body .post.public-post-card:not(.is-reel-post) .media-stage.phone-shot.standard-video-stage > :first-child,
        body .post.public-post-card:not(.is-reel-post) .media-stage.phone-shot.standard-image-stage > :first-child{
          width:100%;
          max-width:100%;
          margin:0 auto;
          border-radius:var(--post-media-radius, 18px);
        }
      }

      body .post.public-post-card:not(.is-reel-post) .standard-media-bottom{
        grid-area:bottom;
        position:static;
        margin-right: -10px;
        left:auto;
        right:auto;
        bottom:auto;
        z-index:auto;
        padding:2px 6px 0;
        background:var(--public-post-card-surface);
        color:var(--public-text);
        margin-top:12px;
      }

      body .post.public-post-card:not(.is-reel-post) .standard-media-bottom .standard-media-title,
      body .post.public-post-card:not(.is-reel-post) .standard-media-bottom .standard-media-caption{
        color:var(--public-text);
      }

      body .post.public-post-card:not(.is-reel-post) .standard-media-btn i{
        color:var(--public-text) !important;
      }

      body .post.public-post-card:not(.is-reel-post) .standard-media-btn .action-count{
        color:var(--public-text);
      }
    }
  </style>
  <style>
/* [PUBLIC_INSTA_UI] — matches feed.php header, stories, right rail, scroll (visual only) */
.ig-feed-header{
  position:relative;
  display:flex;
  justify-content:center;
  align-items:flex-start;
  width:100%;
  margin:0;
  padding:16px 16px 14px;
  background:var(--public-surface, var(--msb-palette-bg, #fff));
  border-bottom:1px solid var(--public-border-strong, rgba(15,23,42,.16));
  box-sizing:border-box;
}
.ig-feed-top-lead{
  position:absolute;
  left:16px;
  top:50%;
  transform:translateY(-50%);
  display:flex;
  align-items:center;
  gap:10px;
  z-index:2;
  padding:0;
  box-sizing:border-box;
  max-width:min(72vw, 520px);
}
.ig-feed-top-actions{
  position:absolute;
  right:16px;
  top:50%;
  transform:translateY(-50%);
  display:flex;
  align-items:center;
  gap:10px;
  z-index:2;
  padding:0;
  box-sizing:border-box;
  max-width:min(52vw, 520px);
}
.ig-feed-account-badge{
  display:inline-flex;
  align-items:center;
  max-width:min(22vw, 160px);
  padding:0 10px;
  min-height:32px;
  border-radius:999px;
  background:var(--public-control-soft, #eef2f7);
  border:1px solid var(--public-control-border, #dbe3ee);
  color:var(--public-text, #1e293b);
  font-size:12px;
  font-weight:700;
  letter-spacing:.02em;
  white-space:nowrap;
  overflow:hidden;
  text-overflow:ellipsis;
  flex-shrink:1;
}
.ig-stories-wrap{
  width:100%;
  max-width:614px;
  margin:0 auto;
  padding:0;
  box-sizing:border-box;
}
.ig-stories-bar{
  display:flex !important;
  align-items:flex-start;
  gap:6px;
  width:100%;
  margin:0 auto;
  padding:0;
  background:transparent;
  border-bottom:0;
  visibility:visible !important;
  opacity:1 !important;
  box-sizing:border-box;
}
.ig-stories-menu-btn{
  display:inline-flex;
  align-items:center;
  justify-content:center;
  width:40px;
  height:40px;
  padding:0;
  border:0;
  border-radius:8px;
  background:transparent;
  color:#262626;
  font-size:18px;
  line-height:1;
  cursor:pointer;
  flex-shrink:0;
}
.ig-stories-menu-btn .fa,
.ig-stories-menu-btn .icon{
  font-size:18px;
  line-height:1;
}
.ig-stories-menu-btn:hover{background:#f5f5f5;}
.ig-stories-brand{
  display:inline-flex;
  align-items:center;
  height:40px;
  font-size:18px;
  font-weight:800;
  color:var(--public-text, #262626);
  text-decoration:none;
  letter-spacing:-.02em;
  line-height:1;
  white-space:nowrap;
  flex-shrink:0;
}
.ig-stories-brand:hover{color:var(--public-text, #000);text-decoration:none;}
.ig-top-act{
  display:inline-flex;
  align-items:center;
  justify-content:center;
  padding:0;
  border:0;
  background:transparent;
  color:var(--public-text, #1e293b);
  cursor:pointer;
  flex-shrink:0;
  line-height:1;
  text-decoration:none;
  box-sizing:border-box;
  transition:background .15s ease,opacity .15s ease;
}
.ig-top-act:hover{opacity:.85;}
.ig-top-mic,
.ig-top-shop{
  width:44px;
  height:44px;
  border-radius:50%;
  background:var(--public-control-soft, #eef2f7);
  font-size:18px;
}
.ig-top-mic:hover,
.ig-top-shop:hover{background:var(--public-surface-alt, #e2e8f0);opacity:1;}
.ig-top-live{
  gap:8px;
  min-height:44px;
  padding:0 18px;
  border-radius:999px;
  background:var(--public-control-soft, #eef2f7);
  border:1px solid var(--public-control-border, #dbe3ee);
  font-size:15px;
  font-weight:800;
  letter-spacing:-.01em;
  color:var(--public-text, #1e293b);
}
.ig-top-live i{font-size:16px;}
.ig-top-live:hover{background:var(--public-surface-alt, #e2e8f0);opacity:1;color:var(--public-text, #1e293b);text-decoration:none;}
.ig-top-more{
  width:36px;
  height:44px;
  font-size:20px;
  color:#1e293b;
}
.ig-top-more:hover{background:#f5f5f5;border-radius:8px;opacity:1;}
.ig-stories-track{
  display:flex;
  align-items:flex-start;
  gap:18px;
  flex:1;
  min-width:0;
  overflow-x:auto;
  overflow-y:hidden;
  scroll-behavior:smooth;
  scrollbar-width:none;
  -ms-overflow-style:none;
  padding:0 2px 2px;
}
.ig-stories-track::-webkit-scrollbar{display:none;}
.ig-story-item{
  flex:0 0 auto;
  width:72px;
  text-align:center;
  cursor:pointer;
  user-select:none;
  border:0;
  padding:0;
  background:transparent;
  font:inherit;
  color:inherit;
}
.ig-story-ring{
  width:66px;
  height:66px;
  margin:0 auto 6px;
  padding:2px;
  border-radius:50%;
  background:linear-gradient(45deg,#f58529,#dd2a7b,#8134af,#515bd4);
  box-sizing:border-box;
}
.ig-story-ring img,
.ig-story-thumb{
  display:block;
  width:100%;
  height:100%;
  border-radius:50%;
  border:2px solid #fff;
  object-fit:cover;
  background:#efefef;
  box-sizing:border-box;
}
.ig-story-name{
  display:block;
  max-width:72px;
  font-size:12px;
  line-height:1.2;
  color:#262626;
  white-space:nowrap;
  overflow:hidden;
  text-overflow:ellipsis;
}
.ig-stories-track.is-empty{
  justify-content:flex-start;
  align-items:center;
  min-height:0;
}
.ig-story-empty{
  width:auto;
  min-width:72px;
  max-width:118px;
  cursor:default;
  pointer-events:none;
  display:flex;
  flex-direction:column;
  align-items:center;
}
.ig-story-ring-empty{
  background:var(--public-surface-alt, #e4e7ec) !important;
}
.ig-story-empty-icon{
  display:flex;
  align-items:center;
  justify-content:center;
  width:100%;
  height:100%;
  border-radius:50%;
  border:2px solid var(--public-surface, #fff);
  background:var(--public-control-soft, #f2f4f7);
  box-sizing:border-box;
  color:var(--public-muted, #98a2b3);
  font-size:26px;
  line-height:1;
}
.ig-story-empty .ig-story-name{
  max-width:118px;
  white-space:normal;
  color:var(--public-muted, #667085);
  font-weight:600;
  font-size:11px;
  line-height:1.25;
}
.ig-stories-bar.is-empty .ig-stories-next{
  display:none;
}
.ig-stories-next{
  flex:0 0 auto;
  width:24px;
  height:24px;
  margin-top:18px;
  padding:0;
  border:0;
  border-radius:50%;
  background:#fff;
  color:#262626;
  box-shadow:0 0 4px rgba(0,0,0,.12);
  cursor:pointer;
  display:flex;
  align-items:center;
  justify-content:center;
  font-size:12px;
  line-height:1;
}
.ig-stories-next:hover{background:#fafafa;}
.feed-top-search{
  width:100%;
  padding:12px 16px 8px;
  box-sizing:border-box;
  position:sticky;
  top:0;
  z-index:105;
  background:var(--public-surface, #fff);
  flex:0 0 auto;
}
body.feed-insta-ui .feed-desktop-center > .feed-top-search,
body.public-page.feed-insta-ui .feed-desktop-center > .feed-top-search{
  position:sticky;
  top:0;
  z-index:105;
  width:100%;
  margin:0;
}
.feed-top-search-form{
  width:100%;
  max-width:614px;
  margin:0 auto;
}
body.feed-insta-ui .feed-desktop-center > .feed-top-search .feed-top-search-form,
body.public-page.feed-insta-ui .feed-desktop-center > .feed-top-search .feed-top-search-form{
  max-width:100%;
}
.feed-top-search-field{
  position:relative;
  width:100%;
}
.feed-top-search-input{
  width:100%;
  min-width:0;
  height:42px;
  border:1px solid var(--public-border, rgba(15,23,42,.14));
  border-radius:999px;
  padding:0 44px 0 16px;
  font-size:14px;
  background:var(--public-surface, #fff);
  color:var(--public-text, #0d0d0d);
  outline:none;
  box-sizing:border-box;
}
.feed-top-search-input::placeholder{
  color:var(--public-muted, #667085);
}
.feed-top-search-input:focus{
  border-color:var(--msb-palette-action, var(--public-accent, #2563eb));
  box-shadow:0 0 0 3px var(--msb-palette-action-soft, rgba(37,99,235,.12));
}
.feed-top-search-icon{
  position:absolute;
  right:6px;
  top:50%;
  transform:translateY(-50%);
  width:32px;
  height:32px;
  border:0;
  border-radius:50%;
  padding:0;
  display:inline-flex;
  align-items:center;
  justify-content:center;
  background:transparent;
  color:var(--msb-palette-action, var(--public-accent, #2563eb));
  cursor:pointer;
  line-height:1;
}
.feed-top-search-icon i{
  font-size:15px;
  line-height:1;
}
.feed-top-search-icon:hover,
.feed-top-search-icon:focus{
  background:var(--msb-palette-action-soft, rgba(37,99,235,.12));
  outline:none;
}
.feed-desktop-layout{display:block;width:100%;}
.feed-left-rail,
.feed-right-rail{display:none;}
/* [PUBLIC_FEED_AVATAR] — same ring technique as .ig-story-ring (story top) + feed blue gradient */
body.feed-insta-ui .avatar,
body.feed-insta-ui .standard-text-author .avatar,
body.feed-insta-ui .standard-media-author .avatar,
body.feed-insta-ui .reel-top-author .avatar,
body.feed-insta-ui .post-author-link .avatar{
  width:44px !important;
  height:44px !important;
  flex:0 0 44px !important;
  padding:2px !important;
  border-radius:50% !important;
  background:linear-gradient(135deg, #0ea5e9 0%, #2563eb 58%, #f8fafc 100%) !important;
  box-sizing:border-box !important;
  line-height:0 !important;
}
body.feed-insta-ui .standard-media-topbar .standard-media-author .avatar{
  width:38px !important;
  height:38px !important;
  flex:0 0 38px !important;
}
.post.public-post-card:not(.is-reel-post) .media-stage.phone-shot .standard-media-topbar{
  border-radius:28px 28px 0 0;
}
@media (min-width:768px){
  .post.public-post-card:not(.is-reel-post) .media-stage.phone-shot .standard-media-topbar{
    border-radius:var(--post-media-radius, 18px) var(--post-media-radius, 18px) 0 0;
  }
}
body.feed-insta-ui .avatar-thumb,
body.feed-insta-ui .avatar > img{
  display:block !important;
  width:100% !important;
  height:100% !important;
  border-radius:50% !important;
  border:2px solid #fff !important;
  object-fit:cover !important;
  background:#fff !important;
  box-sizing:border-box !important;
}
body.feed-insta-ui .avatar-thumb{
  overflow:hidden !important;
}
body.feed-insta-ui .avatar-thumb img{
  display:block !important;
  width:100% !important;
  height:100% !important;
  border-radius:50% !important;
  object-fit:cover !important;
  border:0 !important;
}
/* [FEED_LEFT_RAIL_UI] — desktop left nav panel beside icon rail (visual only) */
@media (min-width:1025px){
  body.feed-insta-ui{
    --feed-left-nav-box-h:min(340px, calc(100vh - 280px));
  }
  body.feed-insta-ui .feed-left-rail{
    display:flex;
    flex-direction:column;
    position:fixed;
    left:calc(var(--feedRailW, 84px) + 40px);
    top:var(--feed-left-rail-top, 220px);
    width:236px;
    height:var(--feed-left-nav-box-h);
    max-height:var(--feed-left-nav-box-h);
    overflow:hidden;
    z-index:90;
    padding:4px 0 8px;
    box-sizing:border-box;
  }
  body.feed-insta-ui .feed-left-nav{
    display:flex;
    flex-direction:column;
    gap:2px;
    flex:1 1 auto;
    min-height:0;
    height:100%;
    max-height:100%;
    overflow-y:auto;
    overflow-x:hidden;
    padding:0 2px 0 0;
    -webkit-overflow-scrolling:touch;
    overscroll-behavior:contain;
    touch-action:pan-y;
    scrollbar-width:thin;
    scrollbar-color:rgba(0,0,0,.18) transparent;
  }
  body.feed-insta-ui .feed-left-nav::-webkit-scrollbar{width:5px;}
  body.feed-insta-ui .feed-left-nav::-webkit-scrollbar-thumb{
    background:rgba(0,0,0,.18);
    border-radius:999px;
  }
  body.feed-insta-ui .feed-left-rail-label{
    padding:0 12px 10px;
    font-size:11px;
    font-weight:700;
    letter-spacing:.14em;
    text-transform:uppercase;
    color:#94a3b8;
  }
  body.feed-insta-ui .feed-left-nav-item{
    display:flex;
    align-items:center;
    gap:12px;
    min-height:42px;
    padding:8px 12px;
    border-radius:10px;
    color:var(--msb-palette-text-on-nav, #0d0d0d);
    font-size:14px;
    font-weight:500;
    line-height:1.2;
    text-decoration:none;
    transition:background .15s ease,color .15s ease;
    box-sizing:border-box;
  }
  body.feed-insta-ui .feed-left-nav-item:hover,
  body.feed-insta-ui .feed-left-nav-item:focus{
    background:var(--msb-palette-nav-hover, #d0d8e4);
    color:var(--msb-palette-text-on-nav-hover, #0a0a0a);
    box-shadow:inset 0 0 0 1px rgba(15,23,42,.14);
    text-decoration:none;
    outline:none;
  }
  body.feed-insta-ui .feed-left-nav-item.is-active{
    background:var(--msb-palette-nav-active-bg, #eef2f7);
    color:var(--msb-palette-nav-active-text, #0f172a);
    font-weight:600;
  }
  body.feed-insta-ui .feed-left-nav-ic{
    flex:0 0 20px;
    width:20px;
    height:20px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    color:inherit;
  }
  body.feed-insta-ui .feed-left-nav-ic svg{
    display:block;
    width:18px;
    height:18px;
    stroke:currentColor;
    fill:none;
    stroke-width:1.75;
    stroke-linecap:round;
    stroke-linejoin:round;
  }
  body.feed-insta-ui .feed-left-nav-label{
    flex:1 1 auto;
    min-width:0;
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis;
  }
  body.feed-insta-ui .feed-left-nav-badge{
    flex:0 0 auto;
    margin-left:8px;
    padding:3px 8px;
    border-radius:999px;
    background:#f3f4f6;
    color:#6b7280;
    font-size:10px;
    font-weight:700;
    letter-spacing:.04em;
    line-height:1;
  }
  body.feed-insta-ui .feed-left-nav-section{
    padding:14px 12px 4px;
    font-size:11px;
    font-weight:700;
    letter-spacing:.12em;
    text-transform:uppercase;
    color:#94a3b8;
  }
  body.feed-insta-ui .feed-left-nav-item-company .feed-left-nav-label,
  body.feed-insta-ui .feed-left-nav-item-publisher .feed-left-nav-label{
    font-weight:600;
  }
  body.feed-insta-ui .feed-left-nav-item-under-public{
    margin-left:12px;
    padding-left:20px;
    min-height:38px;
    font-size:13px;
  }
  body.feed-insta-ui .ig-feed-header{
    display:flex !important;
    justify-content:center !important;
    align-items:flex-start !important;
    padding-left:0;
    padding-right:0;
  }
  body.feed-insta-ui .ig-stories-wrap{
    display:block !important;
    max-width:614px;
    width:100%;
    margin:0 auto;
  }
  body.feed-insta-ui .ig-stories-bar{display:flex !important;width:100%;}
  body.feed-insta-ui .ig-stories-track{
    /* padding-left:22px; */
  }
  body.feed-insta-ui .ig-feed-top-lead{
    left:16px;
  }
  body.feed-insta-ui .ig-feed-top-actions{
    right:16px;
  }
  body.feed-insta-ui .feed-desktop-layout{
    display:block;
    width:100%;
    max-width:none;
    margin:0;
    padding:0;
    box-sizing:border-box;
  }
  body.feed-insta-ui .feed-desktop-center{
    width:614px;
    max-width:614px;
    margin:0 auto;
    min-width:0;
  }
  body.feed-insta-ui .feed-desktop-layout .ig-feed{
    max-width:100% !important;
    width:100% !important;
    margin:0 !important;
    padding:0 0 96px !important;
  }
  body.feed-insta-ui .feed-right-rail{
    display:block;
    position:fixed;
    right:24px;
    top:200px;
    width:320px;
    max-width:calc(100vw - 720px);
    z-index:90;
    padding:0;
    box-sizing:border-box;
  }
  body.feed-insta-ui .jump-rail{
    top:auto;
    bottom:120px;
    right:24px;
    transform:none;
  }
  body.feed-insta-ui .feed-right-nav{
    display:flex;
    flex-direction:column;
    gap:2px;
    margin:0;
    padding:0;
    list-style:none;
  }
  body.feed-insta-ui .feed-right-nav-item{
    display:flex;
    align-items:center;
    gap:12px;
    min-height:42px;
    padding:8px 12px;
    border-radius:10px;
    color:var(--msb-palette-text-on-nav, #0d0d0d);
    font-size:14px;
    font-weight:500;
    line-height:1.2;
    text-decoration:none;
    transition:background .15s ease,color .15s ease;
    box-sizing:border-box;
  }
  body.feed-insta-ui .feed-right-nav-item.is-active{
    background:var(--msb-palette-nav-active-bg, #f3f4f6);
    color:var(--msb-palette-nav-active-text, #0f172a);
    font-weight:700;
  }
  body.feed-insta-ui .feed-right-nav-item:hover,
  body.feed-insta-ui .feed-right-nav-item:focus{
    background:var(--msb-palette-nav-hover, #d0d8e4);
    color:var(--msb-palette-text-on-nav-hover, #0a0a0a);
    box-shadow:inset 0 0 0 1px rgba(15,23,42,.14);
    text-decoration:none;
    outline:none;
  }
  body.feed-insta-ui .feed-right-nav-ic{
    flex:0 0 20px;
    width:20px;
    height:20px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    color:inherit;
  }
  body.feed-insta-ui .feed-right-nav-ic svg{
    display:block;
    width:18px;
    height:18px;
    stroke:currentColor;
    fill:none;
    stroke-width:1.75;
    stroke-linecap:round;
    stroke-linejoin:round;
  }
  body.feed-insta-ui .feed-right-nav-label{
    flex:1 1 auto;
    min-width:0;
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis;
  }
  body.feed-insta-ui .feed-right-nav-badge{
    flex:0 0 auto;
    margin-left:8px;
    padding:3px 8px;
    border-radius:999px;
    background:#f3f4f6;
    color:#6b7280;
    font-size:10px;
    font-weight:700;
    letter-spacing:.04em;
    line-height:1;
  }
  html:has(body.feed-insta-ui),
  body.public-page.feed-insta-ui{
    overflow:hidden !important;
    height:100vh !important;
    max-height:100vh !important;
    /* background:#fff !important; */
  }
  body.feed-insta-ui .sh-mainpanel{
    height:100vh !important;
    max-height:100vh !important;
    overflow:hidden !important;
    display:flex !important;
    flex-direction:column !important;
  }
  body.feed-insta-ui .sh-pagebody{
    flex:1 1 auto !important;
    min-height:0 !important;
    overflow:hidden !important;
    display:flex !important;
    flex-direction:column !important;
    padding:0 !important;
    background:var(--public-surface, var(--msb-palette-bg, #fff)) !important;
  }
  body.feed-insta-ui .ig-feed-header{
    flex:0 0 auto !important;
    position:relative !important;
    top:auto !important;
    z-index:110 !important;
    margin:0 !important;
    background:var(--public-surface, var(--msb-palette-bg, #fff)) !important;
    border-bottom:1px solid var(--public-border-strong, rgba(15,23,42,.16)) !important;
    /* border-bottom:1px solid #dbdbdb !important; */
  }
  body.feed-insta-ui .feed-top-search{
    flex:0 0 auto !important;
    position:relative !important;
    top:auto !important;
    z-index:105 !important;
    background:var(--public-surface, #fff) !important;
    padding:12px 16px 8px !important;
    border-bottom:1px solid var(--public-border-strong, rgba(15,23,42,.16)) !important;
  }
  body.feed-insta-ui .feed-desktop-center > .feed-top-search,
  body.public-page.feed-insta-ui .feed-desktop-center > .feed-top-search{
    position:sticky !important;
    top:0 !important;
    z-index:105 !important;
    flex:0 0 auto !important;
    width:100% !important;
    margin:0 !important;
    background:var(--public-surface, #fff) !important;
    border-bottom:1px solid var(--public-border-strong, rgba(15,23,42,.16)) !important;
  }
  body.feed-insta-ui .feed-desktop-layout{
    flex:1 1 auto !important;
    min-height:0 !important;
    overflow:hidden !important;
    width:100% !important;
  }
  body.feed-insta-ui .feed-desktop-center{
    height:100% !important;
    max-height:100% !important;
    overflow-y:auto !important;
    overflow-x:hidden !important;
    -webkit-overflow-scrolling:touch;
    overscroll-behavior:contain;
    scrollbar-width:thin;
    scrollbar-color:rgba(0,0,0,.22) transparent;
  }
  body.feed-insta-ui .feed-desktop-center::-webkit-scrollbar{width:6px;}
  body.feed-insta-ui .feed-desktop-center::-webkit-scrollbar-thumb{
    background:rgba(0,0,0,.22);
    border-radius:999px;
  }
}
@media (max-width:1024px){
  html:has(body.public-page.feed-insta-ui),
  body.public-page.feed-insta-ui{
    overflow:hidden !important;
    height:100vh !important;
    max-height:100vh !important;
  }
  body.public-page.feed-insta-ui .sh-mainpanel{
    height:100vh !important;
    max-height:100vh !important;
    overflow:hidden !important;
    display:flex !important;
    flex-direction:column !important;
  }
  body.public-page.feed-insta-ui .sh-pagebody{
    flex:1 1 auto !important;
    min-height:0 !important;
    overflow:hidden !important;
    display:flex !important;
    flex-direction:column !important;
    padding:0 !important;
  }
  body.public-page.feed-insta-ui .ig-feed-header,
  body.public-page.feed-insta-ui .feed-top-search{
    flex:0 0 auto !important;
  }
  body.public-page.feed-insta-ui .feed-top-search{
    z-index:105 !important;
    background:var(--public-surface, #fff) !important;
  }
  body.public-page.feed-insta-ui .feed-desktop-layout{
    flex:1 1 auto !important;
    min-height:0 !important;
    overflow:hidden !important;
    width:100% !important;
  }
  body.public-page.feed-insta-ui .feed-desktop-center{
    height:100% !important;
    max-height:100% !important;
    overflow-y:auto !important;
    overflow-x:hidden !important;
    -webkit-overflow-scrolling:touch;
    overscroll-behavior:contain;
  }
}
@media (min-width:768px) and (max-width:1024px){
  body.feed-insta-ui .ig-stories-wrap{max-width:470px;}
  body.feed-insta-ui .ig-stories-track{padding-left:10px;}
  body.feed-insta-ui .ig-feed-top-lead{left:16px;}
}
@media (max-width:767px){
  body.feed-insta-ui .ig-feed-header{
    flex-direction:column;
    align-items:stretch;
    padding:12px 10px 14px;
  }
  body.feed-insta-ui .ig-feed-top-lead{
    position:static;
    transform:none;
    margin-bottom:10px;
  }
  body.feed-insta-ui .ig-feed-top-actions{
    position:static;
    transform:none;
    justify-content:flex-end;
    margin-top:8px;
    padding:0 2px;
    max-width:none;
  }
  body.feed-insta-ui .ig-feed-account-badge{
    max-width:min(38vw, 140px);
    font-size:11px;
  }
  body.feed-insta-ui .ig-top-live{padding:0 14px;font-size:14px;}
  body.feed-insta-ui .ig-stories-wrap{max-width:100%;}
  body.feed-insta-ui .ig-stories-track{padding-left:8px;}
}
</style>


  <style id="public-post-device-media-css">
    .post.public-post-card{
      --post-media-radius:18px;
      --post-media-max:680px;
      --post-phone-max:430px;
      --post-tablet-max:620px;
      --post-landscape-max:760px;
      --post-square-max:620px;
      --post-portrait-max:520px;
    }
    .post.public-post-card.is-single-video-post:not(.is-reel-post),
    .post.public-post-card.is-single-image-post:not(.is-reel-post){
      /* Full-width post card (divider line), media stage gets the width constraint below. */
      width:100% !important;
      max-width:100% !important;
      margin-left:0 !important;
      margin-right:0 !important;
    }
    .post.public-post-card.is-multi-media-post:not(.is-reel-post){
      width:100% !important;
      max-width:100% !important;
    }
    /* Single standard media: constrain the media stage width only. */
    .post.public-post-card.is-single-video-post:not(.is-reel-post) .media-stage.standard-video-stage,
    .post.public-post-card.is-single-image-post:not(.is-reel-post) .media-stage.standard-image-stage{
      width:min(100%, var(--post-media-card-width, var(--post-media-max))) !important;
      max-width:100% !important;
      margin-left:auto !important;
      margin-right:auto !important;
    }
    .post.public-post-card:not(.is-reel-post) .media-stage{
      border-radius:var(--post-media-radius) !important;
      background:transparent !important;
      overflow:hidden !important;
    }
    .post.public-post-card:not(.is-reel-post) .media-stage.standard-video-stage,
    .post.public-post-card:not(.is-reel-post) .media-stage.standard-image-stage{
      background:transparent !important;
      border:0 !important;
      overflow:visible !important;
    }
    .post.public-post-card:not(.is-reel-post) .media-stage.standard-video-stage > video,
    .post.public-post-card:not(.is-reel-post) .media-stage.standard-image-stage > img{
      width:100% !important;
      height:auto !important;
      max-height:min(78svh,960px) !important;
      object-fit:contain !important;
      object-position:center center !important;
      border:0 !important;
      border-radius:var(--post-media-radius) !important;
      background:transparent !important;
    }
    @media (max-width:767.98px){
      .post.public-post-card:not(.is-reel-post) .media-stage.phone-shot{
        width:min(72vw,var(--post-phone-max)) !important;
        max-width:100% !important;
        max-height:min(78svh,900px) !important;
        margin-inline:auto !important;
        aspect-ratio:var(--device-ar-w,375)/var(--device-ar-h,667) !important;
        border-radius:28px !important;
        overflow:hidden !important;
        background:transparent !important;
      }
      .post.public-post-card:not(.is-reel-post) .media-stage.phone-shot.standard-video-stage,
      .post.public-post-card:not(.is-reel-post) .media-stage.phone-shot.standard-image-stage{
        overflow:hidden !important;
        aspect-ratio:var(--device-ar-w,375)/var(--device-ar-h,667) !important;
        border-radius:28px !important;
      }
      .post.public-post-card:not(.is-reel-post) .media-stage.phone-shot.standard-video-stage > video,
      .post.public-post-card:not(.is-reel-post) .media-stage.phone-shot.standard-image-stage > img{
        width:100% !important;
        height:100% !important;
        max-height:none !important;
        border-radius:0 !important;
        object-fit:contain !important;
      }
    }
    @media (min-width:768px){
      .post.public-post-card:not(.is-reel-post) .media-stage.phone-shot,
      .post.public-post-card:not(.is-reel-post) .media-stage.phone-shot.standard-video-stage,
      .post.public-post-card:not(.is-reel-post) .media-stage.phone-shot.standard-image-stage{
        width:100% !important;
        max-width:100% !important;
        margin-inline:0 !important;
        aspect-ratio:auto !important;
        border-radius:var(--post-media-radius) !important;
        overflow:visible !important;
        max-height:none !important;
        box-shadow:none !important;
        background:transparent !important;
      }
      .post.public-post-card:not(.is-reel-post) .media-stage.phone-shot.standard-video-stage > video,
      .post.public-post-card:not(.is-reel-post) .media-stage.phone-shot.standard-image-stage > img{
        width:100% !important;
        height:auto !important;
        max-height:min(78svh,960px) !important;
        border-radius:var(--post-media-radius) !important;
        object-fit:contain !important;
        background:transparent !important;
      }
      .post.public-post-card.is-single-video-post:not(.is-reel-post):has(.media-stage.phone-shot),
      .post.public-post-card.is-single-image-post:not(.is-reel-post):has(.media-stage.phone-shot){
        width:100% !important;
        max-width:100% !important;
        margin-inline:0 !important;
      }
      /* Phone-shot wrapper gets the constraint; keep the post full-width for the divider. */
      .post.public-post-card.is-single-video-post:not(.is-reel-post):has(.media-stage.phone-shot) .media-stage.phone-shot,
      .post.public-post-card.is-single-image-post:not(.is-reel-post):has(.media-stage.phone-shot) .media-stage.phone-shot{
        width:min(100%,var(--post-media-card-width,var(--post-media-max))) !important;
        max-width:100% !important;
        margin-inline:auto !important;
      }
    }
    .post.public-post-card:not(.is-reel-post) .media-stage.has-carousel{
      max-height:min(82svh,900px) !important;
      background:transparent !important;
    }
    @media (max-width:767.98px){
      .post.public-post-card.is-single-video-post:not(.is-reel-post),
      .post.public-post-card.is-single-image-post:not(.is-reel-post){
        width:100% !important;
        max-width:100% !important;
        margin-inline:0 !important;
      }
      .post.public-post-card.is-multi-media-post:not(.is-reel-post){
        width:100% !important;
      }
      .post.public-post-card:not(.is-reel-post) .media-stage.standard-video-stage > video,
      .post.public-post-card:not(.is-reel-post) .media-stage.standard-image-stage > img{
        max-height:calc(100svh - 210px) !important;
      }
      .post.public-post-card:not(.is-reel-post) .media-stage.phone-shot{
        width:min(72vw,var(--post-phone-max)) !important;
      }
    }
  </style>

<style id="live-post-card-css">
<?php require_once __DIR__ . '/includes/live_post_card.css.php'; echo live_post_card_css(); ?>
</style>
</style>
<style><?php include __DIR__ . '/includes/feed_page_chrome.css.php'; ?></style>
<?php post_card_actions_menu_render_css(); ?>
<?php post_action_thin_icons_render_css(); ?>
<style id="public-user-menu-on-media-css">
.post.public-post-card .standard-media-topbar > .post-card-menu-wrap,
html[data-msb-appearance] body .post.public-post-card .standard-media-topbar > .post-card-menu-wrap,
html[data-msb-appearance] body.news-page .post.public-post-card .standard-media-topbar > .post-card-menu-wrap{
  position:absolute !important;
  top:var(--pcm-on-media-topbar-menu-top, 2px) !important;
  right:var(--pcm-on-media-topbar-menu-right, 4px) !important;
  margin:0 !important;
  flex:0 0 auto !important;
  width:auto !important;
  z-index:61 !important;
}
.post.public-post-card:not(.is-reel-post) .media-stage:has(> .standard-media-topbar) > .standard-media-top-actions,
html[data-msb-appearance] body .post.public-post-card:not(.is-reel-post) .media-stage:has(> .standard-media-topbar) > .standard-media-top-actions,
html[data-msb-appearance] body.news-page .post.public-post-card:not(.is-reel-post) .media-stage:has(> .standard-media-topbar) > .standard-media-top-actions{
  right:calc(14px + 34px + 8px) !important;
}
.post.public-post-card:not(.is-reel-post) .media-stage > .standard-media-top-actions .friend-btn,
.post.public-post-card:not(.is-reel-post) .media-stage > .standard-media-top-actions .publisher-follow-btn,
.post.public-post-card:not(.is-reel-post) .media-stage > .standard-media-top-actions .friend-btn.primary,
.post.public-post-card:not(.is-reel-post) .media-stage > .standard-media-top-actions .publisher-follow-btn.primary,
html[data-msb-appearance] body .post.public-post-card:not(.is-reel-post) .media-stage > .standard-media-top-actions .friend-btn,
html[data-msb-appearance] body .post.public-post-card:not(.is-reel-post) .media-stage > .standard-media-top-actions .publisher-follow-btn,
html[data-msb-appearance] body .post.public-post-card:not(.is-reel-post) .media-stage > .standard-media-top-actions .friend-btn.primary,
html[data-msb-appearance] body .post.public-post-card:not(.is-reel-post) .media-stage > .standard-media-top-actions .publisher-follow-btn.primary,
html[data-msb-appearance] body.news-page .post.public-post-card:not(.is-reel-post) .media-stage > .standard-media-top-actions .friend-btn,
html[data-msb-appearance] body.news-page .post.public-post-card:not(.is-reel-post) .media-stage > .standard-media-top-actions .publisher-follow-btn,
html[data-msb-appearance] body.news-page .post.public-post-card:not(.is-reel-post) .media-stage > .standard-media-top-actions .friend-btn.primary,
html[data-msb-appearance] body.news-page .post.public-post-card:not(.is-reel-post) .media-stage > .standard-media-top-actions .publisher-follow-btn.primary{
  margin-right:-20px !important;
}
@media (max-width:767.98px){
  .post.public-post-card:not(.is-reel-post) .media-stage:has(> .standard-media-topbar) > .standard-media-top-actions,
  html[data-msb-appearance] body .post.public-post-card:not(.is-reel-post) .media-stage:has(> .standard-media-topbar) > .standard-media-top-actions,
  html[data-msb-appearance] body.news-page .post.public-post-card:not(.is-reel-post) .media-stage:has(> .standard-media-topbar) > .standard-media-top-actions{
    right:calc(12px + 34px + 8px) !important;
  }
}
</style>
<style id="public-post-card-menu-css">
/* public.php — fries menu (no circle) */
body.public-page .post.public-post-card .post-card-menu-btn,
body.news-page .post.public-post-card .post-card-menu-btn{
  width:auto!important;
  height:auto!important;
  min-width:var(--pcm-menu-btn-size, 28px)!important;
  min-height:var(--pcm-menu-btn-size, 28px)!important;
  padding:6px 4px!important;
  flex:0 0 auto!important;
  border:0!important;
  border-radius:0!important;
  background:transparent!important;
  box-shadow:none!important;
  display:inline-flex!important;
  align-items:center!important;
  justify-content:center!important;
  line-height:1!important;
}
body.public-page .post.public-post-card .standard-media-topbar .post-card-menu-btn,
body.news-page .post.public-post-card .standard-media-topbar .post-card-menu-btn{
  color:#fff!important;
  --pcm-fries-filter:drop-shadow(0 1px 2px rgba(0,0,0,.7)) drop-shadow(0 0 1px rgba(0,0,0,.5));
}
body.public-page .post.public-post-card .standard-text-topbar .post-card-menu-btn,
body.public-page .post.public-post-card .post-card-head-actions .post-card-menu-btn,
body.news-page .post.public-post-card .standard-text-topbar .post-card-menu-btn{
  color:#5c3d2e!important;
}
body.public-page .post.public-post-card .post-card-menu-btn:hover,
body.public-page .post.public-post-card .post-card-menu-btn:focus,
body.news-page .post.public-post-card .post-card-menu-btn:hover,
body.news-page .post.public-post-card .post-card-menu-btn:focus{
  outline:none!important;
  background:transparent!important;
  box-shadow:none!important;
  opacity:.72!important;
}
body.public-page .post.public-post-card .post-card-menu-btn i,
body.news-page .post.public-post-card .post-card-menu-btn i,
body.public-page .post.public-post-card .post-card-menu-btn .pcm-fries-icon,
body.news-page .post.public-post-card .post-card-menu-btn .pcm-fries-icon{
  font-size:16px!important;
  line-height:1!important;
  transform:none!important;
}
body.public-page .post.public-post-card .standard-media-topbar .post-card-menu-btn i,
body.news-page .post.public-post-card .standard-media-topbar .post-card-menu-btn i,
body.public-page .post.public-post-card .standard-media-topbar .post-card-menu-btn .pcm-fries-icon,
body.news-page .post.public-post-card .standard-media-topbar .post-card-menu-btn .pcm-fries-icon{
  color:inherit!important;
  text-shadow:none!important;
}
body.public-page .post.public-post-card .standard-media-topbar .post-card-menu-btn.pcm-on-dark-media,
body.news-page .post.public-post-card .standard-media-topbar .post-card-menu-btn.pcm-on-dark-media{
  background:transparent!important;
  border:0!important;
  color:#fff!important;
  box-shadow:none!important;
}
body.public-page .post.public-post-card .standard-media-topbar .post-card-menu-btn.pcm-on-light-media,
body.news-page .post.public-post-card .standard-media-topbar .post-card-menu-btn.pcm-on-light-media{
  background:transparent!important;
  border:0!important;
  color:#0f172a!important;
  box-shadow:none!important;
  --pcm-fries-filter:drop-shadow(0 1px 1px rgba(255,255,255,.9)) drop-shadow(0 0 1px rgba(255,255,255,.75));
}
body.public-page .post.public-post-card .standard-media-topbar .post-card-menu-btn.pcm-on-dark-media i,
body.news-page .post.public-post-card .standard-media-topbar .post-card-menu-btn.pcm-on-dark-media i,
body.public-page .post.public-post-card .standard-media-topbar .post-card-menu-btn.pcm-on-dark-media .pcm-fries-icon,
body.news-page .post.public-post-card .standard-media-topbar .post-card-menu-btn.pcm-on-dark-media .pcm-fries-icon{
  color:#fff!important;
  text-shadow:none!important;
}
body.public-page .post.public-post-card .standard-media-topbar .post-card-menu-btn.pcm-on-light-media i,
body.news-page .post.public-post-card .standard-media-topbar .post-card-menu-btn.pcm-on-light-media i,
body.public-page .post.public-post-card .standard-media-topbar .post-card-menu-btn.pcm-on-light-media .pcm-fries-icon,
body.news-page .post.public-post-card .standard-media-topbar .post-card-menu-btn.pcm-on-light-media .pcm-fries-icon{
  color:#0f172a!important;
  text-shadow:none!important;
}
</style>
<style id="public-page-modal-fouc-guard">
/* Keep shared header modals fully hidden on Public until JS opens them (matches feed.php). */
body.public-page #globalLiveModal:not(.is-open),
body.public-page #createPostModal:not(.is-open),
body.news-page #globalLiveModal:not(.is-open),
body.news-page #createPostModal:not(.is-open){
  display:none !important;
  visibility:hidden !important;
  opacity:0 !important;
  pointer-events:none !important;
}
body.public-page #globalLiveModal:not(.is-open) .global-live-modal-dialog,
body.public-page #globalLiveModal:not(.is-open) iframe,
body.public-page #globalLiveModal:not(.is-open) video,
body.public-page #globalLiveModal:not(.is-open) img,
body.public-page #globalLiveModal:not(.is-open) aside,
body.public-page #createPostModal:not(.is-open) .create-post-dialog,
body.public-page #createPostModal:not(.is-open) iframe,
body.news-page #globalLiveModal:not(.is-open) .global-live-modal-dialog,
body.news-page #globalLiveModal:not(.is-open) iframe,
body.news-page #globalLiveModal:not(.is-open) video,
body.news-page #globalLiveModal:not(.is-open) img,
body.news-page #globalLiveModal:not(.is-open) aside,
body.news-page #createPostModal:not(.is-open) .create-post-dialog,
body.news-page #createPostModal:not(.is-open) iframe{
  display:none !important;
}
html.dark-auto body.public-page #globalLiveModal:not(.is-open),
html.dark-auto body.public-page #createPostModal:not(.is-open),
body.dark-auto.public-page #globalLiveModal:not(.is-open),
body.dark-auto.public-page #createPostModal:not(.is-open),
html.dark-auto body.news-page #globalLiveModal:not(.is-open),
html.dark-auto body.news-page #createPostModal:not(.is-open),
body.dark-auto.news-page #globalLiveModal:not(.is-open),
body.dark-auto.news-page #createPostModal:not(.is-open){
  display:none !important;
  visibility:hidden !important;
  opacity:0 !important;
  pointer-events:none !important;
}
</style>
<style id="public-media-load-screen-fix">
/* In head so refresh/nav never flash empty brown media boxes before JS runs. */
.post.public-post-card.is-single-video-post:not(.mf-video-ready),
.post.public-post-card.is-single-image-post:not(.mf-image-ready){
  display:none !important;
}
.post.public-post-card.is-single-video-post .media-stage.standard-video-stage:not(.mf-media-sized),
.post.public-post-card.is-single-image-post .media-stage.standard-image-stage:not(.mf-media-sized){
  display:none !important;
}
.post.public-post-card.is-single-video-post:not(.mf-video-ready) .media-stage.standard-video-stage > video,
.post.public-post-card.is-single-image-post:not(.mf-image-ready) .media-stage.standard-image-stage > img{
  visibility:hidden !important;
  opacity:0 !important;
}
@media (max-width:767.98px){
  .post.public-post-card:not(.is-reel-post) .media-stage.phone-shot:not(.mf-media-sized),
  .post.public-post-card:not(.is-reel-post) .media-stage.phone-shot.standard-video-stage:not(.mf-media-sized),
  .post.public-post-card:not(.is-reel-post) .media-stage.phone-shot.standard-image-stage:not(.mf-media-sized),
  .media-stage.phone-shot:not(.mf-media-sized){
    aspect-ratio:auto !important;
    max-height:none !important;
    box-shadow:none !important;
    width:100% !important;
  }
}
</style>
</head>
<body class="public-page feed-insta-ui<?= $isNewsSurface ? ' news-page' : '' ?>">
<?php $GLOBALS['msb_skip_header_leftbar'] = true; $forceFeedRail = true; $skipHeaderThemeBootstrap = true; include __DIR__ . '/includes/header.php'; ?>
<?php $feedLeftRailActive = $selfPage; $feedLeftRailCanFollow = $canFollowPublishers; include __DIR__ . '/includes/feed_left_rail.php'; ?>
  <div class="sh-mainpanel">
  <?php include __DIR__ . '/includes/leftbar.php'; ?>
  <?php include __DIR__ . '/includes/stories_right_door.php'; ?>
  <div class="sh-pagebody">
    <div class="ig-feed-header">
      <?php include __DIR__ . '/includes/feed_top_user_lead.php'; ?>
      <div class="ig-stories-wrap">
        <div class="ig-stories-bar<?= empty($publicStoryCatalog) ? ' is-empty' : '' ?>" aria-label="Stories">
          <div class="ig-stories-track<?= empty($publicStoryCatalog) ? ' is-empty' : '' ?>" id="igStoriesTrack"><?php if (empty($publicStoryCatalog)): ?><div class="ig-story-item ig-story-empty" role="status" aria-label="No stories available"><div class="ig-story-ring ig-story-ring-empty"><span class="ig-story-empty-icon" aria-hidden="true"><i class="icon ion-ios-book-outline"></i></span></div><span class="ig-story-name"></span></div><?php endif; ?></div>
          <button type="button" class="ig-stories-next" aria-label="Next stories" onclick="var t=document.getElementById('igStoriesTrack');if(t){t.scrollBy({left:140,behavior:'smooth'});}"><i class="fa fa-chevron-right"></i></button>
        </div>
      </div>
      <div class="ig-feed-top-actions" aria-label="Header actions">
        <?php include __DIR__ . '/includes/feed_top_actions.php'; ?>
      </div>
    </div>
    <div class="feed-desktop-layout">
      <div class="feed-desktop-center">
        <div class="feed-top-search" aria-label="Search posts">
          <form class="feed-top-search-form" method="get" action="<?= h($selfPage) ?>">
            <div class="feed-top-search-field">
              <input
                type="search"
                name="q"
                class="feed-top-search-input"
                value="<?= h($q) ?>"
                placeholder="<?= $isNewsSurface ? 'Search CNN, Fox News, ABC…' : 'Search posts and publishers…' ?>"
                autocomplete="off"
                enterkeyhint="search"
              >
              <button type="submit" class="feed-top-search-icon" aria-label="Search">
                <i class="fa fa-search" aria-hidden="true"></i>
              </button>
            </div>
          </form>
        </div>
        <?php if ($canFollowPublishers && !$isNewsSurface): ?>
          <?php
            $publisherSearchQuery = $q;
            include __DIR__ . '/includes/publisher_search_panel.php';
          ?>
        <?php endif; ?>
        <section class="ig-feed">
      <?php if (!$posts): ?>
        <div class="mf-feed-empty" role="status">
          <i class="icon ion-ios-paper-outline" aria-hidden="true"></i>
          <div class="mf-feed-empty-title"><?= $isNewsSurface ? 'No publisher posts yet' : 'No Feed Available' ?></div>
        </div>
      <?php endif; ?>

      <?php foreach ($posts as $index => $post): ?>
        <?php
          $isOwner = ((int)$post['user_id'] === $meId) && !$staffReadonly;
          $friendStatus = (string)$post['friend_status'];
          $isPublisher = publisher_is_publisher_row($post);
          $isFollowing = !empty($post['is_following']);
          $attachments = $post['attachments'] ?? [];
          $first = $attachments[0] ?? null;
          $shapeClass = 'single-square';
          $deviceMeta = device_profile_card_meta(
              (string)($post['device_label'] ?? ''),
              (string)($post['device_viewport'] ?? '')
          );
          $isPhoneShot = !empty($deviceMeta['phone_shot']);
          $deviceStageStyle = trim((string)($deviceMeta['style'] ?? ''));

          if ($first && count($attachments) === 1) {
              $type = (string)($first['type'] ?? '');
              $srcForSize = (string)($first['thumb_path'] ?: $first['file_path']);
              $shapeClass = 'single-square';

              if ($type === 'video') {
                  $posterPath = (string)($first['thumb_path'] ?? '');
                  $absPoster = $posterPath !== '' ? (__DIR__ . '/' . ltrim(preg_replace('~^\./~', '', $posterPath), '/')) : '';
                  if ($absPoster !== '' && is_file($absPoster)) {
                      $size = @getimagesize($absPoster);
                      if (is_array($size) && !empty($size[0]) && !empty($size[1])) {
                          if ($size[1] > $size[0] * 1.1) {
                              $shapeClass = 'single-portrait';
                          } elseif ($size[0] > $size[1] * 1.15) {
                              $shapeClass = 'single-landscape';
                          }
                      }
                  } else {
                      $shapeClass = 'single-landscape';
                  }
              } else {
                  $abs = __DIR__ . '/' . ltrim(preg_replace('~^\./~', '', $srcForSize), '/');
                  if (is_file($abs)) {
                      $size = @getimagesize($abs);
                      if (is_array($size) && !empty($size[0]) && !empty($size[1])) {
                          if ($size[1] > $size[0] * 1.1) $shapeClass = 'single-portrait';
                          elseif ($size[0] > $size[1] * 1.15) $shapeClass = 'single-landscape';
                      }
                  }
              }
          }

          $captionSource = (string)($post['body'] !== '' ? $post['body'] : $post['description']);
          $caption = post_format_story_text(trim($captionSource));
          $likesTotal = (int)$post['love_count'] + (int)$post['like_count'];
          $postTitleText = trim((string)$post['title']) !== '' ? trim((string)$post['title']) : 'Post';
          $postAuthorText = trim((string)$post['display_name']) !== '' ? trim((string)$post['display_name']) : trim((string)$post['username']);
          $postDateText = (string)date('M j', strtotime((string)$post['updated_at']));
          $postAvatarText = user_avatar_label($post);
          $postAvatarUrl = user_avatar_url($post, 96);

          $declaredLayout = strtolower(trim((string)($post['declared_layout'] ?? '')));
          if ($declaredLayout === '') {
              $declaredLayout = extract_layout_override_marker((string)$post['description']);
          }

          $isSingleMedia = (
            count($attachments) === 1 &&
            isset($attachments[0]) &&
            in_array((string)($attachments[0]['type'] ?? ''), ['video','image'], true)
          );
          $isReelOnly = ($declaredLayout === 'media_reel_bottom' && $isSingleMedia);
          $isSingleStandardVideo = (
            !$isReelOnly &&
            count($attachments) === 1 &&
            isset($attachments[0]) &&
            (string)($attachments[0]['type'] ?? '') === 'video'
          );
          $isSingleStandardImage = (
            !$isReelOnly &&
            count($attachments) === 1 &&
            isset($attachments[0]) &&
            in_array((string)($attachments[0]['type'] ?? ''), ['image', 'gif'], true)
          );
          $isMultiStandardMedia = (!$isReelOnly && count($attachments) > 1);
          $isStandardMediaPost = (!$isReelOnly && !empty($attachments));
          $liveMeta = (is_array($post['live_meta'] ?? null) ? $post['live_meta'] : null);
          $isPublicLivePost = is_array($liveMeta) && (int)($liveMeta['id'] ?? 0) > 0;

          $singleMediaCardStyle = '';
          if (($isSingleStandardVideo || $isSingleStandardImage) && $deviceStageStyle !== '') {
              if (preg_match('/--device-ar-w:\s*(\d+)/', $deviceStageStyle, $deviceArW)
                  && preg_match('/--device-ar-h:\s*(\d+)/', $deviceStageStyle, $deviceArH)) {
                  $deviceW = max(1, (int)$deviceArW[1]);
                  $deviceH = max(1, (int)$deviceArH[1]);
                  $deviceAspect = $deviceW / $deviceH;
                  $maxVideoH = 960;
                  $desiredWidth = (int)round($deviceAspect * $maxVideoH);
                  $maxByShape = $deviceAspect < 0.8 ? 520 : ($deviceAspect > 1.15 ? 760 : 620);
                  $safeCardWidth = max(280, min($desiredWidth, 680, $maxByShape));
                  // Keep post card full-width (for dividers), constrain only the media stage.
                  $singleMediaCardStyle = '--post-media-card-width:' . $safeCardWidth . 'px;';
              }
          }

          $reelCaptionPreview = limit_sentences($caption, 3);
          if ($reelCaptionPreview !== $caption) {
              $reelCaptionPreview = rtrim($reelCaptionPreview) . '...';
          }

          $followBtnClass = 'reel-follow-btn';
          if ($friendStatus === 'friends') {
              $followBtnClass .= ' is-friends';
          } elseif ($friendStatus === 'incoming_pending') {
              $followBtnClass .= ' is-accept';
          } elseif ($friendStatus === 'outgoing_pending') {
              $followBtnClass .= ' is-pending';
          } elseif ($friendStatus === 'none') {
              $followBtnClass .= ' primary';
          }
        ?>
        <?php $peerProfileHref = public_profile_href($post); ?>
        <?php
          $pcmCtx = post_card_actions_menu_context($post, $meId, $dbh, $peerProfileHref, $staffReadonly, 'public');
          $pcmCtx['menu_surface'] = 'public';
          $pcmCtx['is_publisher'] = $isPublisher;
          $pcmCtx['is_following'] = $isFollowing;
          $pcmCtx['friend_status'] = $friendStatus;
          $pcmCtx['can_follow_publishers'] = $canFollowOnPublicMenu;
          $pcmCtx['publisher_workspace_viewer'] = $isPublisherWorkspaceViewer;
        ?>

        <article
          class="post public-post-card<?= $isPublicLivePost ? ' is-live-post' : '' ?><?= $isReelOnly ? ' is-reel-post' : '' ?><?= $isSingleStandardVideo ? ' is-single-video-post' : '' ?><?= $isSingleStandardImage ? ' is-single-image-post' : '' ?><?= $isMultiStandardMedia ? ' is-multi-media-post' : '' ?>"
          id="post-<?= (int)$post['id'] ?>"
          data-index="<?= (int)$index ?>"
          data-post-id="<?= (int)$post['id'] ?>"
          data-post-owner="<?= $isOwner ? '1' : '0' ?>"
          data-peer-id="<?= (int)$post['user_id'] ?>"
          data-peer-code="<?= h((string)$post['friend_code']) ?>"
          data-account-kind="<?= h((string)($post['account_kind'] ?? 'personal')) ?>"
          data-is-publisher="<?= $isPublisher ? '1' : '0' ?>"
          data-is-following="<?= $isFollowing ? '1' : '0' ?>"
          data-friend-status="<?= h($friendStatus) ?>"
          data-contact-id="<?= (int)($post['contact_id'] ?? 0) ?>"
          data-contact-name="<?= h((string)($post['contact_name'] ?? '')) ?>"
          data-edit-url="dashboard.php?modal=1&edit=<?= (int)$post['id'] ?>"
          data-comment-count="<?= (int)$post['comment_count'] ?>"
          data-like-count="<?= (int)$post['like_count'] ?>"
          data-love-count="<?= (int)$post['love_count'] ?>"
          data-my-reaction="<?= h((string)($post['my_reaction'] ?? "")) ?>"
          <?= $singleMediaCardStyle !== '' ? 'style="' . h($singleMediaCardStyle) . '"' : '' ?>
        >
          <?php if (!$isStandardMediaPost): ?>
          <div class="public-auto-progress" aria-hidden="true">
            <div class="public-auto-progress-bar"></div>
          </div>
          <?php endif; ?>

          <?php if (!$isPublicLivePost && !$isReelOnly && !$isStandardMediaPost): ?>
            <div class="post-header">
              <a class="post-author-link" href="<?= h($peerProfileHref) ?>" aria-label="Open <?= h((string)$post['display_name']) ?> profile">
                <div class="avatar"><span class="avatar-thumb"><img src="<?= h($postAvatarUrl) ?>" alt="<?= h($postAuthorText) ?>"></span></div>
                <div class="head-meta">
                  <div class="name-row">
                    <span class="name"><?= h((string)$post['display_name']) ?></span>
                    <span class="time">• <?= h((string)date('M j', strtotime((string)$post['updated_at']))) ?></span>
                  </div>
                </div>
              </a>

              <?php if (!$isOwner && !$isReelOnly): ?>
                <div class="post-card-head-actions">
                <?php if ($isPublisher && $canFollowPublishers): ?>
                <button
                  type="button"
                  class="publisher-follow-btn<?= $isFollowing ? ' is-following' : ' primary' ?>"
                  data-publisher-id="<?= (int)$post['user_id'] ?>"
                ><?= $isFollowing ? 'Following' : 'Follow' ?></button>
                <?php elseif (!$isPublisher && $friendStatus !== 'friends'): ?>
                <button
                  type="button"
                  class="friend-btn<?= $friendStatus === 'outgoing_pending' ? ' is-pending' : '' ?><?= $friendStatus === 'incoming_pending' ? ' is-accept' : '' ?><?= $friendStatus === 'none' ? ' primary' : '' ?>"
                  data-peer-id="<?= (int)$post['user_id'] ?>"
                  data-status="<?= h($friendStatus) ?>"
                >
                  <?= $friendStatus === 'incoming_pending' ? 'Accept' : ($friendStatus === 'outgoing_pending' ? 'Sent' : '+') ?>
                </button>
                <?php endif; ?>
              <?php else: ?>
                <div class="post-card-head-actions">
              <?php endif; ?>

              <?= post_card_actions_menu_shell_html($pcmCtx) ?>
              </div>
            </div>
          <?php elseif (!$isPublicLivePost && $isReelOnly): ?>
            <div class="post-header">
              <a class="post-author-link" href="<?= h($peerProfileHref) ?>" aria-label="Open <?= h((string)$post['display_name']) ?> profile">
                <div class="avatar"><span class="avatar-thumb"><img src="<?= h($postAvatarUrl) ?>" alt="<?= h($postAuthorText) ?>"></span></div>
                <div class="head-meta">
                  <div class="name-row">
                    <span class="name"><?= h((string)$post['display_name']) ?></span>
                    <span class="time">• <?= h((string)date('M j', strtotime((string)$post['updated_at']))) ?></span>
                  </div>
                </div>
              </a>
              <?= post_card_actions_menu_shell_html($pcmCtx) ?>
            </div>
          <?php endif; ?>

          <?php if (!$isPublicLivePost && !$isReelOnly && !$isStandardMediaPost): ?>
            <div class="standard-text-card">
              <div class="standard-text-topbar">
                <a class="standard-text-author" href="<?= h($peerProfileHref) ?>" aria-label="Open <?= h((string)$post['display_name']) ?> profile">
                  <div class="avatar"><span class="avatar-thumb"><img src="<?= h($postAvatarUrl) ?>" alt="<?= h($postAuthorText) ?>"></span></div>
                  <div class="standard-text-meta">
                    <span class="standard-text-name"><?= h((string)$post['display_name']) ?></span>
                    <span class="standard-text-time">• <?= h((string)date('M j', strtotime((string)$post['updated_at']))) ?></span>
                  </div>
                </a>
                <div class="standard-text-top-actions post-card-head-actions">
                  <?php if (!$isOwner && (!$isPublisher || $canFollowPublishers)): ?>
                    <?php if ($isPublisher && $canFollowPublishers): ?>
                    <button type="button" class="publisher-follow-btn<?= $isFollowing ? ' is-following' : ' primary' ?>" data-publisher-id="<?= (int)$post['user_id'] ?>"><?= $isFollowing ? 'Following' : 'Follow' ?></button>
                    <?php elseif (!$isPublisher && $friendStatus !== 'friends'): ?>
                    <button
                      type="button"
                      class="friend-btn<?= $friendStatus === 'outgoing_pending' ? ' is-pending' : '' ?><?= $friendStatus === 'incoming_pending' ? ' is-accept' : '' ?><?= $friendStatus === 'none' ? ' primary' : '' ?>"
                      data-peer-id="<?= (int)$post['user_id'] ?>"
                      data-status="<?= h($friendStatus) ?>"
                    >
                      <?= $friendStatus === 'incoming_pending' ? 'Accept' : ($friendStatus === 'outgoing_pending' ? 'Sent' : '+') ?>
                    </button>
                    <?php endif; ?>
                  <?php endif; ?>
                  <?= post_card_actions_menu_shell_html($pcmCtx) ?>
                </div>
              </div>

              <div class="standard-text-copy">
                <?php if ((string)$post['title'] !== ''): ?>
                  <h3 class="standard-text-title"><?= h((string)$post['title']) ?></h3>
                <?php endif; ?>
                <?php if ($caption !== ''): ?>
                  <div class="standard-text-caption">
                    <?= public_caption_card_html($caption) ?>
                    <a
                      class="open-inline js-open-readmore"
                      href="#post-<?= (int)$post['id'] ?>"
                      data-post-id="<?= (int)$post['id'] ?>"
                      data-title="<?= h($postTitleText) ?>"
                      data-author="<?= h($postAuthorText) ?>"
                      data-date="<?= h($postDateText) ?>"
                      data-avatar="<?= h($postAvatarText) ?>"
                      data-avatar-url="<?= h($postAvatarUrl) ?>"
                      data-body="<?= h($caption) ?>"
                    >Read more</a>
                  </div>
                <?php endif; ?>
              </div>

              <div class="standard-text-actions">
                <div class="standard-text-left">
                  <div class="standard-text-row">
                    <a class="standard-text-btn js-react-love<?= public_reaction_is_love_lane((string)($post['my_reaction'] ?? '')) ? ' is-love' : '' ?>" type="button" aria-label="Love" data-post-id="<?= (int)$post['id'] ?>">
                      <?= post_action_thin_icon('heart', (string)($post['my_reaction'] ?? '') === 'love') ?>
                      <span class="action-count js-love-count"><?= (int)$post['love_count'] ?></span>
                    </a>
                    <!-- <a class="standard-text-btn js-react-like<?= public_reaction_is_like_lane((string)($post['my_reaction'] ?? '')) ? ' is-like' : '' ?>" type="button" aria-label="Like" data-post-id="<?= (int)$post['id'] ?>">
                      <i class="fa <?= ((string)($post['my_reaction'] ?? '') === 'like') ? 'fa-thumbs-up' : 'fa-thumbs-o-up' ?>"></i>
                      <span class="action-count js-like-count"><?= (int)$post['like_count'] ?></span>
                    </a> -->
                    <a class="standard-text-btn js-open-comments" type="button" aria-label="Comment" data-post-id="<?= (int)$post['id'] ?>">
                      <?= post_action_thin_icon('comment') ?>
                      <span class="action-count js-comment-count-inline"><?= (int)$post['comment_count'] ?></span>
                    </a>
                    <a class="standard-text-btn js-share-post<?= !empty($post['my_shared']) ? ' is-share' : '' ?>" type="button" aria-label="Share" data-post-id="<?= (int)$post['id'] ?>">
                      <?= post_action_thin_icon('share', !empty($post['my_shared'])) ?>
                      <span class="action-count js-share-count"><?= (int)($post['share_count'] ?? 0) ?></span>
                    </a>
                  </div>
                  <div class="standard-text-comments js-open-comments" data-post-id="<?= (int)$post['id'] ?>">
                    View all <span class="js-comment-count"><?= (int)$post['comment_count'] ?></span> comments
                  </div>
                </div>
                <div class="standard-text-right">
                  <a class="standard-text-btn js-save-post<?= !empty($post['my_saved']) ? ' is-save' : '' ?>" type="button" aria-label="Save" data-post-id="<?= (int)$post['id'] ?>">
                    <?= post_action_thin_icon('bookmark', !empty($post['my_saved'])) ?>
                    <span class="action-count js-save-count"><?= (int)($post['save_count'] ?? 0) ?></span>
                  </a>
                  <div class="standard-text-views"><?= (int)$post['views_count'] ?> views</div>
                </div>
              </div>
            </div>
          <?php endif; ?>

          <?php if ($isPublicLivePost): ?>
            <?php
              $liveTitle = trim((string)($liveMeta['title'] ?? ''));
              if ($liveTitle === '') $liveTitle = trim((string)$post['title']);
              if ($liveTitle === '') $liveTitle = 'Live now';
              $liveDesc = trim((string)($liveMeta['description'] ?? ''));
              if ($liveDesc === '') {
                  $liveDesc = trim((string)preg_replace('/(?:\[\[live_post:\d+\]\]|\b(?:live_watch|watch_live)\.php\?live=\d+\b)/i', '', $caption));
              }
              if ($liveDesc === '') $liveDesc = 'Join the live stream and watch now.';
              $liveWatchUrl = trim((string)($liveMeta['watch_url'] ?? ''));
              if ($liveWatchUrl === '') $liveWatchUrl = 'live_watch.php?live=' . (int)($liveMeta['id'] ?? 0);
              $liveSnapshotUrl = trim((string)($liveMeta['snapshot_url'] ?? ''));
              $liveViewers = (int)($liveMeta['viewer_count'] ?? 0);
              $liveReacts = (int)($liveMeta['reaction_count'] ?? 0);
              $liveHostText = trim((string)($liveMeta['host_name'] ?? ''));
              if ($liveHostText === '') $liveHostText = trim((string)$post['display_name']);
            ?>
            <div class="public-live-frame-wrap">
              <div class="public-live-frame">
                <?php if ($liveSnapshotUrl !== ''): ?>
                  <img src="<?= h($liveSnapshotUrl) ?>" alt="<?= h($liveTitle) ?>">
                <?php else: ?>
                  <div class="public-live-placeholder">
                    <div class="public-live-placeholder-inner">
                      <div class="public-live-placeholder-avatar"><img src="<?= h($postAvatarUrl) ?>" alt="<?= h($liveHostText) ?>"></div>
                      <h3 class="public-live-placeholder-title"><?= h($liveTitle) ?></h3>
                      <p class="public-live-placeholder-sub"><?= h($liveHostText) ?> is live now. Tap to open the stream.</p>
                    </div>
                  </div>
                <?php endif; ?>
                <a class="public-live-open-hit" href="<?= h($liveWatchUrl) ?>" aria-label="Watch live"></a>
                <div class="public-live-overlay">
                  <div class="public-live-top">
                    <div class="public-live-host">
                      <div class="public-live-host-avatar"><img src="<?= h($postAvatarUrl) ?>" alt="<?= h($liveHostText) ?>"></div>
                      <div class="public-live-host-meta">
                        <p class="public-live-host-name"><?= h($liveHostText) ?></p>
                        <p class="public-live-host-sub">Live now</p>
                      </div>
                    </div>
                    <div class="public-live-top-pills">
                      <span class="public-live-pill"><i class="icon ion-ios-eye"></i><span><?= (int)$liveViewers ?></span></span>
                      <span class="public-live-pill"><i class="fa fa-heart"></i><span><?= (int)$liveReacts ?></span></span>
                    </div>
                  </div>
                  <div class="public-live-bottom">
                    <div class="public-live-copy">
                      <span class="public-live-chip">LIVE NOW · Public</span>
                      <h3 class="public-live-title"><?= h($liveTitle) ?></h3>
                      <p class="public-live-desc"><?= h($liveDesc) ?></p>
                    </div>
                    <div class="public-live-footer">
                      <a class="public-live-cta" href="<?= h($liveWatchUrl) ?>"><i class="fa fa-play"></i>Watch live</a>
                    </div>
                    <div class="public-live-actionbar">
                      <div class="public-live-action-left">
                        <div class="public-live-action-row">
                          <a class="public-live-action-btn js-react-love<?= public_reaction_is_love_lane((string)($post['my_reaction'] ?? '')) ? ' is-love' : '' ?>" type="button" aria-label="Love" data-post-id="<?= (int)$post['id'] ?>">
                            <?= post_action_thin_icon('heart', (string)($post['my_reaction'] ?? '') === 'love') ?>
                            <span class="action-count js-love-count"><?= (int)$post['love_count'] ?></span>
                          </a>
                          <a class="public-live-action-btn js-react-like<?= public_reaction_is_like_lane((string)($post['my_reaction'] ?? '')) ? ' is-like' : '' ?>" type="button" aria-label="Like" data-post-id="<?= (int)$post['id'] ?>">
                            <i class="fa <?= ((string)($post['my_reaction'] ?? '') === 'like') ? 'fa-thumbs-up' : 'fa-thumbs-o-up' ?>"></i>
                            <span class="action-count js-like-count"><?= (int)$post['like_count'] ?></span>
                          </a>
                          <a class="public-live-action-btn js-open-comments" type="button" aria-label="Comment" data-post-id="<?= (int)$post['id'] ?>">
                            <?= post_action_thin_icon('comment') ?>
                            <span class="action-count js-comment-count-inline"><?= (int)$post['comment_count'] ?></span>
                          </a>
                          <a class="public-live-action-btn js-share-post<?= !empty($post['my_shared']) ? ' is-share' : '' ?>" type="button" aria-label="Share" data-post-id="<?= (int)$post['id'] ?>">
                            <?= post_action_thin_icon('share', !empty($post['my_shared'])) ?>
                            <span class="action-count js-share-count"><?= (int)($post['share_count'] ?? 0) ?></span>
                          </a>
                          <span class="public-live-action-spacer">
                            <a class="public-live-action-btn js-save-post<?= !empty($post['my_saved']) ? ' is-save' : '' ?>" type="button" aria-label="Save" data-post-id="<?= (int)$post['id'] ?>">
                              <?= post_action_thin_icon('bookmark', !empty($post['my_saved'])) ?>
                              <span class="action-count js-save-count"><?= (int)($post['save_count'] ?? 0) ?></span>
                            </a>
                          </span>
                        </div>
                        <div class="public-live-comments-link js-open-comments" data-post-id="<?= (int)$post['id'] ?>">
                          View all <span class="js-comment-count"><?= (int)$post['comment_count'] ?></span> comments
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          <?php elseif ($isReelOnly): ?>
            <?php $a = $attachments[0]; $src = h((string)$a['file_path']); ?>
            <div class="reel-topbar">
              <div class="reel-top-left">
                <a class="reel-top-author" href="<?= h($peerProfileHref) ?>" aria-label="Open <?= h((string)$post['display_name']) ?> profile">
                  <div class="avatar"><span class="avatar-thumb"><img src="<?= h($postAvatarUrl) ?>" alt="<?= h($postAuthorText) ?>"></span></div>
                  <div class="reel-top-meta">
                    <span class="reel-top-name"><?= h((string)$post['display_name']) ?></span>
                    <span class="reel-top-time">• <?= h((string)date('M j', strtotime((string)$post['updated_at']))) ?></span>
                  </div>
                </a>
              </div>
              <div class="reel-top-right post-card-head-actions">
                <?php if (!$isOwner && (!$isPublisher || $canFollowPublishers)): ?>
                  <?php if ($isPublisher && $canFollowPublishers): ?>
                  <button type="button" class="publisher-follow-btn<?= $isFollowing ? ' is-following' : ' primary' ?>" data-publisher-id="<?= (int)$post['user_id'] ?>"><?= $isFollowing ? 'Following' : 'Follow' ?></button>
                  <?php elseif (!$isPublisher && $friendStatus !== 'friends'): ?>
                  <button
                    type="button"
                    class="friend-btn<?= $friendStatus === 'outgoing_pending' ? ' is-pending' : '' ?><?= $friendStatus === 'incoming_pending' ? ' is-accept' : '' ?><?= $friendStatus === 'none' ? ' primary' : '' ?>"
                    data-peer-id="<?= (int)$post['user_id'] ?>"
                    data-status="<?= h($friendStatus) ?>"
                  >
                    <?= $friendStatus === 'incoming_pending' ? 'Accept' : ($friendStatus === 'outgoing_pending' ? 'Sent' : '+') ?>
                  </button>
                  <?php endif; ?>
                <?php endif; ?>
                <?= post_card_actions_menu_shell_html($pcmCtx) ?>
              </div>
            </div>

            <div class="reel-stage">
              <?php if ((string)$a['type'] === 'video'): ?>
                <video
                  class="reel-video js-reel-video"
                  src="<?= $src ?>"
                  muted
                  loop
                  playsinline
                  preload="metadata"
                ></video>
              <?php else: ?>
                <img
                  class="reel-video"
                  src="<?= $src ?>"
                  alt=""
                >
              <?php endif; ?>
            </div>

            <div class="reel-bottom">
              <?php if ($caption !== ''): ?>
                <div class="reel-caption<?= mb_strlen($caption) > 170 ? ' has-more' : '' ?>">
                  <div class="reel-caption-text"><?= public_caption_card_html($caption) ?></div>
                  <?php if (mb_strlen($caption) > 170): ?>
                    <a
                      class="open-inline js-open-readmore"
                      href="#post-<?= (int)$post['id'] ?>"
                      data-post-id="<?= (int)$post['id'] ?>"
                      data-title="<?= h($postTitleText) ?>"
                      data-author="<?= h($postAuthorText) ?>"
                      data-date="<?= h($postDateText) ?>"
                      data-avatar="<?= h($postAvatarText) ?>"
                      data-avatar-url="<?= h($postAvatarUrl) ?>"
                      data-body="<?= h($caption) ?>"
                    >Read more</a>
                  <?php endif; ?>
                </div>
              <?php endif; ?>
              <div class="reel-inline-actions">
                <div class="reel-inline-left">
                  <div class="reel-inline-row">
                    <a class="reel-inline-btn js-react-love<?= public_reaction_is_love_lane((string)($post['my_reaction'] ?? '')) ? ' is-love' : '' ?>" type="button" aria-label="Love" data-post-id="<?= (int)$post['id'] ?>">
                      <?= post_action_thin_icon('heart', (string)($post['my_reaction'] ?? '') === 'love') ?>
                      <span class="action-count js-love-count"><?= (int)$post['love_count'] ?></span>
                    </a>
                    <a class="reel-inline-btn js-react-like<?= public_reaction_is_like_lane((string)($post['my_reaction'] ?? '')) ? ' is-like' : '' ?>" type="button" aria-label="Like" data-post-id="<?= (int)$post['id'] ?>">
                      <i class="fa <?= ((string)($post['my_reaction'] ?? '') === 'like') ? 'fa-thumbs-up' : 'fa-thumbs-o-up' ?>"></i>
                      <span class="action-count js-like-count"><?= (int)$post['like_count'] ?></span>
                    </a>
                    <a class="reel-inline-btn js-open-comments" type="button" aria-label="Comment" data-post-id="<?= (int)$post['id'] ?>">
                      <?= post_action_thin_icon('comment') ?>
                      <span class="action-count js-comment-count-inline"><?= (int)$post['comment_count'] ?></span>
                    </a>
                    <a class="reel-inline-btn js-share-post<?= !empty($post['my_shared']) ? ' is-share' : '' ?>" type="button" aria-label="Share" data-post-id="<?= (int)$post['id'] ?>">
                      <?= post_action_thin_icon('share', !empty($post['my_shared'])) ?>
                      <span class="action-count js-share-count"><?= (int)($post['share_count'] ?? 0) ?></span>
                    </a>
                  </div>
                  <div class="reel-inline-comments js-open-comments" data-post-id="<?= (int)$post['id'] ?>">
                    View all <span class="js-comment-count"><?= (int)$post['comment_count'] ?></span> comments
                  </div>
                </div>
                <div class="reel-inline-right">
                  <a class="reel-inline-btn js-save-post<?= !empty($post['my_saved']) ? ' is-save' : '' ?>" type="button" aria-label="Save" data-post-id="<?= (int)$post['id'] ?>">
                    <?= post_action_thin_icon('bookmark', !empty($post['my_saved'])) ?>
                    <span class="action-count js-save-count"><?= (int)($post['save_count'] ?? 0) ?></span>
                  </a>
                  <div class="reel-inline-views"><?= (int)$post['views_count'] ?> views</div>
                </div>
              </div>
            </div>
          <?php elseif (!empty($attachments)): ?>
            <?php $hasMultiMedia = count($attachments) > 1; ?>
            <?php $mediaStageShape = ($isSingleStandardVideo || $isSingleStandardImage) ? '' : $shapeClass; ?>
            <div class="media-stage <?= h($mediaStageShape) ?><?= !empty($isPhoneShot) ? ' phone-shot' : '' ?><?= $isSingleStandardVideo ? ' standard-video-stage' : '' ?><?= $isSingleStandardImage ? ' standard-image-stage' : '' ?><?= $hasMultiMedia ? ' has-carousel js-media-carousel' : '' ?>"<?= $deviceStageStyle !== '' ? ' style="' . h($deviceStageStyle) . '"' : '' ?><?= $hasMultiMedia ? ' data-count="' . (int)count($attachments) . '" data-index="0"' : '' ?>>
              <?php if (!$hasMultiMedia): ?>
                <?php $a = $attachments[0]; $src = h((string)$a['file_path']); ?>
                <?php if ((string)$a['type'] === 'image'): ?>
                  <img src="<?= $src ?>" alt="">
                <?php elseif ((string)$a['type'] === 'video'): ?>
                  <?php
                    $videoPosterPath = trim((string)($a['thumb_path'] ?? ''));
                    $videoPosterAttr = $videoPosterPath !== '' ? (' poster="' . h($videoPosterPath) . '"') : '';
                  ?>
                  <video src="<?= $src ?>"<?= $videoPosterAttr ?> playsinline preload="metadata" muted loop></video>
                <?php else: ?>
                  <div class="file-tile">
                    <div>
                      <i class="icon ion-document-text" style="font-size:48px"></i>
                      <div style="margin-top:12px"><a href="<?= $src ?>" target="_blank" style="color:#fff;font-weight:700">Open file</a></div>
                    </div>
                  </div>
                <?php endif; ?>
              <?php else: ?>
                <div class="media-carousel">
                  <div class="media-slides">
                    <?php foreach ($attachments as $slideIndex => $a): $src = h((string)$a['file_path']); ?>
                      <div class="media-slide" data-slide-index="<?= (int)$slideIndex ?>">
                        <?php if ((string)$a['type'] === 'image'): ?>
                          <img src="<?= $src ?>" alt="">
                        <?php elseif ((string)$a['type'] === 'video'): ?>
                          <?php
                            $slidePosterPath = trim((string)($a['thumb_path'] ?? ''));
                            $slidePosterAttr = $slidePosterPath !== '' ? (' poster="' . h($slidePosterPath) . '"') : '';
                          ?>
                          <video src="<?= $src ?>"<?= $slidePosterAttr ?> playsinline preload="metadata" muted loop></video>
                        <?php else: ?>
                          <div class="file-tile">
                            <div>
                              <i class="icon ion-document-text" style="font-size:48px"></i>
                              <div style="margin-top:12px"><a href="<?= $src ?>" target="_blank" style="color:#fff;font-weight:700">Open file</a></div>
                            </div>
                          </div>
                        <?php endif; ?>
                      </div>
                    <?php endforeach; ?>
                  </div>
                  <button type="button" class="media-nav prev js-media-prev" aria-label="Previous media"><i class="fa fa-chevron-left"></i></button>
                  <button type="button" class="media-nav next js-media-next" aria-label="Next media"><i class="fa fa-chevron-right"></i></button>
                  <div class="media-dots" role="tablist" aria-label="Media slides">
                    <?php foreach ($attachments as $slideIndex => $a): ?>
                      <button type="button" class="media-dot<?= $slideIndex === 0 ? ' is-active' : '' ?>" data-index="<?= (int)$slideIndex ?>" aria-label="Go to media <?= (int)$slideIndex + 1 ?>"></button>
                    <?php endforeach; ?>
                  </div>
                </div>
              <?php endif; ?>

              <?php if ($isStandardMediaPost): ?>
                <div class="public-auto-progress public-auto-progress--media" aria-hidden="true">
                  <div class="public-auto-progress-bar"></div>
                </div>
                <div class="standard-media-topbar">
                  <a class="standard-media-author" href="<?= h($peerProfileHref) ?>" aria-label="Open <?= h((string)$post['display_name']) ?> profile">
                    <div class="avatar"><span class="avatar-thumb"><img src="<?= h($postAvatarUrl) ?>" alt="<?= h($postAuthorText) ?>"></span></div>
                    <div class="standard-media-meta">
                      <div class="standard-media-name-row">
                        <span class="standard-media-name"><?= h((string)$post['display_name']) ?></span>
                        <span class="standard-media-time">• <?= h((string)date('M j', strtotime((string)$post['updated_at']))) ?></span>
                      </div>
                      <?= post_music_row_html($post) ?>
                    </div>
                  </a>
                  <?= post_card_actions_menu_shell_html($pcmCtx, 'standard-media-topbar-menu') ?>
                </div>
                <?php if (!$isOwner && (($isPublisher && $canFollowPublishers && !$isFollowing) || (!$isPublisher && $friendStatus !== 'friends'))): ?>
                <div class="standard-media-top-actions post-card-head-actions">
                  <?php if ($isPublisher && $canFollowPublishers): ?>
                  <?= publisher_media_follow_btn_html((int)$post['user_id'], $isFollowing, $canFollowPublishers) ?>
                  <?php elseif (!$isPublisher): ?>
                  <button
                    type="button"
                    class="friend-btn mf-media-action-circle mf-media-follow-btn<?= $friendStatus === 'outgoing_pending' ? ' is-pending' : '' ?><?= $friendStatus === 'incoming_pending' ? ' is-accept' : '' ?><?= $friendStatus === 'none' ? ' primary' : '' ?>"
                    data-peer-id="<?= (int)$post['user_id'] ?>"
                    data-status="<?= h($friendStatus) ?>"
                    aria-label="<?= $friendStatus === 'outgoing_pending' ? 'Request sent' : ($friendStatus === 'incoming_pending' ? 'Accept friend request' : 'Add friend') ?>"
                    title="<?= $friendStatus === 'outgoing_pending' ? 'Request sent' : ($friendStatus === 'incoming_pending' ? 'Accept friend request' : 'Add friend') ?>"
                    <?= $friendStatus === 'outgoing_pending' ? 'disabled' : '' ?>
                  >
                    <?php if ($friendStatus === 'outgoing_pending'): ?>
                    <span class="mf-media-action-label">Sent</span>
                    <?php elseif ($friendStatus === 'incoming_pending'): ?>
                    <span class="mf-media-action-label">Accept</span>
                    <?php else: ?>
                    <i class="fa fa-plus" aria-hidden="true"></i>
                    <?php endif; ?>
                  </button>
                  <?php endif; ?>
                </div>
                <?php endif; ?>

                <div class="standard-media-bottom">
                  <?php if ((string)$post['title'] !== '' || $caption !== ''): ?>
                    <div class="standard-media-copy">
                      <?php if ((string)$post['title'] !== ''): ?>
                        <h3 class="standard-media-title"><?= h((string)$post['title']) ?></h3>
                      <?php endif; ?>
                      <?php if ($caption !== ''): ?>
                        <div class="standard-media-caption">
                          <?= public_caption_card_html($caption) ?>
                          <a
                            class="open-inline js-open-readmore"
                            href="#post-<?= (int)$post['id'] ?>"
                            data-post-id="<?= (int)$post['id'] ?>"
                            data-title="<?= h($postTitleText) ?>"
                            data-author="<?= h($postAuthorText) ?>"
                            data-date="<?= h($postDateText) ?>"
                            data-avatar="<?= h($postAvatarText) ?>"
                            data-avatar-url="<?= h($postAvatarUrl) ?>"
                            data-body="<?= h($caption) ?>"
                          >Read more</a>
                        </div>
                      <?php endif; ?>
                    </div>
                  <?php endif; ?>
                  <div class="standard-media-actions">
                    <div class="standard-media-left">
                      <div class="standard-media-row">
                        <a class="standard-media-btn js-react-love<?= public_reaction_is_love_lane((string)($post['my_reaction'] ?? '')) ? ' is-love' : '' ?>" type="button" aria-label="Love" data-post-id="<?= (int)$post['id'] ?>">
                          <?= post_action_thin_icon('heart', (string)($post['my_reaction'] ?? '') === 'love') ?>
                          <span class="action-count js-love-count"><?= (int)$post['love_count'] ?></span>
                        </a>
                        <!-- <a class="standard-media-btn js-react-like<?= public_reaction_is_like_lane((string)($post['my_reaction'] ?? '')) ? ' is-like' : '' ?>" type="button" aria-label="Like" data-post-id="<?= (int)$post['id'] ?>">
                          <i class="fa <?= ((string)($post['my_reaction'] ?? '') === 'like') ? 'fa-thumbs-up' : 'fa-thumbs-o-up' ?>"></i>
                          <span class="action-count js-like-count"><?= (int)$post['like_count'] ?></span>
                        </a> -->
                        <a class="standard-media-btn js-open-comments" type="button" aria-label="Comment" data-post-id="<?= (int)$post['id'] ?>">
                          <?= post_action_thin_icon('comment') ?>
                          <span class="action-count js-comment-count-inline"><?= (int)$post['comment_count'] ?></span>
                        </a>
                        <a class="standard-media-btn js-share-post<?= !empty($post['my_shared']) ? ' is-share' : '' ?>" type="button" aria-label="Share" data-post-id="<?= (int)$post['id'] ?>">
                          <?= post_action_thin_icon('share', !empty($post['my_shared'])) ?>
                          <span class="action-count js-share-count"><?= (int)($post['share_count'] ?? 0) ?></span>
                        </a>
                      </div>
                      <!-- <div class="standard-media-comments js-open-comments" data-post-id="<?= (int)$post['id'] ?>">
                        View all <span class="js-comment-count"><?= (int)$post['comment_count'] ?></span> comments
                      </div> -->
                    </div>
                    <div class="standard-media-right">
                      <a class="standard-media-btn js-save-post<?= !empty($post['my_saved']) ? ' is-save' : '' ?>" type="button" aria-label="Save" data-post-id="<?= (int)$post['id'] ?>">
                        <?= post_action_thin_icon('bookmark', !empty($post['my_saved'])) ?>
                        <span class="action-count js-save-count"><?= (int)($post['save_count'] ?? 0) ?></span>
                      </a>
                      <!-- <div class="standard-media-views"><?= (int)$post['views_count'] ?> views</div> -->
                    </div>
                  </div>
                </div>
              <?php endif; ?>
            </div>
          <?php endif; ?>

          <?php if (false): ?>
            <div class="actions">
              <div class="action-row">
                <div class="action-left">
                  <a class="action-btn js-react-love<?= public_reaction_is_love_lane((string)($post['my_reaction'] ?? '')) ? ' is-love' : '' ?>" type="button" aria-label="Love" data-post-id="<?= (int)$post['id'] ?>">
                    <?= post_action_thin_icon('heart', (string)($post['my_reaction'] ?? '') === 'love') ?>
                    <span class="action-count js-love-count"><?= (int)$post['love_count'] ?></span>
                  </a>

                  <a class="action-btn js-react-like<?= public_reaction_is_like_lane((string)($post['my_reaction'] ?? '')) ? ' is-like' : '' ?>" type="button" aria-label="Like" data-post-id="<?= (int)$post['id'] ?>">
                    <i class="fa <?= ((string)($post['my_reaction'] ?? '') === 'like') ? 'fa-thumbs-up' : 'fa-thumbs-o-up' ?>"></i>
                    <span class="action-count js-like-count"><?= (int)$post['like_count'] ?></span>
                  </a>

                  <a class="action-btn js-open-comments" type="button" aria-label="Comment" data-post-id="<?= (int)$post['id'] ?>">
                    <?= post_action_thin_icon('comment') ?>
                    <span class="action-count js-comment-count-inline"><?= (int)$post['comment_count'] ?></span>
                  </a>

                  <a class="action-btn js-share-post<?= !empty($post['my_shared']) ? ' is-share' : '' ?>" type="button" aria-label="Share" data-post-id="<?= (int)$post['id'] ?>">
                    <?= post_action_thin_icon('share', !empty($post['my_shared'])) ?>
                    <span class="action-count js-share-count"><?= (int)($post['share_count'] ?? 0) ?></span>
                  </a>
                </div>

                <div class="action-right">
                  <a class="action-btn js-save-post<?= !empty($post['my_saved']) ? ' is-save' : '' ?>" type="button" aria-label="Save" data-post-id="<?= (int)$post['id'] ?>">
                    <?= post_action_thin_icon('bookmark', !empty($post['my_saved'])) ?>
                    <span class="action-count js-save-count"><?= (int)($post['save_count'] ?? 0) ?></span>
                  </a>
                </div>
              </div>

              <div class="likes-line"><strong class="js-like-total"><?= (int)$likesTotal ?></strong> reactions</div>

              <div style="display:flex;">
                <div class="comments-line js-open-comments" style="flex: 1;" data-post-id="<?= (int)$post['id'] ?>">View all <span class="js-comment-count"><?= (int)$post['comment_count'] ?></span> comments</div>
                <div class="comments-line"><?= (int)$post['views_count'] ?> views</div>
              </div>
            </div>
          <?php endif; ?>
        </article>
      <?php endforeach; ?>
        </section>
      </div>
    </div>
    <?php
      $suggestedForYouStaffReadonly = $staffReadonly;
      include __DIR__ . '/includes/suggested_for_you.php';
    ?>
  </div><!-- /.sh-pagebody -->
  </div><!-- /.sh-mainpanel -->

  <form id="deletePostForm" method="post" action="<?= h($selfPage) ?><?= $q !== '' ? ('?q=' . urlencode($q)) : '' ?>" style="display:none;">
    <input type="hidden" name="action" value="delete_post">
    <input type="hidden" name="post_id" id="deletePostId" value="0">
  </form>

  <div class="modal fade confirm-sheet" id="deleteConfirmModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
      <div class="modal-content">
        <div class="modal-body">
          <div class="confirm-title">Delete this post?</div>
          <p class="confirm-copy">This will remove your post from public view.</p>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light" data-dismiss="modal">Cancel</button>
          <button type="button" class="btn btn-danger" id="confirmDeleteBtn">OK</button>
        </div>
      </div>
    </div>
  </div>

  <!-- <a class="msg-pill" href="compose.php">
    <i class="chatBadge"></i>
    <span class="txt">New Compose</span>
    <span class="toggle-bubbles"><span>Theme</span><span></span><span class="on"></span></span>
  </a> -->

  <div class="jump-rail">
    <button type="button" id="btnUp" aria-label="Previous post"><i class="icon ion-chevron-up"></i></button>
    <button type="button" id="btnDown" aria-label="Next post"><i class="icon ion-chevron-down"></i></button>
  </div>

<script>
(function(){
  var publicAutoAdvanceTimer = null;
  var publicAutoAdvanceStartedAt = 0;
  var publicAutoAdvanceDelay = 0;
  var publicAutoAdvanceRemaining = 0;
  var publicAutoAdvanceHovered = false;
  var publicAutoAdvanceCardId = 0;
  var publicAutoAdvanceScrollTick = null;
  var publicAutoAdvanceProgressRaf = 0;

  function cards(){ return Array.prototype.slice.call(document.querySelectorAll('.public-post-card')); }

  function currentIndex(){
    var list = cards();
    if(!list.length) return -1;
    var best = 0, bestDist = Infinity;
    list.forEach(function(card, i){
      var r = card.getBoundingClientRect();
      var d = Math.abs(r.top - 90);
      if (d < bestDist){ bestDist = d; best = i; }
    });
    return best;
  }

  function go(step){
    var list = cards();
    if(!list.length) return;
    var idx = currentIndex();
    if(idx < 0) idx = 0;
    idx = Math.max(0, Math.min(list.length - 1, idx + step));
    list[idx].scrollIntoView({behavior:'smooth', block:'start'});
  }

  var up = document.getElementById('btnUp'), down = document.getElementById('btnDown');
  if (up) up.addEventListener('click', function(){ go(-1); });
  if (down) down.addEventListener('click', function(){ go(1); });

  document.addEventListener('keydown', function(e){
    if (e.key === 'ArrowUp') go(-1);
    if (e.key === 'ArrowDown') go(1);
  });

  function clearPublicAutoAdvance(){
    if(publicAutoAdvanceTimer){
      window.clearTimeout(publicAutoAdvanceTimer);
      publicAutoAdvanceTimer = null;
    }
    stopPublicAutoAdvanceProgress(false);
    publicAutoAdvanceStartedAt = 0;
    publicAutoAdvanceDelay = 0;
    publicAutoAdvanceRemaining = 0;
    publicAutoAdvanceCardId = 0;
  }

  function setPublicAutoAdvanceProgress(card, pct){
    if(!card) return;
    var bar = card.querySelector('.public-auto-progress-bar');
    if(!bar) return;
    pct = Math.max(0, Math.min(100, Number(pct || 0)));
    bar.style.width = pct + '%';
  }

  function resetPublicAutoAdvanceProgress(exceptCard){
    document.querySelectorAll('.public-post-card .public-auto-progress-bar').forEach(function(bar){
      var card = bar.closest('.public-post-card');
      if(exceptCard && card === exceptCard) return;
      bar.style.width = '0%';
    });
  }

  function stopPublicAutoAdvanceProgress(keepPosition){
    if(publicAutoAdvanceProgressRaf){
      cancelAnimationFrame(publicAutoAdvanceProgressRaf);
      publicAutoAdvanceProgressRaf = 0;
    }
    if(!keepPosition){
      resetPublicAutoAdvanceProgress(null);
    }
  }

  function startPublicAutoAdvanceProgress(card){
    stopPublicAutoAdvanceProgress(true);
    resetPublicAutoAdvanceProgress(card);
    if(!card || !publicAutoAdvanceStartedAt || !publicAutoAdvanceDelay) return;
    var tick = function(){
      if(!card || !publicAutoAdvanceStartedAt || !publicAutoAdvanceDelay){
        stopPublicAutoAdvanceProgress(false);
        return;
      }
      var active = currentCard();
      if(!active || active !== card){
        resetPublicAutoAdvanceProgress(card);
        publicAutoAdvanceProgressRaf = 0;
        return;
      }
      var elapsed = Math.max(0, Date.now() - publicAutoAdvanceStartedAt);
      setPublicAutoAdvanceProgress(card, (elapsed / publicAutoAdvanceDelay) * 100);
      if(elapsed >= publicAutoAdvanceDelay){
        publicAutoAdvanceProgressRaf = 0;
        setPublicAutoAdvanceProgress(card, 100);
        return;
      }
      publicAutoAdvanceProgressRaf = requestAnimationFrame(tick);
    };
    publicAutoAdvanceProgressRaf = requestAnimationFrame(tick);
  }

  function refreshPublicAutoAdvanceAfterScroll(previousPostId, attempt){
    attempt = Number(attempt || 0);
    var active = currentCard();
    var activeId = Number((active && active.getAttribute('data-post-id')) || 0);
    if(active && activeId && activeId !== Number(previousPostId || 0)){
      refreshPublicAutoAdvance();
      return;
    }
    if(attempt >= 18){
      refreshPublicAutoAdvance();
      return;
    }
    window.setTimeout(function(){
      refreshPublicAutoAdvanceAfterScroll(previousPostId, attempt + 1);
    }, 140);
  }

  function currentCard(){
    var idx = currentIndex();
    var list = cards();
    if(idx < 0 || !list[idx]) return null;
    return list[idx];
  }

  function wordsToMs(text){
    var clean = String(text || '').replace(/\s+/g, ' ').trim();
    if(!clean) return 0;
    var words = clean.split(' ').filter(Boolean).length;
    return Math.max(2200, Math.round((words / 220) * 60000));
  }

  function estimatePublicCardMs(card){
    if(!card) return 4000;

    var bits = [];
    card.querySelectorAll(
      '.standard-text-title, .standard-media-title, .reel-caption-text, .standard-text-caption, .standard-media-caption'
    ).forEach(function(el){
      var txt = String(el.textContent || '').replace(/\b(Read more|See more)\b/gi, '').trim();
      if(txt) bits.push(txt);
    });

    var ms = wordsToMs(bits.join(' '));
    var mediaCount = Number((card.querySelector('.js-media-carousel') || {}).getAttribute ? (card.querySelector('.js-media-carousel').getAttribute('data-count') || 0) : 0);
    if(!mediaCount) mediaCount = card.classList.contains('is-multi-media-post') ? Math.max(card.querySelectorAll('.media-slide').length, 1) : 1;
    ms += Math.max(0, mediaCount - 1) * 1800;

    if(card.classList.contains('is-single-video-post')) ms += 1200;
    if(card.classList.contains('is-reel-post')) ms += 1000;

    return Math.max(2600, Math.min(ms || 4000, 25000));
  }

  function currentPlayableVideo(card){
    if(!card) return null;

    if(card.classList.contains('is-reel-post')){
      return card.querySelector('.js-reel-video');
    }

    if(card.classList.contains('is-single-video-post')){
      return card.querySelector('.media-stage.standard-video-stage > video');
    }

    var stage = card.querySelector('.js-media-carousel');
    if(stage){
      var index = Number(stage.getAttribute('data-index') || 0);
      var slide = stage.querySelector('.media-slide[data-slide-index="' + String(index) + '"]');
      if(slide){
        var slideVideo = slide.querySelector('video');
        if(slideVideo) return slideVideo;
      }
    }

    return null;
  }

  function pauseOtherPublicVideos(activeCard){
    document.querySelectorAll('.public-post-card video').forEach(function(video){
      var owner = video.closest('.public-post-card');
      if(activeCard && owner === activeCard) return;
      try { video.pause(); } catch(err){}
    });
  }

  function playCurrentPublicVideo(card){
    if(!card) return;
    pauseOtherPublicVideos(card);

    var video = currentPlayableVideo(card);
    if(!video) return;

    try {
      video.muted = true;
      video.play().catch(function(){});
    } catch(err){}
  }

  function schedulePublicAutoAdvance(card, delayMs){
    if(!card) return;
    if(document.body.classList.contains('public-leftbar-open')) return;
    if(publicAutoAdvanceHovered) return;

    var postId = Number(card.getAttribute('data-post-id') || 0);
    clearPublicAutoAdvance();
    publicAutoAdvanceCardId = postId;
    publicAutoAdvanceDelay = Math.max(1200, Number(delayMs || 0));
    publicAutoAdvanceRemaining = publicAutoAdvanceDelay;
    publicAutoAdvanceStartedAt = Date.now();
    startPublicAutoAdvanceProgress(card);
    publicAutoAdvanceTimer = window.setTimeout(function(){
      var active = currentCard();
      if(!active) return;
      var activeId = Number(active.getAttribute('data-post-id') || 0);
      if(activeId !== publicAutoAdvanceCardId) return;
      if(document.body.classList.contains('public-leftbar-open')) return;
      if(publicAutoAdvanceHovered) return;
      stopPublicAutoAdvanceProgress(false);
      go(1);
      refreshPublicAutoAdvanceAfterScroll(activeId, 0);
    }, publicAutoAdvanceDelay);
  }

  function bindPublicAutoAdvance(card){
    if(!card) return;
    var video = currentPlayableVideo(card);
    if(video){
      var dur = Number(video.duration || 0);
      if(dur && isFinite(dur) && dur > 0){
        schedulePublicAutoAdvance(card, Math.round(dur * 1000));
        return;
      }

      var fallback = estimatePublicCardMs(card);
      schedulePublicAutoAdvance(card, fallback);

      if(!video.__publicAutoAdvanceBound){
        video.__publicAutoAdvanceBound = true;
        video.addEventListener('loadedmetadata', function(){
          var active = currentCard();
          if(!active || active !== card) return;
          var nextDur = Number(video.duration || 0);
          if(nextDur && isFinite(nextDur) && nextDur > 0){
            schedulePublicAutoAdvance(card, Math.round(nextDur * 1000));
          }
        });
      }
      return;
    }

    schedulePublicAutoAdvance(card, estimatePublicCardMs(card));
  }

  function refreshPublicAutoAdvance(){
    var card = currentCard();
    if(!card) return;
    playCurrentPublicVideo(card);
    var postId = Number(card.getAttribute('data-post-id') || 0);
    if(postId && postId === publicAutoAdvanceCardId && publicAutoAdvanceTimer) return;
    bindPublicAutoAdvance(card);
  }

  function removePublicFriendActionBtn(btn){
    if(!btn || !btn.parentNode) return;
    var peerId = Number(btn.getAttribute('data-peer-id') || 0);
    var wrap = btn.closest('.standard-media-top-actions, .post-card-head-actions, .standard-text-top-actions, .reel-top-right');
    btn.remove();
    if(wrap){
      var hasPeerAction = wrap.querySelector('.friend-btn, .publisher-follow-btn, .mf-media-action-circle, .mf-publisher-follow-circle');
      var hasMenu = wrap.querySelector('.post-card-menu-wrap, .mf-menu-wrap');
      if(!hasPeerAction && !hasMenu){
        wrap.remove();
      } else if(wrap.classList.contains('standard-media-top-actions') && !hasPeerAction){
        wrap.remove();
      }
    }
    if(peerId > 0){
      syncPostCardPeerAttrs(peerId, { 'data-friend-status': 'friends' });
    }
  }

  function applyStatus(btn, status){
    status = String(status || 'none');
    if(status === 'friends'){
      removePublicFriendActionBtn(btn);
      return;
    }
    if(typeof window.msbApplyFriendActionBtnState === 'function' && btn.classList && (btn.classList.contains('mf-media-action-circle') || btn.classList.contains('mf-publisher-follow-circle'))){
      window.msbApplyFriendActionBtnState(btn, status);
      btn.dataset.status = status;
      var card = btn.closest ? btn.closest('.public-post-card') : null;
      if(card) card.setAttribute('data-friend-status', status);
      return;
    }
    btn.classList.remove('primary','is-friends','is-pending','is-accept');

    if(status === 'incoming_pending'){
      if(btn.querySelector('.mf-media-action-label')){
        btn.innerHTML = '<span class="mf-media-action-label">Accept</span>';
      } else {
        btn.textContent = 'Accept';
      }
      btn.classList.add('is-accept');
    } else if(status === 'outgoing_pending'){
      if(btn.querySelector('.mf-media-action-label') || btn.classList.contains('mf-media-action-circle')){
        btn.innerHTML = '<span class="mf-media-action-label">Sent</span>';
        btn.disabled = true;
      } else {
        btn.textContent = 'Sent';
      }
      btn.classList.add('is-pending');
    } else {
      if(btn.classList.contains('sfy-action') || btn.classList.contains('frl-suggest-action')){
        btn.textContent = '+';
        btn.disabled = false;
      } else if(btn.classList.contains('mf-media-action-circle')){
        btn.innerHTML = '<i class="fa fa-plus" aria-hidden="true"></i>';
        btn.disabled = false;
      } else {
        btn.textContent = '+';
      }
      btn.classList.add('primary');
    }

    btn.dataset.status = status;

    var card = btn.closest ? btn.closest('.public-post-card') : null;
    if(card) card.setAttribute('data-friend-status', status);
  }

  function applyStatusForPeer(peerId, status){
    peerId = Number(peerId || 0);
    if(!peerId) return;
    document.querySelectorAll('.friend-btn[data-peer-id="'+String(peerId)+'"]').forEach(function(btn){
      applyStatus(btn, status);
    });
    syncPostCardPeerAttrs(peerId, { 'data-friend-status': String(status || 'none') });
    if(window.MSBPostCardMenu && typeof window.MSBPostCardMenu.syncPublisherCards === 'function'){
      /* friend status only */
    }
  }

  function applyFollowForPublisher(publisherId, following){
    publisherId = Number(publisherId || 0);
    if(!publisherId) return;
    var on = !!following;
    document.querySelectorAll('.pub-follow-btn[data-publisher-id="'+String(publisherId)+'"], .publisher-follow-btn[data-publisher-id="'+String(publisherId)+'"]').forEach(function(el){
      if(typeof window.msbApplyPublisherFollowBtnState === 'function'){
        window.msbApplyPublisherFollowBtnState(el, on);
        return;
      }
      el.classList.toggle('is-following', on);
      el.classList.toggle('primary', !on);
      el.textContent = on ? 'Following' : 'Follow';
    });
    syncPostCardPeerAttrs(publisherId, {
      'data-is-following': on ? '1' : '0',
      'data-account-kind': 'publisher',
      'data-is-publisher': '1'
    });
    if(window.MSBPostCardMenu && typeof window.MSBPostCardMenu.syncPublisherCards === 'function'){
      window.MSBPostCardMenu.syncPublisherCards(publisherId, on);
    }
  }

  $(document).on('click', '.publisher-follow-btn', function(){
    var btn = this;
    var id = btn.getAttribute('data-publisher-id') || '';
    if(!id) return;
    var fd = new FormData();
    fd.append('target_id', id);
    fetch('publisher_follow_toggle.php', { method:'POST', body: fd, cache:'no-store' })
      .then(function(r){ return r.json(); })
      .then(function(res){
        if(!res || !res.ok) return;
        applyFollowForPublisher(id, !!res.following);
      });
  });

  $(document).on('click', '.friend-btn', function(){
    var $btn = $(this), peerId = Number($btn.data('peer-id') || 0), status = String($btn.data('status') || '');
    if(!peerId) return;

    if(status === 'incoming_pending') {
      window.location.href = 'contact_requests.php';
      return;
    }
    if(status === 'outgoing_pending') {
      window.location.href = 'contact_requests.php';
      return;
    }

    $btn.prop('disabled', true);
    $.post('ajax/friend_action.php', { action:'send', peer_id: peerId }, function(res){
      if(res && res.status){ applyStatusForPeer(peerId, String(res.status)); }
      $btn.prop('disabled', false);
    }, 'json').fail(function(){
      $btn.prop('disabled', false);
    });
  });

  $('.friend-btn').each(function(){
    var btn = this, peerId = Number(btn.getAttribute('data-peer-id') || '0');
    if(!peerId) return;
    $.getJSON('ajax/friend_status.php', { peer_id: peerId }, function(res){
      if(res && res.status) applyStatus(btn, String(res.status));
    });
  });

  var COMMENT_API_URL = 'feed_api.php';
  var publicCommentsCache = {};
  var publicAlertPostId = <?php echo (int)$publicAlertPostId; ?>;
  var publicAlertCommentId = <?php echo (int)$publicAlertCommentId; ?>;

  function clearPublicAlertParams(){
    try{
      var nextUrl = new URL(window.location.href);
      nextUrl.searchParams.delete('open_post');
      nextUrl.searchParams.delete('open_comment');
      history.replaceState({}, document.title, nextUrl.pathname + nextUrl.search + nextUrl.hash);
    }catch(err){}
  }

  function highlightPublicCard(postId){
    postId = Number(postId || 0);
    if(!postId) return false;
    document.querySelectorAll('.public-post-card.is-alert-focus').forEach(function(node){
      node.classList.remove('is-alert-focus');
    });
    var card = document.querySelector('.public-post-card[data-post-id="' + String(postId) + '"]');
    if(!card) return false;
    card.classList.add('is-alert-focus');
    try{ card.scrollIntoView({ behavior:'smooth', block:'center' }); }catch(err){}
    return true;
  }

  function syncPublicActionIcon(btn, activeClass){
    if(!btn) return;
    var pact = btn.querySelector('.msb-pact');
    if(pact){
      pact.classList.toggle('is-active', btn.classList.contains(activeClass));
      return;
    }
    var icon = btn.querySelector('i');
    if(!icon) return;
    icon.classList.toggle('is-active', btn.classList.contains(activeClass));
  }

  function publicCardReaction(card){
    return String((card && card.getAttribute('data-my-reaction')) || '');
  }

  function syncPublicReactionButtons(card){
    if(!card || !window.MSBReactions) return;
    var my = publicCardReaction(card);
    card.querySelectorAll('.js-react-like').forEach(function(btn){
      window.MSBReactions.applyLikeButton(btn, my === 'like' ? my : '');
    });
    card.querySelectorAll('.js-react-love').forEach(function(btn){
      window.MSBReactions.applyReactionButton(btn, my !== 'like' ? my : '', 'love');
    });
  }

  function syncPublicCardIcons(card){
    if(!card) return;
    syncPublicReactionButtons(card);
    card.querySelectorAll('.js-share-post').forEach(function(btn){
      syncPublicActionIcon(btn, 'is-share');
    });
    card.querySelectorAll('.js-save-post').forEach(function(btn){
      syncPublicActionIcon(btn, 'is-save');
    });
  }

  function updatePublicCommentCount(postId, count){
    var n = Number(count || 0);
    var card = document.querySelector('.public-post-card[data-post-id="' + String(postId) + '"]');
    if(!card) return;
    card.setAttribute('data-comment-count', String(n));
    card.querySelectorAll('.js-comment-count').forEach(function(el){ el.textContent = String(n); });
    card.querySelectorAll('.js-comment-count-inline').forEach(function(el){ el.textContent = String(n); });
  }

  function updatePublicReactionState(postId, counts){
    postId = Number(postId || 0);
    var card = document.querySelector('.public-post-card[data-post-id="' + String(postId) + '"]');
    if(!card || !counts) return;

    var likeCount = Number(counts.like_count || 0);
    var loveCount = Number(counts.love_count || 0);
    var my = String(counts.my_reaction || '');

    card.setAttribute('data-like-count', String(likeCount));
    card.setAttribute('data-love-count', String(loveCount));
    card.setAttribute('data-my-reaction', my);

    card.querySelectorAll('.js-like-count').forEach(function(el){ el.textContent = String(likeCount); });
    card.querySelectorAll('.js-love-count').forEach(function(el){ el.textContent = String(loveCount); });
    card.querySelectorAll('.js-like-total').forEach(function(el){ el.textContent = String(likeCount + loveCount); });

    card.querySelectorAll('.js-react-like').forEach(function(btn){
      btn.classList.toggle('is-like', my === 'like');
    });
    card.querySelectorAll('.js-react-love').forEach(function(btn){
      btn.classList.toggle('is-love', my !== '' && my !== 'like');
    });

    syncPublicCardIcons(card);
  }

  function updatePublicTrackState(postId, res){
    postId = Number(postId || 0);
    var card = document.querySelector('.public-post-card[data-post-id="' + String(postId) + '"]');
    if(!card || !res) return;

    var shareCount = Number(res.share_count || 0);
    var saveCount = Number(res.save_count || 0);
    var state = res.state || {};

    card.querySelectorAll('.js-share-count').forEach(function(el){ el.textContent = String(shareCount); });
    card.querySelectorAll('.js-save-count').forEach(function(el){ el.textContent = String(saveCount); });

    card.querySelectorAll('.js-share-post').forEach(function(btn){
      btn.classList.toggle('is-share', Number(state.shared || 0) === 1);
    });
    card.querySelectorAll('.js-save-post').forEach(function(btn){
      btn.classList.toggle('is-save', Number(state.saved || 0) === 1);
    });

    syncPublicCardIcons(card);
  }

  function fetchPublicPostComments(postId, forceOpen){
    postId = Number(postId || 0);
    if(!postId) return;

    if(window.TTComments && publicCommentsCache[postId]){
      window.TTComments.setPost(postId, publicCommentsCache[postId], forceOpen !== false);
      return;
    }

    if(window.TTComments && typeof window.TTComments.setPost === 'function'){
      window.TTComments.setPost(postId, [], forceOpen !== false);
      var list = document.getElementById('ttCommentsList');
      if(list) list.innerHTML = '<div class="text-muted" style="padding:10px 6px;">Loading comments...</div>';
    }

    $.getJSON(COMMENT_API_URL, { ajax:'view', id: postId }, function(res){
      if(!(res && res.ok)){
        var list = document.getElementById('ttCommentsList');
        if(list) list.innerHTML = '<div class="text-danger" style="padding:10px 6px;">Unable to load comments.</div>';
        return;
      }
      var comments = Array.isArray(res.comments) ? res.comments : [];
      publicCommentsCache[postId] = comments;
      updatePublicCommentCount(postId, comments.length);
      if(window.TTComments && typeof window.TTComments.setPost === 'function'){
        window.TTComments.setPost(postId, comments, forceOpen !== false);
      }
    }).fail(function(){
      var list = document.getElementById('ttCommentsList');
      if(list) list.innerHTML = '<div class="text-danger" style="padding:10px 6px;">Unable to load comments.</div>';
    });
  }

  $(document).on('click', '.js-open-comments', function(e){
    e.preventDefault();
    e.stopPropagation();
    if(window.TTComments && typeof window.TTComments.clearFocusComment === 'function'){
      window.TTComments.clearFocusComment();
    }
    var card = this.closest('.public-post-card');
    var postId = Number((this.getAttribute('data-post-id')) || (card && card.getAttribute('data-post-id')) || 0);
    if(!postId) return;
    if(window.TTComments && typeof window.TTComments.isOpen === 'function' && window.TTComments.isOpen() && window.TTComments.getPostId() === postId){
      window.TTComments.toggle(postId, publicCommentsCache[postId] || []);
      return;
    }
    document.body.classList.add('public-leftbar-open');
    if(publicAutoAdvanceTimer){
      publicAutoAdvanceRemaining = Math.max(0, publicAutoAdvanceDelay - (Date.now() - publicAutoAdvanceStartedAt));
      window.clearTimeout(publicAutoAdvanceTimer);
      publicAutoAdvanceTimer = null;
      stopPublicAutoAdvanceProgress(true);
    }
    fetchPublicPostComments(postId, true);
  });

  $(document).on('click', '.js-open-readmore', function(e){
    e.preventDefault();
    e.stopPropagation();
    if(!(window.TTReadMore && typeof window.TTReadMore.toggle === 'function')) return;

    if(publicAutoAdvanceTimer){
      publicAutoAdvanceRemaining = Math.max(0, publicAutoAdvanceDelay - (Date.now() - publicAutoAdvanceStartedAt));
      window.clearTimeout(publicAutoAdvanceTimer);
      publicAutoAdvanceTimer = null;
      stopPublicAutoAdvanceProgress(true);
    }
    var opened = window.TTReadMore.toggle({
      title: String(this.getAttribute('data-title') || ''),
      author: String(this.getAttribute('data-author') || ''),
      date: String(this.getAttribute('data-date') || ''),
      avatarText: String(this.getAttribute('data-avatar') || 'P'),
      avatarUrl: String(this.getAttribute('data-avatar-url') || ''),
      body: (window.TTRichText && typeof window.TTRichText.normalizePlain === 'function')
        ? window.TTRichText.normalizePlain(String(this.getAttribute('data-body') || ''))
        : String(this.getAttribute('data-body') || '')
    });
    if(opened) document.body.classList.add('public-leftbar-open');
  });

  // Close leftbar comments/read-more/menu door when clicking outside it.
  $(document).on('click', function(e){
    var target = e.target;
    if(!target || !target.closest) return;

    var menuWrap = document.getElementById('tt-menu-wrap');
    var commentsWrap = document.getElementById('tt-comments-wrap');
    var readWrap = document.getElementById('tt-readmore-wrap');
    var profileWrap = document.getElementById('tt-profile-wrap');
    var storiesWrap = document.getElementById('tt-stories-wrap');
    var menuOpen = !!(menuWrap && menuWrap.classList.contains('is-open'));
    var commentsOpen = !!(commentsWrap && commentsWrap.classList.contains('is-open'));
    var readOpen = !!(readWrap && readWrap.classList.contains('is-open'));
    var profileOpen = !!(profileWrap && profileWrap.classList.contains('is-open'));
    var storiesOpen = !!(storiesWrap && storiesWrap.classList.contains('is-open'));
    if(!menuOpen && !commentsOpen && !readOpen && !profileOpen && !storiesOpen) return;

    if(target.closest('#tt-menu-wrap, #tt-comments-wrap, #tt-readmore-wrap, #tt-profile-wrap, #tt-stories-wrap, #tt-live-right-wrap, #ttMenuClose, #ttCommentsClose, #ttRmClose, #ttProfileClose, #ttStoriesClose, .tt-story-cmt-sheet, .tt-story-cmt-panel, .tt-story-cmt-backdrop')) return;
    if(target.closest('.js-open-menu-door, .ig-story-item, .ig-story-empty, .js-open-comments, .js-open-readmore, .js-open-profile-door, .js-open-messages-door, .js-open-notifications-door, .js-open-friend-requests-door, .js-open-live-door, .feed-ig-avatar')) return;

    if(menuOpen){
      if(window.TTMenu && typeof window.TTMenu.close === 'function') window.TTMenu.close();
      else if(menuWrap) menuWrap.classList.remove('is-open');
    }
    if(commentsOpen){
      if(window.TTComments && typeof window.TTComments.close === 'function') window.TTComments.close();
      else if(commentsWrap) commentsWrap.classList.remove('is-open');
    }
    if(readOpen){
      if(window.TTReadMore && typeof window.TTReadMore.close === 'function') window.TTReadMore.close();
      else if(readWrap) readWrap.classList.remove('is-open');
    }
    if(profileOpen){
      if(window.TTProfile && typeof window.TTProfile.close === 'function') window.TTProfile.close();
      else if(profileWrap) profileWrap.classList.remove('is-open');
    }
    if(storiesWrap && storiesWrap.classList.contains('is-open')){
      if(window.TTStories && typeof window.TTStories.close === 'function') window.TTStories.close();
      else storiesWrap.classList.remove('is-open');
    }
    var liveRightWrap = document.getElementById('tt-live-right-wrap');
    if(liveRightWrap && liveRightWrap.classList.contains('is-open')){
      if(window.TTLiveRight && typeof window.TTLiveRight.close === 'function') window.TTLiveRight.close();
      else liveRightWrap.classList.remove('is-open');
    }
  });

  $(document).on('click', '.ig-story-item[data-story-key]', function(e){
    e.preventDefault();
    e.stopPropagation();
    if($(this).hasClass('ig-story-empty') || $(this).hasClass('ig-story-create')) return;
    var key = String($(this).attr('data-story-key') || '');
    if(!key || !window.TTStories) return;
    window.TTStories.openByKey(key);
  });

  function setPublicCarouselIndex(stage, nextIndex){
    if(!stage) return;
    var track = stage.querySelector('.media-slides');
    if(!track) return;

    var slides = Array.prototype.slice.call(stage.querySelectorAll('.media-slide'));
    var dots = Array.prototype.slice.call(stage.querySelectorAll('.media-dot'));
    var count = slides.length;
    if(!count) return;

    nextIndex = Number(nextIndex || 0);
    if(nextIndex < 0) nextIndex = count - 1;
    if(nextIndex >= count) nextIndex = 0;

    stage.setAttribute('data-index', String(nextIndex));
    track.style.transform = 'translateX(' + (-100 * nextIndex) + '%)';

    dots.forEach(function(dot, dotIndex){
      dot.classList.toggle('is-active', dotIndex === nextIndex);
    });

    slides.forEach(function(slide, slideIndex){
      var videos = slide.querySelectorAll('video');
      videos.forEach(function(video){
        if(slideIndex !== nextIndex){
          try { video.pause(); } catch(err){}
        } else {
          try {
            video.muted = true;
            video.play().catch(function(){});
          } catch(err){}
        }
      });
    });
  }

  function initPublicMediaCarousels(scope){
    (scope || document).querySelectorAll('.js-media-carousel').forEach(function(stage){
      if(stage.getAttribute('data-carousel-ready') === '1') return;
      stage.setAttribute('data-carousel-ready', '1');
      setPublicCarouselIndex(stage, Number(stage.getAttribute('data-index') || 0));
    });
  }

  $(document).on('click', '.js-media-prev, .js-media-next, .media-dot', function(e){
    e.preventDefault();
    e.stopPropagation();
    var stage = this.closest('.js-media-carousel');
    if(!stage) return;
    var current = Number(stage.getAttribute('data-index') || 0);

    if(this.classList.contains('js-media-prev')){
      setPublicCarouselIndex(stage, current - 1);
      return;
    }
    if(this.classList.contains('js-media-next')){
      setPublicCarouselIndex(stage, current + 1);
      var stageCard = stage.closest('.public-post-card');
      if(stageCard) bindPublicAutoAdvance(stageCard);
      return;
    }
    if(this.classList.contains('media-dot')){
      setPublicCarouselIndex(stage, Number(this.getAttribute('data-index') || 0));
      var dotCard = stage.closest('.public-post-card');
      if(dotCard) bindPublicAutoAdvance(dotCard);
    }
  });

  initPublicMediaCarousels(document);

  function copyPublicShareLink(postId){
    var url = (window.location.origin || '') + '/public.php?post=' + encodeURIComponent(String(postId));
    try {
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(url).catch(function(){});
      }
    } catch(err){}
  }

  $(document).on('click', '.js-react-love', function(e){
    e.preventDefault();
    e.stopPropagation();
    var btn = this;
    var postId = Number(btn.getAttribute('data-post-id') || 0);
    if(!postId || btn.disabled) return;
    var card = btn.closest('.public-post-card');
    var currentReaction = String((card && card.getAttribute('data-my-reaction')) || '');
    var nextReaction = currentReaction === 'love' ? 'none' : 'love';
    btn.disabled = true;
    $.post(COMMENT_API_URL + '?ajax=react', { post_id: postId, reaction: nextReaction }, function(res){
      if(res && res.ok) updatePublicReactionState(postId, res.counts || {});
      btn.disabled = false;
    }, 'json').fail(function(){
      btn.disabled = false;
    });
  });

  $(document).on('click', '.js-react-like', function(e){
    e.preventDefault();
    e.stopPropagation();
    var btn = this;
    var postId = Number(btn.getAttribute('data-post-id') || 0);
    if(!postId || btn.disabled) return;
    var card = btn.closest('.public-post-card');
    var currentReaction = String((card && card.getAttribute('data-my-reaction')) || '');
    var nextReaction = currentReaction === 'like' ? 'none' : 'like';
    btn.disabled = true;
    $.post(COMMENT_API_URL + '?ajax=react', { post_id: postId, reaction: nextReaction }, function(res){
      if(res && res.ok) updatePublicReactionState(postId, res.counts || {});
      btn.disabled = false;
    }, 'json').fail(function(){
      btn.disabled = false;
    });
  });

  if(window.MSBReactions){
    window.MSBReactions.bindLikePicker('.js-react-love', function(btn, reaction){
      var postId = Number(btn.getAttribute('data-post-id') || 0);
      if(!postId || btn.disabled) return;
      var card = btn.closest('.public-post-card');
      if(String((card && card.getAttribute('data-my-reaction')) || '') === String(reaction || '')) return;
      btn.disabled = true;
      $.post(COMMENT_API_URL + '?ajax=react', { post_id: postId, reaction: reaction }, function(res){
        if(res && res.ok) updatePublicReactionState(postId, res.counts || {});
        btn.disabled = false;
      }, 'json').fail(function(){
        btn.disabled = false;
      });
    });
  }

  $(document).on('click', '.js-share-post', function(e){
    e.preventDefault();
    e.stopPropagation();
    var btn = this;
    var postId = Number(btn.getAttribute('data-post-id') || 0);
    if(!postId || btn.disabled) return;
    btn.disabled = true;
    copyPublicShareLink(postId);
    $.post(COMMENT_API_URL + '?ajax=share', { post_id: postId }, function(res){
      if(res && res.ok) updatePublicTrackState(postId, res);
      btn.disabled = false;
    }, 'json').fail(function(){
      btn.disabled = false;
    });
  });

  $(document).on('click', '.js-save-post', function(e){
    e.preventDefault();
    e.stopPropagation();
    var btn = this;
    var postId = Number(btn.getAttribute('data-post-id') || 0);
    if(!postId || btn.disabled) return;
    btn.disabled = true;
    $.post(COMMENT_API_URL + '?ajax=save', { post_id: postId }, function(res){
      if(res && res.ok) updatePublicTrackState(postId, res);
      btn.disabled = false;
    }, 'json').fail(function(){
      btn.disabled = false;
    });
  });

  document.querySelectorAll('.public-post-card').forEach(function(card){
    syncPublicCardIcons(card);
  });

  window.__publicRefreshComments = function(postId){
    postId = Number(postId || 0);
    if(!postId) return;
    $.getJSON(COMMENT_API_URL, { ajax:'view', id: postId }, function(res){
      if(!(res && res.ok)) return;
      var comments = Array.isArray(res.comments) ? res.comments : [];
      publicCommentsCache[postId] = comments;
      updatePublicCommentCount(postId, comments.length);
      try{
        if(window.TTComments && typeof window.TTComments.setPost === 'function'){
          window.TTComments.setPost(postId, comments, false);
        }
      }catch(err){}
    });
  };
  window.TTComments = window.TTComments || {};
  window.TTComments.refreshCurrent = function(){
    var pid = Number($('#ttPostId').val() || 0);
    if(pid) window.__publicRefreshComments(pid);
  };

  document.addEventListener('click', function(e){
    var closeBtn = e.target && e.target.closest ? e.target.closest('#ttCommentsClose, #ttRmClose') : null;
    if(closeBtn){
      document.body.classList.remove('public-leftbar-open');
      if(!publicAutoAdvanceHovered){
        setTimeout(refreshPublicAutoAdvance, 40);
      }
    }
  });

  var STAFF_READONLY = <?= $staffReadonly ? 'true' : 'false' ?>;

  function syncPostCardPeerAttrs(peerId, patch){
    peerId = Number(peerId || 0);
    if(!peerId || !patch) return;
    document.querySelectorAll('.post.public-post-card[data-peer-id="'+String(peerId)+'"]').forEach(function(card){
      Object.keys(patch).forEach(function(key){
        card.setAttribute(key, String(patch[key]));
      });
      if(window.MSBPostCardMenu && typeof window.MSBPostCardMenu.syncCardPublisher === 'function' && patch['data-is-following'] != null){
        var isPub = String(card.getAttribute('data-is-publisher') || '') === '1' || String(card.getAttribute('data-account-kind') || '') === 'publisher';
        if(isPub){
          window.MSBPostCardMenu.syncCardPublisher($(card), patch['data-is-following'] === '1');
          return;
        }
      }
      if(window.MSBPostCardMenu && typeof window.MSBPostCardMenu.buildItems === 'function' && (
        patch['data-friend-status'] != null || patch['data-is-following'] != null
      )){
        var pid = Number(card.getAttribute('data-post-id') || 0);
        var isOwner = String(card.getAttribute('data-post-owner') || '') === '1';
        var it = {
          user_id: peerId,
          friend_code: String(card.getAttribute('data-peer-code') || ''),
          account_kind: String(card.getAttribute('data-account-kind') || 'personal'),
          is_following: Number(card.getAttribute('data-is-following') || 0),
          friend_status: String(card.getAttribute('data-friend-status') || 'none'),
          is_publisher: Number(card.getAttribute('data-is-publisher') || 0),
          contact_id: Number(card.getAttribute('data-contact-id') || 0),
          contact_name: String(card.getAttribute('data-contact-name') || '')
        };
        var html = window.MSBPostCardMenu.buildItems(it, isOwner, pid, {});
        var menu = card.querySelector('.mf-menu.post-card-menu, .post-card-menu');
        var wrap = card.querySelector('.post-card-menu-wrap, .mf-menu-wrap.post-card-menu-wrap');
        if(menu) menu.innerHTML = html || '';
        if(wrap) wrap.style.display = html ? '' : 'none';
      }
    });
  }

  window.msbSyncContactDisplayName = function(contactId, displayName){
    contactId = Number(contactId || 0);
    displayName = String(displayName || '');
    if(!contactId) return;
    document.querySelectorAll('.post.public-post-card[data-contact-id="'+String(contactId)+'"]').forEach(function(card){
      card.setAttribute('data-contact-name', displayName);
    });
  };

  var confirmDeleteBtn = document.getElementById('confirmDeleteBtn');
  if(confirmDeleteBtn){
    confirmDeleteBtn.addEventListener('click', function(){
      var input = document.getElementById('deletePostId');
      var form = document.getElementById('deletePostForm');
      var postId = input ? Number(input.value || 0) : 0;
      if(!postId || !form) return;
      form.submit();
    });
  }

  if(window.TTComments){
    try{
      var originalClose = window.TTComments.close;
      window.TTComments.close = function(){
        document.body.classList.remove('public-leftbar-open');
        if(typeof originalClose === 'function') originalClose();
        if(!publicAutoAdvanceHovered){
          setTimeout(refreshPublicAutoAdvance, 40);
        }
      };
    }catch(err){}
  }

  if(window.TTReadMore){
    try{
      var originalRmClose = window.TTReadMore.close;
      window.TTReadMore.close = function(){
        document.body.classList.remove('public-leftbar-open');
        if(typeof originalRmClose === 'function') originalRmClose();
        if(!publicAutoAdvanceHovered){
          setTimeout(refreshPublicAutoAdvance, 40);
        }
      };
    }catch(err){}
  }

  if(window.TTProfile){
    try{
      var originalProfileClose = window.TTProfile.close;
      window.TTProfile.close = function(){
        document.body.classList.remove('public-leftbar-open');
        if(typeof originalProfileClose === 'function') originalProfileClose();
        if(!publicAutoAdvanceHovered){
          setTimeout(refreshPublicAutoAdvance, 40);
        }
      };
    }catch(err){}
  }

  $(document).on('submit', '#ttCommentForm', function(){
    var pid = Number($('#ttPostId').val() || 0);
    if(!pid) return;
    setTimeout(function(){
      window.__publicRefreshComments(pid);
    }, 260);
  });

  (function(){
    if(!publicAlertPostId) return;
    setTimeout(function(){
      highlightPublicCard(publicAlertPostId);
      if(publicAlertCommentId > 0){
        if(window.TTComments && typeof window.TTComments.setFocusComment === 'function'){
          window.TTComments.setFocusComment(publicAlertCommentId);
        }
        document.body.classList.add('public-leftbar-open');
        fetchPublicPostComments(publicAlertPostId, true);
      }
      clearPublicAlertParams();
    }, 180);
  })();

  function syncReelButtons(video){
    if(!video) return;
    var reel = video.closest('.reel-stage');
    if(!reel) return;
    var playBtn = reel.querySelector('.js-reel-toggle-play');
    var muteBtn = reel.querySelector('.js-reel-toggle-mute');

    if(playBtn){
      var playIcon = playBtn.querySelector('i');
      if(playIcon){
        playIcon.className = video.paused ? 'fa fa-play' : 'fa fa-pause';
      }
      playBtn.setAttribute('aria-label', video.paused ? 'Play reel' : 'Pause reel');
    }

    if(muteBtn){
      var muteIcon = muteBtn.querySelector('i');
      if(muteIcon){
        muteIcon.className = video.muted ? 'fa fa-volume-off' : 'fa fa-volume-up';
      }
      muteBtn.setAttribute('aria-label', video.muted ? 'Unmute reel' : 'Mute reel');
    }
  }

  $(document).on('click', '.js-reel-toggle-play', function(e){
    e.preventDefault();
    e.stopPropagation();
    var reel = this.closest('.reel-stage');
    if(!reel) return;
    var video = reel.querySelector('.js-reel-video');
    if(!video) return;

    if(video.paused){
      video.play().catch(function(){});
    } else {
      video.pause();
    }
    syncReelButtons(video);
  });

  $(document).on('click', '.js-reel-toggle-mute', function(e){
    e.preventDefault();
    e.stopPropagation();
    var reel = this.closest('.reel-stage');
    if(!reel) return;
    var video = reel.querySelector('.js-reel-video');
    if(!video) return;

    video.muted = !video.muted;
    syncReelButtons(video);
  });

  document.querySelectorAll('.js-reel-video').forEach(function(video){
    syncReelButtons(video);
    video.addEventListener('play', function(){ syncReelButtons(video); });
    video.addEventListener('pause', function(){ syncReelButtons(video); });
    video.addEventListener('volumechange', function(){ syncReelButtons(video); });
    video.addEventListener('loadedmetadata', function(){
      var card = video.closest('.public-post-card');
      if(card && card === currentCard()) bindPublicAutoAdvance(card);
    });
  });

  function parseDeviceAspectFromStyle(style){
    style = String(style || '');
    var mw = style.match(/--device-ar-w:\s*(\d+)/);
    var mh = style.match(/--device-ar-h:\s*(\d+)/);
    if(!mw || !mh) return null;
    return { w: Number(mw[1] || 0), h: Number(mh[1] || 0) };
  }

  function applyPublicVideoCardWidth(card, aspectW, aspectH){
    if(!card) return;
    aspectW = Number(aspectW || 0);
    aspectH = Number(aspectH || 0);
    if(!aspectW || !aspectH) return;

    var viewportH = Math.max(window.innerHeight || 0, 320);
    var maxVideoH = window.matchMedia('(max-width: 767.98px)').matches
      ? Math.max(viewportH - 210, 300)
      : Math.min(Math.round(viewportH * 0.78), 960);

    var aspect = aspectW / aspectH;
    var feed = card.closest('.ig-feed');
    var feedWidth = feed ? Math.floor(feed.clientWidth) : Math.round(aspect * maxVideoH);
    var cardPadding = window.matchMedia('(max-width: 767.98px)').matches ? 0 : 0;
    var availableWidth = Math.max(280, feedWidth - cardPadding);
    var desiredWidth = Math.round(aspect * maxVideoH);
    var maxByShape = aspect < 0.8 ? 520 : (aspect > 1.15 ? 760 : 620);
    if(card.querySelector('.media-stage.phone-shot') && window.matchMedia('(max-width: 767.98px)').matches) maxByShape = 430;
    var safeWidth = Math.max(280, Math.min(desiredWidth, availableWidth, maxByShape));
    if(aspect >= 0.8 && aspect <= 1.15) safeWidth = Math.min(availableWidth, Math.max(safeWidth, 420));
    if(aspect > 1.15) safeWidth = Math.min(availableWidth, Math.max(safeWidth, 560));

    card.style.width = String(safeWidth) + 'px';
    card.style.maxWidth = '100%';
    card.style.marginLeft = 'auto';
    card.style.marginRight = 'auto';
    card.style.setProperty('--post-media-card-width', String(safeWidth) + 'px');
  }

  function preflightSingleMediaCard(card){
    if(!card) return;
    var media = card.querySelector('.media-stage.standard-video-stage, .media-stage.standard-image-stage');
    if(!media) return;
    var dims = parseDeviceAspectFromStyle(media.getAttribute('style') || '');
    if(!dims || !dims.w || !dims.h) return;
    applyPublicVideoCardWidth(card, dims.w, dims.h);
  }

  function preflightAllSingleMediaCards(){
    document.querySelectorAll('.is-single-video-post, .is-single-image-post').forEach(function(card){
      preflightSingleMediaCard(card);
    });
  }

  function markPublicMediaReady(card, stage){
    if(stage) stage.classList.add('mf-media-sized');
    if(!card) return;
    if(card.classList.contains('is-single-video-post')) card.classList.add('mf-video-ready');
    if(card.classList.contains('is-single-image-post')) card.classList.add('mf-image-ready');
  }

  function syncStandardMediaCard(el){
    if(!el) return;
    var card = el.closest('.is-single-video-post, .is-single-image-post');
    if(!card) return;

    var w = 0;
    var h = 0;
    if(String(el.tagName || '').toUpperCase() === 'VIDEO'){
      w = Number(el.videoWidth || 0);
      h = Number(el.videoHeight || 0);
    } else {
      w = Number(el.naturalWidth || 0);
      h = Number(el.naturalHeight || 0);
    }
    if(!w || !h) return;

    applyPublicVideoCardWidth(card, w, h);
  }

  function revealPublicVideoCard(video){
    if(!video) return;
    if(Number(video.readyState || 0) < 2) return;
    var card = video.closest('.is-single-video-post');
    var stage = video.closest('.media-stage.standard-video-stage');
    syncStandardMediaCard(video);
    markPublicMediaReady(card, stage);
  }

  function revealPublicImageCard(img){
    if(!img) return;
    if(!img.complete || !Number(img.naturalWidth || 0)) return;
    var card = img.closest('.is-single-image-post');
    var stage = img.closest('.media-stage.standard-image-stage');
    syncStandardMediaCard(img);
    markPublicMediaReady(card, stage);
  }

  function syncStandardVideoCard(video){
    syncStandardMediaCard(video);
  }

  function syncStandardImageCard(img){
    syncStandardMediaCard(img);
  }

  function syncAllStandardMediaCards(){
    document.querySelectorAll('.is-single-video-post .media-stage.standard-video-stage > video').forEach(function(video){
      syncStandardVideoCard(video);
    });
    document.querySelectorAll('.is-single-image-post .media-stage.standard-image-stage > img').forEach(function(img){
      syncStandardImageCard(img);
    });
  }

  function syncAllStandardVideoCards(){
    syncAllStandardMediaCards();
  }

  function primePublicStandardVideos(){
    document.querySelectorAll('.is-single-video-post .media-stage.standard-video-stage > video').forEach(function(video){
      var stage = video.closest('.media-stage.standard-video-stage');
      var card = video.closest('.is-single-video-post');
      var reveal = function(){ revealPublicVideoCard(video); };
      if(video.readyState >= 2){
        reveal();
        return;
      }
      try{
        if(video.getAttribute('preload') !== 'metadata'){
          video.setAttribute('preload', 'metadata');
        }
        video.load();
      }catch(e){}
      video.addEventListener('loadeddata', reveal, { once:true });
      video.addEventListener('canplay', reveal, { once:true });
      video.addEventListener('error', function(){
        markPublicMediaReady(card, stage);
      }, { once:true });
    });
  }

  function bindPublicStandardImages(){
    document.querySelectorAll('.is-single-image-post .media-stage.standard-image-stage > img').forEach(function(img){
      var card = img.closest('.is-single-image-post');
      var stage = img.closest('.media-stage.standard-image-stage');
      if(img.complete && img.naturalWidth){
        revealPublicImageCard(img);
      }
      img.addEventListener('load', function(){ revealPublicImageCard(img); });
      img.addEventListener('error', function(){
        markPublicMediaReady(card, stage);
      }, { once:true });
    });
  }

  function resetPublicMediaReadyState(){
    document.querySelectorAll('.is-single-video-post, .is-single-image-post').forEach(function(card){
      card.classList.remove('mf-video-ready', 'mf-image-ready');
      var stage = card.querySelector('.media-stage.standard-video-stage, .media-stage.standard-image-stage');
      if(stage) stage.classList.remove('mf-media-sized');
    });
  }

  function bootPublicMediaCards(){
    resetPublicMediaReadyState();
    preflightAllSingleMediaCards();
    primePublicStandardVideos();
    bindPublicStandardImages();
    syncAllStandardMediaCards();
  }

  document.querySelectorAll('.is-single-video-post .media-stage.standard-video-stage > video').forEach(function(video){
    video.addEventListener('loadedmetadata', function(){
      syncStandardVideoCard(video);
      var card = video.closest('.public-post-card');
      if(card && card === currentCard()) bindPublicAutoAdvance(card);
    });
    video.addEventListener('loadeddata', function(){ revealPublicVideoCard(video); });
    video.addEventListener('canplay', function(){ revealPublicVideoCard(video); });
  });

  function debouncePublic(fn, wait){
    var timer = null;
    return function(){
      var ctx = this;
      var args = arguments;
      if(timer) clearTimeout(timer);
      timer = setTimeout(function(){
        timer = null;
        fn.apply(ctx, args);
      }, wait);
    };
  }

  bootPublicMediaCards();
  window.addEventListener('pageshow', function(ev){
    if(!ev.persisted) return;
    bootPublicMediaCards();
  });
  window.addEventListener('resize', debouncePublic(function(){
    preflightAllSingleMediaCards();
    syncAllStandardMediaCards();
  }, 150));

  window.addEventListener('resize', function(){
    if(publicAutoAdvanceScrollTick){
      window.clearTimeout(publicAutoAdvanceScrollTick);
    }
    publicAutoAdvanceScrollTick = window.setTimeout(function(){
      publicAutoAdvanceScrollTick = null;
      refreshPublicAutoAdvance();
    }, 120);
  });

  document.addEventListener('scroll', function(){
    if(publicAutoAdvanceScrollTick){
      window.clearTimeout(publicAutoAdvanceScrollTick);
    }
    publicAutoAdvanceScrollTick = window.setTimeout(function(){
      publicAutoAdvanceScrollTick = null;
      refreshPublicAutoAdvance();
    }, 140);
  }, { passive:true });

  document.addEventListener('visibilitychange', function(){
    if(document.hidden){
      if(publicAutoAdvanceTimer){
        publicAutoAdvanceRemaining = Math.max(0, publicAutoAdvanceDelay - (Date.now() - publicAutoAdvanceStartedAt));
        window.clearTimeout(publicAutoAdvanceTimer);
        publicAutoAdvanceTimer = null;
      }
      stopPublicAutoAdvanceProgress(true);
      document.querySelectorAll('.public-post-card video').forEach(function(video){
        try{ video.pause(); }catch(err){}
      });
      return;
    }
    setTimeout(refreshPublicAutoAdvance, 80);
  });

  refreshPublicAutoAdvance();
})();
</script>
<script>
(function(){
  window.ME_ID = <?= (int)$meId ?>;
  var catalog = <?php echo json_encode($publicStoryCatalog, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

  function escStory(s){
    return String(s || '').replace(/[&<>"']/g, function(m){
      return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]);
    });
  }

  function mfStoriesEmptyHtml(){
    return ''
      + '<div class="ig-story-item ig-story-empty" role="status" aria-label="No stories available">'
      + '  <div class="ig-story-ring ig-story-ring-empty">'
      + '    <span class="ig-story-empty-icon" aria-hidden="true"><i class="icon ion-ios-book-outline"></i></span>'
      + '  </div>'
      + '  <span class="ig-story-name"></span>'
      + '</div>';
  }

  function setStoriesBarEmptyState(isEmpty){
    var track = document.getElementById('igStoriesTrack');
    if(!track) return;
    var bar = track.closest('.ig-stories-bar');
    if(bar) bar.classList.toggle('is-empty', !!isEmpty);
    track.classList.toggle('is-empty', !!isEmpty);
  }

  function renderPublicStoriesBar(items){
    items = Array.isArray(items) ? items : [];
    if(window.TTStories && typeof window.TTStories.setCatalog === 'function'){
      window.TTStories.setCatalog(items);
    }
    var track = document.getElementById('igStoriesTrack');
    if(!track) return;
    if(!items.length){
      track.innerHTML = mfStoriesEmptyHtml();
      setStoriesBarEmptyState(true);
      return;
    }
    setStoriesBarEmptyState(false);
    var gradients = [
      'linear-gradient(135deg,#667eea,#764ba2)',
      'linear-gradient(135deg,#f093fb,#f5576c)',
      'linear-gradient(135deg,#4facfe,#00f2fe)',
      'linear-gradient(135deg,#43e97b,#38f9d7)',
      'linear-gradient(135deg,#fa709a,#fee140)',
      'linear-gradient(135deg,#a18cd1,#fbc2eb)',
      'linear-gradient(135deg,#ff9a9e,#fecfef)'
    ];
    var html = '';
    items.forEach(function(story, idx){
      var thumb = String(story.avatarUrl || '');
      var ringInner = thumb
        ? ('<img src="'+escStory(thumb)+'" alt="">')
        : ('<span class="ig-story-thumb" style="background:'+gradients[idx % gradients.length]+'"></span>');
      var label = String(story.name || 'Story');
      if(label.length > 11) label = label.slice(0, 10) + '..';
      html += '<a type="button" class="ig-story-item" data-story-key="'+escStory(String(story.key))+'" data-story-index="'+String(idx)+'" aria-label="Open story for '+escStory(story.name)+'">'
        + '<div class="ig-story-ring">'+ringInner+'</div>'
        // + '<span class="ig-story-name">'+escStory(label)+'</span>'
        + '</a>';
    });
    track.innerHTML = html;
  }

  renderPublicStoriesBar(catalog);
})();
</script>
<script>
(function(){
  var storyPostId = <?php echo (int)$publicStoryPostId; ?>;
  if(!storyPostId) return;

  function clearStoryPostParam(){
    try{
      var nextUrl = new URL(window.location.href);
      nextUrl.searchParams.delete('story_post');
      history.replaceState({}, document.title, nextUrl.pathname + nextUrl.search + nextUrl.hash);
    }catch(err){}
  }

  function openStoryByPostId(postId){
    postId = Number(postId || 0);
    if(!postId || !window.TTStories) return false;
    var items = (window.TTStories.getCatalog && typeof window.TTStories.getCatalog === 'function')
      ? window.TTStories.getCatalog()
      : [];
    items = Array.isArray(items) ? items : [];
    for(var i = 0; i < items.length; i += 1){
      var story = items[i] || {};
      var slides = Array.isArray(story.slides) ? story.slides : [];
      for(var j = 0; j < slides.length; j += 1){
        if(Number(slides[j].postId || 0) === postId){
          if(typeof window.TTStories.openByIndex === 'function'){
            window.TTStories.openByIndex(i);
          } else {
            window.TTStories.openByKey(String(story.key || ''));
          }
          return true;
        }
      }
    }
    return false;
  }

  function openTalentraCircle(){
    var key = 'u' + String(Number(window.ME_ID || <?php echo (int)$meId; ?> || 0));
    if(!key || key === 'u0') return;
    if(window.TTStories && typeof window.TTStories.openByKey === 'function'){
      window.TTStories.openByKey(key);
    }
  }

  var tries = 0;
  (function waitForStories(){
    tries += 1;
    var track = document.getElementById('igStoriesTrack');
    var hasStory = !!(track && track.querySelector('.ig-story-item[data-story-key]'));
    if(hasStory){
      clearStoryPostParam();
      if(!openStoryByPostId(storyPostId)){
        setTimeout(openTalentraCircle, 120);
      }
      return;
    }
    if(tries < 40) setTimeout(waitForStories, 200);
    else clearStoryPostParam();
  })();
})();
</script>
<?php if ($publicUploadWarn): ?>
<script>
(function(){
  function clearUploadWarn(){
    try{
      var nextUrl = new URL(window.location.href);
      nextUrl.searchParams.delete('upload_warn');
      history.replaceState({}, document.title, nextUrl.pathname + nextUrl.search + nextUrl.hash);
    }catch(err){}
  }
  function showUploadWarnToast(){
    if(window.MSBToast && typeof window.MSBToast.show === 'function'){
      window.MSBToast.show({
        type: 'warn',
        title: 'Photo not attached',
        message: 'Your story was saved, but the photo or video could not be attached. Try again with JPG, PNG, or MP4.',
        actionLabel: 'Try again',
        actionHref: 'dashboard.php?modal=1&story=1',
        actionModal: true,
        duration: 10000
      });
      clearUploadWarn();
      return;
    }
    setTimeout(showUploadWarnToast, 120);
  }
  setTimeout(showUploadWarnToast, 280);
})();
</script>
<?php endif; ?>
<?php theme_prefs_print_post_card_tail($dbh, $meId); ?>
<style id="news-media-head-overlay-css">
.news-page .post.public-post-card:not(.is-reel-post):has(.standard-media-topbar){
  padding-top:0!important;
}
.news-page .post.public-post-card:not(.is-reel-post) .media-stage:has(.standard-media-topbar){
  background:transparent!important;
  background-color:transparent!important;
}
</style>
<style id="news-media-head-overlay-tail-css">
html[data-msb-appearance] body.news-page .post.public-post-card .standard-media-topbar,
html[data-msb-appearance] body.news-page .post.public-post-card .standard-media-topbar .standard-media-author,
html[data-msb-appearance] body.news-page .post.public-post-card .standard-media-topbar a:hover,
html[data-msb-appearance] body.news-page .post.public-post-card .standard-media-topbar .standard-media-author:hover,
html[data-msb-appearance] body.news-page .post.public-post-card:has(.standard-media-topbar) .media-stage,
html[data-msb-appearance] body.news-page .post.public-post-card:has(.standard-media-topbar) .media-stage > img,
html[data-msb-appearance] body.news-page .post.public-post-card:has(.standard-media-topbar) .media-stage > video,
html[data-msb-appearance] body.news-page .post.public-post-card:has(.standard-media-topbar) .media-stage > .media-carousel,
html[data-msb-appearance] body.dark-auto.news-page .post.public-post-card:has(.standard-media-topbar) .media-stage,
html[data-msb-appearance] body.dark-auto.news-page .post.public-post-card:has(.standard-media-topbar) .media-stage > img,
html[data-msb-appearance] body.dark-auto.news-page .post.public-post-card:has(.standard-media-topbar) .media-stage > video,
html[data-msb-appearance] body.dark-auto.news-page .post.public-post-card:has(.standard-media-topbar) .media-stage > .media-carousel{
  background:transparent!important;
  background-color:transparent!important;
  background-image:none!important;
  border-color:transparent!important;
  box-shadow:none!important;
}
html[data-msb-appearance] body.news-page .post.public-post-card .standard-media-topbar .standard-media-name,
html[data-msb-appearance] body.news-page .post.public-post-card .standard-media-topbar .standard-media-time,
html[data-msb-appearance] body.news-page .post.public-post-card .standard-media-topbar .mf-music-row,
html[data-msb-appearance] body.news-page .post.public-post-card .standard-media-topbar .mf-music-ic,
html[data-msb-appearance] body.news-page .post.public-post-card .standard-media-topbar .mf-music-title,
html[data-msb-appearance] body.news-page .post.public-post-card .standard-media-topbar .mf-music-artist,
html[data-msb-appearance] body.news-page .post.public-post-card .standard-media-topbar .mf-music-dot,
html[data-msb-appearance] body.news-page .post.public-post-card .standard-media-topbar .standard-media-name,
html[data-msb-appearance] body.news-page .post.public-post-card .standard-media-topbar .standard-media-time,
html[data-msb-appearance] body.news-page .post.public-post-card .standard-media-topbar a:hover .standard-media-name,
html[data-msb-appearance] body.news-page .post.public-post-card .standard-media-topbar a:hover .standard-media-time{
  color:#fff!important;
  text-shadow:0 2px 10px rgba(0,0,0,.34);
}
html[data-msb-appearance] body.news-page .post.public-post-card .media-stage > .standard-media-bottom,
html[data-msb-appearance] body.news-page .post.public-post-card .media-stage > .standard-media-bottom .standard-media-actions{
  background:none!important;
  background-color:transparent!important;
  background-image:none!important;
  border-color:transparent!important;
}
html[data-msb-appearance] body .post.public-post-card .standard-media-topbar > .post-card-menu-wrap,
html[data-msb-appearance] body.news-page .post.public-post-card .standard-media-topbar > .post-card-menu-wrap{
  margin-right:0 !important;
  position:absolute !important;
  top:var(--pcm-on-media-topbar-menu-top, 2px) !important;
  right:var(--pcm-on-media-topbar-menu-right, 4px) !important;
}
</style>
<?php if ($isNewsSurface): ?>
<style id="news-feed-dividers-css">
/* news.php — match public.php / feed.php feed column dividers */
body.news-page.feed-insta-ui .feed-desktop-center{
  border-left:1px solid var(--public-border-strong, var(--msb-palette-border, rgba(15,23,42,.16))) !important;
  border-right:1px solid var(--public-border-strong, var(--msb-palette-border, rgba(15,23,42,.16))) !important;
  box-sizing:border-box !important;
}
body.news-page.feed-insta-ui .feed-top-search{
  border-bottom:1px solid var(--public-border-strong, var(--msb-palette-border, rgba(15,23,42,.16))) !important;
}
body.news-page.feed-insta-ui .post.public-post-card{
  margin:0 !important;
  border:0 !important;
  border-radius:0 !important;
  box-shadow:inset 0 -1px 0 var(--public-border-strong, var(--msb-palette-border, rgba(15,23,42,.16))) !important;
  position:relative !important;
  width:100% !important;
  max-width:100% !important;
  display:block !important;
  box-sizing:border-box !important;
  overflow:visible !important;
}
html[data-msb-appearance] body.news-page.feed-insta-ui .post.public-post-card{
  box-shadow:inset 0 -1px 0 var(--msb-palette-border, var(--public-border-strong, rgba(15,23,42,.16))) !important;
}
body.news-page.feed-insta-ui .post.public-post-card.is-single-video-post:not(.is-reel-post),
body.news-page.feed-insta-ui .post.public-post-card.is-single-image-post:not(.is-reel-post){
  width:100% !important;
  max-width:100% !important;
  margin-left:0 !important;
  margin-right:0 !important;
}
body.news-page.feed-insta-ui .post.public-post-card.is-single-video-post:not(.is-reel-post) .media-stage.standard-video-stage,
body.news-page.feed-insta-ui .post.public-post-card.is-single-image-post:not(.is-reel-post) .media-stage.standard-image-stage{
  width:min(100%, var(--post-media-card-width, var(--post-media-max, 680px))) !important;
  max-width:100% !important;
  margin-left:auto !important;
  margin-right:auto !important;
}
body.news-page.feed-insta-ui .feed-desktop-layout .ig-feed{
  margin:0 !important;
}
</style>
<?php endif; ?>
<?php post_card_actions_menu_render_modals(); ?>
<?php post_card_actions_menu_render_js([
  'delete_mode' => 'public',
  'staff_readonly' => $staffReadonly,
  'menu_surface' => 'public',
  'api_url' => 'feed_api.php',
  'can_follow_publishers' => $canFollowOnPublicMenu,
  'publisher_workspace_viewer' => $isPublisherWorkspaceViewer,
]); ?>
</body>
</html>
