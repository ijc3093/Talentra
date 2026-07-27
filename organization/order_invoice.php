<?php
declare(strict_types=1);

/**
 * Seller order invoice / receipt view (OMS → Product # → Invoice).
 */
require_once __DIR__ . '/includes/session_org.php';
require_once __DIR__ . '/includes/org_context.php';
require_once __DIR__ . '/includes/org_manager_guard.php';
require_once __DIR__ . '/includes/org_sales.php';
require_once __DIR__ . '/includes/org_ecommerce.php';
require_once __DIR__ . '/../public_user/includes/org_shop.php';

org_require_manager();
org_require_commerce_seller();
org_ecommerce_ensure_schema($dbh);
org_shop_ensure_schema($dbh);

$orgId = (int)orgActiveOrgId();
$orderId = (int)($_GET['id'] ?? 0);
$embed = ((string)($_GET['embed'] ?? '') === '1');
$print = ((string)($_GET['print'] ?? '') === '1');

$order = org_sales_order($dbh, $orgId, $orderId);
if (!$order) {
    if ($embed) {
        http_response_code(404);
        echo 'Order not found.';
        exit;
    }
    header('Location: sales_management.php#orders');
    exit;
}

// Ensure a receipt row exists (receipt code on the invoice).
$receiptId = org_shop_issue_receipt($dbh, $orgId, $orderId);
$receiptCode = '';
try {
    $stR = $dbh->prepare('SELECT receipt_code FROM org_order_receipts WHERE id = :id LIMIT 1');
    $stR->execute([':id' => $receiptId]);
    $receiptCode = trim((string)($stR->fetchColumn() ?: ''));
} catch (Throwable $e) {
    $receiptCode = '';
}
if ($receiptCode === '') {
    $receiptCode = 'INV-' . $orgId . '-' . $orderId;
}

org_shop_ensure_product_unit_code(
    $dbh,
    $orderId,
    isset($order['product_unit_code']) ? (string)$order['product_unit_code'] : null
);
try {
    $stU = $dbh->prepare('SELECT product_unit_code FROM org_orders WHERE id = :id LIMIT 1');
    $stU->execute([':id' => $orderId]);
    $order['product_unit_code'] = trim((string)($stU->fetchColumn() ?: ''));
} catch (Throwable $e) {
    // ignore
}

$batchLines = org_shop_seller_order_batch($dbh, $orgId, $order);
$batchGroups = org_shop_group_seller_customer_orders($batchLines);
$batch = $batchGroups[0] ?? null;

$buyerName = trim((string)($batch['buyer_name'] ?? $order['buyer_name'] ?? ''));
if ($buyerName === '') {
    $buyerName = trim((string)($order['buyer_email'] ?? '')) ?: 'Guest';
}
$buyerEmail = trim((string)($batch['buyer_email'] ?? $order['buyer_email'] ?? ''));
$buyerPhone = trim((string)($batch['buyer_phone'] ?? $order['buyer_phone'] ?? ''));
$shipTo = trim((string)($batch['delivery_address'] ?? $order['delivery_address'] ?? ''));
$currency = (string)($order['currency'] ?? 'USD');
$status = strtolower((string)($batch['status'] ?? $order['status'] ?? 'pending'));
$createdRaw = (string)($order['created_at'] ?? '');
$dateLabel = $createdRaw !== '' ? date('M j, Y g:i A', strtotime($createdRaw) ?: time()) : '—';
$orderCode = trim((string)($order['order_code'] ?? ''));
$unitCode = trim((string)($order['product_unit_code'] ?? ''));
$sellerName = trim((string)($ORG['name'] ?? 'Seller'));
$sellerCode = trim((string)($ORG['org_code'] ?? ''));

$subtotalCents = 0;
$taxCents = 0;
$shipCents = 0;
$svcCents = 0;
$discountCents = 0;
$lines = $batchLines ?: [$order];
foreach ($lines as $line) {
    $lineTotal = (int)($line['total_cents'] ?? 0);
    $lineTax = (int)($line['tax_cents'] ?? 0);
    $lineShip = (int)($line['shipping_fee_cents'] ?? 0);
    $lineSvc = (int)($line['service_fee_cents'] ?? 0);
    $lineDisc = (int)($line['discount_cents'] ?? 0);
    $taxCents += $lineTax;
    $shipCents += $lineShip;
    $svcCents += $lineSvc;
    $discountCents += $lineDisc;
    $subtotalCents += max(0, $lineTotal - $lineTax - $lineShip - $lineSvc + $lineDisc);
}
$grandCents = (int)($batch['total_cents'] ?? $order['total_cents'] ?? 0);
if ($grandCents <= 0) {
    $grandCents = max(0, $subtotalCents - $discountCents + $taxCents + $shipCents + $svcCents);
}

$h = static function (string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
};
$money = static function (int $cents) use ($currency): string {
    return org_sales_money($cents, $currency);
};

