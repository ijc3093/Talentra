<?php
declare(strict_types=1);

/**
 * Connect public_user publisher registration to organization/ company records.
 * Custom publisher names saved on register.php create an organizations row automatically.
 */

require_once __DIR__ . '/publisher_accounts_load.php';

function publisher_org_db_column_exists(PDO $dbh, string $table, string $column): bool
{
    if (function_exists('publisher_db_column_exists')) {
        return publisher_db_column_exists($dbh, $table, $column);
    }

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

function publisher_org_ensure_schema(PDO $dbh): void
{
    publisher_registry_ensure_schema($dbh);

    $needs = static function (PDO $dbh): bool {
        return !publisher_org_db_column_exists($dbh, 'organizations', 'is_publisher_org')
            || !publisher_org_db_column_exists($dbh, 'organizations', 'publisher_user_id')
            || !publisher_org_db_column_exists($dbh, 'organizations', 'publisher_category')
            || !publisher_org_db_column_exists($dbh, 'publisher_name_options', 'org_id')
            || !publisher_org_db_column_exists($dbh, 'managers', 'publisher_user_id');
    };

    static $done = false;
    if ($done && !$needs($dbh)) {
        return;
    }

    try {
        if (!publisher_org_db_column_exists($dbh, 'organizations', 'is_publisher_org')) {
            try {
                $dbh->exec("ALTER TABLE organizations ADD COLUMN is_publisher_org TINYINT(1) NOT NULL DEFAULT 0 AFTER status");
            } catch (Throwable $e) {
                $dbh->exec('ALTER TABLE organizations ADD COLUMN is_publisher_org TINYINT(1) NOT NULL DEFAULT 0');
            }
        }
        if (!publisher_org_db_column_exists($dbh, 'organizations', 'publisher_user_id')) {
            try {
                $dbh->exec('ALTER TABLE organizations ADD COLUMN publisher_user_id BIGINT NULL DEFAULT NULL AFTER is_publisher_org');
            } catch (Throwable $e) {
                $dbh->exec('ALTER TABLE organizations ADD COLUMN publisher_user_id BIGINT NULL DEFAULT NULL');
            }
        }
        if (!publisher_org_db_column_exists($dbh, 'organizations', 'publisher_category')) {
            try {
                $dbh->exec("ALTER TABLE organizations ADD COLUMN publisher_category VARCHAR(40) NOT NULL DEFAULT '' AFTER publisher_user_id");
            } catch (Throwable $e) {
                $dbh->exec("ALTER TABLE organizations ADD COLUMN publisher_category VARCHAR(40) NOT NULL DEFAULT ''");
            }
        }
        if (!publisher_org_db_column_exists($dbh, 'publisher_name_options', 'org_id')) {
            try {
                $dbh->exec('ALTER TABLE publisher_name_options ADD COLUMN org_id BIGINT UNSIGNED NULL DEFAULT NULL AFTER category');
            } catch (Throwable $e) {
                $dbh->exec('ALTER TABLE publisher_name_options ADD COLUMN org_id BIGINT UNSIGNED NULL DEFAULT NULL');
            }
        }
        if (!publisher_org_db_column_exists($dbh, 'managers', 'publisher_user_id')) {
            try {
                $dbh->exec('ALTER TABLE managers ADD COLUMN publisher_user_id BIGINT NULL DEFAULT NULL AFTER status');
            } catch (Throwable $e) {
                $dbh->exec('ALTER TABLE managers ADD COLUMN publisher_user_id BIGINT NULL DEFAULT NULL');
            }
        }
    } catch (Throwable $e) {
        // Non-fatal — org linking is best-effort.
    }

    $done = !$needs($dbh);
}

function publisher_org_manager_has_publisher_link(PDO $dbh): bool
{
    return publisher_org_db_column_exists($dbh, 'managers', 'publisher_user_id');
}

function publisher_org_organizations_has_publisher_columns(PDO $dbh): bool
{
    return publisher_org_db_column_exists($dbh, 'organizations', 'is_publisher_org')
        && publisher_org_db_column_exists($dbh, 'organizations', 'publisher_user_id');
}

function publisher_org_ensure_manager_membership(PDO $dbh, int $orgId, int $managerId): void
{
    if ($orgId <= 0 || $managerId <= 0) {
        return;
    }

    $roles = publisher_org_ensure_default_roles($dbh, $orgId);
    $managerRoleId = (int)($roles['manager'] ?? 0);
    if ($managerRoleId <= 0) {
        throw new RuntimeException('Manager role missing for organization.');
    }

    $stMember = $dbh->prepare('
        INSERT IGNORE INTO org_members
            (org_id, member_type, member_id, role_id, relationship_label, status, joined_at, created_at)
        VALUES
            (:org, \'manager\', :mid, :role, NULL, 1, NOW(), NOW())
    ');
    $stMember->execute([
        ':org' => $orgId,
        ':mid' => $managerId,
        ':role' => $managerRoleId,
    ]);

    $stGetMember = $dbh->prepare('
        SELECT id
        FROM org_members
        WHERE org_id = :org
          AND member_type = \'manager\'
          AND member_id = :mid
        ORDER BY id DESC
        LIMIT 1
    ');
    $stGetMember->execute([':org' => $orgId, ':mid' => $managerId]);
    $orgMemberId = (int)($stGetMember->fetchColumn() ?: 0);
    if ($orgMemberId <= 0) {
        throw new RuntimeException('Could not resolve organization manager membership.');
    }

    $stOrgUser = $dbh->prepare('
        INSERT INTO organization_users (org_id, user_id, role, joined_at)
        VALUES (:org, :uid, \'manager\', NOW())
        ON DUPLICATE KEY UPDATE role = VALUES(role)
    ');
    $stOrgUser->execute([
        ':org' => $orgId,
        ':uid' => $orgMemberId,
    ]);
}

function publisher_org_manager_for_publisher(PDO $dbh, int $publisherUserId): int
{
    publisher_org_ensure_schema($dbh);

    if ($publisherUserId <= 0) {
        return 0;
    }

    if (publisher_org_manager_has_publisher_link($dbh)) {
        try {
            $st = $dbh->prepare('SELECT id FROM managers WHERE publisher_user_id = :uid LIMIT 1');
            $st->execute([':uid' => $publisherUserId]);
            $id = (int)($st->fetchColumn() ?: 0);
            if ($id > 0) {
                return $id;
            }
        } catch (Throwable $e) {
            // fall through to username lookup
        }
    }

    try {
        $stUser = $dbh->prepare('SELECT username, email FROM users WHERE id = :id LIMIT 1');
        $stUser->execute([':id' => $publisherUserId]);
        $user = $stUser->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
            return 0;
        }

        $st = $dbh->prepare('SELECT id FROM managers WHERE username = :u OR email = :e LIMIT 1');
        $st->execute([
            ':u' => trim((string)($user['username'] ?? '')),
            ':e' => trim((string)($user['email'] ?? '')),
        ]);
        return (int)($st->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        return 0;
    }
}

/** True when the public_user publisher session is an organization manager (not org staff). */
function publisher_org_public_user_is_manager(PDO $dbh, int $publisherUserId = 0): bool
{
    if ($publisherUserId <= 0) {
        $publisherUserId = (int)($_SESSION['user_id'] ?? 0);
    }
    if ($publisherUserId <= 0) {
        return false;
    }

    if (!empty($_SESSION['staff_publisher_mode']) && (int)($_SESSION['staff_account_id'] ?? 0) > 0) {
        return false;
    }
    if ((int)($_SESSION['publisher_session_staff_id'] ?? 0) > 0) {
        return false;
    }

    return publisher_org_manager_for_publisher($dbh, $publisherUserId) > 0;
}

/** Personal users + organization managers may use Live Studio; org staff may not. */
function live_studio_user_can_access(PDO $dbh, int $userId = 0): bool
{
    if ($userId <= 0) {
        $userId = (int)($_SESSION['user_id'] ?? 0);
    }
    if ($userId <= 0) {
        return false;
    }

    if (!empty($_SESSION['staff_publisher_mode']) && (int)($_SESSION['staff_account_id'] ?? 0) > 0) {
        return false;
    }
    if ((int)($_SESSION['publisher_session_staff_id'] ?? 0) > 0) {
        return false;
    }

    if (strtolower(trim((string)($_SESSION['user_account_kind'] ?? ''))) !== 'publisher') {
        return true;
    }

    return publisher_org_manager_for_publisher($dbh, $userId) > 0;
}

function publisher_org_manager_username_taken(PDO $dbh, string $username, string $email, int $ignorePublisherUserId = 0): bool
{
    publisher_org_ensure_schema($dbh);

    $username = trim($username);
    $email = trim($email);
    if ($username === '' && $email === '') {
        return false;
    }

    try {
        $st = $dbh->prepare('
            SELECT id
            FROM managers
            WHERE (username = :u OR email = :e)
              AND (publisher_user_id IS NULL OR publisher_user_id <> :ignore)
            LIMIT 1
        ');
        $st->execute([
            ':u' => $username,
            ':e' => $email,
            ':ignore' => max(0, $ignorePublisherUserId),
        ]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function publisher_org_ensure_manager_for_publisher(
    PDO $dbh,
    int $publisherUserId,
    string $username,
    string $email,
    string $passwordHash,
    string $fullname
): int {
    publisher_org_ensure_schema($dbh);

    if ($publisherUserId <= 0) {
        return 0;
    }

    $username = trim($username);
    $email = trim($email);
    $fullname = publisher_registry_normalize_name($fullname);
    if ($fullname === '') {
        $fullname = $username !== '' ? $username : 'Publisher';
    }

    $existingId = publisher_org_manager_for_publisher($dbh, $publisherUserId);
    if ($existingId > 0) {
        try {
            if (publisher_org_manager_has_publisher_link($dbh)) {
                $st = $dbh->prepare('
                    UPDATE managers
                    SET username = :u,
                        email = :e,
                        password = :pw,
                        fullname = :fn,
                        status = 1,
                        publisher_user_id = :uid
                    WHERE id = :id
                ');
                $st->execute([
                    ':u' => $username,
                    ':e' => $email,
                    ':pw' => $passwordHash,
                    ':fn' => $fullname,
                    ':uid' => $publisherUserId,
                    ':id' => $existingId,
                ]);
            } else {
                $st = $dbh->prepare('
                    UPDATE managers
                    SET username = :u,
                        email = :e,
                        password = :pw,
                        fullname = :fn,
                        status = 1
                    WHERE id = :id
                ');
                $st->execute([
                    ':u' => $username,
                    ':e' => $email,
                    ':pw' => $passwordHash,
                    ':fn' => $fullname,
                    ':id' => $existingId,
                ]);
            }
        } catch (Throwable $e) {
            // ignore update failures; existing row still usable
        }
        return $existingId;
    }

    try {
        $st = $dbh->prepare('SELECT id FROM managers WHERE username = :u OR email = :e LIMIT 1');
        $st->execute([':u' => $username, ':e' => $email]);
        $matchedId = (int)($st->fetchColumn() ?: 0);
        if ($matchedId > 0) {
            if (publisher_org_manager_has_publisher_link($dbh)) {
                $st = $dbh->prepare('
                    UPDATE managers
                    SET password = :pw,
                        fullname = :fn,
                        status = 1,
                        publisher_user_id = :uid
                    WHERE id = :id
                ');
                $st->execute([
                    ':pw' => $passwordHash,
                    ':fn' => $fullname,
                    ':uid' => $publisherUserId,
                    ':id' => $matchedId,
                ]);
            } else {
                $st = $dbh->prepare('
                    UPDATE managers
                    SET password = :pw,
                        fullname = :fn,
                        status = 1
                    WHERE id = :id
                ');
                $st->execute([
                    ':pw' => $passwordHash,
                    ':fn' => $fullname,
                    ':id' => $matchedId,
                ]);
            }
            return $matchedId;
        }

        $friendCode = 'MGR-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 4)) . '-' . random_int(1000, 9999);

        if (publisher_org_manager_has_publisher_link($dbh)) {
            $ins = $dbh->prepare('
                INSERT INTO managers (friend_code, username, email, password, fullname, status, force_password_change, publisher_user_id)
                VALUES (:fc, :u, :e, :pw, :fn, 1, 0, :uid)
            ');
            $ins->execute([
                ':fc' => $friendCode,
                ':u' => $username,
                ':e' => $email,
                ':pw' => $passwordHash,
                ':fn' => $fullname,
                ':uid' => $publisherUserId,
            ]);
        } else {
            $ins = $dbh->prepare('
                INSERT INTO managers (friend_code, username, email, password, fullname, status, force_password_change)
                VALUES (:fc, :u, :e, :pw, :fn, 1, 0)
            ');
            $ins->execute([
                ':fc' => $friendCode,
                ':u' => $username,
                ':e' => $email,
                ':pw' => $passwordHash,
                ':fn' => $fullname,
            ]);
        }

        return (int)$dbh->lastInsertId();
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * Full publisher registration hook:
 * - organization company (publisher name)
 * - managers row (same username/password as public_user signup)
 * - membership + ownership like create_org.php
 */
function publisher_org_provision_publisher_account(
    PDO $dbh,
    int $publisherUserId,
    string $publisherName,
    string $username,
    string $email,
    string $passwordHash,
    string $category = 'news'
): int {
    publisher_org_ensure_schema($dbh);

    if ($publisherUserId <= 0) {
        return 0;
    }

    $publisherName = publisher_registry_normalize_name($publisherName);
    if ($publisherName === '') {
        return 0;
    }

    $category = strtolower(trim($category));
    if (!isset(publisher_categories()[$category])) {
        $category = 'news';
    }

    $managerId = publisher_org_ensure_manager_for_publisher(
        $dbh,
        $publisherUserId,
        $username,
        $email,
        $passwordHash,
        $publisherName
    );
    if ($managerId <= 0) {
        return 0;
    }

    $orgId = publisher_org_ensure_for_publisher_name($dbh, $publisherName, $category);
    if ($orgId <= 0) {
        return 0;
    }

    $logoText = mb_substr($publisherName, 0, 40);

    try {
        $dbh->beginTransaction();

        if (publisher_org_organizations_has_publisher_columns($dbh)) {
            $st = $dbh->prepare('
                UPDATE organizations
                SET owner_manager_id = :mid,
                    name = :name,
                    publisher_user_id = :uid,
                    publisher_category = :cat,
                    is_publisher_org = 1,
                    updated_at = NOW()
                WHERE id = :id
            ');
            $st->execute([
                ':mid' => $managerId,
                ':name' => $publisherName,
                ':uid' => $publisherUserId,
                ':cat' => $category,
                ':id' => $orgId,
            ]);
        } else {
            $st = $dbh->prepare('
                UPDATE organizations
                SET owner_manager_id = :mid,
                    name = :name,
                    updated_at = NOW()
                WHERE id = :id
            ');
            $st->execute([
                ':mid' => $managerId,
                ':name' => $publisherName,
                ':id' => $orgId,
            ]);
        }

        $stSettings = $dbh->prepare('SELECT org_id FROM org_settings WHERE org_id = :org LIMIT 1');
        $stSettings->execute([':org' => $orgId]);
        if ($stSettings->fetchColumn()) {
            $stUpdateSettings = $dbh->prepare('UPDATE org_settings SET logo_text = :logo, updated_at = NOW() WHERE org_id = :org');
            $stUpdateSettings->execute([':logo' => $logoText, ':org' => $orgId]);
        } else {
            $stInsertSettings = $dbh->prepare('
                INSERT INTO org_settings (org_id, logo_type, logo_text, updated_at)
                VALUES (:org, \'text\', :logo, NOW())
            ');
            $stInsertSettings->execute([':org' => $orgId, ':logo' => $logoText]);
        }

        publisher_org_ensure_manager_membership($dbh, $orgId, $managerId);
        publisher_org_registry_set_org_id($dbh, $publisherName, $orgId);

        $dbh->commit();
        return $orgId;
    } catch (Throwable $e) {
        if ($dbh->inTransaction()) {
            $dbh->rollBack();
        }
        return 0;
    }
}

/** @return list<array{id:int,name:string,org_code:string}> */
function publisher_org_list_for_public_user(PDO $dbh, int $publisherUserId): array
{
    publisher_org_ensure_schema($dbh);

    if ($publisherUserId <= 0) {
        return [];
    }

    publisher_org_sync_public_user_orgs($dbh, $publisherUserId);

    return publisher_org_fetch_public_user_orgs($dbh, $publisherUserId);
}

function publisher_org_fetch_public_user_orgs(PDO $dbh, int $publisherUserId): array
{
    if ($publisherUserId <= 0) {
        return [];
    }

    try {
        $st = $dbh->prepare('
            SELECT id, name, org_code
            FROM organizations
            WHERE status = 1
              AND publisher_user_id = :uid
            ORDER BY name ASC, id ASC
        ');
        $st->execute([':uid' => $publisherUserId]);
        $orgs = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($orgs) {
            return $orgs;
        }
    } catch (Throwable $e) {
        // fall through to name-based lookup
    }

    try {
        $stUser = $dbh->prepare('SELECT name FROM users WHERE id = :id LIMIT 1');
        $stUser->execute([':id' => $publisherUserId]);
        $publisherName = publisher_registry_normalize_name((string)($stUser->fetchColumn() ?: ''));
        if ($publisherName === '') {
            return [];
        }

        $st = $dbh->prepare('
            SELECT id, name, org_code
            FROM organizations
            WHERE status = 1
              AND is_publisher_org = 1
              AND LOWER(name) = LOWER(:name)
            ORDER BY id ASC
            LIMIT 1
        ');
        $st->execute([':name' => $publisherName]);
        $org = $st->fetch(PDO::FETCH_ASSOC);
        if (!$org) {
            return [];
        }

        $orgId = (int)($org['id'] ?? 0);
        if ($orgId > 0) {
            publisher_org_link_publisher_user_id($dbh, $orgId, $publisherUserId);
        }

        return [$org];
    } catch (Throwable $e) {
        return [];
    }
}

function publisher_org_sync_public_user_orgs(PDO $dbh, int $publisherUserId): void
{
    if ($publisherUserId <= 0) {
        return;
    }

    publisher_org_ensure_schema($dbh);

    try {
        $stUser = $dbh->prepare('
            SELECT name, username, email, password, publisher_category
            FROM users
            WHERE id = :id
            LIMIT 1
        ');
        $stUser->execute([':id' => $publisherUserId]);
        $user = $stUser->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
            return;
        }

        $publisherName = publisher_registry_normalize_name((string)($user['name'] ?? ''));
        if ($publisherName === '') {
            return;
        }

        if (publisher_org_db_column_exists($dbh, 'organizations', 'publisher_user_id')) {
            $st = $dbh->prepare('
                UPDATE organizations o
                INNER JOIN managers m ON m.id = o.owner_manager_id
                SET o.publisher_user_id = m.publisher_user_id,
                    o.updated_at = NOW()
                WHERE o.status = 1
                  AND o.is_publisher_org = 1
                  AND (o.publisher_user_id IS NULL OR o.publisher_user_id = 0)
                  AND m.publisher_user_id = :uid
            ');
            $st->execute([':uid' => $publisherUserId]);

            $st = $dbh->prepare('
                UPDATE organizations
                SET publisher_user_id = :set_uid, updated_at = NOW()
                WHERE status = 1
                  AND is_publisher_org = 1
                  AND LOWER(name) = LOWER(:name)
                  AND (publisher_user_id IS NULL OR publisher_user_id = 0 OR publisher_user_id = :match_uid)
            ');
            $st->execute([
                ':set_uid' => $publisherUserId,
                ':match_uid' => $publisherUserId,
                ':name' => $publisherName,
            ]);
        }

        if (publisher_org_fetch_public_user_orgs($dbh, $publisherUserId) !== []) {
            return;
        }

        $category = strtolower(trim((string)($user['publisher_category'] ?? 'news')));
        if (!isset(publisher_categories()[$category])) {
            $category = 'news';
        }

        publisher_org_provision_publisher_account(
            $dbh,
            $publisherUserId,
            $publisherName,
            trim((string)($user['username'] ?? '')),
            trim((string)($user['email'] ?? '')),
            (string)($user['password'] ?? ''),
            $category
        );
    } catch (Throwable $e) {
        // best-effort sync
    }
}

/** Companies for the left menu after publisher registration. */
function publisher_org_menu_companies_for_user(PDO $dbh, int $userId): array
{
    if ($userId <= 0) {
        return [];
    }

    publisher_ensure_schema($dbh);
    publisher_org_ensure_schema($dbh);

    try {
        $userCols = ['id', 'name', 'username', 'email', 'password', 'friend_code', 'status'];
        if (publisher_db_column_exists($dbh, 'users', 'account_kind')) {
            $userCols[] = 'account_kind';
        }
        if (publisher_db_column_exists($dbh, 'users', 'publisher_category')) {
            $userCols[] = 'publisher_category';
        }

        $st = $dbh->prepare('SELECT ' . implode(', ', $userCols) . ' FROM users WHERE id = :id LIMIT 1');
        $st->execute([':id' => $userId]);
        $user = $st->fetch(PDO::FETCH_ASSOC);
        if (!$user || (int)($user['status'] ?? 0) !== 1) {
            return [];
        }

        $sessionKind = strtolower(trim((string)($_SESSION['user_account_kind'] ?? '')));
        $looksPublisher = publisher_user_row_looks_like_publisher($dbh, $user) || $sessionKind === 'publisher';
        if (!$looksPublisher) {
            return [];
        }

        publisher_repair_user_as_publisher(
            $dbh,
            $userId,
            trim((string)($user['publisher_category'] ?? ''))
        );

        publisher_org_sync_public_user_orgs($dbh, $userId);

        return publisher_org_fetch_public_user_orgs($dbh, $userId);
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Resolve the publisher's organization id for portal links.
 */
function publisher_org_resolve_user_org_id(PDO $dbh, int $publisherUserId, string $publisherName = ''): int
{
    if ($publisherUserId <= 0) {
        return 0;
    }

    publisher_org_ensure_schema($dbh);
    publisher_org_sync_public_user_orgs($dbh, $publisherUserId);

    $orgId = (int)(publisher_org_fetch_public_user_orgs($dbh, $publisherUserId)[0]['id'] ?? 0);
    if ($orgId > 0) {
        return $orgId;
    }

    $publisherName = publisher_registry_normalize_name($publisherName);
    if ($publisherName === '') {
        try {
            $st = $dbh->prepare('SELECT name FROM users WHERE id = :id LIMIT 1');
            $st->execute([':id' => $publisherUserId]);
            $publisherName = publisher_registry_normalize_name((string)($st->fetchColumn() ?: ''));
        } catch (Throwable $e) {
            $publisherName = '';
        }
    }

    if ($publisherName !== '') {
        $orgId = publisher_org_registry_org_id($dbh, $publisherName);
        if ($orgId > 0) {
            return $orgId;
        }
    }

    $sessionOrgId = (int)($_SESSION['publisher_org_id'] ?? $_SESSION['org_active_org_id'] ?? 0);
    if ($sessionOrgId > 0 && publisher_org_public_user_can_access($dbh, $publisherUserId, $sessionOrgId)) {
        return $sessionOrgId;
    }

    return 0;
}

function publisher_org_portal_href_for_user(PDO $dbh, int $publisherUserId, string $publisherName = ''): string
{
    $orgId = publisher_org_resolve_user_org_id($dbh, $publisherUserId, $publisherName);

    return $orgId > 0
        ? 'publisher_org_portal.php?org_id=' . $orgId
        : 'publisher_org_portal.php';
}

/**
 * Publisher's own company link for the left menu (publishers only; personal users get []).
 *
 * @return list<array{user_id:int,name:string,username:string,org_id:int,is_self:bool,href:string}>
 */
function publisher_menu_own_company(PDO $dbh, int $viewerUserId): array
{
    if ($viewerUserId <= 0) {
        return [];
    }

    publisher_ensure_schema($dbh);
    publisher_org_ensure_schema($dbh);

    try {
        $st = $dbh->prepare('
            SELECT id, name, username, friend_code, account_kind, publisher_category, status
            FROM users
            WHERE id = :id
            LIMIT 1
        ');
        $st->execute([':id' => $viewerUserId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row || (int)($row['status'] ?? 0) !== 1) {
            return [];
        }

        $sessionKind = strtolower(trim((string)($_SESSION['user_account_kind'] ?? '')));
        if (!publisher_user_row_looks_like_publisher($dbh, $row) && $sessionKind !== 'publisher') {
            return [];
        }

        publisher_repair_user_as_publisher(
            $dbh,
            $viewerUserId,
            trim((string)($row['publisher_category'] ?? ''))
        );
        publisher_org_sync_public_user_orgs($dbh, $viewerUserId);

        $name = publisher_registry_normalize_name((string)($row['name'] ?? ''));
        if ($name === '') {
            return [];
        }

        $orgId = publisher_org_resolve_user_org_id($dbh, $viewerUserId, $name);
        $href = publisher_org_portal_href_for_user($dbh, $viewerUserId, $name);

        return [[
            'user_id' => $viewerUserId,
            'name' => $name,
            'username' => trim((string)($row['username'] ?? '')),
            'org_id' => $orgId,
            'is_self' => true,
            'href' => $href,
        ]];
    } catch (Throwable $e) {
        return [];
    }
}

/** @deprecated Use publisher_menu_own_company() — kept for callers migrating gradually. */
function publisher_menu_public_publishers(PDO $dbh, int $viewerUserId = 0): array
{
    return publisher_menu_own_company($dbh, $viewerUserId);
}

function publisher_org_link_publisher_user_id(PDO $dbh, int $orgId, int $publisherUserId): bool
{
    if ($orgId <= 0 || $publisherUserId <= 0) {
        return false;
    }
    if (!publisher_org_db_column_exists($dbh, 'organizations', 'publisher_user_id')) {
        return false;
    }

    try {
        $st = $dbh->prepare('
            UPDATE organizations
            SET publisher_user_id = :set_uid, updated_at = NOW()
            WHERE id = :id
              AND (publisher_user_id IS NULL OR publisher_user_id = 0 OR publisher_user_id = :match_uid)
        ');
        $st->execute([
            ':set_uid' => $publisherUserId,
            ':match_uid' => $publisherUserId,
            ':id' => $orgId,
        ]);

        return $st->rowCount() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function publisher_org_public_user_can_access(PDO $dbh, int $publisherUserId, int $orgId): bool
{
    if ($publisherUserId <= 0 || $orgId <= 0) {
        return false;
    }

    publisher_org_ensure_schema($dbh);

    try {
        $st = $dbh->prepare('
            SELECT 1
            FROM organizations
            WHERE id = :id
              AND publisher_user_id = :uid
              AND status = 1
            LIMIT 1
        ');
        $st->execute([':id' => $orgId, ':uid' => $publisherUserId]);
        if ($st->fetchColumn()) {
            return true;
        }

        $st = $dbh->prepare('
            SELECT 1
            FROM organizations o
            INNER JOIN managers m ON m.id = o.owner_manager_id
            WHERE o.id = :id
              AND o.status = 1
              AND m.publisher_user_id = :uid
            LIMIT 1
        ');
        $st->execute([':id' => $orgId, ':uid' => $publisherUserId]);
        if (!$st->fetchColumn()) {
            return false;
        }

        publisher_org_link_publisher_user_id($dbh, $orgId, $publisherUserId);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/** Open an organization session for a publisher and return manager id. */
function publisher_org_begin_session_for_publisher(PDO $dbh, int $publisherUserId, int $orgId): int
{
    if ($publisherUserId <= 0 || $orgId <= 0) {
        return 0;
    }
    if (!publisher_org_public_user_can_access($dbh, $publisherUserId, $orgId)) {
        return 0;
    }

    $managerId = publisher_org_manager_for_publisher($dbh, $publisherUserId);
    if ($managerId <= 0) {
        return 0;
    }

    try {
        publisher_org_ensure_manager_membership($dbh, $orgId, $managerId);
    } catch (Throwable $e) {
        return 0;
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    session_name('PHPSESSID');
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $_SESSION['org_auth'] = 1;
    $_SESSION['org_account_type'] = 'manager';
    $_SESSION['org_account_id'] = $managerId;
    $_SESSION['org_active_org_id'] = $orgId;
    unset($_SESSION['org_member_id'], $_SESSION['org_role_id']);
    $_SESSION['org_publisher_user_id'] = $publisherUserId;

    $helpers = dirname(__DIR__) . '/public_user/includes/account_display_helpers.php';
    if (is_file($helpers)) {
        require_once $helpers;
        if (function_exists('account_portal_staff_role_label_from_linked_user')) {
            $portalRole = account_portal_staff_role_label_from_linked_user($dbh, $publisherUserId);
            if ($portalRole !== '') {
                $_SESSION['portal_staff_role_label'] = $portalRole;
            }
        }
    }

    return $managerId;
}

function publisher_org_gen_code(): string
{
    $a = strtoupper(substr(bin2hex(random_bytes(3)), 0, 4));
    $b = strtoupper(substr(bin2hex(random_bytes(3)), 0, 4));
    return 'ORG-' . $a . '-' . $b;
}

function publisher_org_ensure_default_roles(PDO $dbh, int $orgId): array
{
    $want = ['Manager', 'Staff'];
    $ids = [];

    foreach ($want as $roleName) {
        $st = $dbh->prepare('SELECT id FROM org_roles WHERE org_id = :org AND name = :name LIMIT 1');
        $st->execute([':org' => $orgId, ':name' => $roleName]);
        $roleId = (int)($st->fetchColumn() ?: 0);

        if ($roleId <= 0) {
            $ins = $dbh->prepare('
                INSERT INTO org_roles (org_id, name, is_system, created_at)
                VALUES (:org, :name, 1, NOW())
            ');
            $ins->execute([':org' => $orgId, ':name' => $roleName]);
            $roleId = (int)$dbh->lastInsertId();
        }

        $ids[strtolower($roleName)] = $roleId;
    }

    return $ids;
}

function publisher_org_system_manager_id(PDO $dbh): int
{
    publisher_org_ensure_schema($dbh);

    $username = 'publisher_orgs';
    $email = 'publisher-orgs@talentra.internal';

    try {
        $st = $dbh->prepare('SELECT id FROM managers WHERE username = :u OR email = :e LIMIT 1');
        $st->execute([':u' => $username, ':e' => $email]);
        $existingId = (int)($st->fetchColumn() ?: 0);
        if ($existingId > 0) {
            return $existingId;
        }

        $friendCode = 'MGR-PUB-' . random_int(1000, 9999);
        $hash = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
        $ins = $dbh->prepare('
            INSERT INTO managers (friend_code, username, email, password, fullname, status, force_password_change, created_at)
            VALUES (:fc, :u, :e, :pw, :fn, 1, 0, NOW())
        ');
        $ins->execute([
            ':fc' => $friendCode,
            ':u' => $username,
            ':e' => $email,
            ':pw' => $hash,
            ':fn' => 'Publisher Organizations',
        ]);

        return (int)$dbh->lastInsertId();
    } catch (Throwable $e) {
        return 0;
    }
}

function publisher_org_find_by_name(PDO $dbh, string $name): int
{
    publisher_org_ensure_schema($dbh);

    $name = publisher_registry_normalize_name($name);
    if ($name === '') {
        return 0;
    }

    try {
        $st = $dbh->prepare('
            SELECT id
            FROM organizations
            WHERE is_publisher_org = 1
              AND LOWER(name) = LOWER(:name)
              AND status = 1
            ORDER BY id ASC
            LIMIT 1
        ');
        $st->execute([':name' => $name]);
        return (int)($st->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        return 0;
    }
}

function publisher_org_registry_org_id(PDO $dbh, string $name): int
{
    publisher_org_ensure_schema($dbh);

    $name = publisher_registry_normalize_name($name);
    if ($name === '') {
        return 0;
    }

    try {
        $st = $dbh->prepare('SELECT org_id FROM publisher_name_options WHERE LOWER(name) = LOWER(:name) LIMIT 1');
        $st->execute([':name' => $name]);
        return (int)($st->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        return 0;
    }
}

function publisher_org_registry_set_org_id(PDO $dbh, string $name, int $orgId): void
{
    if ($orgId <= 0) {
        return;
    }

    publisher_org_ensure_schema($dbh);

    $name = publisher_registry_normalize_name($name);
    if ($name === '') {
        return;
    }

    try {
        $st = $dbh->prepare('UPDATE publisher_name_options SET org_id = :org WHERE LOWER(name) = LOWER(:name)');
        $st->execute([':org' => $orgId, ':name' => $name]);
    } catch (Throwable $e) {
        // ignore
    }
}

function publisher_org_create(PDO $dbh, string $name, string $category = 'news'): int
{
    publisher_org_ensure_schema($dbh);

    $name = publisher_registry_normalize_name($name);
    if ($name === '') {
        return 0;
    }

    $category = strtolower(trim($category));
    if (!isset(publisher_categories()[$category])) {
        $category = 'news';
    }

    $existingId = publisher_org_find_by_name($dbh, $name);
    if ($existingId > 0) {
        publisher_org_registry_set_org_id($dbh, $name, $existingId);
        return $existingId;
    }

    $managerId = publisher_org_system_manager_id($dbh);
    if ($managerId <= 0) {
        return 0;
    }

    $logoText = mb_substr($name, 0, 40);

    try {
        $dbh->beginTransaction();

        $orgCode = publisher_org_gen_code();
        $st = $dbh->prepare('
            INSERT INTO organizations (org_code, name, owner_manager_id, status, is_publisher_org, publisher_user_id, publisher_category, created_at)
            VALUES (:code, :name, :mid, 1, 1, NULL, :cat, NOW())
        ');
        $st->execute([
            ':code' => $orgCode,
            ':name' => $name,
            ':mid' => $managerId,
            ':cat' => $category,
        ]);
        $orgId = (int)$dbh->lastInsertId();
        if ($orgId <= 0) {
            throw new RuntimeException('Could not create publisher organization.');
        }

        $stSettings = $dbh->prepare('
            INSERT INTO org_settings (org_id, logo_type, logo_text, updated_at)
            VALUES (:org, \'text\', :logo, NOW())
        ');
        $stSettings->execute([
            ':org' => $orgId,
            ':logo' => $logoText,
        ]);

        $roles = publisher_org_ensure_default_roles($dbh, $orgId);
        if ((int)($roles['manager'] ?? 0) <= 0) {
            throw new RuntimeException('Could not create publisher organization roles.');
        }

        publisher_org_ensure_manager_membership($dbh, $orgId, $managerId);

        $dbh->commit();
        publisher_org_registry_set_org_id($dbh, $name, $orgId);

        return $orgId;
    } catch (Throwable $e) {
        if ($dbh->inTransaction()) {
            $dbh->rollBack();
        }
        return 0;
    }
}

/** Ensure an organization company record exists for a publisher brand name. */
function publisher_org_ensure_for_publisher_name(PDO $dbh, string $name, string $category = 'news'): int
{
    $orgId = publisher_org_find_by_name($dbh, $name);
    if ($orgId > 0) {
        publisher_org_registry_set_org_id($dbh, $name, $orgId);
        return $orgId;
    }

    $orgId = publisher_org_registry_org_id($dbh, $name);
    if ($orgId > 0) {
        return $orgId;
    }

    return publisher_org_create($dbh, $name, $category);
}

/** After public_user publisher registration, link the org to the new publisher user. */
function publisher_org_link_publisher_user(PDO $dbh, string $name, int $publisherUserId, string $category = 'news'): int
{
    if ($publisherUserId <= 0) {
        return 0;
    }

    publisher_org_ensure_schema($dbh);

    try {
        $st = $dbh->prepare('
            SELECT username, email, password, name
            FROM users
            WHERE id = :id
            LIMIT 1
        ');
        $st->execute([':id' => $publisherUserId]);
        $user = $st->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
            return 0;
        }
    } catch (Throwable $e) {
        return 0;
    }

    $publisherName = publisher_registry_normalize_name($name);
    if ($publisherName === '') {
        $publisherName = publisher_registry_normalize_name((string)($user['name'] ?? ''));
    }

    return publisher_org_provision_publisher_account(
        $dbh,
        $publisherUserId,
        $publisherName,
        trim((string)($user['username'] ?? '')),
        trim((string)($user['email'] ?? '')),
        (string)($user['password'] ?? ''),
        $category
    );
}

function publisher_org_manager_access_sql(): string
{
    return '(owner_manager_id = :mid OR is_publisher_org = 1)';
}

function publisher_org_manager_can_access(PDO $dbh, int $orgId, int $managerId): bool
{
    if ($orgId <= 0 || $managerId <= 0) {
        return false;
    }

    foreach (publisher_org_list_for_manager($dbh, $managerId) as $org) {
        if ((int)($org['id'] ?? 0) === $orgId) {
            return true;
        }
    }

    return false;
}

function publisher_org_manager_publisher_user_id(PDO $dbh, int $managerId): int
{
    if ($managerId <= 0) {
        return 0;
    }

    publisher_org_ensure_schema($dbh);

    if (publisher_org_manager_has_publisher_link($dbh)) {
        try {
            $st = $dbh->prepare('SELECT publisher_user_id FROM managers WHERE id = :id LIMIT 1');
            $st->execute([':id' => $managerId]);
            $uid = (int)($st->fetchColumn() ?: 0);
            if ($uid > 0) {
                return $uid;
            }
        } catch (Throwable $e) {
            // fall through
        }
    }

    try {
        $st = $dbh->prepare('SELECT username, email FROM managers WHERE id = :id LIMIT 1');
        $st->execute([':id' => $managerId]);
        $manager = $st->fetch(PDO::FETCH_ASSOC);
        if (!$manager) {
            return 0;
        }

        $userCols = ['id'];
        if (publisher_db_column_exists($dbh, 'users', 'account_kind')) {
            $userCols[] = 'account_kind';
        }
        if (publisher_db_column_exists($dbh, 'users', 'publisher_category')) {
            $userCols[] = 'publisher_category';
        }

        $stUser = $dbh->prepare('
            SELECT ' . implode(', ', $userCols) . '
            FROM users
            WHERE username = :u OR email = :e
            LIMIT 1
        ');
        $stUser->execute([
            ':u' => trim((string)($manager['username'] ?? '')),
            ':e' => trim((string)($manager['email'] ?? '')),
        ]);
        $user = $stUser->fetch(PDO::FETCH_ASSOC);
        if ($user && publisher_org_is_publisher_user_row($dbh, $user)) {
            return (int)($user['id'] ?? 0);
        }
    } catch (Throwable $e) {
        return 0;
    }

    return 0;
}

/** True when this manager belongs to a publisher registered on public_user/register.php. */
function publisher_org_manager_is_registered_publisher(PDO $dbh, int $managerId): bool
{
    $publisherUserId = publisher_org_manager_publisher_user_id($dbh, $managerId);
    if ($publisherUserId <= 0) {
        return false;
    }

    return publisher_is_publisher_user($dbh, $publisherUserId)
        || publisher_org_list_for_public_user($dbh, $publisherUserId) !== [];
}

/** Primary organization for a publisher registered via public_user/register.php. */
function publisher_org_primary_org_for_registered_publisher(PDO $dbh, int $managerId): int
{
    $publisherUserId = publisher_org_manager_publisher_user_id($dbh, $managerId);
    if ($publisherUserId <= 0) {
        return 0;
    }

    $orgs = publisher_org_list_for_public_user($dbh, $publisherUserId);
    if (!$orgs) {
        publisher_org_repair_registered_publisher_org($dbh, $publisherUserId);
        $orgs = publisher_org_list_for_public_user($dbh, $publisherUserId);
    }
    if ($orgs) {
        return (int)($orgs[0]['id'] ?? 0);
    }

    foreach (publisher_org_list_for_manager($dbh, $managerId) as $org) {
        if ((int)($org['is_publisher_org'] ?? 0) === 1) {
            return (int)($org['id'] ?? 0);
        }
    }

    return 0;
}

function publisher_org_repair_registered_publisher_org(PDO $dbh, int $publisherUserId): void
{
    if ($publisherUserId <= 0) {
        return;
    }

    try {
        $userCols = ['id', 'username', 'email', 'password', 'name', 'status'];
        if (publisher_db_column_exists($dbh, 'users', 'publisher_category')) {
            $userCols[] = 'publisher_category';
        }
        if (publisher_db_column_exists($dbh, 'users', 'account_kind')) {
            $userCols[] = 'account_kind';
        }

        $st = $dbh->prepare('SELECT ' . implode(', ', $userCols) . ' FROM users WHERE id = :id LIMIT 1');
        $st->execute([':id' => $publisherUserId]);
        $user = $st->fetch(PDO::FETCH_ASSOC);
        if (!$user || !publisher_org_is_publisher_user_row($dbh, $user)) {
            return;
        }

        $category = strtolower(trim((string)($user['publisher_category'] ?? 'news')));
        if (!isset(publisher_categories()[$category])) {
            $category = 'news';
        }

        publisher_org_provision_publisher_account(
            $dbh,
            $publisherUserId,
            publisher_registry_normalize_name((string)($user['name'] ?? '')),
            trim((string)($user['username'] ?? '')),
            trim((string)($user['email'] ?? '')),
            (string)($user['password'] ?? ''),
            $category
        );
    } catch (Throwable $e) {
        // best-effort repair
    }
}

/** Set org session for a registered publisher manager; returns active org id. */
function publisher_org_apply_registered_publisher_login(PDO $dbh, int $managerId): int
{
    if (!publisher_org_manager_is_registered_publisher($dbh, $managerId)) {
        return 0;
    }

    $orgId = publisher_org_primary_org_for_registered_publisher($dbh, $managerId);
    if ($orgId > 0) {
        try {
            publisher_org_ensure_manager_membership($dbh, $orgId, $managerId);
        } catch (Throwable $e) {
            // feed.php membership heal will retry
        }
        $_SESSION['org_active_org_id'] = $orgId;
        unset($_SESSION['org_member_id'], $_SESSION['org_role_id']);
    }

    $publisherUserId = publisher_org_manager_publisher_user_id($dbh, $managerId);
    if ($publisherUserId > 0) {
        $_SESSION['org_publisher_user_id'] = $publisherUserId;
    } else {
        unset($_SESSION['org_publisher_user_id']);
    }

    return $orgId;
}

/** Organizations visible to a manager in organization/select_org.php. */
function publisher_org_list_for_manager(PDO $dbh, int $managerId): array
{
    if ($managerId <= 0) {
        return [];
    }

    publisher_org_ensure_schema($dbh);

    $publisherUserId = 0;
    if (publisher_org_manager_has_publisher_link($dbh)) {
        try {
            $stLink = $dbh->prepare('SELECT publisher_user_id FROM managers WHERE id = :mid LIMIT 1');
            $stLink->execute([':mid' => $managerId]);
            $publisherUserId = (int)($stLink->fetchColumn() ?: 0);
        } catch (Throwable $e) {
            $publisherUserId = 0;
        }
    }

    try {
        if ($publisherUserId > 0 && publisher_org_db_column_exists($dbh, 'organizations', 'publisher_user_id')) {
            $st = $dbh->prepare('
                SELECT id, name, org_code, is_publisher_org, publisher_user_id
                FROM organizations
                WHERE status = 1
                  AND (owner_manager_id = :mid OR publisher_user_id = :pub)
                ORDER BY name ASC, created_at DESC
            ');
            $st->execute([':mid' => $managerId, ':pub' => $publisherUserId]);
        } else {
            $st = $dbh->prepare('
                SELECT id, name, org_code,
                       COALESCE(is_publisher_org, 0) AS is_publisher_org,
                       COALESCE(publisher_user_id, 0) AS publisher_user_id
                FROM organizations
                WHERE status = 1
                  AND owner_manager_id = :mid
                ORDER BY name ASC, created_at DESC
            ');
            $st->execute([':mid' => $managerId]);
        }

        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function publisher_org_is_publisher_user_row(PDO $dbh, array $userRow): bool
{
    return publisher_user_row_looks_like_publisher($dbh, $userRow);
}

/**
 * Authenticate organization login using managers table OR public_user publisher accounts.
 *
 * @return array{account_type:string,account_id:int,org_ids:array<int,int>}|null
 */
function publisher_org_authenticate_login(PDO $dbh, string $login, string $plainPassword): ?array
{
    publisher_org_ensure_schema($dbh);

    $login = trim($login);
    if ($login === '' || $plainPassword === '') {
        return null;
    }

    try {
        $st = $dbh->prepare('
            SELECT id, password, status
            FROM managers
            WHERE username = :u OR email = :e
            LIMIT 1
        ');
        $st->execute([':u' => $login, ':e' => $login]);
        $manager = $st->fetch(PDO::FETCH_ASSOC);
        if ($manager && (int)($manager['status'] ?? 0) === 1 && password_verify($plainPassword, (string)($manager['password'] ?? ''))) {
            $managerId = (int)$manager['id'];
            return [
                'account_type' => 'manager',
                'account_id' => $managerId,
                'org_ids' => array_values(array_filter(array_map('intval', array_column(
                    publisher_org_list_for_manager($dbh, $managerId),
                    'id'
                )))),
            ];
        }
    } catch (Throwable $e) {
        // fall through to publisher-user lookup
    }

    try {
        $userCols = ['id', 'username', 'email', 'password', 'name', 'friend_code', 'status'];
        if (publisher_db_column_exists($dbh, 'users', 'account_kind')) {
            $userCols[] = 'account_kind';
        }
        if (publisher_db_column_exists($dbh, 'users', 'publisher_category')) {
            $userCols[] = 'publisher_category';
        }

        $st = $dbh->prepare('
            SELECT ' . implode(', ', $userCols) . '
            FROM users
            WHERE (username = :u OR email = :e)
            LIMIT 1
        ');
        $st->execute([':u' => $login, ':e' => $login]);
        $user = $st->fetch(PDO::FETCH_ASSOC);
        if (
            !$user
            || (int)($user['status'] ?? 0) !== 1
            || !password_verify($plainPassword, (string)($user['password'] ?? ''))
        ) {
            return null;
        }

        if (!publisher_org_is_publisher_user_row($dbh, $user)) {
            return null;
        }

        $publisherUserId = (int)($user['id'] ?? 0);
        publisher_repair_user_as_publisher(
            $dbh,
            $publisherUserId,
            trim((string)($user['publisher_category'] ?? ''))
        );
        $publisherName = publisher_registry_normalize_name((string)($user['name'] ?? ''));
        $category = strtolower(trim((string)($user['publisher_category'] ?? 'news')));
        if (!isset(publisher_categories()[$category])) {
            $category = 'news';
        }

        publisher_org_provision_publisher_account(
            $dbh,
            $publisherUserId,
            $publisherName,
            trim((string)($user['username'] ?? '')),
            trim((string)($user['email'] ?? '')),
            (string)($user['password'] ?? ''),
            $category
        );

        $managerId = publisher_org_manager_for_publisher($dbh, $publisherUserId);
        if ($managerId <= 0) {
            $managerId = publisher_org_ensure_manager_for_publisher(
                $dbh,
                $publisherUserId,
                trim((string)($user['username'] ?? '')),
                trim((string)($user['email'] ?? '')),
                (string)($user['password'] ?? ''),
                $publisherName
            );
        }
        if ($managerId <= 0) {
            return null;
        }

        return [
            'account_type' => 'manager',
            'account_id' => $managerId,
            'org_ids' => array_values(array_filter(array_map('intval', array_column(
                publisher_org_list_for_manager($dbh, $managerId),
                'id'
            )))),
        ];
    } catch (Throwable $e) {
        return null;
    }
}
