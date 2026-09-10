<?php
declare(strict_types=1);

/**
 * Tracks deleted public_user accounts for login messaging (no session side effects).
 */

function user_deleteduser_table_exists(PDO $dbh): bool
{
    try {
        $st = $dbh->query("SHOW TABLES LIKE " . $dbh->quote('deleteduser'));
        return (bool)($st && $st->fetchColumn());
    } catch (Throwable $e) {
        return false;
    }
}

function user_deleteduser_has_column(PDO $dbh, string $column): bool
{
    $column = preg_replace('/[^a-z0-9_]/i', '', $column) ?? '';
    if ($column === '' || !user_deleteduser_table_exists($dbh)) {
        return false;
    }
    try {
        $st = $dbh->query("SHOW COLUMNS FROM deleteduser LIKE " . $dbh->quote($column));
        return (bool)($st && $st->fetch(PDO::FETCH_ASSOC));
    } catch (Throwable $e) {
        return false;
    }
}

function user_deleteduser_ensure_schema(PDO $dbh): void
{
    static $done = false;
    if ($done || !user_deleteduser_table_exists($dbh)) {
        return;
    }
    $done = true;

    $columns = [
        'username' => "ALTER TABLE deleteduser ADD COLUMN username VARCHAR(50) NOT NULL DEFAULT '' AFTER email",
        'friend_code' => "ALTER TABLE deleteduser ADD COLUMN friend_code VARCHAR(20) NOT NULL DEFAULT '' AFTER username",
        'display_name' => "ALTER TABLE deleteduser ADD COLUMN display_name VARCHAR(100) NOT NULL DEFAULT '' AFTER friend_code",
        'mobile' => "ALTER TABLE deleteduser ADD COLUMN mobile VARCHAR(30) NOT NULL DEFAULT '' AFTER display_name",
        'account_kind' => "ALTER TABLE deleteduser ADD COLUMN account_kind VARCHAR(20) NOT NULL DEFAULT 'personal' AFTER mobile",
    ];

    foreach ($columns as $column => $sql) {
        if (!user_deleteduser_has_column($dbh, $column)) {
            try {
                $dbh->exec($sql);
            } catch (Throwable $e) {
                // Non-fatal.
            }
        }
    }

    try {
        if (user_deleteduser_has_column($dbh, 'username')) {
            $dbh->exec('ALTER TABLE deleteduser ADD KEY idx_deleteduser_username (username)');
        }
    } catch (Throwable $e) {
    }
    try {
        if (user_deleteduser_has_column($dbh, 'friend_code')) {
            $dbh->exec('ALTER TABLE deleteduser ADD KEY idx_deleteduser_friend_code (friend_code)');
        }
    } catch (Throwable $e) {
    }
}

function user_record_deleted_account(
    PDO $dbh,
    string $email,
    string $username = '',
    string $friendCode = '',
    string $displayName = '',
    string $mobile = '',
    string $accountKind = 'personal'
): void {
    if (!user_deleteduser_table_exists($dbh)) {
        return;
    }

    user_deleteduser_ensure_schema($dbh);

    $email = trim($email);
    $username = trim($username);
    $friendCode = strtoupper(trim($friendCode));
    $displayName = trim($displayName);
    $mobile = trim($mobile);
    $accountKind = strtolower(trim($accountKind));
    if ($accountKind !== 'publisher') {
        $accountKind = 'personal';
    }

    if ($email === '' && $username === '' && $friendCode === '' && $displayName === '' && $mobile === '') {
        return;
    }

    $hasUsername = user_deleteduser_has_column($dbh, 'username');
    $hasFriendCode = user_deleteduser_has_column($dbh, 'friend_code');
    $hasDisplayName = user_deleteduser_has_column($dbh, 'display_name');
    $hasMobile = user_deleteduser_has_column($dbh, 'mobile');
    $hasAccountKind = user_deleteduser_has_column($dbh, 'account_kind');

    if ($hasUsername && $hasFriendCode && $hasDisplayName && $hasMobile && $hasAccountKind) {
        try {
            $st = $dbh->prepare('
                INSERT INTO deleteduser (email, username, friend_code, display_name, mobile, account_kind)
                VALUES (:email, :username, :friend_code, :display_name, :mobile, :account_kind)
            ');
            $st->execute([
                ':email' => $email,
                ':username' => $username,
                ':friend_code' => $friendCode,
                ':display_name' => $displayName,
                ':mobile' => $mobile,
                ':account_kind' => $accountKind,
            ]);
            return;
        } catch (Throwable $e) {
            // Fall through to simpler inserts.
        }
    }

    if ($hasUsername && $hasFriendCode && $hasDisplayName) {
        try {
            $st = $dbh->prepare('
                INSERT INTO deleteduser (email, username, friend_code, display_name)
                VALUES (:email, :username, :friend_code, :display_name)
            ');
            $st->execute([
                ':email' => $email,
                ':username' => $username,
                ':friend_code' => $friendCode,
                ':display_name' => $displayName,
            ]);
            return;
        } catch (Throwable $e) {
            // Fall through to simpler inserts.
        }
    }

    if ($hasUsername) {
        try {
            $st = $dbh->prepare('INSERT INTO deleteduser (email, username) VALUES (:email, :username)');
            $st->execute([':email' => $email, ':username' => $username]);
            return;
        } catch (Throwable $e) {
        }
    }

    if ($email !== '') {
        try {
            $st = $dbh->prepare('INSERT INTO deleteduser (email) VALUES (:email)');
            $st->execute([':email' => $email]);
        } catch (Throwable $e) {
        }
    }
}

function user_deleted_login_digits(string $value): string
{
    return preg_replace('/\D+/', '', $value) ?? '';
}

function user_login_identifier_was_deleted(PDO $dbh, string $login): bool
{
    $login = trim($login);
    if ($login === '' || !user_deleteduser_table_exists($dbh)) {
        return false;
    }

    user_deleteduser_ensure_schema($dbh);

    $lowerLogin = mb_strtolower($login);
    $upperLogin = strtoupper($login);
    $digitLogin = user_deleted_login_digits($login);

    try {
        $checks = ['LOWER(TRIM(email)) = :lower_email'];
        $params = [':lower_email' => $lowerLogin];

        if (user_deleteduser_has_column($dbh, 'username')) {
            $checks[] = 'LOWER(TRIM(username)) = :lower_username';
            $params[':lower_username'] = $lowerLogin;
        }
        if (user_deleteduser_has_column($dbh, 'friend_code')) {
            $checks[] = 'UPPER(TRIM(friend_code)) = :upper_friend_code';
            $params[':upper_friend_code'] = $upperLogin;
        }
        if (user_deleteduser_has_column($dbh, 'display_name')) {
            $checks[] = 'LOWER(TRIM(display_name)) = :lower_display_name';
            $params[':lower_display_name'] = $lowerLogin;
        }
        if ($digitLogin !== '' && user_deleteduser_has_column($dbh, 'mobile')) {
            $checks[] = "REPLACE(REPLACE(REPLACE(REPLACE(TRIM(mobile), ' ', ''), '-', ''), '(', ''), ')', '') = :digits_mobile";
            $params[':digits_mobile'] = $digitLogin;
        }

        $sql = 'SELECT 1 FROM deleteduser WHERE ' . implode(' OR ', $checks) . ' LIMIT 1';
        $st = $dbh->prepare($sql);
        $st->execute($params);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}
