<?php
// /admin/includes/leftbar.php
declare(strict_types=1);

require_once __DIR__ . '/session_admin.php';
requireAdminLogin();

require_once __DIR__ . '/role_helpers.php';
require_once __DIR__ . '/admin_layout.php';
require_once __DIR__ . '/admin_portal.php';

admin_layout_head_assets();

$dbh = adminDbh();
$rawRoleId = (int)($_SESSION['userRole'] ?? 0);
$currentPage = admin_layout_current_page();
$adminPortal = admin_portal_current();
$leftbarAdminId = (int)($_SESSION['admin_id'] ?? 0);
$leftbarLinkedPortals = [];
if ($leftbarAdminId > 0) {
    try {
        require_once __DIR__ . '/admin_linked_portal_load.php';
        $leftbarLinkedPortals = admin_linked_portal_summary($dbh, $leftbarAdminId);
    } catch (Throwable $e) {
        $leftbarLinkedPortals = [];
    }
}

$base = baseRoleName($dbh, $rawRoleId);
if ($base === '') {
    $base = 'unknown';
}

function roleIs(string $base, string $expected): bool
{
    return strtolower($base) === strtolower($expected);
}
function roleIn(string $base, array $list): bool
{
    $base = strtolower($base);
    $list = array_map(fn($x) => strtolower(trim((string)$x)), $list);
    return in_array($base, $list, true);
}

$navCounts = [
    'publisher_requests' => 0,
    'shop_rent' => 0,
    'commerce_brands' => 0,
    'stripe_connect' => 0,
    'reports' => 0,
    'inbox' => 0,
    'disputes' => 0,
    'product_disputes' => 0,
    'support_customer' => 0,
    'support_seller' => 0,
    'support_publisher' => 0,
    'support_personal' => 0,
    'support_internal' => 0,
    'notifications' => 0,
    'notifications_security' => 0,
    'orders_pending' => 0,
    'products_new' => 0,
    'public_total' => 0,
    'activity_total' => 0,
    'admin_total' => 0,
    'publisher_total' => 0,
    'commerce_total' => 0,
    'help_total' => 0,
    'total' => 0,
];
try {
    $navCounts = admin_nav_attention_counts($dbh);
} catch (Throwable $e) {
    // keep zeros
}

$pendingPublisherRequests = (int)($navCounts['publisher_requests'] ?? 0);
$pendingReports = (int)($navCounts['reports'] ?? 0);
$pendingDisputes = (int)($navCounts['disputes'] ?? 0);
$pendingProductDisputes = (int)($navCounts['product_disputes'] ?? 0);
$incompleteConnect = (int)($navCounts['stripe_connect'] ?? 0);
$overdueRent = (int)($navCounts['shop_rent'] ?? 0);
$unassignedBrands = (int)($navCounts['commerce_brands'] ?? 0);
$inboxUnread = (int)($navCounts['inbox'] ?? 0);
$supportCustomer = (int)($navCounts['support_customer'] ?? 0);
$supportSeller = (int)($navCounts['support_seller'] ?? 0);
$supportPublisher = (int)($navCounts['support_publisher'] ?? 0);
$supportPersonal = (int)($navCounts['support_personal'] ?? 0);
$supportInternal = (int)($navCounts['support_internal'] ?? 0);
$adminNotifications = (int)($navCounts['notifications'] ?? 0);
$securityNotifications = (int)($navCounts['notifications_security'] ?? 0);
$ordersPending = (int)($navCounts['orders_pending'] ?? 0);
$productsNew = (int)($navCounts['products_new'] ?? 0);
$helpUnread = (int)($navCounts['help_total'] ?? $inboxUnread);

/**
 * @param list<string> $pages
 */
$navGroupOpen = static function (array $pages, string $currentPage): bool {
    $aliases = [
        'post_profile.php' => 'posts.php',
        'report_detail.php' => 'reports.php',
        'publisher_request_detail.php' => 'publisher_requests.php',
        'user_activity.php' => 'user_activity_table.php',
        'user_form.php' => 'userlist.php',
    ];
    $check = $aliases[$currentPage] ?? $currentPage;
    return in_array($check, $pages, true);
};

/**
 * @param array{href:string,page:string,label:string,title?:string,badge?:int,match?:array<string,string>} $item
 */
