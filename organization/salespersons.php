<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/session_org.php';
require_once __DIR__ . '/includes/org_context.php';
require_once __DIR__ . '/includes/org_manager_guard.php';
require_once __DIR__ . '/includes/org_sales.php';

org_require_manager();

org_require_commerce_seller();
$orgId = (int)orgActiveOrgId();
$people = org_sales_salesperson_performance($dbh, $orgId);
$pageTitle = 'Salespersons';
require_once __DIR__ . '/includes/org_page_shell.php';
org_page_shell_open($pageTitle, '<link rel="stylesheet" href="css/commerce-hub.css?v=14">');
?>
<?php org_page_body_open('commerce-page'); ?>
  <div class="mg-b-20"><a href="sales_management.php" class="tx-12">&larr; Sales management</a><h4 class="mg-b-0">Salespersons</h4><p class="tx-color-03">Manage sales staff and review order assignment performance.</p></div>
  <div class="card shadow-base"><div class="card-header d-flex justify-content-between align-items-center"><h6 class="card-title tx-14 mg-b-0">Sales team performance</h6><a href="members.php" class="btn btn-sm btn-outline-secondary">Manage team</a></div><div class="card-body pd-0 table-responsive"><table class="table table-hover mg-b-0"><thead><tr><th>Name</th><th>Role</th><th>Email</th><th>Assigned orders</th><th>Open orders</th><th>Revenue</th></tr></thead><tbody><?php if (!$people): ?><tr><td colspan="6" class="text-center tx-color-03">No team members found.</td></tr><?php endif; ?><?php foreach ($people as $p): ?><tr><td><?= org_ecommerce_h((string)$p['name']) ?></td><td><?= org_ecommerce_h((string)$p['member_type']) ?></td><td><?= org_ecommerce_h((string)$p['email']) ?></td><td><?= (int)$p['assigned_orders'] ?></td><td><?= (int)$p['open_orders'] ?></td><td><?= org_ecommerce_h(org_sales_money((int)$p['revenue_cents'])) ?></td></tr><?php endforeach; ?></tbody></table></div></div>
</div>
<?php org_page_shell_close(); ?>
