<?php
declare(strict_types=1);

if (!function_exists('app_session_lifetime_seconds')) {

function app_session_lifetime_seconds(): int
{
    return 86400;
}

function app_session_login_mark(): void
{
    $_SESSION['_session_login_at'] = time();
}

function app_session_login_at(): int
{
    return (int)($_SESSION['_session_login_at'] ?? 0);
}

function app_session_is_expired(): bool
{
    $loginAt = app_session_login_at();
    if ($loginAt <= 0) {
        app_session_login_mark();
        return false;
    }

    return (time() - $loginAt) >= app_session_lifetime_seconds();
}

function app_session_redirect_with_expired(string $redirectUrl): void
{
    $join = (strpos($redirectUrl, '?') !== false) ? '&' : '?';
    header('Location: ' . $redirectUrl . $join . 'expired=1');
    exit;
}

function app_session_expired_message(): string
{
    return 'Your session expired after 24 hours. Please sign in again.';
}

}
