<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/session_org.php';
require_once __DIR__ . '/includes/org_context.php';
require_once __DIR__ . '/includes/org_manager_guard.php';
org_require_manager();
require_once __DIR__ . '/includes/org_crm_lifecycle.php';

$orgId = (int)orgActiveOrgId();
$memberId = (int)orgMemberId();
org_crm_lifecycle_ensure_schema($dbh);

$err = '';
$ok = '';
$tab = strtolower(trim((string)($_GET['tab'] ?? 'feedback')));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['save_feedback'])) {
        $res = org_crm_save_feedback($dbh, $orgId, $_POST);
        $ok = !empty($res['ok']) ? 'Feedback recorded.' : (string)($res['error'] ?? 'Save failed.');
    } elseif (isset($_POST['save_campaign'])) {
        $res = org_crm_save_campaign($dbh, $orgId, $_POST, $memberId);
        $ok = !empty($res['ok']) ? 'Campaign created.' : (string)($res['error'] ?? 'Save failed.');
    }
}

$lifecycle = org_crm_lifecycle_stats($dbh, $orgId);
$crmStats = org_crm_dashboard_stats($dbh, $orgId);
$feedback = org_crm_list_feedback($dbh, $orgId);
$campaigns = org_crm_list_campaigns($dbh, $orgId);
$contacts = org_crm_list_contacts($dbh, $orgId, 'customer', '', 300);
$repeatBookings = array_values(array_filter(org_crm_list_bookings($dbh, $orgId), static fn($b) => !empty($b['is_repeat'])));

