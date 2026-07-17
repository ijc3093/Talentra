<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/session_org.php';
require_once __DIR__ . '/includes/org_context.php';
require_once __DIR__ . '/includes/org_manager_guard.php';
org_require_manager();

org_require_commerce_seller();
require_once __DIR__ . '/includes/org_crm_lifecycle.php';
require_once __DIR__ . '/../public_user/includes/buyer_seller_relationship.php';

$orgId = (int)orgActiveOrgId();
$memberId = (int)orgMemberId();
org_crm_lifecycle_ensure_schema($dbh);
buyer_seller_rel_ensure_schema($dbh);

$contactId = (int)($_GET['id'] ?? 0);
$contact = org_crm_get_contact($dbh, $orgId, $contactId);
if (!$contact) {
    header('Location: crm_contacts.php');
    exit;
}

$err = '';
$ok = '';
$tab = strtolower(trim((string)($_GET['tab'] ?? 'activity')));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['log_interaction'])) {
        $type = (string)($_POST['interaction_type'] ?? 'note');
        $subject = trim((string)($_POST['subject'] ?? ''));
        $body = trim((string)($_POST['body'] ?? ''));
        if ($body === '' && $subject === '') {
            $err = 'Enter a subject or note.';
        } elseif (org_crm_log_interaction($dbh, $orgId, $contactId, $memberId, $type, $subject, $body)) {
            $ok = 'Interaction logged.';
        } else {
            $err = 'Could not log interaction.';
        }
    } elseif (isset($_POST['update_contact'])) {
        $res = org_crm_save_contact($dbh, $orgId, $_POST, $contactId, $memberId);
        if (!empty($res['ok'])) {
            $ok = 'Contact updated.';
            $contact = org_crm_get_contact($dbh, $orgId, $contactId);
        } else {
            $err = (string)($res['error'] ?? 'Update failed.');
        }
    } elseif (isset($_POST['save_address'])) {
        if (org_crm_save_address($dbh, $orgId, $contactId, $_POST)) {
            $ok = 'Address added.';
        } else {
            $err = 'Could not save address.';
        }
    } elseif (isset($_POST['upload_file'])) {
        if (org_crm_upload_contact_file($dbh, $orgId, $contactId, $memberId)) {
            $ok = 'File uploaded.';
        } else {
            $err = 'Could not upload file.';
        }
    }
}

$interactions = org_crm_list_interactions($dbh, $orgId, $contactId);
$addresses = org_crm_list_addresses($dbh, $orgId, $contactId);
$files = org_crm_list_files($dbh, $orgId, $contactId);
$deals = org_crm_list_deals($dbh, $orgId, 'all', 20);
$contactDeals = array_values(array_filter($deals, static fn($d) => (int)($d['contact_id'] ?? 0) === $contactId));
$bookings = array_values(array_filter(org_crm_list_bookings($dbh, $orgId), static fn($b) => (int)($b['contact_id'] ?? 0) === $contactId));
$invoices = array_values(array_filter(org_crm_list_invoices($dbh, $orgId), static fn($i) => (int)($i['contact_id'] ?? 0) === $contactId));
$buyerNeeds = null;
$linkedBuyerId = (int)($contact['linked_user_id'] ?? 0);
if ($linkedBuyerId > 0) {
    $buyerNeeds = buyer_seller_rel_for_seller($dbh, $orgId, $linkedBuyerId);
}

