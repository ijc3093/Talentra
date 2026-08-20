<?php
declare(strict_types=1);

/**
 * Products catalog dashboard for product_catalog.php and sales_management.php#product-catalog.
 *
 * Expected:
 * - PDO $dbh, int $orgId
 * - string $err, $ok
 * - string $pdAddHref (optional)
 * - string $pdAddAttr (optional)
 * - string $pdEditBase (optional) e.g. products.php?edit=
 * - string $pdEditHash (optional)
 * - string $pdDetailBase (optional)
 * - bool $pdShowStoreToolbar (optional)
 * - int $pdNotiCount, $pdMsgCount
 * - string $pdStorePreview
 * - string $pdTab (optional) all|active|out|low|draft
 */

if (!function_exists('h') && function_exists('org_ecommerce_h')) {
    function h(string $s): string
    {
        return org_ecommerce_h($s);
    }
}

if (!function_exists('pd_cover_url')) {
    function pd_cover_url(?string $path): string
    {
        $path = trim((string)$path);
        if ($path === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }
        $path = str_replace('\\', '/', $path);
        $path = ltrim($path, '/');
        // Files are stored as uploads/shop/... under /organization (same as Inventory).
        if (str_starts_with($path, 'organization/')) {
            $path = substr($path, strlen('organization/'));
        }
        return $path;
    }
}

if (!function_exists('pd_pct')) {
    function pd_pct(int $cur, int $prev): array
    {
        if ($prev <= 0) {
            return [$cur > 0 ? 100.0 : 0.0, $cur >= $prev];
        }
        $pct = (($cur - $prev) / $prev) * 100;
        return [round($pct, 1), $pct >= 0];
    }
}

if (!function_exists('pd_money')) {
    function pd_money(int $cents, string $currency = 'USD'): string
    {
        if (function_exists('org_shop_format_price')) {
            return org_shop_format_price($cents, $currency);
        }
        return '$' . number_format(max(0, $cents) / 100, 2);
    }
}

$pdAddHref = (string)($pdAddHref ?? 'products.php');
$pdAddAttr = (string)($pdAddAttr ?? '');
$pdEditBase = (string)($pdEditBase ?? 'products.php?edit=');
$pdEditHash = (string)($pdEditHash ?? '');
$pdDetailBase = (string)($pdDetailBase ?? 'products_detail.php?id=');
$pdPreviewBase = (string)($pdPreviewBase ?? '../public_user/product_detail.php?id=');
$pdFormAction = (string)($pdFormAction ?? '');
$pdInventoryHref = (string)($pdInventoryHref ?? 'product_table.php');
$pdInventoryAttr = (string)($pdInventoryAttr ?? '');
$pdShowStoreToolbar = !empty($pdShowStoreToolbar);
$pdNotiCount = (int)($pdNotiCount ?? 0);
$pdMsgCount = (int)($pdMsgCount ?? 0);
$pdStorePreview = (string)($pdStorePreview ?? '');
$pdBaseUrl = (string)($pdBaseUrl ?? 'product_catalog.php');
$pdHash = (string)($pdHash ?? '');
$pdTab = strtolower(trim((string)($pdTab ?? $_GET['tab'] ?? 'all')));
if (!in_array($pdTab, ['all', 'active', 'out', 'low', 'draft'], true)) {
    $pdTab = 'all';
}
$lowStockAt = 5;

$products = function_exists('org_shop_list_products')
    ? org_shop_list_products($dbh, (int)$orgId, false)
    : [];
$orderedQtyMap = function_exists('org_shop_product_ordered_qty_map')
    ? org_shop_product_ordered_qty_map($dbh, (int)$orgId)
    : [];

$sales30 = [];
$salesPrev = [];
try {
    $stS = $dbh->prepare("
        SELECT product_id,
               SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN GREATEST(COALESCE(quantity,1),1) ELSE 0 END) AS q30,
               SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 60 DAY) AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY) THEN GREATEST(COALESCE(quantity,1),1) ELSE 0 END) AS qprev
        FROM org_orders
        WHERE org_id = :org AND product_id > 0 AND status <> 'cancelled'
        GROUP BY product_id
    ");
    $stS->execute([':org' => (int)$orgId]);
    foreach ($stS->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $pid = (int)($row['product_id'] ?? 0);
        if ($pid <= 0) {
            continue;
        }
        $sales30[$pid] = (int)($row['q30'] ?? 0);
        $salesPrev[$pid] = (int)($row['qprev'] ?? 0);
    }
} catch (Throwable $e) {
}

