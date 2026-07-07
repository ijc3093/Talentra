<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/session_org.php';
require_once __DIR__ . '/includes/org_context.php';
require_once __DIR__ . '/includes/org_ecommerce.php';
require_once __DIR__ . '/includes/org_manager_guard.php';

org_require_manager();

$orgId = (int)orgActiveOrgId();
org_ecommerce_ensure_schema($dbh);

$stats = org_ecommerce_dashboard_stats($dbh, $orgId);
$crmStats = org_crm_dashboard_stats($dbh, $orgId);
$integrations = org_ecommerce_integrations($dbh, $orgId);
$shopSettings = org_ecommerce_get_shop_settings($dbh, $orgId);
$businessModel = org_ecommerce_get_business_model($dbh, $orgId);
$modelLabel = org_ecommerce_business_models()[$businessModel] ?? ucfirst($businessModel);
$recentOrders = org_shop_list_orders($dbh, $orgId, 'all', 6);
$lowStock = org_ecommerce_low_stock_products($dbh, $orgId, 5);
$productCount = org_shop_product_count($dbh, $orgId);
$maxProducts = org_shop_max_products($dbh, $orgId);

$integrationIcons = [
    'payments' => 'ion-card',
    'crm' => 'ion-ios-people',
    'oms' => 'ion-ios-cart',
    'pim' => 'ion-ios-box',
    'social' => 'ion-ios-paperplane',
    'marketplace' => 'ion-ios-world',
    'logistics' => 'ion-ios-location',
];

function commerce_order_badge_class(string $status): string
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

