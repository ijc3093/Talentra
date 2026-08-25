<?php
declare(strict_types=1);

function profile_cover_slides_ensure_schema(PDO $dbh): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $dbh->exec(
            "CREATE TABLE IF NOT EXISTS user_cover_slides (
                id INT NOT NULL AUTO_INCREMENT,
                user_id INT NOT NULL,
                image_path VARCHAR(255) NOT NULL,
                sort_order INT NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_user_cover_slides_user (user_id, sort_order, id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    } catch (Throwable $e) {
        // table may already exist
    }
}

function profile_cover_slide_public_url(string $path): string
{
    $path = ltrim(str_replace('\\', '/', trim($path)), '/');
    if ($path === '') {
        return '';
    }
    if (preg_match('#^https?://#i', $path)) {
        return $path;
    }
    return $path;
}

/**
 * @return list<array{id:int,path:string,url:string}>
 */
function profile_cover_slides_for_user(PDO $dbh, int $userId, string $fallbackCover = ''): array
{
    profile_cover_slides_ensure_schema($dbh);
    $slides = [];
    if ($userId > 0) {
        try {
            $st = $dbh->prepare(
                'SELECT id, image_path FROM user_cover_slides WHERE user_id = :uid ORDER BY sort_order ASC, id ASC'
            );
            $st->execute([':uid' => $userId]);
            while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                $path = profile_cover_slide_public_url((string)($row['image_path'] ?? ''));
                if ($path === '') {
                    continue;
                }
                $slides[] = [
                    'id' => (int)($row['id'] ?? 0),
                    'path' => $path,
                    'url' => $path,
                ];
            }
        } catch (Throwable $e) {
            $slides = [];
        }
    }
    if ($slides === []) {
        $fallback = profile_cover_slide_public_url($fallbackCover);
        if ($fallback !== '') {
            $slides[] = [
                'id' => 0,
                'path' => $fallback,
                'url' => $fallback,
            ];
        }
    }
    return $slides;
}

function profile_cover_slides_next_sort(PDO $dbh, int $userId): int
{
    try {
        $st = $dbh->prepare('SELECT COALESCE(MAX(sort_order), -1) FROM user_cover_slides WHERE user_id = :uid');
        $st->execute([':uid' => $userId]);
        return ((int)$st->fetchColumn()) + 1;
    } catch (Throwable $e) {
        return 0;
    }
}

function profile_cover_slides_max(): int
{
    return 40;
}

