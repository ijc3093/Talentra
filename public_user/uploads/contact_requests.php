<?php
require_once __DIR__ . '/includes/session_user.php';
requireUserLogin();

require_once __DIR__ . '/includes/user_identity.php';
require_once __DIR__ . '/controller.php';
require_once __DIR__ . '/includes/friend_system.php';

$controller = new Controller();
$dbh = $controller->pdo();

$meId = myUserId();
$msg = '';
$error = '';
$ajax = trim((string)($_GET['ajax'] ?? $_POST['ajax'] ?? ''));

function contact_requests_json_response(array $payload): void {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload);
    exit;
}

if ($ajax === 'respond') {
    $reqId = (int)($_POST['id'] ?? $_POST['request_id'] ?? $_POST['friend_request_id'] ?? 0);
    $action = trim((string)($_POST['action'] ?? ''));
    $accept = $action === 'accept' || isset($_POST['accept']);
    $res = $accept
        ? fs_accept_friend_request($dbh, $reqId, $meId)
        : fs_decline_friend_request($dbh, $reqId, $meId);
    contact_requests_json_response($res);
}

if (isset($_POST['accept'])) {
    $reqId = (int)($_POST['id'] ?? 0);
    $res = fs_accept_friend_request($dbh, $reqId, $meId);
    if ($res['ok']) { $msg = (string)$res['message']; } else { $error = (string)$res['message']; }
}

if (isset($_POST['decline'])) {
    $reqId = (int)($_POST['id'] ?? 0);
    $res = fs_decline_friend_request($dbh, $reqId, $meId);
    if ($res['ok']) { $msg = (string)$res['message']; } else { $error = (string)$res['message']; }
}

$stmt = $dbh->prepare("
  SELECT cr.id, cr.from_user_id, cr.created_at, u.name, u.username, u.email, u.friend_code
  FROM contact_requests cr
  JOIN users u ON u.id = cr.from_user_id
  WHERE cr.to_user_id = :me AND cr.status='pending'
  ORDER BY cr.created_at DESC
");
$stmt->execute([':me'=>$meId]);
$requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

function fmt_dt($dt){ return $dt ? date('M d, Y h:i A', strtotime($dt)) : ''; }

if ($ajax === 'list') {
    $items = array_map(static function(array $r): array {
        return [
            'id' => (int)($r['id'] ?? 0),
            'from_user_id' => (int)($r['from_user_id'] ?? 0),
            'display_name' => (string)($r['name'] ?? ''),
            'username' => (string)($r['username'] ?? ''),
            'email' => (string)($r['email'] ?? ''),
            'friend_code' => (string)($r['friend_code'] ?? ''),
            'created_at' => (string)($r['created_at'] ?? ''),
            'time_label' => fmt_dt($r['created_at'] ?? ''),
        ];
    }, $requests);
    contact_requests_json_response(['ok' => true, 'items' => $items, 'count' => count($items)]);
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Friend Requests</title>
  <?php
  require_once __DIR__ . '/includes/theme_prefs.php';
  theme_prefs_print_head_bootstrap($dbh, $meId);
  ?>
  <link rel="stylesheet" href="css/font-awesome.min.css">
  <link rel="stylesheet" href="css/bootstrap.min.css">
  <link rel="stylesheet" href="css/style.css">

  <link href="./lib/font-awesome/css/font-awesome.css" rel="stylesheet">
    <link href="./lib/Ionicons/css/ionicons.css" rel="stylesheet">
    <link href="./lib/perfect-scrollbar/css/perfect-scrollbar.css" rel="stylesheet">
  <link rel="stylesheet" href="./css/shamcey.css">

    <!-- css -->
    <link rel="stylesheet" href="assets/ui_best.css">
    <link rel="stylesheet" href="assets/layout-fixed.css">
    
    <!-- script -->
    <script defer src="assets/layout-fixed.js"></script>
    <script src="./js/shamcey.js"></script>
    <script src="./js/dashboard.js"></script>
    <script src="./lib/jquery/jquery.js"></script>
    <script src="./lib/popper.js/popper.js"></script>
    <script src="./lib/bootstrap/bootstrap.js"></script>
    <script src="./lib/jquery-ui/jquery-ui.js"></script>
    <script src="./lib/perfect-scrollbar/js/perfect-scrollbar.jquery.js"></script>
    <script src="./lib/moment/moment.js"></script>
    <script src="assets/ui_best.js" defer></script>
    <style>
      html, body{
        height:100%;
        overflow:hidden;
      }

      .sh-mainpanel{
        height:80vh;
        display:flex;
        flex-direction:column;
        overflow:hidden;
        margin-left:340px;
        margin-top:20px;
      }

      .sh-pagebody{
        flex:1 1 auto;
        min-height:0;
        overflow:hidden;
        display:flex;
        flex-direction:column;
      }

      .requests-shell{
        flex:1 1 auto;
        min-height:0;
        overflow:hidden;
        display:flex;
        flex-direction:column;
        padding:12px;
        border:1px solid #353333;
        margin:0 10px 5px;
      }

      .requests-fixed{
        flex:0 0 auto;
        position:sticky;
        top:0;
        z-index:5;
        background:inherit;
      }

      .requests-scroll{
        flex:1 1 auto;
        min-height:0;
        overflow:auto;
        -webkit-overflow-scrolling:touch;
      }

      .requests-panel{
        border:1px solid rgba(0,0,0,.08);
        background:#fff;
      }

      .requests-panel .panel-heading{
        font-weight:800;
      }

      .requests-table{
        margin-bottom:0;
      }

      .requests-table thead th{
        position:sticky;
        top:0;
        z-index:4;
        background:#f8fafc;
        border-bottom:1px solid rgba(0,0,0,.08);
        text-transform:uppercase;
        font-size:12px;
        letter-spacing:.02em;
      }

      .requests-table-wrap{
        overflow:visible;
      }

      body.contact-requests-page #globalLiveModal:not(.is-open){
        display:none !important;
        visibility:hidden !important;
        opacity:0 !important;
        pointer-events:none !important;
      }

      body.contact-requests-page #globalLiveModal:not(.is-open) .global-live-modal-dialog,
      body.contact-requests-page #globalLiveModal:not(.is-open) iframe,
      body.contact-requests-page #globalLiveModal:not(.is-open) video,
      body.contact-requests-page #globalLiveModal:not(.is-open) img,
      body.contact-requests-page #globalLiveModal:not(.is-open) aside{
        display:none !important;
      }

      @media (max-width: 991.98px){
        html, body{
          height:auto !important;
          overflow:auto !important;
        }

        .sh-mainpanel{
          margin-left:0 !important;
          margin-top:0 !important;
          height:auto !important;
          min-height:100vh;
        }

        .sh-pagebody{
          overflow:visible !important;
        }

        .requests-shell{
          height:auto !important;
        }
      }
    </style>
