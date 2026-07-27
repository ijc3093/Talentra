<?php
declare(strict_types=1);

/**
 * Seller self-serve shop rent — affordable mall plans (Small Business starts at $1/mo).
 */
require_once __DIR__ . '/includes/session_org.php';
require_once __DIR__ . '/includes/org_context.php';
require_once __DIR__ . '/includes/org_manager_guard.php';
require_once __DIR__ . '/../public_user/includes/platform_rent.php';
require_once __DIR__ . '/../public_user/includes/stripe_shop.php';
require_once __DIR__ . '/../public_user/includes/org_shop.php';

org_require_manager();
org_require_commerce_seller();

$orgId = (int)orgActiveOrgId();
platform_rent_ensure_schema($dbh);

$snapshot = platform_rent_org_snapshot($dbh, $orgId);
if (!$snapshot || !platform_rent_org_is_shop($snapshot)) {
    header('Location: commerce.php');
    exit;
}

$err = '';
$ok = '';
$plans = platform_rent_list_seller_plans($dbh);
$payments = platform_rent_list_payments($dbh, $orgId, 12);
$productCount = org_shop_product_count($dbh, $orgId);

$liveStatus = (string)($snapshot['rent_status_live'] ?? $snapshot['rent_status'] ?? 'trial');
$shopVisible = !empty($snapshot['shop_visible']);
$currentPlanId = (int)($snapshot['platform_plan_id'] ?? 0);
$currentPlan = $currentPlanId > 0 ? platform_rent_get_plan($dbh, $currentPlanId) : null;
$currentPlanCode = strtolower(trim((string)($snapshot['plan_code'] ?? ($currentPlan['code'] ?? ''))));
$paidUntil = trim((string)($snapshot['rent_paid_until'] ?? ''));
$trialEnds = trim((string)($snapshot['rent_trial_ends_at'] ?? ''));
$stripeReady = stripe_shop_is_configured();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string)($_POST['rent_action'] ?? ''));
    $planId = (int)($_POST['plan_id'] ?? 0);
    $months = max(1, min(12, (int)($_POST['months'] ?? 1)));
    $plan = $planId > 0 ? platform_rent_get_plan($dbh, $planId) : null;

    if (!$plan || strtolower(trim((string)($plan['code'] ?? ''))) === 'shop_trial') {
        $err = 'Choose a valid shop plan.';
    } elseif ($action === 'pay_rent') {
        $price = (int)($plan['price_cents'] ?? 0);
        if ($price <= 0) {
            $err = 'That plan has no monthly rent amount.';
        } elseif (!$stripeReady) {
            $err = 'Online rent payment is not configured yet. Ask the platform admin to record your payment after you pay them directly.';
        } else {
            $orgBase = stripe_shop_organization_base_url();
            $checkout = stripe_shop_create_rent_checkout_session(
                $orgId,
                $planId,
                (string)($plan['name'] ?? 'Shop rent'),
                $price,
                $months,
                (string)($plan['currency'] ?? 'USD'),
                $orgBase . '/shop_rent_success.php?session_id={CHECKOUT_SESSION_ID}',
                $orgBase . '/shop_rent.php?checkout=cancel'
            );
            if (!empty($checkout['ok']) && !empty($checkout['checkout_url'])) {
                header('Location: ' . (string)$checkout['checkout_url']);
                exit;
            }
            $err = (string)($checkout['error'] ?? 'Could not start checkout.');
        }
    } else {
        $err = 'Unknown action.';
    }
}

if ((string)($_GET['checkout'] ?? '') === 'cancel') {
    $err = $err !== '' ? $err : 'Rent checkout was cancelled. Your previous plan is unchanged.';
}
if ((string)($_GET['paid'] ?? '') === '1') {
    $ok = $ok !== '' ? $ok : 'Rent payment confirmed. Your shop access is extended.';
    $snapshot = platform_rent_org_snapshot($dbh, $orgId) ?: $snapshot;
    $liveStatus = (string)($snapshot['rent_status_live'] ?? $liveStatus);
    $shopVisible = !empty($snapshot['shop_visible']);
    $currentPlanId = (int)($snapshot['platform_plan_id'] ?? $currentPlanId);
    $currentPlan = $currentPlanId > 0 ? platform_rent_get_plan($dbh, $currentPlanId) : null;
    $currentPlanCode = strtolower(trim((string)($snapshot['plan_code'] ?? ($currentPlan['code'] ?? ''))));
    $paidUntil = trim((string)($snapshot['rent_paid_until'] ?? $paidUntil));
    $payments = platform_rent_list_payments($dbh, $orgId, 12);
}

$pageTitle = 'Shop rent';
require_once __DIR__ . '/includes/org_page_shell.php';
org_page_shell_open($pageTitle, '<link rel="stylesheet" href="css/commerce-hub.css?v=14">');

