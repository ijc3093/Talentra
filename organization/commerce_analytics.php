<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/session_org.php';
require_once __DIR__ . '/includes/org_context.php';
require_once __DIR__ . '/includes/org_ecommerce.php';
require_once __DIR__ . '/includes/org_manager_guard.php';

org_require_manager();

$orgId = (int)orgActiveOrgId();
org_ecommerce_ensure_schema($dbh);

$stats = org_ecommerce_dashboard_stats($dbh, $orgId);
$crmStats = org_crm_dashboard_stats($dbh, $orgId);
$byStatus = org_ecommerce_orders_by_status($dbh, $orgId);
$topProducts = org_ecommerce_top_products($dbh, $orgId, 10);
$lowStock = org_ecommerce_low_stock_products($dbh, $orgId, 10);

$pageTitle = 'Commerce analytics';
require_once __DIR__ . '/includes/org_page_shell.php';
org_page_shell_open($pageTitle);
?>
<div class="sh-pagebody">
  <div class="mg-b-20">
    <a href="commerce.php" class="tx-12">&larr; Commerce hub</a>
    <h4 class="mg-b-0">Sales analytics</h4>
    <p class="tx-color-03">Revenue, inventory, and customer insights for data-driven decisions.</p>
  </div>

  <div class="row row-sm mg-b-20">
    <div class="col-md-3 col-6 mg-b-15">
      <div class="card shadow-base"><div class="card-body">
        <div class="tx-10 tx-uppercase tx-color-03">Revenue MTD</div>
        <div class="tx-24 tx-bold"><?= org_ecommerce_h(org_shop_format_price((int)$stats['revenue_mtd_cents'])) ?></div>
      </div></div>
    </div>
    <div class="col-md-3 col-6 mg-b-15">
      <div class="card shadow-base"><div class="card-body">
        <div class="tx-10 tx-uppercase tx-color-03">Avg order value</div>
        <div class="tx-24 tx-bold"><?= org_ecommerce_h(org_shop_format_price((int)$stats['avg_order_cents'])) ?></div>
      </div></div>
    </div>
    <div class="col-md-3 col-6 mg-b-15">
      <div class="card shadow-base"><div class="card-body">
        <div class="tx-10 tx-uppercase tx-color-03">Pipeline</div>
        <div class="tx-20 tx-bold"><?= org_ecommerce_h(org_crm_money((int)$crmStats['pipeline_cents'])) ?></div>
      </div></div>
    </div>
    <div class="col-md-3 col-6 mg-b-15">
      <div class="card shadow-base"><div class="card-body">
        <div class="tx-10 tx-uppercase tx-color-03">CRM contacts</div>
        <div class="tx-24 tx-bold"><?= (int)$crmStats['contacts'] ?></div>
        <div class="tx-12 tx-color-03"><?= (int)$crmStats['leads'] ?> leads</div>
      </div></div>
    </div>
  </div>

  <div class="row row-sm">
    <div class="col-lg-6 mg-b-20">
      <div class="card shadow-base">
        <div class="card-header"><h6 class="card-title tx-14 mg-b-0">Orders by status</h6></div>
        <div class="card-body pd-0">
          <table class="table table-hover mg-b-0">
            <thead><tr><th>Status</th><th>Count</th><th>Revenue</th></tr></thead>
            <tbody>
            <?php if (!$byStatus): ?>
              <tr><td colspan="3" class="text-center tx-color-03">No order data.</td></tr>
            <?php else: foreach ($byStatus as $row): ?>
              <tr>
                <td><span class="badge badge-light"><?= org_ecommerce_h((string)$row['status']) ?></span></td>
                <td><?= (int)$row['cnt'] ?></td>
                <td><?= org_ecommerce_h(org_shop_format_price((int)$row['total_cents'])) ?></td>
              </tr>
            <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <div class="col-lg-6 mg-b-20">
      <div class="card shadow-base">
        <div class="card-header"><h6 class="card-title tx-14 mg-b-0">Top products</h6></div>
        <div class="card-body pd-0">
          <table class="table table-hover mg-b-0">
            <thead><tr><th>Product</th><th>Orders</th><th>Revenue</th></tr></thead>
            <tbody>
            <?php if (!$topProducts): ?>
              <tr><td colspan="3" class="text-center tx-color-03">No sales yet.</td></tr>
            <?php else: foreach ($topProducts as $p): ?>
              <tr>
                <td><?= org_ecommerce_h((string)$p['product_title']) ?></td>
                <td><?= (int)$p['order_count'] ?></td>
                <td><?= org_ecommerce_h(org_shop_format_price((int)$p['revenue_cents'])) ?></td>
              </tr>
            <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <?php if ($lowStock): ?>
    <div class="col-lg-12 mg-b-20">
      <div class="card shadow-base">
        <div class="card-header"><h6 class="card-title tx-14 mg-b-0">Inventory alerts</h6></div>
        <div class="card-body pd-0">
          <table class="table table-hover mg-b-0">
            <thead><tr><th>Product</th><th>SKU</th><th>Stock</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($lowStock as $p): ?>
              <tr>
                <td><?= org_ecommerce_h((string)$p['title']) ?></td>
                <td><?= org_ecommerce_h((string)($p['sku'] ?? '—')) ?></td>
                <td class="text-danger"><?= (int)$p['stock_qty'] ?></td>
                <td><a href="products.php?edit=<?= (int)$p['id'] ?>" class="btn btn-xs btn-outline-primary">Restock</a></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php org_page_shell_close(); ?>
