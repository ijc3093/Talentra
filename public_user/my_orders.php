<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/session_user.php';
requireUserLogin();

require_once __DIR__ . '/controller.php';
require_once __DIR__ . '/includes/org_shop.php';
require_once __DIR__ . '/includes/stripe_shop.php';
require_once __DIR__ . '/includes/commerce_messaging.php';
require_once __DIR__ . '/includes/theme_prefs.php';
require_once __DIR__ . '/includes/staff_publisher_access.php';
require_once __DIR__ . '/includes/publisher_accounts_load.php';

$controller = new Controller();
$dbh = $controller->pdo();
$meId = (int)($_SESSION['user_id'] ?? $_SESSION['id'] ?? $_SESSION['userid'] ?? 0);
org_shop_ensure_schema($dbh);
$GLOBALS['feedTopDbh'] = $dbh;
$GLOBALS['feedTopMeId'] = $meId;
$canFollowPublishers = publisher_can_follow_as_viewer($dbh, $meId);

require_once __DIR__ . '/includes/shop_filter_context.php';

$msg = '';
$error = '';
$sessionId = trim((string)($_GET['session_id'] ?? ''));

if ($sessionId !== '') {
    $session = stripe_shop_retrieve_session($sessionId);
    if ($session && org_shop_fulfill_stripe_session($dbh, $session)) {
        $code = trim((string)($session['client_reference_id'] ?? ''));
        $msg = $code !== ''
            ? 'Payment received. Order ' . $code . ' is confirmed.'
            : 'Payment received. Your order is confirmed.';
    } elseif ($session && (string)($session['payment_status'] ?? '') !== 'paid') {
        $error = 'Payment was not completed. You can retry from the shop.';
    } else {
        $error = 'Could not verify payment. Contact support if you were charged.';
    }
}

if ((string)($_GET['checkout'] ?? '') === 'cancel') {
    $error = $error !== '' ? $error : 'Checkout was cancelled.';
}

$orders = org_shop_list_buyer_orders($dbh, $meId);
$orderCount = count($orders);
$totalSpentCents = 0;
foreach ($orders as $orderRow) {
    $totalSpentCents += (int)($orderRow['total_cents'] ?? 0);
}
$totalSpentLabel = org_shop_format_price($totalSpentCents, 'USD');

if (!function_exists('h')) {
    function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}

function my_orders_fmt_dt(?string $dt): string
{
    if (!$dt) {
        return '—';
    }
    $t = strtotime($dt);
    return $t ? date('M d, Y', $t) : '—';
}

