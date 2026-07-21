<?php
declare(strict_types=1);

/**
 * Employee detail panel for sales_management.php#detail_employee.
 *
 * - Everyone sees the profile the manager maintains (read-only).
 * - Employees may edit ONLY their home address.
 * - Managers edit full details via detail_employee.php (Edit details).
 *
 * Expected vars:
 * - PDO $dbh, int $orgId, int $memberId, bool $isManager
 * - string $homeAddrOk, $homeAddrErr (optional flashes)
 * - string $dePanelFormAction (optional, default sales_management.php#detail_employee)
 */

require_once __DIR__ . '/org_employee_detail.php';
require_once __DIR__ . '/org_member_address.php';
require_once __DIR__ . '/org_payroll.php';

if (!function_exists('h')) {
    function h(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }
}

org_employee_detail_ensure_schema($dbh);
org_member_address_ensure_schema($dbh);

$deEmp = org_employee_detail_load_member($dbh, (int)$orgId, (int)$memberId);
$homeAddrOk = (string)($homeAddrOk ?? '');
$homeAddrErr = (string)($homeAddrErr ?? '');
$dePanelFormAction = (string)($dePanelFormAction ?? 'sales_management.php#detail_employee');
$canEditFull = !empty($isManager);
$canEditAddress = true; // own address only — POST handler always binds to session member

if (!$deEmp): ?>
  <div class="alert alert-warning">Your employee profile was not found for this organization.</div>
<?php return; endif;

$hr = org_employee_detail_get($dbh, (int)$orgId, (int)$memberId);
$addr = org_member_address_get($dbh, (int)$orgId, (int)$memberId) ?: [];
$addrText = $addr ? org_member_address_format($addr) : '';
$addrComplete = org_member_address_is_complete($addr ?: null);

$name = trim((string)($deEmp['fullname'] ?? ''));
if ($name === '') {
    $name = trim((string)($deEmp['username'] ?? ''));
}
if ($name === '') {
    $name = 'Employee';
}

$isManagerMember = strtolower((string)($deEmp['member_type'] ?? '')) === 'manager';
$roleLabel = trim((string)($deEmp['relationship_label'] ?? ''));
if ($roleLabel === '') {
    $roleLabel = $isManagerMember ? 'Manager' : 'Staff';
}

$rateCents = (int)($deEmp['hourly_rate_cents'] ?? 0);
$weekHours = org_payroll_normalize_weekly_hours((float)($deEmp['expected_weekly_hours'] ?? 40));
$weekMaxCents = $rateCents > 0 ? (int)round($rateCents * $weekHours) : 0;
$annualCents = (int)($deEmp['annual_salary_cents'] ?? 0);
$payType = strtolower((string)($deEmp['pay_type'] ?? 'hourly'));
$payFreq = (string)($deEmp['pay_frequency'] ?? 'monthly');
$dedCents = (int)($deEmp['default_deductions_cents'] ?? 0);

if ($payType === 'salary' && $annualCents > 0) {
    $yearPayCents = $annualCents;
    $monthPayCents = (int)round($annualCents / 12);
} elseif ($rateCents > 0) {
    $yearPayCents = (int)round($rateCents * $weekHours * 52);
    $monthPayCents = (int)round($yearPayCents / 12);
} else {
    $yearPayCents = 0;
    $monthPayCents = (int)($deEmp['default_gross_cents'] ?? 0);
}
$netMonthCents = max(0, $monthPayCents - $dedCents);

$initials = '';
foreach (preg_split('/\s+/', $name) ?: [] as $part) {
    if ($part !== '') {
        $initials .= mb_strtoupper(mb_substr($part, 0, 1));
    }
    if (mb_strlen($initials) >= 2) {
        break;
    }
}
if ($initials === '') {
    $initials = 'E';
}

