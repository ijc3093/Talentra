<?php
declare(strict_types=1);

/**
 * Pay stub (Step 16) — itemized earnings statement for one pay-run line, with YTD totals.
 * Managers can view any employee's stub; employees can view their own.
 */

require_once __DIR__ . '/includes/session_org.php';
require_once __DIR__ . '/includes/org_context.php';
require_once __DIR__ . '/includes/org_timecard.php';
require_once __DIR__ . '/includes/org_payroll.php';

$orgId = (int)orgActiveOrgId();
$memberId = (int)orgMemberId();
$isManager = isOrgManager();

org_payroll_ensure_schema($dbh);

$lineId = (int)($_GET['line'] ?? 0);
$line = org_payroll_get_line($dbh, $orgId, $lineId);

if (!$line) {
    http_response_code(404);
    $stubError = 'Pay stub not found.';
} elseif (!$isManager && (int)($line['org_member_id'] ?? 0) !== $memberId) {
    http_response_code(403);
    $line = null;
    $stubError = 'You can only view your own pay stub.';
} else {
    $stubError = '';
}

$fmt = static fn(int $c): string => org_payroll_format_cents(max(0, $c));

if ($line) {
    $year = (int)date('Y', strtotime((string)($line['period_end'] ?? 'now')));
    $ytd = org_payroll_ytd_for_member($dbh, $orgId, (int)($line['org_member_id'] ?? 0), $year);
    $freqLabel = org_payroll_frequency_label((string)($line['pay_frequency'] ?? 'monthly'));
    $runStatus = strtolower((string)($line['run_status'] ?? 'draft'));

    $grossRows = [
        ['Regular', (int)($line['regular_cents'] ?? 0)],
        ['Overtime', (int)($line['overtime_cents'] ?? 0)],
        ['Bonus', (int)($line['bonus_cents'] ?? 0)],
        ['Commission', (int)($line['commission_cents'] ?? 0)],
        ['Holiday', (int)($line['holiday_cents'] ?? 0)],
        ['Vacation', (int)($line['vacation_cents'] ?? 0)],
    ];
    $dedRows = [
        ['Federal tax', (int)($line['ded_federal_cents'] ?? 0)],
        ['State tax', (int)($line['ded_state_cents'] ?? 0)],
        ['Health insurance', (int)($line['ded_health_cents'] ?? 0)],
        ['Dental', (int)($line['ded_dental_cents'] ?? 0)],
        ['401(k)', (int)($line['ded_retirement_cents'] ?? 0)],
        ['Other', (int)($line['ded_other_cents'] ?? 0)],
    ];
    $etaxRows = [
        ['Social Security', (int)($line['etax_social_cents'] ?? 0)],
        ['Medicare', (int)($line['etax_medicare_cents'] ?? 0)],
        ['Unemployment', (int)($line['etax_unemp_cents'] ?? 0)],
        ["Workers' comp", (int)($line['etax_workers_cents'] ?? 0)],
    ];
}

