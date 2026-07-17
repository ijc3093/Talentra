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
$sellerJourney = org_shop_seller_journey($dbh, $orgId);
$sellerPlan = org_shop_get_seller_plan($dbh, $orgId);
$productCount = org_shop_product_count($dbh, $orgId);
$maxProducts = org_shop_max_products($dbh, $orgId);
$stats = org_ecommerce_dashboard_stats($dbh, $orgId);

$journeySteps = [
    [
        'key' => 'account_ready',
        'label' => 'Seller account ready',
        'hint' => 'Organization registered with shop trial or active rent.',
        'action' => 'shop_settings.php',
        'action_label' => 'Shop settings',
    ],
    [
        'key' => 'catalog_listed',
        'label' => 'Products listed',
        'hint' => 'Create catalog items with title, images, bullets, and keywords.',
        'action' => 'products.php',
        'action_label' => 'Add products',
    ],
    [
        'key' => 'products_live',
        'label' => 'Live on marketplace',
        'hint' => 'Active listings visible to buyers when rent is current.',
        'action' => 'products.php',
        'action_label' => 'Review catalog',
    ],
    [
        'key' => 'inventory_ready',
        'label' => 'Inventory set',
        'hint' => 'Stock quantities or FBA send-in for platform fulfillment.',
        'action' => 'products.php',
        'action_label' => 'Update stock',
    ],
    [
        'key' => 'first_order',
        'label' => 'First customer order',
        'hint' => 'Buyer searches, adds to cart, and checks out.',
        'action' => 'recent_orders.php',
        'action_label' => 'View orders',
    ],
    [
        'key' => 'storefront_live',
        'label' => 'Storefront live',
        'hint' => 'Profile shop, marketplace, and social channels.',
        'action' => 'shop_settings.php',
        'action_label' => 'Configure channels',
    ],
];

$doneCount = 0;
foreach ($journeySteps as $step) {
    if (!empty($sellerJourney[$step['key']])) {
        $doneCount++;
    }
}
$progressPct = (int)round(($doneCount / max(1, count($journeySteps))) * 100);

$pageTitle = 'Seller journey';
require_once __DIR__ . '/includes/org_page_shell.php';
org_page_shell_open($pageTitle, '<link rel="stylesheet" href="css/commerce-hub.css?v=14">');
?>
<?php org_page_body_open('commerce-page'); ?>
  <section class="commerce-hero commerce-hero-compact">
    <div class="commerce-hero-inner">
      <div>
        <p class="commerce-hero-kicker"><a href="commerce.php">&larr; Commerce hub</a></p>
        <h1>Seller journey</h1>
        <p>Register → list → fulfill → get paid. Track your Amazon-style setup from one checklist.</p>
        <div class="commerce-hero-badges">
          <span class="commerce-pill"><?= (int)$doneCount ?> / <?= count($journeySteps) ?> steps complete</span>
          <span class="commerce-pill"><?= org_ecommerce_h(ucfirst($sellerPlan)) ?> seller plan</span>
          <span class="commerce-pill"><?= (int)$productCount ?> / <?= (int)$maxProducts ?> products</span>
        </div>
      </div>
      <div class="commerce-quick">
        <a href="products.php" class="ch-btn-primary"><i class="icon ion-plus"></i> Add product</a>
        <a href="shop_settings.php" class="ch-btn-ghost"><i class="icon ion-gear-b"></i> Settings</a>
      </div>
    </div>
  </section>

  <div class="commerce-panel mg-b-20">
    <div class="commerce-panel-head">
      <h2>Progress</h2>
      <span><?= (int)$progressPct ?>% complete</span>
    </div>
    <div class="commerce-journey-progress" role="progressbar" aria-valuenow="<?= (int)$progressPct ?>" aria-valuemin="0" aria-valuemax="100">
      <span style="width:<?= (int)$progressPct ?>%;"></span>
    </div>
    <?php if ($progressPct < 100): ?>
      <p class="commerce-journey-next tx-12 tx-color-03 mg-b-0 mg-t-10">
        <?php
          $next = null;
          foreach ($journeySteps as $step) {
              if (empty($sellerJourney[$step['key']])) {
                  $next = $step;
                  break;
              }
          }
        ?>
        <?php if ($next): ?>
          Next up: <strong><?= org_ecommerce_h($next['label']) ?></strong>
        <?php endif; ?>
      </p>
    <?php else: ?>
      <p class="commerce-journey-next tx-12 mg-b-0 mg-t-10" style="color:#16a34a;font-weight:600;">All setup steps complete — keep fulfilling orders and growing sales.</p>
    <?php endif; ?>
  </div>

  <div class="commerce-panel">
    <div class="commerce-panel-head">
      <h2>Setup checklist</h2>
      <span>End-to-end seller flow</span>
    </div>
    <ol class="commerce-journey-list list-unstyled mg-b-0">
      <?php foreach ($journeySteps as $i => $step):
        $done = !empty($sellerJourney[$step['key']]);
      ?>
      <li class="commerce-journey-step<?= $done ? ' is-done' : '' ?>">
        <div class="commerce-journey-step-num"><?= $done ? '✓' : (string)($i + 1) ?></div>
        <div class="commerce-journey-step-body">
          <strong><?= org_ecommerce_h($step['label']) ?></strong>
          <p><?= org_ecommerce_h($step['hint']) ?></p>
          <?php if (!$done && !empty($step['action'])): ?>
            <a href="<?= org_ecommerce_h((string)$step['action']) ?>" class="commerce-journey-action"><?= org_ecommerce_h((string)$step['action_label']) ?> →</a>
          <?php endif; ?>
        </div>
        <span class="commerce-journey-status"><?= $done ? 'Done' : 'To do' ?></span>
      </li>
      <?php endforeach; ?>
    </ol>
  </div>

  <div class="commerce-footer-actions mg-t-20">
    <a href="recent_orders.php" class="commerce-action-tile">
      <i class="icon ion-ios-paper"></i>
      <strong>Recent orders</strong>
      <span><?= (int)$stats['orders_open'] ?> open · <?= (int)$stats['orders_mtd'] ?> this month</span>
    </a>
    <a href="orders.php" class="commerce-action-tile">
      <i class="icon ion-ios-list"></i>
      <strong>Order inbox</strong>
      <span>Fulfillment, tracking, CRM sync</span>
    </a>
    <a href="commerce_analytics.php" class="commerce-action-tile">
      <i class="icon ion-pie-graph"></i>
      <strong>Analytics</strong>
      <span>Revenue and top products</span>
    </a>
  </div>
</div>
<?php org_page_shell_close(); ?>
