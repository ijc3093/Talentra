<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/session_user.php';
requireUserLogin();
require_once __DIR__ . '/../controller.php';
require_once __DIR__ . '/../includes/friend_system.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

error_reporting(0);
@ini_set('display_errors', '0');

function j(array $payload): void {
    echo json_encode($payload);
    exit;
}

$controller = new Controller();
$dbh = $controller->pdo();

$meId = (int)($_SESSION['user_id'] ?? $_SESSION['id'] ?? $_SESSION['userid'] ?? 0);
if ($meId <= 0) {
    j(['ok' => false, 'error' => 'Invalid session.']);
}

$postId = (int)($_POST['post_id'] ?? $_POST['id'] ?? 0);
$peerId = (int)($_POST['peer_id'] ?? $_POST['friend_user_id'] ?? $_POST['receiver_id'] ?? $_POST['target_user_id'] ?? $_POST['to_user_id'] ?? $_POST['user_id'] ?? 0);
$friendCode = strtoupper(trim((string)($_POST['friend_code'] ?? $_POST['peer_code'] ?? '')));
$username = ltrim(trim((string)($_POST['username'] ?? $_POST['peer_username'] ?? '')), '@');
$email = trim((string)($_POST['email'] ?? $_POST['peer_email'] ?? ''));
$identifier = trim((string)($_POST['friend'] ?? ''));

try {
    if ($postId > 0) {
        $st = $dbh->prepare("SELECT user_id FROM public_posts WHERE id = :id AND is_deleted = 0 LIMIT 1");
        $st->execute([':id' => $postId]);
        $postOwnerId = (int)($st->fetchColumn() ?: 0);
        if ($postOwnerId > 0) {
            $peerId = $postOwnerId;
        }
    }

    if ($peerId <= 0) {
        $needle = $identifier !== '' ? $identifier : ($friendCode !== '' ? $friendCode : ($username !== '' ? $username : $email));
        $needle = trim($needle);
        if ($needle !== '') {
            $cleanNeedle = ltrim($needle, '@');
            $st = $dbh->prepare("
                SELECT id
                FROM users
                WHERE id = :id
                   OR username = :username
                   OR UPPER(friend_code) = :friend_code
                   OR email = :email
                LIMIT 1
            ");
            $st->execute([
                ':id' => ctype_digit($cleanNeedle) ? (int)$cleanNeedle : 0,
                ':username' => $cleanNeedle,
                ':friend_code' => strtoupper($cleanNeedle),
                ':email' => $needle,
            ]);
            $peerId = (int)($st->fetchColumn() ?: 0);
        }
    }

    if ($peerId <= 0) {
        j(['ok' => false, 'error' => 'Unable to find this user.']);
    }
    if ($meId === $peerId) {
        j(['ok' => false, 'error' => 'You cannot add yourself.', 'from_user_id' => $meId, 'to_user_id' => $peerId]);
    }
    if (fs_are_friends($dbh, $meId, $peerId)) {
        j(['ok' => true, 'message' => 'This user is already your friend.', 'status' => 'friends', 'request_id' => 0, 'from_user_id' => $meId, 'to_user_id' => $peerId]);
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

    $stPeer = $dbh->prepare("SELECT id, name, username, email, friend_code FROM users WHERE id = :id LIMIT 1");
    $stPeer->execute([':id' => $peerId]);
    $peer = $stPeer->fetch(PDO::FETCH_ASSOC) ?: [];

    $stCount = $dbh->prepare("SELECT COUNT(*) FROM contact_requests WHERE to_user_id = :peer AND status = 'pending'");
    $stCount->execute([':peer' => $peerId]);
    $recipientPendingCount = (int)($stCount->fetchColumn() ?: 0);

    $ok = $requestId > 0 && $status === 'outgoing_pending';
    j([
        'ok' => $ok,
        'error' => $ok ? '' : 'Unable to save friend request.',
        'message' => $ok ? 'Friend request sent.' : 'Unable to save friend request.',
        'status' => $status,
        'request_id' => $requestId,
        'from_user_id' => $meId,
        'to_user_id' => $peerId,
        'recipient_pending_count' => $recipientPendingCount,
        'item' => [
            'id' => $requestId,
            'from_user_id' => $meId,
            'to_user_id' => $peerId,
            'status' => $status,
            'display_name' => (string)($peer['name'] ?? ''),
            'username' => (string)($peer['username'] ?? ''),
            'email' => (string)($peer['email'] ?? ''),
            'friend_code' => (string)($peer['friend_code'] ?? ''),
        ],
    ]);
} catch (Throwable $e) {
    j(['ok' => false, 'error' => 'Unable to send friend request.']);
}
