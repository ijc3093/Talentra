<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/session_user.php';
requireUserLogin();
require_once __DIR__ . '/../controller.php';

header('Content-Type: application/json; charset=utf-8');

function jexit(array $payload, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$meId = (int)($_SESSION['user_id'] ?? 0);
if ($meId <= 0) {
    jexit(['ok' => false, 'error' => 'Not signed in.'], 401);
}

$action = strtolower(trim((string)($_POST['action'] ?? '')));
$communityId = (int)($_POST['community_id'] ?? 0);
$memberUserId = (int)($_POST['member_user_id'] ?? 0);
$notificationId = (int)($_POST['notification_id'] ?? 0);

if (!in_array($action, ['approve', 'decline'], true) || $communityId <= 0 || $memberUserId <= 0) {
    jexit(['ok' => false, 'error' => 'Invalid join request action.'], 400);
}

try {
    $dbh = (new Controller())->pdo();
} catch (Throwable $e) {
    jexit(['ok' => false, 'error' => 'Database unavailable.'], 500);
}

try {
    $st = $dbh->prepare("
        SELECT owner_user_id, name
        FROM communities
        WHERE id = :id AND status = 1
        LIMIT 1
    ");
    $st->execute([':id' => $communityId]);
    $community = $st->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable $e) {
    jexit(['ok' => false, 'error' => 'Unable to load community.'], 500);
}

if (!$community) {
    jexit(['ok' => false, 'error' => 'Community not found.'], 404);
}

$ownerId = (int)($community['owner_user_id'] ?? 0);
$isStaff = false;
if ($ownerId === $meId) {
    $isStaff = true;
} else {
    try {
        $stRole = $dbh->prepare("
            SELECT role
            FROM community_members
            WHERE community_id = :c AND user_id = :u AND status = 'active'
            LIMIT 1
        ");
        $stRole->execute([':c' => $communityId, ':u' => $meId]);
        $role = (string)($stRole->fetchColumn() ?: '');
        $isStaff = in_array($role, ['owner', 'admin', 'moderator'], true);
    } catch (Throwable $e) {
        $isStaff = false;
    }
}

if (!$isStaff) {
    jexit(['ok' => false, 'error' => 'Only community staff can approve join requests.'], 403);
}

try {
    $stM = $dbh->prepare("
        SELECT status
        FROM community_members
        WHERE community_id = :c AND user_id = :u
        LIMIT 1
    ");
    $stM->execute([':c' => $communityId, ':u' => $memberUserId]);
    $status = (string)($stM->fetchColumn() ?: '');
} catch (Throwable $e) {
    jexit(['ok' => false, 'error' => 'Unable to load join request.'], 500);
}

if ($status === '') {
    jexit(['ok' => false, 'error' => 'Join request not found.'], 404);
}
if ($status !== 'pending') {
    jexit(['ok' => false, 'error' => 'This join request was already answered.', 'status' => $status], 409);
}

$approved = $action === 'approve';

try {
    if ($approved) {
        $dbh->prepare("
            UPDATE community_members
            SET status = 'active', role = 'member', joined_at = NOW()
            WHERE community_id = :c AND user_id = :u AND status = 'pending'
            LIMIT 1
        ")->execute([':c' => $communityId, ':u' => $memberUserId]);
    } else {
        $dbh->prepare("
            DELETE FROM community_members
            WHERE community_id = :c AND user_id = :u AND status = 'pending'
            LIMIT 1
        ")->execute([':c' => $communityId, ':u' => $memberUserId]);
    }
} catch (Throwable $e) {
    jexit(['ok' => false, 'error' => 'Unable to update join request.'], 500);
}

require_once __DIR__ . '/../includes/community_invite_notify.php';
community_notify_join_response($dbh, $meId, $memberUserId, $communityId, $approved);

// Mark related owner notifications read.
try {
    $receivers = [];
    $stU = $dbh->prepare('SELECT username, email FROM users WHERE id = :id LIMIT 1');
    $stU->execute([':id' => $meId]);
    $urow = $stU->fetch(PDO::FETCH_ASSOC) ?: [];
    foreach (['username', 'email'] as $k) {
        $v = trim((string)($urow[$k] ?? ''));
        if ($v !== '') {
            $receivers[] = $v;
        }
    }
    foreach ([trim((string)($_SESSION['user_login'] ?? '')), trim((string)($_SESSION['user_email'] ?? ''))] as $v) {
        if ($v !== '') {
            $receivers[] = $v;
        }
    }
    $receivers = array_values(array_unique($receivers));
    if ($receivers) {
        $ph = implode(',', array_fill(0, count($receivers), '?'));
        $params = $receivers;
        $sql = "UPDATE notification SET is_read = 1 WHERE notireceiver IN ($ph) AND is_read = 0 AND (notitype LIKE ?";
        $params[] = '%[cm:' . $memberUserId . ']%';
        if ($notificationId > 0) {
            $sql .= ' OR id = ?';
            $params[] = $notificationId;
        }
        $sql .= ')';
        $dbh->prepare($sql)->execute($params);
    }
} catch (Throwable $e) {
    // non-fatal
}

jexit([
    'ok' => true,
    'action' => $approved ? 'approved' : 'declined',
    'community_id' => $communityId,
    'member_user_id' => $memberUserId,
    'redirect' => $approved ? ('community_profile.php?id=' . $communityId . '&tab=members') : '',
    'message' => $approved ? 'Join request approved.' : 'Join request declined.',
]);
