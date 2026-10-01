<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/session_user.php';
requireUserLogin();

require_once __DIR__ . '/controller.php';
require_once __DIR__ . '/includes/org_shop.php';
require_once __DIR__ . '/includes/commerce_messaging.php';
require_once __DIR__ . '/includes/theme_prefs.php';

$controller = new Controller();
$dbh = $controller->pdo();
$meId = (int)($_SESSION['user_id'] ?? $_SESSION['id'] ?? $_SESSION['userid'] ?? 0);
$orderId = (int)($_GET['order_id'] ?? 0);
$embed = (string)($_GET['embed'] ?? '') === '1';
$order = org_shop_get_buyer_order($dbh, $meId, $orderId);

if (!function_exists('h')) {
    function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}

function order_detail_fmt_placed(?string $dt): string
{
    if (!$dt) {
        return '—';
    }
    $t = strtotime($dt);
    return $t ? date('M j, Y \a\t g:i A', $t) : '—';
}

function order_detail_fmt_short(?string $dt): string
{
    if (!$dt) {
        return '';
    }
    $t = strtotime($dt);
    return $t ? date('M j', $t) : '';
}

function order_detail_fmt_delivered(?string $dt): string
{
    if (!$dt) {
        return '';
    }
    $t = strtotime($dt);
    return $t ? date('D, M j, Y', $t) : '';
}

function order_detail_delivery_headline(string $status, ?string $deliveredAt, ?string $shippedAt): string
{
    $status = strtolower(trim($status));
    if ($status === 'delivered') {
        $when = order_detail_fmt_delivered($deliveredAt);
        return $when !== ''
            ? 'Your item is now delivered — ' . $when
            : 'Your item is now delivered';
    }
    if ($status === 'shipped') {
        $when = order_detail_fmt_delivered($shippedAt);
        return $when !== '' ? 'Shipped on ' . $when . ' — on the way' : 'Shipped — on the way';
    }
    if ($status === 'paid') {
        return 'Paid — preparing shipment';
    }
    if ($status === 'cancelled') {
        return 'Order cancelled';
    }
    return 'Order in progress';
}

function order_detail_step_done(string $status, string $step): bool
{
    $status = strtolower(trim($status));
    if ($step === 'paid') {
        return in_array($status, ['paid', 'shipped', 'delivered'], true);
    }
    if ($step === 'tracking') {
        return in_array($status, ['shipped', 'delivered'], true);
    }
    if ($step === 'delivered') {
        return $status === 'delivered';
    }
    return false;
}

function order_detail_track_url(string $carrier, string $tracking): string
{
    $tracking = trim($tracking);
    if ($tracking === '') {
        return '';
    }
    $carrier = strtolower(trim($carrier));
    if (str_contains($carrier, 'ups')) {
        return 'https://www.ups.com/track?tracknum=' . rawurlencode($tracking);
    }
    if (str_contains($carrier, 'usps') || str_contains($carrier, 'postal')) {
        return 'https://tools.usps.com/go/TrackConfirmAction?tLabels=' . rawurlencode($tracking);
    }
    if (str_contains($carrier, 'fedex')) {
        return 'https://www.fedex.com/fedextrack/?trknbr=' . rawurlencode($tracking);
    }
    $q = trim($carrier . ' ' . $tracking);
    return 'https://www.google.com/search?q=' . rawurlencode('track package ' . $q);
}

function order_detail_return_window(?string $deliveredAt): ?string
{
    if (!$deliveredAt) {
        return null;
    }
    $t = strtotime($deliveredAt);
    if (!$t) {
        return null;
    }
    $closes = strtotime('+30 days', $t);
    if ($closes <= time()) {
        return 'Return window closed on ' . date('M j, Y', $closes) . '.';
    }
    return 'Return window closes on ' . date('M j, Y', $closes) . '.';
}

function order_detail_fmt_paid_short(?string $dt): string
{
    if (!$dt) {
        return '';
    }
    $t = strtotime($dt);
    return $t ? date('M j \a\t g:i A', $t) : '';
}

/** @return list<string> */
function order_detail_shipping_lines(string $buyerName, string $address): array
{
    $lines = [];
    $name = trim($buyerName);
    if ($name !== '') {
        $lines[] = $name;
    }
    $address = trim($address);
    if ($address !== '') {
        foreach (preg_split('/\r\n|\r|\n/', $address) as $line) {
            $line = trim($line);
            if ($line !== '') {
                $lines[] = $line;
            }
        }
    }
    return $lines;
}

/** @return array{label:string,icon:string} */
function order_detail_payment_brand(array $order): array
{
    if (trim((string)($order['stripe_payment_intent_id'] ?? '')) !== ''
        || trim((string)($order['stripe_checkout_session_id'] ?? '')) !== '') {
        return ['label' => 'Card', 'icon' => 'card'];
    }
    $status = strtolower(trim((string)($order['status'] ?? '')));
    if (in_array($status, ['paid', 'shipped', 'delivered'], true)) {
        return ['label' => 'Manual payment', 'icon' => 'manual'];
    }
    return ['label' => 'Pending', 'icon' => 'pending'];
}

$notFound = !$order;
$productId = $notFound ? 0 : (int)($order['product_id'] ?? 0);
$status = $notFound ? '' : (string)($order['status'] ?? 'pending');
$qty = $notFound ? 1 : max(1, (int)($order['quantity'] ?? 1));
$total = $notFound ? '' : org_shop_format_price((int)($order['total_cents'] ?? 0), (string)($order['currency'] ?? 'USD'));
$unit = $notFound ? '' : org_shop_format_price((int)($order['unit_price_cents'] ?? 0), (string)($order['currency'] ?? 'USD'));
$cover = $notFound ? '' : org_shop_cover_url((string)($order['cover_image_path'] ?? ''));
$seller = $notFound ? '' : (trim((string)($order['seller_name'] ?? '')) ?: 'Shop');
$sellerAddress = $notFound ? '' : trim((string)($order['seller_address'] ?? ''));
$sellerEmail = $notFound ? '' : trim((string)($order['seller_email'] ?? ''));
$sellerPhone = $notFound ? '' : trim((string)($order['seller_phone'] ?? ''));
$publisherId = $notFound ? 0 : (int)($order['publisher_user_id'] ?? 0);
$detailLabel = $notFound ? '' : trim((string)($order['category'] ?? ''));
if ($detailLabel === '' && !$notFound) {
    $detailLabel = trim((string)($order['product_title'] ?? 'Item'));
}
$canReturn = !$notFound && in_array($status, ['paid', 'shipped', 'delivered'], true);
$canReview = !$notFound && $status === 'delivered';
$canCancel = !$notFound && org_shop_buyer_order_is_cancellable($order ?: []);
$tracking = $notFound ? '' : trim((string)($order['tracking_number'] ?? ''));
$carrier = $notFound ? '' : trim((string)($order['carrier'] ?? ''));
$createdAt = $notFound ? '' : (string)($order['created_at'] ?? '');
$shippedAt = $notFound ? '' : (string)($order['shipped_at'] ?? '');
$deliveredAt = $notFound ? '' : (string)($order['delivered_at'] ?? '');
// Harden cancel: if tracking exists with carrier, treat as shipped for UI.
if ($canCancel && $tracking !== '' && $carrier !== '') {
    $canCancel = false;
}
if ($canCancel && $shippedAt !== '') {
    $canCancel = false;
}
$deliveryHeadline = $notFound ? '' : order_detail_delivery_headline($status, $deliveredAt, $shippedAt);
$isDelivered = !$notFound && strtolower($status) === 'delivered';
$isCancelled = !$notFound && strtolower($status) === 'cancelled';
$paymentIncomplete = !$notFound && in_array(strtolower($status), ['pending', 'confirmed'], true);
$paymentProgress = !$notFound && function_exists('org_shop_order_payment_progress')
    ? org_shop_order_payment_progress($order)
    : ['due_cents' => 0, 'paid_cents' => 0, 'shortfall_cents' => 0, 'is_incomplete' => $paymentIncomplete, 'currency' => 'USD'];
