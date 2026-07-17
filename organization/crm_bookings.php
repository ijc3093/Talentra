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
$prefillContactId = (int)($_GET['contact_id'] ?? 0);
$showNew = isset($_GET['new']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_booking'])) {
    $bid = (int)($_POST['booking_id'] ?? 0);
    $res = org_crm_save_booking($dbh, $orgId, $_POST, $bid > 0 ? $bid : null, $memberId);
    if (!empty($res['ok'])) {
        $ok = 'Booking saved.';
        if ($bid <= 0 && ($_POST['status'] ?? '') === 'completed') {
            org_crm_log_interaction($dbh, $orgId, (int)($_POST['contact_id'] ?? 0), $memberId, 'meeting', 'Service completed: ' . ($_POST['title'] ?? ''), (string)($_POST['notes'] ?? ''));
        }
    } else {
        $err = (string)($res['error'] ?? 'Save failed.');
    }
}

$bookings = org_crm_list_bookings($dbh, $orgId);
$contacts = org_crm_list_contacts($dbh, $orgId, 'all', '', 300);
$members = org_crm_list_org_members($dbh, $orgId);
$completed = array_values(array_filter($bookings, static fn($b) => ($b['status'] ?? '') === 'completed'));

$pageTitle = 'Serve — Bookings';
require_once __DIR__ . '/includes/org_page_shell.php';
org_page_shell_open($pageTitle);
?>
<?php org_page_body_open(); ?>
  <div class="mg-b-20">
    <a href="crm.php" class="tx-12">&larr; CRM lifecycle</a>
    <h4 class="mg-b-0">Serve</h4>
    <p class="tx-color-03">Bookings, fieldworker assignments, and service history.</p>
  </div>

  <?php if ($err): ?><div class="alert alert-danger"><?= org_crm_h($err) ?></div><?php endif; ?>
  <?php if ($ok): ?><div class="alert alert-success"><?= org_crm_h($ok) ?></div><?php endif; ?>

  <div class="row row-sm">
    <div class="col-lg-5 mg-b-20">
      <div class="card shadow-base">
        <div class="card-header"><h6 class="card-title tx-14 mg-b-0"><?= $showNew ? 'New booking' : 'Schedule booking' ?></h6></div>
        <div class="card-body">
          <form method="post">
            <input type="hidden" name="save_booking" value="1">
            <div class="form-group"><label>Title *</label><input name="title" class="form-control" required></div>
            <div class="form-group"><label>Scheduled at *</label><input name="scheduled_at" type="datetime-local" class="form-control" required></div>
            <div class="form-group"><label>Duration (minutes)</label><input name="duration_minutes" type="number" min="15" step="15" class="form-control" value="60"></div>
            <div class="form-group"><label>Contact</label>
              <select name="contact_id" class="form-control">
                <option value="0">— None —</option>
                <?php foreach ($contacts as $c): ?>
                  <option value="<?= (int)$c['id'] ?>" <?= $prefillContactId === (int)$c['id'] ? 'selected' : '' ?>><?= org_crm_h((string)$c['full_name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group"><label>Fieldworker</label>
              <select name="fieldworker_member_id" class="form-control">
                <option value="0">— Unassigned —</option>
                <?php foreach ($members as $m): ?>
                  <option value="<?= (int)$m['id'] ?>"><?= org_crm_h((string)$m['display_name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group"><label>Location</label><input name="location" class="form-control" placeholder="Address or site"></div>
            <div class="form-group"><label>Status</label>
              <select name="status" class="form-control">
                <?php foreach (['scheduled','in_progress','completed','cancelled','no_show'] as $s): ?>
                  <option value="<?= $s ?>"><?= ucfirst(str_replace('_',' ',$s)) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <label class="ckbox mg-b-15"><input type="checkbox" name="is_repeat" value="1"> <span>Repeat booking</span></label>
            <div class="form-group"><label>Notes</label><textarea name="notes" class="form-control" rows="2"></textarea></div>
            <button type="submit" class="btn btn-primary">Save booking</button>
          </form>
        </div>
      </div>
    </div>

    <div class="col-lg-7">
      <div class="card shadow-base mg-b-20">
        <div class="card-header"><h6 class="card-title tx-14 mg-b-0">Upcoming bookings</h6></div>
        <div class="card-body pd-0">
          <table class="table table-hover mg-b-0">
            <thead><tr><th>When</th><th>Service</th><th>Contact</th><th>Fieldworker</th><th>Status</th></tr></thead>
            <tbody>
            <?php
              $upcoming = array_values(array_filter($bookings, static fn($b) => in_array($b['status'] ?? '', ['scheduled','in_progress'], true)));
              if (!$upcoming): ?>
              <tr><td colspan="5" class="text-center tx-color-03">No upcoming bookings.</td></tr>
            <?php else: foreach ($upcoming as $b):
              $fwName = '—';
              foreach ($members as $m) {
                  if ((int)$m['id'] === (int)($b['fieldworker_member_id'] ?? 0)) {
                      $fwName = (string)$m['display_name'];
                      break;
                  }
              }
            ?>
              <tr>
                <td class="tx-12"><?= org_crm_h((string)$b['scheduled_at']) ?></td>
                <td><?= org_crm_h((string)$b['title']) ?><?php if (!empty($b['is_repeat'])): ?> <span class="badge badge-info">Repeat</span><?php endif; ?></td>
                <td><?php if (!empty($b['contact_id'])): ?><a href="crm_contact.php?id=<?= (int)$b['contact_id'] ?>"><?= org_crm_h((string)($b['contact_name'] ?? '')) ?></a><?php else: ?>—<?php endif; ?></td>
                <td class="tx-12"><?= org_crm_h($fwName) ?></td>
                <td><span class="badge <?= org_crm_stage_badge((string)$b['status']) ?>"><?= org_crm_h((string)$b['status']) ?></span></td>
              </tr>
            <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <div class="card shadow-base">
        <div class="card-header"><h6 class="card-title tx-14 mg-b-0">Service history</h6></div>
        <div class="card-body pd-0">
          <?php if (!$completed): ?>
            <p class="pd-15 tx-color-03 mg-b-0">No completed services yet.</p>
          <?php else: ?>
            <ul class="list-unstyled mg-b-0">
              <?php foreach (array_slice($completed, 0, 10) as $b): ?>
                <li class="pd-15 border-bottom">
                  <strong><?= org_crm_h((string)$b['title']) ?></strong>
                  <span class="tx-12 tx-color-03 mg-l-5"><?= org_crm_h((string)$b['scheduled_at']) ?></span>
                  <?php if (!empty($b['notes'])): ?><div class="tx-12"><?= org_crm_h((string)$b['notes']) ?></div><?php endif; ?>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>
<?php org_page_shell_close(); ?>
