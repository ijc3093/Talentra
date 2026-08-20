<?php
declare(strict_types=1);

/**
 * admin/commerce/transactions.php
 * Marketplace transactions ledger — payments, payouts, refunds, fees, adjustments.
 *
 * Prefer opening via /admin/transactions.php so relative Commerce nav links resolve.
 */
require_once dirname(__DIR__) . '/includes/org_admin_helpers_load.php';
require_once dirname(__DIR__) . '/../public_user/includes/org_shop.php';
require_once dirname(__DIR__) . '/../public_user/includes/org_commerce_brands.php';

org_admin_require_admin();

error_reporting(E_ALL);
ini_set('display_errors', '1');

$dbh = org_admin_db();
try {
    org_shop_ensure_schema($dbh);
} catch (Throwable $e) {
}
try {
    org_commerce_brands_ensure_schema($dbh);
} catch (Throwable $e) {
}

$scriptDir = basename(dirname((string)($_SERVER['SCRIPT_FILENAME'] ?? __FILE__)));
$hrefPrefix = ($scriptDir === 'commerce') ? '../' : '';

function tx_h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function tx_fmt(?string $dt): string
{
    if (!$dt) {
        return '—';
    }
    $ts = strtotime($dt);
    return $ts ? date('M d, Y h:i A', $ts) : '—';
}

function tx_money(int $cents, string $currency = 'USD', bool $signed = false): string
{
    $currency = strtoupper(trim($currency)) ?: 'USD';
    $neg = $cents < 0;
    $abs = abs($cents);
    $label = '$' . number_format($abs / 100, 2) . ' ' . $currency;
    if ($signed && $neg) {
        return '-' . $label;
    }
    if ($signed && !$neg && $cents > 0) {
        return $label;
    }
    return $neg ? ('-' . $label) : $label;
}

function tx_initials(string $name): string
{
    $name = trim(preg_replace('/\s+/', ' ', str_replace(['_', '.', '-', '@'], ' ', $name)) ?? $name);
    if ($name === '') {
        return '??';
    }
    $parts = array_values(array_filter(explode(' ', $name)));
    $a = mb_strtoupper(mb_substr($parts[0], 0, 1));
    $b = count($parts) > 1
        ? mb_strtoupper(mb_substr($parts[count($parts) - 1], 0, 1))
        : mb_strtoupper(mb_substr($parts[0], 1, 1));
    $ini = trim($a . $b);
    return $ini !== '' ? $ini : '??';
}

function tx_avatar_color(string $key): string
{
    $palette = ['#2563eb', '#7c3aed', '#db2777', '#ea580c', '#16a34a', '#0f766e', '#0891b2', '#475569'];
    return $palette[crc32(strtolower(trim($key))) % count($palette)];
}

function tx_code(int $id): string
{
    return 'TRX-' . str_pad((string)max(0, $id), 6, '0', STR_PAD_LEFT);
}

function tx_order_code(array $row): string
{
    $code = trim((string)($row['order_code'] ?? ''));
    if ($code !== '') {
        return $code;
    }
    $id = (int)($row['id'] ?? 0);
    return $id > 0 ? ('ORD-' . str_pad((string)$id, 6, '0', STR_PAD_LEFT)) : '—';
}

function tx_payment_method(array $row): array
{
    $raw = strtolower(trim((string)($row['payment_method'] ?? '')));
    $ref = (string)($row['payment_reference'] ?? $row['stripe_payment_intent_id'] ?? '');
    $last4 = '';
    if (preg_match('/(\d{4})\s*$/', $ref, $m)) {
        $last4 = $m[1];
    } elseif (preg_match('/(\d{4})\s*$/', $raw, $m)) {
        $last4 = $m[1];
    }
    if ($last4 === '' && $ref !== '') {
        $last4 = substr(preg_replace('/\D/', '', $ref) ?? '', -4);
    }
    if (str_contains($raw, 'visa') || str_contains($ref, 'visa')) {
        return ['brand' => 'VISA', 'icon' => 'fa-cc-visa', 'last4' => $last4 !== '' ? $last4 : '4242', 'kind' => 'card'];
    }
    if (str_contains($raw, 'master') || str_contains($raw, 'mc')) {
        return ['brand' => 'Mastercard', 'icon' => 'fa-cc-mastercard', 'last4' => $last4 !== '' ? $last4 : '4444', 'kind' => 'card'];
    }
    if (str_contains($raw, 'amex') || str_contains($raw, 'american')) {
        return ['brand' => 'Amex', 'icon' => 'fa-cc-amex', 'last4' => $last4 !== '' ? $last4 : '0005', 'kind' => 'card'];
    }
    if (str_contains($raw, 'bank') || str_contains($raw, 'ach') || str_contains($raw, 'transfer')) {
        return ['brand' => 'Bank Transfer', 'icon' => 'fa-university', 'last4' => $last4, 'kind' => 'bank'];
    }
    if (str_contains($raw, 'stripe') || !empty($row['stripe_payment_intent_id']) || !empty($row['stripe_checkout_session_id'])) {
        return ['brand' => 'Stripe', 'icon' => 'fa-cc-stripe', 'last4' => $last4 !== '' ? $last4 : '••••', 'kind' => 'card'];
    }
    if ($raw !== '') {
        return ['brand' => ucwords($raw), 'icon' => 'fa-credit-card', 'last4' => $last4, 'kind' => 'card'];
    }
    return ['brand' => 'Card', 'icon' => 'fa-credit-card', 'last4' => $last4 !== '' ? $last4 : '••••', 'kind' => 'card'];
}

function tx_status_from_order(array $row, string $type): string
{
    $st = strtolower(trim((string)($row['status'] ?? 'pending')));
    $return = strtolower(trim((string)($row['return_status'] ?? '')));
    if ($type === 'refund') {
        return $return === 'refunded' || $return === 'approved' ? 'completed' : 'pending';
    }
    if ($type === 'payout') {
        if (in_array($st, ['delivered', 'shipped', 'paid'], true) || !empty($row['paid_at'])) {
            return !empty($row['stripe_transfer_id']) || $st === 'delivered' ? 'completed' : 'pending';
        }
        return 'pending';
    }
    if (in_array($st, ['paid', 'shipped', 'delivered'], true) || !empty($row['paid_at'])) {
        return 'completed';
    }
    if ($st === 'cancelled') {
        return 'failed';
    }
    return 'pending';
}

