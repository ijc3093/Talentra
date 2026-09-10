<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/session_user.php';
requireUserLogin();
require_once __DIR__ . '/../controller.php';
require_once __DIR__ . '/../includes/friend_system.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

function snapshot_json(array $payload): void
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload);
    exit;
}

function snapshot_table_exists(PDO $dbh, string $table): bool
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

function snapshot_storage_dir(): string
{
    return __DIR__ . '/../storage/live_snapshots';
}

function snapshot_storage_path(int $liveId): string
{
    return snapshot_storage_dir() . '/' . $liveId . '.jpg';
}

function snapshot_guest_storage_path(int $liveId, int $userId): string
{
    return snapshot_storage_dir() . '/' . $liveId . '_guest_' . $userId . '.jpg';
}

function snapshot_ensure_dir(): bool
{
    $dir = snapshot_storage_dir();
    if (is_dir($dir)) {
        return true;
    }
    return @mkdir($dir, 0775, true);
}

function snapshot_load_live(PDO $dbh, int $liveId): ?array
{
    if ($liveId <= 0 || !snapshot_table_exists($dbh, 'user_video_lives')) {
        return null;
    }

    try {
        $st = $dbh->prepare("
            SELECT l.id, l.user_id, l.status, l.visibility
            FROM user_video_lives l
            WHERE l.id = :id
            LIMIT 1
        ");
        $st->execute([':id' => $liveId]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

function snapshot_can_view(PDO $dbh, array $live, int $meId): bool
{
    $ownerId = (int)($live['user_id'] ?? 0);
    $visibility = strtolower(trim((string)($live['visibility'] ?? 'private')));

    if ($ownerId === $meId) {
        return true;
    }
    if ($visibility === 'public') {
        return true;
    }
    return $visibility === 'friends' && fs_are_friends($dbh, $meId, $ownerId);
}

$controller = new Controller();
$dbh = $controller->pdo();
$meId = (int)($_SESSION['user_id'] ?? 0);
$liveId = (int)($_GET['live'] ?? $_POST['live_id'] ?? 0);
$guestUserId = (int)($_GET['guest_user_id'] ?? $_POST['guest_user_id'] ?? 0);

// Release session lock immediately so rapid host uploads + friend pulls
// do not serialize behind PHP's session file lock (was starving frame writes).
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

if ($meId <= 0 || $liveId <= 0) {
    snapshot_json(['ok' => false, 'error' => 'Invalid request']);
}

$live = snapshot_load_live($dbh, $liveId);
if (!$live) {
    snapshot_json(['ok' => false, 'error' => 'Live room not found']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ownerId = (int)($live['user_id'] ?? 0);
    $isGuestUpload = $guestUserId > 0;
    if ($isGuestUpload) {
        $uploaderIsOwner = $ownerId === $meId;
        if (!$uploaderIsOwner && $guestUserId !== $meId) {
            snapshot_json(['ok' => false, 'error' => 'Invalid guest upload']);
        }
        if (!snapshot_table_exists($dbh, 'user_video_live_guest_requests')) {
            snapshot_json(['ok' => false, 'error' => 'Guest request storage unavailable']);
        }
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
                ':user_id' => $guestUserId,
            ]);
            $status = strtolower(trim((string)($stReq->fetchColumn() ?: '')));
            if ($status !== 'approved') {
                snapshot_json(['ok' => false, 'error' => 'Guest is not approved']);
            }
        } catch (Throwable $e) {
            snapshot_json(['ok' => false, 'error' => 'Unable to verify guest']);
        }
    } elseif ($ownerId !== $meId) {
        snapshot_json(['ok' => false, 'error' => 'Only the host can upload snapshots']);
    }
    if (strtolower(trim((string)($live['status'] ?? ''))) !== 'live') {
        snapshot_json(['ok' => false, 'error' => 'Live room is not active']);
    }
    if (!snapshot_ensure_dir()) {
        snapshot_json(['ok' => false, 'error' => 'Snapshot storage unavailable']);
    }

    $blob = $_FILES['frame'] ?? null;
    $hasFile = $blob && (int)($blob['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK;
    $frameBase64 = trim((string)($_POST['frame_base64'] ?? ''));

    $target = $isGuestUpload ? snapshot_guest_storage_path($liveId, $guestUserId) : snapshot_storage_path($liveId);
    $tmpTarget = $target . '.tmp';
    @unlink($tmpTarget);

    if ($hasFile) {
        $tmpPath = (string)($blob['tmp_name'] ?? '');
        if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
            snapshot_json(['ok' => false, 'error' => 'Invalid frame upload']);
        }
        if (!@move_uploaded_file($tmpPath, $tmpTarget)) {
            if (!@copy($tmpPath, $tmpTarget)) {
                snapshot_json(['ok' => false, 'error' => 'Unable to save frame']);
            }
        }
    } elseif ($frameBase64 !== '') {
        if (preg_match('#^data:image/(?:jpeg|jpg|png|webp);base64,#i', $frameBase64)) {
            $frameBase64 = (string)preg_replace('#^data:image/(?:jpeg|jpg|png|webp);base64,#i', '', $frameBase64);
        }
        $binary = base64_decode($frameBase64, true);
        if ($binary === false || strlen($binary) < 64) {
            snapshot_json(['ok' => false, 'error' => 'Invalid frame data']);
        }
        if (@file_put_contents($tmpTarget, $binary) === false) {
            snapshot_json(['ok' => false, 'error' => 'Unable to save frame']);
        }
    } else {
        snapshot_json(['ok' => false, 'error' => 'Missing frame upload']);
    }

    if (!@rename($tmpTarget, $target)) {
        @unlink($tmpTarget);
        snapshot_json(['ok' => false, 'error' => 'Unable to finalize frame']);
    }

    @chmod($target, 0664);

    // Host is publishing frames — clear stale "camera off" so friends are not
    // stuck behind the solid black camera-off cover.
    if (!$isGuestUpload) {
        $cameraStatePath = snapshot_storage_dir() . '/' . $liveId . '.camera.json';
        @file_put_contents($cameraStatePath, json_encode([
            'enabled' => true,
            'updated_at' => gmdate('c'),
        ]), LOCK_EX);
    }

    snapshot_json([
        'ok' => true,
        // filemtime+size is enough for cache-busting and much cheaper than md5 every frame.
        'snapshot_version' => (string)((int)(@filemtime($target) ?: time()) . '-' . (int)(@filesize($target) ?: 0)),
    ]);
}

if (!snapshot_can_view($dbh, $live, $meId)) {
    http_response_code(403);
    exit;
}

if (strtolower(trim((string)($live['status'] ?? ''))) !== 'live') {
    http_response_code(404);
    exit;
}

$path = $guestUserId > 0 ? snapshot_guest_storage_path($liveId, $guestUserId) : snapshot_storage_path($liveId);
if (!is_file($path)) {
    http_response_code(404);
    exit;
}

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('Content-Type: image/jpeg');
$mtime = (int)(@filemtime($path) ?: time());
$size = (int)(@filesize($path) ?: 0);
$etag = '"' . $mtime . '-' . $size . '"';
header('ETag: ' . $etag);
header('X-Snapshot-Version: ' . $mtime . '-' . $size);
$clientEtag = trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
if ($clientEtag !== '' && $clientEtag === $etag) {
    http_response_code(304);
    exit;
}
header('Content-Length: ' . (string)$size);
readfile($path);
exit;
