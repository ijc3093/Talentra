<?php
declare(strict_types=1);

/**
 * Cancelled orders table — opened from Notification (sales_management.php#table_cancel_orders).
 *
 * Expected:
 * - PDO $dbh
 * - int $orgId
 */

if (!function_exists('h') && function_exists('org_ecommerce_h')) {
    function h(string $s): string
    {
        return org_ecommerce_h($s);
    }
}

$cancelLines = org_shop_list_orders($dbh, $orgId, 'cancelled', 300);
$cancelGroups = org_shop_group_seller_customer_orders($cancelLines, true);
$cancelCount = count($cancelGroups);
$cancelCountLabel = $cancelCount === 1 ? '1 cancelled purchase' : $cancelCount . ' cancelled purchases';
?>
  <div class="card bd-0 shadow-base mg-b-0">
    <div class="card-header d-flex align-items-center justify-content-between flex-wrap" style="gap:10px;">
      <div>
        <h6 class="card-title tx-uppercase tx-14 mg-b-0">Cancel orders table · <?= h($cancelCountLabel) ?></h6>
        <p class="mg-b-0 tx-12 tx-color-03">
          Seller <strong>Cancel</strong> (you stopped the order) and customer <strong>Cancellation</strong> (buyer cancelled). Same customer = one row.
        </p>
      </div>
      <a href="sales_management.php#notification" class="tx-12" data-sales-nav="notification">&larr; Back to Notification</a>
    </div>
    <div class="card-body">
      <div class="table-responsive">
        <table class="table table-hover mg-b-0 oms-data-table">
          <thead>
            <tr>
              <th class="text-center oms-col-narrow">Product #</th>
              <th class="oms-col-products">Products</th>
              <th class="text-center oms-col-narrow">Qty #</th>
              <th class="oms-col-narrow">Customer</th>
              <th class="oms-col-contact">Contact</th>
              <th class="oms-col-address">Cancel reason</th>
              <th class="oms-col-narrow">By</th>
              <th class="oms-col-narrow">Total</th>
              <th class="oms-col-narrow">Status</th>
              <th class="oms-col-narrow">Date</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$cancelGroups): ?>
              <tr><td colspan="10" class="text-center tx-color-03">No cancelled orders yet.</td></tr>
            <?php else: foreach ($cancelGroups as $g): ?>
              <?php
                $primaryId = (int)$g['primary_order_id'];
                $primary = null;
                foreach ($g['lines'] as $line) {
                    if ((int)($line['id'] ?? 0) === $primaryId) {
                        $primary = $line;
                        break;
                    }
                }
                if (!$primary && $g['lines']) {
                    $primary = $g['lines'][0];
                    $primaryId = (int)($primary['id'] ?? 0);
                }
                $productSummary = implode(', ', $g['product_titles']);
                $cancelledBy = 'Customer';
                $notesBlob = '';
                foreach (array_reverse($g['lines']) as $line) {
                    $notesBlob .= "\n" . (string)($line['buyer_notes'] ?? '') . "\n" . (string)($line['seller_notes'] ?? '');
                }
                $meta = org_shop_order_cancel_meta($notesBlob, '');
                $cancelledBy = ((string)($meta['by'] ?? 'Customer') === 'Seller')
                    ? 'Seller · Cancel'
                    : 'Customer · Cancellation';
                $reason = (string)($meta['reason'] ?? 'Cancelled');
              ?>
              <tr>
                <td class="text-center oms-col-narrow">
                  <strong><?= (int)$g['order_num'] ?></strong>
                  <div class="tx-11 tx-color-03 mg-t-2">products</div>
                  <?php if ($primaryId > 0): ?>
                    <div class="tx-11 mg-t-2">
                      <a
                        href="order_details.php?id=<?= $primaryId ?>"
                        class="js-open-org-order-door"
                        data-door-url="order_details.php?id=<?= $primaryId ?>&amp;embed=1"
                        data-door-label="<?= h((string)$g['buyer_name'] . ' · cancelled') ?>"
                      >Details</a>
                    </div>
                  <?php endif; ?>
                </td>
                <td class="oms-col-products">
                  <strong><?= h($productSummary !== '' ? $productSummary : 'Products') ?></strong>
                </td>
                <td class="text-center oms-col-narrow">
                  <strong><?= (int)$g['quantity_num'] ?></strong>
                  <div class="tx-11 tx-color-03 mg-t-2">units</div>
                </td>
                <td class="oms-col-narrow"><?= h((string)$g['buyer_name']) ?></td>
                <td class="oms-col-contact tx-12">
                  <?php if (trim((string)$g['buyer_email']) !== ''): ?>
                    <div><a href="mailto:<?= h((string)$g['buyer_email']) ?>"><?= h((string)$g['buyer_email']) ?></a></div>
                  <?php endif; ?>
                  <?php if (trim((string)$g['buyer_phone']) !== ''): ?>
                    <div><a href="tel:<?= h(preg_replace('/\s+/', '', (string)$g['buyer_phone'])) ?>"><?= h((string)$g['buyer_phone']) ?></a></div>
                  <?php endif; ?>
                  <?php if (trim((string)$g['buyer_email']) === '' && trim((string)$g['buyer_phone']) === ''): ?>
                    <span class="tx-color-03">Not provided</span>
                  <?php endif; ?>
                </td>
                <td class="oms-col-address tx-12"><?= h($reason) ?></td>
                <td class="oms-col-narrow tx-12"><?= h($cancelledBy) ?></td>
                <td class="oms-col-narrow"><?= h((string)$g['total_label']) ?></td>
                <td class="oms-col-narrow"><span class="badge badge-secondary">cancelled</span></td>
                <td class="oms-col-narrow tx-12"><?= h((string)$g['date_label']) ?></td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