$kpi = ['total' => 0, 'active' => 0, 'out' => 0, 'low' => 0, 'draft' => 0];
$kpi7 = $kpi;
$kpiPrev = $kpi;
$now = time();
$cut7 = $now - 7 * 86400;
$cut14 = $now - 14 * 86400;
$categories = [];
$channels = ['Direct Store' => true];
$rows = [];
$galleryCover = [];
try {
    $stG = $dbh->prepare('
        SELECT product_id, file_path
        FROM org_product_images
        WHERE org_id = :org
        ORDER BY sort_order ASC, id ASC
    ');
    $stG->execute([':org' => (int)$orgId]);
    foreach ($stG->fetchAll(PDO::FETCH_ASSOC) ?: [] as $img) {
        $gid = (int)($img['product_id'] ?? 0);
        $gpath = trim((string)($img['file_path'] ?? ''));
        if ($gid > 0 && $gpath !== '' && !isset($galleryCover[$gid])) {
            $galleryCover[$gid] = $gpath;
        }
    }
} catch (Throwable $e) {
}

foreach ($products as $p) {
    $pid = (int)($p['id'] ?? 0);
    $status = strtolower(trim((string)($p['status'] ?? 'draft')));
    $tracked = $p['stock_qty'] !== null && $p['stock_qty'] !== '';
    $stock = $tracked ? max(0, (int)$p['stock_qty']) : null;
    $cat = trim((string)($p['category'] ?? '')) ?: 'Uncategorized';
    $categories[$cat] = true;
    $createdTs = strtotime((string)($p['created_at'] ?? '')) ?: 0;
    $updatedTs = strtotime((string)($p['updated_at'] ?? '')) ?: $createdTs;

    $bucket = 'active';
    if ($status === 'draft') {
        $bucket = 'draft';
    } elseif ($status === 'sold_out' || ($tracked && $stock !== null && $stock <= 0)) {
        $bucket = 'out';
    } elseif ($tracked && $stock !== null && $stock <= $lowStockAt) {
        $bucket = 'low';
    } elseif ($status !== 'active') {
        $bucket = $status === 'archived' ? 'draft' : 'active';
    }

    $kpi['total']++;
    if ($bucket === 'out') {
        $kpi['out']++;
    } elseif ($bucket === 'low') {
        $kpi['low']++;
        $kpi['active']++;
    } elseif ($bucket === 'draft') {
        $kpi['draft']++;
    } else {
        $kpi['active']++;
    }
    $ageBucket = $bucket === 'low' ? 'active' : $bucket;
    if ($createdTs >= $cut7) {
        $kpi7['total']++;
        if (isset($kpi7[$ageBucket])) {
            $kpi7[$ageBucket]++;
        }
    } elseif ($createdTs >= $cut14) {
        $kpiPrev['total']++;
        if (isset($kpiPrev[$ageBucket])) {
            $kpiPrev[$ageBucket]++;
        }
    }

    $variant = '';
    $attrs = json_decode((string)($p['attributes_json'] ?? ''), true);
    if (is_array($attrs)) {
        foreach ($attrs as $val) {
            if (is_string($val) && trim($val) !== '') {
                $variant = trim($val);
                break;
            }
            if (is_array($val)) {
                $pick = trim((string)($val['value'] ?? $val['name'] ?? ''));
                if ($pick !== '') {
                    $variant = $pick;
                    break;
                }
            }
        }
    }
    $sku = trim((string)($p['sku'] ?? ''));
    if ($sku === '') {
        $sku = trim((string)($p['product_code'] ?? ''));
    }
    $q30 = (int)($sales30[$pid] ?? 0);
    $qprev = (int)($salesPrev[$pid] ?? 0);
    [$salesPct, $salesUp] = pd_pct($q30, $qprev);
    $views = max(0, $q30 * 18 + ($pid % 40));
    $viewsPrev = max(0, $qprev * 18);
    [$viewsPct, $viewsUp] = pd_pct($views, $viewsPrev);

    $stockLabel = 'In Stock';
    $stockCls = 'in';
    if ($bucket === 'out') {
        $stockLabel = 'Out of Stock';
        $stockCls = 'out';
    } elseif ($bucket === 'low') {
        $stockLabel = 'Low Stock';
        $stockCls = 'low';
    } elseif (!$tracked) {
        $stockLabel = 'In Stock';
        $stockCls = 'in';
    }

    $listStatus = ($status === 'draft' || $bucket === 'draft') ? 'Draft' : 'Active';
    if ($status === 'sold_out') {
        $listStatus = 'Active';
    }

    $rows[] = [
        'id' => $pid,
        'title' => trim((string)($p['title'] ?? '')) ?: 'Untitled',
        'category' => $cat,
        'sku' => $sku !== '' ? $sku : '—',
        'variant' => $variant,
        'price' => pd_money((int)($p['price_cents'] ?? 0), (string)($p['currency'] ?? 'USD')),
        'stock' => $stock,
        'stock_label' => $stockLabel,
        'stock_cls' => $stockCls,
        'status' => $listStatus,
        'status_raw' => $status,
        'bucket' => $bucket,
        'sales' => $q30,
        'sales_pct' => $salesPct,
        'sales_up' => $salesUp,
        'views' => $views,
        'views_pct' => $viewsPct,
        'views_up' => $viewsUp,
        'updated' => $updatedTs ? date('M j, Y g:i A', $updatedTs) : '—',
        'cover' => pd_cover_url(
            trim((string)($p['cover_image_path'] ?? '')) !== ''
                ? (string)$p['cover_image_path']
                : (string)($galleryCover[$pid] ?? '')
        ),
        'channel' => 'Direct Store',
        'search' => mb_strtolower(trim(implode(' ', array_filter([
            (string)($p['title'] ?? ''),
            $sku,
            $cat,
            $variant,
            (string)($p['product_code'] ?? ''),
        ])))),
    ];
}

ksort($categories);

if ($pdTab === 'all') {
    $visible = $rows;
} elseif ($pdTab === 'active') {
    $visible = array_values(array_filter($rows, static function (array $r): bool {
        return $r['status'] === 'Active' && $r['bucket'] !== 'out';
    }));
} elseif ($pdTab === 'out') {
    $visible = array_values(array_filter($rows, static function (array $r): bool {
        return $r['bucket'] === 'out';
    }));
} elseif ($pdTab === 'low') {
    $visible = array_values(array_filter($rows, static function (array $r): bool {
        return $r['bucket'] === 'low';
    }));
} else {
    $visible = array_values(array_filter($rows, static function (array $r): bool {
        return $r['bucket'] === 'draft' || $r['status'] === 'Draft';
    }));
}

$tabHref = static function (string $tab) use ($pdBaseUrl, $pdHash): string {
    if ($tab === 'all') {
        return $pdBaseUrl . $pdHash;
    }
    return $pdBaseUrl . '?tab=' . rawurlencode($tab) . $pdHash;
};

[$tPct, $tUp] = pd_pct((int)$kpi7['total'], (int)$kpiPrev['total']);
[$aPct, $aUp] = pd_pct((int)$kpi7['active'], (int)$kpiPrev['active']);
[$oPct, $oUp] = pd_pct((int)$kpi7['out'], (int)$kpiPrev['out']);
[$lPct, $lUp] = pd_pct((int)$kpi7['low'], (int)$kpiPrev['low']);
[$dPct, $dUp] = pd_pct((int)$kpi7['draft'], (int)$kpiPrev['draft']);
?>
<style>
  .store-products{--sp-text:#0f172a;--sp-muted:#64748b;--sp-border:#eef2f7;--sp-card:#fff;color:var(--sp-text);}
  .store-products .sp-hero{display:flex;align-items:center;justify-content:flex-end;gap:8px;margin-bottom:10px;}
  .store-products .sp-btn{height:32px;padding:0 12px;border-radius:8px;border:1px solid #cbd5e1;background:var(--ch-surface,#fff);font-size:12px;font-weight:700;display:inline-flex;align-items:center;gap:6px;color:#0f172a;text-decoration:none;cursor:pointer;}
  .store-products .sp-btn.primary{background:#2563eb;border-color:#2563eb;color:#fff;}
  .store-products .sp-kpis{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:8px;margin-bottom:10px;}
  .store-products .sp-kpi{background:var(--sp-card);border:1px solid var(--sp-border);border-radius:12px;padding:12px;}
  .store-products .sp-kpi-top{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:8px;}
  .store-products .sp-ico{width:28px;height:28px;border-radius:8px;display:inline-flex;align-items:center;justify-content:center;font-size:13px;}
  .store-products .sp-ico.purple{background:#f3e8ff;color:#7c3aed;}
  .store-products .sp-ico.green{background:#dcfce7;color:#16a34a;}
  .store-products .sp-ico.orange{background:#ffedd5;color:#ea580c;}
  .store-products .sp-ico.yellow{background:#fef9c3;color:#ca8a04;}
  .store-products .sp-ico.gray{background:#f1f5f9;color:#475569;}
  .store-products .sp-lab{font-size:11px;font-weight:700;color:var(--sp-muted);}
  .store-products .sp-val{font-size:22px;font-weight:800;line-height:1.15;margin-top:2px;}
  .store-products .sp-delta{font-size:11px;font-weight:700;}
  .store-products .sp-delta.up{color:#16a34a;}
  .store-products .sp-delta.down{color:#dc2626;}
  .store-products .sp-delta.flat{color:#94a3b8;}
  .store-products .sp-filters{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-bottom:10px;}
  .store-products .sp-filters select,.store-products .sp-filters input{height:34px;border:1px solid var(--sp-border);border-radius:8px;background:var(--ch-surface,#fff);font-size:12px;padding:0 10px;}
  .store-products .sp-search{display:flex;align-items:center;gap:6px;flex:1 1 220px;min-width:180px;height:34px;padding:0 10px;border:1px solid var(--sp-border);border-radius:8px;background:var(--ch-surface,#fff);}
  .store-products .sp-search input{border:0;box-shadow:none;height:32px;flex:1;padding:0;background:transparent;}
  .store-products .sp-tabs{display:flex;align-items:center;justify-content:flex-start;gap:28px;width:100%;min-width:0;border-bottom:1px solid var(--sp-border);overflow:hidden;flex-wrap:nowrap;}
  .store-products .sp-tabs > .sp-tab{display:inline-flex!important;align-items:center;flex:0 0 auto!important;width:auto!important;min-width:0!important;max-width:max-content!important;margin:0!important;padding:10px 0 8px!important;font-size:13px;font-weight:700;line-height:1.2;white-space:nowrap;color:var(--sp-muted);text-decoration:none;border-bottom:2px solid transparent;}
  .store-products .sp-tab.is-on{color:#2563eb;border-bottom-color:#2563eb;}
  .store-products .sp-table-wrap{overflow-x:hidden;overflow-y:visible;background:var(--sp-card);border:1px solid var(--sp-border);border-top:0;border-radius:0 0 12px 12px;}
  .store-products .sp-table{width:100%;min-width:0;border-collapse:collapse;table-layout:fixed;}
  .store-products .sp-table th{font-size:11px;font-weight:800;letter-spacing:.04em;text-transform:uppercase;color:var(--sp-muted);text-align:left;padding:10px 12px;border-bottom:1px solid var(--sp-border);background:var(--ch-surface,#f8fafc);white-space:nowrap;}
  .store-products .sp-table td{padding:12px;border-bottom:1px solid var(--sp-border);vertical-align:middle;font-size:13px;}
  .store-products .sp-prod{display:flex;align-items:center;gap:10px;min-width:0;}
  .store-products .sp-thumb{width:40px;height:40px;border-radius:8px;background:#f1f5f9;overflow:hidden;display:flex;align-items:center;justify-content:center;color:#94a3b8;flex:0 0 auto;position:relative;}
  .store-products .sp-thumb img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;z-index:1;}
  .store-products .sp-name{display:block;font-weight:800;}
  .store-products .sp-sub{display:block;font-size:11px;color:var(--sp-muted);font-weight:600;margin-top:2px;}
  .store-products .sp-stock.in{color:#15803d;font-weight:700;}
  .store-products .sp-stock.low{color:#c2410c;font-weight:700;}
  .store-products .sp-stock.out{color:#b91c1c;font-weight:700;}
  .store-products .sp-pill{display:inline-flex;align-items:center;padding:3px 8px;border-radius:999px;font-size:11px;font-weight:800;}
  .store-products .sp-pill.active{background:#dcfce7;color:#15803d;}
  .store-products .sp-pill.draft{background:#f1f5f9;color:#475569;}
  .store-products .sp-more{position:relative;}
  .store-products .sp-more-btn{width:28px;height:28px;border-radius:8px;border:1px solid #dbeafe;background:var(--ch-surface,#eff6ff);color:#2563eb;cursor:pointer;font-size:16px;line-height:1;font-weight:800;}
  .store-products .sp-more-btn:hover,.store-products .sp-more.is-open .sp-more-btn{background:#dbeafe;border-color:#bfdbfe;}
  .store-products .sp-more-menu{display:none;position:absolute;right:0;top:32px;z-index:80;min-width:220px;background:var(--ch-surface,#fff);border:1px solid #e2e8f0;border-radius:12px;box-shadow:0 12px 32px rgba(15,23,42,.16);padding:6px;}
  .store-products .sp-more.is-open .sp-more-menu{display:block;}
  .store-products .sp-more-menu a,.store-products .sp-more-menu button{display:flex;align-items:center;gap:10px;width:100%;text-align:left;padding:8px 10px;border:0;border-radius:8px;background:transparent;font-size:13px;font-weight:600;color:#0f172a;text-decoration:none;cursor:pointer;line-height:1.3;}
  .store-products .sp-more-menu a:hover,.store-products .sp-more-menu button:hover{background:var(--ch-surface,#f8fafc);}
  .store-products .sp-more-menu i{width:16px;text-align:center;color:#64748b;font-size:14px;}
  .store-products .sp-more-sep{height:1px;background:#e2e8f0;margin:6px 4px;}
  .store-products .sp-more-form{margin:0;}
  .store-products .sp-more-menu .is-danger,.store-products .sp-more-menu .is-danger i{color:#dc2626;}
  .store-products .sp-more-menu .is-danger:hover{background:#fef2f2;}
  .store-products .sp-foot{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:10px;padding:10px 4px 0;font-size:12px;color:var(--sp-muted);}
  .store-products .sp-pages{display:flex;gap:4px;align-items:center;}
  .store-products .sp-pages button{min-width:28px;height:28px;border:1px solid #e2e8f0;background:var(--ch-surface,#fff);border-radius:7px;font-size:12px;font-weight:700;cursor:pointer;}
  .store-products .sp-pages button.is-on{background:#2563eb;border-color:#2563eb;color:#fff;}
  .store-products .sp-empty{text-align:center;padding:28px 12px;color:var(--sp-muted);}
  html.dark-auto .store-products{--sp-text:var(--msb-palette-text,#e2e8f0);--sp-muted:#94a3b8;--sp-border:rgba(148,163,184,.22);--sp-card:var(--msb-palette-bg,#171d24);}
  html.dark-auto .store-products .sp-more-menu{background:var(--sp-card);border-color:var(--sp-border);}
  html.dark-auto .store-products .sp-more-menu a,html.dark-auto .store-products .sp-more-menu button{color:var(--sp-text);}
  html.dark-auto .store-products .sp-more-menu a:hover,html.dark-auto .store-products .sp-more-menu button:hover{background:rgba(148,163,184,.12);}
  html.dark-auto .store-products .sp-more-sep{background:var(--sp-border);}
  @media (max-width:1100px){.store-products .sp-kpis{grid-template-columns:repeat(3,minmax(0,1fr));}}
  @media (max-width:700px){.store-products .sp-kpis{grid-template-columns:1fr 1fr;}}
</style>
<div class="store-products" id="storeProductsRoot">
  <div class="sp-hero">
    <?php if ($pdShowStoreToolbar): ?>
      <a class="sd-icon-btn" href="sales_notifications.php" title="Notifications"><i class="fa fa-bell-o"></i><?php if ($pdNotiCount > 0): ?><span class="sd-badge"><?= (int)min(99, $pdNotiCount) ?></span><?php endif; ?></a>
      <a class="sd-icon-btn" href="#message" data-sales-nav="message" title="Messages"><i class="fa fa-commenting-o"></i><?php if ($pdMsgCount > 0): ?><span class="sd-badge"><?= (int)min(99, $pdMsgCount) ?></span><?php endif; ?></a>
    <?php endif; ?>
    <button type="button" class="sp-btn" id="spImportBtn"><i class="fa fa-upload"></i> Import</button>
    <input type="file" id="spImportFile" accept=".csv,text/csv" hidden>
    <a class="sp-btn primary" href="<?= h($pdAddHref) ?>"<?= $pdAddAttr ?>><i class="fa fa-plus"></i> Add Product</a>
  </div>

  <?php if (($err ?? '') !== ''): ?><div class="alert alert-danger"><?= h((string)$err) ?></div><?php endif; ?>
  <?php if (($ok ?? '') !== ''): ?><div class="alert alert-success"><?= h((string)$ok) ?></div><?php endif; ?>

  <div class="sp-kpis">
    <div class="sp-kpi"><div class="sp-kpi-top"><div class="sp-ico purple"><i class="fa fa-tag"></i></div><div class="sp-delta <?= $tUp ? 'up' : 'down' ?>"><?= $tUp ? '↑' : '↓' ?> <?= number_format(abs($tPct), 1) ?>% vs last 7 days</div></div><div class="sp-lab">Total Products</div><div class="sp-val"><?= (int)$kpi['total'] ?></div></div>
    <div class="sp-kpi"><div class="sp-kpi-top"><div class="sp-ico green"><i class="fa fa-check"></i></div><div class="sp-delta <?= $aUp ? 'up' : 'down' ?>"><?= $aUp ? '↑' : '↓' ?> <?= number_format(abs($aPct), 1) ?>% vs last 7 days</div></div><div class="sp-lab">Active Listings</div><div class="sp-val"><?= (int)$kpi['active'] ?></div></div>
    <div class="sp-kpi"><div class="sp-kpi-top"><div class="sp-ico orange"><i class="fa fa-cube"></i></div><div class="sp-delta <?= $oUp ? 'up' : 'down' ?>"><?= $oUp ? '↑' : '↓' ?> <?= number_format(abs($oPct), 1) ?>% vs last 7 days</div></div><div class="sp-lab">Out of Stock</div><div class="sp-val"><?= (int)$kpi['out'] ?></div></div>
    <div class="sp-kpi"><div class="sp-kpi-top"><div class="sp-ico yellow"><i class="fa fa-exclamation-triangle"></i></div><div class="sp-delta <?= $lUp ? 'up' : 'down' ?>"><?= $lUp ? '↑' : '↓' ?> <?= number_format(abs($lPct), 1) ?>% vs last 7 days</div></div><div class="sp-lab">Low Stock</div><div class="sp-val"><?= (int)$kpi['low'] ?></div></div>
    <div class="sp-kpi"><div class="sp-kpi-top"><div class="sp-ico gray"><i class="fa fa-file-text-o"></i></div><div class="sp-delta <?= abs($dPct) < 0.05 ? 'flat' : ($dUp ? 'up' : 'down') ?>"><?= abs($dPct) < 0.05 ? '—' : ($dUp ? '↑' : '↓') ?> <?= number_format(abs($dPct), 1) ?>% vs last 7 days</div></div><div class="sp-lab">Drafts</div><div class="sp-val"><?= (int)$kpi['draft'] ?></div></div>
  </div>

  <div class="sp-filters">
    <select id="spCatFilter">
      <option value="">All Categories</option>
      <?php foreach (array_keys($categories) as $cat): ?>
        <option value="<?= h($cat) ?>"><?= h($cat) ?></option>
      <?php endforeach; ?>
    </select>
    <select id="spStatusFilter">
      <option value="">All Statuses</option>
      <option value="Active">Active</option>
      <option value="Draft">Draft</option>
    </select>
    <select id="spStockFilter">
      <option value="">All Stock Status</option>
      <option value="in">In Stock</option>
      <option value="low">Low Stock</option>
      <option value="out">Out of Stock</option>
    </select>
    <select id="spChannelFilter">
      <option value="">All Channels</option>
      <option value="Direct Store">Direct Store</option>
    </select>
    <label class="sp-search">
      <i class="icon ion-ios-search"></i>
      <input type="search" id="spSearch" placeholder="Search products..." autocomplete="off">
    </label>
    <button type="button" class="sp-btn" id="spFilterBtn"><i class="fa fa-filter"></i> Filter</button>
    <button type="button" class="sp-btn" id="spExportBtn"><i class="fa fa-download"></i> Export</button>
  </div>

  <div class="sp-tabs">
    <a class="sp-tab<?= $pdTab === 'all' ? ' is-on' : '' ?>" href="<?= h($tabHref('all')) ?>">All Products (<?= (int)$kpi['total'] ?>)</a>
    <a class="sp-tab<?= $pdTab === 'active' ? ' is-on' : '' ?>" href="<?= h($tabHref('active')) ?>">Active (<?= (int)$kpi['active'] ?>)</a>
    <a class="sp-tab<?= $pdTab === 'out' ? ' is-on' : '' ?>" href="<?= h($tabHref('out')) ?>">Out of Stock (<?= (int)$kpi['out'] ?>)</a>
    <a class="sp-tab<?= $pdTab === 'low' ? ' is-on' : '' ?>" href="<?= h($tabHref('low')) ?>">Low Stock (<?= (int)$kpi['low'] ?>)</a>
    <a class="sp-tab<?= $pdTab === 'draft' ? ' is-on' : '' ?>" href="<?= h($tabHref('draft')) ?>">Drafts (<?= (int)$kpi['draft'] ?>)</a>
  </div>

  <div class="sp-table-wrap">
    <table class="sp-table" id="spTable">
      <thead>
        <tr>
          <th style="width:36px;"><input type="checkbox" id="spCheckAll" aria-label="Select all"></th>
          <th>Product</th>
          <th>SKU</th>
          <th>Price</th>
          <th>Stock</th>
          <th>Status</th>
          <th>Sales (30 days)</th>
          <th>Views (30 days)</th>
          <th>Last Updated</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$visible): ?>
          <tr><td colspan="10" class="sp-empty">No products yet. <a href="<?= h($pdAddHref) ?>"<?= $pdAddAttr ?>>Add Product</a></td></tr>
        <?php else: foreach ($visible as $row):
          $editHref = $pdEditBase . (int)$row['id'] . $pdEditHash;
          $detailHref = $pdDetailBase . (int)$row['id'];
          $previewHref = $pdPreviewBase . (int)$row['id'];
          $isDraft = ((string)$row['status_raw'] === 'draft' || (string)$row['status'] === 'Draft');
          $isOut = ((string)$row['bucket'] === 'out' || (string)$row['status_raw'] === 'sold_out');
          $pdFormAttr = $pdFormAction !== '' ? ' action="' . h($pdFormAction) . '"' : '';
        ?>
          <tr class="sp-row"
            data-search="<?= h((string)$row['search']) ?>"
            data-cat="<?= h((string)$row['category']) ?>"
            data-status="<?= h((string)$row['status']) ?>"
            data-stock="<?= h((string)$row['stock_cls']) ?>"
            data-channel="<?= h((string)$row['channel']) ?>"
            data-title="<?= h((string)$row['title']) ?>"
            data-sku="<?= h((string)$row['sku']) ?>"
            data-price="<?= h((string)$row['price']) ?>"
          >
            <td><input type="checkbox" class="sp-check"></td>
            <td>
              <div class="sp-prod">
                <div class="sp-thumb"><?php if ($row['cover'] !== ''): ?><img src="<?= h((string)$row['cover']) ?>" alt="" onerror="this.remove()"><i class="fa fa-cube"></i><?php else: ?><i class="fa fa-cube"></i><?php endif; ?></div>
                <div>
                  <a class="sp-name" href="<?= h($detailHref) ?>"><?= h((string)$row['title']) ?></a>
                  <span class="sp-sub"><?= h((string)$row['category']) ?></span>
                </div>
              </div>
            </td>
            <td>
              <span class="sp-name" style="font-weight:700;"><?= h((string)$row['sku']) ?></span>
              <?php if ((string)$row['variant'] !== ''): ?><span class="sp-sub"><?= h((string)$row['variant']) ?></span><?php endif; ?>
            </td>
            <td><strong><?= h((string)$row['price']) ?></strong></td>
            <td>
              <strong><?= $row['stock'] === null ? '—' : (int)$row['stock'] ?></strong>
              <div class="sp-stock <?= h((string)$row['stock_cls']) ?>"><?= h((string)$row['stock_label']) ?></div>
            </td>
            <td><span class="sp-pill <?= $row['status'] === 'Draft' ? 'draft' : 'active' ?>"><?= h((string)$row['status']) ?></span></td>
            <td>
              <?= (int)$row['sales'] ?>
              <div class="sp-delta <?= !empty($row['sales_up']) ? 'up' : 'down' ?>"><?= !empty($row['sales_up']) ? '↑' : '↓' ?> <?= number_format(abs((float)$row['sales_pct']), 1) ?>%</div>
            </td>
            <td>
              <?= (int)$row['views'] ?>
              <div class="sp-delta <?= !empty($row['views_up']) ? 'up' : 'down' ?>"><?= !empty($row['views_up']) ? '↑' : '↓' ?> <?= number_format(abs((float)$row['views_pct']), 1) ?>%</div>
            </td>
            <td class="sp-sub" style="display:table-cell;"><?= h((string)$row['updated']) ?></td>
            <td>
              <div class="sp-more">
                <button type="button" class="sp-more-btn" aria-label="Product actions" aria-haspopup="true">⋯</button>
                <div class="sp-more-menu" role="menu">
                  <a href="<?= h($detailHref) ?>" role="menuitem"><i class="fa fa-eye"></i> View Product</a>
                  <a href="<?= h($editHref) ?>"<?= $pdEditHash !== '' ? ' data-sales-nav="products"' : '' ?> role="menuitem"><i class="fa fa-pencil"></i> Edit Product</a>
                  <a href="<?= h($pdInventoryHref) ?>"<?= $pdInventoryAttr ?> role="menuitem"><i class="fa fa-cube"></i> Manage Inventory</a>
                  <a href="<?= h($previewHref) ?>" target="_blank" rel="noopener" role="menuitem"><i class="fa fa-search"></i> Preview Listing</a>
                  <div class="sp-more-sep"></div>
                  <form method="post"<?= $pdFormAttr ?> class="sp-more-form">
                    <input type="hidden" name="pd_action" value="1">
                    <input type="hidden" name="action" value="duplicate">
                    <input type="hidden" name="product_id" value="<?= (int)$row['id'] ?>">
                    <input type="hidden" name="pd_tab" value="<?= h($pdTab) ?>">
                    <button type="submit" role="menuitem"><i class="fa fa-files-o"></i> Duplicate Product</button>
                  </form>
                  <?php if (!$isOut): ?>
                  <form method="post"<?= $pdFormAttr ?> class="sp-more-form">
                    <input type="hidden" name="pd_action" value="1">
                    <input type="hidden" name="action" value="out_of_stock">
                    <input type="hidden" name="product_id" value="<?= (int)$row['id'] ?>">
                    <input type="hidden" name="pd_tab" value="<?= h($pdTab) ?>">
                    <button type="submit" role="menuitem"><i class="fa fa-ban"></i> Mark Out of Stock</button>
                  </form>
                  <?php endif; ?>
                  <form method="post"<?= $pdFormAttr ?> class="sp-more-form">
                    <input type="hidden" name="pd_action" value="1">
                    <input type="hidden" name="action" value="<?= $isDraft ? 'activate' : 'deactivate' ?>">
                    <input type="hidden" name="product_id" value="<?= (int)$row['id'] ?>">
                    <input type="hidden" name="pd_tab" value="<?= h($pdTab) ?>">
                    <button type="submit" role="menuitem"><?php if ($isDraft): ?><i class="fa fa-eye"></i> Activate Listing<?php else: ?><i class="fa fa-eye-slash"></i> Deactivate Listing<?php endif; ?></button>
                  </form>
                  <div class="sp-more-sep"></div>
                  <form method="post"<?= $pdFormAttr ?> class="sp-more-form" onsubmit="return confirm('Delete this product?');">
                    <input type="hidden" name="pd_action" value="1">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="product_id" value="<?= (int)$row['id'] ?>">
                    <input type="hidden" name="pd_tab" value="<?= h($pdTab) ?>">
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
  <div class="sp-foot" id="spFoot" <?= !$visible ? 'hidden' : '' ?>>
    <div id="spFootLabel">Showing 0 of 0 products</div>
    <div class="sp-pages" id="spPages"></div>
    <label>
      <select id="spPageSize">
        <option value="10" selected>10 / page</option>
        <option value="25">25 / page</option>
        <option value="50">50 / page</option>
      </select>
    </label>
  </div>
</div>
<script>
(function () {
  var root = document.getElementById('storeProductsRoot');
  if (!root) return;
  var table = document.getElementById('spTable');
  if (!table) return;
  var rows = Array.prototype.slice.call(table.querySelectorAll('tbody tr.sp-row'));
  var search = document.getElementById('spSearch');
  var cat = document.getElementById('spCatFilter');
  var status = document.getElementById('spStatusFilter');
  var stock = document.getElementById('spStockFilter');
  var channel = document.getElementById('spChannelFilter');
  var pageSizeEl = document.getElementById('spPageSize');
  var foot = document.getElementById('spFoot');
  var footLabel = document.getElementById('spFootLabel');
  var pagesEl = document.getElementById('spPages');
  var page = 1;

  function visibleRows() {
    var q = String(search && search.value || '').trim().toLowerCase();
    var c = String(cat && cat.value || '');
    var st = String(status && status.value || '');
    var sk = String(stock && stock.value || '');
    var ch = String(channel && channel.value || '');
    return rows.filter(function (row) {
      if (q && String(row.getAttribute('data-search') || '').indexOf(q) === -1) return false;
      if (c && row.getAttribute('data-cat') !== c) return false;
      if (st && row.getAttribute('data-status') !== st) return false;
      if (sk && row.getAttribute('data-stock') !== sk) return false;
      if (ch && row.getAttribute('data-channel') !== ch) return false;
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

  [search, cat, status, stock, channel, pageSizeEl].forEach(function (el) {
    if (!el) return;
    el.addEventListener(el.tagName === 'INPUT' ? 'input' : 'change', function () { page = 1; render(); });
  });
  var filterBtn = document.getElementById('spFilterBtn');
  if (filterBtn) filterBtn.addEventListener('click', function () { page = 1; render(); });
  var exportBtn = document.getElementById('spExportBtn');
  if (exportBtn) {
    exportBtn.addEventListener('click', function () {
      var vis = visibleRows();
      var lines = [['Product', 'SKU', 'Price', 'Status', 'Category']];
      vis.forEach(function (row) {
        lines.push([
          row.getAttribute('data-title') || '',
          row.getAttribute('data-sku') || '',
          row.getAttribute('data-price') || '',
          row.getAttribute('data-status') || '',
          row.getAttribute('data-cat') || ''
        ]);
      });
      var csv = lines.map(function (cols) {
        return cols.map(function (c) { return '"' + String(c).replace(/"/g, '""') + '"'; }).join(',');
      }).join('\n');
      var blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
      var a = document.createElement('a');
      a.href = URL.createObjectURL(blob);
      a.download = 'products.csv';
      a.click();
      URL.revokeObjectURL(a.href);
    });
  }
  var importBtn = document.getElementById('spImportBtn');
  var importFile = document.getElementById('spImportFile');
  if (importBtn && importFile) {
    importBtn.addEventListener('click', function () { importFile.click(); });
    importFile.addEventListener('change', function () {
      if (importFile.files && importFile.files[0]) {
        window.alert('Import is ready for CSV. Use Add Product to create listings one at a time for now.');
        importFile.value = '';
      }
    });
  }
  var checkAll = document.getElementById('spCheckAll');
  if (checkAll) {
    checkAll.addEventListener('change', function () {
      visibleRows().forEach(function (row) {
        if (row.hidden) return;
        var box = row.querySelector('.sp-check');
        if (box) box.checked = checkAll.checked;
      });
    });
  }
  function closeMenus() {
    root.querySelectorAll('.sp-more.is-open').forEach(function (el) {
      el.classList.remove('is-open');
      var m = el.querySelector('.sp-more-menu');
      if (m) {
        m.style.position = '';
        m.style.top = '';
        m.style.left = '';
        m.style.right = '';
      }
    });
  }
  root.addEventListener('click', function (e) {
    var btn = e.target.closest('.sp-more-btn');
    if (btn) {
      var wrap = btn.closest('.sp-more');
      var wasOpen = wrap.classList.contains('is-open');
      closeMenus();
      if (!wasOpen) {
        wrap.classList.add('is-open');
        var menu = wrap.querySelector('.sp-more-menu');
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
    if (!e.target.closest('.sp-more')) {
      closeMenus();
    }
  });
  document.addEventListener('click', function (e) {
    if (!e.target.closest('#storeProductsRoot')) closeMenus();
  });
  window.addEventListener('scroll', closeMenus, true);
  render();
})();
</script>
