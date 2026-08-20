<?php
declare(strict_types=1);

/**
 * Shared OMS orders panel for orders.php and sales_management.php#orders.
 *
 * Expected vars (set by caller):
 * - PDO $dbh
 * - int $orgId
 * - int $memberId
 * - string $statusFilter
 * - list<string> $allowedFilters
 * - string $err
 * - string $ok
 * - string $omsBaseUrl  e.g. 'orders.php' or 'sales_management.php'
 * - string $omsHash     e.g. '' or '#orders'
 * - bool $omsShowCommerceHub (optional, default true)
 * - bool $omsShowStoreToolbar (optional)
 * - int $omsNotiCount, $omsMsgCount
 * - string $omsStorePreview
 */

if (!function_exists('h') && function_exists('org_ecommerce_h')) {
    function h(string $s): string
    {
        return org_ecommerce_h($s);
    }
}

if (!function_exists('oms_panel_cover_url')) {
    function oms_panel_cover_url(?string $path): string
    {
        $path = trim((string)$path);
        if ($path === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $path) || str_starts_with($path, '/')) {
            return $path;
        }
        return '../' . ltrim($path, '/');
    }
}

if (!function_exists('oms_panel_status_ui')) {
    function oms_panel_status_ui(string $st): array
    {
        $st = strtolower(trim($st));
        return match ($st) {
            'delivered' => ['Delivered', 'delivered'],
            'shipped' => ['Shipped', 'shipped'],
            'cancelled', 'canceled' => ['Cancelled', 'canceled'],
            'paid', 'confirmed', 'pending', 'processing' => ['Processing', 'processing'],
            default => ['Processing', 'processing'],
        };
    }
}

if (!function_exists('oms_panel_bucket')) {
    function oms_panel_bucket(string $st): string
    {
        $st = strtolower(trim($st));
        if ($st === 'shipped') {
            return 'shipped';
        }
        if ($st === 'delivered') {
            return 'delivered';
        }
        if ($st === 'cancelled' || $st === 'canceled') {
            return 'cancelled';
        }
        return 'processing';
    }
}

if (!function_exists('oms_panel_pct')) {
    function oms_panel_pct(int $cur, int $prev): array
    {
        if ($prev <= 0) {
            return [$cur > 0 ? 100.0 : 0.0, $cur >= $prev];
        }
        $pct = (($cur - $prev) / $prev) * 100;
        return [round($pct, 1), $pct >= 0];
    }
}

