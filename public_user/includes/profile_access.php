<?php
declare(strict_types=1);

/**
 * Profile About / Gear / Favorites may only be changed by the session account owner.
 * Blocks staff, other users, and other publishers from modifying someone else's settings.
 */

require_once __DIR__ . '/staff_publisher_access.php';
require_once __DIR__ . '/publisher_accounts.php';

function profile_session_owner_user_id(): int
{
    if (function_exists('publisher_session_canonical_user_id')) {
        return publisher_session_canonical_user_id();
    }

    return (int)($_SESSION['user_id'] ?? 0);
}

/** True when the profile being viewed belongs to the logged-in session owner. */
function profile_is_own_account(int $accountUserId): bool
{
    if ($accountUserId <= 0) {
        return false;
    }

    return profile_session_owner_user_id() === $accountUserId;
}

function profile_may_edit_account(PDO $dbh, int $accountUserId): bool
{
    if ($accountUserId <= 0) {
        return false;
    }

    if (!profile_is_own_account($accountUserId)) {
        return false;
    }

    if (staff_pub_is_staff_session()) {
        return false;
    }

    if (!empty($_SESSION['staff_publisher_mode']) || !empty($_SESSION['publisher_session_staff_id'])) {
        return false;
    }

    if (publisher_is_staff_workspace_session()) {
        return false;
    }

    if (publisher_is_publisher_user($dbh, $accountUserId)) {
        if (publisher_session_is_owner()) {
            return true;
        }

        $sessionUserId = (int)($_SESSION['user_id'] ?? 0);
        if ($sessionUserId === $accountUserId) {
            try {
                publisher_session_bind_owner($dbh, $accountUserId);
            } catch (Throwable $e) {
                // fall through
            }
            return publisher_session_is_owner();
        }

        return false;
    }

    return true;
}

function profile_settings_ensure_tab_privacy_columns(PDO $dbh): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }
    $ensured = true;
    try {
        $chk = $dbh->query("SHOW TABLES LIKE 'user_profile_settings'");
        if (!$chk || !$chk->fetchColumn()) {
            return;
        }
        $cols = [
            'show_tags_tab' => 'TINYINT(1) NOT NULL DEFAULT 1',
            'show_about_tab' => 'TINYINT(1) NOT NULL DEFAULT 1',
            'show_saved_tab' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'tagged_notifications' => 'TINYINT(1) NOT NULL DEFAULT 1',
            'saved_notifications' => 'TINYINT(1) NOT NULL DEFAULT 1',
            'birthday_notifications' => 'TINYINT(1) NOT NULL DEFAULT 1',
            'followed_notifications' => 'TINYINT(1) NOT NULL DEFAULT 1',
            'event_reminder_notifications' => 'TINYINT(1) NOT NULL DEFAULT 1',
            'memory_notifications' => 'TINYINT(1) NOT NULL DEFAULT 1',
            'post_visibility' => "VARCHAR(32) NOT NULL DEFAULT 'friends'",
            'story_visibility' => "VARCHAR(32) NOT NULL DEFAULT 'friends'",
            'reel_visibility' => "VARCHAR(32) NOT NULL DEFAULT 'friends'",
            'post_hide_from' => "TEXT NULL",
            'story_hide_from' => "TEXT NULL",
            'reel_hide_from' => "TEXT NULL",
        ];
        foreach ($cols as $name => $ddl) {
            $st = $dbh->query('SHOW COLUMNS FROM user_profile_settings LIKE ' . $dbh->quote($name));
            if ($st && $st->fetch(PDO::FETCH_ASSOC)) {
                continue;
            }
            $dbh->exec('ALTER TABLE user_profile_settings ADD COLUMN `' . $name . '` ' . $ddl);
        }
        $enumCols = [
            'profile_visibility' => 'public',
            'about_visibility' => 'friends',
            'gallery_visibility' => 'friends',
            'comment_permission' => 'friends',
            'friend_request_permission' => 'public',
            'message_permission' => 'friends',
        ];
        foreach ($enumCols as $name => $default) {
            $st = $dbh->query('SHOW COLUMNS FROM user_profile_settings LIKE ' . $dbh->quote($name));
            $info = $st ? $st->fetch(PDO::FETCH_ASSOC) : false;
            $type = strtolower((string)($info['Type'] ?? ''));
            if ($type === '' || strpos($type, 'everyone') !== false) {
                continue;
            }
            if (strpos($type, 'enum') === 0) {
                $safeDefault = preg_replace('/[^a-z_]/', '', $default) ?: 'public';
                $dbh->exec(
                    'ALTER TABLE user_profile_settings MODIFY `' . $name . '` '
                    . "ENUM('everyone','public','friends','only_me','approved_visitors') NOT NULL DEFAULT '" . $safeDefault . "'"
                );
            }
        }
    } catch (Throwable $e) {
        // Keep defaults in PHP if the table cannot be altered.
    }
}

