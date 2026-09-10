<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/session_user.php';
requireUserLogin();
require_once __DIR__ . '/../controller.php';
require_once __DIR__ . '/../includes/post_tags.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$controller = new Controller();
$dbh = $controller->pdo();
$meId = (int)($_SESSION['user_id'] ?? 0);
if ($meId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'auth']);
    exit;
}

$action = strtolower(trim((string)($_POST['action'] ?? $_GET['action'] ?? 'list')));
$postId = (int)($_POST['post_id'] ?? $_GET['post_id'] ?? 0);
if ($postId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'missing_post']);
    exit;
}

try {
    $st = $dbh->prepare('SELECT id, user_id, visibility FROM public_posts WHERE id = :id AND is_deleted = 0 LIMIT 1');
    $st->execute([':id' => $postId]);
    $post = $st->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable $e) {
    $post = null;
}
if (!$post) {
    echo json_encode(['ok' => false, 'error' => 'not_found']);
    exit;
}

$ownerId = (int)($post['user_id'] ?? 0);
$visibility = strtolower(trim((string)($post['visibility'] ?? 'friends')));
$isOwner = ($ownerId > 0 && $ownerId === $meId);

function pcm_tag_users_payload(PDO $dbh, int $postId): array
{
    msb_post_tags_ensure_schema($dbh);
    try {
        $st = $dbh->prepare("
            SELECT u.id, u.username, u.name, COALESCE(NULLIF(u.image,''), '') AS image
            FROM public_post_tags t
            INNER JOIN users u ON u.id = t.tagged_user_id
            WHERE t.post_id = :pid AND u.status = 1
            ORDER BY u.username ASC
        ");
        $st->execute([':pid' => $postId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $rows = [];
    }
    $out = [];
    foreach ($rows as $r) {
        $id = (int)($r['id'] ?? 0);
        $username = trim((string)($r['username'] ?? ''));
        if ($id <= 0 || $username === '') {
            continue;
        }
        $out[] = [
            'id' => $id,
            'username' => $username,
            'name' => trim((string)($r['name'] ?? '')),
            'image' => trim((string)($r['image'] ?? '')),
        ];
    }
    return $out;
}

if ($action === 'list' || $action === 'get') {
    echo json_encode([
        'ok' => true,
        'post_id' => $postId,
        'is_owner' => $isOwner,
        'users' => pcm_tag_users_payload($dbh, $postId),
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'save' || $action === 'sync') {
    if (!$isOwner) {
        echo json_encode(['ok' => false, 'error' => 'forbidden']);
        exit;
    }
    $ids = [];
    if (isset($_POST['tagged_user_ids']) && is_array($_POST['tagged_user_ids'])) {
        foreach ($_POST['tagged_user_ids'] as $v) {
            $id = (int)$v;
            if ($id > 0) {
                $ids[] = $id;
            }
        }
    } else {
        $raw = trim((string)($_POST['tagged_user_ids'] ?? ''));
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                foreach ($decoded as $v) {
                    $id = (int)$v;
                    if ($id > 0) {
                        $ids[] = $id;
                    }
                }
            } else {
                foreach (preg_split('/[\s,]+/', $raw) ?: [] as $piece) {
                    $id = (int)$piece;
                    if ($id > 0) {
                        $ids[] = $id;
                    }
                }
            }
        }
    }
    $ids = array_values(array_unique($ids));
    // Also accept @usernames left in the Tag people input (chip miss / typed-only).
    $mentionText = trim((string)($_POST['mention_text'] ?? ''));
    if ($mentionText !== '') {
        $ids = msb_mention_ids_from_texts($dbh, [$mentionText], $ids);
    }
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static function ($id) {
        return $id > 0;
    })));
    msb_post_tags_sync($dbh, $postId, $meId, $ids, $visibility, true);
    echo json_encode([
        'ok' => true,
        'post_id' => $postId,
        'users' => pcm_tag_users_payload($dbh, $postId),
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

echo json_encode(['ok' => false, 'error' => 'bad_action']);
