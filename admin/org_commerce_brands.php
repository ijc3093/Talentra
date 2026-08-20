<?php
declare(strict_types=1);

/**
 * admin/org_commerce_brands.php
 * Commerce brand migration — viewport-fit UI matching orglist / userlist.
 * Preserves filter/search GET + migrate / auto-match / bulk POST behavior.
 */
require_once __DIR__ . '/includes/org_admin_helpers_load.php';
require_once __DIR__ . '/../public_user/includes/org_commerce_brands.php';
require_once __DIR__ . '/../public_user/includes/publisher_accounts.php';

org_admin_require_admin();

error_reporting(E_ALL);
ini_set('display_errors', '1');

$dbh = org_admin_db();
org_commerce_brands_ensure_schema($dbh);
publisher_ensure_schema($dbh);

$msg = '';
$error = '';

$filter = strtolower(trim((string)($_GET['filter'] ?? 'unassigned')));
if (!in_array($filter, ['unassigned', 'assigned', 'publisher', 'news_category', 'all'], true)) {
    $filter = 'unassigned';
}
$search = trim((string)($_GET['q'] ?? ''));

if (isset($_POST['migrate_org'])) {
    $orgId = (int)($_POST['org_id'] ?? 0);
    $brandId = (int)($_POST['brand_id'] ?? 0);
    if ($orgId <= 0 || $brandId <= 0) {
        $error = 'Choose an organization and commerce brand.';
    } elseif (org_commerce_brands_migrate_org($dbh, $orgId, $brandId, true)) {
        $brand = org_commerce_brands_get($dbh, $brandId);
        $msg = 'Organization linked to ' . (string)($brand['name'] ?? 'commerce brand') . ' and category set to commerce.';
    } else {
        $error = 'Could not migrate that organization.';
    }
}

if (isset($_POST['auto_match'])) {
    $orgIds = [];
    if (!empty($_POST['org_ids']) && is_array($_POST['org_ids'])) {
        foreach ($_POST['org_ids'] as $oid) {
            $oid = (int)$oid;
            if ($oid > 0) {
                $orgIds[] = $oid;
            }
        }
    }
    $stats = org_commerce_brands_auto_migrate_orgs($dbh, $orgIds);
    $msg = sprintf(
        'Auto-match complete: %d migrated, %d skipped (no name match), %d errors.',
        (int)$stats['migrated'],
        (int)$stats['skipped'],
        (int)$stats['errors']
    );
    if ((int)$stats['migrated'] === 0 && (int)$stats['errors'] === 0) {
        $error = $msg;
        $msg = '';
    }
}

if (isset($_POST['bulk_migrate'])) {
    $brandId = (int)($_POST['bulk_brand_id'] ?? 0);
    $orgIds = [];
    if (!empty($_POST['org_ids']) && is_array($_POST['org_ids'])) {
        foreach ($_POST['org_ids'] as $oid) {
            $oid = (int)$oid;
            if ($oid > 0) {
                $orgIds[] = $oid;
            }
        }
    }
    if ($brandId <= 0) {
        $error = 'Choose a commerce brand for bulk migration.';
    } elseif (!$orgIds) {
        $error = 'Select at least one organization.';
    } else {
        $ok = 0;
        $fail = 0;
        foreach ($orgIds as $orgId) {
            if (org_commerce_brands_migrate_org($dbh, $orgId, $brandId, true)) {
                $ok++;
            } else {
                $fail++;
            }
        }
        $brand = org_commerce_brands_get($dbh, $brandId);
        $msg = sprintf(
            'Bulk migration to %s: %d updated, %d failed.',
            (string)($brand['name'] ?? 'brand'),
            $ok,
            $fail
        );
        if ($ok === 0) {
            $error = $msg;
            $msg = '';
        }
    }
}

function cb_initials(string $name): string
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

function cb_avatar_color(string $key): string
{
    $key = strtolower(trim($key));
    $hash = crc32($key);
    $palette = ['#2563eb', '#7c3aed', '#db2777', '#ea580c', '#16a34a', '#0f766e', '#0891b2', '#475569'];
    return $palette[$hash % count($palette)];
}

