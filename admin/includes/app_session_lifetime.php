<?php
declare(strict_types=1);

/**
 * Session helpers — time-based auto sign-out has been removed.
 * Logins persist until the user signs out (or the browser clears cookies).
 */
if (!function_exists('app_session_lifetime_seconds')) {

/** PHP session-file retention only. Does NOT force logout. */
function app_session_lifetime_seconds(): int
{
    return 365 * 24 * 3600;
}

/** No-op (kept so older call sites do not fatal). */
function app_session_login_mark(): void
{
}

/** No-op (kept so older call sites do not fatal). */
function app_session_touch_activity(): void
{
}

}