$jobId = trim((string)($hr['job_id'] ?? ''));
if ($jobId === '') {
    $jobId = (string)($deEmp['friend_code'] ?? '');
}
$phone = (string)($hr['phone'] ?? '');
$department = (string)($hr['department'] ?? '');
$supervisor = (string)($hr['supervisor_name'] ?? '');
$empStatus = (string)($hr['employment_status'] ?? 'full_time');
$dob = (string)($hr['dob'] ?? '');
$gender = (string)($hr['gender'] ?? '');
$blood = (string)($hr['blood_group'] ?? '');
$tin = (string)($hr['tin'] ?? '');
$selfService = !isset($hr['self_service_enabled']) || !empty($hr['self_service_enabled']);
$bankName = (string)($deEmp['bank_name'] ?? '');
$bankHolder = trim((string)($hr['bank_account_holder'] ?? ''));
if ($bankHolder === '') {
    $bankHolder = $name;
}
$bankAcct = (string)($hr['bank_account_number'] ?? '');
$bankBranch = (string)($hr['bank_branch'] ?? '');
$bankRouting = (string)($hr['bank_routing'] ?? '');
$bankSwift = (string)($hr['bank_swift'] ?? '');
$joined = (string)($deEmp['member_since'] ?? $deEmp['account_created'] ?? '');
$serviceLen = org_employee_detail_service_length($joined !== '' ? $joined : null);

