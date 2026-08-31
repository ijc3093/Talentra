<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/session_user.php';
requireUserLogin();
require_once __DIR__ . '/includes/home_tabs.php';
home_redirect_legacy_entry((defined('MSB_PUBLIC_FEED_SURFACE') && MSB_PUBLIC_FEED_SURFACE === 'news') ? 'news' : 'public');
require_once __DIR__ . '/controller.php';
require_once __DIR__ . '/includes/friend_system.php';
require_once __DIR__ . '/includes/publisher_accounts.php';
require_once __DIR__ . '/includes/publisher_organization_bridge.php';
require_once __DIR__ . '/includes/staff_publisher_access.php';
require_once __DIR__ . '/includes/device_profile.php';
require_once __DIR__ . '/includes/post_upload.php';
require_once __DIR__ . '/includes/post_layout.php';
require_once __DIR__ . '/includes/theme_prefs.php';
require_once __DIR__ . '/includes/post_card_actions_menu.php';
require_once __DIR__ . '/includes/post_action_thin_icons.php';
require_once __DIR__ . '/includes/post_tags.php';
require_once __DIR__ . '/includes/msb_feed_engagement.php';

error_reporting(E_ALL);
ini_set('display_errors', '0');
$discoverFragmentRequest = (string)($_GET['ajax_discover'] ?? '') === '1';
$discoverFragmentBaseObLevel = ob_get_level();
if ($discoverFragmentRequest) {
    ob_start();
    set_exception_handler(static function (Throwable $e): void {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        if (!headers_sent()) {
            http_response_code(200);
            header('Content-Type: text/html; charset=utf-8');
        }
        echo '';
        exit;
    });
}

$controller = new Controller();
$dbh = $controller->pdo();
publisher_ensure_schema($dbh);
device_profile_ensure_post_columns($dbh);
msb_feed_engagement_ensure_schema($dbh);
$meId = (int)($_SESSION['user_id'] ?? 0);
$canFollowPublishers = publisher_can_follow_as_viewer($dbh, $meId);
$isPublisherWorkspaceViewer = publisher_workspace_viewer($dbh, $meId);
$canFollowOnPublicMenu = $canFollowPublishers || $isPublisherWorkspaceViewer;
$registerWelcome = null;
if (!$discoverFragmentRequest && !empty($_SESSION['register_welcome']) && is_array($_SESSION['register_welcome'])) {
    $registerWelcome = $_SESSION['register_welcome'];
    unset($_SESSION['register_welcome']);
}
$staffReadonly = staff_pub_is_readonly();
$canLiveStudio = live_studio_user_can_access($dbh, $meId);
$feedLeftRailPublicPublishers = staff_pub_menu_for_viewer($dbh, $meId);
$feedSurface = (defined('MSB_PUBLIC_FEED_SURFACE') && MSB_PUBLIC_FEED_SURFACE === 'news') ? 'news' : 'public';
$selfPage = defined('MSB_HOME_BOOTSTRAP') ? 'home.php' : ($feedSurface === 'news' ? 'news.php' : 'public.php');
$isNewsSurface = ($feedSurface === 'news');
$q = trim((string)($_GET['q'] ?? ''));
$discoverTab = home_tab_internal(strtolower(trim((string)($_GET['tab'] ?? ($isNewsSurface ? 'news' : 'public')))));
$pageTitle = $isNewsSurface
    ? 'News'
    : ($discoverTab === 'for-you' ? 'Circle' : ($discoverTab === 'public' ? 'Discover' : 'Public'));
$discoverTabs = [
    'for-you' => 'Circle',
    'public' => 'Discover',
    'enterprise' => 'Commerce',
    'trending' => 'Trending',
    'news' => 'News',
    'sports' => 'Sports',
    'business' => 'Business',
    'science' => 'Science',
    'music' => 'Music',
    'arts' => 'Arts & Painting',
    'agriculture' => 'Agriculture',
    'auto' => 'Auto',
    'political' => 'Political',
];
// These stay in Add Program until the user adds them to the top tabs
$optionalDiscoverTabs = [
    'enterprise' => true,
    'trending' => true,
    'news' => true,
    'sports' => true,
    'business' => true,
    'science' => true,
    'music' => true,
    'arts' => true,
    'agriculture' => true,
    'auto' => true,
    'political' => true,
];
$publicNavTabs = [
    'entertainment' => 'Entertainment',
    'library' => 'Library',
    'cook' => 'Cook',
    'seek-around-the-world' => 'Seek around the World',
    'geology' => 'Geology',
    'animation' => 'Animation',
    'make-a-new-friend' => 'Make a new Friend',
    'agents' => 'Agents',
    'deep-research' => 'Deep research',
] + publisher_academic_categories() + publisher_custom_categories($dbh);
if (!isset($discoverTabs[$discoverTab]) && !isset($publicNavTabs[$discoverTab])) {
    $discoverTab = $isNewsSurface ? 'news' : 'public';
}
$publicAlertPostId = (int)($_GET['open_post'] ?? $_GET['post'] ?? 0);
$publicAlertCommentId = (int)($_GET['open_comment'] ?? 0);
$publicStoryPostId = (int)($_GET['story_post'] ?? 0);
$publicUploadWarn = (string)($_GET['upload_warn'] ?? '') === '1';

if ($discoverTab === 'for-you') {
    if ($discoverFragmentRequest) {
        while (ob_get_level() > $discoverFragmentBaseObLevel) {
            ob_end_clean();
        }
        if (!headers_sent()) {
            header('Content-Type: text/html; charset=utf-8');
        }
        echo '';
        exit;
    }
    $circleExtra = [];
    if ($q !== '') {
        $circleExtra['q'] = $q;
    }
    if ($publicAlertPostId > 0) {
        $circleExtra['post'] = $publicAlertPostId;
    }
    if ($publicAlertCommentId > 0) {
        $circleExtra['open_comment'] = $publicAlertCommentId;
    }
    if ($publicStoryPostId > 0) {
        $circleExtra['story_post'] = $publicStoryPostId;
    }
    if ($publicUploadWarn) {
        $circleExtra['upload_warn'] = '1';
    }
    header('Location: ' . home_tab_url('for-you', $circleExtra));
    exit;
}

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
    return post_limit_sentences($text, $maxSentences);
}

function public_caption_sentence_count(string $text): int
{
    return post_caption_sentence_count($text);
}

/** True when the card should show Read more (more than 3 sentences, or one very long block). */
function public_caption_needs_readmore(string $caption, int $maxSentences = 3, int $maxChars = 170): bool
{
    return post_caption_needs_readmore($caption, $maxSentences, $maxChars);
}

function public_caption_card_html(string $caption, int $maxSentences = 3, int $maxChars = 170): string {
    return post_caption_card_html($caption, $maxSentences, $maxChars);
}

$isForYouTab = ($discoverTab === 'for-you');
$params = [];
if ($isForYouTab) {
    // Circle: same friends / followed-publisher scope as feed_api list (no 24h public window).
    $where = "p.is_deleted = 0 AND COALESCE(p.is_archived,0) = 0";
    if ($meId > 0 && function_exists('fs_ensure_blocks_table') && fs_ensure_blocks_table($dbh)) {
        $where .= ' AND ' . fs_block_exclude_author_sql('p.user_id', ':fsBlockMe', ':fsBlockMe2');
        $params[':fsBlockMe'] = $meId;
        $params[':fsBlockMe2'] = $meId;
    }
    $where .= ' AND ' . publisher_feed_list_scope_sql_for($dbh, $meId);
    $params = array_merge($params, publisher_feed_list_scope_params_for($dbh, $meId));
} else {
    // Discover: public posts from the last 24h, plus the viewer's own public posts
    // (so a fresh create without media/title still appears after publish).
    $where = "p.is_deleted = 0 AND COALESCE(p.is_archived,0) = 0 AND p.visibility = 'public' AND (
        COALESCE(p.updated_at,p.created_at) >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
        OR p.user_id = :discoverMeOwn
    )";
    $params[':discoverMeOwn'] = $meId;
    if ($meId > 0 && function_exists('fs_ensure_blocks_table') && fs_ensure_blocks_table($dbh)) {
        $where .= ' AND ' . fs_block_exclude_author_sql('p.user_id', ':fsBlockMe', ':fsBlockMe2');
        $params[':fsBlockMe'] = $meId;
        $params[':fsBlockMe2'] = $meId;
    }
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
    // Publishers never see personal-user posts on public.php — publisher posts only.
    if ($isPublisherWorkspaceViewer) {
        $where .= ' AND ' . publisher_author_is_publisher_sql('u');
    }
}
if ($q !== '') {
    $where .= " AND (COALESCE(p.title,'') LIKE :qTitle OR COALESCE(p.body,'') LIKE :qBody OR COALESCE(u.name,u.username,'') LIKE :qName OR COALESCE(u.username,'') LIKE :qUser)";
    $qLike = '%' . $q . '%';
    $params[':qTitle'] = $qLike;
    $params[':qBody'] = $qLike;
    $params[':qName'] = $qLike;
    $params[':qUser'] = $qLike;
}

$publisherCategoryTabs = [
    'entertainment' => 'entertainment',
    'library' => 'library',
    'cook' => 'cook',
    'seek-around-the-world' => 'seek-around-the-world',
    'geology' => 'geology',
    'animation' => 'animation',
    'make-a-new-friend' => 'make-a-new-friend',
    'deep-research' => 'deep-research',
    'trending' => 'trending',
    'news' => 'news',
    'sports' => 'sports',
    'business' => 'business',
    'science' => 'science',
    'music' => 'music',
    'arts' => 'arts',
    'agriculture' => 'agriculture',
    'auto' => 'auto',
    'political' => 'political',
];
foreach (publisher_academic_categories() as $categorySlug => $_categoryLabel) {
    $publisherCategoryTabs[$categorySlug] = $categorySlug;
}
foreach (publisher_custom_categories($dbh) as $categorySlug => $_categoryLabel) {
    $publisherCategoryTabs[$categorySlug] = $categorySlug;
}
if (!$isForYouTab && $discoverTab === 'enterprise') {
    $where .= ' AND ' . publisher_author_is_publisher_sql('u');
    $where .= " AND LOWER(TRIM(COALESCE(u.publisher_category,''))) IN ('enterprise','commerce')";
} elseif (!$isForYouTab && isset($publisherCategoryTabs[$discoverTab])) {
    // Category tabs are publisher lanes (view + Follow), not personal Discover.
    $where .= ' AND ' . publisher_author_is_publisher_sql('u');
    $where .= " AND LOWER(TRIM(COALESCE(u.publisher_category,''))) = :discoverCategory";
    $params[':discoverCategory'] = $publisherCategoryTabs[$discoverTab];
} elseif (!$isForYouTab && !$isNewsSurface && $discoverTab === 'public') {
    // Discover: personal users see people (add friend). Publishers see publishers only.
    if (!$isPublisherWorkspaceViewer) {
        $where .= ' AND ' . publisher_author_is_personal_sql('u');
    }
}

$publicOrderBy = 'COALESCE(p.updated_at,p.created_at) DESC, p.id DESC';
if ($isForYouTab) {
    // Circle: newest first so a just-created post (incl. text-only) is always on top.
    // Own posts from the last 48h stay ahead of everyone else's older cards.
    $ownRecentExpr = '(CASE WHEN p.user_id = :meBoost AND COALESCE(p.updated_at,p.created_at) >= DATE_SUB(NOW(), INTERVAL 48 HOUR) THEN 1 ELSE 0 END)';
    $publicOrderBy = $ownRecentExpr . ' DESC, COALESCE(p.updated_at,p.created_at) DESC, p.id DESC';
} elseif ($discoverTab === 'trending') {
    $ownRecentExpr = '(CASE WHEN p.user_id = :meBoost AND COALESCE(p.updated_at,p.created_at) >= DATE_SUB(NOW(), INTERVAL 48 HOUR) THEN 1 ELSE 0 END)';
    $ownRecentTs = '(CASE WHEN p.user_id = :meBoost AND COALESCE(p.updated_at,p.created_at) >= DATE_SUB(NOW(), INTERVAL 48 HOUR) THEN UNIX_TIMESTAMP(COALESCE(p.updated_at,p.created_at)) ELSE 0 END)';
    $ownRecentBoost = $ownRecentExpr . ' DESC, ' . $ownRecentTs . ' DESC, ';
    if (msb_posts_has_attention_cols($dbh)) {
        $publicOrderBy = $ownRecentBoost . msb_attention_score_sql('p') . ' DESC, COALESCE(p.updated_at,p.created_at) DESC, p.id DESC';
    } else {
        $publicOrderBy = $ownRecentBoost . '(COALESCE(p.views_count,0) + (comment_count * 4) + (like_count * 3) + (love_count * 3) + (share_count * 5)) DESC, COALESCE(p.updated_at,p.created_at) DESC, p.id DESC';
    }
}

$layoutColumn = post_layout_column($dbh);
$layoutSelect = post_layout_select_sql($dbh);

$sql = "
SELECT
  p.id, p.user_id, COALESCE(p.title,'') AS title, COALESCE(p.description,'') AS description, COALESCE(p.body,'') AS body,
  COALESCE(p.views_count,0) AS views_count, p.created_at, COALESCE(p.updated_at,p.created_at) AS updated_at,
  COALESCE(p.device_label,'') AS device_label, COALESCE(p.device_viewport,'') AS device_viewport,
  COALESCE(p.music_title,'') AS music_title, COALESCE(p.music_artist,'') AS music_artist,
  COALESCE(p.sound_id,0) AS sound_id,
  COALESCE(p.stitch_of_post_id,0) AS stitch_of_post_id,
  COALESCE(p.duet_of_post_id,0) AS duet_of_post_id,
  COALESCE(p.is_archived,0) AS is_archived,
  LOWER(COALESCE(NULLIF(TRIM(p.visibility), ''), 'public')) AS visibility,
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
ORDER BY {$publicOrderBy}
LIMIT 100";
$params[':me'] = $meId;
$params[':me2'] = $meId;
$params[':me3'] = $meId;
$params[':meFollow'] = $meId;
if (strpos($publicOrderBy, ':meBoost') !== false) {
    $params[':meBoost'] = $meId;
}

$st = $dbh->prepare($sql);
$st->execute($params);
$posts = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

// After create-post redirect (?post= / ?story_post=), put that card at the top
// even when it already matched the list query (attention order can bury it).
$pinPublicId = $publicAlertPostId > 0 ? $publicAlertPostId : $publicStoryPostId;
if ($pinPublicId > 0 && $meId > 0) {
    $existingPinIdx = null;
    $existingPinRow = null;
    foreach ($posts as $existingIdx => $existing) {
        if ((int)($existing['id'] ?? 0) === $pinPublicId) {
            $existingPinIdx = (int)$existingIdx;
            $existingPinRow = $existing;
            break;
        }
    }
    if (is_array($existingPinRow)) {
        array_splice($posts, $existingPinIdx, 1);
        array_unshift($posts, $existingPinRow);
    } else {
        try {
            $pinSql = "
SELECT
  p.id, p.user_id, COALESCE(p.title,'') AS title, COALESCE(p.description,'') AS description, COALESCE(p.body,'') AS body,
  COALESCE(p.views_count,0) AS views_count, p.created_at, COALESCE(p.updated_at,p.created_at) AS updated_at,
  COALESCE(p.device_label,'') AS device_label, COALESCE(p.device_viewport,'') AS device_viewport,
  COALESCE(p.music_title,'') AS music_title, COALESCE(p.music_artist,'') AS music_artist,
  COALESCE(p.sound_id,0) AS sound_id,
  COALESCE(p.stitch_of_post_id,0) AS stitch_of_post_id,
  COALESCE(p.duet_of_post_id,0) AS duet_of_post_id,
  COALESCE(p.is_archived,0) AS is_archived,
  LOWER(COALESCE(NULLIF(TRIM(p.visibility), ''), 'public')) AS visibility,
  COALESCE(u.name, u.username, CONCAT('User ', u.id)) AS display_name, COALESCE(u.username,'') AS username, COALESCE(u.friend_code,'') AS friend_code,
  COALESCE(u.account_kind, 'personal') AS account_kind,
  EXISTS(SELECT 1 FROM public_follows pf WHERE pf.follower_id = :meFollowPin AND pf.following_id = p.user_id) AS is_following,
  {$layoutSelect}
  (SELECT COUNT(*) FROM public_post_comments c WHERE c.post_id = p.id AND c.is_deleted = 0) AS comment_count,
  (SELECT COUNT(*) FROM public_post_reactions r WHERE r.post_id = p.id AND r.reaction <> 'love') AS like_count,
  (SELECT COUNT(*) FROM public_post_reactions r WHERE r.post_id = p.id AND r.reaction = 'love') AS love_count,
  (SELECT COUNT(*) FROM public_post_shares s WHERE s.post_id = p.id) AS share_count,
  (SELECT COUNT(*) FROM public_post_saves s WHERE s.post_id = p.id) AS save_count,
  (SELECT reaction FROM public_post_reactions r WHERE r.post_id = p.id AND r.user_id = :mePin LIMIT 1) AS my_reaction,
  EXISTS(SELECT 1 FROM public_post_shares s WHERE s.post_id = p.id AND s.user_id = :me2Pin) AS my_shared,
  EXISTS(SELECT 1 FROM public_post_saves s WHERE s.post_id = p.id AND s.user_id = :me3Pin) AS my_saved
FROM public_posts p
JOIN users u ON u.id = p.user_id
WHERE p.id = :pinId
  AND p.is_deleted = 0
  AND COALESCE(p.is_archived,0) = 0
  " . ($isForYouTab ? '' : "AND (p.visibility = 'public' OR p.user_id = :pinOwnerMe)\n  ") . "
LIMIT 1";
            $stPin = $dbh->prepare($pinSql);
            $pinParams = [
                ':pinId' => $pinPublicId,
                ':meFollowPin' => $meId,
                ':mePin' => $meId,
                ':me2Pin' => $meId,
                ':me3Pin' => $meId,
            ];
            if (!$isForYouTab) {
                $pinParams[':pinOwnerMe'] = $meId;
            }
            $stPin->execute($pinParams);
            $pinRow = $stPin->fetch(PDO::FETCH_ASSOC) ?: null;
            $pinOwnerId = (int)($pinRow['user_id'] ?? 0);
            $pinIsOwn = ($pinOwnerId > 0 && $pinOwnerId === $meId);
            $pinVisible = is_array($pinRow) && (
                $pinIsOwn
                || (
                    $isForYouTab
                        ? publisher_can_view_post($dbh, $meId, $pinRow)
                        : publisher_post_visible_on_public_surface($dbh, $meId, $pinRow)
                )
            );
            if ($pinVisible) {
                $authorKind = strtolower((string)($pinRow['account_kind'] ?? 'personal'));
                $isPubAuthor = ($authorKind === 'publisher');
                // Discover: personal viewers pin people; publishers pin publishers.
                // news.php / category tabs: publisher posts.
                // Own freshly-created posts always pin on the destination tab.
                $allowPin = $pinIsOwn;
                if (!$allowPin) {
                    $allowPin = true;
                    if ($isNewsSurface) {
                        $allowPin = $isPubAuthor;
                    } elseif ($discoverTab === 'for-you') {
                        $allowPin = true;
                    } elseif ($discoverTab === 'public') {
                        $allowPin = $isPublisherWorkspaceViewer ? $isPubAuthor : !$isPubAuthor;
                    } elseif ($discoverTab === 'enterprise' || isset($publisherCategoryTabs[$discoverTab])) {
                        $allowPin = $isPubAuthor;
                    } elseif ($isPublisherWorkspaceViewer) {
                        $allowPin = $isPubAuthor;
                    }
                }
                if ($allowPin) {
                    array_unshift($posts, $pinRow);
                }
            }
        } catch (Throwable $e) {
            // keep list as-is
        }
    }
}

if (function_exists('post_attachments_ensure_slide_columns')) {
    try { post_attachments_ensure_slide_columns($dbh); } catch (Throwable $eSlideCols) {}
}

