<?php
declare(strict_types=1);

/**
 * Admin — User Activity table (moderation overview).
 * New Post → User → Activity → Risk → Decision
 */
require_once __DIR__ . '/includes/org_admin_helpers_load.php';
require_once __DIR__ . '/../public_user/includes/msb_reports.php';
require_once __DIR__ . '/includes/msb_moderation_activity.php';
require_once __DIR__ . '/includes/admin_kind_tabs.php';
org_admin_require_admin();

error_reporting(E_ALL);
ini_set('display_errors', '1');

$dbh = org_admin_db();
$kindFilter = admin_kind_from_request();
$kindCounts = admin_kind_user_counts($dbh);
msb_reports_ensure_schema($dbh);
msb_mod_ensure_status_schema($dbh);

$tab = strtolower(trim((string)($_GET['tab'] ?? 'all')));
if (!in_array($tab, ['all', 'new', 'flagged', 'reported', 'actions', 'logins'], true)) {
    $tab = 'all';
}

$q = trim((string)($_GET['q'] ?? ''));
$visibility = strtolower(trim((string)($_GET['visibility'] ?? 'all')));
$postType = strtolower(trim((string)($_GET['post_type'] ?? 'all')));
$status = strtolower(trim((string)($_GET['status'] ?? 'all')));
$risk = strtolower(trim((string)($_GET['risk'] ?? 'all')));
$dateFrom = trim((string)($_GET['from'] ?? ''));
$dateTo = trim((string)($_GET['to'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = (int)($_GET['per_page'] ?? 25);
if (!in_array($perPage, [10, 25, 50, 100], true)) {
    $perPage = 25;
}

if ($dateFrom === '' && $dateTo === '') {
    $dateTo = date('Y-m-d');
    $dateFrom = date('Y-m-d', strtotime('-6 days'));
}

$stats = msb_mod_activity_table_stats($dbh);
$result = msb_mod_activity_table_rows(
    $dbh,
    $tab === 'actions' || $tab === 'logins' ? 'all' : $tab,
    $q,
    $visibility,
    $postType,
    $status,
    $risk,
    $dateFrom,
    $dateTo,
    $page,
    $perPage,
    0,
    $kindFilter
);
$rows = $result['rows'];
$total = (int)$result['total'];

$totalPages = max(1, (int)ceil($total / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}

$qs = static function (array $extra = []) use ($tab, $q, $visibility, $postType, $status, $risk, $dateFrom, $dateTo, $perPage, $page, $kindFilter): string {
    $base = [
        'kind' => $kindFilter,
        'tab' => $tab,
        'q' => $q,
        'visibility' => $visibility,
        'post_type' => $postType,
        'status' => $status,
        'risk' => $risk,
        'from' => $dateFrom,
        'to' => $dateTo,
        'per_page' => $perPage,
        'page' => $page,
    ];
    foreach ($extra as $k => $v) {
        $base[$k] = $v;
    }
    // Drop empties / defaults for cleaner URLs
    foreach ($base as $k => $v) {
        if ($v === '' || $v === null) {
            unset($base[$k]);
        }
    }
    return 'user_activity_table.php?' . http_build_query($base);
};

$deltaHtml = static function (array $s): string {
    $d = (int)($s['delta_pct'] ?? 0);
    $cls = $d > 0 ? 'up' : ($d < 0 ? 'down' : 'flat');
    $arrow = $d > 0 ? '↑' : ($d < 0 ? '↓' : '•');
    return '<span class="uat-delta ' . $cls . '">' . $arrow . ' ' . abs($d) . '%</span>';
};

$riskLabel = static function (string $tier): array {
    $tier = strtolower($tier);
    if ($tier === 'high_risk') {
        return ['High', 'high'];
    }
    if ($tier === 'review') {
        return ['Medium', 'medium'];
    }
    return ['Low', 'low'];
};

$statusLabel = static function (string $st): array {
    $st = strtolower($st);
    if ($st === 'under_review') {
        return ['Under Review', 'under'];
    }
    if ($st === 'review' || $st === 'high_risk') {
        return ['Review', 'review'];
    }
    return ['Normal', 'normal'];
};

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

org_admin_render_head('User Activity');
?>
<?php
require_once __DIR__ . '/includes/admin_chrome.php';
admin_chrome_open(null, [
    'title' => 'User Activity',
    'description' => 'Review and monitor user posts and account activity.',
]);
?>

<style>
  .uat-wrap{padding-top:8px;margin-bottom:0;}
  .uat-top{
    display:flex;align-items:center;justify-content:flex-end;gap:16px;flex-wrap:wrap;
    margin-bottom:12px;
  }
  .uat-top-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center;}
  .uat-search{
    min-width:280px;flex:1 1 280px;max-width:420px;position:relative;
  }
  .uat-search input{
    width:100%;height:38px;border:1px solid #e2e8f0;border-radius:10px;
    padding:0 12px 0 36px;font-size:13px;background:#fff;color:#0f172a;
  }
  .uat-search i{position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#94a3b8;font-size:14px;}
  .uat-btn{
    height:36px;padding:0 12px;border-radius:8px;border:1px solid #e2e8f0;background:#fff;
    font-size:12px;font-weight:700;color:#334155;display:inline-flex;align-items:center;gap:6px;
    text-decoration:none;cursor:pointer;
  }
  .uat-btn:hover{background:#f8fafc;text-decoration:none;color:#0f172a;}
  .uat-btn.primary{background:#2563eb;border-color:#2563eb;color:#fff;}
  .uat-btn.primary:hover{background:#1d4ed8;color:#fff;}

  .uat-cards{
    display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:12px;margin-bottom:12px;
  }
  .uat-card{
    background:#fff;border:1px solid #eef2f7;border-radius:14px;padding:14px 14px 12px;
    box-shadow:0 1px 2px rgba(15,23,42,.04);
  }
  .uat-card-top{display:flex;align-items:center;gap:10px;margin-bottom:8px;}
  .uat-card-top .uat-card-lead{display:flex;align-items:center;gap:10px;min-width:0;flex:1;}
  .uat-card-icon{
    width:32px;height:32px;border-radius:10px;display:flex;align-items:center;justify-content:center;
    background:#eff6ff;color:#2563eb;font-size:15px;flex:0 0 auto;
  }
  .uat-card-icon.warn{background:#fff7ed;color:#ea580c;}
  .uat-card-icon.danger{background:#fef2f2;color:#dc2626;}
  .uat-card-icon.ok{background:#f0fdf4;color:#16a34a;}
  .uat-card-icon.purple{background:#f5f3ff;color:#7c3aed;}
  .uat-card .val{font-size:24px;font-weight:800;color:#0f172a;line-height:1;margin:0;}
  .uat-card .label{font-size:12px;color:#64748b;margin-top:0;font-weight:600;}
  .uat-delta{font-size:11px;font-weight:800;margin-left:auto;flex:0 0 auto;}
  .uat-delta.up{color:#dc2626;}
  .uat-delta.down{color:#16a34a;}
  .uat-delta.flat{color:#94a3b8;}
  .uat-card .vs{font-size:11px;color:#94a3b8;margin-top:4px;}

  .uat-panel{
    background:#fff;border:1px solid #eef2f7;border-radius:14px;overflow:hidden;
    box-shadow:0 1px 2px rgba(15,23,42,.04);margin-bottom:32px;
    display:flex;flex-direction:column;
    /* Leave room so pagination (prev/next/pages) stays fully visible above the viewport bottom */
    max-height:calc(100vh - 280px);
    max-height:calc(100dvh - 280px);
  }
  .uat-tabs{
    display:flex;gap:2px;padding:0 12px;border-bottom:1px solid #eef2f7;overflow:auto;
    flex:0 0 auto;
  }
  .uat-tabs a{
    flex:0 0 auto;padding:12px 12px;font-size:13px;font-weight:700;color:#64748b;
    text-decoration:none;border-bottom:2px solid transparent;margin-bottom:-1px;
  }
  .uat-tabs a:hover{color:#0f172a;text-decoration:none;}
  .uat-tabs a.is-active{color:#2563eb;border-bottom-color:#2563eb;}

  .uat-filters{
    display:flex;gap:8px;flex-wrap:wrap;align-items:center;padding:12px;border-bottom:1px solid #eef2f7;
    background:#fafbfc;flex:0 0 auto;
  }
  .uat-filters select,.uat-filters input[type="date"],.uat-filters input[type="text"]{
    height:34px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:12px;background:#fff;color:#0f172a;
  }
  .uat-filters .uat-inline-search{min-width:160px;}

  .uat-table-wrap{
    flex:1 1 auto;
    min-height:0;
    max-height:none;
    overflow:auto;
    overscroll-behavior:contain;
    margin-bottom:0;
    padding-bottom:0;
    box-sizing:border-box;
  }
  .uat-table{width:100%;border-collapse:separate;border-spacing:0;min-width:1100px;margin-bottom:0;}
  .uat-table tbody tr:last-child td{border-bottom:none;}
  .uat-table th{
    text-align:left;font-size:11px;font-weight:800;letter-spacing:.04em;text-transform:uppercase;
    color:#64748b;padding:10px 12px;border-bottom:1px solid #eef2f7;background:#fff;white-space:nowrap;
    position:sticky;top:0;z-index:2;box-shadow:0 1px 0 #eef2f7;
  }
  .uat-table td{
    padding:12px;border-bottom:1px solid #f1f5f9;vertical-align:middle;font-size:13px;color:#0f172a;
  }
  .uat-table tr:hover td{background:#f8fafc;}
  .uat-post-id{color:#2563eb;font-weight:800;text-decoration:none;}
  .uat-post-id:hover{text-decoration:underline;}
  .uat-user{display:flex;align-items:center;gap:10px;min-width:150px;}
  .uat-avatar{
    width:34px;height:34px;border-radius:999px;background:#dbeafe;color:#1d4ed8;
    display:flex;align-items:center;justify-content:center;font-weight:800;font-size:12px;flex:0 0 auto;
  }
  .uat-user .name{font-weight:700;font-size:13px;line-height:1.2;}
  .uat-user .sub{font-size:11px;color:#64748b;}
  .uat-type{display:inline-flex;align-items:center;gap:6px;font-weight:700;font-size:12px;color:#334155;}
  .uat-preview{display:flex;align-items:center;gap:10px;max-width:280px;}
  .uat-thumb{
    width:40px;height:40px;border-radius:8px;object-fit:cover;background:#e2e8f0;flex:0 0 auto;
  }
  .uat-thumb.ph{
    display:flex;align-items:center;justify-content:center;color:#94a3b8;font-size:14px;
  }
  .uat-preview .txt{
    font-size:12px;line-height:1.35;color:#475569;
    display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;
  }
  .uat-vis{display:inline-flex;align-items:center;gap:5px;font-size:12px;font-weight:700;color:#475569;}
  .uat-act{
    display:flex;gap:8px;flex-wrap:wrap;font-size:11px;font-weight:700;color:#64748b;white-space:nowrap;
  }
  .uat-act span{display:inline-flex;align-items:center;gap:3px;}
  .uat-risk{
    display:inline-block;padding:3px 8px;border-radius:999px;font-size:11px;font-weight:800;
  }
  .uat-risk.high{background:#fee2e2;color:#b91c1c;}
  .uat-risk.medium{background:#ffedd5;color:#c2410c;}
  .uat-risk.low{background:#dcfce7;color:#15803d;}
  .uat-status{
    display:inline-block;padding:3px 8px;border-radius:999px;font-size:11px;font-weight:800;
  }
  .uat-status.under{background:#ffedd5;color:#c2410c;}
  .uat-status.review{background:#fef9c3;color:#a16207;}
  .uat-status.normal{background:#dcfce7;color:#15803d;}
  .uat-status.high_risk{background:#fee2e2;color:#b91c1c;}
  .uat-eye{
    width:32px;height:32px;border-radius:8px;border:1px solid #e2e8f0;background:#fff;
    display:inline-flex;align-items:center;justify-content:center;color:#475569;text-decoration:none;
  }
  .uat-eye:hover{background:#eff6ff;color:#2563eb;text-decoration:none;}

  .uat-foot{
    display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;
    padding:12px;border-top:1px solid #eef2f7;flex:0 0 auto;background:#fff;
  }
  .uat-foot .muted{font-size:12px;color:#64748b;}
  .uat-pages{display:flex;gap:4px;align-items:center;flex-wrap:wrap;}
  .uat-pages a,.uat-pages span{
    min-width:30px;height:30px;padding:0 8px;border-radius:8px;border:1px solid #e2e8f0;background:#fff;
    display:inline-flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;color:#334155;
    text-decoration:none;
  }
  .uat-pages a.is-active{background:#2563eb;border-color:#2563eb;color:#fff;}
  .uat-pages a:hover{background:#f8fafc;text-decoration:none;}
  .uat-empty{padding:40px 16px;text-align:center;color:#64748b;font-size:13px;}

  @media (max-width:1100px){
    .uat-cards{grid-template-columns:repeat(2,minmax(0,1fr));}
  }
  @media (max-width:700px){
    .uat-cards{grid-template-columns:1fr;}
  }
<?= admin_kind_tabs_css('ak') ?>
</style>

<div class="sh-mainpanel">
  <div class="sh-pagebody">
    <div class="uat-wrap">
  <?= admin_kind_tabs_html(
      $kindFilter,
      $kindCounts,
      fn($k) => $qs(['kind' => $k, 'page' => 1]),
      fn($s) => org_admin_h((string)$s),
      'ak',
      'activity'
  ) ?>

      <div class="uat-top">
        <div class="uat-top-actions">
          <form class="uat-search" method="get" action="user_activity_table.php">
            <input type="hidden" name="kind" value="<?= org_admin_h($kindFilter) ?>">
            <input type="hidden" name="tab" value="<?= org_admin_h($tab) ?>">
            <input type="hidden" name="from" value="<?= org_admin_h($dateFrom) ?>">
            <input type="hidden" name="to" value="<?= org_admin_h($dateTo) ?>">
            <input type="hidden" name="visibility" value="<?= org_admin_h($visibility) ?>">
            <input type="hidden" name="post_type" value="<?= org_admin_h($postType) ?>">
            <input type="hidden" name="status" value="<?= org_admin_h($status) ?>">
            <input type="hidden" name="risk" value="<?= org_admin_h($risk) ?>">
            <input type="hidden" name="per_page" value="<?= (int)$perPage ?>">
            <i class="fa fa-search" aria-hidden="true"></i>
            <input type="search" name="q" value="<?= org_admin_h($q) ?>" placeholder="Search by user, post ID, or content…">
          </form>
          <a class="uat-btn" href="<?= org_admin_h($qs(['export' => '1'])) ?>" title="Export (CSV coming soon)"><i class="fa fa-download"></i> Export</a>
          <button type="button" class="uat-btn" onclick="document.getElementById('uatFilterForm').scrollIntoView({behavior:'smooth',block:'center'})"><i class="fa fa-sliders"></i> Filters</button>
          <a class="uat-btn" href="reports.php"><i class="fa fa-flag"></i> Reports</a>
        </div>
      </div>

      <div class="uat-cards">
        <?php
          $cards = [
            ['key' => 'new_posts', 'label' => 'New Posts', 'icon' => 'fa-pencil', 'tone' => ''],
            ['key' => 'users_active', 'label' => 'Users Active', 'icon' => 'fa-users', 'tone' => 'purple'],
            ['key' => 'reports', 'label' => 'Reports', 'icon' => 'fa-flag', 'tone' => 'warn'],
            ['key' => 'posts_flagged', 'label' => 'Posts Flagged', 'icon' => 'fa-shield', 'tone' => 'ok'],
            ['key' => 'high_risk', 'label' => 'High Risk Posts', 'icon' => 'fa-warning', 'tone' => 'danger'],
          ];
          foreach ($cards as $c):
            $s = $stats[$c['key']] ?? ['value' => 0, 'delta_pct' => 0];
        ?>
          <div class="uat-card">
            <div class="uat-card-top">
              <div class="uat-card-lead">
                <div class="uat-card-icon <?= org_admin_h($c['tone']) ?>"><i class="fa <?= org_admin_h($c['icon']) ?>"></i></div>
                <div class="val"><?= number_format((int)$s['value']) ?></div>
              </div>
              <?= $deltaHtml($s) ?>
            </div>
            <div class="label"><?= org_admin_h($c['label']) ?></div>
            <div class="vs">vs yesterday</div>
          </div>
        <?php endforeach; ?>
      </div>

      <div class="uat-panel">
        <div class="uat-tabs">
          <?php
            $tabs = [
              'all' => 'All Activity',
              'new' => 'New Posts',
              'flagged' => 'Flagged Posts',
              'reported' => 'Reported Posts',
              'actions' => 'User Actions',
              'logins' => 'Logins',
            ];
            foreach ($tabs as $k => $lab):
          ?>
            <a href="<?= org_admin_h($qs(['tab' => $k, 'page' => 1])) ?>" class="<?= $tab === $k ? 'is-active' : '' ?>"><?= org_admin_h($lab) ?></a>
          <?php endforeach; ?>
        </div>

        <form id="uatFilterForm" class="uat-filters" method="get" action="user_activity_table.php">
          <input type="hidden" name="kind" value="<?= org_admin_h($kindFilter) ?>">
          <input type="hidden" name="tab" value="<?= org_admin_h($tab) ?>">
          <input type="date" name="from" value="<?= org_admin_h($dateFrom) ?>" title="From">
          <input type="date" name="to" value="<?= org_admin_h($dateTo) ?>" title="To">
          <select name="post_type">
            <option value="all">All Post Types</option>
            <?php foreach (['text' => 'Text', 'image' => 'Image', 'video' => 'Video', 'link' => 'Link'] as $k => $lab): ?>
              <option value="<?= $k ?>"<?= $postType === $k ? ' selected' : '' ?>><?= org_admin_h($lab) ?></option>
            <?php endforeach; ?>
          </select>
          <select name="visibility">
            <option value="all">All Visibility</option>
            <?php foreach (['public', 'friends', 'private'] as $k): ?>
              <option value="<?= $k ?>"<?= $visibility === $k ? ' selected' : '' ?>><?= org_admin_h(ucfirst($k)) ?></option>
            <?php endforeach; ?>
          </select>
          <select name="status">
            <option value="all">All Status</option>
            <option value="normal"<?= $status === 'normal' ? ' selected' : '' ?>>Normal</option>
            <option value="review"<?= $status === 'review' ? ' selected' : '' ?>>Review</option>
            <option value="under_review"<?= $status === 'under_review' ? ' selected' : '' ?>>Under Review</option>
            <option value="high_risk"<?= $status === 'high_risk' ? ' selected' : '' ?>>High Risk</option>
          </select>
          <select name="risk">
            <option value="all">All Risk Levels</option>
            <option value="low"<?= $risk === 'low' ? ' selected' : '' ?>>Low</option>
            <option value="medium"<?= $risk === 'medium' ? ' selected' : '' ?>>Medium</option>
            <option value="high"<?= $risk === 'high' ? ' selected' : '' ?>>High</option>
          </select>
          <input class="uat-inline-search" type="search" name="q" value="<?= org_admin_h($q) ?>" placeholder="Search in table…">
          <button type="submit" class="uat-btn primary">Apply</button>
          <a class="uat-btn" href="user_activity_table.php"><i class="fa fa-refresh"></i> Clear Filters</a>
        </form>

        <?php if ($tab === 'actions' || $tab === 'logins'): ?>
          <div class="uat-empty">
            <?= $tab === 'logins' ? 'Login / device activity is summarized on each user’s Activity page.' : 'User actions (likes, follows, comments) are summarized on each user’s Activity page.' ?>
            <div style="margin-top:10px;"><a class="uat-btn primary" href="account_search.php">Find a user</a></div>
          </div>
        <?php else: ?>
          <div class="uat-table-wrap">
            <table class="uat-table">
              <thead>
                <tr>
                  <th>Post ID</th>
                  <th>User</th>
                  <th>Post Type</th>
                  <th>Content Preview</th>
                  <th>Visibility</th>
                  <th>Date &amp; Time</th>
                  <th>Activity Summary (7d)</th>
                  <th>Reports</th>
                  <th>Risk Level</th>
                  <th>Status</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
              <?php if (!$rows): ?>
                <tr><td colspan="11"><div class="uat-empty">No activity matches these filters.</div></td></tr>
              <?php else: foreach ($rows as $r): ?>
                <?php
                  $pid = (int)$r['id'];
                  $uid = (int)$r['user_id'];
                  $uname = (string)$r['username'];
                  $name = (string)$r['name'];
                  $initial = mb_strtoupper(mb_substr($uname !== '' ? $uname : ($name !== '' ? $name : 'U'), 0, 1));
                  [$riskTxt, $riskCls] = $riskLabel((string)$r['risk']);
                  [$stTxt, $stCls] = $statusLabel((string)$r['status']);
                  $a7 = $r['activity_7d'] ?? [];
                  $thumb = $mediaUrl((string)($r['thumb'] ?? ''));
                  $vis = (string)$r['visibility'];
                  $visIcon = $vis === 'friends' ? 'fa-users' : ($vis === 'private' ? 'fa-lock' : 'fa-globe');
                  $typeIcon = 'fa-file-text-o';
                  if ($r['post_type'] === 'Image') $typeIcon = 'fa-image';
                  if ($r['post_type'] === 'Video') $typeIcon = 'fa-film';
                  if ($r['post_type'] === 'Link') $typeIcon = 'fa-link';
                ?>
                <tr>
                  <td><a class="uat-post-id" href="user_activity.php?user_id=<?= $uid ?>&amp;post_id=<?= $pid ?>">#<?= $pid ?></a></td>
                  <td>
                    <div class="uat-user">
                      <div class="uat-avatar"><?= org_admin_h($initial) ?></div>
                      <div>
                        <div class="name"><a href="user_activity.php?user_id=<?= $uid ?>&amp;post_id=<?= $pid ?>">@<?= org_admin_h($uname !== '' ? $uname : ('user' . $uid)) ?></a></div>
                        <div class="sub"><?= org_admin_h($name !== '' ? $name : (string)$r['email']) ?></div>
                      </div>
                    </div>
                  </td>
                  <td><span class="uat-type"><i class="fa <?= $typeIcon ?>"></i> <?= org_admin_h((string)$r['post_type']) ?></span></td>
                  <td>
                    <div class="uat-preview">
                      <?php if ($thumb !== ''): ?>
                        <img class="uat-thumb" src="<?= org_admin_h($thumb) ?>" alt="">
                      <?php else: ?>
                        <div class="uat-thumb ph"><i class="fa fa-align-left"></i></div>
                      <?php endif; ?>
                      <div class="txt"><?= org_admin_h(msb_mod_short_text((string)$r['text_preview'], 90)) ?></div>
                    </div>
                  </td>
                  <td><span class="uat-vis"><i class="fa <?= $visIcon ?>"></i> <?= org_admin_h(ucfirst($vis)) ?></span></td>
                  <td class="muted" style="white-space:nowrap;font-size:12px;"><?= org_admin_h(org_admin_fmt_dt($r['created_at'] ?? '')) ?></td>
                  <td>
                    <div class="uat-act">
                      <span title="Posts 7d"><i class="fa fa-pencil"></i> <?= (int)($a7['posts_7d'] ?? 0) ?></span>
                      <span title="Likes given 7d"><i class="fa fa-heart"></i> <?= (int)($a7['likes_7d'] ?? 0) ?></span>
                      <span title="Comments 7d"><i class="fa fa-comment"></i> <?= (int)($a7['comments_7d'] ?? 0) ?></span>
                      <span title="Shares 7d"><i class="fa fa-share"></i> <?= (int)($a7['shares_7d'] ?? 0) ?></span>
                    </div>
                  </td>
                  <td><?= (int)$r['report_count'] ?></td>
                  <td><span class="uat-risk <?= org_admin_h($riskCls) ?>"><?= org_admin_h($riskTxt) ?></span></td>
                  <td><span class="uat-status <?= org_admin_h($stCls) ?>"><?= org_admin_h($stTxt) ?></span></td>
                  <td>
                    <a class="uat-eye" href="user_activity.php?user_id=<?= $uid ?>&amp;post_id=<?= $pid ?>" title="View user activity"><i class="fa fa-eye"></i></a>
                  </td>
                </tr>
              <?php endforeach; endif; ?>
              </tbody>
            </table>
          </div>

          <?php
            $fromN = $total === 0 ? 0 : (($page - 1) * $perPage + 1);
            $toN = min($total, $page * $perPage);
          ?>
          <div class="uat-foot">
            <div class="muted">Showing <?= (int)$fromN ?> to <?= (int)$toN ?> of <?= number_format($total) ?> results.</div>
            <div class="uat-pages">
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
            <form method="get" action="user_activity_table.php" style="display:flex;gap:6px;align-items:center;">
              <input type="hidden" name="tab" value="<?= org_admin_h($tab) ?>">
              <input type="hidden" name="q" value="<?= org_admin_h($q) ?>">
              <input type="hidden" name="from" value="<?= org_admin_h($dateFrom) ?>">
              <input type="hidden" name="to" value="<?= org_admin_h($dateTo) ?>">
              <input type="hidden" name="visibility" value="<?= org_admin_h($visibility) ?>">
              <input type="hidden" name="post_type" value="<?= org_admin_h($postType) ?>">
              <input type="hidden" name="status" value="<?= org_admin_h($status) ?>">
              <input type="hidden" name="risk" value="<?= org_admin_h($risk) ?>">
              <select name="per_page" onchange="this.form.submit()" style="height:30px;border:1px solid #e2e8f0;border-radius:8px;font-size:12px;">
                <?php foreach ([10, 25, 50, 100] as $n): ?>
                  <option value="<?= $n ?>"<?= $perPage === $n ? ' selected' : '' ?>><?= $n ?> / page</option>
                <?php endforeach; ?>
              </select>
            </form>
          </div>
        <?php endif; ?>
      </div>

    </div>
  </div>
</div>
<?php org_admin_render_foot(); ?>
