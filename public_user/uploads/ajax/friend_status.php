<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/session_user.php';
requireUserLogin();
require_once __DIR__ . '/../controller.php';
require_once __DIR__ . '/../includes/friend_system.php';
header('Content-Type: application/json; charset=utf-8');
$controller = new Controller();
$dbh = $controller->pdo();
$meId = (int)($_SESSION['user_id'] ?? 0);
$peerId = (int)($_GET['peer_id'] ?? 0);
$status = fs_friend_status($dbh, $meId, $peerId);
echo json_encode(['ok'=>true,'status'=>$status]);
