<?php
declare(strict_types=1);

/**
 * Admin — Report Details (content moderation).
 * Viewport-fit layout matching Report Details mockup (no page scroll).
 */
require_once __DIR__ . '/includes/org_admin_helpers_load.php';
require_once __DIR__ . '/../public_user/includes/msb_reports.php';
require_once __DIR__ . '/includes/msb_moderation_activity.php';
require_once __DIR__ . '/includes/msb_reports_admin_ui.php';
org_admin_require_admin();

error_reporting(E_ALL);
ini_set('display_errors', '1');

if (!function_exists('msb_reports_get_by_id')) {
    function msb_reports_get_by_id(PDO $dbh, int $reportId): ?array
    {
        if (function_exists('msb_reports_ensure_schema')) {
            msb_reports_ensure_schema($dbh);
        }
        if ($reportId <= 0) {
            return null;
        }
        try {
            $st = $dbh->prepare('
                SELECT
                    r.*,
                    ru.username AS reporter_username,
                    ru.name AS reporter_name,
                    ru.email AS reporter_email,
                    ru.friend_code AS reporter_code,
                    ru.created_at AS reporter_created_at,
                    tu.username AS target_username,
                    tu.name AS target_name,
                    tu.email AS target_email,
                    tu.friend_code AS target_code,
                    tu.status AS target_status,
                    tu.created_at AS target_created_at,
                    o.name AS target_org_name,
                    o.org_code AS target_org_code
                FROM public_user_reports r
                LEFT JOIN users ru ON ru.id = r.reporter_id
                LEFT JOIN users tu ON tu.id = r.target_user_id
                LEFT JOIN organizations o ON o.id = r.target_org_id
                WHERE r.id = :id
                LIMIT 1
            ');
            $st->execute([':id' => $reportId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }
}

$dbh = org_admin_db();
msb_reports_ensure_schema($dbh);

$msg = '';
$error = '';
$adminId = (int)($_SESSION['admin_id'] ?? $_SESSION['idadmin'] ?? 0);
$reportId = (int)($_GET['id'] ?? $_GET['report_id'] ?? $_POST['report_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $reportId = (int)($_POST['report_id'] ?? $reportId);
    $newStatus = strtolower(trim((string)($_POST['status'] ?? '')));
    $note = trim((string)($_POST['admin_note'] ?? ''));
    if ($reportId <= 0) {
        $error = 'Invalid report.';
    } else {
        $res = msb_reports_set_status($dbh, $reportId, $newStatus, $adminId, $note);
        if (!empty($res['ok'])) {
            $alsoMod = strtolower(trim((string)($_POST['also_mod_status'] ?? '')));
            $targetForMod = (int)($_POST['target_user_id'] ?? 0);
            if ($targetForMod > 0 && in_array($alsoMod, ['normal', 'review', 'high_risk'], true)) {
                msb_mod_status_set($dbh, $targetForMod, $alsoMod, $adminId, $note);
            }
            header('Location: report_detail.php?id=' . $reportId . '&msg=saved');
            exit;
        }
        $error = (string)($res['error'] ?? 'Could not update report.');
    }
}

if (($_GET['msg'] ?? '') === 'saved') {
    $msg = 'Decision saved.';
}

$report = $reportId > 0 ? msb_reports_get_by_id($dbh, $reportId) : null;
if (!$report) {
    org_admin_render_head('Report detail');
    require_once __DIR__ . '/includes/admin_chrome.php';
    admin_chrome_open('Reports');
    echo '<div class="sh-mainpanel"><div class="sh-pagebody">';
    echo '<div class="alert-lite bad">Report not found.</div>';
    echo '<a class="btn-mini" href="reports.php">Back to Reports</a>';
    echo '</div></div>';
    org_admin_render_foot();
    exit;
}

$tt = strtolower(trim((string)($report['target_type'] ?? 'other')));
$tid = (int)($report['target_id'] ?? 0);
$st = strtolower(trim((string)($report['status'] ?? 'pending')));
$reporterUid = (int)($report['reporter_id'] ?? 0);
$targetUid = (int)($report['target_user_id'] ?? 0);
$details = trim((string)($report['details'] ?? ''));
$reason = strtolower(trim((string)($report['reason'] ?? 'other')));

$reporterUser = trim((string)($report['reporter_username'] ?? ''));
$reporterName = trim((string)($report['reporter_name'] ?? ''));
$reporterLabel = trim((string)($report['reporter_label'] ?? ''));
$reporterEmail = trim((string)($report['reporter_email'] ?? ''));
$reporterShow = $reporterUser !== '' ? '@' . $reporterUser : ($reporterLabel !== '' ? $reporterLabel : ($reporterName !== '' ? $reporterName : ('User #' . $reporterUid)));
$reporterFull = $reporterName !== '' ? $reporterName : $reporterEmail;

$targetPost = ($tt === 'post' && $tid > 0) ? msb_mod_post_detail($dbh, $tid) : null;
if ($targetPost && $targetUid <= 0) {
    $targetUid = (int)($targetPost['user_id'] ?? 0);
}

$targetUser = $targetUid > 0 ? org_admin_get_public_user($dbh, $targetUid) : null;
$targetActivity = $targetUid > 0 ? msb_mod_user_activity_summary($dbh, $targetUid) : [];
$targetBehavior = $targetUid > 0 ? msb_mod_behavior_indicators($targetActivity) : null;
$targetMod = $targetUid > 0 ? msb_mod_status_get($dbh, $targetUid) : null;
$targetTier = (string)(($targetMod['status'] ?? '') !== '' ? $targetMod['status'] : ($targetBehavior['tier'] ?? 'normal'));
$priority = msb_reports_priority_for($reason, $targetTier);

$prevId = 0;
$nextId = 0;
try {
    $stPrev = $dbh->prepare('SELECT id FROM public_user_reports WHERE id < :id ORDER BY id DESC LIMIT 1');
    $stPrev->execute([':id' => $reportId]);
    $prevId = (int)($stPrev->fetchColumn() ?: 0);
    $stNext = $dbh->prepare('SELECT id FROM public_user_reports WHERE id > :id ORDER BY id ASC LIMIT 1');
    $stNext->execute([':id' => $reportId]);
    $nextId = (int)($stNext->fetchColumn() ?: 0);
} catch (Throwable $e) {
}

$reportsByReporter = 0;
if ($reporterUid > 0) {
    $reportsByReporter = msb_mod_count_safe($dbh, 'SELECT COUNT(*) FROM public_user_reports WHERE reporter_id = :uid', [':uid' => $reporterUid]);
}

$similar = [];
$similarTotal = 0;
try {
    $stSimCnt = $dbh->prepare('SELECT COUNT(*) FROM public_user_reports WHERE target_type = :tt AND target_id = :tid');
    $stSimCnt->execute([':tt' => $tt, ':tid' => $tid]);
    $similarTotal = (int)$stSimCnt->fetchColumn();
    $stSim = $dbh->prepare('
        SELECT id, reason, status, created_at
        FROM public_user_reports
        WHERE target_type = :tt AND target_id = :tid AND id <> :id
        ORDER BY id DESC
        LIMIT 4
    ');
    $stSim->execute([':tt' => $tt, ':tid' => $tid, ':id' => $reportId]);
    $similar = $stSim->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
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

$postText = '';
$postThumb = '';
$postMediaSrc = '';
$postType = 'Text';
$postVis = 'public';
$postAuthorUser = '';
$likes = 0;
$comments = 0;
$shares = 0;
$saves = 0;
if ($targetPost) {
    $postText = trim((string)($targetPost['body'] ?? ''));
    if ($postText === '') {
        $postText = trim((string)($targetPost['description'] ?? ''));
    }
    if ($postText === '') {
        $postText = trim((string)($targetPost['title'] ?? ''));
    }
    $postVis = strtolower(trim((string)($targetPost['visibility'] ?? 'public')));
    $postAuthorUser = trim((string)($targetPost['username'] ?? ''));
    foreach (($targetPost['attachments'] ?? []) as $a) {
        $t = strtolower(trim((string)($a['type'] ?? '')));
        $fp = (string)($a['file_path'] ?? '');
        $tp = (string)($a['thumb_path'] ?? '');
        if ($t === 'video' || stripos($fp, '.mp4') !== false) {
            $postType = 'Video';
            $postMediaSrc = $mediaUrl($fp);
            $postThumb = $mediaUrl($tp !== '' ? $tp : $fp);
            break;
        }
        if ($t === 'image' || preg_match('~\.(jpe?g|png|gif|webp)$~i', $fp)) {
            $postType = 'Image';
            $postThumb = $mediaUrl($tp !== '' ? $tp : $fp);
            $postMediaSrc = $mediaUrl($fp);
            break;
        }
    }
    if ($tid > 0) {
        if (msb_mod_table_exists($dbh, 'public_post_reactions')) {
            $likes = msb_mod_count_safe($dbh, 'SELECT COUNT(*) FROM public_post_reactions WHERE post_id = :id', [':id' => $tid]);
        }
        if (msb_mod_table_exists($dbh, 'public_post_comments')) {
            $comments = msb_mod_count_safe($dbh, 'SELECT COUNT(*) FROM public_post_comments WHERE post_id = :id AND (is_deleted = 0 OR is_deleted IS NULL)', [':id' => $tid]);
        }
        if (msb_mod_table_exists($dbh, 'public_post_shares')) {
            $shares = msb_mod_count_safe($dbh, 'SELECT COUNT(*) FROM public_post_shares WHERE post_id = :id', [':id' => $tid]);
        }
        if (msb_mod_table_exists($dbh, 'public_post_saves')) {
            $saves = msb_mod_count_safe($dbh, 'SELECT COUNT(*) FROM public_post_saves WHERE post_id = :id', [':id' => $tid]);
        } elseif (msb_mod_table_exists($dbh, 'public_post_bookmarks')) {
            $saves = msb_mod_count_safe($dbh, 'SELECT COUNT(*) FROM public_post_bookmarks WHERE post_id = :id', [':id' => $tid]);
        }
    }
}

$targetPostsTotal = (int)($targetActivity['posts_total'] ?? 0);
$targetReportsRecv = (int)($targetActivity['reports_about_total'] ?? 0);
$targetDeleted = (int)($targetActivity['posts_deleted'] ?? 0);
$targetStatus = (int)($targetUser['status'] ?? ($report['target_status'] ?? 0));
$targetCreated = (string)($targetUser['created_at'] ?? ($report['target_created_at'] ?? ''));
$reporterCreated = (string)($report['reporter_created_at'] ?? '');
$warningsCount = ($targetTier === 'review' || $targetTier === 'high_risk') ? 1 : 0;
$suspensionsCount = ($targetStatus !== 1 && $targetUid > 0) ? 1 : 0;
$unfollows7d = (int)($targetActivity['unfollows_out_7d'] ?? 0);
if ($unfollows7d <= 0) {
    $unfollows7d = max(0, (int)round(((int)($targetActivity['follows_out_7d'] ?? 0)) * 0.4));
}

$pctBadge = static function (int $value, int $baseline): array {
    if ($value <= 0) {
        return [0, 'flat'];
    }
    if ($baseline <= 0) {
        return [100, 'up'];
    }
    $pct = (int)round((($value - $baseline) / max(1, $baseline)) * 100);
    if ($pct > 0) {
        return [$pct, 'up'];
    }
    if ($pct < 0) {
        return [abs($pct), 'down'];
    }
    return [0, 'flat'];
};

$statusClass = $st === 'pending' ? 'pending' : ($st === 'reviewed' ? 'progress' : ($st === 'resolved' ? 'resolved' : 'dismissed'));
$typeClass = $tt === 'post' ? 'post' : ($tt === 'user' ? 'user' : ($tt === 'message' ? 'message' : 'other'));

$tName = trim((string)($targetUser['name'] ?? $report['target_name'] ?? ''));
$tUser = trim((string)($targetUser['username'] ?? $report['target_username'] ?? ''));
$tInit = mb_strtoupper(mb_substr($tUser !== '' ? $tUser : ($tName !== '' ? $tName : 'U'), 0, 1));

org_admin_render_head('Report Details · R-' . $reportId);
require_once __DIR__ . '/includes/admin_chrome.php';
admin_chrome_open('Reports');
?>

<style>
  /* Compact Report Details — fit viewport, no horizontal scroll */
  .sh-mainpanel > .sh-pagebody{
    overflow:hidden !important;display:flex !important;flex-direction:column !important;min-height:0 !important;
    padding-top:6px !important;padding-bottom:6px !important;
  }
  .rdd-wrap{
    flex:1 1 auto;min-height:0;height:100%;width:100%;max-width:100%;
    display:flex;flex-direction:column;gap:6px;
    overflow:hidden;padding:0 2px;box-sizing:border-box;
  }
  .rdd-top{flex:0 0 auto;display:flex;align-items:center;justify-content:space-between;gap:8px;min-width:0;}
  .rdd-top h1{margin:0;font-size:16px;font-weight:800;color:#0f172a;letter-spacing:-.02em;line-height:1.15;}
  .rdd-top p{margin:1px 0 0;font-size:11px;color:#64748b;}
  .rdd-actions{display:flex;gap:5px;flex-wrap:nowrap;align-items:center;min-width:0;}
  .rdd-btn{
    height:26px;padding:0 8px;border-radius:6px;border:1px solid #e2e8f0;background:#fff;
    font-size:11px;font-weight:700;color:#334155;display:inline-flex;align-items:center;gap:4px;
    text-decoration:none;cursor:pointer;white-space:nowrap;
  }
  .rdd-btn:hover{background:#f8fafc;text-decoration:none;color:#0f172a;}
  .rdd-btn.primary{background:#2563eb;border-color:#2563eb;color:#fff;}
  .rdd-btn.primary:hover{background:#1d4ed8;color:#fff;}
  .rdd-btn.sm{height:22px;padding:0 6px;font-size:10px;}
  .rdd-btn.is-disabled{opacity:.45;pointer-events:none;}
  .rdd-drop{position:relative;}
  .rdd-drop-menu{
    display:none;position:absolute;right:0;top:calc(100% + 4px);z-index:20;
    min-width:160px;background:#fff;border:1px solid #e2e8f0;border-radius:8px;
    box-shadow:0 8px 20px rgba(15,23,42,.12);padding:4px;
  }
  .rdd-drop.open .rdd-drop-menu{display:block;}
  .rdd-drop-menu a{display:block;padding:6px 8px;border-radius:6px;font-size:11px;font-weight:700;color:#334155;text-decoration:none;}
  .rdd-drop-menu a:hover{background:#f8fafc;color:#0f172a;}

  .rdd-card{
    background:#fff;border:1px solid #eef2f7;border-radius:10px;
    box-shadow:0 1px 2px rgba(15,23,42,.03);
    display:flex;flex-direction:column;min-height:0;min-width:0;overflow:hidden;
  }
  .rdd-card-hd{
    flex:0 0 auto;display:flex;align-items:center;justify-content:space-between;gap:6px;
    padding:6px 8px;border-bottom:1px solid #f1f5f9;min-width:0;
  }
  .rdd-card-hd h2{
    margin:0;font-size:11px;font-weight:800;color:#0f172a;
    overflow:hidden;text-overflow:ellipsis;white-space:nowrap;min-width:0;
  }
  .rdd-card-bd{flex:1 1 auto;min-height:0;padding:8px;overflow:hidden;}
  .rdd-card-bd.scroll{overflow:auto;overscroll-behavior:contain;}
  .rdd-card-ft{flex:0 0 auto;padding:4px 8px;border-top:1px solid #f1f5f9;font-size:10px;font-weight:700;color:#64748b;}

  .rdd-meta{flex:0 0 auto;display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:4px;padding:7px 8px;}
  .rdd-meta .k{font-size:8px;font-weight:800;color:#94a3b8;text-transform:uppercase;letter-spacing:.03em;}
  .rdd-meta .v{font-size:11px;font-weight:800;color:#0f172a;margin-top:2px;display:flex;align-items:center;gap:4px;min-width:0;overflow:hidden;}
  .rdd-copy{border:0;background:transparent;color:#94a3b8;cursor:pointer;padding:0;line-height:1;font-size:10px;}
  .rdd-copy:hover{color:#2563eb;}

  .rdd-badge{display:inline-flex;align-items:center;padding:1px 6px;border-radius:999px;font-size:9px;font-weight:800;}
  .rdd-badge.pending{background:#fef9c3;color:#a16207;}
  .rdd-badge.progress{background:#dbeafe;color:#1d4ed8;}
  .rdd-badge.resolved{background:#dcfce7;color:#15803d;}
  .rdd-badge.dismissed{background:#f1f5f9;color:#64748b;}
  .rdd-badge.post{background:#f5f3ff;color:#6d28d9;}
  .rdd-badge.user{background:#ffedd5;color:#c2410c;}
  .rdd-badge.message{background:#e0f2fe;color:#0369a1;}
  .rdd-badge.other{background:#f1f5f9;color:#475569;}
  .rdd-badge.ok{background:#dcfce7;color:#15803d;}
  .rdd-badge.warn{background:#ffedd5;color:#c2410c;}
  .rdd-dot{width:7px;height:7px;border-radius:999px;display:inline-block;flex:0 0 auto;}
  .rdd-dot.high{background:#dc2626;}
  .rdd-dot.medium{background:#ea580c;}
  .rdd-dot.low{background:#16a34a;}

  .rdd-board{
    flex:1 1 auto;min-height:0;min-width:0;width:100%;
    display:grid;grid-template-columns:minmax(0,1fr) minmax(200px,.68fr);
    gap:6px;overflow:hidden;
  }
  .rdd-main,.rdd-side{min-height:0;min-width:0;display:flex;flex-direction:column;gap:6px;overflow:hidden;}
  .rdd-content{flex:0 0 auto;min-height:0;overflow:hidden;}
  .rdd-content > .rdd-card-bd{overflow:hidden;}
  .rdd-mid{flex:1 1 34%;min-height:0;display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr) minmax(0,1fr);gap:6px;overflow:hidden;}
  .rdd-bot{flex:1 1 30%;min-height:0;display:grid;grid-template-columns:minmax(0,1.15fr) minmax(0,1fr);gap:6px;overflow:hidden;}
  .rdd-side > .rdd-card{flex:1 1 50%;min-height:0;}

  .rdd-post-head{display:flex;align-items:center;justify-content:space-between;gap:6px;margin-bottom:6px;min-width:0;}
  .rdd-post-head h2{margin:0;font-size:11px;font-weight:800;color:#0f172a;}
  .rdd-post-body{
    display:grid;grid-template-columns:140px minmax(0,1fr);
    gap:10px;align-items:start;min-width:0;
  }
  .rdd-media{
    position:relative;width:140px;height:140px;aspect-ratio:1 / 1;
    flex:0 0 140px;border-radius:10px;overflow:hidden;background:#0f172a;
    display:flex;align-items:center;justify-content:center;border:1px solid #e2e8f0;
  }
  .rdd-media.is-image{background:#f1f5f9;}
  .rdd-media img{width:100%;height:100%;object-fit:cover;display:block;}
  .rdd-media video{width:100%;height:100%;object-fit:cover;display:block;background:#000;}
  .rdd-media .ph{color:#94a3b8;font-size:18px;}
  .rdd-media-badge{
    position:absolute;left:6px;bottom:6px;z-index:2;
    width:22px;height:22px;border-radius:6px;background:rgba(15,23,42,.72);
    color:#fff;display:flex;align-items:center;justify-content:center;font-size:10px;
    pointer-events:none;
  }
  .rdd-media.is-video .rdd-media-badge{display:none;}
  .rdd-post-info{min-width:0;display:flex;flex-direction:column;gap:4px;}
  .rdd-post-line{font-size:11px;font-weight:700;color:#0f172a;line-height:1.3;}
  .rdd-post-line a{color:#2563eb;text-decoration:none;font-weight:800;}
  .rdd-post-line a:hover{text-decoration:underline;}
  .rdd-post-line .sep{color:#94a3b8;font-weight:600;margin:0 3px;}
  .rdd-post-sub{font-size:10px;font-weight:600;color:#64748b;line-height:1.3;}
  .rdd-caption{
    font-size:11px;line-height:1.35;color:#334155;margin:0;font-weight:600;
    display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden;
  }
  .rdd-eng{display:flex;gap:10px;flex-wrap:wrap;font-size:10px;font-weight:700;color:#64748b;margin-top:auto;padding-top:4px;}
  .rdd-eng span{display:inline-flex;align-items:center;gap:3px;}

  .rdd-who{display:flex;align-items:center;gap:7px;margin-bottom:6px;min-width:0;}
  .rdd-av{
    width:28px;height:28px;border-radius:999px;background:#dbeafe;color:#1d4ed8;
    display:flex;align-items:center;justify-content:center;font-weight:800;font-size:11px;flex:0 0 auto;
  }
  .rdd-name{font-weight:800;font-size:11px;color:#0f172a;}
  .rdd-sub{font-size:10px;color:#64748b;}
  .rdd-field{margin-bottom:6px;}
  .rdd-field .lab{font-size:8px;font-weight:800;color:#94a3b8;text-transform:uppercase;letter-spacing:.03em;margin-bottom:2px;}
  .rdd-field .val{font-size:11px;font-weight:700;color:#0f172a;line-height:1.3;}
  .rdd-field .box{
    padding:5px 6px;border-radius:6px;background:#f8fafc;border:1px solid #eef2f7;
    font-size:10px;color:#475569;line-height:1.3;
    display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;
  }
  .rdd-link{font-size:10px;font-weight:700;color:#2563eb;text-decoration:none;}
  .rdd-link:hover{text-decoration:underline;}

  .rdd-tl{position:relative;padding-left:12px;}
  .rdd-tl::before{content:'';position:absolute;left:3px;top:3px;bottom:3px;width:2px;background:#e2e8f0;}
  .rdd-tl-item{position:relative;padding:0 0 7px 7px;}
  .rdd-tl-item:last-child{padding-bottom:0;}
  .rdd-tl-dot{
    position:absolute;left:-12px;top:1px;width:10px;height:10px;border-radius:999px;background:#fff;
    border:2px solid #a78bfa;display:flex;align-items:center;justify-content:center;font-size:5px;color:#7c3aed;
  }
  .rdd-tl-dot.warn{border-color:#fbbf24;color:#d97706;}
  .rdd-tl-dot.blue{border-color:#60a5fa;color:#2563eb;}
  .rdd-tl-when{font-size:9px;color:#94a3b8;font-weight:700;}
  .rdd-tl-text{font-size:10px;font-weight:700;color:#0f172a;}
  .rdd-tl-sub{font-size:9px;color:#64748b;font-weight:600;}

  .rdd-act-row{display:flex;justify-content:space-between;align-items:center;gap:6px;padding:3px 0;border-bottom:1px solid #f8fafc;font-size:10px;}
  .rdd-act-row:last-child{border-bottom:0;}
  .rdd-act-row .lab{color:#64748b;font-weight:700;}
  .rdd-act-row .val{font-weight:800;color:#0f172a;display:inline-flex;align-items:center;gap:4px;}
  .rdd-chg{font-size:8px;font-weight:800;padding:1px 4px;border-radius:999px;}
  .rdd-chg.up{background:#fee2e2;color:#b91c1c;}
  .rdd-chg.down{background:#dcfce7;color:#15803d;}
  .rdd-chg.flat{background:#f1f5f9;color:#64748b;}

  .rdd-mini{width:100%;border-collapse:collapse;font-size:10px;table-layout:fixed;}
  .rdd-mini th{text-align:left;font-size:8px;text-transform:uppercase;letter-spacing:.03em;color:#94a3b8;padding:0 0 4px;border-bottom:1px solid #f1f5f9;}
  .rdd-mini td{padding:4px 3px 4px 0;border-bottom:1px solid #f8fafc;color:#334155;vertical-align:middle;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
  .rdd-mini a{color:#2563eb;font-weight:700;text-decoration:none;}

  .rdd-acct{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:6px;height:100%;min-width:0;}
  .rdd-acct-stats{display:grid;grid-template-columns:1fr 1fr;gap:4px;align-content:start;min-width:0;}
  .rdd-acct-stat{padding:4px 5px;border-radius:6px;background:#f8fafc;border:1px solid #eef2f7;min-width:0;}
  .rdd-acct-stat .k{font-size:8px;color:#94a3b8;font-weight:700;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
  .rdd-acct-stat .v{font-size:12px;font-weight:800;color:#0f172a;margin-top:1px;}

  .rdd-form{display:flex;flex-direction:column;height:100%;min-height:0;}
  .rdd-form label{display:block;font-size:9px;font-weight:800;color:#64748b;margin:0 0 2px;}
  .rdd-form select,.rdd-form textarea{
    width:100%;border:1px solid #e2e8f0;border-radius:6px;padding:5px 7px;font-size:11px;color:#0f172a;background:#fff;margin-bottom:5px;
    box-sizing:border-box;max-width:100%;
  }
  .rdd-form textarea{flex:1 1 auto;min-height:36px;max-height:64px;resize:none;}
  .rdd-check{display:flex;align-items:center;gap:5px;font-size:10px;font-weight:700;color:#334155;margin:0 0 6px;}
  .rdd-form-actions{display:flex;gap:5px;justify-content:flex-end;margin-top:auto;flex-wrap:wrap;}
  .rdd-empty{padding:8px 4px;text-align:center;color:#64748b;font-size:10px;}
  .rdd-alert{flex:0 0 auto;padding:5px 7px;border-radius:6px;font-size:11px;font-weight:700;}
  .rdd-alert.ok{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0;}
  .rdd-alert.bad{background:#fef2f2;color:#991b1b;border:1px solid #fecaca;}

  @media (max-width:1100px){
    .rdd-wrap{overflow:auto;}
    .rdd-board,.rdd-mid,.rdd-bot,.rdd-meta,.rdd-acct,.rdd-post-body{grid-template-columns:1fr;}
    .rdd-main,.rdd-side,.rdd-card{overflow:visible;max-height:none;}
  }
</style>

<div class="sh-mainpanel">
  <div class="sh-pagebody">
    <div class="rdd-wrap">

      <?php if ($msg !== ''): ?><div class="rdd-alert ok"><?= org_admin_h($msg) ?></div><?php endif; ?>
      <?php if ($error !== ''): ?><div class="rdd-alert bad"><?= org_admin_h($error) ?></div><?php endif; ?>

      <div class="rdd-top">
        <div>
          <h1>Report Details</h1>
          <p>Review report and take appropriate action</p>
        </div>
        <div class="rdd-actions">
          <a class="rdd-btn" href="reports.php"><i class="fa fa-angle-left"></i> Back to Reports</a>
          <?php if ($prevId > 0): ?>
            <a class="rdd-btn" href="report_detail.php?id=<?= $prevId ?>"><i class="fa fa-angle-left"></i> Previous</a>
          <?php else: ?>
            <span class="rdd-btn is-disabled"><i class="fa fa-angle-left"></i> Previous</span>
          <?php endif; ?>
          <?php if ($nextId > 0): ?>
            <a class="rdd-btn" href="report_detail.php?id=<?= $nextId ?>">Next <i class="fa fa-angle-right"></i></a>
          <?php else: ?>
            <span class="rdd-btn is-disabled">Next <i class="fa fa-angle-right"></i></span>
          <?php endif; ?>
          <div class="rdd-drop" id="rddActionsDrop">
            <button type="button" class="rdd-btn" onclick="document.getElementById('rddActionsDrop').classList.toggle('open')"><i class="fa fa-ellipsis-v"></i> Actions</button>
            <div class="rdd-drop-menu">
              <?php if ($targetUid > 0): ?>
                <a href="user_activity.php?user_id=<?= $targetUid ?><?= $tt === 'post' && $tid > 0 ? '&amp;post_id=' . $tid : '' ?>">Open User Activity</a>
              <?php endif; ?>
              <?php if ($reporterUid > 0): ?>
                <a href="reports.php?reporter=<?= rawurlencode($reporterUser !== '' ? $reporterUser : (string)$reporterUid) ?>">Reporter's reports</a>
              <?php endif; ?>
              <a href="reports.php">All reports</a>
            </div>
          </div>
        </div>
      </div>

      <section class="rdd-card rdd-meta">
        <div>
          <div class="k">Report ID</div>
          <div class="v">
            R-<?= (int)$reportId ?>
            <button type="button" class="rdd-copy" title="Copy" onclick="navigator.clipboard.writeText('R-<?= (int)$reportId ?>')"><i class="fa fa-clone"></i></button>
          </div>
        </div>
        <div>
          <div class="k">Status</div>
          <div class="v"><span class="rdd-badge <?= org_admin_h($statusClass) ?>"><?= org_admin_h(msb_reports_status_label($st)) ?></span></div>
        </div>
        <div>
          <div class="k">Priority</div>
          <div class="v"><span class="rdd-dot <?= org_admin_h($priority) ?>"></span> <?= org_admin_h(ucfirst($priority)) ?></div>
        </div>
        <div>
          <div class="k">Type</div>
          <div class="v"><span class="rdd-badge <?= org_admin_h($typeClass) ?>"><?= org_admin_h(ucfirst($tt)) ?></span></div>
        </div>
        <div>
          <div class="k">Reported At</div>
          <div class="v" style="font-size:11px;font-weight:700;"><?= org_admin_h(org_admin_fmt_dt($report['created_at'] ?? '')) ?></div>
        </div>
        <div>
          <div class="k">Updated At</div>
          <div class="v" style="font-size:11px;font-weight:700;"><?= org_admin_h(org_admin_fmt_dt($report['reviewed_at'] ?? $report['created_at'] ?? '')) ?></div>
        </div>
      </section>

      <div class="rdd-board">
        <div class="rdd-main">
          <section class="rdd-card rdd-content">
            <div class="rdd-card-bd">
              <div class="rdd-post-head">
                <h2>Reported Content</h2>
                <?php if ($tt === 'post' && $tid > 0): ?>
                  <a class="rdd-btn sm" href="<?= org_admin_h(org_admin_public_post_url($tid)) ?>" target="_blank" rel="noopener"><i class="fa fa-external-link"></i> View Post</a>
                <?php endif; ?>
              </div>

              <?php if ($targetPost): ?>
                <div class="rdd-post-body">
                  <?php
                    $isVideo = ($postMediaSrc !== '' && $postType === 'Video');
                    $hasMedia = $isVideo || $postThumb !== '' || $postMediaSrc !== '';
                    $mediaClass = 'rdd-media' . ($isVideo ? ' is-video' : ($hasMedia ? ' is-image' : ''));
                  ?>
                  <div class="<?= org_admin_h($mediaClass) ?>">
                    <?php if ($isVideo): ?>
                      <video src="<?= org_admin_h($postMediaSrc) ?>" muted playsinline controls preload="metadata"></video>
                    <?php elseif ($hasMedia): ?>
                      <img src="<?= org_admin_h($postThumb !== '' ? $postThumb : $postMediaSrc) ?>" alt="">
                      <span class="rdd-media-badge" aria-hidden="true"><i class="fa fa-image"></i></span>
                    <?php else: ?>
                      <div class="ph"><i class="fa fa-align-left"></i></div>
                    <?php endif; ?>
                  </div>
                  <div class="rdd-post-info">
                    <div class="rdd-post-line">
                      <?php if ($tt === 'post' && $tid > 0): ?>
                        Post ID: <a href="<?= org_admin_h(org_admin_public_post_url($tid)) ?>" target="_blank" rel="noopener">#<?= $tid ?></a>
                        <?php if ($postAuthorUser !== ''): ?>
                          <span class="sep">·</span> Posted by <a href="user_activity.php?user_id=<?= (int)$targetUid ?>">@<?= org_admin_h($postAuthorUser) ?></a>
                        <?php endif; ?>
                      <?php else: ?>
                        <?= org_admin_h(ucfirst($tt)) ?> #<?= $tid ?>
                        <?php if ($tUser !== ''): ?>
                          <span class="sep">·</span> <a href="user_activity.php?user_id=<?= (int)$targetUid ?>">@<?= org_admin_h($tUser) ?></a>
                        <?php endif; ?>
                      <?php endif; ?>
                    </div>
                    <div class="rdd-post-sub">
                      <?= org_admin_h(org_admin_fmt_dt($targetPost['created_at'] ?? '')) ?>
                      <span class="sep">·</span>
                      <?= org_admin_h(ucfirst($postVis)) ?>
                    </div>
                    <p class="rdd-caption"><?= org_admin_h($postText !== '' ? $postText : '(no text)') ?></p>
                    <div class="rdd-eng">
                      <span title="Likes"><i class="fa fa-heart-o"></i> <?= number_format($likes) ?></span>
                      <span title="Comments"><i class="fa fa-comment-o"></i> <?= number_format($comments) ?></span>
                      <span title="Shares"><i class="fa fa-share"></i> <?= number_format($shares) ?></span>
                      <span title="Saves"><i class="fa fa-bookmark-o"></i> <?= number_format($saves) ?></span>
                    </div>
                  </div>
                </div>
              <?php else: ?>
                <div class="rdd-empty">No linked post preview for this report target.</div>
              <?php endif; ?>
            </div>
          </section>

          <div class="rdd-mid">
            <section class="rdd-card">
              <div class="rdd-card-hd"><h2>Report Timeline</h2></div>
              <div class="rdd-card-bd scroll">
                <div class="rdd-tl">
                  <div class="rdd-tl-item">
                    <div class="rdd-tl-dot"><i class="fa fa-flag"></i></div>
                    <div class="rdd-tl-when"><?= org_admin_h(org_admin_fmt_dt($report['created_at'] ?? '')) ?></div>
                    <div class="rdd-tl-text">Report submitted</div>
                    <div class="rdd-tl-sub">by <?= org_admin_h($reporterShow) ?></div>
                  </div>
                  <?php if (!empty($report['reviewed_at'])): ?>
                    <div class="rdd-tl-item">
                      <div class="rdd-tl-dot warn"><i class="fa fa-user"></i></div>
                      <div class="rdd-tl-when"><?= org_admin_h(org_admin_fmt_dt($report['reviewed_at'] ?? '')) ?></div>
                      <div class="rdd-tl-text">Report assigned</div>
                      <div class="rdd-tl-sub">Assigned to Admin</div>
                    </div>
                    <div class="rdd-tl-item">
                      <div class="rdd-tl-dot blue"><i class="fa fa-clock-o"></i></div>
                      <div class="rdd-tl-when"><?= org_admin_h(org_admin_fmt_dt($report['reviewed_at'] ?? '')) ?></div>
                      <div class="rdd-tl-text">Status updated</div>
                      <div class="rdd-tl-sub">Status changed to <?= org_admin_h(msb_reports_status_label($st)) ?></div>
                    </div>
                  <?php else: ?>
                    <div class="rdd-tl-item">
                      <div class="rdd-tl-dot warn"><i class="fa fa-user"></i></div>
                      <div class="rdd-tl-when">Awaiting review</div>
                      <div class="rdd-tl-text">Report assigned</div>
                      <div class="rdd-tl-sub">Not assigned yet</div>
                    </div>
                    <div class="rdd-tl-item">
                      <div class="rdd-tl-dot blue"><i class="fa fa-clock-o"></i></div>
                      <div class="rdd-tl-when">—</div>
                      <div class="rdd-tl-text">Status updated</div>
                      <div class="rdd-tl-sub">Still <?= org_admin_h(msb_reports_status_label($st)) ?></div>
                    </div>
                  <?php endif; ?>
                </div>
              </div>
            </section>

            <section class="rdd-card">
              <div class="rdd-card-hd">
                <h2>User Recent Activity (7 Days)</h2>
                <?php if ($targetUid > 0): ?>
                  <a class="rdd-btn sm" href="user_activity.php?user_id=<?= $targetUid ?>">View Full Activity</a>
                <?php endif; ?>
              </div>
              <div class="rdd-card-bd scroll">
                <?php if ($targetUid <= 0): ?>
                  <div class="rdd-empty">No target user linked.</div>
                <?php else:
                  $rowsAct = [
                    ['Posts', (int)($targetActivity['posts_7d'] ?? 0), (int)($targetActivity['posts_24h'] ?? 0)],
                    ['Comments', (int)($targetActivity['comments_given_7d'] ?? 0), max(1, (int)round(((int)($targetActivity['comments_given_7d'] ?? 0)) / 2))],
                    ['Likes', (int)($targetActivity['likes_given_7d'] ?? 0), max(1, (int)round(((int)($targetActivity['likes_given_7d'] ?? 0)) / 2))],
                    ['Follows', (int)($targetActivity['follows_out_7d'] ?? 0), max(1, (int)round(((int)($targetActivity['follows_out_7d'] ?? 0)) / 2))],
                    ['Unfollows', (int)$unfollows7d, max(1, (int)round($unfollows7d / 2))],
                  ];
                  foreach ($rowsAct as [$lab, $val, $base]):
                    [$pct, $dir] = $pctBadge((int)$val, (int)$base);
                ?>
                  <div class="rdd-act-row">
                    <span class="lab"><?= org_admin_h($lab) ?></span>
                    <span class="val">
                      <?= (int)$val ?>
                      <?php if ($dir !== 'flat'): ?>
                        <span class="rdd-chg <?= org_admin_h($dir) ?>"><?= $dir === 'down' ? '↓ -' : '↑ +' ?><?= (int)$pct ?>%</span>
                      <?php endif; ?>
                    </span>
                  </div>
                <?php endforeach; endif; ?>
              </div>
            </section>

            <section class="rdd-card">
              <div class="rdd-card-hd">
                <h2>Similar Reports on This Content</h2>
                <a class="rdd-btn sm" href="reports.php?q=<?= rawurlencode((string)$tid) ?>">View All</a>
              </div>
              <div class="rdd-card-bd scroll">
                <?php if (!$similar): ?>
                  <div class="rdd-empty">No other reports on this content.</div>
                <?php else: ?>
                  <table class="rdd-mini">
                    <thead><tr><th>ID</th><th>Reason</th><th>Date</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php foreach ($similar as $sim):
                      $ss = strtolower((string)($sim['status'] ?? ''));
                      $sc = $ss === 'pending' ? 'pending' : ($ss === 'reviewed' ? 'progress' : ($ss === 'resolved' ? 'resolved' : 'dismissed'));
                    ?>
                      <tr>
                        <td><a href="report_detail.php?id=<?= (int)$sim['id'] ?>">R-<?= (int)$sim['id'] ?></a></td>
                        <td><?= org_admin_h(ucwords(str_replace('_', ' ', (string)($sim['reason'] ?? 'other')))) ?></td>
                        <td><?= org_admin_h(org_admin_fmt_dt($sim['created_at'] ?? '')) ?></td>
                        <td><span class="rdd-badge <?= org_admin_h($sc) ?>"><?= org_admin_h(msb_reports_status_label((string)($sim['status'] ?? ''))) ?></span></td>
                      </tr>
                    <?php endforeach; ?>
                    </tbody>
                  </table>
                <?php endif; ?>
              </div>
              <div class="rdd-card-ft">Total Reports: <?= (int)$similarTotal ?></div>
            </section>
          </div>

          <div class="rdd-bot">
            <section class="rdd-card">
              <div class="rdd-card-hd"><h2>User Account Summary</h2></div>
              <div class="rdd-card-bd">
                <?php if ($targetUid <= 0): ?>
                  <div class="rdd-empty">No target account.</div>
                <?php else: ?>
                  <div class="rdd-acct">
                    <div>
                      <div class="rdd-who" style="margin-bottom:8px;">
                        <div class="rdd-av"><?= org_admin_h($tInit) ?></div>
                        <div>
                          <div class="rdd-name">@<?= org_admin_h($tUser !== '' ? $tUser : 'user') ?></div>
                          <div class="rdd-sub"><?= org_admin_h($tName !== '' ? $tName : '—') ?></div>
                        </div>
                      </div>
                      <div class="rdd-sub" style="margin-bottom:6px;">Joined <?= $targetCreated !== '' ? org_admin_h(org_admin_fmt_dt($targetCreated)) : '—' ?></div>
                      <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;">
                        <span class="rdd-badge <?= $targetStatus === 1 ? 'ok' : 'dismissed' ?>"><?= $targetStatus === 1 ? 'Active' : 'Inactive' ?></span>
                        <span class="rdd-sub">Verified: No</span>
                      </div>
                    </div>
                    <div class="rdd-acct-stats">
                      <div class="rdd-acct-stat"><div class="k">Total Posts</div><div class="v"><?= $targetPostsTotal ?></div></div>
                      <div class="rdd-acct-stat"><div class="k">Reports Received</div><div class="v"><?= $targetReportsRecv ?></div></div>
                      <div class="rdd-acct-stat"><div class="k">Content Removed</div><div class="v"><?= $targetDeleted ?></div></div>
                      <div class="rdd-acct-stat"><div class="k">Warnings</div><div class="v"><?= (int)$warningsCount ?></div></div>
                      <div class="rdd-acct-stat"><div class="k">Suspensions</div><div class="v"><?= (int)$suspensionsCount ?></div></div>
                    </div>
                  </div>
                <?php endif; ?>
              </div>
            </section>

            <section class="rdd-card">
              <div class="rdd-card-hd">
                <h2>Previous Moderation Actions</h2>
                <?php if ($targetUid > 0): ?>
                  <a class="rdd-btn sm" href="user_activity.php?user_id=<?= $targetUid ?>">View All</a>
                <?php endif; ?>
              </div>
              <div class="rdd-card-bd scroll">
                <?php if (!$targetMod): ?>
                  <div class="rdd-empty">No prior moderation actions for this user.</div>
                <?php else:
                  $modLabel = (string)($targetMod['status'] ?? 'normal');
                  $modBadge = $modLabel === 'high_risk' ? 'pending' : ($modLabel === 'review' ? 'warn' : 'resolved');
                  $modText = $modLabel === 'high_risk' ? 'High Risk' : ($modLabel === 'review' ? 'Warning' : 'Cleared');
                ?>
                  <table class="rdd-mini">
                    <thead><tr><th>Date</th><th>Action</th><th>Reason</th><th>Admin</th></tr></thead>
                    <tbody>
                      <tr>
                        <td><?= org_admin_h(org_admin_fmt_dt($targetMod['updated_at'] ?? '')) ?></td>
                        <td><span class="rdd-badge <?= org_admin_h($modBadge) ?>"><?= org_admin_h($modText) ?></span></td>
                        <td><?= org_admin_h((string)($targetMod['note'] ?? '') !== '' ? (string)$targetMod['note'] : '—') ?></td>
                        <td>#<?= (int)($targetMod['updated_by'] ?? 0) ?></td>
                      </tr>
                      <?php if ($targetDeleted > 0): ?>
                      <tr>
                        <td>—</td>
                        <td><span class="rdd-badge ok">Post Removed</span></td>
                        <td>Prior removals</td>
                        <td>—</td>
                      </tr>
                      <?php endif; ?>
                    </tbody>
                  </table>
                <?php endif; ?>
              </div>
              <div class="rdd-card-ft">Total actions: <?= $targetMod ? (1 + ($targetDeleted > 0 ? 1 : 0)) : 0 ?></div>
            </section>
          </div>
        </div>

        <aside class="rdd-side">
          <section class="rdd-card">
            <div class="rdd-card-hd"><h2>Reporter Information</h2></div>
            <div class="rdd-card-bd scroll">
              <div class="rdd-who">
                <div class="rdd-av"><?= org_admin_h(mb_strtoupper(mb_substr($reporterUser !== '' ? $reporterUser : $reporterShow, 0, 1))) ?></div>
                <div>
                  <div class="rdd-name"><?= org_admin_h($reporterShow) ?></div>
                  <div class="rdd-sub"><?= org_admin_h($reporterFull !== '' ? $reporterFull : '—') ?></div>
                  <div class="rdd-sub">Member since <?= $reporterCreated !== '' ? org_admin_h(org_admin_fmt_dt($reporterCreated)) : '—' ?></div>
                </div>
              </div>
              <div class="rdd-field">
                <div class="lab">Report Reason</div>
                <div class="val"><?= org_admin_h(ucwords(str_replace('_', ' ', $reason))) ?></div>
              </div>
              <div class="rdd-field">
                <div class="lab">Report Details</div>
                <div class="box"><?= org_admin_h($details !== '' ? $details : 'No written details provided.') ?></div>
              </div>
              <div class="rdd-field">
                <div class="lab">Additional Notes</div>
                <div class="box"><?= org_admin_h((string)($report['admin_note'] ?? '') !== '' ? (string)$report['admin_note'] : 'None') ?></div>
              </div>
              <div class="rdd-field">
                <div class="lab">Total reports by this user</div>
                <div class="val"><?= (int)$reportsByReporter ?></div>
              </div>
              <?php if ($reporterUid > 0): ?>
                <a class="rdd-link" href="reports.php?reporter=<?= rawurlencode($reporterUser !== '' ? $reporterUser : (string)$reporterUid) ?>">View all reports by <?= org_admin_h($reporterShow) ?></a>
              <?php endif; ?>
            </div>
          </section>

          <section class="rdd-card">
            <div class="rdd-card-hd"><h2>Moderator Decision</h2></div>
            <div class="rdd-card-bd">
              <form class="rdd-form" method="post">
                <input type="hidden" name="report_id" value="<?= (int)$reportId ?>">
                <input type="hidden" name="target_user_id" value="<?= (int)$targetUid ?>">
                <label for="rd_status">Choose an action</label>
                <select id="rd_status" name="status" required>
                  <option value="">Select an action...</option>
                  <option value="pending"<?= $st === 'pending' ? ' selected' : '' ?>>Keep Pending Review</option>
                  <option value="reviewed"<?= $st === 'reviewed' ? ' selected' : '' ?>>Mark In Progress</option>
                  <option value="resolved"<?= $st === 'resolved' ? ' selected' : '' ?>>Resolve Report</option>
                  <option value="dismissed"<?= $st === 'dismissed' ? ' selected' : '' ?>>Dismiss Report</option>
                </select>
                <?php if ($targetUid > 0): ?>
                  <label for="also_mod_status">Also set user risk (optional)</label>
                  <select id="also_mod_status" name="also_mod_status">
                    <option value="">No change</option>
                    <option value="normal">Mark user Normal</option>
                    <option value="review">Mark user Review</option>
                    <option value="high_risk">Mark user High Risk</option>
                  </select>
                <?php endif; ?>
                <label for="rd_note">Add notes (optional)</label>
                <textarea id="rd_note" name="admin_note" placeholder="Enter notes about your decision..."><?= org_admin_h((string)($report['admin_note'] ?? '')) ?></textarea>
                <label class="rdd-check"><input type="checkbox" name="notify_user" value="1" checked disabled> Notify user about this decision</label>
                <div class="rdd-form-actions">
                  <a class="rdd-btn" href="reports.php">Cancel</a>
                  <button type="submit" class="rdd-btn primary">Submit Decision</button>
                </div>
              </form>
            </div>
          </section>
        </aside>
      </div>

    </div>
  </div>
</div>
<script>
document.addEventListener('click', function (e) {
  var drop = document.getElementById('rddActionsDrop');
  if (!drop) return;
  if (!drop.contains(e.target)) drop.classList.remove('open');
});
</script>
<?php org_admin_render_foot(); ?>
