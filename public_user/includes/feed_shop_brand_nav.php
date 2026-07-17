<?php
declare(strict_types=1);

$shopBrandNavItems = $shopCommerceBrandsNav ?? [];
if (!$shopBrandNavItems) {
    return;
}

if (!function_exists('h')) {
    function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}
?>
<div class="shop-brand-nav" aria-label="Shop by brand">
  <div class="shop-brand-nav-head">Brands</div>
  <div class="shop-brand-nav-list">
  <?php foreach ($shopBrandNavItems as $navBrand): ?>
    <?php
      $slug = (string)($navBrand['slug'] ?? '');
      $name = (string)($navBrand['name'] ?? '');
      $count = (int)($navBrand['product_count'] ?? 0);
      $sellerCount = (int)($navBrand['seller_count'] ?? 0);
      $accent = trim((string)($navBrand['accent_color'] ?? '#2563eb'));
      $icon = trim((string)($navBrand['icon_letter'] ?? mb_substr($name, 0, 1)));
      $isActive = ($shopFilterCommerceBrand ?? '') !== '' && strcasecmp($shopFilterCommerceBrand, $slug) === 0;
      if ($count > 0) {
          $navSub = $count . ' item' . ($count === 1 ? '' : 's');
      } elseif ($sellerCount > 0) {
          $navSub = $sellerCount . ' seller' . ($sellerCount === 1 ? '' : 's') . ' · coming soon';
      } else {
          $navSub = 'Browse';
      }
    ?>
    <a
      href="<?= h(org_commerce_brands_shop_url($slug)) ?>"
      class="shop-brand-nav-item<?= $isActive ? ' is-active' : '' ?>"
      style="--shop-brand-accent: <?= h($accent) ?>"
    >
      <span class="shop-brand-nav-icon" aria-hidden="true"><?= h($icon) ?></span>
      <span class="shop-brand-nav-text">
        <strong><?= h($name) ?></strong>
        <span><?= h($navSub) ?></span>
      </span>
    </a>
  <?php endforeach; ?>
  </div>
  <?php if (($shopFilterCommerceBrand ?? '') !== ''): ?>
    <a href="<?= h(shop_filter_build_url([], ['cbrand'])) ?>" class="shop-brand-nav-clear">Show all brands</a>
  <?php endif; ?>
</div>