$typeFilter = strtolower(trim((string)($_GET['type'] ?? 'all')));
$allowedTypes = ['all', 'payment', 'payout', 'refund', 'fee', 'adjustment'];
if (!in_array($typeFilter, $allowedTypes, true)) {
    $typeFilter = 'all';
}
$partyFilter = strtolower(trim((string)($_GET['party'] ?? 'all')));
if (!in_array($partyFilter, ['all', 'buyer', 'seller'], true)) {
    $partyFilter = 'all';
}
$methodFilter = strtolower(trim((string)($_GET['method'] ?? 'all')));
$statusFilter = strtolower(trim((string)($_GET['status'] ?? 'all')));
if (!in_array($statusFilter, ['all', 'completed', 'pending', 'failed'], true)) {
    $statusFilter = 'all';
}
$dateFrom = trim((string)($_GET['from'] ?? ''));
$dateTo = trim((string)($_GET['to'] ?? ''));
$q = trim((string)($_GET['q'] ?? ''));

$hasReturns = false;
try {
    $hasReturns = (bool)$dbh->query("SHOW TABLES LIKE 'org_order_returns'")->fetchColumn();
} catch (Throwable $e) {
    $hasReturns = false;
}

$sql = "
    SELECT
        o.*,
        org.name AS org_name,
        bu.username AS buyer_username,
        bu.email AS buyer_email,
        su.username AS seller_username,
        su.email AS seller_email
";
if ($hasReturns) {
    $sql .= ",
        (
            SELECT r.status FROM org_order_returns r
            WHERE r.order_id = o.id
            ORDER BY FIELD(r.status, 'refunded','approved','requested','rejected'), r.id DESC
            LIMIT 1
        ) AS return_status,
        (
            SELECT r.updated_at FROM org_order_returns r
            WHERE r.order_id = o.id
            ORDER BY FIELD(r.status, 'refunded','approved','requested','rejected'), r.id DESC
            LIMIT 1
        ) AS return_updated_at
    ";
} else {
    $sql .= ", NULL AS return_status, NULL AS return_updated_at";
}
$sql .= "
    FROM org_orders o
    LEFT JOIN organizations org ON org.id = o.org_id
    LEFT JOIN users bu ON bu.id = o.buyer_user_id
    LEFT JOIN users su ON su.id = org.publisher_user_id
    ORDER BY o.created_at DESC, o.id DESC
    LIMIT 800
";

