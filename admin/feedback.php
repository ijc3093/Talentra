<?php
// /Business_only3/admin/feedback.php
declare(strict_types=1);

require_once __DIR__ . '/includes/session_admin.php';
requireAdminLogin();

require_once __DIR__ . '/includes/identity.php';
require_once __DIR__ . '/controller.php';

error_reporting(E_ALL);
ini_set('display_errors', '1');

$controller = new Controller();
$dbh = $controller->pdo();

$msg = '';
$error = '';

$filter = strtolower(trim((string)($_GET['filter'] ?? 'all')));
$filter = in_array($filter, ['all','unread','read'], true) ? $filter : 'all';

// Public support lanes (content reports belong on reports.php / user_activity.php).
$lane = strtolower(trim((string)($_GET['lane'] ?? $_POST['lane'] ?? 'all')));
$lane = in_array($lane, ['all', 'personal', 'customer', 'seller', 'publisher'], true) ? $lane : 'all';

$adminMode = isAdmin(); // base Admin role
$meId = myAdminId();

// ✅ internal chat uses friend_code in feedback_admin.sender/receiver
$me = myAdminFriendCode();
if ($me === '') $me = ensureAdminFriendCode($dbh);
if ($me === '') $me = myUsername(); // fallback (legacy)
if ($me === '' || $meId <= 0) die("Session missing username/id.");

function fmt_dt($dt): string { return $dt ? date('M d, Y h:i A', strtotime((string)$dt)) : ''; }
function isEmail($s): bool { return (strpos((string)$s, '@') !== false); }
function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$internalChannels = allowedInternalChannelsForMe();

/**
 * ==========================
 * ✅ SIGNED LINKS (CSRF-safe GET actions)
 * ==========================
 * Signs Reply / Mark / Delete links so they can't be tampered with.
 */

function signing_key(): string {
    // Prefer a constant secret (best)
    if (defined('APP_SIGNING_KEY') && APP_SIGNING_KEY !== '') {
        return (string)APP_SIGNING_KEY;
    }
    // Fallback: session-based secret
    if (empty($_SESSION['csrf_link_key'])) {
        $_SESSION['csrf_link_key'] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['csrf_link_key'];
}

function sign_link_token(string $action, string $peer, string $view, int $expiresTs): string {
    $adminId = (int)($_SESSION['admin_id'] ?? 0);
    $sid = session_id();
    $payload = $action . '|' . $peer . '|' . $view . '|' . $expiresTs . '|' . $adminId . '|' . $sid;
    return hash_hmac('sha256', $payload, signing_key());
}

function build_signed_link(
    string $base,
    array $params,
    string $action,
    string $peer,
    string $view,
    int $ttlSeconds = 600
): string {
    $exp = time() + max(60, $ttlSeconds);
    $params['exp'] = $exp;
    $params['sig'] = sign_link_token($action, $peer, $view, $exp);
    return $base . '?' . http_build_query($params);
}

function verify_signed_request(string $action, string $peer, string $view): bool {
    $exp = (int)($_GET['exp'] ?? 0);
    $sig = (string)($_GET['sig'] ?? '');
    if ($exp <= 0 || $sig === '') return false;
    if (time() > $exp) return false;

    $want = sign_link_token($action, $peer, $view, $exp);
    return hash_equals($want, $sig);
}

/**
 * Admin base role can switch public/internal.
 * Other base roles can only access internal.
 */
$view = strtolower(trim((string)($_GET['view'] ?? ($adminMode ? 'public' : 'internal'))));
$view = in_array($view, ['public','internal'], true) ? $view : 'internal';
if (!$adminMode) $view = 'internal';

function goBack(string $view, string $filter, string $msgKey = '', string $lane = 'all'): void {
    $q = "view=" . urlencode($view) . "&filter=" . urlencode($filter);
    if ($view === 'public') {
        $q .= "&lane=" . urlencode($lane);
    }
    if ($msgKey !== '') $q .= "&msg=" . urlencode($msgKey);
    header("Location: feedback.php?$q");
    exit;
}

/** Exclude legacy Content Report rows and commerce disputes from Help. */
function feedback_sql_not_content_report(string $alias = 'f'): string
{
    $a = preg_replace('/[^a-zA-Z0-9_]/', '', $alias) ?: 'f';
    return "(
        COALESCE({$a}.title, '') <> 'Content Report'
        AND COALESCE({$a}.feedbackdata, '') NOT LIKE '[Report #%'
        AND COALESCE({$a}.feedbackdata, '') NOT LIKE 'Reporter message:%'
        AND COALESCE({$a}.title, '') NOT LIKE '%Dispute%'
        AND COALESCE({$a}.feedbackdata, '') NOT LIKE '[Dispute]%'
        AND COALESCE({$a}.feedbackdata, '') NOT LIKE '[Seller dispute]%'
    )";
}

/**
 * SQL fragment matching a public support lane (scope + title/body fallback).
 * @return array{0:string,1:array<string,string>}
 */
function feedback_sql_public_lane(string $lane, string $alias = 'f'): array
{
    $a = preg_replace('/[^a-zA-Z0-9_]/', '', $alias) ?: 'f';
    $lane = strtolower(trim($lane));
    if ($lane === '' || $lane === 'all') {
        return ['1=1', []];
    }
    if (!in_array($lane, ['personal', 'customer', 'seller', 'publisher'], true)) {
        return ['1=0', []];
    }

    $scopeOk = "LOWER(TRIM(COALESCE({$a}.scope, ''))) = :lane_scope";
    $params = [':lane_scope' => $lane];

    if ($lane === 'seller') {
        $fallback = "(
            (
              COALESCE({$a}.title, '') LIKE 'Seller%'
              OR COALESCE({$a}.feedbackdata, '') LIKE '[Seller %'
            )
            AND COALESCE({$a}.title, '') NOT LIKE '%Dispute%'
            AND COALESCE({$a}.feedbackdata, '') NOT LIKE '[Seller dispute]%'
        )";
    } elseif ($lane === 'publisher') {
        $fallback = "(
            COALESCE({$a}.title, '') LIKE 'Publisher%'
            OR COALESCE({$a}.feedbackdata, '') LIKE '[Publisher %'
        )";
    } elseif ($lane === 'personal') {
        $fallback = "(
            COALESCE({$a}.title, '') LIKE 'Personal%'
            OR COALESCE({$a}.feedbackdata, '') LIKE '[Personal %'
        )";
    } else {
        // customer help only (disputes live on dispute.php)
        $fallback = "(
            COALESCE({$a}.title, '') LIKE 'Customer Help%'
            OR COALESCE({$a}.feedbackdata, '') LIKE '[Help] %'
        )";
    }

    // Prefer explicit scope; otherwise title/body prefixes from Support Center.
    $sql = "(
        {$scopeOk}
        OR (
            TRIM(COALESCE({$a}.scope, '')) = ''
            AND {$fallback}
        )
    )";
    return [$sql, $params];
}

