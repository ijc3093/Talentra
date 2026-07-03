<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/session_user.php';
requireUserLogin();
require_once __DIR__ . '/../controller.php';
require_once __DIR__ . '/../includes/friend_system.php';
require_once __DIR__ . '/../includes/publisher_accounts.php';
header('Content-Type: application/json; charset=utf-8');
$controller = new Controller();
$dbh = $controller->pdo();
$meId = (int)($_SESSION['user_id'] ?? $_SESSION['id'] ?? $_SESSION['userid'] ?? 0);
$postId = (int)($_POST['post_id'] ?? $_POST['id'] ?? 0);
$peerId = (int)($_POST['peer_id'] ?? $_POST['friend_user_id'] ?? $_POST['receiver_id'] ?? $_POST['target_user_id'] ?? $_POST['to_user_id'] ?? $_POST['user_id'] ?? 0);
if ($postId > 0) {
    $st = $dbh->prepare("SELECT user_id FROM public_posts WHERE id = :id AND is_deleted = 0 LIMIT 1");
    $st->execute([':id' => $postId]);
    $postOwnerId = (int)($st->fetchColumn() ?: 0);
    if ($postOwnerId > 0) {
        $peerId = $postOwnerId;
    }
}
if ($peerId <= 0) {
    $identifier = trim((string)($_POST['friend'] ?? $_POST['username'] ?? $_POST['friend_code'] ?? $_POST['email'] ?? ''));
    if ($identifier !== '') {
        $needle = ltrim($identifier, '@');
        $st = $dbh->prepare("SELECT id FROM users WHERE id = :id OR username = :u OR UPPER(friend_code) = :c OR email = :e LIMIT 1");
        $st->execute([
            ':id' => ctype_digit($needle) ? (int)$needle : 0,
            ':u' => $needle,
            ':c' => strtoupper($needle),
            ':e' => $identifier,
        ]);
        $peerId = (int)($st->fetchColumn() ?: 0);
    }
}
$action = trim((string)($_POST['action'] ?? 'send'));
if ($action === 'send') {
    if ($meId <= 0 || $peerId <= 0) {
        echo json_encode(['ok'=>false,'message'=>'Invalid user.','status'=>'none','request_id'=>0,'from_user_id'=>$meId,'to_user_id'=>$peerId]);
        exit;
    }
    if ($meId === $peerId) {
        echo json_encode(['ok'=>false,'message'=>'You cannot add yourself.','status'=>'self','request_id'=>0,'from_user_id'=>$meId,'to_user_id'=>$peerId]);
        exit;
    }
    if (publisher_is_publisher_user($dbh, $peerId)) {
        echo json_encode([
            'ok'=>false,
            'message'=>'This is a publisher page. Tap Follow to see their updates in your Feed. Private messages are not available.',
            'status'=>'publisher',
            'request_id'=>0,
            'from_user_id'=>$meId,
            'to_user_id'=>$peerId
        ]);
        exit;
    }
    if (fs_are_friends($dbh, $meId, $peerId)) {
        echo json_encode(['ok'=>true,'message'=>'This user is already your friend.','status'=>'friends','request_id'=>0,'from_user_id'=>$meId,'to_user_id'=>$peerId]);
        exit;
    }

    $existing = $dbh->prepare("SELECT id FROM contact_requests WHERE from_user_id = :from_id AND to_user_id = :to_id ORDER BY id DESC LIMIT 1");
    $existing->execute([':from_id' => $meId, ':to_id' => $peerId]);
    $requestId = (int)($existing->fetchColumn() ?: 0);
    if ($requestId > 0) {
        $up = $dbh->prepare("UPDATE contact_requests SET status = 'pending', created_at = NOW() WHERE id = :id LIMIT 1");
        $up->execute([':id' => $requestId]);
    } else {
        $ins = $dbh->prepare("INSERT INTO contact_requests (from_user_id, to_user_id, status, created_at) VALUES (:from_id, :to_id, 'pending', NOW())");
        $ins->execute([':from_id' => $meId, ':to_id' => $peerId]);
        $requestId = (int)$dbh->lastInsertId();
    }

    $status = fs_friend_status($dbh, $meId, $peerId);
    $verifiedRequestId = fs_pending_request_id($dbh, $meId, $peerId);
    if ($verifiedRequestId > 0) {
        $requestId = $verifiedRequestId;
    }
    $ok = $requestId > 0 && $status === 'outgoing_pending';
    echo json_encode([
        'ok'=>$ok,
        'message'=>$ok ? 'Friend request sent.' : 'Unable to save friend request.',
        'status'=>$status,
        'request_id'=>$requestId,
        'from_user_id'=>$meId,
        'to_user_id'=>$peerId
    ]);
    exit;
}
if ($action === 'accept' || $action === 'decline' || $action === 'remove') {
    $requestId = (int)($_POST['request_id'] ?? $_POST['friend_request_id'] ?? $_POST['id'] ?? 0);
    if ($requestId <= 0 && $peerId > 0) {
        $requestId = fs_pending_request_id($dbh, $peerId, $meId);
    }
    $res = $action === 'accept'
        ? fs_accept_friend_request($dbh, $requestId, $meId)
        : fs_decline_friend_request($dbh, $requestId, $meId);
    $status = $peerId > 0 ? fs_friend_status($dbh, $meId, $peerId) : 'none';
    echo json_encode([
        'ok' => (bool)($res['ok'] ?? false),
        'message' => (string)($res['message'] ?? ''),
        'status' => $status,
        'request_id' => $requestId,
        'from_user_id' => $peerId,
        'to_user_id' => $meId
    ]);
    exit;
}
if ($action === 'unfriend') {
    if ($meId <= 0 || $peerId <= 0) {
        echo json_encode(['ok'=>false,'message'=>'Invalid user.','status'=>'none','from_user_id'=>$meId,'to_user_id'=>$peerId]);
        exit;
    }
    $res = fs_remove_friend($dbh, $meId, $peerId);
    $status = fs_friend_status($dbh, $meId, $peerId);
    echo json_encode([
        'ok' => (bool)($res['ok'] ?? false),
        'message' => (string)($res['message'] ?? ''),
        'status' => $status,
        'from_user_id' => $meId,
        'to_user_id' => $peerId
    ]);
    exit;
}
echo json_encode(['ok'=>false,'message'=>'Unsupported action.']);