if (!function_exists('oms_panel_group_checkouts')) {
    /**
     * One checkout / order code = one table row.
     *
     * @param list<array<string, mixed>> $orders
     * @return list<array<string, mixed>>
     */
    function oms_panel_group_checkouts(array $orders): array
    {
        $groups = [];
        foreach ($orders as $order) {
            $id = (int)($order['id'] ?? 0);
            $code = trim((string)($order['order_code'] ?? ''));
            $key = $code !== '' ? ('c:' . $code) : ('o:' . $id);
            $createdRaw = (string)($order['created_at'] ?? '');
            $createdTs = $createdRaw !== '' ? strtotime($createdRaw) : false;
            $createdTs = $createdTs !== false ? (int)$createdTs : 0;
            if (!isset($groups[$key])) {
                $buyerName = trim((string)($order['buyer_name'] ?? ''));
                if ($buyerName === '' && trim((string)($order['buyer_username'] ?? '')) !== '') {
                    $buyerName = '@' . (string)$order['buyer_username'];
                }
                if ($buyerName === '') {
                    $buyerName = trim((string)($order['buyer_email'] ?? '')) ?: 'Guest';
                }
                $displayCode = $code !== '' ? $code : ('ORD-' . str_pad((string)$id, 6, '0', STR_PAD_LEFT));
                $groups[$key] = [
                    'order_code' => $displayCode,
                    'primary_order_id' => $id,
                    'buyer_name' => $buyerName,
                    'buyer_email' => trim((string)($order['buyer_email'] ?? '')),
                    'buyer_phone' => trim((string)($order['buyer_phone'] ?? '')),
                    'currency' => (string)($order['currency'] ?? 'USD'),
                    'date_ts' => $createdTs,
                    'date_raw' => $createdRaw,
                    'total_cents' => 0,
                    'statuses' => [],
                    'lines' => [],
                    'payment_method' => trim((string)($order['payment_method'] ?? '')),
                    'payment_reference' => trim((string)($order['payment_reference'] ?? '')),
                    'channel' => 'Direct Store',
                    'carrier' => trim((string)($order['carrier'] ?? '')),
                    'tracking_number' => trim((string)($order['tracking_number'] ?? '')),
                    'fulfillment_method' => (string)($order['fulfillment_method'] ?? 'fbm'),
                    'delivery_option' => (string)($order['delivery_option'] ?? 'home_delivery'),
                    'seller_notes' => (string)($order['seller_notes'] ?? ''),
                    'primary' => $order,
                ];
            }
            $g = &$groups[$key];
            $g['lines'][] = $order;
            $g['total_cents'] += (int)($order['total_cents'] ?? 0);
            $st = strtolower(trim((string)($order['status'] ?? '')));
            if ($st !== '') {
                $g['statuses'][] = $st;
            }
            if ($createdTs >= (int)$g['date_ts'] && $id > 0) {
                $g['date_ts'] = $createdTs;
                $g['date_raw'] = $createdRaw;
                $g['primary_order_id'] = $id;
                $g['primary'] = $order;
                $g['carrier'] = trim((string)($order['carrier'] ?? $g['carrier']));
                $g['tracking_number'] = trim((string)($order['tracking_number'] ?? $g['tracking_number']));
                $g['fulfillment_method'] = (string)($order['fulfillment_method'] ?? $g['fulfillment_method']);
                $g['delivery_option'] = (string)($order['delivery_option'] ?? $g['delivery_option']);
                $g['seller_notes'] = (string)($order['seller_notes'] ?? $g['seller_notes']);
                if (trim((string)($order['payment_method'] ?? '')) !== '') {
                    $g['payment_method'] = trim((string)$order['payment_method']);
                }
                if (trim((string)($order['payment_reference'] ?? '')) !== '') {
                    $g['payment_reference'] = trim((string)$order['payment_reference']);
                }
            }
            unset($g);
        }

        $out = [];
        foreach ($groups as $g) {
            $buckets = [];
            foreach ($g['statuses'] as $st) {
                $buckets[] = oms_panel_bucket($st);
            }
            $buckets = array_values(array_unique($buckets));
            $status = count($buckets) === 1 ? $buckets[0] : (in_array('processing', $buckets, true) ? 'processing' : ($buckets[0] ?? 'processing'));
            $ts = (int)$g['date_ts'];
            $dateLabel = $ts > 0 ? date('M j, Y', $ts) : '—';
            $timeLabel = $ts > 0 ? date('g:i A', $ts) : '';
            $items = [];
            foreach ($g['lines'] as $line) {
                $items[] = [
                    'title' => trim((string)($line['product_title'] ?? '')) ?: 'Product',
                    'qty' => max(1, (int)($line['quantity'] ?? 1)),
                    'cover' => oms_panel_cover_url(isset($line['product_cover']) ? (string)$line['product_cover'] : ''),
                ];
            }
            $pm = strtolower((string)$g['payment_method']);
            $ref = (string)$g['payment_reference'];
            $last4 = '';
            if (preg_match('/(\d{4})\s*$/', $ref, $m)) {
                $last4 = $m[1];
            }
            if (str_contains($pm, 'visa')) {
                $payBrand = 'VISA';
            } elseif (str_contains($pm, 'master')) {
                $payBrand = 'Mastercard';
            } elseif (str_contains($pm, 'paypal')) {
                $payBrand = 'PayPal';
            } elseif (str_contains($pm, 'stripe') || str_contains($pm, 'card') || $pm === '') {
                $payBrand = $ref !== '' || $status !== 'cancelled' ? 'Card' : '—';
            } else {
                $payBrand = ucfirst($pm);
            }
            $channel = 'Direct Store';
            $otype = strtolower((string)($g['primary']['order_type'] ?? 'purchase'));
            if (str_contains($otype, 'market')) {
                $channel = 'Marketplace';
            }
            $money = function_exists('org_shop_format_price')
                ? org_shop_format_price((int)$g['total_cents'], (string)$g['currency'])
                : ('$' . number_format(max(0, (int)$g['total_cents']) / 100, 2));

            $primary = $g['primary'];
            $out[] = [
                'order_code' => (string)$g['order_code'],
                'primary_order_id' => (int)$g['primary_order_id'],
                'buyer_name' => (string)$g['buyer_name'],
                'buyer_email' => (string)$g['buyer_email'],
                'buyer_phone' => (string)$g['buyer_phone'],
                'date_ts' => $ts,
                'date_iso' => $ts > 0 ? date('Y-m-d', $ts) : '',
                'date_label' => $dateLabel,
                'time_label' => $timeLabel,
                'total_cents' => (int)$g['total_cents'],
                'total_label' => $money,
                'status' => $status,
                'raw_status' => strtolower((string)($primary['status'] ?? $status)),
                'items' => $items,
                'pay_brand' => $payBrand,
                'pay_last4' => $last4,
                'channel' => $channel,
                'carrier' => (string)$g['carrier'],
                'tracking_number' => (string)$g['tracking_number'],
                'fulfillment_method' => (string)$g['fulfillment_method'],
                'delivery_option' => (string)$g['delivery_option'],
                'seller_notes' => (string)$g['seller_notes'],
                'primary' => $primary,
            ];
        }
        usort($out, static function (array $a, array $b): int {
            return ((int)$b['date_ts'] <=> (int)$a['date_ts']) ?: ((int)$b['primary_order_id'] <=> (int)$a['primary_order_id']);
        });
        return $out;
    }
}

$omsBaseUrl = (string)($omsBaseUrl ?? 'orders.php');
$omsHash = (string)($omsHash ?? '');
$omsShowCommerceHub = !isset($omsShowCommerceHub) || (bool)$omsShowCommerceHub;
$omsShowStoreToolbar = !empty($omsShowStoreToolbar);
$omsNotiCount = (int)($omsNotiCount ?? 0);
$omsMsgCount = (int)($omsMsgCount ?? 0);
$omsStorePreview = (string)($omsStorePreview ?? '');
$omsFormAction = $omsBaseUrl;
if ($statusFilter !== 'all') {
    $omsFormAction .= '?status=' . rawurlencode($statusFilter);
}

$allLines = org_shop_list_orders($dbh, $orgId, 'any', 400);
$allGroups = oms_panel_group_checkouts($allLines);