$paymentIncompleteMsg = '';
if ($paymentIncomplete && function_exists('org_shop_order_incomplete_payment_buyer_message')) {
    $paymentIncompleteMsg = org_shop_order_incomplete_payment_buyer_message($order);
} elseif ($paymentIncomplete) {
    $paymentIncompleteMsg = 'Payment is incomplete. The seller cannot start shipping until this order is fully paid.';
}
$sellerPaymentRequest = '';
if ($paymentIncomplete) {
    $sellerNotesRaw = (string)($order['seller_notes'] ?? '');
    if (preg_match('/Seller payment request:\s*(.+)$/im', $sellerNotesRaw, $mReq)) {
        $sellerPaymentRequest = trim((string)$mReq[1]);
        // Prefer the last request if multiple.
        if (preg_match_all('/Seller payment request:\s*(.+)$/im', $sellerNotesRaw, $mAll) && !empty($mAll[1])) {
            $sellerPaymentRequest = trim((string)end($mAll[1]));
        }
    }
}
$shortfallCentsUi = (int)($paymentProgress['shortfall_cents'] ?? 0);
$shortfallLabelUi = $shortfallCentsUi > 0
    ? org_shop_format_price($shortfallCentsUi, (string)($paymentProgress['currency'] ?? 'USD'))
    : '';
$paidLabelUi = (int)($paymentProgress['paid_cents'] ?? 0) > 0
    ? org_shop_format_price((int)$paymentProgress['paid_cents'], (string)($paymentProgress['currency'] ?? 'USD'))
    : '';
$dueLabelUi = (int)($paymentProgress['due_cents'] ?? 0) > 0
    ? org_shop_format_price((int)$paymentProgress['due_cents'], (string)($paymentProgress['currency'] ?? 'USD'))
    : ($total ?? '');
$orderCodeUi = !$notFound ? trim((string)($order['order_code'] ?? '')) : '';
$cancelMeta = $isCancelled
    ? org_shop_order_cancel_meta(
        (string)($order['buyer_notes'] ?? ''),
        (string)($order['seller_notes'] ?? '')
    )
    : ['by' => '', 'reason' => ''];
$cancelWhenRaw = $isCancelled
    ? (string)($order['updated_at'] ?? $order['created_at'] ?? '')
    : '';
$cancelWhenLabel = '';
if ($cancelWhenRaw !== '') {
    $cts = strtotime($cancelWhenRaw);
    $cancelWhenLabel = $cts ? date('M j, Y', $cts) : '';
}
$cancelBySeller = $isCancelled && (string)($cancelMeta['by'] ?? '') === 'Seller';
$cancelNotice = '';
if ($isCancelled) {
    if ($cancelBySeller) {
        $cancelNotice = $cancelWhenLabel !== ''
            ? ('The seller cancelled this order on ' . $cancelWhenLabel . '.')
            : 'The seller cancelled this order.';
    } else {
        $cancelNotice = $cancelWhenLabel !== ''
            ? ('You cancelled this order on ' . $cancelWhenLabel . '.')
            : 'You cancelled this order.';
    }
    $reason = trim((string)($cancelMeta['reason'] ?? ''));
    if ($reason !== '' && strcasecmp($reason, 'Cancelled') !== 0) {
        $cancelNotice .= ' Reason: ' . $reason . '.';
    }
}
$trackUrl = $tracking !== '' ? order_detail_track_url($carrier, $tracking) : '';
$returnWindow = $notFound ? null : order_detail_return_window($deliveredAt !== '' ? $deliveredAt : null);
$contactUrl = $publisherId > 0
    ? commerce_message_seller_url($publisherId, (int)($order['product_id'] ?? 0), (string)($order['order_code'] ?? ''))
    : 'messages.php';
