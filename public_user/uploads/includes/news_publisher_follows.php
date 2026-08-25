<?php
declare(strict_types=1);

require_once __DIR__ . '/news_publishers.php';

/**
 * Which X publishers a user follows — JSON file per user (not tweet content, not messages).
 */

function news_publisher_follows_dir(): string
{
    $dir = dirname(__DIR__) . '/storage/cache/news_publisher_follows';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir;
}

function news_publisher_follows_path(int $userId): string
{
    return news_publisher_follows_dir() . '/user_' . max(0, $userId) . '.json';
}

function news_publisher_follows_read(int $userId): array
{
    if ($userId <= 0) {
        return [];
    }
    $path = news_publisher_follows_path($userId);
    if (!is_file($path)) {
        return [];
    }
    $raw = @file_get_contents($path);
    if (!is_string($raw) || $raw === '') {
        return [];
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return [];
    }
    $catalog = news_publishers_catalog();
    $keys = [];
    foreach ($data as $key) {
        $key = strtolower(trim((string)$key));
        if ($key !== '' && isset($catalog[$key])) {
            $keys[$key] = true;
        }
    }
    return array_keys($keys);
}

function news_publisher_follows_write(int $userId, array $keys): void
{
    if ($userId <= 0) {
        return;
    }
    $catalog = news_publishers_catalog();
    $clean = [];
    foreach ($keys as $key) {
        $key = strtolower(trim((string)$key));
        if ($key !== '' && isset($catalog[$key])) {
            $clean[$key] = true;
        }
    }
    @file_put_contents(
        news_publisher_follows_path($userId),
        json_encode(array_values(array_keys($clean)), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]',
        LOCK_EX
    );
}

function news_publisher_is_followed(int $userId, string $publisherKey): bool
{
    $publisherKey = strtolower(trim($publisherKey));
    if ($publisherKey === '') {
        return false;
    }
    return in_array($publisherKey, news_publisher_follows_read($userId), true);
}

function news_publisher_follow_toggle(int $userId, string $publisherKey): array
{
    $publisherKey = strtolower(trim($publisherKey));
    if ($userId <= 0 || $publisherKey === '' || news_publisher_key($publisherKey) === null) {
        return ['ok' => false, 'following' => false, 'publisher_key' => $publisherKey];
    }
    $keys = news_publisher_follows_read($userId);
    $following = in_array($publisherKey, $keys, true);
    if ($following) {
        $keys = array_values(array_filter($keys, static fn(string $k): bool => $k !== $publisherKey));
    } else {
        $keys[] = $publisherKey;
    }
    news_publisher_follows_write($userId, $keys);
    return [
        'ok' => true,
        'following' => !$following,
        'publisher_key' => $publisherKey,
        'followed' => news_publisher_follows_read($userId),
    ];
}

function news_publisher_follows_with_meta(int $userId): array
{
    $followed = news_publisher_follows_read($userId);
    $catalog = news_publishers_catalog();
    $rows = [];
    foreach ($catalog as $key => $row) {
        $rows[] = [
            'key' => $key,
            'handle' => (string)$row['handle'],
            'label' => (string)$row['label'],
            'category' => (string)($row['category'] ?? 'news'),
            'avatar' => news_publisher_avatar_url((string)$row['handle']),
            'following' => in_array($key, $followed, true),
        ];
    }
    return $rows;
}
