<?php
declare(strict_types=1);

/**
 * admin/inventory.php
 * Marketplace inventory oversight — all seller products, stock, brands.
 */
require_once __DIR__ . '/includes/org_admin_helpers_load.php';
require_once __DIR__ . '/../public_user/includes/org_shop.php';
require_once __DIR__ . '/../public_user/includes/org_commerce_brands.php';
require_once __DIR__ . '/includes/admin_linked_bootstrap_load.php';

org_admin_require_admin();

error_reporting(E_ALL);
ini_set('display_errors', '1');

$dbh = org_admin_db();
org_shop_ensure_schema($dbh);
org_commerce_brands_ensure_schema($dbh);
$inventoryAdminId = (int)($_SESSION['admin_id'] ?? 0);

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function fmt_dt($dt): string
{
    if (!$dt) {
        return '—';
    }
    $ts = strtotime((string)$dt);
    if (!$ts) {
        return (string)$dt;
    }
    return date('M j, Y g:i A', $ts);
}

function initials2(string $name): string
{
    $name = trim(preg_replace('/\s+/', ' ', $name) ?? $name);
    if ($name === '') {
        return '??';
    }
    $name = str_replace(['_', '.', '-', '@'], ' ', $name);
    $parts = array_values(array_filter(explode(' ', $name), static fn($p) => trim($p) !== ''));
    if (!$parts) {
        return '??';
    }
    $first = mb_strtoupper(mb_substr($parts[0], 0, 1));
    $second = count($parts) > 1
        ? mb_strtoupper(mb_substr($parts[count($parts) - 1], 0, 1))
        : mb_strtoupper(mb_substr($parts[0], 1, 1));
    $ini = trim($first . $second);
    return $ini !== '' ? $ini : '??';
}

function avatarColor(string $key): string
{
    $key = strtolower(trim($key));
    $hash = crc32($key);
    $palette = ['#2563eb', '#7c3aed', '#db2777', '#ea580c', '#16a34a', '#0f766e', '#0891b2', '#475569'];
    return $palette[$hash % count($palette)];
}

function money_fmt(int $cents, string $currency = 'USD'): string
{
    $currency = strtoupper(trim($currency)) ?: 'USD';
    return '$' . number_format(max(0, $cents) / 100, 2) . ' ' . $currency;
}

function truncate_text(string $s, int $n = 48): string
{
    $s = trim(preg_replace('/\s+/', ' ', $s) ?? $s);
    if ($s === '') {
        return '—';
    }
    if (mb_strlen($s) <= $n) {
        return $s;
    }
    return mb_substr($s, 0, $n - 1) . '…';
}

/** Stock bucket for filters/KPIs. */
function inv_stock_bucket(array $row): string
{
    $status = strtolower(trim((string)($row['status'] ?? 'draft')));
    if ($status === 'draft') {
        return 'draft';
    }
    if ($status === 'archived') {
        return 'archived';
    }
    if ($status === 'sold_out') {
        return 'sold_out';
    }
    $raw = $row['stock_qty'] ?? null;
    if ($raw === null || $raw === '') {
        return 'untracked';
    }
    $qty = (int)$raw;
    if ($qty <= 0) {
        return 'sold_out';
    }
    if ($qty < 5) {
        return 'low';
    }
    return 'in_stock';
}

$statusFilter = strtolower(trim((string)($_GET['status'] ?? 'all')));
$allowedStatus = ['all', 'active', 'draft', 'sold_out', 'archived', 'low', 'in_stock', 'untracked'];
if (!in_array($statusFilter, $allowedStatus, true)) {
    $statusFilter = 'all';
}

$sellerFilter = (int)($_GET['seller'] ?? 0);
$brandFilter = (int)($_GET['brand'] ?? 0);
$categoryFilter = strtolower(trim((string)($_GET['category'] ?? 'all')));
$fulfillFilter = strtolower(trim((string)($_GET['fulfillment'] ?? 'all')));
if (!in_array($fulfillFilter, ['all', 'fba', 'fbm'], true)) {
    $fulfillFilter = 'all';
}
$q = trim((string)($_GET['q'] ?? ''));
$detailId = (int)($_GET['id'] ?? 0);

$sql = "
    SELECT
        p.*,
        org.name AS org_name,
        org.org_code AS org_code,
        org.commerce_brand_id AS org_brand_id,
        org.publisher_user_id AS seller_user_id,
        cb.id AS brand_id,
        cb.name AS brand_name,
        cb.slug AS brand_slug,
        cb.icon_letter AS brand_letter,
        cb.accent_color AS brand_color,
        su.username AS seller_username,
        su.email AS seller_user_email,
        m.fullname AS manager_fullname,
        m.email AS manager_email,
        m.username AS manager_username
    FROM org_products p
    LEFT JOIN organizations org ON org.id = p.org_id
    LEFT JOIN commerce_brands cb ON cb.id = org.commerce_brand_id
    LEFT JOIN users su ON su.id = org.publisher_user_id
    LEFT JOIN managers m ON m.id = org.owner_manager_id
    WHERE COALESCE(p.is_deleted, 0) = 0
    ORDER BY p.updated_at DESC, p.id DESC
";

