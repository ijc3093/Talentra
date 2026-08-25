<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/session_user.php';
requireUserLogin();

require_once __DIR__ . '/../controller.php';
require_once __DIR__ . '/../includes/group_video_call_lib.php';
require_once __DIR__ . '/../includes/private_video_call_lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

function j(array $a): void { echo json_encode($a); exit; }

$controller = new Controller();
$dbh = $controller->pdo();

$meId = (int)($_SESSION['user_id'] ?? 0);
$meCode = strtoupper(trim((string)($_SESSION['friend_code'] ?? $_SESSION['user_friend_code'] ?? '')));
if ($meId <= 0 || $meCode === '') j(['ok' => false, 'error' => 'Invalid session']);

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

$peerCode = strtoupper(trim((string)($_POST['peer'] ?? $_POST['to'] ?? '')));
$groupId = (int)($_POST['group_id'] ?? 0);
$callMode = strtolower(trim((string)($_POST['mode'] ?? 'video')));
if (!in_array($callMode, ['video','voice'], true)) $callMode = 'video';

try {
    if ($groupId > 0) {
        if (!ensure_group_video_call_tables($dbh)) {
            j(['ok' => false, 'error' => 'Group call tables unavailable']);
        }
        if (!is_active_chat_group_member($dbh, $groupId, $meId)) {
            j(['ok' => false, 'error' => 'Group not found']);
        }

        $open = fetch_group_video_call_for_user($dbh, $groupId, $meId);
        if ($open) {
            $participants = fetch_group_video_call_participants($dbh, (int)($open['id'] ?? 0));
            $myStatus = 'invited';
            foreach ($participants as $participant) {
                if ((int)($participant['user_id'] ?? 0) === $meId) {
                    $myStatus = (string)($participant['invite_status'] ?? 'invited');
                    break;
                }
            }
            j([
                'ok' => true,
                'scope' => 'group',
                'call_id' => (int)$open['id'],
                'group_id' => $groupId,
                'started_by_user_id' => (int)($open['started_by_user_id'] ?? 0),
                'status' => (string)($open['status'] ?? 'initiated'),
                'call_mode' => (string)($open['call_mode'] ?? $callMode),
                'participants' => $participants,
                'my_status' => $myStatus,
                'reused' => true,
            ]);
        }

        $memberIds = list_active_chat_group_member_ids($dbh, $groupId);
        if (!$memberIds || !in_array($meId, $memberIds, true)) {
            j(['ok' => false, 'error' => 'Group members unavailable']);
        }

        $dbh->beginTransaction();
        $stIns = $dbh->prepare("
            INSERT INTO group_video_calls
              (group_id, started_by_user_id, call_mode, status, created_at, updated_at, started_at)
            VALUES
              (:group_id, :started_by_user_id, :call_mode, 'ringing', NOW(), NOW(), NOW())
        ");
        $stIns->execute([
            ':group_id' => $groupId,
            ':started_by_user_id' => $meId,
            ':call_mode' => $callMode,
        ]);
        $callId = (int)$dbh->lastInsertId();

        $stParticipant = $dbh->prepare("
            INSERT INTO group_video_call_participants
              (call_id, user_id, invite_status, invited_at, responded_at, joined_at, updated_at, last_seen_at)
            VALUES
              (:call_id, :user_id, :invite_status, NOW(), :responded_at, :joined_at, NOW(), :last_seen_at)
        ");
        foreach ($memberIds as $memberId) {
            $isStarter = ($memberId === $meId);
            $stParticipant->execute([
                ':call_id' => $callId,
                ':user_id' => $memberId,
                ':invite_status' => $isStarter ? 'joined' : 'invited',
                ':responded_at' => $isStarter ? date('Y-m-d H:i:s') : null,
                ':joined_at' => $isStarter ? date('Y-m-d H:i:s') : null,
                ':last_seen_at' => $isStarter ? date('Y-m-d H:i:s') : null,
            ]);
        }
        $dbh->commit();

        $participants = fetch_group_video_call_participants($dbh, $callId);
        j([
            'ok' => true,
            'scope' => 'group',
            'call_id' => $callId,
            'group_id' => $groupId,
            'started_by_user_id' => $meId,
            'call_mode' => $callMode,
            'status' => 'ringing',
            'participants' => $participants,
            'my_status' => 'joined',
            'reused' => false,
        ]);
    }

    if (!ensure_private_video_call_tables($dbh)) {
        j(['ok' => false, 'error' => 'Private call tables unavailable']);
    }

    if ($peerCode === '') j(['ok' => false, 'error' => 'Missing peer']);
    if ($peerCode === $meCode) j(['ok' => false, 'error' => 'Cannot call yourself']);

    $st = $dbh->prepare("SELECT id, friend_code FROM users WHERE UPPER(friend_code) = :c AND status = 1 LIMIT 1");
    $st->execute([':c' => $peerCode]);
    $peer = $st->fetch(PDO::FETCH_ASSOC);
    if (!$peer) j(['ok' => false, 'error' => 'Peer not found']);

    $peerId = (int)($peer['id'] ?? 0);
    if ($peerId <= 0) j(['ok' => false, 'error' => 'Peer not found']);

    $stExpire = $dbh->prepare("
        UPDATE user_video_calls
        SET status = 'missed',
            ended_at = COALESCE(ended_at, NOW()),
            ended_by_user_id = callee_user_id,
            updated_at = NOW()
        WHERE (
                (caller_user_id = :me AND callee_user_id = :peer)
             OR (caller_user_id = :peer2 AND callee_user_id = :me2)
              )
          AND status IN ('initiated','ringing')
          AND created_at <= (NOW() - INTERVAL 60 SECOND)
    ");
    $stExpire->execute([
        ':me' => $meId,
        ':peer' => $peerId,
        ':peer2' => $peerId,
        ':me2' => $meId,
    ]);

    $stOpen = $dbh->prepare("
        SELECT id, status, call_mode
        FROM user_video_calls
        WHERE (
                (caller_user_id = :me AND callee_user_id = :peer)
	             OR (caller_user_id = :peer2 AND callee_user_id = :me2)
	              )
	          AND status IN ('initiated','ringing','active')
          AND (status = 'active' OR created_at > (NOW() - INTERVAL 60 SECOND))
        ORDER BY id DESC
        LIMIT 1
    ");
    $stOpen->execute([
        ':me' => $meId,
        ':peer' => $peerId,
        ':peer2' => $peerId,
        ':me2' => $meId,
    ]);
    $open = $stOpen->fetch(PDO::FETCH_ASSOC);
    if ($open) {
        j([
            'ok' => true,
            'call_id' => (int)$open['id'],
            'status' => (string)$open['status'],
            'call_mode' => (string)($open['call_mode'] ?? $callMode),
            'reused' => true,
        ]);
    }

    $stIns = $dbh->prepare("
        INSERT INTO user_video_calls
          (call_mode, caller_user_id, caller_code, callee_user_id, callee_code, status, created_at, updated_at)
        VALUES
          (:call_mode, :caller_id, :caller_code, :callee_id, :callee_code, 'initiated', NOW(), NOW())
    ");
    $stIns->execute([
        ':call_mode' => $callMode,
        ':caller_id' => $meId,
        ':caller_code' => $meCode,
        ':callee_id' => $peerId,
        ':callee_code' => $peerCode,
    ]);

    j([
        'ok' => true,
        'scope' => 'private',
        'call_id' => (int)$dbh->lastInsertId(),
        'call_mode' => $callMode,
        'status' => 'initiated',
        'reused' => false,
    ]);
} catch (Throwable $e) {
    if ($dbh->inTransaction()) {
        $dbh->rollBack();
    }
    j(['ok' => false, 'error' => 'Database error']);
}
