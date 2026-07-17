<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/org_admin_helpers_load.php';
org_admin_require_admin();

error_reporting(E_ALL);
ini_set('display_errors', '1');

$dbh = org_admin_db();
$msg = '';
$error = '';

$orgId = (int)($_GET['id'] ?? 0);
if ($orgId <= 0) {
    header('Location: orglist.php');
    exit;
}

if (isset($_POST['set_org_status'])) {
    $status = (int)($_POST['status_value'] ?? 0) === 1 ? 1 : 0;
    if (org_admin_set_status($dbh, 'organizations', $orgId, $status)) {
        $msg = $status === 1 ? 'Organization activated.' : 'Organization disabled.';
    } else {
        $error = 'Could not update organization status.';
    }
}

if (isset($_POST['set_manager_status'])) {
    $managerId = (int)($_POST['manager_id'] ?? 0);
    $status = (int)($_POST['status_value'] ?? 0) === 1 ? 1 : 0;
    if ($managerId <= 0) {
        $error = 'Invalid manager id.';
    } elseif (org_admin_set_status($dbh, 'managers', $managerId, $status)) {
        $msg = $status === 1 ? 'Manager activated.' : 'Manager disabled.';
    } else {
        $error = 'Could not update manager status.';
    }
}

if (isset($_POST['set_staff_status'])) {
    $staffId = (int)($_POST['staff_id'] ?? 0);
    $status = (int)($_POST['status_value'] ?? 0) === 1 ? 1 : 0;
    if ($staffId <= 0) {
        $error = 'Invalid staff id.';
    } elseif (org_admin_set_status($dbh, 'staff_accounts', $staffId, $status)) {
        $msg = $status === 1 ? 'Staff account activated.' : 'Staff account disabled.';
    } else {
        $error = 'Could not update staff status.';
    }
}

$org = org_admin_get_organization($dbh, $orgId);
if (!$org) {
    header('Location: orglist.php');
    exit;
}

require_once __DIR__ . '/../public_user/includes/platform_rent.php';
require_once __DIR__ . '/../public_user/includes/org_commerce_brands.php';
platform_rent_ensure_schema($dbh);
org_commerce_brands_ensure_schema($dbh);
$adminId = (int)($_SESSION['admin_id'] ?? $_SESSION['idadmin'] ?? 0);
$rentPlans = platform_rent_list_plans($dbh, false);
$paidRentPlans = array_values(array_filter($rentPlans, static fn(array $p): bool => (int)($p['price_cents'] ?? 0) > 0));
$rentSnapshot = platform_rent_org_snapshot($dbh, $orgId);
$rentPayments = platform_rent_list_payments($dbh, $orgId, 10);
$isShopOrg = $rentSnapshot ? platform_rent_org_is_shop($rentSnapshot) : false;

if ($isShopOrg && isset($_POST['mark_rent_paid'])) {
    $planId = (int)($_POST['plan_id'] ?? 0);
    $months = (int)($_POST['months_paid'] ?? 1);
    $method = trim((string)($_POST['payment_method'] ?? 'manual'));
    $reference = trim((string)($_POST['payment_reference'] ?? ''));
    $notes = trim((string)($_POST['notes'] ?? ''));
    if ($planId <= 0) {
        $error = 'Choose a rent plan.';
    } elseif (platform_rent_mark_paid($dbh, $orgId, $planId, $months, $adminId, $method, $reference, $notes)) {
        $msg = 'Rent payment recorded.';
        $rentSnapshot = platform_rent_org_snapshot($dbh, $orgId);
        $rentPayments = platform_rent_list_payments($dbh, $orgId, 10);
    } else {
        $error = 'Could not record rent payment.';
    }
}

if ($isShopOrg && isset($_POST['suspend_rent'])) {
    if (platform_rent_suspend($dbh, $orgId)) {
        $msg = 'Shop rent suspended.';
        $rentSnapshot = platform_rent_org_snapshot($dbh, $orgId);
    } else {
        $error = 'Could not suspend rent.';
    }
}

if (isset($_POST['migrate_commerce_brand'])) {
    $brandId = (int)($_POST['commerce_brand_id'] ?? 0);
    if ($brandId <= 0) {
        $error = 'Choose a commerce brand.';
    } elseif (org_commerce_brands_migrate_org($dbh, $orgId, $brandId, true)) {
        $brand = org_commerce_brands_get($dbh, $brandId);
        $msg = 'Commerce brand set to ' . (string)($brand['name'] ?? 'brand') . '.';
        $org = org_admin_get_organization($dbh, $orgId);
        $orgCommerceBrand = org_commerce_brands_get_for_org($dbh, $orgId);
        $suggestedCommerceBrand = org_commerce_brands_suggest_for_org($dbh, $org ?: []);
    } else {
        $error = 'Could not assign commerce brand.';
    }
}

