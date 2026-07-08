<?php
declare(strict_types=1);

require_once __DIR__ . '/post_layout.php';

/**
 * Build TTStories catalog rows from post arrays (feed.php / public.php parity).
 *
 * @param array<int,array<string,mixed>> $posts
 * @return array<int,array<string,mixed>>
 */
function story_catalog_build_from_posts(array $posts, callable $timeAgoFn): array
{
    $byUser = [];

    foreach ($posts as $post) {
        if (!is_array($post) || !post_is_story_only($post)) {
            continue;
        }

        $attachments = is_array($post['attachments'] ?? null) ? $post['attachments'] : [];
        $first = $attachments[0] ?? null;
        $uid = (int)($post['user_id'] ?? 0);
        if ($uid <= 0) {
            continue;
        }

        [$src, $mediaType] = story_catalog_media_from_attachment(is_array($first) ? $first : null);
        $caption = post_story_caption($post);
        if ($src === '' && $caption === '') {
            continue;
        }

        $key = 'u' . $uid;
        $friendCode = strtoupper(trim((string)($post['friend_code'] ?? '')));
        $isPublisher = story_catalog_item_is_publisher($post);

        if (!isset($byUser[$key])) {
            $byUser[$key] = [
                'key' => $key,
                'userId' => $uid,
                'name' => trim((string)($post['display_name'] ?? $post['username'] ?? 'User')),
                'username' => trim((string)($post['username'] ?? '')),
                'friendCode' => $friendCode,
                'account_kind' => $isPublisher ? 'publisher' : (string)($post['account_kind'] ?? 'personal'),
                'verified' => $isPublisher || !empty($post['is_verified']),
                'isPublisher' => $isPublisher,
                'is_publisher' => $isPublisher ? 1 : 0,
                'avatarUrl' => story_catalog_avatar_url($post, 96),
                'subtitle' => '',
                'slides' => [],
            ];
        }

        $storyWhen = (string)($post['updated_at'] ?? $post['created_at'] ?? '');
        $whenLabel = $timeAgoFn($storyWhen);

        $byUser[$key]['slides'][] = [
            'src' => $src,
            'type' => $src !== '' ? story_catalog_preview_kind($src, $mediaType) : 'text',
            'title' => trim((string)($post['title'] ?? '')),
            'caption' => $caption,
            'timeLabel' => $whenLabel,
            'timeAgo' => $whenLabel,
            'createdAt' => $storyWhen,
            'postId' => (int)($post['id'] ?? 0),
            'myReaction' => trim((string)($post['my_reaction'] ?? '')),
            'myShared' => !empty($post['my_shared']) ? 1 : 0,
            'mySaved' => !empty($post['my_saved']) ? 1 : 0,
            'isArchived' => !empty($post['is_archived']) ? 1 : 0,
            'commentCount' => (int)($post['comment_count'] ?? 0),
            'loveCount' => (int)($post['love_count'] ?? 0),
            'shareCount' => (int)($post['share_count'] ?? 0),
            'saveCount' => (int)($post['save_count'] ?? 0),
            'friendCode' => $friendCode !== '' ? $friendCode : (string)($byUser[$key]['friendCode'] ?? ''),
            'previewType' => (string)$mediaType,
        ];
    }

    return array_values(array_filter($byUser, static function (array $story): bool {
        return !empty($story['slides']);
    }));
}

function story_catalog_item_is_publisher(array $post): bool
{
    if (strtolower(trim((string)($post['account_kind'] ?? ''))) === 'publisher') {
        return true;
    }
    $friendCode = strtoupper(trim((string)($post['friend_code'] ?? '')));
    return str_starts_with($friendCode, 'PUB-');
}

/**
 * @return array{0:string,1:string}
 */
function story_catalog_media_from_attachment(?array $attachment): array
{
    $attachment = is_array($attachment) ? $attachment : [];
    $mediaType = (string)($attachment['type'] ?? 'image');
    $src = trim((string)($attachment['thumb_path'] ?? ''));
    if ($src === '') {
        $src = trim((string)($attachment['file_path'] ?? ''));
    }
    $src = ltrim(preg_replace('~^\./~', '', $src), '/');
    return [$src, $mediaType];
}

function story_catalog_preview_kind(string $src, string $type): string
{
    $type = strtolower(trim($type));
    if (in_array($type, ['video', 'image', 'gif'], true)) {
        return $type === 'gif' ? 'image' : $type;
    }
    $lower = strtolower(pathinfo(parse_url($src, PHP_URL_PATH) ?: $src, PATHINFO_EXTENSION));
    if (in_array($lower, ['mp4', 'webm', 'ogg', 'mov', 'm4v'], true)) {
        return 'video';
    }
    return 'image';
}

function story_catalog_avatar_url(array $post, int $size = 96): string
{
    if (function_exists('user_avatar_url')) {
        return (string)user_avatar_url($post, $size);
    }
    if (function_exists('avatarUrlFor')) {
        return (string)avatarUrlFor($post, $size);
    }
    $uid = (int)($post['user_id'] ?? 0);
    return 'avatar.php?u=' . $uid . '&s=' . $size;
}
