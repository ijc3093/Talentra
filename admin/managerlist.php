<?php
declare(strict_types=1);

/**
 * admin/managerlist.php
 * Managers list — viewport-fit UI matching orglist / userlist.
 * Preserves search GET + set_manager_status POST behavior.
 */
require_once __DIR__ . '/includes/org_admin_helpers_load.php';
org_admin_require_admin();

error_reporting(E_ALL);
ini_set('display_errors', '1');

$dbh = org_admin_db();
$msg = '';
$error = '';

$filter = strtolower(trim((string)($_GET['filter'] ?? 'all')));
if (!in_array($filter, ['all', 'active', 'disabled', 'linked', 'unlinked'], true)) {
    $filter = 'all';
}
$search = trim((string)($_GET['q'] ?? ''));

if (isset($_POST['set_manager_status'])) {
    $managerId = (int)($_POST['manager_id'] ?? 0);
    $status = (int)($_POST['status_value'] ?? 0) === 1 ? 1 : 0;
    if ($managerId <= 0) {
        $error = 'Invalid manager id.';
    } elseif (org_admin_set_status($dbh, 'managers', $managerId, $status)) {
        $msg = $status === 1 ? 'Manager activated.' : 'Manager disabled.';
    } else {
        $error = 'Could not update manager status.';
    }
}

function ml_initials(string $name): string
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

function ml_avatar_color(string $key): string
{
    $key = strtolower(trim($key));
    $hash = crc32($key);
    $palette = ['#2563eb', '#7c3aed', '#db2777', '#ea580c', '#16a34a', '#0f766e', '#0891b2', '#475569'];
    return $palette[$hash % count($palette)];
}

$allRows = org_admin_list_managers($dbh, '');
$totalAll = count($allRows);
$activeCount = 0;
$disabledCount = 0;
$linkedCount = 0;
$unlinkedCount = 0;
$withOrgs = 0;
foreach ($allRows as $r) {
    if ((int)($r['status'] ?? 0) === 1) {
        $activeCount++;
    } else {
        $disabledCount++;
    }
    if (!empty($r['pub_user_id'])) {
        $linkedCount++;
    } else {
        $unlinkedCount++;
    }
    if ((int)($r['owned_org_count'] ?? 0) > 0) {
        $withOrgs++;
    }
}

$rows = org_admin_list_managers($dbh, $search);
if ($filter === 'active') {
    $rows = array_values(array_filter($rows, static fn($r) => (int)($r['status'] ?? 0) === 1));
} elseif ($filter === 'disabled') {
    $rows = array_values(array_filter($rows, static fn($r) => (int)($r['status'] ?? 0) !== 1));
} elseif ($filter === 'linked') {
    $rows = array_values(array_filter($rows, static fn($r) => !empty($r['pub_user_id'])));
} elseif ($filter === 'unlinked') {
    $rows = array_values(array_filter($rows, static fn($r) => empty($r['pub_user_id'])));
}
$total = count($rows);
$qSuffix = $search !== '' ? '&q=' . rawurlencode($search) : '';

$tabMeta = [
    'all' => ['All', $totalAll],
    'active' => ['Active', $activeCount],
    'disabled' => ['Disabled', $disabledCount],
    'linked' => ['Publisher linked', $linkedCount],
    'unlinked' => ['Not linked', $unlinkedCount],
];

