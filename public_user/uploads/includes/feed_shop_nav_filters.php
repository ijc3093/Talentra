<?php
declare(strict_types=1);

if (!function_exists('shop_filter_build_url')) {
    function shop_filter_build_url(array $overrides = [], array $remove = []): string
    {
        global $shopSearchQ, $shopFilterPickup, $shopFilterBrand, $shopFilterCommerceBrand;
        global $shopFilterPrice, $shopFilterRating, $shopFilterType;

        $params = [
            'q' => $shopSearchQ ?? '',
            'pickup' => !empty($shopFilterPickup) ? '1' : '',
            'brand' => (string)($shopFilterBrand ?? ''),
            'cbrand' => (string)($shopFilterCommerceBrand ?? ''),
            'price' => (string)($shopFilterPrice ?? ''),
            'rating' => (string)($shopFilterRating ?? ''),
            'type' => (string)($shopFilterType ?? ''),
        ];

        foreach ($remove as $key) {
            unset($params[$key]);
        }
        foreach ($overrides as $key => $value) {
            if ($value === null || $value === '') {
                unset($params[$key]);
            } else {
                $params[$key] = $value;
            }
        }

        $params = array_filter($params, static fn($v) => $v !== '' && $v !== null);
        $query = http_build_query($params);
        return 'shop.php' . ($query !== '' ? '?' . $query : '');
    }
}

if (!function_exists('h')) {
    function h(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }
}

$shopFilterSections = [
    [
        'id' => 'pickup',
        'label' => 'Pick Up Today',
        'options' => [
            ['label' => 'Available today', 'url' => shop_filter_build_url(['pickup' => '1']), 'active' => !empty($shopFilterPickup)],
        ],
        'active' => !empty($shopFilterPickup),
    ],
    [
        'id' => 'brand',
        'label' => 'Brand',
        'options' => array_map(static function (string $brand) {
            global $shopFilterBrand;
            return [
                'label' => $brand,
                'url' => shop_filter_build_url(['brand' => $brand]),
                'active' => ($shopFilterBrand ?? '') === $brand,
            ];
        }, array_values($shopFilterBrands ?? [])),
        'active' => ($shopFilterBrand ?? '') !== '',
    ],
    [
        'id' => 'location',
        'label' => 'Location',
        'options' => [],
        'active' => !empty($shopLocationActive),
        'custom' => 'location',
    ],
    [
        'id' => 'price',
        'label' => 'Price',
        'options' => [
            ['label' => 'Under $10', 'url' => shop_filter_build_url(['price' => 'under10']), 'active' => ($shopFilterPrice ?? '') === 'under10'],
            ['label' => '$10 – $25', 'url' => shop_filter_build_url(['price' => '10-25']), 'active' => ($shopFilterPrice ?? '') === '10-25'],
            ['label' => '$25 – $50', 'url' => shop_filter_build_url(['price' => '25-50']), 'active' => ($shopFilterPrice ?? '') === '25-50'],
            ['label' => '$50 & up', 'url' => shop_filter_build_url(['price' => '50plus']), 'active' => ($shopFilterPrice ?? '') === '50plus'],
        ],
        'active' => ($shopFilterPrice ?? '') !== '',
    ],
    [
        'id' => 'rating',
        'label' => 'Ratings',
        'options' => [
            ['label' => '4 Stars & Up', 'url' => shop_filter_build_url(['rating' => '4']), 'active' => ($shopFilterRating ?? '') === '4'],
            ['label' => '3 Stars & Up', 'url' => shop_filter_build_url(['rating' => '3']), 'active' => ($shopFilterRating ?? '') === '3'],
        ],
        'active' => ($shopFilterRating ?? '') !== '',
    ],
    [
        'id' => 'type',
        'label' => 'Categories',
        'options' => array_map(static function (string $type) {
            global $shopFilterType;
            return [
                'label' => $type,
                'url' => shop_filter_build_url(['type' => $type]),
                'active' => ($shopFilterType ?? '') === $type,
            ];
        }, array_values($shopFilterTypes ?? [])),
        'active' => ($shopFilterType ?? '') !== '',
    ],
];
?>
<div class="shop-nav-filters" aria-label="Shop filters">
  <?php foreach ($shopFilterSections as $section): ?>
    <?php
      $panelId = 'shopNavFilter-' . $section['id'];
      $isOpen = !empty($section['active']) || ($section['id'] ?? '') === 'location';
      $isLocation = ($section['custom'] ?? '') === 'location';
    ?>
    <div class="shop-nav-filter<?= $isOpen ? ' is-open' : '' ?><?= !empty($section['active']) ? ' is-active' : '' ?>">
      <button
        type="button"
        class="shop-nav-filter-toggle"
        aria-expanded="<?= $isOpen ? 'true' : 'false' ?>"
        aria-controls="<?= h($panelId) ?>"
      >
        <span class="shop-nav-filter-label"><?= h($section['label']) ?></span>
        <span class="shop-nav-filter-chevron" aria-hidden="true"></span>
      </button>
      <div class="shop-nav-filter-panel" id="<?= h($panelId) ?>"<?= $isOpen ? '' : ' hidden' ?>>
        <?php if ($isLocation): ?>
          <button type="button" class="shop-nav-location-link" id="shopNavLocationOpen" aria-haspopup="dialog">
            <?= h($shopLocationSummary ?? 'Set your location') ?>
          </button>
          <p class="shop-nav-location-hint">Products near you · tap to change</p>
        <?php elseif ($section['options']): ?>
          <?php foreach ($section['options'] as $option): ?>
            <a
              href="<?= h($option['url']) ?>"
              class="shop-nav-filter-option<?= !empty($option['active']) ? ' is-active' : '' ?>"
            ><?= h($option['label']) ?></a>
          <?php endforeach; ?>
          <?php if (!empty($section['active'])): ?>
            <a href="<?= h(shop_filter_build_url([], [$section['id'] === 'pickup' ? 'pickup' : $section['id']])) ?>" class="shop-nav-filter-clear">Clear</a>
          <?php endif; ?>
        <?php else: ?>
          <span class="shop-nav-filter-empty">No options yet</span>
        <?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>
</div>
<a class="shop-nav-preferences-link" href="Your_Shopping_preferences.php">
  <span class="shop-nav-preferences-label">Shopping Preferences</span>
</a>
<?php
if (empty($GLOBALS['shop_location_modal_included'])) {
    $GLOBALS['shop_location_modal_included'] = true;
    if (!isset($shopBuyerLocation) || !is_array($shopBuyerLocation)) {
        $shopBuyerLocation = function_exists('shop_location_from_session')
            ? shop_location_from_session()
            : ['label' => '', 'city' => '', 'state' => '', 'country' => '', 'postal' => '', 'miles' => 10, 'lat' => null, 'lng' => null];
    }
    require __DIR__ . '/shop_location_modal.php';
}
?>
