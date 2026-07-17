<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/session_org.php';
require_once __DIR__ . '/includes/org_context.php';
require_once __DIR__ . '/includes/org_ecommerce.php';
require_once __DIR__ . '/includes/org_manager_guard.php';

org_require_manager();

org_require_commerce_seller();

$orgId = (int)orgActiveOrgId();
org_ecommerce_ensure_schema($dbh);
$settings = org_ecommerce_ensure_seller_info_seeded($dbh, $orgId);
$settings = org_ecommerce_get_shop_settings_for_display($dbh, $orgId);
$businessModel = org_ecommerce_get_business_model($dbh, $orgId);
$sellerPlan = org_shop_get_seller_plan($dbh, $orgId);

$err = '';
$ok = '';
if (!empty($_SESSION['org_shop_flash_ok'])) {
    $ok = (string)$_SESSION['org_shop_flash_ok'];
    unset($_SESSION['org_shop_flash_ok']);
}
if (!empty($_SESSION['org_shop_flash_err'])) {
    $err = (string)$_SESSION['org_shop_flash_err'];
    unset($_SESSION['org_shop_flash_err']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string)($_POST['shop_settings_action'] ?? 'save_all'));

    if ($action === 'save_seller_info') {
        $saveResult = org_ecommerce_save_seller_profile_from_post($dbh, $orgId, $_POST);
        if (!empty($saveResult['ok'])) {
            $_SESSION['org_shop_flash_ok'] = 'Seller information updated. Buyers will see these details on orders and invoices.';
        } else {
            $_SESSION['org_shop_flash_err'] = (string)($saveResult['error'] ?? 'Could not save seller information.');
        }
        header('Location: shop_settings.php#seller-information');
        exit;
    }

    $newPlan = strtolower(trim((string)($_POST['seller_plan'] ?? '')));
    if ($newPlan !== '' && org_shop_save_seller_plan($dbh, $orgId, $newPlan)) {
        $sellerPlan = $newPlan;
        $ok = 'Seller plan updated.';
    }

    $newModel = strtolower(trim((string)($_POST['business_model'] ?? '')));
    if ($newModel !== '' && org_ecommerce_save_business_model($dbh, $orgId, $newModel)) {
        $businessModel = $newModel;
        $ok = 'Business model updated.';
    }

    $channels = [
        'profile_shop' => !empty($_POST['channel_profile_shop']),
        'marketplace' => !empty($_POST['channel_marketplace']),
        'social_feed' => !empty($_POST['channel_social_feed']),
    ];
    $paymentMethods = [];
    if (!empty($_POST['pay_stripe'])) {
        $paymentMethods[] = 'stripe';
    }
    if (!empty($_POST['pay_manual'])) {
        $paymentMethods[] = 'manual';
    }
    if ($paymentMethods === []) {
        $paymentMethods = ['manual'];
    }

    $payload = [
        'store_name' => trim((string)($_POST['store_name'] ?? ($settings['store_name'] ?? ''))),
        'tagline' => trim((string)($_POST['tagline'] ?? ($settings['tagline'] ?? ''))),
        'contact_email' => trim((string)($_POST['contact_email'] ?? ($settings['contact_email'] ?? ''))),
        'contact_phone' => trim((string)($_POST['contact_phone'] ?? ($settings['contact_phone'] ?? ''))),
        'delivery_notes' => trim((string)($_POST['delivery_notes'] ?? '')),
        'fulfillment_policy' => trim((string)($_POST['fulfillment_policy'] ?? '')),
        'return_policy' => trim((string)($_POST['return_policy'] ?? '')),
        'default_fulfillment_method' => in_array(strtolower(trim((string)($_POST['default_fulfillment_method'] ?? 'fbm'))), ['fba', 'fbm'], true)
            ? strtolower(trim((string)($_POST['default_fulfillment_method'] ?? 'fbm')))
            : 'fbm',
        'payment_methods' => $paymentMethods,
        'channels' => $channels,
        'seo' => [
            'meta_description' => trim((string)($_POST['seo_meta_description'] ?? '')),
        ],
        'address' => is_array($settings['address'] ?? null) ? $settings['address'] : [],
    ];

    if (org_ecommerce_save_shop_settings($dbh, $orgId, $payload)) {
        $ok = $ok !== '' ? $ok . ' Store settings saved.' : 'Store settings saved.';
        $settings = org_ecommerce_get_shop_settings_for_display($dbh, $orgId);
    } else {
        $err = 'Could not save store settings.';
    }
}

