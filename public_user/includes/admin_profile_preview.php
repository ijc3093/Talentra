<?php
declare(strict_types=1);

/**
 * Lets a logged-in admin open public_user/profile.php for a target user
 * without signing into public_user separately.
 */
function admin_profile_preview_validate(int $targetUserId): bool
{
    if ($targetUserId <= 0) {
        return false;
    }

    $userSessionName = session_name();
    $userSessionId = session_id();
    $userSessionActive = session_status() === PHP_SESSION_ACTIVE;

    if ($userSessionActive) {
        session_write_close();
    }

    $ok = false;

    try {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        session_name('BUSINESS_ONLY_ADMIN');
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $isAdmin = ((int)($_SESSION['userRole'] ?? 0) === 1) && !empty($_SESSION['admin_login']);
        $previewId = (int)($_SESSION['admin_open_profile_user_id'] ?? 0);
        $expires = (int)($_SESSION['admin_open_profile_expires'] ?? 0);

        if ($isAdmin && $previewId === $targetUserId && $expires >= time()) {
            $ok = true;
            unset($_SESSION['admin_open_profile_user_id'], $_SESSION['admin_open_profile_expires']);
        }

        session_write_close();
    } catch (Throwable $e) {
        $ok = false;
    }

    session_name($userSessionName);
    if ($userSessionId !== '') {
        session_id($userSessionId);
    }
    if ($userSessionActive || session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    return $ok;
}

function admin_profile_preview_is_active(): bool
{
    return !empty($GLOBALS['__admin_profile_preview']);
}

function admin_profile_preview_mark_active(): void
{
    $GLOBALS['__admin_profile_preview'] = true;
}