$allProducts = [];
$loadError = '';
try {
    $st = $dbh->query($sql);
    $allProducts = $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
} catch (Throwable $e) {
    $allProducts = [];
    $loadError = 'Could not load inventory.';
    try {
        $fallbackSql = "
            SELECT
                p.*,
                org.name AS org_name,
                org.org_code AS org_code,
                org.commerce_brand_id AS org_brand_id,
                org.publisher_user_id AS seller_user_id,
                cb.id AS brand_id,
                cb.name AS brand_name,
                cb.slug AS brand_slug,
                cb.icon_letter AS brand_letter,
                cb.accent_color AS brand_color,
                su.username AS seller_username,
                su.email AS seller_user_email,
                NULL AS manager_fullname,
                NULL AS manager_email,
                NULL AS manager_username
            FROM org_products p
            LEFT JOIN organizations org ON org.id = p.org_id
            LEFT JOIN commerce_brands cb ON cb.id = org.commerce_brand_id
            LEFT JOIN users su ON su.id = org.publisher_user_id
            WHERE COALESCE(p.is_deleted, 0) = 0
            ORDER BY p.id DESC
        ";
        $st = $dbh->query($fallbackSql);
        $allProducts = $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        if ($allProducts) {
            $loadError = '';
        }
    } catch (Throwable $e2) {
        $loadError = 'Could not load inventory from the database.';
    }
}

$sellers = [];
$brands = [];
$categories = [];
$counts = [
    'all' => 0,
    'active' => 0,
    'draft' => 0,
    'sold_out' => 0,
    'archived' => 0,
    'low' => 0,
    'in_stock' => 0,
    'untracked' => 0,
];
$kpiTotal = 0;
$kpiActive = 0;
$kpiLow = 0;
$kpiSoldOut = 0;
$kpiDraft = 0;
$unitsTracked = 0;

foreach ($allProducts as $row) {
    $kpiTotal++;
    $counts['all']++;
    $dbStatus = strtolower(trim((string)($row['status'] ?? 'draft')));
    if (isset($counts[$dbStatus])) {
        $counts[$dbStatus]++;
    }
    $raw = $row['stock_qty'] ?? null;
    if ($raw !== null && $raw !== '') {
        $unitsTracked += max(0, (int)$raw);
    }

    $oid = (int)($row['org_id'] ?? 0);
    if ($oid > 0) {
        $sellers[$oid] = trim((string)($row['brand_name'] ?? '')) !== ''
            ? (string)$row['brand_name']
            : (string)($row['org_name'] ?? ('Org #' . $oid));
    }
    $bid = (int)($row['brand_id'] ?? 0);
    if ($bid > 0) {
        $brands[$bid] = (string)($row['brand_name'] ?? ('Brand #' . $bid));
    }
    $cat = strtolower(trim((string)($row['category'] ?? '')));
    if ($cat !== '') {
        $categories[$cat] = true;
    }
}
asort($sellers);
asort($brands);
ksort($categories);

$counts['low'] = 0;
$counts['in_stock'] = 0;
$counts['untracked'] = 0;
$kpiActive = (int)$counts['active'];
$kpiDraft = (int)$counts['draft'];
$kpiSoldOut = 0;
$kpiLow = 0;
foreach ($allProducts as $row) {
    $bucket = inv_stock_bucket($row);
    if ($bucket === 'low') {
        $counts['low']++;
        $kpiLow++;
    } elseif ($bucket === 'in_stock') {
        $counts['in_stock']++;
    } elseif ($bucket === 'untracked') {
        $counts['untracked']++;
    } elseif ($bucket === 'sold_out') {
        $kpiSoldOut++;
    }
}
$counts['sold_out'] = $kpiSoldOut;

$products = [];
foreach ($allProducts as $row) {
    $dbStatus = strtolower(trim((string)($row['status'] ?? 'draft')));
    $bucket = inv_stock_bucket($row);

    if ($statusFilter === 'active' && $dbStatus !== 'active') {
        continue;
    }
    if ($statusFilter === 'draft' && $dbStatus !== 'draft') {
        continue;
    }
    if ($statusFilter === 'archived' && $dbStatus !== 'archived') {
        continue;
    }
    if ($statusFilter === 'sold_out' && $bucket !== 'sold_out') {
        continue;
    }
    if ($statusFilter === 'low' && $bucket !== 'low') {
        continue;
    }
    if ($statusFilter === 'in_stock' && $bucket !== 'in_stock') {
        continue;
    }
    if ($statusFilter === 'untracked' && $bucket !== 'untracked') {
        continue;
    }
    if ($sellerFilter > 0 && (int)($row['org_id'] ?? 0) !== $sellerFilter) {
        continue;
    }
    if ($brandFilter > 0 && (int)($row['brand_id'] ?? 0) !== $brandFilter) {
        continue;
    }
    $cat = strtolower(trim((string)($row['category'] ?? '')));
    if ($categoryFilter !== 'all' && $cat !== $categoryFilter) {
        continue;
    }
    $ff = strtolower(trim((string)($row['fulfillment_method'] ?? 'fbm')));
    if ($fulfillFilter !== 'all' && $ff !== $fulfillFilter) {
        continue;
    }
    if ($q !== '') {
        $hay = mb_strtolower(implode(' ', [
            (string)($row['id'] ?? ''),
            (string)($row['title'] ?? ''),
            (string)($row['sku'] ?? ''),
            (string)($row['product_code'] ?? ''),
            (string)($row['org_name'] ?? ''),
            (string)($row['brand_name'] ?? ''),
            (string)($row['seller_username'] ?? ''),
            (string)($row['manager_fullname'] ?? ''),
            (string)($row['category'] ?? ''),
        ]));
        if (mb_strpos($hay, mb_strtolower($q)) === false) {
            continue;
        }
    }
    $row['_stock_bucket'] = $bucket;
    $products[] = $row;
}

