<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/org_admin_helpers_load.php';
require_once __DIR__ . '/../public_user/includes/platform_rent.php';
org_admin_require_admin();

error_reporting(E_ALL);
ini_set('display_errors', '1');

$dbh = org_admin_db();
platform_rent_ensure_schema($dbh);

$msg = '';
$error = '';
$adminId = (int)($_SESSION['admin_id'] ?? $_SESSION['idadmin'] ?? 0);

$filter = strtolower(trim((string)($_GET['filter'] ?? 'all')));
if (!in_array($filter, ['all', 'trial', 'active', 'overdue', 'suspended'], true)) {
    $filter = 'all';
}
$search = trim((string)($_GET['q'] ?? ''));

if (isset($_POST['mark_rent_paid'])) {
    $orgId = (int)($_POST['org_id'] ?? 0);
    $planId = (int)($_POST['plan_id'] ?? 0);
    $months = (int)($_POST['months_paid'] ?? 1);
    $method = trim((string)($_POST['payment_method'] ?? 'manual'));
    $reference = trim((string)($_POST['payment_reference'] ?? ''));
    $notes = trim((string)($_POST['notes'] ?? ''));

    if ($orgId <= 0 || $planId <= 0) {
        $error = 'Organization and plan are required.';
    } elseif (platform_rent_mark_paid($dbh, $orgId, $planId, $months, $adminId, $method, $reference, $notes)) {
        $msg = 'Rent payment recorded. Shop access extended.';
    } else {
        $error = 'Could not record rent payment.';
    }
}

if (isset($_POST['suspend_rent'])) {
    $orgId = (int)($_POST['org_id'] ?? 0);
    if ($orgId <= 0) {
        $error = 'Invalid organization.';
    } elseif (platform_rent_suspend($dbh, $orgId)) {
        $msg = 'Shop rent suspended. Public shop is now hidden.';
    } else {
        $error = 'Could not suspend rent.';
    }
}

$plans = platform_rent_list_plans($dbh, false);
$paidPlans = array_values(array_filter($plans, static fn(array $p): bool => (int)($p['price_cents'] ?? 0) > 0));

$where = ['(o.is_publisher_org = 1 OR o.org_kind = \'shop\')'];
$params = [];

if ($filter !== 'all') {
    $where[] = 'o.rent_status = :rent_status';
    $params[':rent_status'] = $filter;
}

if ($search !== '') {
    $where[] = '(o.name LIKE :q OR o.org_code LIKE :q OR m.username LIKE :q OR u.username LIKE :q)';
    $params[':q'] = '%' . $search . '%';
}

$sql = '
    SELECT
        o.id, o.org_code, o.name, o.status, o.org_kind, o.is_publisher_org,
        o.rent_status, o.rent_paid_until, o.rent_trial_ends_at, o.platform_plan_id,
        m.username AS manager_username,
        u.id AS pub_user_id, u.username AS pub_username,
        p.name AS plan_name, p.price_cents AS plan_price_cents, p.currency AS plan_currency
    FROM organizations o
    JOIN managers m ON m.id = o.owner_manager_id
    LEFT JOIN users u ON u.id = o.publisher_user_id
    LEFT JOIN platform_plans p ON p.id = o.platform_plan_id
    WHERE ' . implode(' AND ', $where) . '
    ORDER BY
      FIELD(o.rent_status, \'overdue\', \'suspended\', \'trial\', \'active\'),
      o.rent_paid_until ASC,
      o.id DESC
';

$rows = [];
try {
    $st = $dbh->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as &$row) {
        $orgId = (int)($row['id'] ?? 0);
        $row['rent_status_live'] = platform_rent_sync_org_status($dbh, $orgId);
        $row['shop_visible'] = platform_rent_shop_is_visible($dbh, $orgId);
    }
    unset($row);
} catch (Throwable $e) {
    $rows = [];
}

org_admin_render_head('Shop Rent');
?>
<div class="sh-logopanel"><a href="" class="sh-logo-text">Talentra Admin</a></div>
<div class="sh-headpanel"></div>
<?php include __DIR__ . '/includes/leftbar.php'; ?>

