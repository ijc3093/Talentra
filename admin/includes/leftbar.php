<?php
// /Business_only3/admin/includes/leftbar.php
declare(strict_types=1);

require_once __DIR__ . '/session_admin.php';
requireAdminLogin();

require_once __DIR__ . '/role_helpers.php';
require_once __DIR__ . '/admin_layout.php';

admin_layout_head_assets();

$dbh = adminDbh();
$rawRoleId = (int)($_SESSION['userRole'] ?? 0);
$currentPage = admin_layout_current_page();
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

$base = baseRoleName($dbh, $rawRoleId);   // coach -> manager
if ($base === '') $base = 'unknown';

function roleIs(string $base, string $expected): bool {
    return strtolower($base) === strtolower($expected);
}
function roleIn(string $base, array $list): bool {
    $base = strtolower($base);
    $list = array_map(fn($x) => strtolower(trim((string)$x)), $list);
    return in_array($base, $list, true);
}

$navCounts = [
    'publisher_requests' => 0,
    'shop_rent' => 0,
    'commerce_brands' => 0,
    'inbox' => 0,
];
try {
    $navCounts = admin_nav_attention_counts($dbh);
} catch (Throwable $e) {
    // keep zeros
}
?>
<style id="admin-nav-badge-critical">
  .sh-sideleft-menu,
  .sh-sideleft-menu .nav,
  .sh-sideleft-menu .nav > .nav-item,
  .sh-sideleft-menu .nav > .nav-item > .nav-link{overflow:visible!important}
  .sh-sideleft-menu .nav > .nav-item > .nav-link{position:relative!important}
  /* Match public header/friend-request badge look */
  .sh-sideleft-menu .nav > .nav-item > .nav-link .admin-nav-badge,
  .sh-sideleft-menu .nav > .nav-item > .nav-link b.admin-nav-badge{
    position:absolute!important;
    top:-2px!important;
    right:2px!important;
    min-width:18px!important;
    height:18px!important;
    padding:0 5px!important;
    border-radius:999px!important;
    background:#ef4444!important;
    color:#0f172a!important;
    font-size:11px!important;
    font-weight:800!important;
    font-style:normal!important;
    line-height:18px!important;
    letter-spacing:0!important;
    display:inline-flex!important;
    align-items:center!important;
    justify-content:center!important;
    border:2px solid #fff!important;
    box-shadow:0 2px 6px rgba(15,23,42,.18)!important;
    z-index:30!important;
    opacity:1!important;
    visibility:visible!important
  }
