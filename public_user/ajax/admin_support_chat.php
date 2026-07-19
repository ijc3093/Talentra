<?php
declare(strict_types=1);

/**
 * Customer ↔ Admin support chat (Support Center).
 */

require_once __DIR__ . '/../includes/session_user.php';
requireUserLogin();

require_once __DIR__ . '/../controller.php';
require_once __DIR__ . '/../includes/admin_support_chat.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function asc_json(array $a): void
{
    echo json_encode($a, JSON_UNESCAPED_UNICODE);
    exit;
}

$controller = new Controller();
$dbh = $controller->pdo();

$meId = (int)($_SESSION['user_id'] ?? $_SESSION['id'] ?? $_SESSION['userid'] ?? 0);
if ($meId <= 0 && function_exists('myUserId')) {
    $meId = (int)myUserId();
}
$meEmail = admin_support_user_email($dbh, $meId);
if ($meEmail === '' && function_exists('myUserEmail')) {
    $meEmail = trim((string)myUserEmail());
}
if ($meEmail === '') {
    asc_json(['ok' => false, 'error' => 'Missing account email.']);
}

$mode = strtolower(trim((string)($_GET['mode'] ?? $_POST['mode'] ?? 'history')));

if ($mode === 'send') {
    $topic = strtolower(trim((string)($_POST['topic'] ?? 'help')));
    $text = (string)($_POST['message'] ?? '');
    $orderCode = trim((string)($_POST['order_code'] ?? ''));
    $sellerName = trim((string)($_POST['seller_name'] ?? ''));
    $extra = [];
    if ($orderCode !== '') {
        $extra[] = 'Order: ' . $orderCode;
    }
    if ($sellerName !== '') {
        $extra[] = 'Seller: ' . $sellerName;
    }
    $result = admin_support_send(
        $dbh,
        $meEmail,
        $text,
        $topic,
        'customer',
        $extra ? implode("\n", $extra) : null
    );
    asc_json($result);
}

$after = (int)($_GET['after'] ?? $_POST['after'] ?? 0);
$mark = !isset($_GET['mark']) || (string)$_GET['mark'] !== '0';
$result = admin_support_poll($dbh, $meEmail, $after, $mark);
asc_json($result);
