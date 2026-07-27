<?php
declare(strict_types=1);

/**
 * Shared OMS customer-order panel for orders.php and sales_management.php#orders.
 *
 * Expected vars (set by caller):
 * - PDO $dbh
 * - int $orgId
 * - int $memberId
 * - string $statusFilter
 * - list<string> $allowedFilters
 * - string $err
 * - string $ok
 * - string $omsBaseUrl  e.g. 'orders.php' or 'sales_management.php'
 * - string $omsHash     e.g. '' or '#orders' (appended to status filter links)
 * - bool $omsShowCommerceHub (optional, default true)
 */

if (!function_exists('h') && function_exists('org_ecommerce_h')) {
    function h(string $s): string
    {
        return org_ecommerce_h($s);
    }
}

$omsBaseUrl = (string)($omsBaseUrl ?? 'orders.php');
$omsHash = (string)($omsHash ?? '');
$omsShowCommerceHub = !isset($omsShowCommerceHub) || (bool)$omsShowCommerceHub;
$omsFormAction = $omsBaseUrl;
if ($statusFilter !== 'all') {
    $omsFormAction .= '?status=' . rawurlencode($statusFilter);
}

$orderLines = org_shop_list_orders($dbh, $orgId, $statusFilter, 200);
$orderGroups = org_shop_group_seller_customer_orders($orderLines);
$orderCount = count($orderGroups);
$orderCountLabel = $orderCount === 1 ? '1 customer order' : $orderCount . ' customer orders';
$omsFilterLabels = [
    'all' => 'All',
    'pending' => 'Pending',
    'confirmed' => 'Confirmed',
    'paid' => 'Paid',
    'cancelled' => 'Cancelled',
    'history' => 'History Order',
];
$statusLabel = $omsFilterLabels[$statusFilter] ?? ($statusFilter === 'all' ? 'active orders' : $statusFilter);
if ($statusFilter === 'history') {
    $statusLabel = 'history (shipped & delivered)';
} elseif ($statusFilter === 'all') {
    $statusLabel = 'active orders (pending, confirmed, paid)';
}
?>
  <div class="card bd-0 shadow-base mg-b-0">
    <div class="card-header d-flex align-items-center justify-content-between flex-wrap">
      <div>
        <h6 class="card-title tx-uppercase tx-14 mg-b-0">Order management (OMS) · <?= h($orderCountLabel) ?></h6>
        <p class="mg-b-0 tx-12 tx-color-03">
          <?= (int)$orderCount ?> customer<?= $orderCount === 1 ? '' : 's' ?> with purchases from this brand (<?= h($statusLabel) ?>).
          Shipped &amp; delivered orders move to <strong>History Order</strong>.
          Same customer = one row. Product # = how many products. Quantity # = total units.
        </p>
      </div>
      <div class="d-flex flex-wrap align-items-center justify-content-end" style="gap:8px;flex:1 1 auto;">
        <?php if ($omsShowCommerceHub): ?>
          <a href="commerce.php" class="btn btn-sm btn-outline-secondary">Commerce hub</a>
        <?php endif; ?>
        <?php
          // Keep History Order last (far right), even if caller array order drifts.
          $omsTabOrder = ['all', 'pending', 'confirmed', 'paid', 'cancelled', 'history'];
          $omsTabsMain = [];
          $omsTabHistory = null;
          foreach ($omsTabOrder as $f) {
              if (!in_array($f, $allowedFilters, true)) {
                  continue;
              }
              if ($f === 'history') {
                  $omsTabHistory = $f;
                  continue;
              }
              $omsTabsMain[] = $f;
          }
          // Any unexpected filters stay with the main group (before History).
          foreach ($allowedFilters as $f) {
              if ($f === 'history' || in_array($f, $omsTabsMain, true)) {
                  continue;
              }
              $omsTabsMain[] = $f;
          }
        ?>
        <div class="btn-group btn-group-sm">
          <?php foreach ($omsTabsMain as $f): ?>
            <a
              href="<?= h($omsBaseUrl) ?>?status=<?= h($f) ?><?= h($omsHash) ?>"
              class="btn btn-outline-secondary<?= $statusFilter === $f ? ' active' : '' ?>"
            ><?= h((string)($omsFilterLabels[$f] ?? ucfirst($f))) ?></a>
          <?php endforeach; ?>
        </div>
        <?php if ($omsTabHistory !== null): ?>
          <a
            href="<?= h($omsBaseUrl) ?>?status=history<?= h($omsHash) ?>"
            class="btn btn-sm btn-outline-secondary<?= $statusFilter === 'history' ? ' active' : '' ?>"
            style="margin-left:auto;"
          ><?= h((string)($omsFilterLabels['history'] ?? 'History Order')) ?></a>
        <?php endif; ?>
      </div>
    </div>
    <div class="card-body">
      <?php if ($err !== ''): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endif; ?>
      <?php if ($ok !== ''): ?><div class="alert alert-success"><?= h($ok) ?></div><?php endif; ?>

      <div class="d-flex flex-wrap align-items-center mg-b-12" style="gap:8px;">
        <label class="oms-search mg-b-0" for="omsProductIdSearch" style="display:flex;align-items:center;gap:8px;min-width:240px;max-width:360px;flex:1 1 240px;padding:6px 10px;border:1px solid rgba(148,163,184,.35);border-radius:8px;">
          <i class="icon ion-ios-search" aria-hidden="true"></i>
          <input
            type="search"
            id="omsProductIdSearch"
            class="form-control form-control-sm bd-0 pd-0"
            style="box-shadow:none;background:transparent;"
            placeholder="Search by Product ID, product, or customer…"
            autocomplete="off"
            spellcheck="false"
          >
        </label>
      </div>

      <div class="table-responsive">
        <table class="table table-hover mg-b-0 oms-data-table" id="omsOrdersTable">
          <thead>
            <tr>
              <th class="text-center oms-col-narrow">Product #</th>
              <th class="oms-col-product-id">Product ID</th>
              <th class="oms-col-products">Products</th>
              <th class="text-center oms-col-narrow">Qty #</th>
              <th class="oms-col-narrow">Customer</th>
              <th class="oms-col-contact">Contact</th>
              <th class="oms-col-address">Address</th>
              <th class="oms-col-narrow">Total</th>
              <th class="oms-col-narrow">Status</th>
              <th class="oms-col-fulfill">Fulfillment</th>
              <th class="oms-col-narrow">Date</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$orderGroups): ?>
              <tr><td colspan="11" class="text-center tx-color-03">No orders yet.</td></tr>
            <?php else: foreach ($orderGroups as $g): ?>
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
                $productIdList = isset($g['product_ids']) && is_array($g['product_ids']) ? $g['product_ids'] : [];
                $productIdLabel = $productIdList ? implode(', ', $productIdList) : '—';
                $searchBlob = mb_strtolower(trim(implode(' ', array_filter([
                    $productIdLabel,
                    $productSummary,
                    (string)($g['buyer_name'] ?? ''),
                    (string)($g['buyer_email'] ?? ''),
                    (string)($g['buyer_phone'] ?? ''),
                    (string)($g['status'] ?? ''),
                    implode(' ', $g['order_codes'] ?? []),
                ]))));
              ?>
              <tr data-oms-search="<?= h($searchBlob) ?>">
                <td class="text-center oms-col-narrow">
                  <strong><?= (int)$g['order_num'] ?></strong>
                  <div class="tx-11 tx-color-03 mg-t-2">products</div>
                  <?php if ($primaryId > 0): ?>
                    <div class="tx-11 mg-t-2">
                      <a
                        href="order_details.php?id=<?= $primaryId ?>"
                        class="js-open-org-order-door"
                        data-door-url="order_details.php?id=<?= $primaryId ?>&amp;embed=1"
                        data-door-title="Order details"
                        data-door-label="<?= h((string)$g['buyer_name'] . ' · ' . (string)$g['date_label']) ?>"
                      >Details</a>
                    </div>
                    <div class="tx-11 mg-t-2">
                      <a
                        href="order_invoice.php?id=<?= $primaryId ?>"
                        class="js-open-org-order-door"
                        data-door-url="order_invoice.php?id=<?= $primaryId ?>&amp;embed=1"
                        data-door-title="Invoice"
                        data-door-label="<?= h((string)$g['buyer_name'] . ' · receipt') ?>"
                      >Invoice</a>
                    </div>
                  <?php endif; ?>
                </td>
                <td class="oms-col-product-id">
                  <?php if ($productIdList): ?>
                    <?php foreach ($productIdList as $pidCode): ?>
                      <div><code class="tx-11"><?= h((string)$pidCode) ?></code></div>
                    <?php endforeach; ?>
                  <?php else: ?>
                    <span class="tx-color-03">—</span>
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
                <td class="oms-col-address tx-12" style="white-space:pre-line;">
                  <?= trim((string)$g['delivery_address']) !== '' ? h((string)$g['delivery_address']) : '<span class="tx-color-03">Not provided</span>' ?>
                </td>
                <td class="oms-col-narrow"><?= h((string)$g['total_label']) ?></td>
                <td class="oms-col-narrow"><span class="badge badge-light"><?= h((string)$g['status']) ?></span></td>
                <td class="oms-col-fulfill">
                  <?php if ($primary): ?>
                    <div class="tx-12 mg-b-5">
                      <strong><?= strtoupper(h((string)($primary['fulfillment_method'] ?? 'fbm'))) ?></strong>
                      · <?= h(str_replace('_', ' ', (string)($primary['delivery_option'] ?? 'home_delivery'))) ?>
                    </div>
                    <form method="post" action="<?= h($omsFormAction) ?>" class="mg-b-0">
                      <input type="hidden" name="oms_action" value="1">
                      <input type="hidden" name="order_id" value="<?= $primaryId ?>">
                      <div class="form-group mg-b-5">
                        <select name="status" class="form-control form-control-sm">
                          <?php foreach (['pending', 'confirmed', 'paid', 'shipped', 'delivered', 'cancelled'] as $st): ?>
                            <option value="<?= h($st) ?>" <?= ((string)($primary['status'] ?? '') === $st) ? 'selected' : '' ?>><?= h(ucfirst($st)) ?></option>
                          <?php endforeach; ?>
                        </select>
                      </div>
                      <div class="form-group mg-b-5">
                        <input type="text" name="carrier" class="form-control form-control-sm" placeholder="Carrier" value="<?= h((string)($primary['carrier'] ?? '')) ?>">
                      </div>
                      <div class="form-group mg-b-5">
                        <input type="text" name="tracking_number" class="form-control form-control-sm" placeholder="Tracking #" value="<?= h((string)($primary['tracking_number'] ?? '')) ?>">
                      </div>
                      <p class="tx-11 tx-color-03 mg-b-5">Save with Carrier + Tracking to mark shipping automatically. Set Delivered when the customer receives the order.</p>
                      <input type="text" name="seller_notes" class="form-control form-control-sm mg-b-5" placeholder="Seller note" value="<?= h((string)($primary['seller_notes'] ?? '')) ?>">
                      <button type="submit" class="btn btn-sm btn-primary btn-block">Save</button>
                    </form>
                    <form method="post" action="<?= h($omsFormAction) ?>" class="mg-t-5">
                      <input type="hidden" name="oms_action" value="1">
                      <input type="hidden" name="order_id" value="<?= $primaryId ?>">
                      <input type="hidden" name="sync_crm" value="1">
                      <button type="submit" class="btn btn-sm btn-outline-secondary btn-block">Sync to CRM</button>
                    </form>
                    <?php
                      $canSellerCancel = in_array(strtolower((string)($primary['status'] ?? '')), ['pending', 'confirmed', 'paid'], true)
                        || in_array(strtolower((string)($g['status'] ?? '')), ['pending', 'confirmed', 'paid', 'multiple'], true);
                    ?>
                    <?php if ($canSellerCancel): ?>
                      <form method="post" action="<?= h($omsFormAction) ?>" class="mg-t-5 js-oms-seller-cancel-form">
                        <input type="hidden" name="oms_cancel_action" value="1">
                        <input type="hidden" name="order_id" value="<?= $primaryId ?>">
                        <input type="hidden" name="cancel_reason" value="" class="js-oms-cancel-reason">
                        <button type="submit" class="btn btn-sm btn-outline-danger btn-block">Cancel order</button>
                      </form>
                    <?php endif; ?>
                  <?php endif; ?>
                </td>
                <td class="oms-col-narrow tx-12"><?= h((string)$g['date_label']) ?></td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
