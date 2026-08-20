<?php
declare(strict_types=1);

/**
 * Admin — Dashboard (viewport-fit; matches Dashboard screenshot).
 * Auth gates must remain at top (force password change / status).
 */
require_once __DIR__ . '/includes/session_admin.php';
requireAdminLogin();

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/controller.php';

$controller = new Controller();
$dbh = $controller->pdo();

// Force password change gate (cannot be bypassed by typing dashboard URL)
$adminId = (int)($_SESSION['admin_id'] ?? 0);
if ($adminId <= 0) {
    clearAdminSession();
    header('Location: index.php');
    exit;
}

$stForce = $dbh->prepare('SELECT force_password_change, status, fullname, username, email, image, role FROM admin WHERE idadmin = :id LIMIT 1');
$stForce->execute([':id' => $adminId]);
$acc = $stForce->fetch(PDO::FETCH_ASSOC);

if (!$acc || (int)$acc['status'] !== 1) {
    clearAdminSession();
    header('Location: index.php');
    exit;
}

if ((int)$acc['force_password_change'] === 1) {
    header('Location: change-password.php?force=1');
    exit;
}

require_once __DIR__ . '/includes/org_admin_helpers_load.php';
require_once __DIR__ . '/includes/admin_layout.php';
require_once __DIR__ . '/includes/admin_chrome.php';
require_once __DIR__ . '/includes/admin_platform_settings.php';
require_once __DIR__ . '/includes/posts_admin_helpers.php';
require_once __DIR__ . '/includes/msb_moderation_activity.php';
require_once __DIR__ . '/includes/admin_overview_helpers.php';
require_once __DIR__ . '/../public_user/includes/msb_reports.php';

$profile = admin_chrome_profile();
$welcomeName = trim((string)($profile['firstName'] ?? ''));
if ($welcomeName === '') {
    $welcomeName = trim((string)($acc['fullname'] ?? ''));
}
if ($welcomeName === '') {
    $welcomeName = trim((string)($acc['username'] ?? 'Admin'));
}
if ($welcomeName === '') {
    $welcomeName = 'Admin';
}

$rangeDays = (int)($_GET['range'] ?? 7);
if (!in_array($rangeDays, [7, 14, 30], true)) {
    $rangeDays = 7;
}

$dashKind = admin_overview_normalize_kind((string)($_GET['kind'] ?? 'personal'));
$kindProfile = admin_overview_kind_profile($dashKind);
$kindCounts = admin_overview_kind_counts($dbh);

$metrics = admin_overview_metric_bundle_for_kind($dbh, $dashKind);
$engBundle = admin_overview_engagement_bundle_for_kind($dbh, $dashKind);
$series = admin_overview_multi_activity_series_for_kind($dbh, $dashKind, $rangeDays);

$postStats = admin_overview_post_stats_for_kind($dbh, $dashKind);
$contentStatus = admin_overview_content_status($postStats);
$contentTotal = (int)($postStats['all']['value'] ?? 0);
$donutBg = admin_overview_donut_bg($contentStatus);

$health = admin_platform_settings_health_checks($dbh);
$healthOk = true;
foreach ($health as $hc) {
    if (($hc['status'] ?? '') !== 'operational') {
        $healthOk = false;
        break;
    }
}

$recentReports = [];
try {
    if (function_exists('msb_reports_ensure_schema')) {
        msb_reports_ensure_schema($dbh);
    }
    $recentReports = msb_reports_list_for_admin($dbh, '', '', 6);
} catch (Throwable $e) {
    $recentReports = [];
}

$topUsers = admin_overview_top_users_for_kind($dbh, $dashKind, 5);
$topCountries = admin_overview_top_countries($dbh, 5);

$settings = admin_platform_settings_load($dbh);
$nextBackup = trim((string)($settings['system_next_backup_at'] ?? ''));
$tasks = [
    [
        'label' => 'Auto-delete old drafts',
        'when' => date('M j, Y \\a\\t g:i A', strtotime('tomorrow 2:00')),
    ],
    [
        'label' => 'Database Backup',
        'when' => $nextBackup !== ''
            ? date('M j, Y \\a\\t g:i A', strtotime($nextBackup) ?: time())
            : date('M j, Y \\a\\t g:i A', strtotime('sunday 3:00')),
    ],
    [
        'label' => 'Generate Weekly Report',
        'when' => date('M j, Y \\a\\t g:i A', strtotime('monday 5:00')),
    ],
];

