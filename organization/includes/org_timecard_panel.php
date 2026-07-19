<?php
declare(strict_types=1);

/**
 * Time card panel for sales_management.php#timecard.
 *
 * Expected from including scope:
 * - PDO   $dbh
 * - int   $orgId
 * - int   $memberId
 * - bool  $isManager
 *
 * POST actions (timecard_action) are handled in sales_management.php, which
 * redirects back to #timecard and sets $_SESSION['tc_flash_ok'|'tc_flash_err'].
 */

require_once __DIR__ . '/org_timecard.php';
require_once __DIR__ . '/org_payroll.php';

if (!function_exists('h') && function_exists('org_ecommerce_h')) {
    function h(string $s): string
    {
        return org_ecommerce_h($s);
    }
}

$tcOk = (string)($_SESSION['tc_flash_ok'] ?? '');
$tcErr = (string)($_SESSION['tc_flash_err'] ?? '');
unset($_SESSION['tc_flash_ok'], $_SESSION['tc_flash_err']);

$me = org_timecard_member($dbh, $orgId, $memberId);
$myName = trim((string)($me['name'] ?? 'Employee'));
$myOpen = org_timecard_open_entry($dbh, $orgId, $memberId);
$myEntries = org_timecard_list_for_member($dbh, $orgId, $memberId, 30);
$orgStats = $isManager ? org_timecard_org_stats($dbh, $orgId) : [];
$orgEntries = $isManager ? org_timecard_list_for_org($dbh, $orgId, 80) : [];

// Pay rates the manager set in Payroll → surfaced here so hours line up with pay.
$payRateMap = [];
foreach (org_payroll_list_employees($dbh, $orgId) as $emp) {
    $payRateMap[(int)($emp['org_member_id'] ?? 0)] = $emp;
}
$myPay = $payRateMap[$memberId] ?? null;

// Home address gate: an employee must have a home address (street, city, state) on
// file before submitting a time card, so payroll knows their state for taxes.
$myAddr = function_exists('org_member_address_get') ? org_member_address_get($dbh, $orgId, $memberId) : null;
$tcHasAddress = is_array($myAddr)
    && trim((string)($myAddr['line1'] ?? '')) !== ''
    && trim((string)($myAddr['city'] ?? '')) !== ''
    && trim((string)($myAddr['state'] ?? '')) !== '';

