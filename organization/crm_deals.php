<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/session_org.php';
require_once __DIR__ . '/includes/org_context.php';
require_once __DIR__ . '/includes/org_manager_guard.php';
org_require_manager();
require_once __DIR__ . '/includes/org_crm.php';

$orgId = (int)orgActiveOrgId();
$memberId = (int)orgMemberId();
org_crm_lifecycle_ensure_schema($dbh);

$stats = org_crm_dashboard_stats($dbh, $orgId);
$stage = strtolower(trim((string)($_GET['stage'] ?? 'all')));
$showNew = isset($_GET['new']);
$editId = (int)($_GET['edit'] ?? 0);
$prefillContactId = (int)($_GET['contact_id'] ?? 0);
$err = '';
$ok = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['delete_deal'])) {
        $did = (int)($_POST['deal_id'] ?? 0);
        try {
            $dbh->prepare('UPDATE org_crm_deals SET is_deleted = 1, updated_at = NOW() WHERE id = :id AND org_id = :org LIMIT 1')
                ->execute([':id' => $did, ':org' => $orgId]);
            $ok = 'Deal removed.';
        } catch (Throwable $e) {
            $err = 'Could not remove deal.';
        }
    } else {
        $pid = (int)($_POST['deal_id'] ?? 0);
        $res = org_crm_save_deal($dbh, $orgId, $_POST, $pid > 0 ? $pid : null, $memberId);
        if (!empty($res['ok'])) {
            $ok = $pid > 0 ? 'Deal updated.' : 'Deal created.';
            $editId = 0;
            $showNew = false;
            $stats = org_crm_dashboard_stats($dbh, $orgId);
        } else {
            $err = (string)($res['error'] ?? 'Save failed.');
            $showNew = true;
            $editId = $pid;
        }
    }
}

$deals = org_crm_list_deals($dbh, $orgId, $stage);
$contacts = org_crm_list_contacts($dbh, $orgId, 'all', '', 300);
$editDeal = null;
if ($editId > 0) {
    foreach ($deals as $d) {
        if ((int)$d['id'] === $editId) {
            $editDeal = $d;
            break;
        }
    }
    if (!$editDeal) {
        $all = org_crm_list_deals($dbh, $orgId, 'all');
        foreach ($all as $d) {
            if ((int)$d['id'] === $editId) {
                $editDeal = $d;
                break;
            }
        }
    }
}
if ($showNew && !$editDeal) {
    $editDeal = ['stage' => 'lead', 'probability' => 20, 'contact_id' => $prefillContactId > 0 ? $prefillContactId : null];
}

$pipelineStages = ['lead', 'qualified', 'proposal', 'negotiation', 'won', 'lost'];

