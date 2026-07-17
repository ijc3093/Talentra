<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/session_org.php';
require_once __DIR__ . '/includes/org_context.php';
require_once __DIR__ . '/includes/org_manager_guard.php';
require_once __DIR__ . '/includes/org_sales.php';

org_require_manager();

org_require_commerce_seller();

$orgId = (int)orgActiveOrgId();
$memberId = (int)orgMemberId();
org_crm_lifecycle_ensure_schema($dbh);

$err = '';
$ok = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_quote'])) {
    $res = org_crm_save_quote($dbh, $orgId, $_POST, null, $memberId);
    if (!empty($res['ok'])) {
        $ok = 'Quotation saved.';
    } else {
        $err = (string)($res['error'] ?? 'Could not save quotation.');
    }
}

$quotes = org_crm_list_quotes($dbh, $orgId, (string)($_GET['status'] ?? 'all'), 200);
$contacts = org_crm_list_contacts($dbh, $orgId, 'all', '', 300);
$openValue = 0;
foreach ($quotes as $q) {
    if (in_array((string)$q['status'], ['draft', 'sent', 'pending_approval'], true)) {
        $openValue += (int)$q['amount_cents'];
    }
}

$pageTitle = 'Quotations';
require_once __DIR__ . '/includes/org_page_shell.php';
org_page_shell_open($pageTitle, '<link rel="stylesheet" href="css/commerce-hub.css?v=14">');
?>
<?php org_page_body_open('commerce-page'); ?>
  <div class="mg-b-20">
    <a href="sales_management.php" class="tx-12">&larr; Sales management</a>
    <h4 class="mg-b-0">Quotations</h4>
    <p class="tx-color-03">Create customer price quotes before the sale and convert approved quotes to invoices.</p>
  </div>
  <?php if ($err): ?><div class="alert alert-danger"><?= org_ecommerce_h($err) ?></div><?php endif; ?>
  <?php if ($ok): ?><div class="alert alert-success"><?= org_ecommerce_h($ok) ?></div><?php endif; ?>

  <div class="commerce-kpi-grid">
    <div class="commerce-kpi"><div class="commerce-kpi-top"><span class="commerce-kpi-label">Quotes</span><span class="commerce-kpi-icon"><i class="icon ion-document-text"></i></span></div><div class="commerce-kpi-main"><div class="commerce-kpi-value"><?= count($quotes) ?></div><div class="commerce-kpi-sub">All quote records</div></div></div>
    <div class="commerce-kpi"><div class="commerce-kpi-top"><span class="commerce-kpi-label">Open value</span><span class="commerce-kpi-icon"><i class="icon ion-cash"></i></span></div><div class="commerce-kpi-main"><div class="commerce-kpi-value"><?= org_ecommerce_h(org_sales_money($openValue)) ?></div><div class="commerce-kpi-sub">Draft, sent, pending approval</div></div></div>
  </div>

  <div class="card shadow-base mg-b-20">
    <div class="card-header"><h6 class="card-title tx-14 mg-b-0">New quotation</h6></div>
    <div class="card-body">
      <form method="post">
        <input type="hidden" name="save_quote" value="1">
        <div class="row">
          <div class="col-md-4 form-group"><label>Quote title *</label><input name="title" class="form-control" required></div>
          <div class="col-md-2 form-group"><label>Amount</label><input name="amount" type="number" step="0.01" min="0" class="form-control"></div>
          <div class="col-md-3 form-group"><label>Contact</label><select name="contact_id" class="form-control"><option value="0">No contact</option><?php foreach ($contacts as $c): ?><option value="<?= (int)$c['id'] ?>"><?= org_ecommerce_h((string)$c['full_name']) ?></option><?php endforeach; ?></select></div>
          <div class="col-md-2 form-group"><label>Valid until</label><input name="valid_until" type="date" class="form-control"></div>
          <div class="col-md-1 form-group"><label>Status</label><select name="status" class="form-control"><option value="draft">Draft</option><option value="sent">Sent</option></select></div>
          <div class="col-md-12 form-group"><label>Notes / terms</label><textarea name="notes" class="form-control" rows="2"></textarea></div>
        </div>
        <button class="btn btn-primary">Create quote</button>
      </form>
    </div>
  </div>

  <div class="card shadow-base">
    <div class="card-header d-flex justify-content-between align-items-center"><h6 class="card-title tx-14 mg-b-0">Quote table</h6><a href="crm_convert.php" class="btn btn-sm btn-outline-secondary">CRM convert</a></div>
    <div class="card-body pd-0 table-responsive">
      <table class="table table-hover mg-b-0">
        <thead><tr><th>Code</th><th>Customer</th><th>Quote</th><th>Amount</th><th>Valid until</th><th>Status</th><th></th></tr></thead>
        <tbody>
          <?php if (!$quotes): ?><tr><td colspan="7" class="text-center tx-color-03">No quotations yet.</td></tr><?php endif; ?>
          <?php foreach ($quotes as $q): ?>
          <tr>
            <td><code><?= org_ecommerce_h((string)$q['quote_code']) ?></code></td>
            <td><?= org_ecommerce_h((string)($q['contact_name'] ?? 'Walk-in / unassigned')) ?></td>
            <td><?= org_ecommerce_h((string)$q['title']) ?></td>
            <td><?= org_ecommerce_h(org_sales_money((int)$q['amount_cents'])) ?></td>
            <td><?= org_ecommerce_h((string)($q['valid_until'] ?? '')) ?></td>
            <td><span class="badge <?= org_sales_status_badge((string)$q['status']) ?>"><?= org_ecommerce_h((string)$q['status']) ?></span></td>
            <td><a href="quotation_details.php?id=<?= (int)$q['id'] ?>" class="btn btn-xs btn-outline-primary">Details</a></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php org_page_shell_close(); ?>
