<?php
declare(strict_types=1);

/**
 * Admin — Device Activity (screenshot layout; viewport-fit, no page scroll).
 * Live rows from user_sessions + users.
 */
require_once __DIR__ . '/includes/org_admin_helpers_load.php';
require_once __DIR__ . '/includes/msb_moderation_activity.php';
require_once __DIR__ . '/includes/posts_admin_helpers.php';
require_once __DIR__ . '/includes/admin_login_activity_helpers.php';
require_once __DIR__ . '/includes/admin_kind_tabs.php';
org_admin_require_admin();

error_reporting(E_ALL);
ini_set('display_errors', '1');

$dbh = org_admin_db();
$kindFilter = admin_kind_from_request();
$kindCounts = admin_kind_user_counts($dbh);
$hasTable = msb_mod_table_exists($dbh, 'user_sessions');

$q = trim((string)($_GET['q'] ?? ''));
$deviceType = strtolower(trim((string)($_GET['device_type'] ?? 'all')));
if (!in_array($deviceType, ['all', 'mobile', 'desktop'], true)) {
    $deviceType = 'all';
}
$platform = strtolower(trim((string)($_GET['platform'] ?? 'all')));
if (!in_array($platform, ['all', 'windows', 'apple', 'android', 'linux', 'other'], true)) {
    $platform = 'all';
}
$statusFilter = strtolower(trim((string)($_GET['status'] ?? 'all')));
if (!in_array($statusFilter, ['all', 'active', 'inactive', 'blocked'], true)) {
    $statusFilter = 'all';
}
$userFilter = strtolower(trim((string)($_GET['user'] ?? 'all')));
if (!in_array($userFilter, ['all', 'active', 'blocked'], true)) {
    $userFilter = 'all';
}

$dateTo = trim((string)($_GET['to'] ?? ''));
$dateFrom = trim((string)($_GET['from'] ?? ''));
if ($dateTo === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
    $dateTo = date('Y-m-d');
}
if ($dateFrom === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
    $dateFrom = date('Y-m-d', strtotime($dateTo . ' -6 days') ?: strtotime('-6 days'));
}

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = (int)($_GET['per_page'] ?? 10);
if (!in_array($perPage, [10, 25, 50], true)) {
    $perPage = 10;
}

$metrics = admin_device_activity_metrics($dbh);
$rows = [];
$total = 0;
$totalPages = 1;

$mobileSql = "(s.user_agent LIKE '%Mobile%' OR s.user_agent LIKE '%Android%' OR s.user_agent LIKE '%iPhone%' OR s.user_agent LIKE '%iPad%')";

