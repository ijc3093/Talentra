<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/session_user.php';
requireUserLogin();

require_once __DIR__ . '/controller.php';
require_once __DIR__ . '/includes/org_shop.php';
require_once __DIR__ . '/includes/stripe_shop.php';
require_once __DIR__ . '/includes/theme_prefs.php';
require_once __DIR__ . '/includes/buyer_shipping.php';

$controller = new Controller();
$dbh = $controller->pdo();
$meId = (int)($_SESSION['user_id'] ?? $_SESSION['id'] ?? $_SESSION['userid'] ?? 0);
$buyerDoorDefault = buyer_shipping_default_row($dbh, $meId);
$buyerDoorAddressText = $buyerDoorDefault ? buyer_shipping_format_door_text($buyerDoorDefault) : '';
$buyerDoorPhone = $buyerDoorDefault ? trim((string)($buyerDoorDefault['phone'] ?? '')) : '';
if ($buyerDoorPhone === '') {
    $buyerDoorPhone = buyer_shipping_default_phone($dbh, $meId);
}
if ($buyerDoorPhone === '' && $meId > 0) {
    try {
        $stBuyerPhone = $dbh->prepare('SELECT mobile FROM users WHERE id = :id LIMIT 1');
        $stBuyerPhone->execute([':id' => $meId]);
        $buyerDoorPhone = trim((string)($stBuyerPhone->fetchColumn() ?: ''));
    } catch (Throwable $e) {
        $buyerDoorPhone = '';
    }
}
$productId = (int)($_GET['product_id'] ?? 0);
$embed = (string)($_GET['embed'] ?? '') === '1';
$fromCart = (string)($_GET['from_cart'] ?? '') === '1';
$initialQty = max(1, min(99, (int)($_GET['quantity'] ?? 1)));
$profileId = (int)($_GET['profile_id'] ?? 0);
$product = org_shop_get_marketplace_product($dbh, $productId);

if (!function_exists('h')) {
    function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}

$notFound = ($product === null);
$outOfStock = false;
if (!$notFound) {
    $stock = $product['stock_qty'];
    $outOfStock = ($stock !== null && $stock !== '' && (int)$stock <= 0);
}

$title = $notFound ? 'Product' : (string)($product['title'] ?? 'Product');
$priceCents = $notFound ? 0 : (int)($product['price_cents'] ?? 0);
$currency = $notFound ? 'USD' : (string)($product['currency'] ?? 'USD');
$unitPrice = $notFound ? '' : org_shop_format_price($priceCents, $currency);
$publisherId = $notFound ? 0 : (int)($product['publisher_user_id'] ?? 0);
if ($profileId <= 0 && $publisherId > 0) {
    $profileId = $publisherId;
}
$fulfillmentMethod = $notFound ? 'fbm' : strtolower(trim((string)($product['fulfillment_method'] ?? 'fbm')));
if (!in_array($fulfillmentMethod, ['fba', 'fbm'], true)) {
    $fulfillmentMethod = 'fbm';
}
$receiveOptions = $notFound ? ['delivery_enabled' => true, 'pickup_enabled' => false, 'carriers' => [], 'carrier_labels' => [], 'shipping_fee_cents' => 0] : org_shop_product_receive_options($product);
$sellerOrgId = $notFound ? 0 : (int)($product['org_id'] ?? 0);
$sellerPickupDisplay = (!$notFound && $sellerOrgId > 0 && !empty($receiveOptions['pickup_enabled']))
    ? org_shop_seller_pickup_display($dbh, $sellerOrgId)
    : ['text' => '', 'store_name' => '', 'address' => '', 'phone' => '', 'email' => '', 'has_address' => false];
$sellerPickupAddress = trim((string)($sellerPickupDisplay['text'] ?? ''));
if ($sellerPickupAddress === '') {
    $sellerPickupAddress = org_shop_seller_pickup_address_text($dbh, $sellerOrgId);
}
$defaultReceiveOption = !empty($receiveOptions['delivery_enabled']) ? 'home_delivery' : 'pickup';
$deliveryShippingCents = !empty($receiveOptions['delivery_enabled']) ? max(0, (int)($receiveOptions['shipping_fee_cents'] ?? 0)) : 0;
$initialShippingCents = $defaultReceiveOption === 'pickup' ? 0 : $deliveryShippingCents;
$deliveryCarrierHint = !empty($receiveOptions['carrier_labels'])
    ? implode(', ', $receiveOptions['carrier_labels'])
    : 'UPS, FedEx, seller trip, and more';
if (!empty($receiveOptions['delivery_enabled'])) {
    $deliveryCarrierHint .= $deliveryShippingCents > 0
        ? ' · ' . org_shop_format_price($deliveryShippingCents, $currency)
        : ' · Free trip';
}
$cover = $notFound ? '' : org_shop_cover_url((string)($product['cover_image_path'] ?? ''));
$shopStripeEnabled = stripe_shop_is_configured();

/** @return list<array<string, mixed>> */
function shop_buy_door_payment_methods(bool $stripeEnabled): array
{
    // Only list methods that can actually complete checkout today.
    $methods = [
        [
            'id' => 'test_cost',
            'label' => 'Cost $ (test)',
            'logo' => 'manual',
            'logo_text' => 'Cost $',
            'sub' => 'Type the amount below. Pay less than the order total and the order stays Pending — shipping starts only after full payment.',
            'enabled' => true,
            'default' => !$stripeEnabled,
            'badge' => 'TEST',
        ],
    ];

    if ($stripeEnabled) {
        $methods[] = [
            'id' => 'stripe',
            'label' => 'Pay with card',
            'logo' => 'cards',
            'logo_text' => '',
            'sub' => 'Secure checkout powered by Stripe.',
            'enabled' => true,
            'default' => true,
            'show_card_icons' => true,
        ];
    } else {
        $methods[] = [
            'id' => 'manual',
            'label' => 'Pay seller directly',
            'logo' => 'manual',
            'logo_text' => 'Manual',
            'sub' => 'The seller confirms payment after you place the order.',
            'enabled' => true,
        ];
    }

    return $methods;
}

/** @return list<array<string, mixed>> */
function shop_buy_door_initial_saved_cards(): array
{
    // No fake saved cards — wire real vaulted cards before showing any.
    return [];
}

/** @param array<string, mixed> $card */
function shop_buy_door_saved_card_type_label(array $card): string
{
    return ((string)($card['cardType'] ?? 'credit')) === 'debit' ? 'Debit card' : 'Credit card';
}

