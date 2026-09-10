<?php
declare(strict_types=1);

/**
 * Chat helpers for Business_only
 *
 * This project uses:
 *   - users.id
 *   - users.friend_code (used in URLs as "peer")
 *   - users.name / users.username (display)
 *   - users.role (integer role id)
 *   - chat_messages: id, sender_id, receiver_id, feedbackdata, attachment, is_read, created_at
 *
 * IMPORTANT:
 * - Messaging is done by IDs in the chat_messages table.
 * - Friend code is only for selecting the peer in the UI.
 */

// if (!function_exists('fmt_time_short')) {
//     function fmt_time_short(string $dt): string {
//         if ($dt === '') return '';
//         $ts = strtotime($dt);
//         if (!$ts) return '';
//         return date('h:i A', $ts);
//     }
// }

// if (!function_exists('h')) {
//     function h(string $s): string {
//         return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
//     }
// }


if (!function_exists('fmt_day_label')) {
    function fmt_day_label(string $dt): string {
        if ($dt === '') return '';
        $ts = strtotime($dt);
        if (!$ts) return '';
        $today = date('Y-m-d');
        $day   = date('Y-m-d', $ts);
        if ($day === $today) return 'Today';
        if ($day === date('Y-m-d', strtotime('-1 day'))) return 'Yesterday';
        return date('M j, Y', $ts);
    }
}

/**
 * Resolve a peer by friend code.
 */
