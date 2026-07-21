<?php
declare(strict_types=1);

/**
 * Admin: buyer online service fees ($1.99) collected when customers order via shop.
 */

require_once __DIR__ . '/includes/org_admin_helpers_load.php';
org_admin_require_admin();

error_reporting(E_ALL);
ini_set('display_errors', '1');

$dbh = org_admin_db();

// Optional shop helpers (schema check). Keep auth above so a shop include
// can never start a different session before the admin session exists.
try {
    require_once __DIR__ . '/../public_user/includes/org_shop.php';
    org_shop_ensure_schema($dbh);
} catch (Throwable $e) {
    // Page still renders; fee column may be missing until schema is fixed.
}

$search = trim((string)($_GET['q'] ?? ''));
$filter = strtolower(trim((string)($_GET['filter'] ?? 'all')));
if (!in_array($filter, ['all', 'with_fee', 'paid', 'open'], true)) {
    $filter = 'all';
}

$feeFixed = function_exists('org_shop_buyer_service_fee_cents')
    ? org_shop_buyer_service_fee_cents()
    : 199;

$hasServiceFeeCol = true;
try {
    $chk = $dbh->query("SHOW COLUMNS FROM org_orders LIKE 'service_fee_cents'");
    $hasServiceFeeCol = (bool)($chk && $chk->fetch(PDO::FETCH_ASSOC));
} catch (Throwable $e) {
    $hasServiceFeeCol = false;
}

$rows = [];
$totalFeeCents = 0;
$orderCount = 0;
$feeOrderCount = 0;

