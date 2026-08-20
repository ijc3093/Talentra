<?php
declare(strict_types=1);

/**
 * Admin — Trends dashboard (screenshot layout; viewport-fit, no page scroll).
 */
require_once __DIR__ . '/includes/org_admin_helpers_load.php';
require_once __DIR__ . '/includes/msb_moderation_activity.php';
require_once __DIR__ . '/includes/posts_admin_helpers.php';
require_once __DIR__ . '/includes/admin_trends_helpers.php';
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

$metricFilter = strtolower(trim((string)($_GET['metric'] ?? 'all')));
if (!in_array($metricFilter, ['all', 'users', 'posts', 'engagements'], true)) {
    $metricFilter = 'all';
}
$freq = strtolower(trim((string)($_GET['freq'] ?? 'daily')));
if (!in_array($freq, ['daily', 'weekly'], true)) {
    $freq = 'daily';
}
$platform = strtolower(trim((string)($_GET['platform'] ?? 'all')));
if (!in_array($platform, ['all', 'mobile', 'desktop', 'web'], true)) {
    $platform = 'all';
}

$metrics = admin_trends_metrics($dbh, $kindFilter);
$growth = admin_trends_user_growth($dbh, $dateFrom, $dateTo, $kindFilter);
$engSeries = admin_trends_engagement_series($dbh, $dateFrom, $dateTo, $kindFilter);
$activeSeries = admin_trends_active_series($dbh, $dateFrom, $dateTo, $kindFilter);
$retention = admin_trends_retention($dbh, $kindFilter);
$topContent = admin_trends_top_content($dbh, 5, $kindFilter);
$insights = admin_trends_insights($dbh, $dateFrom, $dateTo, $metrics, $engSeries);

$dateLabel = date('M j, Y', strtotime($dateFrom) ?: time()) . ' - ' . date('M j, Y', strtotime($dateTo) ?: time());
$rangeLabel = 'Last 7 Days';
$daySpan = (int)floor(((strtotime($dateTo) ?: time()) - (strtotime($dateFrom) ?: time())) / 86400) + 1;
if ($daySpan > 7) {
    $rangeLabel = 'Last ' . $daySpan . ' Days';
}

$qs = static function (array $extra = []) use ($dateFrom, $dateTo, $metricFilter, $freq, $platform, $kindFilter): string {
    $base = [
        'kind' => $kindFilter,
        'from' => $dateFrom,
        'to' => $dateTo,
        'metric' => $metricFilter,
        'freq' => $freq,
        'platform' => $platform,
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
        if ($k === 'metric' && $v === 'all' && !array_key_exists('metric', $extra)) {
            unset($base[$k]);
        }
        if ($k === 'freq' && $v === 'daily' && !array_key_exists('freq', $extra)) {
            unset($base[$k]);
        }
        if ($k === 'platform' && $v === 'all' && !array_key_exists('platform', $extra)) {
            unset($base[$k]);
        }
    }
    return 'trends.php' . ($base ? ('?' . http_build_query($base)) : '');
};

$metricCards = [
    ['label' => 'Total Users', 'icon' => 'fa-user', 'cls' => 'blue', 'm' => $metrics['users']],
    ['label' => 'Total Posts', 'icon' => 'fa-file-text-o', 'cls' => 'green', 'm' => $metrics['posts']],
    ['label' => 'Total Comments', 'icon' => 'fa-comment-o', 'cls' => 'purple', 'm' => $metrics['comments']],
    ['label' => 'Total Engagements', 'icon' => 'fa-thumbs-o-up', 'cls' => 'orange', 'm' => $metrics['engagements']],
    ['label' => 'New Users', 'icon' => 'fa-user-plus', 'cls' => 'red', 'm' => $metrics['new_users']],
];

