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
$isSalesManagementPage = ($currentOrgPage === 'sales_management.php');
$salesAttention = [
    'total' => 0,
    'orders' => 0,
    'delivery' => 0,
    'products' => 0,
    'customers' => 0,
    'returns' => 0,
    'notification' => 0,
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
    ['Create New Products', 'products', 'ion-ios-box', '', ''],
    ['Product Table', 'product-table', 'ion-grid', '', 'products'],
    ['Orders', 'orders', 'ion-ios-list', '', 'orders'],
    ['Returns & Refunds', 'returns-refunds', 'ion-reply', '', 'returns'],
    ['Notification', 'notification', 'ion-alert-circled', '', 'notification'],
    ['Invoices', 'invoices', 'ion-card', '', ''],
    ['Discounts & Promotions', 'discounts-promotions', 'ion-pricetag', '', ''],
    ['Settings', 'settings', 'ion-ios-gear', '', ''],
    ['Customers', 'customers', 'ion-ios-people', '', 'customers'],
    ['Payments', 'payments', 'ion-cash', '', ''],
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
    // SVG keeps red fill + white digits even when theme forces nav-link greys/blues.
    $width = (strlen($label) > 1) ? 28 : 22;
    return '<span class="org-sales-nav-badge-wrap is-alert" aria-hidden="true" title="Needs attention">'
        . '<svg class="org-sales-nav-badge" width="' . $width . '" height="22" viewBox="0 0 ' . $width . ' 22" focusable="false" style="display:block;overflow:visible;">'
        . '<rect x="0" y="0" width="' . $width . '" height="22" rx="11" ry="11" fill="#dc3545" style="fill:#dc3545!important;"></rect>'
        . '<text x="' . ($width / 2) . '" y="15" text-anchor="middle" fill="#ffffff" font-size="12" font-weight="800" font-family="system-ui,-apple-system,Segoe UI,sans-serif" style="fill:#ffffff!important;color:#ffffff!important;">'
        . $safe
        . '</text></svg></span>';
};
?>
<style>
  @keyframes orgSalesBadgeAlert {
    0%, 100% {
      transform: scale(1) translate(0, 0);
      filter: drop-shadow(0 0 0 rgba(220, 53, 69, 0));
    }
    12% {
      transform: scale(1.14) translate(0, -1px);
      filter: drop-shadow(0 0 7px rgba(220, 53, 69, 0.9));
    }
    24% {
      transform: scale(1.06) translate(0, 0);
      filter: drop-shadow(0 0 3px rgba(220, 53, 69, 0.5));
    }
    36% {
      transform: scale(1.12) translate(0, -1px);
      filter: drop-shadow(0 0 6px rgba(220, 53, 69, 0.75));
    }
    50% {
      transform: scale(1) translate(0, 0);
      filter: drop-shadow(0 0 2px rgba(220, 53, 69, 0.35));
    }
    78% {
      transform: scale(1) translate(0, 0);
      filter: drop-shadow(0 0 2px rgba(220, 53, 69, 0.35));
    }
    82% {
      transform: scale(1.08) translate(-2px, 0);
      filter: drop-shadow(0 0 5px rgba(220, 53, 69, 0.7));
    }
    86% {
      transform: scale(1.08) translate(2px, 0);
    }
    90% {
      transform: scale(1.05) translate(-1px, 0);
    }
    94% {
      transform: scale(1.03) translate(1px, 0);
    }
  }
  .org-sales-nav-badge-wrap{
    display:inline-flex !important;
    align-items:center;
    justify-content:center;
    margin-left:auto !important;
    flex-shrink:0 !important;
    line-height:0;
    transform-origin:center center;
  }
  .org-sales-nav-badge-wrap.is-alert{
    animation: orgSalesBadgeAlert 2.4s ease-in-out infinite;
  }
  @media (prefers-reduced-motion: reduce){
    .org-sales-nav-badge-wrap.is-alert{
      animation: none;
      filter: drop-shadow(0 0 4px rgba(220, 53, 69, 0.65));
    }
  }
  .org-sales-nav-badge,
  body.org-app .sh-sideleft-menu .nav > .nav-item > .nav-link .org-sales-nav-badge,
  body.org-app .sh-sideleft-menu .nav > .nav-item > .sales-management-nav-link .org-sales-nav-badge,
  body.org-app .sh-sideleft-menu .nav > .nav-item > .sales-management-nav-link:hover .org-sales-nav-badge,
  body.org-app .sh-sideleft-menu .nav > .nav-item > .sales-management-nav-link:focus .org-sales-nav-badge,
  body.org-app .sh-sideleft-menu .nav > .nav-item > .sales-management-nav-link.active .org-sales-nav-badge,
  body.org-app .org-sideleft-scroll .nav > .nav-item > .nav-link .org-sales-nav-badge{
    flex-shrink:0 !important;
    display:block !important;
    overflow:visible !important;
    color:inherit !important;
    background:transparent !important;
    border:0 !important;
    box-shadow:none !important;
  }
  .org-sales-nav-badge rect{fill:#dc3545 !important;}
  .org-sales-nav-badge text{fill:#ffffff !important;color:#ffffff !important;}
  .org-sideleft-nav .nav-link{
    display:flex;
    align-items:center;
    gap:8px;
  }
</style>
<div class="sh-sideleft-menu org-sideleft-shell">
  <div class="org-sideleft-top">
    <label class="sh-sidebar-label"><?= h($label) ?></label>
    <ul class="nav org-sideleft-nav">
      <li class="nav-item">
        <a href="feed.php" class="nav-link">
          <i class="icon ion-ios-home-outline"></i>
          <span>Home feed</span>
        </a>
        <!-- <a href="feed.php" class="nav-link"<?= org_layout_nav_attrs('feed.php') ?>>
          <i class="icon ion-ios-home-outline"></i>
          <span>Home feed</span>
        </a> -->
      </li>
    </ul>
  </div>

  <div class="org-sideleft-scroll" role="navigation" aria-label="Organization navigation">
    <?php if ($isSalesManagementPage): ?>
      <label class="sh-sidebar-label org-sales-workflow-label">Sales workflow modules</label>
    <?php endif; ?>
    <div class="<?= $isSalesManagementPage ? 'org-sales-workflow-scroll' : '' ?>">
    <ul class="nav org-sideleft-nav">
      <?php if ($isSalesManagementPage): ?>
        <?php foreach ($salesManagementNav as $item): ?>
          <?php
            $salesNavSlug = (string)($item[1] ?? '');
            $salesNavHref = trim((string)($item[3] ?? ''));
            $salesNavIsExternal = $salesNavHref !== '';
            $salesNavLink = $salesNavIsExternal ? $salesNavHref : ('sales_management.php#' . $salesNavSlug);
            $salesNavCountKey = (string)($item[4] ?? '');
            $salesNavCount = ($salesNavCountKey !== '' && isset($salesAttention[$salesNavCountKey]))
                ? (int)$salesAttention[$salesNavCountKey]
                : 0;
          ?>
          <li class="nav-item">
            <a
              href="<?= h($salesNavLink) ?>"
              class="nav-link sales-management-nav-link<?= $salesNavSlug === 'dashboard' ? ' active' : '' ?>"
              <?php if (!$salesNavIsExternal): ?>data-sales-nav="<?= h($salesNavSlug) ?>"<?php endif; ?>
              <?php if ($salesNavCount > 0): ?>aria-label="<?= h((string)$item[0] . ' — ' . ($salesNavCount > 99 ? '99+' : (string)$salesNavCount) . ' need attention') ?>"<?php endif; ?>
            >
              <i class="icon <?= h((string)$item[2]) ?>"></i>
              <span><?= h((string)$item[0]) ?></span>
              <?= $salesNavBadgeHtml($salesNavCount) ?>
            </a>
          </li>
        <?php endforeach; ?>
      <?php else: ?>
      <?php if ($isManager): ?>
      <li class="nav-item">
        <a href="compose_post.php" class="nav-link"<?= org_layout_nav_attrs('compose_post.php') ?>>
          <i class="icon ion-ios-paperplane"></i>
          <span>New announcement</span>
        </a>
      </li>
      <li class="nav-item">
        <a href="dashboard.php" class="nav-link"<?= org_layout_nav_attrs('dashboard.php') ?>>
          <i class="icon ion-ios-pulse"></i>
          <span>Publisher hub</span>
        </a>
      </li>
      <?php endif; ?>

      <li class="nav-item">
        <a href="posts.php" class="nav-link"<?= org_layout_nav_attrs('posts.php') ?>>
          <i class="icon ion-ios-list"></i>
          <span>Posts</span>
        </a>
      </li>

      <li class="nav-item">
        <!-- <a href="messages.php" class="nav-link"<?= org_layout_nav_attrs('messages.php') ?>>
          <i class="icon ion-chatbubble"></i>
          <span>Messages</span>
        </a> -->
        <a href="messages.php" class="nav-link">
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
        <a href="commerce.php" class="nav-link"<?= org_layout_nav_attrs('commerce.php') ?>>
          <i class="icon ion-bag"></i>
          <span>Shop &amp; commerce</span>
        </a>
      </li>
      <li class="nav-item">
        <a href="sales_management.php" class="nav-link"<?= org_layout_nav_attrs('sales_management.php') ?>
           <?php if ((int)($salesAttention['total'] ?? 0) > 0): ?>aria-label="Sales management — <?= (int)$salesAttention['total'] > 99 ? '99+' : (int)$salesAttention['total'] ?> items need attention"<?php endif; ?>>
          <i class="icon ion-speedometer"></i>
          <span>Sales management</span>
          <?= $salesNavBadgeHtml((int)($salesAttention['total'] ?? 0)) ?>
        </a>
      </li>
      <li class="nav-item">
        <a href="sales_management.php#settings" class="nav-link">
          <i class="icon ion-ios-gear"></i>
          <span>Settings</span>
        </a>
      </li>
      <li class="nav-item">
        <a href="crm.php" class="nav-link"<?= org_layout_nav_attrs('crm.php') ?>>
          <i class="icon ion-ios-people"></i>
          <span>Customers (CRM)</span>
        </a>
      </li>
      <?php endif; ?>

      <li class="nav-item">
        <a href="members.php" class="nav-link"<?= org_layout_nav_attrs('members.php') ?>>
          <i class="icon ion-person-stalker"></i>
          <span>Team</span>
        </a>
      </li>

      <?php if ($isManager): ?>
      <li class="nav-item">
        <a href="create_staff.php" class="nav-link"<?= org_layout_nav_attrs('create_staff.php') ?>>
          <i class="icon ion-person-add"></i>
          <span>Add staff</span>
        </a>
      </li>
      <li class="nav-item">
        <a href="create_org.php" class="nav-link"<?= org_layout_nav_attrs('create_org.php') ?>>
          <i class="icon ion-ios-plus-outline"></i>
          <span>New organization</span>
        </a>
      </li>
      <?php endif; ?>
      <?php endif; ?>
    </ul>
    </div>
  </div>

  <div class="org-sideleft-bottom">
    <ul class="nav org-sideleft-nav">
      <li class="nav-item">
        <a href="logout.php" class="nav-link org-sideleft-signout">
          <i class="icon ion-power"></i>
          <span>Sign out</span>
        </a>
      </li>
    </ul>
  </div>
</div>
