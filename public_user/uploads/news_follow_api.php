<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/session_user.php';
require_once __DIR__ . '/includes/news_publisher_follows.php';

requireUserLogin();

header('Content-Type: application/json; charset=UTF-8');

$meId = (int)($_SESSION['user_id'] ?? 0);
$action = strtolower(trim((string)($_REQUEST['action'] ?? 'list')));

try {
    if ($action === 'list') {
        echo json_encode([
            'ok' => true,
            'publishers' => news_publisher_follows_with_meta($meId),
            'followed' => news_publisher_follows_read($meId),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'toggle') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['ok' => false, 'error' => 'POST required.']);
            exit;
        }
        $key = strtolower(trim((string)($_POST['publisher_key'] ?? '')));
        $result = news_publisher_follow_toggle($meId, $key);
        echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Unknown action.']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Follow API error.']);
}
