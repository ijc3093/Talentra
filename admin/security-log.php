<?php
// /Business_only3/admin/security_logs.php
declare(strict_types=1);

require_once __DIR__ . '/includes/session_admin.php';
requireAdminLogin();

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/controller.php';

$controller = new Controller();
$dbh = $controller->pdo();

$msg = '';
$error = '';

// (Optional) show flash messages if you use them
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <!-- Required meta tags -->
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">

  <title>Security Audit Logs</title>

  <!-- vendor css -->
  <link href="../lib/font-awesome/css/font-awesome.css" rel="stylesheet">
  <link href="../lib/Ionicons/css/ionicons.css" rel="stylesheet">
  <link href="../lib/perfect-scrollbar/css/perfect-scrollbar.css" rel="stylesheet">
  <link href="../lib/datatables/jquery.dataTables.css" rel="stylesheet">
  <link href="../lib/datatables-responsive/dataTables.responsive.css" rel="stylesheet">
  <link href="../lib/select2/css/select2.min.css" rel="stylesheet">

  <!-- Shamcey CSS -->
  <link rel="stylesheet" href="../css/shamcey.css">

  <style>
    /* ✅ FIXED PAGE like settings.php: only internal areas scroll */
    html, body { height:100%; overflow:hidden; }

    .sh-mainpanel{
      height:100vh;
      display:flex;
      flex-direction:column;
      overflow:hidden;
    }

    .sh-pagetitle{ flex:0 0 auto; }

    .sh-pagebody{
      flex:1 1 auto;
      min-height:0;
      overflow:hidden;
      display:flex;
      flex-direction:column;
    }

    .fixed-card{
      flex:1 1 auto;
      min-height:0;
      overflow:hidden;
      display:flex;
      flex-direction:column;
    }

    .fixed-head{ flex:0 0 auto; }

    .fixed-body{
      flex:1 1 auto;
      min-height:0;
      overflow:hidden;
      display:flex;
      flex-direction:column;
    }

    /* Filters area doesn't scroll */
    .filters-wrap{
      flex:0 0 auto;
      padding: 15px;
      border-bottom:1px solid rgba(0,0,0,.08);
      background:#fff;
    }

    /* ✅ Only the table area scrolls */
    .table-area{
      flex:1 1 auto;
      min-height:0;
      overflow:hidden;
      padding: 15px;
    }

    /* Make DataTables wrapper fill available height */
    .dt-wrap{
      height:100%;
      display:flex;
      flex-direction:column;
      min-height:0;
      overflow:hidden;
      background:#fff;
      border:1px solid rgba(0,0,0,.08);
      border-radius:10px;
    }

    /* DT top controls */
    .dt-top{
      flex:0 0 auto;
      padding: 10px 12px;
      border-bottom:1px solid rgba(0,0,0,.08);
    }

    /* DT table scroll zone */
    .dt-table{
      flex:1 1 auto;
      min-height:0;
      overflow:hidden; /* DT will handle scrolling */
      padding: 0 12px 12px 12px;
    }

    /* keep DataTables table nice */
    table.dataTable thead th { white-space:nowrap; }
    .mono { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; }
  </style>
</head>

