<?php
declare(strict_types=1);

/**
 * Product inventory detail for inventory_detail.php.
 * Expected: PDO $dbh, int $orgId, int $productId, array $product,
 * string $backHref, bool $fromSales, string $err, $ok
 */

if (!function_exists('h') && function_exists('org_ecommerce_h')) {
    function h(string $s): string
    {
        return org_ecommerce_h($s);
    }
}

if (!function_exists('invd_cover_url')) {
    function invd_cover_url(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }
        $path = ltrim(str_replace('\\', '/', $path), '/');
        if (str_starts_with($path, 'organization/')) {
            $path = substr($path, strlen('organization/'));
        }
        return $path;
    }
}

if (!function_exists('invd_color_css')) {
    function invd_color_css(string $name): string
    {
        $map = [
            'black' => '#111827', 'white' => '#f8fafc', 'red' => '#dc2626', 'blue' => '#2563eb',
            'green' => '#16a34a', 'yellow' => '#eab308', 'orange' => '#ea580c', 'purple' => '#7c3aed',
            'deep purple' => '#4c1d95', 'pink' => '#db2777', 'gray' => '#64748b', 'grey' => '#64748b',
            'silver' => '#94a3b8', 'gold' => '#ca8a04', 'brown' => '#92400e', 'navy' => '#1e3a8a',
        ];
        $key = strtolower(trim($name));
        return $map[$key] ?? '#94a3b8';
    }
}

if (!function_exists('invd_money')) {
    function invd_money(int $cents, string $currency = 'USD'): string
    {
        if (function_exists('org_shop_format_price')) {
            return org_shop_format_price($cents, $currency);
        }
        return '$' . number_format(max(0, $cents) / 100, 2);
    }
}

$fromSales = !empty($fromSales);
$backHref = (string)($backHref ?? 'product_table.php');
$alertsHref = $fromSales ? 'sales_management.php?inv=low#inventory' : 'product_table.php?inv=low';
$editHref = $fromSales
    ? ('sales_management.php?edit=' . (int)$productId . '#products')
    : ('products.php?edit=' . (int)$productId);
$productViewHref = 'products_detail.php?id=' . (int)$productId . ($fromSales ? '&from=sales' : '');
$formAction = (string)($invdFormAction ?? '');
if ($formAction === '') {
    $formAction = $fromSales
        ? ('sales_management.php?inv_product=' . (int)$productId)
        : ('inventory_detail.php?id=' . (int)$productId);
}
$lowStockAt = 5;

$title = trim((string)($product['title'] ?? 'Product'));
$sku = trim((string)($product['sku'] ?? ''));
if ($sku === '') {
    $sku = trim((string)($product['product_code'] ?? ''));
}
$category = trim((string)($product['category'] ?? '')) ?: 'Uncategorized';
$status = strtolower(trim((string)($product['status'] ?? 'draft')));
$currency = (string)($product['currency'] ?? 'USD');
$price = invd_money((int)($product['price_cents'] ?? 0), $currency);
$tracked = $product['stock_qty'] !== null && $product['stock_qty'] !== '';
$available = $tracked ? max(0, (int)$product['stock_qty']) : 0;
$cover = invd_cover_url((string)($product['cover_image_path'] ?? ''));
$listingLabel = ($status === 'draft' || $status === 'archived') ? 'Draft' : 'Active';
if ($status === 'sold_out') {
    $listingLabel = 'Active';
}

