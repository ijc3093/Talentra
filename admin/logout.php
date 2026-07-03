<?php

require_once __DIR__ . '/includes/session_admin.php';
require_once __DIR__ . '/controller.php';

$controller = new Controller();

$adminId = (int)($_SESSION['admin_id'] ?? 0);
$login   = (string)($_SESSION['admin_login'] ?? '');

$controller->logSecurity('admin_logout', true, null, $login, $adminId);

clearAdminSession();
header("Location: index.php");
exit;
