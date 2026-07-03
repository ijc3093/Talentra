<?php

// /Business_only3/admin/change-password.php
require_once __DIR__ . '/includes/session_admin.php';
requireAdminLogin();

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/controller.php';

$controller = new Controller();
$dbh = $controller->pdo();

$error = '';
$msg   = '';

$username = $_SESSION['admin_login'] ?? '';
$adminId  = (int)($_SESSION['admin_id'] ?? 0);

// forced mode from URL OR from DB flag (stronger)
$forceUrl = isset($_GET['force']) && $_GET['force'] == '1';

if ($username === '' || $adminId <= 0) {
    clearAdminSession();
    header("Location: index.php");
    exit;
}

// Pull current hash + force flag from DB (so user cannot bypass ?force=1)
$stAcc = $dbh->prepare("
    SELECT idadmin, username, password, force_password_change, status
    FROM admin
    WHERE idadmin = :id
    LIMIT 1
");
$stAcc->execute([':id' => $adminId]);
$acc = $stAcc->fetch(PDO::FETCH_ASSOC);

if (!$acc) {
    clearAdminSession();
    header("Location: index.php");
    exit;
}

if ((int)$acc['status'] !== 1) {
    clearAdminSession();
    header("Location: index.php");
    exit;
}

$forceDb = ((int)($acc['force_password_change'] ?? 0) === 1);
$force   = $forceUrl || $forceDb;

// If forced, we still show the page, but we will require current password OR allow skipping it.
// Recommendation: allow skipping current password ONLY when forced, because admin used a temp password anyway.
// If you want to ALWAYS require current password, set this to true.
$requireCurrentWhenForced = false;

if (isset($_POST['submit'])) {
    $current = (string)($_POST['password'] ?? '');
    $new     = trim((string)($_POST['newpassword'] ?? ''));
    $confirm = trim((string)($_POST['confirmpassword'] ?? ''));

    // Basic validations
    if ($new === '' || $confirm === '') {
        $error = "New password fields are required.";
    } elseif ($new !== $confirm) {
        $error = "New Password and Confirm Password do not match.";
    } elseif (strlen($new) < 8) {
        $error = "New password must be at least 8 characters.";
    } else {

        $dbHash = (string)$acc['password'];

        // Decide if we must verify current password
        $mustCheckCurrent = (!$force) || $requireCurrentWhenForced;

        if ($mustCheckCurrent) {
            if ($current === '') {
                $error = "Current password is required.";
            } elseif (!password_verify($current, $dbHash)) {
                $error = "Your current password is not valid.";
            }
        }

        // Also prevent setting the same password again (best effort)
        if ($error === '' && $current !== '' && hash_equals($current, $new)) {
            $error = "New password must be different from current password.";
        }

        // If forced and we did NOT verify current, still prevent same password by checking verify()
        if ($error === '' && $force && !$mustCheckCurrent) {
            if (password_verify($new, $dbHash)) {
                $error = "New password must be different from current password.";
            }
        }

        if ($error === '') {
            $newHash = password_hash($new, PASSWORD_DEFAULT);

            // Optional column: last_password_change_at
            // If you don't have it, this query will fail.
            // So we detect if the column exists and update accordingly.
            $hasCol = false;
            try {
                $chk = $dbh->query("SHOW COLUMNS FROM admin LIKE 'last_password_change_at'");
                $hasCol = (bool)$chk->fetch();
            } catch (Throwable $e) {
                $hasCol = false;
            }

            if ($hasCol) {
                $up = $dbh->prepare("
                    UPDATE admin
                    SET password = :p,
                        force_password_change = 0,
                        last_password_change_at = NOW()
                    WHERE idadmin = :id
                    LIMIT 1
                ");
            } else {
                $up = $dbh->prepare("
                    UPDATE admin
                    SET password = :p,
                        force_password_change = 0
                    WHERE idadmin = :id
                    LIMIT 1
                ");
            }

            $up->execute([
                ':p'  => $newHash,
                ':id' => (int)$acc['idadmin']
            ]);

            $msg = "Your Password Successfully Changed";

            // After forced change, go dashboard
            if ($force) {
                header("Location: dashboard.php");
                exit;
            }
        }
    }
}
?>


<!DOCTYPE html>
<html lang="en">
  <head>
    <!-- Required meta tags -->
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">

    <!-- Twitter -->
    <meta name="twitter:site" content="@themepixels">
    <meta name="twitter:creator" content="@themepixels">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="Talentra">
    <meta name="twitter:description" content="Premium Quality and Responsive UI for Dashboard.">
    <meta name="twitter:image" content="http://themepixels.me/shamcey/img/shamcey-social.png">

    <!-- Facebook -->
    <meta property="og:url" content="http://themepixels.me/shamcey">
    <meta property="og:title" content="Talentra">
    <meta property="og:description" content="Premium Quality and Responsive UI for Dashboard.">

    <meta property="og:image" content="http://themepixels.me/shamcey/img/shamcey-social.png">
    <meta property="og:image:secure_url" content="http://themepixels.me/shamcey/img/shamcey-social.png">
    <meta property="og:image:type" content="image/png">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="600">

    <!-- Meta -->
    <meta name="description" content="Premium Quality and Responsive UI for Dashboard.">
    <meta name="author" content="ThemePixels">


    <title>Registration New Account</title>

    <!-- Vendor css -->
    <link href="../lib/font-awesome/css/font-awesome.css" rel="stylesheet">
    <link href="../lib/Ionicons/css/ionicons.css" rel="stylesheet">

    <!-- Shamcey CSS -->
    <link rel="stylesheet" href="../css/shamcey.css">
  </head>

  <body class="bg-gray-900">
    <!-- Leftbar -->
    <?php include('includes/leftbar.php'); ?>
    <!-- Header -->
    <?php include('includes/header.php'); ?>

    <div class="signpanel-wrapper" style="background-color: whitesmoke; padding-top: 10%;">
      <div class="signbox signup">
        
        <div class="signbox-body">
            <h2>Registration New Account</h2><br>
            <form method="post" autocomplete="off">
                <?php if (!$force || $requireCurrentWhenForced): ?>
                    <div class="form-group">
                        <label class="form-control-label">Current Password</label>
                        <input type="password" class="form-control" name="password" required>
                    </div>
                    <div class="hr-dashed"></div>
                <?php endif; ?>

                    <div class="form-group">
                        <label class="form-control-label">New Password</label>
                        <input type="password" class="form-control" name="newpassword" required>
                        <small class="text-muted">Minimum 8 characters.</small>
                    </div>
                    <div class="hr-dashed"></div>

                    <div class="form-group">
                        <label class="form-control-label">Confirm Password</label>
                        <input type="password" class="form-control" name="confirmpassword" required>
                    </div>
                    <!-- form-group -->

                <div class="hr-dashed"></div>
                    <button type="submit" class="btn btn-success btn-block" name="submit">Save changes</button>
                    <!-- Popup after submitting an registration -->
                </div>
                </div>
            </form>
    </div>

    <script src="../lib/jquery/jquery.js"></script>
    <script src="../lib/popper.js/popper.js"></script>
    <script src="../lib/bootstrap/bootstrap.js"></script>

    <script src="../js/shamcey.js"></script>
  </body>
</html>
