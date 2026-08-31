<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/session_user.php';
requireUserLogin();

require_once __DIR__ . '/controller.php';
require_once __DIR__ . '/includes/user_identity.php';
require_once __DIR__ . '/includes/friend_system.php';
require_once __DIR__ . '/includes/post_card_actions_menu.php';
require_once __DIR__ . '/includes/post_action_thin_icons.php';

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
// Always include the DB username/email for this account (session can be stale).
try {
    $stRecv = $dbh->prepare('SELECT username, email FROM users WHERE id = :id LIMIT 1');
    $stRecv->execute([':id' => $meId]);
    $recvRow = $stRecv->fetch(PDO::FETCH_ASSOC) ?: [];
    foreach (['username', 'email'] as $recvKey) {
        $recvVal = trim((string)($recvRow[$recvKey] ?? ''));
        if ($recvVal !== '') {
            $receivers[] = $recvVal;
        }
    }
    $receivers = array_values(array_unique(array_filter($receivers, static function ($value) {
        return trim((string)$value) !== '';
    })));
} catch (Throwable $e) {
    // keep session receivers
}

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
    $isStory = false;
    $profileUserId = 0;

    while (preg_match('/\s\[(live|r|p|c|story|u):([^\]]+)\]\s*$/', $type, $m)) {
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
            $isStory = ((int)$value === 1) || strtolower($value) === '1';
        } elseif ($key === 'u') {
            $profileUserId = (int)$value;
        }
        $type = trim((string)preg_replace('/\s\[(?:live|r|p|c|story|u):[^\]]+\]\s*$/', '', $type, 1));
    }
    if (!$isStory && stripos($type, ' in a story') !== false) {
        $isStory = true;
    }

    $url = '';
    if ($liveId > 0) {
        $url = 'live_watch.php?live=' . $liveId;
    } elseif ($postId > 0 && $isStory) {
        $url = 'home.php?tab=for-you&story_post=' . $postId;
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
        } elseif ($route === 'fd') {
            $page = 'home.php';
        }
        $params = ['open_post' => $postId];
        if ($page === 'home.php') {
            $params['tab'] = 'for-you';
        }
        if ($commentId > 0) {
            $params['open_comment'] = $commentId;
        }
        $typeLower = strtolower($type);
        if (strpos($typeLower, 'mention') !== false
            || strpos($typeLower, 'tagged you') !== false
            || strpos($typeLower, 'comment') !== false
            || strpos($typeLower, 'replied') !== false
            || $commentId > 0) {
            $params['hide_nav'] = 1;
        }
        $url = $page . '?' . http_build_query($params);
    } elseif ($profileUserId > 0) {
        $url = 'profile.php?tab=about&id=' . $profileUserId;
    } elseif ($route === 'pf') {
        $url = 'profile.php?tab=about';
    }

    return [
        'text' => $type,
        'url' => $url,
        'live_id' => $liveId,
        'post_id' => $postId,
        'is_story' => $isStory ? 1 : 0,
        'comment_id' => $commentId,
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

/** Tab bucket: all | mentions | tags | reacts | shares | saves | whats-up */
function notifications_tab(string $text): string {
    $text = strtolower(trim($text));
    // Tags first — "tagged you" must not fall into Mentions.
    if (strpos($text, 'tagged you') !== false
      || preg_match('/\btagged\b/', $text)) {
        return 'tags';
    }
    if (strpos($text, 'mention') !== false
      || strpos($text, 'mentioned') !== false
      || (strpos($text, '@') !== false && strpos($text, 'tagged') === false)) {
        return 'mentions';
    }
    // Saves / shares of *your* post — before What’s up (“shared a new post”).
    if (preg_match('/\b(saved|bookmarked)\s+your\b/', $text)
      || (preg_match('/\b(saved|bookmarked)\b/', $text) && strpos($text, 'your post') !== false)) {
        return 'saves';
    }
    if (preg_match('/\b(shared|reposted)\s+your\b/', $text)
      || (preg_match('/\b(shared|reposted)\b/', $text) && strpos($text, 'your post') !== false)) {
        return 'shares';
    }
    if (strpos($text, 'posted an update') !== false
      || strpos($text, 'shared a new post') !== false
      || strpos($text, "what's up") !== false
      || strpos($text, 'whats up') !== false
      || preg_match('/\bposted (something|a (new )?post)\b/', $text)) {
        return 'whats-up';
    }
    if (preg_match('/\b(loved|love|liked|like|disliked|dislike|laughed|laugh|smiled|smile|wowed|wow|sadly|sad|angrily|angry|clapped|clap|reacted|reaction|react)\b/', $text)) {
        return 'reacts';
    }
    return 'all';
}

function notifications_is_tag_or_mention(string $tab): bool {
    return $tab === 'mentions' || $tab === 'tags';
}

/**
 * @return array{0:string,1:string,2:string} [mode, kind, cssClass]
 * mode: pact | face | emoji | fa
 */
function notifications_icon(string $text, int $liveId = 0): array {
    $t = strtolower(trim($text));
    if ($liveId > 0 || strpos($t, 'live') !== false) {
        return ['fa', 'fa-video-camera', 'is-live'];
    }
    if (preg_match('/\b(loved|love)\b/', $t)) {
        return ['pact', 'heart', 'is-like is-love'];
    }
    if (preg_match('/\b(liked|like)\b/', $t) && strpos($t, 'dislike') === false) {
        return ['pact', 'thumb', 'is-like'];
    }
    if (preg_match('/\b(disliked|dislike)\b/', $t)) {
        return ['pact', 'thumb-down', 'is-dislike'];
    }
    if (preg_match('/\b(laughed|laugh)\b/', $t)) {
        return ['face', 'laugh', 'is-react'];
    }
    if (preg_match('/\b(smiled|smile)\b/', $t)) {
        return ['face', 'smile', 'is-react'];
    }
    if (preg_match('/\b(wowed|wow)\b/', $t)) {
        return ['face', 'wow', 'is-react'];
    }
    if (preg_match('/\b(sadly|sad)\b/', $t)) {
        return ['face', 'sad', 'is-react'];
    }
    if (preg_match('/\b(angrily|angry)\b/', $t)) {
        return ['face', 'angry', 'is-react'];
    }
    if (preg_match('/\b(clapped|clap)\b/', $t)) {
        return ['emoji', '👏', 'is-react'];
    }
    if (preg_match('/\b(reacted|reaction|react)\b/', $t)) {
        return ['pact', 'heart', 'is-like'];
    }
    if (preg_match('/\b(comment|commented|reply|replied)\b/', $t) && strpos($t, 'mentioned') === false && strpos($t, 'tagged') === false) {
        return ['pact', 'comment', 'is-comment'];
    }
    if (strpos($t, 'posted an update') !== false
      || strpos($t, 'shared a new post') !== false
      || preg_match('/\bposted (something|a (new )?post)\b/', $t)) {
        return ['fa', 'fa-bolt', 'is-whats-up'];
    }
    if (preg_match('/\b(share|shared|repost|reposted)\b/', $t)) {
        return ['pact', 'share', 'is-share'];
    }
    if (preg_match('/\b(save|saved|bookmark|bookmarked)\b/', $t)) {
        return ['pact', 'bookmark', 'is-save'];
    }
    if (strpos($t, 'tagged you') !== false || preg_match('/\btagged\b/', $t)) {
        return ['fa', 'fa-tag', 'is-tag'];
    }
    if (strpos($t, 'mention') !== false || strpos($t, 'mentioned') !== false) {
        return ['fa', 'fa-at', 'is-mention'];
    }
    if (preg_match('/\b(follow|friend|request)\b/', $t)) {
        return ['fa', 'fa-user-plus', 'is-follow'];
    }
    return ['pact', 'heart', 'is-bell'];
}

function notifications_face_svg(string $kind): string {
    static $faces = [
        'smile' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" aria-hidden="true"><circle cx="12" cy="12" r="11" fill="#FACC15"/><path d="M7 10.6c.9-1.5 2.6-1.5 3.5 0" fill="none" stroke="#111" stroke-width="1.7" stroke-linecap="round"/><path d="M13.5 10.6c.9-1.5 2.6-1.5 3.5 0" fill="none" stroke="#111" stroke-width="1.7" stroke-linecap="round"/><path d="M8 14.2c1.35 2.5 6.65 2.5 8 0" fill="none" stroke="#111" stroke-width="1.75" stroke-linecap="round"/></svg>',
        'laugh' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" aria-hidden="true"><circle cx="12" cy="12" r="11" fill="#FACC15"/><path d="M6.2 8.2l3.2 2.3-3.2 2.3" fill="none" stroke="#111" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"/><path d="M17.8 8.2l-3.2 2.3 3.2 2.3" fill="none" stroke="#111" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"/><path d="M7.4 13.8c.5 4.4 8.7 4.4 9.2 0-.3-1.15-2.3-1.95-4.6-1.95s-4.3.8-4.6 1.95z" fill="#111"/><ellipse cx="12" cy="16.85" rx="3.1" ry="1.55" fill="#EF4444"/></svg>',
        'wow' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" aria-hidden="true"><circle cx="12" cy="12" r="11" fill="#FACC15"/><path d="M6.4 7.4c1.1-1.35 3-1.35 4.1 0" fill="none" stroke="#111" stroke-width="1.45" stroke-linecap="round"/><path d="M13.5 7.4c1.1-1.35 3-1.35 4.1 0" fill="none" stroke="#111" stroke-width="1.45" stroke-linecap="round"/><circle cx="8.6" cy="10.7" r="1.45" fill="#111"/><circle cx="15.4" cy="10.7" r="1.45" fill="#111"/><ellipse cx="12" cy="16.2" rx="2.15" ry="2.85" fill="#111"/></svg>',
        'sad' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" aria-hidden="true"><circle cx="12" cy="12" r="11" fill="#FACC15"/><path d="M6.3 8.1c1.2-1.2 3.2-.85 4.1.45" fill="none" stroke="#111" stroke-width="1.4" stroke-linecap="round"/><path d="M13.6 8.55c.9-1.3 2.9-1.65 4.1-.45" fill="none" stroke="#111" stroke-width="1.4" stroke-linecap="round"/><circle cx="8.7" cy="11" r="1.2" fill="#111"/><circle cx="15.3" cy="11" r="1.2" fill="#111"/><path d="M8.6 16.4c1.2-1.7 5.6-1.7 6.8 0" fill="none" stroke="#111" stroke-width="1.55" stroke-linecap="round"/><path d="M16.4 14.8c1.35 1.1 1.55 2.85.15 3.85-1.55-.55-2.05-2.05-.15-3.85z" fill="#60A5FA"/></svg>',
        // Unique gradient id per render avoided by using solid fill here for list icons.
        'angry' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" aria-hidden="true"><circle cx="12" cy="12" r="11" fill="#F59E0B"/><path d="M5.8 8.8l4.4 1.7" fill="none" stroke="#111" stroke-width="1.85" stroke-linecap="round"/><path d="M18.2 8.8l-4.4 1.7" fill="none" stroke="#111" stroke-width="1.85" stroke-linecap="round"/><circle cx="8.6" cy="11.6" r="1.15" fill="#111"/><circle cx="15.4" cy="11.6" r="1.15" fill="#111"/><path d="M9.4 16.3c.9-.7 4.3-.7 5.2 0" fill="none" stroke="#111" stroke-width="1.7" stroke-linecap="round"/></svg>',
    ];
    $kind = strtolower(trim($kind));
    return $faces[$kind] ?? '';
}

function notifications_icon_html(string $text, int $liveId = 0): string {
    [$mode, $kind, $cssClass] = notifications_icon($text, $liveId);
    if ($mode === 'pact') {
        $html = post_action_thin_icon($kind, true);
        return $html !== '' ? $html : '<i class="fa fa-bell" aria-hidden="true"></i>';
    }
    if ($mode === 'face') {
        $svg = notifications_face_svg($kind);
        if ($svg === '') {
            return '<span class="x-noti-emoji" aria-hidden="true">🙂</span>';
        }
        // Inline SVG (same faces as the reaction picker). Avoid empty
        // .msb-rx-face spans — shared CSS forces background:transparent.
        return '<span class="x-noti-face msb-rx-' . htmlspecialchars($kind, ENT_QUOTES, 'UTF-8') . '" aria-hidden="true">' . $svg . '</span>';
    }
    if ($mode === 'emoji') {
        return '<span class="x-noti-emoji" aria-hidden="true">' . htmlspecialchars($kind, ENT_QUOTES, 'UTF-8') . '</span>';
    }
    return '<i class="fa ' . htmlspecialchars($kind, ENT_QUOTES, 'UTF-8') . '" aria-hidden="true"></i>';
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
$allowedTabs = ['all', 'mentions', 'tags', 'reacts', 'shares', 'saves', 'whats-up'];
if (!in_array($activeTab, $allowedTabs, true)) {
    $activeTab = 'all';
}

$unreadLabel = $unreadCount > 0
    ? ($unreadCount . ' unread')
    : 'All caught up';

// What’s up — recent posts from publishers this user follows (+ existing notify rows).
require_once __DIR__ . '/includes/publisher_accounts.php';
$whatsUpSeenPostIds = [];
foreach ($notifications as $row) {
    $metaEarly = notifications_parse_meta((string)($row['notitype'] ?? ''));
    $pidEarly = (int)($metaEarly['post_id'] ?? 0);
    $textEarly = (string)($metaEarly['text'] ?? '');
    if ($pidEarly > 0 && notifications_tab($textEarly) === 'whats-up') {
        $whatsUpSeenPostIds[$pidEarly] = true;
    }
}
try {
    $blockSqlWu = function_exists('fs_block_exclude_author_sql')
        ? (' AND ' . fs_block_exclude_author_sql('p.user_id', ':wuBlockMe', ':wuBlockMe2'))
        : '';
    $wuSt = $dbh->prepare("
      SELECT
        p.id,
        p.created_at,
        COALESCE(NULLIF(TRIM(u.name), ''), NULLIF(TRIM(u.username), ''), CONCAT('Publisher ', u.id)) AS publisher_name
      FROM public_posts p
      INNER JOIN users u ON u.id = p.user_id
      INNER JOIN public_follows pf ON pf.following_id = p.user_id AND pf.follower_id = :me
      WHERE p.is_deleted = 0
        AND COALESCE(p.is_archived, 0) = 0
        AND LOWER(COALESCE(NULLIF(TRIM(p.visibility), ''), 'public')) IN ('public', 'friends')
        AND COALESCE(u.account_kind, 'personal') = 'publisher'
        AND u.status = 1
        {$blockSqlWu}
      ORDER BY COALESCE(p.created_at, p.updated_at) DESC
      LIMIT 40
    ");
    $wuParams = [':me' => $meId];
    if ($blockSqlWu !== '') {
        $wuParams[':wuBlockMe'] = $meId;
        $wuParams[':wuBlockMe2'] = $meId;
    }
    $wuSt->execute($wuParams);
    foreach (($wuSt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $wu) {
        $pid = (int)($wu['id'] ?? 0);
        if ($pid <= 0 || isset($whatsUpSeenPostIds[$pid])) {
            continue;
        }
        $whatsUpSeenPostIds[$pid] = true;
        $publisherName = trim((string)($wu['publisher_name'] ?? 'Publisher'));
        $notifications[] = [
            'id' => 0,
            'notiuser' => $publisherName,
            'notitype' => 'posted an update [r:pb] [p:' . $pid . ']',
            'created_at' => (string)($wu['created_at'] ?? ''),
            'is_read' => 1,
            '_synthetic' => 1,
        ];
    }
    // Keep newest first after merging synthetic rows.
    usort($notifications, static function ($a, $b) {
        $ta = strtotime((string)($a['created_at'] ?? '')) ?: 0;
        $tb = strtotime((string)($b['created_at'] ?? '')) ?: 0;
        if ($ta === $tb) {
            return ((int)($b['id'] ?? 0)) <=> ((int)($a['id'] ?? 0));
        }
        return $tb <=> $ta;
    });
} catch (Throwable $eWu) {
    // Keep regular notifications if What’s up query fails.
}

// Shares / Saves — people who shared or saved this user's posts.
$shareSaveSeen = [];
foreach ($notifications as $row) {
    $metaEarly = notifications_parse_meta((string)($row['notitype'] ?? ''));
    $pidEarly = (int)($metaEarly['post_id'] ?? 0);
    $textEarly = (string)($metaEarly['text'] ?? '');
    $tabEarly = notifications_tab($textEarly);
    $senderEarly = strtolower(trim((string)($row['notiuser'] ?? '')));
    if ($pidEarly > 0 && $senderEarly !== '' && ($tabEarly === 'shares' || $tabEarly === 'saves')) {
        $shareSaveSeen[$tabEarly . '|' . $senderEarly . '|' . $pidEarly] = true;
    }
}
$blockSqlShareSave = function_exists('fs_block_exclude_author_sql')
    ? (' AND ' . fs_block_exclude_author_sql('u.id', ':ssBlockMe', ':ssBlockMe2'))
    : '';
try {
    $ssSt = $dbh->prepare("
      SELECT
        s.post_id,
        s.shared_at AS acted_at,
        COALESCE(NULLIF(TRIM(u.name), ''), NULLIF(TRIM(u.username), ''), CONCAT('User ', u.id)) AS actor_name
      FROM public_post_shares s
      INNER JOIN public_posts p ON p.id = s.post_id AND p.is_deleted = 0
      INNER JOIN users u ON u.id = s.user_id
      WHERE p.user_id = :me AND s.user_id <> :me2
        {$blockSqlShareSave}
      ORDER BY s.shared_at DESC
      LIMIT 80
    ");
    $ssParams = [':me' => $meId, ':me2' => $meId];
    if ($blockSqlShareSave !== '') {
        $ssParams[':ssBlockMe'] = $meId;
        $ssParams[':ssBlockMe2'] = $meId;
    }
    $ssSt->execute($ssParams);
    foreach (($ssSt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $ss) {
        $pid = (int)($ss['post_id'] ?? 0);
        $actor = trim((string)($ss['actor_name'] ?? 'Someone'));
        $key = 'shares|' . strtolower($actor) . '|' . $pid;
        if ($pid <= 0 || isset($shareSaveSeen[$key])) {
            continue;
        }
        $shareSaveSeen[$key] = true;
        $notifications[] = [
            'id' => 0,
            'notiuser' => $actor,
            'notitype' => 'shared your post [p:' . $pid . ']',
            'created_at' => (string)($ss['acted_at'] ?? ''),
            'is_read' => 1,
            '_synthetic' => 1,
        ];
    }
} catch (Throwable $eSs) {
    // Keep regular notifications if shares query fails.
}
try {
    $svSt = $dbh->prepare("
      SELECT
        s.post_id,
        s.saved_at AS acted_at,
        COALESCE(NULLIF(TRIM(u.name), ''), NULLIF(TRIM(u.username), ''), CONCAT('User ', u.id)) AS actor_name
      FROM public_post_saves s
      INNER JOIN public_posts p ON p.id = s.post_id AND p.is_deleted = 0
      INNER JOIN users u ON u.id = s.user_id
      WHERE p.user_id = :me AND s.user_id <> :me2
        {$blockSqlShareSave}
      ORDER BY s.saved_at DESC
      LIMIT 80
    ");
    $svParams = [':me' => $meId, ':me2' => $meId];
    if ($blockSqlShareSave !== '') {
        $svParams[':ssBlockMe'] = $meId;
        $svParams[':ssBlockMe2'] = $meId;
    }
    $svSt->execute($svParams);
    foreach (($svSt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $sv) {
        $pid = (int)($sv['post_id'] ?? 0);
        $actor = trim((string)($sv['actor_name'] ?? 'Someone'));
        $key = 'saves|' . strtolower($actor) . '|' . $pid;
        if ($pid <= 0 || isset($shareSaveSeen[$key])) {
            continue;
        }
        $shareSaveSeen[$key] = true;
        $notifications[] = [
            'id' => 0,
            'notiuser' => $actor,
            'notitype' => 'saved your post [p:' . $pid . ']',
            'created_at' => (string)($sv['acted_at'] ?? ''),
            'is_read' => 1,
            '_synthetic' => 1,
        ];
    }
} catch (Throwable $eSv) {
    // Keep regular notifications if saves query fails.
}
usort($notifications, static function ($a, $b) {
    $ta = strtotime((string)($a['created_at'] ?? '')) ?: 0;
    $tb = strtotime((string)($b['created_at'] ?? '')) ?: 0;
    if ($ta === $tb) {
        return ((int)($b['id'] ?? 0)) <=> ((int)($a['id'] ?? 0));
    }
    return $tb <=> $ta;
});

// What’s happening — recent public posts from publishers
$happeningItems = [];
try {
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
  <style id="modal-fouc-lock-css"><?php include __DIR__ . '/includes/modal_fouc_lock.css.php'; ?></style>
  <?php post_action_thin_icons_render_css(); ?>
  <?php if (!defined('MSB_POST_ENGAGEMENT_JS')): ?>
  <script src="./js/post-engagement-sync.js?v=8"></script>
  <?php define('MSB_POST_ENGAGEMENT_JS', true); endif; ?>
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
      gap:10px;
      padding:8px 12px;
      min-height:44px;
      box-sizing:border-box;
    }
    .x-top-meta{min-width:0;flex:1 1 auto;}
    .x-top-name{
      margin:0;
      font-size:16px;
      font-weight:700;
      line-height:1.2;
      letter-spacing:-.01em;
      color:var(--x-text);
    }
    .x-top-sub{
      margin:1px 0 0;
      font-size:12px;
      line-height:1.2;
      color:var(--x-muted);
    }
    .x-settings-btn{
      display:inline-flex;
      align-items:center;
      justify-content:center;
      width:30px;
      height:30px;
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
    .x-settings-btn i{font-size:14px;}

    .x-tabs{
      display:flex;
      align-items:stretch;
      width:100%;
      height:40px;
      min-height:40px;
      overflow-x:auto;
      overflow-y:hidden;
      -webkit-overflow-scrolling:touch;
      scrollbar-width:none;
      box-sizing:border-box;
    }
    .x-tabs::-webkit-scrollbar{display:none;}
    .x-tab{
      flex:1 1 0;
      display:flex;
      align-items:center;
      justify-content:center;
      height:30px;
      min-height:30px;
      min-width:max-content;
      margin:0;
      padding:8px 10px 12px;
      border:0;
      background:transparent;
      color:var(--x-muted);
      font-size:13px;
      font-weight:400;
      line-height:1.2;
      text-decoration:none;
      position:relative;
      box-sizing:border-box;
      transition:background .15s ease,color .15s ease;
      cursor:pointer;
      font-family:inherit;
      appearance:none;
      -webkit-appearance:none;
      white-space:nowrap;
      outline:none;
      box-shadow:none;
    }
    .x-tab:hover{background:rgba(127,127,127,.07);color:var(--x-text);text-decoration:none;}
    .x-tab:focus,
    .x-tab:focus-visible,
    .x-tab:active{
      outline:none !important;
      box-shadow:none !important;
      border-color:transparent;
    }
    .x-tab.is-active{
      color:var(--x-text);
      font-size:13px;
      font-weight:400;
    }
    .x-tab.is-active::after{
      content:'';
      position:absolute;
      left:50%;
      bottom:0;
      transform:translateX(-50%);
      width:40px;
      max-width:70%;
      height:3px;
      border-radius:999px;
      background:var(--x-accent);
    }

    .noti-shell .alert{
      margin:10px 12px;
      border-radius:10px;
      font-size:13px;
      padding:10px 12px;
    }

    .x-noti-row[data-href]{cursor:pointer;}
    .x-noti-row{
      display:flex;
      align-items:flex-start;
      gap:10px;
      width:100%;
      padding:10px 12px;
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
      width:18px;
      padding-top:6px;
      text-align:center;
      color:var(--x-muted);
      font-size:13px;
      line-height:1;
      display:inline-flex;
      align-items:flex-start;
      justify-content:center;
    }
    .x-noti-icon.is-like{color:var(--msb-rx-like, #2563eb);}
    .x-noti-icon.is-love{color:var(--msb-rx-love, #ff4d6d);}
    .x-noti-icon.is-dislike{color:var(--msb-rx-dislike, #475569);}
    .x-noti-icon.is-comment{color:var(--x-accent);}
    .x-noti-icon.is-share{color:#6b7280;}
    .x-noti-icon.is-save{color:#f59e0b;}
    .x-noti-icon.is-mention{color:#00ba7c;}
    .x-noti-icon.is-tag{color:#f59e0b;}
    .x-noti-icon.is-whats-up{color:#f59e0b;}
    .x-noti-icon.is-follow{color:var(--x-accent);}
    .x-noti-icon.is-live{color:#f4212e;}
    .x-noti-icon.is-bell{color:var(--x-muted);}
    .x-noti-icon.is-react{color:inherit;}
    .x-noti-icon .msb-pact{
      width:14px !important;
      height:14px !important;
      min-width:14px !important;
      min-height:14px !important;
      flex-basis:14px !important;
      filter:none !important;
      color:currentColor !important;
    }
    .x-noti-icon.is-love .msb-pact-heart,
    .x-noti-icon.is-like.is-love .msb-pact-heart{
      color:var(--msb-rx-love, #ff4d6d) !important;
    }
    .x-noti-icon.is-like .msb-pact-thumb{
      color:var(--msb-rx-like, #2563eb) !important;
    }
    .x-noti-icon.is-dislike .msb-pact-thumb-down{
      color:var(--msb-rx-dislike, #475569) !important;
    }
    .x-noti-icon .msb-rx-face,
    .x-noti-icon .msb-rx-smile,
    .x-noti-icon .msb-rx-laugh,
    .x-noti-icon .msb-rx-wow,
    .x-noti-icon .msb-rx-sad,
    .x-noti-icon .msb-rx-angry,
    .x-noti-icon .x-noti-face{
      width:14px !important;
      height:14px !important;
      min-width:14px !important;
      min-height:14px !important;
      filter:none !important;
      display:inline-flex !important;
      align-items:center;
      justify-content:center;
      background:transparent !important;
      -webkit-mask:none !important;
      mask:none !important;
    }
    .x-noti-icon .x-noti-face svg{
      width:14px;
      height:14px;
      display:block;
    }
    .x-noti-icon .x-noti-emoji{
      display:inline-flex;
      align-items:center;
      justify-content:center;
      width:14px;
      height:14px;
      font-size:13px;
      line-height:1;
    }
    .x-noti-icon .fa{
      font-size:13px;
      line-height:1;
    }

    .x-noti-avatar{
      flex:0 0 auto;
      width:32px;
      height:32px;
      border-radius:999px;
      overflow:hidden;
      display:inline-flex;
      align-items:center;
      justify-content:center;
      font-weight:700;
      font-size:11px;
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
      gap:5px;
      flex-wrap:wrap;
      margin:0 0 2px;
      font-size:13px;
      line-height:1.25;
      color:var(--x-text);
      padding-right:28px;
    }
    .x-noti-name{
      font-weight:700;
      color:var(--x-text);
      font-size:13px;
    }
    .x-noti-sep{color:var(--x-muted);}
    .x-noti-time{color:var(--x-muted);font-weight:400;font-size:12px;}
    .x-noti-body{
      margin:0;
      font-size:13px;
      line-height:1.35;
      color:var(--x-text);
      word-break:break-word;
    }

    .x-noti-more{
      position:absolute;
      top:6px;
      right:6px;
    }
    .x-noti-more .dropdown-toggle{
      width:24px;
      height:24px;
      border:0;
      border-radius:999px;
      background:transparent;
      color:var(--x-muted);
      display:inline-flex;
      align-items:center;
      justify-content:center;
      padding:0;
      cursor:pointer;
      line-height:1;
      font-size:12px;
    }
    .x-noti-more .dropdown-toggle:hover,
    .x-noti-more .dropdown-toggle:focus{
      background:rgba(29,155,240,.1);
      color:var(--x-accent);
      outline:none;
      box-shadow:none;
    }
    .x-noti-more .dropdown-toggle::after{display:none;}
    .x-noti-more .dropdown-toggle .pcm-fries-icon{
      display:inline-flex;
      flex-direction:column;
      justify-content:center;
      align-items:flex-start;
      gap:2px;
      width:10px;
      color:currentColor;
    }
    .x-noti-more .dropdown-toggle .pcm-fries-bar{
      display:block;
      height:1.25px;
      border-radius:1px;
      background:currentColor;
      width:10px;
      filter:none;
      box-shadow:none;
    }
    .x-noti-more .dropdown-toggle .pcm-fries-bar--short{width:6px;}
    .x-noti-more .dropdown-menu{
      border-radius:10px;
      border:1px solid var(--x-border);
      box-shadow:0 8px 28px rgba(15,20,25,.12);
      min-width:160px;
      padding:4px 0;
    }
    .x-noti-more .dropdown-item{
      display:flex;
      align-items:center;
      gap:8px;
      font-size:13px;
      font-weight:500;
      line-height:1.25;
      color:var(--x-text);
      padding:8px 12px;
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
    .x-noti-more .dropdown-item i{
      width:14px;
      min-width:14px;
      margin-right:0;
      text-align:center;
      font-size:12px;
      line-height:1;
      opacity:.9;
    }

    .x-empty{
      padding:28px 16px;
      text-align:center;
    }
    .x-empty h3{
      margin:0 0 4px;
      font-size:15px;
      font-weight:700;
      letter-spacing:-.01em;
      color:var(--x-text);
    }
    .x-empty p{
      margin:0;
      font-size:12px;
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
      font-size: 13px;
      pointer-events: none;
    }
    .x-rail-search-input{
      width: 100%;
      height: 36px;
      border-radius: 999px;
      border: 1px solid var(--x-border);
      background: var(--msb-palette-input-bg, var(--msb-palette-surface-2, #eff3f4));
      color: var(--x-text);
      padding: 0 36px 0 14px;
      font-size: 13px;
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
      border-radius: 14px;
      margin-bottom: 14px;
      overflow: hidden;
      box-sizing: border-box;
    }
    .x-rail-card-pad{padding: 12px 14px 6px;}
    .x-rail-card-title{
      margin: 0;
      font-size: 15px;
      font-weight: 700;
      letter-spacing: -.01em;
      color: var(--x-text);
      line-height: 1.2;
    }
    .x-trend{
      display: block;
      width: 100%;
      padding: 10px 14px;
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
      font-size: 12px;
      color: var(--x-muted);
      line-height: 1.2;
      margin-bottom: 2px;
    }
    .x-trend-title{
      display: block;
      font-size: 14px;
      font-weight: 600;
      color: var(--x-text);
      line-height: 1.25;
      padding-right: 26px;
    }
    .x-trend-more{
      position: absolute;
      top: 8px;
      right: 8px;
      width: 24px;
      height: 24px;
      border-radius: 999px;
      color: var(--x-muted);
      display: inline-flex;
      align-items: center;
      justify-content: center;
      font-size: 13px;
      line-height: 1;
    }
    .x-trend-more .pcm-fries-icon{
      display:inline-flex;
      flex-direction:column;
      justify-content:center;
      align-items:flex-start;
      gap:2px;
      width:12px;
      color:currentColor;
    }
    .x-trend-more .pcm-fries-bar{
      display:block;
      height:1.5px;
      border-radius:1px;
      background:currentColor;
      width:12px;
      filter:none;
      box-shadow:none;
    }
    .x-trend-more .pcm-fries-bar--short{width:7px;}
    .x-rail-show-more{
      display: block;
      padding: 10px 14px;
      color: var(--x-accent);
      font-size: 13px;
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
      padding: 12px 6px 0;
      font-size: 12px;
      line-height: 1.45;
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
      font-size:15px !important;
      font-weight:700 !important;
      letter-spacing:-.01em !important;
      color:var(--x-text) !important;
    }
    body.notifications-page.feed-insta-ui .x-right-rail .sfy-see{
      font-size:12px !important;
      font-weight:700 !important;
    }
    body.notifications-page.feed-insta-ui .x-right-rail .sfy-name{
      font-size:14px !important;
      font-weight:700 !important;
    }
    body.notifications-page.feed-insta-ui .x-right-rail .sfy-sub{
      font-size:12px !important;
    }
    body.notifications-page.feed-insta-ui .x-right-rail .sfy-action{
      font-size:12px !important;
      font-weight:700 !important;
    }
    body.notifications-page.feed-insta-ui .x-right-rail .sfy-empty{
      font-size:13px !important;
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
              <button type="button" class="x-tab<?= $activeTab === 'whats-up' ? ' is-active' : '' ?>" data-noti-tab="whats-up"<?= $activeTab === 'whats-up' ? ' aria-current="page"' : '' ?>>What’s up</button>
              <button type="button" class="x-tab<?= $activeTab === 'mentions' ? ' is-active' : '' ?>" data-noti-tab="mentions"<?= $activeTab === 'mentions' ? ' aria-current="page"' : '' ?>>Mentions</button>
              <button type="button" class="x-tab<?= $activeTab === 'tags' ? ' is-active' : '' ?>" data-noti-tab="tags"<?= $activeTab === 'tags' ? ' aria-current="page"' : '' ?>>Tags</button>
              <button type="button" class="x-tab<?= $activeTab === 'reacts' ? ' is-active' : '' ?>" data-noti-tab="reacts"<?= $activeTab === 'reacts' ? ' aria-current="page"' : '' ?>>Reacts</button>
              <button type="button" class="x-tab<?= $activeTab === 'shares' ? ' is-active' : '' ?>" data-noti-tab="shares"<?= $activeTab === 'shares' ? ' aria-current="page"' : '' ?>>Shares</button>
              <button type="button" class="x-tab<?= $activeTab === 'saves' ? ' is-active' : '' ?>" data-noti-tab="saves"<?= $activeTab === 'saves' ? ' aria-current="page"' : '' ?>>Saves</button>
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
                  $postId = (int)($meta['post_id'] ?? 0);
                  $commentId = (int)($meta['comment_id'] ?? 0);
                  $isStoryNoti = (int)($meta['is_story'] ?? 0) === 1
                    || stripos($text, ' in a story') !== false
                    || (strpos($url, 'story_post=') !== false);
                  $isUnread = ((int)($item['is_read'] ?? 0) === 0);
                  $tab = notifications_tab($text);
                  $isMentionOrTag = notifications_is_tag_or_mention($tab);
                  [, , $iconClass] = notifications_icon($text, $liveId);
                  $iconHtml = notifications_icon_html($text, $liveId);
                  $initials = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $sender) ?: 'NT', 0, 2));
                  $peerKey = function_exists('normalize_avatar_key') ? normalize_avatar_key($sender) : $sender;
                  $peerColor = function_exists('color_from_string') ? color_from_string($peerKey) : '#536471';
                  $peerGrad = function_exists('avatar_gradient_style') ? avatar_gradient_style($peerColor) : ('background:' . $peerColor);
                  $avatarUrl = 'avatar.php?name=' . rawurlencode($sender);
                  $dateLabel = notifications_date_label((string)($item['created_at'] ?? ''));
                  $timeAgo = notifications_time_ago((string)($item['created_at'] ?? ''));
                  $nid = (int)($item['id'] ?? 0);
                  $isSynthetic = !empty($item['_synthetic']) || $nid <= 0;
                  $rowHidden = ($activeTab !== 'all' && $tab !== $activeTab);
                ?>
                <article class="x-noti-row<?= $isUnread ? ' is-unread' : '' ?>"
                         data-noti-card="<?= h($tab) ?>"
                         data-id="<?= $nid ?>"
                         <?php if ($postId > 0): ?>data-post-id="<?= $postId ?>"<?php endif; ?>
                         <?php if ($commentId > 0): ?>data-comment-id="<?= $commentId ?>"<?php endif; ?>
                         data-is-story="<?= $isStoryNoti ? '1' : '0' ?>"
                         <?php if ($isMentionOrTag || $commentId > 0): ?>data-hide-nav="1"<?php endif; ?>
                         <?php if ($url !== ''): ?>data-href="<?= h($url) ?>" role="link" tabindex="0"<?php endif; ?>
                         <?= $rowHidden ? 'hidden' : '' ?>>
                  <div class="x-noti-icon <?= h($iconClass) ?>" aria-hidden="true">
                    <?= $iconHtml ?>
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
                      <?= post_card_menu_fries_icon_html() ?>
                    </button>
                    <div class="dropdown-menu dropdown-menu-right">
                      <?php if ($postId > 0 && ($isMentionOrTag || $url !== '' || $isStoryNoti)): ?>
                        <button
                          type="button"
                          class="dropdown-item js-noti-view-post"
                          data-post-id="<?= $postId ?>"
                          data-comment-id="<?= $commentId ?>"
                          data-is-story="<?= $isStoryNoti ? '1' : '0' ?>"
                          data-hide-nav="<?= ($isMentionOrTag || $commentId > 0) ? '1' : '0' ?>"
                        ><i class="fa fa-expand" aria-hidden="true"></i><?= $isStoryNoti ? 'View story' : 'View the post' ?></button>
                      <?php elseif ($url !== ''): ?>
                        <a class="dropdown-item" href="<?= h($url) ?>">Open</a>
                      <?php endif; ?>
                      <?php if ($isUnread && !$isSynthetic && $nid > 0): ?>
                        <form method="post">
                          <input type="hidden" name="action" value="mark_one">
                          <input type="hidden" name="id" value="<?= $nid ?>">
                          <button type="submit" class="dropdown-item">Mark as read</button>
                        </form>
                      <?php endif; ?>
                      <?php if ($postId <= 0 && $url === '' && (!$isUnread || $isSynthetic)): ?>
                        <span class="dropdown-item text-muted" style="cursor:default;">No actions</span>
                      <?php endif; ?>
                    </div>
                  </div>
                </article>
              <?php endforeach; ?>
            </div>
            <div class="x-empty" id="notiEmptyMentions" hidden>
              <h3>Nothing in Mentions</h3>
              <p>When someone @mentions you, it’ll show up here.</p>
            </div>
            <div class="x-empty" id="notiEmptyTags" hidden>
              <h3>Nothing in Tags</h3>
              <p>When someone tags you in a post, it’ll show up here.</p>
            </div>
            <div class="x-empty" id="notiEmptyReacts" hidden>
              <h3>Nothing in Reacts</h3>
              <p>Loves, likes, and other reactions will show up here.</p>
            </div>
            <div class="x-empty" id="notiEmptyWhatsUp" hidden>
              <h3>Nothing in What’s up</h3>
              <p>When publishers you follow post, it’ll show up here.</p>
            </div>
            <div class="x-empty" id="notiEmptyShares" hidden>
              <h3>Nothing in Shares</h3>
              <p>When someone shares your post with others, it’ll show up here.</p>
            </div>
            <div class="x-empty" id="notiEmptySaves" hidden>
              <h3>Nothing in Saves</h3>
              <p>When someone saves your post to their account, it’ll show up here.</p>
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
                  <span class="x-trend-more" aria-hidden="true"><?= post_card_menu_fries_icon_html() ?></span>
                </a>
              <?php endforeach; ?>
              <a class="x-rail-show-more" href="home.php?tab=discover">Show more</a>
            <?php else: ?>
              <p class="x-trend" style="cursor:default;pointer-events:none;">
                <span class="x-trend-meta">Publishers</span>
                <span class="x-trend-title" style="font-weight:600;color:var(--x-muted);">No publisher posts yet.</span>
              </p>
              <a class="x-rail-show-more" href="home.php?tab=discover">Explore public</a>
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
          <div style="margin-top:4px;">© <?= date('Y') ?> Talsora</div>
        </div>
      </aside>
    </div>
  </div>
</div>

<?php
post_card_actions_menu_render_modals();
include __DIR__ . '/includes/post_viewer_modal.html.php';
include __DIR__ . '/includes/post_viewer_gallery_chrome.css.php';
post_card_actions_menu_render_css();
post_card_actions_menu_render_js([
  'delete_mode' => 'feed',
  'staff_readonly' => !empty($staffReadonly),
  'menu_surface' => 'notifications',
  'api_url' => 'feed_api.php',
  'always_portal' => true,
]);
$pvModalApiUrl = 'feed_api.php';
include __DIR__ . '/includes/post_viewer_modal.js.php';
?>
<script>
setTimeout(function(){ $('.alert-success,.alert-danger').fadeOut(); }, 2500);
(function(){
  var tabs = Array.prototype.slice.call(document.querySelectorAll('[data-noti-tab]'));
  var cards = Array.prototype.slice.call(document.querySelectorAll('[data-noti-card]'));
  var emptyByMode = {
    mentions: document.getElementById('notiEmptyMentions'),
    tags: document.getElementById('notiEmptyTags'),
    reacts: document.getElementById('notiEmptyReacts'),
    'whats-up': document.getElementById('notiEmptyWhatsUp'),
    shares: document.getElementById('notiEmptyShares'),
    saves: document.getElementById('notiEmptySaves')
  };
  var allowed = { all:1, mentions:1, tags:1, reacts:1, 'whats-up':1, shares:1, saves:1 };
  if (!tabs.length) return;

  function syncEmpty(mode){
    Object.keys(emptyByMode).forEach(function(key){
      var el = emptyByMode[key];
      if (!el) return;
      if (mode !== key) {
        el.hidden = true;
        return;
      }
      var visible = cards.some(function(card){
        return card.getAttribute('data-noti-card') === key && !card.hidden;
      });
      el.hidden = visible || cards.length === 0;
    });
  }

  function setTab(mode){
    mode = allowed[mode] ? mode : 'all';
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

  syncEmpty(<?= json_encode($activeTab, JSON_UNESCAPED_SLASHES) ?>);
  function openNotiStory(postId, href){
    postId = parseInt(postId, 10) || 0;
    if (postId <= 0 && href) {
      try {
        var uStory = new URL(href, window.location.href);
        postId = parseInt(uStory.searchParams.get('story_post') || '0', 10) || 0;
      } catch (eStoryUrl) {}
    }
    if (postId <= 0) return false;
    try {
      if (window.TTStories && typeof window.TTStories.openByPostId === 'function') {
        if (window.TTStories.openByPostId(postId)) return true;
      }
    } catch (eOpenStory) {}
    try {
      var dest = href && href.indexOf('story_post=') !== -1
        ? href
        : ('home.php?tab=for-you&story_post=' + encodeURIComponent(String(postId)));
      window.location.href = dest;
      return true;
    } catch (eNavStory) {}
    return false;
  }

  function openNotiViewPost(postId, hideNav, isStory, commentId){
    postId = parseInt(postId, 10) || 0;
    commentId = parseInt(commentId || 0, 10) || 0;
    if (postId <= 0) return false;
    if (isStory) {
      return openNotiStory(postId, 'home.php?tab=for-you&story_post=' + encodeURIComponent(String(postId)));
    }
    var opts = {};
    if (hideNav || commentId > 0) opts.hideNav = true;
    if (commentId > 0) opts.commentId = commentId;
    // Same Talsora #pvOverlay used by header bell → tagged/mentioned.
    try {
      if (typeof window.pvOpenById === 'function') {
        var opened = window.pvOpenById(postId, opts);
        var ov = document.getElementById('pvOverlay');
        if (opened === true || (ov && (ov.classList.contains('show') || ov.style.display === 'flex'))) {
          return true;
        }
        if (ov && !ov.hasAttribute('hidden') && ov.getAttribute('aria-hidden') === 'false') {
          return true;
        }
      }
    } catch (ePv) {}
    try {
      if (window.MSBPostCardMenu && typeof window.MSBPostCardMenu.openViewPost === 'function') {
        if (window.MSBPostCardMenu.openViewPost(postId, opts)) return true;
      }
    } catch (eMenu) {}
    // Last resort: same destination the header uses when the modal is unavailable.
    try {
      window.location.href = 'home.php?tab=for-you&open_post=' + encodeURIComponent(String(postId)) + (hideNav || commentId > 0 ? '&hide_nav=1' : '') + (commentId > 0 ? '&open_comment=' + encodeURIComponent(String(commentId)) : '');
      return true;
    } catch (eNav) {}
    return false;
  }

  function openNotiRow(row){
    if (!row) return;
    var href = String(row.getAttribute('data-href') || '').trim();
    var openPostId = parseInt(row.getAttribute('data-post-id') || '0', 10) || 0;
    var hideNav = row.getAttribute('data-hide-nav') === '1';
    var isStory = row.getAttribute('data-is-story') === '1';
    var commentId = parseInt(row.getAttribute('data-comment-id') || '0', 10) || 0;
    if (!openPostId && href) {
      try {
        var u = new URL(href, window.location.href);
        openPostId = parseInt(u.searchParams.get('story_post') || u.searchParams.get('open_post') || u.searchParams.get('post') || '0', 10) || 0;
        if (!hideNav) hideNav = u.searchParams.get('hide_nav') === '1';
        if (!isStory) isStory = !!u.searchParams.get('story_post');
        if (!commentId) commentId = parseInt(u.searchParams.get('open_comment') || '0', 10) || 0;
      } catch (eUrl) {}
    }
    if (!isStory) {
      try {
        var bodyEl = row.querySelector('.x-noti-body');
        var bodyText = bodyEl ? String(bodyEl.textContent || '').toLowerCase() : '';
        isStory = bodyText.indexOf(' in a story') !== -1;
      } catch (eBody) {}
    }
    if (openPostId > 0 && isStory && openNotiStory(openPostId, href)) {
      return;
    }
    if (openPostId > 0 && openNotiViewPost(openPostId, hideNav, false, commentId)) {
      return;
    }
    if (href) window.location.href = href;
  }

  document.addEventListener('click', function(e){
    var viewBtn = e.target && e.target.closest ? e.target.closest('.js-noti-view-post') : null;
    if (viewBtn) {
      e.preventDefault();
      e.stopPropagation();
      var postId = parseInt(viewBtn.getAttribute('data-post-id') || '0', 10) || 0;
      var hideNav = viewBtn.getAttribute('data-hide-nav') === '1';
      var isStory = viewBtn.getAttribute('data-is-story') === '1';
      var commentId = parseInt(viewBtn.getAttribute('data-comment-id') || '0', 10) || 0;
      var row = viewBtn.closest('.x-noti-row');
      if (!commentId && row) commentId = parseInt(row.getAttribute('data-comment-id') || '0', 10) || 0;
      if (!isStory && row) isStory = row.getAttribute('data-is-story') === '1';
      try {
        if (window.jQuery) {
          window.jQuery(viewBtn).closest('.dropdown').removeClass('show').find('.dropdown-menu').removeClass('show');
          window.jQuery(viewBtn).closest('.dropdown-toggle').attr('aria-expanded', 'false');
        }
      } catch (eDrop) {}
      if (openNotiViewPost(postId, hideNav, isStory, commentId)) return;
      openNotiRow(row);
      return;
    }
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
