<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/session_org.php';
require_once __DIR__ . '/includes/org_context.php';
require_once __DIR__ . '/includes/org_manager_guard.php';
org_require_manager();

org_require_commerce_seller();
require_once __DIR__ . '/includes/org_crm_lifecycle.php';

$orgId = (int)orgActiveOrgId();
$memberId = (int)orgMemberId();
org_crm_lifecycle_ensure_schema($dbh);

$err = '';
$ok = '';
$fromQuoteId = (int)($_GET['from_quote'] ?? 0);
$prefill = ['title' => '', 'amount' => '', 'contact_id' => 0, 'quote_id' => 0];
if ($fromQuoteId > 0) {
    foreach (org_crm_list_quotes($dbh, $orgId) as $q) {
        if ((int)$q['id'] === $fromQuoteId) {
            $prefill = [
                'title' => 'Invoice for ' . (string)$q['title'],
                'amount' => number_format((int)$q['amount_cents'] / 100, 2, '.', ''),
                'contact_id' => (int)($q['contact_id'] ?? 0),
                'quote_id' => $fromQuoteId,
            ];
            break;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_invoice'])) {
    $iid = (int)($_POST['invoice_id'] ?? 0);
    $res = org_crm_save_invoice($dbh, $orgId, $_POST, $iid > 0 ? $iid : null, $memberId);
    if (!empty($res['ok'])) {
        $ok = 'Invoice saved.';
    } else {
        $err = (string)($res['error'] ?? 'Save failed.');
    }
}

$invoices = org_crm_list_invoices($dbh, $orgId);
$contacts = org_crm_list_contacts($dbh, $orgId, 'all', '', 300);
$unpaidTotal = 0;
$paidMtd = 0;
foreach ($invoices as $inv) {
    if (in_array($inv['status'] ?? '', ['sent', 'overdue'], true)) {
        $unpaidTotal += (int)$inv['amount_cents'];
    }
    if (($inv['status'] ?? '') === 'paid') {
        $paidMtd += (int)$inv['amount_cents'];
    }
}

$pageTitle = 'Collect — Invoices';
require_once __DIR__ . '/includes/org_page_shell.php';
org_page_shell_open($pageTitle);
?>
<?php org_page_body_open(); ?>
  <div class="mg-b-20">
    <a href="crm.php" class="tx-12">&larr; CRM lifecycle</a>
    <h4 class="mg-b-0">Collect</h4>
    <p class="tx-color-03">Invoices, statements, and payment tracking. Card payments via Stripe on shop orders.</p>
  </div>

  <?php if ($err): ?><div class="alert alert-danger"><?= org_crm_h($err) ?></div><?php endif; ?>
  <?php if ($ok): ?><div class="alert alert-success"><?= org_crm_h($ok) ?></div><?php endif; ?>

  <div class="row row-sm mg-b-20">
    <div class="col-md-4"><div class="card shadow-base"><div class="card-body">
      <div class="tx-10 tx-uppercase tx-color-03">Outstanding</div>
      <div class="tx-24 tx-bold"><?= org_crm_h(org_crm_money($unpaidTotal)) ?></div>
    </div></div></div>
    <div class="col-md-4"><div class="card shadow-base"><div class="card-body">
      <div class="tx-10 tx-uppercase tx-color-03">Invoices</div>
      <div class="tx-24 tx-bold"><?= count($invoices) ?></div>
    </div></div></div>
    <div class="col-md-4"><div class="card shadow-base"><div class="card-body">
      <div class="tx-10 tx-uppercase tx-color-03">Shop orders</div>
      <a href="orders.php" class="tx-12">View OMS + Stripe receipts</a>
    </div></div></div>
  </div>

  <div class="card shadow-base mg-b-20">
    <div class="card-header"><h6 class="card-title tx-14 mg-b-0">New invoice</h6></div>
    <div class="card-body">
      <form method="post">
        <input type="hidden" name="save_invoice" value="1">
        <input type="hidden" name="quote_id" value="<?= (int)$prefill['quote_id'] ?>">
        <div class="row">
          <div class="col-md-5 form-group"><label>Title *</label><input name="title" class="form-control" required value="<?= org_crm_h($prefill['title']) ?>"></div>
          <div class="col-md-3 form-group"><label>Amount (USD)</label><input name="amount" type="number" step="0.01" min="0" class="form-control" value="<?= org_crm_h($prefill['amount']) ?>"></div>
          <div class="col-md-4 form-group"><label>Contact</label>
            <select name="contact_id" class="form-control">
              <option value="0">— None —</option>
              <?php foreach ($contacts as $c): ?>
                <option value="<?= (int)$c['id'] ?>" <?= (int)$prefill['contact_id'] === (int)$c['id'] ? 'selected' : '' ?>><?= org_crm_h((string)$c['full_name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-3 form-group"><label>Due date</label><input name="due_date" type="date" class="form-control"></div>
          <div class="col-md-3 form-group"><label>Status</label>
            <select name="status" class="form-control">
              <?php foreach (['draft','sent','paid','overdue'] as $s): ?>
                <option value="<?= $s ?>"><?= ucfirst($s) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-12 form-group"><label>Notes</label><textarea name="notes" class="form-control" rows="2"></textarea></div>
        </div>
        <button type="submit" class="btn btn-primary">Create invoice</button>
      </form>
    </div>
  </div>

  <div class="card shadow-base">
    <div class="card-header"><h6 class="card-title tx-14 mg-b-0">Invoice list (statements)</h6></div>
    <div class="card-body pd-0">
      <table class="table table-hover mg-b-0">
        <thead><tr><th>Code</th><th>Title</th><th>Contact</th><th>Amount</th><th>Due</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php if (!$invoices): ?>
          <tr><td colspan="7" class="text-center tx-color-03">No invoices yet.</td></tr>
        <?php else: foreach ($invoices as $inv): ?>
          <tr>
            <td><code><?= org_crm_h((string)$inv['invoice_code']) ?></code></td>
            <td><?= org_crm_h((string)$inv['title']) ?></td>
            <td><?php if (!empty($inv['contact_id'])): ?><a href="crm_contact.php?id=<?= (int)$inv['contact_id'] ?>"><?= org_crm_h((string)($inv['contact_name'] ?? '')) ?></a><?php else: ?>—<?php endif; ?></td>
            <td><?= org_crm_h(org_crm_money((int)$inv['amount_cents'])) ?></td>
            <td class="tx-12"><?= org_crm_h((string)($inv['due_date'] ?? '—')) ?></td>
            <td><span class="badge <?= org_crm_stage_badge((string)$inv['status']) ?>"><?= org_crm_h((string)$inv['status']) ?></span></td>
            <td>
              <?php if (($inv['status'] ?? '') !== 'paid'): ?>
              <form method="post" class="d-inline">
                <input type="hidden" name="save_invoice" value="1">
                <input type="hidden" name="invoice_id" value="<?= (int)$inv['id'] ?>">
                <input type="hidden" name="title" value="<?= org_crm_h((string)$inv['title']) ?>">
                <input type="hidden" name="amount" value="<?= number_format((int)$inv['amount_cents']/100, 2, '.', '') ?>">
                <input type="hidden" name="contact_id" value="<?= (int)($inv['contact_id'] ?? 0) ?>">
                <input type="hidden" name="status" value="paid">
                <button class="btn btn-xs btn-success">Mark paid</button>
              </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php org_page_shell_close(); ?>
