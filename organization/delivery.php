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
    $newStatus = strtolower(trim((string)($_POST['status'] ?? '')));
    $blockFulfill = false;
    if ($orderId > 0 && $newStatus !== 'cancelled') {
        try {
            $stPay = $dbh->prepare('SELECT * FROM org_orders WHERE id = :id AND org_id = :org LIMIT 1');
            $stPay->execute([':id' => $orderId, ':org' => $orgId]);
            $payOrder = $stPay->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($payOrder && function_exists('org_shop_order_fulfillment_locked') && org_shop_order_fulfillment_locked($payOrder)) {
                $blockFulfill = true;
                $err = 'Fulfillment is locked until the customer pays in full.';
            }
        } catch (Throwable $e) {
            // continue
        }
    }
    if (!$blockFulfill && org_ecommerce_update_fulfillment_customer_batch($dbh, $orgId, $orderId, $newStatus, (string)($_POST['seller_notes'] ?? ''), (string)($_POST['tracking_number'] ?? ''), (string)($_POST['carrier'] ?? ''))) {
        $ok = 'Delivery updated.';
    } elseif (!$blockFulfill) {
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
    <?php foreach ($orders as $o):
      $oLocked = function_exists('org_shop_order_fulfillment_locked') && org_shop_order_fulfillment_locked($o);
    ?><tr>
      <td><a href="order_details.php?id=<?= (int)$o['id'] ?>"><code><?= org_ecommerce_h((string)$o['order_code']) ?></code></a><div class="tx-12 tx-color-03"><?= org_ecommerce_h((string)$o['product_title']) ?></div></td>
      <td><?= org_ecommerce_h((string)($o['buyer_name'] ?: $o['buyer_email'] ?: 'Guest')) ?></td>
      <td class="tx-12"><?= org_ecommerce_h(strtoupper((string)($o['fulfillment_method'] ?? 'fbm'))) ?> · <?= org_ecommerce_h(str_replace('_', ' ', (string)($o['delivery_option'] ?? 'home_delivery'))) ?><br><?= org_ecommerce_h((string)($o['carrier'] ?? '')) ?> <?= org_ecommerce_h((string)($o['tracking_number'] ?? '')) ?></td>
      <td><span class="badge <?= org_sales_status_badge((string)$o['status']) ?>"><?= org_ecommerce_h((string)$o['status']) ?></span><?php if ($oLocked): ?><div class="tx-12" style="color:#b91c1c;margin-top:4px;">Payment incomplete — do not ship</div><?php endif; ?></td>
      <td><?php if ($oLocked): ?><span class="tx-12 tx-color-03">Fulfillment locked until paid in full.</span><?php else: ?><form method="post" class="mg-b-0"><input type="hidden" name="order_id" value="<?= (int)$o['id'] ?>"><div class="d-flex flex-wrap" style="gap:6px;"><select name="status" class="form-control form-control-sm" style="max-width:130px;"><?php foreach (['paid','shipped','delivered','cancelled'] as $s): ?><option value="<?= $s ?>" <?= (string)$o['status'] === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option><?php endforeach; ?></select><input name="carrier" class="form-control form-control-sm" placeholder="Carrier" value="<?= org_ecommerce_h((string)($o['carrier'] ?? '')) ?>" style="max-width:120px;"><input name="tracking_number" class="form-control form-control-sm" placeholder="Tracking" value="<?= org_ecommerce_h((string)($o['tracking_number'] ?? '')) ?>" style="max-width:150px;"><input name="seller_notes" class="form-control form-control-sm" placeholder="Customer update note" value="<?= org_ecommerce_h((string)($o['seller_notes'] ?? '')) ?>" style="max-width:220px;"><button class="btn btn-sm btn-primary">Save</button></div></form><?php endif; ?></td>
    </tr><?php endforeach; ?>
  </tbody></table></div></div>
</div>
<?php org_page_shell_close(); ?>