$lastUpdated = date('M j, Y \\a\\t g:i A');
$dashUrl = static function (array $extra = []) use ($rangeDays, $dashKind): string {
    $params = array_merge(['kind' => $dashKind, 'range' => $rangeDays], $extra);
    if (($params['kind'] ?? 'personal') === 'personal') {
        unset($params['kind']);
    }
    if ((int)($params['range'] ?? 7) === 7) {
        unset($params['range']);
    }
    return 'dashboard.php' . ($params ? ('?' . http_build_query($params)) : '');
};

$userMetricIcon = $dashKind === 'commerce' ? 'fa-shopping-bag' : ($dashKind === 'publisher' ? 'fa-bullhorn' : 'fa-users');
$metricCards = [
    [
        'label' => (string)$kindProfile['user_label'],
        'value' => (int)$metrics['users']['value'],
        'delta' => (int)$metrics['users']['delta_pct'],
        'icon' => $userMetricIcon,
        'cls' => 'blue',
        'href' => (string)$kindProfile['list_href'],
    ],
    [
        'label' => 'Total Posts',
        'value' => (int)$metrics['posts']['value'],
        'delta' => (int)$metrics['posts']['delta_pct'],
        'icon' => 'fa-file-text-o',
        'cls' => 'green',
        'href' => 'posts.php',
    ],
    [
        'label' => 'Total Comments',
        'value' => (int)$metrics['comments']['value'],
        'delta' => (int)$metrics['comments']['delta_pct'],
        'icon' => 'fa-comments',
        'cls' => 'purple',
        'href' => 'posts.php',
    ],
    [
        'label' => 'Total Engagements',
        'value' => (int)$engBundle['value'],
        'delta' => (int)$engBundle['delta_pct'],
        'icon' => 'fa-thumbs-up',
        'cls' => 'orange',
        'href' => 'posts.php',
    ],
    [
        'label' => (string)$kindProfile['new_label'],
        'value' => (int)$engBundle['new_users'],
        'delta' => (int)$engBundle['new_users_delta'],
        'icon' => 'fa-user-plus',
        'cls' => 'red',
        'href' => (string)$kindProfile['list_href'],
    ],
];

$quickActions = $kindProfile['quick'];

$kindTabs = [
    ['key' => 'personal', 'label' => 'Personal', 'icon' => 'fa-user', 'count' => (int)$kindCounts['personal']],
    ['key' => 'publisher', 'label' => 'Publisher', 'icon' => 'fa-bullhorn', 'count' => (int)$kindCounts['publisher']],
    ['key' => 'commerce', 'label' => 'Commerce', 'icon' => 'fa-shopping-bag', 'count' => (int)$kindCounts['commerce']],
];

