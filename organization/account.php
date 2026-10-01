<?php
declare(strict_types=1);

/**
 * Member earnings account — balance credited when a time card is approved.
 * Staff and managers each see only their own account.
 *
 * Standalone: account.php
 * Embedded: sales_management.php#accounts (set $accountEmbedded = true before require)
 */

$accountEmbedded = !empty($accountEmbedded);

if (!$accountEmbedded) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');

    require_once __DIR__ . '/includes/session_org.php';
    require_once __DIR__ . '/includes/org_context.php';
}

require_once __DIR__ . '/includes/org_timecard.php';
require_once __DIR__ . '/includes/org_payroll.php';
require_once __DIR__ . '/includes/org_member_address.php';
require_once __DIR__ . '/includes/org_member_earnings.php';

if (!function_exists('h')) {
    function h(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }
}

$orgId = (int)(function_exists('orgActiveOrgId') ? orgActiveOrgId() : ($orgId ?? 0));
$memberId = (int)(function_exists('orgMemberId') ? orgMemberId() : ($memberId ?? 0));
$accountType = (string)(function_exists('orgAccountType') ? orgAccountType() : '');
$accountId = (int)(function_exists('orgAccountId') ? orgAccountId() : 0);
$isManager = function_exists('isOrgManager') ? isOrgManager() : ($accountType === 'manager');

