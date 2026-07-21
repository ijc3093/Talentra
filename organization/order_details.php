<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/session_org.php';
require_once __DIR__ . '/includes/org_context.php';
require_once __DIR__ . '/includes/org_manager_guard.php';
require_once __DIR__ . '/includes/org_sales.php';
require_once __DIR__ . '/includes/org_ecommerce.php';
require_once __DIR__ . '/../public_user/includes/org_shop.php';
require_once __DIR__ . '/../public_user/includes/commerce_messaging.php';
require_once __DIR__ . '/../public_user/includes/buyer_seller_relationship.php';

org_require_manager();

org_require_commerce_seller();
org_ecommerce_ensure_schema($dbh);
buyer_seller_rel_ensure_schema($dbh);

$orgId = (int)orgActiveOrgId();
$orderId = (int)($_GET['id'] ?? $_POST['order_id'] ?? 0);
$embed = ((string)($_GET['embed'] ?? '') === '1');
$download = ((string)($_GET['download'] ?? '') === '1');
$fulfillFlashOk = '';
$fulfillFlashErr = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['od_fulfill_action'])) {
    $postOrderId = (int)($_POST['order_id'] ?? 0);
    $newStatus = strtolower(trim((string)($_POST['status'] ?? '')));
    $sellerNotes = trim((string)($_POST['seller_notes'] ?? ''));
    $tracking = trim((string)($_POST['tracking_number'] ?? ''));
    $carrier = trim((string)($_POST['carrier'] ?? ''));
    $redirEmbed = ((string)($_POST['embed'] ?? '') === '1') || $embed;

    if ($postOrderId > 0 && org_ecommerce_update_fulfillment($dbh, $orgId, $postOrderId, $newStatus, $sellerNotes, $tracking, $carrier)) {
        $qs = 'id=' . $postOrderId . ($redirEmbed ? '&embed=1' : '');
        $_SESSION['od_fulfill_flash_ok'] = 'Carrier and tracking saved.';
        header('Location: order_details.php?' . $qs);
        exit;
    }
    $fulfillFlashErr = 'Could not update carrier / tracking.';
    $orderId = $postOrderId > 0 ? $postOrderId : $orderId;
    $embed = $redirEmbed;
}

if (!empty($_SESSION['od_fulfill_flash_ok'])) {
    $fulfillFlashOk = (string)$_SESSION['od_fulfill_flash_ok'];
    unset($_SESSION['od_fulfill_flash_ok']);
}

$order = org_sales_order($dbh, $orgId, $orderId);
if (!$order) {
    if ($embed || $download) {
        http_response_code(404);
        echo 'Order not found.';
        exit;
    }
    header('Location: orders.php');
    exit;
}

$buyerRel = null;
$buyerUserId = (int)($order['buyer_user_id'] ?? 0);
if ($buyerUserId > 0) {
    $buyerRel = buyer_seller_rel_for_seller($dbh, $orgId, $buyerUserId);
}

$batchLines = org_shop_seller_order_batch($dbh, $orgId, $order);
$batchGroups = org_shop_group_seller_customer_orders($batchLines);
$batch = $batchGroups[0] ?? null;

$orderNum = $batch ? (int)$batch['order_num'] : 1;
$quantityNum = $batch ? (int)$batch['quantity_num'] : max(1, (int)($order['quantity'] ?? 1));
$totalLabel = $batch
    ? (string)$batch['total_label']
    : org_sales_money((int)($order['total_cents'] ?? 0), (string)($order['currency'] ?? 'USD'));
$products = $batch['products'] ?? [[
    'title' => (string)($order['product_title'] ?? 'Product'),
    'qty' => max(1, (int)($order['quantity'] ?? 1)),
    'amount_cents' => (int)($order['total_cents'] ?? 0),
]];

$buyerName = $batch['buyer_name'] ?? trim((string)($order['buyer_name'] ?? ''));
if ($buyerName === '') {
    $buyerName = trim((string)($order['buyer_email'] ?? '')) ?: 'Guest';
}
$buyerEmail = trim((string)($batch['buyer_email'] ?? $order['buyer_email'] ?? ''));
$buyerPhone = trim((string)($batch['buyer_phone'] ?? $order['buyer_phone'] ?? ''));
$shipTo = trim((string)($batch['delivery_address'] ?? $order['delivery_address'] ?? ''));
$currency = (string)($order['currency'] ?? 'USD');
$status = (string)($batch['status'] ?? $order['status'] ?? 'pending');
$dateLabel = (string)($batch['date_label'] ?? ($order['created_at'] ?? ''));
$deliveryOption = str_replace('_', ' ', (string)($order['delivery_option'] ?? 'home_delivery'));
$payoutLabel = org_sales_money((int)($order['seller_payout_cents'] ?? 0), $currency);
$payoutStatus = (string)($order['payout_status'] ?? 'pending');
$sellerFeeCents = (int)($order['referral_fee_cents'] ?? 0)
    + (int)($order['fulfillment_fee_cents'] ?? 0)
    + (int)($order['platform_fee_cents'] ?? 0);
