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
      <span class="ig-shop-view-label">List</span>
    </button>
    <button type="button" class="ig-shop-view-btn is-active" data-shop-view="grid" aria-pressed="true" aria-label="Grid view">
      <span class="ig-shop-view-ic" aria-hidden="true">
        <svg viewBox="0 0 24 24"><rect x="4" y="4" width="5" height="5" rx="1"/><rect x="10" y="4" width="5" height="5" rx="1"/><rect x="16" y="4" width="5" height="5" rx="1"/><rect x="4" y="10" width="5" height="5" rx="1"/><rect x="10" y="10" width="5" height="5" rx="1"/><rect x="16" y="10" width="5" height="5" rx="1"/><rect x="4" y="16" width="5" height="5" rx="1"/><rect x="10" y="16" width="5" height="5" rx="1"/><rect x="16" y="16" width="5" height="5" rx="1"/></svg>
      </span>
      <span class="ig-shop-view-label">Grid</span>
    </button>
  </div>
  <?php endif; ?>
  <a href="shop.php" class="ig-top-act ig-top-shop<?= $feedTopShopActive ? ' is-active' : '' ?>" aria-label="Shop"<?= $feedTopShopActive ? ' aria-current="page"' : '' ?>><i class="icon ion-bag"></i></a>
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
      <span class="ig-shop-view-label">List</span>
    </button>
    <button type="button" class="ig-shop-view-btn is-active" data-shop-view="grid" aria-pressed="true" aria-label="Grid view">
      <span class="ig-shop-view-ic" aria-hidden="true">
        <svg viewBox="0 0 24 24"><rect x="4" y="4" width="5" height="5" rx="1"/><rect x="10" y="4" width="5" height="5" rx="1"/><rect x="16" y="4" width="5" height="5" rx="1"/><rect x="4" y="10" width="5" height="5" rx="1"/><rect x="10" y="10" width="5" height="5" rx="1"/><rect x="16" y="10" width="5" height="5" rx="1"/><rect x="4" y="16" width="5" height="5" rx="1"/><rect x="10" y="16" width="5" height="5" rx="1"/><rect x="16" y="16" width="5" height="5" rx="1"/></svg>
      </span>
      <span class="ig-shop-view-label">Grid</span>
    </button>
  </div>
  <?php else: ?>
  <a href="shop.php" class="ig-top-act ig-top-shop<?= $feedTopShopActive ? ' is-active' : '' ?>" aria-label="Shop"<?= $feedTopShopActive ? ' aria-current="page"' : '' ?>><i class="icon ion-bag"></i></a>
  <?php endif; ?>
  <a href="cart.php" class="ig-top-act ig-top-cart<?= $feedTopCartActive ? ' is-active' : '' ?>" aria-label="Cart"<?= $feedTopCartActive ? ' aria-current="page"' : '' ?>>
    <i class="icon ion-ios-cart"></i>
    <?php if ($feedTopCartCount > 0): ?>
      <span class="ig-top-cart-badge" id="feedTopCartBadge"><?= (int)$feedTopCartCount ?></span>
    <?php endif; ?>
  </a>
  <?php if ($feedTopShopViewToggle): ?>
  <a href="shop.php" class="ig-top-act ig-top-shop<?= $feedTopShopActive ? ' is-active' : '' ?>" aria-label="Shop"<?= $feedTopShopActive ? ' aria-current="page"' : '' ?>><i class="icon ion-bag"></i></a>
  <?php endif; ?>
  <button type="button" class="ig-top-act ig-top-mic" aria-label="Voice"><i class="fa fa-microphone"></i></button>
  <button type="button" class="ig-top-act ig-top-live js-open-live-door" aria-label="Go live"><i class="fa fa-video-camera"></i><span>Live</span></button>
  <?php endif; ?>
</div>
