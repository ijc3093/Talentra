<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/session_org.php';
require_once __DIR__ . '/includes/org_context.php';
require_once __DIR__ . '/includes/org_manager_guard.php';
require_once __DIR__ . '/includes/org_sales.php';
require_once __DIR__ . '/../public_user/includes/org_shop_connect.php';
require_once __DIR__ . '/../public_user/includes/stripe_shop.php';

org_require_manager();

org_require_commerce_seller();
org_ecommerce_ensure_schema($dbh);

$orgId = (int)orgActiveOrgId();
$flashOk = '';
$flashErr = '';

$connectParam = strtolower(trim((string)($_GET['connect'] ?? '')));
if ($connectParam === 'return' || $connectParam === 'refresh') {
    org_shop_connect_sync_account($dbh, $orgId);
    if ($connectParam === 'return') {
        $flashOk = 'Stripe Connect status updated.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = strtolower(trim((string)($_POST['action'] ?? '')));
    $orderId = (int)($_POST['order_id'] ?? 0);
    if ($action === 'connect_start') {
        $res = org_shop_connect_start_onboarding($dbh, $orgId);
        if (!empty($res['ok']) && !empty($res['url'])) {
            header('Location: ' . (string)$res['url']);
            exit;
        }
        $flashErr = (string)($res['error'] ?? 'Could not start Stripe Connect.');
    } elseif ($action === 'connect_refresh') {
        org_shop_connect_sync_account($dbh, $orgId);
        $flashOk = 'Connect status refreshed.';
    } elseif ($action === 'set_payout' && $orderId > 0) {
        $status = strtolower(trim((string)($_POST['payout_status'] ?? '')));
        if ($status === 'paid') {
            $auto = org_shop_connect_auto_payout_order($dbh, $orderId);
            if (!empty($auto['ok']) && empty($auto['skipped'])) {
                $flashOk = 'Payout transferred via Stripe Connect.';
            } elseif (!empty($auto['ok']) && !empty($auto['skipped']) && !empty($auto['transfer_id'])) {
                $flashOk = 'Payout already transferred.';
            } else {
                $res = org_sales_set_payout_status($dbh, $orgId, $orderId, 'paid');
                if (!empty($res['ok'])) {
                    $flashOk = 'Payout marked paid' . (!empty($auto['error']) && $auto['error'] !== 'connect_not_ready'
                        ? ' (Connect transfer skipped: ' . $auto['error'] . ')'
                        : ' (manual — Connect not ready).');
                } else {
                    $flashErr = (string)($res['error'] ?? 'Could not update payout.');
                }
            }
        } else {
            $res = org_sales_set_payout_status($dbh, $orgId, $orderId, $status);
            if (!empty($res['ok'])) {
                $flashOk = 'Payout marked as ' . $status . '.';
            } else {
                $flashErr = (string)($res['error'] ?? 'Could not update payout.');
            }
        }
    } elseif ($action === 'auto_payout' && $orderId > 0) {
        $auto = org_shop_connect_auto_payout_order($dbh, $orderId);
        if (!empty($auto['ok']) && empty($auto['skipped'])) {
            $flashOk = 'Payout sent via Stripe Connect.';
        } elseif (!empty($auto['ok'])) {
            $flashOk = 'No transfer needed (already paid or skipped).';
        } else {
            $flashErr = (string)($auto['error'] ?? 'Transfer failed.');
        }
    }
}

$totals = org_sales_payment_totals($dbh, $orgId);
$payments = org_sales_recent_payments($dbh, $orgId, 200);
$payoutTotals = org_sales_payout_totals($dbh, $orgId);
$payoutRows = org_sales_payout_rows($dbh, $orgId, 200);
$connect = org_shop_connect_sync_account($dbh, $orgId);
$stripeReady = stripe_shop_is_configured();
$pageTitle = 'Payments';
require_once __DIR__ . '/includes/org_page_shell.php';
org_page_shell_open($pageTitle, '<link rel="stylesheet" href="css/commerce-hub.css?v=14">');
?>
<?php org_page_body_open('commerce-page'); ?>
  <div class="mg-b-20">
    <a href="sales_management.php" class="tx-12">&larr; Sales management</a>
    <h4 class="mg-b-0">Payments</h4>
    <p class="tx-color-03">Customer payments, Stripe Connect payouts, and manual payout status.</p>
  </div>

  <?php if ($flashOk !== ''): ?>
    <div class="alert alert-success"><?= org_ecommerce_h($flashOk) ?></div>
  <?php endif; ?>
  <?php if ($flashErr !== ''): ?>
    <div class="alert alert-danger"><?= org_ecommerce_h($flashErr) ?></div>
  <?php endif; ?>

  <div class="card shadow-base mg-b-20">
    <div class="card-header d-flex justify-content-between align-items-center">
      <h6 class="card-title tx-14 mg-b-0">Stripe Connect</h6>
      <?php if ($stripeReady): ?>
        <form method="post" class="d-inline">
          <input type="hidden" name="action" value="connect_refresh">
          <button type="submit" class="btn btn-sm btn-outline-secondary">Refresh status</button>
        </form>
      <?php endif; ?>
    </div>
    <div class="card-body">
      <?php if (!$stripeReady): ?>
        <p class="tx-color-03 mg-b-0">Stripe keys are not configured on this server yet. Manual payout status still works below.</p>
      <?php else: ?>
        <p class="mg-b-10">
          <?php if ($connect['account_id'] === ''): ?>
            Connect your seller account to receive automatic payouts after customers pay.
          <?php elseif ($connect['payouts_enabled']): ?>
            Connect is live. Paid orders can auto-transfer your seller payout.
          <?php else: ?>
            Connect account created — finish onboarding so payouts can be enabled.
          <?php endif; ?>
        </p>
        <div class="d-flex flex-wrap" style="gap:8px;margin-bottom:10px;">
          <span class="badge <?= $connect['account_id'] !== '' ? 'badge-success' : 'badge-secondary' ?>">Account <?= $connect['account_id'] !== '' ? 'linked' : 'not linked' ?></span>
          <span class="badge <?= $connect['details_submitted'] ? 'badge-success' : 'badge-secondary' ?>">Details <?= $connect['details_submitted'] ? 'submitted' : 'incomplete' ?></span>
          <span class="badge <?= $connect['charges_enabled'] ? 'badge-success' : 'badge-secondary' ?>">Charges <?= $connect['charges_enabled'] ? 'on' : 'off' ?></span>
          <span class="badge <?= $connect['payouts_enabled'] ? 'badge-success' : 'badge-secondary' ?>">Payouts <?= $connect['payouts_enabled'] ? 'on' : 'off' ?></span>
        </div>
        <?php if ($connect['account_id'] !== ''): ?>
          <p class="tx-12 tx-color-03 mg-b-10"><code><?= org_ecommerce_h($connect['account_id']) ?></code></p>
        <?php endif; ?>
        <form method="post">
          <input type="hidden" name="action" value="connect_start">
          <button type="submit" class="btn btn-primary btn-sm">
            <?= $connect['account_id'] === '' ? 'Connect with Stripe' : ($connect['payouts_enabled'] ? 'Open Stripe onboarding again' : 'Continue Stripe onboarding') ?>
          </button>
        </form>
      <?php endif; ?>
    </div>
  </div>

  <div class="commerce-kpi-grid">
    <div class="commerce-kpi"><div class="commerce-kpi-top"><span class="commerce-kpi-label">Collected</span><span class="commerce-kpi-icon"><i class="icon ion-cash"></i></span></div><div class="commerce-kpi-main"><div class="commerce-kpi-value"><?= org_ecommerce_h(org_sales_money((int)$totals['paid_cents'])) ?></div><div class="commerce-kpi-sub"><?= (int)$totals['payments_count'] ?> paid order payments</div></div></div>
    <div class="commerce-kpi"><div class="commerce-kpi-top"><span class="commerce-kpi-label">Outstanding</span><span class="commerce-kpi-icon"><i class="icon ion-card"></i></span></div><div class="commerce-kpi-main"><div class="commerce-kpi-value"><?= org_ecommerce_h(org_sales_money((int)$totals['outstanding_cents'])) ?></div><div class="commerce-kpi-sub"><?= (int)$totals['open_invoices'] ?> unpaid invoices</div></div></div>
    <div class="commerce-kpi"><div class="commerce-kpi-top"><span class="commerce-kpi-label">Payout pending</span><span class="commerce-kpi-icon"><i class="icon ion-clock"></i></span></div><div class="commerce-kpi-main"><div class="commerce-kpi-value"><?= org_ecommerce_h(org_sales_money((int)$payoutTotals['pending_cents'])) ?></div><div class="commerce-kpi-sub"><?= (int)$payoutTotals['pending_count'] ?> orders</div></div></div>
    <div class="commerce-kpi"><div class="commerce-kpi-top"><span class="commerce-kpi-label">Payout paid</span><span class="commerce-kpi-icon"><i class="icon ion-checkmark-circled"></i></span></div><div class="commerce-kpi-main"><div class="commerce-kpi-value"><?= org_ecommerce_h(org_sales_money((int)$payoutTotals['paid_cents'])) ?></div><div class="commerce-kpi-sub"><?= (int)$payoutTotals['paid_count'] ?> marked paid</div></div></div>
  </div>

  <div class="card shadow-base mg-b-20">
    <div class="card-header d-flex justify-content-between align-items-center">
      <h6 class="card-title tx-14 mg-b-0">Seller payouts</h6>
      <span class="tx-12 tx-color-03">Scheduled: <?= org_ecommerce_h(org_sales_money((int)$payoutTotals['scheduled_cents'])) ?></span>
    </div>
    <div class="card-body pd-0 table-responsive">
      <table class="table table-hover mg-b-0">
        <thead>
          <tr>
            <th>Order</th>
            <th>Customer</th>
            <th>Order total</th>
            <th>Seller payout</th>
            <th>Payout status</th>
            <th>Update</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$payoutRows): ?>
            <tr><td colspan="6" class="text-center tx-color-03">No paid orders yet.</td></tr>
          <?php endif; ?>
          <?php foreach ($payoutRows as $row):
            $oid = (int)($row['id'] ?? 0);
            $ps = strtolower(trim((string)($row['payout_status'] ?? 'pending')));
            if (!in_array($ps, ['pending', 'scheduled', 'paid'], true)) {
                $ps = 'pending';
            }
          ?>
            <tr>
              <td><a href="order_details.php?id=<?= $oid ?>"><code><?= org_ecommerce_h((string)($row['order_code'] ?? '')) ?></code></a></td>
              <td><?= org_ecommerce_h((string)(($row['buyer_name'] ?? '') !== '' ? $row['buyer_name'] : 'Guest')) ?></td>
              <td><?= org_ecommerce_h(org_sales_money((int)($row['total_cents'] ?? 0), (string)($row['currency'] ?? 'USD'))) ?></td>
              <td><?= org_ecommerce_h(org_sales_money((int)($row['seller_payout_cents'] ?? 0), (string)($row['currency'] ?? 'USD'))) ?></td>
              <td><span class="badge badge-secondary"><?= org_ecommerce_h($ps) ?></span></td>
              <td>
                <form method="post" class="d-inline-flex align-items-center" style="gap:6px;flex-wrap:wrap;">
                  <input type="hidden" name="action" value="set_payout">
                  <input type="hidden" name="order_id" value="<?= $oid ?>">
                  <select name="payout_status" class="form-control form-control-sm" style="width:auto;min-width:120px;">
                    <option value="pending"<?= $ps === 'pending' ? ' selected' : '' ?>>pending</option>
                    <option value="scheduled"<?= $ps === 'scheduled' ? ' selected' : '' ?>>scheduled</option>
                    <option value="paid"<?= $ps === 'paid' ? ' selected' : '' ?>>paid</option>
                  </select>
                  <button type="submit" class="btn btn-sm btn-outline-primary">Save</button>
                </form>
                <?php if ($ps !== 'paid' && !empty($connect['payouts_enabled'])): ?>
                  <form method="post" class="d-inline" style="margin-left:4px;">
                    <input type="hidden" name="action" value="auto_payout">
                    <input type="hidden" name="order_id" value="<?= $oid ?>">
                    <button type="submit" class="btn btn-sm btn-primary">Pay via Connect</button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card shadow-base">
    <div class="card-header d-flex justify-content-between align-items-center"><h6 class="card-title tx-14 mg-b-0">Payment ledger</h6><a href="invoices.php" class="btn btn-sm btn-outline-secondary">Invoice payments</a></div>
    <div class="card-body pd-0 table-responsive"><table class="table table-hover mg-b-0"><thead><tr><th>Source</th><th>Code</th><th>Customer</th><th>Amount</th><th>Status</th><th>Paid / created</th></tr></thead><tbody>
      <?php if (!$payments): ?><tr><td colspan="6" class="text-center tx-color-03">No collected payments yet.</td></tr><?php endif; ?>
      <?php foreach ($payments as $p): ?><tr><td><?= org_ecommerce_h((string)$p['source']) ?></td><td><a href="order_details.php?id=<?= (int)$p['id'] ?>"><code><?= org_ecommerce_h((string)$p['code']) ?></code></a></td><td><?= org_ecommerce_h((string)($p['customer'] ?: 'Guest')) ?></td><td><?= org_ecommerce_h(org_sales_money((int)$p['amount_cents'], (string)$p['currency'])) ?></td><td><span class="badge <?= org_sales_status_badge((string)$p['status']) ?>"><?= org_ecommerce_h((string)$p['status']) ?></span></td><td class="tx-12"><?= org_ecommerce_h((string)($p['paid_at'] ?: $p['created_at'])) ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
  </div>
</div>
<?php org_page_shell_close(); ?>
