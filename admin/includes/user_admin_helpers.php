<?php
declare(strict_types=1);

require_once __DIR__ . '/org_admin_helpers_load.php';

function user_admin_db(?PDO $dbh = null): PDO
{
    return org_admin_db($dbh);
}

function user_admin_require(): void
{
    org_admin_require_admin();
}

function user_admin_get_user_full(PDO $dbh, int $userId): ?array
{
    if ($userId <= 0) {
        return null;
    }
    $st = $dbh->prepare('
        SELECT id, name, username, email, friend_code, gender, mobile, age, designation, role,
               account_kind, publisher_category, publisher_tagline, image, status, birthday,
               created_at, last_seen
        FROM users
        WHERE id = :id
        LIMIT 1
    ');
    $st->execute([':id' => $userId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function user_admin_roles(PDO $dbh): array
{
    try {
        return $dbh->query('SELECT idrole, name FROM role WHERE status = 1 ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function user_admin_genders(): array
{
    return ['Man', 'Female', 'Other', 'Prefer not to say'];
}

function user_admin_publisher_categories(): array
{
    $path = dirname(__DIR__, 2) . '/public_user/includes/publisher_accounts_load.php';
    if (is_file($path)) {
        require_once $path;
        if (function_exists('publisher_categories')) {
            return publisher_categories();
        }
    }
    return [
        'news' => 'News',
        'sports' => 'Sports',
        'business' => 'Business',
    ];
}

function user_admin_make_friend_code(PDO $dbh, string $accountKind): string
{
    $prefix = strtolower(trim($accountKind)) === 'publisher' ? 'PUB' : 'USR';
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    for ($try = 0; $try < 60; $try++) {
        $part = static function () use ($chars): string {
            $s = '';
            for ($i = 0; $i < 4; $i++) {
                $s .= $chars[random_int(0, strlen($chars) - 1)];
            }
            return $s;
        };
        $code = $prefix . '-' . $part() . '-' . $part();
        $st = $dbh->prepare('SELECT 1 FROM users WHERE friend_code = :c LIMIT 1');
        $st->execute([':c' => $code]);
        if (!$st->fetchColumn()) {
            return $code;
        }
    }
    throw new RuntimeException('Unable to generate a unique friend code.');
}

function user_admin_email_taken(PDO $dbh, string $email, int $exceptId = 0): bool
{
    $email = trim($email);
    if ($email === '') {
        return false;
    }
    $sql = 'SELECT 1 FROM users WHERE email = :email';
    $params = [':email' => $email];
    if ($exceptId > 0) {
        $sql .= ' AND id <> :id';
        $params[':id'] = $exceptId;
    }
    $sql .= ' LIMIT 1';
    $st = $dbh->prepare($sql);
    $st->execute($params);
    return (bool)$st->fetchColumn();
}

function user_admin_username_taken(PDO $dbh, string $username, int $exceptId = 0): bool
{
    $username = trim($username);
    if ($username === '') {
        return false;
    }
    $sql = 'SELECT 1 FROM users WHERE username = :username';
    $params = [':username' => $username];
    if ($exceptId > 0) {
        $sql .= ' AND id <> :id';
        $params[':id'] = $exceptId;
    }
    $sql .= ' LIMIT 1';
    $st = $dbh->prepare($sql);
    $st->execute($params);
    return (bool)$st->fetchColumn();
}

function user_admin_normalize_input(array $in): array
{
    $accountKind = strtolower(trim((string)($in['account_kind'] ?? 'personal')));
    if (!in_array($accountKind, ['personal', 'publisher'], true)) {
        $accountKind = 'personal';
    }

    return [
        'name' => mb_substr(trim((string)($in['name'] ?? '')), 0, 100),
        'username' => mb_substr(trim((string)($in['username'] ?? '')), 0, 50),
        'email' => mb_substr(trim((string)($in['email'] ?? '')), 0, 100),
        'password' => (string)($in['password'] ?? ''),
        'gender' => mb_substr(trim((string)($in['gender'] ?? '')), 0, 50),
        'mobile' => mb_substr(trim((string)($in['mobile'] ?? '')), 0, 50),
        'designation' => mb_substr(trim((string)($in['designation'] ?? '')), 0, 255),
        'role' => max(1, (int)($in['role'] ?? 4)),
        'account_kind' => $accountKind,
        'publisher_category' => mb_substr(trim((string)($in['publisher_category'] ?? '')), 0, 40),
        'publisher_tagline' => mb_substr(trim((string)($in['publisher_tagline'] ?? '')), 0, 255),
        'status' => (int)($in['status'] ?? 1) === 1 ? 1 : 0,
        'birthday' => trim((string)($in['birthday'] ?? '')),
    ];
}

function user_admin_validate(array $data, bool $isEdit): array
{
    $errors = [];
    $cats = user_admin_publisher_categories();

    if ($data['name'] === '') {
        $errors[] = 'Full name is required.';
    }
    if ($data['username'] === '') {
        $errors[] = 'Username is required.';
    }
    if ($data['email'] === '' || !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'A valid email is required.';
    }
    if (!$isEdit && $data['password'] === '') {
        $errors[] = 'Password is required for new users.';
    }
    if ($data['password'] !== '' && strlen($data['password']) < 6) {
        $errors[] = 'Password must be at least 6 characters.';
    }
    if ($data['account_kind'] === 'personal') {
        if ($data['gender'] === '') {
            $errors[] = 'Gender is required for personal accounts.';
        }
        if ($data['mobile'] === '') {
            $errors[] = 'Phone is required for personal accounts.';
        }
    }
    if ($data['account_kind'] === 'publisher') {
        if ($data['publisher_category'] === '' || !isset($cats[$data['publisher_category']])) {
            $errors[] = 'Choose a valid publisher category.';
        }
    }

    if ($data['birthday'] !== '') {
        $ts = strtotime($data['birthday']);
        if (!$ts) {
            $errors[] = 'Birthday must be a valid date (YYYY-MM-DD).';
        } else {
            $data['birthday'] = date('Y-m-d', $ts);
        }
    } else {
        $data['birthday'] = null;
    }

    return [$errors, $data];
}

function user_admin_create(PDO $dbh, array $data): array
{
    [$errors, $data] = user_admin_validate($data, false);
    if ($errors) {
        return ['ok' => false, 'error' => implode(' ', $errors)];
    }
    if (user_admin_email_taken($dbh, $data['email'])) {
        return ['ok' => false, 'error' => 'That email is already registered.'];
    }
    if (user_admin_username_taken($dbh, $data['username'])) {
        return ['ok' => false, 'error' => 'That username is already taken.'];
    }

    try {
        $friendCode = user_admin_make_friend_code($dbh, $data['account_kind']);
        $hash = password_hash($data['password'], PASSWORD_DEFAULT);
        $gender = $data['account_kind'] === 'publisher' && $data['gender'] === '' ? 'N/A' : $data['gender'];
        $mobile = $data['account_kind'] === 'publisher' && $data['mobile'] === '' ? '' : $data['mobile'];

        $st = $dbh->prepare('
            INSERT INTO users
                (name, username, friend_code, email, password, gender, mobile, designation, role,
                 account_kind, publisher_category, publisher_tagline, image, status, birthday, created_at)
            VALUES
                (:name, :username, :friend_code, :email, :password, :gender, :mobile, :designation, :role,
                 :account_kind, :publisher_category, :publisher_tagline, :image, :status, :birthday, NOW())
        ');
        $st->execute([
            ':name' => $data['name'],
            ':username' => $data['username'],
            ':friend_code' => $friendCode,
            ':email' => $data['email'],
            ':password' => $hash,
            ':gender' => $gender,
            ':mobile' => $mobile,
            ':designation' => $data['designation'],
            ':role' => $data['role'],
            ':account_kind' => $data['account_kind'],
            ':publisher_category' => $data['account_kind'] === 'publisher' ? $data['publisher_category'] : '',
            ':publisher_tagline' => $data['account_kind'] === 'publisher' ? $data['publisher_tagline'] : '',
            ':image' => 'default.jpg',
            ':status' => $data['status'],
            ':birthday' => $data['birthday'],
        ]);

        return [
            'ok' => true,
            'id' => (int)$dbh->lastInsertId(),
            'friend_code' => $friendCode,
        ];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Database error: ' . $e->getMessage()];
    }
}

function user_admin_update(PDO $dbh, int $userId, array $data): array
{
    if ($userId <= 0) {
        return ['ok' => false, 'error' => 'Invalid user id.'];
    }
    if (!user_admin_get_user_full($dbh, $userId)) {
        return ['ok' => false, 'error' => 'User not found.'];
    }

    [$errors, $data] = user_admin_validate($data, true);
    if ($errors) {
        return ['ok' => false, 'error' => implode(' ', $errors)];
    }
    if (user_admin_email_taken($dbh, $data['email'], $userId)) {
        return ['ok' => false, 'error' => 'That email is already registered.'];
    }
    if (user_admin_username_taken($dbh, $data['username'], $userId)) {
        return ['ok' => false, 'error' => 'That username is already taken.'];
    }

    try {
        $gender = $data['account_kind'] === 'publisher' && $data['gender'] === '' ? 'N/A' : $data['gender'];
        $mobile = $data['account_kind'] === 'publisher' && $data['mobile'] === '' ? '' : $data['mobile'];

        $sql = '
            UPDATE users SET
                name = :name,
                username = :username,
                email = :email,
                gender = :gender,
                mobile = :mobile,
                designation = :designation,
                role = :role,
                account_kind = :account_kind,
                publisher_category = :publisher_category,
                publisher_tagline = :publisher_tagline,
                status = :status,
                birthday = :birthday
        ';
        $params = [
            ':name' => $data['name'],
            ':username' => $data['username'],
            ':email' => $data['email'],
            ':gender' => $gender,
            ':mobile' => $mobile,
            ':designation' => $data['designation'],
            ':role' => $data['role'],
            ':account_kind' => $data['account_kind'],
            ':publisher_category' => $data['account_kind'] === 'publisher' ? $data['publisher_category'] : '',
            ':publisher_tagline' => $data['account_kind'] === 'publisher' ? $data['publisher_tagline'] : '',
            ':status' => $data['status'],
            ':birthday' => $data['birthday'],
            ':id' => $userId,
        ];

        if ($data['password'] !== '') {
            $sql .= ', password = :password';
            $params[':password'] = password_hash($data['password'], PASSWORD_DEFAULT);
        }

        $sql .= ' WHERE id = :id LIMIT 1';
        $st = $dbh->prepare($sql);
        $st->execute($params);

        return ['ok' => true, 'id' => $userId];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Database error: ' . $e->getMessage()];
    }
}

function user_admin_delete_one(PDO $dbh, int $userId, string $email = ''): array
{
    if ($userId <= 0) {
        return ['ok' => false, 'error' => 'Invalid user id.'];
    }
    try {
        $registryPath = dirname(__DIR__, 2) . '/public_user/includes/deleted_user_registry.php';
        if (is_file($registryPath)) {
            require_once $registryPath;
            user_deleteduser_ensure_schema($dbh);
        }

        $fetch = $dbh->prepare('
            SELECT email, username, friend_code, name, mobile,
                   COALESCE(account_kind, \'personal\') AS account_kind
            FROM users
            WHERE id = :id
            LIMIT 1
        ');
        $fetch->execute([':id' => $userId]);
        $row = $fetch->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return ['ok' => false, 'error' => 'User not found.'];
        }

        $email = trim($email !== '' ? $email : (string)($row['email'] ?? ''));
        $username = trim((string)($row['username'] ?? ''));
        $friendCode = trim((string)($row['friend_code'] ?? ''));
        $displayName = trim((string)($row['name'] ?? ''));
        $mobile = trim((string)($row['mobile'] ?? ''));
        $accountKind = strtolower(trim((string)($row['account_kind'] ?? 'personal')));

        if (user_deleteduser_table_exists($dbh) && function_exists('user_record_deleted_account')) {
            user_record_deleted_account($dbh, $email, $username, $friendCode, $displayName, $mobile, $accountKind);
        } elseif ($email !== '' && user_deleteduser_table_exists($dbh)) {
            $ins = $dbh->prepare('INSERT INTO deleteduser (email) VALUES (:email)');
            $ins->execute([':email' => $email]);
        }

        $dbh->beginTransaction();

        if (org_admin_table_exists($dbh, 'user_sessions')) {
            $revoke = $dbh->prepare('
                UPDATE user_sessions
                SET revoked_at = NOW(), last_seen_at = NOW()
                WHERE user_id = :uid AND revoked_at IS NULL
            ');
            $revoke->execute([':uid' => $userId]);
        }

        $del = $dbh->prepare('DELETE FROM users WHERE id = :id LIMIT 1');
        $del->execute([':id' => $userId]);
        if ($del->rowCount() <= 0) {
            $dbh->rollBack();
            return ['ok' => false, 'error' => 'User not found.'];
        }

        $dbh->commit();
        return ['ok' => true];
    } catch (Throwable $e) {
        if ($dbh->inTransaction()) {
            $dbh->rollBack();
        }
        return ['ok' => false, 'error' => 'Database error: ' . $e->getMessage()];
    }
}

function user_admin_set_user_status(PDO $dbh, int $userId, int $status): array
{
    if ($userId <= 0) {
        return ['ok' => false, 'error' => 'Invalid user id.'];
    }
    $status = $status === 1 ? 1 : 0;
    try {
        $st = $dbh->prepare('UPDATE users SET status = :st WHERE id = :id LIMIT 1');
        $st->execute([':st' => $status, ':id' => $userId]);
        if ($st->rowCount() <= 0) {
            $chk = $dbh->prepare('SELECT status FROM users WHERE id = :id LIMIT 1');
            $chk->execute([':id' => $userId]);
            $current = $chk->fetchColumn();
            if ($current === false) {
                return ['ok' => false, 'error' => 'User not found.'];
            }
        }
        if ($status === 0 && org_admin_table_exists($dbh, 'user_sessions')) {
            $revoke = $dbh->prepare('
                UPDATE user_sessions
                SET revoked_at = NOW(), last_seen_at = NOW()
                WHERE user_id = :uid AND revoked_at IS NULL
            ');
            $revoke->execute([':uid' => $userId]);
        }
        return ['ok' => true, 'status' => $status];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Database error: ' . $e->getMessage()];
    }
}

function user_admin_account_kind_label(string $kind): string
{
    return strtolower(trim($kind)) === 'publisher' ? 'Publisher' : 'Personal';
}

function user_admin_public_profile_href(int $userId): string
{
    return 'open_public_profile.php?user_id=' . max(0, $userId);
}
