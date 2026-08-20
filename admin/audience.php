<?php
declare(strict_types=1);

/**
 * Admin — Audience dashboard (screenshot layout; viewport-fit, no page scroll).
 */
require_once __DIR__ . '/includes/org_admin_helpers_load.php';
require_once __DIR__ . '/includes/admin_overview_helpers.php';
require_once __DIR__ . '/includes/admin_audience_helpers.php';
require_once __DIR__ . '/includes/admin_kind_tabs.php';
org_admin_require_admin();

error_reporting(E_ALL);
ini_set('display_errors', '1');

$dbh = org_admin_db();

$kindFilter = admin_kind_from_request();
$kindCounts = admin_kind_user_counts($dbh);

$dateTo = trim((string)($_GET['to'] ?? ''));
$dateFrom = trim((string)($_GET['from'] ?? ''));
if ($dateTo === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
    $dateTo = date('Y-m-d');
}
if ($dateFrom === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
    $dateFrom = date('Y-m-d', strtotime($dateTo . ' -6 days') ?: strtotime('-6 days'));
}

$userFilter = strtolower(trim((string)($_GET['user'] ?? 'all')));
if (!in_array($userFilter, ['all', 'active', 'blocked'], true)) {
    $userFilter = 'all';
}
$locationFilter = strtolower(trim((string)($_GET['location'] ?? 'all')));
if (!in_array($locationFilter, ['all', 'known', 'unknown'], true)) {
    $locationFilter = 'all';
}
$deviceFilter = strtolower(trim((string)($_GET['device'] ?? 'all')));
if (!in_array($deviceFilter, ['all', 'mobile', 'desktop', 'tablet'], true)) {
    $deviceFilter = 'all';
}

$metrics = admin_audience_metrics($dbh, $kindFilter);
$growth = admin_audience_growth_series($dbh, $dateFrom, $dateTo, $kindFilter);
$ageRows = admin_audience_age_breakdown($dbh, $kindFilter);
$genderRows = admin_audience_gender_breakdown($dbh, $kindFilter);
$ageBg = admin_audience_donut_bg($ageRows);
$genderBg = admin_audience_donut_bg($genderRows);
$totalUsers = (int)$metrics['total']['value'];
$locations = admin_audience_top_locations($dbh, 5);
$devices = admin_audience_top_devices($dbh, 4);
if ($deviceFilter !== 'all') {
    $devices = array_values(array_filter(
        $devices,
        static fn(array $d): bool => strtolower((string)$d['label']) === $deviceFilter
    ));
}
$overviewRows = admin_audience_overview_rows($dbh, $kindFilter);
$segments = admin_audience_segments($dbh, $kindFilter);
$maxLoc = 0;
foreach ($locations as $loc) {
    $maxLoc = max($maxLoc, (int)$loc['count']);
}
$maxDev = 0;
foreach ($devices as $dev) {
    $maxDev = max($maxDev, (int)$dev['count']);
}

$dateLabel = date('M j, Y', strtotime($dateFrom) ?: time()) . ' - ' . date('M j, Y', strtotime($dateTo) ?: time());

$qs = static function (array $extra = []) use ($dateFrom, $dateTo, $userFilter, $locationFilter, $deviceFilter, $kindFilter): string {
    $base = [
        'kind' => $kindFilter,
        'from' => $dateFrom,
        'to' => $dateTo,
        'user' => $userFilter,
        'location' => $locationFilter,
        'device' => $deviceFilter,
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
        if (in_array($k, ['user', 'location', 'device'], true) && $v === 'all' && !array_key_exists($k, $extra)) {
            unset($base[$k]);
        }
    }
    return 'audience.php' . ($base ? ('?' . http_build_query($base)) : '');
};

