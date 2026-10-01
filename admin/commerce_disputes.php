<?php
declare(strict_types=1);

/**
 * Admin — product/seller business dispute cases (from buyer Product Report → Support Center).
 */
require_once __DIR__ . '/includes/org_admin_helpers_load.php';
require_once __DIR__ . '/../public_user/includes/commerce_disputes.php';
require_once __DIR__ . '/../public_user/includes/commerce_messaging.php';
org_admin_require_admin();

$dbh = org_admin_db();
$flashOk = '';
$flashErr = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = strtolower(trim((string)($_POST['action'] ?? '')));
    $disputeId = (int)($_POST['dispute_id'] ?? 0);
    $note = trim((string)($_POST['note'] ?? ''));
    $res = ['ok' => false, 'error' => 'Unknown action.'];
    if ($action === 'notify_seller') {
        $res = commerce_dispute_notify_seller($dbh, $disputeId, $note);
    } elseif ($action === 'close_customer') {
        $res = ['ok' => commerce_dispute_close_customer($dbh, $disputeId, $note), 'error' => 'Could not close customer case.'];
    } elseif ($action === 'close_seller') {
        $res = ['ok' => commerce_dispute_close_seller($dbh, $disputeId, $note), 'error' => 'Could not close seller case.'];
    } elseif ($action === 'refund_suspend') {
        $res = commerce_dispute_refund_and_suspend($dbh, $disputeId, $note);
    }
    if (!empty($res['ok'])) {
        if ($action === 'notify_seller') {
            $flashOk = 'Seller notified with Dispute ID. 30-day response window started.';
        } elseif ($action === 'close_customer') {
            $flashOk = 'Customer case closed.';
        } elseif ($action === 'close_seller') {
            $flashOk = 'Seller case closed.';
        } elseif ($action === 'refund_suspend') {
            $flashOk = 'Customer refund marked; seller shop temporarily suspended until payback.';
        } else {
            $flashOk = 'Updated.';
        }
    } else {
        $flashErr = (string)($res['error'] ?? 'Action failed.');
    }
}

