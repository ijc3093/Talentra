<?php
declare(strict_types=1);

/**
 * Right rail wrapper — loads suggested_for_you.php.
 * Set $feedRightRailMode = 'none' to omit (e.g. feed.php).
 */
$feedRightRailMode = strtolower(trim((string)($feedRightRailMode ?? 'panel')));
if ($feedRightRailMode === 'none') {
    return;
}

$suggestedForYouMode = 'panel';
if (isset($feedRightRailStaffReadonly)) {
    $suggestedForYouStaffReadonly = $feedRightRailStaffReadonly;
}

require __DIR__ . '/suggested_for_you.php';
