<?php
declare(strict_types=1);

/**
 * admin/orglist.php
 * Organizations list — viewport-fit UI matching userlist / dispute list chrome.
 * Preserves filter/search GET + set_org_status POST behavior.
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

if (isset($_POST['set_org_status'])) {
    $orgId = (int)($_POST['org_id'] ?? 0);
    $status = (int)($_POST['status_value'] ?? 0) === 1 ? 1 : 0;
    if ($orgId <= 0) {
        $error = 'Invalid organization id.';
    } elseif (org_admin_set_status($dbh, 'organizations', $orgId, $status)) {
        $msg = $status === 1 ? 'Organization activated.' : 'Organization disabled.';
    } else {
        $error = 'Could not update organization status.';
    }
}

function orglist_initials(string $name): string
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

function orglist_avatar_color(string $key): string
{
    $key = strtolower(trim($key));
    $hash = crc32($key);
    $palette = ['#2563eb', '#7c3aed', '#db2777', '#ea580c', '#16a34a', '#0f766e', '#0891b2', '#475569'];
    return $palette[$hash % count($palette)];
}

$allRows = org_admin_list_organizations($dbh, 'all', '');
$totalAll = count($allRows);
$activeCount = 0;
$disabledCount = 0;
$publisherCount = 0;
$regularCount = 0;
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
}

$rows = org_admin_list_organizations($dbh, $filter, $search);
$total = count($rows);
$qSuffix = $search !== '' ? '&q=' . rawurlencode($search) : '';

org_admin_render_head('Organizations');
require_once __DIR__ . '/includes/admin_chrome.php';
admin_chrome_open(null, [
    'title' => 'Organizations',
    'description' => 'Publisher and regular workspaces — members, Connect, and shop rent.',
]);
?>

<style>
  /* Viewport lock — match userlist.php: no page scroll; only table body scrolls */
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
  .ol-wrap{
    flex:1 1 auto;min-height:0;height:100%;width:100%;max-width:100%;
    display:flex;flex-direction:column;gap:8px;overflow:hidden !important;padding:0 2px;box-sizing:border-box;
  }
  .ol-top{flex:0 0 auto;display:flex;align-items:center;justify-content:flex-end;gap:10px;min-width:0;}
  .ol-actions{display:flex;gap:6px;flex-wrap:wrap;align-items:center;}
  .ol-btn{
    height:30px;padding:0 10px;border-radius:8px;border:1px solid #e2e8f0;background:#fff;
    font-size:11px;font-weight:700;color:#334155;display:inline-flex;align-items:center;gap:5px;
    text-decoration:none;cursor:pointer;white-space:nowrap;
  }
  .ol-btn:hover{background:#f8fafc;text-decoration:none;color:#0f172a;}
  .ol-btn.primary{background:#2563eb;border-color:#2563eb;color:#fff;}
  .ol-btn.primary:hover{background:#1d4ed8;color:#fff;}
  .ol-btn.sm{height:22px;padding:0 7px;font-size:9px;}

  .ol-cards{flex:0 0 auto;display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:8px;}
  .ol-card{
    background:#fff;border:1px solid #eef2f7;border-radius:12px;padding:10px 12px;
    box-shadow:0 1px 2px rgba(15,23,42,.04);min-width:0;text-decoration:none;color:inherit;display:block;
  }
  .ol-card.is-kind{cursor:pointer;transition:border-color .15s, box-shadow .15s;}
  .ol-card.is-kind:hover{border-color:#bfdbfe;text-decoration:none;color:inherit;}
  .ol-card.is-kind.is-active{border-color:#2563eb;box-shadow:0 0 0 1px #2563eb inset;}
  .ol-card-top{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:6px;}
  .ol-card-top .lab{font-size:11px;font-weight:700;color:#64748b;}
  .ol-card-top .delta{font-size:10px;font-weight:800;color:#94a3b8;}
  .ol-ico{
    width:28px;height:28px;border-radius:999px;display:flex;align-items:center;justify-content:center;font-size:12px;flex:0 0 auto;
  }
  .ol-ico.purple{background:#f5f3ff;color:#7c3aed;}
  .ol-ico.green{background:#f0fdf4;color:#16a34a;}
  .ol-ico.blue{background:#dbeafe;color:#2563eb;}
  .ol-ico.orange{background:#fff7ed;color:#ea580c;}
  .ol-ico.red{background:#fef2f2;color:#dc2626;}
  .ol-ico.cyan{background:#ecfeff;color:#0891b2;}
  .ol-card .val{font-size:20px;font-weight:800;color:#0f172a;line-height:1;}
  .ol-card .sub{font-size:10px;color:#94a3b8;font-weight:600;margin-top:4px;}

  .ol-kinds{
    flex:0 0 auto;display:flex;gap:0;background:#fff;border:1px solid #eef2f7;border-radius:10px;
    padding:0 4px;overflow:hidden;min-width:0;
  }
  .ol-kinds a{
    flex:0 0 auto;padding:8px 14px;font-size:12px;font-weight:800;color:#64748b;text-decoration:none;
    border-bottom:2px solid transparent;white-space:nowrap;
  }
  .ol-kinds a .cnt{font-weight:700;color:#94a3b8;margin-left:4px;}
  .ol-kinds a.is-active{color:#2563eb;border-bottom-color:#2563eb;}
  .ol-kinds a:hover{color:#0f172a;text-decoration:none;}

  .ol-panel{
    flex:1 1 auto;min-height:0;min-width:0;background:#fff;border:1px solid #eef2f7;border-radius:12px;
    box-shadow:0 1px 2px rgba(15,23,42,.04);display:flex;flex-direction:column;overflow:hidden !important;
  }
  .ol-filters{
    flex:0 0 auto;display:flex;gap:6px;flex-wrap:wrap;align-items:center;
    padding:10px 12px;border-bottom:1px solid #eef2f7;background:#fafbfc;
  }
  .ol-search{position:relative;flex:1 1 180px;min-width:140px;max-width:280px;}
  .ol-search i{position:absolute;left:9px;top:50%;transform:translateY(-50%);color:#94a3b8;font-size:12px;}
  .ol-search input,.ol-filters select{
    height:30px;border:1px solid #e2e8f0;border-radius:8px;padding:0 9px;font-size:11px;background:#fff;color:#0f172a;
  }
  .ol-search input{width:100%;padding-left:28px;}
  .ol-clear{font-size:11px;font-weight:700;color:#2563eb;text-decoration:none;margin-left:auto;}
  .ol-clear:hover{text-decoration:underline;}

  .ol-table-wrap{flex:1 1 auto;min-height:0;overflow:auto;overscroll-behavior:contain;}
  .ol-table{width:100%;table-layout:fixed;border-collapse:separate;border-spacing:0;}
  .ol-table th{
    text-align:left;font-size:9px;font-weight:800;letter-spacing:.03em;text-transform:uppercase;
    color:#64748b;padding:8px 6px;border-bottom:1px solid #eef2f7;background:#fff;
    position:sticky;top:0;z-index:3;white-space:nowrap;
  }
  .ol-table td{padding:8px 6px;border-bottom:1px solid #f1f5f9;vertical-align:middle;font-size:11px;color:#0f172a;overflow:hidden;}
  .ol-table tr:hover td{background:#f8fafc;}
  .ol-table th:nth-child(1),.ol-table td:nth-child(1){width:36px;}
  .ol-table th:nth-child(2),.ol-table td:nth-child(2){width:22%;}
  .ol-table th:nth-child(3),.ol-table td:nth-child(3){width:16%;}
  .ol-table th:nth-child(4),.ol-table td:nth-child(4){width:16%;}
  .ol-table th:nth-child(5),.ol-table td:nth-child(5){width:90px;}
  .ol-table th:nth-child(6),.ol-table td:nth-child(6){width:80px;}
  .ol-table th:nth-child(7),.ol-table td:nth-child(7){width:80px;}
  .ol-table th:nth-child(8),.ol-table td:nth-child(8){width:14%;}
  .ol-table th:nth-child(9),.ol-table td:nth-child(9){width:44px;}
  .ol-table td:last-child{overflow:visible;}

  .ol-user{display:flex;align-items:center;gap:8px;min-width:0;}
  .ol-av{
    width:28px;height:28px;border-radius:999px;color:#fff;font-size:10px;font-weight:800;
    display:flex;align-items:center;justify-content:center;flex:0 0 28px;
  }
  .ol-user .nm{font-weight:800;font-size:11px;color:#0f172a;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
  .ol-user .un{font-size:10px;color:#64748b;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
  .ol-cell{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
  .ol-when{font-size:10px;color:#64748b;}
  .ol-pill{
    display:inline-flex;align-items:center;padding:2px 7px;border-radius:999px;font-size:10px;font-weight:800;
  }
  .ol-pill.ok{background:#dcfce7;color:#15803d;}
  .ol-pill.bad{background:#fee2e2;color:#b91c1c;}
  .ol-pill.blue{background:#dbeafe;color:#1d4ed8;}
  .ol-pill.gray{background:#f1f5f9;color:#475569;}
  .ol-empty{padding:28px 12px;text-align:center;color:#64748b;font-size:12px;font-weight:700;}
  .ol-alert{flex:0 0 auto;padding:7px 9px;border-radius:8px;font-size:12px;font-weight:700;}
  .ol-alert.ok{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0;}
  .ol-alert.bad{background:#fef2f2;color:#991b1b;border:1px solid #fecaca;}
  .ol-foot{
    flex:0 0 auto;display:flex;align-items:center;justify-content:space-between;gap:8px;
    padding:8px 12px;border-top:1px solid #eef2f7;font-size:11px;color:#64748b;font-weight:600;
  }
  .pill{display:inline-flex;align-items:center;padding:2px 7px;border-radius:999px;font-size:10px;font-weight:800;}
  .pill.ok{background:#dcfce7;color:#15803d;}
  .pill.bad{background:#fee2e2;color:#b91c1c;}
  .pill.info{background:#dbeafe;color:#1d4ed8;}

  @media (max-width:900px){
    .ol-cards{grid-template-columns:repeat(2,minmax(0,1fr));}
  }
</style>

<div class="sh-mainpanel">
  <div class="sh-pagebody">
    <div class="ol-wrap">

      <?php if ($error !== ''): ?><div class="ol-alert bad"><?= org_admin_h($error) ?></div><?php endif; ?>
      <?php if ($msg !== ''): ?><div class="ol-alert ok"><?= org_admin_h($msg) ?></div><?php endif; ?>

      <div class="ol-top">
        <div class="ol-actions">
          <a class="ol-btn" href="org_stripe_connect.php"><i class="fa fa-cc-stripe"></i> Stripe Connect</a>
          <a class="ol-btn" href="org_commerce_brands.php"><i class="fa fa-tags"></i> Commerce brands</a>
          <a class="ol-btn primary" href="orglist.php?filter=active"><i class="fa fa-check-circle"></i> Active</a>
        </div>
      </div>

      <div class="ol-cards">
        <a class="ol-card is-kind<?= $filter === 'all' ? ' is-active' : '' ?>" href="orglist.php?filter=all<?= $qSuffix ?>">
          <div class="ol-card-top">
            <div style="display:flex;align-items:center;gap:8px;">
              <div class="ol-ico purple"><i class="fa fa-building"></i></div>
              <div class="lab">Total</div>
            </div>
            <div class="delta">• all</div>
          </div>
          <div class="val"><?= number_format($totalAll) ?></div>
          <div class="sub">All organizations</div>
        </a>
        <a class="ol-card is-kind<?= $filter === 'active' ? ' is-active' : '' ?>" href="orglist.php?filter=active<?= $qSuffix ?>">
          <div class="ol-card-top">
            <div style="display:flex;align-items:center;gap:8px;">
              <div class="ol-ico green"><i class="fa fa-check-circle"></i></div>
              <div class="lab">Active</div>
            </div>
            <div class="delta">• live</div>
          </div>
          <div class="val"><?= number_format($activeCount) ?></div>
          <div class="sub">Status on</div>
        </a>
        <a class="ol-card is-kind<?= $filter === 'disabled' ? ' is-active' : '' ?>" href="orglist.php?filter=disabled<?= $qSuffix ?>">
          <div class="ol-card-top">
            <div style="display:flex;align-items:center;gap:8px;">
              <div class="ol-ico red"><i class="fa fa-ban"></i></div>
              <div class="lab">Disabled</div>
            </div>
            <div class="delta">• off</div>
          </div>
          <div class="val"><?= number_format($disabledCount) ?></div>
          <div class="sub">Status off</div>
        </a>
        <a class="ol-card is-kind<?= $filter === 'publisher' ? ' is-active' : '' ?>" href="orglist.php?filter=publisher<?= $qSuffix ?>">
          <div class="ol-card-top">
            <div style="display:flex;align-items:center;gap:8px;">
              <div class="ol-ico blue"><i class="fa fa-bullhorn"></i></div>
              <div class="lab">Publisher</div>
            </div>
            <div class="delta">• type</div>
          </div>
          <div class="val"><?= number_format($publisherCount) ?></div>
          <div class="sub">Publisher orgs</div>
        </a>
        <a class="ol-card is-kind<?= $filter === 'regular' ? ' is-active' : '' ?>" href="orglist.php?filter=regular<?= $qSuffix ?>">
          <div class="ol-card-top">
            <div style="display:flex;align-items:center;gap:8px;">
              <div class="ol-ico orange"><i class="fa fa-briefcase"></i></div>
              <div class="lab">Regular</div>
            </div>
            <div class="delta">• type</div>
          </div>
          <div class="val"><?= number_format($regularCount) ?></div>
          <div class="sub">Regular orgs</div>
        </a>
        <div class="ol-card">
          <div class="ol-card-top">
            <div style="display:flex;align-items:center;gap:8px;">
              <div class="ol-ico cyan"><i class="fa fa-filter"></i></div>
              <div class="lab">Showing</div>
            </div>
            <div class="delta">• view</div>
          </div>
          <div class="val"><?= number_format($total) ?></div>
          <div class="sub"><?= org_admin_h(ucfirst($filter)) ?><?= $search !== '' ? ' · search' : '' ?></div>
        </div>
      </div>

      <nav class="ol-kinds" aria-label="Organization filter">
        <?php
          $tabs = [
            'all' => ['All', $totalAll],
            'active' => ['Active', $activeCount],
            'disabled' => ['Disabled', $disabledCount],
            'publisher' => ['Publisher', $publisherCount],
            'regular' => ['Regular', $regularCount],
          ];
          foreach ($tabs as $key => [$lab, $cnt]):
        ?>
          <a href="orglist.php?filter=<?= rawurlencode($key) . $qSuffix ?>" class="<?= $filter === $key ? 'is-active' : '' ?>">
            <?= org_admin_h($lab) ?><span class="cnt">(<?= (int)$cnt ?>)</span>
          </a>
        <?php endforeach; ?>
      </nav>

      <div class="ol-panel">
        <form class="ol-filters" method="get" action="orglist.php" id="olFilters">
          <div class="ol-search">
            <i class="fa fa-search"></i>
            <input type="search" name="q" value="<?= org_admin_h($search) ?>" placeholder="Search org, manager, publisher…" autocomplete="off">
          </div>
          <select name="filter" aria-label="Filter" onchange="this.form.submit()">
            <option value="all"<?= $filter === 'all' ? ' selected' : '' ?>>All</option>
            <option value="active"<?= $filter === 'active' ? ' selected' : '' ?>>Active</option>
            <option value="disabled"<?= $filter === 'disabled' ? ' selected' : '' ?>>Disabled</option>
            <option value="publisher"<?= $filter === 'publisher' ? ' selected' : '' ?>>Publisher</option>
            <option value="regular"<?= $filter === 'regular' ? ' selected' : '' ?>>Regular</option>
          </select>
          <button type="submit" class="ol-btn sm primary">Search</button>
          <?php if ($search !== '' || $filter !== 'all'): ?>
            <a class="ol-clear" href="orglist.php"><i class="fa fa-refresh"></i> Clear</a>
          <?php endif; ?>
        </form>

        <div class="ol-table-wrap">
          <table class="ol-table" id="olTable">
            <thead>
              <tr>
                <th>#</th>
                <th>Org</th>
                <th>Owner manager</th>
                <th>Publisher user</th>
                <th>Members</th>
                <th>Type</th>
                <th>Status</th>
                <th>Created</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
              <tr><td colspan="9"><div class="ol-empty">No organizations found.</div></td></tr>
            <?php else: foreach ($rows as $i => $row):
              $orgId = (int)($row['id'] ?? 0);
              $status = (int)($row['status'] ?? 0);
              $isPub = (int)($row['is_publisher_org'] ?? 0) === 1;
              $name = (string)($row['name'] ?? '');
              $code = (string)($row['org_code'] ?? '');
              $ini = orglist_initials($name !== '' ? $name : $code);
              $bg = orglist_avatar_color($code !== '' ? $code : $name);
              $mgr = (string)($row['manager_username'] ?? '');
              $mgrCode = (string)($row['manager_code'] ?? '');
              $mgrCnt = (int)($row['manager_count'] ?? 0);
              $staffCnt = (int)($row['staff_count'] ?? 0);
            ?>
              <tr data-search="<?= org_admin_h(strtolower($name . ' ' . $code . ' ' . $mgr . ' ' . (string)($row['pub_username'] ?? ''))) ?>">
                <td><?= (int)($i + 1) ?></td>
                <td>
                  <div class="ol-user">
                    <span class="ol-av" style="background:<?= org_admin_h($bg) ?>;"><?= org_admin_h($ini) ?></span>
                    <div style="min-width:0;">
                      <div class="nm" title="<?= org_admin_h($name) ?>"><?= org_admin_h($name) ?></div>
                      <div class="un"><?= org_admin_h($code) ?></div>
                    </div>
                  </div>
                </td>
                <td>
                  <div class="ol-cell" title="<?= org_admin_h($mgr) ?>"><?= org_admin_h($mgr) ?></div>
                  <div class="ol-when"><?= org_admin_h($mgrCode) ?></div>
                </td>
                <td>
                  <?php if (!empty($row['pub_user_id'])): ?>
                    <div class="ol-cell"><?= org_admin_render_public_user_link((int)$row['pub_user_id'], (string)($row['pub_username'] ?? ''), (string)($row['pub_username'] ?? ''), (string)($row['pub_code'] ?? '')) ?></div>
                    <div class="ol-when"><?= org_admin_h((string)($row['pub_code'] ?? '')) ?></div>
                  <?php else: ?>
                    <span class="ol-pill gray">Not linked</span>
                  <?php endif; ?>
                </td>
                <td>
                  <span class="ol-pill gray"><?= $mgrCnt ?> mgr</span>
                  <span class="ol-pill gray" style="margin-left:2px;"><?= $staffCnt ?> staff</span>
                </td>
                <td><?= $isPub ? '<span class="ol-pill blue">Publisher</span>' : '<span class="ol-pill gray">Regular</span>' ?></td>
                <td><?= org_admin_status_badge($status) ?></td>
                <td><div class="ol-when"><?= org_admin_h(org_admin_fmt_dt($row['created_at'] ?? '')) ?></div></td>
                <td>
                  <div class="fries-menu">
                    <button type="button" class="fries-toggle" title="Actions" aria-label="Actions" aria-haspopup="true">
                      <span class="fries-icon" aria-hidden="true"></span>
                    </button>
                    <div class="fries-dropdown" role="menu">
                      <a class="fries-item" role="menuitem" href="orgdetail.php?id=<?= $orgId ?>&amp;filter=<?= rawurlencode($filter) ?><?= $search !== '' ? '&amp;q=' . rawurlencode($search) : '' ?>">
                        <i class="fa fa-eye"></i> View
                      </a>
                      <form method="post" class="fries-item-form" onsubmit="return confirm('Change organization status?');">
                        <input type="hidden" name="org_id" value="<?= $orgId ?>">
                        <input type="hidden" name="status_value" value="<?= $status === 1 ? 0 : 1 ?>">
                        <button type="submit" name="set_org_status" class="fries-item" role="menuitem">
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

        <div class="ol-foot">
          <span id="olShowing"><?= (int)$total ?> organization<?= $total === 1 ? '' : 's' ?></span>
          <span><?= org_admin_h(ucfirst($filter)) ?><?= $search !== '' ? ' · “' . org_admin_h($search) . '”' : '' ?></span>
        </div>
      </div>

    </div>
  </div>
</div>
<?php org_admin_render_foot(); ?>