if (!function_exists('h')) {
    function h(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }
}
?>
<?php org_page_body_open('commerce-page'); ?>
  <div class="mg-b-20">
    <a href="commerce.php" class="tx-12">&larr; Shop &amp; commerce</a>
    <h4 class="mg-b-5">Shop rent</h4>
    <p class="tx-color-03 mg-b-0">
      The admin owns the mall. You rent a storefront.
      <strong>Small Business</strong> starts at <strong>$1/month</strong> (minimum rent) for up to 25 products — affordable, not free.
    </p>
  </div>

  <?php if ($ok !== ''): ?><div class="alert alert-success"><?= h($ok) ?></div><?php endif; ?>
  <?php if ($err !== ''): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endif; ?>

  <div class="commerce-kpi-grid mg-b-20">
    <div class="commerce-kpi">
      <div class="commerce-kpi-top"><span class="commerce-kpi-label">Status</span></div>
      <div class="commerce-kpi-main">
        <div class="commerce-kpi-value tx-18"><?= h(ucfirst($liveStatus)) ?></div>
        <div class="commerce-kpi-sub"><?= $shopVisible ? 'Shop visible to customers' : 'Shop hidden from customers' ?></div>
      </div>
    </div>
    <div class="commerce-kpi">
      <div class="commerce-kpi-top"><span class="commerce-kpi-label">Current plan</span></div>
      <div class="commerce-kpi-main">
        <div class="commerce-kpi-value tx-18"><?= h((string)($snapshot['plan_name'] ?? 'Trial')) ?></div>
        <div class="commerce-kpi-sub">
          <?php if ($paidUntil !== ''): ?>
            Paid until <?= h(date('M j, Y', strtotime($paidUntil) ?: time())) ?>
          <?php elseif ($liveStatus === 'trial' && $trialEnds !== ''): ?>
            Trial ends <?= h(date('M j, Y', strtotime($trialEnds) ?: time())) ?>
          <?php else: ?>
            <?= h(platform_rent_format_money((int)($snapshot['plan_price_cents'] ?? 0))) ?>/mo
          <?php endif; ?>
        </div>
      </div>
    </div>
    <div class="commerce-kpi">
      <div class="commerce-kpi-top"><span class="commerce-kpi-label">Products</span></div>
      <div class="commerce-kpi-main">
        <div class="commerce-kpi-value tx-18"><?= (int)$productCount ?> / <?= (int)($snapshot['plan_max_products'] ?? 0) ?></div>
        <div class="commerce-kpi-sub">Listings allowed on this plan</div>
      </div>
    </div>
  </div>

  <div class="row row-sm">
    <?php foreach ($plans as $plan): ?>
      <?php
        $pid = (int)($plan['id'] ?? 0);
        $code = strtolower(trim((string)($plan['code'] ?? '')));
        $price = (int)($plan['price_cents'] ?? 0);
        $isCurrent = $pid === $currentPlanId || ($currentPlanCode !== '' && $currentPlanCode === $code);
        $blurb = platform_rent_plan_blurb($plan);
        $highlight = $code === 'shop_starter';
      ?>
      <div class="col-lg-6 mg-b-15">
        <div class="card shadow-base h-100" style="<?= $highlight ? 'border:2px solid var(--org-accent,#2563eb);' : '' ?>">
          <div class="card-body">
            <?php if ($highlight): ?>
              <div class="tx-11 tx-semibold mg-b-5" style="color:var(--org-accent,#2563eb);letter-spacing:.04em;text-transform:uppercase;">Best for small businesses</div>
            <?php endif; ?>
            <div class="d-flex justify-content-between align-items-start mg-b-8">
              <h5 class="mg-b-0"><?= h((string)($plan['name'] ?? 'Plan')) ?></h5>
              <?php if ($isCurrent): ?><span class="badge badge-success">Current</span><?php endif; ?>
            </div>
            <div class="tx-24 tx-bold mg-b-5">
              <?= h(platform_rent_format_money($price)) ?>
              <?php if ((string)($plan['billing_interval'] ?? '') === 'monthly'): ?>
                <span class="tx-14 tx-normal tx-color-03">/ month</span>
              <?php endif; ?>
            </div>
            <p class="tx-12 tx-color-03 mg-b-10">Up to <?= (int)($plan['max_products'] ?? 0) ?> products</p>
            <?php if ($blurb !== ''): ?>
              <p class="tx-13 mg-b-15"><?= h($blurb) ?></p>
            <?php endif; ?>

            <form method="post" class="mg-b-0">
              <input type="hidden" name="rent_action" value="pay_rent">
              <input type="hidden" name="plan_id" value="<?= $pid ?>">
              <div class="form-group mg-b-8">
                <label class="tx-12">Pay for</label>
                <select name="months" class="form-control form-control-sm">
                  <option value="1">1 month</option>
                  <option value="3">3 months</option>
                  <option value="6">6 months</option>
                  <option value="12">12 months</option>
                </select>
              </div>
              <button type="submit" class="btn btn-<?= $highlight ? 'primary' : 'outline-primary' ?> btn-block">
                <?= $stripeReady ? 'Pay with card' : 'Pay with card (setup needed)' ?>
              </button>
            </form>
            <?php if (!$stripeReady): ?>
              <p class="tx-11 tx-color-03 mg-t-8 mg-b-0">Card checkout is not enabled yet. Pay the platform admin directly and ask them to mark rent paid.</p>
            <?php endif; ?>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="card shadow-base mg-t-10">
    <div class="card-header"><h6 class="card-title tx-14 mg-b-0">Recent rent payments</h6></div>
    <div class="card-body pd-0 table-responsive">
      <table class="table mg-b-0">
        <thead><tr><th>When</th><th>Plan</th><th>Amount</th><th>Method</th></tr></thead>
        <tbody>
          <?php if (!$payments): ?>
            <tr><td colspan="4" class="text-center tx-color-03">No rent payments yet.</td></tr>
          <?php else: ?>
            <?php foreach ($payments as $pay): ?>
              <tr>
                <td class="tx-12"><?= h(date('M j, Y g:i A', strtotime((string)($pay['paid_at'] ?? '')) ?: time())) ?></td>
                <td><?= h((string)($pay['plan_name'] ?? '')) ?></td>
                <td><?= h(platform_rent_format_money((int)($pay['amount_cents'] ?? 0), (string)($pay['currency'] ?? 'USD'))) ?></td>
                <td class="tx-12"><?= h((string)($pay['payment_method'] ?? '—')) ?></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php org_page_shell_close(); ?>