$pageTitle = 'CRM Pipeline';
require_once __DIR__ . '/includes/org_page_shell.php';
org_page_shell_open($pageTitle);
?>
<div class="sh-pagebody">
  <div class="row row-sm mg-b-20">
    <div class="col-md-4"><div class="card shadow-base"><div class="card-body">
      <div class="tx-10 tx-uppercase tx-color-03">Pipeline value</div>
      <div class="tx-24 tx-bold"><?= org_crm_h(org_crm_money((int)$stats['pipeline_cents'])) ?></div>
    </div></div></div>
    <div class="col-md-4"><div class="card shadow-base"><div class="card-body">
      <div class="tx-10 tx-uppercase tx-color-03">Weighted forecast</div>
      <div class="tx-24 tx-bold"><?= org_crm_h(org_crm_money((int)$stats['forecast_cents'])) ?></div>
    </div></div></div>
    <div class="col-md-4"><div class="card shadow-base"><div class="card-body">
      <div class="tx-10 tx-uppercase tx-color-03">Won this month</div>
      <div class="tx-24 tx-bold"><?= org_crm_h(org_crm_money((int)$stats['won_mtd_cents'])) ?></div>
    </div></div></div>
  </div>

  <div class="card bd-0 shadow-base">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center">
      <h6 class="card-title tx-uppercase tx-14 mg-b-0">Sales pipeline</h6>
      <div class="d-flex" style="gap:8px;">
        <a href="crm.php" class="btn btn-sm btn-outline-secondary">CRM home</a>
        <a href="crm_deals.php?new=1" class="btn btn-sm btn-primary">New deal</a>
      </div>
    </div>
    <div class="card-body">
      <?php if ($err): ?><div class="alert alert-danger"><?= org_crm_h($err) ?></div><?php endif; ?>
      <?php if ($ok): ?><div class="alert alert-success"><?= org_crm_h($ok) ?></div><?php endif; ?>

      <?php if ($showNew || $editDeal): ?>
      <form method="post" class="mg-b-20 pd-15" style="background:#f8fafc;border-radius:12px;">
        <input type="hidden" name="deal_id" value="<?= (int)($editDeal['id'] ?? 0) ?>">
        <div class="row">
          <div class="col-md-6 form-group"><label>Deal title *</label><input name="title" class="form-control" required value="<?= org_crm_h((string)($editDeal['title'] ?? '')) ?>"></div>
          <div class="col-md-3 form-group"><label>Amount (USD)</label><input name="amount" type="number" step="0.01" min="0" class="form-control" value="<?= isset($editDeal['amount_cents']) ? number_format((int)$editDeal['amount_cents'] / 100, 2, '.', '') : '' ?>"></div>
          <div class="col-md-3 form-group"><label>Probability %</label><input name="probability" type="number" min="0" max="100" class="form-control" value="<?= (int)($editDeal['probability'] ?? 20) ?>"></div>
          <div class="col-md-4 form-group"><label>Stage</label>
            <select name="stage" class="form-control">
              <?php foreach ($pipelineStages as $s): ?>
                <option value="<?= $s ?>" <?= (($editDeal['stage'] ?? '') === $s) ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-4 form-group"><label>Contact</label>
            <select name="contact_id" class="form-control">
              <option value="0">— None —</option>
              <?php foreach ($contacts as $c): ?>
                <option value="<?= (int)$c['id'] ?>" <?= ((int)($editDeal['contact_id'] ?? 0) === (int)$c['id']) ? 'selected' : '' ?>><?= org_crm_h((string)$c['full_name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-4 form-group"><label>Expected close</label><input name="expected_close_date" type="date" class="form-control" value="<?= org_crm_h((string)($editDeal['expected_close_date'] ?? '')) ?>"></div>
          <div class="col-md-12 form-group"><label>Notes</label><textarea name="notes" class="form-control" rows="2"><?= org_crm_h((string)($editDeal['notes'] ?? '')) ?></textarea></div>
        </div>
        <button type="submit" class="btn btn-primary">Save deal</button>
        <a href="crm_deals.php" class="btn btn-light mg-l-5">Cancel</a>
      </form>
      <?php endif; ?>

      <form method="get" class="form-inline mg-b-15">
        <select name="stage" class="form-control form-control-sm mg-r-10">
          <option value="all" <?= $stage === 'all' ? 'selected' : '' ?>>All stages</option>
          <?php foreach ($pipelineStages as $s): ?>
            <option value="<?= $s ?>" <?= $stage === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
          <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-sm btn-secondary">Filter</button>
      </form>

      <div class="table-responsive">
        <table class="table table-hover mg-b-0">
          <thead><tr><th>Deal</th><th>Contact</th><th>Stage</th><th>Amount</th><th>Prob.</th><th>Forecast</th><th>Close</th><th></th></tr></thead>
          <tbody>
          <?php if (!$deals): ?>
            <tr><td colspan="8" class="text-center tx-color-03">No deals in pipeline.</td></tr>
          <?php else: foreach ($deals as $d):
            $forecast = (int)round((int)$d['amount_cents'] * (int)$d['probability'] / 100);
          ?>
            <tr>
              <td><strong><?= org_crm_h((string)$d['title']) ?></strong></td>
              <td><?php if (!empty($d['contact_id'])): ?><a href="crm_contact.php?id=<?= (int)$d['contact_id'] ?>"><?= org_crm_h((string)($d['contact_name'] ?? '')) ?></a><?php else: ?>—<?php endif; ?></td>
              <td><span class="badge <?= org_crm_stage_badge((string)$d['stage']) ?>"><?= org_crm_h((string)$d['stage']) ?></span></td>
              <td><?= org_crm_h(org_crm_money((int)$d['amount_cents'])) ?></td>
              <td><?= (int)$d['probability'] ?>%</td>
              <td><?= org_crm_h(org_crm_money($forecast)) ?></td>
              <td class="tx-12"><?= org_crm_h((string)($d['expected_close_date'] ?? '—')) ?></td>
              <td class="text-right">
                <a href="crm_deals.php?edit=<?= (int)$d['id'] ?>" class="btn btn-xs btn-outline-primary">Edit</a>
                <form method="post" class="d-inline" onsubmit="return confirm('Remove this deal?');">
                  <input type="hidden" name="delete_deal" value="1">
                  <input type="hidden" name="deal_id" value="<?= (int)$d['id'] ?>">
                  <button class="btn btn-xs btn-outline-danger">Delete</button>
                </form>
              </td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
<?php org_page_shell_close(); ?>
