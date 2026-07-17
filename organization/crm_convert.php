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
$tab = strtolower(trim((string)($_GET['tab'] ?? 'quotes')));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['save_quote'])) {
        $qid = (int)($_POST['quote_id'] ?? 0);
        $res = org_crm_save_quote($dbh, $orgId, $_POST, $qid > 0 ? $qid : null, $memberId);
        $ok = !empty($res['ok']) ? 'Quote saved.' : (string)($res['error'] ?? 'Save failed.');
        if ($err === '' && empty($res['ok'])) {
            $err = $ok;
            $ok = '';
        }
    } elseif (isset($_POST['approve_quote'])) {
        $_POST['status'] = 'approved';
        $res = org_crm_save_quote($dbh, $orgId, $_POST, (int)($_POST['quote_id'] ?? 0), $memberId);
        $ok = !empty($res['ok']) ? 'Quote approved.' : 'Could not approve.';
    } elseif (isset($_POST['reject_quote'])) {
        $_POST['status'] = 'rejected';
        $res = org_crm_save_quote($dbh, $orgId, $_POST, (int)($_POST['quote_id'] ?? 0), $memberId);
        $ok = !empty($res['ok']) ? 'Quote rejected.' : 'Could not reject.';
    } elseif (isset($_POST['save_reminder'])) {
        $res = org_crm_save_reminder($dbh, $orgId, $_POST, $memberId);
        $ok = !empty($res['ok']) ? 'Reminder created.' : (string)($res['error'] ?? 'Save failed.');
    } elseif (isset($_POST['complete_reminder'])) {
        org_crm_complete_reminder($dbh, $orgId, (int)($_POST['reminder_id'] ?? 0), 'done');
        $ok = 'Reminder completed.';
    }
}

$quotes = org_crm_list_quotes($dbh, $orgId);
$reminders = org_crm_list_reminders($dbh, $orgId, 'pending');
$pendingApproval = array_values(array_filter($quotes, static fn($q) => ($q['status'] ?? '') === 'pending_approval'));
$contacts = org_crm_list_contacts($dbh, $orgId, 'all', '', 300);
$showNewQuote = isset($_GET['new_quote']);

