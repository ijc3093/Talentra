<?php
require_once __DIR__ . '/../includes/session_admin.php';
requireAdminLogin();

require_once __DIR__ . '/../includes/identity.php';
require_once __DIR__ . '/../controller.php';

header('Content-Type: application/json; charset=utf-8');
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");

try {
    $code = trim($_POST['friend_code'] ?? '');
    $code = strtoupper($code);

    if ($code === '') {
        echo json_encode(['ok' => false, 'error' => 'Friend Code is required']);
        exit;
    }

    $controller = new Controller();
    $dbh = $controller->pdo();

    $meId   = myAdminId();
    $meRole = myRoleId();

    $st = $dbh->prepare("
        SELECT idadmin, username, friend_code, role, status
        FROM admin
        WHERE friend_code = :c
        LIMIT 1
    ");
    $st->execute([':c' => $code]);
    $peer = $st->fetch(PDO::FETCH_ASSOC);

    if (!$peer) {
        echo json_encode(['ok' => false, 'error' => 'No admin found for that Friend Code']);
        exit;
    }
    if ((int)$peer['status'] !== 1) {
        echo json_encode(['ok' => false, 'error' => 'That user is inactive']);
        exit;
    }
    if ($meId > 0 && (int)$peer['idadmin'] === $meId) {
        echo json_encode(['ok' => false, 'error' => 'You cannot message yourself']);
        exit;
    }

    $peerRole = (int)$peer['role'];

    if (!canChatWithRole($meRole, $peerRole)) {
        echo json_encode(['ok' => false, 'error' => 'You are not allowed to chat with that role']);
        exit;
    }

    $channel = channelForRoles($meRole, $peerRole);
    if ($channel === '') {
        echo json_encode(['ok' => false, 'error' => 'Channel mapping missing for these roles']);
        exit;
    }

    echo json_encode([
        'ok' => true,
        'peer_username' => $peer['username'],
        'peer_id' => (int)$peer['idadmin'],
        'peer_friend_code' => $peer['friend_code'],
        'channel' => $channel
    ]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => 'Server error']);
}
