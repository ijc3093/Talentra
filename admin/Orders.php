<?php
declare(strict_types=1);

/**
 * admin/Orders.php
 * Marketplace orders oversight — brands, sellers, buyers, contact, address.
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

/** Map DB row → UI lifecycle status. */
function order_ui_status(array $row): string
{
    $refund = strtolower(trim((string)($row['return_status'] ?? '')));
    if ($refund === 'refunded') {
        return 'refunded';
    }
    $st = strtolower(trim((string)($row['status'] ?? 'pending')));
    return match ($st) {
        'confirmed', 'paid' => 'processing',
        'shipped' => 'shipped',
        'delivered' => 'delivered',
        'cancelled' => 'canceled',
        default => 'pending',
    };
}

function order_payment_ui(array $row): string
{
    if (order_ui_status($row) === 'refunded') {
        return 'refunded';
    }
    $st = strtolower(trim((string)($row['status'] ?? '')));
    if (!empty($row['paid_at']) || in_array($st, ['paid', 'shipped', 'delivered'], true)) {
        return 'paid';
    }
    if ($st === 'cancelled') {
        return 'unpaid';
    }
    return 'pending';
}

$statusFilter = strtolower(trim((string)($_GET['status'] ?? 'all')));
$allowedStatus = ['all', 'pending', 'processing', 'shipped', 'delivered', 'canceled', 'refunded'];
if (!in_array($statusFilter, $allowedStatus, true)) {
    $statusFilter = 'all';
}

$payFilter = strtolower(trim((string)($_GET['payment'] ?? 'all')));
if (!in_array($payFilter, ['all', 'paid', 'pending', 'refunded', 'unpaid'], true)) {
    $payFilter = 'all';
}

$sellerFilter = (int)($_GET['seller'] ?? 0);
$brandFilter = (int)($_GET['brand'] ?? 0);
$categoryFilter = strtolower(trim((string)($_GET['category'] ?? 'all')));
$dateFrom = trim((string)($_GET['from'] ?? ''));
$dateTo = trim((string)($_GET['to'] ?? ''));
$q = trim((string)($_GET['q'] ?? ''));
$detailId = (int)($_GET['id'] ?? 0);

$hasReturns = org_admin_table_exists($dbh, 'org_order_returns');

$sql = "
    SELECT
        o.*,
        org.name AS org_name,
        org.org_code AS org_code,
        org.commerce_brand_id AS org_brand_id,
        org.publisher_user_id AS seller_user_id,
        org.business_model AS org_business_model,
        cb.id AS brand_id,
        cb.name AS brand_name,
        cb.slug AS brand_slug,
        cb.icon_letter AS brand_letter,
        cb.accent_color AS brand_color,
        bu.username AS buyer_username,
        su.username AS seller_username,
        su.email AS seller_user_email,
        m.fullname AS manager_fullname,
        m.email AS manager_email,
        m.username AS manager_username,
        p.cover_image_path AS product_cover,
        p.category AS product_category,
        p.product_code AS product_code
";
if ($hasReturns) {
    $sql .= ",
        (
            SELECT r.status
            FROM org_order_returns r
            WHERE r.order_id = o.id
            ORDER BY FIELD(r.status, 'refunded','approved','requested','rejected'), r.id DESC
            LIMIT 1
        ) AS return_status
    ";
} else {
    $sql .= ", NULL AS return_status";
}
$sql .= "
    FROM org_orders o
    LEFT JOIN organizations org ON org.id = o.org_id
    LEFT JOIN commerce_brands cb ON cb.id = org.commerce_brand_id
    LEFT JOIN users bu ON bu.id = o.buyer_user_id
    LEFT JOIN users su ON su.id = org.publisher_user_id
    LEFT JOIN managers m ON m.id = org.owner_manager_id
    LEFT JOIN org_products p ON p.id = o.product_id
    ORDER BY o.created_at DESC, o.id DESC
";

$allOrders = [];
$loadError = '';
try {
    $st = $dbh->query($sql);
    $allOrders = $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
} catch (Throwable $e) {
    $allOrders = [];
    $loadError = 'Could not load orders.';
    // Fallback without returns / optional joins that may be missing on older DBs.
    try {
        $fallbackSql = "
            SELECT
                o.*,
                org.name AS org_name,
                org.org_code AS org_code,
                org.commerce_brand_id AS org_brand_id,
                org.publisher_user_id AS seller_user_id,
                org.business_model AS org_business_model,
                cb.id AS brand_id,
                cb.name AS brand_name,
                cb.slug AS brand_slug,
                cb.icon_letter AS brand_letter,
                cb.accent_color AS brand_color,
                bu.username AS buyer_username,
                su.username AS seller_username,
                su.email AS seller_user_email,
                NULL AS manager_fullname,
                NULL AS manager_email,
                NULL AS manager_username,
                p.cover_image_path AS product_cover,
                p.category AS product_category,
                p.product_code AS product_code,
                NULL AS return_status
            FROM org_orders o
            LEFT JOIN organizations org ON org.id = o.org_id
            LEFT JOIN commerce_brands cb ON cb.id = org.commerce_brand_id
            LEFT JOIN users bu ON bu.id = o.buyer_user_id
            LEFT JOIN users su ON su.id = org.publisher_user_id
            LEFT JOIN org_products p ON p.id = o.product_id
            ORDER BY o.created_at DESC, o.id DESC
        ";
        $st = $dbh->query($fallbackSql);
        $allOrders = $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        if ($allOrders) {
            $loadError = '';
        }
    } catch (Throwable $e2) {
        $loadError = 'Could not load orders from the database.';
    }
}

