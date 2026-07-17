<?php
declare(strict_types=1);

/**
 * Seller profile for sales_management.php#settings.
 * Summary cards + modal form opened by "Add or edit address".
 *
 * Expected vars:
 * - PDO $dbh
 * - int $orgId
 * - array $sellerProfileSettings (shop_json display settings)
 * - string $sellerProfileOk
 * - string $sellerProfileErr
 * - string $sellerProfileFormAction (optional post target)
 * - string $sellerProfileHash (optional, default #settings)
 */

if (!function_exists('h') && function_exists('org_ecommerce_h')) {
    function h(string $s): string
    {
        return org_ecommerce_h($s);
    }
}

$sellerProfileSettings = is_array($sellerProfileSettings ?? null) ? $sellerProfileSettings : [];
$sellerProfileOk = (string)($sellerProfileOk ?? '');
$sellerProfileErr = (string)($sellerProfileErr ?? '');
$sellerProfileFormAction = (string)($sellerProfileFormAction ?? '');
$sellerProfileHash = (string)($sellerProfileHash ?? '#settings');
if ($sellerProfileHash !== '' && $sellerProfileHash[0] !== '#') {
    $sellerProfileHash = '#' . $sellerProfileHash;
}

$fullName = trim((string)($sellerProfileSettings['full_name'] ?? ''));
$storeName = trim((string)($sellerProfileSettings['store_name'] ?? ''));
$tagline = trim((string)($sellerProfileSettings['tagline'] ?? ''));
$email = trim((string)($sellerProfileSettings['contact_email'] ?? ''));
$phone = trim((string)($sellerProfileSettings['contact_phone'] ?? ''));
$addr = is_array($sellerProfileSettings['address'] ?? null) ? $sellerProfileSettings['address'] : [];
$addressText = function_exists('org_ecommerce_format_seller_address')
    ? org_ecommerce_format_seller_address($addr)
    : '';
$addressComplete = function_exists('org_ecommerce_address_is_complete')
    ? org_ecommerce_address_is_complete($addr)
    : ($addressText !== '');
$formAction = $sellerProfileFormAction !== ''
    ? $sellerProfileFormAction . $sellerProfileHash
    : $sellerProfileHash;
