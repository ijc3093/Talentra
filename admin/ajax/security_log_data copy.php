<?php
require_once __DIR__ . '/../includes/session_admin.php';
requireAdminLogin();

require_once __DIR__ . '/../includes/identity.php';
if (!isAdmin()) {
    http_response_code(403);
    echo json_encode(['error' => 'forbidden']);
    exit;
}

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/../controller.php';

$controller = new Controller();
$dbh = $controller->pdo();

$draw  = (int)($_GET['draw'] ?? 1);
$start = (int)($_GET['start'] ?? 0);
$len   = (int)($_GET['length'] ?? 25);

$searchValue = trim($_GET['search']['value'] ?? '');

$email   = trim($_GET['email'] ?? '');
$action  = trim($_GET['action'] ?? '');
$success = trim($_GET['success'] ?? '');
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo   = trim($_GET['date_to'] ?? '');

$where = [];
$params = [];

// Filters
if ($email !== '') {
    $where[] = "email LIKE :email";
    $params[':email'] = "%{$email}%";
}
if ($action !== '') {
    $where[] = "action = :action";
    $params[':action'] = $action;
}
if ($success !== '') {
    $where[] = "success = :success";
    $params[':success'] = (int)$success;
}
if ($dateFrom !== '') {
    $where[] = "DATE(created_at) >= :df";
    $params[':df'] = $dateFrom;
}
if ($dateTo !== '') {
    $where[] = "DATE(created_at) <= :dt";
    $params[':dt'] = $dateTo;
}

// DataTables global search
if ($searchValue !== '') {
    $where[] = "(email LIKE :q OR username LIKE :q OR action LIKE :q OR ip LIKE :q OR meta LIKE :q)";
    $params[':q'] = "%{$searchValue}%";
}

$whereSql = $where ? ("WHERE " . implode(" AND ", $where)) : "";

// total
$total = (int)$dbh->query("SELECT COUNT(*) FROM security_audit_log")->fetchColumn();

// filtered total
$stCount = $dbh->prepare("SELECT COUNT(*) FROM security_audit_log {$whereSql}");
$stCount->execute($params);
$filtered = (int)$stCount->fetchColumn();

// data
$sql = "
  SELECT id, created_at, email, username, admin_id, action, success, ip, user_agent, meta
  FROM security_audit_log
  {$whereSql}
  ORDER BY created_at DESC
  LIMIT :start, :len
";
$st = $dbh->prepare($sql);

// bind filters
foreach ($params as $k => $v) {
    $st->bindValue($k, $v);
}
$st->bindValue(':start', $start, PDO::PARAM_INT);
$st->bindValue(':len', $len, PDO::PARAM_INT);

$st->execute();
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

// format success column
foreach ($rows as &$r) {
    $r['success'] = ((int)$r['success'] === 1)
        ? '<span class="tag-ok">Success</span>'
        : '<span class="tag-bad">Failed</span>';
    $r['meta'] = $r['meta'] ? htmlspecialchars($r['meta']) : '';
}

echo json_encode([
    "draw" => $draw,
    "recordsTotal" => $total,
    "recordsFiltered" => $filtered,
    "data" => $rows
]);