$detailProduct = null;
if ($detailId > 0) {
    foreach ($allProducts as $row) {
        if ((int)($row['id'] ?? 0) === $detailId) {
            $row['_stock_bucket'] = inv_stock_bucket($row);
            $detailProduct = $row;
            break;
        }
    }
}

$href = static function (array $overrides = []) use (
    $statusFilter,
    $sellerFilter,
    $brandFilter,
    $categoryFilter,
    $fulfillFilter,
    $q
): string {
    $params = array_merge([
        'status' => $statusFilter,
        'seller' => $sellerFilter > 0 ? $sellerFilter : '',
        'brand' => $brandFilter > 0 ? $brandFilter : '',
        'category' => $categoryFilter,
        'fulfillment' => $fulfillFilter,
        'q' => $q,
    ], $overrides);
    foreach ($params as $k => $v) {
        if ($v === '' || $v === null || $v === 0 || $v === '0' || $v === 'all') {
            unset($params[$k]);
        }
    }
    return 'inventory.php' . ($params ? ('?' . http_build_query($params)) : '');
};

$statusLabels = [
    'active' => 'Active',
    'draft' => 'Draft',
    'sold_out' => 'Sold Out',
    'archived' => 'Archived',
    'low' => 'Low Stock',
    'in_stock' => 'In Stock',
    'untracked' => 'Untracked',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
  <title>Inventory</title>
  <link href="../lib/font-awesome/css/font-awesome.css" rel="stylesheet">
  <link href="../lib/Ionicons/css/ionicons.css" rel="stylesheet">
  <link href="../lib/perfect-scrollbar/css/perfect-scrollbar.css" rel="stylesheet">
  <link href="../lib/datatables/jquery.dataTables.css" rel="stylesheet">
  <link rel="stylesheet" href="../css/shamcey.css">
  <link rel="stylesheet" href="css/admin-tables-shamcey.css?v=8">
  <style>
    html,body{height:100%;overflow:hidden;}
    .sh-mainpanel{height:100vh;display:flex;flex-direction:column;overflow:hidden;}
    .sh-mainpanel > .sh-pagebody{
      overflow:hidden !important;display:flex !important;flex-direction:column !important;min-height:0 !important;
      padding-top:8px !important;padding-bottom:8px !important;flex:1 1 auto;background:#f4f6fb;
    }
    .inv-wrap{flex:1 1 auto;min-height:0;display:flex;flex-direction:column;gap:8px;overflow:hidden;padding:0 2px;box-sizing:border-box;}
    .inv-top{flex:0 0 auto;display:flex;align-items:center;justify-content:flex-end;gap:8px;}
    .inv-btn{height:30px;padding:0 10px;border-radius:8px;border:1px solid #e2e8f0;background:#fff;font-size:11px;font-weight:700;color:#334155;display:inline-flex;align-items:center;gap:5px;cursor:pointer;text-decoration:none;}
    .inv-btn:hover{background:#f8fafc;text-decoration:none;color:#0f172a;}
    .inv-cards{flex:0 0 auto;display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:8px;}
    .inv-card{background:#fff;border:1px solid #eef2f7;border-radius:12px;padding:10px 12px;box-shadow:0 1px 2px rgba(15,23,42,.04);text-decoration:none;color:inherit;display:block;min-width:0;}
    .inv-card:hover{border-color:#bfdbfe;text-decoration:none;color:inherit;}
    .inv-card.is-active{border-color:#2563eb;box-shadow:0 0 0 1px #2563eb inset;}
    .inv-card-top{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:6px;}
    .inv-card-top .lab{font-size:11px;font-weight:700;color:#64748b;}
    .inv-ico{width:28px;height:28px;border-radius:999px;display:flex;align-items:center;justify-content:center;font-size:12px;}
    .inv-ico.blue{background:#dbeafe;color:#2563eb;}
    .inv-ico.green{background:#f0fdf4;color:#16a34a;}
    .inv-ico.orange{background:#ffedd5;color:#ea580c;}
    .inv-ico.red{background:#fef2f2;color:#dc2626;}
    .inv-ico.gray{background:#f1f5f9;color:#64748b;}
    .inv-card .val{font-size:20px;font-weight:800;color:#0f172a;line-height:1;}
    .inv-card .sub{font-size:10px;color:#94a3b8;font-weight:600;margin-top:4px;}
    .inv-tabs{flex:0 0 auto;display:flex;align-items:center;gap:0;background:#fff;border:1px solid #eef2f7;border-radius:10px;padding:0 4px;overflow:auto;}
    .inv-tabs a{flex:0 0 auto;padding:8px 12px;font-size:12px;font-weight:800;color:#64748b;text-decoration:none;border-bottom:2px solid transparent;white-space:nowrap;}
    .inv-tabs a .cnt{font-weight:700;color:#94a3b8;margin-left:4px;}
    .inv-tabs a.is-active{color:#2563eb;border-bottom-color:#2563eb;}
    .inv-main{flex:1 1 auto;min-height:0;background:#fff;border:1px solid #eef2f7;border-radius:12px;overflow:hidden;box-shadow:0 1px 2px rgba(15,23,42,.04);display:flex;flex-direction:column;}
    .inv-filters{flex:0 0 auto;display:flex;gap:6px;flex-wrap:wrap;align-items:center;padding:10px 12px;border-bottom:1px solid #eef2f7;background:#fafbfc;}
    .inv-search{position:relative;flex:1 1 160px;min-width:140px;max-width:260px;}
    .inv-search i{position:absolute;left:9px;top:50%;transform:translateY(-50%);color:#94a3b8;font-size:12px;}
    .inv-search input,.inv-filters select{height:30px;border:1px solid #e2e8f0;border-radius:8px;padding:0 9px;font-size:11px;background:#fff;color:#0f172a;}
    .inv-search input{width:100%;padding-left:28px;}
    .inv-table-wrap{flex:1 1 auto;min-height:0;overflow:auto;}
    .inv-table{width:100%;border-collapse:separate;border-spacing:0;min-width:1180px;}
    .inv-table th{text-align:left;font-size:9px;font-weight:800;letter-spacing:.03em;text-transform:uppercase;color:#64748b;padding:8px 8px;border-bottom:1px solid #eef2f7;background:#fff;position:sticky;top:0;z-index:3;white-space:nowrap;}
    .inv-table td{padding:10px 8px;border-bottom:1px solid #f1f5f9;vertical-align:middle;font-size:11px;color:#0f172a;}
    .inv-table tr:hover td{background:#f8fafc;}
    .inv-person{display:flex;align-items:center;gap:8px;min-width:0;}
    .inv-av{width:28px;height:28px;border-radius:999px;color:#fff;font-size:10px;font-weight:800;display:flex;align-items:center;justify-content:center;flex:0 0 28px;}
    .inv-name{font-weight:800;font-size:11px;color:#0f172a;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
    .inv-sub{font-size:10px;color:#64748b;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
    .inv-id-link{color:#2563eb;font-weight:800;text-decoration:none;}
    .inv-id-link:hover{text-decoration:underline;color:#1d4ed8;}
    .inv-prod{display:flex;align-items:center;gap:8px;min-width:0;}
    .inv-thumb{width:36px;height:36px;border-radius:8px;object-fit:cover;background:#e2e8f0;flex:0 0 36px;border:1px solid #eef2f7;}
    .inv-thumb.ph{display:flex;align-items:center;justify-content:center;color:#94a3b8;font-size:12px;}
    .inv-amt{font-weight:800;white-space:nowrap;}
    .inv-stock{font-weight:800;font-variant-numeric:tabular-nums;}
    .inv-stock.low{color:#c2410c;}
    .inv-stock.sold_out{color:#b91c1c;}
    .inv-stock.in_stock{color:#15803d;}
    .inv-stock.untracked{color:#64748b;}
    .inv-pill{display:inline-flex;align-items:center;padding:2px 8px;border-radius:999px;font-size:10px;font-weight:800;white-space:nowrap;}
    .inv-pill.active,.inv-pill.in_stock{background:#dcfce7;color:#15803d;}
    .inv-pill.draft,.inv-pill.untracked{background:#f1f5f9;color:#64748b;}
    .inv-pill.sold_out{background:#fee2e2;color:#b91c1c;}
    .inv-pill.archived{background:#e2e8f0;color:#475569;}
    .inv-pill.low{background:#ffedd5;color:#c2410c;}
    .inv-acts{display:flex;align-items:center;gap:6px;}
    .inv-view{width:28px;height:28px;border-radius:8px;border:1px solid #e2e8f0;background:#fff;color:#334155;display:inline-flex;align-items:center;justify-content:center;text-decoration:none;}
    .inv-view:hover{background:#eff6ff;border-color:#bfdbfe;color:#1d4ed8;text-decoration:none;}
    .inv-foot{flex:0 0 auto;display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;padding:10px 12px;border-top:1px solid #eef2f7;}
    .inv-foot .muted{font-size:11px;color:#64748b;font-weight:600;}
    .dataTables_wrapper .dataTables_paginate{float:none;text-align:center;padding:0;}
    .dataTables_wrapper .dataTables_paginate .paginate_button{min-width:28px !important;height:28px !important;padding:0 7px !important;margin:0 2px !important;border-radius:7px !important;border:1px solid #e2e8f0 !important;background:#fff !important;font-size:11px !important;font-weight:700 !important;line-height:26px !important;}
    .dataTables_wrapper .dataTables_paginate .paginate_button.current{background:#2563eb !important;border-color:#2563eb !important;color:#fff !important;}
    .dataTables_wrapper .dataTables_info,.dataTables_wrapper .dataTables_length,.dataTables_wrapper .dataTables_filter{display:none !important;}
    #datatable1_wrapper{display:contents;}
    .inv-drawer{position:fixed;inset:0;z-index:12050;pointer-events:none;}
    .inv-drawer.is-open{pointer-events:auto;}
    .inv-drawer-bg{position:absolute;inset:0;background:rgba(15,23,42,.45);opacity:0;transition:opacity .2s ease;border:0;cursor:pointer;}
    .inv-drawer.is-open .inv-drawer-bg{opacity:1;}
    .inv-drawer-panel{position:absolute;top:0;right:0;bottom:0;width:min(440px,96vw);background:#fff;border-left:1px solid #e5e7eb;box-shadow:-18px 0 48px rgba(0,0,0,.2);transform:translateX(105%);transition:transform .2s ease;display:flex;flex-direction:column;}
    .inv-drawer.is-open .inv-drawer-panel{transform:translateX(0);}
    .inv-drawer-head{padding:16px 18px;border-bottom:1px solid #eef2f7;display:flex;align-items:flex-start;justify-content:space-between;gap:12px;}
    .inv-drawer-head h3{margin:0;font-size:17px;font-weight:900;color:#0f172a;}
    .inv-drawer-head .sub{font-size:12px;color:#64748b;font-weight:600;margin-top:3px;}
    .inv-drawer-close{width:36px;height:36px;border-radius:999px;border:1px solid #e2e8f0;background:#fff;display:inline-flex;align-items:center;justify-content:center;text-decoration:none;color:#0f172a;}
    .inv-drawer-body{flex:1 1 auto;overflow:auto;padding:16px 18px;display:flex;flex-direction:column;gap:14px;}
    .inv-sec{background:#f8fafc;border:1px solid #eef2f7;border-radius:12px;padding:12px 14px;}
    .inv-sec h4{margin:0 0 8px;font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.04em;color:#64748b;}
    .inv-kv{display:grid;grid-template-columns:110px 1fr;gap:6px 10px;font-size:12px;}
    .inv-kv .k{color:#64748b;font-weight:700;}
    .inv-kv .v{color:#0f172a;font-weight:600;word-break:break-word;}
    @media (max-width:1100px){.inv-wrap{overflow:auto;}.inv-cards{grid-template-columns:repeat(2,minmax(0,1fr));}}
  </style>
</head>
<body>
<?php
$adminChromePageIntro = [
    'title' => 'Inventory',
    'description' => 'View and manage marketplace product inventory across sellers and brands.',
];
include __DIR__ . '/includes/leftbar.php';
include __DIR__ . '/includes/header.php';
?>

<div class="sh-mainpanel">
  <div class="sh-pagebody">
    <div class="inv-wrap">
      <div class="inv-top">
        <button type="button" class="inv-btn" onclick="document.getElementById('invFilters').scrollIntoView({behavior:'smooth',block:'center'})"><i class="fa fa-sliders"></i> Filter</button>
        <button type="button" class="inv-btn" title="Export coming soon"><i class="fa fa-download"></i> Export</button>
      </div>

      <?php if ($loadError !== ''): ?>
        <div style="padding:8px 12px;border-radius:8px;background:#fef2f2;border:1px solid #fecaca;color:#991b1b;font-size:12px;font-weight:700;"><?= h($loadError) ?></div>
      <?php endif; ?>

      <div class="inv-cards">
        <a class="inv-card<?= $statusFilter === 'all' ? ' is-active' : '' ?>" href="<?= h($href(['status' => 'all'])) ?>">
          <div class="inv-card-top"><div style="display:flex;align-items:center;gap:8px;"><div class="inv-ico blue"><i class="fa fa-cubes"></i></div><div class="lab">Total Products</div></div></div>
          <div class="val"><?= number_format($kpiTotal) ?></div><div class="sub"><?= number_format($unitsTracked) ?> tracked units</div>
        </a>
        <a class="inv-card<?= $statusFilter === 'active' ? ' is-active' : '' ?>" href="<?= h($href(['status' => 'active'])) ?>">
          <div class="inv-card-top"><div style="display:flex;align-items:center;gap:8px;"><div class="inv-ico green"><i class="fa fa-check-circle"></i></div><div class="lab">Active</div></div></div>
          <div class="val"><?= number_format($kpiActive) ?></div><div class="sub">Listed for sale</div>
        </a>
        <a class="inv-card<?= $statusFilter === 'low' ? ' is-active' : '' ?>" href="<?= h($href(['status' => 'low'])) ?>">
          <div class="inv-card-top"><div style="display:flex;align-items:center;gap:8px;"><div class="inv-ico orange"><i class="fa fa-exclamation-triangle"></i></div><div class="lab">Low Stock</div></div></div>
          <div class="val"><?= number_format($kpiLow) ?></div><div class="sub">Under 5 units</div>
        </a>
        <a class="inv-card<?= $statusFilter === 'sold_out' ? ' is-active' : '' ?>" href="<?= h($href(['status' => 'sold_out'])) ?>">
          <div class="inv-card-top"><div style="display:flex;align-items:center;gap:8px;"><div class="inv-ico red"><i class="fa fa-ban"></i></div><div class="lab">Sold Out</div></div></div>
          <div class="val"><?= number_format($kpiSoldOut) ?></div><div class="sub">Zero / sold out</div>
        </a>
        <a class="inv-card<?= $statusFilter === 'draft' ? ' is-active' : '' ?>" href="<?= h($href(['status' => 'draft'])) ?>">
          <div class="inv-card-top"><div style="display:flex;align-items:center;gap:8px;"><div class="inv-ico gray"><i class="fa fa-file-o"></i></div><div class="lab">Draft</div></div></div>
          <div class="val"><?= number_format($kpiDraft) ?></div><div class="sub">Not published</div>
        </a>
      </div>

      <nav class="inv-tabs" aria-label="Inventory status">
        <a href="<?= h($href(['status' => 'all'])) ?>" class="<?= $statusFilter === 'all' ? 'is-active' : '' ?>">All<span class="cnt">(<?= (int)$counts['all'] ?>)</span></a>
        <a href="<?= h($href(['status' => 'active'])) ?>" class="<?= $statusFilter === 'active' ? 'is-active' : '' ?>">Active<span class="cnt">(<?= (int)$counts['active'] ?>)</span></a>
        <a href="<?= h($href(['status' => 'in_stock'])) ?>" class="<?= $statusFilter === 'in_stock' ? 'is-active' : '' ?>">In Stock<span class="cnt">(<?= (int)$counts['in_stock'] ?>)</span></a>
        <a href="<?= h($href(['status' => 'low'])) ?>" class="<?= $statusFilter === 'low' ? 'is-active' : '' ?>">Low Stock<span class="cnt">(<?= (int)$counts['low'] ?>)</span></a>
        <a href="<?= h($href(['status' => 'sold_out'])) ?>" class="<?= $statusFilter === 'sold_out' ? 'is-active' : '' ?>">Sold Out<span class="cnt">(<?= (int)$counts['sold_out'] ?>)</span></a>
        <a href="<?= h($href(['status' => 'draft'])) ?>" class="<?= $statusFilter === 'draft' ? 'is-active' : '' ?>">Draft<span class="cnt">(<?= (int)$counts['draft'] ?>)</span></a>
        <a href="<?= h($href(['status' => 'archived'])) ?>" class="<?= $statusFilter === 'archived' ? 'is-active' : '' ?>">Archived<span class="cnt">(<?= (int)$counts['archived'] ?>)</span></a>
      </nav>

      <div class="inv-main">
        <form class="inv-filters" id="invFilters" method="get">
          <select name="status" aria-label="Status" onchange="this.form.submit()">
            <option value="all"<?= $statusFilter === 'all' ? ' selected' : '' ?>>All Statuses</option>
            <?php foreach ($statusLabels as $k => $lab): ?>
              <option value="<?= h($k) ?>"<?= $statusFilter === $k ? ' selected' : '' ?>><?= h($lab) ?></option>
            <?php endforeach; ?>
          </select>
          <select name="seller" aria-label="Seller" onchange="this.form.submit()">
            <option value="">All Sellers</option>
            <?php foreach ($sellers as $sid => $sname): ?>
              <option value="<?= (int)$sid ?>"<?= $sellerFilter === (int)$sid ? ' selected' : '' ?>><?= h($sname) ?></option>
            <?php endforeach; ?>
          </select>
          <select name="brand" aria-label="Brand" onchange="this.form.submit()">
            <option value="">All Brands</option>
            <?php foreach ($brands as $bid => $bname): ?>
              <option value="<?= (int)$bid ?>"<?= $brandFilter === (int)$bid ? ' selected' : '' ?>><?= h($bname) ?></option>
            <?php endforeach; ?>
          </select>
          <select name="category" aria-label="Category" onchange="this.form.submit()">
            <option value="all"<?= $categoryFilter === 'all' ? ' selected' : '' ?>>All Categories</option>
            <?php foreach (array_keys($categories) as $cat): ?>
              <option value="<?= h($cat) ?>"<?= $categoryFilter === $cat ? ' selected' : '' ?>><?= h(ucwords(str_replace('_', ' ', $cat))) ?></option>
            <?php endforeach; ?>
          </select>
          <select name="fulfillment" aria-label="Fulfillment" onchange="this.form.submit()">
            <option value="all"<?= $fulfillFilter === 'all' ? ' selected' : '' ?>>All Fulfillment</option>
            <option value="fbm"<?= $fulfillFilter === 'fbm' ? ' selected' : '' ?>>FBM</option>
            <option value="fba"<?= $fulfillFilter === 'fba' ? ' selected' : '' ?>>FBA</option>
          </select>
          <div class="inv-search">
            <i class="fa fa-search"></i>
            <input type="search" name="q" value="<?= h($q) ?>" placeholder="Search product, SKU, seller…" autocomplete="off">
          </div>
          <button type="submit" class="inv-btn">Filter</button>
          <select id="invPageLen" aria-label="Per page">
            <option value="10" selected>10 / page</option>
            <option value="25">25 / page</option>
            <option value="50">50 / page</option>
            <option value="100">100 / page</option>
          </select>
        </form>

        <div class="inv-table-wrap">
          <table id="datatable1" class="inv-table display" style="width:100%;">
            <thead>
              <tr>
                <th style="width:28px;"><input type="checkbox" id="invSelectAll"></th>
                <th>Product</th>
                <th>SKU / Code</th>
                <th>Seller (Brand)</th>
                <th>Category</th>
                <th>Stock</th>
                <th>Price</th>
                <th>Fulfillment</th>
                <th>Status</th>
                <th>Updated</th>
                <th style="width:88px;">Actions</th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($products as $row):
                $id = (int)($row['id'] ?? 0);
                $title = trim((string)($row['title'] ?? 'Untitled'));
                $sku = trim((string)($row['sku'] ?? ''));
                $code = trim((string)($row['product_code'] ?? ''));
                if ($code === '') {
                    $code = function_exists('org_shop_product_code_from_id') ? org_shop_product_code_from_id($id) : ('PRD-' . $id);
                }
                $skuLine = $sku !== '' ? $sku : '—';
                $brandName = trim((string)($row['brand_name'] ?? ''));
                $orgName = trim((string)($row['org_name'] ?? ''));
                $sellerDisplay = $brandName !== '' ? $brandName : ($orgName !== '' ? $orgName : 'Unknown seller');
                $sellerUser = trim((string)($row['seller_username'] ?? ''));
                if ($sellerUser === '') {
                    $sellerUser = trim((string)($row['manager_username'] ?? ''));
                }
                $sellerSub = $orgName !== '' && $brandName !== '' && strcasecmp($orgName, $brandName) !== 0
                    ? $orgName
                    : ($sellerUser !== '' ? '@' . $sellerUser : '—');
                $mgrName = trim((string)($row['manager_fullname'] ?? ''));
                $cover = function_exists('org_shop_cover_url') ? org_shop_cover_url((string)($row['cover_image_path'] ?? '')) : '';
                $bucket = (string)($row['_stock_bucket'] ?? 'untracked');
                $stockRaw = $row['stock_qty'] ?? null;
                $stockLabel = ($stockRaw === null || $stockRaw === '') ? '—' : (string)(int)$stockRaw;
                $dbStatus = strtolower(trim((string)($row['status'] ?? 'draft')));
                $cat = trim((string)($row['category'] ?? ''));
                $ff = strtoupper(trim((string)($row['fulfillment_method'] ?? 'fbm')));
                $detailHref = $href(['id' => $id]);
                $orgDetailHref = 'open_product_detail.php?id=' . $id . '&from=sales';
            ?>
              <tr>
                <td><input type="checkbox" class="inv-row-check" value="<?= $id ?>"></td>
                <td>
                  <div class="inv-prod">
                    <?php if ($cover !== ''): ?>
                      <img class="inv-thumb" src="<?= h($cover) ?>" alt="">
                    <?php else: ?>
                      <span class="inv-thumb ph"><i class="fa fa-image"></i></span>
                    <?php endif; ?>
                    <div style="min-width:0;">
                      <div class="inv-name"><?= h($title) ?></div>
                      <div class="inv-sub">
                        <a class="inv-id-link" href="<?= h($orgDetailHref) ?>" title="Open product detail">Product ID <?= h($code) ?></a>
                      </div>
                    </div>
                  </div>
                </td>
                <td>
                  <div class="inv-name"><?= h($skuLine) ?></div>
                  <div class="inv-sub"><a class="inv-id-link" href="<?= h($orgDetailHref) ?>"><?= h($code) ?></a></div>
                </td>
                <td>
                  <div class="inv-person">
                    <span class="inv-av" style="background:<?= h(trim((string)($row['brand_color'] ?? '')) ?: avatarColor($sellerDisplay)) ?>;">
                      <?= h(trim((string)($row['brand_letter'] ?? '')) !== '' ? strtoupper((string)$row['brand_letter']) : initials2($sellerDisplay)) ?>
                    </span>
                    <div style="min-width:0;">
                      <div class="inv-name"><?= h($sellerDisplay) ?></div>
                      <div class="inv-sub"><?= h($sellerSub) ?><?= $mgrName !== '' ? ' · ' . h($mgrName) : '' ?></div>
                    </div>
                  </div>
                </td>
                <td><div class="inv-sub" style="color:#475569;font-weight:600;"><?= h($cat !== '' ? ucwords(str_replace('_', ' ', $cat)) : '—') ?></div></td>
                <td><span class="inv-stock <?= h($bucket) ?>"><?= h($stockLabel) ?></span></td>
                <td><span class="inv-amt"><?= h(money_fmt((int)($row['price_cents'] ?? 0), (string)($row['currency'] ?? 'USD'))) ?></span></td>
                <td><div class="inv-sub" style="color:#475569;font-weight:700;"><?= h($ff) ?></div></td>
                <td><span class="inv-pill <?= h($dbStatus) ?>"><?= h(ucwords(str_replace('_', ' ', $dbStatus))) ?></span></td>
                <td><div class="inv-sub" style="color:#475569;font-weight:600;"><?= h(fmt_dt($row['updated_at'] ?? $row['created_at'] ?? '')) ?></div></td>
                <td>
                  <div class="inv-acts">
                    <a class="inv-view" href="<?= h($detailHref) ?>" title="View details"><i class="fa fa-eye"></i></a>
                    <div class="fries-menu">
                      <button type="button" class="fries-toggle" title="Actions" aria-label="Actions" aria-haspopup="true"><span class="fries-icon" aria-hidden="true"></span></button>
                      <div class="fries-dropdown" role="menu">
                        <a class="fries-item" role="menuitem" href="<?= h($detailHref) ?>"><i class="fa fa-eye"></i> View details</a>
                        <a class="fries-item" role="menuitem" href="<?= h($orgDetailHref) ?>"><i class="fa fa-external-link"></i> Product page</a>
                        <?php if ((int)($row['org_id'] ?? 0) > 0): ?>
                          <a class="fries-item" role="menuitem" href="orglist.php?q=<?= h(rawurlencode((string)($row['org_name'] ?? ''))) ?>"><i class="fa fa-building"></i> Seller org</a>
                        <?php endif; ?>
                        <?php if ((int)($row['brand_id'] ?? 0) > 0): ?>
                          <a class="fries-item" role="menuitem" href="org_commerce_brands.php?filter=all&amp;q=<?= h(rawurlencode((string)($row['brand_name'] ?? ''))) ?>"><i class="fa fa-tag"></i> Brand</a>
                        <?php endif; ?>
                      </div>
                    </div>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <div class="inv-foot">
          <div class="muted" id="invShowing">Showing 0 products</div>
          <div id="invPagerHost"></div>
          <div class="muted"><span id="visibleInvCount"><?= (int)count($products) ?></span> in this view</div>
        </div>
      </div>
    </div>
  </div>
</div>

<?php if ($detailProduct):
    $d = $detailProduct;
    $dBucket = (string)($d['_stock_bucket'] ?? 'untracked');
    $dBrand = trim((string)($d['brand_name'] ?? '')) ?: '—';
    $dOrg = trim((string)($d['org_name'] ?? '')) ?: '—';
    $dSellerName = trim((string)($d['manager_fullname'] ?? '')) ?: (trim((string)($d['seller_username'] ?? '')) ?: $dOrg);
    $dSellerContact = implode(' · ', array_filter([
        trim((string)($d['manager_email'] ?? '')),
        trim((string)($d['seller_user_email'] ?? '')),
    ])) ?: '—';
    $stockRaw = $d['stock_qty'] ?? null;
    $stockLabel = ($stockRaw === null || $stockRaw === '') ? 'Untracked' : (string)(int)$stockRaw;
    $closeHref = $href(['id' => '']);
?>
<div class="inv-drawer is-open" id="invDrawer" role="dialog" aria-modal="true" aria-label="Product details">
  <button type="button" class="inv-drawer-bg" onclick="location.href='<?= h($closeHref) ?>'" aria-label="Close"></button>
  <div class="inv-drawer-panel">
    <div class="inv-drawer-head">
      <div>
        <h3><?= h(trim((string)($d['title'] ?? 'Product'))) ?></h3>
        <div class="sub">#<?= (int)$d['id'] ?> · <span class="inv-pill <?= h(strtolower((string)($d['status'] ?? ''))) ?>"><?= h(ucwords(str_replace('_', ' ', (string)($d['status'] ?? '')))) ?></span></div>
      </div>
      <a class="inv-drawer-close" href="<?= h($closeHref) ?>" title="Close"><i class="fa fa-times"></i></a>
    </div>
    <div class="inv-drawer-body">
      <div class="inv-sec">
        <h4>Product</h4>
        <div class="inv-kv">
          <div class="k">Title</div><div class="v"><?= h(trim((string)($d['title'] ?? '')) ?: '—') ?></div>
          <div class="k">SKU</div><div class="v"><?= h(trim((string)($d['sku'] ?? '')) ?: '—') ?></div>
          <div class="k">Code</div><div class="v"><?= h(trim((string)($d['product_code'] ?? '')) ?: '—') ?></div>
          <div class="k">Category</div><div class="v"><?= h(trim((string)($d['category'] ?? '')) ?: '—') ?></div>
          <div class="k">Stock</div><div class="v"><span class="inv-stock <?= h($dBucket) ?>"><?= h($stockLabel) ?></span> <span class="inv-pill <?= h($dBucket) ?>"><?= h($statusLabels[$dBucket] ?? $dBucket) ?></span></div>
          <div class="k">Price</div><div class="v"><?= h(money_fmt((int)($d['price_cents'] ?? 0), (string)($d['currency'] ?? 'USD'))) ?></div>
          <div class="k">Fulfillment</div><div class="v"><?= h(strtoupper((string)($d['fulfillment_method'] ?? 'fbm'))) ?></div>
          <div class="k">Updated</div><div class="v"><?= h(fmt_dt($d['updated_at'] ?? $d['created_at'] ?? '')) ?></div>
        </div>
      </div>
      <div class="inv-sec">
        <h4>Seller &amp; brand</h4>
        <div class="inv-kv">
          <div class="k">Brand</div><div class="v"><?= h($dBrand) ?></div>
          <div class="k">Seller</div><div class="v"><?= h($dSellerName) ?></div>
          <div class="k">Organization</div><div class="v"><?= h($dOrg) ?><?= trim((string)($d['org_code'] ?? '')) !== '' ? ' (' . h((string)$d['org_code']) . ')' : '' ?></div>
          <div class="k">Handle</div><div class="v"><?= h(trim((string)($d['seller_username'] ?? '')) !== '' ? '@' . $d['seller_username'] : '—') ?></div>
          <div class="k">Contact</div><div class="v"><?= h($dSellerContact) ?></div>
        </div>
      </div>
      <?php if (trim((string)($d['description'] ?? '')) !== ''): ?>
      <div class="inv-sec">
        <h4>Description</h4>
        <div class="v" style="font-size:12px;font-weight:600;color:#334155;"><?= h(truncate_text((string)$d['description'], 400)) ?></div>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php endif; ?>

<script src="../lib/jquery/jquery.js"></script>
<script src="../lib/popper.js/popper.js"></script>
<script src="../lib/bootstrap/bootstrap.js"></script>
<script src="../lib/datatables/jquery.dataTables.js"></script>
<script src="../js/shamcey.js"></script>
<script src="js/admin-fries-menu.js?v=1"></script>
<script>
$(function() {
  var hasRows = <?= count($products) > 0 ? 'true' : 'false' ?>;
  if (!hasRows) {
    $('#invShowing').text('Showing 0 products');
    return;
  }
  var dt = $('#datatable1').DataTable({
    paging: true,
    pageLength: 10,
    lengthChange: false,
    info: true,
    autoWidth: false,
    order: [[9, 'desc']],
    columnDefs: [{ orderable: false, targets: [0, 10] }],
    dom: 'tp',
    language: { paginate: { previous: '‹', next: '›' } },
    drawCallback: function() {
      var info = this.api().page.info();
      var from = info.recordsDisplay === 0 ? 0 : (info.start + 1);
      $('#invShowing').text('Showing ' + from + ' to ' + info.end + ' of ' + info.recordsDisplay + ' products.');
      $('#visibleInvCount').text(info.recordsDisplay);
      var $pag = $(this.api().table().container()).find('.dataTables_paginate');
      if ($pag.length) $('#invPagerHost').empty().append($pag);
    }
  });
  setTimeout(function(){ var $pag=$('#datatable1_paginate'); if($pag.length) $('#invPagerHost').empty().append($pag); }, 0);
  $('#invPageLen').on('change', function(){ dt.page.len(parseInt(this.value,10)||10).draw(); });
  $('#invSelectAll').on('change', function(){ $('.inv-row-check').prop('checked', this.checked); });
});
</script>
</body>
</html>