$kpi = [
    'total' => 0,
    'delivered' => 0,
    'processing' => 0,
    'shipped' => 0,
    'cancelled' => 0,
    'total7' => 0,
    'total_prev' => 0,
    'delivered7' => 0,
    'delivered_prev' => 0,
    'processing7' => 0,
    'processing_prev' => 0,
    'shipped7' => 0,
    'shipped_prev' => 0,
    'cancelled7' => 0,
    'cancelled_prev' => 0,
];
$now = time();
$cut7 = $now - (7 * 86400);
$cut14 = $now - (14 * 86400);
foreach ($allGroups as $g) {
    $bucket = oms_panel_bucket((string)$g['status']);
    $kpi['total']++;
    $kpi[$bucket] = (int)$kpi[$bucket] + 1;
    $ts = (int)$g['date_ts'];
    if ($ts >= $cut7) {
        $kpi['total7']++;
        $kpi[$bucket . '7'] = (int)$kpi[$bucket . '7'] + 1;
    } elseif ($ts >= $cut14) {
        $kpi['total_prev']++;
        $kpi[$bucket . '_prev'] = (int)$kpi[$bucket . '_prev'] + 1;
    }
}

$tabFilter = $statusFilter;
if (in_array($tabFilter, ['pending', 'confirmed', 'paid'], true)) {
    $tabFilter = 'processing';
}
if ($tabFilter === 'history') {
    $tabFilter = 'shipped';
}

$orderGroups = [];
foreach ($allGroups as $g) {
    $bucket = oms_panel_bucket((string)$g['status']);
    if ($tabFilter === 'all' || $tabFilter === '') {
        $orderGroups[] = $g;
        continue;
    }
    if ($tabFilter === 'history') {
        if (in_array($bucket, ['shipped', 'delivered'], true)) {
            $orderGroups[] = $g;
        }
        continue;
    }
    if ($bucket === $tabFilter) {
        $orderGroups[] = $g;
    }
}

$tabHref = static function (string $status) use ($omsBaseUrl, $omsHash): string {
    if ($status === 'all') {
        return $omsBaseUrl . $omsHash;
    }
    return $omsBaseUrl . '?status=' . rawurlencode($status) . $omsHash;
};

$payments = [];
$channels = [];
foreach ($allGroups as $g) {
    $pay = trim((string)$g['pay_brand']);
    if ($pay !== '' && $pay !== '—') {
        $payments[$pay] = true;
    }
    $ch = trim((string)$g['channel']);
    if ($ch !== '') {
        $channels[$ch] = true;
    }
}
ksort($payments);
ksort($channels);