if (org_admin_table_exists($dbh, 'org_orders')) {
    $where = ['1=1'];
    $params = [];

    if ($filter === 'with_fee' && $hasServiceFeeCol) {
        $where[] = 'COALESCE(o.service_fee_cents, 0) > 0';
    } elseif ($filter === 'paid') {
        $where[] = "o.status IN ('paid','shipped','delivered')";
    } elseif ($filter === 'open') {
        $where[] = "o.status IN ('pending','confirmed','paid')";
        $where[] = "o.status <> 'cancelled'";
    }

    if ($search !== '') {
        $where[] = '(o.order_code LIKE :q OR o.product_title LIKE :q OR o.buyer_name LIKE :q OR o.buyer_email LIKE :q OR org.name LIKE :q OR org.org_code LIKE :q)';
        $params[':q'] = '%' . $search . '%';
    }

    $svcSelect = $hasServiceFeeCol
        ? 'COALESCE(o.service_fee_cents, 0) AS service_fee_cents'
        : '0 AS service_fee_cents';

    $sql = "
        SELECT
          o.id, o.order_code, o.product_title, o.quantity, o.status,
          o.total_cents, o.currency, o.buyer_name, o.buyer_email, o.created_at,
          {$svcSelect},
          o.org_id,
          org.name AS org_name,
          org.org_code
        FROM org_orders o
        LEFT JOIN organizations org ON org.id = o.org_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY o.created_at DESC, o.id DESC
        LIMIT 300
    ";

    try {
        $st = $dbh->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $rows = [];
    }

    foreach ($rows as &$row) {
        $svc = max(0, (int)($row['service_fee_cents'] ?? 0));
        // Older rows: infer fixed fee from total leftover when column was missing at insert.
        if ($svc <= 0 && $hasServiceFeeCol === false) {
            $svc = 0;
        } elseif ($svc <= 0) {
            $unit = 0;
            try {
                // Recompute leftover only for display when stored fee is 0 but total includes it.
                $stOne = $dbh->prepare('
                    SELECT COALESCE(unit_price_cents,0) AS unit_price_cents,
                           COALESCE(quantity,1) AS quantity,
                           COALESCE(discount_cents,0) AS discount_cents,
                           COALESCE(shipping_fee_cents,0) AS shipping_fee_cents,
                           COALESCE(tax_cents,0) AS tax_cents,
                           COALESCE(total_cents,0) AS total_cents
                    FROM org_orders WHERE id = :id LIMIT 1
                ');
                $stOne->execute([':id' => (int)($row['id'] ?? 0)]);
                $one = $stOne->fetch(PDO::FETCH_ASSOC) ?: [];
                $sub = max(0, ((int)($one['unit_price_cents'] ?? 0) * max(1, (int)($one['quantity'] ?? 1))) - (int)($one['discount_cents'] ?? 0));
                $leftover = max(0, (int)($one['total_cents'] ?? 0) - $sub - (int)($one['shipping_fee_cents'] ?? 0) - (int)($one['tax_cents'] ?? 0));
                if ($leftover === $feeFixed) {
                    $svc = $leftover;
                }
            } catch (Throwable $e) {
                // ignore
            }
        }
        $row['service_fee_cents'] = $svc;
        $orderCount++;
        if ($svc > 0) {
            $feeOrderCount++;
            $totalFeeCents += $svc;
        }
    }
    unset($row);

    // Summary across matching filters (not only page rows) when possible.
    try {
        $sumSql = "
            SELECT
              COUNT(*) AS cnt,
              COALESCE(SUM(CASE WHEN COALESCE(o.service_fee_cents, 0) > 0 THEN 1 ELSE 0 END), 0) AS fee_cnt,
              COALESCE(SUM(COALESCE(o.service_fee_cents, 0)), 0) AS fee_sum
            FROM org_orders o
            LEFT JOIN organizations org ON org.id = o.org_id
            WHERE " . implode(' AND ', $where) . "
        ";
        if ($hasServiceFeeCol) {
            $stSum = $dbh->prepare($sumSql);
            $stSum->execute($params);
            $sum = $stSum->fetch(PDO::FETCH_ASSOC) ?: [];
            $orderCount = (int)($sum['cnt'] ?? $orderCount);
            $feeOrderCount = (int)($sum['fee_cnt'] ?? $feeOrderCount);
            $totalFeeCents = (int)($sum['fee_sum'] ?? $totalFeeCents);
        }
    } catch (Throwable $e) {
        // keep per-page totals
    }
}

$money = static function (int $cents, string $currency = 'USD'): string {
    if (function_exists('org_shop_format_price')) {
        return org_shop_format_price($cents, $currency);
    }
    return '$' . number_format($cents / 100, 2);
};

org_admin_render_head('Service Fees');
?>
<div class="sh-logopanel"><a href="" class="sh-logo-text">Talentra Admin</a></div>
<div class="sh-headpanel"></div>
<?php include __DIR__ . '/includes/leftbar.php'; ?>

<div class="sh-mainpanel">
  <div class="sh-pagetitle">
    <div class="sh-pagetitle-left">
      <div class="sh-pagetitle-icon"><i class="icon ion-cash"></i></div>
      <div>
        <h2>Service Fees</h2>
        <p class="mg-b-0">$<?= number_format($feeFixed / 100, 2) ?> online fee paid by customers when they order in Shop — collected for Admin</p>
      </div>
    </div>
    <div class="sh-pagetitle-right">
      <a href="org_rent.php" class="btn-mini">Shop rent</a>
    </div>
  </div>

  <div class="sh-pagebody">
    <?php if (!$hasServiceFeeCol): ?>
      <div class="alert-lite bad">
        The <code>service_fee_cents</code> column is missing on <code>org_orders</code>. Open any shop page once so the migration can run, then refresh this page.
      </div>
    <?php endif; ?>

    <div class="card admin-card" style="margin-bottom:16px;">
      <div class="detail-grid" style="padding:16px;">
        <div class="detail-box">
          <div class="label">Fee per order</div>
          <div class="value"><?= org_admin_h($money($feeFixed)) ?></div>
          <div class="muted" style="margin-top:6px;">Fixed buyer service fee</div>
        </div>
        <div class="detail-box">
          <div class="label">Orders with fee</div>
          <div class="value"><?= (int)$feeOrderCount ?></div>
          <div class="muted" style="margin-top:6px;">Matching current filter</div>
        </div>
        <div class="detail-box">
          <div class="label">Total collected</div>
          <div class="value"><?= org_admin_h($money($totalFeeCents)) ?></div>
          <div class="muted" style="margin-top:6px;">Sum of buyer service fees</div>
        </div>
        <div class="detail-box">
          <div class="label">Orders listed</div>
          <div class="value"><?= (int)$orderCount ?></div>
          <div class="muted" style="margin-top:6px;">Up to 300 newest shown below</div>
        </div>
      </div>
    </div>

    <div class="card admin-card">
      <div class="pro-tools">
        <div class="filter-tabs">
          <?php
            $tabs = [
              'all' => 'All orders',
              'with_fee' => 'Has service fee',
              'paid' => 'Paid / shipped',
              'open' => 'Open',
            ];
            foreach ($tabs as $key => $label):
              $href = 'service_fees.php?filter=' . rawurlencode($key) . ($search !== '' ? '&q=' . rawurlencode($search) : '');
          ?>
            <a href="<?= org_admin_h($href) ?>" class="<?= $filter === $key ? 'is-active' : '' ?>"><?= org_admin_h($label) ?></a>
          <?php endforeach; ?>
        </div>
        <form class="search-form" method="get" action="service_fees.php">
          <input type="hidden" name="filter" value="<?= org_admin_h($filter) ?>">
          <input type="text" name="q" value="<?= org_admin_h($search) ?>" placeholder="Search order, buyer, shop…">
          <button type="submit" class="btn-mini primary">Search</button>
          <?php if ($search !== ''): ?>
            <a class="btn-mini" href="service_fees.php?filter=<?= rawurlencode($filter) ?>">Clear</a>
          <?php endif; ?>
        </form>
      </div>

      <div class="table-scroll">
        <table class="table admin-table">
          <thead>
            <tr>
              <th>When</th>
              <th>Order</th>
              <th>Shop</th>
              <th>Buyer</th>
              <th>Product</th>
              <th>Status</th>
              <th>Order total</th>
              <th>Service fee</th>
            </tr>
          </thead>
          <tbody>
          <?php if (!$rows): ?>
            <tr><td colspan="8" class="text-center tx-color-03">No shop orders found yet. Place a test order from shop.php to see the $<?= number_format($feeFixed / 100, 2) ?> fee here.</td></tr>
          <?php else: foreach ($rows as $row):
            $cur = (string)($row['currency'] ?? 'USD');
            $svc = (int)($row['service_fee_cents'] ?? 0);
            $orgLabel = trim((string)($row['org_name'] ?? ''));
            if ($orgLabel === '') {
                $orgLabel = 'Org #' . (int)($row['org_id'] ?? 0);
            }
            $code = trim((string)($row['org_code'] ?? ''));
            $buyer = trim((string)($row['buyer_name'] ?? ''));
            if ($buyer === '') {
                $buyer = trim((string)($row['buyer_email'] ?? '')) ?: '—';
            }
          ?>
            <tr>
              <td><?= org_admin_h(org_admin_fmt_dt($row['created_at'] ?? '')) ?></td>
              <td>
                <strong><?= org_admin_h((string)($row['order_code'] ?? ('#' . (int)($row['id'] ?? 0)))) ?></strong>
              </td>
              <td>
                <?= org_admin_h($orgLabel) ?>
                <?php if ($code !== ''): ?><div class="muted"><?= org_admin_h($code) ?></div><?php endif; ?>
              </td>
              <td><?= org_admin_h($buyer) ?></td>
              <td>
                <?= org_admin_h((string)($row['product_title'] ?? 'Product')) ?>
                <div class="muted">Qty <?= (int)($row['quantity'] ?? 1) ?></div>
              </td>
              <td><span class="pill"><?= org_admin_h((string)($row['status'] ?? '')) ?></span></td>
              <td><?= org_admin_h($money((int)($row['total_cents'] ?? 0), $cur)) ?></td>
              <td>
                <?php if ($svc > 0): ?>
                  <strong style="color:#15803d;"><?= org_admin_h($money($svc, $cur)) ?></strong>
                <?php else: ?>
                  <span class="muted">$0.00</span>
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
