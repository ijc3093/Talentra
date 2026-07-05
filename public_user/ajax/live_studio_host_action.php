<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/session_user.php';
requireUserLogin();
require_once __DIR__ . '/../controller.php';
require_once __DIR__ . '/../includes/publisher_organization_bridge.php';
require_once __DIR__ . '/../includes/device_profile.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

function json_out(array $payload): void
{
    echo json_encode($payload);
    exit;
}

$controller = new Controller();
$dbh = $controller->pdo();
if (!live_studio_user_can_access($dbh)) {
    json_out(['ok' => false, 'error' => 'forbidden', 'message' => 'Live Studio is not available for this account.']);
}

function studio_table_exists(PDO $dbh, string $table): bool
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

function studio_column_meta(PDO $dbh, string $table, string $column): ?array
{
    try {
        $st = $dbh->prepare("
            SELECT COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, COLLATION_NAME
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = :table_name
              AND COLUMN_NAME = :column_name
            LIMIT 1
        ");
        $st->execute([
            ':table_name' => $table,
            ':column_name' => $column,
        ]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        return is_array($row) ? $row : null;
    } catch (Throwable $e) {
        return null;
    }
}

function studio_ensure_live_table(PDO $dbh): bool
{
    try {
        $dbh->exec("
            CREATE TABLE IF NOT EXISTS user_video_lives (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                friend_code VARCHAR(60) NOT NULL DEFAULT '',
                title VARCHAR(190) NOT NULL DEFAULT '',
                description TEXT NULL,
                stream_key VARCHAR(80) NOT NULL,
                status VARCHAR(30) NOT NULL DEFAULT 'draft',
                visibility VARCHAR(30) NOT NULL DEFAULT 'private',
                viewer_count INT NOT NULL DEFAULT 0,
                share_count INT NOT NULL DEFAULT 0,
                started_at DATETIME NULL DEFAULT NULL,
                scheduled_for DATETIME NULL DEFAULT NULL,
                ended_at DATETIME NULL DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_user_status (user_id, status),
                KEY idx_status_schedule (status, scheduled_for),
                KEY idx_stream_key (stream_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (Throwable $e) {
        return false;
    }

    try {
        $requiredColumns = [
            'friend_code' => "ALTER TABLE user_video_lives ADD COLUMN friend_code VARCHAR(60) NOT NULL DEFAULT '' AFTER user_id",
            'title' => "ALTER TABLE user_video_lives ADD COLUMN title VARCHAR(190) NOT NULL DEFAULT '' AFTER friend_code",
            'description' => "ALTER TABLE user_video_lives ADD COLUMN description TEXT NULL AFTER title",
            'stream_key' => "ALTER TABLE user_video_lives ADD COLUMN stream_key VARCHAR(80) NOT NULL DEFAULT '' AFTER description",
            'status' => "ALTER TABLE user_video_lives ADD COLUMN status VARCHAR(30) NOT NULL DEFAULT 'draft' AFTER stream_key",
            'visibility' => "ALTER TABLE user_video_lives ADD COLUMN visibility VARCHAR(30) NOT NULL DEFAULT 'private' AFTER status",
            'viewer_count' => "ALTER TABLE user_video_lives ADD COLUMN viewer_count INT NOT NULL DEFAULT 0 AFTER visibility",
            'share_count' => "ALTER TABLE user_video_lives ADD COLUMN share_count INT NOT NULL DEFAULT 0 AFTER viewer_count",
            'peak_viewers' => "ALTER TABLE user_video_lives ADD COLUMN peak_viewers INT NOT NULL DEFAULT 0 AFTER share_count",
            'started_at' => "ALTER TABLE user_video_lives ADD COLUMN started_at DATETIME NULL DEFAULT NULL AFTER peak_viewers",
            'scheduled_for' => "ALTER TABLE user_video_lives ADD COLUMN scheduled_for DATETIME NULL DEFAULT NULL AFTER started_at",
            'ended_at' => "ALTER TABLE user_video_lives ADD COLUMN ended_at DATETIME NULL DEFAULT NULL AFTER scheduled_for",
            'created_at' => "ALTER TABLE user_video_lives ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER ended_at",
            'updated_at' => "ALTER TABLE user_video_lives ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER created_at",
            'host_door' => "ALTER TABLE user_video_lives ADD COLUMN host_door VARCHAR(10) NOT NULL DEFAULT ''",
            'studio_source' => "ALTER TABLE user_video_lives ADD COLUMN studio_source VARCHAR(20) NOT NULL DEFAULT ''",
        ];

        foreach ($requiredColumns as $columnName => $sql) {
            $st = $dbh->prepare("
                SELECT 1
                FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = 'user_video_lives'
                  AND COLUMN_NAME = :column_name
                LIMIT 1
            ");
            $st->execute([':column_name' => $columnName]);
            if (!$st->fetchColumn()) {
                $dbh->exec($sql);
            }
        }
    } catch (Throwable $e) {
        // keep live table resilient
    }

    try {
        $liveMeta = studio_column_meta($dbh, 'user_video_lives', 'friend_code');
        $userMeta = studio_column_meta($dbh, 'users', 'friend_code');
        $liveCollation = trim((string)($liveMeta['COLLATION_NAME'] ?? ''));
        $userCollation = trim((string)($userMeta['COLLATION_NAME'] ?? ''));
        if ($liveMeta && $userCollation !== '' && $liveCollation !== $userCollation) {
            $columnType = trim((string)($liveMeta['COLUMN_TYPE'] ?? 'VARCHAR(60)'));
            $nullable = strtoupper(trim((string)($liveMeta['IS_NULLABLE'] ?? 'NO'))) === 'YES' ? 'NULL' : 'NOT NULL';
            $defaultRaw = $liveMeta['COLUMN_DEFAULT'] ?? '';
            $defaultSql = "DEFAULT '" . str_replace("'", "''", (string)$defaultRaw) . "'";
            $dbh->exec("
                ALTER TABLE user_video_lives
                MODIFY friend_code {$columnType}
                CHARACTER SET utf8mb4
                COLLATE {$userCollation}
                {$nullable}
                {$defaultSql}
            ");
        }
    } catch (Throwable $e) {
        // keep live table resilient
    }

    try {
        $descMeta = studio_column_meta($dbh, 'user_video_lives', 'description');
        if ($descMeta) {
            $columnType = strtolower(trim((string)($descMeta['COLUMN_TYPE'] ?? '')));
            if ($columnType !== '' && strpos($columnType, 'text') === false) {
                $dbh->exec('ALTER TABLE user_video_lives MODIFY description TEXT NULL');
            }
        }
    } catch (Throwable $e) {
        // keep live table resilient
    }

    return true;
}

function studio_fetch_user_friend_code(PDO $dbh, int $userId): string
{
    if ($userId <= 0) {
        return '';
    }
    try {
        $st = $dbh->prepare("SELECT COALESCE(friend_code, '') FROM users WHERE id = :id LIMIT 1");
        $st->execute([':id' => $userId]);
        return trim((string)($st->fetchColumn() ?: ''));
    } catch (Throwable $e) {
        return '';
    }
}

function studio_make_stream_key(): string
{
    return 'studio_' . bin2hex(random_bytes(8));
}

function studio_fit_db_text(string $value, int $maxLen): string
{
    $value = trim($value);
    if ($maxLen <= 0 || $value === '') {
        return $value;
    }
    if (mb_strlen($value) <= $maxLen) {
        return $value;
    }
    return mb_substr($value, 0, $maxLen);
}

function studio_live_post_marker(int $liveId): string
{
    return $liveId > 0 ? ('[[live_post:' . $liveId . ']]') : '';
}

function studio_live_post_body(int $liveId): string
{
    if ($liveId <= 0) {
        return '';
    }
    return studio_live_post_marker($liveId) . ' live_watch.php?live=' . $liveId;
}

function studio_feed_post_visibility(string $visibility): ?string
{
    $visibility = strtolower(trim($visibility));
    if ($visibility === 'private') {
        return null;
    }

    return in_array($visibility, ['public', 'friends'], true) ? $visibility : 'friends';
}

function studio_sync_live_feed_post(PDO $dbh, int $liveId, int $userId, string $title, string $description, string $visibility, string $deviceLabel = '', string $deviceViewport = ''): void
{
    if ($liveId <= 0 || $userId <= 0) {
        return;
    }

    $feedVisibility = studio_feed_post_visibility($visibility);
    if ($feedVisibility === null) {
        studio_hide_live_feed_post($dbh, $liveId, $userId);
        return;
    }

    $marker = studio_live_post_marker($liveId);
    $body = studio_live_post_body($liveId);
    if ($marker === '' || $body === '') {
        return;
    }

    $feedTitle = studio_fit_db_text($title, 120);
    $feedDescription = studio_fit_db_text($description, 255);

    $stFind = $dbh->prepare("
        SELECT id
        FROM public_posts
        WHERE user_id = :uid
          AND is_deleted = 0
          AND body LIKE :marker_like
        ORDER BY id DESC
        LIMIT 1
    ");
    $stFind->execute([
        ':uid' => $userId,
        ':marker_like' => '%' . $marker . '%',
    ]);
    $postId = (int)($stFind->fetchColumn() ?: 0);

    if ($postId > 0) {
        $stUpdate = $dbh->prepare("
            UPDATE public_posts
            SET title = :title,
                description = :description,
                body = :body,
                visibility = :visibility,
                device_label = :device_label,
                device_viewport = :device_viewport,
                is_deleted = 0,
                updated_at = NOW()
            WHERE id = :id
              AND user_id = :uid
            LIMIT 1
        ");
        $stUpdate->execute([
            ':title' => $feedTitle,
            ':description' => $feedDescription !== '' ? $feedDescription : null,
            ':body' => $body,
            ':visibility' => $feedVisibility,
            ':device_label' => $deviceLabel,
            ':device_viewport' => $deviceViewport,
            ':id' => $postId,
            ':uid' => $userId,
        ]);
        return;
    }

    $stInsert = $dbh->prepare("
        INSERT INTO public_posts
            (user_id, title, description, body, visibility, device_label, device_viewport, created_at, updated_at, is_deleted, views_count)
        VALUES
            (:uid, :title, :description, :body, :visibility, :device_label, :device_viewport, NOW(), NOW(), 0, 0)
    ");
    $stInsert->execute([
        ':uid' => $userId,
        ':title' => $feedTitle,
        ':description' => $feedDescription !== '' ? $feedDescription : null,
        ':body' => $body,
        ':visibility' => $feedVisibility,
        ':device_label' => $deviceLabel,
        ':device_viewport' => $deviceViewport,
    ]);
}

function studio_hide_live_feed_post(PDO $dbh, int $liveId, int $userId): void
{
    if ($liveId <= 0 || $userId <= 0) {
        return;
    }
    $marker = studio_live_post_marker($liveId);
    if ($marker === '') {
        return;
    }
    $st = $dbh->prepare("
        UPDATE public_posts
        SET is_deleted = 1,
            updated_at = NOW()
        WHERE user_id = :uid
          AND is_deleted = 0
          AND body LIKE :marker_like
    ");
    $st->execute([
        ':uid' => $userId,
        ':marker_like' => '%' . $marker . '%',
    ]);
}

function studio_ensure_usage_table(PDO $dbh): bool
{
    if (studio_table_exists($dbh, 'user_video_live_usage')) {
        return true;
    }

    try {
        $dbh->exec("
            CREATE TABLE IF NOT EXISTS user_video_live_usage (
                user_id INT NOT NULL PRIMARY KEY,
                total_sessions INT NOT NULL DEFAULT 0,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        return true;
    } catch (Throwable $e) {
        return false;
    }
}


function studio_fmt_dt_local(?string $raw): string
{
    $raw = trim((string)$raw);
    if ($raw === '') {
        return '';
    }
    $ts = strtotime($raw);
    return $ts ? date('Y-m-d\TH:i', $ts) : '';
}

function studio_fetch_current_live(PDO $dbh, int $meId): ?array
{
    if ($meId <= 0 || !studio_ensure_live_table($dbh)) {
        return null;
    }

    try {
        $st = $dbh->prepare("
            SELECT id, user_id, title, description, stream_key, status, visibility, viewer_count, started_at, scheduled_for, ended_at, created_at, updated_at
                   , share_count, host_door, studio_source
            FROM user_video_lives
            WHERE user_id = :uid
              AND status IN ('draft','scheduled','live')
            ORDER BY FIELD(status, 'live', 'scheduled', 'draft'), COALESCE(started_at, scheduled_for, updated_at, created_at) DESC, id DESC
            LIMIT 1
        ");
        $st->execute([':uid' => $meId]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$row) {
            return null;
        }

        $row['schedule_input'] = studio_fmt_dt_local((string)($row['scheduled_for'] ?? ''));
        return $row;
    } catch (Throwable $e) {
        return null;
    }
}

function studio_fetch_history_count(PDO $dbh, int $meId): int
{
    if ($meId <= 0 || !studio_ensure_live_table($dbh)) {
        return 0;
    }

    $count = 0;

    try {
        $st = $dbh->prepare("
            SELECT COUNT(*)
            FROM user_video_lives
            WHERE user_id = :uid
              AND status NOT IN ('draft','scheduled','live')
        ");
        $st->execute([':uid' => $meId]);
        $count += (int)($st->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        $count += 0;
    }

    if (studio_ensure_usage_table($dbh)) {
        try {
            $stUsage = $dbh->prepare("
                SELECT total_sessions
                FROM user_video_live_usage
                WHERE user_id = :uid
                LIMIT 1
            ");
            $stUsage->execute([':uid' => $meId]);
            $count = max($count, (int)($stUsage->fetchColumn() ?: 0));
        } catch (Throwable $e) {
            $count += 0;
        }
    }

    return $count;
}

function studio_increment_usage(PDO $dbh, int $meId): void
{
    if ($meId <= 0 || !studio_ensure_usage_table($dbh)) {
        return;
    }

    try {
        $st = $dbh->prepare("
            INSERT INTO user_video_live_usage (user_id, total_sessions, updated_at)
            VALUES (:uid, 1, NOW())
            ON DUPLICATE KEY UPDATE
                total_sessions = total_sessions + 1,
                updated_at = NOW()
        ");
        $st->execute([':uid' => $meId]);
    } catch (Throwable $e) {
        // keep end-live flow resilient
    }
}

function studio_visibility_value(string $value): string
{
    $value = strtolower(trim($value));
    return in_array($value, ['private', 'friends', 'public'], true) ? $value : 'friends';
}

function studio_payload_live(?array $row): ?array
{
    if (!$row) {
        return null;
    }

    return [
        'id' => (int)($row['id'] ?? 0),
        'title' => (string)($row['title'] ?? ''),
        'description' => (string)($row['description'] ?? ''),
        'status' => (string)($row['status'] ?? 'draft'),
        'visibility' => (string)($row['visibility'] ?? 'private'),
        'viewer_count' => (int)($row['viewer_count'] ?? 0),
        'share_count' => (int)($row['share_count'] ?? 0),
        'stream_key' => (string)($row['stream_key'] ?? ''),
        'started_at' => (string)($row['started_at'] ?? ''),
        'scheduled_for' => (string)($row['scheduled_for'] ?? ''),
        'schedule_input' => (string)($row['schedule_input'] ?? studio_fmt_dt_local((string)($row['scheduled_for'] ?? ''))),
        'host_door' => (string)($row['host_door'] ?? ''),
        'studio_source' => (string)($row['studio_source'] ?? ''),
    ];
}

function studio_fetch_latest_finished_summary(PDO $dbh, int $meId): ?array
{
    if ($meId <= 0 || !studio_ensure_live_table($dbh)) {
        return null;
    }

    try {
        $st = $dbh->prepare("
            SELECT id, title, visibility, viewer_count, share_count, ended_at, started_at, created_at
            FROM user_video_lives
            WHERE user_id = :uid
              AND status NOT IN ('draft','scheduled','live')
            ORDER BY COALESCE(ended_at, started_at, created_at) DESC, id DESC
        ");
        $st->execute([':uid' => $meId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (!$rows) {
            return null;
        }

        $latestRow = $rows[0];
        $liveIds = array_values(array_filter(array_map(static function ($row) {
            return (int)($row['id'] ?? 0);
        }, $rows)));
        $views = 0;
        $shares = 0;
        foreach ($rows as $row) {
            $views += (int)($row['viewer_count'] ?? 0);
            $shares += (int)($row['share_count'] ?? 0);
        }

        $comments = 0;
        $reactionCounts = ['love' => 0, 'like' => 0, 'fire' => 0, 'wow' => 0, 'clap' => 0];

        if ($liveIds && studio_table_exists($dbh, 'user_video_live_comments')) {
            try {
                $placeholders = implode(',', array_fill(0, count($liveIds), '?'));
                $stComments = $dbh->prepare("
                    SELECT COUNT(*)
                    FROM user_video_live_comments
                    WHERE live_id IN ($placeholders)
                ");
                $stComments->execute($liveIds);
                $comments = (int)($stComments->fetchColumn() ?: 0);
            } catch (Throwable $e) {
                $comments = 0;
            }
        }

        if ($liveIds && studio_table_exists($dbh, 'user_video_live_reactions')) {
            try {
                $placeholders = implode(',', array_fill(0, count($liveIds), '?'));
                $stReactions = $dbh->prepare("
                    SELECT reaction, COUNT(*) AS total
                    FROM user_video_live_reactions
                    WHERE live_id IN ($placeholders)
                    GROUP BY reaction
                ");
                $stReactions->execute($liveIds);
                foreach (($stReactions->fetchAll(PDO::FETCH_ASSOC) ?: []) as $reactionRow) {
                    $reaction = (string)($reactionRow['reaction'] ?? '');
                    if (array_key_exists($reaction, $reactionCounts)) {
                        $reactionCounts[$reaction] = (int)($reactionRow['total'] ?? 0);
                    }
                }
            } catch (Throwable $e) {
                $reactionCounts = ['love' => 0, 'like' => 0, 'fire' => 0, 'wow' => 0, 'clap' => 0];
            }
        }

        return [
            'id' => (int)($latestRow['id'] ?? 0),
            'title' => 'All finished live totals',
            'visibility' => (string)($latestRow['visibility'] ?? 'private'),
            'views' => $views,
            'comments' => $comments,
            'shares' => $shares,
            'love' => (int)($reactionCounts['love'] ?? 0),
            'like' => (int)($reactionCounts['like'] ?? 0),
            'smile' => (int)($reactionCounts['wow'] ?? 0),
            'care' => (int)($reactionCounts['clap'] ?? 0),
            'angry' => (int)($reactionCounts['fire'] ?? 0),
            'ended_at_label' => 'Saved across ' . count($rows) . ' finished live session' . (count($rows) === 1 ? '' : 's') . '. Latest: ' . studioFmt((string)($latestRow['ended_at'] ?? $latestRow['started_at'] ?? $latestRow['created_at'] ?? '')),
        ];
    } catch (Throwable $e) {
        return null;
    }
}

function studio_ensure_viewers_table(PDO $dbh): bool
{
    if (studio_table_exists($dbh, 'user_video_live_viewers')) {
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

$controller = new Controller();
$dbh = $controller->pdo();
$meId = (int)($_SESSION['user_id'] ?? 0);
$meCode = trim((string)($_SESSION['friend_code'] ?? $_SESSION['user_friend_code'] ?? ''));
$meUsername = trim((string)($_SESSION['user_login'] ?? ''));
$meName = trim((string)($_SESSION['name'] ?? $_SESSION['user_name'] ?? $meUsername));

if (($meUsername === '' || $meName === '') && $meId > 0) {
    try {
        $stMe = $dbh->prepare("SELECT username, name FROM users WHERE id = :id LIMIT 1");
        $stMe->execute([':id' => $meId]);
        $meRow = $stMe->fetch(PDO::FETCH_ASSOC) ?: [];
        if ($meUsername === '') {
            $meUsername = trim((string)($meRow['username'] ?? ''));
        }
        if ($meName === '') {
            $meName = trim((string)($meRow['name'] ?? $meUsername));
        }
    } catch (Throwable $e) {
        // keep request resilient
    }
}

if ($meId <= 0) {
    json_out(['ok' => false, 'error' => 'Invalid session']);
}
if (!studio_ensure_live_table($dbh)) {
    json_out(['ok' => false, 'error' => 'Live storage is not available']);
}
require_once __DIR__ . '/../includes/live_browse.php';
device_profile_ensure_live_columns($dbh);
if ($meCode === '') {
    $meCode = studio_fetch_user_friend_code($dbh, $meId);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $fetchDoor = strtolower(trim((string)($_GET['host_door'] ?? '')));
    if (!in_array($fetchDoor, ['left', 'right'], true)) {
        $fetchDoor = '';
    }
    $liveRow = $fetchDoor !== ''
        ? live_fetch_owner_live_for_door($dbh, $meId, $fetchDoor)
        : studio_fetch_current_live($dbh, $meId);
    json_out([
        'ok' => true,
        'live' => studio_payload_live($liveRow),
        'history_count' => studio_fetch_history_count($dbh, $meId),
        'history_summary' => studio_fetch_latest_finished_summary($dbh, $meId),
    ]);
}

$action = trim((string)($_POST['action'] ?? ''));
$title = trim((string)($_POST['title'] ?? ''));
$description = trim((string)($_POST['description'] ?? ''));
$visibility = studio_visibility_value((string)($_POST['visibility'] ?? 'friends'));
$scheduledForInput = trim((string)($_POST['scheduled_for'] ?? ''));
$deviceProfile = device_profile_read_from_request();
$deviceLabel = (string)($deviceProfile['label'] ?? '');
$deviceViewport = (string)($deviceProfile['viewport'] ?? '');
$studioSource = strtolower(trim((string)($_POST['studio_source'] ?? '')));
if (!in_array($studioSource, ['webcam', 'software'], true)) {
    $studioSource = '';
}
$hostDoor = strtolower(trim((string)($_POST['host_door'] ?? '')));
if (!in_array($hostDoor, ['left', 'right'], true)) {
    $hostDoor = '';
}

if ($title === '' && $action !== 'end_live') {
    $title = 'My live session';
}

$scheduledForSql = null;
if ($scheduledForInput !== '') {
    $ts = strtotime($scheduledForInput);
    if (!$ts) {
        json_out(['ok' => false, 'error' => 'Invalid schedule date']);
    }
    $scheduledForSql = date('Y-m-d H:i:s', $ts);
}

$currentLive = studio_fetch_current_live($dbh, $meId);
$currentId = (int)($currentLive['id'] ?? 0);
$previousStatus = strtolower(trim((string)($currentLive['status'] ?? '')));

try {
    if ($action === 'save_draft') {
        if ($currentId > 0) {
            $st = $dbh->prepare("
                UPDATE user_video_lives
                SET title = :title,
                    description = :description,
                    visibility = :visibility,
                    status = CASE WHEN status = 'live' THEN status ELSE 'draft' END,
                    scheduled_for = NULL,
                    updated_at = NOW()
                WHERE id = :id
                  AND user_id = :uid
                LIMIT 1
            ");
            $st->execute([
                ':title' => $title,
                ':description' => $description !== '' ? $description : null,
                ':visibility' => $visibility,
                ':id' => $currentId,
                ':uid' => $meId,
            ]);
        } else {
            $st = $dbh->prepare("
                INSERT INTO user_video_lives
                    (user_id, friend_code, title, description, stream_key, status, visibility, created_at, updated_at)
                VALUES
                    (:uid, :friend_code, :title, :description, :stream_key, 'draft', :visibility, NOW(), NOW())
            ");
            $st->execute([
                ':uid' => $meId,
                ':friend_code' => $meCode,
                ':title' => $title,
                ':description' => $description !== '' ? $description : null,
                ':stream_key' => studio_make_stream_key(),
                ':visibility' => $visibility,
            ]);
        }
    } elseif ($action === 'schedule_live') {
        if ($scheduledForSql === null) {
            json_out(['ok' => false, 'error' => 'Choose when to schedule the live']);
        }
        if ($currentId > 0) {
            $st = $dbh->prepare("
                UPDATE user_video_lives
                SET title = :title,
                    description = :description,
                    visibility = :visibility,
                    status = 'scheduled',
                    scheduled_for = :scheduled_for,
                    started_at = NULL,
                    ended_at = NULL,
                    updated_at = NOW()
                WHERE id = :id
                  AND user_id = :uid
                LIMIT 1
            ");
            $st->execute([
                ':title' => $title,
                ':description' => $description !== '' ? $description : null,
                ':visibility' => $visibility,
                ':scheduled_for' => $scheduledForSql,
                ':id' => $currentId,
                ':uid' => $meId,
            ]);
        } else {
            $st = $dbh->prepare("
                INSERT INTO user_video_lives
                    (user_id, friend_code, title, description, stream_key, status, visibility, scheduled_for, created_at, updated_at)
                VALUES
                    (:uid, :friend_code, :title, :description, :stream_key, 'scheduled', :visibility, :scheduled_for, NOW(), NOW())
            ");
            $st->execute([
                ':uid' => $meId,
                ':friend_code' => $meCode,
                ':title' => $title,
                ':description' => $description !== '' ? $description : null,
                ':stream_key' => studio_make_stream_key(),
                ':visibility' => $visibility,
                ':scheduled_for' => $scheduledForSql,
            ]);
        }
    } elseif ($action === 'start_live') {
        if ($hostDoor === '' && $studioSource === 'software') {
            $hostDoor = 'right';
        } elseif ($hostDoor === '') {
            $hostDoor = 'left';
        }
        $requestedDoor = live_resolve_owner_door([
            'host_door' => $hostDoor,
            'studio_source' => $studioSource !== '' ? $studioSource : 'webcam',
        ]);
        if ($previousStatus === 'live' && $currentId > 0) {
            $existingDoor = live_resolve_owner_door($currentLive);
            if ($existingDoor !== '' && $requestedDoor !== '' && $existingDoor !== $requestedDoor) {
                $currentId = 0;
            }
        }
        if ($currentId > 0) {
            $st = $dbh->prepare("
                UPDATE user_video_lives
                SET title = :title,
                    description = :description,
                    visibility = :visibility,
                    device_label = :device_label,
                    device_viewport = :device_viewport,
                    host_door = :host_door,
                    studio_source = :studio_source,
                    status = 'live',
                    started_at = COALESCE(started_at, NOW()),
                    scheduled_for = NULL,
                    ended_at = NULL,
                    updated_at = NOW()
                WHERE id = :id
                  AND user_id = :uid
                LIMIT 1
            ");
            $st->execute([
                ':title' => $title,
                ':description' => $description !== '' ? $description : null,
                ':visibility' => $visibility,
                ':device_label' => $deviceLabel,
                ':device_viewport' => $deviceViewport,
                ':host_door' => $hostDoor,
                ':studio_source' => $studioSource !== '' ? $studioSource : 'webcam',
                ':id' => $currentId,
                ':uid' => $meId,
            ]);
            $liveId = $currentId;
        } else {
            $st = $dbh->prepare("
                INSERT INTO user_video_lives
                    (user_id, friend_code, title, description, stream_key, status, visibility, device_label, device_viewport, host_door, studio_source, started_at, created_at, updated_at)
                VALUES
                    (:uid, :friend_code, :title, :description, :stream_key, 'live', :visibility, :device_label, :device_viewport, :host_door, :studio_source, NOW(), NOW(), NOW())
            ");
            $st->execute([
                ':uid' => $meId,
                ':friend_code' => $meCode,
                ':title' => $title,
                ':description' => $description !== '' ? $description : null,
                ':stream_key' => studio_make_stream_key(),
                ':visibility' => $visibility,
                ':device_label' => $deviceLabel,
                ':device_viewport' => $deviceViewport,
                ':host_door' => $hostDoor,
                ':studio_source' => $studioSource !== '' ? $studioSource : 'webcam',
            ]);
            $liveId = (int)$dbh->lastInsertId();
        }
        studio_sync_live_feed_post($dbh, $liveId, $meId, $title, $description, $visibility, $deviceLabel, $deviceViewport);

    } elseif ($action === 'end_live') {
        $endLiveId = (int)($_POST['live_id'] ?? 0);
        if ($endLiveId > 0) {
            $currentId = $endLiveId;
        }
        if ($currentId <= 0) {
            json_out(['ok' => false, 'error' => 'No live session to end']);
        }
        try {
            $dbh->beginTransaction();

            if (studio_ensure_viewers_table($dbh)) {
                $stViewers = $dbh->prepare("DELETE FROM user_video_live_viewers WHERE live_id = :live_id");
                $stViewers->execute([':live_id' => $currentId]);
            }

            if (studio_table_exists($dbh, 'user_video_live_guest_requests')) {
                $stGuests = $dbh->prepare("DELETE FROM user_video_live_guest_requests WHERE live_id = :live_id");
                $stGuests->execute([':live_id' => $currentId]);
            }

            if (studio_table_exists($dbh, 'user_video_live_signals')) {
                $stSignals = $dbh->prepare("DELETE FROM user_video_live_signals WHERE live_id = :live_id");
                $stSignals->execute([':live_id' => $currentId]);
            }

            $st = $dbh->prepare("
                UPDATE user_video_lives
                SET status = 'ended',
                    ended_at = NOW(),
                    updated_at = NOW()
                WHERE id = :id
                  AND user_id = :uid
                LIMIT 1
            ");
            $st->execute([
                ':id' => $currentId,
                ':uid' => $meId,
            ]);

            $dbh->commit();
        } catch (Throwable $e) {
            if ($dbh->inTransaction()) {
                $dbh->rollBack();
            }
            throw $e;
        }

        $snapshotPath = __DIR__ . '/../storage/live_snapshots/' . $currentId . '.jpg';
        if (is_file($snapshotPath)) {
            @unlink($snapshotPath);
        }
        foreach ((array)glob(__DIR__ . '/../storage/live_snapshots/' . $currentId . '_guest_*.jpg') as $guestSnapshotPath) {
            if (is_file($guestSnapshotPath)) {
                @unlink($guestSnapshotPath);
            }
        }
        studio_hide_live_feed_post($dbh, $currentId, $meId);
    } else {
        json_out(['ok' => false, 'error' => 'Unknown action']);
    }

    $current = studio_fetch_current_live($dbh, $meId);
    json_out([
        'ok' => true,
        'live' => studio_payload_live($current),
        'history_count' => studio_fetch_history_count($dbh, $meId),
        'history_summary' => studio_fetch_latest_finished_summary($dbh, $meId),
    ]);
} catch (Throwable $e) {
    $detail = '';
    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    $isLocal = strpos($host, 'localhost') !== false || strpos($host, '127.0.0.1') !== false;
    if ($isLocal) {
        $detail = trim((string)$e->getMessage());
    }
    json_out([
        'ok' => false,
        'error' => $detail !== '' ? ('Unable to save live studio changes: ' . $detail) : 'Unable to save live studio changes'
    ]);
}
