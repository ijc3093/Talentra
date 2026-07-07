<?php
declare(strict_types=1);

if (!isset($meId)) {
    $meId = (int)($_SESSION['user_id'] ?? $_SESSION['id'] ?? $_SESSION['userid'] ?? 0);
}
$feedTopShopActive = !empty($feedTopShopActive);
$feedTopCartActive = !empty($feedTopCartActive);

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
  <a href="shop.php" class="ig-top-act ig-top-shop<?= $feedTopShopActive ? ' is-active' : '' ?>" aria-label="Shop"<?= $feedTopShopActive ? ' aria-current="page"' : '' ?>><i class="icon ion-bag"></i></a>
  <a href="cart.php" class="ig-top-act ig-top-cart<?= $feedTopCartActive ? ' is-active' : '' ?>" aria-label="Cart"<?= $feedTopCartActive ? ' aria-current="page"' : '' ?>>
    <i class="icon ion-ios-cart"></i>
    <?php if ($feedTopCartCount > 0): ?>
      <span class="ig-top-cart-badge" id="feedTopCartBadge"><?= (int)$feedTopCartCount ?></span>
    <?php endif; ?>
  </a>
  <button type="button" class="ig-top-act ig-top-mic" aria-label="Voice"><i class="fa fa-microphone"></i></button>
  <button type="button" class="ig-top-act ig-top-live js-open-live-door" aria-label="Go live"><i class="fa fa-video-camera"></i><span>Live</span></button>
</div>
