<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/session_org.php';
require_once __DIR__ . '/includes/org_context.php';
require_once __DIR__ . '/includes/org_ecommerce.php';
require_once __DIR__ . '/includes/org_manager_guard.php';

org_require_manager();

org_require_commerce_seller();
org_ecommerce_ensure_schema($dbh);

$orgId = (int)orgActiveOrgId();
$limit = max(1, min(50, (int)($_GET['limit'] ?? 25)));
$recentOrders = org_shop_list_orders($dbh, $orgId, 'all', $limit);
$stats = org_ecommerce_dashboard_stats($dbh, $orgId);

function recent_orders_badge_class(string $status): string
{
    $status = strtolower(trim($status));
    if (in_array($status, ['paid', 'delivered'], true)) {
        return 'is-paid';
    }
    if ($status === 'shipped') {
        return 'is-shipped';
    }
    if (in_array($status, ['pending', 'confirmed'], true)) {
        return 'is-pending';
    }
    if ($status === 'cancelled') {
        return 'is-cancelled';
    }
    return '';
}

function recent_orders_fmt_dt(?string $dt): string
{
    if (!$dt) {
        return '—';
    }
    $t = strtotime($dt);
    return $t ? date('M d, Y h:i A', $t) : '—';
}

$pageTitle = 'Recent orders';
require_once __DIR__ . '/includes/org_page_shell.php';
org_page_shell_open($pageTitle, '<link rel="stylesheet" href="css/commerce-hub.css?v=14">');
?>
<?php org_page_body_open('commerce-page'); ?>
  <section class="commerce-hero commerce-hero-compact">
    <div class="commerce-hero-inner">
      <div>
        <p class="commerce-hero-kicker"><a href="commerce.php">&larr; Commerce hub</a></p>
        <h1>Recent orders</h1>
        <p>Latest buyer activity — open the order inbox to update fulfillment and tracking.</p>
        <div class="commerce-hero-badges">
          <span class="commerce-pill"><?= (int)$stats['orders_mtd'] ?> this month</span>
          <span class="commerce-pill<?= (int)$stats['orders_open'] > 0 ? ' is-warn' : '' ?>">
            <?= (int)$stats['orders_open'] ?> awaiting fulfillment
          </span>
        </div>
      </div>
      <div class="commerce-quick">
        <a href="orders.php" class="ch-btn-primary"><i class="icon ion-ios-list"></i> Order inbox</a>
        <a href="products.php" class="ch-btn-ghost"><i class="icon ion-ios-box"></i> Catalog</a>
      </div>
    </div>
  </section>

  <div class="commerce-panel">
    <div class="commerce-panel-head">
      <h2>Last <?= (int)$limit ?> orders</h2>
      <a href="orders.php">Manage all orders</a>
    </div>

    <?php if (!$recentOrders): ?>
      <div class="commerce-empty">
        <i class="icon ion-ios-cart-outline"></i>
        <div>No orders yet</div>
        <p class="tx-12 mg-t-5">Publish products to start selling on your storefront and marketplace.</p>
        <a href="products.php" class="btn btn-sm btn-primary mg-t-10">Add your first product</a>
      </div>
    <?php else: ?>
      <div class="commerce-recent-list">
        <?php foreach ($recentOrders as $o):
          $st = (string)($o['status'] ?? '');
          $buyer = trim((string)($o['buyer_name'] ?? ''));
          if ($buyer === '' && trim((string)($o['buyer_username'] ?? '')) !== '') {
              $buyer = '@' . (string)$o['buyer_username'];
          }
          if ($buyer === '') {
              $buyer = trim((string)($o['buyer_email'] ?? '')) ?: 'Guest';
          }
        ?>
        <article class="commerce-recent-row">
          <div class="commerce-order">
            <span class="commerce-order-code"><i class="icon ion-bag"></i></span>
            <div class="commerce-order-main">
              <strong><?= org_ecommerce_h((string)$o['product_title']) ?></strong>
              <span><?= org_ecommerce_h((string)$o['order_code']) ?> · <?= org_ecommerce_h($buyer) ?></span>
            </div>
            <div class="commerce-order-end">
              <div class="commerce-order-price"><?= org_ecommerce_h(org_shop_format_price((int)$o['total_cents'])) ?></div>
              <span class="commerce-order-badge <?= recent_orders_badge_class($st) ?>"><?= org_ecommerce_h($st) ?></span>
            </div>
          </div>
          <div class="commerce-recent-meta">
            <span>Qty <?= (int)($o['quantity'] ?? 1) ?></span>
            <span><?= org_ecommerce_h(strtoupper((string)($o['fulfillment_method'] ?? 'fbm'))) ?></span>
            <span><?= org_ecommerce_h(recent_orders_fmt_dt((string)($o['created_at'] ?? ''))) ?></span>
            <a href="orders.php?status=all#order-<?= (int)$o['id'] ?>" class="commerce-recent-link">Open in inbox →</a>
          </div>
        </article>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>
<?php org_page_shell_close(); ?>
