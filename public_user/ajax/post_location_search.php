<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/session_user.php';
requireUserLogin();
require_once __DIR__ . '/../includes/post_location.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

$q = trim((string)($_GET['q'] ?? $_POST['q'] ?? ''));
$result = post_location_search($q);

echo json_encode([
    'ok' => !empty($result['ok']),
    'places' => $result['places'] ?? [],
    'error' => (string)($result['error'] ?? ''),
    'mode' => (string)($result['mode'] ?? ''),
], JSON_UNESCAPED_UNICODE);
