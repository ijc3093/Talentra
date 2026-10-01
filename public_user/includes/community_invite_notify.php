<?php
declare(strict_types=1);

/**
 * Dual-write a community-related notification to username + email receivers.
 */
if (!function_exists('community_notify_user')) {
    function community_notify_user(
        PDO $dbh,
        int $actorUserId,
        int $receiverUserId,
        int $communityId,
        string $messagePrefix,
        string $routeTag,
        int $relatedMemberUserId = 0,
        int $relatedPostId = 0
    ): void {
        if ($actorUserId <= 0 || $receiverUserId <= 0 || $actorUserId === $receiverUserId) {
            return;
        }

        try {
            $st = $dbh->prepare("
                SELECT
                    id,
                    username,
                    email,
                    COALESCE(NULLIF(name, ''), username, 'Someone') AS display_name
                FROM users
                WHERE id IN (?, ?)
            ");
            $st->execute([$actorUserId, $receiverUserId]);
            $byId = [];
            while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                $byId[(int)($row['id'] ?? 0)] = $row;
            }

            $senderLabel = trim((string)($byId[$actorUserId]['display_name'] ?? ''));
            if ($senderLabel === '') {
                return;
            }

            $receivers = [];
            $recv = $byId[$receiverUserId] ?? [];
            foreach (['username', 'email'] as $rk) {
                $rv = trim((string)($recv[$rk] ?? ''));
                if ($rv !== '') {
                    $receivers[$rv] = $rv;
                }
            }
            $receivers = array_values($receivers);
            if ($receivers === []) {
                return;
            }

            $cname = 'a community';
            if ($communityId > 0) {
                $stC = $dbh->prepare('SELECT name FROM communities WHERE id = :id LIMIT 1');
                $stC->execute([':id' => $communityId]);
                $n = trim((string)($stC->fetchColumn() ?: ''));
                if ($n !== '') {
                    $cname = $n;
                }
            }

            $msg = $messagePrefix . $cname;
            $route = preg_replace('/[^a-z]/i', '', $routeTag) ?: 'cinvr';
            $suffix = ' [r:' . $route . ']'
                . ($communityId > 0 ? (' [cc:' . $communityId . ']') : '')
                . ($relatedMemberUserId > 0 ? (' [cm:' . $relatedMemberUserId . ']') : '')
                . ($relatedPostId > 0 ? (' [p:' . $relatedPostId . ']') : '');
            $room = max(0, 100 - mb_strlen($suffix));
            if (mb_strlen($msg) > $room) {
                $msg = rtrim(mb_substr($msg, 0, $room));
            }
            $notitype = mb_substr($msg . $suffix, 0, 100);

            $ins = $dbh->prepare(
                'INSERT INTO notification (notiuser, notireceiver, notitype, is_read) VALUES (:s, :r, :t, 0)'
            );
            foreach ($receivers as $receiverKey) {
                $ins->execute([
                    ':s' => $senderLabel,
                    ':r' => $receiverKey,
                    ':t' => $notitype,
                ]);
            }
        } catch (Throwable $e) {
            // Non-fatal
        }
    }
}

/** Notify every active member except the actor about published community activity. */
if (!function_exists('community_notify_active_members')) {
    function community_notify_active_members(
        PDO $dbh,
        int $actorUserId,
        int $communityId,
        string $messagePrefix,
        string $routeTag,
        int $relatedPostId = 0
    ): void {
        if ($actorUserId <= 0 || $communityId <= 0) {
            return;
        }
        try {
            $st = $dbh->prepare("SELECT user_id FROM community_members WHERE community_id=:c AND status='active' AND user_id<>:actor");
            $st->execute([':c' => $communityId, ':actor' => $actorUserId]);
            foreach (($st->fetchAll(PDO::FETCH_COLUMN) ?: []) as $receiverId) {
                community_notify_user(
                    $dbh,
                    $actorUserId,
                    (int)$receiverId,
                    $communityId,
                    $messagePrefix,
                    $routeTag,
                    0,
                    $relatedPostId
                );
            }
        } catch (Throwable $e) {
            // Community activity remains successful when notifications fail.
        }
    }
}

/**
 * Notify the inviter when someone accepts or declines a community invitation.
 */
if (!function_exists('community_notify_invite_response')) {
    function community_notify_invite_response(
        PDO $dbh,
        int $responderUserId,
        int $inviterUserId,
        int $communityId,
        bool $accepted
    ): void {
        $prefix = $accepted ? 'accepted your invitation to ' : 'denied your invitation to ';
        community_notify_user($dbh, $responderUserId, $inviterUserId, $communityId, $prefix, 'cinvr');
    }
}

/**
 * Notify the community owner when a member leaves.
 */
if (!function_exists('community_notify_member_left')) {
    function community_notify_member_left(
        PDO $dbh,
        int $memberUserId,
        int $ownerUserId,
        int $communityId
    ): void {
        community_notify_user($dbh, $memberUserId, $ownerUserId, $communityId, 'left ', 'cleft');
    }
}

/**
 * Notify the community owner when someone joins (or requests to join).
 */
if (!function_exists('community_notify_member_joined')) {
    function community_notify_member_joined(
        PDO $dbh,
        int $memberUserId,
        int $ownerUserId,
        int $communityId,
        bool $pendingApproval = false
    ): void {
        $prefix = $pendingApproval ? 'requested to join ' : 'joined ';
        community_notify_user(
            $dbh,
            $memberUserId,
            $ownerUserId,
            $communityId,
            $prefix,
            'cjoin',
            $pendingApproval ? $memberUserId : 0
        );
    }
}

/**
 * Notify the requester when staff approve or deny their join request.
 */
if (!function_exists('community_notify_join_response')) {
    function community_notify_join_response(
        PDO $dbh,
        int $staffUserId,
        int $memberUserId,
        int $communityId,
        bool $approved
    ): void {
        $prefix = $approved ? 'approved your request to join ' : 'denied your request to join ';
        community_notify_user($dbh, $staffUserId, $memberUserId, $communityId, $prefix, 'cjoinr');
    }
}