$pageTitle = 'Shop & commerce';
require_once __DIR__ . '/includes/org_page_shell.php';
org_page_shell_open($pageTitle, '<link rel="stylesheet" href="css/commerce-hub.css?v=2">');
?>
<div class="sh-pagebody commerce-page">
  <section class="commerce-hero">
    <div class="commerce-hero-inner">
      <div>
        <h1>Shop &amp; commerce</h1>
        <p>Sell on your profile, marketplace, and social feed — track orders, inventory, and revenue from one place.</p>
        <div class="commerce-hero-badges">
          <span class="commerce-pill"><i class="icon ion-briefcase"></i> <?= org_ecommerce_h($modelLabel) ?></span>
          <?php if ($stats['shop_visible']): ?>
            <span class="commerce-pill is-live"><i class="icon ion-checkmark-round"></i> Storefront live</span>
          <?php else: ?>
            <span class="commerce-pill is-warn"><i class="icon ion-alert-circled"></i> Storefront hidden — check rent</span>
          <?php endif; ?>
          <span class="commerce-pill"><?= (int)$productCount ?> / <?= (int)$maxProducts ?> products</span>
        </div>
      </div>
      <div class="commerce-quick">
        <a href="products.php" class="ch-btn-primary"><i class="icon ion-plus"></i> Add product</a>
        <a href="orders.php" class="ch-btn-ghost"><i class="icon ion-ios-list"></i> Orders</a>
        <a href="shop_settings.php" class="ch-btn-ghost"><i class="icon ion-gear-b"></i> Settings</a>
      </div>
    </div>
  </section>

  <div class="commerce-kpi-grid">
    <div class="commerce-kpi">
      <div class="commerce-kpi-top">
        <span class="commerce-kpi-label">Revenue MTD</span>
        <span class="commerce-kpi-icon"><i class="icon ion-cash"></i></span>
      </div>
      <div class="commerce-kpi-value"><?= org_ecommerce_h(org_shop_format_price((int)$stats['revenue_mtd_cents'])) ?></div>
      <div class="commerce-kpi-sub">Paid &amp; fulfilled orders this month</div>
    </div>
    <div class="commerce-kpi">
      <div class="commerce-kpi-top">
        <span class="commerce-kpi-label">Orders</span>
        <span class="commerce-kpi-icon"><i class="icon ion-bag"></i></span>
      </div>
      <div class="commerce-kpi-value"><?= (int)$stats['orders_mtd'] ?></div>
      <div class="commerce-kpi-sub"><?= (int)$stats['orders_open'] ?> awaiting fulfillment</div>
    </div>
    <div class="commerce-kpi">
      <div class="commerce-kpi-top">
        <span class="commerce-kpi-label">Catalog</span>
        <span class="commerce-kpi-icon"><i class="icon ion-ios-pricetags"></i></span>
      </div>
      <div class="commerce-kpi-value"><?= (int)$stats['products_active'] ?></div>
      <div class="commerce-kpi-sub<?= (int)$stats['products_low_stock'] > 0 ? ' is-alert' : '' ?>">
        <?php if ((int)$stats['products_low_stock'] > 0): ?>
          <?= (int)$stats['products_low_stock'] ?> low-stock alert<?= (int)$stats['products_low_stock'] > 1 ? 's' : '' ?>
        <?php else: ?>
          Active listings on your plan
        <?php endif; ?>
      </div>
    </div>
    <div class="commerce-kpi">
      <div class="commerce-kpi-top">
        <span class="commerce-kpi-label">Avg order</span>
        <span class="commerce-kpi-icon"><i class="icon ion-stats-bars"></i></span>
      </div>
      <div class="commerce-kpi-value"><?= org_ecommerce_h(org_shop_format_price((int)$stats['avg_order_cents'])) ?></div>
      <div class="commerce-kpi-sub">Pipeline <?= org_ecommerce_h(org_crm_money((int)$crmStats['forecast_cents'])) ?></div>
    </div>
  </div>

  <div class="commerce-layout">
    <div class="commerce-main">
      <div class="commerce-panel">
        <div class="commerce-panel-head">
          <h2>Connected systems</h2>
          <span>Payments · CRM · fulfillment · catalog</span>
        </div>
        <div class="commerce-int-grid">
          <?php foreach ($integrations as $int):
            $iid = (string)($int['id'] ?? '');
            $icon = $integrationIcons[$iid] ?? 'ion-link';
            $st = (string)($int['status'] ?? '');
          ?>
          <div class="commerce-int-item">
            <span class="commerce-int-icon"><i class="icon <?= org_ecommerce_h($icon) ?>"></i></span>
            <div class="commerce-int-body">
              <strong><?= org_ecommerce_h((string)$int['name']) ?></strong>
              <p><?= org_ecommerce_h((string)$int['description']) ?></p>
            </div>
            <div class="commerce-int-meta">
              <span class="commerce-status is-<?= org_ecommerce_h($st) ?>"><?= org_ecommerce_h($st) ?></span>
              <?php
                $intAction = (string)($int['action'] ?? '');
                if ($intAction !== '' && substr($intAction, -4) === '.php'):
              ?>
                <a href="<?= org_ecommerce_h((string)$int['action']) ?>" class="tx-12">Open →</a>
              <?php endif; ?>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="commerce-panel">
        <div class="commerce-panel-head">
          <h2>Sales channels</h2>
          <a href="shop_settings.php">Configure channels</a>
        </div>
        <?php
          $ch = $shopSettings['channels'] ?? [];
          $profileOn = $stats['shop_visible'];
          $marketOn = $profileOn && !empty($ch['marketplace']);
          $socialOn = !empty($ch['social_feed']);
        ?>
        <div class="commerce-channels">
          <div class="commerce-channel<?= $profileOn ? ' is-on' : '' ?>">
            <i class="icon ion-ios-person"></i>
            <strong>Profile shop</strong>
            <span><?= $profileOn ? 'Live on your page' : 'Not visible' ?></span>
          </div>
          <div class="commerce-channel<?= $marketOn ? ' is-on' : '' ?>">
            <i class="icon ion-ios-world"></i>
            <strong>Marketplace</strong>
            <span><?= $marketOn ? 'Discoverable listing' : 'Disabled' ?></span>
          </div>
          <div class="commerce-channel<?= $socialOn ? ' is-on' : '' ?>">
            <i class="icon ion-ios-paperplane"></i>
            <strong>Social feed</strong>
            <span><?= $socialOn ? 'Product posts enabled' : 'Disabled' ?></span>
          </div>
        </div>
      </div>
    </div>

    <aside class="commerce-side">
      <div class="commerce-panel">
        <div class="commerce-panel-head">
          <h2>Recent orders</h2>
          <a href="orders.php">View all</a>
        </div>
        <?php if (!$recentOrders): ?>
          <div class="commerce-empty">
            <i class="icon ion-ios-cart-outline"></i>
            <div>No orders yet</div>
            <p class="tx-12 mg-t-5">Publish products to start selling.</p>
            <a href="products.php" class="btn btn-sm btn-primary mg-t-10">Add your first product</a>
          </div>
        <?php else: ?>
          <?php foreach ($recentOrders as $o):
            $st = (string)($o['status'] ?? '');
          ?>
          <div class="commerce-order">
            <span class="commerce-order-code"><i class="icon ion-bag"></i></span>
            <div class="commerce-order-main">
              <strong><?= org_ecommerce_h((string)$o['product_title']) ?></strong>
              <span><?= org_ecommerce_h((string)$o['order_code']) ?></span>
            </div>
            <div class="commerce-order-end">
              <div class="commerce-order-price"><?= org_ecommerce_h(org_shop_format_price((int)$o['total_cents'])) ?></div>
              <span class="commerce-order-badge <?= commerce_order_badge_class($st) ?>"><?= org_ecommerce_h($st) ?></span>
            </div>
          </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>

      <?php if ($lowStock): ?>
      <div class="commerce-panel commerce-alert">
        <div class="commerce-panel-head">
          <h2><i class="icon ion-alert-circled"></i> Low stock</h2>
          <a href="products.php">Restock</a>
        </div>
        <?php foreach ($lowStock as $p): ?>
          <a href="products.php?edit=<?= (int)$p['id'] ?>" class="commerce-stock-item">
            <strong><?= org_ecommerce_h((string)$p['title']) ?></strong>
            <span class="commerce-stock-qty"><?= (int)$p['stock_qty'] ?> left</span>
          </a>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </aside>
  </div>

  <div class="commerce-footer-actions">
    <a href="products.php" class="commerce-action-tile">
      <i class="icon ion-ios-box"></i>
      <strong>Product catalog</strong>
      <span>Manage SKUs, pricing, and SEO</span>
    </a>
    <a href="orders.php" class="commerce-action-tile">
      <i class="icon ion-ios-paper"></i>
      <strong>Order inbox</strong>
      <span>Fulfillment, tracking, CRM sync</span>
    </a>
    <a href="crm.php" class="commerce-action-tile">
      <i class="icon ion-ios-people"></i>
      <strong>Customers</strong>
      <span>CRM, leads, and repeat buyers</span>
    </a>
    <a href="commerce_analytics.php" class="commerce-action-tile">
      <i class="icon ion-pie-graph"></i>
      <strong>Analytics</strong>
      <span>Revenue, top products, inventory</span>
    </a>
  </div>
</div>
<?php org_page_shell_close(); ?>
