<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/session_user.php';
requireUserLogin();

require_once __DIR__ . '/controller.php';
require_once __DIR__ . '/includes/org_shop.php';
require_once __DIR__ . '/includes/stripe_shop.php';

$sessionId = trim((string)($_GET['session_id'] ?? ''));
if ($sessionId === '') {
    header('Location: my_orders.php');
    exit;
}

$controller = new Controller();
$dbh = $controller->pdo();

$session = stripe_shop_retrieve_session($sessionId);
if ($session) {
    org_shop_fulfill_stripe_session($dbh, $session);
}

$fallback = 'my_orders.php?session_id=' . rawurlencode($sessionId) . '&paid=1';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Payment received</title>
</head>
<body>
  <p style="font-family:system-ui,sans-serif;padding:24px;">Payment received. Continuing…</p>
  <script>
  (function(){
    var fallback = <?= json_encode($fallback, JSON_UNESCAPED_SLASHES) ?>;
    try {
      var raw = sessionStorage.getItem('msb_cart_checkout_queue') || '[]';
      var urls = JSON.parse(raw);
      if (Array.isArray(urls) && urls.length) {
        var next = String(urls.shift() || '').trim();
        sessionStorage.setItem('msb_cart_checkout_queue', JSON.stringify(urls));
        if (next) {
          window.location.replace(next);
          return;
        }
      } else {
        sessionStorage.removeItem('msb_cart_checkout_queue');
      }
    } catch (e) {}
    window.location.replace(fallback);
  })();
  </script>
</body>
</html>
