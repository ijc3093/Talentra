<?php
// /organization/ajax/org_feed_api.php
declare(strict_types=1);

/**
 * JSON API for the organization Home feed (mobile app).
 * Mirrors organization/feed.php: same queries, same session keys (pins, history,
 * seen reply counts, last-read pointer), same actions.
 *
 * GET  ?action=feed&tab=work|culture|all[&id=POST_ID]
 * GET  ?action=comments&pid=POST_ID
 * POST action=mark_read  pid, tab
 * POST action=pin|unpin  pid, tab
 * POST action=ack|comment|close_comments|edit_post|delete_post  csrf, post_id, ...
 */

error_reporting(0);
ini_set('display_errors', '0');
while (ob_get_level() > 0) { @ob_end_clean(); }
ob_start();

function ofa_out(array $data, int $status = 200): void
{
    while (ob_get_level() > 0) { @ob_end_clean(); }
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    $bootstrapLoad = __DIR__ . '/../../admin/includes/admin_linked_bootstrap_load.php';
    if (is_file($bootstrapLoad)) {
        require_once $bootstrapLoad;
        if (function_exists('admin_linked_apply_session_cookie_path')) {
            admin_linked_apply_session_cookie_path();
        }
    }
    session_start();
}

// Return JSON (not a login redirect) so the app can re-run the enterprise handoff.
if (empty($_SESSION['org_auth'])
    || empty($_SESSION['org_account_type'])
    || empty($_SESSION['org_account_id'])
    || (int)($_SESSION['org_active_org_id'] ?? 0) <= 0) {
    ofa_out(['ok' => false, 'code' => 'org_session_required', 'err' => 'Organization session required.'], 401);
}

try {
    require_once __DIR__ . '/../includes/session_org.php';
    require_once __DIR__ . '/../includes/org_context.php';
    require_once __DIR__ . '/../includes/org_feed_pulse.php';
} catch (Throwable $e) {
    ofa_out(['ok' => false, 'err' => 'Unable to load organization.'], 500);
}
error_reporting(0);
ini_set('display_errors', '0');

if (!isset($dbh) || !($dbh instanceof PDO)) {
    require_once __DIR__ . '/../../admin/controller.php';
    $dbh = (new Controller())->pdo();
}

// -------------------- Helpers (same rules as feed.php) --------------------

function ofa_post_label(string $type): string
{
    switch ($type) {
        case 'announcement': return 'Announcement';
        case 'direction': return 'Direction';
        case 'update': return 'Update';
        case 'weekly_update': return 'Weekly Update';
        case 'recognition': return 'Recognition';
        default: return ucfirst($type);
    }
}

function ofa_post_subject_row(array $p): string
{
    $title = trim((string)($p['title'] ?? ''));
    $created = (string)($p['created_at'] ?? '');
    $dt = $created !== '' ? strtotime($created) : time();
    $when = date('M j, Y g:ia', $dt ?: time());
    if ($title === '') {
        $title = ofa_post_label((string)($p['post_type'] ?? 'update'));
    }
    $id = (int)($p['id'] ?? 0);
    return $title . ' · ' . $when . ($id > 0 ? " · #{$id}" : '');
}

function ofa_post_card_title(array $p): string
{
    $title = trim((string)($p['title'] ?? ''));
    return $title !== '' ? $title : ofa_post_label((string)($p['post_type'] ?? 'update'));
}

function ofa_body_display_text(string $body): string
{
    $body = trim($body);
    if ($body === '') return '';
    $body = preg_replace('/<img[^>]*>/i', '', $body);
    $body = preg_replace('/!\[[^\]]*\]\([^\)]*\)/', '', $body);
    $text = html_entity_decode(strip_tags((string)$body), ENT_QUOTES, 'UTF-8');
    $text = preg_replace("/\r\n?/", "\n", $text);
    $lines = array_map(static function (string $line): string {
        return trim((string)preg_replace('/[ \t]+/', ' ', $line));
    }, explode("\n", (string)$text));
    return trim(implode("\n", array_filter($lines, static function (string $line): bool {
        return $line !== '';
    })));
}

function ofa_first_image_src(string $body): string
{
    $body = trim($body);
    if ($body === '') return '';
    if (preg_match('/<img[^>]+src\s*=\s*["\']([^"\']+)["\'][^>]*>/i', $body, $m)) return (string)$m[1];
    if (preg_match('/!\[[^\]]*\]\(([^\)\s]+)(?:\s+"[^"]*")?\)/', $body, $m2)) return (string)$m2[1];
    return '';
}

