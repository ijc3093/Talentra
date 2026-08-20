<?php
declare(strict_types=1);

/**
 * admin/stafflist.php
 * Org staff list — viewport-fit UI matching managerlist / orglist.
 * Preserves search GET + set_staff_status POST behavior.
 */
require_once __DIR__ . '/includes/org_admin_helpers_load.php';
org_admin_require_admin();

error_reporting(E_ALL);
ini_set('display_errors', '1');

$dbh = org_admin_db();
$msg = '';
$error = '';

$filter = strtolower(trim((string)($_GET['filter'] ?? 'all')));
if (!in_array($filter, ['all', 'active', 'disabled', 'publisher', 'regular'], true)) {
    $filter = 'all';
}
$search = trim((string)($_GET['q'] ?? ''));

if (isset($_POST['set_staff_status'])) {
    $staffId = (int)($_POST['staff_id'] ?? 0);
    $status = (int)($_POST['status_value'] ?? 0) === 1 ? 1 : 0;
    if ($staffId <= 0) {
        $error = 'Invalid staff id.';
    } elseif (org_admin_set_status($dbh, 'staff_accounts', $staffId, $status)) {
        $msg = $status === 1 ? 'Staff account activated.' : 'Staff account disabled.';
    } else {
        $error = 'Could not update staff status.';
    }
}

function sl_initials(string $name): string
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
    $second = count($parts) > 1
        ? mb_strtoupper(mb_substr($parts[count($parts) - 1], 0, 1))
        : mb_strtoupper(mb_substr($parts[0], 1, 1));
    $ini = trim($first . $second);
    return $ini !== '' ? $ini : '??';
}

function sl_avatar_color(string $key): string
{
    $key = strtolower(trim($key));
    $hash = crc32($key);
    $palette = ['#2563eb', '#7c3aed', '#db2777', '#ea580c', '#16a34a', '#0f766e', '#0891b2', '#475569'];
    return $palette[$hash % count($palette)];
}

$allRows = org_admin_list_staff($dbh, '');
$totalAll = count($allRows);
$activeCount = 0;
$disabledCount = 0;
$publisherCount = 0;
$regularCount = 0;
$orgActiveCount = 0;
foreach ($allRows as $r) {
    if ((int)($r['status'] ?? 0) === 1) {
        $activeCount++;
    } else {
        $disabledCount++;
    }
    if ((int)($r['is_publisher_org'] ?? 0) === 1) {
        $publisherCount++;
    } else {
        $regularCount++;
    }
    if ((int)($r['org_status'] ?? 0) === 1) {
        $orgActiveCount++;
    }
}

$rows = org_admin_list_staff($dbh, $search);
if ($filter === 'active') {
    $rows = array_values(array_filter($rows, static fn($r) => (int)($r['status'] ?? 0) === 1));
} elseif ($filter === 'disabled') {
    $rows = array_values(array_filter($rows, static fn($r) => (int)($r['status'] ?? 0) !== 1));
} elseif ($filter === 'publisher') {
    $rows = array_values(array_filter($rows, static fn($r) => (int)($r['is_publisher_org'] ?? 0) === 1));
} elseif ($filter === 'regular') {
    $rows = array_values(array_filter($rows, static fn($r) => (int)($r['is_publisher_org'] ?? 0) !== 1));
}
$total = count($rows);
$qSuffix = $search !== '' ? '&q=' . rawurlencode($search) : '';

$tabMeta = [
    'all' => ['All', $totalAll],
    'active' => ['Active', $activeCount],
    'disabled' => ['Disabled', $disabledCount],
    'publisher' => ['Publisher orgs', $publisherCount],
    'regular' => ['Regular orgs', $regularCount],
];