$settings = org_ecommerce_get_shop_settings_for_display($dbh, $orgId);
$addr = is_array($settings['address'] ?? null) ? $settings['address'] : [];
$sellerAddressText = org_ecommerce_format_seller_address($addr);
$sellerName = trim((string)($settings['store_name'] ?? '')) ?: 'Seller';
$sellerFullName = trim((string)($settings['full_name'] ?? ''));
$sellerEmail = trim((string)($settings['contact_email'] ?? ''));
$sellerPhone = trim((string)($settings['contact_phone'] ?? ''));
$sellerTagline = trim((string)($settings['tagline'] ?? ''));

$pageTitle = 'Store settings';
require_once __DIR__ . '/includes/org_page_shell.php';
org_page_shell_open($pageTitle, '<style>
.seller-info-view{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px;margin-bottom:18px;}
.seller-info-card{border:1px solid rgba(148,163,184,.35);border-radius:6px;background:var(--card-bg,#fff);padding:14px 16px;}
.seller-info-card-head{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:10px;}
.seller-info-card-head h3{margin:0;font-size:14px;font-weight:700;}
.seller-info-edit{border:1px solid rgba(148,163,184,.45);border-radius:4px;background:transparent;padding:5px 10px;font-size:12px;font-weight:700;cursor:pointer;}
.seller-info-line{margin:0 0 7px;font-size:13px;line-height:1.45;}
.seller-info-line strong{display:inline-block;min-width:70px;color:#64748b;}
.seller-info-muted{color:#64748b;}
.seller-info-block{white-space:pre-line;margin:0;font-size:13px;line-height:1.5;}
.seller-info-modal{position:fixed;inset:0;z-index:12000;display:none;align-items:center;justify-content:center;padding:20px;}
.seller-info-modal.is-open{display:flex;}
.seller-info-modal-backdrop{position:absolute;inset:0;background:rgba(15,23,42,.55);}
.seller-info-modal-dialog{position:relative;z-index:1;width:min(560px,100%);max-height:min(90vh,740px);overflow:auto;border-radius:8px;background:var(--card-bg,#fff);padding:18px;box-shadow:0 18px 48px rgba(0,0,0,.28);}
.seller-info-modal-dialog h3{margin:0 0 6px;font-size:18px;font-weight:700;}
.seller-info-modal-dialog>p{margin:0 0 14px;font-size:13px;color:#64748b;}
.seller-info-modal-actions{display:flex;flex-wrap:wrap;gap:8px;margin-top:8px;}
@media (max-width:700px){.seller-info-view{grid-template-columns:1fr;}}
</style>');
?>
<?php org_page_body_open(); ?>
  <div class="mg-b-15">
    <a href="commerce.php" class="tx-12">&larr; Commerce hub</a>
    <h4 class="mg-b-0">Store settings</h4>
    <p class="tx-color-03">Seller contact loads from your organization/publisher account, then you can edit what buyers see.</p>
  </div>

  <?php if ($err): ?><div class="alert alert-danger"><?= org_ecommerce_h($err) ?></div><?php endif; ?>
  <?php if ($ok): ?><div class="alert alert-success"><?= org_ecommerce_h($ok) ?></div><?php endif; ?>

  <div id="seller-information" class="mg-b-10">
    <h5 class="mg-b-10">Seller information</h5>
    <div class="seller-info-view">
      <div class="seller-info-card">
        <div class="seller-info-card-head">
          <h3>Contact</h3>
          <button type="button" class="seller-info-edit" data-open-seller-modal>Edit</button>
        </div>
        <p class="seller-info-line"><strong>Name</strong> <?= org_ecommerce_h($sellerName) ?></p>
        <?php if ($sellerFullName !== ''): ?>
          <p class="seller-info-line"><strong>Full name</strong> <?= org_ecommerce_h($sellerFullName) ?></p>
        <?php endif; ?>
        <p class="seller-info-line"><strong>Email</strong> <?= $sellerEmail !== '' ? org_ecommerce_h($sellerEmail) : '<span class="seller-info-muted">Not set</span>' ?></p>
        <p class="seller-info-line"><strong>Phone</strong> <?= $sellerPhone !== '' ? org_ecommerce_h($sellerPhone) : '<span class="seller-info-muted">Not set</span>' ?></p>
        <?php if ($sellerTagline !== ''): ?>
          <p class="seller-info-line"><strong>Tagline</strong> <?= org_ecommerce_h($sellerTagline) ?></p>
        <?php endif; ?>
      </div>
      <div class="seller-info-card">
        <div class="seller-info-card-head">
          <h3>Business address</h3>
          <button type="button" class="seller-info-edit" data-open-seller-modal><?= $sellerAddressText !== '' ? 'Edit' : 'Add' ?></button>
        </div>
        <?php if ($sellerAddressText !== ''): ?>
          <p class="seller-info-block"><?= org_ecommerce_h($sellerAddressText) ?></p>
        <?php else: ?>
          <p class="seller-info-muted mg-b-0">No business address yet. Click Add so buyers can see it on order details.</p>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="seller-info-modal" id="sellerInfoModal" aria-hidden="true">
    <div class="seller-info-modal-backdrop" data-close-seller-modal></div>
    <div class="seller-info-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="sellerInfoTitle">
      <h3 id="sellerInfoTitle">Edit seller information</h3>
      <p>These details appear to buyers on orders, invoices, and seller contact.</p>
      <form method="post" action="shop_settings.php#seller-information">
        <input type="hidden" name="shop_settings_action" value="save_seller_info">
        <div class="form-group"><label>Full name</label><input name="full_name" class="form-control" value="<?= org_ecommerce_h($sellerFullName) ?>" placeholder="Jane Seller"></div>
        <div class="form-group"><label>Store / seller name</label><input name="store_name" class="form-control" value="<?= org_ecommerce_h($sellerName) ?>" required></div>
        <div class="form-group"><label>Tagline</label><input name="tagline" class="form-control" value="<?= org_ecommerce_h($sellerTagline) ?>"></div>
        <div class="form-group"><label>Contact email</label><input name="contact_email" type="email" class="form-control" value="<?= org_ecommerce_h($sellerEmail) ?>"></div>
        <div class="form-group"><label>Contact phone</label><input name="contact_phone" class="form-control" value="<?= org_ecommerce_h($sellerPhone) ?>"></div>
        <hr>
        <div class="form-group"><label>Address line 1</label><input name="address_line1" class="form-control" value="<?= org_ecommerce_h((string)($addr['line1'] ?? '')) ?>"></div>
        <div class="form-group"><label>Address line 2</label><input name="address_line2" class="form-control" value="<?= org_ecommerce_h((string)($addr['line2'] ?? '')) ?>"></div>
        <div class="row row-sm">
          <div class="col-sm-6 form-group"><label>City</label><input name="address_city" class="form-control" value="<?= org_ecommerce_h((string)($addr['city'] ?? '')) ?>"></div>
          <div class="col-sm-6 form-group"><label>State / Province</label><input name="address_state" class="form-control" value="<?= org_ecommerce_h((string)($addr['state'] ?? '')) ?>"></div>
        </div>
        <div class="row row-sm">
          <div class="col-sm-6 form-group"><label>Postal code</label><input name="address_postal_code" class="form-control" value="<?= org_ecommerce_h((string)($addr['postal_code'] ?? '')) ?>"></div>
          <div class="col-sm-6 form-group"><label>Country</label><input name="address_country" class="form-control" value="<?= org_ecommerce_h((string)($addr['country'] ?? '')) ?>"></div>
        </div>
        <div class="seller-info-modal-actions">
          <button type="submit" class="btn btn-primary btn-sm">Save</button>
          <button type="button" class="btn btn-outline-secondary btn-sm" data-close-seller-modal>Cancel</button>
        </div>
      </form>
    </div>
  </div>

  <form method="post">
    <input type="hidden" name="shop_settings_action" value="save_all">
    <input type="hidden" name="store_name" value="<?= org_ecommerce_h($sellerName) ?>">
    <input type="hidden" name="tagline" value="<?= org_ecommerce_h($sellerTagline) ?>">
    <input type="hidden" name="contact_email" value="<?= org_ecommerce_h($sellerEmail) ?>">
    <input type="hidden" name="contact_phone" value="<?= org_ecommerce_h($sellerPhone) ?>">
    <div class="row row-sm">
      <div class="col-lg-6 mg-b-20">
        <div class="card shadow-base">
          <div class="card-header"><h6 class="card-title tx-14 mg-b-0">Seller account plan</h6></div>
          <div class="card-body">
            <p class="tx-12 tx-color-03">Like Amazon Individual vs Professional — controls per-order fees.</p>
            <select name="seller_plan" class="form-control mg-b-10">
              <option value="individual" <?= $sellerPlan === 'individual' ? 'selected' : '' ?>>Individual — $0.99 per order sold</option>
              <option value="professional" <?= $sellerPlan === 'professional' ? 'selected' : '' ?>>Professional — no per-order fee (monthly shop rent applies)</option>
            </select>
            <label>Default fulfillment for new listings</label>
            <select name="default_fulfillment_method" class="form-control">
              <?php $defaultFulfill = (string)($settings['default_fulfillment_method'] ?? 'fbm'); ?>
              <option value="fbm" <?= $defaultFulfill === 'fbm' ? 'selected' : '' ?>>FBM — Fulfillment by Merchant (you ship)</option>
              <option value="fba" <?= $defaultFulfill === 'fba' ? 'selected' : '' ?>>FBA — Fulfillment by Platform (warehouse ships)</option>
            </select>
          </div>
        </div>
      </div>

      <div class="col-lg-6 mg-b-20">
        <div class="card shadow-base">
          <div class="card-header"><h6 class="card-title tx-14 mg-b-0">Business model</h6></div>
          <div class="card-body">
            <p class="tx-12 tx-color-03">Match your store type to how you sell — B2C retail, B2B wholesale, subscriptions, or affiliate.</p>
            <select name="business_model" class="form-control">
              <?php foreach (org_ecommerce_business_models() as $key => $label): ?>
                <option value="<?= org_ecommerce_h($key) ?>" <?= $businessModel === $key ? 'selected' : '' ?>><?= org_ecommerce_h($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
      </div>

      <div class="col-lg-6 mg-b-20">
        <div class="card shadow-base">
          <div class="card-header"><h6 class="card-title tx-14 mg-b-0">Sales channels</h6></div>
          <div class="card-body">
            <p class="tx-12 tx-color-03">Omnichannel: sell on profile, marketplace, and social feed.</p>
            <?php $ch = $settings['channels'] ?? []; ?>
            <label class="ckbox d-block mg-b-10"><input type="checkbox" name="channel_profile_shop" value="1" <?= !empty($ch['profile_shop']) ? 'checked' : '' ?>> <span>Profile shop tab</span></label>
            <label class="ckbox d-block mg-b-10"><input type="checkbox" name="channel_marketplace" value="1" <?= !empty($ch['marketplace']) ? 'checked' : '' ?>> <span>Public marketplace listing</span></label>
            <label class="ckbox d-block"><input type="checkbox" name="channel_social_feed" value="1" <?= !empty($ch['social_feed']) ? 'checked' : '' ?>> <span>Social feed product posts</span></label>
          </div>
        </div>
      </div>

      <div class="col-lg-6 mg-b-20">
        <div class="card shadow-base">
          <div class="card-header"><h6 class="card-title tx-14 mg-b-0">Payments &amp; policies</h6></div>
          <div class="card-body">
            <?php $pay = $settings['payment_methods'] ?? []; ?>
            <label class="ckbox d-block mg-b-10"><input type="checkbox" name="pay_stripe" value="1" <?= in_array('stripe', $pay, true) ? 'checked' : '' ?>> <span>Stripe card payments</span></label>
            <label class="ckbox d-block mg-b-15"><input type="checkbox" name="pay_manual" value="1" <?= in_array('manual', $pay, true) ? 'checked' : '' ?>> <span>Manual / offline payment</span></label>
            <div class="form-group"><label>Delivery notes</label><textarea name="delivery_notes" class="form-control" rows="2"><?= org_ecommerce_h((string)($settings['delivery_notes'] ?? '')) ?></textarea></div>
            <div class="form-group"><label>Fulfillment policy</label><textarea name="fulfillment_policy" class="form-control" rows="2"><?= org_ecommerce_h((string)($settings['fulfillment_policy'] ?? '')) ?></textarea></div>
            <div class="form-group"><label>Return policy</label><textarea name="return_policy" class="form-control" rows="2"><?= org_ecommerce_h((string)($settings['return_policy'] ?? '')) ?></textarea></div>
          </div>
        </div>
      </div>

      <div class="col-lg-12 mg-b-20">
        <div class="card shadow-base">
          <div class="card-header"><h6 class="card-title tx-14 mg-b-0">SEO</h6></div>
          <div class="card-body">
            <div class="form-group">
              <label>Store meta description</label>
              <textarea name="seo_meta_description" class="form-control" rows="2" maxlength="320"><?= org_ecommerce_h((string)($settings['seo']['meta_description'] ?? '')) ?></textarea>
              <small class="text-muted">Helps search engines surface your storefront.</small>
            </div>
          </div>
        </div>
      </div>
    </div>

    <button type="submit" class="btn btn-primary">Save settings</button>
    <a href="commerce.php" class="btn btn-light mg-l-5">Cancel</a>
  </form>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var modal = document.getElementById('sellerInfoModal');
  function openModal() {
    if (!modal) return;
    modal.classList.add('is-open');
    modal.setAttribute('aria-hidden', 'false');
  }
  function closeModal() {
    if (!modal) return;
    modal.classList.remove('is-open');
    modal.setAttribute('aria-hidden', 'true');
  }
  Array.prototype.slice.call(document.querySelectorAll('[data-open-seller-modal]')).forEach(function (btn) {
    btn.addEventListener('click', openModal);
  });
  Array.prototype.slice.call(document.querySelectorAll('[data-close-seller-modal]')).forEach(function (btn) {
    btn.addEventListener('click', closeModal);
  });
  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') closeModal();
  });
});
</script>
<?php org_page_shell_close(); ?>
