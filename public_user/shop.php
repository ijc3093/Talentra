<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/session_user.php';
requireUserLogin();

require_once __DIR__ . '/controller.php';
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
$staffReadonly = staff_pub_is_readonly();
$canLiveStudio = function_exists('live_studio_user_can_access') ? live_studio_user_can_access($dbh, $meId) : false;
$canFollowPublishers = publisher_can_follow_as_viewer($dbh, $meId);

$products = org_shop_list_marketplace_products($dbh, 120);
$shopStripeEnabled = stripe_shop_is_configured();

if (!function_exists('h')) {
    function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Shop</title>
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
    .shop-page-title{font-size:22px;font-weight:800;padding:8px 0 0;margin:0;}
    .shop-page-sub{padding:4px 0 12px;color:#6b7280;font-size:14px;margin:0;}
    .shop-market-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:16px;padding:0 0 24px;max-width:980px;margin:0;}
    .shop-market-card{background:var(--msb-palette-panel,#fff);border:1px solid var(--msb-palette-border,rgba(15,23,42,.08));border-radius:16px;overflow:hidden;display:flex;flex-direction:column;}
    .shop-market-cover{aspect-ratio:1/1;background:rgba(15,23,42,.04);display:flex;align-items:center;justify-content:center;text-decoration:none;color:inherit;}
    .shop-market-cover img{width:100%;height:100%;object-fit:cover;}
    .shop-market-cover-fallback{font-size:42px;color:#9ca3af;}
    .shop-market-body{padding:12px 14px 14px;display:flex;flex-direction:column;gap:8px;flex:1;}
    .shop-market-body h3{margin:0;font-size:16px;}
    .shop-market-seller{font-size:13px;color:#6b7280;}
    .shop-market-seller a{color:inherit;text-decoration:underline;}
    .shop-market-meta{display:flex;justify-content:space-between;align-items:center;font-size:13px;color:#6b7280;}
    .shop-market-meta strong{font-size:16px;color:#111827;}
    .shop-market-buy{margin-top:auto;border:0;border-radius:10px;background:#2563eb;color:#fff;font-weight:700;padding:10px 12px;cursor:pointer;}
    .shop-market-empty{text-align:center;padding:48px 16px;color:#6b7280;}
    .shop-buy-modal{position:fixed;inset:0;z-index:12000;display:none;align-items:center;justify-content:center;padding:16px;background:rgba(15,23,42,.45);}
    .shop-buy-modal.is-open{display:flex;}
    .shop-buy-card{width:min(420px,100%);background:#fff;border-radius:18px;box-shadow:0 24px 60px rgba(0,0,0,.18);overflow:hidden;}
    .shop-buy-head{padding:18px 20px 8px;font-size:18px;font-weight:700;}
    .shop-buy-sub{padding:0 20px 12px;color:#6b7280;font-size:14px;}
    .shop-buy-body{padding:0 20px 16px;display:grid;gap:12px;}
    .shop-buy-body label{display:block;font-size:13px;font-weight:600;margin-bottom:4px;}
    .shop-buy-body input,.shop-buy-body textarea{width:100%;border:1px solid rgba(15,23,42,.14);border-radius:10px;padding:10px 12px;font-size:14px;box-sizing:border-box;}
    .shop-buy-foot{display:flex;gap:10px;padding:0 20px 18px;}
    .shop-buy-foot button{flex:1;border:0;border-radius:10px;padding:12px;font-weight:700;cursor:pointer;}
    .shop-buy-cancel{background:#f3f4f6;}
    .shop-buy-submit{background:#2563eb;color:#fff;}
  </style>
</head>
<body class="shop-page feed-page feed-insta-ui">

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
      <?php $feedTopShopActive = true; include __DIR__ . '/includes/feed_top_actions.php'; ?>
    </div>

    <div class="shop-page-shell">
    <h1 class="shop-page-title">Shop</h1>
    <p class="shop-page-sub">Browse products from publishers and buy securely.</p>

    <?php if (!$products): ?>
      <div class="shop-market-empty">
        <i class="icon ion-bag" style="font-size:42px;display:block;margin-bottom:10px;"></i>
        No products available right now. Check back when publishers list items.
      </div>
    <?php else: ?>
      <div class="shop-market-grid">
        <?php foreach ($products as $p): ?>
          <?php
            $cover = org_shop_cover_url((string)($p['cover_image_path'] ?? ''));
            $price = org_shop_format_price((int)($p['price_cents'] ?? 0), (string)($p['currency'] ?? 'USD'));
            $publisherId = (int)($p['publisher_user_id'] ?? 0);
            $sellerLabel = trim((string)($p['publisher_name'] ?? '')) ?: trim((string)($p['publisher_username'] ?? '')) ?: trim((string)($p['seller_name'] ?? 'Shop'));
            $stock = $p['stock_qty'];
            $outOfStock = ($stock !== null && $stock !== '' && (int)$stock <= 0);
          ?>
          <article class="shop-market-card">
            <a href="product_detail.php?id=<?= (int)$p['id'] ?>" class="shop-market-cover">
              <?php if ($cover !== ''): ?>
                <img src="<?= h($cover) ?>" alt="">
              <?php else: ?>
                <span class="shop-market-cover-fallback"><i class="icon ion-bag"></i></span>
              <?php endif; ?>
            </a>
            <div class="shop-market-body">
              <h3><a href="product_detail.php?id=<?= (int)$p['id'] ?>" style="color:inherit;text-decoration:none;"><?= h((string)$p['title']) ?></a></h3>
              <div class="shop-market-seller">
                by <a href="profile.php?tab=shop&amp;id=<?= $publisherId ?>"><?= h($sellerLabel) ?></a>
              </div>
              <div class="shop-market-meta">
                <strong><?= h($price) ?></strong>
                <?php if ($stock !== null && $stock !== ''): ?>
                  <span><?= (int)$stock > 0 ? (int)$stock . ' left' : 'Out of stock' ?></span>
                <?php endif; ?>
              </div>
              <?php if (!$outOfStock): ?>
                <div style="display:flex;gap:8px;margin-top:auto;">
                  <button type="button" class="shop-market-buy shop-add-cart" data-cart-add="<?= (int)$p['id'] ?>" style="flex:1;background:#f3f4f6;color:#111827;">Add to cart</button>
                  <button type="button" class="shop-market-buy" data-shop-buy="<?= (int)$p['id'] ?>" data-shop-title="<?= h((string)$p['title']) ?>" data-shop-price="<?= h($price) ?>" data-shop-profile="<?= $publisherId ?>" style="flex:1;">Buy</button>
                </div>
              <?php endif; ?>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
    </div>
  </div>
</div>

<div class="shop-buy-modal" id="shopBuyModal" aria-hidden="true" role="dialog">
  <div class="shop-buy-card">
    <div class="shop-buy-head" id="shopBuyTitle">Product</div>
    <div class="shop-buy-sub" id="shopBuyPrice"></div>
    <div class="shop-buy-msg" id="shopBuyMsg" style="padding:0 20px 12px;font-size:13px;color:#b45309;"></div>
    <div class="shop-buy-body">
      <div><label for="shopBuyQty">Quantity</label><input type="number" id="shopBuyQty" min="1" max="99" value="1"></div>
      <div><label for="shopBuyAddress">Delivery address</label><textarea id="shopBuyAddress" rows="2"></textarea></div>
      <div><label for="shopBuyPhone">Phone</label><input type="text" id="shopBuyPhone"></div>
      <div><label for="shopBuyNotes">Notes</label><textarea id="shopBuyNotes" rows="2"></textarea></div>
    </div>
    <div class="shop-buy-foot">
      <button type="button" class="shop-buy-cancel" id="shopBuyCancel">Cancel</button>
      <button type="button" class="shop-buy-submit" id="shopBuySubmit">Place order</button>
    </div>
  </div>
</div>

<script src="./lib/jquery/jquery.js"></script>
<script src="./js/shamcey.js"></script>
<script>
(function(){
  const modal = document.getElementById('shopBuyModal');
  if (!modal) return;
  const titleEl = document.getElementById('shopBuyTitle');
  const priceEl = document.getElementById('shopBuyPrice');
  const msgEl = document.getElementById('shopBuyMsg');
  const qtyEl = document.getElementById('shopBuyQty');
  const addrEl = document.getElementById('shopBuyAddress');
  const notesEl = document.getElementById('shopBuyNotes');
  const phoneEl = document.getElementById('shopBuyPhone');
  const submitBtn = document.getElementById('shopBuySubmit');
  const cancelBtn = document.getElementById('shopBuyCancel');
  const stripeEnabled = <?= $shopStripeEnabled ? 'true' : 'false' ?>;
  let activeProductId = 0;
  let profileViewId = 0;

  function openModal(btn){
    activeProductId = parseInt(btn.getAttribute('data-shop-buy') || '0', 10);
    profileViewId = parseInt(btn.getAttribute('data-shop-profile') || '0', 10);
    if (!activeProductId) return;
    if (titleEl) titleEl.textContent = btn.getAttribute('data-shop-title') || 'Product';
    if (priceEl) priceEl.textContent = btn.getAttribute('data-shop-price') || '';
    if (msgEl) msgEl.textContent = stripeEnabled ? 'You will be redirected to Stripe for secure payment.' : 'The seller confirms payment after you place the order.';
    if (qtyEl) qtyEl.value = '1';
    modal.classList.add('is-open');
  }
  function closeModal(){ modal.classList.remove('is-open'); activeProductId = 0; }

  document.querySelectorAll('[data-shop-buy]').forEach(btn => btn.addEventListener('click', () => openModal(btn)));
  if (cancelBtn) cancelBtn.addEventListener('click', closeModal);
  modal.addEventListener('click', e => { if (e.target === modal) closeModal(); });

  if (submitBtn) submitBtn.addEventListener('click', async function(){
    if (!activeProductId) return;
    submitBtn.disabled = true;
    try {
      const body = new URLSearchParams();
      body.set('product_id', String(activeProductId));
      body.set('quantity', String(Math.max(1, Math.min(99, parseInt(qtyEl.value || '1', 10) || 1))));
      body.set('delivery_address', addrEl ? addrEl.value.trim() : '');
      body.set('buyer_notes', notesEl ? notesEl.value.trim() : '');
      body.set('buyer_phone', phoneEl ? phoneEl.value.trim() : '');
      body.set('profile_id', String(profileViewId));
      const res = await fetch('ajax/shop_buy.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: body.toString(), credentials:'same-origin' });
      const data = await res.json();
      if (data.ok && data.checkout_url) { window.location.href = data.checkout_url; return; }
      if (data.ok) { closeModal(); window.alert(data.message || 'Order placed.'); return; }
      window.alert(data.message || 'Order failed.');
    } catch (e) {
      window.alert('Could not place order.');
    } finally {
      submitBtn.disabled = false;
    }
  });
})();

(function(){
  document.querySelectorAll('[data-cart-add]').forEach(btn => {
    btn.addEventListener('click', async function(){
      const productId = parseInt(btn.getAttribute('data-cart-add') || '0', 10);
      if (!productId) return;
      btn.disabled = true;
      try {
        const body = new URLSearchParams();
        body.set('action', 'add');
        body.set('product_id', String(productId));
        body.set('quantity', '1');
        const res = await fetch('ajax/cart_action.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: body.toString(), credentials:'same-origin' });
        const data = await res.json();
        const badge = document.getElementById('feedTopCartBadge');
        if (badge && data.count > 0) badge.textContent = String(data.count);
        window.alert(data.message || (data.ok ? 'Added to cart.' : 'Failed.'));
      } catch (e) {
        window.alert('Could not add to cart.');
      } finally {
        btn.disabled = false;
      }
    });
  });
})();
</script>
</body>
</html>