<script>
(function () {
  if (!window.__msbOmsSellerCancelInit) {
    window.__msbOmsSellerCancelInit = true;
    document.addEventListener('submit', function (e) {
      var form = e.target && e.target.closest ? e.target.closest('.js-oms-seller-cancel-form') : null;
      if (!form) return;
      e.preventDefault();
      var reason = window.prompt(
        'Why are you cancelling this customer order?\n(Examples: Card expired, payment issue, changed mind)',
        'Card expired'
      );
      if (reason === null) return;
      reason = String(reason).trim();
      if (!reason) reason = 'Seller cancelled';
      if (!window.confirm('Cancel this customer order and notify the buyer?')) return;
      var reasonInput = form.querySelector('.js-oms-cancel-reason');
      if (reasonInput) reasonInput.value = reason;
      form.submit();
    });
  }

  var searchInput = document.getElementById('omsProductIdSearch');
  var table = document.getElementById('omsOrdersTable');
  if (searchInput && table) {
    var rows = Array.prototype.slice.call(table.querySelectorAll('tbody tr[data-oms-search]'));
    function applyOmsSearch() {
      var q = String(searchInput.value || '').trim().toLowerCase();
      rows.forEach(function (row) {
        var blob = String(row.getAttribute('data-oms-search') || '').toLowerCase();
        row.hidden = q !== '' && blob.indexOf(q) === -1;
      });
    }
    searchInput.addEventListener('input', applyOmsSearch);
    searchInput.addEventListener('search', applyOmsSearch);
  }
})();
</script>
