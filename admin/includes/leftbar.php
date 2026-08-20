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
];
try {
    $navCounts = admin_nav_attention_counts($dbh);
} catch (Throwable $e) {
    // keep zeros
}

$pendingPublisherRequests = (int)($navCounts['publisher_requests'] ?? 0);
$pendingReports = (int)($navCounts['reports'] ?? 0);
$pendingDisputes = (int)($navCounts['disputes'] ?? 0);
$incompleteConnect = (int)($navCounts['stripe_connect'] ?? 0);
$overdueRent = (int)($navCounts['shop_rent'] ?? 0);
$unassignedBrands = (int)($navCounts['commerce_brands'] ?? 0);
$inboxUnread = (int)($navCounts['inbox'] ?? 0);

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
 * @param array{href:string,page:string,label:string,title?:string,badge?:int} $item
 */
$renderNavLink = static function (array $item, string $currentPage): void {
    $page = (string)$item['page'];
    $href = (string)$item['href'];
    $label = (string)$item['label'];
    $title = (string)($item['title'] ?? $label);
    $badge = (int)($item['badge'] ?? 0);
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
    $cls = admin_layout_nav_class($page, $activePage);
    echo '<a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '" class="' . htmlspecialchars($cls, ENT_QUOTES, 'UTF-8') . '"'
        . ' title="' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '"'
        . ' aria-label="' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '">';
    echo '<span>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
    if ($badge > 0) {
        echo ' (' . (int)$badge . ')';
    }
    echo '</span>';
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
    'roleslist.php', 'user_form.php',
];
$publisherPages = ['publisher_requests.php'];
$commercePages = [
    'Orders.php', 'inventory.php', 'transactions.php', 'commerce/transactions.php', 'dispute.php', 'disputes.php', 'dispute_detail.php', 'orglist.php', 'service_fees.php', 'customer_memberships.php',
    'org_stripe_connect.php', 'org_rent.php', 'org_commerce_brands.php',
    'managerlist.php', 'stafflist.php',
];

$publicHasActive = $navGroupOpen($publicPages, $currentPage);
$activityHasActive = $navGroupOpen($activityPages, $currentPage);
$adminHasActive = $navGroupOpen($adminPages, $currentPage);
$publisherHasActive = $navGroupOpen($publisherPages, $currentPage);
$commerceHasActive = $navGroupOpen($commercePages, $currentPage);

// Open only the workspace that owns the current page (so Commerce stays expanded on Orders, etc.).
$publicOpen = $publicHasActive;
$activityOpen = $activityHasActive;
$adminNavOpen = $adminHasActive;
$publisherOpen = $publisherHasActive;
$commerceOpen = $commerceHasActive;

