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
        ];
        foreach ($cols as $name => $ddl) {
            $st = $dbh->query('SHOW COLUMNS FROM user_profile_settings LIKE ' . $dbh->quote($name));
            if ($st && $st->fetch(PDO::FETCH_ASSOC)) {
                continue;
            }
            $dbh->exec('ALTER TABLE user_profile_settings ADD COLUMN `' . $name . '` ' . $ddl);
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
