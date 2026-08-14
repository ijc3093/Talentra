<?php
declare(strict_types=1);

/**
 * news.php — publisher news / brand discovery only.
 * Shows public posts from publisher accounts you have not followed yet (plus your own publisher posts).
 * Personal Friends Feed posts belong on feed.php.
 * Personal Public posts belong on public.php.
 * This page is not a create-post destination.
 */
require_once __DIR__ . '/includes/home_tabs.php';
home_redirect_legacy_entry('news');
define('MSB_PUBLIC_FEED_SURFACE', 'news');
require __DIR__ . '/public.php';
