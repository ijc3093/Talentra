<?php
declare(strict_types=1);

/**
 * admin/publisher_requests.php
 * Verification Requests — matches admin list UI (KPIs, filters, status tabs).
 * Preserves approve/reject POST + kind/status filter GET behavior.
 */
require_once __DIR__ . '/includes/session_admin.php';
requireAdminLogin();

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/controller.php';
require_once __DIR__ . '/../public_user/includes/publisher_accounts.php';
require_once __DIR__ . '/../public_user/includes/publisher_authority.php';
require_once __DIR__ . '/../public_user/includes/org_commerce_brands.php';
require_once __DIR__ . '/includes/admin_kind_tabs.php';

$adminId = (int)($_SESSION['admin_id'] ?? 0);
$adminRole = (int)($_SESSION['userRole'] ?? 0);
$isAdmin = ($adminRole === 1);

if (!$isAdmin) {
    header('Location: dashboard.php');
    exit;
}

$controller = new Controller();
$dbh = $controller->pdo();
publisher_authority_ensure_schema($dbh);
org_commerce_brands_ensure_schema($dbh);

$msg = '';
$error = '';
$filter = strtolower(trim((string)($_GET['status'] ?? 'pending')));
// UI aliases: verified => approved; expired = stale pending (>30d)
if ($filter === 'verified') {
    $filter = 'approved';
}
if (!in_array($filter, ['pending', 'approved', 'rejected', 'expired', 'all'], true)) {
    $filter = 'pending';
}
$kindFilter = admin_kind_from_request();
$typeFilter = strtolower(trim((string)($_GET['type'] ?? 'all')));
$countryFilter = strtoupper(trim((string)($_GET['country'] ?? 'all')));
$dateFrom = trim((string)($_GET['from'] ?? ''));
$dateTo = trim((string)($_GET['to'] ?? ''));

