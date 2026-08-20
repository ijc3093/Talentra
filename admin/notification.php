<?php
/**
 * admin/notification.php
 * Admin notifications — viewport-fit UI matching other admin list pages.
 */
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
 * - Admin (role 1)  => Admin + Manager + Gospel + Staff
 * - Manager (role 2)=> Manager only
 * - Gospel (role 3) => Gospel only
 * - Staff (role 4)  => Staff only
 */
$receiverKeys = myNotificationReceiverKeys();
$allowedReceivers = ['Admin', 'Manager', 'Gospel', 'Staff'];
$receiverKeys = array_values(array_intersect((array)$receiverKeys, $allowedReceivers));

if (empty($receiverKeys)) {
    die('Invalid session receiver keys.');
}

$filter = strtolower(trim((string)($_GET['filter'] ?? 'all')));
if (!in_array($filter, ['all', 'unread', 'read'], true)) {
    $filter = 'all';
}

$whereRead = '';
if ($filter === 'unread') {
    $whereRead = ' AND is_read = 0 ';
}
if ($filter === 'read') {
    $whereRead = ' AND is_read = 1 ';
}

$ph = implode(',', array_fill(0, count($receiverKeys), '?'));

if (isset($_GET['del'])) {
    $id = (int)($_GET['del'] ?? 0);
    if ($id > 0) {
        $stmt = $dbh->prepare("DELETE FROM notification WHERE id = ? AND notireceiver IN ($ph)");
        $stmt->execute(array_merge([$id], $receiverKeys));
        $msg = 'Notification deleted.';
    }
}

if (isset($_POST['delete_all'])) {
    $stmt = $dbh->prepare("DELETE FROM notification WHERE notireceiver IN ($ph)");
    $stmt->execute($receiverKeys);
    $msg = 'All notifications deleted.';
}

$stmtC = $dbh->prepare("SELECT COUNT(*) FROM notification WHERE notireceiver IN ($ph) AND is_read = 0");
$stmtC->execute($receiverKeys);
$unreadCount = (int)$stmtC->fetchColumn();

$stmtT = $dbh->prepare("SELECT COUNT(*) FROM notification WHERE notireceiver IN ($ph)");
$stmtT->execute($receiverKeys);
$totalCount = (int)$stmtT->fetchColumn();
$readCount = max(0, $totalCount - $unreadCount);

