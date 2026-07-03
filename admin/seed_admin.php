<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/controller.php';

$controller = new Controller();
$dbh = $controller->pdo();

// ===== CHANGE THESE VALUES =====
$fullname = "Super Admin";
$username = "admin";
$email    = "admin@gmail.com";
$password = "Admin@12345"; // TEMP password
$role     = 1; // Admin
$status   = 1;
$image    = "default.jpg";
$friend   = "ADM-ROOT-0001";
// ===============================

// Generate bcrypt hash (matches your $2y$10$... format)
$hash = password_hash($password, PASSWORD_DEFAULT);

// Prevent duplicates
$chk = $dbh->prepare("SELECT 1 FROM admin WHERE email = ? OR username = ? LIMIT 1");
$chk->execute([$email, $username]);
if ($chk->fetchColumn()) {
    die("❌ Admin already exists. Delete seed_admin.php now.");
}

// Insert admin
$ins = $dbh->prepare("
    INSERT INTO admin (fullname, username, email, password, role, status, image, friend_code)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
");
$ins->execute([
    $fullname,
    $username,
    $email,
    $hash,
    $role,
    $status,
    $image,
    $friend
]);

$adminId = (int)$dbh->lastInsertId();

require_once __DIR__ . '/includes/admin_linked_accounts_load.php';
admin_linked_provision($dbh, $adminId, $password);

echo "✅ ADMIN CREATED<br><br>";
echo "Email: {$email}<br>";
echo "Password: {$password}<br>";
echo "<br><b>DELETE seed_admin.php NOW</b>";
