<?php
declare(strict_types=1);

function account_switch_ensure_schema(PDO $dbh): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $dbh->exec(
            "CREATE TABLE IF NOT EXISTS user_account_switch (
                id INT NOT NULL AUTO_INCREMENT,
                bundle_id CHAR(36) NOT NULL,
                user_id INT NOT NULL,
                added_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_user_account_switch_user (user_id),
                KEY idx_user_account_switch_bundle (bundle_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    } catch (Throwable $e) {
        // table may already exist
    }
}

function account_switch_new_bundle_id(): string
{
    try {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0x0fff) | 0x4000,
            random_int(0, 0x3fff) | 0x8000,
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0xffff)
        );
    } catch (Throwable $e) {
        return bin2hex(random_bytes(16));
    }
}

function account_switch_bundle_id(PDO $dbh, int $userId): string
{
    account_switch_ensure_schema($dbh);
    if ($userId <= 0) {
        return '';
    }
    try {
        $st = $dbh->prepare('SELECT bundle_id FROM user_account_switch WHERE user_id = :uid LIMIT 1');
        $st->execute([':uid' => $userId]);
        $id = trim((string)$st->fetchColumn());
        if ($id !== '') {
            return $id;
        }
        $id = account_switch_new_bundle_id();
        $ins = $dbh->prepare('INSERT INTO user_account_switch (bundle_id, user_id) VALUES (:b, :u)');
        $ins->execute([':b' => $id, ':u' => $userId]);
        return $id;
    } catch (Throwable $e) {
        return '';
    }
}

function account_switch_link(PDO $dbh, int $userA, int $userB): string
{
    if ($userA <= 0 || $userB <= 0 || $userA === $userB) {
        return account_switch_bundle_id($dbh, $userA > 0 ? $userA : $userB);
    }
    $bundleA = account_switch_bundle_id($dbh, $userA);
    $bundleB = account_switch_bundle_id($dbh, $userB);
    if ($bundleA === '' || $bundleB === '') {
        return $bundleA !== '' ? $bundleA : $bundleB;
    }
    if ($bundleA === $bundleB) {
        return $bundleA;
    }
    try {
        $up = $dbh->prepare('UPDATE user_account_switch SET bundle_id = :keep WHERE bundle_id = :drop');
        $up->execute([':keep' => $bundleA, ':drop' => $bundleB]);
    } catch (Throwable $e) {
        return $bundleA;
    }
    return $bundleA;
}

function account_switch_kind_label(array $user): string
{
    $kind = strtolower(trim((string)($user['account_kind'] ?? 'personal')));
    $cat = strtolower(trim((string)($user['publisher_category'] ?? '')));
    if ($kind === 'publisher' && $cat === 'commerce') {
        return 'Commerce';
    }
    if ($kind === 'publisher') {
        return 'Publisher';
    }
    return 'Personal';
}

function account_switch_avatar_url(array $row, int $size = 96): string
{
    if (function_exists('user_avatar_url')) {
        return user_avatar_url($row, $size);
    }
    $params = [];
    $userId = (int)($row['id'] ?? $row['user_id'] ?? 0);
    $email = trim((string)($row['email'] ?? ''));
    $friendCode = strtoupper(trim((string)($row['friend_code'] ?? '')));
    $username = trim((string)($row['username'] ?? $row['handle'] ?? ''));
    $name = trim((string)($row['name'] ?? $row['display_name'] ?? $username));
    if ($userId > 0) {
        $params[] = 'u=' . $userId;
    }
    if ($email !== '') {
        $params[] = 'email=' . rawurlencode($email);
    }
    if ($friendCode !== '') {
        $params[] = 'friend_code=' . rawurlencode($friendCode);
    }
    if ($username !== '') {
        $params[] = 'username=' . rawurlencode($username);
    }
    if ($name !== '') {
        $params[] = 'name=' . rawurlencode($name);
    }
    $params[] = 's=' . max(32, $size);
    return 'avatar.php?' . implode('&', $params);
}