foreach ($posts as $postIndex => &$post) {
    $pid = (int)$post['id'];
    try {
        $stA = $dbh->prepare("SELECT id, type, file_path, thumb_path, slide_title, slide_body FROM public_post_attachments WHERE post_id = :pid ORDER BY id ASC");
        $stA->execute([':pid' => $pid]);
        $attachments = $stA->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $eAtt) {
        $stA = $dbh->prepare("SELECT id, type, file_path, thumb_path FROM public_post_attachments WHERE post_id = :pid ORDER BY id ASC");
        $stA->execute([':pid' => $pid]);
        $attachments = $stA->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
    foreach ($attachments as &$a) {
        $a['file_path'] = media_src((string)($a['file_path'] ?? ''));
        $a['thumb_path'] = media_src((string)($a['thumb_path'] ?? ''));
        $a['slide_title'] = (string)($a['slide_title'] ?? '');
        $a['slide_body'] = (string)($a['slide_body'] ?? '');
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

if (function_exists('msb_post_tags_people_for_posts') && $posts !== []) {
    $tagMap = msb_post_tags_people_for_posts($dbh, array_map(static function ($row) {
        return (int)($row['id'] ?? 0);
    }, $posts));
    foreach ($posts as &$postTag) {
        $pidTag = (int)($postTag['id'] ?? 0);
        $people = ($pidTag > 0 && isset($tagMap[$pidTag])) ? $tagMap[$pidTag] : [];
        $postTag['tagged_people'] = $people;
        $meTaggedFlag = 0;
        if ($meId > 0) {
            foreach ($people as $tp) {
                if ((int)($tp['id'] ?? 0) === $meId) {
                    $meTaggedFlag = 1;
                    break;
                }
            }
        }
        $postTag['me_tagged'] = $meTaggedFlag;
    }
    unset($postTag);
}

if (function_exists('msb_post_products_for_posts') && $posts !== []) {
    $prodMapPublic = msb_post_products_for_posts($dbh, array_map(static function ($row) {
        return (int)($row['id'] ?? 0);
    }, $posts));
    foreach ($posts as &$postProd) {
        $pidProd = (int)($postProd['id'] ?? 0);
        $postProd['products'] = ($pidProd > 0 && isset($prodMapPublic[$pidProd])) ? $prodMapPublic[$pidProd] : [];
        $postProd['sound_id'] = (int)($postProd['sound_id'] ?? 0);
    }
    unset($postProd);
}

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
  <?php require __DIR__ . '/includes/entry_wake_overlay.php'; ?>
  <script>
    try{ if('scrollRestoration' in history) history.scrollRestoration = 'manual'; }catch(e){}
  </script>
  <?php theme_prefs_print_head_bootstrap($dbh, $meId); ?>
  <style id="modal-fouc-lock-css"><?php include __DIR__ . '/includes/modal_fouc_lock.css.php'; ?></style>
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
    .ig-feed{width:min(100%,614px)}
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
    .search-input{flex:1;height:52px;border:1px solid var(--feed-control-border, var(--public-control-border, var(--msb-palette-border-strong, #3a3a3a)));border-right:0;border-radius:999px 0 0 999px;padding:0 22px;font-size:15px;outline:none;background:var(--msb-palette-input-bg, var(--msb-palette-bg, #121212));color:var(--msb-palette-text, #fff)}
    .search-btn{flex:0 0 auto;width:88px;height:52px;border:1px solid var(--feed-control-border, var(--public-control-border, var(--msb-palette-border-strong, #3a3a3a)));border-radius:0 999px 999px 0;padding:0;font-weight:800;color:#fff;background:#222;cursor:pointer;white-space:nowrap;font-size:24px}
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
      border-left:1px solid var(--public-border-strong, #c0c2c4);
      border-right:1px solid var(--public-border-strong, #c0c2c4);
      box-sizing:border-box;
    }
    body.feed-insta-ui .feed-top-search{
      border-bottom:1px solid var(--feed-post-divider, var(--public-border-strong, #c0c2c4));
    }
    /* Match feed.php .mf-card: full-width bottom divider under the post (under action icons). */
    body.feed-insta-ui .post.public-post-card{
      margin:0 !important;
      border:0 !important;
      border-bottom:1px solid var(--feed-post-divider, var(--public-border-strong, #c0c2c4)) !important;
      border-radius:0 !important;
      box-shadow:none !important;
      position:relative;
      width:100% !important;
      max-width:100% !important;
      display:block;
      box-sizing:border-box;
    }
    .post.is-single-video-post{
      width:100%;
      max-width:100%;
      margin-left:0;
      margin-right:0;
    }
    .post.is-single-image-post{
      width:100%;
      max-width:100%;
      margin-left:0;
      margin-right:0;
    }
    .post.is-multi-media-post{
      width:100%;
      max-width:100%;
      margin-left:0;
      margin-right:0;
    }
    .post.public-post-card:not(.is-reel-post){
      position:relative;
      background:var(--public-surface);
      box-shadow:none;
    }
    .public-auto-progress{
      position:absolute;
      top:2px;
      left:14px;
      right:14px;
      height:1px;
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
      height:1px;
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
    .post-author-avatar-link{display:block;flex:0 0 auto;color:inherit;text-decoration:none}
    .post-author-avatar-link:hover{opacity:.92;text-decoration:none;color:inherit}
    a.msb-sharing-who{color:inherit;font-weight:700;text-decoration:none}
    a.msb-sharing-who:hover{text-decoration:underline}
    .msb-sharing-with{font-weight:400;color:var(--public-muted, #667085)}
    .msb-sharing-meta,
    .name .time.msb-sharing-meta,
    .standard-text-name .time.msb-sharing-meta,
    .standard-media-name .time.msb-sharing-meta,
    .reel-top-name .time.msb-sharing-meta{
      font-weight:400;
      color:var(--public-muted, #667085);
      margin:0 4px;
      white-space:nowrap;
    }
    .name .post-vis-badge,
    .standard-text-name .post-vis-badge,
    .standard-media-name .post-vis-badge,
    .reel-top-name .post-vis-badge{
      margin-left:4px;
      vertical-align:middle;
    }
    .standard-media-topbar .msb-sharing-with,
    .standard-media-name .msb-sharing-with,
    .standard-media-name .msb-sharing-meta{
      color:rgba(255,255,255,.72);
    }
    .name.is-sharing-with,
    .standard-text-name.is-sharing-with,
    .standard-media-name.is-sharing-with,
    .reel-top-name.is-sharing-with{
      white-space:normal;
      line-height:1.25;
    }
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
    .post-card-head-actions .post-card-menu-wrap,
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
      padding:16px 16px 10px;
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
    .standard-text-name.is-sharing-with{
      max-width:min(100%, 340px);
      white-space:normal;
      overflow:visible;
      text-overflow:unset;
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
      font-size:15px;
      font-weight:800;
      line-height:1.3;
    }
    .standard-text-caption{
      font-size:12px;
      font-weight:400;
      line-height:1.45;
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
      /* Preview is already limited to ~3 sentences in PHP; soft CSS cap as backup */
      max-height:6.6em;
      overflow:hidden;
    }
    .standard-text-copy .open-inline{
      color:var(--public-muted);
      font-weight:800;
    }
    .standard-text-actions{
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap:16px;
      margin-top:14px;
      width:100%;
      padding:12px 0 2px;
    }
    .standard-text-meta-bar,
    .standard-media-meta-bar{
      display:none !important;
    }
    .standard-text-left,
    .standard-text-right{
      display:flex;
      align-items:center;
      gap:18px;
      flex-wrap:wrap;
    }
    .standard-text-left{
      flex:1 1 auto;
      min-width:0;
      flex-direction:row;
      gap:18px;
    }
    .standard-text-row{
      display:flex;
      align-items:center;
      gap:18px;
      flex-wrap:wrap;
    }
    .standard-text-right{
      flex:0 0 auto;
      flex-direction:row;
      align-items:center;
      gap:18px;
      text-align:right;
      margin-left:auto;
      min-width:max-content;
    }
    .standard-text-meta-bar .standard-text-left,
    .standard-text-meta-bar .standard-text-right{
      align-items:flex-start;
    }
    .standard-text-meta-bar .standard-text-right{
      flex-direction:column;
      align-items:flex-end;
      gap:10px;
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
    .standard-text-btn:not(.is-love):not(.is-like):not(.is-reacted) i{color:var(--msb-palette-text, var(--public-text)) !important;filter:var(--msb-pact-contrast-filter, drop-shadow(0 0 1.35px rgba(255,255,255,.98)) drop-shadow(0 1px 2px rgba(0,0,0,.55))) !important}
    .standard-text-btn .msb-reaction-glyph,
    .standard-media-btn .msb-reaction-glyph,
    .action-btn .msb-reaction-glyph,
    .reel-inline-btn .msb-reaction-glyph,
    .public-live-action-btn .msb-reaction-glyph{
      display:inline-flex !important;
      align-items:center;
      justify-content:center;
      width:16px;height:16px;min-width:16px;min-height:16px;flex:0 0 16px;
      font-size:16px !important;
      line-height:1 !important;
      font-family:"Apple Color Emoji","Segoe UI Emoji","Noto Color Emoji","Segoe UI Symbol",sans-serif !important;
      background:transparent !important;
      -webkit-mask:none !important;
      mask:none !important;
      filter:var(--msb-pact-contrast-filter, drop-shadow(0 0 1.35px rgba(255,255,255,.98)) drop-shadow(0 1px 2px rgba(0,0,0,.55))) !important;
    }
    .standard-text-btn.is-love i{color:var(--msb-rx-love, var(--msb-love-color, #ff4d6d)) !important}
    .standard-text-btn.is-like i{color:var(--msb-rx-like, #2563eb) !important}
    .standard-text-btn.is-share i{color:#374151 !important}
    .standard-text-btn.is-save i{color:#f59e0b !important}
    .standard-text-btn .action-count{
      color:var(--public-muted);
      font-size:12px;
      font-weight:600;
      line-height:1;
      text-shadow:var(--msb-pact-contrast-text-shadow, 0 0 2px rgba(255,255,255,.95), 0 1px 2px rgba(0,0,0,.45));
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
    .media-stage video,.media-stage img{display:block;width:100%;height:auto;max-height:var(--post-media-max-height, min(70vh, 580px));background:transparent}
    .media-stage.standard-video-stage > video{
      width:100%;
      height:auto;
      max-height:var(--post-media-max-height, min(70vh, 580px));
      background:transparent;
      border-radius:0;
    }
    .media-stage.standard-video-stage > video,
    .media-slide > video,
    .reel-stage > video.reel-video{
      cursor:pointer;
    }
    .media-stage.standard-image-stage > img{
      width:100%;
      height:auto;
      max-height:var(--post-media-max-height, min(70vh, 580px));
      background:transparent;
      border-radius:0;
      object-fit:contain;
      object-position:center center;
    }
    .media-stage video{object-fit:contain;object-position:center center}
    .media-stage img{object-fit:contain;object-position:center center}
    .post.public-post-card:not(.is-reel-post) .media-stage.standard-video-stage > video::-webkit-media-controls,
    .post.public-post-card:not(.is-reel-post) .media-stage.standard-video-stage > video::-webkit-media-controls-enclosure,
    .post.public-post-card:not(.is-reel-post) .media-stage .media-slide > video::-webkit-media-controls,
    .post.public-post-card:not(.is-reel-post) .media-stage .media-slide > video::-webkit-media-controls-enclosure{
      display:none !important;
      opacity:0 !important;
      pointer-events:none !important;
    }
    .single-portrait{aspect-ratio:auto;max-height:var(--post-media-max-height, min(70vh, 580px));overflow:hidden}
    .single-portrait img,.single-portrait video{height:auto;width:100%}
    .single-portrait img{object-fit:contain;object-position:center center}
    .single-portrait video{object-fit:contain;object-position:center center}
    .single-landscape{
      /* aspect-ratio:4/3; */
    overflow:hidden}
    .single-landscape img,.single-landscape video{height:auto;width:100%}
    .single-landscape img{object-fit:contain;object-position:center center}
    .single-landscape video{object-fit:contain;object-position:center center}
    .single-square{
      /* aspect-ratio:1/1; */
      overflow:hidden}
    .single-square img,.single-square video{height:auto;width:100%}
    .single-square img{object-fit:contain;object-position:center center}
    .single-square video{object-fit:contain;object-position:center center}
    .media-stage.phone-shot.standard-image-stage > img{
      width:100%;
      height:auto;
      max-height:var(--post-media-max-height, min(70vh, 580px));
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

    .media-carousel{position:relative;width:100%;height:100%;overflow:hidden}
    .media-slides{display:grid;grid-template-areas:"fade";width:100%;height:100%;transform:none!important;transition:none}
    .media-slide{grid-area:fade;flex:none;width:100%;height:100%;background:transparent;display:flex;align-items:center;justify-content:center;opacity:0;transition:opacity .7s ease-in-out;pointer-events:none;z-index:0}
    .media-slide.is-active{opacity:1;pointer-events:auto;z-index:1}
    @media (prefers-reduced-motion:reduce){.media-slide{transition:none}}
    .media-slide > img,.media-slide > video{width:100%;height:100%;background:transparent}
    .media-slide > img{object-fit:contain;object-position:center center}
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
      margin-left: -8px;
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
    .standard-media-name.is-sharing-with{
      white-space:normal;
      overflow:visible;
      text-overflow:unset;
      flex:1 1 auto;
    }
    .standard-media-name-row:has(.is-sharing-with){
      flex-wrap:wrap;
      align-items:flex-start;
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
      font-size:15px;
      font-weight:800;
      line-height:1.3;
    }
    .standard-media-caption{
      font-size:12px;
      font-weight:400;
      line-height:1.45;
      color:#fff;
      word-break:break-word;
      text-align:left;
    }
    .standard-media-intro{margin:0 0 8px}
    .standard-media-subtitle{
      margin:10px 0 6px;
      color:#fff;
      font-size:15px;
      font-weight:700;
      line-height:1.3;
    }
    .standard-media-summary{
      font-size:14px;
      line-height:1.45;
      color:rgba(255,255,255,.92);
      word-break:break-word;
    }
    .standard-media-summary .post-slide-summary-p{margin:0}
    .standard-media-summary .post-slide-summary-list{
      margin:0;padding-left:1.15em;list-style:disc;
    }
    .standard-media-summary .post-slide-summary-list li{margin:0 0 .35em}
    .standard-media-summary .post-slide-summary-list li:last-child{margin-bottom:0}
    .standard-media-copy .open-inline{
      color:#fff;
      opacity:.92;
      font-weight:800;
      /* margin-left:6px; */
    }
    .standard-media-actions{
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap:16px;
      margin-top:0px;
      width:100%;
      padding:12px 0 2px;
    }
    .standard-media-meta-bar{
      display:flex;
      align-items:flex-start;
      justify-content:space-between;
      gap:16px;
      width:100%;
      margin-top:12px;
    }
    .standard-media-left,
    .standard-media-right{
      display:flex;
      align-items:center;
      gap:18px;
      flex-wrap:wrap;
    }
    .standard-media-left{
      flex:1 1 auto;
      min-width:0;
      flex-direction:row;
      gap:18px;
    }
    .standard-media-row{
      display:flex;
      align-items:center;
      gap:18px;
      flex-wrap:wrap;
    }
    .standard-media-right{
      flex:0 0 auto;
      flex-direction:row;
      align-items:center;
      gap:18px;
      text-align:right;
      margin-left:auto;
      min-width:max-content;
    }
    .standard-media-meta-bar .standard-media-left,
    .standard-media-meta-bar .standard-media-right{
      align-items:flex-start;
    }
    .standard-media-meta-bar .standard-media-right{
      flex-direction:column;
      align-items:flex-end;
      gap:10px;
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
      font-size:12px;
      font-weight:600;
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
    .media-nav{position:absolute;top:50%;transform:translateY(-50%);width:20px;height:20px;border:none;border-radius:999px;background:rgba(159, 153, 153, 0.9);color:#fff;display:flex;align-items:center;justify-content:center;font-size:10px;cursor:pointer;box-shadow:0 8px 24px rgba(0,0,0,.18);z-index:3}
    .media-nav:hover{background:rgba(180,180,180,.95)}
    .media-nav i{color:#fff;font-size:10px;line-height:1}
    .media-nav.prev{left:12px}
    .media-nav.next{right:12px}
    .media-dots{position:absolute;left:50%;bottom:10px;transform:translateX(-50%);display:flex;align-items:center;justify-content:center;gap:5px;padding:0;border-radius:0;background:transparent;z-index:3}
    .media-dot{
      width:5px !important;
      height:5px !important;
      min-width:5px !important;
      min-height:5px !important;
      flex:0 0 5px !important;
      display:block !important;
      border:none !important;
      border-radius:50% !important;
      padding:0 !important;
      margin:0 !important;
      background:rgba(255,255,255,.55) !important;
      cursor:pointer;
      appearance:none;
      -webkit-appearance:none;
      box-shadow:none !important;
      font-size:0 !important;
      line-height:0 !important;
      color:transparent !important;
      text-indent:-9999px !important;
      overflow:hidden !important;
      transition:background-color .15s ease, width .15s ease, height .15s ease;
    }
    .media-dot.is-active{
      width:6px !important;
      height:6px !important;
      min-width:6px !important;
      min-height:6px !important;
      flex:0 0 6px !important;
      background:#3897f0 !important;
      transform:none;
    }
    .post.public-post-card:not(.is-reel-post) .media-dots{
      bottom:12px;
      z-index:5;
      background:transparent;
    }
    .file-tile{display:flex;align-items:center;justify-content:center;min-height:420px;color:#fff;padding:24px;text-align:center;width:100%;height:100%}

    .actions{padding:12px 16px 14px}
    .post.public-post-card:not(.is-reel-post) .actions{
      background:var(--public-surface);
      border-top:0;
    }
    .action-row{display:flex;align-items:center;justify-content:space-between;gap:10px}
    .action-left,.action-right{display:flex;align-items:center;gap:14px}
    .action-btn{background:none;border:none;padding:0;color:var(--public-text);font-size:13px;line-height:1;display:inline-flex;align-items:center;justify-content:center;gap:5px;cursor:pointer}
    .action-btn:hover{opacity:.78}
    .action-btn i{
      color:#fff !important;
      text-shadow:0 1px 2px rgba(0,0,0,.55);
    }
    .action-btn .action-count{font-size:12px;font-weight:600;color:var(--public-muted);line-height:1}
    .action-btn.is-love i{color:var(--msb-love-color, #7c3aed) !important}
    .action-btn.is-like i{color:#2563eb !important}
    .action-btn.is-share i{color:#6b7280 !important}
    .action-btn.is-save i{color:#f59e0b !important}
    .likes-line,.caption-line,.comments-line,.meta-line{font-size:14px;line-height:1.45;color:var(--public-text);margin-top:8px}
    .likes-line strong,.caption-line strong{font-weight:700}
    .comments-line,.meta-line{color:var(--muted)}
    .meta-line{font-size:11px;text-transform:uppercase;letter-spacing:.06em}
    .caption-clamp{display:-webkit-box;-webkit-box-orient:vertical;-webkit-line-clamp:2;overflow:hidden}
    .open-inline{color:var(--muted);font-weight:600;margin-left:0px;cursor:pointer;text-decoration:none}
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
      font-size:12px;
      font-weight:400;
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
    .jump-rail.is-hidden{display:none !important}
    .jump-rail button{width:44px;height:44px;border:none;border-radius:50%;background:#111;color:#fff;box-shadow:0 10px 24px rgba(0,0,0,.18);display:flex;align-items:center;justify-content:center;cursor:pointer;padding:0}
    .jump-rail .jump-rail-video{
      position:fixed;
      right:16px;
      bottom:28px;
      top:auto;
      left:auto;
      margin:0;
      background:#e53935 !important;
      color:#fff !important;
      box-shadow:0 10px 24px rgba(229,57,53,.35) !important;
      z-index:36;
    }
    .jump-rail .jump-rail-video i{
      font-size:16px;
      line-height:1;
      margin-left:2px;
      color:#fff !important;
    }

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
    .mf-feed-empty .mf-feed-empty-nav-icon{
      display:block;
      width:56px;
      height:56px;
      margin:0 auto 16px;
      color:#98a2b3;
      fill:none;
      stroke:currentColor;
      stroke-width:1.75;
      stroke-linecap:round;
      stroke-linejoin:round;
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
    .sheet-btn{width:100%;display:flex;align-items:center;justify-content:center;gap:8px;padding:10px 14px;border:none;background:#fff;color:#111;font-size:13px;font-weight:600;border-bottom:1px solid #f1f5f9}
    .sheet-btn:last-child{border-bottom:none}
    .sheet-btn:hover{background:#f8fafc}
    .sheet-btn.primary{color:var(--blue)}
    .sheet-btn.is-friends{color:#166534}
    .sheet-btn.is-pending{color:#9a3412}
    .sheet-btn.is-accept{color:#1d4ed8}
    .sheet-btn.danger{color:#dc2626}
    .sheet-cancel{background:#f8fafc;color:#374151}
    .confirm-sheet .modal-body{padding:20px 16px 12px;text-align:center}
    .confirm-sheet .confirm-title{font-size:15px;font-weight:700;color:#111;margin-bottom:6px}
    .confirm-sheet .confirm-copy{font-size:13px;color:#6b7280;line-height:1.45;margin:0}
    .confirm-sheet .modal-footer{border-top:none;padding:0 14px 14px;display:grid;grid-template-columns:1fr 1fr;gap:8px}
    .confirm-sheet .btn{height:34px;border-radius:999px;font-weight:600;font-size:13px}

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
      .jump-rail .jump-rail-video{
        right:10px;
        bottom:24px;
        width:40px;
        height:40px;
      }

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
        bottom:12px;
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
      --public-post-card-border:var(--public-border-strong, #c0c2c4);
      --public-border:#c0c2c4;
      --public-border-strong:#c0c2c4;
      --public-text:#132033;
      --public-muted:#5f6c7c;
      --public-soft-text:#7a8797;
      --public-topbar-bg:rgba(255,255,255,.88);
      --public-topbar-text:#112033;
      --public-sidebar-bg:rgba(255,255,255,.92);
      --public-sidebar-hover:#eef3fb;
      --public-control-bg:#ffffff;
      --public-control-soft:#eef3fb;
      --public-control-border:#c0c2c4;
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
      /* A Gear "Progress color" is the page canvas. Keep Public's own
         surface tokens from falling back to white/light grey on header,
         search, tabs, rails, controls, and empty-feed regions. */
      --public-surface:var(--msb-palette-bg);
      --public-surface-alt:var(--msb-palette-bg);
      --public-surface-strong:var(--msb-palette-bg);
      --public-post-card-surface:var(--msb-palette-bg);
      --public-topbar-bg:var(--msb-palette-bg);
      --public-sidebar-bg:var(--msb-palette-bg);
      --public-sidebar-hover:var(--msb-palette-action-soft);
      --public-control-bg:var(--msb-palette-bg);
      --public-control-soft:var(--msb-palette-bg);
      --public-accent:var(--msb-palette-action);
      --public-accent-soft:var(--msb-palette-action-soft);
      --public-accent-strong:var(--msb-palette-action-strong);
      --public-border:var(--msb-palette-border);
      --public-border-strong:var(--msb-palette-border-strong);
      --public-control-border:var(--msb-palette-border-strong);
      --public-post-card-border:var(--msb-palette-border-strong);
      --feed-post-divider:var(--msb-palette-border-strong);
      --feed-post-column-border:var(--msb-palette-border-strong);
      background:var(--msb-palette-bg) !important;
      background-image:none !important;
    }
    html[data-theme="light"]:not([data-msb-appearance]) body{
      --public-border:#c0c2c4;
      --public-border-strong:#c0c2c4;
      --public-control-border:#c0c2c4;
      --public-post-card-border:#c0c2c4;
      --feed-post-divider:#c0c2c4;
      --feed-post-column-border:#c0c2c4;
    }
    html[data-theme="dark"]:not([data-msb-appearance]) body{
      --public-surface:#171d24;
      --public-surface-alt:#1d2530;
      --public-surface-strong:#111821;
      --public-border:#34383c;
      --public-border-strong:#34383c;
      --public-text:#eef4ff;
      --public-muted:#9ba8b8;
      --public-soft-text:#c2cbd7;
      --public-topbar-bg:#171d24;
      --public-topbar-text:#f4f7fb;
      --public-sidebar-bg:rgba(18,24,31,.94);
      --public-sidebar-hover:#1d2530;
      --public-control-bg:#10161e;
      --public-control-soft:#1a222c;
      --public-control-border:#34383c;
      --public-control-placeholder:#8f9baa;
      --public-accent:#7cb2ff;
      --public-accent-soft:rgba(124,178,255,.16);
      --public-accent-strong:#b9d7ff;
      --public-post-card-border:#34383c;
      --feed-post-divider:#34383c;
      --feed-post-column-border:#34383c;
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
      box-shadow:none;
    }
    body.feed-insta-ui .post.public-post-card:not(.is-reel-post){
      box-shadow:none !important;
      border-bottom:1px solid var(--feed-post-divider, var(--public-border-strong, #c0c2c4)) !important;
    }
    .post.public-post-card:not(.is-reel-post) .actions{
      border-top:0 !important;
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
    .post.public-post-card:not(.is-reel-post) .action-btn:not(.is-love):not(.is-like):not(.is-reacted) i,
    .standard-text-btn:not(.is-love):not(.is-like):not(.is-reacted) i{
      color:var(--msb-palette-text, var(--public-text)) !important;
      filter:var(--msb-pact-contrast-filter, drop-shadow(0 0 1.35px rgba(255,255,255,.98)) drop-shadow(0 1px 2px rgba(0,0,0,.55))) !important;
    }
    .post.public-post-card:not(.is-reel-post) .action-btn.is-love i,
    .standard-text-btn.is-love i{
      color:var(--msb-rx-love, var(--msb-love-color, #ff4d6d)) !important;
    }
    .post.public-post-card:not(.is-reel-post) .action-btn.is-like i,
    .standard-text-btn.is-like i{
      color:var(--msb-rx-like, #2563eb) !important;
    }
    .post.public-post-card:not(.is-reel-post) .action-btn.is-share i,
    .standard-text-btn.is-share i{
      color:#374151 !important;
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
    .jump-rail .jump-rail-video{
      background:#e53935 !important;
      color:#fff !important;
      box-shadow:0 10px 24px rgba(229,57,53,.35) !important;
    }
    .jump-rail .jump-rail-video i{
      color:#fff !important;
    }
    .mf-feed-empty{
      color:var(--public-muted);
    }
    .mf-feed-empty i,
    .mf-feed-empty .mf-feed-empty-nav-icon{
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
        padding:0 !important;
        /* background:transparent; */
        color:var(--public-text);
        /* padding: 30px; */
      }
      body .post.public-post-card.public-text-only:not(.is-reel-post){
        padding:8px 12px !important;
        box-sizing:border-box !important;
      }

      body .standard-text-topbar{
        display:flex;
        align-items:center;
        justify-content:space-between;
        gap:12px;
        position:relative;
        margin-bottom:0;
        padding:0 0 1px;
      }

      body .standard-media-topbar{
        display:flex;
        align-items:center;
        justify-content:space-between;
        gap:10px;
        grid-area:stack;
        align-self:start;
        justify-self:stretch;
        width:100%;
        /* margin:20px 15px 20px; */
        padding:1px 0 12px;
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
        top:-9px;
        left:auto;
        right:auto;
        width:calc(100% - 12px);
        /* margin:15px 10px 0; */
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
        /* margin-top: -15px; */
        /* margin-left: -25px; */
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
        font-size:14px;
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
        font-size:14px;
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
        font-size:15px;
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
        font-size:12px;
        font-weight:400;
        line-height:1.45;
        word-break:break-word;
        text-shadow:none;
        text-align:left;
      }
      body .standard-text-caption .post-card-paragraph,
      body .standard-media-caption .post-card-paragraph,
      body .reel-caption-text .post-card-paragraph{
        margin:0 0 4px;
        text-align:left;
        display:block;
      }
      body .post-card-caption-formatted.is-clamped{
        max-height:6.6em;
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
        align-items:center;
        justify-content:space-between;
        gap:16px;
        /* margin-top:14px; */
        width:100%;
        padding:1px 0 2px;
      }

      body .standard-text-meta-bar,
      body .standard-media-meta-bar{
        display:none !important;
      }

      body .standard-text-left,
      body .standard-text-right,
      body .standard-media-left,
      body .standard-media-right{
        display:flex;
        align-items:center;
        gap:10px;
      }

      body .standard-text-left,
      body .standard-media-left{
        flex:1 1 auto;
        min-width:0;
        flex-direction:row;
      }

      body .standard-text-right,
      body .standard-media-right{
        flex:0 0 auto;
        flex-direction:row;
        align-items:center;
        margin-left:auto;
        min-width:max-content;
      }

      body .standard-text-meta-bar .standard-text-left,
      body .standard-text-meta-bar .standard-text-right,
      body .standard-media-meta-bar .standard-media-left,
      body .standard-media-meta-bar .standard-media-right{
        align-items:flex-start;
      }

      body .standard-text-meta-bar .standard-text-right,
      body .standard-media-meta-bar .standard-media-right{
        flex-direction:column;
        align-items:flex-end;
        gap:10px;
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

      body .standard-text-btn i{
        color:var(--msb-palette-text, var(--public-text)) !important;
        font-size:16px;
        filter:var(--msb-pact-contrast-filter, drop-shadow(0 0 1.35px rgba(255,255,255,.98)) drop-shadow(0 1px 2px rgba(0,0,0,.55))) !important;
      }
      body .standard-media-btn i{
        color:#fff !important;
        font-size:16px;
        filter:var(--msb-pact-contrast-filter, drop-shadow(0 0 1.35px rgba(255,255,255,.98)) drop-shadow(0 1px 2px rgba(0,0,0,.55))) !important;
      }

      body .standard-text-btn .action-count{
        color:var(--msb-palette-text, var(--public-text));
        font-size:12px;
        font-weight:600;
        line-height:1;
        text-shadow:var(--msb-pact-contrast-text-shadow, 0 0 2px rgba(255,255,255,.95), 0 1px 2px rgba(0,0,0,.45));
      }
      body .standard-media-btn .action-count{
        color:#fff;
        font-size:12px;
        font-weight:600;
        line-height:1;
        text-shadow:0 1px 3px rgba(0,0,0,.75), 0 0 2px rgba(0,0,0,.5);
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
        /* padding:30px; */
        position:relative;
      }

      body .post.public-post-card:not(.is-reel-post) .media-stage > :first-child{
        grid-area:stack;
        /* margin:0 6px; */
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
        margin-right:0;
        left:auto;
        right:auto;
        bottom:auto;
        z-index:auto;
        padding:0;
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
  width:50px;
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
  width:44px;
  height:44px;
  margin:0 auto 4px;
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
  max-width:50px;
  font-size:11px;
  line-height:1.2;
  color:#262626;
  white-space:nowrap;
  overflow:hidden;
  text-overflow:ellipsis;
}
.ig-story-create{
  text-decoration:none;
  color:inherit;
}
.ig-story-create .ig-story-ring-create{
  background:#fafafa;
  border:2px solid #dbdbdb;
  padding:0;
  display:flex;
  align-items:center;
  justify-content:center;
  box-sizing:border-box;
}
.ig-story-create .ig-story-ring-create i{
  font-size:18px;
  color:#262626;
  line-height:1;
}
.ig-story-create:hover .ig-story-ring-create,
.ig-story-create:focus-visible .ig-story-ring-create{
  background:#f0f0f0;
  border-color:#c7c7c7;
}
.ig-story-create:focus-visible{
  outline:2px solid #0095f6;
  outline-offset:2px;
  border-radius:8px;
}
.ig-stories-track.is-empty{
  justify-content:flex-start;
  align-items:flex-start;
  min-height:44px;
}
.ig-stories-track.has-create.is-empty{
  justify-content:flex-start;
}
.ig-story-empty{
  width:auto;
  min-width:50px;
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
  font-size:18px;
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
.feed-top-search--tabs-only .feed-top-tabs-row{
  display:flex;
  align-items:center;
  gap:12px;
  width:100%;
}
.feed-top-search--tabs-only .feed-discover-tabs{
  flex:1 1 auto;
  min-width:0;
}
.feed-side-search{
  display:none;
}
.feed-top-search{
  width:100%;
  padding:12px 24px 0;
  box-sizing:border-box;
  position:relative;
  top:auto;
  z-index:105;
  background:var(--public-surface, #fff);
  flex:0 0 auto;
}
body.feed-insta-ui .feed-desktop-center > .feed-top-search,
body.public-page.feed-insta-ui .feed-desktop-center > .feed-top-search{
  position:relative;
  top:auto;
  z-index:105;
  width:100%;
  margin:0;
}
.feed-top-search-form{
  flex:1 1 auto;
  min-width:0;
  margin:0;
}
body.feed-insta-ui .feed-desktop-center > .feed-top-search .feed-top-search-form,
body.public-page.feed-insta-ui .feed-desktop-center > .feed-top-search .feed-top-search-form{
  max-width:100%;
}
.feed-top-search-field{
  position:relative;
  width:100%;
}
.feed-top-search-row{
  width:100%;
  display:flex;
  align-items:center;
  gap:16px;
}
.feed-top-search-input{
  width:100%;
  min-width:0;
  height:42px;
  border:1px solid var(--public-border, rgba(15,23,42,.14));
  border-radius:999px;
  padding:0 18px 0 46px;
  font-size:15px;
  background:var(--public-surface, #fff);
  color:var(--public-text, #0d0d0d);
  outline:none;
  box-sizing:border-box;
}
.feed-top-search-input::placeholder{
  color:var(--public-muted, #667085);
}
.feed-top-search-input:focus{
  outline:none;
  border-color:var(--msb-hairline, #2a2f36);
  box-shadow:none;
}
.feed-top-search-icon{
  position:absolute;
  left:6px;
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
  color:var(--public-muted, #667085);
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
.feed-top-search-settings{
  width:42px;
  height:42px;
  flex:0 0 42px;
  border-radius:50%;
  color:var(--public-text, #0d0d0d);
  display:inline-flex;
  align-items:center;
  justify-content:center;
  text-decoration:none;
  font-size:19px;
}
.feed-top-search-settings:hover,
.feed-top-search-settings:focus{
  color:var(--public-text, #0d0d0d);
  background:var(--msb-palette-action-soft, rgba(37,99,235,.12));
  outline:none;
}
.feed-discover-tabs{
  display:flex;
  align-items:stretch;
  width:100%;
  margin-top:8px;
  overflow-x:auto;
  scrollbar-width:none;
}
.feed-discover-tabs::-webkit-scrollbar{display:none;}
.feed-discover-tab{
  position:relative;
  flex:1 0 auto;
  min-width:max-content;
  padding:6px 10px 10px;
  color:var(--public-muted, #667085);
  font-size:13px;
  font-weight:400;
  line-height:1.2;
  text-align:center;
  text-decoration:none;
  white-space:nowrap;
}
.feed-discover-tab:hover,
.feed-discover-tab:focus{
  color:var(--public-text, #0d0d0d);
  background:rgba(127,127,127,.07);
  text-decoration:none;
  outline:none;
}
.feed-discover-tab.is-active{
  color:var(--public-text, #0d0d0d);
  font-size:13px;
  font-weight:400;
}
.feed-discover-tab.is-active::after{
  content:"";
  position:absolute;
  left:50%;
  bottom:0;
  width:40px;
  max-width:70%;
  height:3px;
  border-radius:999px;
  background:#1d9bf0;
  transform:translateX(-50%);
}
html{
  scrollbar-gutter:stable;
}
@view-transition{
  navigation:auto;
}
/* Keep chrome fully opaque during navigation — no header/tab fade flash */
::view-transition-old(root),
::view-transition-new(root),
::view-transition-old(msb-feed-header),
::view-transition-new(msb-feed-header),
::view-transition-old(msb-feed-tabs),
::view-transition-new(msb-feed-tabs){
  animation:msb-page-hold .01s linear both !important;
}
.ig-feed-header{
  view-transition-name:msb-feed-header;
}
.feed-top-search{
  view-transition-name:msb-feed-tabs;
}
@keyframes msb-page-hold{
  from{opacity:1;}
  to{opacity:1;}
}
@media (prefers-reduced-motion:reduce){
  ::view-transition-old(root),
  ::view-transition-new(root),
  ::view-transition-old(msb-feed-header),
  ::view-transition-new(msb-feed-header),
  ::view-transition-old(msb-feed-tabs),
  ::view-transition-new(msb-feed-tabs){
    animation:none !important;
  }
}
.feed-discover-tabs.is-loading{
  pointer-events:none;
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
  width:35px !important;
  height:35px !important;
  flex:0 0 35px !important;
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
    --feed-center-w:614px;
    --feed-side-gap:28px;
    --feed-left-nav-w:236px;
    --feed-right-rail-w:300px;
    --feed-main-inset:0px;
    /* sh-mainpanel already clears the icon rail; this is the in-panel offset only. */
    --feed-center-left:calc(8px + var(--feed-left-nav-w) + var(--feed-side-gap) + var(--feed-main-inset));
    /* Viewport X used by position:fixed rails */
    --feed-mainpanel-left:var(--feedRailW, 84px);
  }
  body.feed-insta-ui .feed-left-rail{
    display:flex;
    flex-direction:column;
    position:fixed;
    left:calc(
      var(--feed-mainpanel-left)
      + max(0px, (var(--feed-left-column-w, calc(8px + var(--feed-left-nav-w))) - var(--feed-left-nav-w)) / 2)
    );
    top:var(--feed-left-rail-top, 220px);
    width:var(--feed-left-nav-w);
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
    height:auto;
    max-height:none;
    overflow-y:auto;
    overflow-x:hidden;
    padding:0 2px 0 0;
    -webkit-overflow-scrolling:touch;
    overscroll-behavior:contain;
    touch-action:pan-y;
    scrollbar-width:thin;
    scrollbar-color:rgba(0,0,0,.18) transparent;
  }
  body.feed-insta-ui .feed-left-rail-footer{
    display:flex;
    flex:0 0 auto;
    flex-direction:column;
    gap:2px;
    padding:6px 2px 0 0;
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
    background:var(--msb-palette-nav-active-bg, #bdc4cd);
    color:var(--msb-palette-nav-active-text, #787c87);
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
    /* margin-left:12px; */
    /* padding-left:20px; */
    min-height:38px;
    font-size:13px;
  }
  body.feed-insta-ui .ig-feed-header{
    display:flex !important;
    justify-content:center !important;
    align-items:flex-start !important;
    padding-left:16px;
    padding-right:16px;
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
    margin-left:max(
      var(--feed-center-left),
      calc((100% - var(--feed-center-w)) / 2)
    );
    margin-right:auto;
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
    /* Sit in the empty column to the right of the (centered) feed. */
    left:calc(
      var(--feed-mainpanel-left)
      + max(
          var(--feed-center-left),
          (100vw - var(--feed-mainpanel-left) - var(--feed-center-w)) / 2
        )
      + var(--feed-center-w)
      + var(--feed-side-gap)
    ) !important;
    right:auto !important;
    top:154px;
    width:var(--feed-right-rail-w);
    height:calc(100vh - 170px);
    max-height:calc(100vh - 170px);
    z-index:90;
    padding:0;
    box-sizing:border-box;
  }
  body.public-page.feed-insta-ui.public-suggestions-visible .feed-right-rail{
    display:flex !important;
    flex-direction:column !important;
    visibility:visible !important;
    opacity:1 !important;
    top:154px !important;
    height:calc(100vh - 170px) !important;
    max-height:calc(100vh - 170px) !important;
    overflow-y:auto !important;
    left:calc(
      var(--feed-mainpanel-left, var(--feedRailW, 84px))
      + max(
          var(--feed-center-left),
          (100vw - var(--feed-mainpanel-left, var(--feedRailW, 84px)) - var(--feed-center-w, 614px)) / 2
        )
      + var(--feed-center-w, 614px)
      + var(--feed-side-gap, 28px)
    ) !important;
    right:auto !important;
  }
  body.feed-insta-ui .jump-rail{
    top:auto;
    bottom:120px;
    right:24px;
    transform:none;
  }
  body.feed-insta-ui .jump-rail .jump-rail-video{
    right:24px;
    bottom:28px;
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
    /* Bond light surface flush to the icon rail (drop the +16px gutter). */
    margin-left:var(--feedRailW, 84px) !important;
    width:calc(100% - var(--feedRailW, 84px)) !important;
    max-width:calc(100% - var(--feedRailW, 84px)) !important;
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
    padding:12px 24px 0 !important;
    border-bottom:1px solid var(--public-border-strong, rgba(15,23,42,.16)) !important;
  }
  body.feed-insta-ui .feed-desktop-center > .feed-top-search,
  body.public-page.feed-insta-ui .feed-desktop-center > .feed-top-search{
    position:relative !important;
    top:auto !important;
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
    display:flex !important;
    flex-direction:column !important;
    height:100% !important;
    max-height:100% !important;
    overflow:hidden !important;
  }
  /* public.php / news.php use .ig-feed; feed.php uses .mf-feed */
  body.feed-insta-ui .feed-desktop-center > .mf-feed,
  body.feed-insta-ui .feed-desktop-layout .mf-feed,
  body.public-page.feed-insta-ui .feed-desktop-center > .ig-feed,
  body.public-page.feed-insta-ui .feed-desktop-layout .ig-feed{
    flex:1 1 auto !important;
    min-height:0 !important;
    overflow-y:auto !important;
    overflow-x:hidden !important;
    -webkit-overflow-scrolling:touch;
    overscroll-behavior:contain;
    scrollbar-width:thin;
    scrollbar-color:rgba(0,0,0,.22) transparent;
  }
  body.feed-insta-ui .feed-desktop-center > .mf-feed::-webkit-scrollbar,
  body.feed-insta-ui .feed-desktop-layout .mf-feed::-webkit-scrollbar,
  body.public-page.feed-insta-ui .feed-desktop-center > .ig-feed::-webkit-scrollbar,
  body.public-page.feed-insta-ui .feed-desktop-layout .ig-feed::-webkit-scrollbar{width:6px;}
  body.feed-insta-ui .feed-desktop-center > .mf-feed::-webkit-scrollbar-thumb,
  body.feed-insta-ui .feed-desktop-layout .mf-feed::-webkit-scrollbar-thumb,
  body.public-page.feed-insta-ui .feed-desktop-center > .ig-feed::-webkit-scrollbar-thumb,
  body.public-page.feed-insta-ui .feed-desktop-layout .ig-feed::-webkit-scrollbar-thumb{
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
    /* Full-width shell so the header bottom border can meet the icon-rail line. */
    margin-left:0 !important;
    width:100% !important;
    max-width:100% !important;
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
    margin-left:var(--feedRailW, 84px) !important;
    width:calc(100% - var(--feedRailW, 84px)) !important;
    max-width:calc(100% - var(--feedRailW, 84px)) !important;
  }
  body.public-page.feed-insta-ui .feed-desktop-center{
    display:flex !important;
    flex-direction:column !important;
    height:100% !important;
    max-height:100% !important;
    overflow:hidden !important;
  }
  body.public-page.feed-insta-ui .feed-desktop-center > .mf-feed,
  body.public-page.feed-insta-ui .feed-desktop-center > .ig-feed{
    flex:1 1 auto !important;
    min-height:0 !important;
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
      --post-media-radius:10px;
      --post-media-max:680px;
      --post-phone-max:430px;
      --post-tablet-max:620px;
      --post-landscape-max:760px;
      --post-square-max:620px;
      --post-portrait-max:400px;
      --post-media-max-height: min(70vh, 580px);
    }
    .ig-feed{
      --post-media-max-height: min(70vh, 580px);
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
    .post.public-post-card.is-single-video-post:not(.is-reel-post):not(.public-media-head-outside) .media-stage.standard-video-stage,
    .post.public-post-card.is-single-image-post:not(.is-reel-post):not(.public-media-head-outside) .media-stage.standard-image-stage{
      width:min(100%, var(--post-media-card-width, var(--post-media-max))) !important;
      max-width:100% !important;
      max-height:var(--post-media-max-height, min(70vh, 580px)) !important;
      margin-left:0 !important;
      margin-right:auto !important;
    }
    .post.public-post-card.public-media-head-outside.is-single-video-post:not(.is-reel-post) .media-stage.standard-video-stage,
    .post.public-post-card.public-media-head-outside.is-single-image-post:not(.is-reel-post) .media-stage.standard-image-stage{
      width:100% !important;
      max-width:100% !important;
      max-height:none !important;
      margin-left:0 !important;
      margin-right:0 !important;
      overflow:visible !important;
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
      overflow:hidden !important;
      aspect-ratio:auto !important;
      height:auto !important;
    }
    .post.public-post-card:not(.is-reel-post) .media-stage.standard-video-stage > video,
    .post.public-post-card:not(.is-reel-post) .media-stage.standard-image-stage > img{
      width:100% !important;
      height:auto !important;
      max-height:var(--post-media-max-height, min(70vh, 580px)) !important;
      object-fit:contain !important;
      object-position:center center !important;
      border:0 !important;
      border-radius:var(--post-media-radius) !important;
      background:transparent !important;
    }
    .post.public-post-card:not(.is-reel-post) .media-stage.single-portrait,
    .post.public-post-card:not(.is-reel-post) .media-stage.single-landscape,
    .post.public-post-card:not(.is-reel-post) .media-stage.single-square{
      aspect-ratio:auto !important;
      max-height:var(--post-media-max-height, min(70vh, 580px)) !important;
      overflow:hidden !important;
    }
    .post.public-post-card:not(.is-reel-post) .media-stage.single-portrait > img,
    .post.public-post-card:not(.is-reel-post) .media-stage.single-portrait > video,
    .post.public-post-card:not(.is-reel-post) .media-stage.single-landscape > img,
    .post.public-post-card:not(.is-reel-post) .media-stage.single-landscape > video,
    .post.public-post-card:not(.is-reel-post) .media-stage.single-square > img,
    .post.public-post-card:not(.is-reel-post) .media-stage.single-square > video,
    .post.public-post-card:not(.is-reel-post) .media-slide > img,
    .post.public-post-card:not(.is-reel-post) .media-slide > video{
      width:100% !important;
      height:auto !important;
      max-height:var(--post-media-max-height, min(70vh, 580px)) !important;
      object-fit:contain !important;
      object-position:center center !important;
    }
    @media (max-width:767.98px){
      .ig-feed,
      .post.public-post-card{
        --post-media-max-height: min(52vh, 580px);
        --post-phone-max:320px;
        --post-portrait-max:310px;
      }
      .post.public-post-card.is-single-video-post:not(.is-reel-post) .media-stage.standard-video-stage:not(.phone-shot),
      .post.public-post-card.is-single-image-post:not(.is-reel-post) .media-stage.standard-image-stage:not(.phone-shot){
        width:min(100%, var(--post-media-card-width, 310px)) !important;
        max-width:min(100%, 330px) !important;
      }
      .post.public-post-card:not(.is-reel-post) .media-stage.phone-shot{
        width:min(72vw,var(--post-phone-max)) !important;
        max-width:100% !important;
        max-height:var(--post-media-max-height, min(52vh, 580px)) !important;
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
    @media (min-width:768px) and (max-width:1024.98px){
      .ig-feed,
      .post.public-post-card{
        --post-media-max-height: min(54vh, 580px);
      }
      .post.public-post-card.is-single-video-post:not(.is-reel-post) .media-stage.standard-video-stage:not(.phone-shot),
      .post.public-post-card.is-single-image-post:not(.is-reel-post) .media-stage.standard-image-stage:not(.phone-shot){
        width:min(100%, var(--post-media-card-width, 400px)) !important;
        max-width:min(100%, 440px) !important;
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
        max-height:var(--post-media-max-height, min(70vh, 580px)) !important;
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
        max-height:var(--post-media-max-height, min(70vh, 580px)) !important;
        margin-left:0 !important;
        margin-right:auto !important;
      }
    }
    .post.public-post-card:not(.is-reel-post) .media-stage.has-carousel{
      max-height:var(--post-media-max-height, min(70vh, 580px)) !important;
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
        max-height:var(--post-media-max-height, min(52vh, 580px)) !important;
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
<style id="shared-feed-public-chrome-lock-css"><?php include __DIR__ . '/includes/feed_public_chrome_lock.css.php'; ?></style>
<?php post_card_actions_menu_render_css(); ?>
<?php include __DIR__ . '/includes/post_viewer_gallery_chrome.css.php'; ?>
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
/* Head-outside: pin fries to the far right of the full-width header. */
body.public-page.feed-insta-ui .post.public-post-card.public-media-head-outside:not(.is-reel-post) .standard-media-topbar > .post-card-menu-wrap,
html[data-msb-appearance] body.public-page.feed-insta-ui .post.public-post-card.public-media-head-outside:not(.is-reel-post) .standard-media-topbar > .post-card-menu-wrap,
html[data-msb-appearance] body .post.public-post-card.public-media-head-outside:not(.is-reel-post) .standard-media-topbar > .post-card-menu-wrap{
  position:absolute !important;
  top:50% !important;
  right:0 !important;
  left:auto !important;
  bottom:auto !important;
  margin:0 !important;
  transform:translateY(-50%) !important;
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
  margin-right:-25px !important;
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
  min-width:var(--pcm-menu-btn-size, 24px)!important;
  min-height:var(--pcm-menu-btn-size, 24px)!important;
  padding:4px 2px!important;
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
}
body.public-page .post.public-post-card .standard-text-topbar .post-card-menu-btn,
body.public-page .post.public-post-card .post-card-head-actions .post-card-menu-btn,
body.news-page .post.public-post-card .standard-text-topbar .post-card-menu-btn{
  color:var(--msb-fries, var(--msb-palette-text, #0f172a))!important;
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
  font-size:12px!important;
  line-height:1!important;
  transform:none!important;
}
body.public-page .post.public-post-card .post-card-menu-btn .pcm-fries-icon,
body.news-page .post.public-post-card .post-card-menu-btn .pcm-fries-icon{
  width:10px!important;
  gap:2px!important;
}
body.public-page .post.public-post-card .post-card-menu-btn .pcm-fries-bar,
body.news-page .post.public-post-card .post-card-menu-btn .pcm-fries-bar{
  height:1.25px!important;
  width:10px!important;
  filter:none!important;
  -webkit-filter:none!important;
  box-shadow:none!important;
  text-shadow:none!important;
}
body.public-page .post.public-post-card .post-card-menu-btn .pcm-fries-bar--short,
body.news-page .post.public-post-card .post-card-menu-btn .pcm-fries-bar--short{
  width:6px!important;
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
<body class="public-page feed-insta-ui public-suggestions-visible<?= $isNewsSurface ? ' news-page' : '' ?><?= defined('MSB_HOME_PAGE') ? ' home-page' : '' ?>">
<?php require __DIR__ . '/includes/register_welcome_modal.php'; ?>
<?php $GLOBALS['msb_skip_header_leftbar'] = true; $forceFeedRail = true; $skipHeaderThemeBootstrap = true; include __DIR__ . '/includes/header.php'; ?>
<?php $feedLeftRailActive = isset($publicNavTabs[$discoverTab]) ? $discoverTab : $selfPage; $feedLeftRailCanFollow = $canFollowPublishers; include __DIR__ . '/includes/feed_left_rail.php'; ?>
  <div class="sh-mainpanel">
  <?php include __DIR__ . '/includes/leftbar.php'; ?>
  <?php include __DIR__ . '/includes/stories_right_door.php'; ?>
  <div class="sh-pagebody">
    <div class="ig-feed-header">
      <?php include __DIR__ . '/includes/feed_top_user_lead.php'; ?>
      <div class="ig-stories-wrap">
        <div class="ig-stories-bar<?= empty($publicStoryCatalog) ? ' is-empty' : '' ?>" aria-label="Moments">
          <div class="ig-stories-track<?= empty($publicStoryCatalog) ? ' is-empty' : '' ?><?= $staffReadonly ? '' : ' has-create' ?>" id="igStoriesTrack">
            <?php if (!$staffReadonly): ?>
              <a class="ig-story-item ig-story-create" href="dashboard.php?modal=1&amp;story=1" data-create-post-modal="1" aria-label="Create a story">
                <div class="ig-story-ring ig-story-ring-create"><i class="icon ion-plus" aria-hidden="true"></i></div>
              </a>
            <?php endif; ?>
            <?php if (empty($publicStoryCatalog)): ?>
              <div class="ig-story-item ig-story-empty" role="status" aria-label="No stories available"><div class="ig-story-ring ig-story-ring-empty"><span class="ig-story-empty-icon" aria-hidden="true"><i class="icon ion-ios-book-outline"></i></span></div><span class="ig-story-name"></span></div>
            <?php endif; ?>
          </div>
          <button type="button" class="ig-stories-next" aria-label="Next stories" onclick="var t=document.getElementById('igStoriesTrack');if(t){t.scrollBy({left:140,behavior:'smooth'});}"><i class="fa fa-chevron-right"></i></button>
        </div>
      </div>
      <?php include __DIR__ . '/includes/feed_top_actions.php'; ?>
    </div>
    <div class="feed-desktop-layout">
      <div class="feed-side-search" aria-label="Search posts">
        <form class="feed-top-search-form feed-side-search-form" method="get" action="<?= h($selfPage) ?>">
          <input type="hidden" name="tab" value="<?= h(home_tab_url_key($discoverTab)) ?>">
          <div class="feed-top-search-field">
            <button type="submit" class="feed-top-search-icon" aria-label="Search">
              <i class="fa fa-search" aria-hidden="true"></i>
            </button>
            <input
              type="search"
              name="q"
              class="feed-top-search-input"
              value="<?= h($q) ?>"
              placeholder="<?= $isNewsSurface ? 'Search news' : 'Search' ?>"
              autocomplete="off"
              enterkeyhint="search"
            >
          </div>
        </form>
      </div>
      <div class="feed-desktop-center">
        <div class="feed-top-search feed-top-search--tabs-only" aria-label="Explore posts">
          <div class="feed-top-search-row feed-top-tabs-row">
            <nav class="feed-discover-tabs" aria-label="Explore categories">
              <?php foreach ($discoverTabs as $tabKey => $tabLabel): ?>
                <?php
                  $tabQuery = [];
                  if ($q !== '') {
                      $tabQuery['q'] = $q;
                  }
                  $isOptionalDiscoverTab = isset($optionalDiscoverTabs[$tabKey]);
                  $optionalTabActive = $isOptionalDiscoverTab && $discoverTab === $tabKey;
                  $tabHref = home_tab_url($tabKey, $tabQuery);
                ?>
                <a
                  class="feed-discover-tab<?= $discoverTab === $tabKey ? ' is-active' : '' ?><?= $isOptionalDiscoverTab ? ' feed-program-tab-item' : '' ?>"
                  href="<?= h($tabHref) ?>"
                  <?= $isOptionalDiscoverTab ? 'data-program-slug="' . h($tabKey) . '"' : '' ?>
                  <?= $discoverTab === $tabKey ? 'aria-current="page"' : '' ?>
                  <?= ($isOptionalDiscoverTab && !$optionalTabActive) ? 'hidden' : '' ?>
                ><?= h($tabLabel) ?></a>
              <?php endforeach; ?>
            </nav>
            <a class="feed-top-search-settings" href="profile.php?tab=gear" aria-label="Explore settings" title="Settings">
              <i class="fa fa-cog" aria-hidden="true"></i>
            </a>
          </div>
        </div>
        <script>
        (function(){
          var tabs = document.querySelector('.feed-discover-tabs');
          var storageKey = 'msbDiscoverTabsScroll';
          if(tabs){
            try{
              var saved = Number(sessionStorage.getItem(storageKey) || 0);
              if(saved > 0) tabs.scrollLeft = saved;
            }catch(e){}
          }
          var requestController = null;
          var tabHtmlCache = Object.create(null);
          var tabHtmlRequests = Object.create(null);
          window.msbClearDiscoverTabCache = function(){
            tabHtmlCache = Object.create(null);
            tabHtmlRequests = Object.create(null);
          };
          function tabKeyFromLink(link){
            try{
              return new URL(link.href, window.location.href).searchParams.get('tab') || 'for-you';
            }catch(err){
              return 'for-you';
            }
          }
          function isCircleTabKey(tab){
            return tab === 'for-you';
          }
          function activateDiscoverTabs(selected){
            if(!tabs) return;
            tabs.querySelectorAll('.feed-discover-tab').forEach(function(tab){
              var active = tabKeyFromLink(tab) === selected;
              tab.classList.toggle('is-active', active);
              if(active) tab.setAttribute('aria-current', 'page');
              else tab.removeAttribute('aria-current');
            });
          }
          function activateLeftProgramNav(selected){
            document.querySelectorAll('a.feed-program-nav-item').forEach(function(link){
              var slug = link.getAttribute('data-program-slug') || tabKeyFromLink(link);
              var active = slug === selected || (selected === 'discover' && slug === 'public') || (selected === 'public' && slug === 'public');
              link.classList.toggle('is-active', active);
              if(active) link.setAttribute('aria-current', 'page');
              else link.removeAttribute('aria-current');
            });
          }
          function activateChromeForTab(selected){
            activateDiscoverTabs(selected);
            activateLeftProgramNav(selected);
          }
          function prefetchTab(link){
            if(!link || !window.fetch || tabHtmlCache[link.href] || tabHtmlRequests[link.href]) return;
            if(isCircleTabKey(tabKeyFromLink(link))) return;
            var prefetchUrl = new URL(link.href, window.location.href);
            prefetchUrl.searchParams.set('ajax_discover', '1');
            tabHtmlRequests[link.href] = fetch(prefetchUrl.href, {
              credentials:'same-origin',
              headers:{'X-Requested-With':'XMLHttpRequest'}
            }).then(function(response){
              if(!response.ok) throw new Error('Prefetch failed');
              return response.text();
            }).then(function(html){
              tabHtmlCache[link.href] = html;
              return html;
            }).catch(function(){
              return '';
            }).finally(function(){
              delete tabHtmlRequests[link.href];
            });
          }
          function prefetchNeighbors(){
            if(!tabs) return;
            var links = Array.prototype.slice.call(tabs.querySelectorAll('.feed-discover-tab'));
            var activeIndex = links.findIndex(function(tab){ return tab.classList.contains('is-active'); });
            if(activeIndex > 0) prefetchTab(links[activeIndex - 1]);
            if(activeIndex >= 0 && activeIndex + 1 < links.length) prefetchTab(links[activeIndex + 1]);
          }
          function prefetchRemainingTabs(){
            if(!tabs) return;
            var links = Array.prototype.slice.call(tabs.querySelectorAll('.feed-discover-tab'));
            links.forEach(function(link, index){
              if(link.classList.contains('is-active')) return;
              window.setTimeout(function(){ prefetchTab(link); }, 120 * index);
            });
          }
          function softSwapCenter(link){
            if(!link || !window.fetch || !window.DOMParser){
              window.location.assign(link.href);
              return;
            }
            var selectedTab = tabKeyFromLink(link);
            if(isCircleTabKey(selectedTab)){
              window.location.assign(link.href);
              return;
            }
            var currentTab = new URL(window.location.href).searchParams.get('tab') || 'discover';
            if(selectedTab === currentTab && link.classList.contains('is-active')) return;
            if(tabs){
              try{ sessionStorage.setItem(storageKey, String(tabs.scrollLeft || 0)); }catch(err){}
              tabs.classList.add('is-loading');
            }
            activateChromeForTab(selectedTab);
            if(requestController) requestController.abort();
            requestController = window.AbortController ? new AbortController() : null;
            var htmlRequest = tabHtmlCache[link.href]
              ? Promise.resolve(tabHtmlCache[link.href])
              : (tabHtmlRequests[link.href] || (function(){
                  var fragmentUrl = new URL(link.href, window.location.href);
                  fragmentUrl.searchParams.set('ajax_discover', '1');
                  return fetch(fragmentUrl.href, {
                    credentials:'same-origin',
                    headers:{'X-Requested-With':'XMLHttpRequest'},
                    signal:requestController ? requestController.signal : undefined
                  });
                })().then(function(response){
                  if(!response.ok) throw new Error('Tab request failed');
                  return response.text();
                }).then(function(html){
                  tabHtmlCache[link.href] = html;
                  return html;
                }));
            htmlRequest.then(function(html){
              var nextDoc = new DOMParser().parseFromString(html, 'text/html');
              var currentFeed = document.querySelector('.feed-desktop-center > .ig-feed');
              var nextFeed = nextDoc.querySelector('.ig-feed');
              if(!currentFeed || !nextFeed) throw new Error('Tab content unavailable');
              var previousScrollBehavior = currentFeed.style.scrollBehavior;
              currentFeed.style.scrollBehavior = 'auto';
              currentFeed.scrollTop = 0;
              currentFeed.classList.add('public-media-hydrating');
              currentFeed.innerHTML = nextFeed.innerHTML;
              if(typeof window.msbBootPublicMediaCards === 'function'){
                window.msbBootPublicMediaCards();
              }
              document.dispatchEvent(new CustomEvent('msb:public-tab-content-ready', {
                detail:{tab:selectedTab}
              }));
              var jumpRail = document.querySelector('.jump-rail');
              if(jumpRail) jumpRail.classList.toggle('is-hidden', selectedTab === 'for-you');
              var searchForm = document.querySelector('.feed-top-search-form');
              if(searchForm){
                searchForm.action = new URL(link.href, window.location.href).pathname;
                var tabInput = searchForm.querySelector('input[name="tab"]');
                if(tabInput) tabInput.value = selectedTab;
              }
              document.body.classList.toggle('news-page', selectedTab === 'news' || new URL(link.href, window.location.href).pathname.endsWith('/news.php'));
              document.body.classList.add('public-suggestions-visible');
              history.pushState({msbDiscover:true}, '', link.href);
              document.title = nextDoc.title || document.title;
              currentFeed.scrollTop = 0;
              currentFeed.style.scrollBehavior = previousScrollBehavior;
              prefetchNeighbors();
            }).catch(function(error){
              if(error && error.name === 'AbortError') return;
              window.location.assign(link.href);
            }).finally(function(){
              if(tabs) tabs.classList.remove('is-loading');
            });
          }
          if(tabs){
            tabs.addEventListener('pointerover', function(e){
              prefetchTab(e.target.closest('.feed-discover-tab'));
            });
            window.setTimeout(prefetchNeighbors, 100);
            tabs.addEventListener('click', function(e){
              var link = e.target.closest('.feed-discover-tab');
              if(!link) return;
              if(link.classList.contains('is-active')){
                e.preventDefault();
                return;
              }
              if(isCircleTabKey(tabKeyFromLink(link))){
                e.preventDefault();
                window.location.assign(link.href);
                return;
              }
              e.preventDefault();
              softSwapCenter(link);
            });
          }
          document.addEventListener('click', function(e){
            var link = e.target.closest('a.feed-program-nav-item');
            if(!link) return;
            if(link.hasAttribute('hidden') || link.getAttribute('aria-hidden') === 'true') return;
            try{
              var hrefUrl = new URL(link.href, window.location.href);
              if(!/home\.php$/i.test(hrefUrl.pathname)) return;
            }catch(err){
              return;
            }
            if(link.classList.contains('is-active')){
              e.preventDefault();
              return;
            }
            if(isCircleTabKey(tabKeyFromLink(link))){
              e.preventDefault();
              window.location.assign(link.href);
              return;
            }
            e.preventDefault();
            softSwapCenter(link);
          });
          window.addEventListener('popstate', function(){
            window.location.reload();
          });
        })();
        </script>
        <?php if ($canFollowPublishers && !$isNewsSurface && $discoverTab !== 'public' && $discoverTab !== 'for-you'): ?>
          <?php
            $publisherSearchQuery = $q;
            include __DIR__ . '/includes/publisher_search_panel.php';
          ?>
        <?php endif; ?>
        <?php ob_start(); ?>
        <section class="ig-feed public-media-hydrating">
      <?php if (!$posts): ?>
        <?php
          $publicEmptyTitles = [
              'public' => 'No People Posts Available',
              'enterprise' => 'No Commerce Posts Available',
              'news' => 'No News Posts Available',
              'sports' => 'No Sports Posts Available',
              'business' => 'No Business Posts Available',
              'science' => 'No Science Posts Available',
              'music' => 'No Music Posts Available',
              'arts' => 'No Arts & Painting Posts Available',
              'agriculture' => 'No Agriculture Posts Available',
              'auto' => 'No Auto Posts Available',
              'political' => 'No Political Posts Available',
              'entertainment' => 'No Entertainment Posts Available',
              'library' => 'No Library Posts Available',
              'cook' => 'No Cook Posts Available',
              'seek-around-the-world' => 'No Seek around the World Posts Available',
              'geology' => 'No Geology Posts Available',
              'animation' => 'No Animation Posts Available',
              'make-a-new-friend' => 'No Make a new Friend Posts Available',
              'agents' => 'No Agents Posts Available',
              'deep-research' => 'No Deep research Posts Available',
              'trending' => 'No Trending Posts Available',
              'for-you' => 'No Feed Available',
          ];
          $publicEmptyTitle = $publicEmptyTitles[$discoverTab]
              ?? (isset($publicNavTabs[$discoverTab]) ? 'No ' . $publicNavTabs[$discoverTab] . ' Posts Available' : 'No Feed Available');
          $publicEmptyNavIcons = [
              'for-you' => '<path d="m3 11 9-8 9 8"/><path d="M5 10v10h14V10"/><path d="M9 20v-6h6v6"/>',
              'public' => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18"/><path d="M12 3a14 14 0 0 1 0 18"/><path d="M12 3a14 14 0 0 0 0 18"/>',
              'enterprise' => '<rect x="4" y="8" width="16" height="12" rx="1"/><path d="M8 8V4h8v4"/><path d="M8 12h2M14 12h2M8 16h2M14 16h2"/>',
              'trending' => '<path d="m3 17 6-6 4 4 8-9"/><path d="M15 6h6v6"/>',
              'news' => '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M7 8h6M7 12h10M7 16h10M16 8h1"/>',
              'sports' => '<circle cx="12" cy="12" r="9"/><path d="m8.5 4.8 1.7 3.4-2.7 2.6-3.7-.6M15.5 4.8l-1.7 3.4 2.7 2.6 3.7-.6M7.5 10.8l1.2 4h6.6l1.2-4M8.7 14.8 6.5 18M15.3 14.8l2.2 3.2"/>',
              'business' => '<rect x="3" y="7" width="18" height="13" rx="2"/><path d="M8 7V4h8v3M3 12h18M9 12v2h6v-2"/>',
              'science' => '<path d="M9 3h6M10 3v5l-5 9a2 2 0 0 0 1.7 3h10.6a2 2 0 0 0 1.7-3l-5-9V3"/><path d="M7.5 15h9"/>',
              'music' => '<path d="M9 18V5l10-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="16" cy="16" r="3"/>',
              'arts' => '<path d="M12 3a9 9 0 1 0 0 18h1.5a2 2 0 0 0 0-4H12a1.5 1.5 0 0 1 0-3h3a6 6 0 0 0 6-6c0-3-4-5-9-5z"/><circle cx="7.5" cy="10" r="1"/><circle cx="10" cy="6.5" r="1"/><circle cx="15" cy="7" r="1"/>',
              'agriculture' => '<path d="M12 21V9"/><path d="M12 13C7 13 4 10 4 5c5 0 8 3 8 8z"/><path d="M12 17c5 0 8-3 8-8-5 0-8 3-8 8z"/>',
              'auto' => '<path d="M5 17h14l1-5-3-5H7l-3 5z"/><circle cx="7" cy="17" r="2"/><circle cx="17" cy="17" r="2"/><path d="M6 12h12"/>',
              'political' => '<path d="M3 10h18M5 10v8M9 10v8M15 10v8M19 10v8M3 18h18M12 3l9 5H3z"/>',
              'entertainment' => '<path d="M8 3v18"/><path d="M16 3v18"/><path d="M3 8h18"/><path d="M3 16h18"/><rect x="3" y="3" width="18" height="18" rx="2"/>',
              'library' => '<path d="M4 5.5A2.5 2.5 0 0 1 6.5 3H11v16H6.5A2.5 2.5 0 0 0 4 21.5z"/><path d="M20 5.5A2.5 2.5 0 0 0 17.5 3H13v16h4.5a2.5 2.5 0 0 1 2.5 2.5z"/>',
              'cook' => '<path d="M6 3v7"/><path d="M3.5 3v4.5A2.5 2.5 0 0 0 6 10"/><path d="M8.5 3v4.5A2.5 2.5 0 0 1 6 10v11"/><path d="M15 3v18"/><path d="M15 3a5 5 0 0 1 5 5v4h-5"/>',
              'seek-around-the-world' => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18"/><path d="M12 3a14 14 0 0 1 0 18"/><path d="M12 3a14 14 0 0 0 0 18"/>',
              'geology' => '<path d="m3 20 6.5-11 3 5 2.5-4 6 10z"/><path d="m7.8 12 1.7 1.5 1.5-2"/><path d="M3 20h18"/>',
              'animation' => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m10 9 5 3-5 3z"/><path d="M7 5v14"/><path d="M17 5v14"/>',
              'make-a-new-friend' => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
              'agents' => '<rect x="5" y="8" width="14" height="10" rx="3"/><path d="M9 8V6a3 3 0 0 1 6 0v2"/><circle cx="10" cy="13" r="1"/><circle cx="14" cy="13" r="1"/><path d="M10 16h4"/>',
              'deep-research' => '<path d="M3 20l6-6"/><path d="M14 4l6 6"/><path d="M9 15l-2 5 5-2 8-8-3-3-8 8z"/><circle cx="18" cy="6" r="2"/>',
          ];
          $publicEmptyNavIcon = $publicEmptyNavIcons[$discoverTab]
              ?? (isset(publisher_academic_categories()[$discoverTab]) ? publisher_category_icon_path($discoverTab) : '');
        ?>
        <div class="mf-feed-empty" role="status">
          <?php if ($publicEmptyNavIcon !== ''): ?>
            <svg class="mf-feed-empty-nav-icon" viewBox="0 0 24 24" aria-hidden="true"><?= $publicEmptyNavIcon ?></svg>
          <?php else: ?>
            <i class="icon ion-ios-paper-outline" aria-hidden="true"></i>
          <?php endif; ?>
          <div class="mf-feed-empty-title"><?= h($publicEmptyTitle) ?></div>
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
          if (function_exists('msb_display_post_text_without_tag_handles')) {
              $captionSource = msb_display_post_text_without_tag_handles(
                  trim($captionSource),
                  is_array($post['tagged_people'] ?? null) ? $post['tagged_people'] : []
              );
          }
          $caption = post_format_story_text(trim($captionSource));
          $legacyCaption = $caption;
          $legacyTitle = trim((string)$post['title']);
          if (function_exists('msb_display_post_text_without_tag_handles')) {
              $legacyTitle = msb_display_post_text_without_tag_handles(
                  $legacyTitle,
                  is_array($post['tagged_people'] ?? null) ? $post['tagged_people'] : []
              );
          }
          $slidePresentation = false;
          foreach ($attachments as $_sa) {
              if (trim((string)($_sa['slide_title'] ?? '')) !== '' || trim((string)($_sa['slide_body'] ?? '')) !== '') {
                  $slidePresentation = true;
                  break;
              }
          }
          // Super title + introduction stay fixed; slide fields sync separately.
          $displayTitle = $legacyTitle;
          $slide0Title = '';
          $slide0Body = '';
          if ($slidePresentation && !empty($attachments[0])) {
              $slide0Title = trim((string)($attachments[0]['slide_title'] ?? ''));
              $slide0Body = trim((string)($attachments[0]['slide_body'] ?? ''));
          }
          $likesTotal = (int)$post['love_count'] + (int)$post['like_count'];
          $postTitleText = $legacyTitle !== '' ? $legacyTitle : 'Post';
          $postAuthorText = trim((string)$post['display_name']) !== '' ? trim((string)$post['display_name']) : trim((string)$post['username']);
          $postDateText = (string)date('M j', strtotime((string)$post['updated_at']));
          $postAvatarText = user_avatar_label($post);
          $postAvatarUrl = user_avatar_url($post, 96);
          $taggedPeople = is_array($post['tagged_people'] ?? null) ? $post['tagged_people'] : [];
          $hasSharingWith = $taggedPeople !== [];

          $declaredLayout = strtolower(trim((string)($post['declared_layout'] ?? '')));
          if ($declaredLayout === '') {
              $declaredLayout = extract_layout_override_marker((string)$post['description']);
          }

          $isSingleMedia = (
            count($attachments) === 1 &&
            isset($attachments[0]) &&
            in_array((string)($attachments[0]['type'] ?? ''), ['video','image'], true)
          );
          $isReelOnly = false;
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
                  $maxVideoH = 580;
                  $desiredWidth = (int)round($deviceAspect * $maxVideoH);
                  $maxByShape = $deviceAspect < 0.8 ? 400 : ($deviceAspect > 1.15 ? 680 : 520);
                  $safeCardWidth = max(260, min($desiredWidth, 680, $maxByShape));
                  // Keep post card full-width (for dividers), constrain only the media stage.
                  $singleMediaCardStyle = '--post-media-card-width:' . $safeCardWidth . 'px;--post-media-max-height:min(70vh, 580px);';
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
          $postTimeLabel = (string)date('M j', strtotime((string)$post['updated_at']));
          $authorAfterHtml = '';
          if ($hasSharingWith) {
              $authorAfterHtml = '<span class="time msb-sharing-meta">• ' . h($postTimeLabel) . '</span>'
                . post_visibility_badge_html((string)($post['visibility'] ?? 'public'));
          }
          $authorNameHtml = function_exists('msb_post_sharing_with_name_html')
            ? msb_post_sharing_with_name_html($postAuthorText, $peerProfileHref, $taggedPeople, [
                'link_author' => true,
                'link_class' => 'msb-sharing-who',
                'muted_class' => 'msb-sharing-with',
                'after_author_html' => $authorAfterHtml,
              ])
            : ('<a class="msb-sharing-who" href="' . h($peerProfileHref) . '">' . h($postAuthorText) . '</a>' . $authorAfterHtml);
          $authorNameClass = $hasSharingWith ? ' is-sharing-with' : '';
          $pcmCtx = post_card_actions_menu_context($post, $meId, $dbh, $peerProfileHref, $staffReadonly, 'public');
          $pcmCtx['menu_surface'] = 'public';
          $pcmCtx['is_publisher'] = $isPublisher;
          $pcmCtx['is_following'] = $isFollowing;
          $pcmCtx['friend_status'] = $friendStatus;
          $pcmCtx['can_follow_publishers'] = $canFollowOnPublicMenu;
          $pcmCtx['publisher_workspace_viewer'] = $isPublisherWorkspaceViewer;
        ?>

        <?php
          $isPublicTextOnly = (!$isPublicLivePost && !$isReelOnly && !$isStandardMediaPost);
        ?>
        <article
          class="post public-post-card<?= $isStandardMediaPost ? ' public-media-head-outside' : '' ?><?= $isPublicTextOnly ? ' public-text-only' : '' ?><?= $isPublicLivePost ? ' is-live-post' : '' ?><?= $isReelOnly ? ' is-reel-post' : '' ?><?= $isSingleStandardVideo ? ' is-single-video-post' : '' ?><?= $isSingleStandardImage ? ' is-single-image-post' : '' ?><?= ($isSingleStandardVideo || $isSingleStandardImage) ? ' ' . h($shapeClass) : '' ?><?= $isMultiStandardMedia ? ' is-multi-media-post' : '' ?>"
          id="post-<?= (int)$post['id'] ?>"
          data-index="<?= (int)$index ?>"
          data-post-id="<?= (int)$post['id'] ?>"
          data-post-owner="<?= $isOwner ? '1' : '0' ?>"
          data-visibility="<?= h(post_visibility_normalize((string)($post['visibility'] ?? 'public'))) ?>"
          data-peer-id="<?= (int)$post['user_id'] ?>"
          data-peer-code="<?= h((string)$post['friend_code']) ?>"
          data-account-kind="<?= h((string)($post['account_kind'] ?? 'personal')) ?>"
          data-is-publisher="<?= $isPublisher ? '1' : '0' ?>"
          data-is-following="<?= $isFollowing ? '1' : '0' ?>"
          data-friend-status="<?= h($friendStatus) ?>"
          data-me-tagged="<?= !empty($post['me_tagged']) ? '1' : '0' ?>"
          data-contact-id="<?= (int)($post['contact_id'] ?? 0) ?>"
          data-contact-name="<?= h((string)($post['contact_name'] ?? '')) ?>"
          data-edit-url="dashboard.php?modal=1&edit=<?= (int)$post['id'] ?>"
          data-comment-count="<?= (int)$post['comment_count'] ?>"
          data-like-count="<?= (int)$post['like_count'] ?>"
          data-love-count="<?= (int)$post['love_count'] ?>"
          data-reaction-count="<?= (int)$post['love_count'] + (int)$post['like_count'] ?>"
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
              <div class="post-author-link<?= $authorNameClass ?>">
                <a class="post-author-avatar-link" href="<?= h($peerProfileHref) ?>" aria-label="Open <?= h($postAuthorText) ?> profile">
                  <div class="avatar"><span class="avatar-thumb"><img src="<?= h($postAvatarUrl) ?>" alt="<?= h($postAuthorText) ?>" onerror="this.onerror=null;this.src='avatar.php?name='+encodeURIComponent(this.alt||'U')+'&amp;s=96';"></span></div>
                </a>
                <div class="head-meta">
                  <div class="name-row">
                    <span class="name<?= $authorNameClass ?>"><?= $authorNameHtml ?></span>
                    <?php if (!$hasSharingWith): ?>
                    <span class="time">• <?= h($postTimeLabel) ?></span>
                    <?= post_visibility_badge_html((string)($post['visibility'] ?? 'public')) ?>
                    <?php endif; ?>
                  </div>
                </div>
              </div>

              <?php if (!$isOwner && !$isReelOnly): ?>
                <div class="post-card-head-actions">
                <?php if ($isPublisher && $canFollowPublishers && !$isFollowing): ?>
                <button
                  type="button"
                  class="publisher-follow-btn primary"
                  data-publisher-id="<?= (int)$post['user_id'] ?>"
                >Follow</button>
                <?php elseif (!$isPublisher && !$isPublisherWorkspaceViewer && $friendStatus !== 'friends'): ?>
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
              <div class="post-author-link<?= $authorNameClass ?>">
                <a class="post-author-avatar-link" href="<?= h($peerProfileHref) ?>" aria-label="Open <?= h($postAuthorText) ?> profile">
                  <div class="avatar"><span class="avatar-thumb"><img src="<?= h($postAvatarUrl) ?>" alt="<?= h($postAuthorText) ?>" onerror="this.onerror=null;this.src='avatar.php?name='+encodeURIComponent(this.alt||'U')+'&amp;s=96';"></span></div>
                </a>
                <div class="head-meta">
                  <div class="name-row">
                    <span class="name<?= $authorNameClass ?>"><?= $authorNameHtml ?></span>
                    <?php if (!$hasSharingWith): ?>
                    <span class="time">• <?= h($postTimeLabel) ?></span>
                    <?= post_visibility_badge_html((string)($post['visibility'] ?? 'public')) ?>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
              <?= post_card_actions_menu_shell_html($pcmCtx) ?>
            </div>
          <?php endif; ?>

          <?php if (!$isPublicLivePost && !$isReelOnly && !$isStandardMediaPost): ?>
            <div class="standard-text-card">
              <div class="standard-text-topbar">
                <div class="standard-text-author<?= $authorNameClass ?>">
                  <a class="post-author-avatar-link" href="<?= h($peerProfileHref) ?>" aria-label="Open <?= h($postAuthorText) ?> profile">
                    <div class="avatar"><span class="avatar-thumb"><img src="<?= h($postAvatarUrl) ?>" alt="<?= h($postAuthorText) ?>" onerror="this.onerror=null;this.src='avatar.php?name='+encodeURIComponent(this.alt||'U')+'&amp;s=96';"></span></div>
                  </a>
                  <div class="standard-text-meta">
                    <span class="standard-text-name<?= $authorNameClass ?>"><?= $authorNameHtml ?></span>
                    <?php if (!$hasSharingWith): ?>
                    <span class="standard-text-time">• <?= h($postTimeLabel) ?></span>
                    <?= post_visibility_badge_html((string)($post['visibility'] ?? 'public')) ?>
                    <?php endif; ?>
                  </div>
                </div>
                <div class="standard-text-top-actions post-card-head-actions">
                  <?php if (!$isOwner && (!$isPublisher || $canFollowPublishers)): ?>
                    <?php if ($isPublisher && $canFollowPublishers && !$isFollowing): ?>
                    <button type="button" class="publisher-follow-btn primary" data-publisher-id="<?= (int)$post['user_id'] ?>">Follow</button>
                    <?php elseif (!$isPublisher && !$isPublisherWorkspaceViewer && $friendStatus !== 'friends'): ?>
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
                <?php if ($displayTitle !== ''): ?>
                  <h3 class="standard-text-title"><?= h($displayTitle) ?></h3>
                <?php endif; ?>
                <?php if ($caption !== ''): ?>
                  <div class="standard-text-caption">
                    <?= public_caption_card_html($caption) ?>
                    <?php if (public_caption_needs_readmore($caption)): ?>
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
                <?= function_exists('msb_post_products_row_html') ? msb_post_products_row_html($post['products'] ?? []) : '' ?>
              </div>

              <div class="standard-text-meta-bar" hidden aria-hidden="true">
                <div class="standard-text-left">
                  <div class="standard-text-comments js-open-comments" data-post-id="<?= (int)$post['id'] ?>">
                    View all <span class="js-comment-count"><?= (int)$post['comment_count'] ?></span> comments
                  </div>
                </div>
                <div class="standard-text-right">
                  <div class="standard-text-views"><?= (int)$post['views_count'] ?> views</div>
                </div>
              </div>

              <div class="standard-text-actions">
                <div class="standard-text-left">
                  <div class="standard-text-row">
                    <span class="msb-react-cluster">
                      <a class="standard-text-btn js-react-love<?= public_reaction_is_love_lane((string)($post['my_reaction'] ?? '')) ? ' is-love' : '' ?>" type="button" aria-label="Love" data-post-id="<?= (int)$post['id'] ?>">
                        <?= post_action_thin_icon('heart', (string)($post['my_reaction'] ?? '') === 'love') ?>
                      </a>
                      <span class="action-count js-love-count js-open-reactors" data-rx-tab="love" data-post-id="<?= (int)$post['id'] ?>" role="button" tabindex="0" aria-label="See who reacted"><?= (int)$post['love_count'] + (int)$post['like_count'] ?></span>
                    </span>
                    <!-- <a class="standard-text-btn js-react-like<?= public_reaction_is_like_lane((string)($post['my_reaction'] ?? '')) ? ' is-like' : '' ?>" type="button" aria-label="Like" data-post-id="<?= (int)$post['id'] ?>">
                      <i class="fa <?= ((string)($post['my_reaction'] ?? '') === 'like') ? 'fa-thumbs-up' : 'fa-thumbs-o-up' ?>"></i>
                      <span class="action-count js-like-count"><?= (int)$post['like_count'] ?></span>
                    </a> -->
                    <a class="standard-text-btn js-open-comments" type="button" aria-label="Comment" data-post-id="<?= (int)$post['id'] ?>">
                      <?= post_action_thin_icon('comment') ?>
                      <span class="action-count js-comment-count-inline"><?= (int)$post['comment_count'] ?></span>
                    </a>
                    <span class="msb-react-cluster">
                      <a class="standard-text-btn js-share-post<?= !empty($post['my_shared']) ? ' is-share' : '' ?>" type="button" aria-label="Share" data-post-id="<?= (int)$post['id'] ?>">
                        <?= post_action_thin_icon('share', !empty($post['my_shared'])) ?>
                      </a>
                      <span class="action-count js-share-count js-open-reactors" data-rx-tab="share" data-post-id="<?= (int)$post['id'] ?>" role="button" tabindex="0" aria-label="See who shared"><?= (int)($post['share_count'] ?? 0) ?></span>
                    </span>
                  </div>
                </div>
                <div class="standard-text-right">
                  <span class="msb-react-cluster">
                    <a class="standard-text-btn js-save-post<?= !empty($post['my_saved']) ? ' is-save' : '' ?>" type="button" aria-label="Favorite" data-post-id="<?= (int)$post['id'] ?>">
                      <?= post_action_thin_icon('bookmark', !empty($post['my_saved'])) ?>
                    </a>
                    <span class="action-count js-save-count js-open-reactors" data-rx-tab="save" data-post-id="<?= (int)$post['id'] ?>" role="button" tabindex="0" aria-label="See who favorited"><?= (int)($post['save_count'] ?? 0) ?></span>
                  </span>
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
                          <span class="msb-react-cluster">
                            <a class="public-live-action-btn js-react-love<?= public_reaction_is_love_lane((string)($post['my_reaction'] ?? '')) ? ' is-love' : '' ?>" type="button" aria-label="Love" data-post-id="<?= (int)$post['id'] ?>">
                              <?= post_action_thin_icon('heart', (string)($post['my_reaction'] ?? '') === 'love') ?>
                            </a>
                            <span class="action-count js-love-count js-open-reactors" data-rx-tab="love" data-post-id="<?= (int)$post['id'] ?>" role="button" tabindex="0" aria-label="See who reacted"><?= (int)$post['love_count'] + (int)$post['like_count'] ?></span>
                          </span>
                          <a class="public-live-action-btn js-react-like<?= public_reaction_is_like_lane((string)($post['my_reaction'] ?? '')) ? ' is-like' : '' ?>" type="button" aria-label="Like" data-post-id="<?= (int)$post['id'] ?>">
                            <i class="fa <?= ((string)($post['my_reaction'] ?? '') === 'like') ? 'fa-thumbs-up' : 'fa-thumbs-o-up' ?>"></i>
                            <span class="action-count js-like-count js-open-reactors" data-rx-tab="like" data-post-id="<?= (int)$post['id'] ?>" role="button" tabindex="0" aria-label="See who liked"><?= (int)$post['like_count'] ?></span>
                          </a>
                          <a class="public-live-action-btn js-open-comments" type="button" aria-label="Comment" data-post-id="<?= (int)$post['id'] ?>">
                            <?= post_action_thin_icon('comment') ?>
                            <span class="action-count js-comment-count-inline"><?= (int)$post['comment_count'] ?></span>
                          </a>
                          <span class="msb-react-cluster">
                            <a class="public-live-action-btn js-share-post<?= !empty($post['my_shared']) ? ' is-share' : '' ?>" type="button" aria-label="Share" data-post-id="<?= (int)$post['id'] ?>">
                              <?= post_action_thin_icon('share', !empty($post['my_shared'])) ?>
                            </a>
                            <span class="action-count js-share-count js-open-reactors" data-rx-tab="share" data-post-id="<?= (int)$post['id'] ?>" role="button" tabindex="0" aria-label="See who shared"><?= (int)($post['share_count'] ?? 0) ?></span>
                          </span>
                          <span class="public-live-action-spacer">
                            <span class="msb-react-cluster">
                              <a class="public-live-action-btn js-save-post<?= !empty($post['my_saved']) ? ' is-save' : '' ?>" type="button" aria-label="Favorite" data-post-id="<?= (int)$post['id'] ?>">
                                <?= post_action_thin_icon('bookmark', !empty($post['my_saved'])) ?>
                              </a>
                              <span class="action-count js-save-count js-open-reactors" data-rx-tab="save" data-post-id="<?= (int)$post['id'] ?>" role="button" tabindex="0" aria-label="See who favorited"><?= (int)($post['save_count'] ?? 0) ?></span>
                            </span>
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
                <div class="reel-top-author<?= $authorNameClass ?>">
                  <a class="post-author-avatar-link" href="<?= h($peerProfileHref) ?>" aria-label="Open <?= h($postAuthorText) ?> profile">
                    <div class="avatar"><span class="avatar-thumb"><img src="<?= h($postAvatarUrl) ?>" alt="<?= h($postAuthorText) ?>" onerror="this.onerror=null;this.src='avatar.php?name='+encodeURIComponent(this.alt||'U')+'&amp;s=96';"></span></div>
                  </a>
                  <div class="reel-top-meta">
                    <span class="reel-top-name<?= $authorNameClass ?>"><?= $authorNameHtml ?></span>
                    <?php if (!$hasSharingWith): ?>
                    <span class="reel-top-time">• <?= h($postTimeLabel) ?></span>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
              <div class="reel-top-right post-card-head-actions">
                <?php if (!$isOwner && (!$isPublisher || $canFollowPublishers)): ?>
                  <?php if ($isPublisher && $canFollowPublishers && !$isFollowing): ?>
                  <button type="button" class="publisher-follow-btn primary" data-publisher-id="<?= (int)$post['user_id'] ?>">Follow</button>
                  <?php elseif (!$isPublisher && !$isPublisherWorkspaceViewer && $friendStatus !== 'friends'): ?>
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
                <div class="reel-caption<?= public_caption_needs_readmore($caption) ? ' has-more' : '' ?>">
                  <div class="reel-caption-text"><?= public_caption_card_html($caption) ?></div>
                  <?php if (public_caption_needs_readmore($caption)): ?>
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
              <?= function_exists('msb_post_products_row_html') ? msb_post_products_row_html($post['products'] ?? []) : '' ?>
              <div class="reel-inline-actions">
                <div class="reel-inline-left">
                  <div class="reel-inline-row">
                    <span class="msb-react-cluster">
                      <a class="reel-inline-btn js-react-love<?= public_reaction_is_love_lane((string)($post['my_reaction'] ?? '')) ? ' is-love' : '' ?>" type="button" aria-label="Love" data-post-id="<?= (int)$post['id'] ?>">
                        <?= post_action_thin_icon('heart', (string)($post['my_reaction'] ?? '') === 'love') ?>
                      </a>
                      <span class="action-count js-love-count js-open-reactors" data-rx-tab="love" data-post-id="<?= (int)$post['id'] ?>" role="button" tabindex="0" aria-label="See who reacted"><?= (int)$post['love_count'] + (int)$post['like_count'] ?></span>
                    </span>
                    <a class="reel-inline-btn js-react-like<?= public_reaction_is_like_lane((string)($post['my_reaction'] ?? '')) ? ' is-like' : '' ?>" type="button" aria-label="Like" data-post-id="<?= (int)$post['id'] ?>">
                      <i class="fa <?= ((string)($post['my_reaction'] ?? '') === 'like') ? 'fa-thumbs-up' : 'fa-thumbs-o-up' ?>"></i>
                      <span class="action-count js-like-count js-open-reactors" data-rx-tab="like" data-post-id="<?= (int)$post['id'] ?>" role="button" tabindex="0" aria-label="See who liked"><?= (int)$post['like_count'] ?></span>
                    </a>
                    <a class="reel-inline-btn js-open-comments" type="button" aria-label="Comment" data-post-id="<?= (int)$post['id'] ?>">
                      <?= post_action_thin_icon('comment') ?>
                      <span class="action-count js-comment-count-inline"><?= (int)$post['comment_count'] ?></span>
                    </a>
                    <span class="msb-react-cluster">
                      <a class="reel-inline-btn js-share-post<?= !empty($post['my_shared']) ? ' is-share' : '' ?>" type="button" aria-label="Share" data-post-id="<?= (int)$post['id'] ?>">
                        <?= post_action_thin_icon('share', !empty($post['my_shared'])) ?>
                      </a>
                      <span class="action-count js-share-count js-open-reactors" data-rx-tab="share" data-post-id="<?= (int)$post['id'] ?>" role="button" tabindex="0" aria-label="See who shared"><?= (int)($post['share_count'] ?? 0) ?></span>
                    </span>
                  </div>
                  <div class="reel-inline-comments js-open-comments" data-post-id="<?= (int)$post['id'] ?>">
                    View all <span class="js-comment-count"><?= (int)$post['comment_count'] ?></span> comments
                  </div>
                </div>
                <div class="reel-inline-right">
                  <span class="msb-react-cluster">
                    <a class="reel-inline-btn js-save-post<?= !empty($post['my_saved']) ? ' is-save' : '' ?>" type="button" aria-label="Favorite" data-post-id="<?= (int)$post['id'] ?>">
                      <?= post_action_thin_icon('bookmark', !empty($post['my_saved'])) ?>
                    </a>
                    <span class="action-count js-save-count js-open-reactors" data-rx-tab="save" data-post-id="<?= (int)$post['id'] ?>" role="button" tabindex="0" aria-label="See who favorited"><?= (int)($post['save_count'] ?? 0) ?></span>
                  </span>
                  <div class="reel-inline-views"><?= (int)$post['views_count'] ?> views</div>
                </div>
              </div>
            </div>
          <?php elseif (!empty($attachments)): ?>
            <?php $hasMultiMedia = count($attachments) > 1; ?>
            <?php $mediaStageShape = ($isSingleStandardVideo || $isSingleStandardImage) ? '' : $shapeClass; ?>
            <div class="media-stage <?= h($mediaStageShape) ?><?= !empty($isPhoneShot) ? ' phone-shot' : '' ?><?= $isSingleStandardVideo ? ' standard-video-stage' : '' ?><?= $isSingleStandardImage ? ' standard-image-stage' : '' ?><?= $hasMultiMedia ? ' has-carousel js-media-carousel' : '' ?>"<?= $deviceStageStyle !== '' ? ' style="' . h($deviceStageStyle) . '"' : '' ?><?= $hasMultiMedia ? ' data-count="' . (int)count($attachments) . '" data-index="0" data-legacy-title="' . h($legacyTitle) . '" data-legacy-body="' . h($legacyCaption) . '" data-slide-presentation="' . ($slidePresentation ? '1' : '0') . '"' : '' ?>>
              <?php if (!$hasMultiMedia): ?>
                <?php $a = $attachments[0]; $src = h((string)$a['file_path']); ?>
                <?php if ((string)$a['type'] === 'image'): ?>
                  <img src="<?= $src ?>" alt="" loading="eager" decoding="sync" fetchpriority="high">
                <?php elseif ((string)$a['type'] === 'video'): ?>
                  <?php
                    $videoPosterPath = trim((string)($a['thumb_path'] ?? ''));
                    $videoPosterAttr = $videoPosterPath !== '' ? (' poster="' . h($videoPosterPath) . '"') : '';
                  ?>
                  <video src="<?= $src ?>"<?= $videoPosterAttr ?> playsinline preload="auto" muted loop></video>
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
                      <div class="media-slide<?= $slideIndex === 0 ? ' is-active' : '' ?>" data-slide-index="<?= (int)$slideIndex ?>" data-slide-title="<?= h((string)($a['slide_title'] ?? '')) ?>" data-slide-body="<?= h((string)($a['slide_body'] ?? '')) ?>">
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
                  <div class="standard-media-author<?= $authorNameClass ?>">
                    <a class="post-author-avatar-link" href="<?= h($peerProfileHref) ?>" aria-label="Open <?= h($postAuthorText) ?> profile">
                      <div class="avatar"><span class="avatar-thumb"><img src="<?= h($postAvatarUrl) ?>" alt="<?= h($postAuthorText) ?>" onerror="this.onerror=null;this.src='avatar.php?name='+encodeURIComponent(this.alt||'U')+'&amp;s=96';"></span></div>
                    </a>
                    <div class="standard-media-meta">
                      <div class="standard-media-name-row">
                        <span class="standard-media-name<?= $authorNameClass ?>"><?= $authorNameHtml ?></span>
                        <?php if (!$hasSharingWith): ?>
                        <span class="standard-media-time">• <?= h($postTimeLabel) ?></span>
                        <?= post_visibility_badge_html((string)($post['visibility'] ?? 'public')) ?>
                        <?php endif; ?>
                      </div>
                      <?= post_music_row_html($post) ?>
                    </div>
                  </div>
                  <?= post_card_actions_menu_shell_html($pcmCtx, 'standard-media-topbar-menu') ?>
                </div>
                <?php if (!$isOwner && (($isPublisher && $canFollowPublishers && !$isFollowing) || (!$isPublisher && !$isPublisherWorkspaceViewer && $friendStatus !== 'friends'))): ?>
                <div class="standard-media-top-actions post-card-head-actions">
                  <?php if ($isPublisher && $canFollowPublishers): ?>
                  <?= publisher_media_follow_btn_html((int)$post['user_id'], $isFollowing, $canFollowPublishers) ?>
                  <?php elseif (!$isPublisher && !$isPublisherWorkspaceViewer): ?>
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
                  <?php if ($displayTitle !== '' || $caption !== '' || $slidePresentation): ?>
                    <div class="standard-media-copy">
                      <?php if ($displayTitle !== ''): ?>
                        <h3 class="standard-media-title"><?= h($displayTitle) ?></h3>
                      <?php endif; ?>
                      <?php if ($caption !== ''): ?>
                        <div class="standard-media-intro standard-media-caption">
                          <?= public_caption_card_html($caption) ?>
                          <?php if (public_caption_needs_readmore($caption)): ?>
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
                      <?php if ($slidePresentation): ?>
                        <h4 class="standard-media-subtitle"<?= $slide0Title === '' ? ' style="display:none"' : '' ?>><?= h($slide0Title) ?></h4>
                        <div class="standard-media-summary"<?= $slide0Body === '' ? ' style="display:none"' : '' ?>><?= post_slide_summary_html($slide0Body) ?></div>
                      <?php endif; ?>
                      <?= function_exists('msb_post_products_row_html') ? msb_post_products_row_html($post['products'] ?? []) : '' ?>
                    </div>
                  <?php endif; ?>
                  <?php if (empty($displayTitle) && $caption === '' && !$slidePresentation): ?>
                    <?= function_exists('msb_post_products_row_html') ? msb_post_products_row_html($post['products'] ?? []) : '' ?>
                  <?php endif; ?>
                  <div class="standard-media-actions">
                    <div class="standard-media-left">
                      <div class="standard-media-row">
                        <span class="msb-react-cluster">
                          <a class="standard-media-btn js-react-love<?= public_reaction_is_love_lane((string)($post['my_reaction'] ?? '')) ? ' is-love' : '' ?>" type="button" aria-label="Love" data-post-id="<?= (int)$post['id'] ?>">
                            <?= post_action_thin_icon('heart', (string)($post['my_reaction'] ?? '') === 'love') ?>
                          </a>
                          <span class="action-count js-love-count js-open-reactors" data-rx-tab="love" data-post-id="<?= (int)$post['id'] ?>" role="button" tabindex="0" aria-label="See who reacted"><?= (int)$post['love_count'] + (int)$post['like_count'] ?></span>
                        </span>
                        <!-- <a class="standard-media-btn js-react-like<?= public_reaction_is_like_lane((string)($post['my_reaction'] ?? '')) ? ' is-like' : '' ?>" type="button" aria-label="Like" data-post-id="<?= (int)$post['id'] ?>">
                          <i class="fa <?= ((string)($post['my_reaction'] ?? '') === 'like') ? 'fa-thumbs-up' : 'fa-thumbs-o-up' ?>"></i>
                          <span class="action-count js-like-count"><?= (int)$post['like_count'] ?></span>
                        </a> -->
                        <a class="standard-media-btn js-open-comments" type="button" aria-label="Comment" data-post-id="<?= (int)$post['id'] ?>">
                          <?= post_action_thin_icon('comment') ?>
                          <span class="action-count js-comment-count-inline"><?= (int)$post['comment_count'] ?></span>
                        </a>
                        <span class="msb-react-cluster">
                          <a class="standard-media-btn js-share-post<?= !empty($post['my_shared']) ? ' is-share' : '' ?>" type="button" aria-label="Share" data-post-id="<?= (int)$post['id'] ?>">
                            <?= post_action_thin_icon('share', !empty($post['my_shared'])) ?>
                          </a>
                          <span class="action-count js-share-count js-open-reactors" data-rx-tab="share" data-post-id="<?= (int)$post['id'] ?>" role="button" tabindex="0" aria-label="See who shared"><?= (int)($post['share_count'] ?? 0) ?></span>
                        </span>
                      </div>
                      <!-- <div class="standard-media-comments js-open-comments" data-post-id="<?= (int)$post['id'] ?>">
                        View all <span class="js-comment-count"><?= (int)$post['comment_count'] ?></span> comments
                      </div> -->
                    </div>
                    <div class="standard-media-right">
                      <span class="msb-react-cluster">
                        <a class="standard-media-btn js-save-post<?= !empty($post['my_saved']) ? ' is-save' : '' ?>" type="button" aria-label="Favorite" data-post-id="<?= (int)$post['id'] ?>">
                          <?= post_action_thin_icon('bookmark', !empty($post['my_saved'])) ?>
                        </a>
                        <span class="action-count js-save-count js-open-reactors" data-rx-tab="save" data-post-id="<?= (int)$post['id'] ?>" role="button" tabindex="0" aria-label="See who favorited"><?= (int)($post['save_count'] ?? 0) ?></span>
                      </span>
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
                    <span class="action-count js-love-count"><?= (int)$post['love_count'] + (int)$post['like_count'] ?></span>
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
                  <a class="action-btn js-save-post<?= !empty($post['my_saved']) ? ' is-save' : '' ?>" type="button" aria-label="Favorite" data-post-id="<?= (int)$post['id'] ?>">
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
        <?php
          $discoverFeedFragment = (string)ob_get_clean();
          if ($discoverFragmentRequest) {
              while (ob_get_level() > $discoverFragmentBaseObLevel) {
                  ob_end_clean();
              }
              header('Content-Type: text/html; charset=utf-8');
              echo $discoverFeedFragment;
              exit;
          }
          echo $discoverFeedFragment;
        ?>
      </div>
    </div>
    <?php
      // Right rail: Suggested for you stays visible on public.php / news.php.
      // Followed publishers leave this list and their posts move to Friends Feed.
      $suggestedForYouStaffReadonly = $staffReadonly;
      $suggestedForYouMaxFriends = 12;
      $suggestedForYouMaxFollow = 12;
      $GLOBALS['suggestedForYouIncludePeople'] = empty($isPublisherWorkspaceViewer);
      if ($isNewsSurface || $isPublisherWorkspaceViewer) {
          $suggestedForYouMaxAdvertise = 3;
      } else {
          $suggestedForYouMaxAdvertise = 0;
      }
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

  <div class="jump-rail<?= $discoverTab === 'for-you' ? ' is-hidden' : '' ?>" aria-label="Post navigation">
    <button type="button" id="btnUp" aria-label="Previous post"><i class="icon ion-chevron-up"></i></button>
    <button type="button" id="btnDown" aria-label="Next post"><i class="icon ion-chevron-down"></i></button>
  </div>

<script>
(function(){
  try{
    if('scrollRestoration' in history) history.scrollRestoration = 'manual';
  }catch(e){}

  var publicAutoAdvanceTimer = null;
  var publicAutoAdvanceStartedAt = 0;
  var publicAutoAdvanceDelay = 0;
  var publicAutoAdvanceRemaining = 0;
  var publicAutoAdvanceHovered = false;
  var publicAutoAdvanceCardId = 0;
  var publicAutoAdvanceScrollTick = null;
  var publicAutoAdvanceProgressRaf = 0;

  function cards(){ return Array.prototype.slice.call(document.querySelectorAll('.public-post-card')); }

  function scrollRoot(){
    return document.querySelector('.feed-desktop-center > .ig-feed')
      || document.querySelector('.ig-feed')
      || document.scrollingElement
      || document.documentElement;
  }

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
    var card = list[idx];
    scrollToCard(card);
  }

  function isVideoCard(card){
    if(!card || !card.classList) return false;
    if(card.classList.contains('is-single-video-post') || card.classList.contains('is-reel-post')) return true;
    return !!(card.querySelector('video'));
  }

  function scrollToCard(card){
    if(!card) return;
    var root = scrollRoot();
    if (root && root.contains(card) && typeof root.scrollTo === 'function') {
      var rootRect = root.getBoundingClientRect();
      var cardRect = card.getBoundingClientRect();
      var nextTop = root.scrollTop + (cardRect.top - rootRect.top);
      root.scrollTo({ top: Math.max(0, nextTop), behavior: 'smooth' });
      return;
    }
    card.scrollIntoView({behavior:'smooth', block:'start'});
  }

  function goNextVideo(){
    var list = cards();
    if(!list.length) return;
    var idx = currentIndex();
    if(idx < 0) idx = 0;
    var i;
    for(i = idx + 1; i < list.length; i += 1){
      if(isVideoCard(list[i])){
        scrollToCard(list[i]);
        return;
      }
    }
    for(i = 0; i <= idx; i += 1){
      if(isVideoCard(list[i])){
        scrollToCard(list[i]);
        return;
      }
    }
  }

  var up = document.getElementById('btnUp'), down = document.getElementById('btnDown'), videoBtn = document.getElementById('btnVideo');
  if (up) up.addEventListener('click', function(){ go(-1); });
  if (down) down.addEventListener('click', function(){ go(1); });
  if (videoBtn) videoBtn.addEventListener('click', function(){
    var list = cards();
    var idx = currentIndex();
    var card = (idx >= 0 && list[idx] && isVideoCard(list[idx])) ? list[idx] : null;
    if(!card){
      for(var i = 0; i < list.length; i += 1){
        if(isVideoCard(list[i])){ card = list[i]; break; }
      }
    }
    var pid = card ? Number(card.getAttribute('data-post-id') || 0) : 0;
    window.location.href = pid > 0 ? ('reel.php?post=' + encodeURIComponent(String(pid))) : 'reel.php';
  });

<?php if (empty($isNewsSurface)): ?>
  if(!window.__msbDiscoverVideoToReelBound){
    window.__msbDiscoverVideoToReelBound = true;
    document.addEventListener('click', function(e){
      var tabNow = '';
      try{ tabNow = String((new URL(window.location.href)).searchParams.get('tab') || ''); }catch(eTab){ tabNow = ''; }
      if(tabNow !== 'discover' && tabNow !== 'public') return;
      var t = e.target;
      if(!t || !t.closest) return;
      if(t.closest('a, button, input, textarea, select, .post-card-menu-wrap, .standard-media-top-actions, .standard-media-topbar, .standard-media-actions, .standard-media-bottom, .standard-text-actions, .reel-inline-actions, .reel-mute, .media-dots, .js-media-prev, .js-media-next, .public-live-actionbar, .js-open-reactors, .js-open-comments')) return;
      if(t.closest('img')) return;
      var card = t.closest('.post.public-post-card');
      if(!card) return;
      var slide = t.closest('.media-slide');
      if(slide && !slide.querySelector('video')) return;
      var hitVideo = t.closest('video');
      var videoStage = t.closest('.media-stage.standard-video-stage');
      var reelStage = t.closest('.reel-stage');
      var isVideoHit = !!hitVideo;
      if(!isVideoHit && videoStage && !videoStage.classList.contains('has-carousel')) isVideoHit = true;
      if(!isVideoHit && slide && slide.querySelector('video')) isVideoHit = true;
      if(!isVideoHit && reelStage){
        var reelVid = reelStage.querySelector('video');
        isVideoHit = !!(reelVid && reelVid.parentNode === reelStage);
      }
      if(!isVideoHit) return;
      var pid = Number(card.getAttribute('data-post-id') || 0);
      if(!pid) return;
      e.preventDefault();
      e.stopPropagation();
      window.location.href = 'reel.php?post=' + encodeURIComponent(String(pid));
    });
  }
<?php endif; ?>

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
    /*
     * Keep the selected/newest post anchored like feed.php.
     * Videos may continue playing/looping, but a timer must never scroll the
     * viewer to another post after refresh, navigation, or a tab switch.
     */
    clearPublicAutoAdvance();
    resetPublicAutoAdvanceProgress(card);
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

  function remountPublicFriendBtn(card, peerId, status){
    if(!card || !peerId) return;
    status = String(status || 'none');
    if(String(card.getAttribute('data-is-publisher') || '') === '1') return;
    if(String(card.getAttribute('data-post-owner') || '') === '1') return;
    if(card.querySelector('.friend-btn[data-peer-id="'+String(peerId)+'"]')) return;

    var circle = !!card.querySelector('.media-stage, .standard-media-topbar');
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = circle
      ? 'friend-btn mf-media-action-circle mf-media-follow-btn primary'
      : 'friend-btn primary';
    btn.setAttribute('data-peer-id', String(peerId));
    btn.setAttribute('data-status', status);
    btn.setAttribute('aria-label', 'Add friend');
    btn.setAttribute('title', 'Add friend');
    if(circle){
      btn.innerHTML = '<i class="fa fa-plus" aria-hidden="true"></i>';
    } else {
      btn.textContent = '+';
    }

    var mediaActions = card.querySelector('.standard-media-top-actions');
    if(mediaActions){
      mediaActions.appendChild(btn);
      return;
    }
    var mediaStage = card.querySelector('.media-stage');
    if(mediaStage && card.querySelector('.standard-media-topbar')){
      var wrap = document.createElement('div');
      wrap.className = 'standard-media-top-actions post-card-head-actions';
      wrap.appendChild(btn);
      mediaStage.appendChild(wrap);
      return;
    }
    var headActions = card.querySelector('.post-card-head-actions, .standard-text-top-actions');
    if(headActions){
      var menu = headActions.querySelector('.post-card-menu-wrap, .mf-menu-wrap');
      if(menu) headActions.insertBefore(btn, menu);
      else headActions.appendChild(btn);
      return;
    }
    var header = card.querySelector('.post-header');
    if(header){
      var actions = document.createElement('div');
      actions.className = 'post-card-head-actions';
      actions.appendChild(btn);
      var existingMenu = header.querySelector('.post-card-menu-wrap, .mf-menu-wrap');
      if(existingMenu){
        actions.appendChild(existingMenu);
      }
      header.appendChild(actions);
    }
  }

  function applyStatusForPeer(peerId, status){
    peerId = Number(peerId || 0);
    if(!peerId) return;
    status = String(status || 'none');
    document.querySelectorAll('.friend-btn[data-peer-id="'+String(peerId)+'"]').forEach(function(btn){
      applyStatus(btn, status);
    });
    syncPostCardPeerAttrs(peerId, { 'data-friend-status': status });
    if(window.MSBPostCardMenu && typeof window.MSBPostCardMenu.syncFriendCards === 'function'){
      window.MSBPostCardMenu.syncFriendCards(peerId, status);
    }
    if(status !== 'friends'){
      document.querySelectorAll('.post.public-post-card[data-peer-id="'+String(peerId)+'"]').forEach(function(card){
        remountPublicFriendBtn(card, peerId, status);
      });
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
    // Followed publishers leave public.php / news.php and belong on feed.php.
    if(on){
      document.querySelectorAll('.public-post-card[data-peer-id="'+String(publisherId)+'"]').forEach(function(card){
        try{ card.remove(); }catch(_e){}
      });
      document.querySelectorAll('.sfy-row[data-sfy-id="'+String(publisherId)+'"]').forEach(function(row){
        var kind = String(row.getAttribute('data-sfy-kind') || '');
        if(kind === 'publisher' || kind === 'advertise' || kind === ''){
          try{ row.remove(); }catch(_e2){}
        }
      });
      var feed = document.querySelector('.ig-feed');
      if(feed && !feed.querySelector('.public-post-card') && !feed.querySelector('.mf-feed-empty')){
        var empty = document.createElement('div');
        empty.className = 'mf-feed-empty';
        empty.setAttribute('role', 'status');
        empty.innerHTML = '<i class="icon ion-ios-paper-outline" aria-hidden="true"></i><div class="mf-feed-empty-title"><?= $isNewsSurface ? 'No publisher posts yet' : 'No Feed Available' ?></div>';
        feed.appendChild(empty);
      }
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
  var publicAlertHideNav = <?php echo ((int)($_GET['hide_nav'] ?? 0) === 1) ? 'true' : 'false'; ?>;

  function clearPublicAlertParams(){
    try{
      var nextUrl = new URL(window.location.href);
      nextUrl.searchParams.delete('open_post');
      nextUrl.searchParams.delete('post');
      nextUrl.searchParams.delete('open_comment');
      nextUrl.searchParams.delete('hide_nav');
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
      window.MSBReactions.applyReactionButton(btn, my, 'love');
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

  function stampPublicEngagement(card){
    if(card) card.setAttribute('data-engage-at', String(Date.now()));
  }

  function publicCardCounts(card){
    if(!card) return { love_count:0, like_count:0, reaction_count:0, comment_count:0, save_count:0, share_count:0, my_reaction:'', is_saved:0, is_shared:0 };
    var loveN = Number(card.getAttribute('data-love-count') || (card.querySelector('.js-love-count') || {}).textContent || 0);
    var likeN = Number(card.getAttribute('data-like-count') || (card.querySelector('.js-like-count') || {}).textContent || 0);
    var reactN = Number(card.getAttribute('data-reaction-count') || (card.querySelector('.js-love-count') || {}).textContent || 0);
    return {
      love_count: loveN,
      like_count: likeN,
      reaction_count: reactN,
      comment_count: Number(card.getAttribute('data-comment-count') || (card.querySelector('.js-comment-count') || {}).textContent || 0),
      save_count: Number((card.querySelector('.js-save-count') || {}).textContent || 0),
      share_count: Number((card.querySelector('.js-share-count') || {}).textContent || 0),
      my_reaction: String(card.getAttribute('data-my-reaction') || ''),
      is_saved: card.querySelector('.js-save-post.is-save') ? 1 : 0,
      is_shared: card.querySelector('.js-share-post.is-share') ? 1 : 0
    };
  }

  function publicReactionBadge(counts){
    if(!counts) return 0;
    if(counts.reaction_count != null && counts.reaction_count !== '') return Number(counts.reaction_count || 0);
    if(counts.love_count != null && counts.like_count != null) return Number(counts.love_count || 0) + Number(counts.like_count || 0);
    return Number(counts.love_count || 0);
  }

  function updatePublicReactionState(postId, counts){
    postId = Number(postId || 0);
    var card = document.querySelector('.public-post-card[data-post-id="' + String(postId) + '"]');
    if(!card || !counts) return;

    // Only update fields that are actually present (avoid wiping with empty {})
    if(Object.prototype.hasOwnProperty.call(counts, 'like_count') && counts.like_count != null){
      var likeCount = Number(counts.like_count || 0);
      card.setAttribute('data-like-count', String(likeCount));
      card.querySelectorAll('.js-like-count').forEach(function(el){ el.textContent = String(likeCount); });
    }
    if(Object.prototype.hasOwnProperty.call(counts, 'love_count') && counts.love_count != null){
      card.setAttribute('data-love-count', String(Number(counts.love_count || 0)));
    }
    if(Object.prototype.hasOwnProperty.call(counts, 'reaction_count') && counts.reaction_count != null){
      card.setAttribute('data-reaction-count', String(Number(counts.reaction_count || 0)));
      card.querySelectorAll('.js-love-count, .js-reaction-count').forEach(function(el){
        el.textContent = String(Number(counts.reaction_count || 0));
      });
    } else if(Object.prototype.hasOwnProperty.call(counts, 'love_count') && Object.prototype.hasOwnProperty.call(counts, 'like_count')){
      var summed = Number(counts.love_count || 0) + Number(counts.like_count || 0);
      card.setAttribute('data-reaction-count', String(summed));
      card.querySelectorAll('.js-love-count, .js-reaction-count').forEach(function(el){
        el.textContent = String(summed);
      });
    }
    if(Object.prototype.hasOwnProperty.call(counts, 'my_reaction')){
      var my = String(counts.my_reaction || '');
      card.setAttribute('data-my-reaction', my);
      card.querySelectorAll('.js-react-like').forEach(function(btn){
        btn.classList.toggle('is-like', my === 'like');
      });
      card.querySelectorAll('.js-react-love').forEach(function(btn){
        btn.classList.toggle('is-love', my === 'love');
        btn.classList.toggle('is-reacted', my !== '' && my !== 'love');
      });
    }

    var likeN = Number(card.getAttribute('data-like-count') || 0);
    var loveN = Number(card.getAttribute('data-love-count') || 0);
    card.querySelectorAll('.js-like-total').forEach(function(el){ el.textContent = String(likeN + loveN); });

    syncPublicCardIcons(card);
  }

  function updatePublicTrackState(postId, res){
    postId = Number(postId || 0);
    var card = document.querySelector('.public-post-card[data-post-id="' + String(postId) + '"]');
    if(!card || !res) return;

    if(typeof res.share_count !== 'undefined' && res.share_count != null){
      card.querySelectorAll('.js-share-count').forEach(function(el){ el.textContent = String(Number(res.share_count || 0)); });
    }
    if(typeof res.save_count !== 'undefined' && res.save_count != null){
      card.querySelectorAll('.js-save-count').forEach(function(el){ el.textContent = String(Number(res.save_count || 0)); });
    }

    var state = res.state || {};
    if(typeof state.shared !== 'undefined'){
      card.querySelectorAll('.js-share-post').forEach(function(btn){
        btn.classList.toggle('is-share', Number(state.shared || 0) === 1);
      });
    }
    if(typeof state.saved !== 'undefined'){
      card.querySelectorAll('.js-save-post').forEach(function(btn){
        btn.classList.toggle('is-save', Number(state.saved || 0) === 1);
      });
    }

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
      if(window.MSBPostEngagement){
        window.MSBPostEngagement.publishCommentCount(postId, comments.length, { source: 'public-comments' });
      }
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
    if(target instanceof Node && !document.contains(target)) return;

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

    if(target.closest('#tt-menu-wrap, #tt-comments-wrap, #tt-readmore-wrap, #tt-profile-wrap, #tt-stories-wrap, #tt-live-right-wrap, #ttCommentEmojiPicker, #ttCommentGifPicker, #ttEmojiBtn, #ttMediaBtn, #ttMenuClose, #ttCommentsClose, #ttRmClose, #ttProfileClose, #ttStoriesClose, .tt-story-cmt-sheet, .tt-story-cmt-panel, .tt-story-cmt-backdrop')) return;
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
    track.style.transform = 'none';
    slides.forEach(function(el, i){
      el.classList.toggle('is-active', i === nextIndex);
    });

    dots.forEach(function(dot, dotIndex){
      var on = dotIndex === nextIndex;
      dot.classList.toggle('is-active', on);
      dot.style.background = on ? '#3897f0' : 'rgba(255,255,255,.55)';
      dot.style.width = on ? '6px' : '5px';
      dot.style.height = on ? '6px' : '5px';
      dot.style.minWidth = on ? '6px' : '5px';
      dot.style.minHeight = on ? '6px' : '5px';
      dot.style.flex = on ? '0 0 6px' : '0 0 5px';
      dot.style.boxShadow = 'none';
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

    // Presentation: keep super title + intro fixed; sync slide subtitle/summary only.
    try {
      var card = stage.closest('.public-post-card, .post, article');
      var active = slides[nextIndex];
      if (card && active) {
        var anySlideText = stage.getAttribute('data-slide-presentation') === '1' || slides.some(function(s){
          return String(s.getAttribute('data-slide-title') || '').trim() || String(s.getAttribute('data-slide-body') || '').trim();
        });
        if (anySlideText) {
          var slideTitle = String(active.getAttribute('data-slide-title') || '').trim();
          var slideBody = String(active.getAttribute('data-slide-body') || '').trim();
          function escHtml(s){
            return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
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
          var subEls = card.querySelectorAll('.standard-media-subtitle, .standard-text-subtitle');
          if (subEls.length) {
            subEls.forEach(function(el){
              if (!slideTitle) {
                el.style.display = 'none';
                el.textContent = '';
              } else {
                el.style.display = '';
                el.textContent = slideTitle;
              }
            });
          }
          var sumEls = card.querySelectorAll('.standard-media-summary, .standard-text-summary');
          if (sumEls.length) {
            sumEls.forEach(function(el){
              if (!slideBody) {
                el.style.display = 'none';
                el.innerHTML = '';
              } else {
                el.style.display = '';
                el.innerHTML = slideSummaryHtml(slideBody);
              }
            });
          }
          // Reel overlay still shows active slide text when present.
          var reelCap = card.querySelector('.reel-caption');
          if (reelCap && !reelCap.querySelector('.reel-caption-text')) {
            if (!slideBody && !slideTitle) {
              reelCap.style.display = 'none';
              reelCap.innerHTML = '';
            } else {
              reelCap.style.display = '';
              reelCap.innerHTML = (slideTitle ? '<strong>'+escHtml(slideTitle)+'</strong> ' : '') + escHtml(slideBody);
            }
          }
          var copy = card.querySelector('.standard-media-copy, .standard-text-copy');
          if (copy) {
            var hasFixed = !!(card.querySelector('.standard-media-title, .standard-text-title, .standard-media-intro, .standard-media-caption, .standard-text-caption'));
            copy.style.display = (hasFixed || slideTitle || slideBody) ? '' : 'none';
          }
        }
      }
    } catch (eSlideCap) {}
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
    var url = '';
    try{
      var here = new URL(window.location.href);
      var dir = here.pathname.replace(/[^/]+$/, '');
      url = here.origin + dir + 'post.php?id=' + encodeURIComponent(String(postId));
    }catch(err){
      url = (window.location.origin || '') + '/post.php?id=' + encodeURIComponent(String(postId));
    }
    try {
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(url).catch(function(){});
      }
    } catch(err2){}
  }

  $(document).on('click', '.js-react-love', function(e){
    if (this.closest && this.closest('#reelApp, .reel-slide')) return;
    if (e.target && e.target.closest && e.target.closest('.js-love-count, .js-open-reactors, .js-like-count, .js-reaction-count, .js-share-count, .js-save-count')) return;
    e.preventDefault();
    e.stopPropagation();
    var btn = this;
    var postId = Number(btn.getAttribute('data-post-id') || 0);
    if(!postId || btn.disabled) return;
    var card = btn.closest('.public-post-card');
    var snap = publicCardCounts(card);
    var nextReaction = snap.my_reaction === 'love' ? 'none' : 'love';
    var totals = (window.MSBPostEngagement && typeof window.MSBPostEngagement.nextReaction === 'function')
      ? window.MSBPostEngagement.nextReaction(snap, nextReaction)
      : {
          my_reaction: nextReaction === 'none' ? '' : 'love',
          love_count: Math.max(0, Number(snap.love_count || 0) + (nextReaction === 'none' ? -1 : 1)),
          like_count: snap.like_count,
          reaction_count: Math.max(0, Number(snap.reaction_count || 0) + (nextReaction === 'none' ? -1 : (snap.my_reaction ? 0 : 1)))
        };
    var optimistic = Object.assign({}, snap, totals);
    stampPublicEngagement(card);
    updatePublicReactionState(postId, optimistic);
    if(window.MSBPostEngagement) window.MSBPostEngagement.publish(postId, optimistic, { source: 'public-card' });
    btn.disabled = true;
    $.post(COMMENT_API_URL + '?ajax=react', { post_id: postId, reaction: nextReaction }, function(res){
      if(res && res.ok){
        var counts = Object.assign({}, optimistic, res.counts || {});
        if(counts.my_reaction == null) counts.my_reaction = optimistic.my_reaction;
        if(counts.love_count == null) counts.love_count = optimistic.love_count;
        stampPublicEngagement(card);
        updatePublicReactionState(postId, counts);
        if(window.MSBPostEngagement) window.MSBPostEngagement.publishFromReact(postId, { counts: counts }, { source: 'public-card' });
      } else {
        stampPublicEngagement(card);
        updatePublicReactionState(postId, snap);
        if(window.MSBPostEngagement) window.MSBPostEngagement.publish(postId, snap, { source: 'public-card' });
      }
      btn.disabled = false;
    }, 'json').fail(function(){
      stampPublicEngagement(card);
      updatePublicReactionState(postId, snap);
      if(window.MSBPostEngagement) window.MSBPostEngagement.publish(postId, snap, { source: 'public-card' });
      btn.disabled = false;
    });
  });

  $(document).on('click', '.js-react-like', function(e){
    if (e.target && e.target.closest && e.target.closest('.js-like-count, .js-open-reactors')) return;
    e.preventDefault();
    e.stopPropagation();
    var btn = this;
    var postId = Number(btn.getAttribute('data-post-id') || 0);
    if(!postId || btn.disabled) return;
    var card = btn.closest('.public-post-card');
    var snap = publicCardCounts(card);
    var nextReaction = snap.my_reaction === 'like' ? 'none' : 'like';
    var totals = (window.MSBPostEngagement && typeof window.MSBPostEngagement.nextReaction === 'function')
      ? window.MSBPostEngagement.nextReaction(snap, nextReaction)
      : { my_reaction: nextReaction === 'none' ? '' : 'like', love_count: snap.love_count, like_count: Math.max(0, Number(snap.like_count || 0) + (nextReaction === 'none' ? -1 : 1)), reaction_count: Math.max(0, Number(snap.reaction_count || 0) + (nextReaction === 'none' ? -1 : (snap.my_reaction ? 0 : 1))) };
    var optimistic = Object.assign({}, snap, totals);
    stampPublicEngagement(card);
    updatePublicReactionState(postId, optimistic);
    if(window.MSBPostEngagement) window.MSBPostEngagement.publish(postId, optimistic, { source: 'public-card' });
    btn.disabled = true;
    $.post(COMMENT_API_URL + '?ajax=react', { post_id: postId, reaction: nextReaction }, function(res){
      if(res && res.ok){
        var counts = Object.assign({}, optimistic, res.counts || {});
        stampPublicEngagement(card);
        updatePublicReactionState(postId, counts);
        if(window.MSBPostEngagement) window.MSBPostEngagement.publishFromReact(postId, { counts: counts }, { source: 'public-card' });
      } else {
        stampPublicEngagement(card);
        updatePublicReactionState(postId, snap);
        if(window.MSBPostEngagement) window.MSBPostEngagement.publish(postId, snap, { source: 'public-card' });
      }
      btn.disabled = false;
    }, 'json').fail(function(){
      stampPublicEngagement(card);
      updatePublicReactionState(postId, snap);
      if(window.MSBPostEngagement) window.MSBPostEngagement.publish(postId, snap, { source: 'public-card' });
      btn.disabled = false;
    });
  });

  if(window.MSBReactions){
    window.MSBReactions.bindLikePicker('.js-react-love', function(btn, reaction){
      var postId = Number(btn.getAttribute('data-post-id') || 0);
      if(!postId || btn.disabled || !reaction) return;
      var next = String(reaction || 'none');
      var card = btn.closest('.public-post-card');
      var snap = publicCardCounts(card);
      var prev = String(snap.my_reaction || '');
      if(next === 'none' && !prev) return;
      if(next !== 'none' && prev === next) return;
      var totals = (window.MSBPostEngagement && typeof window.MSBPostEngagement.nextReaction === 'function')
        ? window.MSBPostEngagement.nextReaction(snap, next)
        : { my_reaction: next === 'none' ? '' : next, love_count: snap.love_count, like_count: snap.like_count, reaction_count: Math.max(0, Number(snap.reaction_count || 0) + (!prev && next !== 'none' ? 1 : 0) - (prev && next === 'none' ? 1 : 0)) };
      var optimistic = Object.assign({}, snap, totals);
      stampPublicEngagement(card);
      updatePublicReactionState(postId, optimistic);
      try {
        if(window.MSBReactions) window.MSBReactions.applyReactionButton(btn, next === 'none' ? '' : next, 'love');
      } catch(err){}
      if(window.MSBPostEngagement) window.MSBPostEngagement.publish(postId, optimistic, { source: 'public-card' });
      btn.disabled = true;
      $.post(COMMENT_API_URL + '?ajax=react', { post_id: postId, reaction: next }, function(res){
        if(res && res.ok){
          var counts = Object.assign({}, optimistic, res.counts || {});
          if(typeof counts.my_reaction === 'undefined' || counts.my_reaction === null){
            counts.my_reaction = next === 'none' ? '' : next;
          }
          stampPublicEngagement(card);
          updatePublicReactionState(postId, counts);
          if(window.MSBPostEngagement) window.MSBPostEngagement.publishFromReact(postId, { counts: counts }, { source: 'public-card' });
        } else {
          stampPublicEngagement(card);
          updatePublicReactionState(postId, snap);
          if(window.MSBPostEngagement) window.MSBPostEngagement.publish(postId, snap, { source: 'public-card' });
        }
        btn.disabled = false;
      }, 'json').fail(function(){
        stampPublicEngagement(card);
        updatePublicReactionState(postId, snap);
        if(window.MSBPostEngagement) window.MSBPostEngagement.publish(postId, snap, { source: 'public-card' });
        btn.disabled = false;
      });
    });
  }

  $(document).on('click', '.js-share-post', function(e){
    if (this.closest && this.closest('#reelApp, .reel-slide')) return;
    if (e.target && e.target.closest && e.target.closest('.js-share-count, .js-open-reactors')) return;
    e.preventDefault();
    e.stopPropagation();
    var btn = this;
    var postId = Number(btn.getAttribute('data-post-id') || 0);
    if(!postId || btn.disabled) return;
    if(window.MSBPostCardMenu && typeof window.MSBPostCardMenu.openShare === 'function'){
      if(window.MSBPostCardMenu.openShare(postId, btn.closest('.public-post-card'))) return;
    }
    var card = btn.closest('.public-post-card');
    var snap = publicCardCounts(card);
    var nextShared = snap.is_shared ? 0 : 1;
    var optimisticRes = {
      share_count: Math.max(0, Number(snap.share_count || 0) + (nextShared ? 1 : -1)),
      save_count: snap.save_count,
      state: { shared: nextShared, saved: snap.is_saved }
    };
    stampPublicEngagement(card);
    updatePublicTrackState(postId, optimisticRes);
    if(window.MSBPostEngagement) window.MSBPostEngagement.publishFromTrack(postId, optimisticRes, { source: 'public-card' });
    btn.disabled = true;
    copyPublicShareLink(postId);
    $.post(COMMENT_API_URL + '?ajax=share', { post_id: postId, share_action: 'add' }, function(res){
      if(res && res.ok){
        stampPublicEngagement(card);
        updatePublicTrackState(postId, res);
        if(window.MSBPostEngagement) window.MSBPostEngagement.publishFromTrack(postId, res, { source: 'public-card' });
      } else {
        stampPublicEngagement(card);
        updatePublicTrackState(postId, { share_count: snap.share_count, save_count: snap.save_count, state: { shared: snap.is_shared, saved: snap.is_saved } });
        if(window.MSBPostEngagement) window.MSBPostEngagement.publish(postId, snap, { source: 'public-card' });
      }
      btn.disabled = false;
    }, 'json').fail(function(){
      stampPublicEngagement(card);
      updatePublicTrackState(postId, { share_count: snap.share_count, save_count: snap.save_count, state: { shared: snap.is_shared, saved: snap.is_saved } });
      btn.disabled = false;
    });
  });

  $(document).on('click', '.js-save-post', function(e){
    if (this.closest && this.closest('#reelApp, .reel-slide')) return;
    if (e.target && e.target.closest && e.target.closest('.js-save-count, .js-open-reactors')) return;
    e.preventDefault();
    e.stopPropagation();
    var btn = this;
    var postId = Number(btn.getAttribute('data-post-id') || 0);
    if(!postId || btn.disabled) return;
    var card = btn.closest('.public-post-card');
    var snap = publicCardCounts(card);
    var nextSaved = snap.is_saved ? 0 : 1;
    var optimisticRes = {
      save_count: Math.max(0, Number(snap.save_count || 0) + (nextSaved ? 1 : -1)),
      share_count: snap.share_count,
      state: { saved: nextSaved, shared: snap.is_shared }
    };
    stampPublicEngagement(card);
    updatePublicTrackState(postId, optimisticRes);
    if(window.MSBPostEngagement) window.MSBPostEngagement.publishFromTrack(postId, optimisticRes, { source: 'public-card' });
    btn.disabled = true;
    $.post(COMMENT_API_URL + '?ajax=save', { post_id: postId }, function(res){
      if(res && res.ok){
        stampPublicEngagement(card);
        updatePublicTrackState(postId, res);
        if(window.MSBPostEngagement) window.MSBPostEngagement.publishFromTrack(postId, res, { source: 'public-card' });
        try{
          var savedNow = !!(res.state && Number(res.state.saved || 0) === 1);
          if(window.MSBPostCardMenu && typeof window.MSBPostCardMenu.toast === 'function'){
            window.MSBPostCardMenu.toast(savedNow
              ? 'Added to Favorites. Find it in Settings → Favorites.'
              : 'Removed from Favorites.');
          }
        }catch(_eToast){}
      } else {
        stampPublicEngagement(card);
        updatePublicTrackState(postId, { share_count: snap.share_count, save_count: snap.save_count, state: { shared: snap.is_shared, saved: snap.is_saved } });
        if(window.MSBPostEngagement) window.MSBPostEngagement.publish(postId, snap, { source: 'public-card' });
      }
      btn.disabled = false;
    }, 'json').fail(function(){
      stampPublicEngagement(card);
      updatePublicTrackState(postId, { share_count: snap.share_count, save_count: snap.save_count, state: { shared: snap.is_shared, saved: snap.is_saved } });
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
      if(window.MSBPostEngagement){
        window.MSBPostEngagement.publishCommentCount(postId, comments.length, { source: 'public-comments' });
      }
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
    // Prefer feed-style View-the-post modal when available.
    if (typeof window.pvOpenById === 'function') {
      window.pvOpenById(publicAlertPostId, {
        hideNav: publicAlertHideNav || publicAlertCommentId > 0,
        commentId: publicAlertCommentId
      });
      if (publicAlertCommentId > 0) {
        var triesC = 0;
        (function waitComment(){
          triesC += 1;
          try {
            if (typeof window.pvFocusCommentById === 'function' && window.pvFocusCommentById(publicAlertCommentId)) {
              clearPublicAlertParams();
              return;
            }
          } catch (eFocus) {}
          if (triesC < 20) window.setTimeout(waitComment, 160);
          else clearPublicAlertParams();
        })();
        return;
      }
      clearPublicAlertParams();
      return;
    }
    var attempts = 0;
    function tryHighlight(){
      attempts += 1;
      var card = document.querySelector('.public-post-card[data-post-id="'+String(publicAlertPostId)+'"], #post-'+String(publicAlertPostId));
      var ready = true;
      if(card){
        if(card.classList.contains('is-single-video-post')){
          ready = card.classList.contains('mf-video-ready')
            || card.classList.contains('mf-video-error')
            || card.classList.contains('mf-frame-painted');
        }else if(card.classList.contains('is-single-image-post')){
          ready = card.classList.contains('mf-image-ready')
            || card.classList.contains('mf-image-error');
        }
      }else{
        ready = attempts > 40;
      }
      if(!ready && attempts < 80){
        window.setTimeout(tryHighlight, 120);
        return;
      }
      highlightPublicCard(publicAlertPostId);
      if(publicAlertCommentId > 0){
        if(window.TTComments && typeof window.TTComments.setFocusComment === 'function'){
          window.TTComments.setFocusComment(publicAlertCommentId);
        }
        document.body.classList.add('public-leftbar-open');
        fetchPublicPostComments(publicAlertPostId, true);
      }
      clearPublicAlertParams();
    }
    setTimeout(tryHighlight, 180);
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

  function publicMaxVideoHeight(){
    // Numeric budget for width math only (avatar/name + reacts stay visible).
    var viewportH = Math.max(window.innerHeight || 0, 320);
    var reserved = 250;
    var fitH = Math.max(280, viewportH - reserved);
    if(window.matchMedia('(max-width: 767.98px)').matches){
      return Math.min(Math.round(viewportH * 0.52), fitH, 580);
    }
    if(window.matchMedia('(max-width: 1024.98px)').matches){
      return Math.min(Math.round(viewportH * 0.54), fitH, 580);
    }
    return Math.min(Math.round(viewportH * 0.56), fitH, 580);
  }

  function publicMediaMaxHeightCss(){
    // Never bake a computed px (e.g. 397px) into inline styles — use CSS min().
    if(window.matchMedia('(max-width: 767.98px)').matches){
      return 'min(52vh, 580px)';
    }
    if(window.matchMedia('(max-width: 1024.98px)').matches){
      return 'min(54vh, 580px)';
    }
    return 'min(70vh, 580px)';
  }

  function publicComputeMediaCardWidth(aspectW, aspectH, opts){
    opts = opts || {};
    aspectW = Number(aspectW || 0);
    aspectH = Number(aspectH || 0);
    if(!aspectW || !aspectH) return 0;

    var aspect = aspectW / aspectH;
    var maxVideoH = publicMaxVideoHeight();
    var feed = opts.feedEl || document.querySelector('.ig-feed');
    var feedWidth = feed ? Math.floor(feed.clientWidth || 0) : Math.min(Math.max(window.innerWidth || 0, 320), 680);
    var cardPad = opts.cardPad != null ? Number(opts.cardPad) : 24;
    var availableWidth = Math.max(240, (feedWidth || 680) - cardPad);
    var isPhoneShot = !!opts.isPhoneShot;
    var desiredWidth = Math.round(aspect * maxVideoH);
    var isMobile = window.matchMedia('(max-width: 767.98px)').matches;
    var isTablet = window.matchMedia('(min-width: 768px) and (max-width: 1024.98px)').matches;

    if(isMobile){
      if(isPhoneShot){
        return Math.max(220, Math.min(availableWidth, Math.round(Math.min(window.innerWidth * 0.72, 320))));
      }
      var mobileMax = aspect < 0.8 ? 310 : (aspect > 1.15 ? Math.min(availableWidth, 380) : 330);
      return Math.max(220, Math.min(desiredWidth, availableWidth, mobileMax));
    }

    if(isTablet){
      var tabletMax = aspect < 0.8 ? 400 : (aspect > 1.15 ? Math.min(availableWidth, 560) : 440);
      return Math.max(260, Math.min(desiredWidth, availableWidth, tabletMax));
    }

    var maxByShape = aspect < 0.8 ? 400 : (aspect > 1.15 ? 680 : 520);
    return Math.max(260, Math.min(desiredWidth, availableWidth, maxByShape));
  }

  function applyPublicVideoCardWidth(card, aspectW, aspectH){
    if(!card) return;
    aspectW = Number(aspectW || 0);
    aspectH = Number(aspectH || 0);
    if(!aspectW || !aspectH) return;

    var isPhoneShot = !!card.querySelector('.media-stage.phone-shot');
    var safeWidth = publicComputeMediaCardWidth(aspectW, aspectH, {
      isPhoneShot: isPhoneShot,
      feedEl: card.closest('.ig-feed'),
      cardPad: 24
    });
    if(!safeWidth) return;

    var maxH = publicMediaMaxHeightCss();
    card.style.width = '100%';
    card.style.maxWidth = '100%';
    card.style.marginLeft = '0';
    card.style.marginRight = '0';
    card.style.setProperty('box-sizing', 'border-box', 'important');
    card.style.setProperty('padding', '8px 12px', 'important');
    card.style.setProperty('--post-media-card-width', String(safeWidth) + 'px');
    card.style.setProperty('--post-media-max-height', maxH);

    var media = card.querySelector('.media-stage.standard-video-stage, .media-stage.standard-image-stage');
    var video = card.querySelector('.media-stage.standard-video-stage > video');
    var image = card.querySelector('.media-stage.standard-image-stage > img');
    var isHeadOutside = card.classList.contains('public-media-head-outside');
    if(media){
      if(isHeadOutside){
        media.style.width = '100%';
        media.style.maxWidth = '100%';
        media.style.marginLeft = '0';
        media.style.marginRight = '0';
      }else{
        media.style.width = 'min(100%, ' + String(safeWidth) + 'px)';
        media.style.maxWidth = '100%';
        media.style.marginLeft = '0';
        media.style.marginRight = 'auto';
      }
      media.style.height = 'auto';
      media.style.aspectRatio = 'auto';
      media.style.background = 'transparent';
      media.style.setProperty('overflow', isHeadOutside ? 'visible' : 'hidden', 'important');
      if(!isHeadOutside){
        media.style.setProperty('max-height', maxH, 'important');
      }else{
        media.style.removeProperty('max-height');
      }
      media.style.removeProperty('min-height');
      try{
        media.classList.remove('single-portrait', 'single-landscape', 'single-square');
      }catch(e){}
    }
    if(video){
      video.style.setProperty('width', isHeadOutside ? ('min(100%, ' + String(safeWidth) + 'px)') : '100%', 'important');
      video.style.setProperty('max-width', '100%', 'important');
      video.style.setProperty('height', 'auto', 'important');
      video.style.setProperty('max-height', maxH, 'important');
      video.style.setProperty('object-fit', 'contain', 'important');
      video.style.setProperty('object-position', 'center center', 'important');
      video.style.setProperty('margin-left', '0', 'important');
      video.style.setProperty('margin-right', 'auto', 'important');
      video.style.setProperty('justify-self', 'start', 'important');
      video.style.background = 'transparent';
      video.style.removeProperty('padding');
    }
    if(image){
      image.style.setProperty('width', isHeadOutside ? ('min(100%, ' + String(safeWidth) + 'px)') : '100%', 'important');
      image.style.setProperty('max-width', '100%', 'important');
      image.style.setProperty('height', 'auto', 'important');
      image.style.setProperty('max-height', maxH, 'important');
      image.style.setProperty('object-fit', 'contain', 'important');
      image.style.setProperty('object-position', 'center center', 'important');
      image.style.setProperty('margin-left', '0', 'important');
      image.style.setProperty('margin-right', 'auto', 'important');
      image.style.setProperty('justify-self', 'start', 'important');
      image.style.background = 'transparent';
      image.style.removeProperty('padding');
      image.style.removeProperty('box-sizing');
    }
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

  function markPublicPaintedFrame(video){
    if(!video) return;
    try{ video.classList.add('mf-frame-painted'); }catch(e){}
    try{
      var card = video.closest('.public-post-card.is-single-video-post');
      if(card) card.classList.add('mf-frame-painted');
    }catch(e){}
  }

  function waitForPublicPaintedFrame(video){
    if(!video) return;
    window.setTimeout(function(){ markPublicPaintedFrame(video); }, 700);
    if(video.dataset && video.dataset.publicFramePaintPending === '1') return;
    try{ video.dataset.publicFramePaintPending = '1'; }catch(e){}
    if(typeof video.requestVideoFrameCallback === 'function'){
      try{
        video.requestVideoFrameCallback(function(){
          try{ video.dataset.publicFramePaintPending = '0'; }catch(e){}
          markPublicPaintedFrame(video);
        });
        return;
      }catch(e){}
    }
    window.requestAnimationFrame(function(){
      window.requestAnimationFrame(function(){
        try{ video.dataset.publicFramePaintPending = '0'; }catch(e){}
        // Decoded frame is enough — do not require playback (autoplay can be delayed).
        if(Number(video.readyState || 0) >= 2) markPublicPaintedFrame(video);
      });
    });
  }

  function revealPublicVideoCard(video){
    if(!video) return;
    if(Number(video.readyState || 0) < 2) return;
    var card = video.closest('.is-single-video-post');
    var stage = video.closest('.media-stage.standard-video-stage');
    syncStandardMediaCard(video);
    markPublicMediaReady(card, stage);
    // Kick muted playback so the first frame paints after create-post redirects.
    try{
      video.muted = true;
      video.playsInline = true;
      video.setAttribute('playsinline', '');
      video.setAttribute('preload', 'auto');
    }catch(eMute){}
    try{
      var playPromise = video.play && video.play();
      if(playPromise && typeof playPromise.then === 'function'){
        playPromise.then(function(){
          waitForPublicPaintedFrame(video);
        }).catch(function(){
          waitForPublicPaintedFrame(video);
        });
      }else{
        waitForPublicPaintedFrame(video);
      }
    }catch(ePlay){
      waitForPublicPaintedFrame(video);
    }
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
        if(video.getAttribute('preload') !== 'auto'){
          video.setAttribute('preload', 'auto');
        }
        video.load();
      }catch(e){}
      video.addEventListener('loadeddata', reveal, { once:true });
      video.addEventListener('canplay', reveal, { once:true });
      video.addEventListener('error', function(){
        var retries = Number(video.dataset.publicLoadRetries || 0);
        if(retries >= 2){
          if(card) card.classList.add('mf-video-error');
          return;
        }
        video.dataset.publicLoadRetries = String(retries + 1);
        window.setTimeout(function(){
          try{ video.load(); }catch(e){}
        }, 180 * (retries + 1));
      });
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
        var retries = Number(img.dataset.publicLoadRetries || 0);
        if(retries >= 2){
          if(card) card.classList.add('mf-image-error');
          return;
        }
        img.dataset.publicLoadRetries = String(retries + 1);
        window.setTimeout(function(){
          try{
            var src = img.currentSrc || img.getAttribute('src') || '';
            if(src) img.setAttribute('src', src);
          }catch(e){}
        }, 180 * (retries + 1));
      });
    });
  }

  function resetPublicMediaReadyState(){
    document.querySelectorAll('.is-single-video-post, .is-single-image-post').forEach(function(card){
      card.classList.remove('mf-video-ready', 'mf-image-ready');
      var stage = card.querySelector('.media-stage.standard-video-stage, .media-stage.standard-image-stage');
      if(stage) stage.classList.remove('mf-media-sized');
    });
  }

  function publicFirstCardIsReady(){
    var feed = document.querySelector('.feed-desktop-center > .ig-feed') || document.querySelector('.ig-feed');
    if(!feed) return true;
    var card = feed.querySelector('.public-post-card');
    if(!card) return true;
    if(card.classList.contains('mf-video-error') || card.classList.contains('mf-image-error')) return true;
    if(card.classList.contains('is-single-video-post')){
      // Prefer a painted frame; fall back to decoded media so we do not
      // linger on a blank hydrating feed after create-post redirects.
      return card.classList.contains('mf-frame-painted')
        || card.classList.contains('mf-video-ready');
    }
    if(card.classList.contains('is-single-image-post')){
      return card.classList.contains('mf-image-ready');
    }
    return true;
  }

  function revealPublicFeedWhenReady(){
    var feed = document.querySelector('.feed-desktop-center > .ig-feed') || document.querySelector('.ig-feed');
    if(!feed) return;
    var attempts = 0;
    var loadingStartedAt = Date.now();
    var freshCreate = false;
    try{
      freshCreate = new URL(window.location.href).searchParams.get('fresh') === '1';
    }catch(eFresh){}
    var maxWaitMs = freshCreate ? 8000 : 1500;
    function tick(){
      attempts += 1;
      syncAllStandardMediaCards();
      if(publicFirstCardIsReady() || (Date.now() - loadingStartedAt) >= maxWaitMs){
        feed.classList.remove('public-media-hydrating');
        feed.setAttribute('aria-busy','false');
        if(freshCreate){
          try{
            var u = new URL(window.location.href);
            if(u.searchParams.has('fresh')){
              u.searchParams.delete('fresh');
              window.history.replaceState({}, '', u.pathname + (u.search ? u.search : '') + u.hash);
            }
          }catch(_u){}
        }
        return;
      }
      window.requestAnimationFrame(tick);
    }
    window.requestAnimationFrame(tick);
  }

  function bootPublicMediaCards(){
    var feed = document.querySelector('.feed-desktop-center > .ig-feed') || document.querySelector('.ig-feed');
    if(feed){
      feed.classList.add('public-media-hydrating');
      feed.setAttribute('aria-busy','true');
    }
    resetPublicMediaReadyState();
    preflightAllSingleMediaCards();
    primePublicStandardVideos();
    bindPublicStandardImages();
    syncAllStandardMediaCards();
    revealPublicFeedWhenReady();
  }
  window.msbBootPublicMediaCards = bootPublicMediaCards;

  document.querySelectorAll('.is-single-video-post .media-stage.standard-video-stage > video').forEach(function(video){
    video.addEventListener('loadedmetadata', function(){
      syncStandardVideoCard(video);
      var card = video.closest('.public-post-card');
      if(card && card === currentCard()) bindPublicAutoAdvance(card);
    });
    video.addEventListener('loadeddata', function(){ revealPublicVideoCard(video); });
    video.addEventListener('canplay', function(){ revealPublicVideoCard(video); });
    video.addEventListener('playing', function(){ waitForPublicPaintedFrame(video); });
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

  var publicFeedScrollEl = document.querySelector('.feed-desktop-center > .ig-feed') || document.querySelector('.ig-feed');
  if (publicFeedScrollEl) {
    publicFeedScrollEl.addEventListener('scroll', function(){
      if(publicAutoAdvanceScrollTick){
        window.clearTimeout(publicAutoAdvanceScrollTick);
      }
      publicAutoAdvanceScrollTick = window.setTimeout(function(){
        publicAutoAdvanceScrollTick = null;
        refreshPublicAutoAdvance();
      }, 140);
    }, { passive:true });
  }

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

  function mfStoriesCreateHtml(){
    if(<?= $staffReadonly ? 'true' : 'false' ?>) return '';
    return ''
      + '<a class="ig-story-item ig-story-create" href="dashboard.php?modal=1&story=1" data-create-post-modal="1" aria-label="Create a story">'
      + '  <div class="ig-story-ring ig-story-ring-create"><i class="icon ion-plus" aria-hidden="true"></i></div>'
      + '</a>';
  }

  function setStoriesBarEmptyState(isEmpty){
    var track = document.getElementById('igStoriesTrack');
    if(!track) return;
    var bar = track.closest('.ig-stories-bar');
    if(bar) bar.classList.toggle('is-empty', !!isEmpty);
    track.classList.toggle('is-empty', !!isEmpty);
    track.classList.toggle('has-create', <?= $staffReadonly ? 'false' : 'true' ?>);
  }

  function renderPublicStoriesBar(items){
    items = Array.isArray(items) ? items : [];
    if(window.TTStories && typeof window.TTStories.setCatalog === 'function'){
      window.TTStories.setCatalog(items);
    }
    var track = document.getElementById('igStoriesTrack');
    if(!track) return;
    if(!items.length){
      track.innerHTML = mfStoriesCreateHtml() + mfStoriesEmptyHtml();
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
    var html = mfStoriesCreateHtml();
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

  function openTalsoraCircle(){
    var me = Number(window.ME_ID || <?php echo (int)$meId; ?> || 0);
    if(!me || !window.TTStories) return;
    var items = (typeof window.TTStories.getCatalog === 'function') ? (window.TTStories.getCatalog() || []) : [];
    for(var i = 0; i < items.length; i += 1){
      if(Number((items[i] || {}).userId || 0) !== me) continue;
      if(typeof window.TTStories.openByIndex === 'function'){
        window.TTStories.openByIndex(i);
      } else if(typeof window.TTStories.openByKey === 'function'){
        window.TTStories.openByKey(String(items[i].key || ''));
      }
      return;
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
        setTimeout(openTalsoraCircle, 120);
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
html[data-msb-appearance] body.public-page .post.public-post-card.public-media-head-outside:not(.is-reel-post) .standard-media-topbar > .post-card-menu-wrap,
html[data-msb-appearance] body.news-page .post.public-post-card.public-media-head-outside:not(.is-reel-post) .standard-media-topbar > .post-card-menu-wrap{
  top:50% !important;
  right:0 !important;
  transform:translateY(-50%) !important;
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
  border-bottom:1px solid var(--feed-post-divider, var(--public-border-strong, #c0c2c4)) !important;
}
body.news-page.feed-insta-ui .post.public-post-card{
  margin:0 !important;
  border:0 !important;
  border-bottom:1px solid var(--feed-post-divider, var(--public-border-strong, #c0c2c4)) !important;
  border-radius:0 !important;
  box-shadow:none !important;
  position:relative !important;
  width:100% !important;
  max-width:100% !important;
  display:block !important;
  box-sizing:border-box !important;
  overflow:visible !important;
}
html[data-msb-appearance] body.news-page.feed-insta-ui .post.public-post-card{
  box-shadow:none !important;
  border-bottom:1px solid var(--feed-post-divider, var(--msb-palette-border-strong, var(--public-border-strong, #c0c2c4))) !important;
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
  max-height:var(--post-media-max-height, min(70vh, 580px)) !important;
  margin-left:0 !important;
  margin-right:auto !important;
}
body.news-page.feed-insta-ui .feed-desktop-layout .ig-feed{
  margin:0 !important;
}
</style>
<?php endif; ?>
<style id="public-news-light-black-actions-css">
html:not([data-theme="dark"]):not(.dark-auto) body.public-page.feed-insta-ui,
html:not([data-theme="dark"]):not(.dark-auto) body.news-page.feed-insta-ui{
  --public-accent:#0b1220;
  --public-accent-strong:#000000;
  --msb-palette-action:#0b1220;
  --msb-palette-action-strong:#000000;
  --msb-palette-link:#0b1220;
  --msb-palette-link-hover:#000000;
  --msb-palette-btn-bg:#0b1220;
  --msb-palette-btn-hover-bg:#000000;
  --msb-palette-btn-text:#ffffff;
}
html:not([data-theme="dark"]):not(.dark-auto) body.public-page.feed-insta-ui .feed-ig-link,
html:not([data-theme="dark"]):not(.dark-auto) body.news-page.feed-insta-ui .feed-ig-link,
html:not([data-theme="dark"]):not(.dark-auto) body.public-page.feed-insta-ui .feed-ig-link i,
html:not([data-theme="dark"]):not(.dark-auto) body.news-page.feed-insta-ui .feed-ig-link i,
html:not([data-theme="dark"]):not(.dark-auto) body.public-page.feed-insta-ui .ig-link,
html:not([data-theme="dark"]):not(.dark-auto) body.news-page.feed-insta-ui .ig-link,
html:not([data-theme="dark"]):not(.dark-auto) body.public-page.feed-insta-ui .ig-link i,
html:not([data-theme="dark"]):not(.dark-auto) body.news-page.feed-insta-ui .ig-link i,
html:not([data-theme="dark"]):not(.dark-auto) body.public-page.feed-insta-ui .feed-left-nav-item,
html:not([data-theme="dark"]):not(.dark-auto) body.news-page.feed-insta-ui .feed-left-nav-item,
html:not([data-theme="dark"]):not(.dark-auto) body.public-page.feed-insta-ui .feed-left-nav-item:hover,
html:not([data-theme="dark"]):not(.dark-auto) body.news-page.feed-insta-ui .feed-left-nav-item:hover,
html:not([data-theme="dark"]):not(.dark-auto) body.public-page.feed-insta-ui .feed-left-nav-item:focus,
html:not([data-theme="dark"]):not(.dark-auto) body.news-page.feed-insta-ui .feed-left-nav-item:focus,
html:not([data-theme="dark"]):not(.dark-auto) body.public-page.feed-insta-ui .feed-left-nav-item.is-active,
html:not([data-theme="dark"]):not(.dark-auto) body.news-page.feed-insta-ui .feed-left-nav-item.is-active,
html:not([data-theme="dark"]):not(.dark-auto) body.public-page.feed-insta-ui .feed-left-nav-ic,
html:not([data-theme="dark"]):not(.dark-auto) body.news-page.feed-insta-ui .feed-left-nav-ic,
html:not([data-theme="dark"]):not(.dark-auto) body.public-page.feed-insta-ui .feed-left-nav-ic svg,
html:not([data-theme="dark"]):not(.dark-auto) body.news-page.feed-insta-ui .feed-left-nav-ic svg,
html:not([data-theme="dark"]):not(.dark-auto) body.public-page.feed-insta-ui .feed-left-nav-label,
html:not([data-theme="dark"]):not(.dark-auto) body.news-page.feed-insta-ui .feed-left-nav-label,
html:not([data-theme="dark"]):not(.dark-auto) body.public-page.feed-insta-ui .feed-right-nav-item,
html:not([data-theme="dark"]):not(.dark-auto) body.news-page.feed-insta-ui .feed-right-nav-item,
html:not([data-theme="dark"]):not(.dark-auto) body.public-page.feed-insta-ui .feed-right-nav-ic,
html:not([data-theme="dark"]):not(.dark-auto) body.news-page.feed-insta-ui .feed-right-nav-ic,
html:not([data-theme="dark"]):not(.dark-auto) body.public-page.feed-insta-ui .feed-right-nav-ic svg,
html:not([data-theme="dark"]):not(.dark-auto) body.news-page.feed-insta-ui .feed-right-nav-ic svg,
html:not([data-theme="dark"]):not(.dark-auto) body.public-page.feed-insta-ui .feed-right-nav-label,
html:not([data-theme="dark"]):not(.dark-auto) body.news-page.feed-insta-ui .feed-right-nav-label,
html:not([data-theme="dark"]):not(.dark-auto) body.public-page.feed-insta-ui .yt-icon-btn,
html:not([data-theme="dark"]):not(.dark-auto) body.news-page.feed-insta-ui .yt-icon-btn,
html:not([data-theme="dark"]):not(.dark-auto) body.public-page.feed-insta-ui .yt-icon-btn i,
html:not([data-theme="dark"]):not(.dark-auto) body.news-page.feed-insta-ui .yt-icon-btn i,
html:not([data-theme="dark"]):not(.dark-auto) body.public-page.feed-insta-ui .search-btn,
html:not([data-theme="dark"]):not(.dark-auto) body.news-page.feed-insta-ui .search-btn,
html:not([data-theme="dark"]):not(.dark-auto) body.public-page.feed-insta-ui .search-btn i,
html:not([data-theme="dark"]):not(.dark-auto) body.news-page.feed-insta-ui .search-btn i,
html:not([data-theme="dark"]):not(.dark-auto) body.public-page.feed-insta-ui .open-inline,
html:not([data-theme="dark"]):not(.dark-auto) body.news-page.feed-insta-ui .open-inline,
html:not([data-theme="dark"]):not(.dark-auto) body.public-page.feed-insta-ui .js-open-readmore,
html:not([data-theme="dark"]):not(.dark-auto) body.news-page.feed-insta-ui .js-open-readmore,
html:not([data-theme="dark"]):not(.dark-auto) body.public-page.feed-insta-ui .standard-text-btn:not(.is-love):not(.is-like):not(.is-reacted),
html:not([data-theme="dark"]):not(.dark-auto) body.news-page.feed-insta-ui .standard-text-btn:not(.is-love):not(.is-like):not(.is-reacted),
html:not([data-theme="dark"]):not(.dark-auto) body.public-page.feed-insta-ui .standard-text-btn:not(.is-love):not(.is-like):not(.is-reacted) i,
html:not([data-theme="dark"]):not(.dark-auto) body.news-page.feed-insta-ui .standard-text-btn:not(.is-love):not(.is-like):not(.is-reacted) i,
html:not([data-theme="dark"]):not(.dark-auto) body.public-page.feed-insta-ui .standard-media-btn:not(.is-love):not(.is-like):not(.is-reacted),
html:not([data-theme="dark"]):not(.dark-auto) body.news-page.feed-insta-ui .standard-media-btn:not(.is-love):not(.is-like):not(.is-reacted),
html:not([data-theme="dark"]):not(.dark-auto) body.public-page.feed-insta-ui .standard-media-btn:not(.is-love):not(.is-like):not(.is-reacted) i,
html:not([data-theme="dark"]):not(.dark-auto) body.news-page.feed-insta-ui .standard-media-btn:not(.is-love):not(.is-like):not(.is-reacted) i,
html:not([data-theme="dark"]):not(.dark-auto) body.public-page.feed-insta-ui .action-btn:not(.is-love):not(.is-like):not(.is-reacted),
html:not([data-theme="dark"]):not(.dark-auto) body.news-page.feed-insta-ui .action-btn:not(.is-love):not(.is-like):not(.is-reacted),
html:not([data-theme="dark"]):not(.dark-auto) body.public-page.feed-insta-ui .action-btn:not(.is-love):not(.is-like):not(.is-reacted) i,
html:not([data-theme="dark"]):not(.dark-auto) body.news-page.feed-insta-ui .action-btn:not(.is-love):not(.is-like):not(.is-reacted) i,
html:not([data-theme="dark"]):not(.dark-auto) body.public-page.feed-insta-ui .reel-inline-btn:not(.is-love):not(.is-like):not(.is-reacted),
html:not([data-theme="dark"]):not(.dark-auto) body.news-page.feed-insta-ui .reel-inline-btn:not(.is-love):not(.is-like):not(.is-reacted),
html:not([data-theme="dark"]):not(.dark-auto) body.public-page.feed-insta-ui .reel-inline-btn:not(.is-love):not(.is-like):not(.is-reacted) i,
html:not([data-theme="dark"]):not(.dark-auto) body.news-page.feed-insta-ui .reel-inline-btn:not(.is-love):not(.is-like):not(.is-reacted) i{
  color:#0b1220!important;
  -webkit-text-fill-color:#0b1220!important;
}
/* Keep selected reaction colors fixed (match picker) — do not force black */
html:not([data-theme="dark"]):not(.dark-auto) body.public-page.feed-insta-ui .standard-text-btn.is-like i,
html:not([data-theme="dark"]):not(.dark-auto) body.news-page.feed-insta-ui .standard-text-btn.is-like i,
html:not([data-theme="dark"]):not(.dark-auto) body.public-page.feed-insta-ui .standard-media-btn.is-like i,
html:not([data-theme="dark"]):not(.dark-auto) body.news-page.feed-insta-ui .standard-media-btn.is-like i,
html:not([data-theme="dark"]):not(.dark-auto) body.public-page.feed-insta-ui .action-btn.is-like i,
html:not([data-theme="dark"]):not(.dark-auto) body.news-page.feed-insta-ui .action-btn.is-like i,
html:not([data-theme="dark"]):not(.dark-auto) body.public-page.feed-insta-ui .reel-inline-btn.is-like i,
html:not([data-theme="dark"]):not(.dark-auto) body.news-page.feed-insta-ui .reel-inline-btn.is-like i,
html:not([data-theme="dark"]):not(.dark-auto) body.public-page.feed-insta-ui .has-rx-icon[data-selected-reaction="like"] .msb-pact-thumb,
html:not([data-theme="dark"]):not(.dark-auto) body.news-page.feed-insta-ui .has-rx-icon[data-selected-reaction="like"] .msb-pact-thumb{
  color:var(--msb-rx-like, #2563eb)!important;
  -webkit-text-fill-color:var(--msb-rx-like, #2563eb)!important;
}
html:not([data-theme="dark"]):not(.dark-auto) body.public-page.feed-insta-ui .standard-text-btn.is-love i,
html:not([data-theme="dark"]):not(.dark-auto) body.news-page.feed-insta-ui .standard-text-btn.is-love i,
html:not([data-theme="dark"]):not(.dark-auto) body.public-page.feed-insta-ui .standard-media-btn.is-love i,
html:not([data-theme="dark"]):not(.dark-auto) body.news-page.feed-insta-ui .standard-media-btn.is-love i,
html:not([data-theme="dark"]):not(.dark-auto) body.public-page.feed-insta-ui .has-rx-icon[data-selected-reaction="love"] .msb-pact-heart,
html:not([data-theme="dark"]):not(.dark-auto) body.news-page.feed-insta-ui .has-rx-icon[data-selected-reaction="love"] .msb-pact-heart{
  color:var(--msb-rx-love, #ff4d6d)!important;
  -webkit-text-fill-color:var(--msb-rx-love, #ff4d6d)!important;
}
html:not([data-theme="dark"]):not(.dark-auto) body.public-page.feed-insta-ui .has-rx-icon[data-selected-reaction="dislike"] .msb-pact-thumb-down,
html:not([data-theme="dark"]):not(.dark-auto) body.news-page.feed-insta-ui .has-rx-icon[data-selected-reaction="dislike"] .msb-pact-thumb-down{
  color:var(--msb-rx-dislike, #475569)!important;
  -webkit-text-fill-color:var(--msb-rx-dislike, #475569)!important;
}
html:not([data-theme="dark"]):not(.dark-auto) body.public-page.feed-insta-ui .btn-primary,
html:not([data-theme="dark"]):not(.dark-auto) body.news-page.feed-insta-ui .btn-primary,
html:not([data-theme="dark"]):not(.dark-auto) body.public-page.feed-insta-ui button.btn-primary,
html:not([data-theme="dark"]):not(.dark-auto) body.news-page.feed-insta-ui button.btn-primary{
  background:#0b1220!important;
  border-color:#0b1220!important;
  color:#ffffff!important;
  -webkit-text-fill-color:#ffffff!important;
}
html:not([data-theme="dark"]):not(.dark-auto) body.public-page.feed-insta-ui .standard-media-topbar,
html:not([data-theme="dark"]):not(.dark-auto) body.news-page.feed-insta-ui .standard-media-topbar,
html:not([data-theme="dark"]):not(.dark-auto) body.public-page.feed-insta-ui .standard-media-topbar .standard-media-name,
html:not([data-theme="dark"]):not(.dark-auto) body.news-page.feed-insta-ui .standard-media-topbar .standard-media-name,
html:not([data-theme="dark"]):not(.dark-auto) body.public-page.feed-insta-ui .standard-media-topbar .standard-media-time,
html:not([data-theme="dark"]):not(.dark-auto) body.news-page.feed-insta-ui .standard-media-topbar .standard-media-time,
html:not([data-theme="dark"]):not(.dark-auto) body.public-page.feed-insta-ui .standard-media-topbar .post-card-menu-btn,
html:not([data-theme="dark"]):not(.dark-auto) body.news-page.feed-insta-ui .standard-media-topbar .post-card-menu-btn,
html:not([data-theme="dark"]):not(.dark-auto) body.public-page.feed-insta-ui .standard-media-topbar .pcm-fries-icon,
html:not([data-theme="dark"]):not(.dark-auto) body.news-page.feed-insta-ui .standard-media-topbar .pcm-fries-icon{
  color:#ffffff!important;
  -webkit-text-fill-color:#ffffff!important;
}
</style>
<style id="public-match-feed-size-final">
/*
 * Visual sizing source of truth: feed.php.
 * Public keeps its own post data, filters, suggestions and interaction logic.
 */
.feed-discover-tabs{
  view-transition-name:none!important;
  transition:none!important;
  animation:none!important;
  contain:layout!important;
}
.feed-discover-tab,
.feed-discover-tab.is-active,
.feed-discover-tab::after,
.feed-discover-tab.is-active::after{
  transition:none!important;
  animation:none!important;
  transform:none;
}
.feed-discover-tab.is-active::after{
  transform:translateX(-50%)!important;
}
@media (min-width:1025px){
  body.public-page.feed-insta-ui .ig-feed-header{
    width:100%!important;
    margin:0!important;
    padding:16px 16px 14px calc(var(--feedRailW, 84px) + 16px)!important;
    box-sizing:border-box!important;
    border-bottom:1px solid var(--msb-palette-border-strong, var(--public-border-strong, #d1d5db))!important;
  }
  body.public-page.feed-insta-ui .ig-stories-wrap{
    width:100%!important;
    max-width:614px!important;
    margin:0 auto!important;
  }
  body.public-page.feed-insta-ui .ig-stories-track.is-empty{
    justify-content:center!important;
    min-height:44px!important;
  }
  body.public-page.feed-insta-ui .ig-stories-track.has-create.is-empty{
    justify-content:flex-start!important;
  }
  body.public-page.feed-insta-ui .ig-stories-bar{
    display:flex!important;
    width:100%!important;
    align-items:flex-start!important;
  }
  body.public-page.feed-insta-ui .ig-stories-track{
    gap:18px!important;
    padding:0 2px 2px!important;
  }
  body.public-page.feed-insta-ui .ig-story-item{
    width:50px!important;
    min-width:50px!important;
    padding:0!important;
  }
  body.public-page.feed-insta-ui .ig-story-ring{
    width:44px!important;
    height:44px!important;
    margin:0 auto 4px!important;
    padding:2px!important;
    box-sizing:border-box!important;
  }
  body.public-page.feed-insta-ui .ig-feed-top-lead{left:calc(var(--feedRailW, 84px) + 16px)!important}
  body.public-page.feed-insta-ui .ig-feed-top-actions{
    right:16px!important;
    gap:10px!important;
  }
  body.public-page.feed-insta-ui .ig-top-mic,
  body.public-page.feed-insta-ui .ig-top-shop{
    width:44px!important;
    height:44px!important;
    font-size:18px!important;
  }
  body.public-page.feed-insta-ui .ig-top-live{
    min-height:44px!important;
    padding:0 18px!important;
    gap:8px!important;
    font-size:15px!important;
  }
  body.public-page.feed-insta-ui .feed-desktop-center{
    width:614px!important;
    max-width:614px!important;
    min-width:0!important;
    margin-left:max(
      var(--feed-center-left),
      calc((100% - var(--feed-center-w, 614px)) / 2)
    )!important;
    margin-right:auto!important;
    box-sizing:border-box!important;
  }
  body.public-page.feed-insta-ui .feed-top-search{
    width:100%!important;
    margin:0!important;
    padding:12px 24px 0!important;
    box-sizing:border-box!important;
  }
  body.public-page.feed-insta-ui .feed-top-search-row{gap:16px!important}
  body.public-page.feed-insta-ui .feed-top-search-input{
    height:42px!important;
    padding:0 18px 0 46px!important;
    font-size:15px!important;
    box-sizing:border-box!important;
  }
  body.public-page.feed-insta-ui .feed-top-search-settings{
    width:42px!important;
    height:42px!important;
    flex:0 0 42px!important;
    font-size:19px!important;
  }
  body.public-page.feed-insta-ui .feed-discover-tabs{margin-top:8px!important}
  body.public-page.feed-insta-ui .feed-discover-tab,
  body.public-page.feed-insta-ui .feed-discover-tab.is-active{
    min-width:max-content!important;
    /* padding:12px 12px 14px!important; */
    font-size:13px!important;
    font-weight:400!important;
    line-height:1.2!important;
  }
  body.public-page.feed-insta-ui .feed-desktop-layout .ig-feed{
    width:100%!important;
    max-width:100%!important;
    margin:0!important;
    padding:0 0 96px!important;
  }

  /* Match feed.php: full-width card, left-packed constrained media, 8×12 padding. */
  body.public-page.feed-insta-ui .post.public-post-card.is-single-video-post:not(.is-reel-post),
  body.public-page.feed-insta-ui .post.public-post-card.is-single-image-post:not(.is-reel-post){
    width:100%!important;
    max-width:100%!important;
    margin-left:0!important;
    margin-right:0!important;
    padding:8px 12px!important;
    box-sizing:border-box!important;
  }
  body.public-page.feed-insta-ui .post.public-post-card:not(.is-reel-post):not(.public-media-head-outside) .media-stage.standard-video-stage,
  body.public-page.feed-insta-ui .post.public-post-card:not(.is-reel-post):not(.public-media-head-outside) .media-stage.standard-image-stage{
    display:grid!important;
    grid-template-areas:"stack" "bottom"!important;
    width:min(100%, var(--post-media-card-width, 100%))!important;
    max-width:100%!important;
    margin-left:0!important;
    margin-right:auto!important;
    box-sizing:border-box!important;
    overflow:hidden!important;
  }
  body.public-page.feed-insta-ui .post.public-post-card.public-media-head-outside:not(.is-reel-post) .media-stage.standard-video-stage,
  body.public-page.feed-insta-ui .post.public-post-card.public-media-head-outside:not(.is-reel-post) .media-stage.standard-image-stage{
    display:grid!important;
    grid-template-areas:none!important;
    width:100%!important;
    max-width:100%!important;
    margin-left:0!important;
    margin-right:0!important;
    box-sizing:border-box!important;
    overflow:visible!important;
  }
  body.public-page.feed-insta-ui .post.public-post-card:not(.is-reel-post):not(.public-media-head-outside) .media-stage > :first-child{
    grid-area:stack!important;
    width:100%!important;
    max-width:100%!important;
    margin:0!important;
    border-radius:var(--post-media-radius,10px)!important;
    overflow:hidden!important;
  }
  body.public-page.feed-insta-ui .post.public-post-card.public-media-head-outside.is-single-video-post:not(.is-reel-post) .media-stage > video,
  body.public-page.feed-insta-ui .post.public-post-card.public-media-head-outside.is-single-image-post:not(.is-reel-post) .media-stage > img,
  body.public-page.feed-insta-ui .post.public-post-card.public-media-head-outside.is-single-video-post:not(.is-reel-post) .media-stage > .media-carousel,
  body.public-page.feed-insta-ui .post.public-post-card.public-media-head-outside.is-single-image-post:not(.is-reel-post) .media-stage > .media-carousel{
    width:min(100%, var(--post-media-card-width, 100%))!important;
    max-width:100%!important;
    margin-left:0!important;
    margin-right:auto!important;
    justify-self:start!important;
    border-radius:var(--post-media-radius,10px)!important;
    overflow:hidden!important;
  }
  body.public-page.feed-insta-ui .post.public-post-card:not(.is-reel-post) .standard-media-topbar{
    padding:1px 0 12px!important;
    gap:10px!important;
    box-sizing:border-box!important;
    width:100%!important;
    max-width:100%!important;
  }
  body.public-page.feed-insta-ui .standard-media-topbar .standard-media-author .avatar{
    width:35px!important;
    height:35px!important;
    flex:0 0 35px!important;
  }
  body.public-page.feed-insta-ui .standard-media-author{gap:12px!important}
  body.public-page.feed-insta-ui .standard-media-name{
    font-size:14px!important;
    line-height:1.2!important;
    font-weight:700!important;
  }
  body.public-page.feed-insta-ui .standard-media-time{
    font-size:13px!important;
    line-height:1.2!important;
  }
  body.public-page.feed-insta-ui .post.public-post-card:not(.is-reel-post) .standard-media-bottom{
    grid-area:bottom!important;
    position:static!important;
    left:auto!important;
    right:auto!important;
    bottom:auto!important;
    width:100%!important;
    max-width:100%!important;
    margin:0!important;
    padding:0!important;
    box-sizing:border-box!important;
  }
  body.public-page.feed-insta-ui .standard-media-actions{
    display:flex!important;
    align-items:center!important;
    justify-content:space-between!important;
    gap:10px!important;
    width:100%!important;
  }
  body.public-page.feed-insta-ui .standard-media-left{
    flex:1 1 auto!important;
    min-width:0!important;
  }
  body.public-page.feed-insta-ui .standard-media-right{
    flex:0 0 auto!important;
    margin-left:auto!important;
  }
  body.public-page.feed-insta-ui .post.public-post-card:not(.is-reel-post) .standard-media-topbar{
    display:flex!important;
    align-items:center!important;
    justify-content:space-between!important;
    width:100%!important;
  }
  body.public-page.feed-insta-ui .post.public-post-card:not(.is-reel-post) .standard-media-topbar > .post-card-menu-wrap{
    margin-left:auto!important;
    flex:0 0 auto!important;
  }
  body.public-page.feed-insta-ui .standard-media-row{gap:20px!important}
  body.public-page.feed-insta-ui .standard-media-btn{
    gap:8px!important;
    padding:0!important;
    font-size:14px!important;
    line-height:1!important;
  }
  body.public-page.feed-insta-ui .standard-media-btn i{font-size:20px!important}
  body.public-page.feed-insta-ui .standard-media-btn .action-count{
    font-size:13px!important;
    line-height:1!important;
    font-weight:600!important;
  }
  body.public-page.feed-insta-ui .ig-feed-account-name,
  body.public-page.feed-insta-ui .ig-stories-brand{
    font-size:18px!important;
    line-height:1!important;
    font-weight:800!important;
  }
  body.public-page.feed-insta-ui .ig-top-live{
    font-size:15px!important;
    line-height:1!important;
    font-weight:800!important;
  }
  body.public-page.feed-insta-ui .feed-left-nav-item,
  body.public-page.feed-insta-ui .feed-left-nav-label,
  body.public-page.feed-insta-ui .feed-right-nav-item,
  body.public-page.feed-insta-ui .feed-right-nav-label{
    font-size:14px!important;
    line-height:1.2!important;
  }
  body.public-page.feed-insta-ui .standard-media-title,
  body.public-page.feed-insta-ui .standard-text-title{
    margin-top: 15px!important;
    font-size:15px!important;
    line-height:1.28!important;
    font-weight:800!important;
  }
  body.public-page.feed-insta-ui .standard-media-caption,
  body.public-page.feed-insta-ui .standard-text-caption,
  body.public-page.feed-insta-ui .reel-caption,
  body.public-page.feed-insta-ui .reel-caption-text,
  body.home-page.feed-insta-ui .standard-media-caption,
  body.home-page.feed-insta-ui .standard-text-caption,
  body.home-page.feed-insta-ui .reel-caption,
  body.home-page.feed-insta-ui .reel-caption-text,
  body.news-page.feed-insta-ui .standard-media-caption,
  body.news-page.feed-insta-ui .standard-text-caption,
  body.news-page.feed-insta-ui .reel-caption,
  body.news-page.feed-insta-ui .reel-caption-text,
  body.public-page.feed-insta-ui .post-copy p{
    font-size:12px!important;
    line-height:1.45!important;
    font-weight:400!important;
  }
  body.public-page.feed-insta-ui .open-inline,
  body.public-page.feed-insta-ui .js-open-readmore{
    font-size:13px!important;
    line-height:1.2!important;
  }
  body.public-page.feed-insta-ui .standard-media-comments,
  body.public-page.feed-insta-ui .standard-media-views,
  body.public-page.feed-insta-ui .standard-text-comments,
  body.public-page.feed-insta-ui .standard-text-views{
    font-size:14px!important;
    line-height:1.3!important;
  }
  body.public-page.feed-insta-ui .ig-story-create .ig-story-ring-create i,
  body.public-page.feed-insta-ui .ig-story-empty-icon{
    font-size:18px!important;
    line-height:1!important;
  }
  body.public-page.feed-insta-ui .ig-top-shop i,
  body.public-page.feed-insta-ui .ig-top-cart i{
    font-size:18px!important;
    line-height:1!important;
  }
  body.public-page.feed-insta-ui .ig-top-mic i{
    font-size:12px!important;
    line-height:1!important;
  }
  body.public-page.feed-insta-ui .ig-top-live i{
    font-size:16px!important;
    line-height:1!important;
  }
  body.public-page.feed-insta-ui .feed-top-search-icon i{
    font-size:15px!important;
    line-height:1!important;
  }
  body.public-page.feed-insta-ui .feed-top-search-settings i{
    font-size:13px!important;
    line-height:1!important;
  }
  body.public-page.feed-insta-ui .feed-left-nav-ic,
  body.public-page.feed-insta-ui .feed-right-nav-ic{
    width:20px!important;
    height:20px!important;
    flex:0 0 20px!important;
  }
  body.public-page.feed-insta-ui .feed-left-nav-ic svg,
  body.public-page.feed-insta-ui .feed-right-nav-ic svg{
    width:18px!important;
    height:18px!important;
  }
  body.public-page.feed-insta-ui .feed-left-nav-item i,
  body.public-page.feed-insta-ui .feed-right-nav-item i{
    font-size:18px!important;
    line-height:1!important;
  }
  body.public-page.feed-insta-ui .standard-media-topbar .post-card-menu-btn,
  body.public-page.feed-insta-ui .standard-media-topbar .post-card-menu-btn .pcm-fries-icon{
    font-size:18px!important;
    line-height:1!important;
  }
  body.public-page.feed-insta-ui .standard-media-btn i,
  body.public-page.feed-insta-ui .standard-media-btn .msb-pact,
  body.public-page.feed-insta-ui .standard-text-btn i,
  body.public-page.feed-insta-ui .standard-text-btn .msb-pact{
    width:16px!important;
    height:16px!important;
    font-size:16px!important;
    line-height:1!important;
  }
}
</style>
<style id="shared-feed-discover-tabs-css">
<?php include __DIR__ . '/includes/feed_discover_tabs.css.php'; ?>
</style>
<style id="shared-feed-public-chrome-lock-css">
<?php include __DIR__ . '/includes/feed_public_chrome_lock.css.php'; ?>
</style>
<style id="home-header-rail-tjunction">
@media (min-width:1025px){
  body.public-page.feed-insta-ui .sh-mainpanel,
  body.home-page.feed-insta-ui .sh-mainpanel{
    margin-left:0 !important;
    width:100% !important;
    max-width:100% !important;
  }
  body.public-page.feed-insta-ui .ig-feed-header,
  body.home-page.feed-insta-ui .ig-feed-header{
    width:100% !important;
    margin:0 !important;
    padding:16px 16px 14px calc(var(--feedRailW, 84px) + 16px) !important;
    box-sizing:border-box !important;
    border-bottom:1px solid var(--msb-palette-border-strong, #d1d5db) !important;
  }
  body.public-page.feed-insta-ui .ig-feed-top-lead,
  body.home-page.feed-insta-ui .ig-feed-top-lead{
    left:calc(var(--feedRailW, 84px) + 16px) !important;
  }
  body.public-page.feed-insta-ui .feed-desktop-layout,
  body.home-page.feed-insta-ui .feed-desktop-layout{
    margin-left:var(--feedRailW, 84px) !important;
    width:calc(100% - var(--feedRailW, 84px)) !important;
    max-width:calc(100% - var(--feedRailW, 84px)) !important;
  }
}
</style>
<style id="public-mobile-tablet-post-divider">
/* Match feed.php: draw a column-width divider without resizing the card/media. */
body.public-page.feed-insta-ui .feed-desktop-center .ig-feed{
  container-type:inline-size !important;
}
body.public-page.feed-insta-ui .feed-desktop-center .ig-feed .post.public-post-card:not(.is-reel-post){
  position:relative !important;
  border:0 !important;
  border-bottom:1px solid var(--msb-hairline, var(--feed-post-divider, var(--public-border-strong, #d3d3d3))) !important;
  overflow:visible !important;
}
body.public-page.feed-insta-ui .feed-desktop-center .ig-feed .post.public-post-card.is-reel-post{
  border:0 !important;
  border-bottom:1px solid var(--msb-hairline, var(--feed-post-divider, var(--public-border-strong, #d3d3d3))) !important;
}
/* Head-outside: stage must grow with header + media + actions so the divider
   sits UNDER reacts/save — not through them. Cap height on media only. */
body.public-page.feed-insta-ui .post.public-post-card.public-media-head-outside:not(.is-reel-post) .media-stage,
body.public-page.feed-insta-ui .post.public-post-card.public-media-head-outside:not(.is-reel-post) .media-stage.standard-video-stage,
body.public-page.feed-insta-ui .post.public-post-card.public-media-head-outside:not(.is-reel-post) .media-stage.standard-image-stage{
  max-height:none !important;
  height:auto !important;
  overflow:visible !important;
}
body.public-page.feed-insta-ui .post.public-post-card.public-media-head-outside:not(.is-reel-post) .media-stage > video,
body.public-page.feed-insta-ui .post.public-post-card.public-media-head-outside:not(.is-reel-post) .media-stage > img,
body.public-page.feed-insta-ui .post.public-post-card.public-media-head-outside:not(.is-reel-post) .media-stage > .media-carousel{
  max-height:var(--post-media-max-height, min(70vh, 580px)) !important;
}
body.public-page.feed-insta-ui .post.public-post-card.public-media-head-outside:not(.is-reel-post) .standard-media-actions{
  position:relative !important;
  z-index:2 !important;
  margin-bottom:8px !important;
  padding-bottom:4px !important;
}
body.public-page.feed-insta-ui .feed-desktop-center .ig-feed .post.public-post-card.public-media-head-outside:not(.is-reel-post){
  padding-bottom:8px !important;
}
body.public-page.feed-insta-ui .feed-desktop-center .ig-feed .post.public-post-card:not(.is-reel-post)::after,
body.public-page.feed-insta-ui .feed-desktop-center .ig-feed .post.public-post-card:not(.is-reel-post)::before{
  content:none !important;
  display:none !important;
  border:0 !important;
}
</style>
<style id="public-media-action-icon-size">
body.public-page .post.public-post-card:not(.is-reel-post) .standard-media-actions{
  margin-top:15px !important;
}
body.public-page .post.public-post-card .media-stage > .standard-media-top-actions .mf-media-action-circle i,
body.public-page .post.public-post-card .media-stage > .standard-media-top-actions .mf-publisher-follow-circle i{
  font-size:12px !important;
}
</style>
<style id="public-light-media-actions-contrast">
html:not([data-theme="dark"]):not(.dark-auto) body.public-page.feed-insta-ui
  .post.public-post-card:not(.is-reel-post) .standard-media-bottom
  .standard-media-btn:not(.is-love):not(.is-like):not(.is-share):not(.is-save):not(.is-reacted),
html:not([data-theme="dark"]):not(.dark-auto) body.public-page.feed-insta-ui
  .post.public-post-card:not(.is-reel-post) .standard-media-bottom
  .standard-media-btn:not(.is-love):not(.is-like):not(.is-share):not(.is-save):not(.is-reacted) .msb-pact,
html:not([data-theme="dark"]):not(.dark-auto) body.public-page.feed-insta-ui
  .post.public-post-card:not(.is-reel-post) .standard-media-bottom
  .standard-media-btn .action-count{
  color:var(--msb-palette-text, var(--public-text, #0b1220)) !important;
  -webkit-text-fill-color:var(--msb-palette-text, var(--public-text, #0b1220)) !important;
}
</style>
<style id="public-read-more-bold">
body.public-page .open-inline,
body.public-page .js-open-readmore{
  font-weight:800 !important;
}
</style>
<style id="public-media-head-outside-layout">
/* Match feed.php's standard post structure without changing the media dimensions. */
body.public-page.feed-insta-ui .post.public-post-card.public-media-head-outside:not(.is-reel-post){
  width:100% !important;
  max-width:100% !important;
}
body.public-page.feed-insta-ui .post.public-post-card.public-media-head-outside.is-single-video-post:not(.is-reel-post),
body.public-page.feed-insta-ui .post.public-post-card.public-media-head-outside.is-single-image-post:not(.is-reel-post){
  width:100% !important;
  max-width:100% !important;
  margin-left:0 !important;
  margin-right:0 !important;
  padding:8px 12px !important;
  box-sizing:border-box !important;
}
body.public-page.feed-insta-ui .post.public-post-card.public-media-head-outside:not(.is-reel-post) .media-stage{
  display:grid !important;
  grid-template-columns:minmax(0,1fr) !important;
  grid-template-rows:auto auto auto auto !important;
  grid-template-areas:none !important;
  /* Full card width so fries/save can sit on the true right edge */
  width:100% !important;
  max-width:100% !important;
  margin-left:0 !important;
  margin-right:0 !important;
  overflow:visible !important;
}
body.public-page.feed-insta-ui .post.public-post-card.public-media-head-outside:not(.is-reel-post) .media-stage > :first-child{
  grid-area:auto !important;
  grid-column:1 !important;
  grid-row:3 !important;
  justify-self:start !important;
}
body.public-page.feed-insta-ui .post.public-post-card.public-media-head-outside.is-single-video-post:not(.is-reel-post) .media-stage > :first-child,
body.public-page.feed-insta-ui .post.public-post-card.public-media-head-outside.is-single-image-post:not(.is-reel-post) .media-stage > :first-child,
body.public-page.feed-insta-ui .post.public-post-card.public-media-head-outside.is-single-video-post:not(.is-reel-post) .media-stage > video,
body.public-page.feed-insta-ui .post.public-post-card.public-media-head-outside.is-single-image-post:not(.is-reel-post) .media-stage > img{
  width:min(100%, var(--post-media-card-width, var(--post-media-max, 680px))) !important;
  max-width:100% !important;
  margin-left:0 !important;
  margin-right:auto !important;
  justify-self:start !important;
}
body.public-page.feed-insta-ui .post.public-post-card.public-media-head-outside:not(.is-reel-post) .standard-media-topbar{
  position:relative !important;
  inset:auto !important;
  grid-area:auto !important;
  grid-column:1 !important;
  grid-row:1 !important;
  display:flex !important;
  align-items:center !important;
  justify-content:space-between !important;
  width:100% !important;
  max-width:100% !important;
  min-height:48px !important;
  left:auto !important;
  margin:0 !important;
  transform:none !important;
  padding:1px 0 12px 0 !important;
  border-radius:0 !important;
  background:none !important;
  box-sizing:border-box !important;
  justify-self:stretch !important;
}
body.public-page.feed-insta-ui .post.public-post-card.public-media-head-outside:not(.is-reel-post) .standard-media-author,
body.public-page.feed-insta-ui .post.public-post-card.public-media-head-outside:not(.is-reel-post) .standard-media-name,
body.public-page.feed-insta-ui .post.public-post-card.public-media-head-outside:not(.is-reel-post) .standard-media-time,
body.public-page.feed-insta-ui .post.public-post-card.public-media-head-outside:not(.is-reel-post) .standard-media-topbar .mf-music-row{
  color:var(--msb-palette-text, var(--public-text)) !important;
  -webkit-text-fill-color:var(--msb-palette-text, var(--public-text)) !important;
  text-shadow:none !important;
}
body.public-page.feed-insta-ui .post.public-post-card.public-media-head-outside:not(.is-reel-post) .standard-media-author{
  flex:1 1 auto !important;
  min-width:0 !important;
  padding-right:40px !important;
  box-sizing:border-box !important;
}
body.public-page.feed-insta-ui .post.public-post-card.public-media-head-outside:not(.is-reel-post) .standard-media-topbar > .post-card-menu-wrap{
  position:absolute !important;
  top:50% !important;
  right:0 !important;
  left:auto !important;
  bottom:auto !important;
  align-self:center !important;
  flex:0 0 auto !important;
  margin:0 !important;
  transform:translateY(-50%) !important;
  z-index:61 !important;
}
body.public-page.feed-insta-ui .post.public-post-card.public-media-head-outside:not(.is-reel-post) .standard-media-topbar .post-card-menu-btn,
body.public-page.feed-insta-ui .post.public-post-card.public-media-head-outside:not(.is-reel-post) .standard-media-topbar .post-card-menu-btn i,
body.public-page.feed-insta-ui .post.public-post-card.public-media-head-outside:not(.is-reel-post) .standard-media-topbar .pcm-fries-icon{
  color:var(--msb-fries, var(--msb-palette-text, var(--public-text))) !important;
  -webkit-text-fill-color:var(--msb-fries, var(--msb-palette-text, var(--public-text))) !important;
  text-shadow:none !important;
}
body.public-page.feed-insta-ui .post.public-post-card.public-media-head-outside:not(.is-reel-post) .standard-media-topbar .pcm-fries-bar{
  background:var(--msb-fries, #ffffff) !important;
}
body.public-page.feed-insta-ui .post.public-post-card.public-media-head-outside:not(.is-reel-post) .media-stage > .standard-media-top-actions{
  position:relative !important;
  inset:auto !important;
  grid-area:auto !important;
  grid-column:1 !important;
  grid-row:1 !important;
  align-self:start !important;
  justify-self:start !important;
  justify-content:flex-end !important;
  align-items:center !important;
  width:min(100%, var(--post-media-card-width, 560px)) !important;
  max-width:100% !important;
  left:auto !important;
  margin:1px 0 0 !important;
  transform:none !important;
  padding:0 42px 0 0 !important;
  box-sizing:border-box !important;
  z-index:7 !important;
}
body.public-page.feed-insta-ui .post.public-post-card.public-media-head-outside:not(.is-reel-post) .media-stage > .standard-media-top-actions .publisher-follow-btn,
body.public-page.feed-insta-ui .post.public-post-card.public-media-head-outside:not(.is-reel-post) .media-stage > .standard-media-top-actions .friend-btn{
  margin-top:0 !important;
  margin-right:-10px !important;
}
body.public-page.feed-insta-ui .post.public-post-card.public-media-head-outside:not(.is-reel-post) .standard-media-bottom{
  /* Hoist copy + actions into the card grid so the bar stays full width
     (love/comment/share left, save right). Do not replace with flex. */
  display:contents !important;
}
body.public-page.feed-insta-ui .post.public-post-card.public-media-head-outside:not(.is-reel-post) .standard-media-copy{
  grid-area:auto !important;
  grid-column:1 !important;
  grid-row:2 !important;
  width:100% !important;
  max-width:100% !important;
  position:relative !important;
  left:auto !important;
  margin:0 0 12px !important;
  padding-left:0 !important;
  padding-right:0 !important;
  transform:none !important;
  color:var(--msb-palette-text, var(--public-text)) !important;
  text-shadow:none !important;
  box-sizing:border-box !important;
  justify-self:stretch !important;
}
body.public-page.feed-insta-ui .post.public-post-card.public-media-head-outside:not(.is-reel-post) .standard-media-title,
body.public-page.feed-insta-ui .post.public-post-card.public-media-head-outside:not(.is-reel-post) .standard-media-caption,
body.public-page.feed-insta-ui .post.public-post-card.public-media-head-outside:not(.is-reel-post) .standard-media-intro,
body.public-page.feed-insta-ui .post.public-post-card.public-media-head-outside:not(.is-reel-post) .standard-media-subtitle,
body.public-page.feed-insta-ui .post.public-post-card.public-media-head-outside:not(.is-reel-post) .standard-media-summary,
body.public-page.feed-insta-ui .post.public-post-card.public-media-head-outside:not(.is-reel-post) .standard-media-copy .open-inline{
  color:var(--msb-palette-text, var(--public-text)) !important;
  -webkit-text-fill-color:var(--msb-palette-text, var(--public-text)) !important;
  text-shadow:none !important;
  padding-left:0 !important;
  padding-right:0 !important;
  margin-left:0 !important;
  margin-right:0 !important;
}
body.public-page.feed-insta-ui .post.public-post-card.public-media-head-outside:not(.is-reel-post) .standard-media-actions{
  grid-area:auto !important;
  grid-column:1 !important;
  grid-row:4 !important;
  pointer-events:auto !important;
  display:flex !important;
  align-items:center !important;
  justify-content:space-between !important;
  width:100% !important;
  max-width:100% !important;
  position:relative !important;
  left:auto !important;
  margin:15px 0 0 !important;
  padding:0 !important;
  transform:none !important;
  box-sizing:border-box !important;
  justify-self:stretch !important;
}
body.public-page.feed-insta-ui .post.public-post-card.public-media-head-outside:not(.is-reel-post) .standard-media-left{
  flex:1 1 auto !important;
  min-width:0 !important;
}
body.public-page.feed-insta-ui .post.public-post-card.public-media-head-outside:not(.is-reel-post) .standard-media-right{
  flex:0 0 auto !important;
  margin-left:auto !important;
  margin-right:0 !important;
  padding-right:0 !important;
}

/* Text-only (home.php via public.php): same left edge as media posts. */
body.public-page.feed-insta-ui .post.public-post-card.public-text-only:not(.is-reel-post),
body.news-page.feed-insta-ui .post.public-post-card.public-text-only:not(.is-reel-post){
  display:flex !important;
  flex-direction:column !important;
  align-items:stretch !important;
  width:100% !important;
  max-width:100% !important;
  margin-left:0 !important;
  margin-right:0 !important;
  padding:8px 12px !important;
  box-sizing:border-box !important;
}
body.public-page.feed-insta-ui .post.public-post-card.public-text-only:not(.is-reel-post) > .standard-text-card,
body.news-page.feed-insta-ui .post.public-post-card.public-text-only:not(.is-reel-post) > .standard-text-card{
  display:flex !important;
  flex-direction:column !important;
  align-items:stretch !important;
  width:100% !important;
  max-width:100% !important;
  margin:0 !important;
  padding:0 !important;
  box-sizing:border-box !important;
  background:transparent !important;
}
body.public-page.feed-insta-ui .post.public-post-card.public-text-only:not(.is-reel-post) .standard-text-topbar,
body.public-page.feed-insta-ui .post.public-post-card.public-text-only:not(.is-reel-post) .standard-text-copy,
body.public-page.feed-insta-ui .post.public-post-card.public-text-only:not(.is-reel-post) .standard-text-actions,
body.news-page.feed-insta-ui .post.public-post-card.public-text-only:not(.is-reel-post) .standard-text-topbar,
body.news-page.feed-insta-ui .post.public-post-card.public-text-only:not(.is-reel-post) .standard-text-copy,
body.news-page.feed-insta-ui .post.public-post-card.public-text-only:not(.is-reel-post) .standard-text-actions{
  position:relative !important;
  left:auto !important;
  right:auto !important;
  float:none !important;
  clear:both !important;
  width:100% !important;
  max-width:100% !important;
  margin-left:0 !important;
  margin-right:0 !important;
  padding-left:0 !important;
  padding-right:0 !important;
  transform:none !important;
  box-sizing:border-box !important;
  text-indent:0 !important;
}
body.public-page.feed-insta-ui .post.public-post-card.public-text-only:not(.is-reel-post) .standard-text-topbar,
body.news-page.feed-insta-ui .post.public-post-card.public-text-only:not(.is-reel-post) .standard-text-topbar{
  display:flex !important;
  align-items:center !important;
  justify-content:space-between !important;
  padding:1px 0 12px !important;
}
body.public-page.feed-insta-ui .post.public-post-card.public-text-only:not(.is-reel-post) .standard-text-title,
body.public-page.feed-insta-ui .post.public-post-card.public-text-only:not(.is-reel-post) .standard-text-caption,
body.public-page.feed-insta-ui .post.public-post-card.public-text-only:not(.is-reel-post) .standard-text-caption .post-card-paragraph,
body.public-page.feed-insta-ui .post.public-post-card.public-text-only:not(.is-reel-post) .standard-text-caption .post-card-caption-formatted,
body.public-page.feed-insta-ui .post.public-post-card.public-text-only:not(.is-reel-post) .standard-text-caption p,
body.news-page.feed-insta-ui .post.public-post-card.public-text-only:not(.is-reel-post) .standard-text-title,
body.news-page.feed-insta-ui .post.public-post-card.public-text-only:not(.is-reel-post) .standard-text-caption,
body.news-page.feed-insta-ui .post.public-post-card.public-text-only:not(.is-reel-post) .standard-text-caption .post-card-paragraph,
body.news-page.feed-insta-ui .post.public-post-card.public-text-only:not(.is-reel-post) .standard-text-caption .post-card-caption-formatted,
body.news-page.feed-insta-ui .post.public-post-card.public-text-only:not(.is-reel-post) .standard-text-caption p{
  margin-left:0 !important;
  margin-right:0 !important;
  padding-left:0 !important;
  padding-right:0 !important;
  text-indent:0 !important;
  text-align:left !important;
}
body.public-page.feed-insta-ui .post.public-post-card.public-text-only:not(.is-reel-post) .standard-text-actions,
body.news-page.feed-insta-ui .post.public-post-card.public-text-only:not(.is-reel-post) .standard-text-actions{
  display:flex !important;
  align-items:center !important;
  justify-content:space-between !important;
  padding:1px 0 2px !important;
}
</style>
<style id="public-post-right-icons-lock">
/* Final lock: fries + save flush to the right edge of the FULL post card. */
body.public-page.feed-insta-ui .post.public-post-card.public-media-head-outside:not(.is-reel-post) .media-stage{
  width:100% !important;
  max-width:100% !important;
  margin-left:0 !important;
  margin-right:0 !important;
}
body.public-page.feed-insta-ui .post.public-post-card.public-media-head-outside:not(.is-reel-post) .standard-media-topbar{
  position:relative !important;
  display:flex !important;
  align-items:center !important;
  justify-content:space-between !important;
  width:100% !important;
  max-width:100% !important;
  padding-right:0 !important;
  box-sizing:border-box !important;
  justify-self:stretch !important;
}
body.public-page.feed-insta-ui .post.public-post-card.public-media-head-outside:not(.is-reel-post) .standard-media-topbar > .post-card-menu-wrap,
html[data-msb-appearance] body.public-page.feed-insta-ui .post.public-post-card.public-media-head-outside:not(.is-reel-post) .standard-media-topbar > .post-card-menu-wrap,
html[data-msb-appearance] body .post.public-post-card.public-media-head-outside:not(.is-reel-post) .standard-media-topbar > .post-card-menu-wrap,
body.public-page.feed-insta-ui .post.public-post-card.public-media-head-outside:not(.is-reel-post) .standard-media-topbar .post-card-menu-wrap{
  position:absolute !important;
  top:25% !important;
  right:0 !important;
  left:auto !important;
  bottom:auto !important;
  margin:0 !important;
  transform:translateY(-50%) !important;
  z-index:61 !important;
}
body.public-page.feed-insta-ui .post.public-post-card.public-media-head-outside:not(.is-reel-post) .standard-media-actions,
body.public-page.feed-insta-ui .post.public-post-card:not(.is-reel-post) .standard-media-actions{
  display:flex !important;
  align-items:center !important;
  width:100% !important;
  max-width:100% !important;
  justify-content:space-between !important;
  justify-self:stretch !important;
  box-sizing:border-box !important;
}
body.public-page.feed-insta-ui .post.public-post-card.public-media-head-outside:not(.is-reel-post) .standard-media-left,
body.public-page.feed-insta-ui .post.public-post-card:not(.is-reel-post) .standard-media-left{
  flex:1 1 auto !important;
  min-width:0 !important;
}
body.public-page.feed-insta-ui .post.public-post-card.public-media-head-outside:not(.is-reel-post) .standard-media-right,
body.public-page.feed-insta-ui .post.public-post-card:not(.is-reel-post) .standard-media-right{
  flex:0 0 auto !important;
  margin-left:auto !important;
  margin-right:0 !important;
  padding-right:0 !important;
}
</style>
<script id="public-post-visible-media-left-align">
(function(){
  'use strict';

  /* Match feed.php: left-align media only; keep header/actions full card width
     so fries + save stay flush right. */
  var feed = document.querySelector('.ig-feed');
  if (!feed) return;

  function publicMediaMaxHeightCss(){
    if (window.matchMedia('(max-width: 767.98px)').matches) {
      return 'min(52vh, 580px)';
    }
    if (window.matchMedia('(max-width: 1024.98px)').matches) {
      return 'min(54vh, 580px)';
    }
    return 'min(70vh, 580px)';
  }

  function syncCardMedia(card){
    if (!card || card.classList.contains('is-reel-post')) return;
    card.style.setProperty('width', '100%', 'important');
    card.style.setProperty('max-width', '100%', 'important');
    card.style.setProperty('margin-left', '0', 'important');
    card.style.setProperty('margin-right', '0', 'important');
    card.style.setProperty('padding', '8px 12px', 'important');

    var stage = card.querySelector('.media-stage.standard-video-stage, .media-stage.standard-image-stage, .media-stage');
    if (!stage) return;
    stage.style.removeProperty('transform');
    stage.style.removeProperty('--public-media-left-shift');
    stage.style.setProperty('aspect-ratio', 'auto', 'important');
    stage.style.setProperty('height', 'auto', 'important');
    try{ stage.classList.remove('single-portrait', 'single-landscape', 'single-square'); }catch(e){}

    var w = card.style.getPropertyValue('--post-media-card-width') || getComputedStyle(card).getPropertyValue('--post-media-card-width');
    w = String(w || '').trim();
    // Prefer live CSS expression — never re-apply stale computed px (397px / 500px).
    var maxH = publicMediaMaxHeightCss();
    card.style.setProperty('--post-media-max-height', maxH);

    var isHeadOutside = card.classList.contains('public-media-head-outside');
    var mediaEl = stage.querySelector(':scope > img, :scope > video, :scope > .media-carousel');

    if (isHeadOutside) {
      stage.style.setProperty('width', '100%', 'important');
      stage.style.setProperty('max-width', '100%', 'important');
      stage.style.setProperty('margin-left', '0', 'important');
      stage.style.setProperty('margin-right', '0', 'important');
      stage.style.setProperty('overflow', 'visible', 'important');
      stage.style.removeProperty('max-height');
      if (mediaEl && w) {
        mediaEl.style.setProperty('width', 'min(100%, ' + w + ')', 'important');
        mediaEl.style.setProperty('max-width', '100%', 'important');
        mediaEl.style.setProperty('margin-left', '0', 'important');
        mediaEl.style.setProperty('margin-right', 'auto', 'important');
        mediaEl.style.setProperty('align-self', 'flex-start', 'important');
        mediaEl.style.setProperty('justify-self', 'start', 'important');
        mediaEl.style.setProperty('height', 'auto', 'important');
        mediaEl.style.setProperty('object-fit', 'contain', 'important');
        mediaEl.style.setProperty('object-position', 'center center', 'important');
        mediaEl.style.setProperty('max-height', maxH, 'important');
      }
    } else {
      stage.style.setProperty('margin-left', '0', 'important');
      stage.style.setProperty('margin-right', 'auto', 'important');
      if (w) {
        stage.style.setProperty('width', 'min(100%, ' + w + ')', 'important');
        stage.style.setProperty('max-width', '100%', 'important');
      }
      stage.style.setProperty('max-height', maxH, 'important');
      if (mediaEl) {
        mediaEl.style.setProperty('width', '100%', 'important');
        mediaEl.style.setProperty('height', 'auto', 'important');
        mediaEl.style.setProperty('object-fit', 'contain', 'important');
        mediaEl.style.setProperty('object-position', 'center center', 'important');
        mediaEl.style.setProperty('max-height', maxH, 'important');
      }
    }

    if (mediaEl) {
      mediaEl.style.removeProperty('transform');
      mediaEl.style.removeProperty('--public-media-left-shift');
    }
  }

  function clearMediaShifts(){
    feed.querySelectorAll('.post.public-post-card:not(.is-reel-post)').forEach(syncCardMedia);
  }

  clearMediaShifts();
  window.addEventListener('load', clearMediaShifts, {once:true});
  window.addEventListener('resize', clearMediaShifts);
  if (typeof MutationObserver !== 'undefined') {
    var mo = new MutationObserver(function(){ clearMediaShifts(); });
    mo.observe(feed, { childList:true, subtree:false });
  }
})();
</script>
<?php post_card_actions_menu_render_modals(); ?>
<?php include __DIR__ . '/includes/post_viewer_modal.html.php'; ?>
<?php include __DIR__ . '/includes/post_viewer_gallery_chrome.css.php'; ?>
<?php post_card_actions_menu_render_js([
  // Use the same direct feed_api.php deletion path as reel.php. This keeps
  // description-only cards out of the legacy hidden-form delete flow.
  'delete_mode' => 'feed',
  'staff_readonly' => $staffReadonly,
  'menu_surface' => 'public',
  'api_url' => 'feed_api.php',
  // Keep every Public dropdown in the body-level portal so the right rail
  // (Suggested for you / Follow links) cannot paint above the menu.
  'always_portal' => true,
  'can_follow_publishers' => $canFollowOnPublicMenu,
  'publisher_workspace_viewer' => $isPublisherWorkspaceViewer,
]); ?>
<?php
$pvModalApiUrl = 'feed_api.php';
include __DIR__ . '/includes/post_viewer_modal.js.php';
?>
<script id="public-post-menu-outside-column-fix">
(function(){
  'use strict';

  /*
   * Text-only cards can still use the original nested menu node while media
   * cards use the shared body portal.  Catch Delete at the window boundary so
   * both menu shapes always enter the same custom confirmation dialog.
   */
  var lastDeletePostId = 0;
  var lastDeleteTime = 0;

  function openPublicDeleteDialog(e){
    var target = e.target;
    var deleteButton = target && target.closest
      ? target.closest('.pcm-delete')
      : null;
    if(!deleteButton) return;

    var postId = Number(deleteButton.getAttribute('data-post-id') || 0);
    if(!postId){
      var card = deleteButton.closest('.public-post-card, [data-post-id]');
      postId = Number(card ? (card.getAttribute('data-post-id') || card.getAttribute('data-id') || 0) : 0);
    }
    if(!postId) return;

    var now = Date.now();
    if(postId === lastDeletePostId && now - lastDeleteTime < 750) return;
    lastDeletePostId = postId;
    lastDeleteTime = now;

    e.preventDefault();
    e.stopPropagation();
    if(e.stopImmediatePropagation) e.stopImmediatePropagation();

    if(window.MSBPostCardMenu && typeof window.MSBPostCardMenu.closeAll === 'function'){
      window.MSBPostCardMenu.closeAll();
    }

    /*
     * Open the shared dialog the same way as feed: defer showModal so the
     * fries-menu click cannot light-dismiss the confirm popup.
     */
    if(window.MSBPostCardMenu && typeof window.MSBPostCardMenu.confirmDelete === 'function'){
      window.MSBPostCardMenu.confirmDelete(postId);
      return;
    }

    window.__pcmPendingDeleteId = postId;
    window.__pcmPendingDeleteDone = null;
    var dialog = document.getElementById('pcmDeleteConfirmDialog');
    if(dialog){
      setTimeout(function(){
        try{
          if(dialog.parentNode !== document.body) document.body.appendChild(dialog);
          if(typeof dialog.showModal === 'function' && !dialog.open){
            dialog.showModal();
          }else{
            dialog.setAttribute('open', '');
          }
        }catch(ignore){
          dialog.setAttribute('open', '');
        }
      }, 0);
    }
  }

  window.addEventListener('click', openPublicDeleteDialog, true);

  function placePublicPostMenu(){
    var menu = document.querySelector('body.public-page > .pcm-menu-portal.open')
      || document.querySelector('body.public-page .post.public-post-card .post-card-menu.open');
    var center = document.querySelector('body.public-page .feed-desktop-center');
    if(!menu || !center) return;
    var centerRect = center.getBoundingClientRect();
    var menuWidth = menu.offsetWidth || 220;
    var menuHeight = menu.offsetHeight || 275;
    var viewportWidth = Math.max(document.documentElement.clientWidth, window.innerWidth || 0);
    var viewportHeight = Math.max(document.documentElement.clientHeight, window.innerHeight || 0);
    var outsideLeft = centerRect.right + 8;
    var suggestions = document.querySelector('body.public-page .feed-right-rail .sfy-panel');
    var suggestionsRect = suggestions ? suggestions.getBoundingClientRect() : null;
    if(suggestionsRect && suggestionsRect.height > 0){
      var baseOverlapsSuggestions = outsideLeft < suggestionsRect.right && outsideLeft + menuWidth > suggestionsRect.left;
      var rightOfSuggestions = suggestionsRect.right + 12;
      if(baseOverlapsSuggestions && rightOfSuggestions + menuWidth <= viewportWidth - 10){
        outsideLeft = rightOfSuggestions;
      }
    }
    if(outsideLeft + menuWidth <= viewportWidth - 10){
      var menuButton = menu.closest('.post-card-menu-wrap');
      menuButton = menuButton ? menuButton.querySelector('.post-card-menu-btn') : null;
      if(!menuButton){
        menuButton = document.querySelector('body.public-page .post-card-menu-btn[aria-expanded="true"]');
      }
      var currentTop = parseFloat(menu.style.top || '') || 10;
      var desiredTop = menuButton ? Math.max(10, menuButton.getBoundingClientRect().top) : currentTop;
      desiredTop = Math.max(10, Math.min(desiredTop, viewportHeight - menuHeight - 10));
      menu.style.setProperty('top', desiredTop + 'px', 'important');
      menu.style.setProperty('position', 'fixed', 'important');
      menu.style.setProperty('left', outsideLeft + 'px', 'important');
      menu.style.setProperty('right', 'auto', 'important');
      menu.style.setProperty('z-index', '100000', 'important');
    }
  }
  document.addEventListener('click', function(){
    requestAnimationFrame(placePublicPostMenu);
  }, true);
  window.addEventListener('resize', placePublicPostMenu, {passive:true});
  window.addEventListener('scroll', placePublicPostMenu, {passive:true, capture:true});
  new MutationObserver(function(records){
    for(var i=0;i<records.length;i++){
      if((records[i].addedNodes && records[i].addedNodes.length) || records[i].type === 'attributes'){
        requestAnimationFrame(placePublicPostMenu);
        break;
      }
    }
  }).observe(document.body, {childList:true, subtree:true, attributes:true, attributeFilter:['class']});
})();
</script>
<?php include __DIR__ . '/includes/post_reactors_modal.php'; ?>
<?php include __DIR__ . '/includes/watch_beacon.js.php'; ?>
</body>
</html>
