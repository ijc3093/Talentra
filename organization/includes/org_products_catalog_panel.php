<?php
declare(strict_types=1);

/**
 * Shared Product catalog (PIM) form for products.php and sales_management.php#products.
 *
 * Expected vars:
 * - PDO $dbh
 * - int $orgId
 * - array|null $commerceBrand
 * - array $brandCategories
 * - array $sellingTypeOptions (optional)
 * - string $defaultFulfillment
 * - int $productCount
 * - int $maxProducts
 * - bool $shopVisible
 * - array|null $editProduct
 * - string $err
 * - string $ok
 * - string $pimFormAction (optional)
 * - string $pimTableHref (optional) link to product table
 * - string $pimCancelHref (optional)
 * - string $pimHubHref (optional)
 * - string $pimHubLabel (optional)
 */

if (!function_exists('h') && function_exists('org_ecommerce_h')) {
    function h(string $s): string
    {
        return org_ecommerce_h($s);
    }
}

$err = (string)($err ?? '');
$ok = (string)($ok ?? '');
$editProduct = is_array($editProduct ?? null) ? $editProduct : null;
$brandCategories = is_array($brandCategories ?? null) ? $brandCategories : [];
$sellingTypeOptions = is_array($sellingTypeOptions ?? null) ? $sellingTypeOptions : [];
$defaultFulfillment = (string)($defaultFulfillment ?? 'fbm');
$commerceBrandName = (string)(($commerceBrand['name'] ?? '') ?: 'Brand');
$pimFormAction = (string)($pimFormAction ?? '');
$pimTableHref = (string)($pimTableHref ?? 'product_table.php');
$pimTableAttr = (string)($pimTableAttr ?? '');
$pimCancelHref = (string)($pimCancelHref ?? 'products.php');
$pimHubHref = (string)($pimHubHref ?? 'commerce.php');
$pimHubLabel = (string)($pimHubLabel ?? 'Commerce hub');
$productCount = (int)($productCount ?? 0);
$maxProducts = (int)($maxProducts ?? 0);
$shopVisible = !empty($shopVisible);
$pimInitialAttributes = [];
if (is_array($editProduct) && !empty($editProduct['attributes_json'])) {
    $decodedAttrs = json_decode((string)$editProduct['attributes_json'], true);
    if (is_array($decodedAttrs)) {
        $pimInitialAttributes = $decodedAttrs;
    }
}
$pimExistingImages = [];
if (is_array($editProduct) && !empty($editProduct['id']) && isset($dbh) && $dbh instanceof PDO) {
    $editPid = (int)$editProduct['id'];
    $editOrg = (int)($orgId ?? 0);
    // Promote legacy cover into gallery so sellers can manage/remove it with other photos.
    $coverRel = trim((string)($editProduct['cover_image_path'] ?? ''));
    if ($coverRel !== '' && $editOrg > 0) {
        $foundCover = false;
        foreach (org_shop_list_product_images($dbh, $editPid, $editOrg) as $img) {
            if ((string)($img['file_path'] ?? '') === $coverRel) {
                $foundCover = true;
                break;
            }
        }
        if (!$foundCover) {
            org_shop_add_product_image_row($dbh, $editOrg, $editPid, $coverRel, 0);
        }
    }
    $pimExistingImages = org_shop_list_product_images($dbh, $editPid, $editOrg);
}
$pimImagesMax = function_exists('org_shop_product_images_max') ? org_shop_product_images_max() : 12;
?>
  <div class="card bd-0 shadow-base">
    <div class="card-header d-flex align-items-center justify-content-between flex-wrap">
      <div>
        <h6 class="card-title tx-uppercase tx-14 mg-b-0">Product catalog (PIM)</h6>
        <p class="mg-b-0 tx-12 tx-color-03">
          <?= $productCount ?> / <?= $maxProducts ?> on your plan
          · <?= h($commerceBrandName) ?> system
        </p>
      </div>
      <div class="d-flex" style="gap:8px;">
        <a href="<?= h($pimHubHref) ?>" class="btn btn-sm btn-outline-secondary"><?= h($pimHubLabel) ?></a>
        <?php if (!$shopVisible): ?>
          <span class="badge badge-warning align-self-center">Shop hidden — rent overdue or trial ended</span>
        <?php else: ?>
          <span class="badge badge-success align-self-center">Shop live</span>
        <?php endif; ?>
      </div>
    </div>
    <div class="card-body">
      <?php if ($err !== ''): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endif; ?>
      <?php if ($ok !== ''): ?><div class="alert alert-success"><?= h($ok) ?></div><?php endif; ?>

      <form method="post" enctype="multipart/form-data" class="mg-b-25 pim-product-form" id="pimProductForm"<?= $pimFormAction !== '' ? ' action="' . h($pimFormAction) . '"' : '' ?>>
        <input type="hidden" name="pim_action" value="1">
        <input type="hidden" name="product_id" value="<?= (int)($editProduct['id'] ?? 0) ?>">
        <div class="row">
          <div class="col-md-6">
            <div class="form-group">
              <label for="pimSellingTypeSelect">What things are you selling <span class="pim-required-star">*</span></label>
              <?php
                $selectedSellingType = trim((string)($editProduct['selling_type'] ?? ''));
                if ($selectedSellingType !== '' && !in_array($selectedSellingType, $sellingTypeOptions, true)) {
                    $sellingTypeOptions[] = $selectedSellingType;
                    natcasesort($sellingTypeOptions);
                    $sellingTypeOptions = array_values($sellingTypeOptions);
                }
              ?>
              <select name="selling_type" id="pimSellingTypeSelect" class="form-control" required>
                <option value="">Select what you sell</option>
                <?php foreach ($sellingTypeOptions as $stype): ?>
                  <option value="<?= h((string)$stype) ?>" <?= strcasecmp($selectedSellingType, (string)$stype) === 0 ? 'selected' : '' ?>><?= h((string)$stype) ?></option>
                <?php endforeach; ?>
                <option value="__add_name__">Add name…</option>
              </select>
              <small class="text-muted">Pick a type (Car, Bed, Shoe…) and the form fields change to match. Can’t find it? Choose <strong>Add name…</strong> — saved for your shop only.</small>
            </div>
          </div>
        </div>
        <div class="pim-type-fields-card" id="pimTypeFieldsWrap" style="display:none;">
          <p class="pim-type-fields-head">Product details for <strong id="pimTypeFieldsLabel">this type</strong> <span class="pim-type-fields-hint" id="pimTypeFieldsHint"></span></p>
          <div class="row" id="pimTypeFields"></div>
        </div>
        <div class="row">
          <div class="col-md-5">
            <div class="form-group">
              <label for="pimTitle">Title <span class="pim-required-star">*</span></label>
              <input type="text" name="title" id="pimTitle" class="form-control" maxlength="200" required value="<?= h((string)($editProduct['title'] ?? '')) ?>">
            </div>
          </div>
          <div class="col-md-3">
            <div class="form-group">
              <label>SKU</label>
              <input type="text" name="sku" class="form-control" maxlength="64" value="<?= h((string)($editProduct['sku'] ?? '')) ?>">
            </div>
          </div>
          <div class="col-md-2">
            <div class="form-group">
              <label for="pimPrice">Price (USD) <span class="pim-required-star">*</span></label>
              <input type="number" name="price" id="pimPrice" class="form-control" min="0" step="0.01" required value="<?= h($editProduct ? number_format(((int)($editProduct['price_cents'] ?? 0)) / 100, 2, '.', '') : '') ?>" placeholder="0.00">
            </div>
          </div>
          <div class="col-md-2">
            <div class="form-group">
              <label for="pimStock">Stock <span class="pim-required-star">*</span></label>
              <input type="number" name="stock_qty" id="pimStock" class="form-control" min="0" required placeholder="0" value="<?= isset($editProduct['stock_qty']) && $editProduct['stock_qty'] !== null ? (int)$editProduct['stock_qty'] : '' ?>">
            </div>
          </div>
        </div>
        <div class="row">
          <div class="col-md-3">
            <div class="form-group">
              <label>Offer type</label>
              <select name="offer_type" class="form-control">
                <?php foreach (['physical','digital','service','subscription','license'] as $ot): ?>
                  <option value="<?= h($ot) ?>" <?= (($editProduct['offer_type'] ?? 'physical') === $ot) ? 'selected' : '' ?>><?= h(ucfirst($ot)) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <div class="col-md-3">
            <div class="form-group">
              <label>Pricing model</label>
              <select name="pricing_model" class="form-control">
                <?php foreach (['one_time','recurring','quote','free','wholesale_tier'] as $pm): ?>
                  <option value="<?= h($pm) ?>" <?= (($editProduct['pricing_model'] ?? 'one_time') === $pm) ? 'selected' : '' ?>><?= h(str_replace('_', ' ', ucfirst($pm))) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <div class="col-md-3">
            <div class="form-group">
              <label for="pimCategorySelect">Category <span class="pim-required-star">*</span></label>
              <?php
                $selectedCategory = trim((string)($editProduct['category'] ?? ''));
                if ($selectedCategory !== '' && !in_array($selectedCategory, $brandCategories, true)) {
                    $brandCategories[] = $selectedCategory;
                    natcasesort($brandCategories);
                    $brandCategories = array_values($brandCategories);
                }
              ?>
              <select name="category" id="pimCategorySelect" class="form-control" required>
                <option value="">Select category</option>
                <?php foreach ($brandCategories as $cat): ?>
                  <option value="<?= h((string)$cat) ?>" <?= strcasecmp($selectedCategory, (string)$cat) === 0 ? 'selected' : '' ?>><?= h((string)$cat) ?></option>
                <?php endforeach; ?>
                <option value="__add_name__">Add name…</option>
              </select>
              <small class="text-muted">Same names as <strong>What things are you selling</strong>. New names you add there also show here. Or choose <strong>Add name…</strong> for a category only.</small>
            </div>
          </div>
          <div class="col-md-3">
            <div class="form-group">
              <?php $selectedStatus = strtolower(trim((string)($editProduct['status'] ?? ''))); ?>
              <label for="pimStatus">Status <span class="pim-required-star">*</span></label>
              <select name="status" id="pimStatus" class="form-control" required>
                <option value="">Select status</option>
                <?php foreach (['draft', 'active', 'sold_out', 'archived'] as $st): ?>
                  <option value="<?= h($st) ?>" <?= $selectedStatus === $st ? 'selected' : '' ?>><?= h(ucfirst(str_replace('_', ' ', $st))) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
        </div>
        <div class="row">
          <div class="col-md-6">
            <div class="form-group">
              <label>SEO title</label>
              <input type="text" name="seo_title" class="form-control" maxlength="200" value="<?= h((string)($editProduct['seo_title'] ?? '')) ?>">
            </div>
          </div>
          <div class="col-md-6">
            <div class="form-group">
              <label for="pimProductImages">Product photos <span class="pim-required-star">*</span></label>
              <input type="file" name="product_images[]" id="pimProductImages" class="form-control" accept="image/*" multiple<?= $pimExistingImages ? '' : ' required' ?>>
              <small class="text-muted">Upload multiple photos (up to <?= (int)$pimImagesMax ?>). The first photo becomes the cover buyers see in the shop.</small>
              <?php if ($pimExistingImages): ?>
                <div class="pim-photo-grid" style="display:flex;flex-wrap:wrap;gap:10px;margin-top:10px;">
                  <?php foreach ($pimExistingImages as $imgIdx => $img): ?>
                    <?php
                      $imgPath = (string)($img['file_path'] ?? '');
                      $imgUrl = org_shop_cover_url($imgPath);
                      $imgId = (int)($img['id'] ?? 0);
                      $isCover = $imgIdx === 0;
                    ?>
                    <?php if ($imgUrl === '') continue; ?>
                    <label class="pim-photo-thumb" style="display:block;width:88px;margin:0;cursor:pointer;">
                      <span style="display:block;width:88px;height:88px;border:1px solid #d1d5db;border-radius:8px;overflow:hidden;background:#f8fafc;position:relative;">
                        <img src="<?= h($imgUrl) ?>" alt="" style="width:100%;height:100%;object-fit:cover;">
                        <?php if ($isCover): ?>
                          <span style="position:absolute;left:4px;top:4px;background:#111827;color:#fff;font-size:10px;font-weight:700;padding:2px 5px;border-radius:4px;">Cover</span>
                        <?php endif; ?>
                      </span>
                      <?php if ($imgId > 0): ?>
                        <span style="display:flex;align-items:center;gap:4px;margin-top:4px;font-size:11px;color:#6b7280;">
                          <input type="checkbox" name="remove_product_image[]" value="<?= $imgId ?>"> Remove
                        </span>
                      <?php endif; ?>
                    </label>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </div>
          </div>
          <div class="col-md-12">
            <div class="form-group">
              <label>SEO description</label>
              <input type="text" name="seo_description" class="form-control" maxlength="320" value="<?= h((string)($editProduct['seo_description'] ?? '')) ?>">
            </div>
          </div>
        </div>
        <div class="form-group">
          <label for="pimDescription">Description <span class="pim-required-star">*</span></label>
          <textarea name="description" id="pimDescription" class="form-control" rows="3" required><?= h((string)($editProduct['description'] ?? '')) ?></textarea>
        </div>
        <div class="form-group">
          <label>Bullet points (one per line)</label>
          <textarea name="bullet_points" class="form-control" rows="4" placeholder="Incl. water pump and hardware&#10;100% new components&#10;OE design"><?= h((string)($editProduct['bullet_points'] ?? '')) ?></textarea>
          <small class="text-muted">Shown as feature bullets on the product page — like Amazon listing highlights.</small>
        </div>
        <div class="row">
          <div class="col-md-6">
            <div class="form-group">
              <label>Search keywords</label>
              <input type="text" name="search_keywords" class="form-control" maxlength="500" value="<?= h((string)($editProduct['search_keywords'] ?? '')) ?>" placeholder="water pump, fan clutch, engine cooling">
              <small class="text-muted">Helps buyers find your product in marketplace search.</small>
            </div>
          </div>
          <div class="col-md-6">
            <div class="form-group">
              <label>Fulfillment method</label>
              <select name="fulfillment_method" class="form-control">
                <option value="fbm" <?= (($editProduct['fulfillment_method'] ?? $defaultFulfillment) === 'fbm') ? 'selected' : '' ?>>FBM — you store &amp; ship</option>
                <option value="fba" <?= (($editProduct['fulfillment_method'] ?? $defaultFulfillment) === 'fba') ? 'selected' : '' ?>>FBA — platform warehouse ships</option>
              </select>
            </div>
          </div>
        </div>
        <?php
          if (is_array($editProduct) && $editProduct) {
              $editReceive = org_shop_product_receive_options($editProduct);
          } else {
              // New product: seller must choose Delivery and/or Pick up (no silent default).
              $editReceive = [
                  'delivery_enabled' => false,
                  'pickup_enabled' => false,
                  'carriers' => [],
                  'shipping_fee_cents' => 0,
              ];
          }
          $editCarriers = $editReceive['carriers'];
          if (!$editCarriers && $editReceive['delivery_enabled']) {
              $editCarriers = ['ups', 'fedex', 'own_trip'];
          }
          $carrierLabels = org_shop_delivery_carrier_labels();
        ?>
        <div class="row row-sm mg-t-10">
          <div class="col-md-7">
            <div class="form-group mg-b-0" id="pimReceiveOptions">
              <label class="d-block">How buyers receive this product <span class="pim-required-star">*</span></label>
              <label class="ckbox d-block mg-b-8">
                <input type="checkbox" name="delivery_enabled" value="1" <?= $editReceive['delivery_enabled'] ? 'checked' : '' ?> id="pimDeliveryEnabled">
                <span><strong>Delivery</strong> — you ship (UPS, FedEx, seller’s own trip, etc.)</span>
              </label>
              <div class="mg-l-20 mg-b-12" id="pimDeliveryCarriers" style="<?= $editReceive['delivery_enabled'] ? '' : 'display:none;' ?>">
                <small class="text-muted d-block mg-b-6">Select the trips / carriers you provide:</small>
                <?php foreach ($carrierLabels as $ckey => $clabel): ?>
                  <label class="ckbox d-inline-block mg-r-15 mg-b-6">
                    <input type="checkbox" name="delivery_carriers[]" value="<?= h($ckey) ?>" class="pim-delivery-carrier" <?= in_array($ckey, $editCarriers, true) ? 'checked' : '' ?>>
                    <span><?= h($clabel) ?></span>
                  </label>
                <?php endforeach; ?>
                <?php
                  $editShipFeeCents = (int)($editReceive['shipping_fee_cents'] ?? 0);
                  $editShipFree = $editShipFeeCents <= 0;
                  $editShipFeeDollars = number_format($editShipFeeCents / 100, 2, '.', '');
                ?>
                <div class="mg-t-10" id="pimShippingFeeBox">
                  <small class="text-muted d-block mg-b-6">Trip / shipping fee for the customer:</small>
                  <label class="rdiobox d-block mg-b-6">
                    <input type="radio" name="shipping_is_free" value="1" <?= $editShipFree ? 'checked' : '' ?> id="pimShipFree">
                    <span><strong>Free trip</strong> — customer does not pay shipping</span>
                  </label>
                  <label class="rdiobox d-block mg-b-6">
                    <input type="radio" name="shipping_is_free" value="0" <?= !$editShipFree ? 'checked' : '' ?> id="pimShipPaid">
                    <span><strong>Customer pays</strong> for the trip</span>
                  </label>
                  <div class="form-inline mg-t-6" id="pimShipFeeAmountWrap" style="<?= $editShipFree ? 'display:none;' : '' ?>">
                    <label class="mg-r-8" for="pimShipFee">Shipping fee ($)</label>
                    <input type="number" step="0.01" min="0" name="shipping_fee" id="pimShipFee" class="form-control" style="max-width:140px;" value="<?= h($editShipFree ? '0.00' : $editShipFeeDollars) ?>">
                  </div>
                </div>
              </div>
              <label class="ckbox d-block">
                <input type="checkbox" name="pickup_enabled" value="1" <?= $editReceive['pickup_enabled'] ? 'checked' : '' ?> id="pimPickupEnabled">
                <span><strong>Pick up</strong> — customer can collect at your shop</span>
              </label>
              <small class="text-muted d-block mg-t-6">Choose Delivery and/or Pick up before creating a product. Buyers will see these options in the shop Buy now door.</small>
              <p class="tx-danger tx-12 mg-b-0 mg-t-6" id="pimReceiveErr" hidden></p>
            </div>
          </div>
          <div class="col-md-5">
            <?php
              if (!isset($pimSellerAddress) || !is_array($pimSellerAddress)) {
                  $pimShopSettings = function_exists('org_ecommerce_get_shop_settings')
                      ? org_ecommerce_get_shop_settings($dbh, (int)$orgId)
                      : [];
                  $pimSellerAddress = is_array($pimShopSettings['address'] ?? null) ? $pimShopSettings['address'] : [];
              }
              $pimSellerAddressText = function_exists('org_ecommerce_format_seller_address')
                  ? org_ecommerce_format_seller_address($pimSellerAddress)
                  : '';
              $pimAddressComplete = function_exists('org_ecommerce_address_is_complete')
                  ? org_ecommerce_address_is_complete($pimSellerAddress)
                  : ($pimSellerAddressText !== '');
            ?>
            <div class="pim-full-address-card<?= $pimAddressComplete ? '' : ' is-required-missing' ?>" id="pimFullAddressCard">
              <div class="pim-full-address-head">
                <strong>Full Address <span class="pim-required-star" title="Required">*</span></strong>
                <button type="button" class="btn btn-sm btn-outline-primary" id="pimFullAddressOpen">
                  <?= $pimAddressComplete ? 'Edit' : 'Add' ?>
                </button>
              </div>
              <div class="pim-full-address-body" id="pimFullAddressDisplay">
                <?php if ($pimAddressComplete): ?>
                  <p class="pim-full-address-text mg-b-0"><?= h($pimSellerAddressText) ?></p>
                <?php else: ?>
                  <p class="pim-full-address-empty mg-b-0">Required. Add your shop / pickup address before creating a product.</p>
                <?php endif; ?>
              </div>
              <p class="pim-full-address-err tx-danger tx-12 mg-b-0 mg-t-6" id="pimFullAddressErr"<?= $pimAddressComplete ? ' hidden' : '' ?>>
                <?= $pimAddressComplete ? '' : 'Address is required to add or update a product.' ?>
              </p>
            </div>
            <input type="hidden" id="pimAddressComplete" value="<?= $pimAddressComplete ? '1' : '0' ?>">
          </div>
        </div>
        <div class="d-flex align-items-center justify-content-between flex-wrap products-form-actions">
          <div class="d-flex align-items-center flex-wrap" style="gap:8px;">
            <button type="submit" class="btn btn-primary"><?= $editProduct ? 'Update product' : 'Add product' ?></button>
            <?php if ($editProduct): ?>
              <a href="<?= h($pimCancelHref) ?>" class="btn btn-outline-secondary">Cancel edit</a>
            <?php endif; ?>
          </div>
          <a href="<?= h($pimTableHref) ?>" class="btn btn-sm btn-outline-secondary"<?= $pimTableAttr ?>><?= $editProduct ? 'View inventory' : 'View inventory' ?></a>
        </div>
      </form>
    </div>
  </div>

