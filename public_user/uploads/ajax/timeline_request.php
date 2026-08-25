<?php
// /Business_only3/public_user/ajax/timeline_request.php
// ✅ Visitor requests access to owner's timeline

declare(strict_types=1);

require_once __DIR__ . '/../includes/session_user.php';
requireUserLogin();

require_once __DIR__ . '/../controller.php';

header('Content-Type: application/json; charset=utf-8');

function jexit(array $a): void { echo json_encode($a); exit; }

$meId = (int)($_SESSION['user_id'] ?? 0);
if ($meId <= 0) jexit(['ok' => false, 'error' => 'Invalid session']);

$ownerId = (int)($_POST['owner_id'] ?? 0);
if ($ownerId <= 0) jexit(['ok' => false, 'error' => 'Missing owner_id']);
if ($ownerId === $meId) jexit(['ok' => true, 'status' => 'approved']);

$controller = new Controller();
$dbh = $controller->pdo();

try {
  // If exists -> keep status unless denied/revoked, then allow re-request by setting pending again
  $st = $dbh->prepare('SELECT id, status FROM timeline_access_requests WHERE owner_user_id = :o AND requester_user_id = :r LIMIT 1');
  $st->execute([':o' => $ownerId, ':r' => $meId]);
  $row = $st->fetch(PDO::FETCH_ASSOC);

  if ($row) {
    $status = (string)($row['status'] ?? 'pending');
    if ($status === 'approved') jexit(['ok' => true, 'status' => 'approved']);
    if ($status === 'pending') jexit(['ok' => true, 'status' => 'pending']);

    // denied/revoked => re-request
    $stU = $dbh->prepare('UPDATE timeline_access_requests SET status = "pending", updated_at = NOW() WHERE id = :id LIMIT 1');
    $stU->execute([':id' => (int)$row['id']]);
    jexit(['ok' => true, 'status' => 'pending']);
  }

  $stI = $dbh->prepare('INSERT INTO timeline_access_requests (owner_user_id, requester_user_id, status, created_at, updated_at) VALUES (:o, :r, "pending", NOW(), NOW())');
  $stI->execute([':o' => $ownerId, ':r' => $meId]);

  jexit(['ok' => true, 'status' => 'pending']);
} catch (Throwable $e) {
  jexit(['ok' => false, 'error' => 'Timeline tables missing or DB error']);
}
