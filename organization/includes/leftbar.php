<?php
// /Business_only3/organization/includes/leftbar.php
declare(strict_types=1);

// ✅ Don't force active org selection here (create_org/select_org must still render)
require_once __DIR__ . '/session_org_login.php';
orgRequireLoginOnly();

$label = isOrgManager() ? 'Manager Menu' : 'Staff Menu';

$isManager = isOrgManager();

// --- current account display info (safe) ---
$displayName = $isManager ? 'Manager' : 'Staff';
$displayEmail = '';
try {
    if ($isManager) {
        $st = $dbh->prepare("SELECT fullname, email FROM managers WHERE id = :id LIMIT 1");
    } else {
        $st = $dbh->prepare("SELECT fullname, email FROM staff_accounts WHERE id = :id LIMIT 1");
    }
    $st->execute([':id' => orgAccountId()]);
    $u = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    $dn = trim((string)($u['fullname'] ?? ''));
    if ($dn !== '') $displayName = $dn;
    $displayEmail = trim((string)($u['email'] ?? ''));
} catch (Throwable $e) {
    // keep defaults
}
?>
<div class="sh-sideleft-menu">
  <label class="sh-sidebar-label"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></label>
  <ul class="nav">
    <li class="nav-item">
      <a href="feed.php" class="nav-link">
        <i class="icon ion-ios-home-outline"></i>
        <span>Home</span>
      </a>
    </li>

    <!-- <li class="nav-item">
      <?php if ($isManager): ?>
        <?php
          $orgs = [];
          try {
              $stOrgs = $dbh->prepare("SELECT id, name FROM organizations WHERE owner_manager_id = :mid AND status = 1 ORDER BY id DESC");
              $stOrgs->execute([':mid' => orgAccountId()]);
              $orgs = $stOrgs->fetchAll(PDO::FETCH_ASSOC);
          } catch (Throwable $e) {}
        ?>
        <form method="get" action="switch_org.php" class="mg-r-10" style="margin:0;">
          <div class="d-flex align-items-center">
            
            <select name="org" onchange="this.form.submit()" style="min-width:200px;margin-left:7%;margin-right:5%;color:white;background:lightslategrey;text-shadow: 0 1px 0 rgba(0, 0, 0, 0.4);">
              <?php foreach ($orgs as $o): ?>
                <option value="<?= (int)$o['id'] ?>" <?= ((int)$o['id'] === (int)($ORG['id'] ?? 0)) ? 'selected' : '' ?>>
                  <?= h((string)($o['name'] ?? 'Org')) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
        </form>
      <?php else: ?>
        
      <?php endif; ?>
    </li> -->

    <li class="nav-item">
      <a href="messages.php" class="nav-link">
        <i class="icon ion-chatbubble"></i>
        <span>Messages</span>
      </a>
    </li>

    <li class="nav-item">
      <a href="posts.php" class="nav-link">
        <i class="icon ion-ios-list"></i>
        <span>Posts List</span>
      </a>
    </li>

    <?php if (!$isManager): ?>
    <li class="nav-item">
      <a href="../public_user/staff_publisher_portal.php" class="nav-link">
        <i class="icon ion-ios-world-outline"></i>
        <span>Publisher Public Feed</span>
      </a>
    </li>
    <?php endif; ?>

    <li class="nav-item">
      <a href="members.php" class="nav-link">
        <i class="icon ion-person-stalker"></i>
        <span>Members</span>
      </a>
    </li>

    <?php if (isOrgManager()): ?>
      <li class="nav-item">
        <a href="create_staff.php" class="nav-link">
          <i class="icon ion-person-add"></i>
          <span>Create Staff</span>
        </a>
      </li>

      <li class="nav-item">
        <a href="create_org.php" class="nav-link">
          <i class="icon ion-ios-plus-outline"></i>
          <span>Create Organization</span>
        </a>
      </li>

      <!-- <li class="nav-item">
        <a href="settings.php" class="nav-link">
          <i class="icon ion-ios-gear-outline"></i>
          <span>Branding &amp; Accessibility</span>
        </a>
      </li> -->
    <?php endif; ?>

    <li class="nav-item">
      <a href="logout.php" class="nav-link">
        <i class="icon ion-power"></i>
        <span>Sign Out</span>
      </a>
    </li>
  </ul>
</div>
