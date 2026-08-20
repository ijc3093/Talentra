<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/session_org.php';
require_once __DIR__ . '/includes/org_context.php';
require_once __DIR__ . '/../public_user/includes/platform_rent.php';
require_once __DIR__ . '/../public_user/includes/org_shop.php';
require_once __DIR__ . '/includes/org_ecommerce.php';
require_once __DIR__ . '/includes/org_manager_guard.php';

org_require_manager();
org_require_commerce_seller();
org_ecommerce_ensure_schema($dbh);

if (!function_exists('h')) {
    function h(string $value): string { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
}

$orgId = (int)orgActiveOrgId();
$ref = strtoupper(trim((string)($_GET['ref'] ?? '')));
$product = null;
$order = null;
$type = 'Stock Received';
$typeClass = 'received';
$quantity = 0;
$location = 'Direct Store';
$source = 'Opening Inventory';
$createdBy = 'System';
$notes = 'Product listed with opening stock.';

if (preg_match('/^RCV-(\d{1,10})$/', $ref, $match)) {
    $product = org_shop_get_product($dbh, (int)$match[1], $orgId);
    if ($product) $quantity = max(0, (int)($product['stock_qty'] ?? 0));
} else {
    try {
        $st = $dbh->prepare('SELECT o.*, p.sku, p.product_code, p.cover_image_path, p.attributes_json, p.stock_qty, p.price_cents AS product_price_cents FROM org_orders o LEFT JOIN org_products p ON p.id=o.product_id AND p.is_deleted=0 WHERE o.org_id=:org AND (o.order_code=:ref OR o.id=:id) LIMIT 1');
        $numericId = preg_match('/(\d+)$/', $ref, $m) ? (int)$m[1] : 0;
        $st->execute([':org' => $orgId, ':ref' => $ref, ':id' => $numericId]);
        $order = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($order) {
            $product = org_shop_get_product($dbh, (int)($order['product_id'] ?? 0), $orgId);
            $quantity = max(1, (int)($order['quantity'] ?? 1));
            $cancelled = in_array(strtolower((string)($order['status'] ?? '')), ['cancelled', 'canceled'], true);
            $type = $cancelled ? 'Stock Returned' : 'Stock Reserved';
            $typeClass = $cancelled ? 'returned' : 'reserved';
            $source = $cancelled ? 'Customer Return' : 'Customer Order';
            $createdBy = trim((string)($order['buyer_name'] ?? '')) ?: 'Customer';
            $notes = $cancelled ? 'Cancelled order returned to stock.' : 'Stock reserved for customer order.';
        }
    } catch (Throwable $e) { $order = null; }
}

if (!$product && !$order) {
    header('Location: sales_management.php#transactions');
    exit;
}

$product = $product ?: [];
$title = trim((string)($product['title'] ?? $order['product_title'] ?? 'Product')) ?: 'Product';
$sku = trim((string)($product['sku'] ?? $product['product_code'] ?? '')) ?: '—';
$createdAt = (string)($order['created_at'] ?? $product['created_at'] ?? '');
$timestamp = strtotime($createdAt) ?: time();
$unitCents = (int)($order['unit_price_cents'] ?? $product['price_cents'] ?? 0);
$totalCents = $unitCents * $quantity;
$currency = (string)($order['currency'] ?? $product['currency'] ?? 'USD');
$money = static fn(int $cents): string => org_shop_format_price($cents, $currency);
$cover = trim((string)($product['cover_image_path'] ?? ''));
if ($cover !== '' && !preg_match('#^https?://#i', $cover)) {
    $cover = ltrim(str_replace('\\', '/', $cover), '/');
    if (str_starts_with($cover, 'organization/')) $cover = substr($cover, 13);
}
$variant = '';
$attrs = json_decode((string)($product['attributes_json'] ?? ''), true);
if (is_array($attrs)) {
    $values = [];
    foreach ($attrs as $value) {
        if (is_string($value) && trim($value) !== '') $values[] = trim($value);
        if (count($values) === 2) break;
    }
    $variant = implode(' · ', $values);
}

$receivedIds = [];
try {
    $st = $dbh->prepare('SELECT id FROM org_products WHERE org_id=:org AND is_deleted=0 AND stock_qty > 0 ORDER BY id');
    $st->execute([':org' => $orgId]);
    $receivedIds = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
} catch (Throwable $e) {}
$currentId = (int)($product['id'] ?? 0);
$position = array_search($currentId, $receivedIds, true);
$previousRef = ($position !== false && $position > 0) ? 'RCV-' . str_pad((string)$receivedIds[$position - 1], 6, '0', STR_PAD_LEFT) : '';
$nextRef = ($position !== false && isset($receivedIds[$position + 1])) ? 'RCV-' . str_pad((string)$receivedIds[$position + 1], 6, '0', STR_PAD_LEFT) : '';

$pageTitle = $ref;
require_once __DIR__ . '/includes/org_page_shell.php';
org_page_shell_open($pageTitle, '<link rel="stylesheet" href="css/commerce-hub.css?v=17"><link rel="stylesheet" href="css/sales-azia.css?v=6">');
org_page_body_open('commerce-page');
?>
<style>
.txd{--ink:#10204a;--muted:#627093;--line:#dce5f1;color:var(--ink);font-size:14px}.txd a{text-decoration:none}.txd-crumb{font-weight:700;margin:0 0 18px}.txd-crumb a{color:#155eef}.txd-head,.txd-nav,.txd-actions{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}.txd h1{font-size:32px;margin:0;letter-spacing:-.03em}.txd-tag{display:inline-flex;padding:8px 12px;border-radius:7px;background:#dcfce7;color:#18794e;font-size:13px;vertical-align:middle;margin-left:10px}.txd-sub{color:var(--muted);font-weight:600;margin:14px 0 22px}.txd-btn{display:inline-flex;align-items:center;gap:9px;border:1px solid var(--line);border-radius:9px;padding:12px 18px;color:var(--ink);background:var(--ch-surface,#fff);font-weight:700}.txd-btn.disabled{opacity:.45;pointer-events:none}.txd-card{border:1px solid var(--line);border-radius:12px;background:var(--ch-surface,#fff);margin-top:18px}.txd-summary{display:grid;grid-template-columns:repeat(4,1fr);padding:22px}.txd-col{padding:0 22px;border-right:1px solid var(--line)}.txd-col:last-child{border:0}.txd-stat{display:grid;grid-template-columns:36px 1fr;gap:10px;margin-bottom:24px}.txd-stat:last-child{margin-bottom:0}.txd-icon{width:34px;height:34px;border-radius:50%;background:#f5f7fb;display:flex;align-items:center;justify-content:center}.txd-icon.green{background:#e8faf2;color:#159c68}.txd-label{font-size:12px;color:var(--muted);margin-bottom:5px}.txd-value{font-weight:750}.txd-green{display:inline-block;background:#dcfce7;color:#18794e;border-radius:6px;padding:4px 8px}.txd-grid{display:grid;grid-template-columns:2fr 1fr;gap:18px}.txd-section{padding:20px 22px}.txd-section h2{font-size:17px;margin:0 0 18px}.txd-table{width:100%;border-collapse:collapse;border:1px solid var(--line)}.txd-table th,.txd-table td{text-align:left;padding:14px;border-bottom:1px solid var(--line)}.txd-table th{font-size:12px;color:var(--muted);background:var(--ch-surface,#fbfcfe)}.txd-product{display:flex;align-items:center;gap:10px;font-weight:700}.txd-thumb{width:40px;height:40px;border-radius:7px;background:#f1f5f9;object-fit:cover}.txd-total td{font-weight:800}.txd-details{display:grid;grid-template-columns:1fr 1fr;gap:14px 18px}.txd-details span:nth-child(odd){color:var(--muted)}.txd-activity{display:flex;gap:12px;padding:9px 0}.txd-dot{width:28px;height:28px;flex:0 0 auto;border-radius:50%;display:flex;align-items:center;justify-content:center;background:#16a36a;color:#fff}.txd-activity small{display:block;color:var(--muted);margin-top:4px}.txd-note{background:#f6f9ff;border:1px solid #cfe0ff;border-radius:9px;padding:16px;color:#345}.txd-empty{color:var(--muted);padding:10px 0}@media(max-width:900px){.txd-summary{grid-template-columns:1fr 1fr}.txd-col{border:0}.txd-grid{grid-template-columns:1fr}}@media(max-width:560px){.txd-summary{grid-template-columns:1fr}.txd h1{font-size:26px}}
@media(min-width:901px){
 html,body.org-app,body.org-app .sh-mainpanel,body.org-app .sh-pagebody{overflow:hidden!important}
 body.org-app .sh-pagebody{padding-bottom:0!important}
 .txd{height:calc(100vh - var(--org-header-h,48px) - 28px);max-height:calc(100vh - var(--org-header-h,48px) - 28px);display:flex;flex-direction:column;overflow:hidden;padding-bottom:6px}
 .txd-crumb{flex:0 0 auto;margin:0 0 7px;font-size:11px}
 .txd-head{flex:0 0 auto;margin-bottom:7px}
 .txd h1{font-size:25px}.txd-tag{padding:5px 9px;margin-left:8px}.txd-sub{margin:5px 0 0;font-size:12px}.txd-btn{padding:8px 12px;font-size:12px}
 .txd-card{margin-top:8px}.txd-summary{flex:0 0 auto;padding:12px 8px}.txd-col{padding:0 14px}.txd-stat{grid-template-columns:30px 1fr;gap:8px;margin-bottom:10px}.txd-icon{width:29px;height:29px}.txd-label{font-size:10px;margin-bottom:2px}.txd-value,.txd-green{font-size:11px}.txd-green{padding:3px 6px}
 .txd-grid{flex:1 1 0;min-height:0;gap:9px;overflow:hidden}.txd-grid>.txd-card{height:calc(100% - 8px);min-height:0;overflow:hidden}.txd-section{padding:11px 13px}.txd-section h2{font-size:14px;margin:0 0 8px}
 .txd-table th,.txd-table td{padding:8px;font-size:10px}.txd-thumb{width:31px;height:31px}.txd-details{gap:6px 12px;font-size:10px}
 .txd-activity{gap:9px;padding:4px 0;font-size:11px}.txd-dot{width:23px;height:23px}.txd-activity small{margin-top:1px;font-size:9px}.txd-empty{padding:2px 0;margin:0 0 7px;font-size:10px}.txd-note{padding:9px;font-size:10px}.txd-note p{margin:4px 0 0}
}
</style>
<main class="txd">
  <p class="txd-crumb"><a href="sales_management.php#inventory">Inventory</a> &nbsp;›&nbsp; <a href="sales_management.php#transactions">Transactions</a> &nbsp;›&nbsp; <?= h($ref) ?></p>
  <div class="txd-head"><div><h1><?= h($ref) ?><span class="txd-tag"><?= h($type) ?></span></h1><p class="txd-sub"><?= $typeClass === 'received' ? 'Stock received into inventory.' : h($notes) ?></p></div><div class="txd-actions"><a class="txd-btn" href="sales_management.php#transactions">← &nbsp;Back to Transactions</a><a class="txd-btn <?= $previousRef === '' ? 'disabled' : '' ?>" href="<?= $previousRef ? 'transactions_detail.php?ref=' . h($previousRef) : '#' ?>">‹ Previous</a><a class="txd-btn <?= $nextRef === '' ? 'disabled' : '' ?>" href="<?= $nextRef ? 'transactions_detail.php?ref=' . h($nextRef) : '#' ?>">Next ›</a></div></div>
  <section class="txd-card txd-summary">
    <div class="txd-col"><div class="txd-stat"><span class="txd-icon green"><i class="fa fa-arrow-down"></i></span><div><div class="txd-label">Transaction Type</div><div class="txd-value"><?= h($type) ?></div></div></div><div class="txd-stat"><span class="txd-icon"><i class="fa fa-clock-o"></i></span><div><div class="txd-label">Reference</div><div class="txd-value" style="color:#155eef"><?= h($ref) ?></div></div></div><div class="txd-stat"><span class="txd-icon"><i class="fa fa-clipboard"></i></span><div><div class="txd-label">Status</div><span class="txd-green">Completed</span></div></div></div>
    <div class="txd-col"><div class="txd-stat"><span class="txd-icon"><i class="fa fa-calendar"></i></span><div><div class="txd-label">Transaction Date &amp; Time</div><div class="txd-value"><?= h(date('M j, Y g:i A', $timestamp)) ?></div></div></div><div class="txd-stat"><span class="txd-icon"><i class="fa fa-user-o"></i></span><div><div class="txd-label">Created By</div><div class="txd-value"><?= h($createdBy) ?></div></div></div><div class="txd-stat"><span class="txd-icon"><i class="fa fa-sticky-note-o"></i></span><div><div class="txd-label">Notes</div><div class="txd-value"><?= h($notes) ?></div></div></div></div>
    <div class="txd-col"><div class="txd-stat"><span class="txd-icon"><i class="fa fa-building-o"></i></span><div><div class="txd-label">Location</div><div class="txd-value"><?= h($location) ?></div></div></div><div class="txd-stat"><span class="txd-icon"><i class="fa fa-truck"></i></span><div><div class="txd-label">Source</div><div class="txd-value"><?= h($source) ?></div></div></div></div>
    <div class="txd-col"><div class="txd-stat"><span class="txd-icon"><i class="fa fa-cube"></i></span><div><div class="txd-label">Total Items</div><div class="txd-value">1 Item</div></div></div><div class="txd-stat"><span class="txd-icon"><i class="fa fa-archive"></i></span><div><div class="txd-label">Total Quantity</div><div class="txd-value"><?= number_format($quantity) ?> units</div></div></div><div class="txd-stat"><span class="txd-icon"><i class="fa fa-usd"></i></span><div><div class="txd-label">Total Value</div><div class="txd-value"><?= h($money($totalCents)) ?></div></div></div></div>
  </section>
  <div class="txd-grid"><section class="txd-card txd-section"><h2>Items <?= $typeClass === 'received' ? 'Received' : 'Affected' ?> (1)</h2><table class="txd-table"><thead><tr><th>Product</th><th>SKU</th><th>Variant</th><th>Quantity</th><th>Unit Value</th><th>Total Value</th></tr></thead><tbody><tr><td><div class="txd-product"><?php if ($cover): ?><img class="txd-thumb" src="<?= h($cover) ?>" alt=""><?php else: ?><span class="txd-thumb"></span><?php endif; ?><a href="sales_management.php?inv_product=<?= $currentId ?>#inventory-detail"><?= h($title) ?></a></div></td><td><?= h($sku) ?></td><td><?= h($variant ?: '—') ?></td><td><?= number_format($quantity) ?></td><td><?= h($money($unitCents)) ?></td><td><strong><?= h($money($totalCents)) ?></strong></td></tr><tr class="txd-total"><td colspan="3">Total</td><td><?= number_format($quantity) ?> units</td><td></td><td><?= h($money($totalCents)) ?></td></tr></tbody></table></section>
  <section class="txd-card txd-section"><h2>Transaction Details</h2><div class="txd-details"><span>Reference Number</span><strong><?= h($ref) ?></strong><span>Transaction Type</span><strong><?= h($type) ?></strong><span>Transaction Date</span><strong><?= h(date('M j, Y', $timestamp)) ?></strong><span>Transaction Time</span><strong><?= h(date('g:i A', $timestamp)) ?></strong><span>Location</span><strong><?= h($location) ?></strong><span>Source</span><strong><?= h($source) ?></strong><span>Created By</span><strong><?= h($createdBy) ?></strong><span>Created At</span><strong><?= h(date('M j, Y g:i A', $timestamp)) ?></strong></div></section></div>
  <div class="txd-grid"><section class="txd-card txd-section"><h2>Activity Log</h2><div class="txd-activity"><span class="txd-dot">✓</span><div><strong><?= h($type) ?></strong><small><?= number_format($quantity) ?> units of <?= h($title) ?> were processed.</small><small><?= h(date('M j, Y g:i A', $timestamp)) ?> · <?= h($createdBy) ?></small></div></div><div class="txd-activity"><span class="txd-dot"><i class="fa fa-file-text-o"></i></span><div><strong>Transaction created</strong><small><?= h($ref) ?> was created.</small></div></div><div class="txd-activity"><span class="txd-dot">✓</span><div><strong>Transaction completed</strong><small>Inventory records were updated.</small></div></div></section><section class="txd-card txd-section" id="documents"><h2>Documents</h2><p class="txd-empty">No documents have been uploaded for this transaction.</p><div class="txd-note"><strong><i class="fa fa-info-circle"></i> &nbsp;Notes</strong><p><?= h($notes) ?></p></div></section></div>
</main>
</div>
<?php org_page_shell_close(); ?>
