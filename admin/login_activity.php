<?php
declare(strict_types=1);

/**
 * Admin — Login Activity (screenshot layout; viewport-fit, no page scroll).
 * Live rows from user_sessions + users. Failed logins are not logged yet → 0.
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
$loginType = strtolower(trim((string)($_GET['login_type'] ?? 'all')));
if (!in_array($loginType, ['all', 'web', 'mobile'], true)) {
    $loginType = 'all';
}
$location = strtolower(trim((string)($_GET['location'] ?? 'all')));
if (!in_array($location, ['all', 'known', 'unknown'], true)) {
    $location = 'all';
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

$metrics = admin_login_activity_metrics($dbh);
$rows = [];
$total = 0;
$totalPages = 1;

if ($hasTable) {
    $where = [
        'DATE(COALESCE(s.created_at, s.last_seen_at)) >= :from',
        'DATE(COALESCE(s.created_at, s.last_seen_at)) <= :to',
        admin_kind_user_where($kindFilter, 'u'),
    ];
    $params = [':from' => $dateFrom, ':to' => $dateTo];

    if ($q !== '') {
        $where[] = '(u.username LIKE :q OR u.name LIKE :q2 OR u.email LIKE :q3 OR s.ip_address LIKE :q4 OR CAST(s.user_id AS CHAR) = :qid)';
        $like = '%' . $q . '%';
        $params[':q'] = $like;
        $params[':q2'] = $like;
        $params[':q3'] = $like;
        $params[':q4'] = $like;
        $params[':qid'] = $q;
    }
    if ($userFilter === 'active') {
        $where[] = '(u.status = 1 OR u.status IS NULL)';
    } elseif ($userFilter === 'blocked') {
        $where[] = 'u.status = 0';
    }
    if ($location === 'known') {
        $where[] = "(s.ip_address IS NOT NULL AND TRIM(s.ip_address) <> '')";
    } elseif ($location === 'unknown') {
        $where[] = "(s.ip_address IS NULL OR TRIM(s.ip_address) = '')";
    }
    if ($loginType === 'web') {
        $where[] = "(COALESCE(s.user_agent, '') NOT LIKE '%Mobile%' AND COALESCE(s.user_agent, '') NOT LIKE '%Android%' AND COALESCE(s.user_agent, '') NOT LIKE '%iPhone%' AND COALESCE(s.user_agent, '') NOT LIKE '%iPad%')";
    } elseif ($loginType === 'mobile') {
        $where[] = "(s.user_agent LIKE '%Mobile%' OR s.user_agent LIKE '%Android%' OR s.user_agent LIKE '%iPhone%' OR s.user_agent LIKE '%iPad%')";
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
            ORDER BY COALESCE(s.created_at, s.last_seen_at) DESC, s.id DESC
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

$qs = static function (array $extra = []) use ($q, $loginType, $location, $userFilter, $dateFrom, $dateTo, $page, $perPage, $kindFilter): string {
    $base = [
        'kind' => $kindFilter,
        'q' => $q,
        'login_type' => $loginType,
        'location' => $location,
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
        if (in_array($k, ['login_type', 'location', 'user'], true) && $v === 'all' && !array_key_exists($k, $extra)) {
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
    return 'login_activity.php' . ($base ? ('?' . http_build_query($base)) : '');
};

$metricCards = [
    [
        'label' => 'Total Logins',
        'icon' => 'fa-line-chart',
        'cls' => 'blue',
        'm' => $metrics['total'],
    ],
    [
        'label' => 'Successful Logins',
        'icon' => 'fa-shield',
        'cls' => 'green',
        'm' => $metrics['success'],
    ],
    [
        'label' => 'Failed Logins',
        'icon' => 'fa-exclamation-triangle',
        'cls' => 'orange',
        'm' => $metrics['failed'],
    ],
    [
        'label' => 'Unique Locations',
        'icon' => 'fa-map-marker',
        'cls' => 'purple',
        'm' => $metrics['locations'],
    ],
    [
        'label' => 'New Devices',
        'icon' => 'fa-desktop',
        'cls' => 'red',
        'm' => $metrics['devices'],
    ],
];

org_admin_render_head('Login Activity');
require_once __DIR__ . '/includes/admin_chrome.php';
admin_chrome_open(null, [
    'title' => 'Login Activity',
    'crumb' => [
        ['Overview', 'overview.php'],
        ['Login Activity', null],
    ],
    'description' => 'Monitor user login attempts and account access activity across your platform.',
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
  .la-wrap{
    flex:1 1 auto;min-height:0;height:100%;display:flex;flex-direction:column;gap:8px;overflow:hidden;
  }
  .la-metrics{flex:0 0 auto;display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:8px;}
  .la-metric{
    background:#fff;border:1px solid #eef2f7;border-radius:10px;padding:10px 12px;
    box-shadow:0 1px 2px rgba(15,23,42,.04);display:flex;align-items:flex-start;gap:10px;min-width:0;
  }
  .la-metric .ico{
    width:32px;height:32px;border-radius:9px;display:flex;align-items:center;justify-content:center;
    font-size:13px;flex:0 0 32px;
  }
  .la-metric .ico.blue{background:#eff6ff;color:#2563eb;}
  .la-metric .ico.green{background:#dcfce7;color:#16a34a;}
  .la-metric .ico.orange{background:#ffedd5;color:#ea580c;}
  .la-metric .ico.purple{background:#f3e8ff;color:#7c3aed;}
  .la-metric .ico.red{background:#fee2e2;color:#dc2626;}
  .la-metric .k{font-size:10px;font-weight:700;color:#64748b;}
  .la-metric .v{margin-top:2px;font-size:18px;font-weight:800;color:#0f172a;letter-spacing:-.02em;line-height:1.1;}
  .la-metric .d{margin-top:2px;font-size:10px;font-weight:700;}
  .la-metric .d.up{color:#16a34a;}
  .la-metric .d.down{color:#dc2626;}
  .la-metric .d.flat{color:#94a3b8;}
  .la-panel{
    flex:1 1 auto;min-height:0;background:#fff;border:1px solid #eef2f7;border-radius:10px;
    box-shadow:0 1px 2px rgba(15,23,42,.04);display:flex;flex-direction:column;overflow:hidden;
  }
  .la-filters{
    flex:0 0 auto;display:flex;flex-wrap:wrap;gap:8px;align-items:center;
    padding:8px 10px;border-bottom:1px solid #eef2f7;background:#fafbfc;
  }
  .la-filters label{display:none;}
  .la-filters input[type=date],.la-filters select,.la-filters .la-q{
    height:30px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:11px;
    font-weight:700;color:#334155;background:#fff;
  }
  .la-filters .la-dates{display:flex;align-items:center;gap:6px;}
  .la-filters .la-dates .sep{color:#94a3b8;font-size:11px;font-weight:700;}
  .la-filters .la-q-wrap{position:relative;flex:1 1 180px;min-width:160px;}
  .la-filters .la-q-wrap i{position:absolute;left:10px;top:50%;transform:translateY(-50%);color:#94a3b8;font-size:12px;}
  .la-filters .la-q{width:100%;padding-left:28px;font-weight:600;}
  .la-btn{
    height:30px;padding:0 10px;border-radius:8px;border:1px solid #e2e8f0;background:#fff;
    font-size:11px;font-weight:700;color:#334155;display:inline-flex;align-items:center;gap:6px;
    text-decoration:none;cursor:pointer;
  }
  .la-btn:hover{background:#f8fafc;text-decoration:none;color:#0f172a;}
  .la-table-scroll{flex:1 1 auto;min-height:0;overflow:auto;overscroll-behavior:contain;}
  .la-table{width:100%;border-collapse:separate;border-spacing:0;font-size:12px;}
  .la-table thead th{
    position:sticky;top:0;z-index:1;background:#f8fafc;border-bottom:1px solid #e2e8f0;
    text-align:left;padding:8px 10px;font-size:11px;font-weight:800;color:#64748b;white-space:nowrap;
  }
  .la-table tbody td{
    padding:9px 10px;border-bottom:1px solid #f1f5f9;vertical-align:middle;color:#334155;
  }
  .la-table tbody tr:hover td{background:#f8fafc;}
  .la-user{display:flex;align-items:center;gap:8px;min-width:0;}
  .la-av{
    width:30px;height:30px;border-radius:999px;flex:0 0 30px;display:flex;align-items:center;justify-content:center;
    color:#fff;font-size:10px;font-weight:800;
  }
  .la-user .n{font-size:12px;font-weight:800;color:#0f172a;line-height:1.2;}
  .la-user .n a{color:inherit;text-decoration:none;}
  .la-user .n a:hover{color:#2563eb;}
  .la-user .e{font-size:11px;color:#94a3b8;font-weight:600;margin-top:1px;}
  .la-type{display:inline-flex;align-items:center;gap:6px;font-size:12px;font-weight:700;color:#475569;}
  .la-type i{width:18px;text-align:center;color:#64748b;}
  .la-pill{
    display:inline-flex;align-items:center;height:20px;padding:0 8px;border-radius:999px;
    font-size:10px;font-weight:800;
  }
  .la-pill.ok{background:#dcfce7;color:#166534;}
  .la-pill.bad{background:#fee2e2;color:#b91c1c;}
  .la-mono{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:11px;font-weight:700;color:#475569;}
  .la-loc{display:inline-flex;align-items:center;gap:6px;font-size:12px;font-weight:700;color:#334155;}
  .la-loc .flag{font-size:14px;line-height:1;}
  .la-dev{display:flex;align-items:flex-start;gap:6px;min-width:0;}
  .la-dev i{margin-top:2px;color:#64748b;width:14px;text-align:center;}
  .la-dev .t{font-size:12px;font-weight:700;color:#0f172a;line-height:1.25;}
  .la-dev .s{font-size:10px;font-weight:600;color:#94a3b8;margin-top:1px;}
  .la-time{font-size:11px;font-weight:700;color:#475569;white-space:nowrap;}
  .la-empty{padding:28px 16px;text-align:center;color:#64748b;font-size:12px;font-weight:600;}
  .la-foot{
    flex:0 0 auto;display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;
    padding:8px 12px;border-top:1px solid #eef2f7;font-size:11px;font-weight:700;color:#64748b;background:#fff;
  }
  .la-pager{display:flex;align-items:center;gap:4px;}
  .la-pager a,.la-pager span{
    min-width:28px;height:28px;padding:0 8px;border-radius:7px;border:1px solid #e2e8f0;background:#fff;
    display:inline-flex;align-items:center;justify-content:center;text-decoration:none;color:#334155;font-weight:800;font-size:12px;
  }
  .la-pager a:hover{background:#f8fafc;text-decoration:none;}
  .la-pager .is-active{background:#2563eb;border-color:#2563eb;color:#fff;}
  .la-pager .is-disabled{opacity:.45;pointer-events:none;}
  @media (max-width:1100px){
    .la-metrics{grid-template-columns:repeat(3,minmax(0,1fr));}
  }
<?= admin_kind_tabs_css('ak') ?>
</style>

<div class="sh-mainpanel">
  <div class="sh-pagebody">
    <div class="la-wrap">
      <?= admin_kind_tabs_html(
          $kindFilter,
          $kindCounts,
          fn($k) => $qs(['kind' => $k, 'page' => 1]),
          fn($s) => org_admin_h((string)$s),
          'ak',
          'activity'
      ) ?>
      <div class="la-metrics" aria-label="Login metrics">
        <?php foreach ($metricCards as $c): ?>
          <?php $m = $c['m']; ?>
          <div class="la-metric">
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

      <div class="la-panel">
        <form class="la-filters" method="get" action="login_activity.php">
        <input type="hidden" name="kind" value="<?= org_admin_h($kindFilter) ?>">
          <div class="la-dates" title="<?= org_admin_h($dateLabel) ?>">
            <i class="fa fa-calendar" style="color:#94a3b8;" aria-hidden="true"></i>
            <label for="la_from">From</label>
            <input id="la_from" type="date" name="from" value="<?= org_admin_h($dateFrom) ?>">
            <span class="sep">–</span>
            <label for="la_to">To</label>
            <input id="la_to" type="date" name="to" value="<?= org_admin_h($dateTo) ?>">
          </div>
          <select name="user" aria-label="Users">
            <option value="all"<?= $userFilter === 'all' ? ' selected' : '' ?>>All Users</option>
            <option value="active"<?= $userFilter === 'active' ? ' selected' : '' ?>>Active Users</option>
            <option value="blocked"<?= $userFilter === 'blocked' ? ' selected' : '' ?>>Blocked Users</option>
          </select>
          <select name="login_type" aria-label="Login types">
            <option value="all"<?= $loginType === 'all' ? ' selected' : '' ?>>All Login Types</option>
            <option value="web"<?= $loginType === 'web' ? ' selected' : '' ?>>Web</option>
            <option value="mobile"<?= $loginType === 'mobile' ? ' selected' : '' ?>>Mobile App</option>
          </select>
          <select name="location" aria-label="Locations">
            <option value="all"<?= $location === 'all' ? ' selected' : '' ?>>All Locations</option>
            <option value="known"<?= $location === 'known' ? ' selected' : '' ?>>With IP</option>
            <option value="unknown"<?= $location === 'unknown' ? ' selected' : '' ?>>Unknown IP</option>
          </select>
          <div class="la-q-wrap">
            <i class="fa fa-search" aria-hidden="true"></i>
            <input class="la-q" type="search" name="q" value="<?= org_admin_h($q) ?>" placeholder="Search by user, email, IP address…">
          </div>
          <button type="submit" class="la-btn">Filters</button>
          <a class="la-btn" href="<?= org_admin_h($qs(['export' => '1'])) ?>" title="CSV export coming soon"><i class="fa fa-download"></i> Export</a>
        </form>

        <div class="la-table-scroll">
          <?php if (!$hasTable): ?>
            <div class="la-empty">Session table is not installed yet. Run <code>sql_user_sessions.sql</code> first.</div>
          <?php elseif ($rows === []): ?>
            <div class="la-empty">No login activity matches your filters.</div>
          <?php else: ?>
            <table class="la-table">
              <thead>
                <tr>
                  <th>User</th>
                  <th>Login Type</th>
                  <th>Status</th>
                  <th>IP Address</th>
                  <th>Location</th>
                  <th>Device / Browser</th>
                  <th>Time</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($rows as $row): ?>
                  <?php
                  $sid = (int)($row['id'] ?? 0);
                  $uid = (int)($row['user_id'] ?? 0);
                  $name = trim((string)($row['name'] ?? ''));
                  $uname = trim((string)($row['username'] ?? ''));
                  $email = trim((string)($row['email'] ?? ''));
                  if ($name === '') {
                      $name = $uname !== '' ? $uname : ('User #' . $uid);
                  }
                  $avKey = $uname !== '' ? $uname : $name;
                  $ua = (string)($row['user_agent'] ?? '');
                  $parsed = admin_login_activity_parse_ua($ua);
                  $ip = trim((string)($row['ip_address'] ?? ''));
                  $whenRaw = (string)($row['created_at'] ?? $row['last_seen_at'] ?? '');
                  $when = $whenRaw !== '' ? date('M j, Y g:i A', strtotime($whenRaw) ?: time()) : '—';
                  // Sessions exist only after successful login.
                  $statusOk = true;
                  $locLabel = $ip !== '' ? 'Unknown location' : 'Unknown';
                  ?>
                  <tr>
                    <td>
                      <div class="la-user">
                        <span class="la-av" style="background:<?= org_admin_h(posts_admin_avatar_color($avKey)) ?>;">
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
                      <span class="la-type">
                        <i class="fa <?= org_admin_h($parsed['login_type_icon']) ?>" aria-hidden="true"></i>
                        <?= org_admin_h($parsed['login_type']) ?>
                      </span>
                    </td>
                    <td>
                      <span class="la-pill <?= $statusOk ? 'ok' : 'bad' ?>">
                        <?= $statusOk ? 'Success' : 'Failed' ?>
                      </span>
                    </td>
                    <td><span class="la-mono"><?= org_admin_h($ip !== '' ? $ip : '—') ?></span></td>
                    <td>
                      <span class="la-loc">
                        <span class="flag" aria-hidden="true">🌐</span>
                        <?= org_admin_h($locLabel) ?>
                      </span>
                    </td>
                    <td>
                      <div class="la-dev">
                        <i class="fa <?= org_admin_h($parsed['browser_icon']) ?>" aria-hidden="true"></i>
                        <div>
                          <div class="t"><?= org_admin_h($parsed['browser']) ?></div>
                          <div class="s"><?= org_admin_h($parsed['os']) ?></div>
                        </div>
                      </div>
                    </td>
                    <td><span class="la-time"><?= org_admin_h($when) ?></span></td>
                    <td>
                      <div class="fries-menu">
                        <button type="button" class="fries-toggle" title="Actions" aria-label="Actions" aria-haspopup="true">
                          <span class="fries-icon" aria-hidden="true"></span>
                        </button>
                        <div class="fries-dropdown" role="menu">
                          <?php if ($uid > 0): ?>
                            <a class="fries-item" role="menuitem" href="user_form.php?id=<?= $uid ?>"><i class="fa fa-user"></i> View user</a>
                            <a class="fries-item" role="menuitem" href="user_activity.php?user_id=<?= $uid ?>"><i class="fa fa-heartbeat"></i> User activity</a>
                          <?php endif; ?>
                          <a class="fries-item" role="menuitem" href="login_activity.php?q=<?= rawurlencode($ip !== '' ? $ip : (string)$uid) ?>"><i class="fa fa-search"></i> Related sessions</a>
                        </div>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>
        </div>

        <div class="la-foot">
          <span>Showing <?= (int)$fromN ?> to <?= (int)$toN ?> of <?= number_format((int)$total) ?> logins</span>
          <div class="la-pager">
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