$sellerFeeLabel = org_sales_money($sellerFeeCents, $currency);
$buyerServiceFeeCents = max(0, (int)($order['service_fee_cents'] ?? 0));
if ($buyerServiceFeeCents <= 0 && function_exists('org_shop_buyer_service_fee_cents')) {
    $buyerServiceFeeCents = 0; // keep stored value; admin page can infer older rows
}
$buyerServiceFeeLabel = org_sales_money($buyerServiceFeeCents, $currency);
$carrier = trim((string)($order['carrier'] ?? ''));
$tracking = trim((string)($order['tracking_number'] ?? ''));
$buyerNotes = trim((string)($order['buyer_notes'] ?? ''));
$sellerNotes = trim((string)($order['seller_notes'] ?? ''));
$isCancelled = strtolower((string)($order['status'] ?? '')) === 'cancelled';
$messageUrl = $buyerUserId > 0 ? commerce_message_buyer_org_url((int)$order['id']) : '';

if ($download) {
    if (!function_exists('org_ecommerce_h')) {
        require_once __DIR__ . '/includes/org_ecommerce.php';
    }
    $h = static function (string $s): string {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    };
    $safeName = preg_replace('/[^a-zA-Z0-9_-]+/', '-', strtolower(trim($buyerName))) ?: 'customer';
    $fileName = 'order-' . $safeName . '-' . max(1, $orderId) . '.html';
    header('Content-Type: text/html; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    header('X-Content-Type-Options: nosniff');
    ?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Order details · <?= $h($buyerName) ?></title>
  <style>
    body{font-family:system-ui,-apple-system,Segoe UI,sans-serif;color:#111827;margin:32px;line-height:1.45;}
    h1{margin:0 0 6px;font-size:22px;}
    .muted{color:#6b7280;font-size:13px;margin:0 0 18px;}
    table{width:100%;border-collapse:collapse;margin:14px 0 22px;}
    th,td{border-bottom:1px solid #e5e7eb;padding:9px 8px;text-align:left;font-size:13px;vertical-align:top;}
    th{color:#6b7280;font-weight:700;font-size:11px;text-transform:uppercase;letter-spacing:.04em;}
    .total{font-weight:800;}
  </style>
</head>
<body>
  <h1>Order details</h1>
  <p class="muted"><?= $h($buyerName) ?> · <?= $h($dateLabel) ?> · <?= $h($status) ?></p>
  <table>
    <tr><th>Customer</th><td><?= $h($buyerName) ?></td></tr>
    <tr><th>Email</th><td><?= $buyerEmail !== '' ? $h($buyerEmail) : 'Not provided' ?></td></tr>
    <tr><th>Phone</th><td><?= $buyerPhone !== '' ? $h($buyerPhone) : 'Not provided' ?></td></tr>
    <tr><th>Address</th><td><?= $shipTo !== '' ? nl2br($h($shipTo)) : 'Not provided' ?></td></tr>
    <tr><th>Products</th><td><?= (int)$orderNum ?></td></tr>
    <tr><th>Units</th><td><?= (int)$quantityNum ?></td></tr>
    <tr><th>Total</th><td class="total"><?= $h($totalLabel) ?></td></tr>
    <tr><th>Fulfillment</th><td><?= $h(strtoupper((string)($order['fulfillment_method'] ?? 'fbm'))) ?> · <?= $h($deliveryOption) ?></td></tr>
  </table>
  <h2 style="font-size:16px;margin:0 0 8px;">Ordered products</h2>
  <table>
    <thead>
      <tr><th>Product</th><th>Qty</th><th>Amount</th><th>Status</th><th>Code</th></tr>
    </thead>
    <tbody>
      <?php foreach ($batchLines as $line): ?>
        <?php
          $lineQty = max(1, (int)($line['quantity'] ?? 1));
          $lineAmount = org_sales_money((int)($line['total_cents'] ?? 0), (string)($line['currency'] ?? $currency));
          $lineCode = trim((string)($line['order_code'] ?? ''));
          if ($lineCode === '') {
              $lineCode = '#' . (int)($line['id'] ?? 0);
          }
        ?>
        <tr>
          <td><?= $h((string)($line['product_title'] ?? 'Product')) ?></td>
          <td><?= $lineQty ?></td>
          <td><?= $h($lineAmount) ?></td>
          <td><?= $h((string)($line['status'] ?? '')) ?></td>
          <td><?= $h($lineCode) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</body>
</html>
    <?php
    exit;
}

$pageTitle = 'Order details';

if ($embed) {
    if (!function_exists('org_ecommerce_h')) {
        require_once __DIR__ . '/includes/org_ecommerce.php';
    }
    $h = static function (string $s): string {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    };
    ?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= $h($pageTitle) ?></title>
  <style>
    :root{
      --od-bg:#ffffff;
      --od-text:#111827;
      --od-muted:#6b7280;
      --od-line:rgba(15,23,42,.1);
      --od-soft:rgba(15,23,42,.04);
      --od-link:#2563eb;
      --od-accent:#0f766e;
      --od-danger:#b91c1c;
      --od-pill-bg:#ecfdf5;
      --od-pill-text:#065f46;
      --od-pill-pending-bg:#fff7ed;
      --od-pill-pending-text:#9a3412;
      --od-pill-cancel-bg:#fef2f2;
      --od-pill-cancel-text:#991b1b;
    }
    html.dark-auto{
      --od-bg:#171d24;
      --od-text:#e5e7eb;
      --od-muted:#94a3b8;
      --od-line:rgba(148,163,184,.18);
      --od-soft:rgba(148,163,184,.08);
      --od-link:#93c5fd;
      --od-accent:#5eead4;
      --od-danger:#fca5a5;
      --od-pill-bg:rgba(16,185,129,.16);
      --od-pill-text:#6ee7b7;
      --od-pill-pending-bg:rgba(249,115,22,.16);
      --od-pill-pending-text:#fdba74;
      --od-pill-cancel-bg:rgba(239,68,68,.16);
      --od-pill-cancel-text:#fca5a5;
    }
    *{box-sizing:border-box;}
    body.org-order-details-embed{
      margin:0;
      padding:0 0 28px;
      background:var(--od-bg);
      color:var(--od-text);
      font-family:"Segoe UI", system-ui, -apple-system, BlinkMacSystemFont, "Helvetica Neue", Arial, sans-serif;
      font-size:14px;
      line-height:1.45;
      -webkit-font-smoothing:antialiased;
    }
    .od-wrap{padding:16px 18px 0;}
    .od-hero{
      display:flex;
      align-items:flex-start;
      justify-content:space-between;
      gap:12px;
      margin-bottom:14px;
    }
    .od-hero h1{
      margin:0;
      font-size:18px;
      font-weight:800;
      letter-spacing:-.01em;
      line-height:1.25;
    }
    .od-hero p{
      margin:4px 0 0;
      font-size:12px;
      color:var(--od-muted);
      font-weight:600;
    }
    .od-status{
      display:inline-flex;
      align-items:center;
      padding:5px 10px;
      border-radius:999px;
      font-size:11px;
      font-weight:800;
      text-transform:capitalize;
      letter-spacing:.02em;
      white-space:nowrap;
      background:var(--od-pill-bg);
      color:var(--od-pill-text);
    }
    .od-status.is-pending,
    .od-status.is-confirmed{
      background:var(--od-pill-pending-bg);
      color:var(--od-pill-pending-text);
    }
    .od-status.is-cancelled{
      background:var(--od-pill-cancel-bg);
      color:var(--od-pill-cancel-text);
    }
    .od-stats{
      display:grid;
      grid-template-columns:repeat(3,minmax(0,1fr));
      gap:8px;
      margin-bottom:18px;
    }
    .od-stat{
      background:var(--od-soft);
      border:1px solid var(--od-line);
      border-radius:12px;
      padding:12px 10px;
      text-align:center;
      min-width:0;
    }
    .od-stat strong{
      display:block;
      font-size:18px;
      font-weight:800;
      line-height:1.15;
      letter-spacing:-.02em;
    }
    .od-stat span{
      display:block;
      margin-top:4px;
      font-size:11px;
      font-weight:700;
      color:var(--od-muted);
      text-transform:uppercase;
      letter-spacing:.04em;
    }
    .od-section{
      padding:16px 0;
      border-top:1px solid var(--od-line);
    }
    .od-section h2{
      margin:0 0 12px;
      font-size:13px;
      font-weight:800;
      text-transform:uppercase;
      letter-spacing:.04em;
      color:var(--od-muted);
    }
    .od-kv{
      display:grid;
      gap:10px;
    }
    .od-row{
      display:grid;
      grid-template-columns:88px minmax(0,1fr);
      gap:8px 12px;
      align-items:baseline;
    }
    .od-row dt{
      margin:0;
      font-size:12px;
      font-weight:700;
      color:var(--od-muted);
    }
    .od-row dd{
      margin:0;
      font-size:13px;
      font-weight:600;
      color:var(--od-text);
      word-break:break-word;
      min-width:0;
    }
    .od-row dd.od-row-pre{
      white-space:pre-line;
    }
    .od-row a{
      color:var(--od-link);
      text-decoration:none;
      font-weight:700;
    }
    .od-row a:hover{text-decoration:underline;}
    .od-muted{color:var(--od-muted);font-weight:600;}
    .od-item{
      display:flex;
      flex-direction:column;
      gap:8px;
      padding:12px 0;
      border-top:1px solid var(--od-line);
    }
    .od-item:first-of-type{border-top:0;padding-top:0;}
    .od-item:last-of-type{padding-bottom:0;}
    .od-item-top{
      display:flex;
      align-items:flex-start;
      justify-content:space-between;
      gap:12px;
    }
    .od-item-main{min-width:0;flex:1 1 auto;}
    .od-item-title{
      margin:0;
      font-size:14px;
      font-weight:800;
      line-height:1.3;
    }
    .od-item-meta{
      margin:4px 0 0;
      font-size:12px;
      color:var(--od-muted);
      font-weight:600;
    }
    .od-item-meta code{
      font-family:ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
      font-size:11px;
      font-weight:600;
    }
    .od-item-amt{
      font-size:14px;
      font-weight:800;
      white-space:nowrap;
      text-align:right;
      flex:0 0 auto;
    }
    .od-item-qty{
      display:flex;
      align-items:baseline;
      justify-content:space-between;
      gap:12px;
      font-size:12px;
      font-weight:700;
      color:var(--od-muted);
    }
    .od-item-qty strong{
      color:var(--od-text);
      font-size:13px;
      font-weight:800;
    }
    .od-total{
      margin-top:12px;
      padding-top:12px;
      border-top:1px solid var(--od-line);
      display:flex;
      justify-content:space-between;
      gap:12px;
      align-items:baseline;
      font-size:14px;
      font-weight:800;
    }
    .od-total span:last-child{color:var(--od-accent);}
    .od-note{
      margin:0;
      font-size:13px;
      font-weight:600;
      color:var(--od-text);
      white-space:pre-line;
    }
    .od-alert{
      margin:0 0 12px;
      padding:10px 12px;
      border-radius:10px;
      background:var(--od-pill-cancel-bg);
      color:var(--od-pill-cancel-text);
      font-size:12px;
      font-weight:700;
      line-height:1.4;
    }
    .od-actions{
      display:flex;
      flex-wrap:wrap;
      gap:8px;
      margin-top:14px;
    }
    .od-btn{
      display:inline-flex;
      align-items:center;
      justify-content:center;
      min-height:36px;
      padding:8px 14px;
      border-radius:10px;
      border:1px solid var(--od-line);
      background:var(--od-soft);
      color:var(--od-text);
      font:inherit;
      font-size:13px;
      font-weight:800;
      text-decoration:none;
      cursor:pointer;
    }
    .od-btn-primary{
      background:var(--od-accent);
      border-color:transparent;
      color:#fff;
    }
    html.dark-auto .od-btn-primary{color:#042f2e;}
    .od-flash{
      margin:0 0 12px;
      padding:10px 12px;
      border-radius:10px;
      font-size:12px;
      font-weight:700;
      line-height:1.4;
      background:#ecfdf5;
      color:#065f46;
    }
    .od-flash-err{background:#fef2f2;color:#991b1b;}
    .od-fulfill-form{margin-top:4px;}
    .od-fulfill-form .od-row{align-items:center;}
    .od-fulfill-form input[type="text"]{
      width:100%;
      min-height:34px;
      padding:6px 10px;
      border:1px solid var(--od-line);
      border-radius:8px;
      background:var(--od-soft);
      color:var(--od-text);
      font:inherit;
      font-size:13px;
      font-weight:600;
    }
    .od-fulfill-form .od-btn{width:100%;margin-top:8px;}
    .od-hint{margin:8px 0 0;font-size:11px;font-weight:600;color:var(--od-muted);}
  </style>
  <script>
    (function () {
      try {
        if (window.parent && window.parent.document &&
            window.parent.document.documentElement.classList.contains('dark-auto')) {
          document.documentElement.classList.add('dark-auto');
        }
      } catch (e) {}
    })();
  </script>
</head>
<body class="org-order-details-embed">
  <div class="od-wrap">
    <div class="od-hero">
      <div>
        <h1><?= $h($buyerName) ?></h1>
        <p><?= $h($dateLabel !== '' ? $dateLabel : 'Purchase date unavailable') ?></p>
      </div>
      <span class="od-status is-<?= $h(preg_replace('/[^a-z0-9_-]+/i', '', strtolower($status)) ?: 'pending') ?>"><?= $h($status) ?></span>
    </div>

    <div class="od-stats">
      <div class="od-stat">
        <strong><?= (int)$orderNum ?></strong>
        <span>Products</span>
      </div>
      <div class="od-stat">
        <strong><?= (int)$quantityNum ?></strong>
        <span>Units</span>
      </div>
      <div class="od-stat">
        <strong><?= $h($totalLabel) ?></strong>
        <span>Total</span>
      </div>
    </div>

    <section class="od-section">
      <h2>Customer</h2>
      <dl class="od-kv">
        <div class="od-row">
          <dt>Name</dt>
          <dd><?= $h($buyerName) ?></dd>
        </div>
        <div class="od-row">
          <dt>Email</dt>
          <dd><?php if ($buyerEmail !== ''): ?><a href="mailto:<?= $h($buyerEmail) ?>"><?= $h($buyerEmail) ?></a><?php else: ?><span class="od-muted">Not provided</span><?php endif; ?></dd>
        </div>
        <div class="od-row">
          <dt>Phone</dt>
          <dd><?php if ($buyerPhone !== ''): ?><a href="tel:<?= $h(preg_replace('/\s+/', '', $buyerPhone) ?: '') ?>"><?= $h($buyerPhone) ?></a><?php else: ?><span class="od-muted">Not provided</span><?php endif; ?></dd>
        </div>
        <div class="od-row">
          <dt>Address</dt>
          <dd class="<?= $shipTo !== '' ? 'od-row-pre' : '' ?>"><?= $shipTo !== '' ? $h($shipTo) : '<span class="od-muted">Not provided</span>' ?></dd>
        </div>
      </dl>
      <?php if ($messageUrl !== ''): ?>
        <div class="od-actions">
          <a class="od-btn od-btn-primary" href="<?= $h($messageUrl) ?>" target="_top">Message buyer</a>
        </div>
      <?php endif; ?>
    </section>

    <section class="od-section">
      <h2>Ordered products</h2>
      <?php foreach ($batchLines as $line): ?>
        <?php
          $lineQty = max(1, (int)($line['quantity'] ?? 1));
          $lineAmount = org_sales_money((int)($line['total_cents'] ?? 0), (string)($line['currency'] ?? $currency));
          $lineCode = trim((string)($line['order_code'] ?? ''));
          if ($lineCode === '') {
              $lineCode = '#' . (int)($line['id'] ?? 0);
          }
          $lineSku = trim((string)($line['sku'] ?? ''));
          $lineStatus = (string)($line['status'] ?? '');
        ?>
        <article class="od-item">
          <div class="od-item-top">
            <div class="od-item-main">
              <h3 class="od-item-title"><?= $h((string)($line['product_title'] ?? 'Product')) ?></h3>
              <p class="od-item-meta">
                <?= $h($lineStatus) ?>
                <?php if ($lineSku !== ''): ?> · SKU <?= $h($lineSku) ?><?php endif; ?>
                · <code><?= $h($lineCode) ?></code>
              </p>
            </div>
            <div class="od-item-amt"><?= $h($lineAmount) ?></div>
          </div>
          <div class="od-item-qty">
            <span>Qty</span>
            <strong><?= $lineQty ?></strong>
          </div>
        </article>
      <?php endforeach; ?>
      <div class="od-total">
        <span>Order total</span>
        <span><?= $h($totalLabel) ?></span>
      </div>
    </section>

    <section class="od-section">
      <h2>Fulfillment</h2>
      <?php if ($fulfillFlashOk !== ''): ?>
        <p class="od-flash"><?= $h($fulfillFlashOk) ?></p>
      <?php endif; ?>
      <?php if ($fulfillFlashErr !== ''): ?>
        <p class="od-flash od-flash-err"><?= $h($fulfillFlashErr) ?></p>
      <?php endif; ?>
      <dl class="od-kv">
        <div class="od-row">
          <dt>Method</dt>
          <dd><?= $h(strtoupper((string)($order['fulfillment_method'] ?? 'fbm'))) ?> · <?= $h($deliveryOption) ?></dd>
        </div>
      </dl>
      <form method="post" action="order_details.php?id=<?= (int)$orderId ?>&amp;embed=1" class="od-fulfill-form">
        <input type="hidden" name="od_fulfill_action" value="1">
        <input type="hidden" name="order_id" value="<?= (int)$orderId ?>">
        <input type="hidden" name="embed" value="1">
        <input type="hidden" name="status" value="<?= $h(strtolower((string)($order['status'] ?? 'shipped'))) ?>">
        <input type="hidden" name="seller_notes" value="<?= $h($sellerNotes) ?>">
        <dl class="od-kv">
          <div class="od-row">
            <dt>Carrier</dt>
            <dd><input type="text" name="carrier" value="<?= $h($carrier) ?>" placeholder="e.g. UPS, FedEx, USPS" autocomplete="off"></dd>
          </div>
          <div class="od-row">
            <dt>Tracking</dt>
            <dd><input type="text" name="tracking_number" value="<?= $h($tracking) ?>" placeholder="Tracking #" autocomplete="off"></dd>
          </div>
          <div class="od-row">
            <dt>Buyer service fee</dt>
            <dd><?= $buyerServiceFeeCents > 0 ? $h($buyerServiceFeeLabel) . ' <span class="od-muted">(paid to Admin)</span>' : '<span class="od-muted">$0.00</span>' ?></dd>
          </div>
          <div class="od-row">
            <dt>Seller fees</dt>
            <dd><?= $sellerFeeCents > 0 ? $h($sellerFeeLabel) . ' <span class="od-muted">(referral / platform)</span>' : '<span class="od-muted">Not set</span>' ?></dd>
          </div>
          <div class="od-row">
            <dt>Payout</dt>
            <dd><?= $h($payoutLabel) ?> · <?= $h($payoutStatus) ?></dd>
          </div>
        </dl>
        <button type="submit" class="od-btn od-btn-primary">Save carrier &amp; tracking</button>
        <p class="od-hint">Enter both to mark the order shipping and notify the customer.</p>
      </form>
    </section>

    <section class="od-section">
      <h2>Notes</h2>
      <?php if ($isCancelled): ?>
        <p class="od-alert">
          Cancelled<?= stripos($buyerNotes . $sellerNotes, 'Cancelled by customer') !== false ? ' by the customer' : '' ?>.
          Status is already updated in your order inbox.
        </p>
      <?php endif; ?>
      <dl class="od-kv">
        <div class="od-row">
          <dt>Buyer</dt>
          <dd><?= $buyerNotes !== '' ? $h($buyerNotes) : '<span class="od-muted">None</span>' ?></dd>
        </div>
        <div class="od-row">
          <dt>Seller</dt>
          <dd><?= $sellerNotes !== '' ? $h($sellerNotes) : '<span class="od-muted">None</span>' ?></dd>
        </div>
      </dl>
    </section>

    <?php if ($buyerRel): ?>
      <section class="od-section">
        <h2>Buyer needs</h2>
        <dl class="od-kv">
          <div class="od-row">
            <dt>Relationship</dt>
            <dd><?= $h(buyer_seller_rel_type_label((string)($buyerRel['relationship_type'] ?? ''))) ?></dd>
          </div>
          <?php if (trim((string)($buyerRel['interests'] ?? '')) !== ''): ?>
            <div class="od-row">
              <dt>Interests</dt>
              <dd><?= $h((string)$buyerRel['interests']) ?></dd>
            </div>
          <?php endif; ?>
          <div class="od-row">
            <dt>Contact</dt>
            <dd><?= $h(buyer_seller_rel_contact_label((string)($buyerRel['preferred_contact'] ?? ''))) ?></dd>
          </div>
          <?php if (trim((string)($buyerRel['delivery_preference'] ?? '')) !== ''): ?>
            <div class="od-row">
              <dt>Delivery</dt>
              <dd><?= $h((string)$buyerRel['delivery_preference']) ?></dd>
            </div>
          <?php endif; ?>
          <?php if (trim((string)($buyerRel['budget_range'] ?? '')) !== ''): ?>
            <div class="od-row">
              <dt>Budget</dt>
              <dd><?= $h((string)$buyerRel['budget_range']) ?></dd>
            </div>
          <?php endif; ?>
          <?php if (trim((string)($buyerRel['needs_note'] ?? '')) !== ''): ?>
            <div class="od-row">
              <dt>Note</dt>
              <dd><?= $h((string)$buyerRel['needs_note']) ?></dd>
            </div>
          <?php endif; ?>
        </dl>
      </section>
    <?php elseif ($buyerUserId > 0): ?>
      <section class="od-section">
        <h2>Buyer needs</h2>
        <p class="od-note od-muted">This buyer has not shared shopping preferences with your organization yet.</p>
      </section>
    <?php endif; ?>
  </div>
</body>
</html>
    <?php
    exit;
}

require_once __DIR__ . '/includes/org_page_shell.php';
org_page_shell_open($pageTitle, '<link rel="stylesheet" href="css/commerce-hub.css?v=14">');
org_page_body_open('commerce-page');
?>
  <div class="mg-b-20">
    <a href="orders.php" class="tx-12">&larr; Orders</a>
    <h4 class="mg-b-0">Customer purchase · <?= (int)$orderNum ?> product<?= $orderNum === 1 ? '' : 's' ?></h4>
    <p class="tx-color-03">
      Product # = how many products from this brand. Quantity # = total units (same day purchase).
    </p>
  </div>

  <div class="card shadow-base mg-b-20">
    <div class="card-header"><h6 class="card-title tx-14 mg-b-0">Customer order summary</h6></div>
    <div class="card-body pd-0 table-responsive">
      <table class="table mg-b-0">
        <thead>
          <tr>
            <th class="text-center">Product #</th>
            <th class="text-center">Quantity #</th>
            <th>Customer</th>
            <th>Customer contact</th>
            <th>Customer address</th>
            <th>Status</th>
            <th>Date</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td class="text-center"><strong style="font-size:18px;"><?= (int)$orderNum ?></strong><div class="tx-11 tx-color-03">products</div></td>
            <td class="text-center"><strong style="font-size:18px;"><?= (int)$quantityNum ?></strong><div class="tx-11 tx-color-03">units</div></td>
            <td><?= org_ecommerce_h($buyerName) ?></td>
            <td class="tx-12">
              <?php if ($buyerEmail !== ''): ?>
                <div>Email: <a href="mailto:<?= org_ecommerce_h($buyerEmail) ?>"><?= org_ecommerce_h($buyerEmail) ?></a></div>
              <?php endif; ?>
              <?php if ($buyerPhone !== ''): ?>
                <div>Phone: <a href="tel:<?= org_ecommerce_h(preg_replace('/\s+/', '', $buyerPhone)) ?>"><?= org_ecommerce_h($buyerPhone) ?></a></div>
              <?php endif; ?>
              <?php if ($buyerEmail === '' && $buyerPhone === ''): ?>
                <span class="tx-color-03">Not provided</span>
              <?php endif; ?>
            </td>
            <td class="tx-12" style="white-space:pre-line;max-width:260px;">
              <?= $shipTo !== '' ? org_ecommerce_h($shipTo) : '<span class="tx-color-03">Not provided</span>' ?>
            </td>
            <td><span class="badge <?= org_sales_status_badge($status) ?>"><?= org_ecommerce_h($status) ?></span></td>
            <td class="tx-12"><?= org_ecommerce_h($dateLabel) ?></td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>

  <div class="row row-sm">
    <div class="col-lg-8">
      <div class="card shadow-base mg-b-20">
        <div class="card-header"><h6 class="card-title tx-14 mg-b-0">Ordered products</h6></div>
        <div class="card-body pd-0 table-responsive">
          <table class="table mg-b-0">
            <thead>
              <tr>
                <th>Product</th>
                <th class="text-center">Qty</th>
                <th>Amount</th>
                <th>Line status</th>
                <th>Code</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($batchLines as $line): ?>
                <?php
                  $lineQty = max(1, (int)($line['quantity'] ?? 1));
                  $lineAmount = org_sales_money((int)($line['total_cents'] ?? 0), (string)($line['currency'] ?? $currency));
                  $lineCode = trim((string)($line['order_code'] ?? ''));
                  if ($lineCode === '') {
                      $lineCode = '#' . (int)($line['id'] ?? 0);
                  }
                ?>
                <tr>
                  <td>
                    <?= org_ecommerce_h((string)($line['product_title'] ?? 'Product')) ?>
                    <?php if (!empty($line['sku'])): ?>
                      <div class="tx-12 tx-color-03">SKU <?= org_ecommerce_h((string)$line['sku']) ?></div>
                    <?php endif; ?>
                  </td>
                  <td class="text-center"><strong><?= $lineQty ?></strong></td>
                  <td><?= org_ecommerce_h($lineAmount) ?></td>
                  <td><span class="badge badge-light"><?= org_ecommerce_h((string)($line['status'] ?? '')) ?></span></td>
                  <td class="tx-11"><code><?= org_ecommerce_h($lineCode) ?></code></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
      <div class="card shadow-base">
        <div class="card-header"><h6 class="card-title tx-14 mg-b-0">Delivery and notes</h6></div>
        <div class="card-body">
          <p><strong>Fulfillment:</strong> <?= org_ecommerce_h(strtoupper((string)($order['fulfillment_method'] ?? 'fbm'))) ?> · <?= org_ecommerce_h($deliveryOption) ?></p>
          <p><strong>Carrier:</strong> <?= org_ecommerce_h($carrier) ?> <strong class="mg-l-10">Tracking:</strong> <?= org_ecommerce_h($tracking) ?></p>
          <p><strong>Customer address:</strong><br><?= $shipTo !== '' ? nl2br(org_ecommerce_h($shipTo)) : '<span class="tx-color-03">Not provided</span>' ?></p>
          <p><strong>Buyer note:</strong><br><?= nl2br(org_ecommerce_h($buyerNotes)) ?></p>
          <?php if ($isCancelled): ?>
            <p class="tx-12 text-danger mg-b-0"><strong>Cancelled</strong> — this order was cancelled<?= stripos($buyerNotes . $sellerNotes, 'Cancelled by customer') !== false ? ' by the customer' : '' ?>. Status is already updated in your order inbox.</p>
          <?php endif; ?>
          <p><strong>Seller note:</strong><br><?= nl2br(org_ecommerce_h($sellerNotes)) ?></p>
        </div>
      </div>
    </div>
    <div class="col-lg-4">
      <div class="commerce-panel">
        <div class="commerce-panel-head">
          <h2>Customer contact</h2>
          <span class="badge <?= org_sales_status_badge($status) ?>"><?= org_ecommerce_h($status) ?></span>
        </div>
        <p><strong>Name:</strong> <?= org_ecommerce_h($buyerName) ?></p>
        <p><strong>Email:</strong>
          <?php if ($buyerEmail !== ''): ?>
            <a href="mailto:<?= org_ecommerce_h($buyerEmail) ?>"><?= org_ecommerce_h($buyerEmail) ?></a>
          <?php else: ?>
            <span class="tx-color-03">Not provided</span>
          <?php endif; ?>
        </p>
        <p><strong>Phone:</strong>
          <?php if ($buyerPhone !== ''): ?>
            <a href="tel:<?= org_ecommerce_h(preg_replace('/\s+/', '', $buyerPhone)) ?>"><?= org_ecommerce_h($buyerPhone) ?></a>
          <?php else: ?>
            <span class="tx-color-03">Not provided</span>
          <?php endif; ?>
        </p>
        <p><strong>Product #:</strong> <?= (int)$orderNum ?> product<?= $orderNum === 1 ? '' : 's' ?></p>
        <p><strong>Quantity #:</strong> <?= (int)$quantityNum ?> unit<?= $quantityNum === 1 ? '' : 's' ?></p>
        <p><strong>Order total:</strong> <?= org_ecommerce_h($totalLabel) ?></p>
        <p><strong>Payout:</strong> <?= org_ecommerce_h($payoutLabel) ?> · <?= org_ecommerce_h($payoutStatus) ?></p>
        <?php if ($messageUrl !== ''): ?>
          <a href="<?= org_ecommerce_h($messageUrl) ?>" class="btn btn-sm btn-primary mg-b-10">Message buyer</a>
        <?php endif; ?>
        <a href="orders.php?status=<?= org_ecommerce_h((string)$order['status']) ?>" class="btn btn-sm btn-outline-secondary">Update in order inbox</a>
      </div>
      <?php if ($buyerRel): ?>
        <div class="commerce-panel mg-t-15">
          <div class="commerce-panel-head"><h2>Buyer needs</h2></div>
          <p class="tx-12 tx-color-03 mg-b-10">Shared by the customer so you can meet their preferences.</p>
          <p><strong>Relationship:</strong> <?= org_ecommerce_h(buyer_seller_rel_type_label((string)($buyerRel['relationship_type'] ?? ''))) ?></p>
          <?php if (trim((string)($buyerRel['interests'] ?? '')) !== ''): ?>
            <p><strong>Interests:</strong> <?= org_ecommerce_h((string)$buyerRel['interests']) ?></p>
          <?php endif; ?>
          <p><strong>Preferred contact:</strong> <?= org_ecommerce_h(buyer_seller_rel_contact_label((string)($buyerRel['preferred_contact'] ?? ''))) ?></p>
          <?php if (trim((string)($buyerRel['delivery_preference'] ?? '')) !== ''): ?>
            <p><strong>Delivery:</strong> <?= org_ecommerce_h((string)$buyerRel['delivery_preference']) ?></p>
          <?php endif; ?>
          <?php if (trim((string)($buyerRel['budget_range'] ?? '')) !== ''): ?>
            <p><strong>Budget:</strong> <?= org_ecommerce_h((string)$buyerRel['budget_range']) ?></p>
          <?php endif; ?>
          <?php if (trim((string)($buyerRel['needs_note'] ?? '')) !== ''): ?>
            <p><strong>Note:</strong><br><?= nl2br(org_ecommerce_h((string)$buyerRel['needs_note'])) ?></p>
          <?php endif; ?>
        </div>
      <?php elseif ($buyerUserId > 0): ?>
        <div class="commerce-panel mg-t-15">
          <div class="commerce-panel-head"><h2>Buyer needs</h2></div>
          <p class="tx-12 tx-color-03">This buyer has not shared shopping preferences with your organization yet.</p>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php org_page_shell_close(); ?>
