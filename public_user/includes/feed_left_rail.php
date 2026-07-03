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
?>
<?php if (!$feedLeftRailEmbed): ?><aside class="feed-left-rail" aria-label="Main navigation"><?php endif; ?>
  <nav class="feed-left-nav" aria-label="Sidebar menu">
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
    </a>
    <?php endif; ?>
    <a class="feed-left-nav-item" href="logout.php">
      <span class="feed-left-nav-ic" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M10 7V5a2 2 0 0 1 2-2h7v18h-7a2 2 0 0 1-2-2v-2"/><path d="M15 12H3"/><path d="M6 9l-3 3 3 3"/></svg></span>
      <span class="feed-left-nav-label">Sign Out</span>
    </a>
  </nav>
<?php if (!$feedLeftRailEmbed): ?></aside><?php endif; ?>
