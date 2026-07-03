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

function is_group_snapshot_payload(?string $payload): bool {
    if ($payload === null || trim($payload) === '') return false;
    $decoded = json_decode($payload, true);
    return is_array($decoded)
        && strtolower((string)($decoded['type'] ?? '')) === 'ios_snapshot'
        && trim((string)($decoded['image'] ?? '')) !== '';
}

function prune_pending_group_snapshot_signals(PDO $dbh, int $callId, int $fromUserId, array $toUserIds): void {
    $targets = array_values(array_unique(array_filter(array_map('intval', $toUserIds), static fn($id) => $id > 0)));
    if ($callId <= 0 || $fromUserId <= 0 || !$targets) return;

    $placeholders = [];
    $params = [
        ':call_id' => $callId,
        ':from_user_id' => $fromUserId,
    ];
    foreach ($targets as $index => $targetId) {
        $key = ':target_' . $index;
        $placeholders[] = $key;
        $params[$key] = $targetId;
    }

    $sql = "
        DELETE FROM group_video_call_signals
        WHERE call_id = :call_id
          AND from_user_id = :from_user_id
          AND to_user_id IN (" . implode(',', $placeholders) . ")
          AND signal_type = 'ice'
          AND consumed_at IS NULL
          AND payload LIKE '%ios_snapshot%'
    ";
    $dbh->prepare($sql)->execute($params);
}

$controller = new Controller();
$dbh = $controller->pdo();

$meId = (int)($_SESSION['user_id'] ?? 0);
$meCode = strtoupper(trim((string)($_SESSION['friend_code'] ?? $_SESSION['user_friend_code'] ?? '')));
if ($meId <= 0 || $meCode === '') j(['ok' => false, 'error' => 'Invalid session']);

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

$callId = (int)($_POST['call_id'] ?? 0);
$scope = strtolower(trim((string)($_POST['scope'] ?? 'private')));
$signalType = strtolower(trim((string)($_POST['signal_type'] ?? '')));
$targetUserId = (int)($_POST['target_user_id'] ?? 0);
$payloadRaw = $_POST['payload'] ?? '';

if ($callId <= 0) j(['ok' => false, 'error' => 'Missing call id']);
$allowedSignalTypes = ['offer','answer','ice','join','decline','end','join_request','join_approved','join_denied'];
if (!in_array($signalType, $allowedSignalTypes, true)) {
    j(['ok' => false, 'error' => 'Invalid signal type']);
}
if ($scope !== 'group' && in_array($signalType, ['join_request','join_approved','join_denied'], true)) {
    j(['ok' => false, 'error' => 'Invalid signal type']);
}

$payload = null;
if ($payloadRaw !== '' && $payloadRaw !== null) {
    if (is_string($payloadRaw)) {
        $payload = $payloadRaw;
    } else {
        $payload = json_encode($payloadRaw, JSON_UNESCAPED_SLASHES);
    }
}

