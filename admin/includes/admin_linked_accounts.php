<?php
declare(strict_types=1);

/**
 * Links each admin to their own public_user (personal + publisher) and organization manager.
 * Admin credentials sign into those linked accounts only — not other users' data.
 */

require_once __DIR__ . '/user_admin_helpers_load.php';

function admin_linked_ensure_schema(PDO $dbh): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $columns = [
        'linked_personal_user_id' => 'INT NULL DEFAULT NULL',
        'linked_publisher_user_id' => 'INT NULL DEFAULT NULL',
        'linked_manager_id' => 'INT NULL DEFAULT NULL',
    ];

    foreach ($columns as $col => $def) {
        try {
            if (!admin_linked_column_exists($dbh, $col)) {
                $dbh->exec("ALTER TABLE admin ADD COLUMN {$col} {$def}");
            }
        } catch (Throwable $e) {
            // ignore if already exists or lacks permission
        }
    }
}

function admin_linked_column_exists(PDO $dbh, string $column): bool
{
    try {
        $st = $dbh->prepare('
            SELECT 1 FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = \'admin\'
              AND COLUMN_NAME = :col
            LIMIT 1
        ');
        $st->execute([':col' => $column]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function admin_linked_password_matches(string $plain, string $dbHash): bool
{
    if ($dbHash === '') {
        return false;
    }
    if (password_get_info($dbHash)['algo'] !== 0) {
        return password_verify($plain, $dbHash);
    }
    if (hash('sha256', $plain) === $dbHash) {
        return true;
    }
    if (hash('sha384', $plain) === $dbHash) {
        return true;
    }
    return md5($plain) === $dbHash;
}

function admin_linked_fetch_admin(PDO $dbh, int $adminId): ?array
{
    admin_linked_ensure_schema($dbh);
    if ($adminId <= 0) {
        return null;
    }

    $cols = 'idadmin, fullname, username, email, password, status, role, image, friend_code';
    if (admin_linked_column_exists($dbh, 'linked_personal_user_id')) {
        $cols .= ', linked_personal_user_id, linked_publisher_user_id, linked_manager_id';
    }

    $st = $dbh->prepare("SELECT {$cols} FROM admin WHERE idadmin = :id LIMIT 1");
    $st->execute([':id' => $adminId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/** @return array<string,mixed>|null */
function admin_linked_verify_credentials(PDO $dbh, string $login, string $password): ?array
{
    admin_linked_ensure_schema($dbh);

    $login = trim($login);
    if ($login === '' || $password === '') {
        return null;
    }

    $st = $dbh->prepare('
        SELECT idadmin, fullname, username, email, password, status, role, image, friend_code
        FROM admin
        WHERE username = :u OR email = :e
        LIMIT 1
    ');
    $st->execute([':u' => $login, ':e' => $login]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row || (int)($row['status'] ?? 0) !== 1) {
        return null;
    }
    if (!admin_linked_password_matches($password, (string)($row['password'] ?? ''))) {
        return null;
    }

    return admin_linked_fetch_admin($dbh, (int)$row['idadmin']) ?: $row;
}

function admin_linked_username_taken(PDO $dbh, string $username): bool
{
    $username = trim($username);
    if ($username === '') {
        return false;
    }
    $st = $dbh->prepare('SELECT 1 FROM admin WHERE username = :u LIMIT 1');
    $st->execute([':u' => $username]);
    if ($st->fetchColumn()) {
        return true;
    }
    return user_admin_username_taken($dbh, $username);
}

function admin_linked_email_taken(PDO $dbh, string $email): bool
{
    $email = trim($email);
    if ($email === '') {
        return false;
    }
    $st = $dbh->prepare('SELECT 1 FROM admin WHERE email = :e LIMIT 1');
    $st->execute([':e' => $email]);
    if ($st->fetchColumn()) {
        return true;
    }
    return user_admin_email_taken($dbh, $email);
}

function admin_linked_derived_username(PDO $dbh, string $base, string $suffix): string
{
    $base = preg_replace('/[^a-zA-Z0-9_]/', '_', trim($base)) ?: 'admin';
    $candidate = mb_substr($base . $suffix, 0, 50);
    if (!user_admin_username_taken($dbh, $candidate) && !admin_linked_username_taken($dbh, $candidate)) {
        return $candidate;
    }
    for ($i = 2; $i <= 99; $i++) {
        $candidate = mb_substr($base . $suffix . (string)$i, 0, 50);
        if (!user_admin_username_taken($dbh, $candidate) && !admin_linked_username_taken($dbh, $candidate)) {
            return $candidate;
        }
    }
    return mb_substr($base . $suffix . bin2hex(random_bytes(2)), 0, 50);
}

function admin_linked_derived_email(PDO $dbh, string $adminEmail, int $adminId, string $tag): string
{
    $adminEmail = trim($adminEmail);
    if ($adminEmail === '' || strpos($adminEmail, '@') === false) {
        return 'admin' . $adminId . '.' . $tag . '@linked.talentra.local';
    }

    [$local, $domain] = explode('@', $adminEmail, 2);
    $candidate = mb_substr($local . '+' . $tag . '.' . $adminId . '@' . $domain, 0, 100);
    if (!user_admin_email_taken($dbh, $candidate) && !admin_linked_email_taken($dbh, $candidate)) {
        return $candidate;
    }

    return mb_substr('linked.' . $adminId . '.' . $tag . '@' . $domain, 0, 100);
}

function admin_linked_insert_user(
    PDO $dbh,
    array $admin,
    string $accountKind,
    string $passwordHash,
    string $publisherName = ''
): int {
    $adminId = (int)($admin['idadmin'] ?? 0);
    $suffix = $accountKind === 'publisher' ? '_pub' : '_personal';
    $tag = $accountKind === 'publisher' ? 'pub' : 'personal';

    $username = admin_linked_derived_username($dbh, (string)($admin['username'] ?? 'admin'), $suffix);
    $email = admin_linked_derived_email($dbh, (string)($admin['email'] ?? ''), $adminId, $tag);
    $name = $accountKind === 'publisher'
        ? ($publisherName !== '' ? $publisherName : trim((string)($admin['fullname'] ?? '')) . ' Publisher')
        : trim((string)($admin['fullname'] ?? ''));

    $friendCode = user_admin_make_friend_code($dbh, $accountKind);
    $gender = (string)($admin['gender'] ?? 'N/A');
    $mobile = (string)($admin['mobile'] ?? 'N/A');

    $st = $dbh->prepare('
        INSERT INTO users
            (name, username, friend_code, email, password, gender, mobile, designation, role,
             account_kind, publisher_category, publisher_tagline, image, status, birthday, created_at)
        VALUES
            (:name, :username, :friend_code, :email, :password, :gender, :mobile, :designation, :role,
             :account_kind, :publisher_category, :publisher_tagline, :image, 1, NULL, NOW())
    ');
    $st->execute([
        ':name' => mb_substr($name, 0, 100),
        ':username' => $username,
        ':friend_code' => $friendCode,
        ':email' => $email,
        ':password' => $passwordHash,
        ':gender' => $gender !== '' ? $gender : 'N/A',
        ':mobile' => $mobile !== '' ? $mobile : 'N/A',
        ':designation' => 'Admin linked',
        ':role' => 4,
        ':account_kind' => $accountKind,
        ':publisher_category' => $accountKind === 'publisher' ? 'news' : '',
        ':publisher_tagline' => $accountKind === 'publisher' ? 'Admin publisher workspace' : '',
        ':image' => (string)($admin['image'] ?? 'default.jpg'),
    ]);

    return (int)$dbh->lastInsertId();
}

function admin_linked_save_links(PDO $dbh, int $adminId, int $personalId, int $publisherId, int $managerId): void
{
    admin_linked_ensure_schema($dbh);
    $st = $dbh->prepare('
        UPDATE admin SET
            linked_personal_user_id = :pu,
            linked_publisher_user_id = :pub,
            linked_manager_id = :mid
        WHERE idadmin = :id
        LIMIT 1
    ');
    $st->execute([
        ':pu' => $personalId > 0 ? $personalId : null,
        ':pub' => $publisherId > 0 ? $publisherId : null,
        ':mid' => $managerId > 0 ? $managerId : null,
        ':id' => $adminId,
    ]);
}

function admin_linked_sync_password(PDO $dbh, int $adminId, string $passwordHash): void
{
    $admin = admin_linked_fetch_admin($dbh, $adminId);
    if (!$admin) {
        return;
    }

    $userIds = array_filter([
        (int)($admin['linked_personal_user_id'] ?? 0),
        (int)($admin['linked_publisher_user_id'] ?? 0),
    ]);
    foreach ($userIds as $uid) {
        $st = $dbh->prepare('UPDATE users SET password = :pw WHERE id = :id LIMIT 1');
        $st->execute([':pw' => $passwordHash, ':id' => $uid]);
    }

    $managerId = (int)($admin['linked_manager_id'] ?? 0);
    if ($managerId > 0) {
        $st = $dbh->prepare('UPDATE managers SET password = :pw WHERE id = :id LIMIT 1');
        $st->execute([':pw' => $passwordHash, ':id' => $managerId]);
    }
}

/**
 * Create or repair linked personal user, publisher user, and organization manager.
 *
 * @return array{ok:bool,personal_user_id?:int,publisher_user_id?:int,manager_id?:int,error?:string}
 */
function admin_linked_provision(PDO $dbh, int $adminId, string $plainPassword): array
{
    admin_linked_ensure_schema($dbh);

    $admin = admin_linked_fetch_admin($dbh, $adminId);
    if (!$admin) {
        return ['ok' => false, 'error' => 'Admin not found.'];
    }

    $hash = password_hash($plainPassword, PASSWORD_DEFAULT);
    if ($plainPassword === '') {
        $hash = (string)($admin['password'] ?? '');
    }

    $personalId = (int)($admin['linked_personal_user_id'] ?? 0);
    $publisherId = (int)($admin['linked_publisher_user_id'] ?? 0);
    $managerId = (int)($admin['linked_manager_id'] ?? 0);

    try {
        if ($personalId <= 0 || !user_admin_get_user_full($dbh, $personalId)) {
            $personalId = admin_linked_insert_user($dbh, $admin, 'personal', $hash);
        }

        $publisherName = trim((string)($admin['fullname'] ?? '')) . ' Admin Publisher';
        if ($publisherId <= 0 || !user_admin_get_user_full($dbh, $publisherId)) {
            $publisherId = admin_linked_insert_user($dbh, $admin, 'publisher', $hash, $publisherName);
        }

        require_once dirname(__DIR__, 2) . '/public_user/includes/publisher_organization_bridge.php';
        require_once dirname(__DIR__, 2) . '/public_user/includes/publisher_accounts_load.php';

        $pubUser = user_admin_get_user_full($dbh, $publisherId);
        if ($pubUser) {
            publisher_repair_user_as_publisher($dbh, $publisherId, 'news');
            publisher_org_provision_publisher_account(
                $dbh,
                $publisherId,
                $publisherName,
                (string)($pubUser['username'] ?? ''),
                (string)($pubUser['email'] ?? ''),
                $hash,
                'news'
            );

            $st = $dbh->prepare('
                SELECT id FROM managers
                WHERE username = :u OR email = :e
                ORDER BY id DESC
                LIMIT 1
            ');
            $st->execute([
                ':u' => (string)($pubUser['username'] ?? ''),
                ':e' => (string)($pubUser['email'] ?? ''),
            ]);
            $foundManager = (int)($st->fetchColumn() ?: 0);
            if ($foundManager > 0) {
                $managerId = $foundManager;
                try {
                    $stLink = $dbh->prepare('UPDATE managers SET publisher_user_id = :uid WHERE id = :id LIMIT 1');
                    $stLink->execute([':uid' => $publisherId, ':id' => $managerId]);
                } catch (Throwable $e) {
                    // column may be absent on older schemas
                }
            }
        }

        if ($managerId <= 0) {
            $st = $dbh->prepare('SELECT id FROM managers WHERE publisher_user_id = :uid LIMIT 1');
            $st->execute([':uid' => $publisherId]);
            $managerId = (int)($st->fetchColumn() ?: 0);
        }

        admin_linked_save_links($dbh, $adminId, $personalId, $publisherId, $managerId);
        if ($plainPassword !== '') {
            admin_linked_sync_password($dbh, $adminId, $hash);
        }

        return [
            'ok' => true,
            'personal_user_id' => $personalId,
            'publisher_user_id' => $publisherId,
            'manager_id' => $managerId,
        ];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

function admin_linked_ensure_provisioned(PDO $dbh, int $adminId, string $plainPassword = ''): void
{
    $admin = admin_linked_fetch_admin($dbh, $adminId);
    if (!$admin) {
        return;
    }

    $needs = (
        (int)($admin['linked_personal_user_id'] ?? 0) <= 0
        || (int)($admin['linked_publisher_user_id'] ?? 0) <= 0
        || (int)($admin['linked_manager_id'] ?? 0) <= 0
    );

    if ($needs) {
        admin_linked_provision($dbh, $adminId, $plainPassword);
    }
}

/** @return array<string,mixed>|null */
function admin_linked_portal_user(PDO $dbh, int $adminId, string $accountKind): ?array
{
    $admin = admin_linked_fetch_admin($dbh, $adminId);
    if (!$admin) {
        return null;
    }

    $accountKind = strtolower(trim($accountKind)) === 'publisher' ? 'publisher' : 'personal';
    $userId = $accountKind === 'publisher'
        ? (int)($admin['linked_publisher_user_id'] ?? 0)
        : (int)($admin['linked_personal_user_id'] ?? 0);

    if ($userId <= 0) {
        return null;
    }

    $user = user_admin_get_user_full($dbh, $userId);
    if (!$user || (int)($user['status'] ?? 0) !== 1) {
        return null;
    }

    $portalStaffRole = '';
    $roleHelpers = dirname(__DIR__, 2) . '/public_user/includes/account_display_helpers.php';
    if (is_file($roleHelpers)) {
        require_once $roleHelpers;
        if (function_exists('account_admin_role_label')) {
            $portalStaffRole = account_admin_role_label($dbh, (int)($admin['role'] ?? 0));
        }
    }

    return [
        'id' => (int)$user['id'],
        'name' => (string)($user['name'] ?? ''),
        'username' => (string)($user['username'] ?? ''),
        'email' => (string)($user['email'] ?? ''),
        'image' => (string)($user['image'] ?? 'default.jpg'),
        'role' => (int)($user['role'] ?? 4),
        'status' => (int)($user['status'] ?? 1),
        'friend_code' => (string)($user['friend_code'] ?? ''),
        'account_kind' => (string)($user['account_kind'] ?? $accountKind),
        'publisher_category' => (string)($user['publisher_category'] ?? ''),
        'portal_staff_role_label' => $portalStaffRole,
    ];
}

function admin_linked_manager_id(PDO $dbh, int $adminId): int
{
    $admin = admin_linked_fetch_admin($dbh, $adminId);
    if (!$admin) {
        return 0;
    }
    return (int)($admin['linked_manager_id'] ?? 0);
}
