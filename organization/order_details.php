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

$adminOversight = function_exists('admin_linked_is_org_admin_oversight') && admin_linked_is_org_admin_oversight();
if (!$adminOversight) {
    org_require_commerce_seller();
}

org_ecommerce_ensure_schema($dbh);
buyer_seller_rel_ensure_schema($dbh);

$orgId = (int)orgActiveOrgId();
$orderId = (int)($_GET['id'] ?? $_POST['order_id'] ?? 0);
$codeParam = trim((string)($_GET['code'] ?? ''));
$embed = ((string)($_GET['embed'] ?? '') === '1');
$download = ((string)($_GET['download'] ?? '') === '1');
$fromSales = ((string)($_GET['from'] ?? '') === 'sales');
$fulfillFlashOk = '';
$fulfillFlashErr = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['od_fulfill_action'])) {
    if ($adminOversight) {
        $fulfillFlashErr = 'Fulfillment updates are disabled in admin oversight view.';
        $orderId = (int)($_POST['order_id'] ?? $orderId);
        $embed = ((string)($_POST['embed'] ?? '') === '1') || $embed;
    } else {
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
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['od_note_action']) && empty($adminOversight)) {
    $postOrderId = (int)($_POST['order_id'] ?? 0);
    $note = trim((string)($_POST['seller_notes'] ?? ''));
    $redirEmbed = ((string)($_POST['embed'] ?? '') === '1') || $embed;
    $fromSalesPost = ((string)($_POST['from'] ?? '') === 'sales') || $fromSales;
    if ($postOrderId > 0) {
        try {
            $stNote = $dbh->prepare('UPDATE org_orders SET seller_notes = :n, updated_at = NOW() WHERE id = :id AND org_id = :org LIMIT 1');
            $stNote->execute([
                ':n' => $note !== '' ? $note : null,
                ':id' => $postOrderId,
                ':org' => $orgId,
            ]);
            $_SESSION['od_fulfill_flash_ok'] = 'Note saved.';
            $qs = 'id=' . $postOrderId . ($redirEmbed ? '&embed=1' : '') . ($fromSalesPost ? '&from=sales' : '');
            header('Location: order_details.php?' . $qs);
            exit;
        } catch (Throwable $e) {
            $fulfillFlashErr = 'Could not save note.';
            $orderId = $postOrderId;
        }
    }
}

if (!empty($_SESSION['od_fulfill_flash_ok'])) {
    $fulfillFlashOk = (string)$_SESSION['od_fulfill_flash_ok'];
    unset($_SESSION['od_fulfill_flash_ok']);
}