$metricCards = [
    ['label' => 'Total Users', 'icon' => 'fa-users', 'cls' => 'blue', 'm' => $metrics['total']],
    ['label' => 'New Users', 'icon' => 'fa-user-plus', 'cls' => 'green', 'm' => $metrics['new']],
    ['label' => 'Active Users', 'icon' => 'fa-clock-o', 'cls' => 'purple', 'm' => $metrics['active']],
    ['label' => 'Returning Users', 'icon' => 'fa-bar-chart', 'cls' => 'orange', 'm' => $metrics['returning']],
    ['label' => 'Engaged Users', 'icon' => 'fa-heart', 'cls' => 'red', 'm' => $metrics['engaged']],
];

org_admin_render_head('Audience');
require_once __DIR__ . '/includes/admin_chrome.php';
admin_chrome_open(null, [
    'title' => 'Audience',
    'crumb' => [
        ['Overview', 'overview.php'],
        ['Audience', null],
    ],
    'description' => 'Understand Personal, Publisher, and Commerce audiences and how they interact.',
]);
?>

<style>
  html,body{height:100% !important;overflow:hidden !important;max-height:100dvh !important;}
  body.azia-admin{overflow:hidden !important;}
  .sh-mainpanel{
    height:100vh !important;max-height:70dvh !important;
    display:flex !important;flex-direction:column !important;overflow:hidden !important;
  }
  .sh-mainpanel > .sh-pagebody{
    overflow:hidden !important;display:flex !important;flex-direction:column !important;min-height:0 !important;
    padding:6px 10px 14px !important;margin:0 !important;flex:1 1 auto !important;background:#f4f6fb !important;
  }
  .aud-wrap{
    flex:1 1 auto;min-height:0;height:100%;
    display:flex;flex-direction:column;gap:6px;overflow:hidden;box-sizing:border-box;
    padding-bottom:8px;
  }
  .aud-metrics{flex:0 0 auto;display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:6px;}
  .aud-metric{
    background:#fff;border:1px solid #eef2f7;border-radius:10px;padding:8px 10px;
    box-shadow:0 1px 2px rgba(15,23,42,.04);display:flex;align-items:flex-start;gap:8px;min-width:0;
  }
  .aud-metric .ico{
    width:28px;height:28px;border-radius:8px;display:flex;align-items:center;justify-content:center;
    font-size:12px;flex:0 0 28px;
  }
  .aud-metric .ico.blue{background:#eff6ff;color:#2563eb;}
  .aud-metric .ico.green{background:#dcfce7;color:#16a34a;}
  .aud-metric .ico.purple{background:#f3e8ff;color:#7c3aed;}
  .aud-metric .ico.orange{background:#ffedd5;color:#ea580c;}
  .aud-metric .ico.red{background:#fee2e2;color:#dc2626;}
  .aud-metric .k{font-size:10px;font-weight:700;color:#64748b;}
  .aud-metric .v{margin-top:2px;font-size:17px;font-weight:800;color:#0f172a;letter-spacing:-.02em;line-height:1.1;}
  .aud-metric .d{margin-top:2px;font-size:10px;font-weight:700;}
  .aud-metric .d.up{color:#16a34a;}
  .aud-metric .d.down{color:#dc2626;}
  .aud-metric .d.flat{color:#94a3b8;}
  .aud-filters{
    flex:0 0 auto;display:flex;flex-wrap:wrap;gap:6px;align-items:center;
    padding:6px 8px;background:#fff;border:1px solid #eef2f7;border-radius:10px;
  }
  .aud-filters label{display:none;}
  .aud-filters input[type=date],.aud-filters select{
    height:28px;border:1px solid #e2e8f0;border-radius:8px;padding:0 8px;font-size:11px;
    font-weight:700;color:#334155;background:#fff;
  }
  .aud-filters .aud-dates{display:flex;align-items:center;gap:5px;}
  .aud-filters .sep{color:#94a3b8;font-size:11px;font-weight:700;}
  .aud-btn{
    height:28px;padding:0 10px;border-radius:8px;border:1px solid #e2e8f0;background:#fff;
    font-size:11px;font-weight:700;color:#334155;display:inline-flex;align-items:center;gap:5px;
    text-decoration:none;margin-left:auto;cursor:pointer;
  }
  .aud-btn:hover{background:#f8fafc;text-decoration:none;color:#0f172a;}
  .aud-mid{
    flex:1.05 1 0;min-height:0;
    display:grid;grid-template-columns:minmax(0,1.35fr) minmax(0,.85fr) minmax(0,.85fr);gap:6px;
  }
  .aud-low{
    flex:0.92 1 0;min-height:0;
    display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr) minmax(0,1.1fr);gap:6px;
  }
  .aud-seg-wrap{flex:0 0 auto;min-height:0;margin-bottom:2px;}
  .aud-card{
    background:#fff;border:1px solid #eef2f7;border-radius:10px;box-shadow:0 1px 2px rgba(15,23,42,.04);
    padding:8px 10px;min-width:0;min-height:0;display:flex;flex-direction:column;overflow:hidden;
  }
  .aud-card-hd{flex:0 0 auto;display:flex;align-items:center;justify-content:space-between;gap:6px;margin-bottom:4px;}
  .aud-card-hd h2{margin:0;font-size:12px;font-weight:800;color:#0f172a;display:flex;align-items:center;gap:5px;}
  .aud-card-hd h2 .info{color:#94a3b8;font-size:11px;}
  .aud-card-hd a.more{font-size:10px;font-weight:700;color:#2563eb;text-decoration:none;white-space:nowrap;}
  .aud-card-hd a.more:hover{text-decoration:underline;}
  .aud-legend{flex:0 0 auto;display:flex;gap:12px;margin-bottom:2px;font-size:10px;font-weight:700;color:#64748b;}
  .aud-legend span{display:inline-flex;align-items:center;gap:5px;}
  .aud-legend i{width:14px;height:2px;border-radius:2px;background:#2563eb;display:inline-block;}
  .aud-legend i.dash{background:repeating-linear-gradient(90deg,#93c5fd 0 3px,transparent 3px 6px);}
  .aud-chart-wrap{position:relative;flex:1 1 auto;min-height:0;}
  .aud-chart-wrap canvas{width:100% !important;height:100% !important;}
  .aud-donut-wrap{flex:1 1 auto;min-height:0;display:flex;flex-direction:row;align-items:center;gap:10px;}
  .aud-donut{
    width:88px;height:88px;border-radius:50%;position:relative;flex:0 0 88px;
    display:flex;align-items:center;justify-content:center;
  }
  .aud-donut:after{content:"";position:absolute;inset:20px;border-radius:50%;background:#fff;}
  .aud-donut .center{position:relative;z-index:1;text-align:center;line-height:1.1;}
  .aud-donut .center .n{font-size:12px;font-weight:800;color:#0f172a;}
  .aud-donut .center .l{font-size:8px;font-weight:700;color:#94a3b8;margin-top:1px;}
  .aud-clegend{list-style:none;margin:0;padding:0;flex:1 1 auto;min-width:0;}
  .aud-clegend li{
    display:flex;align-items:center;gap:6px;padding:1px 0;font-size:10px;font-weight:700;color:#334155;
  }
  .aud-clegend .dot{width:7px;height:7px;border-radius:999px;flex:0 0 7px;}
  .aud-clegend .lab{flex:1 1 auto;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
  .aud-clegend .pct{margin-left:auto;color:#64748b;font-weight:800;white-space:nowrap;}
  .aud-barlist{list-style:none;margin:0;padding:0;flex:1 1 auto;min-height:0;overflow:hidden;display:flex;flex-direction:column;gap:5px;}
  .aud-barlist li{display:grid;grid-template-columns:18px minmax(0,1fr) auto;gap:6px;align-items:center;}
  .aud-barlist .flag,.aud-barlist .dico{
    width:18px;text-align:center;font-size:13px;color:#64748b;line-height:1;
  }
  .aud-barlist .meta{min-width:0;}
  .aud-barlist .name{font-size:11px;font-weight:700;color:#0f172a;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
  .aud-barlist .track{height:5px;border-radius:999px;background:#eef2f7;margin-top:3px;overflow:hidden;}
  .aud-barlist .fill{height:100%;border-radius:999px;background:#2563eb;}
  .aud-barlist .nums{text-align:right;font-size:10px;font-weight:800;color:#475569;white-space:nowrap;line-height:1.2;}
  .aud-barlist .nums .pct{display:block;color:#94a3b8;font-weight:700;}
  .aud-empty{font-size:11px;font-weight:600;color:#94a3b8;padding:8px 0;}
  .aud-table{width:100%;border-collapse:collapse;font-size:11px;}
  .aud-table th{
    text-align:left;font-size:10px;font-weight:800;color:#94a3b8;padding:2px 4px 4px;border-bottom:1px solid #eef2f7;
  }
  .aud-table td{padding:4px;border-bottom:1px solid #f1f5f9;font-weight:700;color:#334155;vertical-align:middle;}
  .aud-table tr:last-child td{border-bottom:0;}
  .aud-table .chg{font-weight:800;white-space:nowrap;}
  .aud-table .chg.up{color:#16a34a;}
  .aud-table .chg.down{color:#dc2626;}
  .aud-table .chg.flat{color:#94a3b8;}
  .aud-seg-hd{display:flex;align-items:center;gap:5px;margin:0 0 4px;font-size:12px;font-weight:800;color:#0f172a;}
  .aud-seg-hd .info{color:#94a3b8;font-size:11px;}
  .aud-segs{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:6px; margin-bottom:15px;}
  .aud-seg{
    background:#fff;border:1px solid #eef2f7;border-radius:10px;padding:8px 9px;
    box-shadow:0 1px 2px rgba(15,23,42,.04);min-width:0;
  }
  .aud-seg .row{display:flex;align-items:flex-start;gap:7px;}
  .aud-seg .ico{
    width:26px;height:26px;border-radius:8px;display:flex;align-items:center;justify-content:center;
    font-size:11px;flex:0 0 26px;
  }
  .aud-seg .ico.blue{background:#eff6ff;color:#2563eb;}
  .aud-seg .ico.green{background:#dcfce7;color:#16a34a;}
  .aud-seg .ico.purple{background:#f3e8ff;color:#7c3aed;}
  .aud-seg .ico.orange{background:#ffedd5;color:#ea580c;}
  .aud-seg .ico.red{background:#fee2e2;color:#dc2626;}
  .aud-seg .lab{font-size:11px;font-weight:800;color:#0f172a;line-height:1.2;}
  .aud-seg .val{margin-top:2px;font-size:15px;font-weight:800;color:#0f172a;letter-spacing:-.02em;}
  .aud-seg .delta{margin-top:1px;font-size:10px;font-weight:800;}
  .aud-seg .delta.up{color:#16a34a;}
  .aud-seg .delta.down{color:#dc2626;}
  .aud-seg .delta.flat{color:#94a3b8;}
  .aud-seg .desc{margin:5px 0 0;font-size:10px;font-weight:600;color:#94a3b8;line-height:1.3;}
  .aud-seg-foot{text-align:center;margin-top:4px;}
  .aud-seg-foot a{font-size:11px;font-weight:700;color:#2563eb;text-decoration:none;}
  .aud-seg-foot a:hover{text-decoration:underline;}
  @media (max-width:1100px){
    .aud-metrics,.aud-segs{grid-template-columns:repeat(3,minmax(0,1fr));}
    .aud-mid,.aud-low{grid-template-columns:1fr 1fr;}
  }
<?= admin_kind_tabs_css('ak') ?>
</style>

<div class="sh-mainpanel">
  <div class="sh-pagebody">
    <div class="aud-wrap">
      <?= admin_kind_tabs_html(
          $kindFilter,
          $kindCounts,
          fn($k) => $qs(['kind' => $k]),
          fn($s) => admin_audience_h((string)$s),
          'ak',
          'default'
      ) ?>
      <div class="aud-metrics" aria-label="Audience metrics">
        <?php foreach ($metricCards as $c): ?>
          <?php $m = $c['m']; ?>
          <div class="aud-metric">
            <span class="ico <?= admin_audience_h((string)$c['cls']) ?>" aria-hidden="true">
              <i class="fa <?= admin_audience_h((string)$c['icon']) ?>"></i>
            </span>
            <div>
              <div class="k"><?= admin_audience_h((string)$c['label']) ?></div>
              <div class="v"><?= number_format((int)$m['value']) ?></div>
              <div class="d <?= admin_audience_h((string)$m['sub_cls']) ?>"><?= admin_audience_h((string)$m['sub']) ?></div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>

      <form class="aud-filters" method="get" action="audience.php">
        <input type="hidden" name="kind" value="<?= admin_audience_h($kindFilter) ?>">
        <div class="aud-dates" title="<?= admin_audience_h($dateLabel) ?>">
          <i class="fa fa-calendar" style="color:#94a3b8;" aria-hidden="true"></i>
          <label for="aud_from">From</label>
          <input id="aud_from" type="date" name="from" value="<?= admin_audience_h($dateFrom) ?>" onchange="this.form.submit()">
          <span class="sep">–</span>
          <label for="aud_to">To</label>
          <input id="aud_to" type="date" name="to" value="<?= admin_audience_h($dateTo) ?>" onchange="this.form.submit()">
        </div>
        <select name="user" aria-label="Users" onchange="this.form.submit()">
          <option value="all"<?= $userFilter === 'all' ? ' selected' : '' ?>>All Users</option>
          <option value="active"<?= $userFilter === 'active' ? ' selected' : '' ?>>Active Users</option>
          <option value="blocked"<?= $userFilter === 'blocked' ? ' selected' : '' ?>>Blocked Users</option>
        </select>
        <select name="location" aria-label="Locations" onchange="this.form.submit()">
          <option value="all"<?= $locationFilter === 'all' ? ' selected' : '' ?>>All Locations</option>
          <option value="known"<?= $locationFilter === 'known' ? ' selected' : '' ?>>Known</option>
          <option value="unknown"<?= $locationFilter === 'unknown' ? ' selected' : '' ?>>Unknown</option>
        </select>
        <select name="device" aria-label="Devices" onchange="this.form.submit()">
          <option value="all"<?= $deviceFilter === 'all' ? ' selected' : '' ?>>All Devices</option>
          <option value="mobile"<?= $deviceFilter === 'mobile' ? ' selected' : '' ?>>Mobile</option>
          <option value="desktop"<?= $deviceFilter === 'desktop' ? ' selected' : '' ?>>Desktop</option>
          <option value="tablet"<?= $deviceFilter === 'tablet' ? ' selected' : '' ?>>Tablet</option>
        </select>
        <a class="aud-btn" href="<?= admin_audience_h($qs(['export' => '1'])) ?>" title="CSV export coming soon">
          <i class="fa fa-download"></i> Export
        </a>
      </form>

      <div class="aud-mid">
        <div class="aud-card">
          <div class="aud-card-hd">
            <h2>Audience Growth <i class="fa fa-info-circle info" title="Cumulative users and new signups in the selected range"></i></h2>
            <span style="font-size:10px;font-weight:700;color:#64748b;"><?= admin_audience_h($dateLabel) ?></span>
          </div>
          <div class="aud-legend">
            <span><i></i> Total Users</span>
            <span><i class="dash"></i> New Users</span>
          </div>
          <div class="aud-chart-wrap">
            <canvas id="audGrowthChart" aria-label="Audience growth chart"></canvas>
          </div>
        </div>

        <div class="aud-card">
          <div class="aud-card-hd">
            <h2>Users by Age <i class="fa fa-info-circle info" title="Derived from birthday / age fields"></i></h2>
          </div>
          <div class="aud-donut-wrap">
            <div class="aud-donut" style="background:<?= admin_audience_h($ageBg) ?>;">
              <div class="center">
                <div class="n"><?= number_format($totalUsers) ?></div>
                <div class="l">Total Users</div>
              </div>
            </div>
            <ul class="aud-clegend">
              <?php foreach ($ageRows as $r): ?>
                <li>
                  <span class="dot" style="background:<?= admin_audience_h((string)$r['color']) ?>;"></span>
                  <span class="lab"><?= admin_audience_h((string)$r['label']) ?></span>
                  <span class="pct"><?= number_format((float)$r['pct'], 1) ?>%</span>
                </li>
              <?php endforeach; ?>
            </ul>
          </div>
        </div>

        <div class="aud-card">
          <div class="aud-card-hd">
            <h2>Users by Gender <i class="fa fa-info-circle info" title="From user profile gender"></i></h2>
          </div>
          <div class="aud-donut-wrap">
            <div class="aud-donut" style="background:<?= admin_audience_h($genderBg) ?>;">
              <div class="center">
                <div class="n"><?= number_format($totalUsers) ?></div>
                <div class="l">Total Users</div>
              </div>
            </div>
            <ul class="aud-clegend">
              <?php foreach ($genderRows as $r): ?>
                <li>
                  <span class="dot" style="background:<?= admin_audience_h((string)$r['color']) ?>;"></span>
                  <span class="lab"><?= admin_audience_h((string)$r['label']) ?></span>
                  <span class="pct"><?= number_format((float)$r['pct'], 1) ?>%</span>
                </li>
              <?php endforeach; ?>
            </ul>
          </div>
        </div>
      </div>

      <div class="aud-low">
        <div class="aud-card">
          <div class="aud-card-hd">
            <h2>Top Locations</h2>
            <a class="more" href="device_activity.php">View All Locations</a>
          </div>
          <?php if ($locations === [] || ($locationFilter === 'known' && count($locations) === 1 && ($locations[0]['label'] ?? '') === 'Unknown location')): ?>
            <div class="aud-empty">No location data yet.</div>
          <?php elseif ($locationFilter === 'unknown'): ?>
            <ul class="aud-barlist">
              <li>
                <span class="flag" aria-hidden="true">🌐</span>
                <div class="meta">
                  <div class="name">Unknown location</div>
                  <div class="track"><div class="fill" style="width:100%;"></div></div>
                </div>
                <div class="nums">
                  <?= number_format($totalUsers) ?>
                  <span class="pct">100%</span>
                </div>
              </li>
            </ul>
          <?php else: ?>
            <ul class="aud-barlist">
              <?php foreach ($locations as $loc): ?>
                <?php
                $pctBar = $maxLoc > 0 ? round(((int)$loc['count'] / $maxLoc) * 100) : 0;
                ?>
                <li>
                  <span class="flag" aria-hidden="true"><?= admin_audience_h((string)($loc['flag'] ?? '🌐')) ?></span>
                  <div class="meta">
                    <div class="name"><?= admin_audience_h((string)$loc['label']) ?></div>
                    <div class="track"><div class="fill" style="width:<?= (int)$pctBar ?>%;"></div></div>
                  </div>
                  <div class="nums">
                    <?= number_format((int)$loc['count']) ?>
                    <span class="pct"><?= number_format((float)$loc['pct'], 1) ?>%</span>
                  </div>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </div>

        <div class="aud-card">
          <div class="aud-card-hd">
            <h2>Top Devices</h2>
            <a class="more" href="device_activity.php">View All Devices</a>
          </div>
          <?php if ($devices === []): ?>
            <div class="aud-empty">No device sessions yet.</div>
          <?php else: ?>
            <ul class="aud-barlist">
              <?php foreach ($devices as $dev): ?>
                <?php $pctBar = $maxDev > 0 ? round(((int)$dev['count'] / $maxDev) * 100) : 0; ?>
                <li>
                  <span class="dico" aria-hidden="true"><i class="fa <?= admin_audience_h((string)$dev['icon']) ?>"></i></span>
                  <div class="meta">
                    <div class="name"><?= admin_audience_h((string)$dev['label']) ?></div>
                    <div class="track"><div class="fill" style="width:<?= (int)$pctBar ?>%;"></div></div>
                  </div>
                  <div class="nums">
                    <?= number_format((int)$dev['count']) ?>
                    <span class="pct"><?= number_format((float)$dev['pct'], 1) ?>%</span>
                  </div>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </div>

        <div class="aud-card">
          <div class="aud-card-hd">
            <h2>Audience Overview</h2>
          </div>
          <div style="flex:1 1 auto;min-height:0;overflow:hidden;">
            <table class="aud-table">
              <thead>
                <tr>
                  <th>Metric</th>
                  <th>Users</th>
                  <th>Change</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($overviewRows as $row): ?>
                  <?php
                  $d = (float)$row['delta'];
                  $arrow = $d > 0 ? '▲ ' : ($d < 0 ? '▼ ' : '');
                  ?>
                  <tr>
                    <td><?= admin_audience_h((string)$row['metric']) ?></td>
                    <td><?= admin_audience_h((string)$row['value']) ?></td>
                    <td class="chg <?= admin_audience_h((string)$row['delta_cls']) ?>">
                      <?= admin_audience_h($arrow . number_format(abs($d), 1) . '%') ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <div class="aud-seg-wrap">
        <div class="aud-seg-hd">
          Recent User Segments <i class="fa fa-info-circle info" title="Live segments from users and activity"></i>
        </div>
        <div class="aud-segs">
          <?php foreach ($segments as $seg): ?>
            <?php
            $d = (float)$seg['delta'];
            $dCls = $d > 0 ? 'up' : ($d < 0 ? 'down' : 'flat');
            $arrow = $d > 0 ? '▲ ' : ($d < 0 ? '▼ ' : '');
            ?>
            <div class="aud-seg">
              <div class="row">
                <span class="ico <?= admin_audience_h((string)$seg['cls']) ?>" aria-hidden="true">
                  <i class="fa <?= admin_audience_h((string)$seg['icon']) ?>"></i>
                </span>
                <div>
                  <div class="lab"><?= admin_audience_h((string)$seg['label']) ?></div>
                  <div class="val"><?= number_format((int)$seg['value']) ?></div>
                  <div class="delta <?= $dCls ?>"><?= admin_audience_h($arrow . number_format(abs($d), 1) . '%') ?></div>
                </div>
              </div>
              <p class="desc"><?= admin_audience_h((string)$seg['desc']) ?></p>
            </div>
          <?php endforeach; ?>
        </div>
        <div class="aud-seg-foot">
          <a href="userlist.php">View All Segments</a>
        </div>
      </div>
    </div>
  </div>
</div>

<script src="../lib/chart.js/Chart.js"></script>
<script>
(function () {
  var el = document.getElementById('audGrowthChart');
  if (!el || !window.Chart) return;
  var labels = <?= json_encode($growth['labels'], JSON_UNESCAPED_UNICODE) ?>;
  var total = <?= json_encode($growth['total'], JSON_UNESCAPED_UNICODE) ?>;
  var neu = <?= json_encode($growth['new'], JSON_UNESCAPED_UNICODE) ?>;
  new Chart(el.getContext('2d'), {
    type: 'line',
    data: {
      labels: labels,
      datasets: [
        {
          label: 'Total Users',
          data: total,
          borderColor: '#2563eb',
          backgroundColor: 'rgba(37,99,235,.08)',
          borderWidth: 2,
          pointRadius: 2,
          pointBackgroundColor: '#2563eb',
          fill: true,
          lineTension: 0.35
        },
        {
          label: 'New Users',
          data: neu,
          borderColor: '#93c5fd',
          backgroundColor: 'transparent',
          borderWidth: 2,
          borderDash: [5, 4],
          pointRadius: 0,
          fill: false,
          lineTension: 0.35
        }
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