function profile_setting_is_on(array $settings, string $field, int $defaultOn = 1): bool
{
    if (!array_key_exists($field, $settings) || $settings[$field] === null || $settings[$field] === '') {
        return $defaultOn === 1;
    }
    return (int)$settings[$field] === 1;
}

function profile_user_wants_notification(PDO $dbh, int $userId, string $field): bool
{
    $allowed = [
        'tagged_notifications' => true,
        'saved_notifications' => true,
        'birthday_notifications' => true,
        'followed_notifications' => true,
        'event_reminder_notifications' => true,
        'memory_notifications' => true,
        'friend_request_notifications' => true,
        'comment_notifications' => true,
        'reaction_notifications' => true,
        'share_notifications' => true,
        'email_notifications' => true,
    ];
    if ($userId <= 0 || $field === '' || !isset($allowed[$field])) {
        return true;
    }
    profile_settings_ensure_tab_privacy_columns($dbh);
    try {
        $st = $dbh->prepare('SELECT `' . str_replace('`', '', $field) . '` FROM user_profile_settings WHERE user_id = :uid LIMIT 1');
        $st->execute([':uid' => $userId]);
        $val = $st->fetchColumn();
        if ($val === false || $val === null || $val === '') {
            return true;
        }
        return (int)$val === 1;
    } catch (Throwable $e) {
        return true;
    }
}

function profile_privacy_audience_values(): array
{
    return ['everyone', 'public', 'friends', 'only_me', 'approved_visitors'];
}

function profile_privacy_hide_people_decode($raw): array
{
    $raw = trim((string)$raw);
    if ($raw === '' || $raw === '[]' || $raw === 'null') {
        return [];
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return [];
    }
    $out = [];
    foreach ($decoded as $item) {
        if (is_string($item) || is_int($item)) {
            $username = trim((string)$item);
            $id = 0;
        } else {
            $username = trim((string)($item['username'] ?? ''));
            $id = (int)($item['id'] ?? 0);
        }
        $username = ltrim($username, '@');
        if ($username === '' && $id <= 0) {
            continue;
        }
        $key = $id > 0 ? ('id:' . $id) : ('u:' . strtolower($username));
        $out[$key] = ['id' => $id, 'username' => $username];
    }
    return array_values($out);
}

function profile_privacy_hide_people_encode(PDO $dbh, int $ownerId, $raw): string
{
    $people = is_array($raw) ? $raw : profile_privacy_hide_people_decode($raw);
    $clean = [];
    $seen = [];
    foreach ($people as $item) {
        $username = ltrim(trim((string)($item['username'] ?? $item)), '@');
        $id = (int)($item['id'] ?? 0);
        if ($username === '' && $id <= 0) {
            continue;
        }
        try {
            if ($id > 0) {
                $st = $dbh->prepare('SELECT id, username FROM users WHERE id = :id LIMIT 1');
                $st->execute([':id' => $id]);
            } else {
                $st = $dbh->prepare('SELECT id, username FROM users WHERE LOWER(TRIM(username)) = LOWER(:u) LIMIT 1');
                $st->execute([':u' => $username]);
            }
            $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            $row = [];
        }
        $id = (int)($row['id'] ?? 0);
        $username = trim((string)($row['username'] ?? $username));
        if ($id <= 0 || $id === $ownerId || $username === '') {
            continue;
        }
        if (isset($seen[$id])) {
            continue;
        }
        $seen[$id] = true;
        $clean[] = ['id' => $id, 'username' => $username];
    }
    return json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function profile_viewer_hidden_from_content(array $settings, int $viewerId, string $viewerUsername, string $kind): bool
{
    if ($viewerId <= 0) {
        return false;
    }
    $map = [
        'post' => 'post_hide_from',
        'story' => 'story_hide_from',
        'reel' => 'reel_hide_from',
    ];
    $field = $map[$kind] ?? '';
    if ($field === '') {
        return false;
    }
    $viewerUsername = strtolower(ltrim(trim($viewerUsername), '@'));
    foreach (profile_privacy_hide_people_decode($settings[$field] ?? '') as $person) {
        if ((int)($person['id'] ?? 0) === $viewerId) {
            return true;
        }
        if ($viewerUsername !== '' && strtolower((string)($person['username'] ?? '')) === $viewerUsername) {
            return true;
        }
    }
    return false;
}

function profile_require_edit_access(PDO $dbh, int $accountUserId, bool $json = true): void
{
    if (profile_may_edit_account($dbh, $accountUserId)) {
        return;
    }

    if ($json) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => false,
            'error' => 'forbidden_profile_edit',
            'message' => 'You can only change About, Gear, and Favorites on your own account.',
        ], JSON_UNESCAPED_SLASHES);
    } else {
        header('Location: profile.php?tab=posts');
    }
    exit;
}