try {
    if ($scope === 'group') {
        if (!ensure_group_video_call_tables($dbh)) {
            j(['ok' => false, 'error' => 'Group call tables unavailable']);
        }

        $stCall = $dbh->prepare("
            SELECT id, group_id, started_by_user_id, call_mode, status
            FROM group_video_calls
            WHERE id = :id
            LIMIT 1
        ");
        $stCall->execute([':id' => $callId]);
        $call = $stCall->fetch(PDO::FETCH_ASSOC);
        if (!$call) j(['ok' => false, 'error' => 'Call not found']);

        $stParticipant = $dbh->prepare("
            SELECT user_id, invite_status
            FROM group_video_call_participants
            WHERE call_id = :call_id
              AND user_id = :uid
            LIMIT 1
        ");
        $stParticipant->execute([':call_id' => $callId, ':uid' => $meId]);
        $meParticipant = $stParticipant->fetch(PDO::FETCH_ASSOC);
        if (!$meParticipant) {
            j(['ok' => false, 'error' => 'Forbidden']);
        }

        $payloadText = is_string($payload) ? $payload : ($payload !== null ? json_encode($payload, JSON_UNESCAPED_SLASHES) : null);
        $startedByUserId = (int)($call['started_by_user_id'] ?? 0);
        $callStatus = strtolower((string)($call['status'] ?? ''));
        $myInviteStatus = strtolower((string)($meParticipant['invite_status'] ?? 'invited'));

        if ($signalType === 'join') {
            update_group_video_call_participant_status($dbh, $callId, $meId, 'joined');
            $status = sync_group_video_call_status($dbh, $callId);
            j([
                'ok' => true,
                'scope' => 'group',
                'status' => $status,
                'requires_approval' => false,
                'my_status' => 'joined',
                'participants' => fetch_group_video_call_participants($dbh, $callId),
            ]);
        }

        if ($signalType === 'join_request') {
            if ($meId === $startedByUserId) {
                j(['ok' => false, 'error' => 'Host is already in the call']);
            }
            update_group_video_call_participant_status($dbh, $callId, $meId, 'requested');
            insert_group_video_call_signals($dbh, $callId, $meId, [$startedByUserId], 'join_request', $payloadText);
            $status = sync_group_video_call_status($dbh, $callId);
            j([
                'ok' => true,
                'scope' => 'group',
                'status' => $status,
                'requires_approval' => true,
                'my_status' => 'requested',
                'participants' => fetch_group_video_call_participants($dbh, $callId),
            ]);
        }

        if (in_array($signalType, ['join_approved', 'join_denied'], true)) {
            if ($meId !== $startedByUserId) {
                j(['ok' => false, 'error' => 'Only the call host can respond to join requests']);
            }
            if ($targetUserId <= 0) {
                j(['ok' => false, 'error' => 'Missing target user']);
            }

            $stTarget = $dbh->prepare("
                SELECT user_id, invite_status
                FROM group_video_call_participants
                WHERE call_id = :call_id
                  AND user_id = :uid
                LIMIT 1
            ");
            $stTarget->execute([':call_id' => $callId, ':uid' => $targetUserId]);
            $targetParticipant = $stTarget->fetch(PDO::FETCH_ASSOC);
            if (!$targetParticipant) {
                j(['ok' => false, 'error' => 'Join request not found']);
            }

            update_group_video_call_participant_status(
                $dbh,
                $callId,
                $targetUserId,
                $signalType === 'join_approved' ? 'joined' : 'declined'
            );
            insert_group_video_call_signals($dbh, $callId, $meId, [$targetUserId], $signalType, $payloadText);
            $status = sync_group_video_call_status($dbh, $callId);
            j([
                'ok' => true,
                'scope' => 'group',
                'status' => $status,
                'requires_approval' => false,
                'participants' => fetch_group_video_call_participants($dbh, $callId),
            ]);
        }

        $isBroadcastSnapshot = ($signalType === 'ice' && $targetUserId <= 0 && is_group_snapshot_payload($payloadText));
        if (in_array($signalType, ['offer', 'answer', 'ice'], true) && $targetUserId <= 0 && !$isBroadcastSnapshot) {
            j(['ok' => false, 'error' => 'Missing target user']);
        }

        if ($signalType === 'offer') {
            update_group_video_call_participant_status($dbh, $callId, $meId, 'joined');
        } elseif ($signalType === 'answer') {
            update_group_video_call_participant_status($dbh, $callId, $meId, 'joined');
        } elseif ($signalType === 'decline') {
            update_group_video_call_participant_status($dbh, $callId, $meId, 'declined');
        } elseif ($signalType === 'end') {
            update_group_video_call_participant_status($dbh, $callId, $meId, 'left');
        }

        $targets = [];
        if ($isBroadcastSnapshot) {
            $stTargets = $dbh->prepare("
                SELECT user_id
                FROM group_video_call_participants
                WHERE call_id = :call_id
                  AND user_id <> :uid
                  AND invite_status = 'joined'
            ");
            $stTargets->execute([':call_id' => $callId, ':uid' => $meId]);
            $targets = array_map('intval', $stTargets->fetchAll(PDO::FETCH_COLUMN) ?: []);
        } elseif (in_array($signalType, ['offer', 'answer', 'ice'], true)) {
            $targets = [$targetUserId];
        } elseif ($signalType === 'decline') {
            $targets = [(int)($call['started_by_user_id'] ?? 0)];
        } elseif ($signalType === 'end') {
            $stTargets = $dbh->prepare("
                SELECT user_id
                FROM group_video_call_participants
                WHERE call_id = :call_id
                  AND user_id <> :uid
                  AND invite_status IN ('joined', 'invited', 'requested')
            ");
            $stTargets->execute([':call_id' => $callId, ':uid' => $meId]);
            $targets = array_map('intval', $stTargets->fetchAll(PDO::FETCH_COLUMN) ?: []);
        }
        if ($isBroadcastSnapshot) {
            prune_pending_group_snapshot_signals($dbh, $callId, $meId, $targets);
        }
        if ($signalType === 'end') {
            foreach ($targets as $targetParticipantId) {
                update_group_video_call_participant_status($dbh, $callId, (int)$targetParticipantId, 'left');
            }
        }
        insert_group_video_call_signals($dbh, $callId, $meId, $targets, $signalType, $payloadText);
        $status = sync_group_video_call_status($dbh, $callId);
        if ($signalType === 'end' && $status === 'ended') {
            record_group_video_call_finished_message($dbh, $callId, $meId);
        }

        j([
            'ok' => true,
            'scope' => 'group',
            'status' => $status,
            'participants' => fetch_group_video_call_participants($dbh, $callId),
        ]);
    }

    if (!ensure_private_video_call_tables($dbh)) {
        j(['ok' => false, 'error' => 'Private call tables unavailable']);
    }

    $stCall = $dbh->prepare("
        SELECT id, call_mode, caller_user_id, callee_user_id, status
        FROM user_video_calls
        WHERE id = :id
        LIMIT 1
    ");
    $stCall->execute([':id' => $callId]);
    $call = $stCall->fetch(PDO::FETCH_ASSOC);
    if (!$call) j(['ok' => false, 'error' => 'Call not found']);

    $callerId = (int)($call['caller_user_id'] ?? 0);
    $calleeId = (int)($call['callee_user_id'] ?? 0);
    if ($meId !== $callerId && $meId !== $calleeId) {
        j(['ok' => false, 'error' => 'Forbidden']);
    }

    $toUserId = ($meId === $callerId) ? $calleeId : $callerId;

    $stSig = $dbh->prepare("
        INSERT INTO user_video_call_signals
          (call_id, from_user_id, to_user_id, signal_type, payload, created_at)
        VALUES
          (:call_id, :from_user_id, :to_user_id, :signal_type, :payload, NOW())
    ");
    $stSig->execute([
        ':call_id' => $callId,
        ':from_user_id' => $meId,
        ':to_user_id' => $toUserId,
        ':signal_type' => $signalType,
        ':payload' => $payload,
    ]);

    if ($signalType === 'join') {
        $dbh->prepare("UPDATE user_video_calls SET updated_at = NOW() WHERE id = :id LIMIT 1")
            ->execute([':id' => $callId]);
    } elseif ($signalType === 'offer') {
        $dbh->prepare("UPDATE user_video_calls SET status = 'ringing', updated_at = NOW() WHERE id = :id LIMIT 1")
            ->execute([':id' => $callId]);
    } elseif ($signalType === 'answer') {
        $dbh->prepare("UPDATE user_video_calls SET status = 'active', started_at = COALESCE(started_at, NOW()), updated_at = NOW() WHERE id = :id LIMIT 1")
            ->execute([':id' => $callId]);
    } elseif ($signalType === 'decline') {
        $dbh->prepare("UPDATE user_video_calls SET status = 'declined', ended_at = NOW(), ended_by_user_id = :uid, updated_at = NOW() WHERE id = :id LIMIT 1")
            ->execute([':id' => $callId, ':uid' => $meId]);
    } elseif ($signalType === 'end') {
        $dbh->prepare("UPDATE user_video_calls SET status = 'ended', ended_at = NOW(), ended_by_user_id = :uid, updated_at = NOW() WHERE id = :id LIMIT 1")
            ->execute([':id' => $callId, ':uid' => $meId]);
    }

    j([
        'ok' => true,
        'signal_id' => (int)$dbh->lastInsertId(),
    ]);
} catch (Throwable $e) {
    j(['ok' => false, 'error' => 'Database error']);
}