$statusPaidLike = in_array($status, ['paid', 'shipped', 'delivered'], true);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Invoice · <?= $h($receiptCode) ?></title>
  <style>
    :root{
      --inv-bg:#f3f4f6;
      --inv-paper:#ffffff;
      --inv-text:#111827;
      --inv-muted:#6b7280;
      --inv-line:#e5e7eb;
      --inv-accent:#0f766e;
      --inv-soft:#f8fafc;
    }
    html.dark-auto{
      --inv-bg:#0f141a;
      --inv-paper:#171d24;
      --inv-text:#e8edf5;
      --inv-muted:#94a3b8;
      --inv-line:#334155;
      --inv-accent:#2dd4bf;
      --inv-soft:#1e2733;
    }
    *{box-sizing:border-box;}
    body{
      margin:0;
      font-family:ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif;
      background:var(--inv-bg);
      color:var(--inv-text);
      line-height:1.45;
    }
    .inv-wrap{
      max-width:520px;
      margin:0 auto;
      padding:<?= $embed ? '14px' : '28px 16px' ?>;
    }
    .inv-toolbar{
      display:flex;
      gap:8px;
      justify-content:flex-end;
      margin-bottom:12px;
    }
    .inv-toolbar a,.inv-toolbar button{
      appearance:none;
      border:1px solid var(--inv-line);
      background:var(--inv-paper);
      color:var(--inv-text);
      border-radius:6px;
      padding:7px 12px;
      font-size:12px;
      font-weight:700;
      text-decoration:none;
      cursor:pointer;
    }
    .inv-receipt{
      background:var(--inv-paper);
      border:1px solid var(--inv-line);
      border-radius:10px;
      box-shadow:0 10px 28px rgba(15,23,42,.08);
      padding:22px 20px 18px;
      position:relative;
      overflow:hidden;
    }
    .inv-receipt::before{
      content:"";
      position:absolute;
      left:0;right:0;top:0;
      height:4px;
      background:var(--inv-accent);
    }
    .inv-brand{
      text-align:center;
      margin-bottom:14px;
      padding-bottom:12px;
      border-bottom:1px dashed var(--inv-line);
    }
    .inv-brand .store{
      font-size:18px;
      font-weight:850;
      letter-spacing:-.02em;
      margin:0 0 2px;
    }
    .inv-brand .tag{
      margin:0;
      font-size:11px;
      font-weight:700;
      letter-spacing:.12em;
      text-transform:uppercase;
      color:var(--inv-muted);
    }
    .inv-brand .code{
      margin:8px 0 0;
      font-size:12px;
      font-weight:800;
      color:var(--inv-accent);
    }
    .inv-meta{
      display:grid;
      grid-template-columns:1fr 1fr;
      gap:10px;
      margin-bottom:14px;
      font-size:12px;
    }
    .inv-box{
      background:var(--inv-soft);
      border:1px solid var(--inv-line);
      border-radius:8px;
      padding:10px 12px;
    }
    .inv-box strong{
      display:block;
      margin-bottom:4px;
      font-size:11px;
      letter-spacing:.04em;
      text-transform:uppercase;
      color:var(--inv-muted);
    }
    .inv-box span,.inv-box div{color:var(--inv-text);white-space:pre-line;}
    .inv-items{width:100%;border-collapse:collapse;margin:4px 0 12px;font-size:13px;}
    .inv-items th{
      text-align:left;
      font-size:10px;
      letter-spacing:.06em;
      text-transform:uppercase;
      color:var(--inv-muted);
      border-bottom:1px solid var(--inv-line);
      padding:6px 0;
    }
    .inv-items td{
      padding:9px 0;
      border-bottom:1px dashed var(--inv-line);
      vertical-align:top;
    }
    .inv-items td.qty,.inv-items th.qty,
    .inv-items td.amt,.inv-items th.amt{text-align:right;white-space:nowrap;}
    .inv-items .pid{display:block;margin-top:2px;font-size:11px;color:var(--inv-muted);font-weight:700;}
    .inv-totals{margin-top:8px;font-size:13px;}
    .inv-tot-row{
      display:flex;
      justify-content:space-between;
      gap:12px;
      padding:5px 0;
      color:var(--inv-muted);
    }
    .inv-tot-row strong{color:var(--inv-text);font-weight:800;}
    .inv-tot-row.is-grand{
      margin-top:8px;
      padding-top:10px;
      border-top:2px solid var(--inv-text);
      font-size:15px;
      color:var(--inv-text);
      font-weight:850;
    }
    .inv-status{
      display:inline-flex;
      margin-top:10px;
      padding:4px 10px;
      border-radius:999px;
      font-size:11px;
      font-weight:850;
      text-transform:uppercase;
      letter-spacing:.04em;
      background:<?= $statusPaidLike ? 'rgba(15,118,110,.12)' : 'rgba(148,163,184,.18)' ?>;
      color:<?= $statusPaidLike ? 'var(--inv-accent)' : 'var(--inv-muted)' ?>;
    }
    .inv-foot{
      margin-top:16px;
      padding-top:12px;
      border-top:1px dashed var(--inv-line);
      text-align:center;
      font-size:12px;
      color:var(--inv-muted);
    }
    .inv-foot .thanks{
      margin:0 0 4px;
      font-size:14px;
      font-weight:800;
      color:var(--inv-text);
    }
    @media print{
      body{background:#fff;}
      .inv-toolbar{display:none !important;}
      .inv-wrap{padding:0;max-width:none;}
      .inv-receipt{box-shadow:none;border:0;border-radius:0;}
    }
  </style>
</head>
<body>
  <div class="inv-wrap">
    <?php if (!$embed || $print): ?>
      <div class="inv-toolbar">
        <button type="button" onclick="window.print()">Print receipt</button>
      </div>
    <?php else: ?>
      <div class="inv-toolbar">
        <a href="order_invoice.php?id=<?= (int)$orderId ?>&amp;print=1" target="_blank" rel="noopener">Print / save</a>
      </div>
    <?php endif; ?>

    <article class="inv-receipt" aria-label="Order invoice receipt">
      <header class="inv-brand">
        <p class="tag">Sales receipt</p>
        <h1 class="store"><?= $h($sellerName) ?></h1>
        <?php if ($sellerCode !== ''): ?>
          <p class="tag" style="letter-spacing:.04em;text-transform:none;font-weight:600;"><?= $h($sellerCode) ?></p>
        <?php endif; ?>
        <p class="code"><?= $h($receiptCode) ?></p>
      </header>

      <div class="inv-meta">
        <div class="inv-box">
          <strong>Bill to</strong>
          <div><?= $h($buyerName) ?></div>
          <?php if ($buyerEmail !== ''): ?><div><?= $h($buyerEmail) ?></div><?php endif; ?>
          <?php if ($buyerPhone !== ''): ?><div><?= $h($buyerPhone) ?></div><?php endif; ?>
        </div>
        <div class="inv-box">
          <strong>Invoice info</strong>
          <div>Date: <?= $h($dateLabel) ?></div>
          <?php if ($orderCode !== ''): ?><div>Order: <?= $h($orderCode) ?></div><?php endif; ?>
          <?php if ($unitCode !== ''): ?><div>Product ID: <?= $h($unitCode) ?></div><?php endif; ?>
          <span class="inv-status"><?= $h($status) ?></span>
        </div>
      </div>

      <?php if ($shipTo !== ''): ?>
        <div class="inv-box" style="margin-bottom:14px;">
          <strong>Ship to</strong>
          <div><?= $h($shipTo) ?></div>
        </div>
      <?php endif; ?>

      <table class="inv-items">
        <thead>
          <tr>
            <th>Item</th>
            <th class="qty">Qty</th>
            <th class="amt">Amount</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($lines as $line): ?>
            <?php
              $title = trim((string)($line['product_title'] ?? 'Product')) ?: 'Product';
              $qty = max(1, (int)($line['quantity'] ?? 1));
              $amt = (int)($line['total_cents'] ?? 0);
              $lineUnit = trim((string)($line['product_unit_code'] ?? ''));
              if ($lineUnit === '' && (int)($line['id'] ?? 0) > 0) {
                  $lineUnit = org_shop_product_unit_code_from_order_id((int)$line['id']);
              }
            ?>
            <tr>
              <td>
                <?= $h($title) ?>
                <?php if ($lineUnit !== ''): ?>
                  <span class="pid"><?= $h($lineUnit) ?></span>
                <?php endif; ?>
              </td>
              <td class="qty"><?= $qty ?></td>
              <td class="amt"><?= $h($money($amt)) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>

      <div class="inv-totals">
        <div class="inv-tot-row"><span>Subtotal</span><strong><?= $h($money($subtotalCents)) ?></strong></div>
        <?php if ($discountCents > 0): ?>
          <div class="inv-tot-row"><span>Discount</span><strong>-<?= $h($money($discountCents)) ?></strong></div>
        <?php endif; ?>
        <?php if ($shipCents > 0): ?>
          <div class="inv-tot-row"><span>Shipping</span><strong><?= $h($money($shipCents)) ?></strong></div>
        <?php endif; ?>
        <?php if ($taxCents > 0): ?>
          <div class="inv-tot-row"><span>Tax</span><strong><?= $h($money($taxCents)) ?></strong></div>
        <?php endif; ?>
        <?php if ($svcCents > 0): ?>
          <div class="inv-tot-row"><span>Service fee</span><strong><?= $h($money($svcCents)) ?></strong></div>
        <?php endif; ?>
        <div class="inv-tot-row is-grand"><span>Total</span><span><?= $h($money($grandCents)) ?></span></div>
      </div>

      <footer class="inv-foot">
        <p class="thanks">Thank you for your purchase</p>
        <p>This receipt is your invoice for this order.</p>
      </footer>
    </article>
  </div>
  <?php if ($print): ?>
  <script>window.addEventListener('load', function(){ setTimeout(function(){ window.print(); }, 120); });</script>
  <?php endif; ?>
</body>
</html>
