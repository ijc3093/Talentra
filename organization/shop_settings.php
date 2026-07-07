<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/session_org.php';
require_once __DIR__ . '/includes/org_context.php';
require_once __DIR__ . '/includes/org_ecommerce.php';
require_once __DIR__ . '/includes/org_manager_guard.php';

org_require_manager();

$orgId = (int)orgActiveOrgId();
org_ecommerce_ensure_schema($dbh);

$err = '';
$ok = '';
$settings = org_ecommerce_get_shop_settings($dbh, $orgId);
$businessModel = org_ecommerce_get_business_model($dbh, $orgId);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
        'store_name' => trim((string)($_POST['store_name'] ?? '')),
        'tagline' => trim((string)($_POST['tagline'] ?? '')),
        'contact_email' => trim((string)($_POST['contact_email'] ?? '')),
        'contact_phone' => trim((string)($_POST['contact_phone'] ?? '')),
        'delivery_notes' => trim((string)($_POST['delivery_notes'] ?? '')),
        'fulfillment_policy' => trim((string)($_POST['fulfillment_policy'] ?? '')),
        'return_policy' => trim((string)($_POST['return_policy'] ?? '')),
        'payment_methods' => $paymentMethods,
        'channels' => $channels,
        'seo' => [
            'meta_description' => trim((string)($_POST['seo_meta_description'] ?? '')),
        ],
    ];

    if (org_ecommerce_save_shop_settings($dbh, $orgId, $payload)) {
        $ok = $ok !== '' ? $ok . ' Store settings saved.' : 'Store settings saved.';
        $settings = org_ecommerce_get_shop_settings($dbh, $orgId);
    } else {
        $err = 'Could not save store settings.';
    }
}

$pageTitle = 'Store settings';
require_once __DIR__ . '/includes/org_page_shell.php';
org_page_shell_open($pageTitle);
?>
<div class="sh-pagebody">
  <div class="mg-b-15">
    <a href="commerce.php" class="tx-12">&larr; Commerce hub</a>
    <h4 class="mg-b-0">Store settings</h4>
    <p class="tx-color-03">Align your storefront with your business model and sales channels.</p>
  </div>

  <?php if ($err): ?><div class="alert alert-danger"><?= org_ecommerce_h($err) ?></div><?php endif; ?>
  <?php if ($ok): ?><div class="alert alert-success"><?= org_ecommerce_h($ok) ?></div><?php endif; ?>

  <form method="post">
    <div class="row row-sm">
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
          <div class="card-header"><h6 class="card-title tx-14 mg-b-0">Storefront identity</h6></div>
          <div class="card-body">
            <div class="form-group"><label>Store name</label><input name="store_name" class="form-control" value="<?= org_ecommerce_h((string)($settings['store_name'] ?? '')) ?>"></div>
            <div class="form-group"><label>Tagline</label><input name="tagline" class="form-control" value="<?= org_ecommerce_h((string)($settings['tagline'] ?? '')) ?>"></div>
            <div class="form-group"><label>Contact email</label><input name="contact_email" type="email" class="form-control" value="<?= org_ecommerce_h((string)($settings['contact_email'] ?? '')) ?>"></div>
            <div class="form-group"><label>Contact phone</label><input name="contact_phone" class="form-control" value="<?= org_ecommerce_h((string)($settings['contact_phone'] ?? '')) ?>"></div>
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
<?php org_page_shell_close(); ?>
