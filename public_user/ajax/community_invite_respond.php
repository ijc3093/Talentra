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
$inviteId = (int)($_POST['invite_id'] ?? 0);
$notificationId = (int)($_POST['notification_id'] ?? 0);

if (!in_array($action, ['accept', 'decline'], true) || $inviteId <= 0) {
    jexit(['ok' => false, 'error' => 'Invalid invite action.'], 400);
}

try {
    $dbh = (new Controller())->pdo();
} catch (Throwable $e) {
    jexit(['ok' => false, 'error' => 'Database unavailable.'], 500);
}

try {
    $st = $dbh->prepare("
        SELECT id, community_id, invited_user_id, invited_by_user_id, status
        FROM community_invitations
        WHERE id = :id
        LIMIT 1
    ");
    $st->execute([':id' => $inviteId]);
    $invite = $st->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable $e) {
    jexit(['ok' => false, 'error' => 'Unable to load invitation.'], 500);
}

if (!$invite) {
    jexit(['ok' => false, 'error' => 'Invitation not found.'], 404);
}
if ((int)($invite['invited_user_id'] ?? 0) !== $meId) {
    jexit(['ok' => false, 'error' => 'This invitation is not for you.'], 403);
}
if ((string)($invite['status'] ?? '') !== 'pending') {
    jexit(['ok' => false, 'error' => 'This invitation was already answered.', 'status' => (string)$invite['status']], 409);
}

$communityId = (int)($invite['community_id'] ?? 0);
$inviterUserId = (int)($invite['invited_by_user_id'] ?? 0);
$accepted = $action === 'accept';

try {
    $dbh->prepare("
        UPDATE community_invitations
        SET status = :s, responded_at = NOW()
        WHERE community_id = :c
          AND invited_user_id = :u
          AND status = 'pending'
    ")->execute([
        ':s' => $accepted ? 'accepted' : 'declined',
        ':c' => $communityId,
        ':u' => $meId,
    ]);

    if ($accepted && $communityId > 0) {
        $dbh->prepare("
            INSERT INTO community_members (community_id, user_id, role, status, joined_at)
            VALUES (:c, :u, 'member', 'active', NOW())
            ON DUPLICATE KEY UPDATE status = 'active', joined_at = NOW()
        ")->execute([':c' => $communityId, ':u' => $meId]);
    }
} catch (Throwable $e) {
    jexit(['ok' => false, 'error' => 'Unable to update invitation.'], 500);
}

require_once __DIR__ . '/../includes/community_invite_notify.php';
community_notify_invite_response($dbh, $meId, $inviterUserId, $communityId, $accepted);

// Mark related notifications read for this invite.
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
        $params[] = '%[ci:' . $inviteId . ']%';
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
    'action' => $accepted ? 'accepted' : 'declined',
    'invite_id' => $inviteId,
    'community_id' => $communityId,
    'redirect' => $accepted && $communityId > 0 ? ('community_profile.php?id=' . $communityId) : '',
    'message' => $accepted ? 'Invitation accepted. Welcome to the community!' : 'Invitation declined.',
]);
