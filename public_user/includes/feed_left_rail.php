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
    <a class="feed-left-nav-item<?= $flrActive('entertainment') ?>" href="public.php?tab=entertainment">
      <span class="feed-left-nav-ic" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M8 3v18"/><path d="M16 3v18"/><path d="M3 8h18"/><path d="M3 16h18"/><rect x="3" y="3" width="18" height="18" rx="2"/></svg></span>
      <span class="feed-left-nav-label">Entertainment</span>
    </a>
    <a class="feed-left-nav-item<?= $flrActive('library') ?>" href="public.php?tab=library">
      <span class="feed-left-nav-ic" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 5.5A2.5 2.5 0 0 1 6.5 3H11v16H6.5A2.5 2.5 0 0 0 4 21.5z"/><path d="M20 5.5A2.5 2.5 0 0 0 17.5 3H13v16h4.5a2.5 2.5 0 0 1 2.5 2.5z"/></svg></span>
      <span class="feed-left-nav-label">Library</span>
    </a>
    <a class="feed-left-nav-item<?= $flrActive('cook') ?>" href="public.php?tab=cook">
      <span class="feed-left-nav-ic" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M6 3v7"/><path d="M3.5 3v4.5A2.5 2.5 0 0 0 6 10"/><path d="M8.5 3v4.5A2.5 2.5 0 0 1 6 10v11"/><path d="M15 3v18"/><path d="M15 3a5 5 0 0 1 5 5v4h-5"/></svg></span>
      <span class="feed-left-nav-label">Cook</span>
    </a>
    <a class="feed-left-nav-item<?= $flrActive('seek-around-the-world') ?>" href="public.php?tab=seek-around-the-world">
      <span class="feed-left-nav-ic" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M3 12h18"/><path d="M12 3a14 14 0 0 1 0 18"/><path d="M12 3a14 14 0 0 0 0 18"/></svg></span>
      <span class="feed-left-nav-label">Seek around the World</span>
    </a>
    <a class="feed-left-nav-item<?= $flrActive('geology') ?>" href="public.php?tab=geology">
      <span class="feed-left-nav-ic" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="m3 20 6.5-11 3 5 2.5-4 6 10z"/><path d="m7.8 12 1.7 1.5 1.5-2"/><path d="M3 20h18"/></svg></span>
      <span class="feed-left-nav-label">Geology</span>
    </a>
    <a class="feed-left-nav-item<?= $flrActive('animation') ?>" href="public.php?tab=animation">
      <span class="feed-left-nav-ic" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m10 9 5 3-5 3z"/><path d="M7 5v14"/><path d="M17 5v14"/></svg></span>
      <span class="feed-left-nav-label">Animation</span>
    </a>
    <a class="feed-left-nav-item<?= $flrActive('make-a-new-friend') ?>" href="public.php?tab=make-a-new-friend">
      <span class="feed-left-nav-ic" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/></svg></span>
      <span class="feed-left-nav-label">Make a new Friend</span>
    </a>
    <a class="feed-left-nav-item<?= $flrActive('agents') ?>" href="public.php?tab=agents">
      <span class="feed-left-nav-ic" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="5" y="8" width="14" height="10" rx="3"/><path d="M9 8V6a3 3 0 0 1 6 0v2"/><circle cx="10" cy="13" r="1"/><circle cx="14" cy="13" r="1"/><path d="M10 16h4"/></svg></span>
      <span class="feed-left-nav-label">Agents</span>
      <span class="feed-left-nav-badge">NEW</span>
    </a>
    <a class="feed-left-nav-item<?= $flrActive('deep-research') ?>" href="public.php?tab=deep-research">
      <span class="feed-left-nav-ic" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M3 20l6-6"/><path d="M14 4l6 6"/><path d="M9 15l-2 5 5-2 8-8-3-3-8 8z"/><circle cx="18" cy="6" r="2"/></svg></span>
      <span class="feed-left-nav-label">Deep research</span>
    </a>
    <?php if (function_exists('publisher_academic_categories') && function_exists('publisher_category_icon_path')): ?>
    <?php foreach (publisher_academic_categories() as $categorySlug => $categoryLabel): ?>
    <a class="feed-left-nav-item<?= $flrActive($categorySlug) ?>" href="public.php?tab=<?= htmlspecialchars($categorySlug, ENT_QUOTES, 'UTF-8') ?>">
      <span class="feed-left-nav-ic" aria-hidden="true"><svg viewBox="0 0 24 24"><?= publisher_category_icon_path($categorySlug) ?></svg></span>
      <span class="feed-left-nav-label"><?= htmlspecialchars($categoryLabel, ENT_QUOTES, 'UTF-8') ?></span>
    </a>
    <?php endforeach; ?>
    <?php endif; ?>
    <?php if (!empty($feedLeftRailShopFilters)): ?>
      <?php include __DIR__ . '/feed_shop_brand_nav.php'; ?>
      <?php include __DIR__ . '/feed_shop_nav_filters.php'; ?>
    <?php endif; ?>
    <?php endif; ?>
  </nav>
  <?php if (!$feedLeftRailShopOnly): ?>
  <div class="feed-left-rail-footer" aria-label="Sidebar actions">
    <button class="feed-left-nav-item feed-left-nav-add-program" type="button" id="feedProgramManagerOpen" aria-label="Add Program" aria-haspopup="dialog">
      <span class="feed-left-nav-ic" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 8v8"/><path d="M8 12h8"/></svg></span>
      <span class="feed-left-nav-label">Add Program</span>
    </button>
    <a class="feed-left-nav-item" href="logout.php">
      <span class="feed-left-nav-ic" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M10 7V5a2 2 0 0 1 2-2h7v18h-7a2 2 0 0 1-2-2v-2"/><path d="M15 12H3"/><path d="M6 9l-3 3 3 3"/></svg></span>
      <span class="feed-left-nav-label">Sign Out</span>
    </a>
  </div>
  <dialog class="feed-program-dialog" id="feedProgramManager" aria-labelledby="feedProgramManagerTitle">
    <div class="feed-program-dialog-head">
      <div>
        <h2 id="feedProgramManagerTitle">Add Program</h2>
        <p>Choose programs to show in your navigation.</p>
      </div>
      <button type="button" class="feed-program-dialog-close" data-program-close aria-label="Close">&times;</button>
    </div>
    <label class="feed-program-search">
      <span class="feed-program-search-icon" aria-hidden="true">⌕</span>
      <input type="search" id="feedProgramSearch" placeholder="Search programs" autocomplete="off">
    </label>
    <div class="feed-program-list" id="feedProgramList" role="list"></div>
    <div class="feed-program-empty" id="feedProgramEmpty" hidden>No programs found.</div>
    <div class="feed-program-dialog-actions">
      <button type="button" class="feed-program-done" data-program-close>Done</button>
    </div>
  </dialog>
  <style id="feed-program-manager-styles">
    .feed-left-nav-add-program{width:100%;border:0;background:transparent;font:inherit;text-align:left;cursor:pointer;color:inherit}
    .feed-program-nav-item[hidden]{display:none!important}
    .feed-program-nav-item{position:relative;padding-right:42px!important}
    .feed-program-remove{position:absolute;right:8px;top:50%;width:26px;height:26px;transform:translateY(-50%);display:inline-flex;align-items:center;justify-content:center;border-radius:999px;color:inherit;font-size:20px;font-weight:400;line-height:1;opacity:.62;cursor:pointer}
    .feed-program-remove:hover,.feed-program-remove:focus-visible{background:rgba(127,127,127,.16);opacity:1;outline:none}
    .feed-program-dialog{width:min(540px,calc(100vw - 32px));max-height:min(720px,calc(100dvh - 32px));margin:auto;padding:0;border:1px solid var(--msb-palette-border,#d0d5dd);border-radius:22px;background:var(--msb-palette-surface-2,#fff);color:var(--msb-palette-text,#101828);box-shadow:0 24px 70px rgba(15,23,42,.28);overflow:hidden}
    .feed-program-dialog::backdrop{background:rgba(15,23,42,.52);backdrop-filter:blur(5px)}
    .feed-program-dialog-head{display:flex;align-items:flex-start;justify-content:space-between;gap:18px;padding:24px 24px 14px}
    .feed-program-dialog-head h2{margin:0;font:700 24px/1.2 "Roboto","Helvetica Neue",Arial,sans-serif;color:inherit}
    .feed-program-dialog-head p{margin:6px 0 0;color:var(--msb-palette-muted,#667085);font:400 14px/1.45 "Roboto","Helvetica Neue",Arial,sans-serif}
    .feed-program-dialog-close{flex:0 0 36px;width:36px;height:36px;border:1px solid var(--msb-palette-border,#d0d5dd);border-radius:50%;background:transparent;color:inherit;font-size:25px;line-height:1;cursor:pointer}
    .feed-program-dialog-close:hover{background:rgba(127,127,127,.12)}
    .feed-program-search{display:flex;align-items:center;gap:10px;margin:0 24px 16px;padding:0 14px;height:46px;border:1px solid var(--msb-palette-border,#d0d5dd);border-radius:14px;background:var(--msb-palette-surface,#fff)}
    .feed-program-search-icon{font-size:24px;color:var(--msb-palette-muted,#667085)}
    .feed-program-search input{width:100%;border:0;outline:0;background:transparent;color:inherit;font:400 15px/1.2 "Roboto","Helvetica Neue",Arial,sans-serif}
    .feed-program-list{max-height:min(430px,52dvh);padding:0 14px;overflow:auto;overscroll-behavior:contain}
    .feed-program-row{display:flex;align-items:center;justify-content:space-between;gap:16px;min-height:58px;padding:8px 10px;border-bottom:1px solid var(--msb-palette-border,#eaecf0)}
    .feed-program-row:last-child{border-bottom:0}
    .feed-program-identity{display:flex;align-items:center;gap:13px;min-width:0}
    .feed-program-row-icon{flex:0 0 24px;width:24px;height:24px;display:inline-flex;align-items:center;justify-content:center;color:inherit}
    .feed-program-row-icon svg{width:20px;height:20px;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round}
    .feed-program-row-name{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font:600 15px/1.25 "Roboto","Helvetica Neue",Arial,sans-serif}
    .feed-program-toggle{flex:0 0 auto;min-width:78px;height:36px;padding:0 16px;border:1px solid var(--feed-accent,#1d9bf0);border-radius:999px;background:var(--feed-accent,#1d9bf0);color:#fff;font:700 13px/1 "Roboto","Helvetica Neue",Arial,sans-serif;cursor:pointer}
    .feed-program-toggle.is-added{border-color:var(--msb-palette-border,#d0d5dd);background:transparent;color:inherit}
    .feed-program-empty{padding:34px 24px;text-align:center;color:var(--msb-palette-muted,#667085);font:500 14px/1.4 "Roboto","Helvetica Neue",Arial,sans-serif}
    .feed-program-dialog-actions{padding:16px 24px 22px;border-top:1px solid var(--msb-palette-border,#eaecf0)}
    .feed-program-done{width:100%;height:44px;border:0;border-radius:999px;background:var(--feed-accent,#1d9bf0);color:#fff;font:700 15px/1 "Roboto","Helvetica Neue",Arial,sans-serif;cursor:pointer}
    @media(max-width:640px){.feed-program-dialog{width:calc(100vw - 20px);border-radius:18px}.feed-program-dialog-head{padding:20px 18px 12px}.feed-program-search{margin:0 18px 12px}.feed-program-list{padding:0 8px}.feed-program-dialog-actions{padding:14px 18px 18px}}
  </style>
  <?php endif; ?>
<?php if (!$feedLeftRailEmbed): ?></aside><?php endif; ?>
<?php if (!$feedLeftRailShopOnly): ?>
<script>
(function () {
  var rail = document.currentScript && document.currentScript.previousElementSibling;
  var nav = document.querySelector('.feed-left-rail .feed-left-nav, .feed-left-nav');
  var dialog = document.getElementById('feedProgramManager');
  var openButton = document.getElementById('feedProgramManagerOpen');
  var list = document.getElementById('feedProgramList');
  var search = document.getElementById('feedProgramSearch');
  var empty = document.getElementById('feedProgramEmpty');
  if (!nav || !dialog || !openButton || !list) return;

  var viewerKey = <?= json_encode((string)($flrMeId ?: 'guest'), JSON_UNESCAPED_SLASHES) ?>;
  var storageKey = 'msb.feed.programs.v1.' + viewerKey;
  var links = Array.prototype.filter.call(nav.querySelectorAll('a.feed-left-nav-item[href*="public.php?tab="]'), function (link) {
    return !link.classList.contains('feed-left-nav-item-publisher');
  });
  var programs = links.map(function (link) {
    var url = new URL(link.href, window.location.href);
    var slug = url.searchParams.get('tab') || '';
    var labelNode = link.querySelector('.feed-left-nav-label');
    var iconNode = link.querySelector('.feed-left-nav-ic');
    link.dataset.programSlug = slug;
    link.classList.add('feed-program-nav-item');
    return { slug: slug, label: labelNode ? labelNode.textContent.trim() : slug, link: link, icon: iconNode ? iconNode.innerHTML : '' };
  }).filter(function (item) { return item.slug; });
  if (!programs.length) return;

  var defaultSlugs = programs.slice(0, Math.min(7, programs.length)).map(function (item) { return item.slug; });
  var selected;
  try {
    var saved = JSON.parse(localStorage.getItem(storageKey));
    selected = Array.isArray(saved) ? saved.filter(function (slug) { return programs.some(function (p) { return p.slug === slug; }); }) : defaultSlugs;
  } catch (e) { selected = defaultSlugs; }

  function save() {
    try { localStorage.setItem(storageKey, JSON.stringify(selected)); } catch (e) {}
  }
  function isSelected(slug) { return selected.indexOf(slug) !== -1; }
  function renderNav() {
    programs.forEach(function (program) {
      program.link.hidden = !isSelected(program.slug);
      var oldRemove = program.link.querySelector('.feed-program-remove');
      if (oldRemove) oldRemove.remove();
      if (isSelected(program.slug)) {
        var remove = document.createElement('span');
        remove.className = 'feed-program-remove';
        remove.setAttribute('role', 'button');
        remove.setAttribute('tabindex', '0');
        remove.setAttribute('aria-label', 'Remove ' + program.label + ' from navigation');
        remove.title = 'Remove from navigation';
        remove.textContent = '×';
        remove.addEventListener('click', function (event) {
          event.preventDefault(); event.stopPropagation();
          selected = selected.filter(function (slug) { return slug !== program.slug; });
          save(); renderNav(); renderModal(search ? search.value : '');
        });
        remove.addEventListener('keydown', function (event) {
          if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            remove.click();
          }
        });
        program.link.appendChild(remove);
      }
    });
  }
  function renderModal(query) {
    query = String(query || '').trim().toLowerCase();
    list.innerHTML = '';
    var shown = 0;
    programs.forEach(function (program) {
      if (query && program.label.toLowerCase().indexOf(query) === -1) return;
      shown++;
      var row = document.createElement('div');
      row.className = 'feed-program-row'; row.setAttribute('role', 'listitem');
      var identity = document.createElement('div'); identity.className = 'feed-program-identity';
      var icon = document.createElement('span'); icon.className = 'feed-program-row-icon'; icon.innerHTML = program.icon;
      var name = document.createElement('span'); name.className = 'feed-program-row-name'; name.textContent = program.label;
      var action = document.createElement('button'); action.type = 'button';
      action.className = 'feed-program-toggle' + (isSelected(program.slug) ? ' is-added' : '');
      action.textContent = isSelected(program.slug) ? 'Remove' : 'Add';
      action.setAttribute('aria-label', action.textContent + ' ' + program.label);
      action.addEventListener('click', function () {
        if (isSelected(program.slug)) selected = selected.filter(function (slug) { return slug !== program.slug; });
        else selected.push(program.slug);
        save(); renderNav(); renderModal(search ? search.value : '');
      });
      identity.appendChild(icon); identity.appendChild(name); row.appendChild(identity); row.appendChild(action); list.appendChild(row);
    });
    if (empty) empty.hidden = shown !== 0;
  }
  openButton.addEventListener('click', function () {
    renderModal(''); if (search) search.value = '';
    if (typeof dialog.showModal === 'function') dialog.showModal(); else dialog.setAttribute('open', '');
    window.setTimeout(function () { if (search) search.focus(); }, 0);
  });
  dialog.querySelectorAll('[data-program-close]').forEach(function (button) { button.addEventListener('click', function () { dialog.close(); }); });
  dialog.addEventListener('click', function (event) { if (event.target === dialog) dialog.close(); });
  if (search) search.addEventListener('input', function () { renderModal(search.value); });
  renderNav();
})();
</script>
<?php endif; ?>
