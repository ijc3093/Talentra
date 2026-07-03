<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/session_user.php';
requireUserLogin();
require_once __DIR__ . '/../controller.php';
require_once __DIR__ . '/../includes/friend_system.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

const WATCH_MAX_ACTIVE_GUESTS = 29;
const WATCH_VIEWER_TIMEOUT_SECONDS = 20;

function watch_json(array $payload): void
{
    echo json_encode($payload);
    exit;
}

function watch_table_exists(PDO $dbh, string $table): bool
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

function watch_fmt(?string $raw): string
{
    $raw = trim((string)$raw);
    if ($raw === '') {
        return '';
    }
    $ts = strtotime($raw);
    return $ts ? date('M d, Y h:i A', $ts) : $raw;
}

function watch_camera_state_dir(): string
{
    return __DIR__ . '/../storage/live_snapshots';
}

function watch_host_camera_state_path(int $liveId): string
{
    return watch_camera_state_dir() . '/' . $liveId . '.camera.json';
}

function watch_guest_camera_state_path(int $liveId, int $userId): string
{
    return watch_camera_state_dir() . '/' . $liveId . '_guest_' . $userId . '.camera.json';
}

function watch_read_camera_enabled(string $path, bool $default = true): bool
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

function watch_write_camera_enabled(string $path, bool $enabled): bool
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

function watch_like_label(array $names, int $count): string
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

function watch_reactor_status(PDO $dbh, int $meId, int $reactorId): string
{
    if ($reactorId <= 0) {
        return 'none';
    }
    if ($reactorId === $meId) {
        return 'self';
    }
    return fs_friend_status($dbh, $meId, $reactorId);
}