/**
 * ==========================
 * ACTIONS (Mark / Delete) ✅ now require signed links
 * ==========================
 */

// MARK ONE THREAD READ (signed)
if (isset($_GET['mark']) && $_GET['mark'] !== '') {
    $peerKey = trim((string)$_GET['mark']);

    // ✅ MUST be signed (prevents tampering / CSRF)
    if (!verify_signed_request('mark', $peerKey, $view)) {
        goBack($view, $filter, '', $lane);
    }

    try {
        // PUBLIC
        if ($view === 'public') {
            if (!$adminMode || !isEmail($peerKey)) goBack($view, $filter, '', $lane);

            [$laneSql, $laneParams] = feedback_sql_public_lane($lane);
            $mk = $dbh->prepare("
                UPDATE feedback_admin f
                SET f.is_read = 1, f.read_at = NOW()
                WHERE f.channel='user_admin'
                  AND f.receiver='Admin'
                  AND f.sender = :peer
                  AND f.is_read = 0
                  AND " . feedback_sql_not_content_report('f') . "
                  AND {$laneSql}
            ");
            $mk->execute(array_merge([':peer' => $peerKey], $laneParams));
            goBack($view, $filter, 'threadread', $lane);
        }

        // INTERNAL (✅ friend_code)
        if (empty($internalChannels)) goBack($view, $filter, '', $lane);

        $peerCode = strtoupper(trim($peerKey));
        $ph = implode(',', array_fill(0, count($internalChannels), '?'));
        $mk = $dbh->prepare("
            UPDATE feedback_admin
            SET is_read = 1, read_at = NOW()
            WHERE receiver = ?
              AND sender = ?
              AND channel IN ($ph)
              AND is_read = 0
        ");
        $mk->execute(array_merge([$me, $peerCode], $internalChannels));
        goBack($view, $filter, 'threadread', $lane);

    } catch (Throwable $e) {
        $error = "DB error: " . $e->getMessage();
    }
}

// DELETE ONE THREAD (signed)
if (isset($_GET['del']) && $_GET['del'] !== '') {
    $peerKey = trim((string)$_GET['del']);

    // ✅ MUST be signed
    if (!verify_signed_request('del', $peerKey, $view)) {
        goBack($view, $filter, '', $lane);
    }

    try {
        // PUBLIC
        if ($view === 'public') {
            if (!$adminMode || !isEmail($peerKey)) goBack($view, $filter, '', $lane);

            [$laneSql, $laneParams] = feedback_sql_public_lane($lane);
            // Delete support messages for this peer in the active lane (never touch Content Reports).
            $del = $dbh->prepare("
                DELETE f FROM feedback_admin f
                WHERE f.channel='user_admin'
                  AND (
                        (f.sender=:peer AND f.receiver='Admin')
                     OR (f.sender='Admin' AND f.receiver=:peer2)
                  )
                  AND " . feedback_sql_not_content_report('f') . "
                  AND {$laneSql}
            ");
            $del->execute(array_merge([':peer'=>$peerKey, ':peer2'=>$peerKey], $laneParams));
            goBack($view, $filter, 'deleted', $lane);
        }

        // INTERNAL (✅ friend_code)
        if (empty($internalChannels)) goBack($view, $filter, '', $lane);

        $peerCode = strtoupper(trim($peerKey));
        $ph = implode(',', array_fill(0, count($internalChannels), '?'));
        $del = $dbh->prepare("
            DELETE FROM feedback_admin
            WHERE channel IN ($ph)
              AND (
                    (sender = ? AND receiver = ?)
                 OR (sender = ? AND receiver = ?)
              )
        ");
        $del->execute(array_merge($internalChannels, [$me, $peerCode, $peerCode, $me]));
        goBack($view, $filter, 'deleted', $lane);

    } catch (Throwable $e) {
        $error = "DB error: " . $e->getMessage();
    }
}

// MARK ALL READ (POST stays as-is)
if (isset($_POST['mark_all_read'])) {
    try {
        // PUBLIC
        if ($view === 'public') {
            if (!$adminMode) goBack($view, $filter, '', $lane);

            [$laneSql, $laneParams] = feedback_sql_public_lane($lane);
            $mk = $dbh->prepare("
                UPDATE feedback_admin f
                SET f.is_read=1, f.read_at=NOW()
                WHERE f.receiver='Admin'
                  AND f.channel='user_admin'
                  AND f.is_read=0
                  AND " . feedback_sql_not_content_report('f') . "
                  AND {$laneSql}
            ");
            $mk->execute($laneParams);
            goBack($view, $filter, 'allread', $lane);
        }

        // INTERNAL
        if (empty($internalChannels)) goBack($view, $filter, '', $lane);

        $ph = implode(',', array_fill(0, count($internalChannels), '?'));
        $mk = $dbh->prepare("
            UPDATE feedback_admin
            SET is_read=1, read_at=NOW()
            WHERE receiver=?
              AND channel IN ($ph)
              AND is_read=0
        ");
        $mk->execute(array_merge([$me], $internalChannels));
        goBack($view, $filter, 'allread', $lane);

    } catch (Throwable $e) {
        $error = "DB error: " . $e->getMessage();
    }
}

// DELETE ALL (POST stays as-is)
if (isset($_POST['delete_all'])) {
    try {
        // PUBLIC
        if ($view === 'public') {
            if (!$adminMode) goBack($view, $filter, '', $lane);

            [$laneSql, $laneParams] = feedback_sql_public_lane($lane);
            $del = $dbh->prepare("
                DELETE f FROM feedback_admin f
                WHERE f.receiver='Admin'
                  AND f.channel='user_admin'
                  AND " . feedback_sql_not_content_report('f') . "
                  AND {$laneSql}
            ");
            $del->execute($laneParams);
            goBack($view, $filter, 'deletedall', $lane);
        }

        // INTERNAL
        if (empty($internalChannels)) goBack($view, $filter, '', $lane);

        $ph = implode(',', array_fill(0, count($internalChannels), '?'));
        $del = $dbh->prepare("DELETE FROM feedback_admin WHERE receiver=? AND channel IN ($ph)");
        $del->execute(array_merge([$me], $internalChannels));
        goBack($view, $filter, 'deletedall', $lane);

    } catch (Throwable $e) {
        $error = "DB error: " . $e->getMessage();
    }
}

// UI messages
if (($_GET['msg'] ?? '') === 'allread')    $msg = "All messages marked as read.";
if (($_GET['msg'] ?? '') === 'threadread') $msg = "Thread marked as read.";
if (($_GET['msg'] ?? '') === 'deleted')    $msg = "Thread deleted.";
if (($_GET['msg'] ?? '') === 'deletedall') $msg = "All threads deleted.";

/**
 * ==========================
 * FETCH THREADS
 * ==========================
 */
$threads = [];

try {
    if ($view === 'public') {
        if (!$adminMode) {
            $threads = [];
        } else {
            [$laneSql, $laneParams] = feedback_sql_public_lane($lane);
            $sql = "
              SELECT
                f.sender AS peer_key,
                f.sender AS peer_display,
                MAX(f.created_at) AS last_time,
                SUM(CASE WHEN f.is_read=0 THEN 1 ELSE 0 END) AS unread_count,
                SUBSTRING_INDEX(
                  GROUP_CONCAT(f.feedbackdata ORDER BY f.created_at DESC SEPARATOR ' ||| '),
                  ' ||| ', 1
                ) AS last_message,
                SUBSTRING_INDEX(
                  GROUP_CONCAT(COALESCE(NULLIF(TRIM(f.scope), ''), COALESCE(f.title, '')) ORDER BY f.created_at DESC SEPARATOR ' ||| '),
                  ' ||| ', 1
                ) AS last_lane_hint
              FROM feedback_admin f
              WHERE f.receiver='Admin'
                AND f.channel='user_admin'
                AND " . feedback_sql_not_content_report('f') . "
                AND {$laneSql}
              GROUP BY f.sender
              ORDER BY last_time DESC
              LIMIT 500
            ";
            $stmt = $dbh->prepare($sql);
            $stmt->execute($laneParams);
            $threads = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }

    } else {
        if (empty($internalChannels)) {
            $threads = [];
        } else {
            $ph = implode(',', array_fill(0, count($internalChannels), '?'));

            $sql = "
              SELECT
                a.friend_code AS peer_key,
                CONCAT(
                  COALESCE(NULLIF(ac.display_name,''), NULLIF(a.fullname,''), a.username),
                  ' • ',
                  COALESCE(NULLIF(a.friend_code,''), a.username)
                ) AS peer_display,
                MAX(f.created_at) AS last_time,
                SUM(CASE WHEN f.is_read=0 AND f.receiver=? THEN 1 ELSE 0 END) AS unread_count,
                SUBSTRING_INDEX(
                  GROUP_CONCAT(f.feedbackdata ORDER BY f.created_at DESC SEPARATOR ' ||| '),
                  ' ||| ', 1
                ) AS last_message
              FROM feedback_admin f
              JOIN admin a
                ON a.friend_code = CASE WHEN f.sender=? THEN f.receiver ELSE f.sender END
              LEFT JOIN admin_contacts ac
                ON ac.owner_admin_id = ?
               AND ac.friend_admin_id = a.idadmin
              WHERE (f.sender=? OR f.receiver=?)
                AND f.channel IN ($ph)
              GROUP BY a.friend_code, peer_display
              ORDER BY last_time DESC
              LIMIT 500
            ";

            $stmt = $dbh->prepare($sql);
            $params = array_merge([$me, $me, $meId, $me, $me], $internalChannels);
            $stmt->execute($params);
            $threads = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
    }

    // Stats for current view+lane before read filter
    $threadsForStats = $threads;
    $totalThreads = count($threadsForStats);
    $unreadTotal = 0;
    $readTotal = 0;
    $unreadMsgs = 0;
    foreach ($threadsForStats as $tStat) {
        $u = (int)($tStat['unread_count'] ?? 0);
        if ($u > 0) {
            $unreadTotal++;
        } else {
            $readTotal++;
        }
        $unreadMsgs += $u;
    }

    if ($filter !== 'all') {
        $threads = array_values(array_filter($threads, function($t) use ($filter){
            $u = (int)($t['unread_count'] ?? 0);
            return ($filter === 'unread') ? ($u > 0) : ($u === 0);
        }));
    }

} catch (Throwable $e) {
    $error = "DB error: " . $e->getMessage();
    $threads = [];
    $threadsForStats = [];
    $totalThreads = 0;
    $unreadTotal = 0;
    $readTotal = 0;
    $unreadMsgs = 0;
}

if (!isset($threadsForStats)) {
    $threadsForStats = $threads;
    $totalThreads = count($threadsForStats);
    $unreadTotal = 0;
    $readTotal = 0;
    $unreadMsgs = 0;
}

/** Distinct public-lane thread counts (lightweight). */
function feedback_count_public_lane_threads(PDO $dbh, string $laneKey): int
{
    [$laneSql, $laneParams] = feedback_sql_public_lane($laneKey);
    $sql = "
      SELECT COUNT(*) FROM (
        SELECT f.sender
        FROM feedback_admin f
        WHERE f.receiver='Admin'
          AND f.channel='user_admin'
          AND " . feedback_sql_not_content_report('f') . "
          AND {$laneSql}
        GROUP BY f.sender
      ) t
    ";
    try {
        $st = $dbh->prepare($sql);
        $st->execute($laneParams);
        return (int)$st->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

$laneCounts = [
    'all' => $view === 'public' ? $totalThreads : 0,
    'personal' => 0,
    'customer' => 0,
    'seller' => 0,
    'publisher' => 0,
];
if ($view === 'public' && $adminMode) {
    if ($lane === 'all') {
        $laneCounts['all'] = $totalThreads;
    } else {
        $laneCounts['all'] = feedback_count_public_lane_threads($dbh, 'all');
    }
    foreach (['personal', 'customer', 'seller', 'publisher'] as $lk) {
        $laneCounts[$lk] = ($lane === $lk)
            ? $totalThreads
            : feedback_count_public_lane_threads($dbh, $lk);
    }
}

// Short preview (keep Actions visible)
function short_preview_plain(string $s, int $n = 70): string {
    $s = trim(html_entity_decode(strip_tags($s)));
    $s = preg_replace('/\s+/', ' ', $s) ?? $s;
    if (mb_strlen($s) <= $n) return $s;
    return mb_substr($s, 0, $n - 1) . '…';
}

function feedback_avatar_color(string $key): string
{
    $key = strtolower(trim($key));
    $hash = crc32($key);
    $palette = ['#2563eb','#7c3aed','#db2777','#ea580c','#16a34a','#0f766e','#0891b2','#475569'];
    return $palette[$hash % count($palette)];
}

function feedback_initials(string $peer): string
{
    $peer = trim($peer);
    if ($peer === '') {
        return '?';
    }
    $local = explode('@', $peer)[0] ?? $peer;
    $local = preg_replace('/[^a-zA-Z0-9]+/', ' ', $local) ?? $local;
    $parts = array_values(array_filter(explode(' ', trim($local))));
    if (!$parts) {
        return mb_strtoupper(mb_substr($peer, 0, 1));
    }
    $a = mb_strtoupper(mb_substr($parts[0], 0, 1));
    $b = count($parts) > 1
        ? mb_strtoupper(mb_substr($parts[count($parts) - 1], 0, 1))
        : mb_strtoupper(mb_substr($parts[0], 1, 1));
    $ini = trim($a . $b);
    return $ini !== '' ? $ini : '?';
}

$fbUrl = static function (array $extra = []) use ($view, $filter, $lane): string {
    $base = [
        'view' => $view,
        'filter' => $filter,
    ];
    if (($extra['view'] ?? $view) === 'public' || (!isset($extra['view']) && $view === 'public')) {
        $base['lane'] = $lane;
    }
    foreach ($extra as $k => $v) {
        $base[$k] = $v;
    }
    if (($base['view'] ?? '') !== 'public') {
        unset($base['lane']);
    }
    return 'feedback.php?' . http_build_query($base);
};

$subtitle = $view === 'public'
    ? 'Help — Personal / Customer / Seller / Publisher (not reports or disputes).'
    : 'Internal threads (friend_code) with contacts display names.';

require_once __DIR__ . '/includes/org_admin_helpers_load.php';
org_admin_render_head('Help');
require_once __DIR__ . '/includes/admin_chrome.php';
admin_chrome_open(null, [
    'title' => 'Help',
    'description' => $subtitle,
]);
?>

<style>
  html,body{height:100% !important;overflow:hidden !important;max-height:100dvh !important;}
  body.azia-admin{overflow:hidden !important;}
  .sh-mainpanel{
    height:100vh !important;max-height:100dvh !important;
    display:flex !important;flex-direction:column !important;overflow:hidden !important;
  }
  .sh-mainpanel > .sh-pagebody{
    overflow:hidden !important;display:flex !important;flex-direction:column !important;min-height:0 !important;
    padding-top:8px !important;padding-bottom:8px !important;padding-left:10px !important;padding-right:10px !important;
    margin-left:0 !important;margin-right:0 !important;flex:1 1 auto !important;background:#f4f6fb !important;
  }
  .fb-wrap{
    flex:1 1 auto;min-height:0;width:100%;max-width:100%;
    display:flex;flex-direction:column;gap:8px;overflow:hidden;padding:0 2px;box-sizing:border-box;
  }
  .fb-top{flex:0 0 auto;display:flex;align-items:center;justify-content:flex-end;gap:10px;min-width:0;}
  .fb-actions{display:flex;gap:6px;flex-wrap:wrap;align-items:center;}
  .fb-btn{
    height:30px;padding:0 10px;border-radius:8px;border:1px solid #e2e8f0;background:#fff;
    font-size:11px;font-weight:700;color:#334155;display:inline-flex;align-items:center;gap:5px;
    text-decoration:none;cursor:pointer;white-space:nowrap;
  }
  .fb-btn:hover{background:#f8fafc;text-decoration:none;color:#0f172a;}
  .fb-btn.primary{background:#2563eb;border-color:#2563eb;color:#fff;}
  .fb-btn.primary:hover{background:#1d4ed8;color:#fff;}
  .fb-btn.danger{border-color:#fecaca;color:#b91c1c;}
  .fb-btn.danger:hover{background:#fef2f2;color:#991b1b;}
  .fb-btn.sm{height:26px;padding:0 8px;font-size:10px;}
  .fb-btn:disabled{opacity:.45;cursor:not-allowed;}

  .fb-cards{flex:0 0 auto;display:grid;grid-template-columns:repeat(<?= ($view === 'public' && $adminMode) ? '7' : '4' ?>,minmax(0,1fr));gap:8px;}
  .fb-card{
    background:#fff;border:1px solid #eef2f7;border-radius:12px;padding:10px 12px;
    box-shadow:0 1px 2px rgba(15,23,42,.04);min-width:0;text-decoration:none;color:inherit;display:block;
  }
  .fb-card.is-kind{cursor:pointer;transition:border-color .15s, box-shadow .15s;}
  .fb-card.is-kind:hover{border-color:#bfdbfe;text-decoration:none;color:inherit;}
  .fb-card.is-kind.is-active{border-color:#2563eb;box-shadow:0 0 0 1px #2563eb inset;}
  .fb-card-top{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:6px;}
  .fb-card-top .lab{font-size:11px;font-weight:700;color:#64748b;}
  .fb-card-top .delta{font-size:10px;font-weight:800;color:#94a3b8;}
  .fb-ico{
    width:28px;height:28px;border-radius:999px;display:flex;align-items:center;justify-content:center;font-size:12px;flex:0 0 auto;
  }
  .fb-ico.purple{background:#f5f3ff;color:#7c3aed;}
  .fb-ico.green{background:#f0fdf4;color:#16a34a;}
  .fb-ico.blue{background:#dbeafe;color:#2563eb;}
  .fb-ico.orange{background:#fff7ed;color:#ea580c;}
  .fb-ico.red{background:#fef2f2;color:#dc2626;}
  .fb-ico.yellow{background:#fefce8;color:#ca8a04;}
  .fb-ico.teal{background:#f0fdfa;color:#0f766e;}
  .fb-card .val{font-size:20px;font-weight:800;color:#0f172a;line-height:1;}
  .fb-card .sub{font-size:10px;color:#94a3b8;font-weight:600;margin-top:4px;}

  .fb-kinds{
    flex:0 0 auto;display:flex;gap:0;background:#fff;border:1px solid #eef2f7;border-radius:10px;
    padding:0 4px;overflow:hidden;min-width:0;flex-wrap:wrap;
  }
  .fb-kinds a{
    flex:0 0 auto;padding:8px 14px;font-size:12px;font-weight:800;color:#64748b;text-decoration:none;
    border-bottom:2px solid transparent;white-space:nowrap;
  }
  .fb-kinds a .cnt{font-weight:700;color:#94a3b8;margin-left:4px;}
  .fb-kinds a.is-active{color:#2563eb;border-bottom-color:#2563eb;}
  .fb-kinds a:hover{color:#0f172a;text-decoration:none;}

  .fb-board{
    flex:1 1 auto;min-height:0;min-width:0;display:grid;gap:8px;overflow:hidden;
    grid-template-columns:minmax(0,1fr);
  }
  .fb-main{min-height:0;min-width:0;display:flex;flex-direction:column;overflow:hidden;}
  .fb-panel{
    flex:1 1 auto;min-height:0;min-width:0;background:#fff;border:1px solid #eef2f7;border-radius:12px;
    box-shadow:0 1px 2px rgba(15,23,42,.04);display:flex;flex-direction:column;overflow:hidden;
  }
  .fb-filters{
    flex:0 0 auto;display:flex;gap:6px;flex-wrap:wrap;align-items:center;
    padding:10px 12px;border-bottom:1px solid #eef2f7;background:#fafbfc;
  }
  .fb-search{position:relative;flex:1 1 160px;min-width:120px;max-width:240px;}
  .fb-search i{position:absolute;left:9px;top:50%;transform:translateY(-50%);color:#94a3b8;font-size:12px;}
  .fb-search input{
    height:30px;border:1px solid #e2e8f0;border-radius:8px;padding:0 9px 0 28px;font-size:11px;background:#fff;color:#0f172a;width:100%;
  }
  .fb-clear{font-size:11px;font-weight:700;color:#2563eb;text-decoration:none;margin-left:auto;}
  .fb-clear:hover{text-decoration:underline;}

  .fb-table-wrap{flex:1 1 auto;min-height:0;overflow:auto;overscroll-behavior:contain;}
  .fb-table{width:100%;table-layout:fixed;border-collapse:separate;border-spacing:0;}
  .fb-table th{
    text-align:left;font-size:9px;font-weight:800;letter-spacing:.03em;text-transform:uppercase;
    color:#64748b;padding:8px 6px;border-bottom:1px solid #eef2f7;background:#fff;
    position:sticky;top:0;z-index:3;white-space:nowrap;
  }
  .fb-table td{padding:8px 6px;border-bottom:1px solid #f1f5f9;vertical-align:middle;font-size:11px;color:#0f172a;overflow:hidden;}
  .fb-table tr:hover td{background:#f8fafc;}
  .fb-table th:nth-child(1),.fb-table td:nth-child(1){width:36px;}
  .fb-table th:nth-child(2),.fb-table td:nth-child(2){width:28%;}
  .fb-table th:nth-child(3),.fb-table td:nth-child(3){width:34%;}
  .fb-table th:nth-child(4),.fb-table td:nth-child(4){width:16%;}
  .fb-table th:nth-child(5),.fb-table td:nth-child(5){width:70px;}
  .fb-table th:nth-child(6),.fb-table td:nth-child(6){width:110px;overflow:visible;}

  .fb-user{display:flex;align-items:center;gap:8px;min-width:0;}
  .fb-av{
    width:28px;height:28px;border-radius:999px;color:#fff;font-size:10px;font-weight:800;
    display:flex;align-items:center;justify-content:center;flex:0 0 28px;
  }
  .fb-user .nm{font-weight:800;font-size:11px;color:#0f172a;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
  .fb-user .un{font-size:10px;color:#64748b;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
  .fb-preview{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:#334155;}
  .fb-when{font-size:10px;color:#64748b;}
  .fb-pill{
    display:inline-flex;align-items:center;padding:2px 7px;border-radius:999px;font-size:10px;font-weight:800;
  }
  .fb-pill.warn{background:#ffedd5;color:#c2410c;}
  .fb-pill.ok{background:#dcfce7;color:#15803d;}
  .fb-empty{padding:28px 12px;text-align:center;color:#64748b;font-size:12px;font-weight:700;}
  .fb-alert{flex:0 0 auto;padding:7px 9px;border-radius:8px;font-size:12px;font-weight:700;}
  .fb-alert.ok{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0;}
  .fb-alert.bad{background:#fef2f2;color:#991b1b;border:1px solid #fecaca;}
  .fb-foot{
    flex:0 0 auto;display:flex;align-items:center;justify-content:space-between;gap:8px;
    padding:8px 12px;border-top:1px solid #eef2f7;font-size:11px;color:#64748b;font-weight:600;
  }
  .fb-row-actions{display:inline-flex;align-items:center;gap:6px;}

  @media (max-width:1100px){
    .fb-wrap{overflow:auto;}
    .fb-cards{grid-template-columns:repeat(2,minmax(0,1fr));}
    .fb-board{grid-template-columns:1fr;}
    .fb-main,.fb-panel{overflow:visible;max-height:none;}
  }
</style>

<div class="sh-mainpanel">
  <div class="sh-pagebody">
    <div class="fb-wrap">

      <?php if ($error !== ''): ?><div class="fb-alert bad" id="msgshow"><?= h($error) ?></div><?php endif; ?>
      <?php if ($msg !== ''): ?><div class="fb-alert ok" id="msgshow"><?= h($msg) ?></div><?php endif; ?>

      <div class="fb-top">
        <div class="fb-actions">
          <?php if ($view === 'public'): ?>
            <a class="fb-btn" href="reports.php"><i class="fa fa-flag"></i> Reports</a>
            <a class="fb-btn" href="dispute.php"><i class="fa fa-balance-scale"></i> Disputes</a>
          <?php else: ?>
            <a class="fb-btn" href="contacts.php"><i class="fa fa-address-book"></i> Contacts</a>
          <?php endif; ?>
          <a class="fb-btn primary" href="compose.php"><i class="fa fa-plus"></i> New Message</a>
          <button type="button" class="fb-btn" data-toggle="modal" data-target="#markAllModal"<?= empty($threads) ? ' disabled' : '' ?>>
            <i class="fa fa-check"></i> Mark All Read
          </button>
          <button type="button" class="fb-btn danger" data-toggle="modal" data-target="#deleteAllModal"<?= empty($threads) ? ' disabled' : '' ?>>
            <i class="fa fa-trash"></i> Delete All
          </button>
        </div>
      </div>

      <div class="fb-cards">
        <a class="fb-card is-kind<?= $filter === 'all' && ($view !== 'public' || $lane === 'all') ? ' is-active' : '' ?>" href="<?= h($fbUrl(['filter' => 'all', 'lane' => $view === 'public' ? 'all' : $lane])) ?>">
          <div class="fb-card-top">
            <div style="display:flex;align-items:center;gap:8px;">
              <div class="fb-ico purple"><i class="fa fa-question-circle"></i></div>
              <div class="lab">Total threads</div>
            </div>
            <div class="delta">• view</div>
          </div>
          <div class="val"><?= number_format($totalThreads) ?></div>
          <div class="sub"><?= $view === 'public' ? 'Current lane' : 'Internal help' ?></div>
        </a>
        <a class="fb-card is-kind<?= $filter === 'unread' ? ' is-active' : '' ?>" href="<?= h($fbUrl(['filter' => 'unread'])) ?>">
          <div class="fb-card-top">
            <div style="display:flex;align-items:center;gap:8px;">
              <div class="fb-ico orange"><i class="fa fa-envelope"></i></div>
              <div class="lab">Unread</div>
            </div>
            <div class="delta">• threads</div>
          </div>
          <div class="val"><?= number_format($unreadTotal) ?></div>
          <div class="sub"><?= number_format($unreadMsgs) ?> unread messages</div>
        </a>
        <a class="fb-card is-kind<?= $filter === 'read' ? ' is-active' : '' ?>" href="<?= h($fbUrl(['filter' => 'read'])) ?>">
          <div class="fb-card-top">
            <div style="display:flex;align-items:center;gap:8px;">
              <div class="fb-ico green"><i class="fa fa-check"></i></div>
              <div class="lab">Read</div>
            </div>
            <div class="delta">• clear</div>
          </div>
          <div class="val"><?= number_format($readTotal) ?></div>
          <div class="sub">No unread messages</div>
        </a>
        <?php if ($view === 'public' && $adminMode): ?>
          <?php
            $laneCardMeta = [
              'personal' => ['Personal', 'blue', 'fa-user'],
              'customer' => ['Customer', 'teal', 'fa-life-ring'],
              'seller' => ['Seller', 'yellow', 'fa-shopping-bag'],
              'publisher' => ['Publisher', 'red', 'fa-book'],
            ];
            foreach ($laneCardMeta as $lk => $meta):
          ?>
            <a class="fb-card is-kind<?= $lane === $lk ? ' is-active' : '' ?>" href="<?= h($fbUrl(['view' => 'public', 'lane' => $lk])) ?>">
              <div class="fb-card-top">
                <div style="display:flex;align-items:center;gap:8px;">
                  <div class="fb-ico <?= h($meta[1]) ?>"><i class="fa <?= h($meta[2]) ?>"></i></div>
                  <div class="lab"><?= h($meta[0]) ?></div>
                </div>
                <div class="delta">• lane</div>
              </div>
              <div class="val"><?= number_format((int)$laneCounts[$lk]) ?></div>
              <div class="sub"><?= h($meta[0]) ?> help</div>
            </a>
          <?php endforeach; ?>
        <?php else: ?>
          <div class="fb-card">
            <div class="fb-card-top">
              <div style="display:flex;align-items:center;gap:8px;">
                <div class="fb-ico blue"><i class="fa fa-list"></i></div>
                <div class="lab">Showing</div>
              </div>
              <div class="delta">• list</div>
            </div>
            <div class="val"><?= number_format(count($threads)) ?></div>
            <div class="sub"><?= h(ucfirst($view)) ?> · <?= h(ucfirst($filter)) ?></div>
          </div>
        <?php endif; ?>
      </div>

      <nav class="fb-kinds" aria-label="Help view">
        <?php if ($adminMode): ?>
          <a href="<?= h($fbUrl(['view' => 'public', 'lane' => $lane])) ?>" class="<?= $view === 'public' ? 'is-active' : '' ?>">Public</a>
          <a href="<?= h($fbUrl(['view' => 'internal'])) ?>" class="<?= $view === 'internal' ? 'is-active' : '' ?>">Internal</a>
        <?php else: ?>
          <a href="<?= h($fbUrl(['view' => 'internal'])) ?>" class="is-active">Internal</a>
        <?php endif; ?>

        <?php if ($view === 'public' && $adminMode): ?>
          <?php
            $laneTabs = [
              'all' => 'All help',
              'personal' => 'Personal',
              'customer' => 'Customer',
              'seller' => 'Seller',
              'publisher' => 'Publisher',
            ];
            foreach ($laneTabs as $laneKey => $laneLabel):
          ?>
            <a href="<?= h($fbUrl(['view' => 'public', 'lane' => $laneKey])) ?>" class="<?= $lane === $laneKey ? 'is-active' : '' ?>">
              <?= h($laneLabel) ?>
              <span class="cnt">(<?= (int)$laneCounts[$laneKey] ?>)</span>
            </a>
          <?php endforeach; ?>
        <?php endif; ?>

        <span style="margin-left:auto;display:flex;">
          <?php foreach (['all' => 'All', 'unread' => 'Unread', 'read' => 'Read'] as $fk => $flab): ?>
            <a href="<?= h($fbUrl(['filter' => $fk])) ?>" class="<?= $filter === $fk ? 'is-active' : '' ?>"><?= h($flab) ?></a>
          <?php endforeach; ?>
        </span>
      </nav>

      <div class="fb-board">
        <div class="fb-main">
          <div class="fb-panel">
            <div class="fb-filters">
              <div class="fb-search">
                <i class="fa fa-search"></i>
                <input type="search" id="fbSearch" placeholder="Search peer or message..." autocomplete="off">
              </div>
              <a href="<?= h($fbUrl()) ?>" class="fb-clear" id="fbClear"><i class="fa fa-refresh"></i> Clear</a>
            </div>

            <div class="fb-table-wrap">
              <table class="fb-table" id="fbTable">
                <thead>
                  <tr>
                    <th>#</th>
                    <th>Peer</th>
                    <th>Last message</th>
                    <th>Time</th>
                    <th>Unread</th>
                    <th>Actions</th>
                  </tr>
                </thead>
                <tbody>
                <?php if (!$threads): ?>
                  <tr>
                    <td colspan="6">
                      <div class="fb-empty">
                        <?php if ($view === 'public'): ?>
                          No support help threads in this lane. Content reports are listed under
                          <a href="reports.php">Reports</a> and each user’s Activity page.
                        <?php else: ?>
                          No chat threads found.
                        <?php endif; ?>
                      </div>
                    </td>
                  </tr>
                <?php else: foreach ($threads as $i => $t):
                  $peerKey = (string)($t['peer_key'] ?? '');
                  $peerDisplay = (string)($t['peer_display'] ?? $peerKey);
                  $unread = (int)($t['unread_count'] ?? 0);
                  $plain = short_preview_plain((string)($t['last_message'] ?? ''), 70);
                  $lastTime = fmt_dt($t['last_time'] ?? '');
                  $ini = feedback_initials($peerDisplay !== '' ? $peerDisplay : $peerKey);
                  $bg = feedback_avatar_color($peerKey !== '' ? $peerKey : $peerDisplay);

                  $replyUrl = build_signed_link(
                      'sendreply.php',
                      [
                          'reply'  => $peerKey,
                          'view'   => $view,
                          'filter' => $filter,
                          'lane'   => $lane,
                      ],
                      'reply',
                      $peerKey,
                      $view,
                      900
                  );
                  $markUrl = build_signed_link(
                      'feedback.php',
                      [
                          'view'   => $view,
                          'filter' => $filter,
                          'lane'   => $lane,
                          'mark'   => $peerKey
                      ],
                      'mark',
                      $peerKey,
                      $view,
                      900
                  );
                  $delUrl = build_signed_link(
                      'feedback.php',
                      [
                          'view'   => $view,
                          'filter' => $filter,
                          'lane'   => $lane,
                          'del'    => $peerKey
                      ],
                      'del',
                      $peerKey,
                      $view,
                      900
                  );
                  $searchHay = strtolower($peerKey . ' ' . $peerDisplay . ' ' . $plain);
                ?>
                  <tr data-search="<?= h($searchHay) ?>">
                    <td><?= (int)($i + 1) ?></td>
                    <td>
                      <div class="fb-user">
                        <span class="fb-av" style="background:<?= h($bg) ?>;"><?= h($ini) ?></span>
                        <div style="min-width:0;">
                          <div class="nm" title="<?= h($peerDisplay) ?>"><?= h($peerDisplay) ?></div>
                          <div class="un" title="<?= h($peerKey) ?>"><?= h($peerKey) ?></div>
                        </div>
                      </div>
                    </td>
                    <td><div class="fb-preview" title="<?= h($plain) ?>"><?= h($plain) ?></div></td>
                    <td><div class="fb-when"><?= h($lastTime) ?></div></td>
                    <td>
                      <?php if ($unread > 0): ?>
                        <span class="fb-pill warn"><?= (int)$unread ?></span>
                      <?php else: ?>
                        <span class="fb-pill ok">0</span>
                      <?php endif; ?>
                    </td>
                    <td>
                      <div class="fb-row-actions">
                        <a class="fb-btn sm primary" href="<?= h($replyUrl) ?>" title="Reply"><i class="fa fa-mail-reply"></i></a>
                        <div class="fries-menu">
                          <button type="button" class="fries-toggle" title="Actions" aria-label="Actions" aria-haspopup="true">
                            <span class="fries-icon" aria-hidden="true"></span>
                          </button>
                          <div class="fries-dropdown" role="menu">
                            <?php if ($unread > 0): ?>
                              <button type="button" class="fries-item" role="menuitem"
                                      data-peer="<?= h($peerKey) ?>"
                                      data-peerdisplay="<?= h($peerDisplay) ?>"
                                      data-markurl="<?= h($markUrl) ?>"
                                      onclick="openThreadMarkModal(this);">
                                <i class="fa fa-check"></i> Mark read
                              </button>
                            <?php else: ?>
                              <button type="button" class="fries-item" role="menuitem" disabled>
                                <i class="fa fa-check"></i> Already read
                              </button>
                            <?php endif; ?>
                            <button type="button" class="fries-item fries-item-danger" role="menuitem"
                                    data-peer="<?= h($peerKey) ?>"
                                    data-peerdisplay="<?= h($peerDisplay) ?>"
                                    data-delurl="<?= h($delUrl) ?>"
                                    onclick="openThreadDeleteModal(this);">
                              <i class="fa fa-trash"></i> Delete
                            </button>
                          </div>
                        </div>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; endif; ?>
                </tbody>
              </table>
            </div>

            <div class="fb-foot">
              <span id="fbShowing"><?= (int)count($threads) ?> thread<?= count($threads) === 1 ? '' : 's' ?></span>
              <span><?= h(ucfirst($view)) ?><?= $view === 'public' ? ' · ' . h(ucfirst($lane)) : '' ?> · <?= h(ucfirst($filter)) ?></span>
            </div>
          </div>
        </div>
      </div>

    </div>
  </div>

  <div class="modal fade" id="markAllModal" tabindex="-1" role="dialog" aria-labelledby="markAllModalLabel">
    <div class="modal-dialog" role="document">
      <div class="modal-content">
        <form method="post">
          <input type="hidden" name="lane" value="<?= h($lane) ?>">
          <div class="modal-header">
            <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
            <h4 class="modal-title" id="markAllModalLabel">Mark All Read</h4>
          </div>
          <div class="modal-body">
            <p>Mark <b>all threads</b> in this view as read?</p>
            <p class="mono" style="opacity:.75;margin:0;font-family:ui-monospace,Menlo,monospace;font-size:12px;">View: <?= h($view) ?><?= $view === 'public' ? ' • Lane: ' . h($lane) : '' ?> • Filter: <?= h($filter) ?></p>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-default" data-dismiss="modal">Cancel</button>
            <button type="submit" name="mark_all_read" class="btn btn-primary">
              <i class="fa fa-check"></i> Mark All Read
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <div class="modal fade" id="deleteAllModal" tabindex="-1" role="dialog" aria-labelledby="deleteAllModalLabel">
    <div class="modal-dialog" role="document">
      <div class="modal-content">
        <form method="post">
          <input type="hidden" name="lane" value="<?= h($lane) ?>">
          <div class="modal-header">
            <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
            <h4 class="modal-title" id="deleteAllModalLabel">Delete All Threads</h4>
          </div>
          <div class="modal-body">
            <p>You are about to delete <b><?= (int)count($threads) ?></b> support thread(s) in this view<?= $view === 'public' ? ' / lane' : '' ?>.</p>
            <p style="color:#b91c1c;font-weight:700;margin:0;">This cannot be undone. Content reports are never deleted from here.</p>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-default" data-dismiss="modal">Cancel</button>
            <button type="submit" name="delete_all" class="btn btn-danger">
              <i class="fa fa-trash"></i> Delete All
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <div class="modal fade" id="markThreadModal" tabindex="-1" role="dialog" aria-labelledby="markThreadModalLabel">
    <div class="modal-dialog" role="document">
      <div class="modal-content">
        <div class="modal-header">
          <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
          <h4 class="modal-title" id="markThreadModalLabel">Mark Thread Read</h4>
        </div>
        <div class="modal-body">
          <p>Mark this thread as read?</p>
          <p style="margin:0;"><b id="mkPeerName"></b></p>
          <p style="opacity:.75;margin-top:6px;font-family:ui-monospace,Menlo,monospace;font-size:12px;" id="mkPeerKey"></p>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-default" data-dismiss="modal">Cancel</button>
          <a id="mkGo" class="btn btn-primary" href="#"><i class="fa fa-check"></i> Mark Read</a>
        </div>
      </div>
    </div>
  </div>

  <div class="modal fade" id="deleteThreadModal" tabindex="-1" role="dialog" aria-labelledby="deleteThreadModalLabel">
    <div class="modal-dialog" role="document">
      <div class="modal-content">
        <div class="modal-header">
          <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
          <h4 class="modal-title" id="deleteThreadModalLabel">Delete Thread</h4>
        </div>
        <div class="modal-body">
          <p>Delete this thread permanently?</p>
          <p style="margin:0;"><b id="delPeerName"></b></p>
          <p style="opacity:.75;margin-top:6px;font-family:ui-monospace,Menlo,monospace;font-size:12px;" id="delPeerKey"></p>
          <p style="margin-top:10px;color:#b91c1c;font-weight:700;">This cannot be undone.</p>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-default" data-dismiss="modal">Cancel</button>
          <a id="delGo" class="btn btn-danger" href="#"><i class="fa fa-trash"></i> Delete</a>
        </div>
      </div>
    </div>
  </div>
</div>

<script src="../lib/jquery/jquery.js"></script>
<script src="../lib/popper.js/popper.js"></script>
<script src="../lib/bootstrap/bootstrap.js"></script>
<script src="../js/shamcey.js"></script>
<script>
(function(){
  var search = document.getElementById('fbSearch');
  var table = document.getElementById('fbTable');
  var showing = document.getElementById('fbShowing');
  function applySearch(){
    if (!table) return;
    var q = (search && search.value ? search.value : '').toLowerCase().trim();
    var rows = table.querySelectorAll('tbody tr[data-search]');
    var visible = 0;
    rows.forEach(function(row){
      var hay = row.getAttribute('data-search') || '';
      var show = !q || hay.indexOf(q) !== -1;
      row.style.display = show ? '' : 'none';
      if (show) visible++;
    });
    if (showing) showing.textContent = visible + ' thread' + (visible === 1 ? '' : 's');
  }
  if (search) search.addEventListener('input', applySearch);
  var clear = document.getElementById('fbClear');
  if (clear) clear.addEventListener('click', function(e){
    e.preventDefault();
    if (search) search.value = '';
    applySearch();
  });
  setTimeout(function(){
    var el = document.getElementById('msgshow');
    if (el && window.jQuery) window.jQuery(el).fadeOut();
  }, 2500);
})();

function openThreadMarkModal(btn){
  var peer = btn.getAttribute('data-peer') || '';
  var peerDisplay = btn.getAttribute('data-peerdisplay') || peer;
  var markUrl = btn.getAttribute('data-markurl') || '#';
  document.getElementById('mkPeerName').textContent = peerDisplay;
  document.getElementById('mkPeerKey').textContent = peer;
  document.getElementById('mkGo').setAttribute('href', markUrl);
  if (window.jQuery) window.jQuery('#markThreadModal').modal('show');
}

function openThreadDeleteModal(btn){
  var peer = btn.getAttribute('data-peer') || '';
  var peerDisplay = btn.getAttribute('data-peerdisplay') || peer;
  var delUrl = btn.getAttribute('data-delurl') || '#';
  document.getElementById('delPeerName').textContent = peerDisplay;
  document.getElementById('delPeerKey').textContent = peer;
  document.getElementById('delGo').setAttribute('href', delUrl);
  if (window.jQuery) window.jQuery('#deleteThreadModal').modal('show');
}
</script>
<?php org_admin_render_foot(); ?>

