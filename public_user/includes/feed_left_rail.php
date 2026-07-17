<?php
/** [FEED_LEFT_RAIL_UI] visual nav panel — set $feedLeftRailActive before include */
require_once __DIR__ . '/session_user.php';

$feedLeftRailActive = strtolower((string)($feedLeftRailActive ?? basename($_SERVER['PHP_SELF'] ?? '')));
$feedLeftRailEmbed = !empty($feedLeftRailEmbed);
$flrActive = static function (string $file) use ($feedLeftRailActive): string {
    return ($feedLeftRailActive === strtolower($file)) ? ' is-active' : '';
};
$flrPublisherPortalActive = static function (array $company) use ($feedLeftRailActive): string {
    if ($feedLeftRailActive !== 'publisher_org_portal.php') {
        return '';
    }
    $requestedOrgId = (int)($_GET['org_id'] ?? $_SESSION['org_active_org_id'] ?? $_SESSION['publisher_org_id'] ?? 0);
    $companyOrgId = (int)($company['org_id'] ?? 0);
    if ($requestedOrgId > 0 && $companyOrgId > 0) {
        return $requestedOrgId === $companyOrgId ? ' is-active' : '';
    }
    return ' is-active';
};

$myPublisherCompany = [];
$flrMeId = (int)($_SESSION['user_id'] ?? 0);

if (isset($feedLeftRailPublicPublishers) && is_array($feedLeftRailPublicPublishers)) {
    $myPublisherCompany = $feedLeftRailPublicPublishers;
} elseif ($flrMeId > 0) {
    try {
        require_once __DIR__ . '/../controller.php';
        require_once __DIR__ . '/publisher_accounts_load.php';
        require_once __DIR__ . '/publisher_organization_bridge.php';
        require_once __DIR__ . '/staff_publisher_access.php';
        $flrDbh = (new Controller())->pdo();
        $myPublisherCompany = staff_pub_menu_for_viewer($flrDbh, $flrMeId);
    } catch (Throwable $e) {
        $myPublisherCompany = [];
    }
}

if (!$myPublisherCompany && $flrMeId > 0) {
    require_once __DIR__ . '/publisher_accounts_load.php';
    require_once __DIR__ . '/staff_publisher_access.php';
    if (staff_pub_is_staff_session()) {
        $orgId = staff_pub_org_id();
        $sessionName = publisher_registry_normalize_name((string)($_SESSION['user_name'] ?? ''));
        if ($sessionName !== '' && $orgId > 0) {
            $myPublisherCompany[] = [
                'user_id' => $flrMeId,
                'name' => $sessionName,
                'username' => trim((string)($_SESSION['user_login'] ?? '')),
                'org_id' => $orgId,
                'is_self' => true,
                'href' => 'staff_org_portal.php?org_id=' . $orgId,
            ];
        }
    } else {
        $sessionName = publisher_registry_normalize_name((string)($_SESSION['user_name'] ?? ''));
        $sessionFriendCode = strtoupper(trim((string)($_SESSION['user_friend_code'] ?? '')));
        $sessionKind = strtolower(trim((string)($_SESSION['user_account_kind'] ?? '')));
        if (
            $sessionName !== ''
            && ($sessionKind === 'publisher' || str_starts_with($sessionFriendCode, 'PUB-'))
        ) {
            $flrOrgId = 0;
            $flrPortalHref = 'publisher_org_portal.php';
            try {
                require_once __DIR__ . '/publisher_organization_bridge.php';
                require_once __DIR__ . '/../controller.php';
                $flrFallbackDbh = (new Controller())->pdo();
                $flrOrgId = publisher_org_resolve_user_org_id($flrFallbackDbh, $flrMeId, $sessionName);
                $flrPortalHref = publisher_org_portal_href_for_user($flrFallbackDbh, $flrMeId, $sessionName);
            } catch (Throwable $e) {
                $flrOrgId = (int)($_SESSION['publisher_org_id'] ?? 0);
                if ($flrOrgId > 0) {
                    $flrPortalHref = 'publisher_org_portal.php?org_id=' . $flrOrgId;
                }
            }
            $myPublisherCompany[] = [
                'user_id' => $flrMeId,
                'name' => $sessionName,
                'username' => trim((string)($_SESSION['user_login'] ?? '')),
                'org_id' => $flrOrgId,
                'is_self' => true,
                'href' => $flrPortalHref,
            ];
        }
    }
}

$flrStaffReadonly = false;
try {
    require_once __DIR__ . '/staff_publisher_access.php';
    $flrStaffReadonly = staff_pub_is_readonly();
} catch (Throwable $e) {
    $flrStaffReadonly = false;
}

$flrCanFollow = !empty($feedLeftRailCanFollow);
if (!isset($feedLeftRailCanFollow) && $flrMeId > 0) {
    try {
        require_once __DIR__ . '/publisher_accounts.php';
        if (!isset($flrDbh) || !($flrDbh instanceof PDO)) {
            require_once __DIR__ . '/../controller.php';
            $flrDbh = (new Controller())->pdo();
        }
        $flrCanFollow = publisher_can_follow_as_viewer($flrDbh, $flrMeId);
    } catch (Throwable $e) {
        $flrCanFollow = false;
    }
}

