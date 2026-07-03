<?php
declare(strict_types=1);

if (!function_exists('postCategorySlugify')) {
    function postCategorySlugify(string $value): string {
        $value = trim(mb_strtolower($value));
        $value = preg_replace('/[^a-z0-9]+/i', '-', $value) ?? $value;
        $value = trim($value, '-');
        return $value !== '' ? $value : 'category';
    }
}

if (!function_exists('postCategoryDefaults')) {
    function postCategoryDefaults(): array {
        return [
            ['name' => 'Video Category', 'slug' => 'video-category', 'category_type' => 'video'],
            ['name' => 'Photo Category', 'slug' => 'photo-category', 'category_type' => 'photo'],
            ['name' => 'Topic Category', 'slug' => 'topic-category', 'category_type' => 'topic'],
            ['name' => 'Mixed Category', 'slug' => 'mixed-category', 'category_type' => 'mixed'],
            ['name' => 'File Category', 'slug' => 'file-category', 'category_type' => 'file'],
        ];
    }
}

if (!function_exists('ensurePostCategorySchema')) {
    function ensurePostCategorySchema(PDO $dbh): void {
        static $done = false;
        if ($done) return;
        $done = true;

        $dbh->exec("
            CREATE TABLE IF NOT EXISTS user_post_categories (
              id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
              user_id INT UNSIGNED NOT NULL,
              name VARCHAR(120) NOT NULL,
              slug VARCHAR(140) NOT NULL,
              category_type VARCHAR(24) NOT NULL DEFAULT 'topic',
              is_system TINYINT(1) NOT NULL DEFAULT 0,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              UNIQUE KEY uniq_user_slug (user_id, slug),
              KEY idx_user_type (user_id, category_type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $colStmt = $dbh->query("SHOW COLUMNS FROM public_posts LIKE 'category_id'");
        $hasCategoryId = (bool)($colStmt && $colStmt->fetch(PDO::FETCH_ASSOC));
        if (!$hasCategoryId) {
            $dbh->exec("ALTER TABLE public_posts ADD COLUMN category_id INT UNSIGNED NULL");
        }

        try {
            $idxStmt = $dbh->query("SHOW INDEX FROM public_posts WHERE Key_name = 'idx_category_id'");
            $hasIndex = (bool)($idxStmt && $idxStmt->fetch(PDO::FETCH_ASSOC));
            if (!$hasIndex) {
                $dbh->exec("ALTER TABLE public_posts ADD INDEX idx_category_id (category_id)");
            }
        } catch (Throwable $e) {
            // keep feature working even if index creation is unavailable
        }
    }
}

if (!function_exists('seedUserPostCategories')) {
    function seedUserPostCategories(PDO $dbh, int $userId): void {
        if ($userId <= 0) return;
        ensurePostCategorySchema($dbh);
        $st = $dbh->prepare("
            INSERT INTO user_post_categories (user_id, name, slug, category_type, is_system, created_at, updated_at)
            VALUES (:uid, :name, :slug, :ctype, 1, NOW(), NOW())
            ON DUPLICATE KEY UPDATE
              name = VALUES(name),
              category_type = VALUES(category_type),
              is_system = VALUES(is_system),
              updated_at = updated_at
        ");
        foreach (postCategoryDefaults() as $row) {
            $st->execute([
                ':uid' => $userId,
                ':name' => $row['name'],
                ':slug' => $row['slug'],
                ':ctype' => $row['category_type'],
            ]);
        }
    }
}

if (!function_exists('fetchUserPostCategories')) {
    function fetchUserPostCategories(PDO $dbh, int $userId): array {
        if ($userId <= 0) return [];
        ensurePostCategorySchema($dbh);
        seedUserPostCategories($dbh, $userId);
        $st = $dbh->prepare("
            SELECT id, user_id, name, slug, category_type, is_system, created_at, updated_at
            FROM user_post_categories
            WHERE user_id = :uid
            ORDER BY is_system DESC, name ASC, id ASC
        ");
        $st->execute([':uid' => $userId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

if (!function_exists('createUserPostCategory')) {
    function createUserPostCategory(PDO $dbh, int $userId, string $name, string $categoryType): int {
        ensurePostCategorySchema($dbh);
        seedUserPostCategories($dbh, $userId);

        $name = trim($name);
        if ($userId <= 0 || $name === '') return 0;
        if (!in_array($categoryType, ['video', 'photo', 'topic', 'mixed', 'file'], true)) {
            $categoryType = 'topic';
        }

        $baseSlug = postCategorySlugify($name);
        $slug = $baseSlug;
        $suffix = 2;

        $stExists = $dbh->prepare("SELECT id FROM user_post_categories WHERE user_id = :uid AND slug = :slug LIMIT 1");
        while (true) {
            $stExists->execute([':uid' => $userId, ':slug' => $slug]);
            if (!$stExists->fetchColumn()) break;
            $slug = $baseSlug . '-' . $suffix;
            $suffix++;
        }

        $st = $dbh->prepare("
            INSERT INTO user_post_categories (user_id, name, slug, category_type, is_system, created_at, updated_at)
            VALUES (:uid, :name, :slug, :ctype, 0, NOW(), NOW())
        ");
        $st->execute([
            ':uid' => $userId,
            ':name' => $name,
            ':slug' => $slug,
            ':ctype' => $categoryType,
        ]);
        return (int)$dbh->lastInsertId();
    }
}

if (!function_exists('resolveDefaultCategorySlugForType')) {
    function resolveDefaultCategorySlugForType(string $type): string {
        switch ($type) {
            case 'video': return 'video-category';
            case 'photo': return 'photo-category';
            case 'mixed': return 'mixed-category';
            case 'file': return 'file-category';
            case 'topic':
            default: return 'topic-category';
        }
    }
}

if (!function_exists('resolveUserPostCategoryId')) {
    function resolveUserPostCategoryId(PDO $dbh, int $userId, int $requestedCategoryId, string $detectedType): int {
        ensurePostCategorySchema($dbh);
        seedUserPostCategories($dbh, $userId);

        if ($requestedCategoryId > 0) {
            $st = $dbh->prepare("SELECT id FROM user_post_categories WHERE id = :id AND user_id = :uid LIMIT 1");
            $st->execute([':id' => $requestedCategoryId, ':uid' => $userId]);
            $found = (int)$st->fetchColumn();
            if ($found > 0) return $found;
        }

        $slug = resolveDefaultCategorySlugForType($detectedType);
        $st = $dbh->prepare("SELECT id FROM user_post_categories WHERE user_id = :uid AND slug = :slug LIMIT 1");
        $st->execute([':uid' => $userId, ':slug' => $slug]);
        $found = (int)$st->fetchColumn();
        if ($found > 0) return $found;

        $stAny = $dbh->prepare("SELECT id FROM user_post_categories WHERE user_id = :uid ORDER BY is_system DESC, id ASC LIMIT 1");
        $stAny->execute([':uid' => $userId]);
        return (int)$stAny->fetchColumn();
    }
}

if (!function_exists('detectPostCategoryType')) {
    function detectPostCategoryType(array $attachmentTypes, bool $hasText): string {
        $types = array_values(array_filter(array_map(static function ($v): string {
            return trim((string)$v);
        }, $attachmentTypes)));

        if (empty($types)) {
            return $hasText ? 'topic' : 'topic';
        }

        $hasVideo = in_array('video', $types, true);
        $hasImage = in_array('image', $types, true);
        $hasOther = count(array_diff($types, ['video', 'image'])) > 0;

        if ($hasVideo && !$hasImage && !$hasOther) return 'video';
        if ($hasImage && !$hasVideo && !$hasOther) return 'photo';
        if ($hasOther && !$hasImage && !$hasVideo) return 'file';
        return 'mixed';
    }
}

if (!function_exists('postCategoryTypeLabel')) {
    function postCategoryTypeLabel(string $type): string {
        switch ($type) {
            case 'video': return 'Video';
            case 'photo': return 'Photo';
            case 'topic': return 'Topic';
            case 'mixed': return 'Mixed';
            case 'file': return 'File';
            default: return 'Category';
        }
    }
}
