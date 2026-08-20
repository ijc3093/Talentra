<?php
declare(strict_types=1);

/**
 * Shared Azia chrome: narrow logo rail + top header (search, mail, bell, profile).
 * Used by dashboard and all other admin pages.
 */
require_once __DIR__ . '/session_admin.php';
require_once __DIR__ . '/admin_layout.php';
require_once __DIR__ . '/admin_portal.php';

if (!function_exists('admin_chrome_h')) {
    function admin_chrome_h(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('admin_chrome_initials')) {
    function admin_chrome_initials(string $name): string
    {
        $name = trim(preg_replace('/\s+/', ' ', $name) ?? $name);
        if ($name === '') {
            return 'AD';
        }
        $name = str_replace(['_', '.', '-', '@'], ' ', $name);
        $parts = array_values(array_filter(explode(' ', $name), static fn($p) => trim($p) !== ''));
        if (!$parts) {
            return 'AD';
        }
        $first = mb_strtoupper(mb_substr($parts[0], 0, 1));
        $second = count($parts) > 1
            ? mb_strtoupper(mb_substr($parts[count($parts) - 1], 0, 1))
            : mb_strtoupper(mb_substr($parts[0], 1, 1));
        $ini = trim($first . $second);
        return $ini !== '' ? $ini : 'AD';
    }
}

if (!function_exists('admin_chrome_profile')) {
    /**
     * @return array{
     *   displayName:string,firstName:string,roleLabel:string,initials:string,
     *   avatarWeb:string,feedbackCount:int,notiCount:int
     * }
     */
    function admin_chrome_profile(): array
    {
        static $cached = null;
        if (is_array($cached)) {
            return $cached;
        }

        $roleLabels = [1 => 'Administrator', 2 => 'Manager', 3 => 'Gospel', 4 => 'Staff'];
        $adminRole = (int)($_SESSION['userRole'] ?? 0);
        $displayName = trim((string)($_SESSION['admin_login'] ?? 'Admin'));
        $avatarWeb = '';
        $feedbackCount = 0;
        $notiCount = 0;

        try {
            $dbh = adminDbh();
            $adminId = (int)($_SESSION['admin_id'] ?? 0);
            if ($adminId > 0) {
                $st = $dbh->prepare('SELECT fullname, username, image, role FROM admin WHERE idadmin = :id LIMIT 1');
                $st->execute([':id' => $adminId]);
                $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
                if ($row) {
                    $displayName = trim((string)($row['fullname'] ?? ''));
                    if ($displayName === '') {
                        $displayName = trim((string)($row['username'] ?? $displayName));
                    }
                    $adminRole = (int)($row['role'] ?? $adminRole);
                    if (!empty($row['image'])) {
                        $imgPath = dirname(__DIR__) . '/images/' . $row['image'];
                        if (is_file($imgPath)) {
                            $avatarWeb = 'images/' . $row['image'];
                        }
                    }
                }

                try {
                    $st = $dbh->prepare("SELECT COUNT(*) FROM feedback_admin WHERE receiver = :r");
                    $st->execute([':r' => 'Admin']);
                    $feedbackCount = (int)$st->fetchColumn();
                } catch (Throwable $e) {
                    $feedbackCount = 0;
                }
                try {
                    $st = $dbh->prepare("SELECT COUNT(*) FROM notification WHERE notireceiver = :r");
                    $st->execute([':r' => 'Admin']);
                    $notiCount = (int)$st->fetchColumn();
                } catch (Throwable $e) {
                    $notiCount = 0;
                }
            }
        } catch (Throwable $e) {
            // keep defaults
        }

        if ($displayName === '') {
            $displayName = 'Admin';
        }
        $firstName = explode(' ', $displayName)[0] ?: $displayName;

        $cached = [
            'displayName' => $displayName,
            'firstName' => $firstName,
            'roleLabel' => $roleLabels[$adminRole] ?? 'Admin',
            'initials' => admin_chrome_initials($displayName),
            'avatarWeb' => $avatarWeb,
            'feedbackCount' => $feedbackCount,
            'notiCount' => $notiCount,
        ];
        return $cached;
    }
}

if (!function_exists('admin_chrome_logo')) {
    function admin_chrome_logo(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        ?>
    <div class="sh-logopanel">
      <a href="dashboard.php" class="sh-logo-text azia-brand" title="Admin Panel">Admin Panel</a>
    </div>
        <?php
    }
}

if (!function_exists('admin_chrome_header')) {
    /**
     * @param array{title?:string,crumb?:list<array{0:string,1?:string|null}>,description?:string}|null $pageIntro
     */
    function admin_chrome_header(?string $pageLabel = null, ?array $pageIntro = null): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        $p = admin_chrome_profile();
        $label = trim((string)$pageLabel);
        if ($label === '') {
            $label = 'Admin';
        }
        $subtitle = 'Your admin workspace — ' . $p['firstName'] . '.';
        if ($pageIntro === null && isset($GLOBALS['admin_chrome_page_intro']) && is_array($GLOBALS['admin_chrome_page_intro'])) {
            $pageIntro = $GLOBALS['admin_chrome_page_intro'];
        }
        $introTitle = trim((string)($pageIntro['title'] ?? ''));
        $introDesc = trim((string)($pageIntro['description'] ?? ''));
        $introCrumb = is_array($pageIntro['crumb'] ?? null) ? $pageIntro['crumb'] : [];
        $useIntro = $introTitle !== '' || $introDesc !== '' || $introCrumb !== [];
        ?>
    <div class="sh-headpanel azia-headpanel<?= $useIntro ? ' azia-headpanel-intro' : '' ?>">
      <div class="sh-headpanel-left azia-head-left">
        <?php if ($useIntro): ?>
          <div class="azia-page-intro">
            <?php if ($introTitle !== ''): ?>
              <h1><?= admin_chrome_h($introTitle) ?></h1>
            <?php endif; ?>
            <?php if ($introCrumb !== []): ?>
              <div class="azia-page-crumb">
                <?php
                $crumbParts = [];
                foreach ($introCrumb as $c) {
                    $cLabel = trim((string)($c[0] ?? ''));
                    if ($cLabel === '') {
                        continue;
                    }
                    $cHref = isset($c[1]) ? trim((string)$c[1]) : '';
                    if ($cHref !== '') {
                        $crumbParts[] = '<a href="' . admin_chrome_h($cHref) . '">' . admin_chrome_h($cLabel) . '</a>';
                    } else {
                        $crumbParts[] = '<span>' . admin_chrome_h($cLabel) . '</span>';
                    }
                }
                echo implode('<span class="azia-page-crumb-sep">&gt;</span>', $crumbParts);
                ?>
              </div>
            <?php endif; ?>
            <?php if ($introDesc !== ''): ?>
              <p><?= admin_chrome_h($introDesc) ?></p>
            <?php endif; ?>
          </div>
        <?php else: ?>
          <div class="azia-welcome-copy azia-welcome-in-header">
            <h2><?= admin_chrome_h($label) ?></h2>
            <p><?= admin_chrome_h($subtitle) ?></p>
          </div>
        <?php endif; ?>
      </div>
      <div class="sh-headpanel-right azia-head-right">
        <?php
          // Finance shortcuts (admin only).
          $isAdminChrome = (int)($_SESSION['userRole'] ?? 0) === 1;
          $chromeNavCounts = [
            'stripe_connect' => 0,
            'shop_rent' => 0,
          ];
          if ($isAdminChrome) {
              try {
                  require_once __DIR__ . '/admin_layout.php';
                  if (function_exists('adminDbh') && function_exists('admin_nav_attention_counts')) {
                      $chromeNavCounts = admin_nav_attention_counts(adminDbh());
                  }
              } catch (Throwable $eChromeNav) {
                  $chromeNavCounts = ['stripe_connect' => 0, 'shop_rent' => 0];
              }
              $overdueRent = (int)($chromeNavCounts['shop_rent'] ?? 0);
              $rentTitle = 'Shop Rent' . ($overdueRent > 0 ? ' (' . $overdueRent . ' need attention)' : '');
              $incompleteConnect = (int)($chromeNavCounts['stripe_connect'] ?? 0);
              $connectTitle = 'Stripe Connect' . ($incompleteConnect > 0 ? ' (' . $incompleteConnect . ' incomplete)' : '');
        ?>
        <a href="adminroles.php" class="azia-icon-btn azia-icon-admin-roles" title="List Roles &amp; Accounts" aria-label="List Roles &amp; Accounts">
          <i class="icon ion-ios-person" aria-hidden="true"></i>
          <i class="icon ion-key azia-icon-admin-roles-key" aria-hidden="true"></i>
        </a>
        <a href="userlist.php" class="azia-icon-btn" title="User List" aria-label="User List">
          <i class="icon ion-person-stalker"></i>
        </a>
        <a href="account_search.php" class="azia-icon-btn" title="Account Search" aria-label="Account Search">
          <i class="icon ion-ios-search"></i>
        </a>
        <a href="overview.php" class="azia-icon-btn" title="Overview" aria-label="Overview">
          <i class="icon ion-ios-analytics"></i>
        </a>
        <a href="org_rent.php?filter=overdue" class="azia-icon-btn" title="<?= admin_chrome_h($rentTitle) ?>" aria-label="<?= admin_chrome_h($rentTitle) ?>">
          <i class="icon ion-card"></i>
          <?php if ($overdueRent > 0): ?><span class="azia-count"><?= admin_chrome_h((string)min(99, $overdueRent)) ?></span><?php endif; ?>
        </a>
        <a href="org_stripe_connect.php?filter=incomplete" class="azia-icon-btn" title="<?= admin_chrome_h($connectTitle) ?>" aria-label="<?= admin_chrome_h($connectTitle) ?>">
          <i class="icon ion-social-usd"></i>
          <?php if ($incompleteConnect > 0): ?><span class="azia-count"><?= admin_chrome_h((string)min(99, $incompleteConnect)) ?></span><?php endif; ?>
        </a>
        <a href="service_fees.php" class="azia-icon-btn" title="Service Fees" aria-label="Service Fees">
          <i class="icon ion-cash"></i>
        </a>
        <a href="customer_memberships.php" class="azia-icon-btn" title="Customer Memberships" aria-label="Customer Memberships">
          <i class="icon ion-ribbon-a"></i>
        </a>
        <?php } ?>
        <form class="azia-search" action="account_search.php" method="get" role="search">
          <i class="fa fa-search" aria-hidden="true"></i>
          <input type="search" name="q" placeholder="Search for anything..." aria-label="Search" value="<?= admin_chrome_h((string)($_GET['q'] ?? '')) ?>">
        </form>
        <a href="mailbox.php" class="azia-icon-btn" title="Messages">
          <i class="icon ion-ios-chatboxes-outline"></i>
          <?php if ($p['feedbackCount'] > 0): ?><span class="azia-dot"></span><?php endif; ?>
        </a>
        <a href="notification.php" class="azia-icon-btn" title="Notifications">
          <i class="icon ion-ios-bell-outline"></i>
          <?php if ($p['notiCount'] > 0): ?><span class="azia-dot azia-dot-pink"></span><?php endif; ?>
        </a>
        <div class="dropdown dropdown-profile">
          <a href="" data-toggle="dropdown" class="dropdown-link azia-profile-link">
            <?php if ($p['avatarWeb'] !== ''): ?>
              <img src="<?= admin_chrome_h($p['avatarWeb']) ?>" alt="" class="azia-avatar">
            <?php else: ?>
              <span class="azia-avatar azia-avatar-fallback"><?= admin_chrome_h($p['initials']) ?></span>
            <?php endif; ?>
          </a>
          <div class="dropdown-menu dropdown-menu-right">
            <div class="dropdown-item-text tx-12 tx-color-03"><?= admin_chrome_h($p['displayName']) ?> · <?= admin_chrome_h($p['roleLabel']) ?></div>
            <a class="dropdown-item" href="settings.php">Settings</a>
            <a class="dropdown-item" href="change-password.php">Password</a>
            <a class="dropdown-item" href="logout.php">Sign Out</a>
          </div>
        </div>
      </div>
    </div>
    <?php if ($useIntro): ?>
    <!-- page-intro header styles live in css/admin-ui-scale.css (match service_fees) -->
    <?php endif; ?>
        <?php
    }
}

if (!function_exists('admin_chrome_open')) {
    /**
     * Logo + leftbar + header (standard shell order).
     *
     * @param array{title?:string,crumb?:list<array{0:string,1?:string|null}>,description?:string}|null $pageIntro
     */
    function admin_chrome_open(?string $pageLabel = null, ?array $pageIntro = null): void
    {
        admin_chrome_logo();
        include __DIR__ . '/leftbar.php';
        admin_chrome_header($pageLabel, $pageIntro);
    }
}
