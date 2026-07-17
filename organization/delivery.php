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
$memberId = (int)orgMemberId();
$err = '';
$ok = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $orderId = (int)($_POST['order_id'] ?? 0);
    if (org_ecommerce_update_fulfillment($dbh, $orgId, $orderId, (string)($_POST['status'] ?? ''), (string)($_POST['seller_notes'] ?? ''), (string)($_POST['tracking_number'] ?? ''), (string)($_POST['carrier'] ?? ''))) {
        $ok = 'Delivery updated.';
    } else {
        $err = 'Could not update delivery.';
    }
}
$orders = org_sales_delivery_orders($dbh, $orgId);
$pageTitle = 'Delivery / Shipping';
require_once __DIR__ . '/includes/org_page_shell.php';
org_page_shell_open($pageTitle, '<link rel="stylesheet" href="css/commerce-hub.css?v=14">');
?>
<?php org_page_body_open('commerce-page'); ?>
  <div class="mg-b-20"><a href="sales_management.php" class="tx-12">&larr; Sales management</a><h4 class="mg-b-0">Delivery / Shipping</h4><p class="tx-color-03">Assign shipments, update carriers, track delivery status, and keep customers informed.</p></div>
  <?php if ($err): ?><div class="alert alert-danger"><?= org_ecommerce_h($err) ?></div><?php endif; ?>
  <?php if ($ok): ?><div class="alert alert-success"><?= org_ecommerce_h($ok) ?></div><?php endif; ?>
  <div class="card shadow-base"><div class="card-body pd-0 table-responsive"><table class="table table-hover mg-b-0"><thead><tr><th>Order</th><th>Buyer</th><th>Delivery</th><th>Status</th><th>Update shipment</th></tr></thead><tbody>
    <?php if (!$orders): ?><tr><td colspan="5" class="text-center tx-color-03">No orders to ship yet.</td></tr><?php endif; ?>
    <?php foreach ($orders as $o): ?><tr>
      <td><a href="order_details.php?id=<?= (int)$o['id'] ?>"><code><?= org_ecommerce_h((string)$o['order_code']) ?></code></a><div class="tx-12 tx-color-03"><?= org_ecommerce_h((string)$o['product_title']) ?></div></td>
      <td><?= org_ecommerce_h((string)($o['buyer_name'] ?: $o['buyer_email'] ?: 'Guest')) ?></td>
      <td class="tx-12"><?= org_ecommerce_h(strtoupper((string)($o['fulfillment_method'] ?? 'fbm'))) ?> · <?= org_ecommerce_h(str_replace('_', ' ', (string)($o['delivery_option'] ?? 'home_delivery'))) ?><br><?= org_ecommerce_h((string)($o['carrier'] ?? '')) ?> <?= org_ecommerce_h((string)($o['tracking_number'] ?? '')) ?></td>
      <td><span class="badge <?= org_sales_status_badge((string)$o['status']) ?>"><?= org_ecommerce_h((string)$o['status']) ?></span></td>
      <td><form method="post" class="mg-b-0"><input type="hidden" name="order_id" value="<?= (int)$o['id'] ?>"><div class="d-flex flex-wrap" style="gap:6px;"><select name="status" class="form-control form-control-sm" style="max-width:130px;"><?php foreach (['paid','shipped','delivered','cancelled'] as $s): ?><option value="<?= $s ?>" <?= (string)$o['status'] === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option><?php endforeach; ?></select><input name="carrier" class="form-control form-control-sm" placeholder="Carrier" value="<?= org_ecommerce_h((string)($o['carrier'] ?? '')) ?>" style="max-width:120px;"><input name="tracking_number" class="form-control form-control-sm" placeholder="Tracking" value="<?= org_ecommerce_h((string)($o['tracking_number'] ?? '')) ?>" style="max-width:150px;"><input name="seller_notes" class="form-control form-control-sm" placeholder="Customer update note" value="<?= org_ecommerce_h((string)($o['seller_notes'] ?? '')) ?>" style="max-width:220px;"><button class="btn btn-sm btn-primary">Save</button></div></form></td>
    </tr><?php endforeach; ?>
  </tbody></table></div></div>
</div>
<?php org_page_shell_close(); ?>