$brands = org_commerce_brands_list_active($dbh);
$brandCount = count($brands);

$countUnassigned = count(org_commerce_brands_list_orgs_for_migration($dbh, 'unassigned', ''));
$countAssigned = count(org_commerce_brands_list_orgs_for_migration($dbh, 'assigned', ''));
$countNews = count(org_commerce_brands_list_orgs_for_migration($dbh, 'news_category', ''));
$countPublisher = count(org_commerce_brands_list_orgs_for_migration($dbh, 'publisher', ''));
$countAll = count(org_commerce_brands_list_orgs_for_migration($dbh, 'all', ''));

$rows = org_commerce_brands_list_orgs_for_migration($dbh, $filter, $search);
$total = count($rows);
$suggestCount = 0;
foreach ($rows as $row) {
    if ((int)($row['suggested_brand_id'] ?? 0) > 0 && (int)($row['commerce_brand_id'] ?? 0) <= 0) {
        $suggestCount++;
    }
}

$qSuffix = $search !== '' ? '&q=' . rawurlencode($search) : '';
$showBulk = $rows && $filter !== 'assigned';
$colspan = $showBulk ? 7 : 6;

$tabMeta = [
    'unassigned' => ['No brand', $countUnassigned],
    'news_category' => ['News category', $countNews],
    'assigned' => ['Has brand', $countAssigned],
    'publisher' => ['Publisher orgs', $countPublisher],
    'all' => ['All orgs', $countAll],
];

