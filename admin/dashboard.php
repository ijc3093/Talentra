<?php
// /Business_only3/admin/app/dashboard.php

require_once __DIR__ . '/includes/session_admin.php';
requireAdminLogin();

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/controller.php';

$controller = new Controller();
$dbh = $controller->pdo();

// ✅ Force password change gate (cannot be bypassed by typing dashboard URL)
$adminId = (int)($_SESSION['admin_id'] ?? 0);
if ($adminId <= 0) {
    clearAdminSession();
    header("Location: index.php");
    exit;
}

$stForce = $dbh->prepare("SELECT force_password_change, status, fullname, username, email, image, role FROM admin WHERE idadmin = :id LIMIT 1");
$stForce->execute([':id' => $adminId]);
$acc = $stForce->fetch(PDO::FETCH_ASSOC);

if (!$acc || (int)$acc['status'] !== 1) {
    clearAdminSession();
    header("Location: index.php");
    exit;
}

if ((int)$acc['force_password_change'] === 1) {
    header("Location: change-password.php?force=1");
    exit;
}

// ✅ Admin identity (from session_admin.php login)
$adminLogin = (string)($_SESSION['admin_login'] ?? '');
$adminRole  = (int)($_SESSION['userRole'] ?? 0); // 1 Admin, 2 Manager, 3 Gospel, 4 Staff
$isAdmin    = ($adminRole === 1);

$roleLabels = [1 => 'Administrator', 2 => 'Manager', 3 => 'Gospel', 4 => 'Staff'];
$roleLabel  = $roleLabels[$adminRole] ?? 'Admin';

function dashboard_h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function dashboard_initials(string $name): string
{
    $name = trim(preg_replace('/\s+/', ' ', $name) ?? $name);
    if ($name === '') return 'AD';
    $name = str_replace(['_', '.', '-', '@'], ' ', $name);
    $parts = array_values(array_filter(explode(' ', $name), fn($p) => trim($p) !== ''));
    if (!$parts) return 'AD';
    $first = mb_strtoupper(mb_substr($parts[0], 0, 1));
    $second = count($parts) > 1
        ? mb_strtoupper(mb_substr($parts[count($parts) - 1], 0, 1))
        : mb_strtoupper(mb_substr($parts[0], 1, 1));
    $ini = trim($first . $second);
    return $ini !== '' ? $ini : 'AD';
}

// Counts & money totals for dashboard cards
$userCount     = 0;
$feedbackCount = 0;
$notiCount     = 0;
$deletedCount  = 0;
$orgCount      = 0;
$adminCount    = 0;
$managerCount  = 0;
$publisherRequestCount = 0;
$serviceFeeCents = 0;
$shopRentCents = 0;
$membershipCents = 0;

$dashboardTableExists = static function (PDO $dbh, string $table): bool {
    static $cache = [];
    $table = preg_replace('/[^a-z0-9_]/i', '', $table) ?? '';
    if ($table === '') {
        return false;
    }
    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }
    try {
        $st = $dbh->query('SHOW TABLES LIKE ' . $dbh->quote($table));
        $cache[$table] = (bool)($st && $st->fetchColumn());
    } catch (Throwable $e) {
        $cache[$table] = false;
    }
    return $cache[$table];
};

$dashboardColExists = static function (PDO $dbh, string $table, string $column) use ($dashboardTableExists): bool {
    if (!$dashboardTableExists($dbh, $table)) {
        return false;
    }
    try {
        $st = $dbh->query('SHOW COLUMNS FROM `' . str_replace('`', '', $table) . '` LIKE ' . $dbh->quote($column));
        return (bool)($st && $st->fetch(PDO::FETCH_ASSOC));
    } catch (Throwable $e) {
        return false;
    }
};

