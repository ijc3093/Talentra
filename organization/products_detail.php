<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/session_org.php';
require_once __DIR__ . '/includes/org_context.php';
require_once __DIR__ . '/../public_user/includes/platform_rent.php';
require_once __DIR__ . '/../public_user/includes/org_shop.php';
require_once __DIR__ . '/includes/org_ecommerce.php';
require_once __DIR__ . '/includes/org_manager_guard.php';

org_require_manager();
org_require_commerce_seller();
org_ecommerce_ensure_schema($dbh);
org_shop_ensure_schema($dbh);

if (!function_exists('h')) {
    function h(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }
}

$orgId = (int)orgActiveOrgId();
$productId = (int)($_GET['id'] ?? 0);
$fromSales = ((string)($_GET['from'] ?? '') === 'sales');
$product = $productId > 0 ? org_shop_get_product($dbh, $productId, $orgId) : null;

if (!$product) {
    header('Location: ' . ($fromSales ? 'sales_management.php#inventory' : 'product_table.php'));
    exit;
}

$productCode = org_shop_ensure_product_code(
    $dbh,
    $orgId,
    $productId,
    isset($product['product_code']) ? (string)$product['product_code'] : null
);
$title = trim((string)($product['title'] ?? 'Product'));
$sku = trim((string)($product['sku'] ?? ''));
$category = trim((string)($product['category'] ?? ''));
$sellingType = trim((string)($product['selling_type'] ?? ''));
$status = strtolower(trim((string)($product['status'] ?? 'draft')));
$currency = strtoupper((string)($product['currency'] ?? 'USD'));
$priceCents = (int)($product['price_cents'] ?? 0);
$price = org_shop_format_price($priceCents, $currency);
$priceDisplay = trim($price . ' ' . $currency);
$stock = $product['stock_qty'];
$stockTracked = !($stock === null || $stock === '');
$stockCount = $stockTracked ? (int)$stock : null;
$inStock = !$stockTracked || ($stockCount !== null && $stockCount > 0);
$description = trim((string)($product['description'] ?? ''));
$bulletPoints = org_shop_parse_bullet_points((string)($product['bullet_points'] ?? ''));
$featureLines = $bulletPoints;
if (!$featureLines && $description !== '') {
    $featureLines = array_values(array_filter(array_map('trim', preg_split('/\r\n|\n|\r|(?:\s*•\s*)|(?:\s*-\s+)/', $description) ?: [])));
}
$offerType = trim((string)($product['offer_type'] ?? 'physical'));
$fulfillment = strtolower(trim((string)($product['fulfillment_method'] ?? 'fbm')));
if (!in_array($fulfillment, ['fba', 'fbm'], true)) {
    $fulfillment = 'fbm';
}
$receive = org_shop_product_receive_options($product);
$shippingFeeCents = max(0, (int)($receive['shipping_fee_cents'] ?? 0));
$shippingFeeLabel = !empty($receive['delivery_enabled'])
    ? ($shippingFeeCents > 0 ? org_shop_format_price($shippingFeeCents, $currency) : 'Free')
    : '—';

$productFacts = org_product_type_buyer_facts(
    isset($product['attributes_json']) ? (string)$product['attributes_json'] : null,
    $sellingType,
    8
);
$productSpecRows = $productFacts['rows'];
$productCondition = $productFacts['condition'];
$productTypeLabel = $productFacts['type_label'] !== '' ? $productFacts['type_label'] : $sellingType;

$galleryImages = [];
foreach (org_shop_product_gallery_paths($dbh, $product) as $path) {
    $path = trim((string)$path);
    if ($path === '') {
        continue;
    }
    // Org pages resolve uploads relative to /organization/ (not public_user cover URLs).
    $galleryImages[] = preg_match('#^https?://#i', $path) ? $path : ltrim($path, '/');
}

$sellerPublicInfo = org_shop_seller_pickup_display($dbh, $orgId);
$backHref = $fromSales ? 'sales_management.php#inventory' : 'product_table.php';
$editHref = $fromSales
    ? ('sales_management.php?edit=' . $productId . '#products')
    : ('products.php?edit=' . $productId);
$publicUrl = '../public_user/product_detail.php?id=' . $productId;

$statusLabel = $status === 'sold_out' ? 'sold out' : $status;
$pageTitle = $productCode !== '' ? ($productCode . ' · ' . $title) : $title;

