<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/session_user.php';
require_once __DIR__ . '/../controller.php';
require_once __DIR__ . '/../includes/org_shop.php';

header('Content-Type: application/json; charset=utf-8');

$controller = new Controller();
$dbh = $controller->pdo();
$meId = (int)($_SESSION['user_id'] ?? $_SESSION['id'] ?? $_SESSION['userid'] ?? 0);

if ($meId <= 0) {
    echo json_encode(['ok' => false, 'message' => 'Please sign in.']);
    exit;
}

$orderId = (int)($_POST['order_id'] ?? 0);
$parseDollarsToCents = static function (string $raw): int {
    $cleaned = preg_replace('/[^0-9.]/', '', trim($raw)) ?? '';
    if ($cleaned === '' || $cleaned === '.') {
        return 0;
    }
    if (!is_numeric($cleaned)) {
        return 0;
    }
    return max(0, (int)round(((float)$cleaned) * 100));
};

$addCents = (int)($_POST['amount_cents'] ?? 0);
if ($addCents <= 0) {
    $addCents = $parseDollarsToCents((string)($_POST['amount'] ?? ''));
}
if ($addCents <= 0) {
    // Default to remaining shortfall when amount omitted.
    try {
        $st = $dbh->prepare('SELECT * FROM org_orders WHERE id = :id AND buyer_user_id = :uid LIMIT 1');
        $st->execute([':id' => $orderId, ':uid' => $meId]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($row && function_exists('org_shop_order_payment_progress')) {
            $addCents = (int)(org_shop_order_payment_progress($row)['shortfall_cents'] ?? 0);
        }
    } catch (Throwable $e) {
        $addCents = 0;
    }
}

$result = org_shop_buyer_complete_order_payment($dbh, $meId, $orderId, $addCents);
if (empty($result['ok'])) {
    echo json_encode(['ok' => false, 'message' => (string)($result['error'] ?? 'Payment failed.')]);
    exit;
}

$status = (string)($result['status'] ?? 'pending');
$paid = (int)($result['amount_paid_cents'] ?? 0);
$short = (int)($result['shortfall_cents'] ?? 0);
$due = (int)($result['total_cents'] ?? 0);
$currency = 'USD';

if ($status === 'paid') {
    echo json_encode([
        'ok' => true,
        'status' => 'paid',
        'message' => 'Payment complete. The seller can start shipping.',
        'amount_paid_cents' => $paid,
        'shortfall_cents' => 0,
        'total_cents' => $due,
        'currency' => $currency,
    ]);
    exit;
}

echo json_encode([
    'ok' => true,
    'status' => 'pending',
    'message' => 'Payment updated. Still due '
        . org_shop_format_price($short, $currency)
        . ' before shipping can start.',
    'amount_paid_cents' => $paid,
    'shortfall_cents' => $short,
    'total_cents' => $due,
    'currency' => $currency,
]);
