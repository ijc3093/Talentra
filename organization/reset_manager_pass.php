<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/../admin/controller.php';
$controller = new Controller();
$dbh = $controller->pdo();

$username = 'ikem';
$email    = 'ikem@local';
$newPass  = '123456';

echo "Connected DB: " . $dbh->query("SELECT DATABASE()")->fetchColumn() . "<br><br>";

$hash = password_hash($newPass, PASSWORD_DEFAULT);

// Create manager if not exists, else reset password
$st = $dbh->prepare("SELECT id FROM managers WHERE username = :u OR email = :e LIMIT 1");
$st->execute([':u' => $username, ':e' => $email]);
$id = $st->fetchColumn();

if (!$id) {
    $friend = 'MGR-' . random_int(1000, 9999);
    $ins = $dbh->prepare("
        INSERT INTO managers (friend_code, username, email, password, fullname, status, force_password_change)
        VALUES (:fc, :u, :e, :pw, :fn, 1, 0)
    ");
    $ins->execute([
        ':fc' => $friend,
        ':u'  => $username,
        ':e'  => $email,
        ':pw' => $hash,
        ':fn' => 'Ikemefuna',
    ]);
    echo "✅ Created manager.<br>";
} else {
    $up = $dbh->prepare("UPDATE managers SET password = :pw, status = 1 WHERE id = :id LIMIT 1");
    $up->execute([':pw' => $hash, ':id' => (int)$id]);
    echo "✅ Reset manager password.<br>";
}

echo "<br>Login at /organization/login.php<br>
Username: <b>{$username}</b><br>
Password: <b>{$newPass}</b><br><br>
⚠️ Delete reset_manager_pass.php after you confirm login.";
