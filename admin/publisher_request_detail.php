<?php
declare(strict_types=1);

/**
 * admin/publisher_request_detail.php
 * Profile-style detail UI matching user_form.php.
 * Preserves approve/reject + prev/next behavior.
 */
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
$requestId = (int)($_GET['id'] ?? $_GET['request_id'] ?? $_POST['request_id'] ?? 0);
$listStatus = strtolower(trim((string)($_GET['status'] ?? 'all')));
if (!in_array($listStatus, ['pending', 'approved', 'rejected', 'all'], true)) {
    $listStatus = 'all';
}

function h($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function fmt_dt($dt): string
{
    if (!$dt) {
        return '—';
    }
    $ts = strtotime((string)$dt);
    if (!$ts) {
        return (string)$dt;
    }
    return date('M j, Y g:i A', $ts);
}

function initials2(string $name): string
{
    $name = trim(preg_replace('/\s+/', ' ', $name) ?? $name);
    if ($name === '') {
        return '??';
    }
    $name = str_replace(['_', '.', '-', '@'], ' ', $name);
    $parts = array_values(array_filter(explode(' ', $name), static fn($p) => trim($p) !== ''));
    if (!$parts) {
        return '??';
    }
    $first = mb_strtoupper(mb_substr($parts[0], 0, 1));
    $second = '';
    if (count($parts) > 1) {
        $second = mb_strtoupper(mb_substr($parts[count($parts) - 1], 0, 1));
    } else {
        $second = mb_strtoupper(mb_substr($parts[0], 1, 1));
    }
    $ini = trim($first . $second);
    return $ini !== '' ? $ini : '??';
}

function avatarColor(string $key): string
{
    $key = strtolower(trim($key));
    $hash = crc32($key);
    $palette = ['#2563eb','#7c3aed','#db2777','#ea580c','#16a34a','#0f766e','#0891b2','#475569'];
    return $palette[$hash % count($palette)];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $requestId = (int)($_POST['request_id'] ?? $requestId);
    $reviewNote = trim((string)($_POST['review_note'] ?? ''));
    $listStatus = strtolower(trim((string)($_POST['list_status'] ?? $listStatus)));
    if (!in_array($listStatus, ['pending', 'approved', 'rejected', 'all'], true)) {
        $listStatus = 'all';
    }

    if (isset($_POST['approve_request'])) {
        $result = publisher_authority_admin_approve($dbh, $requestId, $adminId, $reviewNote);
        if (!empty($result['ok'])) {
            if (!empty($result['repaired'])) {
                $msg = 'Restored publisher name: ' . (string)($result['name'] ?? '');
            } elseif (!empty($result['message']) && strpos((string)$result['message'], 'Commerce') !== false) {
                $msg = (string)$result['message'];
            } else {
                $msg = 'Approved publisher name: ' . (string)($result['name'] ?? '');
            }
            header('Location: publisher_request_detail.php?id=' . $requestId . '&status=' . rawurlencode($listStatus) . '&msg=' . rawurlencode($msg));
            exit;
        }
        $error = (string)($result['message'] ?? 'Unable to approve this request.');
    }

    if (isset($_POST['reject_request'])) {
        $result = publisher_authority_admin_reject($dbh, $requestId, $adminId, $reviewNote);
        if (!empty($result['ok'])) {
            header('Location: publisher_request_detail.php?id=' . $requestId . '&status=' . rawurlencode($listStatus) . '&msg=' . rawurlencode('Request rejected.'));
            exit;
        }
        $error = 'Unable to reject this request.';
    }
}

if (isset($_GET['msg']) && trim((string)$_GET['msg']) !== '') {
    $msg = trim((string)$_GET['msg']);
}

$request = $requestId > 0 ? publisher_authority_fetch_request($dbh, $requestId) : null;

$entityTypes = publisher_authority_entity_types();
$categories = publisher_categories();

$allRequests = publisher_authority_admin_list($dbh, 'all');
$pendingCount = 0;
$approvedCount = 0;
$rejectedCount = 0;
foreach ($allRequests as $countRow) {
    $st = strtolower((string)($countRow['status'] ?? 'pending'));
    if ($st === 'approved') {
        $approvedCount++;
    } elseif ($st === 'rejected') {
        $rejectedCount++;
    } else {
        $pendingCount++;
    }
}
$totalCount = count($allRequests);

$navIds = [];
foreach (publisher_authority_admin_list($dbh, $listStatus) as $navRow) {
    $nid = (int)($navRow['id'] ?? 0);
    if ($nid > 0) {
        $navIds[] = $nid;
    }
}
$prevId = 0;
$nextId = 0;
$navPos = 0;
$navTotal = count($navIds);
if ($requestId > 0 && $navIds) {
    $idx = array_search($requestId, $navIds, true);
    if ($idx === false) {
        $navIds = [];
        foreach ($allRequests as $navRow) {
            $nid = (int)($navRow['id'] ?? 0);
            if ($nid > 0) {
                $navIds[] = $nid;
            }
        }
        $navTotal = count($navIds);
        $idx = array_search($requestId, $navIds, true);
        $listStatus = 'all';
    }
    if ($idx !== false) {
        $navPos = (int)$idx + 1;
        if ($idx > 0) {
            $prevId = (int)$navIds[$idx - 1];
        }
        if ($idx < count($navIds) - 1) {
            $nextId = (int)$navIds[$idx + 1];
        }
    }
}

if (!$request) {
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
  <title>Request not found</title>
  <link href="../lib/font-awesome/css/font-awesome.css" rel="stylesheet">
  <link rel="stylesheet" href="../css/shamcey.css">
</head>
<body>
<?php include __DIR__ . '/includes/leftbar.php'; ?>
<?php include __DIR__ . '/includes/header.php'; ?>
<div class="sh-mainpanel">
  <div class="sh-pagebody" style="padding:20px;">
    <div style="background:#fef2f2;border:1px solid #fecaca;color:#991b1b;padding:10px 12px;border-radius:8px;font-weight:700;">
      Request not found.
    </div>
    <p style="margin-top:12px;"><a href="publisher_requests.php">Back to Publisher Requests</a></p>
  </div>
</div>
</body>
</html>
    <?php
    exit;
}

$status = strtolower((string)($request['status'] ?? 'pending'));
$pubName = trim((string)($request['publisher_name'] ?? ''));
$categoryKey = strtolower((string)($request['publisher_category'] ?? 'news'));
$categoryLabel = $categories[$categoryKey] ?? $categoryKey;
$entityType = (string)($request['entity_type'] ?? '');
$entityLabel = $entityTypes[$entityType] ?? $entityType;
$isCommerce = publisher_authority_is_commerce_request($request);
$brandId = (int)($request['commerce_brand_id'] ?? 0);
$brandRow = $brandId > 0 ? org_commerce_brands_get($dbh, $brandId) : null;
$legalName = trim((string)($request['legal_entity_name'] ?? ''));
$regId = trim((string)($request['registration_id'] ?? ''));
$regCountry = trim((string)($request['registration_country'] ?? ''));
$applicantUsername = trim((string)($request['applicant_username'] ?? ''));
$applicantEmail = trim((string)($request['applicant_email'] ?? ''));
$contactName = trim((string)($request['authorized_contact_name'] ?? ''));
$contactEmail = trim((string)($request['authorized_contact_email'] ?? ''));
$reqNote = trim((string)($request['request_note'] ?? ''));
$authorityConfirmed = (int)($request['authority_confirmed'] ?? 0) === 1;
$reviewedBy = (int)($request['reviewed_by_admin_id'] ?? 0);
$reviewedAt = (string)($request['reviewed_at'] ?? '');
$reviewNote = trim((string)($request['review_note'] ?? ''));
$createdAt = (string)($request['created_at'] ?? '');
$optionId = (int)($request['publisher_name_option_id'] ?? 0);

$ageDays = 0;
if ($createdAt !== '') {
    $cts = strtotime($createdAt);
    if ($cts) {
        $ageDays = max(0, (int)floor((time() - $cts) / 86400));
    }
}

$completeness = 0;
$checks = [
    'Publisher name' => $pubName !== '',
    'Category set' => $categoryKey !== '',
    'Legal / org name' => $legalName !== '',
    'Contact name' => $contactName !== '',
    'Contact email' => $contactEmail !== '',
    'Authority confirmed' => $authorityConfirmed,
];
$checkTotal = count($checks);
$checkOk = 0;
foreach ($checks as $ok) {
    if ($ok) {
        $checkOk++;
    }
}
$completeness = $checkTotal > 0 ? (int)round(($checkOk / $checkTotal) * 100) : 0;
$gaugeDeg = (int)round(($completeness / 100) * 180);

$iniSrc = $applicantUsername !== '' ? $applicantUsername : ($contactName !== '' ? $contactName : $pubName);
$ini = initials2($iniSrc !== '' ? $iniSrc : 'RQ');
$avBg = avatarColor($applicantEmail !== '' ? $applicantEmail : ($pubName !== '' ? $pubName : (string)$requestId));

$reviewerName = '';
if ($reviewedBy > 0) {
    try {
        $st = $dbh->prepare('SELECT fullname, username FROM admin WHERE idadmin = :id LIMIT 1');
        $st->execute([':id' => $reviewedBy]);
        $adm = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $reviewerName = trim((string)($adm['fullname'] ?? ''));
        if ($reviewerName === '') {
            $reviewerName = trim((string)($adm['username'] ?? ''));
        }
        if ($reviewerName === '') {
            $reviewerName = 'Admin #' . $reviewedBy;
        }
    } catch (Throwable $e) {
        $reviewerName = 'Admin #' . $reviewedBy;
    }
}

$statusBadge = $status === 'approved' ? 'ok' : ($status === 'rejected' ? 'bad' : 'warn');
$listHref = 'publisher_requests.php?status=' . rawurlencode($listStatus);
$detailQs = 'status=' . rawurlencode($listStatus);
$typeLabel = $isCommerce ? 'Seller' : 'Publisher';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
  <title>Request #<?= (int)$requestId ?> · <?= h($pubName !== '' ? $pubName : 'Publisher Request') ?></title>
  <link href="../lib/font-awesome/css/font-awesome.css" rel="stylesheet">
  <link href="../lib/Ionicons/css/ionicons.css" rel="stylesheet">
  <link rel="stylesheet" href="../css/shamcey.css">
  <link rel="stylesheet" href="css/admin-tables-shamcey.css?v=6">
  <style>
    html,body{height:100%;overflow:hidden;}
    .sh-mainpanel{height:100vh;display:flex;flex-direction:column;overflow:hidden;}
    .sh-mainpanel > .sh-pagebody{
      overflow:hidden !important;display:flex !important;flex-direction:column !important;min-height:0 !important;
      padding-top:4px !important;padding-bottom:4px !important;flex:1 1 auto;background:#f4f6fb;
    }
    .uf-wrap{
      flex:1 1 auto;min-height:0;height:100%;width:100%;max-width:100%;
      display:flex;flex-direction:column;gap:5px;overflow:hidden;padding:0 2px;box-sizing:border-box;
    }
    .uf-btn{
      height:24px;padding:0 8px;border-radius:6px;border:1px solid #e2e8f0;background:#fff;
      font-size:10px;font-weight:700;color:#334155;display:inline-flex;align-items:center;gap:4px;
      text-decoration:none;cursor:pointer;white-space:nowrap;
    }
    .uf-btn:hover{background:#f8fafc;text-decoration:none;color:#0f172a;}
    .uf-btn.primary{background:#2563eb;border-color:#2563eb;color:#fff;}
    .uf-btn.primary:hover{background:#1d4ed8;color:#fff;}
    .uf-btn.ok{background:#16a34a;border-color:#16a34a;color:#fff;}
    .uf-btn.bad{border-color:#fecaca;color:#b91c1c;}
    .uf-btn.sm{height:20px;padding:0 6px;font-size:9px;}
    .uf-btn.is-disabled{opacity:.45;pointer-events:none;}

    .uf-hero{
      flex:0 0 auto;background:#fff;border:1px solid #eef2f7;border-radius:8px;padding:6px 10px;
      display:flex;align-items:flex-start;justify-content:space-between;gap:8px;min-width:0;
    }
    .uf-hero-left{display:flex;gap:8px;min-width:0;align-items:flex-start;flex:1 1 auto;}
    .uf-av{width:40px;height:40px;border-radius:999px;color:#fff;font-weight:800;font-size:13px;display:flex;align-items:center;justify-content:center;flex:0 0 40px;}
    .uf-hero h1{margin:0;font-size:14px;font-weight:800;color:#0f172a;line-height:1.15;display:inline-flex;align-items:center;gap:5px;}
    .uf-hero .name{font-size:11px;color:#64748b;font-weight:600;margin-top:1px;display:flex;align-items:center;gap:6px;flex-wrap:wrap;}
    .uf-meta{margin-top:3px;display:grid;grid-template-columns:1fr 1fr;gap:1px 12px;}
    .uf-meta-row{display:flex;align-items:center;gap:5px;font-size:10px;color:#475569;font-weight:600;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
    .uf-meta-row i{width:12px;color:#94a3b8;text-align:center;font-size:10px;flex:0 0 auto;}
    .uf-hero-actions{display:flex;gap:5px;flex-wrap:wrap;align-items:center;justify-content:flex-end;}

    .uf-badge{display:inline-flex;align-items:center;gap:3px;padding:1px 6px;border-radius:999px;font-size:9px;font-weight:800;}
    .uf-badge.ok,.uf-badge.green{background:#dcfce7;color:#15803d;}
    .uf-badge.bad{background:#fee2e2;color:#b91c1c;}
    .uf-badge.warn{background:#ffedd5;color:#c2410c;}
    .uf-badge.blue{background:#dbeafe;color:#1d4ed8;}
    .uf-badge.gray{background:#f1f5f9;color:#475569;}
    .uf-badge.orange{background:#ffedd5;color:#c2410c;}

    .uf-metrics{flex:0 0 auto;display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:5px;min-width:0;}
    .uf-metric{background:#fff;border:1px solid #eef2f7;border-radius:8px;padding:5px 8px;min-width:0;overflow:hidden;}
    .uf-metric-top{display:flex;align-items:center;justify-content:space-between;gap:4px;margin-bottom:1px;}
    .uf-metric .lab{font-size:9px;font-weight:700;color:#64748b;}
    .uf-metric .val{font-size:14px;font-weight:800;color:#0f172a;line-height:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
    .uf-mico{width:16px;height:16px;border-radius:999px;display:flex;align-items:center;justify-content:center;font-size:8px;flex:0 0 auto;}
    .uf-mico.purple{background:#f5f3ff;color:#7c3aed;}
    .uf-mico.blue{background:#dbeafe;color:#2563eb;}
    .uf-mico.green{background:#dcfce7;color:#16a34a;}
    .uf-mico.orange{background:#ffedd5;color:#ea580c;}
    .uf-mico.yellow{background:#fef9c3;color:#ca8a04;}
    .uf-mico.red{background:#fee2e2;color:#dc2626;}

    .uf-summary{
      flex:0 0 auto;background:#fff;border:1px solid #eef2f7;border-radius:8px;padding:5px 10px;
      display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:6px;min-width:0;
    }
    .uf-sum-item{min-width:0;overflow:hidden;}
    .uf-sum-item .k{font-size:8px;font-weight:800;color:#94a3b8;text-transform:uppercase;}
    .uf-sum-item .v{font-size:10px;font-weight:700;color:#0f172a;margin-top:2px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}

    .uf-tabs{
      flex:0 0 auto;display:flex;gap:0;background:#fff;border:1px solid #eef2f7;border-radius:8px;
      padding:0 4px;overflow:hidden;min-width:0;
    }
    .uf-tabs a{
      flex:0 0 auto;padding:5px 8px;font-size:10px;font-weight:700;color:#64748b;text-decoration:none;
      border-bottom:2px solid transparent;white-space:nowrap;
    }
    .uf-tabs a.is-active{color:#2563eb;border-bottom-color:#2563eb;}
    .uf-tabs a:hover{color:#0f172a;text-decoration:none;}

    .uf-board{
      flex:1 1 auto;min-height:0;min-width:0;
      display:grid;grid-template-columns:minmax(0,1.15fr) minmax(0,.9fr) minmax(0,.95fr);
      gap:5px;overflow:hidden;
    }
    .uf-col{min-height:0;min-width:0;display:flex;flex-direction:column;gap:5px;overflow:hidden;}
    .uf-card{
      background:#fff;border:1px solid #eef2f7;border-radius:8px;overflow:hidden;min-width:0;min-height:0;
      display:flex;flex-direction:column;
    }
    .uf-card.flex{flex:1 1 auto;}
    .uf-card-hd{
      flex:0 0 auto;display:flex;align-items:center;justify-content:space-between;gap:6px;
      padding:4px 8px;border-bottom:1px solid #f1f5f9;
    }
    .uf-card-hd h2{margin:0;font-size:11px;font-weight:800;color:#0f172a;}
    .uf-card-bd{flex:1 1 auto;min-height:0;padding:6px 8px;overflow:hidden;}
    .uf-card-bd.scroll{overflow:auto;overscroll-behavior:contain;}

    .uf-kv{display:flex;justify-content:space-between;gap:8px;padding:3px 0;border-bottom:1px solid #f8fafc;font-size:10px;}
    .uf-kv:last-child{border-bottom:0;}
    .uf-kv .k{color:#64748b;font-weight:700;}
    .uf-kv .v{color:#0f172a;font-weight:800;text-align:right;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:62%;}

    .uf-tl{position:relative;padding-left:12px;}
    .uf-tl::before{content:'';position:absolute;left:3px;top:2px;bottom:2px;width:2px;background:#e2e8f0;}
    .uf-tl-item{position:relative;padding:0 0 6px 7px;}
    .uf-tl-item:last-child{padding-bottom:0;}
    .uf-tl-dot{
      position:absolute;left:-12px;top:1px;width:10px;height:10px;border-radius:999px;background:#fff;
      border:2px solid #93c5fd;
    }
    .uf-tl-dot.ok{border-color:#86efac;}
    .uf-tl-dot.bad{border-color:#fca5a5;}
    .uf-tl-dot.warn{border-color:#fdba74;}
    .uf-tl-when{font-size:8px;color:#94a3b8;font-weight:700;}
    .uf-tl-text{font-size:10px;font-weight:700;color:#0f172a;}

    .uf-note{
      background:#fffbeb;border:1px solid #fde68a;border-radius:6px;padding:5px 7px;margin-bottom:5px;
      font-size:10px;color:#78350f;line-height:1.3;white-space:pre-wrap;
    }
    .uf-note.blue{background:#eff6ff;border-color:#bfdbfe;color:#1e3a8a;}
    .uf-note.muted{background:#f8fafc;border-color:#e2e8f0;color:#475569;}
    .uf-note:last-child{margin-bottom:0;}
    .uf-note .meta{font-size:8px;font-weight:700;margin-bottom:2px;opacity:.85;}

    .uf-risk-top{display:flex;align-items:center;justify-content:space-between;gap:6px;margin-bottom:4px;}
    .uf-risk-top .lab{font-size:9px;font-weight:700;color:#64748b;}
    .uf-gauge{
      width:84px;height:42px;margin:0 auto 4px;position:relative;
      background:conic-gradient(from 180deg, #ef4444 0deg, #eab308 90deg, #22c55e 180deg, transparent 180deg);
      border-radius:84px 84px 0 0;overflow:hidden;
    }
    .uf-gauge::after{
      content:'';position:absolute;left:9px;right:9px;top:9px;bottom:0;background:#fff;border-radius:70px 70px 0 0;
    }
    .uf-gauge-needle{
      position:absolute;left:50%;bottom:0;width:2px;height:34px;background:#0f172a;transform-origin:bottom center;
      transform:translateX(-50%) rotate(var(--needle, -90deg));z-index:2;border-radius:2px;
    }
    .uf-gauge-score{text-align:center;font-size:11px;font-weight:800;color:#0f172a;margin-top:-2px;}
    .uf-flags{display:flex;flex-direction:column;gap:2px;max-height:72px;overflow:auto;}
    .uf-flag{display:flex;align-items:center;gap:5px;font-size:9px;font-weight:700;color:#334155;}
    .uf-flag .dot{width:6px;height:6px;border-radius:999px;flex:0 0 auto;}
    .uf-flag .dot.bad{background:#dc2626;}
    .uf-flag .dot.ok{background:#16a34a;}
    .uf-flag .dot.warn{background:#ea580c;}

    .uf-quick{display:grid;grid-template-columns:1fr 1fr;gap:4px;}
    .uf-qbtn{
      border:1px solid #e2e8f0;border-radius:6px;padding:7px 4px;background:#fff;text-align:center;
      font-size:9px;font-weight:800;color:#334155;text-decoration:none;display:flex;flex-direction:column;align-items:center;gap:2px;
      cursor:pointer;
    }
    .uf-qbtn i{font-size:11px;}
    .uf-qbtn:hover{text-decoration:none;background:#f8fafc;}
    .uf-qbtn.green{border-color:#bbf7d0;background:#f0fdf4;color:#166534;}
    .uf-qbtn.orange{border-color:#fed7aa;background:#fff7ed;color:#c2410c;}
    .uf-qbtn.red{border-color:#fecaca;background:#fef2f2;color:#b91c1c;}
    .uf-qbtn.blue{border-color:#bfdbfe;background:#eff6ff;color:#1d4ed8;}
    .uf-qbtn.is-disabled{opacity:.45;pointer-events:none;}

    .uf-form{display:flex;flex-direction:column;gap:6px;min-height:0;height:100%;}
    .uf-field label{display:block;font-size:9px;font-weight:800;color:#64748b;margin:0 0 2px;}
    .uf-field textarea{
      width:100%;min-height:64px;border:1px solid #e2e8f0;border-radius:6px;padding:6px 7px;
      font-size:11px;color:#0f172a;box-sizing:border-box;resize:vertical;
    }
    .uf-actions{display:flex;justify-content:flex-end;gap:5px;margin-top:auto;flex-wrap:wrap;}
    .uf-alert{flex:0 0 auto;padding:4px 8px;border-radius:6px;font-size:11px;font-weight:700;}
    .uf-alert.bad{background:#fef2f2;color:#991b1b;border:1px solid #fecaca;}
    .uf-alert.ok{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0;}
    .uf-drop{position:relative;}
    .uf-drop-menu{
      display:none;position:absolute;right:0;top:calc(100% + 4px);z-index:30;min-width:150px;
      background:#fff;border:1px solid #e2e8f0;border-radius:8px;box-shadow:0 8px 20px rgba(15,23,42,.12);padding:4px;
    }
    .uf-drop.open .uf-drop-menu{display:block;}
    .uf-drop-menu a{
      display:block;width:100%;text-align:left;padding:6px 8px;border-radius:6px;font-size:11px;font-weight:700;
      color:#334155;text-decoration:none;
    }
    .uf-drop-menu a:hover{background:#f8fafc;}
    .uf-empty{padding:6px 4px;text-align:center;color:#64748b;font-size:10px;}

    @media (max-width:1100px){
      .uf-wrap{overflow:auto;}
      .uf-board,.uf-metrics,.uf-summary,.uf-meta,.uf-quick{grid-template-columns:1fr;}
      .uf-col,.uf-card{overflow:visible;max-height:none;}
    }
  </style>
</head>
<body>
<?php include __DIR__ . '/includes/leftbar.php'; ?>
<?php include __DIR__ . '/includes/header.php'; ?>

<div class="sh-mainpanel">
  <div class="sh-pagebody">
    <div class="uf-wrap">

      <?php if ($error !== ''): ?><div class="uf-alert bad"><?= h($error) ?></div><?php endif; ?>
      <?php if ($msg !== ''): ?><div class="uf-alert ok"><?= h($msg) ?></div><?php endif; ?>

      <section class="uf-hero">
        <div class="uf-hero-left">
          <div class="uf-av" style="background:<?= h($avBg) ?>;"><?= h($ini) ?></div>
          <div style="min-width:0;">
            <h1>
              <?= h($pubName !== '' ? $pubName : 'Request #' . $requestId) ?>
              <i class="fa fa-<?= $isCommerce ? 'shopping-bag' : 'bullhorn' ?>" style="color:#2563eb;font-size:12px;" title="<?= h($typeLabel) ?>"></i>
            </h1>
            <div class="name">
              <?= h($typeLabel) ?> name request
              <span class="uf-badge <?= h($statusBadge) ?>"><?= h(ucfirst($status)) ?></span>
              <?php if ($isCommerce): ?><span class="uf-badge orange">Seller</span><?php else: ?><span class="uf-badge blue">Publisher</span><?php endif; ?>
              <?php if ($navPos > 0): ?><span class="uf-badge gray"><?= (int)$navPos ?> / <?= (int)$navTotal ?></span><?php endif; ?>
            </div>
            <div class="uf-meta">
              <div class="uf-meta-row"><i class="fa fa-calendar"></i> Submitted <?= h(fmt_dt($createdAt)) ?></div>
              <div class="uf-meta-row"><i class="fa fa-tag"></i> <?= h($categoryLabel) ?></div>
              <div class="uf-meta-row"><i class="fa fa-envelope"></i> <?= h($applicantEmail !== '' ? $applicantEmail : ($contactEmail !== '' ? $contactEmail : '—')) ?></div>
              <div class="uf-meta-row"><i class="fa fa-hashtag"></i> Request #<?= (int)$requestId ?><?= $optionId > 0 ? ' · option #' . (int)$optionId : '' ?></div>
            </div>
          </div>
        </div>
        <div class="uf-hero-actions">
          <div class="uf-drop" id="ufActionsDrop">
            <button type="button" class="uf-btn" onclick="document.getElementById('ufActionsDrop').classList.toggle('open')"><i class="fa fa-ellipsis-v"></i> Actions</button>
            <div class="uf-drop-menu">
              <a href="publisher_requests.php?status=pending">Pending queue</a>
              <a href="userlist.php?kind=publisher">Publisher users</a>
              <a href="userlist.php?kind=commerce">Seller users</a>
            </div>
          </div>
          <a class="uf-btn<?= $prevId <= 0 ? ' is-disabled' : '' ?>" href="<?= $prevId > 0 ? 'publisher_request_detail.php?id=' . $prevId . '&amp;' . h($detailQs) : '#' ?>"><i class="fa fa-chevron-left"></i> Previous</a>
          <a class="uf-btn<?= $nextId <= 0 ? ' is-disabled' : '' ?>" href="<?= $nextId > 0 ? 'publisher_request_detail.php?id=' . $nextId . '&amp;' . h($detailQs) : '#' ?>">Next <i class="fa fa-chevron-right"></i></a>
          <a class="uf-btn primary" href="<?= h($listHref) ?>"><i class="fa fa-angle-left"></i> Back to Requests</a>
        </div>
      </section>

      <div class="uf-metrics">
        <div class="uf-metric"><div class="uf-metric-top"><span class="lab">Status</span><span class="uf-mico <?= $status === 'approved' ? 'green' : ($status === 'rejected' ? 'red' : 'orange') ?>"><i class="fa fa-flag"></i></span></div><div class="val"><?= h(ucfirst($status)) ?></div></div>
        <div class="uf-metric"><div class="uf-metric-top"><span class="lab">Type</span><span class="uf-mico blue"><i class="fa fa-<?= $isCommerce ? 'shopping-bag' : 'bullhorn' ?>"></i></span></div><div class="val"><?= h($typeLabel) ?></div></div>
        <div class="uf-metric"><div class="uf-metric-top"><span class="lab">Age (days)</span><span class="uf-mico purple"><i class="fa fa-clock-o"></i></span></div><div class="val"><?= number_format($ageDays) ?></div></div>
        <div class="uf-metric"><div class="uf-metric-top"><span class="lab">Pending queue</span><span class="uf-mico orange"><i class="fa fa-hourglass-half"></i></span></div><div class="val"><?= number_format($pendingCount) ?></div></div>
        <div class="uf-metric"><div class="uf-metric-top"><span class="lab">Approved</span><span class="uf-mico green"><i class="fa fa-check"></i></span></div><div class="val"><?= number_format($approvedCount) ?></div></div>
        <div class="uf-metric"><div class="uf-metric-top"><span class="lab">Complete</span><span class="uf-mico yellow"><i class="fa fa-pie-chart"></i></span></div><div class="val"><?= (int)$completeness ?>%</div></div>
      </div>

      <div class="uf-summary">
        <div class="uf-sum-item"><div class="k">Status</div><div class="v"><span class="uf-badge <?= h($statusBadge) ?>"><?= h(ucfirst($status)) ?></span></div></div>
        <div class="uf-sum-item"><div class="k">Category</div><div class="v"><?= h($categoryLabel) ?></div></div>
        <div class="uf-sum-item"><div class="k">Entity</div><div class="v"><?= h($entityLabel !== '' ? $entityLabel : '—') ?></div></div>
        <div class="uf-sum-item"><div class="k">Applicant</div><div class="v"><?= h($applicantUsername !== '' ? $applicantUsername : '—') ?></div></div>
        <div class="uf-sum-item"><div class="k">Contact</div><div class="v"><?= h($contactName !== '' ? $contactName : '—') ?></div></div>
        <div class="uf-sum-item"><div class="k">Submitted</div><div class="v"><?= h(fmt_dt($createdAt)) ?></div></div>
        <div class="uf-sum-item"><div class="k">Reviewed</div><div class="v"><?= h($reviewedAt !== '' ? fmt_dt($reviewedAt) : '—') ?></div></div>
      </div>

      <nav class="uf-tabs">
        <a href="#ufOverview" class="is-active">Overview</a>
        <a href="#ufApplicant">Applicant</a>
        <a href="#ufDecision">Decision</a>
        <a href="publisher_requests.php?status=pending">Pending (<?= (int)$pendingCount ?>)</a>
        <a href="publisher_requests.php?status=all">All (<?= (int)$totalCount ?>)</a>
        <a href="#ufNotes">Notes</a>
      </nav>

      <div class="uf-board" id="ufOverview">
        <!-- LEFT -->
        <div class="uf-col">
          <section class="uf-card" style="flex:0 0 auto;">
            <div class="uf-card-hd"><h2>Request Summary</h2></div>
            <div class="uf-card-bd">
              <div class="uf-kv"><span class="k">Publisher / brand</span><span class="v" title="<?= h($pubName) ?>"><?= h($pubName !== '' ? $pubName : '—') ?></span></div>
              <div class="uf-kv"><span class="k">Category</span><span class="v"><?= h($categoryLabel) ?></span></div>
              <div class="uf-kv"><span class="k">Request type</span><span class="v"><?= h($typeLabel) ?></span></div>
              <div class="uf-kv"><span class="k">Authority</span><span class="v"><?= $authorityConfirmed ? 'Confirmed' : 'Not confirmed' ?></span></div>
              <div class="uf-kv"><span class="k">Option ID</span><span class="v"><?= $optionId > 0 ? '#' . (int)$optionId : '—' ?></span></div>
            </div>
          </section>

          <section class="uf-card flex">
            <div class="uf-card-hd"><h2>Organization</h2></div>
            <div class="uf-card-bd scroll">
              <div class="uf-kv"><span class="k">Legal / org name</span><span class="v" title="<?= h($legalName) ?>"><?= h($legalName !== '' ? $legalName : '—') ?></span></div>
              <div class="uf-kv"><span class="k">Entity type</span><span class="v"><?= h($entityLabel !== '' ? $entityLabel : '—') ?></span></div>
              <div class="uf-kv"><span class="k">Registration ID</span><span class="v"><?= h($regId !== '' ? $regId : '—') ?></span></div>
              <div class="uf-kv"><span class="k">Country</span><span class="v"><?= h($regCountry !== '' ? $regCountry : '—') ?></span></div>
              <?php if ($isCommerce): ?>
                <div class="uf-note blue" style="margin-top:6px;">
                  <div class="meta">Commerce brand</div>
                  <?php if ($brandRow): ?>
                    Linked brand: <b><?= h((string)($brandRow['name'] ?? '')) ?></b> (#<?= (int)$brandId ?>)
                  <?php elseif ($brandId > 0): ?>
                    Brand ID #<?= (int)$brandId ?> (row missing)
                  <?php else: ?>
                    New commerce brand request — approving creates/links the seller brand.
                  <?php endif; ?>
                </div>
              <?php endif; ?>
            </div>
          </section>

          <section class="uf-card" style="flex:0 0 auto;" id="ufNotes">
            <div class="uf-card-hd"><h2>Request Note</h2></div>
            <div class="uf-card-bd">
              <div class="uf-note">
                <div class="meta">From applicant</div>
                <?= h($reqNote !== '' ? $reqNote : 'No note provided.') ?>
              </div>
            </div>
          </section>
        </div>

        <!-- MIDDLE -->
        <div class="uf-col" id="ufApplicant">
          <section class="uf-card flex">
            <div class="uf-card-hd"><h2>Applicant</h2></div>
            <div class="uf-card-bd scroll">
              <div class="uf-kv"><span class="k">Username</span><span class="v"><?= h($applicantUsername !== '' ? $applicantUsername : '—') ?></span></div>
              <div class="uf-kv"><span class="k">Email</span><span class="v" title="<?= h($applicantEmail) ?>"><?= h($applicantEmail !== '' ? $applicantEmail : '—') ?></span></div>
              <div class="uf-kv"><span class="k">Contact name</span><span class="v"><?= h($contactName !== '' ? $contactName : '—') ?></span></div>
              <div class="uf-kv"><span class="k">Contact email</span><span class="v" title="<?= h($contactEmail) ?>"><?= h($contactEmail !== '' ? $contactEmail : '—') ?></span></div>
            </div>
          </section>

          <section class="uf-card flex">
            <div class="uf-card-hd"><h2>Timeline</h2></div>
            <div class="uf-card-bd scroll">
              <div class="uf-tl">
                <div class="uf-tl-item">
                  <div class="uf-tl-dot"></div>
                  <div class="uf-tl-when"><?= h(fmt_dt($createdAt)) ?></div>
                  <div class="uf-tl-text">Request submitted</div>
                </div>
                <?php if ($status === 'approved' || $status === 'rejected'): ?>
                  <div class="uf-tl-item">
                    <div class="uf-tl-dot <?= $status === 'approved' ? 'ok' : 'bad' ?>"></div>
                    <div class="uf-tl-when"><?= h(fmt_dt($reviewedAt)) ?></div>
                    <div class="uf-tl-text">
                      <?= $status === 'approved' ? 'Approved' : 'Rejected' ?>
                      <?php if ($reviewerName !== ''): ?> by <?= h($reviewerName) ?><?php endif; ?>
                    </div>
                  </div>
                <?php else: ?>
                  <div class="uf-tl-item">
                    <div class="uf-tl-dot warn"></div>
                    <div class="uf-tl-when">Pending</div>
                    <div class="uf-tl-text">Awaiting admin decision</div>
                  </div>
                <?php endif; ?>
              </div>
            </div>
          </section>

          <section class="uf-card" style="flex:0 0 auto;">
            <div class="uf-card-hd"><h2>Review Note</h2></div>
            <div class="uf-card-bd">
              <?php if ($reviewNote !== ''): ?>
                <div class="uf-note muted">
                  <div class="meta"><?= h(fmt_dt($reviewedAt)) ?><?= $reviewerName !== '' ? ' · ' . h($reviewerName) : '' ?></div>
                  <?= h($reviewNote) ?>
                </div>
              <?php else: ?>
                <div class="uf-empty">No review note yet.</div>
              <?php endif; ?>
            </div>
          </section>
        </div>

        <!-- RIGHT -->
        <div class="uf-col" id="ufDecision">
          <section class="uf-card" style="flex:0 0 auto;">
            <div class="uf-card-hd"><h2>Completeness</h2></div>
            <div class="uf-card-bd">
              <div class="uf-risk-top">
                <span class="lab">Profile score</span>
                <span class="uf-badge <?= $completeness >= 80 ? 'ok' : ($completeness >= 50 ? 'warn' : 'bad') ?>"><?= (int)$completeness ?>%</span>
              </div>
              <div class="uf-gauge">
                <div class="uf-gauge-needle" style="--needle: <?= (int)($gaugeDeg - 90) ?>deg;"></div>
              </div>
              <div class="uf-gauge-score"><?= (int)$checkOk ?>/<?= (int)$checkTotal ?></div>
              <div class="uf-flags">
                <?php foreach ($checks as $label => $ok): ?>
                  <div class="uf-flag"><span class="dot <?= $ok ? 'ok' : 'bad' ?>"></span> <?= h($label) ?></div>
                <?php endforeach; ?>
              </div>
            </div>
          </section>

          <section class="uf-card flex">
            <div class="uf-card-hd"><h2>Decision</h2></div>
            <div class="uf-card-bd">
              <?php if ($status === 'pending'): ?>
                <form method="post" class="uf-form" id="prdDecisionForm" autocomplete="off">
                  <input type="hidden" name="request_id" value="<?= (int)$requestId ?>">
                  <input type="hidden" name="list_status" value="<?= h($listStatus) ?>">
                  <div class="uf-field">
                    <label>Review note (optional)</label>
                    <textarea name="review_note" placeholder="Add a note for audit / applicant"><?= h($reviewNote) ?></textarea>
                  </div>
                  <div class="uf-actions">
                    <button type="submit" name="approve_request" value="1" class="uf-btn ok"><i class="fa fa-check"></i> Approve</button>
                    <button type="submit" name="reject_request" value="1" class="uf-btn bad"><i class="fa fa-times"></i> Reject</button>
                  </div>
                </form>
              <?php else: ?>
                <div class="uf-note <?= $status === 'approved' ? 'blue' : 'muted' ?>">
                  <div class="meta">Final decision</div>
                  <b><?= h(ucfirst($status)) ?></b><br>
                  Reviewed <?= h(fmt_dt($reviewedAt)) ?>
                  <?php if ($reviewerName !== ''): ?><br>By <?= h($reviewerName) ?><?php endif; ?>
                </div>
                <div class="uf-actions" style="margin-top:8px;">
                  <a class="uf-btn" href="<?= h($listHref) ?>">Back to list</a>
                  <?php if ($nextId > 0): ?>
                    <a class="uf-btn primary" href="publisher_request_detail.php?id=<?= (int)$nextId ?>&amp;<?= h($detailQs) ?>">Next request</a>
                  <?php endif; ?>
                </div>
              <?php endif; ?>
            </div>
          </section>

          <section class="uf-card" style="flex:0 0 auto;">
            <div class="uf-card-hd"><h2>Quick Actions</h2></div>
            <div class="uf-card-bd">
              <div class="uf-quick">
                <?php if ($status === 'pending'): ?>
                  <button type="submit" form="prdDecisionForm" name="approve_request" value="1" class="uf-qbtn green"><i class="fa fa-check"></i> Approve</button>
                  <button type="submit" form="prdDecisionForm" name="reject_request" value="1" class="uf-qbtn red"><i class="fa fa-times"></i> Reject</button>
                <?php else: ?>
                  <a class="uf-qbtn green is-disabled" href="#"><i class="fa fa-check"></i> Decided</a>
                  <a class="uf-qbtn blue" href="<?= h($listHref) ?>"><i class="fa fa-list"></i> Back</a>
                <?php endif; ?>
                <a class="uf-qbtn orange" href="publisher_requests.php?status=pending"><i class="fa fa-hourglass-half"></i> Pending</a>
                <a class="uf-qbtn blue" href="userlist.php?kind=<?= $isCommerce ? 'commerce' : 'publisher' ?>"><i class="fa fa-users"></i> <?= h($typeLabel) ?>s</a>
                <a class="uf-qbtn<?= $prevId <= 0 ? ' is-disabled' : '' ?>" href="<?= $prevId > 0 ? 'publisher_request_detail.php?id=' . $prevId . '&amp;' . h($detailQs) : '#' ?>"><i class="fa fa-chevron-left"></i> Previous</a>
                <a class="uf-qbtn<?= $nextId <= 0 ? ' is-disabled' : '' ?>" href="<?= $nextId > 0 ? 'publisher_request_detail.php?id=' . $nextId . '&amp;' . h($detailQs) : '#' ?>"><i class="fa fa-chevron-right"></i> Next</a>
              </div>
            </div>
          </section>
        </div>
      </div>

    </div>
  </div>
</div>

<script src="../lib/jquery/jquery.js"></script>
<script src="../lib/bootstrap/bootstrap.js"></script>
<script>
document.addEventListener('click', function(e){
  var drop = document.getElementById('ufActionsDrop');
  if (!drop) return;
  if (!drop.contains(e.target)) drop.classList.remove('open');
});
</script>
</body>
</html>
