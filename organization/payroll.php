<?php
declare(strict_types=1);

/**
 * Payroll pay-rate roster — manager view of manager and employee hourly rates.
 * Full pay-run workspace lives at sales_management.php#payroll.
 */

require_once __DIR__ . '/includes/session_org.php';
require_once __DIR__ . '/includes/org_context.php';
require_once __DIR__ . '/includes/org_manager_guard.php';
require_once __DIR__ . '/includes/org_payroll.php';

org_require_manager();

$orgId = (int)orgActiveOrgId();
org_payroll_ensure_schema($dbh);

$people = org_payroll_list_employees($dbh, $orgId);

$managers = [];
$employees = [];
foreach ($people as $p) {
    if (strtolower((string)($p['member_type'] ?? '')) === 'manager') {
        $managers[] = $p;
    } else {
        $employees[] = $p;
    }
}

function payroll_rate_row(array $p): string
{
    $mid = (int)($p['org_member_id'] ?? 0);
    $name = trim((string)($p['name'] ?? 'Team member'));
    $email = trim((string)($p['email'] ?? ''));
    $payType = strtolower((string)($p['pay_type'] ?? 'salary'));
    $rateCents = (int)($p['hourly_rate_cents'] ?? 0);
    $freq = function_exists('org_payroll_frequency_label')
        ? org_payroll_frequency_label((string)($p['pay_frequency'] ?? 'monthly'))
        : 'Monthly';
    $grossCents = (int)($p['default_gross_cents'] ?? 0);

    $rateLabel = $rateCents > 0
        ? (org_payroll_format_cents($rateCents) . '/hr')
        : '<span class="pr-muted">Not set</span>';
    $payTypeLabel = ucfirst($payType);
    $grossLabel = $payType === 'hourly'
        ? '<span class="pr-muted">by hours</span>'
        : ($grossCents > 0 ? org_payroll_format_cents($grossCents) : '<span class="pr-muted">Not set</span>');

    return '<tr>'
        . '<td><strong>' . h($name) . '</strong>' . ($email !== '' ? '<div class="pr-sub">' . h($email) . '</div>' : '') . '</td>'
        . '<td>' . h($payTypeLabel) . '</td>'
        . '<td class="num">' . $rateLabel . '</td>'
        . '<td>' . h($freq) . '</td>'
        . '<td class="num">' . $grossLabel . '</td>'
        . '<td><a class="btn btn-sm btn-outline-primary" href="sales_management.php#payroll">Set rate</a></td>'
        . '</tr>';
}

$pageTitle = 'Payroll rates';
require_once __DIR__ . '/includes/org_page_shell.php';
org_page_shell_open($pageTitle, '<link rel="stylesheet" href="css/commerce-hub.css?v=17">');
?>
<?php org_page_body_open('commerce-page'); ?>
<style>
  .pr-wrap{max-width:1040px;}
  .pr-head{margin-bottom:16px;}
  .pr-head h4{margin:0;font-weight:850;}
  .pr-head p{margin:4px 0 0;font-size:13px;opacity:.8;}
  .pr-card{border:1px solid rgba(148,163,184,.35);border-radius:8px;background:var(--card-bg,transparent);overflow:hidden;margin-bottom:18px;}
  .pr-card-head{padding:10px 12px;border-bottom:1px solid rgba(148,163,184,.25);font-weight:800;font-size:13px;display:flex;align-items:center;justify-content:space-between;gap:8px;}
  .pr-table-wrap{overflow:auto;}
  .pr-table{width:100%;border-collapse:collapse;min-width:720px;}
  .pr-table th,.pr-table td{padding:9px 11px;border-bottom:1px solid rgba(148,163,184,.22);font-size:12px;text-align:left;vertical-align:middle;white-space:nowrap;}
  .pr-table th{font-size:10px;text-transform:uppercase;letter-spacing:.04em;opacity:.75;}
  .pr-table tr:last-child td{border-bottom:0;}
  .pr-table .num{text-align:right;font-variant-numeric:tabular-nums;}
  .pr-sub{font-size:11px;opacity:.7;margin-top:2px;}
  .pr-muted{opacity:.6;}
  .pr-empty{padding:18px 10px;text-align:center;font-size:13px;opacity:.8;}
</style>

<div class="pr-wrap">
  <div class="pr-head">
    <a href="sales_management.php#payroll" class="tx-12">&larr; Payroll workspace</a>
    <h4>Payroll rates</h4>
    <p>View the hourly rate and pay setup for managers and employees. Worked hours live on the <a href="sales_management.php#timecard">Time card</a>. Use <strong>Set rate</strong> to edit, or run pay in the <a href="sales_management.php#payroll">Payroll workspace</a>.</p>
  </div>

  <div class="pr-card">
    <div class="pr-card-head"><span>Employee pay rates</span><span class="pr-muted"><?= count($employees) ?> employee<?= count($employees) === 1 ? '' : 's' ?></span></div>
    <div class="pr-table-wrap">
      <table class="pr-table">
        <thead>
          <tr><th>Employee</th><th>Pay type</th><th class="num">Hourly rate</th><th>Frequency</th><th class="num">Default gross</th><th></th></tr>
        </thead>
        <tbody>
          <?php if (!$employees): ?>
            <tr><td colspan="6" class="pr-empty">No employees yet. <a href="create_staff.php">Hire staff</a> to add them here.</td></tr>
          <?php else: foreach ($employees as $p): ?>
            <?= payroll_rate_row($p) ?>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="pr-card">
    <div class="pr-card-head"><span>Manager pay rates</span><span class="pr-muted"><?= count($managers) ?> manager<?= count($managers) === 1 ? '' : 's' ?></span></div>
    <div class="pr-table-wrap">
      <table class="pr-table">
        <thead>
          <tr><th>Manager</th><th>Pay type</th><th class="num">Hourly rate</th><th>Frequency</th><th class="num">Default gross</th><th></th></tr>
        </thead>
        <tbody>
          <?php if (!$managers): ?>
            <tr><td colspan="6" class="pr-empty">No managers found for this organization.</td></tr>
          <?php else: foreach ($managers as $p): ?>
            <?= payroll_rate_row($p) ?>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <p class="tx-12 tx-color-03">Hourly rates feed pay runs: Gross Pay = clocked hours × hourly rate. Manage rates and run payroll in the <a href="sales_management.php#payroll">Payroll workspace</a>.</p>
</div>
<?php org_page_shell_close(); ?>
