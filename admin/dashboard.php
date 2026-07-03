<?php
// /Business_only3/admin/app/dashboard.php

require_once __DIR__ . '/includes/session_admin.php';
requireAdminLogin();

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/controller.php';
require_once __DIR__ . '/includes/admin_linked_portal_load.php';

$controller = new Controller();
$dbh = $controller->pdo();

// ✅ Force password change gate (cannot be bypassed by typing dashboard URL)
$adminId = (int)($_SESSION['admin_id'] ?? 0);
if ($adminId <= 0) {
    clearAdminSession();
    header("Location: index.php");
    exit;
}

$stForce = $dbh->prepare("SELECT force_password_change, status FROM admin WHERE idadmin = :id LIMIT 1");
$stForce->execute([':id' => $adminId]);
$acc = $stForce->fetch(PDO::FETCH_ASSOC);

if (!$acc || (int)$acc['status'] !== 1) {
    clearAdminSession();
    header("Location: index.php");
    exit;
}

if ((int)$acc['force_password_change'] === 1) {
    header("Location: change-password.php?force=1");
    exit;
}

// ✅ Admin identity (from session_admin.php login)
$adminLogin = (string)($_SESSION['admin_login'] ?? '');
$adminRole  = (int)($_SESSION['userRole'] ?? 0); // 1 Admin, 2 Manager, 3 Gospel, 4 Staff
$isAdmin    = ($adminRole === 1);

$linkedPortals = admin_linked_portal_summary($dbh, $adminId);
$portalError = isset($_GET['portal_error']) && (string)$_GET['portal_error'] === '1';

function dashboard_h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

// Counts
$userCount     = 0;
$feedbackCount = 0;
$notiCount     = 0;
$deletedCount  = 0;

