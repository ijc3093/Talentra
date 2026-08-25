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
  <?php if (!$flrShopOnlyNav): ?>
  <div class="feed-left-rail-head">
    <button class="feed-left-nav-item feed-left-nav-add-program" type="button" aria-label="Add Program" aria-haspopup="dialog" aria-controls="feedProgramManager">
      <span class="feed-left-nav-ic" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 8v8"/><path d="M8 12h8"/></svg></span>
      <span class="feed-left-nav-label">Add Program</span>
    </button>
  </div>
  <?php endif; ?>
  <nav class="feed-left-nav" aria-label="Sidebar menu">
    <?php if ($flrShopOnlyNav): ?>
    <?php if (!empty($feedLeftRailShopFilters)): ?>
      <?php include __DIR__ . '/feed_shop_brand_nav.php'; ?>
      <?php include __DIR__ . '/feed_shop_nav_filters.php'; ?>
    <?php endif; ?>
    <?php else: ?>
    <!-- <a class="feed-left-nav-item<?= $flrActive('feed.php') ?>" href="feed.php">
      <span class="feed-left-nav-ic" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M3 10.5L12 3l9 7.5V20a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1v-9.5z"/></svg></span>
      <span class="feed-left-nav-label">Friends Feed</span>
    </a> -->
    <!-- <a class="feed-left-nav-item<?= $flrActive('public.php') ?>" href="public.php">
      <span class="feed-left-nav-ic" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M2 12h20M12 2a15 15 0 0 1 0 20M12 2a15 15 0 0 0 0 20"/></svg></span>
      <span class="feed-left-nav-label">Public</span>
    </a> -->
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
    <!-- <a class="feed-left-nav-item<?= $flrActive('messages.php') ?>" href="messages.php">
      <span class="feed-left-nav-ic" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M21 12a8 8 0 0 1-8 8H7l-4 3V12a8 8 0 0 1 8-8h4a8 8 0 0 1 4 8z"/></svg></span>
      <span class="feed-left-nav-label">Messages</span>
    </a> -->
    <!-- <a class="feed-left-nav-item<?= $flrActive('add_contact.php') ?>" href="add_contact.php">
      <span class="feed-left-nav-ic" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="10" cy="8" r="3.5"/><path d="M3 20c0-3.3 2.4-6 7-6"/><path d="M19 8v6M16 11h6"/></svg></span>
      <span class="feed-left-nav-label">Add Friend</span>
    </a> -->
    <!-- <a class="feed-left-nav-item<?= $flrActive('contact_requests.php') ?>" href="contact_requests.php">
      <span class="feed-left-nav-ic" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M16 3h5v5"/><path d="M8 3H3v5"/><path d="M12 12v9"/><path d="M21 3l-7 7"/><path d="M3 3l7 7"/><rect x="8" y="14" width="8" height="7" rx="1.5"/></svg></span>
      <span class="feed-left-nav-label">Friend Requests</span>
      <?php if ($flrPendingCount > 0): ?>
      <span class="feed-left-nav-badge" aria-label="<?= (int)$flrPendingCount ?> pending"><?= (int)$flrPendingCount ?></span>
      <?php endif; ?>
    </a> -->
    <!-- <a class="feed-left-nav-item<?= $flrActive('contacts.php') ?>" href="contacts.php">
      <span class="feed-left-nav-ic" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="9" cy="8" r="3.5"/><circle cx="17" cy="9" r="2.5"/><path d="M3 20c0-3 2.2-5.5 6-5.5"/><path d="M14 20c0-2.2 1.6-4 4-4.5"/></svg></span>
      <span class="feed-left-nav-label">Friends</span>
    </a> -->
    <?php endif; ?>
    <?php if ($flrCanFollow): ?>
    <!-- <a class="feed-left-nav-item<?= $flrActive('news.php') ?>" href="news.php">
      <span class="feed-left-nav-ic" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg></span>
      <span class="feed-left-nav-label">Follow</span>
    </a> -->
    <?php endif; ?>
    <!-- <a class="feed-left-nav-item<?= $flrActive('news.php') ?>" href="news.php">
      <span class="feed-left-nav-ic" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/><path d="M8 7h8M8 11h8M8 15h5"/></svg></span>
      <span class="feed-left-nav-label">News</span>
    </a> -->
    <!-- <a class="feed-left-nav-item<?= $flrActive('shop.php') ?>" href="shop.php">
      <span class="feed-left-nav-ic" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z"/><path d="M3 6h18"/><path d="M16 10a4 4 0 0 1-8 0"/></svg></span>
      <span class="feed-left-nav-label">Shop</span>
    </a> -->
    <!-- <a class="feed-left-nav-item<?= $flrActive('cart.php') ?>" href="cart.php">
      <span class="feed-left-nav-ic" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.7 13.4a2 2 0 0 0 2 1.6h9.7a2 2 0 0 0 2-1.6L23 6H6"/></svg></span>
      <span class="feed-left-nav-label">Cart</span>
    </a> -->
    <a class="feed-left-nav-item feed-program-nav-item<?= $flrActive('entertainment') ?>" href="home.php?tab=entertainment" data-program-slug="entertainment" hidden>
      <span class="feed-left-nav-ic" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M8 3v18"/><path d="M16 3v18"/><path d="M3 8h18"/><path d="M3 16h18"/><rect x="3" y="3" width="18" height="18" rx="2"/></svg></span>
      <span class="feed-left-nav-label">Entertainment</span>
    </a>
    <a class="feed-left-nav-item feed-program-nav-item<?= $flrActive('library') ?>" href="home.php?tab=library" data-program-slug="library" hidden>
      <span class="feed-left-nav-ic" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 5.5A2.5 2.5 0 0 1 6.5 3H11v16H6.5A2.5 2.5 0 0 0 4 21.5z"/><path d="M20 5.5A2.5 2.5 0 0 0 17.5 3H13v16h4.5a2.5 2.5 0 0 1 2.5 2.5z"/></svg></span>
      <span class="feed-left-nav-label">Library</span>
    </a>
    <a class="feed-left-nav-item feed-program-nav-item<?= $flrActive('cook') ?>" href="home.php?tab=cook" data-program-slug="cook" hidden>
      <span class="feed-left-nav-ic" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M6 3v7"/><path d="M3.5 3v4.5A2.5 2.5 0 0 0 6 10"/><path d="M8.5 3v4.5A2.5 2.5 0 0 1 6 10v11"/><path d="M15 3v18"/><path d="M15 3a5 5 0 0 1 5 5v4h-5"/></svg></span>
      <span class="feed-left-nav-label">Cook</span>
    </a>
    <a class="feed-left-nav-item feed-program-nav-item<?= $flrActive('seek-around-the-world') ?>" href="home.php?tab=seek-around-the-world" data-program-slug="seek-around-the-world" hidden>
      <span class="feed-left-nav-ic" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M3 12h18"/><path d="M12 3a14 14 0 0 1 0 18"/><path d="M12 3a14 14 0 0 0 0 18"/></svg></span>
      <span class="feed-left-nav-label">Seek around the World</span>
    </a>
    <a class="feed-left-nav-item feed-program-nav-item<?= $flrActive('geology') ?>" href="home.php?tab=geology" data-program-slug="geology" hidden>
      <span class="feed-left-nav-ic" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="m3 20 6.5-11 3 5 2.5-4 6 10z"/><path d="m7.8 12 1.7 1.5 1.5-2"/><path d="M3 20h18"/></svg></span>
      <span class="feed-left-nav-label">Geology</span>
    </a>
    <a class="feed-left-nav-item feed-program-nav-item<?= $flrActive('animation') ?>" href="home.php?tab=animation" data-program-slug="animation" hidden>
      <span class="feed-left-nav-ic" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m10 9 5 3-5 3z"/><path d="M7 5v14"/><path d="M17 5v14"/></svg></span>
      <span class="feed-left-nav-label">Animation</span>
    </a>
    <a class="feed-left-nav-item feed-program-nav-item<?= $flrActive('make-a-new-friend') ?>" href="home.php?tab=make-a-new-friend" data-program-slug="make-a-new-friend" hidden>
      <span class="feed-left-nav-ic" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/></svg></span>
      <span class="feed-left-nav-label">Make a new Friend</span>
    </a>
    <a class="feed-left-nav-item feed-program-nav-item<?= $flrActive('agents') ?>" href="home.php?tab=agents" data-program-slug="agents" hidden>
      <span class="feed-left-nav-ic" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="5" y="8" width="14" height="10" rx="3"/><path d="M9 8V6a3 3 0 0 1 6 0v2"/><circle cx="10" cy="13" r="1"/><circle cx="14" cy="13" r="1"/><path d="M10 16h4"/></svg></span>
      <span class="feed-left-nav-label">Agents</span>
      <span class="feed-left-nav-badge">NEW</span>
    </a>
    <a class="feed-left-nav-item feed-program-nav-item<?= $flrActive('deep-research') ?>" href="home.php?tab=deep-research" data-program-slug="deep-research" hidden>
      <span class="feed-left-nav-ic" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M3 20l6-6"/><path d="M14 4l6 6"/><path d="M9 15l-2 5 5-2 8-8-3-3-8 8z"/><circle cx="18" cy="6" r="2"/></svg></span>
      <span class="feed-left-nav-label">Deep research</span>
    </a>
    <?php if (function_exists('publisher_academic_categories') && function_exists('publisher_category_icon_path')): ?>
    <?php foreach (publisher_academic_categories() as $categorySlug => $categoryLabel): ?>
    <a class="feed-left-nav-item feed-program-nav-item<?= $flrActive($categorySlug) ?>" href="home.php?tab=<?= htmlspecialchars($categorySlug, ENT_QUOTES, 'UTF-8') ?>" data-program-slug="<?= htmlspecialchars($categorySlug, ENT_QUOTES, 'UTF-8') ?>" hidden>
      <span class="feed-left-nav-ic" aria-hidden="true"><svg viewBox="0 0 24 24"><?= publisher_category_icon_path($categorySlug) ?></svg></span>
      <span class="feed-left-nav-label"><?= htmlspecialchars($categoryLabel, ENT_QUOTES, 'UTF-8') ?></span>
    </a>
    <?php endforeach; ?>
    <?php endif; ?>
    <?php
      $flrCustomCategories = [];
      try {
        if (!isset($flrDbh) || !($flrDbh instanceof PDO)) {
          require_once __DIR__ . '/../controller.php';
          $flrDbh = (new Controller())->pdo();
        }
        if (function_exists('publisher_custom_categories')) {
          $flrCustomCategories = publisher_custom_categories($flrDbh);
        }
      } catch (Throwable $e) {
        $flrCustomCategories = [];
      }
    ?>
    <?php foreach ($flrCustomCategories as $categorySlug => $categoryLabel): ?>
    <a class="feed-left-nav-item feed-program-nav-item<?= $flrActive($categorySlug) ?>" href="home.php?tab=<?= htmlspecialchars($categorySlug, ENT_QUOTES, 'UTF-8') ?>" data-program-slug="<?= htmlspecialchars($categorySlug, ENT_QUOTES, 'UTF-8') ?>" hidden>
      <span class="feed-left-nav-ic" aria-hidden="true"><svg viewBox="0 0 24 24"><?= function_exists('publisher_category_icon_path') ? publisher_category_icon_path($categorySlug) : '<circle cx="12" cy="12" r="9"/><path d="M7 12h10M12 7v10"/>' ?></svg></span>
      <span class="feed-left-nav-label"><?= htmlspecialchars($categoryLabel, ENT_QUOTES, 'UTF-8') ?></span>
    </a>
    <?php endforeach; ?>
    <?php if (!empty($feedLeftRailShopFilters)): ?>
      <?php include __DIR__ . '/feed_shop_brand_nav.php'; ?>
      <?php include __DIR__ . '/feed_shop_nav_filters.php'; ?>
    <?php endif; ?>
    <?php endif; ?>
  </nav>
  <?php if (!$feedLeftRailShopOnly): ?>
  <?php
    // Mount popup once (prefer desktop rail; embed only if desktop never boots)
    $feedProgramManagerBoot = false;
    if (!$feedLeftRailEmbed && empty($GLOBALS['msb_feed_program_manager_booted'])) {
      $GLOBALS['msb_feed_program_manager_booted'] = true;
      $feedProgramManagerBoot = true;
    }
  ?>
  <div class="feed-left-rail-footer" aria-label="Sidebar actions">
    <a class="feed-left-nav-item" href="logout.php">
      <span class="feed-left-nav-ic" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M10 7V5a2 2 0 0 1 2-2h7v18h-7a2 2 0 0 1-2-2v-2"/><path d="M15 12H3"/><path d="M6 9l-3 3 3 3"/></svg></span>
      <span class="feed-left-nav-label">Sign Out</span>
    </a>
  </div>
  <?php if ($feedProgramManagerBoot): ?>
  <?php
    // Remove any stale native <dialog> leftovers from older renders
  ?>
  <div class="feed-program-overlay" id="feedProgramManager" hidden aria-hidden="true">
    <div class="feed-program-dialog-card" role="dialog" aria-modal="true" aria-labelledby="feedProgramManagerTitle">
      <div class="feed-program-dialog-head">
        <h2 id="feedProgramManagerTitle">Add Programs</h2>
        <button type="button" class="feed-program-dialog-close" data-program-close aria-label="Close">
          <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path d="M6.4 6.4l11.2 11.2M17.6 6.4 6.4 17.6" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/></svg>
        </button>
      </div>
      <div class="feed-program-search">
        <input type="search" id="feedProgramSearch" placeholder="Search" autocomplete="off" aria-label="Search programs">
      </div>
      <div class="feed-program-list" id="feedProgramList" role="list"></div>
      <div class="feed-program-empty" id="feedProgramEmpty" hidden>No programs found.</div>
    </div>
  </div>
  <style id="feed-program-manager-styles">
    .feed-left-nav-add-program{width:100%;border:0;background:transparent;font:inherit;text-align:left;cursor:pointer;color:inherit}
    .feed-program-nav-item[hidden],
    .feed-discover-tab.feed-program-tab-item[hidden]{display:none!important}
    .feed-program-nav-item .feed-left-nav-ic{display:none!important}
    .feed-program-overlay[hidden],
    .feed-program-overlay:not(.is-open){
      display:none !important;
      pointer-events:none !important;
      visibility:hidden !important;
    }
    .feed-program-overlay.is-open{
      display:flex !important;
      visibility:visible !important;
      pointer-events:auto !important;
      align-items:center;
      justify-content:center;
      position:fixed;
      inset:0;
      z-index:12000;
      margin:0;
      padding:24px 16px;
      border:0;
      background:rgba(0,0,0,.4);
      box-sizing:border-box;
    }
    dialog.feed-program-dialog{display:none!important}
    .feed-program-dialog-card{
      --x-bg:#ffffff;
      --x-text:#0f1419;
      --x-muted:#536471;
      --x-soft:#eff3f4;
      --x-hover:rgba(15,20,25,.03);
      --x-green:#00ba7c;
      --x-green-hover:#00a870;
      --x-red:#f4212e;
      width:min(600px, calc(100vw - 32px));
      max-height:min(650px, calc(100dvh - 48px));
      display:flex;
      flex-direction:column;
      background:var(--x-bg);
      color:var(--x-text);
      border-radius:16px;
      box-shadow:0 0 0 1px rgba(0,0,0,.04), 0 12px 40px rgba(0,0,0,.28);
      overflow:hidden;
      font-family:TwitterChirp,-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;
    }
    html[data-theme="dark"] .feed-program-dialog-card,
    html.dark-auto .feed-program-dialog-card{
      --x-bg:#16181c;
      --x-text:#e7e9ea;
      --x-muted:#71767b;
      --x-soft:#202327;
      --x-hover:rgba(255,255,255,.03);
    }
    .feed-program-dialog-head{
      position:relative;
      flex:0 0 auto;
      display:flex;
      align-items:center;
      justify-content:center;
      min-height:53px;
      padding:0 56px;
    }
    .feed-program-dialog-head h2{
      margin:0;
      font-size:20px;
      font-weight:800;
      line-height:24px;
      letter-spacing:-.02em;
      text-align:center;
    }
    .feed-program-dialog-close{
      position:absolute;
      top:8px;
      right:12px;
      width:34px;
      height:34px;
      border:0;
      border-radius:999px;
      background:transparent;
      color:inherit;
      display:inline-flex;
      align-items:center;
      justify-content:center;
      cursor:pointer;
      padding:0;
    }
    .feed-program-dialog-close:hover{background:var(--x-hover)}
    .feed-program-search{
      flex:0 0 auto;
      padding:0 16px 12px;
    }
    .feed-program-search input{
      width:100%;
      height:42px;
      border:0;
      outline:0;
      border-radius:999px;
      background:var(--x-soft);
      color:inherit;
      padding:0 18px;
      font-size:15px;
      font-weight:400;
      line-height:20px;
      box-sizing:border-box;
    }
    .feed-program-search input::placeholder{color:var(--x-muted)}
    .feed-program-search input:focus{
      background:var(--x-bg);
      box-shadow:0 0 0 1px #1d9bf0;
    }
    .feed-program-list{
      flex:1 1 auto;
      min-height:0;
      overflow:auto;
      overscroll-behavior:contain;
      padding:0 0 12px;
      scrollbar-width:thin;
    }
    .feed-program-section{padding:4px 0 8px}
    .feed-program-section-head{
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap:12px;
      padding:12px 16px 8px;
    }
    .feed-program-section-title{
      margin:0;
      font-size:20px;
      font-weight:800;
      line-height:24px;
      letter-spacing:-.02em;
    }
    .feed-program-section-edit{
      border:0;
      background:transparent;
      color:inherit;
      font-size:14px;
      font-weight:700;
      line-height:16px;
      cursor:pointer;
      padding:6px 8px;
      border-radius:999px;
    }
    .feed-program-section-edit:hover{background:var(--x-hover)}
    .feed-program-section-toggle{
      width:34px;
      height:34px;
      border:0;
      border-radius:999px;
      background:transparent;
      color:inherit;
      display:inline-flex;
      align-items:center;
      justify-content:center;
      cursor:pointer;
      padding:0;
    }
    .feed-program-section-toggle:hover{background:var(--x-hover)}
    .feed-program-section-toggle svg{
      width:18px;
      height:18px;
      transition:transform .15s ease;
    }
    .feed-program-section.is-collapsed .feed-program-section-toggle svg{transform:rotate(-90deg)}
    .feed-program-section.is-collapsed .feed-program-section-body{display:none}
    .feed-program-section-hint{
      padding:4px 16px 12px;
      color:var(--x-muted);
      font-size:14px;
      line-height:18px;
    }
    .feed-program-row{
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap:12px;
      min-height:64px;
      padding:10px 16px;
      color:inherit;
    }
    .feed-program-row:hover{background:var(--x-hover)}
    .feed-program-identity{
      display:flex;
      align-items:center;
      gap:12px;
      min-width:0;
      flex:1 1 auto;
    }
    .feed-program-row-icon{
      flex:0 0 48px;
      width:48px;
      height:48px;
      border-radius:12px;
      background:var(--x-soft);
      color:var(--x-text);
      display:inline-flex;
      align-items:center;
      justify-content:center;
    }
    .feed-program-row-icon svg{
      width:24px;
      height:24px;
      fill:none;
      stroke:currentColor;
      stroke-width:1.7;
      stroke-linecap:round;
      stroke-linejoin:round;
    }
    .feed-program-row-name{
      overflow:hidden;
      text-overflow:ellipsis;
      white-space:nowrap;
      font-size:15px;
      font-weight:700;
      line-height:20px;
    }
    .feed-program-add{
      flex:0 0 34px;
      width:34px;
      height:34px;
      border:0;
      border-radius:999px;
      background:var(--x-green);
      color:#fff;
      display:inline-flex;
      align-items:center;
      justify-content:center;
      cursor:pointer;
      padding:0;
    }
    .feed-program-add:hover{background:var(--x-green-hover)}
    .feed-program-add svg{
      width:18px;
      height:18px;
      fill:none;
      stroke:currentColor;
      stroke-width:2.5;
      stroke-linecap:round;
    }
    .feed-program-remove{
      flex:0 0 34px;
      width:34px;
      height:34px;
      border:1.5px solid var(--x-red);
      border-radius:999px;
      background:transparent;
      color:var(--x-red);
      display:inline-flex;
      align-items:center;
      justify-content:center;
      cursor:pointer;
      padding:0;
    }
    .feed-program-remove svg{
      width:14px;
      height:14px;
      fill:none;
      stroke:currentColor;
      stroke-width:2.5;
      stroke-linecap:round;
    }
    .feed-program-empty{
      padding:32px 16px 40px;
      text-align:center;
      color:var(--x-muted);
      font-size:15px;
      line-height:20px;
    }
    @media (max-width:640px){
      .feed-program-overlay.is-open{padding:0;align-items:stretch}
      .feed-program-dialog-card{
        width:100%;
        max-height:100dvh;
        height:100dvh;
        border-radius:0;
      }
    }
  </style>
  <script>
  (function () {
    if (window.MSBFeedPrograms && window.MSBFeedPrograms.__ready) return;

    Array.prototype.slice.call(document.querySelectorAll('dialog.feed-program-dialog')).forEach(function (node) {
      try { node.remove(); } catch (eStale) {}
    });

    var overlay = document.getElementById('feedProgramManager');
    if (!overlay) return;
    try {
      Array.prototype.slice.call(document.querySelectorAll('.feed-program-overlay')).forEach(function (node, index) {
        if (index === 0) return;
        try { node.remove(); } catch (eDup) {}
      });
      overlay = document.getElementById('feedProgramManager') || overlay;
      if (overlay.parentNode !== document.body) document.body.appendChild(overlay);
    } catch (eMove) {}

    overlay.hidden = true;
    overlay.classList.remove('is-open');
    overlay.setAttribute('aria-hidden', 'true');

    var list = document.getElementById('feedProgramList');
    var search = document.getElementById('feedProgramSearch');
    var empty = document.getElementById('feedProgramEmpty');
    if (!list) return;

    var viewerKey = <?= json_encode((string)($flrMeId ?: 'guest'), JSON_UNESCAPED_SLASHES) ?>;
    var storageKey = 'msb.feed.programs.v2.' + viewerKey;
    var activeTab = <?= json_encode((string)$feedLeftRailActive, JSON_UNESCAPED_SLASHES) ?>;
    var currentDiscoverTab = '';
    try {
      currentDiscoverTab = String(new URL(window.location.href).searchParams.get('tab') || '').toLowerCase();
    } catch (eTab) { currentDiscoverTab = ''; }

    var editPinned = false;
    var topicsCollapsed = false;
    var fallbackIcon = '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 8v8M8 12h8"/></svg>';

    var optionalTabIcons = {
      enterprise: '<svg viewBox="0 0 24 24"><path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z"/><path d="M3 6h18"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>',
      trending: '<svg viewBox="0 0 24 24"><path d="M3 17l6-6 4 4 8-8"/><path d="M14 7h7v7"/></svg>',
      news: '<svg viewBox="0 0 24 24"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/><path d="M8 7h8M8 11h8M8 15h5"/></svg>',
      sports: '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18M3 12h18"/></svg>',
      business: '<svg viewBox="0 0 24 24"><rect x="3" y="7" width="18" height="13" rx="2"/><path d="M8 7V5a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>',
      science: '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="2"/><path d="M12 2v4M12 18v4M2 12h4M18 12h4M4.9 4.9l2.8 2.8M16.3 16.3l2.8 2.8M19.1 4.9l-2.8 2.8M7.7 16.3l-2.8 2.8"/></svg>',
      music: '<svg viewBox="0 0 24 24"><path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/></svg>',
      arts: '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="3"/><path d="M12 3v3M12 18v3M3 12h3M18 12h3"/></svg>',
      agriculture: '<svg viewBox="0 0 24 24"><path d="M12 22V10"/><path d="M12 10c-4 0-7-2-7-6 4 0 7 2 7 6z"/><path d="M12 12c4 0 7-2 7-6-4 0-7 2-7 6z"/><path d="M7 22h10"/></svg>',
      auto: '<svg viewBox="0 0 24 24"><path d="M3 13l2-6h14l2 6"/><path d="M5 13h14v5H5z"/><circle cx="7.5" cy="18.5" r="1.5"/><circle cx="16.5" cy="18.5" r="1.5"/></svg>',
      political: '<svg viewBox="0 0 24 24"><path d="M4 20h16"/><path d="M6 20V10l6-4 6 4v10"/><path d="M10 20v-5h4v5"/></svg>'
    };

    function programSlugFromLink(link) {
      var slug = String(link.getAttribute('data-program-slug') || '');
      if (slug) return slug;
      try {
        return String(new URL(link.href, window.location.href).searchParams.get('tab') || '');
      } catch (eSlug) {
        return '';
      }
    }

    function collectPrograms() {
      var seen = {};
      var out = [];
      function pushFrom(link, kind) {
        var slug = programSlugFromLink(link);
        if (!slug || seen[slug]) return;
        seen[slug] = true;
        link.dataset.programSlug = slug;
        var labelNode = link.querySelector('.feed-left-nav-label');
        var iconNode = link.querySelector('.feed-left-nav-ic');
        var label = labelNode
          ? labelNode.textContent.trim()
          : String(link.textContent || slug).replace(/\s+/g, ' ').trim();
        var icon = iconNode
          ? iconNode.innerHTML
          : (optionalTabIcons[slug] || '');
        out.push({
          slug: slug,
          label: label,
          icon: icon,
          kind: kind,
          active: link.classList.contains('is-active')
            || slug === activeTab
            || slug === currentDiscoverTab
        });
      }
      Array.prototype.slice.call(document.querySelectorAll('a.feed-discover-tab.feed-program-tab-item')).forEach(function (link) {
        pushFrom(link, 'tab');
      });
      Array.prototype.slice.call(document.querySelectorAll('a.feed-program-nav-item')).forEach(function (link) {
        pushFrom(link, 'nav');
      });
      return out;
    }

    var programs = [];
    var selected = [];

    function normalizeSelected(list) {
      var out = [];
      var seen = {};
      (Array.isArray(list) ? list : []).forEach(function (raw) {
        var slug = String(raw || '').trim().toLowerCase();
        if (!slug || seen[slug]) return;
        // Core home tabs are never "programs".
        if (slug === 'for-you' || slug === 'discover' || slug === 'public') return;
        seen[slug] = true;
        out.push(slug);
      });
      return out;
    }

    function loadSelected() {
      var saved = null;
      try { saved = JSON.parse(localStorage.getItem(storageKey)); } catch (eRead) { saved = null; }
      if (!Array.isArray(saved)) {
        try { saved = JSON.parse(localStorage.getItem('msb.feed.programs.v1.' + viewerKey)); }
        catch (eLegacy) { saved = null; }
      }
      // Keep every saved slug. Do NOT filter against collectPrograms() here:
      // this script boots inside the left rail, before top discover tabs exist.
      // Filtering early used to wipe pinned top tabs after create/repost reloads.
      selected = normalizeSelected(saved);
    }

    function ensureActiveInSelected() {
      programs = collectPrograms();
      programs.forEach(function (program) {
        if (program.active && selected.indexOf(program.slug) === -1) {
          selected.push(program.slug);
        }
      });
      var urlTab = currentDiscoverTab === 'discover' ? 'public' : currentDiscoverTab;
      if (
        urlTab
        && urlTab !== 'for-you'
        && urlTab !== 'public'
        && selected.indexOf(urlTab) === -1
        && document.querySelector('[data-program-slug="' + urlTab + '"]')
      ) {
        selected.push(urlTab);
      }
    }

    function save() {
      try { localStorage.setItem(storageKey, JSON.stringify(selected)); } catch (e) {}
    }
    function isSelected(slug) { return selected.indexOf(slug) !== -1; }

    function syncProgramLinks() {
      programs = collectPrograms();
      Array.prototype.slice.call(document.querySelectorAll('a.feed-discover-tab.feed-program-tab-item')).forEach(function (link) {
        var slug = programSlugFromLink(link);
        if (!slug) return;
        var on = isSelected(slug) || link.classList.contains('is-active') || slug === currentDiscoverTab || slug === activeTab;
        link.hidden = !on;
      });
      Array.prototype.slice.call(document.querySelectorAll('a.feed-program-nav-item')).forEach(function (link) {
        var slug = programSlugFromLink(link);
        if (!slug) return;
        link.hidden = !isSelected(slug);
        var oldRemove = link.querySelector('.feed-program-remove');
        if (oldRemove) oldRemove.remove();
      });
    }

    function restoreProgramPins() {
      try {
        currentDiscoverTab = String(new URL(window.location.href).searchParams.get('tab') || '').toLowerCase();
      } catch (eTab2) {}
      loadSelected();
      ensureActiveInSelected();
      save();
      syncProgramLinks();
    }

    loadSelected();

    function addProgram(slug) {
      slug = String(slug || '').trim().toLowerCase();
      if (!slug || isSelected(slug)) return;
      selected.push(slug);
      save();
      syncProgramLinks();
      renderModal(search ? search.value : '');
    }
    function removeProgram(slug) {
      slug = String(slug || '').trim().toLowerCase();
      if (!slug) return;
      selected = selected.filter(function (item) { return item !== slug; });
      save();
      syncProgramLinks();
      renderModal(search ? search.value : '');
    }

    function makeRow(program, mode) {
      var row = document.createElement('div');
      row.className = 'feed-program-row';
      row.setAttribute('role', 'listitem');

      var identity = document.createElement('div');
      identity.className = 'feed-program-identity';

      var icon = document.createElement('span');
      icon.className = 'feed-program-row-icon';
      icon.setAttribute('aria-hidden', 'true');
      icon.innerHTML = program.icon || optionalTabIcons[program.slug] || fallbackIcon;

      var name = document.createElement('span');
      name.className = 'feed-program-row-name';
      name.textContent = program.label;

      identity.appendChild(icon);
      identity.appendChild(name);
      row.appendChild(identity);

      if (mode === 'available') {
        var addBtn = document.createElement('button');
        addBtn.type = 'button';
        addBtn.className = 'feed-program-add';
        addBtn.setAttribute('aria-label', 'Add ' + program.label);
        addBtn.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>';
        addBtn.addEventListener('click', function () { addProgram(program.slug); });
        row.appendChild(addBtn);
      } else if (mode === 'pinned-edit') {
        var removeBtn = document.createElement('button');
        removeBtn.type = 'button';
        removeBtn.className = 'feed-program-remove';
        removeBtn.setAttribute('aria-label', 'Remove ' + program.label);
        removeBtn.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 12h12"/></svg>';
        removeBtn.addEventListener('click', function () { removeProgram(program.slug); });
        row.appendChild(removeBtn);
      }

      return row;
    }

    function makeSection(title, options) {
      var section = document.createElement('section');
      section.className = 'feed-program-section' + (options.collapsed ? ' is-collapsed' : '');

      var head = document.createElement('div');
      head.className = 'feed-program-section-head';

      var heading = document.createElement('h3');
      heading.className = 'feed-program-section-title';
      heading.textContent = title;
      head.appendChild(heading);

      if (options.edit) {
        var editBtn = document.createElement('button');
        editBtn.type = 'button';
        editBtn.className = 'feed-program-section-edit';
        editBtn.textContent = editPinned ? 'Done' : 'Edit';
        editBtn.addEventListener('click', function () {
          editPinned = !editPinned;
          renderModal(search ? search.value : '');
        });
        head.appendChild(editBtn);
      } else if (options.collapsible) {
        var toggle = document.createElement('button');
        toggle.type = 'button';
        toggle.className = 'feed-program-section-toggle';
        toggle.setAttribute('aria-expanded', options.collapsed ? 'false' : 'true');
        toggle.setAttribute('aria-label', (options.collapsed ? 'Expand ' : 'Collapse ') + title);
        toggle.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 9l6 6 6-6" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
        toggle.addEventListener('click', function () {
          topicsCollapsed = !topicsCollapsed;
          renderModal(search ? search.value : '');
        });
        head.appendChild(toggle);
      }

      section.appendChild(head);

      var body = document.createElement('div');
      body.className = 'feed-program-section-body';
      if (options.hint) {
        var hint = document.createElement('div');
        hint.className = 'feed-program-section-hint';
        hint.textContent = options.hint;
        body.appendChild(hint);
      }
      options.items.forEach(function (program) {
        body.appendChild(makeRow(program, options.mode));
      });
      section.appendChild(body);
      return section;
    }

    function renderModal(query) {
      query = String(query || '').trim().toLowerCase();
      programs = collectPrograms();
      list.innerHTML = '';

      function matches(program) {
        return !query || program.label.toLowerCase().indexOf(query) !== -1;
      }

      var pinned = programs.filter(function (program) {
        return isSelected(program.slug) && matches(program);
      });
      var available = programs.filter(function (program) {
        return !isSelected(program.slug) && matches(program);
      });

      list.appendChild(makeSection('Pinned', {
        edit: true,
        mode: editPinned ? 'pinned-edit' : 'pinned',
        items: pinned,
        hint: pinned.length ? '' : 'Programs you add will show up here.'
      }));

      list.appendChild(makeSection('Topics', {
        collapsible: true,
        collapsed: topicsCollapsed,
        mode: 'available',
        items: available,
        hint: available.length ? '' : (query ? 'No matching programs.' : 'All programs are pinned.')
      }));

      if (empty) empty.hidden = true;
    }

    function openModal(event) {
      if (event) {
        event.preventDefault();
        event.stopPropagation();
      }
      editPinned = false;
      topicsCollapsed = false;
      if (search) search.value = '';
      renderModal('');
      overlay.hidden = false;
      overlay.classList.add('is-open');
      overlay.setAttribute('aria-hidden', 'false');
      window.setTimeout(function () { if (search) search.focus(); }, 0);
    }
    function closeModal() {
      overlay.classList.remove('is-open');
      overlay.hidden = true;
      overlay.setAttribute('aria-hidden', 'true');
      editPinned = false;
    }

    document.addEventListener('click', function (event) {
      var btn = event.target && event.target.closest
        ? event.target.closest('.feed-left-nav-add-program')
        : null;
      if (btn) openModal(event);
    });
    overlay.querySelectorAll('[data-program-close]').forEach(function (button) {
      button.addEventListener('click', function (event) {
        event.preventDefault();
        closeModal();
      });
    });
    overlay.addEventListener('click', function (event) {
      if (event.target === overlay) closeModal();
    });
    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape' && overlay.classList.contains('is-open')) closeModal();
    });
    if (search) search.addEventListener('input', function () { renderModal(search.value); });

    // Left rail boots before top discover tabs exist in the DOM. Restore pins
    // after the full home chrome is parsed so create/repost redirects keep them.
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', restoreProgramPins);
    } else {
      restoreProgramPins();
    }
    document.addEventListener('msb:public-tab-content-ready', function () {
      syncProgramLinks();
    });
    window.addEventListener('popstate', function () {
      window.setTimeout(restoreProgramPins, 0);
    });

    window.MSBFeedPrograms = {
      open: openModal,
      close: closeModal,
      sync: syncProgramLinks,
      restore: restoreProgramPins,
      __ready: true
    };
  })();
  </script>
  <?php endif; ?>
  <?php endif; ?>
<?php if (!$feedLeftRailEmbed): ?></aside>
<script>
(function () {
  function syncAddProgramToAvatar() {
    var avatar = document.querySelector('.feed-ig-rail .feed-ig-avatar');
    if (!avatar) return;
    var y = Math.round(avatar.getBoundingClientRect().top);
    if (y >= 40 && y < 400) {
      document.documentElement.style.setProperty('--feed-left-rail-top', y + 'px');
    }
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', syncAddProgramToAvatar);
  } else {
    syncAddProgramToAvatar();
  }
  window.addEventListener('load', syncAddProgramToAvatar);
  window.addEventListener('resize', syncAddProgramToAvatar);
})();
</script>
<?php endif; ?>
