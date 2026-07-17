<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/session_org.php';
require_once __DIR__ . '/includes/org_context.php';
require_once __DIR__ . '/includes/org_manager_guard.php';
org_require_manager();

org_require_commerce_seller();
require_once __DIR__ . '/includes/org_crm.php';

$orgId = (int)orgActiveOrgId();
$memberId = (int)orgMemberId();
org_crm_lifecycle_ensure_schema($dbh);

$err = '';
$ok = '';
$status = strtolower(trim((string)($_GET['status'] ?? 'all')));
$showNew = isset($_GET['new']);
$prefillContactId = (int)($_GET['contact_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_ticket'])) {
    $res = org_crm_create_ticket($dbh, $orgId, $_POST, $memberId);
    if (!empty($res['ok'])) {
        header('Location: crm_ticket.php?id=' . (int)$res['ticket_id']);
        exit;
    }
    $err = (string)($res['error'] ?? 'Could not create ticket.');
    $showNew = true;
}

$tickets = org_crm_list_tickets($dbh, $orgId, $status);
$contacts = org_crm_list_contacts($dbh, $orgId, 'all', '', 300);

$pageTitle = 'CRM Tickets';
require_once __DIR__ . '/includes/org_page_shell.php';
org_page_shell_open($pageTitle);
?>
<?php org_page_body_open(); ?>
  <div class="card bd-0 shadow-base">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center">
      <h6 class="card-title tx-uppercase tx-14 mg-b-0">Support tickets</h6>
      <div class="d-flex" style="gap:8px;">
        <a href="crm.php" class="btn btn-sm btn-outline-secondary">CRM home</a>
        <a href="crm_tickets.php?new=1" class="btn btn-sm btn-primary">New ticket</a>
      </div>
    </div>
    <div class="card-body">
      <?php if ($err): ?><div class="alert alert-danger"><?= org_crm_h($err) ?></div><?php endif; ?>
      <?php if ($ok): ?><div class="alert alert-success"><?= org_crm_h($ok) ?></div><?php endif; ?>

      <?php if ($showNew): ?>
      <form method="post" class="mg-b-20 pd-15 org-form-panel">
        <input type="hidden" name="create_ticket" value="1">
        <div class="row">
          <div class="col-md-8 form-group"><label>Subject *</label><input name="subject" class="form-control" required></div>
          <div class="col-md-4 form-group"><label>Priority</label>
            <select name="priority" class="form-control">
              <?php foreach (['low','normal','high','urgent'] as $p): ?>
                <option value="<?= $p ?>"><?= ucfirst($p) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-4 form-group"><label>Contact</label>
            <select name="contact_id" class="form-control">
              <option value="0">— None —</option>
              <?php foreach ($contacts as $c): ?>
                <option value="<?= (int)$c['id'] ?>" <?= $prefillContactId === (int)$c['id'] ? 'selected' : '' ?>><?= org_crm_h((string)$c['full_name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-4 form-group"><label>Requester name</label><input name="requester_name" class="form-control"></div>
          <div class="col-md-4 form-group"><label>Requester email</label><input name="requester_email" type="email" class="form-control"></div>
          <div class="col-md-12 form-group"><label>Description</label><textarea name="description" class="form-control" rows="3"></textarea></div>
        </div>
        <button type="submit" class="btn btn-primary">Create ticket</button>
        <a href="crm_tickets.php" class="btn btn-light mg-l-5">Cancel</a>
      </form>
      <?php endif; ?>

      <form method="get" class="form-inline mg-b-15">
        <select name="status" class="form-control form-control-sm mg-r-10">
          <?php foreach (['all','open','pending','resolved','closed'] as $s): ?>
            <option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
          <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-sm btn-secondary">Filter</button>
      </form>

      <div class="table-responsive">
        <table class="table table-hover mg-b-0">
          <thead><tr><th>Code</th><th>Subject</th><th>Contact</th><th>Status</th><th>Priority</th><th>Updated</th></tr></thead>
          <tbody>
          <?php if (!$tickets): ?>
            <tr><td colspan="6" class="text-center tx-color-03">No tickets.</td></tr>
          <?php else: foreach ($tickets as $t): ?>
            <tr>
              <td><a href="crm_ticket.php?id=<?= (int)$t['id'] ?>"><?= org_crm_h((string)$t['ticket_code']) ?></a></td>
              <td><a href="crm_ticket.php?id=<?= (int)$t['id'] ?>"><?= org_crm_h((string)$t['subject']) ?></a></td>
              <td><?= org_crm_h((string)($t['contact_name'] ?? $t['requester_name'] ?? '—')) ?></td>
              <td><span class="badge <?= org_crm_stage_badge((string)$t['status']) ?>"><?= org_crm_h((string)$t['status']) ?></span></td>
              <td><span class="badge <?= org_crm_stage_badge((string)$t['priority']) ?>"><?= org_crm_h((string)$t['priority']) ?></span></td>
              <td class="tx-12"><?= org_crm_h((string)($t['updated_at'] ?? '')) ?></td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
<?php org_page_shell_close(); ?>