$editBtnLabel = ($addressComplete || $storeName !== '') ? 'Add or edit address' : 'Add or edit address';
$openModalOnLoad = $sellerProfileErr !== '';
?>
<style>
  .seller-profile-panel .seller-profile-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px;margin-bottom:16px;}
  .seller-profile-panel .seller-profile-card{border:1px solid rgba(148,163,184,.35);border-radius:8px;background:var(--card-bg,#fff);padding:14px 16px;}
  .seller-profile-panel .seller-profile-card h3{margin:0 0 10px;font-size:14px;font-weight:700;}
  .seller-profile-panel .seller-profile-line{margin:0 0 7px;font-size:13px;line-height:1.45;}
  .seller-profile-panel .seller-profile-line strong{display:inline-block;min-width:88px;color:#64748b;font-weight:600;}
  .seller-profile-panel .seller-profile-muted{color:#64748b;}
  .seller-profile-panel .seller-profile-block{white-space:pre-line;margin:0;font-size:13px;line-height:1.5;}
  .seller-profile-panel .seller-profile-toolbar{display:flex;flex-wrap:wrap;align-items:center;gap:8px;margin-bottom:16px;}
  .seller-profile-modal{position:fixed;inset:0;z-index:12060;display:none;align-items:center;justify-content:center;padding:20px;}
  .seller-profile-modal.is-open{display:flex;}
  .seller-profile-modal-backdrop{position:absolute;inset:0;background:rgba(15,23,42,.55);}
  .seller-profile-modal-dialog{
    position:relative;z-index:1;width:min(640px,100%);max-height:min(92vh,820px);overflow:auto;
    border:1px solid rgba(148,163,184,.35);border-radius:10px;
    background:var(--msb-palette-bg,var(--card-bg,#111827));color:inherit;
    padding:18px 18px 16px;box-shadow:0 18px 48px rgba(0,0,0,.35);
  }
  .seller-profile-modal-dialog h3{margin:0 0 6px;font-size:18px;font-weight:800;}
  .seller-profile-modal-dialog > p{margin:0 0 14px;font-size:13px;opacity:.8;}
  .seller-profile-modal-actions{display:flex;flex-wrap:wrap;gap:8px;margin-top:8px;}
  .seller-profile-modal-close{
    position:absolute;top:12px;right:12px;width:36px;height:36px;border:0;border-radius:50%;
    background:rgba(148,163,184,.2);color:inherit;font-size:22px;line-height:1;cursor:pointer;
  }
  @media (max-width:800px){.seller-profile-panel .seller-profile-grid{grid-template-columns:1fr;}}
</style>

<div class="seller-profile-panel">
  <?php if ($sellerProfileErr !== ''): ?>
    <div class="alert alert-danger"><?= h($sellerProfileErr) ?></div>
  <?php endif; ?>
  <?php if ($sellerProfileOk !== ''): ?>
    <div class="alert alert-success"><?= h($sellerProfileOk) ?></div>
  <?php endif; ?>

  <div class="seller-profile-toolbar">
    <button type="button" class="btn btn-primary btn-sm" id="sellerProfileOpenModal"><?= h($editBtnLabel) ?></button>
    <a href="shop_settings.php#seller-information" class="btn btn-outline-secondary btn-sm">Open shop settings</a>
  </div>

  <div class="seller-profile-grid">
    <div class="seller-profile-card">
      <h3>Contact</h3>
      <p class="seller-profile-line"><strong>Full name</strong> <?= $fullName !== '' ? h($fullName) : '<span class="seller-profile-muted">Not set</span>' ?></p>
      <p class="seller-profile-line"><strong>Store name</strong> <?= $storeName !== '' ? h($storeName) : '<span class="seller-profile-muted">Not set</span>' ?></p>
      <p class="seller-profile-line"><strong>Email</strong> <?= $email !== '' ? h($email) : '<span class="seller-profile-muted">Not set</span>' ?></p>
      <p class="seller-profile-line"><strong>Phone</strong> <?= $phone !== '' ? h($phone) : '<span class="seller-profile-muted">Not set</span>' ?></p>
      <?php if ($tagline !== ''): ?>
        <p class="seller-profile-line"><strong>Tagline</strong> <?= h($tagline) ?></p>
      <?php endif; ?>
    </div>
    <div class="seller-profile-card">
      <h3>Business address</h3>
      <div id="sellerProfileAddressSummary">
        <?php if ($addressText !== ''): ?>
          <p class="seller-profile-block"><?= h($addressText) ?></p>
        <?php else: ?>
          <p class="seller-profile-muted mg-b-0">No address yet. Click <strong>Add or edit address</strong> so buyers can use it for pickup and invoices.</p>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<div class="seller-profile-modal<?= $openModalOnLoad ? ' is-open' : '' ?>" id="sellerProfileModal" aria-hidden="<?= $openModalOnLoad ? 'false' : 'true' ?>">
  <div class="seller-profile-modal-backdrop" data-close-seller-profile-modal></div>
  <div class="seller-profile-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="sellerProfileModalTitle">
    <button type="button" class="seller-profile-modal-close" data-close-seller-profile-modal aria-label="Close">&times;</button>
    <h3 id="sellerProfileModalTitle">Edit seller profile</h3>
    <p>Buyers see these details on orders, invoices, pickup, and seller contact.</p>
    <form method="post" action="<?= h($formAction) ?>">
      <input type="hidden" name="seller_profile_action" value="save">
      <div class="row row-sm">
        <div class="col-md-6 form-group">
          <label for="sellerFullName">Full name</label>
          <input id="sellerFullName" name="full_name" class="form-control" maxlength="120" value="<?= h($fullName) ?>" placeholder="Jane Seller">
        </div>
        <div class="col-md-6 form-group">
          <label for="sellerStoreName">Store / seller name <span class="tx-danger">*</span></label>
          <input id="sellerStoreName" name="store_name" class="form-control" maxlength="120" required value="<?= h($storeName) ?>" placeholder="Jane's Auto Shop">
        </div>
      </div>
      <div class="form-group">
        <label for="sellerTagline">Tagline</label>
        <input id="sellerTagline" name="tagline" class="form-control" maxlength="200" value="<?= h($tagline) ?>" placeholder="Quality cars & parts">
      </div>
      <div class="row row-sm">
        <div class="col-md-6 form-group">
          <label for="sellerEmail">Contact email</label>
          <input id="sellerEmail" name="contact_email" type="email" class="form-control" value="<?= h($email) ?>" placeholder="you@example.com">
        </div>
        <div class="col-md-6 form-group">
          <label for="sellerPhone">Contact phone</label>
          <input id="sellerPhone" name="contact_phone" class="form-control" value="<?= h($phone) ?>" placeholder="+1 555 0100">
        </div>
      </div>
      <hr>
      <div class="form-group">
        <label for="sellerAddr1">Address line 1 <span class="tx-danger">*</span></label>
        <input id="sellerAddr1" name="address_line1" class="form-control" value="<?= h((string)($addr['line1'] ?? '')) ?>" placeholder="123 Main St" required>
      </div>
      <div class="form-group">
        <label for="sellerAddr2">Address line 2</label>
        <input id="sellerAddr2" name="address_line2" class="form-control" value="<?= h((string)($addr['line2'] ?? '')) ?>" placeholder="Suite 200">
      </div>
      <div class="row row-sm">
        <div class="col-sm-6 form-group">
          <label for="sellerCity">City <span class="tx-danger">*</span></label>
          <input id="sellerCity" name="address_city" class="form-control" value="<?= h((string)($addr['city'] ?? '')) ?>" required>
        </div>
        <div class="col-sm-6 form-group">
          <label for="sellerState">State / Province <span class="tx-danger">*</span></label>
          <input id="sellerState" name="address_state" class="form-control" value="<?= h((string)($addr['state'] ?? '')) ?>" required>
        </div>
      </div>
      <div class="row row-sm">
        <div class="col-sm-6 form-group">
          <label for="sellerPostal">Postal code</label>
          <input id="sellerPostal" name="address_postal_code" class="form-control" value="<?= h((string)($addr['postal_code'] ?? '')) ?>">
        </div>
        <div class="col-sm-6 form-group">
          <label for="sellerCountry">Country</label>
          <input id="sellerCountry" name="address_country" class="form-control" value="<?= h((string)($addr['country'] ?? '')) ?>">
        </div>
      </div>
      <div class="seller-profile-modal-actions">
        <button type="submit" class="btn btn-primary btn-sm">Save seller profile</button>
        <button type="button" class="btn btn-outline-secondary btn-sm" data-close-seller-profile-modal>Cancel</button>
      </div>
    </form>
  </div>
</div>

<script>
(function () {
  var modal = document.getElementById('sellerProfileModal');
  var openBtn = document.getElementById('sellerProfileOpenModal');
  if (!modal || !openBtn) return;

  function openModal() {
    modal.classList.add('is-open');
    modal.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
    var first = document.getElementById('sellerFullName') || document.getElementById('sellerAddr1');
    if (first) setTimeout(function () { first.focus(); }, 40);
  }
  function closeModal() {
    modal.classList.remove('is-open');
    modal.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
  }

  openBtn.addEventListener('click', function (e) {
    e.preventDefault();
    openModal();
  });
  modal.querySelectorAll('[data-close-seller-profile-modal]').forEach(function (el) {
    el.addEventListener('click', closeModal);
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && modal.classList.contains('is-open')) closeModal();
  });

  if (modal.classList.contains('is-open')) {
    document.body.style.overflow = 'hidden';
  }

  /** Keep Settings in sync when address is saved from #products (same page, no reload). */
  function applySellerAddressUpdate(detail) {
    detail = detail || {};
    var addr = detail.address || {};
    var text = String(detail.address_text || '').trim();
    var map = {
      sellerAddr1: addr.line1,
      sellerAddr2: addr.line2,
      sellerCity: addr.city,
      sellerState: addr.state,
      sellerPostal: addr.postal_code,
      sellerCountry: addr.country
    };
    Object.keys(map).forEach(function (id) {
      var el = document.getElementById(id);
      if (el && map[id] != null) el.value = String(map[id]);
    });
    var summary = document.getElementById('sellerProfileAddressSummary');
    if (!summary) return;
    summary.innerHTML = '';
    if (text !== '') {
      var p = document.createElement('p');
      p.className = 'seller-profile-block';
      p.textContent = text;
      summary.appendChild(p);
    } else {
      var empty = document.createElement('p');
      empty.className = 'seller-profile-muted mg-b-0';
      empty.innerHTML = 'No address yet. Click <strong>Add or edit address</strong> so buyers can use it for pickup and invoices.';
      summary.appendChild(empty);
    }
  }
  document.addEventListener('msb:seller-address-updated', function (e) {
    applySellerAddressUpdate((e && e.detail) || {});
  });
})();
</script>
