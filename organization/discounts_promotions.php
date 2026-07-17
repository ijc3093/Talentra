<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/session_org.php';
require_once __DIR__ . '/includes/org_context.php';
require_once __DIR__ . '/includes/org_manager_guard.php';
require_once __DIR__ . '/includes/org_sales.php';

org_require_manager();

org_require_commerce_seller();
org_ecommerce_ensure_schema($dbh);

$orgId = (int)orgActiveOrgId();
$err = '';
$ok = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_promo'])) {
    $promos = org_sales_promotions($dbh, $orgId);
    $code = strtoupper(trim((string)($_POST['code'] ?? '')));
    $label = trim((string)($_POST['label'] ?? ''));
    $value = max(0, (float)($_POST['value'] ?? 0));
    $type = ($_POST['type'] ?? 'percent') === 'fixed' ? 'fixed' : 'percent';
    if ($code === '' || $label === '') {
        $err = 'Promotion code and label are required.';
    } else {
        $promos[] = [
            'code' => substr($code, 0, 40),
            'label' => substr($label, 0, 120),
            'type' => $type,
            'value' => $value,
            'starts_at' => trim((string)($_POST['starts_at'] ?? '')),
            'ends_at' => trim((string)($_POST['ends_at'] ?? '')),
            'status' => 'active',
        ];
        $ok = org_sales_save_promotions($dbh, $orgId, $promos) ? 'Promotion saved.' : 'Could not save promotion.';
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['archive_promo'])) {
    $idx = (int)($_POST['promo_index'] ?? -1);
    $promos = org_sales_promotions($dbh, $orgId);
    if (isset($promos[$idx])) {
        $promos[$idx]['status'] = 'archived';
        $ok = org_sales_save_promotions($dbh, $orgId, $promos) ? 'Promotion archived.' : 'Could not archive promotion.';
    }
}
$promos = org_sales_promotions($dbh, $orgId);
$pageTitle = 'Discounts & Promotions';
require_once __DIR__ . '/includes/org_page_shell.php';
org_page_shell_open($pageTitle, '<link rel="stylesheet" href="css/commerce-hub.css?v=14">');
?>
<?php org_page_body_open('commerce-page'); ?>
  <div class="mg-b-20"><a href="sales_management.php" class="tx-12">&larr; Sales management</a><h4 class="mg-b-0">Discounts &amp; Promotions</h4><p class="tx-color-03">Create seller coupons and promotional pricing notes for campaigns.</p></div>
  <?php if ($err): ?><div class="alert alert-danger"><?= org_ecommerce_h($err) ?></div><?php endif; ?>
  <?php if ($ok): ?><div class="alert alert-success"><?= org_ecommerce_h($ok) ?></div><?php endif; ?>
  <div class="card shadow-base mg-b-20"><div class="card-header"><h6 class="card-title tx-14 mg-b-0">New promotion</h6></div><div class="card-body"><form method="post"><input type="hidden" name="save_promo" value="1"><div class="row"><div class="col-md-2 form-group"><label>Code</label><input name="code" class="form-control" placeholder="SUMMER10" required></div><div class="col-md-4 form-group"><label>Label</label><input name="label" class="form-control" placeholder="Summer launch discount" required></div><div class="col-md-2 form-group"><label>Type</label><select name="type" class="form-control"><option value="percent">Percent</option><option value="fixed">Fixed amount</option></select></div><div class="col-md-2 form-group"><label>Value</label><input name="value" type="number" step="0.01" min="0" class="form-control"></div><div class="col-md-1 form-group"><label>Starts</label><input name="starts_at" type="date" class="form-control"></div><div class="col-md-1 form-group"><label>Ends</label><input name="ends_at" type="date" class="form-control"></div></div><button class="btn btn-primary">Save promotion</button></form></div></div>
  <div class="card shadow-base"><div class="card-header"><h6 class="card-title tx-14 mg-b-0">Promotion table</h6></div><div class="card-body pd-0 table-responsive"><table class="table table-hover mg-b-0"><thead><tr><th>Code</th><th>Label</th><th>Discount</th><th>Dates</th><th>Status</th><th></th></tr></thead><tbody><?php if (!$promos): ?><tr><td colspan="6" class="text-center tx-color-03">No promotions yet.</td></tr><?php endif; ?><?php foreach ($promos as $i => $p): ?><tr><td><code><?= org_ecommerce_h((string)($p['code'] ?? '')) ?></code></td><td><?= org_ecommerce_h((string)($p['label'] ?? '')) ?></td><td><?= org_ecommerce_h((string)($p['value'] ?? 0)) ?> <?= ($p['type'] ?? 'percent') === 'fixed' ? 'fixed' : '%' ?></td><td class="tx-12"><?= org_ecommerce_h((string)($p['starts_at'] ?? '')) ?> to <?= org_ecommerce_h((string)($p['ends_at'] ?? '')) ?></td><td><span class="badge <?= (($p['status'] ?? '') === 'active') ? 'badge-success' : 'badge-secondary' ?>"><?= org_ecommerce_h((string)($p['status'] ?? 'active')) ?></span></td><td><?php if (($p['status'] ?? 'active') === 'active'): ?><form method="post"><input type="hidden" name="archive_promo" value="1"><input type="hidden" name="promo_index" value="<?= (int)$i ?>"><button class="btn btn-xs btn-outline-secondary">Archive</button></form><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div></div>
</div>
<?php org_page_shell_close(); ?>
