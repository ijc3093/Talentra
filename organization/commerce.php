<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/session_org.php';
require_once __DIR__ . '/includes/org_context.php';
require_once __DIR__ . '/includes/org_ecommerce.php';
require_once __DIR__ . '/includes/org_manager_guard.php';

org_require_manager();

org_require_commerce_seller();

$orgId = (int)orgActiveOrgId();
org_ecommerce_ensure_schema($dbh);
require_once __DIR__ . '/../public_user/includes/org_commerce_brands.php';
org_commerce_brands_ensure_schema($dbh);

$commerceBrand = org_commerce_brands_get_for_org($dbh, $orgId);
if (!$commerceBrand && !isset($_GET['setup'])) {
    header('Location: commerce_brand_select.php');
    exit;
}
$brandSystem = org_commerce_brands_parse_system($commerceBrand);

$stats = org_ecommerce_dashboard_stats($dbh, $orgId);
$crmStats = org_crm_dashboard_stats($dbh, $orgId);
$integrations = org_ecommerce_integrations($dbh, $orgId);
$shopSettings = org_ecommerce_get_shop_settings($dbh, $orgId);
$businessModel = org_ecommerce_get_business_model($dbh, $orgId);
$modelLabel = org_ecommerce_business_models()[$businessModel] ?? ucfirst($businessModel);
$lowStock = org_ecommerce_low_stock_products($dbh, $orgId, 5);
$productCount = org_shop_product_count($dbh, $orgId);
$maxProducts = org_shop_max_products($dbh, $orgId);
$sellerPlan = org_shop_get_seller_plan($dbh, $orgId);
$shopChannels = $shopSettings['channels'] ?? [];
$profileShopOn = $stats['shop_visible'];
$marketplaceOn = $profileShopOn && !empty($shopChannels['marketplace']);
$socialFeedOn = !empty($shopChannels['social_feed']);

$integrationIcons = [
    'payments' => 'ion-card',
    'crm' => 'ion-ios-people',
    'oms' => 'ion-ios-cart',
    'pim' => 'ion-ios-box',
    'social' => 'ion-ios-paperplane',
    'marketplace' => 'ion-ios-world',
    'logistics' => 'ion-ios-location',
];