</head>
<body class="contact-requests-page">

<?php $forceFeedRail = true; $skipHeaderThemeBootstrap = true; include __DIR__ . '/includes/header.php'; ?>

<div class="sh-mainpanel">
  <div class="sh-pagebody">
    <div class="requests-shell">
      <div class="requests-fixed">
        <div class="container-fluid pd-20">
          <h2 class="page-title">Friend Requests</h2>

          <?php if ($error): ?><div class="alert alert-danger"><?php echo htmlentities($error); ?></div><?php endif; ?>
          <?php if ($msg): ?><div class="alert alert-success"><?php echo htmlentities($msg); ?></div><?php endif; ?>

          <div class="panel panel-default requests-panel">
            <div class="panel-heading">Pending Friend Requests</div>
          </div>
        </div>
      </div>

      <div class="requests-scroll">
        <div class="container-fluid pd-20 pt-0">
          <div class="panel panel-default requests-panel">
            <div class="panel-body">
              <?php if (empty($requests)): ?>
                <div class="alert alert-info">No pending friend requests.</div>
              <?php else: ?>
                <div class="requests-table-wrap">
                  <table class="table table-striped table-bordered requests-table">
                    <thead>
                      <tr>
                        <th>From</th>
                        <th>Username</th>
                        <th>Email</th>
                        <th>Time</th>
                        <th style="width:200px;">Action</th>
                      </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($requests as $r): ?>
                      <tr>
                        <td><?php echo htmlentities($r['name'] ?? ''); ?></td>
                        <td><?php echo htmlentities($r['username'] ?? ''); ?></td>
                        <td><?php echo htmlentities($r['email'] ?? ''); ?></td>
                        <td><?php echo htmlentities(fmt_dt($r['created_at'])); ?></td>
                        <td>
                          <form method="post" style="display:inline;">
                            <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                            <button class="btn btn-success btn-xs" name="accept" type="submit">
                              <i class="fa fa-check"></i> Accept
                            </button>
                          </form>
                          <form method="post" style="display:inline;">
                            <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                            <button class="btn btn-danger btn-xs" name="decline" type="submit">
                              <i class="fa fa-times"></i> Decline
                            </button>
                          </form>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>
    </div>

    </div>
  </div>
</div>

<script src="js/jquery.min.js"></script>
<script src="js/bootstrap.min.js"></script>
<script>
setTimeout(function(){ $('.alert-success,.alert-danger').fadeOut(); }, 2500);
</script>

<!-- <?php include __DIR__ . '/includes/footer.php'; ?> -->
</body>
</html>
