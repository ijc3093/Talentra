<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/session_user.php';
requireUserLogin();
require_once __DIR__ . '/../controller.php';
require_once __DIR__ . '/../includes/friend_system.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function post_reactors_json(array $payload): void
{
    echo json_encode($payload);
    exit;
}

function post_reactors_avatar(int $uid, string $img): string
{
    $img = trim($img);
    if ($img !== '' && $img !== 'default.jpg' && $img !== 'default.png' && !preg_match('~^(https?:)?//~i', $img) && ($img[0] ?? '') !== '/') {
        $img = './' . ltrim($img, './');
    }
    if ($img === '' || $img === 'default.jpg' || $img === 'default.png') {
        return 'avatar.php?u=' . $uid . '&s=80';
    }
    return $img;
}

function post_reactors_rows(PDO $dbh, string $sql, int $postId): array
{
    try {
        $st = $dbh->prepare($sql);
        $st->execute([':pid' => $postId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

$meId = (int)($_SESSION['user_id'] ?? 0);
$postId = (int)($_GET['post_id'] ?? $_POST['post_id'] ?? 0);
$filter = strtolower(trim((string)($_GET['reaction'] ?? $_POST['tab'] ?? $_POST['reaction'] ?? 'all')));
if ($filter === '' || $filter === 'all') {
    $filter = 'all';
}

if ($meId <= 0 || $postId <= 0) {
    post_reactors_json(['ok' => false, 'error' => 'Invalid request.', 'counts' => [], 'people' => []]);
}

$dbh = (new Controller())->pdo();
$userSelect = "COALESCE(u.name, u.username, CONCAT('User ', u.id)) AS display_name,
               COALESCE(u.username, '') AS username, COALESCE(u.friend_code, '') AS friend_code,
               COALESCE(u.image, '') AS image";

$reactionRows = post_reactors_rows($dbh, "
    SELECT r.user_id, r.reaction, {$userSelect}
    FROM public_post_reactions r
    INNER JOIN users u ON u.id = r.user_id
    WHERE r.post_id = :pid
    ORDER BY r.user_id DESC
    LIMIT 200
", $postId);

$shareRows = post_reactors_rows($dbh, "
    SELECT s.user_id, {$userSelect}
    FROM public_post_shares s
    INNER JOIN users u ON u.id = s.user_id
    WHERE s.post_id = :pid
    ORDER BY s.id DESC
    LIMIT 200
", $postId);

$saveRows = post_reactors_rows($dbh, "
    SELECT s.user_id, {$userSelect}
    FROM public_post_saves s
    INNER JOIN users u ON u.id = s.user_id
    WHERE s.post_id = :pid
    ORDER BY s.saved_at DESC
    LIMIT 200
", $postId);

$entries = [];
foreach ($reactionRows as $row) {
    $kind = strtolower(trim((string)($row['reaction'] ?? '')));
    if ($kind === '') {
        continue;
    }
    $row['kind'] = $kind;
    $entries[] = $row;
}
foreach ($shareRows as $row) {
    $row['kind'] = 'share';
    $entries[] = $row;
}
foreach ($saveRows as $row) {
    $row['kind'] = 'save';
    $entries[] = $row;
}

$counts = [
    'love' => 0,
    'like' => 0,
    'dislike' => 0,
    'smile' => 0,
    'laugh' => 0,
    'wow' => 0,
    'sad' => 0,
    'angry' => 0,
    'clap' => 0,
    'share' => 0,
    'save' => 0,
];
foreach ($entries as $row) {
    $kind = (string)$row['kind'];
    $counts[$kind] = (int)($counts[$kind] ?? 0) + 1;
}

$people = [];
foreach ($entries as $row) {
    $kind = (string)$row['kind'];
    if ($filter !== 'all' && $kind !== $filter) {
        continue;
    }
    $uid = (int)($row['user_id'] ?? 0);
    if ($uid <= 0) {
        continue;
    }
    $friendStatus = 'none';
    try {
        $friendStatus = fs_friend_status($dbh, $meId, $uid);
    } catch (Throwable $e) {
        $friendStatus = 'none';
    }
    $people[] = [
        'id' => $uid,
        'name' => (string)($row['display_name'] ?? 'User'),
        'username' => (string)($row['username'] ?? ''),
        'friend_code' => (string)($row['friend_code'] ?? ''),
        'avatar' => post_reactors_avatar($uid, (string)($row['image'] ?? '')),
        'reaction' => $kind,
        'friend_status' => $friendStatus,
        'profile' => 'profile.php?id=' . $uid,
    ];
    if (count($people) >= 200) {
        break;
    }
}

post_reactors_json([
    'ok' => true,
    'post_id' => $postId,
    'counts' => $counts,
    'total' => array_sum($counts),
    'people' => $people,
]);