org_admin_render_head('Managers');
require_once __DIR__ . '/includes/admin_chrome.php';
admin_chrome_open(null, [
    'title' => 'Managers',
    'description' => 'Organization owner accounts for the org portal.',
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
  .ml-wrap{
    flex:1 1 auto;min-height:0;height:100%;width:100%;max-width:100%;
    display:flex;flex-direction:column;gap:8px;overflow:hidden !important;padding:0 2px;box-sizing:border-box;
  }
  .ml-top{flex:0 0 auto;display:flex;align-items:center;justify-content:flex-end;gap:10px;min-width:0;}
  .ml-actions{display:flex;gap:6px;flex-wrap:wrap;align-items:center;}
  .ml-btn{
    height:30px;padding:0 10px;border-radius:8px;border:1px solid #e2e8f0;background:#fff;
    font-size:11px;font-weight:700;color:#334155;display:inline-flex;align-items:center;gap:5px;
    text-decoration:none;cursor:pointer;white-space:nowrap;
  }
  .ml-btn:hover{background:#f8fafc;text-decoration:none;color:#0f172a;}
  .ml-btn.primary{background:#2563eb;border-color:#2563eb;color:#fff;}
  .ml-btn.primary:hover{background:#1d4ed8;color:#fff;}
  .ml-btn.sm{height:22px;padding:0 7px;font-size:9px;}

  .ml-cards{flex:0 0 auto;display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:8px;}
  .ml-card{
    background:#fff;border:1px solid #eef2f7;border-radius:12px;padding:10px 12px;
    box-shadow:0 1px 2px rgba(15,23,42,.04);min-width:0;text-decoration:none;color:inherit;display:block;
  }
  .ml-card.is-kind{cursor:pointer;transition:border-color .15s, box-shadow .15s;}
  .ml-card.is-kind:hover{border-color:#bfdbfe;text-decoration:none;color:inherit;}
  .ml-card.is-kind.is-active{border-color:#2563eb;box-shadow:0 0 0 1px #2563eb inset;}
  .ml-card-top{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:6px;}
  .ml-card-top .lab{font-size:11px;font-weight:700;color:#64748b;}
  .ml-card-top .delta{font-size:10px;font-weight:800;color:#94a3b8;}
  .ml-ico{
    width:28px;height:28px;border-radius:999px;display:flex;align-items:center;justify-content:center;font-size:12px;flex:0 0 auto;
  }
  .ml-ico.purple{background:#f5f3ff;color:#7c3aed;}
  .ml-ico.green{background:#f0fdf4;color:#16a34a;}
  .ml-ico.blue{background:#dbeafe;color:#2563eb;}
  .ml-ico.orange{background:#fff7ed;color:#ea580c;}
  .ml-ico.red{background:#fef2f2;color:#dc2626;}
  .ml-ico.cyan{background:#ecfeff;color:#0891b2;}
  .ml-card .val{font-size:20px;font-weight:800;color:#0f172a;line-height:1;}
  .ml-card .sub{font-size:10px;color:#94a3b8;font-weight:600;margin-top:4px;}

  .ml-kinds{
    flex:0 0 auto;display:flex;gap:0;background:#fff;border:1px solid #eef2f7;border-radius:10px;
    padding:0 4px;overflow:hidden;min-width:0;
  }
  .ml-kinds a{
    flex:0 0 auto;padding:8px 14px;font-size:12px;font-weight:800;color:#64748b;text-decoration:none;
    border-bottom:2px solid transparent;white-space:nowrap;
  }
  .ml-kinds a .cnt{font-weight:700;color:#94a3b8;margin-left:4px;}
  .ml-kinds a.is-active{color:#2563eb;border-bottom-color:#2563eb;}
  .ml-kinds a:hover{color:#0f172a;text-decoration:none;}

  .ml-panel{
    flex:1 1 auto;min-height:0;min-width:0;background:#fff;border:1px solid #eef2f7;border-radius:12px;
    box-shadow:0 1px 2px rgba(15,23,42,.04);display:flex;flex-direction:column;overflow:hidden !important;
  }
  .ml-filters{
    flex:0 0 auto;display:flex;gap:6px;flex-wrap:wrap;align-items:center;
    padding:10px 12px;border-bottom:1px solid #eef2f7;background:#fafbfc;
  }
  .ml-search{position:relative;flex:1 1 180px;min-width:140px;max-width:280px;}
  .ml-search i{position:absolute;left:9px;top:50%;transform:translateY(-50%);color:#94a3b8;font-size:12px;}
  .ml-search input,.ml-filters select{
    height:30px;border:1px solid #e2e8f0;border-radius:8px;padding:0 9px;font-size:11px;background:#fff;color:#0f172a;
  }
  .ml-search input{width:100%;padding-left:28px;}
  .ml-clear{font-size:11px;font-weight:700;color:#2563eb;text-decoration:none;margin-left:auto;}
  .ml-clear:hover{text-decoration:underline;}

  .ml-table-wrap{flex:1 1 auto;min-height:0;overflow:auto;overscroll-behavior:contain;}
  .ml-table{width:100%;table-layout:fixed;border-collapse:separate;border-spacing:0;}
  .ml-table th{
    text-align:left;font-size:9px;font-weight:800;letter-spacing:.03em;text-transform:uppercase;
    color:#64748b;padding:8px 6px;border-bottom:1px solid #eef2f7;background:#fff;
    position:sticky;top:0;z-index:3;white-space:nowrap;
  }
  .ml-table td{padding:8px 6px;border-bottom:1px solid #f1f5f9;vertical-align:middle;font-size:11px;color:#0f172a;overflow:hidden;}
  .ml-table tr:hover td{background:#f8fafc;}
  .ml-table th:nth-child(1),.ml-table td:nth-child(1){width:36px;}
  .ml-table th:nth-child(2),.ml-table td:nth-child(2){width:26%;}
  .ml-table th:nth-child(3),.ml-table td:nth-child(3){width:16%;}
  .ml-table th:nth-child(4),.ml-table td:nth-child(4){width:22%;}
  .ml-table th:nth-child(5),.ml-table td:nth-child(5){width:80px;}
  .ml-table th:nth-child(6),.ml-table td:nth-child(6){width:14%;}
  .ml-table th:nth-child(7),.ml-table td:nth-child(7){width:44px;}
  .ml-table td:last-child{overflow:visible;}

  .ml-user{display:flex;align-items:center;gap:8px;min-width:0;}
  .ml-av{
    width:28px;height:28px;border-radius:999px;color:#fff;font-size:10px;font-weight:800;
    display:flex;align-items:center;justify-content:center;flex:0 0 28px;
  }
  .ml-user .nm{font-weight:800;font-size:11px;color:#0f172a;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
  .ml-user .un{font-size:10px;color:#64748b;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
  .ml-cell{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
  .ml-orgs{display:flex;flex-direction:column;gap:2px;min-width:0;}
  .ml-orgs a{font-size:10px;font-weight:700;color:#2563eb;text-decoration:none;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
  .ml-orgs a:hover{text-decoration:underline;}
  .ml-when{font-size:10px;color:#64748b;}
  .ml-pill{
    display:inline-flex;align-items:center;padding:2px 7px;border-radius:999px;font-size:10px;font-weight:800;
  }
  .ml-pill.ok{background:#dcfce7;color:#15803d;}
  .ml-pill.bad{background:#fee2e2;color:#b91c1c;}
  .ml-pill.blue{background:#dbeafe;color:#1d4ed8;}
  .ml-pill.gray{background:#f1f5f9;color:#475569;}
  .ml-empty{padding:28px 12px;text-align:center;color:#64748b;font-size:12px;font-weight:700;}
  .ml-alert{flex:0 0 auto;padding:7px 9px;border-radius:8px;font-size:12px;font-weight:700;}
  .ml-alert.ok{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0;}
  .ml-alert.bad{background:#fef2f2;color:#991b1b;border:1px solid #fecaca;}
  .ml-foot{
    flex:0 0 auto;display:flex;align-items:center;justify-content:space-between;gap:8px;
    padding:8px 12px;border-top:1px solid #eef2f7;font-size:11px;color:#64748b;font-weight:600;
  }
  .pill{display:inline-flex;align-items:center;padding:2px 7px;border-radius:999px;font-size:10px;font-weight:800;}
  .pill.ok{background:#dcfce7;color:#15803d;}
  .pill.bad{background:#fee2e2;color:#b91c1c;}

  @media (max-width:900px){
    .ml-cards{grid-template-columns:repeat(2,minmax(0,1fr));}
  }
</style>

<div class="sh-mainpanel">
  <div class="sh-pagebody">
    <div class="ml-wrap">

      <?php if ($error !== ''): ?><div class="ml-alert bad"><?= org_admin_h($error) ?></div><?php endif; ?>
      <?php if ($msg !== ''): ?><div class="ml-alert ok"><?= org_admin_h($msg) ?></div><?php endif; ?>

      <div class="ml-top">
        <div class="ml-actions">
          <a class="ml-btn" href="orglist.php"><i class="fa fa-building"></i> Organizations</a>
          <a class="ml-btn" href="stafflist.php"><i class="fa fa-id-badge"></i> Staff</a>
          <a class="ml-btn primary" href="managerlist.php?filter=active"><i class="fa fa-check-circle"></i> Active</a>
        </div>
      </div>

      <div class="ml-cards">
        <a class="ml-card is-kind<?= $filter === 'all' ? ' is-active' : '' ?>" href="managerlist.php?filter=all<?= $qSuffix ?>">
          <div class="ml-card-top">
            <div style="display:flex;align-items:center;gap:8px;">
              <div class="ml-ico purple"><i class="fa fa-users"></i></div>
              <div class="lab">Total</div>
            </div>
            <div class="delta">• all</div>
          </div>
          <div class="val"><?= number_format($totalAll) ?></div>
          <div class="sub">All managers</div>
        </a>
        <a class="ml-card is-kind<?= $filter === 'active' ? ' is-active' : '' ?>" href="managerlist.php?filter=active<?= $qSuffix ?>">
          <div class="ml-card-top">
            <div style="display:flex;align-items:center;gap:8px;">
              <div class="ml-ico green"><i class="fa fa-check-circle"></i></div>
              <div class="lab">Active</div>
            </div>
            <div class="delta">• live</div>
          </div>
          <div class="val"><?= number_format($activeCount) ?></div>
          <div class="sub">Can sign in</div>
        </a>
        <a class="ml-card is-kind<?= $filter === 'disabled' ? ' is-active' : '' ?>" href="managerlist.php?filter=disabled<?= $qSuffix ?>">
          <div class="ml-card-top">
            <div style="display:flex;align-items:center;gap:8px;">
              <div class="ml-ico red"><i class="fa fa-ban"></i></div>
              <div class="lab">Disabled</div>
            </div>
            <div class="delta">• off</div>
          </div>
          <div class="val"><?= number_format($disabledCount) ?></div>
          <div class="sub">Blocked</div>
        </a>
        <a class="ml-card is-kind<?= $filter === 'linked' ? ' is-active' : '' ?>" href="managerlist.php?filter=linked<?= $qSuffix ?>">
          <div class="ml-card-top">
            <div style="display:flex;align-items:center;gap:8px;">
              <div class="ml-ico blue"><i class="fa fa-link"></i></div>
              <div class="lab">Publisher linked</div>
            </div>
            <div class="delta">• pub</div>
          </div>
          <div class="val"><?= number_format($linkedCount) ?></div>
          <div class="sub">Has public user</div>
        </a>
        <a class="ml-card is-kind<?= $filter === 'unlinked' ? ' is-active' : '' ?>" href="managerlist.php?filter=unlinked<?= $qSuffix ?>">
          <div class="ml-card-top">
            <div style="display:flex;align-items:center;gap:8px;">
              <div class="ml-ico orange"><i class="fa fa-unlink"></i></div>
              <div class="lab">Not linked</div>
            </div>
            <div class="delta">• gap</div>
          </div>
          <div class="val"><?= number_format($unlinkedCount) ?></div>
          <div class="sub">No publisher user</div>
        </a>
        <div class="ml-card">
          <div class="ml-card-top">
            <div style="display:flex;align-items:center;gap:8px;">
              <div class="ml-ico cyan"><i class="fa fa-building"></i></div>
              <div class="lab">Own orgs</div>
            </div>
            <div class="delta">• owners</div>
          </div>
          <div class="val"><?= number_format($withOrgs) ?></div>
          <div class="sub">Managers with orgs</div>
        </div>
      </div>

      <nav class="ml-kinds" aria-label="Manager filter">
        <?php foreach ($tabMeta as $key => [$lab, $cnt]): ?>
          <a href="managerlist.php?filter=<?= rawurlencode($key) . $qSuffix ?>" class="<?= $filter === $key ? 'is-active' : '' ?>">
            <?= org_admin_h($lab) ?><span class="cnt">(<?= (int)$cnt ?>)</span>
          </a>
        <?php endforeach; ?>
      </nav>

      <div class="ml-panel">
        <form class="ml-filters" method="get" action="managerlist.php" id="mlFilters">
          <div class="ml-search">
            <i class="fa fa-search"></i>
            <input type="search" name="q" value="<?= org_admin_h($search) ?>" placeholder="Search username, email, code…" autocomplete="off">
          </div>
          <select name="filter" aria-label="Filter" onchange="this.form.submit()">
            <?php foreach ($tabMeta as $key => [$lab]): ?>
              <option value="<?= org_admin_h($key) ?>"<?= $filter === $key ? ' selected' : '' ?>><?= org_admin_h($lab) ?></option>
            <?php endforeach; ?>
          </select>
          <button type="submit" class="ml-btn sm primary">Search</button>
          <?php if ($search !== '' || $filter !== 'all'): ?>
            <a class="ml-clear" href="managerlist.php"><i class="fa fa-refresh"></i> Clear</a>
          <?php endif; ?>
        </form>

        <div class="ml-table-wrap">
          <table class="ml-table" id="mlTable">
            <thead>
              <tr>
                <th>#</th>
                <th>Manager</th>
                <th>Publisher user</th>
                <th>Organizations</th>
                <th>Status</th>
                <th>Created</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
              <tr><td colspan="7"><div class="ml-empty">No managers found.</div></td></tr>
            <?php else: foreach ($rows as $i => $row):
              $managerId = (int)($row['id'] ?? 0);
              $status = (int)($row['status'] ?? 0);
              $owned = org_admin_manager_orgs($dbh, $managerId);
              $username = (string)($row['username'] ?? '');
              $fullname = (string)($row['fullname'] ?? '');
              $code = (string)($row['friend_code'] ?? '');
              $email = (string)($row['email'] ?? '');
              $iniSrc = $fullname !== '' ? $fullname : ($username !== '' ? $username : $code);
              $ini = ml_initials($iniSrc !== '' ? $iniSrc : 'MG');
              $bg = ml_avatar_color($code !== '' ? $code : $username);
              $ownedCnt = (int)($row['owned_org_count'] ?? count($owned));
            ?>
              <tr>
                <td><?= (int)($i + 1) ?></td>
                <td>
                  <div class="ml-user">
                    <span class="ml-av" style="background:<?= org_admin_h($bg) ?>;"><?= org_admin_h($ini) ?></span>
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
                  <?php if (!empty($row['pub_user_id'])): ?>
                    <div class="ml-cell"><?= org_admin_render_public_user_link((int)$row['pub_user_id'], (string)($row['pub_username'] ?? ''), (string)($row['pub_username'] ?? ''), (string)($row['pub_code'] ?? '')) ?></div>
                    <div class="ml-when"><?= org_admin_h((string)($row['pub_code'] ?? '')) ?></div>
                  <?php else: ?>
                    <span class="ml-pill gray">Not linked</span>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if (!$owned): ?>
                    <span class="ml-pill gray">None owned</span>
                  <?php else: ?>
                    <div class="ml-orgs">
                      <span class="ml-pill blue" style="align-self:flex-start;margin-bottom:2px;"><?= (int)$ownedCnt ?> org<?= $ownedCnt === 1 ? '' : 's' ?></span>
                      <?php foreach (array_slice($owned, 0, 3) as $o): ?>
                        <a href="orgdetail.php?id=<?= (int)($o['id'] ?? 0) ?>" title="<?= org_admin_h((string)($o['name'] ?? '')) ?>"><?= org_admin_h((string)($o['name'] ?? '')) ?></a>
                      <?php endforeach; ?>
                      <?php if (count($owned) > 3): ?>
                        <span class="ml-when">+<?= count($owned) - 3 ?> more</span>
                      <?php endif; ?>
                    </div>
                  <?php endif; ?>
                </td>
                <td><?= org_admin_status_badge($status) ?></td>
                <td><div class="ml-when"><?= org_admin_h(org_admin_fmt_dt($row['created_at'] ?? '')) ?></div></td>
                <td>
                  <div class="fries-menu">
                    <button type="button" class="fries-toggle" title="Actions" aria-label="Actions" aria-haspopup="true">
                      <span class="fries-icon" aria-hidden="true"></span>
                    </button>
                    <div class="fries-dropdown" role="menu">
                      <?php if ($owned): ?>
                        <a class="fries-item" role="menuitem" href="orgdetail.php?id=<?= (int)($owned[0]['id'] ?? 0) ?>">
                          <i class="fa fa-eye"></i> View org
                        </a>
                      <?php endif; ?>
                      <form method="post" class="fries-item-form" onsubmit="return confirm('Change manager status?');">
                        <input type="hidden" name="manager_id" value="<?= $managerId ?>">
                        <input type="hidden" name="status_value" value="<?= $status === 1 ? 0 : 1 ?>">
                        <button type="submit" name="set_manager_status" class="fries-item" role="menuitem">
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

        <div class="ml-foot">
          <span><?= (int)$total ?> manager<?= $total === 1 ? '' : 's' ?></span>
          <span><?= org_admin_h($tabMeta[$filter][0] ?? ucfirst($filter)) ?><?= $search !== '' ? ' · “' . org_admin_h($search) . '”' : '' ?></span>
        </div>
      </div>

    </div>
  </div>
</div>
<?php org_admin_render_foot(); ?>
