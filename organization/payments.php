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
$totals = org_sales_payment_totals($dbh, $orgId);
$payments = org_sales_recent_payments($dbh, $orgId, 200);
$pageTitle = 'Payments';
require_once __DIR__ . '/includes/org_page_shell.php';
org_page_shell_open($pageTitle, '<link rel="stylesheet" href="css/commerce-hub.css?v=14">');
?>
<?php org_page_body_open('commerce-page'); ?>
  <div class="mg-b-20"><a href="sales_management.php" class="tx-12">&larr; Sales management</a><h4 class="mg-b-0">Payments</h4><p class="tx-color-03">Record payments by order status and track outstanding invoice balances.</p></div>
  <div class="commerce-kpi-grid">
    <div class="commerce-kpi"><div class="commerce-kpi-top"><span class="commerce-kpi-label">Collected</span><span class="commerce-kpi-icon"><i class="icon ion-cash"></i></span></div><div class="commerce-kpi-main"><div class="commerce-kpi-value"><?= org_ecommerce_h(org_sales_money((int)$totals['paid_cents'])) ?></div><div class="commerce-kpi-sub"><?= (int)$totals['payments_count'] ?> paid order payments</div></div></div>
    <div class="commerce-kpi"><div class="commerce-kpi-top"><span class="commerce-kpi-label">Outstanding</span><span class="commerce-kpi-icon"><i class="icon ion-card"></i></span></div><div class="commerce-kpi-main"><div class="commerce-kpi-value"><?= org_ecommerce_h(org_sales_money((int)$totals['outstanding_cents'])) ?></div><div class="commerce-kpi-sub"><?= (int)$totals['open_invoices'] ?> unpaid invoices</div></div></div>
  </div>
  <div class="card shadow-base">
    <div class="card-header d-flex justify-content-between align-items-center"><h6 class="card-title tx-14 mg-b-0">Payment ledger</h6><a href="invoices.php" class="btn btn-sm btn-outline-secondary">Invoice payments</a></div>
    <div class="card-body pd-0 table-responsive"><table class="table table-hover mg-b-0"><thead><tr><th>Source</th><th>Code</th><th>Customer</th><th>Amount</th><th>Status</th><th>Paid / created</th></tr></thead><tbody>
      <?php if (!$payments): ?><tr><td colspan="6" class="text-center tx-color-03">No collected payments yet.</td></tr><?php endif; ?>
      <?php foreach ($payments as $p): ?><tr><td><?= org_ecommerce_h((string)$p['source']) ?></td><td><a href="order_details.php?id=<?= (int)$p['id'] ?>"><code><?= org_ecommerce_h((string)$p['code']) ?></code></a></td><td><?= org_ecommerce_h((string)($p['customer'] ?: 'Guest')) ?></td><td><?= org_ecommerce_h(org_sales_money((int)$p['amount_cents'], (string)$p['currency'])) ?></td><td><span class="badge <?= org_sales_status_badge((string)$p['status']) ?>"><?= org_ecommerce_h((string)$p['status']) ?></span></td><td class="tx-12"><?= org_ecommerce_h((string)($p['paid_at'] ?: $p['created_at'])) ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
  </div>
</div>
<?php org_page_shell_close(); ?>