$dash = static function (string $v): string {
    $v = trim($v);
    return $v !== '' ? h($v) : '<span class="de-empty">—</span>';
};
?>
<style>
  .de-panel-wrap{
    max-width:1180px;
    height:calc(100vh - var(--org-header-h, 48px) - 36px);
    max-height:calc(100vh - var(--org-header-h, 48px) - 36px);
    display:flex;
    flex-direction:column;
    min-height:0;
  }
  .de-panel-top{
    flex:0 0 auto;
    display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:10px;
    padding:0 0 12px;margin-bottom:0;
    position:relative;z-index:20;
    background:var(--bg-main, var(--org-surface, var(--msb-palette-bg, #171d24)));
    border-bottom:1px solid rgba(148,163,184,.28);
    box-shadow:0 6px 12px -10px rgba(0,0,0,.35);
  }
  .de-panel-top h4{margin:0;font-weight:850;font-size:20px;}
  .de-panel-top p{margin:2px 0 0;font-size:12px;opacity:.75;}
  .de-panel-actions{display:flex;flex-wrap:wrap;gap:8px;}
  .de-panel-scroll{
    flex:1 1 auto;
    min-height:0;
    overflow:auto;
    -webkit-overflow-scrolling:touch;
    padding:14px 0 28px;
  }
  .de-layout{display:grid;grid-template-columns:320px minmax(0,1fr);gap:18px;align-items:start;}
  .de-side,.de-card{
    border:1px solid rgba(148,163,184,.32);border-radius:14px;
    background:var(--card-bg, rgba(255,255,255,.03));overflow:hidden;
  }
  .de-side{padding:22px 18px 18px;}
  .de-avatar{
    width:96px;height:96px;border-radius:50%;margin:0 auto 12px;
    display:flex;align-items:center;justify-content:center;
    font-size:32px;font-weight:850;
    background:linear-gradient(145deg, rgba(59,130,246,.35), rgba(14,165,233,.18));
    border:3px solid rgba(148,163,184,.25);
  }
  .de-name{text-align:center;font-size:22px;font-weight:850;margin:0 0 2px;}
  .de-role{text-align:center;font-size:13px;opacity:.7;margin:0 0 14px;}
  .de-sec-title{
    font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.06em;
    opacity:.55;margin:14px 0 8px;padding-top:10px;border-top:1px solid rgba(148,163,184,.22);
  }
  .de-sec-title:first-of-type{border-top:0;padding-top:0;margin-top:0;}
  .de-row{display:grid;grid-template-columns:28px 1fr;gap:8px;padding:7px 0;font-size:13px;}
  .de-row i{font-size:16px;opacity:.65;text-align:center;line-height:1.35;}
  .de-row label{display:block;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;opacity:.55;margin:0 0 1px;}
  .de-row strong{display:block;font-weight:700;line-height:1.3;word-break:break-word;}
  .de-toggle{display:inline-flex;align-items:center;gap:8px;font-size:12px;font-weight:700;}
  .de-toggle-pill{width:42px;height:22px;border-radius:999px;background:rgba(148,163,184,.35);position:relative;display:inline-block;}
  .de-toggle-pill.on{background:#2563eb;}
  .de-toggle-pill::after{content:'';position:absolute;top:2px;left:2px;width:18px;height:18px;border-radius:50%;background:#fff;}
  .de-toggle-pill.on::after{left:22px;}
  .de-card{margin-bottom:16px;}
  .de-card-head{padding:16px 18px 8px;}
  .de-card-head h5{margin:0;font-size:16px;font-weight:850;}
  .de-card-head p{margin:4px 0 0;font-size:12px;opacity:.7;line-height:1.4;}
  .de-card-body{padding:8px 18px 18px;}
  .de-pay-grid{display:grid;grid-template-columns:1fr 1.2fr;gap:18px;}
  .de-pay-big{font-size:28px;font-weight:850;line-height:1.15;margin:0 0 10px;}
  .de-pay-big .unit{font-size:14px;font-weight:700;opacity:.7;}
  .de-underline-green{box-shadow:inset 0 -3px 0 #22c55e;display:inline-block;padding-bottom:2px;}
  .de-underline-blue{box-shadow:inset 0 -3px 0 #3b82f6;display:inline-block;padding-bottom:2px;}
  .de-pay-note{font-size:12px;line-height:1.45;opacity:.78;margin:12px 0 0;}
  .de-table{width:100%;border-collapse:collapse;font-size:13px;}
  .de-table th,.de-table td{padding:9px 8px;border-bottom:1px solid rgba(148,163,184,.2);text-align:left;}
  .de-table th{font-size:10px;text-transform:uppercase;letter-spacing:.04em;opacity:.65;font-weight:800;}
  .de-table tr:nth-child(even) td{background:rgba(148,163,184,.06);}
  .de-table .label{opacity:.75;font-weight:600;}
  .de-empty{opacity:.45;font-weight:500;}
  .de-readonly-banner{
    font-size:12px;padding:8px 12px;border-radius:8px;margin-bottom:14px;
    background:rgba(59,130,246,.1);border:1px solid rgba(59,130,246,.25);
  }
  .de-addr-summary{font-size:13px;line-height:1.45;margin:0 0 12px;}
  .de-addr-summary.is-empty{opacity:.55;font-style:italic;}
  .de-addr-form label{display:block;font-size:11px;font-weight:700;margin-bottom:3px;opacity:.75;}
  .de-addr-form .form-control{height:34px;font-size:13px;margin-bottom:8px;}
  .de-addr-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px;}
  .de-home-modal{position:fixed;inset:0;z-index:12060;display:none;align-items:center;justify-content:center;padding:20px;}
  .de-home-modal.is-open{display:flex;}
  .de-home-modal-backdrop{position:absolute;inset:0;background:rgba(15,23,42,.55);}
  .de-home-modal-dialog{
    position:relative;z-index:1;width:min(560px,100%);max-height:min(92vh,760px);overflow:auto;
    border:1px solid rgba(148,163,184,.35);border-radius:12px;
    background:var(--msb-palette-bg,var(--card-bg,#111827));color:inherit;
    padding:18px 18px 16px;box-shadow:0 18px 48px rgba(0,0,0,.35);
  }
  .de-home-modal-dialog h3{margin:0 0 6px;font-size:18px;font-weight:800;}
  .de-home-modal-dialog > p{margin:0 0 14px;font-size:13px;opacity:.8;line-height:1.4;}
  .de-home-modal-actions{display:flex;flex-wrap:wrap;gap:8px;margin-top:8px;}
  .de-home-modal-close{
    position:absolute;top:12px;right:12px;width:36px;height:36px;border:0;border-radius:50%;
    background:rgba(148,163,184,.2);color:inherit;font-size:22px;line-height:1;cursor:pointer;
  }
  @media (max-width:980px){
    .de-layout,.de-pay-grid,.de-addr-grid{grid-template-columns:1fr;}
  }
</style>

<div class="de-panel-wrap">
  <div class="de-panel-top">
    <div>
      <h4><?= $isManagerMember ? 'Manager detail' : 'Employee detail' ?></h4>
      <p><?= $canEditFull
        ? ($isManagerMember
          ? 'Your manager profile and pay setup. Use Edit details to change fields. Approved time cards feed Payroll the same way as staff.'
          : 'Your profile and pay setup. Use Edit details to change fields, or manage staff from Team.')
        : 'View-only profile maintained by your manager. You may update your home address below.' ?></p>
    </div>
    <div class="de-panel-actions">
      <?php if ($canEditFull): ?>
        <a class="btn btn-primary btn-sm" href="detail_employee.php?id=<?= (int)$memberId ?>&edit=1">Edit details</a>
        <a class="btn btn-outline-secondary btn-sm" href="account.php">Account</a>
        <a class="btn btn-outline-secondary btn-sm" href="sales_management.php#timecard" data-sales-nav="timecard">Time card</a>
        <a class="btn btn-outline-secondary btn-sm" href="members.php?tab=<?= $isManagerMember ? 'managers' : 'staff' ?>">Team</a>
        <a class="btn btn-outline-secondary btn-sm" href="sales_management.php#payroll" data-sales-nav="payroll">Payroll</a>
      <?php else: ?>
        <a class="btn btn-outline-secondary btn-sm" href="account.php">Account</a>
        <a class="btn btn-outline-secondary btn-sm" href="sales_management.php#timecard" data-sales-nav="timecard">Time card</a>
      <?php endif; ?>
    </div>
  </div>

  <div class="de-panel-scroll">
  <?php if ($homeAddrErr !== ''): ?><div class="alert alert-danger"><?= h($homeAddrErr) ?></div><?php endif; ?>
  <?php if ($homeAddrOk !== ''): ?><div class="alert alert-success"><?= h($homeAddrOk) ?></div><?php endif; ?>

  <?php if (!$canEditFull): ?>
    <div class="de-readonly-banner">Profile, salary, and bank info are set by your manager. You can only edit your home address.</div>
  <?php endif; ?>

  <div class="de-layout">
    <aside class="de-side">
      <div class="de-avatar" aria-hidden="true"><?= h($initials) ?></div>
      <h1 class="de-name"><?= h($name) ?></h1>
      <p class="de-role"><?= h($roleLabel) ?></p>

      <div class="de-sec-title">Professional Info</div>
      <div class="de-row"><i class="icon ion-ios-email-outline"></i><div><label>Email</label><strong><?= $dash((string)($deEmp['email'] ?? '')) ?></strong></div></div>
      <div class="de-row"><i class="icon ion-ios-telephone-outline"></i><div><label>Phone</label><strong><?= $dash($phone) ?></strong></div></div>
      <div class="de-row"><i class="icon ion-ios-briefcase-outline"></i><div><label>Job ID</label><strong><?= $dash($jobId) ?></strong></div></div>
      <div class="de-row"><i class="icon ion-ios-checkmark-outline"></i><div><label>Employment Status</label><strong><?= h(org_employee_detail_status_label($empStatus)) ?></strong></div></div>
      <div class="de-row"><i class="icon ion-ios-people-outline"></i><div><label>Department</label><strong><?= $dash($department) ?></strong></div></div>
      <div class="de-row"><i class="icon ion-ios-timer-outline"></i><div><label>Service Length</label><strong><?= h($serviceLen) ?></strong></div></div>
      <div class="de-row"><i class="icon ion-ios-calendar-outline"></i><div><label>Joined</label><strong><?= $joined !== '' ? h(date('d M, Y', strtotime($joined))) : '<span class="de-empty">—</span>' ?></strong></div></div>
      <div class="de-row"><i class="icon ion-ios-person-outline"></i><div><label>Supervisor</label><strong><?= $dash($supervisor) ?></strong></div></div>

      <div class="de-sec-title">Other Info</div>
      <div class="de-row"><i class="icon ion-ios-flower-outline"></i><div><label>DOB</label><strong><?= $dob !== '' ? h(date('d M, Y', strtotime($dob))) : '<span class="de-empty">—</span>' ?></strong></div></div>
      <div class="de-row"><i class="icon ion-ios-person"></i><div><label>Gender</label><strong><?= $dash($gender) ?></strong></div></div>
      <div class="de-row"><i class="icon ion-ios-medical-outline"></i><div><label>Blood Group</label><strong><?= $dash($blood) ?></strong></div></div>
      <div class="de-row"><i class="icon ion-ios-filing-outline"></i><div><label>TIN</label><strong><?= $dash($tin) ?></strong></div></div>
      <div class="de-row"><i class="icon ion-ios-location-outline"></i><div><label>Address</label><strong><?= $addrText !== '' ? h(str_replace("\n", ', ', $addrText)) : '<span class="de-empty">—</span>' ?></strong></div></div>
      <div class="de-row">
        <i class="icon ion-ios-toggle"></i>
        <div>
          <label>Employee Self Service Portal</label>
          <div class="de-toggle">
            <span class="de-toggle-pill<?= $selfService ? ' on' : '' ?>"></span>
            <?= $selfService ? 'On' : 'Off' ?>
          </div>
        </div>
      </div>
    </aside>

    <div>
      <div class="de-card">
        <div class="de-card-head">
          <h5>Salary Details</h5>
          <p>This information is periodic payment from an employer to an employee.</p>
        </div>
        <div class="de-card-body">
          <div class="de-pay-grid">
            <div>
              <p class="de-pay-big">
                <span class="de-underline-green"><?= $monthPayCents > 0 ? h(org_payroll_format_cents($monthPayCents)) : '—' ?></span>
                <span class="unit"> /month</span>
              </p>
              <p class="de-pay-big">
                <span class="de-underline-blue"><?= $yearPayCents > 0 ? h(org_payroll_format_cents($yearPayCents)) : '—' ?></span>
                <span class="unit"> /year</span>
              </p>
            </div>
            <div>
              <table class="de-table">
                <thead><tr><th>Salary Component</th><th>Calculation Type</th></tr></thead>
                <tbody>
                  <tr><td class="label">Pay type</td><td><?= h(ucfirst($payType)) ?> · <?= h(org_payroll_frequency_label($payFreq)) ?></td></tr>
                  <tr><td class="label">Per hour rate</td><td><?= $rateCents > 0 ? h(org_payroll_format_cents($rateCents)) . '/hr' : '—' ?></td></tr>
                  <tr><td class="label">Hours per week</td><td><?= $rateCents > 0 ? h(rtrim(rtrim(number_format($weekHours, 2), '0'), '.')) . ' hrs' : '—' ?></td></tr>
                  <tr><td class="label">Week max income</td><td><?= $weekMaxCents > 0 ? h(org_payroll_format_cents($weekMaxCents)) : '—' ?></td></tr>
                  <tr><td class="label">Annual salary</td><td><?= $annualCents > 0 ? h(org_payroll_format_cents($annualCents)) : '—' ?></td></tr>
                  <tr><td class="label">Default deductions</td><td><?= $dedCents > 0 ? h(org_payroll_format_cents($dedCents)) : '—' ?></td></tr>
                </tbody>
              </table>
            </div>
          </div>
          <p class="de-pay-note">
            <?php if ($monthPayCents > 0): ?>
              Estimated net after listed deductions:
              <strong><?= h(org_payroll_format_cents($netMonthCents)) ?></strong> / month.
              Final pay comes from approved Time card hours on each pay run.
            <?php else: ?>
              <?= $canEditFull
                ? 'Pay rate is not set yet. Use Edit details or Payroll to set Per hour work.'
                : 'Pay rate is not set yet. Ask your manager to update your profile.' ?>
            <?php endif; ?>
          </p>
        </div>
      </div>

      <div class="de-card">
        <div class="de-card-head">
          <h5>Payment Info (Bank)</h5>
          <p>Employee monthly payment method<?= $canEditFull ? '' : ' (set by your manager)' ?>.</p>
        </div>
        <div class="de-card-body" style="padding-top:0;">
          <table class="de-table">
            <tbody>
              <tr><td class="label">Bank Name</td><td><?= $dash($bankName) ?></td></tr>
              <tr><td class="label">Account Holder Name</td><td><?= $dash($bankHolder) ?></td></tr>
              <tr><td class="label">Account Number</td><td><?= $dash($bankAcct) ?></td></tr>
              <tr><td class="label">Branch Name</td><td><?= $dash($bankBranch) ?></td></tr>
              <tr><td class="label">Routing No.</td><td><?= $dash($bankRouting) ?></td></tr>
              <tr><td class="label">SWIFT Code</td><td><?= $dash($bankSwift) ?></td></tr>
            </tbody>
          </table>
        </div>
      </div>

      <?php if ($canEditAddress):
        $homeModalOpen = $homeAddrErr !== '';
      ?>
      <div class="de-card">
        <div class="de-card-head" style="display:flex;flex-wrap:wrap;align-items:flex-start;justify-content:space-between;gap:10px;">
          <div>
            <h5>Home address<?= $canEditFull ? '' : ' (you can edit)' ?></h5>
            <p>Used so your manager can post letters to your home. Only you can change this address.</p>
          </div>
          <button type="button" class="btn btn-outline-primary btn-sm" id="deHomeAddrOpenModal">
            <?= $addrComplete ? 'Edit address' : ($addrText !== '' ? 'Finish address' : 'Add address') ?>
          </button>
        </div>
        <div class="de-card-body">
          <?php if ($addrComplete): ?>
            <p class="de-addr-summary"><?= h(str_replace("\n", ', ', $addrText)) ?></p>
          <?php elseif ($addrText !== ''): ?>
            <p class="de-addr-summary"><?= h(str_replace("\n", ', ', $addrText)) ?></p>
            <p class="alert alert-warning" style="margin:8px 0 0;padding:8px 10px;font-size:12px;">
              Street (Address line 1), city, and state are required for time card / payroll tax. Open the form and fill Address line 1 — do not put the street in Recipient name.
            </p>
          <?php else: ?>
            <p class="de-addr-summary is-empty">No home address on file yet.</p>
          <?php endif; ?>
        </div>
      </div>

      <div class="de-home-modal<?= $homeModalOpen ? ' is-open' : '' ?>" id="deHomeAddrModal" aria-hidden="<?= $homeModalOpen ? 'false' : 'true' ?>">
        <div class="de-home-modal-backdrop" data-close-de-home-modal></div>
        <div class="de-home-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="deHomeAddrModalTitle">
          <button type="button" class="de-home-modal-close" data-close-de-home-modal aria-label="Close">&times;</button>
          <h3 id="deHomeAddrModalTitle">Home address</h3>
          <p>Used so your manager can post letters to your home. Street, city, and state are required for payroll.</p>
          <form method="post" action="<?= h($dePanelFormAction) ?>" class="de-addr-form">
            <input type="hidden" name="home_addr_action" value="1">
            <label for="deHomeRecipient">Recipient name (person, not street)</label>
            <input class="form-control" id="deHomeRecipient" name="recipient_name" maxlength="160"
              value="<?= h((string)($addr['recipient_name'] ?? '')) ?>" placeholder="<?= h($name) ?>">
            <label for="deHomeLine1">Address line 1 (street) *</label>
            <input class="form-control" id="deHomeLine1" name="home_line1" maxlength="200" required
              value="<?= h((string)($addr['line1'] ?? '')) ?>" placeholder="e.g. 21 Lagoon Dr">
            <label for="deHomeLine2">Address line 2</label>
            <input class="form-control" id="deHomeLine2" name="home_line2" maxlength="200" value="<?= h((string)($addr['line2'] ?? '')) ?>">
            <div class="de-addr-grid">
              <div>
                <label for="deHomeCity">City *</label>
                <input class="form-control" id="deHomeCity" name="home_city" required value="<?= h((string)($addr['city'] ?? '')) ?>">
              </div>
              <div>
                <label for="deHomeState">State *</label>
                <input class="form-control" id="deHomeState" name="home_state" required value="<?= h((string)($addr['state'] ?? '')) ?>">
              </div>
              <div>
                <label for="deHomeZip">ZIP</label>
                <input class="form-control" id="deHomeZip" name="home_postal_code" value="<?= h((string)($addr['postal_code'] ?? '')) ?>">
              </div>
              <div>
                <label for="deHomeCountry">Country</label>
                <input class="form-control" id="deHomeCountry" name="home_country" value="<?= h((string)($addr['country'] ?? 'United States')) ?>">
              </div>
            </div>
            <div class="de-home-modal-actions">
              <button type="submit" class="btn btn-primary btn-sm">Save home address</button>
              <button type="button" class="btn btn-outline-secondary btn-sm" data-close-de-home-modal>Cancel</button>
            </div>
          </form>
        </div>
      </div>
      <script>
      (function () {
        var modal = document.getElementById('deHomeAddrModal');
        var openBtn = document.getElementById('deHomeAddrOpenModal');
        if (!modal || !openBtn) return;
        function open() {
          modal.classList.add('is-open');
          modal.setAttribute('aria-hidden', 'false');
          document.body.style.overflow = 'hidden';
          var first = document.getElementById('deHomeRecipient');
          if (first) setTimeout(function () { first.focus(); }, 40);
        }
        function close() {
          modal.classList.remove('is-open');
          modal.setAttribute('aria-hidden', 'true');
          document.body.style.overflow = '';
        }
        openBtn.addEventListener('click', function (e) { e.preventDefault(); open(); });
        modal.querySelectorAll('[data-close-de-home-modal]').forEach(function (el) {
          el.addEventListener('click', close);
        });
        document.addEventListener('keydown', function (e) {
          if (e.key === 'Escape' && modal.classList.contains('is-open')) close();
        });
        if (modal.classList.contains('is-open')) document.body.style.overflow = 'hidden';
      })();
      </script>
      <?php endif; ?>
    </div>
  </div>
  </div><!-- /.de-panel-scroll -->
</div>