$reserved = 0;
try {
    $stR = $dbh->prepare("
        SELECT COALESCE(SUM(GREATEST(COALESCE(quantity, 1), 1)), 0)
        FROM org_orders
        WHERE org_id = :org AND product_id = :pid
          AND LOWER(TRIM(status)) IN ('pending','paid','confirmed','processing')
    ");
    $stR->execute([':org' => (int)$orgId, ':pid' => (int)$productId]);
    $reserved = (int)$stR->fetchColumn();
} catch (Throwable $e) {
}

$totalStock = $available + $reserved;
$pct = static function (int $part, int $total): int {
    return $total > 0 ? (int)round($part / $total * 100) : 0;
};

$attrRows = [];
if (function_exists('org_product_type_attributes_for_display')) {
    $attrRows = org_product_type_attributes_for_display(
        isset($product['attributes_json']) ? (string)$product['attributes_json'] : null,
        (string)($product['selling_type'] ?? '')
    );
}
$variantName = 'Default';
$colorName = '';
foreach ($attrRows as $ar) {
    $k = strtolower((string)($ar['key'] ?? ''));
    if ($k === 'color' || $k === 'colour') {
        $colorName = (string)$ar['value'];
        $variantName = $colorName;
        break;
    }
}
if ($variantName === 'Default') {
    foreach ($attrRows as $ar) {
        $k = strtolower((string)($ar['key'] ?? ''));
        if (in_array($k, ['size', 'storage_gb', 'model'], true)) {
            $variantName = (string)$ar['value'];
            break;
        }
    }
}

$stockCls = 'in';
$stockLabel = 'In Stock';
if ($status === 'sold_out' || ($tracked && $available <= 0)) {
    $stockCls = 'out';
    $stockLabel = 'Out of Stock';
} elseif ($tracked && $available <= $lowStockAt) {
    $stockCls = 'low';
    $stockLabel = 'Low Stock';
}
$oosUnits = $stockCls === 'out' ? 1 : 0;
$variantCount = 1;

$history = [];
try {
    $stH = $dbh->prepare("
        SELECT o.id, o.created_at, o.quantity, o.status, o.order_code, o.updated_at
        FROM org_orders o
        WHERE o.org_id = :org AND o.product_id = :pid
        ORDER BY o.created_at DESC, o.id DESC
        LIMIT 40
    ");
    $stH->execute([':org' => (int)$orgId, ':pid' => (int)$productId]);
    $orders = $stH->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $running = $available;
    foreach ($orders as $o) {
        $st = strtolower(trim((string)($o['status'] ?? '')));
        $qty = max(1, (int)($o['quantity'] ?? 1));
        $isReturn = in_array($st, ['cancelled', 'canceled'], true);
        $change = $isReturn ? $qty : -$qty;
        $type = $isReturn ? 'Refund' : (in_array($st, ['shipped', 'delivered'], true) ? 'Sale' : 'Sale');
        $code = trim((string)($o['order_code'] ?? ''));
        if ($code === '') {
            $code = 'ORD-' . str_pad((string)((int)$o['id']), 6, '0', STR_PAD_LEFT);
        }
        $ts = strtotime((string)($o['created_at'] ?? '')) ?: 0;
        $history[] = [
            'ts' => $ts,
            'when' => $ts ? date('M j, Y g:i A', $ts) : '—',
            'type' => $type,
            'type_cls' => $isReturn ? 'refund' : 'sale',
            'ref' => $code,
            'ref_href' => 'order_details.php?id=' . (int)$o['id'] . ($fromSales ? '&from=sales' : ''),
            'variant' => $variantName,
            'change' => $change,
            'qty' => $qty,
            'after' => $running,
        ];
        $running -= $change;
    }
    $createdTs = strtotime((string)($product['created_at'] ?? '')) ?: 0;
    if ($createdTs > 0) {
        $history[] = [
            'ts' => $createdTs,
            'when' => date('M j, Y g:i A', $createdTs),
            'type' => 'Initial Stock',
            'type_cls' => 'initial',
            'ref' => '—',
            'ref_href' => '',
            'variant' => $variantName,
            'change' => $running,
            'qty' => max(0, $running),
            'after' => $running,
        ];
    }
} catch (Throwable $e) {
}

$sellerName = 'Seller';
try {
    $pubId = (int)($_SESSION['org_publisher_user_id'] ?? 0);
    if ($pubId <= 0 && function_exists('org_shop_publisher_user_id')) {
        $pubId = org_shop_publisher_user_id($dbh, (int)$orgId);
    }
    if ($pubId > 0) {
        $stU = $dbh->prepare('SELECT username FROM users WHERE id = :id LIMIT 1');
        $stU->execute([':id' => $pubId]);
        $nm = trim((string)($stU->fetchColumn() ?: ''));
        if ($nm !== '') {
            $sellerName = $nm;
        }
    }
} catch (Throwable $e) {
}

$notiCount = 0;
$msgCount = 0;
if (!empty($GLOBALS['dashNotiCount'])) {
    $notiCount = (int)$GLOBALS['dashNotiCount'];
}
if (!empty($GLOBALS['dashMsgCount'])) {
    $msgCount = (int)$GLOBALS['dashMsgCount'];
}

$showAlert = $stockCls === 'low' || $stockCls === 'out';
?>
<style>
  .invd{--t:#0f172a;--m:#64748b;--b:#e2e8f0;--c:#fff;color:var(--t);}
  .invd a{color:#2563eb;}
  .invd .invd-crumb{font-size:12px;font-weight:700;color:var(--m);margin-bottom:6px;}
  .invd .invd-crumb a{color:#64748b;text-decoration:none;}
  .invd .invd-crumb a:hover{color:#2563eb;}
  .invd .invd-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;flex-wrap:wrap;margin-bottom:14px;}
  .invd .invd-head h1{margin:0;font-size:28px;font-weight:800;line-height:1.15;}
  .invd .invd-head p{margin:4px 0 0;color:var(--m);font-size:13px;}
  .invd .invd-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center;}
  .invd .invd-btn{height:32px;padding:0 12px;border-radius:8px;border:1px solid #cbd5e1;background:#fff;font-size:12px;font-weight:700;display:inline-flex;align-items:center;gap:6px;color:#0f172a;text-decoration:none;cursor:pointer;}
  .invd .invd-btn.primary{background:#2563eb;border-color:#2563eb;color:#fff;}
  .invd .invd-card{background:var(--c);border:1px solid var(--b);border-radius:12px;padding:16px;margin-bottom:12px;}
  .invd .invd-summary{display:grid;grid-template-columns:minmax(260px,1.2fr) minmax(0,1.6fr);gap:16px;align-items:center;}
  .invd .invd-prod{display:flex;gap:12px;align-items:center;}
  .invd .invd-thumb{width:64px;height:64px;border-radius:10px;background:#f1f5f9;overflow:hidden;position:relative;flex:0 0 auto;display:flex;align-items:center;justify-content:center;color:#94a3b8;}
  .invd .invd-thumb img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;}
  .invd .invd-title{display:flex;align-items:center;gap:8px;flex-wrap:wrap;}
  .invd .invd-title strong{font-size:18px;font-weight:800;}
  .invd .invd-meta{font-size:12px;color:var(--m);margin-top:4px;line-height:1.5;}
  .invd .invd-pill{display:inline-flex;align-items:center;padding:3px 8px;border-radius:999px;font-size:11px;font-weight:800;}
  .invd .invd-pill.active{background:#dcfce7;color:#15803d;}
  .invd .invd-pill.draft{background:#f1f5f9;color:#475569;}
  .invd .invd-pill.in{background:#dcfce7;color:#15803d;}
  .invd .invd-pill.low{background:#ffedd5;color:#c2410c;}
  .invd .invd-pill.out{background:#fee2e2;color:#b91c1c;}
  .invd .invd-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:0;}
  .invd .invd-stat{padding:0 14px;border-left:1px solid var(--b);}
  .invd .invd-stat:first-child{border-left:0;}
  .invd .invd-stat .k{font-size:11px;font-weight:700;color:var(--m);}
  .invd .invd-stat .v{font-size:18px;font-weight:800;margin-top:4px;}
  .invd .invd-stat .s{font-size:11px;color:var(--m);margin-top:2px;}
  .invd .invd-tabs{display:flex;gap:18px;border-bottom:1px solid var(--b);margin-bottom:12px;}
  .invd .invd-tab{padding:10px 0 8px;font-size:13px;font-weight:700;color:var(--m);background:none;border:0;border-bottom:2px solid transparent;cursor:pointer;}
  .invd .invd-tab.is-on{color:#2563eb;border-bottom-color:#2563eb;}
  .invd .invd-toolbar{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:12px;}
  .invd .invd-search{display:flex;align-items:center;gap:6px;flex:1 1 200px;height:34px;padding:0 10px;border:1px solid var(--b);border-radius:8px;background:#fff;}
  .invd .invd-search input{border:0;flex:1;height:32px;background:transparent;font-size:12px;}
  .invd table{width:100%;border-collapse:collapse;}
  .invd th{font-size:11px;font-weight:800;letter-spacing:.04em;text-transform:uppercase;color:var(--m);text-align:left;padding:10px 12px;border-bottom:1px solid var(--b);background:#f8fafc;white-space:nowrap;}
  .invd td{padding:12px;border-bottom:1px solid var(--b);vertical-align:middle;font-size:13px;}
  .invd .invd-swatch{width:14px;height:14px;border-radius:50%;border:1px solid rgba(15,23,42,.12);display:inline-block;margin-right:8px;vertical-align:middle;}
  .invd .invd-avail{color:#15803d;font-weight:800;}
  .invd .invd-res{color:#c2410c;font-weight:800;}
  .invd .invd-type.sale{color:#dc2626;font-weight:800;}
  .invd .invd-type.refund{color:#ea580c;font-weight:800;}
  .invd .invd-type.initial{color:#16a34a;font-weight:800;}
  .invd .invd-chg.neg{color:#dc2626;font-weight:800;}
  .invd .invd-chg.pos{color:#16a34a;font-weight:800;}
  .invd .invd-card-h{display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:10px;}
  .invd .invd-card-h h2{margin:0;font-size:16px;font-weight:800;}
  .invd .invd-more{position:relative;}
  .invd .invd-more-btn{width:28px;height:28px;border-radius:8px;border:1px solid #dbeafe;background:#eff6ff;color:#2563eb;cursor:pointer;font-weight:800;}
  .invd .invd-more-menu{display:none;position:absolute;right:0;top:32px;z-index:40;min-width:200px;background:#fff;border:1px solid var(--b);border-radius:12px;box-shadow:0 12px 32px rgba(15,23,42,.16);padding:6px;}
  .invd .invd-more.is-open .invd-more-menu{display:block;}
  .invd .invd-more-menu a,.invd .invd-more-menu button{display:flex;align-items:center;gap:8px;width:100%;text-align:left;padding:8px 10px;border:0;border-radius:8px;background:transparent;font-size:13px;font-weight:600;color:#0f172a;text-decoration:none;cursor:pointer;}
  .invd .invd-more-menu a:hover,.invd .invd-more-menu button:hover{background:#f8fafc;}
  .invd .invd-more-menu .is-danger{color:#dc2626;}
  .invd .invd-more-form{margin:0;}
  .invd-modal{display:none;position:fixed;inset:0;background:rgba(15,23,42,.35);z-index:80;align-items:center;justify-content:center;padding:16px;}
  .invd-modal.is-on{display:flex;}
  .invd-modal-card{background:#fff;border-radius:12px;padding:18px;width:min(420px,100%);box-shadow:0 20px 50px rgba(15,23,42,.2);}
  .invd-modal-card h3{margin:0 0 10px;font-size:16px;font-weight:800;}
  .invd-modal-card input{width:100%;height:36px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;margin:6px 0 12px;}
  .invd-modal-actions{display:flex;justify-content:flex-end;gap:8px;}
  .invd .invd-empty{color:var(--m);padding:16px;text-align:center;}
  html.dark-auto .invd{--t:var(--msb-palette-text,#e2e8f0);--m:#94a3b8;--b:rgba(148,163,184,.22);--c:var(--msb-palette-bg,#171d24);}
  @media (max-width:900px){.invd .invd-summary,.invd .invd-stats{grid-template-columns:1fr;}.invd .invd-stat{border-left:0;border-top:1px solid var(--b);padding:10px 0;}}
  @media (min-width:901px){
    html[data-sales-active-view="inventory-detail"],html[data-sales-active-view="inventory-detail"] body.org-app,html[data-sales-active-view="inventory-detail"] body.org-app .sh-mainpanel,html[data-sales-active-view="inventory-detail"] body.org-app .sh-pagebody{overflow:hidden!important;}
    .sales-management-view[data-sales-view="inventory-detail"].is-active,
    html[data-sales-initial-view="inventory-detail"] .sales-management-view[data-sales-view="inventory-detail"],
    html[data-sales-active-view="inventory-detail"] .sales-management-view[data-sales-view="inventory-detail"]{height:calc(100vh - var(--org-header-h,48px) - 24px);max-height:calc(100vh - var(--org-header-h,48px) - 24px);overflow:hidden!important;padding-bottom:4px;}
    .sales-management-view[data-sales-view="inventory-detail"] .invd{height:100%;display:grid;grid-template-columns:minmax(0,1.25fr) minmax(0,.75fr);grid-template-rows:auto auto auto auto minmax(0,1fr);gap:8px;overflow:hidden;}
    .invd .invd-crumb,.invd .invd-head,.invd .invd-summary-card,.invd .invd-variants-card{grid-column:1/-1;}
    .invd .invd-history-card{grid-column:1;grid-row:5;}.invd .invd-alerts-card{grid-column:2;grid-row:5;}
    .invd .invd-crumb{margin:0;font-size:10px;}
    .invd .invd-head{margin:0;align-items:center;}
    .invd .invd-head h1{font-size:22px;}.invd .invd-head p{font-size:11px;margin-top:1px;}
    .invd .invd-card{margin:0;padding:9px 11px;min-height:0;overflow:hidden;}
    .invd .invd-summary{gap:10px;}.invd .invd-thumb{width:48px;height:48px;}.invd .invd-title strong{font-size:15px;}.invd .invd-meta{font-size:10px;line-height:1.3;margin-top:2px;}
    .invd .invd-stat{padding:0 10px;}.invd .invd-stat .k,.invd .invd-stat .s{font-size:9px;}.invd .invd-stat .v{font-size:15px;margin-top:2px;}
    .invd .invd-tabs{margin-bottom:6px;}.invd .invd-tab{padding:4px 0;font-size:11px;}.invd .invd-toolbar{margin-bottom:6px;}.invd .invd-search{height:29px;}.invd .invd-btn{height:29px;font-size:10px;}
    .invd th{padding:6px 8px;font-size:9px;}.invd td{padding:7px 8px;font-size:10px;}.invd .invd-pill{font-size:9px;padding:2px 6px;}
    .invd .invd-history-card,.invd .invd-alerts-card{height:100%;overflow:hidden;}.invd .invd-card-h{margin-bottom:5px;}.invd .invd-card-h h2{font-size:13px;}.invd .invd-empty{padding:8px;font-size:10px;}
    .invd .invd-history-card tbody tr:nth-child(n+4){display:none;}
  }
</style>
<div class="invd" id="invdRoot">
  <div class="invd-crumb"><a href="<?= h($backHref) ?>">Inventory</a> &gt; Product Inventory</div>
  <div class="invd-head">
    <div>
      <h1>Inventory</h1>
      <p>Track and manage your stock across all products and variants.</p>
    </div>
    <div class="invd-actions">
      <a class="sd-icon-btn" href="sales_notifications.php" title="Notifications"><i class="fa fa-bell-o"></i><?php if ($notiCount > 0): ?><span class="sd-badge"><?= (int)min(99, $notiCount) ?></span><?php endif; ?></a>
      <a class="sd-icon-btn" href="<?= $fromSales ? 'sales_management.php#message' : 'sales_management.php#message' ?>" title="Messages"><i class="fa fa-commenting-o"></i><?php if ($msgCount > 0): ?><span class="sd-badge"><?= (int)min(99, $msgCount) ?></span><?php endif; ?></a>
      <button type="button" class="invd-btn" id="invdExportBtn"><i class="fa fa-download"></i> Export</button>
      <a class="invd-btn" href="<?= h($alertsHref) ?>"><i class="fa fa-bell-o"></i> View Stock Alerts</a>
    </div>
  </div>

  <?php if ($err !== ''): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endif; ?>
  <?php if ($ok !== ''): ?><div class="alert alert-success"><?= h($ok) ?></div><?php endif; ?>

  <div class="invd-card invd-summary-card">
    <div class="invd-summary">
      <div class="invd-prod">
        <div class="invd-thumb"><?php if ($cover !== ''): ?><img src="<?= h($cover) ?>" alt="" onerror="this.remove()"><i class="fa fa-cube"></i><?php else: ?><i class="fa fa-cube"></i><?php endif; ?></div>
        <div>
          <div class="invd-title">
            <strong><?= h($title) ?></strong>
            <span class="invd-pill <?= $listingLabel === 'Draft' ? 'draft' : 'active' ?>"><?= h($listingLabel) ?></span>
          </div>
          <div class="invd-meta">
            SKU: <?= h($sku !== '' ? $sku : '—') ?><br>
            Category: <?= h($category) ?><br>
            Price: <?= h($price) ?>
          </div>
          <a href="<?= h($productViewHref) ?>">View Product <i class="fa fa-external-link"></i></a>
        </div>
      </div>
      <div class="invd-stats">
        <div class="invd-stat"><div class="k">Total Stock</div><div class="v"><?= (int)$totalStock ?> units</div><div class="s">Across <?= (int)$variantCount ?> variant<?= $variantCount === 1 ? '' : 's' ?></div></div>
        <div class="invd-stat"><div class="k">Available</div><div class="v"><?= (int)$available ?> units</div><div class="s"><?= $pct($available, $totalStock) ?>% of total</div></div>
        <div class="invd-stat"><div class="k">Reserved</div><div class="v"><?= (int)$reserved ?> unit<?= $reserved === 1 ? '' : 's' ?></div><div class="s"><?= $pct($reserved, $totalStock) ?>% of total</div></div>
        <div class="invd-stat"><div class="k">Out of Stock</div><div class="v"><?= (int)$oosUnits ?> unit<?= $oosUnits === 1 ? '' : 's' ?></div><div class="s"><?= $pct($oosUnits, max(1, $variantCount)) ?>% of total</div></div>
      </div>
    </div>
  </div>

  <div class="invd-card invd-variants-card">
    <div class="invd-tabs">
      <button type="button" class="invd-tab is-on" data-invd-tab="variants">Variants</button>
      <button type="button" class="invd-tab" data-invd-tab="moves">Stock Movements</button>
    </div>
    <div data-invd-panel="variants">
      <div class="invd-toolbar">
        <label class="invd-search"><i class="icon ion-ios-search"></i><input type="search" id="invdVarSearch" placeholder="Search variants..."></label>
        <button type="button" class="invd-btn" id="invdFilterBtn"><i class="fa fa-filter"></i> Filter</button>
        <button type="button" class="invd-btn primary" id="invdUpdateBtn"><i class="fa fa-plus"></i> Update Inventory</button>
      </div>
      <div style="overflow:auto;">
        <table id="invdVarTable">
          <thead>
            <tr>
              <th>Variant</th><th>SKU</th><th>Attributes</th><th>Total Stock</th><th>Available</th><th>Reserved</th><th>Incoming</th><th>Status</th><th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr class="invd-var-row" data-search="<?= h(mb_strtolower($variantName . ' ' . $sku)) ?>">
              <td>
                <span class="invd-swatch" style="background:<?= h(invd_color_css($colorName !== '' ? $colorName : $variantName)) ?>"></span>
                <strong><?= h($variantName) ?></strong>
                <span class="invd-pill active" style="margin-left:6px;">Default</span>
              </td>
              <td><strong><?= h($sku !== '' ? $sku : '—') ?></strong></td>
              <td>
                <?php if (!$attrRows): ?>
                  <span class="invd-meta">—</span>
                <?php else: foreach ($attrRows as $ar): ?>
                  <div><?= h((string)$ar['label']) ?>: <?= h((string)$ar['value']) ?></div>
                <?php endforeach; endif; ?>
              </td>
              <td><strong><?= (int)$totalStock ?></strong></td>
              <td class="invd-avail"><?= (int)$available ?></td>
              <td class="invd-res"><?= (int)$reserved ?></td>
              <td>—</td>
              <td><span class="invd-pill <?= h($stockCls) ?>"><?= h($stockLabel) ?></span></td>
              <td>
                <div class="invd-more">
                  <button type="button" class="invd-more-btn" aria-label="Variant actions">⋯</button>
                  <div class="invd-more-menu">
                    <button type="button" class="invd-open-stock"><i class="fa fa-cube"></i> Adjust Stock</button>
                    <a href="<?= h($productViewHref) ?>"><i class="fa fa-eye"></i> View Product</a>
                    <a href="<?= h($editHref) ?>"><i class="fa fa-pencil"></i> Edit Product</a>
                    <?php if ($stockCls !== 'out'): ?>
                    <form method="post" action="<?= h($formAction) ?>" class="invd-more-form">
                      <input type="hidden" name="inv_action" value="1">
                      <input type="hidden" name="action" value="out_of_stock">
                      <input type="hidden" name="product_id" value="<?= (int)$productId ?>">
                      <input type="hidden" name="from" value="<?= $fromSales ? 'sales' : '' ?>">
                      <?php if ($fromSales): ?><input type="hidden" name="from_view" value="inventory-detail"><?php endif; ?>
                      <button type="submit"><i class="fa fa-ban"></i> Mark Out of Stock</button>
                    </form>
                    <?php endif; ?>
                    <form method="post" action="<?= h($formAction) ?>" class="invd-more-form" onsubmit="return confirm('Delete this product?');">
                      <input type="hidden" name="inv_action" value="1">
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="product_id" value="<?= (int)$productId ?>">
                      <input type="hidden" name="from" value="<?= $fromSales ? 'sales' : '' ?>">
                      <?php if ($fromSales): ?><input type="hidden" name="from_view" value="inventory-detail"><?php endif; ?>
                      <button type="submit" class="is-danger"><i class="fa fa-trash"></i> Delete Product</button>
                    </form>
                  </div>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
    <div data-invd-panel="moves" hidden>
      <?php if (!$history): ?>
        <p class="invd-empty">No stock movements yet.</p>
      <?php else: ?>
        <div style="overflow:auto;">
          <table>
            <thead><tr><th>Date &amp; Time</th><th>Type</th><th>Reference</th><th>Variant</th><th>Change</th><th>Quantity</th><th>Stock After</th><th>User</th></tr></thead>
            <tbody>
              <?php foreach ($history as $ev): ?>
                <tr>
                  <td><?= h((string)$ev['when']) ?></td>
                  <td class="invd-type <?= h((string)$ev['type_cls']) ?>"><?= h((string)$ev['type']) ?></td>
                  <td><?php if ((string)$ev['ref_href'] !== ''): ?><a href="<?= h((string)$ev['ref_href']) ?>"><?= h((string)$ev['ref']) ?></a><?php else: ?><?= h((string)$ev['ref']) ?><?php endif; ?></td>
                  <td><?= h((string)$ev['variant']) ?></td>
                  <td class="invd-chg <?= ((int)$ev['change'] < 0) ? 'neg' : 'pos' ?>"><?= ((int)$ev['change'] > 0 ? '+' : '') . (int)$ev['change'] ?></td>
                  <td><?= (int)$ev['qty'] ?></td>
                  <td><?= (int)$ev['after'] ?></td>
                  <td><?= h($sellerName) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="invd-card invd-history-card">
    <div class="invd-card-h">
      <h2>Stock History</h2>
      <div class="invd-actions">
        <select id="invdHistType">
          <option value="">All Types</option>
          <option value="Sale">Sale</option>
          <option value="Refund">Refund</option>
          <option value="Initial Stock">Initial Stock</option>
        </select>
      </div>
    </div>
    <?php if (!$history): ?>
      <p class="invd-empty">No stock history yet.</p>
    <?php else: ?>
      <div style="overflow:auto;">
        <table id="invdHistTable">
          <thead><tr><th>Date &amp; Time</th><th>Type</th><th>Reference</th><th>Variant</th><th>Change</th><th>Quantity</th><th>Stock After</th><th>User</th></tr></thead>
          <tbody>
            <?php foreach ($history as $ev): ?>
              <tr data-type="<?= h((string)$ev['type']) ?>">
                <td><?= h((string)$ev['when']) ?></td>
                <td class="invd-type <?= h((string)$ev['type_cls']) ?>"><?= h((string)$ev['type']) ?></td>
                <td><?php if ((string)$ev['ref_href'] !== ''): ?><a href="<?= h((string)$ev['ref_href']) ?>"><?= h((string)$ev['ref']) ?></a><?php else: ?><?= h((string)$ev['ref']) ?><?php endif; ?></td>
                <td><?= h((string)$ev['variant']) ?></td>
                <td class="invd-chg <?= ((int)$ev['change'] < 0) ? 'neg' : 'pos' ?>"><?= ((int)$ev['change'] > 0 ? '+' : '') . (int)$ev['change'] ?></td>
                <td><?= (int)$ev['qty'] ?></td>
                <td><?= (int)$ev['after'] ?></td>
                <td><?= h($sellerName) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <div class="invd-card invd-alerts-card">
    <div class="invd-card-h">
      <h2>Low Stock Alerts</h2>
      <a href="<?= h($alertsHref) ?>">View All Alerts</a>
    </div>
    <?php if (!$showAlert): ?>
      <p class="invd-empty">No low-stock alerts for this product.</p>
    <?php else: ?>
      <div style="overflow:auto;">
        <table>
          <thead><tr><th>Variant</th><th>Current Stock</th><th>Threshold</th><th>Status</th><th></th></tr></thead>
          <tbody>
            <tr>
              <td><span class="invd-swatch" style="background:<?= h(invd_color_css($colorName !== '' ? $colorName : $variantName)) ?>"></span><?= h($variantName) ?></td>
              <td style="font-weight:800;color:<?= $stockCls === 'out' ? '#b91c1c' : '#c2410c' ?>"><?= (int)$available ?> units</td>
              <td><?= (int)$lowStockAt ?> units</td>
              <td><span class="invd-pill <?= h($stockCls) ?>"><?= h($stockLabel) ?></span></td>
              <td><button type="button" class="invd-btn invd-open-stock">Update Stock</button></td>
            </tr>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<div class="invd-modal" id="invdStockModal">
  <div class="invd-modal-card">
    <h3>Update Inventory</h3>
    <form method="post" action="<?= h($formAction) ?>">
      <input type="hidden" name="invd_stock" value="1">
      <input type="hidden" name="product_id" value="<?= (int)$productId ?>">
      <input type="hidden" name="from" value="<?= $fromSales ? 'sales' : '' ?>">
      <?php if ($fromSales): ?><input type="hidden" name="from_view" value="inventory-detail"><?php endif; ?>
      <label for="invdStockQty">Available units</label>
      <input type="number" id="invdStockQty" name="stock_qty" min="0" value="<?= (int)$available ?>" required>
      <div class="invd-modal-actions">
        <button type="button" class="invd-btn" id="invdStockCancel">Cancel</button>
        <button type="submit" class="invd-btn primary">Save stock</button>
      </div>
    </form>
  </div>
</div>

<script>
(function () {
  var root = document.getElementById('invdRoot');
  if (!root) return;
  var modal = document.getElementById('invdStockModal');
  function openStock() { if (modal) modal.classList.add('is-on'); }
  function closeStock() { if (modal) modal.classList.remove('is-on'); }
  document.querySelectorAll('.invd-open-stock, #invdUpdateBtn').forEach(function (el) {
    el.addEventListener('click', function (e) { e.preventDefault(); openStock(); });
  });
  var cancel = document.getElementById('invdStockCancel');
  if (cancel) cancel.addEventListener('click', closeStock);
  if (modal) modal.addEventListener('click', function (e) { if (e.target === modal) closeStock(); });

  root.querySelectorAll('[data-invd-tab]').forEach(function (tab) {
    tab.addEventListener('click', function () {
      var id = tab.getAttribute('data-invd-tab');
      root.querySelectorAll('[data-invd-tab]').forEach(function (t) { t.classList.toggle('is-on', t === tab); });
      root.querySelectorAll('[data-invd-panel]').forEach(function (p) {
        p.hidden = p.getAttribute('data-invd-panel') !== id;
      });
    });
  });

  var search = document.getElementById('invdVarSearch');
  var filterBtn = document.getElementById('invdFilterBtn');
  function filterVars() {
    var q = String(search && search.value || '').trim().toLowerCase();
    root.querySelectorAll('.invd-var-row').forEach(function (row) {
      row.hidden = !!(q && String(row.getAttribute('data-search') || '').indexOf(q) === -1);
    });
  }
  if (search) search.addEventListener('input', filterVars);
  if (filterBtn) filterBtn.addEventListener('click', filterVars);

  var histType = document.getElementById('invdHistType');
  if (histType) {
    histType.addEventListener('change', function () {
      var v = histType.value;
      root.querySelectorAll('#invdHistTable tbody tr').forEach(function (row) {
        row.hidden = !!(v && row.getAttribute('data-type') !== v);
      });
    });
  }

  function closeMenus() {
    root.querySelectorAll('.invd-more.is-open').forEach(function (el) { el.classList.remove('is-open'); });
  }
  root.addEventListener('click', function (e) {
    var btn = e.target.closest('.invd-more-btn');
    if (btn) {
      var wrap = btn.closest('.invd-more');
      var open = wrap.classList.contains('is-open');
      closeMenus();
      if (!open) wrap.classList.add('is-open');
      e.preventDefault();
      e.stopPropagation();
      return;
    }
    if (!e.target.closest('.invd-more')) closeMenus();
  });
  document.addEventListener('click', function (e) {
    if (!e.target.closest('#invdRoot')) closeMenus();
  });

  var exportBtn = document.getElementById('invdExportBtn');
  if (exportBtn) {
    exportBtn.addEventListener('click', function () {
      var lines = [['Date', 'Type', 'Reference', 'Variant', 'Change', 'Quantity', 'Stock After', 'User']];
      root.querySelectorAll('#invdHistTable tbody tr').forEach(function (row) {
        var cells = Array.prototype.map.call(row.querySelectorAll('td'), function (td) { return td.textContent.trim(); });
        if (cells.length) lines.push(cells);
      });
      var csv = lines.map(function (cols) {
        return cols.map(function (c) { return '"' + String(c).replace(/"/g, '""') + '"'; }).join(',');
      }).join('\n');
      var a = document.createElement('a');
      a.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8;' }));
      a.download = 'inventory-history.csv';
      a.click();
    });
  }
})();
</script>
