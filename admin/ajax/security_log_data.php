<?php
// admin/ajax/security_log_data.php
declare(strict_types=1);

require_once __DIR__ . '/../includes/session_admin.php';
requireAdminLogin();

require_once __DIR__ . '/../includes/identity.php';
if (!isAdmin()) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'forbidden']);
    exit;
}

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/../controller.php';

$controller = new Controller();
$dbh = $controller->pdo();

/* -----------------------------
   DataTables params
----------------------------- */
$draw  = (int)($_GET['draw'] ?? 1);
$start = (int)($_GET['start'] ?? 0);
$len   = (int)($_GET['length'] ?? 25);

if ($len <= 0) $len = 25;
if ($len > 500) $len = 500; // safety

$searchValue = trim((string)($_GET['search']['value'] ?? ''));

/* -----------------------------
   Custom filters (from your UI)
----------------------------- */
$email    = trim((string)($_GET['email'] ?? ''));
$action   = trim((string)($_GET['action'] ?? ''));
$success  = trim((string)($_GET['success'] ?? '')); // "1" or "0" or ""
$dateFrom = trim((string)($_GET['date_from'] ?? '')); // YYYY-MM-DD
$dateTo   = trim((string)($_GET['date_to'] ?? ''));

$where  = [];
$params = [];

/* -----------------------------
   Filters
----------------------------- */
if ($email !== '') {
    $where[] = "email LIKE :email";
    $params[':email'] = "%{$email}%";
}

if ($action !== '') {
    $where[] = "action = :action";
    $params[':action'] = $action;
}

if ($success !== '' && ($success === '0' || $success === '1')) {
    $where[] = "success = :success";
    $params[':success'] = (int)$success;
}

// Date range (inclusive)
if ($dateFrom !== '') {
    $where[] = "DATE(created_at) >= :df";
    $params[':df'] = $dateFrom;
}
if ($dateTo !== '') {
    $where[] = "DATE(created_at) <= :dt";
    $params[':dt'] = $dateTo;
}

/* -----------------------------
   Global search (DataTables)
----------------------------- */
if ($searchValue !== '') {
    $where[] = "(email LIKE :q OR username LIKE :q OR action LIKE :q OR ip LIKE :q OR meta LIKE :q OR user_agent LIKE :q)";
    $params[':q'] = "%{$searchValue}%";
}

$whereSql = $where ? ("WHERE " . implode(" AND ", $where)) : "";

/* -----------------------------
   Ordering (safe allow-list)
----------------------------- */
$allowedCols = [
    0 => 'id',
    1 => 'created_at',
    2 => 'email',
    3 => 'admin_id',
    4 => 'action',
    5 => 'success',
    6 => 'ip',
    7 => 'user_agent',
    8 => 'meta',
];

$orderColIndex = (int)($_GET['order'][0]['column'] ?? 1);
$orderDirRaw   = strtolower((string)($_GET['order'][0]['dir'] ?? 'desc'));
$orderDir      = ($orderDirRaw === 'asc') ? 'ASC' : 'DESC';

$orderCol = $allowedCols[$orderColIndex] ?? 'created_at';
$orderSql = "ORDER BY {$orderCol} {$orderDir}, id DESC";

/* -----------------------------
   Totals
----------------------------- */
try {
    $total = (int)$dbh->query("SELECT COUNT(*) FROM security_audit_log")->fetchColumn();

    $stCount = $dbh->prepare("SELECT COUNT(*) FROM security_audit_log {$whereSql}");
    foreach ($params as $k => $v) {
        $stCount->bindValue($k, $v);
    }
    $stCount->execute();
    $filtered = (int)$stCount->fetchColumn();

    /* -----------------------------
       Fetch data
    ----------------------------- */
    $sql = "
        SELECT id, created_at, email, username, admin_id, action, success, ip, user_agent, meta
        FROM security_audit_log
        {$whereSql}
        {$orderSql}
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

    // ✅ IMPORTANT: return RAW success (0/1) and sanitize text fields safely
    foreach ($rows as &$r) {
        $r['id'] = (int)($r['id'] ?? 0);
        $r['admin_id'] = isset($r['admin_id']) ? (int)$r['admin_id'] : null;

        // keep created_at as string (DataTables sorts by server-side order anyway)
        $r['created_at'] = (string)($r['created_at'] ?? '');

        $r['email'] = (string)($r['email'] ?? '');
        $r['username'] = (string)($r['username'] ?? '');
        $r['action'] = (string)($r['action'] ?? '');
        $r['ip'] = (string)($r['ip'] ?? '');
        $r['user_agent'] = (string)($r['user_agent'] ?? '');
        $r['meta'] = (string)($r['meta'] ?? '');

        // Return success as 0/1 so JS can render pills
        $r['success'] = ((int)($r['success'] ?? 0) === 1) ? 1 : 0;
    }
    unset($r);

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'draw'            => $draw,
        'recordsTotal'    => $total,
        'recordsFiltered' => $filtered,
        'data'            => $rows,
    ]);
    exit;

} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'draw' => $draw,
        'recordsTotal' => 0,
        'recordsFiltered' => 0,
        'data' => [],
        'error' => 'Server error: ' . $e->getMessage(),
    ]);
    exit;
}
