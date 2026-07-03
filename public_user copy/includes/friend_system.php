<?php
declare(strict_types=1);

if (!function_exists('fs_user_has_friend')) {
    function fs_user_has_friend(PDO $dbh, int $userId, int $peerId): bool {
        if ($userId <= 0 || $peerId <= 0) return false;
        try {
            $st = $dbh->prepare("SELECT 1 FROM user_contacts WHERE owner_user_id = ? AND friend_user_id = ? LIMIT 1");
            $st->execute([$userId, $peerId]);
            return (bool)$st->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('fs_are_friends')) {
    function fs_are_friends(PDO $dbh, int $a, int $b): bool {
        return fs_user_has_friend($dbh, $a, $b) || fs_user_has_friend($dbh, $b, $a);
    }
}

if (!function_exists('fs_friend_count')) {
    function fs_friend_count(PDO $dbh, int $userId): int {
        if ($userId <= 0) return 0;
        try {
            $st = $dbh->prepare("SELECT COUNT(DISTINCT friend_user_id) FROM user_contacts WHERE owner_user_id = ?");
            $st->execute([$userId]);
            return (int)$st->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    }
}

if (!function_exists('fs_pending_request_id')) {
    function fs_pending_request_id(PDO $dbh, int $fromUserId, int $toUserId): int {
        if ($fromUserId <= 0 || $toUserId <= 0) return 0;
        try {
            $st = $dbh->prepare("SELECT id FROM contact_requests WHERE from_user_id = ? AND to_user_id = ? AND status = 'pending' ORDER BY id DESC LIMIT 1");
            $st->execute([$fromUserId, $toUserId]);
            return (int)($st->fetchColumn() ?: 0);
        } catch (Throwable $e) {
            return 0;
        }
    }
}

if (!function_exists('fs_friend_status')) {
    function fs_friend_status(PDO $dbh, int $meId, int $peerId): string {
        if ($meId <= 0 || $peerId <= 0 || $meId === $peerId) return 'self';
        if (fs_are_friends($dbh, $meId, $peerId)) return 'friends';
        if (fs_pending_request_id($dbh, $meId, $peerId) > 0) return 'outgoing_pending';
        if (fs_pending_request_id($dbh, $peerId, $meId) > 0) return 'incoming_pending';
        return 'none';
    }
}

if (!function_exists('fs_send_friend_request')) {
    function fs_send_friend_request(PDO $dbh, int $fromUserId, int $toUserId): array {
        if ($fromUserId <= 0 || $toUserId <= 0) return ['ok' => false, 'message' => 'Invalid user.'];
        if ($fromUserId === $toUserId) return ['ok' => false, 'message' => 'You cannot add yourself.'];
        $status = fs_friend_status($dbh, $fromUserId, $toUserId);
        if ($status === 'friends') return ['ok' => false, 'message' => 'This user is already your friend.'];
        if ($status === 'outgoing_pending') return ['ok' => false, 'message' => 'Friend request already sent.'];
        if ($status === 'incoming_pending') return ['ok' => false, 'message' => 'This user already sent you a friend request. Open Friend Requests to accept it.'];
        try {
            $reopen = $dbh->prepare("UPDATE contact_requests SET status = 'pending', created_at = NOW(), updated_at = NOW() WHERE from_user_id = ? AND to_user_id = ? AND status <> 'pending' AND status <> 'blocked' LIMIT 1");
            $reopen->execute([$fromUserId, $toUserId]);
            if ($reopen->rowCount() > 0) {
                return ['ok' => true, 'message' => 'Friend request sent.'];
            }

            $st = $dbh->prepare("INSERT INTO contact_requests (from_user_id, to_user_id, status, created_at) VALUES (?, ?, 'pending', NOW())");
            $st->execute([$fromUserId, $toUserId]);
            return ['ok' => true, 'message' => 'Friend request sent.'];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => 'Unable to send friend request.'];
        }
    }
}

if (!function_exists('fs_accept_friend_request')) {
    function fs_accept_friend_request(PDO $dbh, int $requestId, int $meId): array {
        if ($requestId <= 0 || $meId <= 0) return ['ok' => false, 'message' => 'Request not found.'];
        try {
            $dbh->beginTransaction();
            $st = $dbh->prepare("SELECT id, from_user_id, to_user_id FROM contact_requests WHERE id = ? AND to_user_id = ? AND status = 'pending' LIMIT 1");
            $st->execute([$requestId, $meId]);
            $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
            if (!$row) {
                $dbh->rollBack();
                return ['ok' => false, 'message' => 'Request not found.'];
            }
            $from = (int)$row['from_user_id'];
            $to   = (int)$row['to_user_id'];

            $up = $dbh->prepare("UPDATE contact_requests SET status = 'accepted' WHERE id = ? LIMIT 1");
            $up->execute([$requestId]);

            $ins = $dbh->prepare("INSERT INTO user_contacts (owner_user_id, friend_user_id, display_name, created_at)
                                  SELECT ?, ?, COALESCE(NULLIF(TRIM(name), ''), NULLIF(TRIM(username), ''), email, friend_code, CONCAT('User ', id)), NOW()
                                  FROM users WHERE id = ?
                                  AND NOT EXISTS (
                                      SELECT 1 FROM user_contacts WHERE owner_user_id = ? AND friend_user_id = ?
                                  )");
            $ins->execute([$to, $from, $from, $to, $from]);
            $ins->execute([$from, $to, $to, $from, $to]);

            $dbh->commit();
            return ['ok' => true, 'message' => 'Friend request accepted.'];
        } catch (Throwable $e) {
            if ($dbh->inTransaction()) $dbh->rollBack();
            return ['ok' => false, 'message' => 'Unable to accept friend request.'];
        }
    }
}

if (!function_exists('fs_decline_friend_request')) {
    function fs_decline_friend_request(PDO $dbh, int $requestId, int $meId): array {
        if ($requestId <= 0 || $meId <= 0) return ['ok' => false, 'message' => 'Request not found.'];
        try {
            $st = $dbh->prepare("UPDATE contact_requests SET status = 'declined' WHERE id = ? AND to_user_id = ? AND status = 'pending'");
            $st->execute([$requestId, $meId]);
            return ['ok' => ($st->rowCount() > 0), 'message' => ($st->rowCount() > 0 ? 'Friend request declined.' : 'Request not found.')];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => 'Unable to decline friend request.'];
        }
    }
}

if (!function_exists('fs_remove_friend')) {
    function fs_remove_friend(PDO $dbh, int $meId, int $peerId): array {
        if ($meId <= 0 || $peerId <= 0 || $meId === $peerId) {
            return ['ok' => false, 'message' => 'Invalid user.'];
        }
        if (!fs_are_friends($dbh, $meId, $peerId)) {
            return ['ok' => false, 'message' => 'You are not friends with this user.'];
        }
        try {
            $dbh->beginTransaction();
            $del = $dbh->prepare(
                "DELETE FROM user_contacts
                 WHERE (owner_user_id = ? AND friend_user_id = ?)
                    OR (owner_user_id = ? AND friend_user_id = ?)"
            );
            $del->execute([$meId, $peerId, $peerId, $meId]);
            $dbh->commit();
            return ['ok' => true, 'message' => 'Friend removed.'];
        } catch (Throwable $e) {
            if ($dbh->inTransaction()) $dbh->rollBack();
            return ['ok' => false, 'message' => 'Unable to remove friend.'];
        }
    }
}
