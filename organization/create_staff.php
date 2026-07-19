<?php
// /Business_only3/organization/create_staff.php
declare(strict_types=1);

require_once __DIR__ . '/includes/session_org.php';
require_once __DIR__ . '/includes/org_context.php';
require_once __DIR__ . '/includes/org_payroll.php';

if (!isOrgManager()) {
    header("Location: feed.php");
    exit;
}

if (!function_exists('h')) {
    function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}

function genFriendCode(): string {
    $a = strtoupper(substr(bin2hex(random_bytes(3)), 0, 4));
    $b = strtoupper(substr(bin2hex(random_bytes(3)), 0, 4));
    return "STF-{$a}-{$b}";
}

// CSRF
if (empty($_SESSION['csrf_create_staff'])) {
    $_SESSION['csrf_create_staff'] = bin2hex(random_bytes(16));
}
$csrf = (string)$_SESSION['csrf_create_staff'];
function csrf_ok_create_staff(): bool {
    return isset($_POST['csrf'], $_SESSION['csrf_create_staff'])
        && is_string($_POST['csrf'])
        && hash_equals((string)$_SESSION['csrf_create_staff'], (string)$_POST['csrf']);
}

$err = '';
$created = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_ok_create_staff()) {
        $err = 'Security check failed. Please refresh and try again.';
    } else {
        $username = trim((string)($_POST['username'] ?? ''));
        $fullname = trim((string)($_POST['fullname'] ?? ''));
        $email    = trim((string)($_POST['email'] ?? ''));
        $rel      = trim((string)($_POST['relationship_label'] ?? ''));
        $pass     = (string)($_POST['password'] ?? '');

        // Pay setup (payroll onboarding) captured on the same form.
        $payType       = (string)($_POST['pay_type'] ?? 'salary');
        $payFrequency  = (string)($_POST['pay_frequency'] ?? 'monthly');
        $annualSalary  = (string)($_POST['annual_salary'] ?? '0');
        $hourlyRate    = (string)($_POST['hourly_rate'] ?? '0');
        $taxStatus     = (string)($_POST['tax_status'] ?? 'single');
        $bankName      = trim((string)($_POST['bank_name'] ?? ''));
        $defaultGross  = (string)($_POST['gross'] ?? '0');
        $defaultDed    = (string)($_POST['deductions'] ?? '0');
        $otEligible    = isset($_POST['overtime_eligible']);

        if ($username === '' || $pass === '') {
            $err = "Username and password required.";
        } else {
            // Uniqueness checks
            try {
                // username uniqueness in staff_accounts
                $stU = $dbh->prepare("SELECT 1 FROM staff_accounts WHERE username = :u LIMIT 1");
                $stU->execute([':u'=>$username]);
                if ($stU->fetchColumn()) {
                    $err = "Username already exists. Choose another username.";
                }

                // email uniqueness in staff_accounts (if provided)
                if ($err === '' && $email !== '') {
                    $stE = $dbh->prepare("SELECT 1 FROM staff_accounts WHERE email = :e LIMIT 1");
                    $stE->execute([':e'=>$email]);
                    if ($stE->fetchColumn()) {
                        $err = "Email already exists. Use another email.";
                    }
                }
            } catch (Throwable $e) {
                $err = "DB error: " . $e->getMessage();
            }

            if ($err === '') {
                // Generate friend_code until unique
                $friend = '';
                try {
                    for ($i=0; $i<10; $i++) {
                        $try = genFriendCode();
                        $stF = $dbh->prepare("SELECT 1 FROM staff_accounts WHERE friend_code = :fc LIMIT 1");
                        $stF->execute([':fc'=>$try]);
                        if (!$stF->fetchColumn()) { $friend = $try; break; }
                    }
                } catch (Throwable $e) {
                    $friend = '';
                }
                if ($friend === '') {
                    $err = "Could not generate unique friend code. Try again.";
                } else {

                    $hash = password_hash($pass, PASSWORD_DEFAULT);

                    // role: Staff (org_roles)
                    $orgActive = (int)orgActiveOrgId();
                    $stR = $dbh->prepare("SELECT id FROM org_roles WHERE org_id=:org AND name='Staff' LIMIT 1");
                    $stR->execute([':org'=>$orgActive]);
                    $role = $stR->fetch(PDO::FETCH_ASSOC);

                    if (!$role) {
                        $err = "Staff role missing for this org. Create org roles first.";
                    } else {
                        $dbh->beginTransaction();
                        try {
                            // 1) staff_accounts
                            $st = $dbh->prepare("
                              INSERT INTO staff_accounts (org_id, friend_code, username, email, password, fullname, status, force_password_change, created_at)
                              VALUES (:org, :fc, :u, :e, :p, :fn, 1, 1, NOW())
                            ");
                            $st->execute([
                                ':org'=>$orgActive,
                                ':fc'=>$friend,
                                ':u'=>$username,
                                ':e'=>($email!==''?$email:null),
                                ':p'=>$hash,
                                ':fn'=>($fullname!==''?$fullname:null),
                            ]);
                            $staffId = (int)$dbh->lastInsertId();

                            // 2) org_members (org-scoped identity)
                            $stM = $dbh->prepare("
                              INSERT INTO org_members (org_id, member_type, member_id, role_id, relationship_label, status, joined_at)
                              VALUES (:org, 'staff', :sid, :role, :rel, 1, NOW())
                            ");
                            $stM->execute([
                                ':org'=>$orgActive,
                                ':sid'=>$staffId,
                                ':role'=>(int)$role['id'],
                                ':rel'=>($rel!==''?$rel:null),
                            ]);
                            $orgMemberId = (int)$dbh->lastInsertId();

                            // 3) organization_users (points to org_members.id)
                            $stOU = $dbh->prepare("
                                INSERT INTO organization_users (org_id, user_id, role, joined_at)
                                VALUES (:org, :uid, 'staff', NOW())
                                ON DUPLICATE KEY UPDATE role = VALUES(role)
                            ");
                            $stOU->execute([':org'=>$orgActive, ':uid'=>$orgMemberId]);

                            $dbh->commit();

                            // Save the pay agreement so it's ready in Payroll and visible on the Time card.
                            try {
                                org_payroll_save_profile(
                                    $dbh,
                                    $orgActive,
                                    $orgMemberId,
                                    $payType,
                                    org_payroll_money_to_cents($defaultGross),
                                    org_payroll_money_to_cents($defaultDed),
                                    0, // employer taxes auto-computed at pay-run time
                                    '',
                                    org_payroll_money_to_cents($hourlyRate),
                                    $payFrequency,
                                    org_payroll_money_to_cents($annualSalary),
                                    $taxStatus,
                                    $bankName,
                                    $otEligible
                                );
                            } catch (Throwable $e) {
                                // Staff is created; pay setup can still be edited later in Payroll.
                            }

                            $created = [
                                'username'=>$username,
                                'password'=>$pass,
                                'friend_code'=>$friend,
                            ];
                        } catch (Throwable $e) {
                            if ($dbh->inTransaction()) $dbh->rollBack();
                            $err = "Create staff failed: " . $e->getMessage();
                        }
                    }
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once __DIR__ . '/includes/org_theme_head.php'; ?>

  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
  <title><?= h($ORG['name']) ?> - Create Staff</title>

  <link href="../lib/font-awesome/css/font-awesome.css" rel="stylesheet">
  <link href="../lib/Ionicons/css/ionicons.css" rel="stylesheet">
  <link href="../lib/perfect-scrollbar/css/perfect-scrollbar.css" rel="stylesheet">
  <link href="../lib/bootstrap/bootstrap.css" rel="stylesheet">
  <link rel="stylesheet" href="../css/shamcey.css">
  <?php require_once __DIR__ . '/includes/org_layout.php'; org_layout_head_assets(); ?>

  <style>
    html,body{ height:100%; overflow:hidden; }
    .sh-mainpanel{ height:100vh; display:flex; flex-direction:column; overflow:hidden; }
    .sh-pagetitle{ flex:0 0 auto; }
    .sh-pagebody{
      flex:1 1 auto; overflow:hidden; padding-bottom:0!important;
      display:flex; flex-direction:column; min-height:0;
    }
    .create-card{ flex:1 1 auto; min-height:0; display:flex; flex-direction:column; overflow:hidden; }
    .card-body-fixed{ flex:1 1 auto; min-height:0; overflow:hidden; display:flex; flex-direction:column; }
    .rows-scroll{ flex:1 1 auto; min-height:0; overflow:auto; padding: 15px; }
    .actions-fixed{
      flex:0 0 auto; padding: 12px 15px; border-top: 1px solid rgba(17,24,39,.10);
      background: rgba(248,250,252,.96); display:flex; gap:10px; flex-wrap:wrap;
      justify-content:flex-end; align-items:center;
    }
    .actions-fixed .btn{ font-weight:800; }
    .created-box code{ font-weight:900; }
  </style>
</head>

<body class="org-app">
<?php include __DIR__ . '/includes/header.php'; ?>
<?php include __DIR__ . '/includes/leftbar.php'; ?>

<div class="sh-mainpanel">

  <?php org_page_body_open('', 'border-bottom: 1px solid #4a535c;'); ?>

    <div class="card bd-0 create-card">
      <div class="card-body card-body-fixed">

        <form method="post" autocomplete="off" style="height:100%;display:flex;flex-direction:column;min-height:0;">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">

          <div class="rows-scroll">

            <?php if ($err): ?>
              <div class="alert alert-danger"><?= h($err) ?></div>
            <?php endif; ?>

            <?php if ($created): ?>
              <div class="alert alert-success created-box">
                <div style="font-weight:900;margin-bottom:6px;">Staff created successfully.</div>
                <div><strong>Username:</strong> <code><?= h($created['username']) ?></code></div>
                <div><strong>Password:</strong> <code><?= h($created['password']) ?></code></div>
                <div><strong>Friend Code:</strong> <code><?= h($created['friend_code']) ?></code></div>
                <small style="opacity:.85;">Staff will be forced to change password on first login. Pay setup was saved — edit it anytime in <a href="sales_management.php#payroll">Payroll</a>, and the employee sees their rate on the <a href="sales_management.php#timecard">Time card</a>.</small>
              </div>
            <?php endif; ?>

            <div class="form-group">
              <label>Username</label>
              <input type="text" name="username" class="form-control" required value="<?= h((string)($_POST['username'] ?? '')) ?>">
            </div>

            <div class="form-group">
              <label>Full name</label>
              <input type="text" name="fullname" class="form-control" value="<?= h((string)($_POST['fullname'] ?? '')) ?>">
            </div>

            <div class="form-group">
              <label>Email (optional)</label>
              <input type="email" name="email" class="form-control" value="<?= h((string)($_POST['email'] ?? '')) ?>">
            </div>

            <div class="form-group">
              <label>Role label</label>
              <input type="text" name="relationship_label" class="form-control" value="<?= h((string)($_POST['relationship_label'] ?? '')) ?>">
            </div>

            <div class="form-group">
              <label>Temporary password</label>
              <input type="text" name="password" class="form-control" required value="<?= h((string)($_POST['password'] ?? '')) ?>">
              <small class="form-text text-muted">Staff will be required to change password on first login.</small>
            </div>

            <?php
              $pv = static function (string $key, string $default = '') {
                  return h((string)($_POST[$key] ?? $default));
              };
              $sel = static function (string $key, string $val, string $default): string {
                  $cur = (string)($_POST[$key] ?? $default);
                  return $cur === $val ? ' selected' : '';
              };
              $otChecked = (!isset($_POST['pay_type']) || isset($_POST['overtime_eligible'])) ? ' checked' : '';
            ?>
            <hr>
            <h5 style="font-weight:850;margin-bottom:4px;">Pay setup (payroll onboarding)</h5>
            <p class="tx-12 tx-color-03" style="margin-bottom:14px;">
              Record the pay agreement now. For <strong>Salary</strong>, enter the <strong>Annual salary</strong> and pay frequency —
              per-period Gross is calculated automatically. For <strong>Hourly</strong>, enter the <strong>Hourly rate</strong>; pay runs
              compute Gross from approved time card hours (Overtime at 1.5× over 40h/week when eligible). The employee sees their
              rate on the <strong>Time card</strong>.
            </p>

            <div class="row">
              <div class="form-group col-md-6">
                <label>Pay type</label>
                <select name="pay_type" class="form-control">
                  <option value="salary"<?= $sel('pay_type','salary','salary') ?>>Salary</option>
                  <option value="hourly"<?= $sel('pay_type','hourly','salary') ?>>Hourly</option>
                  <option value="commission"<?= $sel('pay_type','commission','salary') ?>>Commission</option>
                </select>
              </div>
              <div class="form-group col-md-6">
                <label>Pay frequency</label>
                <select name="pay_frequency" class="form-control">
                  <option value="weekly"<?= $sel('pay_frequency','weekly','monthly') ?>>Weekly</option>
                  <option value="bi_weekly"<?= $sel('pay_frequency','bi_weekly','monthly') ?>>Bi-weekly</option>
                  <option value="monthly"<?= $sel('pay_frequency','monthly','monthly') ?>>Monthly</option>
                </select>
              </div>
            </div>

            <div class="row">
              <div class="form-group col-md-6">
                <label>Annual salary</label>
                <input type="number" step="0.01" min="0" name="annual_salary" class="form-control" value="<?= $pv('annual_salary','0') ?>">
              </div>
              <div class="form-group col-md-6">
                <label>Hourly rate</label>
                <input type="number" step="0.01" min="0" name="hourly_rate" class="form-control" value="<?= $pv('hourly_rate','0') ?>">
              </div>
            </div>

            <div class="row">
              <div class="form-group col-md-6">
                <label>Tax status</label>
                <select name="tax_status" class="form-control">
                  <option value="single"<?= $sel('tax_status','single','single') ?>>Single</option>
                  <option value="married"<?= $sel('tax_status','married','single') ?>>Married</option>
                  <option value="head"<?= $sel('tax_status','head','single') ?>>Head of household</option>
                </select>
              </div>
              <div class="form-group col-md-6">
                <label>Bank</label>
                <input type="text" name="bank_name" class="form-control" maxlength="120" placeholder="e.g. Chase" value="<?= $pv('bank_name','') ?>">
              </div>
            </div>

            <div class="row">
              <div class="form-group col-md-6">
                <label>Default Gross (per period)</label>
                <input type="number" step="0.01" min="0" name="gross" class="form-control" value="<?= $pv('gross','0') ?>">
              </div>
              <div class="form-group col-md-6">
                <label>Default Deductions</label>
                <input type="number" step="0.01" min="0" name="deductions" class="form-control" value="<?= $pv('deductions','0') ?>">
              </div>
            </div>

            <div class="form-group">
              <label style="display:flex;align-items:center;gap:8px;font-weight:700;">
                <input type="checkbox" name="overtime_eligible" value="1"<?= $otChecked ?>> Overtime eligible
              </label>
            </div>

          </div>

          <div class="actions-fixed">
            <a href="feed.php" class="btn btn-light">Cancel</a>
            <button type="submit" class="btn btn-primary">
              <i class="ion-person-add mg-r-5"></i> Create Staff
            </button>
          </div>

        </form>

      </div>
    </div>

  </div>

  <?php include __DIR__ . '/includes/footer.php'; ?>

</div>
<?php require_once __DIR__ . '/includes/org_layout.php'; org_layout_footer_assets(); ?>
</body>
</html>
