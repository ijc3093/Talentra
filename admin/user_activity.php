<?php
declare(strict_types=1);

/**
 * Admin — User Activity Overview (per-user moderation review).
 */
require_once __DIR__ . '/includes/org_admin_helpers_load.php';
org_admin_require_admin();

error_reporting(E_ALL);
ini_set('display_errors', '1');

$dbh = org_admin_db();
require_once __DIR__ . '/includes/msb_moderation_activity.php';
require_once dirname(__DIR__) . '/public_user/includes/msb_reports.php';

$userId = (int)($_GET['user_id'] ?? $_GET['id'] ?? 0);
if ($userId <= 0) {
    header('Location: account_search.php');
    exit;
}

$user = org_admin_get_public_user($dbh, $userId);
if (!$user) {
    header('Location: account_search.php');
    exit;
}

$msg = '';
$error = '';
$adminId = (int)($_SESSION['admin_id'] ?? $_SESSION['idadmin'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mod_status'])) {
    $res = msb_mod_status_set(
        $dbh,
        $userId,
        (string)($_POST['mod_status'] ?? 'normal'),
        $adminId,
        (string)($_POST['mod_note'] ?? '')
    );
    if (!empty($res['ok'])) {
        $redir = 'user_activity.php?user_id=' . $userId . '&msg=modsaved';
        $pidKeep = (int)($_POST['post_id'] ?? 0);
        if ($pidKeep > 0) {
            $redir .= '&post_id=' . $pidKeep;
        }
        header('Location: ' . $redir);
        exit;
    }
    $error = (string)($res['error'] ?? 'Could not save moderation status.');
}
if (($_GET['msg'] ?? '') === 'modsaved') {
    $msg = 'Moderator decision saved.';
}

$isPublisher = strtolower(trim((string)($user['account_kind'] ?? ''))) === 'publisher';
$followerCount = $isPublisher ? org_admin_user_follower_count($dbh, $userId) : 0;
$friendCount = !$isPublisher ? org_admin_user_friend_count($dbh, $userId) : 0;
$followingCount = 0;
if (msb_mod_table_exists($dbh, 'public_follows')) {
    $followingCount = msb_mod_count_safe($dbh, 'SELECT COUNT(*) FROM public_follows WHERE follower_id = :uid', [':uid' => $userId]);
}

$focusPostId = (int)($_GET['post_id'] ?? 0);
// Support legacy links that used #post-123 only.
if ($focusPostId <= 0 && !empty($_GET['post'])) {
    $focusPostId = (int)$_GET['post'];
}

$bundle = msb_mod_activity_overview_bundle($dbh, $userId, $focusPostId);

// If the clicked post belongs to a different user id than the URL, reload that user.
$resolvedUid = (int)($bundle['resolved_user_id'] ?? $userId);
if ($resolvedUid > 0 && $resolvedUid !== $userId) {
    $userId = $resolvedUid;
    $user = org_admin_get_public_user($dbh, $userId);
    if (!$user) {
        header('Location: account_search.php');
        exit;
    }
    $isPublisher = strtolower(trim((string)($user['account_kind'] ?? ''))) === 'publisher';
    $followerCount = $isPublisher ? org_admin_user_follower_count($dbh, $userId) : 0;
    $friendCount = !$isPublisher ? org_admin_user_friend_count($dbh, $userId) : 0;
    $followingCount = 0;
    if (msb_mod_table_exists($dbh, 'public_follows')) {
        $followingCount = msb_mod_count_safe($dbh, 'SELECT COUNT(*) FROM public_follows WHERE follower_id = :uid', [':uid' => $userId]);
    }
}

$activity = $bundle['activity'];
$behavior = $bundle['behavior'];
$savedMod = $bundle['saved_mod'];

$posts = $bundle['posts'];
$focusPost = $bundle['focus'];
$postIndex = (int)$bundle['post_index'];
$postTotal = (int)$bundle['post_total'];
$postPos = $postTotal > 0 ? ($postIndex + 1) : 0;
$prevPost = $bundle['prev'];
$nextPost = $bundle['next'];
$focusPostId = (int)(is_array($focusPost) ? ($focusPost['id'] ?? $focusPostId) : $focusPostId);

$reportsOnPost = $bundle['reports_on_post'];
$timeline = $bundle['timeline'];

$reportsFiled = [];
$reportsAbout = [];
try {
    msb_reports_ensure_schema($dbh);
    $reportsFiled = msb_reports_list_for_reporter($dbh, $userId, 40);
    $reportsAbout = msb_reports_list_about_user($dbh, $userId, 40);
} catch (Throwable $eReports) {
    $reportsFiled = [];
    $reportsAbout = [];
}

$displayName = trim((string)($user['name'] ?? ''));
$username = trim((string)($user['username'] ?? ''));
if (is_array($focusPost)) {
    if (trim((string)($focusPost['username'] ?? '')) !== '') {
        $username = trim((string)$focusPost['username']);
    }
    if (trim((string)($focusPost['name'] ?? '')) !== '') {
        $displayName = trim((string)$focusPost['name']);
    }
}
$friendCode = trim((string)($user['friend_code'] ?? ''));
$profileUrl = org_admin_public_profile_url($userId, $username, $friendCode);
$initial = mb_strtoupper(mb_substr($username !== '' ? $username : ($displayName !== '' ? $displayName : 'U'), 0, 1));

// Consistent risk / decision (one source of truth for the whole right column).
$suggestedTier = strtolower(trim((string)($behavior['tier'] ?? 'normal')));
$savedTier = strtolower(trim((string)($savedMod['status'] ?? '')));
$effectiveTier = $savedTier !== '' ? $savedTier : $suggestedTier;
if (!in_array($effectiveTier, ['normal', 'review', 'high_risk'], true)) {
    $effectiveTier = 'normal';
}
$decisionTier = $effectiveTier;
$decisionNote = (string)($savedMod['note'] ?? '');

// Post-level status (for "Reports on This Post" context only).
$postPendingCount = (int)(is_array($focusPost) ? ($focusPost['pending_count'] ?? 0) : 0);
$postReportCount = (int)(is_array($focusPost) ? ($focusPost['report_count'] ?? 0) : 0);
if ($postPendingCount <= 0 && !empty($reportsOnPost)) {
    foreach ($reportsOnPost as $rp) {
        if (strtolower((string)($rp['status'] ?? '')) === 'pending') {
            $postPendingCount++;
        }
    }
    $postReportCount = max($postReportCount, count($reportsOnPost));
}

$userReportsTotal = (int)($activity['reports_about_total'] ?? 0);
$userReportsPending = (int)($activity['reports_about_pending'] ?? 0);

$riskScore100 = min(100, max(0, (int)($behavior['score'] ?? 0) * 12));
if ($effectiveTier === 'high_risk') {
    $riskScore100 = max($riskScore100, 70);
} elseif ($effectiveTier === 'review') {
    $riskScore100 = max($riskScore100, 40);
}
$riskTone = $effectiveTier === 'high_risk' ? 'high' : ($effectiveTier === 'review' ? 'mid' : 'low');
$riskLabel = $effectiveTier === 'high_risk' ? 'High Risk' : ($effectiveTier === 'review' ? 'Review' : 'Normal');
$riskSub = ($savedTier !== '' ? 'Saved decision' : 'Suggested') . ' · ' . (int)$riskScore100 . '/100';
if ($postPendingCount > 0) {
    $riskSub .= ' · ' . $postPendingCount . ' pending on this post';
} elseif ($userReportsPending > 0) {
    $riskSub .= ' · ' . $userReportsPending . ' pending about user';
}

$mediaUrl = static function (string $path): string {
    $path = trim($path);
    if ($path === '') {
        return '';
    }
    if (preg_match('~^https?://~i', $path)) {
        return $path;
    }
    if ($path[0] === '/') {
        return '..' . $path;
    }
    return '../public_user/' . ltrim($path, '/');
};

$postTypeLabel = (string)(is_array($focusPost) ? ($focusPost['post_type'] ?? 'Text') : 'Text');
$postTypeIcon = 'fa-file-text-o';
if ($postTypeLabel === 'Video') {
    $postTypeIcon = 'fa-film';
} elseif ($postTypeLabel === 'Image') {
    $postTypeIcon = 'fa-image';
} elseif ($postTypeLabel === 'Link') {
    $postTypeIcon = 'fa-link';
}
$postThumb = $mediaUrl((string)(is_array($focusPost) ? ($focusPost['thumb'] ?? '') : ''));
$postMediaSrc = '';
$atts = is_array($focusPost) ? ($focusPost['attachments'] ?? []) : [];
foreach ($atts as $a) {
    $fp = (string)($a['file_path'] ?? '');
    $tp = (string)($a['thumb_path'] ?? '');
    $t = strtolower(trim((string)($a['type'] ?? '')));
    if ($fp === '' && $tp === '') {
        continue;
    }
    if ($postMediaSrc === '' && $fp !== '') {
        $postMediaSrc = $mediaUrl($fp);
    }
    if ($postThumb === '') {
        $postThumb = $mediaUrl($tp !== '' ? $tp : $fp);
    }
    if ($t === 'video' || stripos($fp, '.mp4') !== false || stripos($fp, '.mov') !== false) {
        $postTypeLabel = 'Video';
        $postTypeIcon = 'fa-film';
        break;
    }
}

$vis = strtolower(trim((string)(is_array($focusPost) ? ($focusPost['visibility'] ?? 'public') : 'public')));
$visIcon = $vis === 'friends' ? 'fa-users' : ($vis === 'private' ? 'fa-lock' : 'fa-globe');
$postText = is_array($focusPost) ? trim((string)($focusPost['text_preview'] ?? '')) : '';
$isNewPost = false;
if (is_array($focusPost) && !empty($focusPost['created_at'])) {
    $cts = strtotime((string)$focusPost['created_at']);
    $isNewPost = $cts && (time() - $cts) < 86400;
}

$a7 = is_array($focusPost) ? ($focusPost['activity_7d'] ?? []) : [];
if ($a7 === []) {
    $a7 = [
        'posts_7d' => (int)$activity['posts_7d'],
        'likes_7d' => (int)$activity['likes_given_7d'],
        'comments_7d' => (int)$activity['comments_given_7d'],
        'shares_7d' => (int)$activity['shares_given_7d'],
    ];
}

$avgPostsHint = max(1, (int)round(max(1, (int)($a7['posts_7d'] ?? $activity['posts_7d'])) / 7));
$deltaPct = static function (int $value, int $avg): array {
    if ($value <= 0) {
        return [0, 'flat'];
    }
    if ($avg <= 0) {
        return [100, 'up'];
    }
    // Avoid fake "↑ 100%" when comparing a value only to itself.
    if ($avg === $value) {
        return [0, 'flat'];
    }
    $pct = (int)round((($value - $avg) / $avg) * 100);
    if ($pct > 0) {
        return [$pct, 'up'];
    }
    if ($pct < 0) {
        return [abs($pct), 'down'];
    }
    return [0, 'flat'];
};

org_admin_render_head('User Activity · ' . ($displayName !== '' ? $displayName : $username));
?>
<?php
require_once __DIR__ . '/includes/admin_chrome.php';
admin_chrome_open('User Activity');
?>
<script>
(function () {
  // Legacy table links used #post-123 without ?post_id=
  var m = (location.hash || '').match(/^#post-(\d+)$/i);
  if (!m) return;
  var sp = new URLSearchParams(location.search);
  if (sp.get('post_id')) return;
  sp.set('post_id', m[1]);
  location.replace(location.pathname + '?' + sp.toString());
})();
</script>

<style>
  /* Fit entire overview in viewport — no page scroll */
  .sh-mainpanel > .sh-pagebody{
    overflow:hidden !important;
    display:flex !important;
    flex-direction:column !important;
    min-height:0 !important;
    padding-top:10px !important;
    padding-bottom:10px !important;
  }
  .uao-wrap{
    flex:1 1 auto;min-height:0;height:100%;
    display:flex;flex-direction:column;gap:10px;
    overflow:hidden;padding:0 2px;box-sizing:border-box;
  }
  .uao-top{
    flex:0 0 auto;
    display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;
  }
  .uao-top h1{margin:0;font-size:20px;font-weight:800;color:#0f172a;letter-spacing:-.02em;line-height:1.15;}
  .uao-top p{margin:2px 0 0;font-size:12px;color:#64748b;}
  .uao-top-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center;}
  .uao-btn{
    height:32px;padding:0 11px;border-radius:8px;border:1px solid #e2e8f0;background:#fff;
    font-size:12px;font-weight:700;color:#334155;display:inline-flex;align-items:center;gap:6px;
    text-decoration:none;cursor:pointer;white-space:nowrap;
  }
  .uao-btn:hover{background:#f8fafc;text-decoration:none;color:#0f172a;}
  .uao-btn.primary{background:#2563eb;border-color:#2563eb;color:#fff;}
  .uao-btn.primary:hover{background:#1d4ed8;color:#fff;}
  .uao-btn.is-disabled{opacity:.45;pointer-events:none;}
  .uao-btn.sm{height:28px;padding:0 9px;font-size:11px;}
  .uao-nav-count{font-size:12px;font-weight:700;color:#64748b;min-width:52px;text-align:center;}

  .uao-board{
    flex:1 1 auto;min-height:0;
    display:grid;
    grid-template-columns:minmax(240px,1fr) minmax(0,1.55fr) minmax(240px,1fr);
    gap:10px;overflow:hidden;
  }
  .uao-col{
    min-height:0;min-width:0;
    display:flex;flex-direction:column;gap:10px;overflow:hidden;
  }

  .uao-card{
    background:#fff;border:1px solid #eef2f7;border-radius:12px;
    box-shadow:0 1px 2px rgba(15,23,42,.04);
    display:flex;flex-direction:column;min-height:0;overflow:hidden;
  }
  .uao-card.grow{flex:1 1 auto;}
  .uao-card.shrink{flex:0 0 auto;}
  .uao-card.mid{flex:0 1 auto;}
  .uao-card-hd{
    flex:0 0 auto;
    display:flex;align-items:center;justify-content:space-between;gap:8px;
    padding:9px 12px;border-bottom:1px solid #f1f5f9;
  }
  .uao-card-hd h2{margin:0;font-size:13px;font-weight:800;color:#0f172a;}
  .uao-card-bd{
    flex:1 1 auto;min-height:0;padding:10px 12px;
    overflow:hidden;display:flex;flex-direction:column;
  }
  .uao-card-bd.scroll{overflow:auto;overscroll-behavior:contain;}
  .uao-badge{
    display:inline-flex;align-items:center;padding:2px 7px;border-radius:999px;
    font-size:10px;font-weight:800;
  }
  .uao-badge.new{background:#dcfce7;color:#15803d;}
  .uao-badge.warn{background:#ffedd5;color:#c2410c;}
  .uao-badge.bad{background:#fee2e2;color:#b91c1c;}

  .uao-meta{display:flex;flex-direction:column;gap:6px;margin-bottom:8px;flex:0 0 auto;}
  .uao-meta-row{display:flex;align-items:flex-start;justify-content:space-between;gap:8px;font-size:11px;}
  .uao-meta-row .k{color:#64748b;font-weight:700;}
  .uao-meta-row .v{color:#0f172a;font-weight:700;text-align:right;}
  .uao-user-mini{display:inline-flex;align-items:center;gap:6px;}
  .uao-av{
    width:24px;height:24px;border-radius:999px;background:#dbeafe;color:#1d4ed8;
    display:inline-flex;align-items:center;justify-content:center;font-size:10px;font-weight:800;flex:0 0 auto;
  }
  .uao-copy{border:0;background:transparent;color:#94a3b8;cursor:pointer;padding:0 0 0 3px;}
  .uao-post-body{
    font-size:12px;line-height:1.35;color:#334155;margin:0 0 8px;
    display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;flex:0 0 auto;
  }
  .uao-post-media{
    flex:1 1 auto;min-height:72px;max-height:160px;border-radius:10px;overflow:hidden;
    background:#f1f5f9;border:1px solid #eef2f7;display:flex;align-items:center;justify-content:center;
  }
  .uao-post-media img,.uao-post-media video{width:100%;height:100%;object-fit:cover;display:block;}
  .uao-post-media .ph{color:#94a3b8;font-size:22px;}
  .uao-edit-note{
    flex:0 0 auto;margin-top:8px;padding-top:8px;border-top:1px solid #f1f5f9;
    font-size:11px;color:#64748b;line-height:1.35;
  }
  .uao-edit-note strong{color:#0f172a;}

  .uao-summary-stats{
    display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:8px;margin-bottom:8px;flex:0 0 auto;
  }
  .uao-stat{padding:8px;border:1px solid #f1f5f9;border-radius:10px;background:#fafbfc;}
  .uao-stat-ico{
    width:24px;height:24px;border-radius:999px;display:flex;align-items:center;justify-content:center;
    font-size:11px;margin-bottom:5px;
  }
  .uao-stat-ico.purple{background:#f5f3ff;color:#7c3aed;}
  .uao-stat-ico.blue{background:#eff6ff;color:#2563eb;}
  .uao-stat-ico.green{background:#f0fdf4;color:#16a34a;}
  .uao-stat-ico.orange{background:#fff7ed;color:#ea580c;}
  .uao-stat-ico.cyan{background:#ecfeff;color:#0891b2;}
  .uao-stat .lab{font-size:10px;color:#64748b;font-weight:700;}
  .uao-stat .val{font-size:18px;font-weight:800;color:#0f172a;line-height:1.05;margin-top:1px;}
  .uao-stat .delta{font-size:10px;font-weight:800;margin-top:2px;}
  .uao-stat .delta.up{color:#dc2626;}
  .uao-stat .delta.down{color:#16a34a;}
  .uao-stat .delta.flat{color:#94a3b8;}

  .uao-account-row{
    display:grid;grid-template-columns:minmax(0,0.9fr) minmax(0,1.4fr) minmax(0,1fr) minmax(0,0.8fr) minmax(0,0.8fr);gap:10px;
    border-top:1px solid #f1f5f9;padding-top:8px;flex:0 0 auto;
  }
  .uao-acc{
    display:flex;align-items:flex-start;gap:6px;font-size:11px;color:#475569;
    min-width:0;overflow:hidden;
  }
  .uao-acc > div{min-width:0;overflow:hidden;}
  .uao-acc i{color:#94a3b8;margin-top:2px;flex:0 0 auto;}
  .uao-acc span{display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
  .uao-acc strong{
    display:block;color:#0f172a;font-size:12px;font-weight:800;
    white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:100%;
  }

  .uao-timeline{position:relative;padding-left:16px;flex:1 1 auto;min-height:0;overflow:hidden;}
  .uao-timeline::before{
    content:'';position:absolute;left:5px;top:2px;bottom:2px;width:2px;background:#e2e8f0;
  }
  .uao-tl-item{position:relative;padding:0 0 10px 8px;}
  .uao-tl-item:last-child{padding-bottom:0;}
  .uao-tl-dot{
    position:absolute;left:-15px;top:1px;width:12px;height:12px;border-radius:999px;
    background:#fff;border:2px solid #cbd5e1;display:flex;align-items:center;justify-content:center;
    font-size:6px;color:#64748b;
  }
  .uao-tl-dot.pink{border-color:#f9a8d4;color:#db2777;}
  .uao-tl-dot.blue{border-color:#93c5fd;color:#2563eb;}
  .uao-tl-dot.teal{border-color:#5eead4;color:#0d9488;}
  .uao-tl-dot.purple{border-color:#c4b5fd;color:#7c3aed;}
  .uao-tl-dot.orange{border-color:#fdba74;color:#ea580c;}
  .uao-tl-dot.green{border-color:#86efac;color:#16a34a;}
  .uao-tl-dot.slate{border-color:#cbd5e1;color:#475569;}
  .uao-tl-when{font-size:10px;color:#94a3b8;font-weight:700;margin-bottom:1px;}
  .uao-tl-row{display:flex;justify-content:space-between;gap:8px;align-items:flex-start;}
  .uao-tl-text{font-size:12px;font-weight:700;color:#0f172a;}
  .uao-tl-meta{font-size:10px;font-weight:700;color:#64748b;white-space:nowrap;}
  .uao-tl-meta a{color:#2563eb;text-decoration:none;}

  .uao-flags{display:flex;flex-direction:column;gap:6px;margin-bottom:8px;flex:1 1 auto;min-height:0;overflow:hidden;}
  .uao-flag{display:flex;align-items:flex-start;gap:7px;font-size:11px;font-weight:700;color:#334155;}
  .uao-flag i{margin-top:3px;font-size:7px;}
  .uao-flag.high i{color:#dc2626;}
  .uao-flag.mid i{color:#ea580c;}
  .uao-flag.ok i{color:#16a34a;}
  .uao-risk-box{
    flex:0 0 auto;border-radius:10px;padding:10px;border:1px solid #fecaca;background:#fef2f2;
  }
  .uao-risk-box.mid{border-color:#fed7aa;background:#fff7ed;}
  .uao-risk-box.low{border-color:#bbf7d0;background:#f0fdf4;}
  .uao-risk-top{display:flex;align-items:center;gap:8px;margin-bottom:8px;}
  .uao-risk-ico{
    width:30px;height:30px;border-radius:9px;display:flex;align-items:center;justify-content:center;
    background:#fee2e2;color:#dc2626;font-size:14px;
  }
  .uao-risk-box.mid .uao-risk-ico{background:#ffedd5;color:#ea580c;}
  .uao-risk-box.low .uao-risk-ico{background:#dcfce7;color:#16a34a;}
  .uao-risk-top strong{display:block;font-size:13px;color:#0f172a;}
  .uao-risk-top span{font-size:11px;color:#64748b;font-weight:700;}

  .uao-mini-table{width:100%;border-collapse:collapse;font-size:11px;}
  .uao-mini-table th{
    text-align:left;font-size:9px;text-transform:uppercase;letter-spacing:.04em;
    color:#94a3b8;font-weight:800;padding:0 0 6px;border-bottom:1px solid #f1f5f9;
  }
  .uao-mini-table td{padding:6px 0;border-bottom:1px solid #f8fafc;vertical-align:middle;color:#334155;}
  .uao-mini-table a{color:#2563eb;font-weight:700;text-decoration:none;}
  .uao-empty{text-align:center;padding:14px 8px;color:#64748b;font-size:12px;}
  .uao-empty .ico{
    width:36px;height:36px;border-radius:999px;margin:0 auto 8px;
    background:#f0fdf4;color:#16a34a;display:flex;align-items:center;justify-content:center;font-size:15px;
  }
  .uao-empty .title{font-size:13px;font-weight:800;color:#0f172a;margin-bottom:2px;}
  .uao-form{display:flex;flex-direction:column;min-height:0;height:100%;}
  .uao-form label{display:block;font-size:10px;font-weight:800;color:#64748b;margin:0 0 4px;}
  .uao-form select,.uao-form textarea{
    width:100%;border:1px solid #e2e8f0;border-radius:8px;padding:7px 9px;
    font-size:12px;color:#0f172a;background:#fff;margin-bottom:8px;
  }
  .uao-form textarea{flex:1 1 auto;min-height:54px;max-height:90px;resize:none;}
  .uao-form .uao-btn.primary{width:100%;justify-content:center;height:36px;margin-top:auto;}
  .uao-alert{flex:0 0 auto;margin:0;padding:7px 10px;border-radius:8px;font-size:12px;font-weight:700;}
  .uao-alert.ok{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0;}
  .uao-alert.bad{background:#fef2f2;color:#991b1b;border:1px solid #fecaca;}

  @media (max-width:1100px){
    .uao-board{grid-template-columns:1fr 1fr;}
    .uao-col-right{grid-column:1 / -1;flex-direction:row;}
    .uao-col-right > .uao-card{flex:1 1 0;}
    .uao-account-row{grid-template-columns:repeat(3,minmax(0,1fr));}
  }
  @media (max-width:780px){
    .uao-wrap{overflow:auto;}
    .uao-board{grid-template-columns:1fr;height:auto;overflow:visible;}
    .uao-col,.uao-card{overflow:visible;max-height:none;}
  }
</style>

<div class="sh-mainpanel">
  <div class="sh-pagebody">
    <div class="uao-wrap">

      <?php if ($msg !== ''): ?><div class="uao-alert ok"><?= org_admin_h($msg) ?></div><?php endif; ?>
      <?php if ($error !== ''): ?><div class="uao-alert bad"><?= org_admin_h($error) ?></div><?php endif; ?>

      <div class="uao-top">
        <div>
          <h1>User Activity Overview</h1>
          <p>Review user behavior and activity<?= $focusPostId > 0 ? ' around this post' : ' for this account' ?>.</p>
        </div>
        <div class="uao-top-actions">
          <?php if ($prevPost): ?>
            <a class="uao-btn" href="user_activity.php?user_id=<?= $userId ?>&amp;post_id=<?= (int)$prevPost['id'] ?>"><i class="fa fa-angle-left"></i> Previous</a>
          <?php else: ?>
            <span class="uao-btn is-disabled"><i class="fa fa-angle-left"></i> Previous</span>
          <?php endif; ?>
          <span class="uao-nav-count"><?= (int)$postPos ?> of <?= (int)$postTotal ?></span>
          <?php if ($nextPost): ?>
            <a class="uao-btn" href="user_activity.php?user_id=<?= $userId ?>&amp;post_id=<?= (int)$nextPost['id'] ?>">Next <i class="fa fa-angle-right"></i></a>
          <?php else: ?>
            <span class="uao-btn is-disabled">Next <i class="fa fa-angle-right"></i></span>
          <?php endif; ?>
          <a class="uao-btn primary" href="<?= org_admin_h($profileUrl) ?>" target="_blank" rel="noopener"><i class="fa fa-user"></i> View User Profile</a>
        </div>
      </div>

      <?php
        $likes7 = (int)($a7['likes_7d'] ?? $activity['likes_given_7d']);
        $comments7 = (int)($a7['comments_7d'] ?? $activity['comments_given_7d']);
        $posts7 = (int)($a7['posts_7d'] ?? $activity['posts_7d']);
        $shares7 = (int)($a7['shares_7d'] ?? $activity['shares_given_7d']);
        // Keep summary Reports in sync with Behavior Indicators (user-level).
        $summaryReports = $userReportsTotal;
        $summaryPending = $userReportsPending;
        [$dPosts, $cPosts] = $deltaPct($posts7, max(1, $avgPostsHint));
        [$dLikes, $cLikes] = $deltaPct($likes7, max(1, max($likes7, 1)));
        [$dComm, $cComm] = $deltaPct($comments7, max(1, max($comments7, 1)));
        [$dShare, $cShare] = $deltaPct($shares7, max(1, max($shares7, 1)));
        $deltaHtml = static function (int $pct, string $cls): string {
            if ($cls === 'flat' || $pct === 0) {
                return '<div class="delta flat">—</div>';
            }
            $arrow = $cls === 'down' ? '↓' : '↑';
            return '<div class="delta ' . htmlspecialchars($cls, ENT_QUOTES, 'UTF-8') . '">' . $arrow . ' ' . (int)$pct . '%</div>';
        };
      ?>

      <div class="uao-board">

        <!-- LEFT -->
        <div class="uao-col uao-col-left">
          <section class="uao-card grow" id="post-<?= (int)$focusPostId ?>">
            <div class="uao-card-hd">
              <h2>New Post Under Review</h2>
              <?php if ($isNewPost): ?><span class="uao-badge new">New</span><?php endif; ?>
            </div>
            <div class="uao-card-bd">
              <?php if (!$focusPost): ?>
                <div class="uao-empty">No posts to review yet.</div>
              <?php else: ?>
                <div class="uao-meta">
                  <div class="uao-meta-row">
                    <span class="k">Post ID</span>
                    <span class="v">#<?= (int)$focusPost['id'] ?>
                      <button type="button" class="uao-copy" title="Copy" onclick="navigator.clipboard.writeText('#<?= (int)$focusPost['id'] ?>')"><i class="fa fa-clone"></i></button>
                    </span>
                  </div>
                  <div class="uao-meta-row">
                    <span class="k">Posted by</span>
                    <span class="v">
                      <span class="uao-user-mini">
                        <span class="uao-av"><?= org_admin_h($initial) ?></span>
                        <span>@<?= org_admin_h($username !== '' ? $username : ('user' . $userId)) ?></span>
                      </span>
                    </span>
                  </div>
                  <div class="uao-meta-row">
                    <span class="k">Date &amp; Time</span>
                    <span class="v"><?= org_admin_h(org_admin_fmt_dt($focusPost['created_at'] ?? '')) ?></span>
                  </div>
                  <div class="uao-meta-row">
                    <span class="k">Post Type</span>
                    <span class="v"><i class="fa <?= $postTypeIcon ?>"></i> <?= org_admin_h($postTypeLabel) ?></span>
                  </div>
                  <div class="uao-meta-row">
                    <span class="k">Visibility</span>
                    <span class="v"><i class="fa <?= $visIcon ?>"></i> <?= org_admin_h(ucfirst($vis)) ?></span>
                  </div>
                </div>
                <?php if ($postText !== ''): ?>
                  <p class="uao-post-body"><?= org_admin_h($postText) ?></p>
                <?php endif; ?>
                <div class="uao-post-media">
                  <?php if ($postMediaSrc !== '' && $postTypeLabel === 'Video'): ?>
                    <video src="<?= org_admin_h($postMediaSrc) ?>" muted playsinline></video>
                  <?php elseif ($postThumb !== '' || $postMediaSrc !== ''): ?>
                    <img src="<?= org_admin_h($postThumb !== '' ? $postThumb : $postMediaSrc) ?>" alt="">
                  <?php else: ?>
                    <div class="ph"><i class="fa fa-align-left"></i></div>
                  <?php endif; ?>
                </div>
                <?php if (!empty($focusPost['was_edited'])): ?>
                  <div class="uao-edit-note">
                    <strong>Edit History</strong><br>
                    Edited <?= org_admin_h(org_admin_fmt_dt($focusPost['updated_at'] ?? '')) ?> · Content updated
                  </div>
                <?php endif; ?>
              <?php endif; ?>
            </div>
          </section>

          <section class="uao-card mid" id="uao-reports" style="flex:0 0 34%;">
            <div class="uao-card-hd">
              <h2>Reports on This Post</h2>
              <a class="uao-btn sm" href="reports.php">View All Reports</a>
            </div>
            <div class="uao-card-bd scroll">
              <?php if (!$reportsOnPost): ?>
                <div class="uao-empty" style="padding:10px 4px;">
                  No reports on this post.
                  <?php if ($userReportsPending > 0 || $userReportsTotal > 0): ?>
                    <div style="margin-top:6px;font-size:11px;">
                      <?= (int)$userReportsTotal ?> report<?= $userReportsTotal === 1 ? '' : 's' ?> about this user
                      (<?= (int)$userReportsPending ?> pending).
                    </div>
                  <?php endif; ?>
                </div>
              <?php else: ?>
                <table class="uao-mini-table">
                  <thead>
                    <tr>
                      <th>Report ID</th>
                      <th>Reason</th>
                      <th>Reported By</th>
                      <th>Time</th>
                    </tr>
                  </thead>
                  <tbody>
                  <?php foreach (array_slice($reportsOnPost, 0, 4) as $rep): ?>
                    <?php
                      $rid = (int)($rep['id'] ?? 0);
                      $rShow = trim((string)($rep['reporter_label'] ?? ''));
                      if ($rShow === '') $rShow = trim((string)($rep['reporter_username'] ?? ''));
                      if ($rShow === '') $rShow = 'User #' . (int)($rep['reporter_id'] ?? 0);
                    ?>
                    <tr>
                      <td><a href="report_detail.php?id=<?= $rid ?>">#<?= $rid ?></a></td>
                      <td><?= org_admin_h((string)($rep['reason'] ?? 'other')) ?></td>
                      <td><a href="report_detail.php?id=<?= $rid ?>"><?= org_admin_h($rShow) ?></a></td>
                      <td><?= org_admin_h(org_admin_fmt_dt($rep['created_at'] ?? '')) ?></td>
                    </tr>
                  <?php endforeach; ?>
                  </tbody>
                </table>
              <?php endif; ?>
            </div>
          </section>
        </div>

        <!-- MIDDLE -->
        <div class="uao-col uao-col-mid">
          <section class="uao-card shrink">
            <div class="uao-card-hd"><h2>Activity Summary</h2></div>
            <div class="uao-card-bd" style="overflow:hidden;">
              <div class="uao-summary-stats">
                <div class="uao-stat">
                  <div class="uao-stat-ico purple"><i class="fa fa-pencil"></i></div>
                  <div class="lab">Posts (7d)</div>
                  <div class="val"><?= $posts7 ?></div>
                  <div class="delta <?= ((int)$activity['posts_24h'] > 0) ? 'up' : 'flat' ?>"><?= (int)$activity['posts_24h'] ?> in 24h</div>
                </div>
                <div class="uao-stat">
                  <div class="uao-stat-ico blue"><i class="fa fa-heart"></i></div>
                  <div class="lab">Likes (7d)</div>
                  <div class="val"><?= $likes7 ?></div>
                  <?= $deltaHtml($dLikes, $cLikes) ?>
                </div>
                <div class="uao-stat">
                  <div class="uao-stat-ico green"><i class="fa fa-comment"></i></div>
                  <div class="lab">Comments (7d)</div>
                  <div class="val"><?= $comments7 ?></div>
                  <?= $deltaHtml($dComm, $cComm) ?>
                </div>
                <div class="uao-stat">
                  <div class="uao-stat-ico orange"><i class="fa fa-share"></i></div>
                  <div class="lab">Shares (7d)</div>
                  <div class="val"><?= $shares7 ?></div>
                  <?= $deltaHtml($dShare, $cShare) ?>
                </div>
                <div class="uao-stat">
                  <div class="uao-stat-ico cyan"><i class="fa fa-flag"></i></div>
                  <div class="lab">Reports</div>
                  <div class="val"><?= $summaryReports ?></div>
                  <div class="delta <?= $summaryPending > 0 ? 'up' : 'flat' ?>"><?= $summaryPending ?> pending<?= $postReportCount > 0 ? ' · ' . $postReportCount . ' on post' : '' ?></div>
                </div>
              </div>
              <div class="uao-account-row">
                <div class="uao-acc">
                  <i class="fa fa-calendar"></i>
                  <div><span>Account Age</span><strong><?= (int)$activity['account_age_days'] ?>d</strong></div>
                </div>
                <div class="uao-acc">
                  <i class="fa fa-clock-o"></i>
                  <div>
                    <span>Last Login</span>
                    <strong title="<?= !empty($activity['last_login_at']) ? org_admin_h(org_admin_fmt_dt($activity['last_login_at'])) : '' ?>">
                      <?php
                        if (!empty($activity['last_login_at'])) {
                            $loginTs = strtotime((string)$activity['last_login_at']);
                            echo org_admin_h($loginTs ? date('M j, g:i A', $loginTs) : org_admin_fmt_dt($activity['last_login_at']));
                        } else {
                            echo '—';
                        }
                      ?>
                    </strong>
                  </div>
                </div>
                <div class="uao-acc">
                  <i class="fa fa-check-circle"></i>
                  <div><span>Verification</span><strong><?= (int)($user['status'] ?? 0) === 1 ? 'Active' : 'Not Verified' ?></strong></div>
                </div>
                <div class="uao-acc">
                  <i class="fa fa-users"></i>
                  <div><span><?= $isPublisher ? 'Followers' : 'Friends' ?></span><strong><?= $isPublisher ? (int)$followerCount : (int)$friendCount ?></strong></div>
                </div>
                <div class="uao-acc">
                  <i class="fa fa-user"></i>
                  <div><span>Following</span><strong><?= (int)$followingCount ?></strong></div>
                </div>
              </div>
            </div>
          </section>

          <section class="uao-card grow">
            <div class="uao-card-hd">
              <h2>Recent Activity Timeline</h2>
              <a class="uao-btn sm" href="user_activity_table.php?q=<?= rawurlencode($username !== '' ? $username : (string)$userId) ?>">View All Activity</a>
            </div>
            <div class="uao-card-bd">
              <?php if (!$timeline): ?>
                <div class="uao-empty">No recent activity signals yet.</div>
              <?php else: ?>
                <div class="uao-timeline">
                  <?php foreach (array_slice($timeline, 0, 6) as $ev): ?>
                    <div class="uao-tl-item">
                      <div class="uao-tl-dot <?= org_admin_h((string)$ev['tone']) ?>"><i class="fa <?= org_admin_h((string)$ev['icon']) ?>"></i></div>
                      <div class="uao-tl-when"><?= org_admin_h((string)$ev['when']) ?></div>
                      <div class="uao-tl-row">
                        <div class="uao-tl-text"><?= org_admin_h((string)$ev['text']) ?></div>
                        <div class="uao-tl-meta">
                          <?php if (!empty($ev['href'])): ?>
                            <a href="<?= org_admin_h((string)$ev['href']) ?>"><?= org_admin_h((string)$ev['meta']) ?></a>
                          <?php else: ?>
                            <?= org_admin_h((string)$ev['meta']) ?>
                          <?php endif; ?>
                        </div>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </div>
          </section>

          <section class="uao-card shrink" style="flex:0 0 22%;">
            <div class="uao-card-hd">
              <h2>Previous Moderation Actions</h2>
              <a class="uao-btn sm" href="user_form.php?user_id=<?= $userId ?>">View All</a>
            </div>
            <div class="uao-card-bd">
              <?php if (!$savedMod): ?>
                <div class="uao-empty" style="padding:8px;">
                  <div class="ico"><i class="fa fa-shield"></i></div>
                  <div class="title">No prior actions</div>
                  This user has no previous moderation actions.
                </div>
              <?php else: ?>
                <table class="uao-mini-table">
                  <thead><tr><th>Status</th><th>Note</th><th>When</th></tr></thead>
                  <tbody>
                    <tr>
                      <td><span class="uao-badge <?= $savedMod['status'] === 'high_risk' ? 'bad' : ($savedMod['status'] === 'review' ? 'warn' : 'new') ?>"><?= org_admin_h(ucwords(str_replace('_', ' ', (string)$savedMod['status']))) ?></span></td>
                      <td><?= org_admin_h((string)($savedMod['note'] ?? '') !== '' ? (string)$savedMod['note'] : '—') ?></td>
                      <td><?= org_admin_h(org_admin_fmt_dt($savedMod['updated_at'] ?? '')) ?></td>
                    </tr>
                  </tbody>
                </table>
              <?php endif; ?>
            </div>
          </section>
        </div>

        <!-- RIGHT -->
        <div class="uao-col uao-col-right">
          <section class="uao-card grow">
            <div class="uao-card-hd"><h2>Behavior Indicators</h2></div>
            <div class="uao-card-bd">
              <div class="uao-flags">
                <?php foreach (array_slice($behavior['flags'], 0, 6) as $flag): ?>
                  <?php $lvl = (string)($flag['level'] ?? 'ok'); ?>
                  <div class="uao-flag <?= org_admin_h($lvl) ?>">
                    <i class="fa fa-circle"></i>
                    <span><?= org_admin_h((string)($flag['label'] ?? '')) ?></span>
                  </div>
                <?php endforeach; ?>
              </div>
              <div class="uao-risk-box <?= org_admin_h($riskTone) ?>">
                <div class="uao-risk-top">
                  <div class="uao-risk-ico"><i class="fa fa-shield"></i></div>
                  <div>
                    <strong><?= org_admin_h($riskLabel) ?></strong>
                    <span><?= org_admin_h($riskSub) ?></span>
                  </div>
                </div>
                <a class="uao-btn sm" href="#uao-reports" style="width:100%;justify-content:center;">View Evidence Details</a>
              </div>
            </div>
          </section>

          <section class="uao-card shrink" style="flex:0 0 42%;">
            <div class="uao-card-hd"><h2>Moderator Decision</h2></div>
            <div class="uao-card-bd">
              <form class="uao-form" method="post">
                <input type="hidden" name="post_id" value="<?= (int)$focusPostId ?>">
                <label for="mod_status">Select an action</label>
                <select id="mod_status" name="mod_status" required>
                  <option value="">Choose an action...</option>
                  <option value="normal"<?= $decisionTier === 'normal' ? ' selected' : '' ?>>Mark Normal</option>
                  <option value="review"<?= $decisionTier === 'review' ? ' selected' : '' ?>>Mark Review</option>
                  <option value="high_risk"<?= $decisionTier === 'high_risk' ? ' selected' : '' ?>>Mark High Risk</option>
                </select>
                <label for="mod_note">Add notes (optional)</label>
                <textarea id="mod_note" name="mod_note" placeholder="Enter any notes about your decision..."><?= org_admin_h($decisionNote) ?></textarea>
                <button type="submit" class="uao-btn primary">Submit Decision</button>
              </form>
            </div>
          </section>
        </div>

      </div>
    </div>
  </div>
</div>
<?php org_admin_render_foot(); ?>
