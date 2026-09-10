<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/session_user.php';
requireUserLogin();
require_once __DIR__ . '/../controller.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

function recording_json_out(array $payload): void
{
    echo json_encode($payload);
    exit;
}

function recording_table_exists(PDO $dbh, string $table): bool
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

function recording_ensure_table(PDO $dbh): bool
{
    try {
        $dbh->exec("
            CREATE TABLE IF NOT EXISTS user_video_live_recordings (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                live_id BIGINT UNSIGNED NOT NULL,
                user_id INT NOT NULL,
                original_name VARCHAR(190) NOT NULL DEFAULT '',
                mime_type VARCHAR(120) NOT NULL DEFAULT '',
                file_size BIGINT NOT NULL DEFAULT 0,
                duration_seconds DECIMAL(10,2) NOT NULL DEFAULT 0,
                recording_blob LONGBLOB NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_live_id (live_id),
                KEY idx_user_created (user_id, created_at)
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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    recording_json_out(['ok' => false, 'error' => 'Unsupported method']);
}

if ($meId <= 0) {
    recording_json_out(['ok' => false, 'error' => 'Invalid session']);
}

if (!recording_table_exists($dbh, 'user_video_lives')) {
    recording_json_out(['ok' => false, 'error' => 'Live storage is not available']);
}

if (!recording_ensure_table($dbh)) {
    recording_json_out(['ok' => false, 'error' => 'Recording storage is not available']);
}

$liveId = (int)($_POST['live_id'] ?? 0);
$durationSeconds = round((float)($_POST['duration_seconds'] ?? 0), 2);
$mimeType = trim((string)($_POST['mime_type'] ?? ''));
$upload = $_FILES['recording'] ?? null;

if ($liveId <= 0) {
    recording_json_out(['ok' => false, 'error' => 'Missing live id']);
}

if (!$upload || (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    recording_json_out(['ok' => false, 'error' => 'Recording file is missing']);
}

$tmpPath = trim((string)($upload['tmp_name'] ?? ''));
if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
    recording_json_out(['ok' => false, 'error' => 'Recording upload is invalid']);
}

$originalName = trim((string)($upload['name'] ?? 'live-recording.webm'));
$fileSize = (int)($upload['size'] ?? 0);
if ($fileSize <= 0) {
    recording_json_out(['ok' => false, 'error' => 'Recording file is empty']);
}

try {
    $st = $dbh->prepare("
        SELECT id, user_id
        FROM user_video_lives
        WHERE id = :id
        LIMIT 1
    ");
    $st->execute([':id' => $liveId]);
    $liveRow = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$liveRow) {
        recording_json_out(['ok' => false, 'error' => 'Live session not found']);
    }
    if ((int)($liveRow['user_id'] ?? 0) !== $meId) {
        recording_json_out(['ok' => false, 'error' => 'You do not own this live session']);
    }
} catch (Throwable $e) {
    recording_json_out(['ok' => false, 'error' => 'Unable to verify live session']);
}

$blob = @file_get_contents($tmpPath);
if ($blob === false || $blob === '') {
    recording_json_out(['ok' => false, 'error' => 'Unable to read the recording file']);
}

if ($mimeType === '') {
    $mimeType = trim((string)($upload['type'] ?? 'video/webm'));
}
if ($mimeType === '') {
    $mimeType = 'video/webm';
}

try {
    $st = $dbh->prepare("
        INSERT INTO user_video_live_recordings
            (live_id, user_id, original_name, mime_type, file_size, duration_seconds, recording_blob, created_at, updated_at)
        VALUES
            (:live_id, :user_id, :original_name, :mime_type, :file_size, :duration_seconds, :recording_blob, NOW(), NOW())
        ON DUPLICATE KEY UPDATE
            original_name = VALUES(original_name),
            mime_type = VALUES(mime_type),
            file_size = VALUES(file_size),
            duration_seconds = VALUES(duration_seconds),
            recording_blob = VALUES(recording_blob),
            updated_at = NOW()
    ");
    $st->bindValue(':live_id', $liveId, PDO::PARAM_INT);
    $st->bindValue(':user_id', $meId, PDO::PARAM_INT);
    $st->bindValue(':original_name', $originalName, PDO::PARAM_STR);
    $st->bindValue(':mime_type', $mimeType, PDO::PARAM_STR);
    $st->bindValue(':file_size', $fileSize, PDO::PARAM_INT);
    $st->bindValue(':duration_seconds', $durationSeconds);
    $st->bindValue(':recording_blob', $blob, PDO::PARAM_LOB);
    $st->execute();
} catch (Throwable $e) {
    recording_json_out(['ok' => false, 'error' => 'Unable to save recording']);
}

recording_json_out([
    'ok' => true,
    'live_id' => $liveId,
    'file_size' => $fileSize,
    'duration_seconds' => $durationSeconds,
    'mime_type' => $mimeType,
]);
