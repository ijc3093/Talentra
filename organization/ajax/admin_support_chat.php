<?php
declare(strict_types=1);

/**
 * Seller ↔ Admin support chat (Sales Support Center).
 */

require_once __DIR__ . '/../includes/session_org.php';
require_once __DIR__ . '/../includes/org_context.php';
require_once __DIR__ . '/../includes/org_manager_guard.php';
require_once __DIR__ . '/../../public_user/includes/staff_publisher_access.php';
require_once __DIR__ . '/../../public_user/includes/admin_support_chat.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function sasc_json(array $a): void
{
    echo json_encode($a, JSON_UNESCAPED_UNICODE);
    exit;
}

org_require_manager();
org_require_commerce_seller();

$orgId = (int)orgActiveOrgId();
$publisherUserId = staff_pub_org_publisher_user_id($dbh, $orgId);
if ($publisherUserId <= 0) {
    $publisherUserId = (int)($_SESSION['org_publisher_user_id'] ?? 0);
}
if ($publisherUserId <= 0) {
    sasc_json(['ok' => false, 'error' => 'No publisher account linked to this shop.']);
}

$meEmail = admin_support_user_email($dbh, $publisherUserId);
if ($meEmail === '') {
    sasc_json(['ok' => false, 'error' => 'Missing seller account email.']);
}

$mode = strtolower(trim((string)($_GET['mode'] ?? $_POST['mode'] ?? 'history')));

if ($mode === 'send') {
    $topic = strtolower(trim((string)($_POST['topic'] ?? 'seller_help')));
    $text = (string)($_POST['message'] ?? '');
    $orderCode = trim((string)($_POST['order_code'] ?? ''));
    $extra = [];
    if ($orgId > 0) {
        $extra[] = 'Org ID: ' . $orgId;
    }
    if ($orderCode !== '') {
        $extra[] = 'Order: ' . $orderCode;
    }
    $result = admin_support_send(
        $dbh,
        $meEmail,
        $text,
        $topic,
        'seller',
        $extra ? implode("\n", $extra) : null
    );
    sasc_json($result);
}

$after = (int)($_GET['after'] ?? $_POST['after'] ?? 0);
$mark = !isset($_GET['mark']) || (string)$_GET['mark'] !== '0';
$result = admin_support_poll($dbh, $meEmail, $after, $mark);
sasc_json($result);