function ofa_attachment_kind(string $mime, string $ext): string
{
    $e = strtolower(ltrim($ext, '.'));
    $m = strtolower($mime);
    if (strpos($m, 'image/') === 0 || in_array($e, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg'], true)) return 'image';
    if (strpos($m, 'video/') === 0 || in_array($e, ['mp4', 'webm', 'ogg', 'mov', 'm4v'], true)) return 'video';
    if ($m === 'application/pdf' || $e === 'pdf') return 'pdf';
    if (in_array($e, ['ppt', 'pptx'], true) || strpos($m, 'presentation') !== false) return 'ppt';
    if (in_array($e, ['doc', 'docx'], true) || strpos($m, 'word') !== false) return 'doc';
    if (in_array($e, ['xls', 'xlsx', 'csv'], true) || strpos($m, 'spreadsheet') !== false || strpos($m, 'excel') !== false) return 'sheet';
    return 'file';
}

/** Absolute URL for a media path stored relative to /organization/. */
function ofa_media_url(string $path): string
{
    $path = trim($path);
    if ($path === '') return '';
    if (preg_match('~^https?://~i', $path)) return $path;

    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'talsora.com');
    $origin = ($https ? 'https://' : 'http://') . $host;

    if (strpos($path, '//') === 0) return ($https ? 'https:' : 'http:') . $path;
    if ($path[0] === '/') return $origin . $path;

    // Script lives in /organization/ajax/ → media is relative to /organization/.
    $orgDir = rtrim(str_replace('\\', '/', dirname(dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/organization/ajax/x.php')))), '/');
    return $origin . $orgDir . '/' . ltrim($path, '/');
}

function ofa_fetch_attachments(PDO $dbh, int $orgId, int $postId): array
{
    try {
        $st = $dbh->prepare("
            SELECT id, file_path,
              COALESCE(original_name, file_name) AS original_name,
              COALESCE(mime_type, mime, '') AS mime_type,
              COALESCE(ext, '') AS ext,
              COALESCE(file_size, 0) AS file_size
            FROM org_post_attachments
            WHERE org_id = :org AND post_id = :pid
            ORDER BY id ASC
        ");
        $st->execute([':org' => $orgId, ':pid' => $postId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

/** feed.php pick_primary_media(): image → video → body <img> → first other attachment. */
function ofa_primary_media(array $attachments, string $bodyImg): array
{
    foreach (['image', 'video'] as $want) {
        foreach ($attachments as $a) {
            $kind = ofa_attachment_kind((string)($a['mime_type'] ?? ''), (string)($a['ext'] ?? ''));
            if ($kind === $want) {
                return [
                    'type' => $kind,
                    'src' => ofa_media_url((string)($a['file_path'] ?? '')),
                    'name' => (string)($a['original_name'] ?? $kind),
                    'mime' => (string)($a['mime_type'] ?? ''),
                ];
            }
        }
    }
    if (trim($bodyImg) !== '') {
        return ['type' => 'image', 'src' => ofa_media_url($bodyImg), 'name' => 'image', 'mime' => 'image/*'];
    }
    if ($attachments) {
        $a = $attachments[0];
        return [
            'type' => ofa_attachment_kind((string)($a['mime_type'] ?? ''), (string)($a['ext'] ?? '')),
            'src' => ofa_media_url((string)($a['file_path'] ?? '')),
            'name' => (string)($a['original_name'] ?? 'file'),
            'mime' => (string)($a['mime_type'] ?? ''),
        ];
    }
    return ['type' => 'none', 'src' => '', 'name' => '', 'mime' => ''];
}

function ofa_has_reply_column(PDO $dbh): bool
{
    try {
        $st = $dbh->prepare("
            SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'org_post_comments'
              AND COLUMN_NAME = 'parent_comment_id'
        ");
        $st->execute();
        return ((int)($st->fetchColumn() ?: 0) > 0);
    } catch (Throwable $e) {
        return false;
    }
}

/** Comments in display order: each top-level comment followed by its replies. */
function ofa_fetch_comments(PDO $dbh, int $orgId, int $postId, bool $hasReplyColumn): array
{
    try {
        $extra = $hasReplyColumn ? ', c.parent_comment_id' : '';
        $st = $dbh->prepare("
            SELECT c.id, c.user_id, c.body, c.created_at $extra,
              COALESCE(ou.role,'member') AS role,
              COALESCE(m.fullname, s.fullname, CONCAT('Member #', om.member_id)) AS user_name
            FROM org_post_comments c
            LEFT JOIN organization_users ou ON ou.org_id = :org1 AND ou.user_id = c.user_id
            LEFT JOIN org_members om ON om.org_id = :org2 AND om.id = c.user_id
            LEFT JOIN managers m ON om.member_type = 'manager' AND m.id = om.member_id
            LEFT JOIN staff_accounts s ON om.member_type = 'staff' AND s.id = om.member_id
            WHERE c.post_id = :pid
            ORDER BY c.created_at ASC
            LIMIT 500
        ");
        $st->execute([':org1' => $orgId, ':org2' => $orgId, ':pid' => $postId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }

    $thread = [];
    $children = [];
    foreach ($rows as $c) {
        $parent = $hasReplyColumn ? (int)($c['parent_comment_id'] ?? 0) : 0;
        if ($parent > 0) $children[$parent][] = $c;
        else $thread[] = $c;
    }

    $pack = static function (array $c, bool $isReply): array {
        return [
            'id' => (int)($c['id'] ?? 0),
            'parent_id' => (int)($c['parent_comment_id'] ?? 0),
            'user_id' => (int)($c['user_id'] ?? 0),
            'user_name' => (string)($c['user_name'] ?? ('User #' . (int)($c['user_id'] ?? 0))),
            'role' => (string)($c['role'] ?? 'member'),
            'created_at' => (string)($c['created_at'] ?? ''),
            'body' => (string)($c['body'] ?? ''),
            'is_reply' => $isReply,
        ];
    };

    $out = [];
    foreach ($thread as $c) {
        $out[] = $pack($c, false);
        foreach ($children[(int)($c['id'] ?? 0)] ?? [] as $r) {
            $out[] = $pack($r, true);
        }
    }
    return $out;
}

function ofa_comment_count(PDO $dbh, int $postId): int
{
    try {
        $st = $dbh->prepare('SELECT COUNT(*) FROM org_post_comments WHERE post_id = :pid');
        $st->execute([':pid' => $postId]);
        return (int)($st->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        return 0;
    }
}

function ofa_tab(string $raw): string
{
    return in_array($raw, ['work', 'culture', 'all'], true) ? $raw : 'work';
}

function ofa_where_type(string $tab): string
{
    if ($tab === 'culture') return " AND p.post_type = 'recognition' ";
    if ($tab === 'work') return " AND p.post_type IN ('announcement','direction','update','weekly_update') ";
    return '';
}

// -------------------- Org + membership (same resolution as feed.php) --------------------

$orgId = (int)($ORG['id'] ?? 0);
if ($orgId <= 0) $orgId = (int)orgActiveOrgId();
if ($orgId <= 0) ofa_out(['ok' => false, 'code' => 'org_session_required', 'err' => 'Invalid organization context.'], 401);

$accountType = (string)orgAccountType();
$accountId = (int)orgAccountId();
if ($accountType !== 'manager' && $accountType !== 'staff') {
    $accountType = isOrgManager() ? 'manager' : 'staff';
}

$meMemberId = function_exists('orgMemberId') ? (int)orgMemberId() : 0;
$myRoleId = function_exists('orgRoleId') ? (int)orgRoleId() : 0;
if ($meMemberId <= 0) {
    try {
        $st = $dbh->prepare('SELECT id, role_id FROM org_members WHERE org_id = :org AND member_type = :mt AND member_id = :mid LIMIT 1');
        $st->execute([':org' => $orgId, ':mt' => $accountType, ':mid' => $accountId]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $meMemberId = (int)($row['id'] ?? 0);
        $myRoleId = (int)($row['role_id'] ?? 0);
    } catch (Throwable $e) { /* handled below */ }
}
if ($meMemberId <= 0) {
    ofa_out(['ok' => false, 'err' => 'Not a member of this organization.'], 403);
}

$meRole = ($accountType === 'manager') ? 'manager' : 'staff';
$canManagePosts = ($accountType === 'manager');
try {
    if ($myRoleId > 0) {
        $stR = $dbh->prepare('SELECT name FROM org_roles WHERE id = :id AND org_id = :org LIMIT 1');
        $stR->execute([':id' => $myRoleId, ':org' => $orgId]);
        $rl = strtolower((string)($stR->fetchColumn() ?: ''));
        if (in_array($rl, ['manager', 'staff', 'admin'], true)) $meRole = $rl;
    }
} catch (Throwable $e) { /* keep fallback */ }
try {
    $stChk = $dbh->prepare('SELECT role FROM organization_users WHERE org_id = :o AND user_id = :u LIMIT 1');
    $stChk->execute([':o' => $orgId, ':u' => $meMemberId]);
    $have = (string)($stChk->fetchColumn() ?: '');
    if ($have !== '') $meRole = $have;
} catch (Throwable $e) { /* ignore */ }

$myFullname = 'Member';
try {
    $stN = $dbh->prepare("
        SELECT COALESCE(m.fullname, s.fullname, 'Member')
        FROM org_members om
        LEFT JOIN managers m ON om.member_type = 'manager' AND m.id = om.member_id
        LEFT JOIN staff_accounts s ON om.member_type = 'staff' AND s.id = om.member_id
        WHERE om.org_id = :org AND om.id = :omid
        LIMIT 1
    ");
    $stN->execute([':org' => $orgId, ':omid' => $meMemberId]);
    $myFullname = (string)($stN->fetchColumn() ?: 'Member');
} catch (Throwable $e) { /* keep fallback */ }

if (empty($_SESSION['csrf_org_dash'])) {
    $_SESSION['csrf_org_dash'] = bin2hex(random_bytes(16));
}
$csrf = (string)$_SESSION['csrf_org_dash'];

$hasReplyColumn = ofa_has_reply_column($dbh);

$firstVisitNewWindowDays = 180;
try {
    $stS = $dbh->prepare('SELECT theme_json FROM org_settings WHERE org_id = :oid LIMIT 1');
    $stS->execute([':oid' => $orgId]);
    $theme = json_decode((string)($stS->fetchColumn() ?: ''), true);
    if (is_array($theme) && isset($theme['feed_first_visit_new_window_days'])) {
        $v = (int)$theme['feed_first_visit_new_window_days'];
        if ($v >= 0 && $v <= 3650) $firstVisitNewWindowDays = $v;
    }
} catch (Throwable $e) { /* default */ }

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$action = strtolower(trim((string)($_POST['action'] ?? $_GET['action'] ?? 'feed')));
$tab = ofa_tab((string)($_POST['tab'] ?? $_GET['tab'] ?? 'work'));

$pinKey = 'feed_pins_' . $orgId . '_' . $tab . '_' . $meMemberId;
$seenKey = 'feed_seen_' . $orgId . '_' . $tab . '_' . $meMemberId;
$readAtKey = 'feed_last_read_at_' . $orgId . '_' . $tab . '_' . $meMemberId;
$histKeyLatest = 'feed_latest_' . $orgId . '_' . $tab;
$histKeyList = 'feed_hist_' . $orgId . '_' . $tab . '_' . $meMemberId;
foreach ([$pinKey, $seenKey, $histKeyList] as $k) {
    if (!isset($_SESSION[$k]) || !is_array($_SESSION[$k])) $_SESSION[$k] = [];
}
if (!isset($_SESSION[$readAtKey])) $_SESSION[$readAtKey] = 0;

/** PHP-local datetime; created_at is compared as a string because MySQL's time zone may differ from PHP's. */
function ofa_since(int $ts): string
{
    return date('Y-m-d H:i:s', $ts);
}

function ofa_unread(PDO $dbh, int $orgId, string $tab, int $sinceTs): int
{
    try {
        $st = $dbh->prepare('SELECT COUNT(*) FROM org_posts p WHERE p.org_id = :org ' . ofa_where_type($tab) . ' AND p.created_at > :since');
        $st->execute([':org' => $orgId, ':since' => ofa_since($sinceTs)]);
        return (int)($st->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        return 0;
    }
}

$effectiveReadTs = (int)$_SESSION[$readAtKey] > 0
    ? (int)$_SESSION[$readAtKey]
    : time() - ($firstVisitNewWindowDays * 86400);

// -------------------- GET comments (comments door) --------------------

if ($action === 'comments') {
    $pid = (int)($_GET['pid'] ?? $_POST['pid'] ?? 0);
    if ($pid <= 0) ofa_out(['ok' => false, 'err' => 'Invalid post.'], 400);
    try {
        $st = $dbh->prepare('SELECT id, post_type, title, created_at, comments_locked FROM org_posts WHERE id = :pid AND org_id = :org LIMIT 1');
        $st->execute([':pid' => $pid, ':org' => $orgId]);
        $post = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        ofa_out(['ok' => false, 'err' => 'DB error.'], 500);
    }
    if (!$post) ofa_out(['ok' => false, 'err' => 'Post not found.'], 404);

    ofa_out([
        'ok' => true,
        'pid' => $pid,
        'meta' => ofa_post_label((string)($post['post_type'] ?? 'update')) . ' · ' . (string)($post['created_at'] ?? ''),
        'locked' => ((int)($post['comments_locked'] ?? 0) === 1),
        'count' => ofa_comment_count($dbh, $pid),
        'comments' => ofa_fetch_comments($dbh, $orgId, $pid, $hasReplyColumn),
        'has_reply_column' => $hasReplyColumn,
    ]);
}

// -------------------- mark_read (feed.php ?ajax=mark_read) --------------------

if ($action === 'mark_read') {
    $pid = (int)($_POST['pid'] ?? $_GET['pid'] ?? 0);
    if ($pid <= 0) ofa_out(['ok' => false, 'err' => 'Invalid post id'], 400);
    try {
        $st = $dbh->prepare('
            SELECT p.created_at, (SELECT COUNT(*) FROM org_post_comments c WHERE c.post_id = p.id) AS comment_count
            FROM org_posts p WHERE p.org_id = :org AND p.id = :pid LIMIT 1
        ');
        $st->execute([':org' => $orgId, ':pid' => $pid]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $_SESSION[$seenKey][$pid] = (int)($row['comment_count'] ?? 0);
        $ts = !empty($row['created_at']) ? (int)strtotime((string)$row['created_at']) : 0;
        if ($ts > (int)($_SESSION[$readAtKey] ?? 0)) $_SESSION[$readAtKey] = $ts;
    } catch (Throwable $e) {
        ofa_out(['ok' => false, 'err' => 'DB error'], 500);
    }
    $sinceTs = (int)$_SESSION[$readAtKey] > 0 ? (int)$_SESSION[$readAtKey] : time() - ($firstVisitNewWindowDays * 86400);
    ofa_out(['ok' => true, 'pid' => $pid, 'unread' => ofa_unread($dbh, $orgId, $tab, $sinceTs)]);
}

// -------------------- pin / unpin (feed.php ?action=pin|unpin) --------------------

if ($action === 'pin' || $action === 'unpin') {
    $pid = (int)($_POST['pid'] ?? $_GET['pid'] ?? 0);
    if ($pid <= 0) ofa_out(['ok' => false, 'err' => 'Invalid post.'], 400);
    $pins = array_values(array_unique(array_map('intval', $_SESSION[$pinKey])));
    if ($action === 'pin') {
        array_unshift($pins, $pid);
    } else {
        $pins = array_values(array_filter($pins, static function ($x) use ($pid) { return (int)$x !== $pid; }));
    }
    $pins = array_values(array_unique(array_map('intval', $pins)));
    if (count($pins) > 50) $pins = array_slice($pins, 0, 50);
    $_SESSION[$pinKey] = $pins;
    ofa_out(['ok' => true, 'pid' => $pid, 'pinned' => in_array($pid, $pins, true), 'pins' => $pins]);
}

// -------------------- POST actions (feed.php ajax=1) --------------------

if ($method === 'POST' && in_array($action, ['ack', 'comment', 'close_comments', 'edit_post', 'delete_post'], true)) {
    $postedCsrf = (string)($_POST['csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if ($postedCsrf === '' || !hash_equals($csrf, $postedCsrf)) {
        ofa_out(['ok' => false, 'code' => 'csrf', 'err' => 'Security check failed. Please refresh and try again.', 'csrf' => $csrf], 400);
    }

    $pid = (int)($_POST['post_id'] ?? 0);
    if ($pid <= 0) ofa_out(['ok' => false, 'err' => 'Invalid post.'], 400);

    try {
        $stChk = $dbh->prepare('SELECT comments_locked FROM org_posts WHERE id = :pid AND org_id = :org LIMIT 1');
        $stChk->execute([':pid' => $pid, ':org' => $orgId]);
        $lockedVal = $stChk->fetchColumn();
        if ($lockedVal === false) throw new RuntimeException('Post not found.');
        $isLocked = ((int)$lockedVal === 1);

        if ($action === 'delete_post' || $action === 'edit_post') {
            if (!$canManagePosts) throw new RuntimeException('Only the manager can edit or delete posts.');
            $stP = $dbh->prepare('SELECT id, title, body FROM org_posts WHERE id = :pid AND org_id = :org LIMIT 1');
            $stP->execute([':pid' => $pid, ':org' => $orgId]);
            $pRow = $stP->fetch(PDO::FETCH_ASSOC);
            if (!$pRow) throw new RuntimeException('Post not found.');

            if ($action === 'delete_post') {
                foreach (['org_post_attachments', 'org_post_comments', 'org_post_acknowledgements', 'org_post_likes', 'org_post_views'] as $tbl) {
                    try { $dbh->prepare("DELETE FROM $tbl WHERE post_id = :pid")->execute([':pid' => $pid]); } catch (Throwable $e) {}
                }
                $dbh->prepare('DELETE FROM org_posts WHERE id = :pid AND org_id = :org LIMIT 1')->execute([':pid' => $pid, ':org' => $orgId]);
                ofa_out(['ok' => true, 'deleted' => true, 'post_id' => $pid]);
            }

            $newTitle = trim((string)($_POST['edit_title'] ?? ''));
            $newBody = (string)($_POST['edit_body'] ?? '');
            if ($newTitle === '') $newTitle = (string)($pRow['title'] ?? '');
            if (mb_strlen($newTitle) > 255) throw new RuntimeException('Title is too long.');
            if (trim(strip_tags($newBody)) === '') throw new RuntimeException('Body cannot be empty.');
            $dbh->prepare('UPDATE org_posts SET title = :t, body = :b WHERE id = :pid AND org_id = :org LIMIT 1')
                ->execute([':t' => $newTitle, ':b' => $newBody, ':pid' => $pid, ':org' => $orgId]);
            ofa_out(['ok' => true, 'edited' => true, 'post_id' => $pid]);
        }

        if ($action === 'ack') {
            $dbh->prepare('
                INSERT INTO org_post_acknowledgements (post_id, user_id, acknowledged_at)
                VALUES (:pid, :uid, NOW())
                ON DUPLICATE KEY UPDATE acknowledged_at = VALUES(acknowledged_at)
            ')->execute([':pid' => $pid, ':uid' => $meMemberId]);
            $stA = $dbh->prepare('SELECT COUNT(*) FROM org_post_acknowledgements WHERE post_id = :pid');
            $stA->execute([':pid' => $pid]);
            ofa_out(['ok' => true, 'post_id' => $pid, 'i_acknowledged' => true, 'ack_count' => (int)$stA->fetchColumn()]);
        }

        if ($action === 'comment') {
            if ($isLocked) throw new RuntimeException('Comments are closed for this update.');
            $body = trim((string)($_POST['comment_body'] ?? ''));
            $replyTo = (int)($_POST['reply_to'] ?? 0);
            if ($body === '') throw new RuntimeException('Comment cannot be empty.');
            if (mb_strlen($body) > 500) $body = mb_substr($body, 0, 500);

            if (!$hasReplyColumn && $replyTo > 0) {
                $body = "↳ Reply to #{$replyTo}: " . $body;
                if (mb_strlen($body) > 500) $body = mb_substr($body, 0, 500);
                $replyTo = 0;
            }

            if ($hasReplyColumn) {
                $st = $dbh->prepare('
                    INSERT INTO org_post_comments (post_id, user_id, parent_comment_id, body, created_at)
                    SELECT :pid1, :uid, :parent_id, :body, NOW()
                    FROM org_posts p
                    WHERE p.id = :pid2 AND p.org_id = :org AND p.comments_locked = 0
                    LIMIT 1
                ');
                $st->execute([
                    ':pid1' => $pid, ':pid2' => $pid, ':uid' => $meMemberId,
                    ':parent_id' => ($replyTo > 0 ? $replyTo : null), ':body' => $body, ':org' => $orgId,
                ]);
            } else {
                $st = $dbh->prepare('
                    INSERT INTO org_post_comments (post_id, user_id, body, created_at)
                    SELECT :pid1, :uid, :body, NOW()
                    FROM org_posts p
                    WHERE p.id = :pid2 AND p.org_id = :org AND p.comments_locked = 0
                    LIMIT 1
                ');
                $st->execute([':pid1' => $pid, ':pid2' => $pid, ':uid' => $meMemberId, ':body' => $body, ':org' => $orgId]);
            }
            if ($st->rowCount() < 1) throw new RuntimeException('Comments are closed for this update.');

            ofa_out([
                'ok' => true,
                'post_id' => $pid,
                'count' => ofa_comment_count($dbh, $pid),
                'comments' => ofa_fetch_comments($dbh, $orgId, $pid, $hasReplyColumn),
            ]);
        }

        // close_comments
        if (!in_array($meRole, ['admin', 'manager'], true)) throw new RuntimeException('Only Manager/Admin can close comments.');
        $st = $dbh->prepare('UPDATE org_posts SET comments_locked = 1, locked_at = NOW(), updated_at = NOW() WHERE id = :pid AND org_id = :org LIMIT 1');
        $st->execute([':pid' => $pid, ':org' => $orgId]);
        if ($st->rowCount() < 1) throw new RuntimeException('Post not found for this organization.');
        ofa_out(['ok' => true, 'post_id' => $pid, 'locked' => true]);
    } catch (Throwable $e) {
        ofa_out(['ok' => false, 'err' => $e->getMessage()], 400);
    }
}

if ($action !== 'feed') {
    ofa_out(['ok' => false, 'err' => 'Invalid action.'], 400);
}

// -------------------- GET feed (feed.php home list / ?id= view) --------------------

$postId = (int)($_GET['id'] ?? 0);
$isView = $postId > 0;
$whereType = ofa_where_type($tab);

$postSelect = "
    SELECT
      p.id, p.org_id, p.author_id, p.author_role, p.post_type,
      p.title, p.body, p.visibility, p.comments_locked, p.locked_at, p.created_at,
      COALESCE(ou.role, '') AS author_org_role,
      COALESCE(m.fullname, s.fullname,
        CONCAT(UPPER(LEFT(om.member_type,1)), SUBSTRING(om.member_type,2), ' #', om.member_id)) AS author_name,
      (SELECT COUNT(*) FROM org_post_comments c WHERE c.post_id = p.id) AS comment_count,
      (SELECT COUNT(*) FROM org_post_acknowledgements a WHERE a.post_id = p.id) AS ack_count,
      EXISTS(SELECT 1 FROM org_post_acknowledgements a2 WHERE a2.post_id = p.id AND a2.user_id = :me_id) AS i_acknowledged
    FROM org_posts p
    LEFT JOIN organization_users ou ON ou.org_id = p.org_id AND ou.user_id = p.author_id
    LEFT JOIN org_members om ON om.org_id = p.org_id AND om.id = p.author_id
    LEFT JOIN managers m ON om.member_type = 'manager' AND m.id = om.member_id
    LEFT JOIN staff_accounts s ON om.member_type = 'staff' AND s.id = om.member_id
";

$sideSelect = "
    SELECT p.id, p.post_type, p.title, p.created_at,
      (SELECT COUNT(*) FROM org_post_comments c WHERE c.post_id = p.id) AS comment_count,
      (SELECT COUNT(*) FROM org_post_attachments a WHERE a.post_id = p.id AND a.org_id = p.org_id) AS media_count,
      EXISTS(
        SELECT 1 FROM org_post_attachments a2
        WHERE a2.post_id = p.id AND a2.org_id = p.org_id
          AND COALESCE(a2.mime_type, a2.mime, '') LIKE 'video/%'
      ) AS has_video
    FROM org_posts p
";

$currentPost = [];
$sidebarPosts = [];
$flashErr = '';

try {
    if (!$isView) {
        // Newest unread post in this tab, else newest post in this tab.
        $st = $dbh->prepare($postSelect . " WHERE p.org_id = :org_id $whereType AND p.created_at > :since ORDER BY p.created_at DESC LIMIT 1");
        $st->execute([':org_id' => $orgId, ':me_id' => $meMemberId, ':since' => ofa_since($effectiveReadTs)]);
        $currentPost = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        if (!$currentPost) {
            $st = $dbh->prepare($postSelect . " WHERE p.org_id = :org_id $whereType ORDER BY p.created_at DESC LIMIT 1");
            $st->execute([':org_id' => $orgId, ':me_id' => $meMemberId]);
            $currentPost = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        }
        $currentPostId = (int)($currentPost['id'] ?? 0);

        $prevLatest = (int)($_SESSION[$histKeyLatest] ?? 0);
        if ($currentPostId > 0 && $prevLatest > 0 && $prevLatest !== $currentPostId) {
            array_unshift($_SESSION[$histKeyList], $prevLatest);
        }
        if ($currentPostId > 0) $_SESSION[$histKeyLatest] = $currentPostId;
        $_SESSION[$histKeyList] = array_slice(array_values(array_unique(array_map('intval', $_SESSION[$histKeyList]))), 0, 50);

        $histIds = array_values(array_filter(array_map('intval', $_SESSION[$histKeyList]), static function ($x) use ($currentPostId) {
            return $x > 0 && $x !== $currentPostId;
        }));
        if ($histIds) {
            $in = implode(',', array_fill(0, count($histIds), '?'));
            $stH = $dbh->prepare($sideSelect . " WHERE p.org_id = ? AND p.id IN ($in) ORDER BY FIELD(p.id, $in) LIMIT 20");
            $stH->execute(array_merge([$orgId], $histIds, $histIds));
            $sidebarPosts = $stH->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }

        if (count($sidebarPosts) < 20) {
            $need = 20 - count($sidebarPosts);
            $params = [':org_id' => $orgId];
            $ph = [];
            $i = 0;
            foreach ($sidebarPosts as $r) {
                $sid = (int)($r['id'] ?? 0);
                if ($sid <= 0) continue;
                $k = ':x' . $i++;
                $ph[] = $k;
                $params[$k] = $sid;
            }
            $notIn = $ph ? ' AND p.id NOT IN (' . implode(',', $ph) . ') ' : '';
            $stMore = $dbh->prepare($sideSelect . " WHERE p.org_id = :org_id $whereType $notIn ORDER BY p.created_at DESC LIMIT $need");
            $stMore->execute($params);
            foreach ($stMore->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) $sidebarPosts[] = $r;
        }
    } else {
        array_unshift($_SESSION[$histKeyList], $postId);
        $_SESSION[$histKeyList] = array_slice(array_values(array_unique(array_map('intval', $_SESSION[$histKeyList]))), 0, 50);

        $stS = $dbh->prepare($sideSelect . " WHERE p.org_id = :org_id $whereType AND p.id <> :cur ORDER BY p.created_at DESC LIMIT 20");
        $stS->execute([':org_id' => $orgId, ':cur' => $postId]);
        $sidebarPosts = $stS->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $st = $dbh->prepare($postSelect . ' WHERE p.org_id = :org_id AND p.id = :pid LIMIT 1');
        $st->execute([':org_id' => $orgId, ':pid' => $postId, ':me_id' => $meMemberId]);
        $currentPost = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        if ($currentPost) {
            $_SESSION[$seenKey][$postId] = ofa_comment_count($dbh, $postId);
        } else {
            $isView = false;
        }
    }
} catch (Throwable $e) {
    $flashErr = 'DB error: ' . $e->getMessage();
}

// Pinned items first.
$pins = array_values(array_unique(array_map('intval', $_SESSION[$pinKey] ?? [])));
if ($pins && $sidebarPosts) {
    $pinSet = array_flip($pins);
    $pinned = [];
    $rest = [];
    foreach ($sidebarPosts as $r) {
        if (isset($pinSet[(int)($r['id'] ?? 0)])) $pinned[] = $r;
        else $rest[] = $r;
    }
    $sidebarPosts = array_merge($pinned, $rest);
}

$activePostId = (int)($currentPost['id'] ?? 0);

$postOut = null;
if ($currentPost) {
    $pid = $activePostId;
    $body = (string)($currentPost['body'] ?? '');
    $attachments = ofa_fetch_attachments($dbh, $orgId, $pid);
    $primary = ofa_primary_media($attachments, ofa_first_image_src($body));
    $hasMedia = $primary['type'] !== 'none' && $primary['src'] !== '';
    $rawTitle = trim((string)($currentPost['title'] ?? ''));
    $description = ofa_body_display_text($body);

    $gallery = [];
    foreach ($attachments as $a) {
        $src = ofa_media_url((string)($a['file_path'] ?? ''));
        if ($src === '') continue;
        $gallery[] = [
            'type' => ofa_attachment_kind((string)($a['mime_type'] ?? ''), (string)($a['ext'] ?? '')),
            'src' => $src,
            'name' => (string)($a['original_name'] ?? 'attachment'),
            'mime' => (string)($a['mime_type'] ?? ''),
        ];
    }

    $postOut = [
        'id' => $pid,
        'post_type' => (string)($currentPost['post_type'] ?? 'update'),
        'label' => ofa_post_label((string)($currentPost['post_type'] ?? 'update')),
        'title' => $rawTitle,
        'card_title' => ofa_post_card_title($currentPost),
        'subject' => ofa_post_subject_row($currentPost),
        'description' => $description,
        'body_html' => $canManagePosts ? $body : '',
        'created_at' => (string)($currentPost['created_at'] ?? ''),
        'author_name' => (string)($currentPost['author_name'] ?? 'Unknown'),
        'author_role' => (string)(($currentPost['author_org_role'] ?? '') !== '' ? $currentPost['author_org_role'] : ($currentPost['author_role'] ?? 'member')),
        'locked' => ((int)($currentPost['comments_locked'] ?? 0) === 1),
        'i_acknowledged' => ((int)($currentPost['i_acknowledged'] ?? 0) === 1),
        'ack_count' => (int)($currentPost['ack_count'] ?? 0),
        'comment_count' => (int)($currentPost['comment_count'] ?? 0),
        'pinned' => in_array($pid, $pins, true),
        'primary_media' => $primary,
        'media' => $gallery,
        'has_media' => $hasMedia,
        'text_in_media' => !$hasMedia && ($rawTitle !== '' || $description !== ''),
        'detail_title' => $hasMedia ? ofa_post_card_title($currentPost) : '',
        'comments' => ofa_fetch_comments($dbh, $orgId, $pid, $hasReplyColumn),
    ];
}

$history = [];
foreach ($sidebarPosts as $sp) {
    $sid = (int)($sp['id'] ?? 0);
    $scnt = (int)($sp['comment_count'] ?? 0);
    $seen = $_SESSION[$seenKey][$sid] ?? null;
    $sdt = (string)($sp['created_at'] ?? '');
    $history[] = [
        'id' => $sid,
        'post_type' => (string)($sp['post_type'] ?? 'update'),
        'label' => ofa_post_label((string)($sp['post_type'] ?? 'update')),
        'subject' => ofa_post_subject_row($sp),
        'created_at' => $sdt,
        'when' => date('M j, Y g:ia', ($sdt !== '' ? strtotime($sdt) : time()) ?: time()),
        'comment_count' => $scnt,
        'media_count' => (int)($sp['media_count'] ?? 0),
        'has_video' => ((int)($sp['has_video'] ?? 0) === 1),
        'is_new' => ($seen !== null && $scnt > (int)$seen),
        'pinned' => in_array($sid, $pins, true),
        'is_active' => ($sid > 0 && $sid === $activePostId),
    ];
}

$currentlyViewing = null;
if ($currentPost) {
    $currentlyViewing = [
        'id' => $activePostId,
        'label' => ofa_post_label((string)($currentPost['post_type'] ?? 'update')),
        'subject' => ofa_post_subject_row($currentPost),
        'replies' => $isView ? (int)($_SESSION[$seenKey][$activePostId] ?? 0) : (int)($currentPost['comment_count'] ?? 0),
        'pinned' => in_array($activePostId, $pins, true),
    ];
}

$pulse = org_feed_pulse_stats($dbh, $orgId);

ofa_out([
    'ok' => true,
    'tab' => $tab,
    'is_view' => $isView,
    'csrf' => $csrf,
    'org' => [
        'id' => $orgId,
        'name' => (string)($ORG['name'] ?? 'Organization'),
        'code' => (string)($ORG['org_code'] ?? ''),
    ],
    'me' => [
        'member_id' => $meMemberId,
        'name' => $myFullname,
        'role' => $meRole,
        'account_type' => $accountType,
        'can_manage_posts' => $canManagePosts,
        'is_managerish' => in_array($meRole, ['admin', 'manager'], true),
    ],
    'unread' => ofa_unread($dbh, $orgId, $tab, $effectiveReadTs),
    'pulse' => [
        'posts_7d' => (int)($pulse['posts_7d'] ?? 0),
        'comments_7d' => (int)($pulse['comments_7d'] ?? 0),
        'acks_7d' => (int)($pulse['acks_7d'] ?? 0),
    ],
    'post' => $postOut,
    'currently_viewing' => $currentlyViewing,
    'history' => $history,
    'has_reply_column' => $hasReplyColumn,
    'error' => $flashErr,
]);