$publicBadge = $pendingReports;
$publisherBadge = $pendingPublisherRequests;
$commerceBadge = $pendingDisputes + $incompleteConnect + $overdueRent + $unassignedBrands;
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
            <li><?php $renderNavLink(['href' => 'posts.php', 'page' => 'posts.php', 'label' => 'Posts'], $currentPage); ?></li>
            <li><?php $renderNavLink(['href' => 'audience.php', 'page' => 'audience.php', 'label' => 'Audience'], $currentPage); ?></li>
          </ul>
        </li>

        <li class="nav-item admin-nav-group<?= $activityOpen ? ' is-open' : '' ?><?= $activityHasActive ? ' has-active' : '' ?>" data-portal="activity">
          <button type="button" class="nav-link admin-nav-group-toggle<?= $activityOpen ? ' is-active' : '' ?>" aria-expanded="<?= $activityOpen ? 'true' : 'false' ?>">
            <i class="icon ion-ios-pulse"></i>
            <span>Activity</span>
            <i class="fa fa-chevron-down admin-nav-chevron" aria-hidden="true"></i>
          </button>
          <ul class="admin-nav-sub">
            <li><?php $renderNavLink(['href' => 'user_activity_table.php', 'page' => 'user_activity_table.php', 'label' => 'User Activity'], $currentPage); ?></li>
            <li><?php $renderNavLink(['href' => 'login_activity.php', 'page' => 'login_activity.php', 'label' => 'Login Activity'], $currentPage); ?></li>
            <li><?php $renderNavLink(['href' => 'device_activity.php', 'page' => 'device_activity.php', 'label' => 'Device Activity'], $currentPage); ?></li>
          </ul>
        </li>

        <li class="nav-item admin-nav-group<?= $adminNavOpen ? ' is-open' : '' ?><?= $adminHasActive ? ' has-active' : '' ?>" data-portal="admin">
          <button type="button" class="nav-link admin-nav-group-toggle<?= $adminNavOpen ? ' is-active' : '' ?>" aria-expanded="<?= $adminNavOpen ? 'true' : 'false' ?>">
            <i class="icon ion-ios-locked"></i>
            <span>Admin</span>
            <i class="fa fa-chevron-down admin-nav-chevron" aria-hidden="true"></i>
          </button>
          <ul class="admin-nav-sub">
            <li><?php $renderNavLink(['href' => 'userlist.php', 'page' => 'userlist.php', 'label' => 'Users'], $currentPage); ?></li>
            <li><?php $renderNavLink(['href' => 'account_search.php', 'page' => 'account_search.php', 'label' => 'Account Search'], $currentPage); ?></li>
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
            <li><?php $renderNavLink(['href' => 'Orders.php', 'page' => 'Orders.php', 'label' => 'Orders', 'title' => 'Marketplace Orders'], $currentPage); ?></li>
            <li><?php $renderNavLink(['href' => 'inventory.php', 'page' => 'inventory.php', 'label' => 'Inventory', 'title' => 'Marketplace Inventory'], $currentPage); ?></li>
            <li><?php $renderNavLink(['href' => 'transactions.php', 'page' => 'transactions.php', 'label' => 'Transactions', 'title' => 'Marketplace Transactions'], $currentPage); ?></li>
            <li><?php $renderNavLink(['href' => 'disputes.php?filter=open', 'page' => 'dispute.php', 'label' => 'Disputes', 'title' => 'Disputes' . ($pendingDisputes > 0 ? " ($pendingDisputes unread)" : ''), 'badge' => $pendingDisputes], $currentPage); ?></li>
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
          <a href="feedback.php?view=internal&filter=unread" class="<?php echo admin_layout_nav_class('feedback.php', $currentPage); ?>" title="<?= htmlspecialchars('Help' . ($inboxUnread > 0 ? " ($inboxUnread unread)" : ''), ENT_QUOTES, 'UTF-8') ?>" aria-label="<?= htmlspecialchars('Help' . ($inboxUnread > 0 ? " ($inboxUnread unread)" : ''), ENT_QUOTES, 'UTF-8') ?>">
            <i class="fa fa-question-circle" aria-hidden="true"></i>
            <span>Help<?php if ($inboxUnread > 0): ?> (<?= $inboxUnread ?>)<?php endif; ?></span>
            <?= admin_nav_badge_html($inboxUnread) ?>
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
      closeGroup(group);
    });
  }

  function revealGroup(group) {
    if (!group) return;
    var sub = group.querySelector('.admin-nav-sub');
    if (sub) sub.scrollTop = 0;
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
      var willOpen = !group.classList.contains('is-open');
      closeAllGroups(group);
      group.classList.toggle('is-open', willOpen);
      btn.classList.toggle('is-active', willOpen);
      btn.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
      if (willOpen) {
        window.requestAnimationFrame(function () { revealGroup(group); });
      }
    });
  });

  var activeGroup = document.querySelector('.admin-nav-group.is-open, .admin-nav-group.has-active');
  if (activeGroup) {
    if (!activeGroup.classList.contains('is-open')) {
      closeAllGroups(activeGroup);
      activeGroup.classList.add('is-open');
      var activeToggle = activeGroup.querySelector('.admin-nav-group-toggle');
      if (activeToggle) {
        activeToggle.classList.add('is-active');
        activeToggle.setAttribute('aria-expanded', 'true');
      }
    }
    window.requestAnimationFrame(function () { revealGroup(activeGroup); });
  }
})();
</script>
<?php admin_layout_footer_assets(); ?>
