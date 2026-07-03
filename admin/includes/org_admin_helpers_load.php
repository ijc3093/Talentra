<?php
declare(strict_types=1);

if (!function_exists('org_admin_h')) {
    $file = realpath(__DIR__ . '/org_admin_helpers.php');
    if ($file) {
        require_once $file;
    }
}
