<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/includes/session_org.php';
require_once __DIR__ . '/includes/org_context.php';
require_once __DIR__ . '/../public_user/includes/org_shop.php';
require_once __DIR__ . '/includes/org_ecommerce.php';
require_once __DIR__ . '/includes/org_manager_guard.php';

org_require_manager();
org_ecommerce_ensure_schema($dbh);

$orgId = (int)orgActiveOrgId();
$memberId = (int)orgMemberId();
$err = '';
$ok = '';

$statusFilter = strtolower(trim((string)($_GET['status'] ?? 'all')));
$allowedFilters = ['all', 'pending', 'confirmed', 'paid', 'shipped', 'delivered', 'cancelled'];
if (!in_array($statusFilter, $allowedFilters, true)) {
    $statusFilter = 'all';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $orderId = (int)($_POST['order_id'] ?? 0);
    $newStatus = strtolower(trim((string)($_POST['status'] ?? '')));
    $sellerNotes = trim((string)($_POST['seller_notes'] ?? ''));
    $tracking = trim((string)($_POST['tracking_number'] ?? ''));
    $carrier = trim((string)($_POST['carrier'] ?? ''));

    if (isset($_POST['sync_crm'])) {
        if (org_ecommerce_sync_buyer_to_crm($dbh, $orgId, $orderId, $memberId)) {
            $ok = 'Buyer synced to CRM.';
        } else {
            $err = 'Could not sync buyer to CRM.';
        }
    } elseif (org_ecommerce_update_fulfillment($dbh, $orgId, $orderId, $newStatus, $sellerNotes, $tracking, $carrier)) {
        $ok = 'Order updated.';
    } else {
        $err = 'Could not update order.';
    }
}

$orders = org_shop_list_orders($dbh, $orgId, $statusFilter);

$pageTitle = 'Orders';
require_once __DIR__ . '/includes/org_page_shell.php';
org_page_shell_open($pageTitle);
?>
<div class="sh-pagebody">
  <div class="card bd-0 shadow-base">
    <div class="card-header d-flex align-items-center justify-content-between flex-wrap">
      <div>
        <h6 class="card-title tx-uppercase tx-14 mg-b-0">Order management (OMS)</h6>
        <p class="mg-b-0 tx-12 tx-color-03">Fulfillment, tracking, and CRM sync</p>
      </div>
      <div class="d-flex flex-wrap align-items-center" style="gap:8px;">
        <a href="commerce.php" class="btn btn-sm btn-outline-secondary">Commerce hub</a>
        <div class="btn-group btn-group-sm">
        <?php foreach ($allowedFilters as $f): ?>
          <a href="orders.php?status=<?= h($f) ?>" class="btn btn-outline-secondary<?= $statusFilter === $f ? ' active' : '' ?>"><?= h(ucfirst($f)) ?></a>
        <?php endforeach; ?>
        </div>
      </div>
    </div>
    <div class="card-body">
      <?php if ($err !== ''): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endif; ?>
      <?php if ($ok !== ''): ?><div class="alert alert-success"><?= h($ok) ?></div><?php endif; ?>

      <div class="table-responsive">
        <table class="table table-hover mg-b-0">
          <thead>
            <tr>
              <th>Code</th>
              <th>Product</th>
              <th>Buyer</th>
              <th>Total</th>
              <th>Status</th>
              <th>Fulfillment</th>
              <th>Date</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$orders): ?>
              <tr><td colspan="7" class="text-center tx-color-03">No orders yet.</td></tr>
            <?php else: foreach ($orders as $o): ?>
              <?php
                $total = org_shop_format_price((int)($o['total_cents'] ?? 0), (string)($o['currency'] ?? 'USD'));
                $buyer = trim((string)($o['buyer_name'] ?? ''));
                if ($buyer === '' && trim((string)($o['buyer_username'] ?? '')) !== '') {
                    $buyer = '@' . (string)$o['buyer_username'];
                }
                if ($buyer === '') {
                    $buyer = trim((string)($o['buyer_email'] ?? '')) ?: 'Guest';
                }
              ?>
              <tr>
                <td><code><?= h((string)$o['order_code']) ?></code></td>
                <td>
                  <?= h((string)$o['product_title']) ?>
                  <div class="tx-12 tx-color-03">Qty <?= (int)($o['quantity'] ?? 1) ?></div>
                </td>
                <td><?= h($buyer) ?></td>
                <td><?= h($total) ?></td>
                <td><span class="badge badge-light"><?= h((string)$o['status']) ?></span></td>
                <td>
                  <form method="post" class="mg-b-0">
                    <input type="hidden" name="order_id" value="<?= (int)$o['id'] ?>">
                    <div class="form-group mg-b-5">
                      <select name="status" class="form-control form-control-sm">
                        <?php foreach (['pending', 'confirmed', 'paid', 'shipped', 'delivered', 'cancelled'] as $st): ?>
                          <option value="<?= h($st) ?>" <?= ((string)$o['status'] === $st) ? 'selected' : '' ?>><?= h(ucfirst($st)) ?></option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                    <div class="form-group mg-b-5">
                      <input type="text" name="carrier" class="form-control form-control-sm" placeholder="Carrier" value="<?= h((string)($o['carrier'] ?? '')) ?>">
                    </div>
                    <div class="form-group mg-b-5">
                      <input type="text" name="tracking_number" class="form-control form-control-sm" placeholder="Tracking #" value="<?= h((string)($o['tracking_number'] ?? '')) ?>">
                    </div>
                    <input type="text" name="seller_notes" class="form-control form-control-sm mg-b-5" placeholder="Seller note" value="<?= h((string)($o['seller_notes'] ?? '')) ?>">
                    <button type="submit" class="btn btn-sm btn-primary btn-block">Save</button>
                  </form>
                  <form method="post" class="mg-t-5">
                    <input type="hidden" name="order_id" value="<?= (int)$o['id'] ?>">
                    <input type="hidden" name="sync_crm" value="1">
                    <button type="submit" class="btn btn-sm btn-outline-secondary btn-block">Sync to CRM</button>
                  </form>
                  <?php if (trim((string)($o['buyer_notes'] ?? '')) !== ''): ?>
                    <div class="tx-11 tx-color-03 mg-t-5">Buyer: <?= h((string)$o['buyer_notes']) ?></div>
                  <?php endif; ?>
                  <?php if (trim((string)($o['delivery_address'] ?? '')) !== ''): ?>
                    <div class="tx-11 tx-color-03">Ship to: <?= h((string)$o['delivery_address']) ?></div>
                  <?php endif; ?>
                  <?php if (!empty($o['shipped_at'])): ?>
                    <div class="tx-11 tx-color-03">Shipped: <?= h((string)$o['shipped_at']) ?></div>
                  <?php endif; ?>
                </td>
                <td class="tx-12"><?= h((string)($o['created_at'] ?? '')) ?></td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
<?php org_page_shell_close(); ?>
