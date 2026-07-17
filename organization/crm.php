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

$stats = org_crm_dashboard_stats($dbh, $orgId);
$lifecycle = org_crm_lifecycle_stats($dbh, $orgId);
$activity = org_crm_recent_activity($dbh, $orgId, 8);

$pageTitle = 'CRM';
require_once __DIR__ . '/includes/org_page_shell.php';
org_page_shell_open($pageTitle);
?>
<style>
.crm-lifecycle { display:flex; flex-direction:column; align-items:center; gap:12px; }
.crm-stage-row { display:flex; flex-wrap:wrap; justify-content:center; gap:12px; width:100%; }
.crm-stage {
  flex:1; min-width:140px; max-width:200px; text-align:center; padding:16px 12px; border-radius:12px;
  background:var(--bg-card, var(--msb-palette-bg, #f8fafc));
  border:1px solid var(--border-color, #e2e8f0);
  text-decoration:none; color:var(--text-primary, inherit); transition:box-shadow .15s;
}
.crm-stage:hover { box-shadow:0 4px 12px rgba(0,0,0,.08); text-decoration:none; color:var(--text-primary, inherit); }
.crm-stage strong { display:block; font-size:14px; margin-bottom:4px; color:var(--text-primary, inherit); }
.crm-stage .crm-count { font-size:22px; font-weight:700; color:var(--msb-palette-action, #0861bc); }
.crm-stage .crm-sub { font-size:11px; color:var(--text-muted, #64748b); }
.crm-hub { width:100%; max-width:520px; margin:8px 0; padding:24px; border-radius:50%; aspect-ratio:1; display:flex; flex-direction:column; align-items:center; justify-content:center; background:linear-gradient(135deg,var(--msb-palette-action-strong, #0861bc),var(--msb-palette-action, #0ea5e9)); color:#fff; text-align:center; }
.crm-hub h5 { color:#fff; margin:0 0 8px; font-weight:700; }
.crm-hub-modules { display:flex; flex-wrap:wrap; justify-content:center; gap:6px; margin-top:8px; }
.crm-hub-modules a { font-size:11px; padding:4px 8px; background:rgba(255,255,255,.2); border-radius:20px; color:#fff; text-decoration:none; }
.crm-hub-modules a:hover { background:rgba(255,255,255,.35); color:#fff; }
.crm-arrow { color:var(--text-muted, #94a3b8); font-size:20px; }
html[data-msb-appearance] .crm-stage {
  background:var(--msb-palette-bg) !important;
  border-color:var(--msb-palette-border) !important;
  color:var(--msb-palette-text) !important;
}
html[data-msb-appearance] .crm-stage strong { color:var(--msb-palette-text) !important; }
html[data-msb-appearance] .crm-stage .crm-sub,
html[data-msb-appearance] .crm-arrow { color:var(--msb-palette-text-muted, var(--msb-palette-text)) !important; }
@media (max-width:768px) { .crm-hub { max-width:280px; padding:16px; } }
</style>
<?php org_page_body_open(); ?>
  <div class="d-flex flex-wrap justify-content-between align-items-center mg-b-20">
    <div>
      <h4 class="mg-b-5">CRM lifecycle</h4>
      <p class="tx-color-03 mg-b-0">Capture leads → convert deals → serve customers → collect revenue → retain loyalty</p>
    </div>
    <a href="crm_contacts.php?new=1" class="btn btn-primary btn-sm">Add contact</a>
  </div>

  <div class="row row-sm mg-b-20">
    <div class="col-md-3 col-6 mg-b-15"><div class="card shadow-base"><div class="card-body">
      <div class="tx-10 tx-uppercase tx-color-03">Contacts</div>
      <div class="tx-24 tx-bold"><?= (int)$stats['contacts'] ?></div>
    </div></div></div>
    <div class="col-md-3 col-6 mg-b-15"><div class="card shadow-base"><div class="card-body">
      <div class="tx-10 tx-uppercase tx-color-03">Pipeline</div>
      <div class="tx-20 tx-bold"><?= org_crm_h(org_crm_money((int)$stats['pipeline_cents'])) ?></div>
    </div></div></div>
    <div class="col-md-3 col-6 mg-b-15"><div class="card shadow-base"><div class="card-body">
      <div class="tx-10 tx-uppercase tx-color-03">Open tickets</div>
      <div class="tx-24 tx-bold"><?= (int)$stats['open_tickets'] ?></div>
    </div></div></div>
    <div class="col-md-3 col-6 mg-b-15"><div class="card shadow-base"><div class="card-body">
      <div class="tx-10 tx-uppercase tx-color-03">Forecast</div>
      <div class="tx-20 tx-bold"><?= org_crm_h(org_crm_money((int)$stats['forecast_cents'])) ?></div>
    </div></div></div>
  </div>

  <div class="card shadow-base mg-b-20">
    <div class="card-body pd-25">
      <div class="crm-lifecycle">
        <div class="crm-stage-row">
          <a href="crm_capture.php" class="crm-stage">
            <strong>Capture</strong>
            <div class="crm-count"><?= (int)$lifecycle['capture_mtd'] ?></div>
            <div class="crm-sub">Website · Portal · Phone</div>
          </a>
        </div>
        <div class="crm-arrow">↓</div>
        <div class="crm-stage-row">
          <a href="crm_convert.php" class="crm-stage">
            <strong>Convert</strong>
            <div class="crm-count"><?= (int)$lifecycle['quotes_open'] ?></div>
            <div class="crm-sub">Quotes · Reminders · Approvals</div>
          </a>
        </div>
        <div class="crm-arrow">↓</div>
        <div class="crm-hub">
          <h5>CRM Hub</h5>
          <div class="tx-12" style="opacity:.9;">Customer 360° view</div>
          <div class="crm-hub-modules">
            <a href="crm_contacts.php">Profiles</a>
            <a href="crm_contacts.php">Activity</a>
            <a href="crm_contacts.php">Files</a>
            <a href="members.php">Access</a>
            <a href="crm_bookings.php">Fieldworkers</a>
            <a href="crm_contacts.php">Address</a>
            <a href="crm_contacts.php">History</a>
            <a href="messages.php">Messages</a>
          </div>
        </div>
        <div class="crm-arrow">↓</div>
        <div class="crm-stage-row">
          <a href="crm_bookings.php" class="crm-stage">
            <strong>Serve</strong>
            <div class="crm-count"><?= (int)$lifecycle['bookings_upcoming'] ?></div>
            <div class="crm-sub">Bookings · Field context · Service history</div>
          </a>
        </div>
        <div class="crm-arrow">↓</div>
        <div class="crm-stage-row">
          <a href="crm_invoices.php" class="crm-stage">
            <strong>Collect</strong>
            <div class="crm-count"><?= (int)$lifecycle['invoices_unpaid'] ?></div>
            <div class="crm-sub">Invoices · Statements · Payments</div>
          </a>
          <a href="crm_retain.php" class="crm-stage">
            <strong>Retain</strong>
            <div class="crm-count"><?= org_crm_h((string)$lifecycle['feedback_avg']) ?>★</div>
            <div class="crm-sub">Feedback · Campaigns · Reporting</div>
          </a>
        </div>
      </div>
    </div>
  </div>

  <div class="row row-sm">
    <div class="col-lg-6 mg-b-20">
      <div class="card shadow-base">
        <div class="card-header d-flex justify-content-between">
          <h6 class="card-title tx-14 mg-b-0">Due reminders</h6>
          <a href="crm_convert.php" class="tx-12">View all</a>
        </div>
        <div class="card-body pd-0">
          <?php $reminders = org_crm_list_reminders($dbh, $orgId, 'pending', 5); ?>
          <?php if (!$reminders): ?>
            <p class="pd-15 tx-color-03 mg-b-0">No pending reminders.</p>
          <?php else: foreach ($reminders as $r): ?>
            <div class="pd-15 border-bottom">
              <strong><?= org_crm_h((string)$r['title']) ?></strong>
              <div class="tx-12 tx-color-03">Due <?= org_crm_h((string)$r['due_at']) ?></div>
            </div>
          <?php endforeach; endif; ?>
        </div>
      </div>
    </div>
    <div class="col-lg-6 mg-b-20">
      <div class="card shadow-base">
        <div class="card-header"><h6 class="card-title tx-14 mg-b-0">Recent activity</h6></div>
        <div class="card-body pd-0">
          <?php if (!$activity): ?>
            <p class="pd-15 tx-color-03 mg-b-0">No activity yet.</p>
          <?php else: ?>
            <table class="table table-hover mg-b-0">
              <tbody>
              <?php foreach ($activity as $a): ?>
                <tr>
                  <td class="tx-12"><?= org_crm_h((string)($a['created_at'] ?? '')) ?></td>
                  <td><a href="crm_contact.php?id=<?= (int)$a['contact_id'] ?>"><?= org_crm_h((string)($a['contact_name'] ?? '')) ?></a></td>
                  <td><?= org_crm_h((string)$a['interaction_type']) ?></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>
<?php org_page_shell_close(); ?>
