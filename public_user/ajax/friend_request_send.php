<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/session_user.php';
requireUserLogin();
require_once __DIR__ . '/../controller.php';
require_once __DIR__ . '/../includes/friend_system.php';
require_once __DIR__ . '/../includes/publisher_accounts.php';

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
    if (function_exists('publisher_is_publisher_user') && publisher_is_publisher_user($dbh, $peerId)) {
        j([
            'ok' => false,
            'error' => 'This is a publisher page. Tap Follow to see their updates in your Feed.',
            'status' => 'publisher',
            'from_user_id' => $meId,
            'to_user_id' => $peerId,
        ]);
    }

    $res = fs_send_friend_request($dbh, $meId, $peerId);
    $status = fs_friend_status($dbh, $meId, $peerId);
    $requestId = fs_pending_request_id($dbh, $meId, $peerId);

    $stPeer = $dbh->prepare("SELECT id, name, username, email, friend_code FROM users WHERE id = :id LIMIT 1");
    $stPeer->execute([':id' => $peerId]);
    $peer = $stPeer->fetch(PDO::FETCH_ASSOC) ?: [];

    $stCount = $dbh->prepare("SELECT COUNT(*) FROM contact_requests WHERE to_user_id = :peer AND status = 'pending'");
    $stCount->execute([':peer' => $peerId]);
    $recipientPendingCount = (int)($stCount->fetchColumn() ?: 0);

    $ok = !empty($res['ok']) || $status === 'outgoing_pending' || $status === 'friends';
    j([
        'ok' => $ok,
        'error' => $ok ? '' : (string)($res['message'] ?? 'Unable to save friend request.'),
        'message' => (string)($res['message'] ?? ($ok ? 'Friend request sent.' : 'Unable to save friend request.')),
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
