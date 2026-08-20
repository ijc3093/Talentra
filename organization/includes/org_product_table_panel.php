<?php
declare(strict_types=1);

/**
 * Inventory dashboard for product_table.php and sales_management.php#inventory.
 *
 * Expected: PDO $dbh, int $orgId, string $err, $ok
 * Optional: $ptFormAction, $ptAddHref, $ptAddAttr, $ptEditBase, $ptEditHash,
 *           $ptDetailBase, $ptDetailSuffix, $ptShowStoreToolbar,
 *           $ptNotiCount, $ptMsgCount, $ptBaseUrl, $ptHash, $invTab
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

$ptFormAction = (string)($ptFormAction ?? '');
$ptAddHref = (string)($ptAddHref ?? 'products.php');
$ptAddAttr = (string)($ptAddAttr ?? '');
$ptEditBase = (string)($ptEditBase ?? 'products.php?edit=');
$ptEditHash = (string)($ptEditHash ?? '');
$ptDetailBase = (string)($ptDetailBase ?? 'product_table.php?id=');
$ptDetailSuffix = (string)($ptDetailSuffix ?? '');
$ptPreviewBase = (string)($ptPreviewBase ?? '../public_user/product_detail.php?id=');
$ptShowStoreToolbar = !empty($ptShowStoreToolbar);
$ptNotiCount = (int)($ptNotiCount ?? 0);
$ptMsgCount = (int)($ptMsgCount ?? 0);
$ptBaseUrl = (string)($ptBaseUrl ?? 'product_table.php');
$ptHash = (string)($ptHash ?? '');
$ptAlertsHref = (string)($ptAlertsHref ?? '');
$err = (string)($err ?? '');
$ok = (string)($ok ?? '');
$lowStockAt = 5;

$invTab = strtolower(trim((string)($invTab ?? $_GET['inv'] ?? 'all')));
if (!in_array($invTab, ['all', 'low', 'out'], true)) {
    $invTab = 'all';
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

$now = time();
$cut30 = $now - 30 * 86400;
$cut60 = $now - 60 * 86400;
$kpi = ['total' => 0, 'units' => 0, 'value' => 0, 'low' => 0, 'out' => 0, 'in' => 0, 'draft' => 0];
$kpi30 = $kpi;
$kpiPrev = $kpi;
$categories = [];
$rows = [];

foreach ($products as $p) {
    $pid = (int)($p['id'] ?? 0);
    $status = strtolower(trim((string)($p['status'] ?? 'draft')));
    $tracked = $p['stock_qty'] !== null && $p['stock_qty'] !== '';
    $available = $tracked ? max(0, (int)$p['stock_qty']) : null;
    $reserved = (int)($reservedMap[$pid] ?? 0);
    $totalStock = ($available !== null ? $available : 0) + $reserved;
    $priceCents = max(0, (int)($p['price_cents'] ?? 0));
    $valueCents = ($available !== null ? $available : 0) * $priceCents;
    $cat = trim((string)($p['category'] ?? '')) ?: 'Uncategorized';
    $categories[$cat] = true;
    $createdTs = strtotime((string)($p['created_at'] ?? '')) ?: 0;
    $updatedTs = strtotime((string)($p['updated_at'] ?? '')) ?: $createdTs;

    $stockCls = 'in';
    $stockLabel = 'In Stock';
    if ($status === 'sold_out' || ($tracked && $available !== null && $available <= 0)) {
        $stockCls = 'out';
        $stockLabel = 'Out of Stock';
    } elseif ($tracked && $available !== null && $available <= $lowStockAt) {
        $stockCls = 'low';
        $stockLabel = 'Low Stock';
    }

    $isDraft = ($status === 'draft' || $status === 'archived');
    $kpi['total']++;
    $kpi['units'] += ($available !== null ? $available : 0);
    $kpi['value'] += $valueCents;
    if ($isDraft) {
        $kpi['draft']++;
    } elseif ($stockCls === 'out') {
        $kpi['out']++;
    } elseif ($stockCls === 'low') {
        $kpi['low']++;
    } else {
        $kpi['in']++;
    }

    $ageKpi = $isDraft ? 'draft' : $stockCls;
    if ($ageKpi === 'in') {
        $ageKpi = 'in';
    }
    if ($createdTs >= $cut30) {
        $kpi30['total']++;
        $kpi30['units'] += ($available !== null ? $available : 0);
        $kpi30['value'] += $valueCents;
        if (isset($kpi30[$ageKpi])) {
            $kpi30[$ageKpi]++;
        }
    } elseif ($createdTs >= $cut60) {
        $kpiPrev['total']++;
        $kpiPrev['units'] += ($available !== null ? $available : 0);
        $kpiPrev['value'] += $valueCents;
        if (isset($kpiPrev[$ageKpi])) {
            $kpiPrev[$ageKpi]++;
        }
    }

    $attrs = json_decode((string)($p['attributes_json'] ?? ''), true);
    $variantCount = is_array($attrs) ? count($attrs) : 0;
    $variantLabel = $variantCount > 0
        ? ($variantCount . ' variant' . ($variantCount === 1 ? '' : 's'))
        : '';

    $sku = trim((string)($p['sku'] ?? ''));
    if ($sku === '') {
        $sku = trim((string)($p['product_code'] ?? ''));
    }

    $rows[] = [
        'id' => $pid,
        'title' => trim((string)($p['title'] ?? '')) ?: 'Untitled',
        'category' => $cat,
        'sku' => $sku !== '' ? $sku : '—',
        'variant_label' => $variantLabel,
        'status_raw' => $status,
        'listing' => $isDraft ? 'Draft' : 'Active',
        'stock_cls' => $stockCls,
        'stock_label' => $stockLabel,
        'total_stock' => $totalStock,
        'available' => $available,
        'reserved' => $reserved,
        'value' => inv_money($valueCents, (string)($p['currency'] ?? 'USD')),
        'value_cents' => $valueCents,
        'updated' => $updatedTs ? date('M j, Y g:i A', $updatedTs) : '—',
        'cover' => product_table_cover_url((string)($p['cover_image_path'] ?? '')),
        'public_post_id' => (int)($p['public_post_id'] ?? 0),
        'search' => mb_strtolower(trim(implode(' ', array_filter([
            (string)($p['title'] ?? ''),
            $sku,
            $cat,
            (string)($p['product_code'] ?? ''),
        ])))),
    ];
}

ksort($categories);

if ($invTab === 'low') {
    $visible = array_values(array_filter($rows, static function (array $r): bool {
        return $r['stock_cls'] === 'low';
    }));
} elseif ($invTab === 'out') {
    $visible = array_values(array_filter($rows, static function (array $r): bool {
        return $r['stock_cls'] === 'out';
    }));
} else {
    $visible = $rows;
}

$lowList = array_values(array_filter($rows, static function (array $r): bool {
    return $r['stock_cls'] === 'low';
}));
usort($lowList, static function (array $a, array $b): int {
    return ((int)($a['available'] ?? 0)) <=> ((int)($b['available'] ?? 0));
});
$lowList = array_slice($lowList, 0, 4);

$donutN = max(0, (int)$kpi['total']);
$donutIn = $donutN > 0 ? round($kpi['in'] / $donutN * 100, 1) : 0.0;
$donutLow = $donutN > 0 ? round($kpi['low'] / $donutN * 100, 1) : 0.0;
$donutOut = $donutN > 0 ? round($kpi['out'] / $donutN * 100, 1) : 0.0;
$donutDraft = $donutN > 0 ? round($kpi['draft'] / $donutN * 100, 1) : 0.0;
$g1 = $donutIn;
$g2 = $g1 + $donutLow;
$g3 = $g2 + $donutOut;

$tabHref = static function (string $tab) use ($ptBaseUrl, $ptHash): string {
    $qs = $tab === 'all' ? '' : ('?inv=' . rawurlencode($tab));
    return $ptBaseUrl . $qs . $ptHash;
};
$ptFormAttr = $ptFormAction !== '' ? ' action="' . h($ptFormAction) . '"' : '';
$alertsHref = $ptAlertsHref !== '' ? $ptAlertsHref : $tabHref('low');

[$vPct, $vUp] = inv_pct((int)$kpi30['value'], (int)$kpiPrev['value']);
[$tPct, $tUp] = inv_pct((int)$kpi30['total'], (int)$kpiPrev['total']);
[$uPct, $uUp] = inv_pct((int)$kpi30['units'], (int)$kpiPrev['units']);
[$lPct, $lUp] = inv_pct((int)$kpi30['low'], (int)$kpiPrev['low']);
[$oPct, $oUp] = inv_pct((int)$kpi30['out'], (int)$kpiPrev['out']);
?>
<style>
  .inv-dash{--inv-text:#0f172a;--inv-muted:#64748b;--inv-border:#e2e8f0;--inv-card:#fff;color:var(--inv-text);}
  .inv-dash .inv-hero{display:flex;justify-content:flex-end;gap:8px;margin-bottom:12px;flex-wrap:wrap;align-items:center;}
  .inv-dash .inv-btn{height:32px;padding:0 12px;border-radius:8px;border:1px solid #cbd5e1;background:var(--ch-surface,#fff);font-size:12px;font-weight:700;display:inline-flex;align-items:center;gap:6px;color:#0f172a;text-decoration:none;cursor:pointer;}
  .inv-dash .inv-kpis{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:8px;margin-bottom:10px;}
  .inv-dash .inv-kpi{background:var(--inv-card);border:1px solid var(--inv-border);border-radius:12px;padding:12px;}
  .inv-dash .inv-kpi-top{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:8px;}
  .inv-dash .inv-ico{width:28px;height:28px;border-radius:8px;display:inline-flex;align-items:center;justify-content:center;font-size:13px;}
  .inv-dash .inv-ico.purple{background:#f3e8ff;color:#7c3aed;}
  .inv-dash .inv-ico.blue{background:#dbeafe;color:#2563eb;}
  .inv-dash .inv-ico.green{background:#dcfce7;color:#16a34a;}
  .inv-dash .inv-ico.orange{background:#ffedd5;color:#ea580c;}
  .inv-dash .inv-ico.red{background:#fee2e2;color:#dc2626;}
  .inv-dash .inv-lab{font-size:11px;font-weight:700;color:var(--inv-muted);}
  .inv-dash .inv-val{font-size:22px;font-weight:800;line-height:1.15;margin-top:2px;}
  .inv-dash .inv-delta{font-size:11px;font-weight:700;}
  .inv-dash .inv-delta.up{color:#16a34a;}
  .inv-dash .inv-delta.down{color:#dc2626;}
  .inv-dash .inv-filters{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-bottom:10px;}
  .inv-dash .inv-filters select,.inv-dash .inv-filters input{height:34px;border:1px solid var(--inv-border);border-radius:8px;background:var(--ch-surface,#fff);font-size:12px;padding:0 10px;}
  .inv-dash .inv-search{display:flex;align-items:center;gap:6px;flex:1 1 220px;min-width:180px;height:34px;padding:0 10px;border:1px solid var(--inv-border);border-radius:8px;background:var(--ch-surface,#fff);}
  .inv-dash .inv-search input{border:0;box-shadow:none;height:32px;flex:1;padding:0;background:transparent;}
  .inv-dash .inv-card{background:var(--inv-card);border:1px solid var(--inv-border);border-radius:12px;overflow:hidden;}
  .inv-dash .inv-tabs{display:flex;gap:18px;padding:0 14px;border-bottom:1px solid var(--inv-border);overflow:auto;}
  .inv-dash .inv-tab{flex:0 0 auto;padding:12px 0 10px;font-size:13px;font-weight:700;color:var(--inv-muted);text-decoration:none;border-bottom:2px solid transparent;}
  .inv-dash .inv-tab.is-on{color:#2563eb;border-bottom-color:#2563eb;}
  .inv-dash .inv-table-wrap{overflow:auto;}
  .inv-dash .inv-table{width:100%;min-width:1180px;border-collapse:collapse;}
  .inv-dash .inv-table th{font-size:11px;font-weight:800;letter-spacing:.04em;text-transform:uppercase;color:var(--inv-muted);text-align:left;padding:10px 12px;border-bottom:1px solid var(--inv-border);background:var(--ch-surface,#f8fafc);white-space:nowrap;}
  .inv-dash .inv-table td{padding:12px;border-bottom:1px solid var(--inv-border);vertical-align:middle;font-size:13px;}
  .inv-dash .inv-prod{display:flex;align-items:center;gap:10px;min-width:0;}
  .inv-dash .inv-thumb{width:40px;height:40px;border-radius:8px;background:#f1f5f9;overflow:hidden;display:flex;align-items:center;justify-content:center;color:#94a3b8;flex:0 0 auto;position:relative;}
  .inv-dash .inv-thumb img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;z-index:1;}
  .inv-dash .inv-name{display:block;font-weight:800;}
  .inv-dash .inv-sub{display:block;font-size:11px;color:var(--inv-muted);font-weight:600;margin-top:2px;}
  .inv-dash .inv-pill{display:inline-flex;align-items:center;padding:3px 8px;border-radius:999px;font-size:11px;font-weight:800;}
  .inv-dash .inv-pill.in{background:#dcfce7;color:#15803d;}
  .inv-dash .inv-pill.low{background:#ffedd5;color:#c2410c;}
  .inv-dash .inv-pill.out{background:#fee2e2;color:#b91c1c;}
  .inv-dash .inv-avail{color:#15803d;font-weight:800;}
  .inv-dash .inv-res{color:#c2410c;font-weight:800;}
  .inv-dash .inv-more{position:relative;}
  .inv-dash .inv-more-btn{width:28px;height:28px;border-radius:8px;border:1px solid #dbeafe;background:var(--ch-surface,#eff6ff);color:#2563eb;cursor:pointer;font-size:16px;line-height:1;font-weight:800;}
  .inv-dash .inv-more-btn:hover,.inv-dash .inv-more.is-open .inv-more-btn{background:#dbeafe;}
  .inv-dash .inv-more-menu{display:none;position:absolute;right:0;top:32px;z-index:80;min-width:220px;background:var(--ch-surface,#fff);border:1px solid #e2e8f0;border-radius:12px;box-shadow:0 12px 32px rgba(15,23,42,.16);padding:6px;}
  .inv-dash .inv-more.is-open .inv-more-menu{display:block;}
  .inv-dash .inv-more-menu a,.inv-dash .inv-more-menu button{display:flex;align-items:center;gap:10px;width:100%;text-align:left;padding:8px 10px;border:0;border-radius:8px;background:transparent;font-size:13px;font-weight:600;color:#0f172a;text-decoration:none;cursor:pointer;line-height:1.3;}
  .inv-dash .inv-more-menu a:hover,.inv-dash .inv-more-menu button:hover{background:var(--ch-surface,#f8fafc);}
  .inv-dash .inv-more-menu i{width:16px;text-align:center;color:#64748b;font-size:14px;}
  .inv-dash .inv-more-sep{height:1px;background:#e2e8f0;margin:6px 4px;}
  .inv-dash .inv-more-form{margin:0;}
  .inv-dash .inv-more-menu .is-danger,.inv-dash .inv-more-menu .is-danger i{color:#dc2626;}
  .inv-dash .inv-more-menu .is-danger:hover{background:#fef2f2;}
  .inv-dash .inv-foot{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:10px;padding:10px 12px;font-size:12px;color:var(--inv-muted);}
  .inv-dash .inv-pages{display:flex;gap:4px;align-items:center;}
  .inv-dash .inv-pages button{min-width:28px;height:28px;border:1px solid #e2e8f0;background:var(--ch-surface,#fff);border-radius:7px;font-size:12px;font-weight:700;cursor:pointer;}
  .inv-dash .inv-pages button.is-on{background:#2563eb;border-color:#2563eb;color:#fff;}
  .inv-dash .inv-empty{text-align:center;padding:28px 12px;color:var(--inv-muted);}
  .inv-dash .inv-widgets{display:grid;grid-template-columns:1.1fr 1fr 1fr;gap:10px;margin-top:12px;}
  .inv-dash .inv-widget{background:var(--inv-card);border:1px solid var(--inv-border);border-radius:12px;padding:14px;}
  .inv-dash .inv-widget h3{margin:0 0 12px;font-size:14px;font-weight:800;}
  .inv-dash .inv-donut-row{display:flex;align-items:center;gap:16px;}
  .inv-dash .inv-donut{width:132px;height:132px;border-radius:50%;position:relative;flex:0 0 auto;}
  .inv-dash .inv-donut:after{content:'';position:absolute;inset:28px;background:var(--ch-surface,#fff);border-radius:50%;}
  .inv-dash .inv-donut-center{position:absolute;inset:0;z-index:1;display:flex;flex-direction:column;align-items:center;justify-content:center;pointer-events:none;}
  .inv-dash .inv-donut-center strong{font-size:18px;font-weight:800;line-height:1;}
  .inv-dash .inv-donut-center span{font-size:10px;font-weight:700;color:var(--inv-muted);margin-top:4px;}
  .inv-dash .inv-legend{list-style:none;margin:0;padding:0;font-size:12px;}
  .inv-dash .inv-legend li{display:flex;align-items:center;justify-content:space-between;gap:8px;padding:4px 0;}
  .inv-dash .inv-dot{width:8px;height:8px;border-radius:50%;display:inline-block;margin-right:8px;}
  .inv-dash .inv-low-row{display:flex;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid var(--inv-border);}
  .inv-dash .inv-low-row:last-child{border-bottom:0;}
  .inv-dash .inv-reorder{height:28px;padding:0 10px;border-radius:8px;border:1px solid #fdba74;background:#fff7ed;color:#c2410c;font-size:11px;font-weight:800;text-decoration:none;display:inline-flex;align-items:center;margin-left:auto;}
  .inv-dash .inv-move{display:flex;gap:10px;padding:8px 0;border-bottom:1px solid var(--inv-border);}
  .inv-dash .inv-move:last-child{border-bottom:0;}
  .inv-dash .inv-move-ico{width:28px;height:28px;border-radius:8px;display:flex;align-items:center;justify-content:center;flex:0 0 auto;font-size:12px;}
  .inv-dash .inv-move-ico.plus{background:#dcfce7;color:#16a34a;}
  .inv-dash .inv-move-ico.minus{background:#fee2e2;color:#dc2626;}
  html.dark-auto .inv-dash{--inv-text:var(--msb-palette-text,#e2e8f0);--inv-muted:#94a3b8;--inv-border:rgba(148,163,184,.22);--inv-card:var(--msb-palette-bg,#171d24);}
  html.dark-auto .inv-dash .inv-donut:after{background:var(--inv-card);}
  @media (max-width:1100px){.inv-dash .inv-kpis,.inv-dash .inv-widgets{grid-template-columns:1fr 1fr;}}
  @media (max-width:700px){.inv-dash .inv-kpis,.inv-dash .inv-widgets{grid-template-columns:1fr;}}
</style>
<div class="inv-dash" id="invDashRoot">
  <div class="inv-hero">
    <?php if ($ptShowStoreToolbar): ?>
      <a class="sd-icon-btn" href="sales_notifications.php" title="Notifications"><i class="fa fa-bell-o"></i><?php if ($ptNotiCount > 0): ?><span class="sd-badge"><?= (int)min(99, $ptNotiCount) ?></span><?php endif; ?></a>
      <a class="sd-icon-btn" href="#message" data-sales-nav="message" title="Messages"><i class="fa fa-commenting-o"></i><?php if ($ptMsgCount > 0): ?><span class="sd-badge"><?= (int)min(99, $ptMsgCount) ?></span><?php endif; ?></a>
    <?php endif; ?>
    <button type="button" class="inv-btn" id="invExportBtn"><i class="fa fa-download"></i> Export</button>
    <a class="inv-btn" href="<?= h($alertsHref) ?>"><i class="fa fa-bell-o"></i> View Stock Alerts</a>
  </div>

  <?php if ($err !== ''): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endif; ?>
  <?php if ($ok !== ''): ?><div class="alert alert-success"><?= h($ok) ?></div><?php endif; ?>

  <div class="inv-kpis">
    <div class="inv-kpi"><div class="inv-kpi-top"><div class="inv-ico purple"><i class="fa fa-usd"></i></div><div class="inv-delta <?= $vUp ? 'up' : 'down' ?>"><?= $vUp ? '↑' : '↓' ?> <?= number_format(abs($vPct), 1) ?>% vs last 30 days</div></div><div class="inv-lab">Total Inventory Value</div><div class="inv-val"><?= h(inv_money((int)$kpi['value'])) ?></div></div>
    <div class="inv-kpi"><div class="inv-kpi-top"><div class="inv-ico blue"><i class="fa fa-cube"></i></div><div class="inv-delta <?= $tUp ? 'up' : 'down' ?>"><?= $tUp ? '↑' : '↓' ?> <?= number_format(abs($tPct), 1) ?>% vs last 30 days</div></div><div class="inv-lab">Total Products</div><div class="inv-val"><?= (int)$kpi['total'] ?></div></div>
    <div class="inv-kpi"><div class="inv-kpi-top"><div class="inv-ico green"><i class="fa fa-th"></i></div><div class="inv-delta <?= $uUp ? 'up' : 'down' ?>"><?= $uUp ? '↑' : '↓' ?> <?= number_format(abs($uPct), 1) ?>% vs last 30 days</div></div><div class="inv-lab">Total Units in Stock</div><div class="inv-val"><?= (int)$kpi['units'] ?></div></div>
    <div class="inv-kpi"><div class="inv-kpi-top"><div class="inv-ico orange"><i class="fa fa-exclamation-triangle"></i></div><div class="inv-delta <?= $lUp ? 'up' : 'down' ?>"><?= $lUp ? '↑' : '↓' ?> <?= number_format(abs($lPct), 1) ?>% vs last 30 days</div></div><div class="inv-lab">Low Stock Items</div><div class="inv-val"><?= (int)$kpi['low'] ?></div></div>
    <div class="inv-kpi"><div class="inv-kpi-top"><div class="inv-ico red"><i class="fa fa-times-circle"></i></div><div class="inv-delta <?= $oUp ? 'up' : 'down' ?>"><?= $oUp ? '↑' : '↓' ?> <?= number_format(abs($oPct), 1) ?>% vs last 30 days</div></div><div class="inv-lab">Out of Stock Items</div><div class="inv-val"><?= (int)$kpi['out'] ?></div></div>
  </div>

  <div class="inv-filters">
    <label class="inv-search">
      <i class="icon ion-ios-search"></i>
      <input type="search" id="invSearch" placeholder="Search products, SKU..." autocomplete="off">
    </label>
    <select id="invCatFilter">
      <option value="">All Categories</option>
      <?php foreach (array_keys($categories) as $cat): ?>
        <option value="<?= h($cat) ?>"><?= h($cat) ?></option>
      <?php endforeach; ?>
    </select>
    <select id="invStatusFilter">
      <option value="">All Statuses</option>
      <option value="Active">Active</option>
      <option value="Draft">Draft</option>
    </select>
    <select id="invStockFilter">
      <option value="">All Stock Status</option>
      <option value="in">In Stock</option>
      <option value="low">Low Stock</option>
      <option value="out">Out of Stock</option>
    </select>
    <select id="invLocFilter">
      <option value="">All Locations</option>
      <option value="Direct Store">Direct Store</option>
    </select>
    <button type="button" class="inv-btn" id="invFilterBtn"><i class="fa fa-filter"></i> Filter</button>
    <button type="button" class="inv-btn" id="invResetBtn"><i class="fa fa-refresh"></i> Reset</button>
  </div>

  <div class="inv-card">
    <div class="inv-tabs">
      <a class="inv-tab<?= $invTab === 'all' ? ' is-on' : '' ?>" href="<?= h($tabHref('all')) ?>">All</a>
      <a class="inv-tab<?= $invTab === 'low' ? ' is-on' : '' ?>" href="<?= h($tabHref('low')) ?>">Low Stock</a>
      <a class="inv-tab<?= $invTab === 'out' ? ' is-on' : '' ?>" href="<?= h($tabHref('out')) ?>">Out of Stock</a>
    </div>
    <div class="inv-table-wrap">
      <table class="inv-table" id="invTable">
        <thead>
          <tr>
            <th style="width:36px;"><input type="checkbox" id="invCheckAll" aria-label="Select all"></th>
            <th>Product</th>
            <th>SKU</th>
            <th>Category</th>
            <th>Stock Status</th>
            <th>Total Stock</th>
            <th>Available</th>
            <th>Reserved</th>
            <th>Incoming</th>
            <th>Value</th>
            <th>Last Updated</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$visible): ?>
            <tr><td colspan="12" class="inv-empty">No products yet. <a href="<?= h($ptAddHref) ?>"<?= $ptAddAttr ?>>Add a product</a>.</td></tr>
          <?php else: foreach ($visible as $row):
            $editHref = $ptEditBase . (int)$row['id'] . $ptEditHash;
            $detailHref = $ptDetailBase . (int)$row['id'] . $ptDetailSuffix;
            $previewHref = $ptPreviewBase . (int)$row['id'];
            $isDraft = ((string)$row['status_raw'] === 'draft' || (string)$row['listing'] === 'Draft');
            $isOut = ((string)$row['stock_cls'] === 'out');
          ?>
            <tr class="inv-row"
              data-search="<?= h((string)$row['search']) ?>"
              data-cat="<?= h((string)$row['category']) ?>"
              data-status="<?= h((string)$row['listing']) ?>"
              data-stock="<?= h((string)$row['stock_cls']) ?>"
              data-loc="Direct Store"
              data-title="<?= h((string)$row['title']) ?>"
              data-sku="<?= h((string)$row['sku']) ?>"
              data-value="<?= h((string)$row['value']) ?>"
              data-total="<?= (int)$row['total_stock'] ?>"
              data-available="<?= $row['available'] === null ? '' : (int)$row['available'] ?>"
              data-reserved="<?= (int)$row['reserved'] ?>"
            >
              <td><input type="checkbox" class="inv-check"></td>
              <td>
                <div class="inv-prod">
                  <div class="inv-thumb"><?php if ($row['cover'] !== ''): ?><img src="<?= h((string)$row['cover']) ?>" alt="" onerror="this.remove()"><i class="fa fa-cube"></i><?php else: ?><i class="fa fa-cube"></i><?php endif; ?></div>
                  <div>
                    <a class="inv-name" href="<?= h($detailHref) ?>"><?= h((string)$row['title']) ?></a>
                    <?php if ((string)$row['variant_label'] !== ''): ?><span class="inv-sub"><?= h((string)$row['variant_label']) ?></span><?php endif; ?>
                  </div>
                </div>
              </td>
              <td><strong><?= h((string)$row['sku']) ?></strong></td>
              <td><?= h((string)$row['category']) ?></td>
              <td><span class="inv-pill <?= h((string)$row['stock_cls']) ?>"><?= h((string)$row['stock_label']) ?></span></td>
              <td><strong><?= (int)$row['total_stock'] ?></strong></td>
              <td class="inv-avail"><?= $row['available'] === null ? '—' : (int)$row['available'] ?></td>
              <td class="inv-res"><?= (int)$row['reserved'] ?></td>
              <td class="inv-sub" style="display:table-cell;">—</td>
              <td><strong><?= h((string)$row['value']) ?></strong></td>
              <td class="inv-sub" style="display:table-cell;"><?= h((string)$row['updated']) ?></td>
              <td>
                <div class="inv-more">
                  <button type="button" class="inv-more-btn" aria-label="Inventory actions" aria-haspopup="true">⋯</button>
                  <div class="inv-more-menu" role="menu">
                    <a href="<?= h($detailHref) ?>" role="menuitem"><i class="fa fa-eye"></i> View Product</a>
                    <a href="<?= h($editHref) ?>"<?= $ptEditHash !== '' ? ' data-sales-nav="products"' : '' ?> role="menuitem"><i class="fa fa-pencil"></i> Edit Product</a>
                    <a href="<?= h($editHref) ?>"<?= $ptEditHash !== '' ? ' data-sales-nav="products"' : '' ?> role="menuitem"><i class="fa fa-cube"></i> Adjust Stock</a>
                    <a href="<?= h($previewHref) ?>" target="_blank" rel="noopener" role="menuitem"><i class="fa fa-search"></i> Preview Listing</a>
                    <div class="inv-more-sep"></div>
                    <form method="post"<?= $ptFormAttr ?> class="inv-more-form">
                      <input type="hidden" name="inv_action" value="1">
                      <input type="hidden" name="action" value="duplicate">
                      <input type="hidden" name="product_id" value="<?= (int)$row['id'] ?>">
                      <input type="hidden" name="inv_tab" value="<?= h($invTab) ?>">
                      <button type="submit" role="menuitem"><i class="fa fa-files-o"></i> Duplicate Product</button>
                    </form>
                    <?php if (!$isOut): ?>
                    <form method="post"<?= $ptFormAttr ?> class="inv-more-form">
                      <input type="hidden" name="inv_action" value="1">
                      <input type="hidden" name="action" value="out_of_stock">
                      <input type="hidden" name="product_id" value="<?= (int)$row['id'] ?>">
                      <input type="hidden" name="inv_tab" value="<?= h($invTab) ?>">
                      <button type="submit" role="menuitem"><i class="fa fa-ban"></i> Mark Out of Stock</button>
                    </form>
                    <?php endif; ?>
                    <form method="post"<?= $ptFormAttr ?> class="inv-more-form">
                      <input type="hidden" name="inv_action" value="1">
                      <input type="hidden" name="action" value="<?= $isDraft ? 'activate' : 'deactivate' ?>">
                      <input type="hidden" name="product_id" value="<?= (int)$row['id'] ?>">
                      <input type="hidden" name="inv_tab" value="<?= h($invTab) ?>">
                      <button type="submit" role="menuitem"><?php if ($isDraft): ?><i class="fa fa-eye"></i> Activate Listing<?php else: ?><i class="fa fa-eye-slash"></i> Deactivate Listing<?php endif; ?></button>
                    </form>
                    <?php if ((string)$row['status_raw'] === 'active'): ?>
                    <form method="post"<?= $ptFormAttr ?> class="inv-more-form">
                      <input type="hidden" name="pt_action" value="1">
                      <input type="hidden" name="action" value="publish_feed">
                      <input type="hidden" name="product_id" value="<?= (int)$row['id'] ?>">
                      <button type="submit" role="menuitem"><i class="fa fa-rss"></i> Publish to Feed</button>
                    </form>
                    <?php endif; ?>
                    <div class="inv-more-sep"></div>
                    <form method="post"<?= $ptFormAttr ?> class="inv-more-form" onsubmit="return confirm('Delete this product?');">
                      <input type="hidden" name="inv_action" value="1">
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="product_id" value="<?= (int)$row['id'] ?>">
                      <input type="hidden" name="inv_tab" value="<?= h($invTab) ?>">
                      <button type="submit" class="is-danger" role="menuitem"><i class="fa fa-trash"></i> Delete Product</button>
                    </form>
                  </div>
                </div>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
    <div class="inv-foot" id="invFoot" <?= !$visible ? 'hidden' : '' ?>>
      <div id="invFootLabel">Showing 0 of 0 products</div>
      <div class="inv-pages" id="invPages"></div>
      <label>
        <select id="invPageSize">
          <option value="10" selected>10 / page</option>
          <option value="25">25 / page</option>
          <option value="50">50 / page</option>
        </select>
      </label>
    </div>
  </div>

  <div class="inv-widgets">
    <div class="inv-widget">
      <h3>Stock Status Summary</h3>
      <div class="inv-donut-row">
        <div class="inv-donut" style="background:conic-gradient(#16a34a 0% <?= h((string)$g1) ?>%, #f59e0b <?= h((string)$g1) ?>% <?= h((string)$g2) ?>%, #ef4444 <?= h((string)$g2) ?>% <?= h((string)$g3) ?>%, #94a3b8 <?= h((string)$g3) ?>% 100%);">
          <div class="inv-donut-center"><strong><?= (int)$kpi['total'] ?></strong><span>Products</span></div>
        </div>
        <ul class="inv-legend">
          <li><span><span class="inv-dot" style="background:#16a34a"></span>In Stock</span><strong><?= number_format($donutIn, 1) ?>%</strong></li>
          <li><span><span class="inv-dot" style="background:#f59e0b"></span>Low Stock</span><strong><?= number_format($donutLow, 1) ?>%</strong></li>
          <li><span><span class="inv-dot" style="background:#ef4444"></span>Out of Stock</span><strong><?= number_format($donutOut, 1) ?>%</strong></li>
          <li><span><span class="inv-dot" style="background:#94a3b8"></span>Draft / Inactive</span><strong><?= number_format($donutDraft, 1) ?>%</strong></li>
        </ul>
      </div>
    </div>
    <div class="inv-widget">
      <h3>Top Low Stock Products</h3>
      <?php if (!$lowList): ?>
        <p class="inv-sub">No low-stock products right now.</p>
      <?php else: foreach ($lowList as $low): ?>
        <div class="inv-low-row">
          <div class="inv-thumb"><?php if ($low['cover'] !== ''): ?><img src="<?= h((string)$low['cover']) ?>" alt="" onerror="this.remove()"><i class="fa fa-cube"></i><?php else: ?><i class="fa fa-cube"></i><?php endif; ?></div>
          <div>
            <span class="inv-name"><a href="<?= h($ptDetailBase . (int)$low['id'] . $ptDetailSuffix) ?>"><?= h((string)$low['title']) ?></a></span>
            <span class="inv-sub"><?= h((string)$low['sku']) ?> · Available <?= (int)($low['available'] ?? 0) ?></span>
          </div>
          <a class="inv-reorder" href="<?= h($ptDetailBase . (int)$low['id'] . $ptDetailSuffix) ?>">Reorder</a>
        </div>
      <?php endforeach; endif; ?>
    </div>
    <div class="inv-widget">
      <h3>Recent Stock Movements</h3>
      <?php if (!$movements): ?>
        <p class="inv-sub">No stock movements yet.</p>
      <?php else: foreach ($movements as $mv):
        $st = strtolower(trim((string)($mv['status'] ?? '')));
        $qty = max(1, (int)($mv['quantity'] ?? 1));
        $isReturn = in_array($st, ['cancelled', 'canceled'], true);
        $label = $isReturn ? 'Stock returned' : (in_array($st, ['shipped', 'delivered'], true) ? 'Stock sold' : 'Stock reserved');
        $title = trim((string)($mv['title'] ?? $mv['product_title'] ?? 'Product'));
        $when = strtotime((string)($mv['created_at'] ?? ''));
        $cover = product_table_cover_url((string)($mv['cover_image_path'] ?? ''));
      ?>
        <div class="inv-move">
          <div class="inv-move-ico <?= $isReturn ? 'plus' : 'minus' ?>"><i class="fa <?= $isReturn ? 'fa-arrow-down' : 'fa-arrow-up' ?>"></i></div>
          <div>
            <span class="inv-name"><?= h($label) ?></span>
            <span class="inv-sub"><?= h($title) ?> · <?= $isReturn ? '+' : '−' ?><?= $qty ?> units<?= $when ? ' · ' . date('M j, g:i A', $when) : '' ?></span>
          </div>
        </div>
      <?php endforeach; endif; ?>
    </div>
  </div>
</div>
<script>
(function () {
  var root = document.getElementById('invDashRoot');
  if (!root) return;
  var rows = Array.prototype.slice.call(root.querySelectorAll('.inv-row'));
  var search = document.getElementById('invSearch');
  var cat = document.getElementById('invCatFilter');
  var status = document.getElementById('invStatusFilter');
  var stock = document.getElementById('invStockFilter');
  var loc = document.getElementById('invLocFilter');
  var pageSizeEl = document.getElementById('invPageSize');
  var foot = document.getElementById('invFoot');
  var footLabel = document.getElementById('invFootLabel');
  var pagesEl = document.getElementById('invPages');
  var page = 1;

  function visibleRows() {
    var q = String(search && search.value || '').trim().toLowerCase();
    var c = String(cat && cat.value || '');
    var st = String(status && status.value || '');
    var sk = String(stock && stock.value || '');
    var lc = String(loc && loc.value || '');
    return rows.filter(function (row) {
      if (q && String(row.getAttribute('data-search') || '').indexOf(q) === -1) return false;
      if (c && row.getAttribute('data-cat') !== c) return false;
      if (st && row.getAttribute('data-status') !== st) return false;
      if (sk && row.getAttribute('data-stock') !== sk) return false;
      if (lc && row.getAttribute('data-loc') !== lc) return false;
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
    rows.forEach(function (row) { row.hidden = true; });
    vis.forEach(function (row, i) { row.hidden = !(i >= start && i < end); });
    if (foot) foot.hidden = total === 0;
    if (footLabel) {
      footLabel.textContent = total === 0 ? 'No matching products' : ('Showing ' + (total ? (start + 1) : 0) + ' to ' + end + ' of ' + total + ' products');
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
      for (var i = 1; i <= pages && i <= 8; i++) addBtn(String(i), i, i === page);
      addBtn('›', Math.min(pages, page + 1), false);
    }
  }

  [search, cat, status, stock, loc, pageSizeEl].forEach(function (el) {
    if (!el) return;
    el.addEventListener(el.tagName === 'INPUT' ? 'input' : 'change', function () { page = 1; render(); });
  });
  var filterBtn = document.getElementById('invFilterBtn');
  if (filterBtn) filterBtn.addEventListener('click', function () { page = 1; render(); });
  var resetBtn = document.getElementById('invResetBtn');
  if (resetBtn) {
    resetBtn.addEventListener('click', function () {
      if (search) search.value = '';
      if (cat) cat.value = '';
      if (status) status.value = '';
      if (stock) stock.value = '';
      if (loc) loc.value = '';
      page = 1;
      render();
    });
  }
  var exportBtn = document.getElementById('invExportBtn');
  if (exportBtn) {
    exportBtn.addEventListener('click', function () {
      var vis = visibleRows();
      var lines = [['Product', 'SKU', 'Category', 'Stock Status', 'Total Stock', 'Available', 'Reserved', 'Value']];
      vis.forEach(function (row) {
        lines.push([
          row.getAttribute('data-title') || '',
          row.getAttribute('data-sku') || '',
          row.getAttribute('data-cat') || '',
          row.getAttribute('data-stock') || '',
          row.getAttribute('data-total') || '',
          row.getAttribute('data-available') || '',
          row.getAttribute('data-reserved') || '',
          row.getAttribute('data-value') || ''
        ]);
      });
      var csv = lines.map(function (cols) {
        return cols.map(function (c) { return '"' + String(c).replace(/"/g, '""') + '"'; }).join(',');
      }).join('\n');
      var blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
      var a = document.createElement('a');
      a.href = URL.createObjectURL(blob);
      a.download = 'inventory.csv';
      a.click();
      URL.revokeObjectURL(a.href);
    });
  }
  var checkAll = document.getElementById('invCheckAll');
  if (checkAll) {
    checkAll.addEventListener('change', function () {
      visibleRows().forEach(function (row) {
        if (row.hidden) return;
        var box = row.querySelector('.inv-check');
        if (box) box.checked = checkAll.checked;
      });
    });
  }
  function closeMenus() {
    root.querySelectorAll('.inv-more.is-open').forEach(function (el) {
      el.classList.remove('is-open');
      var m = el.querySelector('.inv-more-menu');
      if (m) {
        m.style.position = '';
        m.style.top = '';
        m.style.left = '';
        m.style.right = '';
      }
    });
  }
  root.addEventListener('click', function (e) {
    var btn = e.target.closest('.inv-more-btn');
    if (btn) {
      var wrap = btn.closest('.inv-more');
      var wasOpen = wrap.classList.contains('is-open');
      closeMenus();
      if (!wasOpen) {
        wrap.classList.add('is-open');
        var menu = wrap.querySelector('.inv-more-menu');
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
    if (!e.target.closest('.inv-more')) closeMenus();
  });
  document.addEventListener('click', function (e) {
    if (!e.target.closest('#invDashRoot')) closeMenus();
  });
  window.addEventListener('scroll', closeMenus, true);
  render();
})();
</script>
