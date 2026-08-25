<?php
declare(strict_types=1);
?>
<div class="ig-stories-wrap shop-header-search-wrap" aria-label="Search shop">
  <div class="feed-top-search shop-header-search">
    <form class="feed-top-search-form" method="get" action="shop.php">
      <?php if (!empty($shopFilterPickup)): ?><input type="hidden" name="pickup" value="1"><?php endif; ?>
      <?php if (($shopFilterBrand ?? '') !== ''): ?><input type="hidden" name="brand" value="<?= h($shopFilterBrand) ?>"><?php endif; ?>
      <?php if (($shopFilterCommerceBrand ?? '') !== ''): ?><input type="hidden" name="cbrand" value="<?= h($shopFilterCommerceBrand) ?>"><?php endif; ?>
      <?php if (($shopFilterPrice ?? '') !== ''): ?><input type="hidden" name="price" value="<?= h($shopFilterPrice) ?>"><?php endif; ?>
      <?php if (($shopFilterRating ?? '') !== ''): ?><input type="hidden" name="rating" value="<?= h($shopFilterRating) ?>"><?php endif; ?>
      <?php if (($shopFilterType ?? '') !== ''): ?><input type="hidden" name="type" value="<?= h($shopFilterType) ?>"><?php endif; ?>
      <div class="feed-top-search-field">
        <input
          type="search"
          name="q"
          class="feed-top-search-input"
          value="<?= h($shopSearchQ ?? '') ?>"
          placeholder="Search products…"
          autocomplete="off"
          enterkeyhint="search"
        >
        <button type="submit" class="feed-top-search-icon" aria-label="Search">
          <i class="fa fa-search" aria-hidden="true"></i>
        </button>
      </div>
    </form>
  </div>
</div>
