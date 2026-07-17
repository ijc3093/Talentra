<?php
// /Business_only3/organization/includes/header.php
declare(strict_types=1);

require_once __DIR__ . '/org_context.php';
require_once __DIR__ . '/org_publisher_access.php';
require_once __DIR__ . '/org_theme_prefs.php';
require_once __DIR__ . '/org_layout.php';
require_once __DIR__ . '/org_header_counts.php';

$isManager = isOrgManager();

if (!function_exists('h')) {
  function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}

// ✅ One-time flash message (NO floating toast — keeps UI clean)
$orgFlash = trim((string)($_SESSION['org_flash'] ?? ''));
if ($orgFlash !== '') {
  unset($_SESSION['org_flash']);
}

// Load current account display info
$displayName = '';
$displayEmail = '';
try {
  if ($isManager) {
      $st = $dbh->prepare("SELECT fullname, email FROM managers WHERE id = :id LIMIT 1");
      $st->execute([':id' => orgAccountId()]);
      $u = $st->fetch(PDO::FETCH_ASSOC) ?: [];
      $displayName = trim((string)($u['fullname'] ?? 'Manager'));
      $displayEmail = trim((string)($u['email'] ?? ''));
  } else {
      $st = $dbh->prepare("SELECT fullname, email FROM staff_accounts WHERE id = :id LIMIT 1");
      $st->execute([':id' => orgAccountId()]);
      $u = $st->fetch(PDO::FETCH_ASSOC) ?: [];
      $displayName = trim((string)($u['fullname'] ?? 'Staff'));
      $displayEmail = trim((string)($u['email'] ?? ''));
  }
} catch (Throwable $e) { /* ignore */ }

if ($displayName === '') $displayName = $isManager ? 'Manager' : 'Staff';

require_once dirname(__DIR__, 2) . '/public_user/includes/account_display_helpers.php';
$orgHeaderChrome = account_display_name_parts($displayName, true, $dbh);
$orgPortalRoleBadge = trim((string)($orgHeaderChrome['badge'] ?? ''));
$displayName = (string)($orgHeaderChrome['display_name'] ?? $displayName);

// Theme CSS variables — org canvas locked to public_user dark (#171d24)
$bg = '';
$accent = $ORG_THEME_ACCENT !== '' ? $ORG_THEME_ACCENT : '#4f46e5';
$fontSz = $ORG_FONT_SIZE !== '' ? $ORG_FONT_SIZE : '12px';

if (!function_exists('org_accent_button_text')) {
  function org_accent_button_text(string $hex): string {
    $hex = ltrim(trim($hex), '#');
    if ($hex === '') {
      return '#ffffff';
    }
    if (strlen($hex) === 3) {
      $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }
    if (!preg_match('/^[0-9a-fA-F]{6}$/', $hex)) {
      return '#ffffff';
    }
    $r = hexdec(substr($hex, 0, 2));
    $g = hexdec(substr($hex, 2, 2));
    $b = hexdec(substr($hex, 4, 2));
    $luminance = (0.299 * $r + 0.587 * $g + 0.114 * $b) / 255;
    return $luminance > 0.55 ? '#0f172a' : '#ffffff';
  }
}
$accentBtnText = org_accent_button_text($accent);

// --- Unread badge counts (messages + feed updates + sales attention) ---
$unreadCount = 0;
$feedUnreadCount = 0;
$salesAttentionCount = 0;
$salesAttentionBreakdown = [
    'orders' => 0,
    'delivery' => 0,
    'products' => 0,
    'customers' => 0,
    'returns' => 0,
    'notification' => 0,
];
try {
    if (function_exists('orgMemberId')) {
        $meMid = (int)orgMemberId();
        $orgId = (int)($ORG['id'] ?? 0);

        if ($meMid > 0 && $orgId > 0) {
            $unreadCount = org_header_message_unread_count($dbh, $orgId, $meMid);
            $feedUnreadCount = org_header_feed_unread_count($dbh, $orgId, $meMid);
        }
        if ($orgId > 0 && $isManager) {
            require_once __DIR__ . '/org_manager_guard.php';
            if (org_active_is_commerce_seller($dbh)) {
                require_once __DIR__ . '/org_sales.php';
                $salesAttentionBreakdown = org_sales_attention_counts($dbh, $orgId);
                $salesAttentionCount = (int)($salesAttentionBreakdown['total'] ?? 0);
            }
        }
    }
} catch (Throwable $e) {
    $unreadCount = 0;
    $feedUnreadCount = 0;
    $salesAttentionCount = 0;
}

