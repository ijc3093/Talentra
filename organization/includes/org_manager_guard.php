<?php
declare(strict_types=1);

/** Redirect non-managers away from manager-only publisher tools. */
function org_require_manager(string $redirect = 'feed.php'): void
{
    if (!isOrgManager()) {
        header('Location: ' . $redirect);
        exit;
    }
}