function account_switch_list(PDO $dbh, int $userId): array
{
    $bundle = account_switch_bundle_id($dbh, $userId);
    $rows = [];
    if ($bundle !== '') {
        try {
            $st = $dbh->prepare(
                'SELECT u.id, u.name, u.username, u.email, u.image, u.friend_code, u.account_kind, u.publisher_category, u.status
                 FROM user_account_switch s
                 INNER JOIN users u ON u.id = s.user_id
                 WHERE s.bundle_id = :b
                 ORDER BY u.account_kind ASC, u.name ASC, u.id ASC'
            );
            $st->execute([':b' => $bundle]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            try {
                $st = $dbh->prepare(
                    'SELECT u.id, u.name, u.username, u.email, u.image, u.friend_code, u.status
                     FROM user_account_switch s
                     INNER JOIN users u ON u.id = s.user_id
                     WHERE s.bundle_id = :b
                     ORDER BY u.name ASC, u.id ASC'
                );
                $st->execute([':b' => $bundle]);
                $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } catch (Throwable $e2) {
                $rows = [];
            }
        }
    }
    $seen = [];
    $out = [];
    foreach ($rows as $row) {
        $item = account_switch_list_item($row, $userId);
        if ($item === null) {
            continue;
        }
        $seen[(int)$item['id']] = true;
        $out[] = $item;
    }
    if ($userId > 0 && empty($seen[$userId])) {
        $current = account_switch_load_user($dbh, $userId);
        if ($current) {
            $item = account_switch_list_item($current, $userId);
            if ($item !== null) {
                array_unshift($out, $item);
            }
        }
    }
    return $out;
}

function account_switch_list_item(array $row, int $currentUserId): ?array
{
    $id = (int)($row['id'] ?? $row['user_id'] ?? 0);
    if ($id <= 0) {
        return null;
    }
    $username = trim((string)($row['username'] ?? ''));
    $name = trim((string)($row['name'] ?? ''));
    $avatarUrl = account_switch_avatar_url($row, 96);
    return [
        'id' => $id,
        'user_id' => $id,
        'name' => $name,
        'display_name' => $name,
        'username' => $username,
        'handle' => $username,
        'email' => trim((string)($row['email'] ?? '')),
        'image' => $avatarUrl,
        'avatar_url' => $avatarUrl,
        'friend_code' => strtoupper(trim((string)($row['friend_code'] ?? ''))),
        'kind' => account_switch_kind_label($row),
        'account_kind' => strtolower(trim((string)($row['account_kind'] ?? 'personal'))),
        'status' => (int)($row['status'] ?? 1),
        'current' => $id === $currentUserId,
        'is_current' => $id === $currentUserId,
    ];
}

function account_switch_first_created_at(PDO $dbh, int $userId): string
{
    account_switch_ensure_schema($dbh);
    if ($userId <= 0) {
        return '';
    }
    try {
        $st = $dbh->prepare(
            'SELECT MIN(u.created_at)
             FROM user_account_switch s
             INNER JOIN user_account_switch s2 ON s2.bundle_id = s.bundle_id
             INNER JOIN users u ON u.id = s2.user_id
             WHERE s.user_id = :uid'
        );
        $st->execute([':uid' => $userId]);
        $min = trim((string)$st->fetchColumn());
        if ($min !== '' && $min !== '0000-00-00 00:00:00') {
            return $min;
        }
    } catch (Throwable $e) {
        // fall through to this account
    }
    try {
        $st = $dbh->prepare('SELECT created_at FROM users WHERE id = :uid LIMIT 1');
        $st->execute([':uid' => $userId]);
        return trim((string)$st->fetchColumn());
    } catch (Throwable $e) {
        return '';
    }
}

function account_switch_can_use(PDO $dbh, int $fromId, int $toId): bool
{
    if ($fromId <= 0 || $toId <= 0) {
        return false;
    }
    if ($fromId === $toId) {
        return true;
    }
    $bundle = account_switch_bundle_id($dbh, $fromId);
    if ($bundle === '') {
        return false;
    }
    try {
        $st = $dbh->prepare('SELECT 1 FROM user_account_switch WHERE bundle_id = :b AND user_id = :u LIMIT 1');
        $st->execute([':b' => $bundle, ':u' => $toId]);
        if ($st->fetchColumn()) {
            return true;
        }
    } catch (Throwable $e) {
        // fall through
    }
    foreach (account_switch_list($dbh, $fromId) as $row) {
        if ((int)($row['id'] ?? 0) === $toId) {
            return true;
        }
    }
    return false;
}

function account_switch_load_user(PDO $dbh, int $userId): ?array
{
    if ($userId <= 0) {
        return null;
    }
    try {
        $st = $dbh->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
        $st->execute([':id' => $userId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

function account_switch_is_staff_session(): bool
{
    return !empty($_SESSION['staff_publisher_mode'])
        || !empty($_SESSION['publisher_session_staff_id']);
}

function account_switch_is_add_request(): bool
{
    $v = strtolower(trim((string)($_POST['add_account'] ?? $_GET['add_account'] ?? '')));
    return $v === '1' || $v === 'true' || $v === 'yes';
}

function account_switch_pending_owner_id(): int
{
    if (account_switch_is_staff_session()) {
        return 0;
    }
    return (int)($_SESSION['user_id'] ?? 0);
}

function account_switch_complete_after_auth(PDO $dbh, int $fromId, int $toId): void
{
    if ($fromId > 0 && $toId > 0 && $fromId !== $toId) {
        account_switch_link($dbh, $fromId, $toId);
        try {
            require_once __DIR__ . '/account_admin_events.php';
            account_admin_event_notify($dbh, $fromId, 'add_account', [
                'from_id' => $fromId,
                'to_id' => $toId,
                'from_label' => 'user #' . $fromId,
                'to_label' => 'user #' . $toId,
            ]);
        } catch (Throwable $e) {
            // admin notice is optional
        }
    }
}

function account_switch_apply(PDO $dbh, int $fromId, int $toId): array
{
    if (!account_switch_can_use($dbh, $fromId, $toId)) {
        return ['ok' => false, 'error' => 'That account is not linked.'];
    }
    $user = account_switch_load_user($dbh, $toId);
    if (!$user) {
        return ['ok' => false, 'error' => 'Account was not found.'];
    }
    if ((int)($user['status'] ?? 1) !== 1) {
        return ['ok' => false, 'error' => 'That account is deactivated.'];
    }
    if (function_exists('user_is_account_removed') && user_is_account_removed($dbh, $toId)) {
        return ['ok' => false, 'error' => 'That account was removed.'];
    }
    setUserSession($user, false);
    $_SESSION['user_id'] = $toId;
    if (trim((string)($_SESSION['user_login'] ?? '')) === '') {
        $_SESSION['user_login'] = trim((string)($user['username'] ?? $user['email'] ?? '')) ?: ('user' . $toId);
    }
    $handle = trim((string)($user['username'] ?? ''));
    try {
        require_once __DIR__ . '/account_admin_events.php';
        $fromUser = account_switch_load_user($dbh, $fromId);
        $fromLabel = trim((string)($fromUser['username'] ?? $fromUser['name'] ?? '')) ?: ('user #' . $fromId);
        $toLabel = trim((string)($user['username'] ?? $user['name'] ?? '')) ?: ('user #' . $toId);
        $ctx = [
            'from_id' => $fromId,
            'to_id' => $toId,
            'from_label' => $fromLabel,
            'to_label' => $toLabel,
        ];
        account_admin_event_notify($dbh, $fromId, 'switch_account', $ctx);
    } catch (Throwable $e) {
        // admin notice is optional
    }
    return [
        'ok' => true,
        'user_id' => $toId,
        'username' => $handle,
        'handle' => $handle !== '' ? ('@' . ltrim($handle, '@')) : ('ID ' . $toId),
        'name' => trim((string)($user['name'] ?? '')),
        'redirect' => 'home.php?tab=for-you',
    ];
}

function account_switch_next(PDO $dbh, int $fromId): array
{
    $accounts = array_values(array_filter(
        account_switch_list($dbh, $fromId),
        static function (array $row): bool {
            return (int)($row['id'] ?? 0) > 0;
        }
    ));
    if (count($accounts) < 2) {
        return ['ok' => false, 'error' => 'No other linked account to switch to.'];
    }
    $currentIndex = 0;
    foreach ($accounts as $i => $row) {
        $id = (int)($row['id'] ?? 0);
        if ($id === $fromId || !empty($row['current']) || !empty($row['is_current'])) {
            $currentIndex = $i;
            break;
        }
    }
    $next = $accounts[($currentIndex + 1) % count($accounts)];
    $toId = (int)($next['id'] ?? 0);
    if ($toId <= 0 || $toId === $fromId) {
        return ['ok' => false, 'error' => 'No other linked account to switch to.'];
    }
    $result = account_switch_apply($dbh, $fromId, $toId);
    if (!empty($result['ok'])) {
        $handle = trim((string)($next['username'] ?? $result['username'] ?? ''));
        $result['username'] = $handle;
        $result['handle'] = $handle !== '' ? ('@' . ltrim($handle, '@')) : ('ID ' . $toId);
    }
    return $result;
}