</style>
    <div class="sh-sideleft-menu" data-nav-counts="<?= htmlspecialchars(json_encode($navCounts), ENT_QUOTES, 'UTF-8') ?>">
      <ul class="nav">
        <li class="nav-item">
          <a href="dashboard.php" class="<?php echo admin_layout_nav_class('dashboard.php', $currentPage); ?>" title="Home" aria-label="Home"<?php echo admin_layout_nav_attrs('dashboard.php'); ?>>
            <i class="icon ion-ios-home-outline"></i>
            <span>Home</span>
          </a>
        </li>
        <?php if (roleIs($base,'admin')): ?>
        <li class="nav-item">
          <a href="adminroles.php" class="<?php echo admin_layout_nav_class('adminroles.php', $currentPage); ?>" title="List Roles &amp; Accounts" aria-label="List Roles &amp; Accounts"<?php echo admin_layout_nav_attrs('adminroles.php'); ?>>
            <i class="icon ion-person"></i>
            <span>List Roles & Accounts</span>
          </a>
          <a href="userlist.php" class="<?php echo admin_layout_nav_class('userlist.php', $currentPage); ?>" title="User List" aria-label="User List"<?php echo admin_layout_nav_attrs('userlist.php'); ?>>
            <i class="icon ion-person"></i>
            <span>User List</span>
          </a>
          <?php
            $pendingPublisherRequests = (int)($navCounts['publisher_requests'] ?? 0);
            $publisherTitle = 'Publisher Requests' . ($pendingPublisherRequests > 0 ? ' (' . $pendingPublisherRequests . ')' : '');
          ?>
          <a href="publisher_requests.php" class="<?php echo admin_layout_nav_class('publisher_requests.php', $currentPage); ?>" title="<?= htmlspecialchars($publisherTitle, ENT_QUOTES, 'UTF-8') ?>" aria-label="<?= htmlspecialchars($publisherTitle, ENT_QUOTES, 'UTF-8') ?>"<?php echo admin_layout_nav_attrs('publisher_requests.php'); ?>>
            <i class="icon ion-ios-paper"></i>
            <?= admin_nav_badge_html($pendingPublisherRequests) ?>
            <span>Publisher Requests<?php if ($pendingPublisherRequests > 0): ?> (<?= $pendingPublisherRequests ?>)<?php endif; ?></span>
          </a>
          <a href="orglist.php" class="<?php echo admin_layout_nav_class('orglist.php', $currentPage); ?>" title="Organizations" aria-label="Organizations"<?php echo admin_layout_nav_attrs('orglist.php'); ?>>
            <i class="icon ion-ios-briefcase"></i>
            <span>Organizations</span>
          </a>
          <?php
            $overdueRent = (int)($navCounts['shop_rent'] ?? 0);
            $rentTitle = 'Shop Rent' . ($overdueRent > 0 ? ' (' . $overdueRent . ' need attention)' : '');
          ?>
          <a href="org_rent.php?filter=overdue" class="<?php echo admin_layout_nav_class('org_rent.php', $currentPage); ?>" title="<?= htmlspecialchars($rentTitle, ENT_QUOTES, 'UTF-8') ?>" aria-label="<?= htmlspecialchars($rentTitle, ENT_QUOTES, 'UTF-8') ?>"<?php echo admin_layout_nav_attrs('org_rent.php'); ?>>
            <i class="icon ion-card"></i>
            <?= admin_nav_badge_html($overdueRent) ?>
            <span>Shop Rent<?php if ($overdueRent > 0): ?> (<?= $overdueRent ?>)<?php endif; ?></span>
          </a>
          <?php
            $unassignedBrands = (int)($navCounts['commerce_brands'] ?? 0);
            $brandsTitle = 'Commerce Brands' . ($unassignedBrands > 0 ? ' (' . $unassignedBrands . ' unassigned)' : '');
          ?>
          <a href="org_commerce_brands.php?filter=unassigned" class="<?php echo admin_layout_nav_class('org_commerce_brands.php', $currentPage); ?>" title="<?= htmlspecialchars($brandsTitle, ENT_QUOTES, 'UTF-8') ?>" aria-label="<?= htmlspecialchars($brandsTitle, ENT_QUOTES, 'UTF-8') ?>"<?php echo admin_layout_nav_attrs('org_commerce_brands.php'); ?>>
            <i class="icon ion-ios-cart"></i>
            <?= admin_nav_badge_html($unassignedBrands) ?>
            <span>Commerce Brands<?php if ($unassignedBrands > 0): ?> (<?= $unassignedBrands ?>)<?php endif; ?></span>
          </a>
          <a href="service_fees.php" class="<?php echo admin_layout_nav_class('service_fees.php', $currentPage); ?>" title="Service Fees" aria-label="Service Fees"<?php echo admin_layout_nav_attrs('service_fees.php'); ?>>
            <i class="icon ion-cash"></i>
            <span>Service Fees</span>
          </a>
          <a href="customer_memberships.php" class="<?php echo admin_layout_nav_class('customer_memberships.php', $currentPage); ?>" title="Customer Memberships" aria-label="Customer Memberships"<?php echo admin_layout_nav_attrs('customer_memberships.php'); ?>>
            <i class="icon ion-ribbon-a"></i>
            <span>Memberships</span>
          </a>
          <a href="managerlist.php" class="<?php echo admin_layout_nav_class('managerlist.php', $currentPage); ?>" title="Managers" aria-label="Managers"<?php echo admin_layout_nav_attrs('managerlist.php'); ?>>
            <i class="icon ion-person-stalker"></i>
            <span>Managers</span>
          </a>
          <a href="stafflist.php" class="<?php echo admin_layout_nav_class('stafflist.php', $currentPage); ?>" title="Org Staff" aria-label="Org Staff"<?php echo admin_layout_nav_attrs('stafflist.php'); ?>>
            <i class="icon ion-ios-people"></i>
            <span>Org Staff</span>
          </a>
          <a href="account_search.php" class="<?php echo admin_layout_nav_class('account_search.php', $currentPage); ?>" title="Account Search" aria-label="Account Search"<?php echo admin_layout_nav_attrs('account_search.php'); ?>>
            <i class="icon ion-ios-search"></i>
            <span>Account Search</span>
          </a>
          <a href="security-log.php" class="<?php echo admin_layout_nav_class('security-log.php', $currentPage); ?>" title="Security Logs" aria-label="Security Logs"<?php echo admin_layout_nav_attrs('security-log.php'); ?>>
            <i class="fa fa-cog"></i>
            <span>Security Logs</span>
          </a>
        </li>
        <?php endif; ?>

        <?php if (roleIn($base, ['admin','manager','staff'])): ?>
        <?php
          $inboxUnread = (int)($navCounts['inbox'] ?? 0);
          $inboxTitle = 'Inbox' . ($inboxUnread > 0 ? ' (' . $inboxUnread . ' unread)' : '');
        ?>
        <li class="nav-item">
          <a href="feedback.php?view=internal&filter=unread" class="<?php echo admin_layout_nav_class('feedback.php', $currentPage); ?>" title="<?= htmlspecialchars($inboxTitle, ENT_QUOTES, 'UTF-8') ?>" aria-label="<?= htmlspecialchars($inboxTitle, ENT_QUOTES, 'UTF-8') ?>"<?php echo admin_layout_nav_attrs('feedback.php?view=internal'); ?>>
              <i class="icon ion-reply"></i>
              <?= admin_nav_badge_html($inboxUnread) ?>
              <span>Inbox<?php if ($inboxUnread > 0): ?> (<?= $inboxUnread ?>)<?php endif; ?></span>
          </a>
        </li>
        <?php endif; ?>
        <li class="nav-item">
          <a href="logout.php" class="nav-link" title="Signout" aria-label="Signout">
              <i class="icon ion-power"></i>
              <span>Signout</span>
          </a>
        </li>
      </ul>
    </div>
<?php admin_layout_footer_assets(); ?>