<div class="pim-cat-modal" id="pimSellingTypeModal" aria-hidden="true">
  <div class="pim-cat-modal-backdrop" data-close-pim-selling></div>
  <div class="pim-cat-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="pimSellingTypeModalTitle">
    <h3 id="pimSellingTypeModalTitle">Add what you sell</h3>
    <p>This name is saved for your shop only — other sellers won’t see it.</p>
    <div class="form-group mg-b-10">
      <label for="pimSellingTypeNewName">Name</label>
      <input type="text" id="pimSellingTypeNewName" class="form-control" maxlength="80" placeholder="e.g. Car">
    </div>
    <p class="tx-danger tx-12 mg-b-10" id="pimSellingTypeModalErr" hidden></p>
    <div class="d-flex" style="gap:8px;">
      <button type="button" class="btn btn-primary" id="pimSellingTypeSaveBtn">Add name</button>
      <button type="button" class="btn btn-outline-secondary" data-close-pim-selling>Cancel</button>
    </div>
  </div>
</div>
<div class="pim-cat-modal" id="pimCategoryModal" aria-hidden="true">
  <div class="pim-cat-modal-backdrop" data-close-pim-cat></div>
  <div class="pim-cat-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="pimCategoryModalTitle">
    <h3 id="pimCategoryModalTitle">Add category name</h3>
    <p>This name is saved for your shop only — other sellers won’t see it.</p>
    <div class="form-group mg-b-10">
      <label for="pimCategoryNewName">Category name</label>
      <input type="text" id="pimCategoryNewName" class="form-control" maxlength="80" placeholder="e.g. Garlic bread">
    </div>
    <p class="tx-danger tx-12 mg-b-10" id="pimCategoryModalErr" hidden></p>
    <div class="d-flex" style="gap:8px;">
      <button type="button" class="btn btn-primary" id="pimCategorySaveBtn">Add name</button>
      <button type="button" class="btn btn-outline-secondary" data-close-pim-cat>Cancel</button>
    </div>
  </div>
