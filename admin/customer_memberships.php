<?php
declare(strict_types=1);

/**
 * Admin: Customer Plus membership ($10/mo) revenue and active members.
 */
require_once __DIR__ . '/includes/org_admin_helpers_load.php';
require_once __DIR__ . '/../public_user/includes/buyer_membership.php';

org_admin_require_admin();

error_reporting(E_ALL);
ini_set('display_errors', '1');

$dbh = org_admin_db();
buyer_membership_ensure_schema($dbh);

$filter = strtolower(trim((string)($_GET['filter'] ?? 'all')));
if (!in_array($filter, ['all', 'active', 'expired'], true)) {
    $filter = 'all';
}
$search = trim((string)($_GET['q'] ?? ''));

$msg = '';
$error = '';
$adminId = (int)($_SESSION['admin_id'] ?? $_SESSION['idadmin'] ?? 0);

if (isset($_POST['mark_membership_paid'])) {
    $userId = (int)($_POST['user_id'] ?? 0);
    $months = max(1, min(12, (int)($_POST['months_paid'] ?? 1)));
    $method = trim((string)($_POST['payment_method'] ?? 'manual'));
    $reference = trim((string)($_POST['payment_reference'] ?? ''));
    if ($userId <= 0) {
        $error = 'User is required.';
    } elseif (buyer_membership_mark_paid($dbh, $userId, $months, $method !== '' ? $method : 'manual', $reference, 'Admin recorded membership')) {
        $msg = 'Membership payment recorded.';
    } else {
        $error = 'Could not record membership payment.';
    }
}

$rows = buyer_membership_admin_list($dbh, $filter, $search, 250);
$revenueCents = buyer_membership_revenue_cents($dbh);
$activeCount = 0;
foreach ($rows as $row) {
    if (($row['status_live'] ?? '') === 'active') {
        $activeCount++;
    }
}

org_admin_render_head('Customer Membership');
?>
<?php
require_once __DIR__ . '/includes/admin_chrome.php';
admin_chrome_open('Customer Membership');
?>

<div class="sh-mainpanel">
  <div class="sh-pagebody">
    <?php if ($msg !== ''): ?><div class="alert-lite ok"><?= org_admin_h($msg) ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="alert-lite bad"><?= org_admin_h($error) ?></div><?php endif; ?>

    <div class="card admin-card" style="margin-bottom:16px;">
      <div class="card-header pro">
        Customer Plus — $10/month
        <div class="sub">Optional membership. Members pay $0 service fee on shop orders (normally $1.99).</div>
      </div>
      <div class="detail-grid" style="padding:16px;">
        <div class="detail-box">
          <div class="label">Membership revenue</div>
          <div class="value"><?= org_admin_h(platform_rent_format_money($revenueCents)) ?></div>
        </div>
        <div class="detail-box">
          <div class="label">Listed members</div>
          <div class="value"><?= (int)count($rows) ?></div>
        </div>
        <div class="detail-box">
          <div class="label">Active now</div>
          <div class="value"><?= (int)$activeCount ?></div>
        </div>
      </div>
    </div>

    <div class="card admin-card sh-admin-table-card">
      <div class="card-header">Members</div>
      <div class="pro-tools">
        <div class="filter-tabs">
          <?php
            $tabs = ['all' => 'All', 'active' => 'Active', 'expired' => 'Expired'];
            foreach ($tabs as $key => $label):
              $href = 'customer_memberships.php?filter=' . rawurlencode($key) . ($search !== '' ? '&q=' . rawurlencode($search) : '');
          ?>
            <a href="<?= org_admin_h($href) ?>" class="<?= $filter === $key ? 'is-active' : '' ?>"><?= org_admin_h($label) ?></a>
          <?php endforeach; ?>
        </div>
        <form class="search-form" method="get" action="customer_memberships.php">
          <input type="hidden" name="filter" value="<?= org_admin_h($filter) ?>">
          <input type="text" name="q" value="<?= org_admin_h($search) ?>" placeholder="Search user…">
          <button type="submit" class="btn-mini primary">Search</button>
        </form>
      </div>
      <div class="card-body-fixed">
        <div class="table-scroll">
          <table class="table table-bordered table-hover mg-b-0 admin-table">
            <thead>
              <tr>
                <th>Customer</th>
                <th>Status</th>
                <th>Paid until</th>
                <th>Record payment</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!$rows): ?>
                <tr><td colspan="4" class="text-center tx-color-03">No memberships yet.</td></tr>
              <?php else: ?>
                <?php foreach ($rows as $row): ?>
                  <?php
                    $uid = (int)($row['user_id'] ?? 0);
                    $live = (string)($row['status_live'] ?? 'expired');
                    $until = trim((string)($row['paid_until'] ?? ''));
                    $label = trim((string)($row['fullname'] ?? ''));
                    if ($label === '') {
                        $label = trim((string)($row['username'] ?? 'User #' . $uid));
                    }
                  ?>
                  <tr>
                    <td>
                      <strong><?= org_admin_h($label) ?></strong>
                      <div class="muted"><?= org_admin_h((string)($row['email'] ?? '')) ?></div>
                    </td>
                    <td>
                      <?php if ($live === 'active'): ?>
                        <span class="pill ok">Active</span>
                      <?php else: ?>
                        <span class="pill bad"><?= org_admin_h(ucfirst($live)) ?></span>
                      <?php endif; ?>
                    </td>
                    <td class="muted"><?= $until !== '' ? org_admin_h(org_admin_fmt_dt($until)) : '—' ?></td>
                    <td style="min-width:240px;">
                      <form method="post" class="rent-pay-form">
                        <input type="hidden" name="user_id" value="<?= $uid ?>">
                        <div style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:6px;">
                          <select name="months_paid" class="form-control" style="width:90px;">
                            <option value="1">1 mo</option>
                            <option value="3">3 mo</option>
                            <option value="6">6 mo</option>
                            <option value="12">12 mo</option>
                          </select>
                          <input type="text" name="payment_method" class="form-control" placeholder="Method" value="manual" style="min-width:100px;">
                        </div>
                        <input type="text" name="payment_reference" class="form-control" placeholder="Reference #" style="margin-bottom:6px;">
                        <button type="submit" name="mark_membership_paid" class="btn-mini primary">Mark paid ($10/mo)</button>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>
<?php org_admin_render_foot(); ?>
