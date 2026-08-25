<?php
// /Business_only3/public_user/follow_toggle.php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/includes/session_user.php';
requireUserLogin();

require_once __DIR__ . '/controller.php';
$controller = new Controller();
$dbh = $controller->pdo();

$meId = (int)($_SESSION['user_id'] ?? 0);
$targetId = (int)($_POST['target_id'] ?? 0);

if ($meId <= 0 || $targetId <= 0 || $targetId === $meId) {
  echo json_encode(['ok'=>false]);
  exit;
}

function table_exists(PDO $dbh, string $table): bool {
  try {
    $st = $dbh->prepare("SHOW TABLES LIKE :t");
    $st->execute([':t' => $table]);
    return (bool)$st->fetchColumn();
  } catch (Throwable $e) { return false; }
}

if (!table_exists($dbh, 'public_follows')) {
  echo json_encode(['ok'=>false, 'error'=>'missing_table']);
  exit;
}

$following = false;

try {
  $st = $dbh->prepare("SELECT 1 FROM public_follows WHERE follower_id = :me AND following_id = :you LIMIT 1");
  $st->execute([':me'=>$meId, ':you'=>$targetId]);
  $exists = (bool)$st->fetchColumn();

  if ($exists) {
    $st = $dbh->prepare("DELETE FROM public_follows WHERE follower_id = :me AND following_id = :you LIMIT 1");
    $st->execute([':me'=>$meId, ':you'=>$targetId]);
    $following = false;
  } else {
    $st = $dbh->prepare("INSERT INTO public_follows (follower_id, following_id, created_at) VALUES (:me, :you, NOW())");
    $st->execute([':me'=>$meId, ':you'=>$targetId]);
    $following = true;
  }
} catch (Throwable $e) {
  echo json_encode(['ok'=>false]);
  exit;
}

$followers = 0;
try {
  $st = $dbh->prepare("SELECT COUNT(*) FROM public_follows WHERE following_id = :you");
  $st->execute([':you'=>$targetId]);
  $followers = (int)$st->fetchColumn();
} catch (Throwable $e) {}

echo json_encode(['ok'=>true, 'following'=>$following, 'followers'=>$followers]);
