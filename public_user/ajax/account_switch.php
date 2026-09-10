<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/session_user.php';
requireUserLogin();
require_once __DIR__ . '/../controller.php';
require_once __DIR__ . '/../includes/account_switch.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    $dbh = (new Controller())->pdo();
    $meId = (int)($_SESSION['user_id'] ?? 0);
    $staffBlocked = account_switch_is_staff_session();
    $accounts = ($staffBlocked || $meId <= 0) ? [] : account_switch_list($dbh, $meId);
    echo json_encode([
        'ok' => true,
        'staff_blocked' => $staffBlocked,
        'current_user_id' => $meId,
        'accounts' => $accounts,
        'users' => $accounts,
        'csrf_token' => function_exists('csrfToken') ? csrfToken() : (string)($_SESSION['csrf_token'] ?? ''),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST required']);
    exit;
}

if (account_switch_is_staff_session()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Staff sessions cannot switch accounts here.']);
    exit;
}

$postedCsrf = trim((string)($_POST['csrf_token'] ?? ''));
$sessionCsrf = trim((string)($_SESSION['csrf_token'] ?? ''));
if ($sessionCsrf !== '' && $postedCsrf !== '' && !hash_equals($sessionCsrf, $postedCsrf)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Session expired. Refresh and try again.']);
    exit;
}

$fromId = (int)($_SESSION['user_id'] ?? 0);
$wantNext = in_array(strtolower(trim((string)($_POST['action'] ?? $_POST['next'] ?? ''))), ['next', '1', 'true', 'yes'], true);
$toId = (int)($_POST['target_user_id'] ?? 0);

try {
    $dbh = (new Controller())->pdo();
    $result = $wantNext
        ? account_switch_next($dbh, $fromId)
        : account_switch_apply($dbh, $fromId, $toId);
    echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Unable to switch accounts right now.']);
}
