<!-- 
 - How to create a new username and password? Please use this link: http://localhost:8888/talentra/organization/bootstrap_manager.php. 
 - It will create a new username and password automically by itself.
 - Username and password will save in the organization table in database
 - Access the login using username and password that the link created for you recently.
 - It will go direclty to create_org.php and ask you to create a new company name. Type "CMD" in the input and click "Create" Button to create new company.
 -->

<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/../admin/controller.php';
$controller = new Controller();
$dbh = $controller->pdo();

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

echo "Connected DB: " . $dbh->query("SELECT DATABASE()")->fetchColumn() . "<br><br>";

$username = 'ikem';
$email    = 'ikem@local';
$pass     = '123456';
$fullname = 'Ikemefuna';
$friend   = 'MGR-' . random_int(1000, 9999);

// Ensure table exists
try {
    $dbh->query("SELECT 1 FROM managers LIMIT 1");
} catch (Throwable $e) {
    die("❌ managers table not found. Run the org migration SQL first.");
}

// Check existing
$st = $dbh->prepare("SELECT id FROM managers WHERE username=:u OR email=:e LIMIT 1");
$st->execute([':u'=>$username, ':e'=>$email]);
$exists = $st->fetchColumn();

if ($exists) {
    echo "✅ Manager already exists.<br>Login with:<br><b>{$username}</b> / <b>{$pass}</b><br><br>
    If you forgot the password, tell me and I’ll give you a reset script.";
    exit;
}

// Create
$hash = password_hash($pass, PASSWORD_DEFAULT);

$ins = $dbh->prepare("
  INSERT INTO managers (friend_code, username, email, password, fullname, status, force_password_change)
  VALUES (:fc, :u, :e, :pw, :fn, 1, 0)
");
$ins->execute([
  ':fc' => $friend,
  ':u'  => $username,
  ':e'  => $email,
  ':pw' => $hash,
  ':fn' => $fullname
]);

echo "✅ Created Manager.<br>
Username: <b>{$username}</b><br>
Password: <b>{$pass}</b><br>
Friend_code: <b>{$friend}</b><br><br>
Now go to <b>/organization/login.php</b><br><br>
⚠️ IMPORTANT: Delete bootstrap_manager.php after you log in.";