$renderNavLink = static function (array $item, string $currentPage): void {
    $page = (string)$item['page'];
    $href = (string)$item['href'];
    $label = (string)$item['label'];
    $title = (string)($item['title'] ?? $label);
    $badge = (int)($item['badge'] ?? 0);
    $match = isset($item['match']) && is_array($item['match']) ? $item['match'] : [];
    $activePage = $currentPage;
    if (in_array($currentPage, ['post_profile.php'], true) && $page === 'posts.php') {
        $activePage = 'posts.php';
    }
    if (in_array($currentPage, ['report_detail.php'], true) && $page === 'reports.php') {
        $activePage = 'reports.php';
    }
    if (in_array($currentPage, ['publisher_request_detail.php'], true) && $page === 'publisher_requests.php') {
        $activePage = 'publisher_requests.php';
    }
    if (in_array($currentPage, ['user_activity.php'], true) && $page === 'user_activity_table.php') {
        $activePage = 'user_activity_table.php';
    }
    if (in_array($currentPage, ['user_form.php'], true) && $page === 'userlist.php') {
        $activePage = 'userlist.php';
    }
    if (in_array($currentPage, ['commerce/transactions.php'], true) && $page === 'transactions.php') {
        $activePage = 'transactions.php';
    }
    if (in_array($currentPage, ['disputes.php', 'dispute.php', 'dispute_detail.php'], true) && $page === 'commerce_disputes.php') {
        $activePage = 'commerce_disputes.php';
    }
    $isActive = ($page === $activePage);
    if ($isActive && $match !== []) {
        foreach ($match as $key => $want) {
            $got = strtolower(trim((string)($_GET[(string)$key] ?? '')));
            if ($got !== strtolower(trim((string)$want))) {
                $isActive = false;
                break;
            }
        }
    } elseif ($page === 'feedback.php' && $currentPage === 'feedback.php' && $match === []) {
        // Unscoped feedback link: only active when no lane matchers are expected.
        $isActive = true;
    }
    $cls = $isActive ? 'nav-link active' : 'nav-link';
    $badgeAttr = $badge > 0 ? (' data-admin-badge="' . (int)$badge . '"') : '';
    echo '<a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '" class="' . htmlspecialchars($cls, ENT_QUOTES, 'UTF-8') . '"'
        . ' title="' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '"'
        . ' aria-label="' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '"'
        . ($isActive ? ' aria-current="page"' : '')
        . $badgeAttr . '>';
    echo '<span>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>';
    echo admin_nav_badge_html($badge);
    echo '</a>';
};

$publicPages = [
    'overview.php', 'reports.php', 'posts.php', 'audience.php',
];
$activityPages = [
    'user_activity_table.php', 'login_activity.php', 'device_activity.php',
    'user_activity.php',
];
$adminPages = [
    'userlist.php', 'account_search.php', 'trends.php', 'adminroles.php',
    'roleslist.php', 'user_form.php', 'notification.php',
];
$publisherPages = ['publisher_requests.php'];
$commercePages = [
    'Orders.php', 'inventory.php', 'transactions.php', 'commerce/transactions.php',
    'dispute.php', 'disputes.php', 'dispute_detail.php', 'commerce_disputes.php',
    'orglist.php', 'service_fees.php', 'customer_memberships.php',
    'org_stripe_connect.php', 'org_rent.php', 'org_commerce_brands.php',
    'managerlist.php', 'stafflist.php',
];

$publicHasActive = $navGroupOpen($publicPages, $currentPage)
    || ($currentPage === 'feedback.php' && in_array(strtolower(trim((string)($_GET['lane'] ?? ''))), ['customer', 'personal'], true));
$activityHasActive = $navGroupOpen($activityPages, $currentPage);
$adminHasActive = $navGroupOpen($adminPages, $currentPage);
$publisherHasActive = $navGroupOpen($publisherPages, $currentPage)
    || ($currentPage === 'feedback.php' && strtolower(trim((string)($_GET['lane'] ?? ''))) === 'publisher');
$commerceHasActive = $navGroupOpen($commercePages, $currentPage);

// Open only the workspace that owns the current page (so Commerce stays expanded on Orders, etc.).
$publicOpen = $publicHasActive;
$activityOpen = $activityHasActive;
$adminNavOpen = $adminHasActive;
$publisherOpen = $publisherHasActive;
$commerceOpen = $commerceHasActive;