// ✅ Active org display
$activeOrgName = trim((string)($ORG['name'] ?? ''));
$activeOrgName = account_org_display_label($activeOrgName);
$activeOrgCode = trim((string)($ORG['org_code'] ?? ''));
$activeOrgId   = (int)($ORG['id'] ?? 0);

// ✅ Manager org quick switch list (same UI, just dropdown)
$orgChoices = [];
if ($isManager) {
  try {
    $mid = (int)orgAccountId();
    if ($mid > 0) {
      $orgChoices = org_manager_accessible_orgs($dbh, $mid);
      if (count($orgChoices) > 10) {
        $orgChoices = array_slice($orgChoices, 0, 10);
      }
    }
  } catch (Throwable $e) {
    $orgChoices = [];
  }
}
?>

<?php if (!org_theme_head_was_printed()) { org_theme_print_head_bootstrap($dbh); } ?>
<?php if (!org_theme_css_was_printed()): ?>
<link rel="stylesheet" href="../css/dark-auto.css?v=23">
<?php endif; ?>

<style>
  :root{
    --org-bg: var(--org-page-bg, #171d24);
    --org-accent: var(--msb-palette-action, <?= h($accent) ?>);
    --org-btn-on-accent: var(--msb-palette-btn-text, <?= h($accentBtnText) ?>);
    --org-btn-filled-bg: var(--msb-palette-btn-bg, var(--org-accent, <?= h($accent) ?>));
    --org-btn-filled-text: var(--msb-palette-btn-text, var(--org-btn-on-accent, <?= h($accentBtnText) ?>));
    --org-font: <?= h($fontSz) ?>;
  }
  html:not([data-msb-appearance]):not(.msb-palette-active) body.org-app{
    background: var(--org-page-bg, #171d24) !important;
    font-size: var(--org-font);
  }
  html[data-msb-appearance] body.org-app,
  html.msb-palette-active body.org-app{
    background-color: var(--msb-palette-bg) !important;
    background-image: none !important;
    font-size: var(--org-font);
  }
  html[data-msb-org-light]:not(.dark-auto) body.org-app{
    background-color: #ffffff !important;
    background-image: none !important;
    font-size: var(--org-font);
  }
  html.dark-auto[data-msb-org-light] body.org-app{
    background-color: var(--org-page-bg, #171d24) !important;
    background-image: none !important;
    font-size: var(--org-font);
  }
  .sh-logo-text{ text-transform: none !important; }
  .org-logo-label{
    font-size: 18px;
    font-weight: 600;
    font-family: cursive;
    line-height: 1.25;
    color: var(--msb-palette-text-on-nav, var(--msb-palette-action, var(--org-accent, #4f46e5)));
    max-width: 140px;
    word-break: break-word;
  }

  /* Legacy peer-list pills on messages page */
  .msg-badge{
    position:absolute;
    top:-6px;
    right:-8px;
    min-width:18px;
    height:18px;
    padding:0 5px;
    border-radius:999px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    font-size:11px;
    line-height:1;
    background:#dc3545;
    color:#fff;
    font-weight:700;
    border:2px solid #171d24;
  }

  @keyframes badgePulse {
    0%   { transform: scale(1);   box-shadow: 0 0 0 0 rgba(220,53,69,.55); }
    70%  { transform: scale(1.08); box-shadow: 0 0 0 10px rgba(220,53,69,0); }
    100% { transform: scale(1);   box-shadow: 0 0 0 0 rgba(220,53,69,0); }
  }
  .msg-badge.pulse{ animation: badgePulse 1.3s infinite; }

  @keyframes orgHeaderSalesBadgeAlert {
    0%, 100% {
      transform: scale(1) translate(0, 0);
      box-shadow: 0 0 0 0 rgba(220, 53, 69, 0.55);
    }
    12% {
      transform: scale(1.18) translate(0, -1px);
      box-shadow: 0 0 0 6px rgba(220, 53, 69, 0);
    }
    24% {
      transform: scale(1.08) translate(0, 0);
      box-shadow: 0 0 0 2px rgba(220, 53, 69, 0.25);
    }
    36% {
      transform: scale(1.14) translate(0, -1px);
      box-shadow: 0 0 0 5px rgba(220, 53, 69, 0);
    }
    50%, 78% {
      transform: scale(1) translate(0, 0);
      box-shadow: 0 0 0 1px rgba(220, 53, 69, 0.35);
    }
    82% {
      transform: scale(1.1) translate(-2px, 0);
      box-shadow: 0 0 0 4px rgba(220, 53, 69, 0.15);
    }
    86% {
      transform: scale(1.1) translate(2px, 0);
    }
    90% {
      transform: scale(1.06) translate(-1px, 0);
    }
    94% {
      transform: scale(1.04) translate(1px, 0);
    }
  }

  @keyframes badgePop {0% { transform: scale(1); }40% { transform: scale(1.25); }100% { transform: scale(1); }}
  .msg-badge.pop{ animation: badgePop .35s ease; }

  /* ✅ Org switch pill — styled to match your screenshot */
  .org-pill{
    display:inline-flex;
    align-items:center;
    gap:5px;
    padding:4px 10px;
    border-radius:999px;
    background: rgba(255,255,255,.14);
    border:1px solid rgba(255,255,255,.20);
    color:#fff !important;
    font-weight:700;
    font-size:11px;
    line-height:1;
    white-space:nowrap;
    text-decoration:none !important;
  }
  .org-pill small{ opacity:.95; font-weight:700; }

  .org-pill .caret{
    opacity:.95;
    font-size:12px;
    margin-left:2px;
  }
  .org-header-context{
    display:inline-flex;
    flex-direction:row;
    align-items:center;
    justify-content:center;
    gap:12px;
    margin-right:12px;
    min-width:0;
  }
  .org-header-page-title{
    position:relative;
    color:var(--msb-palette-text, #111827);
    font-size:13px;
    font-weight:800;
    line-height:1;
    white-space:nowrap;
    margin-top:0;
    text-decoration:none !important;
    display:inline-flex;
    align-items:center;
    gap:6px;
  }
  .org-header-page-title:hover,
  .org-header-page-title:focus{
    color:var(--msb-palette-action, var(--org-accent, #2563eb));
    text-decoration:none !important;
  }
  .org-header-sales-badge{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    min-width:18px;
    height:18px;
    padding:0 5px;
    border-radius:999px;
    background:#dc3545 !important;
    color:#ffffff !important;
    font-size:10px;
    font-weight:800;
    line-height:1;
    border:2px solid var(--msb-palette-nav-bg, #171d24);
    box-shadow:0 0 0 1px rgba(220,53,69,.25);
    transform-origin:center center;
  }
  .org-header-sales-badge.is-pulse,
  .org-header-sales-badge.is-alert{
    animation: orgHeaderSalesBadgeAlert 2.4s ease-in-out infinite;
  }
  @media (prefers-reduced-motion: reduce){
    .org-header-sales-badge.is-pulse,
    .org-header-sales-badge.is-alert{
      animation: none;
      box-shadow: 0 0 0 3px rgba(220, 53, 69, 0.35);
    }
  }
  html[data-msb-appearance] body.org-app .org-header-page-title,
  html.msb-palette-active body.org-app .org-header-page-title{
    color:var(--msb-palette-text) !important;
  }

  /* 7-day feed stats in blue header (feed page) */
  .header-feed-stats{
    display:inline-flex;
    align-items:center;
    gap:6px;
    flex-wrap:wrap;
    margin-right:10px;
  }
  .sh-headpanel-right .header-feed-stats .stat-pill{
    display:inline-flex;
    align-items:center;
    gap:5px;
    padding:3px 8px;
    border-radius:999px;
    background:rgba(255,255,255,.14);
    border:1px solid rgba(255,255,255,.20);
    color:#fff;
    font-size:11px;
    line-height:1;
    white-space:nowrap;
    box-shadow:none;
  }
  .sh-headpanel-right .header-feed-stats .stat-pill .icon{
    width:18px;
    height:18px;
    border-radius:999px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    background:rgba(255,255,255,.18);
    color:#fff;
    font-size:10px;
  }
  .sh-headpanel-right .header-feed-stats .stat-pill .num,
  .sh-headpanel-right .header-feed-stats .stat-pill .lbl,
  .sh-headpanel-right .header-feed-stats .stat-pill .sub{
    color:#fff !important;
    font-weight:700;
  }
  .sh-headpanel-right .header-feed-stats .stat-pill .sub{
    opacity:.85;
    margin-left:2px;
    font-size:10px;
  }

  .header-feed-tabs{
    display:inline-flex;
    align-items:center;
    gap:6px;
    flex-wrap:wrap;
    margin-right:10px;
  }
  .header-feed-tab{
    display:inline-flex;
    align-items:center;
    padding:3px 10px;
    border-radius:999px;
    background:rgba(255,255,255,.14);
    border:1px solid rgba(255,255,255,.20);
    color:#fff !important;
    font-weight:700;
    font-size:11px;
    line-height:1;
    text-decoration:none !important;
    white-space:nowrap;
  }
  .header-feed-tab:hover{
    background:rgba(255,255,255,.22);
    color:#fff !important;
  }
  .header-feed-tab.active{
    background:#fff;
    color:#0b5cab !important;
    border-color:#fff;
  }
  .sh-headpanel-right .header-feed-unread.stat-pill{
    display:inline-flex;
    align-items:center;
    gap:5px;
    padding:3px 8px;
    border-radius:999px;
    background:rgba(255,255,255,.14);
    border:1px solid rgba(255,255,255,.20);
    color:#fff;
    font-size:11px;
    line-height:1;
    white-space:nowrap;
    box-shadow:none;
  }
  .sh-headpanel-right .header-feed-unread .icon{
    width:18px;
    height:18px;
    border-radius:999px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    background:rgba(255,255,255,.18);
    color:#fff;
    font-size:10px;
  }
  .sh-headpanel-right .header-feed-unread .num,
  .sh-headpanel-right .header-feed-unread .lbl{
    color:#fff !important;
    font-weight:700;
  }

  /* Keep dropdown menu readable */
  .dropdown-menu .dropdown-item{
    font-weight:600;
  }

  /* Tiny inline flash under header (does not affect layout badly) */
  .org-inline-flash{
    position: fixed;
    top: 76px; /* sits just under header bar */
    left: 50%;
    transform: translateX(-50%);
    z-index: 9999;
    max-width: 520px;
    width: calc(100% - 30px);
    padding: 10px 12px;
    border-radius: 10px;
    background: rgba(40,167,69,.96);
    color: #fff;
    font-weight: 800;
    box-shadow: 0 10px 25px rgba(0,0,0,.20);
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:10px;
  }
  .org-inline-flash .x{
    cursor:pointer;
    opacity:.9;
    font-size:18px;
    line-height:1;
  }
  .sh-headpanel-right .ig-feed-account-badge{
    display:inline-flex;
    align-items:center;
    max-width:min(22vw, 160px);
    min-height:32px;
    padding:0 10px;
    border-radius:999px;
    font-size:12px;
    font-weight:700;
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis;
    flex-shrink:1;
    background:rgba(255,255,255,.14);
    color:#fff;
  }
</style>

<?php
  $logoText  = trim((string)($ORG_SETTINGS['logo_text'] ?? ''));
  if ($logoText === '') {
    $logoText = (string)($ORG['name'] ?? 'Organization');
  }
  $logoParts = account_display_name_parts($logoText, true, $dbh, false);
  $logoText = (string)($logoParts['display_name'] ?? $logoText);
  $logoIcon  = (string)($ORG_SETTINGS['logo_icon'] ?? 'ion-ios-briefcase');
?>

<?php if ($orgFlash !== ''): ?>
  <div class="org-inline-flash" id="orgInlineFlash">
    <div><i class="icon ion-checkmark-circled" style="margin-right:6px;"></i><?= h($orgFlash) ?></div>
    <div class="x" onclick="document.getElementById('orgInlineFlash').remove()">&times;</div>
  </div>
  <script>
    (function(){
      var el = document.getElementById('orgInlineFlash');
      if (!el) return;
      setTimeout(function(){ if(el && el.parentNode) el.remove(); }, 3500);
    })();
  </script>
<?php endif; ?>

<div class="sh-logopanel" style="padding: 10px;">
  <a href="publisher_public_enter.php?to=feed" class="sh-logo-text" style="display:flex;align-items:center;gap:1px;">
   
    <span class="org-logo-label"><?= h($logoText) ?></span>
  </a>

  <!-- <a id="navicon" href="" class="sh-navicon d-none d-xl-block"><i class="icon ion-navicon"></i></a> -->
  <a id="naviconMobile" href="" class="sh-navicon d-xl-none"><i class="icon ion-navicon"></i></a>
</div>

<script>
// Live header inbox + feed alert chips
(function(){
  var pollMs = 5000;

  function formatCount(n) {
    n = parseInt(n, 10) || 0;
    if (n <= 0) return '';
    return n > 99 ? '99+' : String(n);
  }

  function updateChip(chipSelector, badgeId, count) {
    var chip = document.querySelector(chipSelector);
    if (!chip) return;

    var badge = document.getElementById(badgeId);
    var label = formatCount(count);
    var hasUnread = count > 0;

    chip.classList.toggle('has-unread', hasUnread);

    if (!badge) {
      badge = chip.querySelector('.org-header-chip__count');
      if (badge) badge.id = badgeId;
    }
    if (!badge) return;

    if (!hasUnread) {
      badge.textContent = '';
      badge.classList.remove('is-visible', 'pop');
      return;
    }

    if (badge.textContent !== label) {
      badge.textContent = label;
      badge.classList.add('is-visible');
      badge.classList.remove('pop');
      void badge.offsetWidth;
      badge.classList.add('pop');
      return;
    }

    badge.classList.add('is-visible');
  }

  async function poll(){
    try {
      var res = await fetch('ajax/ajax_unread_counts.php', { credentials: 'same-origin' });
      var data = await res.json();
      if (!data || !data.ok) return;
      updateChip('.org-header-chip--messages', 'headerUnreadBadge', parseInt(data.total, 10) || 0);
      updateChip('.org-header-chip--alerts', 'headerFeedUnreadBadge', parseInt(data.feedUnread, 10) || 0);
    } catch(e) {}
  }

  poll();
  setInterval(poll, pollMs);
})();
</script>

<div class="sh-headpanel">
  <div class="sh-headpanel-left">
    <?php if ($isManager): ?>
      <a href="create_org.php" class="sh-icon-link"<?php echo org_layout_nav_attrs('create_org.php'); ?>><div><i class="icon ion-ios-plus-outline"></i><span>New Org</span></div></a>
      <a href="create_staff.php" class="sh-icon-link"<?php echo org_layout_nav_attrs('create_staff.php'); ?>><div><i class="icon ion-person-add"></i><span>Create Staff</span></div></a>
      <!-- <a href="settings.php" class="sh-icon-link"<?php echo org_layout_nav_attrs('settings.php'); ?>><div><i class="icon ion-gear-b"></i><span>Setting</span></div></a> -->
    <?php else: ?>
      <a href="#" class="sh-icon-link"><div><i class="icon ion-ios-information-outline"></i><span><?= h((string)($ORG['org_code'] ?? '')) ?></span></div></a>
    <?php endif; ?>
  </div>

  <div class="sh-headpanel-right">
    <?php
      $myType = $isManager ? 'manager' : 'staff';
      $myId   = (int)orgAccountId();
      $myAvatar = 'includes/avatar.php?type=' . urlencode($myType) . '&id=' . $myId;
    ?>

    <?php if ($isManager && $activeOrgId > 0): ?>
      <div class="org-header-context" aria-label="Current organization context">
        <?php
          require_once __DIR__ . '/org_manager_guard.php';
          $headerIsCommerceSeller = org_active_is_commerce_seller(isset($dbh) && $dbh instanceof PDO ? $dbh : null);
          if ($headerIsCommerceSeller) {
              $headerPageTitle = 'Sales Management';
              $headerPageHref = 'sales_management.php';
          } else {
              $headerPageTitle = 'Publisher hub';
              $headerPageHref = 'dashboard.php';
          }
          $salesBadgeLabel = $salesAttentionCount > 99 ? '99+' : (string)(int)$salesAttentionCount;
          $salesBadgeTitleParts = [];
          if ((int)($salesAttentionBreakdown['orders'] ?? 0) > 0) {
              $salesBadgeTitleParts[] = (int)$salesAttentionBreakdown['orders'] . ' open order(s)';
          }
          if ((int)($salesAttentionBreakdown['delivery'] ?? 0) > 0) {
              $salesBadgeTitleParts[] = (int)$salesAttentionBreakdown['delivery'] . ' ready to ship';
          }
          if ((int)($salesAttentionBreakdown['products'] ?? 0) > 0) {
              $salesBadgeTitleParts[] = (int)$salesAttentionBreakdown['products'] . ' product alert(s)';
          }
          if ((int)($salesAttentionBreakdown['customers'] ?? 0) > 0) {
              $salesBadgeTitleParts[] = (int)$salesAttentionBreakdown['customers'] . ' customer alert(s)';
          }
          if ((int)($salesAttentionBreakdown['returns'] ?? 0) > 0) {
              $salesBadgeTitleParts[] = (int)$salesAttentionBreakdown['returns'] . ' return(s)';
          }
          if ((int)($salesAttentionBreakdown['notification'] ?? 0) > 0) {
              $salesBadgeTitleParts[] = (int)$salesAttentionBreakdown['notification'] . ' notification(s)';
          }
          $salesBadgeTitle = $salesBadgeTitleParts
              ? ('Attention: ' . implode(', ', $salesBadgeTitleParts))
              : 'Sales management';
        ?>
        <a
          class="org-header-page-title"
          href="<?= h($headerPageHref) ?>"
          title="<?= h($salesBadgeTitle) ?>"
          aria-label="<?= h($headerPageTitle . ($salesAttentionCount > 0 ? (' — ' . $salesBadgeLabel . ' items need attention') : '')) ?>"
        >
          <span><?= h($headerPageTitle) ?></span>
          <?php if ($headerIsCommerceSeller && $salesAttentionCount > 0): ?>
            <span class="org-header-sales-badge is-pulse is-alert" aria-hidden="true"><?= h($salesBadgeLabel) ?></span>
          <?php endif; ?>
        </a>
        <div class="dropdown">
        <a href="#" class="org-pill dropdown-toggle" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
          <?= h($activeOrgName !== '' ? $activeOrgName : 'Organization') ?>
          <?php if ($activeOrgCode !== ''): ?>
            <small>(<?= h($activeOrgCode) ?>)</small>
          <?php endif; ?>
          <span class="caret">▼</span>
        </a>
        <div class="dropdown-menu dropdown-menu-right" style="min-width:240px;">
          <h6 class="dropdown-header">Switch Organization</h6>
          <?php if (!empty($orgChoices)): ?>
            <?php foreach ($orgChoices as $o): ?>
              <?php $oid = (int)($o['id'] ?? 0); ?>
              <a class="dropdown-item" href="switch_org.php?org=<?= $oid ?>">
                <?= h(account_org_display_label((string)($o['name'] ?? ''))) ?>
                <small style="opacity:.8;">(<?= h((string)($o['org_code'] ?? '')) ?>)</small>
                <?php if ($oid === $activeOrgId): ?> ✓<?php endif; ?>
              </a>
            <?php endforeach; ?>
          <?php else: ?>
            <span class="dropdown-item-text text-muted">No organizations found.</span>
          <?php endif; ?>
          <div class="dropdown-divider"></div>
          <a class="dropdown-item" href="select_org.php">Manage organizations</a>
        </div>
      </div>
      </div>
    <?php endif; ?>

    <?php if ($orgPortalRoleBadge !== ''): ?>
      <span class="ig-feed-account-badge" style="margin-right:10px;" aria-label="Account type"><?= h($orgPortalRoleBadge) ?></span>
    <?php endif; ?>

    <div class="org-header-actions" role="group" aria-label="Inbox and feed alerts">
      <a href="messages.php"
         class="org-header-chip org-header-chip--messages<?= $unreadCount > 0 ? ' has-unread' : '' ?>"
         aria-label="Open inbox<?= $unreadCount > 0 ? ' — ' . (int)$unreadCount . ' unread' : '' ?>">
        <span class="org-header-chip__icon" aria-hidden="true"><i class="icon ion-ios-paper-outline"></i></span>
        <span class="org-header-chip__label">Inbox</span>
        <span class="org-header-chip__meta">
          <span id="headerUnreadBadge" class="org-header-chip__count<?= $unreadCount > 0 ? ' is-visible' : '' ?>"><?= $unreadCount > 0 ? ($unreadCount > 99 ? '99+' : (string)(int)$unreadCount) : '' ?></span>
        </span>
      </a>
      <a href="feed.php?tab=work"
         class="org-header-chip org-header-chip--alerts<?= $feedUnreadCount > 0 ? ' has-unread' : '' ?>"
         aria-label="Open feed updates<?= $feedUnreadCount > 0 ? ' — ' . (int)$feedUnreadCount . ' new posts' : '' ?>">
        <span class="org-header-chip__icon" aria-hidden="true"><i class="icon ion-ios-bell-outline"></i></span>
        <span class="org-header-chip__label">Feed</span>
        <span class="org-header-chip__meta">
          <span id="headerFeedUnreadBadge" class="org-header-chip__count<?= $feedUnreadCount > 0 ? ' is-visible' : '' ?>"><?= $feedUnreadCount > 0 ? ($feedUnreadCount > 99 ? '99+' : (string)(int)$feedUnreadCount) : '' ?></span>
        </span>
      </a>
    </div>

    <div class="dropdown dropdown-profile">
      <a href="#" data-toggle="dropdown" class="dropdown-link">
        <img src="<?= h($myAvatar) ?>" class="wd-40 rounded-circle" alt="">
      </a>
      <div class="dropdown-menu dropdown-menu-right">
        <div class="media align-items-center">
          <img src="<?= h($myAvatar) ?>" class="wd-60 ht-60 rounded-circle bd pd-5" alt="">
          <div class="media-body">
            <h6 class="tx-inverse tx-15 mg-b-5"><?= h($displayName) ?></h6>
            <p class="mg-b-0 tx-12"><?= h($displayEmail) ?></p>
          </div>
        </div>
        <hr>
        <ul class="dropdown-profile-nav">
          <?php if ($isManager): ?>
            <li><a href="settings.php"><i class="icon ion-ios-gear"></i> Org Settings</a></li>
          <?php endif; ?>
          <li><a href="logout.php"><i class="icon ion-power"></i> Sign Out</a></li>
        </ul>
      </div>
    </div>

  </div>
</div>