try {
    if ($isAdmin) {
        if ($dashboardTableExists($dbh, 'users')) {
            $stmt = $dbh->query('SELECT COUNT(*) FROM users');
            $userCount = (int)$stmt->fetchColumn();
        }

        if ($dashboardTableExists($dbh, 'deleteduser')) {
            $stmt = $dbh->query('SELECT COUNT(*) FROM deleteduser');
            $deletedCount = (int)$stmt->fetchColumn();
        }

        if ($dashboardTableExists($dbh, 'organizations')) {
            $stmt = $dbh->query('SELECT COUNT(*) FROM organizations');
            $orgCount = (int)$stmt->fetchColumn();
        }

        if ($dashboardTableExists($dbh, 'admin')) {
            $stmt = $dbh->query('SELECT COUNT(*) FROM admin');
            $adminCount = (int)$stmt->fetchColumn();
        }

        if ($dashboardTableExists($dbh, 'managers')) {
            $stmt = $dbh->query('SELECT COUNT(*) FROM managers');
            $managerCount = (int)$stmt->fetchColumn();
        }

        if ($dashboardTableExists($dbh, 'publisher_name_authority')) {
            $stmt = $dbh->query("SELECT COUNT(*) FROM publisher_name_authority WHERE status = 'pending'");
            $publisherRequestCount = (int)$stmt->fetchColumn();
        }

        if ($dashboardColExists($dbh, 'org_orders', 'service_fee_cents')) {
            $stmt = $dbh->query('SELECT COALESCE(SUM(COALESCE(service_fee_cents, 0)), 0) FROM org_orders');
            $serviceFeeCents = (int)$stmt->fetchColumn();
        }

        if ($dashboardTableExists($dbh, 'platform_payments')) {
            $stmt = $dbh->query("SELECT COALESCE(SUM(amount_cents), 0) FROM platform_payments WHERE status = 'confirmed'");
            $shopRentCents = (int)$stmt->fetchColumn();
        }

        if ($dashboardTableExists($dbh, 'buyer_membership_payments')) {
            $stmt = $dbh->query("SELECT COALESCE(SUM(amount_cents), 0) FROM buyer_membership_payments WHERE status = 'confirmed'");
            $membershipCents = (int)$stmt->fetchColumn();
        }
    }

    if ($dashboardTableExists($dbh, 'feedback_admin')) {
        $stmt = $dbh->prepare('SELECT COUNT(*) FROM feedback_admin WHERE receiver = :r');
        $stmt->execute([':r' => 'Admin']);
        $feedbackCount = (int)$stmt->fetchColumn();
    }

    if ($dashboardTableExists($dbh, 'notification')) {
        $stmt = $dbh->prepare('SELECT COUNT(*) FROM notification WHERE notireceiver = :r');
        $stmt->execute([':r' => 'Admin']);
        $notiCount = (int)$stmt->fetchColumn();
    }

} catch (PDOException $e) {
    $error = 'DB Error: ' . $e->getMessage();
}

$fmtMoney = static function (int $cents): string {
    return '$' . number_format(max(0, $cents) / 100, 2);
};

$displayName = trim((string)($acc['fullname'] ?? ''));
if ($displayName === '') {
    $displayName = trim((string)($acc['username'] ?? $adminLogin));
}
if ($displayName === '') {
    $displayName = 'Admin';
}

$avatarWeb = '';
if (!empty($acc['image'])) {
    $imgPath = __DIR__ . '/images/' . $acc['image'];
    if (is_file($imgPath)) {
        $avatarWeb = 'images/' . $acc['image'];
    }
}
$initials = dashboard_initials($displayName);

$firstName = explode(' ', $displayName)[0] ?: $displayName;

// Soft chart series scaled from live counts
$u = max(2, $userCount);
$o = max(2, $orgCount);

$barOnline  = [];
$barOffline = [];
$barLabels  = [];
for ($i = 0; $i < 7; $i++) {
    $d = (new DateTimeImmutable('now'))->modify('-' . (6 - $i) . ' days');
    $barLabels[] = $d->format('M d');
    $wave = 0.6 + 0.4 * sin(($i + 1) * 0.8);
    $barOnline[]  = (int)round($u * $wave * (0.8 + ($i % 3) * 0.1));
    $barOffline[] = (int)round($o * $wave * (0.7 + ($i % 4) * 0.1));
}

