<?php
declare(strict_types=1);

if (!isset($meId)) {
    $meId = (int)($_SESSION['user_id'] ?? $_SESSION['id'] ?? $_SESSION['userid'] ?? 0);
}
$feedTopShopActive = !empty($feedTopShopActive);
$feedTopCartActive = !empty($feedTopCartActive);
$feedTopShopViewToggle = !empty($feedTopShopViewToggle);
$feedTopShopOnly = !empty($feedTopShopOnly);

$feedTopCartCount = 0;
$feedTopShopBadge = 0;
if ($meId > 0) {
    try {
        if (!isset($dbh) || !($dbh instanceof PDO)) {
            require_once __DIR__ . '/../controller.php';
            $dbh = (new Controller())->pdo();
        }
        require_once __DIR__ . '/org_cart.php';
        $feedTopCartCount = org_cart_count($dbh, $meId);
    } catch (Throwable $e) {
        $feedTopCartCount = 0;
    }
    try {
        if (!isset($dbh) || !($dbh instanceof PDO)) {
            require_once __DIR__ . '/../controller.php';
            $dbh = (new Controller())->pdo();
        }
        require_once __DIR__ . '/commerce_messaging.php';
        if (function_exists('commerce_buyer_shop_hub_badge_count')) {
            $feedTopShopBadge = commerce_buyer_shop_hub_badge_count($dbh, $meId);
        }
    } catch (Throwable $e) {
        $feedTopShopBadge = 0;
    }
}

$feedTopShopBadgeLabel = $feedTopShopBadge > 99 ? '99+' : (string)(int)$feedTopShopBadge;
/* Bag always opens shop.php; Shopping Preferences (with the same hub count) lives on shop. */
$feedTopShopHref = 'shop.php';
$feedTopShopAria = $feedTopShopBadge > 0
    ? ('Shop — ' . $feedTopShopBadgeLabel . ' shopping preference update' . ($feedTopShopBadge === 1 ? '' : 's'))
    : 'Shop';