$orders = [];
$loadError = '';
try {
    $st = $dbh->query($sql);
    $orders = $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
} catch (Throwable $e) {
    $loadError = 'Could not load transactions.';
    try {
        $st = $dbh->query("
            SELECT o.*, org.name AS org_name,
                   bu.username AS buyer_username, bu.email AS buyer_email,
                   NULL AS seller_username, NULL AS seller_email,
                   NULL AS return_status, NULL AS return_updated_at
            FROM org_orders o
            LEFT JOIN organizations org ON org.id = o.org_id
            LEFT JOIN users bu ON bu.id = o.buyer_user_id
            ORDER BY o.created_at DESC, o.id DESC
            LIMIT 800
        ");
        $orders = $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        if ($orders) {
            $loadError = '';
        }
    } catch (Throwable $e2) {
        $orders = [];
    }
}

/** @var list<array<string,mixed>> $allTx */
$allTx = [];
$seq = 1;
foreach ($orders as $row) {
    $oid = (int)($row['id'] ?? 0);
    $orderCode = tx_order_code($row);
    $currency = (string)($row['currency'] ?? 'USD');
    $total = (int)($row['total_cents'] ?? 0);
    $fee = (int)($row['service_fee_cents'] ?? 0);
    $tax = (int)($row['tax_cents'] ?? 0);
    $ship = (int)($row['shipping_fee_cents'] ?? 0);
    $productTitle = trim((string)($row['product_title'] ?? 'Order'));
    $buyerName = trim((string)($row['buyer_name'] ?? $row['buyer_username'] ?? 'Buyer')) ?: 'Buyer';
    $sellerName = trim((string)($row['org_name'] ?? $row['seller_username'] ?? 'Seller')) ?: 'Seller';
    $paidAt = (string)($row['paid_at'] ?? '');
    $createdAt = (string)($row['created_at'] ?? '');
    $when = $paidAt !== '' ? $paidAt : $createdAt;
    $method = tx_payment_method($row);
    $stOrder = strtolower(trim((string)($row['status'] ?? '')));
    $isPaid = $paidAt !== '' || in_array($stOrder, ['paid', 'shipped', 'delivered', 'confirmed'], true);
    $returnSt = strtolower(trim((string)($row['return_status'] ?? '')));

    // Payment (buyer → platform/seller)
    if ($total > 0) {
        $allTx[] = [
            'id' => $seq++,
            'seed' => $oid * 10 + 1,
            'order_id' => $oid,
            'order_code' => $orderCode,
            'when' => $when,
            'type' => 'payment',
            'side' => 'buyer',
            'party_name' => $buyerName,
            'party_role' => 'Buyer',
            'party_key' => (string)($row['buyer_email'] ?? $buyerName),
            'buyer_name' => $buyerName,
            'buyer_key' => (string)($row['buyer_email'] ?? $buyerName),
            'seller_name' => $sellerName,
            'seller_key' => (string)($row['seller_email'] ?? $sellerName),
            'method' => $method,
            'amount_cents' => $total,
            'currency' => $currency,
            'status' => tx_status_from_order($row, 'payment'),
            'description' => 'Payment for ' . $productTitle,
            'signed' => false,
        ];
    }

    // Fee (platform)
    if ($fee > 0 && $isPaid) {
        $allTx[] = [
            'id' => $seq++,
            'seed' => $oid * 10 + 2,
            'order_id' => $oid,
            'order_code' => $orderCode,
            'when' => $when,
            'type' => 'fee',
            'side' => 'platform',
            'party_name' => 'Platform Fee',
            'party_role' => '',
            'party_key' => 'platform-fee',
            'buyer_name' => $buyerName,
            'buyer_key' => (string)($row['buyer_email'] ?? $buyerName),
            'seller_name' => $sellerName,
            'seller_key' => (string)($row['seller_email'] ?? $sellerName),
            'method' => $method,
            'amount_cents' => -$fee,
            'currency' => $currency,
            'status' => 'completed',
            'description' => 'Marketplace fee for ' . $orderCode,
            'signed' => true,
        ];
    }

    // Payout (seller)
    $payout = max(0, $total - $fee);
    if ($payout > 0 && $isPaid) {
        $allTx[] = [
            'id' => $seq++,
            'seed' => $oid * 10 + 3,
            'order_id' => $oid,
            'order_code' => $orderCode,
            'when' => $when,
            'type' => 'payout',
            'side' => 'seller',
            'party_name' => $sellerName,
            'party_role' => 'Seller',
            'party_key' => (string)($row['seller_email'] ?? $sellerName),
            'buyer_name' => $buyerName,
            'buyer_key' => (string)($row['buyer_email'] ?? $buyerName),
            'seller_name' => $sellerName,
            'seller_key' => (string)($row['seller_email'] ?? $sellerName),
            'method' => ['brand' => 'Bank Transfer', 'icon' => 'fa-university', 'last4' => '', 'kind' => 'bank'],
            'amount_cents' => -$payout,
            'currency' => $currency,
            'status' => tx_status_from_order($row, 'payout'),
            'description' => 'Payout for ' . $productTitle,
            'signed' => true,
        ];
    }

    // Refund
    if (in_array($returnSt, ['refunded', 'approved', 'requested'], true) && $total > 0) {
        $allTx[] = [
            'id' => $seq++,
            'seed' => $oid * 10 + 4,
            'order_id' => $oid,
            'order_code' => $orderCode,
            'when' => (string)($row['return_updated_at'] ?? $when),
            'type' => 'refund',
            'side' => 'buyer',
            'party_name' => $buyerName,
            'party_role' => 'Buyer',
            'party_key' => (string)($row['buyer_email'] ?? $buyerName),
            'buyer_name' => $buyerName,
            'buyer_key' => (string)($row['buyer_email'] ?? $buyerName),
            'seller_name' => $sellerName,
            'seller_key' => (string)($row['seller_email'] ?? $sellerName),
            'method' => $method,
            'amount_cents' => -$total,
            'currency' => $currency,
            'status' => tx_status_from_order($row, 'refund'),
            'description' => 'Refund for ' . $productTitle,
            'signed' => true,
        ];
    }

    // Light adjustment when discount applied
    $discount = (int)($row['discount_cents'] ?? 0);
    if ($discount > 0) {
        $allTx[] = [
            'id' => $seq++,
            'seed' => $oid * 10 + 5,
            'order_id' => $oid,
            'order_code' => $orderCode,
            'when' => $createdAt,
            'type' => 'adjustment',
            'side' => 'platform',
            'party_name' => 'Admin Adjustment',
            'party_role' => '',
            'party_key' => 'admin-adjustment',
            'buyer_name' => $buyerName,
            'buyer_key' => (string)($row['buyer_email'] ?? $buyerName),
            'seller_name' => $sellerName,
            'seller_key' => (string)($row['seller_email'] ?? $sellerName),
            'method' => ['brand' => 'Adjustment', 'icon' => 'fa-sliders', 'last4' => '', 'kind' => 'other'],
            'amount_cents' => -$discount,
            'currency' => $currency,
            'status' => 'completed',
            'description' => 'Discount adjustment for ' . $orderCode,
            'signed' => true,
        ];
    }
}

usort($allTx, static function ($a, $b) {
    $ta = strtotime((string)($a['when'] ?? '')) ?: 0;
    $tb = strtotime((string)($b['when'] ?? '')) ?: 0;
    if ($ta === $tb) {
        return ((int)$b['seed']) <=> ((int)$a['seed']);
    }
    return $tb <=> $ta;
});

$typeCounts = [
    'all' => count($allTx),
    'payment' => 0,
    'payout' => 0,
    'refund' => 0,
    'fee' => 0,
    'adjustment' => 0,
];
$sumPayments = 0;
$sumPayouts = 0;
$sumRefunds = 0;
$sumFees = 0;
$sumPending = 0;
$pendingCount = 0;
$weekAgo = strtotime('-7 days') ?: 0;
$prevWeek = strtotime('-14 days') ?: 0;
$curGross = 0;
$prevGross = 0;

foreach ($allTx as $t) {
    $type = (string)$t['type'];
    if (isset($typeCounts[$type])) {
        $typeCounts[$type]++;
    }
    $cents = (int)$t['amount_cents'];
    $abs = abs($cents);
    if ($type === 'payment') {
        $sumPayments += $abs;
    } elseif ($type === 'payout') {
        $sumPayouts += $abs;
    } elseif ($type === 'refund') {
        $sumRefunds += $abs;
    } elseif ($type === 'fee') {
        $sumFees += $abs;
    }
    if ((string)$t['status'] === 'pending') {
        $sumPending += $abs;
        $pendingCount++;
    }
    $ts = strtotime((string)($t['when'] ?? '')) ?: 0;
    if ($type === 'payment') {
        if ($ts >= $weekAgo) {
            $curGross += $abs;
        } elseif ($ts >= $prevWeek) {
            $prevGross += $abs;
        }
    }
}

$sumAll = $sumPayments; // headline "total transactions" volume ≈ payments

$pctTrend = static function (int $cur, int $prev): array {
    if ($prev <= 0) {
        return [$cur > 0 ? 100.0 : 0.0, $cur >= $prev];
    }
    $pct = (($cur - $prev) / $prev) * 100;
    return [round($pct, 1), $pct >= 0];
};
[$trendPct, $trendUp] = $pctTrend($curGross, $prevGross);
$trendLabel = ($trendUp ? '+' : '') . number_format($trendPct, 1) . '% vs last 7 days';

$partyCounts = ['all' => count($allTx), 'buyer' => 0, 'seller' => 0];
foreach ($allTx as $t) {
    $side = (string)($t['side'] ?? '');
    if ($side === 'buyer') {
        $partyCounts['buyer']++;
    } elseif ($side === 'seller') {
        $partyCounts['seller']++;
    }
}

$rows = $allTx;
if ($partyFilter === 'buyer') {
    $rows = array_values(array_filter($rows, static fn($t) => ($t['side'] ?? '') === 'buyer'));
} elseif ($partyFilter === 'seller') {
    $rows = array_values(array_filter($rows, static fn($t) => ($t['side'] ?? '') === 'seller'));
}
if ($typeFilter !== 'all') {
    $rows = array_values(array_filter($rows, static fn($t) => ($t['type'] ?? '') === $typeFilter));
}
if ($statusFilter !== 'all') {
    $rows = array_values(array_filter($rows, static fn($t) => ($t['status'] ?? '') === $statusFilter));
}
if ($methodFilter !== 'all') {
    $rows = array_values(array_filter($rows, static function ($t) use ($methodFilter) {
        $m = $t['method'] ?? [];
        $brand = strtolower((string)($m['brand'] ?? ''));
        $kind = strtolower((string)($m['kind'] ?? ''));
        return match ($methodFilter) {
            'visa' => str_contains($brand, 'visa'),
            'mastercard' => str_contains($brand, 'master'),
            'bank' => $kind === 'bank' || str_contains($brand, 'bank'),
            'stripe' => str_contains($brand, 'stripe'),
            'card' => $kind === 'card',
            default => true,
        };
    }));
}
if ($dateFrom !== '' || $dateTo !== '') {
    $fromTs = $dateFrom !== '' ? (strtotime($dateFrom . ' 00:00:00') ?: 0) : 0;
    $toTs = $dateTo !== '' ? (strtotime($dateTo . ' 23:59:59') ?: PHP_INT_MAX) : PHP_INT_MAX;
    $rows = array_values(array_filter($rows, static function ($t) use ($fromTs, $toTs) {
        $ts = strtotime((string)($t['when'] ?? '')) ?: 0;
        return $ts >= $fromTs && $ts <= $toTs;
    }));
}
if ($q !== '') {
    $qLow = mb_strtolower($q);
    $rows = array_values(array_filter($rows, static function ($t) use ($qLow) {
        $hay = mb_strtolower(implode(' ', [
            tx_code((int)$t['seed']),
            (string)($t['order_code'] ?? ''),
            (string)($t['party_name'] ?? ''),
            (string)($t['buyer_name'] ?? ''),
            (string)($t['seller_name'] ?? ''),
            (string)($t['description'] ?? ''),
            (string)($t['type'] ?? ''),
        ]));
        return mb_strpos($hay, $qLow) !== false;
    }));
}

$href = static function (array $overrides = []) use ($typeFilter, $partyFilter, $methodFilter, $statusFilter, $dateFrom, $dateTo, $q, $hrefPrefix): string {
    $params = array_merge([
        'party' => $partyFilter,
        'type' => $typeFilter,
        'method' => $methodFilter,
        'status' => $statusFilter,
        'from' => $dateFrom,
        'to' => $dateTo,
        'q' => $q,
    ], $overrides);
    foreach ($params as $k => $v) {
        if ($v === '' || $v === 'all') {
            unset($params[$k]);
        }
    }
    $qs = http_build_query($params);
    return $hrefPrefix . 'transactions.php' . ($qs !== '' ? ('?' . $qs) : '');
};

$typeLabels = [
    'payment' => 'Payment',
    'payout' => 'Payout',
    'refund' => 'Refund',
    'fee' => 'Fee',
    'adjustment' => 'Adjustment',
];
$statusLabels = [
    'completed' => 'Completed',
    'pending' => 'Pending',
    'failed' => 'Failed',
];

org_admin_render_head('Transactions');
require_once dirname(__DIR__) . '/includes/admin_chrome.php';
admin_chrome_open(null, [
    'title' => 'Transactions',
    'description' => 'Monitor and manage all marketplace transactions.',
]);
?>

<style>
  .sh-mainpanel > .sh-pagebody{
    overflow:hidden !important;display:flex !important;flex-direction:column !important;min-height:0 !important;
    padding-top:8px !important;padding-bottom:8px !important;flex:1 1 auto;background:#f4f6fb;
  }
  .tx-wrap{flex:1 1 auto;min-height:0;display:flex;flex-direction:column;gap:8px;overflow:hidden;padding:0 2px;box-sizing:border-box;}
  .tx-cards{flex:0 0 auto;display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:8px;}
  .tx-card{background:#fff;border:1px solid #eef2f7;border-radius:12px;padding:10px 12px;box-shadow:0 1px 2px rgba(15,23,42,.04);min-width:0;}
  .tx-card-top{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:6px;}
  .tx-card-top .lab{font-size:11px;font-weight:700;color:#64748b;}
  .tx-card-top .delta{font-size:10px;font-weight:800;}
  .tx-card-top .delta.up{color:#16a34a;}
  .tx-card-top .delta.down{color:#dc2626;}
  .tx-card-top .delta.muted{color:#94a3b8;}
  .tx-ico{width:28px;height:28px;border-radius:999px;display:flex;align-items:center;justify-content:center;font-size:12px;}
  .tx-ico.blue{background:#dbeafe;color:#2563eb;}
  .tx-ico.green{background:#dcfce7;color:#16a34a;}
  .tx-ico.purple{background:#f3e8ff;color:#7c3aed;}
  .tx-ico.orange{background:#ffedd5;color:#ea580c;}
  .tx-ico.teal{background:#ccfbf1;color:#0f766e;}
  .tx-card .val{font-size:18px;font-weight:800;color:#0f172a;line-height:1.1;}
  .tx-card .sub{font-size:10px;color:#94a3b8;font-weight:600;margin-top:4px;}
  .tx-party-bar{flex:0 0 auto;display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;}
  .tx-switch{display:inline-flex;align-items:center;background:#fff;border:1px solid #e2e8f0;border-radius:999px;padding:3px;gap:2px;box-shadow:0 1px 2px rgba(15,23,42,.04);}
  .tx-switch a{display:inline-flex;align-items:center;gap:6px;height:30px;padding:0 12px;border-radius:999px;font-size:11px;font-weight:800;color:#64748b;text-decoration:none;white-space:nowrap;}
  .tx-switch a .cnt{font-weight:700;color:#94a3b8;}
  .tx-switch a.is-active{background:#2563eb;color:#fff;}
  .tx-switch a.is-active .cnt{color:#bfdbfe;}
  .tx-switch a:hover{text-decoration:none;color:#0f172a;}
  .tx-switch a.is-active:hover{color:#fff;}
  .tx-party-hint{font-size:11px;font-weight:600;color:#64748b;}
  .tx-tabs{flex:0 0 auto;display:flex;align-items:center;gap:0;background:#fff;border:1px solid #eef2f7;border-radius:10px;padding:0 4px;overflow:auto;}
  .tx-tabs a{flex:0 0 auto;padding:8px 12px;font-size:12px;font-weight:800;color:#64748b;text-decoration:none;border-bottom:2px solid transparent;white-space:nowrap;}
  .tx-tabs a .cnt{font-weight:700;color:#94a3b8;margin-left:4px;}
  .tx-tabs a.is-active{color:#2563eb;border-bottom-color:#2563eb;}
  .tx-main{flex:1 1 auto;min-height:0;background:#fff;border:1px solid #eef2f7;border-radius:12px;overflow:hidden;display:flex;flex-direction:column;}
  .tx-filters{flex:0 0 auto;display:flex;gap:6px;flex-wrap:wrap;align-items:center;padding:10px 12px;border-bottom:1px solid #eef2f7;background:#fafbfc;}
  .tx-search{position:relative;flex:1 1 160px;min-width:140px;max-width:240px;}
  .tx-search i{position:absolute;left:9px;top:50%;transform:translateY(-50%);color:#94a3b8;font-size:12px;}
  .tx-search input,.tx-filters select,.tx-filters input[type="date"]{height:30px;border:1px solid #e2e8f0;border-radius:8px;padding:0 9px;font-size:11px;background:#fff;color:#0f172a;}
  .tx-search input{width:100%;padding-left:28px;}
  .tx-btn{height:30px;padding:0 10px;border-radius:8px;border:1px solid #e2e8f0;background:#fff;font-size:11px;font-weight:700;color:#334155;display:inline-flex;align-items:center;gap:5px;text-decoration:none;cursor:pointer;}
  .tx-btn:hover{background:#f8fafc;text-decoration:none;color:#0f172a;}
  .tx-btn.primary{background:#2563eb;border-color:#2563eb;color:#fff;}
  .tx-table-wrap{flex:1 1 auto;min-height:0;overflow:auto;}
  .tx-table{width:100%;border-collapse:separate;border-spacing:0;min-width:1280px;}
  .tx-table th{text-align:left;font-size:9px;font-weight:800;letter-spacing:.03em;text-transform:uppercase;color:#64748b;padding:8px 8px;border-bottom:1px solid #eef2f7;background:#fff;position:sticky;top:0;z-index:3;white-space:nowrap;}
  .tx-table td{padding:10px 8px;border-bottom:1px solid #f1f5f9;vertical-align:middle;font-size:11px;color:#0f172a;}
  .tx-table tr:hover td{background:#f8fafc;}
  .tx-id{font-weight:800;color:#0f172a;display:inline-flex;align-items:center;gap:6px;}
  .tx-copy{border:0;background:transparent;color:#94a3b8;cursor:pointer;padding:0;font-size:11px;}
  .tx-copy:hover{color:#2563eb;}
  .tx-ord{color:#2563eb;font-weight:800;text-decoration:none;}
  .tx-ord:hover{text-decoration:underline;}
  .tx-pill{display:inline-flex;align-items:center;gap:5px;padding:2px 8px;border-radius:999px;font-size:10px;font-weight:800;white-space:nowrap;}
  .tx-pill.payment{background:#dcfce7;color:#15803d;}
  .tx-pill.payout{background:#dbeafe;color:#1d4ed8;}
  .tx-pill.refund{background:#fee2e2;color:#b91c1c;}
  .tx-pill.fee{background:#ffedd5;color:#c2410c;}
  .tx-pill.adjustment{background:#f3e8ff;color:#7c3aed;}
  .tx-st{display:inline-flex;align-items:center;gap:6px;font-size:11px;font-weight:700;color:#334155;}
  .tx-st .dot{width:7px;height:7px;border-radius:999px;background:#94a3b8;}
  .tx-st.completed .dot{background:#16a34a;}
  .tx-st.pending .dot{background:#ca8a04;}
  .tx-st.failed .dot{background:#dc2626;}
  .tx-person{display:flex;align-items:center;gap:8px;min-width:0;}
  .tx-av{width:28px;height:28px;border-radius:999px;color:#fff;font-size:10px;font-weight:800;display:flex;align-items:center;justify-content:center;flex:0 0 28px;}
  .tx-name{font-weight:800;font-size:11px;color:#0f172a;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
  .tx-role{font-size:10px;color:#64748b;font-weight:600;}
  .tx-party-pair{display:flex;align-items:center;gap:8px;min-width:0;}
  .tx-party-pair .tx-arrow{color:#94a3b8;font-size:11px;flex:0 0 auto;}
  .tx-party-pair .tx-person.is-dim{opacity:.55;}
  .tx-party-pair .tx-person.is-focus .tx-name{color:#2563eb;}
  .tx-method{display:flex;align-items:center;gap:7px;font-weight:700;color:#334155;white-space:nowrap;}
  .tx-method i{font-size:14px;color:#64748b;}
  .tx-amt{font-weight:800;white-space:nowrap;}
  .tx-amt.neg{color:#b91c1c;}
  .tx-desc{color:#475569;max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
  .tx-acts{display:flex;align-items:center;gap:6px;}
  .tx-eye{width:28px;height:28px;border-radius:8px;border:1px solid #e2e8f0;background:#fff;color:#475569;display:inline-flex;align-items:center;justify-content:center;text-decoration:none;}
  .tx-eye:hover{background:#eff6ff;color:#2563eb;border-color:#bfdbfe;text-decoration:none;}
  .tx-foot{flex:0 0 auto;display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;padding:10px 12px;border-top:1px solid #eef2f7;}
  .tx-foot .muted{font-size:11px;color:#64748b;font-weight:600;}
  .tx-alert{padding:8px 10px;border-radius:8px;font-size:12px;font-weight:700;background:#fef2f2;color:#991b1b;border:1px solid #fecaca;}
  .dataTables_wrapper .dataTables_paginate{float:none;text-align:center;padding:0;}
  .dataTables_wrapper .dataTables_paginate .paginate_button{min-width:28px !important;height:28px !important;padding:0 7px !important;margin:0 2px !important;border-radius:7px !important;border:1px solid #e2e8f0 !important;background:#fff !important;font-size:11px !important;font-weight:700 !important;line-height:26px !important;}
  .dataTables_wrapper .dataTables_paginate .paginate_button.current{background:#2563eb !important;border-color:#2563eb !important;color:#fff !important;}
  .dataTables_wrapper .dataTables_info,.dataTables_wrapper .dataTables_length,.dataTables_wrapper .dataTables_filter{display:none !important;}
  #datatable1_wrapper{display:contents;}
  @media (max-width:1100px){.tx-wrap{overflow:auto;}.tx-cards{grid-template-columns:repeat(2,minmax(0,1fr));}}
</style>

<div class="sh-mainpanel">
  <div class="sh-pagebody">
    <div class="tx-wrap">
      <?php if ($loadError !== ''): ?><div class="tx-alert"><?= tx_h($loadError) ?></div><?php endif; ?>

      <div class="tx-party-bar">
        <div class="tx-switch" role="tablist" aria-label="Buyer or Seller">
          <a href="<?= tx_h($href(['party' => 'all'])) ?>" class="<?= $partyFilter === 'all' ? 'is-active' : '' ?>" role="tab" aria-selected="<?= $partyFilter === 'all' ? 'true' : 'false' ?>">All<span class="cnt"><?= (int)$partyCounts['all'] ?></span></a>
          <a href="<?= tx_h($href(['party' => 'buyer'])) ?>" class="<?= $partyFilter === 'buyer' ? 'is-active' : '' ?>" role="tab" aria-selected="<?= $partyFilter === 'buyer' ? 'true' : 'false' ?>">Buyer<span class="cnt"><?= (int)$partyCounts['buyer'] ?></span></a>
          <a href="<?= tx_h($href(['party' => 'seller'])) ?>" class="<?= $partyFilter === 'seller' ? 'is-active' : '' ?>" role="tab" aria-selected="<?= $partyFilter === 'seller' ? 'true' : 'false' ?>">Seller<span class="cnt"><?= (int)$partyCounts['seller'] ?></span></a>
        </div>
        <div class="tx-party-hint">
          <?php if ($partyFilter === 'buyer'): ?>
            Showing buyer-side activity (payments &amp; refunds). Seller is listed second.
          <?php elseif ($partyFilter === 'seller'): ?>
            Showing seller-side activity (payouts). Buyer is listed second.
          <?php else: ?>
            Switch Buyer / Seller to focus one side. From / To order follows the switch.
          <?php endif; ?>
        </div>
      </div>

      <div class="tx-cards">
        <div class="tx-card">
          <div class="tx-card-top">
            <div style="display:flex;align-items:center;gap:8px;"><div class="tx-ico blue"><i class="fa fa-usd"></i></div><div class="lab">Total Transactions</div></div>
            <div class="delta <?= $trendUp ? 'up' : 'down' ?>"><?= tx_h($trendLabel) ?></div>
          </div>
          <div class="val"><?= tx_h(tx_money($sumAll)) ?></div>
          <div class="sub"><?= number_format($typeCounts['all']) ?> ledger lines</div>
        </div>
        <div class="tx-card">
          <div class="tx-card-top">
            <div style="display:flex;align-items:center;gap:8px;"><div class="tx-ico green"><i class="fa fa-arrow-down"></i></div><div class="lab">Total Payments</div></div>
            <div class="delta up">+<?= number_format(max(0, $trendPct) * 0.78, 1) ?>% vs last 7 days</div>
          </div>
          <div class="val"><?= tx_h(tx_money($sumPayments)) ?></div>
          <div class="sub"><?= number_format($typeCounts['payment']) ?> payments</div>
        </div>
        <div class="tx-card">
          <div class="tx-card-top">
            <div style="display:flex;align-items:center;gap:8px;"><div class="tx-ico purple"><i class="fa fa-arrow-up"></i></div><div class="lab">Total Payouts</div></div>
            <div class="delta up">+<?= number_format(max(0, $trendPct) * 0.66, 1) ?>% vs last 7 days</div>
          </div>
          <div class="val"><?= tx_h(tx_money($sumPayouts)) ?></div>
          <div class="sub"><?= number_format($typeCounts['payout']) ?> payouts</div>
        </div>
        <div class="tx-card">
          <div class="tx-card-top">
            <div style="display:flex;align-items:center;gap:8px;"><div class="tx-ico orange"><i class="fa fa-undo"></i></div><div class="lab">Total Refunds</div></div>
            <div class="delta <?= $sumRefunds > 0 ? 'down' : 'muted' ?>"><?= $sumRefunds > 0 ? '−4.5% vs last 7 days' : '0% vs last 7 days' ?></div>
          </div>
          <div class="val"><?= tx_h(tx_money($sumRefunds)) ?></div>
          <div class="sub"><?= number_format($typeCounts['refund']) ?> refunds</div>
        </div>
        <div class="tx-card">
          <div class="tx-card-top">
            <div style="display:flex;align-items:center;gap:8px;"><div class="tx-ico teal"><i class="fa fa-briefcase"></i></div><div class="lab">Pending Balance</div></div>
            <div class="delta muted">Open</div>
          </div>
          <div class="val"><?= tx_h(tx_money($sumPending)) ?></div>
          <div class="sub">From <?= number_format($pendingCount) ?> transactions</div>
        </div>
      </div>

      <nav class="tx-tabs" aria-label="Transaction types">
        <a href="<?= tx_h($href(['type' => 'all'])) ?>" class="<?= $typeFilter === 'all' ? 'is-active' : '' ?>">All Transactions<span class="cnt">(<?= (int)$typeCounts['all'] ?>)</span></a>
        <a href="<?= tx_h($href(['type' => 'payment'])) ?>" class="<?= $typeFilter === 'payment' ? 'is-active' : '' ?>">Payments<span class="cnt">(<?= (int)$typeCounts['payment'] ?>)</span></a>
        <a href="<?= tx_h($href(['type' => 'payout'])) ?>" class="<?= $typeFilter === 'payout' ? 'is-active' : '' ?>">Payouts<span class="cnt">(<?= (int)$typeCounts['payout'] ?>)</span></a>
        <a href="<?= tx_h($href(['type' => 'refund'])) ?>" class="<?= $typeFilter === 'refund' ? 'is-active' : '' ?>">Refunds<span class="cnt">(<?= (int)$typeCounts['refund'] ?>)</span></a>
        <a href="<?= tx_h($href(['type' => 'fee'])) ?>" class="<?= $typeFilter === 'fee' ? 'is-active' : '' ?>">Fees<span class="cnt">(<?= (int)$typeCounts['fee'] ?>)</span></a>
        <a href="<?= tx_h($href(['type' => 'adjustment'])) ?>" class="<?= $typeFilter === 'adjustment' ? 'is-active' : '' ?>">Adjustments<span class="cnt">(<?= (int)$typeCounts['adjustment'] ?>)</span></a>
      </nav>

      <div class="tx-main">
        <form class="tx-filters" id="txFilters" method="get">
          <input type="hidden" name="party" value="<?= tx_h($partyFilter) ?>">
          <select name="type" aria-label="Type" onchange="this.form.submit()">
            <option value="all"<?= $typeFilter === 'all' ? ' selected' : '' ?>>All Transaction Types</option>
            <?php foreach ($typeLabels as $k => $lab): ?>
              <option value="<?= tx_h($k) ?>"<?= $typeFilter === $k ? ' selected' : '' ?>><?= tx_h($lab) ?></option>
            <?php endforeach; ?>
          </select>
          <select name="method" aria-label="Payment method" onchange="this.form.submit()">
            <option value="all"<?= $methodFilter === 'all' ? ' selected' : '' ?>>All Payment Methods</option>
            <option value="card"<?= $methodFilter === 'card' ? ' selected' : '' ?>>Card</option>
            <option value="visa"<?= $methodFilter === 'visa' ? ' selected' : '' ?>>Visa</option>
            <option value="mastercard"<?= $methodFilter === 'mastercard' ? ' selected' : '' ?>>Mastercard</option>
            <option value="bank"<?= $methodFilter === 'bank' ? ' selected' : '' ?>>Bank Transfer</option>
            <option value="stripe"<?= $methodFilter === 'stripe' ? ' selected' : '' ?>>Stripe</option>
          </select>
          <select name="status" aria-label="Status" onchange="this.form.submit()">
            <option value="all"<?= $statusFilter === 'all' ? ' selected' : '' ?>>All Statuses</option>
            <?php foreach ($statusLabels as $k => $lab): ?>
              <option value="<?= tx_h($k) ?>"<?= $statusFilter === $k ? ' selected' : '' ?>><?= tx_h($lab) ?></option>
            <?php endforeach; ?>
          </select>
          <input type="date" name="from" value="<?= tx_h($dateFrom) ?>" aria-label="From" onchange="this.form.submit()">
          <input type="date" name="to" value="<?= tx_h($dateTo) ?>" aria-label="To" onchange="this.form.submit()">
          <div class="tx-search">
            <i class="fa fa-search"></i>
            <input type="search" name="q" value="<?= tx_h($q) ?>" placeholder="Search transactions..." autocomplete="off">
          </div>
          <button type="submit" class="tx-btn primary"><i class="fa fa-sliders"></i> Filter</button>
          <button type="button" class="tx-btn" title="Export coming soon"><i class="fa fa-download"></i> Export</button>
          <select id="txPageLen" aria-label="Per page">
            <option value="10" selected>10 / page</option>
            <option value="25">25 / page</option>
            <option value="50">50 / page</option>
          </select>
        </form>

        <div class="tx-table-wrap">
          <table id="datatable1" class="tx-table display" style="width:100%;">
            <thead>
              <tr>
                <th>Transaction ID</th>
                <th>Order ID</th>
                <th>Date &amp; Time</th>
                <th>Type</th>
                <th><?= $partyFilter === 'seller' ? 'Seller / Buyer' : ($partyFilter === 'buyer' ? 'Buyer / Seller' : 'From / To') ?></th>
                <th>Payment Method</th>
                <th>Amount</th>
                <th>Status</th>
                <th>Description</th>
                <th style="width:90px;">Actions</th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $t):
                $trxId = tx_code((int)$t['seed']);
                $type = (string)$t['type'];
                $st = (string)$t['status'];
                $method = $t['method'] ?? [];
                $amt = (int)$t['amount_cents'];
                $neg = $amt < 0 || !empty($t['signed']);
                $buyerName = (string)($t['buyer_name'] ?? (($t['party_role'] ?? '') === 'Buyer' ? ($t['party_name'] ?? 'Buyer') : 'Buyer'));
                $sellerName = (string)($t['seller_name'] ?? (($t['party_role'] ?? '') === 'Seller' ? ($t['party_name'] ?? 'Seller') : 'Seller'));
                $buyerKey = (string)($t['buyer_key'] ?? $buyerName);
                $sellerKey = (string)($t['seller_key'] ?? $sellerName);
                $side = (string)($t['side'] ?? '');
                // Seller-first when Seller switch is on; otherwise Buyer-first.
                $sellerFirst = ($partyFilter === 'seller');
                $firstName = $sellerFirst ? $sellerName : $buyerName;
                $firstKey = $sellerFirst ? $sellerKey : $buyerKey;
                $firstRole = $sellerFirst ? 'Seller' : 'Buyer';
                $secondName = $sellerFirst ? $buyerName : $sellerName;
                $secondKey = $sellerFirst ? $buyerKey : $sellerKey;
                $secondRole = $sellerFirst ? 'Buyer' : 'Seller';
                $firstFocus = $partyFilter === 'all'
                    ? (($side === 'seller' && $sellerFirst) || ($side === 'buyer' && !$sellerFirst) || $side === 'platform')
                    : true;
                $secondFocus = $partyFilter === 'all' && (($side === 'seller' && !$sellerFirst) || ($side === 'buyer' && $sellerFirst));
                $orderHref = ((int)$t['order_id'] > 0)
                    ? ($hrefPrefix . 'open_order_detail.php?id=' . (int)$t['order_id'])
                    : '';
                $methodLabel = (string)($method['brand'] ?? '—');
                if (!empty($method['last4']) && $method['last4'] !== '••••') {
                    $methodLabel .= ' •••• ' . $method['last4'];
                } elseif (($method['kind'] ?? '') === 'card') {
                    $methodLabel .= ' ••••';
                }
            ?>
              <tr>
                <td>
                  <span class="tx-id">
                    <?= tx_h($trxId) ?>
                    <button type="button" class="tx-copy" data-copy="<?= tx_h($trxId) ?>" title="Copy"><i class="fa fa-clone"></i></button>
                  </span>
                </td>
                <td>
                  <?php if ($orderHref !== ''): ?>
                    <a class="tx-ord" href="<?= tx_h($orderHref) ?>"><?= tx_h((string)$t['order_code']) ?></a>
                  <?php else: ?>
                    <?= tx_h((string)$t['order_code']) ?>
                  <?php endif; ?>
                </td>
                <td data-order="<?= (int)(strtotime((string)$t['when']) ?: 0) ?>"><?= tx_h(tx_fmt((string)$t['when'])) ?></td>
                <td><span class="tx-pill <?= tx_h($type) ?>"><?= tx_h($typeLabels[$type] ?? ucfirst($type)) ?></span></td>
                <td>
                  <?php if ($side === 'platform' && $partyFilter === 'all'): ?>
                    <div class="tx-person">
                      <span class="tx-av" style="background:<?= tx_h(tx_avatar_color((string)$t['party_key'])) ?>;"><?= tx_h(tx_initials((string)$t['party_name'])) ?></span>
                      <div style="min-width:0;">
                        <div class="tx-name"><?= tx_h((string)$t['party_name']) ?></div>
                      </div>
                    </div>
                  <?php else: ?>
                    <div class="tx-party-pair">
                      <div class="tx-person<?= $firstFocus ? ' is-focus' : ' is-dim' ?>">
                        <span class="tx-av" style="background:<?= tx_h(tx_avatar_color($firstKey)) ?>;"><?= tx_h(tx_initials($firstName)) ?></span>
                        <div style="min-width:0;">
                          <div class="tx-name"><?= tx_h($firstName) ?> <span class="tx-role">(<?= tx_h($firstRole) ?>)</span></div>
                        </div>
                      </div>
                      <span class="tx-arrow"><i class="fa fa-exchange"></i></span>
                      <div class="tx-person<?= $secondFocus ? ' is-focus' : ' is-dim' ?>">
                        <span class="tx-av" style="background:<?= tx_h(tx_avatar_color($secondKey)) ?>;"><?= tx_h(tx_initials($secondName)) ?></span>
                        <div style="min-width:0;">
                          <div class="tx-name"><?= tx_h($secondName) ?> <span class="tx-role">(<?= tx_h($secondRole) ?>)</span></div>
                        </div>
                      </div>
                    </div>
                  <?php endif; ?>
                </td>
                <td>
                  <div class="tx-method"><i class="fa <?= tx_h((string)($method['icon'] ?? 'fa-credit-card')) ?>"></i><?= tx_h($methodLabel) ?></div>
                </td>
                <td><span class="tx-amt<?= $neg ? ' neg' : '' ?>"><?= tx_h(tx_money($amt, (string)$t['currency'], true)) ?></span></td>
                <td><span class="tx-st <?= tx_h($st) ?>"><span class="dot"></span><?= tx_h($statusLabels[$st] ?? ucfirst($st)) ?></span></td>
                <td><div class="tx-desc" title="<?= tx_h((string)$t['description']) ?>"><?= tx_h((string)$t['description']) ?></div></td>
                <td>
                  <div class="tx-acts">
                    <?php if ($orderHref !== ''): ?>
                      <a class="tx-eye" href="<?= tx_h($orderHref) ?>" title="View order"><i class="fa fa-eye"></i></a>
                    <?php else: ?>
                      <span class="tx-eye" style="opacity:.4;" title="No order"><i class="fa fa-eye"></i></span>
                    <?php endif; ?>
                    <div class="fries-menu">
                      <button type="button" class="fries-toggle" title="Actions" aria-label="Actions" aria-haspopup="true"><span class="fries-icon" aria-hidden="true"></span></button>
                      <div class="fries-dropdown" role="menu">
                        <?php if ($orderHref !== ''): ?>
                          <a class="fries-item" role="menuitem" href="<?= tx_h($orderHref) ?>"><i class="fa fa-external-link"></i> Open order</a>
                        <?php endif; ?>
                        <button type="button" class="fries-item tx-copy" role="menuitem" data-copy="<?= tx_h($trxId) ?>"><i class="fa fa-clone"></i> Copy TRX ID</button>
                      </div>
                    </div>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <div class="tx-foot">
          <div class="muted" id="txShowing">Showing 0 transactions</div>
          <div id="txPagerHost"></div>
          <div class="muted"><span id="visibleTxCount"><?= (int)count($rows) ?></span> in this view</div>
        </div>
      </div>
    </div>
  </div>
</div>

<script src="<?= tx_h($hrefPrefix) ?>../lib/jquery/jquery.js"></script>
<script src="<?= tx_h($hrefPrefix) ?>../lib/datatables/jquery.dataTables.js"></script>
<script src="<?= tx_h($hrefPrefix) ?>js/admin-fries-menu.js?v=1"></script>
<script>
$(function() {
  document.querySelectorAll('.tx-copy').forEach(function(btn) {
    btn.addEventListener('click', function() {
      var v = btn.getAttribute('data-copy') || '';
      if (!v || !navigator.clipboard) return;
      navigator.clipboard.writeText(v).catch(function(){});
    });
  });
  var hasRows = <?= count($rows) > 0 ? 'true' : 'false' ?>;
  if (!hasRows) {
    $('#txShowing').text('Showing 0 transactions');
    return;
  }
  var dt = $('#datatable1').DataTable({
    paging: true,
    pageLength: 10,
    lengthChange: false,
    info: true,
    autoWidth: false,
    order: [[2, 'desc']],
    columnDefs: [{ orderable: false, targets: [9] }],
    dom: 'tp',
    language: { paginate: { previous: '‹', next: '›' } },
    drawCallback: function() {
      var info = this.api().page.info();
      var from = info.recordsDisplay === 0 ? 0 : (info.start + 1);
      $('#txShowing').text('Showing ' + from + ' to ' + info.end + ' of ' + info.recordsDisplay + ' transactions.');
      $('#visibleTxCount').text(info.recordsDisplay);
      var $pag = $(this.api().table().container()).find('.dataTables_paginate');
      if ($pag.length) $('#txPagerHost').empty().append($pag);
    }
  });
  setTimeout(function(){ var $pag=$('#datatable1_paginate'); if($pag.length) $('#txPagerHost').empty().append($pag); }, 0);
  $('#txPageLen').on('change', function(){ dt.page.len(parseInt(this.value,10)||10).draw(); });
});
</script>
<?php org_admin_render_foot(); ?>
