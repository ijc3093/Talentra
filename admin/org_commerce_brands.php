<?php
declare(strict_types=1);

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

$brands = org_commerce_brands_list_active($dbh);
$rows = org_commerce_brands_list_orgs_for_migration($dbh, $filter, $search);
$total = count($rows);
$suggestCount = 0;
foreach ($rows as $row) {
    if ((int)($row['suggested_brand_id'] ?? 0) > 0 && (int)($row['commerce_brand_id'] ?? 0) <= 0) {
        $suggestCount++;
    }
}

org_admin_render_head('Commerce Brand Migration');
?>
<div class="sh-logopanel"><a href="" class="sh-logo-text">Talentra Admin</a></div>
<div class="sh-headpanel"></div>
<?php include __DIR__ . '/includes/leftbar.php'; ?>

<div class="sh-mainpanel">
  <div class="sh-pagetitle">
    <div class="sh-pagetitle-left">
      <div class="sh-pagetitle-icon"><i class="icon ion-ios-cart"></i></div>
      <div>
        <h2>Commerce brand migration</h2>
        <p class="mg-b-0">Link existing organizations to McDonald's, Wendy's, and other commerce systems.</p>
      </div>
    </div>
    <div class="sh-pagetitle-right">
      <a href="orglist.php" class="btn-mini">Organizations</a>
    </div>
  </div>

  <div class="sh-pagebody">
    <?php if ($msg !== ''): ?><div class="alert-lite ok"><?= org_admin_h($msg) ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="alert-lite bad"><?= org_admin_h($error) ?></div><?php endif; ?>

    <div class="card admin-card mg-b-20">
      <div class="card-header pro">
        How this works
        <div class="sub">One-time fix for orgs created before commerce brand signup existed (e.g. CNN/news instead of McDonald's).</div>
      </div>
      <div class="card-body" style="padding:16px 18px;font-size:14px;line-height:1.55;color:#475569;">
        <p style="margin:0 0 8px;"><strong>Auto-match by name</strong> links orgs whose name matches a commerce brand (McDonald's, Wendy's, etc.).</p>
        <p style="margin:0 0 8px;"><strong>Manual assign</strong> lets you pick the brand per org — use this when the store name differs from the brand (e.g. "Downtown Burgers" → McDonald's system).</p>
        <p style="margin:0;">Category is updated to <code>commerce</code> on the organization and linked publisher user.</p>
      </div>
    </div>

    <div class="card admin-card">
      <div class="pro-tools">
        <div class="filter-tabs">
          <?php
            $tabs = [
              'unassigned' => 'No brand',
              'news_category' => 'News category',
              'assigned' => 'Has brand',
              'publisher' => 'All publisher orgs',
              'all' => 'All orgs',
            ];
            foreach ($tabs as $key => $label):
              $href = 'org_commerce_brands.php?filter=' . rawurlencode($key) . ($search !== '' ? '&q=' . rawurlencode($search) : '');
          ?>
            <a href="<?= org_admin_h($href) ?>" class="<?= $filter === $key ? 'is-active' : '' ?>"><?= org_admin_h($label) ?></a>
          <?php endforeach; ?>
        </div>
        <div class="sub"><?= (int)$total ?> org<?= $total === 1 ? '' : 's' ?><?php if ($suggestCount > 0): ?> · <?= (int)$suggestCount ?> auto-match<?= $suggestCount === 1 ? '' : 'es' ?> ready<?php endif; ?></div>
        <form class="search-form" method="get" action="org_commerce_brands.php">
          <input type="hidden" name="filter" value="<?= org_admin_h($filter) ?>">
          <input type="text" name="q" value="<?= org_admin_h($search) ?>" placeholder="Search org, publisher, brand…">
          <button type="submit" class="btn-mini primary">Search</button>
          <?php if ($search !== ''): ?>
            <a class="btn-mini" href="org_commerce_brands.php?filter=<?= rawurlencode($filter) ?>">Clear</a>
          <?php endif; ?>
        </form>
      </div>

      <?php if ($rows && $filter !== 'assigned'): ?>
      <form method="post" id="bulkMigrateForm" class="pro-tools" style="border-top:1px solid #e5e7eb;padding-top:12px;">
        <strong>Bulk actions</strong>
        <select name="bulk_brand_id" class="form-control" style="max-width:280px;display:inline-block;width:auto;min-height:34px;">
          <option value="">Choose brand for selected orgs</option>
          <?php foreach ($brands as $brand): ?>
            <option value="<?= (int)($brand['id'] ?? 0) ?>"><?= org_admin_h((string)($brand['name'] ?? '')) ?></option>
          <?php endforeach; ?>
        </select>
        <button type="submit" name="bulk_migrate" class="btn-mini primary" onclick="return confirm('Apply this brand to all checked organizations?');">Apply brand to selected</button>
        <button type="submit" name="auto_match" class="btn-mini" onclick="return confirm('Auto-match checked orgs by name (only where a brand name matches)?');">Auto-match selected</button>
        <?php if ($suggestCount > 0): ?>
          <button type="button" class="btn-mini" id="autoMatchAllVisibleBtn">Auto-match all visible (<?= (int)$suggestCount ?>)</button>
        <?php endif; ?>
      <?php endif; ?>

      <div class="card-body-fixed">
        <div class="table-scroll">
          <table class="table admin-table">
            <thead>
              <tr>
                <?php if ($filter !== 'assigned'): ?><th style="width:36px;"><input type="checkbox" id="orgMigrateCheckAll" aria-label="Select all"></th><?php endif; ?>
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
              <tr><td colspan="7" class="text-center muted" style="padding:28px;">No organizations match this filter.</td></tr>
            <?php else: foreach ($rows as $row): ?>
              <?php
                $orgId = (int)($row['id'] ?? 0);
                $currentBrandId = (int)($row['commerce_brand_id'] ?? 0);
                $suggestedId = (int)($row['suggested_brand_id'] ?? 0);
                $pubCat = trim((string)($row['publisher_category'] ?? ''));
                $pubUserCat = trim((string)($row['pub_publisher_category'] ?? ''));
              ?>
              <tr>
                <?php if ($filter !== 'assigned'): ?>
                <td>
                  <input type="checkbox" class="org-migrate-check" name="org_ids[]" value="<?= $orgId ?>" form="bulkMigrateForm">
                </td>
                <?php endif; ?>
                <td>
                  <div><strong><a href="orgdetail.php?id=<?= $orgId ?>"><?= org_admin_h($row['name'] ?? '') ?></a></strong></div>
                  <div class="muted"><?= org_admin_h($row['org_code'] ?? '') ?></div>
                </td>
                <td>
                  <?php if (!empty($row['pub_username'])): ?>
                    <div><?= org_admin_h($row['pub_username']) ?></div>
                    <?php if (!empty($row['registered_publisher_name']) && strcasecmp((string)$row['registered_publisher_name'], (string)($row['pub_username'] ?? '')) !== 0): ?>
                      <div class="muted"><?= org_admin_h($row['registered_publisher_name']) ?></div>
                    <?php endif; ?>
                  <?php else: ?>
                    <span class="muted">—</span>
                  <?php endif; ?>
                </td>
                <td>
                  <div><?= org_admin_h($pubCat !== '' ? $pubCat : '—') ?></div>
                  <?php if ($pubUserCat !== '' && $pubUserCat !== $pubCat): ?>
                    <div class="muted">user: <?= org_admin_h($pubUserCat) ?></div>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if ($currentBrandId > 0): ?>
                    <span class="pill ok"><?= org_admin_h($row['commerce_brand_name'] ?? 'Assigned') ?></span>
                  <?php else: ?>
                    <span class="pill bad">None</span>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if ($suggestedId > 0): ?>
                    <span class="pill info"><?= org_admin_h($row['suggested_brand_name'] ?? '') ?></span>
                  <?php else: ?>
                    <span class="muted">—</span>
                  <?php endif; ?>
                </td>
                <td>
                  <form method="post" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;">
                    <input type="hidden" name="org_id" value="<?= $orgId ?>">
                    <select name="brand_id" class="form-control" style="min-width:160px;max-width:200px;min-height:32px;height:32px;padding:2px 8px;font-size:13px;" required>
                      <option value="">Brand…</option>
                      <?php foreach ($brands as $brand): ?>
                        <?php $bid = (int)($brand['id'] ?? 0); ?>
                        <option value="<?= $bid ?>"<?= ($currentBrandId === $bid || ($currentBrandId <= 0 && $suggestedId === $bid)) ? ' selected' : '' ?>><?= org_admin_h((string)($brand['name'] ?? '')) ?></option>
                      <?php endforeach; ?>
                    </select>
                    <button type="submit" name="migrate_org" class="btn-mini primary"><?= $currentBrandId > 0 ? 'Update' : 'Assign' ?></button>
                  </form>
                </td>
              </tr>
            <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <?php if ($rows && $filter !== 'assigned'): ?>
      </form>
      <?php endif; ?>
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