org_admin_render_head('Commerce Brand Migration');
require_once __DIR__ . '/includes/admin_chrome.php';
admin_chrome_open(null, [
    'title' => 'Commerce Brands',
    'description' => 'Link organizations to McDonald’s, Wendy’s, and other seller brand systems.',
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
  .cb-wrap{
    flex:1 1 auto;min-height:0;height:100%;width:100%;max-width:100%;
    display:flex;flex-direction:column;gap:8px;overflow:hidden !important;padding:0 2px;box-sizing:border-box;
  }
  .cb-top{flex:0 0 auto;display:flex;align-items:center;justify-content:flex-end;gap:10px;min-width:0;}
  .cb-actions{display:flex;gap:6px;flex-wrap:wrap;align-items:center;}
  .cb-btn{
    height:30px;padding:0 10px;border-radius:8px;border:1px solid #e2e8f0;background:#fff;
    font-size:11px;font-weight:700;color:#334155;display:inline-flex;align-items:center;gap:5px;
    text-decoration:none;cursor:pointer;white-space:nowrap;
  }
  .cb-btn:hover{background:#f8fafc;text-decoration:none;color:#0f172a;}
  .cb-btn.primary{background:#2563eb;border-color:#2563eb;color:#fff;}
  .cb-btn.primary:hover{background:#1d4ed8;color:#fff;}
  .cb-btn.sm{height:22px;padding:0 7px;font-size:9px;}

  .cb-cards{flex:0 0 auto;display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:8px;}
  .cb-card{
    background:#fff;border:1px solid #eef2f7;border-radius:12px;padding:10px 12px;
    box-shadow:0 1px 2px rgba(15,23,42,.04);min-width:0;text-decoration:none;color:inherit;display:block;
  }
  .cb-card.is-kind{cursor:pointer;transition:border-color .15s, box-shadow .15s;}
  .cb-card.is-kind:hover{border-color:#bfdbfe;text-decoration:none;color:inherit;}
  .cb-card.is-kind.is-active{border-color:#2563eb;box-shadow:0 0 0 1px #2563eb inset;}
  .cb-card-top{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:6px;}
  .cb-card-top .lab{font-size:11px;font-weight:700;color:#64748b;}
  .cb-card-top .delta{font-size:10px;font-weight:800;color:#94a3b8;}
  .cb-ico{
    width:28px;height:28px;border-radius:999px;display:flex;align-items:center;justify-content:center;font-size:12px;flex:0 0 auto;
  }
  .cb-ico.purple{background:#f5f3ff;color:#7c3aed;}
  .cb-ico.green{background:#f0fdf4;color:#16a34a;}
  .cb-ico.blue{background:#dbeafe;color:#2563eb;}
  .cb-ico.orange{background:#fff7ed;color:#ea580c;}
  .cb-ico.red{background:#fef2f2;color:#dc2626;}
  .cb-ico.cyan{background:#ecfeff;color:#0891b2;}
  .cb-card .val{font-size:20px;font-weight:800;color:#0f172a;line-height:1;}
  .cb-card .sub{font-size:10px;color:#94a3b8;font-weight:600;margin-top:4px;}

  .cb-kinds{
    flex:0 0 auto;display:flex;gap:0;background:#fff;border:1px solid #eef2f7;border-radius:10px;
    padding:0 4px;overflow:hidden;min-width:0;
  }
  .cb-kinds a{
    flex:0 0 auto;padding:8px 14px;font-size:12px;font-weight:800;color:#64748b;text-decoration:none;
    border-bottom:2px solid transparent;white-space:nowrap;
  }
  .cb-kinds a .cnt{font-weight:700;color:#94a3b8;margin-left:4px;}
  .cb-kinds a.is-active{color:#2563eb;border-bottom-color:#2563eb;}
  .cb-kinds a:hover{color:#0f172a;text-decoration:none;}

  .cb-note{
    flex:0 0 auto;background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:6px 10px;
    font-size:10px;color:#1e3a8a;font-weight:600;line-height:1.35;
  }
  .cb-note code{font-size:9px;background:#dbeafe;padding:1px 4px;border-radius:3px;}

  .cb-panel{
    flex:1 1 auto;min-height:0;min-width:0;background:#fff;border:1px solid #eef2f7;border-radius:12px;
    box-shadow:0 1px 2px rgba(15,23,42,.04);display:flex;flex-direction:column;overflow:hidden !important;
  }
  .cb-filters{
    flex:0 0 auto;display:flex;gap:6px;flex-wrap:wrap;align-items:center;
    padding:10px 12px;border-bottom:1px solid #eef2f7;background:#fafbfc;
  }
  .cb-bulk{
    flex:0 0 auto;display:flex;gap:6px;flex-wrap:wrap;align-items:center;
    padding:8px 12px;border-bottom:1px solid #eef2f7;background:#fff;
  }
  .cb-bulk .lab{font-size:10px;font-weight:800;color:#64748b;text-transform:uppercase;margin-right:4px;}
  .cb-search{position:relative;flex:1 1 180px;min-width:140px;max-width:280px;}
  .cb-search i{position:absolute;left:9px;top:50%;transform:translateY(-50%);color:#94a3b8;font-size:12px;}
  .cb-search input,.cb-filters select,.cb-bulk select,.cb-assign select{
    height:30px;border:1px solid #e2e8f0;border-radius:8px;padding:0 9px;font-size:11px;background:#fff;color:#0f172a;
  }
  .cb-search input{width:100%;padding-left:28px;}
  .cb-bulk select{min-width:180px;max-width:240px;}
  .cb-clear{font-size:11px;font-weight:700;color:#2563eb;text-decoration:none;margin-left:auto;}
  .cb-clear:hover{text-decoration:underline;}

  .cb-table-wrap{flex:1 1 auto;min-height:0;overflow:auto;overscroll-behavior:contain;}
  .cb-table{width:100%;table-layout:fixed;border-collapse:separate;border-spacing:0;}
  .cb-table th{
    text-align:left;font-size:9px;font-weight:800;letter-spacing:.03em;text-transform:uppercase;
    color:#64748b;padding:8px 6px;border-bottom:1px solid #eef2f7;background:#fff;
    position:sticky;top:0;z-index:3;white-space:nowrap;
  }
  .cb-table td{padding:8px 6px;border-bottom:1px solid #f1f5f9;vertical-align:middle;font-size:11px;color:#0f172a;overflow:hidden;}
  .cb-table tr:hover td{background:#f8fafc;}
  .cb-table th:nth-child(1),.cb-table td:nth-child(1){width:36px;}
  .cb-table.has-check th:nth-child(1),.cb-table.has-check td:nth-child(1){width:34px;}
  .cb-table.has-check th:nth-child(2),.cb-table.has-check td:nth-child(2){width:22%;}
  .cb-table.has-check th:nth-child(3),.cb-table.has-check td:nth-child(3){width:14%;}
  .cb-table.has-check th:nth-child(4),.cb-table.has-check td:nth-child(4){width:12%;}
  .cb-table.has-check th:nth-child(5),.cb-table.has-check td:nth-child(5){width:12%;}
  .cb-table.has-check th:nth-child(6),.cb-table.has-check td:nth-child(6){width:12%;}
  .cb-table.has-check th:nth-child(7),.cb-table.has-check td:nth-child(7){width:22%;}
  .cb-table:not(.has-check) th:nth-child(1),.cb-table:not(.has-check) td:nth-child(1){width:24%;}
  .cb-table:not(.has-check) th:nth-child(6),.cb-table:not(.has-check) td:nth-child(6){width:24%;}
  .cb-table td:last-child{overflow:visible;}

  .cb-user{display:flex;align-items:center;gap:8px;min-width:0;}
  .cb-av{
    width:28px;height:28px;border-radius:999px;color:#fff;font-size:10px;font-weight:800;
    display:flex;align-items:center;justify-content:center;flex:0 0 28px;
  }
  .cb-user .nm{font-weight:800;font-size:11px;color:#0f172a;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
  .cb-user .nm a{color:inherit;text-decoration:none;}
  .cb-user .nm a:hover{color:#2563eb;text-decoration:underline;}
  .cb-user .un{font-size:10px;color:#64748b;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
  .cb-cell{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
  .cb-when{font-size:10px;color:#64748b;}
  .cb-pill{
    display:inline-flex;align-items:center;padding:2px 7px;border-radius:999px;font-size:10px;font-weight:800;
  }
  .cb-pill.ok{background:#dcfce7;color:#15803d;}
  .cb-pill.bad{background:#fee2e2;color:#b91c1c;}
  .cb-pill.blue{background:#dbeafe;color:#1d4ed8;}
  .cb-pill.gray{background:#f1f5f9;color:#475569;}
  .cb-assign{display:flex;gap:5px;align-items:center;flex-wrap:nowrap;min-width:0;}
  .cb-assign select{flex:1 1 auto;min-width:0;max-width:160px;}
  .cb-empty{padding:28px 12px;text-align:center;color:#64748b;font-size:12px;font-weight:700;}
  .cb-alert{flex:0 0 auto;padding:7px 9px;border-radius:8px;font-size:12px;font-weight:700;}
  .cb-alert.ok{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0;}
  .cb-alert.bad{background:#fef2f2;color:#991b1b;border:1px solid #fecaca;}
  .cb-foot{
    flex:0 0 auto;display:flex;align-items:center;justify-content:space-between;gap:8px;
    padding:8px 12px;border-top:1px solid #eef2f7;font-size:11px;color:#64748b;font-weight:600;
  }
  .pill{display:inline-flex;align-items:center;padding:2px 7px;border-radius:999px;font-size:10px;font-weight:800;}
  .pill.ok{background:#dcfce7;color:#15803d;}
  .pill.bad{background:#fee2e2;color:#b91c1c;}
  .pill.info{background:#dbeafe;color:#1d4ed8;}

  @media (max-width:900px){
    .cb-cards{grid-template-columns:repeat(2,minmax(0,1fr));}
  }
</style>

<div class="sh-mainpanel">
  <div class="sh-pagebody">
    <div class="cb-wrap">

      <?php if ($error !== ''): ?><div class="cb-alert bad"><?= org_admin_h($error) ?></div><?php endif; ?>
      <?php if ($msg !== ''): ?><div class="cb-alert ok"><?= org_admin_h($msg) ?></div><?php endif; ?>

      <div class="cb-top">
        <div class="cb-actions">
          <a class="cb-btn" href="orglist.php"><i class="fa fa-building"></i> Organizations</a>
          <a class="cb-btn" href="org_stripe_connect.php"><i class="fa fa-cc-stripe"></i> Stripe Connect</a>
          <a class="cb-btn primary" href="org_commerce_brands.php?filter=unassigned"><i class="fa fa-link"></i> Unassigned</a>
        </div>
      </div>

      <div class="cb-cards">
        <a class="cb-card is-kind<?= $filter === 'unassigned' ? ' is-active' : '' ?>" href="org_commerce_brands.php?filter=unassigned<?= $qSuffix ?>">
          <div class="cb-card-top">
            <div style="display:flex;align-items:center;gap:8px;">
              <div class="cb-ico red"><i class="fa fa-unlink"></i></div>
              <div class="lab">No brand</div>
            </div>
            <div class="delta">• fix</div>
          </div>
          <div class="val"><?= number_format($countUnassigned) ?></div>
          <div class="sub">Need assignment</div>
        </a>
        <a class="cb-card is-kind<?= $filter === 'assigned' ? ' is-active' : '' ?>" href="org_commerce_brands.php?filter=assigned<?= $qSuffix ?>">
          <div class="cb-card-top">
            <div style="display:flex;align-items:center;gap:8px;">
              <div class="cb-ico green"><i class="fa fa-check-circle"></i></div>
              <div class="lab">Has brand</div>
            </div>
            <div class="delta">• linked</div>
          </div>
          <div class="val"><?= number_format($countAssigned) ?></div>
          <div class="sub">Already assigned</div>
        </a>
        <a class="cb-card is-kind<?= $filter === 'news_category' ? ' is-active' : '' ?>" href="org_commerce_brands.php?filter=news_category<?= $qSuffix ?>">
          <div class="cb-card-top">
            <div style="display:flex;align-items:center;gap:8px;">
              <div class="cb-ico orange"><i class="fa fa-newspaper-o"></i></div>
              <div class="lab">News category</div>
            </div>
            <div class="delta">• review</div>
          </div>
          <div class="val"><?= number_format($countNews) ?></div>
          <div class="sub">Publisher · news</div>
        </a>
        <a class="cb-card is-kind<?= $filter === 'publisher' ? ' is-active' : '' ?>" href="org_commerce_brands.php?filter=publisher<?= $qSuffix ?>">
          <div class="cb-card-top">
            <div style="display:flex;align-items:center;gap:8px;">
              <div class="cb-ico blue"><i class="fa fa-bullhorn"></i></div>
              <div class="lab">Publisher</div>
            </div>
            <div class="delta">• orgs</div>
          </div>
          <div class="val"><?= number_format($countPublisher) ?></div>
          <div class="sub">All publisher orgs</div>
        </a>
        <a class="cb-card is-kind<?= $filter === 'all' ? ' is-active' : '' ?>" href="org_commerce_brands.php?filter=all<?= $qSuffix ?>">
          <div class="cb-card-top">
            <div style="display:flex;align-items:center;gap:8px;">
              <div class="cb-ico purple"><i class="fa fa-building"></i></div>
              <div class="lab">All orgs</div>
            </div>
            <div class="delta">• list</div>
          </div>
          <div class="val"><?= number_format($countAll) ?></div>
          <div class="sub">Migration pool</div>
        </a>
        <div class="cb-card">
          <div class="cb-card-top">
            <div style="display:flex;align-items:center;gap:8px;">
              <div class="cb-ico cyan"><i class="fa fa-tags"></i></div>
              <div class="lab">Brands</div>
            </div>
            <div class="delta">• active</div>
          </div>
          <div class="val"><?= number_format($brandCount) ?></div>
          <div class="sub"><?= (int)$suggestCount ?> match<?= $suggestCount === 1 ? '' : 'es' ?> ready</div>
        </div>
      </div>

      <nav class="cb-kinds" aria-label="Brand filter">
        <?php foreach ($tabMeta as $key => [$lab, $cnt]): ?>
          <a href="org_commerce_brands.php?filter=<?= rawurlencode($key) . $qSuffix ?>" class="<?= $filter === $key ? 'is-active' : '' ?>">
            <?= org_admin_h($lab) ?><span class="cnt">(<?= (int)$cnt ?>)</span>
          </a>
        <?php endforeach; ?>
      </nav>

      <div class="cb-note">
        <b>Auto-match</b> links orgs whose name matches a brand.
        <b>Manual assign</b> when the store name differs.
        Category becomes <code>commerce</code> on the org and linked publisher user.
      </div>

      <div class="cb-panel">
        <form class="cb-filters" method="get" action="org_commerce_brands.php" id="cbFilters">
          <div class="cb-search">
            <i class="fa fa-search"></i>
            <input type="search" name="q" value="<?= org_admin_h($search) ?>" placeholder="Search org, publisher, brand…" autocomplete="off">
          </div>
          <select name="filter" aria-label="Filter" onchange="this.form.submit()">
            <?php foreach ($tabMeta as $key => [$lab]): ?>
              <option value="<?= org_admin_h($key) ?>"<?= $filter === $key ? ' selected' : '' ?>><?= org_admin_h($lab) ?></option>
            <?php endforeach; ?>
          </select>
          <button type="submit" class="cb-btn sm primary">Search</button>
          <?php if ($search !== '' || $filter !== 'unassigned'): ?>
            <a class="cb-clear" href="org_commerce_brands.php"><i class="fa fa-refresh"></i> Clear</a>
          <?php endif; ?>
        </form>

        <?php if ($showBulk): ?>
        <form method="post" id="bulkMigrateForm" class="cb-bulk">
          <span class="lab">Bulk</span>
          <select name="bulk_brand_id" aria-label="Bulk brand">
            <option value="">Choose brand for selected…</option>
            <?php foreach ($brands as $brand): ?>
              <option value="<?= (int)($brand['id'] ?? 0) ?>"><?= org_admin_h((string)($brand['name'] ?? '')) ?></option>
            <?php endforeach; ?>
          </select>
          <button type="submit" name="bulk_migrate" class="cb-btn sm primary" onclick="return confirm('Apply this brand to all checked organizations?');">Apply selected</button>
          <button type="submit" name="auto_match" class="cb-btn sm" onclick="return confirm('Auto-match checked orgs by name?');">Auto-match selected</button>
          <?php if ($suggestCount > 0): ?>
            <button type="button" class="cb-btn sm" id="autoMatchAllVisibleBtn">Auto-match all visible (<?= (int)$suggestCount ?>)</button>
          <?php endif; ?>
        <?php endif; ?>

        <div class="cb-table-wrap">
          <table class="cb-table<?= $showBulk ? ' has-check' : '' ?>" id="cbTable">
            <thead>
              <tr>
                <?php if ($showBulk): ?><th><input type="checkbox" id="orgMigrateCheckAll" aria-label="Select all"></th><?php endif; ?>
                <th>Organization</th>
                <th>Publisher</th>
                <th>Category</th>
                <th>Current brand</th>
                <th>Suggested</th>
                <th>Assign brand</th>
              </tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
              <tr><td colspan="<?= (int)$colspan ?>"><div class="cb-empty">No organizations match this filter.</div></td></tr>
            <?php else: foreach ($rows as $row):
              $orgId = (int)($row['id'] ?? 0);
              $currentBrandId = (int)($row['commerce_brand_id'] ?? 0);
              $suggestedId = (int)($row['suggested_brand_id'] ?? 0);
              $pubCat = trim((string)($row['publisher_category'] ?? ''));
              $pubUserCat = trim((string)($row['pub_publisher_category'] ?? ''));
              $name = (string)($row['name'] ?? '');
              $code = (string)($row['org_code'] ?? '');
              $ini = cb_initials($name !== '' ? $name : $code);
              $bg = cb_avatar_color($code !== '' ? $code : $name);
            ?>
              <tr>
                <?php if ($showBulk): ?>
                <td>
                  <input type="checkbox" class="org-migrate-check" name="org_ids[]" value="<?= $orgId ?>" form="bulkMigrateForm">
                </td>
                <?php endif; ?>
                <td>
                  <div class="cb-user">
                    <span class="cb-av" style="background:<?= org_admin_h($bg) ?>;"><?= org_admin_h($ini) ?></span>
                    <div style="min-width:0;">
                      <div class="nm"><a href="orgdetail.php?id=<?= $orgId ?>"><?= org_admin_h($name) ?></a></div>
                      <div class="un"><?= org_admin_h($code) ?></div>
                    </div>
                  </div>
                </td>
                <td>
                  <?php if (!empty($row['pub_username'])): ?>
                    <div class="cb-cell"><?= org_admin_h((string)$row['pub_username']) ?></div>
                    <?php if (!empty($row['registered_publisher_name']) && strcasecmp((string)$row['registered_publisher_name'], (string)($row['pub_username'] ?? '')) !== 0): ?>
                      <div class="cb-when"><?= org_admin_h((string)$row['registered_publisher_name']) ?></div>
                    <?php endif; ?>
                  <?php else: ?>
                    <span class="cb-pill gray">—</span>
                  <?php endif; ?>
                </td>
                <td>
                  <div class="cb-cell"><?= org_admin_h($pubCat !== '' ? $pubCat : '—') ?></div>
                  <?php if ($pubUserCat !== '' && $pubUserCat !== $pubCat): ?>
                    <div class="cb-when">user: <?= org_admin_h($pubUserCat) ?></div>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if ($currentBrandId > 0): ?>
                    <span class="cb-pill ok"><?= org_admin_h((string)($row['commerce_brand_name'] ?? 'Assigned')) ?></span>
                  <?php else: ?>
                    <span class="cb-pill bad">None</span>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if ($suggestedId > 0): ?>
                    <span class="cb-pill blue"><?= org_admin_h((string)($row['suggested_brand_name'] ?? '')) ?></span>
                  <?php else: ?>
                    <span class="cb-pill gray">—</span>
                  <?php endif; ?>
                </td>
                <td>
                  <form method="post" class="cb-assign">
                    <input type="hidden" name="org_id" value="<?= $orgId ?>">
                    <select name="brand_id" required aria-label="Brand for <?= org_admin_h($name) ?>">
                      <option value="">Brand…</option>
                      <?php foreach ($brands as $brand):
                        $bid = (int)($brand['id'] ?? 0);
                      ?>
                        <option value="<?= $bid ?>"<?= ($currentBrandId === $bid || ($currentBrandId <= 0 && $suggestedId === $bid)) ? ' selected' : '' ?>><?= org_admin_h((string)($brand['name'] ?? '')) ?></option>
                      <?php endforeach; ?>
                    </select>
                    <button type="submit" name="migrate_org" class="cb-btn sm primary"><?= $currentBrandId > 0 ? 'Update' : 'Assign' ?></button>
                  </form>
                </td>
              </tr>
            <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>

        <?php if ($showBulk): ?>
        </form>
        <?php endif; ?>

        <div class="cb-foot">
          <span><?= (int)$total ?> org<?= $total === 1 ? '' : 's' ?><?php if ($suggestCount > 0): ?> · <?= (int)$suggestCount ?> auto-match ready<?php endif; ?></span>
          <span><?= org_admin_h($tabMeta[$filter][0] ?? ucfirst($filter)) ?><?= $search !== '' ? ' · “' . org_admin_h($search) . '”' : '' ?></span>
        </div>
      </div>

    </div>
  </div>
</div>

<script>
(function(){
  var master = document.getElementById('orgMigrateCheckAll');
  if (master) {
    master.addEventListener('change', function(){
      document.querySelectorAll('.org-migrate-check').forEach(function(box){
        box.checked = master.checked;
      });
    });
  }

  var autoAllBtn = document.getElementById('autoMatchAllVisibleBtn');
  var bulkForm = document.getElementById('bulkMigrateForm');
  if (autoAllBtn && bulkForm) {
    autoAllBtn.addEventListener('click', function(){
      if (!confirm('Auto-match ALL visible orgs with a name match?')) {
        return;
      }
      document.querySelectorAll('.org-migrate-check').forEach(function(box){
        box.checked = true;
      });
      var input = document.createElement('input');
      input.type = 'hidden';
      input.name = 'auto_match';
      input.value = '1';
      bulkForm.appendChild(input);
      bulkForm.submit();
    });
  }
})();
</script>

<?php org_admin_render_foot(); ?>