$cases = commerce_dispute_list_open_admin($dbh, 80);
// Also show recently resolved/suspended for context
$recentClosed = [];
try {
    commerce_disputes_ensure_schema($dbh);
    $st = $dbh->query("
        SELECT * FROM commerce_disputes
        WHERE status IN ('resolved', 'refunded_suspended')
        ORDER BY updated_at DESC
        LIMIT 20
    ");
    $recentClosed = $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
} catch (Throwable $e) {
    $recentClosed = [];
}

function cd_h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function cd_cover(PDO $dbh, array $case): string
{
    $path = trim((string)($case['product_cover_path'] ?? ''));
    if ($path === '') {
        return '';
    }
    require_once __DIR__ . '/../public_user/includes/org_shop.php';
    return function_exists('org_shop_cover_url') ? org_shop_cover_url($path) : '';
}

org_admin_render_head('Commerce Disputes');
require_once __DIR__ . '/includes/admin_chrome.php';
admin_chrome_open(null, [
    'title' => 'Commerce Disputes',
    'description' => 'Product and seller dispute cases from buyer reports.',
]);
?>
<style>
  .sh-mainpanel{
    padding-top:100px !important;
  }
  .sh-mainpanel > .sh-pagebody{
    overflow:hidden !important;display:flex !important;flex-direction:column !important;min-height:0 !important;
    padding-top:16px !important;padding-bottom:12px !important;flex:1 1 auto;background:var(--msb-palette-bg,#f4f6fb);
  }
  .cd-wrap{flex:1 1 auto;min-height:0;overflow:auto;max-width:1100px;width:100%;margin:0 auto;padding:12px 12px 28px;box-sizing:border-box;}
  .cd-head{display:flex;align-items:flex-end;justify-content:space-between;gap:12px;margin:0 0 18px;flex-wrap:wrap;}
  .cd-head h1{margin:0;font-size:18px;font-weight:800;color:var(--azia-text,#0f172a);}
  .cd-head p{margin:4px 0 0;color:var(--azia-muted,#64748b);font-size:13px;max-width:52rem;}
  .cd-alert{padding:10px 12px;border-radius:8px;margin-bottom:12px;font-size:13px;font-weight:600;}
  .cd-alert.ok{background:#dcfce7;color:#166534;}
  .cd-alert.err{background:#fee2e2;color:#991b1b;}
  .cd-card{border:1px solid #e2e8f0;border-radius:12px;background:var(--azia-card,#fff);margin-bottom:12px;overflow:hidden;}
  .cd-card-top{display:grid;grid-template-columns:56px minmax(0,1fr) auto;gap:12px;align-items:center;padding:12px 14px;border-bottom:1px solid #f1f5f9;}
  .cd-card-top img{width:56px;height:56px;border-radius:8px;object-fit:cover;background:#e2e8f0;}
  .cd-card-top strong{display:block;font-size:14px;font-weight:800;}
  .cd-meta{display:block;margin-top:2px;font-size:12px;color:var(--azia-muted,#64748b);font-weight:600;}
  .cd-badge{display:inline-block;padding:4px 8px;border-radius:999px;font-size:11px;font-weight:800;background:#eff6ff;color:#1d4ed8;}
  .cd-badge.warn{background:#ffedd5;color:#c2410c;}
  .cd-badge.bad{background:#fee2e2;color:#b91c1c;}
  .cd-badge.ok{background:#dcfce7;color:#166534;}
  .cd-body{padding:12px 14px;}
  .cd-actions{display:flex;flex-wrap:wrap;gap:8px;margin-top:10px;}
  .cd-actions form{display:inline-flex;flex-wrap:wrap;gap:6px;align-items:center;}
  .cd-actions button,.cd-actions a.btn,.cd-actions a.btn-muted{
    border:0;border-radius:8px;padding:8px 12px;font-size:12px;font-weight:800;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;
  }
  .cd-actions .btn-primary{background:#2563eb;color:#fff;}
  .cd-actions .btn-muted{background:#f1f5f9;color:var(--azia-text,#334155);}
  .cd-actions .btn-danger{background:#dc2626;color:#fff;}
  .cd-actions input[type=text]{height:34px;border:1px solid #e2e8f0;border-radius:8px;padding:0 10px;font-size:12px;min-width:180px;}
  .cd-empty{padding:28px;text-align:center;color:var(--azia-muted,#64748b);font-size:14px;}
  .cd-head-link{padding:8px 12px;border-radius:8px;background:#f1f5f9;color:var(--azia-text,#334155);font-weight:800;text-decoration:none;font-size:12px;display:inline-flex;align-items:center;}
  .cd-head-link:hover{background:#e2e8f0;color:var(--azia-text,#0f172a);text-decoration:none;}
</style>
<div class="sh-mainpanel">
  <div class="sh-pagebody">
<div class="cd-wrap">
  <div class="cd-head">
    <div>
      <h1>Product disputes</h1>
      <p>Cases from buyer Product Report → Support Center. Open to seller with Dispute ID (30 days). Close both sides when resolved, or refund + suspend if seller does not respond.</p>
    </div>
    <a class="cd-head-link" href="dispute.php">Chat disputes inbox</a>
  </div>
  <?php if ($flashOk !== ''): ?><div class="cd-alert ok"><?= cd_h($flashOk) ?></div><?php endif; ?>
  <?php if ($flashErr !== ''): ?><div class="cd-alert err"><?= cd_h($flashErr) ?></div><?php endif; ?>

  <?php if (!$cases): ?>
    <div class="cd-card"><div class="cd-empty">No open product dispute cases.</div></div>
  <?php endif; ?>

  <?php foreach ($cases as $case):
    $id = (int)($case['id'] ?? 0);
    $code = trim((string)($case['dispute_code'] ?? '')) ?: commerce_dispute_format_id($id);
    $status = strtolower(trim((string)($case['status'] ?? 'open')));
    $cover = cd_cover($dbh, $case);
    $title = trim((string)($case['product_title'] ?? 'Product'));
    $biz = trim((string)($case['seller_business_name'] ?? 'Seller'));
    $pid = (int)($case['product_id'] ?? 0);
    $due = trim((string)($case['seller_response_due_at'] ?? ''));
    $dueTs = $due !== '' ? strtotime($due) : false;
    $overdue = $status === 'seller_notified' && $dueTs && $dueTs < time();
    $badgeClass = $status === 'seller_notified' ? ($overdue ? 'bad' : 'warn') : '';
    $buyerChat = commerce_dispute_buyer_chat_url($case);
    $statusLabel = $status === 'seller_notified' ? ($overdue ? 'Overdue — no seller reply' : 'Seller notified (30 days)') : 'Open — Admin review';
  ?>
    <div class="cd-card">
      <div class="cd-card-top">
        <?php if ($cover !== ''): ?>
          <img src="<?= cd_h($cover) ?>" alt="">
        <?php else: ?>
          <img src="../public_user/avatar.php?name=<?= rawurlencode($title) ?>" alt="">
        <?php endif; ?>
        <div>
          <strong><?= cd_h($title) ?></strong>
          <span class="cd-meta"><?= cd_h($code) ?> · Product ID #<?= (int)$pid ?> · <?= cd_h($biz) ?></span>
          <span class="cd-meta">Buyer #<?= (int)($case['buyer_user_id'] ?? 0) ?> · Seller user #<?= (int)($case['publisher_user_id'] ?? 0) ?></span>
          <?php if ($dueTs): ?>
            <span class="cd-meta">Seller response due <?= cd_h(date('M j, Y', $dueTs)) ?></span>
          <?php endif; ?>
        </div>
        <span class="cd-badge <?= cd_h($badgeClass) ?>"><?= cd_h($statusLabel) ?></span>
      </div>
      <div class="cd-body">
        <?php if (trim((string)($case['buyer_message'] ?? '')) !== ''): ?>
          <p style="margin:0 0 8px;font-size:13px;line-height:1.45;color:var(--azia-text,#334155);"><?= nl2br(cd_h((string)$case['buyer_message'])) ?></p>
        <?php endif; ?>
        <div class="cd-actions">
          <?php if ($status === 'open' || ($status === 'seller_notified' && (int)($case['seller_case_closed'] ?? 0) === 0)): ?>
            <form method="post">
              <input type="hidden" name="dispute_id" value="<?= $id ?>">
              <input type="hidden" name="action" value="notify_seller">
              <input type="text" name="note" placeholder="Optional note to seller" maxlength="500">
              <button type="submit" class="btn-primary">Open dispute to seller (Dispute ID + 30 days)</button>
            </form>
          <?php endif; ?>
          <a class="btn-muted" href="../organization/<?= cd_h(ltrim($buyerChat, '/')) ?>">Message buyer about product</a>
          <?php if ((int)($case['customer_case_closed'] ?? 0) === 0): ?>
            <form method="post">
              <input type="hidden" name="dispute_id" value="<?= $id ?>">
              <input type="hidden" name="action" value="close_customer">
              <button type="submit" class="btn-muted">Close customer case</button>
            </form>
          <?php endif; ?>
          <?php if ((int)($case['seller_case_closed'] ?? 0) === 0): ?>
            <form method="post">
              <input type="hidden" name="dispute_id" value="<?= $id ?>">
              <input type="hidden" name="action" value="close_seller">
              <button type="submit" class="btn-muted">Close seller case</button>
            </form>
          <?php endif; ?>
          <?php if ($status === 'seller_notified' || $overdue): ?>
            <form method="post" onsubmit="return confirm('Refund customer and temporarily suspend this seller shop until they pay Admin back?');">
              <input type="hidden" name="dispute_id" value="<?= $id ?>">
              <input type="hidden" name="action" value="refund_suspend">
              <button type="submit" class="btn-danger">No seller reply → refund + suspend shop</button>
            </form>
          <?php endif; ?>
        </div>
      </div>
    </div>
  <?php endforeach; ?>

  <?php if ($recentClosed): ?>
    <h2 style="margin:24px 0 10px;font-size:16px;font-weight:800;">Recently closed</h2>
    <?php foreach ($recentClosed as $case):
      $id = (int)($case['id'] ?? 0);
      $code = trim((string)($case['dispute_code'] ?? '')) ?: commerce_dispute_format_id($id);
      $status = strtolower(trim((string)($case['status'] ?? '')));
      $title = trim((string)($case['product_title'] ?? 'Product'));
      $biz = trim((string)($case['seller_business_name'] ?? 'Seller'));
    ?>
      <div class="cd-card">
        <div class="cd-card-top">
          <div style="width:56px;height:56px;border-radius:8px;background:#f1f5f9;"></div>
          <div>
            <strong><?= cd_h($title) ?></strong>
            <span class="cd-meta"><?= cd_h($code) ?> · <?= cd_h($biz) ?></span>
          </div>
          <span class="cd-badge <?= $status === 'refunded_suspended' ? 'bad' : 'ok' ?>"><?= cd_h($status) ?></span>
        </div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>
  </div>
</div>
<?php org_admin_render_foot(); ?>
