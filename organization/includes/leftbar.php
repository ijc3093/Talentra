<?php
declare(strict_types=1);

require_once __DIR__ . '/session_org_login.php';
orgRequireLoginOnly();

require_once __DIR__ . '/../../admin/controller.php';
$leftbarDbh = (new Controller())->pdo();
require_once __DIR__ . '/org_theme_prefs.php';
require_once __DIR__ . '/org_layout.php';
$leftbarPublisherUserId = org_theme_viewer_user_id($leftbarDbh);

$isManager = isOrgManager();
$label = $isManager ? 'Publisher workspace' : 'Staff workspace';

if (!function_exists('h')) {
    function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}
?>
<div class="sh-sideleft-menu org-sideleft-shell">
  <div class="org-sideleft-top">
    <label class="sh-sidebar-label"><?= h($label) ?></label>
    <ul class="nav org-sideleft-nav">
      <li class="nav-item">
        <a href="feed.php" class="nav-link"<?= org_layout_nav_attrs('feed.php') ?>>
          <i class="icon ion-ios-home-outline"></i>
          <span>Home feed</span>
        </a>
      </li>
    </ul>
  </div>

  <div class="org-sideleft-scroll" role="navigation" aria-label="Organization navigation">
    <ul class="nav org-sideleft-nav">
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
        <a href="messages.php" class="nav-link"<?= org_layout_nav_attrs('messages.php') ?>>
          <i class="icon ion-chatbubble"></i>
          <span>Messages</span>
        </a>
      </li>

      <?php if ($leftbarPublisherUserId > 0): ?>
        <?php if ($isManager): ?>
        <li class="nav-item">
          <a href="publisher_public_enter.php?to=compose" class="nav-link">
            <i class="icon ion-ios-paperplane"></i>
            <span>Publish to public</span>
          </a>
        </li>
        <li class="nav-item">
          <a href="publisher_public_enter.php?to=feed" class="nav-link">
            <i class="icon ion-ios-world-outline"></i>
            <span>Public feed</span>
          </a>
        </li>
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

      <?php if ($isManager): ?>
      <li class="nav-item">
        <a href="commerce.php" class="nav-link"<?= org_layout_nav_attrs('commerce.php') ?>>
          <i class="icon ion-bag"></i>
          <span>Shop &amp; commerce</span>
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
    </ul>
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
