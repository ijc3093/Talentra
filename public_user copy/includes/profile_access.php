<?php
declare(strict_types=1);

/**
 * Profile About / Gear / Preserve may only be changed by the session account owner.
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
            'message' => 'You can only change About, Gear, and Preserve on your own account.',
        ], JSON_UNESCAPED_SLASHES);
    } else {
        header('Location: profile.php?tab=posts');
    }
    exit;
}
