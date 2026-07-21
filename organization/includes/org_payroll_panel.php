<?php
declare(strict_types=1);

/**
 * Payroll panel for sales_management.php#payroll.
 *
 * Expected vars:
 * - PDO $dbh
 * - int $orgId
 * - array $payrollStats
 * - list $payrollEmployees
 * - list $payrollRuns
 * - array|null $payrollActiveRun
 * - list $payrollActiveLines
 * - array $payrollPeriodHours       org_member_id => worked_seconds
 * - array $payrollPeriodBreakdown    org_member_id => {regular_secs,overtime_secs,paid_leave_secs}
 * - string $payrollOk
 * - string $payrollErr
 * - string $payrollFormAction
 */

if (!function_exists('h') && function_exists('org_ecommerce_h')) {
    function h(string $s): string
    {
        return org_ecommerce_h($s);
    }
}

$payrollStats = is_array($payrollStats ?? null) ? $payrollStats : [];
$payrollEmployees = is_array($payrollEmployees ?? null) ? $payrollEmployees : [];
$payrollRuns = is_array($payrollRuns ?? null) ? $payrollRuns : [];
$payrollActiveRun = is_array($payrollActiveRun ?? null) ? $payrollActiveRun : null;
$payrollActiveLines = is_array($payrollActiveLines ?? null) ? $payrollActiveLines : [];
$payrollPeriodHours = is_array($payrollPeriodHours ?? null) ? $payrollPeriodHours : [];
$payrollPeriodBreakdown = is_array($payrollPeriodBreakdown ?? null) ? $payrollPeriodBreakdown : [];
$payrollPendingTimecards = is_array($payrollPendingTimecards ?? null) ? $payrollPendingTimecards : [];
$payrollPendingGroups = function_exists('org_timecard_group_submitted')
    ? org_timecard_group_submitted($payrollPendingTimecards)
    : [];
$payrollApprovedMemberIds = is_array($payrollApprovedMemberIds ?? null) ? array_map('intval', $payrollApprovedMemberIds) : [];
$payrollOk = (string)($payrollOk ?? '');
$payrollErr = (string)($payrollErr ?? '');
$payrollFormAction = (string)($payrollFormAction ?? 'sales_management.php');
$activeRunId = (int)($payrollActiveRun['id'] ?? 0);
$activeStatus = strtolower((string)($payrollActiveRun['status'] ?? 'draft'));
$isPaid = $activeStatus === 'paid';
$isApproved = $activeStatus === 'approved';
$isDraft = $activeRunId > 0 && $activeStatus === 'draft';

$etaxRates = function_exists('org_payroll_employer_tax_rates')
    ? org_payroll_employer_tax_rates()
    : ['social' => 0.062, 'medicare' => 0.0145, 'unemp' => 0.006, 'workers' => 0.010];

$sumGross = 0;
$sumDed = 0;
$sumNet = 0;
$sumEtax = 0;
foreach ($payrollActiveLines as $line) {
    $sumGross += (int)($line['gross_cents'] ?? 0);
    $sumDed += (int)($line['deductions_cents'] ?? 0);
    $sumNet += (int)($line['net_cents'] ?? 0);
    $sumEtax += (int)($line['employer_tax_cents'] ?? 0);
}

// Employee FICA (federal, applies in every state): the worker's own share.
$payrollFicaRates = [
    'social' => (float)($etaxRates['social'] ?? 0.062),
    'medicare' => (float)($etaxRates['medicare'] ?? 0.0145),
];

// States with no wage income tax — State tax should be $0 for employees living there.
$payrollNoTaxStates = ['AK', 'FL', 'NV', 'NH', 'SD', 'TN', 'TX', 'WA', 'WY'];
$payrollNoTaxStateNames = 'AK, FL, NV, NH, SD, TN, TX, WA, WY';

// Simplified withholding ESTIMATES used to auto-fill the deduction fields.
// These are rough effective rates a manager can override — not exact IRS tables.
$payrollFederalRateByStatus = ['single' => 0.12, 'married' => 0.10, 'head' => 0.11];
$payrollStateTaxRate = [
    'CA' => 0.060, 'NY' => 0.060, 'IL' => 0.0495, 'PA' => 0.0307, 'MA' => 0.050,
    'GA' => 0.0549, 'NJ' => 0.050, 'VA' => 0.0575, 'OH' => 0.035, 'MI' => 0.0425,
    'CO' => 0.044, 'AZ' => 0.025, 'NC' => 0.045, 'MD' => 0.0475, 'MN' => 0.0535,
    'WI' => 0.0453, 'IN' => 0.0305, 'MO' => 0.048, 'SC' => 0.064, 'OR' => 0.0875,
];
$payrollStateDefaultRate = 0.05;

// Each employee's state on file (from their home address) so we can flag whether
// state income tax applies. (Home vs. work state can differ; this is a helpful default.)
$payrollMemberState = [];
if (function_exists('org_member_address_map') && (int)($orgId ?? 0) > 0 && isset($dbh)) {
    foreach (org_member_address_map($dbh, (int)$orgId) as $mid => $addrRow) {
        $st = strtoupper(trim((string)($addrRow['state'] ?? '')));
        if ($st !== '') {
            $payrollMemberState[(int)$mid] = $st;
        }
    }
}
/** @return bool|null true=has state tax, false=no state tax, null=unknown */
$payrollStateHasTax = static function (string $state) use ($payrollNoTaxStates): ?bool {
    $s = strtoupper(trim($state));
    if ($s === '') {
        return null;
    }
    $names = ['ALASKA' => 'AK', 'FLORIDA' => 'FL', 'NEVADA' => 'NV', 'NEW HAMPSHIRE' => 'NH', 'SOUTH DAKOTA' => 'SD', 'TENNESSEE' => 'TN', 'TEXAS' => 'TX', 'WASHINGTON' => 'WA', 'WYOMING' => 'WY'];
    if (isset($names[$s])) {
        $s = $names[$s];
    }
    return !in_array($s, $payrollNoTaxStates, true);
};

// Employee profiles keyed by org_member_id, so each pay-run row can carry the
// data needed to auto-fill the edit form (no separate employee picker needed).
$payrollEmpById = [];
foreach ($payrollEmployees as $__emp) {
    $payrollEmpById[(int)($__emp['org_member_id'] ?? 0)] = $__emp;
}