// Recent activity rows for earnings-style table
$recentRows = [];
for ($i = 0; $i < 6; $i++) {
    $d = (new DateTimeImmutable('now'))->modify('-' . $i . ' days');
    $sales = max(1, (int)round(($u + $o) * (0.08 + (5 - $i) * 0.02)));
    $earn  = $sales * 12.5;
    $tax   = $earn * 0.08;
    $recentRows[] = [
        'date' => $d->format('M d, Y'),
        'sales' => $sales,
        'earnings' => $earn,
        'tax' => $tax,
    ];
}

$topRegions = [
    ['name' => 'United States', 'code' => 'US', 'value' => max(100, $userCount * 18 + 420)],
    ['name' => 'Netherlands', 'code' => 'NL', 'value' => max(80, $orgCount * 22 + 180)],
    ['name' => 'United Kingdom', 'code' => 'GB', 'value' => max(60, $feedbackCount * 15 + 140)],
];
?>
<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Dashboard — Talentra Admin</title>

    <link href="../lib/font-awesome/css/font-awesome.css" rel="stylesheet">
    <link href="../lib/Ionicons/css/ionicons.css" rel="stylesheet">
    <link href="../lib/perfect-scrollbar/css/perfect-scrollbar.css" rel="stylesheet">
    <link href="../lib/jqvmap/jqvmap.css" rel="stylesheet">
    <link rel="stylesheet" href="../css/shamcey.css">
    <?php require_once __DIR__ . '/includes/admin_layout.php'; admin_layout_head_assets(); ?>
    <link rel="stylesheet" href="css/dashboard-shamcey.css?v=10">
  </head>

  <body class="azia-admin">
    <?php
      require_once __DIR__ . '/includes/admin_chrome.php';
      admin_chrome_open('Hi, welcome back!');
    ?>

    <div class="sh-mainpanel">
      <div class="azia-pagebody">
        <!-- Metric cards -->
        <div class="row row-sm azia-metric-row">
          <div class="col-6 col-md-4 col-xl-3">
            <a class="azia-kpi azia-kpi-link" href="service_fees.php">
              <span class="azia-kpi-icon fee"><i class="fa fa-usd"></i></span>
              <span class="azia-kpi-label">Service Fee</span>
              <h3><?= $isAdmin ? dashboard_h($fmtMoney($serviceFeeCents)) : '—' ?></h3>
              <p class="azia-kpi-delta up">Collected from shop orders <span>view</span></p>
            </a>
          </div>
          <div class="col-6 col-md-4 col-xl-3">
            <a class="azia-kpi azia-kpi-link" href="org_rent.php">
              <span class="azia-kpi-icon rent"><i class="fa fa-home"></i></span>
              <span class="azia-kpi-label">Shop Rent</span>
              <h3><?= $isAdmin ? dashboard_h($fmtMoney($shopRentCents)) : '—' ?></h3>
              <p class="azia-kpi-delta up">Confirmed rent payments <span>view</span></p>
            </a>
          </div>
          <div class="col-6 col-md-4 col-xl-3">
            <a class="azia-kpi azia-kpi-link" href="customer_memberships.php">
              <span class="azia-kpi-icon fee"><i class="fa fa-id-card-o"></i></span>
              <span class="azia-kpi-label">Memberships</span>
              <h3><?= $isAdmin ? dashboard_h($fmtMoney($membershipCents)) : '—' ?></h3>
              <p class="azia-kpi-delta up">Customer Plus $10/mo <span>view</span></p>
            </a>
          </div>
          <div class="col-6 col-md-4 col-xl-3 mg-t-15 mg-md-t-0">
            <a class="azia-kpi azia-kpi-link" href="adminroles.php">
              <span class="azia-kpi-icon roles"><i class="fa fa-shield"></i></span>
              <span class="azia-kpi-label">Admin Roles</span>
              <h3><?= $isAdmin ? number_format($adminCount) : '—' ?></h3>
              <p class="azia-kpi-delta up">Admin accounts <span>view</span></p>
            </a>
          </div>
          <div class="col-6 col-md-4 col-xl-3 mg-t-15 mg-xl-t-0">
            <a class="azia-kpi azia-kpi-link" href="userlist.php">
              <span class="azia-kpi-icon users"><i class="fa fa-users"></i></span>
              <span class="azia-kpi-label">Users</span>
              <h3><?= $isAdmin ? number_format($userCount) : '—' ?></h3>
              <p class="azia-kpi-delta up"><?= $isAdmin ? ((int)$deletedCount . ' deleted') : 'Admin only' ?> <span>view</span></p>
            </a>
          </div>
          <div class="col-6 col-md-4 col-xl-3 mg-t-15">
            <a class="azia-kpi azia-kpi-link" href="publisher_requests.php">
              <span class="azia-kpi-icon requests"><i class="fa fa-file-text-o"></i></span>
              <span class="azia-kpi-label">Publisher Requests</span>
              <h3><?= $isAdmin ? number_format($publisherRequestCount) : '—' ?></h3>
              <p class="azia-kpi-delta <?= $publisherRequestCount > 0 ? 'down' : 'up' ?>">Pending approvals <span>view</span></p>
            </a>
          </div>
          <div class="col-6 col-md-4 col-xl-3 mg-t-15">
            <a class="azia-kpi azia-kpi-link" href="orglist.php">
              <span class="azia-kpi-icon orgs"><i class="fa fa-building"></i></span>
              <span class="azia-kpi-label">Organizations</span>
              <h3><?= $isAdmin ? number_format($orgCount) : '—' ?></h3>
              <p class="azia-kpi-delta up">Shops &amp; teams <span>view</span></p>
            </a>
          </div>
          <div class="col-6 col-md-4 col-xl-3 mg-t-15">
            <a class="azia-kpi azia-kpi-link" href="managerlist.php">
              <span class="azia-kpi-icon managers"><i class="fa fa-user"></i></span>
              <span class="azia-kpi-label">Managers</span>
              <h3><?= $isAdmin ? number_format($managerCount) : '—' ?></h3>
              <p class="azia-kpi-delta up">Org owner accounts <span>view</span></p>
            </a>
          </div>
        </div>

        <!-- Charts + map -->
        <div class="row row-sm mg-t-20">
          <div class="col-lg-7">
            <div class="azia-card">
              <div class="azia-card-head">
                <h4>This Year's Total Activity</h4>
                <ul class="azia-legend">
                  <li><span class="dot online"></span> Users</li>
                  <li><span class="dot offline"></span> Organizations</li>
                </ul>
              </div>
              <canvas id="revenueBarChart" height="160"></canvas>
            </div>
          </div>
          <div class="col-lg-5 mg-t-20 mg-lg-t-0">
            <div class="azia-card">
              <div class="azia-card-head">
                <h4>Activity By Region (USA)</h4>
              </div>
              <div id="usaMap" class="azia-map"></div>
            </div>
          </div>
        </div>

        <!-- Table + top countries -->
        <div class="row row-sm mg-t-20">
          <div class="col-lg-8">
            <div class="azia-card">
              <div class="azia-card-head">
                <h4>Your Most Recent Activity</h4>
              </div>
              <div class="table-responsive">
                <table class="table azia-table mg-b-0">
                  <thead>
                    <tr>
                      <th>Date</th>
                      <th>Activity Count</th>
                      <th>Engagement</th>
                      <th>Alerts</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($recentRows as $row): ?>
                      <tr>
                        <td><?= dashboard_h($row['date']) ?></td>
                        <td><?= number_format((int)$row['sales']) ?></td>
                        <td>$<?= number_format($row['earnings'], 2) ?></td>
                        <td>$<?= number_format($row['tax'], 2) ?></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
          <div class="col-lg-4 mg-t-20 mg-lg-t-0">
            <div class="azia-card">
              <div class="azia-card-head">
                <h4>Your Top Regions</h4>
              </div>
              <ul class="azia-countries">
                <?php foreach ($topRegions as $region): ?>
                  <li>
                    <span class="flag flag-<?= dashboard_h(strtolower($region['code'])) ?>"><?= dashboard_h($region['code']) ?></span>
                    <span class="name"><?= dashboard_h($region['name']) ?></span>
                    <strong>$<?= number_format($region['value'], 2) ?></strong>
                  </li>
                <?php endforeach; ?>
              </ul>
            </div>
          </div>
        </div>
      </div>

      <div class="sh-footer">
        <div>Copyright &copy; <?= date('Y') ?>. All Rights Reserved. Talentra</div>
        <div class="mg-t-10 mg-md-t-0">Admin Dashboard</div>
      </div>
    </div>

    <script src="../lib/jquery/jquery.js"></script>
    <script src="../lib/popper.js/popper.js"></script>
    <script src="../lib/bootstrap/bootstrap.js"></script>
    <script src="../lib/jquery-ui/jquery-ui.js"></script>
    <script src="../lib/perfect-scrollbar/js/perfect-scrollbar.jquery.js"></script>
    <script src="../lib/chart.js/Chart.js"></script>
    <script src="../lib/jqvmap/jquery.vmap.js"></script>
    <script src="../lib/jqvmap/maps/jquery.vmap.usa.js"></script>
    <script src="../js/shamcey.js"></script>
    <script>
    (function ($) {
      'use strict';

      var barEl = document.getElementById('revenueBarChart');
      if (barEl && window.Chart) {
        new Chart(barEl.getContext('2d'), {
          type: 'bar',
          data: {
            labels: <?= json_encode($barLabels) ?>,
            datasets: [
              {
                label: 'Users',
                data: <?= json_encode($barOnline) ?>,
                backgroundColor: '#5b47fb',
                barPercentage: 0.5,
                categoryPercentage: 0.55
              },
              {
                label: 'Organizations',
                data: <?= json_encode($barOffline) ?>,
                backgroundColor: '#00cccc',
                barPercentage: 0.5,
                categoryPercentage: 0.55
              }
            ]
          },
          options: {
            responsive: true,
            maintainAspectRatio: true,
            legend: { display: false },
            tooltips: { mode: 'index', intersect: false },
            scales: {
              xAxes: [{
                gridLines: { display: false },
                ticks: { fontColor: '#8392a5', fontSize: 11 }
              }],
              yAxes: [{
                gridLines: { color: 'rgba(0,0,0,0.05)', zeroLineColor: 'rgba(0,0,0,0.05)' },
                ticks: { beginAtZero: true, fontColor: '#8392a5', fontSize: 11 }
              }]
            }
          }
        });
      }

      if ($.fn.vectorMap) {
        $('#usaMap').vectorMap({
          map: 'usa_en',
          backgroundColor: 'transparent',
          borderColor: '#fff',
          borderOpacity: 0.9,
          borderWidth: 1,
          color: '#d4e4ff',
          enableZoom: false,
          hoverColor: '#5b47fb',
          hoverOpacity: null,
          normalizeFunction: 'linear',
          selectedColor: '#00cccc',
          showTooltip: true,
          values: {
            ca: 480, tx: 420, fl: 360, ny: 510, il: 290, pa: 250,
            oh: 220, ga: 310, nc: 280, mi: 240, nj: 300, va: 260,
            wa: 270, az: 230, ma: 290, tn: 200, in: 180, mo: 170,
            md: 210, wi: 160, co: 250, mn: 190, sc: 175, al: 165
          },
          scaleColors: ['#c5d9ff', '#3366ff'],
          onLabelShow: function (event, label, code) {
            label.html(code.toUpperCase());
          }
        });
      }
    })(jQuery);
    </script>
  </body>
</html>