if ($hasTable) {
    $where = [
        'DATE(COALESCE(s.last_seen_at, s.created_at)) >= :from',
        'DATE(COALESCE(s.last_seen_at, s.created_at)) <= :to',
        admin_kind_user_where($kindFilter, 'u'),
    ];
    $params = [':from' => $dateFrom, ':to' => $dateTo];

    if ($q !== '') {
        $where[] = '(u.username LIKE :q OR u.name LIKE :q2 OR u.email LIKE :q3 OR s.ip_address LIKE :q4 OR s.user_agent LIKE :q5 OR CAST(s.user_id AS CHAR) = :qid)';
        $like = '%' . $q . '%';
        $params[':q'] = $like;
        $params[':q2'] = $like;
        $params[':q3'] = $like;
        $params[':q4'] = $like;
        $params[':q5'] = $like;
        $params[':qid'] = $q;
    }
    if ($userFilter === 'active') {
        $where[] = '(u.status = 1 OR u.status IS NULL)';
    } elseif ($userFilter === 'blocked') {
        $where[] = 'u.status = 0';
    }
    if ($deviceType === 'mobile') {
        $where[] = $mobileSql;
    } elseif ($deviceType === 'desktop') {
        $where[] = 'NOT ' . $mobileSql;
    }
    if ($platform === 'windows') {
        $where[] = "s.user_agent LIKE '%Windows%'";
    } elseif ($platform === 'apple') {
        $where[] = "(s.user_agent LIKE '%Mac OS X%' OR s.user_agent LIKE '%iPhone%' OR s.user_agent LIKE '%iPad%' OR s.user_agent LIKE '%Macintosh%')";
    } elseif ($platform === 'android') {
        $where[] = "s.user_agent LIKE '%Android%'";
    } elseif ($platform === 'linux') {
        $where[] = "(s.user_agent LIKE '%Linux%' AND s.user_agent NOT LIKE '%Android%')";
    } elseif ($platform === 'other') {
        $where[] = "(COALESCE(s.user_agent,'') NOT LIKE '%Windows%' AND COALESCE(s.user_agent,'') NOT LIKE '%Mac OS X%' AND COALESCE(s.user_agent,'') NOT LIKE '%iPhone%' AND COALESCE(s.user_agent,'') NOT LIKE '%iPad%' AND COALESCE(s.user_agent,'') NOT LIKE '%Macintosh%' AND COALESCE(s.user_agent,'') NOT LIKE '%Android%' AND COALESCE(s.user_agent,'') NOT LIKE '%Linux%')";
    }

    if ($statusFilter === 'blocked') {
        $where[] = "(s.revoked_at IS NOT NULL AND s.revoked_at <> '0000-00-00 00:00:00')";
    } elseif ($statusFilter === 'active') {
        $where[] = "(s.revoked_at IS NULL OR s.revoked_at = '0000-00-00 00:00:00') AND s.last_seen_at >= (NOW() - INTERVAL 15 MINUTE)";
    } elseif ($statusFilter === 'inactive') {
        $where[] = "(s.revoked_at IS NULL OR s.revoked_at = '0000-00-00 00:00:00') AND (s.last_seen_at IS NULL OR s.last_seen_at < (NOW() - INTERVAL 15 MINUTE))";
    }

    $whereSql = implode(' AND ', $where);

    try {
        $stCount = $dbh->prepare("
            SELECT COUNT(*)
            FROM user_sessions s
            LEFT JOIN users u ON u.id = s.user_id
            WHERE {$whereSql}
        ");
        $stCount->execute($params);
        $total = (int)$stCount->fetchColumn();
    } catch (Throwable $e) {
        $total = 0;
    }

    $totalPages = max(1, (int)ceil(max(1, $total) / $perPage));
    if ($page > $totalPages) {
        $page = $totalPages;
    }
    $offset = ($page - 1) * $perPage;

    try {
        $st = $dbh->prepare("
            SELECT
              s.id, s.user_id, s.ip_address, s.user_agent, s.created_at, s.last_seen_at, s.revoked_at,
              u.username, u.name, u.email, u.status AS user_status
            FROM user_sessions s
            LEFT JOIN users u ON u.id = s.user_id
            WHERE {$whereSql}
            ORDER BY COALESCE(s.last_seen_at, s.created_at) DESC, s.id DESC
            LIMIT {$perPage} OFFSET {$offset}
        ");
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $rows = [];
    }
}

$fromN = $total === 0 ? 0 : ((($page - 1) * $perPage) + 1);
$toN = min($total, ($page - 1) * $perPage + count($rows));
$dateLabel = date('M j, Y', strtotime($dateFrom) ?: time()) . ' - ' . date('M j, Y', strtotime($dateTo) ?: time());

$qs = static function (array $extra = []) use ($q, $deviceType, $platform, $statusFilter, $userFilter, $dateFrom, $dateTo, $page, $perPage, $kindFilter): string {
    $base = [
        'kind' => $kindFilter,
        'q' => $q,
        'device_type' => $deviceType,
        'platform' => $platform,
        'status' => $statusFilter,
        'user' => $userFilter,
        'from' => $dateFrom,
        'to' => $dateTo,
        'page' => $page,
        'per_page' => $perPage,
    ];
    foreach ($extra as $k => $v) {
        $base[$k] = $v;
    }
    foreach ($base as $k => $v) {
        if ($v === '' || $v === null) {
            unset($base[$k]);
            continue;
        }
        if (in_array($k, ['device_type', 'platform', 'status', 'user'], true) && $v === 'all' && !array_key_exists($k, $extra)) {
            unset($base[$k]);
            continue;
        }
        if ($k === 'page' && (int)$v === 1 && !array_key_exists('page', $extra)) {
            unset($base[$k]);
            continue;
        }
        if ($k === 'per_page' && (int)$v === 10 && !array_key_exists('per_page', $extra)) {
            unset($base[$k]);
        }
    }
    return 'device_activity.php' . ($base ? ('?' . http_build_query($base)) : '');
};

$metricCards = [
    ['label' => 'Total Devices', 'icon' => 'fa-desktop', 'cls' => 'blue', 'm' => $metrics['total']],
    ['label' => 'Active Now', 'icon' => 'fa-laptop', 'cls' => 'green', 'm' => $metrics['active']],
    ['label' => 'Mobile Devices', 'icon' => 'fa-mobile', 'cls' => 'purple', 'm' => $metrics['mobile']],
    ['label' => 'Desktop Devices', 'icon' => 'fa-desktop', 'cls' => 'orange', 'm' => $metrics['desktop']],
    ['label' => 'Blocked Devices', 'icon' => 'fa-ban', 'cls' => 'red', 'm' => $metrics['blocked']],
];

org_admin_render_head('Device Activity');
require_once __DIR__ . '/includes/admin_chrome.php';
admin_chrome_open(null, [
    'title' => 'Device Activity',
    'crumb' => [
        ['Overview', 'overview.php'],
        ['Device Activity', null],
    ],
    'description' => 'Monitor devices and sessions used to access your platform.',
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
    padding:6px 10px !important;margin:0 !important;flex:1 1 auto !important;background:#f4f6fb !important;
  }
  .da-wrap{flex:1 1 auto;min-height:0;height:100%;display:flex;flex-direction:column;gap:8px;overflow:hidden;}
  .da-metrics{flex:0 0 auto;display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:8px;}
  .da-metric{
    background:#fff;border:1px solid #eef2f7;border-radius:10px;padding:10px 12px;
    box-shadow:0 1px 2px rgba(15,23,42,.04);display:flex;align-items:flex-start;gap:10px;min-width:0;
  }
  .da-metric .ico{
    width:32px;height:32px;border-radius:9px;display:flex;align-items:center;justify-content:center;
    font-size:13px;flex:0 0 32px;
  }
  .da-metric .ico.blue{background:#eff6ff;color:#2563eb;}
  .da-metric .ico.green{background:#dcfce7;color:#16a34a;}
  .da-metric .ico.purple{background:#f3e8ff;color:#7c3aed;}
  .da-metric .ico.orange{background:#ffedd5;color:#ea580c;}
  .da-metric .ico.red{background:#fee2e2;color:#dc2626;}
  .da-metric .k{font-size:10px;font-weight:700;color:#64748b;}
  .da-metric .v{margin-top:2px;font-size:18px;font-weight:800;color:#0f172a;letter-spacing:-.02em;line-height:1.1;}
  .da-metric .d{margin-top:2px;font-size:10px;font-weight:700;}
  .da-metric .d.up{color:#16a34a;}
  .da-metric .d.down{color:#dc2626;}
  .da-metric .d.flat{color:#94a3b8;}
  .da-panel{
    flex:1 1 auto;min-height:0;background:#fff;border:1px solid #eef2f7;border-radius:10px;
    box-shadow:0 1px 2px rgba(15,23,42,.04);display:flex;flex-direction:column;overflow:hidden;
  }
  .da-filters{
    flex:0 0 auto;display:flex;flex-wrap:wrap;gap:8px;align-items:center;
    padding:8px 10px;border-bottom:1px solid #eef2f7;background:#fafbfc;
  }
  .da-filters label{display:none;}
  .da-filters input[type=date],.da-filters select,.da-filters .da-q{
    height:30px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:11px;
    font-weight:700;color:#334155;background:#fff;
  }
  .da-filters .da-dates{display:flex;align-items:center;gap:6px;}
  .da-filters .da-dates .sep{color:#94a3b8;font-size:11px;font-weight:700;}
  .da-filters .da-q-wrap{position:relative;flex:1 1 180px;min-width:160px;}
  .da-filters .da-q-wrap i{position:absolute;left:10px;top:50%;transform:translateY(-50%);color:#94a3b8;font-size:12px;}
  .da-filters .da-q{width:100%;padding-left:28px;font-weight:600;}
  .da-btn{
    height:30px;padding:0 10px;border-radius:8px;border:1px solid #e2e8f0;background:#fff;
    font-size:11px;font-weight:700;color:#334155;display:inline-flex;align-items:center;gap:6px;
    text-decoration:none;cursor:pointer;
  }
  .da-btn:hover{background:#f8fafc;text-decoration:none;color:#0f172a;}
  .da-table-scroll{flex:1 1 auto;min-height:0;overflow:auto;overscroll-behavior:contain;}
  .da-table{width:100%;border-collapse:separate;border-spacing:0;font-size:12px;}
  .da-table thead th{
    position:sticky;top:0;z-index:1;background:#f8fafc;border-bottom:1px solid #e2e8f0;
    text-align:left;padding:8px 10px;font-size:11px;font-weight:800;color:#64748b;white-space:nowrap;
  }
  .da-table tbody td{padding:9px 10px;border-bottom:1px solid #f1f5f9;vertical-align:middle;color:#334155;}
  .da-table tbody tr:hover td{background:#f8fafc;}
  .da-user{display:flex;align-items:center;gap:8px;min-width:0;}
  .da-av{
    width:30px;height:30px;border-radius:999px;flex:0 0 30px;display:flex;align-items:center;justify-content:center;
    color:#fff;font-size:10px;font-weight:800;
  }
  .da-user .n{font-size:12px;font-weight:800;color:#0f172a;line-height:1.2;}
  .da-user .n a{color:inherit;text-decoration:none;}
  .da-user .n a:hover{color:#2563eb;}
  .da-user .e{font-size:11px;color:#94a3b8;font-weight:600;margin-top:1px;}
  .da-dev .t{font-size:12px;font-weight:800;color:#0f172a;line-height:1.2;}
  .da-dev .s{font-size:11px;font-weight:600;color:#94a3b8;margin-top:1px;}
  .da-type{display:inline-flex;align-items:center;gap:6px;font-size:12px;font-weight:700;color:#475569;}
  .da-type i{width:16px;text-align:center;color:#64748b;}
  .da-plat{display:inline-flex;align-items:center;gap:6px;font-size:12px;font-weight:700;color:#334155;}
  .da-plat i{width:14px;text-align:center;color:#64748b;}
  .da-mono{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:11px;font-weight:700;color:#475569;}
  .da-loc{display:inline-flex;align-items:center;gap:6px;font-size:12px;font-weight:700;color:#334155;}
  .da-loc .flag{font-size:14px;line-height:1;}
  .da-pill{
    display:inline-flex;align-items:center;height:20px;padding:0 8px;border-radius:999px;
    font-size:10px;font-weight:800;
  }
  .da-pill.active{background:#dcfce7;color:#166534;}
  .da-pill.inactive{background:#e2e8f0;color:#475569;}
  .da-pill.blocked{background:#fee2e2;color:#b91c1c;}
  .da-time{font-size:11px;font-weight:700;color:#475569;white-space:nowrap;line-height:1.25;}
  .da-time .d{display:block;}
  .da-empty{padding:28px 16px;text-align:center;color:#64748b;font-size:12px;font-weight:600;}
  .da-foot{
    flex:0 0 auto;display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;
    padding:8px 12px;border-top:1px solid #eef2f7;font-size:11px;font-weight:700;color:#64748b;background:#fff;
  }
  .da-pager{display:flex;align-items:center;gap:4px;}
  .da-pager a,.da-pager span{
    min-width:28px;height:28px;padding:0 8px;border-radius:7px;border:1px solid #e2e8f0;background:#fff;
    display:inline-flex;align-items:center;justify-content:center;text-decoration:none;color:#334155;font-weight:800;font-size:12px;
  }
  .da-pager a:hover{background:#f8fafc;text-decoration:none;}
  .da-pager .is-active{background:#2563eb;border-color:#2563eb;color:#fff;}
  .da-pager .is-disabled{opacity:.45;pointer-events:none;}
  @media (max-width:1100px){.da-metrics{grid-template-columns:repeat(3,minmax(0,1fr));}}
<?= admin_kind_tabs_css('ak') ?>
</style>

<div class="sh-mainpanel">
  <div class="sh-pagebody">
    <div class="da-wrap">
      <?= admin_kind_tabs_html(
          $kindFilter,
          $kindCounts,
          fn($k) => $qs(['kind' => $k, 'page' => 1]),
          fn($s) => org_admin_h((string)$s),
          'ak',
          'activity'
      ) ?>
      <div class="da-metrics" aria-label="Device metrics">
        <?php foreach ($metricCards as $c): ?>
          <?php $m = $c['m']; ?>
          <div class="da-metric">
            <span class="ico <?= org_admin_h((string)$c['cls']) ?>" aria-hidden="true">
              <i class="fa <?= org_admin_h((string)$c['icon']) ?>"></i>
            </span>
            <div>
              <div class="k"><?= org_admin_h((string)$c['label']) ?></div>
              <div class="v"><?= number_format((int)$m['value']) ?></div>
              <div class="d <?= org_admin_h((string)$m['sub_cls']) ?>"><?= org_admin_h((string)$m['sub']) ?></div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>

      <div class="da-panel">
        <form class="da-filters" method="get" action="device_activity.php">
        <input type="hidden" name="kind" value="<?= org_admin_h($kindFilter) ?>">
          <div class="da-dates" title="<?= org_admin_h($dateLabel) ?>">
            <i class="fa fa-calendar" style="color:#94a3b8;" aria-hidden="true"></i>
            <label for="da_from">From</label>
            <input id="da_from" type="date" name="from" value="<?= org_admin_h($dateFrom) ?>" onchange="this.form.submit()">
            <span class="sep">–</span>
            <label for="da_to">To</label>
            <input id="da_to" type="date" name="to" value="<?= org_admin_h($dateTo) ?>" onchange="this.form.submit()">
          </div>
          <select name="user" aria-label="Users" onchange="this.form.submit()">
            <option value="all"<?= $userFilter === 'all' ? ' selected' : '' ?>>All Users</option>
            <option value="active"<?= $userFilter === 'active' ? ' selected' : '' ?>>Active Users</option>
            <option value="blocked"<?= $userFilter === 'blocked' ? ' selected' : '' ?>>Blocked Users</option>
          </select>
          <select name="device_type" aria-label="Device types" onchange="this.form.submit()">
            <option value="all"<?= $deviceType === 'all' ? ' selected' : '' ?>>All Device Types</option>
            <option value="desktop"<?= $deviceType === 'desktop' ? ' selected' : '' ?>>Desktop</option>
            <option value="mobile"<?= $deviceType === 'mobile' ? ' selected' : '' ?>>Mobile</option>
          </select>
          <select name="platform" aria-label="Platforms" onchange="this.form.submit()">
            <option value="all"<?= $platform === 'all' ? ' selected' : '' ?>>All Platforms</option>
            <option value="windows"<?= $platform === 'windows' ? ' selected' : '' ?>>Windows</option>
            <option value="apple"<?= $platform === 'apple' ? ' selected' : '' ?>>Apple</option>
            <option value="android"<?= $platform === 'android' ? ' selected' : '' ?>>Android</option>
            <option value="linux"<?= $platform === 'linux' ? ' selected' : '' ?>>Linux</option>
            <option value="other"<?= $platform === 'other' ? ' selected' : '' ?>>Other</option>
          </select>
          <select name="status" aria-label="Statuses" onchange="this.form.submit()">
            <option value="all"<?= $statusFilter === 'all' ? ' selected' : '' ?>>All Statuses</option>
            <option value="active"<?= $statusFilter === 'active' ? ' selected' : '' ?>>Active</option>
            <option value="inactive"<?= $statusFilter === 'inactive' ? ' selected' : '' ?>>Inactive</option>
            <option value="blocked"<?= $statusFilter === 'blocked' ? ' selected' : '' ?>>Blocked</option>
          </select>
          <div class="da-q-wrap">
            <i class="fa fa-search" aria-hidden="true"></i>
            <input class="da-q" type="search" name="q" value="<?= org_admin_h($q) ?>" placeholder="Search by user or IP address…">
          </div>
          <button type="submit" class="da-btn" style="display:none" aria-hidden="true">Apply</button>
          <a class="da-btn" href="<?= org_admin_h($qs(['export' => '1'])) ?>" title="CSV export coming soon"><i class="fa fa-download"></i> Export</a>
        </form>

        <div class="da-table-scroll">
          <?php if (!$hasTable): ?>
            <div class="da-empty">Session table is not installed yet. Run <code>sql_user_sessions.sql</code> first.</div>
          <?php elseif ($rows === []): ?>
            <div class="da-empty">No devices match your filters.</div>
          <?php else: ?>
            <table class="da-table">
              <thead>
                <tr>
                  <th>User</th>
                  <th>Device</th>
                  <th>Device Type</th>
                  <th>Platform / OS</th>
                  <th>IP Address</th>
                  <th>Location</th>
                  <th>Status</th>
                  <th>Last Active</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($rows as $row): ?>
                  <?php
                  $uid = (int)($row['user_id'] ?? 0);
                  $name = trim((string)($row['name'] ?? ''));
                  $uname = trim((string)($row['username'] ?? ''));
                  $email = trim((string)($row['email'] ?? ''));
                  if ($name === '') {
                      $name = $uname !== '' ? $uname : ('User #' . $uid);
                  }
                  $avKey = $uname !== '' ? $uname : $name;
                  $parsed = admin_login_activity_parse_ua((string)($row['user_agent'] ?? ''));
                  $isMobile = !empty($parsed['is_mobile']);
                  $ip = trim((string)($row['ip_address'] ?? ''));
                  $st = admin_device_activity_status(
                      isset($row['revoked_at']) ? (string)$row['revoked_at'] : null,
                      isset($row['last_seen_at']) ? (string)$row['last_seen_at'] : null
                  );
                  $seenRaw = (string)($row['last_seen_at'] ?? $row['created_at'] ?? '');
                  $seenTs = $seenRaw !== '' ? strtotime($seenRaw) : false;
                  $seenDate = $seenTs ? date('M j, Y', $seenTs) : '—';
                  $seenTime = $seenTs ? date('g:i A', $seenTs) : '';
                  ?>
                  <tr>
                    <td>
                      <div class="da-user">
                        <span class="da-av" style="background:<?= org_admin_h(posts_admin_avatar_color($avKey)) ?>;">
                          <?= org_admin_h(posts_admin_initials($name)) ?>
                        </span>
                        <div>
                          <div class="n">
                            <?php if ($uid > 0): ?>
                              <a href="user_form.php?id=<?= $uid ?>"><?= org_admin_h($name) ?></a>
                            <?php else: ?>
                              <?= org_admin_h($name) ?>
                            <?php endif; ?>
                          </div>
                          <div class="e"><?= org_admin_h($email !== '' ? $email : ($uname !== '' ? '@' . $uname : '—')) ?></div>
                        </div>
                      </div>
                    </td>
                    <td>
                      <div class="da-dev">
                        <div class="t"><?= org_admin_h((string)($parsed['device_title'] ?? $parsed['device_label'])) ?></div>
                        <div class="s"><?= org_admin_h((string)$parsed['browser']) ?></div>
                      </div>
                    </td>
                    <td>
                      <span class="da-type">
                        <i class="fa <?= $isMobile ? 'fa-mobile' : 'fa-desktop' ?>" aria-hidden="true"></i>
                        <?= $isMobile ? 'Mobile' : 'Desktop' ?>
                      </span>
                    </td>
                    <td>
                      <span class="da-plat">
                        <i class="fa <?= org_admin_h((string)($parsed['os_icon'] ?? 'fa-laptop')) ?>" aria-hidden="true"></i>
                        <?= org_admin_h((string)$parsed['os']) ?>
                      </span>
                    </td>
                    <td><span class="da-mono"><?= org_admin_h($ip !== '' ? $ip : '—') ?></span></td>
                    <td>
                      <span class="da-loc">
                        <span class="flag" aria-hidden="true">🌐</span>
                        <?= org_admin_h($ip !== '' ? 'Unknown location' : 'Unknown') ?>
                      </span>
                    </td>
                    <td>
                      <span class="da-pill <?= org_admin_h($st['cls']) ?>"><?= org_admin_h($st['label']) ?></span>
                    </td>
                    <td>
                      <div class="da-time">
                        <span class="d"><?= org_admin_h($seenDate) ?></span>
                        <?php if ($seenTime !== ''): ?><span><?= org_admin_h($seenTime) ?></span><?php endif; ?>
                      </div>
                    </td>
                    <td>
                      <div class="fries-menu">
                        <button type="button" class="fries-toggle" title="Actions" aria-label="Actions" aria-haspopup="true">
                          <span class="fries-icon" aria-hidden="true"></span>
                        </button>
                        <div class="fries-dropdown" role="menu">
                          <?php if ($uid > 0): ?>
                            <a class="fries-item" role="menuitem" href="user_form.php?id=<?= $uid ?>"><i class="fa fa-user"></i> View user</a>
                            <a class="fries-item" role="menuitem" href="login_activity.php?q=<?= rawurlencode($uname !== '' ? $uname : (string)$uid) ?>"><i class="fa fa-sign-in"></i> Login activity</a>
                            <a class="fries-item" role="menuitem" href="user_activity.php?user_id=<?= $uid ?>"><i class="fa fa-heartbeat"></i> User activity</a>
                          <?php endif; ?>
                          <?php if ($ip !== ''): ?>
                            <a class="fries-item" role="menuitem" href="device_activity.php?q=<?= rawurlencode($ip) ?>"><i class="fa fa-search"></i> Same IP</a>
                          <?php endif; ?>
                        </div>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>
        </div>

        <div class="da-foot">
          <span>Showing <?= (int)$fromN ?> to <?= (int)$toN ?> of <?= number_format((int)$total) ?> devices</span>
          <div class="da-pager">
            <a class="<?= $page <= 1 ? 'is-disabled' : '' ?>" href="<?= org_admin_h($qs(['page' => max(1, $page - 1)])) ?>">&lsaquo;</a>
            <?php
            $startP = max(1, $page - 2);
            $endP = min($totalPages, $startP + 4);
            $startP = max(1, $endP - 4);
            for ($p = $startP; $p <= $endP; $p++):
            ?>
              <a class="<?= $p === $page ? 'is-active' : '' ?>" href="<?= org_admin_h($qs(['page' => $p])) ?>"><?= $p ?></a>
            <?php endfor; ?>
            <?php if ($endP < $totalPages): ?>
              <span>…</span>
              <a href="<?= org_admin_h($qs(['page' => $totalPages])) ?>"><?= (int)$totalPages ?></a>
            <?php endif; ?>
            <a class="<?= $page >= $totalPages ? 'is-disabled' : '' ?>" href="<?= org_admin_h($qs(['page' => min($totalPages, $page + 1)])) ?>">&rsaquo;</a>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<?php org_admin_render_foot(); ?>
