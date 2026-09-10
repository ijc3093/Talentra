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

function private_call_latest_offer_payload(PDO $dbh, int $callId, int $toUserId): ?string
{
    if ($callId <= 0 || $toUserId <= 0) {
        return null;
    }
    try {
        $st = $dbh->prepare("
            SELECT payload
            FROM user_video_call_signals
            WHERE call_id = :call_id
              AND to_user_id = :to_user_id
              AND signal_type = 'offer'
              AND payload IS NOT NULL
              AND payload <> ''
            ORDER BY id DESC
            LIMIT 1
        ");
        $st->execute([
            ':call_id' => $callId,
            ':to_user_id' => $toUserId,
        ]);
        $payload = $st->fetchColumn();
        return is_string($payload) && $payload !== '' ? $payload : null;
    } catch (Throwable $e) {
        return null;
    }
}

function private_call_display_name(PDO $dbh, int $ownerId, int $personId, string $fallbackCode): string
{
    try {
        $st = $dbh->prepare("
            SELECT COALESCE(NULLIF(uc.display_name, ''), NULLIF(u.name, ''), NULLIF(u.username, ''), u.friend_code) AS display_name
            FROM users u
            LEFT JOIN user_contacts uc
              ON uc.owner_user_id = :owner_id
             AND uc.friend_user_id = u.id
            WHERE u.id = :person_id
            LIMIT 1
        ");
        $st->execute([
            ':owner_id' => $ownerId,
            ':person_id' => $personId,
        ]);
        $name = trim((string)($st->fetchColumn() ?: ''));
        if ($name !== '') {
            return $name;
        }
    } catch (Throwable $e) {
        // Fall through to the stable code fallback.
    }
    return trim($fallbackCode) !== '' ? trim($fallbackCode) : 'this contact';
}

function private_call_event_marker(string $action, string $actor, string $target, int $callId): string
{
    $payload = json_encode([
        'action' => $action,
        'actor' => $actor,
        'target' => $target,
        'call_id' => $callId,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return '[[MSB_CALL_EVENT:' . (is_string($payload) ? $payload : '{}') . ']]';
}

function private_insert_call_event_once(PDO $dbh, string $senderCode, string $receiverCode, string $message): void
{
    $senderCode = strtoupper(trim($senderCode));
    $receiverCode = strtoupper(trim($receiverCode));
    $message = trim($message);
    if ($senderCode === '' || $receiverCode === '' || $message === '') {
        return;
    }

    try {
        $stExists = $dbh->prepare("
            SELECT id
            FROM feedback
            WHERE sender = :sender
              AND receiver = :receiver
              AND channel = 'user_user'
              AND feedbackdata = :message
            LIMIT 1
        ");
        $stExists->execute([
            ':sender' => $senderCode,
            ':receiver' => $receiverCode,
            ':message' => $message,
        ]);
        if ($stExists->fetchColumn()) {
            return;
        }

        $stInsert = $dbh->prepare("
            INSERT INTO feedback
                (sender, receiver, channel, title, feedbackdata,
                 attachment, attachment_type, attachment_original, attachment_url,
                 is_read, created_at)
            VALUES
                (:sender, :receiver, 'user_user', '', :message,
                 '', '', '', '',
                 0, NOW())
        ");
        $stInsert->execute([
            ':sender' => $senderCode,
            ':receiver' => $receiverCode,
            ':message' => $message,
        ]);
    } catch (Throwable $e) {
        // Call state should still complete even if the chat event cannot be stored.
    }
}

function private_mark_missed_call_if_needed(PDO $dbh, array $call): array
{
    $status = (string)($call['status'] ?? '');
    if (!in_array($status, ['initiated', 'ringing'], true)) {
        return $call;
    }

    $callId = (int)($call['id'] ?? 0);
    $callerId = (int)($call['caller_user_id'] ?? 0);
    $calleeId = (int)($call['callee_user_id'] ?? 0);
    $callerCode = (string)($call['caller_code'] ?? '');
    $calleeCode = (string)($call['callee_code'] ?? '');
    if ($callId <= 0 || $callerId <= 0 || $calleeId <= 0) {
        return $call;
    }

    try {
        $stUpdate = $dbh->prepare("
            UPDATE user_video_calls
            SET status = 'missed',
                ended_at = NOW(),
                ended_by_user_id = :callee_id,
                updated_at = NOW()
            WHERE id = :call_id
              AND status IN ('initiated', 'ringing')
              AND created_at <= (NOW() - INTERVAL 60 SECOND)
            LIMIT 1
        ");
        $stUpdate->execute([
            ':callee_id' => $calleeId,
            ':call_id' => $callId,
        ]);

        if ($stUpdate->rowCount() <= 0) {
            return $call;
        }

        $calleeNameForCaller = private_call_display_name($dbh, $callerId, $calleeId, $calleeCode);
        $callerNameForCallee = private_call_display_name($dbh, $calleeId, $callerId, $callerCode);
        private_insert_call_event_once(
            $dbh,
            $calleeCode,
            $callerCode,
            private_call_event_marker('miss', $calleeNameForCaller, $callerNameForCallee, $callId)
        );

        $call['status'] = 'missed';
        $call['ended_at'] = date('Y-m-d H:i:s');
        $call['ended_by_user_id'] = $calleeId;
        $call['updated_at'] = date('Y-m-d H:i:s');
    } catch (Throwable $e) {
        // Keep polling resilient.
    }

    return $call;
}

$controller = new Controller();
$dbh = $controller->pdo();

$meId = (int)($_SESSION['user_id'] ?? 0);
$meCode = strtoupper(trim((string)($_SESSION['friend_code'] ?? $_SESSION['user_friend_code'] ?? '')));
if ($meId <= 0 || $meCode === '') j(['ok' => false, 'error' => 'Invalid session']);

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

$peerCode = strtoupper(trim((string)($_GET['peer'] ?? '')));
$groupId = (int)($_GET['group_id'] ?? 0);
$afterSignalId = (int)($_GET['after_signal_id'] ?? 0);
$currentCallId = (int)($_GET['call_id'] ?? 0);
$wait = (int)($_GET['wait'] ?? 0);
$globalIncoming = ((string)($_GET['global'] ?? '') === '1');
$scope = strtolower(trim((string)($_GET['scope'] ?? 'private')));
if ($wait < 0) $wait = 0;
if ($wait > 12) $wait = 12;

if (!$globalIncoming && $groupId <= 0 && $peerCode === '') j(['ok' => false, 'error' => 'Missing peer']);

try {
    if ($globalIncoming && $scope === 'group') {
        if (!ensure_group_video_call_tables($dbh)) {
            j(['ok' => false, 'error' => 'Group call tables unavailable']);
        }

        $deadline = $wait > 0 ? (microtime(true) + $wait) : microtime(true);

        do {
            $stCall = $dbh->prepare("
                SELECT
                    c.id,
                    c.group_id,
                    c.started_by_user_id,
                    c.call_mode,
                    c.status,
                    c.created_at,
                    c.updated_at,
                    c.started_at,
                    c.ended_at,
                    g.name AS group_name,
                    COALESCE(NULLIF(u.name,''), NULLIF(u.username,''), u.friend_code) AS starter_name,
                    p.invite_status AS my_status
                FROM group_video_calls c
                JOIN group_video_call_participants p
                  ON p.call_id = c.id
                 AND p.user_id = :me
                 AND p.invite_status = 'invited'
                JOIN chat_groups g
                  ON g.id = c.group_id
                 AND g.status = 1
                JOIN chat_group_members gm
                  ON gm.group_id = c.group_id
                 AND gm.user_id = :me_member
                 AND gm.left_at IS NULL
                 AND gm.blocked_at IS NULL
                LEFT JOIN users u
                  ON u.id = c.started_by_user_id
                WHERE c.status IN ('initiated','ringing','active')
                ORDER BY c.id DESC
                LIMIT 1
            ");
            $stCall->execute([
                ':me' => $meId,
                ':me_member' => $meId,
            ]);
            $call = $stCall->fetch(PDO::FETCH_ASSOC) ?: null;
            $group = null;
            $participants = [];

            if ($call) {
                $callId = (int)($call['id'] ?? 0);
                if ($callId > 0) {
                    touch_group_video_call_participant($dbh, $callId, $meId);
                    $participants = fetch_group_video_call_participants($dbh, $callId);
                }

                $groupName = trim((string)($call['group_name'] ?? 'Group call'));
                $groupIdFromCall = (int)($call['group_id'] ?? 0);
                $group = [
                    'group_id' => $groupIdFromCall,
                    'name' => $groupName !== '' ? $groupName : 'Group call',
                    'initials' => strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $groupName) ?: 'GC', 0, 2)),
                    'starter_name' => trim((string)($call['starter_name'] ?? 'Someone')),
                ];
                unset($call['group_name'], $call['starter_name']);
            }

            if ($call || $wait <= 0 || microtime(true) >= $deadline) {
                j([
                    'ok' => true,
                    'scope' => 'group',
                    'global' => true,
                    'group' => $group,
                    'call' => $call,
                    'participants' => $participants,
                    'signals' => [],
                    'last_signal_id' => $afterSignalId,
                ]);
            }

            usleep(250000);
        } while (microtime(true) < $deadline);

        j([
            'ok' => true,
            'scope' => 'group',
            'global' => true,
            'group' => null,
            'call' => null,
            'participants' => [],
            'signals' => [],
            'last_signal_id' => $afterSignalId,
        ]);
    }

    if ($globalIncoming && $groupId <= 0) {
        if (!ensure_private_video_call_tables($dbh)) {
            j([
                'ok' => true,
                'scope' => 'private',
                'global' => true,
                'peer' => null,
                'call' => null,
                'participants' => [],
                'signals' => [],
                'last_signal_id' => $afterSignalId,
            ]);
        }

        $deadline = $wait > 0 ? (microtime(true) + $wait) : microtime(true);

        do {
            $stCall = $dbh->prepare("
                SELECT c.id, c.call_mode, c.caller_user_id, c.caller_code, c.callee_user_id, c.callee_code,
                       c.status, c.started_at, c.ended_at, c.created_at, c.updated_at,
                       COALESCE(NULLIF(uc.display_name, ''), NULLIF(u.name, ''), NULLIF(u.username, ''), u.friend_code) AS peer_display,
                       u.friend_code AS peer_code
                FROM user_video_calls c
                JOIN users u
                  ON u.id = c.caller_user_id
                LEFT JOIN user_contacts uc
                  ON uc.owner_user_id = :me_contact
                 AND uc.friend_user_id = c.caller_user_id
                WHERE c.callee_user_id = :me
                  AND c.status IN ('initiated','ringing')
                ORDER BY c.id DESC
                LIMIT 1
            ");
            $stCall->execute([
                ':me_contact' => $meId,
                ':me' => $meId,
            ]);
            $call = $stCall->fetch(PDO::FETCH_ASSOC) ?: null;

            $peer = null;
            $signals = [];
            $lastSignalId = $afterSignalId;

            if ($call) {
                $call = private_mark_missed_call_if_needed($dbh, $call);
                $status = (string)($call['status'] ?? '');
                if (!in_array($status, ['initiated', 'ringing'], true)) {
                    $call = null;
                }
            }

            if ($call) {
                $callId = (int)($call['id'] ?? 0);
                $peerCodeFromCall = strtoupper(trim((string)($call['peer_code'] ?? $call['caller_code'] ?? '')));
                $peerDisplay = trim((string)($call['peer_display'] ?? $peerCodeFromCall));
                $peer = [
                    'peer_code' => $peerCodeFromCall,
                    'display_name' => $peerDisplay !== '' ? $peerDisplay : $peerCodeFromCall,
                    'avatar_url' => 'avatar.php?friend_code=' . rawurlencode($peerCodeFromCall) . '&name=' . rawurlencode($peerDisplay !== '' ? $peerDisplay : $peerCodeFromCall),
                ];
                unset($call['peer_code'], $call['peer_display']);

                if ($callId > 0) {
                    $latestOfferPayload = private_call_latest_offer_payload($dbh, $callId, $meId);
                    if ($latestOfferPayload !== null) {
                        $call['latest_offer_payload'] = $latestOfferPayload;
                    }

                    $stSig = $dbh->prepare("
                        SELECT id, signal_type, payload, from_user_id, to_user_id, created_at
                        FROM user_video_call_signals
                        WHERE call_id = :call_id
                          AND to_user_id = :to_user_id
                          AND id > :after_id
                        ORDER BY id ASC
                        LIMIT 100
                    ");
                    $stSig->execute([
                        ':call_id' => $callId,
                        ':to_user_id' => $meId,
                        ':after_id' => $afterSignalId,
                    ]);
                    $signals = $stSig->fetchAll(PDO::FETCH_ASSOC) ?: [];
                    foreach ($signals as $sig) {
                        $sid = (int)($sig['id'] ?? 0);
                        if ($sid > $lastSignalId) $lastSignalId = $sid;
                    }
                    if ($signals) {
                        $dbh->prepare("UPDATE user_video_call_signals SET consumed_at = NOW() WHERE to_user_id = :uid AND call_id = :call_id AND id <= :max_id")
                            ->execute([
                                ':uid' => $meId,
                                ':call_id' => $callId,
                                ':max_id' => $lastSignalId,
                            ]);
                    }
                }
            }

            $hasPayload = $call || !empty($signals);
            if ($hasPayload || $wait <= 0 || microtime(true) >= $deadline) {
                j([
                    'ok' => true,
                    'scope' => 'private',
                    'global' => true,
                    'peer' => $peer,
                    'call' => $call,
                    'participants' => [],
                    'signals' => $signals,
                    'last_signal_id' => $lastSignalId,
                ]);
            }

            usleep(250000);
        } while (microtime(true) < $deadline);

        j([
            'ok' => true,
            'scope' => 'private',
            'global' => true,
            'peer' => null,
            'call' => null,
            'participants' => [],
            'signals' => [],
            'last_signal_id' => $afterSignalId,
        ]);
    }

	    if ($groupId > 0) {
        if (!ensure_group_video_call_tables($dbh)) {
            j(['ok' => false, 'error' => 'Group call tables unavailable']);
        }
        if (!is_active_chat_group_member($dbh, $groupId, $meId)) {
            j(['ok' => false, 'error' => 'Group not found']);
        }

        $deadline = $wait > 0 ? (microtime(true) + $wait) : microtime(true);
        do {
            $call = fetch_group_video_call_for_user($dbh, $groupId, $meId, $currentCallId);
            $participants = [];
            $signals = [];
            $lastSignalId = $afterSignalId;

            if ($call) {
                $callId = (int)($call['id'] ?? 0);
                if ($callId > 0) {
                    touch_group_video_call_participant($dbh, $callId, $meId);
                    $participants = fetch_group_video_call_participants($dbh, $callId);

                    $stSig = $dbh->prepare("
                        SELECT id, signal_type, payload, from_user_id, to_user_id, created_at
                        FROM group_video_call_signals
                        WHERE call_id = :call_id
                          AND to_user_id = :to_user_id
                          AND id > :after_id
                        ORDER BY id ASC
                        LIMIT 100
                    ");
                    $stSig->execute([
                        ':call_id' => $callId,
                        ':to_user_id' => $meId,
                        ':after_id' => $afterSignalId,
                    ]);
                    $signals = $stSig->fetchAll(PDO::FETCH_ASSOC) ?: [];
                    foreach ($signals as $sig) {
                        $sid = (int)($sig['id'] ?? 0);
                        if ($sid > $lastSignalId) $lastSignalId = $sid;
                    }
                    if ($signals) {
                        $dbh->prepare("
                            UPDATE group_video_call_signals
                            SET consumed_at = NOW()
                            WHERE to_user_id = :uid
                              AND call_id = :call_id
                              AND id <= :max_id
                        ")->execute([
                            ':uid' => $meId,
                            ':call_id' => $callId,
                            ':max_id' => $lastSignalId,
                        ]);
                    }
                }
            }

            $hasPayload = $call || !empty($signals);
            if ($hasPayload || $wait <= 0 || microtime(true) >= $deadline) {
                j([
                    'ok' => true,
                    'scope' => 'group',
                    'call' => $call,
                    'participants' => $participants,
                    'signals' => $signals,
                    'last_signal_id' => $lastSignalId,
                ]);
            }

            usleep(250000);
        } while (microtime(true) < $deadline);

        j([
            'ok' => true,
            'scope' => 'group',
            'call' => null,
            'participants' => [],
            'signals' => [],
            'last_signal_id' => $afterSignalId,
        ]);
    }

    if (!ensure_private_video_call_tables($dbh)) {
        j(['ok' => false, 'error' => 'Private call tables unavailable']);
    }

    $stPeer = $dbh->prepare("SELECT id, friend_code FROM users WHERE UPPER(friend_code) = :c AND status = 1 LIMIT 1");
    $stPeer->execute([':c' => $peerCode]);
    $peer = $stPeer->fetch(PDO::FETCH_ASSOC);
    if (!$peer) j(['ok' => false, 'error' => 'Peer not found']);

    $peerId = (int)($peer['id'] ?? 0);
    $deadline = $wait > 0 ? (microtime(true) + $wait) : microtime(true);

    do {
        $call = null;
        if ($currentCallId > 0) {
            $stCall = $dbh->prepare("
                SELECT id, call_mode, caller_user_id, caller_code, callee_user_id, callee_code, status, started_at, ended_at, created_at, updated_at
                FROM user_video_calls
                WHERE id = :id
                  AND (caller_user_id = :me OR callee_user_id = :me2)
                LIMIT 1
            ");
            $stCall->execute([':id' => $currentCallId, ':me' => $meId, ':me2' => $meId]);
            $call = $stCall->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        if (!$call) {
            $stCall = $dbh->prepare("
                SELECT id, call_mode, caller_user_id, caller_code, callee_user_id, callee_code, status, started_at, ended_at, created_at, updated_at
                FROM user_video_calls
                WHERE (
                        (caller_user_id = :me AND callee_user_id = :peer)
                     OR (caller_user_id = :peer2 AND callee_user_id = :me2)
                      )
                  AND status IN ('initiated','ringing','active','declined','ended','missed','failed')
                ORDER BY id DESC
                LIMIT 1
            ");
            $stCall->execute([
                ':me' => $meId,
                ':peer' => $peerId,
                ':peer2' => $peerId,
                ':me2' => $meId,
            ]);
            $call = $stCall->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        $signals = [];
        $lastSignalId = $afterSignalId;
        if ($call) {
            $call = private_mark_missed_call_if_needed($dbh, $call);
            $callId = (int)($call['id'] ?? 0);
            if ($callId > 0) {
                $latestOfferPayload = private_call_latest_offer_payload($dbh, $callId, $meId);
                if ($latestOfferPayload !== null) {
                    $call['latest_offer_payload'] = $latestOfferPayload;
                }
            }

            if (
                (int)($call['caller_user_id'] ?? 0) === $meId
                && in_array((string)($call['status'] ?? ''), ['initiated', 'ringing', 'active'], true)
            ) {
                $dbh->prepare("
                    UPDATE user_video_calls
                    SET updated_at = NOW()
                    WHERE id = :id
                    LIMIT 1
                ")->execute([
                    ':id' => (int)$call['id'],
                ]);
                $call['updated_at'] = date('Y-m-d H:i:s');
            }

            $stSig = $dbh->prepare("
                SELECT id, signal_type, payload, from_user_id, to_user_id, created_at
                FROM user_video_call_signals
                WHERE call_id = :call_id
                  AND to_user_id = :to_user_id
                  AND id > :after_id
                ORDER BY id ASC
                LIMIT 100
            ");
            $stSig->execute([
                ':call_id' => (int)$call['id'],
                ':to_user_id' => $meId,
                ':after_id' => $afterSignalId,
            ]);
            $signals = $stSig->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($signals as $sig) {
                $sid = (int)($sig['id'] ?? 0);
                if ($sid > $lastSignalId) $lastSignalId = $sid;
            }
            if ($signals) {
                $dbh->prepare("UPDATE user_video_call_signals SET consumed_at = NOW() WHERE to_user_id = :uid AND call_id = :call_id AND id <= :max_id")
                    ->execute([
                        ':uid' => $meId,
                        ':call_id' => (int)$call['id'],
                        ':max_id' => $lastSignalId,
                    ]);
            }
        }

        $hasPayload = $call || !empty($signals);
        if ($hasPayload || $wait <= 0 || microtime(true) >= $deadline) {
            j([
                'ok' => true,
                'scope' => 'private',
                'call' => $call,
                'participants' => [],
                'signals' => $signals,
                'last_signal_id' => $lastSignalId,
            ]);
        }

        usleep(250000);
    } while (microtime(true) < $deadline);

    j([
        'ok' => true,
        'scope' => 'private',
        'call' => null,
        'participants' => [],
        'signals' => [],
        'last_signal_id' => $afterSignalId,
    ]);
} catch (Throwable $e) {
    j(['ok' => false, 'error' => 'Database error']);
}
