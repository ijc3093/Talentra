<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/session_user.php';
requireUserLogin();

require_once __DIR__ . '/controller.php';
require_once __DIR__ . '/includes/org_cart.php';
require_once __DIR__ . '/includes/org_shop.php';
require_once __DIR__ . '/includes/theme_prefs.php';
require_once __DIR__ . '/includes/staff_publisher_access.php';
require_once __DIR__ . '/includes/publisher_accounts_load.php';

$controller = new Controller();
$dbh = $controller->pdo();
$meId = (int)($_SESSION['user_id'] ?? $_SESSION['id'] ?? $_SESSION['userid'] ?? 0);
$GLOBALS['feedTopDbh'] = $dbh;
$GLOBALS['feedTopMeId'] = $meId;
$canFollowPublishers = publisher_can_follow_as_viewer($dbh, $meId);

require_once __DIR__ . '/includes/shop_filter_context.php';

$items = org_cart_list_items($dbh, $meId);
$subtotalCents = org_cart_subtotal_cents($items);
$subtotalLabel = org_shop_format_price($subtotalCents, 'USD');
$checkoutCancel = (string)($_GET['checkout'] ?? '') === 'cancel';
$defaultShipText = buyer_shipping_default_text($dbh, $meId);
$defaultShipPhone = buyer_shipping_default_phone($dbh, $meId);
$savedAddresses = buyer_shipping_list($dbh, $meId);

