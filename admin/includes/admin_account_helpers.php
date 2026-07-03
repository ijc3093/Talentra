<?php
declare(strict_types=1);

require_once __DIR__ . '/org_admin_helpers_load.php';

function admin_account_db(?PDO $dbh = null): PDO
{
    return org_admin_db($dbh);
}

function admin_account_require(): void
{
    org_admin_require_admin();
}

function admin_account_roles(PDO $dbh): array
{
    try {
        return $dbh->query('SELECT idrole, name FROM role WHERE status = 1 ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function admin_account_get_full(PDO $dbh, int $adminId): ?array
{
    if ($adminId <= 0) {
        return null;
    }
    $st = $dbh->prepare('
        SELECT idadmin, fullname, username, email, friend_code, gender, mobile, designation,
               role, image, status, created_at, last_login_at
        FROM admin
        WHERE idadmin = :id
        LIMIT 1
    ');
    $st->execute([':id' => $adminId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function admin_account_make_friend_code(PDO $dbh): string
{
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    for ($try = 0; $try < 80; $try++) {
        $part = static function () use ($chars): string {
            $s = '';
            for ($i = 0; $i < 4; $i++) {
                $s .= $chars[random_int(0, strlen($chars) - 1)];
            }
            return $s;
        };
        $code = 'ADM-' . $part() . '-' . $part();
        $st = $dbh->prepare('SELECT 1 FROM admin WHERE friend_code = :c LIMIT 1');
        $st->execute([':c' => $code]);
        if (!$st->fetchColumn()) {
            return $code;
        }
    }
    throw new RuntimeException('Unable to generate a unique friend code.');
}

function admin_account_email_taken(PDO $dbh, string $email, int $exceptId = 0): bool
{
    $email = trim($email);
    if ($email === '') {
        return false;
    }
    $sql = 'SELECT 1 FROM admin WHERE email = :email';
    $params = [':email' => $email];
    if ($exceptId > 0) {
        $sql .= ' AND idadmin <> :id';
        $params[':id'] = $exceptId;
    }
    $sql .= ' LIMIT 1';
    $st = $dbh->prepare($sql);
    $st->execute($params);
    return (bool)$st->fetchColumn();
}

function admin_account_username_taken(PDO $dbh, string $username, int $exceptId = 0): bool
{
    $username = trim($username);
    if ($username === '') {
        return false;
    }
    $sql = 'SELECT 1 FROM admin WHERE username = :username';
    $params = [':username' => $username];
    if ($exceptId > 0) {
        $sql .= ' AND idadmin <> :id';
        $params[':id'] = $exceptId;
    }
    $sql .= ' LIMIT 1';
    $st = $dbh->prepare($sql);
    $st->execute($params);
    return (bool)$st->fetchColumn();
}

function admin_account_normalize_input(array $in): array
{
    return [
        'fullname' => mb_substr(trim((string)($in['fullname'] ?? '')), 0, 20),
        'username' => mb_substr(trim((string)($in['username'] ?? '')), 0, 100),
        'email' => mb_substr(trim((string)($in['email'] ?? '')), 0, 100),
        'password' => (string)($in['password'] ?? ''),
        'gender' => mb_substr(trim((string)($in['gender'] ?? 'N/A')), 0, 50) ?: 'N/A',
        'mobile' => mb_substr(trim((string)($in['mobile'] ?? 'N/A')), 0, 50) ?: 'N/A',
        'designation' => mb_substr(trim((string)($in['designation'] ?? 'Internal')), 0, 50) ?: 'Internal',
        'role' => max(1, (int)($in['role'] ?? 1)),
        'status' => (int)($in['status'] ?? 1) === 1 ? 1 : 0,
    ];
}

function admin_account_validate(array $data, bool $isEdit): array
{
    $errors = [];

    if ($data['fullname'] === '') {
        $errors[] = 'Full name is required.';
    }
    if ($data['username'] === '') {
        $errors[] = 'Username is required.';
    }
    if ($data['email'] === '' || !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'A valid email is required.';
    }
    if (!$isEdit && $data['password'] === '') {
        $errors[] = 'Password is required for new admin accounts.';
    }
    if ($data['password'] !== '' && strlen($data['password']) < 6) {
        $errors[] = 'Password must be at least 6 characters.';
    }
    if ($data['role'] <= 0) {
        $errors[] = 'Choose a valid role.';
    }

    return [$errors, $data];
}

function admin_account_create(PDO $dbh, array $data): array
{
    [$errors, $data] = admin_account_validate($data, false);
    if ($errors) {
        return ['ok' => false, 'error' => implode(' ', $errors)];
    }
    if (admin_account_email_taken($dbh, $data['email'])) {
        return ['ok' => false, 'error' => 'Email is already in use.'];
    }
    if (admin_account_username_taken($dbh, $data['username'])) {
        return ['ok' => false, 'error' => 'Username is already in use.'];
    }

    try {
        $friendCode = admin_account_make_friend_code($dbh);
        $hash = password_hash($data['password'], PASSWORD_DEFAULT);

        $st = $dbh->prepare('
            INSERT INTO admin
                (fullname, username, friend_code, email, password, gender, mobile, designation,
                 role, image, status, force_password_change, failed_login_attempts, locked_until, last_login_at)
            VALUES
                (:fullname, :username, :friend_code, :email, :password, :gender, :mobile, :designation,
                 :role, :image, :status, 0, 0, NULL, NULL)
        ');
        $st->execute([
            ':fullname' => $data['fullname'],
            ':username' => $data['username'],
            ':friend_code' => $friendCode,
            ':email' => $data['email'],
            ':password' => $hash,
            ':gender' => $data['gender'],
            ':mobile' => $data['mobile'],
            ':designation' => $data['designation'],
            ':role' => $data['role'],
            ':image' => 'default.jpg',
            ':status' => $data['status'],
        ]);

        $adminId = (int)$dbh->lastInsertId();
        require_once __DIR__ . '/admin_linked_accounts_load.php';
        admin_linked_provision($dbh, $adminId, $data['password']);

        return ['ok' => true, 'id' => $adminId, 'friend_code' => $friendCode];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Database error: ' . $e->getMessage()];
    }
}

function admin_account_update(PDO $dbh, int $adminId, array $data): array
{
    if ($adminId <= 0) {
        return ['ok' => false, 'error' => 'Invalid admin id.'];
    }
    if (!admin_account_get_full($dbh, $adminId)) {
        return ['ok' => false, 'error' => 'Admin account not found.'];
    }

    [$errors, $data] = admin_account_validate($data, true);
    if ($errors) {
        return ['ok' => false, 'error' => implode(' ', $errors)];
    }
    if (admin_account_email_taken($dbh, $data['email'], $adminId)) {
        return ['ok' => false, 'error' => 'Email is already in use.'];
    }
    if (admin_account_username_taken($dbh, $data['username'], $adminId)) {
        return ['ok' => false, 'error' => 'Username is already in use.'];
    }

    try {
        $sql = '
            UPDATE admin SET
                fullname = :fullname,
                username = :username,
                email = :email,
                gender = :gender,
                mobile = :mobile,
                designation = :designation,
                role = :role,
                status = :status
        ';
        $params = [
            ':fullname' => $data['fullname'],
            ':username' => $data['username'],
            ':email' => $data['email'],
            ':gender' => $data['gender'],
            ':mobile' => $data['mobile'],
            ':designation' => $data['designation'],
            ':role' => $data['role'],
            ':status' => $data['status'],
            ':id' => $adminId,
        ];

        if ($data['password'] !== '') {
            $sql .= ', password = :password, force_password_change = 0';
            $params[':password'] = password_hash($data['password'], PASSWORD_DEFAULT);
        }

        $sql .= ' WHERE idadmin = :id LIMIT 1';
        $st = $dbh->prepare($sql);
        $st->execute($params);

        if ($data['password'] !== '') {
            require_once __DIR__ . '/admin_linked_accounts_load.php';
            admin_linked_sync_password($dbh, $adminId, $params[':password']);
        } else {
            require_once __DIR__ . '/admin_linked_accounts_load.php';
            admin_linked_ensure_provisioned($dbh, $adminId);
        }

        return ['ok' => true];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Database error: ' . $e->getMessage()];
    }
}

function admin_account_delete_one(PDO $dbh, int $adminId, int $currentAdminId = 0): array
{
    if ($adminId <= 0) {
        return ['ok' => false, 'error' => 'Invalid admin id.'];
    }
    if ($currentAdminId > 0 && $adminId === $currentAdminId) {
        return ['ok' => false, 'error' => 'You cannot delete your own account while signed in.'];
    }

    try {
        $st = $dbh->prepare('DELETE FROM admin WHERE idadmin = :id LIMIT 1');
        $st->execute([':id' => $adminId]);
        if ($st->rowCount() <= 0) {
            return ['ok' => false, 'error' => 'Admin account not found.'];
        }
        return ['ok' => true];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Database error: ' . $e->getMessage()];
    }
}

function admin_account_delete_all(PDO $dbh, int $currentAdminId = 0): array
{
    try {
        if ($currentAdminId > 0) {
            $st = $dbh->prepare('DELETE FROM admin WHERE idadmin <> :id');
            $st->execute([':id' => $currentAdminId]);
        } else {
            $dbh->exec('DELETE FROM admin');
        }
        return ['ok' => true];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Database error: ' . $e->getMessage()];
    }
}

function admin_account_set_status(PDO $dbh, int $adminId, int $status, int $currentAdminId = 0): array
{
    if ($adminId <= 0) {
        return ['ok' => false, 'error' => 'Invalid admin id.'];
    }
    if ($currentAdminId > 0 && $adminId === $currentAdminId && $status !== 1) {
        return ['ok' => false, 'error' => 'You cannot block your own account while signed in.'];
    }

    $status = $status === 1 ? 1 : 0;
    try {
        $st = $dbh->prepare('UPDATE admin SET status = :st WHERE idadmin = :id LIMIT 1');
        $st->execute([':st' => $status, ':id' => $adminId]);
        if ($st->rowCount() <= 0) {
            $chk = $dbh->prepare('SELECT status FROM admin WHERE idadmin = :id LIMIT 1');
            $chk->execute([':id' => $adminId]);
            if ($chk->fetchColumn() === false) {
                return ['ok' => false, 'error' => 'Admin account not found.'];
            }
        }
        return ['ok' => true, 'status' => $status];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Database error: ' . $e->getMessage()];
    }
}
