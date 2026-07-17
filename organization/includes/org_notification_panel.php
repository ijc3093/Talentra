<?php
declare(strict_types=1);

/**
 * Seller Notification panel — order lifecycle + inventory/returns for full seller responsibility.
 *
 * Expected:
 * - PDO $dbh
 * - int $orgId
 */

if (!function_exists('h') && function_exists('org_ecommerce_h')) {
    function h(string $s): string
    {
        return org_ecommerce_h($s);
    }
}

$notifAlerts = org_sales_notifications($dbh, $orgId);
$life = org_sales_order_lifecycle_counts($dbh, $orgId);
$alertCount = count($notifAlerts);
$cancelTableCount = (int)($life['cancelled'] ?? 0);
$lifeTotal = (int)$life['pending'] + (int)$life['paid'] + (int)$life['cancel']
    + (int)$life['cancellation'] + (int)$life['shipping'] + (int)$life['delivery'];

$lifeStages = [
    [
        'key' => 'pending',
        'label' => 'Pending',
        'count' => (int)$life['pending'],
        'hint' => 'Waiting for customer payment',
        'href' => 'sales_management.php#orders',
        'nav' => 'orders',
    ],
    [
        'key' => 'paid',
        'label' => 'Paid',
        'count' => (int)$life['paid'],
        'hint' => 'Payment confirmed — ship now',
        'href' => 'sales_management.php#orders',
        'nav' => 'orders',
    ],
    [
        'key' => 'cancel',
        'label' => 'Cancel',
        'count' => (int)$life['cancel'],
        'hint' => 'You cancelled (seller reason)',
        'href' => 'sales_management.php#table_cancel_orders',
        'nav' => 'table_cancel_orders',
    ],
    [
        'key' => 'cancellation',
        'label' => 'Cancellation',
        'count' => (int)$life['cancellation'],
        'hint' => 'Customer cancelled their order',
        'href' => 'sales_management.php#table_cancel_orders',
        'nav' => 'table_cancel_orders',
    ],
    [
        'key' => 'shipping',
        'label' => 'Shipping',
        'count' => (int)$life['shipping'],
        'hint' => 'In transit — mark delivery',
        'href' => 'sales_management.php#delivery-shipping',
        'nav' => 'delivery-shipping',
    ],
    [
        'key' => 'delivery',
        'label' => 'Delivery',
        'count' => (int)$life['delivery'],
        'hint' => 'Recently received by customer',
        'href' => 'sales_management.php#orders',
        'nav' => 'orders',
    ],
];

