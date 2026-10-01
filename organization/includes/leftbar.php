<?php
declare(strict_types=1);

require_once __DIR__ . '/session_org_login.php';
orgRequireLoginOnly();

require_once __DIR__ . '/../../admin/controller.php';
$leftbarDbh = (new Controller())->pdo();
require_once __DIR__ . '/org_theme_prefs.php';
require_once __DIR__ . '/org_layout.php';
require_once __DIR__ . '/org_manager_guard.php';
require_once __DIR__ . '/../../public_user/includes/org_shop.php';
$leftbarPublisherUserId = org_theme_viewer_user_id($leftbarDbh);

$isManager = isOrgManager();
$isCommerceSeller = org_active_is_commerce_seller($leftbarDbh);
$label = $isManager
    ? ($isCommerceSeller ? 'Seller workspace' : 'Publisher workspace')
    : 'Staff workspace';
$currentOrgPage = org_layout_current_page();
$isSalesManagementPage = in_array($currentOrgPage, ['sales_management.php', 'overview.php', 'transactions.php', 'refunds.php', 'reviews.php', 'analytics.php', 'marketing.php'], true);
$salesAttention = [
    'total' => 0,
    'orders' => 0,
    'delivery' => 0,
    'products' => 0,
    'inventory_low' => 0,
    'inventory_out' => 0,
    'customers' => 0,
    'returns' => 0,
    'notification' => 0,
    'messages' => 0,
    'support' => 0,
    'disputes' => 0,
];
if ($isManager && $isCommerceSeller) {
    try {
        require_once __DIR__ . '/org_sales.php';
        $salesAttention = org_sales_attention_counts($leftbarDbh, (int)orgActiveOrgId());
    } catch (Throwable $e) {
        // keep zeros
    }
}
$salesManagementNav = [
    ['Dashboard', 'dashboard', 'ion-speedometer', '', ''],
    ['Quotations', 'quotations', 'ion-document-text', '', ''],
    ['Delivery / Shipping', 'delivery-shipping', 'ion-model-s', '', 'delivery'],
    ['Salespersons', 'salespersons', 'ion-person-stalker', '', ''],
    ['Products', 'product-catalog', 'ion-ios-pricetags', '', 'stock'],
    ['Create New Products', 'products', 'ion-ios-box', '', ''],
    ['Inventory', 'inventory', 'ion-grid', '', 'stock'],
    ['Transactions', 'transactions', 'ion-arrow-swap', '', ''],
    ['Overview', 'overview', 'ion-ios-pie', '', ''],
    ['Orders', 'orders', 'ion-ios-list', '', 'orders'],
    ['Returns & Refunds', 'refunds', 'ion-reply', '', 'returns'],
    ['Notification', 'notification', 'ion-alert-circled', '', 'notification'],
    ['Messages', 'message', 'ion-chatboxes', '', 'messages'],
    ['Invoices', 'invoices', 'ion-card', '', ''],
    ['Discounts & Promotions', 'discounts-promotions', 'ion-pricetag', '', ''],
    ['Employee detail', 'detail_employee', 'ion-ios-person', '', ''],
    ['Customers', 'customers', 'ion-ios-people', '', ''],
    ['Reviews', 'reviews', 'ion-star', '', ''],
    ['Analytics', 'analytics', 'ion-stats-bars', '', ''],
    ['Marketing', 'marketing', 'ion-paper-airplane', '', ''],
    ['Store Settings', 'settings', 'ion-gear-a', '', ''],
    ['Payments', 'payments', 'ion-cash', '', ''],
    ['Payroll', 'payroll', 'ion-ios-briefcase', '', ''],
    ['Account', 'accounts', 'ion-person org-account-nav-icon', '', ''],
    ['Time card', 'timecard', 'ion-ios-clock', '', ''],
    ['Sales reports', 'sales-reports', 'ion-stats-bars', '', ''],
];

if (!function_exists('h')) {
    function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}