function profile_cover_slides_count(PDO $dbh, int $userId): int
{
    try {
        $st = $dbh->prepare('SELECT COUNT(*) FROM user_cover_slides WHERE user_id = :uid');
        $st->execute([':uid' => $userId]);
        return (int)$st->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function profile_cover_slides_add(PDO $dbh, int $userId, string $path, string $previousCover = ''): bool
{
    profile_cover_slides_ensure_schema($dbh);
    $path = profile_cover_slide_public_url($path);
    if ($userId <= 0 || $path === '') {
        return false;
    }
    try {
        if (profile_cover_slides_count($dbh, $userId) === 0) {
            $prev = profile_cover_slide_public_url($previousCover);
            if ($prev !== '' && $prev !== $path) {
                $insPrev = $dbh->prepare(
                    'INSERT INTO user_cover_slides (user_id, image_path, sort_order) VALUES (:uid, :path, 0)'
                );
                $insPrev->execute([':uid' => $userId, ':path' => $prev]);
            }
        }
        if (profile_cover_slides_count($dbh, $userId) >= profile_cover_slides_max()) {
            return false;
        }
        $sort = profile_cover_slides_next_sort($dbh, $userId);
        $ins = $dbh->prepare(
            'INSERT INTO user_cover_slides (user_id, image_path, sort_order) VALUES (:uid, :path, :sort)'
        );
        $ins->execute([':uid' => $userId, ':path' => $path, ':sort' => $sort]);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * @param list<string> $paths
 */
function profile_cover_slides_add_many(PDO $dbh, int $userId, array $paths, string $previousCover = ''): void
{
    profile_cover_slides_ensure_schema($dbh);
    $clean = [];
    foreach ($paths as $path) {
        $path = profile_cover_slide_public_url((string)$path);
        if ($path !== '') {
            $clean[] = $path;
        }
    }
    if ($userId <= 0 || $clean === []) {
        return;
    }
    $count = profile_cover_slides_count($dbh, $userId);
    $max = profile_cover_slides_max();
    $sort = profile_cover_slides_next_sort($dbh, $userId);
    if ($count === 0) {
        $prev = profile_cover_slide_public_url($previousCover);
        if ($prev !== '' && $prev !== $clean[0]) {
            $insPrev = $dbh->prepare(
                'INSERT INTO user_cover_slides (user_id, image_path, sort_order) VALUES (:uid, :path, 0)'
            );
            $insPrev->execute([':uid' => $userId, ':path' => $prev]);
            $count = 1;
            $sort = 1;
        }
    }
    $clean = array_slice($clean, 0, max(0, $max - $count));
    if ($clean === []) {
        return;
    }
    $parts = [];
    $params = [];
    foreach ($clean as $i => $path) {
        $parts[] = '(:uid' . $i . ', :path' . $i . ', :sort' . $i . ')';
        $params[':uid' . $i] = $userId;
        $params[':path' . $i] = $path;
        $params[':sort' . $i] = $sort + $i;
    }
    $ins = $dbh->prepare(
        'INSERT INTO user_cover_slides (user_id, image_path, sort_order) VALUES ' . implode(',', $parts)
    );
    $ins->execute($params);
}

function profile_cover_slides_delete(PDO $dbh, int $userId, int $slideId): string
{
    profile_cover_slides_ensure_schema($dbh);
    if ($userId <= 0 || $slideId <= 0) {
        return '';
    }
    try {
        $st = $dbh->prepare('SELECT image_path FROM user_cover_slides WHERE id = :id AND user_id = :uid LIMIT 1');
        $st->execute([':id' => $slideId, ':uid' => $userId]);
        $path = profile_cover_slide_public_url((string)$st->fetchColumn());
        if ($path === '') {
            return '';
        }
        $del = $dbh->prepare('DELETE FROM user_cover_slides WHERE id = :id AND user_id = :uid LIMIT 1');
        $del->execute([':id' => $slideId, ':uid' => $userId]);
        $remain = profile_cover_slides_for_user($dbh, $userId, '');
        $nextCover = $remain[0]['path'] ?? '';
        try {
            $up = $dbh->prepare('UPDATE user_profile_settings SET cover_image_path = :path WHERE user_id = :uid');
            $up->execute([':path' => $nextCover, ':uid' => $userId]);
        } catch (Throwable $e) {
            // keep delete even if settings update fails
        }
        return $path;
    } catch (Throwable $e) {
        return '';
    }
}

/**
 * @param list<int> $ids
 * @return list<array{id:int,path:string,url:string}>
 */
function profile_cover_slides_delete_many(PDO $dbh, int $userId, array $ids): array
{
    $clearFallback = false;
    $realIds = [];
    foreach ($ids as $id) {
        $id = (int)$id;
        if ($id > 0) {
            $realIds[] = $id;
        } elseif ($id === 0) {
            $clearFallback = true;
        }
    }
    $realIds = array_values(array_unique($realIds));
    foreach ($realIds as $id) {
        profile_cover_slides_delete($dbh, $userId, $id);
    }
    if ($clearFallback && profile_cover_slides_count($dbh, $userId) === 0) {
        try {
            $up = $dbh->prepare("UPDATE user_profile_settings SET cover_image_path = '' WHERE user_id = :uid");
            $up->execute([':uid' => $userId]);
        } catch (Throwable $e) {
            // ignore
        }
    }
    return profile_cover_slides_for_user($dbh, $userId, '');
}
