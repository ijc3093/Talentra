<?php
// /Business_only3/public_user/profile_update.php
declare(strict_types=1);

require_once __DIR__ . '/includes/session_user.php';
requireUserLogin();

require_once __DIR__ . '/controller.php';
$controller = new Controller();
$dbh = $controller->pdo();

$meId = (int)($_SESSION['user_id'] ?? 0);
if ($meId <= 0) { header("Location: profile.php"); exit; }

function clean(string $s, int $max = 255): string {
  $s = trim($s);
  if (strlen($s) > $max) $s = substr($s, 0, $max);
  return $s;
}

$name        = clean((string)($_POST['name'] ?? ''), 120);
$username    = clean((string)($_POST['username'] ?? ''), 60);
$status      = clean((string)($_POST['status'] ?? ''), 500);
$designation = clean((string)($_POST['designation'] ?? ''), 120);
$mobile      = clean((string)($_POST['mobile'] ?? ''), 40);
$gender      = clean((string)($_POST['gender'] ?? ''), 40);

try {
  $st = $dbh->prepare("
    UPDATE users SET
      fullname = :name,
      username = :username,
      status = :status,
      designation = :designation,
      mobile = :mobile,
      gender = :gender
    WHERE id = :id
    LIMIT 1
  ");
  $st->execute([
    ':name' => $name,
    ':username' => $username,
    ':status' => $status,
    ':designation' => $designation,
    ':mobile' => $mobile,
    ':gender' => $gender,
    ':id' => $meId
  ]);
} catch (Throwable $e) {
  try {
    $st = $dbh->prepare("UPDATE users SET fullname = :name, username = :username WHERE id = :id LIMIT 1");
    $st->execute([':name'=>$name, ':username'=>$username, ':id'=>$meId]);
  } catch (Throwable $e2) {}
}

header("Location: profile.php");
exit;
