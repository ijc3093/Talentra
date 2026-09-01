<?php
declare(strict_types=1);

/**
 * News / brand publisher accounts (CNN, Fox News, ABC, etc.) — real users rows, not Twitter.
 * Follow uses public_follows. Friend requests and DMs are blocked for publishers.
 */

if (function_exists('publisher_db_column_exists')) {
    return;
}

if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool
    {
        if ($needle === '') {
            return true;
        }
        return strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}

if (!function_exists('publisher_db_column_exists')) {
function publisher_db_column_exists(PDO $dbh, string $table, string $column): bool
{
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $column = preg_replace('/[^a-zA-Z0-9_]/', '', $column);
    if ($table === '' || $column === '') {
        return false;
    }

    try {
        $st = $dbh->prepare('
            SELECT 1
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = :table
              AND COLUMN_NAME = :column
            LIMIT 1
        ');
        $st->execute([
            ':table' => $table,
            ':column' => $column,
        ]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        try {
            $quotedColumn = $dbh->quote($column);
            $st = $dbh->query('SHOW COLUMNS FROM `' . $table . '` LIKE ' . $quotedColumn);
            return (bool)$st->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e2) {
            return false;
        }
    }
}
}

function publisher_ensure_schema(PDO $dbh): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    try {
        if (!publisher_db_column_exists($dbh, 'users', 'account_kind')) {
            try {
                $dbh->exec("ALTER TABLE users ADD COLUMN account_kind ENUM('personal','publisher') NOT NULL DEFAULT 'personal' AFTER role");
            } catch (Throwable $e) {
                $dbh->exec("ALTER TABLE users ADD COLUMN account_kind ENUM('personal','publisher') NOT NULL DEFAULT 'personal'");
            }
        }
        if (!publisher_db_column_exists($dbh, 'users', 'publisher_category')) {
            try {
                $dbh->exec("ALTER TABLE users ADD COLUMN publisher_category VARCHAR(40) NOT NULL DEFAULT '' AFTER account_kind");
            } catch (Throwable $e) {
                $dbh->exec("ALTER TABLE users ADD COLUMN publisher_category VARCHAR(40) NOT NULL DEFAULT ''");
            }
        }
        if (!publisher_db_column_exists($dbh, 'users', 'publisher_tagline')) {
            try {
                $dbh->exec("ALTER TABLE users ADD COLUMN publisher_tagline VARCHAR(255) NOT NULL DEFAULT '' AFTER publisher_category");
            } catch (Throwable $e) {
                $dbh->exec("ALTER TABLE users ADD COLUMN publisher_tagline VARCHAR(255) NOT NULL DEFAULT ''");
            }
        }
    } catch (Throwable $e) {
        // Non-fatal — registration falls back to legacy users insert.
    }
}

function publisher_is_publisher_row(array $row): bool
{
    return strtolower(trim((string)($row['account_kind'] ?? ''))) === 'publisher';
}

function publisher_user_row_looks_like_publisher(PDO $dbh, array $row): bool
{
    if (publisher_is_publisher_row($row)) {
        return true;
    }

    if (trim((string)($row['publisher_category'] ?? '')) !== '') {
        return true;
    }

    $friendCode = strtoupper(trim((string)($row['friend_code'] ?? '')));
    if (str_starts_with($friendCode, 'PUB-')) {
        return true;
    }

    $name = publisher_registry_normalize_name((string)($row['name'] ?? ''));
    if ($name !== '' && publisher_registry_name_is_registered($dbh, $name)) {
        return true;
    }

    return false;
}

function publisher_repair_user_as_publisher(PDO $dbh, int $userId, ?string $category = null): bool
{
    if ($userId <= 0) {
        return false;
    }

    publisher_ensure_schema($dbh);

    try {
        $st = $dbh->prepare('
            SELECT id, name, friend_code, account_kind, publisher_category, publisher_tagline
            FROM users
            WHERE id = :id
            LIMIT 1
        ');
        $st->execute([':id' => $userId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row || !publisher_user_row_looks_like_publisher($dbh, $row)) {
            return false;
        }

        if (publisher_is_publisher_row($row) && trim((string)($row['publisher_category'] ?? '')) !== '') {
            return true;
        }

        $name = publisher_registry_normalize_name((string)($row['name'] ?? ''));
        if ($category === null || $category === '') {
            $category = 'news';
            if ($name !== '') {
                try {
                    $stCat = $dbh->prepare('SELECT category FROM publisher_name_options WHERE name = :n LIMIT 1');
                    $stCat->execute([':n' => $name]);
                    $fromOption = strtolower(trim((string)($stCat->fetchColumn() ?: '')));
                    if ($fromOption !== '' && isset(publisher_categories()[$fromOption])) {
                        $category = $fromOption;
                    }
                } catch (Throwable $e) {
                    // keep default category
                }
            }
        }

        $category = strtolower(trim($category));
        if (!isset(publisher_categories()[$category])) {
            $category = 'news';
        }

        if (!publisher_db_column_exists($dbh, 'users', 'account_kind')) {
            return false;
        }

        $stUpdate = $dbh->prepare('
            UPDATE users
            SET account_kind = \'publisher\',
                publisher_category = :cat
            WHERE id = :id
        ');
        $stUpdate->execute([
            ':cat' => $category,
            ':id' => $userId,
        ]);

        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function publisher_is_publisher_user(PDO $dbh, int $userId): bool
{
    if ($userId <= 0) {
        return false;
    }
    publisher_ensure_schema($dbh);
    try {
        $st = $dbh->prepare('
            SELECT id, name, friend_code, account_kind, publisher_category
            FROM users
            WHERE id = :id
            LIMIT 1
        ');
        $st->execute([':id' => $userId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return false;
        }
        if (publisher_user_row_looks_like_publisher($dbh, $row)) {
            publisher_repair_user_as_publisher($dbh, $userId);
            return true;
        }
        return false;
    } catch (Throwable $e) {
        return false;
    }
}

function publisher_can_friend(PDO $dbh, int $peerId): bool
{
    return !publisher_is_publisher_user($dbh, $peerId);
}

function publisher_can_message(PDO $dbh, int $peerId): bool
{
    return !publisher_is_publisher_user($dbh, $peerId);
}

function publisher_categories_builtin(): array
{
    return [
        'commerce' => 'Commerce & restaurants',
        'entertainment' => 'Entertainment',
        'library' => 'Library',
        'cook' => 'Cook',
        'seek-around-the-world' => 'Seek around the World',
        'geology' => 'Geology',
        'animation' => 'Animation',
        'make-a-new-friend' => 'Make a new Friend',
        'deep-research' => 'Deep research',
        'enterprise' => 'Enterprise',
        'trending' => 'Trending',
        'news' => 'News',
        'sports' => 'Sports',
        'business' => 'Business',
        'science' => 'Science',
        'music' => 'Music',
        'arts' => 'Arts & Painting',
        'agriculture' => 'Agriculture',
        'auto' => 'Auto',
        'political' => 'Political',
        'english' => 'English',
        'mathematics' => 'Mathematics',
        'social-studies' => 'Social Studies',
        'special-classes' => 'Special Classes',
        'information-technology' => 'Information Technology',
        'design' => 'Design',
        'health-wellness-sciences' => 'Health, Wellness & Sciences',
        'environmental-sustainability' => 'Environmental Sustainability',
        'psychology' => 'Psychology',
        'engineering' => 'Engineering',
        'lawyer' => 'Lawyer',
        'astrobiology' => 'Astrobiology',
        'biology' => 'Biology',
        'economics' => 'Economics',
        'criminal-justice' => 'Criminal Justice',
        'marketing' => 'Marketing',
        'museum' => 'Museum',
        'philosophy' => 'Philosophy',
        'physics' => 'Physics',
        'vets' => 'Vets',
        'sociology-and-anthropology' => 'Sociology and Anthropology',
    ];
}

function publisher_category_reserved_slugs(): array
{
    return [
        'for-you' => true,
        'public' => true,
        'feed' => true,
        'agents' => true,
        'news-surface' => true,
    ];
}

function publisher_category_slugify(string $label): string
{
    $slug = strtolower(trim($label));
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
    $slug = trim($slug, '-');
    if (function_exists('mb_substr')) {
        return mb_substr($slug, 0, 40);
    }
    return substr($slug, 0, 40);
}

function publisher_custom_categories_ensure_schema(PDO $dbh): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $dbh->exec("
            CREATE TABLE IF NOT EXISTS publisher_category_options (
                slug VARCHAR(40) NOT NULL,
                label VARCHAR(80) NOT NULL,
                created_by_user_id INT UNSIGNED NULL DEFAULT NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (slug),
                KEY idx_publisher_category_label (label)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (Throwable $e) {
        // Non-fatal — custom categories stay unavailable until schema succeeds.
    }
}

/**
 * @return array<string,string> slug => label
 */
function publisher_custom_categories(PDO $dbh): array
{
    publisher_custom_categories_ensure_schema($dbh);
    try {
        $rows = $dbh->query('SELECT slug, label FROM publisher_category_options ORDER BY label ASC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
    $out = [];
    foreach ($rows as $row) {
        $slug = strtolower(trim((string)($row['slug'] ?? '')));
        $label = trim((string)($row['label'] ?? ''));
        if ($slug === '' || $label === '' || isset(publisher_category_reserved_slugs()[$slug])) {
            continue;
        }
        $out[$slug] = $label;
    }
    return $out;
}

function publisher_categories_reset_cache(): void
{
    $store = &publisher_categories_cache_store();
    $store['map'] = null;
    $store['loaded'] = false;
}

/**
 * @return array{map:?array<string,string>,loaded:bool}
 */
function &publisher_categories_cache_store(): array
{
    static $store = ['map' => null, 'loaded' => false];
    return $store;
}

/**
 * Built-in + publisher-added custom categories.
 *
 * @return array<string,string>
 */
function publisher_categories(?PDO $dbh = null): array
{
    $store = &publisher_categories_cache_store();
    if ($store['loaded'] && is_array($store['map'])) {
        return $store['map'];
    }

    $cats = publisher_categories_builtin();

    if (!($dbh instanceof PDO)) {
        try {
            if (!class_exists('Controller', false)) {
                $controllerPath = dirname(__DIR__) . '/controller.php';
                if (is_file($controllerPath)) {
                    require_once $controllerPath;
                }
            }
            if (class_exists('Controller', false)) {
                $dbh = (new Controller())->pdo();
            }
        } catch (Throwable $e) {
            $dbh = null;
        }
    }

    if ($dbh instanceof PDO) {
        foreach (publisher_custom_categories($dbh) as $slug => $label) {
            if (!isset($cats[$slug])) {
                $cats[$slug] = $label;
            }
        }
        $store['map'] = $cats;
        $store['loaded'] = true;
    }

    return $cats;
}

/**
 * @return array{ok:bool,slug?:string,label?:string,existing?:bool,error?:string,message?:string}
 */
function publisher_category_add(PDO $dbh, string $label, ?int $createdByUserId = null): array
{
    $label = preg_replace('/\s+/u', ' ', trim($label)) ?? trim($label);
    if (function_exists('mb_substr')) {
        $label = mb_substr($label, 0, 80);
    } else {
        $label = substr($label, 0, 80);
    }
    $labelLen = function_exists('mb_strlen') ? mb_strlen($label) : strlen($label);
    if ($labelLen < 2) {
        return ['ok' => false, 'error' => 'too_short', 'message' => 'Enter a category name (at least 2 characters).'];
    }

    $slug = publisher_category_slugify($label);
    if ($slug === '' || isset(publisher_category_reserved_slugs()[$slug])) {
        return ['ok' => false, 'error' => 'invalid', 'message' => 'That category name is not allowed. Try a different one.'];
    }

    $all = publisher_categories($dbh);
    if (isset($all[$slug])) {
        return [
            'ok' => true,
            'slug' => $slug,
            'label' => (string)$all[$slug],
            'existing' => true,
        ];
    }

    // Match by label case-insensitively against builtins + customs
    foreach ($all as $existingSlug => $existingLabel) {
        if (strcasecmp((string)$existingLabel, $label) === 0) {
            return [
                'ok' => true,
                'slug' => (string)$existingSlug,
                'label' => (string)$existingLabel,
                'existing' => true,
            ];
        }
    }

    publisher_custom_categories_ensure_schema($dbh);
    try {
        $st = $dbh->prepare('
            INSERT INTO publisher_category_options (slug, label, created_by_user_id, created_at)
            VALUES (:slug, :label, :uid, NOW())
        ');
        $st->execute([
            ':slug' => $slug,
            ':label' => $label,
            ':uid' => ($createdByUserId !== null && $createdByUserId > 0) ? $createdByUserId : null,
        ]);
    } catch (Throwable $e) {
        // Race: another request may have inserted the same slug
        $customs = publisher_custom_categories($dbh);
        if (isset($customs[$slug])) {
            publisher_categories_reset_cache();
            return [
                'ok' => true,
                'slug' => $slug,
                'label' => (string)$customs[$slug],
                'existing' => true,
            ];
        }
        return ['ok' => false, 'error' => 'save_failed', 'message' => 'Unable to add that category right now.'];
    }

    publisher_categories_reset_cache();
    return [
        'ok' => true,
        'slug' => $slug,
        'label' => $label,
        'existing' => false,
    ];
}

function publisher_academic_categories(): array
{
    return array_intersect_key(publisher_categories(), array_flip([
        'english', 'mathematics', 'social-studies', 'special-classes',
        'information-technology', 'design', 'health-wellness-sciences',
        'environmental-sustainability', 'psychology', 'engineering', 'lawyer',
        'astrobiology', 'biology', 'economics', 'criminal-justice', 'marketing',
        'museum', 'philosophy', 'physics', 'vets', 'sociology-and-anthropology',
    ]));
}

function publisher_category_icon_path(string $slug): string
{
    $icons = [
        'english' => '<path d="M4 5.5A2.5 2.5 0 0 1 6.5 3H11v16H6.5A2.5 2.5 0 0 0 4 21.5z"/><path d="M20 5.5A2.5 2.5 0 0 0 17.5 3H13v16h4.5a2.5 2.5 0 0 1 2.5 2.5z"/>',
        'mathematics' => '<path d="M5 4h14v16H5z"/><path d="M8 8h8M8 12h2M14 12h2M8 16h2M14 16h2"/>',
        'social-studies' => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/>',
        'special-classes' => '<path d="m12 3 2.5 5 5.5.8-4 3.9.9 5.5-4.9-2.6-4.9 2.6.9-5.5-4-3.9 5.5-.8z"/>',
        'information-technology' => '<rect x="3" y="4" width="18" height="13" rx="2"/><path d="M8 21h8M12 17v4"/>',
        'design' => '<path d="m4 20 4-1 11-11-3-3L5 16z"/><path d="m14 7 3 3M4 4h6"/>',
        'health-wellness-sciences' => '<path d="M12 21s-8-4.5-8-11a4.5 4.5 0 0 1 8-2.8A4.5 4.5 0 0 1 20 10c0 6.5-8 11-8 11z"/><path d="M8 12h2l1-2 2 4 1-2h2"/>',
        'environmental-sustainability' => '<path d="M12 21V9"/><path d="M12 13C7 13 4 10 4 5c5 0 8 3 8 8zM12 17c5 0 8-3 8-8-5 0-8 3-8 8z"/>',
        'psychology' => '<path d="M9 20H7a4 4 0 0 1-4-4v-2a3 3 0 0 1 2-2.8V9a7 7 0 0 1 14 0v11"/><path d="M9 8a3 3 0 1 1 3 3v3"/>',
        'engineering' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1-2.8 2.8-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1 1.6v.2h-4V21a1.7 1.7 0 0 0-1-1.6 1.7 1.7 0 0 0-1.9.3l-.1.1L4.2 17l.1-.1a1.7 1.7 0 0 0 .3-1.9A1.7 1.7 0 0 0 3 14H3v-4h.1a1.7 1.7 0 0 0 1.6-1 1.7 1.7 0 0 0-.3-1.9L4.2 7 7 4.2l.1.1A1.7 1.7 0 0 0 9 4.6 1.7 1.7 0 0 0 10 3V3h4v.1a1.7 1.7 0 0 0 1 1.6 1.7 1.7 0 0 0 1.9-.3l.1-.1L19.8 7l-.1.1a1.7 1.7 0 0 0-.3 1.9 1.7 1.7 0 0 0 1.6 1h.2v4H21a1.7 1.7 0 0 0-1.6 1z"/>',
        'lawyer' => '<path d="M12 3v18M5 7h14M7 7l-4 7h8zM17 7l-4 7h8zM8 21h8"/>',
        'astrobiology' => '<circle cx="12" cy="12" r="2"/><ellipse cx="12" cy="12" rx="9" ry="4"/><ellipse cx="12" cy="12" rx="4" ry="9" transform="rotate(45 12 12)"/>',
        'biology' => '<path d="M7 3c8 4 2 14 10 18M17 3C9 7 15 17 7 21M8 7h8M7 12h10M8 17h8"/>',
        'economics' => '<path d="M4 19V9M10 19V5M16 19v-7M22 19H2"/>',
        'criminal-justice' => '<path d="M12 3 20 6v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z"/><path d="M9 12l2 2 4-5"/>',
        'marketing' => '<path d="M3 11v2l11 4V7zM14 9l5-3v12l-5-3M6 14l1 6h4l-2-5"/>',
        'museum' => '<path d="M3 10h18M5 10v8M9 10v8M15 10v8M19 10v8M3 18h18M12 3l9 5H3z"/>',
        'philosophy' => '<path d="M8 21h8M10 21v-4a7 7 0 1 1 4 0v4"/><path d="M9 10h6"/>',
        'physics' => '<circle cx="12" cy="12" r="2"/><ellipse cx="12" cy="12" rx="9" ry="4"/><ellipse cx="12" cy="12" rx="4" ry="9" transform="rotate(60 12 12)"/>',
        'vets' => '<path d="M8 12c-3-2-5 0-4 3s4 2 4 0M16 12c3-2 5 0 4 3s-4 2-4 0"/><path d="M8 16c1-5 7-5 8 0 1 4-7 5-8 0z"/><circle cx="8" cy="7" r="2"/><circle cx="16" cy="7" r="2"/>',
        'sociology-and-anthropology' => '<circle cx="8" cy="8" r="3"/><circle cx="16" cy="9" r="2.5"/><path d="M2 21c0-4 2-7 6-7s6 3 6 7M14 15c4 0 7 2 7 6"/>',
    ];
    return $icons[$slug] ?? '<circle cx="12" cy="12" r="9"/><path d="M7 12h10M12 7v10"/>';
}

function publisher_make_friend_code(PDO $dbh): string
{
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    for ($try = 0; $try < 60; $try++) {
        $part = static function () use ($chars): string {
            $s = '';
            for ($i = 0; $i < 4; $i++) {
                $s .= $chars[random_int(0, strlen($chars) - 1)];
            }
            return $s;
        };
        $code = 'PUB-' . $part() . '-' . $part();
        $st = $dbh->prepare('SELECT 1 FROM users WHERE friend_code = :c LIMIT 1');
        $st->execute([':c' => $code]);
        if (!$st->fetchColumn()) {
            return $code;
        }
    }
    throw new RuntimeException('Unable to generate publisher code.');
}

function publisher_user_is_followed(PDO $dbh, int $followerId, int $publisherId): bool
{
    if ($followerId <= 0 || $publisherId <= 0) {
        return false;
    }
    try {
        $st = $dbh->prepare('SELECT 1 FROM public_follows WHERE follower_id = :me AND following_id = :them LIMIT 1');
        $st->execute([':me' => $followerId, ':them' => $publisherId]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function publisher_follower_count(PDO $dbh, int $publisherId): int
{
    if ($publisherId <= 0 || !publisher_is_publisher_user($dbh, $publisherId)) {
        return 0;
    }
    try {
        $st = $dbh->prepare('SELECT COUNT(*) FROM public_follows WHERE following_id = :id');
        $st->execute([':id' => $publisherId]);
        return (int)($st->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        return 0;
    }
}

function publisher_social_stat_label(int $count): string
{
    return ($count === 0 || $count === 1) ? 'Follow' : 'Follows';
}

/**
 * Notify personal followers when a publisher publishes a visible post.
 * Creates “posted an update” rows for the What’s up notifications tab.
 */
function publisher_notify_followers_of_post(PDO $dbh, int $publisherId, int $postId, string $visibility = 'public'): void
{
    $publisherId = (int)$publisherId;
    $postId = (int)$postId;
    $visibility = strtolower(trim($visibility)) ?: 'public';
    if ($publisherId <= 0 || $postId <= 0 || $visibility === 'private') {
        return;
    }
    if (!publisher_is_publisher_user($dbh, $publisherId)) {
        return;
    }

    try {
        publisher_ensure_schema($dbh);
        $stPub = $dbh->prepare("
            SELECT
              COALESCE(NULLIF(TRIM(name), ''), NULLIF(TRIM(username), ''), CONCAT('Publisher ', id)) AS display_name
            FROM users
            WHERE id = :id AND status = 1
            LIMIT 1
        ");
        $stPub->execute([':id' => $publisherId]);
        $senderLabel = trim((string)($stPub->fetchColumn() ?: ''));
        if ($senderLabel === '') {
            return;
        }

        $route = ($visibility === 'public') ? 'pb' : 'fd';
        $type = 'posted an update [r:' . $route . '] [p:' . $postId . ']';

        $followers = [];
        try {
            $stFollowers = $dbh->prepare("
                SELECT u.id, u.username
                FROM public_follows pf
                INNER JOIN users u ON u.id = pf.follower_id
                WHERE pf.following_id = :pub
                  AND u.status = 1
                  AND COALESCE(u.account_kind, 'personal') = 'personal'
                  AND NULLIF(TRIM(u.username), '') IS NOT NULL
                LIMIT 500
            ");
            $stFollowers->execute([':pub' => $publisherId]);
            $followers = $stFollowers->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $eFollowers) {
            return;
        }
        if (!$followers) {
            return;
        }

        $ins = $dbh->prepare("
            INSERT INTO notification (notiuser, notireceiver, notitype, is_read)
            VALUES (:sender, :receiver, :type, 0)
        ");
        foreach ($followers as $row) {
            $receiverId = (int)($row['id'] ?? 0);
            $receiverUsername = trim((string)($row['username'] ?? ''));
            if ($receiverId <= 0 || $receiverUsername === '' || $receiverId === $publisherId) {
                continue;
            }
            try {
                $ins->execute([
                    ':sender' => $senderLabel,
                    ':receiver' => $receiverUsername,
                    ':type' => $type,
                ]);
            } catch (Throwable $eIns) {
                // Keep publishing even if one follower notify fails.
            }
        }
    } catch (Throwable $e) {
        // Notification failure must not break publish.
    }
}

function publisher_list(PDO $dbh, string $category = '', int $limit = 40): array
{
    publisher_ensure_schema($dbh);
    $limit = max(1, min($limit, 100));
    $sql = "
        SELECT id, name, username, friend_code, image, publisher_category, publisher_tagline, designation,
               COALESCE(account_kind, 'personal') AS account_kind
        FROM users
        WHERE status = 1 AND COALESCE(account_kind, 'personal') = 'publisher'
    ";
    $params = [];
    $category = strtolower(trim($category));
    if ($category !== '' && isset(publisher_categories()[$category])) {
        $sql .= ' AND publisher_category = :cat';
        $params[':cat'] = $category;
    }
    $sql .= ' ORDER BY name ASC LIMIT ' . $limit;

    try {
        $st = $dbh->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function publisher_followed_ids(PDO $dbh, int $userId): array
{
    if ($userId <= 0) {
        return [];
    }
    publisher_ensure_schema($dbh);
    try {
        $st = $dbh->prepare("
            SELECT pf.following_id
            FROM public_follows pf
            INNER JOIN users u ON u.id = pf.following_id
            WHERE pf.follower_id = :me AND COALESCE(u.account_kind, 'personal') = 'publisher'
        ");
        $st->execute([':me' => $userId]);
        return array_map('intval', array_column($st->fetchAll(PDO::FETCH_ASSOC) ?: [], 'following_id'));
    } catch (Throwable $e) {
        return [];
    }
}

function publisher_attach_follow_state(PDO $dbh, array $publishers, int $viewerId): array
{
    if ($viewerId <= 0 || !$publishers) {
        return $publishers;
    }
    $followed = array_flip(publisher_followed_ids($dbh, $viewerId));
    foreach ($publishers as &$row) {
        $id = (int)($row['id'] ?? 0);
        $row['is_following'] = isset($followed[$id]) ? 1 : 0;
    }
    unset($row);
    return $publishers;
}

/** Personal viewers on public.php / news.php — not publisher workspace staff. */
function publisher_public_stranger_surface(PDO $dbh, int $viewerId): bool
{
    return $viewerId > 0 && !publisher_workspace_viewer($dbh, $viewerId);
}

function publisher_public_discoverable_catalog_names_lower(): array
{
    static $names = null;
    if ($names !== null) {
        return $names;
    }

    $names = [];
    foreach (publisher_registry_catalog_names() as $row) {
        $name = mb_strtolower(publisher_registry_normalize_name((string)($row['name'] ?? '')));
        if ($name !== '') {
            $names[$name] = true;
        }
    }

    return $names;
}

/**
 * SQL true when a publisher account is a public brand (catalog or authority-approved).
 * Use for strangers on public.php / news.php — hides admin/manager/staff workspace publishers.
 */
function publisher_public_discoverable_publisher_sql(PDO $dbh, string $alias = 'u'): string
{
    require_once __DIR__ . '/publisher_authority.php';
    publisher_authority_ensure_schema($dbh);

    $catalogNames = publisher_public_discoverable_catalog_names_lower();
    $inList = "''";
    if ($catalogNames) {
        $quoted = [];
        foreach (array_keys($catalogNames) as $name) {
            $quoted[] = $dbh->quote($name);
        }
        $inList = implode(',', $quoted);
    }

    $nameExpr = "LOWER(TRIM(COALESCE({$alias}.name, '')))";

    return "(
        {$nameExpr} IN ({$inList})
        OR EXISTS (
            SELECT 1
            FROM publisher_name_authority pna
            WHERE LOWER(pna.publisher_name) = {$nameExpr}
              AND pna.status = 'approved'
        )
    )";
}

function publisher_row_is_public_discoverable_publisher(PDO $dbh, array $row): bool
{
    if (!publisher_is_publisher_row($row)) {
        return false;
    }

    require_once __DIR__ . '/publisher_authority.php';

    $name = publisher_registry_normalize_name((string)($row['name'] ?? ''));
    if ($name === '') {
        return false;
    }
    if (publisher_registry_is_catalog_name($name)) {
        return true;
    }

    return publisher_authority_is_approved($dbh, $name);
}

function publisher_is_public_discoverable_publisher(PDO $dbh, int $publisherUserId): bool
{
    if ($publisherUserId <= 0) {
        return false;
    }
    if (!publisher_is_publisher_user($dbh, $publisherUserId)) {
        return true;
    }

    try {
        $st = $dbh->prepare("
            SELECT id, name, username, friend_code, image, publisher_category, publisher_tagline, designation,
                   COALESCE(account_kind, 'personal') AS account_kind
            FROM users
            WHERE id = :id
            LIMIT 1
        ");
        $st->execute([':id' => $publisherUserId]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return false;
    }

    return publisher_row_is_public_discoverable_publisher($dbh, $row);
}

/** @param array<int, array<string, mixed>> $rows */
function publisher_filter_public_discoverable_publishers(PDO $dbh, array $rows): array
{
    return array_values(array_filter($rows, static function (array $row) use ($dbh): bool {
        return publisher_row_is_public_discoverable_publisher($dbh, $row);
    }));
}

function publisher_search(PDO $dbh, string $query, int $limit = 20, bool $publicDiscoverableOnly = false): array
{
    publisher_ensure_schema($dbh);
    $query = trim($query);
    if ($query === '') {
        return [];
    }
    $limit = max(1, min($limit, 50));
    $discoverableSql = $publicDiscoverableOnly
        ? (' AND ' . publisher_public_discoverable_publisher_sql($dbh, 'users'))
        : '';
    try {
        $st = $dbh->prepare("
            SELECT id, name, username, friend_code, image, publisher_category, publisher_tagline, designation,
                   COALESCE(account_kind, 'personal') AS account_kind
            FROM users
            WHERE status = 1
              AND (
                COALESCE(account_kind, 'personal') = 'publisher'
                OR friend_code LIKE 'PUB-%'
              )
              AND (
                name LIKE :q1 OR username LIKE :q2 OR publisher_tagline LIKE :q3 OR designation LIKE :q4
              ){$discoverableSql}
            ORDER BY name ASC
            LIMIT {$limit}
        ");
        $qLike = '%' . $query . '%';
        $st->execute([
            ':q1' => $qLike,
            ':q2' => $qLike,
            ':q3' => $qLike,
            ':q4' => $qLike,
        ]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function publisher_is_publisher_session(): bool
{
    return strtolower(trim((string)($_SESSION['user_account_kind'] ?? ''))) === 'publisher';
}

/** Publisher org staff acting as the linked publisher in public_user. */
function publisher_is_staff_workspace_session(): bool
{
    return !empty($_SESSION['staff_publisher_mode'])
        && (int)($_SESSION['staff_account_id'] ?? 0) > 0;
}

/**
 * Publisher, manager (as publisher), or org staff — not a personal user viewer.
 */
function publisher_workspace_viewer(PDO $dbh, int $meId): bool
{
    if ($meId <= 0) {
        return false;
    }
    if (publisher_is_staff_workspace_session()) {
        return true;
    }
    return publisher_account_is($dbh, $meId);
}

/** Personal users may follow publishers; publisher workspace accounts may not. */
function publisher_can_follow_as_viewer(PDO $dbh, int $meId): bool
{
    return $meId > 0 && !publisher_workspace_viewer($dbh, $meId);
}

/** Browse publisher suggestions in public.php / suggested_for_you (not personal friend lists). */
function publisher_can_browse_publisher_suggestions(PDO $dbh, int $meId): bool
{
    return $meId > 0 && (publisher_can_follow_as_viewer($dbh, $meId) || publisher_workspace_viewer($dbh, $meId));
}

function publisher_author_is_publisher_sql(string $alias = 'u'): string
{
    return "COALESCE({$alias}.account_kind, 'personal') = 'publisher'";
}

/** SQL predicate: user row looks like a publisher account (matches PHP heuristics). */
function publisher_user_row_is_publisher_sql(string $alias = 'u'): string
{
    $a = $alias;
    return "(
        COALESCE({$a}.account_kind, 'personal') = 'publisher'
        OR UPPER(COALESCE({$a}.friend_code, '')) LIKE 'PUB-%'
        OR COALESCE({$a}.publisher_category, '') <> ''
    )";
}

function publisher_author_is_personal_sql(string $alias = 'u'): string
{
    return "COALESCE({$alias}.account_kind, 'personal') <> 'publisher'";
}

/** True when the user is a publisher account (DB row or current session). */
function publisher_account_is(PDO $dbh, int $userId): bool
{
    if ($userId <= 0) {
        return false;
    }
    if (publisher_is_publisher_user($dbh, $userId)) {
        return true;
    }
    return (int)($_SESSION['user_id'] ?? 0) === $userId && publisher_is_publisher_session();
}

function publisher_set_session_kind(array $user): void
{
    $_SESSION['user_account_kind'] = strtolower(trim((string)($user['account_kind'] ?? 'personal')));
}

/** Load the canonical users row for session binding (unique per publisher account). */
function publisher_session_load_user_row(PDO $dbh, int $userId): ?array
{
    if ($userId <= 0) {
        return null;
    }

    try {
        publisher_ensure_schema($dbh);
    } catch (Throwable $e) {
        // Schema repair must never block auth; fall through to a plain SELECT.
    }

    $cols = [
        'id', 'name', 'username', 'email', 'password', 'friend_code', 'image', 'role', 'status',
        'gender', 'mobile', 'designation',
    ];
    try {
        if (publisher_db_column_exists($dbh, 'users', 'account_kind')) {
            $cols[] = 'account_kind';
        }
        if (publisher_db_column_exists($dbh, 'users', 'publisher_category')) {
            $cols[] = 'publisher_category';
        }
        if (publisher_db_column_exists($dbh, 'users', 'publisher_tagline')) {
            $cols[] = 'publisher_tagline';
        }
    } catch (Throwable $e) {
        // use base columns only
    }

    try {
        $st = $dbh->prepare('SELECT ' . implode(', ', $cols) . ' FROM users WHERE id = :id LIMIT 1');
        $st->execute([':id' => $userId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row || (int)($row['status'] ?? 0) !== 1) {
            return null;
        }
        return $row;
    } catch (Throwable $e) {
        return publisher_session_load_user_row_fallback($dbh, $userId);
    }
}

function publisher_session_clear_identity(): void
{
    unset(
        $_SESSION['session_user_id'],
        $_SESSION['publisher_session_user_id'],
        $_SESSION['publisher_session_owner'],
        $_SESSION['publisher_session_staff_id'],
        $_SESSION['publisher_org_id']
    );
}

/**
 * Bind BUSINESS_ONLY_USER session to one publisher account (owner login).
 * @param bool $syncOrg When false, only refresh session keys (safe on every page load).
 */
function publisher_session_bind_owner(PDO $dbh, int $publisherUserId, bool $syncOrg = true): void
{
    if ($publisherUserId <= 0) {
        publisher_session_clear_identity();
        return;
    }

    $_SESSION['session_user_id'] = $publisherUserId;
    $_SESSION['publisher_session_user_id'] = $publisherUserId;
    $_SESSION['publisher_session_owner'] = 1;
    $_SESSION['user_account_kind'] = 'publisher';
    unset($_SESSION['publisher_session_staff_id']);

    if (!$syncOrg) {
        return;
    }

    try {
        require_once __DIR__ . '/publisher_organization_bridge.php';
        publisher_org_sync_public_user_orgs($dbh, $publisherUserId);
        $orgId = (int)(publisher_org_fetch_public_user_orgs($dbh, $publisherUserId)[0]['id'] ?? 0);
        if ($orgId > 0) {
            $_SESSION['publisher_org_id'] = $orgId;
        } else {
            unset($_SESSION['publisher_org_id']);
        }
    } catch (Throwable $e) {
        unset($_SESSION['publisher_org_id']);
    }
}

/** Bind BUSINESS_ONLY_USER session when staff acts as a linked publisher (view-only). */
function publisher_session_bind_staff(int $publisherUserId, int $staffId, int $orgId): void
{
    if ($publisherUserId <= 0) {
        publisher_session_clear_identity();
        return;
    }

    $_SESSION['session_user_id'] = $publisherUserId;
    $_SESSION['publisher_session_user_id'] = $publisherUserId;
    $_SESSION['publisher_session_staff_id'] = max(0, $staffId);
    unset($_SESSION['publisher_session_owner']);
    if ($orgId > 0) {
        $_SESSION['publisher_org_id'] = $orgId;
    } else {
        unset($_SESSION['publisher_org_id']);
    }
}

/** Canonical public_user identity for the active session (always users.id). */
function publisher_session_canonical_user_id(): int
{
    $uid = (int)($_SESSION['user_id'] ?? 0);
    $bound = (int)($_SESSION['publisher_session_user_id'] ?? $_SESSION['session_user_id'] ?? 0);
    return $bound > 0 ? $bound : $uid;
}

function publisher_session_is_owner(): bool
{
    return !empty($_SESSION['publisher_session_owner'])
        && empty($_SESSION['publisher_session_staff_id'])
        && !publisher_is_staff_workspace_session();
}

/** Re-bind publisher owner identity before auth guards (profile.php, APIs, etc.). */
function publisher_session_ensure_owner_binding(PDO $dbh): void
{
    if (publisher_is_staff_workspace_session()) {
        return;
    }

    $userId = (int)($_SESSION['user_id'] ?? 0);
    if ($userId <= 0) {
        return;
    }

    if (!publisher_account_is($dbh, $userId)) {
        return;
    }

    if (!publisher_session_is_owner()) {
        try {
            // Light bind on page guards — avoid org sync on every navigation.
            publisher_session_bind_owner($dbh, $userId, false);
        } catch (Throwable $e) {
            // ignore
        }
    }
}

/**
 * Load a minimal active users row when the full publisher session loader fails
 * (schema repair race, missing optional columns, etc.).
 */
function publisher_session_load_user_row_fallback(PDO $dbh, int $userId): ?array
{
    if ($userId <= 0) {
        return null;
    }

    try {
        $st = $dbh->prepare('
            SELECT id, name, username, email, friend_code, image, role, status
            FROM users
            WHERE id = :id
            LIMIT 1
        ');
        $st->execute([':id' => $userId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row || (int)($row['status'] ?? 0) !== 1) {
            return null;
        }
        return $row;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Ensure publisher/staff sessions still map to one unique users.id row.
 * Prefer repair/rebind over logout so publishers can navigate feed/public/news smoothly.
 * Returns false only when the account is missing or inactive.
 */
function publisher_session_validate(PDO $dbh): bool
{
    $userId = (int)($_SESSION['user_id'] ?? 0);
    if ($userId <= 0) {
        return false;
    }

    $row = publisher_session_load_user_row($dbh, $userId);
    if (!$row) {
        $row = publisher_session_load_user_row_fallback($dbh, $userId);
    }
    if (!$row) {
        // Keep login if PHP session still has credentials but DB briefly failed.
        return trim((string)($_SESSION['user_login'] ?? '')) !== '';
    }

    $isPublisherRow = publisher_user_row_looks_like_publisher($dbh, $row);
    $sessionKind = strtolower(trim((string)($_SESSION['user_account_kind'] ?? '')));
    $isStaffSession = publisher_is_staff_workspace_session();
    $isPublisherSession = $isPublisherRow
        || $sessionKind === 'publisher'
        || $isStaffSession;

    // Personal accounts: keep session_user_id aligned, drop publisher bindings.
    if (!$isPublisherSession) {
        publisher_session_clear_identity();
        $_SESSION['session_user_id'] = $userId;
        $_SESSION['user_account_kind'] = 'personal';
        return true;
    }

    // Stale/incomplete staff flags must not force logout — fall back to owner/personal.
    if ($isStaffSession) {
        $staffId = (int)($_SESSION['staff_account_id'] ?? $_SESSION['publisher_session_staff_id'] ?? 0);
        $orgId = (int)($_SESSION['staff_org_id'] ?? $_SESSION['publisher_org_id'] ?? 0);
        $staffOk = false;

        if ($staffId > 0 && $orgId > 0) {
            if (!function_exists('staff_pub_staff_can_access_org')) {
                require_once __DIR__ . '/staff_publisher_access.php';
            }
            if (!function_exists('staff_pub_org_publisher_user_id')) {
                require_once __DIR__ . '/staff_publisher_access.php';
            }
            try {
                $staffOk = staff_pub_staff_can_access_org($dbh, $staffId, $orgId)
                    && staff_pub_org_publisher_user_id($dbh, $orgId) === $userId;
            } catch (Throwable $e) {
                $staffOk = false;
            }
        }

        if ($staffOk) {
            publisher_session_bind_staff($userId, $staffId, $orgId);
            $_SESSION['session_user_id'] = $userId;
            return true;
        }

        if (!function_exists('staff_pub_clear_session_flags')) {
            require_once __DIR__ . '/staff_publisher_access.php';
        }
        staff_pub_clear_session_flags();
        unset($_SESSION['publisher_session_staff_id']);
        $isStaffSession = false;
    }

    // Session claims publisher but DB row does not — repair, else demote (stay logged in).
    if (!$isPublisherRow) {
        try {
            publisher_repair_user_as_publisher(
                $dbh,
                $userId,
                trim((string)($row['publisher_category'] ?? ''))
            );
            $row = publisher_session_load_user_row($dbh, $userId) ?: $row;
            $isPublisherRow = publisher_user_row_looks_like_publisher($dbh, $row);
        } catch (Throwable $e) {
            // ignore repair failures
        }
    }

    if (!$isPublisherRow) {
        publisher_session_clear_identity();
        $_SESSION['session_user_id'] = $userId;
        $_SESSION['user_account_kind'] = 'personal';
        return true;
    }

    $canonical = (int)($_SESSION['publisher_session_user_id'] ?? $_SESSION['session_user_id'] ?? 0);
    if ($canonical <= 0 || $canonical !== $userId || (int)($_SESSION['publisher_session_owner'] ?? 0) !== 1) {
        try {
            // Do not run org provisioning during auth checks — it slowed/broke nav.
            publisher_session_bind_owner($dbh, $userId, false);
        } catch (Throwable $e) {
            $_SESSION['session_user_id'] = $userId;
            $_SESSION['publisher_session_user_id'] = $userId;
            $_SESSION['publisher_session_owner'] = 1;
            $_SESSION['user_account_kind'] = 'publisher';
            unset($_SESSION['publisher_session_staff_id']);
        }
    }

    $_SESSION['session_user_id'] = $userId;
    $_SESSION['publisher_session_user_id'] = $userId;
    $_SESSION['publisher_session_owner'] = 1;
    $_SESSION['user_account_kind'] = 'publisher';

    return true;
}

/** Open BUSINESS_ONLY_USER session for a registered publisher manager (organization login). */
function publisher_session_establish_for_manager(PDO $dbh, int $managerId): void
{
    if ($managerId <= 0) {
        return;
    }

    require_once __DIR__ . '/publisher_organization_bridge.php';

    $publisherUserId = publisher_org_manager_publisher_user_id($dbh, $managerId);
    if ($publisherUserId <= 0) {
        return;
    }

    $user = publisher_session_load_user_row($dbh, $publisherUserId);
    if (!$user || !publisher_user_row_looks_like_publisher($dbh, $user)) {
        return;
    }

    // Preserve the caller's session name + id (usually PHPSESSID with org_auth).
    // setUserSession() regenerates the BUSINESS_ONLY_USER id; without restoring
    // previousId, PHPSESSID can be overwritten and Enterprise/org access breaks.
    $previousName = session_name();
    $previousId = session_id();
    $wasActive = session_status() === PHP_SESSION_ACTIVE;
    if ($wasActive) {
        session_write_close();
    }

    $bootstrapLoad = dirname(__DIR__, 2) . '/admin/includes/admin_linked_bootstrap_load.php';
    if (is_file($bootstrapLoad)) {
        require_once $bootstrapLoad;
    }
    if (function_exists('admin_linked_apply_session_cookie_path')) {
        admin_linked_apply_session_cookie_path();
    }

    session_name('BUSINESS_ONLY_USER');
    if (function_exists('session_create_id')) {
        $freshId = session_create_id('pub');
        if (is_string($freshId) && $freshId !== '') {
            session_id($freshId);
        }
    } elseif ($previousId !== '') {
        // Avoid accidentally reopening the org session id under the public cookie name.
        session_id(bin2hex(random_bytes(16)));
    }
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    if (!function_exists('setUserSession')) {
        require_once __DIR__ . '/session_user.php';
    }
    $user['portal_staff_role_label'] = 'Manager';
    setUserSession($user);

    session_write_close();
    session_name($previousName !== '' ? $previousName : 'PHPSESSID');
    if ($previousId !== '') {
        session_id($previousId);
    }
    if ($wasActive || session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
}

function publisher_post_visibility(PDO $dbh, int $userId, string $requested): string
{
    publisher_ensure_post_visibility_supports_private($dbh);
    $requested = strtolower(trim($requested));
    return in_array($requested, ['public', 'friends', 'private'], true) ? $requested : 'public';
}

/**
 * public_posts.visibility was historically enum('public','friends').
 * Private gallery posts need 'private' or MySQL rejects the INSERT.
 */
function publisher_ensure_post_visibility_supports_private(PDO $dbh): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    try {
        $st = $dbh->prepare("
            SELECT COLUMN_TYPE
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'public_posts'
              AND COLUMN_NAME = 'visibility'
            LIMIT 1
        ");
        $st->execute();
        $columnType = strtolower(trim((string)($st->fetchColumn() ?: '')));
        if ($columnType === '') {
            return;
        }
        // Already accepts private (enum with private, or varchar).
        if (strpos($columnType, 'private') !== false || strpos($columnType, 'varchar') === 0 || strpos($columnType, 'char(') === 0) {
            return;
        }
        if (strpos($columnType, 'enum(') === 0) {
            $dbh->exec("ALTER TABLE public_posts MODIFY COLUMN visibility ENUM('public','friends','private') NOT NULL DEFAULT 'public'");
            return;
        }
        // Fallback: widen to varchar so any destination value is safe.
        $dbh->exec("ALTER TABLE public_posts MODIFY COLUMN visibility VARCHAR(20) NOT NULL DEFAULT 'public'");
    } catch (Throwable $e) {
        // Non-fatal: insert may still fail and surface to the client.
    }
}

/** After posting, land on the surface that matches the chosen destination. */
function publisher_post_redirect(PDO $dbh, int $userId, string $visibility): string
{
    $visibility = strtolower(trim($visibility));

    // Public → Discover. Friends → Circle. Private → Gallery Private.
    if ($visibility === 'public') {
        return 'public.php';
    }
    if ($visibility === 'private') {
        return 'profile.php';
    }

    return 'feed.php';
}

/**
 * Posts that belong in Circle (friends room):
 * - my own friends-destination posts
 * - friends-only posts from my friends
 * - public posts from publishers I follow (following lane, not stranger Discover)
 *
 * Public posts anyone can open belong on Discover — not Circle.
 * Private stays in Gallery → Private.
 */
function publisher_workspace_feed_scope_sql(): string
{
    return "(
        (
            p.user_id = :wsFeedMe
            AND LOWER(COALESCE(NULLIF(TRIM(p.visibility), ''), 'friends')) = 'friends'
        )
        OR (
            p.visibility = 'friends'
            AND EXISTS (
                SELECT 1 FROM user_contacts uc
                WHERE uc.owner_user_id = :wsFeedFriendMe
                  AND uc.friend_user_id = p.user_id
            )
        )
        OR (
            p.visibility = 'public'
            AND EXISTS (
                SELECT 1 FROM public_follows pf
                WHERE pf.follower_id = :wsFeedMe2 AND pf.following_id = p.user_id
            )
        )
    )";
}

function publisher_feed_list_scope_sql(): string
{
    return "(
        (
            p.user_id = :scopeMeOwn
            AND LOWER(COALESCE(NULLIF(TRIM(p.visibility), ''), 'friends')) = 'friends'
        )
        OR
        (p.visibility = 'friends' AND EXISTS (
            SELECT 1 FROM user_contacts uc
            WHERE uc.owner_user_id = :scopeMe2 AND uc.friend_user_id = p.user_id
        ))
        OR
        (p.visibility = 'public' AND EXISTS (
            SELECT 1 FROM public_follows pf
            INNER JOIN users pu ON pu.id = pf.following_id
            WHERE pf.follower_id = :scopeMe3 AND pf.following_id = p.user_id
              AND COALESCE(pu.account_kind, 'personal') = 'publisher'
        ))
    )";
}

function publisher_feed_list_scope_sql_for(PDO $dbh, int $meId): string
{
    if (publisher_workspace_viewer($dbh, $meId)) {
        return publisher_workspace_feed_scope_sql();
    }
    return publisher_feed_list_scope_sql();
}

function publisher_feed_list_scope_params(int $meId): array
{
    return [
        ':scopeMeOwn' => $meId,
        ':scopeMe2' => $meId,
        ':scopeMe3' => $meId,
    ];
}

function publisher_feed_list_scope_params_for(PDO $dbh, int $meId): array
{
    if (publisher_workspace_viewer($dbh, $meId)) {
        return [
            ':wsFeedMe' => $meId,
            ':wsFeedFriendMe' => $meId,
            ':wsFeedMe2' => $meId,
        ];
    }
    return publisher_feed_list_scope_params($meId);
}

function publisher_feed_unread_scope_named_sql(): string
{
    return "(
        (
            p.user_id = :unreadMe4
            AND LOWER(COALESCE(NULLIF(TRIM(p.visibility), ''), 'friends')) = 'friends'
        )
        OR
        (p.visibility = 'friends' AND EXISTS (
            SELECT 1 FROM user_contacts uc WHERE uc.owner_user_id = :unreadMe2 AND uc.friend_user_id = p.user_id
        ))
        OR
        (p.visibility = 'public' AND EXISTS (
            SELECT 1 FROM public_follows pf
            INNER JOIN users pu ON pu.id = pf.following_id
            WHERE pf.follower_id = :unreadMe3 AND pf.following_id = p.user_id
              AND COALESCE(pu.account_kind, 'personal') = 'publisher'
        ))
    )";
}

function publisher_feed_unread_scope_sql_for(PDO $dbh, int $meId): string
{
    if (publisher_workspace_viewer($dbh, $meId)) {
        return publisher_workspace_feed_scope_sql();
    }
    return publisher_feed_unread_scope_named_sql();
}

function publisher_feed_unread_scope_params_for(PDO $dbh, int $meId): array
{
    if (publisher_workspace_viewer($dbh, $meId)) {
        return [
            ':wsFeedMe' => $meId,
            ':wsFeedFriendMe' => $meId,
            ':wsFeedMe2' => $meId,
        ];
    }
    return [
        ':unreadMe2' => $meId,
        ':unreadMe3' => $meId,
        ':unreadMe4' => $meId,
    ];
}

/** Can this post be opened in feed.php? (public.php / news.php show unfollowed publisher posts for discovery.) */
function publisher_feed_can_view_post(PDO $dbh, int $meId, array $post): bool
{
    $authorId = (int)($post['user_id'] ?? 0);
    if ($authorId <= 0) {
        return false;
    }

    $authorIsPublisher = publisher_is_publisher_user($dbh, $authorId);

    if (publisher_workspace_viewer($dbh, $meId)) {
        if ($authorId === $meId) {
            return true;
        }
        $vis = strtolower(trim((string)($post['visibility'] ?? 'public')));
        if ($authorIsPublisher) {
            return $vis === 'public' && publisher_user_is_followed($dbh, $meId, $authorId);
        }
        if ($vis !== 'friends') {
            return false;
        }
        if (!function_exists('fs_are_friends')) {
            require_once __DIR__ . '/friend_system.php';
        }
        return fs_are_friends($dbh, $meId, $authorId);
    }

    if ($authorIsPublisher) {
        if ($authorId === $meId) {
            return true;
        }
        return publisher_user_is_followed($dbh, $meId, $authorId);
    }

    if ($authorId === $meId) {
        $vis = strtolower(trim((string)($post['visibility'] ?? 'friends')));
        // Own friends posts → Circle. Own public → Discover. Own private → Gallery.
        return ($vis === 'friends' || $vis === '');
    }

    $vis = strtolower(trim((string)($post['visibility'] ?? 'public')));
    if ($vis === 'friends') {
        if (!function_exists('fs_are_friends')) {
            require_once __DIR__ . '/friend_system.php';
        }
        return fs_are_friends($dbh, $meId, $authorId);
    }

    return false;
}

/** Personal users browse unfollowed publisher public posts on public.php. */
function publisher_post_visible_on_public_surface(PDO $dbh, int $meId, array $post): bool
{
    $authorId = (int)($post['user_id'] ?? 0);
    if ($authorId <= 0) {
        return false;
    }

    if (publisher_workspace_viewer($dbh, $meId)) {
        $vis = strtolower(trim((string)($post['visibility'] ?? 'public')));
        if ($vis !== 'public') {
            return false;
        }
        if (!publisher_is_publisher_user($dbh, $authorId)) {
            return true;
        }
        return $authorId === $meId || !publisher_user_is_followed($dbh, $meId, $authorId);
    }

    $vis = strtolower(trim((string)($post['visibility'] ?? 'public')));
    if ($vis !== 'public') {
        return false;
    }

    if (publisher_is_publisher_user($dbh, $authorId)) {
        if (publisher_user_is_followed($dbh, $meId, $authorId)) {
            return false;
        }
        return true;
    }

    return true;
}

function publisher_can_view_post(PDO $dbh, int $meId, array $post): bool
{
    $authorId = (int)($post['user_id'] ?? 0);
    // Owner can always open their own posts (Private gallery modal, edits, etc.).
    // Friends/strangers still cannot see private — feed/list scopes already exclude them.
    if ($authorId > 0 && $authorId === $meId) {
        return true;
    }

    if ($meId > 0 && $authorId > 0) {
        if (!function_exists('fs_block_either_way')) {
            require_once __DIR__ . '/friend_system.php';
        }
        if (function_exists('fs_block_either_way') && fs_block_either_way($dbh, $meId, $authorId)) {
            return false;
        }
    }

    return publisher_feed_can_view_post($dbh, $meId, $post)
        || publisher_post_visible_on_public_surface($dbh, $meId, $post)
        || publisher_profile_can_view_publisher_post($dbh, $meId, $post);
}

/** profile.php: staff and personal users may browse a publisher's public posts without following. */
function publisher_profile_can_view_publisher_post(PDO $dbh, int $meId, array $post): bool
{
    $authorId = (int)($post['user_id'] ?? 0);
    if ($authorId <= 0 || !publisher_is_publisher_user($dbh, $authorId)) {
        return false;
    }

    if ($meId === $authorId) {
        return true;
    }

    $vis = strtolower(trim((string)($post['visibility'] ?? 'public')));
    if ($vis !== 'public') {
        return false;
    }

    if (publisher_is_staff_workspace_session()) {
        return true;
    }

    if (!publisher_workspace_viewer($dbh, $meId)) {
        return true;
    }

    return true;
}

/** Any signed-in writable account may interact with a post it is permitted to view. */
function publisher_post_interaction_allowed(PDO $dbh, int $meId, array $post): bool
{
    if (function_exists('staff_pub_is_readonly') && staff_pub_is_readonly()) {
        return false;
    }

    $authorId = (int)($post['user_id'] ?? 0);
    $postId = (int)($post['id'] ?? $post['post_id'] ?? 0);
    // People tagged or mentioned on the post may engage even when visibility is private/friends.
    if ($meId > 0 && $postId > 0) {
        if (!function_exists('msb_user_is_tagged_on_post') || !function_exists('msb_user_is_mentioned_on_post')) {
            require_once __DIR__ . '/post_tags.php';
        }
        if (function_exists('msb_user_is_tagged_on_post') && msb_user_is_tagged_on_post($dbh, $postId, $meId)) {
            return true;
        }
        if (function_exists('msb_user_is_mentioned_on_post') && msb_user_is_mentioned_on_post($dbh, $postId, $meId)) {
            return true;
        }
    }

    if ($authorId <= 0 || !publisher_can_view_post($dbh, $meId, $post)) {
        return false;
    }

    return true;
}

/** Can the current viewer open this user's profile? */
function publisher_profile_can_view_user(PDO $dbh, int $meId, int $viewId): bool
{
    if ($meId <= 0 || $viewId <= 0) {
        return false;
    }
    if ($meId === $viewId) {
        return true;
    }

    if ($meId !== $viewId) {
        if (!function_exists('fs_block_either_way')) {
            require_once __DIR__ . '/friend_system.php';
        }
        if (function_exists('fs_block_either_way') && fs_block_either_way($dbh, $meId, $viewId)) {
            return false;
        }
    }

    $viewIsPublisher = publisher_is_publisher_user($dbh, $viewId);

    // Publisher workspace may only open other publisher profiles (not personal).
    if (publisher_workspace_viewer($dbh, $meId)) {
        return $viewIsPublisher;
    }

    // Personal users may open publisher profiles (Posts / Gallery / Tags)
    // and other personal profiles.
    return true;
}

/**
 * public.php / news.php list scope.
 * - news.php: publisher-authored public posts only (your own + unfollowed publishers).
 *   Followed publisher posts appear in feed.php instead. Personal public posts never appear here.
 * - Discover (public.php?tab=public): personal viewers see people (add friend);
 *   publisher viewers see publisher posts only (never personal-user posts).
 * - Publisher workspace viewers on public.php / reel: publisher-authored posts only.
 */
function publisher_public_discover_exclude_followed_sql(string $meBind = ':pubDiscMe'): string
{
    return "NOT (
        COALESCE(u.account_kind, 'personal') = 'publisher'
        AND EXISTS (
            SELECT 1 FROM public_follows pf
            WHERE pf.follower_id = {$meBind} AND pf.following_id = p.user_id
        )
    )";
}

function publisher_public_discover_exclude_followed_params(int $meId): array
{
    return [':pubDiscMe' => $meId];
}

function publisher_news_surface_scope_sql(string $meBind = ':newsMe', string $discBind = ':newsDiscMe'): string
{
    return '(' . publisher_author_is_publisher_sql('u') . "
        AND (
            p.user_id = {$meBind}
            OR NOT EXISTS (
                SELECT 1 FROM public_follows pf
                WHERE pf.follower_id = {$discBind} AND pf.following_id = p.user_id
            )
        ))";
}

function publisher_public_surface_scope_sql(PDO $dbh, int $meId, bool $newsSurface): string
{
    if ($newsSurface) {
        return publisher_news_surface_scope_sql();
    }

    if (publisher_workspace_viewer($dbh, $meId)) {
        return publisher_author_is_publisher_sql('u');
    }

    return '(1 = 1)';
}

function publisher_public_surface_scope_params(PDO $dbh, int $meId, bool $newsSurface): array
{
    if ($newsSurface) {
        return publisher_news_list_scope_params($meId);
    }

    if (publisher_workspace_viewer($dbh, $meId)) {
        return [];
    }

    return [];
}

/** Publisher-only posts for news.php: your own + unfollowed publisher accounts. */
function publisher_news_list_scope_sql(string $meBind = ':newsMe', string $discBind = ':newsDiscMe'): string
{
    return publisher_news_surface_scope_sql($meBind, $discBind);
}

function publisher_news_list_scope_params(int $meId): array
{
    return [
        ':newsMe' => $meId,
        ':newsDiscMe' => $meId,
    ];
}

/**
 * profile.php Posts / Gallery / Tags: list by profile owner, not feed discover rules.
 * - Own profile: all posts.
 * - Publisher profile: any signed-in viewer may browse that publisher's public posts.
 * - Personal profile:
 *   - accepted friends → Friend + Public posts
 *   - strangers → Public posts only
 *   - Private stays owner-only
 */
function publisher_profile_author_posts_scope_sql(PDO $dbh, int $viewerId, int $authorId): string
{
    if ($viewerId <= 0 || $authorId <= 0) {
        return '0=1';
    }

    if ($viewerId === $authorId) {
        return '1=1';
    }

    if (publisher_is_publisher_user($dbh, $authorId)) {
        // Public destination posts are the publisher portfolio for visitors.
        return "LOWER(COALESCE(NULLIF(TRIM(p.visibility), ''), 'public')) = 'public'";
    }

    // Personal profile: strangers see Public only; friends see Friend + Public.
    return "(
        LOWER(COALESCE(NULLIF(TRIM(p.visibility), ''), 'public')) = 'public'
        OR (
            LOWER(COALESCE(p.visibility, '')) = 'friends'
            AND EXISTS (
                SELECT 1 FROM user_contacts uc
                WHERE uc.owner_user_id = :profFriendMe AND uc.friend_user_id = :profFriendAuthor
            )
        )
    )";
}

function publisher_profile_author_posts_scope_params(PDO $dbh, int $viewerId, int $authorId): array
{
    if ($viewerId <= 0 || $authorId <= 0 || $viewerId === $authorId) {
        return [];
    }

    if (publisher_is_publisher_user($dbh, $authorId)) {
        return [];
    }

    return [
        ':profFriendMe' => $viewerId,
        ':profFriendAuthor' => $authorId,
    ];
}

function publisher_registry_normalize_name(string $name): string
{
    $name = preg_replace('/\s+/u', ' ', trim($name)) ?? trim($name);
    return mb_substr($name, 0, 120);
}

function publisher_registry_ensure_schema(PDO $dbh): void
{
    publisher_ensure_schema($dbh);

    static $registryDone = false;
    if ($registryDone) {
        return;
    }
    $registryDone = true;

    try {
        $dbh->exec("
            CREATE TABLE IF NOT EXISTS publisher_name_options (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                name VARCHAR(120) NOT NULL,
                category VARCHAR(40) NOT NULL DEFAULT 'news',
                created_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_publisher_name_option (name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        if (!publisher_db_column_exists($dbh, 'publisher_name_options', 'registered_user_id')) {
            try {
                $dbh->exec('ALTER TABLE publisher_name_options ADD COLUMN registered_user_id INT UNSIGNED NULL DEFAULT NULL AFTER category');
            } catch (Throwable $e) {
                $dbh->exec('ALTER TABLE publisher_name_options ADD COLUMN registered_user_id INT UNSIGNED NULL DEFAULT NULL');
            }
        }
    } catch (Throwable $e) {
        // Non-fatal — registration falls back to typed names.
    }

    publisher_registry_backfill_claimed_options($dbh);
}

/** One-time sync: mark saved options that already have publisher accounts. */
function publisher_registry_backfill_claimed_options(PDO $dbh): void
{
    static $backfillDone = false;
    if ($backfillDone) {
        return;
    }
    $backfillDone = true;

    if (!publisher_db_column_exists($dbh, 'publisher_name_options', 'registered_user_id')) {
        return;
    }

    try {
        $where = publisher_registry_registered_user_where_sql();
        $dbh->exec("
            UPDATE publisher_name_options pno
            INNER JOIN users u ON LOWER(TRIM(u.name)) = LOWER(TRIM(pno.name))
            SET pno.registered_user_id = u.id
            WHERE (pno.registered_user_id IS NULL OR pno.registered_user_id = 0)
              AND {$where}
        ");
    } catch (Throwable $e) {
        // Non-fatal.
    }
}

/** SQL fragment: active publisher accounts in users table. */
function publisher_registry_registered_user_where_sql(): string
{
    return "(
        status = 1
        AND (
            COALESCE(account_kind, 'personal') = 'publisher'
            OR UPPER(TRIM(COALESCE(friend_code, ''))) LIKE 'PUB-%'
            OR TRIM(COALESCE(publisher_category, '')) <> ''
        )
    )";
}

/** True when a publisher account already owns this display name (register dropdown should hide it). */
function publisher_registry_option_is_available(PDO $dbh, string $name): bool
{
    $name = publisher_registry_normalize_name($name);
    if ($name === '') {
        return false;
    }
    if (publisher_registry_name_is_registered($dbh, $name)) {
        return false;
    }

    publisher_registry_ensure_schema($dbh);
    if (publisher_db_column_exists($dbh, 'publisher_name_options', 'registered_user_id')) {
        try {
            $st = $dbh->prepare('
                SELECT registered_user_id
                FROM publisher_name_options
                WHERE LOWER(name) = LOWER(:name)
                LIMIT 1
            ');
            $st->execute([':name' => $name]);
            if ((int)($st->fetchColumn() ?: 0) > 0) {
                return false;
            }
        } catch (Throwable $e) {
            // fall through
        }
    }

    return true;
}

/**
 * Mark a saved option as claimed after register.php creates the publisher account.
 * Keeps the row for organization linking; only hides it from future registration options.
 */
function publisher_registry_mark_registered(PDO $dbh, string $name, int $userId): void
{
    if ($userId <= 0) {
        return;
    }

    $name = publisher_registry_normalize_name($name);
    if ($name === '') {
        return;
    }

    publisher_registry_ensure_schema($dbh);
    if (!publisher_db_column_exists($dbh, 'publisher_name_options', 'registered_user_id')) {
        return;
    }

    try {
        $st = $dbh->prepare('
            UPDATE publisher_name_options
            SET registered_user_id = :uid
            WHERE LOWER(name) = LOWER(:name)
              AND (registered_user_id IS NULL OR registered_user_id = 0)
        ');
        $st->execute([
            ':uid' => $userId,
            ':name' => $name,
        ]);
    } catch (Throwable $e) {
        // Non-fatal — users.name still drives display in public_user / org / admin.
    }
}

function publisher_registry_catalog_names(): array
{
    require_once __DIR__ . '/news_publishers.php';

    // These curated read-only news sources must not be offered as names for
    // new publisher accounts. Keep them in the news catalog so existing feed
    // integrations continue to work.
    $excludedRegistrationNames = array_fill_keys([
        'ap news',
        'bbc news',
        'fox news',
        'reuters',
        'the met',
        'top gear',
    ], true);

    $rows = [];
    foreach (news_publishers_catalog() as $row) {
        $label = publisher_registry_normalize_name((string)($row['label'] ?? ''));
        if ($label === '' || isset($excludedRegistrationNames[mb_strtolower($label)])) {
            continue;
        }
        $category = strtolower(trim((string)($row['category'] ?? 'news')));
        if (!isset(publisher_categories()[$category])) {
            $category = 'news';
        }
        $rows[] = [
            'name' => $label,
            'category' => $category,
            'source' => 'catalog',
        ];
    }

    $extras = [
        ['name' => 'NBC News', 'category' => 'news'],
    ];
    foreach ($extras as $extra) {
        $label = publisher_registry_normalize_name((string)($extra['name'] ?? ''));
        if ($label === '') {
            continue;
        }
        $category = strtolower(trim((string)($extra['category'] ?? 'news')));
        if (!isset(publisher_categories()[$category])) {
            $category = 'news';
        }
        $rows[] = [
            'name' => $label,
            'category' => $category,
            'source' => 'catalog',
        ];
    }

    $deduped = [];
    foreach ($rows as $row) {
        $key = mb_strtolower($row['name']);
        if (!isset($deduped[$key])) {
            $deduped[$key] = $row;
        }
    }

    return array_values($deduped);
}

function publisher_registry_custom_names(PDO $dbh): array
{
    publisher_registry_ensure_schema($dbh);

    try {
        $st = $dbh->query('SELECT name, category FROM publisher_name_options ORDER BY name ASC');
        $rows = $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    } catch (Throwable $e) {
        return [];
    }

    $out = [];
    foreach ($rows as $row) {
        $name = publisher_registry_normalize_name((string)($row['name'] ?? ''));
        if ($name === '') {
            continue;
        }
        $category = strtolower(trim((string)($row['category'] ?? 'news')));
        if (!isset(publisher_categories()[$category])) {
            $category = 'news';
        }
        $out[] = [
            'name' => $name,
            'category' => $category,
            'source' => 'custom',
        ];
    }

    return $out;
}

function publisher_registry_registered_names(PDO $dbh): array
{
    publisher_ensure_schema($dbh);

    try {
        $where = publisher_registry_registered_user_where_sql();
        $st = $dbh->query("
            SELECT name
            FROM users
            WHERE {$where}
            ORDER BY name ASC
        ");
        $rows = $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    } catch (Throwable $e) {
        return [];
    }

    $names = [];
    foreach ($rows as $row) {
        $name = publisher_registry_normalize_name((string)($row['name'] ?? ''));
        if ($name !== '') {
            $names[] = $name;
        }
    }

    return $names;
}

function publisher_registry_name_is_registered(PDO $dbh, string $name): bool
{
    $name = publisher_registry_normalize_name($name);
    if ($name === '') {
        return false;
    }

    publisher_ensure_schema($dbh);

    try {
        $where = publisher_registry_registered_user_where_sql();
        $st = $dbh->prepare("
            SELECT 1
            FROM users
            WHERE {$where}
              AND LOWER(TRIM(name)) = LOWER(:name)
            LIMIT 1
        ");
        $st->execute([':name' => $name]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

/** Names available on the register page (catalog only, minus already-registered publishers). */
function publisher_registry_list_options(PDO $dbh): array
{
    publisher_registry_ensure_schema($dbh);

    $byKey = [];
    foreach (publisher_registry_catalog_names() as $row) {
        $optName = publisher_registry_normalize_name((string)($row['name'] ?? ''));
        if ($optName === '' || !publisher_registry_option_is_available($dbh, $optName)) {
            continue;
        }
        $key = mb_strtolower($optName);
        $row['name'] = $optName;
        $byKey[$key] = $row;
    }

    $out = array_values($byKey);
    usort($out, static function (array $a, array $b): int {
        return strcasecmp((string)$a['name'], (string)$b['name']);
    });

    return $out;
}

function publisher_registry_add_option(PDO $dbh, string $name, string $category = 'news'): array
{
    publisher_registry_ensure_schema($dbh);

    $name = publisher_registry_normalize_name($name);
    if ($name === '') {
        return ['ok' => false, 'error' => 'empty_name'];
    }
    if (mb_strlen($name) < 2) {
        return ['ok' => false, 'error' => 'name_too_short'];
    }

    $category = strtolower(trim($category));
    if (!isset(publisher_categories()[$category])) {
        $category = 'news';
    }

    if (publisher_registry_name_is_registered($dbh, $name)) {
        return ['ok' => false, 'error' => 'already_registered'];
    }

    if (!publisher_registry_option_is_available($dbh, $name)) {
        return ['ok' => false, 'error' => 'already_registered'];
    }

    foreach (publisher_registry_catalog_names() as $row) {
        if (mb_strtolower((string)$row['name']) === mb_strtolower($name)) {
            require_once __DIR__ . '/publisher_authority.php';
            if (!publisher_authority_is_approved($dbh, $name)) {
                return ['ok' => false, 'error' => 'approval_required'];
            }
            return [
                'ok' => true,
                'name' => (string)$row['name'],
                'category' => (string)$row['category'],
                'source' => 'catalog',
                'status' => 'approved',
            ];
        }
    }

    try {
        $st = $dbh->prepare('SELECT id, name, category FROM publisher_name_options WHERE LOWER(name) = LOWER(:name) LIMIT 1');
        $st->execute([':name' => $name]);
        $existing = $st->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            require_once __DIR__ . '/publisher_organization_bridge.php';
            $orgId = publisher_org_ensure_for_publisher_name($dbh, $name, $category);

            return [
                'ok' => true,
                'name' => publisher_registry_normalize_name((string)($existing['name'] ?? $name)),
                'category' => strtolower(trim((string)($existing['category'] ?? $category))),
                'source' => 'custom',
                'status' => 'approved',
                'org_id' => $orgId,
            ];
        }

        require_once __DIR__ . '/publisher_authority.php';
        if (publisher_registry_requires_authority($dbh, $name) && !publisher_authority_is_approved($dbh, $name)) {
            return ['ok' => false, 'error' => 'approval_required'];
        }

        return ['ok' => false, 'error' => 'approval_required'];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'save_failed'];
    }
}