$commerceBrands = org_commerce_brands_list_active($dbh);
$orgCommerceBrand = org_commerce_brands_get_for_org($dbh, $orgId);
$suggestedCommerceBrand = org_commerce_brands_suggest_for_org($dbh, $org ?: []);

$members = org_admin_list_org_members($dbh, $orgId);
$orgStatus = (int)($org['status'] ?? 0);
$managerStatus = (int)($org['manager_status'] ?? 0);

org_admin_render_head('Organization · ' . (string)($org['name'] ?? ''));
?>
<div class="sh-logopanel"><a href="" class="sh-logo-text">Talentra Admin</a></div>
<div class="sh-headpanel"></div>
<?php include __DIR__ . '/includes/leftbar.php'; ?>

<div class="sh-mainpanel">
  <div class="sh-pagetitle">
    <div class="sh-pagetitle-left">
      <div class="sh-pagetitle-icon"><i class="icon ion-ios-briefcase"></i></div>
      <div>
        <h2><?= org_admin_h($org['name'] ?? 'Organization') ?></h2>
        <p class="mg-b-0"><?= org_admin_h($org['org_code'] ?? '') ?></p>
      </div>
    </div>
    <div class="sh-pagetitle-right">
      <a href="orglist.php" class="btn-mini">Back to list</a>
    </div>
  </div>

  <div class="sh-pagebody">
    <?php if ($msg !== ''): ?><div class="alert-lite ok"><?= org_admin_h($msg) ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="alert-lite bad"><?= org_admin_h($error) ?></div><?php endif; ?>

    <div class="card admin-card">
      <div class="card-header pro">
        Organization Details
        <div class="sub">
          <?= (int)($org['is_publisher_org'] ?? 0) === 1 ? 'Publisher workspace' : 'Regular workspace' ?>
          · <?= org_admin_h(org_admin_fmt_dt($org['created_at'] ?? '')) ?>
        </div>
      </div>

      <div class="detail-grid">
        <div class="detail-box">
          <div class="label">Organization status</div>
          <div class="value"><?= org_admin_status_badge($orgStatus) ?></div>
          <form method="post" style="margin-top:10px;" onsubmit="return confirm('Change organization status?');">
            <input type="hidden" name="status_value" value="<?= $orgStatus === 1 ? 0 : 1 ?>">
            <button type="submit" name="set_org_status" class="btn-mini <?= $orgStatus === 1 ? 'warn' : 'primary' ?>">
              <?= $orgStatus === 1 ? 'Disable org' : 'Activate org' ?>
            </button>
          </form>
        </div>
        <div class="detail-box">
          <div class="label">Owner manager</div>
          <div class="value">
            <?= org_admin_h($org['manager_username'] ?? '') ?>
            <?php if (!empty($org['manager_fullname'])): ?>
              <div class="muted"><?= org_admin_h($org['manager_fullname']) ?></div>
            <?php endif; ?>
          </div>
          <div class="muted" style="margin-top:6px;">
            <?= org_admin_h($org['manager_code'] ?? '') ?> · <?= org_admin_h($org['manager_email'] ?? '') ?>
          </div>
          <div style="margin-top:8px;"><?= org_admin_status_badge($managerStatus) ?></div>
          <form method="post" style="margin-top:10px;" onsubmit="return confirm('Change manager status?');">
            <input type="hidden" name="manager_id" value="<?= (int)($org['manager_id'] ?? 0) ?>">
            <input type="hidden" name="status_value" value="<?= $managerStatus === 1 ? 0 : 1 ?>">
            <button type="submit" name="set_manager_status" class="btn-mini <?= $managerStatus === 1 ? 'warn' : 'primary' ?>">
              <?= $managerStatus === 1 ? 'Disable manager' : 'Activate manager' ?>
            </button>
          </form>
        </div>
        <div class="detail-box">
          <div class="label">Linked publisher user</div>
          <div class="value">
            <?php if (!empty($org['pub_user_id'])): ?>
              <div style="margin-bottom:8px;">
                <?= org_admin_render_public_user_link(
                  (int)$org['pub_user_id'],
                  (string)($org['pub_username'] ?? ''),
                  (string)($org['pub_username'] ?? ''),
                  (string)($org['pub_code'] ?? '')
                ) ?>
              </div>
              <div class="muted"><?= org_admin_h($org['pub_code'] ?? '') ?> · <?= org_admin_h($org['pub_email'] ?? '') ?></div>
              <div style="margin-top:8px;"><?= org_admin_status_badge((int)($org['pub_user_status'] ?? 0)) ?></div>
            <?php else: ?>
              <span class="muted">No public_user link on this org</span>
            <?php endif; ?>
          </div>
        </div>
        <div class="detail-box">
          <div class="label">Publisher registry</div>
          <div class="value">
            <?php if (!empty($org['registered_publisher_name'])): ?>
              <?php if (!empty($org['pub_user_id'])): ?>
                <a href="<?= org_admin_h(org_admin_user_activity_link((int)$org['pub_user_id'])) ?>"><?= org_admin_h($org['registered_publisher_name']) ?></a>
              <?php else: ?>
                <?= org_admin_h($org['registered_publisher_name']) ?>
              <?php endif; ?>
            <?php else: ?>
              <span class="muted">No publisher_name_options row</span>
            <?php endif; ?>
          </div>
          <?php if (!empty($org['publisher_category'])): ?>
            <div class="muted" style="margin-top:6px;">Category: <?= org_admin_h($org['publisher_category']) ?></div>
          <?php endif; ?>
        </div>
        <div class="detail-box">
          <div class="label">Commerce brand system</div>
          <div class="value">
            <?php if ($orgCommerceBrand): ?>
              <span class="pill ok"><?= org_admin_h($orgCommerceBrand['name'] ?? '') ?></span>
            <?php else: ?>
              <span class="pill bad">Not linked</span>
              <?php if ($suggestedCommerceBrand): ?>
                <div class="muted" style="margin-top:6px;">Suggested: <?= org_admin_h($suggestedCommerceBrand['name'] ?? '') ?></div>
              <?php endif; ?>
            <?php endif; ?>
          </div>
          <?php if ($commerceBrands): ?>
          <form method="post" style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
            <select name="commerce_brand_id" class="form-control" style="min-width:180px;max-width:240px;min-height:34px;height:34px;" required>
              <option value="">Choose brand…</option>
              <?php foreach ($commerceBrands as $brand): ?>
                <?php
                  $bid = (int)($brand['id'] ?? 0);
                  $selected = $orgCommerceBrand && (int)($orgCommerceBrand['id'] ?? 0) === $bid;
                  if (!$selected && !$orgCommerceBrand && $suggestedCommerceBrand && (int)($suggestedCommerceBrand['id'] ?? 0) === $bid) {
                      $selected = true;
                  }
                ?>
                <option value="<?= $bid ?>"<?= $selected ? ' selected' : '' ?>><?= org_admin_h((string)($brand['name'] ?? '')) ?></option>
              <?php endforeach; ?>
            </select>
            <button type="submit" name="migrate_commerce_brand" class="btn-mini primary"><?= $orgCommerceBrand ? 'Change brand' : 'Assign brand' ?></button>
            <a href="org_commerce_brands.php" class="btn-mini">All migrations</a>
          </form>
          <?php endif; ?>
        </div>
      </div>

      <?php if ($isShopOrg && $rentSnapshot): ?>
      <div class="pro-tools" style="margin-top:18px;">
        <strong>Shop rent</strong>
        <span class="muted">Seller pays you monthly to keep this shop live on public_user</span>
      </div>
      <div class="detail-grid">
        <div class="detail-box">
          <div class="label">Rent status</div>
          <div class="value"><?= platform_rent_status_badge((string)($rentSnapshot['rent_status_live'] ?? $rentSnapshot['rent_status'] ?? 'trial')) ?></div>
          <div class="muted" style="margin-top:8px;">
            Shop on public feed:
            <?= !empty($rentSnapshot['shop_visible']) ? '<span class="pill ok">Visible</span>' : '<span class="pill bad">Hidden</span>' ?>
          </div>
        </div>
        <div class="detail-box">
          <div class="label">Current plan</div>
          <div class="value"><?= org_admin_h($rentSnapshot['plan_name'] ?? 'Shop Trial') ?></div>
          <div class="muted" style="margin-top:6px;">
            <?= org_admin_h(platform_rent_format_money((int)($rentSnapshot['plan_price_cents'] ?? 0), (string)($rentSnapshot['plan_currency'] ?? 'USD'))) ?>
          </div>
        </div>
        <div class="detail-box">
          <div class="label">Paid until / trial ends</div>
          <div class="value">
            <?php
              $until = trim((string)($rentSnapshot['rent_paid_until'] ?? ''));
              if ($until === '') {
                  $until = trim((string)($rentSnapshot['rent_trial_ends_at'] ?? ''));
              }
              echo $until !== '' ? org_admin_h(org_admin_fmt_dt($until)) : '—';
            ?>
          </div>
        </div>
      </div>

      <div style="padding:0 16px 16px;">
        <form method="post" style="margin-bottom:12px;">
          <div class="form-row">
            <div class="form-group col-md-3">
              <label class="mini-muted">Plan</label>
              <select name="plan_id" class="form-control" required>
                <?php foreach ($paidRentPlans as $plan): ?>
                  <option value="<?= (int)$plan['id'] ?>"><?= org_admin_h($plan['name'] ?? '') ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group col-md-2">
              <label class="mini-muted">Months</label>
              <select name="months_paid" class="form-control">
                <option value="1">1</option>
                <option value="3">3</option>
                <option value="6">6</option>
                <option value="12">12</option>
              </select>
            </div>
            <div class="form-group col-md-2">
              <label class="mini-muted">Method</label>
              <input type="text" name="payment_method" class="form-control" value="manual">
            </div>
            <div class="form-group col-md-3">
              <label class="mini-muted">Reference</label>
              <input type="text" name="payment_reference" class="form-control" placeholder="Transfer #">
            </div>
            <div class="form-group col-md-2" style="display:flex;align-items:flex-end;gap:8px;">
              <button type="submit" name="mark_rent_paid" class="btn-mini primary">Mark paid</button>
              <button type="submit" name="suspend_rent" class="btn-mini warn" onclick="return confirm('Suspend shop rent?');">Suspend</button>
            </div>
          </div>
          <input type="text" name="notes" class="form-control" placeholder="Payment notes (optional)">
        </form>

        <?php if ($rentPayments): ?>
        <div class="mini-muted" style="margin-bottom:8px;">Recent rent payments</div>
        <table class="table admin-table">
          <thead>
            <tr><th>Date</th><th>Plan</th><th>Amount</th><th>Months</th><th>Method</th></tr>
          </thead>
          <tbody>
            <?php foreach ($rentPayments as $pay): ?>
            <tr>
              <td class="muted"><?= org_admin_h(org_admin_fmt_dt($pay['paid_at'] ?? '')) ?></td>
              <td><?= org_admin_h($pay['plan_name'] ?? '') ?></td>
              <td><?= org_admin_h(platform_rent_format_money((int)($pay['amount_cents'] ?? 0), (string)($pay['currency'] ?? 'USD'))) ?></td>
              <td><?= (int)($pay['months_paid'] ?? 0) ?></td>
              <td class="muted"><?= org_admin_h($pay['payment_method'] ?? '') ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <div class="pro-tools">
        <strong>Members</strong>
        <span class="muted"><?= count($members) ?> membership row<?= count($members) === 1 ? '' : 's' ?></span>
      </div>

      <div class="table-scroll">
        <table class="table admin-table">
          <thead>
            <tr>
              <th>Type</th>
              <th>Account</th>
              <th>Role</th>
              <th>Status</th>
              <th>Joined</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
          <?php if (!$members): ?>
            <tr><td colspan="6" class="text-center muted" style="padding:28px;">No members found.</td></tr>
          <?php else: foreach ($members as $m): ?>
            <?php
              $memberType = (string)($m['member_type'] ?? '');
              $accountStatus = (int)($m['account_status'] ?? 0);
              $memberId = (int)($m['member_id'] ?? 0);
            ?>
            <tr>
              <td><span class="pill <?= $memberType === 'staff' ? 'warn' : 'info' ?>"><?= org_admin_h(ucfirst($memberType)) ?></span></td>
              <td>
                <div><strong><?= org_admin_h($m['member_username'] ?? '') ?></strong></div>
                <div class="muted"><?= org_admin_h($m['member_code'] ?? '') ?></div>
                <?php if (!empty($m['member_email'])): ?>
                  <div class="muted"><?= org_admin_h($m['member_email']) ?></div>
                <?php endif; ?>
              </td>
              <td><?= org_admin_h($m['role_name'] ?? '') ?><?php if (!empty($m['relationship_label'])): ?><div class="muted"><?= org_admin_h($m['relationship_label']) ?></div><?php endif; ?></td>
              <td><?= org_admin_status_badge($accountStatus) ?></td>
              <td class="muted"><?= org_admin_h(org_admin_fmt_dt($m['joined_at'] ?? '')) ?></td>
              <td>
                <?php if ($memberType === 'staff' && $memberId > 0): ?>
                  <form method="post" style="display:inline;" onsubmit="return confirm('Change staff status?');">
                    <input type="hidden" name="staff_id" value="<?= $memberId ?>">
                    <input type="hidden" name="status_value" value="<?= $accountStatus === 1 ? 0 : 1 ?>">
                    <button type="submit" name="set_staff_status" class="btn-mini <?= $accountStatus === 1 ? 'warn' : 'primary' ?>">
                      <?= $accountStatus === 1 ? 'Disable' : 'Activate' ?>
                    </button>
                  </form>
                <?php elseif ($memberType === 'manager' && $memberId > 0 && $memberId !== (int)($org['manager_id'] ?? 0)): ?>
                  <span class="muted">Use manager panel</span>
                <?php else: ?>
                  <span class="muted">Owner</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
<?php org_admin_render_foot(); ?>
