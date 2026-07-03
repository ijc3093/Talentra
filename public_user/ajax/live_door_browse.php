<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/session_user.php';
requireUserLogin();
require_once __DIR__ . '/../controller.php';
require_once __DIR__ . '/../includes/live_browse.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$dbh = (new Controller())->pdo();
$meId = (int)($_SESSION['user_id'] ?? 0);
$hubSurface = live_browse_hub_surface($_GET['hub_surface'] ?? null);
$payload = live_browse_hub_payload($dbh, $meId, 50, $hubSurface);

echo json_encode([
    'ok' => true,
    'lives' => $payload['lives'],
    'public_lives' => $payload['public_lives'],
    'friend_lives' => $payload['friend_lives'],
    'browse_lives' => $payload['browse_lives'],
    'chat_lives' => $payload['chat_lives'],
    'featured' => $payload['featured'],
    'own_live_id' => $payload['own_live_id'],
    'hub_surface' => $payload['hub_surface'],
    'fingerprint' => $payload['fingerprint'],
    'public_fingerprint' => $payload['public_fingerprint'],
    'friend_fingerprint' => $payload['friend_fingerprint'],
    'browse_fingerprint' => $payload['browse_fingerprint'],
    'chat_fingerprint' => $payload['chat_fingerprint'],
], JSON_UNESCAPED_SLASHES);