if (!function_exists('feed_top_shop_icon_html')) {
    function feed_top_shop_icon_html(
        bool $active,
        string $href,
        string $aria,
        int $badge,
        string $badgeLabel,
        bool $withId = true
    ): string {
        $cls = 'ig-top-act ig-top-shop' . ($active ? ' is-active' : '');
        $current = $active ? ' aria-current="page"' : '';
        $html = '<a href="' . htmlspecialchars($href, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" class="' . $cls . '" aria-label="'
            . htmlspecialchars($aria, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"' . $current . '>'
            . '<i class="icon ion-bag"></i>';
        $idAttr = $withId ? ' id="feedTopShopBadge"' : '';
        if ($badge > 0) {
            $wide = strlen($badgeLabel) > 1 ? ' is-wide' : '';
            $html .= '<span class="feed-ig-badge ig-top-shop-badge' . $wide . '"' . $idAttr . '>'
                . htmlspecialchars($badgeLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                . '</span>';
        } else {
            $html .= '<span class="feed-ig-badge ig-top-shop-badge"' . $idAttr . ' hidden>0</span>';
        }
        $html .= '</a>';
        return $html;
    }
}
?>
<div class="ig-feed-top-actions" aria-label="Header actions">
  <?php if ($feedTopShopOnly): ?>
  <?php if ($feedTopShopViewToggle): ?>
  <div class="ig-shop-view-toggle" role="group" aria-label="Shop view">
    <button type="button" class="ig-shop-view-btn" data-shop-view="list" aria-pressed="false" aria-label="List view">
      <span class="ig-shop-view-ic" aria-hidden="true">
        <svg viewBox="0 0 24 24"><rect x="4" y="5" width="5" height="5" rx="1"/><rect x="11" y="6.5" width="9" height="2" rx="1"/><rect x="4" y="14" width="5" height="5" rx="1"/><rect x="11" y="15.5" width="9" height="2" rx="1"/></svg>
      </span>
      <span class="ig-shop-view-label"><?= h(function_exists('app_t') ? app_t('List') : 'List') ?></span>
    </button>
    <button type="button" class="ig-shop-view-btn is-active" data-shop-view="grid" aria-pressed="true" aria-label="Grid view">
      <span class="ig-shop-view-ic" aria-hidden="true">
        <svg viewBox="0 0 24 24"><rect x="4" y="4" width="5" height="5" rx="1"/><rect x="10" y="4" width="5" height="5" rx="1"/><rect x="16" y="4" width="5" height="5" rx="1"/><rect x="4" y="10" width="5" height="5" rx="1"/><rect x="10" y="10" width="5" height="5" rx="1"/><rect x="16" y="10" width="5" height="5" rx="1"/><rect x="4" y="16" width="5" height="5" rx="1"/><rect x="10" y="16" width="5" height="5" rx="1"/><rect x="16" y="16" width="5" height="5" rx="1"/></svg>
      </span>
      <span class="ig-shop-view-label"><?= h(function_exists('app_t') ? app_t('Grid') : 'Grid') ?></span>
    </button>
  </div>
  <?php endif; ?>
  <?= feed_top_shop_icon_html($feedTopShopActive, $feedTopShopHref, $feedTopShopAria, $feedTopShopBadge, $feedTopShopBadgeLabel) ?>
  <a href="cart.php" class="ig-top-act ig-top-cart<?= $feedTopCartActive ? ' is-active' : '' ?>" aria-label="Cart"<?= $feedTopCartActive ? ' aria-current="page"' : '' ?>>
    <i class="icon ion-ios-cart"></i>
    <?php if ($feedTopCartCount > 0): ?>
      <span class="ig-top-cart-badge" id="feedTopCartBadge"><?= (int)$feedTopCartCount ?></span>
    <?php endif; ?>
  </a>
  <?php else: ?>
  <?php if ($feedTopShopViewToggle): ?>
  <div class="ig-shop-view-toggle" role="group" aria-label="Shop view">
    <button type="button" class="ig-shop-view-btn" data-shop-view="list" aria-pressed="false" aria-label="List view">
      <span class="ig-shop-view-ic" aria-hidden="true">
        <svg viewBox="0 0 24 24"><rect x="4" y="5" width="5" height="5" rx="1"/><rect x="11" y="6.5" width="9" height="2" rx="1"/><rect x="4" y="14" width="5" height="5" rx="1"/><rect x="11" y="15.5" width="9" height="2" rx="1"/></svg>
      </span>
      <span class="ig-shop-view-label"><?= h(function_exists('app_t') ? app_t('List') : 'List') ?></span>
    </button>
    <button type="button" class="ig-shop-view-btn is-active" data-shop-view="grid" aria-pressed="true" aria-label="Grid view">
      <span class="ig-shop-view-ic" aria-hidden="true">
        <svg viewBox="0 0 24 24"><rect x="4" y="4" width="5" height="5" rx="1"/><rect x="10" y="4" width="5" height="5" rx="1"/><rect x="16" y="4" width="5" height="5" rx="1"/><rect x="4" y="10" width="5" height="5" rx="1"/><rect x="10" y="10" width="5" height="5" rx="1"/><rect x="16" y="10" width="5" height="5" rx="1"/><rect x="4" y="16" width="5" height="5" rx="1"/><rect x="10" y="16" width="5" height="5" rx="1"/><rect x="16" y="16" width="5" height="5" rx="1"/></svg>
      </span>
      <span class="ig-shop-view-label"><?= h(function_exists('app_t') ? app_t('Grid') : 'Grid') ?></span>
    </button>
  </div>
  <?php else: ?>
  <?= feed_top_shop_icon_html($feedTopShopActive, $feedTopShopHref, $feedTopShopAria, $feedTopShopBadge, $feedTopShopBadgeLabel) ?>
  <?php endif; ?>
  <?php if (!defined('MSB_HOME_PAGE')): ?>
  <a href="cart.php" class="ig-top-act ig-top-cart<?= $feedTopCartActive ? ' is-active' : '' ?>" aria-label="Cart"<?= $feedTopCartActive ? ' aria-current="page"' : '' ?>>
    <i class="icon ion-ios-cart"></i>
    <?php if ($feedTopCartCount > 0): ?>
      <span class="ig-top-cart-badge" id="feedTopCartBadge"><?= (int)$feedTopCartCount ?></span>
    <?php endif; ?>
  </a>
  <?php endif; ?>
  <?php if ($feedTopShopViewToggle): ?>
  <?= feed_top_shop_icon_html($feedTopShopActive, $feedTopShopHref, $feedTopShopAria, $feedTopShopBadge, $feedTopShopBadgeLabel, false) ?>
  <?php endif; ?>
  <button type="button" class="ig-top-act ig-top-mic" aria-label="Voice"><i class="fa fa-microphone"></i></button>
  <button type="button" class="ig-top-act ig-top-live js-open-live-door" aria-label="Go live"><i class="fa fa-video-camera"></i><span>Live</span></button>
  <?php endif; ?>
</div>
<style>
/* Shop hub badge — pinned on bag corner (defeat cascade conflicts). */
a.ig-top-shop{position:relative!important;overflow:visible!important;background:transparent!important;border:0!important;box-shadow:none!important;border-radius:0!important;}
a.ig-top-shop>.ig-top-shop-badge,
a.ig-top-shop>.feed-ig-badge.ig-top-shop-badge{
  position:absolute!important;top:2px!important;right:-2px!important;left:auto!important;bottom:auto!important;
  transform:translate(30%,-35%)!important;
  width:18px!important;height:18px!important;min-width:18px!important;max-width:18px!important;padding:0!important;margin:0!important;
  border-radius:50%!important;box-sizing:border-box!important;
  display:inline-flex!important;align-items:center!important;justify-content:center!important;
  background:#ef4444!important;color:#fff!important;font-size:10px!important;font-weight:800!important;line-height:1!important;
  border:2px solid #fff!important;box-shadow:0 4px 10px rgba(239,68,68,.3)!important;
  pointer-events:none!important;z-index:5!important;overflow:visible!important;flex:none!important;
}
a.ig-top-shop>.ig-top-shop-badge.is-wide{width:auto!important;max-width:none!important;min-width:22px!important;padding:0 4px!important;border-radius:999px!important;}
a.ig-top-shop>.ig-top-shop-badge[hidden]{display:none!important;}
</style>
<script>
(function () {
  function paintShopBadge(count, href) {
    var n = Math.max(0, parseInt(count || 0, 10) || 0);
    var label = n > 99 ? '99+' : String(n);
    var badges = document.querySelectorAll('.ig-top-shop-badge');
    badges.forEach(function (badge) {
      if (n > 0) {
        badge.hidden = false;
        badge.textContent = label;
        badge.classList.toggle('is-wide', label.length > 1);
      } else {
        badge.hidden = true;
        badge.textContent = '0';
        badge.classList.remove('is-wide');
      }
    });
    document.querySelectorAll('a.ig-top-shop').forEach(function (link) {
      link.setAttribute('href', 'shop.php');
      link.setAttribute('aria-label', n > 0 ? ('Shop — ' + label + ' shopping preference update' + (n === 1 ? '' : 's')) : 'Shop');
    });
    var prefsBadge = document.getElementById('shopNavPrefsBadge');
    var prefsLink = document.getElementById('shopNavPrefsLink');
    if (prefsBadge) {
      if (n > 0) {
        prefsBadge.hidden = false;
        prefsBadge.textContent = label;
        prefsBadge.classList.toggle('is-wide', label.length > 1);
      } else {
        prefsBadge.hidden = true;
        prefsBadge.textContent = '0';
        prefsBadge.classList.remove('is-wide');
      }
    }
    if (prefsLink) {
      prefsLink.setAttribute('aria-label', n > 0
        ? ('Shopping Preferences — ' + label + ' update' + (n === 1 ? '' : 's'))
        : 'Shopping Preferences');
    }
  }
  async function refreshShopBadge() {
    try {
      var res = await fetch('ajax/shop_hub_badge.php', { credentials: 'same-origin', cache: 'no-store' });
      var data = await res.json();
      if (data && data.ok) paintShopBadge(data.count, 'shop.php');
    } catch (e) { /* ignore */ }
  }
  function paintCartBadge(count) {
    var n = Math.max(0, parseInt(count || 0, 10) || 0);
    document.querySelectorAll('a.ig-top-cart').forEach(function (link) {
      var badge = link.querySelector('.ig-top-cart-badge');
      if (n > 0) {
        if (!badge) {
          badge = document.createElement('span');
          badge.className = 'ig-top-cart-badge';
          link.appendChild(badge);
        }
        badge.hidden = false;
        badge.textContent = n > 99 ? '99+' : String(n);
      } else if (badge) {
        badge.hidden = true;
      }
    });
  }

  /*
   * Live sync with the iOS app (and other tabs): ajax/shop_sync_state.php returns a hash per area.
   * Badges repaint in place; when an area this page shows changes elsewhere, the page quietly
   * reloads (same hash + scroll) once the user is not mid-typing or inside a dialog.
   */
  var SYNC_URL = 'ajax/shop_sync_state.php';
  var page = (location.pathname.split('/').pop() || '').toLowerCase();
  var pageParts = {
    'your_shopping_preferences.php': ['orders', 'returns', 'reviews', 'cart', 'wishlist', 'addresses', 'inbox', 'messages', 'profile'],
    'cart.php': ['cart', 'orders'],
    'shop.php': ['cart', 'wishlist', 'orders'],
    'product_detail.php': ['cart', 'wishlist', 'orders', 'reviews'],
    'ordering_tracking.php': ['orders', 'returns']
  }[page] || [];
  var baseline = null;
  var version = '';
  var pendingReload = false;
  var acceptNext = false;
  var lastInteraction = 0;
  var pollTimer = null;
  var scrollKey = 'msbShopSyncScroll:' + location.pathname + location.search;

  try {
    var savedScroll = sessionStorage.getItem(scrollKey);
    if (savedScroll !== null) {
      sessionStorage.removeItem(scrollKey);
      var y = parseInt(savedScroll, 10) || 0;
      window.addEventListener('load', function () { window.scrollTo(0, y); });
    }
  } catch (e) { /* ignore */ }

  ['keydown', 'input', 'pointerdown', 'touchstart'].forEach(function (evt) {
    document.addEventListener(evt, function () { lastInteraction = Date.now(); }, true);
  });

  // This page's own AJAX writes already updated its UI: absorb the next fingerprint without reloading.
  if (window.fetch && !window.__msbShopSyncFetchWrapped) {
    window.__msbShopSyncFetchWrapped = true;
    var nativeFetch = window.fetch.bind(window);
    window.fetch = function (input, init) {
      var url = typeof input === 'string' ? input : ((input && input.url) || '');
      var method = String((init && init.method) || (input && input.method) || 'GET').toUpperCase();
      var p = nativeFetch(input, init);
      if (method !== 'GET' && url.indexOf('ajax/') !== -1 && url.indexOf(SYNC_URL) === -1) {
        p.then(function () { acceptNext = true; schedule(250); }, function () {});
      }
      return p;
    };
  }

  function userBusy() {
    if (Date.now() - lastInteraction < 4000) return true;
    if (document.querySelector('dialog[open], .modal.show, .modal.in, [aria-modal="true"]:not([hidden])')) return true;
    var el = document.activeElement;
    if (el && (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA' || el.tagName === 'SELECT' || el.isContentEditable)) {
      return String(el.value || el.textContent || '').trim() !== '';
    }
    return false;
  }

  function reloadWhenIdle() {
    if (!pendingReload) return;
    if (document.hidden || userBusy()) {
      setTimeout(reloadWhenIdle, 1500);
      return;
    }
    try { sessionStorage.setItem(scrollKey, String(window.scrollY || 0)); } catch (e) { /* ignore */ }
    location.reload();
  }

  function schedule(ms) {
    if (pollTimer) clearTimeout(pollTimer);
    pollTimer = setTimeout(syncNow, ms);
  }

  async function syncNow() {
    pollTimer = null;
    try {
      var res = await fetch(SYNC_URL + (version ? ('?since=' + encodeURIComponent(version)) : ''), {
        credentials: 'same-origin',
        cache: 'no-store'
      });
      var data = await res.json();
      if (data && data.ok) {
        if (data.badges) {
          paintShopBadge(data.badges.hub, 'shop.php');
          paintCartBadge(data.badges.cart);
        }
        var parts = data.parts || {};
        if (baseline && !acceptNext && data.changed) {
          var moved = pageParts.some(function (key) {
            return (baseline[key] || '') !== (parts[key] || '');
          });
          if (moved && !pendingReload) {
            pendingReload = true;
            reloadWhenIdle();
          }
        }
        if (!pendingReload) {
          baseline = parts;
          version = String(data.version || '');
        }
        acceptNext = false;
      }
    } catch (e) { /* offline — keep polling */ }
    schedule(document.hidden ? 15000 : 3000);
  }

  if (document.querySelector('.ig-top-shop, .ig-top-cart')) {
    syncNow();
    document.addEventListener('visibilitychange', function () {
      if (!document.hidden) schedule(0);
    });
    window.addEventListener('focus', function () { schedule(0); });
    window.msbShopSyncNow = function () { schedule(0); };
  }
  window.msbRefreshShopBadge = refreshShopBadge;
})();
</script>
