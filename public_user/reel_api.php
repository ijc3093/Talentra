<?php
declare(strict_types=1);

/**
 * reel_api.php — JSON for mobile Clips theater (same query as reel.php).
 * GET: limit (1–100), post/from_post (optional), q (optional)
 * Uses feed_api.php?ajax=list&page=public&media=video&exclude_stories=1&order=created
 */
require_once __DIR__ . '/includes/session_user.php';
requireUserLogin();

$limit = (int)($_GET['limit'] ?? 80);
if ($limit < 1) {
    $limit = 80;
}
if ($limit > 100) {
    $limit = 100;
}
$postId = (int)($_GET['post'] ?? $_GET['from_post'] ?? 0);
$q = trim((string)($_GET['q'] ?? ''));

$_GET['ajax'] = 'list';
$_GET['filter'] = 'all';
$_GET['page'] = 'public';
$_GET['limit'] = (string)$limit;
$_GET['exclude_stories'] = '1';
$_GET['order'] = 'created';
$_GET['media'] = 'video';
if ($q !== '') {
    $_GET['q'] = $q;
} else {
    unset($_GET['q']);
}
if ($postId > 0) {
    $_GET['from_post'] = (string)$postId;
    $_GET['id'] = (string)$postId;
}

require __DIR__ . '/feed_api.php';
