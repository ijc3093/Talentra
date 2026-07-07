<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/session_user.php';
requireUserLogin();

require_once __DIR__ . '/controller.php';
require_once __DIR__ . '/includes/org_cart.php';
require_once __DIR__ . '/includes/org_shop.php';
require_once __DIR__ . '/includes/stripe_shop.php';
require_once __DIR__ . '/includes/theme_prefs.php';
require_once __DIR__ . '/includes/staff_publisher_access.php';
require_once __DIR__ . '/includes/publisher_accounts_load.php';

$controller = new Controller();
$dbh = $controller->pdo();
$meId = (int)($_SESSION['user_id'] ?? $_SESSION['id'] ?? $_SESSION['userid'] ?? 0);
$GLOBALS['feedTopDbh'] = $dbh;
$GLOBALS['feedTopMeId'] = $meId;
$canFollowPublishers = publisher_can_follow_as_viewer($dbh, $meId);

$items = org_cart_list_items($dbh, $meId);
$subtotalCents = org_cart_subtotal_cents($items);
$subtotalLabel = org_shop_format_price($subtotalCents, 'USD');
$shopStripeEnabled = stripe_shop_is_configured();
$checkoutCancel = (string)($_GET['checkout'] ?? '') === 'cancel';

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
  <style><?php include __DIR__ . '/includes/feed_rails.css.php'; ?></style>
  <style><?php include __DIR__ . '/includes/feed_header_chrome.css.php'; ?></style>
  <script defer src="assets/layout-fixed.js"></script>
  <style>
    .cart-back{display:inline-flex;align-items:center;gap:6px;color:#2563eb;text-decoration:none;font-size:14px;font-weight:600;margin-bottom:12px;}
    .cart-title{font-size:22px;font-weight:800;margin:0 0 4px;}
    .cart-sub{color:#6b7280;font-size:14px;margin:0 0 16px;}
    .cart-list{display:grid;gap:12px;max-width:720px;}
    .cart-row{display:grid;grid-template-columns:88px 1fr auto;gap:14px;align-items:center;background:var(--msb-palette-panel,#fff);border:1px solid var(--msb-palette-border,rgba(15,23,42,.08));border-radius:14px;padding:12px;}
    .cart-thumb{width:88px;height:88px;border-radius:10px;overflow:hidden;background:rgba(15,23,42,.04);display:flex;align-items:center;justify-content:center;}
    .cart-thumb img{width:100%;height:100%;object-fit:cover;}
    .cart-name{font-size:16px;font-weight:700;margin:0 0 4px;}
    .cart-name a{color:inherit;text-decoration:none;}
    .cart-seller{font-size:13px;color:#6b7280;margin-bottom:6px;}
    .cart-price{font-size:15px;font-weight:700;}
    .cart-qty{display:flex;align-items:center;gap:8px;margin-top:8px;}
    .cart-qty input{width:72px;}
    .cart-remove{border:0;background:transparent;color:#b91c1c;font-size:13px;cursor:pointer;padding:0;}
    .cart-line-total{font-size:15px;font-weight:700;text-align:right;white-space:nowrap;}
    .cart-summary{max-width:720px;margin-top:20px;padding:16px;border:1px solid var(--msb-palette-border,rgba(15,23,42,.08));border-radius:14px;background:var(--msb-palette-panel,#fff);display:grid;gap:12px;}
    .cart-summary label{display:block;font-size:13px;font-weight:600;margin-bottom:4px;}
    .cart-summary input,.cart-summary textarea{width:100%;border:1px solid rgba(15,23,42,.14);border-radius:10px;padding:10px 12px;font-size:14px;box-sizing:border-box;}
    .cart-checkout{border:0;border-radius:12px;background:#2563eb;color:#fff;font-weight:700;font-size:16px;padding:14px;cursor:pointer;}
    .cart-checkout:disabled{opacity:.55;cursor:not-allowed;}
    .cart-empty{text-align:center;padding:48px 16px;color:#6b7280;}
    .cart-alert{padding:10px 12px;border-radius:10px;margin-bottom:12px;font-size:14px;}
    .cart-alert-warn{background:#fff7ed;color:#9a3412;}
    @media (max-width:640px){.cart-row{grid-template-columns:72px 1fr;}.cart-line-total{grid-column:2;text-align:left;}}
    .ig-top-cart{position:relative;}
    .ig-top-cart-badge{position:absolute;top:-2px;right:-2px;min-width:18px;height:18px;padding:0 5px;border-radius:999px;background:#ef4444;color:#fff;font-size:11px;font-weight:800;line-height:18px;text-align:center;}
    .ig-top-shop,.ig-top-cart{width:44px;height:44px;border-radius:50%;background:var(--feed-control-soft,#eef2f7);font-size:18px;}
  </style>
</head>
<body class="shop-page feed-page feed-insta-ui cart-page">

<?php
  $GLOBALS['msb_skip_header_leftbar'] = true;
  $skipHeaderThemeBootstrap = true;
  include __DIR__ . '/includes/header.php';
?>
<?php $feedLeftRailActive = 'shop.php'; $feedLeftRailCanFollow = $canFollowPublishers; include __DIR__ . '/includes/feed_left_rail.php'; ?>

<div class="sh-mainpanel">
  <?php include __DIR__ . '/includes/leftbar.php'; ?>
  <?php include __DIR__ . '/includes/stories_right_door.php'; ?>
  <div class="sh-pagebody">
    <div class="ig-feed-header">
      <?php include __DIR__ . '/includes/feed_top_user_lead.php'; ?>
      <div class="ig-stories-wrap" aria-hidden="true"></div>
      <?php $feedTopCartActive = true; include __DIR__ . '/includes/feed_top_actions.php'; ?>
    </div>

    <div class="shop-page-shell">
      <a href="shop.php" class="cart-back"><i class="icon ion-ios-arrow-left"></i> Continue shopping</a>
      <h1 class="cart-title">Your cart</h1>
      <p class="cart-sub"><?= count($items) ?> item<?= count($items) === 1 ? '' : 's' ?></p>

      <?php if ($checkoutCancel): ?>
        <div class="cart-alert cart-alert-warn">Checkout was cancelled. Your cart is unchanged.</div>
      <?php endif; ?>

      <?php if (!$items): ?>
        <div class="cart-empty">
          <i class="icon ion-ios-cart" style="font-size:42px;display:block;margin-bottom:10px;"></i>
          Your cart is empty.
        </div>
      <?php else: ?>
        <div class="cart-list" id="cartList">
          <?php foreach ($items as $item): ?>
            <?php
              $cover = org_shop_cover_url((string)($item['cover_image_path'] ?? ''));
              $unit = org_shop_format_price((int)($item['price_cents'] ?? 0), (string)($item['currency'] ?? 'USD'));
              $line = org_shop_format_price((int)($item['price_cents'] ?? 0) * (int)($item['quantity'] ?? 1), (string)($item['currency'] ?? 'USD'));
              $publisherId = (int)($item['publisher_user_id'] ?? 0);
              $seller = trim((string)($item['publisher_name'] ?? '')) ?: trim((string)($item['publisher_username'] ?? '')) ?: trim((string)($item['seller_name'] ?? 'Shop'));
            ?>
            <article class="cart-row" data-product-id="<?= (int)$item['product_id'] ?>">
              <a href="product_detail.php?id=<?= (int)$item['product_id'] ?>" class="cart-thumb">
                <?php if ($cover !== ''): ?><img src="<?= h($cover) ?>" alt=""><?php else: ?><i class="icon ion-bag"></i><?php endif; ?>
              </a>
              <div>
                <h2 class="cart-name"><a href="product_detail.php?id=<?= (int)$item['product_id'] ?>"><?= h((string)$item['title']) ?></a></h2>
                <div class="cart-seller"><?= h($seller) ?></div>
                <div class="cart-price"><?= h($unit) ?> each</div>
                <div class="cart-qty">
                  <label>Qty</label>
                  <input type="number" class="cart-qty-input" min="1" max="99" value="<?= (int)$item['quantity'] ?>" data-product-id="<?= (int)$item['product_id'] ?>">
                  <button type="button" class="cart-remove" data-remove="<?= (int)$item['product_id'] ?>">Remove</button>
                </div>
              </div>
              <div class="cart-line-total"><?= h($line) ?></div>
            </article>
          <?php endforeach; ?>
        </div>

        <div class="cart-summary">
          <div><strong>Subtotal:</strong> <?= h($subtotalLabel) ?></div>
          <p style="margin:0;font-size:13px;color:#6b7280;">
            <?= $shopStripeEnabled ? 'Single-item carts can pay with Stripe. Multi-item checkout creates separate orders per product.' : 'Orders are placed per product; the seller confirms payment.' ?>
          </p>
          <div>
            <label for="cartAddress">Delivery address</label>
            <textarea id="cartAddress" rows="2"></textarea>
          </div>
          <div>
            <label for="cartPhone">Phone</label>
            <input type="text" id="cartPhone">
          </div>
          <div>
            <label for="cartNotes">Notes for sellers</label>
            <textarea id="cartNotes" rows="2"></textarea>
          </div>
          <button type="button" class="cart-checkout" id="cartCheckoutBtn">Checkout</button>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<script>
(function(){
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

  const checkoutBtn = document.getElementById('cartCheckoutBtn');
  if (checkoutBtn) {
    checkoutBtn.addEventListener('click', async function(){
      checkoutBtn.disabled = true;
      try {
        const body = new URLSearchParams();
        body.set('delivery_address', (document.getElementById('cartAddress').value || '').trim());
        body.set('buyer_phone', (document.getElementById('cartPhone').value || '').trim());
        body.set('buyer_notes', (document.getElementById('cartNotes').value || '').trim());
        const res = await fetch('ajax/cart_checkout.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: body.toString(), credentials:'same-origin' });
        const data = await res.json();
        if (data.ok && data.checkout_url) { window.location.href = data.checkout_url; return; }
        if (data.ok) { window.location.href = 'my_orders.php'; return; }
        window.alert(data.message || 'Checkout failed.');
      } catch (e) {
        window.alert('Checkout failed.');
      } finally {
        checkoutBtn.disabled = false;
      }
    });
  }
})();
</script>
<script src="./lib/jquery/jquery.js"></script>
<script src="./js/shamcey.js"></script>
</body>
</html>
