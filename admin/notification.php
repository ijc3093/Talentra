<?php
// /admin/notification.php

require_once __DIR__ . '/includes/session_admin.php';
requireAdminLogin();

require_once __DIR__ . '/includes/identity.php';
require_once __DIR__ . '/controller.php';

error_reporting(E_ALL);
ini_set('display_errors', '1');

$controller = new Controller();
$dbh = $controller->pdo();

$msg = '';
$error = '';

/**
 * Receiver keys based on role:
 * - Admin (role 1)  => can see Admin + Manager + Gospel + Staff notifications
 * - Manager (role 2)=> can see Manager notifications only
 * - Gospel (role 3) => can see Gospel notifications only
 * - Staff (role 4)  => can see Staff notifications only
 */
$receiverKeys = myNotificationReceiverKeys();

/**
 * Hard safety: allow only known receiver labels
 */
$allowedReceivers = ['Admin', 'Manager', 'Gospel', 'Staff'];
$receiverKeys = array_values(array_intersect((array)$receiverKeys, $allowedReceivers));

if (empty($receiverKeys)) {
    die("Invalid session receiver keys.");
}

// FILTER
$filter = $_GET['filter'] ?? 'all'; // all | unread | read
$filter = in_array($filter, ['all','unread','read'], true) ? $filter : 'all';

$whereRead = "";
if ($filter === 'unread') $whereRead = " AND is_read = 0 ";
if ($filter === 'read')   $whereRead = " AND is_read = 1 ";

// build IN (?, ?, ?)
$ph = implode(',', array_fill(0, count($receiverKeys), '?'));

// DELETE ONE (only if it belongs to allowed receivers)
if (isset($_GET['del'])) {
    $id = (int)($_GET['del'] ?? 0);
    if ($id > 0) {
        $stmt = $dbh->prepare("DELETE FROM notification WHERE id = ? AND notireceiver IN ($ph)");
        $stmt->execute(array_merge([$id], $receiverKeys));
        $msg = "Notification deleted.";
    }
}

// DELETE ALL (only for allowed receivers)
if (isset($_POST['delete_all'])) {
    $stmt = $dbh->prepare("DELETE FROM notification WHERE notireceiver IN ($ph)");
    $stmt->execute($receiverKeys);
    $msg = "All notifications deleted.";
}

// LOAD COUNTS (unread badge)
$stmtC = $dbh->prepare("SELECT COUNT(*) FROM notification WHERE notireceiver IN ($ph) AND is_read = 0");
$stmtC->execute($receiverKeys);
$unreadCount = (int)$stmtC->fetchColumn();

