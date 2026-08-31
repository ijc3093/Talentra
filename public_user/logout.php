<?php
require_once __DIR__ . '/includes/session_user.php';
require_once __DIR__ . '/controller.php';
require_once __DIR__ . '/includes/appearance_bridge.php';

$controller = new Controller();
$username = (string)($_SESSION['user_name'] ?? '');
$controller->logSecurity('user_logout', true, userUsername(), $username);

$logoutUid = (int)($_SESSION['user_id'] ?? 0);
try {
    if ($logoutUid > 0) {
        appearance_bridge_write_cookie(appearance_bridge_user_mode($controller->pdo(), $logoutUid));
    }
} catch (Throwable $e) {
    // keep logout going even if appearance cookie cannot be stored
}

try {
    revokeCurrentUserSession($controller->pdo());
} catch (Throwable $e) {
    // keep logout resilient even if SQL session revoke fails
}

clearUserSession();
$accountType = strtolower(trim((string)($_GET['account_type'] ?? '')));
if (!in_array($accountType, ['personal', 'publisher', 'commerce'], true)) {
    $accountType = '';
}
$next = 'index.php';
$qs = [];
if ($accountType !== '') {
    $qs['account_type'] = $accountType;
}
$view = strtolower(trim((string)($_GET['view'] ?? '')));
if ($view === 'register') {
    $qs['view'] = 'register';
}
if ($qs) {
    $next .= '?' . http_build_query($qs);
}
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Location: ' . $next);
exit;