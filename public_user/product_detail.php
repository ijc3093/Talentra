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
$canFollowPublishers = publisher_can_follow_as_viewer($dbh, $meId);

$productId = (int)($_GET['id'] ?? 0);
$product = org_shop_get_marketplace_product($dbh, $productId);

if (!function_exists('h')) {
    function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}

$shopStripeEnabled = stripe_shop_is_configured();
$notFound = ($product === null);

if (!$notFound) {
    $cover = org_shop_cover_url((string)($product['cover_image_path'] ?? ''));
    $price = org_shop_format_price((int)($product['price_cents'] ?? 0), (string)($product['currency'] ?? 'USD'));
    $publisherId = (int)($product['publisher_user_id'] ?? 0);
    $sellerLabel = trim((string)($product['publisher_name'] ?? ''))
        ?: trim((string)($product['publisher_username'] ?? ''))
        ?: trim((string)($product['seller_name'] ?? 'Shop'));
    $stock = $product['stock_qty'];
    $outOfStock = ($stock !== null && $stock !== '' && (int)$stock <= 0);
    $pageTitle = (string)($product['title'] ?? 'Product');
} else {
    $cover = '';
    $price = '';
    $publisherId = 0;
    $sellerLabel = '';
    $outOfStock = true;
    $pageTitle = 'Product not found';
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= h($pageTitle) ?></title>
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
    .pd-back{display:inline-flex;align-items:center;gap:6px;color:#2563eb;text-decoration:none;font-size:14px;font-weight:600;margin-bottom:12px;}
    .pd-back:hover{text-decoration:underline;}
    .pd-layout{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:28px;align-items:start;max-width:920px;}
    .pd-cover{background:var(--msb-palette-panel,#fff);border:1px solid var(--msb-palette-border,rgba(15,23,42,.08));border-radius:18px;overflow:hidden;aspect-ratio:1/1;display:flex;align-items:center;justify-content:center;}
    .pd-cover img{width:100%;height:100%;object-fit:cover;display:block;}
    .pd-cover-fallback{font-size:64px;color:#9ca3af;}
    .pd-info{display:flex;flex-direction:column;gap:14px;}
    .pd-title{margin:0;font-size:28px;line-height:1.2;font-weight:800;}
    .pd-seller{font-size:14px;color:#6b7280;}
    .pd-seller a{color:inherit;text-decoration:underline;}
    .pd-price{font-size:24px;font-weight:800;color:#111827;}
    .pd-stock{font-size:14px;color:#6b7280;}
    .pd-desc{font-size:15px;line-height:1.55;color:#374151;white-space:pre-wrap;}
    .pd-category{font-size:13px;color:#6b7280;}
    .pd-buy-panel{background:var(--msb-palette-panel,#fff);border:1px solid var(--msb-palette-border,rgba(15,23,42,.08));border-radius:16px;padding:16px;display:grid;gap:12px;}
    .pd-buy-panel label{display:block;font-size:13px;font-weight:600;margin-bottom:4px;}
    .pd-buy-panel input,.pd-buy-panel textarea{width:100%;border:1px solid rgba(15,23,42,.14);border-radius:10px;padding:10px 12px;font-size:14px;box-sizing:border-box;}
    .pd-buy-msg{font-size:13px;color:#b45309;}
    .pd-buy-btn{border:0;border-radius:12px;background:#2563eb;color:#fff;font-weight:700;font-size:16px;padding:14px 16px;cursor:pointer;}
    .pd-buy-btn:disabled{opacity:.55;cursor:not-allowed;}
    .pd-empty{text-align:center;padding:48px 16px;color:#6b7280;}
    @media (max-width:768px){
      .pd-layout{grid-template-columns:1fr;gap:20px;}
      .pd-title{font-size:24px;}
    }
  </style>
</head>
<body class="shop-page feed-page feed-insta-ui product-detail-page">

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
      <a href="shop.php" class="pd-back"><i class="icon ion-ios-arrow-left"></i> Back to Shop</a>

      <?php if ($notFound): ?>
        <div class="pd-empty">
          <i class="icon ion-bag" style="font-size:42px;display:block;margin-bottom:10px;"></i>
          This product is not available.
        </div>
      <?php else: ?>
        <div class="pd-layout">
          <div class="pd-cover">
            <?php if ($cover !== ''): ?>
              <img src="<?= h($cover) ?>" alt="<?= h((string)$product['title']) ?>">
            <?php else: ?>
              <span class="pd-cover-fallback"><i class="icon ion-bag"></i></span>
            <?php endif; ?>
          </div>

          <div class="pd-info">
            <h1 class="pd-title"><?= h((string)$product['title']) ?></h1>
            <div class="pd-seller">
              Sold by <a href="profile.php?tab=shop&amp;id=<?= $publisherId ?>"><?= h($sellerLabel) ?></a>
            </div>
            <div class="pd-price"><?= h($price) ?></div>
            <?php if ($stock !== null && $stock !== ''): ?>
              <div class="pd-stock"><?= (int)$stock > 0 ? (int)$stock . ' in stock' : 'Out of stock' ?></div>
            <?php endif; ?>
            <?php if (trim((string)($product['category'] ?? '')) !== ''): ?>
              <div class="pd-category"><?= h((string)$product['category']) ?></div>
            <?php endif; ?>
            <?php if (trim((string)($product['description'] ?? '')) !== ''): ?>
              <div class="pd-desc"><?= h((string)$product['description']) ?></div>
            <?php endif; ?>

            <?php if (!$outOfStock): ?>
            <div style="display:flex;gap:10px;flex-wrap:wrap;">
              <button type="button" class="pd-buy-btn" id="pdAddCartBtn" style="background:#f3f4f6;color:#111827;flex:1;">Add to cart</button>
            </div>
            <form class="pd-buy-panel" id="pdBuyForm">
              <div class="pd-buy-msg" id="pdBuyMsg">
                <?= $shopStripeEnabled ? 'You will be redirected to Stripe for secure payment.' : 'The seller confirms payment after you place the order.' ?>
              </div>
              <div>
                <label for="pdQty">Quantity</label>
                <input type="number" id="pdQty" name="quantity" min="1" max="99" value="1" required>
              </div>
              <div>
                <label for="pdAddress">Delivery address</label>
                <textarea id="pdAddress" name="delivery_address" rows="2" placeholder="Optional"></textarea>
              </div>
              <div>
                <label for="pdPhone">Phone</label>
                <input type="text" id="pdPhone" name="buyer_phone" placeholder="Optional">
              </div>
              <div>
                <label for="pdNotes">Notes for seller</label>
                <textarea id="pdNotes" name="buyer_notes" rows="2" placeholder="Optional"></textarea>
              </div>
              <button type="submit" class="pd-buy-btn" id="pdBuyBtn">Buy now</button>
            </form>
            <?php endif; ?>
          </div>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php if (!$notFound && !$outOfStock): ?>
<script>
(function(){
  const addBtn = document.getElementById('pdAddCartBtn');
  if (addBtn) {
    addBtn.addEventListener('click', async function(){
      addBtn.disabled = true;
      try {
        const body = new URLSearchParams();
        body.set('action', 'add');
        body.set('product_id', '<?= (int)$productId ?>');
        body.set('quantity', String(Math.max(1, Math.min(99, parseInt(document.getElementById('pdQty').value || '1', 10) || 1))));
        const res = await fetch('ajax/cart_action.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: body.toString(), credentials:'same-origin' });
        const data = await res.json();
        window.alert(data.message || (data.ok ? 'Added to cart.' : 'Failed.'));
      } catch (e) {
        window.alert('Could not add to cart.');
      } finally {
        addBtn.disabled = false;
      }
    });
  }

  const form = document.getElementById('pdBuyForm');
  const btn = document.getElementById('pdBuyBtn');
  if (!form || !btn) return;

  form.addEventListener('submit', async function(e){
    e.preventDefault();
    btn.disabled = true;
    try {
      const body = new URLSearchParams();
      body.set('product_id', '<?= (int)$productId ?>');
      body.set('quantity', String(Math.max(1, Math.min(99, parseInt(document.getElementById('pdQty').value || '1', 10) || 1))));
      body.set('delivery_address', (document.getElementById('pdAddress').value || '').trim());
      body.set('buyer_phone', (document.getElementById('pdPhone').value || '').trim());
      body.set('buyer_notes', (document.getElementById('pdNotes').value || '').trim());
      body.set('profile_id', '<?= (int)$publisherId ?>');

      const res = await fetch('ajax/shop_buy.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body.toString(),
        credentials: 'same-origin'
      });
      const data = await res.json();
      if (data.ok && data.checkout_url) {
        window.location.href = data.checkout_url;
        return;
      }
      if (data.ok) {
        window.alert(data.message || 'Order placed.');
        return;
      }
      window.alert(data.message || 'Order failed.');
    } catch (err) {
      window.alert('Could not place order.');
    } finally {
      btn.disabled = false;
    }
  });
})();
</script>
<?php endif; ?>

<script src="./lib/jquery/jquery.js"></script>
<script src="./js/shamcey.js"></script>
</body>
</html>
