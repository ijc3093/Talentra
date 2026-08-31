<?php
declare(strict_types=1);

/**
 * Unified home for Circle / Discover / program tabs.
 * Examples:
 *   home.php?tab=for-you
 *   home.php?tab=discover
 *   home.php?tab=news
 *
 * Keeps existing feed.php / public.php / news.php behavior via include.
 */

require_once __DIR__ . '/includes/home_tabs.php';

$rawTab = strtolower(trim((string)($_GET['tab'] ?? 'for-you')));
$internalTab = home_tab_internal($rawTab === '' ? 'for-you' : $rawTab);

// Canonicalize so included pages see a stable internal tab key.
$_GET['tab'] = $internalTab;

if (!defined('MSB_HOME_BOOTSTRAP')) {
    define('MSB_HOME_BOOTSTRAP', true);
}
if (!defined('MSB_HOME_TAB')) {
    define('MSB_HOME_TAB', $internalTab);
}
if (!defined('MSB_HOME_PAGE')) {
    define('MSB_HOME_PAGE', true);
}

$entry = home_entry_script($internalTab);
// Circle = feed.php (friends + followed publishers). Discover / programs = public.php.
if ($entry === 'feed') {
    require __DIR__ . '/feed.php';
    exit;
}
if ($entry === 'news') {
    if (!defined('MSB_PUBLIC_FEED_SURFACE')) {
        define('MSB_PUBLIC_FEED_SURFACE', 'news');
    }
}
require __DIR__ . '/public.php';
exit;