<body>

  <!-- Leftbar -->
  <?php include __DIR__ . '/includes/leftbar.php'; ?>
  <!-- Header -->
  <?php include __DIR__ . '/includes/header.php'; ?>

  <div class="sh-mainpanel">

    <!-- <div class="sh-pagetitle">
      <div class="sh-pagetitle-left">
        <div class="sh-pagetitle-icon"><i class="icon ion-ios-list"></i></div>
        <div class="sh-pagetitle-title">
          <h2>Security Audit Logs</h2>
        </div>
      </div>
    </div> -->

    <?php if ($error): ?>
      <div class="errorWrap" id="msgshow"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php elseif ($msg): ?>
      <div class="succWrap" id="msgshow"><?= htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <div class="sh-pagebody">

      <div class="card bd-0 fixed-card">

        <div class="card-header bg-primary tx-white fixed-head">
          <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;">
            <div><strong>Logs</strong></div>
            <div style="opacity:.9;font-size:12px;">Filter + Search + Export-ready structure</div>
          </div>
        </div>

        <div class="fixed-body">

          <!-- ✅ Filters (fixed) -->
          <div class="filters-wrap">
            <div class="row row-xs">

              <div class="col-sm-3">
                <div class="form-group">
                  <label class="form-control-label">Email Address</label>
                  <input type="text" id="fEmail" class="form-control" placeholder="Search email...">
                </div>
              </div>

              <div class="col-sm-3">
                <div class="form-group">
                  <label class="form-control-label">Action</label>
                  <select id="fAction" class="form-control">
                    <option value="">All</option>
                    <option value="forgot_username">forgot_username</option>
                    <option value="forgot_password">forgot_password</option>
                    <option value="reset_password">reset_password</option>
                    <option value="admin_login">admin_login</option>
                    <option value="admin_login_failed">admin_login_failed</option>
                  </select>
                  <div class="small">Actions appear if you log them.</div>
                </div>
              </div>

              <div class="col-sm-2">
                <div class="form-group">
                  <label class="form-control-label">Result</label>
                  <select id="fSuccess" class="form-control">
                    <option value="">All</option>
                    <option value="1">Success</option>
                    <option value="0">Failed</option>
                  </select>
                </div>
              </div>

              <div class="col-sm-2">
                <div class="form-group">
                  <label class="form-control-label">Date From</label>
                  <input type="date" id="fFrom" class="form-control">
                </div>
              </div>

              <div class="col-sm-2">
                <div class="form-group">
                  <label class="form-control-label">Date To</label>
                  <input type="date" id="fTo" class="form-control">
                </div>
              </div>

            </div>

            <div style="display:flex;gap:10px;align-items:center;justify-content:flex-end;">
              <button id="btnApply" class="btn btn-primary">
                <i class="fa fa-filter"></i> Apply
              </button>

              <button id="btnReset" class="btn btn-outline-secondary">
                <i class="fa fa-eraser"></i> Reset
              </button>
            </div>
          </div>

          <!-- ✅ Table area (only this scrolls via DataTables scrollY) -->
          <div class="table-area">
            <div class="dt-wrap">

              <div class="dt-top">
                <div class="small">
                  Tip: Use the DataTables search box (top-right of the table) to search across all columns.
                </div>
              </div>

              <div class="dt-table">
                <table id="datatable1" class="table display responsive nowrap" style="width:100%">
                  <thead>
                    <tr>
                      <th style="width:60px;">ID</th>
                      <th style="width:160px;">Time</th>
                      <th>Email</th>
                      <th style="width:100px;">Admin ID</th>
                      <th>Action</th>
                      <th style="width:90px;">Result</th>
                      <th style="width:140px;">IP</th>
                      <th style="width:220px;">User Agent</th>
                      <th>Meta</th>
                    </tr>
                  </thead>
                  <tbody></tbody>
                </table>
              </div>

            </div>
          </div>

        </div><!-- /fixed-body -->
      </div><!-- /card -->

    </div><!-- /sh-pagebody -->

    <?php include __DIR__ . '/includes/footer.php'; ?>

  </div><!-- /sh-mainpanel -->

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
    'use strict';

    // ✅ server-side DataTables
    var table = $('#datatable1').DataTable({
      processing: true,
      serverSide: true,
      pageLength: 25,
      order: [[1, 'desc']], // Time desc
      responsive: true,
      autoWidth: false,

      // ✅ ONLY table body scrolls
      scrollY: '420px',
      scrollCollapse: true,
      scrollX: true,

      ajax: {
        url: 'ajax/security_log_data.php',
        type: 'GET',
        data: function(d){
          d.email     = $('#fEmail').val();
          d.action    = $('#fAction').val();
          d.success   = $('#fSuccess').val();
          d.date_from = $('#fFrom').val();
          d.date_to   = $('#fTo').val();
        },
        error: function(xhr){
          // Shows the real error in console
          console.error('DataTables AJAX error:', xhr.status, xhr.responseText);
        }
      },

      columns: [
        { data: 'id', width: '60px' },
        { data: 'created_at', width: '160px' },
        { data: 'email' },
        { data: 'admin_id', width: '100px' },
        { data: 'action', className: 'mono' },
        { data: 'success', width: '90px', orderable: true, searchable: false },
        { data: 'ip', className: 'mono', width: '140px' },
        { data: 'user_agent', width: '220px' },
        { data: 'meta' }
      ],

      initComplete: function(){
        setTimeout(function(){
          table.columns.adjust().draw(false);
        }, 50);
      }
    });

    $('#btnApply').on('click', function(e){
      e.preventDefault();
      table.ajax.reload();
    });

    $('#btnReset').on('click', function(e){
      e.preventDefault();
      $('#fEmail').val('');
      $('#fAction').val('');
      $('#fSuccess').val('');
      $('#fFrom').val('');
      $('#fTo').val('');
      table.ajax.reload();
    });

    $(window).on('resize', function(){
      table.columns.adjust();
    });
  });
  </script>

</body>
</html>