function my_orders_status_class(string $status): string
{
    $status = strtolower(trim($status));
    $map = [
        'pending' => 'is-pending',
        'confirmed' => 'is-confirmed',
        'paid' => 'is-paid',
        'shipped' => 'is-shipped',
        'delivered' => 'is-delivered',
        'cancelled' => 'is-cancelled',
    ];
    return 'orders-status ' . ($map[$status] ?? 'is-pending');
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>My Orders</title>
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
    .orders-back{display:inline-flex;align-items:center;gap:6px;color:var(--shop-link,var(--msb-palette-link,#2563eb));text-decoration:none;font-size:14px;font-weight:600;}
    .orders-back:hover{text-decoration:underline;}
    .orders-page-main{max-width:720px;width:100%;}
    .orders-title{font-size:22px;font-weight:800;margin:0;color:var(--shop-text,var(--msb-palette-text,inherit));}
    .orders-sub{color:var(--shop-text-muted,var(--msb-palette-text-muted,#6b7280));font-size:14px;margin:4px 0 0;}
    .orders-page-intro .orders-sub{margin-bottom:0;}
    .orders-page-title-row{display:flex;align-items:center;justify-content:space-between;gap:16px;max-width:720px;margin-top:20px;}
    .orders-page-total{font-size:15px;font-weight:700;white-space:nowrap;color:var(--shop-text,var(--msb-palette-text,inherit));flex-shrink:0;}
    .orders-page-actions{display:flex;justify-content:flex-start;align-items:center;width:100%;gap:16px;}
    .orders-page-footer{flex-shrink:0;padding:8px 0 0;margin-bottom:72px;}
    .orders-list{display:grid;gap:12px;}
    .orders-row{
      display:grid;
      grid-template-columns:88px 1fr auto;
      gap:14px;
      align-items:start;
      background:var(--shop-card-bg,var(--msb-palette-bg,#fff));
      border:1px solid var(--shop-border,var(--msb-palette-border,rgba(15,23,42,.08)));
      border-radius:14px;
      padding:12px;
      color:var(--shop-text,var(--msb-palette-text,inherit));
    }
    .orders-row-aside{display:flex;flex-direction:column;align-items:flex-end;gap:10px;min-width:88px;}
    .orders-thumb{width:88px;height:88px;border-radius:10px;overflow:hidden;background:var(--shop-card-raised,rgba(15,23,42,.04));display:flex;align-items:center;justify-content:center;color:var(--shop-text-muted,var(--msb-palette-text-muted,#6b7280));}
    .orders-thumb img{width:100%;height:100%;object-fit:cover;}
    .orders-name{font-size:16px;font-weight:700;margin:0 0 4px;color:var(--shop-text,var(--msb-palette-text,inherit));}
    .orders-name a{color:inherit;text-decoration:none;}
    .orders-price{font-size:15px;font-weight:700;color:var(--shop-text,var(--msb-palette-text,inherit));}
    .orders-meta{font-size:13px;color:var(--shop-text-muted,var(--msb-palette-text-muted,#6b7280));margin:6px 0 0;line-height:1.45;}
    .orders-meta code{font-size:12px;color:var(--shop-text,var(--msb-palette-text,inherit));}
    .orders-meta a{color:var(--shop-link,var(--msb-palette-link,#2563eb));text-decoration:none;}
    .orders-meta a:hover{text-decoration:underline;}
    .orders-actions{display:flex;align-items:center;gap:8px;margin-top:8px;flex-wrap:wrap;}
    .orders-qty-label{font-size:13px;color:var(--shop-text-muted,var(--msb-palette-text-muted,#6b7280));}
    .orders-btn{
      border:1px solid var(--shop-border,var(--msb-palette-border,rgba(177,188,206,.45)));
      background:var(--shop-btn-outline-bg,transparent);
      color:var(--shop-btn-outline-text,var(--shop-text,var(--msb-palette-text,inherit)));
      font-size:13px;
      font-weight:600;
      cursor:pointer;
      padding:4px 10px;
      border-radius:8px;
    }
    .orders-btn-primary{
      border-color:var(--shop-border,var(--msb-palette-border,rgba(177,188,206,.55)));
      background:var(--shop-btn-filled-bg,var(--msb-palette-btn-bg,#3d4a32));
      color:var(--shop-btn-filled-text,var(--msb-palette-btn-text,#fff));
    }
    .orders-btn-danger{
      border-color:#fca5a5;
      color:#b91c1c;
      background:transparent;
    }
    .orders-btn-danger:hover{
      background:#fee2e2;
    }
    .orders-details-link{
      font-size:13px;
      font-weight:600;
      color:var(--shop-link,var(--msb-palette-link,#2563eb));
      text-decoration:none;
      white-space:nowrap;
    }
    .orders-details-link:hover{text-decoration:underline;}
    .orders-line-total{font-size:15px;font-weight:700;text-align:right;white-space:nowrap;color:var(--shop-text,var(--msb-palette-text,inherit));}
    .orders-seller{font-size:12px;color:var(--shop-text-muted,var(--msb-palette-text-muted,#6b7280));margin:0;text-align:right;max-width:120px;line-height:1.3;}
    .orders-seller a{color:inherit;text-decoration:none;}
    .orders-seller a:hover{text-decoration:underline;color:var(--shop-link,var(--msb-palette-link,#2563eb));}
    .orders-status{
      display:inline-flex;
      align-items:center;
      justify-content:center;
      font-size:11px;
      font-weight:700;
      letter-spacing:.06em;
      text-transform:uppercase;
      padding:4px 8px;
      border-radius:999px;
      border:1px solid var(--shop-border,var(--msb-palette-border,rgba(177,188,206,.45)));
      color:var(--shop-text,var(--msb-palette-text,inherit));
      background:var(--shop-card-raised,rgba(15,23,42,.04));
    }
    .orders-status.is-paid,.orders-status.is-delivered{color:#166534;border-color:#86efac;background:#dcfce7;}
    .orders-status.is-shipped{color:#1d4ed8;border-color:#93c5fd;background:#dbeafe;}
    .orders-status.is-pending,.orders-status.is-confirmed{color:#9a3412;border-color:#fdba74;background:#ffedd5;}
    .orders-status.is-cancelled{color:var(--shop-text-muted,var(--msb-palette-text-muted,#6b7280));opacity:.85;}
    .orders-review-form{
      margin-top:10px;
      padding-top:10px;
      border-top:1px solid var(--shop-border,var(--msb-palette-border,rgba(15,23,42,.08)));
      display:grid;
      gap:8px;
      max-width:420px;
    }
    .orders-review-form[hidden]{display:none;}
    .orders-review-form label{font-size:13px;font-weight:600;color:var(--shop-text-muted,var(--msb-palette-text-muted,#6b7280));}
    .orders-review-form .form-control{
      background:var(--shop-input-bg,var(--msb-palette-input-bg,transparent));
      border:1px solid var(--shop-border-strong,var(--shop-border,var(--msb-palette-border,rgba(15,23,42,.14))));
      border-radius:8px;
      color:var(--shop-text,var(--msb-palette-text,inherit));
    }
    .orders-empty{text-align:center;padding:48px 16px;color:var(--shop-text-muted,var(--msb-palette-text-muted,#6b7280));}
    .orders-alert{padding:10px 12px;border-radius:10px;margin:12px 0 0;font-size:14px;}
    .orders-alert-success{background:#dcfce7;color:#166534;border:1px solid #86efac;}
    .orders-alert-danger{background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;}
    .shop-page-head-mobile .shop-page-title{font-size:22px;font-weight:800;padding:8px 0 0;margin:0;color:var(--shop-text,var(--msb-palette-text,#111827));}
    .shop-page-head-mobile .shop-page-sub{padding:4px 0 0;color:var(--shop-text-muted,var(--msb-palette-text-muted,#6b7280));font-size:14px;margin:0;}
    @media (max-width:640px){
      .orders-row{grid-template-columns:72px 1fr;}
      .orders-row-aside{
        grid-column:2;
        flex-direction:column;
        align-items:flex-end;
        min-width:0;
        margin-top:4px;
      }
      .orders-line-total{text-align:left;}
      .orders-page-title-row{flex-wrap:wrap;}
    }
  </style>
</head>
<body class="shop-page feed-page feed-insta-ui my-orders-page">

<?php
  $GLOBALS['msb_skip_header_leftbar'] = true;
  $skipHeaderThemeBootstrap = true;
  include __DIR__ . '/includes/header.php';
?>
<?php
  $feedLeftRailActive = 'shop.php';
  $feedLeftRailCanFollow = $canFollowPublishers;
  $feedLeftRailShopOnly = true;
  $feedLeftRailShopFilters = true;
  $feedLeftRailPageHeadTitle = 'Shop';
  $feedLeftRailPageHeadSub = 'Track orders, shipping, returns, and reviews.';
  include __DIR__ . '/includes/feed_left_rail.php';
?>

<div class="sh-mainpanel">
  <?php include __DIR__ . '/includes/leftbar.php'; ?>
  <?php include __DIR__ . '/includes/stories_right_door.php'; ?>
  <div class="sh-pagebody">
    <div class="ig-feed-header">
      <?php include __DIR__ . '/includes/feed_top_user_lead.php'; ?>
      <?php include __DIR__ . '/includes/shop_header_search.php'; ?>
      <?php $feedTopShopActive = true; $feedTopShopOnly = true; include __DIR__ . '/includes/feed_top_actions.php'; ?>
    </div>

    <div class="shop-page-shell">
      <div class="shop-page-head-mobile">
        <h1 class="shop-page-title">Shop</h1>
        <p class="shop-page-sub">Track shipments, request returns, and leave reviews.</p>
      </div>

      <div class="orders-page-intro">
        <div class="orders-page-title-row">
          <h1 class="orders-title">My orders</h1>
          <?php if ($orders): ?>
            <div class="orders-page-total"><strong>Total:</strong> <?= h($totalSpentLabel) ?></div>
          <?php endif; ?>
        </div>
        <p class="orders-sub"><?= $orderCount ?> order<?= $orderCount === 1 ? '' : 's' ?></p>

        <?php if ($error !== ''): ?><div class="orders-alert orders-alert-danger"><?= h($error) ?></div><?php endif; ?>
        <?php if ($msg !== ''): ?><div class="orders-alert orders-alert-success"><?= h($msg) ?></div><?php endif; ?>
      </div>

      <div class="orders-page-scroll">
        <?php if (!$orders): ?>
          <div class="orders-empty">
            <i class="icon ion-ios-paper" style="font-size:42px;display:block;margin-bottom:10px;"></i>
            You have not placed any shop orders yet.
            <div style="margin-top:12px;"><a href="shop.php" class="orders-details-link">Browse the marketplace</a></div>
          </div>
        <?php else: ?>
          <div class="orders-page-main">
            <div class="orders-list">
              <?php foreach ($orders as $o): ?>
                <?php
                  $orderId = (int)($o['id'] ?? 0);
                  $productId = (int)($o['product_id'] ?? 0);
                  $total = org_shop_format_price((int)($o['total_cents'] ?? 0), (string)($o['currency'] ?? 'USD'));
                  $unit = org_shop_format_price((int)($o['unit_price_cents'] ?? 0), (string)($o['currency'] ?? 'USD'));
                  $status = (string)($o['status'] ?? 'pending');
                  $publisherId = (int)($o['publisher_user_id'] ?? 0);
                  $seller = trim((string)($o['seller_name'] ?? '')) ?: 'Shop';
                  $brandName = trim((string)($o['commerce_brand_name'] ?? ''));
                  if ($brandName === '') {
                      $brandName = $seller;
                  }
                  $brandShopUrl = org_shop_order_brand_shop_url($o);
                  $tracking = trim((string)($o['tracking_number'] ?? ''));
                  $carrier = trim((string)($o['carrier'] ?? ''));
                  $fulfillment = strtoupper((string)($o['fulfillment_method'] ?? 'fbm'));
                  $deliveryOpt = ucwords(str_replace('_', ' ', (string)($o['delivery_option'] ?? 'home_delivery')));
                  $canReturn = in_array($status, ['paid', 'shipped', 'delivered'], true);
                  $canReview = $status === 'delivered';
                  $canCancel = in_array($status, ['pending', 'confirmed', 'paid'], true);
                  $cover = org_shop_cover_url((string)($o['cover_image_path'] ?? ''));
                  $detailLabel = trim((string)($o['category'] ?? ''));
                  if ($detailLabel === '') {
                      $detailLabel = trim((string)($o['product_title'] ?? 'Item'));
                  }
                ?>
                <article class="orders-row" data-order-id="<?= $orderId ?>">
                  <?php if ($productId > 0): ?>
                    <a href="product_detail.php?id=<?= $productId ?>" class="orders-thumb">
                      <?php if ($cover !== ''): ?><img src="<?= h($cover) ?>" alt=""><?php else: ?><i class="icon ion-bag"></i><?php endif; ?>
                    </a>
                  <?php else: ?>
                    <div class="orders-thumb"><i class="icon ion-bag"></i></div>
                  <?php endif; ?>

                  <div>
                    <h2 class="orders-name">
                      <?php if ($productId > 0): ?>
                        <a href="product_detail.php?id=<?= $productId ?>"><?= h((string)($o['product_title'] ?? '')) ?></a>
                      <?php else: ?>
                        <?= h((string)($o['product_title'] ?? '')) ?>
                      <?php endif; ?>
                    </h2>
                    <div class="orders-price"><?= h($unit) ?> each</div>
                    <div class="orders-meta">
                      Order <code><?= h((string)($o['order_code'] ?? '')) ?></code>
                      · Placed <?= h(my_orders_fmt_dt((string)($o['created_at'] ?? ''))) ?>
                    </div>
                    <div class="orders-meta">
                      <?= h($fulfillment) ?> · <?= h($deliveryOpt) ?>
                      <?php if ($tracking !== ''): ?>
                        · <?= h($carrier !== '' ? $carrier . ' ' : '') ?><?= h($tracking) ?>
                      <?php elseif ($status === 'shipped'): ?>
                        · Shipment in progress
                      <?php endif; ?>
                    </div>
                    <?php if (!empty($o['receipt_code'])): ?>
                      <div class="orders-meta">Receipt <code><?= h((string)$o['receipt_code']) ?></code></div>
                    <?php endif; ?>

                    <div class="orders-actions">
                      <span class="orders-qty-label">Qty <?= (int)($o['quantity'] ?? 1) ?></span>
                      <?php if ($canCancel): ?>
                        <button type="button" class="orders-btn js-order-cancel" data-order-id="<?= $orderId ?>">Cancel order</button>
                      <?php endif; ?>
                      <?php if ($canReturn): ?>
                        <button type="button" class="orders-btn js-order-return" data-order-id="<?= $orderId ?>">Request return</button>
                      <?php endif; ?>
                      <?php if ($canReview): ?>
                        <button type="button" class="orders-btn orders-btn-primary js-order-review-toggle" data-order-id="<?= $orderId ?>">Leave review</button>
                      <?php endif; ?>
                      <button type="button" class="orders-btn orders-btn-danger js-order-delete" data-order-id="<?= $orderId ?>" data-order-status="<?= h($status) ?>">Delete</button>
                      <?php if ($productId > 0): ?>
                        <a href="order_detail.php?order_id=<?= (int)$orderId ?>&amp;embed=1" class="orders-details-link js-open-order-details-door" data-order-id="<?= (int)$orderId ?>" data-door-url="order_detail.php?order_id=<?= (int)$orderId ?>&amp;embed=1">View Order details · <?= h($detailLabel) ?></a>
                      <?php endif; ?>
                    </div>

                    <?php if ($canReview): ?>
                      <form class="orders-review-form" id="reviewForm-<?= $orderId ?>" hidden>
                        <label for="reviewRating-<?= $orderId ?>">Rating</label>
                        <select class="form-control" id="reviewRating-<?= $orderId ?>" name="rating">
                          <?php for ($r = 5; $r >= 1; $r--): ?>
                            <option value="<?= $r ?>"><?= $r ?> star<?= $r === 1 ? '' : 's' ?></option>
                          <?php endfor; ?>
                        </select>
                        <label for="reviewText-<?= $orderId ?>">Review</label>
                        <textarea class="form-control" id="reviewText-<?= $orderId ?>" name="review_text" rows="2" placeholder="Share your experience"></textarea>
                        <button type="submit" class="orders-btn orders-btn-primary">Submit review</button>
                      </form>
                    <?php endif; ?>
                  </div>

                  <div class="orders-row-aside">
                    <div class="orders-line-total"><?= h($total) ?></div>
                    <span class="<?= h(my_orders_status_class($status)) ?>"><?= h($status) ?></span>
                    <div class="orders-seller">
                      <?php if ($brandShopUrl !== ''): ?>
                        <a href="<?= h($brandShopUrl) ?>" title="View <?= h($brandName) ?> brand group"><?= h($brandName) ?></a>
                        <?php if ($publisherId > 0): ?>
                          <div class="tx-11" style="margin-top:4px;">
                            <a href="<?= h(commerce_message_seller_url($publisherId, (int)($o['product_id'] ?? 0), (string)($o['order_code'] ?? ''))) ?>">Message seller</a>
                          </div>
                        <?php endif; ?>
                      <?php elseif ($publisherId > 0): ?>
                        <a href="profile.php?tab=shop&amp;id=<?= $publisherId ?>"><?= h($seller) ?></a>
                        <div class="tx-11" style="margin-top:4px;">
                          <a href="<?= h(commerce_message_seller_url($publisherId, (int)($o['product_id'] ?? 0), (string)($o['order_code'] ?? ''))) ?>">Message seller</a>
                        </div>
                      <?php else: ?>
                        <?= h($seller) ?>
                      <?php endif; ?>
                    </div>
                  </div>
                </article>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>
      </div>

      <div class="orders-page-footer">
        <div class="orders-page-main orders-page-actions">
          <a href="shop.php" class="orders-back"><i class="icon ion-ios-arrow-left"></i> Continue shopping</a>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
(function(){
  document.querySelectorAll('.js-order-return').forEach(btn => {
    btn.addEventListener('click', async function(){
      const orderId = btn.getAttribute('data-order-id');
      const reason = window.prompt('Why are you returning this item?');
      if (!reason || !reason.trim()) return;
      const body = new URLSearchParams();
      body.set('order_id', orderId);
      body.set('reason', reason.trim());
      const res = await fetch('ajax/order_return.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: body.toString(), credentials:'same-origin' });
      const data = await res.json();
      window.alert(data.message || (data.ok ? 'Return requested.' : 'Failed.'));
    });
  });

  document.querySelectorAll('.js-order-cancel').forEach(btn => {
    btn.addEventListener('click', async function(){
      const orderId = btn.getAttribute('data-order-id');
      if (!window.confirm('Cancel this order? The seller will see it as cancelled.')) return;
      const reason = window.prompt('Why are you cancelling? (optional)', 'Changed mind');
      if (reason === null) return;
      const body = new URLSearchParams();
      body.set('order_id', orderId);
      body.set('reason', reason.trim() || 'Changed mind');
      const res = await fetch('ajax/order_cancel.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: body.toString(), credentials:'same-origin' });
      const data = await res.json();
      window.alert(data.message || (data.ok ? 'Order cancelled.' : 'Failed.'));
      if (data.ok) window.location.reload();
    });
  });

  document.querySelectorAll('.js-order-delete').forEach(btn => {
    btn.addEventListener('click', async function(){
      const orderId = btn.getAttribute('data-order-id');
      const status = (btn.getAttribute('data-order-status') || '').toLowerCase();
      const active = ['pending', 'confirmed', 'paid', 'shipped'].indexOf(status) >= 0;
      const msg = active
        ? 'Remove this order from your list? The seller still has the order — use Cancel order if you need to stop it.'
        : 'Delete this order from your list? This cannot be undone.';
      if (!window.confirm(msg)) return;
      const body = new URLSearchParams();
      body.set('order_id', orderId);
      const res = await fetch('ajax/order_delete.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: body.toString(), credentials:'same-origin' });
      const data = await res.json();
      if (!data.ok) {
        window.alert(data.message || 'Could not delete this order.');
        return;
      }
      const row = btn.closest('.orders-row');
      if (row) {
        row.remove();
        const list = document.querySelector('.orders-list');
        if (list && !list.querySelector('.orders-row')) {
          window.location.reload();
        }
      } else {
        window.location.reload();
      }
    });
  });

  document.querySelectorAll('.js-order-review-toggle').forEach(btn => {
    btn.addEventListener('click', function(){
      const orderId = btn.getAttribute('data-order-id');
      const form = document.getElementById('reviewForm-' + orderId);
      if (!form) return;
      form.hidden = !form.hidden;
    });
  });

  document.querySelectorAll('.orders-review-form').forEach(form => {
    form.addEventListener('submit', async function(e){
      e.preventDefault();
      const card = form.closest('.orders-row');
      const orderId = card ? card.getAttribute('data-order-id') : '';
      if (!orderId) return;
      const body = new URLSearchParams();
      body.set('order_id', orderId);
      body.set('rating', form.querySelector('[name="rating"]').value);
      body.set('review_text', form.querySelector('[name="review_text"]').value || '');
      const res = await fetch('ajax/product_review.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: body.toString(), credentials:'same-origin' });
      const data = await res.json();
      window.alert(data.message || (data.ok ? 'Review saved.' : 'Failed.'));
      if (data.ok) form.hidden = true;
    });
  });

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

  document.querySelectorAll('.js-open-order-details-door').forEach(function(link){
    link.addEventListener('click', function(e){
      e.preventDefault();
      e.stopPropagation();
      var url = link.getAttribute('data-door-url') || link.getAttribute('href') || '';
      if (!url) return;
      if (window.TTLiveRight && typeof window.TTLiveRight.open === 'function') {
        window.TTLiveRight.open(url);
        return;
      }
      window.postMessage({ type: 'msb-live-right-door-open', url: url }, '*');
    });
  });
})();
</script>
<script src="./lib/jquery/jquery.js"></script>
<script src="./js/shamcey.js"></script>
</body>
</html>