[$kpiTotalPct, $kpiTotalUp] = oms_panel_pct((int)$kpi['total7'], (int)$kpi['total_prev']);
[$kpiDelPct, $kpiDelUp] = oms_panel_pct((int)$kpi['delivered7'], (int)$kpi['delivered_prev']);
[$kpiProcPct, $kpiProcUp] = oms_panel_pct((int)$kpi['processing7'], (int)$kpi['processing_prev']);
[$kpiShipPct, $kpiShipUp] = oms_panel_pct((int)$kpi['shipped7'], (int)$kpi['shipped_prev']);
[$kpiCanPct, $kpiCanUp] = oms_panel_pct((int)$kpi['cancelled7'], (int)$kpi['cancelled_prev']);
?>
<style>
  .store-orders{--so-text:#0f172a;--so-muted:#64748b;--so-border:#eef2f7;--so-card:#fff;--so-blue:#2563eb;color:var(--so-text);}
  .store-orders .so-hero .sd-icon-btn{
    position:relative;width:28px;height:28px;border-radius:7px;border:1px solid var(--so-border);
    background:var(--so-card);color:#475569;display:inline-flex;align-items:center;justify-content:center;
    text-decoration:none;font-size:12px;
  }
  .store-orders .so-hero .sd-preview-btn{
    height:28px;padding:0 9px;border-radius:7px;border:1px solid #cbd5e1;background:var(--ch-surface,#fff);color:#0f172a;
    font-size:11px;font-weight:700;display:inline-flex;align-items:center;gap:5px;text-decoration:none;
  }
  .store-orders .so-hero .sd-badge{
    position:absolute;top:-3px;right:-3px;min-width:14px;height:14px;padding:0 3px;border-radius:999px;
    background:#ef4444;color:#fff;font-size:9px;font-weight:800;display:inline-flex;align-items:center;justify-content:center;
  }
  .store-orders .so-kpis{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:8px;margin-bottom:10px;}
  .store-orders .so-kpi{background:var(--so-card);border:1px solid var(--so-border);border-radius:12px;padding:12px 12px 10px;}
  .store-orders .so-kpi-top{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:8px;}
  .store-orders .so-ico{width:28px;height:28px;border-radius:8px;display:inline-flex;align-items:center;justify-content:center;font-size:13px;}
  .store-orders .so-ico.blue{background:#dbeafe;color:#2563eb;}
  .store-orders .so-ico.green{background:#dcfce7;color:#16a34a;}
  .store-orders .so-ico.orange{background:#ffedd5;color:#ea580c;}
  .store-orders .so-ico.purple{background:#f3e8ff;color:#7c3aed;}
  .store-orders .so-ico.red{background:#fee2e2;color:#dc2626;}
  .store-orders .so-lab{font-size:11px;font-weight:700;color:var(--so-muted);}
  .store-orders .so-val{font-size:22px;font-weight:800;line-height:1.15;margin-top:2px;}
  .store-orders .so-delta{font-size:11px;font-weight:700;white-space:nowrap;}
  .store-orders .so-delta.up{color:#16a34a;}
  .store-orders .so-delta.down{color:#dc2626;}
  .store-orders .so-filters{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-bottom:10px;}
  .store-orders .so-filters input,.store-orders .so-filters select{
    height:34px;border:1px solid var(--so-border);border-radius:8px;background:var(--ch-surface,#fff);font-size:12px;padding:0 10px;color:var(--so-text);
  }
  .store-orders .so-search{display:flex;align-items:center;gap:6px;flex:1 1 220px;min-width:200px;height:34px;padding:0 10px;border:1px solid var(--so-border);border-radius:8px;background:var(--ch-surface,#fff);}
  .store-orders .so-search input{border:0;box-shadow:none;height:32px;flex:1;padding:0;background:transparent;}
  .store-orders .so-btn{height:34px;padding:0 12px;border-radius:8px;border:1px solid #cbd5e1;background:var(--ch-surface,#fff);font-size:12px;font-weight:700;display:inline-flex;align-items:center;gap:6px;color:#0f172a;text-decoration:none;cursor:pointer;}
  .store-orders .so-btn.primary{background:#2563eb;border-color:#2563eb;color:#fff;}
  .store-orders .so-tabs{display:flex;gap:18px;border-bottom:1px solid var(--so-border);margin-bottom:0;overflow:auto;}
  .store-orders .so-tab{flex:0 0 auto;padding:10px 0 8px;font-size:13px;font-weight:700;color:var(--so-muted);text-decoration:none;border-bottom:2px solid transparent;}
  .store-orders .so-tab.is-on{color:#2563eb;border-bottom-color:#2563eb;}
  .store-orders .so-table-wrap{overflow:auto;background:var(--so-card);border:1px solid var(--so-border);border-top:0;border-radius:0 0 12px 12px;}
  .store-orders .so-table{width:100%;min-width:1100px;border-collapse:collapse;}
  .store-orders .so-table th{font-size:11px;font-weight:800;letter-spacing:.04em;text-transform:uppercase;color:var(--so-muted);text-align:left;padding:10px 12px;border-bottom:1px solid var(--so-border);background:var(--ch-surface,#f8fafc);white-space:nowrap;}
  .store-orders .so-table td{padding:12px;border-bottom:1px solid var(--so-border);vertical-align:middle;font-size:13px;color:var(--so-text);}
  .store-orders .so-table tr:last-child td{border-bottom:0;}
  .store-orders .so-id-link{color:inherit;text-decoration:none;}
  .store-orders .so-id-link:hover{color:#2563eb;text-decoration:underline;}
  .store-orders .so-sub{display:block;font-size:11px;color:var(--so-muted);font-weight:600;margin-top:2px;}
  .store-orders .so-item{display:flex;align-items:center;gap:8px;min-width:0;}
  .store-orders .so-thumb{width:36px;height:36px;border-radius:8px;background:#f1f5f9;display:flex;align-items:center;justify-content:center;overflow:hidden;flex:0 0 auto;color:#94a3b8;}
  .store-orders .so-thumb img{width:100%;height:100%;object-fit:cover;}
  .store-orders .so-status{display:inline-flex;align-items:center;padding:3px 8px;border-radius:999px;font-size:11px;font-weight:800;white-space:nowrap;}
  .store-orders .so-status.delivered{background:#dcfce7;color:#15803d;}
  .store-orders .so-status.shipped{background:#f3e8ff;color:#6d28d9;}
  .store-orders .so-status.processing{background:#ffedd5;color:#c2410c;}
  .store-orders .so-status.canceled{background:#fee2e2;color:#b91c1c;}
  .store-orders .so-actions{display:flex;align-items:center;gap:6px;justify-content:flex-end;}
  .store-orders .so-view{height:28px;padding:0 10px;border-radius:7px;border:1px solid #cbd5e1;background:var(--ch-surface,#fff);font-size:12px;font-weight:700;color:#0f172a;text-decoration:none;display:inline-flex;align-items:center;}
  .store-orders .so-more{position:relative;}
  .store-orders .so-more-btn{width:28px;height:28px;border-radius:7px;border:1px solid #e2e8f0;background:var(--ch-surface,#fff);cursor:pointer;font-size:16px;line-height:1;color:#475569;}
  .store-orders .so-more-menu{display:none;position:absolute;right:0;top:32px;z-index:20;min-width:180px;background:var(--ch-surface,#fff);border:1px solid #e2e8f0;border-radius:10px;box-shadow:0 8px 24px rgba(15,23,42,.12);padding:6px;}
  .store-orders .so-more.is-open .so-more-menu{display:block;}
  .store-orders .so-more-menu a,.store-orders .so-more-menu button{
    display:block;width:100%;text-align:left;background:none;border:0;padding:8px 10px;border-radius:7px;font-size:12px;font-weight:700;color:#0f172a;cursor:pointer;text-decoration:none;
  }
  .store-orders .so-more-menu a:hover,.store-orders .so-more-menu button:hover{background:var(--ch-surface,#f8fafc);}
  .store-orders .so-more-menu .is-danger{color:#dc2626;}
  .store-orders .so-fulfill{background:var(--ch-surface,#f8fafc);}
  .store-orders .so-fulfill td{padding:12px 12px 16px;}
  .store-orders .so-fulfill-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px;align-items:end;}
  .store-orders .so-foot{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:10px;padding:10px 4px 0;font-size:12px;color:var(--so-muted);}
  .store-orders .so-pages{display:flex;gap:4px;align-items:center;}
  .store-orders .so-pages button{min-width:28px;height:28px;border:1px solid #e2e8f0;background:var(--ch-surface,#fff);border-radius:7px;font-size:12px;font-weight:700;cursor:pointer;}
  .store-orders .so-pages button.is-on{background:#2563eb;border-color:#2563eb;color:#fff;}
  .store-orders .so-empty{text-align:center;padding:28px 12px;color:var(--so-muted);}
  html.dark-auto .store-orders{--so-text:var(--msb-palette-text,#e2e8f0);--so-muted:var(--msb-palette-text-muted,#94a3b8);--so-border:rgba(148,163,184,.22);--so-card:var(--msb-palette-bg,#171d24);}
  html.dark-auto .store-orders .so-filters input,html.dark-auto .store-orders .so-filters select,
  html.dark-auto .store-orders .so-search,html.dark-auto .store-orders .so-btn,html.dark-auto .store-orders .so-view,
  html.dark-auto .store-orders .so-more-btn,html.dark-auto .store-orders .so-more-menu,html.dark-auto .store-orders .so-pages button{
    background:var(--msb-palette-bg,#171d24);color:var(--msb-palette-text,#e2e8f0);border-color:rgba(148,163,184,.28);
  }
  @media (max-width:1100px){.store-orders .so-kpis{grid-template-columns:repeat(3,minmax(0,1fr));}.store-orders .so-fulfill-grid{grid-template-columns:1fr 1fr;}}
  @media (max-width:700px){.store-orders .so-kpis{grid-template-columns:1fr 1fr;}}
</style>

<div class="store-orders" id="storeOrdersRoot">
  <?php if ($omsShowStoreToolbar || $omsShowCommerceHub): ?>
    <div class="so-hero">
      <?php if ($omsShowStoreToolbar): ?>
        <a class="sd-icon-btn" href="sales_notifications.php" title="Notifications" aria-label="Notifications">
          <i class="fa fa-bell-o"></i>
          <?php if ($omsNotiCount > 0): ?><span class="sd-badge"><?= (int)min(99, $omsNotiCount) ?></span><?php endif; ?>
        </a>
        <a class="sd-icon-btn" href="#message" data-sales-nav="message" title="Messages" aria-label="Messages">
          <i class="fa fa-commenting-o"></i>
          <?php if ($omsMsgCount > 0): ?><span class="sd-badge"><?= (int)min(99, $omsMsgCount) ?></span><?php endif; ?>
        </a>
        <?php if ($omsStorePreview !== ''): ?>
          <a class="sd-preview-btn" href="<?= h($omsStorePreview) ?>" target="_blank" rel="noopener">
            <i class="fa fa-external-link"></i> Store Preview
          </a>
        <?php endif; ?>
      <?php elseif ($omsShowCommerceHub): ?>
        <a href="commerce.php" class="so-btn">Commerce hub</a>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <div class="so-kpis">
    <div class="so-kpi">
      <div class="so-kpi-top">
        <div class="so-ico blue"><i class="fa fa-cube"></i></div>
        <div class="so-delta <?= $kpiTotalUp ? 'up' : 'down' ?>"><?= $kpiTotalUp ? '↑' : '↓' ?> <?= number_format(abs($kpiTotalPct), 1) ?>%</div>
      </div>
      <div class="so-lab">Total Orders</div>
      <div class="so-val"><?= (int)$kpi['total'] ?></div>
    </div>
    <div class="so-kpi">
      <div class="so-kpi-top">
        <div class="so-ico green"><i class="fa fa-check"></i></div>
        <div class="so-delta <?= $kpiDelUp ? 'up' : 'down' ?>"><?= $kpiDelUp ? '↑' : '↓' ?> <?= number_format(abs($kpiDelPct), 1) ?>%</div>
      </div>
      <div class="so-lab">Delivered</div>
      <div class="so-val"><?= (int)$kpi['delivered'] ?></div>
    </div>
    <div class="so-kpi">
      <div class="so-kpi-top">
        <div class="so-ico orange"><i class="fa fa-clock-o"></i></div>
        <div class="so-delta <?= $kpiProcUp ? 'up' : 'down' ?>"><?= $kpiProcUp ? '↑' : '↓' ?> <?= number_format(abs($kpiProcPct), 1) ?>%</div>
      </div>
      <div class="so-lab">Processing</div>
      <div class="so-val"><?= (int)$kpi['processing'] ?></div>
    </div>
    <div class="so-kpi">
      <div class="so-kpi-top">
        <div class="so-ico purple"><i class="fa fa-truck"></i></div>
        <div class="so-delta <?= $kpiShipUp ? 'up' : 'down' ?>"><?= $kpiShipUp ? '↑' : '↓' ?> <?= number_format(abs($kpiShipPct), 1) ?>%</div>
      </div>
      <div class="so-lab">Shipped</div>
      <div class="so-val"><?= (int)$kpi['shipped'] ?></div>
    </div>
    <div class="so-kpi">
      <div class="so-kpi-top">
        <div class="so-ico red"><i class="fa fa-times"></i></div>
        <div class="so-delta <?= $kpiCanUp ? 'up' : 'down' ?>"><?= $kpiCanUp ? '↑' : '↓' ?> <?= number_format(abs($kpiCanPct), 1) ?>%</div>
      </div>
      <div class="so-lab">Cancelled</div>
      <div class="so-val"><?= (int)$kpi['cancelled'] ?></div>
    </div>
  </div>

  <?php if ($err !== ''): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endif; ?>
  <?php if ($ok !== ''): ?><div class="alert alert-success"><?= h($ok) ?></div><?php endif; ?>

  <div class="so-filters">
    <input type="date" id="soDateFrom" title="From date">
    <input type="date" id="soDateTo" title="To date">
    <select id="soPayFilter">
      <option value="">All Payment Methods</option>
      <?php foreach (array_keys($payments) as $pay): ?>
        <option value="<?= h($pay) ?>"><?= h($pay) ?></option>
      <?php endforeach; ?>
    </select>
    <select id="soChannelFilter">
      <option value="">All Channels</option>
      <?php foreach (array_keys($channels) as $ch): ?>
        <option value="<?= h($ch) ?>"><?= h($ch) ?></option>
      <?php endforeach; ?>
    </select>
    <label class="so-search" for="omsProductIdSearch">
      <i class="icon ion-ios-search" aria-hidden="true"></i>
      <input type="search" id="omsProductIdSearch" placeholder="Search orders by ID, customer..." autocomplete="off" spellcheck="false">
    </label>
    <button type="button" class="so-btn" id="soFilterBtn"><i class="fa fa-filter"></i> Filter</button>
    <button type="button" class="so-btn" id="soExportBtn"><i class="fa fa-download"></i> Export</button>
  </div>

  <div class="so-tabs" role="tablist">
    <a class="so-tab<?= $tabFilter === 'all' ? ' is-on' : '' ?>" href="<?= h($tabHref('all')) ?>">All Orders (<?= (int)$kpi['total'] ?>)</a>
    <a class="so-tab<?= $tabFilter === 'processing' ? ' is-on' : '' ?>" href="<?= h($tabHref('processing')) ?>">Processing (<?= (int)$kpi['processing'] ?>)</a>
    <a class="so-tab<?= $tabFilter === 'shipped' ? ' is-on' : '' ?>" href="<?= h($tabHref('shipped')) ?>">Shipped (<?= (int)$kpi['shipped'] ?>)</a>
    <a class="so-tab<?= $tabFilter === 'delivered' ? ' is-on' : '' ?>" href="<?= h($tabHref('delivered')) ?>">Delivered (<?= (int)$kpi['delivered'] ?>)</a>
    <a class="so-tab<?= $tabFilter === 'cancelled' ? ' is-on' : '' ?>" href="<?= h($tabHref('cancelled')) ?>">Cancelled (<?= (int)$kpi['cancelled'] ?>)</a>
  </div>

  <div class="so-table-wrap">
    <table class="so-table" id="omsOrdersTable">
      <thead>
        <tr>
          <th style="width:36px;"><input type="checkbox" id="soCheckAll" aria-label="Select all"></th>
          <th>Order ID</th>
          <th>Date &amp; Time</th>
          <th>Customer</th>
          <th>Items</th>
          <th>Amount</th>
          <th>Payment</th>
          <th>Status</th>
          <th>Delivery</th>
          <th style="text-align:right;">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$orderGroups): ?>
          <tr class="so-empty-row"><td colspan="10" class="so-empty">No orders yet.</td></tr>
        <?php else: foreach ($orderGroups as $g): ?>
          <?php
            $primaryId = (int)$g['primary_order_id'];
            $primary = $g['primary'];
            [$stLab, $stCls] = oms_panel_status_ui((string)$g['status']);
            $firstItem = $g['items'][0] ?? ['title' => 'Product', 'qty' => 1, 'cover' => ''];
            $extraItems = max(0, count($g['items']) - 1);
            $itemLabel = (string)$firstItem['title'] . ' ×' . (int)$firstItem['qty'];
            if ($extraItems > 0) {
                $itemLabel .= ' +' . $extraItems . ' more';
            }
            $delivery = 'Preparing';
            if ($g['status'] === 'delivered') {
                $delivery = $g['date_label'];
            } elseif ($g['status'] === 'shipped') {
                $delivery = trim((string)$g['tracking_number']) !== '' ? ('In transit · ' . (string)$g['tracking_number']) : 'In transit';
            } elseif ($g['status'] === 'cancelled') {
                $delivery = '—';
            }
            $searchBlob = mb_strtolower(trim(implode(' ', array_filter([
                (string)$g['order_code'],
                (string)$g['buyer_name'],
                (string)$g['buyer_email'],
                $itemLabel,
                (string)$g['pay_brand'],
                (string)$g['channel'],
                $stLab,
            ]))));
            $canSellerCancel = in_array((string)$g['raw_status'], ['pending', 'confirmed', 'paid'], true);
            $detailHref = 'order_details.php?id=' . $primaryId;
            if ($omsHash === '#orders') {
                $detailHref .= '&from=sales';
            }
            $codeForUrl = trim((string)$g['order_code']);
            if ($codeForUrl !== '') {
                $detailHref .= '&code=' . rawurlencode($codeForUrl);
            }
          ?>
          <tr
            class="so-row"
            data-oms-search="<?= h($searchBlob) ?>"
            data-date="<?= h((string)$g['date_iso']) ?>"
            data-pay="<?= h((string)$g['pay_brand']) ?>"
            data-channel="<?= h((string)$g['channel']) ?>"
            data-code="<?= h((string)$g['order_code']) ?>"
            data-customer="<?= h((string)$g['buyer_name']) ?>"
            data-amount="<?= h((string)$g['total_label']) ?>"
            data-status="<?= h($stLab) ?>"
          >
            <td><input type="checkbox" class="so-check" aria-label="Select order <?= h((string)$g['order_code']) ?>"></td>
            <td>
              <a class="so-id so-id-link" href="<?= h($detailHref) ?>"><?= h((string)$g['order_code']) ?></a>
              <span class="so-sub"><?= h((string)$g['channel']) ?></span>
            </td>
            <td>
              <span class="so-id" style="font-weight:700;"><?= h((string)$g['date_label']) ?></span>
              <span class="so-sub"><?= h((string)$g['time_label']) ?></span>
            </td>
            <td>
              <span class="so-id" style="font-weight:700;"><?= h((string)$g['buyer_name']) ?></span>
              <span class="so-sub"><?= h((string)$g['buyer_email'] !== '' ? $g['buyer_email'] : 'No email') ?></span>
            </td>
            <td>
              <div class="so-item">
                <div class="so-thumb">
                  <?php if ((string)$firstItem['cover'] !== ''): ?>
                    <img src="<?= h((string)$firstItem['cover']) ?>" alt="">
                  <?php else: ?>
                    <i class="fa fa-cube"></i>
                  <?php endif; ?>
                </div>
                <div>
                  <span class="so-id" style="font-weight:700;"><?= h((string)$firstItem['title']) ?></span>
                  <span class="so-sub">x<?= (int)$firstItem['qty'] ?><?= $extraItems > 0 ? ' · +' . $extraItems . ' more' : '' ?></span>
                </div>
              </div>
            </td>
            <td><strong><?= h((string)$g['total_label']) ?></strong></td>
            <td>
              <span class="so-id" style="font-weight:700;"><?= h((string)$g['pay_brand']) ?></span>
              <span class="so-sub"><?= $g['pay_last4'] !== '' ? '•••• ' . h((string)$g['pay_last4']) : '—' ?></span>
            </td>
            <td><span class="so-status <?= h($stCls) ?>"><?= h($stLab) ?></span></td>
            <td>
              <span class="so-id" style="font-weight:700;"><?= h($delivery) ?></span>
            </td>
            <td>
              <div class="so-actions">
                <?php if ($primaryId > 0): ?>
                  <a
                    href="<?= h($detailHref) ?>"
                    class="so-view"
                  >View</a>
                <?php endif; ?>
                <div class="so-more">
                  <button type="button" class="so-more-btn" aria-label="More actions">⋯</button>
                  <div class="so-more-menu">
                    <?php if ($primaryId > 0): ?>
                      <a
                        href="order_invoice.php?id=<?= $primaryId ?>"
                        class="js-open-org-order-door"
                        data-door-url="order_invoice.php?id=<?= $primaryId ?>&amp;embed=1"
                        data-door-title="Invoice"
                        data-door-label="<?= h((string)$g['buyer_name'] . ' · receipt') ?>"
                      >Invoice</a>
                    <?php endif; ?>
                    <button type="button" class="js-so-fulfill">Update fulfillment</button>
                    <form method="post" action="<?= h($omsFormAction) ?>">
                      <input type="hidden" name="oms_action" value="1">
                      <input type="hidden" name="order_id" value="<?= $primaryId ?>">
                      <input type="hidden" name="sync_crm" value="1">
                      <button type="submit">Sync to CRM</button>
                    </form>
                    <?php if ($canSellerCancel): ?>
                      <form method="post" action="<?= h($omsFormAction) ?>" class="js-oms-seller-cancel-form">
                        <input type="hidden" name="oms_cancel_action" value="1">
                        <input type="hidden" name="return_to" value="orders">
                        <input type="hidden" name="order_id" value="<?= $primaryId ?>">
                        <input type="hidden" name="cancel_reason" value="" class="js-oms-cancel-reason">
                        <button type="submit" class="is-danger">Cancel order</button>
                      </form>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
            </td>
          </tr>
          <tr class="so-fulfill" hidden>
            <td colspan="10">
              <form method="post" action="<?= h($omsFormAction) ?>" class="so-fulfill-grid">
                <input type="hidden" name="oms_action" value="1">
                <input type="hidden" name="order_id" value="<?= $primaryId ?>">
                <label>Status
                  <select name="status" class="form-control form-control-sm">
                    <?php foreach (['pending', 'confirmed', 'paid', 'shipped', 'delivered', 'cancelled'] as $st): ?>
                      <option value="<?= h($st) ?>" <?= ((string)($primary['status'] ?? '') === $st) ? 'selected' : '' ?>><?= h(ucfirst($st)) ?></option>
                    <?php endforeach; ?>
                  </select>
                </label>
                <label>Carrier
                  <input type="text" name="carrier" class="form-control form-control-sm" placeholder="Carrier" value="<?= h((string)$g['carrier']) ?>">
                </label>
                <label>Tracking #
                  <input type="text" name="tracking_number" class="form-control form-control-sm" placeholder="Tracking #" value="<?= h((string)$g['tracking_number']) ?>">
                </label>
                <label>Seller note
                  <input type="text" name="seller_notes" class="form-control form-control-sm" placeholder="Seller note" value="<?= h((string)$g['seller_notes']) ?>">
                </label>
                <div style="grid-column:1 / -1;">
                  <button type="submit" class="so-btn primary">Save fulfillment</button>
                  <span class="so-sub" style="display:inline;margin-left:8px;">Save with carrier + tracking to mark shipping. Set Delivered when the customer receives the order.</span>
                </div>
              </form>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
  <div class="so-foot" id="soFoot" <?= !$orderGroups ? 'hidden' : '' ?>>
    <div id="soFootLabel">Showing 0 of 0 orders</div>
    <div class="so-pages" id="soPages"></div>
    <label>
      <select id="soPageSize">
        <option value="10" selected>10 / page</option>
        <option value="25">25 / page</option>
        <option value="50">50 / page</option>
      </select>
    </label>
  </div>
</div>
<script>
(function () {
  if (!window.__msbOmsSellerCancelInit) {
    window.__msbOmsSellerCancelInit = true;
    document.addEventListener('submit', function (e) {
      var form = e.target && e.target.closest ? e.target.closest('.js-oms-seller-cancel-form') : null;
      if (!form) return;
      e.preventDefault();
      var reason = window.prompt(
        'Why are you cancelling this customer order?\n(Examples: Card expired, payment issue, changed mind)',
        'Card expired'
      );
      if (reason === null) return;
      reason = String(reason).trim();
      if (!reason) reason = 'Seller cancelled';
      if (!window.confirm('Cancel this customer order and notify the buyer?')) return;
      var reasonInput = form.querySelector('.js-oms-cancel-reason');
      if (reasonInput) reasonInput.value = reason;
      form.submit();
    });
  }

  var root = document.getElementById('storeOrdersRoot');
  if (!root) return;
  var table = document.getElementById('omsOrdersTable');
  if (!table) return;
  var rows = Array.prototype.slice.call(table.querySelectorAll('tbody tr.so-row'));
  var searchInput = document.getElementById('omsProductIdSearch');
  var dateFrom = document.getElementById('soDateFrom');
  var dateTo = document.getElementById('soDateTo');
  var payFilter = document.getElementById('soPayFilter');
  var channelFilter = document.getElementById('soChannelFilter');
  var pageSizeEl = document.getElementById('soPageSize');
  var foot = document.getElementById('soFoot');
  var footLabel = document.getElementById('soFootLabel');
  var pagesEl = document.getElementById('soPages');
  var page = 1;

  function pair(row) {
    return row && row.nextElementSibling && row.nextElementSibling.classList.contains('so-fulfill')
      ? row.nextElementSibling
      : null;
  }

  function visibleRows() {
    var q = String(searchInput && searchInput.value || '').trim().toLowerCase();
    var from = String(dateFrom && dateFrom.value || '');
    var to = String(dateTo && dateTo.value || '');
    var pay = String(payFilter && payFilter.value || '');
    var channel = String(channelFilter && channelFilter.value || '');
    return rows.filter(function (row) {
      var blob = String(row.getAttribute('data-oms-search') || '');
      var date = String(row.getAttribute('data-date') || '');
      var rowPay = String(row.getAttribute('data-pay') || '');
      var rowCh = String(row.getAttribute('data-channel') || '');
      if (q && blob.indexOf(q) === -1) return false;
      if (from && date && date < from) return false;
      if (to && date && date > to) return false;
      if (pay && rowPay !== pay) return false;
      if (channel && rowCh !== channel) return false;
      return true;
    });
  }

  function render() {
    var vis = visibleRows();
    var size = Math.max(1, parseInt(pageSizeEl && pageSizeEl.value, 10) || 10);
    var total = vis.length;
    var pages = Math.max(1, Math.ceil(total / size));
    if (page > pages) page = pages;
    var start = (page - 1) * size;
    var end = Math.min(total, start + size);
    rows.forEach(function (row) {
      var extra = pair(row);
      row.hidden = true;
      if (extra && !extra.classList.contains('is-open')) extra.hidden = true;
    });
    vis.forEach(function (row, i) {
      var on = i >= start && i < end;
      row.hidden = !on;
      var extra = pair(row);
      if (extra && extra.classList.contains('is-open')) extra.hidden = !on;
    });
    if (foot) foot.hidden = total === 0;
    if (footLabel) {
      footLabel.textContent = total === 0
        ? 'No matching orders'
        : ('Showing ' + (total ? (start + 1) : 0) + ' to ' + end + ' of ' + total + ' orders');
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

  ['input', 'change', 'search'].forEach(function (ev) {
    if (searchInput) searchInput.addEventListener(ev, function () { page = 1; render(); });
  });
  [dateFrom, dateTo, payFilter, channelFilter, pageSizeEl].forEach(function (el) {
    if (!el) return;
    el.addEventListener('change', function () { page = 1; render(); });
  });
  var filterBtn = document.getElementById('soFilterBtn');
  if (filterBtn) filterBtn.addEventListener('click', function () { page = 1; render(); });

  var exportBtn = document.getElementById('soExportBtn');
  if (exportBtn) {
    exportBtn.addEventListener('click', function () {
      var vis = visibleRows();
      var lines = [['Order ID', 'Date', 'Customer', 'Amount', 'Payment', 'Channel', 'Status']];
      vis.forEach(function (row) {
        lines.push([
          row.getAttribute('data-code') || '',
          row.getAttribute('data-date') || '',
          row.getAttribute('data-customer') || '',
          row.getAttribute('data-amount') || '',
          row.getAttribute('data-pay') || '',
          row.getAttribute('data-channel') || '',
          row.getAttribute('data-status') || ''
        ]);
      });
      var csv = lines.map(function (cols) {
        return cols.map(function (c) { return '"' + String(c).replace(/"/g, '""') + '"'; }).join(',');
      }).join('\n');
      var blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
      var a = document.createElement('a');
      a.href = URL.createObjectURL(blob);
      a.download = 'orders.csv';
      a.click();
      URL.revokeObjectURL(a.href);
    });
  }

  var checkAll = document.getElementById('soCheckAll');
  if (checkAll) {
    checkAll.addEventListener('change', function () {
      visibleRows().forEach(function (row) {
        if (row.hidden) return;
        var box = row.querySelector('.so-check');
        if (box) box.checked = checkAll.checked;
      });
    });
  }

  root.addEventListener('click', function (e) {
    var moreBtn = e.target.closest('.so-more-btn');
    if (moreBtn) {
      var wrap = moreBtn.closest('.so-more');
      root.querySelectorAll('.so-more.is-open').forEach(function (el) {
        if (el !== wrap) el.classList.remove('is-open');
      });
      wrap.classList.toggle('is-open');
      e.preventDefault();
      return;
    }
    var fulfillBtn = e.target.closest('.js-so-fulfill');
    if (fulfillBtn) {
      var row = fulfillBtn.closest('tr.so-row');
      var extra = pair(row);
      if (extra) {
        extra.classList.toggle('is-open');
        extra.hidden = !extra.classList.contains('is-open');
      }
      var menu = fulfillBtn.closest('.so-more');
      if (menu) menu.classList.remove('is-open');
      return;
    }
    if (!e.target.closest('.so-more')) {
      root.querySelectorAll('.so-more.is-open').forEach(function (el) { el.classList.remove('is-open'); });
    }
  });

  render();
})();
</script>
