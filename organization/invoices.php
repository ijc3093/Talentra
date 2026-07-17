<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/session_org.php';
require_once __DIR__ . '/includes/org_context.php';
require_once __DIR__ . '/includes/org_manager_guard.php';
require_once __DIR__ . '/includes/org_sales.php';

org_require_manager();

org_require_commerce_seller();

$orgId = (int)orgActiveOrgId();
org_crm_lifecycle_ensure_schema($dbh);
$invoices = org_crm_list_invoices($dbh, $orgId);
$outstanding = 0;
$paid = 0;
foreach ($invoices as $inv) {
    if (in_array((string)$inv['status'], ['sent', 'overdue'], true)) $outstanding += (int)$inv['amount_cents'];
    if ((string)$inv['status'] === 'paid') $paid += (int)$inv['amount_cents'];
}
$pageTitle = 'Invoices';
require_once __DIR__ . '/includes/org_page_shell.php';
org_page_shell_open($pageTitle, '<link rel="stylesheet" href="css/commerce-hub.css?v=14">');
?>
<?php org_page_body_open('commerce-page'); ?>
  <div class="mg-b-20"><a href="sales_management.php" class="tx-12">&larr; Sales management</a><h4 class="mg-b-0">Invoices</h4><p class="tx-color-03">Generate invoices for completed sales and track invoice payment status.</p></div>
  <div class="commerce-kpi-grid">
    <div class="commerce-kpi"><div class="commerce-kpi-top"><span class="commerce-kpi-label">Invoices</span><span class="commerce-kpi-icon"><i class="icon ion-card"></i></span></div><div class="commerce-kpi-main"><div class="commerce-kpi-value"><?= count($invoices) ?></div><div class="commerce-kpi-sub">All invoice records</div></div></div>
    <div class="commerce-kpi"><div class="commerce-kpi-top"><span class="commerce-kpi-label">Outstanding</span><span class="commerce-kpi-icon"><i class="icon ion-alert-circled"></i></span></div><div class="commerce-kpi-main"><div class="commerce-kpi-value"><?= org_ecommerce_h(org_sales_money($outstanding)) ?></div><div class="commerce-kpi-sub">Sent and overdue</div></div></div>
    <div class="commerce-kpi"><div class="commerce-kpi-top"><span class="commerce-kpi-label">Paid</span><span class="commerce-kpi-icon"><i class="icon ion-checkmark-round"></i></span></div><div class="commerce-kpi-main"><div class="commerce-kpi-value"><?= org_ecommerce_h(org_sales_money($paid)) ?></div><div class="commerce-kpi-sub">Closed invoices</div></div></div>
  </div>
  <div class="card shadow-base">
    <div class="card-header d-flex justify-content-between align-items-center"><h6 class="card-title tx-14 mg-b-0">Invoice table</h6><a href="crm_invoices.php" class="btn btn-sm btn-primary">Create invoice</a></div>
    <div class="card-body pd-0 table-responsive"><table class="table table-hover mg-b-0"><thead><tr><th>Code</th><th>Customer</th><th>Title</th><th>Amount</th><th>Due</th><th>Status</th><th></th></tr></thead><tbody>
      <?php if (!$invoices): ?><tr><td colspan="7" class="text-center tx-color-03">No invoices yet.</td></tr><?php endif; ?>
      <?php foreach ($invoices as $inv): ?><tr>
        <td><code><?= org_ecommerce_h((string)$inv['invoice_code']) ?></code></td>
        <td><?= org_ecommerce_h((string)($inv['contact_name'] ?? 'Unassigned')) ?></td>
        <td><?= org_ecommerce_h((string)$inv['title']) ?></td>
        <td><?= org_ecommerce_h(org_sales_money((int)$inv['amount_cents'])) ?></td>
        <td><?= org_ecommerce_h((string)($inv['due_date'] ?? '')) ?></td>
        <td><span class="badge <?= org_sales_status_badge((string)$inv['status']) ?>"><?= org_ecommerce_h((string)$inv['status']) ?></span></td>
        <td><a href="invoice_details.php?id=<?= (int)$inv['id'] ?>" class="btn btn-xs btn-outline-primary">Details</a></td>
      </tr><?php endforeach; ?>
    </tbody></table></div>
  </div>
</div>
<?php org_page_shell_close(); ?>
