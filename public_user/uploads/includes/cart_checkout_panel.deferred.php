<?php
/**
 * Deferred cart checkout panel — removed from cart.php UI for now.
 * To restore: include this file inside cart-page-scroll after cart-list.
 *
 * Expects: $items, $subtotalLabel, $shopStripeEnabled, h()
 */
declare(strict_types=1);

if (empty($items)) {
    return;
}
?>
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
