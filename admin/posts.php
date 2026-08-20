<?php
declare(strict_types=1);

/**
 * Admin — Posts list (viewport-fit; table/panel scroll only).
 */
require_once __DIR__ . '/includes/org_admin_helpers_load.php';
require_once __DIR__ . '/includes/posts_admin_helpers.php';
require_once __DIR__ . '/includes/msb_moderation_activity.php';
org_admin_require_admin();

error_reporting(E_ALL);
ini_set('display_errors', '1');

$dbh = org_admin_db();

$status = strtolower(trim((string)($_GET['status'] ?? 'all')));
if (!in_array($status, ['all', 'pending', 'published', 'flagged', 'removed'], true)) {
    $status = 'all';
}
$kindFilter = posts_admin_normalize_kind((string)($_GET['kind'] ?? 'personal'));
$q = trim((string)($_GET['q'] ?? ''));
$visibility = strtolower(trim((string)($_GET['visibility'] ?? 'all')));
if (!in_array($visibility, ['all', 'public', 'friends', 'private'], true)) {
    $visibility = 'all';
}
$postType = strtolower(trim((string)($_GET['post_type'] ?? 'all')));
if (!in_array($postType, ['all', 'text', 'image', 'video', 'link'], true)) {
    $postType = 'all';
}
$dateFrom = trim((string)($_GET['from'] ?? ''));
$dateTo = trim((string)($_GET['to'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = (int)($_GET['per_page'] ?? 10);
if (!in_array($perPage, [10, 25, 50], true)) {
    $perPage = 10;
}
$postId = (int)($_GET['post_id'] ?? 0);

$kindCounts = posts_admin_kind_counts($dbh);
$stats = posts_admin_stats($dbh, $kindFilter);
$list = posts_admin_list($dbh, $status, $q, $visibility, $postType, $dateFrom, $dateTo, $page, $perPage, $kindFilter);
$rows = $list['rows'];
$total = (int)$list['total'];
$totalPages = max(1, (int)ceil($total / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
    $list = posts_admin_list($dbh, $status, $q, $visibility, $postType, $dateFrom, $dateTo, $page, $perPage, $kindFilter);
    $rows = $list['rows'];
    $total = (int)$list['total'];
}
$offset = ($page - 1) * $perPage;
$fromN = $total === 0 ? 0 : ($offset + 1);
$toN = min($total, $offset + count($rows));

$createHref = is_file(__DIR__ . '/../public_user/post_create.php')
    ? '../public_user/post_create.php'
    : '#';
$createTitle = $createHref === '#' ? 'Coming soon' : 'Create Post';

$qs = static function (array $extra = []) use ($status, $kindFilter, $q, $visibility, $postType, $dateFrom, $dateTo, $perPage, $page, $postId): string {
    $base = [
        'kind' => $kindFilter,
        'status' => $status,
        'q' => $q,
        'visibility' => $visibility,
        'post_type' => $postType,
        'from' => $dateFrom,
        'to' => $dateTo,
        'per_page' => $perPage,
        'page' => $page,
        'post_id' => $postId > 0 ? $postId : '',
    ];
    foreach ($extra as $k => $v) {
        $base[$k] = $v;
    }
    foreach ($base as $k => $v) {
        if ($v === '' || $v === null) {
            unset($base[$k]);
            continue;
        }
        if ($k === 'kind' && $v === 'personal' && !array_key_exists('kind', $extra)) {
            unset($base[$k]);
            continue;
        }
        if ($k === 'status' && $v === 'all' && !array_key_exists('status', $extra)) {
            unset($base[$k]);
            continue;
        }
        if (in_array($k, ['visibility', 'post_type'], true) && $v === 'all') {
            unset($base[$k]);
            continue;
        }
        if ($k === 'page' && (int)$v === 1 && !array_key_exists('page', $extra)) {
            unset($base[$k]);
            continue;
        }
        if ($k === 'per_page' && (int)$v === 10 && !array_key_exists('per_page', $extra)) {
            unset($base[$k]);
            continue;
        }
        if ($k === 'post_id' && (int)$v <= 0) {
            unset($base[$k]);
        }
    }
    return 'posts.php' . ($base ? ('?' . http_build_query($base)) : '');
};

$detail = null;
$detailEng = ['likes' => 0, 'comments' => 0, 'shares' => 0, 'saves' => 0];
$detailStatus = ['key' => 'published', 'label' => 'Published', 'cls' => 'published'];
$detailType = 'Text';
$detailHero = '';
$detailVideo = '';
$detailText = '';
$detailReportCount = 0;
$detailPendingCount = 0;

if ($postId > 0) {
    $detail = msb_mod_post_detail($dbh, $postId);
    if ($detail) {
        $detailEng = posts_admin_engagement($dbh, $postId);
        $detailReportCount = 0;
        $detailPendingCount = 0;
        if (msb_mod_table_exists($dbh, 'public_user_reports')) {
            $detailReportCount = msb_mod_count_safe(
                $dbh,
                'SELECT COUNT(*) FROM public_user_reports WHERE target_type = \'post\' AND target_id = :id',
                [':id' => $postId]
            );
            $detailPendingCount = msb_mod_count_safe(
                $dbh,
                'SELECT COUNT(*) FROM public_user_reports WHERE target_type = \'post\' AND target_id = :id AND status = \'pending\'',
                [':id' => $postId]
            );
        }
        $detail['_report_count'] = $detailReportCount;
        $detail['_pending_count'] = $detailPendingCount;
        $statusRow = [
            'is_deleted' => (int)($detail['is_deleted'] ?? 0),
            'report_count' => $detailReportCount,
            'pending_count' => $detailPendingCount,
        ];
        $detailStatus = posts_admin_status_from_row($statusRow);

        $atts = $detail['attachments'] ?? [];
        $types = [];
        $detailVideo = '';
        foreach ($atts as $a) {
            $t = strtolower(trim((string)($a['type'] ?? 'file')));
            $types[] = $t;
            $fp = (string)($a['file_path'] ?? '');
            $tp = (string)($a['thumb_path'] ?? '');
            if ($detailHero === '' && $t === 'image' && $fp !== '') {
                $detailHero = $fp;
            } elseif ($detailHero === '' && $tp !== '') {
                $detailHero = $tp;
            }
            if ($t === 'video' && $fp !== '' && $detailVideo === '') {
                $detailVideo = $fp;
            }
        }
        if ($detailHero === '' && $detailVideo !== '') {
            $detailHero = $detailVideo;
        }
        $types = array_values(array_unique($types));
        $detailType = 'Text';
        if (in_array('video', $types, true)) {
            $detailType = 'Video';
        } elseif (in_array('image', $types, true)) {
            $detailType = 'Image';
        } else {
            $blob = (string)($detail['body'] ?? $detail['description'] ?? '');
            if (preg_match('~https?://~i', $blob)) {
                $detailType = 'Link';
            }
        }
        $detailText = trim((string)($detail['body'] ?? ''));
        if ($detailText === '') {
            $detailText = trim((string)($detail['description'] ?? ''));
        }
        if ($detailText === '') {
            $detailText = trim((string)($detail['title'] ?? ''));
        }
    }
}

$typeIcon = static function (string $type): string {
    $t = strtolower($type);
    if ($t === 'image') {
        return 'fa-image';
    }
    if ($t === 'video') {
        return 'fa-video-camera';
    }
    if ($t === 'link') {
        return 'fa-link';
    }
    return 'fa-align-left';
};

$visIcon = static function (string $vis): string {
    $v = strtolower($vis);
    if ($v === 'friends') {
        return 'fa-users';
    }
    if ($v === 'private') {
        return 'fa-lock';
    }
    return 'fa-globe';
};

$deltaHtml = static function (int $pct): string {
    if ($pct > 0) {
        return '<span class="ps-delta up">↑ ' . (int)$pct . '% vs last 7 days</span>';
    }
    if ($pct < 0) {
        return '<span class="ps-delta down">↓ ' . (int)abs($pct) . '% vs last 7 days</span>';
    }
    return '<span class="ps-delta flat">• 0% vs last 7 days</span>';
};

$hasFilters = $q !== '' || $status !== 'all' || $visibility !== 'all' || $postType !== 'all' || $dateFrom !== '' || $dateTo !== '';

org_admin_render_head('Posts');
require_once __DIR__ . '/includes/admin_chrome.php';
admin_chrome_open(null, [
    'title' => 'Posts',
    'description' => 'Switch Personal, Publisher, or Commerce to manage posts for that audience.',
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
  .ps-wrap{
    flex:1 1 auto;min-height:0;height:100%;width:100%;max-width:100%;
    display:flex;flex-direction:column;gap:8px;overflow:hidden !important;padding:0 2px;box-sizing:border-box;
  }
  .ps-top{flex:0 0 auto;display:flex;align-items:center;justify-content:space-between;gap:10px;min-width:0;flex-wrap:wrap;}
  .ps-kind-tabs{display:flex;align-items:center;gap:6px;flex-wrap:wrap;}
  .ps-kind-tabs a{
    display:inline-flex;align-items:center;gap:6px;height:28px;padding:0 12px;border-radius:999px;
    font-size:11px;font-weight:800;color:#64748b;background:#fff;border:1px solid #e2e8f0;text-decoration:none;
  }
  .ps-kind-tabs a:hover{border-color:#93c5fd;color:#1e40af;text-decoration:none;}
  .ps-kind-tabs a.is-active{background:#2563eb;border-color:#2563eb;color:#fff;}
  .ps-kind-tabs a .cnt{
    display:inline-flex;align-items:center;justify-content:center;min-width:18px;height:16px;padding:0 5px;
    border-radius:999px;font-size:9px;font-weight:800;background:#f1f5f9;color:#475569;
  }
  .ps-kind-tabs a.is-active .cnt{background:rgba(255,255,255,.22);color:#fff;}
  .ps-kind-note{flex:0 0 auto;font-size:11px;font-weight:600;color:#64748b;line-height:1.35;padding:0 2px;}
  .ps-kind-note strong{color:#0f172a;font-weight:800;}
  .ps-actions{display:flex;gap:6px;flex-wrap:wrap;align-items:center;}
  .ps-btn{
    height:30px;padding:0 10px;border-radius:8px;border:1px solid #e2e8f0;background:#fff;
    font-size:11px;font-weight:700;color:#334155;display:inline-flex;align-items:center;gap:5px;
    text-decoration:none;cursor:pointer;white-space:nowrap;
  }
  .ps-btn:hover{background:#f8fafc;text-decoration:none;color:#0f172a;}
  .ps-btn.primary{background:#2563eb;border-color:#2563eb;color:#fff;}
  .ps-btn.primary:hover{background:#1d4ed8;color:#fff;}
  .ps-btn.sm{height:26px;padding:0 8px;font-size:10px;}
  .ps-btn.ghost{background:#fff;}

  .ps-cards{flex:0 0 auto;display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:8px;}
  .ps-card{
    background:#fff;border:1px solid #eef2f7;border-radius:12px;padding:10px 12px;
    box-shadow:0 1px 2px rgba(15,23,42,.04);min-width:0;text-decoration:none;color:inherit;display:block;
    transition:border-color .15s, box-shadow .15s;
  }
  .ps-card:hover{border-color:#bfdbfe;text-decoration:none;color:inherit;}
  .ps-card.is-active{border-color:#2563eb;box-shadow:0 0 0 1px #2563eb inset;}
  .ps-card-top{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:6px;}
  .ps-card-top .lab{font-size:11px;font-weight:700;color:#64748b;}
  .ps-ico{
    width:28px;height:28px;border-radius:999px;display:flex;align-items:center;justify-content:center;font-size:12px;flex:0 0 auto;
  }
  .ps-ico.purple{background:#f5f3ff;color:#7c3aed;}
  .ps-ico.orange{background:#fff7ed;color:#ea580c;}
  .ps-ico.green{background:#f0fdf4;color:#16a34a;}
  .ps-ico.red{background:#fef2f2;color:#dc2626;}
  .ps-ico.gray{background:#f1f5f9;color:#64748b;}
  .ps-card .val{font-size:20px;font-weight:800;color:#0f172a;line-height:1;}
  .ps-delta{display:block;font-size:10px;font-weight:700;margin-top:4px;}
  .ps-delta.up{color:#16a34a;}
  .ps-delta.down{color:#dc2626;}
  .ps-delta.flat{color:#94a3b8;}

  .ps-board{
    flex:1 1 auto;min-height:0;min-width:0;
    display:grid;grid-template-columns:minmax(0,1fr);gap:10px;overflow:hidden;
  }
  .ps-board.has-detail{grid-template-columns:minmax(0,65%) minmax(280px,35%);}
  .ps-main{
    min-width:0;min-height:0;background:#fff;border:1px solid #eef2f7;border-radius:12px;
    box-shadow:0 1px 2px rgba(15,23,42,.04);display:flex;flex-direction:column;overflow:hidden !important;
  }
  .ps-filters{
    flex:0 0 auto;display:flex;gap:6px;flex-wrap:wrap;align-items:center;
    padding:10px 12px;border-bottom:1px solid #eef2f7;background:#fafbfc;
  }
  .ps-search{position:relative;flex:1 1 160px;min-width:140px;max-width:240px;}
  .ps-search i{position:absolute;left:9px;top:50%;transform:translateY(-50%);color:#94a3b8;font-size:12px;}
  .ps-search input,.ps-filters select,.ps-filters input[type="date"]{
    height:30px;border:1px solid #e2e8f0;border-radius:8px;padding:0 9px;font-size:11px;background:#fff;color:#0f172a;
  }
  .ps-search input{width:100%;padding-left:28px;}
  .ps-clear{font-size:11px;font-weight:700;color:#2563eb;text-decoration:none;margin-left:auto;}
  .ps-clear:hover{text-decoration:underline;}

  .ps-table-wrap{flex:1 1 auto;min-height:0;overflow:auto;overscroll-behavior:contain;}
  .ps-table{width:100%;table-layout:fixed;border-collapse:separate;border-spacing:0;}
  .ps-table th{
    text-align:left;font-size:9px;font-weight:800;letter-spacing:.03em;text-transform:uppercase;
    color:#64748b;padding:8px 6px;border-bottom:1px solid #eef2f7;background:#fff;
    position:sticky;top:0;z-index:3;white-space:nowrap;
  }
  .ps-table td{padding:8px 6px;border-bottom:1px solid #f1f5f9;vertical-align:middle;font-size:11px;color:#0f172a;overflow:hidden;}
  .ps-table tr:hover td{background:#f8fafc;}
  .ps-table tr.is-selected td{background:#eff6ff;}
  .ps-table th:nth-child(1),.ps-table td:nth-child(1){width:32px;}
  .ps-table th:nth-child(2),.ps-table td:nth-child(2){width:72px;}
  .ps-table th:nth-child(3),.ps-table td:nth-child(3){width:22%;}
  .ps-table th:nth-child(4),.ps-table td:nth-child(4){width:14%;}
  .ps-table th:nth-child(5),.ps-table td:nth-child(5){width:70px;}
  .ps-table th:nth-child(6),.ps-table td:nth-child(6){width:72px;}
  .ps-table th:nth-child(7),.ps-table td:nth-child(7){width:88px;}
  .ps-table th:nth-child(8),.ps-table td:nth-child(8){width:90px;}
  .ps-table th:nth-child(9),.ps-table td:nth-child(9){width:56px;}
  .ps-table th:nth-child(10),.ps-table td:nth-child(10){width:40px;}
  .ps-table td:last-child{overflow:visible;}

  .ps-id{color:#2563eb;font-weight:800;text-decoration:none;white-space:nowrap;}
  .ps-id:hover{text-decoration:underline;}
  .ps-item{display:flex;align-items:center;gap:8px;min-width:0;}
  .ps-thumb{
    width:32px;height:32px;border-radius:7px;object-fit:cover;background:#e2e8f0;flex:0 0 32px;
  }
  video.ps-thumb{display:block;object-fit:cover;pointer-events:none;background:#0f172a;}
  .ps-thumb.ph{display:flex;align-items:center;justify-content:center;color:#94a3b8;font-size:13px;}
  .ps-thumb-wrap{position:relative;width:32px;height:32px;flex:0 0 32px;}
  .ps-thumb-wrap .ps-thumb{width:100%;height:100%;}
  .ps-thumb-wrap .ps-play{
    position:absolute;inset:0;display:flex;align-items:center;justify-content:center;
    color:#fff;font-size:10px;text-shadow:0 1px 2px rgba(0,0,0,.45);pointer-events:none;
  }
  .ps-item .txt{
    font-size:11px;font-weight:700;line-height:1.3;color:#334155;min-width:0;
    overflow:hidden;text-overflow:ellipsis;white-space:nowrap;
  }
  .ps-user{display:flex;align-items:center;gap:7px;min-width:0;}
  .ps-av{
    width:26px;height:26px;border-radius:999px;color:#fff;font-size:9px;font-weight:800;
    display:flex;align-items:center;justify-content:center;flex:0 0 26px;
  }
  .ps-user > div{min-width:0;overflow:hidden;}
  .ps-user .nm{font-weight:800;font-size:11px;color:#0f172a;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
  .ps-user .un{font-size:10px;color:#64748b;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
  .ps-meta{display:inline-flex;align-items:center;gap:4px;font-size:11px;color:#475569;white-space:nowrap;}
  .ps-meta i{color:#94a3b8;}
  .ps-when{font-size:11px;color:#475569;line-height:1.3;}
  .ps-when span{display:block;color:#94a3b8;font-size:10px;}
  .ps-pill{
    display:inline-flex;align-items:center;padding:2px 7px;border-radius:999px;font-size:10px;font-weight:800;
    max-width:100%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;
  }
  .ps-pill.published{background:#dcfce7;color:#15803d;}
  .ps-pill.pending{background:#ffedd5;color:#c2410c;}
  .ps-pill.flagged{background:#fee2e2;color:#b91c1c;}
  .ps-pill.removed{background:#f1f5f9;color:#64748b;}
  .ps-empty{padding:28px 12px;text-align:center;color:#64748b;font-size:12px;font-weight:700;}
  .ps-foot{
    flex:0 0 auto;display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap;
    padding:8px 12px;border-top:1px solid #eef2f7;font-size:11px;color:#64748b;font-weight:600;
  }
  .ps-pages{display:flex;gap:4px;align-items:center;flex-wrap:wrap;}
  .ps-pages a,.ps-pages span{
    min-width:28px;height:28px;padding:0 7px;border-radius:8px;border:1px solid #e2e8f0;background:#fff;
    display:inline-flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;color:#334155;
    text-decoration:none;
  }
  .ps-pages a.is-active{background:#2563eb;border-color:#2563eb;color:#fff;}
  .ps-pages a:hover{background:#f8fafc;text-decoration:none;}
  .ps-perpage{height:28px;border:1px solid #e2e8f0;border-radius:8px;padding:0 6px;font-size:11px;background:#fff;}

  .ps-side{
    min-width:0;min-height:0;background:#fff;border:1px solid #eef2f7;border-radius:12px;
    box-shadow:0 1px 2px rgba(15,23,42,.04);display:flex;flex-direction:column;overflow:hidden;
  }
  .ps-side-hd{
    flex:0 0 auto;display:flex;align-items:center;justify-content:space-between;gap:8px;
    padding:12px 14px;border-bottom:1px solid #eef2f7;
  }
  .ps-side-hd h2{margin:0;font-size:14px;font-weight:800;color:#0f172a;}
  .ps-side-close{
    width:28px;height:28px;border-radius:8px;border:1px solid #e2e8f0;background:#fff;color:#64748b;
    display:inline-flex;align-items:center;justify-content:center;text-decoration:none;
  }
  .ps-side-close:hover{background:#f8fafc;color:#0f172a;text-decoration:none;}
  .ps-side-bd{flex:1 1 auto;min-height:0;overflow:auto;overscroll-behavior:contain;padding:14px;}
  .ps-side-ft{
    flex:0 0 auto;display:flex;gap:6px;flex-wrap:wrap;align-items:center;
    padding:12px 14px;border-top:1px solid #eef2f7;background:#fafbfc;
  }
  .ps-idrow{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:10px;}
  .ps-copy{
    height:24px;padding:0 8px;border-radius:6px;border:1px solid #e2e8f0;background:#fff;
    font-size:10px;font-weight:700;color:#475569;cursor:pointer;
  }
  .ps-dates{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin:10px 0 14px;}
  .ps-dates .lab{font-size:10px;font-weight:800;color:#94a3b8;text-transform:uppercase;letter-spacing:.03em;}
  .ps-dates .val{font-size:11px;font-weight:700;color:#334155;margin-top:2px;}
  .ps-author{display:flex;align-items:center;gap:8px;margin-bottom:12px;padding:8px;border-radius:10px;background:#f8fafc;}
  .ps-body{font-size:12px;line-height:1.5;color:#0f172a;white-space:pre-wrap;word-break:break-word;margin-bottom:12px;}
  .ps-hero{
    width:100%;max-height:220px;object-fit:cover;border-radius:10px;background:#e2e8f0;margin-bottom:12px;display:block;
  }
  video.ps-hero{max-height:280px;object-fit:contain;background:#0f172a;}
  .ps-eng{
    display:flex;gap:14px;flex-wrap:wrap;align-items:center;margin-bottom:14px;
    font-size:11px;font-weight:700;color:#64748b;
  }
  .ps-eng span{display:inline-flex;align-items:center;gap:5px;}
  .ps-kv{display:flex;flex-direction:column;gap:0;border:1px solid #eef2f7;border-radius:10px;overflow:hidden;}
  .ps-kv-row{
    display:flex;justify-content:space-between;gap:10px;padding:8px 10px;
    border-bottom:1px solid #f1f5f9;font-size:11px;
  }
  .ps-kv-row:last-child{border-bottom:0;}
  .ps-kv-row .k{color:#64748b;font-weight:700;}
  .ps-kv-row .v{color:#0f172a;font-weight:800;text-align:right;}
  .ps-side-missing{padding:24px 12px;text-align:center;color:#64748b;font-size:12px;font-weight:700;}

  @media (max-width:1100px){
    .ps-cards{grid-template-columns:repeat(3,minmax(0,1fr));}
    .ps-board.has-detail{grid-template-columns:1fr;overflow:auto;}
  }
  @media (max-width:700px){
    .ps-cards{grid-template-columns:1fr 1fr;}
    .ps-wrap{overflow:auto !important;}
  }
</style>

<div class="sh-mainpanel">
  <div class="sh-pagebody">
    <div class="ps-wrap">

      <div class="ps-top">
        <?php
          $kindTabs = [
            ['key' => 'personal', 'label' => 'Personal', 'icon' => 'fa-user'],
            ['key' => 'publisher', 'label' => 'Publisher', 'icon' => 'fa-bullhorn'],
            ['key' => 'commerce', 'label' => 'Commerce', 'icon' => 'fa-shopping-bag'],
          ];
          $kindBlurbs = [
            'personal' => 'Posts from personal members — social updates, friends feeds, and profile content.',
            'publisher' => 'Posts from publisher brands and newsrooms — public content feeds and authority profiles.',
            'commerce' => 'Posts from sellers and shops — listings, product updates, and commerce profiles.',
          ];
        ?>
        <div class="ps-kind-tabs" role="tablist" aria-label="Audience type">
          <?php foreach ($kindTabs as $tab): ?>
            <a class="<?= $kindFilter === $tab['key'] ? 'is-active' : '' ?>"
               href="<?= posts_admin_h($qs(['kind' => $tab['key'], 'page' => 1, 'post_id' => ''])) ?>"
               role="tab"
               aria-selected="<?= $kindFilter === $tab['key'] ? 'true' : 'false' ?>">
              <i class="fa <?= posts_admin_h($tab['icon']) ?>" aria-hidden="true"></i>
              <?= posts_admin_h($tab['label']) ?>
              <span class="cnt"><?= number_format((int)($kindCounts[$tab['key']] ?? 0)) ?></span>
            </a>
          <?php endforeach; ?>
        </div>
        <div class="ps-actions">
          <button type="button" class="ps-btn" disabled title="Coming soon"><i class="fa fa-download"></i> Export</button>
          <button type="button" class="ps-btn" disabled title="Coming soon"><i class="fa fa-columns"></i> Columns</button>
          <a class="ps-btn primary" href="<?= posts_admin_h($createHref) ?>" title="<?= posts_admin_h($createTitle) ?>"<?= $createHref === '#' ? ' onclick="return false;"' : '' ?>>
            <i class="fa fa-plus"></i> Create Post
          </a>
        </div>
      </div>
      <div class="ps-kind-note">
        <strong><?= posts_admin_h(ucfirst($kindFilter)) ?>:</strong>
        <?= posts_admin_h((string)($kindBlurbs[$kindFilter] ?? '')) ?>
      </div>

      <?php
        $cards = [
          ['key' => 'all', 'label' => 'All Posts', 'icon' => 'fa-th-large', 'tone' => 'purple'],
          ['key' => 'pending', 'label' => 'Pending Review', 'icon' => 'fa-clock-o', 'tone' => 'orange'],
          ['key' => 'published', 'label' => 'Published', 'icon' => 'fa-check-circle', 'tone' => 'green'],
          ['key' => 'flagged', 'label' => 'Flagged', 'icon' => 'fa-exclamation-triangle', 'tone' => 'red'],
          ['key' => 'removed', 'label' => 'Removed', 'icon' => 'fa-ban', 'tone' => 'gray'],
        ];
      ?>
      <div class="ps-cards">
        <?php foreach ($cards as $c):
          $s = $stats[$c['key']] ?? ['value' => 0, 'delta_pct' => 0];
        ?>
          <a class="ps-card<?= $status === $c['key'] ? ' is-active' : '' ?>" href="<?= posts_admin_h($qs(['status' => $c['key'], 'page' => 1, 'post_id' => ''])) ?>">
            <div class="ps-card-top">
              <div style="display:flex;align-items:center;gap:8px;">
                <div class="ps-ico <?= posts_admin_h($c['tone']) ?>"><i class="fa <?= posts_admin_h($c['icon']) ?>"></i></div>
                <div class="lab"><?= posts_admin_h($c['label']) ?></div>
              </div>
            </div>
            <div class="val"><?= number_format((int)$s['value']) ?></div>
            <?= $deltaHtml((int)($s['delta_pct'] ?? 0)) ?>
          </a>
        <?php endforeach; ?>
      </div>

      <div class="ps-board<?= $postId > 0 ? ' has-detail' : '' ?>">
        <div class="ps-main">
          <form class="ps-filters" method="get" action="posts.php">
            <input type="hidden" name="kind" value="<?= posts_admin_h($kindFilter) ?>">
            <?php if ($postId > 0): ?>
              <input type="hidden" name="post_id" value="<?= (int)$postId ?>">
            <?php endif; ?>
            <div class="ps-search">
              <i class="fa fa-search"></i>
              <input type="search" name="q" value="<?= posts_admin_h($q) ?>" placeholder="Search posts…" autocomplete="off">
            </div>
            <select name="status" aria-label="Status" onchange="this.form.submit()">
              <option value="all"<?= $status === 'all' ? ' selected' : '' ?>>All Status</option>
              <option value="pending"<?= $status === 'pending' ? ' selected' : '' ?>>Pending Review</option>
              <option value="published"<?= $status === 'published' ? ' selected' : '' ?>>Published</option>
              <option value="flagged"<?= $status === 'flagged' ? ' selected' : '' ?>>Flagged</option>
              <option value="removed"<?= $status === 'removed' ? ' selected' : '' ?>>Removed</option>
            </select>
            <select name="post_type" aria-label="Post type" onchange="this.form.submit()">
              <option value="all"<?= $postType === 'all' ? ' selected' : '' ?>>All Post Types</option>
              <option value="text"<?= $postType === 'text' ? ' selected' : '' ?>>Text</option>
              <option value="image"<?= $postType === 'image' ? ' selected' : '' ?>>Image</option>
              <option value="video"<?= $postType === 'video' ? ' selected' : '' ?>>Video</option>
              <option value="link"<?= $postType === 'link' ? ' selected' : '' ?>>Link</option>
            </select>
            <select name="visibility" aria-label="Visibility" onchange="this.form.submit()">
              <option value="all"<?= $visibility === 'all' ? ' selected' : '' ?>>All Visibility</option>
              <option value="public"<?= $visibility === 'public' ? ' selected' : '' ?>>Public</option>
              <option value="friends"<?= $visibility === 'friends' ? ' selected' : '' ?>>Friends</option>
              <option value="private"<?= $visibility === 'private' ? ' selected' : '' ?>>Private</option>
            </select>
            <input type="date" name="from" value="<?= posts_admin_h($dateFrom) ?>" title="From" onchange="this.form.submit()">
            <input type="date" name="to" value="<?= posts_admin_h($dateTo) ?>" title="To" onchange="this.form.submit()">
            <input type="hidden" name="per_page" value="<?= (int)$perPage ?>">
            <button type="submit" class="ps-btn sm primary">Apply</button>
            <?php if ($hasFilters): ?>
              <a class="ps-clear" href="<?= posts_admin_h($qs(['q' => '', 'status' => 'all', 'visibility' => 'all', 'post_type' => 'all', 'from' => '', 'to' => '', 'page' => 1])) ?>"><i class="fa fa-refresh"></i> Clear</a>
            <?php endif; ?>
          </form>

          <div class="ps-table-wrap">
            <table class="ps-table">
              <thead>
                <tr>
                  <th><input type="checkbox" disabled title="Bulk select coming soon"></th>
                  <th>Post ID</th>
                  <th>Content</th>
                  <th>User</th>
                  <th>Type</th>
                  <th>Visibility</th>
                  <th>Created At</th>
                  <th>Status</th>
                  <th>Reports</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
              <?php if (!$rows): ?>
                <tr><td colspan="10"><div class="ps-empty">No posts match these filters.</div></td></tr>
              <?php else: foreach ($rows as $row):
                $pid = (int)($row['id'] ?? 0);
                $uid = (int)($row['user_id'] ?? 0);
                $uname = (string)($row['username'] ?? '');
                $name = (string)($row['name'] ?? '');
                $ptype = (string)($row['post_type'] ?? 'Text');
                $vis = (string)($row['visibility'] ?? 'public');
                $created = (string)($row['created_at'] ?? '');
                $createdTs = $created !== '' ? strtotime($created) : false;
                $dateLine = $createdTs ? date('M j, Y', $createdTs) : posts_admin_fmt($created);
                $timeLine = $createdTs ? date('g:i A', $createdTs) : '';
                $thumb = posts_admin_media_url((string)($row['thumb'] ?? ''));
                $videoSrc = posts_admin_media_url((string)($row['video'] ?? ''));
                $preview = posts_admin_preview((string)($row['text_preview'] ?? ''));
                if ($preview === '' && strcasecmp($ptype, 'Video') === 0) {
                    $preview = 'Video';
                }
                $ini = posts_admin_initials($name !== '' ? $name : ($uname !== '' ? $uname : 'U'));
                $avBg = posts_admin_avatar_color($uname !== '' ? $uname : (string)$uid);
                $stCls = (string)($row['status_cls'] ?? 'published');
                $stLab = (string)($row['status_label'] ?? 'Published');
                $actLink = org_admin_user_activity_link($uid) . ($pid > 0 ? ('&post_id=' . $pid) : '');
                $rowHref = $qs(['post_id' => $pid]);
              ?>
                <tr class="<?= $postId === $pid ? 'is-selected' : '' ?>">
                  <td><input type="checkbox" disabled></td>
                  <td><a class="ps-id" href="<?= posts_admin_h($rowHref) ?>">P-<?= $pid ?></a></td>
                  <td>
                    <div class="ps-item">
                      <?php if ($thumb !== ''): ?>
                        <img class="ps-thumb" src="<?= posts_admin_h($thumb) ?>" alt="" width="32" height="32" loading="lazy">
                      <?php elseif ($videoSrc !== ''): ?>
                        <span class="ps-thumb-wrap">
                          <video class="ps-thumb" src="<?= posts_admin_h($videoSrc) ?>#t=0.1" muted playsinline preload="metadata"></video>
                          <span class="ps-play" aria-hidden="true"><i class="fa fa-play"></i></span>
                        </span>
                      <?php else: ?>
                        <div class="ps-thumb ph"><i class="fa <?= posts_admin_h($typeIcon($ptype)) ?>"></i></div>
                      <?php endif; ?>
                      <div class="txt" title="<?= posts_admin_h($preview) ?>"><?= posts_admin_h($preview !== '' ? $preview : '—') ?></div>
                    </div>
                  </td>
                  <td>
                    <div class="ps-user">
                      <span class="ps-av" style="background:<?= posts_admin_h($avBg) ?>;"><?= posts_admin_h($ini) ?></span>
                      <div>
                        <div class="nm"><?= posts_admin_h($uname !== '' ? '@' . $uname : ('User #' . $uid)) ?></div>
                        <div class="un"><?= posts_admin_h($name !== '' ? $name : '—') ?></div>
                      </div>
                    </div>
                  </td>
                  <td>
                    <span class="ps-meta"><i class="fa <?= posts_admin_h($typeIcon($ptype)) ?>"></i> <?= posts_admin_h($ptype) ?></span>
                  </td>
                  <td>
                    <span class="ps-meta"><i class="fa <?= posts_admin_h($visIcon($vis)) ?>"></i> <?= posts_admin_h(ucfirst($vis)) ?></span>
                  </td>
                  <td>
                    <div class="ps-when">
                      <?= posts_admin_h($dateLine) ?>
                      <?php if ($timeLine !== ''): ?><span><?= posts_admin_h($timeLine) ?></span><?php endif; ?>
                    </div>
                  </td>
                  <td><span class="ps-pill <?= posts_admin_h($stCls) ?>"><?= posts_admin_h($stLab) ?></span></td>
                  <td><?= number_format((int)($row['report_count'] ?? 0)) ?></td>
                  <td>
                    <div class="fries-menu">
                      <button type="button" class="fries-toggle" title="Actions" aria-label="Actions" aria-haspopup="true">
                        <span class="fries-icon" aria-hidden="true"></span>
                      </button>
                      <div class="fries-dropdown" role="menu">
                        <a class="fries-item" role="menuitem" href="post_profile.php?id=<?= $pid ?>">
                          <i class="fa fa-eye"></i> View details
                        </a>
                        <?php if ($uid > 0): ?>
                          <a class="fries-item" role="menuitem" href="user_form.php?id=<?= $uid ?>">
                            <i class="fa fa-user"></i> View user
                          </a>
                          <a class="fries-item" role="menuitem" href="<?= posts_admin_h($actLink) ?>">
                            <i class="fa fa-pulse"></i> View activity
                          </a>
                        <?php endif; ?>
                      </div>
                    </div>
                  </td>
                </tr>
              <?php endforeach; endif; ?>
              </tbody>
            </table>
          </div>

          <div class="ps-foot">
            <div>Showing <?= (int)$fromN ?> to <?= (int)$toN ?> of <?= number_format($total) ?> posts</div>
            <div class="ps-pages">
              <?php if ($page > 1): ?>
                <a href="<?= posts_admin_h($qs(['page' => $page - 1])) ?>">&lsaquo;</a>
              <?php endif; ?>
              <?php
                $start = max(1, $page - 2);
                $end = min($totalPages, $start + 4);
                $start = max(1, $end - 4);
                if ($start > 1) {
                    echo '<a href="' . posts_admin_h($qs(['page' => 1])) . '">1</a>';
                    if ($start > 2) {
                        echo '<span>…</span>';
                    }
                }
                for ($p = $start; $p <= $end; $p++) {
                    $cls = $p === $page ? ' is-active' : '';
                    echo '<a class="' . trim($cls) . '" href="' . posts_admin_h($qs(['page' => $p])) . '">' . $p . '</a>';
                }
                if ($end < $totalPages) {
                    if ($end < $totalPages - 1) {
                        echo '<span>…</span>';
                    }
                    echo '<a href="' . posts_admin_h($qs(['page' => $totalPages])) . '">' . $totalPages . '</a>';
                }
              ?>
              <?php if ($page < $totalPages): ?>
                <a href="<?= posts_admin_h($qs(['page' => $page + 1])) ?>">&rsaquo;</a>
              <?php endif; ?>
            </div>
            <form method="get" action="posts.php" style="margin:0;">
              <input type="hidden" name="kind" value="<?= posts_admin_h($kindFilter) ?>">
              <?php if ($status !== 'all'): ?><input type="hidden" name="status" value="<?= posts_admin_h($status) ?>"><?php endif; ?>
              <?php if ($q !== ''): ?><input type="hidden" name="q" value="<?= posts_admin_h($q) ?>"><?php endif; ?>
              <?php if ($visibility !== 'all'): ?><input type="hidden" name="visibility" value="<?= posts_admin_h($visibility) ?>"><?php endif; ?>
              <?php if ($postType !== 'all'): ?><input type="hidden" name="post_type" value="<?= posts_admin_h($postType) ?>"><?php endif; ?>
              <?php if ($dateFrom !== ''): ?><input type="hidden" name="from" value="<?= posts_admin_h($dateFrom) ?>"><?php endif; ?>
              <?php if ($dateTo !== ''): ?><input type="hidden" name="to" value="<?= posts_admin_h($dateTo) ?>"><?php endif; ?>
              <?php if ($postId > 0): ?><input type="hidden" name="post_id" value="<?= (int)$postId ?>"><?php endif; ?>
              <select class="ps-perpage" name="per_page" onchange="this.form.submit()" aria-label="Per page">
                <?php foreach ([10, 25, 50] as $pp): ?>
                  <option value="<?= $pp ?>"<?= $perPage === $pp ? ' selected' : '' ?>><?= $pp ?> per page</option>
                <?php endforeach; ?>
              </select>
            </form>
          </div>
        </div>

        <?php if ($postId > 0): ?>
          <aside class="ps-side" aria-label="Post details">
            <div class="ps-side-hd">
              <h2>Post Details</h2>
              <a class="ps-side-close" href="<?= posts_admin_h($qs(['post_id' => ''])) ?>" title="Close"><i class="fa fa-times"></i></a>
            </div>
            <?php if (!$detail): ?>
              <div class="ps-side-bd"><div class="ps-side-missing">Post not found.</div></div>
            <?php else:
              $duid = (int)($detail['user_id'] ?? 0);
              $duname = (string)($detail['username'] ?? '');
              $dname = (string)($detail['name'] ?? '');
              $dini = posts_admin_initials($dname !== '' ? $dname : ($duname !== '' ? $duname : 'U'));
              $dav = posts_admin_avatar_color($duname !== '' ? $duname : (string)$duid);
              $dvis = (string)($detail['visibility'] ?? 'public');
              $heroUrl = posts_admin_media_url($detailHero);
              $videoUrl = posts_admin_media_url($detailVideo);
              $actHref = org_admin_user_activity_link($duid) . '&post_id=' . $postId;
              $pidLabel = 'P-' . $postId;
            ?>
              <div class="ps-side-bd">
                <div class="ps-idrow">
                  <a class="ps-id" href="post_profile.php?id=<?= $postId ?>"><?= posts_admin_h($pidLabel) ?></a>
                  <button type="button" class="ps-copy" data-copy="<?= posts_admin_h($pidLabel) ?>">Copy</button>
                  <span class="ps-pill <?= posts_admin_h($detailStatus['cls']) ?>"><?= posts_admin_h($detailStatus['label']) ?></span>
                </div>
                <div class="ps-dates">
                  <div>
                    <div class="lab">Created</div>
                    <div class="val"><?= posts_admin_h(posts_admin_fmt((string)($detail['created_at'] ?? ''))) ?></div>
                  </div>
                  <div>
                    <div class="lab">Updated</div>
                    <div class="val"><?= posts_admin_h(posts_admin_fmt((string)($detail['updated_at'] ?? ''))) ?></div>
                  </div>
                </div>
                <div class="ps-author">
                  <span class="ps-av" style="background:<?= posts_admin_h($dav) ?>;"><?= posts_admin_h($dini) ?></span>
                  <div style="min-width:0;">
                    <div class="nm"><?= posts_admin_h($duname !== '' ? '@' . $duname : ('User #' . $duid)) ?></div>
                    <div class="un"><?= posts_admin_h($dname !== '' ? $dname : '—') ?></div>
                  </div>
                  <span class="ps-meta" style="margin-left:auto;"><i class="fa <?= posts_admin_h($visIcon($dvis)) ?>"></i> <?= posts_admin_h(ucfirst($dvis)) ?></span>
                </div>
                <?php if ($detailText !== ''): ?>
                  <div class="ps-body"><?= posts_admin_h($detailText) ?></div>
                <?php endif; ?>
                <?php if ($videoUrl !== ''): ?>
                  <video class="ps-hero" src="<?= posts_admin_h($videoUrl) ?>" controls playsinline preload="metadata"<?= $heroUrl !== '' && $heroUrl !== $videoUrl ? ' poster="' . posts_admin_h($heroUrl) . '"' : '' ?>></video>
                <?php elseif ($heroUrl !== ''): ?>
                  <img class="ps-hero" src="<?= posts_admin_h($heroUrl) ?>" alt="">
                <?php endif; ?>
                <div class="ps-eng">
                  <span><i class="fa fa-heart-o"></i> <?= number_format((int)$detailEng['likes']) ?></span>
                  <span><i class="fa fa-comment-o"></i> <?= number_format((int)$detailEng['comments']) ?></span>
                  <span><i class="fa fa-share-square-o"></i> <?= number_format((int)$detailEng['shares']) ?></span>
                  <span><i class="fa fa-bookmark-o"></i> <?= number_format((int)$detailEng['saves']) ?></span>
                </div>
                <div class="ps-kv">
                  <div class="ps-kv-row"><span class="k">Type</span><span class="v"><?= posts_admin_h($detailType) ?></span></div>
                  <div class="ps-kv-row"><span class="k">Visibility</span><span class="v"><?= posts_admin_h(ucfirst($dvis)) ?></span></div>
                  <div class="ps-kv-row"><span class="k">Reports</span><span class="v"><?= number_format($detailReportCount) ?></span></div>
                  <div class="ps-kv-row"><span class="k">Views</span><span class="v"><?= number_format((int)($detail['views_count'] ?? 0)) ?></span></div>
                  <div class="ps-kv-row"><span class="k">Likes</span><span class="v"><?= number_format((int)$detailEng['likes']) ?></span></div>
                  <div class="ps-kv-row"><span class="k">Comments</span><span class="v"><?= number_format((int)$detailEng['comments']) ?></span></div>
                </div>
              </div>
              <div class="ps-side-ft">
                <?php if ($duid > 0): ?>
                  <a class="ps-btn ghost" href="user_form.php?id=<?= $duid ?>">View User</a>
                  <a class="ps-btn primary" href="<?= posts_admin_h($actHref) ?>">View Activity</a>
                <?php endif; ?>
                <div class="fries-menu">
                  <button type="button" class="ps-btn fries-toggle" title="More Actions" aria-label="More Actions" aria-haspopup="true">
                    More Actions <i class="fa fa-angle-down"></i>
                  </button>
                  <div class="fries-dropdown" role="menu">
                    <a class="fries-item" role="menuitem" href="post_profile.php?id=<?= $postId ?>">
                      <i class="fa fa-expand"></i> Full details
                    </a>
                    <a class="fries-item" role="menuitem" href="reports.php?status=all&amp;q=<?= rawurlencode((string)$postId) ?>&amp;type=post">
                      <i class="fa fa-flag"></i> Reports
                    </a>
                  </div>
                </div>
              </div>
            <?php endif; ?>
          </aside>
        <?php endif; ?>
      </div>

    </div>
  </div>
</div>
<script>
(function () {
  document.querySelectorAll('[data-copy]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var t = btn.getAttribute('data-copy') || '';
      if (!t) return;
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(t).then(function () {
          btn.textContent = 'Copied';
          setTimeout(function () { btn.textContent = 'Copy'; }, 1200);
        }).catch(function () {});
      }
    });
  });
})();
</script>
<?php org_admin_render_foot(); ?>
