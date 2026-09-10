<?php
declare(strict_types=1);

function ensure_group_video_call_tables(PDO $dbh): bool {
    try {
        $dbh->exec("
            CREATE TABLE IF NOT EXISTS group_video_calls (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                group_id INT NOT NULL,
                started_by_user_id INT NOT NULL,
                call_mode VARCHAR(16) NOT NULL DEFAULT 'video',
                status VARCHAR(20) NOT NULL DEFAULT 'initiated',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                started_at DATETIME NULL DEFAULT NULL,
                ended_at DATETIME NULL DEFAULT NULL,
                KEY idx_group_video_calls_group (group_id, status, id),
                KEY idx_group_video_calls_starter (started_by_user_id, id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $dbh->exec("
            CREATE TABLE IF NOT EXISTS group_video_call_participants (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                call_id BIGINT UNSIGNED NOT NULL,
                user_id INT NOT NULL,
                invite_status VARCHAR(20) NOT NULL DEFAULT 'invited',
                invited_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                responded_at DATETIME NULL DEFAULT NULL,
                joined_at DATETIME NULL DEFAULT NULL,
                left_at DATETIME NULL DEFAULT NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                last_seen_at DATETIME NULL DEFAULT NULL,
                UNIQUE KEY uq_group_video_call_participant (call_id, user_id),
                KEY idx_group_video_call_participant_user (user_id, invite_status),
                KEY idx_group_video_call_participant_call (call_id, invite_status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $dbh->exec("
            CREATE TABLE IF NOT EXISTS group_video_call_signals (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                call_id BIGINT UNSIGNED NOT NULL,
                from_user_id INT NOT NULL,
                to_user_id INT NOT NULL,
                signal_type VARCHAR(20) NOT NULL,
                payload LONGTEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                consumed_at DATETIME NULL DEFAULT NULL,
                KEY idx_group_video_call_signals_target (to_user_id, call_id, id),
                KEY idx_group_video_call_signals_call (call_id, id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function is_active_chat_group_member(PDO $dbh, int $groupId, int $userId): bool {
    if ($groupId <= 0 || $userId <= 0) return false;
    try {
        $st = $dbh->prepare("
            SELECT 1
            FROM chat_group_members
            WHERE group_id = :gid
              AND user_id = :uid
              AND left_at IS NULL
              AND blocked_at IS NULL
            LIMIT 1
        ");
        $st->execute([':gid' => $groupId, ':uid' => $userId]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function list_active_chat_group_member_ids(PDO $dbh, int $groupId): array {
    if ($groupId <= 0) return [];
    try {
        $st = $dbh->prepare("
            SELECT user_id
            FROM chat_group_members
            WHERE group_id = :gid
              AND left_at IS NULL
              AND blocked_at IS NULL
            ORDER BY user_id ASC
        ");
        $st->execute([':gid' => $groupId]);
        $rows = $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
        return array_values(array_unique(array_map('intval', $rows)));
    } catch (Throwable $e) {
        return [];
    }
}

function fetch_group_video_call_participants(PDO $dbh, int $callId): array {
    if ($callId <= 0) return [];
    try {
        $st = $dbh->prepare("
            SELECT
                p.user_id,
                p.invite_status,
                p.invited_at,
                p.responded_at,
                p.joined_at,
                p.left_at,
                p.updated_at,
                p.last_seen_at,
                COALESCE(NULLIF(u.name,''), NULLIF(u.username,''), u.friend_code) AS display_name,
                COALESCE(u.friend_code, '') AS friend_code
            FROM group_video_call_participants p
            JOIN users u
              ON u.id = p.user_id
            WHERE p.call_id = :call_id
            ORDER BY p.user_id ASC
        ");
        $st->execute([':call_id' => $callId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function fetch_group_video_call_for_user(PDO $dbh, int $groupId, int $userId, int $currentCallId = 0): ?array {
    if ($groupId <= 0 || $userId <= 0) return null;
    try {
        if ($currentCallId > 0) {
            $st = $dbh->prepare("
                SELECT
                    c.id,
                    c.group_id,
                    c.started_by_user_id,
                    c.call_mode,
                    c.status,
                    c.created_at,
                    c.updated_at,
                    c.started_at,
                    c.ended_at
                FROM group_video_calls c
                JOIN group_video_call_participants p
                  ON p.call_id = c.id
                 AND p.user_id = :uid
                WHERE c.id = :call_id
                LIMIT 1
            ");
            $st->execute([':uid' => $userId, ':call_id' => $currentCallId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if ($row) return $row;
        }

        $st = $dbh->prepare("
            SELECT
                c.id,
                c.group_id,
                c.started_by_user_id,
                c.call_mode,
                c.status,
                c.created_at,
                c.updated_at,
                c.started_at,
                c.ended_at
            FROM group_video_calls c
            JOIN group_video_call_participants p
              ON p.call_id = c.id
             AND p.user_id = :uid
             AND p.invite_status IN ('invited','requested','joined')
            WHERE c.group_id = :gid
              AND c.status IN ('initiated','ringing','active')
            ORDER BY c.id DESC
            LIMIT 1
        ");
        $st->execute([':uid' => $userId, ':gid' => $groupId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

function update_group_video_call_participant_status(PDO $dbh, int $callId, int $userId, string $status): void {
    if ($callId <= 0 || $userId <= 0) return;
    $status = strtolower(trim($status));
    if (!in_array($status, ['invited', 'requested', 'joined', 'declined', 'left'], true)) return;

    if ($status === 'joined') {
        $st = $dbh->prepare("
            UPDATE group_video_call_participants
            SET invite_status = 'joined',
                responded_at = COALESCE(responded_at, NOW()),
                joined_at = COALESCE(joined_at, NOW()),
                left_at = NULL,
                last_seen_at = NOW(),
                updated_at = NOW()
            WHERE call_id = :call_id
              AND user_id = :uid
            LIMIT 1
        ");
        $st->execute([':call_id' => $callId, ':uid' => $userId]);
        return;
    }

    if ($status === 'requested') {
        $st = $dbh->prepare("
            UPDATE group_video_call_participants
            SET invite_status = 'requested',
                responded_at = COALESCE(responded_at, NOW()),
                left_at = NULL,
                updated_at = NOW()
            WHERE call_id = :call_id
              AND user_id = :uid
            LIMIT 1
        ");
        $st->execute([':call_id' => $callId, ':uid' => $userId]);
        return;
    }

    if ($status === 'declined') {
        $st = $dbh->prepare("
            UPDATE group_video_call_participants
            SET invite_status = 'declined',
                responded_at = COALESCE(responded_at, NOW()),
                left_at = COALESCE(left_at, NOW()),
                updated_at = NOW()
            WHERE call_id = :call_id
              AND user_id = :uid
            LIMIT 1
        ");
        $st->execute([':call_id' => $callId, ':uid' => $userId]);
        return;
    }

    if ($status === 'left') {
        $st = $dbh->prepare("
            UPDATE group_video_call_participants
            SET invite_status = 'left',
                responded_at = COALESCE(responded_at, NOW()),
                left_at = COALESCE(left_at, NOW()),
                updated_at = NOW()
            WHERE call_id = :call_id
              AND user_id = :uid
            LIMIT 1
        ");
        $st->execute([':call_id' => $callId, ':uid' => $userId]);
        return;
    }

    $st = $dbh->prepare("
        UPDATE group_video_call_participants
        SET invite_status = 'invited',
            updated_at = NOW()
        WHERE call_id = :call_id
          AND user_id = :uid
        LIMIT 1
    ");
    $st->execute([':call_id' => $callId, ':uid' => $userId]);
}

function touch_group_video_call_participant(PDO $dbh, int $callId, int $userId): void {
    if ($callId <= 0 || $userId <= 0) return;
    try {
        $st = $dbh->prepare("
            UPDATE group_video_call_participants
            SET last_seen_at = NOW(),
                updated_at = NOW()
            WHERE call_id = :call_id
              AND user_id = :uid
            LIMIT 1
        ");
        $st->execute([':call_id' => $callId, ':uid' => $userId]);
    } catch (Throwable $e) {
    }
}

function insert_group_video_call_signals(PDO $dbh, int $callId, int $fromUserId, array $toUserIds, string $signalType, ?string $payload): void {
    if ($callId <= 0 || $fromUserId <= 0 || !$toUserIds) return;
    $st = $dbh->prepare("
        INSERT INTO group_video_call_signals
          (call_id, from_user_id, to_user_id, signal_type, payload, created_at)
        VALUES
          (:call_id, :from_user_id, :to_user_id, :signal_type, :payload, NOW())
    ");
    foreach (array_values(array_unique(array_map('intval', $toUserIds))) as $toUserId) {
        if ($toUserId <= 0 || $toUserId === $fromUserId) continue;
        $st->execute([
            ':call_id' => $callId,
            ':from_user_id' => $fromUserId,
            ':to_user_id' => $toUserId,
            ':signal_type' => $signalType,
            ':payload' => $payload,
        ]);
    }
}

function sync_group_video_call_status(PDO $dbh, int $callId): ?string {
    if ($callId <= 0) return null;
    try {
        $st = $dbh->prepare("
            SELECT
                SUM(CASE WHEN invite_status = 'joined' THEN 1 ELSE 0 END) AS joined_count,
                SUM(CASE WHEN invite_status = 'invited' THEN 1 ELSE 0 END) AS invited_count
            FROM group_video_call_participants
            WHERE call_id = :call_id
        ");
        $st->execute([':call_id' => $callId]);
        $counts = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $joinedCount = (int)($counts['joined_count'] ?? 0);
        $invitedCount = (int)($counts['invited_count'] ?? 0);

        $status = 'ended';
        if ($joinedCount > 1) {
            $status = 'active';
        } elseif ($joinedCount === 1 && $invitedCount > 0) {
            $status = 'ringing';
        } elseif ($joinedCount === 1) {
            $status = 'active';
        }

        if ($status === 'ended') {
            $dbh->prepare("
                UPDATE group_video_calls
                SET status = 'ended',
                    ended_at = COALESCE(ended_at, NOW()),
                    updated_at = NOW()
                WHERE id = :call_id
                LIMIT 1
            ")->execute([':call_id' => $callId]);
            return $status;
        }

        $dbh->prepare("
            UPDATE group_video_calls
            SET status = :status,
                started_at = CASE
                    WHEN :status = 'active' THEN COALESCE(started_at, NOW())
                    ELSE started_at
                END,
                ended_at = NULL,
                updated_at = NOW()
            WHERE id = :call_id
            LIMIT 1
        ")->execute([
            ':status' => $status,
            ':call_id' => $callId,
        ]);

        return $status;
    } catch (Throwable $e) {
        return null;
    }
}

function group_video_call_display_name(PDO $dbh, int $userId): string {
    if ($userId <= 0) return 'Someone';
    try {
        $st = $dbh->prepare("
            SELECT COALESCE(NULLIF(name,''), NULLIF(username,''), friend_code) AS display_name
            FROM users
            WHERE id = :uid
            LIMIT 1
        ");
        $st->execute([':uid' => $userId]);
        $name = trim((string)($st->fetchColumn() ?: ''));
        return $name !== '' ? $name : 'Someone';
    } catch (Throwable $e) {
        return 'Someone';
    }
}

function group_video_call_group_name(PDO $dbh, int $groupId): string {
    if ($groupId <= 0) return 'group';
    try {
        $st = $dbh->prepare("
            SELECT name
            FROM chat_groups
            WHERE id = :gid
            LIMIT 1
        ");
        $st->execute([':gid' => $groupId]);
        $name = trim((string)($st->fetchColumn() ?: ''));
        return $name !== '' ? $name : 'group';
    } catch (Throwable $e) {
        return 'group';
    }
}

function group_video_call_event_marker(string $action, string $actor, string $target, int $callId): string {
    $payload = json_encode([
        'action' => $action,
        'actor' => $actor,
        'target' => $target,
        'call_id' => $callId,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return '[[MSB_CALL_EVENT:' . (is_string($payload) ? $payload : '{}') . ']]';
}

function insert_group_call_event_once(PDO $dbh, int $groupId, int $senderUserId, string $message): void {
    $message = trim($message);
    if ($groupId <= 0 || $senderUserId <= 0 || $message === '') return;
    try {
        $stExists = $dbh->prepare("
            SELECT id
            FROM chat_group_messages
            WHERE group_id = :gid
              AND body = :body
            LIMIT 1
        ");
        $stExists->execute([
            ':gid' => $groupId,
            ':body' => $message,
        ]);
        if ($stExists->fetchColumn()) return;

        $stInsert = $dbh->prepare("
            INSERT INTO chat_group_messages (group_id, sender_user_id, body, created_at)
            VALUES (:gid, :uid, :body, NOW())
        ");
        $stInsert->execute([
            ':gid' => $groupId,
            ':uid' => $senderUserId,
            ':body' => $message,
        ]);
        $dbh->prepare("UPDATE chat_groups SET updated_at = NOW() WHERE id = :gid LIMIT 1")
            ->execute([':gid' => $groupId]);
    } catch (Throwable $e) {
        // Call state should still complete even if the final chat event cannot be stored.
    }
}

function record_group_video_call_finished_message(PDO $dbh, int $callId, int $actorUserId): void {
    if ($callId <= 0 || $actorUserId <= 0) return;
    try {
        $st = $dbh->prepare("
            SELECT id, group_id, status
            FROM group_video_calls
            WHERE id = :call_id
            LIMIT 1
        ");
        $st->execute([':call_id' => $callId]);
        $call = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$call || strtolower((string)($call['status'] ?? '')) !== 'ended') return;

        $groupId = (int)($call['group_id'] ?? 0);
        if ($groupId <= 0) return;

        $actor = group_video_call_display_name($dbh, $actorUserId);
        $target = group_video_call_group_name($dbh, $groupId);
        insert_group_call_event_once(
            $dbh,
            $groupId,
            $actorUserId,
            group_video_call_event_marker('end', $actor, $target, $callId)
        );
    } catch (Throwable $e) {
    }
}

function broadcast_group_call_chat_message(PDO $dbh, int $groupId, int $senderUserId, array $messageItem): void {
    if ($groupId <= 0 || $senderUserId <= 0 || !$messageItem) return;
    try {
        if (!ensure_group_video_call_tables($dbh)) return;
        $activeCall = fetch_group_video_call_for_user($dbh, $groupId, $senderUserId);
        $activeCallId = (int)($activeCall['id'] ?? 0);
        if ($activeCallId <= 0) return;

        $participants = fetch_group_video_call_participants($dbh, $activeCallId);
        $targetUserIds = [];
        foreach ($participants as $participant) {
            $participantUserId = (int)($participant['user_id'] ?? 0);
            $status = (string)($participant['invite_status'] ?? '');
            if ($participantUserId > 0 && $participantUserId !== $senderUserId && in_array($status, ['invited', 'joined'], true)) {
                $targetUserIds[] = $participantUserId;
            }
        }
        if (!$targetUserIds) return;

        $payload = json_encode($messageItem, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        insert_group_video_call_signals($dbh, $activeCallId, $senderUserId, $targetUserIds, 'chat_message', $payload ?: null);
    } catch (Throwable $e) {
    }
}
