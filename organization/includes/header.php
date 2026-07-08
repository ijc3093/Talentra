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

// --- Unread badge counts (messages + feed updates) ---
$unreadCount = 0;
$feedUnreadCount = 0;
try {
    if (function_exists('orgMemberId')) {
        $meMid = (int)orgMemberId();
        $orgId = (int)($ORG['id'] ?? 0);

        if ($meMid > 0 && $orgId > 0) {
            $unreadCount = org_header_message_unread_count($dbh, $orgId, $meMid);
            $feedUnreadCount = org_header_feed_unread_count($dbh, $orgId, $meMid);
        }
    }
} catch (Throwable $e) {
    $unreadCount = 0;
    $feedUnreadCount = 0;
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
<link rel="stylesheet" href="../css/dark-auto.css">
<?php endif; ?>
<script src="../js/dark-auto.js?v=6" defer></script>

<style>
  :root{
    --org-bg: #171d24;
    --org-accent: var(--msb-palette-action, <?= h($accent) ?>);
    --org-font: <?= h($fontSz) ?>;
  }
  body{
    background: #171d24 !important;
    font-size: var(--org-font);
  }
  .sh-logo-text{ text-transform: none !important; }
  .org-logo-label{
    font-size: 18px;
    font-weight: 600;
    font-family: cursive;
    line-height: 1.25;
    color: #fff;
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
  <a href="feed.php" class="sh-logo-text" style="display:flex;align-items:center;gap:1px;">
   
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
      <div class="dropdown" style="margin-right:12px;">
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