$eventFeed = [];
try {
    $eventFeed = org_sales_commerce_event_feed($dbh, $orgId, 25);
    // Prefer order-lifecycle events for seller focus.
    $eventFeed = array_values(array_filter($eventFeed, static function (array $row): bool {
        $type = strtolower((string)($row['type'] ?? ''));
        return in_array($type, [
            'cancel',
            'cancellation',
            'order cancel',
            'shipping',
            'delivery',
            'ready to ship',
            'paid',
            'pending',
        ], true)
            || strpos($type, 'ship') !== false
            || strpos($type, 'deliver') !== false
            || strpos($type, 'cancel') !== false
            || strpos($type, 'paid') !== false;
    }));
} catch (Throwable $e) {
    $eventFeed = [];
}
?>
<style>
  .org-notif-alert-tile{
    position:relative;
    overflow:visible !important;
  }
  body.org-app .commerce-action-tile .org-notif-card-badge,
  body.org-app .commerce-action-tile.org-notif-alert-tile .org-notif-card-badge,
  .commerce-action-tile .org-notif-card-badge,
  .org-notif-card-badge{
    display:inline-flex !important;
    align-items:center;
    justify-content:center;
    min-width:22px !important;
    height:22px !important;
    padding:0 7px !important;
    margin-left:8px;
    border-radius:999px !important;
    background:#dc3545 !important;
    color:#ffffff !important;
    font-size:12px !important;
    font-weight:700 !important;
    line-height:1 !important;
    border:2px solid #ffffff !important;
    box-shadow:0 1px 4px rgba(220,53,69,.35) !important;
    vertical-align:middle;
    flex-shrink:0;
  }
  .org-notif-alert-tile-title{
    display:flex;
    align-items:center;
    flex-wrap:wrap;
    gap:6px 10px;
    width:100%;
  }
  .commerce-action-tile.org-notif-alert-tile .org-notif-alert-tile-title{
    color:inherit;
    font-size:14px !important;
    font-weight:700 !important;
  }
  .org-notif-alert-tile-meta{
    display:inline-flex;
    flex-wrap:wrap;
    align-items:center;
    gap:6px;
    margin-left:auto;
  }
  .org-notif-alert-tile-meta span,
  body.org-app .commerce-action-tile.org-notif-alert-tile .org-notif-alert-tile-meta span{
    display:inline-flex !important;
    align-items:center;
    padding:3px 8px;
    border-radius:999px;
    background:rgba(15,23,42,.06);
    color:inherit !important;
    font-size:11px !important;
    font-weight:700 !important;
    line-height:1.2;
    white-space:nowrap;
  }
  .org-notif-alert-tile-copy{
    display:block;
    width:100%;
    font-size:12px !important;
    line-height:1.45 !important;
    font-weight:400 !important;
  }
  .org-notif-card-badge.is-alert{
    animation: orgNotifBadgeAlert 2.4s ease-in-out infinite;
  }
  @keyframes orgNotifBadgeAlert{
    0%,100%{transform:scale(1);box-shadow:0 0 0 0 rgba(220,53,69,.45);}
    35%{transform:scale(1.12);box-shadow:0 0 0 5px rgba(220,53,69,0);}
    70%{transform:scale(1.04);box-shadow:0 0 0 2px rgba(220,53,69,.2);}
  }
  @media (prefers-reduced-motion:reduce){
    .org-notif-card-badge.is-alert{animation:none;}
  }

  .org-notif-life{
    border:1px solid rgba(148,163,184,.35);
    border-radius:12px;
    background:var(--ch-surface, #fff);
    padding:14px 16px;
    margin-bottom:16px;
  }
  .org-notif-life-head{
    display:flex;
    align-items:flex-start;
    justify-content:space-between;
    gap:12px;
    flex-wrap:wrap;
    margin-bottom:12px;
  }
  .org-notif-life-head h3{
    margin:0 0 4px;
    font-size:14px;
    font-weight:700;
    color:inherit;
  }
  .org-notif-life-head p{
    margin:0;
    font-size:12px;
    line-height:1.4;
    color:var(--ch-muted, #64748b);
    max-width:720px;
  }
  .org-notif-life-grid{
    display:grid;
    grid-template-columns:repeat(6, minmax(0, 1fr));
    gap:8px;
  }
  .org-notif-life-stage{
    display:flex;
    flex-direction:column;
    gap:4px;
    padding:10px 12px;
    border-radius:10px;
    border:1px solid rgba(148,163,184,.28);
    background:rgba(148,163,184,.06);
    text-decoration:none !important;
    color:inherit !important;
    min-width:0;
    transition:border-color .15s ease, background .15s ease;
  }
  .org-notif-life-stage:hover{
    border-color:rgba(14,165,233,.45);
    background:rgba(14,165,233,.06);
  }
  .org-notif-life-stage.is-hot{
    border-color:rgba(220,53,69,.35);
    background:rgba(220,53,69,.06);
  }
  .org-notif-life-stage-top{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:8px;
  }
  .org-notif-life-stage-label{
    font-size:12px;
    font-weight:700;
    line-height:1.2;
  }
  .org-notif-life-stage-count{
    font-size:16px;
    font-weight:800;
    line-height:1;
  }
  .org-notif-life-stage-hint{
    font-size:11px;
    line-height:1.35;
    color:var(--ch-muted, #64748b);
    font-weight:400;
  }
  .org-notif-section-title{
    margin:18px 0 10px;
    font-size:13px;
    font-weight:700;
  }
  .org-notif-feed{
    display:flex;
    flex-direction:column;
    gap:8px;
  }
  .org-notif-feed-row{
    display:flex;
    flex-direction:column;
    gap:2px;
    padding:10px 12px;
    border:1px solid rgba(148,163,184,.28);
    border-radius:10px;
    text-decoration:none !important;
    color:inherit !important;
    background:var(--ch-surface, #fff);
  }
  .org-notif-feed-row:hover{
    border-color:rgba(14,165,233,.4);
  }
  .org-notif-feed-row strong{
    font-size:13px;
    font-weight:700;
  }
  .org-notif-feed-row span{
    font-size:12px;
    line-height:1.4;
    color:var(--ch-muted, #64748b);
  }
  .org-notif-feed-when{
    font-size:11px !important;
    margin-top:2px;
  }
  @media (max-width: 1100px){
    .org-notif-life-grid{
      grid-template-columns:repeat(3, minmax(0, 1fr));
    }
  }
  @media (max-width: 900px){
    .org-notif-life-grid{
      grid-template-columns:repeat(2, minmax(0, 1fr));
    }
  }
  @media (max-width: 520px){
    .org-notif-life-grid{
      grid-template-columns:1fr;
    }
  }
</style>
  <div class="card bd-0 shadow-base mg-b-20">
    <div class="card-header d-flex align-items-center justify-content-between flex-wrap" style="gap:10px;">
      <div>
        <h6 class="card-title tx-uppercase tx-14 mg-b-0">Notification</h6>
        <p class="mg-b-0 tx-12 tx-color-03">
          Your order lifecycle hub — Pending → Paid → Shipping → Delivery. Cancel = you stopped the order; Cancellation = the customer cancelled.
        </p>
      </div>
      <div class="d-flex align-items-center flex-wrap" style="gap:12px;">
        <a
          href="sales_management.php#table_cancel_orders"
          class="btn btn-sm btn-outline-danger"
          data-sales-nav="table_cancel_orders"
        >
          Cancel orders table<?= $cancelTableCount > 0 ? ' (' . ($cancelTableCount > 99 ? '99+' : (int)$cancelTableCount) . ')' : '' ?>
        </a>
        <span class="tx-12 tx-color-03"><?= (int)$alertCount ?> alert<?= $alertCount === 1 ? '' : 's' ?></span>
      </div>
    </div>
    <div class="card-body">
      <div class="org-notif-life">
        <div class="org-notif-life-head">
          <div>
            <h3>Order lifecycle</h3>
            <p>
              Counts update automatically: paid moves Pending → Paid; seller Cancel vs customer Cancellation stay separate; carrier + tracking → Shipping; customer receives → Delivery.
              <?= (int)$lifeTotal ?> order<?= $lifeTotal === 1 ? '' : 's' ?> need your attention across stages.
            </p>
          </div>
        </div>
        <div class="org-notif-life-grid" role="list">
          <?php foreach ($lifeStages as $stage): ?>
            <?php $hot = (int)$stage['count'] > 0; ?>
            <a
              href="<?= h((string)$stage['href']) ?>"
              class="org-notif-life-stage<?= $hot ? ' is-hot' : '' ?>"
              data-sales-nav="<?= h((string)$stage['nav']) ?>"
              role="listitem"
            >
              <span class="org-notif-life-stage-top">
                <span class="org-notif-life-stage-label"><?= h((string)$stage['label']) ?></span>
                <span class="org-notif-life-stage-count"><?= (int)$stage['count'] ?></span>
              </span>
              <span class="org-notif-life-stage-hint"><?= h((string)$stage['hint']) ?></span>
            </a>
          <?php endforeach; ?>
        </div>
      </div>

      <?php if ($notifAlerts): ?>
        <div class="org-notif-section-title">Action alerts</div>
        <div class="commerce-int-grid">
          <?php foreach ($notifAlerts as $alert): ?>
            <?php
              $badgeCount = max(0, (int)($alert['count'] ?? 0));
              $badgeLabel = $badgeCount > 99 ? '99+' : (string)$badgeCount;
              $metaRows = is_array($alert['meta'] ?? null) ? $alert['meta'] : [];
              $isInventory = strtolower((string)($alert['type'] ?? '')) === 'inventory';
            ?>
            <a
              href="<?= h((string)$alert['action']) ?>"
              class="commerce-action-tile org-notif-alert-tile"
              data-sales-nav="<?= h(ltrim((string)parse_url((string)$alert['action'], PHP_URL_FRAGMENT) ?: '', '#')) ?>"
            >
              <i class="icon ion-alert-circled"></i>
              <strong class="org-notif-alert-tile-title">
                <?= h((string)$alert['type']) ?>
                <?php if ($badgeCount > 0): ?>
                  <b class="org-notif-card-badge<?= $isInventory ? ' is-alert' : '' ?>" aria-label="<?= h($badgeLabel . ' items') ?>"><?= h($badgeLabel) ?></b>
                <?php endif; ?>
                <?php if ($metaRows): ?>
                  <span class="org-notif-alert-tile-meta">
                    <?php foreach ($metaRows as $meta): ?>
                      <?php
                        $metaCount = (int)($meta['count'] ?? 0);
                        if ($metaCount <= 0) {
                            continue;
                        }
                        $metaLabel = (string)($meta['label'] ?? '');
                      ?>
                      <span><?= h($metaLabel) ?></span>
                    <?php endforeach; ?>
                  </span>
                <?php endif; ?>
              </strong>
              <span class="org-notif-alert-tile-copy"><?= h((string)$alert['message']) ?></span>
            </a>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <p class="mg-b-0 tx-color-03">No action alerts right now. Lifecycle counts above stay current as orders move.</p>
      <?php endif; ?>

      <?php if ($eventFeed): ?>
        <div class="org-notif-section-title">Recent order updates</div>
        <div class="org-notif-feed">
          <?php foreach (array_slice($eventFeed, 0, 12) as $ev): ?>
            <a
              href="<?= h((string)($ev['action'] ?? 'sales_management.php#orders')) ?>"
              class="org-notif-feed-row"
              data-sales-nav="<?= h(ltrim((string)parse_url((string)($ev['action'] ?? ''), PHP_URL_FRAGMENT) ?: 'orders', '#')) ?>"
            >
              <strong><?= h((string)($ev['title'] ?? $ev['type'] ?? 'Update')) ?></strong>
              <span><?= h((string)($ev['message'] ?? '')) ?></span>
              <?php if (trim((string)($ev['when'] ?? '')) !== ''): ?>
                <span class="org-notif-feed-when"><?= h((string)$ev['when']) ?></span>
              <?php endif; ?>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