$publicBadge = (int)($navCounts['public_total'] ?? ($pendingReports + $supportCustomer + $supportPersonal));
$activityBadge = (int)($navCounts['activity_total'] ?? $securityNotifications);
$adminBadge = (int)($navCounts['admin_total'] ?? $adminNotifications);
$publisherBadge = (int)($navCounts['publisher_total'] ?? ($pendingPublisherRequests + $supportPublisher));
$commerceBadge = (int)($navCounts['commerce_total'] ?? (
    $pendingDisputes + $pendingProductDisputes + $supportSeller + $incompleteConnect + $overdueRent
    + $unassignedBrands + $ordersPending + $productsNew
));
$disputesNavBadge = $pendingDisputes + $pendingProductDisputes;
$ordersNavBadge = $ordersPending;
$productsNavBadge = $productsNew;
?>
<style id="admin-nav-badge-critical">
  .sh-sideleft-menu .admin-nav-badge,
  .sh-sideleft-menu b.admin-nav-badge{
    min-width:18px!important;height:18px!important;padding:0 5px!important;
    border-radius:999px!important;background:#ef4444!important;color:#fff!important;
    font-size:10px!important;font-weight:800!important;line-height:18px!important;
    display:inline-flex!important;align-items:center!important;justify-content:center!important;
    border:0!important;box-shadow:none!important;z-index:2!important
  }
  .sh-sideleft-menu .admin-nav-help-link{
    position:relative!important;
  }
  .sh-sideleft-menu .admin-nav-help-link .admin-nav-badge,
  .sh-sideleft-menu .admin-nav-help-link b.admin-nav-badge{
    position:absolute!important;
    top:2px!important;
    left:22px!important;
    right:auto!important;
    margin:0!important;
    min-width:16px!important;
    height:16px!important;
    padding:0 4px!important;
    line-height:16px!important;
    font-size:9px!important;
    box-shadow:0 0 0 2px var(--admin-nav-bg,#0f172a)!important;
  }
</style>
    <div class="sh-sideleft-menu admin-side-nav" data-nav-counts="<?= htmlspecialchars(json_encode($navCounts), ENT_QUOTES, 'UTF-8') ?>">
      <div class="admin-nav-scroll">
      <ul class="nav">
        <li class="admin-nav-section" aria-hidden="true"><span>Overview</span></li>
        <li class="nav-item">
          <a href="dashboard.php" class="<?php echo admin_layout_nav_class('dashboard.php', $currentPage); ?>" title="Dashboard" aria-label="Dashboard">
            <i class="icon ion-ios-home-outline"></i>
            <span>Dashboard</span>
          </a>
        </li>
        <?php if (roleIs($base, 'admin')): ?>
        <li class="nav-item">
          <a href="overview.php" class="<?php echo admin_layout_nav_class('overview.php', $currentPage); ?>" title="Overview" aria-label="Overview">
            <i class="icon ion-ios-analytics"></i>
            <span>Overview</span>
          </a>
        </li>

        <li class="admin-nav-section" aria-hidden="true"><span>Workspaces</span></li>

        <li class="nav-item admin-nav-group<?= $publicOpen ? ' is-open' : '' ?><?= $publicHasActive ? ' has-active' : '' ?>" data-portal="public_user">
          <button type="button" class="nav-link admin-nav-group-toggle<?= $publicOpen ? ' is-active' : '' ?>" aria-expanded="<?= $publicOpen ? 'true' : 'false' ?>">
            <i class="icon ion-ios-people"></i>
            <span>Public_user</span>
            <?= admin_nav_badge_html($publicBadge) ?>
            <i class="fa fa-chevron-down admin-nav-chevron" aria-hidden="true"></i>
          </button>
          <ul class="admin-nav-sub">
            <li><?php $renderNavLink(['href' => 'reports.php?status=pending', 'page' => 'reports.php', 'label' => 'Reports', 'title' => 'Reports' . ($pendingReports > 0 ? " ($pendingReports pending)" : ''), 'badge' => $pendingReports], $currentPage); ?></li>
            <li><?php $renderNavLink(['href' => 'feedback.php?view=public&lane=customer&filter=unread', 'page' => 'feedback.php', 'label' => 'Customer Help', 'title' => 'Customer Help' . ($supportCustomer > 0 ? " ($supportCustomer unread)" : ''), 'badge' => $supportCustomer, 'match' => ['lane' => 'customer']], $currentPage); ?></li>
            <li><?php $renderNavLink(['href' => 'feedback.php?view=public&lane=personal&filter=unread', 'page' => 'feedback.php', 'label' => 'Personal Help', 'title' => 'Personal Help' . ($supportPersonal > 0 ? " ($supportPersonal unread)" : ''), 'badge' => $supportPersonal, 'match' => ['lane' => 'personal']], $currentPage); ?></li>
            <li><?php $renderNavLink(['href' => 'posts.php', 'page' => 'posts.php', 'label' => 'Posts'], $currentPage); ?></li>
            <li><?php $renderNavLink(['href' => 'audience.php', 'page' => 'audience.php', 'label' => 'Audience'], $currentPage); ?></li>
          </ul>
        </li>

        <li class="nav-item admin-nav-group<?= $activityOpen ? ' is-open' : '' ?><?= $activityHasActive ? ' has-active' : '' ?>" data-portal="activity">
          <button type="button" class="nav-link admin-nav-group-toggle<?= $activityOpen ? ' is-active' : '' ?>" aria-expanded="<?= $activityOpen ? 'true' : 'false' ?>">
            <i class="icon ion-ios-pulse"></i>
            <span>Activity</span>
            <?= admin_nav_badge_html($activityBadge) ?>
            <i class="fa fa-chevron-down admin-nav-chevron" aria-hidden="true"></i>
          </button>
          <ul class="admin-nav-sub">
            <li><?php $renderNavLink(['href' => 'user_activity_table.php', 'page' => 'user_activity_table.php', 'label' => 'User Activity'], $currentPage); ?></li>
            <li><?php $renderNavLink(['href' => 'login_activity.php', 'page' => 'login_activity.php', 'label' => 'Login Activity', 'title' => 'Login Activity' . ($securityNotifications > 0 ? " ($securityNotifications security alerts)" : ''), 'badge' => $securityNotifications], $currentPage); ?></li>
            <li><?php $renderNavLink(['href' => 'device_activity.php', 'page' => 'device_activity.php', 'label' => 'Device Activity'], $currentPage); ?></li>
          </ul>
        </li>

        <li class="nav-item admin-nav-group<?= $adminNavOpen ? ' is-open' : '' ?><?= $adminHasActive ? ' has-active' : '' ?>" data-portal="admin">
          <button type="button" class="nav-link admin-nav-group-toggle<?= $adminNavOpen ? ' is-active' : '' ?>" aria-expanded="<?= $adminNavOpen ? 'true' : 'false' ?>">
            <i class="icon ion-ios-locked"></i>
            <span>Admin</span>
            <?= admin_nav_badge_html($adminBadge) ?>
            <i class="fa fa-chevron-down admin-nav-chevron" aria-hidden="true"></i>
          </button>
          <ul class="admin-nav-sub">
            <li><?php $renderNavLink(['href' => 'userlist.php', 'page' => 'userlist.php', 'label' => 'Users'], $currentPage); ?></li>
            <li><?php $renderNavLink(['href' => 'account_search.php', 'page' => 'account_search.php', 'label' => 'Account Search'], $currentPage); ?></li>
            <li><?php $renderNavLink(['href' => 'notification.php', 'page' => 'notification.php', 'label' => 'Notifications', 'title' => 'Notifications' . ($adminNotifications > 0 ? " ($adminNotifications unread)" : ''), 'badge' => $adminNotifications], $currentPage); ?></li>
            <li><?php $renderNavLink(['href' => 'trends.php', 'page' => 'trends.php', 'label' => 'Trends'], $currentPage); ?></li>
            <li><?php $renderNavLink(['href' => 'adminroles.php', 'page' => 'adminroles.php', 'label' => 'Roles & Accounts'], $currentPage); ?></li>
            <li><?php $renderNavLink(['href' => 'roleslist.php', 'page' => 'roleslist.php', 'label' => 'Permissions'], $currentPage); ?></li>
          </ul>
        </li>

        <li class="nav-item admin-nav-group<?= $publisherOpen ? ' is-open' : '' ?><?= $publisherHasActive ? ' has-active' : '' ?>" data-portal="publisher">
          <button type="button" class="nav-link admin-nav-group-toggle<?= $publisherOpen ? ' is-active' : '' ?>" aria-expanded="<?= $publisherOpen ? 'true' : 'false' ?>">
            <i class="icon ion-ios-paper"></i>
            <span>Publisher</span>
            <?= admin_nav_badge_html($publisherBadge) ?>
            <i class="fa fa-chevron-down admin-nav-chevron" aria-hidden="true"></i>
          </button>
          <ul class="admin-nav-sub">
            <li><?php $renderNavLink(['href' => 'publisher_requests.php', 'page' => 'publisher_requests.php', 'label' => 'Verification', 'title' => 'Verification Requests' . ($pendingPublisherRequests > 0 ? " ($pendingPublisherRequests)" : ''), 'badge' => $pendingPublisherRequests], $currentPage); ?></li>
            <li><?php $renderNavLink(['href' => 'feedback.php?view=public&lane=publisher&filter=unread', 'page' => 'feedback.php', 'label' => 'Publisher Help', 'title' => 'Publisher Help' . ($supportPublisher > 0 ? " ($supportPublisher unread)" : ''), 'badge' => $supportPublisher, 'match' => ['lane' => 'publisher']], $currentPage); ?></li>
          </ul>
        </li>

        <li class="nav-item admin-nav-group<?= $commerceOpen ? ' is-open' : '' ?><?= $commerceHasActive ? ' has-active' : '' ?>" data-portal="commerce">
          <button type="button" class="nav-link admin-nav-group-toggle<?= $commerceOpen ? ' is-active' : '' ?>" aria-expanded="<?= $commerceOpen ? 'true' : 'false' ?>">
            <i class="icon ion-ios-cart"></i>
            <span>Commerce</span>
            <?= admin_nav_badge_html($commerceBadge) ?>
            <i class="fa fa-chevron-down admin-nav-chevron" aria-hidden="true"></i>
          </button>
          <ul class="admin-nav-sub">
            <li><?php $renderNavLink(['href' => 'Orders.php?status=pending', 'page' => 'Orders.php', 'label' => 'Orders', 'title' => 'Marketplace Orders' . ($ordersNavBadge > 0 ? " ($ordersNavBadge need attention)" : ''), 'badge' => $ordersNavBadge], $currentPage); ?></li>
            <li><?php $renderNavLink(['href' => 'inventory.php', 'page' => 'inventory.php', 'label' => 'Inventory', 'title' => 'Marketplace Inventory' . ($productsNavBadge > 0 ? " ($productsNavBadge new products)" : ''), 'badge' => $productsNavBadge], $currentPage); ?></li>
            <li><?php $renderNavLink(['href' => 'transactions.php', 'page' => 'transactions.php', 'label' => 'Transactions', 'title' => 'Marketplace Transactions'], $currentPage); ?></li>
            <li><?php $renderNavLink(['href' => 'feedback.php?view=public&lane=seller&filter=unread', 'page' => 'feedback.php', 'label' => 'Seller Help', 'title' => 'Seller Help' . ($supportSeller > 0 ? " ($supportSeller unread)" : ''), 'badge' => $supportSeller], $currentPage); ?></li>
            <li><?php $renderNavLink(['href' => 'commerce_disputes.php', 'page' => 'commerce_disputes.php', 'label' => 'Disputes', 'title' => 'Disputes' . ($disputesNavBadge > 0 ? " ($disputesNavBadge open)" : ''), 'badge' => $disputesNavBadge], $currentPage); ?></li>
            <li><?php $renderNavLink(['href' => 'orglist.php', 'page' => 'orglist.php', 'label' => 'Organizations'], $currentPage); ?></li>
            <li><?php $renderNavLink(['href' => 'service_fees.php', 'page' => 'service_fees.php', 'label' => 'Service Fees'], $currentPage); ?></li>
            <li><?php $renderNavLink(['href' => 'customer_memberships.php', 'page' => 'customer_memberships.php', 'label' => 'Memberships'], $currentPage); ?></li>
            <li><?php $renderNavLink(['href' => 'org_stripe_connect.php?filter=incomplete', 'page' => 'org_stripe_connect.php', 'label' => 'Stripe Connect', 'title' => 'Stripe Connect' . ($incompleteConnect > 0 ? " ($incompleteConnect incomplete)" : ''), 'badge' => $incompleteConnect], $currentPage); ?></li>
            <li><?php $renderNavLink(['href' => 'org_rent.php?filter=overdue', 'page' => 'org_rent.php', 'label' => 'Shop Rent', 'title' => 'Shop Rent' . ($overdueRent > 0 ? " ($overdueRent need attention)" : ''), 'badge' => $overdueRent], $currentPage); ?></li>
            <li><?php $renderNavLink(['href' => 'org_commerce_brands.php?filter=unassigned', 'page' => 'org_commerce_brands.php', 'label' => 'Brands', 'title' => 'Commerce Brands' . ($unassignedBrands > 0 ? " ($unassignedBrands unassigned)" : ''), 'badge' => $unassignedBrands], $currentPage); ?></li>
            <li><?php $renderNavLink(['href' => 'managerlist.php', 'page' => 'managerlist.php', 'label' => 'Managers'], $currentPage); ?></li>
            <li><?php $renderNavLink(['href' => 'stafflist.php', 'page' => 'stafflist.php', 'label' => 'Org Staff'], $currentPage); ?></li>
          </ul>
        </li>
        <?php endif; ?>
      </ul>
      </div>

      <div class="admin-nav-sticky-foot">
      <ul class="nav">
        <?php if (roleIs($base, 'admin')): ?>
        <li class="admin-nav-section" aria-hidden="true"><span>Settings</span></li>
        <li class="nav-item">
          <a href="settings.php" class="<?php echo admin_layout_nav_class('settings.php', $currentPage); ?>" title="Settings" aria-label="Settings">
            <i class="fa fa-cog"></i>
            <span>Settings</span>
          </a>
        </li>
        <?php endif; ?>

        <?php if (roleIn($base, ['admin', 'manager', 'staff'])): ?>
        <li class="nav-item">
          <?php
            $helpTitle = 'Help' . ($helpUnread > 0
              ? (' (' . $helpUnread . ' unread: customer, seller, publisher, personal, disputes, staff)')
              : '');
          ?>
          <a href="feedback.php?view=public&filter=unread"
             class="admin-nav-help-link <?php echo admin_layout_nav_class('feedback.php', $currentPage); ?>"
             title="<?= htmlspecialchars($helpTitle, ENT_QUOTES, 'UTF-8') ?>"
             aria-label="<?= htmlspecialchars($helpTitle, ENT_QUOTES, 'UTF-8') ?>"
             data-admin-help-badge
             <?php if ($helpUnread > 0): ?>data-admin-badge="<?= (int)$helpUnread ?>"<?php endif; ?>>
            <i class="fa fa-question-circle" aria-hidden="true"></i>
            <span>Help</span>
            <?= admin_nav_badge_html($helpUnread) ?>
          </a>
        </li>
        <?php endif; ?>
        <li class="nav-item admin-nav-footer">
          <a href="logout.php" class="nav-link" title="Signout" aria-label="Signout">
            <i class="icon ion-power"></i>
            <span>Signout</span>
          </a>
        </li>
      </ul>
      </div>
    </div>
<script>
(function () {
  var scrollRoot = document.querySelector('.admin-side-nav .admin-nav-scroll');
  var STICKY_PORTALS = { public_user: true, publisher: true, activity: true, admin: true, commerce: true };
  var STICKY_KEY = 'adminNavStickyOpen';
  var SUB_SCROLL_KEY = 'adminNavSubScroll';

  function getStickyState() {
    try {
      var raw = localStorage.getItem(STICKY_KEY);
      return raw ? (JSON.parse(raw) || {}) : {};
    } catch (e) {
      return {};
    }
  }

  function setStickyPortal(portal, open) {
    if (!portal || !STICKY_PORTALS[portal]) return;
    try {
      var state = getStickyState();
      state[portal] = !!open;
      localStorage.setItem(STICKY_KEY, JSON.stringify(state));
    } catch (e) {}
  }

  function isStickyKeptOpen(portal) {
    if (!portal || !STICKY_PORTALS[portal]) return false;
    return getStickyState()[portal] === true;
  }

  function getSubScrollState() {
    try {
      var raw = sessionStorage.getItem(SUB_SCROLL_KEY);
      return raw ? (JSON.parse(raw) || {}) : {};
    } catch (e) {
      return {};
    }
  }

  function setSubScroll(portal, top) {
    if (!portal) return;
    try {
      var state = getSubScrollState();
      state[portal] = Math.max(0, parseInt(top, 10) || 0);
      sessionStorage.setItem(SUB_SCROLL_KEY, JSON.stringify(state));
    } catch (e) {}
  }

  function restoreSubScroll(group) {
    if (!group) return;
    var portal = group.getAttribute('data-portal') || '';
    var sub = group.querySelector('.admin-nav-sub');
    if (!sub || !portal) return;
    var top = getSubScrollState()[portal];
    if (typeof top !== 'number') return;
    sub.scrollTop = top;
  }

  function openGroup(group) {
    if (!group) return;
    group.classList.add('is-open');
    var toggle = group.querySelector('.admin-nav-group-toggle');
    if (!toggle) return;
    toggle.classList.add('is-active');
    toggle.setAttribute('aria-expanded', 'true');
  }

  function closeGroup(group) {
    if (!group) return;
    group.classList.remove('is-open');
    var toggle = group.querySelector('.admin-nav-group-toggle');
    if (!toggle) return;
    toggle.classList.remove('is-active');
    toggle.setAttribute('aria-expanded', 'false');
  }

  function closeAllGroups(except) {
    document.querySelectorAll('.admin-nav-group.is-open').forEach(function (group) {
      if (except && group === except) return;
      var portal = group.getAttribute('data-portal') || '';
      // Sticky dropdowns stay open until the user closes them.
      if (isStickyKeptOpen(portal)) return;
      closeGroup(group);
    });
  }

  function revealGroup(group, resetSubScroll) {
    if (!group) return;
    var sub = group.querySelector('.admin-nav-sub');
    if (sub && resetSubScroll) {
      sub.scrollTop = 0;
      var portal = group.getAttribute('data-portal') || '';
      if (portal) setSubScroll(portal, 0);
    } else {
      restoreSubScroll(group);
    }
    if (!scrollRoot) return;
    try {
      var groupTop = group.offsetTop;
      var groupBottom = groupTop + group.offsetHeight;
      var viewTop = scrollRoot.scrollTop;
      var viewBottom = viewTop + scrollRoot.clientHeight;
      if (groupTop < viewTop + 8) {
        scrollRoot.scrollTop = Math.max(0, groupTop - 8);
      } else if (groupBottom > viewBottom - 8) {
        scrollRoot.scrollTop = Math.max(0, groupBottom - scrollRoot.clientHeight + 8);
      }
    } catch (e) {}
  }

  document.querySelectorAll('.admin-nav-group-toggle').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var group = btn.closest('.admin-nav-group');
      if (!group) return;
      var portal = group.getAttribute('data-portal') || '';
      var willOpen = !group.classList.contains('is-open');
      closeAllGroups(group);
      group.classList.toggle('is-open', willOpen);
      btn.classList.toggle('is-active', willOpen);
      btn.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
      if (STICKY_PORTALS[portal]) setStickyPortal(portal, willOpen);
      if (willOpen) {
        window.requestAnimationFrame(function () { revealGroup(group, true); });
      }
    });
  });

  // Clicking a dropdown row keeps that workspace open and remembers list scroll.
  document.querySelectorAll('.admin-nav-group .admin-nav-sub .nav-link').forEach(function (link) {
    link.addEventListener('click', function () {
      var group = link.closest('.admin-nav-group');
      if (!group) return;
      var portal = group.getAttribute('data-portal') || '';
      var sub = group.querySelector('.admin-nav-sub');
      if (portal) setStickyPortal(portal, true);
      if (portal && sub) setSubScroll(portal, sub.scrollTop);
      openGroup(group);
    });
  });

  // Persist scroll while the user moves the dropdown list up/down.
  document.querySelectorAll('.admin-nav-group .admin-nav-sub').forEach(function (sub) {
    var group = sub.closest('.admin-nav-group');
    var portal = group ? (group.getAttribute('data-portal') || '') : '';
    if (!portal) return;
    sub.addEventListener('scroll', function () {
      setSubScroll(portal, sub.scrollTop);
    }, { passive: true });
  });

  // Remember sticky portals that PHP already opened for the current page.
  document.querySelectorAll('.admin-nav-group.is-open').forEach(function (group) {
    var portal = group.getAttribute('data-portal') || '';
    if (STICKY_PORTALS[portal]) setStickyPortal(portal, true);
  });

  // Restore dropdowns the user left open.
  Object.keys(STICKY_PORTALS).forEach(function (portal) {
    if (!isStickyKeptOpen(portal)) return;
    var group = document.querySelector('.admin-nav-group[data-portal="' + portal + '"]');
    if (!group) return;
    openGroup(group);
  });

  var activeGroup = document.querySelector('.admin-nav-group.has-active') || document.querySelector('.admin-nav-group.is-open');
  if (activeGroup) {
    if (!activeGroup.classList.contains('is-open')) {
      closeAllGroups(activeGroup);
      openGroup(activeGroup);
      var activePortal = activeGroup.getAttribute('data-portal') || '';
      if (STICKY_PORTALS[activePortal]) setStickyPortal(activePortal, true);
    }
    window.requestAnimationFrame(function () { revealGroup(activeGroup, false); });
  }

  // Restore saved list scroll for every open dropdown (no jump-to-top after click).
  window.requestAnimationFrame(function () {
    document.querySelectorAll('.admin-nav-group.is-open').forEach(function (group) {
      restoreSubScroll(group);
    });
  });

  // Live-refresh Help + workspace attention badges so admins do not miss new messages.
  (function pollAdminNavBadges() {
    var navRoot = document.querySelector('.admin-side-nav[data-nav-counts]');
    if (!navRoot) return;

    function labelFor(n) {
      n = Math.max(0, parseInt(n, 10) || 0);
      if (n <= 0) return '';
      return n > 99 ? '99+' : String(n);
    }

    function setBadgeOn(el, count) {
      if (!el) return;
      count = Math.max(0, parseInt(count, 10) || 0);
      var badge = el.querySelector('.admin-nav-badge');
      if (count > 0) {
        el.setAttribute('data-admin-badge', String(count));
        if (!badge) {
          badge = document.createElement('b');
          badge.className = 'admin-nav-badge';
          badge.setAttribute('aria-hidden', 'true');
          el.appendChild(badge);
        }
        badge.textContent = labelFor(count);
        badge.hidden = false;
      } else {
        el.removeAttribute('data-admin-badge');
        if (badge) badge.remove();
      }
    }

    function applyCounts(c) {
      if (!c || typeof c !== 'object') return;
      navRoot.setAttribute('data-nav-counts', JSON.stringify(c));

      var help = Math.max(0, parseInt(c.help_total, 10) || 0);
      var helpLink = navRoot.querySelector('[data-admin-help-badge]');
      setBadgeOn(helpLink, help);
      if (helpLink) {
        var title = help > 0
          ? ('Help (' + help + ' unread: customer, seller, publisher, personal, disputes, staff)')
          : 'Help';
        helpLink.setAttribute('title', title);
        helpLink.setAttribute('aria-label', title);
      }

      var map = {
        public_user: Math.max(0, parseInt(c.public_total, 10) || 0),
        activity: Math.max(0, parseInt(c.activity_total, 10) || 0),
        admin: Math.max(0, parseInt(c.admin_total, 10) || 0),
        publisher: Math.max(0, parseInt(c.publisher_total, 10) || 0),
        commerce: Math.max(0, parseInt(c.commerce_total, 10) || 0)
      };
      Object.keys(map).forEach(function (portal) {
        var group = navRoot.querySelector('.admin-nav-group[data-portal="' + portal + '"]');
        if (!group) return;
        setBadgeOn(group.querySelector('.admin-nav-group-toggle'), map[portal]);
      });

      var mailboxBtn = document.querySelector('.azia-head-right a[href="mailbox.php"]');
      if (mailboxBtn) {
        var dot = mailboxBtn.querySelector('.azia-dot');
        if (help > 0) {
          if (!dot) {
            dot = document.createElement('span');
            dot.className = 'azia-dot';
            mailboxBtn.appendChild(dot);
          }
        } else if (dot) {
          dot.remove();
        }
      }
    }

    function tick() {
      fetch('ajax/nav_attention_poll.php', {
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json' },
        cache: 'no-store'
      }).then(function (r) { return r.json(); }).then(function (data) {
        if (data && data.ok && data.counts) applyCounts(data.counts);
      }).catch(function () {});
    }

    tick();
    setInterval(tick, 20000);
    document.addEventListener('visibilitychange', function () {
      if (!document.hidden) tick();
    });
  })();
})();
</script>
<?php admin_layout_footer_assets(); ?>