$pageTitle = 'Contact — ' . (string)$contact['full_name'];
require_once __DIR__ . '/includes/org_page_shell.php';
org_page_shell_open($pageTitle);
?>
<?php org_page_body_open(); ?>
  <div class="d-flex flex-wrap justify-content-between align-items-center mg-b-15">
    <div>
      <a href="crm.php" class="tx-12">&larr; CRM hub</a>
      <h4 class="mg-b-0"><?= org_crm_h((string)$contact['full_name']) ?></h4>
      <span class="badge <?= org_crm_stage_badge((string)$contact['lifecycle_stage']) ?>"><?= org_crm_h((string)$contact['lifecycle_stage']) ?></span>
      <span class="tx-12 tx-color-03 mg-l-5">via <?= org_crm_h((string)($contact['lead_source'] ?? '')) ?></span>
    </div>
    <div class="d-flex flex-wrap" style="gap:8px;">
      <a href="messages.php" class="btn btn-sm btn-outline-primary">Messages</a>
      <a href="crm_convert.php?new_quote=1" class="btn btn-sm btn-outline-success">Quote</a>
      <a href="crm_bookings.php?new=1&amp;contact_id=<?= $contactId ?>" class="btn btn-sm btn-outline-info">Booking</a>
      <a href="crm_tickets.php?new=1&amp;contact_id=<?= $contactId ?>" class="btn btn-sm btn-outline-danger">Ticket</a>
    </div>
  </div>

  <?php if ($err): ?><div class="alert alert-danger"><?= org_crm_h($err) ?></div><?php endif; ?>
  <?php if ($ok): ?><div class="alert alert-success"><?= org_crm_h($ok) ?></div><?php endif; ?>

  <ul class="nav nav-tabs mg-b-20">
    <li class="nav-item"><a class="nav-link<?= $tab === 'profile' ? ' active' : '' ?>" href="crm_contact.php?id=<?= $contactId ?>&tab=profile">Profile</a></li>
    <li class="nav-item"><a class="nav-link<?= $tab === 'activity' ? ' active' : '' ?>" href="crm_contact.php?id=<?= $contactId ?>&tab=activity">Activity</a></li>
    <li class="nav-item"><a class="nav-link<?= $tab === 'address' ? ' active' : '' ?>" href="crm_contact.php?id=<?= $contactId ?>&tab=address">Address</a></li>
    <li class="nav-item"><a class="nav-link<?= $tab === 'files' ? ' active' : '' ?>" href="crm_contact.php?id=<?= $contactId ?>&tab=files">Files</a></li>
    <li class="nav-item"><a class="nav-link<?= $tab === 'history' ? ' active' : '' ?>" href="crm_contact.php?id=<?= $contactId ?>&tab=history">History</a></li>
  </ul>

  <div class="row row-sm">
    <div class="col-lg-4 mg-b-20">
      <div class="card shadow-base">
        <div class="card-header"><h6 class="card-title tx-14 mg-b-0">Customer profile</h6></div>
        <div class="card-body">
          <form method="post">
            <input type="hidden" name="update_contact" value="1">
            <div class="form-group"><label>Email</label><input name="email" type="email" class="form-control form-control-sm" value="<?= org_crm_h((string)($contact['email'] ?? '')) ?>"></div>
            <div class="form-group"><label>Phone</label><input name="phone" class="form-control form-control-sm" value="<?= org_crm_h((string)($contact['phone'] ?? '')) ?>"></div>
            <div class="form-group"><label>Company</label><input name="company" class="form-control form-control-sm" value="<?= org_crm_h((string)($contact['company'] ?? '')) ?>"></div>
            <div class="form-group"><label>Job title</label><input name="job_title" class="form-control form-control-sm" value="<?= org_crm_h((string)($contact['job_title'] ?? '')) ?>"></div>
            <input type="hidden" name="full_name" value="<?= org_crm_h((string)$contact['full_name']) ?>">
            <div class="form-group"><label>Stage</label>
              <select name="lifecycle_stage" class="form-control form-control-sm">
                <?php foreach (['lead','prospect','customer','partner','churned'] as $s): ?>
                  <option value="<?= $s ?>" <?= (($contact['lifecycle_stage'] ?? '') === $s) ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group"><label>Notes</label><textarea name="notes" class="form-control form-control-sm" rows="2"><?= org_crm_h((string)($contact['notes'] ?? '')) ?></textarea></div>
            <button type="submit" class="btn btn-primary btn-sm">Save</button>
          </form>
        </div>
      </div>

      <?php if ($buyerNeeds): ?>
      <div class="card shadow-base mg-t-15">
        <div class="card-header"><h6 class="card-title tx-14 mg-b-0">Buyer shared needs</h6></div>
        <div class="card-body tx-13">
          <p class="tx-12 tx-color-03">Preferences the customer shared so you can meet their needs.</p>
          <p class="mg-b-5"><strong>Relationship:</strong> <?= org_crm_h(buyer_seller_rel_type_label((string)($buyerNeeds['relationship_type'] ?? ''))) ?></p>
          <?php if (trim((string)($buyerNeeds['interests'] ?? '')) !== ''): ?>
            <p class="mg-b-5"><strong>Interests:</strong> <?= org_crm_h((string)$buyerNeeds['interests']) ?></p>
          <?php endif; ?>
          <p class="mg-b-5"><strong>Preferred contact:</strong> <?= org_crm_h(buyer_seller_rel_contact_label((string)($buyerNeeds['preferred_contact'] ?? ''))) ?></p>
          <?php if (trim((string)($buyerNeeds['delivery_preference'] ?? '')) !== ''): ?>
            <p class="mg-b-5"><strong>Delivery:</strong> <?= org_crm_h((string)$buyerNeeds['delivery_preference']) ?></p>
          <?php endif; ?>
          <?php if (trim((string)($buyerNeeds['budget_range'] ?? '')) !== ''): ?>
            <p class="mg-b-5"><strong>Budget:</strong> <?= org_crm_h((string)$buyerNeeds['budget_range']) ?></p>
          <?php endif; ?>
          <?php if (trim((string)($buyerNeeds['needs_note'] ?? '')) !== ''): ?>
            <p class="mg-b-0"><strong>Note:</strong><br><?= nl2br(org_crm_h((string)$buyerNeeds['needs_note'])) ?></p>
          <?php endif; ?>
        </div>
      </div>
      <?php elseif ($linkedBuyerId > 0): ?>
      <div class="card shadow-base mg-t-15">
        <div class="card-header"><h6 class="card-title tx-14 mg-b-0">Buyer shared needs</h6></div>
        <div class="card-body tx-13 tx-color-03">This linked buyer has not shared shopping preferences yet.</div>
      </div>
      <?php endif; ?>

      <?php if ($contactDeals || $bookings || $invoices): ?>
      <div class="card shadow-base mg-t-15">
        <div class="card-header"><h6 class="card-title tx-14 mg-b-0">Linked records</h6></div>
        <div class="card-body pd-0 tx-12">
          <?php foreach ($contactDeals as $d): ?>
            <a href="crm_deals.php?edit=<?= (int)$d['id'] ?>" class="d-block pd-10 border-bottom" style="text-decoration:none;color:inherit;">Deal: <?= org_crm_h((string)$d['title']) ?></a>
          <?php endforeach; ?>
          <?php foreach (array_slice($bookings, 0, 3) as $b): ?>
            <div class="pd-10 border-bottom">Booking: <?= org_crm_h((string)$b['title']) ?></div>
          <?php endforeach; ?>
          <?php foreach (array_slice($invoices, 0, 3) as $inv): ?>
            <div class="pd-10 border-bottom">Invoice: <?= org_crm_h((string)$inv['invoice_code']) ?></div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>
    </div>

    <div class="col-lg-8">
      <?php if ($tab === 'activity' || $tab === 'profile'): ?>
      <div class="card shadow-base mg-b-20">
        <div class="card-header"><h6 class="card-title tx-14 mg-b-0">Log activity</h6></div>
        <div class="card-body">
          <form method="post">
            <input type="hidden" name="log_interaction" value="1">
            <div class="row">
              <div class="col-md-3 form-group">
                <select name="interaction_type" class="form-control form-control-sm">
                  <?php foreach (['note','call','email','meeting','task'] as $t): ?>
                    <option value="<?= $t ?>"><?= ucfirst($t) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-9 form-group"><input name="subject" class="form-control form-control-sm" placeholder="Subject"></div>
              <div class="col-md-12 form-group"><textarea name="body" class="form-control" rows="2" required placeholder="Activity details"></textarea></div>
            </div>
            <button type="submit" class="btn btn-primary btn-sm">Log</button>
          </form>
        </div>
      </div>
      <div class="card shadow-base">
        <div class="card-header"><h6 class="card-title tx-14 mg-b-0">Activity timeline</h6></div>
        <div class="card-body pd-0">
          <?php if (!$interactions): ?>
            <p class="pd-15 tx-color-03">No activity yet.</p>
          <?php else: foreach ($interactions as $i): ?>
            <div class="pd-15 border-bottom">
              <div class="d-flex justify-content-between">
                <strong><?= org_crm_h((string)($i['interaction_type'] ?? '')) ?></strong>
                <span class="tx-12 tx-color-03"><?= org_crm_h((string)($i['created_at'] ?? '')) ?></span>
              </div>
              <?php if (!empty($i['subject'])): ?><div><?= org_crm_h((string)$i['subject']) ?></div><?php endif; ?>
              <?php if (!empty($i['body'])): ?><div class="tx-color-03"><?= nl2br(org_crm_h((string)$i['body'])) ?></div><?php endif; ?>
            </div>
          <?php endforeach; endif; ?>
        </div>
      </div>
      <?php endif; ?>

      <?php if ($tab === 'address'): ?>
      <div class="card shadow-base mg-b-20">
        <div class="card-header"><h6 class="card-title tx-14 mg-b-0">Add address</h6></div>
        <div class="card-body">
          <form method="post">
            <input type="hidden" name="save_address" value="1">
            <div class="row">
              <div class="col-md-4 form-group"><label>Label</label><input name="label" class="form-control" value="Primary"></div>
              <div class="col-md-8 form-group"><label>Street *</label><input name="line1" class="form-control" required></div>
              <div class="col-md-6 form-group"><label>City</label><input name="city" class="form-control"></div>
              <div class="col-md-3 form-group"><label>State</label><input name="state" class="form-control"></div>
              <div class="col-md-3 form-group"><label>Postal</label><input name="postal_code" class="form-control"></div>
              <div class="col-md-6 form-group"><label>Country</label><input name="country" class="form-control"></div>
            </div>
            <label class="ckbox"><input type="checkbox" name="is_primary" value="1" checked> <span>Primary address</span></label>
            <div class="mg-t-10"><button type="submit" class="btn btn-primary btn-sm">Save address</button></div>
          </form>
        </div>
      </div>
      <div class="card shadow-base">
        <div class="card-header"><h6 class="card-title tx-14 mg-b-0">Saved addresses</h6></div>
        <div class="card-body pd-0">
          <?php if (!$addresses): ?>
            <p class="pd-15 tx-color-03">No addresses on file.</p>
          <?php else: foreach ($addresses as $a): ?>
            <div class="pd-15 border-bottom">
              <strong><?= org_crm_h((string)($a['label'] ?? 'Address')) ?></strong>
              <?php if (!empty($a['is_primary'])): ?> <span class="badge badge-primary">Primary</span><?php endif; ?>
              <div><?= org_crm_h((string)$a['line1']) ?></div>
              <div class="tx-12 tx-color-03"><?= org_crm_h(trim(implode(', ', array_filter([$a['city'] ?? '', $a['state'] ?? '', $a['postal_code'] ?? '', $a['country'] ?? ''])))) ?></div>
            </div>
          <?php endforeach; endif; ?>
        </div>
      </div>
      <?php endif; ?>

      <?php if ($tab === 'files'): ?>
      <div class="card shadow-base mg-b-20">
        <div class="card-header"><h6 class="card-title tx-14 mg-b-0">Upload file</h6></div>
        <div class="card-body">
          <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="upload_file" value="1">
            <input type="file" name="contact_file" class="form-control mg-b-10" required>
            <button type="submit" class="btn btn-primary btn-sm">Upload</button>
          </form>
        </div>
      </div>
      <div class="card shadow-base">
        <div class="card-header"><h6 class="card-title tx-14 mg-b-0">Files</h6></div>
        <div class="card-body pd-0">
          <?php if (!$files): ?>
            <p class="pd-15 tx-color-03">No files attached.</p>
          <?php else: foreach ($files as $f): ?>
            <a href="../<?= org_crm_h((string)$f['file_path']) ?>" target="_blank" class="d-block pd-15 border-bottom" style="text-decoration:none;">
              <?= org_crm_h((string)$f['file_name']) ?>
              <span class="tx-12 tx-color-03"><?= org_crm_h((string)($f['created_at'] ?? '')) ?></span>
            </a>
          <?php endforeach; endif; ?>
        </div>
      </div>
      <?php endif; ?>

      <?php if ($tab === 'history'): ?>
      <div class="card shadow-base">
        <div class="card-header"><h6 class="card-title tx-14 mg-b-0">Full history</h6></div>
        <div class="card-body pd-0">
          <ul class="list-unstyled mg-b-0">
            <?php foreach ($interactions as $i): ?>
              <li class="pd-15 border-bottom">
                <span class="badge badge-light"><?= org_crm_h((string)$i['interaction_type']) ?></span>
                <span class="tx-12 tx-color-03 mg-l-5"><?= org_crm_h((string)$i['created_at']) ?></span>
                <div><?= org_crm_h((string)($i['subject'] ?? $i['body'] ?? '')) ?></div>
              </li>
            <?php endforeach; ?>
            <?php foreach ($bookings as $b): ?>
              <li class="pd-15 border-bottom">
                <span class="badge badge-info">booking</span>
                <span class="tx-12 tx-color-03"><?= org_crm_h((string)$b['scheduled_at']) ?></span>
                <div><?= org_crm_h((string)$b['title']) ?> — <?= org_crm_h((string)$b['status']) ?></div>
              </li>
            <?php endforeach; ?>
            <?php if (!$interactions && !$bookings): ?>
              <li class="pd-15 tx-color-03">No history yet.</li>
            <?php endif; ?>
          </ul>
        </div>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php org_page_shell_close(); ?>