// Soft-heal missing org_member_id (common after publisher/manager handoff) without redirecting.
if ($memberId <= 0 && $orgId > 0 && $accountId > 0 && in_array($accountType, ['manager', 'staff'], true) && isset($dbh) && $dbh instanceof PDO) {
    try {
        $stHeal = $dbh->prepare("
            SELECT id, role_id, status
            FROM org_members
            WHERE org_id = :org AND member_type = :mt AND member_id = :mid
            LIMIT 1
        ");
        $stHeal->execute([
            ':org' => $orgId,
            ':mt' => $accountType,
            ':mid' => $accountId,
        ]);
        $heal = $stHeal->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$heal && $accountType === 'manager') {
            $roleId = 0;
            try {
                $stRole = $dbh->prepare("SELECT id FROM org_roles WHERE org_id = :org AND name = 'Manager' LIMIT 1");
                $stRole->execute([':org' => $orgId]);
                $roleId = (int)($stRole->fetchColumn() ?: 0);
            } catch (Throwable $eRole) {
                $roleId = 0;
            }
            if ($roleId > 0) {
                $ins = $dbh->prepare("
                    INSERT IGNORE INTO org_members
                      (org_id, member_type, member_id, role_id, relationship_label, status, joined_at, created_at)
                    VALUES
                      (:org, 'manager', :mid, :role, NULL, 1, NOW(), NOW())
                ");
                $ins->execute([
                    ':org' => $orgId,
                    ':mid' => $accountId,
                    ':role' => $roleId,
                ]);
                $stHeal->execute([
                    ':org' => $orgId,
                    ':mt' => $accountType,
                    ':mid' => $accountId,
                ]);
                $heal = $stHeal->fetch(PDO::FETCH_ASSOC) ?: null;
            }
        }
        if ($heal && (int)($heal['status'] ?? 0) === 1) {
            $memberId = (int)$heal['id'];
            $_SESSION['org_member_id'] = $memberId;
            if ((int)($heal['role_id'] ?? 0) > 0) {
                $_SESSION['org_role_id'] = (int)$heal['role_id'];
            }
        }
    } catch (Throwable $eHeal) {
        // keep going — empty-state message below if still unresolved
    }
}

if ($orgId <= 0 || $memberId <= 0) {
    if ($accountEmbedded) {
        echo '<div class="acct-wrap"><p class="acct-empty" style="padding:24px 8px;">'
            . 'Your earnings account is not available for this login. '
            . 'Open Account from a staff or manager membership in this organization.'
            . '</p></div>';
        return;
    }
    if (!headers_sent()) {
        header('Location: select_org.php');
    }
    exit;
}

$me = org_timecard_member($dbh, $orgId, $memberId);
$name = trim((string)($me['name'] ?? ''));
if ($name === '') {
    $name = 'Team member';
}

$email = '';
try {
    if ($accountType === 'manager' && $accountId > 0) {
        $st = $dbh->prepare('SELECT fullname, email FROM managers WHERE id = :id LIMIT 1');
        $st->execute([':id' => $accountId]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $email = trim((string)($row['email'] ?? ''));
        if (trim((string)($row['fullname'] ?? '')) !== '') {
            $name = trim((string)$row['fullname']);
        }
    } elseif ($accountType === 'staff' && $accountId > 0) {
        $st = $dbh->prepare('SELECT fullname, email FROM staff_accounts WHERE id = :id AND org_id = :org LIMIT 1');
        $st->execute([':id' => $accountId, ':org' => $orgId]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $email = trim((string)($row['email'] ?? ''));
        if (trim((string)($row['fullname'] ?? '')) !== '') {
            $name = trim((string)$row['fullname']);
        }
    }
} catch (Throwable $e) {
    // ignore
}

$orgName = trim((string)($ORG['name'] ?? ''));
if ($orgName === '') {
    $orgName = 'Organization';
}

// Credit any already-approved time cards that predate the earnings ledger.
org_member_earnings_ensure_account($dbh, $orgId, $memberId);
org_member_earnings_backfill_member($dbh, $orgId, $memberId);

$balanceCents = org_member_earnings_get_balance($dbh, $orgId, $memberId);
$txns = org_member_earnings_list_transactions($dbh, $orgId, $memberId, 60);
$balanceLabel = org_payroll_format_cents($balanceCents);

$addr = org_member_address_get($dbh, $orgId, $memberId) ?: [];
$addrText = $addr ? org_member_address_format($addr) : '';

if (!$accountEmbedded) {
    $pageTitle = 'Account';
    require_once __DIR__ . '/includes/org_page_shell.php';
    org_page_shell_open($pageTitle, '<link rel="stylesheet" href="css/commerce-hub.css?v=17">');
    org_page_body_open('commerce-page');
}
?>
<style>
  .acct-wrap{max-width:860px;}
  .acct-kicker{margin:0 0 4px;font-size:12px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;opacity:.7;}
  .acct-wrap h1{margin:0 0 6px;font-size:26px;font-weight:850;}
  .acct-lead{margin:0 0 18px;font-size:14px;opacity:.8;line-height:1.45;}
  .acct-balance{
    border:1px solid rgba(34,197,94,.4);border-radius:12px;padding:18px 20px;margin-bottom:16px;
    background:rgba(34,197,94,.08);
  }
  .acct-balance-label{margin:0 0 4px;font-size:12px;font-weight:800;letter-spacing:.04em;text-transform:uppercase;opacity:.8;}
  .acct-balance-amt{margin:0;font-size:34px;font-weight:850;line-height:1.1;color:#15803d;letter-spacing:-.02em;}
  .acct-balance-hint{margin:8px 0 0;font-size:12px;line-height:1.4;opacity:.8;}
  .acct-card{
    border:1px solid rgba(148,163,184,.35);border-radius:10px;background:var(--card-bg,transparent);
    padding:14px 16px;margin-bottom:14px;
  }
  .acct-card h2{margin:0 0 10px;font-size:14px;font-weight:800;}
  .acct-meta{display:grid;grid-template-columns:1fr 1fr;gap:8px 16px;font-size:13px;}
  .acct-meta div span{display:block;font-size:11px;opacity:.7;margin-bottom:2px;}
  .acct-meta div strong{font-weight:700;}
  .acct-table-wrap{overflow:auto;}
  .acct-table{width:100%;border-collapse:collapse;min-width:520px;}
  .acct-table th,.acct-table td{padding:8px 10px;border-bottom:1px solid rgba(148,163,184,.22);font-size:12px;text-align:left;vertical-align:top;}
  .acct-table th{font-size:10px;text-transform:uppercase;letter-spacing:.04em;opacity:.7;}
  .acct-table tr:last-child td{border-bottom:0;}
  .acct-amt{font-weight:800;white-space:nowrap;}
  .acct-amt.is-credit{color:#15803d;}
  .acct-amt.is-debit{color:#b91c1c;}
  .acct-empty{padding:16px 8px;text-align:center;font-size:13px;opacity:.75;}
  .acct-actions{display:flex;flex-wrap:wrap;gap:8px;margin-top:4px;}
  .acct-actions .btn{font-size:13px;}
  @media (max-width:640px){
    .acct-meta{grid-template-columns:1fr;}
    .acct-balance-amt{font-size:28px;}
  }
  html.dark-theme .acct-balance-amt,
  html.dark-auto .acct-balance-amt{color:#4ade80;}
</style>

<div class="acct-wrap">
  <p class="acct-kicker"><?= $isManager ? 'Manager account' : 'Employee account' ?></p>
  <h1><?= h($name) ?></h1>
  <p class="acct-lead">
    Income from approved time cards is deposited here for <?= h($orgName) ?>.
    <?php if ($isManager): ?>
      When your submitted time card is approved (by you or another manager), the earned amount is added to this balance — same as staff.
    <?php else: ?>
      When your manager approves a submitted time card, the earned amount is added to this balance.
    <?php endif; ?>
  </p>

  <div class="acct-balance">
    <p class="acct-balance-label">Available balance</p>
    <p class="acct-balance-amt"><?= h($balanceLabel) ?></p>
    <p class="acct-balance-hint">
      Gross estimated pay (hours × your hourly rate). Final payroll stubs may still apply taxes or deductions separately.
      After <strong>Approve payroll</strong>, those hours leave the Start pay run list (compensated) and do not return.
    </p>
  </div>

  <div class="acct-card">
    <h2>Account activity</h2>
    <div class="acct-table-wrap">
      <table class="acct-table">
        <thead>
          <tr>
            <th>Date</th>
            <th>Description</th>
            <th>Amount</th>
            <th>Balance</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$txns): ?>
            <tr>
              <td colspan="4" class="acct-empty">
                <?php if ($isManager): ?>
                  No deposits yet. Submit your time card, then approve it in Payroll — earnings will appear here.
                <?php else: ?>
                  No deposits yet. Submit your time card, then wait for manager approval — earnings will appear here.
                <?php endif; ?>
              </td>
            </tr>
          <?php else: foreach ($txns as $txn):
            $amt = (int)($txn['amount_cents'] ?? 0);
            $after = (int)($txn['balance_after_cents'] ?? 0);
            $when = (string)($txn['created_at'] ?? '');
            $whenTs = $when !== '' ? strtotime($when) : false;
            $whenLabel = $whenTs ? date('M j, Y g:i A', $whenTs) : '—';
            $desc = trim((string)($txn['description'] ?? ''));
            if ($desc === '') {
                $desc = (string)($txn['txn_type'] ?? 'Transaction');
            }
            $amtClass = $amt >= 0 ? 'is-credit' : 'is-debit';
            $amtLabel = ($amt >= 0 ? '+' : '') . org_payroll_format_cents($amt);
          ?>
            <tr>
              <td><?= h($whenLabel) ?></td>
              <td><?= h($desc) ?></td>
              <td class="acct-amt <?= $amtClass ?>"><?= h($amtLabel) ?></td>
              <td class="acct-amt"><?= h(org_payroll_format_cents($after)) ?></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="acct-card">
    <h2>Profile</h2>
    <div class="acct-meta">
      <div><span>Email</span><strong><?= $email !== '' ? h($email) : '—' ?></strong></div>
      <div><span>Home address</span><strong><?= $addrText !== '' ? h(str_replace("\n", ', ', $addrText)) : '—' ?></strong></div>
    </div>
    <div class="acct-actions" style="margin-top:12px;">
      <a class="btn btn-primary btn-sm" href="sales_management.php#timecard"<?= $accountEmbedded ? ' data-sales-nav="timecard"' : '' ?>>Time card</a>
      <a class="btn btn-outline-secondary btn-sm" href="sales_management.php#detail_employee"<?= $accountEmbedded ? ' data-sales-nav="detail_employee"' : '' ?>><?= $isManager ? 'My detail' : 'Employee detail' ?></a>
      <?php if ($isManager): ?>
        <a class="btn btn-outline-secondary btn-sm" href="sales_management.php#payroll"<?= $accountEmbedded ? ' data-sales-nav="payroll"' : '' ?>>Payroll</a>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php if (!$accountEmbedded) { org_page_shell_close(); } ?>
