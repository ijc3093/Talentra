<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/session_org.php';
require_once __DIR__ . '/includes/org_context.php';
require_once __DIR__ . '/includes/org_ecommerce.php';
require_once __DIR__ . '/includes/org_manager_guard.php';
require_once __DIR__ . '/../public_user/includes/org_commerce_brands.php';

org_require_manager();

org_require_commerce_seller();

$orgId = (int)orgActiveOrgId();
org_ecommerce_ensure_schema($dbh);
org_commerce_brands_ensure_schema($dbh);

$err = '';
$brands = org_commerce_brands_list_active($dbh);
$currentBrand = org_commerce_brands_get_for_org($dbh, $orgId);
$switchMode = ((string)($_GET['switch'] ?? '') === '1');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $brandId = (int)($_POST['brand_id'] ?? 0);
    if (org_commerce_brands_assign_org($dbh, $orgId, $brandId)) {
        header('Location: commerce.php');
        exit;
    }
    $err = 'Could not join that brand system. Try again.';
}

$pageTitle = 'Choose your commerce brand';
require_once __DIR__ . '/includes/org_page_shell.php';
org_page_shell_open($pageTitle, '<link rel="stylesheet" href="css/commerce-hub.css?v=14">');
?>
<?php org_page_body_open('commerce-page'); ?>
  <section class="commerce-hero commerce-hero-compact">
    <div class="commerce-hero-inner">
      <div>
        <?php if ($currentBrand && !$switchMode): ?>
          <p class="commerce-hero-kicker"><a href="commerce.php">&larr; Commerce hub</a></p>
        <?php endif; ?>
        <h1>Choose your commerce brand</h1>
        <p>Pick a brand system — McDonald's, Wendy's, and more. Your storefront, menu categories, and fulfillment flow will match how that brand sells.</p>
        <?php if ($currentBrand): ?>
          <div class="commerce-hero-badges">
            <span class="commerce-pill">Current: <?= org_ecommerce_h((string)$currentBrand['name']) ?></span>
          </div>
        <?php endif; ?>
      </div>
      <?php if ($currentBrand): ?>
        <div class="commerce-quick">
          <a href="commerce.php" class="ch-btn-primary">Back to commerce</a>
        </div>
      <?php endif; ?>
    </div>
  </section>

  <?php if ($err !== ''): ?><div class="alert alert-danger"><?= org_ecommerce_h($err) ?></div><?php endif; ?>

  <?php if (!$brands): ?>
    <div class="commerce-panel">
      <div class="commerce-empty">
        <i class="icon ion-ios-cart-outline"></i>
        <div>No brand systems available yet.</div>
      </div>
    </div>
  <?php else: ?>
    <form method="post" class="commerce-brand-grid">
      <?php foreach ($brands as $brand): ?>
        <?php
          $bid = (int)($brand['id'] ?? 0);
          $system = org_commerce_brands_parse_system($brand);
          $categories = is_array($system['menu_categories'] ?? null) ? $system['menu_categories'] : [];
          $accent = trim((string)($brand['accent_color'] ?? '#0d9488'));
          $icon = trim((string)($brand['icon_letter'] ?? mb_substr((string)$brand['name'], 0, 1)));
          $isCurrent = $currentBrand && (int)($currentBrand['id'] ?? 0) === $bid;
        ?>
        <label class="commerce-brand-card<?= $isCurrent ? ' is-current' : '' ?>" style="--brand-accent: <?= org_ecommerce_h($accent) ?>">
          <input type="radio" name="brand_id" value="<?= $bid ?>"<?= $isCurrent ? ' checked' : '' ?> required>
          <span class="commerce-brand-card-icon" aria-hidden="true"><?= org_ecommerce_h($icon) ?></span>
          <span class="commerce-brand-card-body">
            <strong><?= org_ecommerce_h((string)$brand['name']) ?></strong>
            <span><?= org_ecommerce_h((string)($brand['tagline'] ?? '')) ?></span>
            <?php if ($categories): ?>
              <span class="commerce-brand-card-tags">
                <?php foreach (array_slice($categories, 0, 4) as $cat): ?>
                  <em><?= org_ecommerce_h((string)$cat) ?></em>
                <?php endforeach; ?>
              </span>
            <?php endif; ?>
          </span>
          <?php if ($isCurrent): ?>
            <span class="commerce-brand-card-badge">Active</span>
          <?php endif; ?>
        </label>
      <?php endforeach; ?>
      <div class="commerce-brand-grid-foot">
        <button type="submit" class="ch-btn-primary commerce-brand-submit">
          <?= $currentBrand ? 'Switch brand system' : 'Continue to commerce hub' ?>
        </button>
      </div>
    </form>
  <?php endif; ?>
</div>
<?php org_page_shell_close(); ?>
