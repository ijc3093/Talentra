<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/session_org.php';
require_once __DIR__ . '/includes/org_context.php';
require_once __DIR__ . '/includes/org_manager_guard.php';
require_once __DIR__ . '/includes/org_sales.php';

org_require_manager();

org_require_commerce_seller();
$orgId = (int)orgActiveOrgId();
$alerts = org_sales_notifications($dbh, $orgId);
$pageTitle = 'Sales Notifications';
require_once __DIR__ . '/includes/org_page_shell.php';
org_page_shell_open($pageTitle, '<link rel="stylesheet" href="css/commerce-hub.css?v=14">');
?>
<?php org_page_body_open('commerce-page'); ?>
  <div class="mg-b-20"><a href="sales_management.php" class="tx-12">&larr; Sales management</a><h4 class="mg-b-0">Sales notifications</h4><p class="tx-color-03">Order cancels, returns/refunds, shipping, delivery, payments, inventory, and quotes. Also available in Sales management → Notification.</p></div>
  <div class="commerce-panel">
    <div class="commerce-panel-head"><h2>Action alerts</h2><span><?= count($alerts) ?> active</span></div>
    <div class="commerce-int-grid">
      <?php if (!$alerts): ?><div class="commerce-empty"><i class="icon ion-checkmark-round"></i><p>No seller alerts right now.</p></div><?php endif; ?>
      <?php foreach ($alerts as $alert): ?>
        <?php $badgeCount = max(0, (int)($alert['count'] ?? 0)); $badgeLabel = $badgeCount > 99 ? '99+' : (string)$badgeCount; ?>
        <a href="<?= org_ecommerce_h($alert['action']) ?>" class="commerce-action-tile" style="position:relative;">
          <i class="icon ion-alert-circled"></i>
          <strong style="display:flex;align-items:center;gap:8px;">
            <?= org_ecommerce_h($alert['type']) ?>
            <?php if ($badgeCount > 0): ?>
              <b class="org-notif-card-badge" style="display:inline-flex;align-items:center;justify-content:center;min-width:26px;height:26px;padding:0 8px;border-radius:999px;background:#dc3545!important;color:#ffffff!important;font-size:14px!important;font-weight:800!important;"><?= org_ecommerce_h($badgeLabel) ?></b>
            <?php endif; ?>
          </strong>
          <span><?= org_ecommerce_h($alert['message']) ?></span>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
</div>
<?php org_page_shell_close(); ?>