$pageTitle = 'Shop & commerce';
require_once __DIR__ . '/includes/org_page_shell.php';
org_page_shell_open($pageTitle, '<link rel="stylesheet" href="css/commerce-hub.css?v=15"><link rel="stylesheet" href="css/org-commerce-theme.css?v=2" id="org-commerce-theme-css">');
?>
<?php org_page_body_open('commerce-page'); ?>
  <section class="commerce-hero">
    <div class="commerce-hero-inner">
      <div>
        <h1>Shop &amp; commerce</h1>
        <p>Sell on your profile, marketplace, and social feed — track orders, inventory, and revenue from one place.</p>
        <div class="commerce-hero-badges">
          <?php if ($commerceBrand): ?>
            <span class="commerce-pill is-live" style="border-color: <?= org_ecommerce_h((string)($commerceBrand['accent_color'] ?? '#0d9488')) ?>">
              <i class="icon ion-ios-star"></i> <?= org_ecommerce_h((string)$commerceBrand['name']) ?> system
            </span>
          <?php endif; ?>
          <span class="commerce-pill"><i class="icon ion-briefcase"></i> <?= org_ecommerce_h($modelLabel) ?></span>
          <?php if ($stats['shop_visible']): ?>
            <span class="commerce-pill is-live"><i class="icon ion-checkmark-round"></i> Storefront live</span>
          <?php else: ?>
            <span class="commerce-pill is-warn"><i class="icon ion-alert-circled"></i> Storefront hidden — check rent</span>
          <?php endif; ?>
          <span class="commerce-pill"><?= (int)$productCount ?> / <?= (int)$maxProducts ?> products</span>
          <span class="commerce-pill"><?= org_ecommerce_h(ucfirst($sellerPlan)) ?> seller plan</span>
        </div>
      </div>
      <div class="commerce-quick">
        <a href="sales_management.php" class="ch-btn-primary"><i class="icon ion-speedometer"></i> Sales management</a>
        <a href="products.php" class="ch-btn-primary"><i class="icon ion-plus"></i> Add product</a>
        <div class="commerce-quick-col">
          <a href="orders.php" class="ch-btn-ghost"><i class="icon ion-ios-list"></i> Orders</a>
          <a href="recent_orders.php" class="commerce-quick-sub">Recent orders</a>
        </div>
        <div class="commerce-quick-col">
          <a href="shop_settings.php" class="ch-btn-ghost"><i class="icon ion-gear-b"></i> Settings</a>
          <a href="seller_journey.php" class="commerce-quick-sub">Seller journey</a>
        </div>
        <?php if ($commerceBrand): ?>
        <a href="commerce_brand_select.php?switch=1" class="ch-btn-ghost"><i class="icon ion-shuffle"></i> Switch brand</a>
        <?php endif; ?>
      </div>
    </div>
  </section>

  <?php if ($commerceBrand): ?>
  <div class="commerce-panel mg-b-20 commerce-brand-system-panel" style="--brand-accent: <?= org_ecommerce_h((string)($commerceBrand['accent_color'] ?? '#0d9488')) ?>">
    <div class="commerce-panel-head">
      <h2><?= org_ecommerce_h((string)$commerceBrand['name']) ?> selling system</h2>
      <a href="commerce_brand_select.php?switch=1">Change brand</a>
    </div>
    <p class="commerce-brand-system-lead"><?= org_ecommerce_h((string)($brandSystem['order_hint'] ?? $commerceBrand['tagline'] ?? '')) ?></p>
    <div class="commerce-brand-system-meta">
      <?php if (!empty($brandSystem['pickup_enabled'])): ?>
        <span class="commerce-pill">Pickup enabled</span>
      <?php endif; ?>
      <?php if (!empty($brandSystem['delivery_enabled'])): ?>
        <span class="commerce-pill">Delivery enabled</span>
      <?php endif; ?>
      <span class="commerce-pill">Default: <?= org_ecommerce_h(strtoupper((string)($brandSystem['default_fulfillment'] ?? 'fbm'))) ?></span>
      <span class="commerce-pill"><?= org_ecommerce_h(ucfirst(str_replace('_', ' ', (string)($brandSystem['model'] ?? 'retail')))) ?></span>
    </div>
    <?php if (!empty($brandSystem['menu_categories']) && is_array($brandSystem['menu_categories'])): ?>
      <div class="commerce-brand-categories">
        <strong>Suggested menu categories</strong>
        <div class="commerce-brand-category-list">
          <?php foreach ($brandSystem['menu_categories'] as $cat): ?>
            <span><?= org_ecommerce_h((string)$cat) ?></span>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

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
          <?php if ($lowStock): ?>
          <div class="commerce-int-item commerce-int-alert">
            <span class="commerce-int-icon is-alert"><i class="icon ion-alert-circled"></i></span>
            <div class="commerce-int-body">
              <div class="commerce-int-alert-top">
                <strong>Low stock</strong>
                <a href="products.php" class="commerce-int-alert-link">Restock →</a>
              </div>
              <div class="commerce-int-alert-list">
                <?php foreach ($lowStock as $p): ?>
                  <a href="products.php?edit=<?= (int)$p['id'] ?>" class="commerce-int-stock-row">
                    <span><?= org_ecommerce_h((string)$p['title']) ?></span>
                    <span class="commerce-stock-qty"><?= (int)$p['stock_qty'] ?> left</span>
                  </a>
                <?php endforeach; ?>
              </div>
            </div>
          </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <aside class="commerce-side">
      <div class="commerce-panel">
        <div class="commerce-panel-head">
          <h2>Sales channels</h2>
          <a href="shop_settings.php">Configure channels</a>
        </div>
        <div class="commerce-channels">
          <div class="commerce-channel<?= $profileShopOn ? ' is-on' : '' ?>">
            <i class="icon ion-ios-person"></i>
            <strong>Profile shop</strong>
            <span><?= $profileShopOn ? 'Live on your page' : 'Not visible' ?></span>
          </div>
          <div class="commerce-channel<?= $marketplaceOn ? ' is-on' : '' ?>">
            <i class="icon ion-ios-world"></i>
            <strong>Marketplace</strong>
            <span><?= $marketplaceOn ? 'Discoverable listing' : 'Disabled' ?></span>
          </div>
          <div class="commerce-channel<?= $socialFeedOn ? ' is-on' : '' ?>">
            <i class="icon ion-ios-paperplane"></i>
            <strong>Social feed</strong>
            <span><?= $socialFeedOn ? 'Product posts enabled' : 'Disabled' ?></span>
          </div>
        </div>
      </div>
    </aside>
  </div>

</div>
<?php org_page_shell_close(); ?>