$sellers = [];
$brands = [];
$categories = [];
$counts = [
    'all' => 0,
    'pending' => 0,
    'processing' => 0,
    'shipped' => 0,
    'delivered' => 0,
    'canceled' => 0,
    'refunded' => 0,
];
$kpiTotal = 0;
$kpiCompleted = 0;
$kpiPending = 0;
$kpiCanceled = 0;
$kpiRefunds = 0;
$weekAgo = strtotime('-7 days') ?: 0;
$prevWeek = strtotime('-14 days') ?: 0;
$curWeek = 0;
$prevWeekCount = 0;

foreach ($allOrders as $row) {
    $kpiTotal++;
    $ui = order_ui_status($row);
    $counts['all']++;
    if (isset($counts[$ui])) {
        $counts[$ui]++;
    }
    if ($ui === 'delivered') {
        $kpiCompleted++;
    } elseif ($ui === 'pending' || $ui === 'processing') {
        $kpiPending++;
    } elseif ($ui === 'canceled') {
        $kpiCanceled++;
    } elseif ($ui === 'refunded') {
        $kpiRefunds++;
    }

    $cts = strtotime((string)($row['created_at'] ?? '')) ?: 0;
    if ($cts >= $weekAgo) {
        $curWeek++;
    } elseif ($cts >= $prevWeek) {
        $prevWeekCount++;
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
    $cat = strtolower(trim((string)($row['product_category'] ?? '')));
    if ($cat !== '') {
        $categories[$cat] = true;
    }
}
asort($sellers);
asort($brands);
ksort($categories);

$pctTrend = static function (int $cur, int $prev): string {
    if ($prev <= 0) {
        return $cur > 0 ? '+100% vs last 7 days' : '0% vs last 7 days';
    }
    $pct = (($cur - $prev) / $prev) * 100;
    $sign = $pct >= 0 ? '+' : '';
    return $sign . number_format($pct, 1) . '% vs last 7 days';
};
$trendLabel = $pctTrend($curWeek, $prevWeekCount);

$orders = [];
foreach ($allOrders as $row) {
    $ui = order_ui_status($row);
    $pay = order_payment_ui($row);
    if ($statusFilter !== 'all' && $ui !== $statusFilter) {
        continue;
    }
    if ($payFilter !== 'all' && $pay !== $payFilter) {
        continue;
    }
    if ($sellerFilter > 0 && (int)($row['org_id'] ?? 0) !== $sellerFilter) {
        continue;
    }
    if ($brandFilter > 0 && (int)($row['brand_id'] ?? 0) !== $brandFilter) {
        continue;
    }
    $cat = strtolower(trim((string)($row['product_category'] ?? '')));
    if ($categoryFilter !== 'all' && $cat !== $categoryFilter) {
        continue;
    }
    $cts = strtotime((string)($row['created_at'] ?? '')) ?: 0;
    if ($dateFrom !== '') {
        $fromTs = strtotime($dateFrom . ' 00:00:00');
        if ($fromTs && $cts < $fromTs) {
            continue;
        }
    }
    if ($dateTo !== '') {
        $toTs = strtotime($dateTo . ' 23:59:59');
        if ($toTs && $cts > $toTs) {
            continue;
        }
    }
    if ($q !== '') {
        $hay = mb_strtolower(implode(' ', [
            (string)($row['order_code'] ?? ''),
            (string)($row['id'] ?? ''),
            (string)($row['buyer_name'] ?? ''),
            (string)($row['buyer_email'] ?? ''),
            (string)($row['buyer_phone'] ?? ''),
            (string)($row['buyer_username'] ?? ''),
            (string)($row['org_name'] ?? ''),
            (string)($row['brand_name'] ?? ''),
            (string)($row['seller_username'] ?? ''),
            (string)($row['manager_fullname'] ?? ''),
            (string)($row['product_title'] ?? ''),
            (string)($row['delivery_address'] ?? ''),
            (string)($row['stripe_payment_intent_id'] ?? ''),
        ]));
        if (mb_strpos($hay, mb_strtolower($q)) === false) {
            continue;
        }
    }
    $row['_ui_status'] = $ui;
    $row['_ui_payment'] = $pay;
    $orders[] = $row;
}

$detailOrder = null;
if ($detailId > 0) {
    foreach ($allOrders as $row) {
        if ((int)($row['id'] ?? 0) === $detailId) {
            $row['_ui_status'] = order_ui_status($row);
            $row['_ui_payment'] = order_payment_ui($row);
            $detailOrder = $row;
            break;
        }
    }
}

$href = static function (array $overrides = []) use (
    $statusFilter,
    $payFilter,
    $sellerFilter,
    $brandFilter,
    $categoryFilter,
    $dateFrom,
    $dateTo,
    $q
): string {
    $params = array_merge([
        'status' => $statusFilter,
        'payment' => $payFilter,
        'seller' => $sellerFilter > 0 ? $sellerFilter : '',
        'brand' => $brandFilter > 0 ? $brandFilter : '',
        'category' => $categoryFilter,
        'from' => $dateFrom,
        'to' => $dateTo,
        'q' => $q,
    ], $overrides);
    foreach ($params as $k => $v) {
        if ($v === '' || $v === null || $v === 0 || $v === '0' || $v === 'all') {
            if (!in_array($k, ['status'], true) || $v === 'all') {
                if ($k === 'status' && $v === 'all') {
                    // keep
                } elseif ($v === 'all' || $v === '' || $v === 0 || $v === '0' || $v === null) {
                    unset($params[$k]);
                }
            }
        }
    }
    if (($params['status'] ?? '') === 'all') {
        unset($params['status']);
    }
    return 'Orders.php' . ($params ? ('?' . http_build_query($params)) : '');
};

$statusLabels = [
    'pending' => 'Pending',
    'processing' => 'Processing',
    'shipped' => 'Shipped',
    'delivered' => 'Delivered',
    'canceled' => 'Canceled',
    'refunded' => 'Refunded',
];
$payLabels = [
    'paid' => 'Paid',
    'pending' => 'Pending',
    'refunded' => 'Refunded',
    'unpaid' => 'Unpaid',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
  <title>Orders</title>
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
    .od-wrap{flex:1 1 auto;min-height:0;display:flex;flex-direction:column;gap:8px;overflow:hidden;padding:0 2px;box-sizing:border-box;}
    .od-top{flex:0 0 auto;display:flex;align-items:center;justify-content:flex-end;gap:8px;}
    .od-btn{height:30px;padding:0 10px;border-radius:8px;border:1px solid #e2e8f0;background:#fff;font-size:11px;font-weight:700;color:#334155;display:inline-flex;align-items:center;gap:5px;cursor:pointer;text-decoration:none;}
    .od-btn:hover{background:#f8fafc;text-decoration:none;color:#0f172a;}
    .od-cards{flex:0 0 auto;display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:8px;}
    .od-card{background:#fff;border:1px solid #eef2f7;border-radius:12px;padding:10px 12px;box-shadow:0 1px 2px rgba(15,23,42,.04);text-decoration:none;color:inherit;display:block;min-width:0;}
    .od-card:hover{border-color:#bfdbfe;text-decoration:none;color:inherit;}
    .od-card.is-active{border-color:#2563eb;box-shadow:0 0 0 1px #2563eb inset;}
    .od-card-top{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:6px;}
    .od-card-top .lab{font-size:11px;font-weight:700;color:#64748b;}
    .od-card-top .delta{font-size:10px;font-weight:800;color:#16a34a;}
    .od-ico{width:28px;height:28px;border-radius:999px;display:flex;align-items:center;justify-content:center;font-size:12px;}
    .od-ico.blue{background:#dbeafe;color:#2563eb;}
    .od-ico.green{background:#f0fdf4;color:#16a34a;}
    .od-ico.orange{background:#ffedd5;color:#ea580c;}
    .od-ico.purple{background:#f3e8ff;color:#7c3aed;}
    .od-ico.red{background:#fef2f2;color:#dc2626;}
    .od-card .val{font-size:20px;font-weight:800;color:#0f172a;line-height:1;}
    .od-card .sub{font-size:10px;color:#94a3b8;font-weight:600;margin-top:4px;}
    .od-tabs{flex:0 0 auto;display:flex;align-items:center;gap:0;background:#fff;border:1px solid #eef2f7;border-radius:10px;padding:0 4px;overflow:auto;}
    .od-tabs a{flex:0 0 auto;padding:8px 12px;font-size:12px;font-weight:800;color:#64748b;text-decoration:none;border-bottom:2px solid transparent;white-space:nowrap;}
    .od-tabs a .cnt{font-weight:700;color:#94a3b8;margin-left:4px;}
    .od-tabs a.is-active{color:#2563eb;border-bottom-color:#2563eb;}
    .od-tabs .spacer{flex:1 1 auto;}
    .od-main{flex:1 1 auto;min-height:0;background:#fff;border:1px solid #eef2f7;border-radius:12px;overflow:hidden;box-shadow:0 1px 2px rgba(15,23,42,.04);display:flex;flex-direction:column;}
    .od-filters{flex:0 0 auto;display:flex;gap:6px;flex-wrap:wrap;align-items:center;padding:10px 12px;border-bottom:1px solid #eef2f7;background:#fafbfc;}
    .od-search{position:relative;flex:1 1 160px;min-width:140px;max-width:240px;}
    .od-search i{position:absolute;left:9px;top:50%;transform:translateY(-50%);color:#94a3b8;font-size:12px;}
    .od-search input,.od-filters select,.od-filters input[type="date"]{height:30px;border:1px solid #e2e8f0;border-radius:8px;padding:0 9px;font-size:11px;background:#fff;color:#0f172a;}
    .od-search input{width:100%;padding-left:28px;}
    .od-table-wrap{flex:1 1 auto;min-height:0;overflow:auto;}
    .od-table{width:100%;border-collapse:separate;border-spacing:0;min-width:1280px;}
    .od-table th{text-align:left;font-size:9px;font-weight:800;letter-spacing:.03em;text-transform:uppercase;color:#64748b;padding:8px 8px;border-bottom:1px solid #eef2f7;background:#fff;position:sticky;top:0;z-index:3;white-space:nowrap;}
    .od-table td{padding:10px 8px;border-bottom:1px solid #f1f5f9;vertical-align:middle;font-size:11px;color:#0f172a;}
    .od-table tr:hover td{background:#f8fafc;}
    .od-id{font-weight:800;color:#0f172a;}
    .od-id-sub{font-size:10px;color:#64748b;font-weight:600;}
    .od-id-link{color:#2563eb;font-weight:800;text-decoration:none;}
    .od-id-link:hover{text-decoration:underline;color:#1d4ed8;}
    .od-person{display:flex;align-items:center;gap:8px;min-width:0;}
    .od-av{width:28px;height:28px;border-radius:999px;color:#fff;font-size:10px;font-weight:800;display:flex;align-items:center;justify-content:center;flex:0 0 28px;}
    .od-name{font-weight:800;font-size:11px;color:#0f172a;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
    .od-sub{font-size:10px;color:#64748b;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
    .od-prod{display:flex;align-items:center;gap:8px;min-width:0;}
    .od-thumb{width:36px;height:36px;border-radius:8px;object-fit:cover;background:#e2e8f0;flex:0 0 36px;border:1px solid #eef2f7;}
    .od-thumb.ph{display:flex;align-items:center;justify-content:center;color:#94a3b8;font-size:12px;}
    .od-amt{font-weight:800;white-space:nowrap;}
    .od-pill{display:inline-flex;align-items:center;padding:2px 8px;border-radius:999px;font-size:10px;font-weight:800;white-space:nowrap;}
    .od-pill.paid,.od-pill.delivered{background:#dcfce7;color:#15803d;}
    .od-pill.pending{background:#ffedd5;color:#c2410c;}
    .od-pill.processing{background:#ffedd5;color:#c2410c;}
    .od-pill.shipped{background:#dbeafe;color:#1d4ed8;}
    .od-pill.canceled,.od-pill.unpaid{background:#fee2e2;color:#b91c1c;}
    .od-pill.refunded{background:#f3e8ff;color:#7c3aed;}
    .od-acts{display:flex;align-items:center;gap:6px;}
    .od-view{width:28px;height:28px;border-radius:8px;border:1px solid #e2e8f0;background:#fff;color:#334155;display:inline-flex;align-items:center;justify-content:center;text-decoration:none;}
    .od-view:hover{background:#eff6ff;border-color:#bfdbfe;color:#1d4ed8;text-decoration:none;}
    .od-foot{flex:0 0 auto;display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;padding:10px 12px;border-top:1px solid #eef2f7;}
    .od-foot .muted{font-size:11px;color:#64748b;font-weight:600;}
    .dataTables_wrapper .dataTables_paginate{float:none;text-align:center;padding:0;}
    .dataTables_wrapper .dataTables_paginate .paginate_button{min-width:28px !important;height:28px !important;padding:0 7px !important;margin:0 2px !important;border-radius:7px !important;border:1px solid #e2e8f0 !important;background:#fff !important;font-size:11px !important;font-weight:700 !important;line-height:26px !important;}
    .dataTables_wrapper .dataTables_paginate .paginate_button.current{background:#2563eb !important;border-color:#2563eb !important;color:#fff !important;}
    .dataTables_wrapper .dataTables_info,.dataTables_wrapper .dataTables_length,.dataTables_wrapper .dataTables_filter{display:none !important;}
    #datatable1_wrapper{display:contents;}
    .od-drawer{position:fixed;inset:0;z-index:12050;pointer-events:none;}
    .od-drawer.is-open{pointer-events:auto;}
    .od-drawer-bg{position:absolute;inset:0;background:rgba(15,23,42,.45);opacity:0;transition:opacity .2s ease;border:0;cursor:pointer;}
    .od-drawer.is-open .od-drawer-bg{opacity:1;}
    .od-drawer-panel{position:absolute;top:0;right:0;bottom:0;width:min(440px,96vw);background:#fff;border-left:1px solid #e5e7eb;box-shadow:-18px 0 48px rgba(0,0,0,.2);transform:translateX(105%);transition:transform .2s ease;display:flex;flex-direction:column;}
    .od-drawer.is-open .od-drawer-panel{transform:translateX(0);}
    .od-drawer-head{padding:16px 18px;border-bottom:1px solid #eef2f7;display:flex;align-items:flex-start;justify-content:space-between;gap:12px;}
    .od-drawer-head h3{margin:0;font-size:17px;font-weight:900;color:#0f172a;}
    .od-drawer-head .sub{font-size:12px;color:#64748b;font-weight:600;margin-top:3px;}
    .od-drawer-close{width:36px;height:36px;border-radius:999px;border:1px solid #e2e8f0;background:#fff;cursor:pointer;}
    .od-drawer-body{flex:1 1 auto;overflow:auto;padding:16px 18px;display:flex;flex-direction:column;gap:14px;}
    .od-sec{background:#f8fafc;border:1px solid #eef2f7;border-radius:12px;padding:12px 14px;}
    .od-sec h4{margin:0 0 8px;font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.04em;color:#64748b;}
    .od-kv{display:grid;grid-template-columns:100px 1fr;gap:6px 10px;font-size:12px;}
    .od-kv .k{color:#64748b;font-weight:700;}
    .od-kv .v{color:#0f172a;font-weight:600;word-break:break-word;}
    @media (max-width:1100px){.od-wrap{overflow:auto;}.od-cards{grid-template-columns:repeat(2,minmax(0,1fr));}}
  </style>
</head>
<body>
<?php
$adminChromePageIntro = [
    'title' => 'Orders',
    'description' => 'View and manage all marketplace orders.',
];
include __DIR__ . '/includes/leftbar.php';
include __DIR__ . '/includes/header.php';
?>

<div class="sh-mainpanel">
  <div class="sh-pagebody">
    <div class="od-wrap">
      <div class="od-top">
        <button type="button" class="od-btn" onclick="document.getElementById('odFilters').scrollIntoView({behavior:'smooth',block:'center'})"><i class="fa fa-sliders"></i> Filter</button>
        <button type="button" class="od-btn" title="Export coming soon"><i class="fa fa-download"></i> Export</button>
      </div>

      <?php if ($loadError !== ''): ?>
        <div style="padding:8px 12px;border-radius:8px;background:#fef2f2;border:1px solid #fecaca;color:#991b1b;font-size:12px;font-weight:700;"><?= h($loadError) ?></div>
      <?php endif; ?>

      <div class="od-cards">
        <a class="od-card<?= $statusFilter === 'all' ? ' is-active' : '' ?>" href="<?= h($href(['status' => 'all'])) ?>">
          <div class="od-card-top"><div style="display:flex;align-items:center;gap:8px;"><div class="od-ico blue"><i class="fa fa-shopping-bag"></i></div><div class="lab">Total Orders</div></div><div class="delta"><?= h($trendLabel) ?></div></div>
          <div class="val"><?= number_format($kpiTotal) ?></div><div class="sub">All marketplace orders</div>
        </a>
        <a class="od-card<?= $statusFilter === 'delivered' ? ' is-active' : '' ?>" href="<?= h($href(['status' => 'delivered'])) ?>">
          <div class="od-card-top"><div style="display:flex;align-items:center;gap:8px;"><div class="od-ico green"><i class="fa fa-check-circle"></i></div><div class="lab">Completed Orders</div></div><div class="delta"><?= h($trendLabel) ?></div></div>
          <div class="val"><?= number_format($kpiCompleted) ?></div><div class="sub">Delivered</div>
        </a>
        <a class="od-card<?= in_array($statusFilter, ['pending', 'processing'], true) ? ' is-active' : '' ?>" href="<?= h($href(['status' => 'pending'])) ?>">
          <div class="od-card-top"><div style="display:flex;align-items:center;gap:8px;"><div class="od-ico orange"><i class="fa fa-clock-o"></i></div><div class="lab">Pending Orders</div></div><div class="delta"><?= h($trendLabel) ?></div></div>
          <div class="val"><?= number_format($kpiPending) ?></div><div class="sub">Pending + processing</div>
        </a>
        <a class="od-card<?= $statusFilter === 'canceled' ? ' is-active' : '' ?>" href="<?= h($href(['status' => 'canceled'])) ?>">
          <div class="od-card-top"><div style="display:flex;align-items:center;gap:8px;"><div class="od-ico purple"><i class="fa fa-ban"></i></div><div class="lab">Canceled Orders</div></div><div class="delta"><?= h($trendLabel) ?></div></div>
          <div class="val"><?= number_format($kpiCanceled) ?></div><div class="sub">Canceled</div>
        </a>
        <a class="od-card<?= $statusFilter === 'refunded' ? ' is-active' : '' ?>" href="<?= h($href(['status' => 'refunded'])) ?>">
          <div class="od-card-top"><div style="display:flex;align-items:center;gap:8px;"><div class="od-ico red"><i class="fa fa-undo"></i></div><div class="lab">Refunds Issued</div></div><div class="delta"><?= h($trendLabel) ?></div></div>
          <div class="val"><?= number_format($kpiRefunds) ?></div><div class="sub">Refunded returns</div>
        </a>
      </div>

      <nav class="od-tabs" aria-label="Order status">
        <a href="<?= h($href(['status' => 'all'])) ?>" class="<?= $statusFilter === 'all' ? 'is-active' : '' ?>">All Orders<span class="cnt">(<?= (int)$counts['all'] ?>)</span></a>
        <a href="<?= h($href(['status' => 'pending'])) ?>" class="<?= $statusFilter === 'pending' ? 'is-active' : '' ?>">Pending<span class="cnt">(<?= (int)$counts['pending'] ?>)</span></a>
        <a href="<?= h($href(['status' => 'processing'])) ?>" class="<?= $statusFilter === 'processing' ? 'is-active' : '' ?>">Processing<span class="cnt">(<?= (int)$counts['processing'] ?>)</span></a>
        <a href="<?= h($href(['status' => 'shipped'])) ?>" class="<?= $statusFilter === 'shipped' ? 'is-active' : '' ?>">Shipped<span class="cnt">(<?= (int)$counts['shipped'] ?>)</span></a>
        <a href="<?= h($href(['status' => 'delivered'])) ?>" class="<?= $statusFilter === 'delivered' ? 'is-active' : '' ?>">Delivered<span class="cnt">(<?= (int)$counts['delivered'] ?>)</span></a>
        <a href="<?= h($href(['status' => 'canceled'])) ?>" class="<?= $statusFilter === 'canceled' ? 'is-active' : '' ?>">Canceled<span class="cnt">(<?= (int)$counts['canceled'] ?>)</span></a>
        <a href="<?= h($href(['status' => 'refunded'])) ?>" class="<?= $statusFilter === 'refunded' ? 'is-active' : '' ?>">Refunded<span class="cnt">(<?= (int)$counts['refunded'] ?>)</span></a>
        <span class="spacer"></span>
        <select id="odBulk" aria-label="Bulk actions" style="height:28px;border:1px solid #e2e8f0;border-radius:8px;font-size:11px;font-weight:700;margin:4px 6px;padding:0 8px;">
          <option value="">Bulk Actions</option>
          <option value="noop" disabled>Coming soon</option>
        </select>
      </nav>

      <div class="od-main">
        <form class="od-filters" id="odFilters" method="get">
          <select name="status" aria-label="Status" onchange="this.form.submit()">
            <option value="all"<?= $statusFilter === 'all' ? ' selected' : '' ?>>All Statuses</option>
            <?php foreach ($statusLabels as $k => $lab): ?>
              <option value="<?= h($k) ?>"<?= $statusFilter === $k ? ' selected' : '' ?>><?= h($lab) ?></option>
            <?php endforeach; ?>
          </select>
          <select name="payment" aria-label="Payment status" onchange="this.form.submit()">
            <option value="all"<?= $payFilter === 'all' ? ' selected' : '' ?>>All Payment Statuses</option>
            <?php foreach ($payLabels as $k => $lab): ?>
              <option value="<?= h($k) ?>"<?= $payFilter === $k ? ' selected' : '' ?>><?= h($lab) ?></option>
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
          <input type="date" name="from" value="<?= h($dateFrom) ?>" aria-label="From" onchange="this.form.submit()">
          <input type="date" name="to" value="<?= h($dateTo) ?>" aria-label="To" onchange="this.form.submit()">
          <div class="od-search">
            <i class="fa fa-search"></i>
            <input type="search" name="q" value="<?= h($q) ?>" placeholder="Search orders..." autocomplete="off">
          </div>
          <button type="submit" class="od-btn">Filter</button>
          <select id="odPageLen" aria-label="Per page">
            <option value="10" selected>10 / page</option>
            <option value="25">25 / page</option>
            <option value="50">50 / page</option>
            <option value="100">100 / page</option>
          </select>
        </form>

        <div class="od-table-wrap">
          <table id="datatable1" class="od-table display" style="width:100%;">
            <thead>
              <tr>
                <th style="width:28px;"><input type="checkbox" id="odSelectAll"></th>
                <th>Order ID</th>
                <th>Date</th>
                <th>Buyer</th>
                <th>Seller (Brands)</th>
                <th>Product</th>
                <th>Amount</th>
                <th>Payment</th>
                <th>Status</th>
                <th>Contact</th>
                <th>Address</th>
                <th>Delivery</th>
                <th style="width:88px;">Actions</th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($orders as $row):
                $id = (int)($row['id'] ?? 0);
                $code = trim((string)($row['order_code'] ?? ''));
                if ($code === '') {
                    $code = 'ORD-' . str_pad((string)$id, 6, '0', STR_PAD_LEFT);
                }
                $txn = trim((string)($row['stripe_payment_intent_id'] ?? ''));
                if ($txn === '') {
                    $txn = '#' . (1000000 + $id);
                } elseif ($txn[0] !== '#') {
                    $txn = '#' . $txn;
                }

                $buyerName = trim((string)($row['buyer_name'] ?? ''));
                $buyerUser = trim((string)($row['buyer_username'] ?? ''));
                if ($buyerName === '') {
                    $buyerName = $buyerUser !== '' ? $buyerUser : (trim((string)($row['buyer_email'] ?? '')) ?: 'Guest');
                }
                $buyerHandle = $buyerUser !== '' ? '@' . $buyerUser : (trim((string)($row['buyer_email'] ?? '')) ?: '—');

                $brandName = trim((string)($row['brand_name'] ?? ''));
                $orgName = trim((string)($row['org_name'] ?? ''));
                $sellerDisplay = $brandName !== '' ? $brandName : ($orgName !== '' ? $orgName : 'Unknown seller');
                $sellerUser = trim((string)($row['seller_username'] ?? ''));
                if ($sellerUser === '') {
                    $sellerUser = trim((string)($row['manager_username'] ?? ''));
                }
                $sellerHandle = $sellerUser !== '' ? '@' . $sellerUser : ($brandName !== '' ? '@' . preg_replace('/\s+/', '', mb_strtolower($brandName)) : '—');
                $sellerSub = $orgName !== '' && $brandName !== '' && strcasecmp($orgName, $brandName) !== 0
                    ? $orgName
                    : $sellerHandle;

                $mgrName = trim((string)($row['manager_fullname'] ?? ''));
                $contactBits = array_filter([
                    trim((string)($row['buyer_phone'] ?? '')),
                    trim((string)($row['buyer_email'] ?? '')),
                ]);
                $contact = $contactBits ? implode(' · ', $contactBits) : '—';
                $address = trim((string)($row['delivery_address'] ?? ''));
                $cover = function_exists('org_shop_cover_url') ? org_shop_cover_url((string)($row['product_cover'] ?? '')) : '';
                $prodTitle = trim((string)($row['product_title'] ?? 'Product'));
                $productId = (int)($row['product_id'] ?? 0);
                $prodCode = trim((string)($row['product_code'] ?? ''));
                if ($prodCode === '' && $productId > 0 && function_exists('org_shop_product_code_from_id')) {
                    $prodCode = org_shop_product_code_from_id($productId);
                }
                if ($prodCode === '' && $productId > 0) {
                    $prodCode = 'PRD-' . $productId;
                }
                $qty = max(1, (int)($row['quantity'] ?? 1));
                $deliveryOpt = str_replace('_', ' ', (string)($row['delivery_option'] ?? ''));
                $prodMeta = 'Qty: ' . $qty . ($deliveryOpt !== '' ? ' | ' . ucwords($deliveryOpt) : '');

                $ui = (string)$row['_ui_status'];
                $pay = (string)$row['_ui_payment'];
                $deliveryAt = (string)($row['delivered_at'] ?? $row['shipped_at'] ?? '');
                $detailHref = $href(['id' => $id]);
                $orderDetailHref = 'open_order_detail.php?id=' . $id;
            ?>
              <tr>
                <td><input type="checkbox" class="od-row-check" value="<?= $id ?>"></td>
                <td>
                  <div class="od-id"><a class="od-id-link" href="<?= h($orderDetailHref) ?>" title="Open order detail"><?= h($code) ?></a></div>
                  <div class="od-id-sub"><?= h(truncate_text($txn, 22)) ?></div>
                </td>
                <td><div class="od-sub" style="color:#475569;font-weight:600;"><?= h(fmt_dt($row['created_at'] ?? '')) ?></div></td>
                <td>
                  <div class="od-person">
                    <span class="od-av" style="background:<?= h(avatarColor($buyerHandle . $buyerName)) ?>;"><?= h(initials2($buyerName)) ?></span>
                    <div style="min-width:0;">
                      <div class="od-name"><?= h($buyerName) ?></div>
                      <div class="od-sub"><?= h($buyerHandle) ?></div>
                    </div>
                  </div>
                </td>
                <td>
                  <div class="od-person">
                    <span class="od-av" style="background:<?= h(trim((string)($row['brand_color'] ?? '')) ?: avatarColor($sellerDisplay)) ?>;">
                      <?= h(trim((string)($row['brand_letter'] ?? '')) !== '' ? strtoupper((string)$row['brand_letter']) : initials2($sellerDisplay)) ?>
                    </span>
                    <div style="min-width:0;">
                      <div class="od-name"><?= h($sellerDisplay) ?></div>
                      <div class="od-sub"><?= h($sellerSub) ?><?= $mgrName !== '' ? ' · ' . h($mgrName) : '' ?></div>
                    </div>
                  </div>
                </td>
                <td>
                  <div class="od-prod">
                    <?php if ($cover !== ''): ?>
                      <img class="od-thumb" src="<?= h($cover) ?>" alt="">
                    <?php else: ?>
                      <span class="od-thumb ph"><i class="fa fa-image"></i></span>
                    <?php endif; ?>
                    <div style="min-width:0;">
                      <div class="od-name"><?= h($prodTitle) ?></div>
                      <div class="od-sub">
                        <?php if ($prodCode !== ''): ?>
                          <a class="od-id-link" href="<?= h($orderDetailHref) ?>" title="Open order detail">Product ID <?= h($prodCode) ?></a>
                          <span style="color:#94a3b8;"> · </span>
                        <?php endif; ?>
                        <?= h($prodMeta) ?>
                      </div>
                    </div>
                  </div>
                </td>
                <td><span class="od-amt"><?= h(money_fmt((int)($row['total_cents'] ?? 0), (string)($row['currency'] ?? 'USD'))) ?></span></td>
                <td><span class="od-pill <?= h($pay) ?>"><?= h($payLabels[$pay] ?? ucfirst($pay)) ?></span></td>
                <td><span class="od-pill <?= h($ui) ?>"><?= h($statusLabels[$ui] ?? ucfirst($ui)) ?></span></td>
                <td><div class="od-sub" style="color:#475569;font-weight:600;max-width:140px;" title="<?= h($contact) ?>"><?= h(truncate_text($contact, 36)) ?></div></td>
                <td><div class="od-sub" style="color:#475569;font-weight:600;max-width:160px;" title="<?= h($address) ?>"><?= h(truncate_text($address !== '' ? $address : '—', 40)) ?></div></td>
                <td><div class="od-sub" style="color:#475569;font-weight:600;"><?= h($deliveryAt !== '' ? fmt_dt($deliveryAt) : '—') ?></div></td>
                <td>
                  <div class="od-acts">
                    <a class="od-view" href="<?= h($orderDetailHref) ?>" title="View order detail"><i class="fa fa-eye"></i></a>
                    <div class="fries-menu">
                      <button type="button" class="fries-toggle" title="Actions" aria-label="Actions" aria-haspopup="true"><span class="fries-icon" aria-hidden="true"></span></button>
                      <div class="fries-dropdown" role="menu">
                        <a class="fries-item" role="menuitem" href="<?= h($orderDetailHref) ?>"><i class="fa fa-eye"></i> Order detail</a>
                        <a class="fries-item" role="menuitem" href="<?= h($detailHref) ?>"><i class="fa fa-list"></i> Quick panel</a>
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

        <div class="od-foot">
          <div class="muted" id="odShowing">Showing 0 orders</div>
          <div id="odPagerHost"></div>
          <div class="muted"><span id="visibleOrderCount"><?= (int)count($orders) ?></span> in this view</div>
        </div>
      </div>
    </div>
  </div>
</div>

<?php if ($detailOrder):
    $d = $detailOrder;
    $dUi = (string)$d['_ui_status'];
    $dPay = (string)$d['_ui_payment'];
    $dBrand = trim((string)($d['brand_name'] ?? '')) ?: '—';
    $dOrg = trim((string)($d['org_name'] ?? '')) ?: '—';
    $dSellerName = trim((string)($d['manager_fullname'] ?? '')) ?: (trim((string)($d['seller_username'] ?? '')) ?: $dOrg);
    $dSellerContact = implode(' · ', array_filter([
        trim((string)($d['manager_email'] ?? '')),
        trim((string)($d['seller_user_email'] ?? '')),
    ])) ?: '—';
    $closeHref = $href(['id' => '']);
?>
<div class="od-drawer is-open" id="odDrawer" role="dialog" aria-modal="true" aria-label="Order details">
  <button type="button" class="od-drawer-bg" onclick="location.href='<?= h($closeHref) ?>'" aria-label="Close"></button>
  <div class="od-drawer-panel">
    <div class="od-drawer-head">
      <div>
        <h3><?= h(trim((string)($d['order_code'] ?? '')) ?: ('ORD-' . (int)$d['id'])) ?></h3>
        <div class="sub"><?= h(fmt_dt($d['created_at'] ?? '')) ?> · <span class="od-pill <?= h($dUi) ?>"><?= h($statusLabels[$dUi] ?? $dUi) ?></span></div>
      </div>
      <a class="od-drawer-close" href="<?= h($closeHref) ?>" title="Close" style="display:inline-flex;align-items:center;justify-content:center;text-decoration:none;color:#0f172a;"><i class="fa fa-times"></i></a>
    </div>
    <div class="od-drawer-body">
      <div class="od-sec">
        <h4>Buyer</h4>
        <div class="od-kv">
          <div class="k">Name</div><div class="v"><?= h(trim((string)($d['buyer_name'] ?? '')) ?: '—') ?></div>
          <div class="k">Username</div><div class="v"><?= h(trim((string)($d['buyer_username'] ?? '')) !== '' ? '@' . $d['buyer_username'] : '—') ?></div>
          <div class="k">Contact</div><div class="v"><?= h(implode(' · ', array_filter([trim((string)($d['buyer_phone'] ?? '')), trim((string)($d['buyer_email'] ?? ''))])) ?: '—') ?></div>
          <div class="k">Address</div><div class="v"><?= h(trim((string)($d['delivery_address'] ?? '')) ?: '—') ?></div>
        </div>
      </div>
      <div class="od-sec">
        <h4>Seller &amp; brand</h4>
        <div class="od-kv">
          <div class="k">Brand</div><div class="v"><?= h($dBrand) ?></div>
          <div class="k">Seller</div><div class="v"><?= h($dSellerName) ?></div>
          <div class="k">Organization</div><div class="v"><?= h($dOrg) ?><?= trim((string)($d['org_code'] ?? '')) !== '' ? ' (' . h((string)$d['org_code']) . ')' : '' ?></div>
          <div class="k">Handle</div><div class="v"><?= h(trim((string)($d['seller_username'] ?? '')) !== '' ? '@' . $d['seller_username'] : '—') ?></div>
          <div class="k">Contact</div><div class="v"><?= h($dSellerContact) ?></div>
        </div>
      </div>
      <div class="od-sec">
        <h4>Product &amp; payment</h4>
        <div class="od-kv">
          <div class="k">Product</div><div class="v"><?= h(trim((string)($d['product_title'] ?? '')) ?: '—') ?></div>
          <div class="k">Qty</div><div class="v"><?= (int)($d['quantity'] ?? 1) ?></div>
          <div class="k">Amount</div><div class="v"><?= h(money_fmt((int)($d['total_cents'] ?? 0), (string)($d['currency'] ?? 'USD'))) ?></div>
          <div class="k">Payment</div><div class="v"><span class="od-pill <?= h($dPay) ?>"><?= h($payLabels[$dPay] ?? $dPay) ?></span></div>
          <div class="k">Delivery</div><div class="v"><?= h(ucwords(str_replace('_', ' ', (string)($d['delivery_option'] ?? '—')))) ?></div>
          <div class="k">Tracking</div><div class="v"><?= h(trim((string)($d['tracking_number'] ?? '')) ?: '—') ?><?= trim((string)($d['carrier'] ?? '')) !== '' ? ' (' . h((string)$d['carrier']) . ')' : '' ?></div>
          <div class="k">Notes</div><div class="v"><?= h(trim((string)($d['buyer_notes'] ?? $d['seller_notes'] ?? '')) ?: '—') ?></div>
        </div>
      </div>
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
  var hasRows = <?= count($orders) > 0 ? 'true' : 'false' ?>;
  if (!hasRows) {
    $('#odShowing').text('Showing 0 orders');
    return;
  }
  var dt = $('#datatable1').DataTable({
    paging: true,
    pageLength: 10,
    lengthChange: false,
    info: true,
    autoWidth: false,
    order: [[2, 'desc']],
    columnDefs: [{ orderable: false, targets: [0, 12] }],
    dom: 'tp',
    language: { paginate: { previous: '‹', next: '›' } },
    drawCallback: function() {
      var info = this.api().page.info();
      var from = info.recordsDisplay === 0 ? 0 : (info.start + 1);
      $('#odShowing').text('Showing ' + from + ' to ' + info.end + ' of ' + info.recordsDisplay + ' orders.');
      $('#visibleOrderCount').text(info.recordsDisplay);
      var $pag = $(this.api().table().container()).find('.dataTables_paginate');
      if ($pag.length) $('#odPagerHost').empty().append($pag);
    }
  });
  setTimeout(function(){ var $pag=$('#datatable1_paginate'); if($pag.length) $('#odPagerHost').empty().append($pag); }, 0);
  $('#odPageLen').on('change', function(){ dt.page.len(parseInt(this.value,10)||10).draw(); });
  $('#odSelectAll').on('change', function(){ $('.od-row-check').prop('checked', this.checked); });
});
</script>
</body>
</html>
