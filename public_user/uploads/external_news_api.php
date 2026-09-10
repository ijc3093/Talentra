<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/session_user.php';
require_once __DIR__ . '/includes/external_news_feed.php';

requireUserLogin();

header('Content-Type: application/json; charset=UTF-8');

$category = strtolower(trim((string)($_GET['category'] ?? 'all')));
$query = trim((string)($_GET['q'] ?? ''));
$limit = (int)($_GET['limit'] ?? 40);
if ($limit < 1) {
    $limit = 40;
}
if ($limit > 80) {
    $limit = 80;
}

try {
    $items = external_news_collect($category, $query, $limit);
    echo json_encode([
        'ok' => true,
        'category' => external_news_is_valid_category($category) ? $category : 'all',
        'count' => count($items),
        'items' => $items,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Unable to load external news.',
        'items' => [],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
