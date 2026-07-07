<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/session_user.php';
requireUserLogin();

require_once __DIR__ . '/controller.php';
require_once __DIR__ . '/includes/org_shop.php';
require_once __DIR__ . '/includes/stripe_shop.php';

$controller = new Controller();
$dbh = $controller->pdo();
$meId = (int)($_SESSION['user_id'] ?? $_SESSION['id'] ?? $_SESSION['userid'] ?? 0);

$msg = '';
$error = '';
$sessionId = trim((string)($_GET['session_id'] ?? ''));

if ($sessionId !== '') {
    $session = stripe_shop_retrieve_session($sessionId);
    if ($session && org_shop_fulfill_stripe_session($dbh, $session)) {
        $code = trim((string)($session['client_reference_id'] ?? ''));
        $msg = $code !== ''
            ? 'Payment received. Order ' . $code . ' is confirmed.'
            : 'Payment received. Your order is confirmed.';
    } elseif ($session && (string)($session['payment_status'] ?? '') !== 'paid') {
        $error = 'Payment was not completed. You can retry from the shop.';
    } else {
        $error = 'Could not verify payment. Contact support if you were charged.';
    }
}

if ((string)($_GET['checkout'] ?? '') === 'cancel') {
    $error = $error !== '' ? $error : 'Checkout was cancelled.';
}

$orders = org_shop_list_buyer_orders($dbh, $meId);

function my_orders_fmt_dt(?string $dt): string
{
    if (!$dt) {
        return '—';
    }
    $t = strtotime($dt);
    return $t ? date('M d, Y h:i A', $t) : '—';
}

function my_orders_status_badge(string $status): string
{
    $map = [
        'pending' => 'badge-warning',
        'confirmed' => 'badge-info',
        'paid' => 'badge-success',
        'shipped' => 'badge-primary',
        'delivered' => 'badge-success',
        'cancelled' => 'badge-secondary',
    ];
    return $map[$status] ?? 'badge-light';
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>My Orders</title>
  <?php
  require_once __DIR__ . '/includes/theme_prefs.php';
  theme_prefs_print_head_bootstrap($dbh, $meId);
  ?>
  <link rel="stylesheet" href="css/font-awesome.min.css">
  <link rel="stylesheet" href="css/bootstrap.min.css">
  <link rel="stylesheet" href="css/style.css">
  <link href="./lib/font-awesome/css/font-awesome.css" rel="stylesheet">
  <link href="./lib/Ionicons/css/ionicons.css" rel="stylesheet">
  <link rel="stylesheet" href="./css/shamcey.css">
  <link rel="stylesheet" href="assets/ui_best.css">
  <link rel="stylesheet" href="assets/layout-fixed.css">
  <script defer src="assets/layout-fixed.js"></script>
  <style>
    html, body { height:100%; overflow:hidden; }
    .sh-mainpanel {
      height:80vh; display:flex; flex-direction:column; overflow:hidden;
      margin-left:340px; margin-top:20px;
    }
    .sh-pagebody { flex:1 1 auto; min-height:0; overflow:hidden; display:flex; flex-direction:column; }
    .orders-shell { flex:1 1 auto; min-height:0; display:flex; flex-direction:column; }
    .orders-scroll { flex:1 1 auto; min-height:0; overflow:auto; }
    .orders-table-wrap { overflow:auto; }
    @media (max-width: 991.98px) {
      html, body { height:auto !important; overflow:auto !important; }
      .sh-mainpanel { margin-left:0 !important; margin-top:0 !important; height:auto !important; min-height:100vh; }
      .sh-pagebody, .orders-shell { overflow:visible !important; height:auto !important; }
    }
  </style>
</head>
<body class="my-orders-page">

<?php $forceFeedRail = true; $skipHeaderThemeBootstrap = true; include __DIR__ . '/includes/header.php'; ?>

<div class="sh-mainpanel">
  <div class="sh-pagebody">
    <div class="orders-shell">
      <div class="container-fluid pd-20">
        <h2 class="page-title">My Orders</h2>
        <?php if ($error !== ''): ?><div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
        <?php if ($msg !== ''): ?><div class="alert alert-success"><?= htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
      </div>
      <div class="orders-scroll">
        <div class="container-fluid pd-20 pt-0">
          <div class="panel panel-default">
            <div class="panel-body">
              <?php if (!$orders): ?>
                <div class="alert alert-info">You have not placed any shop orders yet.</div>
              <?php else: ?>
                <div class="orders-table-wrap">
                  <table class="table table-striped table-bordered">
                    <thead>
                      <tr>
                        <th>Code</th>
                        <th>Product</th>
                        <th>Seller</th>
                        <th>Total</th>
                        <th>Status</th>
                        <th>Receipt</th>
                        <th>Date</th>
                      </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($orders as $o): ?>
                      <?php
                        $total = org_shop_format_price((int)($o['total_cents'] ?? 0), (string)($o['currency'] ?? 'USD'));
                        $status = (string)($o['status'] ?? 'pending');
                        $publisherId = (int)($o['publisher_user_id'] ?? 0);
                      ?>
                      <tr>
                        <td><code><?= htmlspecialchars((string)($o['order_code'] ?? ''), ENT_QUOTES, 'UTF-8') ?></code></td>
                        <td>
                          <?= htmlspecialchars((string)($o['product_title'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                          <div class="text-muted small">Qty <?= (int)($o['quantity'] ?? 1) ?></div>
                        </td>
                        <td>
                          <?php if ($publisherId > 0): ?>
                            <a href="profile.php?tab=shop&amp;id=<?= $publisherId ?>"><?= htmlspecialchars((string)($o['seller_name'] ?? 'Shop'), ENT_QUOTES, 'UTF-8') ?></a>
                          <?php else: ?>
                            <?= htmlspecialchars((string)($o['seller_name'] ?? '—'), ENT_QUOTES, 'UTF-8') ?>
                          <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars($total, ENT_QUOTES, 'UTF-8') ?></td>
                        <td><span class="badge <?= my_orders_status_badge($status) ?>"><?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?></span></td>
                        <td>
                          <?php if (!empty($o['receipt_code'])): ?>
                            <code><?= htmlspecialchars((string)$o['receipt_code'], ENT_QUOTES, 'UTF-8') ?></code>
                          <?php else: ?>
                            —
                          <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars(my_orders_fmt_dt((string)($o['created_at'] ?? '')), ENT_QUOTES, 'UTF-8') ?></td>
                      </tr>
                    <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<script src="js/jquery.min.js"></script>
<script src="js/bootstrap.min.js"></script>
</body>
</html>
