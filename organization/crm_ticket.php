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

$ticketId = (int)($_GET['id'] ?? 0);
$ticket = org_crm_get_ticket($dbh, $orgId, $ticketId);
if (!$ticket) {
    header('Location: crm_tickets.php');
    exit;
}

$err = '';
$ok = '';
$contact = !empty($ticket['contact_id']) ? org_crm_get_contact($dbh, $orgId, (int)$ticket['contact_id']) : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_reply'])) {
        $body = trim((string)($_POST['body'] ?? ''));
        $internal = !empty($_POST['is_internal']);
        if ($body === '') {
            $err = 'Reply cannot be empty.';
        } elseif (org_crm_add_ticket_reply($dbh, $orgId, $ticketId, $memberId, $body, $internal)) {
            $ok = 'Reply added.';
            if ($contact && !$internal) {
                org_crm_log_interaction($dbh, $orgId, (int)$contact['id'], $memberId, 'ticket', 'Ticket reply: ' . (string)$ticket['subject'], $body, null, $ticketId);
            }
        } else {
            $err = 'Could not add reply.';
        }
    } elseif (isset($_POST['update_status'])) {
        $newStatus = strtolower(trim((string)($_POST['status'] ?? '')));
        $priority = strtolower(trim((string)($_POST['priority'] ?? '')));
        if (org_crm_update_ticket($dbh, $orgId, $ticketId, $newStatus, $priority)) {
            $ok = 'Ticket updated.';
            $ticket = org_crm_get_ticket($dbh, $orgId, $ticketId);
        } else {
            $err = 'Could not update ticket.';
        }
    }
}

$replies = org_crm_list_ticket_replies($dbh, $orgId, $ticketId);

$pageTitle = 'Ticket ' . (string)$ticket['ticket_code'];
require_once __DIR__ . '/includes/org_page_shell.php';
org_page_shell_open($pageTitle);
?>
<?php org_page_body_open(); ?>
  <div class="mg-b-15">
    <a href="crm_tickets.php" class="tx-12">&larr; All tickets</a>
    <h4 class="mg-b-5"><?= org_crm_h((string)$ticket['subject']) ?></h4>
    <span class="tx-12 tx-color-03"><?= org_crm_h((string)$ticket['ticket_code']) ?></span>
    <span class="badge <?= org_crm_stage_badge((string)$ticket['status']) ?> mg-l-5"><?= org_crm_h((string)$ticket['status']) ?></span>
    <span class="badge <?= org_crm_stage_badge((string)$ticket['priority']) ?> mg-l-5"><?= org_crm_h((string)$ticket['priority']) ?></span>
  </div>

  <?php if ($err): ?><div class="alert alert-danger"><?= org_crm_h($err) ?></div><?php endif; ?>
  <?php if ($ok): ?><div class="alert alert-success"><?= org_crm_h($ok) ?></div><?php endif; ?>

  <div class="row row-sm">
    <div class="col-lg-8">
      <?php if (!empty($ticket['description'])): ?>
      <div class="card shadow-base mg-b-20">
        <div class="card-header"><h6 class="card-title tx-14 mg-b-0">Original request</h6></div>
        <div class="card-body"><?= nl2br(org_crm_h((string)$ticket['description'])) ?></div>
      </div>
      <?php endif; ?>

      <div class="card shadow-base mg-b-20">
        <div class="card-header"><h6 class="card-title tx-14 mg-b-0">Conversation</h6></div>
        <div class="card-body pd-0">
          <?php if (!$replies): ?>
            <p class="pd-15 tx-color-03 mg-b-0">No replies yet.</p>
          <?php else: foreach ($replies as $r): ?>
            <div class="pd-15 border-bottom <?= !empty($r['is_internal']) ? 'bg-light' : '' ?>">
              <div class="d-flex justify-content-between">
                <strong><?= !empty($r['is_internal']) ? 'Internal note' : 'Reply' ?></strong>
                <span class="tx-12 tx-color-03"><?= org_crm_h((string)($r['created_at'] ?? '')) ?></span>
              </div>
              <div><?= nl2br(org_crm_h((string)$r['body'])) ?></div>
            </div>
          <?php endforeach; endif; ?>
        </div>
      </div>

      <div class="card shadow-base">
        <div class="card-header"><h6 class="card-title tx-14 mg-b-0">Add reply</h6></div>
        <div class="card-body">
          <form method="post">
            <input type="hidden" name="add_reply" value="1">
            <div class="form-group"><textarea name="body" class="form-control" rows="4" required placeholder="Write a reply to the customer…"></textarea></div>
            <label class="ckbox"><input type="checkbox" name="is_internal" value="1"> <span>Internal note (not visible to customer)</span></label>
            <div class="mg-t-10"><button type="submit" class="btn btn-primary">Send reply</button></div>
          </form>
        </div>
      </div>
    </div>

    <div class="col-lg-4">
      <div class="card shadow-base mg-b-20">
        <div class="card-header"><h6 class="card-title tx-14 mg-b-0">Ticket details</h6></div>
        <div class="card-body">
          <form method="post">
            <input type="hidden" name="update_status" value="1">
            <div class="form-group"><label>Status</label>
              <select name="status" class="form-control form-control-sm">
                <?php foreach (['open','pending','resolved','closed'] as $s): ?>
                  <option value="<?= $s ?>" <?= (($ticket['status'] ?? '') === $s) ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group"><label>Priority</label>
              <select name="priority" class="form-control form-control-sm">
                <?php foreach (['low','normal','high','urgent'] as $p): ?>
                  <option value="<?= $p ?>" <?= (($ticket['priority'] ?? '') === $p) ? 'selected' : '' ?>><?= ucfirst($p) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <button type="submit" class="btn btn-sm btn-secondary">Update</button>
          </form>
          <hr>
          <?php if ($contact): ?>
            <p class="mg-b-5"><strong>Contact</strong><br><a href="crm_contact.php?id=<?= (int)$contact['id'] ?>"><?= org_crm_h((string)$contact['full_name']) ?></a></p>
          <?php elseif (!empty($ticket['requester_name']) || !empty($ticket['requester_email'])): ?>
            <p class="mg-b-5"><strong>Requester</strong><br><?= org_crm_h((string)($ticket['requester_name'] ?? '')) ?><br><?= org_crm_h((string)($ticket['requester_email'] ?? '')) ?></p>
          <?php endif; ?>
          <p class="tx-12 tx-color-03 mg-b-0">Created <?= org_crm_h((string)($ticket['created_at'] ?? '')) ?></p>
        </div>
      </div>
    </div>
  </div>
</div>
<?php org_page_shell_close(); ?>