$order = null;
if ($orderId <= 0 && $codeParam !== '') {
    try {
        $stCode = $dbh->prepare('
            SELECT id FROM org_orders
            WHERE org_id = :org AND order_code = :code
            ORDER BY id DESC LIMIT 1
        ');
        $stCode->execute([':org' => $orgId, ':code' => $codeParam]);
        $orderId = (int)($stCode->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        $orderId = 0;
    }
    if ($orderId <= 0 && preg_match('/^ORD-0*([0-9]+)$/i', $codeParam, $m)) {
        $orderId = (int)$m[1];
    }
}
if ($orderId > 0) {
    if ($adminOversight) {
        // Load any marketplace order, then bind org to its seller.
        try {
            $stAny = $dbh->prepare('SELECT * FROM org_orders WHERE id = :id LIMIT 1');
            $stAny->execute([':id' => $orderId]);
            $order = $stAny->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable $e) {
            $order = null;
        }
        if ($order) {
            $orderOrgId = (int)($order['org_id'] ?? 0);
            if ($orderOrgId > 0) {
                $_SESSION['org_active_org_id'] = $orderOrgId;
                $orgId = $orderOrgId;
            }
            // Prefer the org-scoped loader so related fields match seller tools.
            $scoped = org_sales_order($dbh, $orgId, $orderId);
            if ($scoped) {
                $order = $scoped;
            }
        }
    } else {
        $order = org_sales_order($dbh, $orgId, $orderId);
    }
}

if (!$order) {
    if ($adminOversight) {
        header('Location: ../admin/Orders.php');
        exit;
    }
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
$checkoutCode = trim((string)($order['order_code'] ?? ''));
$detailLines = [];
foreach ($batchLines as $line) {
    $lc = trim((string)($line['order_code'] ?? ''));
    if ($checkoutCode !== '' && $lc === $checkoutCode) {
        $detailLines[] = $line;
    } elseif ($checkoutCode === '' && (int)($line['id'] ?? 0) === $orderId) {
        $detailLines[] = $line;
    }
}
if (!$detailLines) {
    $detailLines = [$order];
}
$displayOrderCode = $checkoutCode !== ''
    ? $checkoutCode
    : ('ORD-' . str_pad((string)$orderId, 6, '0', STR_PAD_LEFT));

$batchGroups = org_shop_group_seller_customer_orders($detailLines);
$batch = $batchGroups[0] ?? null;

$currency = (string)($order['currency'] ?? 'USD');
$orderNum = count($detailLines);
$quantityNum = 0;
$subtotalCents = 0;
$shippingCents = 0;
$taxCents = 0;
$discountCents = 0;
$totalCents = 0;
foreach ($detailLines as $line) {
    $qty = max(1, (int)($line['quantity'] ?? 1));
    $quantityNum += $qty;
    $subtotalCents += (int)($line['unit_price_cents'] ?? 0) * $qty;
    $shippingCents += (int)($line['shipping_fee_cents'] ?? 0);
    $taxCents += (int)($line['tax_cents'] ?? 0);
    $discountCents += (int)($line['discount_cents'] ?? 0);
    $totalCents += (int)($line['total_cents'] ?? 0);
}
if ($totalCents <= 0) {
    $totalCents = (int)($order['total_cents'] ?? 0);
}
$totalLabel = org_sales_money($totalCents, $currency);
$subtotalLabel = org_sales_money($subtotalCents > 0 ? $subtotalCents : max(0, $totalCents - $shippingCents - $taxCents), $currency);
$shippingLabel = $shippingCents <= 0 ? 'Free' : org_sales_money($shippingCents, $currency);
$taxLabel = org_sales_money($taxCents, $currency);
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
$shipTo = trim((string)($order['delivery_address'] ?? $batch['delivery_address'] ?? ''));
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

$fmtWhen = static function (?string $raw): string {
    $raw = trim((string)$raw);
    if ($raw === '') {
        return '';
    }
    $ts = strtotime($raw);
    return $ts ? date('M j, Y, g:i A', $ts) : $raw;
};
$placedTs = strtotime((string)($order['created_at'] ?? '')) ?: 0;
$placedFull = $placedTs ? date('M j, Y \a\t g:i A', $placedTs) : (string)$dateLabel;
$placedStep = $fmtWhen((string)($order['created_at'] ?? ''));
$paidStep = $fmtWhen((string)($order['paid_at'] ?? ''));
$shippedStep = $fmtWhen((string)($order['shipped_at'] ?? ''));
$deliveredRaw = (string)($order['delivered_at'] ?? '');
if ($deliveredRaw === '' && strtolower((string)($order['status'] ?? '')) === 'delivered') {
    $deliveredRaw = (string)($order['updated_at'] ?? '');
}
$deliveredStep = $fmtWhen($deliveredRaw);
$statusRaw = strtolower((string)($order['status'] ?? $status));
$statusBucket = match ($statusRaw) {
    'delivered' => 'delivered',
    'shipped' => 'shipped',
    'cancelled', 'canceled' => 'cancelled',
    default => 'processing',
};
$statusLab = match ($statusBucket) {
    'delivered' => 'Delivered',
    'shipped' => 'Shipped',
    'cancelled' => 'Cancelled',
    default => 'Processing',
};
$stepDone = [
    'placed' => true,
    'processing' => in_array($statusBucket, ['processing', 'shipped', 'delivered'], true) || $paidStep !== '',
    'shipped' => in_array($statusBucket, ['shipped', 'delivered'], true),
    'delivered' => $statusBucket === 'delivered',
];
if ($statusBucket === 'cancelled') {
    $stepDone = ['placed' => true, 'processing' => $paidStep !== '', 'shipped' => false, 'delivered' => false];
}
$pm = strtolower(trim((string)($order['payment_method'] ?? '')));
$pref = trim((string)($order['payment_reference'] ?? ''));
$payLast4 = preg_match('/(\d{4})\s*$/', $pref, $pmatch) ? $pmatch[1] : '';
if (str_contains($pm, 'visa')) {
    $payBrand = 'VISA';
} elseif (str_contains($pm, 'master')) {
    $payBrand = 'Mastercard';
} elseif (str_contains($pm, 'paypal')) {
    $payBrand = 'PayPal';
} elseif ($pm !== '') {
    $payBrand = ucfirst($pm);
} else {
    $payBrand = 'Card';
}
$payStatus = in_array($statusRaw, ['paid', 'shipped', 'delivered'], true) ? 'Paid'
    : ($statusBucket === 'cancelled' ? 'Cancelled' : 'Unpaid');
$channelLabel = str_contains(strtolower((string)($order['order_type'] ?? '')), 'market') ? 'Marketplace' : 'Direct Store';
$fm = strtolower((string)($order['fulfillment_method'] ?? 'fbm'));
$fulfillLabel = $fm === 'fba' ? 'Platform fulfilled' : 'Seller Fulfilled';
$deliveryMethod = $carrier !== '' ? $carrier : ucwords($deliveryOption);
$coverUrl = static function (?string $path): string {
    $path = trim((string)$path);
    if ($path === '') {
        return '';
    }
    if (preg_match('#^https?://#i', $path) || strpos($path, '/') === 0) {
        return $path;
    }
    return '../' . ltrim($path, '/');
};
$backHref = $fromSales ? 'sales_management.php#orders' : 'orders.php';
$invoiceHref = 'order_invoice.php?id=' . $orderId;

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
  <p class="muted"><?= $h($displayOrderCode) ?> · <?= $h($buyerName) ?> · <?= $h($placedFull) ?> · <?= $h($statusLab) ?></p>
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
      <?php foreach ($detailLines as $line): ?>
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
        <h1>Order Details</h1>
        <p><strong><?= $h($displayOrderCode) ?></strong> · Placed on <?= $h($placedFull !== '' ? $placedFull : 'date unavailable') ?></p>
      </div>
      <span class="od-status is-<?= $h($statusBucket === 'cancelled' ? 'cancelled' : ($statusBucket === 'delivered' ? 'delivered' : ($statusBucket === 'shipped' ? 'confirmed' : 'pending'))) ?>"><?= $h($statusLab) ?></span>
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
      <?php foreach ($detailLines as $line): ?>
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
org_page_shell_open(
    $pageTitle,
    '<link rel="stylesheet" href="css/commerce-hub.css?v=14">'
    . '<link rel="stylesheet" href="css/sales-azia.css?v=6">'
);
org_page_body_open('commerce-page');
$h = static function (string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
};
$fromQs = $fromSales ? '&from=sales' : '';
?>
<style>
  .od-page{color:#0f172a;}
  .od-page .od-back{display:inline-flex;align-items:center;gap:6px;font-size:13px;font-weight:700;color:#2563eb;text-decoration:none;margin-bottom:8px;}
  .od-page .od-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:14px;}
  .od-page .od-head h1{margin:0;font-size:26px;font-weight:800;letter-spacing:-.02em;display:inline-flex;align-items:center;gap:10px;}
  .od-page .od-code{margin:6px 0 0;font-size:14px;font-weight:800;}
  .od-page .od-placed{margin:2px 0 0;font-size:13px;color:#64748b;font-weight:600;}
  .od-page .od-badge{display:inline-flex;align-items:center;padding:4px 10px;border-radius:999px;font-size:12px;font-weight:800;}
  .od-page .od-badge.delivered{background:#dcfce7;color:#15803d;}
  .od-page .od-badge.shipped{background:#f3e8ff;color:#6d28d9;}
  .od-page .od-badge.processing{background:#ffedd5;color:#c2410c;}
  .od-page .od-badge.cancelled{background:#fee2e2;color:#b91c1c;}
  .od-page .od-head-actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap;}
  .od-page .od-btn{height:32px;padding:0 12px;border-radius:8px;border:1px solid #cbd5e1;background:#fff;font-size:12px;font-weight:700;display:inline-flex;align-items:center;gap:6px;color:#0f172a;text-decoration:none;cursor:pointer;}
  .od-page .od-steps{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px;background:#fff;border:1px solid #eef2f7;border-radius:12px;padding:14px 16px;margin-bottom:14px;}
  .od-page .od-step{position:relative;padding-left:4px;}
  .od-page .od-step strong{display:block;font-size:13px;font-weight:800;}
  .od-page .od-step span{display:block;font-size:11px;color:#64748b;margin-top:2px;}
  .od-page .od-dot{width:18px;height:18px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:11px;margin-bottom:6px;background:#e2e8f0;color:#64748b;}
  .od-page .od-step.is-done .od-dot{background:#16a34a;color:#fff;}
  .od-page .od-grid{display:grid;grid-template-columns:minmax(0,1.4fr) minmax(280px,.8fr);gap:14px;align-items:start;}
  .od-page .od-card{background:#fff;border:1px solid #eef2f7;border-radius:12px;padding:16px;margin-bottom:14px;}
  .od-page .od-card h2{margin:0 0 12px;font-size:15px;font-weight:800;}
  .od-page .od-item{display:flex;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid #eef2f7;}
  .od-page .od-item:last-of-type{border-bottom:0;}
  .od-page .od-thumb{width:48px;height:48px;border-radius:10px;background:#f1f5f9;overflow:hidden;display:flex;align-items:center;justify-content:center;color:#94a3b8;flex:0 0 auto;}
  .od-page .od-thumb img{width:100%;height:100%;object-fit:cover;}
  .od-page .od-item-main{flex:1 1 auto;min-width:0;}
  .od-page .od-item-main strong{display:block;font-size:14px;}
  .od-page .od-item-main span{display:block;font-size:12px;color:#64748b;margin-top:2px;}
  .od-page .od-sum{display:flex;justify-content:space-between;gap:12px;font-size:13px;padding:4px 0;color:#475569;}
  .od-page .od-sum.is-total{font-weight:800;color:#0f172a;border-top:1px solid #eef2f7;margin-top:8px;padding-top:10px;font-size:15px;}
  .od-page .od-tl{display:flex;flex-direction:column;gap:14px;}
  .od-page .od-tl-item{padding-left:18px;border-left:2px solid #bbf7d0;position:relative;}
  .od-page .od-tl-item:before{content:"";width:10px;height:10px;border-radius:50%;background:#16a34a;position:absolute;left:-6px;top:4px;}
  .od-page .od-tl-item strong{display:block;font-size:13px;}
  .od-page .od-tl-item span,.od-page .od-tl-item p{display:block;font-size:12px;color:#64748b;margin:2px 0 0;}
  .od-page .od-track{margin-top:8px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:8px 10px;font-size:12px;}
  .od-page .od-kv{display:grid;grid-template-columns:140px minmax(0,1fr);gap:8px 10px;font-size:13px;align-items:start;}
  .od-page .od-kv dt{margin:0;color:#64748b;font-weight:700;}
  .od-page .od-kv dd{margin:0;font-weight:600;word-break:break-word;}
  .od-page .od-copy{border:0;background:none;color:#2563eb;cursor:pointer;font-weight:800;padding:0 0 0 6px;}
  html.dark-auto .od-page{color:var(--msb-palette-text,#e2e8f0);}
  html.dark-auto .od-page .od-card,html.dark-auto .od-page .od-steps{background:var(--msb-palette-bg,#171d24);border-color:rgba(148,163,184,.22);}
  @media (max-width:980px){.od-page .od-grid,.od-page .od-steps{grid-template-columns:1fr;}}
  @media print{.sh-header,.sh-sideleft-menu,.od-head-actions,.od-back{display:none !important;}}
</style>
<div class="od-page">
  <a class="od-back" href="<?= $h($backHref) ?>">&larr; Back to Orders</a>
  <div class="od-head">
    <div>
      <h1>
        Order Details
        <span class="od-badge <?= $h($statusBucket) ?>"><?= $h($statusLab) ?></span>
      </h1>
      <p class="od-code" id="odOrderCode"><?= $h($displayOrderCode) ?></p>
      <p class="od-placed">Placed on <?= $h($placedFull !== '' ? $placedFull : 'date unavailable') ?></p>
    </div>
    <div class="od-head-actions">
      <a class="od-btn" href="<?= $h($invoiceHref) ?>" target="_blank" rel="noopener"><i class="fa fa-print"></i> Print</a>
      <details>
        <summary class="od-btn">More Actions</summary>
        <div class="od-card" style="position:absolute;right:24px;z-index:8;min-width:200px;margin-top:6px;">
          <a class="od-btn" href="<?= $h($invoiceHref) ?>" style="width:100%;margin-bottom:6px;">Invoice</a>
          <?php if ($messageUrl !== ''): ?>
            <a class="od-btn" href="<?= $h($messageUrl) ?>" style="width:100%;margin-bottom:6px;">Message buyer</a>
          <?php endif; ?>
          <a class="od-btn" href="<?= $h($backHref) ?>" style="width:100%;">Back to inbox</a>
        </div>
      </details>
    </div>
  </div>

  <div class="od-steps">
    <div class="od-step <?= !empty($stepDone['placed']) ? 'is-done' : '' ?>">
      <div class="od-dot"><?= !empty($stepDone['placed']) ? '✓' : '1' ?></div>
      <strong>Order Placed</strong>
      <span><?= $h($placedStep !== '' ? $placedStep : '—') ?></span>
    </div>
    <div class="od-step <?= !empty($stepDone['processing']) ? 'is-done' : '' ?>">
      <div class="od-dot"><?= !empty($stepDone['processing']) ? '✓' : '2' ?></div>
      <strong>Processing</strong>
      <span><?= $h($paidStep !== '' ? $paidStep : ($stepDone['processing'] ? $placedStep : 'Pending')) ?></span>
    </div>
    <div class="od-step <?= !empty($stepDone['shipped']) ? 'is-done' : '' ?>">
      <div class="od-dot"><?= !empty($stepDone['shipped']) ? '✓' : '3' ?></div>
      <strong>Shipped</strong>
      <span><?= $h($shippedStep !== '' ? $shippedStep : ($stepDone['shipped'] ? 'Shipped' : 'Pending')) ?></span>
    </div>
    <div class="od-step <?= !empty($stepDone['delivered']) ? 'is-done' : '' ?>">
      <div class="od-dot"><?= !empty($stepDone['delivered']) ? '✓' : '4' ?></div>
      <strong>Delivered</strong>
      <span><?= $h($deliveredStep !== '' ? $deliveredStep : ($stepDone['delivered'] ? 'Delivered' : 'Pending')) ?></span>
    </div>
  </div>

  <?php if ($fulfillFlashOk !== ''): ?><div class="alert alert-success"><?= $h($fulfillFlashOk) ?></div><?php endif; ?>
  <?php if ($fulfillFlashErr !== ''): ?><div class="alert alert-danger"><?= $h($fulfillFlashErr) ?></div><?php endif; ?>

  <div class="od-grid">
    <div>
      <div class="od-card">
        <h2>Order Items</h2>
        <?php foreach ($detailLines as $line):
          $lineQty = max(1, (int)($line['quantity'] ?? 1));
          $lineAmount = org_sales_money((int)($line['total_cents'] ?? 0), (string)($line['currency'] ?? $currency));
          $cover = $coverUrl(isset($line['product_cover']) ? (string)$line['product_cover'] : '');
          $attr = trim((string)($line['sku'] ?? ''));
        ?>
          <div class="od-item">
            <div class="od-thumb">
              <?php if ($cover !== ''): ?><img src="<?= $h($cover) ?>" alt=""><?php else: ?><i class="fa fa-cube"></i><?php endif; ?>
            </div>
            <div class="od-item-main">
              <strong><?= $h((string)($line['product_title'] ?? 'Product')) ?></strong>
              <span>x<?= $lineQty ?><?= $attr !== '' ? ' · ' . $h($attr) : '' ?></span>
            </div>
            <strong><?= $h($lineAmount) ?></strong>
          </div>
        <?php endforeach; ?>
        <div class="od-sum"><span>Item Subtotal</span><span><?= $h($subtotalLabel) ?></span></div>
        <div class="od-sum"><span>Shipping</span><span><?= $h($shippingLabel) ?></span></div>
        <div class="od-sum"><span>Tax</span><span><?= $h($taxLabel) ?></span></div>
        <div class="od-sum is-total"><span>Order Total</span><span><?= $h($totalLabel) ?></span></div>
      </div>

      <div class="od-card">
        <h2>Order Timeline</h2>
        <div class="od-tl">
          <div class="od-tl-item">
            <strong>Order Placed</strong>
            <span><?= $h($placedStep !== '' ? $placedStep : '—') ?></span>
            <p>The order has been placed.</p>
          </div>
          <?php if ($paidStep !== '' || !empty($stepDone['processing'])): ?>
            <div class="od-tl-item">
              <strong>Payment Confirmed</strong>
              <span><?= $h($paidStep !== '' ? $paidStep : $placedStep) ?></span>
              <p>Payment has been successfully authorized.</p>
            </div>
            <div class="od-tl-item">
              <strong>Processing</strong>
              <span><?= $h($paidStep !== '' ? $paidStep : $placedStep) ?></span>
              <p>The order is being prepared.</p>
            </div>
          <?php endif; ?>
          <?php if (!empty($stepDone['shipped'])): ?>
            <div class="od-tl-item">
              <strong>Shipped</strong>
              <span><?= $h($shippedStep !== '' ? $shippedStep : '—') ?></span>
              <p>Your order has been shipped<?= $carrier !== '' ? ' via ' . $h($carrier) : '' ?>.</p>
              <?php if ($tracking !== ''): ?>
                <div class="od-track">
                  Tracking Number: <strong><?= $h($tracking) ?></strong>
                  <?php if ($carrier !== ''): ?>
                    · <span><?= $h($carrier) ?></span>
                  <?php endif; ?>
                </div>
              <?php endif; ?>
            </div>
          <?php endif; ?>
          <?php if (!empty($stepDone['delivered'])): ?>
            <div class="od-tl-item">
              <strong>Delivered</strong>
              <span><?= $h($deliveredStep !== '' ? $deliveredStep : '—') ?></span>
              <p>The order has been delivered to the customer.</p>
            </div>
          <?php endif; ?>
          <?php if ($isCancelled): ?>
            <div class="od-tl-item">
              <strong>Cancelled</strong>
              <p>This order was cancelled<?= stripos($buyerNotes . $sellerNotes, 'Cancelled by customer') !== false ? ' by the customer' : '' ?>.</p>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div>
      <div class="od-card">
        <h2>Customer Information</h2>
        <dl class="od-kv">
          <dt>Name</dt>
          <dd>
            <?= $h($buyerName) ?>
            <?php if ($buyerUserId > 0): ?>
              <a href="crm.php?q=<?= $h(rawurlencode($buyerName)) ?>" style="margin-left:8px;font-size:12px;">View Profile</a>
            <?php endif; ?>
          </dd>
          <dt>Email</dt>
          <dd><?= $buyerEmail !== '' ? '<a href="mailto:' . $h($buyerEmail) . '">' . $h($buyerEmail) . '</a>' : 'Not provided' ?></dd>
          <dt>Phone</dt>
          <dd><?= $buyerPhone !== '' ? '<a href="tel:' . $h(preg_replace('/\s+/', '', $buyerPhone) ?: '') . '">' . $h($buyerPhone) . '</a>' : 'Not provided' ?></dd>
          <dt>Shipping Address</dt>
          <dd>
            <?php if ($statusBucket === 'delivered'): ?><span class="od-badge delivered" style="margin-bottom:6px;">Delivered</span><br><?php endif; ?>
            <?= $shipTo !== '' ? nl2br($h($shipTo)) : 'Not provided' ?>
          </dd>
        </dl>
      </div>

      <div class="od-card">
        <h2>Order Summary</h2>
        <dl class="od-kv">
          <dt>Order ID</dt>
          <dd>
            <strong id="odSummaryCode"><?= $h($displayOrderCode) ?></strong>
            <button type="button" class="od-copy" id="odCopyCode" title="Copy order ID">copy</button>
          </dd>
          <dt>Order Date</dt>
          <dd><?= $h($placedFull !== '' ? $placedFull : '—') ?></dd>
          <dt>Sales Channel</dt>
          <dd><?= $h($channelLabel) ?></dd>
          <dt>Payment Method</dt>
          <dd><?= $h($payBrand) ?><?= $payLast4 !== '' ? ' · **** ' . $h($payLast4) : '' ?></dd>
          <dt>Payment Status</dt>
          <dd><span class="od-badge <?= $payStatus === 'Paid' ? 'delivered' : ($payStatus === 'Cancelled' ? 'cancelled' : 'processing') ?>"><?= $h($payStatus) ?></span></dd>
          <dt>Fulfillment Method</dt>
          <dd><?= $h($fulfillLabel) ?></dd>
          <dt>Delivery Method</dt>
          <dd><?= $h($deliveryMethod) ?></dd>
          <dt>Delivery Date</dt>
          <dd><?= $h($deliveredStep !== '' ? $deliveredStep : '—') ?></dd>
        </dl>
      </div>

      <div class="od-card">
        <h2>Notes</h2>
        <?php if ($sellerNotes === '' && $buyerNotes === ''): ?>
          <p class="od-placed" style="margin-bottom:10px;">No notes added yet.</p>
        <?php else: ?>
          <?php if ($buyerNotes !== ''): ?><p><strong>Buyer:</strong> <?= nl2br($h($buyerNotes)) ?></p><?php endif; ?>
          <?php if ($sellerNotes !== ''): ?><p><strong>Seller:</strong> <?= nl2br($h($sellerNotes)) ?></p><?php endif; ?>
        <?php endif; ?>
        <form method="post" action="order_details.php?id=<?= (int)$orderId . $h($fromQs) ?>">
          <input type="hidden" name="od_note_action" value="1">
          <input type="hidden" name="order_id" value="<?= (int)$orderId ?>">
          <?php if ($fromSales): ?><input type="hidden" name="from" value="sales"><?php endif; ?>
          <textarea name="seller_notes" class="form-control" rows="3" placeholder="Add an internal note…"><?= $h($sellerNotes) ?></textarea>
          <button type="submit" class="od-btn" style="margin-top:8px;"><i class="fa fa-pencil"></i> <?= $sellerNotes !== '' ? 'Update Note' : 'Add Note' ?></button>
        </form>
      </div>
    </div>
  </div>
</div>
<script>
(function () {
  var btn = document.getElementById('odCopyCode');
  if (!btn) return;
  btn.addEventListener('click', function () {
    var text = (document.getElementById('odSummaryCode') || {}).textContent || '';
    if (!text) return;
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text.trim());
    }
    btn.textContent = 'copied';
    setTimeout(function () { btn.textContent = 'copy'; }, 1200);
  });
})();
</script>
</div>
<?php org_page_shell_close(); ?>