function watch_ensure_viewers_table(PDO $dbh): bool
{
    if (watch_table_exists($dbh, 'user_video_live_viewers')) {
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

function watch_ensure_comment_likes_table(PDO $dbh): bool
{
    if (watch_table_exists($dbh, 'user_video_live_comment_likes')) {
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

function watch_ensure_reactions_table(PDO $dbh): bool
{
    if (watch_table_exists($dbh, 'user_video_live_reactions')) {
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

function watch_sync_viewer_presence(PDO $dbh, int $liveId, int $meId, bool $isHost, bool $markActive): void
{
    if ($liveId <= 0 || $meId <= 0 || $isHost || !watch_ensure_viewers_table($dbh)) {
        return;
    }

    try {
        $cutoff = max(5, WATCH_VIEWER_TIMEOUT_SECONDS);
        $stCleanup = $dbh->prepare("
            DELETE FROM user_video_live_viewers
            WHERE live_id = :live_id
              AND last_seen_at < (NOW() - INTERVAL {$cutoff} SECOND)
        ");
        $stCleanup->execute([':live_id' => $liveId]);
    } catch (Throwable $e) {
        // keep viewer tracking resilient
    }

    try {
        if ($markActive) {
            $stTouch = $dbh->prepare("
                INSERT INTO user_video_live_viewers (live_id, viewer_user_id, joined_at, last_seen_at)
                VALUES (:live_id, :viewer_user_id, NOW(), NOW())
                ON DUPLICATE KEY UPDATE last_seen_at = NOW()
            ");
            $stTouch->execute([
                ':live_id' => $liveId,
                ':viewer_user_id' => $meId,
            ]);
        } else {
            $stLeave = $dbh->prepare("
                DELETE FROM user_video_live_viewers
                WHERE live_id = :live_id
                  AND viewer_user_id = :viewer_user_id
                LIMIT 1
            ");
            $stLeave->execute([
                ':live_id' => $liveId,
                ':viewer_user_id' => $meId,
            ]);
        }
    } catch (Throwable $e) {
        // keep viewer tracking resilient
    }

    try {
        $stCount = $dbh->prepare("
            SELECT COUNT(*)
            FROM user_video_live_viewers
            WHERE live_id = :live_id
        ");
        $stCount->execute([':live_id' => $liveId]);
        $viewerCount = (int)($stCount->fetchColumn() ?: 0);

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
        // keep viewer tracking resilient
    }
}

function watch_load_live(PDO $dbh, int $liveId, int $meId): ?array
{
    if ($liveId <= 0 || !watch_table_exists($dbh, 'user_video_lives')) {
        return null;
    }

    try {
        $st = $dbh->prepare("
            SELECT l.*, u.name, u.username
            FROM user_video_lives l
            JOIN users u ON u.id = l.user_id
            WHERE l.id = :id
            LIMIT 1
        ");
        $st->execute([':id' => $liveId]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$row) {
            return null;
        }

        $ownerId = (int)($row['user_id'] ?? 0);
        $visibility = (string)($row['visibility'] ?? 'private');
        $canView = ($ownerId === $meId)
            || $visibility === 'public'
            || ($visibility === 'friends' && fs_are_friends($dbh, $meId, $ownerId));

        $row['can_view'] = $canView;
        $row['owner_name'] = trim((string)($row['name'] ?: $row['username'] ?: 'Host'));
        $row['started_at_label'] = watch_fmt((string)($row['started_at'] ?? $row['scheduled_for'] ?? ''));
        return $row;
    } catch (Throwable $e) {
        return null;
    }
}

function watch_payload(PDO $dbh, int $liveId, int $meId, bool $markViewerActive = true): array
{
    $live = watch_load_live($dbh, $liveId, $meId);
    $payload = [
        'live' => null,
        'comments' => [],
        'comment_total' => 0,
        'reaction_counts' => ['love' => 0, 'like' => 0, 'fire' => 0, 'wow' => 0, 'clap' => 0],
        'reaction_users' => [],
        'my_reaction' => '',
        'can_view' => false,
        'join_request_status' => '',
        'can_request_join' => false,
        'approved_guests' => [],
    ];

    if (!$live) {
        return $payload;
    }

    $ownerId = (int)($live['user_id'] ?? 0);
    $payload['can_view'] = (bool)($live['can_view'] ?? false);
    if (!$payload['can_view']) {
        return $payload;
    }

    if ($markViewerActive) {
        watch_sync_viewer_presence($dbh, $liveId, $meId, $ownerId === $meId, true);
        $live = watch_load_live($dbh, $liveId, $meId);
        if (!$live) {
            return $payload;
        }
    }
    $payload['live'] = [
        'id' => (int)($live['id'] ?? 0),
        'owner_id' => (int)($live['user_id'] ?? 0),
        'title' => (string)($live['title'] ?? ''),
        'description' => (string)($live['description'] ?? ''),
        'status' => (string)($live['status'] ?? ''),
        'visibility' => (string)($live['visibility'] ?? 'private'),
        'viewer_count' => (int)($live['viewer_count'] ?? 0),
        'share_count' => (int)($live['share_count'] ?? 0),
        'owner_name' => (string)($live['owner_name'] ?? 'Host'),
        'started_at_label' => (string)($live['started_at_label'] ?? ''),
        'stream_key' => (string)($live['stream_key'] ?? ''),
        'snapshot_version' => '',
        'camera_enabled' => watch_read_camera_enabled(watch_host_camera_state_path($liveId), true),
    ];

    if (!$payload['can_view']) {
        return $payload;
    }

    $payload['can_request_join'] = $ownerId > 0 && $ownerId !== $meId && fs_are_friends($dbh, $meId, $ownerId);

    if (watch_table_exists($dbh, 'user_video_live_guest_requests') && $ownerId !== $meId) {
        try {
            $stReq = $dbh->prepare("
                SELECT status
                FROM user_video_live_guest_requests
                WHERE live_id = :live_id
                  AND requester_user_id = :user_id
                LIMIT 1
            ");
            $stReq->execute([
                ':live_id' => $liveId,
                ':user_id' => $meId,
            ]);
            $payload['join_request_status'] = (string)($stReq->fetchColumn() ?: '');
        } catch (Throwable $e) {
            $payload['join_request_status'] = '';
        }
    }

    $snapshotPath = __DIR__ . '/../storage/live_snapshots/' . $liveId . '.jpg';
    if (is_file($snapshotPath)) {
        $payload['live']['snapshot_version'] = (string)(@md5_file($snapshotPath) ?: '');
    }

    if (watch_table_exists($dbh, 'user_video_live_guest_requests')) {
        try {
            $stGuests = $dbh->prepare("
                SELECT gr.requester_user_id AS user_id, u.name, u.username
                FROM user_video_live_guest_requests gr
                JOIN users u ON u.id = gr.requester_user_id
                WHERE gr.live_id = :live_id
                  AND gr.status = 'approved'
                ORDER BY gr.updated_at ASC, gr.id ASC
            ");
            $stGuests->execute([':live_id' => $liveId]);
            foreach (($stGuests->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
                $guestId = (int)($row['user_id'] ?? 0);
                if ($guestId <= 0) {
                    continue;
                }
                if (count($payload['approved_guests']) >= WATCH_MAX_ACTIVE_GUESTS) {
                    break;
                }
                $guestSnapshotPath = __DIR__ . '/../storage/live_snapshots/' . $liveId . '_guest_' . $guestId . '.jpg';
                $payload['approved_guests'][] = [
                    'user_id' => $guestId,
                    'name' => trim((string)($row['name'] ?: $row['username'] ?: 'Guest')),
                    'snapshot_version' => is_file($guestSnapshotPath) ? (string)(@md5_file($guestSnapshotPath) ?: '') : '',
                    'camera_enabled' => watch_read_camera_enabled(watch_guest_camera_state_path($liveId, $guestId), true),
                ];
            }
        } catch (Throwable $e) {
            $payload['approved_guests'] = [];
        }
    }

    if (watch_table_exists($dbh, 'user_video_live_comments')) {
        try {
            $stCommentTotal = $dbh->prepare("
                SELECT COUNT(*)
                FROM user_video_live_comments
                WHERE live_id = :live_id
            ");
            $stCommentTotal->execute([':live_id' => $liveId]);
            $payload['comment_total'] = (int)($stCommentTotal->fetchColumn() ?: 0);
        } catch (Throwable $e) {
            $payload['comment_total'] = 0;
        }

        try {
            $likedCommentIds = [];
            $likeCounts = [];
            $likeNames = [];
            if (watch_ensure_comment_likes_table($dbh)) {
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
                ORDER BY c.id ASC
                LIMIT 200
            ");
            $stComments->execute([':live_id' => $liveId]);
            foreach (($stComments->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
                $payload['comments'][] = [
                    'id' => (int)($row['id'] ?? 0),
                    'user_id' => (int)($row['user_id'] ?? 0),
                    'author' => trim((string)($row['name'] ?: $row['username'] ?: 'User')),
                    'body' => (string)($row['body'] ?? ''),
                    'created_at_label' => watch_fmt((string)($row['created_at'] ?? '')),
                    'like_count' => (int)($likeCounts[(int)($row['id'] ?? 0)] ?? 0),
                    'liked_by_me' => !empty($likedCommentIds[(int)($row['id'] ?? 0)]),
                    'liked_by' => array_values(array_unique($likeNames[(int)($row['id'] ?? 0)] ?? [])),
                    'liked_by_label' => watch_like_label(
                        array_values(array_unique($likeNames[(int)($row['id'] ?? 0)] ?? [])),
                        (int)($likeCounts[(int)($row['id'] ?? 0)] ?? 0)
                    ),
                ];
            }
        } catch (Throwable $e) {
            $payload['comments'] = [];
        }
    }

    if (watch_ensure_reactions_table($dbh)) {
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
                if (array_key_exists($reaction, $payload['reaction_counts'])) {
                    $payload['reaction_counts'][$reaction] = (int)($row['total'] ?? 0);
                }
            }
        } catch (Throwable $e) {
            $payload['reaction_counts'] = ['love' => 0, 'like' => 0, 'fire' => 0, 'wow' => 0, 'clap' => 0];
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
            $payload['my_reaction'] = (string)($stMine->fetchColumn() ?: '');
        } catch (Throwable $e) {
            $payload['my_reaction'] = '';
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
                $payload['reaction_users'][] = [
                    'user_id' => $reactorId,
                    'name' => trim((string)($row['name'] ?: $row['username'] ?: 'User')),
                    'reaction' => (string)($row['reaction'] ?? ''),
                    'created_at_label' => watch_fmt((string)($row['created_at'] ?? '')),
                    'friend_status' => watch_reactor_status($dbh, $meId, $reactorId),
                ];
            }
        } catch (Throwable $e) {
            $payload['reaction_users'] = [];
        }
    }

    return $payload;
}

function watch_ensure_guest_request_table(PDO $dbh): bool
{
    if (watch_table_exists($dbh, 'user_video_live_guest_requests')) {
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

$controller = new Controller();
$dbh = $controller->pdo();
$meId = (int)($_SESSION['user_id'] ?? 0);
$liveId = (int)($_GET['live'] ?? $_POST['live_id'] ?? 0);

if ($meId <= 0) {
    watch_json(['ok' => false, 'error' => 'Invalid session']);
}
if ($liveId <= 0) {
    watch_json(['ok' => false, 'error' => 'Missing live room']);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $payload = watch_payload($dbh, $liveId, $meId);
    watch_json(['ok' => true] + $payload);
}

$live = watch_load_live($dbh, $liveId, $meId);
if (!$live) {
    watch_json(['ok' => false, 'error' => 'Live room not found']);
}
if (empty($live['can_view'])) {
    watch_json(['ok' => false, 'error' => 'You do not have access to this live room']);
}

$action = trim((string)($_POST['action'] ?? ''));
$isHost = (int)($live['user_id'] ?? 0) === $meId;

if ($action === 'leave_view') {
    watch_sync_viewer_presence($dbh, $liveId, $meId, $isHost, false);
    watch_json(['ok' => true] + watch_payload($dbh, $liveId, $meId, false));
}

if ($action === 'send_comment') {
    if (!watch_table_exists($dbh, 'user_video_live_comments')) {
        watch_json(['ok' => false, 'error' => 'Comment storage unavailable']);
    }
    $body = trim((string)($_POST['comment_body'] ?? ''));
    if ($body === '') {
        watch_json(['ok' => false, 'error' => 'Type a comment before sending']);
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
        watch_json(['ok' => false, 'error' => 'Unable to send comment']);
    }
} elseif ($action === 'react_live') {
    if (!watch_ensure_reactions_table($dbh)) {
        watch_json(['ok' => false, 'error' => 'Reaction storage unavailable']);
    }
    $reaction = trim((string)($_POST['reaction'] ?? ''));
    $allowed = ['love', 'like', 'fire', 'wow', 'clap'];
    if (!in_array($reaction, $allowed, true)) {
        watch_json(['ok' => false, 'error' => 'Invalid reaction']);
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
        watch_json(['ok' => false, 'error' => 'Unable to save reaction']);
    }
} elseif ($action === 'share_live') {
    try {
        $stShare = $dbh->prepare("
            UPDATE user_video_lives
            SET share_count = share_count + 1,
                updated_at = NOW()
            WHERE id = :live_id
            LIMIT 1
        ");
        $stShare->execute([':live_id' => $liveId]);
    } catch (Throwable $e) {
        watch_json(['ok' => false, 'error' => 'Unable to save share']);
    }
} elseif ($action === 'toggle_comment_like') {
    if (!watch_ensure_comment_likes_table($dbh)) {
        watch_json(['ok' => false, 'error' => 'Comment likes unavailable']);
    }
    $commentId = (int)($_POST['comment_id'] ?? 0);
    if ($commentId <= 0) {
        watch_json(['ok' => false, 'error' => 'Invalid comment']);
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
            watch_json(['ok' => false, 'error' => 'Comment not found']);
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
            $stDelete = $dbh->prepare("
                DELETE FROM user_video_live_comment_likes
                WHERE id = :id
                LIMIT 1
            ");
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
        watch_json(['ok' => false, 'error' => 'Unable to update comment like']);
    }
} elseif ($action === 'request_join') {
    $ownerId = (int)($live['user_id'] ?? 0);
    if ($ownerId === $meId) {
        watch_json(['ok' => false, 'error' => 'Host cannot request to join their own live']);
    }
    if (!fs_are_friends($dbh, $meId, $ownerId)) {
        watch_json(['ok' => false, 'error' => 'Only friends can request to join the host live']);
    }
    if (!watch_ensure_guest_request_table($dbh)) {
        watch_json(['ok' => false, 'error' => 'Guest request storage unavailable']);
    }
    try {
        $stCount = $dbh->prepare("
            SELECT COUNT(*)
            FROM user_video_live_guest_requests
            WHERE live_id = :live_id
              AND status = 'approved'
        ");
        $stCount->execute([':live_id' => $liveId]);
        $approvedCount = (int)($stCount->fetchColumn() ?: 0);
        if ($approvedCount >= WATCH_MAX_ACTIVE_GUESTS) {
            watch_json(['ok' => false, 'error' => 'Live guest limit reached (29 friends maximum).']);
        }
    } catch (Throwable $e) {
        watch_json(['ok' => false, 'error' => 'Unable to verify guest limit']);
    }
    try {
        $st = $dbh->prepare("
            INSERT INTO user_video_live_guest_requests (live_id, requester_user_id, status, created_at, updated_at)
            VALUES (:live_id, :user_id, 'requested', NOW(), NOW())
            ON DUPLICATE KEY UPDATE
                status = 'requested',
                updated_at = NOW()
        ");
        $st->execute([
            ':live_id' => $liveId,
            ':user_id' => $meId,
        ]);
    } catch (Throwable $e) {
        watch_json(['ok' => false, 'error' => 'Unable to send join request']);
    }
} elseif ($action === 'set_camera_enabled') {
    $ownerId = (int)($live['user_id'] ?? 0);
    if ($ownerId === $meId) {
        watch_json(['ok' => false, 'error' => 'Host camera state is managed in studio']);
    }
    if (!watch_ensure_guest_request_table($dbh)) {
        watch_json(['ok' => false, 'error' => 'Guest request storage unavailable']);
    }
    try {
        $stApproved = $dbh->prepare("
            SELECT status
            FROM user_video_live_guest_requests
            WHERE live_id = :live_id
              AND requester_user_id = :user_id
            LIMIT 1
        ");
        $stApproved->execute([
            ':live_id' => $liveId,
            ':user_id' => $meId,
        ]);
        $status = (string)($stApproved->fetchColumn() ?: '');
        if ($status !== 'approved') {
            watch_json(['ok' => false, 'error' => 'Guest camera control is available after approval']);
        }
        $enabled = filter_var($_POST['enabled'] ?? '1', FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        watch_write_camera_enabled(watch_guest_camera_state_path($liveId, $meId), $enabled !== false);
    } catch (Throwable $e) {
        watch_json(['ok' => false, 'error' => 'Unable to update guest camera state']);
    }
} else {
    watch_json(['ok' => false, 'error' => 'Unknown action']);
}

watch_json(['ok' => true] + watch_payload($dbh, $liveId, $meId));
