<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/session_org.php';
require_once __DIR__ . '/includes/org_context.php';
require_once __DIR__ . '/includes/org_manager_guard.php';
require_once __DIR__ . '/includes/org_sales.php';

org_require_manager();

org_require_commerce_seller();
org_ecommerce_ensure_schema($dbh);

$orgId = (int)orgActiveOrgId();
$err = '';
$ok = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_return'])) {
        $returnId = (int)($_POST['return_id'] ?? 0);
        $status = strtolower(trim((string)($_POST['return_status'] ?? '')));
        $note = trim((string)($_POST['seller_notes'] ?? ''));
        if (org_sales_update_return_request($dbh, $orgId, $returnId, $status, $note)) {
            $ok = 'Return request updated (' . $status . ').';
        } else {
            $err = 'Could not update return request.';
        }
    } elseif (isset($_POST['manual_cancel'])) {
        $orderId = (int)($_POST['order_id'] ?? 0);
        $note = 'Return/refund: ' . trim((string)($_POST['return_note'] ?? ''));
        if (org_ecommerce_update_fulfillment($dbh, $orgId, $orderId, 'cancelled', $note, '', '')) {
            $ok = 'Order cancelled with seller note.';
        } else {
            $err = 'Could not cancel order.';
        }
    }
}

$requests = org_sales_return_requests($dbh, $orgId);
$orders = org_sales_returns_candidates($dbh, $orgId);
$pageTitle = 'Returns & Refunds';
require_once __DIR__ . '/includes/org_page_shell.php';
org_page_shell_open($pageTitle, '<link rel="stylesheet" href="css/commerce-hub.css?v=15">');
?>
<?php org_page_body_open('commerce-page'); ?>
  <div class="mg-b-20">
    <a href="sales_management.php" class="tx-12">&larr; Sales management</a>
    <h4 class="mg-b-0">Returns &amp; Refunds</h4>
    <p class="tx-color-03">Buyer return requests from the shop feed into this inbox so sellers can approve, reject, or refund them.</p>
  </div>
  <?php if ($err): ?><div class="alert alert-danger"><?= org_ecommerce_h($err) ?></div><?php endif; ?>
  <?php if ($ok): ?><div class="alert alert-success"><?= org_ecommerce_h($ok) ?></div><?php endif; ?>

  <div class="card shadow-base mg-b-20">
    <div class="card-header"><h6 class="card-title tx-14 mg-b-0">Buyer return requests</h6></div>
    <div class="card-body pd-0 table-responsive">
      <table class="table table-hover mg-b-0">
        <thead>
          <tr>
            <th>Order</th>
            <th>Buyer</th>
            <th>Reason</th>
            <th>Status</th>
            <th>Seller action</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$requests): ?>
            <tr><td colspan="5" class="text-center tx-color-03">No buyer return requests yet.</td></tr>
          <?php endif; ?>
          <?php foreach ($requests as $r): ?>
            <tr>
              <td>
                <a href="order_details.php?id=<?= (int)$r['order_id'] ?>"><code><?= org_ecommerce_h((string)$r['order_code']) ?></code></a>
                <div class="tx-12 tx-color-03"><?= org_ecommerce_h((string)$r['product_title']) ?></div>
                <div class="tx-12"><?= org_ecommerce_h(org_sales_money((int)$r['total_cents'], (string)$r['currency'])) ?></div>
              </td>
              <td>
                <?= org_ecommerce_h((string)($r['buyer_name'] ?: $r['buyer_email'] ?: 'Buyer')) ?>
                <?php if (!empty($r['buyer_email'])): ?>
                  <div class="tx-12 tx-color-03"><?= org_ecommerce_h((string)$r['buyer_email']) ?></div>
                <?php endif; ?>
              </td>
              <td class="tx-12"><?= org_ecommerce_h((string)$r['reason']) ?></td>
              <td><span class="badge badge-secondary"><?= org_ecommerce_h((string)$r['status']) ?></span></td>
              <td>
                <form method="post" class="d-flex flex-column" style="gap:6px;min-width:220px;">
                  <input type="hidden" name="update_return" value="1">
                  <input type="hidden" name="return_id" value="<?= (int)$r['id'] ?>">
                  <select name="return_status" class="form-control form-control-sm">
                    <?php foreach (['requested','approved','rejected','refunded'] as $st): ?>
                      <option value="<?= $st ?>"<?= ((string)$r['status'] === $st) ? ' selected' : '' ?>><?= $st ?></option>
                    <?php endforeach; ?>
                  </select>
                  <input name="seller_notes" class="form-control form-control-sm" placeholder="Seller notes / refund method"
                         value="<?= org_ecommerce_h((string)($r['seller_notes'] ?? '')) ?>">
                  <button class="btn btn-sm btn-primary">Update</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card shadow-base">
    <div class="card-header"><h6 class="card-title tx-14 mg-b-0">Manual cancel (delivered / cancelled orders)</h6></div>
    <div class="card-body pd-0 table-responsive">
      <table class="table table-hover mg-b-0">
        <thead><tr><th>Order</th><th>Customer</th><th>Total</th><th>Status</th><th>Action</th></tr></thead>
        <tbody>
          <?php if (!$orders): ?><tr><td colspan="5" class="text-center tx-color-03">No delivered or cancelled orders yet.</td></tr><?php endif; ?>
          <?php foreach ($orders as $o): ?>
            <tr>
              <td>
                <a href="order_details.php?id=<?= (int)$o['id'] ?>"><code><?= org_ecommerce_h((string)$o['order_code']) ?></code></a>
                <div class="tx-12 tx-color-03"><?= org_ecommerce_h((string)$o['product_title']) ?></div>
              </td>
              <td><?= org_ecommerce_h((string)($o['buyer_name'] ?: $o['buyer_email'] ?: 'Guest')) ?></td>
              <td><?= org_ecommerce_h(org_sales_money((int)$o['total_cents'], (string)$o['currency'])) ?></td>
              <td><span class="badge <?= org_sales_status_badge((string)$o['status']) ?>"><?= org_ecommerce_h((string)$o['status']) ?></span></td>
              <td>
                <?php if ((string)$o['status'] !== 'cancelled'): ?>
                  <form method="post" class="d-flex" style="gap:6px;">
                    <input type="hidden" name="manual_cancel" value="1">
                    <input type="hidden" name="order_id" value="<?= (int)$o['id'] ?>">
                    <input name="return_note" class="form-control form-control-sm" placeholder="Reason, refund method, restock" value="<?= org_ecommerce_h((string)($o['seller_notes'] ?? '')) ?>">
                    <button class="btn btn-sm btn-outline-danger">Cancel</button>
                  </form>
                <?php else: ?>
                  <span class="tx-12 tx-color-03">Already cancelled</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php org_page_shell_close(); ?>