/** Short "$X.XX/hr" (or Salary/—) label for a payroll profile row. */
$tcRateLabel = static function (?array $emp): string {
    if (!$emp) {
        return 'Not set in Payroll';
    }
    $payType = strtolower((string)($emp['pay_type'] ?? 'salary'));
    $rate = (int)($emp['hourly_rate_cents'] ?? 0);
    if ($rate > 0) {
        return org_payroll_format_cents($rate) . '/hr';
    }
    if ($payType === 'salary' && (int)($emp['annual_salary_cents'] ?? 0) > 0) {
        return 'Salary';
    }
    return 'Not set in Payroll';
};
?>
<style>
  .tc-wrap{max-width:1040px;}
  /* Fixed-height panel: header (title, clock, log hours) and bottom metrics stay
     put; only the rows inside "My recent time cards" scroll. */
  .tc-wrap{
    display:flex;
    flex-direction:column;
    height:calc(100vh - var(--org-header-h, 48px) - 36px);
    min-height:520px;
    overflow:hidden;
  }
  .tc-wrap > *{flex:0 0 auto;}
  .tc-recent-card{flex:1 1 auto;min-height:0;display:flex;flex-direction:column;}
  .tc-recent-card > .tc-card-head{flex:0 0 auto;}
  .tc-recent-card > .tc-table-wrap{flex:1 1 auto;min-height:0;max-height:none;overflow-y:auto;}
  .tc-head{margin-bottom:1px;}
  .tc-head h4{margin:0;font-weight:850;}
  .tc-head p{margin:4px 0 0;font-size:13px;opacity:.8;}
  .tc-clock-card{border:1px solid rgba(148,163,184,.35);border-radius:10px;padding:10px 10px;background:var(--card-bg,#fff);margin-bottom:18px;}
  .tc-clock-status{display:flex;flex-wrap:wrap;align-items:center;gap:12px;margin-bottom:14px;}
  .tc-status-pill{display:inline-flex;align-items:center;gap:7px;padding:6px 12px;border-radius:999px;font-size:12px;font-weight:800;}
  .tc-status-pill.on{background:rgba(34,197,94,.15);color:#15803d;}
  .tc-status-pill.off{background:rgba(148,163,184,.18);color:#475569;}
  .tc-rate-pill{display:inline-flex;align-items:center;gap:6px;padding:6px 12px;border-radius:999px;font-size:12px;font-weight:800;background:rgba(59,130,246,.12);color:#1d4ed8;}
  .tc-since{font-size:13px;opacity:.8;}
  .tc-clock-form{display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;}
  .tc-clock-form .tc-note{flex:1 1 240px;}
  .tc-clock-form label{display:block;font-size:11px;font-weight:700;margin-bottom:4px;opacity:.8;}
  .tc-metrics{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-bottom:1px;}
  .tc-metric{border:1px solid rgba(148,163,184,.35);border-radius:8px;padding:12px 14px;background:var(--card-bg,transparent);}
  .tc-metric strong{display:block;font-size:18px;font-weight:850;}
  .tc-metric span{display:block;margin-top:4px;font-size:12px;opacity:.75;}
  .tc-card{border:1px solid rgba(148,163,184,.35);border-radius:8px;background:var(--card-bg,transparent);overflow:hidden;margin-bottom:18px;}
  .tc-card-head{padding:10px 12px;border-bottom:1px solid rgba(148,163,184,.25);font-weight:800;font-size:13px;}
  .tc-table-wrap{overflow:auto;}
  .tc-table-scroll{max-height:260px;overflow-y:auto;}
  .tc-table-scroll thead th{position:sticky;top:0;z-index:1;background:var(--card-bg,#fff);box-shadow:inset 0 -1px 0 rgba(148,163,184,.35);}
  .tc-table{width:100%;border-collapse:collapse;min-width:640px;}
  .tc-table th,.tc-table td{padding:8px 10px;border-bottom:1px solid rgba(148,163,184,.22);font-size:12px;text-align:left;vertical-align:middle;white-space:nowrap;}
  .tc-table th{font-size:10px;text-transform:uppercase;letter-spacing:.04em;opacity:.75;}
  .tc-table tr:last-child td{border-bottom:0;}
  .tc-badge{display:inline-block;padding:2px 7px;border-radius:999px;font-size:10px;font-weight:800;}
  .tc-badge.on{background:rgba(34,197,94,.15);color:#15803d;}
  .tc-badge.done{background:rgba(148,163,184,.18);color:#475569;}
  .tc-badge.draft{background:rgba(148,163,184,.18);color:#475569;}
  .tc-badge.submitted{background:rgba(245,158,11,.16);color:#b45309;}
  .tc-badge.approved{background:rgba(34,197,94,.15);color:#15803d;}
  .tc-badge.rejected{background:rgba(239,68,68,.15);color:#b91c1c;}
  .tc-type{display:inline-block;padding:2px 7px;border-radius:6px;font-size:10px;font-weight:800;background:rgba(59,130,246,.12);color:#1d4ed8;}
  .tc-log-form{display:grid;grid-template-columns:1fr 1fr 1fr 2fr auto;gap:10px;align-items:end;}
  .tc-log-form label{display:block;font-size:11px;font-weight:700;margin-bottom:4px;opacity:.8;}
  .tc-inline-form{display:inline;}
  @media (max-width:800px){.tc-log-form{grid-template-columns:1fr 1fr;}}
  .tc-empty{padding:18px 10px;text-align:center;font-size:13px;opacity:.8;}
  @media (max-width:900px){.tc-metrics{grid-template-columns:1fr 1fr;}}
  @media (max-width:600px){.tc-metrics{grid-template-columns:1fr;}}
  @media (max-width:820px){
    .tc-wrap{height:auto;min-height:0;overflow:visible;}
    .tc-recent-card{flex:0 0 auto;}
    .tc-recent-card > .tc-table-wrap{max-height:320px;}
  }
  .tc-metric-btn{cursor:pointer;text-align:left;font:inherit;color:inherit;width:100%;transition:border-color .15s ease,background .15s ease;}
  .tc-metric-btn:hover{border-color:#f59e0b;background:rgba(245,158,11,.08);}
  .tc-metric-btn span{display:flex;align-items:center;gap:4px;}
  .tc-modal{position:fixed;inset:0;z-index:12060;display:none;align-items:center;justify-content:center;padding:20px;}
  .tc-modal.is-open{display:flex;}
  .tc-modal-backdrop{position:absolute;inset:0;background:rgba(15,23,42,.55);}
  .tc-modal-dialog{position:relative;z-index:1;display:flex;flex-direction:column;width:min(880px,100%);height:min(88vh,760px);overflow:hidden;border:1px solid rgba(148,163,184,.35);border-radius:10px;background:var(--card-bg,#fff);color:inherit;padding:20px;box-shadow:0 18px 48px rgba(0,0,0,.35);}
  .tc-modal-dialog h3,.tc-modal-dialog > p,.tc-modal-tabs{flex:0 0 auto;}
  .tc-modal-dialog .tc-table-wrap{flex:1 1 auto;min-height:0;overflow:auto;}
  .tc-modal-dialog > div[style*="margin-top"]{flex:0 0 auto;}
  .tc-modal-dialog h3{margin:0 0 6px;font-size:18px;font-weight:850;padding-right:40px;}
  .tc-modal-dialog > p{margin:0 0 14px;font-size:13px;opacity:.8;}
  .tc-modal-close{position:absolute;top:12px;right:12px;width:34px;height:34px;border:0;border-radius:50%;background:rgba(148,163,184,.2);color:inherit;font-size:22px;line-height:1;cursor:pointer;}
  .tc-modal-close:hover{background:rgba(148,163,184,.35);}
  .tc-modal-dialog .tc-table thead th{position:sticky;top:0;background:var(--card-bg,#fff);z-index:1;}
  .tc-modal-tabs{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:12px;}
  .tc-modal-tab{display:inline-flex;align-items:center;gap:6px;padding:6px 12px;border:1px solid rgba(148,163,184,.35);border-radius:999px;background:transparent;color:inherit;font-size:12px;font-weight:700;cursor:pointer;transition:all .15s ease;}
  .tc-modal-tab:hover{border-color:#f59e0b;}
  .tc-modal-tab.is-active{background:#f59e0b;border-color:#f59e0b;color:#111827;}
  .tc-modal-tab-count{display:inline-flex;align-items:center;justify-content:center;min-width:18px;height:18px;padding:0 5px;border-radius:999px;background:rgba(148,163,184,.25);font-size:11px;font-weight:800;}
  .tc-modal-tab.is-active .tc-modal-tab-count{background:rgba(17,24,39,.18);}

  /* Follow gear-tab appearance selection (dark-auto "On" + custom palette) */
  html.dark-auto .tc-wrap .tc-clock-card,
  html.dark-auto .tc-wrap .tc-metric,
  html.dark-auto .tc-wrap .tc-card,
  html.dark-auto .tc-wrap .tc-table-scroll thead th,
  html.dark-auto .tc-modal-dialog,
  html.dark-auto .tc-modal-dialog .tc-table thead th{
    background:#171d24 !important;
    color:#e8edf5 !important;
  }
  html[data-msb-appearance] .tc-wrap .tc-clock-card,
  html[data-msb-appearance] .tc-wrap .tc-metric,
  html[data-msb-appearance] .tc-wrap .tc-card,
  html[data-msb-appearance] .tc-wrap .tc-table-scroll thead th,
  html[data-msb-appearance] .tc-modal-dialog,
  html[data-msb-appearance] .tc-modal-dialog .tc-table thead th{
    background:var(--msb-palette-bg) !important;
    color:var(--msb-palette-text) !important;
  }
</style>

<div class="tc-wrap">
  <div class="tc-head">
    <p class="sales-management-kicker">Time card</p>
    <h1>Track your hours</h1>
    
  </div>

  <?php if ($isManager): ?>
    <div class="tc-metrics">
      <div class="tc-metric"><strong><?= (int)($orgStats['on_clock'] ?? 0) ?></strong><span>On the clock now</span></div>
      <button type="button" class="tc-metric tc-metric-btn" id="tcPendingBtn" title="View pending time cards">
        <strong><?= (int)($orgStats['pending'] ?? 0) ?></strong><span>Pending approval &rsaquo;</span>
      </button>
      <div class="tc-metric"><strong><?= h(number_format((float)($orgStats['hours_today'] ?? 0), 2)) ?></strong><span>Hours logged today</span></div>
      <div class="tc-metric"><strong><?= h(number_format((float)($orgStats['hours_week'] ?? 0), 2)) ?></strong><span>Hours (last 7 days)</span></div>
    </div>
  <?php endif; ?>

  <?php if ($tcOk !== ''): ?><div class="alert alert-success"><?= h($tcOk) ?></div><?php endif; ?>
  <?php if ($tcErr !== ''): ?><div class="alert alert-danger"><?= h($tcErr) ?></div><?php endif; ?>

  <?php if (!$tcHasAddress): ?>
    <div class="alert alert-warning" style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
      <span>
        <i class="icon ion-android-home"></i>
        <strong>Add your home address before submitting.</strong>
        Payroll uses your state to calculate Federal and State tax. Add your street, city, and state in Settings.
      </span>
      <a href="sales_management.php#settings" class="btn btn-primary btn-sm" data-sales-nav="settings">Add home address</a>
    </div>
  <?php endif; ?>

  <div class="tc-clock-card">
    <div class="tc-clock-status">
      <strong style="font-size:15px;"><?= h($myName) ?></strong>
      <span class="tc-rate-pill"><i class="icon ion-cash"></i> <?= h($tcRateLabel($myPay)) ?></span>
      <?php if ($myOpen): ?>
        <span class="tc-status-pill on"><i class="icon ion-record"></i> Clocked in</span>
        <span class="tc-since">Since <?= h(org_timecard_fmt((string)($myOpen['clock_in'] ?? ''))) ?>
          · <?= h(org_timecard_duration_label(org_timecard_entry_duration_seconds($myOpen))) ?> so far</span>
      <?php else: ?>
        <span class="tc-status-pill off"><i class="icon ion-ios-circle-outline"></i> Not clocked in</span>
      <?php endif; ?>
    </div>
    <form method="post" action="sales_management.php#timecard" class="tc-clock-form">
      <div class="tc-note">
        <label for="tcNote">Note (optional) <p>Clock in/out or log hours &amp; leave, then <strong>Submit</strong> your timesheet. Your manager approves it in <a href="sales_management.php#payroll" data-sales-nav="payroll">Payroll</a>, and approved hours flow into pay.</p></label>
        <input class="form-control form-control-sm" type="text" id="tcNote" name="note" maxlength="255" placeholder="Shift, task, or break note…">
      </div>
      <?php if ($myOpen): ?>
        <input type="hidden" name="timecard_action" value="clock_out">
        <button type="submit" class="btn btn-danger btn-sm">Clock out</button>
      <?php else: ?>
        <input type="hidden" name="timecard_action" value="clock_in">
        <button type="submit" class="btn btn-success btn-sm">Clock in</button>
      <?php endif; ?>
    </form>
  </div>

  <div class="tc-card">
    <div class="tc-card-head">Log hours or leave</div>
    <div style="padding:14px 16px;">
      <p class="tx-12 tx-color-03" style="margin:0 0 10px;">Add worked hours (Regular / Overtime) or paid leave (PTO, Sick, Holiday, Vacation) for a day. These are submitted for manager approval like clock punches.</p>
      <form method="post" action="sales_management.php#timecard" class="tc-log-form">
        <input type="hidden" name="timecard_action" value="log_hours">
        <div>
          <label for="tcDate">Date</label>
          <input class="form-control form-control-sm" type="date" id="tcDate" name="entry_date" value="<?= h(date('Y-m-d')) ?>" required>
        </div>
        <div>
          <label for="tcType">Type</label>
          <select class="form-control form-control-sm" id="tcType" name="entry_type">
            <?php foreach (org_timecard_entry_types() as $val => $lbl): ?>
              <option value="<?= h($val) ?>"><?= h($lbl) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label for="tcHours">Hours</label>
          <input class="form-control form-control-sm" type="number" step="0.25" min="0" max="24" id="tcHours" name="hours" placeholder="8" required>
        </div>
        <div>
          <label for="tcLogNote">Note (optional)</label>
          <input class="form-control form-control-sm" type="text" id="tcLogNote" name="note" maxlength="255" placeholder="Reason or task…">
        </div>
        <div>
          <button type="submit" class="btn btn-primary btn-sm">Log</button>
        </div>
      </form>
    </div>
  </div>

  <div class="tc-card tc-recent-card">
    <div class="tc-card-head" style="display:flex;justify-content:space-between;align-items:center;gap:8px;">
      <span>My recent time cards</span>
      <?php if ($tcHasAddress): ?>
        <form method="post" action="sales_management.php#timecard" class="tc-inline-form" onsubmit="return confirm('Submit all draft entries for manager approval?');">
          <input type="hidden" name="timecard_action" value="submit_timecard">
          <button type="submit" class="btn btn-outline-primary btn-sm">Submit timesheet</button>
        </form>
      <?php else: ?>
        <a href="sales_management.php#settings" class="btn btn-outline-secondary btn-sm" data-sales-nav="settings" title="Add your home address first">Add address to submit</a>
      <?php endif; ?>
    </div>
    <div class="tc-table-wrap tc-table-scroll">
      <table class="tc-table">
        <thead>
          <tr><th>Type</th><th>Clock in</th><th>Clock out</th><th>Worked</th><th>Status</th><th>Note</th><th>Review</th></tr>
        </thead>
        <tbody>
          <?php if (!$myEntries): ?>
            <tr><td colspan="7" class="tc-empty">No time cards yet. Clock in or log hours to start.</td></tr>
          <?php else: foreach ($myEntries as $e):
            $open = empty($e['clock_out']);
            $status = strtolower((string)($e['status'] ?? 'draft'));
            $eid = (int)($e['id'] ?? 0);
          ?>
            <tr>
              <td><span class="tc-type"><?= h(org_timecard_entry_type_label((string)($e['entry_type'] ?? 'regular'))) ?></span></td>
              <td><?= h(org_timecard_fmt((string)($e['clock_in'] ?? ''))) ?></td>
              <td><?= $open ? '—' : h(org_timecard_fmt((string)($e['clock_out'] ?? ''))) ?></td>
              <td><?= h(org_timecard_duration_label(org_timecard_entry_duration_seconds($e))) ?></td>
              <td><?= $open
                    ? '<span class="tc-badge on">On clock</span>'
                    : '<span class="tc-badge ' . h($status) . '">' . h(org_timecard_status_label($status)) . '</span>' ?></td>
              <td><?= h((string)($e['note'] ?? '')) ?></td>
              <td>
                <?php if ($open): ?>
                  <span class="tx-12 tx-color-03">Clock out first</span>
                <?php elseif ($status === 'draft' || $status === 'rejected'): ?>
                  <?php if ($tcHasAddress): ?>
                    <form method="post" action="sales_management.php#timecard" class="tc-inline-form">
                      <input type="hidden" name="timecard_action" value="submit_entry">
                      <input type="hidden" name="entry_id" value="<?= $eid ?>">
                      <button type="submit" class="btn btn-primary btn-sm">Submit</button>
                    </form>
                  <?php else: ?>
                    <a href="sales_management.php#settings" class="tx-12" data-sales-nav="settings" title="Add your home address first">Add address</a>
                  <?php endif; ?>
                <?php elseif ($status === 'submitted'): ?>
                  <span class="tc-badge submitted">Pending</span>
                <?php else: ?>
                  <span class="tc-badge approved">Approved</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <?php if ($isManager): ?>
    <?php $tcPending = array_values(array_filter($orgEntries, static function ($e) {
        return strtolower((string)($e['status'] ?? '')) === 'submitted';
    })); ?>
    <div class="tc-modal" id="tcPendingModal" aria-hidden="true">
      <div class="tc-modal-backdrop" data-close-tc-modal></div>
      <div class="tc-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="tcPendingModalTitle">
        <button type="button" class="tc-modal-close" data-close-tc-modal aria-label="Close">&times;</button>
        <h3 id="tcPendingModalTitle">Team time cards</h3>
        <p>Review and approve time cards in <a href="sales_management.php#payroll" data-sales-nav="payroll">Payroll</a>. Only approved hours feed each employee’s Gross Pay.</p>
        <?php
          // Bucket every entry by status for the filter tabs.
          $tcBuckets = ['all' => [], 'on' => [], 'submitted' => [], 'approved' => [], 'rejected' => [], 'draft' => []];
          foreach ($orgEntries as $e) {
              $key = empty($e['clock_out']) ? 'on' : strtolower((string)($e['status'] ?? 'draft'));
              if (!isset($tcBuckets[$key])) { $tcBuckets[$key] = []; }
              $tcBuckets['all'][] = $e;
              $tcBuckets[$key][] = $e;
          }
          $tcTabDefs = [
              'all' => 'All',
              'submitted' => 'Pending',
              'approved' => 'Approved',
              'rejected' => 'Rejected',
              'draft' => 'Draft',
              'on' => 'On clock',
          ];
        ?>
        <div class="tc-modal-tabs" role="tablist">
          <?php foreach ($tcTabDefs as $key => $label):
            $cnt = count($tcBuckets[$key] ?? []);
            if ($key !== 'all' && $cnt === 0) { continue; }
          ?>
            <button type="button" class="tc-modal-tab<?= $key === 'all' ? ' is-active' : '' ?>" data-tc-tab="<?= h($key) ?>">
              <?= h($label) ?> <span class="tc-modal-tab-count"><?= $cnt ?></span>
            </button>
          <?php endforeach; ?>
        </div>
        <div class="tc-table-wrap">
          <table class="tc-table">
            <thead>
              <tr><th>Employee</th><th>Rate</th><th>Type</th><th>Clock in</th><th>Clock out</th><th>Worked</th><th>Status</th></tr>
            </thead>
            <tbody id="tcModalRows">
              <?php if (!$orgEntries): ?>
                <tr><td colspan="7" class="tc-empty">No employee time cards yet.</td></tr>
              <?php else: foreach ($orgEntries as $e):
                $open = empty($e['clock_out']);
                $status = strtolower((string)($e['status'] ?? 'draft'));
                $rowKey = $open ? 'on' : $status;
                $eMemberId = (int)($e['org_member_id'] ?? 0);
              ?>
                <tr data-tc-status="<?= h($rowKey) ?>">
                  <td><?= h((string)($e['employee_name'] ?? 'Employee')) ?></td>
                  <td><?= h($tcRateLabel($payRateMap[$eMemberId] ?? null)) ?></td>
                  <td><span class="tc-type"><?= h(org_timecard_entry_type_label((string)($e['entry_type'] ?? 'regular'))) ?></span></td>
                  <td><?= h(org_timecard_fmt((string)($e['clock_in'] ?? ''))) ?></td>
                  <td><?= $open ? '—' : h(org_timecard_fmt((string)($e['clock_out'] ?? ''))) ?></td>
                  <td><?= h(org_timecard_duration_label(org_timecard_entry_duration_seconds($e))) ?></td>
                  <td><?= $open
                        ? '<span class="tc-badge on">On clock</span>'
                        : '<span class="tc-badge ' . h($status) . '">' . h(org_timecard_status_label($status)) . '</span>' ?></td>
                </tr>
              <?php endforeach; endif; ?>
              <tr id="tcModalEmpty" style="display:none;"><td colspan="7" class="tc-empty">No time cards in this status.</td></tr>
            </tbody>
          </table>
        </div>
        <div style="margin-top:14px;display:flex;gap:8px;flex-wrap:wrap;">
          <?php if ((int)($orgStats['pending'] ?? 0) > 0): ?>
            <a class="btn btn-success btn-sm" href="sales_management.php#payroll" data-sales-nav="payroll">Approve in Payroll (<?= (int)($orgStats['pending'] ?? 0) ?>)</a>
          <?php endif; ?>
          <button type="button" class="btn btn-outline-secondary btn-sm" data-close-tc-modal>Close</button>
        </div>
      </div>
    </div>

    <script>
    (function () {
      var modal = document.getElementById('tcPendingModal');
      var openBtn = document.getElementById('tcPendingBtn');
      if (!modal || !openBtn) return;
      function open() { modal.classList.add('is-open'); modal.setAttribute('aria-hidden', 'false'); document.body.style.overflow = 'hidden'; }
      function close() { modal.classList.remove('is-open'); modal.setAttribute('aria-hidden', 'true'); document.body.style.overflow = ''; }
      openBtn.addEventListener('click', open);
      modal.querySelectorAll('[data-close-tc-modal]').forEach(function (el) { el.addEventListener('click', close); });
      document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && modal.classList.contains('is-open')) close(); });

      var tabs = modal.querySelectorAll('[data-tc-tab]');
      var rows = modal.querySelectorAll('#tcModalRows tr[data-tc-status]');
      var emptyRow = document.getElementById('tcModalEmpty');
      tabs.forEach(function (tab) {
        tab.addEventListener('click', function () {
          var want = tab.getAttribute('data-tc-tab');
          tabs.forEach(function (t) { t.classList.toggle('is-active', t === tab); });
          var shown = 0;
          rows.forEach(function (r) {
            var match = want === 'all' || r.getAttribute('data-tc-status') === want;
            r.style.display = match ? '' : 'none';
            if (match) shown++;
          });
          if (emptyRow) emptyRow.style.display = shown === 0 ? '' : 'none';
        });
      });
    })();
    </script>
  <?php endif; ?>
</div>