// LOAD NOTIFICATIONS
$stmt = $dbh->prepare("
    SELECT id, notiuser, notireceiver, notitype, created_at, is_read
    FROM notification
    WHERE notireceiver IN ($ph)
    $whereRead
    ORDER BY created_at DESC
");
$stmt->execute($receiverKeys);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

function fmt_dt($dt) {
    return $dt ? date('M d, Y h:i A', strtotime($dt)) : 'N/A';
}
function h($s): string {
    return htmlentities((string)$s);
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


    <title>List Roles & Accounts - All Admin-side Accounts</title>

    <!-- vendor css -->
    <link href="../lib/font-awesome/css/font-awesome.css" rel="stylesheet">
    <link href="../lib/Ionicons/css/ionicons.css" rel="stylesheet">
    <link href="../lib/perfect-scrollbar/css/perfect-scrollbar.css" rel="stylesheet">
    <link href="../lib/datatables/jquery.dataTables.css" rel="stylesheet">
    <link href="../lib/select2/css/select2.min.css" rel="stylesheet">

    <!-- Shamcey CSS -->
    <link rel="stylesheet" href="../css/shamcey.css">
  </head>

  <body>

    <!-- Leftbar -->
    <?php include('includes/leftbar.php'); ?>
    <!-- Header -->
    <?php include('includes/header.php'); ?>
    
    <div class="sh-mainpanel">
      <!-- <div class="sh-breadcrumb">
        <nav class="breadcrumb">
          <a class="breadcrumb-item" href="index.html">Talentra</a>
          <a class="breadcrumb-item" href="index.html">Tables</a>
          <span class="breadcrumb-item active">Data Table</span>
        </nav>
      </div> -->
      <!-- sh-breadcrumb -->
      <div class="sh-pagetitle">
        <div class="input-group">
          <!-- <input type="search" class="form-control" placeholder="Search">
          <span class="input-group-btn">
            <button class="btn"><i class="fa fa-search"></i></button>
          </span> -->
          <!-- input-group-btn -->
        </div><!-- input-group -->
        <div class="sh-pagetitle-left">
          <div class="sh-pagetitle-icon"><i class="icon ion-ios-list"></i></div>
          <div class="sh-pagetitle-title">
            <!-- <span>Table Styles</span> -->
            <h2>Notification List</h2>
          </div><!-- sh-pagetitle-left-title -->
        </div><!-- sh-pagetitle-left -->
      </div><!-- sh-pagetitle -->

      <div class="sh-pagebody">
        

        <div class="card bd-primary">
          <div class="card-header bg-primary tx-white">List Roles & Accounts - All Admin-side Accounts</div>
          <div class="card-body pd-sm-30">
            
                <div class="row">
                <div class="col-lg-5" style="margin-right: 37%;">
                    <div class="btn-group" role="group" aria-label="Basic example">
                    
                    <a class="btn btn-secondary pd-x-25 active" href="notification.php?filter=all">All</a>
                    <a class="btn btn-secondary pd-x-25" href="notification.php?filter=unread">
                            Unread
                            <?php if ($unreadCount > 0): ?>
                            <span class="badge-red"><?php echo (int)$unreadCount; ?></span>
                            <?php endif; ?>
                        </a>
                        <a class="btn btn-secondary pd-x-25" href="notification.php?filter=read">Read</a>
                    </div>
                </div><!-- col-7 -->

                <!-- Mark All Read & Delete All Operation -->
                <div class="btn-group">
                        <div>
                            <button class="btn btn-secondary" id="btnMarkAll" type="button" <?php echo empty($rows) ? 'disabled' : ''; ?>>
                                <i class="fa fa-check"></i> Mark All Read
                            </button>

                            <form method="post" style="display:inline;">
                                <button class="btn btn-secondary" style="background-color:red;" type="submit" name="delete_all"
                                    onclick="return confirm('Delete ALL notifications you can see?');"
                                    <?php echo empty($rows) ? 'disabled' : ''; ?>>
                                    <i class="fa fa-trash"></i> Delete All
                                </button>
                            </form>
                        </div>
                    </div>
                </div><!-- row -->
                <br>
                <div class="table-wrapper">
                <table id="datatable1" class="table display responsive nowrap">
                    <thead>
                    <tr>
                        <th>#</th>
                        <th>From</th>
                        <th>Notification</th>
                        <th>To</th>
                        <th>Date &amp; Time</th>
                        <th>Status</th>
                        <th style="width:110px;">Action</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php $i=1; foreach ($rows as $r): ?>
                        <tr class="<?php echo ((int)$r['is_read'] === 0) ? 'unread' : ''; ?>">
                        <td><?php echo $i++; ?></td>
                        <td><?php echo h($r['notiuser'] ?? ''); ?></td>
                        <td><?php echo h($r['notitype'] ?? ''); ?></td>
                        <td><?php echo h($r['notireceiver'] ?? ''); ?></td>
                        <td><?php echo h(fmt_dt($r['created_at'] ?? null)); ?></td>
                        <td>
                            <?php if ((int)$r['is_read'] === 1): ?>
                            <span class="label label-success">Read</span>
                            <?php else: ?>
                            <span class="label label-warning">Unread</span>
                            <?php endif; ?>
                        </td>
                        <td class="action-icons">
                            <?php if ((int)$r['is_read'] === 0): ?>
                            <a href="#" class="markReadBtn" data-id="<?php echo (int)$r['id']; ?>" title="Mark Read">
                                <i class="fa fa-check text-success"></i>
                            </a>
                            <?php endif; ?>

                            <a href="notification.php?filter=<?php echo urlencode($filter); ?>&del=<?php echo (int)$r['id']; ?>"
                            onclick="return confirm('Delete this notification?');" title="Delete">
                            <i class="fa fa-trash text-danger"></i>
                            </a>
                        </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div><!-- table-wrapper -->
          </div><!-- card-body -->
        </div><!-- card -->
      </div><!-- sh-pagebody -->
      <div class="sh-footer">
        <div>Copyright &copy; 2017. All Rights Reserved. Talentra</div>
        <div class="mg-t-10 mg-md-t-0">Designed by: <a href="http://themepixels.me">ThemePixels</a></div>
      </div><!-- sh-footer -->
    </div><!-- sh-mainpanel -->

    <script src="../lib/jquery/jquery.js"></script>
    <script src="../lib/popper.js/popper.js"></script>
    <script src="../lib/bootstrap/bootstrap.js"></script>
    <script src="../lib/perfect-scrollbar/js/perfect-scrollbar.jquery.js"></script>
    <script src="../lib/datatables/jquery.dataTables.js"></script>
    <script src="../lib/datatables-responsive/dataTables.responsive.js"></script>
    <script src="../lib/select2/js/select2.min.js"></script>

    <script src="../js/shamcey.js"></script>
    <script>
    $(function(){

    // ✅ DataTables scrolling body only (header + search stay fixed)
    const dt = $('#zctb').DataTable({
        pageLength: 25,
        lengthMenu: [[10, 25, 50, 100], [10, 25, 50, 100]],
        order: [[4, 'desc']], // sort by Date & Time column (index 4)
        scrollY: '55vh',      // ✅ body scroll height (change if you want)
        scrollCollapse: true
    });

    // Mark ONE read
    $(document).on('click', '.markReadBtn', function(e){
        e.preventDefault();
        const id = $(this).data('id');
        if (!confirm('Mark this notification as read?')) return;

        $.post('ajax/admin_mark_notification_read.php', { id: id }, function(resp){
        if (resp && resp.ok) location.reload();
        else alert(resp.error || 'Failed');
        }, 'json').fail(function(){
        alert('Request failed');
        });
    });

    // Mark ALL read
    $('#btnMarkAll').on('click', function(){
        if (!confirm('Mark ALL notifications you can see as read?')) return;

        $.post('ajax/admin_mark_all_notifications_read.php', {}, function(resp){
        if (resp && resp.ok) location.reload();
        else alert(resp.error || 'Failed');
        }, 'json').fail(function(){
        alert('Request failed');
        });
    });

    // Auto-hide messages
    setTimeout(function () {
        const success = document.querySelector('.succWrap');
        const error   = document.querySelector('.errorWrap');

        if (success) {
        success.style.transition = 'opacity 0.4s ease';
        success.style.opacity = '0';
        setTimeout(() => success.remove(), 400);
        }
        if (error) {
        error.style.transition = 'opacity 0.4s ease';
        error.style.opacity = '0';
        setTimeout(() => error.remove(), 400);
        }
    }, 2500);

    });
    </script>
</body>
</html>