if (!function_exists('h')) {
    function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Cart</title>
  <?php theme_prefs_print_head_bootstrap($dbh, $meId); ?>
  <link href="./lib/font-awesome/css/font-awesome.css" rel="stylesheet">
  <link href="./lib/Ionicons/css/ionicons.css" rel="stylesheet">
  <link rel="stylesheet" href="./css/shamcey.css">
  <link rel="stylesheet" href="assets/ui_best.css">
  <link rel="stylesheet" href="assets/layout-fixed.css">
  <link rel="stylesheet" href="./css/shop-page.css?v=10">
  <style><?php include __DIR__ . '/includes/feed_rails.css.php'; ?></style>
  <style><?php include __DIR__ . '/includes/feed_header_chrome.css.php'; ?></style>
  <script defer src="assets/layout-fixed.js"></script>
  <style>
    .cart-back{display:inline-flex;align-items:center;gap:6px;color:var(--shop-link,var(--msb-palette-link,#2563eb));text-decoration:none;font-size:14px;font-weight:600;}
    .cart-back:hover{text-decoration:underline;}
    .cart-page-main{max-width:720px;width:100%;}
    .cart-title{font-size:22px;font-weight:800;margin:0;color:var(--shop-text,var(--msb-palette-text,inherit));}
    .cart-sub{color:var(--shop-text-muted,var(--msb-palette-text-muted,#6b7280));font-size:14px;margin:4px 0 0;}
    .cart-page-intro .cart-sub{margin-bottom:0;}
    .cart-page-title-row{display:flex;align-items:center;justify-content:space-between;gap:16px;max-width:720px;margin-top:20px;}
    .cart-page-subtotal{font-size:15px;font-weight:700;white-space:nowrap;color:var(--shop-text,var(--msb-palette-text,inherit));flex-shrink:0;}
    .cart-select-hint{margin:8px 0 0;font-size:13px;color:var(--shop-text-muted,var(--msb-palette-text-muted,#6b7280));}
    .cart-page-actions{display:flex;justify-content:space-between;align-items:center;width:100%;gap:16px;}
    .cart-page-footer{flex-shrink:0;padding:8px 0 0;margin-bottom:72px;}
    .cart-checkout-btn{
      border:1px solid var(--shop-btn-outline-border,var(--shop-border,var(--msb-palette-border,rgba(177,188,206,.45))));
      background:var(--shop-btn-outline-bg,transparent);
      color:var(--shop-btn-outline-text,var(--shop-text,var(--msb-palette-text,inherit)));
      font-size:16px;
      font-weight:600;
      padding:8px 18px;
      border-radius:10px;
      cursor:pointer;
      text-align:center;
    }
    .cart-checkout-btn:disabled{opacity:.55;cursor:not-allowed;}
    .cart-list{display:grid;gap:12px;}
    .cart-row{
      display:grid;
      grid-template-columns:88px 1fr auto;
      gap:14px;
      align-items:start;
      background:var(--shop-card-bg,var(--msb-palette-bg,#fff));
      border:1px solid var(--shop-border,var(--msb-palette-border,rgba(15,23,42,.08)));
      border-radius:14px;
      padding:12px;
      color:var(--shop-text,var(--msb-palette-text,inherit));
      transition:opacity .15s ease,border-color .15s ease;
    }
    .cart-row:not(.is-selected){opacity:.72;}
    .cart-row-aside{display:flex;flex-direction:column;align-items:flex-end;gap:10px;min-width:88px;}
    .cart-select-wrap{position:relative;display:flex;align-items:center;justify-content:center;cursor:pointer;width:24px;height:24px;flex-shrink:0;}
    .cart-select{position:absolute;opacity:0;width:0;height:0;pointer-events:none;margin:0;}
    .cart-select-mark{
      width:24px;
      height:24px;
      border:1px solid var(--shop-border-strong,var(--shop-border,var(--msb-palette-border,rgba(177,188,206,.65))));
      border-radius:6px;
      background:transparent;
      display:inline-flex;
      align-items:center;
      justify-content:center;
      font-size:13px;
      box-sizing:border-box;
      pointer-events:none;
    }
    .cart-select-mark i{
      display:none;
      line-height:1;
      color:var(--shop-btn-filled-text,var(--msb-palette-btn-text,#fff));
    }
    .cart-select:checked + .cart-select-mark{
      background:var(--shop-btn-filled-bg,var(--msb-palette-btn-bg,#3d4a32));
      border-color:var(--shop-btn-filled-bg,var(--msb-palette-btn-bg,#3d4a32));
    }
    .cart-select:checked + .cart-select-mark i{display:inline-block;}
    .cart-select:focus-visible + .cart-select-mark{outline:2px solid var(--shop-link,var(--msb-palette-link,#2563eb));outline-offset:2px;}
    .cart-thumb{width:88px;height:88px;border-radius:10px;overflow:hidden;background:var(--shop-card-raised,rgba(15,23,42,.04));display:flex;align-items:center;justify-content:center;color:var(--shop-text-muted,var(--msb-palette-text-muted,#6b7280));}
    .cart-thumb img{width:100%;height:100%;object-fit:cover;}
    .cart-name{font-size:16px;font-weight:700;margin:0 0 4px;color:var(--shop-text,var(--msb-palette-text,inherit));}
    .cart-name a{color:inherit;text-decoration:none;}
    .cart-seller{font-size:12px;color:var(--shop-text-muted,var(--msb-palette-text-muted,#6b7280));margin:0;text-align:right;max-width:120px;line-height:1.3;}
    .cart-price{font-size:15px;font-weight:700;color:var(--shop-text,var(--msb-palette-text,inherit));}
    .cart-qty{display:flex;align-items:center;gap:8px;margin-top:8px;flex-wrap:wrap;color:var(--shop-text,var(--msb-palette-text,inherit));}
    .cart-qty label{color:var(--shop-text-muted,var(--msb-palette-text-muted,#6b7280));}
    .cart-qty input{width:72px;background:var(--shop-input-bg,var(--msb-palette-input-bg,transparent));border:1px solid var(--shop-border-strong,var(--shop-border,var(--msb-palette-border,rgba(15,23,42,.14))));border-radius:8px;color:var(--shop-text,var(--msb-palette-text,inherit));padding:4px 8px;}
    .cart-details-link{
      font-size:13px;
      font-weight:600;
      color:var(--shop-link,var(--msb-palette-link,#2563eb));
      text-decoration:none;
      white-space:nowrap;
    }
    .cart-details-link:hover{text-decoration:underline;}
    .cart-remove{
      border:1px solid var(--cart-remove-border,rgba(185,28,28,.35));
      background:transparent;
      color:var(--cart-remove-text,#b91c1c);
      font-size:13px;
      font-weight:600;
      cursor:pointer;
      padding:4px 10px;
      border-radius:8px;
    }
    .cart-line-total{font-size:15px;font-weight:700;text-align:right;white-space:nowrap;color:var(--shop-text,var(--msb-palette-text,inherit));}
    .cart-empty{text-align:center;padding:48px 16px;color:var(--shop-text-muted,var(--msb-palette-text-muted,#6b7280));}
    .cart-alert{padding:10px 12px;border-radius:10px;margin-bottom:12px;font-size:14px;}
    .cart-alert-warn{background:#fff7ed;color:#9a3412;border:1px solid #fed7aa;}
    .shop-page-head-mobile .shop-page-title{font-size:22px;font-weight:800;padding:8px 0 0;margin:0;color:var(--shop-text,var(--msb-palette-text,#111827));}
    .shop-page-head-mobile .shop-page-sub{padding:4px 0 0;color:var(--shop-text-muted,var(--msb-palette-text-muted,#6b7280));font-size:14px;margin:0;}
    @media (max-width:640px){
      .cart-row{grid-template-columns:72px 1fr;}
      .cart-row-aside{
        grid-column:2;
        flex-direction:column;
        align-items:flex-end;
        min-width:0;
        margin-top:4px;
      }
      .cart-line-total{text-align:left;}
      .cart-page-title-row{flex-wrap:wrap;}
    }
  </style>
</head>
<body class="shop-page feed-page feed-insta-ui cart-page">

<?php
  $GLOBALS['msb_skip_header_leftbar'] = true;
  $skipHeaderThemeBootstrap = true;
  include __DIR__ . '/includes/header.php';
?>
<?php
  $feedLeftRailActive = 'cart.php';
  $feedLeftRailCanFollow = $canFollowPublishers;
  $feedLeftRailShopOnly = true;
  $feedLeftRailShopFilters = true;
  $feedLeftRailPageHeadTitle = 'Shop';
  $feedLeftRailPageHeadSub = 'Browse products from publishers and buy securely.';
  include __DIR__ . '/includes/feed_left_rail.php';
?>

<div class="sh-mainpanel">
  <?php include __DIR__ . '/includes/leftbar.php'; ?>
  <?php include __DIR__ . '/includes/stories_right_door.php'; ?>
  <div class="sh-pagebody">
    <div class="ig-feed-header">
      <?php include __DIR__ . '/includes/feed_top_user_lead.php'; ?>
      <?php include __DIR__ . '/includes/shop_header_search.php'; ?>
      <?php $feedTopCartActive = true; $feedTopShopOnly = true; include __DIR__ . '/includes/feed_top_actions.php'; ?>
    </div>

    <div class="shop-page-shell">
      <div class="shop-page-head-mobile">
        <h1 class="shop-page-title">Shop</h1>
        <p class="shop-page-sub">Browse products from publishers and buy securely.</p>
      </div>

      <div class="cart-page-intro">
        <div class="cart-page-title-row">
          <h1 class="cart-title">Your cart</h1>
          <?php if ($items): ?>
            <div class="cart-page-subtotal" id="cartSubtotal"><strong>Subtotal:</strong> <?= h($subtotalLabel) ?></div>
          <?php endif; ?>
        </div>
        <p class="cart-sub"><?= count($items) ?> item<?= count($items) === 1 ? '' : 's' ?></p>
        <?php if ($items): ?>
          <p class="cart-select-hint">Check items to buy now. Unchecked items stay in your cart for later.</p>
        <?php endif; ?>

        <?php if ($checkoutCancel): ?>
          <div class="cart-alert cart-alert-warn">Checkout was cancelled. Your cart is unchanged.</div>
        <?php endif; ?>
      </div>

      <div class="cart-page-scroll">
        <?php if (!$items): ?>
          <div class="cart-empty">
            <i class="icon ion-ios-cart" style="font-size:42px;display:block;margin-bottom:10px;"></i>
            Your cart is empty.
          </div>
        <?php else: ?>
          <div class="cart-page-main">
            <div class="cart-list" id="cartList">
            <?php foreach ($items as $item): ?>
              <?php
                $cover = org_shop_cover_url((string)($item['cover_image_path'] ?? ''));
                $unit = org_shop_format_price((int)($item['price_cents'] ?? 0), (string)($item['currency'] ?? 'USD'));
                $line = org_shop_format_price((int)($item['price_cents'] ?? 0) * (int)($item['quantity'] ?? 1), (string)($item['currency'] ?? 'USD'));
                $publisherId = (int)($item['publisher_user_id'] ?? 0);
                $seller = trim((string)($item['publisher_name'] ?? '')) ?: trim((string)($item['publisher_username'] ?? '')) ?: trim((string)($item['seller_name'] ?? 'Shop'));
                $detailLabel = trim((string)($item['category'] ?? ''));
                if ($detailLabel === '') {
                    $detailLabel = trim((string)($item['title'] ?? 'Item'));
                }
              ?>
              <article class="cart-row is-selected" data-product-id="<?= (int)$item['product_id'] ?>" data-unit-cents="<?= (int)($item['price_cents'] ?? 0) ?>" data-currency="<?= h((string)($item['currency'] ?? 'USD')) ?>">
                <a href="product_detail.php?id=<?= (int)$item['product_id'] ?>" class="cart-thumb">
                  <?php if ($cover !== ''): ?><img src="<?= h($cover) ?>" alt=""><?php else: ?><i class="icon ion-bag"></i><?php endif; ?>
                </a>
                <div>
                  <h2 class="cart-name"><a href="product_detail.php?id=<?= (int)$item['product_id'] ?>"><?= h((string)$item['title']) ?></a></h2>
                  <div class="cart-price"><?= h($unit) ?> each</div>
                  <div class="cart-qty">
                    <label>Qty</label>
                    <input type="number" class="cart-qty-input" min="1" max="99" value="<?= (int)$item['quantity'] ?>" data-product-id="<?= (int)$item['product_id'] ?>">
                    <button type="button" class="cart-remove" data-remove="<?= (int)$item['product_id'] ?>">Remove</button>
                    <a href="product_detail.php?id=<?= (int)$item['product_id'] ?>" class="cart-details-link">View details · <?= h($detailLabel) ?></a>
                  </div>
                </div>
                <div class="cart-row-aside">
                  <div class="cart-line-total"><?= h($line) ?></div>
                  <div class="cart-select-wrap" role="button" tabindex="0" title="Buy this item now" aria-pressed="true">
                    <input type="checkbox" class="cart-select" checked aria-hidden="true" tabindex="-1">
                    <span class="cart-select-mark" aria-hidden="true"><i class="fa fa-check"></i></span>
                  </div>
                  <div class="cart-seller"><?= h($seller) ?></div>
                </div>
              </article>
            <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>
      </div>

      <div class="cart-page-footer">
        <?php if ($items): ?>
        <div class="cart-page-main" style="padding:12px 0 4px;display:grid;gap:10px;max-width:520px;">
          <label class="tx-12" style="display:grid;gap:4px;">
            <span>Promo code (seller coupon)</span>
            <input type="text" id="cartPromoCode" class="form-control" placeholder="SUMMER10" maxlength="40" style="max-width:240px;">
          </label>
          <label class="tx-12" style="display:grid;gap:4px;">
            <span>Delivery</span>
            <select id="cartDeliveryOption" class="form-control" style="max-width:240px;">
              <option value="home_delivery">Home delivery</option>
              <option value="pickup">Pickup</option>
            </select>
          </label>
          <label class="tx-12" style="display:grid;gap:4px;">
            <span>Phone</span>
            <input type="text" id="cartBuyerPhone" class="form-control" value="<?= h($defaultShipPhone) ?>" placeholder="Contact phone" style="max-width:240px;">
          </label>
          <label class="tx-12" style="display:grid;gap:4px;">
            <span>Delivery address <?php if ($savedAddresses): ?><a href="Your_Shopping_preferences.php#addresses" style="font-weight:600;">(manage saved)</a><?php endif; ?></span>
            <textarea id="cartDeliveryAddress" class="form-control" rows="3" placeholder="Street, city, postal code"><?= h($defaultShipText) ?></textarea>
          </label>
        </div>
        <?php endif; ?>
        <div class="cart-page-main cart-page-actions">
          <a href="shop.php" class="cart-back"><i class="icon ion-ios-arrow-left"></i> Continue shopping</a>
          <?php if ($items): ?>
            <button type="button" class="cart-checkout-btn" id="cartCheckoutBtn">Checkout</button>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
(function(){
  const subtotalEl = document.getElementById('cartSubtotal');
  const checkoutBtn = document.getElementById('cartCheckoutBtn');

  function formatMoney(cents, currency){
    const amount = (Math.max(0, cents) / 100).toFixed(2);
    currency = String(currency || 'USD').toUpperCase();
    if (currency === 'USD') return '$' + amount;
    return amount + ' ' + currency;
  }

  function getSelectedRows(){
    return Array.from(document.querySelectorAll('.cart-row')).filter(function(row){
      const checkbox = row.querySelector('.cart-select');
      return checkbox && checkbox.checked;
    });
  }

  function getSelectedProductIds(){
    return getSelectedRows().map(function(row){
      return parseInt(row.getAttribute('data-product-id') || '0', 10);
    }).filter(function(id){ return id > 0; });
  }

  function updateSelectionUi(){
    document.querySelectorAll('.cart-row').forEach(function(row){
      const checkbox = row.querySelector('.cart-select');
      const wrap = row.querySelector('.cart-select-wrap');
      row.classList.toggle('is-selected', !!(checkbox && checkbox.checked));
      if (wrap && checkbox) {
        wrap.setAttribute('aria-pressed', checkbox.checked ? 'true' : 'false');
      }
    });

    let subtotalCents = 0;
    let currency = 'USD';
    getSelectedRows().forEach(function(row){
      const unitCents = parseInt(row.getAttribute('data-unit-cents') || '0', 10);
      const qtyInput = row.querySelector('.cart-qty-input');
      const qty = qtyInput ? Math.max(1, parseInt(qtyInput.value || '1', 10) || 1) : 1;
      currency = row.getAttribute('data-currency') || currency;
      subtotalCents += unitCents * qty;
    });

    if (subtotalEl) {
      subtotalEl.innerHTML = '<strong>Subtotal:</strong> ' + formatMoney(subtotalCents, currency);
    }
    if (checkoutBtn) {
      checkoutBtn.disabled = getSelectedProductIds().length === 0;
    }
  }

  document.querySelectorAll('.cart-select-wrap').forEach(function(wrap){
    const checkbox = wrap.querySelector('.cart-select');
    if (!checkbox) return;

    function toggleMark(){
      checkbox.checked = !checkbox.checked;
      wrap.setAttribute('aria-pressed', checkbox.checked ? 'true' : 'false');
      checkbox.dispatchEvent(new Event('change', { bubbles: true }));
    }

    wrap.addEventListener('click', function(e){
      e.preventDefault();
      e.stopPropagation();
      toggleMark();
    });
    wrap.addEventListener('keydown', function(e){
      if (e.key === ' ' || e.key === 'Enter') {
        e.preventDefault();
        toggleMark();
      }
    });
  });

  document.querySelectorAll('.cart-select').forEach(function(checkbox){
    checkbox.addEventListener('change', updateSelectionUi);
  });

  async function cartRequest(action, productId, quantity){
    const body = new URLSearchParams();
    body.set('action', action);
    body.set('product_id', String(productId));
    if (quantity !== undefined) body.set('quantity', String(quantity));
    const res = await fetch('ajax/cart_action.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: body.toString(), credentials:'same-origin' });
    return res.json();
  }

  document.querySelectorAll('.cart-remove').forEach(btn => {
    btn.addEventListener('click', async function(){
      const pid = parseInt(btn.getAttribute('data-remove') || '0', 10);
      if (!pid) return;
      const data = await cartRequest('remove', pid);
      if (data.ok) window.location.reload();
      else window.alert(data.message || 'Could not remove item.');
    });
  });

  document.querySelectorAll('.cart-qty-input').forEach(input => {
    input.addEventListener('change', async function(){
      const pid = parseInt(input.getAttribute('data-product-id') || '0', 10);
      const qty = Math.max(1, Math.min(99, parseInt(input.value || '1', 10) || 1));
      input.value = String(qty);
      const data = await cartRequest('update', pid, qty);
      if (data.ok) window.location.reload();
      else window.alert(data.message || 'Could not update quantity.');
    });
  });

  if (checkoutBtn) {
    checkoutBtn.addEventListener('click', async function(){
      const selectedIds = getSelectedProductIds();
      if (!selectedIds.length) {
        window.alert('Select at least one item to checkout.');
        return;
      }
      checkoutBtn.disabled = true;
      try {
        const body = new URLSearchParams();
        body.set('product_ids', selectedIds.join(','));
        const promo = document.getElementById('cartPromoCode');
        const phone = document.getElementById('cartBuyerPhone');
        const addr = document.getElementById('cartDeliveryAddress');
        const dopt = document.getElementById('cartDeliveryOption');
        if (promo && promo.value.trim()) body.set('promo_code', promo.value.trim());
        if (phone && phone.value.trim()) body.set('buyer_phone', phone.value.trim());
        if (addr && addr.value.trim()) body.set('delivery_address', addr.value.trim());
        if (dopt && dopt.value) body.set('delivery_option', dopt.value);
        const res = await fetch('ajax/cart_checkout.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: body.toString(), credentials:'same-origin' });
        const data = await res.json();
        if (data.ok && data.checkout_url) { window.location.href = data.checkout_url; return; }
        if (data.ok) { window.location.href = 'my_orders.php'; return; }
        window.alert(data.message || 'Checkout failed.');
      } catch (e) {
        window.alert('Checkout failed.');
      } finally {
        updateSelectionUi();
      }
    });
  }

  updateSelectionUi();
})();
</script>
<script src="./lib/jquery/jquery.js"></script>
<script src="./js/shamcey.js"></script>
<script>
(function(){
  document.querySelectorAll('.shop-nav-filter').forEach(filter => {
    const toggle = filter.querySelector('.shop-nav-filter-toggle');
    const panel = filter.querySelector('.shop-nav-filter-panel');
    if (!toggle || !panel) return;

    toggle.addEventListener('click', function(){
      const isOpen = filter.classList.toggle('is-open');
      toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
      panel.hidden = !isOpen;
    });
  });
})();
</script>
</body>
</html>
