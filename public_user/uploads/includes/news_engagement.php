<?php
declare(strict_types=1);

/**
 * News (X/Twitter) reactions/comments — JSON files only (no database tables).
 */

function news_engagement_dir(): string
{
    $dir = dirname(__DIR__) . '/storage/cache/news_engagement';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir;
}

function news_engagement_safe_id(string $itemId): string
{
    $itemId = trim($itemId);
    if ($itemId === '') {
        return 'unknown';
    }
    return preg_replace('/[^a-z0-9:._-]+/i', '_', $itemId) ?? 'unknown';
}

function news_engagement_path(string $itemId): string
{
    return news_engagement_dir() . '/' . news_engagement_safe_id($itemId) . '.json';
}

function news_engagement_default(): array
{
    return [
        'love_count' => 0,
        'like_count' => 0,
        'comment_count' => 0,
        'user_reactions' => [],
        'comments' => [],
    ];
}

function news_engagement_read(string $itemId): array
{
    $path = news_engagement_path($itemId);
    if (!is_file($path)) {
        return news_engagement_default();
    }
    $raw = @file_get_contents($path);
    if (!is_string($raw) || $raw === '') {
        return news_engagement_default();
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return news_engagement_default();
    }
    return array_merge(news_engagement_default(), $data);
}

function news_engagement_write(string $itemId, array $data): void
{
    $data = array_merge(news_engagement_default(), $data);
    $data['comment_count'] = count($data['comments'] ?? []);
    @file_put_contents(
        news_engagement_path($itemId),
        json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}',
        LOCK_EX
    );
}

function news_engagement_recount(array $data): array
{
    $userReactions = is_array($data['user_reactions'] ?? null) ? $data['user_reactions'] : [];
    $love = 0;
    $like = 0;
    foreach ($userReactions as $reaction) {
        $reaction = strtolower(trim((string)$reaction));
        if ($reaction === 'love') {
            $love++;
        } elseif ($reaction === 'like') {
            $like++;
        }
    }
    $data['love_count'] = $love;
    $data['like_count'] = $like;
    $data['comment_count'] = count(is_array($data['comments'] ?? null) ? $data['comments'] : []);
    return $data;
}

function news_engagement_for_user(array $data, int $userId): array
{
    $uid = (string)$userId;
    $userReactions = is_array($data['user_reactions'] ?? null) ? $data['user_reactions'] : [];
    $myReaction = strtolower(trim((string)($userReactions[$uid] ?? '')));
    return [
        'love_count' => (int)($data['love_count'] ?? 0),
        'like_count' => (int)($data['like_count'] ?? 0),
        'comment_count' => (int)($data['comment_count'] ?? 0),
        'my_reaction' => in_array($myReaction, ['love', 'like'], true) ? $myReaction : '',
    ];
}

function news_engagement_attach_item(array $item, int $userId): array
{
    $itemId = trim((string)($item['id'] ?? ''));
    if ($itemId === '') {
        return $item;
    }
    $eng = news_engagement_for_user(news_engagement_read($itemId), $userId);
    return array_merge($item, $eng);
}

function news_engagement_attach_many(array $items, int $userId): array
{
    return array_map(static fn(array $it): array => news_engagement_attach_item($it, $userId), $items);
}

function news_engagement_react(string $itemId, int $userId, string $reaction): array
{
    $itemId = trim($itemId);
    if ($itemId === '' || $userId <= 0) {
        return news_engagement_for_user(news_engagement_default(), $userId);
    }
    $reaction = strtolower(trim($reaction));
    if (!in_array($reaction, ['love', 'like', ''], true)) {
        $reaction = '';
    }
    $data = news_engagement_read($itemId);
    $uid = (string)$userId;
    $userReactions = is_array($data['user_reactions'] ?? null) ? $data['user_reactions'] : [];
    $current = strtolower(trim((string)($userReactions[$uid] ?? '')));

    if ($reaction === '') {
        unset($userReactions[$uid]);
    } elseif ($current === $reaction) {
        unset($userReactions[$uid]);
    } else {
        $userReactions[$uid] = $reaction;
    }

    $data['user_reactions'] = $userReactions;
    $data = news_engagement_recount($data);
    news_engagement_write($itemId, $data);
    return news_engagement_for_user($data, $userId);
}

function news_engagement_add_comment(string $itemId, int $userId, string $author, string $text): array
{
    $itemId = trim($itemId);
    $text = trim($text);
    if ($itemId === '' || $userId <= 0 || $text === '') {
        return [];
    }
    $data = news_engagement_read($itemId);
    $comments = is_array($data['comments'] ?? null) ? $data['comments'] : [];
    $comment = [
        'id' => 'c' . substr(sha1($itemId . '|' . $userId . '|' . microtime(true)), 0, 12),
        'user_id' => $userId,
        'author' => trim($author) !== '' ? trim($author) : 'User',
        'text' => mb_substr($text, 0, 1200),
        'created_at' => gmdate('c'),
    ];
    $comments[] = $comment;
    $data['comments'] = $comments;
    $data = news_engagement_recount($data);
    news_engagement_write($itemId, $data);
    return $comment;
}

function news_engagement_get_comments(string $itemId): array
{
    $data = news_engagement_read(trim($itemId));
    $comments = is_array($data['comments'] ?? null) ? $data['comments'] : [];
    usort($comments, static fn(array $a, array $b): int => strcmp((string)($a['created_at'] ?? ''), (string)($b['created_at'] ?? '')));
    return $comments;
}