org_admin_render_head('Org Staff');
require_once __DIR__ . '/includes/admin_chrome.php';
admin_chrome_open(null, [
    'title' => 'Organization Staff',
    'description' => 'Staff accounts created inside organization workspaces.',
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
  .sl-wrap{
    flex:1 1 auto;min-height:0;height:100%;width:100%;max-width:100%;
    display:flex;flex-direction:column;gap:8px;overflow:hidden !important;padding:0 2px;box-sizing:border-box;
  }
  .sl-top{flex:0 0 auto;display:flex;align-items:center;justify-content:flex-end;gap:10px;min-width:0;}
  .sl-actions{display:flex;gap:6px;flex-wrap:wrap;align-items:center;}
  .sl-btn{
    height:30px;padding:0 10px;border-radius:8px;border:1px solid #e2e8f0;background:#fff;
    font-size:11px;font-weight:700;color:#334155;display:inline-flex;align-items:center;gap:5px;
    text-decoration:none;cursor:pointer;white-space:nowrap;
  }
  .sl-btn:hover{background:#f8fafc;text-decoration:none;color:#0f172a;}
  .sl-btn.primary{background:#2563eb;border-color:#2563eb;color:#fff;}
  .sl-btn.primary:hover{background:#1d4ed8;color:#fff;}
  .sl-btn.sm{height:22px;padding:0 7px;font-size:9px;}

  .sl-cards{flex:0 0 auto;display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:8px;}
  .sl-card{
    background:#fff;border:1px solid #eef2f7;border-radius:12px;padding:10px 12px;
    box-shadow:0 1px 2px rgba(15,23,42,.04);min-width:0;text-decoration:none;color:inherit;display:block;
  }
  .sl-card.is-kind{cursor:pointer;transition:border-color .15s, box-shadow .15s;}
  .sl-card.is-kind:hover{border-color:#bfdbfe;text-decoration:none;color:inherit;}
  .sl-card.is-kind.is-active{border-color:#2563eb;box-shadow:0 0 0 1px #2563eb inset;}
  .sl-card-top{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:6px;}
  .sl-card-top .lab{font-size:11px;font-weight:700;color:#64748b;}
  .sl-card-top .delta{font-size:10px;font-weight:800;color:#94a3b8;}
  .sl-ico{
    width:28px;height:28px;border-radius:999px;display:flex;align-items:center;justify-content:center;font-size:12px;flex:0 0 auto;
  }
  .sl-ico.purple{background:#f5f3ff;color:#7c3aed;}
  .sl-ico.green{background:#f0fdf4;color:#16a34a;}
  .sl-ico.blue{background:#dbeafe;color:#2563eb;}
  .sl-ico.orange{background:#fff7ed;color:#ea580c;}
  .sl-ico.red{background:#fef2f2;color:#dc2626;}
  .sl-ico.cyan{background:#ecfeff;color:#0891b2;}
  .sl-card .val{font-size:20px;font-weight:800;color:#0f172a;line-height:1;}
  .sl-card .sub{font-size:10px;color:#94a3b8;font-weight:600;margin-top:4px;}

  .sl-kinds{
    flex:0 0 auto;display:flex;gap:0;background:#fff;border:1px solid #eef2f7;border-radius:10px;
    padding:0 4px;overflow:hidden;min-width:0;
  }
  .sl-kinds a{
    flex:0 0 auto;padding:8px 14px;font-size:12px;font-weight:800;color:#64748b;text-decoration:none;
    border-bottom:2px solid transparent;white-space:nowrap;
  }
  .sl-kinds a .cnt{font-weight:700;color:#94a3b8;margin-left:4px;}
  .sl-kinds a.is-active{color:#2563eb;border-bottom-color:#2563eb;}
  .sl-kinds a:hover{color:#0f172a;text-decoration:none;}

  .sl-panel{
    flex:1 1 auto;min-height:0;min-width:0;background:#fff;border:1px solid #eef2f7;border-radius:12px;
    box-shadow:0 1px 2px rgba(15,23,42,.04);display:flex;flex-direction:column;overflow:hidden !important;
  }
  .sl-filters{
    flex:0 0 auto;display:flex;gap:6px;flex-wrap:wrap;align-items:center;
    padding:10px 12px;border-bottom:1px solid #eef2f7;background:#fafbfc;
  }
  .sl-search{position:relative;flex:1 1 180px;min-width:140px;max-width:280px;}
  .sl-search i{position:absolute;left:9px;top:50%;transform:translateY(-50%);color:#94a3b8;font-size:12px;}
  .sl-search input,.sl-filters select{
    height:30px;border:1px solid #e2e8f0;border-radius:8px;padding:0 9px;font-size:11px;background:#fff;color:#0f172a;
  }
  .sl-search input{width:100%;padding-left:28px;}
  .sl-clear{font-size:11px;font-weight:700;color:#2563eb;text-decoration:none;margin-left:auto;}
  .sl-clear:hover{text-decoration:underline;}

  .sl-table-wrap{flex:1 1 auto;min-height:0;overflow:auto;overscroll-behavior:contain;}
  .sl-table{width:100%;table-layout:fixed;border-collapse:separate;border-spacing:0;}
  .sl-table th{
    text-align:left;font-size:9px;font-weight:800;letter-spacing:.03em;text-transform:uppercase;
    color:#64748b;padding:8px 6px;border-bottom:1px solid #eef2f7;background:#fff;
    position:sticky;top:0;z-index:3;white-space:nowrap;
  }
  .sl-table td{padding:8px 6px;border-bottom:1px solid #f1f5f9;vertical-align:middle;font-size:11px;color:#0f172a;overflow:hidden;}
  .sl-table tr:hover td{background:#f8fafc;}
  .sl-table th:nth-child(1),.sl-table td:nth-child(1){width:36px;}
  .sl-table th:nth-child(2),.sl-table td:nth-child(2){width:32%;}
  .sl-table th:nth-child(3),.sl-table td:nth-child(3){width:28%;}
  .sl-table th:nth-child(4),.sl-table td:nth-child(4){width:90px;}
  .sl-table th:nth-child(5),.sl-table td:nth-child(5){width:16%;}
  .sl-table th:nth-child(6),.sl-table td:nth-child(6){width:44px;}
  .sl-table td:last-child{overflow:visible;}

  .sl-user{display:flex;align-items:center;gap:8px;min-width:0;}
  .sl-av{
    width:28px;height:28px;border-radius:999px;color:#fff;font-size:10px;font-weight:800;
    display:flex;align-items:center;justify-content:center;flex:0 0 28px;
  }
  .sl-user .nm{font-weight:800;font-size:11px;color:#0f172a;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
  .sl-user .un{font-size:10px;color:#64748b;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
  .sl-org .nm a{font-weight:800;font-size:11px;color:#2563eb;text-decoration:none;}
  .sl-org .nm a:hover{text-decoration:underline;}
  .sl-org .un{font-size:10px;color:#64748b;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
  .sl-when{font-size:10px;color:#64748b;}
  .sl-pill{
    display:inline-flex;align-items:center;padding:2px 7px;border-radius:999px;font-size:10px;font-weight:800;
  }
  .sl-pill.ok{background:#dcfce7;color:#15803d;}
  .sl-pill.bad{background:#fee2e2;color:#b91c1c;}
  .sl-pill.blue{background:#dbeafe;color:#1d4ed8;}
  .sl-pill.gray{background:#f1f5f9;color:#475569;}
  .sl-empty{padding:28px 12px;text-align:center;color:#64748b;font-size:12px;font-weight:700;}
  .sl-alert{flex:0 0 auto;padding:7px 9px;border-radius:8px;font-size:12px;font-weight:700;}
  .sl-alert.ok{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0;}
  .sl-alert.bad{background:#fef2f2;color:#991b1b;border:1px solid #fecaca;}
  .sl-foot{
    flex:0 0 auto;display:flex;align-items:center;justify-content:space-between;gap:8px;
    padding:8px 12px;border-top:1px solid #eef2f7;font-size:11px;color:#64748b;font-weight:600;
  }
  .pill{display:inline-flex;align-items:center;padding:2px 7px;border-radius:999px;font-size:10px;font-weight:800;}
  .pill.ok{background:#dcfce7;color:#15803d;}
  .pill.bad{background:#fee2e2;color:#b91c1c;}

  @media (max-width:900px){
    .sl-cards{grid-template-columns:repeat(2,minmax(0,1fr));}
  }
</style>

<div class="sh-mainpanel">
  <div class="sh-pagebody">
    <div class="sl-wrap">

      <?php if ($error !== ''): ?><div class="sl-alert bad"><?= org_admin_h($error) ?></div><?php endif; ?>
      <?php if ($msg !== ''): ?><div class="sl-alert ok"><?= org_admin_h($msg) ?></div><?php endif; ?>

      <div class="sl-top">
        <div class="sl-actions">
          <a class="sl-btn" href="orglist.php"><i class="fa fa-building"></i> Organizations</a>
          <a class="sl-btn" href="managerlist.php"><i class="fa fa-users"></i> Managers</a>
          <a class="sl-btn primary" href="stafflist.php?filter=active"><i class="fa fa-check-circle"></i> Active</a>
        </div>
      </div>

      <div class="sl-cards">
        <a class="sl-card is-kind<?= $filter === 'all' ? ' is-active' : '' ?>" href="stafflist.php?filter=all<?= $qSuffix ?>">
          <div class="sl-card-top">
            <div style="display:flex;align-items:center;gap:8px;">
              <div class="sl-ico purple"><i class="fa fa-id-badge"></i></div>
              <div class="lab">Total</div>
            </div>
            <div class="delta">• all</div>
          </div>
          <div class="val"><?= number_format($totalAll) ?></div>
          <div class="sub">All staff accounts</div>
        </a>
        <a class="sl-card is-kind<?= $filter === 'active' ? ' is-active' : '' ?>" href="stafflist.php?filter=active<?= $qSuffix ?>">
          <div class="sl-card-top">
            <div style="display:flex;align-items:center;gap:8px;">
              <div class="sl-ico green"><i class="fa fa-check-circle"></i></div>
              <div class="lab">Active</div>
            </div>
            <div class="delta">• live</div>
          </div>
          <div class="val"><?= number_format($activeCount) ?></div>
          <div class="sub">Can sign in</div>
        </a>
        <a class="sl-card is-kind<?= $filter === 'disabled' ? ' is-active' : '' ?>" href="stafflist.php?filter=disabled<?= $qSuffix ?>">
          <div class="sl-card-top">
            <div style="display:flex;align-items:center;gap:8px;">
              <div class="sl-ico red"><i class="fa fa-ban"></i></div>
              <div class="lab">Disabled</div>
            </div>
            <div class="delta">• off</div>
          </div>
          <div class="val"><?= number_format($disabledCount) ?></div>
          <div class="sub">Blocked</div>
        </a>
        <a class="sl-card is-kind<?= $filter === 'publisher' ? ' is-active' : '' ?>" href="stafflist.php?filter=publisher<?= $qSuffix ?>">
          <div class="sl-card-top">
            <div style="display:flex;align-items:center;gap:8px;">
              <div class="sl-ico blue"><i class="fa fa-bullhorn"></i></div>
              <div class="lab">Publisher orgs</div>
            </div>
            <div class="delta">• type</div>
          </div>
          <div class="val"><?= number_format($publisherCount) ?></div>
          <div class="sub">In publisher workspaces</div>
        </a>
        <a class="sl-card is-kind<?= $filter === 'regular' ? ' is-active' : '' ?>" href="stafflist.php?filter=regular<?= $qSuffix ?>">
          <div class="sl-card-top">
            <div style="display:flex;align-items:center;gap:8px;">
              <div class="sl-ico orange"><i class="fa fa-briefcase"></i></div>
              <div class="lab">Regular orgs</div>
            </div>
            <div class="delta">• type</div>
          </div>
          <div class="val"><?= number_format($regularCount) ?></div>
          <div class="sub">In regular workspaces</div>
        </a>
        <div class="sl-card">
          <div class="sl-card-top">
            <div style="display:flex;align-items:center;gap:8px;">
              <div class="sl-ico cyan"><i class="fa fa-building"></i></div>
              <div class="lab">Active orgs</div>
            </div>
            <div class="delta">• host</div>
          </div>
          <div class="val"><?= number_format($orgActiveCount) ?></div>
          <div class="sub">Staff in active orgs</div>
        </div>
      </div>

      <nav class="sl-kinds" aria-label="Staff filter">
        <?php foreach ($tabMeta as $key => [$lab, $cnt]): ?>
          <a href="stafflist.php?filter=<?= rawurlencode($key) . $qSuffix ?>" class="<?= $filter === $key ? 'is-active' : '' ?>">
            <?= org_admin_h($lab) ?><span class="cnt">(<?= (int)$cnt ?>)</span>
          </a>
        <?php endforeach; ?>
      </nav>

      <div class="sl-panel">
        <form class="sl-filters" method="get" action="stafflist.php" id="slFilters">
          <div class="sl-search">
            <i class="fa fa-search"></i>
            <input type="search" name="q" value="<?= org_admin_h($search) ?>" placeholder="Search staff or organization…" autocomplete="off">
          </div>
          <select name="filter" aria-label="Filter" onchange="this.form.submit()">
            <?php foreach ($tabMeta as $key => [$lab]): ?>
              <option value="<?= org_admin_h($key) ?>"<?= $filter === $key ? ' selected' : '' ?>><?= org_admin_h($lab) ?></option>
            <?php endforeach; ?>
          </select>
          <button type="submit" class="sl-btn sm primary">Search</button>
          <?php if ($search !== '' || $filter !== 'all'): ?>
            <a class="sl-clear" href="stafflist.php"><i class="fa fa-refresh"></i> Clear</a>
          <?php endif; ?>
        </form>

        <div class="sl-table-wrap">
          <table class="sl-table" id="slTable">
            <thead>
              <tr>
                <th>#</th>
                <th>Staff</th>
                <th>Organization</th>
                <th>Status</th>
                <th>Created</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
              <tr><td colspan="6"><div class="sl-empty">No staff accounts found.</div></td></tr>
            <?php else: foreach ($rows as $i => $row):
              $staffId = (int)($row['id'] ?? 0);
              $status = (int)($row['status'] ?? 0);
              $orgId = (int)($row['org_id'] ?? 0);
              $username = (string)($row['username'] ?? '');
              $fullname = (string)($row['fullname'] ?? '');
              $code = (string)($row['friend_code'] ?? '');
              $email = (string)($row['email'] ?? '');
              $orgName = (string)($row['org_name'] ?? '');
              $orgCode = (string)($row['org_code'] ?? '');
              $isPub = (int)($row['is_publisher_org'] ?? 0) === 1;
              $iniSrc = $fullname !== '' ? $fullname : ($username !== '' ? $username : $code);
              $ini = sl_initials($iniSrc !== '' ? $iniSrc : 'ST');
              $bg = sl_avatar_color($code !== '' ? $code : $username);
            ?>
              <tr>
                <td><?= (int)($i + 1) ?></td>
                <td>
                  <div class="sl-user">
                    <span class="sl-av" style="background:<?= org_admin_h($bg) ?>;"><?= org_admin_h($ini) ?></span>
                    <div style="min-width:0;">
                      <div class="nm" title="<?= org_admin_h($username) ?>"><?= org_admin_h($username) ?></div>
                      <div class="un">
                        <?= org_admin_h($code) ?>
                        <?php if ($email !== ''): ?> · <?= org_admin_h($email) ?><?php endif; ?>
                      </div>
                      <?php if ($fullname !== ''): ?>
                        <div class="un"><?= org_admin_h($fullname) ?></div>
                      <?php endif; ?>
                    </div>
                  </div>
                </td>
                <td>
                  <div class="sl-org">
                    <div class="nm">
                      <?php if ($orgId > 0): ?>
                        <a href="orgdetail.php?id=<?= $orgId ?>"><?= org_admin_h($orgName) ?></a>
                      <?php else: ?>
                        <?= org_admin_h($orgName !== '' ? $orgName : '—') ?>
                      <?php endif; ?>
                    </div>
                    <div class="un">
                      <?= org_admin_h($orgCode) ?>
                      · <span class="sl-pill <?= $isPub ? 'blue' : 'gray' ?>"><?= $isPub ? 'Publisher' : 'Regular' ?></span>
                    </div>
                  </div>
                </td>
                <td><?= org_admin_status_badge($status) ?></td>
                <td><div class="sl-when"><?= org_admin_h(org_admin_fmt_dt($row['created_at'] ?? '')) ?></div></td>
                <td>
                  <div class="fries-menu">
                    <button type="button" class="fries-toggle" title="Actions" aria-label="Actions" aria-haspopup="true">
                      <span class="fries-icon" aria-hidden="true"></span>
                    </button>
                    <div class="fries-dropdown" role="menu">
                      <?php if ($orgId > 0): ?>
                        <a class="fries-item" role="menuitem" href="orgdetail.php?id=<?= $orgId ?>">
                          <i class="fa fa-eye"></i> View org
                        </a>
                      <?php endif; ?>
                      <form method="post" class="fries-item-form" onsubmit="return confirm('Change staff status?');">
                        <input type="hidden" name="staff_id" value="<?= $staffId ?>">
                        <input type="hidden" name="status_value" value="<?= $status === 1 ? 0 : 1 ?>">
                        <button type="submit" name="set_staff_status" class="fries-item" role="menuitem">
                          <i class="fa <?= $status === 1 ? 'fa-ban' : 'fa-check' ?>"></i>
                          <?= $status === 1 ? 'Disable' : 'Activate' ?>
                        </button>
                      </form>
                    </div>
                  </div>
                </td>
              </tr>
            <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>

        <div class="sl-foot">
          <span><?= (int)$total ?> staff account<?= $total === 1 ? '' : 's' ?></span>
          <span><?= org_admin_h($tabMeta[$filter][0] ?? ucfirst($filter)) ?><?= $search !== '' ? ' · “' . org_admin_h($search) . '”' : '' ?></span>
        </div>
      </div>

    </div>
  </div>
</div>
<?php org_admin_render_foot(); ?>
