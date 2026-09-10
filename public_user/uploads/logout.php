<?php
require_once __DIR__ . '/includes/session_user.php';
require_once __DIR__ . '/controller.php';

$controller = new Controller();
$username = (string)($_SESSION['user_name'] ?? '');
$controller->logSecurity('user_logout', true, userUsername(), $username);

try {
    revokeCurrentUserSession($controller->pdo());
} catch (Throwable $e) {
    // keep logout resilient even if SQL session revoke fails
}

clearUserSession();
header("Location: index.php");
exit;