<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/session_user.php';
require_once __DIR__ . '/../controller.php';
require_once __DIR__ . '/../includes/buyer_shipping.php';

header('Content-Type: application/json; charset=utf-8');

$controller = new Controller();
$dbh = $controller->pdo();
$meId = (int)($_SESSION['user_id'] ?? $_SESSION['id'] ?? $_SESSION['userid'] ?? 0);

if ($meId <= 0) {
    echo json_encode(['ok' => false, 'message' => 'Please sign in.']);
    exit;
}

$addressText = trim((string)($_POST['delivery_address'] ?? ''));
$phone = trim((string)($_POST['phone'] ?? ''));

$result = buyer_shipping_sync_door_address($dbh, $meId, $addressText, $phone);

if (!empty($result['ok']) && $phone !== '') {
    try {
        require_once __DIR__ . '/../includes/user_phone.php';
        if (function_exists('user_phone_is_valid') && user_phone_is_valid($phone)) {
            $normalizedPhone = user_phone_normalize($phone);
            $stPhone = $dbh->prepare('UPDATE users SET mobile = :mobile WHERE id = :id LIMIT 1');
            $stPhone->execute([':mobile' => mb_substr($normalizedPhone, 0, 40), ':id' => $meId]);
        }
    } catch (Throwable $e) {
        // ignore profile sync failure
    }
}

echo json_encode([
    'ok' => !empty($result['ok']),
    'message' => !empty($result['ok'])
        ? 'Address saved for checkout.'
        : (string)($result['error'] ?? 'Could not save address.'),
    'address_text' => (string)($result['address_text'] ?? $addressText),
]);
