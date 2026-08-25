<?php
declare(strict_types=1);

require_once __DIR__ . '/theme_prefs.php';

global $dbh;

$userId = 0;
if (isset($meId) && (int)$meId > 0) {
    $userId = (int)$meId;
} elseif (function_exists('theme_prefs_viewer_user_id')) {
    $userId = theme_prefs_viewer_user_id();
}

if (isset($dbh) && $dbh instanceof PDO) {
    theme_prefs_print_head_bootstrap($dbh, $userId);
}