$stmt = $dbh->prepare("
    SELECT id, notiuser, notireceiver, notitype, created_at, is_read
    FROM notification
    WHERE notireceiver IN ($ph)
    $whereRead
    ORDER BY created_at DESC
");
$stmt->execute($receiverKeys);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

function fmt_dt($dt): string
{
    return $dt ? date('M j, Y g:i A', strtotime((string)$dt)) : 'N/A';
}
function h($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

$visibleCount = count($rows);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
  <title>Notifications</title>
  <link href="../lib/font-awesome/css/font-awesome.css" rel="stylesheet">
  <link href="../lib/Ionicons/css/ionicons.css" rel="stylesheet">
  <link href="../lib/perfect-scrollbar/css/perfect-scrollbar.css" rel="stylesheet">
  <link href="../lib/datatables/jquery.dataTables.css" rel="stylesheet">
  <link href="../lib/select2/css/select2.min.css" rel="stylesheet">
  <link rel="stylesheet" href="../css/shamcey.css">
  <link rel="stylesheet" href="css/admin-tables-shamcey.css?v=8">
  <style>
    html,body{height:100%;overflow:hidden;}
    .sh-mainpanel{height:100vh;display:flex;flex-direction:column;overflow:hidden;}
    .sh-mainpanel > .sh-pagebody{
      overflow:hidden !important;display:flex !important;flex-direction:column !important;min-height:0 !important;
      padding-top:8px !important;padding-bottom:8px !important;flex:1 1 auto;background:#f4f6fb;
    }
    .nt-wrap{
      flex:1 1 auto;min-height:0;width:100%;max-width:100%;
      display:flex;flex-direction:column;gap:8px;overflow:hidden;padding:0 2px;box-sizing:border-box;
    }
    .nt-top{flex:0 0 auto;display:flex;align-items:center;justify-content:flex-end;gap:10px;min-width:0;}
    .nt-actions{display:flex;gap:6px;flex-wrap:wrap;align-items:center;}
    .nt-btn{
      height:30px;padding:0 10px;border-radius:8px;border:1px solid #e2e8f0;background:#fff;
      font-size:11px;font-weight:700;color:#334155;display:inline-flex;align-items:center;gap:5px;
      text-decoration:none;cursor:pointer;white-space:nowrap;
    }
    .nt-btn:hover{background:#f8fafc;text-decoration:none;color:#0f172a;}
    .nt-btn.primary{background:#2563eb;border-color:#2563eb;color:#fff;}
    .nt-btn.primary:hover{background:#1d4ed8;color:#fff;}
    .nt-btn.danger{background:#fff;border-color:#fecaca;color:#b91c1c;}
    .nt-btn.danger:hover{background:#fef2f2;}
    .nt-btn:disabled{opacity:.45;pointer-events:none;}

    .nt-cards{
      flex:0 0 auto;display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px;
    }
    .nt-card{
      background:#fff;border:1px solid #eef2f7;border-radius:12px;padding:10px 12px;
      box-shadow:0 1px 2px rgba(15,23,42,.04);min-width:0;text-decoration:none;color:inherit;
      transition:border-color .15s, box-shadow .15s;
    }
    .nt-card:hover{border-color:#bfdbfe;text-decoration:none;color:inherit;}
    .nt-card.is-active{border-color:#2563eb;box-shadow:0 0 0 1px #2563eb inset;}
    .nt-card-top{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:6px;}
    .nt-card-top .lab{font-size:11px;font-weight:700;color:#64748b;}
    .nt-card-top .delta{font-size:10px;font-weight:800;color:#94a3b8;}
    .nt-ico{
      width:28px;height:28px;border-radius:999px;display:flex;align-items:center;justify-content:center;font-size:12px;flex:0 0 auto;
    }
    .nt-ico.purple{background:#f5f3ff;color:#7c3aed;}
    .nt-ico.orange{background:#fff7ed;color:#ea580c;}
    .nt-ico.green{background:#f0fdf4;color:#16a34a;}
    .nt-card .val{font-size:20px;font-weight:800;color:#0f172a;line-height:1;}
    .nt-card .sub{font-size:10px;color:#94a3b8;font-weight:600;margin-top:4px;}

    .nt-kinds{
      flex:0 0 auto;display:flex;gap:0;background:#fff;border:1px solid #eef2f7;border-radius:10px;
      padding:0 4px;overflow:hidden;min-width:0;
    }
    .nt-kinds a{
      flex:0 0 auto;padding:8px 14px;font-size:12px;font-weight:800;color:#64748b;text-decoration:none;
      border-bottom:2px solid transparent;white-space:nowrap;
    }
    .nt-kinds a .cnt{font-weight:700;color:#94a3b8;margin-left:4px;}
    .nt-kinds a.is-active{color:#2563eb;border-bottom-color:#2563eb;}
    .nt-kinds a:hover{color:#0f172a;text-decoration:none;}

    .nt-main{
      flex:1 1 auto;min-height:0;min-width:0;
      background:#fff;border:1px solid #eef2f7;border-radius:12px;overflow:hidden;
      box-shadow:0 1px 2px rgba(15,23,42,.04);
      display:flex;flex-direction:column;
    }
    .nt-filters{
      flex:0 0 auto;display:flex;gap:6px;flex-wrap:wrap;align-items:center;
      padding:10px 12px;border-bottom:1px solid #eef2f7;background:#fafbfc;
    }
    .nt-search{position:relative;flex:1 1 180px;min-width:140px;max-width:280px;}
    .nt-search i{position:absolute;left:9px;top:50%;transform:translateY(-50%);color:#94a3b8;font-size:12px;}
    .nt-search input{
      height:30px;width:100%;border:1px solid #e2e8f0;border-radius:8px;padding:0 9px 0 28px;
      font-size:11px;background:#fff;color:#0f172a;
    }

    .nt-table-wrap{flex:1 1 auto;min-height:0;overflow-x:hidden;overflow-y:auto;overscroll-behavior:contain;}
    .nt-table{width:100%;table-layout:fixed;border-collapse:separate;border-spacing:0;min-width:0;}
    .nt-table th{
      text-align:left;font-size:9px;font-weight:800;letter-spacing:.03em;text-transform:uppercase;
      color:#64748b;padding:8px 10px;border-bottom:1px solid #eef2f7;background:#fff;
      position:sticky;top:0;z-index:3;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;
    }
    .nt-table td{
      padding:10px;border-bottom:1px solid #f1f5f9;vertical-align:middle;font-size:12px;color:#0f172a;overflow:hidden;
    }
    .nt-table tr:hover td{background:#f8fafc;}
    .nt-table tr.is-unread td{background:#f8fbff;}
    .nt-table tr.is-unread:hover td{background:#eff6ff;}
    .nt-table th:nth-child(1),.nt-table td:nth-child(1){width:40px;}
    .nt-table th:nth-child(2),.nt-table td:nth-child(2){width:18%;}
    .nt-table th:nth-child(3),.nt-table td:nth-child(3){width:28%;}
    .nt-table th:nth-child(4),.nt-table td:nth-child(4){width:90px;}
    .nt-table th:nth-child(5),.nt-table td:nth-child(5){width:140px;}
    .nt-table th:nth-child(6),.nt-table td:nth-child(6){width:90px;}
    .nt-table th:nth-child(7),.nt-table td:nth-child(7){width:88px;}

    .nt-from{font-weight:700;color:#0f172a;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
    .nt-type{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:#334155;}
    .nt-to,.nt-when{font-size:11px;color:#475569;white-space:nowrap;}
    .nt-status{
      display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:999px;
      font-size:10px;font-weight:800;white-space:nowrap;
    }
    .nt-status.unread{background:#fff7ed;color:#c2410c;}
    .nt-status.read{background:#dcfce7;color:#15803d;}
    .nt-status .dot{width:6px;height:6px;border-radius:999px;background:currentColor;}
    .nt-acts{display:flex;align-items:center;gap:6px;}
    .nt-act{
      width:28px;height:28px;border-radius:8px;border:1px solid #e2e8f0;background:#fff;
      display:inline-flex;align-items:center;justify-content:center;color:#64748b;text-decoration:none;
    }
    .nt-act:hover{background:#f8fafc;text-decoration:none;}
    .nt-act.ok{color:#16a34a;border-color:#bbf7d0;}
    .nt-act.ok:hover{background:#f0fdf4;color:#15803d;}
    .nt-act.danger{color:#dc2626;border-color:#fecaca;}
    .nt-act.danger:hover{background:#fef2f2;color:#b91c1c;}
    .nt-empty{padding:36px 16px;text-align:center;color:#94a3b8;font-size:13px;font-weight:600;}

    .nt-alert{flex:0 0 auto;padding:7px 9px;border-radius:8px;font-size:12px;font-weight:700;}
    .nt-alert.ok{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0;}
    .nt-alert.bad{background:#fef2f2;color:#991b1b;border:1px solid #fecaca;}
    .nt-foot{
      flex:0 0 auto;display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;
      padding:10px 12px;border-top:1px solid #eef2f7;background:#fff;
    }
    .nt-foot .muted{font-size:11px;color:#64748b;font-weight:600;}
    .dataTables_wrapper .dataTables_paginate{float:none;text-align:center;padding:0;}
    .dataTables_wrapper .dataTables_paginate .paginate_button{
      min-width:28px !important;height:28px !important;padding:0 7px !important;margin:0 2px !important;
      border-radius:7px !important;border:1px solid #e2e8f0 !important;background:#fff !important;
      font-size:11px !important;font-weight:700 !important;line-height:26px !important;box-sizing:border-box;
    }
    .dataTables_wrapper .dataTables_paginate .paginate_button.current{
      background:#2563eb !important;border-color:#2563eb !important;color:#fff !important;
    }
    .dataTables_wrapper .dataTables_info{display:none;}
    .dataTables_wrapper .dataTables_length,.dataTables_wrapper .dataTables_filter{display:none !important;}
    .dataTables_wrapper .top,.dataTables_wrapper .bottom{display:none;}
    #ntTable_wrapper{display:contents;}
    #ntTable{width:100% !important;margin:0 !important;}
    @media (max-width:900px){
      .nt-wrap{overflow:auto;}
      .nt-cards{grid-template-columns:1fr;}
    }
  </style>
</head>
<body>
<?php
$adminChromePageIntro = [
    'title' => 'Notifications',
    'description' => 'Account and system alerts for your admin workspace.',
];
include __DIR__ . '/includes/leftbar.php';
include __DIR__ . '/includes/header.php';
?>

<div class="sh-mainpanel">
  <div class="sh-pagebody">
    <div class="nt-wrap">

      <?php if ($error !== ''): ?>
        <div class="nt-alert bad"><?= h($error) ?></div>
      <?php elseif ($msg !== ''): ?>
        <div class="nt-alert ok"><?= h($msg) ?></div>
      <?php endif; ?>

      <div class="nt-top">
        <div class="nt-actions">
          <button class="nt-btn primary" id="btnMarkAll" type="button" <?= $unreadCount <= 0 ? 'disabled' : '' ?>>
            <i class="fa fa-check"></i> Mark All Read
          </button>
          <form method="post" style="display:inline;margin:0;">
            <button class="nt-btn danger" type="submit" name="delete_all"
              onclick="return confirm('Delete ALL notifications you can see?');"
              <?= $totalCount <= 0 ? 'disabled' : '' ?>>
              <i class="fa fa-trash"></i> Delete All
            </button>
          </form>
        </div>
      </div>

      <div class="nt-cards">
        <a class="nt-card<?= $filter === 'all' ? ' is-active' : '' ?>" href="notification.php?filter=all">
          <div class="nt-card-top">
            <div style="display:flex;align-items:center;gap:8px;">
              <div class="nt-ico purple"><i class="fa fa-bell"></i></div>
              <div class="lab">All</div>
            </div>
            <div class="delta">• list</div>
          </div>
          <div class="val"><?= number_format($totalCount) ?></div>
          <div class="sub">All notifications</div>
        </a>
        <a class="nt-card<?= $filter === 'unread' ? ' is-active' : '' ?>" href="notification.php?filter=unread">
          <div class="nt-card-top">
            <div style="display:flex;align-items:center;gap:8px;">
              <div class="nt-ico orange"><i class="fa fa-envelope"></i></div>
              <div class="lab">Unread</div>
            </div>
            <div class="delta">• pending</div>
          </div>
          <div class="val"><?= number_format($unreadCount) ?></div>
          <div class="sub">Need attention</div>
        </a>
        <a class="nt-card<?= $filter === 'read' ? ' is-active' : '' ?>" href="notification.php?filter=read">
          <div class="nt-card-top">
            <div style="display:flex;align-items:center;gap:8px;">
              <div class="nt-ico green"><i class="fa fa-check-circle"></i></div>
              <div class="lab">Read</div>
            </div>
            <div class="delta">• done</div>
          </div>
          <div class="val"><?= number_format($readCount) ?></div>
          <div class="sub">Already seen</div>
        </a>
      </div>

      <nav class="nt-kinds" aria-label="Notification filter">
        <a href="notification.php?filter=all" class="<?= $filter === 'all' ? 'is-active' : '' ?>">All<span class="cnt"><?= (int)$totalCount ?></span></a>
        <a href="notification.php?filter=unread" class="<?= $filter === 'unread' ? 'is-active' : '' ?>">Unread<span class="cnt"><?= (int)$unreadCount ?></span></a>
        <a href="notification.php?filter=read" class="<?= $filter === 'read' ? 'is-active' : '' ?>">Read<span class="cnt"><?= (int)$readCount ?></span></a>
      </nav>

      <div class="nt-main">
        <div class="nt-filters">
          <div class="nt-search">
            <i class="fa fa-search" aria-hidden="true"></i>
            <input type="search" id="ntSearch" placeholder="Search notifications..." aria-label="Search notifications">
          </div>
        </div>

        <div class="nt-table-wrap">
          <table id="ntTable" class="nt-table display">
            <thead>
              <tr>
                <th>#</th>
                <th>From</th>
                <th>Notification</th>
                <th>To</th>
                <th>Date &amp; Time</th>
                <th>Status</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
              <tr>
                <td colspan="7"><div class="nt-empty">No notifications in this view.</div></td>
              </tr>
            <?php else: ?>
              <?php $i = 1; foreach ($rows as $r): ?>
                <?php $isUnread = (int)($r['is_read'] ?? 0) === 0; ?>
                <tr class="<?= $isUnread ? 'is-unread' : '' ?>">
                  <td><?= $i++ ?></td>
                  <td><div class="nt-from" title="<?= h($r['notiuser'] ?? '') ?>"><?= h($r['notiuser'] ?? '') ?></div></td>
                  <td><div class="nt-type" title="<?= h($r['notitype'] ?? '') ?>"><?= h($r['notitype'] ?? '') ?></div></td>
                  <td><span class="nt-to"><?= h($r['notireceiver'] ?? '') ?></span></td>
                  <td><span class="nt-when"><?= h(fmt_dt($r['created_at'] ?? null)) ?></span></td>
                  <td>
                    <?php if ($isUnread): ?>
                      <span class="nt-status unread"><span class="dot"></span>Unread</span>
                    <?php else: ?>
                      <span class="nt-status read"><span class="dot"></span>Read</span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <div class="nt-acts">
                      <?php if ($isUnread): ?>
                        <a href="#" class="nt-act ok markReadBtn" data-id="<?= (int)$r['id'] ?>" title="Mark read" aria-label="Mark read">
                          <i class="fa fa-check"></i>
                        </a>
                      <?php endif; ?>
                      <a class="nt-act danger"
                         href="notification.php?filter=<?= rawurlencode($filter) ?>&amp;del=<?= (int)$r['id'] ?>"
                         onclick="return confirm('Delete this notification?');"
                         title="Delete" aria-label="Delete">
                        <i class="fa fa-trash"></i>
                      </a>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
          </table>
        </div>

        <div class="nt-foot">
          <div class="muted">Showing <?= number_format($visibleCount) ?> of <?= number_format($totalCount) ?> notifications</div>
          <div id="ntPager"></div>
        </div>
      </div>

    </div>
  </div>
</div>

<script src="../lib/jquery/jquery.js"></script>
<script src="../lib/popper.js/popper.js"></script>
<script src="../lib/bootstrap/bootstrap.js"></script>
<script src="../lib/perfect-scrollbar/js/perfect-scrollbar.jquery.js"></script>
<script src="../lib/datatables/jquery.dataTables.js"></script>
<script src="../lib/datatables-responsive/dataTables.responsive.js"></script>
<script src="../lib/select2/js/select2.min.js"></script>
<script src="../js/shamcey.js"></script>
<script>
$(function () {
  var hasRows = <?= $rows ? 'true' : 'false' ?>;
  var dt = null;
  if (hasRows) {
    dt = $('#ntTable').DataTable({
      pageLength: 25,
      lengthMenu: [[10, 25, 50, 100], [10, 25, 50, 100]],
      order: [[4, 'desc']],
      pagingType: 'simple_numbers',
      dom: 't<"nt-dt-foot"p>',
      language: { paginate: { previous: '‹', next: '›' } }
    });
    $('#ntPager').append($('#ntTable_paginate'));
  }

  $('#ntSearch').on('input', function () {
    if (dt) dt.search(this.value).draw();
  });

  $(document).on('click', '.markReadBtn', function (e) {
    e.preventDefault();
    var id = $(this).data('id');
    if (!confirm('Mark this notification as read?')) return;
    $.post('ajax/admin_mark_notification_read.php', { id: id }, function (resp) {
      if (resp && resp.ok) location.reload();
      else alert((resp && resp.error) || 'Failed');
    }, 'json').fail(function () {
      alert('Request failed');
    });
  });

  $('#btnMarkAll').on('click', function () {
    if (!confirm('Mark ALL notifications you can see as read?')) return;
    $.post('ajax/admin_mark_all_notifications_read.php', {}, function (resp) {
      if (resp && resp.ok) location.reload();
      else alert((resp && resp.error) || 'Failed');
    }, 'json').fail(function () {
      alert('Request failed');
    });
  });

  setTimeout(function () {
    $('.nt-alert').each(function () {
      var el = this;
      el.style.transition = 'opacity 0.4s ease';
      el.style.opacity = '0';
      setTimeout(function () { el.remove(); }, 400);
    });
  }, 2500);
});
</script>
</body>
</html>
