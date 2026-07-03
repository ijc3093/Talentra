<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/session_user.php';
requireUserLogin();
require_once __DIR__ . '/../controller.php';
require_once __DIR__ . '/../includes/friend_system.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

const ROOM_MAX_ACTIVE_GUESTS = 29;
const ROOM_VIEWER_TIMEOUT_SECONDS = 20;

function room_json(array $payload): void
{
    echo json_encode($payload);
    exit;
}

function room_table_exists(PDO $dbh, string $table): bool
{
    try {
        $st = $dbh->prepare("
            SELECT 1
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
            LIMIT 1
        ");
        $st->execute([$table]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function room_ensure_comments_table(PDO $dbh): bool
{
    if (room_table_exists($dbh, 'user_video_live_comments')) {
        return true;
    }
    try {
        $dbh->exec("
            CREATE TABLE IF NOT EXISTS user_video_live_comments (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                live_id BIGINT UNSIGNED NOT NULL,
                user_id INT NOT NULL,
                body TEXT NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_live_created (live_id, created_at),
                KEY idx_user_created (user_id, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function room_ensure_reactions_table(PDO $dbh): bool
{
    if (room_table_exists($dbh, 'user_video_live_reactions')) {
        try {
            $stIdx = $dbh->prepare("
                SELECT INDEX_NAME
                FROM information_schema.STATISTICS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = 'user_video_live_reactions'
                  AND INDEX_NAME = 'uq_live_user'
                LIMIT 1
            ");
            $stIdx->execute();
            if ($stIdx->fetchColumn()) {
                $dbh->exec("ALTER TABLE user_video_live_reactions DROP INDEX uq_live_user");
            }
        } catch (Throwable $e) {
            // keep reaction storage resilient
        }
        try {
            $dbh->exec("ALTER TABLE user_video_live_reactions ADD INDEX idx_live_user_created (live_id, user_id, created_at)");
        } catch (Throwable $e) {
            // index may already exist
        }
        return true;
    }
    try {
        $dbh->exec("
            CREATE TABLE IF NOT EXISTS user_video_live_reactions (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                live_id BIGINT UNSIGNED NOT NULL,
                user_id INT NOT NULL,
                reaction VARCHAR(20) NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_live_reaction (live_id, reaction),
                KEY idx_live_user_created (live_id, user_id, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function room_ensure_comment_likes_table(PDO $dbh): bool
{
    if (room_table_exists($dbh, 'user_video_live_comment_likes')) {
        return true;
    }
    try {
        $dbh->exec("
            CREATE TABLE IF NOT EXISTS user_video_live_comment_likes (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                comment_id BIGINT UNSIGNED NOT NULL,
                user_id INT NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_comment_user (comment_id, user_id),
                KEY idx_comment (comment_id),
                KEY idx_user (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function room_ensure_guest_request_table(PDO $dbh): bool
{
    if (room_table_exists($dbh, 'user_video_live_guest_requests')) {
        return true;
    }
    try {
        $dbh->exec("
            CREATE TABLE IF NOT EXISTS user_video_live_guest_requests (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                live_id BIGINT UNSIGNED NOT NULL,
                requester_user_id INT NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'requested',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_live_requester (live_id, requester_user_id),
                KEY idx_live_status (live_id, status),
                KEY idx_requester_status (requester_user_id, status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function room_ensure_viewers_table(PDO $dbh): bool
{
    if (room_table_exists($dbh, 'user_video_live_viewers')) {
        return true;
    }
    try {
        $dbh->exec("
            CREATE TABLE IF NOT EXISTS user_video_live_viewers (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                live_id BIGINT UNSIGNED NOT NULL,
                viewer_user_id INT NOT NULL,
                joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_live_viewer (live_id, viewer_user_id),
                KEY idx_live_last_seen (live_id, last_seen_at),
                KEY idx_viewer_last_seen (viewer_user_id, last_seen_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function room_camera_state_dir(): string
{
    return __DIR__ . '/../storage/live_snapshots';
}

function room_host_camera_state_path(int $liveId): string
{
    return room_camera_state_dir() . '/' . $liveId . '.camera.json';
}

function room_guest_camera_state_path(int $liveId, int $userId): string
{
    return room_camera_state_dir() . '/' . $liveId . '_guest_' . $userId . '.camera.json';
}

function room_read_camera_enabled(string $path, bool $default = true): bool
{
    if (!is_file($path)) {
        return $default;
    }
    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') {
        return $default;
    }
    $data = json_decode($raw, true);
    if (!is_array($data) || !array_key_exists('enabled', $data)) {
        return $default;
    }
    return (bool)$data['enabled'];
}

function room_write_camera_enabled(string $path, bool $enabled): bool
{
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
        return false;
    }
    $payload = json_encode([
        'enabled' => $enabled,
        'updated_at' => gmdate('c'),
    ]);
    return $payload !== false && @file_put_contents($path, $payload, LOCK_EX) !== false;
}

function room_sync_viewer_counts(PDO $dbh, int $liveId): int
{
    if ($liveId <= 0 || !room_ensure_viewers_table($dbh)) {
        return 0;
    }

    try {
        $cutoff = max(5, ROOM_VIEWER_TIMEOUT_SECONDS);
        $stCleanup = $dbh->prepare("
            DELETE FROM user_video_live_viewers
            WHERE live_id = :live_id
              AND last_seen_at < (NOW() - INTERVAL {$cutoff} SECOND)
        ");
        $stCleanup->execute([':live_id' => $liveId]);
    } catch (Throwable $e) {
        // keep host polling resilient
    }

    $viewerCount = 0;
    try {
        $stCount = $dbh->prepare("
            SELECT COUNT(*)
            FROM user_video_live_viewers
            WHERE live_id = :live_id
        ");
        $stCount->execute([':live_id' => $liveId]);
        $viewerCount = (int)($stCount->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        $viewerCount = 0;
    }

    try {
        $stUpdate = $dbh->prepare("
            UPDATE user_video_lives
            SET viewer_count = :viewer_count,
                updated_at = NOW()
            WHERE id = :live_id
            LIMIT 1
        ");
        $stUpdate->execute([
            ':viewer_count' => $viewerCount,
            ':live_id' => $liveId,
        ]);
    } catch (Throwable $e) {
        // keep host polling resilient
    }

    return $viewerCount;
}

function room_fetch_current_live(PDO $dbh, int $meId): ?array
{
    try {
        $st = $dbh->prepare("
            SELECT id, status, title, viewer_count, share_count
            FROM user_video_lives
            WHERE user_id = :uid
              AND status IN ('draft','scheduled','live')
            ORDER BY FIELD(status, 'live', 'scheduled', 'draft'), COALESCE(started_at, scheduled_for, updated_at, created_at) DESC, id DESC
            LIMIT 1
        ");
        $st->execute([':uid' => $meId]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($row) {
            $row['viewer_count'] = room_sync_viewer_counts($dbh, (int)($row['id'] ?? 0));
        }
        return $row;
    } catch (Throwable $e) {
        return null;
    }
}

function room_like_label(array $names, int $count): string
{
    $clean = [];
    foreach ($names as $name) {
        $value = trim((string)$name);
        if ($value !== '') {
            $clean[] = $value;
        }
    }
    $clean = array_values(array_unique($clean));
    if ($count <= 0 || !$clean) {
        return '';
    }
    $visible = array_slice($clean, 0, 3);
    $remaining = max(0, $count - count($visible));
    $label = 'Liked by ' . implode(', ', $visible);
    if ($remaining === 1) {
        $label .= ' and 1 other';
    } elseif ($remaining > 1) {
        $label .= ' and ' . $remaining . ' others';
    }
    return $label;
}

function room_reactor_status(PDO $dbh, int $meId, int $reactorId): string
{
    if ($reactorId <= 0) {
        return 'none';
    }
    if ($reactorId === $meId) {
        return 'self';
    }
    return fs_friend_status($dbh, $meId, $reactorId);
}

function room_fetch_payload(PDO $dbh, int $meId): array
{
    $live = room_fetch_current_live($dbh, $meId);
    $reactionCounts = ['love' => 0, 'like' => 0, 'fire' => 0, 'wow' => 0, 'clap' => 0];
    $myReaction = '';
    $comments = [];
    $commentTotal = 0;
    $reactionUsers = [];
    $guestRequests = [];
    $approvedGuests = [];

    $snapshotVersion = 0;

    if ($live && room_ensure_comments_table($dbh) && room_ensure_reactions_table($dbh)) {
        $liveId = (int)($live['id'] ?? 0);
        $snapshotPath = __DIR__ . '/../storage/live_snapshots/' . $liveId . '.jpg';
        if (is_file($snapshotPath)) {
            $snapshotVersion = (int)(filemtime($snapshotPath) ?: 0);
        }
        try {
            $stCommentTotal = $dbh->prepare("
                SELECT COUNT(*)
                FROM user_video_live_comments
                WHERE live_id = :live_id
            ");
            $stCommentTotal->execute([':live_id' => $liveId]);
            $commentTotal = (int)($stCommentTotal->fetchColumn() ?: 0);
        } catch (Throwable $e) {
            $commentTotal = 0;
        }

        try {
            $likedCommentIds = [];
            $likeCounts = [];
            $likeNames = [];
            if (room_ensure_comment_likes_table($dbh)) {
                try {
                    $stLikeCounts = $dbh->prepare("
                        SELECT l.comment_id, COUNT(*) AS total
                        FROM user_video_live_comment_likes l
                        JOIN user_video_live_comments c ON c.id = l.comment_id
                        WHERE c.live_id = :live_id
                        GROUP BY l.comment_id
                    ");
                    $stLikeCounts->execute([':live_id' => $liveId]);
                    foreach (($stLikeCounts->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
                        $likeCounts[(int)($row['comment_id'] ?? 0)] = (int)($row['total'] ?? 0);
                    }
                } catch (Throwable $e) {
                    $likeCounts = [];
                }

                try {
                    $stLikeNames = $dbh->prepare("
                        SELECT l.comment_id, u.name, u.username
                        FROM user_video_live_comment_likes l
                        JOIN user_video_live_comments c ON c.id = l.comment_id
                        JOIN users u ON u.id = l.user_id
                        WHERE c.live_id = :live_id
                        ORDER BY l.created_at ASC, l.id ASC
                    ");
                    $stLikeNames->execute([':live_id' => $liveId]);
                    foreach (($stLikeNames->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
                        $commentId = (int)($row['comment_id'] ?? 0);
                        if ($commentId <= 0) {
                            continue;
                        }
                        $name = trim((string)($row['name'] ?: $row['username'] ?: 'User'));
                        if ($name !== '') {
                            $likeNames[$commentId][] = $name;
                        }
                    }
                } catch (Throwable $e) {
                    $likeNames = [];
                }

                try {
                    $stLiked = $dbh->prepare("
                        SELECT l.comment_id
                        FROM user_video_live_comment_likes l
                        JOIN user_video_live_comments c ON c.id = l.comment_id
                        WHERE c.live_id = :live_id
                          AND l.user_id = :user_id
                    ");
                    $stLiked->execute([
                        ':live_id' => $liveId,
                        ':user_id' => $meId,
                    ]);
                    foreach (($stLiked->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
                        $likedCommentIds[(int)($row['comment_id'] ?? 0)] = true;
                    }
                } catch (Throwable $e) {
                    $likedCommentIds = [];
                }
            }

            $stComments = $dbh->prepare("
                SELECT c.id, c.user_id, c.body, c.created_at, u.name, u.username
                FROM user_video_live_comments c
                JOIN users u ON u.id = c.user_id
                WHERE c.live_id = :live_id
                ORDER BY c.id DESC
                LIMIT 20
            ");
            $stComments->execute([':live_id' => $liveId]);
            $rows = $stComments->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $rows = array_reverse($rows);
            foreach ($rows as $row) {
                $comments[] = [
                    'id' => (int)($row['id'] ?? 0),
                    'user_id' => (int)($row['user_id'] ?? 0),
                    'body' => (string)($row['body'] ?? ''),
                    'author' => trim((string)($row['name'] ?: $row['username'] ?: 'User')),
                    'created_at' => (string)($row['created_at'] ?? ''),
                    'like_count' => (int)($likeCounts[(int)($row['id'] ?? 0)] ?? 0),
                    'liked_by_me' => !empty($likedCommentIds[(int)($row['id'] ?? 0)]),
                    'liked_by' => array_values(array_unique($likeNames[(int)($row['id'] ?? 0)] ?? [])),
                    'liked_by_label' => room_like_label(
                        array_values(array_unique($likeNames[(int)($row['id'] ?? 0)] ?? [])),
                        (int)($likeCounts[(int)($row['id'] ?? 0)] ?? 0)
                    ),
                ];
            }
        } catch (Throwable $e) {
            $comments = [];
        }

        try {
            $stReactions = $dbh->prepare("
                SELECT reaction, COUNT(*) AS total
                FROM user_video_live_reactions
                WHERE live_id = :live_id
                GROUP BY reaction
            ");
            $stReactions->execute([':live_id' => $liveId]);
            foreach (($stReactions->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
                $reaction = (string)($row['reaction'] ?? '');
                if (array_key_exists($reaction, $reactionCounts)) {
                    $reactionCounts[$reaction] = (int)($row['total'] ?? 0);
                }
            }
        } catch (Throwable $e) {
            $reactionCounts = ['love' => 0, 'like' => 0, 'fire' => 0, 'wow' => 0, 'clap' => 0];
        }

        try {
            $stMine = $dbh->prepare("
                SELECT reaction
                FROM user_video_live_reactions
                WHERE live_id = :live_id
                  AND user_id = :user_id
                ORDER BY created_at DESC, id DESC
                LIMIT 1
            ");
            $stMine->execute([
                ':live_id' => $liveId,
                ':user_id' => $meId,
            ]);
            $myReaction = (string)($stMine->fetchColumn() ?: '');
        } catch (Throwable $e) {
            $myReaction = '';
        }

        try {
            $stReactionUsers = $dbh->prepare("
                SELECT r.user_id, r.reaction, r.created_at, u.name, u.username
                FROM user_video_live_reactions r
                JOIN users u ON u.id = r.user_id
                WHERE r.live_id = :live_id
                ORDER BY r.created_at DESC, r.id DESC
            ");
            $stReactionUsers->execute([':live_id' => $liveId]);
            foreach (($stReactionUsers->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
                $reactorId = (int)($row['user_id'] ?? 0);
                $reactionUsers[] = [
                    'user_id' => $reactorId,
                    'name' => trim((string)($row['name'] ?: $row['username'] ?: 'User')),
                    'reaction' => (string)($row['reaction'] ?? ''),
                    'created_at_label' => (string)($row['created_at'] ?? ''),
                    'friend_status' => room_reactor_status($dbh, $meId, $reactorId),
                ];
            }
        } catch (Throwable $e) {
            $reactionUsers = [];
        }

        if (room_ensure_guest_request_table($dbh)) {
            try {
                $stGuests = $dbh->prepare("
                    SELECT r.requester_user_id, r.status, r.created_at, u.name, u.username
                    FROM user_video_live_guest_requests r
                    JOIN users u ON u.id = r.requester_user_id
                    WHERE r.live_id = :live_id
                    ORDER BY r.updated_at ASC, r.id ASC
                ");
                $stGuests->execute([':live_id' => $liveId]);
                foreach (($stGuests->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
                    $entry = [
                        'user_id' => (int)($row['requester_user_id'] ?? 0),
                        'name' => trim((string)($row['name'] ?: $row['username'] ?: 'User')),
                        'status' => (string)($row['status'] ?? 'requested'),
                        'created_at' => (string)($row['created_at'] ?? ''),
                    ];
                    if ($entry['status'] === 'approved') {
                        if (count($approvedGuests) < ROOM_MAX_ACTIVE_GUESTS) {
                            $guestSnapshotPath = __DIR__ . '/../storage/live_snapshots/' . $liveId . '_guest_' . (int)$entry['user_id'] . '.jpg';
                            $entry['snapshot_version'] = is_file($guestSnapshotPath) ? (string)(@md5_file($guestSnapshotPath) ?: '') : '';
                            $entry['camera_enabled'] = room_read_camera_enabled(room_guest_camera_state_path($liveId, (int)$entry['user_id']), true);
                            $approvedGuests[] = $entry;
                        }
                    } elseif ($entry['status'] === 'requested') {
                        $guestRequests[] = $entry;
                    }
                }
            } catch (Throwable $e) {
                $guestRequests = [];
                $approvedGuests = [];
            }
        }
    }

    return [
        'live' => $live ? [
            'id' => (int)($live['id'] ?? 0),
            'status' => (string)($live['status'] ?? 'draft'),
            'title' => (string)($live['title'] ?? ''),
            'viewer_count' => (int)($live['viewer_count'] ?? 0),
            'share_count' => (int)($live['share_count'] ?? 0),
            'snapshot_version' => $snapshotVersion,
            'camera_enabled' => room_read_camera_enabled(room_host_camera_state_path((int)($live['id'] ?? 0)), true),
        ] : null,
        'comments' => $comments,
        'comment_total' => $commentTotal,
        'reaction_counts' => $reactionCounts,
        'reaction_users' => $reactionUsers,
        'my_reaction' => $myReaction,
        'guest_requests' => $guestRequests,
        'approved_guests' => $approvedGuests,
    ];
}

$controller = new Controller();
$dbh = $controller->pdo();
$meId = (int)($_SESSION['user_id'] ?? 0);
if ($meId <= 0) {
    room_json(['ok' => false, 'error' => 'Invalid session']);
}
if (!room_table_exists($dbh, 'user_video_lives')) {
    room_json(['ok' => false, 'error' => 'Live storage unavailable']);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    room_json(['ok' => true] + room_fetch_payload($dbh, $meId));
}

$action = trim((string)($_POST['action'] ?? ''));
$currentLive = room_fetch_current_live($dbh, $meId);
$liveId = (int)($currentLive['id'] ?? 0);

if ($liveId <= 0) {
    room_json(['ok' => false, 'error' => 'No current live room']);
}

if ($action === 'send_comment') {
    if (!room_ensure_comments_table($dbh)) {
        room_json(['ok' => false, 'error' => 'Comment storage unavailable']);
    }
    $body = trim((string)($_POST['comment_body'] ?? ''));
    if ($body === '') {
        room_json(['ok' => false, 'error' => 'Type a message before sending']);
    }
    try {
        $st = $dbh->prepare("
            INSERT INTO user_video_live_comments (live_id, user_id, body, created_at, updated_at)
            VALUES (:live_id, :user_id, :body, NOW(), NOW())
        ");
        $st->execute([
            ':live_id' => $liveId,
            ':user_id' => $meId,
            ':body' => mb_substr($body, 0, 1000),
        ]);
    } catch (Throwable $e) {
        room_json(['ok' => false, 'error' => 'Unable to send comment']);
    }
} elseif ($action === 'react_live') {
    if (!room_ensure_reactions_table($dbh)) {
        room_json(['ok' => false, 'error' => 'Reaction storage unavailable']);
    }
    $reaction = trim((string)($_POST['reaction'] ?? ''));
    $allowed = ['love', 'like', 'fire', 'wow', 'clap'];
    if (!in_array($reaction, $allowed, true)) {
        room_json(['ok' => false, 'error' => 'Invalid reaction']);
    }
    try {
        $st = $dbh->prepare("
            INSERT INTO user_video_live_reactions (live_id, user_id, reaction, created_at)
            VALUES (:live_id, :user_id, :reaction, NOW())
        ");
        $st->execute([
            ':live_id' => $liveId,
            ':user_id' => $meId,
            ':reaction' => $reaction,
        ]);
    } catch (Throwable $e) {
        room_json(['ok' => false, 'error' => 'Unable to save reaction']);
    }
} elseif ($action === 'share_live') {
    try {
        $st = $dbh->prepare("
            UPDATE user_video_lives
            SET share_count = share_count + 1,
                updated_at = NOW()
            WHERE id = :live_id
            LIMIT 1
        ");
        $st->execute([':live_id' => $liveId]);
    } catch (Throwable $e) {
        room_json(['ok' => false, 'error' => 'Unable to save share']);
    }
} elseif ($action === 'toggle_comment_like') {
    if (!room_ensure_comment_likes_table($dbh) || !room_ensure_comments_table($dbh)) {
        room_json(['ok' => false, 'error' => 'Comment likes unavailable']);
    }
    $commentId = (int)($_POST['comment_id'] ?? 0);
    if ($commentId <= 0) {
        room_json(['ok' => false, 'error' => 'Invalid comment']);
    }
    try {
        $stComment = $dbh->prepare("
            SELECT id
            FROM user_video_live_comments
            WHERE id = :comment_id
              AND live_id = :live_id
            LIMIT 1
        ");
        $stComment->execute([
            ':comment_id' => $commentId,
            ':live_id' => $liveId,
        ]);
        if (!$stComment->fetch(PDO::FETCH_ASSOC)) {
            room_json(['ok' => false, 'error' => 'Comment not found']);
        }

        $stExisting = $dbh->prepare("
            SELECT id
            FROM user_video_live_comment_likes
            WHERE comment_id = :comment_id
              AND user_id = :user_id
            LIMIT 1
        ");
        $stExisting->execute([
            ':comment_id' => $commentId,
            ':user_id' => $meId,
        ]);
        $existingId = (int)($stExisting->fetchColumn() ?: 0);

        if ($existingId > 0) {
            $stDelete = $dbh->prepare("DELETE FROM user_video_live_comment_likes WHERE id = :id LIMIT 1");
            $stDelete->execute([':id' => $existingId]);
        } else {
            $stInsert = $dbh->prepare("
                INSERT INTO user_video_live_comment_likes (comment_id, user_id, created_at)
                VALUES (:comment_id, :user_id, NOW())
            ");
            $stInsert->execute([
                ':comment_id' => $commentId,
                ':user_id' => $meId,
            ]);
        }
    } catch (Throwable $e) {
        room_json(['ok' => false, 'error' => 'Unable to update comment like']);
    }
} elseif ($action === 'confirm_guest_request' || $action === 'deny_guest_request') {
    if (!room_ensure_guest_request_table($dbh)) {
        room_json(['ok' => false, 'error' => 'Guest request storage unavailable']);
    }
    $requestUserId = (int)($_POST['request_user_id'] ?? 0);
    if ($requestUserId <= 0) {
        room_json(['ok' => false, 'error' => 'Invalid guest request']);
    }
    $nextStatus = $action === 'confirm_guest_request' ? 'approved' : 'denied';
    if ($nextStatus === 'approved') {
        try {
            $stCount = $dbh->prepare("
                SELECT COUNT(*)
                FROM user_video_live_guest_requests
                WHERE live_id = :live_id
                  AND status = 'approved'
            ");
            $stCount->execute([':live_id' => $liveId]);
            $approvedCount = (int)($stCount->fetchColumn() ?: 0);
            if ($approvedCount >= ROOM_MAX_ACTIVE_GUESTS) {
                room_json(['ok' => false, 'error' => 'Live guest limit reached (29 friends maximum).']);
            }
        } catch (Throwable $e) {
            room_json(['ok' => false, 'error' => 'Unable to verify guest limit']);
        }
    }
    try {
        $st = $dbh->prepare("
            UPDATE user_video_live_guest_requests
            SET status = :status,
                updated_at = NOW()
            WHERE live_id = :live_id
              AND requester_user_id = :user_id
            LIMIT 1
        ");
        $st->execute([
            ':status' => $nextStatus,
            ':live_id' => $liveId,
            ':user_id' => $requestUserId,
        ]);
    } catch (Throwable $e) {
        room_json(['ok' => false, 'error' => 'Unable to update guest request']);
    }
} elseif ($action === 'set_camera_enabled') {
    $enabled = filter_var($_POST['enabled'] ?? '1', FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    try {
        room_write_camera_enabled(room_host_camera_state_path($liveId), $enabled !== false);
    } catch (Throwable $e) {
        room_json(['ok' => false, 'error' => 'Unable to update host camera state']);
    }
} else {
    room_json(['ok' => false, 'error' => 'Unknown action']);
}

room_json(['ok' => true] + room_fetch_payload($dbh, $meId));
