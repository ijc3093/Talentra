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
$id = (int)($_GET['id'] ?? 0);
org_crm_lifecycle_ensure_schema($dbh);

$err = '';
$ok = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_quote'])) {
    $res = org_crm_save_quote($dbh, $orgId, $_POST, $id, $memberId);
    if (!empty($res['ok'])) {
        $ok = 'Quotation updated.';
    } else {
        $err = (string)($res['error'] ?? 'Update failed.');
    }
}

$quote = null;
foreach (org_crm_list_quotes($dbh, $orgId, 'all', 200) as $q) {
    if ((int)$q['id'] === $id) {
        $quote = $q;
        break;
    }
}
if (!$quote) {
    header('Location: quotations.php');
    exit;
}

$pageTitle = 'Quotation details';
require_once __DIR__ . '/includes/org_page_shell.php';
org_page_shell_open($pageTitle, '<link rel="stylesheet" href="css/commerce-hub.css?v=14">');
?>
<?php org_page_body_open('commerce-page'); ?>
  <div class="mg-b-20">
    <a href="quotations.php" class="tx-12">&larr; Quotations</a>
    <h4 class="mg-b-0"><?= org_ecommerce_h((string)$quote['quote_code']) ?></h4>
    <p class="tx-color-03">Customer quote, amount, validity, approval status, and conversion path.</p>
  </div>
  <?php if ($err): ?><div class="alert alert-danger"><?= org_ecommerce_h($err) ?></div><?php endif; ?>
  <?php if ($ok): ?><div class="alert alert-success"><?= org_ecommerce_h($ok) ?></div><?php endif; ?>

  <div class="row row-sm">
    <div class="col-lg-7">
      <div class="card shadow-base">
        <div class="card-header"><h6 class="card-title tx-14 mg-b-0">Quote details</h6></div>
        <div class="card-body">
          <form method="post">
            <input type="hidden" name="update_quote" value="1">
            <div class="form-group"><label>Title</label><input name="title" class="form-control" value="<?= org_ecommerce_h((string)$quote['title']) ?>" required></div>
            <div class="row">
              <div class="col-md-4 form-group"><label>Amount</label><input name="amount" type="number" step="0.01" class="form-control" value="<?= number_format((int)$quote['amount_cents'] / 100, 2, '.', '') ?>"></div>
              <div class="col-md-4 form-group"><label>Valid until</label><input name="valid_until" type="date" class="form-control" value="<?= org_ecommerce_h((string)($quote['valid_until'] ?? '')) ?>"></div>
              <div class="col-md-4 form-group"><label>Status</label><select name="status" class="form-control"><?php foreach (['draft','sent','pending_approval','approved','rejected','expired'] as $s): ?><option value="<?= $s ?>" <?= (string)$quote['status'] === $s ? 'selected' : '' ?>><?= ucfirst(str_replace('_', ' ', $s)) ?></option><?php endforeach; ?></select></div>
            </div>
            <input type="hidden" name="contact_id" value="<?= (int)($quote['contact_id'] ?? 0) ?>">
            <div class="form-group"><label>Notes / terms</label><textarea name="notes" class="form-control" rows="5"><?= org_ecommerce_h((string)($quote['notes'] ?? '')) ?></textarea></div>
            <button class="btn btn-primary">Save quote</button>
            <a href="crm_invoices.php?from_quote=<?= (int)$quote['id'] ?>" class="btn btn-outline-secondary">Create invoice</a>
          </form>
        </div>
      </div>
    </div>
    <div class="col-lg-5">
      <div class="commerce-panel">
        <div class="commerce-panel-head"><h2>Quote summary</h2><span class="badge <?= org_sales_status_badge((string)$quote['status']) ?>"><?= org_ecommerce_h((string)$quote['status']) ?></span></div>
        <p><strong>Customer:</strong> <?= org_ecommerce_h((string)($quote['contact_name'] ?? 'Unassigned')) ?></p>
        <p><strong>Amount:</strong> <?= org_ecommerce_h(org_sales_money((int)$quote['amount_cents'])) ?></p>
        <p><strong>Approved:</strong> <?= !empty($quote['approved_at']) ? org_ecommerce_h((string)$quote['approved_at']) : 'Not approved yet' ?></p>
      </div>
    </div>
  </div>
</div>
<?php org_page_shell_close(); ?>