<div class="sh-mainpanel">
  <div class="sh-pagetitle">
    <div class="sh-pagetitle-left">
      <div class="sh-pagetitle-icon"><i class="icon ion-card"></i></div>
      <div>
        <h2>Shop Rent</h2>
        <p class="mg-b-0">Monthly rent for seller shop organizations</p>
      </div>
    </div>
    <div class="sh-pagetitle-right">
      <a href="orglist.php" class="btn-mini">All organizations</a>
    </div>
  </div>

  <div class="sh-pagebody">
    <?php if ($msg !== ''): ?><div class="alert-lite ok"><?= org_admin_h($msg) ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="alert-lite bad"><?= org_admin_h($error) ?></div><?php endif; ?>

    <div class="card admin-card" style="margin-bottom:16px;">
      <div class="card-header pro">
        Plans
        <div class="sub">What sellers pay you each month to keep their shop live</div>
      </div>
      <div class="detail-grid" style="padding:16px;">
        <?php foreach ($plans as $plan): ?>
          <div class="detail-box">
            <div class="label"><?= org_admin_h($plan['name'] ?? '') ?></div>
            <div class="value"><?= org_admin_h(platform_rent_format_money((int)($plan['price_cents'] ?? 0), (string)($plan['currency'] ?? 'USD'))) ?><?php if ((string)($plan['billing_interval'] ?? '') === 'monthly'): ?>/mo<?php endif; ?></div>
            <div class="muted" style="margin-top:6px;">Up to <?= (int)($plan['max_products'] ?? 0) ?> products<?php if ((int)($plan['trial_days'] ?? 0) > 0): ?> · <?= (int)$plan['trial_days'] ?>-day trial<?php endif; ?></div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="card admin-card">
      <div class="pro-tools">
        <div class="filter-tabs">
          <?php
            $tabs = [
              'all' => 'All shops',
              'overdue' => 'Overdue',
              'trial' => 'Trial',
              'active' => 'Paid',
              'suspended' => 'Suspended',
            ];
            foreach ($tabs as $key => $label):
              $href = 'org_rent.php?filter=' . rawurlencode($key) . ($search !== '' ? '&q=' . rawurlencode($search) : '');
          ?>
            <a href="<?= org_admin_h($href) ?>" class="<?= $filter === $key ? 'is-active' : '' ?>"><?= org_admin_h($label) ?></a>
          <?php endforeach; ?>
        </div>
        <form class="search-form" method="get" action="org_rent.php">
          <input type="hidden" name="filter" value="<?= org_admin_h($filter) ?>">
          <input type="text" name="q" value="<?= org_admin_h($search) ?>" placeholder="Search shop org…">
          <button type="submit" class="btn-mini primary">Search</button>
        </form>
      </div>

      <div class="table-scroll">
        <table class="table admin-table">
          <thead>
            <tr>
              <th>Shop org</th>
              <th>Publisher</th>
              <th>Plan</th>
              <th>Rent status</th>
              <th>Paid / trial until</th>
              <th>Shop live</th>
              <th>Record payment</th>
            </tr>
          </thead>
          <tbody>
          <?php if (!$rows): ?>
            <tr><td colspan="7" class="text-center muted" style="padding:28px;">No shop organizations found.</td></tr>
          <?php else: foreach ($rows as $row): ?>
            <?php
              $orgId = (int)($row['id'] ?? 0);
              $liveStatus = (string)($row['rent_status_live'] ?? $row['rent_status'] ?? 'trial');
              $until = trim((string)($row['rent_paid_until'] ?? ''));
              if ($until === '') {
                  $until = trim((string)($row['rent_trial_ends_at'] ?? ''));
              }
            ?>
            <tr>
              <td>
                <div><strong><?= org_admin_h($row['name'] ?? '') ?></strong></div>
                <div class="muted"><?= org_admin_h($row['org_code'] ?? '') ?></div>
                <a class="btn-mini" href="orgdetail.php?id=<?= $orgId ?>">Details</a>
              </td>
              <td>
                <?php if (!empty($row['pub_user_id'])): ?>
                  <?= org_admin_render_public_user_link((int)$row['pub_user_id'], (string)($row['pub_username'] ?? ''), (string)($row['pub_username'] ?? ''), '') ?>
                <?php else: ?>
                  <span class="muted"><?= org_admin_h($row['manager_username'] ?? '') ?></span>
                <?php endif; ?>
              </td>
              <td>
                <div><?= org_admin_h($row['plan_name'] ?? 'Shop Trial') ?></div>
                <div class="muted"><?= org_admin_h(platform_rent_format_money((int)($row['plan_price_cents'] ?? 0), (string)($row['plan_currency'] ?? 'USD'))) ?></div>
              </td>
              <td><?= platform_rent_status_badge($liveStatus) ?></td>
              <td class="muted"><?= $until !== '' ? org_admin_h(org_admin_fmt_dt($until)) : '—' ?></td>
              <td><?= !empty($row['shop_visible']) ? '<span class="pill ok">Visible</span>' : '<span class="pill bad">Hidden</span>' ?></td>
              <td style="min-width:280px;">
                <form method="post" class="rent-pay-form">
                  <input type="hidden" name="org_id" value="<?= $orgId ?>">
                  <div style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:6px;">
                    <select name="plan_id" class="form-control" style="min-width:120px;" required>
                      <?php foreach ($paidPlans as $plan): ?>
                        <option value="<?= (int)$plan['id'] ?>"><?= org_admin_h($plan['name'] ?? '') ?> (<?= org_admin_h(platform_rent_format_money((int)($plan['price_cents'] ?? 0), (string)($plan['currency'] ?? 'USD'))) ?>)</option>
                      <?php endforeach; ?>
                    </select>
                    <select name="months_paid" class="form-control" style="width:90px;">
                      <option value="1">1 mo</option>
                      <option value="3">3 mo</option>
                      <option value="6">6 mo</option>
                      <option value="12">12 mo</option>
                    </select>
                  </div>
                  <div style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:6px;">
                    <input type="text" name="payment_method" class="form-control" placeholder="Method (cash, transfer…)" value="manual">
                    <input type="text" name="payment_reference" class="form-control" placeholder="Reference #">
                  </div>
                  <input type="text" name="notes" class="form-control" placeholder="Notes (optional)" style="margin-bottom:6px;">
                  <div style="display:flex;gap:6px;flex-wrap:wrap;">
                    <button type="submit" name="mark_rent_paid" class="btn-mini primary">Mark rent paid</button>
                    <button type="submit" name="suspend_rent" class="btn-mini warn" onclick="return confirm('Suspend this shop? Public storefront will be hidden.');">Suspend</button>
                  </div>
                </form>
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