$flrPendingCount = (int)($feedLeftRailPendingCount ?? -1);
if ($flrPendingCount < 0 && $flrMeId > 0) {
    $flrPendingCount = 0;
    try {
        if (!isset($flrDbh) || !($flrDbh instanceof PDO)) {
            require_once __DIR__ . '/../controller.php';
            $flrDbh = (new Controller())->pdo();
        }
        $stFriendReq = $flrDbh->prepare("
          SELECT COUNT(*)
          FROM contact_requests
          WHERE to_user_id = :me
            AND status = 'pending'
        ");
        $stFriendReq->execute([':me' => $flrMeId]);
        $flrPendingCount = (int)($stFriendReq->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        $flrPendingCount = 0;
    }
}
?>
<?php
  $flrPageHeadTitle = trim((string)($feedLeftRailPageHeadTitle ?? ''));
  $flrPageHeadSub = trim((string)($feedLeftRailPageHeadSub ?? ''));
  $flrShopOnlyNav = !empty($feedLeftRailShopOnly);
?>
<?php if ($flrPageHeadTitle !== '' && !$feedLeftRailEmbed): ?>
<div class="feed-left-rail-page-head">
  <h1 class="feed-left-rail-page-title"><?= htmlspecialchars($flrPageHeadTitle, ENT_QUOTES, 'UTF-8') ?></h1>
  <?php if ($flrPageHeadSub !== ''): ?>
  <p class="feed-left-rail-page-sub"><?= htmlspecialchars($flrPageHeadSub, ENT_QUOTES, 'UTF-8') ?></p>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php if (!$feedLeftRailEmbed): ?><aside class="feed-left-rail" aria-label="Main navigation"><?php endif; ?>
  <?php if ($flrPageHeadTitle !== '' && $feedLeftRailEmbed): ?>
  <div class="feed-left-rail-page-head">
    <h1 class="feed-left-rail-page-title"><?= htmlspecialchars($flrPageHeadTitle, ENT_QUOTES, 'UTF-8') ?></h1>
    <?php if ($flrPageHeadSub !== ''): ?>
    <p class="feed-left-rail-page-sub"><?= htmlspecialchars($flrPageHeadSub, ENT_QUOTES, 'UTF-8') ?></p>
    <?php endif; ?>
  </div>
  <?php endif; ?>
  <nav class="feed-left-nav" aria-label="Sidebar menu">
    <?php if ($flrShopOnlyNav): ?>
    <?php if (!empty($feedLeftRailShopFilters)): ?>
      <?php include __DIR__ . '/feed_shop_brand_nav.php'; ?>
      <?php include __DIR__ . '/feed_shop_nav_filters.php'; ?>
    <?php endif; ?>
    <?php else: ?>
    <a class="feed-left-nav-item<?= $flrActive('feed.php') ?>" href="feed.php">
      <span class="feed-left-nav-ic" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M3 10.5L12 3l9 7.5V20a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1v-9.5z"/></svg></span>
      <span class="feed-left-nav-label">Friends Feed</span>
    </a>
    <a class="feed-left-nav-item<?= $flrActive('public.php') ?><?= $flrActive('news.php') ?>" href="public.php">
      <span class="feed-left-nav-ic" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M2 12h20M12 2a15 15 0 0 1 0 20M12 2a15 15 0 0 0 0 20"/></svg></span>
      <span class="feed-left-nav-label">Public</span>
    </a>
    <?php foreach ($myPublisherCompany as $company): ?>
      <?php
        $companyName = trim((string)($company['name'] ?? ''));
        $companyHref = trim((string)($company['href'] ?? ''));
        if ($companyName === '' || $companyHref === '') {
            continue;
        }
        $companyLabel = 'Enterprise';
      ?>
      <a
        class="feed-left-nav-item feed-left-nav-item-publisher feed-left-nav-item-under-public is-self-publisher<?= $flrPublisherPortalActive($company) ?>"
        href="<?= htmlspecialchars($companyHref, ENT_QUOTES, 'UTF-8') ?>"
        title="<?= htmlspecialchars($companyName, ENT_QUOTES, 'UTF-8') ?>"
        aria-label="Enterprise"
      >
        <span class="feed-left-nav-ic" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M3 21h18"/><path d="M6 21V7l6-3.5L18 7v14"/><path d="M9 21v-5h6v5"/></svg></span>
        <span class="feed-left-nav-label"><?= htmlspecialchars($companyLabel, ENT_QUOTES, 'UTF-8') ?></span>
      </a>
    <?php endforeach; ?>
    <?php if (!$flrStaffReadonly): ?>
    <a class="feed-left-nav-item<?= $flrActive('messages.php') ?>" href="messages.php">
      <span class="feed-left-nav-ic" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M21 12a8 8 0 0 1-8 8H7l-4 3V12a8 8 0 0 1 8-8h4a8 8 0 0 1 4 8z"/></svg></span>
      <span class="feed-left-nav-label">Messages</span>
    </a>
    <a class="feed-left-nav-item<?= $flrActive('add_contact.php') ?>" href="add_contact.php">
      <span class="feed-left-nav-ic" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="10" cy="8" r="3.5"/><path d="M3 20c0-3.3 2.4-6 7-6"/><path d="M19 8v6M16 11h6"/></svg></span>
      <span class="feed-left-nav-label">Add Friend</span>
    </a>
    <a class="feed-left-nav-item<?= $flrActive('contact_requests.php') ?>" href="contact_requests.php">
      <span class="feed-left-nav-ic" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M16 3h5v5"/><path d="M8 3H3v5"/><path d="M12 12v9"/><path d="M21 3l-7 7"/><path d="M3 3l7 7"/><rect x="8" y="14" width="8" height="7" rx="1.5"/></svg></span>
      <span class="feed-left-nav-label">Friend Requests</span>
      <?php if ($flrPendingCount > 0): ?>
      <span class="feed-left-nav-badge" aria-label="<?= (int)$flrPendingCount ?> pending"><?= (int)$flrPendingCount ?></span>
      <?php endif; ?>
    </a>
    <a class="feed-left-nav-item<?= $flrActive('contacts.php') ?>" href="contacts.php">
      <span class="feed-left-nav-ic" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="9" cy="8" r="3.5"/><circle cx="17" cy="9" r="2.5"/><path d="M3 20c0-3 2.2-5.5 6-5.5"/><path d="M14 20c0-2.2 1.6-4 4-4.5"/></svg></span>
      <span class="feed-left-nav-label">Friends</span>
    </a>
    <?php endif; ?>
    <?php if ($flrCanFollow): ?>
    <a class="feed-left-nav-item<?= $flrActive('news.php') ?>" href="news.php">
      <span class="feed-left-nav-ic" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg></span>
      <span class="feed-left-nav-label">Follow</span>
    </a>
    <?php endif; ?>
    <a class="feed-left-nav-item<?= $flrActive('news.php') ?>" href="news.php">
      <span class="feed-left-nav-ic" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/><path d="M8 7h8M8 11h8M8 15h5"/></svg></span>
      <span class="feed-left-nav-label">News</span>
    </a>
    <a class="feed-left-nav-item<?= $flrActive('shop.php') ?>" href="shop.php">
      <span class="feed-left-nav-ic" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z"/><path d="M3 6h18"/><path d="M16 10a4 4 0 0 1-8 0"/></svg></span>
      <span class="feed-left-nav-label">Shop</span>
    </a>
    <a class="feed-left-nav-item<?= $flrActive('cart.php') ?>" href="cart.php">
      <span class="feed-left-nav-ic" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.7 13.4a2 2 0 0 0 2 1.6h9.7a2 2 0 0 0 2-1.6L23 6H6"/></svg></span>
      <span class="feed-left-nav-label">Cart</span>
    </a>
    <a class="feed-left-nav-item<?= $flrActive('library') ?>" href="#">
      <span class="feed-left-nav-ic" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 19V5"/><path d="M4 19h16"/><path d="M8 17V9"/><path d="M12 17V7"/><path d="M16 17v-5"/></svg></span>
      <span class="feed-left-nav-label">Library</span>
    </a>
    <a class="feed-left-nav-item<?= $flrActive('apps') ?>" href="#">
      <span class="feed-left-nav-ic" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/></svg></span>
      <span class="feed-left-nav-label">Apps</span>
    </a>
    <a class="feed-left-nav-item<?= $flrActive('agents') ?>" href="#">
      <span class="feed-left-nav-ic" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="5" y="8" width="14" height="10" rx="3"/><path d="M9 8V6a3 3 0 0 1 6 0v2"/><circle cx="10" cy="13" r="1"/><circle cx="14" cy="13" r="1"/><path d="M10 16h4"/></svg></span>
      <span class="feed-left-nav-label">Agents</span>
      <span class="feed-left-nav-badge">NEW</span>
    </a>
    <a class="feed-left-nav-item<?= $flrActive('research') ?>" href="#">
      <span class="feed-left-nav-ic" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M3 20l6-6"/><path d="M14 4l6 6"/><path d="M9 15l-2 5 5-2 8-8-3-3-8 8z"/><circle cx="18" cy="6" r="2"/></svg></span>
      <span class="feed-left-nav-label">Deep research</span>
    </a>
    <a class="feed-left-nav-item" href="logout.php">
      <span class="feed-left-nav-ic" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M10 7V5a2 2 0 0 1 2-2h7v18h-7a2 2 0 0 1-2-2v-2"/><path d="M15 12H3"/><path d="M6 9l-3 3 3 3"/></svg></span>
      <span class="feed-left-nav-label">Sign Out</span>
    </a>
    <?php if (!empty($feedLeftRailShopFilters)): ?>
      <?php include __DIR__ . '/feed_shop_brand_nav.php'; ?>
      <?php include __DIR__ . '/feed_shop_nav_filters.php'; ?>
    <?php endif; ?>
    <?php endif; ?>
  </nav>
<?php if (!$feedLeftRailEmbed): ?></aside><?php endif; ?>
