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
?>
    <!-- <div class="sh-logopanel">
      <a href="" class="sh-logo-text">Private App</a>
      <a id="navicon" href="" class="sh-navicon d-none d-xl-block"><i class="icon ion-navicon"></i></a>
      <a id="naviconMobile" href="" class="sh-navicon d-xl-none"><i class="icon ion-navicon"></i></a>
    </div> -->
    <!-- sh-logopanel -->

    <div class="sh-sideleft-menu">
      <!-- <label class="sh-sidebar-label"><?php echo htmlspecialchars(ucfirst($base)); ?> Menu</label> -->
      <ul class="nav">
        <li class="nav-item">
          <a href="dashboard.php" class="<?php echo admin_layout_nav_class('dashboard.php', $currentPage); ?>"<?php echo admin_layout_nav_attrs('dashboard.php'); ?>>
            <i class="icon ion-ios-home-outline"></i>
            <span>Home</span>
          </a>
        </li><!-- nav-item -->
        <?php if ($leftbarLinkedPortals): ?>
        <li class="nav-item">
          <a href="<?= htmlspecialchars((string)($leftbarLinkedPortals['personal']['url'] ?? 'open_linked_portal.php?portal=personal'), ENT_QUOTES, 'UTF-8') ?>" class="nav-link" target="_blank" rel="noopener noreferrer">
            <i class="icon ion-ios-person-outline"></i>
            <span>My Personal Feed</span>
          </a>
          <a href="<?= htmlspecialchars((string)($leftbarLinkedPortals['publisher']['url'] ?? 'open_linked_portal.php?portal=publisher'), ENT_QUOTES, 'UTF-8') ?>" class="nav-link" target="_blank" rel="noopener noreferrer">
            <i class="icon ion-ios-paper-outline"></i>
            <span>My Publisher Feed</span>
          </a>
          <a href="<?= htmlspecialchars((string)($leftbarLinkedPortals['organization']['url'] ?? 'open_linked_portal.php?portal=organization'), ENT_QUOTES, 'UTF-8') ?>" class="nav-link" target="_blank" rel="noopener noreferrer">
            <i class="icon ion-ios-briefcase-outline"></i>
            <span>My Organization</span>
          </a>
        </li><!-- nav-item -->
        <?php endif; ?>
        <?php if (roleIs($base,'admin')): ?>
        <li class="nav-item">
          <a href="adminroles.php" class="<?php echo admin_layout_nav_class('adminroles.php', $currentPage); ?>"<?php echo admin_layout_nav_attrs('adminroles.php'); ?>>
            <i class="icon ion-person"></i>
            <span>List Roles & Accounts</span>
          </a>
          <a href="userlist.php" class="<?php echo admin_layout_nav_class('userlist.php', $currentPage); ?>"<?php echo admin_layout_nav_attrs('userlist.php'); ?>>
            <i class="icon ion-person"></i>
            <span>User List</span>
          </a>
          <?php
            $pendingPublisherRequests = 0;
            try {
              require_once __DIR__ . '/../../public_user/includes/publisher_authority.php';
              $pendingPublisherRequests = publisher_authority_pending_count(adminDbh());
            } catch (Throwable $e) {
              $pendingPublisherRequests = 0;
            }
          ?>
          <a href="publisher_requests.php" class="<?php echo admin_layout_nav_class('publisher_requests.php', $currentPage); ?>"<?php echo admin_layout_nav_attrs('publisher_requests.php'); ?>>
            <i class="icon ion-ios-paper"></i>
            <span>Publisher Requests<?php if ($pendingPublisherRequests > 0): ?> (<?= (int)$pendingPublisherRequests ?>)<?php endif; ?></span>
          </a>
          <a href="orglist.php" class="<?php echo admin_layout_nav_class('orglist.php', $currentPage); ?>"<?php echo admin_layout_nav_attrs('orglist.php'); ?>>
            <i class="icon ion-ios-briefcase"></i>
            <span>Organizations</span>
          </a>
          <a href="managerlist.php" class="<?php echo admin_layout_nav_class('managerlist.php', $currentPage); ?>"<?php echo admin_layout_nav_attrs('managerlist.php'); ?>>
            <i class="icon ion-person-stalker"></i>
            <span>Managers</span>
          </a>
          <a href="stafflist.php" class="<?php echo admin_layout_nav_class('stafflist.php', $currentPage); ?>"<?php echo admin_layout_nav_attrs('stafflist.php'); ?>>
            <i class="icon ion-ios-people"></i>
            <span>Org Staff</span>
          </a>
          <a href="account_search.php" class="<?php echo admin_layout_nav_class('account_search.php', $currentPage); ?>"<?php echo admin_layout_nav_attrs('account_search.php'); ?>>
            <i class="icon ion-ios-search"></i>
            <span>Account Search</span>
          </a>
          <a href="security-log.php" class="<?php echo admin_layout_nav_class('security-log.php', $currentPage); ?>"<?php echo admin_layout_nav_attrs('security-log.php'); ?>>
            <i class="fa fa-cog"></i>
            <span>Security Logs</span>
          </a>
        </li>
        <!-- nav-item -->
        <?php endif; ?>

        <?php if (roleIn($base, ['admin','manager','staff'])): ?>
        <li class="nav-item">
          <a href="feedback.php?view=internal" class="<?php echo admin_layout_nav_class('feedback.php', $currentPage); ?>"<?php echo admin_layout_nav_attrs('feedback.php?view=internal'); ?>>
              <i class="icon ion-reply"></i>
              <span>Inbox</span>
          </a>
        </li>
        <?php endif; ?>
        <!-- <li class="nav-item">
          <a href="contacts.php" class="nav-link active">
              <i class="icon ion-folder"></i>
              <span>Contact</span>
          </a>
        </li>  -->
        <li class="nav-item">
          <a href="logout.php" class="nav-link">
              <i class="icon ion-power"></i>
              <span>Signout</span>
          </a>
        </li><!-- nav-item -->
      </ul>
      
    </div><!-- sh-sideleft-menu -->
<?php admin_layout_footer_assets(); ?>
