<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/session_admin.php';
requireAdminLogin();

$adminRole = (int)($_SESSION['userRole'] ?? 0);
if ($adminRole !== 1) {
    header('Location: dashboard.php');
    exit;
}

$userId = (int)($_GET['user_id'] ?? $_GET['id'] ?? 0);
if ($userId <= 0) {
    header('Location: userlist.php');
    exit;
}

require_once __DIR__ . '/controller.php';
$dbh = (new Controller())->pdo();

$st = $dbh->prepare('SELECT id FROM users WHERE id = :id LIMIT 1');
$st->execute([':id' => $userId]);
if (!$st->fetchColumn()) {
    header('Location: userlist.php?msg=' . rawurlencode('User not found.'));
    exit;
}

$_SESSION['admin_open_profile_user_id'] = $userId;
$_SESSION['admin_open_profile_expires'] = time() + 300;

header('Location: ../public_user/profile.php?id=' . $userId . '&from_admin=1');
exit;