require_once __DIR__ . '/includes/org_page_shell.php';
org_page_shell_open(
    $pageTitle,
    '<link rel="stylesheet" href="css/product-table.css?v=5">'
    . '<link rel="stylesheet" href="css/products-detail.css?v=7">'
    . '<link href="../public_user/lib/font-awesome/css/font-awesome.css" rel="stylesheet">'
);
?>
<?php org_page_body_open('products-detail-page product-table-page product-detail-page'); ?>
  <div class="pd-page">
    <div class="pd-gallery-col">
      <div class="pd-gallery-body">
        <div class="pd-gallery-main">
          <button type="button" class="pd-gallery-nav" id="pdGalleryPrev" aria-label="Previous image"<?= count($galleryImages) <= 1 ? ' disabled' : '' ?>>‹</button>
          <div class="pd-gallery-stage" id="pdGalleryStage"<?= $galleryImages ? ' role="button" tabindex="0" aria-label="Open photo viewer"' : '' ?>>
            <?php if ($galleryImages): ?>
              <img src="<?= h($galleryImages[0]) ?>" alt="<?= h($title) ?>" id="pdGalleryImage">
            <?php else: ?>
              <span class="pd-gallery-fallback"><i class="icon ion-ios-box"></i></span>
            <?php endif; ?>
          </div>
          <?php if ($galleryImages): ?>
            <button type="button" class="pd-photo-count" id="pdPhotoCount" aria-label="Open photo viewer">
              <i class="fa fa-camera" aria-hidden="true"></i>
              <span id="pdPhotoCountLabel">1 / <?= (int)count($galleryImages) ?></span>
            </button>
          <?php endif; ?>
          <button type="button" class="pd-gallery-nav" id="pdGalleryNext" aria-label="Next image"<?= count($galleryImages) <= 1 ? ' disabled' : '' ?>>›</button>
        </div>
        <?php if ($galleryImages): ?>
          <div class="pd-gallery-thumbs" id="pdGalleryThumbs">
            <?php foreach ($galleryImages as $gi => $imgUrl): ?>
              <button type="button" class="pd-gallery-thumb<?= $gi === 0 ? ' is-active' : '' ?>" data-index="<?= (int)$gi ?>" aria-label="View image <?= (int)$gi + 1 ?>">
                <img src="<?= h($imgUrl) ?>" alt="">
              </button>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
      <a href="<?= h($backHref) ?>" class="pd-back pd-gallery-back"><i class="icon ion-ios-arrow-left"></i> Back to product table</a>
    </div>

    <div class="pd-info-col">
      <div class="pd-info-head">
        <div class="pd-head-row">
          <div>
            <h1 class="pd-title"><?= h($title) ?></h1>
            <div class="pd-rating-row">
              <code class="pd-listing-code"><?= h($productCode !== '' ? $productCode : ('#' . $productId)) ?></code>
              <span class="pt-status <?= $status === 'active' ? 'is-active' : ($status === 'draft' ? 'is-draft' : ($status === 'sold_out' ? 'is-sold-out' : 'is-other')) ?>"><?= h($statusLabel) ?></span>
            </div>
          </div>
          <a href="<?= h($editHref) ?>" class="pd-wishlist" title="Edit listing"><i class="fa fa-pencil" aria-hidden="true"></i> Edit product</a>
        </div>

        <div class="pd-price"><?= h($priceDisplay) ?></div>

        <?php if ($productTypeLabel !== '' || $productCondition !== ''): ?>
          <div class="pd-type-meta">
            <?php if ($productTypeLabel !== ''): ?>
              <span class="pd-type-pill"><?= h($productTypeLabel) ?></span>
            <?php endif; ?>
            <?php if ($productCondition !== ''): ?>
              <span class="pd-condition-pill<?= stripos($productCondition, 'used') !== false ? ' is-used' : '' ?>"><?= h($productCondition) ?></span>
            <?php endif; ?>
          </div>
        <?php endif; ?>

        <p class="pd-stock-note<?= $inStock ? '' : ' is-out' ?>">
          <?php if ($inStock): ?>
            <?= $stockCount !== null ? ((int)$stockCount . ' in stock') : 'Stock not tracked' ?>
            · <?= !empty($receive['delivery_enabled']) ? 'Delivery on' : 'Delivery off' ?>
            · <?= !empty($receive['pickup_enabled']) ? 'Pickup on' : 'Pickup off' ?>
          <?php else: ?>
            Out of stock / sold out
          <?php endif; ?>
        </p>

        <div class="pd-purchase">
          <div class="pd-purchase-row">
            <a href="<?= h($editHref) ?>" class="pd-add-cart">Edit product</a>
            <?php if ($status === 'active'): ?>
              <a href="<?= h($publicUrl) ?>" class="pd-buy-now" target="_blank" rel="noopener">View in shop</a>
            <?php else: ?>
              <button type="button" class="pd-buy-now" disabled>Not live in shop</button>
            <?php endif; ?>
          </div>
        </div>

        <nav class="pd-tabs" role="tablist" aria-label="Product information">
          <button type="button" class="pd-tab is-active" role="tab" aria-selected="true" data-tab="description">Description</button>
          <button type="button" class="pd-tab" role="tab" aria-selected="false" data-tab="details">Details</button>
          <button type="button" class="pd-tab" role="tab" aria-selected="false" data-tab="shipping">Shipping</button>
          <button type="button" class="pd-tab" role="tab" aria-selected="false" data-tab="listing">Listing</button>
        </nav>
      </div>

      <div class="pd-tab-scroll">
        <div class="pd-tab-panel is-active" role="tabpanel" data-panel="description">
          <?php if ($description !== ''): ?>
            <?php foreach ((preg_split('/\r\n\r\n|\n\n/', $description) ?: [$description]) as $para): ?>
              <?php $para = trim((string)$para); if ($para === '') continue; ?>
              <p><?= nl2br(h($para)) ?></p>
            <?php endforeach; ?>
          <?php else: ?>
            <p>No description yet. Edit this product to add one.</p>
          <?php endif; ?>
          <?php if (count($featureLines) > 1): ?>
            <p><strong>Bullet points</strong></p>
            <ul>
              <?php foreach ($featureLines as $featureLine): ?>
                <li><?= h($featureLine) ?></li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </div>

        <div class="pd-tab-panel" role="tabpanel" data-panel="details">
          <?php if ($productSpecRows || $productCode !== '' || $sku !== '' || $category !== '' || $productTypeLabel !== ''): ?>
            <table class="pd-spec-table">
              <tbody>
                <?php if ($productCode !== ''): ?>
                  <tr><th scope="row">ID</th><td><code><?= h($productCode) ?></code></td></tr>
                <?php endif; ?>
                <?php if ($productTypeLabel !== ''): ?>
                  <tr><th scope="row">Product type</th><td><?= h($productTypeLabel) ?></td></tr>
                <?php endif; ?>
                <?php if ($category !== ''): ?>
                  <tr><th scope="row">Category</th><td><?= h($category) ?></td></tr>
                <?php endif; ?>
                <?php if ($sku !== ''): ?>
                  <tr><th scope="row">SKU</th><td><?= h($sku) ?></td></tr>
                <?php endif; ?>
                <?php foreach ($productSpecRows as $specRow): ?>
                  <tr>
                    <th scope="row"><?= h($specRow['label']) ?></th>
                    <td><?= h($specRow['value']) ?></td>
                  </tr>
                <?php endforeach; ?>
                <tr>
                  <th scope="row">Fulfillment</th>
                  <td><?= $fulfillment === 'fba' ? 'FBA (platform warehouse)' : 'FBM (seller ships)' ?></td>
                </tr>
              </tbody>
            </table>
          <?php else: ?>
            <p>No details yet. Edit this product to add type-specific fields.</p>
          <?php endif; ?>
        </div>

        <div class="pd-tab-panel" role="tabpanel" data-panel="shipping">
          <table class="pd-spec-table">
            <tbody>
              <tr><th scope="row">Delivery</th><td><?= !empty($receive['delivery_enabled']) ? 'Enabled' : 'Off' ?></td></tr>
              <tr><th scope="row">Pick up</th><td><?= !empty($receive['pickup_enabled']) ? 'Enabled' : 'Off' ?></td></tr>
              <?php if (!empty($receive['carrier_labels'])): ?>
                <tr><th scope="row">Carriers</th><td><?= h(implode(', ', $receive['carrier_labels'])) ?></td></tr>
              <?php endif; ?>
              <tr><th scope="row">Shipping fee</th><td><?= h($shippingFeeLabel) ?></td></tr>
            </tbody>
          </table>
          <?php if (!empty($sellerPublicInfo['has_address']) || trim((string)($sellerPublicInfo['text'] ?? '')) !== ''): ?>
            <p style="margin-top:14px;"><strong>Pickup address buyers see</strong></p>
            <p class="pd-seller-address"><?= h((string)($sellerPublicInfo['text'] !== '' ? $sellerPublicInfo['text'] : $sellerPublicInfo['address'])) ?></p>
          <?php endif; ?>
        </div>

        <div class="pd-tab-panel" role="tabpanel" data-panel="listing">
          <table class="pd-spec-table">
            <tbody>
              <tr><th scope="row">Status</th><td><?= h($statusLabel) ?></td></tr>
              <tr><th scope="row">Offer type</th><td><?= h($offerType) ?></td></tr>
              <tr><th scope="row">Photos</th><td><?= count($galleryImages) ?></td></tr>
              <tr><th scope="row">Stock</th><td><?= $stockCount !== null ? (int)$stockCount : 'Not tracked' ?></td></tr>
            </tbody>
          </table>
          <p style="margin-top:14px;">
            <a href="<?= h($editHref) ?>">Edit this listing</a>
            <?php if ($status === 'active'): ?>
              · <a href="<?= h($publicUrl) ?>" target="_blank" rel="noopener">Open public product page</a>
            <?php endif; ?>
          </p>
        </div>
      </div>

      <div class="pd-info-foot">
        <div class="pd-badges">
          <div class="pd-badge">
            <div class="pd-badge-ic"><i class="fa fa-truck" aria-hidden="true"></i></div>
            <?= !empty($receive['delivery_enabled']) ? 'Delivery' : 'No delivery' ?>
          </div>
          <div class="pd-badge">
            <div class="pd-badge-ic"><i class="fa fa-map-marker" aria-hidden="true"></i></div>
            <?= !empty($receive['pickup_enabled']) ? 'Pickup' : 'No pickup' ?>
          </div>
          <div class="pd-badge">
            <div class="pd-badge-ic"><i class="fa fa-check" aria-hidden="true"></i></div>
            <?= $inStock ? 'In stock' : 'Out of stock' ?>
          </div>
          <div class="pd-badge">
            <div class="pd-badge-ic"><i class="fa fa-tag" aria-hidden="true"></i></div>
            <?= h(ucfirst($statusLabel)) ?>
          </div>
        </div>
      </div>
    </div>
  </div>

  <?php if ($galleryImages): ?>
  <div class="pd-lightbox" id="pdLightbox" hidden aria-hidden="true" role="dialog" aria-modal="true" aria-label="Product photos">
    <button type="button" class="pd-lightbox-close" id="pdLightboxClose" aria-label="Close photo viewer">&times;</button>
    <button type="button" class="pd-photo-count" tabindex="-1" aria-hidden="true">
      <i class="fa fa-camera" aria-hidden="true"></i>
      <span id="pdLightboxCountLabel">1 / <?= (int)count($galleryImages) ?></span>
    </button>
    <button type="button" class="pd-lightbox-nav pd-lightbox-prev" id="pdLightboxPrev" aria-label="Previous photo"<?= count($galleryImages) <= 1 ? ' disabled' : '' ?>>‹</button>
    <div class="pd-lightbox-inner">
      <img src="<?= h($galleryImages[0]) ?>" alt="<?= h($title) ?>" class="pd-lightbox-img" id="pdLightboxImage">
    </div>
    <button type="button" class="pd-lightbox-nav pd-lightbox-next" id="pdLightboxNext" aria-label="Next photo"<?= count($galleryImages) <= 1 ? ' disabled' : '' ?>>›</button>
  </div>
  <?php endif; ?>

  <script>
  (function(){
    var galleryImages = <?= json_encode(array_values($galleryImages), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    var galleryIndex = 0;
    var lightbox = document.getElementById('pdLightbox');
    var lightboxImg = document.getElementById('pdLightboxImage');
    var countLabel = document.getElementById('pdPhotoCountLabel');
    var lightboxCountLabel = document.getElementById('pdLightboxCountLabel');
    var lastFocus = null;

    function updateCountLabels(){
      if (!galleryImages.length) return;
      var label = (galleryIndex + 1) + ' / ' + galleryImages.length;
      if (countLabel) countLabel.textContent = label;
      if (lightboxCountLabel) lightboxCountLabel.textContent = label;
    }

    function setGalleryIndex(nextIndex){
      if (!galleryImages.length) return;
      galleryIndex = (nextIndex + galleryImages.length) % galleryImages.length;
      var img = document.getElementById('pdGalleryImage');
      if (img) img.src = galleryImages[galleryIndex];
      if (lightboxImg) lightboxImg.src = galleryImages[galleryIndex];
      document.querySelectorAll('.pd-gallery-thumb').forEach(function(btn){
        var idx = parseInt(btn.getAttribute('data-index') || '0', 10);
        btn.classList.toggle('is-active', idx === galleryIndex);
      });
      updateCountLabels();
    }

    function openLightbox(){
      if (!lightbox || !galleryImages.length) return;
      lastFocus = document.activeElement;
      lightbox.hidden = false;
      lightbox.classList.add('is-open');
      lightbox.setAttribute('aria-hidden', 'false');
      document.body.style.overflow = 'hidden';
      if (lightboxImg) lightboxImg.src = galleryImages[galleryIndex];
      updateCountLabels();
      var closeBtn = document.getElementById('pdLightboxClose');
      if (closeBtn) closeBtn.focus();
    }

    function closeLightbox(){
      if (!lightbox) return;
      lightbox.classList.remove('is-open');
      lightbox.hidden = true;
      lightbox.setAttribute('aria-hidden', 'true');
      document.body.style.overflow = '';
      if (lastFocus && typeof lastFocus.focus === 'function') {
        try { lastFocus.focus(); } catch (e) {}
      }
    }

    function isLightboxOpen(){
      return !!(lightbox && lightbox.classList.contains('is-open'));
    }

    var galleryPrev = document.getElementById('pdGalleryPrev');
    var galleryNext = document.getElementById('pdGalleryNext');
    if (galleryPrev) galleryPrev.addEventListener('click', function(e){ e.stopPropagation(); setGalleryIndex(galleryIndex - 1); });
    if (galleryNext) galleryNext.addEventListener('click', function(e){ e.stopPropagation(); setGalleryIndex(galleryIndex + 1); });

    document.querySelectorAll('.pd-gallery-thumb').forEach(function(btn){
      btn.addEventListener('click', function(){
        setGalleryIndex(parseInt(btn.getAttribute('data-index') || '0', 10));
      });
    });

    var stage = document.getElementById('pdGalleryStage');
    if (stage && galleryImages.length) {
      stage.addEventListener('click', function(){ openLightbox(); });
      stage.addEventListener('keydown', function(e){
        if (e.key === 'Enter' || e.key === ' ') {
          e.preventDefault();
          openLightbox();
        }
      });
    }

    var photoCountBtn = document.getElementById('pdPhotoCount');
    if (photoCountBtn) {
      photoCountBtn.addEventListener('click', function(e){
        e.stopPropagation();
        openLightbox();
      });
    }

    var lightboxPrev = document.getElementById('pdLightboxPrev');
    var lightboxNext = document.getElementById('pdLightboxNext');
    var lightboxClose = document.getElementById('pdLightboxClose');
    if (lightboxPrev) lightboxPrev.addEventListener('click', function(e){ e.stopPropagation(); setGalleryIndex(galleryIndex - 1); });
    if (lightboxNext) lightboxNext.addEventListener('click', function(e){ e.stopPropagation(); setGalleryIndex(galleryIndex + 1); });
    if (lightboxClose) lightboxClose.addEventListener('click', function(e){ e.stopPropagation(); closeLightbox(); });
    if (lightbox) {
      lightbox.addEventListener('click', function(e){
        if (e.target === lightbox) closeLightbox();
      });
    }

    document.addEventListener('keydown', function(e){
      if (!isLightboxOpen()) return;
      if (e.key === 'Escape') {
        e.preventDefault();
        closeLightbox();
      } else if (e.key === 'ArrowLeft') {
        e.preventDefault();
        setGalleryIndex(galleryIndex - 1);
      } else if (e.key === 'ArrowRight') {
        e.preventDefault();
        setGalleryIndex(galleryIndex + 1);
      }
    });

    document.querySelectorAll('.pd-tab').forEach(function(tab){
      tab.addEventListener('click', function(){
        var key = tab.getAttribute('data-tab') || 'description';
        document.querySelectorAll('.pd-tab').forEach(function(t){
          var on = t === tab;
          t.classList.toggle('is-active', on);
          t.setAttribute('aria-selected', on ? 'true' : 'false');
        });
        document.querySelectorAll('.pd-tab-panel').forEach(function(panel){
          panel.classList.toggle('is-active', panel.getAttribute('data-panel') === key);
        });
      });
    });

    updateCountLabels();
  })();
  </script>
</div>
<?php org_page_shell_close(); ?>
