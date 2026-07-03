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

function publisher_categories(): array
{
    return [
        'news' => 'News',
        'sports' => 'Sports',
        'business' => 'Business',
        'science' => 'Science',
        'music' => 'Music',
        'arts' => 'Arts & Painting',
        'agriculture' => 'Agriculture',
        'auto' => 'Auto',
        'political' => 'Political',
    ];
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

function publisher_search(PDO $dbh, string $query, int $limit = 20): array
{
    publisher_ensure_schema($dbh);
    $query = trim($query);
    if ($query === '') {
        return [];
    }
    $limit = max(1, min($limit, 50));
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
              )
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

    publisher_ensure_schema($dbh);

    $cols = [
        'id', 'name', 'username', 'email', 'password', 'friend_code', 'image', 'role', 'status',
        'gender', 'mobile', 'designation',
    ];
    if (publisher_db_column_exists($dbh, 'users', 'account_kind')) {
        $cols[] = 'account_kind';
    }
    if (publisher_db_column_exists($dbh, 'users', 'publisher_category')) {
        $cols[] = 'publisher_category';
    }
    if (publisher_db_column_exists($dbh, 'users', 'publisher_tagline')) {
        $cols[] = 'publisher_tagline';
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
        return null;
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

/** Bind BUSINESS_ONLY_USER session to one publisher account (owner login). */
function publisher_session_bind_owner(PDO $dbh, int $publisherUserId): void
{
    if ($publisherUserId <= 0) {
        publisher_session_clear_identity();
        return;
    }

    $_SESSION['session_user_id'] = $publisherUserId;
    $_SESSION['publisher_session_user_id'] = $publisherUserId;
    $_SESSION['publisher_session_owner'] = 1;
    unset($_SESSION['publisher_session_staff_id']);

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

/**
 * Ensure publisher/staff sessions still map to one unique users.id row.
 * Returns false when identity is missing or inconsistent (caller should log out).
 */
function publisher_session_validate(PDO $dbh): bool
{
    $userId = (int)($_SESSION['user_id'] ?? 0);
    if ($userId <= 0) {
        return false;
    }

    $row = publisher_session_load_user_row($dbh, $userId);
    if (!$row) {
        return false;
    }

    $isPublisherRow = publisher_user_row_looks_like_publisher($dbh, $row);
    $sessionKind = strtolower(trim((string)($_SESSION['user_account_kind'] ?? '')));
    $isPublisherSession = $isPublisherRow
        || $sessionKind === 'publisher'
        || publisher_is_staff_workspace_session();

    if (!$isPublisherSession) {
        publisher_session_clear_identity();
        $_SESSION['session_user_id'] = $userId;
        return true;
    }

    $canonical = (int)($_SESSION['publisher_session_user_id'] ?? $_SESSION['session_user_id'] ?? 0);
    if ($canonical <= 0) {
        if (publisher_is_staff_workspace_session()) {
            publisher_session_bind_staff(
                $userId,
                (int)($_SESSION['staff_account_id'] ?? 0),
                (int)($_SESSION['staff_org_id'] ?? 0)
            );
        } else {
            publisher_session_bind_owner($dbh, $userId);
        }
        $canonical = $userId;
    }

    if ($canonical !== $userId) {
        return false;
    }

    if (publisher_is_staff_workspace_session()) {
        $staffId = (int)($_SESSION['staff_account_id'] ?? $_SESSION['publisher_session_staff_id'] ?? 0);
        $orgId = (int)($_SESSION['staff_org_id'] ?? $_SESSION['publisher_org_id'] ?? 0);
        if ($staffId <= 0 || $orgId <= 0) {
            return false;
        }
        if (!function_exists('staff_pub_staff_can_access_org')) {
            require_once __DIR__ . '/staff_publisher_access.php';
        }
        if (!staff_pub_staff_can_access_org($dbh, $staffId, $orgId)) {
            return false;
        }
        if (!function_exists('staff_pub_org_publisher_user_id')) {
            require_once __DIR__ . '/staff_publisher_access.php';
        }
        return staff_pub_org_publisher_user_id($dbh, $orgId) === $userId;
    }

    if (!$isPublisherRow) {
        return false;
    }

    if ((int)($_SESSION['publisher_session_owner'] ?? 0) !== 1) {
        return false;
    }

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

    $previousName = session_name();
    $wasActive = session_status() === PHP_SESSION_ACTIVE;
    if ($wasActive) {
        session_write_close();
    }

    session_name('BUSINESS_ONLY_USER');
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
    if ($wasActive || session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
}

function publisher_post_visibility(PDO $dbh, int $userId, string $requested): string
{
    if (publisher_account_is($dbh, $userId)) {
        return 'public';
    }
    $requested = strtolower(trim($requested));
    return in_array($requested, ['public', 'friends'], true) ? $requested : 'public';
}

/** After posting, publishers stay on feed.php (brand / star workspace). */
function publisher_post_redirect(PDO $dbh, int $userId, string $visibility): string
{
    if (publisher_account_is($dbh, $userId)) {
        return 'feed.php';
    }
    return strtolower(trim($visibility)) === 'public' ? 'public.php' : 'feed.php';
}

/**
 * Posts that belong in feed.php:
 * - friends-only posts from me or my friends (personal users)
 * - public posts from publishers I follow
 * - my own public posts when I am a publisher
 *
 * Publisher workspace feed.php is empty until the publisher follows another publisher
 * (or publishes their own content). Unfollowed publisher posts stay on public.php.
 */
function publisher_workspace_feed_scope_sql(): string
{
    return "(
        p.visibility = 'public'
        AND EXISTS (
            SELECT 1 FROM users wu
            WHERE wu.id = p.user_id
              AND COALESCE(wu.account_kind, 'personal') = 'publisher'
        )
        AND (
            p.user_id = :wsFeedMe
            OR EXISTS (
                SELECT 1 FROM public_follows pf
                WHERE pf.follower_id = :wsFeedMe2 AND pf.following_id = p.user_id
            )
        )
    )";
}

function publisher_feed_list_scope_sql(): string
{
    return "(
        (p.visibility = 'friends' AND (p.user_id = :scopeMe OR EXISTS (
            SELECT 1 FROM user_contacts uc
            WHERE uc.owner_user_id = :scopeMe2 AND uc.friend_user_id = p.user_id
        )))
        OR
        (p.visibility = 'public' AND EXISTS (
            SELECT 1 FROM public_follows pf
            INNER JOIN users pu ON pu.id = pf.following_id
            WHERE pf.follower_id = :scopeMe3 AND pf.following_id = p.user_id
              AND COALESCE(pu.account_kind, 'personal') = 'publisher'
        ))
        OR
        (p.visibility = 'public' AND p.user_id = :scopeMeOwn AND EXISTS (
            SELECT 1 FROM users ou
            WHERE ou.id = p.user_id AND COALESCE(ou.account_kind, 'personal') = 'publisher'
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
        ':scopeMe' => $meId,
        ':scopeMe2' => $meId,
        ':scopeMe3' => $meId,
        ':scopeMeOwn' => $meId,
    ];
}

function publisher_feed_list_scope_params_for(PDO $dbh, int $meId): array
{
    if (publisher_workspace_viewer($dbh, $meId)) {
        return [
            ':wsFeedMe' => $meId,
            ':wsFeedMe2' => $meId,
        ];
    }
    return publisher_feed_list_scope_params($meId);
}

function publisher_feed_unread_scope_named_sql(): string
{
    return "(
        (p.visibility = 'friends' AND (p.user_id = :unreadMe OR EXISTS (
            SELECT 1 FROM user_contacts uc WHERE uc.owner_user_id = :unreadMe2 AND uc.friend_user_id = p.user_id
        )))
        OR
        (p.visibility = 'public' AND EXISTS (
            SELECT 1 FROM public_follows pf
            INNER JOIN users pu ON pu.id = pf.following_id
            WHERE pf.follower_id = :unreadMe3 AND pf.following_id = p.user_id
              AND COALESCE(pu.account_kind, 'personal') = 'publisher'
        ))
        OR
        (p.visibility = 'public' AND p.user_id = :unreadMe4 AND EXISTS (
            SELECT 1 FROM users ou
            WHERE ou.id = p.user_id AND COALESCE(ou.account_kind, 'personal') = 'publisher'
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
            ':wsFeedMe2' => $meId,
        ];
    }
    return [
        ':unreadMe' => $meId,
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
        if (!$authorIsPublisher) {
            return false;
        }
        if ($authorId === $meId) {
            return true;
        }
        return publisher_user_is_followed($dbh, $meId, $authorId);
    }

    if ($authorIsPublisher) {
        if ($authorId === $meId) {
            return false;
        }
        return publisher_user_is_followed($dbh, $meId, $authorId);
    }

    if ($authorId === $meId) {
        return true;
    }

    $vis = strtolower(trim((string)($post['visibility'] ?? 'public')));
    if ($vis === 'friends') {
        if (!function_exists('fs_are_friends')) {
            require_once __DIR__ . '/friend_system.php';
        }
        return fs_are_friends($dbh, $meId, $authorId);
    }

    return $vis === 'public';
}

/** Personal users browse unfollowed publisher public posts on public.php. */
function publisher_post_visible_on_public_surface(PDO $dbh, int $meId, array $post): bool
{
    $authorId = (int)($post['user_id'] ?? 0);
    if ($authorId <= 0) {
        return false;
    }

    if (publisher_workspace_viewer($dbh, $meId)) {
        if (!publisher_is_publisher_user($dbh, $authorId)) {
            return false;
        }
        if ($authorId === $meId) {
            return true;
        }
        return !publisher_user_is_followed($dbh, $meId, $authorId);
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

/** Viewing on public.php is browse-only until the user follows the publisher. */
function publisher_post_interaction_allowed(PDO $dbh, int $meId, array $post): bool
{
    if (function_exists('staff_pub_is_readonly') && staff_pub_is_readonly()) {
        return false;
    }

    $authorId = (int)($post['user_id'] ?? 0);
    if ($authorId <= 0 || !publisher_can_view_post($dbh, $meId, $post)) {
        return false;
    }

    if (publisher_workspace_viewer($dbh, $meId)) {
        return publisher_is_publisher_user($dbh, $authorId);
    }

    if (publisher_is_publisher_user($dbh, $authorId)) {
        return publisher_user_is_followed($dbh, $meId, $authorId);
    }

    return publisher_feed_can_view_post($dbh, $meId, $post);
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

    $viewIsPublisher = publisher_is_publisher_user($dbh, $viewId);

    if (publisher_workspace_viewer($dbh, $meId)) {
        return $viewIsPublisher;
    }

    if ($viewIsPublisher) {
        return true;
    }

    return true;
}

/**
 * public.php / news.php list scope.
 * - news.php: publisher-authored public posts only (your own + unfollowed publishers).
 *   Followed publisher posts appear in feed.php instead.
 * - Workspace viewers on public.php: your own + unfollowed publisher posts.
 * - Personal users on public.php: unfollowed publisher posts (+ personal public posts).
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
        return publisher_news_surface_scope_sql(':pubWsMe', ':pubWsDiscMe');
    }

    return publisher_public_discover_exclude_followed_sql(':pubDiscMe');
}

function publisher_public_surface_scope_params(PDO $dbh, int $meId, bool $newsSurface): array
{
    if ($newsSurface) {
        return publisher_news_list_scope_params($meId);
    }

    if (publisher_workspace_viewer($dbh, $meId)) {
        return [
            ':pubWsMe' => $meId,
            ':pubWsDiscMe' => $meId,
        ];
    }

    return publisher_public_discover_exclude_followed_params($meId);
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
 * profile.php Posts tab (feed_api filter=author): list by profile owner, not feed discover rules.
 * Staff and personal users may browse all public posts on a publisher profile without following.
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
        return "p.visibility = 'public'";
    }

    if (publisher_workspace_viewer($dbh, $viewerId)) {
        return '0=1';
    }

    return "(
        p.visibility = 'public'
        OR (
            p.visibility = 'friends'
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

    if (publisher_is_publisher_user($dbh, $authorId) || publisher_workspace_viewer($dbh, $viewerId)) {
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

    $rows = [];
    foreach (news_publishers_catalog() as $row) {
        $label = publisher_registry_normalize_name((string)($row['label'] ?? ''));
        if ($label === '') {
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