function h($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function fmt_dt($dt): string
{
    if (!$dt) {
        return 'N/A';
    }
    $ts = strtotime((string)$dt);
    if (!$ts) {
        return (string)$dt;
    }
    return date('M j, Y g:i A', $ts);
}

function initials2(string $name): string
{
    $name = trim(preg_replace('/\s+/', ' ', $name) ?? $name);
    if ($name === '') {
        return '??';
    }
    $name = str_replace(['_', '.', '-', '@'], ' ', $name);
    $parts = array_values(array_filter(explode(' ', $name), static fn($p) => trim($p) !== ''));
    if (!$parts) {
        return '??';
    }
    $first = mb_strtoupper(mb_substr($parts[0], 0, 1));
    $second = '';
    if (count($parts) > 1) {
        $second = mb_strtoupper(mb_substr($parts[count($parts) - 1], 0, 1));
    } else {
        $second = mb_strtoupper(mb_substr($parts[0], 1, 1));
    }
    $ini = trim($first . $second);
    return $ini !== '' ? $ini : '??';
}

function avatarColor(string $key): string
{
    $key = strtolower(trim($key));
    $hash = crc32($key);
    $palette = ['#2563eb','#7c3aed','#db2777','#ea580c','#16a34a','#0f766e','#0891b2','#475569'];
    return $palette[$hash % count($palette)];
}

if (isset($_POST['approve_request'])) {
    $requestId = (int)($_POST['request_id'] ?? 0);
    $reviewNote = trim((string)($_POST['review_note'] ?? ''));
    $result = publisher_authority_admin_approve($dbh, $requestId, $adminId, $reviewNote);
    if (!empty($result['ok'])) {
        if (!empty($result['repaired'])) {
            $msg = 'Restored publisher name: ' . (string)($result['name'] ?? '');
        } elseif (!empty($result['message']) && strpos((string)$result['message'], 'Commerce') !== false) {
            $msg = (string)$result['message'];
        } else {
            $msg = 'Approved publisher name: ' . (string)($result['name'] ?? '');
        }
    } else {
        $error = (string)($result['message'] ?? 'Unable to approve this request.');
    }
}

if (isset($_POST['reject_request'])) {
    $requestId = (int)($_POST['request_id'] ?? 0);
    $reviewNote = trim((string)($_POST['review_note'] ?? ''));
    $result = publisher_authority_admin_reject($dbh, $requestId, $adminId, $reviewNote);
    if (!empty($result['ok'])) {
        $msg = 'Request rejected.';
    } else {
        $error = 'Unable to reject this request.';
    }
}

$prClassify = static function (array $row): string {
    if (publisher_authority_is_commerce_request($row)) {
        return 'commerce';
    }
    $cat = strtolower(trim((string)($row['publisher_category'] ?? '')));
    if ($cat === 'commerce') {
        return 'commerce';
    }
    return 'publisher';
};

$allRequests = publisher_authority_admin_list($dbh, 'all');
$pendingCount = 0;
$approvedCount = 0;
$rejectedCount = 0;
$expiredCount = 0;
$kindCounts = ['personal' => 0, 'publisher' => 0, 'commerce' => 0];
$expireBefore = strtotime('-30 days');
$countries = [];
$displayStatusOf = static function (array $row) use ($expireBefore): string {
    $st = strtolower((string)($row['status'] ?? 'pending'));
    if ($st === 'approved') {
        return 'verified';
    }
    if ($st === 'rejected') {
        return 'rejected';
    }
    $cts = strtotime((string)($row['created_at'] ?? ''));
    if ($cts && $cts < $expireBefore) {
        return 'expired';
    }
    return 'pending';
};

foreach ($allRequests as $countRow) {
    $rk = $prClassify($countRow);
    $kindCounts[$rk] = (int)($kindCounts[$rk] ?? 0) + 1;
    $ds = $displayStatusOf($countRow);
    if ($ds === 'verified') {
        $approvedCount++;
    } elseif ($ds === 'rejected') {
        $rejectedCount++;
    } elseif ($ds === 'expired') {
        $expiredCount++;
    } else {
        $pendingCount++;
    }
    $cc = strtoupper(trim((string)($countRow['registration_country'] ?? '')));
    if ($cc !== '') {
        $countries[$cc] = true;
    }
}
$totalCount = count($allRequests);
ksort($countries);

$requests = [];
foreach ($allRequests as $row) {
    if ($prClassify($row) !== $kindFilter) {
        continue;
    }
    $ds = $displayStatusOf($row);
    if ($filter === 'pending' && $ds !== 'pending') {
        continue;
    }
    if ($filter === 'approved' && $ds !== 'verified') {
        continue;
    }
    if ($filter === 'rejected' && $ds !== 'rejected') {
        continue;
    }
    if ($filter === 'expired' && $ds !== 'expired') {
        continue;
    }
    $cat = strtolower(trim((string)($row['publisher_category'] ?? '')));
    $isCommerce = publisher_authority_is_commerce_request($row);
    $vType = $isCommerce ? 'business' : 'identity';
    if ($typeFilter === 'identity' && $vType !== 'identity') {
        continue;
    }
    if ($typeFilter === 'business' && $vType !== 'business') {
        continue;
    }
    if ($typeFilter !== 'all' && $typeFilter !== 'identity' && $typeFilter !== 'business' && $cat !== $typeFilter) {
        continue;
    }
    $cc = strtoupper(trim((string)($row['registration_country'] ?? 'US')));
    if ($countryFilter !== 'all' && $cc !== $countryFilter) {
        continue;
    }
    $cts = strtotime((string)($row['created_at'] ?? '')) ?: 0;
    if ($dateFrom !== '') {
        $fromTs = strtotime($dateFrom . ' 00:00:00');
        if ($fromTs && $cts < $fromTs) {
            continue;
        }
    }
    if ($dateTo !== '') {
        $toTs = strtotime($dateTo . ' 23:59:59');
        if ($toTs && $cts > $toTs) {
            continue;
        }
    }
    $row['_display_status'] = $ds;
    $row['_verify_type'] = $vType;
    $requests[] = $row;
}

$uiStatus = $filter === 'approved' ? 'verified' : $filter;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
  <title>Verification Requests</title>
  <link href="../lib/font-awesome/css/font-awesome.css" rel="stylesheet">
  <link href="../lib/Ionicons/css/ionicons.css" rel="stylesheet">
  <link href="../lib/perfect-scrollbar/css/perfect-scrollbar.css" rel="stylesheet">
  <link href="../lib/datatables/jquery.dataTables.css" rel="stylesheet">
  <link rel="stylesheet" href="../css/shamcey.css">
  <link rel="stylesheet" href="css/admin-tables-shamcey.css?v=8">
  <style>
    html,body{height:100%;overflow:hidden;}
    .sh-mainpanel{height:100vh;display:flex;flex-direction:column;overflow:hidden;}
    .sh-mainpanel > .sh-pagebody{
      overflow:hidden !important;display:flex !important;flex-direction:column !important;min-height:0 !important;
      padding-top:8px !important;padding-bottom:8px !important;flex:1 1 auto;background:#f4f6fb;
    }
    .pr-wrap{flex:1 1 auto;min-height:0;display:flex;flex-direction:column;gap:8px;overflow:hidden;padding:0 2px;box-sizing:border-box;}
    .pr-top{flex:0 0 auto;display:flex;align-items:center;justify-content:flex-end;gap:10px;}
    .pr-actions{display:flex;gap:6px;flex-wrap:wrap;align-items:center;}
    .pr-btn{height:30px;padding:0 10px;border-radius:8px;border:1px solid #e2e8f0;background:#fff;font-size:11px;font-weight:700;color:#334155;display:inline-flex;align-items:center;gap:5px;text-decoration:none;cursor:pointer;white-space:nowrap;}
    .pr-btn:hover{background:#f8fafc;text-decoration:none;color:#0f172a;}
    .pr-btn.primary{background:#2563eb;border-color:#2563eb;color:#fff;}
    .pr-cards{flex:0 0 auto;display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:8px;}
    .pr-card{background:#fff;border:1px solid #eef2f7;border-radius:12px;padding:10px 12px;box-shadow:0 1px 2px rgba(15,23,42,.04);text-decoration:none;color:inherit;display:block;min-width:0;}
    .pr-card:hover{border-color:#bfdbfe;text-decoration:none;color:inherit;}
    .pr-card.is-active{border-color:#2563eb;box-shadow:0 0 0 1px #2563eb inset;}
    .pr-card-top{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:6px;}
    .pr-card-top .lab{font-size:11px;font-weight:700;color:#64748b;}
    .pr-card-top .delta{font-size:10px;font-weight:800;color:#94a3b8;}
    .pr-ico{width:28px;height:28px;border-radius:999px;display:flex;align-items:center;justify-content:center;font-size:12px;}
    .pr-ico.blue{background:#dbeafe;color:#2563eb;}
    .pr-ico.yellow{background:#fef9c3;color:#ca8a04;}
    .pr-ico.green{background:#f0fdf4;color:#16a34a;}
    .pr-ico.red{background:#fef2f2;color:#dc2626;}
    .pr-ico.gray{background:#f1f5f9;color:#64748b;}
    .pr-card .val{font-size:20px;font-weight:800;color:#0f172a;line-height:1;}
    .pr-card .sub{font-size:10px;color:#94a3b8;font-weight:600;margin-top:4px;}
    .pr-kinds{flex:0 0 auto;display:flex;gap:0;background:#fff;border:1px solid #eef2f7;border-radius:10px;padding:0 4px;overflow:auto;}
    .pr-kinds a{flex:0 0 auto;padding:8px 14px;font-size:12px;font-weight:800;color:#64748b;text-decoration:none;border-bottom:2px solid transparent;white-space:nowrap;}
    .pr-kinds a .cnt{font-weight:700;color:#94a3b8;margin-left:4px;}
    .pr-kinds a.is-active{color:#2563eb;border-bottom-color:#2563eb;}
    .pr-main{flex:1 1 auto;min-height:0;background:#fff;border:1px solid #eef2f7;border-radius:12px;overflow:hidden;box-shadow:0 1px 2px rgba(15,23,42,.04);display:flex;flex-direction:column;}
    .pr-filters{flex:0 0 auto;display:flex;gap:6px;flex-wrap:wrap;align-items:center;padding:10px 12px;border-bottom:1px solid #eef2f7;background:#fafbfc;}
    .pr-search{position:relative;flex:1 1 160px;min-width:140px;max-width:240px;}
    .pr-search i{position:absolute;left:9px;top:50%;transform:translateY(-50%);color:#94a3b8;font-size:12px;}
    .pr-search input,.pr-filters select,.pr-filters input[type="date"]{height:30px;border:1px solid #e2e8f0;border-radius:8px;padding:0 9px;font-size:11px;background:#fff;color:#0f172a;}
    .pr-search input{width:100%;padding-left:28px;}
    .pr-table-wrap{flex:1 1 auto;min-height:0;overflow:auto;}
    .pr-table{width:100%;table-layout:fixed;border-collapse:separate;border-spacing:0;}
    .pr-table th{text-align:left;font-size:9px;font-weight:800;letter-spacing:.03em;text-transform:uppercase;color:#64748b;padding:8px 8px;border-bottom:1px solid #eef2f7;background:#fff;position:sticky;top:0;z-index:3;}
    .pr-table td{padding:10px 8px;border-bottom:1px solid #f1f5f9;vertical-align:middle;font-size:11px;color:#0f172a;}
    .pr-table tr:hover td{background:#f8fafc;}
    .pr-user{display:flex;align-items:center;gap:8px;min-width:0;}
    .pr-av{width:28px;height:28px;border-radius:999px;color:#fff;font-size:10px;font-weight:800;display:flex;align-items:center;justify-content:center;flex:0 0 28px;}
    .pr-name{font-weight:800;font-size:11px;color:#0f172a;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
    .pr-sub{font-size:10px;color:#64748b;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
    .pr-id{color:#2563eb;font-weight:800;text-decoration:none;white-space:nowrap;}
    .pr-type{display:inline-flex;align-items:center;gap:5px;font-size:11px;font-weight:700;color:#334155;}
    .pr-type i{color:#64748b;}
    .pr-status{display:inline-flex;align-items:center;padding:2px 8px;border-radius:999px;font-size:10px;font-weight:800;white-space:nowrap;}
    .pr-status.pending{background:#fef3c7;color:#b45309;}
    .pr-status.verified{background:#dcfce7;color:#15803d;}
    .pr-status.rejected{background:#fee2e2;color:#b91c1c;}
    .pr-status.expired{background:#f1f5f9;color:#64748b;}
    .pr-docs{color:#94a3b8;font-weight:700;}
    .pr-country{font-weight:600;color:#475569;white-space:nowrap;}
    .pr-acts{display:flex;align-items:center;gap:6px;}
    .pr-review{height:28px;padding:0 10px;border-radius:8px;border:1px solid #bfdbfe;background:#eff6ff;color:#1d4ed8;font-size:11px;font-weight:800;text-decoration:none;display:inline-flex;align-items:center;}
    .pr-review:hover{background:#dbeafe;text-decoration:none;color:#1e40af;}
    .pr-alert{padding:7px 9px;border-radius:8px;font-size:12px;font-weight:700;}
    .pr-alert.ok{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0;}
    .pr-alert.bad{background:#fef2f2;color:#991b1b;border:1px solid #fecaca;}
    .pr-foot{flex:0 0 auto;display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;padding:10px 12px;border-top:1px solid #eef2f7;}
    .pr-foot .muted{font-size:11px;color:#64748b;font-weight:600;}
    .dataTables_wrapper .dataTables_paginate{float:none;text-align:center;padding:0;}
    .dataTables_wrapper .dataTables_paginate .paginate_button{min-width:28px !important;height:28px !important;padding:0 7px !important;margin:0 2px !important;border-radius:7px !important;border:1px solid #e2e8f0 !important;background:#fff !important;font-size:11px !important;font-weight:700 !important;line-height:26px !important;}
    .dataTables_wrapper .dataTables_paginate .paginate_button.current{background:#2563eb !important;border-color:#2563eb !important;color:#fff !important;}
    .dataTables_wrapper .dataTables_info,.dataTables_wrapper .dataTables_length,.dataTables_wrapper .dataTables_filter{display:none !important;}
    #datatable1_wrapper{display:contents;}
    @media (max-width:1100px){.pr-wrap{overflow:auto;}.pr-cards{grid-template-columns:repeat(2,minmax(0,1fr));}}
    <?= admin_kind_tabs_css('ak') ?>
  </style>
</head>
<body>
<?php
$adminChromePageIntro = [
    'title' => 'Verification Requests',
    'description' => 'Review and manage user verification requests.',
];
include __DIR__ . '/includes/leftbar.php';
include __DIR__ . '/includes/header.php';

$href = static function (array $overrides = []) use ($uiStatus, $kindFilter, $typeFilter, $countryFilter, $dateFrom, $dateTo): string {
    $q = array_merge([
        'status' => $uiStatus,
        'kind' => $kindFilter,
        'type' => $typeFilter,
        'country' => $countryFilter,
        'from' => $dateFrom,
        'to' => $dateTo,
    ], $overrides);
    foreach ($q as $k => $v) {
        if ($v === '' || $v === 'all' && in_array($k, ['type', 'country', 'from', 'to'], true)) {
            if ($k !== 'status' && $k !== 'kind' && ($v === '' || $v === 'all')) {
                unset($q[$k]);
            }
        }
    }
    if (($q['type'] ?? 'all') === 'all') unset($q['type']);
    if (($q['country'] ?? 'all') === 'all') unset($q['country']);
    if (($q['from'] ?? '') === '') unset($q['from']);
    if (($q['to'] ?? '') === '') unset($q['to']);
    return 'publisher_requests.php?' . http_build_query($q);
};
?>

<div class="sh-mainpanel">
  <div class="sh-pagebody">
    <div class="pr-wrap">
      <?= admin_kind_tabs_html(
          $kindFilter,
          $kindCounts,
          static function ($k) use ($href) {
              return $href(['kind' => $k]);
          },
          'h',
          'ak',
          'requests'
      ) ?>

      <?php if ($error): ?><div class="pr-alert bad"><?= h($error) ?></div><?php elseif ($msg): ?><div class="pr-alert ok"><?= h($msg) ?></div><?php endif; ?>

      <div class="pr-top">
        <div class="pr-actions">
          <button type="button" class="pr-btn" title="Export coming soon"><i class="fa fa-download"></i> Export</button>
          <button type="button" class="pr-btn" onclick="document.getElementById('prFilters').scrollIntoView({behavior:'smooth',block:'center'})"><i class="fa fa-sliders"></i> Filter</button>
        </div>
      </div>

      <div class="pr-cards">
        <a class="pr-card<?= $uiStatus === 'all' ? ' is-active' : '' ?>" href="<?= h($href(['status' => 'all'])) ?>">
          <div class="pr-card-top"><div style="display:flex;align-items:center;gap:8px;"><div class="pr-ico blue"><i class="fa fa-files-o"></i></div><div class="lab">Total Requests</div></div><div class="delta">• all</div></div>
          <div class="val"><?= number_format($totalCount) ?></div><div class="sub">All time</div>
        </a>
        <a class="pr-card<?= $uiStatus === 'pending' ? ' is-active' : '' ?>" href="<?= h($href(['status' => 'pending'])) ?>">
          <div class="pr-card-top"><div style="display:flex;align-items:center;gap:8px;"><div class="pr-ico yellow"><i class="fa fa-clock-o"></i></div><div class="lab">Pending Review</div></div><div class="delta">• queue</div></div>
          <div class="val"><?= number_format($pendingCount) ?></div><div class="sub">Awaiting action</div>
        </a>
        <a class="pr-card<?= $uiStatus === 'verified' ? ' is-active' : '' ?>" href="<?= h($href(['status' => 'verified'])) ?>">
          <div class="pr-card-top"><div style="display:flex;align-items:center;gap:8px;"><div class="pr-ico green"><i class="fa fa-check-circle"></i></div><div class="lab">Verified</div></div><div class="delta">• ok</div></div>
          <div class="val"><?= number_format($approvedCount) ?></div><div class="sub">Approved</div>
        </a>
        <a class="pr-card<?= $uiStatus === 'rejected' ? ' is-active' : '' ?>" href="<?= h($href(['status' => 'rejected'])) ?>">
          <div class="pr-card-top"><div style="display:flex;align-items:center;gap:8px;"><div class="pr-ico red"><i class="fa fa-times-circle"></i></div><div class="lab">Rejected</div></div><div class="delta">• no</div></div>
          <div class="val"><?= number_format($rejectedCount) ?></div><div class="sub">Not approved</div>
        </a>
        <a class="pr-card<?= $uiStatus === 'expired' ? ' is-active' : '' ?>" href="<?= h($href(['status' => 'expired'])) ?>">
          <div class="pr-card-top"><div style="display:flex;align-items:center;gap:8px;"><div class="pr-ico gray"><i class="fa fa-calendar-times-o"></i></div><div class="lab">Expired</div></div><div class="delta">• 30d+</div></div>
          <div class="val"><?= number_format($expiredCount) ?></div><div class="sub">Stale pending</div>
        </a>
      </div>

      <nav class="pr-kinds" aria-label="Request status">
        <a href="<?= h($href(['status' => 'all'])) ?>" class="<?= $uiStatus === 'all' ? 'is-active' : '' ?>">All Requests<span class="cnt"><?= (int)$totalCount ?></span></a>
        <a href="<?= h($href(['status' => 'pending'])) ?>" class="<?= $uiStatus === 'pending' ? 'is-active' : '' ?>">Pending Review<span class="cnt"><?= (int)$pendingCount ?></span></a>
        <a href="<?= h($href(['status' => 'verified'])) ?>" class="<?= $uiStatus === 'verified' ? 'is-active' : '' ?>">Verified<span class="cnt"><?= (int)$approvedCount ?></span></a>
        <a href="<?= h($href(['status' => 'rejected'])) ?>" class="<?= $uiStatus === 'rejected' ? 'is-active' : '' ?>">Rejected<span class="cnt"><?= (int)$rejectedCount ?></span></a>
        <a href="<?= h($href(['status' => 'expired'])) ?>" class="<?= $uiStatus === 'expired' ? 'is-active' : '' ?>">Expired<span class="cnt"><?= (int)$expiredCount ?></span></a>
      </nav>

      <div class="pr-main">
        <form class="pr-filters" id="prFilters" method="get">
          <input type="hidden" name="kind" value="<?= h($kindFilter) ?>">
          <select name="type" aria-label="Verification type" onchange="this.form.submit()">
            <option value="all"<?= $typeFilter === 'all' ? ' selected' : '' ?>>All Verification Types</option>
            <option value="identity"<?= $typeFilter === 'identity' ? ' selected' : '' ?>>Identity (KYC)</option>
            <option value="business"<?= $typeFilter === 'business' ? ' selected' : '' ?>>Business Verification</option>
          </select>
          <select name="status" aria-label="Status" onchange="this.form.submit()">
            <option value="all"<?= $uiStatus === 'all' ? ' selected' : '' ?>>All Statuses</option>
            <option value="pending"<?= $uiStatus === 'pending' ? ' selected' : '' ?>>Pending Review</option>
            <option value="verified"<?= $uiStatus === 'verified' ? ' selected' : '' ?>>Verified</option>
            <option value="rejected"<?= $uiStatus === 'rejected' ? ' selected' : '' ?>>Rejected</option>
            <option value="expired"<?= $uiStatus === 'expired' ? ' selected' : '' ?>>Expired</option>
          </select>
          <select name="country" aria-label="Country" onchange="this.form.submit()">
            <option value="all">All Countries</option>
            <?php foreach (array_keys($countries) as $cc): ?>
              <option value="<?= h($cc) ?>"<?= $countryFilter === $cc ? ' selected' : '' ?>><?= h($cc) ?></option>
            <?php endforeach; ?>
          </select>
          <input type="date" name="from" value="<?= h($dateFrom) ?>" aria-label="From date" onchange="this.form.submit()">
          <input type="date" name="to" value="<?= h($dateTo) ?>" aria-label="To date" onchange="this.form.submit()">
          <div class="pr-search">
            <i class="fa fa-search"></i>
            <input type="search" id="prSearchInput" placeholder="Search users..." autocomplete="off">
          </div>
          <select id="prPageLen" aria-label="Per page">
            <option value="10" selected>10 / page</option>
            <option value="25">25 / page</option>
            <option value="50">50 / page</option>
            <option value="100">100 / page</option>
          </select>
        </form>

        <div class="pr-table-wrap">
          <table id="datatable1" class="pr-table display" style="width:100%;">
            <thead>
              <tr>
                <th style="width:28px;"><input type="checkbox" id="prSelectAll"></th>
                <th>User</th>
                <th style="width:90px;">User ID</th>
                <th>Verification Type</th>
                <th style="width:120px;">Submitted</th>
                <th style="width:100px;">Status</th>
                <th style="width:80px;">Documents</th>
                <th style="width:90px;">Country</th>
                <th style="width:140px;">Actions</th>
              </tr>
            </thead>
            <tbody>
            <?php if ($kindFilter === 'personal'): ?>
              <tr><td colspan="9" style="text-align:center;color:#64748b;font-weight:700;padding:24px;">Personal accounts do not submit verification name requests.</td></tr>
            <?php endif; ?>
            <?php foreach ($requests as $row):
                $ds = (string)($row['_display_status'] ?? 'pending');
                $vType = (string)($row['_verify_type'] ?? 'identity');
                $typeLabel = $vType === 'business' ? 'Business Verification' : 'Identity (KYC)';
                $applicantUsername = trim((string)($row['applicant_username'] ?? ''));
                $applicantEmail = trim((string)($row['applicant_email'] ?? ''));
                $contactName = trim((string)($row['authorized_contact_name'] ?? ''));
                $pubName = trim((string)($row['publisher_name'] ?? ''));
                $displayName = $contactName !== '' ? $contactName : ($applicantUsername !== '' ? $applicantUsername : $pubName);
                $handle = $applicantUsername !== '' ? '@' . $applicantUsername : ($applicantEmail !== '' ? $applicantEmail : '—');
                $ini = initials2($displayName !== '' ? $displayName : 'RQ');
                $bg = avatarColor($applicantEmail !== '' ? $applicantEmail : ($displayName !== '' ? $displayName : (string)($row['id'] ?? 0)));
                $rid = (int)($row['id'] ?? 0);
                $detailHref = 'publisher_request_detail.php?id=' . $rid . '&status=' . rawurlencode($filter === 'approved' ? 'approved' : ($filter === 'expired' ? 'pending' : $filter));
                $country = strtoupper(trim((string)($row['registration_country'] ?? '')));
                if ($country === '') { $country = 'US'; }
                $statusLabel = match ($ds) {
                    'verified' => 'Verified',
                    'rejected' => 'Rejected',
                    'expired' => 'Expired',
                    default => 'Pending Review',
                };
            ?>
              <tr>
                <td><input type="checkbox" class="pr-row-check" value="<?= $rid ?>"></td>
                <td>
                  <div class="pr-user">
                    <span class="pr-av" style="background:<?= h($bg) ?>;"><?= h($ini) ?></span>
                    <div style="min-width:0;">
                      <div class="pr-name"><?= h($displayName !== '' ? $displayName : '—') ?></div>
                      <div class="pr-sub"><?= h($handle) ?></div>
                    </div>
                  </div>
                </td>
                <td><a class="pr-id" href="<?= h($detailHref) ?>">REQ-<?= str_pad((string)$rid, 5, '0', STR_PAD_LEFT) ?></a></td>
                <td><span class="pr-type"><i class="fa fa-id-card-o"></i> <?= h($typeLabel) ?></span></td>
                <td><div class="pr-sub" style="color:#475569;font-weight:600;"><?= h(fmt_dt($row['created_at'] ?? '')) ?></div></td>
                <td><span class="pr-status <?= h($ds) ?>"><?= h($statusLabel) ?></span></td>
                <td><span class="pr-docs">—</span></td>
                <td><span class="pr-country"><?= h($country) ?></span></td>
                <td>
                  <div class="pr-acts">
                    <a class="pr-review" href="<?= h($detailHref) ?>"><?= $ds === 'pending' || $ds === 'expired' ? 'Review' : 'View Details' ?></a>
                    <div class="fries-menu">
                      <button type="button" class="fries-toggle" title="Actions" aria-label="Actions" aria-haspopup="true"><span class="fries-icon" aria-hidden="true"></span></button>
                      <div class="fries-dropdown" role="menu">
                        <a class="fries-item" role="menuitem" href="<?= h($detailHref) ?>"><i class="fa fa-eye"></i> Open detail</a>
                        <?php if ($ds === 'pending' || $ds === 'expired'): ?>
                        <form method="post" style="margin:0;">
                          <input type="hidden" name="request_id" value="<?= $rid ?>">
                          <button type="submit" name="approve_request" value="1" class="fries-item" role="menuitem"><i class="fa fa-check"></i> Approve</button>
                          <button type="submit" name="reject_request" value="1" class="fries-item fries-item-danger" role="menuitem"><i class="fa fa-times"></i> Reject</button>
                        </form>
                        <?php endif; ?>
                      </div>
                    </div>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <div class="pr-foot">
          <div class="muted" id="prShowing">Showing 0 requests</div>
          <div id="prPagerHost"></div>
          <div class="muted"><span id="visibleRequestCount"><?= (int)count($requests) ?></span> in this view</div>
        </div>
      </div>
    </div>
  </div>
</div>

<script src="../lib/jquery/jquery.js"></script>
<script src="../lib/popper.js/popper.js"></script>
<script src="../lib/bootstrap/bootstrap.js"></script>
<script src="../lib/datatables/jquery.dataTables.js"></script>
<script src="../js/shamcey.js"></script>
<script src="js/admin-fries-menu.js?v=1"></script>
<script>
$(function() {
  var hasRows = <?= count($requests) > 0 ? 'true' : 'false' ?>;
  if (!hasRows) {
    $('#prShowing').text('Showing 0 requests');
    return;
  }
  var dt = $('#datatable1').DataTable({
    paging: true,
    pageLength: 10,
    lengthChange: false,
    info: true,
    autoWidth: false,
    order: [[4, 'desc']],
    columnDefs: [{ orderable: false, targets: [0, 8] }],
    dom: 'tp',
    language: { paginate: { previous: '‹', next: '›' } },
    drawCallback: function() {
      var info = this.api().page.info();
      var from = info.recordsDisplay === 0 ? 0 : (info.start + 1);
      $('#prShowing').text('Showing ' + from + ' to ' + info.end + ' of ' + info.recordsDisplay + ' requests.');
      $('#visibleRequestCount').text(info.recordsDisplay);
      var $pag = $(this.api().table().container()).find('.dataTables_paginate');
      if ($pag.length) $('#prPagerHost').empty().append($pag);
    }
  });
  setTimeout(function(){ var $pag=$('#datatable1_paginate'); if($pag.length) $('#prPagerHost').empty().append($pag); }, 0);
  $('#prSearchInput').on('input', function(){ dt.search(this.value).draw(); });
  $('#prPageLen').on('change', function(){ dt.page.len(parseInt(this.value,10)||10).draw(); });
  $('#prSelectAll').on('change', function(){ $('.pr-row-check').prop('checked', this.checked); });
});
</script>
</body>
</html>