</div>

<div class="pim-cat-modal" id="pimFullAddressModal" aria-hidden="true">
  <div class="pim-cat-modal-backdrop" data-close-pim-address></div>
  <div class="pim-cat-modal-dialog pim-address-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="pimFullAddressModalTitle">
    <h3 id="pimFullAddressModalTitle">Full Address</h3>
    <p>Required before you can create a product. Used for pickup, invoices, and shop location matching.</p>
    <div class="form-group mg-b-10">
      <label for="pimAddrLine1">Address line 1 <span class="pim-required-star">*</span></label>
      <input type="text" id="pimAddrLine1" class="form-control" maxlength="160" value="<?= h((string)($pimSellerAddress['line1'] ?? '')) ?>" placeholder="123 Main St" required>
    </div>
    <div class="form-group mg-b-10">
      <label for="pimAddrLine2">Address line 2</label>
      <input type="text" id="pimAddrLine2" class="form-control" maxlength="160" value="<?= h((string)($pimSellerAddress['line2'] ?? '')) ?>" placeholder="Suite 200">
    </div>
    <div class="row row-sm">
      <div class="col-sm-6 form-group mg-b-10">
        <label for="pimAddrCity">City <span class="pim-required-star">*</span></label>
        <input type="text" id="pimAddrCity" class="form-control" maxlength="100" value="<?= h((string)($pimSellerAddress['city'] ?? '')) ?>" required>
      </div>
      <div class="col-sm-6 form-group mg-b-10">
        <label for="pimAddrState">State / Province <span class="pim-required-star">*</span></label>
        <input type="text" id="pimAddrState" class="form-control" maxlength="80" value="<?= h((string)($pimSellerAddress['state'] ?? '')) ?>" placeholder="TX" required>
      </div>
    </div>
    <div class="row row-sm">
      <div class="col-sm-6 form-group mg-b-10">
        <label for="pimAddrPostal">Postal code</label>
        <input type="text" id="pimAddrPostal" class="form-control" maxlength="30" value="<?= h((string)($pimSellerAddress['postal_code'] ?? '')) ?>">
      </div>
      <div class="col-sm-6 form-group mg-b-10">
        <label for="pimAddrCountry">Country</label>
        <input type="text" id="pimAddrCountry" class="form-control" maxlength="80" value="<?= h((string)($pimSellerAddress['country'] ?? '')) ?>" placeholder="United States">
      </div>
    </div>
    <p class="tx-danger tx-12 mg-b-10" id="pimFullAddressModalErr" hidden></p>
    <div class="d-flex" style="gap:8px;">
      <button type="button" class="btn btn-primary" id="pimFullAddressSaveBtn">Save</button>
      <button type="button" class="btn btn-outline-secondary" data-close-pim-address>Cancel</button>
    </div>
  </div>
