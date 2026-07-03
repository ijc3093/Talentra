<?php
declare(strict_types=1);

require_once __DIR__ . '/app_session_lifetime_load.php';

if (!function_exists('admin_linked_web_base_path')) {
    require_once __DIR__ . '/admin_linked_bootstrap.php';
}