$pageTitle = 'Retain';
require_once __DIR__ . '/includes/org_page_shell.php';
org_page_shell_open($pageTitle);
?>
<div class="sh-pagebody">
  <div class="mg-b-20">
    <a href="crm.php" class="tx-12">&larr; CRM lifecycle</a>
    <h4 class="mg-b-0">Retain</h4>
    <p class="tx-color-03">Feedback, campaigns, repeat bookings, and lifecycle reporting.</p>
  </div>

  <?php if ($err): ?><div class="alert alert-danger"><?= org_crm_h($err) ?></div><?php endif; ?>
  <?php if ($ok): ?><div class="alert alert-success"><?= org_crm_h($ok) ?></div><?php endif; ?>

  <div class="row row-sm mg-b-20">
    <div class="col-md-3 col-6"><div class="card shadow-base"><div class="card-body">
      <div class="tx-10 tx-uppercase tx-color-03">Avg rating</div>
      <div class="tx-24 tx-bold"><?= org_crm_h((string)$lifecycle['feedback_avg']) ?> / 5</div>
    </div></div></div>
    <div class="col-md-3 col-6"><div class="card shadow-base"><div class="card-body">
      <div class="tx-10 tx-uppercase tx-color-03">Customers</div>
      <div class="tx-24 tx-bold"><?= (int)$crmStats['customers'] ?></div>
    </div></div></div>
    <div class="col-md-3 col-6"><div class="card shadow-base"><div class="card-body">
      <div class="tx-10 tx-uppercase tx-color-03">Repeat bookings</div>
      <div class="tx-24 tx-bold"><?= count($repeatBookings) ?></div>
    </div></div></div>
    <div class="col-md-3 col-6"><div class="card shadow-base"><div class="card-body">
      <div class="tx-10 tx-uppercase tx-color-03">Campaigns</div>
      <div class="tx-24 tx-bold"><?= (int)$lifecycle['campaigns_active'] ?></div>
    </div></div></div>
  </div>

  <ul class="nav nav-tabs mg-b-20">
    <li class="nav-item"><a class="nav-link<?= $tab === 'feedback' ? ' active' : '' ?>" href="crm_retain.php?tab=feedback">Feedback</a></li>
    <li class="nav-item"><a class="nav-link<?= $tab === 'campaigns' ? ' active' : '' ?>" href="crm_retain.php?tab=campaigns">Campaigns</a></li>
    <li class="nav-item"><a class="nav-link<?= $tab === 'repeat' ? ' active' : '' ?>" href="crm_retain.php?tab=repeat">Repeat bookings</a></li>
    <li class="nav-item"><a class="nav-link<?= $tab === 'reporting' ? ' active' : '' ?>" href="crm_retain.php?tab=reporting">Reporting</a></li>
  </ul>

  <?php if ($tab === 'feedback'): ?>
  <div class="row row-sm">
    <div class="col-lg-4 mg-b-20">
      <div class="card shadow-base">
        <div class="card-header"><h6 class="card-title tx-14 mg-b-0">Record feedback</h6></div>
        <div class="card-body">
          <form method="post">
            <input type="hidden" name="save_feedback" value="1">
            <div class="form-group"><label>Contact</label>
              <select name="contact_id" class="form-control">
                <option value="0">— None —</option>
                <?php foreach ($contacts as $c): ?>
                  <option value="<?= (int)$c['id'] ?>"><?= org_crm_h((string)$c['full_name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group"><label>Rating</label>
              <select name="rating" class="form-control">
                <?php for ($i = 5; $i >= 1; $i--): ?>
                  <option value="<?= $i ?>"><?= $i ?> stars</option>
                <?php endfor; ?>
              </select>
            </div>
            <div class="form-group"><label>Comment</label><textarea name="comment" class="form-control" rows="3"></textarea></div>
            <button type="submit" class="btn btn-primary">Save feedback</button>
          </form>
        </div>
      </div>
    </div>
    <div class="col-lg-8 mg-b-20">
      <div class="card shadow-base">
        <div class="card-header"><h6 class="card-title tx-14 mg-b-0">Recent feedback</h6></div>
        <div class="card-body pd-0">
          <?php if (!$feedback): ?>
            <p class="pd-15 tx-color-03">No feedback yet.</p>
          <?php else: foreach ($feedback as $f): ?>
            <div class="pd-15 border-bottom">
              <strong><?= str_repeat('★', (int)$f['rating']) ?><?= str_repeat('☆', 5 - (int)$f['rating']) ?></strong>
              <span class="tx-12 tx-color-03 mg-l-10"><?= org_crm_h((string)($f['contact_name'] ?? '')) ?></span>
              <?php if (!empty($f['comment'])): ?><div class="mg-t-5"><?= org_crm_h((string)$f['comment']) ?></div><?php endif; ?>
            </div>
          <?php endforeach; endif; ?>
        </div>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($tab === 'campaigns'): ?>
  <div class="card shadow-base">
    <div class="card-header"><h6 class="card-title tx-14 mg-b-0">Marketing campaigns</h6></div>
    <div class="card-body">
      <form method="post" class="mg-b-20 pd-15" style="background:#f8fafc;border-radius:12px;">
        <input type="hidden" name="save_campaign" value="1">
        <div class="row">
          <div class="col-md-4 form-group"><label>Campaign name *</label><input name="name" class="form-control" required></div>
          <div class="col-md-3 form-group"><label>Channel</label>
            <select name="channel" class="form-control">
              <?php foreach (['email','sms','social','other'] as $ch): ?>
                <option value="<?= $ch ?>"><?= ucfirst($ch) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-3 form-group"><label>Schedule</label><input name="scheduled_at" type="datetime-local" class="form-control"></div>
          <div class="col-md-12 form-group"><label>Message</label><textarea name="message" class="form-control" rows="3"></textarea></div>
        </div>
        <button type="submit" class="btn btn-primary">Create campaign</button>
      </form>
      <table class="table table-hover mg-b-0">
        <thead><tr><th>Name</th><th>Channel</th><th>Status</th><th>Scheduled</th></tr></thead>
        <tbody>
        <?php if (!$campaigns): ?>
          <tr><td colspan="4" class="text-center tx-color-03">No campaigns yet.</td></tr>
        <?php else: foreach ($campaigns as $camp): ?>
          <tr>
            <td><?= org_crm_h((string)$camp['name']) ?></td>
            <td><?= org_crm_h((string)$camp['channel']) ?></td>
            <td><span class="badge badge-light"><?= org_crm_h((string)$camp['status']) ?></span></td>
            <td class="tx-12"><?= org_crm_h((string)($camp['scheduled_at'] ?? '—')) ?></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($tab === 'repeat'): ?>
  <div class="card shadow-base">
    <div class="card-header d-flex justify-content-between">
      <h6 class="card-title tx-14 mg-b-0">Repeat bookings</h6>
      <a href="crm_bookings.php?new=1" class="btn btn-sm btn-primary">Schedule repeat</a>
    </div>
    <div class="card-body pd-0">
      <?php if (!$repeatBookings): ?>
        <p class="pd-15 tx-color-03">No repeat bookings flagged yet. Check "Repeat booking" when scheduling in Serve.</p>
      <?php else: ?>
        <table class="table table-hover mg-b-0">
          <thead><tr><th>When</th><th>Service</th><th>Contact</th><th>Status</th></tr></thead>
          <tbody>
          <?php foreach ($repeatBookings as $b): ?>
            <tr>
              <td><?= org_crm_h((string)$b['scheduled_at']) ?></td>
              <td><?= org_crm_h((string)$b['title']) ?></td>
              <td><?= org_crm_h((string)($b['contact_name'] ?? '—')) ?></td>
              <td><?= org_crm_h((string)$b['status']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($tab === 'reporting'): ?>
  <div class="row row-sm">
    <div class="col-md-6 mg-b-20">
      <div class="card shadow-base">
        <div class="card-header"><h6 class="card-title tx-14 mg-b-0">Revenue &amp; pipeline</h6></div>
        <div class="card-body">
          <p><strong>Pipeline:</strong> <?= org_crm_h(org_crm_money((int)$crmStats['pipeline_cents'])) ?></p>
          <p><strong>Forecast:</strong> <?= org_crm_h(org_crm_money((int)$crmStats['forecast_cents'])) ?></p>
          <p><strong>Won MTD:</strong> <?= org_crm_h(org_crm_money((int)$crmStats['won_mtd_cents'])) ?></p>
          <a href="crm_deals.php" class="btn btn-sm btn-outline-primary">Deals pipeline</a>
          <a href="commerce_analytics.php" class="btn btn-sm btn-outline-secondary mg-l-5">Commerce analytics</a>
        </div>
      </div>
    </div>
    <div class="col-md-6 mg-b-20">
      <div class="card shadow-base">
        <div class="card-header"><h6 class="card-title tx-14 mg-b-0">Lifecycle funnel</h6></div>
        <div class="card-body">
          <p><strong>Leads captured MTD:</strong> <?= (int)$lifecycle['capture_mtd'] ?></p>
          <p><strong>Open quotes:</strong> <?= (int)$lifecycle['quotes_open'] ?></p>
          <p><strong>Customers:</strong> <?= (int)$crmStats['customers'] ?></p>
          <p><strong>Unpaid invoices:</strong> <?= (int)$lifecycle['invoices_unpaid'] ?></p>
          <p><strong>Avg satisfaction:</strong> <?= org_crm_h((string)$lifecycle['feedback_avg']) ?> / 5</p>
        </div>
      </div>
    </div>
  </div>
  <?php endif; ?>
</div>
<?php org_page_shell_close(); ?>
