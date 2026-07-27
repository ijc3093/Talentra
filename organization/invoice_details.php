<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/session_org.php';
require_once __DIR__ . '/includes/org_context.php';
require_once __DIR__ . '/includes/org_manager_guard.php';
require_once __DIR__ . '/includes/org_sales.php';
require_once __DIR__ . '/../public_user/includes/org_shop.php';

org_require_manager();

org_require_commerce_seller();

$orgId = (int)orgActiveOrgId();
$id = (int)($_GET['id'] ?? 0);
org_crm_lifecycle_ensure_schema($dbh);
org_shop_ensure_schema($dbh);
$invoice = null;
foreach (org_crm_list_invoices($dbh, $orgId) as $inv) {
    if ((int)$inv['id'] === $id) {
        $invoice = $inv;
        break;
    }
}
if (!$invoice) {
    header('Location: invoices.php');
    exit;
}

$relatedOrderId = (int)($invoice['related_order_id'] ?? 0);
$orderProductIds = [];
$orderLines = [];
if ($relatedOrderId > 0) {
    $order = org_sales_order($dbh, $orgId, $relatedOrderId);
    if ($order) {
        $batchLines = org_shop_seller_order_batch($dbh, $orgId, $order);
        foreach ($batchLines as $line) {
            $oid = (int)($line['id'] ?? 0);
            $unit = trim((string)($line['product_unit_code'] ?? ''));
            if ($unit === '' && $oid > 0) {
                $unit = org_shop_ensure_product_unit_code($dbh, $oid, '');
            }
            if ($unit !== '' && !in_array($unit, $orderProductIds, true)) {
                $orderProductIds[] = $unit;
            }
            $orderLines[] = [
                'title' => trim((string)($line['product_title'] ?? 'Product')) ?: 'Product',
                'qty' => max(1, (int)($line['quantity'] ?? 1)),
                'product_unit_code' => $unit,
                'amount_cents' => (int)($line['total_cents'] ?? 0),
            ];
        }
    }
}

$pageTitle = 'Invoice details';
require_once __DIR__ . '/includes/org_page_shell.php';
org_page_shell_open($pageTitle, '<link rel="stylesheet" href="css/commerce-hub.css?v=14">');
?>
<?php org_page_body_open('commerce-page'); ?>
  <div class="mg-b-20"><a href="invoices.php" class="tx-12">&larr; Invoices</a><h4 class="mg-b-0"><?= org_ecommerce_h((string)$invoice['invoice_code']) ?></h4><p class="tx-color-03">Invoice items, taxes, discounts, and payment information.</p></div>
  <div class="row row-sm">
    <div class="col-lg-8">
      <div class="card shadow-base">
        <div class="card-header"><h6 class="card-title tx-14 mg-b-0">Invoice item</h6></div>
        <div class="card-body pd-0 table-responsive"><table class="table mg-b-0"><thead><tr><th>Product ID</th><th>Description</th><th>Qty</th><th>Subtotal</th><th>Discount</th><th>Tax</th><th>Total</th></tr></thead><tbody>
          <?php if ($orderLines): ?>
            <?php foreach ($orderLines as $line): ?>
              <tr>
                <td><?= $line['product_unit_code'] !== '' ? '<code>' . org_ecommerce_h($line['product_unit_code']) . '</code>' : '<span class="tx-color-03">—</span>' ?></td>
                <td><?= org_ecommerce_h($line['title']) ?></td>
                <td><?= (int)$line['qty'] ?></td>
                <td><?= org_ecommerce_h(org_sales_money((int)$line['amount_cents'])) ?></td>
                <td><?= org_ecommerce_h(org_sales_money(0)) ?></td>
                <td><?= org_ecommerce_h(org_sales_money(0)) ?></td>
                <td><strong><?= org_ecommerce_h(org_sales_money((int)$line['amount_cents'])) ?></strong></td>
              </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr>
              <td><span class="tx-color-03">—</span></td>
              <td><?= org_ecommerce_h((string)$invoice['title']) ?><div class="tx-12 tx-color-03"><?= nl2br(org_ecommerce_h((string)($invoice['notes'] ?? ''))) ?></div></td>
              <td>1</td>
              <td><?= org_ecommerce_h(org_sales_money((int)$invoice['amount_cents'])) ?></td>
              <td><?= org_ecommerce_h(org_sales_money(0)) ?></td>
              <td><?= org_ecommerce_h(org_sales_money(0)) ?></td>
              <td><strong><?= org_ecommerce_h(org_sales_money((int)$invoice['amount_cents'])) ?></strong></td>
            </tr>
          <?php endif; ?>
        </tbody></table></div>
      </div>
    </div>
    <div class="col-lg-4">
      <div class="commerce-panel">
        <div class="commerce-panel-head"><h2>Payment</h2><span class="badge <?= org_sales_status_badge((string)$invoice['status']) ?>"><?= org_ecommerce_h((string)$invoice['status']) ?></span></div>
        <p><strong>Customer:</strong> <?= org_ecommerce_h((string)($invoice['contact_name'] ?? 'Unassigned')) ?></p>
        <p><strong>Due:</strong> <?= org_ecommerce_h((string)($invoice['due_date'] ?? '')) ?></p>
        <p><strong>Paid at:</strong> <?= org_ecommerce_h((string)($invoice['paid_at'] ?? 'Not paid')) ?></p>
        <?php if ($orderProductIds): ?>
          <p><strong>Product ID<?= count($orderProductIds) > 1 ? 's' : '' ?>:</strong>
            <?php foreach ($orderProductIds as $i => $pidCode): ?>
              <?= $i > 0 ? ', ' : '' ?><code><?= org_ecommerce_h($pidCode) ?></code>
            <?php endforeach; ?>
          </p>
        <?php endif; ?>
        <p><strong>Related order:</strong> <?= $relatedOrderId > 0 ? '<a href="order_details.php?id=' . $relatedOrderId . '">Open order</a>' : 'None' ?></p>
        <?php if ($relatedOrderId > 0): ?>
          <p><a href="order_invoice.php?id=<?= $relatedOrderId ?>">View receipt invoice</a></p>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<?php org_page_shell_close(); ?>