org_admin_render_head('Trends');
require_once __DIR__ . '/includes/admin_chrome.php';
admin_chrome_open(null, [
    'title' => 'Trends',
    'crumb' => [
        ['Overview', 'overview.php'],
        ['Trends', null],
    ],
    'description' => 'Analyze key trends for Personal, Publisher, and Commerce audiences.',
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
  .tr-wrap{flex:1 1 auto;min-height:0;height:100%;display:flex;flex-direction:column;gap:6px;overflow:hidden;box-sizing:border-box;}
  .tr-metrics{flex:0 0 auto;display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:6px;}
  .tr-metric{
    background:#fff;border:1px solid #eef2f7;border-radius:10px;padding:8px 10px;
    box-shadow:0 1px 2px rgba(15,23,42,.04);display:flex;align-items:flex-start;gap:8px;min-width:0;
  }
  .tr-metric .ico{
    width:28px;height:28px;border-radius:999px;display:flex;align-items:center;justify-content:center;
    font-size:12px;flex:0 0 28px;
  }
  .tr-metric .ico.blue{background:#eff6ff;color:#2563eb;}
  .tr-metric .ico.green{background:#dcfce7;color:#16a34a;}
  .tr-metric .ico.purple{background:#f3e8ff;color:#7c3aed;}
  .tr-metric .ico.orange{background:#ffedd5;color:#ea580c;}
  .tr-metric .ico.red{background:#fee2e2;color:#dc2626;}
  .tr-metric .k{font-size:10px;font-weight:700;color:#64748b;}
  .tr-metric .v{margin-top:2px;font-size:17px;font-weight:800;color:#0f172a;letter-spacing:-.02em;line-height:1.1;}
  .tr-metric .d{margin-top:2px;font-size:10px;font-weight:700;}
  .tr-metric .d.up{color:#16a34a;}
  .tr-metric .d.down{color:#dc2626;}
  .tr-metric .d.flat{color:#94a3b8;}
  .tr-filters{
    flex:0 0 auto;display:flex;flex-wrap:wrap;gap:6px;align-items:center;
    padding:6px 8px;background:#fff;border:1px solid #eef2f7;border-radius:10px;
  }
  .tr-filters label{display:none;}
  .tr-filters input[type=date],.tr-filters select{
    height:28px;border:1px solid #e2e8f0;border-radius:8px;padding:0 8px;font-size:11px;
    font-weight:700;color:#334155;background:#fff;
  }
  .tr-filters .tr-dates{display:flex;align-items:center;gap:5px;}
  .tr-filters .sep{color:#94a3b8;font-size:11px;font-weight:700;}
  .tr-btn{
    height:28px;padding:0 10px;border-radius:8px;border:1px solid #e2e8f0;background:#fff;
    font-size:11px;font-weight:700;color:#334155;display:inline-flex;align-items:center;gap:5px;
    text-decoration:none;margin-left:auto;cursor:pointer;
  }
  .tr-btn:hover{background:#f8fafc;text-decoration:none;color:#0f172a;}
  .tr-row1{
    flex:1.1 1 0;min-height:0;
    display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:6px;
  }
  .tr-row2{
    flex:1.05 1 0;min-height:0;
    display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr) minmax(0,1.05fr);gap:6px;
  }
  .tr-foot{flex:0 0 auto;min-height:0;}
  .tr-card{
    background:#fff;border:1px solid #eef2f7;border-radius:10px;box-shadow:0 1px 2px rgba(15,23,42,.04);
    padding:8px 10px;min-width:0;min-height:0;display:flex;flex-direction:column;overflow:hidden;
  }
  .tr-card-hd{flex:0 0 auto;display:flex;align-items:center;justify-content:space-between;gap:6px;margin-bottom:4px;}
  .tr-card-hd h2{margin:0;font-size:12px;font-weight:800;color:#0f172a;display:flex;align-items:center;gap:5px;}
  .tr-card-hd h2 .info{color:#94a3b8;font-size:11px;}
  .tr-card-hd .range{font-size:10px;font-weight:700;color:#64748b;}
  .tr-legend{flex:0 0 auto;display:flex;flex-wrap:wrap;gap:10px;margin-bottom:2px;font-size:10px;font-weight:700;color:#64748b;}
  .tr-legend span{display:inline-flex;align-items:center;gap:5px;}
  .tr-legend i{width:14px;height:2px;border-radius:2px;display:inline-block;background:#2563eb;}
  .tr-legend i.dash{background:repeating-linear-gradient(90deg,#93c5fd 0 3px,transparent 3px 6px);}
  .tr-legend i.g{background:#22c55e;}
  .tr-legend i.o{background:#f59e0b;}
  .tr-legend i.p{background:#a855f7;}
  .tr-chart{position:relative;flex:1 1 auto;min-height:0;}
  .tr-chart canvas{width:100% !important;height:100% !important;}
  .tr-content{list-style:none;margin:0;padding:0;flex:1 1 auto;min-height:0;overflow:hidden;display:flex;flex-direction:column;gap:6px;}
  .tr-content li{display:flex;align-items:center;gap:8px;min-width:0;}
  .tr-content .thumb{
    width:36px;height:36px;border-radius:8px;object-fit:cover;flex:0 0 36px;background:#e2e8f0;
  }
  .tr-content .thumb.ph{
    display:flex;align-items:center;justify-content:center;color:#94a3b8;font-size:13px;
  }
  .tr-content .meta{flex:1 1 auto;min-width:0;}
  .tr-content .t{font-size:11px;font-weight:800;color:#0f172a;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
  .tr-content .t a{color:inherit;text-decoration:none;}
  .tr-content .t a:hover{color:#2563eb;}
  .tr-content .s{font-size:10px;font-weight:600;color:#94a3b8;margin-top:1px;display:flex;align-items:center;gap:5px;}
  .tr-content .eng{font-size:11px;font-weight:800;color:#334155;white-space:nowrap;}
  .tr-content-foot{flex:0 0 auto;margin-top:4px;}
  .tr-content-foot a{font-size:11px;font-weight:700;color:#2563eb;text-decoration:none;}
  .tr-content-foot a:hover{text-decoration:underline;}
  .tr-empty{font-size:11px;font-weight:600;color:#94a3b8;padding:8px 0;}
  .tr-insights{
    background:#fff;border:1px solid #eef2f7;border-radius:10px;box-shadow:0 1px 2px rgba(15,23,42,.04);
    padding:8px 8px;display:grid;grid-template-columns:minmax(0,1.4fr) repeat(4,minmax(0,.85fr));gap:8px;align-items:stretch;
  }
  .tr-insights .blurb{min-width:0;}
  .tr-insights .blurb h3{margin:0 0 3px;font-size:12px;font-weight:800;color:#0f172a;}
  .tr-insights .blurb p{margin:0;font-size:11px;font-weight:600;color:#64748b;line-height:1.35;}
  .tr-insight{
    display:flex;align-items:flex-start;gap:7px;padding:4px 6px;border-radius:8px;background:#f8fafc;min-width:0;
  }
  .tr-insight .ico{
    width:24px;height:24px;border-radius:7px;display:flex;align-items:center;justify-content:center;
    font-size:11px;flex:0 0 24px;
  }
  .tr-insight .ico.green{background:#dcfce7;color:#16a34a;}
  .tr-insight .ico.red{background:#fee2e2;color:#dc2626;}
  .tr-insight .ico.orange{background:#ffedd5;color:#ea580c;}
  .tr-insight .ico.purple{background:#f3e8ff;color:#7c3aed;}
  .tr-insight .k{font-size:9px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:.02em;}
  .tr-insight .v{font-size:11px;font-weight:800;color:#0f172a;line-height:1.25;margin-top:1px;}
  @media (max-width:1100px){
    .tr-metrics{grid-template-columns:repeat(3,minmax(0,1fr));}
    .tr-row2,.tr-insights{grid-template-columns:1fr 1fr;}
  }
<?= admin_kind_tabs_css('ak') ?>
</style>

<div class="sh-mainpanel">
  <div class="sh-pagebody">
    <div class="tr-wrap">
      <?= admin_kind_tabs_html(
          $kindFilter,
          $kindCounts,
          fn($k) => $qs(['kind' => $k]),
          fn($s) => admin_trends_h((string)$s),
          'ak',
          'default'
      ) ?>
      <div class="tr-metrics" aria-label="Trend metrics">
        <?php foreach ($metricCards as $c): ?>
          <?php $m = $c['m']; ?>
          <div class="tr-metric">
            <span class="ico <?= admin_trends_h((string)$c['cls']) ?>" aria-hidden="true">
              <i class="fa <?= admin_trends_h((string)$c['icon']) ?>"></i>
            </span>
            <div>
              <div class="k"><?= admin_trends_h((string)$c['label']) ?></div>
              <div class="v"><?= number_format((int)$m['value']) ?></div>
              <div class="d <?= admin_trends_h((string)$m['sub_cls']) ?>"><?= admin_trends_h((string)$m['sub']) ?></div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>

      <form class="tr-filters" method="get" action="trends.php">
        <input type="hidden" name="kind" value="<?= admin_trends_h($kindFilter) ?>">
        <div class="tr-dates" title="<?= admin_trends_h($dateLabel) ?>">
          <i class="fa fa-calendar" style="color:#94a3b8;" aria-hidden="true"></i>
          <label for="tr_from">From</label>
          <input id="tr_from" type="date" name="from" value="<?= admin_trends_h($dateFrom) ?>" onchange="this.form.submit()">
          <span class="sep">–</span>
          <label for="tr_to">To</label>
          <input id="tr_to" type="date" name="to" value="<?= admin_trends_h($dateTo) ?>" onchange="this.form.submit()">
        </div>
        <select name="metric" aria-label="Metrics" onchange="this.form.submit()">
          <option value="all"<?= $metricFilter === 'all' ? ' selected' : '' ?>>All Metrics</option>
          <option value="users"<?= $metricFilter === 'users' ? ' selected' : '' ?>>Users</option>
          <option value="posts"<?= $metricFilter === 'posts' ? ' selected' : '' ?>>Posts</option>
          <option value="engagements"<?= $metricFilter === 'engagements' ? ' selected' : '' ?>>Engagements</option>
        </select>
        <select name="freq" aria-label="Frequency" onchange="this.form.submit()">
          <option value="daily"<?= $freq === 'daily' ? ' selected' : '' ?>>Daily</option>
          <option value="weekly"<?= $freq === 'weekly' ? ' selected' : '' ?>>Weekly</option>
        </select>
        <select name="platform" aria-label="Platforms" onchange="this.form.submit()">
          <option value="all"<?= $platform === 'all' ? ' selected' : '' ?>>All Platforms</option>
          <option value="mobile"<?= $platform === 'mobile' ? ' selected' : '' ?>>Mobile</option>
          <option value="desktop"<?= $platform === 'desktop' ? ' selected' : '' ?>>Desktop</option>
          <option value="web"<?= $platform === 'web' ? ' selected' : '' ?>>Web</option>
        </select>
        <a class="tr-btn" href="<?= admin_trends_h($qs(['export' => '1'])) ?>" title="CSV export coming soon">
          <i class="fa fa-download"></i> Export
        </a>
      </form>

      <div class="tr-row1">
        <div class="tr-card">
          <div class="tr-card-hd">
            <h2>User Growth <i class="fa fa-info-circle info" title="Cumulative users vs new signups"></i></h2>
            <span class="range"><?= admin_trends_h($rangeLabel) ?></span>
          </div>
          <div class="tr-legend">
            <span><i></i> Total Users</span>
            <span><i class="dash"></i> New Users</span>
          </div>
          <div class="tr-chart"><canvas id="trGrowthChart"></canvas></div>
        </div>
        <div class="tr-card">
          <div class="tr-card-hd">
            <h2>Engagement Overview <i class="fa fa-info-circle info" title="Likes, comments, shares, and saves"></i></h2>
            <span class="range"><?= admin_trends_h($rangeLabel) ?></span>
          </div>
          <div class="tr-legend">
            <span><i></i> Likes</span>
            <span><i class="g"></i> Comments</span>
            <span><i class="o"></i> Shares</span>
            <span><i class="p"></i> Saves</span>
          </div>
          <div class="tr-chart"><canvas id="trEngChart"></canvas></div>
        </div>
      </div>

      <div class="tr-row2">
        <div class="tr-card">
          <div class="tr-card-hd">
            <h2>Active Users <i class="fa fa-info-circle info" title="DAU vs MAU by day"></i></h2>
            <span class="range"><?= admin_trends_h($rangeLabel) ?></span>
          </div>
          <div class="tr-legend">
            <span><i></i> Daily Active Users (DAU)</span>
            <span><i class="dash"></i> Monthly Active Users (MAU)</span>
          </div>
          <div class="tr-chart"><canvas id="trActiveChart"></canvas></div>
        </div>
        <div class="tr-card">
          <div class="tr-card-hd">
            <h2>User Retention <i class="fa fa-info-circle info" title="Soft retention from signup + last seen"></i></h2>
            <span class="range">Cohort</span>
          </div>
          <div class="tr-chart"><canvas id="trRetentionChart"></canvas></div>
        </div>
        <div class="tr-card">
          <div class="tr-card-hd">
            <h2>Top Content by Engagements</h2>
          </div>
          <?php if ($topContent === []): ?>
            <div class="tr-empty">No content engagements yet.</div>
          <?php else: ?>
            <ul class="tr-content">
              <?php foreach ($topContent as $item): ?>
                <?php
                $thumbUrl = function_exists('posts_admin_media_url')
                    ? posts_admin_media_url((string)$item['thumb'])
                    : '';
                ?>
                <li>
                  <?php if ($thumbUrl !== ''): ?>
                    <img class="thumb" src="<?= admin_trends_h($thumbUrl) ?>" alt="" width="36" height="36" loading="lazy">
                  <?php else: ?>
                    <span class="thumb ph" aria-hidden="true"><i class="fa <?= admin_trends_h((string)$item['type_icon']) ?>"></i></span>
                  <?php endif; ?>
                  <div class="meta">
                    <div class="t">
                      <a href="post_profile.php?id=<?= (int)$item['id'] ?>"><?= admin_trends_h((string)$item['title']) ?></a>
                    </div>
                    <div class="s">
                      <span><?= admin_trends_h((string)$item['date']) ?></span>
                      <i class="fa <?= admin_trends_h((string)$item['type_icon']) ?>" aria-hidden="true"></i>
                    </div>
                  </div>
                  <div class="eng"><?= number_format((int)$item['engagements']) ?></div>
                </li>
              <?php endforeach; ?>
            </ul>
            <div class="tr-content-foot">
              <a href="posts.php">View All Content</a>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <div class="tr-foot">
        <div class="tr-insights">
          <div class="blurb">
            <h3>Insights</h3>
            <p><?= admin_trends_h((string)$insights['text']) ?></p>
          </div>
          <div class="tr-insight">
            <span class="ico green" aria-hidden="true"><i class="fa fa-calendar"></i></span>
            <div>
              <div class="k">Best Performing Day</div>
              <div class="v"><?= admin_trends_h((string)$insights['best_day']) ?></div>
            </div>
          </div>
          <div class="tr-insight">
            <span class="ico red" aria-hidden="true"><i class="fa fa-calendar"></i></span>
            <div>
              <div class="k">Lowest Performing Day</div>
              <div class="v"><?= admin_trends_h((string)$insights['low_day']) ?></div>
            </div>
          </div>
          <div class="tr-insight">
            <span class="ico orange" aria-hidden="true"><i class="fa fa-clock-o"></i></span>
            <div>
              <div class="k">Peak Engagement Time</div>
              <div class="v"><?= admin_trends_h((string)$insights['peak_time']) ?></div>
            </div>
          </div>
          <div class="tr-insight">
            <span class="ico purple" aria-hidden="true"><i class="fa fa-mobile"></i></span>
            <div>
              <div class="k">Top Platform</div>
              <div class="v">
                <?php if ((string)$insights['top_platform'] !== '—'): ?>
                  <?= admin_trends_h((string)$insights['top_platform']) ?>,
                  <?= number_format((float)$insights['top_platform_pct'], 1) ?>% of sessions
                <?php else: ?>
                  —
                <?php endif; ?>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<script src="../lib/chart.js/Chart.js"></script>
<script>
(function () {
  if (!window.Chart) return;

  function lineOpts() {
    return {
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
    };
  }

  var growthEl = document.getElementById('trGrowthChart');
  if (growthEl) {
    new Chart(growthEl.getContext('2d'), {
      type: 'line',
      data: {
        labels: <?= json_encode($growth['labels'], JSON_UNESCAPED_UNICODE) ?>,
        datasets: [
          {
            label: 'Total Users',
            data: <?= json_encode($growth['total'], JSON_UNESCAPED_UNICODE) ?>,
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
            data: <?= json_encode($growth['new'], JSON_UNESCAPED_UNICODE) ?>,
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
      options: lineOpts()
    });
  }

  var engEl = document.getElementById('trEngChart');
  if (engEl) {
    new Chart(engEl.getContext('2d'), {
      type: 'line',
      data: {
        labels: <?= json_encode($engSeries['labels'], JSON_UNESCAPED_UNICODE) ?>,
        datasets: [
          {
            label: 'Likes',
            data: <?= json_encode($engSeries['likes'], JSON_UNESCAPED_UNICODE) ?>,
            borderColor: '#2563eb',
            backgroundColor: 'transparent',
            borderWidth: 2,
            pointRadius: 2,
            fill: false,
            lineTension: 0.35
          },
          {
            label: 'Comments',
            data: <?= json_encode($engSeries['comments'], JSON_UNESCAPED_UNICODE) ?>,
            borderColor: '#22c55e',
            backgroundColor: 'transparent',
            borderWidth: 2,
            pointRadius: 2,
            fill: false,
            lineTension: 0.35
          },
          {
            label: 'Shares',
            data: <?= json_encode($engSeries['shares'], JSON_UNESCAPED_UNICODE) ?>,
            borderColor: '#f59e0b',
            backgroundColor: 'transparent',
            borderWidth: 2,
            pointRadius: 2,
            fill: false,
            lineTension: 0.35
          },
          {
            label: 'Saves',
            data: <?= json_encode($engSeries['saves'], JSON_UNESCAPED_UNICODE) ?>,
            borderColor: '#a855f7',
            backgroundColor: 'transparent',
            borderWidth: 2,
            pointRadius: 2,
            fill: false,
            lineTension: 0.35
          }
        ]
      },
      options: lineOpts()
    });
  }

  var activeEl = document.getElementById('trActiveChart');
  if (activeEl) {
    new Chart(activeEl.getContext('2d'), {
      type: 'line',
      data: {
        labels: <?= json_encode($activeSeries['labels'], JSON_UNESCAPED_UNICODE) ?>,
        datasets: [
          {
            label: 'DAU',
            data: <?= json_encode($activeSeries['dau'], JSON_UNESCAPED_UNICODE) ?>,
            borderColor: '#2563eb',
            backgroundColor: 'rgba(37,99,235,.08)',
            borderWidth: 2,
            pointRadius: 2,
            fill: true,
            lineTension: 0.35
          },
          {
            label: 'MAU',
            data: <?= json_encode($activeSeries['mau'], JSON_UNESCAPED_UNICODE) ?>,
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
      options: lineOpts()
    });
  }

  var retEl = document.getElementById('trRetentionChart');
  if (retEl) {
    var retOpts = lineOpts();
    retOpts.scales.yAxes[0].ticks.callback = function (v) { return v + '%'; };
    new Chart(retEl.getContext('2d'), {
      type: 'line',
      data: {
        labels: <?= json_encode($retention['labels'], JSON_UNESCAPED_UNICODE) ?>,
        datasets: [
          {
            label: 'Retention',
            data: <?= json_encode($retention['values'], JSON_UNESCAPED_UNICODE) ?>,
            borderColor: '#2563eb',
            backgroundColor: 'rgba(37,99,235,.18)',
            borderWidth: 2,
            pointRadius: 2,
            pointBackgroundColor: '#2563eb',
            fill: true,
            lineTension: 0.35
          }
        ]
      },
      options: retOpts
    });
  }
})();
</script>

<?php org_admin_render_foot(); ?>
