<?php
declare(strict_types=1);

require_once __DIR__ . '/org_admin_helpers_load.php';

if (!function_exists('user_admin_db')) {
    $file = realpath(__DIR__ . '/user_admin_helpers.php');
    if ($file) {
        require_once $file;
    }
}