function resolvePeerByFriendCode(PDO $dbh, string $friendCode): array {
    try {
        $st = $dbh->prepare(
            "SELECT id, friend_code, name, username, email, image, status, role
             FROM users
             WHERE friend_code = :fc
             LIMIT 1"
        );
        $st->execute([':fc' => $friendCode]);
        $u = $st->fetch(PDO::FETCH_ASSOC);
        if (!$u) return ['ok' => false];

        $label = (string)($u['friend_code'] ?? '');
        $display = (string)($u['name'] ?? '');
        if ($display === '') $display = (string)($u['username'] ?? '');
        if ($display === '') $display = $label;

        return ['ok' => true, 'peer' => $u, 'label' => $label, 'display' => $display];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Guard peer visibility based on roles.
 * Adjust these rules if your app wants different permissions.
 */
function guardPeerByFriendCode(PDO $dbh, string $friendCode, int $myRole): array {
    $r = resolvePeerByFriendCode($dbh, $friendCode);
    if (!$r['ok']) return ['ok' => false];

    $peer = $r['peer'];
    $peerRole = (int)($peer['role'] ?? 0);
    $peerStatus = (int)($peer['status'] ?? 1);

    // Basic safety: don't chat with deactivated users.
    if ($peerStatus !== 1) return ['ok' => false];

    // Example rule: regular users can't message admins unless you want to allow it.
    // Roles in your memory: Admin, Manager, Gospel, Staff (stored as ints).
    // If you want to allow all roles, simply return ok true.
    $allow = true;
    // Uncomment to enforce a stricter rule:
    // if ($myRole > 1 && $peerRole === 1) $allow = false;

    return $allow
        ? ['ok' => true, 'peer' => $peer]
        : ['ok' => false];
}

/**
 * List left-side threads for the logged-in user (by ID).
 * Returns: peer_id, peer_key(friend_code), peer_display, last_message, last_time, unread_count
 */
function listThreadsByIds(PDO $dbh, int $meId): array {
    $sql = "
        SELECT
            u.id AS peer_id,
            u.friend_code AS peer_key,
            COALESCE(NULLIF(u.name,''), NULLIF(u.username,''), u.friend_code) AS peer_display,
            lm.feedbackdata AS last_message,
            lm.created_at   AS last_time,
            COALESCE(ur.unread_count, 0) AS unread_count
        FROM (
            SELECT
                CASE WHEN sender_id = :me THEN receiver_id ELSE sender_id END AS peer_id,
                MAX(created_at) AS last_time
            FROM chat_messages
            WHERE sender_id = :me OR receiver_id = :me
            GROUP BY peer_id
        ) t
        JOIN users u ON u.id = t.peer_id
        JOIN chat_messages lm
            ON lm.id = (
                SELECT cm.id
                FROM chat_messages cm
                WHERE (
                    (cm.sender_id = :me AND cm.receiver_id = t.peer_id)
                    OR
                    (cm.sender_id = t.peer_id AND cm.receiver_id = :me)
                )
                ORDER BY cm.created_at DESC, cm.id DESC
                LIMIT 1
            )
        LEFT JOIN (
            SELECT sender_id AS peer_id, COUNT(*) AS unread_count
            FROM chat_messages
            WHERE receiver_id = :me AND is_read = 0
            GROUP BY sender_id
        ) ur ON ur.peer_id = t.peer_id
        ORDER BY t.last_time DESC
    ";

    try {
        $st = $dbh->prepare($sql);
        $st->execute([':me' => $meId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        // Fail safe: return no threads rather than crashing the page.
        return [];
    }
}

/**
 * Sidebar list: show ALL users (except me), with friend_code visible,
 * and last message if any (history preview).
 */
function listPeersSidebar(PDO $dbh, int $meId): array {
    $sql = "
        SELECT
            u.id AS peer_id,
            u.friend_code AS peer_key,
            COALESCE(NULLIF(u.name,''), NULLIF(u.username,''), u.friend_code) AS peer_display,
            lm.feedbackdata AS last_message,
            lm.created_at AS last_time,
            COALESCE(ur.unread_count, 0) AS unread_count
        FROM users u
        LEFT JOIN chat_messages lm
            ON lm.id = (
                SELECT cm.id
                FROM chat_messages cm
                WHERE (
                    (cm.sender_id = :me AND cm.receiver_id = u.id)
                    OR
                    (cm.sender_id = u.id AND cm.receiver_id = :me)
                )
                ORDER BY cm.created_at DESC, cm.id DESC
                LIMIT 1
            )
        LEFT JOIN (
            SELECT sender_id AS peer_id, COUNT(*) AS unread_count
            FROM chat_messages
            WHERE receiver_id = :me AND is_read = 0
            GROUP BY sender_id
        ) ur ON ur.peer_id = u.id
        WHERE u.id <> :me AND u.status = 1
        ORDER BY (lm.created_at IS NULL) ASC, lm.created_at DESC, u.name ASC
    ";

    try {
        $st = $dbh->prepare($sql);
        $st->execute([':me' => $meId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Mark messages as read when opening a conversation.
 */
function markReadByIds(PDO $dbh, int $meId, int $peerId): void {
    try {
        $st = $dbh->prepare(
            "UPDATE chat_messages
             SET is_read = 1
             WHERE receiver_id = :me AND sender_id = :peer AND is_read = 0"
        );
        $st->execute([':me' => $meId, ':peer' => $peerId]);
    } catch (Throwable $e) {
        // ignore
    }
}

/**
 * Send a message.
 */
function sendMessageByIds(PDO $dbh, int $meId, int $peerId, string $text, ?string $attachmentName = null): bool {
    try {
        $st = $dbh->prepare(
            "INSERT INTO chat_messages (sender_id, receiver_id, feedbackdata, attachment, is_read, created_at)
             VALUES (:s, :r, :t, :a, 0, NOW())"
        );
        $st->execute([
            ':s' => $meId,
            ':r' => $peerId,
            ':t' => $text,
            ':a' => $attachmentName,
        ]);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Load conversation between two users.
 */
function loadConversationByIds(PDO $dbh, int $meId, int $peerId, int $limit = 100): array {
    $limit = max(10, min(500, $limit));
    try {
        $st = $dbh->prepare(
            "SELECT id, sender_id, receiver_id, feedbackdata, attachment, is_read, created_at
             FROM chat_messages
             WHERE (sender_id = :me AND receiver_id = :peer)
                OR (sender_id = :peer AND receiver_id = :me)
             ORDER BY created_at ASC, id ASC
             LIMIT {$limit}"
        );
        $st->execute([':me' => $meId, ':peer' => $peerId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function listAllChatUsers(PDO $dbh, int $meId): array {
    try {
        $st = $dbh->prepare(
            "SELECT
                id AS peer_id,
                friend_code AS peer_key,
                COALESCE(NULLIF(name,''), NULLIF(username,''), friend_code) AS peer_display,
                '' AS last_message,
                '' AS last_time,
                0  AS unread_count
             FROM users
             WHERE status = 1 AND id <> :me
             ORDER BY peer_display ASC"
        );
        $st->execute([':me' => $meId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}


