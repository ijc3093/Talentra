<?php
declare(strict_types=1);

/**
 * Publisher discovery feed — public posts from news/brand accounts you have not followed yet.
 * Followed publisher posts appear in feed.php instead.
 */
define('MSB_PUBLIC_FEED_SURFACE', 'news');
require __DIR__ . '/public.php';