$paymentMethods = shop_buy_door_payment_methods($shopStripeEnabled);
$initialSavedCards = shop_buy_door_initial_saved_cards();
$defaultPaymentId = '';
foreach ($paymentMethods as $pm) {
    if (!empty($pm['default']) && !empty($pm['enabled'])) {
        $defaultPaymentId = (string)$pm['id'];
        break;
    }
}
if ($defaultPaymentId === '' && $initialSavedCards !== []) {
    $defaultPaymentId = 'saved_card_' . (int)($initialSavedCards[0]['id'] ?? 0);
}
if ($defaultPaymentId === '' && $paymentMethods !== []) {
    $defaultPaymentId = (string)($paymentMethods[0]['id'] ?? '');
}
?>
<!doctype html>
<html <?= app_html_lang_attrs() ?>>
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Buy now</title>
  <?php theme_prefs_print_head_bootstrap($dbh, $meId); ?>
  <link href="./lib/font-awesome/css/font-awesome.css" rel="stylesheet">
  <link href="./lib/Ionicons/css/ionicons.css" rel="stylesheet">
  <link rel="stylesheet" href="./css/shop-page.css?v=7">
  <style>
    body.shop-buy-door{
      margin:0;
      background:var(--shop-card-bg,var(--msb-palette-bg,#fff));
      color:var(--shop-text,var(--msb-palette-text,inherit));
      font-family:inherit;
      overflow:hidden;
    }
    .sbd-shell{display:flex;flex-direction:column;height:100vh;max-height:100vh;box-sizing:border-box;}
    .sbd-head{
      flex-shrink:0;display:flex;flex-direction:column;gap:10px;
      padding:14px 16px 12px;border-bottom:1px solid var(--shop-border,rgba(15,23,42,.08));
    }
    .sbd-head-row{display:flex;align-items:center;justify-content:space-between;gap:12px;}
    .sbd-title{font-size:17px;font-weight:800;margin:0;}
    .sbd-steps{
      display:flex;align-items:flex-start;justify-content:space-between;gap:4px;
      width:100%;margin:0;padding:0;list-style:none;
    }
    .sbd-step{
      flex:1 1 0;min-width:0;display:flex;flex-direction:column;align-items:center;gap:5px;
      position:relative;text-align:center;
    }
    .sbd-step:not(:last-child)::after{
      content:"";position:absolute;top:9px;left:calc(50% + 12px);right:calc(-50% + 12px);
      height:2px;background:var(--shop-border,rgba(15,23,42,.14));z-index:0;
    }
    .sbd-step.is-done:not(:last-child)::after,
    .sbd-step.is-active:not(:last-child)::after{
      background:var(--shop-link,#2563eb);
    }
    .sbd-step-dot{
      position:relative;z-index:1;width:18px;height:18px;border-radius:50%;
      border:2px solid var(--shop-border,rgba(15,23,42,.22));
      background:var(--shop-card-bg,#fff);color:transparent;
      display:inline-flex;align-items:center;justify-content:center;
      font-size:9px;font-weight:800;line-height:1;box-sizing:border-box;
    }
    .sbd-step.is-active .sbd-step-dot{
      border-color:var(--shop-link,#2563eb);background:var(--shop-link,#2563eb);color:#fff;
    }
    .sbd-step.is-done .sbd-step-dot{
      border-color:var(--shop-link,#2563eb);background:var(--shop-link,#2563eb);color:#fff;
    }
    .sbd-step-label{
      font-size:10px;font-weight:700;line-height:1.2;
      color:var(--shop-text-muted,#64748b);max-width:100%;
    }
    .sbd-step.is-active .sbd-step-label{color:var(--shop-link,#2563eb);}
    .sbd-step.is-done .sbd-step-label{color:var(--shop-text,#0f172a);}
    .sbd-close{
      border:1px solid var(--shop-border,rgba(177,188,206,.45));
      background:transparent;color:inherit;width:34px;height:34px;border-radius:50%;
      display:inline-flex;align-items:center;justify-content:center;cursor:pointer;flex-shrink:0;
    }
    .sbd-body{
      flex:1;min-height:0;display:flex;flex-direction:column;
      overflow-x:hidden;overflow-y:auto;
      padding:16px 16px 24px;-webkit-overflow-scrolling:touch;
    }
    .sbd-body.is-success-scroll{overflow-y:auto;padding-bottom:24px;}
    .sbd-form-view,
    .sbd-review-view,
    .sbd-placing-view,
    .sbd-success{flex:0 0 auto;display:flex;flex-direction:column;}
    .sbd-form-view[hidden],
    .sbd-review-view[hidden],
    .sbd-placing-view[hidden],
    .sbd-success[hidden]{display:none !important;}
    .sbd-body.is-success-mode .sbd-form-view,
    .sbd-body.is-success-mode .sbd-review-view,
    .sbd-body.is-success-mode .sbd-placing-view{display:none !important;}
    .sbd-body.is-success-mode .sbd-success{display:flex !important;}
    .sbd-placing-view{
      flex:1;min-height:0;align-items:center;justify-content:center;
      text-align:center;padding:40px 16px;gap:10px;
    }
    .sbd-placing-ic{
      width:48px;height:48px;border-radius:50%;margin:0 auto;
      display:flex;align-items:center;justify-content:center;
      background:rgba(37,99,235,.12);color:#2563eb;font-size:20px;
    }
    .sbd-placing-view h2{font-size:18px;font-weight:850;margin:0;}
    .sbd-placing-view p{font-size:13px;color:var(--shop-text-muted,#64748b);margin:0;max-width:22em;}
    .sbd-review-block{
      margin:0 0 14px;padding:12px 14px;border-radius:10px;
      border:1px solid var(--shop-border,rgba(15,23,42,.12));
      background:var(--shop-card-raised,rgba(15,23,42,.03));
    }
    .sbd-review-block h3{
      margin:0 0 8px;font-size:12px;font-weight:800;letter-spacing:.04em;
      text-transform:uppercase;color:var(--shop-text-muted,#64748b);
    }
    .sbd-review-line{font-size:14px;font-weight:650;line-height:1.4;margin:0 0 4px;}
    .sbd-review-line:last-child{margin-bottom:0;}
    .sbd-review-muted{font-size:12px;font-weight:600;color:var(--shop-text-muted,#64748b);margin:0;}
    .sbd-success{
      flex:1;min-height:0;display:none;flex-direction:column;align-items:center;justify-content:center;
      text-align:center;padding:28px 12px 20px;gap:0;
    }
    .sbd-success-ic{
      width:56px;height:56px;border-radius:50%;margin:0 auto 14px;
      display:flex;align-items:center;justify-content:center;
      background:#dcfce7;color:#166534;font-size:24px;
    }
    .sbd-success h2{font-size:20px;font-weight:850;margin:0 0 10px;}
    .sbd-success-amount{
      font-size:22px;font-weight:850;margin:0 0 4px;letter-spacing:-.02em;color:#15803d;
    }
    .sbd-success-due{
      font-size:12px;font-weight:650;margin:0 0 8px;color:var(--shop-text-muted,#6b7280);line-height:1.35;
    }
    .sbd-success-code{
      font-size:13px;font-weight:700;margin:0 0 10px;opacity:.9;
    }
    .sbd-success-note{
      font-size:13px;color:var(--shop-text-muted,#6b7280);line-height:1.45;margin:0 0 20px;
      max-width:28em;
    }
    .sbd-success-actions{width:100%;max-width:280px;display:flex;flex-direction:column;gap:8px;}
    .sbd-success-actions .sbd-btn{width:100%;}
    .sbd-form-top{flex-shrink:0;}
    .sbd-product{display:flex;gap:12px;align-items:flex-start;margin-bottom:16px;}
    .sbd-thumb{
      width:64px;height:64px;border-radius:8px;overflow:hidden;flex-shrink:0;
      background:var(--shop-card-raised,rgba(15,23,42,.04));
      display:flex;align-items:center;justify-content:center;color:var(--shop-text-muted,#6b7280);
    }
    .sbd-thumb img{width:100%;height:100%;object-fit:cover;}
    .sbd-product-title{font-size:15px;font-weight:700;line-height:1.35;margin:0 0 4px;}
    .sbd-product-price{font-size:14px;font-weight:800;}
    .sbd-product-qty-row{display:flex;align-items:center;gap:8px;margin-top:6px;}
    .sbd-product-qty{font-size:14px;font-weight:800;color:inherit;min-width:5.5em;}
    .sbd-qty-btn{
      width:28px;height:28px;border-radius:8px;border:1px solid var(--shop-border,rgba(15,23,42,.18));
      background:var(--shop-card-raised,rgba(15,23,42,.06));color:inherit;font-size:16px;font-weight:700;
      line-height:1;display:inline-flex;align-items:center;justify-content:center;cursor:pointer;padding:0;
    }
    .sbd-qty-btn:hover{filter:brightness(1.08);}
    .sbd-qty-btn:disabled{opacity:.35;cursor:not-allowed;filter:none;}
    .sbd-receive-row{display:flex;gap:8px;margin:0 0 12px;}
    .sbd-receive-btn{
      flex:1;min-width:0;border:1px solid var(--shop-border,rgba(15,23,42,.16));border-radius:10px;
      background:var(--shop-card-raised,rgba(15,23,42,.04));color:inherit;font:inherit;
      padding:10px 12px;cursor:pointer;text-align:left;
    }
    .sbd-receive-btn.is-active{border-color:rgba(14,165,233,.55);background:rgba(14,165,233,.1);box-shadow:inset 0 0 0 1px rgba(14,165,233,.25);}
    .sbd-receive-btn strong{display:block;font-size:13px;font-weight:800;margin-bottom:2px;}
    .sbd-receive-btn span{display:block;font-size:11px;line-height:1.35;color:var(--shop-text-muted,#6b7280);}
    .sbd-ship-row{
      width:100%;display:flex;align-items:center;gap:10px;margin:0 0 16px;padding:12px 14px;
      border:1px solid var(--shop-border,rgba(15,23,42,.12));border-radius:10px;
      background:var(--shop-card-raised,rgba(15,23,42,.04));cursor:pointer;text-align:left;
      color:inherit;font:inherit;
    }
    .sbd-ship-row.is-pickup{cursor:default;}
    .sbd-ship-row.is-pickup:hover{filter:none;}
    .sbd-ship-row.is-pickup .sbd-ship-chevron{display:none;}
    .sbd-ship-value.is-multiline{white-space:pre-line;font-weight:600;}
    .sbd-pickup-box{
      display:none;margin:-6px 0 16px;padding:12px 14px;
      border:1px solid rgba(14,165,233,.35);border-radius:10px;
      background:rgba(14,165,233,.08);
    }
    .sbd-pickup-box.is-visible{display:block;}
    .sbd-pickup-box-label{
      margin:0 0 4px;font-size:11px;font-weight:800;letter-spacing:.04em;
      text-transform:uppercase;color:var(--shop-text-muted,#0369a1);
    }
    .sbd-pickup-box-text{
      margin:0;font-size:14px;font-weight:600;line-height:1.45;
      white-space:pre-line;color:var(--shop-text,inherit);
    }
    .sbd-pickup-box-note{
      margin:8px 0 0;font-size:12px;line-height:1.4;color:var(--shop-text-muted,#64748b);
    }
    .sbd-summary-line.is-hidden{display:none;}
    .sbd-ship-row:hover{filter:brightness(1.02);}
    .sbd-ship-main{flex:1;min-width:0;}
    .sbd-ship-label{display:block;font-size:12px;font-weight:700;color:var(--shop-text-muted,#6b7280);margin-bottom:2px;}
    .sbd-ship-value{display:block;font-size:14px;font-weight:600;line-height:1.35;color:var(--shop-text,inherit);}
    .sbd-ship-value.is-placeholder{color:var(--shop-text-muted,#6b7280);font-weight:500;}
    .sbd-ship-chevron{font-size:14px;color:var(--shop-text-muted,#6b7280);flex-shrink:0;}
    .sbd-address-popup,
    .sbd-card-popup{
      position:fixed;inset:0;z-index:300;display:none;flex-direction:column;
      background:var(--shop-card-bg,var(--msb-palette-bg,#fff));
      color:var(--shop-text,var(--msb-palette-text,inherit));
    }
    .sbd-address-popup.is-open,
    .sbd-card-popup.is-open{display:flex;}
    .sbd-address-head{
      flex-shrink:0;display:flex;align-items:center;gap:10px;
      padding:14px 16px;border-bottom:1px solid var(--shop-border,rgba(15,23,42,.08));
    }
    .sbd-address-back{
      border:1px solid var(--shop-border,rgba(177,188,206,.45));
      background:transparent;color:inherit;width:34px;height:34px;border-radius:50%;
      display:inline-flex;align-items:center;justify-content:center;cursor:pointer;flex-shrink:0;
    }
    .sbd-address-title{font-size:17px;font-weight:800;margin:0;flex:1;}
    .sbd-address-body{flex:1;min-height:0;overflow-y:auto;padding:16px 16px 24px;-webkit-overflow-scrolling:touch;}
    .sbd-field{margin-bottom:14px;}
    .sbd-field label{display:block;font-size:14px;font-weight:800;margin-bottom:8px;color:var(--shop-text,var(--msb-palette-text,inherit));}
    .sbd-field .sbd-field-label{display:block;font-size:14px;font-weight:800;margin-bottom:8px;color:var(--shop-text,var(--msb-palette-text,inherit));}
    .sbd-field .form-control{
      width:100%;box-sizing:border-box;
      background:var(--shop-input-bg,rgba(15,23,42,.04));
      border:1px solid var(--shop-border-strong,rgba(15,23,42,.14));
      border-radius:10px;color:var(--shop-text,inherit);padding:10px 12px;font-size:14px;
    }
    .sbd-field textarea.form-control{min-height:84px;resize:none;}
    .sbd-address-foot{padding:0 16px 16px;flex-shrink:0;}
    .sbd-total{
      display:flex;align-items:center;justify-content:space-between;gap:10px;
      font-size:14px;font-weight:700;margin:6px 0 14px;padding-top:10px;
      border-top:1px solid var(--shop-border,rgba(15,23,42,.08));
    }
    .sbd-summary{margin:12px 0 10px;}
    .sbd-summary-head{font-size:15px;font-weight:800;margin:0 0 12px;line-height:1.3;}
    .sbd-summary-line{
      display:flex;align-items:baseline;justify-content:space-between;gap:12px;
      font-size:14px;line-height:1.45;padding:3px 0;color:var(--shop-text,var(--msb-palette-text,inherit));
    }
    .sbd-summary-line.is-total{
      font-weight:800;padding-top:12px;margin-top:8px;
      border-top:1px solid var(--shop-border,rgba(15,23,42,.1));
    }
    .sbd-summary-line.is-discount span:last-child{color:#059669;font-weight:700;}
    .sbd-promo-row{
      display:flex;gap:8px;align-items:stretch;margin:8px 0 4px;
    }
    .sbd-promo-row .form-control{
      flex:1;min-width:0;box-sizing:border-box;
      background:var(--shop-input-bg,rgba(15,23,42,.04));
      border:1px solid var(--shop-border-strong,rgba(15,23,42,.14));
      border-radius:10px;color:var(--shop-text,inherit);padding:10px 12px;font-size:14px;
    }
    .sbd-promo-apply{
      flex-shrink:0;border:1px solid var(--shop-border-strong,rgba(15,23,42,.18));
      background:var(--shop-card-bg,transparent);color:inherit;border-radius:10px;
      padding:0 14px;font-size:13px;font-weight:800;cursor:pointer;
    }
    .sbd-promo-apply:hover{filter:brightness(1.03);}
    .sbd-promo-msg{
      margin:0 0 8px;font-size:12px;line-height:1.35;color:var(--shop-text-muted,#6b7280);
    }
    .sbd-promo-msg.is-error{color:#b91c1c;}
    .sbd-promo-msg.is-ok{color:#059669;}
    .sbd-summary-klarna,
    .sbd-summary-promo{
      display:flex;align-items:flex-start;gap:10px;margin-top:14px;
      font-size:12px;line-height:1.45;color:var(--shop-text-muted,#6b7280);
    }
    .sbd-summary-klarna-logo,
    .sbd-summary-promo-logo{
      flex-shrink:0;display:inline-flex;align-items:center;justify-content:center;
      min-width:52px;height:22px;padding:0 8px;border-radius:4px;
      font-size:11px;font-weight:700;
    }
    .sbd-summary-klarna-logo,
    .sbd-summary-promo-logo.is-klarna{background:#ffb3c7;color:#111;}
    .sbd-summary-promo-logo.is-paypal{
      background:#fff;color:#003087;border:1px solid var(--shop-border,rgba(15,23,42,.12));
    }
    .sbd-summary-promo-logo.is-gpay{
      background:#fff;color:#111;border:1px solid var(--shop-border,rgba(15,23,42,.12));
      font-size:9px;font-weight:800;
    }
    .sbd-summary-promo-logo.is-paypal_credit{
      background:#fff;color:#003087;border:1px solid var(--shop-border,rgba(15,23,42,.12));
      font-size:8px;line-height:1.1;text-align:center;
    }
    .sbd-summary-promo.is-hidden{display:none;}
    .sbd-summary-klarna a,
    .sbd-summary-promo a{color:var(--shop-link,#2563eb);text-decoration:underline;}
    .sbd-actions{display:flex;flex-direction:column;gap:10px;padding-bottom:16px;}
    .sbd-btn{
      border:1px solid var(--shop-border,rgba(177,188,206,.45));
      background:var(--shop-btn-outline-bg,transparent);
      color:var(--shop-btn-outline-text,var(--shop-text,inherit));
      font-size:14px;font-weight:700;cursor:pointer;padding:10px 14px;border-radius:999px;
    }
    .sbd-btn-primary{
      background:var(--shop-btn-filled-bg,#3d4a32);
      color:var(--shop-btn-filled-text,#fff);
      border-color:var(--shop-border,rgba(177,188,206,.55));
    }
    .sbd-btn:disabled{opacity:.55;cursor:not-allowed;}
    .sbd-empty{padding:32px 12px;text-align:center;color:var(--shop-text-muted,#6b7280);}
    .sbd-pay-with{
      flex:0 0 auto;display:flex;flex-direction:column;overflow:visible;
      margin:14px 0 0;
      --sbd-pay-text-sm:11px;
      --sbd-pay-text-md:13px;
      --sbd-pay-text-normal:12px;
    }
    .sbd-pay-with-head{font-size:15px;font-weight:800;margin:0 0 8px;line-height:1.3;flex-shrink:0;}
    .sbd-pay-list{
      font-family:inherit;
      font-size:var(--sbd-pay-text-normal);
      font-weight:400;
      line-height:1.4;
    }
    .sbd-pay-scroll{
      flex:0 0 auto;overflow:visible;
      margin:0 -2px;padding:0 2px 2px;
    }
    .sbd-form-bottom{
      flex:0 0 auto;padding-top:12px;margin-top:4px;
      border-top:1px solid var(--shop-border,rgba(15,23,42,.08));
      background:var(--shop-card-bg,var(--msb-palette-bg,#fff));
    }
    .sbd-pay-list{display:flex;flex-direction:column;gap:0;}
    .sbd-pay-option{
      display:flex;align-items:flex-start;gap:10px;padding:10px 0;cursor:pointer;
      border-bottom:1px solid var(--shop-border,rgba(15,23,42,.06));
      position:relative;user-select:none;
    }
    .sbd-pay-option:last-child{border-bottom:none;}
    .sbd-pay-option.is-selected .sbd-pay-radio-ui{
      border-color:var(--shop-link,#2563eb);
      box-shadow:inset 0 0 0 5px var(--shop-link,#2563eb);
    }
    .sbd-pay-option.is-door{cursor:pointer;}
    .sbd-pay-option-saved{align-items:center;}
    .sbd-pay-option-saved .sbd-pay-body{flex:1;min-width:0;}
    .sbd-pay-chevron{
      margin-left:auto;align-self:center;font-size:14px;
      color:var(--shop-text-muted,#6b7280);flex-shrink:0;padding-left:6px;
    }
    .sbd-pay-radio{
      position:absolute;opacity:0;width:1px;height:1px;margin:0;pointer-events:none;
    }
    .sbd-pay-radio-ui{
      width:18px;height:18px;margin-top:3px;flex-shrink:0;border-radius:50%;
      border:2px solid var(--shop-border,rgba(148,163,184,.65));
      background:var(--shop-card-bg,#fff);box-sizing:border-box;
      pointer-events:none;
    }
    .sbd-pay-logo{
      width:46px;height:30px;border:1px solid var(--shop-border,rgba(15,23,42,.14));
      border-radius:4px;display:inline-flex;align-items:center;justify-content:center;
      font-size:10px;font-weight:800;letter-spacing:.02em;flex-shrink:0;overflow:hidden;
      background:var(--shop-card-bg,#fff);color:var(--shop-text,#111);
    }
    .sbd-pay-logo.is-klarna{background:#ffb3c7;border-color:#ffb3c7;color:#111;font-size:11px;font-weight:700;}
    .sbd-pay-logo.is-paypal{background:#fff;color:#003087;font-size:9px;}
    .sbd-pay-logo.is-venmo{background:#008cff;border-color:#008cff;color:#fff;font-size:10px;}
    .sbd-pay-logo.is-visa{background:#fff;color:#1a1f71;font-size:11px;font-weight:900;font-style:italic;}
    .sbd-pay-logo.is-discover{background:#fff;color:#111;font-size:9px;font-weight:800;}
    .sbd-pay-logo.is-diners{background:#fff;color:#111;font-size:9px;font-weight:800;}
    .sbd-pay-logo.is-mastercard{background:#fff;padding:0;display:inline-flex;align-items:center;}
    .sbd-pay-logo.is-mastercard span{display:block;width:12px;height:12px;border-radius:50%;margin:0 -2px;}
    .sbd-pay-logo.is-mastercard .mc-r{background:#eb001b;}
    .sbd-pay-logo.is-mastercard .mc-y{background:#f79e1b;}
    .sbd-pay-logo.is-gpay{font-size:9px;font-weight:700;gap:2px;}
    .sbd-pay-logo.is-paypal_credit{font-size:8px;line-height:1.1;text-align:center;padding:2px;}
    .sbd-pay-logo.is-manual{font-size:10px;font-weight:700;color:var(--shop-text-muted,#6b7280);}
    .sbd-pay-option.is-test-cost{align-items:flex-start;flex-wrap:wrap;}
    .sbd-pay-option.is-test-cost .sbd-pay-body{width:100%;}
    .sbd-test-cost-inline{
      display:none;width:100%;margin:8px 0 2px 0;padding:0;
      grid-column:1 / -1;
    }
    .sbd-pay-option.is-selected .sbd-test-cost-inline{display:block;}
    .sbd-test-cost-label{display:block;font-size:11px;font-weight:800;margin:0 0 4px;}
    .sbd-test-cost-input{
      width:100%;box-sizing:border-box;min-height:40px;padding:8px 10px;border-radius:8px;
      border:1px solid rgba(148,163,184,.45);background:var(--shop-card-bg,#fff);
      color:inherit;font-size:15px;font-weight:700;
    }
    .sbd-test-cost-input:focus{outline:2px solid rgba(34,197,94,.45);border-color:#22c55e;}
    .sbd-test-cost-use{margin-top:5px;border:0;background:transparent;color:inherit;font-size:11px;font-weight:700;text-decoration:underline;cursor:pointer;padding:0;opacity:.8;}
    .sbd-test-cost-use:hover{opacity:1;}
    .sbd-pay-logo.is-cards{border:none;background:transparent;width:auto;height:auto;justify-content:flex-start;gap:4px;padding:0;}
    .sbd-pay-body{flex:1;min-width:0;padding-top:1px;}
    .sbd-pay-label-row{
      display:flex;align-items:center;flex-wrap:wrap;gap:6px;
      font-size:var(--sbd-pay-text-md);font-weight:500;line-height:1.35;
      color:var(--shop-text,var(--msb-palette-text,inherit));
    }
    .sbd-pay-label-row.is-expired{color:#dc2626;}
    .sbd-pay-badge{
      display:inline-flex;align-items:center;padding:1px 6px;border-radius:999px;
      font-size:var(--sbd-pay-text-sm);font-weight:600;letter-spacing:.03em;text-transform:uppercase;
      background:var(--shop-link,#2563eb);color:#fff;
    }
    .sbd-pay-sub{
      font-size:var(--sbd-pay-text-sm);font-weight:400;
      color:var(--shop-text-muted,#6b7280);line-height:1.4;margin-top:3px;
    }
    .sbd-pay-sub a{color:var(--shop-link,#2563eb);text-decoration:underline;}
    .sbd-pay-card-actions{
      display:flex;flex-direction:row;align-items:center;gap:12px;
      margin-left:auto;margin-top:0;flex-shrink:0;padding-left:10px;
    }
    .sbd-pay-card-action{
      border:0;background:transparent;padding:0;
      font-size:var(--sbd-pay-text-sm);font-weight:500;line-height:1.3;
      color:var(--shop-link,#2563eb);cursor:pointer;text-decoration:underline;
      font-family:inherit;
    }
    .sbd-pay-card-action:hover{opacity:.85;}
    .sbd-pay-card-action.is-delete{color:#dc2626;}
    .sbd-pay-card-icons{display:flex;align-items:center;gap:5px;margin-top:6px;flex-wrap:wrap;}
    .sbd-pay-card-icons button{
      display:inline-flex;align-items:center;justify-content:center;
      min-width:28px;height:18px;padding:0 4px;border:1px solid var(--shop-border,rgba(15,23,42,.12));
      border-radius:3px;font-size:9px;font-weight:600;color:var(--shop-text-muted,#6b7280);
      background:transparent;cursor:pointer;font-family:inherit;
    }
    .sbd-pay-card-icons button:hover,
    .sbd-pay-card-icons button:focus{
      border-color:var(--shop-link,#2563eb);color:var(--shop-text,inherit);
    }
    .sbd-card-preview{
      font-size:var(--sbd-pay-text-sm);font-weight:400;line-height:1.4;margin-top:3px;
    }
    .sbd-card-preview.is-placeholder{color:var(--shop-text-muted,#6b7280);}
    .sbd-card-brands{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:14px;}
    .sbd-card-brand-btn{
      display:inline-flex;align-items:center;justify-content:center;
      min-width:44px;height:28px;padding:0 8px;border:1px solid var(--shop-border,rgba(15,23,42,.14));
      border-radius:6px;font-size:10px;font-weight:800;color:var(--shop-text-muted,#6b7280);
      background:var(--shop-input-bg,rgba(15,23,42,.04));cursor:pointer;font:inherit;
    }
    .sbd-card-brand-btn.is-active{
      border-color:var(--shop-link,#2563eb);color:var(--shop-text,inherit);
      box-shadow:inset 0 0 0 1px var(--shop-link,#2563eb);
    }
    .sbd-card-type-row{display:flex;gap:8px;margin-bottom:14px;}
    .sbd-card-type-btn{
      flex:1;display:inline-flex;align-items:center;justify-content:center;
      min-height:36px;padding:0 10px;border:1px solid var(--shop-border,rgba(15,23,42,.14));
      border-radius:10px;font-size:13px;font-weight:700;color:var(--shop-text-muted,#6b7280);
      background:var(--shop-input-bg,rgba(15,23,42,.04));cursor:pointer;font:inherit;
    }
    .sbd-card-type-btn.is-active{
      border-color:var(--shop-link,#2563eb);color:var(--shop-text,inherit);
      box-shadow:inset 0 0 0 1px var(--shop-link,#2563eb);
    }
    .sbd-card-row{display:flex;gap:10px;}
    .sbd-card-row .sbd-field{flex:1;min-width:0;margin-bottom:14px;}
    .sbd-confirm-overlay{
      position:fixed;inset:0;z-index:400;display:none;align-items:center;justify-content:center;
      padding:20px 16px;box-sizing:border-box;
      background:rgba(17,24,39,.48);
      -webkit-backdrop-filter:blur(8px);
      backdrop-filter:blur(8px);
    }
    .sbd-confirm-overlay.is-open{display:flex;}
    .sbd-confirm-dialog{
      position:relative;
      width:100%;max-width:360px;
      border-radius:22px;
      padding:26px 24px 22px;
      background:var(--shop-card-bg,var(--msb-palette-bg,#f7f7f8));
      color:var(--shop-text,var(--msb-palette-text,#111827));
      border:0;
      box-shadow:0 24px 60px rgba(15,23,42,.28);
    }
    .sbd-confirm-close{
      position:absolute;top:14px;right:14px;
      width:32px;height:32px;border:0;border-radius:50%;
      background:transparent;color:var(--shop-text-muted,#6b7280);
      display:inline-flex;align-items:center;justify-content:center;
      cursor:pointer;font-size:22px;line-height:1;padding:0;
    }
    .sbd-confirm-close:hover{color:var(--shop-text,#111827);background:rgba(15,23,42,.06);}
    .sbd-confirm-title{
      margin:0 36px 8px 0;font-size:20px;font-weight:800;line-height:1.25;letter-spacing:-.02em;
      color:var(--shop-text,var(--msb-palette-text,#111827));
    }
    .sbd-confirm-message{
      margin:0 0 16px;font-size:14px;font-weight:400;line-height:1.45;
      color:var(--shop-text-muted,#6b7280);
    }
    .sbd-confirm-message[hidden]{display:none !important;}
    .sbd-confirm-field{margin:0 0 18px;}
    .sbd-confirm-field[hidden]{display:none !important;}
    .sbd-confirm-input{
      width:100%;box-sizing:border-box;min-height:46px;padding:12px 14px;
      border:1px solid rgba(148,163,184,.45);border-radius:12px;
      background:var(--shop-input-bg,var(--msb-palette-input-bg,#fff));
      color:var(--shop-text,inherit);font-size:14px;font-family:inherit;
    }
    .sbd-confirm-input:focus{
      outline:0;border-color:rgba(37,99,235,.55);
      box-shadow:0 0 0 3px rgba(37,99,235,.12);
    }
    .sbd-confirm-input.is-invalid{
      border-color:#f87171;
      box-shadow:0 0 0 3px rgba(248,113,113,.15);
    }
    .sbd-confirm-card-preview{
      display:flex;align-items:center;gap:10px;margin:0 0 16px;padding:10px 12px;
      border:1px solid var(--shop-border,rgba(15,23,42,.1));border-radius:12px;
      background:var(--shop-card-raised,rgba(15,23,42,.04));
    }
    .sbd-confirm-card-preview .sbd-pay-body{padding-top:0;}
    .sbd-confirm-actions{display:flex;gap:10px;}
    .sbd-confirm-actions .sbd-btn{
      flex:1;margin:0;min-height:44px;border-radius:999px;font-size:14px;font-weight:700;
    }
    .sbd-confirm-actions .sbd-btn:not(.sbd-btn-primary):not(.sbd-btn-danger){
      background:#ececef;border-color:transparent;color:#111827;
    }
    .sbd-btn-danger{
      background:#e62e2d;color:#fff;border-color:#e62e2d;
    }
    .sbd-btn-danger:hover{filter:brightness(1.05);}
    .sbd-confirm-actions .sbd-btn-primary{
      background:#e62e2d;border-color:#e62e2d;color:#fff;
    }
  </style>
</head>
<body class="shop-page shop-buy-door<?= $embed ? ' shop-buy-door-embed' : '' ?>">

<div class="sbd-shell" data-checkout-step="1">
  <div class="sbd-head">
    <div class="sbd-head-row">
      <h1 class="sbd-title" id="sbdTitle">Buy now</h1>
      <?php if ($embed): ?>
        <button type="button" class="sbd-close" id="sbdCloseBtn" aria-label="Close"><i class="icon ion-close"></i></button>
      <?php endif; ?>
    </div>
    <?php if (!$notFound && !$outOfStock): ?>
      <ol class="sbd-steps" id="sbdSteps" aria-label="Checkout steps">
        <li class="sbd-step is-active" data-step="1">
          <span class="sbd-step-dot" aria-hidden="true">1</span>
          <span class="sbd-step-label">Buy now</span>
        </li>
        <li class="sbd-step" data-step="2">
          <span class="sbd-step-dot" aria-hidden="true">2</span>
          <span class="sbd-step-label">Review order</span>
        </li>
        <li class="sbd-step" data-step="3">
          <span class="sbd-step-dot" aria-hidden="true">3</span>
          <span class="sbd-step-label">Place order</span>
        </li>
        <li class="sbd-step" data-step="4">
          <span class="sbd-step-dot" aria-hidden="true">4</span>
          <span class="sbd-step-label">Order placed</span>
        </li>
      </ol>
    <?php endif; ?>
  </div>

  <div class="sbd-body">
    <?php if ($notFound || $outOfStock): ?>
      <div class="sbd-empty"><?= $notFound ? 'This product is not available.' : 'This product is out of stock.' ?></div>
    <?php else: ?>
      <div id="sbdFormView" class="sbd-form-view">
        <div class="sbd-form-top">
        <div class="sbd-product">
          <div class="sbd-thumb">
            <?php if ($cover !== ''): ?><img src="<?= h($cover) ?>" alt=""><?php else: ?><i class="icon ion-bag"></i><?php endif; ?>
          </div>
          <div>
            <h2 class="sbd-product-title"><?= h($title) ?></h2>
            <div class="sbd-product-price" id="sbdUnitPrice" data-unit-cents="<?= (int)$priceCents ?>" data-currency="<?= h(strtoupper($currency)) ?>"><?= h($unitPrice) ?></div>
            <div class="sbd-product-qty-row" role="group" aria-label="Quantity">
              <button type="button" class="sbd-qty-btn" id="sbdQtyDec" aria-label="Decrease quantity">−</button>
              <div class="sbd-product-qty" id="sbdProductQty">Quantity <?= (int)$initialQty ?></div>
              <button type="button" class="sbd-qty-btn" id="sbdQtyInc" aria-label="Increase quantity">+</button>
            </div>
          </div>
        </div>

        <?php if (!empty($receiveOptions['delivery_enabled']) || !empty($receiveOptions['pickup_enabled'])): ?>
        <div class="sbd-receive-row" role="radiogroup" aria-label="Receive option">
          <?php if (!empty($receiveOptions['delivery_enabled'])): ?>
            <button type="button" class="sbd-receive-btn<?= $defaultReceiveOption === 'home_delivery' ? ' is-active' : '' ?>" data-receive="home_delivery" id="sbdReceiveDelivery">
              <strong>Delivery</strong>
              <span><?= h($deliveryCarrierHint) ?></span>
            </button>
          <?php endif; ?>
          <?php if (!empty($receiveOptions['pickup_enabled'])): ?>
            <button type="button" class="sbd-receive-btn<?= $defaultReceiveOption === 'pickup' ? ' is-active' : '' ?>" data-receive="pickup" id="sbdReceivePickup">
              <strong>Pick up</strong>
              <span><?= $sellerPickupDisplay['has_address'] || $sellerPickupAddress !== ''
                ? h($sellerPickupDisplay['store_name'] !== '' ? $sellerPickupDisplay['store_name'] : 'Collect at seller’s shop')
                : 'Collect at seller’s shop' ?></span>
            </button>
          <?php endif; ?>
        </div>
        <?php endif; ?>

        <button type="button" class="sbd-ship-row<?= $defaultReceiveOption === 'pickup' ? ' is-pickup' : '' ?>" id="sbdOpenAddressBtn" aria-haspopup="dialog" aria-controls="sbdAddressPopup">
          <span class="sbd-ship-main">
            <span class="sbd-ship-label" id="sbdShipLabel"><?= $defaultReceiveOption === 'pickup' ? 'Pick up at' : 'Ship to' ?></span>
            <span class="sbd-ship-value<?= $defaultReceiveOption === 'pickup' ? ' is-multiline' : ' is-placeholder' ?>" id="sbdShipPreview"><?php
              if ($defaultReceiveOption === 'pickup') {
                  echo $sellerPickupAddress !== '' ? h($sellerPickupAddress) : "Seller's shop";
              } else {
                  echo 'Add delivery address';
              }
            ?></span>
          </span>
          <i class="fa fa-chevron-right sbd-ship-chevron" aria-hidden="true"></i>
        </button>
        <div class="sbd-pickup-box<?= $defaultReceiveOption === 'pickup' ? ' is-visible' : '' ?>" id="sbdPickupBox" aria-live="polite">
          <p class="sbd-pickup-box-label">Seller pickup address</p>
          <p class="sbd-pickup-box-text" id="sbdPickupBoxText"><?= $sellerPickupAddress !== '' ? h($sellerPickupAddress) : "Seller's shop" ?></p>
          <p class="sbd-pickup-box-note" id="sbdPickupBoxNote"><?=
            !empty($sellerPickupDisplay['has_address'])
              ? 'Show this address when you arrive to collect your order.'
              : 'Seller has not added a full street address yet. Contact them if you need directions.'
          ?></p>
        </div>
        </div>

        <section class="sbd-pay-with" aria-labelledby="sbdPayWithHead">
          <h2 class="sbd-pay-with-head" id="sbdPayWithHead">Pay with</h2>
          <div class="sbd-pay-scroll">
          <div class="sbd-pay-list" role="radiogroup" aria-labelledby="sbdPayWithHead">
            <?php foreach ($paymentMethods as $pm):
              $pmId = (string)($pm['id'] ?? '');
              if ($pmId === 'add_card') {
                  foreach ($initialSavedCards as $card):
                      $cardId = (int)($card['id'] ?? 0);
                      $savedMethodId = 'saved_card_' . $cardId;
                      $isSavedDefault = $savedMethodId === $defaultPaymentId;
                      $cardBrand = (string)($card['brand'] ?? 'visa');
              ?>
              <label class="sbd-pay-option sbd-pay-option-saved<?= $isSavedDefault ? ' is-selected' : '' ?>" data-payment-id="<?= h($savedMethodId) ?>" data-saved-card-id="<?= $cardId ?>">
                <input
                  type="radio"
                  name="payment_method"
                  class="sbd-pay-radio"
                  value="<?= h($savedMethodId) ?>"
                  <?= $isSavedDefault ? 'checked' : '' ?>
                >
                <span class="sbd-pay-radio-ui" aria-hidden="true"></span>
                <?php if ($cardBrand === 'mastercard'): ?>
                  <span class="sbd-pay-logo is-mastercard" aria-hidden="true"><span class="mc-r"></span><span class="mc-y"></span></span>
                <?php else: ?>
                  <span class="sbd-pay-logo is-<?= h($cardBrand) ?>" aria-hidden="true"><?= h(strtoupper($cardBrand === 'diners' ? 'DC' : ($cardBrand === 'discover' ? 'DISC' : $cardBrand))) ?></span>
                <?php endif; ?>
                <span class="sbd-pay-body">
                  <span class="sbd-pay-label-row">x-<?= h((string)($card['last4'] ?? '')) ?></span>
                  <div class="sbd-pay-sub"><?= h(shop_buy_door_saved_card_type_label($card)) ?></div>
                </span>
                <span class="sbd-pay-card-actions">
                  <button type="button" class="sbd-pay-card-action" data-card-action="edit">Edit</button>
                  <button type="button" class="sbd-pay-card-action is-delete" data-card-action="delete">Delete</button>
                </span>
              </label>
              <?php
                  endforeach;
              }
              $isDefault = $pmId === $defaultPaymentId;
              $logo = (string)($pm['logo'] ?? '');
              $expired = !empty($pm['expired']);
            ?>
              <label class="sbd-pay-option<?= $isDefault ? ' is-selected' : '' ?><?= $pmId === 'add_card' ? ' is-door' : '' ?><?= $pmId === 'test_cost' ? ' is-test-cost' : '' ?>" data-payment-id="<?= h($pmId) ?>"<?= $pmId === 'add_card' ? ' data-opens-card="1"' : '' ?>>
                <input
                  type="radio"
                  name="payment_method"
                  class="sbd-pay-radio"
                  value="<?= h($pmId) ?>"
                  <?= $isDefault ? 'checked' : '' ?>
                >
                <span class="sbd-pay-radio-ui" aria-hidden="true"></span>
                <?php if ($logo === 'mastercard'): ?>
                  <span class="sbd-pay-logo is-mastercard" aria-hidden="true"><span class="mc-r"></span><span class="mc-y"></span></span>
                <?php elseif ($logo === 'cards'): ?>
                  <span class="sbd-pay-logo is-cards" aria-hidden="true"></span>
                <?php else: ?>
                  <span class="sbd-pay-logo is-<?= h($logo) ?>" aria-hidden="true"><?= h((string)($pm['logo_text'] ?? '')) ?></span>
                <?php endif; ?>
                <span class="sbd-pay-body">
                  <span class="sbd-pay-label-row<?= $expired ? ' is-expired' : '' ?>">
                    <?= h((string)($pm['label'] ?? '')) ?>
                    <?php if (!empty($pm['badge'])): ?>
                      <span class="sbd-pay-badge"><?= h((string)$pm['badge']) ?></span>
                    <?php endif; ?>
                  </span>
                  <?php if (!empty($pm['sub'])): ?>
                    <div class="sbd-pay-sub">
                      <?php if ($pmId === 'paypal_credit'): ?>
                        Apply now. <a href="#" onclick="return false;">See terms</a>
                      <?php else: ?>
                        <?= h((string)$pm['sub']) ?>
                      <?php endif; ?>
                    </div>
                  <?php endif; ?>
                  <?php if ($pmId === 'test_cost'): ?>
                    <div class="sbd-test-cost-inline" id="sbdTestCostBox" onclick="event.stopPropagation();">
                      <span class="sbd-test-cost-label" id="sbdTestCostLabel">Cost $</span>
                      <input class="sbd-test-cost-input" type="text" inputmode="decimal" id="sbdTestCost" name="test_cost" placeholder="0.00" autocomplete="off" aria-labelledby="sbdTestCostLabel" onclick="event.stopPropagation();" onfocus="event.stopPropagation();">
                      <button type="button" class="sbd-test-cost-use" id="sbdTestCostUseTotal">Use order total</button>
                    </div>
                  <?php endif; ?>
                  <?php if (!empty($pm['show_card_icons'])): ?>
                    <div class="sbd-pay-card-icons">
                      <button type="button" class="sbd-open-card-btn" data-card-brand="visa" aria-label="Add Visa card">VISA</button>
                      <button type="button" class="sbd-open-card-btn" data-card-brand="mastercard" aria-label="Add Mastercard">MC</button>
                      <button type="button" class="sbd-open-card-btn" data-card-brand="discover" aria-label="Add Discover card">DISC</button>
                      <button type="button" class="sbd-open-card-btn" data-card-brand="diners" aria-label="Add Diners Club card">DC</button>
                    </div>
                    <div class="sbd-pay-sub sbd-card-preview is-placeholder" id="sbdCardPreview">Add another credit or debit card</div>
                  <?php endif; ?>
                </span>
                <?php if ($pmId === 'add_card'): ?>
                  <i class="fa fa-chevron-right sbd-pay-chevron" aria-hidden="true"></i>
                <?php endif; ?>
              </label>
            <?php endforeach; ?>
          </div>
          </div>
        </section>

        <div class="sbd-form-bottom">
        <section class="sbd-summary" id="sbdSummary" data-shipping-cents="<?= (int)$initialShippingCents ?>" data-delivery-shipping-cents="<?= (int)$deliveryShippingCents ?>" data-tax-rate="<?= h((string)org_shop_sales_tax_rate()) ?>" data-service-fee-cents="<?= (int)org_shop_buyer_service_fee_cents($dbh, $meId) ?>" data-org-id="<?= (int)$sellerOrgId ?>" aria-labelledby="sbdSummaryHead">
          <h2 class="sbd-summary-head" id="sbdSummaryHead">Order Summary</h2>
          <?php if (org_shop_buyer_service_fee_cents($dbh, $meId) <= 0): ?>
            <p class="tx-12" style="margin:0 0 10px;opacity:.8;">Customer Plus member — $0 platform service fee</p>
          <?php endif; ?>
          <div class="sbd-promo-row">
            <input type="text" class="form-control" id="sbdPromoCode" placeholder="Promo / discount code" autocomplete="off" maxlength="40" aria-label="Promo code">
            <button type="button" class="sbd-promo-apply" id="sbdPromoApply">Apply</button>
          </div>
          <p class="sbd-promo-msg" id="sbdPromoMsg" hidden></p>
          <div class="sbd-summary-line">
            <span id="sbdSummaryItemLabel">Item (<?= (int)$initialQty ?>)</span>
            <span id="sbdSummarySubtotal"><?= h($unitPrice) ?></span>
          </div>
          <div class="sbd-summary-line is-discount" id="sbdSummaryDiscountRow">
            <span>Discount</span>
            <span id="sbdSummaryDiscount"><?= h(org_shop_format_price(0, $currency)) ?></span>
          </div>
          <div class="sbd-summary-line<?= $defaultReceiveOption === 'pickup' ? ' is-hidden' : '' ?>" id="sbdSummaryShippingRow">
            <span>Shipping</span>
            <span id="sbdSummaryShipping"><?= $initialShippingCents > 0 ? h(org_shop_format_price($initialShippingCents, $currency)) : 'Free' ?></span>
          </div>
          <div class="sbd-summary-line">
            <span id="sbdSummaryTaxLabel">Tax (<?= h(rtrim(rtrim(number_format(org_shop_sales_tax_rate() * 100, 2, '.', ''), '0'), '.')) ?>%)</span>
            <span id="sbdSummaryTax">—</span>
          </div>
          <div class="sbd-summary-line">
            <span>Service fee</span>
            <span id="sbdSummaryServiceFee"><?= h(org_shop_format_price(org_shop_buyer_service_fee_cents($dbh, $meId), $currency)) ?></span>
          </div>
          <div class="sbd-summary-line is-total">
            <span>Order total</span>
            <span id="sbdTotal">—</span>
          </div>
          <div class="sbd-summary-promo is-hidden" id="sbdPaymentPromo" hidden>
            <span class="sbd-summary-promo-logo" id="sbdPromoLogo" aria-hidden="true"></span>
            <span id="sbdPromoText"></span>
          </div>
        </section>

        <div class="sbd-actions">
          <button type="button" class="sbd-btn sbd-btn-primary" id="sbdContinueReview">Continue to review</button>
          <button type="button" class="sbd-btn" id="sbdCancel">Cancel</button>
        </div>
        </div>
      </div>

      <div id="sbdReviewView" class="sbd-review-view" hidden>
        <div class="sbd-review-block">
          <h3>Item</h3>
          <p class="sbd-review-line" id="sbdReviewTitle"><?= h($title) ?></p>
          <p class="sbd-review-muted" id="sbdReviewQtyPrice">Quantity <?= (int)$initialQty ?> · <?= h($unitPrice) ?></p>
        </div>
        <div class="sbd-review-block">
          <h3>Receive</h3>
          <p class="sbd-review-line" id="sbdReviewReceive">Delivery</p>
          <p class="sbd-review-muted" id="sbdReviewShip">—</p>
        </div>
        <div class="sbd-review-block">
          <h3>Payment</h3>
          <p class="sbd-review-line" id="sbdReviewPay">—</p>
        </div>
        <div class="sbd-review-block">
          <h3>Order total</h3>
          <p class="sbd-review-line" id="sbdReviewTotal">—</p>
          <p class="sbd-review-muted" id="sbdReviewBreakdown">Includes tax and fees shown on the previous step.</p>
        </div>
        <div class="sbd-actions">
          <button type="button" class="sbd-btn sbd-btn-primary" id="sbdSubmit">Place order</button>
          <button type="button" class="sbd-btn" id="sbdBackToBuy">Back</button>
        </div>
      </div>

      <div id="sbdPlacingView" class="sbd-placing-view" hidden>
        <div class="sbd-placing-ic" aria-hidden="true"><i class="fa fa-spinner fa-spin"></i></div>
        <h2>Placing your order…</h2>
        <p>Please wait while we confirm payment and create your order.</p>
      </div>

      <div class="sbd-success" id="sbdSuccess" hidden>
        <div class="sbd-success-ic"><i class="fa fa-check"></i></div>
        <h2>Order placed</h2>
        <p class="sbd-success-amount" id="sbdSuccessAmount" hidden></p>
        <p class="sbd-success-due" id="sbdSuccessDue" hidden></p>
        <p class="sbd-success-code" id="sbdSuccessCode" hidden></p>
        <p class="sbd-success-note" id="sbdSuccessMsg"></p>
        <div class="sbd-success-actions">
          <button type="button" class="sbd-btn sbd-btn-primary" id="sbdViewOrder">View order details</button>
          <button type="button" class="sbd-btn" id="sbdDone">Done</button>
        </div>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php if (!$notFound && !$outOfStock): ?>
<div class="sbd-address-popup" id="sbdAddressPopup" role="dialog" aria-modal="true" aria-labelledby="sbdAddressTitle" aria-hidden="true">
  <div class="sbd-address-head">
    <button type="button" class="sbd-address-back" id="sbdAddressBack" aria-label="Back"><i class="icon ion-ios-arrow-left"></i></button>
    <h2 class="sbd-address-title" id="sbdAddressTitle">Delivery</h2>
  </div>
  <div class="sbd-address-body">
    <div class="sbd-field">
      <label for="sbdQty">Quantity</label>
      <input type="number" class="form-control" id="sbdQty" min="1" max="99" value="<?= (int)$initialQty ?>">
    </div>
    <div class="sbd-field">
      <label for="sbdAddress">Delivery address</label>
      <textarea class="form-control" id="sbdAddress" rows="3" placeholder="Street, city, state, zip"><?= h($buyerDoorAddressText) ?></textarea>
    </div>
    <div class="sbd-field">
      <label for="sbdPhone">Phone</label>
      <input type="text" class="form-control" id="sbdPhone" placeholder="Optional" value="<?= h($buyerDoorPhone) ?>">
    </div>
    <div class="sbd-field">
      <label for="sbdNotes">Notes for seller</label>
      <textarea class="form-control" id="sbdNotes" rows="3" placeholder="Optional"></textarea>
    </div>
  </div>
  <div class="sbd-address-foot">
    <button type="button" class="sbd-btn sbd-btn-primary" id="sbdAddressDone" style="width:100%;">Done</button>
  </div>
</div>

<div class="sbd-card-popup" id="sbdCardPopup" role="dialog" aria-modal="true" aria-labelledby="sbdCardTitle" aria-hidden="true">
  <div class="sbd-address-head">
    <button type="button" class="sbd-address-back" id="sbdCardBack" aria-label="Back"><i class="icon ion-ios-arrow-left"></i></button>
    <h2 class="sbd-address-title" id="sbdCardTitle">Add card</h2>
  </div>
  <div class="sbd-address-body">
    <div class="sbd-card-brands" role="group" aria-label="Card type">
      <button type="button" class="sbd-card-brand-btn" data-card-brand="visa">VISA</button>
      <button type="button" class="sbd-card-brand-btn" data-card-brand="mastercard">MC</button>
      <button type="button" class="sbd-card-brand-btn" data-card-brand="discover">DISC</button>
      <button type="button" class="sbd-card-brand-btn" data-card-brand="diners">DC</button>
    </div>
    <div class="sbd-field">
      <span class="sbd-field-label">Card type</span>
      <div class="sbd-card-type-row" role="radiogroup" aria-label="Credit or debit">
        <button type="button" class="sbd-card-type-btn is-active" data-card-type="credit">Credit</button>
        <button type="button" class="sbd-card-type-btn" data-card-type="debit">Debit</button>
      </div>
    </div>
    <div class="sbd-field">
      <label for="sbdCardNumber">Card number</label>
      <input type="text" class="form-control" id="sbdCardNumber" inputmode="numeric" autocomplete="cc-number" placeholder="1234 5678 9012 3456">
    </div>
    <div class="sbd-card-row">
      <div class="sbd-field">
        <label for="sbdCardExpiry">Expiry</label>
        <input type="text" class="form-control" id="sbdCardExpiry" inputmode="numeric" autocomplete="cc-exp" placeholder="MM/YY">
      </div>
      <div class="sbd-field">
        <label for="sbdCardCvc">CVV</label>
        <input type="text" class="form-control" id="sbdCardCvc" inputmode="numeric" autocomplete="cc-csc" placeholder="123">
      </div>
    </div>
    <div class="sbd-field">
      <label for="sbdCardName">Name on card</label>
      <input type="text" class="form-control" id="sbdCardName" autocomplete="cc-name" placeholder="Full name">
    </div>
  </div>
  <div class="sbd-address-foot">
    <button type="button" class="sbd-btn sbd-btn-primary" id="sbdCardDone" style="width:100%;">Done</button>
  </div>
</div>

<div class="sbd-confirm-overlay" id="sbdConfirmOverlay" role="dialog" aria-modal="true" aria-labelledby="sbdConfirmTitle" aria-hidden="true">
  <div class="sbd-confirm-dialog">
    <button type="button" class="sbd-confirm-close" id="sbdConfirmClose" aria-label="Close">&times;</button>
    <h3 class="sbd-confirm-title" id="sbdConfirmTitle">Remove card?</h3>
    <p class="sbd-confirm-message" id="sbdConfirmMessage">Remove this card from your payment methods?</p>
    <div class="sbd-confirm-field" id="sbdConfirmFieldWrap" hidden>
      <input type="text" class="sbd-confirm-input" id="sbdConfirmInput" placeholder="" autocomplete="street-address">
    </div>
    <div class="sbd-confirm-card-preview" id="sbdConfirmCardPreview" hidden></div>
    <div class="sbd-confirm-actions">
      <button type="button" class="sbd-btn" id="sbdConfirmCancel">Cancel</button>
      <button type="button" class="sbd-btn sbd-btn-danger" id="sbdConfirmOk">Remove</button>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if (!$notFound && !$outOfStock): ?>
<script>
(function(){
  var productId = <?= (int)$productId ?>;
  var profileId = <?= (int)$profileId ?>;
  var fulfillmentMethod = <?= json_encode($fulfillmentMethod) ?>;
  var receiveOption = <?= json_encode($defaultReceiveOption) ?>;
  var pickupEnabled = <?= !empty($receiveOptions['pickup_enabled']) ? 'true' : 'false' ?>;
  var deliveryEnabled = <?= !empty($receiveOptions['delivery_enabled']) ? 'true' : 'false' ?>;
  var sellerPickupText = <?= json_encode($sellerPickupAddress !== '' ? $sellerPickupAddress : "Seller's shop", JSON_UNESCAPED_UNICODE) ?>;
  var sellerPickupHasAddress = <?= !empty($sellerPickupDisplay['has_address']) ? 'true' : 'false' ?>;
  var deliveryShippingCents = <?= (int)$deliveryShippingCents ?>;
  var priceEl = document.getElementById('sbdUnitPrice');
  var qtyEl = document.getElementById('sbdQty');
  var addressPopup = document.getElementById('sbdAddressPopup');
  var openAddressBtn = document.getElementById('sbdOpenAddressBtn');
  var shipLabelEl = document.getElementById('sbdShipLabel');
  var shipPreview = document.getElementById('sbdShipPreview');
  var pickupBox = document.getElementById('sbdPickupBox');
  var pickupBoxText = document.getElementById('sbdPickupBoxText');
  var pickupBoxNote = document.getElementById('sbdPickupBoxNote');
  var summaryShippingRow = document.getElementById('sbdSummaryShippingRow');
  var addressBackBtn = document.getElementById('sbdAddressBack');
  var addressDoneBtn = document.getElementById('sbdAddressDone');
  var cardPopup = document.getElementById('sbdCardPopup');
  var cardBackBtn = document.getElementById('sbdCardBack');
  var cardDoneBtn = document.getElementById('sbdCardDone');
  var cardTitleEl = document.getElementById('sbdCardTitle');
  var cardNumberEl = document.getElementById('sbdCardNumber');
  var cardExpiryEl = document.getElementById('sbdCardExpiry');
  var cardCvcEl = document.getElementById('sbdCardCvc');
  var cardNameEl = document.getElementById('sbdCardName');
  var confirmOverlay = document.getElementById('sbdConfirmOverlay');
  var confirmTitleEl = document.getElementById('sbdConfirmTitle');
  var confirmMessageEl = document.getElementById('sbdConfirmMessage');
  var confirmCardPreviewEl = document.getElementById('sbdConfirmCardPreview');
  var confirmFieldWrap = document.getElementById('sbdConfirmFieldWrap');
  var confirmInputEl = document.getElementById('sbdConfirmInput');
  var confirmCancelBtn = document.getElementById('sbdConfirmCancel');
  var confirmOkBtn = document.getElementById('sbdConfirmOk');
  var confirmCloseBtn = document.getElementById('sbdConfirmClose');
  var pendingConfirmAction = null;
  var addCardRow = document.querySelector('.sbd-pay-option[data-payment-id="add_card"]');
  var payListEl = document.querySelector('.sbd-pay-list');
  var savedCards = <?= json_encode($initialSavedCards, JSON_UNESCAPED_UNICODE) ?>;
  var savedCardSeq = savedCards.reduce(function(max, card){
    var id = parseInt(String(card && card.id || '0'), 10);
    return id > max ? id : max;
  }, 0);
  var selectedCardType = 'credit';
  var editingCardId = 0;
  var totalEl = document.getElementById('sbdTotal');
  var summaryEl = document.getElementById('sbdSummary');
  var summaryItemLabel = document.getElementById('sbdSummaryItemLabel');
  var summarySubtotal = document.getElementById('sbdSummarySubtotal');
  var summaryShipping = document.getElementById('sbdSummaryShipping');
  var summaryTaxLabel = document.getElementById('sbdSummaryTaxLabel');
  var summaryTax = document.getElementById('sbdSummaryTax');
  var summaryServiceFee = document.getElementById('sbdSummaryServiceFee');
  var summaryDiscountRow = document.getElementById('sbdSummaryDiscountRow');
  var summaryDiscount = document.getElementById('sbdSummaryDiscount');
  var promoCodeInput = document.getElementById('sbdPromoCode');
  var promoApplyBtn = document.getElementById('sbdPromoApply');
  var promoMsgEl = document.getElementById('sbdPromoMsg');
  var appliedPromoCode = '';
  var appliedDiscountCents = 0;
  var lastAutoFilledCostCents = null;
  var klarnaMonthlyEl = document.getElementById('sbdKlarnaMonthly');
  var promoLogo = document.getElementById('sbdPromoLogo');
  var promoText = document.getElementById('sbdPromoText');
  var submitBtn = document.getElementById('sbdSubmit');
  var continueReviewBtn = document.getElementById('sbdContinueReview');
  var backToBuyBtn = document.getElementById('sbdBackToBuy');
  var cancelBtn = document.getElementById('sbdCancel');
  var closeBtn = document.getElementById('sbdCloseBtn');
  var formView = document.getElementById('sbdFormView');
  var reviewView = document.getElementById('sbdReviewView');
  var placingView = document.getElementById('sbdPlacingView');
  var successView = document.getElementById('sbdSuccess');
  var bodyEl = document.querySelector('.sbd-body');
  var shellEl = document.querySelector('.sbd-shell');
  var titleEl = document.getElementById('sbdTitle');
  var stepsEl = document.getElementById('sbdSteps');
  var reviewTitle = document.getElementById('sbdReviewTitle');
  var reviewQtyPrice = document.getElementById('sbdReviewQtyPrice');
  var reviewReceive = document.getElementById('sbdReviewReceive');
  var reviewShip = document.getElementById('sbdReviewShip');
  var reviewPay = document.getElementById('sbdReviewPay');
  var reviewTotal = document.getElementById('sbdReviewTotal');
  var reviewBreakdown = document.getElementById('sbdReviewBreakdown');
  var successMsg = document.getElementById('sbdSuccessMsg');
  var successAmount = document.getElementById('sbdSuccessAmount');
  var successDue = document.getElementById('sbdSuccessDue');
  var successCode = document.getElementById('sbdSuccessCode');
  var viewOrderBtn = document.getElementById('sbdViewOrder');
  var doneBtn = document.getElementById('sbdDone');
  var testCostBox = document.getElementById('sbdTestCostBox');
  var testCostInput = document.getElementById('sbdTestCost');
  var testCostUseBtn = document.getElementById('sbdTestCostUseTotal');
  var unitCents = priceEl ? parseInt(priceEl.getAttribute('data-unit-cents') || '0', 10) : 0;
  var currency = priceEl ? String(priceEl.getAttribute('data-currency') || 'USD').toUpperCase() : 'USD';
  var lastOrderId = 0;
  var fromCart = <?= $fromCart ? 'true' : 'false' ?>;
  var buySucceeded = false;
  var checkoutStep = 1;
  var stepTitles = {
    1: 'Buy now',
    2: 'Review order',
    3: 'Place order',
    4: 'Order placed'
  };

  function setCheckoutStep(step){
    checkoutStep = Math.max(1, Math.min(4, parseInt(String(step || 1), 10) || 1));
    if (shellEl) shellEl.setAttribute('data-checkout-step', String(checkoutStep));
    if (titleEl) titleEl.textContent = stepTitles[checkoutStep] || 'Buy now';
    if (stepsEl) {
      Array.prototype.slice.call(stepsEl.querySelectorAll('[data-step]')).forEach(function(el){
        var n = parseInt(el.getAttribute('data-step') || '0', 10) || 0;
        el.classList.toggle('is-active', n === checkoutStep);
        el.classList.toggle('is-done', n < checkoutStep);
      });
    }
    if (bodyEl) {
      bodyEl.classList.toggle('is-success-mode', checkoutStep === 4);
      bodyEl.classList.toggle('is-success-scroll', checkoutStep === 4);
    }
    if (formView) formView.hidden = checkoutStep !== 1;
    if (reviewView) reviewView.hidden = checkoutStep !== 2;
    if (placingView) placingView.hidden = checkoutStep !== 3;
    if (successView) successView.hidden = checkoutStep !== 4;
  }

  function fillReviewPanel(){
    var qty = clampQty(qtyEl ? qtyEl.value : 1);
    if (reviewTitle) reviewTitle.textContent = <?= json_encode($title) ?>;
    if (reviewQtyPrice) {
      reviewQtyPrice.textContent = 'Quantity ' + qty + ' · ' + formatMoney(unitCents);
    }
    if (reviewReceive) {
      reviewReceive.textContent = receiveOption === 'pickup' ? 'Pick up' : 'Delivery';
    }
    if (reviewShip) {
      if (receiveOption === 'pickup') {
        reviewShip.textContent = String(sellerPickupText || "Seller's shop");
      } else {
        var address = (document.getElementById('sbdAddress') ? document.getElementById('sbdAddress').value : '').trim();
        reviewShip.textContent = address !== '' ? address : 'Delivery address needed';
      }
    }
    if (reviewPay) {
      var payId = getSelectedPaymentMethodId();
      var payLabel = 'Payment';
      var selectedOpt = document.querySelector('.sbd-pay-option.is-selected .sbd-pay-label-row');
      if (selectedOpt) {
        payLabel = String(selectedOpt.textContent || '').replace(/\s+/g, ' ').trim() || payLabel;
      }
      if (payId === 'test_cost' && testCostInput) {
        var cost = String(testCostInput.value || '').trim();
        if (cost) payLabel += ' · $' + cost;
      }
      reviewPay.textContent = payLabel;
    }
    if (reviewTotal && totalEl) {
      var orderTotalLabel = String(totalEl.textContent || '—').trim() || '—';
      var payIdForTotal = getSelectedPaymentMethodId();
      if (payIdForTotal === 'test_cost' && testCostInput) {
        var paidCentsReview = parseTestCostCents(testCostInput.value);
        var orderTotalCents = typeof getOrderTotalCents === 'function' ? getOrderTotalCents() : 0;
        if (paidCentsReview > 0) {
          reviewTotal.textContent = formatMoney(paidCentsReview) + ' sent';
          if (reviewBreakdown) {
            if (orderTotalCents > 0 && paidCentsReview < orderTotalCents) {
              reviewBreakdown.textContent = 'Order total ' + formatMoney(orderTotalCents)
                + ' · short ' + formatMoney(orderTotalCents - paidCentsReview)
                + ' — shipping waits until fully paid.';
            } else if (orderTotalCents > 0 && paidCentsReview >= orderTotalCents) {
              reviewBreakdown.textContent = 'Covers full order total ' + formatMoney(orderTotalCents) + '.';
            } else {
              reviewBreakdown.textContent = 'Amount you are sending to the seller.';
            }
          }
        } else {
          reviewTotal.textContent = orderTotalLabel;
          if (reviewBreakdown) {
            reviewBreakdown.textContent = 'Includes tax and fees shown on the previous step.';
          }
        }
      } else {
        reviewTotal.textContent = orderTotalLabel;
        if (reviewBreakdown) {
          reviewBreakdown.textContent = 'Includes tax and fees shown on the previous step.';
        }
      }
    }
  }

  function continueToReview(){
    var addressEl = document.getElementById('sbdAddress');
    var addressVal = (addressEl ? addressEl.value : '').trim();
    if (receiveOption !== 'pickup' && addressVal === '') {
      showBuyPrompt({
        title: 'Add delivery address',
        message: 'Type your shipping address so we can deliver this order.',
        showInput: true,
        inputPlaceholder: 'Street, city, ZIP',
        confirmLabel: 'Save',
        cancelLabel: 'Cancel',
        onConfirm: function(typed){
          if (addressEl) addressEl.value = typed;
          closeConfirmDialog();
          updateShipPreview();
          window.setTimeout(continueToReview, 0);
        }
      });
      return;
    }
    if (getSelectedPaymentMethodId() === 'add_card') {
      showBuyPrompt({
        title: 'Add a card',
        message: 'Add a payment card before reviewing this order.',
        confirmLabel: 'Add card',
        cancelLabel: 'Cancel',
        onConfirm: function(){
          closeConfirmDialog();
          openCardPopup('visa');
        }
      });
      return;
    }
    var payMethodId = getSelectedPaymentMethodId();
    if (payMethodId === 'test_cost') {
      var testCostCents = parseTestCostCents(testCostInput ? testCostInput.value : '');
      if (testCostCents <= 0) {
        showBuyPrompt({
          title: 'Enter Cost $',
          message: 'Enter a Cost $ amount before reviewing this order.',
          showInput: true,
          inputPlaceholder: '0.00',
          inputValue: testCostInput ? String(testCostInput.value || '').trim() : '',
          confirmLabel: 'Continue',
          cancelLabel: 'Cancel',
          onConfirm: function(typed){
            selectPaymentMethod('test_cost');
            if (testCostInput) {
              testCostInput.value = typed;
              try { testCostInput.dispatchEvent(new Event('input', { bubbles: true })); } catch (e) {}
            }
            closeConfirmDialog();
            window.setTimeout(continueToReview, 0);
          }
        });
        return;
      }
    }
    fillReviewPanel();
    setCheckoutStep(2);
    try { if (bodyEl) bodyEl.scrollTop = 0; } catch (e) {}
  }

  function closeDoor(){
    try {
      if (window.parent && window.parent !== window) {
        if (buySucceeded) {
          window.parent.postMessage({
            type: 'msb-shop-buy-success',
            order_id: lastOrderId,
            from_cart: fromCart
          }, '*');
        }
        window.parent.postMessage({ type: 'msb-live-right-door-close' }, '*');
        return;
      }
    } catch (e) {}
    if (fromCart && buySucceeded) {
      window.location.href = 'cart.php';
      return;
    }
    if (window.history.length > 1) window.history.back();
  }

  function clampQty(v){
    return Math.max(1, Math.min(99, parseInt(String(v || '1'), 10) || 1));
  }

  function formatMoney(cents){
    var amount = (Math.max(0, cents) / 100).toFixed(2);
    if (currency === 'USD') return '$' + amount;
    return amount + ' ' + currency;
  }

  function effectiveShippingCents(){
    return receiveOption === 'pickup' ? 0 : Math.max(0, deliveryShippingCents || 0);
  }

  function updateShipPreview(){
    if (!shipPreview) return;
    var qty = clampQty(qtyEl ? qtyEl.value : 1);
    var qtyPrefix = qty === 1 ? 'Qty 1 · ' : 'Qty ' + qty + ' · ';
    if (receiveOption === 'pickup') {
      shipPreview.textContent = String(sellerPickupText || "Seller's shop");
      shipPreview.classList.remove('is-placeholder');
      shipPreview.classList.add('is-multiline');
      if (shipLabelEl) shipLabelEl.textContent = 'Pick up at';
      if (openAddressBtn) openAddressBtn.classList.add('is-pickup');
      if (pickupBox) pickupBox.classList.add('is-visible');
      if (pickupBoxText) pickupBoxText.textContent = String(sellerPickupText || "Seller's shop");
      if (pickupBoxNote) {
        pickupBoxNote.textContent = sellerPickupHasAddress
          ? 'Show this address when you arrive to collect your order.'
          : 'Seller has not added a full street address yet. Contact them if you need directions.';
      }
      return;
    }
    if (shipLabelEl) shipLabelEl.textContent = 'Ship to';
    if (openAddressBtn) openAddressBtn.classList.remove('is-pickup');
    if (pickupBox) pickupBox.classList.remove('is-visible');
    shipPreview.classList.remove('is-multiline');
    var address = (document.getElementById('sbdAddress') ? document.getElementById('sbdAddress').value : '').trim();
    var firstLine = address.split(/\r?\n/).map(function(l){ return l.trim(); }).filter(Boolean)[0] || '';
    if (firstLine === '') {
      shipPreview.textContent = qty === 1 ? 'Add delivery address · Qty 1' : 'Add delivery address · Qty ' + qty;
      shipPreview.classList.add('is-placeholder');
      return;
    }
    shipPreview.textContent = qtyPrefix + firstLine;
    shipPreview.classList.remove('is-placeholder');
  }

  function setReceiveOption(next){
    if (next === 'pickup' && !pickupEnabled) return;
    if (next === 'home_delivery' && !deliveryEnabled) return;
    receiveOption = next;
    document.querySelectorAll('.sbd-receive-btn').forEach(function(btn){
      btn.classList.toggle('is-active', btn.getAttribute('data-receive') === receiveOption);
    });
    if (summaryEl) summaryEl.setAttribute('data-shipping-cents', String(effectiveShippingCents()));
    if (summaryShippingRow) {
      summaryShippingRow.classList.toggle('is-hidden', receiveOption === 'pickup');
    }
    updateShipPreview();
    updateTotal();
    refreshAppliedPromoIfNeeded();
  }

  function openAddressPopup(){
    if (receiveOption === 'pickup') return;
    if (!addressPopup) return;
    addressPopup.classList.add('is-open');
    addressPopup.setAttribute('aria-hidden', 'false');
  }

  function closeAddressPopup(save){
    if (!addressPopup) return;
    if (save) {
      updateShipPreview();
      updateTotal();
    }
    addressPopup.classList.remove('is-open');
    addressPopup.setAttribute('aria-hidden', 'true');
    if (openAddressBtn) openAddressBtn.focus();
  }

  var cardBrandLabels = {
    visa: 'Visa',
    mastercard: 'Mastercard',
    discover: 'Discover',
    diners: 'Diners Club'
  };
  var cardBrandLogoText = {
    visa: 'VISA',
    mastercard: 'MC',
    discover: 'DISC',
    diners: 'DC'
  };

  function escapeHtml(value){
    return String(value || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/"/g, '&quot;');
  }

  function isSavedCardMethod(methodId){
    return String(methodId || '').indexOf('saved_card_') === 0;
  }

  function syncCardTypeButtons(cardType){
    document.querySelectorAll('.sbd-card-type-btn').forEach(function(btn){
      btn.classList.toggle('is-active', btn.getAttribute('data-card-type') === cardType);
    });
  }

  function setCardType(cardType){
    selectedCardType = (cardType === 'debit') ? 'debit' : 'credit';
    syncCardTypeButtons(selectedCardType);
    if (cardPopup) cardPopup.setAttribute('data-selected-type', selectedCardType);
  }

  function findSavedCard(cardId){
    var id = parseInt(String(cardId || '0'), 10);
    if (!id) return null;
    for (var i = 0; i < savedCards.length; i++) {
      if (savedCards[i].id === id) return savedCards[i];
    }
    return null;
  }

  function buildSavedCardLogoHtml(brand){
    if (brand === 'mastercard') {
      return '<span class="sbd-pay-logo is-mastercard" aria-hidden="true"><span class="mc-r"></span><span class="mc-y"></span></span>';
    }
    var logoClass = cardBrandLogoText[brand] ? brand : 'visa';
    var logoText = cardBrandLogoText[brand] || 'CARD';
    return '<span class="sbd-pay-logo is-' + escapeHtml(logoClass) + '" aria-hidden="true">' + escapeHtml(logoText) + '</span>';
  }

  function createSavedCardRow(card){
    var methodId = 'saved_card_' + card.id;
    var row = document.createElement('label');
    row.className = 'sbd-pay-option sbd-pay-option-saved';
    row.setAttribute('data-payment-id', methodId);
    row.setAttribute('data-saved-card-id', String(card.id));
    row.innerHTML =
      '<input type="radio" name="payment_method" class="sbd-pay-radio" value="' + escapeHtml(methodId) + '">' +
      '<span class="sbd-pay-radio-ui" aria-hidden="true"></span>' +
      buildSavedCardLogoHtml(card.brand) +
      '<span class="sbd-pay-body">' +
        '<span class="sbd-pay-label-row">x-' + escapeHtml(card.last4) + '</span>' +
        '<div class="sbd-pay-sub">' + escapeHtml(card.cardType === 'debit' ? 'Debit card' : 'Credit card') + '</div>' +
      '</span>' +
      '<span class="sbd-pay-card-actions">' +
        '<button type="button" class="sbd-pay-card-action" data-card-action="edit">Edit</button>' +
        '<button type="button" class="sbd-pay-card-action is-delete" data-card-action="delete">Delete</button>' +
      '</span>';
    return row;
  }

  function updateSavedCardRowDom(card){
    if (!payListEl || !card) return null;
    var row = payListEl.querySelector('.sbd-pay-option[data-saved-card-id="' + card.id + '"]');
    if (!row) return null;
    var wasSelected = row.classList.contains('is-selected');
    var newRow = createSavedCardRow(card);
    row.parentNode.replaceChild(newRow, row);
    if (wasSelected) {
      selectPaymentMethod(newRow.getAttribute('data-payment-id') || '');
    }
    return newRow;
  }

  function closeConfirmDialog(){
    pendingConfirmAction = null;
    if (!confirmOverlay) return;
    confirmOverlay.classList.remove('is-open');
    confirmOverlay.setAttribute('aria-hidden', 'true');
    if (confirmCardPreviewEl) {
      confirmCardPreviewEl.hidden = true;
      confirmCardPreviewEl.innerHTML = '';
    }
    if (confirmFieldWrap) confirmFieldWrap.hidden = true;
    if (confirmInputEl) {
      confirmInputEl.value = '';
      confirmInputEl.classList.remove('is-invalid');
      confirmInputEl.placeholder = '';
    }
  }

  function openConfirmDialog(opts){
    if (!confirmOverlay) return;
    opts = opts || {};
    pendingConfirmAction = typeof opts.onConfirm === 'function' ? opts.onConfirm : null;
    if (confirmTitleEl) confirmTitleEl.textContent = opts.title || 'Are you sure?';
    if (confirmMessageEl) {
      confirmMessageEl.textContent = opts.message || '';
      confirmMessageEl.hidden = !(opts.message || '');
    }
    if (confirmOkBtn) confirmOkBtn.textContent = opts.confirmLabel || 'OK';
    if (confirmCancelBtn) confirmCancelBtn.textContent = opts.cancelLabel || 'Cancel';
    if (confirmOkBtn) {
      var isDanger = opts.destructive === true;
      confirmOkBtn.classList.toggle('sbd-btn-danger', isDanger);
      confirmOkBtn.classList.toggle('sbd-btn-primary', !isDanger);
    }
    if (confirmFieldWrap && confirmInputEl) {
      if (opts.showInput) {
        confirmFieldWrap.hidden = false;
        confirmInputEl.placeholder = opts.inputPlaceholder || '';
        confirmInputEl.value = opts.inputValue || '';
        confirmInputEl.classList.remove('is-invalid');
      } else {
        confirmFieldWrap.hidden = true;
        confirmInputEl.value = '';
        confirmInputEl.classList.remove('is-invalid');
      }
    }
    if (confirmCardPreviewEl) {
      if (opts.cardPreviewHtml) {
        confirmCardPreviewEl.innerHTML = opts.cardPreviewHtml;
        confirmCardPreviewEl.hidden = false;
      } else {
        confirmCardPreviewEl.hidden = true;
        confirmCardPreviewEl.innerHTML = '';
      }
    }
    confirmOverlay.classList.add('is-open');
    confirmOverlay.setAttribute('aria-hidden', 'false');
    window.setTimeout(function(){
      if (opts.showInput && confirmInputEl) confirmInputEl.focus();
      else if (confirmCancelBtn) confirmCancelBtn.focus();
    }, 0);
  }

  function showBuyPrompt(opts){
    openConfirmDialog(Object.assign({
      destructive: false,
      cancelLabel: 'Cancel',
      confirmLabel: 'OK'
    }, opts || {}));
  }

  function buildSavedCardPreviewHtml(card){
    if (!card) return '';
    return buildSavedCardLogoHtml(card.brand) +
      '<span class="sbd-pay-body">' +
        '<span class="sbd-pay-label-row">x-' + escapeHtml(card.last4) + '</span>' +
        '<div class="sbd-pay-sub">' + escapeHtml(card.cardType === 'debit' ? 'Debit card' : 'Credit card') + '</div>' +
      '</span>';
  }

  function performDeleteSavedCard(cardId){
    var id = parseInt(String(cardId || '0'), 10);
    if (!id) return;
    savedCards = savedCards.filter(function(card){ return card.id !== id; });
    var row = payListEl ? payListEl.querySelector('.sbd-pay-option[data-saved-card-id="' + id + '"]') : null;
    if (row) row.remove();
    if (getSelectedPaymentMethodId() === 'saved_card_' + id) {
      if (savedCards.length) {
        selectPaymentMethod('saved_card_' + savedCards[savedCards.length - 1].id);
      } else if (addCardRow) {
        selectPaymentMethod('add_card');
      } else {
        var fallback = document.querySelector('input[name="payment_method"]');
        if (fallback) selectPaymentMethod(String(fallback.value || ''));
      }
    }
  }

  function deleteSavedCard(cardId){
    var id = parseInt(String(cardId || '0'), 10);
    if (!id) return;
    var card = findSavedCard(id);
    openConfirmDialog({
      title: 'Remove card?',
      message: 'This card will be removed from your payment methods for this order.',
      confirmLabel: 'Remove',
      cancelLabel: 'Cancel',
      destructive: true,
      cardPreviewHtml: buildSavedCardPreviewHtml(card),
      onConfirm: function(){
        performDeleteSavedCard(id);
        closeConfirmDialog();
      }
    });
  }

  function addSavedCardToList(card){
    if (!payListEl) return null;
    var row = createSavedCardRow(card);
    if (addCardRow && addCardRow.parentNode) {
      addCardRow.parentNode.insertBefore(row, addCardRow);
    } else {
      payListEl.appendChild(row);
    }
    return row;
  }

  function clearCardForm(){
    if (cardNumberEl) cardNumberEl.value = '';
    if (cardExpiryEl) cardExpiryEl.value = '';
    if (cardCvcEl) cardCvcEl.value = '';
    if (cardNameEl) cardNameEl.value = '';
    setCardType('credit');
  }

  function syncCardBrandButtons(brand){
    document.querySelectorAll('.sbd-card-brand-btn').forEach(function(btn){
      btn.classList.toggle('is-active', btn.getAttribute('data-card-brand') === brand);
    });
  }

  function setCardBrand(brand, isEdit){
    if (!brand || !cardBrandLabels[brand]) brand = 'visa';
    syncCardBrandButtons(brand);
    if (cardTitleEl) {
      cardTitleEl.textContent = (isEdit ? 'Edit ' : 'Add ') + cardBrandLabels[brand] + ' card';
    }
    if (cardPopup) cardPopup.setAttribute('data-selected-brand', brand);
  }

  function resetCardPopupMode(){
    editingCardId = 0;
    if (cardPopup) cardPopup.removeAttribute('data-editing-card-id');
    if (cardDoneBtn) cardDoneBtn.textContent = 'Done';
    if (cardNumberEl) {
      cardNumberEl.placeholder = '1234 5678 9012 3456';
    }
  }

  function openCardPopup(brand){
    if (!cardPopup) return;
    resetCardPopupMode();
    setCardBrand(brand || 'visa', false);
    setCardType('credit');
    clearCardForm();
    cardPopup.classList.add('is-open');
    cardPopup.setAttribute('aria-hidden', 'false');
    if (cardNumberEl) {
      window.setTimeout(function(){ cardNumberEl.focus(); }, 0);
    }
  }

  function openCardPopupForEdit(cardId){
    var card = findSavedCard(cardId);
    if (!card || !cardPopup) return;
    editingCardId = card.id;
    cardPopup.setAttribute('data-editing-card-id', String(card.id));
    if (cardDoneBtn) cardDoneBtn.textContent = 'Save';
    setCardBrand(card.brand || 'visa', true);
    setCardType(card.cardType || 'credit');
    if (cardNumberEl) {
      cardNumberEl.value = '';
      cardNumberEl.placeholder = '···· ···· ···· ' + card.last4;
    }
    if (cardExpiryEl) cardExpiryEl.value = card.expiry || '';
    if (cardCvcEl) cardCvcEl.value = '';
    if (cardNameEl) cardNameEl.value = card.name || '';
    cardPopup.classList.add('is-open');
    cardPopup.setAttribute('aria-hidden', 'false');
    if (cardNumberEl) {
      window.setTimeout(function(){ cardNumberEl.focus(); }, 0);
    }
  }

  function closeCardPopup(save){
    if (!cardPopup) return;
    if (save) {
      var brand = cardPopup.getAttribute('data-selected-brand') || 'visa';
      var cardType = cardPopup.getAttribute('data-selected-type') || selectedCardType || 'credit';
      var number = (cardNumberEl ? cardNumberEl.value : '').replace(/\D/g, '');
      var expiry = (cardExpiryEl ? cardExpiryEl.value : '').trim();
      var cvc = (cardCvcEl ? cardCvcEl.value : '').trim();
      var name = (cardNameEl ? cardNameEl.value : '').trim();
      var editingId = parseInt(cardPopup.getAttribute('data-editing-card-id') || '0', 10);
      var existingCard = editingId ? findSavedCard(editingId) : null;

      if (editingId && existingCard) {
        if (number.length > 0 && number.length < 4) {
          showBuyPrompt({
            title: 'Card number',
            message: 'Please enter a valid card number.',
            confirmLabel: 'OK',
            cancelLabel: 'Close',
            onConfirm: function(){ closeConfirmDialog(); }
          });
          return;
        }
        if (expiry === '' || name === '') {
          showBuyPrompt({
            title: 'Card details',
            message: 'Please complete expiry and name on card.',
            confirmLabel: 'OK',
            cancelLabel: 'Close',
            onConfirm: function(){ closeConfirmDialog(); }
          });
          return;
        }
        existingCard.brand = brand;
        existingCard.last4 = number.length >= 4 ? number.slice(-4) : existingCard.last4;
        existingCard.name = name;
        existingCard.expiry = expiry;
        existingCard.cardType = cardType === 'debit' ? 'debit' : 'credit';
        var updatedRow = updateSavedCardRowDom(existingCard);
        clearCardForm();
        if (updatedRow) {
          selectPaymentMethod(updatedRow.getAttribute('data-payment-id') || '');
        }
      } else {
        if (number.length < 4) {
          showBuyPrompt({
            title: 'Card number',
            message: 'Please enter a valid card number.',
            confirmLabel: 'OK',
            cancelLabel: 'Close',
            onConfirm: function(){ closeConfirmDialog(); }
          });
          return;
        }
        if (expiry === '' || cvc === '' || name === '') {
          showBuyPrompt({
            title: 'Card details',
            message: 'Please complete expiry, CVV, and name on card.',
            confirmLabel: 'OK',
            cancelLabel: 'Close',
            onConfirm: function(){ closeConfirmDialog(); }
          });
          return;
        }
        savedCardSeq += 1;
        var card = {
          id: savedCardSeq,
          brand: brand,
          last4: number.slice(-4),
          name: name,
          expiry: expiry,
          cardType: cardType === 'debit' ? 'debit' : 'credit'
        };
        savedCards.push(card);
        var row = addSavedCardToList(card);
        clearCardForm();
        if (row) {
          selectPaymentMethod(row.getAttribute('data-payment-id') || '');
          row.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        }
      }
    }
    resetCardPopupMode();
    cardPopup.classList.remove('is-open');
    cardPopup.setAttribute('aria-hidden', 'true');
    if (addCardRow) addCardRow.focus();
  }

  function getOrderTotalCents(){
    var qty = clampQty(qtyEl ? qtyEl.value : 1);
    var subtotalCents = unitCents * qty;
    var discountCents = Math.max(0, Math.min(subtotalCents, appliedDiscountCents | 0));
    var merchCents = Math.max(0, subtotalCents - discountCents);
    var shippingCents = effectiveShippingCents();
    var taxRate = summaryEl ? parseFloat(summaryEl.getAttribute('data-tax-rate') || '0.0825') : 0.0825;
    if (!Number.isFinite(taxRate) || taxRate < 0) taxRate = 0;
    var taxCents = Math.round((merchCents + shippingCents) * taxRate);
    var serviceFeeCents = summaryEl ? parseInt(summaryEl.getAttribute('data-service-fee-cents') || '199', 10) : 199;
    if (!Number.isFinite(serviceFeeCents) || serviceFeeCents < 0) serviceFeeCents = 199;
    return merchCents + shippingCents + taxCents + serviceFeeCents;
  }

  function setPromoMessage(text, kind){
    if (!promoMsgEl) return;
    var msg = String(text || '').trim();
    if (!msg) {
      promoMsgEl.hidden = true;
      promoMsgEl.textContent = '';
      promoMsgEl.classList.remove('is-error', 'is-ok');
      return;
    }
    promoMsgEl.hidden = false;
    promoMsgEl.textContent = msg;
    promoMsgEl.classList.toggle('is-error', kind === 'error');
    promoMsgEl.classList.toggle('is-ok', kind === 'ok');
  }

  function clearAppliedPromo(){
    appliedPromoCode = '';
    appliedDiscountCents = 0;
    if (summaryDiscount) summaryDiscount.textContent = formatMoney(0);
  }

  async function applyPromoCode(){
    var code = promoCodeInput ? String(promoCodeInput.value || '').trim().toUpperCase() : '';
    if (!code) {
      clearAppliedPromo();
      setPromoMessage('', '');
      updateTotal();
      return;
    }
    if (promoApplyBtn) promoApplyBtn.disabled = true;
    try {
      var params = new URLSearchParams();
      params.set('product_id', String(productId));
      params.set('quantity', String(clampQty(qtyEl ? qtyEl.value : 1)));
      params.set('delivery_option', receiveOption === 'pickup' ? 'pickup' : 'home_delivery');
      params.set('promo_code', code);
      var res = await fetch('ajax/shop_buy_preview.php?' + params.toString(), {
        method: 'GET',
        credentials: 'same-origin'
      });
      var data = await res.json();
      if (!data || !data.ok || !data.summary) {
        clearAppliedPromo();
        setPromoMessage((data && data.message) || 'Could not apply promo.', 'error');
        updateTotal();
        return;
      }
      if (data.summary.promo_valid === false) {
        clearAppliedPromo();
        setPromoMessage('That promo code is not valid for this seller.', 'error');
        updateTotal();
        return;
      }
      appliedPromoCode = code;
      appliedDiscountCents = Math.max(0, parseInt(String(data.summary.discount_cents || '0'), 10) || 0);
      if (appliedDiscountCents <= 0) {
        setPromoMessage('Promo applied — no discount on this order.', 'ok');
      } else {
        setPromoMessage('Discount applied.', 'ok');
      }
      updateTotal();
    } catch (err) {
      clearAppliedPromo();
      setPromoMessage('Could not apply promo.', 'error');
      updateTotal();
    } finally {
      if (promoApplyBtn) promoApplyBtn.disabled = false;
    }
  }

  function getSelectedPaymentMethodId(){
    var payMethod = document.querySelector('input[name="payment_method"]:checked');
    return payMethod ? String(payMethod.value || '') : '';
  }

  function syncPaymentSelection(methodId){
    document.querySelectorAll('.sbd-pay-option').forEach(function(row){
      var input = row.querySelector('.sbd-pay-radio');
      var isSelected = !!(input && input.value === methodId);
      if (input) input.checked = isSelected;
      row.classList.toggle('is-selected', isSelected);
    });
    if (methodId === 'test_cost') {
      fillTestCostFromTotal(false);
      if (testCostInput) {
        setTimeout(function(){ try { testCostInput.focus(); } catch (e) {} }, 0);
      }
    }
  }

  function fillTestCostFromTotal(force){
    if (!testCostInput) return;
    var cents = getOrderTotalCents();
    var raw = String(testCostInput.value || '').trim();
    var currentCents = parseTestCostCents(raw);
    if (!force && raw !== '' && lastAutoFilledCostCents !== null && currentCents !== lastAutoFilledCostCents) {
      return;
    }
    testCostInput.value = (Math.max(0, cents) / 100).toFixed(2);
    lastAutoFilledCostCents = cents;
  }

  function parseTestCostCents(raw){
    var cleaned = String(raw || '').replace(/[^0-9.]/g, '');
    if (cleaned === '' || cleaned === '.') return 0;
    var dollars = parseFloat(cleaned);
    if (!Number.isFinite(dollars) || dollars <= 0) return 0;
    return Math.round(dollars * 100);
  }

  function updatePaymentPromo(orderTotalCents, methodId){
    var promoEl = document.getElementById('sbdPaymentPromo');
    if (!promoEl || !promoLogo || !promoText) return;

    methodId = methodId || getSelectedPaymentMethodId();

    if (methodId === 'manual' || methodId === 'test_cost' || methodId === 'stripe' || methodId === 'add_card' || isSavedCardMethod(methodId)) {
      promoEl.classList.add('is-hidden');
      return;
    }

    // Stub BNPL / wallet methods are no longer listed; keep promo hidden.
    promoEl.classList.add('is-hidden');
  }

  function selectPaymentMethod(methodId){
    if (!methodId) return;
    syncPaymentSelection(methodId);
    updatePaymentPromo(getOrderTotalCents(), methodId);
  }

  function updateTotal(){
    var qty = clampQty(qtyEl ? qtyEl.value : 1);
    if (qtyEl) qtyEl.value = String(qty);
    var productQtyEl = document.getElementById('sbdProductQty');
    if (productQtyEl) productQtyEl.textContent = 'Quantity ' + qty;
    var qtyDecBtn = document.getElementById('sbdQtyDec');
    var qtyIncBtn = document.getElementById('sbdQtyInc');
    if (qtyDecBtn) qtyDecBtn.disabled = qty <= 1;
    if (qtyIncBtn) qtyIncBtn.disabled = qty >= 99;
    var subtotalCents = unitCents * qty;
    var discountCents = Math.max(0, Math.min(subtotalCents, appliedDiscountCents | 0));
    var merchCents = Math.max(0, subtotalCents - discountCents);
    var shippingCents = effectiveShippingCents();
    if (summaryEl) summaryEl.setAttribute('data-shipping-cents', String(shippingCents));
    var taxRate = summaryEl ? parseFloat(summaryEl.getAttribute('data-tax-rate') || '0.0825') : 0.0825;
    if (!Number.isFinite(taxRate) || taxRate < 0) taxRate = 0;
    var taxableCents = merchCents + shippingCents;
    var taxCents = Math.round(taxableCents * taxRate);
    var serviceFeeCents = summaryEl ? parseInt(summaryEl.getAttribute('data-service-fee-cents') || '199', 10) : 199;
    if (!Number.isFinite(serviceFeeCents) || serviceFeeCents < 0) serviceFeeCents = 199;
    var orderTotalCents = merchCents + shippingCents + taxCents + serviceFeeCents;
    if (summaryItemLabel) {
      summaryItemLabel.textContent = qty === 1 ? 'Item (1)' : 'Item (' + qty + ')';
    }
    if (summarySubtotal) summarySubtotal.textContent = formatMoney(subtotalCents);
    if (summaryDiscountRow && summaryDiscount) {
      summaryDiscount.textContent = discountCents > 0
        ? ('-' + formatMoney(discountCents))
        : formatMoney(0);
    }
    if (summaryShipping) {
      summaryShipping.textContent = shippingCents > 0 ? formatMoney(shippingCents) : 'Free';
    }
    if (summaryShippingRow) {
      summaryShippingRow.classList.toggle('is-hidden', receiveOption === 'pickup');
    }
    if (summaryTaxLabel) {
      var pct = (taxRate * 100);
      var pctLabel = (Math.round(pct * 100) / 100).toString();
      summaryTaxLabel.textContent = 'Tax (' + pctLabel + '%)';
    }
    if (summaryTax) summaryTax.textContent = formatMoney(taxCents);
    if (summaryServiceFee) summaryServiceFee.textContent = formatMoney(serviceFeeCents);
    if (totalEl) totalEl.textContent = formatMoney(orderTotalCents);
    updatePaymentPromo(orderTotalCents, getSelectedPaymentMethodId());
    if (getSelectedPaymentMethodId() === 'test_cost') {
      fillTestCostFromTotal(false);
    }
  }

  if (payListEl) {
    payListEl.addEventListener('click', function(e){
      var editBtn = e.target && e.target.closest ? e.target.closest('[data-card-action="edit"]') : null;
      if (editBtn) {
        e.preventDefault();
        e.stopPropagation();
        var editRow = editBtn.closest('.sbd-pay-option[data-saved-card-id]');
        if (editRow) {
          openCardPopupForEdit(parseInt(editRow.getAttribute('data-saved-card-id') || '0', 10));
        }
        return;
      }
      var deleteBtn = e.target && e.target.closest ? e.target.closest('[data-card-action="delete"]') : null;
      if (deleteBtn) {
        e.preventDefault();
        e.stopPropagation();
        var deleteRow = deleteBtn.closest('.sbd-pay-option[data-saved-card-id]');
        if (deleteRow) {
          deleteSavedCard(parseInt(deleteRow.getAttribute('data-saved-card-id') || '0', 10));
        }
        return;
      }
      var cardBtn = e.target && e.target.closest ? e.target.closest('.sbd-open-card-btn') : null;
      if (cardBtn) {
        e.preventDefault();
        e.stopPropagation();
        openCardPopup(cardBtn.getAttribute('data-card-brand') || 'visa');
        return;
      }
      if (e.target && e.target.closest && e.target.closest('.sbd-pay-card-action')) return;
      if (e.target && e.target.closest && e.target.closest('a')) return;
      var row = e.target && e.target.closest ? e.target.closest('.sbd-pay-option') : null;
      if (!row) return;
      var methodId = row.getAttribute('data-payment-id') || '';
      if (!methodId) return;
      e.preventDefault();
      if (methodId === 'add_card' || row.getAttribute('data-opens-card') === '1') {
        openCardPopup('');
        return;
      }
      selectPaymentMethod(methodId);
    });
    payListEl.addEventListener('change', function(e){
      var radio = e.target;
      if (!radio || !radio.classList || !radio.classList.contains('sbd-pay-radio')) return;
      selectPaymentMethod(String(radio.value || ''));
    });
  }

  document.querySelectorAll('.sbd-card-brand-btn').forEach(function(btn){
    btn.addEventListener('click', function(){
      setCardBrand(btn.getAttribute('data-card-brand') || 'visa', editingCardId > 0);
    });
  });

  document.querySelectorAll('.sbd-card-type-btn').forEach(function(btn){
    btn.addEventListener('click', function(){
      setCardType(btn.getAttribute('data-card-type') || 'credit');
    });
  });

  async function refreshAppliedPromoIfNeeded(){
    if (!appliedPromoCode) return;
    if (promoCodeInput) promoCodeInput.value = appliedPromoCode;
    await applyPromoCode();
  }

  if (qtyEl) {
    qtyEl.addEventListener('input', function(){
      updateTotal();
      updateShipPreview();
    });
    qtyEl.addEventListener('change', function(){
      updateTotal();
      updateShipPreview();
      refreshAppliedPromoIfNeeded();
    });
  }
  function bumpQty(delta){
    var next = clampQty((qtyEl ? qtyEl.value : 1)) + delta;
    if (qtyEl) qtyEl.value = String(clampQty(next));
    updateTotal();
    updateShipPreview();
    refreshAppliedPromoIfNeeded();
  }
  var qtyDecBtn = document.getElementById('sbdQtyDec');
  var qtyIncBtn = document.getElementById('sbdQtyInc');
  if (qtyDecBtn) qtyDecBtn.addEventListener('click', function(){ bumpQty(-1); });
  if (qtyIncBtn) qtyIncBtn.addEventListener('click', function(){ bumpQty(1); });
  updateTotal();
  updateShipPreview();
  selectPaymentMethod(getSelectedPaymentMethodId() || 'test_cost');
  if (testCostUseBtn) {
    testCostUseBtn.addEventListener('click', function(e){
      e.preventDefault();
      e.stopPropagation();
      selectPaymentMethod('test_cost');
      fillTestCostFromTotal(true);
      if (testCostInput) testCostInput.focus();
    });
  }
  if (promoApplyBtn) {
    promoApplyBtn.addEventListener('click', function(e){
      e.preventDefault();
      applyPromoCode();
    });
  }
  if (promoCodeInput) {
    promoCodeInput.addEventListener('keydown', function(e){
      if (e.key === 'Enter') {
        e.preventDefault();
        applyPromoCode();
      }
    });
    promoCodeInput.addEventListener('input', function(){
      var next = String(promoCodeInput.value || '').trim().toUpperCase();
      if (appliedPromoCode && next !== appliedPromoCode) {
        clearAppliedPromo();
        setPromoMessage('', '');
        updateTotal();
      }
    });
  }
  if (testCostInput) {
    testCostInput.addEventListener('focus', function(){
      if (getSelectedPaymentMethodId() !== 'test_cost') {
        selectPaymentMethod('test_cost');
      }
    });
    testCostInput.addEventListener('click', function(e){
      e.stopPropagation();
    });
  }

  if (openAddressBtn) openAddressBtn.addEventListener('click', openAddressPopup);
  document.querySelectorAll('.sbd-receive-btn').forEach(function(btn){
    btn.addEventListener('click', function(){
      setReceiveOption(btn.getAttribute('data-receive') || 'home_delivery');
    });
  });
  setReceiveOption(receiveOption);
  if (addressBackBtn) addressBackBtn.addEventListener('click', function(){ closeAddressPopup(true); });
  if (addressDoneBtn) addressDoneBtn.addEventListener('click', async function(){
    var addressEl = document.getElementById('sbdAddress');
    var phoneEl = document.getElementById('sbdPhone');
    var addressVal = (addressEl ? addressEl.value : '').trim();
    if (addressVal === '') {
      showBuyPrompt({
        title: 'Add delivery address',
        message: 'Type your shipping address so we can deliver this order.',
        showInput: true,
        inputPlaceholder: 'Street, city, ZIP',
        confirmLabel: 'Save',
        cancelLabel: 'Cancel',
        onConfirm: function(typed){
          if (addressEl) addressEl.value = typed;
          closeConfirmDialog();
          updateShipPreview();
        }
      });
      return;
    }
    addressDoneBtn.disabled = true;
    try {
      var body = new URLSearchParams();
      body.set('delivery_address', addressVal);
      body.set('phone', (phoneEl ? phoneEl.value : '').trim());
      var res = await fetch('ajax/buyer_address_save.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body.toString(),
        credentials: 'same-origin'
      });
      var data = await res.json();
      if (!data.ok) {
        showBuyPrompt({
          title: 'Could not save',
          message: data.message || 'Could not save address.',
          confirmLabel: 'OK',
          cancelLabel: 'Close',
          onConfirm: function(){ closeConfirmDialog(); }
        });
        return;
      }
      if (data.address_text && addressEl) {
        addressEl.value = data.address_text;
      }
      closeAddressPopup(true);
    } catch (e) {
      showBuyPrompt({
        title: 'Could not save',
        message: 'Could not save address.',
        confirmLabel: 'OK',
        cancelLabel: 'Close',
        onConfirm: function(){ closeConfirmDialog(); }
      });
    } finally {
      addressDoneBtn.disabled = false;
    }
  });
  if (cardBackBtn) cardBackBtn.addEventListener('click', function(){ closeCardPopup(false); });
  if (cardDoneBtn) cardDoneBtn.addEventListener('click', function(){ closeCardPopup(true); });
  if (confirmCancelBtn) confirmCancelBtn.addEventListener('click', closeConfirmDialog);
  if (confirmCloseBtn) confirmCloseBtn.addEventListener('click', closeConfirmDialog);
  if (confirmOkBtn) {
    confirmOkBtn.addEventListener('click', function(){
      var action = pendingConfirmAction;
      if (!action) {
        closeConfirmDialog();
        return;
      }
      if (confirmFieldWrap && !confirmFieldWrap.hidden && confirmInputEl) {
        var typed = String(confirmInputEl.value || '').trim();
        if (!typed) {
          confirmInputEl.classList.add('is-invalid');
          confirmInputEl.focus();
          return;
        }
        confirmInputEl.classList.remove('is-invalid');
        action(typed);
        return;
      }
      action();
    });
  }
  if (confirmInputEl) {
    confirmInputEl.addEventListener('input', function(){
      confirmInputEl.classList.remove('is-invalid');
    });
    confirmInputEl.addEventListener('keydown', function(e){
      if (e.key === 'Enter') {
        e.preventDefault();
        if (confirmOkBtn) confirmOkBtn.click();
      }
    });
  }
  if (confirmOverlay) {
    confirmOverlay.addEventListener('click', function(e){
      if (e.target === confirmOverlay) closeConfirmDialog();
    });
  }
  document.addEventListener('keydown', function(e){
    if (e.key === 'Escape' && confirmOverlay && confirmOverlay.classList.contains('is-open')) {
      e.preventDefault();
      closeConfirmDialog();
    }
  });

  if (closeBtn) closeBtn.addEventListener('click', closeDoor);
  if (cancelBtn) cancelBtn.addEventListener('click', closeDoor);
  if (doneBtn) doneBtn.addEventListener('click', closeDoor);

  if (viewOrderBtn) {
    viewOrderBtn.addEventListener('click', function(){
      if (!lastOrderId) { closeDoor(); return; }
      var url = 'order_detail.php?order_id=' + encodeURIComponent(String(lastOrderId)) + '&embed=1';
      try {
        if (window.parent && window.parent !== window) {
          window.parent.postMessage({ type: 'msb-live-right-door-open', url: url }, '*');
          return;
        }
      } catch (e) {}
      window.location.href = url;
    });
  }

  function placeOrderNow(){
    if (!submitBtn) return;
    submitBtn.click();
  }

  if (continueReviewBtn) {
    continueReviewBtn.addEventListener('click', function(){
      continueToReview();
    });
  }
  if (backToBuyBtn) {
    backToBuyBtn.addEventListener('click', function(){
      setCheckoutStep(1);
      try { if (bodyEl) bodyEl.scrollTop = 0; } catch (e) {}
    });
  }

  if (submitBtn) {
    submitBtn.addEventListener('click', async function(){
      var addressEl = document.getElementById('sbdAddress');
      var addressVal = (addressEl ? addressEl.value : '').trim();
      if (receiveOption !== 'pickup' && addressVal === '') {
        setCheckoutStep(1);
        showBuyPrompt({
          title: 'Add delivery address',
          message: 'Type your shipping address so we can deliver this order.',
          showInput: true,
          inputPlaceholder: 'Street, city, ZIP',
          confirmLabel: 'Save',
          cancelLabel: 'Cancel',
          onConfirm: function(typed){
            if (addressEl) addressEl.value = typed;
            closeConfirmDialog();
            updateShipPreview();
            window.setTimeout(continueToReview, 0);
          }
        });
        return;
      }
      if (getSelectedPaymentMethodId() === 'add_card') {
        setCheckoutStep(1);
        showBuyPrompt({
          title: 'Add a card',
          message: 'Add a payment card to continue placing this order.',
          confirmLabel: 'Add card',
          cancelLabel: 'Cancel',
          onConfirm: function(){
            closeConfirmDialog();
            openCardPopup('visa');
          }
        });
        return;
      }
      var payMethodId = getSelectedPaymentMethodId();
      var testCostCents = 0;
      if (payMethodId === 'test_cost') {
        testCostCents = parseTestCostCents(testCostInput ? testCostInput.value : '');
        if (testCostCents <= 0) {
          setCheckoutStep(1);
          showBuyPrompt({
            title: 'Enter Cost $',
            message: 'Enter a Cost $ amount to place this test order.',
            showInput: true,
            inputPlaceholder: '0.00',
            inputValue: testCostInput ? String(testCostInput.value || '').trim() : '',
            confirmLabel: 'Continue',
            cancelLabel: 'Cancel',
            onConfirm: function(typed){
              selectPaymentMethod('test_cost');
              if (testCostInput) {
                testCostInput.value = typed;
                try { testCostInput.dispatchEvent(new Event('input', { bubbles: true })); } catch (e) {}
              }
              closeConfirmDialog();
              window.setTimeout(continueToReview, 0);
            }
          });
          return;
        }
      }
      submitBtn.disabled = true;
      setCheckoutStep(3);
      try {
        var phoneVal = (document.getElementById('sbdPhone') ? document.getElementById('sbdPhone').value : '').trim();
        if (receiveOption !== 'pickup' && addressVal !== '') {
          try {
            var saveBody = new URLSearchParams();
            saveBody.set('delivery_address', addressVal);
            saveBody.set('phone', phoneVal);
            await fetch('ajax/buyer_address_save.php', {
              method: 'POST',
              headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
              body: saveBody.toString(),
              credentials: 'same-origin'
            });
          } catch (saveErr) {}
        }

        var body = new URLSearchParams();
        body.set('product_id', String(productId));
        body.set('quantity', String(clampQty(qtyEl ? qtyEl.value : 1)));
        body.set('delivery_option', receiveOption === 'pickup' ? 'pickup' : 'home_delivery');
        body.set('fulfillment_method', fulfillmentMethod);
        body.set('delivery_address', receiveOption === 'pickup' ? '' : addressVal);
        body.set('buyer_phone', phoneVal);
        body.set('buyer_notes', (document.getElementById('sbdNotes').value || '').trim());
        if (profileId > 0) body.set('profile_id', String(profileId));
        body.set('payment_method', payMethodId || '');
        if (appliedPromoCode) body.set('promo_code', appliedPromoCode);
        if (payMethodId === 'test_cost') {
          body.set('test_cost_cents', String(testCostCents));
          body.set('test_cost', testCostInput ? String(testCostInput.value || '').trim() : '');
        }

        var res = await fetch('ajax/shop_buy.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: body.toString(),
          credentials: 'same-origin'
        });
        var data = await res.json();
        if (data.ok && data.checkout_url) {
          var top = window.top || window;
          top.location.href = data.checkout_url;
          return;
        }
        if (data.ok) {
          lastOrderId = parseInt(data.order_id || '0', 10) || 0;
          buySucceeded = true;
          try {
            if (window.parent && window.parent !== window) {
              window.parent.postMessage({
                type: 'msb-shop-buy-success',
                order_id: lastOrderId,
                from_cart: fromCart
              }, '*');
            }
          } catch (e) {}
          var amountLabel = '';
          var paidCentsShow = parseInt(String(data.amount_paid_cents != null ? data.amount_paid_cents : ''), 10);
          var dueCentsShow = parseInt(String(data.total_cents != null ? data.total_cents : ''), 10);
          var shortCentsShow = parseInt(String(data.shortfall_cents != null ? data.shortfall_cents : ''), 10);
          if (!Number.isFinite(paidCentsShow) || paidCentsShow < 0) paidCentsShow = 0;
          if (!Number.isFinite(dueCentsShow) || dueCentsShow < 0) dueCentsShow = 0;
          if (!Number.isFinite(shortCentsShow) || shortCentsShow < 0) {
            shortCentsShow = Math.max(0, dueCentsShow - paidCentsShow);
          }
          // Big number = amount sent to the seller (what you typed), not the catalog order total.
          if (paidCentsShow > 0) {
            amountLabel = formatMoney(paidCentsShow);
          } else if (dueCentsShow > 0) {
            amountLabel = formatMoney(dueCentsShow);
          }
          if (successAmount) {
            if (amountLabel) {
              successAmount.hidden = false;
              successAmount.textContent = amountLabel;
            } else {
              successAmount.hidden = true;
              successAmount.textContent = '';
            }
          }
          if (successDue) {
            if (data.payment_complete === false && dueCentsShow > 0 && paidCentsShow > 0 && shortCentsShow > 0) {
              successDue.hidden = false;
              successDue.textContent = 'Sent to seller · Order total '
                + formatMoney(dueCentsShow)
                + ' · Still due '
                + formatMoney(shortCentsShow);
            } else if (data.payment_complete === true && paidCentsShow > 0 && dueCentsShow > 0 && paidCentsShow !== dueCentsShow) {
              successDue.hidden = false;
              successDue.textContent = 'Fully covers order total ' + formatMoney(dueCentsShow);
            } else {
              successDue.hidden = true;
              successDue.textContent = '';
            }
          }
          if (successCode) {
            var code = String(data.order_code || '').trim();
            if (code) {
              successCode.hidden = false;
              successCode.textContent = 'Code ' + code;
            } else {
              successCode.hidden = true;
              successCode.textContent = '';
            }
          }
          if (successMsg) {
            if (data.test_cost) {
              if (data.payment_complete === false) {
                successMsg.textContent = data.message
                  || 'Payment is incomplete. Shipping will not start until the full order total is paid. Check Notifications → Pending.';
              } else {
                successMsg.textContent = 'Fully paid (test). Seller received '
                  + (amountLabel || 'your payment')
                  + ' and can ship from Sales Management → Orders.';
              }
            } else {
              successMsg.textContent = data.message || 'Your order was placed successfully.';
            }
          }
          setCheckoutStep(4);
          return;
        }
        setCheckoutStep(2);
        showBuyPrompt({
          title: 'Order failed',
          message: data.message || 'Order failed.',
          confirmLabel: 'OK',
          cancelLabel: 'Close',
          onConfirm: function(){ closeConfirmDialog(); }
        });
      } catch (e) {
        setCheckoutStep(2);
        showBuyPrompt({
          title: 'Order failed',
          message: 'Could not place order.',
          confirmLabel: 'OK',
          cancelLabel: 'Close',
          onConfirm: function(){ closeConfirmDialog(); }
        });
      } finally {
        submitBtn.disabled = false;
      }
    });
  }

  setCheckoutStep(1);
})();
</script>
<?php endif; ?>
</body>
</html>
