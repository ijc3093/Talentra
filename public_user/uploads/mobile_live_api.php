<?php
declare(strict_types=1);

/**
 * Mobile JSON API for live broadcasts (feeds + watch room list).
 * Session cookie auth — same as feed_api.php / index.php login.
 */

require_once __DIR__ . '/includes/session_user.php';
requireUserLogin();
require_once __DIR__ . '/controller.php';
require_once __DIR__ . '/includes/friend_system.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

function mobile_live_json(array $payload): void
{
    echo json_encode($payload);
    exit;
}

function mobile_live_table_exists(PDO $dbh, string $table): bool
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

function mobile_live_item(array $row, int $meId): array
{
    $hostId = (int)($row['user_id'] ?? 0);
    $hostName = trim((string)($row['name'] ?? $row['username'] ?? 'Host'));
    if ($hostName === '') {
        $hostName = 'Host';
    }

    $visibility = strtolower(trim((string)($row['visibility'] ?? 'private')));
    if (!in_array($visibility, ['private', 'friends', 'public'], true)) {
        $visibility = 'private';
    }

    return [
        'id' => (int)($row['id'] ?? 0),
        'host_user_id' => $hostId,
        'host_name' => $hostName,
        'host_username' => trim((string)($row['username'] ?? '')),
        'title' => trim((string)($row['title'] ?? '')),
        'description' => trim((string)($row['description'] ?? '')),
        'viewer_count' => (int)($row['viewer_count'] ?? 0),
        'visibility' => $visibility,
        'status' => (string)($row['status'] ?? ''),
        'started_at' => (string)($row['started_at'] ?? ''),
        'is_hosted_by_me' => $hostId > 0 && $hostId === $meId,
    ];
}

$controller = new Controller();
$dbh = $controller->pdo();
$meId = (int)($_SESSION['user_id'] ?? 0);

if ($meId <= 0) {
    mobile_live_json(['ok' => false, 'error' => 'Invalid session']);
}

$ajax = strtolower(trim((string)($_GET['ajax'] ?? $_POST['ajax'] ?? '')));

if ($ajax !== 'list_active') {
    mobile_live_json(['ok' => false, 'error' => 'Unknown action']);
}

$scope = strtolower(trim((string)($_GET['scope'] ?? $_POST['scope'] ?? 'public')));
if (!in_array($scope, ['friends', 'public'], true)) {
    $scope = 'public';
}

$q = trim((string)($_GET['q'] ?? $_POST['q'] ?? ''));
$items = [];

if (!mobile_live_table_exists($dbh, 'user_video_lives')) {
    mobile_live_json(['ok' => true, 'items' => [], 'scope' => $scope]);
}

$sql = "
    SELECT
        l.id,
        l.user_id,
        l.title,
        l.description,
        l.visibility,
        l.status,
        l.viewer_count,
        l.started_at,
        l.updated_at,
        u.name,
        u.username
    FROM user_video_lives l
    JOIN users u ON u.id = l.user_id
    WHERE l.status = 'live'
";
$params = [];

if ($scope === 'friends') {
    $sql .= "
      AND l.visibility = 'friends'
      AND (
        l.user_id = :me_id
        OR EXISTS (
          SELECT 1
          FROM user_contacts uc
          WHERE uc.owner_user_id = :me_id_2
            AND uc.friend_user_id = l.user_id
        )
        OR EXISTS (
          SELECT 1
          FROM user_contacts uc2
          WHERE uc2.owner_user_id = l.user_id
            AND uc2.friend_user_id = :me_id_3
        )
      )
    ";
    $params[':me_id'] = $meId;
    $params[':me_id_2'] = $meId;
    $params[':me_id_3'] = $meId;
} else {
    $sql .= "
      AND l.visibility = 'public'
    ";
}

if ($q !== '') {
    $sql .= "
      AND (
        COALESCE(l.title, '') LIKE :q
        OR COALESCE(l.description, '') LIKE :q
        OR COALESCE(u.name, '') LIKE :q
        OR COALESCE(u.username, '') LIKE :q
      )
    ";
    $params[':q'] = '%' . $q . '%';
}

$sql .= "
    ORDER BY COALESCE(l.started_at, l.updated_at) DESC, l.id DESC
    LIMIT 50
";

try {
    $st = $dbh->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as $row) {
        $item = mobile_live_item($row, $meId);
        if ($item['id'] > 0) {
            $items[] = $item;
        }
    }
    mobile_live_json(['ok' => true, 'items' => $items, 'scope' => $scope]);
} catch (Throwable $e) {
    mobile_live_json(['ok' => false, 'error' => 'Unable to load live sessions']);
}
