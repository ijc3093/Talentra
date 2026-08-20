<?php
declare(strict_types=1);

/**
 * Admin — Reports dashboard (content moderation queue).
 */
require_once __DIR__ . '/includes/org_admin_helpers_load.php';
require_once __DIR__ . '/../public_user/includes/msb_reports.php';
require_once __DIR__ . '/includes/msb_moderation_activity.php';
require_once __DIR__ . '/includes/msb_reports_admin_ui.php';
org_admin_require_admin();

error_reporting(E_ALL);
ini_set('display_errors', '1');

$dbh = org_admin_db();
msb_reports_ensure_schema($dbh);

$msg = '';
$error = '';
$adminId = (int)($_SESSION['admin_id'] ?? $_SESSION['idadmin'] ?? 0);

$filter = strtolower(trim((string)($_GET['status'] ?? 'all')));
if (!in_array($filter, ['pending', 'reviewed', 'resolved', 'dismissed', 'all'], true)) {
    $filter = 'all';
}
$kindFilter = strtolower(trim((string)($_GET['kind'] ?? 'personal')));
if (!in_array($kindFilter, ['personal', 'publisher', 'commerce'], true)) {
    $kindFilter = 'personal';
}
$search = trim((string)($_GET['q'] ?? ''));
$typeFilter = strtolower(trim((string)($_GET['type'] ?? 'all')));
$reasonFilter = strtolower(trim((string)($_GET['reason'] ?? 'all')));
$priorityFilter = strtolower(trim((string)($_GET['priority'] ?? 'all')));
$reporterQ = trim((string)($_GET['reporter'] ?? ''));
$dateFrom = trim((string)($_GET['from'] ?? ''));
$dateTo = trim((string)($_GET['to'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = (int)($_GET['per_page'] ?? 10);
if (!in_array($perPage, [10, 25, 50, 100], true)) {
    $perPage = 10;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $reportId = (int)($_POST['report_id'] ?? 0);
    $newStatus = strtolower(trim((string)($_POST['status'] ?? '')));
    $note = trim((string)($_POST['admin_note'] ?? ''));
    if ($reportId <= 0) {
        $error = 'Invalid report.';
    } else {
        $res = msb_reports_set_status($dbh, $reportId, $newStatus, $adminId, $note);
        if (!empty($res['ok'])) {
            $redir = 'reports.php?status=' . rawurlencode($filter) . '&kind=' . rawurlencode($kindFilter) . '&msg=updated';
            if ($search !== '') {
                $redir .= '&q=' . rawurlencode($search);
            }
            header('Location: ' . $redir);
            exit;
        }
        $error = (string)($res['error'] ?? 'Could not update report.');
    }
}
if (($_GET['msg'] ?? '') === 'updated') {
    $msg = 'Report updated.';
}

$stats = msb_reports_admin_stats($dbh);
// Load without status so kind tabs + status cards can both scope correctly.
$allRows = msb_reports_list_for_admin($dbh, '', $search, 500);

$kindCounts = ['personal' => 0, 'publisher' => 0, 'commerce' => 0];
foreach ($allRows as &$rowRef) {
    $k = msb_reports_audience_kind($rowRef);
    $rowRef['_kind'] = $k;
    if (isset($kindCounts[$k])) {
        $kindCounts[$k]++;
    }
}
unset($rowRef);

$kindRows = [];
foreach ($allRows as $row) {
    if (($row['_kind'] ?? 'personal') === $kindFilter) {
        $kindRows[] = $row;
    }
}
$stats = msb_reports_stats_from_rows($kindRows);

// Risk cache for priority + badges
$riskByUser = [];
foreach ($kindRows as $row) {
    $tuid = (int)($row['target_user_id'] ?? 0);
    if ($tuid <= 0 || isset($riskByUser[$tuid])) {
        continue;
    }
    $saved = msb_mod_status_get($dbh, $tuid);
    if ($saved && !empty($saved['status'])) {
        $riskByUser[$tuid] = (string)$saved['status'];
        continue;
    }
    $sum = msb_mod_user_activity_summary($dbh, $tuid);
    $beh = msb_mod_behavior_indicators($sum);
    $riskByUser[$tuid] = (string)($beh['tier'] ?? 'normal');
}

// Enrich + filter
$enriched = [];
foreach ($kindRows as $row) {
    $tuid = (int)($row['target_user_id'] ?? 0);
    $risk = $tuid > 0 ? (string)($riskByUser[$tuid] ?? 'normal') : 'normal';
    $prio = msb_reports_priority_for((string)($row['reason'] ?? 'other'), $risk);
    $tt = strtolower(trim((string)($row['target_type'] ?? 'other')));
    $created = (string)($row['created_at'] ?? '');
    $st = strtolower(trim((string)($row['status'] ?? 'pending')));

    if ($filter !== 'all' && $st !== $filter) {
        continue;
    }
    if ($typeFilter !== 'all' && $tt !== $typeFilter) {
        continue;
    }
    if ($reasonFilter !== 'all' && strtolower((string)($row['reason'] ?? '')) !== $reasonFilter) {
        continue;
    }
    if ($priorityFilter !== 'all' && $prio !== $priorityFilter) {
        continue;
    }
    if ($reporterQ !== '') {
        $blob = strtolower(trim(
            (string)($row['reporter_username'] ?? '') . ' ' .
            (string)($row['reporter_name'] ?? '') . ' ' .
            (string)($row['reporter_label'] ?? '') . ' ' .
            (string)($row['reporter_email'] ?? '')
        ));
        if (strpos($blob, strtolower($reporterQ)) === false) {
            continue;
        }
    }
    if ($dateFrom !== '' && $created !== '' && strcmp(substr($created, 0, 10), $dateFrom) < 0) {
        continue;
    }
    if ($dateTo !== '' && $created !== '' && strcmp(substr($created, 0, 10), $dateTo) > 0) {
        continue;
    }

    $row['_risk'] = $risk;
    $row['_priority'] = $prio;
    $enriched[] = $row;
}

$total = count($enriched);
$totalPages = max(1, (int)ceil($total / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;
$rows = array_slice($enriched, $offset, $perPage);

// Post thumbs for reported items
$postIds = [];
foreach ($rows as $r) {
    if (strtolower((string)($r['target_type'] ?? '')) === 'post') {
        $pid = (int)($r['target_id'] ?? 0);
        if ($pid > 0) {
            $postIds[] = $pid;
        }
    }
}
$postIds = array_values(array_unique($postIds));
$thumbByPost = [];
if ($postIds && msb_mod_table_exists($dbh, 'public_post_attachments')) {
    try {
        $in = implode(',', array_fill(0, count($postIds), '?'));
        $q = $dbh->prepare("SELECT post_id, type, file_path, thumb_path FROM public_post_attachments WHERE post_id IN ($in) ORDER BY id ASC");
        $q->execute($postIds);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $a) {
            $pid = (int)$a['post_id'];
            $type = strtolower(trim((string)($a['type'] ?? '')));
            $fp = trim((string)($a['file_path'] ?? ''));
            $tp = trim((string)($a['thumb_path'] ?? ''));
            $isVideo = ($type === 'video' || preg_match('~\.(mp4|webm|mov|m4v)$~i', $fp) === 1);
            $isImage = ($type === 'image' || preg_match('~\.(jpe?g|png|gif|webp)$~i', $fp) === 1 || preg_match('~\.(jpe?g|png|gif|webp)$~i', $tp) === 1);
            $path = '';
            if ($tp !== '' && preg_match('~\.(jpe?g|png|gif|webp)$~i', $tp)) {
                $path = $tp;
            } elseif ($isImage && $fp !== '') {
                $path = $fp;
            } elseif ($isVideo && $tp !== '') {
                $path = $tp;
            }
            if ($path === '') {
                continue;
            }
            if (!isset($thumbByPost[$pid]) || $isImage) {
                $thumbByPost[$pid] = $path;
            }
        }
    } catch (Throwable $e) {
    }
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

$qs = static function (array $extra = []) use ($filter, $kindFilter, $search, $typeFilter, $reasonFilter, $priorityFilter, $reporterQ, $dateFrom, $dateTo, $perPage, $page): string {
    $base = [
        'kind' => $kindFilter,
        'status' => $filter,
        'q' => $search,
        'type' => $typeFilter,
        'reason' => $reasonFilter,
        'priority' => $priorityFilter,
        'reporter' => $reporterQ,
        'from' => $dateFrom,
        'to' => $dateTo,
        'per_page' => $perPage,
        'page' => $page,
    ];
    foreach ($extra as $k => $v) {
        $base[$k] = $v;
    }
    foreach ($base as $k => $v) {
        if ($v === '' || $v === null) {
            unset($base[$k]);
            continue;
        }
        if (in_array($k, ['type', 'reason', 'priority'], true) && $v === 'all') {
            unset($base[$k]);
            continue;
        }
        if ($k === 'page' && (int)$v === 1) {
            unset($base[$k]);
            continue;
        }
        if ($k === 'per_page' && (int)$v === 10) {
            unset($base[$k]);
            continue;
        }
        if ($k === 'kind' && $v === 'personal' && !array_key_exists('kind', $extra)) {
            unset($base[$k]);
        }
    }
    return 'reports.php?' . http_build_query($base);
};

$typeBadgeClass = static function (string $tt): string {
    $tt = strtolower($tt);
    if ($tt === 'post') {
        return 'post';
    }
    if ($tt === 'user' || $tt === 'profile') {
        return 'user';
    }
    if ($tt === 'message') {
        return 'message';
    }
    if ($tt === 'product' || $tt === 'comment') {
        return 'comment';
    }
    return 'other';
};

$statusBadgeClass = static function (string $st): string {
    $st = strtolower($st);
    if ($st === 'pending') {
        return 'pending';
    }
    if ($st === 'reviewed') {
        return 'progress';
    }
    if ($st === 'resolved') {
        return 'resolved';
    }
    if ($st === 'dismissed') {
        return 'dismissed';
    }
    return 'dismissed';
};

$reasons = msb_reports_reasons();
$types = ['post' => 'Post', 'user' => 'User', 'message' => 'Message', 'product' => 'Product', 'org' => 'Org', 'other' => 'Other'];

org_admin_render_head('Reports');
?>
<?php
require_once __DIR__ . '/includes/admin_chrome.php';
admin_chrome_open(null, [
    'title' => 'Reports',
    'description' => 'Switch Personal, Publisher, or Commerce to review reports for that audience.',
]);
?>

<style>
  /* Keep reports table card above the viewport bottom; rows scroll inside */
  .sh-mainpanel > .sh-pagebody{
    overflow:hidden !important;
    display:flex !important;
    flex-direction:column !important;
    min-height:0 !important;
    padding-top:10px !important;
    padding-bottom:10px !important;
  }
  .rp-wrap{
    flex:1 1 auto;min-height:0;
    display:flex;flex-direction:column;gap:10px;
    overflow:hidden;padding:0 2px 12px;box-sizing:border-box;margin-bottom:0;
  }
  .rp-top{
    flex:0 0 auto;
    display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;
    margin-bottom:0;
  }
  .rp-kind-tabs{
    display:flex;align-items:center;gap:6px;flex-wrap:wrap;
  }
  .rp-kind-tabs a{
    display:inline-flex;align-items:center;gap:6px;height:28px;padding:0 12px;border-radius:999px;
    font-size:11px;font-weight:800;color:#64748b;background:#fff;border:1px solid #e2e8f0;text-decoration:none;
  }
  .rp-kind-tabs a:hover{border-color:#93c5fd;color:#1e40af;text-decoration:none;}
  .rp-kind-tabs a.is-active{background:#2563eb;border-color:#2563eb;color:#fff;}
  .rp-kind-tabs a .cnt{
    display:inline-flex;align-items:center;justify-content:center;min-width:18px;height:16px;padding:0 5px;
    border-radius:999px;font-size:9px;font-weight:800;background:#f1f5f9;color:#475569;
  }
  .rp-kind-tabs a.is-active .cnt{background:rgba(255,255,255,.22);color:#fff;}
  .rp-kind-note{
    flex:0 0 auto;font-size:11px;font-weight:600;color:#64748b;line-height:1.35;
    padding:6px 2px 0;
  }
  .rp-kind-note strong{color:#0f172a;font-weight:800;}
  .rp-actions{display:flex;gap:8px;flex-wrap:wrap;}
  .rp-btn{
    height:34px;padding:0 12px;border-radius:8px;border:1px solid #e2e8f0;background:#fff;
    font-size:12px;font-weight:700;color:#334155;display:inline-flex;align-items:center;gap:6px;
    text-decoration:none;cursor:pointer;
  }
  .rp-btn:hover{background:#f8fafc;text-decoration:none;color:#0f172a;}
  .rp-btn.primary{background:#2563eb;border-color:#2563eb;color:#fff;}
  .rp-btn.primary:hover{background:#1d4ed8;color:#fff;}

  .rp-cards{
    flex:0 0 auto;
    display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:12px;margin-bottom:0;
  }
  .rp-card{
    background:#fff;border:1px solid #eef2f7;border-radius:14px;padding:14px;
    box-shadow:0 1px 2px rgba(15,23,42,.04);
  }
  .rp-card-top{display:flex;align-items:center;gap:10px;margin-bottom:8px;}
  .rp-ico{
    width:32px;height:32px;border-radius:999px;display:flex;align-items:center;justify-content:center;font-size:14px;flex:0 0 auto;
  }
  .rp-ico.purple{background:#f5f3ff;color:#7c3aed;}
  .rp-ico.pink{background:#fdf2f8;color:#db2777;}
  .rp-ico.orange{background:#fff7ed;color:#ea580c;}
  .rp-ico.green{background:#f0fdf4;color:#16a34a;}
  .rp-ico.red{background:#fef2f2;color:#dc2626;}
  .rp-card .val{font-size:24px;font-weight:800;color:#0f172a;line-height:1;margin:0;}
  .rp-card .lab{font-size:12px;color:#64748b;font-weight:700;margin-top:6px;}
  .rp-delta{font-size:11px;font-weight:800;margin-top:4px;}
  .rp-delta.up{color:#dc2626;}
  .rp-delta.down{color:#16a34a;}
  .rp-delta.flat{color:#94a3b8;}

  .rp-layout{
    flex:1 1 auto;min-height:0;
    display:grid;grid-template-columns:minmax(0,1fr) 260px;gap:14px;align-items:stretch;
    overflow:hidden;margin-bottom:8px;padding-bottom:4px;
  }
  .rp-main{
    background:#fff;border:1px solid #eef2f7;border-radius:14px;overflow:hidden;
    box-shadow:0 1px 2px rgba(15,23,42,.04);min-width:0;min-height:0;
    display:flex;flex-direction:column;
    max-height:calc(100vh - 320px);
    max-height:calc(100dvh - 320px);
  }
  .rp-tabs{
    flex:0 0 auto;
    display:flex;gap:2px;padding:0 12px;border-bottom:1px solid #eef2f7;overflow:auto;
  }
  .rp-tabs a{
    flex:0 0 auto;padding:12px 12px;font-size:13px;font-weight:700;color:#64748b;
    text-decoration:none;border-bottom:2px solid transparent;margin-bottom:-1px;
  }
  .rp-tabs a:hover{color:#0f172a;text-decoration:none;}
  .rp-tabs a.is-active{color:#2563eb;border-bottom-color:#2563eb;}

  .rp-toolbar{
    flex:0 0 auto;
    display:flex;gap:8px;flex-wrap:wrap;align-items:center;justify-content:space-between;
    padding:12px;border-bottom:1px solid #eef2f7;background:#fafbfc;
  }
  .rp-toolbar-left{display:flex;gap:8px;flex-wrap:wrap;align-items:center;}
  .rp-toolbar select,.rp-toolbar input[type="date"],.rp-toolbar input[type="search"],.rp-side input,.rp-side select{
    height:34px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:12px;background:#fff;color:#0f172a;
  }
  .rp-search{position:relative;min-width:180px;}
  .rp-search i{position:absolute;left:10px;top:50%;transform:translateY(-50%);color:#94a3b8;font-size:12px;}
  .rp-search input{padding-left:30px;width:100%;}

  .rp-table-wrap{
    flex:1 1 auto;min-height:0;
    overflow-x:hidden;overflow-y:auto;
    overscroll-behavior:contain;
  }
  .rp-table{
    width:100%;table-layout:fixed;border-collapse:separate;border-spacing:0;min-width:0;
  }
  .rp-table th{
    text-align:left;font-size:9px;font-weight:800;letter-spacing:.03em;text-transform:uppercase;
    color:#64748b;padding:8px 6px;border-bottom:1px solid #eef2f7;background:#fff;
    position:sticky;top:0;z-index:2;white-space:nowrap;box-shadow:0 1px 0 #eef2f7;
    overflow:hidden;text-overflow:ellipsis;
  }
  .rp-table td{
    padding:8px 6px;border-bottom:1px solid #f1f5f9;vertical-align:middle;font-size:12px;color:#0f172a;
    overflow:hidden;
  }
  .rp-table tr:hover td{background:#f8fafc;}
  /* Fit all columns — no horizontal scroll */
  .rp-table th:nth-child(1),.rp-table td:nth-child(1){width:28px;}
  .rp-table th:nth-child(2),.rp-table td:nth-child(2){width:52px;}
  .rp-table th:nth-child(3),.rp-table td:nth-child(3){width:64px;}
  .rp-table th:nth-child(4),.rp-table td:nth-child(4){width:22%;}
  .rp-table th:nth-child(5),.rp-table td:nth-child(5){width:16%;}
  .rp-table th:nth-child(6),.rp-table td:nth-child(6){width:9%;}
  .rp-table th:nth-child(7),.rp-table td:nth-child(7){width:70px;}
  .rp-table th:nth-child(8),.rp-table td:nth-child(8){width:96px;}
  .rp-table th:nth-child(9),.rp-table td:nth-child(9){width:88px;}
  .rp-table th:nth-child(10),.rp-table td:nth-child(10){width:44px;}

  .rp-id{color:#2563eb;font-weight:800;text-decoration:none;white-space:nowrap;}
  .rp-id:hover{text-decoration:underline;}

  .rp-type{
    display:inline-flex;align-items:center;padding:2px 6px;border-radius:999px;font-size:10px;font-weight:800;
    max-width:100%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;
  }
  .rp-type.post{background:#f5f3ff;color:#6d28d9;}
  .rp-type.comment{background:#dcfce7;color:#15803d;}
  .rp-type.user{background:#ffedd5;color:#c2410c;}
  .rp-type.message{background:#e0f2fe;color:#0369a1;}
  .rp-type.other{background:#f1f5f9;color:#475569;}

  .rp-item{display:flex;align-items:center;gap:8px;min-width:0;max-width:100%;}
  .rp-thumb{
    width:32px;height:32px;border-radius:7px;object-fit:cover;background:#e2e8f0;flex:0 0 32px;
  }
  .rp-thumb.ph{
    display:flex;align-items:center;justify-content:center;color:#94a3b8;font-size:13px;
  }
  .rp-item .txt{
    font-size:11px;font-weight:700;line-height:1.3;color:#334155;min-width:0;
    overflow:hidden;text-overflow:ellipsis;white-space:nowrap;
  }
  .rp-item .txt a{color:#2563eb;text-decoration:none;}
  .rp-item .txt a:hover{text-decoration:underline;}

  .rp-user{display:flex;align-items:center;gap:6px;min-width:0;max-width:100%;}
  .rp-av{
    width:26px;height:26px;border-radius:999px;background:#dbeafe;color:#1d4ed8;
    display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:800;flex:0 0 26px;
  }
  .rp-user > div{min-width:0;overflow:hidden;}
  .rp-user .name{
    font-weight:800;font-size:11px;line-height:1.2;
    overflow:hidden;text-overflow:ellipsis;white-space:nowrap;
  }
  .rp-user .sub{
    font-size:10px;color:#64748b;
    overflow:hidden;text-overflow:ellipsis;white-space:nowrap;
  }

  .rp-prio{display:inline-flex;align-items:center;gap:5px;font-size:11px;font-weight:700;color:#334155;white-space:nowrap;}
  .rp-dot{width:7px;height:7px;border-radius:999px;display:inline-block;}
  .rp-dot.high{background:#dc2626;}
  .rp-dot.medium{background:#ea580c;}
  .rp-dot.low{background:#16a34a;}

  .rp-status{
    display:inline-flex;align-items:center;padding:2px 6px;border-radius:7px;font-size:10px;font-weight:800;
    max-width:100%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;
  }
  .rp-status.pending{background:#ffedd5;color:#c2410c;}
  .rp-status.progress{background:#dbeafe;color:#1d4ed8;}
  .rp-status.resolved{background:#dcfce7;color:#15803d;}
  .rp-status.dismissed{background:#f1f5f9;color:#64748b;}

  .rp-when{font-size:11px;color:#475569;line-height:1.3;}
  .rp-when span{display:block;color:#94a3b8;font-size:10px;}
  .rp-eye{
    width:30px;height:30px;border-radius:8px;border:1px solid #e2e8f0;background:#fff;
    display:inline-flex;align-items:center;justify-content:center;color:#475569;text-decoration:none;
  }
  .rp-eye:hover{background:#eff6ff;color:#2563eb;text-decoration:none;}

  .rp-foot{
    flex:0 0 auto;background:#fff;
    display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;
    padding:12px;border-top:1px solid #eef2f7;
  }
  .rp-foot .muted{font-size:12px;color:#64748b;}
  .rp-pages{display:flex;gap:4px;align-items:center;flex-wrap:wrap;}
  .rp-pages a,.rp-pages span{
    min-width:30px;height:30px;padding:0 8px;border-radius:8px;border:1px solid #e2e8f0;background:#fff;
    display:inline-flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;color:#334155;
    text-decoration:none;
  }
  .rp-pages a.is-active{background:#2563eb;border-color:#2563eb;color:#fff;}
  .rp-pages a:hover{background:#f8fafc;text-decoration:none;}

  .rp-side{
    background:#fff;border:1px solid #eef2f7;border-radius:14px;padding:14px;
    box-shadow:0 1px 2px rgba(15,23,42,.04);
    max-height:calc(100vh - 280px);max-height:calc(100dvh - 280px);
    overflow:auto;overscroll-behavior:contain;min-height:0;
  }
  .rp-side-hd{
    display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;
  }
  .rp-side-hd h2{margin:0;font-size:14px;font-weight:800;color:#0f172a;}
  .rp-side-hd a{font-size:12px;font-weight:700;color:#2563eb;text-decoration:none;}
  .rp-side label{display:block;font-size:11px;font-weight:800;color:#64748b;margin:0 0 6px;}
  .rp-side .field{margin-bottom:12px;}
  .rp-checks{display:flex;flex-direction:column;gap:6px;font-size:12px;font-weight:700;color:#334155;}
  .rp-checks label{display:flex;align-items:center;gap:8px;margin:0;font-weight:700;color:#334155;font-size:12px;cursor:pointer;}
  .rp-side .rp-btn.primary{width:100%;justify-content:center;height:38px;margin-top:4px;}
  .rp-empty{padding:40px 16px;text-align:center;color:#64748b;font-size:13px;}
  .rp-alert{flex:0 0 auto;margin:0;padding:10px 12px;border-radius:10px;font-size:13px;font-weight:700;}
  .rp-alert.ok{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0;}
  .rp-alert.bad{background:#fef2f2;color:#991b1b;border:1px solid #fecaca;}

  @media (max-width:1200px){
    .rp-cards{grid-template-columns:repeat(3,minmax(0,1fr));}
    .rp-layout{grid-template-columns:1fr;overflow:auto;}
    .rp-main,.rp-side{max-height:none;}
    .rp-side{position:static;}
  }
  @media (max-width:700px){
    .rp-cards{grid-template-columns:1fr 1fr;}
    .rp-wrap{overflow:auto;}
  }
</style>

<div class="sh-mainpanel">
  <div class="sh-pagebody">
    <div class="rp-wrap">

      <?php if ($msg !== ''): ?><div class="rp-alert ok"><?= org_admin_h($msg) ?></div><?php endif; ?>
      <?php if ($error !== ''): ?><div class="rp-alert bad"><?= org_admin_h($error) ?></div><?php endif; ?>

      <div class="rp-top">
        <?php
          $kindTabs = [
            ['key' => 'personal', 'label' => 'Personal', 'icon' => 'fa-user'],
            ['key' => 'publisher', 'label' => 'Publisher', 'icon' => 'fa-bullhorn'],
            ['key' => 'commerce', 'label' => 'Commerce', 'icon' => 'fa-shopping-bag'],
          ];
          $kindBlurbs = [
            'personal' => 'Reports about personal members, their posts, profiles, and messages.',
            'publisher' => 'Reports about publisher brands, newsroom posts, and publisher profiles.',
            'commerce' => 'Reports about sellers, products, shops, and commerce orgs.',
          ];
        ?>
        <div class="rp-kind-tabs" role="tablist" aria-label="Audience type">
          <?php foreach ($kindTabs as $tab): ?>
            <a class="<?= $kindFilter === $tab['key'] ? 'is-active' : '' ?>"
               href="<?= org_admin_h($qs(['kind' => $tab['key'], 'page' => 1])) ?>"
               role="tab"
               aria-selected="<?= $kindFilter === $tab['key'] ? 'true' : 'false' ?>">
              <i class="fa <?= org_admin_h($tab['icon']) ?>" aria-hidden="true"></i>
              <?= org_admin_h($tab['label']) ?>
              <span class="cnt"><?= number_format((int)($kindCounts[$tab['key']] ?? 0)) ?></span>
            </a>
          <?php endforeach; ?>
        </div>
        <div class="rp-actions">
          <a class="rp-btn" href="<?= org_admin_h($qs()) ?>" title="Export coming soon"><i class="fa fa-download"></i> Export</a>
          <a class="rp-btn" href="#rpFilters"><i class="fa fa-filter"></i> Filters</a>
          <a class="rp-btn" href="user_activity_table.php"><i class="fa fa-list"></i> Activity</a>
        </div>
      </div>
      <div class="rp-kind-note">
        <strong><?= org_admin_h(ucfirst($kindFilter)) ?>:</strong>
        <?= org_admin_h((string)($kindBlurbs[$kindFilter] ?? '')) ?>
      </div>

      <?php
        $cards = [
          ['key' => 'all', 'label' => 'All Reports', 'icon' => 'fa-file-text-o', 'tone' => 'purple'],
          ['key' => 'pending', 'label' => 'Pending Review', 'icon' => 'fa-flag', 'tone' => 'pink'],
          ['key' => 'reviewed', 'label' => 'In Progress', 'icon' => 'fa-clock-o', 'tone' => 'orange'],
          ['key' => 'resolved', 'label' => 'Resolved', 'icon' => 'fa-check', 'tone' => 'green'],
          ['key' => 'dismissed', 'label' => 'Dismissed', 'icon' => 'fa-times', 'tone' => 'red'],
        ];
      ?>
      <div class="rp-cards">
        <?php foreach ($cards as $c):
          $s = $stats[$c['key']] ?? ['value' => 0, 'delta_pct' => 0, 'dir' => 'flat'];
          $dir = (string)($s['dir'] ?? 'flat');
          $arrow = $dir === 'down' ? '↓' : ($dir === 'up' ? '↑' : '•');
        ?>
          <a class="rp-card" href="<?= org_admin_h($qs(['status' => $c['key'] === 'all' ? 'all' : $c['key'], 'page' => 1])) ?>" style="text-decoration:none;color:inherit;display:block;">
            <div class="rp-card-top">
              <div class="rp-ico <?= org_admin_h($c['tone']) ?>"><i class="fa <?= org_admin_h($c['icon']) ?>"></i></div>
              <div class="val"><?= number_format((int)$s['value']) ?></div>
            </div>
            <div class="lab"><?= org_admin_h($c['label']) ?></div>
            <div class="rp-delta <?= org_admin_h($dir) ?>"><?= $arrow ?> <?= (int)$s['delta_pct'] ?>% vs yesterday</div>
          </a>
        <?php endforeach; ?>
      </div>

      <div class="rp-layout">
        <div class="rp-main">
          <div class="rp-tabs">
            <?php
              $tabs = [
                'all' => 'All Reports',
                'pending' => 'Pending Review',
                'reviewed' => 'In Progress',
                'resolved' => 'Resolved',
                'dismissed' => 'Dismissed',
              ];
              foreach ($tabs as $k => $lab):
            ?>
              <a href="<?= org_admin_h($qs(['status' => $k, 'page' => 1])) ?>" class="<?= $filter === $k ? 'is-active' : '' ?>"><?= org_admin_h($lab) ?></a>
            <?php endforeach; ?>
          </div>

          <form class="rp-toolbar" method="get" action="reports.php">
            <input type="hidden" name="kind" value="<?= org_admin_h($kindFilter) ?>">
            <input type="hidden" name="status" value="<?= org_admin_h($filter) ?>">
            <input type="hidden" name="reporter" value="<?= org_admin_h($reporterQ) ?>">
            <div class="rp-toolbar-left">
              <select name="type">
                <option value="all">All Report Types</option>
                <?php foreach ($types as $k => $lab): ?>
                  <option value="<?= $k ?>"<?= $typeFilter === $k ? ' selected' : '' ?>><?= org_admin_h($lab) ?></option>
                <?php endforeach; ?>
              </select>
              <input type="date" name="from" value="<?= org_admin_h($dateFrom) ?>" title="From">
              <input type="date" name="to" value="<?= org_admin_h($dateTo) ?>" title="To">
              <select name="priority">
                <option value="all">All Priorities</option>
                <?php foreach (['high' => 'High', 'medium' => 'Medium', 'low' => 'Low'] as $k => $lab): ?>
                  <option value="<?= $k ?>"<?= $priorityFilter === $k ? ' selected' : '' ?>><?= org_admin_h($lab) ?></option>
                <?php endforeach; ?>
              </select>
              <select name="reason">
                <option value="all">All Reasons</option>
                <?php foreach ($reasons as $r): ?>
                  <option value="<?= org_admin_h($r) ?>"<?= $reasonFilter === $r ? ' selected' : '' ?>><?= org_admin_h(ucwords(str_replace('_', ' ', $r))) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="rp-search">
              <i class="fa fa-search"></i>
              <input type="search" name="q" value="<?= org_admin_h($search) ?>" placeholder="Search in table…">
            </div>
            <button type="submit" class="rp-btn primary">Apply</button>
          </form>

          <div class="rp-table-wrap">
            <table class="rp-table">
              <thead>
                <tr>
                  <th style="width:36px;"><input type="checkbox" disabled title="Bulk select coming soon"></th>
                  <th>Report ID</th>
                  <th>Type</th>
                  <th>Reported Item</th>
                  <th>Reporter</th>
                  <th>Reason</th>
                  <th>Priority</th>
                  <th>Status</th>
                  <th>Reported At</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
              <?php if (!$rows): ?>
                <tr><td colspan="10"><div class="rp-empty">No reports match these filters.</div></td></tr>
              <?php else: foreach ($rows as $row): ?>
                <?php
                  $rid = (int)($row['id'] ?? 0);
                  $tt = strtolower(trim((string)($row['target_type'] ?? 'other')));
                  $tid = (int)($row['target_id'] ?? 0);
                  $st = strtolower(trim((string)($row['status'] ?? 'pending')));
                  $prio = (string)($row['_priority'] ?? 'low');
                  $reporterUid = (int)($row['reporter_id'] ?? 0);
                  $targetUid = (int)($row['target_user_id'] ?? 0);
                  $rUser = trim((string)($row['reporter_username'] ?? ''));
                  $rName = trim((string)($row['reporter_name'] ?? ''));
                  $rLabel = trim((string)($row['reporter_label'] ?? ''));
                  $reporterShow = $rUser !== '' ? '@' . $rUser : ($rLabel !== '' ? $rLabel : ('User #' . $reporterUid));
                  $reporterSub = $rName !== '' ? $rName : (string)($row['reporter_email'] ?? '');
                  $initial = mb_strtoupper(mb_substr($rUser !== '' ? $rUser : ($reporterShow !== '' ? $reporterShow : 'U'), 0, 1));

                  $tUser = trim((string)($row['target_username'] ?? ''));
                  $itemLabel = ucfirst($tt) . ' #' . $tid;
                  if ($tt === 'post') {
                      $itemLabel = 'Post #' . $tid . ($tUser !== '' ? ' by @' . $tUser : '');
                  } elseif ($tt === 'user') {
                      $itemLabel = 'User' . ($tUser !== '' ? ' @' . $tUser : ' #' . ($targetUid ?: $tid));
                  } elseif ($tt === 'message') {
                      $itemLabel = 'Message #' . $tid;
                  } elseif ($tt === 'product') {
                      $itemLabel = 'Product #' . $tid;
                  } elseif ($tt === 'org') {
                      $itemLabel = (string)($row['target_org_name'] ?? ('Org #' . (int)($row['target_org_id'] ?? $tid)));
                  }

                  $thumb = '';
                  if ($tt === 'post' && isset($thumbByPost[$tid])) {
                      $thumb = $mediaUrl((string)$thumbByPost[$tid]);
                  }
                  $created = (string)($row['created_at'] ?? '');
                  $createdTs = $created !== '' ? strtotime($created) : false;
                  $dateLine = $createdTs ? date('M j, Y', $createdTs) : org_admin_fmt_dt($created);
                  $timeLine = $createdTs ? date('g:i A', $createdTs) : '';
                  $typeIcon = $tt === 'post' ? 'fa-image' : ($tt === 'message' ? 'fa-comment' : ($tt === 'user' ? 'fa-user' : 'fa-file-o'));
                ?>
                <tr>
                  <td><input type="checkbox" disabled></td>
                  <td><a class="rp-id" href="report_detail.php?id=<?= $rid ?>">R-<?= $rid ?></a></td>
                  <td><span class="rp-type <?= org_admin_h($typeBadgeClass($tt)) ?>"><?= org_admin_h(ucfirst($tt)) ?></span></td>
                  <td>
                    <div class="rp-item">
                      <?php if ($thumb !== ''): ?>
                        <img class="rp-thumb" src="<?= org_admin_h($thumb) ?>" alt="" width="36" height="36" loading="lazy">
                      <?php else: ?>
                        <div class="rp-thumb ph"><i class="fa <?= $typeIcon ?>"></i></div>
                      <?php endif; ?>
                      <div class="txt">
                        <?php if ($tt === 'post' && $tid > 0 && $targetUid > 0): ?>
                          <a href="user_activity.php?user_id=<?= $targetUid ?>&amp;post_id=<?= $tid ?>"><?= org_admin_h($itemLabel) ?></a>
                        <?php elseif ($targetUid > 0): ?>
                          <a href="user_activity.php?user_id=<?= $targetUid ?>"><?= org_admin_h($itemLabel) ?></a>
                        <?php else: ?>
                          <?= org_admin_h($itemLabel) ?>
                        <?php endif; ?>
                      </div>
                    </div>
                  </td>
                  <td>
                    <div class="rp-user">
                      <div class="rp-av"><?= org_admin_h($initial) ?></div>
                      <div>
                        <div class="name"><a href="report_detail.php?id=<?= $rid ?>"><?= org_admin_h($reporterShow) ?></a></div>
                        <div class="sub"><?= org_admin_h($reporterSub) ?></div>
                      </div>
                    </div>
                  </td>
                  <td><span style="display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= org_admin_h(ucwords(str_replace('_', ' ', (string)($row['reason'] ?? 'other')))) ?></span></td>
                  <td>
                    <span class="rp-prio">
                      <span class="rp-dot <?= org_admin_h($prio) ?>"></span>
                      <?= org_admin_h(ucfirst($prio)) ?>
                    </span>
                  </td>
                  <td><span class="rp-status <?= org_admin_h($statusBadgeClass($st)) ?>"><?= org_admin_h(msb_reports_status_label($st)) ?></span></td>
                  <td>
                    <div class="rp-when">
                      <?= org_admin_h($dateLine) ?>
                      <?php if ($timeLine !== ''): ?><span><?= org_admin_h($timeLine) ?></span><?php endif; ?>
                    </div>
                  </td>
                  <td>
                    <a class="rp-eye" href="report_detail.php?id=<?= $rid ?>" title="View details"><i class="fa fa-eye"></i></a>
                  </td>
                </tr>
              <?php endforeach; endif; ?>
              </tbody>
            </table>
          </div>

          <?php
            $fromN = $total === 0 ? 0 : ($offset + 1);
            $toN = min($total, $offset + $perPage);
          ?>
          <div class="rp-foot">
            <div class="muted">Showing <?= (int)$fromN ?> to <?= (int)$toN ?> of <?= number_format($total) ?> results.</div>
            <div class="rp-pages">
              <?php if ($page > 1): ?>
                <a href="<?= org_admin_h($qs(['page' => $page - 1])) ?>">&lsaquo;</a>
              <?php endif; ?>
              <?php
                $start = max(1, $page - 2);
                $end = min($totalPages, $start + 4);
                $start = max(1, $end - 4);
                for ($i = $start; $i <= $end; $i++):
              ?>
                <a href="<?= org_admin_h($qs(['page' => $i])) ?>" class="<?= $i === $page ? 'is-active' : '' ?>"><?= $i ?></a>
              <?php endfor; ?>
              <?php if ($page < $totalPages): ?>
                <a href="<?= org_admin_h($qs(['page' => $page + 1])) ?>">&rsaquo;</a>
              <?php endif; ?>
            </div>
            <form method="get" action="reports.php" style="display:flex;gap:6px;align-items:center;">
              <input type="hidden" name="kind" value="<?= org_admin_h($kindFilter) ?>">
              <input type="hidden" name="status" value="<?= org_admin_h($filter) ?>">
              <input type="hidden" name="q" value="<?= org_admin_h($search) ?>">
              <input type="hidden" name="type" value="<?= org_admin_h($typeFilter) ?>">
              <input type="hidden" name="reason" value="<?= org_admin_h($reasonFilter) ?>">
              <input type="hidden" name="priority" value="<?= org_admin_h($priorityFilter) ?>">
              <input type="hidden" name="reporter" value="<?= org_admin_h($reporterQ) ?>">
              <input type="hidden" name="from" value="<?= org_admin_h($dateFrom) ?>">
              <input type="hidden" name="to" value="<?= org_admin_h($dateTo) ?>">
              <select name="per_page" onchange="this.form.submit()" style="height:30px;border:1px solid #e2e8f0;border-radius:8px;font-size:12px;">
                <?php foreach ([10, 25, 50, 100] as $n): ?>
                  <option value="<?= $n ?>"<?= $perPage === $n ? ' selected' : '' ?>><?= $n ?> / page</option>
                <?php endforeach; ?>
              </select>
            </form>
          </div>
        </div>

        <aside class="rp-side" id="rpFilters">
          <div class="rp-side-hd">
            <h2>Filters</h2>
            <a href="<?= org_admin_h($qs(['type' => 'all', 'reason' => 'all', 'priority' => 'all', 'reporter' => '', 'from' => '', 'to' => '', 'q' => '', 'page' => 1])) ?>">Clear all</a>
          </div>
          <form method="get" action="reports.php">
            <input type="hidden" name="kind" value="<?= org_admin_h($kindFilter) ?>">
            <input type="hidden" name="status" value="<?= org_admin_h($filter) ?>">
            <input type="hidden" name="q" value="<?= org_admin_h($search) ?>">

            <div class="field">
              <label>Report Type</label>
              <div class="rp-checks">
                <label><input type="radio" name="type" value="all"<?= $typeFilter === 'all' ? ' checked' : '' ?>> All Types</label>
                <?php foreach ($types as $k => $lab): ?>
                  <label><input type="radio" name="type" value="<?= $k ?>"<?= $typeFilter === $k ? ' checked' : '' ?>> <?= org_admin_h($lab) ?></label>
                <?php endforeach; ?>
              </div>
            </div>

            <div class="field">
              <label>Reason</label>
              <select name="reason" style="width:100%;">
                <option value="all">All Reasons</option>
                <?php foreach ($reasons as $r): ?>
                  <option value="<?= org_admin_h($r) ?>"<?= $reasonFilter === $r ? ' selected' : '' ?>><?= org_admin_h(ucwords(str_replace('_', ' ', $r))) ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="field">
              <label>Priority</label>
              <select name="priority" style="width:100%;">
                <option value="all">All Priorities</option>
                <option value="high"<?= $priorityFilter === 'high' ? ' selected' : '' ?>>High</option>
                <option value="medium"<?= $priorityFilter === 'medium' ? ' selected' : '' ?>>Medium</option>
                <option value="low"<?= $priorityFilter === 'low' ? ' selected' : '' ?>>Low</option>
              </select>
            </div>

            <div class="field">
              <label>Status</label>
              <select name="status" style="width:100%;">
                <option value="all"<?= $filter === 'all' ? ' selected' : '' ?>>All Statuses</option>
                <option value="pending"<?= $filter === 'pending' ? ' selected' : '' ?>>Pending Review</option>
                <option value="reviewed"<?= $filter === 'reviewed' ? ' selected' : '' ?>>In Progress</option>
                <option value="resolved"<?= $filter === 'resolved' ? ' selected' : '' ?>>Resolved</option>
                <option value="dismissed"<?= $filter === 'dismissed' ? ' selected' : '' ?>>Dismissed</option>
              </select>
            </div>

            <div class="field">
              <label>Reported By</label>
              <input type="search" name="reporter" value="<?= org_admin_h($reporterQ) ?>" placeholder="Search user…" style="width:100%;">
            </div>

            <div class="field">
              <label>Date range</label>
              <div style="display:grid;gap:6px;">
                <input type="date" name="from" value="<?= org_admin_h($dateFrom) ?>">
                <input type="date" name="to" value="<?= org_admin_h($dateTo) ?>">
              </div>
            </div>

            <button type="submit" class="rp-btn primary">Apply Filters</button>
          </form>
        </aside>
      </div>
    </div>
  </div>
</div>
<?php org_admin_render_foot(); ?>
