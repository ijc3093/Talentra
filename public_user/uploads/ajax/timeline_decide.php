<?php
// /Business_only3/public_user/ajax/timeline_decide.php
// ✅ Owner approves/denies a request

declare(strict_types=1);

require_once __DIR__ . '/../includes/session_user.php';
requireUserLogin();

require_once __DIR__ . '/../controller.php';

header('Content-Type: application/json; charset=utf-8');

function jexit(array $a): void { echo json_encode($a); exit; }

$meId = (int)($_SESSION['user_id'] ?? 0);
if ($meId <= 0) jexit(['ok' => false, 'error' => 'Invalid session']);

$requestId = (int)($_POST['request_id'] ?? 0);
$action = trim((string)($_POST['action'] ?? ''));
if ($requestId <= 0) jexit(['ok' => false, 'error' => 'Missing request_id']);
if (!in_array($action, ['approve','deny'], true)) jexit(['ok' => false, 'error' => 'Invalid action']);

$status = ($action === 'approve') ? 'approved' : 'denied';

$controller = new Controller();
$dbh = $controller->pdo();

try {
  // Ensure owner owns this request
  $st = $dbh->prepare('UPDATE timeline_access_requests SET status = :st, updated_at = NOW() WHERE id = :id AND owner_user_id = :me LIMIT 1');
  $st->execute([':st' => $status, ':id' => $requestId, ':me' => $meId]);

  if ($st->rowCount() <= 0) jexit(['ok' => false, 'error' => 'Request not found']);
  jexit(['ok' => true]);
} catch (Throwable $e) {
  jexit(['ok' => false, 'error' => 'Timeline tables missing or DB error']);
}