$pageTitle = 'Convert';
require_once __DIR__ . '/includes/org_page_shell.php';
org_page_shell_open($pageTitle);
?>
<?php org_page_body_open(); ?>
  <div class="mg-b-20">
    <a href="crm.php" class="tx-12">&larr; CRM lifecycle</a>
    <h4 class="mg-b-0">Convert</h4>
    <p class="tx-color-03">Quotes, follow-up reminders, and deal approvals.</p>
  </div>

  <?php if ($err): ?><div class="alert alert-danger"><?= org_crm_h($err) ?></div><?php endif; ?>
  <?php if ($ok): ?><div class="alert alert-success"><?= org_crm_h($ok) ?></div><?php endif; ?>

  <ul class="nav nav-tabs mg-b-20">
    <li class="nav-item"><a class="nav-link<?= $tab === 'quotes' ? ' active' : '' ?>" href="crm_convert.php?tab=quotes">Quotes</a></li>
    <li class="nav-item"><a class="nav-link<?= $tab === 'reminders' ? ' active' : '' ?>" href="crm_convert.php?tab=reminders">Reminders</a></li>
    <li class="nav-item"><a class="nav-link<?= $tab === 'approvals' ? ' active' : '' ?>" href="crm_convert.php?tab=approvals">Approvals <span class="badge badge-warning"><?= count($pendingApproval) ?></span></a></li>
    <li class="nav-item"><a class="nav-link" href="crm_deals.php">Deals pipeline</a></li>
  </ul>

  <?php if ($tab === 'quotes' || $showNewQuote): ?>
  <div class="card shadow-base mg-b-20">
    <div class="card-header d-flex justify-content-between">
      <h6 class="card-title tx-14 mg-b-0">Quotes</h6>
      <a href="crm_convert.php?tab=quotes&new_quote=1" class="btn btn-sm btn-primary">New quote</a>
    </div>
    <div class="card-body">
      <?php if ($showNewQuote): ?>
      <form method="post" class="mg-b-20 pd-15 org-form-panel">
        <input type="hidden" name="save_quote" value="1">
        <div class="row">
          <div class="col-md-5 form-group"><label>Title *</label><input name="title" class="form-control" required></div>
          <div class="col-md-3 form-group"><label>Amount (USD)</label><input name="amount" type="number" step="0.01" min="0" class="form-control"></div>
          <div class="col-md-4 form-group"><label>Contact</label>
            <select name="contact_id" class="form-control">
              <option value="0">— None —</option>
              <?php foreach ($contacts as $c): ?>
                <option value="<?= (int)$c['id'] ?>"><?= org_crm_h((string)$c['full_name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-3 form-group"><label>Valid until</label><input name="valid_until" type="date" class="form-control"></div>
          <div class="col-md-3 form-group"><label>Status</label>
            <select name="status" class="form-control">
              <?php foreach (['draft','sent','pending_approval'] as $s): ?>
                <option value="<?= $s ?>"><?= ucfirst(str_replace('_',' ',$s)) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-12 form-group"><label>Notes</label><textarea name="notes" class="form-control" rows="2"></textarea></div>
        </div>
        <button type="submit" class="btn btn-primary">Save quote</button>
      </form>
      <?php endif; ?>

      <table class="table table-hover mg-b-0">
        <thead><tr><th>Code</th><th>Title</th><th>Contact</th><th>Amount</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php if (!$quotes): ?>
          <tr><td colspan="6" class="text-center tx-color-03">No quotes yet.</td></tr>
        <?php else: foreach ($quotes as $q): ?>
          <tr>
            <td><code><?= org_crm_h((string)$q['quote_code']) ?></code></td>
            <td><?= org_crm_h((string)$q['title']) ?></td>
            <td><?= org_crm_h((string)($q['contact_name'] ?? '—')) ?></td>
            <td><?= org_crm_h(org_crm_money((int)$q['amount_cents'])) ?></td>
            <td><span class="badge <?= org_crm_stage_badge((string)$q['status']) ?>"><?= org_crm_h((string)$q['status']) ?></span></td>
            <td>
              <?php if (($q['status'] ?? '') === 'pending_approval'): ?>
              <form method="post" class="d-inline">
                <input type="hidden" name="quote_id" value="<?= (int)$q['id'] ?>">
                <input type="hidden" name="title" value="<?= org_crm_h((string)$q['title']) ?>">
                <input type="hidden" name="amount" value="<?= number_format((int)$q['amount_cents']/100, 2, '.', '') ?>">
                <button name="approve_quote" value="1" class="btn btn-xs btn-success">Approve</button>
                <button name="reject_quote" value="1" class="btn btn-xs btn-outline-danger">Reject</button>
              </form>
              <?php endif; ?>
              <a href="crm_invoices.php?from_quote=<?= (int)$q['id'] ?>" class="btn btn-xs btn-outline-primary">Invoice</a>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($tab === 'reminders'): ?>
  <div class="card shadow-base">
    <div class="card-header"><h6 class="card-title tx-14 mg-b-0">Reminders</h6></div>
    <div class="card-body">
      <form method="post" class="mg-b-20 pd-15 org-form-panel">
        <input type="hidden" name="save_reminder" value="1">
        <div class="row">
          <div class="col-md-4 form-group"><label>Title *</label><input name="title" class="form-control" required></div>
          <div class="col-md-4 form-group"><label>Due at *</label><input name="due_at" type="datetime-local" class="form-control" required></div>
          <div class="col-md-4 form-group"><label>Contact</label>
            <select name="contact_id" class="form-control">
              <option value="0">— None —</option>
              <?php foreach ($contacts as $c): ?>
                <option value="<?= (int)$c['id'] ?>"><?= org_crm_h((string)$c['full_name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-12 form-group"><label>Note</label><textarea name="body" class="form-control" rows="2"></textarea></div>
        </div>
        <button type="submit" class="btn btn-primary">Add reminder</button>
      </form>
      <table class="table table-hover mg-b-0">
        <thead><tr><th>Due</th><th>Title</th><th>Contact</th><th></th></tr></thead>
        <tbody>
        <?php if (!$reminders): ?>
          <tr><td colspan="4" class="text-center tx-color-03">No pending reminders.</td></tr>
        <?php else: foreach ($reminders as $r): ?>
          <tr>
            <td class="tx-12"><?= org_crm_h((string)$r['due_at']) ?></td>
            <td><?= org_crm_h((string)$r['title']) ?></td>
            <td><?= org_crm_h((string)($r['contact_name'] ?? '—')) ?></td>
            <td>
              <form method="post" class="d-inline">
                <input type="hidden" name="reminder_id" value="<?= (int)$r['id'] ?>">
                <button name="complete_reminder" value="1" class="btn btn-xs btn-success">Done</button>
              </form>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($tab === 'approvals'): ?>
  <div class="card shadow-base">
    <div class="card-header"><h6 class="card-title tx-14 mg-b-0">Pending approvals</h6></div>
    <div class="card-body pd-0">
      <?php if (!$pendingApproval): ?>
        <p class="pd-15 tx-color-03">No quotes awaiting approval.</p>
      <?php else: foreach ($pendingApproval as $q): ?>
        <div class="pd-15 border-bottom d-flex justify-content-between align-items-center flex-wrap">
          <div>
            <strong><?= org_crm_h((string)$q['title']) ?></strong>
            <div class="tx-12"><?= org_crm_h(org_crm_money((int)$q['amount_cents'])) ?> · <?= org_crm_h((string)($q['contact_name'] ?? '')) ?></div>
          </div>
          <form method="post" class="d-flex" style="gap:6px;">
            <input type="hidden" name="quote_id" value="<?= (int)$q['id'] ?>">
            <input type="hidden" name="title" value="<?= org_crm_h((string)$q['title']) ?>">
            <input type="hidden" name="amount" value="<?= number_format((int)$q['amount_cents']/100, 2, '.', '') ?>">
            <button name="approve_quote" value="1" class="btn btn-sm btn-success">Approve</button>
            <button name="reject_quote" value="1" class="btn btn-sm btn-outline-danger">Reject</button>
          </form>
        </div>
      <?php endforeach; endif; ?>
    </div>
  </div>
  <?php endif; ?>
</div>
<?php org_page_shell_close(); ?>
