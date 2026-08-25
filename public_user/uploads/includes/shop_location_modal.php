<?php
declare(strict_types=1);

/** Change-location modal for shop left-rail Location filter. Expects $shopBuyerLocation array. */

$shopLoc = is_array($shopBuyerLocation ?? null) ? $shopBuyerLocation : shop_location_default();
$shopLocMiles = max(1, (int)($shopLoc['miles'] ?? 10));
$shopLocLabel = trim((string)($shopLoc['label'] ?? ''));
if ($shopLocLabel === '') {
    $shopLocLabel = shop_location_format_label(
        (string)($shopLoc['city'] ?? ''),
        (string)($shopLoc['state'] ?? ''),
        (string)($shopLoc['country'] ?? '')
    );
}
$shopLocLat = $shopLoc['lat'] ?? null;
$shopLocLng = $shopLoc['lng'] ?? null;
?>
<div class="shop-loc-modal" id="shopLocModal" hidden aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="shopLocModalTitle">
  <div class="shop-loc-modal-card" role="document">
    <div class="shop-loc-modal-head">
      <h2 class="shop-loc-modal-title" id="shopLocModalTitle">Change location</h2>
      <button type="button" class="shop-loc-modal-close" id="shopLocModalClose" aria-label="Close">&times;</button>
    </div>
    <p class="shop-loc-modal-hint">Search by city, neighborhood or ZIP code.</p>

    <div class="shop-loc-search-wrap">
      <label class="shop-loc-sr" for="shopLocSearchInput">Search location</label>
      <input
        type="search"
        id="shopLocSearchInput"
        class="shop-loc-search-input"
        placeholder="City, neighborhood, or ZIP"
        autocomplete="off"
        value="<?= h($shopLocLabel) ?>"
      >
      <button type="button" class="shop-loc-search-btn" id="shopLocSearchBtn">Search</button>
    </div>
    <p class="shop-loc-search-msg" id="shopLocSearchMsg" hidden></p>

    <div class="shop-loc-fields">
      <div class="shop-loc-field">
        <span class="shop-loc-field-ic" aria-hidden="true"><i class="fa fa-map-marker"></i></span>
        <div class="shop-loc-field-body">
          <span class="shop-loc-field-label">Location</span>
          <span class="shop-loc-field-value" id="shopLocFieldLabel"><?= h($shopLocLabel !== '' ? $shopLocLabel : 'Choose a place') ?></span>
        </div>
      </div>
      <div class="shop-loc-field shop-loc-field-radius">
        <div class="shop-loc-field-body">
          <label class="shop-loc-field-label" for="shopLocRadius">Radius</label>
          <select id="shopLocRadius" class="shop-loc-radius-select">
            <?php foreach (shop_location_radius_options() as $mi): ?>
              <option value="<?= (int)$mi ?>"<?= $shopLocMiles === (int)$mi ? ' selected' : '' ?>><?= (int)$mi ?> miles</option>
            <?php endforeach; ?>
          </select>
        </div>
        <span class="shop-loc-field-chevron" aria-hidden="true"></span>
      </div>
    </div>

    <div class="shop-loc-map" id="shopLocMap" aria-label="Map preview"></div>

    <div class="shop-loc-modal-foot">
      <button type="button" class="shop-loc-apply" id="shopLocApply">Apply</button>
    </div>
  </div>
</div>

<script>
window.__shopLocState = <?= json_encode([
    'label' => $shopLocLabel,
    'city' => (string)($shopLoc['city'] ?? ''),
    'state' => (string)($shopLoc['state'] ?? ''),
    'country' => (string)($shopLoc['country'] ?? ''),
    'postal' => (string)($shopLoc['postal'] ?? ''),
    'miles' => $shopLocMiles,
    'lat' => $shopLocLat,
    'lng' => $shopLocLng,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
</script>
<?php if (empty($GLOBALS['shop_location_assets_included'])): ?>
  <?php $GLOBALS['shop_location_assets_included'] = true; ?>
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">
  <script defer src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
  <script defer src="assets/shop-location.js?v=1"></script>
<?php endif; ?>
