<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/session_user.php';
requireUserLogin();
require_once __DIR__ . '/../controller.php';
require_once __DIR__ . '/../includes/friend_system.php';
require_once __DIR__ . '/../includes/publisher_accounts.php';
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
$meTagged = msb_user_is_tagged_on_post($dbh, $postId, $meId);
$canSelfTag = msb_viewer_can_self_tag_post($dbh, $meId, $post);
$isStoryPost = function_exists('msb_post_is_story_id') && msb_post_is_story_id($dbh, $postId);
$viewerFriendsWithOwner = $isOwner;
if (!$viewerFriendsWithOwner && $ownerId > 0 && function_exists('fs_are_friends')) {
    $viewerFriendsWithOwner = fs_are_friends($dbh, $meId, $ownerId);
} elseif (!$viewerFriendsWithOwner && $ownerId > 0) {
    require_once __DIR__ . '/../includes/friend_system.php';
    $viewerFriendsWithOwner = function_exists('fs_are_friends') && fs_are_friends($dbh, $meId, $ownerId);
}

function pcm_tag_users_payload(PDO $dbh, int $postId): array
{
    if (function_exists('msb_post_tags_people_for_post')) {
        return msb_post_tags_people_for_post($dbh, $postId);
    }
    return [];
}

if ($action === 'list' || $action === 'get') {
    echo json_encode([
        'ok' => true,
        'post_id' => $postId,
        'is_owner' => $isOwner,
        'me_tagged' => $meTagged ? 1 : 0,
        'can_self_tag' => ($canSelfTag || $meTagged) ? 1 : 0,
        'users' => pcm_tag_users_payload($dbh, $postId),
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

// Friend (non-owner) adds/removes this post on their Tags tab.
if ($action === 'self_toggle' || $action === 'self_add' || $action === 'self_remove') {
    if ($isOwner) {
        echo json_encode(['ok' => false, 'error' => 'owner_use_tag_people']);
        exit;
    }
    $wantAdd = $action === 'self_add' ? true : ($action === 'self_remove' ? false : !$meTagged);
    if ($wantAdd && !$canSelfTag) {
        echo json_encode(['ok' => false, 'error' => 'forbidden']);
        exit;
    }
    if (!$wantAdd && !$meTagged) {
        echo json_encode([
            'ok' => true,
            'post_id' => $postId,
            'me_tagged' => 0,
            'users' => pcm_tag_users_payload($dbh, $postId),
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }
    if (!msb_post_tags_self_set($dbh, $postId, $meId, $wantAdd)) {
        echo json_encode(['ok' => false, 'error' => 'save_failed']);
        exit;
    }
    $meTaggedNow = msb_user_is_tagged_on_post($dbh, $postId, $meId);
    echo json_encode([
        'ok' => true,
        'post_id' => $postId,
        'me_tagged' => $meTaggedNow ? 1 : 0,
        'users' => pcm_tag_users_payload($dbh, $postId),
        'message' => $meTaggedNow ? 'Saved to your Tags.' : 'Removed from your Tags.',
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
        'me_tagged' => msb_user_is_tagged_on_post($dbh, $postId, $meId) ? 1 : 0,
        'users' => pcm_tag_users_payload($dbh, $postId),
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

// Fries → Mention: notify only (no Tags tab).
if ($action === 'mention' || $action === 'notify') {
    // On stories, only the owner or their friends may mention people
    // (prevents non-friends from kicking off owner DMs / alerts via story tools).
    if ($isStoryPost && !$isOwner && !$viewerFriendsWithOwner) {
        echo json_encode(['ok' => false, 'error' => 'friends_only']);
        exit;
    }
    $canMention = $isOwner
        || publisher_post_interaction_allowed($dbh, $meId, [
            'id' => $postId,
            'user_id' => $ownerId,
            'visibility' => $visibility,
        ]);
    if (!$canMention) {
        echo json_encode(['ok' => false, 'error' => 'forbidden']);
        exit;
    }

    $ids = [];
    if (isset($_POST['user_ids']) && is_array($_POST['user_ids'])) {
        foreach ($_POST['user_ids'] as $v) {
            $id = (int)$v;
            if ($id > 0) {
                $ids[] = $id;
            }
        }
    } else {
        $raw = trim((string)($_POST['user_ids'] ?? $_POST['tagged_user_ids'] ?? ''));
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
    $mentionText = trim((string)($_POST['mention_text'] ?? ''));
    if ($mentionText !== '') {
        $ids = msb_mention_ids_from_texts($dbh, [$mentionText], $ids);
    }
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static function ($id) use ($meId) {
        return $id > 0 && $id !== $meId;
    })));
    if ($ids === []) {
        echo json_encode(['ok' => false, 'error' => 'no_people']);
        exit;
    }
    $notified = msb_post_mentions_notify($dbh, $meId, $ids, $postId, $visibility);
    echo json_encode([
        'ok' => true,
        'post_id' => $postId,
        'notified' => count($notified),
        'message' => count($notified) === 1
            ? 'Mention sent.'
            : ('Mentioned ' . count($notified) . ' people.'),
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

echo json_encode(['ok' => false, 'error' => 'bad_action']);
