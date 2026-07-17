<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/session_admin.php';
requireAdminLogin();

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/controller.php';
require_once __DIR__ . '/../public_user/includes/publisher_accounts.php';
require_once __DIR__ . '/../public_user/includes/publisher_authority.php';
require_once __DIR__ . '/../public_user/includes/org_commerce_brands.php';

$adminId = (int)($_SESSION['admin_id'] ?? 0);
$adminRole = (int)($_SESSION['userRole'] ?? 0);
$isAdmin = ($adminRole === 1);

if (!$isAdmin) {
    header('Location: dashboard.php');
    exit;
}

$controller = new Controller();
$dbh = $controller->pdo();
publisher_authority_ensure_schema($dbh);
org_commerce_brands_ensure_schema($dbh);

$msg = '';
$error = '';
$filter = strtolower(trim((string)($_GET['status'] ?? 'pending')));
if (!in_array($filter, ['pending', 'approved', 'rejected', 'all'], true)) {
    $filter = 'pending';
}

function h($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function fmt_dt($dt): string
{
    if (!$dt) {
        return 'N/A';
    }
    $ts = strtotime((string)$dt);
    if (!$ts) {
        return (string)$dt;
    }
    return date('M j, Y g:i A', $ts);
}

if (isset($_POST['approve_request'])) {
    $requestId = (int)($_POST['request_id'] ?? 0);
    $reviewNote = trim((string)($_POST['review_note'] ?? ''));
    $result = publisher_authority_admin_approve($dbh, $requestId, $adminId, $reviewNote);
    if (!empty($result['ok'])) {
        if (!empty($result['repaired'])) {
            $msg = 'Restored publisher name: ' . (string)($result['name'] ?? '');
        } elseif (!empty($result['message']) && strpos((string)$result['message'], 'Commerce') !== false) {
            $msg = (string)$result['message'];
        } else {
            $msg = 'Approved publisher name: ' . (string)($result['name'] ?? '');
        }
    } else {
        $error = (string)($result['message'] ?? 'Unable to approve this request.');
    }
}

if (isset($_POST['reject_request'])) {
    $requestId = (int)($_POST['request_id'] ?? 0);
    $reviewNote = trim((string)($_POST['review_note'] ?? ''));
    $result = publisher_authority_admin_reject($dbh, $requestId, $adminId, $reviewNote);
    if (!empty($result['ok'])) {
        $msg = 'Request rejected.';
    } else {
        $error = 'Unable to reject this request.';
    }
}

$requests = publisher_authority_admin_list($dbh, $filter);
$entityTypes = publisher_authority_entity_types();
$categories = publisher_categories();
$pendingCount = publisher_authority_pending_count($dbh);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
  <title>Publisher Name Requests</title>
  <link href="../lib/font-awesome/css/font-awesome.css" rel="stylesheet">
  <link href="../lib/Ionicons/css/ionicons.css" rel="stylesheet">
  <link rel="stylesheet" href="../css/shamcey.css">
  <style>
    :root{
      --bg:#f3f4f6;
      --card:#fff;
      --border:rgba(17,24,39,.10);
      --muted:#64748b;
      --brand:#2563eb;
      --brand2:#1d4ed8;
      --shadow:0 10px 30px rgba(17,24,39,.08);
      --shadow2:0 4px 14px rgba(17,24,39,.06);
      --radius:16px;
    }
    html, body, .sh-mainpanel{ height:100%; }
    body{ background:var(--bg); }
    .sh-pagebody{
      flex:1 1 auto;
      overflow:hidden;
      padding-bottom:0!important;
      display:flex;
      flex-direction:column;
      background:var(--bg);
    }
    .requests-card{
      flex:1 1 auto;
      min-height:0;
      display:flex;
      flex-direction:column;
      border:1px solid var(--border);
      box-shadow:var(--shadow);
      overflow:hidden;
      background:var(--card);
    }
    .card-header.pro{
      background:linear-gradient(135deg,var(--brand2),var(--brand));
      color:#fff;
      padding:16px 18px;
      font-weight:900;
      border-bottom:1px solid rgba(255,255,255,.18);
    }
    .card-header .sub{
      font-size:12px;
      opacity:.92;
      margin-top:4px;
      font-weight:700;
    }
    .pro-tools{
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap:12px;
      flex-wrap:wrap;
      padding:14px 18px;
      border-bottom:1px solid rgba(17,24,39,.06);
      background:rgba(248,250,252,.90);
    }
    .filter-tabs{display:flex;gap:8px;flex-wrap:wrap}
    .filter-tabs a{
      display:inline-flex;
      align-items:center;
      gap:6px;
      padding:8px 12px;
      border-radius:999px;
      border:1px solid var(--border);
      background:#fff;
      color:#111827;
      font-size:12px;
      font-weight:800;
      text-decoration:none;
    }
    .filter-tabs a.is-active{
      background:rgba(37,99,235,.10);
      border-color:rgba(37,99,235,.25);
      color:#1d4ed8;
    }
    .table-scroll{
      flex:1 1 auto;
      min-height:0;
      overflow:auto;
    }
    .pill{
      display:inline-flex;
      align-items:center;
      padding:6px 10px;
      border-radius:999px;
      font-size:12px;
      font-weight:900;
      white-space:nowrap;
    }
    .pill.pending{background:rgba(245,158,11,.12);color:#b45309}
    .pill.approved{background:rgba(34,197,94,.12);color:#15803d}
    .pill.rejected{background:rgba(239,68,68,.12);color:#b91c1c}
    .btn{border-radius:14px;font-weight:900}
    .note-cell{max-width:260px;white-space:normal;font-size:12px;color:var(--muted)}
    .action-form{display:flex;gap:8px;align-items:flex-start;flex-wrap:wrap}
    .action-form textarea{
      min-width:180px;
      max-width:220px;
      min-height:38px;
      border-radius:12px;
      border:1px solid var(--border);
      padding:8px 10px;
      font-size:12px;
    }
    #requestsTable thead th{
      position:sticky;
      top:0;
      background:#fff;
      z-index:5;
      font-weight:900;
    }
  </style>
</head>
<body>

<?php include __DIR__ . '/includes/leftbar.php'; ?>
<?php include __DIR__ . '/includes/header.php'; ?>

<div class="sh-mainpanel">
  <!-- <div class="sh-pagetitle">
    <div class="input-group"></div>
    <div class="sh-pagetitle-left">
      <div class="sh-pagetitle-icon"><i class="icon ion-ios-paper"></i></div>
      <div class="sh-pagetitle-title">
        <span>Publishers</span>
        <h2>Publisher Name Requests</h2>
      </div>
    </div>
  </div> -->

  <div class="sh-pagebody">
    <?php if ($error): ?>
      <div class="alert alert-danger" style="margin:0 0 10px 0;"><?= h($error) ?></div>
    <?php elseif ($msg): ?>
      <div class="alert alert-success" style="margin:0 0 10px 0;"><?= h($msg) ?></div>
    <?php endif; ?>

    <div class="requests-card">
      <div class="pro-tools">
        <div class="filter-tabs">
          <a href="publisher_requests.php?status=pending" class="<?= $filter === 'pending' ? 'is-active' : '' ?>">
            Pending<?php if ($pendingCount > 0): ?> (<?= (int)$pendingCount ?>)<?php endif; ?>
          </a>
          <a href="publisher_requests.php?status=approved" class="<?= $filter === 'approved' ? 'is-active' : '' ?>">Approved</a>
          <a href="publisher_requests.php?status=rejected" class="<?= $filter === 'rejected' ? 'is-active' : '' ?>">Rejected</a>
          <a href="publisher_requests.php?status=all" class="<?= $filter === 'all' ? 'is-active' : '' ?>">All</a>
        </div>
        <div style="font-size:12px;color:var(--muted);font-weight:700;">
          Showing <?= (int)count($requests) ?> request<?= count($requests) === 1 ? '' : 's' ?>
        </div>
      </div>
        <!-- <div>
          <div style="font-size:16px;">Review new publisher name requests</div>
          <div class="sub">Approve a request to make the name available for publisher signup. No tax ID is required.</div>
        </div> -->
      <div class="table-scroll">
        <table class="table table-hover mg-b-0-force" id="requestsTable">
          <thead>
            <tr>
              <th>Publisher / brand</th>
              <th>Category</th>
              <th>Applicant</th>
              <th>Organization</th>
              <th>Contact</th>
              <th>Request note</th>
              <th>Status</th>
              <th>Submitted</th>
              <th>Review</th>
            </tr>
          </thead>
          <tbody>
          <?php if (!$requests): ?>
            <tr>
              <td colspan="9" style="padding:24px;color:var(--muted);font-weight:700;">No requests in this view.</td>
            </tr>
          <?php else: ?>
            <?php foreach ($requests as $row): ?>
              <?php
                $status = strtolower((string)($row['status'] ?? 'pending'));
                $entityType = (string)($row['entity_type'] ?? '');
                $entityLabel = $entityTypes[$entityType] ?? $entityType;
                $categoryKey = strtolower((string)($row['publisher_category'] ?? 'news'));
                $categoryLabel = $categories[$categoryKey] ?? $categoryKey;
                $isCommerce = publisher_authority_is_commerce_request($row);
                $brandId = (int)($row['commerce_brand_id'] ?? 0);
                $brandRow = $brandId > 0 ? org_commerce_brands_get($dbh, $brandId) : null;
                $legalName = trim((string)($row['legal_entity_name'] ?? ''));
                $orgLine = $legalName !== '' ? $legalName : $entityLabel;
                $applicantUsername = trim((string)($row['applicant_username'] ?? ''));
                $applicantEmail = trim((string)($row['applicant_email'] ?? ''));
              ?>
              <tr>
                <td>
                  <strong><?= h($row['publisher_name'] ?? '') ?></strong>
                  <?php if ($isCommerce && $brandRow): ?>
                    <div style="font-size:11px;color:var(--muted);">Brand system: <?= h($brandRow['name'] ?? '') ?></div>
                  <?php elseif ($isCommerce && $brandId <= 0): ?>
                    <div style="font-size:11px;color:var(--muted);">New commerce brand system request</div>
                  <?php endif; ?>
                </td>
                <td><?= h($categoryLabel) ?></td>
                <td>
                  <?php if ($applicantUsername !== '' || $applicantEmail !== ''): ?>
                    <div><?= h($applicantUsername !== '' ? $applicantUsername : '—') ?></div>
                    <div style="font-size:11px;color:var(--muted);"><?= h($applicantEmail) ?></div>
                  <?php else: ?>
                    <span style="color:var(--muted);">—</span>
                  <?php endif; ?>
                </td>
                <td>
                  <div><?= h($orgLine) ?></div>
                  <div style="font-size:11px;color:var(--muted);"><?= h($entityLabel) ?></div>
                </td>
                <td>
                  <div><?= h($row['authorized_contact_name'] ?? '') ?></div>
                  <div style="font-size:11px;color:var(--muted);"><?= h($row['authorized_contact_email'] ?? '') ?></div>
                </td>
                <td class="note-cell"><?= h($row['request_note'] ?? '') ?: '—' ?></td>
                <td><span class="pill <?= h($status) ?>"><?= h(ucfirst($status)) ?></span></td>
                <td><?= h(fmt_dt($row['created_at'] ?? '')) ?></td>
                <td>
                  <?php if ($status === 'pending'): ?>
                    <form method="post" class="action-form">
                      <input type="hidden" name="request_id" value="<?= (int)($row['id'] ?? 0) ?>">
                      <textarea name="review_note" placeholder="Optional note"></textarea>
                      <button type="submit" name="approve_request" value="1" class="btn btn-success btn-sm">Approve</button>
                      <button type="submit" name="reject_request" value="1" class="btn btn-danger btn-sm">Reject</button>
                    </form>
                  <?php else: ?>
                    <div style="font-size:12px;color:var(--muted);">
                      <?= h(fmt_dt($row['reviewed_at'] ?? '')) ?>
                      <?php if (trim((string)($row['review_note'] ?? '')) !== ''): ?>
                        <div><?= h($row['review_note']) ?></div>
                      <?php endif; ?>
                    </div>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<script src="../lib/jquery/jquery.js"></script>
<script src="../lib/bootstrap/bootstrap.js"></script>
</body>
</html>
