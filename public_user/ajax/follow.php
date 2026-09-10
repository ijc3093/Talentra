<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/session_user.php';
requireUserLogin();
require_once __DIR__ . '/../controller.php';
require_once __DIR__ . '/../includes/publisher_accounts.php';
require_once __DIR__ . '/../includes/profile_access.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$meId = (int)($_SESSION['user_id'] ?? 0);
$targetId = (int)($_POST['peer_id'] ?? $_POST['publisher_id'] ?? $_POST['target_id'] ?? $_POST['user_id'] ?? $_GET['peer_id'] ?? 0);
$action = strtolower(trim((string)($_POST['action'] ?? $_POST['follow'] ?? 'follow')));
if ($action === '1' || $action === 'true' || $action === 'yes') {
    $action = 'follow';
}

if ($meId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Sign in required.']);
    exit;
}
if ($targetId <= 0 || $targetId === $meId) {
    echo json_encode(['ok' => false, 'error' => 'Unable to find this publisher.']);
    exit;
}

$controller = new Controller();
$dbh = $controller->pdo();
publisher_ensure_schema($dbh);

if (!publisher_is_publisher_user($dbh, $targetId)) {
    echo json_encode(['ok' => false, 'error' => 'This is not a publisher page.', 'status' => 'none']);
    exit;
}

try {
    $st = $dbh->prepare('SELECT 1 FROM public_follows WHERE follower_id = :me AND following_id = :you LIMIT 1');
    $st->execute([':me' => $meId, ':you' => $targetId]);
    $exists = (bool)$st->fetchColumn();
    $wantUnfollow = in_array($action, ['unfollow', '0', 'false', 'no'], true);

    if ($wantUnfollow) {
        if ($exists) {
            $del = $dbh->prepare('DELETE FROM public_follows WHERE follower_id = :me AND following_id = :you LIMIT 1');
            $del->execute([':me' => $meId, ':you' => $targetId]);
        }
        echo json_encode(['ok' => true, 'following' => false, 'status' => 'none', 'target_id' => $targetId]);
        exit;
    }

    if (!$exists) {
        $ins = $dbh->prepare('INSERT INTO public_follows (follower_id, following_id, created_at) VALUES (:me, :you, NOW())');
        $ins->execute([':me' => $meId, ':you' => $targetId]);
        if (function_exists('profile_user_wants_notification')
            && profile_user_wants_notification($dbh, $targetId, 'followed_notifications')
            && profile_user_wants_notification($dbh, $targetId, 'inapp_notifications')) {
            try {
                $stN = $dbh->prepare('SELECT id, name, username FROM users WHERE id IN (?, ?)');
                $stN->execute([$meId, $targetId]);
                $byId = [];
                while ($row = $stN->fetch(PDO::FETCH_ASSOC)) {
                    $byId[(int)$row['id']] = $row;
                }
                $sender = trim((string)($byId[$meId]['name'] ?? $byId[$meId]['username'] ?? ''));
                $receiver = trim((string)($byId[$targetId]['username'] ?? ''));
                if ($sender !== '' && $receiver !== '') {
                    $stI = $dbh->prepare('INSERT INTO notification (notiuser, notireceiver, notitype, is_read) VALUES (:s, :r, :t, 0)');
                    $stI->execute([':s' => $sender, ':r' => $receiver, ':t' => 'started following you [r:pb]']);
                }
            } catch (Throwable $eN) {
            }
        }
    }
    echo json_encode(['ok' => true, 'following' => true, 'status' => 'friends', 'target_id' => $targetId]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => 'Unable to follow publisher.']);
}
