<?php
require_once __DIR__ . '/../includes/session_user.php';
requireUserLogin();

require_once __DIR__ . '/../controller.php';

header('Content-Type: application/json');
sendNoCacheHeadersUser();

$controller = new Controller();
$dbh = $controller->pdo();

/**
 * We want unread badge to work for:
 * - NEW rows (friend_code in sender/receiver)
 * - OLD rows (email in sender/receiver)
 */
$meCode  = function_exists('userFriendCode') ? userFriendCode() : '';
$meEmail = function_exists('userEmail') ? userEmail() : '';

$meCode  = trim($meCode);
$meEmail = trim($meEmail);

if ($meCode === '' && $meEmail === '') {
    echo json_encode(['ok' => false, 'error' => 'missing_identity']);
    exit;
}

// Build receiver IN (...) dynamically
$receiverVals = [];
if ($meCode !== '')  $receiverVals[] = $meCode;
if ($meEmail !== '') $receiverVals[] = $meEmail;

$placeholders = implode(',', array_fill(0, count($receiverVals), '?'));

$sql = "
    SELECT COUNT(*)
    FROM feedback
    WHERE channel = 'user_user'
      AND is_read = 0
      AND receiver IN ($placeholders)
";

$st = $dbh->prepare($sql);
$st->execute($receiverVals);

echo json_encode([
    'ok' => true,
    'unread' => (int)$st->fetchColumn()
]);