</div>
<style>
  .pim-cat-modal{position:fixed;inset:0;z-index:12050;display:none;align-items:center;justify-content:center;padding:20px;}
  .pim-cat-modal.is-open{display:flex;}
  .pim-cat-modal-backdrop{position:absolute;inset:0;background:rgba(15,23,42,.55);}
  .pim-cat-modal-dialog{position:relative;z-index:1;width:min(420px,100%);border:1px solid rgba(148,163,184,.35);border-radius:10px;background:var(--msb-palette-bg,#111827);color:inherit;padding:18px 18px 16px;box-shadow:0 18px 48px rgba(0,0,0,.35);}
  .pim-cat-modal-dialog h3{margin:0 0 6px;font-size:18px;font-weight:800;}
  .pim-cat-modal-dialog > p{margin:0 0 12px;font-size:13px;opacity:.8;}
  .pim-type-fields-card{border:1px solid rgba(148,163,184,.35);border-radius:8px;padding:14px 14px 4px;margin:0 0 16px;background:rgba(15,23,42,.04);}
  .pim-type-fields-head{margin:0 0 12px;font-size:13px;}
  .pim-type-fields-hint{font-weight:400;opacity:.75;}
  .pim-full-address-card{
    border:1px dashed rgba(148,163,184,.45);
    border-radius:8px;
    padding:12px 14px;
    min-height:140px;
    background:rgba(15,23,42,.04);
  }
  .pim-full-address-head{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:10px;
    margin-bottom:10px;
  }
  .pim-full-address-text{
    white-space:pre-line;
    font-size:13px;
    line-height:1.45;
    margin:0;
  }
  .pim-full-address-empty{
    font-size:12px;
    opacity:.75;
    line-height:1.4;
  }
  .pim-full-address-card.is-required-missing{
    border-color:#f87171;
    background:rgba(248,113,113,.08);
  }
  .pim-required-star{color:#f87171;font-weight:700;}
  .pim-address-modal-dialog{width:min(520px,100%);}
</style>
<script>
(function () {
  var pimInitialAttributes = <?= json_encode($pimInitialAttributes, JSON_UNESCAPED_UNICODE) ?>;
  var deliv = document.getElementById('pimDeliveryEnabled');
  var carriers = document.getElementById('pimDeliveryCarriers');
  var shipFree = document.getElementById('pimShipFree');
  var shipPaid = document.getElementById('pimShipPaid');
  var feeWrap = document.getElementById('pimShipFeeAmountWrap');
  function syncDelivery() {
    if (carriers) carriers.style.display = (deliv && deliv.checked) ? '' : 'none';
  }
  function syncFee() {
    if (!feeWrap) return;
    feeWrap.style.display = (shipPaid && shipPaid.checked) ? '' : 'none';
  }
  if (deliv) deliv.addEventListener('change', syncDelivery);
  if (shipFree) shipFree.addEventListener('change', syncFee);
  if (shipPaid) shipPaid.addEventListener('change', syncFee);
  syncDelivery();
  syncFee();

  /* Full Address modal + required gate */
  (function () {
    var addrModal = document.getElementById('pimFullAddressModal');
    var openBtn = document.getElementById('pimFullAddressOpen');
    var saveBtn = document.getElementById('pimFullAddressSaveBtn');
    var display = document.getElementById('pimFullAddressDisplay');
    var card = document.getElementById('pimFullAddressCard');
    var cardErr = document.getElementById('pimFullAddressErr');
    var modalErr = document.getElementById('pimFullAddressModalErr');
    var completeFlag = document.getElementById('pimAddressComplete');
    var productForm = document.querySelector('form[name="pim_product_form"], form.pim-product-form, #pimProductForm');
    if (!productForm) {
      productForm = document.querySelector('input[name="pim_action"]')
        ? document.querySelector('input[name="pim_action"]').closest('form')
        : null;
    }
    if (!addrModal || !openBtn) return;

    var addressComplete = !!(completeFlag && completeFlag.value === '1');

    function setModalErr(msg) {
      if (!modalErr) return;
      if (!msg) { modalErr.hidden = true; modalErr.textContent = ''; return; }
      modalErr.hidden = false;
      modalErr.textContent = msg;
    }
    function setCardErr(msg) {
      if (!cardErr) return;
      if (!msg) { cardErr.hidden = true; cardErr.textContent = ''; return; }
      cardErr.hidden = false;
      cardErr.textContent = msg;
    }
    function markComplete(on) {
      addressComplete = !!on;
      if (completeFlag) completeFlag.value = on ? '1' : '0';
      if (card) card.classList.toggle('is-required-missing', !on);
      if (on) setCardErr('');
      else setCardErr('Address is required to add or update a product.');
    }
    function openAddr() {
      addrModal.classList.add('is-open');
      addrModal.setAttribute('aria-hidden', 'false');
      setModalErr('');
      var first = document.getElementById('pimAddrLine1');
      if (first) first.focus();
    }
    function closeAddr() {
      addrModal.classList.remove('is-open');
      addrModal.setAttribute('aria-hidden', 'true');
      setModalErr('');
    }
    openBtn.addEventListener('click', function (e) {
      e.preventDefault();
      openAddr();
    });
    addrModal.querySelectorAll('[data-close-pim-address]').forEach(function (el) {
      el.addEventListener('click', closeAddr);
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && addrModal.classList.contains('is-open')) closeAddr();
    });

    if (productForm) {
      productForm.addEventListener('submit', function (e) {
        var receiveErr = document.getElementById('pimReceiveErr');
        function setReceiveErr(msg) {
          if (!receiveErr) return;
          if (!msg) { receiveErr.hidden = true; receiveErr.textContent = ''; return; }
          receiveErr.hidden = false;
          receiveErr.textContent = msg;
        }
        setReceiveErr('');

        var sell = document.getElementById('pimSellingTypeSelect');
        if (sell && (!sell.value || sell.value === '__add_name__')) {
          e.preventDefault();
          sell.focus();
          return;
        }
        var cat = document.getElementById('pimCategorySelect');
        if (cat && (!cat.value || cat.value === '__add_name__')) {
          e.preventDefault();
          cat.focus();
          return;
        }
        var statusEl = document.getElementById('pimStatus');
        if (statusEl && !statusEl.value) {
          e.preventDefault();
          statusEl.focus();
          return;
        }
        var stockEl = document.getElementById('pimStock');
        if (stockEl && String(stockEl.value || '').trim() === '') {
          e.preventDefault();
          stockEl.focus();
          return;
        }
        var descEl = document.getElementById('pimDescription');
        if (descEl && String(descEl.value || '').trim() === '') {
          e.preventDefault();
          descEl.focus();
          return;
        }

        var delivOn = !!(deliv && deliv.checked);
        var pickupEl = document.getElementById('pimPickupEnabled');
        var pickupOn = !!(pickupEl && pickupEl.checked);
        if (!delivOn && !pickupOn) {
          e.preventDefault();
          setReceiveErr('Choose Delivery and/or Pick up before creating a product.');
          var receiveBox = document.getElementById('pimReceiveOptions');
          if (receiveBox) receiveBox.scrollIntoView({ behavior: 'smooth', block: 'center' });
          return;
        }
        if (delivOn) {
          var anyCarrier = false;
          document.querySelectorAll('.pim-delivery-carrier').forEach(function (cb) {
            if (cb.checked) anyCarrier = true;
          });
          if (!anyCarrier) {
            e.preventDefault();
            setReceiveErr('Select at least one delivery carrier / trip.');
            if (carriers) carriers.scrollIntoView({ behavior: 'smooth', block: 'center' });
            return;
          }
        }

        if (!addressComplete) {
          e.preventDefault();
          setCardErr('Add your Full Address before you can create or update a product.');
          if (card) {
            card.scrollIntoView({ behavior: 'smooth', block: 'center' });
          }
          openAddr();
          return;
        }

        var imgInput = document.getElementById('pimProductImages');
        var hasExisting = <?= $pimExistingImages ? 'true' : 'false' ?>;
        if (imgInput && imgInput.files && imgInput.files.length === 0 && !hasExisting) {
          e.preventDefault();
          imgInput.focus();
          return;
        }
      });
    }

    if (saveBtn) {
      saveBtn.addEventListener('click', function () {
        setModalErr('');
        var line1 = ((document.getElementById('pimAddrLine1') || {}).value || '').trim();
        var city = ((document.getElementById('pimAddrCity') || {}).value || '').trim();
        var state = ((document.getElementById('pimAddrState') || {}).value || '').trim();
        if (!line1 || !city || !state) {
          setModalErr('Address line 1, city, and state are required.');
          return;
        }
        saveBtn.disabled = true;
        var body = new URLSearchParams();
        body.set('address_line1', line1);
        body.set('address_line2', (document.getElementById('pimAddrLine2') || {}).value || '');
        body.set('address_city', city);
        body.set('address_state', state);
        body.set('address_postal_code', (document.getElementById('pimAddrPostal') || {}).value || '');
        body.set('address_country', (document.getElementById('pimAddrCountry') || {}).value || '');
        fetch('ajax/save_seller_address.php', {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Accept': 'application/json', 'Content-Type': 'application/x-www-form-urlencoded' },
          body: body.toString()
        })
          .then(function (r) { return r.json(); })
          .then(function (data) {
            saveBtn.disabled = false;
            if (!data || !data.ok) {
              setModalErr((data && data.error) || 'Could not save address.');
              return;
            }
            var text = (data.address_text || '').trim();
            if (display) {
              if (text !== '') {
                display.innerHTML = '<p class="pim-full-address-text mg-b-0"></p>';
                display.querySelector('.pim-full-address-text').textContent = text;
              } else {
                display.innerHTML = '<p class="pim-full-address-empty mg-b-0">Required. Add your shop / pickup address before creating a product.</p>';
              }
            }
            openBtn.textContent = text !== '' ? 'Edit' : 'Add';
            markComplete(text !== '');
            closeAddr();
            try {
              document.dispatchEvent(new CustomEvent('msb:seller-address-updated', {
                detail: {
                  address: data.address || {},
                  address_text: text
                }
              }));
            } catch (err) { /* ignore */ }
          })
          .catch(function () {
            saveBtn.disabled = false;
            setModalErr('Could not save address. Try again.');
          });
      });
    }

    markComplete(addressComplete);
  })();

  var catSelect = document.getElementById('pimCategorySelect');
  var modal = document.getElementById('pimCategoryModal');
  var nameInput = document.getElementById('pimCategoryNewName');
  var errEl = document.getElementById('pimCategoryModalErr');
  var saveBtn = document.getElementById('pimCategorySaveBtn');
  var lastCategory = catSelect ? String(catSelect.value || '') : '';
  if (lastCategory === '__add_name__') lastCategory = '';

  function openCatModal() {
    if (!modal) return;
    if (errEl) { errEl.hidden = true; errEl.textContent = ''; }
    if (nameInput) { nameInput.value = ''; }
    modal.classList.add('is-open');
    modal.setAttribute('aria-hidden', 'false');
    if (nameInput) setTimeout(function(){ nameInput.focus(); }, 30);
  }
  function closeCatModal(restore) {
    if (!modal) return;
    modal.classList.remove('is-open');
    modal.setAttribute('aria-hidden', 'true');
    if (restore && catSelect) {
      catSelect.value = lastCategory || '';
    }
  }
  function rebuildCategoryOptions(categories, selected) {
    if (!catSelect) return;
    var html = '<option value="">Select category</option>';
    (categories || []).forEach(function(name){
      var safe = String(name || '');
      if (!safe) return;
      html += '<option value="' + safe.replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;') + '"'
        + (safe === selected ? ' selected' : '') + '>'
        + safe.replace(/&/g,'&amp;').replace(/</g,'&lt;') + '</option>';
    });
    html += '<option value="__add_name__">Add name…</option>';
    catSelect.innerHTML = html;
    if (selected) catSelect.value = selected;
    lastCategory = selected || '';
  }

  if (catSelect) {
    catSelect.addEventListener('change', function(){
      if (catSelect.value === '__add_name__') {
        openCatModal();
        return;
      }
      lastCategory = catSelect.value;
    });
  }
  Array.prototype.slice.call(document.querySelectorAll('[data-close-pim-cat]')).forEach(function(btn){
    btn.addEventListener('click', function(){ closeCatModal(true); });
  });
  document.addEventListener('keydown', function(e){
    if (e.key === 'Escape' && modal && modal.classList.contains('is-open')) {
      closeCatModal(true);
    }
  });
  if (saveBtn) {
    saveBtn.addEventListener('click', async function(){
      var name = nameInput ? String(nameInput.value || '').trim() : '';
      if (!name) {
        if (errEl) { errEl.hidden = false; errEl.textContent = 'Enter a category name.'; }
        return;
      }
      saveBtn.disabled = true;
      try {
        var body = new URLSearchParams();
        body.set('name', name);
        var res = await fetch('ajax/product_category_add.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: body.toString(),
          credentials: 'same-origin'
        });
        var data = await res.json();
        if (!data.ok) {
          if (errEl) { errEl.hidden = false; errEl.textContent = data.message || 'Could not save.'; }
          return;
        }
        rebuildCategoryOptions(data.categories || [], data.name || name);
        closeCatModal(false);
      } catch (e) {
        if (errEl) { errEl.hidden = false; errEl.textContent = 'Could not save category.'; }
      } finally {
        saveBtn.disabled = false;
      }
    });
  }
  if (nameInput) {
    nameInput.addEventListener('keydown', function(e){
      if (e.key === 'Enter') {
        e.preventDefault();
        if (saveBtn) saveBtn.click();
      }
    });
  }

  var sellSelect = document.getElementById('pimSellingTypeSelect');
  var sellModal = document.getElementById('pimSellingTypeModal');
  var sellNameInput = document.getElementById('pimSellingTypeNewName');
  var sellErrEl = document.getElementById('pimSellingTypeModalErr');
  var sellSaveBtn = document.getElementById('pimSellingTypeSaveBtn');
  var lastSellingType = sellSelect ? String(sellSelect.value || '') : '';
  if (lastSellingType === '__add_name__') lastSellingType = '';

  function openSellModal() {
    if (!sellModal) return;
    if (sellErrEl) { sellErrEl.hidden = true; sellErrEl.textContent = ''; }
    if (sellNameInput) sellNameInput.value = '';
    sellModal.classList.add('is-open');
    sellModal.setAttribute('aria-hidden', 'false');
    if (sellNameInput) setTimeout(function(){ sellNameInput.focus(); }, 30);
  }
  function closeSellModal(restore) {
    if (!sellModal) return;
    sellModal.classList.remove('is-open');
    sellModal.setAttribute('aria-hidden', 'true');
    if (restore && sellSelect) sellSelect.value = lastSellingType || '';
  }
  function rebuildSellingOptions(types, selected) {
    if (!sellSelect) return;
    var html = '<option value="">Select what you sell</option>';
    (types || []).forEach(function(name){
      var safe = String(name || '');
      if (!safe) return;
      html += '<option value="' + safe.replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;') + '"'
        + (safe === selected ? ' selected' : '') + '>'
        + safe.replace(/&/g,'&amp;').replace(/</g,'&lt;') + '</option>';
    });
    html += '<option value="__add_name__">Add name…</option>';
    sellSelect.innerHTML = html;
    if (selected) sellSelect.value = selected;
    lastSellingType = selected || '';
  }
  if (sellSelect) {
    sellSelect.addEventListener('change', function(){
      if (sellSelect.value === '__add_name__') {
        openSellModal();
        return;
      }
      snapshotTypeAttrValues();
      lastSellingType = sellSelect.value;
      loadTypeSchema(sellSelect.value);
    });
  }

  var typeWrap = document.getElementById('pimTypeFieldsWrap');
  var typeFields = document.getElementById('pimTypeFields');
  var typeLabel = document.getElementById('pimTypeFieldsLabel');
  var typeHint = document.getElementById('pimTypeFieldsHint');
  var typeAttrValues = Object.assign({}, pimInitialAttributes || {});

  function snapshotTypeAttrValues() {
    if (!typeFields) return;
    typeFields.querySelectorAll('[name^="product_attr["]').forEach(function(el) {
      var m = el.name.match(/product_attr\[([^\]]+)\]/);
      if (m) typeAttrValues[m[1]] = el.value;
    });
  }

  function escHtml(s) {
    return String(s || '').replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;');
  }
  function renderTypeField(field, colClass) {
    var key = String(field.key || '');
    var label = String(field.label || key);
    var type = String(field.type || 'text');
    var req = field.required ? ' required' : '';
    var val = escHtml(typeAttrValues[key] || '');
    var ph = field.placeholder ? ' placeholder="' + escHtml(field.placeholder) + '"' : '';
    var name = 'product_attr[' + key + ']';
    var min = field.min !== undefined ? ' min="' + field.min + '"' : '';
    var max = field.max !== undefined ? ' max="' + field.max + '"' : '';
    var unit = field.unit ? ' <small class="text-muted">(' + escHtml(field.unit) + ')</small>' : '';
    var input = '';
    if (type === 'select' && Array.isArray(field.options)) {
      input = '<select name="' + name + '" class="form-control"' + req + '>';
      input += '<option value="">Select</option>';
      field.options.forEach(function(opt){
        var sel = String(typeAttrValues[key] || '') === String(opt) ? ' selected' : '';
        input += '<option value="' + escHtml(opt) + '"' + sel + '>' + escHtml(opt) + '</option>';
      });
      input += '</select>';
    } else {
      input = '<input type="' + (type === 'number' ? 'number' : 'text') + '" name="' + name + '" class="form-control" value="' + val + '"' + ph + min + max + req + '>';
    }
    return '<div class="' + colClass + '"><div class="form-group"><label>' + escHtml(label) + unit + '</label>' + input + '</div></div>';
  }
  function renderTypeFields(schema) {
    if (!typeFields || !typeWrap) return;
    if (!schema || !schema.fields || !schema.fields.length) {
      typeWrap.style.display = 'none';
      typeFields.innerHTML = '';
      return;
    }
    typeWrap.style.display = '';
    if (typeLabel) typeLabel.textContent = schema.label || schema.slug || 'this type';
    if (typeHint) typeHint.textContent = schema.slug ? ('— matched as ' + schema.slug) : '';
    var cols = schema.fields.length <= 4 ? 'col-md-6' : 'col-md-4';
    typeFields.innerHTML = schema.fields.map(function(f){ return renderTypeField(f, cols); }).join('');
  }
  async function loadTypeSchema(sellingType) {
    snapshotTypeAttrValues();
    sellingType = String(sellingType || '').trim();
    if (!sellingType || sellingType === '__add_name__') {
      renderTypeFields(null);
      return;
    }
    try {
      var res = await fetch('ajax/product_type_schema.php?selling_type=' + encodeURIComponent(sellingType), { credentials: 'same-origin' });
      var data = await res.json();
      if (data.ok) renderTypeFields(data);
      else renderTypeFields(null);
    } catch (e) {
      renderTypeFields(null);
    }
  }
  if (sellSelect && sellSelect.value && sellSelect.value !== '__add_name__') {
    loadTypeSchema(sellSelect.value);
  }
  Array.prototype.slice.call(document.querySelectorAll('[data-close-pim-selling]')).forEach(function(btn){
    btn.addEventListener('click', function(){ closeSellModal(true); });
  });
  if (sellSaveBtn) {
    sellSaveBtn.addEventListener('click', async function(){
      var name = sellNameInput ? String(sellNameInput.value || '').trim() : '';
      if (!name) {
        if (sellErrEl) { sellErrEl.hidden = false; sellErrEl.textContent = 'Enter a name.'; }
        return;
      }
      sellSaveBtn.disabled = true;
      try {
        var body = new URLSearchParams();
        body.set('name', name);
        var res = await fetch('ajax/product_selling_type_add.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: body.toString(),
          credentials: 'same-origin'
        });
        var data = await res.json();
        if (!data.ok) {
          if (sellErrEl) { sellErrEl.hidden = false; sellErrEl.textContent = data.message || 'Could not save.'; }
          return;
        }
        rebuildSellingOptions(data.selling_types || [], data.name || name);
        var keepCat = catSelect ? String(catSelect.value || '') : '';
        if (keepCat === '__add_name__') keepCat = lastCategory || '';
        rebuildCategoryOptions(data.categories || data.selling_types || [], keepCat);
        closeSellModal(false);
        loadTypeSchema(data.name || name);
      } catch (e) {
        if (sellErrEl) { sellErrEl.hidden = false; sellErrEl.textContent = 'Could not save.'; }
      } finally {
        sellSaveBtn.disabled = false;
      }
    });
  }
  if (sellNameInput) {
    sellNameInput.addEventListener('keydown', function(e){
      if (e.key === 'Enter') {
        e.preventDefault();
        if (sellSaveBtn) sellSaveBtn.click();
      }
    });
  }
})();
</script>