/** Emit the data-* attributes used to auto-fill the edit form for a member. */
$payrollLineDataAttrs = static function (int $mid) use ($payrollEmpById, $payrollPeriodBreakdown, $payrollMemberState, $payrollStateHasTax): string {
    $emp = $payrollEmpById[$mid] ?? [];
    $bd = $payrollPeriodBreakdown[$mid] ?? ['regular_secs' => 0, 'overtime_secs' => 0, 'paid_leave_secs' => 0];
    $state = $payrollMemberState[$mid] ?? '';
    $hasTax = $payrollStateHasTax($state);
    $hasTaxAttr = $hasTax === true ? '1' : ($hasTax === false ? '0' : '');
    $esc = static fn ($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    return ' data-member="' . $mid . '"'
        . ' data-pay-type="' . $esc($emp['pay_type'] ?? 'salary') . '"'
        . ' data-rate="' . number_format(((int)($emp['hourly_rate_cents'] ?? 0)) / 100, 2, '.', '') . '"'
        . ' data-weekly-hours="' . number_format((float)($emp['expected_weekly_hours'] ?? 40), 2, '.', '') . '"'
        . ' data-reg-hours="' . number_format(((int)($bd['regular_secs'] ?? 0)) / 3600, 2, '.', '') . '"'
        . ' data-ot-hours="' . number_format(((int)($bd['overtime_secs'] ?? 0)) / 3600, 2, '.', '') . '"'
        . ' data-leave-hours="' . number_format(((int)($bd['paid_leave_secs'] ?? 0)) / 3600, 2, '.', '') . '"'
        . ' data-gross="' . number_format(((int)($emp['default_gross_cents'] ?? 0)) / 100, 2, '.', '') . '"'
        . ' data-ded="' . number_format(((int)($emp['default_deductions_cents'] ?? 0)) / 100, 2, '.', '') . '"'
        . ' data-state="' . $esc($state) . '"'
        . ' data-hastax="' . $hasTaxAttr . '"'
        . ' data-tax="' . $esc($emp['tax_status'] ?? 'single') . '"';
};

?>
<style>
  .org-payroll-panel{margin-top:4px;}
  .org-payroll-metrics{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin:1px 0 5px;}
  .org-payroll-metric{border:1px solid rgba(148,163,184,.35);border-radius:8px;padding:12px 14px;background:var(--card-bg,transparent);}
  .org-payroll-metric strong{display:block;font-size:18px;font-weight:850;line-height:1.2;}
  .org-payroll-metric span{display:block;margin-top:4px;font-size:12px;opacity:.75;}
  .org-payroll-grid{display:grid;grid-template-columns:minmax(240px,300px) minmax(0,1fr);gap:14px;align-items:start;}
  .org-payroll-card{border:1px solid rgba(148,163,184,.35);border-radius:8px;background:var(--card-bg,transparent);overflow:hidden;}
  .org-payroll-card-head{padding:10px 12px;border-bottom:1px solid rgba(148,163,184,.25);font-weight:800;font-size:13px;display:flex;align-items:center;justify-content:space-between;gap:8px;}
  .org-payroll-card-body{padding:12px;}

  /* Fixed-height panel: the header (title, metrics, Time card approvals) and the
     left "Pay runs" box stay put; only the Pay run detail scrolls up/down. */
  .org-payroll-panel{
    display:flex;
    flex-direction:column;
    height:calc(100vh - var(--org-header-h, 48px) - 36px);
    min-height:520px;
    overflow:hidden;
  }
  .org-payroll-panel > .sales-management-detail-head,
  .org-payroll-panel > .alert,
  .org-payroll-panel > .org-payroll-metrics,
  .org-payroll-panel > .org-payroll-card{ flex:0 0 auto; }
  .org-payroll-grid{ flex:1 1 auto; min-height:0; overflow:hidden; }
  .org-payroll-grid > .org-payroll-card{ min-height:0; max-height:100%; display:flex; flex-direction:column; }
  .org-payroll-grid > .org-payroll-card > .org-payroll-card-body{ flex:1 1 auto; min-height:0; overflow-y:auto; }
  .org-payroll-detail .org-payroll-card-head{ position:sticky; top:0; z-index:2; background:var(--msb-palette-bg, var(--card-bg, #171d24)); }

  /* Time card approvals: box + its title stay; only the rows scroll inside it. */
  .org-payroll-approvals{ display:flex; flex-direction:column; max-height:42vh; }
  .org-payroll-approvals > .org-payroll-card-head{ flex:0 0 auto; }
  .org-payroll-approvals > .org-payroll-card-body{ flex:1 1 auto; min-height:0; overflow-y:auto; }
  .org-payroll-approvals .org-payroll-table thead th{ position:sticky; top:0; z-index:1; background:var(--msb-palette-bg, var(--card-bg, #171d24)); }
  .org-payroll-tc-groups{display:flex;flex-direction:column;gap:12px;}
  .org-payroll-tc-group{
    border:1px solid rgba(148,163,184,.4);border-radius:8px;overflow:hidden;
    background:rgba(148,163,184,.04);
  }
  .org-payroll-tc-group + .org-payroll-tc-group{margin-top:2px;}
  .org-payroll-tc-group-head{
    display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;
    padding:10px 12px;border-bottom:1px solid rgba(148,163,184,.28);
    background:rgba(59,130,246,.1);
  }
  .org-payroll-tc-group-head strong{font-size:14px;font-weight:850;}
  .org-payroll-tc-group-meta{font-size:12px;opacity:.8;margin-top:2px;}
  .org-payroll-tc-group-actions{display:flex;gap:6px;flex-wrap:wrap;}
  .org-payroll-tc-day{padding:8px 12px 10px;border-top:1px dashed rgba(148,163,184,.28);}
  .org-payroll-tc-day:first-of-type{border-top:0;}
  .org-payroll-tc-day-head{
    display:flex;align-items:baseline;justify-content:space-between;gap:8px;
    margin:0 0 6px;font-size:12px;font-weight:800;
  }
  .org-payroll-tc-day-head span{font-weight:600;opacity:.75;}
  .org-payroll-tc-group .org-payroll-table{min-width:640px;margin:0;}
  .org-payroll-tc-group .org-payroll-table td.tc-type-break{opacity:.85;font-style:italic;}
  .org-payroll-help{font-size:12px;line-height:1.55;opacity:.85;margin:0 0 12px;}
  .org-payroll-help strong{font-weight:800;}
  .org-payroll-form-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px;}
  .org-payroll-form-grid label,.org-payroll-item-form label{display:block;font-size:11px;font-weight:700;margin-bottom:4px;opacity:.8;}
  .org-payroll-form-grid .full{grid-column:1 / -1;}
  .org-payroll-run-list{display:flex;flex-direction:column;gap:6px;max-height:420px;overflow:auto;}
  .org-payroll-run-item{display:block;padding:9px 10px;border:1px solid rgba(148,163,184,.28);border-radius:6px;text-decoration:none;color:inherit;}
  .org-payroll-run-item:hover,.org-payroll-run-item.is-active{background:rgba(148,163,184,.12);text-decoration:none;}
  .org-payroll-run-item strong{display:block;font-size:13px;}
  .org-payroll-run-item span{display:block;font-size:11px;opacity:.75;margin-top:2px;}
  .org-payroll-badge{display:inline-block;padding:2px 7px;border-radius:999px;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.04em;}
  .org-payroll-badge.draft{background:rgba(245,158,11,.15);color:#b45309;}
  .org-payroll-badge.approved{background:rgba(59,130,246,.15);color:#1d4ed8;}
  .org-payroll-badge.paid{background:rgba(34,197,94,.15);color:#15803d;}
  .org-payroll-table-wrap{overflow:auto;border:1px solid rgba(148,163,184,.28);border-radius:6px;}
  .org-payroll-table{width:100%;border-collapse:collapse;min-width:860px;}
  .org-payroll-table th,.org-payroll-table td{padding:8px 10px;border-bottom:1px solid rgba(148,163,184,.22);font-size:12px;text-align:left;vertical-align:middle;}
  .org-payroll-table th{font-size:10px;text-transform:uppercase;letter-spacing:.04em;opacity:.75;}
  .org-payroll-table tr:last-child td{border-bottom:0;}
  .org-payroll-table .num{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap;}
  .org-payroll-table tfoot td{font-weight:800;border-top:1px solid rgba(148,163,184,.35);}
  .org-payroll-actions{display:flex;flex-wrap:wrap;gap:8px;margin-top:12px;}
  .org-payroll-empty{padding:18px 10px;text-align:center;font-size:13px;opacity:.8;}
  .org-payroll-note{font-size:11px;opacity:.7;margin-top:8px;}
  /* Itemized add-line form */
  .org-payroll-item-form{margin-top:14px;border:1px dashed rgba(148,163,184,.4);border-radius:8px;padding:12px;}
  .org-payroll-fieldset{margin:10px 0;}
  .org-payroll-fieldset > h5{font-size:11px;font-weight:850;text-transform:uppercase;letter-spacing:.05em;opacity:.7;margin:0 0 6px;}
  .org-payroll-tag{display:inline-block;font-size:9px;font-weight:800;text-transform:uppercase;letter-spacing:.04em;padding:1px 5px;border-radius:6px;vertical-align:middle;background:rgba(148,163,184,.22);color:inherit;opacity:.9;}
  .org-payroll-tag.req{background:rgba(220,38,38,.16);color:#dc2626;}
  .org-payroll-tag.opt{background:rgba(148,163,184,.18);}
  .org-payroll-tag.notax{background:rgba(22,163,74,.16);color:#16a34a;}
  .org-payroll-tag.hastax{background:rgba(37,99,235,.16);color:#2563eb;}
  .org-payroll-hint{display:block;margin-top:3px;font-size:10px;line-height:1.35;opacity:.75;}
  .org-payroll-inputs{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:8px;}
  .org-payroll-inputs .with-hint small{display:block;font-size:10px;opacity:.6;margin-top:2px;}
  .org-payroll-topline{display:grid;grid-template-columns:2fr 1.2fr auto;gap:8px;align-items:end;}
  .org-payroll-topline-save{min-width:88px;}
  .org-payroll-perhour{
    margin:10px 0 12px;padding:12px;border:1px solid rgba(59,130,246,.35);border-radius:8px;
    background:rgba(59,130,246,.04);
  }
  .org-payroll-perhour h6{margin:0 0 4px;font-size:12px;font-weight:850;}
  .org-payroll-perhour > p{margin:0 0 10px;font-size:11px;line-height:1.35;opacity:.8;}
  .org-payroll-perhour-grid{display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;align-items:end;}
  .org-payroll-perhour-grid label{display:block;font-size:11px;font-weight:700;margin-bottom:2px;opacity:.8;}
  @media (max-width:700px){
    .org-payroll-perhour-grid{grid-template-columns:1fr;}
  }
  .org-payroll-totals{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:8px;margin-top:10px;}
  .org-payroll-total{border:1px solid rgba(148,163,184,.3);border-radius:6px;padding:8px 10px;}
  .org-payroll-total span{display:block;font-size:10px;text-transform:uppercase;letter-spacing:.04em;opacity:.7;}
  .org-payroll-total strong{display:block;font-size:15px;font-weight:850;font-variant-numeric:tabular-nums;}
  .org-payroll-etax-toggle{display:flex;gap:14px;align-items:center;font-size:12px;margin-bottom:8px;}
  @media (max-width:1100px){
    .org-payroll-metrics{grid-template-columns:1fr 1fr;}
    .org-payroll-grid{grid-template-columns:1fr;}
    .org-payroll-inputs{grid-template-columns:repeat(3,minmax(0,1fr));}
    .org-payroll-totals{grid-template-columns:1fr 1fr;}
    /* Stack normally on narrow screens — no fixed-height split view. */
    .org-payroll-panel{height:auto;min-height:0;overflow:visible;}
    .org-payroll-grid{overflow:visible;}
    .org-payroll-grid > .org-payroll-card{max-height:none;display:block;}
    .org-payroll-grid > .org-payroll-card > .org-payroll-card-body{overflow-y:visible;}
    .org-payroll-detail .org-payroll-card-head{position:static;}
    .org-payroll-approvals{max-height:none;}
  }
  @media (max-width:700px){
    .org-payroll-metrics{grid-template-columns:1fr;}
    .org-payroll-form-grid{grid-template-columns:1fr;}
    .org-payroll-inputs{grid-template-columns:1fr 1fr;}
    .org-payroll-topline{grid-template-columns:1fr;}
  }
</style>

<div class="org-payroll-panel">
  <div class="sales-management-detail-head">
    <div>
      <p class="sales-management-kicker">Payroll</p>
      <h1>Pay employees</h1>
    </div>
    <div>
      <a class="btn btn-outline-primary btn-sm" href="payroll.php"><i class="icon ion-cash"></i> View pay rates</a>
    </div>
  </div>

  <?php if ($payrollOk !== ''): ?><div class="alert alert-success"><?= h($payrollOk) ?></div><?php endif; ?>
  <?php if ($payrollErr !== ''): ?><div class="alert alert-danger"><?= h($payrollErr) ?></div><?php endif; ?>

  <div class="org-payroll-metrics">
    <div class="org-payroll-metric">
      <strong><?= (int)($payrollStats['employees'] ?? 0) ?></strong>
      <span>Staff employees</span>
    </div>
    <div class="org-payroll-metric">
      <strong><?= (int)($payrollStats['open_runs'] ?? 0) ?></strong>
      <span>Open pay runs</span>
    </div>
    <div class="org-payroll-metric">
      <strong><?= h(org_payroll_format_cents((int)($payrollStats['net_paid_cents'] ?? 0))) ?></strong>
      <span>Net pay paid</span>
    </div>
    <div class="org-payroll-metric">
      <strong><?= h(org_payroll_format_cents((int)($payrollStats['employer_tax_cents'] ?? 0))) ?></strong>
      <span>Employer taxes paid</span>
    </div>
  </div>

  <div class="org-payroll-card org-payroll-approvals" style="margin-bottom:14px;">
    <div class="org-payroll-card-head">
      <span>Time card approvals<?= $payrollPendingTimecards ? ' (' . count($payrollPendingTimecards) . ' pending)' : '' ?></span>
      <?php if ($payrollPendingTimecards): ?>
        <form method="post" action="<?= h($payrollFormAction) ?>#payroll" onsubmit="return confirm('Approve all submitted time cards?');" style="margin:0;">
          <input type="hidden" name="payroll_action" value="timecard_approve_all">
          <button type="submit" class="btn btn-success btn-sm">Approve all</button>
        </form>
      <?php endif; ?>
    </div>
    <div class="org-payroll-card-body">
      <p class="org-payroll-help">
        When a staff member or manager taps <strong>Submit</strong> on the <a href="sales_management.php#timecard">Time card</a>, their hours land here as
        <strong>Pending</strong>, grouped by person and by day (Regular, Break, and other types for the same day stay together).
        Each employee is in a separate block so reviews stay clear. Approve them and those hours flow into Gross Pay (and their <a href="account.php">Account</a>);
        the Time card status turns <strong>Approved</strong>. Their name then appears under Employee below.
        Rejected entries go back to fix and resubmit.
      </p>
      <?php if (!$payrollPendingGroups): ?>
        <div class="org-payroll-empty">No time cards waiting for approval.</div>
      <?php else: ?>
        <div class="org-payroll-tc-groups">
          <?php foreach ($payrollPendingGroups as $group):
            $gMid = (int)($group['org_member_id'] ?? 0);
            $gName = (string)($group['employee_name'] ?? 'Employee');
            $gRole = trim((string)($group['employee_role'] ?? ''));
            $gCount = (int)($group['entry_count'] ?? 0);
            $gHours = number_format(((int)($group['total_seconds'] ?? 0)) / 3600, 2);
            $gDays = is_array($group['days'] ?? null) ? $group['days'] : [];
          ?>
            <section class="org-payroll-tc-group" aria-label="<?= h($gName) ?> time cards">
              <div class="org-payroll-tc-group-head">
                <div>
                  <strong><?= h($gName) ?></strong>
                  <?php if ($gRole !== ''): ?>
                    <span class="org-payroll-note" style="margin-left:6px;">(<?= h($gRole) ?>)</span>
                  <?php endif; ?>
                  <div class="org-payroll-tc-group-meta">
                    <?= (int)$gCount ?> entr<?= $gCount === 1 ? 'y' : 'ies' ?>
                    · <?= h($gHours) ?> hrs total
                    · <?= count($gDays) ?> day<?= count($gDays) === 1 ? '' : 's' ?>
                  </div>
                </div>
                <?php if ($gMid > 0): ?>
                  <div class="org-payroll-tc-group-actions">
                    <form method="post" action="<?= h($payrollFormAction) ?>#payroll" style="margin:0;" onsubmit="return confirm('Approve all pending time cards for <?= h($gName) ?>?');">
                      <input type="hidden" name="payroll_action" value="timecard_approve_member">
                      <input type="hidden" name="org_member_id" value="<?= $gMid ?>">
                      <button type="submit" class="btn btn-success btn-sm">Approve <?= h($gName) ?></button>
                    </form>
                    <form method="post" action="<?= h($payrollFormAction) ?>#payroll" style="margin:0;" onsubmit="return confirm('Reject all pending time cards for <?= h($gName) ?>?');">
                      <input type="hidden" name="payroll_action" value="timecard_reject_member">
                      <input type="hidden" name="org_member_id" value="<?= $gMid ?>">
                      <button type="submit" class="btn btn-outline-danger btn-sm">Reject all</button>
                    </form>
                  </div>
                <?php endif; ?>
              </div>
              <?php foreach ($gDays as $day):
                $dayLabel = (string)($day['date_label'] ?? '');
                $dayHours = number_format(((int)($day['total_seconds'] ?? 0)) / 3600, 2);
                $dayEntries = is_array($day['entries'] ?? null) ? $day['entries'] : [];
              ?>
                <div class="org-payroll-tc-day">
                  <div class="org-payroll-tc-day-head">
                    <strong><?= h($dayLabel) ?></strong>
                    <span><?= h($dayHours) ?> hrs · <?= count($dayEntries) ?> type<?= count($dayEntries) === 1 ? '' : 's' ?></span>
                  </div>
                  <div class="org-payroll-table-wrap">
                    <table class="org-payroll-table">
                      <thead>
                        <tr>
                          <th>Type</th>
                          <th>Time</th>
                          <th class="num">Hours</th>
                          <th>Note</th>
                          <th>Review</th>
                        </tr>
                      </thead>
                      <tbody>
                        <?php foreach ($dayEntries as $tc):
                          $tcId = (int)($tc['id'] ?? 0);
                          $tcSecs = (int)($tc['worked_seconds'] ?? 0);
                          $tcTypeRaw = strtolower((string)($tc['entry_type'] ?? 'regular'));
                          $tcType = function_exists('org_timecard_entry_type_label')
                              ? org_timecard_entry_type_label($tcTypeRaw)
                              : ucfirst($tcTypeRaw);
                          $tcUnpaid = function_exists('org_timecard_is_unpaid_type') && org_timecard_is_unpaid_type($tcTypeRaw);
                          $cin = (string)($tc['clock_in'] ?? '');
                          $cout = (string)($tc['clock_out'] ?? '');
                          $timeLabel = '';
                          if ($cin !== '') {
                              $timeLabel = date('g:i A', strtotime($cin));
                              if ($cout !== '') {
                                  $timeLabel .= ' – ' . date('g:i A', strtotime($cout));
                              }
                          }
                        ?>
                          <tr>
                            <td class="<?= $tcTypeRaw === 'break' ? 'tc-type-break' : '' ?>">
                              <?= h($tcType) ?><?= $tcUnpaid ? ' <span class="org-payroll-note">(unpaid)</span>' : '' ?>
                            </td>
                            <td><?= h($timeLabel) ?></td>
                            <td class="num"><?= h(number_format($tcSecs / 3600, 2)) ?></td>
                            <td><?= h((string)($tc['note'] ?? '')) ?></td>
                            <td style="display:flex;gap:6px;">
                              <form method="post" action="<?= h($payrollFormAction) ?>#payroll" style="margin:0;">
                                <input type="hidden" name="payroll_action" value="timecard_approve">
                                <input type="hidden" name="entry_id" value="<?= $tcId ?>">
                                <button type="submit" class="btn btn-success btn-sm">Approve</button>
                              </form>
                              <form method="post" action="<?= h($payrollFormAction) ?>#payroll" style="margin:0;">
                                <input type="hidden" name="payroll_action" value="timecard_reject">
                                <input type="hidden" name="entry_id" value="<?= $tcId ?>">
                                <button type="submit" class="btn btn-outline-danger btn-sm">Reject</button>
                              </form>
                            </td>
                          </tr>
                        <?php endforeach; ?>
                      </tbody>
                    </table>
                  </div>
                </div>
              <?php endforeach; ?>
            </section>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="org-payroll-grid">
    <div class="org-payroll-card">
      <div class="org-payroll-card-head">Pay runs</div>
      <div class="org-payroll-card-body">
        <form method="post" action="<?= h($payrollFormAction) ?>#payroll" class="mg-b-14" id="orgPayrollCreateForm">
          <input type="hidden" name="payroll_action" value="create_run">
          <?php
            $payrollRunEmployees = array_values(array_filter($payrollEmployees, function ($emp) use ($payrollApprovedMemberIds) {
                return in_array((int)($emp['org_member_id'] ?? 0), $payrollApprovedMemberIds, true);
            }));
          ?>
          <div class="org-payroll-form-grid">
            <div class="full">
              <label for="payrollRunMember">Employee</label>
              <select class="form-control form-control-sm" id="payrollRunMember" name="run_member_id">
                <option value="0" data-freq="">All (staff &amp; managers with unpaid approved time cards)</option>
                <?php foreach ($payrollRunEmployees as $emp):
                  $mid = (int)($emp['org_member_id'] ?? 0);
                  if ($mid <= 0) {
                      continue;
                  }
                  $ename = trim((string)($emp['name'] ?? 'Team member'));
                  $erole = (string)($emp['member_type'] ?? '');
                ?>
                  <option value="<?= $mid ?>" data-freq="<?= h((string)($emp['pay_frequency'] ?? 'monthly')) ?>"><?= h($ename) ?><?= $erole !== '' ? ' (' . h($erole) . ')' : '' ?></option>
                <?php endforeach; ?>
              </select>
              <?php if (!$payrollRunEmployees): ?>
                <p class="org-payroll-note" style="margin-top:6px;">No one waiting for a pay run. Approve a staff or manager time card above to add a name here. After you Approve payroll, that name leaves this list until they have new approved hours.</p>
              <?php else: ?>
                <p class="org-payroll-note" style="margin-top:6px;">Select a staff member or manager with approved time cards, then Start pay run. Approving payroll removes them from this list (hours are compensated and do not return).</p>
              <?php endif; ?>
            </div>
            <div class="full">
              <label for="payrollFrequency">Pay frequency</label>
              <select class="form-control form-control-sm" id="payrollFrequency" name="pay_frequency">
                <option value="weekly">Weekly</option>
                <option value="bi_weekly">Bi-weekly</option>
                <option value="monthly" selected>Monthly</option>
              </select>
            </div>
            <div class="full">
              <label for="payrollLabel">Label (optional)</label>
              <input class="form-control form-control-sm" type="text" id="payrollLabel" name="label" maxlength="120" placeholder="e.g. March payroll">
            </div>
          </div>
          <div class="org-payroll-actions">
            <button type="submit" class="btn btn-primary btn-sm">Start pay run</button>
          </div>
        </form>

        <div class="org-payroll-run-list">
          <?php if (!$payrollRuns): ?>
            <div class="org-payroll-empty">No pay runs yet.</div>
          <?php else: foreach ($payrollRuns as $run):
            $rid = (int)($run['id'] ?? 0);
            $st = strtolower((string)($run['status'] ?? 'draft'));
          ?>
            <a class="org-payroll-run-item<?= $rid === $activeRunId ? ' is-active' : '' ?>" href="sales_management.php?pay_run=<?= $rid ?>#payroll">
              <strong><?= h((string)($run['label'] ?? 'Pay run')) ?></strong>
              <span>
                <?= h((string)($run['period_start'] ?? '')) ?> → <?= h((string)($run['period_end'] ?? '')) ?>
                · <?= (int)($run['employee_count'] ?? 0) ?> employee<?= (int)($run['employee_count'] ?? 0) === 1 ? '' : 's' ?>
                · <span class="org-payroll-badge <?= h($st) ?>"><?= h($st) ?></span>
              </span>
            </a>
          <?php endforeach; endif; ?>
        </div>
      </div>
    </div>

    <div class="org-payroll-card org-payroll-detail">
      <div class="org-payroll-card-head">
        <span><?= $activeRunId > 0 ? h((string)($payrollActiveRun['label'] ?? 'Pay run')) : 'Pay run detail' ?></span>
        <?php if ($activeRunId > 0): ?>
          <span class="org-payroll-badge <?= h($activeStatus) ?>"><?= h($activeStatus) ?></span>
        <?php endif; ?>
      </div>
      <div class="org-payroll-card-body">
        <?php if ($activeRunId <= 0): ?>
          <div class="org-payroll-empty">Select or start a pay run to enter Gross Pay, Deductions, Net Pay, and Employer Taxes.</div>
        <?php else: ?>
          <?php $activeFreqLabel = function_exists('org_payroll_frequency_label')
              ? org_payroll_frequency_label((string)($payrollActiveRun['pay_frequency'] ?? 'monthly'))
              : 'Monthly'; ?>
          <p class="org-payroll-help">
            <?= h($activeFreqLabel) ?> · period <?= h((string)($payrollActiveRun['period_start'] ?? '')) ?>
            to <?= h((string)($payrollActiveRun['period_end'] ?? '')) ?>.
            <strong>Net Pay = Gross − Deductions.</strong>
            Employer Taxes are the employer’s cost and are not taken from Net Pay.
            <?php if ($isDraft): ?>Click <strong>Edit</strong> on an employee row to load and adjust their pay below.<?php endif; ?>
          </p>

          <div class="org-payroll-table-wrap">
            <table class="org-payroll-table">
              <thead>
                <tr>
                  <th>Employee</th>
                  <th class="num">Reg h</th>
                  <th class="num">OT h</th>
                  <th class="num">Rate</th>
                  <th class="num">Gross Pay</th>
                  <th class="num">Deductions</th>
                  <th class="num">Net Pay</th>
                  <th class="num">Employer Taxes</th>
                  <th>Stub</th>
                  <?php if ($isDraft): ?><th></th><?php endif; ?>
                </tr>
              </thead>
              <tbody>
                <?php if (!$payrollActiveLines): ?>
                  <tr><td colspan="<?= $isDraft ? 10 : 9 ?>" class="org-payroll-empty">No employees on this run yet. Add one below<?= count($payrollEmployees) ? '' : ' (hire staff first)' ?>.</td></tr>
                <?php else: foreach ($payrollActiveLines as $line):
                  $lineSecs = (int)($line['worked_seconds'] ?? 0);
                  $lineOtSecs = (int)($line['overtime_seconds'] ?? 0);
                  $lineRegSecs = max(0, $lineSecs - $lineOtSecs);
                  $lineRate = (int)($line['hourly_rate_cents'] ?? 0);
                  $lineId = (int)($line['id'] ?? 0);
                  $lineMid = (int)($line['org_member_id'] ?? 0);
                  $lineWeekHours = (float)(($payrollEmpById[$lineMid]['expected_weekly_hours'] ?? 40));
                  if ($lineWeekHours <= 0) {
                      $lineWeekHours = 40.0;
                  }
                  $lineWeekMaxCents = $lineRate > 0 ? (int)round($lineRate * $lineWeekHours) : 0;
                ?>
                  <tr>
                    <td>
                      <strong><?= h((string)($line['employee_name'] ?? 'Employee')) ?></strong>
                      <div class="org-payroll-note"><?= h((string)($line['employee_role'] ?? '')) ?></div>
                    </td>
                    <td class="num"><?= $lineRegSecs > 0 ? h(number_format($lineRegSecs / 3600, 2)) : '—' ?></td>
                    <td class="num"><?= $lineOtSecs > 0 ? h(number_format($lineOtSecs / 3600, 2)) : '—' ?></td>
                    <td class="num">
                      <?= $lineRate > 0 ? h(org_payroll_format_cents($lineRate)) . '/hr' : '—' ?>
                      <?php if ($lineWeekMaxCents > 0): ?>
                        <div class="org-payroll-note" style="margin-top:2px;">
                          Week max <?= h(org_payroll_format_cents($lineWeekMaxCents)) ?>
                          · <?= h(rtrim(rtrim(number_format($lineWeekHours, 2), '0'), '.')) ?> hrs
                        </div>
                      <?php endif; ?>
                    </td>
                    <td class="num"><?= h(org_payroll_format_cents((int)($line['gross_cents'] ?? 0))) ?></td>
                    <td class="num"><?= h(org_payroll_format_cents((int)($line['deductions_cents'] ?? 0))) ?></td>
                    <td class="num"><?= h(org_payroll_format_cents((int)($line['net_cents'] ?? 0))) ?></td>
                    <td class="num"><?= h(org_payroll_format_cents((int)($line['employer_tax_cents'] ?? 0))) ?></td>
                    <td><a class="btn btn-sm btn-outline-primary" href="paystub.php?line=<?= $lineId ?>" target="_blank" rel="noopener">Stub</a></td>
                    <?php if ($isDraft): ?>
                      <td style="white-space:nowrap;">
                        <button type="button" class="btn btn-sm btn-outline-primary org-payroll-edit"
                          data-name="<?= h((string)($line['employee_name'] ?? 'Employee')) ?>"
                          <?= $payrollLineDataAttrs((int)($line['org_member_id'] ?? 0)) ?>>Edit</button>
                        <form method="post" action="<?= h($payrollFormAction) ?>#payroll" style="display:inline;" onsubmit="return confirm('Remove this employee from the pay run?');">
                          <input type="hidden" name="payroll_action" value="delete_line">
                          <input type="hidden" name="pay_run_id" value="<?= $activeRunId ?>">
                          <input type="hidden" name="line_id" value="<?= $lineId ?>">
                          <button type="submit" class="btn btn-sm btn-outline-danger">Remove</button>
                        </form>
                      </td>
                    <?php endif; ?>
                  </tr>
                <?php endforeach; endif; ?>
              </tbody>
              <tfoot>
                <tr>
                  <td>Totals</td>
                  <td class="num"></td>
                  <td class="num"></td>
                  <td class="num"></td>
                  <td class="num"><?= h(org_payroll_format_cents($sumGross)) ?></td>
                  <td class="num"><?= h(org_payroll_format_cents($sumDed)) ?></td>
                  <td class="num"><?= h(org_payroll_format_cents($sumNet)) ?></td>
                  <td class="num"><?= h(org_payroll_format_cents($sumEtax)) ?></td>
                  <td colspan="<?= $isDraft ? 2 : 1 ?>">
                    Employer cost ≈ <?= h(org_payroll_format_cents($sumNet + $sumEtax)) ?>
                  </td>
                </tr>
              </tfoot>
            </table>
          </div>

          <?php if ($isDraft): ?>
            <form method="post" action="<?= h($payrollFormAction) ?>#payroll" class="org-payroll-item-form" id="orgPayrollLineForm">
              <input type="hidden" name="payroll_action" value="save_line">
              <input type="hidden" name="pay_run_id" value="<?= $activeRunId ?>">
              <input type="hidden" id="payrollMemberId" name="org_member_id" value="">
              <div class="org-payroll-topline">
                <div>
                  <label>Employee</label>
                  <input class="form-control form-control-sm" type="text" id="payrollMemberName" readonly value="" placeholder="Click “Edit” on a row above">
                </div>
                <div>
                  <label>Hours (approved this period)</label>
                  <input class="form-control form-control-sm" type="text" id="payrollHoursHint" readonly value="—">
                </div>
                <div class="org-payroll-topline-save">
                  <label>&nbsp;</label>
                  <button type="submit" class="btn btn-primary btn-sm" style="width:100%;">Save</button>
                </div>
              </div>

              <div class="org-payroll-perhour" id="orgPayrollPerHour">
                <h6>Per hour work</h6>
                <p>Same as Create Staff — set <strong>Per hour rate</strong> and <strong>hours per week</strong>. Week max = rate × hours (Time card income check).</p>
                <div class="org-payroll-perhour-grid">
                  <div>
                    <label for="payrollRate">Per hour rate ($/hr)</label>
                    <input class="form-control form-control-sm" type="number" step="0.01" min="0" id="payrollRate" name="hourly_rate" placeholder="e.g. 35.00" value="0">
                  </div>
                  <div>
                    <label for="payrollWeeklyHours">Hours per week</label>
                    <input class="form-control form-control-sm" type="number" step="0.25" min="0.25" max="168" id="payrollWeeklyHours" name="weekly_hours" placeholder="e.g. 40" value="40">
                  </div>
                  <div>
                    <label for="payrollWeekMax">Week max income</label>
                    <input class="form-control form-control-sm" type="text" id="payrollWeekMax" readonly value="—" style="font-weight:800;">
                  </div>
                </div>
              </div>
              <p class="org-payroll-note" style="margin:0 0 10px;">After changing Per hour work, click <strong>Save</strong> above (or <strong>Save employee line</strong> at the bottom).</p>

              <div class="org-payroll-fieldset">
                <h5>Gross pay (Step 7-8)</h5>
                <div class="org-payroll-inputs">
                  <div><label for="pg_regular">Regular</label><input class="form-control form-control-sm pg" type="number" step="0.01" min="0" id="pg_regular" name="g_regular" value="0"></div>
                  <div><label for="pg_overtime">Overtime</label><input class="form-control form-control-sm pg" type="number" step="0.01" min="0" id="pg_overtime" name="g_overtime" value="0"></div>
                  <div><label for="pg_bonus">Bonus</label><input class="form-control form-control-sm pg" type="number" step="0.01" min="0" id="pg_bonus" name="g_bonus" value="0"></div>
                  <div><label for="pg_commission">Commission</label><input class="form-control form-control-sm pg" type="number" step="0.01" min="0" id="pg_commission" name="g_commission" value="0"></div>
                  <div><label for="pg_holiday">Holiday</label><input class="form-control form-control-sm pg" type="number" step="0.01" min="0" id="pg_holiday" name="g_holiday" value="0"></div>
                  <div><label for="pg_vacation">Vacation</label><input class="form-control form-control-sm pg" type="number" step="0.01" min="0" id="pg_vacation" name="g_vacation" value="0"></div>
                </div>
              </div>

              <div class="org-payroll-fieldset">
                <h5>Deductions (Step 9)</h5>
                <div class="org-payroll-inputs">
                  <div>
                    <label for="pd_federal">Federal tax <span class="org-payroll-tag req">required</span></label>
                    <input class="form-control form-control-sm pd" type="number" step="0.01" min="0" id="pd_federal" name="d_federal" value="0">
                  </div>
                  <div>
                    <label for="pd_state">State tax <span class="org-payroll-tag" id="pd_state_tag">by state</span></label>
                    <input class="form-control form-control-sm pd" type="number" step="0.01" min="0" id="pd_state" name="d_state" value="0">
                    <small class="org-payroll-hint" id="pd_state_hint">Depends on the employee's state.</small>
                  </div>
                  <div><label for="pd_health">Health <span class="org-payroll-tag opt">optional</span></label><input class="form-control form-control-sm pd" type="number" step="0.01" min="0" id="pd_health" name="d_health" value="0"></div>
                  <div><label for="pd_dental">Dental <span class="org-payroll-tag opt">optional</span></label><input class="form-control form-control-sm pd" type="number" step="0.01" min="0" id="pd_dental" name="d_dental" value="0"></div>
                  <div><label for="pd_retirement">401(k) <span class="org-payroll-tag opt">optional</span></label><input class="form-control form-control-sm pd" type="number" step="0.01" min="0" id="pd_retirement" name="d_retirement" value="0"></div>
                  <div><label for="pd_other">Other <span class="org-payroll-tag opt">optional</span></label><input class="form-control form-control-sm pd" type="number" step="0.01" min="0" id="pd_other" name="d_other" value="0"></div>
                </div>
                <p class="org-payroll-help" style="margin:8px 0 0;">
                  When you pick an employee, <strong>Federal</strong> and <strong>State</strong> tax auto-fill as
                  <em>estimates</em> from their gross pay and the state on their home address — you can adjust any amount.
                  <strong>State income tax</strong> depends on where the employee lives — there is
                  <strong>no state income tax</strong> in: <?= h($payrollNoTaxStateNames) ?>.
                  Health, Dental, 401(k) and Other are only deducted if the employee signed up.
                </p>
              </div>

              <div class="org-payroll-fieldset">
                <h5>Employer taxes (Step 11)</h5>
                <div class="org-payroll-etax-toggle">
                  <label style="margin:0;"><input type="radio" name="etax_mode" value="auto" checked> Auto from gross</label>
                  <label style="margin:0;"><input type="radio" name="etax_mode" value="manual"> Enter manually</label>
                </div>
                <div class="org-payroll-inputs" id="payrollEtaxInputs">
                  <div><label for="pe_social">Social Security</label><input class="form-control form-control-sm pe" type="number" step="0.01" min="0" id="pe_social" name="et_social" value="0" readonly></div>
                  <div><label for="pe_medicare">Medicare</label><input class="form-control form-control-sm pe" type="number" step="0.01" min="0" id="pe_medicare" name="et_medicare" value="0" readonly></div>
                  <div><label for="pe_unemp">Unemployment</label><input class="form-control form-control-sm pe" type="number" step="0.01" min="0" id="pe_unemp" name="et_unemp" value="0" readonly></div>
                  <div><label for="pe_workers">Workers' comp</label><input class="form-control form-control-sm pe" type="number" step="0.01" min="0" id="pe_workers" name="et_workers" value="0" readonly></div>
                </div>
              </div>

              <div class="org-payroll-fieldset">
                <label for="payrollLineNote">Note (optional)</label>
                <input class="form-control form-control-sm" type="text" id="payrollLineNote" name="line_note" maxlength="255" placeholder="Adjustment, correction…">
              </div>

              <div class="org-payroll-totals">
                <div class="org-payroll-total"><span>Gross</span><strong id="ptGross">$0.00</strong></div>
                <div class="org-payroll-total"><span>Deductions</span><strong id="ptDed">$0.00</strong></div>
                <div class="org-payroll-total"><span>Net pay</span><strong id="ptNet">$0.00</strong></div>
                <div class="org-payroll-total"><span>Employer taxes</span><strong id="ptEtax">$0.00</strong></div>
                <div class="org-payroll-total"><span>Employer cost</span><strong id="ptCost">$0.00</strong></div>
              </div>

              <div class="org-payroll-actions">
                <button type="submit" class="btn btn-primary btn-sm">Save employee line</button>
              </div>
            </form>

            <div class="org-payroll-actions">
              <form method="post" action="<?= h($payrollFormAction) ?>#payroll" onsubmit="return confirm('Pull the latest approved time cards into this run? Timecard hours refresh; manual bonuses and deductions are kept.');">
                <input type="hidden" name="payroll_action" value="refresh_run">
                <input type="hidden" name="pay_run_id" value="<?= $activeRunId ?>">
                <button type="submit" class="btn btn-outline-primary btn-sm"><i class="icon ion-refresh"></i> Refresh from time cards</button>
              </form>
              <form method="post" action="<?= h($payrollFormAction) ?>#payroll" onsubmit="return confirm('Approve this payroll? It locks amounts for payment.');">
                <input type="hidden" name="payroll_action" value="approve_run">
                <input type="hidden" name="pay_run_id" value="<?= $activeRunId ?>">
                <button type="submit" class="btn btn-primary btn-sm">Approve payroll</button>
              </form>
              <form method="post" action="<?= h($payrollFormAction) ?>#payroll" onsubmit="return confirm('Delete this draft pay run?');">
                <input type="hidden" name="payroll_action" value="delete_run">
                <input type="hidden" name="pay_run_id" value="<?= $activeRunId ?>">
                <button type="submit" class="btn btn-outline-danger btn-sm">Delete draft</button>
              </form>
            </div>
          <?php elseif ($isApproved): ?>
            <p class="org-payroll-note">
              Approved<?= !empty($payrollActiveRun['approved_at']) ? (' on ' . h((string)$payrollActiveRun['approved_at'])) : '' ?>.
              Amounts are locked for review. Mark paid to release payment, or reopen to edit.
            </p>
            <div class="org-payroll-actions">
              <form method="post" action="<?= h($payrollFormAction) ?>#payroll" onsubmit="return confirm('Mark this pay run as paid?');">
                <input type="hidden" name="payroll_action" value="mark_paid">
                <input type="hidden" name="pay_run_id" value="<?= $activeRunId ?>">
                <button type="submit" class="btn btn-success btn-sm">Mark pay run paid</button>
              </form>
              <form method="post" action="<?= h($payrollFormAction) ?>#payroll">
                <input type="hidden" name="payroll_action" value="reopen_run">
                <input type="hidden" name="pay_run_id" value="<?= $activeRunId ?>">
                <button type="submit" class="btn btn-outline-secondary btn-sm">Reopen for edits</button>
              </form>
            </div>
          <?php else: ?>
            <p class="org-payroll-note">
              Paid<?= !empty($payrollActiveRun['paid_at']) ? (' on ' . h((string)$payrollActiveRun['paid_at'])) : '' ?>.
              Paid runs are locked. Employees can view their pay stubs.
            </p>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>

</div>
<script>
(function () {
  var ETAX = <?= json_encode([
      'social' => (float)$etaxRates['social'],
      'medicare' => (float)$etaxRates['medicare'],
      'unemp' => (float)$etaxRates['unemp'],
      'workers' => (float)$etaxRates['workers'],
  ]) ?>;
  // Simplified withholding ESTIMATES (managers can override each amount).
  var FED_RATE = <?= json_encode($payrollFederalRateByStatus) ?>;
  var STATE_RATE = <?= json_encode($payrollStateTaxRate) ?>;
  var STATE_DEFAULT_RATE = <?= json_encode($payrollStateDefaultRate) ?>;
  function num(v) { var n = parseFloat(v); return isNaN(n) ? 0 : n; }
  function money(n) { return '$' + (Math.round(n * 100) / 100).toFixed(2); }

  /* Itemized add-line form. */
  (function () {
    var form = document.getElementById('orgPayrollLineForm');
    if (!form) return;
    var hidden = document.getElementById('payrollMemberId');
    var nameField = document.getElementById('payrollMemberName');
    var rate = document.getElementById('payrollRate');
    var weeklyHours = document.getElementById('payrollWeeklyHours');
    var weekMax = document.getElementById('payrollWeekMax');
    var hoursHint = document.getElementById('payrollHoursHint');
    var pg = Array.prototype.slice.call(form.querySelectorAll('.pg'));
    var pd = Array.prototype.slice.call(form.querySelectorAll('.pd'));
    var pe = { social: document.getElementById('pe_social'), medicare: document.getElementById('pe_medicare'), unemp: document.getElementById('pe_unemp'), workers: document.getElementById('pe_workers') };
    var reg = document.getElementById('pg_regular');
    var ot = document.getElementById('pg_overtime');
    var vac = document.getElementById('pg_vacation');
    var etaxRadios = form.querySelectorAll('input[name="etax_mode"]');

    function syncWeekMax() {
      if (!weekMax) return;
      var r = num(rate && rate.value);
      var h = num(weeklyHours && weeklyHours.value);
      if (!(h > 0)) h = 40;
      if (!(r > 0)) {
        weekMax.value = '—';
        return;
      }
      weekMax.value = money(r * h) + ' / week';
    }

    function etaxManual() {
      var v = form.querySelector('input[name="etax_mode"]:checked');
      return v && v.value === 'manual';
    }
    function grossTotal() { var t = 0; pg.forEach(function (i) { t += num(i.value); }); return t; }
    function dedTotal() { var t = 0; pd.forEach(function (i) { t += num(i.value); }); return t; }

    function recompute() {
      var g = grossTotal();
      var d = dedTotal();
      var net = Math.max(0, g - d);
      var et;
      if (etaxManual()) {
        et = num(pe.social.value) + num(pe.medicare.value) + num(pe.unemp.value) + num(pe.workers.value);
      } else {
        pe.social.value = (g * ETAX.social).toFixed(2);
        pe.medicare.value = (g * ETAX.medicare).toFixed(2);
        pe.unemp.value = (g * ETAX.unemp).toFixed(2);
        pe.workers.value = (g * ETAX.workers).toFixed(2);
        et = num(pe.social.value) + num(pe.medicare.value) + num(pe.unemp.value) + num(pe.workers.value);
      }
      document.getElementById('ptGross').textContent = money(g);
      document.getElementById('ptDed').textContent = money(d);
      document.getElementById('ptNet').textContent = money(net);
      document.getElementById('ptEtax').textContent = money(et);
      document.getElementById('ptCost').textContent = money(net + et);
    }

    function setEtaxReadonly() {
      var manual = etaxManual();
      Object.keys(pe).forEach(function (k) { if (pe[k]) pe[k].readOnly = !manual; });
      recompute();
    }

    // Load an employee's real numbers into the form from a row's "Edit" button.
    function applyFromEl(el) {
      if (!el) return;
      var payType = el.getAttribute('data-pay-type') || 'salary';
      var r = num(el.getAttribute('data-rate'));
      var regH = num(el.getAttribute('data-reg-hours'));
      var otH = num(el.getAttribute('data-ot-hours'));
      var leaveH = num(el.getAttribute('data-leave-hours'));
      if (hidden) hidden.value = el.getAttribute('data-member') || '';
      if (nameField) nameField.value = el.getAttribute('data-name') || '';
      if (rate) rate.value = r.toFixed(2);
      if (weeklyHours) {
        var wh = num(el.getAttribute('data-weekly-hours'));
        weeklyHours.value = (wh > 0 ? wh : 40).toFixed(2);
      }
      syncWeekMax();
      if (hoursHint) hoursHint.value = regH.toFixed(2) + ' reg · ' + otH.toFixed(2) + ' OT · ' + leaveH.toFixed(2) + ' leave';
      if (payType === 'hourly' && r > 0) {
        if (reg) reg.value = (regH * r).toFixed(2);
        if (ot) ot.value = (otH * r * 1.5).toFixed(2);
        if (vac) vac.value = (leaveH * r).toFixed(2);
      } else {
        if (reg) reg.value = num(el.getAttribute('data-gross')).toFixed(2);
        if (ot) ot.value = '0.00';
        if (vac) vac.value = '0.00';
      }
      var dOther = document.getElementById('pd_other');
      if (dOther) dOther.value = num(el.getAttribute('data-ded')).toFixed(2);

      // Gross for this line (used to estimate taxes automatically).
      var gross = grossTotal();

      // Federal income tax estimate — applies in every state, based on filing status.
      var fedField = document.getElementById('pd_federal');
      var taxStatus = (el.getAttribute('data-tax') || 'single').toLowerCase();
      var fedRate = (FED_RATE && FED_RATE[taxStatus] != null) ? FED_RATE[taxStatus] : (FED_RATE.single || 0.12);
      if (fedField) fedField.value = (gross * fedRate).toFixed(2);

      // State income tax — depends on the employee's state (from their home address).
      var stateCode = (el.getAttribute('data-state') || '').toUpperCase();
      var hasTax = el.getAttribute('data-hastax'); // '1' has tax, '0' none, '' unknown
      var stateTag = document.getElementById('pd_state_tag');
      var stateHint = document.getElementById('pd_state_hint');
      var stateField = document.getElementById('pd_state');
      if (stateTag) stateTag.className = 'org-payroll-tag';
      if (hasTax === '0') {
        if (stateTag) { stateTag.textContent = 'no tax'; stateTag.classList.add('notax'); }
        if (stateHint) stateHint.textContent = (stateCode || 'This state') + ' has no state income tax — leave at 0.';
        if (stateField) stateField.value = '0';
      } else if (hasTax === '1') {
        var stateRate = (STATE_RATE && STATE_RATE[stateCode] != null) ? STATE_RATE[stateCode] : STATE_DEFAULT_RATE;
        if (stateField) stateField.value = (gross * stateRate).toFixed(2);
        if (stateTag) { stateTag.textContent = 'applies'; stateTag.classList.add('hastax'); }
        if (stateHint) stateHint.textContent = 'Estimated for ' + (stateCode || 'this state') + ' at ' + (stateRate * 100).toFixed(1) + '% — adjust if needed.';
      } else {
        if (stateTag) stateTag.textContent = 'by state';
        if (stateHint) stateHint.textContent = 'No state on file. Ask the employee to add their home address in Settings to auto-estimate.';
      }

      recompute();
    }

    // Wire each row's "Edit" button, and preload the first employee automatically.
    var editBtns = Array.prototype.slice.call(document.querySelectorAll('.org-payroll-edit'));
    editBtns.forEach(function (b) {
      b.addEventListener('click', function () {
        applyFromEl(b);
        form.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
      });
    });
    if (editBtns.length) applyFromEl(editBtns[0]);

    // Don't allow saving a line until an employee row is chosen.
    form.addEventListener('submit', function (e) {
      if (!hidden || !hidden.value) {
        e.preventDefault();
        alert('Click “Edit” on an employee row above to load their pay first.');
      }
    });

    pg.concat(pd).forEach(function (i) { i.addEventListener('input', recompute); });
    Object.keys(pe).forEach(function (k) { if (pe[k]) pe[k].addEventListener('input', recompute); });
    Array.prototype.forEach.call(etaxRadios, function (r) { r.addEventListener('change', setEtaxReadonly); });
    if (rate) rate.addEventListener('input', syncWeekMax);
    if (weeklyHours) weeklyHours.addEventListener('input', syncWeekMax);
    syncWeekMax();
    setEtaxReadonly();
  })();

  /* Start pay run: default the pay frequency from the chosen employee's pay setup. */
  (function () {
    var sel = document.getElementById('payrollRunMember');
    var freq = document.getElementById('payrollFrequency');
    if (!sel || !freq) return;
    sel.addEventListener('change', function () {
      var opt = sel.options[sel.selectedIndex];
      if (!opt) return;
      var f = opt.getAttribute('data-freq');
      if (f) freq.value = f;
    });
  })();

})();
</script>