$itemLabel = $qty === 1 ? '1 item' : $qty . ' items';
$buyerAddress = $notFound ? '' : trim((string)($order['delivery_address'] ?? ''));
$buyerName = $notFound ? '' : trim((string)($order['buyer_name'] ?? ''));
$shippingLines = $notFound ? [] : order_detail_shipping_lines($buyerName, $buyerAddress);
$currency = $notFound ? 'USD' : (string)($order['currency'] ?? 'USD');
$subtotalCents = $notFound ? 0 : max(0, ((int)($order['unit_price_cents'] ?? 0) * $qty) - (int)($order['discount_cents'] ?? 0));
$taxCents = $notFound ? 0 : max(0, (int)($order['tax_cents'] ?? 0));
$shippingCents = $notFound ? 0 : max(0, (int)($order['shipping_fee_cents'] ?? 0));
$serviceFeeCents = $notFound ? 0 : max(0, (int)($order['service_fee_cents'] ?? 0));
$totalCents = $notFound ? 0 : (int)($order['total_cents'] ?? 0);
if ($serviceFeeCents <= 0 && function_exists('org_shop_buyer_service_fee_cents') && $totalCents > ($subtotalCents + $shippingCents + $taxCents)) {
    // Older rows may lack the column; infer only when leftover matches the fixed fee.
    $leftover = max(0, $totalCents - $subtotalCents - $shippingCents - $taxCents);
    if ($leftover === org_shop_buyer_service_fee_cents()) {
        $serviceFeeCents = $leftover;
    }
}
if ($taxCents <= 0 && $totalCents > ($subtotalCents + $shippingCents + $serviceFeeCents)) {
    $taxCents = max(0, $totalCents - $subtotalCents - $shippingCents - $serviceFeeCents);
}
if ($isCancelled) {
    $subtotalCents = 0;
    $taxCents = 0;
    $shippingCents = 0;
    $serviceFeeCents = 0;
    $totalCents = 0;
}
$subtotal = $notFound ? '' : org_shop_format_price($subtotalCents, $currency);
$tax = $notFound ? '' : org_shop_format_price($taxCents, $currency);
$serviceFee = $notFound ? '' : org_shop_format_price($serviceFeeCents, $currency);
$shippingPrice = $shippingCents > 0 ? org_shop_format_price($shippingCents, $currency) : ($isCancelled ? org_shop_format_price(0, $currency) : 'Free');
$shippingIsFree = $shippingCents <= 0;
$total = $notFound ? '' : org_shop_format_price($totalCents, $currency);
$paymentBrand = $notFound ? ['label' => '', 'icon' => 'pending'] : order_detail_payment_brand($order);
if ($isCancelled) {
    $paymentBrand = ['label' => 'No charge', 'icon' => 'manual'];
}
$paidAt = $notFound ? '' : (string)($order['paid_at'] ?? '');
$paymentWhen = $paidAt !== '' ? $paidAt : (in_array(strtolower($status), ['paid', 'shipped', 'delivered'], true) ? $createdAt : '');
$paymentDateShort = $isCancelled ? '' : order_detail_fmt_paid_short($paymentWhen);
$receiptCode = $notFound ? '' : trim((string)($order['receipt_code'] ?? ''));
$itemCountLabel = $qty === 1 ? '1 item' : $qty . ' items';
?>
<!doctype html>
<html <?= app_html_lang_attrs() ?>>
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Order details</title>
  <?php theme_prefs_print_head_bootstrap($dbh, $meId); ?>
  <link href="./lib/font-awesome/css/font-awesome.css" rel="stylesheet">
  <link href="./lib/Ionicons/css/ionicons.css" rel="stylesheet">
  <link rel="stylesheet" href="./css/shop-page.css?v=7">
  <style>
    body.order-detail-door{
      margin:0;
      background:var(--shop-card-bg,var(--msb-palette-bg,#fff));
      color:var(--shop-text,var(--msb-palette-text,inherit));
      font-family:inherit;
      overflow:hidden;
    }
    .od-shell{
      display:flex;flex-direction:column;height:100vh;max-height:100vh;
      box-sizing:border-box;
    }
    .od-top{
      flex-shrink:0;padding:14px 16px 0;
      border-bottom:1px solid var(--shop-border,var(--msb-palette-border,rgba(15,23,42,.08)));
      background:var(--shop-card-bg,var(--msb-palette-bg,#fff));
    }
    .od-head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:12px;}
    .od-title{font-size:17px;font-weight:800;margin:0;color:var(--shop-text,var(--msb-palette-text,inherit));}
    .od-back{
      font-size:13px;font-weight:800;color:var(--shop-link,var(--msb-palette-link,#2563eb));
      text-decoration:none;white-space:nowrap;flex-shrink:0;
    }
    .od-back:hover{text-decoration:underline;}
    .od-close{
      border:1px solid var(--shop-border,var(--msb-palette-border,rgba(177,188,206,.45)));
      background:var(--shop-btn-outline-bg,transparent);
      color:var(--shop-btn-outline-text,var(--shop-text,inherit));
      width:34px;height:34px;border-radius:50%;cursor:pointer;
      display:inline-flex;align-items:center;justify-content:center;flex-shrink:0;
    }
    .od-tabs{display:flex;gap:0;margin:0 -16px;padding:0 16px;}
    .od-tab{
      flex:1;border:none;background:transparent;cursor:pointer;
      font-size:13px;font-weight:700;padding:10px 6px 12px;
      color:var(--shop-text-muted,var(--msb-palette-text-muted,#6b7280));
      border-bottom:2px solid transparent;margin-bottom:-1px;
    }
    .od-tab.is-active{
      color:var(--shop-link,var(--msb-palette-link,#2563eb));
      border-bottom-color:var(--shop-link,var(--msb-palette-link,#2563eb));
    }
    .od-viewport{flex:1;min-height:0;overflow:hidden;position:relative;}
    .od-track{
      display:flex;width:300%;height:100%;
      transition:transform .28s ease;
    }
    .od-shell[data-screen="1"] .od-track{transform:translateX(-33.333333%);}
    .od-shell[data-screen="2"] .od-track{transform:translateX(-66.666667%);}
    .od-screen{
      width:33.333333%;flex-shrink:0;height:100%;overflow-y:auto;
      -webkit-overflow-scrolling:touch;padding:0 16px 24px;box-sizing:border-box;
    }
    .od-block{
      display:grid;grid-template-columns:minmax(72px,92px) 1fr;
      gap:8px 14px;padding:18px 0;
      border-bottom:1px solid var(--shop-border,var(--msb-palette-border,rgba(15,23,42,.08)));
    }
    .od-block:last-child{border-bottom:none;}
    .od-block-label{
      grid-column:1;grid-row:1;
      font-size:14px;font-weight:800;line-height:1.35;margin:0;
      color:var(--shop-text,var(--msb-palette-text,inherit));
    }
    .od-block-body{grid-column:2;grid-row:1;min-width:0;}
    .od-kv{display:grid;grid-template-columns:minmax(88px,1fr) 1fr;gap:6px 12px;font-size:13px;line-height:1.45;}
    .od-kv dt{margin:0;font-weight:600;color:var(--shop-text-muted,var(--msb-palette-text-muted,#6b7280));}
    .od-kv dd{margin:0;color:var(--shop-text,var(--msb-palette-text,inherit));}
    .od-kv dd.od-kv-total{font-weight:800;}
    .od-kv a{color:var(--shop-link,var(--msb-palette-link,#2563eb));text-decoration:underline;}
    .od-pay-section{padding:20px 0;border-bottom:1px solid var(--shop-border,var(--msb-palette-border,rgba(15,23,42,.08)));}
    .od-pay-section:last-child{border-bottom:none;padding-bottom:8px;}
    .od-pay-heading{font-size:15px;font-weight:800;margin:0 0 12px;line-height:1.35;color:var(--shop-text,var(--msb-palette-text,inherit));}
    .od-pay-address{font-size:13px;line-height:1.55;color:var(--shop-text,var(--msb-palette-text,inherit));}
    .od-pay-method{display:flex;align-items:flex-start;justify-content:space-between;gap:14px;margin-bottom:14px;}
    .od-pay-method-left{display:flex;align-items:center;gap:10px;min-width:0;}
    .od-pay-method-icon{
      width:38px;height:26px;border:1px solid var(--shop-border,rgba(15,23,42,.18));
      border-radius:4px;display:inline-flex;align-items:center;justify-content:center;
      font-size:14px;color:var(--shop-text,var(--msb-palette-text,inherit));flex-shrink:0;
      background:var(--shop-card-bg,#fff);
    }
    .od-pay-method-label{font-size:14px;font-weight:600;line-height:1.3;}
    .od-pay-method-right{text-align:right;flex-shrink:0;}
    .od-pay-method-amt{font-size:14px;font-weight:700;line-height:1.3;}
    .od-pay-method-date{font-size:12px;color:var(--shop-text-muted,#6b7280);margin-top:3px;line-height:1.3;}
    .od-pay-breakdown{
      background:var(--shop-card-raised,rgba(15,23,42,.045));
      border-radius:8px;padding:12px 14px;
    }
    .od-pay-line{display:flex;align-items:baseline;justify-content:space-between;gap:12px;font-size:13px;line-height:1.45;padding:3px 0;}
    .od-pay-line.is-total{
      font-weight:800;padding-top:10px;margin-top:8px;
      border-top:1px solid var(--shop-border,rgba(15,23,42,.1));
    }
    .od-pay-free{color:#15803d;font-weight:600;}
    .od-pay-footnote{font-size:11px;color:var(--shop-text-muted,#6b7280);margin:10px 0 0;line-height:1.45;}
    .od-pay-footnote a{color:var(--shop-link,var(--msb-palette-link,#2563eb));text-decoration:underline;}
    .od-delivery-headline{
      font-size:15px;font-weight:700;margin:0 0 14px;line-height:1.35;
      color:var(--shop-text,var(--msb-palette-text,inherit));
    }
    .od-delivery-headline.is-delivered{color:#15803d;}
    .od-pay-incomplete{
      margin:0 0 14px;padding:10px 12px;border-radius:8px;
      border:1px solid rgba(220,38,38,.35);
      background:rgba(220,38,38,.08);
      color:#b91c1c;
      font-size:13px;font-weight:650;line-height:1.45;
    }
    .od-pay-incomplete strong{font-weight:800;}
    .od-seller-pay-msg{
      margin:10px 0 0;padding:8px 10px;border-radius:6px;
      background:rgba(255,255,255,.4);border:1px solid rgba(185,28,28,.2);
      font-size:12.5px;line-height:1.45;font-weight:550;
    }
    .od-seller-pay-msg strong{display:block;margin-bottom:4px;}
    .od-pay-actions{display:flex;flex-wrap:wrap;gap:8px;margin-top:12px;}
    .od-pay-action-primary{
      border:0;border-radius:8px;background:#111827;color:#fff;
      font-size:13px;font-weight:750;padding:9px 14px;cursor:pointer;
    }
    .od-pay-action-primary:hover{opacity:.92;}
    .od-pay-action-secondary{
      border:1px solid rgba(185,28,28,.45);border-radius:8px;background:transparent;color:#b91c1c;
      font-size:13px;font-weight:750;padding:9px 14px;cursor:pointer;
    }
    .od-pay-action-secondary:hover{background:rgba(254,226,226,.55);}
    .od-pay-modal{
      position:fixed;inset:0;z-index:12000;display:none;align-items:center;justify-content:center;
      padding:18px;box-sizing:border-box;
    }
    .od-pay-modal.is-open{display:flex;}
    .od-pay-modal-backdrop{
      position:absolute;inset:0;border:0;padding:0;margin:0;cursor:pointer;
      background:rgba(2,6,23,.72);backdrop-filter:blur(4px);-webkit-backdrop-filter:blur(4px);
    }
    .od-pay-modal-card{
      position:relative;z-index:1;width:100%;max-width:420px;
      background:#fff;color:#0f172a;
      border:1px solid #e2e8f0;border-radius:18px;
      box-shadow:0 24px 64px rgba(2,6,23,.28);
      padding:22px 22px 18px;text-align:center;
    }
    .od-pay-modal-close{
      position:absolute;top:12px;right:12px;width:32px;height:32px;
      border:0;border-radius:999px;background:transparent;color:#64748b;
      font-size:22px;line-height:1;cursor:pointer;
    }
    .od-pay-modal-close:hover{background:#f1f5f9;color:#0f172a;}
    .od-pay-modal-icon{
      width:52px;height:52px;margin:4px auto 14px;border-radius:999px;
      display:flex;align-items:center;justify-content:center;
      background:#eff6ff;color:#2563eb;font-size:22px;
    }
    .od-pay-modal-card h3{
      margin:0 0 6px;font-size:20px;font-weight:800;letter-spacing:-.02em;color:#0f172a;
    }
    .od-pay-modal-card .od-pay-modal-desc{
      margin:0 0 14px;font-size:13px;line-height:1.45;color:#64748b;font-weight:600;
    }
    .od-pay-modal-meta{
      display:grid;gap:6px;text-align:left;margin:0 0 14px;padding:10px 12px;
      border-radius:12px;background:#f8fafc;border:1px solid #e2e8f0;
      font-size:12px;font-weight:700;color:#334155;
    }
    .od-pay-modal-meta div{display:flex;justify-content:space-between;gap:10px;}
    .od-pay-modal-meta span{color:#64748b;font-weight:650;}
    .od-pay-modal-meta strong{color:#0f172a;font-weight:800;text-align:right;}
    .od-pay-modal-meta .is-short strong{color:#b91c1c;}
    .od-pay-modal-card label{
      display:block;text-align:left;font-size:12px;font-weight:800;color:#334155;margin:0 0 6px;
    }
    .od-pay-modal-card input[type="text"],
    .od-pay-modal-card input[type="number"]{
      width:100%;box-sizing:border-box;
      border:1px solid #cbd5e1;border-radius:12px;padding:12px 12px;
      font:inherit;font-size:15px;font-weight:700;color:#0f172a;background:#fff;
      margin:0 0 16px;
    }
    .od-pay-modal-card input:focus{
      outline:0;border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,.18);
    }
    .od-pay-modal-actions{display:flex;gap:10px;}
    .od-pay-modal-actions button{
      flex:1 1 0;height:42px;border-radius:12px;font:inherit;font-size:13px;font-weight:800;cursor:pointer;
    }
    .od-pay-modal-cancel{border:1px solid #cbd5e1;background:#fff;color:#0f172a;}
    .od-pay-modal-cancel:hover{background:#f8fafc;}
    .od-pay-modal-submit{border:0;background:#2563eb;color:#fff;}
    .od-pay-modal-submit:hover{background:#1d4ed8;}
    .od-pay-modal-submit:disabled{opacity:.55;cursor:not-allowed;}
    .od-pay-modal-err{
      display:none;margin:-8px 0 12px;text-align:left;font-size:12px;font-weight:700;color:#b91c1c;
    }
    .od-pay-modal-err.is-on{display:block;}
    html.dark-auto .od-pay-modal-card,
    html[data-theme="dark"] .od-pay-modal-card,
    .shop-page.dark .od-pay-modal-card{
      background:#1a1d21;color:#f8fafc;border-color:rgba(148,163,184,.28);
      box-shadow:0 24px 64px rgba(0,0,0,.55);
    }
    html.dark-auto .od-pay-modal-close,
    html[data-theme="dark"] .od-pay-modal-close{color:#94a3b8;}
    html.dark-auto .od-pay-modal-close:hover,
    html[data-theme="dark"] .od-pay-modal-close:hover{background:rgba(148,163,184,.12);color:#f8fafc;}
    html.dark-auto .od-pay-modal-icon,
    html[data-theme="dark"] .od-pay-modal-icon{background:rgba(59,130,246,.18);color:#93c5fd;}
    html.dark-auto .od-pay-modal-card h3,
    html[data-theme="dark"] .od-pay-modal-card h3{color:#f8fafc;}
    html.dark-auto .od-pay-modal-card .od-pay-modal-desc,
    html[data-theme="dark"] .od-pay-modal-card .od-pay-modal-desc{color:#94a3b8;}
    html.dark-auto .od-pay-modal-meta,
    html[data-theme="dark"] .od-pay-modal-meta{
      background:#12151a;border-color:rgba(148,163,184,.22);color:#e2e8f0;
    }
    html.dark-auto .od-pay-modal-meta span,
    html[data-theme="dark"] .od-pay-modal-meta span{color:#94a3b8;}
    html.dark-auto .od-pay-modal-meta strong,
    html[data-theme="dark"] .od-pay-modal-meta strong{color:#f8fafc;}
    html.dark-auto .od-pay-modal-meta .is-short strong,
    html[data-theme="dark"] .od-pay-modal-meta .is-short strong{color:#fca5a5;}
    html.dark-auto .od-pay-modal-card label,
    html[data-theme="dark"] .od-pay-modal-card label{color:#cbd5e1;}
    html.dark-auto .od-pay-modal-card input,
    html[data-theme="dark"] .od-pay-modal-card input{
      background:#0f1216;border-color:rgba(148,163,184,.28);color:#f1f5f9;
    }
    html.dark-auto .od-pay-modal-card input:focus,
    html[data-theme="dark"] .od-pay-modal-card input:focus{
      border-color:#3b82f6;box-shadow:0 0 0 3px rgba(59,130,246,.25);
    }
    html.dark-auto .od-pay-modal-cancel,
    html[data-theme="dark"] .od-pay-modal-cancel{
      background:#12151a;border-color:rgba(148,163,184,.35);color:#f8fafc;
    }
    html.dark-auto .od-pay-modal-cancel:hover,
    html[data-theme="dark"] .od-pay-modal-cancel:hover{background:#0f1216;}
    html.dark-auto .od-pay-modal-submit,
    html[data-theme="dark"] .od-pay-modal-submit{background:#3b82f6;color:#fff;}
    html.dark-auto .od-pay-modal-submit:hover,
    html[data-theme="dark"] .od-pay-modal-submit:hover{background:#2563eb;}
    html.dark-auto .od-pay-modal-err,
    html[data-theme="dark"] .od-pay-modal-err{color:#fca5a5;}
    .od-cancel-notice{
      margin:0 0 14px;padding:10px 12px;border-radius:8px;
      border:1px solid rgba(100,116,139,.28);
      background:rgba(100,116,139,.08);
      color:#334155;
      font-size:13px;font-weight:650;line-height:1.45;
    }
    .od-cancel-notice strong{font-weight:800;color:#b91c1c;}
    .od-progress{position:relative;margin:0 0 16px;padding:0 0 4px;}
    .od-progress.is-complete .od-progress-line-fill{background:#15803d;}
    .od-progress-line{
      position:absolute;top:11px;left:12%;right:12%;height:2px;
      background:var(--shop-border,var(--msb-palette-border,rgba(15,23,42,.12)));
      z-index:0;
    }
    .od-progress-line-fill{
      height:100%;background:var(--shop-link,var(--msb-palette-link,#2563eb));
      transition:width .25s ease;
    }
    .od-progress-steps{
      position:relative;z-index:1;
      display:grid;grid-template-columns:repeat(3,1fr);gap:4px;text-align:center;
    }
    .od-step-dot{
      width:24px;height:24px;border-radius:50%;margin:0 auto 6px;
      display:flex;align-items:center;justify-content:center;
      font-size:11px;border:2px solid var(--shop-border,rgba(15,23,42,.18));
      background:var(--shop-card-bg,#fff);color:transparent;
    }
    .od-step.is-done .od-step-dot{
      border-color:var(--shop-link,var(--msb-palette-link,#2563eb));
      background:var(--shop-link,var(--msb-palette-link,#2563eb));color:#fff;
    }
    .od-progress.is-complete .od-step.is-done .od-step-dot{
      border-color:#15803d;background:#15803d;color:#fff;
    }
    .od-step-label{display:block;font-size:12px;font-weight:700;line-height:1.25;}
    .od-step-date{display:block;font-size:11px;color:var(--shop-text-muted,#6b7280);margin-top:2px;}
    .od-progress.is-complete .od-step-label{color:#15803d;}
    .od-delivery-path-note{
      margin:0 0 12px;font-size:12px;line-height:1.4;
      color:var(--shop-text-muted,var(--msb-palette-text-muted,#64748b));
    }
    .od-tracking-head{font-size:13px;font-weight:800;margin:0 0 8px;}
    .od-tracking-row{
      display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;
    }
    .od-tracking-num{font-size:13px;color:var(--shop-text-muted,#6b7280);}
    .od-tracking-num strong{color:var(--shop-text,inherit);font-weight:600;}
    .od-pill-btn{
      display:inline-flex;align-items:center;justify-content:center;
      padding:6px 14px;border-radius:999px;font-size:13px;font-weight:600;
      border:1px solid var(--shop-link,var(--msb-palette-link,#2563eb));
      color:var(--shop-link,var(--msb-palette-link,#2563eb));
      background:transparent;text-decoration:none;cursor:pointer;white-space:nowrap;
    }
    .od-item-row{display:flex;gap:12px;align-items:flex-start;}
    .od-thumb{
      width:72px;height:72px;border-radius:8px;overflow:hidden;flex-shrink:0;
      background:var(--shop-card-raised,rgba(15,23,42,.04));
      display:flex;align-items:center;justify-content:center;color:var(--shop-text-muted,#6b7280);
    }
    .od-thumb img{width:100%;height:100%;object-fit:cover;}
    .od-item-main{flex:1;min-width:0;}
    .od-item-top{display:flex;align-items:flex-start;justify-content:space-between;gap:10px;}
    .od-item-title{
      font-size:14px;font-weight:600;line-height:1.35;margin:0;
    }
    .od-item-title a{color:var(--shop-link,var(--msb-palette-link,#2563eb));text-decoration:underline;}
    .od-item-price{font-size:14px;font-weight:800;white-space:nowrap;}
    .od-item-meta{font-size:12px;color:var(--shop-text-muted,#6b7280);margin-top:6px;line-height:1.45;}
    .od-item-ship{margin-top:10px;font-size:12px;line-height:1.5;color:var(--shop-text-muted,#6b7280);}
    .od-item-ship-label{display:block;font-size:12px;font-weight:700;color:var(--shop-text,var(--msb-palette-text,inherit));margin-bottom:2px;}
    .od-item-actions{
      display:flex;align-items:center;justify-content:space-between;gap:10px;
      margin-top:12px;flex-wrap:wrap;
    }
    .od-text-link{
      font-size:13px;font-weight:600;color:var(--shop-link,var(--msb-palette-link,#2563eb));
      text-decoration:underline;background:none;border:none;cursor:pointer;padding:0;
    }
    .od-more-wrap{position:relative;margin-left:auto;}
    .od-more-menu{
      position:absolute;right:0;top:calc(100% + 6px);min-width:180px;z-index:20;
      background:var(--shop-card-bg,#fff);
      border:1px solid var(--shop-border,rgba(15,23,42,.12));
      border-radius:10px;box-shadow:0 8px 24px rgba(15,23,42,.12);
      padding:6px 0;display:none;
    }
    .od-more-menu.is-open{display:block;}
    .od-more-menu button,.od-more-menu a{
      display:block;width:100%;text-align:left;border:none;background:transparent;
      padding:8px 14px;font-size:13px;color:var(--shop-text,inherit);cursor:pointer;text-decoration:none;
    }
    .od-more-menu button:hover,.od-more-menu a:hover{background:var(--shop-card-raised,rgba(15,23,42,.04));}
    .od-seller-name{font-size:14px;font-weight:800;line-height:1.45;text-transform:uppercase;}
    .od-seller-address{font-size:13px;line-height:1.55;color:var(--shop-text-muted,#6b7280);margin-top:4px;}
    .od-action-link{
      font-size:13px;color:var(--shop-link,var(--msb-palette-link,#2563eb));
      text-decoration:underline;
    }
    .od-review-form{margin-top:12px;padding-top:12px;border-top:1px solid var(--shop-border,rgba(15,23,42,.08));display:grid;gap:8px;}
    .od-review-form[hidden]{display:none;}
    .od-review-form label{font-size:13px;font-weight:600;color:var(--shop-text-muted,#6b7280);}
    .od-review-form .form-control{
      background:var(--shop-input-bg,transparent);
      border:1px solid var(--shop-border-strong,rgba(15,23,42,.14));
      border-radius:8px;color:var(--shop-text,inherit);
    }
    .od-btn{
      border:1px solid var(--shop-border,rgba(177,188,206,.45));
      background:var(--shop-btn-outline-bg,transparent);
      color:var(--shop-btn-outline-text,var(--shop-text,inherit));
      font-size:13px;font-weight:600;cursor:pointer;padding:6px 12px;border-radius:8px;
    }
    .od-btn-primary{
      background:var(--shop-btn-filled-bg,#3d4a32);
      color:var(--shop-btn-filled-text,#fff);
      border-color:var(--shop-border,rgba(177,188,206,.55));
    }
    .od-empty{padding:32px 16px;text-align:center;color:var(--shop-text-muted,#6b7280);}
  </style>
</head>
<body class="shop-page order-detail-door<?= $embed ? ' order-detail-embed' : '' ?>">
<script>
(function () {
  try {
    if (window.parent && window.parent.document &&
        (window.parent.document.documentElement.classList.contains('dark-auto')
          || window.parent.document.documentElement.getAttribute('data-theme') === 'dark')) {
      document.documentElement.classList.add('dark-auto');
    }
  } catch (e) {}
})();
</script>

<div class="od-shell" id="odShell" data-screen="0"<?= $notFound ? '' : ' data-order-id="' . (int)$orderId . '"' ?>>
  <div class="od-top">
    <div class="od-head">
      <h1 class="od-title">Order details</h1>
      <?php if ($embed): ?>
        <button type="button" class="od-close" id="odCloseBtn" aria-label="Close"><i class="icon ion-close"></i></button>
      <?php else: ?>
        <a href="shop.php" class="od-back" target="_top">&larr; Back to shop</a>
      <?php endif; ?>
    </div>
    <?php if (!$notFound): ?>
      <div class="od-tabs" role="tablist">
        <button type="button" class="od-tab is-active" role="tab" aria-selected="true" data-screen="0">Order</button>
        <button type="button" class="od-tab" role="tab" aria-selected="false" data-screen="1">Payment</button>
        <button type="button" class="od-tab" role="tab" aria-selected="false" data-screen="2">Seller</button>
      </div>
    <?php endif; ?>
  </div>

  <?php if ($notFound): ?>
    <div class="od-empty">This order was not found.</div>
  <?php else: ?>
    <div class="od-viewport">
      <div class="od-track">
        <div class="od-screen od-screen-order" role="tabpanel">
          <?php if ($paymentIncomplete): ?>
            <div class="od-pay-incomplete" role="alert">
              <strong>Payment not complete — shipping on hold.</strong>
              <?= h($paymentIncompleteMsg) ?>
              <?php if ($sellerPaymentRequest !== ''): ?>
                <div class="od-seller-pay-msg">
                  <strong>Message from seller:</strong>
                  <?= h($sellerPaymentRequest) ?>
                </div>
              <?php endif; ?>
              <div class="od-pay-actions">
                <button
                  type="button"
                  class="od-pay-action-primary js-order-complete-pay"
                  data-order-id="<?= (int)$orderId ?>"
                  data-order-code="<?= h($orderCodeUi) ?>"
                  data-paid-label="<?= h($paidLabelUi) ?>"
                  data-due-label="<?= h((string)$dueLabelUi) ?>"
                  data-shortfall-cents="<?= (int)$shortfallCentsUi ?>"
                  data-shortfall-label="<?= h($shortfallLabelUi) ?>"
                >Complete payment<?= $shortfallLabelUi !== '' ? (' (' . h($shortfallLabelUi) . ')') : '' ?></button>
                <?php if ($canCancel): ?>
                  <button type="button" class="od-pay-action-secondary js-order-cancel" data-order-id="<?= (int)$orderId ?>">Cancel order</button>
                <?php endif; ?>
              </div>
            </div>
          <?php endif; ?>
          <?php if ($isCancelled && $cancelNotice !== ''): ?>
            <div class="od-cancel-notice" role="status">
              <strong>Order cancelled.</strong>
              <?= h($cancelNotice) ?>
            </div>
          <?php endif; ?>
          <section class="od-block">
            <h2 class="od-block-label">Order info</h2>
            <div class="od-block-body">
              <dl class="od-kv">
                <dt>Time placed</dt>
                <dd><?= h(order_detail_fmt_placed($createdAt)) ?></dd>
                <dt>Order number</dt>
                <dd><?= h((string)($order['order_code'] ?? '')) ?></dd>
                <dt>Total</dt>
                <dd><?= $isCancelled ? 'No charge' : (h($total) . ' (' . h($itemLabel) . ')') ?></dd>
                <?php if ($paymentIncomplete && (int)($paymentProgress['paid_cents'] ?? 0) > 0): ?>
                <dt>Amount paid</dt>
                <dd><?= h(org_shop_format_price((int)$paymentProgress['paid_cents'], (string)$paymentProgress['currency'])) ?></dd>
                <dt>Still due</dt>
                <dd><?= h(org_shop_format_price((int)$paymentProgress['shortfall_cents'], (string)$paymentProgress['currency'])) ?></dd>
                <?php endif; ?>
                <dt>Sold by</dt>
                <dd>
                  <?php if ($publisherId > 0): ?>
                    <a href="profile.php?tab=shop&amp;id=<?= $publisherId ?>" target="_top"><?= h($seller) ?></a>
                  <?php else: ?>
                    <?= h($seller) ?>
                  <?php endif; ?>
                </dd>
              </dl>
            </div>
          </section>

          <section class="od-block">
            <h2 class="od-block-label">Delivery info</h2>
            <div class="od-block-body">
              <p class="od-delivery-headline<?= $isDelivered ? ' is-delivered' : '' ?>"><?= h($deliveryHeadline) ?></p>
              <?php if ($isCancelled): ?>
                <p class="od-delivery-path-note">Shipping timeline is not available for cancelled orders.</p>
              <?php elseif ($isDelivered): ?>
                <p class="od-delivery-path-note">Delivery complete — follow the path from Paid to Delivered below.</p>
              <?php endif; ?>
              <?php if (!$isCancelled): ?>
              <?php
                $paidDone = order_detail_step_done($status, 'paid');
                $trackDone = order_detail_step_done($status, 'tracking') || $tracking !== '';
                $delivDone = order_detail_step_done($status, 'delivered');
                $fillPct = $delivDone ? 100 : ($trackDone ? 55 : ($paidDone ? 12 : 0));
                $paidDate = order_detail_fmt_short($paymentWhen !== '' ? $paymentWhen : $createdAt);
                $trackDate = order_detail_fmt_short($shippedAt !== '' ? $shippedAt : ($trackDone && $tracking !== '' ? $shippedAt : ''));
                $delivDate = order_detail_fmt_short($deliveredAt);
              ?>
              <div class="od-progress<?= $delivDone ? ' is-complete' : '' ?>" aria-label="Delivery progress from Paid to Delivered">
                <div class="od-progress-line"><div class="od-progress-line-fill" style="width:<?= (int)$fillPct ?>%"></div></div>
                <div class="od-progress-steps">
                  <div class="od-step<?= $paidDone ? ' is-done' : '' ?>">
                    <span class="od-step-dot" aria-hidden="true"><i class="fa fa-check"></i></span>
                    <span class="od-step-label">Paid</span>
                    <?php if ($paidDate !== ''): ?><span class="od-step-date"><?= h($paidDate) ?></span><?php endif; ?>
                  </div>
                  <div class="od-step<?= $trackDone ? ' is-done' : '' ?>">
                    <span class="od-step-dot" aria-hidden="true"><i class="fa fa-truck"></i></span>
                    <span class="od-step-label">Shipping</span>
                    <?php if ($trackDate !== ''): ?><span class="od-step-date"><?= h($trackDate) ?></span><?php endif; ?>
                  </div>
                  <div class="od-step<?= $delivDone ? ' is-done' : '' ?>">
                    <span class="od-step-dot" aria-hidden="true"><i class="fa fa-home"></i></span>
                    <span class="od-step-label">Delivered</span>
                    <?php if ($delivDate !== ''): ?><span class="od-step-date"><?= h($delivDate) ?></span><?php endif; ?>
                  </div>
                </div>
              </div>
              <?php if ($tracking !== ''): ?>
                <p class="od-tracking-head">Tracking details</p>
                <div class="od-tracking-row">
                  <div class="od-tracking-num">Number <strong><?= h($tracking) ?></strong></div>
                  <?php if ($trackUrl !== ''): ?>
                    <a href="<?= h($trackUrl) ?>" class="od-pill-btn" target="_blank" rel="noopener noreferrer">Track package</a>
                  <?php endif; ?>
                </div>
              <?php endif; ?>
              <?php endif; ?>
            </div>
          </section>

          <section class="od-block">
            <h2 class="od-block-label">Item info</h2>
            <div class="od-block-body">
              <div class="od-item-row">
                <div class="od-thumb">
                  <?php if ($cover !== ''): ?><img src="<?= h($cover) ?>" alt=""><?php else: ?><i class="icon ion-bag"></i><?php endif; ?>
                </div>
                <div class="od-item-main">
                  <div class="od-item-top">
                    <h3 class="od-item-title">
                      <?php if ($productId > 0): ?>
                        <a href="product_detail.php?id=<?= $productId ?>" target="_top"><?= h((string)($order['product_title'] ?? '')) ?></a>
                      <?php else: ?>
                        <?= h((string)($order['product_title'] ?? '')) ?>
                      <?php endif; ?>
                    </h3>
                    <div class="od-item-price"><?= h($unit) ?></div>
                  </div>
                  <div class="od-item-meta">
                    <?php if ($productId > 0): ?>Item number: <?= (int)$productId ?><br><?php endif; ?>
                    <?php if ($returnWindow): ?><?= h($returnWindow) ?><?php endif; ?>
                  </div>
                  <div class="od-item-actions">
                    <?php if ($productId > 0): ?>
                      <a href="product_detail.php?id=<?= $productId ?>" class="od-text-link" target="_top">Buy again</a>
                    <?php endif; ?>
                    <div class="od-more-wrap">
                      <button type="button" class="od-pill-btn js-more-actions-toggle" aria-expanded="false">More actions <i class="fa fa-chevron-down"></i></button>
                      <div class="od-more-menu" id="odMoreMenu">
                        <?php if ($canReturn): ?>
                          <button type="button" class="js-order-return" data-order-id="<?= (int)$orderId ?>">Request return</button>
                        <?php endif; ?>
                        <?php if ($canReview): ?>
                          <button type="button" class="js-order-review-toggle" data-order-id="<?= (int)$orderId ?>">Leave review</button>
                        <?php endif; ?>
                        <?php if ($productId > 0): ?>
                          <a href="product_detail.php?id=<?= $productId ?>" target="_top">View product · <?= h($detailLabel) ?></a>
                        <?php endif; ?>
                      </div>
                    </div>
                  </div>
                  <?php if ($canReview): ?>
                    <form class="od-review-form" id="reviewForm-<?= (int)$orderId ?>" hidden>
                      <label for="reviewRating-<?= (int)$orderId ?>">Rating</label>
                      <select class="form-control" id="reviewRating-<?= (int)$orderId ?>" name="rating">
                        <?php for ($r = 5; $r >= 1; $r--): ?>
                          <option value="<?= $r ?>"><?= $r ?> star<?= $r === 1 ? '' : 's' ?></option>
                        <?php endfor; ?>
                      </select>
                      <label for="reviewText-<?= (int)$orderId ?>">Review</label>
                      <textarea class="form-control" id="reviewText-<?= (int)$orderId ?>" name="review_text" rows="2" placeholder="Share your experience"></textarea>
                      <button type="submit" class="od-btn od-btn-primary">Submit review</button>
                    </form>
                  <?php endif; ?>
                </div>
              </div>
            </div>
          </section>
        </div>

        <div class="od-screen od-screen-payment" role="tabpanel">
          <?php if ($paymentIncomplete): ?>
            <div class="od-pay-incomplete" role="alert">
              <strong>Payment not complete — shipping on hold.</strong>
              <?= h($paymentIncompleteMsg) ?>
              <?php if ($sellerPaymentRequest !== ''): ?>
                <div class="od-seller-pay-msg">
                  <strong>Message from seller:</strong>
                  <?= h($sellerPaymentRequest) ?>
                </div>
              <?php endif; ?>
              <div class="od-pay-actions">
                <button
                  type="button"
                  class="od-pay-action-primary js-order-complete-pay"
                  data-order-id="<?= (int)$orderId ?>"
                  data-order-code="<?= h($orderCodeUi) ?>"
                  data-paid-label="<?= h($paidLabelUi) ?>"
                  data-due-label="<?= h((string)$dueLabelUi) ?>"
                  data-shortfall-cents="<?= (int)$shortfallCentsUi ?>"
                  data-shortfall-label="<?= h($shortfallLabelUi) ?>"
                >Complete payment<?= $shortfallLabelUi !== '' ? (' (' . h($shortfallLabelUi) . ')') : '' ?></button>
                <?php if ($canCancel): ?>
                  <button type="button" class="od-pay-action-secondary js-order-cancel" data-order-id="<?= (int)$orderId ?>">Cancel order</button>
                <?php endif; ?>
              </div>
            </div>
          <?php endif; ?>
          <?php if ($isCancelled && $cancelNotice !== ''): ?>
            <div class="od-cancel-notice" role="status">
              <strong>No charge.</strong>
              <?= h($cancelNotice) ?> Payment amounts are cleared for this cancelled order.
            </div>
          <?php endif; ?>
          <?php if ($shippingLines !== [] && !$isCancelled): ?>
            <section class="od-pay-section">
              <h2 class="od-pay-heading">Shipping address</h2>
              <div class="od-pay-address">
                <?php foreach ($shippingLines as $line): ?>
                  <?= h($line) ?><br>
                <?php endforeach; ?>
              </div>
            </section>
          <?php endif; ?>

          <section class="od-pay-section">
            <h2 class="od-pay-heading">Payment info</h2>
            <div class="od-pay-method">
              <div class="od-pay-method-left">
                <span class="od-pay-method-icon" aria-hidden="true">
                  <?php if ($isCancelled): ?>
                    <i class="fa fa-ban"></i>
                  <?php elseif ($paymentBrand['icon'] === 'card'): ?>
                    <i class="fa fa-credit-card"></i>
                  <?php elseif ($paymentBrand['icon'] === 'manual'): ?>
                    <i class="fa fa-money"></i>
                  <?php else: ?>
                    <i class="fa fa-clock-o"></i>
                  <?php endif; ?>
                </span>
                <span class="od-pay-method-label"><?= h((string)$paymentBrand['label']) ?></span>
              </div>
              <div class="od-pay-method-right">
                <div class="od-pay-method-amt"><?= $isCancelled ? 'No charge' : h($total) ?></div>
                <?php if ($paymentIncomplete && (int)($paymentProgress['paid_cents'] ?? 0) > 0): ?>
                  <div class="od-pay-method-date">Paid <?= h(org_shop_format_price((int)$paymentProgress['paid_cents'], (string)$paymentProgress['currency'])) ?> · due <?= h(org_shop_format_price((int)$paymentProgress['shortfall_cents'], (string)$paymentProgress['currency'])) ?></div>
                <?php elseif ($paymentDateShort !== ''): ?>
                  <div class="od-pay-method-date"><?= h($paymentDateShort) ?></div>
                <?php endif; ?>
              </div>
            </div>

            <?php if ($isCancelled): ?>
              <div class="od-pay-breakdown">
                <div class="od-pay-line">
                  <span><?= h($itemCountLabel) ?></span>
                  <span><?= h($subtotal) ?></span>
                </div>
                <div class="od-pay-line">
                  <span>Shipping</span>
                  <span><?= h($shippingPrice) ?></span>
                </div>
                <div class="od-pay-line">
                  <span>Tax*</span>
                  <span><?= h($tax) ?></span>
                </div>
                <div class="od-pay-line is-total">
                  <span>Order total</span>
                  <span>No charge</span>
                </div>
              </div>
            <?php else: ?>
            <div class="od-pay-breakdown">
              <div class="od-pay-line">
                <span><?= h($itemCountLabel) ?></span>
                <span><?= h($subtotal) ?></span>
              </div>
              <div class="od-pay-line">
                <span>Shipping</span>
                <span class="<?= $shippingIsFree ? 'od-pay-free' : '' ?>"><?= h($shippingPrice) ?></span>
              </div>
              <div class="od-pay-line">
                <span>Tax*</span>
                <span><?= h($tax) ?></span>
              </div>
              <?php if ($serviceFeeCents > 0): ?>
              <div class="od-pay-line">
                <span>Service fee</span>
                <span><?= h($serviceFee) ?></span>
              </div>
              <?php endif; ?>
              <div class="od-pay-line is-total">
                <span>Order total</span>
                <span><?= h($total) ?></span>
              </div>
            </div>
            <?php endif; ?>

            <?php if (!$isCancelled): ?>
            <p class="od-pay-footnote">*We're required by law to collect sales tax and applicable fees for certain tax authorities. <a href="#" onclick="return false;">Learn more</a></p>
            <?php endif; ?>

            <?php if ($receiptCode !== '' && !$isCancelled): ?>
              <p class="od-pay-footnote" style="margin-top:8px;">Receipt <code><?= h($receiptCode) ?></code></p>
            <?php endif; ?>
          </section>
        </div>

        <div class="od-screen od-screen-seller" role="tabpanel">
          <section class="od-block">
            <h2 class="od-block-label">Seller info</h2>
            <div class="od-block-body">
              <div class="od-seller-name"><?= h($seller) ?></div>
              <?php if ($sellerEmail !== ''): ?>
                <div class="od-seller-address">Email: <a href="mailto:<?= h($sellerEmail) ?>"><?= h($sellerEmail) ?></a></div>
              <?php endif; ?>
              <?php if ($sellerPhone !== ''): ?>
                <div class="od-seller-address">Phone: <a href="tel:<?= h(preg_replace('/\s+/', '', $sellerPhone)) ?>"><?= h($sellerPhone) ?></a></div>
              <?php endif; ?>
              <?php if ($sellerAddress !== ''): ?>
                <div class="od-seller-address"><?= nl2br(h($sellerAddress)) ?></div>
              <?php endif; ?>
            </div>
          </section>

          <section class="od-block">
            <h2 class="od-block-label">Other actions</h2>
            <div class="od-block-body">
              <?php if ($canCancel): ?>
                <button type="button" class="od-action-link js-order-cancel" data-order-id="<?= (int)$orderId ?>" style="display:block;width:100%;text-align:left;border:0;background:transparent;padding:0;margin:0 0 10px;cursor:pointer;color:inherit;font:inherit;">Cancel order</button>
              <?php endif; ?>
              <a href="<?= h($contactUrl) ?>" class="od-action-link" target="_top">Contact seller</a>
            </div>
          </section>
        </div>
      </div>
    </div>
  <?php endif; ?>
</div>

<?php if (!$notFound && $paymentIncomplete): ?>
<div class="od-pay-modal" id="odPayCompleteModal" aria-hidden="true">
  <button type="button" class="od-pay-modal-backdrop" id="odPayCompleteBackdrop" aria-label="Close"></button>
  <div class="od-pay-modal-card" role="dialog" aria-modal="true" aria-labelledby="odPayCompleteTitle">
    <button type="button" class="od-pay-modal-close" id="odPayCompleteClose" aria-label="Close">&times;</button>
    <div class="od-pay-modal-icon" aria-hidden="true"><i class="fa fa-credit-card"></i></div>
    <h3 id="odPayCompleteTitle">Complete payment?</h3>
    <p class="od-pay-modal-desc">
      Enter how much to pay now. Shipping stays on hold until the full order total is paid.
    </p>
    <div class="od-pay-modal-meta">
      <div><span>Order</span><strong id="odPayMetaCode"><?= h($orderCodeUi !== '' ? $orderCodeUi : '—') ?></strong></div>
      <div><span>Already paid</span><strong id="odPayMetaPaid"><?= h($paidLabelUi !== '' ? $paidLabelUi : '—') ?></strong></div>
      <div><span>Order total</span><strong id="odPayMetaDue"><?= h((string)($dueLabelUi !== '' ? $dueLabelUi : '—')) ?></strong></div>
      <div class="is-short"><span>Still due</span><strong id="odPayMetaShort"><?= h($shortfallLabelUi !== '' ? $shortfallLabelUi : '—') ?></strong></div>
    </div>
    <label for="odPayCompleteAmount">Amount to pay now</label>
    <input
      type="text"
      inputmode="decimal"
      id="odPayCompleteAmount"
      value="<?= h($shortfallCentsUi > 0 ? number_format($shortfallCentsUi / 100, 2, '.', '') : '') ?>"
      placeholder="0.00"
      autocomplete="off"
    >
    <div class="od-pay-modal-err" id="odPayCompleteErr" role="alert"></div>
    <div class="od-pay-modal-actions">
      <button type="button" class="od-pay-modal-cancel" id="odPayCompleteCancel">Cancel</button>
      <button type="button" class="od-pay-modal-submit" id="odPayCompleteSubmit">Pay now</button>
    </div>
  </div>
</div>
<?php endif; ?>

<script>
(function(){
  var shell = document.getElementById('odShell');
  var tabs = document.querySelectorAll('.od-tab');
  function setScreen(screen) {
    screen = String(screen || '0');
    if (shell) shell.setAttribute('data-screen', screen);
    tabs.forEach(function(t){
      var active = String(t.getAttribute('data-screen') || '') === screen;
      t.classList.toggle('is-active', active);
      t.setAttribute('aria-selected', active ? 'true' : 'false');
    });
  }
  tabs.forEach(function(tab){
    tab.addEventListener('click', function(){
      setScreen(tab.getAttribute('data-screen') || '0');
    });
  });
  (function openDeepLinkTab(){
    var tab = '';
    try {
      tab = String((new URLSearchParams(window.location.search || '')).get('tab') || '').trim().toLowerCase();
    } catch (e) {}
    if (!tab) {
      tab = String((window.location.hash || '').replace(/^#/, '')).trim().toLowerCase();
    }
    if (tab === 'payment' || tab === 'pay' || tab === '1') setScreen('1');
    else if (tab === 'seller' || tab === '2') setScreen('2');
    else if (tab === 'order' || tab === '0') setScreen('0');
  })();

  var closeBtn = document.getElementById('odCloseBtn');
  if (closeBtn) {
    closeBtn.addEventListener('click', function(){
      try {
        if (window.parent && window.parent !== window) {
          window.parent.postMessage({ type: 'msb-live-right-door-close' }, '*');
          return;
        }
      } catch (e) {}
      if (window.history.length > 1) window.history.back();
    });
  }

  var moreToggle = document.querySelector('.js-more-actions-toggle');
  var moreMenu = document.getElementById('odMoreMenu');
  if (moreToggle && moreMenu) {
    moreToggle.addEventListener('click', function(e){
      e.stopPropagation();
      var open = moreMenu.classList.toggle('is-open');
      moreToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
    document.addEventListener('click', function(){
      moreMenu.classList.remove('is-open');
      moreToggle.setAttribute('aria-expanded', 'false');
    });
  }

  document.querySelectorAll('.js-order-return').forEach(function(btn){
    btn.addEventListener('click', async function(){
      if (moreMenu) moreMenu.classList.remove('is-open');
      var orderId = btn.getAttribute('data-order-id');
      var reason = window.prompt('Why are you returning this item?');
      if (!reason || !reason.trim()) return;
      var body = new URLSearchParams();
      body.set('order_id', orderId);
      body.set('reason', reason.trim());
      var res = await fetch('ajax/order_return.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: body.toString(), credentials:'same-origin' });
      var data = await res.json();
      window.alert(data.message || (data.ok ? 'Return requested.' : 'Failed.'));
    });
  });

  document.querySelectorAll('.js-order-complete-pay').forEach(function(btn){
    btn.addEventListener('click', function(){
      if (moreMenu) moreMenu.classList.remove('is-open');
      openPayCompleteModal(btn);
    });
  });

  var payModal = document.getElementById('odPayCompleteModal');
  var payAmount = document.getElementById('odPayCompleteAmount');
  var payErr = document.getElementById('odPayCompleteErr');
  var paySubmit = document.getElementById('odPayCompleteSubmit');
  var activePayBtn = null;

  function setPayErr(msg) {
    if (!payErr) return;
    if (msg) {
      payErr.textContent = msg;
      payErr.classList.add('is-on');
    } else {
      payErr.textContent = '';
      payErr.classList.remove('is-on');
    }
  }

  function closePayCompleteModal() {
    if (!payModal) return;
    payModal.classList.remove('is-open');
    payModal.setAttribute('aria-hidden', 'true');
    activePayBtn = null;
    setPayErr('');
    document.body.style.overflow = '';
  }

  function openPayCompleteModal(btn) {
    if (!payModal || !btn) return;
    activePayBtn = btn;
    var code = String(btn.getAttribute('data-order-code') || '').trim();
    var paid = String(btn.getAttribute('data-paid-label') || '').trim();
    var due = String(btn.getAttribute('data-due-label') || '').trim();
    var shortfallCents = parseInt(btn.getAttribute('data-shortfall-cents') || '0', 10) || 0;
    var shortfallLabel = String(btn.getAttribute('data-shortfall-label') || '').trim();
    var codeEl = document.getElementById('odPayMetaCode');
    var paidEl = document.getElementById('odPayMetaPaid');
    var dueEl = document.getElementById('odPayMetaDue');
    var shortEl = document.getElementById('odPayMetaShort');
    if (codeEl) codeEl.textContent = code || '—';
    if (paidEl) paidEl.textContent = paid || '—';
    if (dueEl) dueEl.textContent = due || '—';
    if (shortEl) shortEl.textContent = shortfallLabel || '—';
    if (payAmount) {
      payAmount.value = shortfallCents > 0 ? (shortfallCents / 100).toFixed(2) : '';
      setTimeout(function () {
        payAmount.focus();
        payAmount.select();
      }, 30);
    }
    setPayErr('');
    payModal.classList.add('is-open');
    payModal.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
  }

  async function submitPayCompleteModal() {
    if (!activePayBtn || !payModal) return;
    var orderId = activePayBtn.getAttribute('data-order-id');
    var shortfallCents = parseInt(activePayBtn.getAttribute('data-shortfall-cents') || '0', 10) || 0;
    var typed = payAmount ? String(payAmount.value || '').trim() : '';
    if (!typed && shortfallCents <= 0) {
      setPayErr('Enter an amount to pay.');
      return;
    }
    var body = new URLSearchParams();
    body.set('order_id', orderId);
    if (typed) body.set('amount', typed);
    if (paySubmit) paySubmit.disabled = true;
    activePayBtn.disabled = true;
    setPayErr('');
    try {
      var res = await fetch('ajax/shop_order_complete_payment.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body.toString(),
        credentials: 'same-origin'
      });
      var data = await res.json();
      if (data.ok) {
        closePayCompleteModal();
        window.location.reload();
        return;
      }
      setPayErr(data.message || 'Could not update payment.');
    } catch (e) {
      setPayErr('Could not update payment.');
    } finally {
      if (paySubmit) paySubmit.disabled = false;
      if (activePayBtn) activePayBtn.disabled = false;
    }
  }

  if (payModal) {
    var payClose = document.getElementById('odPayCompleteClose');
    var payCancel = document.getElementById('odPayCompleteCancel');
    var payBackdrop = document.getElementById('odPayCompleteBackdrop');
    if (payClose) payClose.addEventListener('click', closePayCompleteModal);
    if (payCancel) payCancel.addEventListener('click', closePayCompleteModal);
    if (payBackdrop) payBackdrop.addEventListener('click', closePayCompleteModal);
    if (paySubmit) paySubmit.addEventListener('click', submitPayCompleteModal);
    if (payAmount) {
      payAmount.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
          e.preventDefault();
          submitPayCompleteModal();
        }
      });
    }
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && payModal.classList.contains('is-open')) {
        closePayCompleteModal();
      }
    });
  }

  document.querySelectorAll('.js-order-cancel').forEach(function(btn){
    btn.addEventListener('click', async function(){
      var orderId = btn.getAttribute('data-order-id');
      if (!window.confirm('Cancel this order? The seller will see it as cancelled.')) return;
      var reason = window.prompt('Why are you cancelling? (optional)', 'Changed mind');
      if (reason === null) return;
      var body = new URLSearchParams();
      body.set('order_id', orderId);
      body.set('reason', reason.trim() || 'Changed mind');
      var res = await fetch('ajax/order_cancel.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: body.toString(), credentials:'same-origin' });
      var data = await res.json();
      window.alert(data.message || (data.ok ? 'Order cancelled.' : 'Failed.'));
      if (data.ok) {
        try {
          if (window.parent && window.parent !== window) {
            window.parent.postMessage({ type: 'msb-order-cancelled', orderId: orderId }, '*');
          }
        } catch (e) {}
        window.location.reload();
      }
    });
  });

  document.querySelectorAll('.js-order-review-toggle').forEach(function(btn){
    btn.addEventListener('click', function(){
      if (moreMenu) moreMenu.classList.remove('is-open');
      var orderId = btn.getAttribute('data-order-id');
      var form = document.getElementById('reviewForm-' + orderId);
      if (form) form.hidden = !form.hidden;
    });
  });

  document.querySelectorAll('.od-review-form').forEach(function(form){
    form.addEventListener('submit', async function(e){
      e.preventDefault();
      var orderId = shell ? shell.getAttribute('data-order-id') : '';
      if (!orderId) return;
      var body = new URLSearchParams();
      body.set('order_id', orderId);
      body.set('rating', form.querySelector('[name="rating"]').value);
      body.set('review_text', form.querySelector('[name="review_text"]').value || '');
      var res = await fetch('ajax/product_review.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: body.toString(), credentials:'same-origin' });
      var data = await res.json();
      window.alert(data.message || (data.ok ? 'Review saved.' : 'Failed.'));
      if (data.ok) form.hidden = true;
    });
  });
})();
</script>
<script>
/* Live sync with the iOS app: reload quietly when this buyer's orders / returns / reviews change elsewhere. */
(function () {
  var SYNC_URL = 'ajax/shop_sync_state.php';
  var watch = ['orders', 'returns', 'reviews'];
  var baseline = null, version = '', pending = false, acceptNext = false, lastInteraction = 0, timer = null;
  ['keydown', 'input', 'pointerdown', 'touchstart'].forEach(function (evt) {
    document.addEventListener(evt, function () { lastInteraction = Date.now(); }, true);
  });
  var nativeFetch = window.fetch.bind(window);
  window.fetch = function (input, init) {
    var url = typeof input === 'string' ? input : ((input && input.url) || '');
    var method = String((init && init.method) || 'GET').toUpperCase();
    var p = nativeFetch(input, init);
    if (method !== 'GET' && url.indexOf('ajax/') !== -1 && url.indexOf(SYNC_URL) === -1) {
      p.then(function () { acceptNext = true; schedule(250); }, function () {});
    }
    return p;
  };
  function busy() {
    if (Date.now() - lastInteraction < 4000) return true;
    if (document.querySelector('dialog[open], form:not([hidden]) textarea:focus, input:focus')) return true;
    return false;
  }
  function reloadWhenIdle() {
    if (document.hidden || busy()) { setTimeout(reloadWhenIdle, 1500); return; }
    location.reload();
  }
  function schedule(ms) { if (timer) clearTimeout(timer); timer = setTimeout(sync, ms); }
  async function sync() {
    timer = null;
    try {
      var res = await nativeFetch(SYNC_URL + (version ? ('?since=' + encodeURIComponent(version)) : ''), { credentials: 'same-origin', cache: 'no-store' });
      var data = await res.json();
      if (data && data.ok) {
        var parts = data.parts || {};
        if (baseline && !acceptNext && data.changed && !pending) {
          if (watch.some(function (k) { return (baseline[k] || '') !== (parts[k] || ''); })) {
            pending = true;
            reloadWhenIdle();
          }
        }
        if (!pending) { baseline = parts; version = String(data.version || ''); }
        acceptNext = false;
      }
    } catch (e) { /* offline */ }
    schedule(document.hidden ? 15000 : 3000);
  }
  sync();
  document.addEventListener('visibilitychange', function () { if (!document.hidden) schedule(0); });
})();
</script>
</body>
</html>