$pageTitle = 'Pay stub';
require_once __DIR__ . '/includes/org_page_shell.php';
org_page_shell_open($pageTitle, '<link rel="stylesheet" href="css/commerce-hub.css?v=17">');
?>
<?php org_page_body_open('commerce-page'); ?>
<style>
  .ps-wrap{max-width:720px;}
  .ps-actions{display:flex;justify-content:space-between;align-items:center;gap:8px;margin-bottom:12px;}
  .ps-doc{border:1px solid rgba(148,163,184,.4);border-radius:10px;overflow:hidden;background:var(--card-bg,#fff);}
  .ps-head{padding:16px 18px;border-bottom:1px solid rgba(148,163,184,.3);display:flex;justify-content:space-between;flex-wrap:wrap;gap:10px;}
  .ps-head h4{margin:0;font-weight:850;}
  .ps-head .ps-meta{font-size:12px;opacity:.8;text-align:right;}
  .ps-badge{display:inline-block;padding:2px 8px;border-radius:999px;font-size:10px;font-weight:800;text-transform:uppercase;}
  .ps-badge.paid{background:rgba(34,197,94,.15);color:#15803d;}
  .ps-badge.approved{background:rgba(59,130,246,.15);color:#1d4ed8;}
  .ps-badge.draft{background:rgba(245,158,11,.15);color:#b45309;}
  .ps-grid{display:grid;grid-template-columns:1fr 1fr;gap:0;}
  .ps-col{padding:14px 18px;}
  .ps-col + .ps-col{border-left:1px solid rgba(148,163,184,.25);}
  .ps-col h5{font-size:11px;text-transform:uppercase;letter-spacing:.05em;opacity:.7;margin:0 0 8px;font-weight:850;}
  .ps-line{display:flex;justify-content:space-between;font-size:13px;padding:4px 0;border-bottom:1px dashed rgba(148,163,184,.2);}
  .ps-line.total{border-bottom:0;border-top:1px solid rgba(148,163,184,.35);margin-top:6px;padding-top:8px;font-weight:850;}
  .ps-net{padding:14px 18px;border-top:1px solid rgba(148,163,184,.3);display:flex;justify-content:space-between;align-items:center;font-size:16px;font-weight:850;background:rgba(34,197,94,.08);}
  .ps-foot{padding:12px 18px;border-top:1px solid rgba(148,163,184,.3);font-size:12px;}
  .ps-ytd{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-top:8px;}
  .ps-ytd div{border:1px solid rgba(148,163,184,.3);border-radius:6px;padding:8px;text-align:center;}
  .ps-ytd span{display:block;font-size:10px;text-transform:uppercase;opacity:.7;}
  .ps-ytd strong{display:block;font-size:14px;font-variant-numeric:tabular-nums;}
  @media (max-width:640px){.ps-grid{grid-template-columns:1fr;}.ps-col + .ps-col{border-left:0;border-top:1px solid rgba(148,163,184,.25);}.ps-ytd{grid-template-columns:1fr 1fr;}}
  @media print{.ps-actions,.sh-header,.org-leftbar,.sidebar,header,nav{display:none !important;}.ps-wrap{max-width:100%;}}
</style>

<div class="ps-wrap">
  <?php if (!$line): ?>
    <div class="alert alert-danger"><?= h($stubError) ?></div>
    <a class="btn btn-outline-primary btn-sm" href="sales_management.php#payroll">&larr; Back to Payroll</a>
  <?php else: ?>
    <div class="ps-actions">
      <a class="tx-12" href="<?= $isManager ? 'sales_management.php?pay_run=' . (int)($line['pay_run_id'] ?? 0) . '#payroll' : 'sales_management.php#timecard' ?>">&larr; Back</a>
      <button type="button" class="btn btn-outline-primary btn-sm" onclick="window.print();">Print / Save PDF</button>
    </div>

    <div class="ps-doc">
      <div class="ps-head">
        <div>
          <h4><?= h((string)($line['employee_name'] ?? 'Employee')) ?></h4>
          <div class="tx-12 tx-color-03"><?= h(ucfirst((string)($line['employee_role'] ?? 'staff'))) ?> · <?= h($freqLabel) ?></div>
        </div>
        <div class="ps-meta">
          <div><strong><?= h((string)($line['run_label'] ?? 'Pay run')) ?></strong></div>
          <div>Pay period: <?= h((string)($line['period_start'] ?? '')) ?> – <?= h((string)($line['period_end'] ?? '')) ?></div>
          <div><span class="ps-badge <?= h($runStatus) ?>"><?= h($runStatus) ?></span>
            <?= !empty($line['paid_at']) ? ('· paid ' . h((string)$line['paid_at'])) : '' ?></div>
        </div>
      </div>

      <div class="ps-grid">
        <div class="ps-col">
          <h5>Earnings</h5>
          <?php foreach ($grossRows as [$lbl, $c]): if ($c <= 0) continue; ?>
            <div class="ps-line"><span><?= h($lbl) ?></span><span><?= h($fmt($c)) ?></span></div>
          <?php endforeach; ?>
          <div class="ps-line total"><span>Gross Pay</span><span><?= h($fmt((int)($line['gross_cents'] ?? 0))) ?></span></div>
        </div>
        <div class="ps-col">
          <h5>Deductions</h5>
          <?php $anyDed = false; foreach ($dedRows as [$lbl, $c]): if ($c <= 0) continue; $anyDed = true; ?>
            <div class="ps-line"><span><?= h($lbl) ?></span><span>−<?= h($fmt($c)) ?></span></div>
          <?php endforeach; ?>
          <?php if (!$anyDed): ?><div class="ps-line"><span>No deductions</span><span>—</span></div><?php endif; ?>
          <div class="ps-line total"><span>Total Deductions</span><span>−<?= h($fmt((int)($line['deductions_cents'] ?? 0))) ?></span></div>
        </div>
      </div>

      <div class="ps-net">
        <span>Net Pay</span>
        <span><?= h($fmt((int)($line['net_cents'] ?? 0))) ?></span>
      </div>

      <div class="ps-foot">
        <h5 style="font-size:11px;text-transform:uppercase;letter-spacing:.05em;opacity:.7;margin:0 0 6px;font-weight:850;">Employer-paid taxes (not deducted from your pay)</h5>
        <?php foreach ($etaxRows as [$lbl, $c]): ?>
          <div class="ps-line"><span><?= h($lbl) ?></span><span><?= h($fmt($c)) ?></span></div>
        <?php endforeach; ?>
        <div class="ps-line total"><span>Total employer taxes</span><span><?= h($fmt((int)($line['employer_tax_cents'] ?? 0))) ?></span></div>

        <h5 style="font-size:11px;text-transform:uppercase;letter-spacing:.05em;opacity:.7;margin:14px 0 6px;font-weight:850;">Year-to-date (paid runs, <?= (int)$year ?>)</h5>
        <div class="ps-ytd">
          <div><span>Gross</span><strong><?= h($fmt((int)$ytd['gross'])) ?></strong></div>
          <div><span>Deductions</span><strong><?= h($fmt((int)$ytd['deductions'])) ?></strong></div>
          <div><span>Net</span><strong><?= h($fmt((int)$ytd['net'])) ?></strong></div>
          <div><span>Employer tax</span><strong><?= h($fmt((int)$ytd['employer_tax'])) ?></strong></div>
        </div>
      </div>
    </div>
  <?php endif; ?>
</div>
<?php org_page_shell_close(); ?>
