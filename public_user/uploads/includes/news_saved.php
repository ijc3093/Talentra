<?php
declare(strict_types=1);

/**
 * Saved X news posts per user — JSON only (bookmark list, not tweet archive in DB).
 */

function news_saved_dir(): string
{
    $dir = dirname(__DIR__) . '/storage/cache/news_saved';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir;
}

function news_saved_path(int $userId): string
{
    return news_saved_dir() . '/user_' . max(0, $userId) . '.json';
}

function news_saved_read(int $userId): array
{
    if ($userId <= 0) {
        return [];
    }
    $path = news_saved_path($userId);
    if (!is_file($path)) {
        return [];
    }
    $raw = @file_get_contents($path);
    if (!is_string($raw) || $raw === '') {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? array_values(array_unique(array_map('strval', $data))) : [];
}

function news_saved_write(int $userId, array $itemIds): void
{
    if ($userId <= 0) {
        return;
    }
    $clean = [];
    foreach ($itemIds as $id) {
        $id = trim((string)$id);
        if ($id !== '') {
            $clean[$id] = true;
        }
    }
    @file_put_contents(
        news_saved_path($userId),
        json_encode(array_values(array_keys($clean)), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]',
        LOCK_EX
    );
}

function news_saved_toggle(int $userId, string $itemId): array
{
    $itemId = trim($itemId);
    if ($userId <= 0 || $itemId === '') {
        return ['ok' => false, 'saved' => false];
    }
    $ids = news_saved_read($userId);
    $saved = in_array($itemId, $ids, true);
    if ($saved) {
        $ids = array_values(array_filter($ids, static fn(string $id): bool => $id !== $itemId));
    } else {
        $ids[] = $itemId;
    }
    news_saved_write($userId, $ids);
    return ['ok' => true, 'saved' => !$saved, 'item_id' => $itemId];
}

function news_saved_attach_many(array $items, int $userId): array
{
    if ($userId <= 0) {
        return $items;
    }
    $saved = array_flip(news_saved_read($userId));
    return array_map(static function (array $item) use ($saved): array {
        $id = trim((string)($item['id'] ?? ''));
        $item['saved'] = $id !== '' && isset($saved[$id]);
        return $item;
    }, $items);
}