org_admin_render_head('Dashboard');
admin_chrome_open(null, [
    'title' => 'Dashboard',
    'description' => 'Welcome back, ' . $welcomeName . '! Use the left nav workspaces: Public_user, Publisher, and Commerce.',
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
    padding:6px 10px 6px !important;margin:0 !important;flex:1 1 auto !important;background:#f4f6fb !important;
  }
  .ov-wrap{
    flex:1 1 auto;min-height:0;height:100%;
    display:flex;flex-direction:column;gap:8px;overflow:hidden;box-sizing:border-box;
  }
  .ov-metrics{
    flex:0 0 auto;display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:8px;
  }
  a.ov-metric{
    display:flex;align-items:center;gap:10px;background:#fff;border:1px solid #eef2f7;border-radius:10px;
    padding:10px 12px;box-shadow:0 1px 2px rgba(15,23,42,.04);text-decoration:none;color:inherit;min-width:0;
  }
  a.ov-metric:hover{border-color:#bfdbfe;text-decoration:none;}
  .ov-metric .ico{
    width:36px;height:36px;border-radius:999px;display:flex;align-items:center;justify-content:center;
    font-size:14px;flex:0 0 36px;
  }
  .ov-metric .ico.blue{background:#eff6ff;color:#2563eb;}
  .ov-metric .ico.green{background:#dcfce7;color:#16a34a;}
  .ov-metric .ico.purple{background:#f3e8ff;color:#7c3aed;}
  .ov-metric .ico.orange{background:#ffedd5;color:#ea580c;}
  .ov-metric .ico.red{background:#fee2e2;color:#dc2626;}
  .ov-metric .body{min-width:0;flex:1 1 auto;}
  .ov-metric .k{font-size:11px;font-weight:700;color:#64748b;}
  .ov-metric .v{margin-top:2px;font-size:20px;font-weight:800;color:#0f172a;letter-spacing:-.02em;line-height:1.1;}
  .ov-metric .d{margin-top:2px;font-size:10px;font-weight:700;}
  .ov-metric .d.up{color:#16a34a;}
  .ov-metric .d.down{color:#dc2626;}
  .ov-metric .d.flat{color:#94a3b8;}
  .ov-mid{
    flex:1.05 1 0;min-height:0;
    display:grid;grid-template-columns:minmax(0,1.4fr) minmax(0,.85fr) minmax(200px,.55fr);gap:8px;align-items:stretch;
  }
  .ov-low{
    flex:.9 1 0;min-height:0;
    display:grid;grid-template-columns:minmax(0,1.15fr) minmax(0,.9fr) minmax(0,.9fr) minmax(200px,.7fr);gap:8px;align-items:stretch;
  }
  .ov-card{
    background:#fff;border:1px solid #eef2f7;border-radius:10px;box-shadow:0 1px 2px rgba(15,23,42,.04);
    padding:8px 10px;min-width:0;min-height:0;display:flex;flex-direction:column;overflow:hidden;
  }
  .ov-card-hd{flex:0 0 auto;display:flex;align-items:center;justify-content:space-between;gap:6px;margin-bottom:6px;}
  .ov-card-hd h2{margin:0;font-size:12px;font-weight:800;color:#0f172a;display:flex;align-items:center;gap:5px;}
  .ov-card-hd h2 .info{color:#94a3b8;font-size:11px;}
  .ov-card-hd a.more{font-size:10px;font-weight:700;color:#2563eb;text-decoration:none;white-space:nowrap;}
  .ov-card-hd a.more:hover{text-decoration:underline;}
  .ov-select{
    height:24px;border:1px solid #e2e8f0;border-radius:6px;padding:0 6px;font-size:10px;font-weight:700;
    color:#475569;background:#fff;
  }
  .ov-chart-wrap{position:relative;flex:1 1 auto;min-height:0;}
  .ov-chart-wrap canvas{width:100% !important;height:100% !important;}
  .ov-legend{flex:0 0 auto;display:flex;flex-wrap:wrap;gap:10px;margin-top:2px;font-size:10px;font-weight:700;color:#64748b;}
  .ov-legend span{display:inline-flex;align-items:center;gap:5px;}
  .ov-legend i{width:14px;height:2px;border-radius:2px;display:inline-block;}
  .ov-donut-wrap{
    flex:1 1 auto;min-height:0;display:flex;flex-direction:row;align-items:center;gap:12px;
  }
  .ov-donut{
    width:96px;height:96px;border-radius:50%;position:relative;flex:0 0 96px;
    display:flex;align-items:center;justify-content:center;
  }
  .ov-donut:after{content:"";position:absolute;inset:22px;border-radius:50%;background:#fff;}
  .ov-donut .center{position:relative;z-index:1;text-align:center;line-height:1.1;}
  .ov-donut .center .n{font-size:13px;font-weight:800;color:#0f172a;}
  .ov-donut .center .l{font-size:9px;font-weight:700;color:#94a3b8;margin-top:1px;}
  .ov-clegend{list-style:none;margin:0;padding:0;flex:1 1 auto;min-width:0;}
  .ov-clegend li{
    display:flex;align-items:center;gap:6px;padding:2px 0;font-size:10px;font-weight:700;color:#334155;
  }
  .ov-clegend .dot{width:7px;height:7px;border-radius:999px;flex:0 0 7px;}
  .ov-clegend .lab{flex:1 1 auto;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
  .ov-clegend .pct{margin-left:auto;color:#64748b;font-weight:800;white-space:nowrap;}
  .ov-side-stack{display:flex;flex-direction:column;gap:8px;min-width:0;min-height:0;overflow:hidden;}
  .ov-side-stack > .ov-card{flex:1 1 0;min-height:0;}
  .ov-sys{display:flex;flex-direction:column;align-items:flex-start;gap:4px;padding:2px 0;}
  .ov-sys .badge-ok,.ov-sys .badge-bad{
    display:inline-flex;align-items:center;gap:6px;font-size:11px;font-weight:800;color:#166534;
  }
  .ov-sys .badge-ok .chk,.ov-sys .badge-bad .chk{
    width:18px;height:18px;border-radius:999px;background:#dcfce7;color:#16a34a;
    display:inline-flex;align-items:center;justify-content:center;font-size:9px;flex:0 0 18px;
  }
  .ov-sys .badge-bad{color:#b91c1c;}
  .ov-sys .badge-bad .chk{background:#fee2e2;color:#dc2626;}
  .ov-sys p{margin:0;font-size:10px;color:#64748b;font-weight:600;line-height:1.35;}
  .ov-sys a{font-size:11px;font-weight:700;color:#2563eb;text-decoration:none;}
  .ov-sys a:hover{text-decoration:underline;}
  .ov-qa{list-style:none;margin:0;padding:0;flex:1 1 auto;min-height:0;display:flex;flex-direction:column;}
  .ov-qa a{
    display:flex;align-items:center;gap:8px;padding:6px 0;border-bottom:1px solid #f1f5f9;
    text-decoration:none;color:#0f172a;font-size:11px;font-weight:700;flex:1 1 auto;
  }
  .ov-qa a:last-child{border-bottom:0;}
  .ov-qa a:hover{color:#2563eb;text-decoration:none;}
  .ov-qa .ico{
    width:24px;height:24px;border-radius:7px;background:#eff6ff;color:#2563eb;
    display:flex;align-items:center;justify-content:center;flex:0 0 24px;font-size:11px;
  }
  .ov-qa .ch{margin-left:auto;color:#94a3b8;font-size:10px;}
  .ov-list{list-style:none;margin:0;padding:0;flex:1 1 auto;min-height:0;overflow:hidden;}
  .ov-list li{
    display:flex;align-items:center;gap:8px;padding:5px 0;border-bottom:1px solid #f1f5f9;min-height:0;
  }
  .ov-list li:last-child{border-bottom:0;}
  .ov-list .ico{
    width:24px;height:24px;border-radius:7px;background:#f8fafc;color:#64748b;
    display:flex;align-items:center;justify-content:center;flex:0 0 24px;font-size:11px;
  }
  .ov-list .meta{flex:1 1 auto;min-width:0;}
  .ov-list .t{font-size:11px;font-weight:800;color:#0f172a;line-height:1.2;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
  .ov-list .s{font-size:10px;font-weight:600;color:#94a3b8;margin-top:1px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
  .ov-pill{
    display:inline-flex;align-items:center;height:18px;padding:0 7px;border-radius:999px;
    font-size:9px;font-weight:800;flex:0 0 auto;white-space:nowrap;
  }
  .ov-pill.new{background:#fee2e2;color:#b91c1c;}
  .ov-pill.review{background:#ffedd5;color:#c2410c;}
  .ov-pill.pending{background:#fef3c7;color:#b45309;}
  .ov-pill.resolved{background:#dcfce7;color:#166534;}
  .ov-country .bar-wrap{flex:1 1 auto;min-width:0;}
  .ov-country .bar-hd{display:flex;justify-content:space-between;gap:6px;font-size:11px;font-weight:700;color:#0f172a;}
  .ov-country .bar-hd .pct{color:#64748b;font-weight:800;}
  .ov-country .bar{margin-top:4px;height:5px;border-radius:999px;background:#f1f5f9;overflow:hidden;}
  .ov-country .bar > span{display:block;height:100%;background:#2563eb;border-radius:999px;}
  .ov-user .av{
    width:24px;height:24px;border-radius:999px;flex:0 0 24px;display:flex;align-items:center;justify-content:center;
    color:#fff;font-size:9px;font-weight:800;
  }
  .ov-user .score{margin-left:auto;font-size:11px;font-weight:800;color:#0f172a;}
  .ov-empty{padding:10px 4px;font-size:11px;font-weight:600;color:#94a3b8;text-align:center;line-height:1.35;}
  .ov-task .when{font-size:10px;font-weight:700;color:#64748b;white-space:nowrap;}
  .ov-help{
    background:linear-gradient(180deg,#eff6ff 0%,#dbeafe 100%);border:1px solid #bfdbfe;flex:0 0 auto !important;
  }
  .ov-help .help-ico{
    width:28px;height:28px;border-radius:8px;background:#fff;color:#2563eb;
    display:flex;align-items:center;justify-content:center;font-size:13px;margin-bottom:4px;
  }
  .ov-help h2{margin:0 0 2px;font-size:12px;font-weight:800;color:#0f172a;}
  .ov-help p{margin:0 0 6px;font-size:10px;color:#475569;font-weight:600;line-height:1.35;}
  .ov-help a{font-size:11px;font-weight:700;color:#2563eb;text-decoration:none;}
  .ov-help a:hover{text-decoration:underline;}
  .ov-foot{
    flex:0 0 auto;display:flex;align-items:center;gap:6px;font-size:10px;font-weight:700;color:#94a3b8;
  }
  .ov-foot a{color:#64748b;text-decoration:none;}
  .ov-foot a:hover{color:#2563eb;}
  .ov-card > a.more{flex:0 0 auto;margin-top:auto;padding-top:4px;font-size:10px;font-weight:700;color:#2563eb;text-decoration:none;}
  .ov-card > a.more:hover{text-decoration:underline;}
  .dash-tabs{
    flex:0 0 auto;display:flex;align-items:center;gap:6px;flex-wrap:wrap;
  }
  .dash-tabs a{
    display:inline-flex;align-items:center;gap:6px;height:28px;padding:0 12px;border-radius:999px;
    font-size:11px;font-weight:800;color:#64748b;background:#fff;border:1px solid #e2e8f0;text-decoration:none;
  }
  .dash-tabs a:hover{border-color:#93c5fd;color:#1e40af;text-decoration:none;}
  .dash-tabs a.is-active{background:#2563eb;border-color:#2563eb;color:#fff;}
  .dash-tabs a .cnt{
    display:inline-flex;align-items:center;justify-content:center;min-width:18px;height:16px;padding:0 5px;
    border-radius:999px;font-size:9px;font-weight:800;background:#f1f5f9;color:#475569;
  }
  .dash-tabs a.is-active .cnt{background:rgba(255,255,255,.22);color:#fff;}
  .dash-kind-note{
    flex:0 0 auto;display:flex;align-items:flex-start;gap:10px;padding:8px 10px;
    background:#fff;border:1px solid #eef2f7;border-radius:10px;box-shadow:0 1px 2px rgba(15,23,42,.04);
  }
  .dash-kind-note .ico{
    width:28px;height:28px;border-radius:8px;background:#eff6ff;color:#2563eb;
    display:flex;align-items:center;justify-content:center;flex:0 0 28px;font-size:12px;
  }
  .dash-kind-note .ico.pub{background:#f3e8ff;color:#7c3aed;}
  .dash-kind-note .ico.com{background:#ffedd5;color:#ea580c;}
  .dash-kind-note .txt{min-width:0;flex:1 1 auto;}
  .dash-kind-note .ttl{font-size:12px;font-weight:800;color:#0f172a;line-height:1.2;}
  .dash-kind-note .sub{margin-top:2px;font-size:10px;font-weight:600;color:#64748b;line-height:1.35;}
  .ov-focus{list-style:none;margin:0;padding:0;flex:1 1 auto;min-height:0;overflow:hidden;}
  .ov-focus li{
    display:flex;align-items:flex-start;gap:7px;padding:4px 0;border-bottom:1px solid #f1f5f9;
    font-size:10px;font-weight:700;color:#334155;line-height:1.35;
  }
  .ov-focus li:last-child{border-bottom:0;}
  .ov-focus .dot{
    width:6px;height:6px;border-radius:999px;background:#2563eb;margin-top:4px;flex:0 0 6px;
  }
</style>

<div class="sh-mainpanel">
  <div class="sh-pagebody">
    <div class="ov-wrap">
      <div class="dash-tabs" role="tablist" aria-label="Audience type">
        <?php foreach ($kindTabs as $tab): ?>
          <?php
          $isActive = $dashKind === $tab['key'];
          $href = $dashUrl(['kind' => $tab['key']]);
          ?>
          <a class="<?= $isActive ? 'is-active' : '' ?>" href="<?= admin_overview_h($href) ?>" role="tab" aria-selected="<?= $isActive ? 'true' : 'false' ?>">
            <i class="fa <?= admin_overview_h((string)$tab['icon']) ?>" aria-hidden="true"></i>
            <?= admin_overview_h((string)$tab['label']) ?>
            <span class="cnt"><?= number_format((int)$tab['count']) ?></span>
          </a>
        <?php endforeach; ?>
      </div>

      <div class="dash-kind-note">
        <span class="ico<?= $dashKind === 'publisher' ? ' pub' : ($dashKind === 'commerce' ? ' com' : '') ?>" aria-hidden="true">
          <i class="fa <?= admin_overview_h($userMetricIcon) ?>"></i>
        </span>
        <div class="txt">
          <div class="ttl"><?= admin_overview_h((string)$kindProfile['label']) ?> · <?= admin_overview_h((string)$kindProfile['short']) ?></div>
          <div class="sub"><?= admin_overview_h((string)$kindProfile['blurb']) ?></div>
        </div>
      </div>

      <div class="ov-metrics" aria-label="Key metrics">
        <?php foreach ($metricCards as $m): ?>
          <?php
          $delta = (int)$m['delta'];
          $dir = $delta > 0 ? 'up' : ($delta < 0 ? 'down' : 'flat');
          $arrow = $delta > 0 ? '↑' : ($delta < 0 ? '↓' : '•');
          ?>
          <a class="ov-metric" href="<?= admin_overview_h((string)$m['href']) ?>">
            <span class="ico <?= admin_overview_h((string)$m['cls']) ?>" aria-hidden="true">
              <i class="fa <?= admin_overview_h((string)$m['icon']) ?>"></i>
            </span>
            <div class="body">
              <div class="k"><?= admin_overview_h((string)$m['label']) ?></div>
              <div class="v"><?= number_format((int)$m['value']) ?></div>
              <div class="d <?= $dir ?>"><?= $arrow ?> <?= abs($delta) ?>% vs last 7 days</div>
            </div>
          </a>
        <?php endforeach; ?>
      </div>

      <div class="ov-mid">
        <div class="ov-card">
          <div class="ov-card-hd">
            <h2>Activity Overview <i class="fa fa-info-circle info" title="Scoped to this audience type"></i></h2>
            <form method="get" action="dashboard.php">
              <input type="hidden" name="kind" value="<?= admin_overview_h($dashKind) ?>">
              <select class="ov-select" name="range" onchange="this.form.submit()" aria-label="Activity range">
                <option value="7"<?= $rangeDays === 7 ? ' selected' : '' ?>>Last 7 Days</option>
                <option value="14"<?= $rangeDays === 14 ? ' selected' : '' ?>>Last 14 Days</option>
                <option value="30"<?= $rangeDays === 30 ? ' selected' : '' ?>>Last 30 Days</option>
              </select>
            </form>
          </div>
          <div class="ov-chart-wrap">
            <canvas id="dashActivityChart" aria-label="Activity overview chart"></canvas>
          </div>
          <div class="ov-legend">
            <span><i style="background:#2563eb;"></i> <?= admin_overview_h((string)$kindProfile['chart_user_label']) ?></span>
            <span><i style="background:#22c55e;"></i> Posts</span>
            <span><i style="background:#a855f7;"></i> Comments</span>
            <span><i style="background:#f97316;"></i> Engagements</span>
          </div>
        </div>

        <div class="ov-card">
          <div class="ov-card-hd">
            <h2>Content Status <i class="fa fa-info-circle info" title="Post status mix"></i></h2>
            <span class="ov-select" style="display:inline-flex;align-items:center;pointer-events:none;">Last 7 Days</span>
          </div>
          <div class="ov-donut-wrap">
            <div class="ov-donut" style="background:<?= admin_overview_h($donutBg) ?>;" aria-hidden="true">
              <div class="center">
                <div class="n"><?= number_format($contentTotal) ?></div>
                <div class="l">Total</div>
              </div>
            </div>
            <ul class="ov-clegend">
              <?php foreach ($contentStatus as $cs): ?>
                <li>
                  <span class="dot" style="background:<?= admin_overview_h((string)$cs['color']) ?>;"></span>
                  <span class="lab"><?= admin_overview_h((string)$cs['label']) ?></span>
                  <span class="pct"><?= number_format((int)$cs['count']) ?> (<?= admin_overview_h((string)$cs['pct']) ?>%)</span>
                </li>
              <?php endforeach; ?>
            </ul>
          </div>
        </div>

        <div class="ov-side-stack">
          <div class="ov-card">
            <div class="ov-card-hd"><h2>Admin Focus</h2></div>
            <ul class="ov-focus">
              <?php foreach ($kindProfile['focus'] as $focusItem): ?>
                <li><span class="dot" aria-hidden="true"></span><span><?= admin_overview_h((string)$focusItem) ?></span></li>
              <?php endforeach; ?>
            </ul>
            <div class="ov-sys" style="margin-top:6px;padding-top:6px;border-top:1px solid #f1f5f9;">
              <div class="badge-<?= $healthOk ? 'ok' : 'bad' ?>" style="font-size:10px;">
                <span class="chk"><i class="fa <?= $healthOk ? 'fa-check' : 'fa-exclamation' ?>"></i></span>
                <?= $healthOk ? 'Systems OK' : 'Needs attention' ?>
              </div>
              <a href="settings.php?section=system">System Health</a>
            </div>
          </div>
          <div class="ov-card">
            <div class="ov-card-hd"><h2>Quick Actions</h2></div>
            <div class="ov-qa">
              <?php foreach ($quickActions as $qa): ?>
                <a href="<?= admin_overview_h((string)$qa[1]) ?>">
                  <span class="ico" aria-hidden="true"><i class="fa <?= admin_overview_h((string)$qa[2]) ?>"></i></span>
                  <span><?= admin_overview_h((string)$qa[0]) ?></span>
                  <i class="fa fa-chevron-right ch"></i>
                </a>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
      </div>

      <div class="ov-low">
        <div class="ov-card">
          <div class="ov-card-hd">
            <h2>Recent Reports</h2>
            <a class="more" href="reports.php">View All</a>
          </div>
          <?php if ($recentReports === []): ?>
            <div class="ov-empty">No reports yet.</div>
          <?php else: ?>
            <ul class="ov-list">
              <?php foreach (array_slice($recentReports, 0, 5) as $rep): ?>
                <?php
                $rid = (int)($rep['id'] ?? 0);
                $reason = admin_overview_reason_label((string)($rep['reason'] ?? 'other'));
                $reporter = trim((string)($rep['reporter_username'] ?? ''));
                if ($reporter === '') {
                    $reporter = trim((string)($rep['reporter_name'] ?? ''));
                }
                if ($reporter === '') {
                    $reporter = 'user';
                }
                $tt = strtolower(trim((string)($rep['target_type'] ?? 'post')));
                $targetWord = $tt === 'user' ? 'User' : ($tt === 'org' ? 'Org' : 'Post');
                $badge = admin_overview_report_badge((string)($rep['status'] ?? 'pending'));
                $ago = admin_overview_relative_time((string)($rep['created_at'] ?? ''));
                ?>
                <li>
                  <span class="ico" aria-hidden="true"><i class="fa fa-flag"></i></span>
                  <div class="meta">
                    <div class="t"><?= admin_overview_h($reason) ?></div>
                    <div class="s"><?= admin_overview_h($targetWord) ?> reported by @<?= admin_overview_h($reporter) ?> · <?= admin_overview_h($ago) ?></div>
                  </div>
                  <a class="ov-pill <?= admin_overview_h($badge['cls']) ?>" href="report_detail.php?id=<?= $rid ?>">
                    <?= admin_overview_h($badge['label']) ?>
                  </a>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </div>

        <div class="ov-card">
          <div class="ov-card-hd">
            <h2>Top Countries <i class="fa fa-info-circle info" title="From publisher registration countries when available"></i></h2>
            <span class="ov-select" style="display:inline-flex;align-items:center;pointer-events:none;">Last 7 Days</span>
          </div>
          <?php if ($topCountries === []): ?>
            <div class="ov-empty">No country data yet.</div>
            <a class="more" href="publisher_requests.php">View All Countries</a>
          <?php else: ?>
            <ul class="ov-list ov-country">
              <?php foreach (array_slice($topCountries, 0, 5) as $c): ?>
                <li>
                  <span class="ico" style="background:transparent;font-size:14px;" aria-hidden="true"><?= admin_overview_h((string)$c['flag']) ?></span>
                  <div class="bar-wrap">
                    <div class="bar-hd">
                      <span><?= admin_overview_h((string)$c['label']) ?></span>
                      <span class="pct"><?= admin_overview_h((string)$c['pct']) ?>%</span>
                    </div>
                    <div class="bar" aria-hidden="true"><span style="width:<?= min(100, (float)$c['pct']) ?>%;"></span></div>
                  </div>
                </li>
              <?php endforeach; ?>
            </ul>
            <a class="more" href="publisher_requests.php">View All Countries</a>
          <?php endif; ?>
        </div>

        <div class="ov-card">
          <div class="ov-card-hd">
            <h2>Top Active Users <i class="fa fa-info-circle info" title="Most posts in the last 30 days"></i></h2>
            <span class="ov-select" style="display:inline-flex;align-items:center;pointer-events:none;">Last 7 Days</span>
          </div>
          <?php if ($topUsers === []): ?>
            <div class="ov-empty">No recent <?= admin_overview_h(strtolower((string)$kindProfile['label'])) ?> post activity.</div>
            <a class="more" href="<?= admin_overview_h((string)$kindProfile['list_href']) ?>">View All</a>
          <?php else: ?>
            <ul class="ov-list ov-user">
              <?php foreach (array_slice($topUsers, 0, 5) as $u): ?>
                <?php
                $uname = trim((string)$u['username']);
                $dname = trim((string)$u['name']);
                $label = $uname !== '' ? $uname : ($dname !== '' ? $dname : ('User #' . (int)$u['id']));
                $avKey = $uname !== '' ? $uname : $label;
                ?>
                <li>
                  <span class="av" style="background:<?= admin_overview_h(posts_admin_avatar_color($avKey)) ?>;">
                    <?= admin_overview_h(posts_admin_initials($dname !== '' ? $dname : $label)) ?>
                  </span>
                  <div class="meta">
                    <a class="t" href="user_form.php?id=<?= (int)$u['id'] ?>" style="text-decoration:none;color:#0f172a;">
                      @<?= admin_overview_h($label) ?>
                    </a>
                  </div>
                  <span class="score"><?= number_format((int)$u['score']) ?></span>
                </li>
              <?php endforeach; ?>
            </ul>
            <a class="more" href="<?= admin_overview_h((string)$kindProfile['list_href']) ?>">View All</a>
          <?php endif; ?>
        </div>

        <div class="ov-side-stack">
          <div class="ov-card">
            <div class="ov-card-hd">
              <h2>Next Scheduled Tasks</h2>
              <a class="more" href="settings.php?section=system">View All</a>
            </div>
            <ul class="ov-list ov-task">
              <?php foreach ($tasks as $t): ?>
                <li>
                  <span class="ico" style="background:#eff6ff;color:#2563eb;" aria-hidden="true"><i class="fa fa-clock-o"></i></span>
                  <div class="meta">
                    <div class="t"><?= admin_overview_h((string)$t['label']) ?></div>
                    <div class="when"><?= admin_overview_h((string)$t['when']) ?></div>
                  </div>
                </li>
              <?php endforeach; ?>
            </ul>
          </div>
          <div class="ov-card ov-help">
            <div class="help-ico" aria-hidden="true"><i class="fa fa-question"></i></div>
            <h2>Need Help?</h2>
            <p>Check our documentation or contact support.</p>
            <a href="feedback.php?view=internal&amp;filter=unread"><i class="fa fa-external-link"></i> Visit Help Center</a>
          </div>
        </div>
      </div>

      <div class="ov-foot">
        <span>Last updated: <?= admin_overview_h($lastUpdated) ?></span>
        <a href="<?= admin_overview_h($dashUrl()) ?>" title="Refresh"><i class="fa fa-refresh"></i></a>
      </div>
    </div>
  </div>
</div>

<script src="../lib/chart.js/Chart.js"></script>
<script>
(function () {
  var el = document.getElementById('dashActivityChart');
  if (!el || !window.Chart) return;
  var labels = <?= json_encode($series['labels'], JSON_UNESCAPED_UNICODE) ?>;
  var mk = function (label, data, color) {
    return {
      label: label,
      data: data,
      borderColor: color,
      backgroundColor: 'transparent',
      borderWidth: 2,
      pointRadius: 2,
      pointBackgroundColor: color,
      fill: false,
      lineTension: 0.35
    };
  };
  new Chart(el.getContext('2d'), {
    type: 'line',
    data: {
      labels: labels,
      datasets: [
        mk(<?= json_encode((string)$kindProfile['chart_user_label'], JSON_UNESCAPED_UNICODE) ?>, <?= json_encode($series['users'], JSON_UNESCAPED_UNICODE) ?>, '#2563eb'),
        mk('Posts', <?= json_encode($series['posts'], JSON_UNESCAPED_UNICODE) ?>, '#22c55e'),
        mk('Comments', <?= json_encode($series['comments'], JSON_UNESCAPED_UNICODE) ?>, '#a855f7'),
        mk('Engagements', <?= json_encode($series['engagements'], JSON_UNESCAPED_UNICODE) ?>, '#f97316')
      ]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      legend: { display: false },
      tooltips: { mode: 'index', intersect: false },
      scales: {
        xAxes: [{
          gridLines: { display: false },
          ticks: { fontColor: '#94a3b8', fontSize: 9 }
        }],
        yAxes: [{
          gridLines: { color: 'rgba(15,23,42,.06)', zeroLineColor: 'rgba(15,23,42,.06)' },
          ticks: { beginAtZero: true, fontColor: '#94a3b8', fontSize: 9, precision: 0 }
        }]
      }
    }
  });
})();
</script>

<?php org_admin_render_foot(); ?>
