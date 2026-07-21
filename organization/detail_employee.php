<?php
declare(strict_types=1);

/**
 * Member detail profile (manager editable / employee read-only).
 * Works for staff and managers. Layout: sidebar + salary + bank cards.
 */

require_once __DIR__ . '/includes/session_org.php';
require_once __DIR__ . '/includes/org_context.php';
require_once __DIR__ . '/includes/org_payroll.php';
require_once __DIR__ . '/includes/org_timecard.php';
require_once __DIR__ . '/includes/org_member_address.php';
require_once __DIR__ . '/includes/org_employee_detail.php';

if (!function_exists('h')) {
    function h(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }
}

$orgId = (int)orgActiveOrgId();
$meMemberId = (int)orgMemberId();
$isManager = isOrgManager();

if ($orgId <= 0 || $meMemberId <= 0) {
    header('Location: login.php');
    exit;
}

org_payroll_ensure_schema($dbh);
org_timecard_ensure_schema($dbh);
org_member_address_ensure_schema($dbh);
org_employee_detail_ensure_schema($dbh);

$ok = '';
$err = '';

/** @return array<string,mixed>|null */
function de_load_employee(PDO $dbh, int $orgId, int $memberId): ?array
{
    if ($orgId <= 0 || $memberId <= 0) {
        return null;
    }
    try {
        $st = $dbh->prepare("
            SELECT
              om.id AS org_member_id,
              om.member_type,
              om.member_id AS account_id,
              om.relationship_label,
              om.role_id,
              om.status AS member_status,
              COALESCE(om.joined_at, s.created_at) AS member_since,
              COALESCE(s.fullname, m.fullname, '') AS fullname,
              COALESCE(s.username, m.username, '') AS username,
              COALESCE(s.email, m.email, '') AS email,
              COALESCE(s.friend_code, '') AS friend_code,
              COALESCE(s.last_seen, m.last_seen) AS last_seen,
              COALESCE(s.created_at, om.joined_at) AS account_created,
              COALESCE(pp.pay_type, 'hourly') AS pay_type,
              COALESCE(pp.pay_frequency, 'monthly') AS pay_frequency,
              COALESCE(pp.hourly_rate_cents, 0) AS hourly_rate_cents,
              COALESCE(pp.expected_weekly_hours, 40) AS expected_weekly_hours,
              COALESCE(pp.default_gross_cents, 0) AS default_gross_cents,
              COALESCE(pp.default_deductions_cents, 0) AS default_deductions_cents,
              COALESCE(pp.default_employer_tax_cents, 0) AS default_employer_tax_cents,
              COALESCE(pp.annual_salary_cents, 0) AS annual_salary_cents,
              COALESCE(pp.tax_status, 'single') AS tax_status,
              COALESCE(pp.bank_name, '') AS bank_name,
              COALESCE(pp.overtime_eligible, 1) AS overtime_eligible,
              COALESCE(pp.notes, '') AS profile_notes
            FROM org_members om
            LEFT JOIN staff_accounts s
              ON om.member_type = 'staff' AND s.id = om.member_id
            LEFT JOIN managers m
              ON om.member_type = 'manager' AND m.id = om.member_id
            LEFT JOIN org_payroll_profiles pp
              ON pp.org_id = om.org_id AND pp.org_member_id = om.id
            WHERE om.id = :id
              AND om.org_id = :org
              AND om.status = 1
              AND om.member_type IN ('staff', 'manager')
            LIMIT 1
        ");
        $st->execute([':id' => $memberId, ':org' => $orgId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

$memberId = (int)($_GET['id'] ?? $_POST['org_member_id'] ?? 0);
if ($memberId <= 0) {
    // No id → open your own profile (staff or manager).
    $memberId = $meMemberId;
}

$canEdit = false;
$canView = false;

if ($isManager && $memberId > 0) {
    $canView = true;
    $canEdit = true;
} elseif (!$isManager && $memberId > 0 && $memberId === $meMemberId) {
    $canView = true;
    $canEdit = false;
}

$editing = $canEdit && (isset($_GET['edit']) || (isset($_POST['de_action']) && (string)$_POST['de_action'] === 'save_all'));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['de_action'] ?? '');
    $postedMemberId = (int)($_POST['org_member_id'] ?? $memberId);

    // Employees may save ONLY their own home address.
    if ($action === 'save_address' && !$canEdit) {
        if ($postedMemberId !== $meMemberId) {
            $err = 'You can only edit your own home address.';
        } else {
            $res = org_member_address_save($dbh, $orgId, $meMemberId, [
                'recipient_name' => (string)($_POST['recipient_name'] ?? ''),
                'line1' => (string)($_POST['line1'] ?? ''),
                'line2' => (string)($_POST['line2'] ?? ''),
                'city' => (string)($_POST['city'] ?? ''),
                'state' => (string)($_POST['state'] ?? ''),
                'postal_code' => (string)($_POST['postal_code'] ?? ''),
                'country' => (string)($_POST['country'] ?? ''),
            ]);
            if (!empty($res['ok'])) {
                header('Location: detail_employee.php?id=' . $meMemberId . '&saved=1');
                exit;
            }
            $err = (string)($res['error'] ?? 'Could not save address.');
        }
    } elseif (!$canEdit) {
        $err = 'You cannot edit employee details. Contact your manager.';
        $editing = false;
    } else {
        $memberId = $postedMemberId;
        $empPost = de_load_employee($dbh, $orgId, $memberId);

        if (!$empPost) {
            $err = 'Employee not found in this organization.';
        } elseif ($action === 'save_all') {
            $fullname = trim((string)($_POST['fullname'] ?? ''));
            $email = trim((string)($_POST['email'] ?? ''));
            $rel = trim((string)($_POST['relationship_label'] ?? ''));
            $accountId = (int)($empPost['account_id'] ?? 0);

            $payType = strtolower(trim((string)($_POST['pay_type'] ?? 'hourly')));
            if (!in_array($payType, ['hourly', 'salary', 'commission'], true)) {
                $payType = 'hourly';
            }
            $payFrequency = (string)($_POST['pay_frequency'] ?? 'monthly');
            $hourlyRateCents = org_payroll_money_to_cents((string)($_POST['hourly_rate'] ?? '0'));
            $weeklyHours = org_payroll_normalize_weekly_hours((float)($_POST['weekly_hours'] ?? 40));
            $annualSalaryCents = org_payroll_money_to_cents((string)($_POST['annual_salary'] ?? '0'));
            $taxStatus = (string)($_POST['tax_status'] ?? 'single');
            $bankName = trim((string)($_POST['bank_name'] ?? ''));
            $defaultGross = org_payroll_money_to_cents((string)($_POST['gross'] ?? '0'));
            $defaultDed = org_payroll_money_to_cents((string)($_POST['deductions'] ?? '0'));
            $otEligible = isset($_POST['overtime_eligible']);

            if ($payType === 'hourly' && $hourlyRateCents <= 0) {
                $err = 'Enter a per hour rate greater than zero (same as Create Staff).';
            } elseif ($accountId <= 0) {
                $err = 'Account missing for this member.';
            } else {
                try {
                    $memberType = strtolower((string)($empPost['member_type'] ?? 'staff'));
                    if ($email !== '') {
                        if ($memberType === 'manager') {
                            $stE = $dbh->prepare('SELECT id FROM managers WHERE email = :e AND id <> :id LIMIT 1');
                        } else {
                            $stE = $dbh->prepare('SELECT id FROM staff_accounts WHERE email = :e AND id <> :id LIMIT 1');
                        }
                        $stE->execute([':e' => $email, ':id' => $accountId]);
                        if ($stE->fetchColumn()) {
                            throw new RuntimeException('Email already exists on another account.');
                        }
                    }

                    if ($memberType === 'manager') {
                        $stU = $dbh->prepare('UPDATE managers SET fullname = :fn, email = :e WHERE id = :id LIMIT 1');
                        $stU->execute([
                            ':fn' => $fullname !== '' ? $fullname : null,
                            ':e' => $email !== '' ? $email : null,
                            ':id' => $accountId,
                        ]);
                    } else {
                        $stU = $dbh->prepare('UPDATE staff_accounts SET fullname = :fn, email = :e WHERE id = :id AND org_id = :org LIMIT 1');
                        $stU->execute([
                            ':fn' => $fullname !== '' ? $fullname : null,
                            ':e' => $email !== '' ? $email : null,
                            ':id' => $accountId,
                            ':org' => $orgId,
                        ]);
                    }

                    $stM = $dbh->prepare('UPDATE org_members SET relationship_label = :rel WHERE id = :id AND org_id = :org LIMIT 1');
                    $stM->execute([
                        ':rel' => $rel !== '' ? $rel : null,
                        ':id' => $memberId,
                        ':org' => $orgId,
                    ]);

                    $payRes = org_payroll_save_profile(
                        $dbh,
                        $orgId,
                        $memberId,
                        $payType,
                        $defaultGross,
                        $defaultDed,
                        (int)($empPost['default_employer_tax_cents'] ?? 0),
                        trim((string)($_POST['profile_notes'] ?? '')),
                        $hourlyRateCents,
                        $payFrequency,
                        $annualSalaryCents,
                        $taxStatus,
                        $bankName,
                        $otEligible,
                        $weeklyHours
                    );
                    if (empty($payRes['ok'])) {
                        throw new RuntimeException((string)($payRes['error'] ?? 'Pay setup failed.'));
                    }

                    $hrRes = org_employee_detail_save($dbh, $orgId, $memberId, [
                        'phone' => $_POST['phone'] ?? '',
                        'job_id' => $_POST['job_id'] ?? '',
                        'employment_status' => $_POST['employment_status'] ?? 'full_time',
                        'department' => $_POST['department'] ?? '',
                        'supervisor_name' => $_POST['supervisor_name'] ?? '',
                        'dob' => $_POST['dob'] ?? '',
                        'gender' => $_POST['gender'] ?? '',
                        'blood_group' => $_POST['blood_group'] ?? '',
                        'tin' => $_POST['tin'] ?? '',
                        'bank_account_holder' => $_POST['bank_account_holder'] ?? '',
                        'bank_account_number' => $_POST['bank_account_number'] ?? '',
                        'bank_branch' => $_POST['bank_branch'] ?? '',
                        'bank_routing' => $_POST['bank_routing'] ?? '',
                        'bank_swift' => $_POST['bank_swift'] ?? '',
                        'self_service_enabled' => isset($_POST['self_service_enabled']),
                    ]);
                    if (empty($hrRes['ok'])) {
                        throw new RuntimeException((string)($hrRes['error'] ?? 'HR details failed.'));
                    }

                    $addrRes = org_member_address_save($dbh, $orgId, $memberId, [
                        'recipient_name' => (string)($_POST['recipient_name'] ?? $fullname),
                        'line1' => (string)($_POST['line1'] ?? ''),
                        'line2' => (string)($_POST['line2'] ?? ''),
                        'city' => (string)($_POST['city'] ?? ''),
                        'state' => (string)($_POST['state'] ?? ''),
                        'postal_code' => (string)($_POST['postal_code'] ?? ''),
                        'country' => (string)($_POST['country'] ?? ''),
                    ]);
                    if (empty($addrRes['ok'])) {
                        throw new RuntimeException((string)($addrRes['error'] ?? 'Address save failed.'));
                    }

                    $ok = 'Details saved.';
                    $editing = false;
                    header('Location: detail_employee.php?id=' . $memberId . '&saved=1');
                    exit;
                } catch (Throwable $e) {
                    $err = $e->getMessage();
                    $editing = true;
                }
            }
        }
    }
}

if (isset($_GET['saved']) && (string)$_GET['saved'] === '1') {
    $ok = 'Details saved.';
}

if ($memberId <= 0 || !$canView) {
    $pageTitle = 'Member detail';
    require_once __DIR__ . '/includes/org_page_shell.php';
    org_page_shell_open($pageTitle, '<link rel="stylesheet" href="css/commerce-hub.css?v=17">');
    org_page_body_open('commerce-page');
    echo '<div class="de-wrap"><div class="alert alert-warning">';
    if ($isManager) {
        echo 'Choose someone from <a href="members.php?tab=managers">Managers</a> or <a href="members.php?tab=staff">Staff / Family</a>.';
    } else {
        echo 'You can only view your own details.';
    }
    echo '</div></div>';
    org_page_shell_close();
    exit;
}

$emp = de_load_employee($dbh, $orgId, $memberId);
if (!$emp) {
    $pageTitle = 'Not found';
    require_once __DIR__ . '/includes/org_page_shell.php';
    org_page_shell_open($pageTitle, '<link rel="stylesheet" href="css/commerce-hub.css?v=17">');
    org_page_body_open('commerce-page');
    echo '<div class="de-wrap"><div class="alert alert-warning">That member was not found.</div></div>';
    org_page_shell_close();
    exit;
}

$isManagerMember = strtolower((string)($emp['member_type'] ?? '')) === 'manager';
$personLabel = $isManagerMember ? 'manager' : 'employee';
$rosterTab = $isManagerMember ? 'managers' : 'staff';
$rosterLabel = $isManagerMember ? 'Managers' : 'Staff / Family';

// Re-check access after load (manager vs own profile).
if (!$isManager && (int)$emp['org_member_id'] !== $meMemberId) {
    header('Location: detail_employee.php');
    exit;
}

$hr = org_employee_detail_get($dbh, $orgId, $memberId);
$addr = org_member_address_get($dbh, $orgId, $memberId) ?: [];
$addrText = $addr ? org_member_address_format($addr) : '';

$name = trim((string)($emp['fullname'] ?? ''));
if ($name === '') {
    $name = trim((string)($emp['username'] ?? ''));
}
if ($name === '') {
    $name = 'Employee';
}

$roleLabel = trim((string)($emp['relationship_label'] ?? ''));
if ($roleLabel === '') {
    $roleLabel = $isManagerMember ? 'Manager' : 'Staff';
}

$rateCents = (int)($emp['hourly_rate_cents'] ?? 0);
$weekHours = org_payroll_normalize_weekly_hours((float)($emp['expected_weekly_hours'] ?? 40));
$weekMaxCents = $rateCents > 0 ? (int)round($rateCents * $weekHours) : 0;
$annualCents = (int)($emp['annual_salary_cents'] ?? 0);
$payType = strtolower((string)($emp['pay_type'] ?? 'hourly'));
$payFreq = (string)($emp['pay_frequency'] ?? 'monthly');

// Estimated payable figures for Salary Details card.
if ($payType === 'salary' && $annualCents > 0) {
    $yearPayCents = $annualCents;
    $monthPayCents = (int)round($annualCents / 12);
} elseif ($rateCents > 0) {
    $yearPayCents = (int)round($rateCents * $weekHours * 52);
    $monthPayCents = (int)round($yearPayCents / 12);
} else {
    $yearPayCents = 0;
    $monthPayCents = (int)($emp['default_gross_cents'] ?? 0);
}

$dedCents = (int)($emp['default_deductions_cents'] ?? 0);
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
    $jobId = (string)($emp['friend_code'] ?? '');
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

$bankName = (string)($emp['bank_name'] ?? '');
$bankHolder = (string)($hr['bank_account_holder'] ?? '');
if ($bankHolder === '') {
    $bankHolder = $name;
}
$bankAcct = (string)($hr['bank_account_number'] ?? '');
$bankBranch = (string)($hr['bank_branch'] ?? '');
$bankRouting = (string)($hr['bank_routing'] ?? '');
$bankSwift = (string)($hr['bank_swift'] ?? '');

$joined = (string)($emp['member_since'] ?? $emp['account_created'] ?? '');
$serviceLen = org_employee_detail_service_length($joined !== '' ? $joined : null);

$dash = static function (string $v): string {
    $v = trim($v);
    return $v !== '' ? h($v) : '<span class="de-empty">—</span>';
};

$pageTitle = $name;
require_once __DIR__ . '/includes/org_page_shell.php';
org_page_shell_open($pageTitle, '<link rel="stylesheet" href="css/commerce-hub.css?v=17">');
?>
<?php org_page_body_open('commerce-page'); ?>
<style>
  /* Sticky page chrome: top bar stays; profile content scrolls */
  html,body{height:100%;overflow:hidden;}
  .sh-mainpanel{
    height:100vh;
    display:flex;
    flex-direction:column;
    overflow:hidden;
  }
  .sh-pagebody{
    flex:1 1 auto;
    min-height:0;
    overflow:hidden;
    display:flex;
    flex-direction:column;
  }
  .de-wrap{
    max-width:1180px;
    width:100%;
    flex:1 1 auto;
    min-height:0;
    display:flex;
    flex-direction:column;
  }
  .de-top{
    flex:0 0 auto;
    display:flex;
    flex-wrap:wrap;
    align-items:center;
    justify-content:space-between;
    gap:10px;
    padding:4px 0 12px;
    margin-bottom:0;
    position:sticky;
    top:0;
    z-index:30;
    background:var(--bg-main, var(--org-surface, var(--msb-palette-bg, #171d24)));
    border-bottom:1px solid rgba(148,163,184,.28);
    box-shadow:0 6px 12px -10px rgba(0,0,0,.35);
  }
  .de-top a.back{font-size:12px;opacity:.8;}
  .de-top h4{margin:0;font-weight:850;font-size:20px;}
  .de-top-actions{display:flex;flex-wrap:wrap;gap:8px;}
  .de-scroll{
    flex:1 1 auto;
    min-height:0;
    overflow:auto;
    -webkit-overflow-scrolling:touch;
    padding:14px 0 28px;
  }
  .de-layout{display:grid;grid-template-columns:320px minmax(0,1fr);gap:18px;align-items:start;}
  .de-side,.de-panel{
    border:1px solid rgba(148,163,184,.32);
    border-radius:14px;
    background:var(--card-bg, rgba(255,255,255,.03));
    overflow:hidden;
  }
  .de-side{padding:22px 18px 18px;}
  .de-avatar{
    width:96px;height:96px;border-radius:50%;margin:0 auto 12px;
    display:flex;align-items:center;justify-content:center;
    font-size:32px;font-weight:850;letter-spacing:.02em;
    background:linear-gradient(145deg, rgba(59,130,246,.35), rgba(14,165,233,.18));
    border:3px solid rgba(148,163,184,.25);
  }
  .de-name{text-align:center;font-size:22px;font-weight:850;margin:0 0 2px;line-height:1.2;}
  .de-role{text-align:center;font-size:13px;opacity:.7;margin:0 0 14px;}
  .de-msg-btn{display:block;width:100%;text-align:center;margin-bottom:18px;}
  .de-sec{margin-top:6px;}
  .de-sec-title{
    font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.06em;
    opacity:.55;margin:14px 0 8px;padding-top:10px;border-top:1px solid rgba(148,163,184,.22);
  }
  .de-sec-title:first-of-type{border-top:0;padding-top:0;margin-top:0;}
  .de-row{
    display:grid;grid-template-columns:28px 1fr;gap:8px;align-items:start;
    padding:7px 0;font-size:13px;
  }
  .de-row i{font-size:16px;opacity:.65;line-height:1.35;text-align:center;}
  .de-row label{display:block;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;opacity:.55;margin:0 0 1px;}
  .de-row strong{display:block;font-weight:700;line-height:1.3;word-break:break-word;}
  .de-toggle{display:inline-flex;align-items:center;gap:8px;font-size:12px;font-weight:700;}
  .de-toggle-pill{
    width:42px;height:22px;border-radius:999px;background:rgba(148,163,184,.35);
    position:relative;display:inline-block;vertical-align:middle;
  }
  .de-toggle-pill.on{background:#2563eb;}
  .de-toggle-pill::after{
    content:'';position:absolute;top:2px;left:2px;width:18px;height:18px;border-radius:50%;
    background:#fff;transition:left .15s ease;
  }
  .de-toggle-pill.on::after{left:22px;}
  .de-panel{margin-bottom:16px;}
  .de-panel-head{padding:16px 18px 8px;}
  .de-panel-head h5{margin:0;font-size:16px;font-weight:850;}
  .de-panel-head p{margin:4px 0 0;font-size:12px;opacity:.7;line-height:1.4;}
  .de-panel-body{padding:8px 18px 18px;}
  .de-pay-grid{display:grid;grid-template-columns:1fr 1.2fr;gap:18px;align-items:start;}
  .de-pay-big{font-size:28px;font-weight:850;line-height:1.15;margin:0 0 10px;}
  .de-pay-big .unit{font-size:14px;font-weight:700;opacity:.7;}
  .de-underline-green{box-shadow:inset 0 -3px 0 #22c55e;display:inline-block;padding-bottom:2px;}
  .de-underline-blue{box-shadow:inset 0 -3px 0 #3b82f6;display:inline-block;padding-bottom:2px;}
  .de-pay-note{font-size:12px;line-height:1.45;opacity:.78;margin:12px 0 8px;}
  .de-link{font-size:13px;font-weight:700;}
  .de-table{width:100%;border-collapse:collapse;font-size:13px;}
  .de-table th,.de-table td{padding:9px 8px;border-bottom:1px solid rgba(148,163,184,.2);text-align:left;vertical-align:middle;}
  .de-table th{font-size:10px;text-transform:uppercase;letter-spacing:.04em;opacity:.65;font-weight:800;}
  .de-table tr:nth-child(even) td{background:rgba(148,163,184,.06);}
  .de-table .label{opacity:.75;font-weight:600;}
  .de-empty{opacity:.45;font-weight:500;}
  .de-form-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px;}
  .de-form-grid .full{grid-column:1 / -1;}
  .de-form-grid label{display:block;font-size:11px;font-weight:700;margin-bottom:3px;opacity:.75;}
  .de-form-grid .form-control{height:34px;font-size:13px;}
  .de-readonly-banner{
    font-size:12px;padding:8px 12px;border-radius:8px;margin-bottom:14px;
    background:rgba(59,130,246,.1);border:1px solid rgba(59,130,246,.25);
  }
  .de-addr-summary{font-size:13px;line-height:1.45;margin:0 0 12px;}
  .de-addr-summary.is-empty{opacity:.55;font-style:italic;}
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
    .de-layout,.de-pay-grid,.de-form-grid{grid-template-columns:1fr;}
  }
</style>

<div class="de-wrap">
  <div class="de-top">
    <div>
      <?php if ($isManager): ?>
        <a class="back" href="members.php?tab=<?= h($rosterTab) ?>">&larr; <?= h($rosterLabel) ?></a>
      <?php else: ?>
        <a class="back" href="sales_management.php#timecard">&larr; Time card</a>
      <?php endif; ?>
      <h4><?= $editing ? 'Edit ' . h($personLabel) : ucfirst(h($personLabel)) . ' detail' ?></h4>
    </div>
    <div class="de-top-actions">
      <?php if ($canEdit && !$editing): ?>
        <a class="btn btn-primary btn-sm" href="detail_employee.php?id=<?= (int)$memberId ?>&edit=1">Edit details</a>
      <?php elseif ($canEdit && $editing): ?>
        <a class="btn btn-outline-secondary btn-sm" href="detail_employee.php?id=<?= (int)$memberId ?>">Cancel</a>
      <?php endif; ?>
      <?php if ($isManager): ?>
        <?php if (!$isManagerMember): ?>
          <a class="btn btn-outline-secondary btn-sm" href="create_staff.php">Create Staff</a>
        <?php endif; ?>
        <a class="btn btn-outline-secondary btn-sm" href="sales_management.php#payroll">Payroll</a>
      <?php endif; ?>
    </div>
  </div>

  <div class="de-scroll">
  <?php if ($err !== ''): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endif; ?>
  <?php if ($ok !== ''): ?><div class="alert alert-success"><?= h($ok) ?></div><?php endif; ?>

  <?php if (!$canEdit): ?>
    <div class="de-readonly-banner">View only for profile, salary, and bank — your manager maintains those. You may update your home address below.</div>
  <?php endif; ?>

  <?php if ($editing): ?>
  <form method="post" action="detail_employee.php?id=<?= (int)$memberId ?>&edit=1" id="deEditForm">
    <input type="hidden" name="de_action" value="save_all">
    <input type="hidden" name="org_member_id" value="<?= (int)$memberId ?>">

    <div class="de-layout">
      <aside class="de-side">
        <div class="de-avatar" aria-hidden="true"><?= h($initials) ?></div>
        <div class="de-form-grid" style="grid-template-columns:1fr;">
          <div class="full">
            <label for="deFullname">Full name</label>
            <input class="form-control" id="deFullname" name="fullname" value="<?= h((string)($emp['fullname'] ?? '')) ?>" required>
          </div>
          <div class="full">
            <label for="deRole">Job title / role</label>
            <input class="form-control" id="deRole" name="relationship_label" value="<?= h((string)($emp['relationship_label'] ?? '')) ?>" placeholder="e.g. Technical Writer">
          </div>
          <div class="full">
            <label for="deEmail">Email</label>
            <input class="form-control" type="email" id="deEmail" name="email" value="<?= h((string)($emp['email'] ?? '')) ?>">
          </div>
          <div class="full">
            <label for="dePhone">Phone</label>
            <input class="form-control" id="dePhone" name="phone" value="<?= h($phone) ?>">
          </div>
          <div class="full">
            <label for="deJobId">Job ID</label>
            <input class="form-control" id="deJobId" name="job_id" value="<?= h($jobId) ?>" placeholder="<?= h((string)($emp['friend_code'] ?? '')) ?>">
          </div>
          <div class="full">
            <label for="deEmpStatus">Employment status</label>
            <select class="form-control" id="deEmpStatus" name="employment_status">
              <?php foreach (['full_time'=>'Full-time','part_time'=>'Part-time','contract'=>'Contract','intern'=>'Intern','temporary'=>'Temporary'] as $sk=>$sl): ?>
                <option value="<?= h($sk) ?>"<?= $empStatus === $sk ? ' selected' : '' ?>><?= h($sl) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="full">
            <label for="deDept">Department</label>
            <input class="form-control" id="deDept" name="department" value="<?= h($department) ?>">
          </div>
          <div class="full">
            <label for="deSupervisor">Supervisor</label>
            <input class="form-control" id="deSupervisor" name="supervisor_name" value="<?= h($supervisor) ?>">
          </div>
          <div class="full">
            <label for="deDob">Date of birth</label>
            <input class="form-control" type="date" id="deDob" name="dob" value="<?= h($dob !== '' ? substr($dob, 0, 10) : '') ?>">
          </div>
          <div class="full">
            <label for="deGender">Gender</label>
            <input class="form-control" id="deGender" name="gender" value="<?= h($gender) ?>">
          </div>
          <div class="full">
            <label for="deBlood">Blood group</label>
            <input class="form-control" id="deBlood" name="blood_group" value="<?= h($blood) ?>">
          </div>
          <div class="full">
            <label for="deTin">TIN</label>
            <input class="form-control" id="deTin" name="tin" value="<?= h($tin) ?>">
          </div>
          <div class="full">
            <label style="display:flex;align-items:center;gap:8px;opacity:1;font-size:12px;">
              <input type="checkbox" name="self_service_enabled" value="1"<?= $selfService ? ' checked' : '' ?>>
              Employee self-service portal
            </label>
          </div>
          <div class="full"><label>Address line 1 (street) *</label><input class="form-control" name="line1" required value="<?= h((string)($addr['line1'] ?? '')) ?>" placeholder="e.g. 21 Lagoon Dr"></div>
          <div class="full"><label>Address line 2</label><input class="form-control" name="line2" value="<?= h((string)($addr['line2'] ?? '')) ?>"></div>
          <div><label>City *</label><input class="form-control" name="city" required value="<?= h((string)($addr['city'] ?? '')) ?>"></div>
          <div><label>State *</label><input class="form-control" name="state" required value="<?= h((string)($addr['state'] ?? '')) ?>"></div>
          <div><label>ZIP</label><input class="form-control" name="postal_code" value="<?= h((string)($addr['postal_code'] ?? '')) ?>"></div>
          <div><label>Country</label><input class="form-control" name="country" value="<?= h((string)($addr['country'] ?? 'United States')) ?>"></div>
          <input type="hidden" name="recipient_name" value="<?= h($name) ?>">
        </div>
      </aside>

      <div>
        <div class="de-panel">
          <div class="de-panel-head">
            <h5>Salary Details</h5>
            <p>Same pay setup as Create Staff — per hour rate, hours/week, salary, and deductions.</p>
          </div>
          <div class="de-panel-body">
            <div class="de-form-grid">
              <div>
                <label>Pay type</label>
                <select class="form-control" name="pay_type" id="dePayType">
                  <option value="hourly"<?= $payType === 'hourly' ? ' selected' : '' ?>>Hourly (per hour)</option>
                  <option value="salary"<?= $payType === 'salary' ? ' selected' : '' ?>>Salary</option>
                  <option value="commission"<?= $payType === 'commission' ? ' selected' : '' ?>>Commission</option>
                </select>
              </div>
              <div>
                <label>Pay frequency</label>
                <select class="form-control" name="pay_frequency">
                  <?php foreach (['weekly'=>'Weekly','bi_weekly'=>'Bi-weekly','monthly'=>'Monthly'] as $fk=>$fl): ?>
                    <option value="<?= h($fk) ?>"<?= $payFreq === $fk ? ' selected' : '' ?>><?= h($fl) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div>
                <label>Per hour rate ($/hr)</label>
                <input class="form-control" type="number" step="0.01" min="0" name="hourly_rate" id="deHourlyRate"
                  value="<?= $rateCents > 0 ? h(number_format($rateCents / 100, 2, '.', '')) : '' ?>">
              </div>
              <div>
                <label>Hours per week</label>
                <input class="form-control" type="number" step="0.25" min="0.25" max="168" name="weekly_hours" id="deWeeklyHours"
                  value="<?= h(number_format($weekHours, 2, '.', '')) ?>">
              </div>
              <div>
                <label>Week max income</label>
                <input class="form-control" type="text" id="deWeekMax" readonly value="—" style="font-weight:800;">
              </div>
              <div>
                <label>Annual salary</label>
                <input class="form-control" type="number" step="0.01" min="0" name="annual_salary"
                  value="<?= $annualCents > 0 ? h(number_format($annualCents / 100, 2, '.', '')) : '0' ?>">
              </div>
              <div>
                <label>Default gross (period)</label>
                <input class="form-control" type="number" step="0.01" min="0" name="gross"
                  value="<?= h(number_format(((int)($emp['default_gross_cents'] ?? 0)) / 100, 2, '.', '')) ?>">
              </div>
              <div>
                <label>Default deductions</label>
                <input class="form-control" type="number" step="0.01" min="0" name="deductions"
                  value="<?= h(number_format($dedCents / 100, 2, '.', '')) ?>">
              </div>
              <div>
                <label>Tax status</label>
                <select class="form-control" name="tax_status">
                  <?php foreach (['single'=>'Single','married'=>'Married','head'=>'Head of household'] as $tk=>$tl): ?>
                    <option value="<?= h($tk) ?>"<?= (string)($emp['tax_status'] ?? '') === $tk ? ' selected' : '' ?>><?= h($tl) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div>
                <label style="display:flex;align-items:center;gap:8px;margin-top:22px;opacity:1;">
                  <input type="checkbox" name="overtime_eligible" value="1"<?= !empty($emp['overtime_eligible']) ? ' checked' : '' ?>>
                  Overtime eligible (1.5×)
                </label>
              </div>
              <div class="full">
                <label>Notes</label>
                <input class="form-control" name="profile_notes" value="<?= h((string)($emp['profile_notes'] ?? '')) ?>">
              </div>
            </div>
          </div>
        </div>

        <div class="de-panel">
          <div class="de-panel-head">
            <h5>Payment Info (Bank)</h5>
            <p>Employee monthly payment method.</p>
          </div>
          <div class="de-panel-body">
            <div class="de-form-grid">
              <div class="full">
                <label>Bank name</label>
                <input class="form-control" name="bank_name" value="<?= h($bankName) ?>" maxlength="120">
              </div>
              <div>
                <label>Account holder name</label>
                <input class="form-control" name="bank_account_holder" value="<?= h($bankHolder) ?>">
              </div>
              <div>
                <label>Account number</label>
                <input class="form-control" name="bank_account_number" value="<?= h($bankAcct) ?>">
              </div>
              <div>
                <label>Branch name</label>
                <input class="form-control" name="bank_branch" value="<?= h($bankBranch) ?>">
              </div>
              <div>
                <label>Routing No.</label>
                <input class="form-control" name="bank_routing" value="<?= h($bankRouting) ?>">
              </div>
              <div class="full">
                <label>SWIFT code</label>
                <input class="form-control" name="bank_swift" value="<?= h($bankSwift) ?>">
              </div>
            </div>
            <div style="margin-top:14px;">
              <button type="submit" class="btn btn-primary">Save details</button>
              <a class="btn btn-outline-secondary" href="detail_employee.php?id=<?= (int)$memberId ?>">Cancel</a>
            </div>
          </div>
        </div>
      </div>
    </div>
  </form>
  <script>
  (function () {
    var rateEl = document.getElementById('deHourlyRate');
    var hoursEl = document.getElementById('deWeeklyHours');
    var weekEl = document.getElementById('deWeekMax');
    if (!rateEl || !weekEl) return;
    function money(n) {
      try { return new Intl.NumberFormat(undefined, { style: 'currency', currency: 'USD' }).format(n); }
      catch (e) { return '$' + n.toFixed(2); }
    }
    function sync() {
      var rate = parseFloat(String(rateEl.value || '0').replace(/[^0-9.]/g, ''));
      var hours = parseFloat(String((hoursEl && hoursEl.value) || '40').replace(/[^0-9.]/g, ''));
      if (!Number.isFinite(hours) || hours <= 0) hours = 40;
      if (!Number.isFinite(rate) || rate <= 0) { weekEl.value = '—'; return; }
      weekEl.value = money(rate * hours) + ' / week';
    }
    rateEl.addEventListener('input', sync);
    if (hoursEl) hoursEl.addEventListener('input', sync);
    sync();
  })();
  </script>

  <?php else: ?>
  <div class="de-layout">
    <aside class="de-side">
      <div class="de-avatar" aria-hidden="true"><?= h($initials) ?></div>
      <h1 class="de-name"><?= h($name) ?></h1>
      <p class="de-role"><?= h($roleLabel) ?></p>
      <?php if ($isManager): ?>
        <a class="btn btn-outline-secondary btn-sm de-msg-btn" href="messages.php">Message</a>
      <?php endif; ?>

      <div class="de-sec">
        <div class="de-sec-title">Professional Info</div>
        <div class="de-row"><i class="icon ion-ios-email-outline"></i><div><label>Email</label><strong><?= $dash((string)($emp['email'] ?? '')) ?></strong></div></div>
        <div class="de-row"><i class="icon ion-ios-telephone-outline"></i><div><label>Phone</label><strong><?= $dash($phone) ?></strong></div></div>
        <div class="de-row"><i class="icon ion-ios-briefcase-outline"></i><div><label>Job ID</label><strong><?= $dash($jobId) ?></strong></div></div>
        <div class="de-row"><i class="icon ion-ios-checkmark-outline"></i><div><label>Employment Status</label><strong><?= h(org_employee_detail_status_label($empStatus)) ?></strong></div></div>
        <div class="de-row"><i class="icon ion-ios-people-outline"></i><div><label>Department</label><strong><?= $dash($department) ?></strong></div></div>
        <div class="de-row"><i class="icon ion-ios-timer-outline"></i><div><label>Service Length</label><strong><?= h($serviceLen) ?></strong></div></div>
        <div class="de-row"><i class="icon ion-ios-calendar-outline"></i><div><label>Joined</label><strong><?= $joined !== '' ? h(date('d M, Y', strtotime($joined))) : '<span class="de-empty">—</span>' ?></strong></div></div>
        <div class="de-row"><i class="icon ion-ios-person-outline"></i><div><label>Supervisor</label><strong><?= $dash($supervisor) ?></strong></div></div>
      </div>

      <div class="de-sec">
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
      </div>
    </aside>

    <div>
      <div class="de-panel">
        <div class="de-panel-head">
          <h5>Salary Details</h5>
          <p>This information is periodic payment from an employer to an employee.</p>
        </div>
        <div class="de-panel-body">
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
                <thead>
                  <tr><th>Salary Component</th><th>Calculation Type</th></tr>
                </thead>
                <tbody>
                  <tr>
                    <td class="label">Pay type</td>
                    <td><?= h(ucfirst($payType)) ?> · <?= h(org_payroll_frequency_label($payFreq)) ?></td>
                  </tr>
                  <tr>
                    <td class="label">Per hour rate</td>
                    <td><?= $rateCents > 0 ? h(org_payroll_format_cents($rateCents)) . '/hr' : '—' ?></td>
                  </tr>
                  <tr>
                    <td class="label">Hours per week</td>
                    <td><?= $rateCents > 0 ? h(rtrim(rtrim(number_format($weekHours, 2), '0'), '.')) . ' hrs' : '—' ?></td>
                  </tr>
                  <tr>
                    <td class="label">Week max income</td>
                    <td><?= $weekMaxCents > 0 ? h(org_payroll_format_cents($weekMaxCents)) : '—' ?></td>
                  </tr>
                  <tr>
                    <td class="label">Annual salary</td>
                    <td><?= $annualCents > 0 ? h(org_payroll_format_cents($annualCents)) : '—' ?></td>
                  </tr>
                  <tr>
                    <td class="label">Default deductions</td>
                    <td><?= $dedCents > 0 ? h(org_payroll_format_cents($dedCents)) : '—' ?></td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
          <p class="de-pay-note">
            <?php if ($monthPayCents > 0): ?>
              Estimated net after listed deductions:
              <strong><?= h(org_payroll_format_cents($netMonthCents)) ?></strong> / month.
              Final pay is calculated on each Payroll run from approved Time card hours.
            <?php else: ?>
              Pay rate is not set yet.<?= $canEdit ? ' Use Edit details to enter Per hour work (same as Create Staff).' : ' Ask your manager to set your rate.' ?>
            <?php endif; ?>
          </p>
          <?php if ($isManager): ?>
            <a class="de-link" href="sales_management.php#payroll">Salary Payslip / Payroll workspace</a>
          <?php else: ?>
            <a class="de-link" href="sales_management.php#timecard">View Time card</a>
          <?php endif; ?>
        </div>
      </div>

      <div class="de-panel">
        <div class="de-panel-head">
          <h5>Payment Info (Bank)</h5>
          <p>This information is considered the employee monthly payment method.</p>
        </div>
        <div class="de-panel-body" style="padding-top:0;">
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

      <?php if (!$canEdit):
        $homeModalOpen = ($err !== '' && isset($_POST['de_action']) && (string)$_POST['de_action'] === 'save_address');
      ?>
      <div class="de-panel">
        <div class="de-panel-head" style="display:flex;flex-wrap:wrap;align-items:flex-start;justify-content:space-between;gap:10px;">
          <div>
            <h5>Home address (you can edit)</h5>
            <p>Only you can update this mailing address. Profile and pay stay manager-controlled.</p>
          </div>
          <button type="button" class="btn btn-outline-primary btn-sm" id="dePageHomeAddrOpen">
            <?= $addrText !== '' ? 'Edit address' : 'Add address' ?>
          </button>
        </div>
        <div class="de-panel-body">
          <?php if ($addrText !== ''): ?>
            <p class="de-addr-summary"><?= h(str_replace("\n", ', ', $addrText)) ?></p>
          <?php else: ?>
            <p class="de-addr-summary is-empty">No home address on file yet.</p>
          <?php endif; ?>
        </div>
      </div>

      <div class="de-home-modal<?= $homeModalOpen ? ' is-open' : '' ?>" id="dePageHomeAddrModal" aria-hidden="<?= $homeModalOpen ? 'false' : 'true' ?>">
        <div class="de-home-modal-backdrop" data-close-de-page-home></div>
        <div class="de-home-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="dePageHomeAddrTitle">
          <button type="button" class="de-home-modal-close" data-close-de-page-home aria-label="Close">&times;</button>
          <h3 id="dePageHomeAddrTitle">Home address</h3>
          <p>Only you can update this mailing address. Profile and pay stay manager-controlled.</p>
          <form method="post" action="detail_employee.php?id=<?= (int)$memberId ?>">
            <input type="hidden" name="de_action" value="save_address">
            <input type="hidden" name="org_member_id" value="<?= (int)$memberId ?>">
            <div class="de-form-grid">
              <div class="full"><label>Recipient name (person, not street)</label><input class="form-control" id="dePageHomeRecipient" name="recipient_name" value="<?= h((string)($addr['recipient_name'] ?? $name)) ?>"></div>
              <div class="full"><label>Address line 1 (street) *</label><input class="form-control" name="line1" required value="<?= h((string)($addr['line1'] ?? '')) ?>" placeholder="e.g. 21 Lagoon Dr"></div>
              <div class="full"><label>Address line 2</label><input class="form-control" name="line2" value="<?= h((string)($addr['line2'] ?? '')) ?>"></div>
              <div><label>City *</label><input class="form-control" name="city" required value="<?= h((string)($addr['city'] ?? '')) ?>"></div>
              <div><label>State *</label><input class="form-control" name="state" required value="<?= h((string)($addr['state'] ?? '')) ?>"></div>
              <div><label>ZIP</label><input class="form-control" name="postal_code" value="<?= h((string)($addr['postal_code'] ?? '')) ?>"></div>
              <div><label>Country</label><input class="form-control" name="country" value="<?= h((string)($addr['country'] ?? 'United States')) ?>"></div>
            </div>
            <div class="de-home-modal-actions">
              <button type="submit" class="btn btn-primary btn-sm">Save home address</button>
              <button type="button" class="btn btn-outline-secondary btn-sm" data-close-de-page-home>Cancel</button>
            </div>
          </form>
        </div>
      </div>
      <script>
      (function () {
        var modal = document.getElementById('dePageHomeAddrModal');
        var openBtn = document.getElementById('dePageHomeAddrOpen');
        if (!modal || !openBtn) return;
        function open() {
          modal.classList.add('is-open');
          modal.setAttribute('aria-hidden', 'false');
          document.body.style.overflow = 'hidden';
          var first = document.getElementById('dePageHomeRecipient');
          if (first) setTimeout(function () { first.focus(); }, 40);
        }
        function close() {
          modal.classList.remove('is-open');
          modal.setAttribute('aria-hidden', 'true');
          document.body.style.overflow = '';
        }
        openBtn.addEventListener('click', function (e) { e.preventDefault(); open(); });
        modal.querySelectorAll('[data-close-de-page-home]').forEach(function (el) {
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
  <?php endif; ?>
  </div><!-- /.de-scroll -->
</div>
<?php org_page_shell_close(); ?>
