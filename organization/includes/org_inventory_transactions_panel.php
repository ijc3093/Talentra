<?php
declare(strict_types=1);

/**
 * Inventory transactions for transactions.php and sales_management.php#transactions.
 *
 * Expected: PDO $dbh, int $orgId
 * Optional: $txnInSalesHub, $txnShowPageHead, $txnInventoryHref, $txnInventoryAttr,
 *           $txnProductBase, $txnProductSuffix, $txnNotiCount, $txnMsgCount, $err, $ok
 */

if (!function_exists('h') && function_exists('org_ecommerce_h')) {
    function h(string $s): string
    {
        return org_ecommerce_h($s);
    }
}

if (!function_exists('product_table_cover_url')) {
    function product_table_cover_url(string $path): string
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

if (!function_exists('inv_pct')) {
    function inv_pct(int $cur, int $prev): array
    {
        if ($prev <= 0) {
            return [$cur > 0 ? 100.0 : 0.0, $cur >= $prev];
        }
        $pct = (($cur - $prev) / $prev) * 100;
        return [round($pct, 1), $pct >= 0];
    }
}

if (!function_exists('txn_pad_ref')) {
    function txn_pad_ref(string $prefix, int $id): string
    {
        return $prefix . '-' . str_pad((string)max(0, $id), 6, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('txn_variant_label')) {
    function txn_variant_label(string $json): string
    {
        $attrs = json_decode($json, true);
        if (!is_array($attrs) || $attrs === []) {
            return '';
        }
        $bits = [];
        foreach ($attrs as $v) {
            if (is_string($v) && trim($v) !== '') {
                $bits[] = trim($v);
            } elseif (is_array($v)) {
                foreach ($v as $x) {
                    if (is_string($x) && trim($x) !== '') {
                        $bits[] = trim($x);
                    }
                }
            }
            if (count($bits) >= 2) {
                break;
            }
        }
        return implode(' · ', $bits);
    }
}

$txnInSalesHub = !empty($txnInSalesHub);
$txnShowPageHead = array_key_exists('txnShowPageHead', get_defined_vars())
    ? !empty($txnShowPageHead)
    : !$txnInSalesHub;
$txnInventoryHref = (string)($txnInventoryHref ?? ($txnInSalesHub ? '#inventory' : 'product_table.php'));
$txnInventoryAttr = (string)($txnInventoryAttr ?? ($txnInSalesHub ? ' data-sales-nav="inventory"' : ''));
$txnProductBase = (string)($txnProductBase ?? ($txnInSalesHub ? 'sales_management.php?inv_product=' : 'product_table.php?id='));
$txnProductSuffix = (string)($txnProductSuffix ?? ($txnInSalesHub ? '#inventory-detail' : ''));
$txnNotiCount = (int)($txnNotiCount ?? 0);
$txnMsgCount = (int)($txnMsgCount ?? 0);
$txnNotiHref = (string)($txnNotiHref ?? 'sales_notifications.php');
$txnMsgHref = (string)($txnMsgHref ?? ($txnInSalesHub ? '#message' : 'sales_management.php#message'));
$txnMsgAttr = (string)($txnMsgAttr ?? ($txnInSalesHub ? ' data-sales-nav="message"' : ''));
$err = (string)($err ?? '');
$ok = (string)($ok ?? '');

$txnRange = (int)($_GET['txn_range'] ?? 7);
if (!in_array($txnRange, [7, 14, 30], true)) {
    $txnRange = 7;
}

$now = time();
$rangeStart = $now - ($txnRange - 1) * 86400;
$prevStart = $rangeStart - $txnRange * 86400;
$rangeFrom = date('M j', $rangeStart);
$rangeTo = date('M j, Y', $now);

$productsById = [];
$stockNow = [];
if (function_exists('org_shop_list_products')) {
    foreach (org_shop_list_products($dbh, (int)$orgId, false) as $p) {
        $pid = (int)($p['id'] ?? 0);
        if ($pid <= 0) {
            continue;
        }
        $productsById[$pid] = $p;
        $tracked = $p['stock_qty'] !== null && $p['stock_qty'] !== '';
        $stockNow[$pid] = $tracked ? max(0, (int)$p['stock_qty']) : 0;
    }
}

$orders = [];
try {
    $st = $dbh->prepare("
        SELECT o.id, o.created_at, o.quantity, o.status, o.product_title, o.product_id,
               o.order_code, o.buyer_name, o.buyer_email, o.buyer_notes,
               p.title, p.sku, p.product_code, p.cover_image_path, p.stock_qty, p.attributes_json
        FROM org_orders o
        LEFT JOIN org_products p ON p.id = o.product_id AND p.is_deleted = 0
        WHERE o.org_id = :org
        ORDER BY o.created_at DESC, o.id DESC
        LIMIT 400
    ");
    $st->execute([':org' => (int)$orgId]);
    $orders = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $orders = [];
}

$events = [];
foreach ($orders as $o) {
    $oid = (int)($o['id'] ?? 0);
    $pid = (int)($o['product_id'] ?? 0);
    $st = strtolower(trim((string)($o['status'] ?? '')));
    $qty = max(1, (int)($o['quantity'] ?? 1));
    $ts = strtotime((string)($o['created_at'] ?? '')) ?: 0;
    $isReturn = in_array($st, ['cancelled', 'canceled'], true);
    $title = trim((string)($o['title'] ?? $o['product_title'] ?? 'Product')) ?: 'Product';
    $sku = trim((string)($o['sku'] ?? ''));
    if ($sku === '') {
        $sku = trim((string)($o['product_code'] ?? ''));
    }
    $code = trim((string)($o['order_code'] ?? ''));
    $buyer = trim((string)($o['buyer_name'] ?? ''));
    if ($isReturn) {
        $type = 'returned';
        $label = 'Stock Returned';
        $ref = $code !== '' ? $code : txn_pad_ref('RTN', $oid);
        $user = 'Customer Return';
        $signed = $qty;
        $note = 'Cancelled order returned to stock.';
    } else {
        $type = 'reserved';
        $label = 'Stock Reserved';
        $ref = $code !== '' ? $code : txn_pad_ref('RES', $oid);
        $user = $buyer !== '' ? $buyer : 'Customer';
        $signed = -$qty;
        $note = 'Order ' . ($st !== '' ? $st : 'open') . '.';
    }
    $events[] = [
        'ts' => $ts,
        'order_id' => $oid,
        'product_id' => $pid,
        'type' => $type,
        'label' => $label,
        'ref' => $ref,
        'title' => $title,
        'sku' => $sku !== '' ? $sku : '—',
        'variant' => txn_variant_label((string)($o['attributes_json'] ?? '')),
        'cover' => product_table_cover_url((string)($o['cover_image_path'] ?? '')),
        'location' => 'Direct Store',
        'qty' => $signed,
        'user' => $user,
        'note' => $note,
        'status' => $st,
    ];
}

foreach ($productsById as $pid => $p) {
    $ts = strtotime((string)($p['created_at'] ?? '')) ?: 0;
    if ($ts <= 0) {
        continue;
    }
    $qty = $stockNow[$pid] ?? 0;
    if ($qty <= 0) {
        continue;
    }
    $title = trim((string)($p['title'] ?? '')) ?: 'Untitled';
    $sku = trim((string)($p['sku'] ?? ''));
    if ($sku === '') {
        $sku = trim((string)($p['product_code'] ?? ''));
    }
    $events[] = [
        'ts' => $ts,
        'order_id' => 0,
        'product_id' => $pid,
        'type' => 'received',
        'label' => 'Stock Received',
        'ref' => txn_pad_ref('RCV', $pid),
        'title' => $title,
        'sku' => $sku !== '' ? $sku : '—',
        'variant' => txn_variant_label((string)($p['attributes_json'] ?? '')),
        'cover' => product_table_cover_url((string)($p['cover_image_path'] ?? '')),
        'location' => 'Direct Store',
        'qty' => $qty,
        'user' => 'System',
        'note' => 'Product listed with opening stock.',
        'status' => '',
    ];
}

usort($events, static function (array $a, array $b): int {
    $c = ((int)$b['ts']) <=> ((int)$a['ts']);
    if ($c !== 0) {
        return $c;
    }
    return ((int)($b['order_id'] ?? 0)) <=> ((int)($a['order_id'] ?? 0));
});

$running = $stockNow;
foreach ($events as $i => $ev) {
    $pid = (int)($ev['product_id'] ?? 0);
    $after = (int)($running[$pid] ?? 0);
    $events[$i]['after'] = $after;
    $events[$i]['when'] = !empty($ev['ts']) ? date('M j, Y g:i A', (int)$ev['ts']) : '—';
    $running[$pid] = $after - (int)($ev['qty'] ?? 0);
}

$kpi = ['total' => 0, 'received' => 0, 'reserved' => 0, 'adjustment' => 0, 'returned' => 0];
$kpiPrev = $kpi;
foreach ($events as $ev) {
    $ts = (int)($ev['ts'] ?? 0);
    $type = (string)($ev['type'] ?? '');
    if ($ts >= $rangeStart) {
        $kpi['total']++;
        if (isset($kpi[$type])) {
            $kpi[$type]++;
        }
    } elseif ($ts >= $prevStart) {
        $kpiPrev['total']++;
        if (isset($kpiPrev[$type])) {
            $kpiPrev[$type]++;
        }
    }
}

[$totPct, $totUp] = inv_pct((int)$kpi['total'], (int)$kpiPrev['total']);
[$rcvPct, $rcvUp] = inv_pct((int)$kpi['received'], (int)$kpiPrev['received']);
[$resPct, $resUp] = inv_pct((int)$kpi['reserved'], (int)$kpiPrev['reserved']);
[$adjPct, $adjUp] = inv_pct((int)$kpi['adjustment'], (int)$kpiPrev['adjustment']);
[$rtnPct, $rtnUp] = inv_pct((int)$kpi['returned'], (int)$kpiPrev['returned']);

$productNames = [];
foreach ($events as $ev) {
    $name = trim((string)($ev['title'] ?? ''));
    if ($name !== '') {
        $productNames[$name] = true;
    }
}
ksort($productNames, SORT_NATURAL | SORT_FLAG_CASE);

$typeMeta = [
    'received' => ['ico' => 'fa-arrow-down', 'cls' => 'rcv'],
    'reserved' => ['ico' => 'fa-arrow-up', 'cls' => 'res'],
    'adjustment' => ['ico' => 'fa-pencil', 'cls' => 'adj'],
    'returned' => ['ico' => 'fa-undo', 'cls' => 'rtn'],
];
?>
<style>
  .txn-dash{--txn-text:#0f172a;--txn-muted:#64748b;--txn-border:#e2e8f0;--txn-card:#fff;color:var(--txn-text);}
  .txn-dash .txn-crumb{font-size:12px;font-weight:700;color:var(--txn-muted);margin:0 0 6px;}
  .txn-dash .txn-crumb a{color:#2563eb;text-decoration:none;}
  .txn-dash .txn-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;flex-wrap:wrap;margin-bottom:14px;}
  .txn-dash .txn-head h1{margin:0;font-size:28px;font-weight:800;letter-spacing:-.03em;}
  .txn-dash .txn-sub{margin:4px 0 0;font-size:13px;color:var(--txn-muted);font-weight:600;}
  .txn-dash .txn-actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap;}
  .txn-dash .txn-ico-btn{position:relative;width:36px;height:36px;border-radius:10px;border:1px solid var(--txn-border);background:#fff;color:#334155;display:inline-flex;align-items:center;justify-content:center;text-decoration:none;}
  .txn-dash .txn-badge{position:absolute;top:-4px;right:-4px;min-width:16px;height:16px;padding:0 4px;border-radius:999px;background:#ef4444;color:#fff;font-size:9px;font-weight:800;display:flex;align-items:center;justify-content:center;}
  .txn-dash .txn-btn,.txn-dash .txn-range,.txn-dash .txn-filters select,.txn-dash .txn-filters button{height:36px;padding:0 12px;border-radius:10px;border:1px solid #cbd5e1;background:#fff;font-size:12px;font-weight:700;display:inline-flex;align-items:center;gap:6px;color:#0f172a;text-decoration:none;cursor:pointer;}
  .txn-dash .txn-kpis{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:8px;margin-bottom:10px;}
  .txn-dash .txn-kpi{background:var(--txn-card);border:1px solid var(--txn-border);border-radius:12px;padding:12px;}
  .txn-dash .txn-kpi-top{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:8px;}
  .txn-dash .txn-ico{width:28px;height:28px;border-radius:8px;display:inline-flex;align-items:center;justify-content:center;font-size:13px;}
  .txn-dash .txn-ico.blue{background:#dbeafe;color:#2563eb;}
  .txn-dash .txn-ico.green{background:#dcfce7;color:#16a34a;}
  .txn-dash .txn-ico.orange{background:#ffedd5;color:#ea580c;}
  .txn-dash .txn-ico.red{background:#fee2e2;color:#dc2626;}
  .txn-dash .txn-lab{font-size:11px;font-weight:700;color:var(--txn-muted);}
  .txn-dash .txn-val{font-size:22px;font-weight:800;line-height:1.15;margin-top:2px;}
  .txn-dash .txn-delta{font-size:11px;font-weight:700;}
  .txn-dash .txn-delta.up{color:#16a34a;}
  .txn-dash .txn-delta.down{color:#dc2626;}
  .txn-dash .txn-delta span{font-weight:600;color:var(--txn-muted);}
  .txn-dash .txn-filters{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-bottom:10px;}
  .txn-dash .txn-search{display:flex;align-items:center;gap:6px;flex:1 1 240px;min-width:180px;height:36px;padding:0 10px;border:1px solid var(--txn-border);border-radius:10px;background:#fff;}
  .txn-dash .txn-search input{border:0;box-shadow:none;height:32px;flex:1;padding:0;background:transparent;font-size:13px;}
  .txn-dash .txn-card{background:var(--txn-card);border:1px solid var(--txn-border);border-radius:12px;overflow:hidden;}
  .txn-dash .txn-table-wrap{overflow:auto;}
  .txn-dash .txn-table{width:100%;min-width:1180px;border-collapse:collapse;}
  .txn-dash .txn-table th{font-size:11px;font-weight:800;letter-spacing:.04em;text-transform:uppercase;color:var(--txn-muted);text-align:left;padding:10px 12px;border-bottom:1px solid var(--txn-border);background:#f8fafc;white-space:nowrap;}
  .txn-dash .txn-table td{padding:12px;border-bottom:1px solid var(--txn-border);vertical-align:middle;font-size:13px;}
  .txn-dash .txn-exp{width:28px;height:28px;border:0;background:transparent;color:#64748b;cursor:pointer;font-size:12px;}
  .txn-dash .txn-row.is-open .txn-exp i{transform:rotate(90deg);display:inline-block;}
  .txn-dash .txn-detail td{background:#f8fafc;font-size:12px;color:var(--txn-muted);font-weight:600;}
  .txn-dash .txn-pill{display:inline-flex;align-items:center;gap:6px;padding:4px 8px;border-radius:999px;font-size:11px;font-weight:800;white-space:nowrap;}
  .txn-dash .txn-pill.rcv{background:#dcfce7;color:#15803d;}
  .txn-dash .txn-pill.res{background:#fee2e2;color:#b91c1c;}
  .txn-dash .txn-pill.adj{background:#dbeafe;color:#1d4ed8;}
  .txn-dash .txn-pill.rtn{background:#ffedd5;color:#c2410c;}
  .txn-dash .txn-ref{color:#2563eb;font-weight:800;text-decoration:none;}
  .txn-dash .txn-prod{display:flex;align-items:center;gap:10px;min-width:0;}
  .txn-dash .txn-thumb{width:40px;height:40px;border-radius:8px;background:#f1f5f9;overflow:hidden;display:flex;align-items:center;justify-content:center;color:#94a3b8;flex:0 0 auto;position:relative;}
  .txn-dash .txn-thumb img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;z-index:1;}
  .txn-dash .txn-name{display:block;font-weight:800;color:inherit;text-decoration:none;}
  .txn-dash .txn-name-sub{display:block;font-size:11px;color:var(--txn-muted);font-weight:600;margin-top:2px;}
  .txn-dash .txn-qty.plus{color:#16a34a;font-weight:800;}
  .txn-dash .txn-qty.minus{color:#dc2626;font-weight:800;}
  .txn-dash .txn-more{position:relative;}
  .txn-dash .txn-more-btn{width:28px;height:28px;border-radius:8px;border:1px solid #dbeafe;background:#eff6ff;color:#2563eb;cursor:pointer;font-size:16px;line-height:1;font-weight:800;}
  .txn-dash .txn-more.is-open .txn-more-btn{background:#dbeafe;}
  .txn-dash .txn-more-menu{display:none;position:absolute;right:0;top:32px;z-index:80;min-width:200px;background:#fff;border:1px solid #e2e8f0;border-radius:12px;box-shadow:0 12px 32px rgba(15,23,42,.16);padding:6px;}
  .txn-dash .txn-more.is-open .txn-more-menu{display:block;}
  .txn-dash .txn-more-menu a,.txn-dash .txn-more-menu button{display:flex;align-items:center;gap:10px;width:100%;text-align:left;padding:8px 10px;border:0;border-radius:8px;background:transparent;font-size:13px;font-weight:600;color:#0f172a;text-decoration:none;cursor:pointer;}
  .txn-dash .txn-more-menu a:hover,.txn-dash .txn-more-menu button:hover{background:#f8fafc;}
  .txn-dash .txn-more-menu i{width:16px;text-align:center;font-size:14px;}
  .txn-dash .txn-menu-sep{height:1px;background:#e2e8f0;margin:6px -6px;}
  .txn-dash .txn-more-menu .txn-danger{color:#dc2626;}
  .txn-dash .txn-foot{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:10px;padding:10px 12px;font-size:12px;color:var(--txn-muted);}
  .txn-dash .txn-pages{display:flex;gap:4px;align-items:center;flex-wrap:wrap;}
  .txn-dash .txn-pages button{min-width:28px;height:28px;border:1px solid #e2e8f0;background:#fff;border-radius:7px;font-size:12px;font-weight:700;cursor:pointer;}
  .txn-dash .txn-pages button.is-on{background:#2563eb;border-color:#2563eb;color:#fff;}
  .txn-dash .txn-empty{text-align:center;padding:28px 12px;color:var(--txn-muted);}
  html.dark-auto .txn-dash{--txn-text:var(--msb-palette-text,#e2e8f0);--txn-muted:#94a3b8;--txn-border:rgba(148,163,184,.22);--txn-card:var(--msb-palette-bg,#171d24);}
  html.dark-auto .txn-dash .txn-ico-btn,html.dark-auto .txn-dash .txn-btn,html.dark-auto .txn-dash .txn-range,html.dark-auto .txn-dash .txn-search,html.dark-auto .txn-dash .txn-filters select,html.dark-auto .txn-dash .txn-filters button{background:var(--txn-card);color:var(--txn-text);border-color:var(--txn-border);}
  html.dark-auto .txn-dash .txn-table th,html.dark-auto .txn-dash .txn-detail td{background:rgba(148,163,184,.08);}
  html.dark-auto .txn-dash .txn-more-menu{background:var(--txn-card);border-color:var(--txn-border);}
  @media (max-width:1100px){.txn-dash .txn-kpis{grid-template-columns:1fr 1fr;}}
  @media (max-width:700px){.txn-dash .txn-kpis{grid-template-columns:1fr;}.txn-dash .txn-head h1{font-size:22px;}}
</style>
<div class="txn-dash" id="txnDashRoot">
  <?php if ($txnShowPageHead): ?>
    <p class="txn-crumb"><a href="<?= h($txnInventoryHref) ?>"<?= $txnInventoryAttr ?>>Inventory</a> &gt; Transactions</p>
  <?php endif; ?>
  <div class="txn-head">
    <?php if ($txnShowPageHead): ?>
      <div>
        <h1>Transactions</h1>
        <p class="txn-sub">Track all inventory transactions and stock movements.</p>
      </div>
    <?php else: ?>
      <div></div>
    <?php endif; ?>
    <div class="txn-actions">
      <a class="txn-ico-btn" href="<?= h($txnNotiHref) ?>" title="Notifications"><i class="fa fa-bell-o"></i><?php if ($txnNotiCount > 0): ?><span class="txn-badge"><?= (int)min(99, $txnNotiCount) ?></span><?php endif; ?></a>
      <a class="txn-ico-btn" href="<?= h($txnMsgHref) ?>"<?= $txnMsgAttr ?> title="Messages"><i class="fa fa-commenting-o"></i><?php if ($txnMsgCount > 0): ?><span class="txn-badge"><?= (int)min(99, $txnMsgCount) ?></span><?php endif; ?></a>
      <select class="txn-range" id="txnRangeSel" aria-label="Date range">
        <option value="7"<?= $txnRange === 7 ? ' selected' : '' ?>><?= h($rangeFrom . ' – ' . $rangeTo) ?></option>
        <option value="14"<?= $txnRange === 14 ? ' selected' : '' ?>>Last 14 days</option>
        <option value="30"<?= $txnRange === 30 ? ' selected' : '' ?>>Last 30 days</option>
      </select>
      <button type="button" class="txn-btn" id="txnExportBtn"><i class="fa fa-download"></i> Export</button>
    </div>
  </div>

  <?php if ($err !== ''): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endif; ?>
  <?php if ($ok !== ''): ?><div class="alert alert-success"><?= h($ok) ?></div><?php endif; ?>

  <div class="txn-kpis">
    <div class="txn-kpi"><div class="txn-kpi-top"><div class="txn-ico blue"><i class="fa fa-exchange"></i></div><div class="txn-delta <?= $totUp ? 'up' : 'down' ?>"><?= $totUp ? '↑' : '↓' ?> <?= number_format(abs($totPct), 1) ?>% <span>vs last <?= (int)$txnRange ?> days</span></div></div><div class="txn-lab">Total Transactions</div><div class="txn-val"><?= (int)$kpi['total'] ?></div></div>
    <div class="txn-kpi"><div class="txn-kpi-top"><div class="txn-ico green"><i class="fa fa-arrow-down"></i></div><div class="txn-delta <?= $rcvUp ? 'up' : 'down' ?>"><?= $rcvUp ? '↑' : '↓' ?> <?= number_format(abs($rcvPct), 1) ?>% <span>vs last <?= (int)$txnRange ?> days</span></div></div><div class="txn-lab">Stock Received</div><div class="txn-val"><?= (int)$kpi['received'] ?></div></div>
    <div class="txn-kpi"><div class="txn-kpi-top"><div class="txn-ico orange"><i class="fa fa-arrow-up"></i></div><div class="txn-delta <?= $resUp ? 'up' : 'down' ?>"><?= $resUp ? '↑' : '↓' ?> <?= number_format(abs($resPct), 1) ?>% <span>vs last <?= (int)$txnRange ?> days</span></div></div><div class="txn-lab">Stock Reserved</div><div class="txn-val"><?= (int)$kpi['reserved'] ?></div></div>
    <div class="txn-kpi"><div class="txn-kpi-top"><div class="txn-ico blue"><i class="fa fa-pencil"></i></div><div class="txn-delta <?= $adjUp ? 'up' : 'down' ?>"><?= $adjUp ? '↑' : '↓' ?> <?= number_format(abs($adjPct), 1) ?>% <span>vs last <?= (int)$txnRange ?> days</span></div></div><div class="txn-lab">Stock Adjustments</div><div class="txn-val"><?= (int)$kpi['adjustment'] ?></div></div>
    <div class="txn-kpi"><div class="txn-kpi-top"><div class="txn-ico red"><i class="fa fa-undo"></i></div><div class="txn-delta <?= $rtnUp ? 'up' : 'down' ?>"><?= $rtnUp ? '↑' : '↓' ?> <?= number_format(abs($rtnPct), 1) ?>% <span>vs last <?= (int)$txnRange ?> days</span></div></div><div class="txn-lab">Stock Returned</div><div class="txn-val"><?= (int)$kpi['returned'] ?></div></div>
  </div>

  <div class="txn-filters">
    <label class="txn-search">
      <i class="icon ion-ios-search"></i>
      <input type="search" id="txnSearch" placeholder="Search by product, SKU, reference..." autocomplete="off">
    </label>
    <select id="txnTypeFilter">
      <option value="">All Transaction Types</option>
      <option value="received">Stock Received</option>
      <option value="reserved">Stock Reserved</option>
      <option value="adjustment">Stock Adjustment</option>
      <option value="returned">Stock Returned</option>
    </select>
    <select id="txnLocFilter">
      <option value="">All Locations</option>
      <option value="Direct Store">Direct Store</option>
    </select>
    <select id="txnProductFilter">
      <option value="">All Products</option>
      <?php foreach (array_keys($productNames) as $pname): ?>
        <option value="<?= h($pname) ?>"><?= h($pname) ?></option>
      <?php endforeach; ?>
    </select>
    <button type="button" id="txnFilterBtn" title="Filter"><i class="fa fa-filter"></i> Filter</button>
    <button type="button" id="txnResetBtn" title="Reset"><i class="fa fa-refresh"></i> Reset</button>
  </div>

  <div class="txn-card">
    <div class="txn-table-wrap">
      <table class="txn-table">
        <thead>
          <tr>
            <th></th>
            <th>Date &amp; Time</th>
            <th>Transaction Type</th>
            <th>Reference</th>
            <th>Product</th>
            <th>SKU</th>
            <th>Location</th>
            <th>Quantity</th>
            <th>Stock After</th>
            <th>User</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$events): ?>
            <tr><td colspan="11" class="txn-empty">No inventory transactions yet.</td></tr>
          <?php else: foreach ($events as $i => $ev):
              $meta = $typeMeta[(string)$ev['type']] ?? $typeMeta['reserved'];
              $pid = (int)$ev['product_id'];
              $oid = (int)$ev['order_id'];
              $prodHref = $pid > 0 ? ($txnProductBase . $pid . $txnProductSuffix) : '';
              $orderHref = $oid > 0 ? ('order_details.php?id=' . $oid) : '';
              $refHref = 'transactions_detail.php?ref=' . rawurlencode((string)$ev['ref']);
              $qty = (int)$ev['qty'];
              $search = mb_strtolower(trim(implode(' ', array_filter([
                  (string)$ev['title'],
                  (string)$ev['sku'],
                  (string)$ev['ref'],
                  (string)$ev['user'],
                  (string)$ev['label'],
              ]))));
          ?>
            <tr class="txn-row"
              data-search="<?= h($search) ?>"
              data-type="<?= h((string)$ev['type']) ?>"
              data-loc="<?= h((string)$ev['location']) ?>"
              data-product="<?= h((string)$ev['title']) ?>"
              data-ref="<?= h((string)$ev['ref']) ?>"
              data-qty="<?= (int)$qty ?>"
              data-when="<?= h((string)$ev['when']) ?>"
            >
              <td><button type="button" class="txn-exp" aria-label="Expand row"><i class="fa fa-chevron-right"></i></button></td>
              <td><?= h((string)$ev['when']) ?></td>
              <td><span class="txn-pill <?= h((string)$meta['cls']) ?>"><i class="fa <?= h((string)$meta['ico']) ?>"></i> <?= h((string)$ev['label']) ?></span></td>
              <td><?php if ($refHref !== ''): ?><a class="txn-ref" href="<?= h($refHref) ?>"><?= h((string)$ev['ref']) ?></a><?php else: ?><?= h((string)$ev['ref']) ?><?php endif; ?></td>
              <td>
                <div class="txn-prod">
                  <div class="txn-thumb"><?php if ((string)$ev['cover'] !== ''): ?><img src="<?= h((string)$ev['cover']) ?>" alt="" onerror="this.remove()"><i class="fa fa-cube"></i><?php else: ?><i class="fa fa-cube"></i><?php endif; ?></div>
                  <div>
                    <?php if ($prodHref !== ''): ?><a class="txn-name" href="<?= h($prodHref) ?>"><?= h((string)$ev['title']) ?></a><?php else: ?><span class="txn-name"><?= h((string)$ev['title']) ?></span><?php endif; ?>
                    <?php if ((string)$ev['variant'] !== ''): ?><span class="txn-name-sub"><?= h((string)$ev['variant']) ?></span><?php endif; ?>
                  </div>
                </div>
              </td>
              <td><strong><?= h((string)$ev['sku']) ?></strong></td>
              <td><?= h((string)$ev['location']) ?></td>
              <td class="txn-qty <?= $qty >= 0 ? 'plus' : 'minus' ?>"><?= $qty > 0 ? '+' : '' ?><?= (int)$qty ?></td>
              <td><strong><?= (int)$ev['after'] ?></strong></td>
              <td><?= h((string)$ev['user']) ?></td>
              <td>
                <div class="txn-more">
                  <button type="button" class="txn-more-btn" aria-label="Transaction actions">⋯</button>
                  <div class="txn-more-menu" role="menu">
                    <a href="<?= h($refHref) ?>" role="menuitem"><i class="fa fa-eye"></i> View Transaction</a>
                    <?php if ($prodHref !== ''): ?><a href="<?= h($prodHref) ?>" role="menuitem"><i class="fa fa-cube"></i> View Product</a><?php endif; ?>
                    <?php if ($orderHref !== ''): ?><a href="<?= h($orderHref) ?>" role="menuitem"><i class="fa fa-file-text-o"></i> View Reference</a><?php else: ?><button type="button" class="txn-copy-ref" data-ref="<?= h((string)$ev['ref']) ?>" role="menuitem"><i class="fa fa-file-text-o"></i> View Reference</button><?php endif; ?>
                    <?php if ($prodHref !== ''): ?><a href="<?= h($prodHref) ?>" role="menuitem"><i class="fa fa-pencil"></i> Edit Details</a><?php endif; ?>
                    <a href="<?= h($refHref) ?>#documents" role="menuitem"><i class="fa fa-paperclip"></i> View Documents</a>
                    <div class="txn-menu-sep" role="separator"></div>
                    <button type="button" class="txn-row-export" role="menuitem"><i class="fa fa-download"></i> Download / Export</button>
                    <button type="button" class="txn-action-unavailable" data-action="Duplicate Transaction" role="menuitem"><i class="fa fa-clone"></i> Duplicate Transaction</button>
                    <button type="button" class="txn-action-unavailable" data-action="Reverse Transaction" role="menuitem"><i class="fa fa-undo"></i> Reverse Transaction</button>
                    <div class="txn-menu-sep" role="separator"></div>
                    <button type="button" class="txn-danger txn-action-unavailable" data-action="Delete Transaction" role="menuitem"><i class="fa fa-trash-o"></i> Delete Transaction</button>
                  </div>
                </div>
              </td>
            </tr>
            <tr class="txn-detail" hidden>
              <td></td>
              <td colspan="10"><?= h((string)$ev['note']) ?><?php if ($oid > 0): ?> · Order #<?= (int)$oid ?><?php endif; ?></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
    <div class="txn-foot" id="txnFoot" <?= !$events ? 'hidden' : '' ?>>
      <div id="txnFootLabel">Showing 0 of 0 transactions</div>
      <div class="txn-pages" id="txnPages"></div>
      <label>
        <select id="txnPageSize">
          <option value="10" selected>10 / page</option>
          <option value="25">25 / page</option>
          <option value="50">50 / page</option>
        </select>
      </label>
    </div>
  </div>
</div>
<script>
(function () {
  var root = document.getElementById('txnDashRoot');
  if (!root) return;
  var rows = Array.prototype.slice.call(root.querySelectorAll('.txn-row'));
  var search = document.getElementById('txnSearch');
  var typeEl = document.getElementById('txnTypeFilter');
  var locEl = document.getElementById('txnLocFilter');
  var prodEl = document.getElementById('txnProductFilter');
  var pageSizeEl = document.getElementById('txnPageSize');
  var foot = document.getElementById('txnFoot');
  var footLabel = document.getElementById('txnFootLabel');
  var pagesEl = document.getElementById('txnPages');
  var page = 1;

  function detailRow(row) {
    return row ? row.nextElementSibling : null;
  }
  function visibleRows() {
    var q = String(search && search.value || '').trim().toLowerCase();
    var t = String(typeEl && typeEl.value || '');
    var loc = String(locEl && locEl.value || '');
    var p = String(prodEl && prodEl.value || '');
    return rows.filter(function (row) {
      if (q && String(row.getAttribute('data-search') || '').indexOf(q) === -1) return false;
      if (t && row.getAttribute('data-type') !== t) return false;
      if (loc && row.getAttribute('data-loc') !== loc) return false;
      if (p && row.getAttribute('data-product') !== p) return false;
      return true;
    });
  }
  function render() {
    var vis = visibleRows();
    var size = Math.max(1, parseInt(pageSizeEl && pageSizeEl.value, 10) || 10);
    var total = vis.length;
    var pages = Math.max(1, Math.ceil(total / size) || 1);
    if (page > pages) page = pages;
    var start = (page - 1) * size;
    var end = Math.min(total, start + size);
    rows.forEach(function (row) {
      row.hidden = true;
      var d = detailRow(row);
      if (d && d.classList.contains('txn-detail')) d.hidden = true;
      row.classList.remove('is-open');
    });
    vis.forEach(function (row, i) {
      row.hidden = !(i >= start && i < end);
    });
    if (foot) foot.hidden = total === 0;
    if (footLabel) {
      footLabel.textContent = total === 0 ? 'No matching transactions' : ('Showing ' + (total ? (start + 1) : 0) + ' to ' + end + ' of ' + total + ' transactions');
    }
    if (pagesEl) {
      pagesEl.innerHTML = '';
      function addBtn(label, to, on) {
        var b = document.createElement('button');
        b.type = 'button';
        b.textContent = label;
        if (on) b.className = 'is-on';
        b.addEventListener('click', function () { page = to; render(); });
        pagesEl.appendChild(b);
      }
      addBtn('‹', Math.max(1, page - 1), false);
      var maxBtn = Math.min(pages, 8);
      for (var i = 1; i <= maxBtn; i++) addBtn(String(i), i, i === page);
      if (pages > 8) {
        var last = document.createElement('span');
        last.textContent = '… ' + pages;
        pagesEl.appendChild(last);
      }
      addBtn('›', Math.min(pages, page + 1), false);
    }
  }
  [search, typeEl, locEl, prodEl, pageSizeEl].forEach(function (el) {
    if (!el) return;
    el.addEventListener(el.tagName === 'INPUT' ? 'input' : 'change', function () { page = 1; render(); });
  });
  var filterBtn = document.getElementById('txnFilterBtn');
  if (filterBtn) filterBtn.addEventListener('click', function () { page = 1; render(); });
  var resetBtn = document.getElementById('txnResetBtn');
  if (resetBtn) {
    resetBtn.addEventListener('click', function () {
      if (search) search.value = '';
      if (typeEl) typeEl.value = '';
      if (locEl) locEl.value = '';
      if (prodEl) prodEl.value = '';
      page = 1;
      render();
    });
  }
  root.addEventListener('click', function (e) {
    var exp = e.target.closest('.txn-exp');
    if (exp) {
      var row = exp.closest('.txn-row');
      var d = detailRow(row);
      var open = row.classList.toggle('is-open');
      if (d && d.classList.contains('txn-detail')) d.hidden = !open;
      e.preventDefault();
      return;
    }
    var copyBtn = e.target.closest('.txn-copy-ref');
    if (copyBtn) {
      var ref = copyBtn.getAttribute('data-ref') || '';
      if (ref && navigator.clipboard) navigator.clipboard.writeText(ref).catch(function () {});
      e.preventDefault();
      return;
    }
    var rowExport = e.target.closest('.txn-row-export');
    if (rowExport) {
      var exportRow = rowExport.closest('.txn-row');
      var exportCells = exportRow ? exportRow.querySelectorAll('td') : [];
      function csvCell(i) { return '"' + String(exportCells[i] ? exportCells[i].innerText.replace(/"/g, '""') : '').trim() + '"'; }
      var csv = ['Date,Type,Reference,Product,SKU,Location,Quantity,Stock After,User', [csvCell(1),csvCell(2),csvCell(3),csvCell(4),csvCell(5),csvCell(6),csvCell(7),csvCell(8),csvCell(9)].join(',')].join('\n');
      var rowBlob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
      var rowLink = document.createElement('a');
      rowLink.href = URL.createObjectURL(rowBlob);
      rowLink.download = String(exportRow && exportRow.getAttribute('data-ref') || 'transaction') + '.csv';
      rowLink.click();
      setTimeout(function () { URL.revokeObjectURL(rowLink.href); }, 500);
      e.preventDefault();
      return;
    }
    var unavailable = e.target.closest('.txn-action-unavailable');
    if (unavailable) {
      window.alert((unavailable.getAttribute('data-action') || 'This action') + ' is not available for generated inventory transactions.');
      e.preventDefault();
    }
  });
  var exportBtn = document.getElementById('txnExportBtn');
  if (exportBtn) {
    exportBtn.addEventListener('click', function () {
      var lines = [['Date','Type','Reference','Product','SKU','Location','Quantity','Stock After','User'].join(',')];
      visibleRows().forEach(function (row) {
        var cells = row.querySelectorAll('td');
        function txt(i) { return '"' + String(cells[i] ? cells[i].innerText.replace(/"/g, '""') : '').trim() + '"'; }
        lines.push([txt(1), txt(2), txt(3), txt(4), txt(5), txt(6), txt(7), txt(8), txt(9)].join(','));
      });
      var blob = new Blob([lines.join('\n')], { type: 'text/csv;charset=utf-8;' });
      var a = document.createElement('a');
      a.href = URL.createObjectURL(blob);
      a.download = 'inventory-transactions.csv';
      a.click();
      setTimeout(function () { URL.revokeObjectURL(a.href); }, 500);
    });
  }
  var rangeSel = document.getElementById('txnRangeSel');
  if (rangeSel) {
    rangeSel.addEventListener('change', function () {
      var v = encodeURIComponent(rangeSel.value || '7');
      window.location.href = <?= $txnInSalesHub ? "'sales_management.php?txn_range=' + v + '#transactions'" : "'transactions.php?txn_range=' + v" ?>;
    });
  }
  function closeMenus() {
    root.querySelectorAll('.txn-more.is-open').forEach(function (el) {
      el.classList.remove('is-open');
      var m = el.querySelector('.txn-more-menu');
      if (m) {
        m.style.position = '';
        m.style.top = '';
        m.style.left = '';
        m.style.right = '';
      }
    });
  }
  root.addEventListener('click', function (e) {
    var btn = e.target.closest('.txn-more-btn');
    if (btn) {
      var wrap = btn.closest('.txn-more');
      var wasOpen = wrap.classList.contains('is-open');
      closeMenus();
      if (!wasOpen) {
        wrap.classList.add('is-open');
        var menu = wrap.querySelector('.txn-more-menu');
        if (menu) {
          var r = btn.getBoundingClientRect();
          menu.style.position = 'fixed';
          menu.style.right = 'auto';
          var left = r.right - menu.offsetWidth;
          var top = r.bottom + 4;
          if (left < 8) left = 8;
          if (top + menu.offsetHeight > window.innerHeight - 8) {
            top = Math.max(8, r.top - menu.offsetHeight - 4);
          }
          menu.style.left = left + 'px';
          menu.style.top = top + 'px';
        }
      }
      e.preventDefault();
      e.stopPropagation();
      return;
    }
    if (!e.target.closest('.txn-more')) closeMenus();
  });
  document.addEventListener('click', function (e) {
    if (!e.target.closest('#txnDashRoot')) closeMenus();
  });
  window.addEventListener('scroll', closeMenus, true);
  render();
})();
</script>
