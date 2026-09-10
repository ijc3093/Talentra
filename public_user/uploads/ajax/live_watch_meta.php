<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/session_user.php';
requireUserLogin();
require_once __DIR__ . '/../controller.php';
require_once __DIR__ . '/../includes/live_browse.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$liveId = (int)($_GET['live_id'] ?? $_GET['live'] ?? 0);
if ($liveId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Invalid live id']);
    exit;
}

$dbh = (new Controller())->pdo();
live_browse_ensure_host_meta_columns($dbh);

try {
    $st = $dbh->prepare("
        SELECT l.id, l.user_id, l.title, l.visibility, l.status,
               COALESCE(l.host_door, '') AS host_door,
               COALESCE(l.studio_source, '') AS studio_source,
               COALESCE(u.name, u.username, 'Host') AS host_name
        FROM user_video_lives l
        LEFT JOIN users u ON u.id = l.user_id
        WHERE l.id = :live_id
        LIMIT 1
    ");
    $st->execute([':live_id' => $liveId]);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => 'Unable to load live']);
    exit;
}

if (!$row || strtolower(trim((string)($row['status'] ?? ''))) !== 'live') {
    echo json_encode(['ok' => false, 'error' => 'Live is not active']);
    exit;
}

$hostDoor = strtolower(trim((string)($row['host_door'] ?? '')));
$studioSource = strtolower(trim((string)($row['studio_source'] ?? '')));

echo json_encode([
    'ok' => true,
    'live' => [
        'id' => (int)($row['id'] ?? 0),
        'user_id' => (int)($row['user_id'] ?? 0),
        'title' => trim((string)($row['title'] ?? 'Live session')) ?: 'Live session',
        'host' => trim((string)($row['host_name'] ?? 'Host')) ?: 'Host',
        'visibility' => strtolower(trim((string)($row['visibility'] ?? 'friends'))),
        'host_door' => $hostDoor,
        'studio_source' => $studioSource,
        'watch_door' => live_resolve_owner_door($row),
    ],
], JSON_UNESCAPED_SLASHES);
