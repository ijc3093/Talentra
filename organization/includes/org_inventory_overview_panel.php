<?php
declare(strict_types=1);

/**
 * Inventory overview dashboard for overview.php and sales_management.php#overview.
 *
 * Expected: PDO $dbh, int $orgId
 * Optional: $ovInSalesHub, $ovInventoryHref, $ovInventoryAttr, $ovLowHref,
 *           $ovNotiCount, $ovMsgCount, $ovShowPageHead, $err, $ok
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

if (!function_exists('inv_money')) {
    function inv_money(int $cents, string $currency = 'USD'): string
    {
        if (function_exists('org_shop_format_price')) {
            return org_shop_format_price($cents, $currency);
        }
        return '$' . number_format(max(0, $cents) / 100, 2);
    }
}

if (!function_exists('ov_cat_icon')) {
    function ov_cat_icon(string $name): string
    {
        $n = strtolower($name);
        if (str_contains($n, 'phone') || str_contains($n, 'mobile')) {
            return 'fa-mobile';
        }
        if (str_contains($n, 'shoe')) {
            return 'fa-star';
        }
        if (str_contains($n, 'apparel') || str_contains($n, 'cloth') || str_contains($n, 'shirt')) {
            return 'fa-tag';
        }
        if (str_contains($n, 'wear') || str_contains($n, 'watch')) {
            return 'fa-clock-o';
        }
        if (str_contains($n, 'furn') || str_contains($n, 'home')) {
            return 'fa-home';
        }
        return 'fa-cube';
    }
}

$ovInSalesHub = !empty($ovInSalesHub);
$ovShowPageHead = array_key_exists('ovShowPageHead', get_defined_vars())
    ? !empty($ovShowPageHead)
    : !$ovInSalesHub;
$ovInventoryHref = (string)($ovInventoryHref ?? ($ovInSalesHub ? '#inventory' : 'product_table.php'));
$ovInventoryAttr = (string)($ovInventoryAttr ?? ($ovInSalesHub ? ' data-sales-nav="inventory"' : ''));
$ovLowHref = (string)($ovLowHref ?? ($ovInSalesHub ? 'sales_management.php?inv=low#inventory' : 'product_table.php?inv=low'));
$ovNotiCount = (int)($ovNotiCount ?? 0);
$ovMsgCount = (int)($ovMsgCount ?? 0);
$ovNotiHref = (string)($ovNotiHref ?? 'sales_notifications.php');
$ovMsgHref = (string)($ovMsgHref ?? ($ovInSalesHub ? '#message' : 'sales_management.php#message'));
$ovMsgAttr = (string)($ovMsgAttr ?? ($ovInSalesHub ? ' data-sales-nav="message"' : ''));
$err = (string)($err ?? '');
$ok = (string)($ok ?? '');
$lowStockAt = 5;

$ovRange = (int)($_GET['ov_range'] ?? 7);
if (!in_array($ovRange, [7, 14, 30], true)) {
    $ovRange = 7;
}

if (function_exists('org_shop_sync_org_sold_out_stock')) {
    org_shop_sync_org_sold_out_stock($dbh, (int)$orgId);
}
$products = function_exists('org_shop_list_products')
    ? org_shop_list_products($dbh, (int)$orgId, false)
    : [];

$reservedMap = [];
try {
    $stR = $dbh->prepare("
        SELECT product_id, COALESCE(SUM(GREATEST(COALESCE(quantity, 1), 1)), 0) AS qty
        FROM org_orders
        WHERE org_id = :org
          AND product_id IS NOT NULL AND product_id > 0
          AND LOWER(TRIM(status)) IN ('pending','paid','confirmed','processing')
        GROUP BY product_id
    ");
    $stR->execute([':org' => (int)$orgId]);
    foreach ($stR->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $rid = (int)($row['product_id'] ?? 0);
        if ($rid > 0) {
            $reservedMap[$rid] = (int)($row['qty'] ?? 0);
        }
    }
} catch (Throwable $e) {
}

$movements = [];
try {
    $stM = $dbh->prepare("
        SELECT o.created_at, o.quantity, o.status, o.product_title, o.product_id,
               p.title, p.sku, p.cover_image_path
        FROM org_orders o
        LEFT JOIN org_products p ON p.id = o.product_id AND p.is_deleted = 0
        WHERE o.org_id = :org
        ORDER BY o.created_at DESC, o.id DESC
        LIMIT 8
    ");
    $stM->execute([':org' => (int)$orgId]);
    $movements = $stM->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $movements = [];
}

$received = [];
try {
    $stIn = $dbh->prepare("
        SELECT created_at, title, sku, stock_qty, cover_image_path
        FROM org_products
        WHERE org_id = :org AND is_deleted = 0
        ORDER BY created_at DESC, id DESC
        LIMIT 4
    ");
    $stIn->execute([':org' => (int)$orgId]);
    $received = $stIn->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $received = [];
}

$soldByDay = [];
try {
    $stSold = $dbh->prepare("
        SELECT DATE(created_at) AS d,
               COALESCE(SUM(GREATEST(COALESCE(quantity, 1), 1) * COALESCE(unit_price_cents, 0)), 0) AS cents
        FROM org_orders
        WHERE org_id = :org
          AND created_at >= :cut
          AND LOWER(TRIM(status)) NOT IN ('cancelled','canceled')
        GROUP BY DATE(created_at)
    ");
    $stSold->execute([
        ':org' => (int)$orgId,
        ':cut' => date('Y-m-d 00:00:00', time() - ($ovRange - 1) * 86400),
    ]);
    foreach ($stSold->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $d = (string)($row['d'] ?? '');
        if ($d !== '') {
            $soldByDay[$d] = (int)($row['cents'] ?? 0);
        }
    }
} catch (Throwable $e) {
    $soldByDay = [];
}

$now = time();
$cutCur = $now - $ovRange * 86400;
$cutPrev = $now - ($ovRange * 2) * 86400;
$kpi = ['total' => 0, 'units' => 0, 'value' => 0, 'low' => 0, 'out' => 0, 'in' => 0, 'draft' => 0];
$kpiCur = $kpi;
$kpiPrev = $kpi;
$unitIn = 0;
$unitLow = 0;
$unitOut = 0;
$unitDraft = 0;
$catUnits = [];

foreach ($products as $p) {
    $pid = (int)($p['id'] ?? 0);
    $status = strtolower(trim((string)($p['status'] ?? 'draft')));
    $tracked = $p['stock_qty'] !== null && $p['stock_qty'] !== '';
    $available = $tracked ? max(0, (int)$p['stock_qty']) : 0;
    $priceCents = max(0, (int)($p['price_cents'] ?? 0));
    $valueCents = $available * $priceCents;
    $cat = trim((string)($p['category'] ?? '')) ?: 'Uncategorized';
    $createdTs = strtotime((string)($p['created_at'] ?? '')) ?: 0;

    $stockCls = 'in';
    if ($status === 'sold_out' || ($tracked && $available <= 0)) {
        $stockCls = 'out';
    } elseif ($tracked && $available <= $lowStockAt) {
        $stockCls = 'low';
    }
    $isDraft = ($status === 'draft' || $status === 'archived');

    $kpi['total']++;
    $kpi['units'] += $available;
    $kpi['value'] += $valueCents;
    if ($isDraft) {
        $kpi['draft']++;
        $unitDraft += $available;
    } elseif ($stockCls === 'out') {
        $kpi['out']++;
        $unitOut += max(1, (int)($reservedMap[$pid] ?? 0));
    } elseif ($stockCls === 'low') {
        $kpi['low']++;
        $unitLow += $available;
    } else {
        $kpi['in']++;
        $unitIn += $available;
    }

    if (!isset($catUnits[$cat])) {
        $catUnits[$cat] = 0;
    }
    $catUnits[$cat] += $available;

    $ageKpi = $isDraft ? 'draft' : $stockCls;
    if ($createdTs >= $cutCur) {
        $kpiCur['total']++;
        $kpiCur['units'] += $available;
        $kpiCur['value'] += $valueCents;
        if (isset($kpiCur[$ageKpi])) {
            $kpiCur[$ageKpi]++;
        }
    } elseif ($createdTs >= $cutPrev) {
        $kpiPrev['total']++;
        $kpiPrev['units'] += $available;
        $kpiPrev['value'] += $valueCents;
        if (isset($kpiPrev[$ageKpi])) {
            $kpiPrev[$ageKpi]++;
        }
    }
}

arsort($catUnits, SORT_NUMERIC);
$topCats = [];
foreach ($catUnits as $catName => $units) {
    $topCats[] = ['name' => $catName, 'units' => (int)$units];
    if (count($topCats) >= 5) {
        break;
    }
}

$chartDays = [];
$walkValue = (int)$kpi['value'];
for ($i = 0; $i < $ovRange; $i++) {
    $ts = strtotime('today -' . $i . ' days');
    $key = date('Y-m-d', $ts);
    $chartDays[] = [
        'ts' => $ts,
        'label' => date('M j', $ts),
        'full' => date('M j, Y', $ts),
        'value' => max(0, $walkValue),
    ];
    $walkValue += (int)($soldByDay[$key] ?? 0);
}
$chartDays = array_reverse($chartDays);

$chartMax = 1;
foreach ($chartDays as $pt) {
    $chartMax = max($chartMax, (int)$pt['value']);
}
$chartMax = (int)ceil($chartMax * 1.15);
if ($chartMax < 100) {
    $chartMax = 100;
}

$svgW = 560;
$svgH = 210;
$padL = 48;
$padR = 16;
$padT = 18;
$padB = 36;
$plotW = $svgW - $padL - $padR;
$plotH = $svgH - $padT - $padB;
$nPts = max(1, count($chartDays) - 1);
$poly = [];
$dots = [];
foreach ($chartDays as $i => $pt) {
    $x = $padL + ($nPts > 0 ? ($i / $nPts) * $plotW : $plotW / 2);
    $y = $padT + (1 - ((int)$pt['value'] / $chartMax)) * $plotH;
    $poly[] = round($x, 1) . ',' . round($y, 1);
    $dots[] = [
        'x' => $x,
        'y' => $y,
        'label' => (string)$pt['full'],
        'money' => inv_money((int)$pt['value']),
        'value' => (int)$pt['value'],
    ];
}
$areaPts = $poly;
if ($dots) {
    $last = $dots[count($dots) - 1];
    $first = $dots[0];
    $areaPts[] = round($last['x'], 1) . ',' . ($padT + $plotH);
    $areaPts[] = round($first['x'], 1) . ',' . ($padT + $plotH);
}

$yTicks = 4;
$yLabels = [];
for ($t = 0; $t <= $yTicks; $t++) {
    $v = (int)round($chartMax * ($yTicks - $t) / $yTicks);
    $yLabels[] = [
        'y' => $padT + ($t / $yTicks) * $plotH,
        'label' => $v >= 100000 ? ('$' . number_format($v / 100000, 0) . 'K') : ('$' . number_format($v / 100, 0)),
    ];
}

$donutUnits = max(1, $unitIn + $unitLow + $unitOut + $unitDraft);
$donutIn = round($unitIn / $donutUnits * 100, 1);
$donutLow = round($unitLow / $donutUnits * 100, 1);
$donutOut = round($unitOut / $donutUnits * 100, 1);
$donutDraft = max(0.0, round(100 - $donutIn - $donutLow - $donutOut, 1));
$g1 = $donutIn;
$g2 = $g1 + $donutLow;
$g3 = $g2 + $donutOut;

[$vPct, $vUp] = inv_pct((int)$kpiCur['value'], (int)$kpiPrev['value']);
[$tPct, $tUp] = inv_pct((int)$kpiCur['total'], (int)$kpiPrev['total']);
[$uPct, $uUp] = inv_pct((int)$kpiCur['units'], (int)$kpiPrev['units']);
[$lPct, $lUp] = inv_pct((int)$kpiCur['low'], (int)$kpiPrev['low']);
[$oPct, $oUp] = inv_pct((int)$kpiCur['out'], (int)$kpiPrev['out']);

$rangeLabel = 'Last ' . $ovRange . ' Days';
$rangeFrom = date('M j', $now - ($ovRange - 1) * 86400);
$rangeTo = date('M j, Y', $now);
$feed = [];
foreach ($received as $row) {
    $when = strtotime((string)($row['created_at'] ?? '')) ?: 0;
    $qty = max(0, (int)($row['stock_qty'] ?? 0));
    $feed[] = [
        'ts' => $when,
        'plus' => true,
        'title' => 'Stock received: ' . (trim((string)($row['title'] ?? '')) ?: 'Product'),
        'qty' => $qty,
        'when' => $when ? date('M j, Y g:i A', $when) : '',
        'cover' => product_table_cover_url((string)($row['cover_image_path'] ?? '')),
    ];
}
foreach ($movements as $mv) {
    $st = strtolower(trim((string)($mv['status'] ?? '')));
    $when = strtotime((string)($mv['created_at'] ?? '')) ?: 0;
    $qty = max(1, (int)($mv['quantity'] ?? 1));
    $isReturn = in_array($st, ['cancelled', 'canceled'], true);
    $title = trim((string)($mv['title'] ?? $mv['product_title'] ?? 'Product'));
    if ($isReturn) {
        $label = 'Stock returned: ' . $title;
    } elseif (in_array($st, ['shipped', 'delivered'], true)) {
        $label = 'Stock sold: ' . $title;
    } else {
        $label = 'Stock reserved: ' . $title;
    }
    $feed[] = [
        'ts' => $when,
        'plus' => $isReturn,
        'title' => $label,
        'qty' => $qty,
        'when' => $when ? date('M j, Y g:i A', $when) : '',
        'cover' => product_table_cover_url((string)($mv['cover_image_path'] ?? '')),
    ];
}
usort($feed, static function (array $a, array $b): int {
    return ((int)$b['ts']) <=> ((int)$a['ts']);
});
$feed = array_slice($feed, 0, 6);

$locIn = $unitIn;
$locLow = $unitLow;
$locOut = (int)$kpi['out'];
$locUnits = (int)$kpi['units'];
$locValue = (int)$kpi['value'];
?>
<style>
  .ov-dash{--ov-text:#0f172a;--ov-muted:#64748b;--ov-border:#e2e8f0;--ov-card:#fff;color:var(--ov-text);}
  .ov-dash .ov-crumb{font-size:12px;font-weight:700;color:var(--ov-muted);margin:0 0 6px;}
  .ov-dash .ov-crumb a{color:#2563eb;text-decoration:none;}
  .ov-dash .ov-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;flex-wrap:wrap;margin-bottom:14px;}
  .ov-dash .ov-head h1{margin:0;font-size:28px;font-weight:800;letter-spacing:-.03em;}
  .ov-dash .ov-sub{margin:4px 0 0;font-size:13px;color:var(--ov-muted);font-weight:600;}
  .ov-dash .ov-actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap;}
  .ov-dash .ov-ico-btn{position:relative;width:36px;height:36px;border-radius:10px;border:1px solid var(--ov-border);background:var(--ch-surface,#fff);color:#334155;display:inline-flex;align-items:center;justify-content:center;text-decoration:none;}
  .ov-dash .ov-badge{position:absolute;top:-4px;right:-4px;min-width:16px;height:16px;padding:0 4px;border-radius:999px;background:#ef4444;color:#fff;font-size:9px;font-weight:800;display:flex;align-items:center;justify-content:center;}
  .ov-dash .ov-btn,.ov-dash .ov-range{height:36px;padding:0 12px;border-radius:10px;border:1px solid #cbd5e1;background:var(--ch-surface,#fff);font-size:12px;font-weight:700;display:inline-flex;align-items:center;gap:6px;color:#0f172a;text-decoration:none;cursor:pointer;}
  .ov-dash .ov-kpis{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:8px;margin-bottom:10px;}
  .ov-dash .ov-kpi{background:var(--ov-card);border:1px solid var(--ov-border);border-radius:12px;padding:12px;}
  .ov-dash .ov-kpi-top{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:8px;}
  .ov-dash .ov-ico{width:28px;height:28px;border-radius:8px;display:inline-flex;align-items:center;justify-content:center;font-size:13px;}
  .ov-dash .ov-ico.purple{background:#f3e8ff;color:#7c3aed;}
  .ov-dash .ov-ico.blue{background:#dbeafe;color:#2563eb;}
  .ov-dash .ov-ico.green{background:#dcfce7;color:#16a34a;}
  .ov-dash .ov-ico.orange{background:#ffedd5;color:#ea580c;}
  .ov-dash .ov-ico.red{background:#fee2e2;color:#dc2626;}
  .ov-dash .ov-lab{font-size:11px;font-weight:700;color:var(--ov-muted);}
  .ov-dash .ov-val{font-size:22px;font-weight:800;line-height:1.15;margin-top:2px;}
  .ov-dash .ov-delta{font-size:11px;font-weight:700;}
  .ov-dash .ov-delta.up{color:#16a34a;}
  .ov-dash .ov-delta.down{color:#dc2626;}
  .ov-dash .ov-delta span{font-weight:600;color:var(--ov-muted);}
  .ov-dash .ov-top{display:grid;grid-template-columns:1.35fr 1fr .95fr;gap:10px;margin-bottom:10px;}
  .ov-dash .ov-bot{display:grid;grid-template-columns:1.35fr 1fr;gap:10px;}
  .ov-dash .ov-card{background:var(--ov-card);border:1px solid var(--ov-border);border-radius:12px;padding:14px;min-width:0;}
  .ov-dash .ov-card-h{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:12px;}
  .ov-dash .ov-card h3{margin:0;font-size:14px;font-weight:800;}
  .ov-dash .ov-link{font-size:12px;font-weight:800;color:#2563eb;text-decoration:none;}
  .ov-dash .ov-chart-wrap{position:relative;width:100%;overflow:hidden;}
  .ov-dash .ov-chart-wrap svg{width:100%;height:auto;display:block;}
  .ov-dash .ov-tip{display:none;position:absolute;z-index:4;background:#0f172a;color:#fff;font-size:11px;font-weight:700;padding:6px 8px;border-radius:8px;pointer-events:none;white-space:nowrap;}
  .ov-dash .ov-donut-row{display:flex;align-items:center;gap:16px;}
  .ov-dash .ov-donut{width:132px;height:132px;border-radius:50%;position:relative;flex:0 0 auto;}
  .ov-dash .ov-donut:after{content:'';position:absolute;inset:28px;background:var(--ov-card);border-radius:50%;}
  .ov-dash .ov-donut-center{position:absolute;inset:0;z-index:1;display:flex;flex-direction:column;align-items:center;justify-content:center;pointer-events:none;}
  .ov-dash .ov-donut-center strong{font-size:18px;font-weight:800;line-height:1;}
  .ov-dash .ov-donut-center span{font-size:10px;font-weight:700;color:var(--ov-muted);margin-top:4px;}
  .ov-dash .ov-legend{list-style:none;margin:0;padding:0;font-size:12px;flex:1;}
  .ov-dash .ov-legend li{display:flex;align-items:center;justify-content:space-between;gap:8px;padding:4px 0;}
  .ov-dash .ov-dot{width:8px;height:8px;border-radius:50%;display:inline-block;margin-right:8px;}
  .ov-dash .ov-cat{display:flex;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid var(--ov-border);}
  .ov-dash .ov-cat:last-of-type{border-bottom:0;}
  .ov-dash .ov-cat-ico{width:32px;height:32px;border-radius:9px;background:#eff6ff;color:#2563eb;display:flex;align-items:center;justify-content:center;flex:0 0 auto;}
  .ov-dash .ov-cat-name{font-weight:800;font-size:13px;}
  .ov-dash .ov-cat-units{margin-left:auto;font-weight:800;font-size:13px;white-space:nowrap;}
  .ov-dash .ov-cat-total{margin-top:10px;padding-top:10px;border-top:1px solid var(--ov-border);display:flex;justify-content:space-between;font-size:12px;font-weight:800;}
  .ov-dash .ov-table-wrap{overflow:auto;}
  .ov-dash .ov-table{width:100%;border-collapse:collapse;min-width:520px;}
  .ov-dash .ov-table th{font-size:11px;font-weight:800;letter-spacing:.04em;text-transform:uppercase;color:var(--ov-muted);text-align:left;padding:8px 10px;border-bottom:1px solid var(--ov-border);background:var(--ch-surface,#f8fafc);white-space:nowrap;}
  .ov-dash .ov-table td{padding:10px;border-bottom:1px solid var(--ov-border);font-size:13px;}
  .ov-dash .ov-table tfoot td{font-weight:800;background:var(--ch-surface,#f8fafc);border-bottom:0;}
  .ov-dash .ov-move{display:flex;gap:10px;padding:8px 0;border-bottom:1px solid var(--ov-border);}
  .ov-dash .ov-move:last-child{border-bottom:0;}
  .ov-dash .ov-move-ico{width:28px;height:28px;border-radius:8px;display:flex;align-items:center;justify-content:center;flex:0 0 auto;font-size:12px;}
  .ov-dash .ov-move-ico.plus{background:#dcfce7;color:#16a34a;}
  .ov-dash .ov-move-ico.minus{background:#fee2e2;color:#dc2626;}
  .ov-dash .ov-move-title{display:block;font-weight:800;font-size:13px;}
  .ov-dash .ov-move-sub{display:block;font-size:11px;color:var(--ov-muted);font-weight:600;margin-top:2px;}
  .ov-dash .ov-qty{margin-left:auto;font-weight:800;font-size:12px;white-space:nowrap;}
  .ov-dash .ov-qty.plus{color:#16a34a;}
  .ov-dash .ov-qty.minus{color:#dc2626;}
  .ov-dash .ov-alert{margin-top:12px;background:#fff7ed;border:1px solid #fdba74;border-radius:12px;padding:12px 14px;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;}
  .ov-dash .ov-alert-copy{display:flex;align-items:center;gap:10px;font-size:13px;font-weight:700;color:#9a3412;}
  .ov-dash .ov-alert-copy i{color:#ea580c;}
  .ov-dash .ov-alert a{height:34px;padding:0 12px;border-radius:8px;background:var(--ch-surface,#fff);border:1px solid #fdba74;color:#9a3412;font-size:12px;font-weight:800;text-decoration:none;display:inline-flex;align-items:center;}
  .ov-dash .ov-empty{margin:0;font-size:13px;color:var(--ov-muted);font-weight:600;}
  html.dark-auto .ov-dash{--ov-text:var(--msb-palette-text,#e2e8f0);--ov-muted:#94a3b8;--ov-border:rgba(148,163,184,.22);--ov-card:var(--msb-palette-bg,#171d24);}
  html.dark-auto .ov-dash .ov-donut:after,html.dark-auto .ov-dash .ov-ico-btn,html.dark-auto .ov-dash .ov-btn,html.dark-auto .ov-dash .ov-range{background:var(--ov-card);color:var(--ov-text);border-color:var(--ov-border);}
  html.dark-auto .ov-dash .ov-table th,html.dark-auto .ov-dash .ov-table tfoot td{background:rgba(148,163,184,.08);}
  @media (max-width:1100px){.ov-dash .ov-kpis,.ov-dash .ov-top,.ov-dash .ov-bot{grid-template-columns:1fr 1fr;}}
  @media (max-width:700px){.ov-dash .ov-kpis,.ov-dash .ov-top,.ov-dash .ov-bot{grid-template-columns:1fr;}.ov-dash .ov-head h1{font-size:22px;}}
</style>
<div class="ov-dash" id="ovDashRoot">
  <?php if ($ovShowPageHead): ?>
    <p class="ov-crumb"><a href="<?= h($ovInventoryHref) ?>"<?= $ovInventoryAttr ?>>Inventory</a> &gt; Overview</p>
  <?php endif; ?>
  <div class="ov-head">
    <?php if ($ovShowPageHead): ?>
      <div>
        <h1>Overview</h1>
        <p class="ov-sub">Real-time overview of your inventory performance and stock status.</p>
      </div>
    <?php else: ?>
      <div></div>
    <?php endif; ?>
    <div class="ov-actions">
      <a class="ov-ico-btn" href="<?= h($ovNotiHref) ?>" title="Notifications"><i class="fa fa-bell-o"></i><?php if ($ovNotiCount > 0): ?><span class="ov-badge"><?= (int)min(99, $ovNotiCount) ?></span><?php endif; ?></a>
      <a class="ov-ico-btn" href="<?= h($ovMsgHref) ?>"<?= $ovMsgAttr ?> title="Messages"><i class="fa fa-commenting-o"></i><?php if ($ovMsgCount > 0): ?><span class="ov-badge"><?= (int)min(99, $ovMsgCount) ?></span><?php endif; ?></a>
      <select class="ov-range" id="ovRangeSel" aria-label="Date range">
        <option value="7"<?= $ovRange === 7 ? ' selected' : '' ?>><?= h($rangeFrom . ' – ' . $rangeTo) ?></option>
        <option value="14"<?= $ovRange === 14 ? ' selected' : '' ?>>Last 14 days</option>
        <option value="30"<?= $ovRange === 30 ? ' selected' : '' ?>>Last 30 days</option>
      </select>
      <button type="button" class="ov-btn" id="ovExportBtn"><i class="fa fa-download"></i> Export</button>
    </div>
  </div>

  <?php if ($err !== ''): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endif; ?>
  <?php if ($ok !== ''): ?><div class="alert alert-success"><?= h($ok) ?></div><?php endif; ?>

  <div class="ov-kpis">
    <div class="ov-kpi"><div class="ov-kpi-top"><div class="ov-ico purple"><i class="fa fa-cubes"></i></div><div class="ov-delta <?= $vUp ? 'up' : 'down' ?>"><?= $vUp ? '+' : '' ?><?= number_format($vPct, 1) ?>% <span>vs last <?= (int)$ovRange ?> days</span></div></div><div class="ov-lab">Total Inventory Value</div><div class="ov-val"><?= h(inv_money((int)$kpi['value'])) ?></div></div>
    <div class="ov-kpi"><div class="ov-kpi-top"><div class="ov-ico blue"><i class="fa fa-cube"></i></div><div class="ov-delta <?= $tUp ? 'up' : 'down' ?>"><?= $tUp ? '+' : '' ?><?= number_format($tPct, 1) ?>% <span>vs last <?= (int)$ovRange ?> days</span></div></div><div class="ov-lab">Total Products</div><div class="ov-val"><?= (int)$kpi['total'] ?></div></div>
    <div class="ov-kpi"><div class="ov-kpi-top"><div class="ov-ico green"><i class="fa fa-th"></i></div><div class="ov-delta <?= $uUp ? 'up' : 'down' ?>"><?= $uUp ? '+' : '' ?><?= number_format($uPct, 1) ?>% <span>vs last <?= (int)$ovRange ?> days</span></div></div><div class="ov-lab">Total Units in Stock</div><div class="ov-val"><?= (int)$kpi['units'] ?></div></div>
    <div class="ov-kpi"><div class="ov-kpi-top"><div class="ov-ico orange"><i class="fa fa-exclamation-triangle"></i></div><div class="ov-delta <?= $lUp ? 'up' : 'down' ?>"><?= $lUp ? '+' : '' ?><?= number_format($lPct, 1) ?>% <span>vs last <?= (int)$ovRange ?> days</span></div></div><div class="ov-lab">Low Stock Items</div><div class="ov-val"><?= (int)$kpi['low'] ?></div></div>
    <div class="ov-kpi"><div class="ov-kpi-top"><div class="ov-ico red"><i class="fa fa-times-circle"></i></div><div class="ov-delta <?= $oUp ? 'up' : 'down' ?>"><?= $oUp ? '+' : '' ?><?= number_format($oPct, 1) ?>% <span>vs last <?= (int)$ovRange ?> days</span></div></div><div class="ov-lab">Out of Stock Items</div><div class="ov-val"><?= (int)$kpi['out'] ?></div></div>
  </div>

  <div class="ov-top">
    <div class="ov-card">
      <div class="ov-card-h">
        <h3>Inventory Value Over Time</h3>
        <span class="ov-lab"><?= h($rangeLabel) ?></span>
      </div>
      <div class="ov-chart-wrap" id="ovChartWrap">
        <svg viewBox="0 0 <?= (int)$svgW ?> <?= (int)$svgH ?>" role="img" aria-label="Inventory value over time">
          <?php foreach ($yLabels as $tick): ?>
            <line x1="<?= (int)$padL ?>" y1="<?= h((string)round($tick['y'], 1)) ?>" x2="<?= (int)($svgW - $padR) ?>" y2="<?= h((string)round($tick['y'], 1)) ?>" stroke="#e2e8f0" stroke-width="1"/>
            <text x="<?= (int)($padL - 8) ?>" y="<?= h((string)round($tick['y'] + 4, 1)) ?>" text-anchor="end" font-size="10" fill="#64748b"><?= h((string)$tick['label']) ?></text>
          <?php endforeach; ?>
          <?php if ($areaPts): ?>
            <polygon points="<?= h(implode(' ', $areaPts)) ?>" fill="rgba(37,99,235,.12)" stroke="none"/>
            <polyline points="<?= h(implode(' ', $poly)) ?>" fill="none" stroke="#2563eb" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round"/>
          <?php endif; ?>
          <?php foreach ($dots as $dot): ?>
            <circle class="ov-dot-pt" cx="<?= h((string)round($dot['x'], 1)) ?>" cy="<?= h((string)round($dot['y'], 1)) ?>" r="4" fill="#2563eb" data-label="<?= h((string)$dot['label'] . ': ' . $dot['money']) ?>"/>
          <?php endforeach; ?>
          <?php foreach ($chartDays as $i => $pt):
              $x = $padL + ($nPts > 0 ? ($i / $nPts) * $plotW : $plotW / 2);
              $show = ($ovRange <= 7) || $i === 0 || $i === count($chartDays) - 1 || $i % 2 === 0;
              if (!$show) {
                  continue;
              }
          ?>
            <text x="<?= h((string)round($x, 1)) ?>" y="<?= (int)($svgH - 10) ?>" text-anchor="middle" font-size="10" fill="#64748b"><?= h((string)$pt['label']) ?></text>
          <?php endforeach; ?>
        </svg>
        <div class="ov-tip" id="ovChartTip"></div>
      </div>
    </div>

    <div class="ov-card">
      <div class="ov-card-h"><h3>Stock Status Summary</h3></div>
      <div class="ov-donut-row">
        <div class="ov-donut" style="background:conic-gradient(#16a34a 0% <?= h((string)$g1) ?>%, #f59e0b <?= h((string)$g1) ?>% <?= h((string)$g2) ?>%, #ef4444 <?= h((string)$g2) ?>% <?= h((string)$g3) ?>%, #3b82f6 <?= h((string)$g3) ?>% 100%);">
          <div class="ov-donut-center"><strong><?= (int)$kpi['units'] ?></strong><span>Total Units</span></div>
        </div>
        <ul class="ov-legend">
          <li><span><span class="ov-dot" style="background:#16a34a"></span>In Stock</span><strong><?= (int)$unitIn ?> (<?= number_format($donutIn, 1) ?>%)</strong></li>
          <li><span><span class="ov-dot" style="background:#f59e0b"></span>Low Stock</span><strong><?= (int)$unitLow ?> (<?= number_format($donutLow, 1) ?>%)</strong></li>
          <li><span><span class="ov-dot" style="background:#ef4444"></span>Out of Stock</span><strong><?= (int)$unitOut ?> (<?= number_format($donutOut, 1) ?>%)</strong></li>
          <li><span><span class="ov-dot" style="background:#3b82f6"></span>Draft / Inactive</span><strong><?= (int)$unitDraft ?> (<?= number_format($donutDraft, 1) ?>%)</strong></li>
        </ul>
      </div>
    </div>

    <div class="ov-card">
      <div class="ov-card-h">
        <h3>Top Categories by Units in Stock</h3>
        <a class="ov-link" href="<?= h($ovInventoryHref) ?>"<?= $ovInventoryAttr ?>>View All</a>
      </div>
      <?php if (!$topCats): ?>
        <p class="ov-empty">No categories yet.</p>
      <?php else: foreach ($topCats as $cat): ?>
        <div class="ov-cat">
          <div class="ov-cat-ico"><i class="fa <?= h(ov_cat_icon((string)$cat['name'])) ?>"></i></div>
          <div class="ov-cat-name"><?= h((string)$cat['name']) ?></div>
          <div class="ov-cat-units"><?= (int)$cat['units'] ?> units</div>
        </div>
      <?php endforeach; endif; ?>
      <div class="ov-cat-total"><span>Total</span><span><?= (int)$kpi['units'] ?> units</span></div>
    </div>
  </div>

  <div class="ov-bot">
    <div class="ov-card">
      <div class="ov-card-h">
        <h3>Stock Status by Location</h3>
        <a class="ov-link" href="<?= h($ovInventoryHref) ?>"<?= $ovInventoryAttr ?>>View All</a>
      </div>
      <div class="ov-table-wrap">
        <table class="ov-table">
          <thead>
            <tr>
              <th>Location</th>
              <th>Total Units</th>
              <th>In Stock</th>
              <th>Low Stock</th>
              <th>Out of Stock</th>
              <th>Value</th>
            </tr>
          </thead>
          <tbody>
            <tr>
              <td>Direct Store</td>
              <td><?= (int)$locUnits ?></td>
              <td><?= (int)$locIn ?></td>
              <td><?= (int)$locLow ?></td>
              <td><?= (int)$locOut ?></td>
              <td><?= h(inv_money($locValue)) ?></td>
            </tr>
          </tbody>
          <tfoot>
            <tr>
              <td>Total</td>
              <td><?= (int)$locUnits ?></td>
              <td><?= (int)$locIn ?></td>
              <td><?= (int)$locLow ?></td>
              <td><?= (int)$locOut ?></td>
              <td><?= h(inv_money($locValue)) ?></td>
            </tr>
          </tfoot>
        </table>
      </div>
    </div>

    <div class="ov-card">
      <div class="ov-card-h">
        <h3>Recent Stock Movements</h3>
        <a class="ov-link" href="<?= h($ovInventoryHref) ?>"<?= $ovInventoryAttr ?>>View All</a>
      </div>
      <?php if (!$feed): ?>
        <p class="ov-empty">No stock movements yet.</p>
      <?php else: foreach ($feed as $mv): ?>
        <div class="ov-move">
          <div class="ov-move-ico <?= !empty($mv['plus']) ? 'plus' : 'minus' ?>"><i class="fa <?= !empty($mv['plus']) ? 'fa-arrow-down' : 'fa-arrow-up' ?>"></i></div>
          <div>
            <span class="ov-move-title"><?= h((string)$mv['title']) ?></span>
            <span class="ov-move-sub"><?= h((string)$mv['when']) ?></span>
          </div>
          <div class="ov-qty <?= !empty($mv['plus']) ? 'plus' : 'minus' ?>"><?= !empty($mv['plus']) ? '+' : '−' ?><?= (int)$mv['qty'] ?> units</div>
        </div>
      <?php endforeach; endif; ?>
    </div>
  </div>

  <?php if ((int)$kpi['low'] > 0): ?>
    <div class="ov-alert">
      <div class="ov-alert-copy"><i class="fa fa-exclamation-triangle"></i> Low Stock Alert: You have <?= (int)$kpi['low'] ?> item<?= (int)$kpi['low'] === 1 ? '' : 's' ?> that <?= (int)$kpi['low'] === 1 ? 'is' : 'are' ?> running low on stock.</div>
      <a href="<?= h($ovLowHref) ?>">View Low Stock Items</a>
    </div>
  <?php endif; ?>
</div>
<script>
(function () {
  var wrap = document.getElementById('ovChartWrap');
  var tip = document.getElementById('ovChartTip');
  if (wrap && tip) {
    wrap.querySelectorAll('.ov-dot-pt').forEach(function (dot) {
      dot.addEventListener('mouseenter', function (ev) {
        tip.textContent = dot.getAttribute('data-label') || '';
        tip.style.display = 'block';
        var r = wrap.getBoundingClientRect();
        tip.style.left = Math.max(0, ev.clientX - r.left - 40) + 'px';
        tip.style.top = Math.max(0, ev.clientY - r.top - 36) + 'px';
      });
      dot.addEventListener('mouseleave', function () { tip.style.display = 'none'; });
    });
  }
  var btn = document.getElementById('ovExportBtn');
  if (btn) {
    btn.addEventListener('click', function () {
      var lines = [
        'Metric,Value',
        'Total Inventory Value,<?= addslashes(inv_money((int)$kpi['value'])) ?>',
        'Total Products,<?= (int)$kpi['total'] ?>',
        'Total Units,<?= (int)$kpi['units'] ?>',
        'Low Stock Items,<?= (int)$kpi['low'] ?>',
        'Out of Stock Items,<?= (int)$kpi['out'] ?>'
      ];
      var blob = new Blob([lines.join('\n')], { type: 'text/csv;charset=utf-8;' });
      var a = document.createElement('a');
      a.href = URL.createObjectURL(blob);
      a.download = 'inventory-overview.csv';
      a.click();
      setTimeout(function () { URL.revokeObjectURL(a.href); }, 500);
    });
  }
  var rangeSel = document.getElementById('ovRangeSel');
  if (rangeSel) {
    rangeSel.addEventListener('change', function () {
      var v = encodeURIComponent(rangeSel.value || '7');
      window.location.href = <?= $ovInSalesHub ? "'sales_management.php?ov_range=' + v + '#overview'" : "'overview.php?ov_range=' + v" ?>;
    });
  }
})();
</script>
