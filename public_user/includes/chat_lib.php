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

/** Plural-safe relative age for presence / last-seen ("1 day ago", not "1 days ago"). */
if (!function_exists('chat_seconds_ago_label')) {
    function chat_seconds_ago_label(int $sec): string {
        if ($sec < 0) {
            $sec = 0;
        }
        if ($sec < 10) {
            return 'just now';
        }
        if ($sec < 60) {
            return $sec . ' second' . ($sec === 1 ? '' : 's') . ' ago';
        }
        $m = (int)floor($sec / 60);
        if ($m < 60) {
            return $m . ' minute' . ($m === 1 ? '' : 's') . ' ago';
        }
        $h = (int)floor($sec / 3600);
        if ($h < 24) {
            return $h . ' hour' . ($h === 1 ? '' : 's') . ' ago';
        }
        $d = (int)floor($sec / 86400);
        if ($d < 7) {
            return $d . ' day' . ($d === 1 ? '' : 's') . ' ago';
        }
        $w = (int)floor($d / 7);
        if ($w < 5) {
            return $w . ' week' . ($w === 1 ? '' : 's') . ' ago';
        }
        $mo = (int)floor($d / 30);
        if ($mo < 12) {
            return $mo . ' month' . ($mo === 1 ? '' : 's') . ' ago';
        }
        $y = (int)floor($d / 365);
        return max(1, $y) . ' year' . ($y === 1 ? '' : 's') . ' ago';
    }
}

/** Message bubble timestamp: "Sep 6, 2026 7:39 PM" */
if (!function_exists('chat_fmt_time_full')) {
    function chat_fmt_time_full(string $dt): string {
        $dt = trim($dt);
        if ($dt === '') {
            return '';
        }
        $ts = strtotime($dt);
        return $ts ? date('M j, Y g:i A', $ts) : '';
    }
}

/** Compact clock time: "7:39 PM" */
if (!function_exists('chat_fmt_time_short')) {
    function chat_fmt_time_short(string $dt): string {
        $dt = trim($dt);
        if ($dt === '') {
            return '';
        }
        $ts = strtotime($dt);
        return $ts ? date('g:i A', $ts) : '';
    }
}

/** Thread list relative stamp: "5h", "2w" */
if (!function_exists('chat_fmt_thread_time')) {
    function chat_fmt_thread_time(string $dt): string {
        $dt = trim($dt);
        if ($dt === '') {
            return '';
        }
        $ts = strtotime($dt);
        if (!$ts) {
            return '';
        }
        $diff = max(0, time() - $ts);
        if ($diff < 60) {
            return 'now';
        }
        if ($diff < 3600) {
            return (string)max(1, (int)floor($diff / 60)) . 'm';
        }
        if ($diff < 86400) {
            return (string)max(1, (int)floor($diff / 3600)) . 'h';
        }
        if ($diff < 604800) {
            return (string)max(1, (int)floor($diff / 86400)) . 'd';
        }
        if ($diff < 2592000) {
            return (string)max(1, (int)floor($diff / 604800)) . 'w';
        }
        if ($diff < 31536000) {
            return (string)max(1, (int)floor($diff / 2592000)) . 'mo';
        }
        return (string)max(1, (int)floor($diff / 31536000)) . 'y';
    }
}

if (!function_exists('call_event_possessive_name')) {
    function call_event_possessive_name(string $name): string {
        $clean = trim($name);
        if ($clean === '') return 'their';
        return preg_match('/s$/i', $clean) ? $clean . "'" : $clean . "'s";
    }
}

/**
 * Turn [[MSB_CALL_EVENT:{...}]] markers into human-readable previews.
 * Tolerates truncated markers (e.g. inbox/door previews).
 */
if (!function_exists('call_event_display_text')) {
    function call_event_display_text(string $text, bool $isMe, bool $isGroup = false): string {
        $trimmed = trim($text);
        $prefix = '[[MSB_CALL_EVENT:';
        if ($trimmed === '' || strncmp($trimmed, $prefix, strlen($prefix)) !== 0) {
            return $text;
        }

        $json = (substr($trimmed, -2) === ']]')
            ? substr($trimmed, strlen($prefix), -2)
            : substr($trimmed, strlen($prefix));
        $payload = json_decode((string)$json, true);
        if (!is_array($payload)) {
            if (preg_match('/"action"\s*:\s*"(end|ended|deny|denied|decline|declined|miss|missed|unavailable)"/i', $trimmed, $m)) {
                $actor = '';
                if (preg_match('/"actor"\s*:\s*"((?:\\\\.|[^"\\\\])*)"/', $trimmed, $am)) {
                    $actor = stripcslashes((string)$am[1]);
                } elseif (preg_match('/"actor"\s*:\s*"([^"]*)/', $trimmed, $am)) {
                    // Truncated preview (no closing quote)
                    $actor = rtrim((string)$am[1], ".\xE2\x80\xA6");
                    $actor = trim($actor);
                }
                $payload = [
                    'action' => strtolower((string)$m[1]),
                    'actor' => $actor,
                    'target' => '',
                ];
            } else {
                return 'Call update';
            }
        }

        $action = strtolower(trim((string)($payload['action'] ?? '')));
        $actor = trim((string)($payload['actor'] ?? ''));
        $target = call_event_possessive_name((string)($payload['target'] ?? ''));
        if ($actor === '') $actor = 'They';

        if ($isGroup) {
            if (in_array($action, ['deny', 'denied', 'decline', 'declined'], true)) {
                return $actor . ' declined the group call';
            }
            if (in_array($action, ['miss', 'missed', 'unavailable'], true)) {
                return $actor . ' missed the group call';
            }
            if (in_array($action, ['end', 'ended'], true)) {
                return $actor . ' ended the group call';
            }
            return 'Group call update';
        }

        if (in_array($action, ['deny', 'denied', 'decline', 'declined'], true)) {
            return $isMe ? ('You denied ' . $target . ' call') : ($actor . ' denied your call');
        }
        if (in_array($action, ['miss', 'missed', 'unavailable'], true)) {
            return $isMe ? ('You missed ' . $target . ' call') : ($actor . ' is not avalible yet. Please call me later');
        }
        if (in_array($action, ['end', 'ended'], true)) {
            return $isMe ? ('You ended ' . $target . ' call') : ($actor . ' ended your call');
        }

        return 'Call update';
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


