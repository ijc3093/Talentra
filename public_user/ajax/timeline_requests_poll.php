<?php
// /Business_only3/public_user/ajax/timeline_requests_poll.php
// ✅ Owner loads incoming timeline access requests

declare(strict_types=1);

require_once __DIR__ . '/../includes/session_user.php';
requireUserLogin();

require_once __DIR__ . '/../controller.php';

header('Content-Type: application/json; charset=utf-8');

function jexit(array $a): void { echo json_encode($a); exit; }

$meId = (int)($_SESSION['user_id'] ?? 0);
if ($meId <= 0) jexit(['ok' => false, 'error' => 'Invalid session']);

$controller = new Controller();
$dbh = $controller->pdo();

try {
  $st = $dbh->prepare('
    SELECT r.id, r.status, r.created_at,
           u.id AS requester_id,
           u.username,
           COALESCE(u.name, u.username) AS display_name
    FROM timeline_access_requests r
    JOIN users u ON u.id = r.requester_user_id
    WHERE r.owner_user_id = :me
    ORDER BY (r.status = "pending") DESC, r.updated_at DESC, r.created_at DESC
    LIMIT 100
  ');
  $st->execute([':me' => $meId]);
  $items = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  jexit(['ok' => true, 'items' => $items]);
} catch (Throwable $e) {
  jexit(['ok' => false, 'error' => 'Timeline tables missing or DB error']);
}
