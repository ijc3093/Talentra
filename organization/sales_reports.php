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
$stats = org_ecommerce_dashboard_stats($dbh, $orgId);
$byStatus = org_ecommerce_orders_by_status($dbh, $orgId);
$topProducts = org_ecommerce_top_products($dbh, $orgId, 12);
$lowStock = org_ecommerce_low_stock_products($dbh, $orgId, 12);
$pageTitle = 'Sales Reports';
require_once __DIR__ . '/includes/org_page_shell.php';
org_page_shell_open($pageTitle, '<link rel="stylesheet" href="css/commerce-hub.css?v=14">');
?>
<?php org_page_body_open('commerce-page'); ?>
  <div class="mg-b-20"><a href="sales_management.php" class="tx-12">&larr; Sales management</a><h4 class="mg-b-0">Sales reports</h4><p class="tx-color-03">Analyze revenue, top-selling products, sales status mix, and inventory risks.</p></div>
  <div class="commerce-kpi-grid">
    <div class="commerce-kpi"><div class="commerce-kpi-top"><span class="commerce-kpi-label">Revenue MTD</span><span class="commerce-kpi-icon"><i class="icon ion-cash"></i></span></div><div class="commerce-kpi-main"><div class="commerce-kpi-value"><?= org_ecommerce_h(org_sales_money((int)$stats['revenue_mtd_cents'])) ?></div><div class="commerce-kpi-sub"><?= (int)$stats['orders_mtd'] ?> monthly orders</div></div></div>
    <div class="commerce-kpi"><div class="commerce-kpi-top"><span class="commerce-kpi-label">Average order</span><span class="commerce-kpi-icon"><i class="icon ion-stats-bars"></i></span></div><div class="commerce-kpi-main"><div class="commerce-kpi-value"><?= org_ecommerce_h(org_sales_money((int)$stats['avg_order_cents'])) ?></div><div class="commerce-kpi-sub">Current month</div></div></div>
  </div>
  <div class="row row-sm">
    <div class="col-lg-6"><div class="card shadow-base mg-b-20"><div class="card-header"><h6 class="card-title tx-14 mg-b-0">Orders by status</h6></div><div class="card-body pd-0"><table class="table mg-b-0"><thead><tr><th>Status</th><th>Orders</th><th>Revenue</th></tr></thead><tbody><?php foreach ($byStatus as $r): ?><tr><td><span class="badge <?= org_sales_status_badge((string)$r['status']) ?>"><?= org_ecommerce_h((string)$r['status']) ?></span></td><td><?= (int)$r['cnt'] ?></td><td><?= org_ecommerce_h(org_sales_money((int)$r['total_cents'])) ?></td></tr><?php endforeach; ?></tbody></table></div></div></div>
    <div class="col-lg-6"><div class="card shadow-base mg-b-20"><div class="card-header"><h6 class="card-title tx-14 mg-b-0">Top products</h6></div><div class="card-body pd-0"><table class="table mg-b-0"><thead><tr><th>Product</th><th>Orders</th><th>Revenue</th></tr></thead><tbody><?php if (!$topProducts): ?><tr><td colspan="3" class="text-center tx-color-03">No sales yet.</td></tr><?php endif; ?><?php foreach ($topProducts as $p): ?><tr><td><?= org_ecommerce_h((string)$p['product_title']) ?></td><td><?= (int)$p['order_count'] ?></td><td><?= org_ecommerce_h(org_sales_money((int)$p['revenue_cents'])) ?></td></tr><?php endforeach; ?></tbody></table></div></div></div>
  </div>
  <div class="card shadow-base"><div class="card-header d-flex justify-content-between align-items-center"><h6 class="card-title tx-14 mg-b-0">Low-stock selling risk</h6><a href="commerce_analytics.php" class="btn btn-sm btn-outline-secondary">Analytics hub</a></div><div class="card-body pd-0"><table class="table mg-b-0"><thead><tr><th>Product</th><th>SKU</th><th>Stock</th><th>Status</th></tr></thead><tbody><?php if (!$lowStock): ?><tr><td colspan="4" class="text-center tx-color-03">No low-stock products.</td></tr><?php endif; ?><?php foreach ($lowStock as $p): ?><tr><td><?= org_ecommerce_h((string)$p['title']) ?></td><td><?= org_ecommerce_h((string)($p['sku'] ?? '')) ?></td><td><?= (int)$p['stock_qty'] ?></td><td><?= org_ecommerce_h((string)$p['status']) ?></td></tr><?php endforeach; ?></tbody></table></div></div>
</div>
<?php org_page_shell_close(); ?>
