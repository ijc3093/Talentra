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

// Home / mailing address (self-service per logged-in user; managers see all).
$myHomeAddress = is_array($myHomeAddress ?? null) ? $myHomeAddress : [];
$myMemberName = (string)($myMemberName ?? 'Team member');
$homeAddrOk = (string)($homeAddrOk ?? '');
$homeAddrErr = (string)($homeAddrErr ?? '');
$homeAddrMembers = is_array($homeAddrMembers ?? null) ? $homeAddrMembers : [];
$homeAddrMap = is_array($homeAddrMap ?? null) ? $homeAddrMap : [];
$isManagerView = !empty($isManager);
$myHomeText = ($myHomeAddress && function_exists('org_member_address_format'))
    ? org_member_address_format($myHomeAddress)
    : '';
$homeModalOpenOnLoad = $homeAddrErr !== '';
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
  .seller-profile-panel .seller-profile-home{border-color:var(--msb-palette-action,#4f46e5);border-left-width:4px;}
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

  /* Follow gear-tab appearance selection (dark-auto "On" + custom palette) */
  html.dark-auto .seller-profile-panel .seller-profile-card{
    background:#171d24 !important;
    color:#e8edf5 !important;
  }
  html.dark-auto .seller-profile-panel .seller-profile-card h3,
  html.dark-auto .seller-profile-panel .seller-profile-block{
    color:#e8edf5 !important;
  }
  html.dark-auto .seller-profile-panel .seller-profile-muted,
  html.dark-auto .seller-profile-panel .seller-profile-line strong{
    color:#b1bcce !important;
  }
  html[data-msb-appearance] .seller-profile-panel .seller-profile-card{
    background:var(--msb-palette-bg) !important;
    color:var(--msb-palette-text) !important;
  }
  html[data-msb-appearance] .seller-profile-panel .seller-profile-card h3,
  html[data-msb-appearance] .seller-profile-panel .seller-profile-block{
    color:var(--msb-palette-text) !important;
  }
  html[data-msb-appearance] .seller-profile-panel .seller-profile-muted,
  html[data-msb-appearance] .seller-profile-panel .seller-profile-line strong{
    color:var(--msb-palette-text-muted,var(--msb-palette-text)) !important;
  }
</style>

<div class="seller-profile-panel">
  <?php if ($sellerProfileErr !== ''): ?>
    <div class="alert alert-danger"><?= h($sellerProfileErr) ?></div>
  <?php endif; ?>
  <?php if ($sellerProfileOk !== ''): ?>
    <div class="alert alert-success"><?= h($sellerProfileOk) ?></div>
  <?php endif; ?>

  <!-- Home / mailing address: personal to the logged-in user -->
  <?php if ($homeAddrErr !== ''): ?><div class="alert alert-danger"><?= h($homeAddrErr) ?></div><?php endif; ?>
  <?php if ($homeAddrOk !== ''): ?><div class="alert alert-success"><?= h($homeAddrOk) ?></div><?php endif; ?>

  <div class="seller-profile-card seller-profile-home" style="margin-bottom:16px;">
    <h3>My home address (for mailing letters)</h3>
    <p class="seller-profile-line seller-profile-muted" style="margin-bottom:12px;">
      This is <strong><?= h($myMemberName) ?></strong>'s personal mailing address. Your manager uses it to post letters to your home.
      Only you can edit your own address.
    </p>
    <div id="homeAddrSummary">
      <?php if (trim($myHomeText) !== ''): ?>
        <p class="seller-profile-block mg-b-0"><?= h($myHomeText) ?></p>
      <?php else: ?>
        <p class="seller-profile-muted mg-b-0">No home address yet. Click <strong>Add or edit home address</strong> to add it.</p>
      <?php endif; ?>
    </div>
    <div style="margin-top:12px;">
      <button type="button" class="btn btn-primary btn-sm" id="homeAddrOpenModal">Add or edit home address</button>
    </div>
  </div>

</div>

<div class="seller-profile-modal<?= $homeModalOpenOnLoad ? ' is-open' : '' ?>" id="homeAddrModal" aria-hidden="<?= $homeModalOpenOnLoad ? 'false' : 'true' ?>">
  <div class="seller-profile-modal-backdrop" data-close-home-addr-modal></div>
  <div class="seller-profile-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="homeAddrModalTitle">
    <button type="button" class="seller-profile-modal-close" data-close-home-addr-modal aria-label="Close">&times;</button>
    <h3 id="homeAddrModalTitle">My home address</h3>
    <p>Your manager uses this to post letters to your home. Only you can edit it.</p>
    <form method="post" action="sales_management.php#settings">
      <input type="hidden" name="home_addr_action" value="1">
      <div class="form-group">
        <label for="homeRecipient">Recipient name</label>
        <input id="homeRecipient" name="recipient_name" class="form-control" maxlength="160" value="<?= h((string)($myHomeAddress['recipient_name'] ?? '')) ?>" placeholder="<?= h($myMemberName) ?>">
      </div>
      <div class="form-group">
        <label for="homeLine1">Address line 1</label>
        <input id="homeLine1" name="home_line1" class="form-control" maxlength="200" value="<?= h((string)($myHomeAddress['line1'] ?? '')) ?>" placeholder="123 Main St">
      </div>
      <div class="form-group">
        <label for="homeLine2">Address line 2</label>
        <input id="homeLine2" name="home_line2" class="form-control" maxlength="200" value="<?= h((string)($myHomeAddress['line2'] ?? '')) ?>" placeholder="Apt, unit (optional)">
      </div>
      <div class="row row-sm">
        <div class="col-sm-6 form-group">
          <label for="homeCity">City</label>
          <input id="homeCity" name="home_city" class="form-control" maxlength="120" value="<?= h((string)($myHomeAddress['city'] ?? '')) ?>">
        </div>
        <div class="col-sm-6 form-group">
          <label for="homeState">State / Province</label>
          <input id="homeState" name="home_state" class="form-control" maxlength="120" value="<?= h((string)($myHomeAddress['state'] ?? '')) ?>">
        </div>
      </div>
      <div class="row row-sm">
        <div class="col-sm-6 form-group">
          <label for="homePostal">ZIP / Postal code</label>
          <input id="homePostal" name="home_postal_code" class="form-control" maxlength="40" value="<?= h((string)($myHomeAddress['postal_code'] ?? '')) ?>">
        </div>
        <div class="col-sm-6 form-group">
          <label for="homeCountry">Country</label>
          <input id="homeCountry" name="home_country" class="form-control" maxlength="120" value="<?= h((string)($myHomeAddress['country'] ?? '')) ?>">
        </div>
      </div>
      <div class="seller-profile-modal-actions">
        <button type="submit" class="btn btn-primary btn-sm">Save my home address</button>
        <button type="button" class="btn btn-outline-secondary btn-sm" data-close-home-addr-modal>Cancel</button>
      </div>
    </form>
  </div>
</div>

<script>
(function () {
  var modal = document.getElementById('homeAddrModal');
  var openBtn = document.getElementById('homeAddrOpenModal');
  if (modal && openBtn) {
    var open = function () {
      modal.classList.add('is-open');
      modal.setAttribute('aria-hidden', 'false');
      document.body.style.overflow = 'hidden';
      var first = document.getElementById('homeRecipient');
      if (first) setTimeout(function () { first.focus(); }, 40);
    };
    var close = function () {
      modal.classList.remove('is-open');
      modal.setAttribute('aria-hidden', 'true');
      document.body.style.overflow = '';
    };
    openBtn.addEventListener('click', function (e) { e.preventDefault(); open(); });
    modal.querySelectorAll('[data-close-home-addr-modal]').forEach(function (el) {
      el.addEventListener('click', close);
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && modal.classList.contains('is-open')) close();
    });
    if (modal.classList.contains('is-open')) document.body.style.overflow = 'hidden';
  }
})();
</script>