try {
    if ($isAdmin) {
        $stmt = $dbh->query("SELECT COUNT(*) FROM users");
        $userCount = (int)$stmt->fetchColumn();

        $stmt = $dbh->query("SELECT COUNT(*) FROM deleteduser");
        $deletedCount = (int)$stmt->fetchColumn();
    }

    $stmt = $dbh->prepare("SELECT COUNT(*) FROM feedback_admin WHERE receiver = :r");
    $stmt->execute([':r' => 'Admin']);
    $feedbackCount = (int)$stmt->fetchColumn();

    $stmt = $dbh->prepare("SELECT COUNT(*) FROM notification WHERE notireceiver = :r");
    $stmt->execute([':r' => 'Admin']);
    $notiCount = (int)$stmt->fetchColumn();

} catch (PDOException $e) {
    $error = "DB Error: " . $e->getMessage();
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


    <title>Talentra</title>

    <!-- Vendor css -->
    <link href="../lib/font-awesome/css/font-awesome.css" rel="stylesheet">
    <link href="../lib/Ionicons/css/ionicons.css" rel="stylesheet">
    <link href="../lib/perfect-scrollbar/css/perfect-scrollbar.css" rel="stylesheet">

    <!-- Shamcey CSS -->
    <link rel="stylesheet" href="../css/shamcey.css">
    <?php require_once __DIR__ . '/includes/admin_layout.php'; admin_layout_head_assets(); ?>
  </head>

  <body>
    <!-- Leftbar -->
    <?php include('includes/leftbar.php'); ?>
    <!-- Header -->
    <?php include('includes/header.php'); ?>

    <div class="sh-mainpanel">

      <div class="sh-pagebody">
        <?php if ($portalError): ?>
          <div class="alert alert-warning bd bd-warning mg-b-20" role="alert">
            Could not open that linked account. Try again from the dashboard, or sign in directly on the public or organization login page using your admin credentials.
          </div>
        <?php endif; ?>

        <div class="card bd-primary mg-b-20">
          <div class="card-header bg-primary tx-white d-flex align-items-center justify-content-between">
            <span><i class="icon ion-ios-link mg-r-5"></i> My Linked Accounts</span>
            <span class="badge badge-light tx-primary tx-12">Admin credentials work on all three</span>
          </div>
          <div class="card-body pd-25">
            <p class="mg-b-20 tx-gray-700">
              Open your own personal feed, publisher feed, or organization workspace. The admin panel still manages every user and organization — these shortcuts only sign you into <strong>your</strong> linked accounts.
            </p>
            <div class="row row-xs">
              <div class="col-6 col-sm-4 col-md">
                <a href="<?= dashboard_h((string)$linkedPortals['personal']['url']) ?>" class="shortcut-icon" target="_blank" rel="noopener noreferrer"<?= !empty($linkedPortals['personal']['ready']) ? '' : ' style="opacity:.55;pointer-events:none;"' ?>>
                  <div>
                    <i class="icon ion-ios-person-outline"></i>
                    <span>Personal Feed</span>
                  </div>
                </a>
                <?php if (!empty($linkedPortals['personal']['ready'])): ?>
                  <p class="tx-center tx-12 mg-t-8 mg-b-0 tx-gray-600"><?= dashboard_h((string)$linkedPortals['personal']['username']) ?></p>
                <?php endif; ?>
              </div><!-- col -->
              <div class="col-6 col-sm-4 col-md mg-t-10 mg-sm-t-0">
                <a href="<?= dashboard_h((string)$linkedPortals['publisher']['url']) ?>" class="shortcut-icon" target="_blank" rel="noopener noreferrer"<?= !empty($linkedPortals['publisher']['ready']) ? '' : ' style="opacity:.55;pointer-events:none;"' ?>>
                  <div>
                    <i class="icon ion-ios-paper-outline"></i>
                    <span>Publisher Feed</span>
                  </div>
                </a>
                <?php if (!empty($linkedPortals['publisher']['ready'])): ?>
                  <p class="tx-center tx-12 mg-t-8 mg-b-0 tx-gray-600"><?= dashboard_h((string)$linkedPortals['publisher']['username']) ?></p>
                <?php endif; ?>
              </div><!-- col -->
              <div class="col-6 col-sm-4 col-md mg-t-10 mg-md-t-0">
                <a href="<?= dashboard_h((string)$linkedPortals['organization']['url']) ?>" class="shortcut-icon" target="_blank" rel="noopener noreferrer"<?= !empty($linkedPortals['organization']['ready']) ? '' : ' style="opacity:.55;pointer-events:none;"' ?>>
                  <div>
                    <i class="icon ion-ios-briefcase-outline"></i>
                    <span>Organization</span>
                  </div>
                </a>
                <?php if (!empty($linkedPortals['organization']['ready'])): ?>
                  <p class="tx-center tx-12 mg-t-8 mg-b-0 tx-gray-600"><?= dashboard_h((string)$linkedPortals['organization']['name']) ?></p>
                <?php endif; ?>
              </div><!-- col -->
              <div class="col-6 col-sm-4 col-md mg-t-10 mg-md-t-0">
                <?php if ($isAdmin): ?>
                <a href="userlist.php" class="shortcut-icon" data-admin-nav="1">
                  <div>
                    <i class="icon ion-ios-people-outline"></i>
                    <span>All Users</span>
                  </div>
                </a>
                <p class="tx-center tx-12 mg-t-8 mg-b-0 tx-gray-600">Admin governance</p>
                <?php else: ?>
                <a href="dashboard.php" class="shortcut-icon" style="opacity:.45;pointer-events:none;">
                  <div>
                    <i class="icon ion-ios-locked-outline"></i>
                    <span>Admin Only</span>
                  </div>
                </a>
                <?php endif; ?>
              </div><!-- col -->
              <div class="col-6 col-sm-4 col-md mg-t-10 mg-md-t-0">
                <?php if ($isAdmin): ?>
                <a href="orglist.php" class="shortcut-icon" data-admin-nav="1">
                  <div>
                    <i class="icon ion-ios-briefcase-outline"></i>
                    <span>All Orgs</span>
                  </div>
                </a>
                <p class="tx-center tx-12 mg-t-8 mg-b-0 tx-gray-600">Admin governance</p>
                <?php else: ?>
                <a href="dashboard.php" class="shortcut-icon" style="opacity:.45;pointer-events:none;">
                  <div>
                    <i class="icon ion-ios-locked-outline"></i>
                    <span>Admin Only</span>
                  </div>
                </a>
                <?php endif; ?>
              </div><!-- col -->
            </div><!-- row -->
          </div><!-- card-body -->
        </div><!-- card -->

        <div class="row row-sm">
          <div class="col-lg-4 mg-t-20 mg-lg-t-0">
            <div class="card bd-primary mg-b-20">
              <div class="card-header bg-primary tx-white">Quick Launch</div>
              <div class="card-body pd-20">
                <p class="tx-13 tx-gray-700 mg-b-15">Same admin username and password on every portal.</p>
                <a href="<?= dashboard_h((string)$linkedPortals['personal']['url']) ?>" class="btn btn-outline-primary btn-block mg-b-10" target="_blank" rel="noopener noreferrer"<?= !empty($linkedPortals['personal']['ready']) ? '' : ' disabled' ?>>
                  <i class="icon ion-ios-person-outline mg-r-5"></i> My Personal Feed
                </a>
                <a href="<?= dashboard_h((string)$linkedPortals['publisher']['url']) ?>" class="btn btn-outline-primary btn-block mg-b-10" target="_blank" rel="noopener noreferrer"<?= !empty($linkedPortals['publisher']['ready']) ? '' : ' disabled' ?>>
                  <i class="icon ion-ios-paper-outline mg-r-5"></i> My Publisher Feed
                </a>
                <a href="<?= dashboard_h((string)$linkedPortals['organization']['url']) ?>" class="btn btn-outline-primary btn-block" target="_blank" rel="noopener noreferrer"<?= !empty($linkedPortals['organization']['ready']) ? '' : ' disabled' ?>>
                  <i class="icon ion-ios-briefcase-outline mg-r-5"></i> My Organization
                </a>
              </div><!-- card-body -->
            </div><!-- card -->

            <?php if ($isAdmin): ?>
            <div class="alert alert-primary bd bd-primary pd-25 mg-b-20">
              <h6 class="tx-14 mg-b-15"><i class="icon ion-ios-information-outline mg-r-5"></i> Governance vs. your accounts</h6>
              <p class="mg-b-10 op-8">Use <a href="userlist.php" data-admin-nav="1">User List</a> and <a href="orglist.php" data-admin-nav="1">Organizations</a> to manage everyone. Linked shortcuts above open only your own personal, publisher, and org workspaces.</p>
              <?php if ($userCount > 0): ?>
                <p class="mg-b-0 tx-12 op-8"><?= (int)$userCount ?> registered users · <?= (int)$deletedCount ?> deleted records</p>
              <?php endif; ?>
            </div><!-- alert -->
            <?php else: ?>
            <div class="alert alert-primary bd bd-primary pd-25 mg-b-20">
              <h6 class="tx-14 mg-b-15">Some Announcement</h6>
              <p class="mg-b-0 op-8">Best check yo self, you're not looking too good. Nulla vitae elit libero, a pharetra augue. Praesent commodo cursus magna.</p>
            </div><!-- alert -->
            <?php endif; ?>
          </div><!-- col-4 -->
        </div><!-- row -->
      </div><!-- sh-pagebody -->
      <div class="sh-footer">
        <div>Copyright &copy; 2017. All Rights Reserved. Talentra</div>
        <div class="mg-t-10 mg-md-t-0">Designed by: <a href="http://themepixels.me">ThemePixels</a></div>
      </div><!-- sh-footer -->
    </div><!-- sh-mainpanel -->

    <script src="../lib/jquery/jquery.js"></script>
    <script src="../lib/popper.js/popper.js"></script>
    <script src="../lib/bootstrap/bootstrap.js"></script>
    <script src="../lib/jquery-ui/jquery-ui.js"></script>
    <script src="../lib/perfect-scrollbar/js/perfect-scrollbar.jquery.js"></script>
    <script src="../lib/moment/moment.js"></script>
    <script src="../lib/Flot/jquery.flot.js"></script>
    <script src="../lib/Flot/jquery.flot.resize.js"></script>
    <script src="../lib/flot-spline/jquery.flot.spline.js"></script>

    <script src="../js/shamcey.js"></script>
    <script src="../js/dashboard.js"></script>
  </body>
</html>
