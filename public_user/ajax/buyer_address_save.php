<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/session_user.php';
require_once __DIR__ . '/../controller.php';
require_once __DIR__ . '/../includes/buyer_shipping.php';
require_once __DIR__ . '/../includes/user_phone.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$meId = (int)($_SESSION['user_id'] ?? $_SESSION['id'] ?? $_SESSION['userid'] ?? 0);
if ($meId <= 0) {
    echo json_encode(['ok' => false, 'message' => 'Please sign in.']);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    echo json_encode(['ok' => false, 'message' => 'POST required.']);
    exit;
}

$dbh = (new Controller())->pdo();
$action = strtolower(trim((string)($_POST['action'] ?? 'save')));
$addressId = (int)($_POST['address_id'] ?? 0);

if ($action === 'delete') {
    $ok = buyer_shipping_delete($dbh, $meId, $addressId);
    echo json_encode(['ok' => $ok, 'message' => $ok ? 'Address removed.' : 'Could not remove address.']);
    exit;
}

if ($action === 'default') {
    $ok = buyer_shipping_set_default($dbh, $meId, $addressId);
    echo json_encode(['ok' => $ok, 'message' => $ok ? 'Default address updated.' : 'Could not set default address.']);
    exit;
}

$res = buyer_shipping_save($dbh, $meId, [
    'label' => $_POST['label'] ?? 'Home',
    'full_name' => $_POST['full_name'] ?? '',
    'phone' => $_POST['phone'] ?? '',
    'line1' => $_POST['line1'] ?? '',
    'line2' => $_POST['line2'] ?? '',
    'city' => $_POST['city'] ?? '',
    'region' => $_POST['region'] ?? '',
    'postal_code' => $_POST['postal_code'] ?? '',
    'country' => $_POST['country'] ?? 'US',
    'is_default' => !empty($_POST['is_default']) && (string)$_POST['is_default'] !== '0',
], $addressId);

// Same as Your_Shopping_preferences.php: a valid shipping phone also updates the account phone.
if (!empty($res['ok'])) {
    $phoneSync = trim((string)($_POST['phone'] ?? ''));
    if ($phoneSync !== '' && function_exists('user_phone_is_valid') && user_phone_is_valid($phoneSync)) {
        try {
            $stPhone = $dbh->prepare('UPDATE users SET mobile = :mobile WHERE id = :id LIMIT 1');
            $stPhone->execute([':mobile' => mb_substr(user_phone_normalize($phoneSync), 0, 40), ':id' => $meId]);
        } catch (Throwable $e) {
            // Address is saved; profile phone sync is best-effort.
        }
    }
}

echo json_encode([
    'ok' => !empty($res['ok']),
    'id' => (int)($res['id'] ?? $addressId),
    'message' => !empty($res['ok']) ? 'Address and contact details updated.' : (string)($res['error'] ?? 'Could not save address.'),
]);