$salesNavBadgeHtml = static function (int $count): string {
    if ($count <= 0) {
        return '';
    }
    $label = $count > 99 ? '99+' : (string)$count;
    $safe = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
    $wide = strlen($label) > 1 ? ' is-wide' : '';
    return '<span class="org-sales-nav-badge-wrap' . $wide . '" aria-hidden="true" title="Needs attention">'
        . '<b class="org-sales-nav-badge">' . $safe . '</b>'
        . '</span>';
};
$salesNavStockBadgeHtml = static function (int $low, int $out): string {
    if ($low <= 0 && $out <= 0) {
        return '';
    }
    $parts = [];
    if ($low > 0) {
        $n = $low > 99 ? '99+' : (string)$low;
        $parts[] = '<span class="org-sales-nav-stock-badge is-low" title="Low stock — restock soon" aria-hidden="true">'
            . '<i class="fa fa-exclamation-triangle"></i>'
            . '<b>' . htmlspecialchars($n, ENT_QUOTES, 'UTF-8') . '</b>'
            . '</span>';
    }
    if ($out > 0) {
        $n = $out > 99 ? '99+' : (string)$out;
        $parts[] = '<span class="org-sales-nav-stock-badge is-out" title="Out of stock" aria-hidden="true">'
            . '<i class="fa fa-ban"></i>'
            . '<b>' . htmlspecialchars($n, ENT_QUOTES, 'UTF-8') . '</b>'
            . '</span>';
    }
    return '<span class="org-sales-nav-stock-wrap">' . implode('', $parts) . '</span>';
};
?>
<style>
  .org-sales-nav-badge-wrap{
    display:inline-flex !important;
    align-items:center;
    justify-content:center;
    margin-left:auto !important;
    flex-shrink:0 !important;
    line-height:0;
  }
  .org-sales-nav-badge,
  body.org-app .sh-sideleft-menu .nav > .nav-item > .nav-link .org-sales-nav-badge,
  body.org-app .sh-sideleft-menu .nav > .nav-item > .sales-management-nav-link .org-sales-nav-badge,
  body.org-app .sh-sideleft-menu .nav > .nav-item > .sales-management-nav-link:hover .org-sales-nav-badge,
  body.org-app .sh-sideleft-menu .nav > .nav-item > .sales-management-nav-link:focus .org-sales-nav-badge,
  body.org-app .sh-sideleft-menu .nav > .nav-item > .sales-management-nav-link.active .org-sales-nav-badge,
  body.org-app .org-sideleft-scroll .nav > .nav-item > .nav-link .org-sales-nav-badge,
  body.org-app .org-sales-support-center .org-sales-nav-badge,
  .org-sales-support-center .org-sales-nav-badge{
    display:inline-flex !important;
    align-items:center;
    justify-content:center;
    min-width:18px;
    height:18px;
    padding:0 5px;
    border-radius:999px;
    background:#dc3545 !important;
    background-color:#dc3545 !important;
    color:#ffffff !important;
    -webkit-text-fill-color:#ffffff !important;
    font-size:10px !important;
    font-weight:800 !important;
    font-style:normal !important;
    line-height:1 !important;
    border:none !important;
    box-shadow:none !important;
    flex-shrink:0 !important;
  }
  .org-sales-nav-badge-wrap.is-wide .org-sales-nav-badge{
    min-width:22px;
  }
  .org-sales-nav-stock-wrap{
    display:inline-flex !important;
    align-items:center;
    gap:4px;
    margin-left:auto !important;
    flex-shrink:0 !important;
  }
  .org-sales-nav-stock-badge{
    display:inline-flex !important;
    align-items:center;
    justify-content:center;
    gap:3px;
    min-height:18px;
    padding:0 6px;
    border-radius:999px;
    font-size:10px !important;
    font-weight:800 !important;
    line-height:1 !important;
    white-space:nowrap;
  }
  .org-sales-nav-stock-badge i{
    font-size:10px !important;
    line-height:1 !important;
    width:auto !important;
    margin:0 !important;
  }
  .org-sales-nav-stock-badge b{
    font-weight:800 !important;
    font-style:normal !important;
  }
  .org-sales-nav-stock-badge.is-low,
  body.org-app .sh-sideleft-menu .nav > .nav-item > .sales-management-nav-link .org-sales-nav-stock-badge.is-low,
  body.org-app .sh-sideleft-menu .nav > .nav-item > .sales-management-nav-link.active .org-sales-nav-stock-badge.is-low{
    background:#ffedd5 !important;
    background-color:#ffedd5 !important;
    color:#c2410c !important;
    -webkit-text-fill-color:#c2410c !important;
    border:1px solid #fdba74 !important;
  }
  .org-sales-nav-stock-badge.is-out,
  body.org-app .sh-sideleft-menu .nav > .nav-item > .sales-management-nav-link .org-sales-nav-stock-badge.is-out,
  body.org-app .sh-sideleft-menu .nav > .nav-item > .sales-management-nav-link.active .org-sales-nav-stock-badge.is-out{
    background:#fee2e2 !important;
    background-color:#fee2e2 !important;
    color:#b91c1c !important;
    -webkit-text-fill-color:#b91c1c !important;
    border:1px solid #fca5a5 !important;
  }
  .org-sideleft-nav .nav-link{
    display:flex;
    align-items:center;
    gap:8px;
    background:transparent !important;
    background-color:transparent !important;
    box-shadow:none !important;
  }
  .org-account-nav-icon{
    position:relative;
    overflow:visible !important;
  }
  .org-account-nav-icon::after{
    content:"\f013";
    position:absolute;
    right:-7px;
    bottom:-5px;
    width:12px;
    height:12px;
    display:grid;
    place-items:center;
    border-radius:50%;
    font:normal normal normal 10px/1 FontAwesome;
    color:inherit;
    background:var(--org-page-bg, var(--msb-palette-bg, #fff));
  }
  .org-sideleft-nav .nav-link:hover:not(.active),
  body.org-app .sh-sideleft-menu .nav > .nav-item > .nav-link:hover:not(.active),
  body.org-app .org-sideleft-top .nav > .nav-item > .nav-link:hover:not(.active){
    background:var(--msb-palette-nav-hover, var(--msb-palette-action-soft, rgba(37, 99, 235, 0.10))) !important;
    background-color:var(--msb-palette-nav-hover, var(--msb-palette-action-soft, rgba(37, 99, 235, 0.10))) !important;
    color:var(--msb-palette-text, #334155) !important;
    box-shadow:none !important;
  }
  html.dark-auto .org-sideleft-nav .nav-link:hover:not(.active),
  html.dark-auto body.org-app .sh-sideleft-menu .nav > .nav-item > .nav-link:hover:not(.active),
  html.dark-auto body.org-app .org-sideleft-top .nav > .nav-item > .nav-link:hover:not(.active),
  html.dark-auto body.org-app .org-sideleft-scroll .nav > .nav-item > .nav-link:hover:not(.active){
    background:var(--msb-palette-nav-hover, rgba(148, 163, 184, 0.16)) !important;
    background-color:var(--msb-palette-nav-hover, rgba(148, 163, 184, 0.16)) !important;
    color:var(--msb-palette-text, #e8edf5) !important;
  }
  .org-sideleft-nav .nav-link:hover:not(.active) > span,
  .org-sideleft-nav .nav-link:hover:not(.active) i,
  .org-sideleft-nav .nav-link:hover:not(.active) [class*="ion-"]{
    color:inherit !important;
    background:transparent !important;
  }
  html.dark-auto .org-sideleft-nav .nav-link:hover:not(.active) > span,
  html.dark-auto .org-sideleft-nav .nav-link:hover:not(.active) i,
  html.dark-auto .org-sideleft-nav .nav-link:hover:not(.active) [class*="ion-"]{
    color:var(--msb-palette-text, #e8edf5) !important;
  }
  .org-sideleft-nav .nav-link:focus,
  body.org-app .sh-sideleft-menu .nav > .nav-item > .nav-link:focus{
    outline:none !important;
    box-shadow:none !important;
  }
  .org-sideleft-nav .nav-link:focus:not(:focus-visible):not(.active),
  body.org-app .sh-sideleft-menu .nav > .nav-item > .nav-link:focus:not(:focus-visible):not(.active){
    background:transparent !important;
    background-color:transparent !important;
  }
  .org-sideleft-nav .nav-link.active:not(.sales-management-nav-link){
    background:var(--msb-palette-action-soft, rgba(37, 99, 235, 0.18)) !important;
    background-color:var(--msb-palette-action-soft, rgba(37, 99, 235, 0.18)) !important;
  }
  .org-sideleft-nav .sales-management-nav-link.active{
    background:transparent !important;
    background-color:transparent !important;
    background-image:none !important;
    color:var(--msb-palette-action, #60a5fa) !important;
    box-shadow:none !important;
  }
  .org-sideleft-nav .sales-management-nav-link.active i,
  .org-sideleft-nav .sales-management-nav-link.active [class*="ion-"],
  .org-sideleft-nav .sales-management-nav-link.active .icon,
  .org-sideleft-nav .sales-management-nav-link.active > span{
    color:var(--msb-palette-action, #60a5fa) !important;
    background:transparent !important;
  }
  html.dark-auto .org-sideleft-nav .nav-link.active:not(.sales-management-nav-link){
    background:var(--msb-palette-action-soft, rgba(37, 99, 235, 0.26)) !important;
    background-color:var(--msb-palette-action-soft, rgba(37, 99, 235, 0.26)) !important;
    color:var(--msb-palette-action, #93c5fd) !important;
  }
  html.dark-auto .org-sideleft-nav .sales-management-nav-link.active{
    background:transparent !important;
    background-color:transparent !important;
    background-image:none !important;
    color:var(--msb-palette-action, #60a5fa) !important;
  }
  html.dark-auto .org-sideleft-nav .sales-management-nav-link.active i,
  html.dark-auto .org-sideleft-nav .sales-management-nav-link.active [class*="ion-"],
  html.dark-auto .org-sideleft-nav .sales-management-nav-link.active .icon,
  html.dark-auto .org-sideleft-nav .sales-management-nav-link.active > span{
    color:var(--msb-palette-action, #60a5fa) !important;
    background:transparent !important;
  }
  .org-sideleft-nav .sales-management-nav-link:hover:not(.active),
  html.dark-auto .org-sideleft-nav .sales-management-nav-link:hover:not(.active){
    background:rgba(148, 163, 184, 0.14) !important;
    background-color:rgba(148, 163, 184, 0.14) !important;
    color:var(--msb-palette-text, #e8edf5) !important;
  }
  .org-sales-support-center{
    flex:0 0 auto;
    display:flex;
    align-items:center;
    gap:8px;
    margin:4px 8px 8px;
    padding:9px 12px;
    color:var(--msb-palette-text, var(--org-text, #111827));
    font-size:13px;
    font-weight:600;
    text-decoration:none;
    line-height:1.2;
    border-radius:8px;
  }
  .org-sales-support-center:hover,
  .org-sales-support-center:focus,
  .org-sales-support-center.active{
    color:var(--msb-palette-action, #60a5fa);
    background:transparent;
    text-decoration:none;
  }
  .org-sales-support-center i{
    font-size:16px;
    color:var(--msb-palette-text-muted, var(--org-text-muted, #64748b));
  }
  .org-sales-support-center:hover i,
  .org-sales-support-center:focus i,
  .org-sales-support-center.active i{
    color:inherit;
  }
</style>
<div class="sh-sideleft-menu org-sideleft-shell">
  <div class="org-sideleft-top">
    <label class="sh-sidebar-label"><?= h($label) ?></label>
    <ul class="nav org-sideleft-nav">
      <li class="nav-item">
        <a href="feed.php#Home_feed" class="nav-link">
          <i class="icon ion-ios-home-outline"></i>
          <span>Home feed</span>
        </a>
      </li>
    </ul>
  </div>

  <div class="org-sideleft-scroll" role="navigation" aria-label="Organization navigation">
    <?php if ($isSalesManagementPage): ?>
      <label class="sh-sidebar-label org-sales-workflow-label">Sales workflow modules</label>
    <?php endif; ?>
    <div class="<?= $isSalesManagementPage ? 'org-sales-workflow-scroll' : '' ?>"<?= $isSalesManagementPage ? ' tabindex="0" aria-label="Sales workflow modules list"' : '' ?>>
    <ul class="nav org-sideleft-nav">
      <?php if ($isSalesManagementPage): ?>
        <?php foreach ($salesManagementNav as $item): ?>
          <?php
            $salesNavSlug = (string)($item[1] ?? '');
            // Payroll, payments, and store settings are manager-only.
            if (!$isManager && in_array($salesNavSlug, ['payroll', 'payments', 'settings'], true)) {
                continue;
            }
            $salesNavHref = trim((string)($item[3] ?? ''));
            $salesNavIsExternal = $salesNavHref !== '';
            // Hash-only while already on sales hub so clicks switch the right panel
            // without a full reload / SPA tear-down.
            if ($salesNavIsExternal) {
                $salesNavLink = $salesNavHref;
            } elseif ($isSalesManagementPage) {
                $salesNavLink = '#' . $salesNavSlug;
            } else {
                $salesNavLink = 'sales_management.php#' . $salesNavSlug;
            }
            $salesStandalone = [
                'overview.php' => 'overview',
                'transactions.php' => 'transactions',
                'refunds.php' => 'refunds',
                'reviews.php' => 'reviews',
                'analytics.php' => 'analytics',
                'marketing.php' => 'marketing',
            ];
            if (isset($salesStandalone[$currentOrgPage]) && $salesNavSlug === $salesStandalone[$currentOrgPage]) {
                $salesNavLink = $currentOrgPage;
            }
            $salesNavCountKey = (string)($item[4] ?? '');
            $salesNavIsStock = ($salesNavCountKey === 'stock');
            $salesNavLow = (int)($salesAttention['inventory_low'] ?? 0);
            $salesNavOut = (int)($salesAttention['inventory_out'] ?? 0);
            $salesNavCount = (!$salesNavIsStock && $salesNavCountKey !== '' && isset($salesAttention[$salesNavCountKey]))
                ? (int)$salesAttention[$salesNavCountKey]
                : 0;
            $salesNavActive = isset($salesStandalone[$currentOrgPage]) && $salesNavSlug === $salesStandalone[$currentOrgPage];
            $salesNavAria = '';
            if ($salesNavIsStock && ($salesNavLow > 0 || $salesNavOut > 0)) {
                $bits = [];
                if ($salesNavLow > 0) {
                    $bits[] = ($salesNavLow > 99 ? '99+' : (string)$salesNavLow) . ' low stock';
                }
                if ($salesNavOut > 0) {
                    $bits[] = ($salesNavOut > 99 ? '99+' : (string)$salesNavOut) . ' out of stock';
                }
                $salesNavAria = (string)$item[0] . ' — ' . implode(', ', $bits);
            } elseif ($salesNavCount > 0) {
                $salesNavAria = (string)$item[0] . ' — ' . ($salesNavCount > 99 ? '99+' : (string)$salesNavCount) . ' need attention';
            }
          ?>
          <li class="nav-item">
            <a
              href="<?= h($salesNavLink) ?>"
              class="nav-link sales-management-nav-link<?= $salesNavActive ? ' active' : '' ?>"
              <?php if (!$salesNavIsExternal): ?>data-sales-nav="<?= h($salesNavSlug) ?>" onclick="if(window.__salesSetHash){event.preventDefault();event.stopPropagation();window.__salesSetHash('<?= h($salesNavSlug) ?>',true);return false;}"<?php endif; ?>
              <?php if ($salesNavIsStock): ?>data-sales-stock-badge="1"<?php elseif ($salesNavCountKey !== ''): ?>data-sales-count-key="<?= h($salesNavCountKey) ?>"<?php endif; ?>
              <?php if ($salesNavAria !== ''): ?>aria-label="<?= h($salesNavAria) ?>"<?php endif; ?>
            >
              <i class="icon <?= h((string)$item[2]) ?>"></i>
              <span><?= h((string)$item[0]) ?></span>
              <?php if ($salesNavIsStock): ?>
                <?= $salesNavStockBadgeHtml($salesNavLow, $salesNavOut) ?>
              <?php else: ?>
                <?= $salesNavBadgeHtml($salesNavCount) ?>
              <?php endif; ?>
            </a>
          </li>
        <?php endforeach; ?>
      <?php else: ?>
      <?php if ($isManager): ?>
      <li class="nav-item">
        <a href="compose_post.php#New_announcement" class="nav-link"<?= org_layout_nav_attrs('compose_post.php') ?>>
          <i class="icon ion-ios-paperplane"></i>
          <span>New announcement</span>
        </a>
      </li>
      <li class="nav-item">
        <a href="dashboard.php#Publisher_hub" class="nav-link"<?= org_layout_nav_attrs('dashboard.php') ?>>
          <i class="icon ion-ios-pulse"></i>
          <span>Publisher hub</span>
        </a>
      </li>
      <?php endif; ?>

      <li class="nav-item">
        <a href="posts.php#Posts" class="nav-link"<?= org_layout_nav_attrs('posts.php') ?>>
          <i class="icon ion-ios-list"></i>
          <span>Posts</span>
        </a>
      </li>

      <li class="nav-item">
        <a href="messages.php#Messages" class="nav-link">
          <i class="icon ion-chatbubble"></i>
          <span>Messages</span>
        </a>
      </li>

      <?php if ($leftbarPublisherUserId > 0): ?>
        <?php if ($isManager): ?>
        <!-- <li class="nav-item">
          <a href="publisher_public_enter.php?to=compose" class="nav-link">
            <i class="icon ion-ios-paperplane"></i>
            <span>Publish to public</span>
          </a>
        </li> -->
        <!-- <li class="nav-item">
          <a href="publisher_public_enter.php?to=feed" class="nav-link">
            <i class="icon ion-ios-world-outline"></i>
            <span>Public feed</span>
          </a>
        </li> -->
        <?php else: ?>
        <li class="nav-item">
          <a href="../public_user/staff_publisher_portal.php" class="nav-link">
            <i class="icon ion-ios-world-outline"></i>
            <span>Public feed</span>
          </a>
        </li>
        <?php endif; ?>
      <?php elseif (!$isManager): ?>
      <li class="nav-item">
        <a href="../public_user/staff_publisher_portal.php" class="nav-link">
          <i class="icon ion-ios-world-outline"></i>
          <span>Public feed</span>
        </a>
      </li>
      <?php endif; ?>

      <?php if ($isManager && $isCommerceSeller): ?>
      <li class="nav-item">
        <a href="commerce.php#Shop_commerce" class="nav-link"<?= org_layout_nav_attrs('commerce.php') ?>>
          <i class="icon ion-bag"></i>
          <span>Shop &amp; commerce</span>
        </a>
      </li>
      <li class="nav-item">
        <a href="shop_rent.php#Shop_rent" class="nav-link">
          <i class="icon ion-card"></i>
          <span>Shop rent</span>
        </a>
      </li>
      <li class="nav-item">
        <a href="sales_management.php#dashboard" class="nav-link"<?= org_layout_nav_attrs('sales_management.php') ?>
           data-sales-hub-link="1"
           <?php if ((int)($salesAttention['total'] ?? 0) > 0): ?>aria-label="Sales management — <?= (int)$salesAttention['total'] > 99 ? '99+' : (int)$salesAttention['total'] ?> items need attention"<?php endif; ?>>
          <i class="icon ion-speedometer"></i>
          <span>Sales management</span>
          <?= $salesNavBadgeHtml((int)($salesAttention['total'] ?? 0)) ?>
        </a>
      </li>
      <li class="nav-item">
        <a href="sales_management.php#detail_employee" class="nav-link" data-sales-nav="detail_employee">
          <i class="icon ion-ios-person"></i>
          <span>Employee detail</span>
        </a>
      </li>
      <li class="nav-item">
        <a href="crm.php#Customers_CRM" class="nav-link"<?= org_layout_nav_attrs('crm.php') ?>>
          <i class="icon ion-ios-people"></i>
          <span>Customers (CRM)</span>
        </a>
      </li>
      <?php endif; ?>

      <?php if (!$isManager && $isCommerceSeller): ?>
      <li class="nav-item">
        <a href="sales_management.php#dashboard" class="nav-link"<?= org_layout_nav_attrs('sales_management.php') ?>>
          <i class="icon ion-speedometer"></i>
          <span>Sales management</span>
        </a>
      </li>
      <?php endif; ?>

      <?php if ($isManager): ?>
      <li class="nav-item">
        <a href="members.php#Team" class="nav-link"<?= org_layout_nav_attrs('members.php') ?>>
          <i class="icon ion-person-stalker"></i>
          <span>Team</span>
        </a>
      </li>
      <?php endif; ?>

      <?php if ($isCommerceSeller): ?>
      <li class="nav-item">
        <a href="sales_management.php#accounts" class="nav-link" data-sales-nav="accounts">
          <i class="icon ion-person org-account-nav-icon"></i>
          <span>Account</span>
        </a>
      </li>
      <li class="nav-item">
        <a href="sales_management.php#timecard" class="nav-link" data-sales-nav="timecard">
          <i class="icon ion-ios-clock"></i>
          <span>Time card</span>
        </a>
      </li>
      <li class="nav-item">
        <a href="<?= $isManager ? 'members.php?tab=managers#Team_details' : 'detail_employee.php#My_details' ?>" class="nav-link"<?= $isManager ? org_layout_nav_attrs('members.php') : org_layout_nav_attrs('detail_employee.php') ?>>
          <i class="icon ion-ios-person"></i>
          <span><?= $isManager ? 'Team details' : 'My details' ?></span>
        </a>
      </li>
      <?php endif; ?>

      <?php if ($isManager): ?>
      <li class="nav-item">
        <a href="create_staff.php#Add_staff" class="nav-link"<?= org_layout_nav_attrs('create_staff.php') ?>>
          <i class="icon ion-person-add"></i>
          <span>Add staff</span>
        </a>
      </li>
      <li class="nav-item">
        <a href="create_org.php#New_organization" class="nav-link"<?= org_layout_nav_attrs('create_org.php') ?>>
          <i class="icon ion-ios-plus-outline"></i>
          <span>New organization</span>
        </a>
      </li>
      <?php endif; ?>
      <?php endif; ?>
    </ul>
    </div>
    <?php if ($isSalesManagementPage): ?>
      <?php
        $supportBadgeCount = (int)($salesAttention['support'] ?? 0) + (int)($salesAttention['disputes'] ?? 0);
      ?>
      <a
        class="org-sales-support-center"
        href="<?= $isSalesManagementPage ? '#support-center' : 'sales_management.php#support-center' ?>"
        data-sales-nav="support-center"
        data-sales-badge-key="support"
        onclick="if(window.__salesSetHash){event.preventDefault();event.stopPropagation();window.__salesSetHash('support-center',true);return false;}"
        <?php if ($supportBadgeCount > 0): ?>aria-label="Support Center — <?= $supportBadgeCount > 99 ? '99+' : $supportBadgeCount ?> unread"<?php endif; ?>
      >
        <i class="icon ion-ios-help"></i>
        <span>Support Center</span>
        <?= $salesNavBadgeHtml($supportBadgeCount) ?>
      </a>
    <?php endif; ?>
  </div>
</div>
<?php if ($isSalesManagementPage): ?>
<script src="js/sales-hub-nav.js?v=13"></script>
<?php endif; ?>
<?php if ($isManager && $isCommerceSeller): ?>
<script>
(function () {
  var endpoint = 'ajax/sales_attention_badge.php';
  function paintBadge(el, count) {
    if (!el) return;
    var wrap = el.querySelector('.org-sales-nav-badge-wrap');
    if (count <= 0) {
      if (wrap) wrap.remove();
      return;
    }
    var label = count > 99 ? '99+' : String(count);
    var wide = label.length > 1 ? ' is-wide' : '';
    var html = '<span class="org-sales-nav-badge-wrap' + wide + '" aria-hidden="true" title="Needs attention">'
      + '<b class="org-sales-nav-badge">' + label + '</b></span>';
    if (wrap) wrap.outerHTML = html;
    else el.insertAdjacentHTML('beforeend', html);
  }
  function paintStockBadges(el, low, out) {
    if (!el) return;
    var wrap = el.querySelector('.org-sales-nav-stock-wrap');
    low = parseInt(low || 0, 10) || 0;
    out = parseInt(out || 0, 10) || 0;
    if (low <= 0 && out <= 0) {
      if (wrap) wrap.remove();
      el.removeAttribute('aria-label');
      return;
    }
    var parts = [];
    var ariaBits = [];
    var label = (el.querySelector('span') && el.querySelector('span').textContent) ? el.querySelector('span').textContent.trim() : 'Items';
    if (low > 0) {
      var ln = low > 99 ? '99+' : String(low);
      parts.push('<span class="org-sales-nav-stock-badge is-low" title="Low stock — restock soon" aria-hidden="true"><i class="fa fa-exclamation-triangle"></i><b>' + ln + '</b></span>');
      ariaBits.push(ln + ' low stock');
    }
    if (out > 0) {
      var on = out > 99 ? '99+' : String(out);
      parts.push('<span class="org-sales-nav-stock-badge is-out" title="Out of stock" aria-hidden="true"><i class="fa fa-ban"></i><b>' + on + '</b></span>');
      ariaBits.push(on + ' out of stock');
    }
    var html = '<span class="org-sales-nav-stock-wrap">' + parts.join('') + '</span>';
    if (wrap) wrap.outerHTML = html;
    else el.insertAdjacentHTML('beforeend', html);
    el.setAttribute('aria-label', label + ' — ' + ariaBits.join(', '));
  }
  function applyCounts(data) {
    if (!data || !data.ok) return;
    var counts = data.counts || {};
    var total = parseInt(data.total || counts.total || 0, 10) || 0;
    var low = parseInt(counts.inventory_low || 0, 10) || 0;
    var out = parseInt(counts.inventory_out || 0, 10) || 0;

    document.querySelectorAll('a[data-sales-hub-link="1"]').forEach(function (a) {
      paintBadge(a, total);
      if (total > 0) {
        a.setAttribute('aria-label', 'Sales management — ' + (total > 99 ? '99+' : total) + ' items need attention');
      } else {
        a.removeAttribute('aria-label');
      }
    });

    document.querySelectorAll('a[data-sales-count-key]').forEach(function (a) {
      var key = a.getAttribute('data-sales-count-key') || '';
      if (!key) return;
      paintBadge(a, parseInt(counts[key] || 0, 10) || 0);
    });

    document.querySelectorAll('a[data-sales-stock-badge="1"]').forEach(function (a) {
      paintStockBadges(a, low, out);
    });

    document.querySelectorAll('a[data-sales-badge-key="support"]').forEach(function (a) {
      var n = (parseInt(counts.support || 0, 10) || 0) + (parseInt(counts.disputes || 0, 10) || 0);
      paintBadge(a, n);
    });

    var headerTitle = document.querySelector('.org-header-page-title[data-sales-hub-title="1"]');
    if (headerTitle) {
      var headerBadge = headerTitle.querySelector('.org-header-sales-badge');
      if (total > 0) {
        var label = total > 99 ? '99+' : String(total);
        if (headerBadge) {
          headerBadge.textContent = label;
          headerBadge.classList.add('is-pulse', 'is-alert');
        } else {
          headerTitle.insertAdjacentHTML('beforeend', '<span class="org-header-sales-badge is-pulse is-alert" aria-hidden="true">' + label + '</span>');
        }
        headerTitle.setAttribute('aria-label', 'Sales Management — ' + label + ' items need attention');
      } else if (headerBadge) {
        headerBadge.remove();
      }
    }
  }
  async function refresh() {
    try {
      var res = await fetch(endpoint + '?_=' + Date.now(), { credentials: 'same-origin', cache: 'no-store' });
      applyCounts(await res.json());
    } catch (e) { /* ignore */ }
  }
  refresh();
  setInterval(refresh, 20000);
  document.addEventListener('visibilitychange', function () {
    if (!document.hidden) refresh();
  });
})();
</script>
<?php endif; ?>
