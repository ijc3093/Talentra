<?php
/**
 * admin/userlist.php
 * ✅ Admin-only
 * ✅ Fixed page (no scroll)
 * ✅ card-body fixed
 * ✅ ONLY table rows scroll
 * ✅ Two-letter avatar per row (fallback)
 * ✅ Delete ONE + Delete ALL with modals
 * ✅ Confirm/Unconfirm modal
 */
require_once __DIR__ . '/includes/session_admin.php';
requireAdminLogin();

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/controller.php';
require_once __DIR__ . '/includes/user_admin_helpers_load.php';

$adminLogin = $_SESSION['admin_login'] ?? '';
$adminRole  = (int)($_SESSION['userRole'] ?? 0);
$isAdmin    = ($adminRole === 1);

$controller = new Controller();
$dbh = $controller->pdo();

if (!$isAdmin) {
    header('Location: dashboard.php');
    exit;
}

$msg = trim((string)($_GET['msg'] ?? ''));
$error = '';
$createdFriendCode = trim((string)($_GET['fc'] ?? ''));

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function fmt_dt($dt): string {
    if (!$dt) return 'N/A';
    $ts = strtotime((string)$dt);
    if (!$ts) return (string)$dt;
    return date('M j, Y g:i A', $ts);
}

function initials2(string $name): string {
    $name = trim(preg_replace('/\s+/', ' ', $name) ?? $name);
    if ($name === '') return '??';
    $name = str_replace(['_', '.', '-', '@'], ' ', $name);
    $parts = array_values(array_filter(explode(' ', $name), fn($p)=>trim($p) !== ''));
    if (!$parts) return '??';
    $first = mb_strtoupper(mb_substr($parts[0], 0, 1));
    $second = '';
    if (count($parts) > 1) $second = mb_strtoupper(mb_substr($parts[count($parts)-1], 0, 1));
    else $second = mb_strtoupper(mb_substr($parts[0], 1, 1));
    $ini = trim($first.$second);
    return $ini !== '' ? $ini : '??';
}

function avatarColor(string $key): string {
    $key = strtolower(trim($key));
    $hash = crc32($key);
    $palette = ['#2563eb','#7c3aed','#db2777','#ea580c','#16a34a','#0f766e','#0891b2','#475569'];
    return $palette[$hash % count($palette)];
}

function userlist_row_is_publisher(object $row): bool
{
    return userlist_row_kind($row) === 'publisher';
}

function userlist_row_is_commerce(object $row): bool
{
    return userlist_row_kind($row) === 'commerce';
}

/** @return 'personal'|'publisher'|'commerce' */
function userlist_row_kind(object $row): string
{
    $accountKind = strtolower(trim((string)($row->account_kind ?? 'personal')));
    $category = strtolower(trim((string)($row->publisher_category ?? '')));
    $friendCode = strtoupper(trim((string)($row->friend_code ?? '')));

    if ($accountKind === 'commerce' || $category === 'commerce') {
        return 'commerce';
    }
    if ($accountKind === 'publisher' || strpos($friendCode, 'PUB-') === 0) {
        return 'publisher';
    }
    return 'personal';
}

// -----------------------------
// DELETE ONE USER (POST from modal)
// -----------------------------
if (isset($_POST['delete_user'])) {
    $id   = (int)($_POST['delete_id'] ?? 0);
    $email = trim((string)($_POST['delete_email'] ?? ''));
    $username = trim((string)($_POST['delete_username'] ?? ''));

    $result = user_admin_delete_one($dbh, $id, $email);
    if (!empty($result['ok'])) {
        $msg = 'User deleted successfully.';
    } else {
        $error = (string)($result['error'] ?? 'Delete failed.');
    }
}

// -----------------------------
// BULK DELETE SELECTED
// -----------------------------
if (isset($_POST['bulk_delete'])) {
    $bulkIds = trim((string)($_POST['bulk_ids'] ?? ''));
    $ids = [];
    foreach (explode(',', $bulkIds) as $rawId) {
        $id = (int)$rawId;
        if ($id > 0) {
            $ids[] = $id;
        }
    }
    $ids = array_values(array_unique($ids));
    $okN = 0;
    $fail = '';
    foreach ($ids as $id) {
        $result = user_admin_delete_one($dbh, $id, '');
        if (!empty($result['ok'])) {
            $okN++;
        } elseif ($fail === '') {
            $fail = (string)($result['error'] ?? 'Delete failed.');
        }
    }
    if ($okN > 0) {
        $msg = $okN === 1 ? 'User deleted successfully.' : ($okN . ' users deleted successfully.');
    } else {
        $error = $fail !== '' ? $fail : 'Delete failed.';
    }
}

// -----------------------------
// DELETE ALL USERS (POST from modal)
// -----------------------------
if (isset($_POST['delete_all'])) {
    try {
        $registryPath = __DIR__ . '/../public_user/includes/deleted_user_registry.php';
        if (is_file($registryPath)) {
            require_once $registryPath;
            user_deleteduser_ensure_schema($dbh);
        }

        $all = $dbh->query('
            SELECT email, username, friend_code, name, mobile,
                   COALESCE(account_kind, \'personal\') AS account_kind
            FROM users
        ')->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($all) && org_admin_table_exists($dbh, 'deleteduser')) {
            foreach ($all as $row) {
                $em = trim((string)($row['email'] ?? ''));
                $un = trim((string)($row['username'] ?? ''));
                $fc = trim((string)($row['friend_code'] ?? ''));
                $dn = trim((string)($row['name'] ?? ''));
                $mobile = trim((string)($row['mobile'] ?? ''));
                $kind = strtolower(trim((string)($row['account_kind'] ?? 'personal')));
                if ($em === '' && $un === '' && $fc === '' && $dn === '' && $mobile === '') {
                    continue;
                }
                if (function_exists('user_record_deleted_account')) {
                    user_record_deleted_account($dbh, $em, $un, $fc, $dn, $mobile, $kind);
                } elseif ($em !== '') {
                    $ins = $dbh->prepare('INSERT INTO deleteduser (email) VALUES (:email)');
                    $ins->execute([':email' => $em]);
                }
            }
        }

        $dbh->beginTransaction();

        $delAll = $dbh->prepare('DELETE FROM users');
        $delAll->execute();

        $dbh->commit();
        $msg = 'All users deleted successfully.';
    } catch (Throwable $e) {
        if ($dbh->inTransaction()) $dbh->rollBack();
        $error = 'Database error: ' . $e->getMessage();
    }
}

// -----------------------------
// CONFIRM / UNCONFIRM (POST from modal)
// status: 1 = confirmed, 0 = unconfirmed (your existing logic)
// -----------------------------
if (isset($_POST['set_status'])) {
    $uid = (int)($_POST['status_id'] ?? 0);
    $newStatus = (int)($_POST['status_value'] ?? 0) === 1 ? 1 : 0;
    $bulkIds = trim((string)($_POST['bulk_ids'] ?? ''));
    $ids = [];
    if ($bulkIds !== '') {
        foreach (explode(',', $bulkIds) as $rawId) {
            $id = (int)$rawId;
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        $ids = array_values(array_unique($ids));
    } elseif ($uid > 0) {
        $ids = [$uid];
    }

    $okN = 0;
    $fail = '';
    foreach ($ids as $id) {
        $result = user_admin_set_user_status($dbh, $id, $newStatus);
        if (!empty($result['ok'])) {
            $okN++;
        } elseif ($fail === '') {
            $fail = (string)($result['error'] ?? 'Status update failed.');
        }
    }
    if ($okN > 0) {
        $msg = $newStatus === 1
            ? ($okN === 1 ? 'Account activated. User can sign in again.' : ($okN . ' accounts activated.'))
            : ($okN === 1 ? 'Account suspended. User cannot sign in.' : ($okN . ' accounts suspended.'));
    } else {
        $error = $fail !== '' ? $fail : 'Status update failed.';
    }
}

// -----------------------------
// FETCH USERS
// -----------------------------
try {
    $sql = "SELECT id, name, username, email, gender, mobile, designation, image, status,
                   account_kind, friend_code, created_at, last_seen,
                   COALESCE(policy_agreed, 0) AS policy_agreed,
                   COALESCE(age_confirmed, 0) AS age_confirmed,
                   COALESCE(publisher_category, '') AS publisher_category
            FROM users
            ORDER BY created_at DESC";
    $query = $dbh->prepare($sql);
    $query->execute();
    $results = $query->fetchAll(PDO::FETCH_OBJ);
} catch (Throwable $e) {
    // Older schemas may not have publisher_category / last_seen / policy yet.
    try {
        $sql = "SELECT id, name, username, email, gender, mobile, designation, image, status,
                       account_kind, friend_code, created_at
                FROM users
                ORDER BY created_at DESC";
        $query = $dbh->prepare($sql);
        $query->execute();
        $results = $query->fetchAll(PDO::FETCH_OBJ);
        foreach ($results as $row) {
            if (!isset($row->publisher_category)) {
                $row->publisher_category = '';
            }
            if (!isset($row->last_seen)) {
                $row->last_seen = null;
            }
            if (!isset($row->policy_agreed)) {
                $row->policy_agreed = 0;
            }
            if (!isset($row->age_confirmed)) {
                $row->age_confirmed = 0;
            }
        }
    } catch (Throwable $e2) {
        $results = [];
        $error = "Database error: " . $e2->getMessage();
    }
}

$personalCount = 0;
$publisherCount = 0;
$commerceCount = 0;
foreach ($results as $countRow) {
    $kind = userlist_row_kind($countRow);
    if ($kind === 'commerce') {
        $commerceCount++;
    } elseif ($kind === 'publisher') {
        $publisherCount++;
    } else {
        $personalCount++;
    }
}

$listKind = strtolower(trim((string)($_GET['kind'] ?? 'personal')));
if (!in_array($listKind, ['personal', 'publisher', 'commerce'], true)) {
    $listKind = 'personal';
}

$listStatus = strtolower(trim((string)($_GET['status'] ?? 'all')));
if (!in_array($listStatus, ['all', 'active', 'suspended', 'pending'], true)) {
    $listStatus = 'all';
}

$totalUsers = count($results);
$activeUsers = 0;
$suspendedUsers = 0;
$verifiedUsers = 0;
$pendingVerifyUsers = 0;
$newUsers7d = 0;
$newUsersPrev7d = 0;
$cutoff7 = strtotime('-7 days');
$cutoff14 = strtotime('-14 days');
foreach ($results as $statRow) {
    $st = (int)($statRow->status ?? 0);
    if ($st === 1) {
        $activeUsers++;
    } else {
        $suspendedUsers++;
    }
    $verified = (int)($statRow->policy_agreed ?? 0) === 1;
    if ($verified) {
        $verifiedUsers++;
    } else {
        $pendingVerifyUsers++;
    }
    $cts = strtotime((string)($statRow->created_at ?? ''));
    if ($cts && $cts >= $cutoff7) {
        $newUsers7d++;
    } elseif ($cts && $cts >= $cutoff14 && $cts < $cutoff7) {
        $newUsersPrev7d++;
    }
}

$pctTrend = static function (int $cur, int $prev): array {
    if ($prev <= 0) {
        return [$cur > 0 ? 100.0 : 0.0, $cur >= $prev ? 'up' : 'down'];
    }
    $pct = (($cur - $prev) / $prev) * 100;
    return [round($pct, 1), $pct >= 0 ? 'up' : 'down'];
};
[$newTrendPct, $newTrendDir] = $pctTrend($newUsers7d, $newUsersPrev7d);
$activeTrendPct = $totalUsers > 0 ? round(($activeUsers / $totalUsers) * 100, 1) : 0.0;
$suspendedTrendPct = $totalUsers > 0 ? round(($suspendedUsers / $totalUsers) * 100, 1) : 0.0;
$pendingTrendPct = $totalUsers > 0 ? round(($pendingVerifyUsers / $totalUsers) * 100, 1) : 0.0;

$visibleSeedCount = $listKind === 'personal' ? $personalCount
    : ($listKind === 'publisher' ? $publisherCount : $commerceCount);

// Lightweight per-user metrics (optional tables)
$postsByUser = [];
$reportsByUser = [];
$followersByUser = [];
$userIds = [];
foreach ($results as $r) {
    $uid = (int)($r->id ?? 0);
    if ($uid > 0) {
        $userIds[] = $uid;
    }
}
$userIds = array_values(array_unique($userIds));
$accountEventsByUser = [];
if ($userIds) {
    try {
        require_once dirname(__DIR__) . '/public_user/includes/account_admin_events.php';
        $accountEventsByUser = account_admin_events_latest_by_users($dbh, $userIds);
    } catch (Throwable $e) {
        $accountEventsByUser = [];
    }
}
if ($userIds && function_exists('org_admin_table_exists')) {
    $chunk = array_chunk($userIds, 400);
    try {
        if (org_admin_table_exists($dbh, 'public_posts')) {
            foreach ($chunk as $ids) {
                $in = implode(',', array_map('intval', $ids));
                $q = $dbh->query("SELECT user_id, COUNT(*) AS c FROM public_posts WHERE user_id IN ($in) AND (is_deleted = 0 OR is_deleted IS NULL) GROUP BY user_id");
                if ($q) {
                    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
                        $postsByUser[(int)$row['user_id']] = (int)$row['c'];
                    }
                }
            }
        }
        if (org_admin_table_exists($dbh, 'public_user_reports')) {
            foreach ($chunk as $ids) {
                $in = implode(',', array_map('intval', $ids));
                $q = $dbh->query("SELECT target_user_id AS user_id, COUNT(*) AS c FROM public_user_reports WHERE target_user_id IN ($in) GROUP BY target_user_id");
                if ($q) {
                    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
                        $reportsByUser[(int)$row['user_id']] = (int)$row['c'];
                    }
                }
            }
        }
        if (org_admin_table_exists($dbh, 'public_follows')) {
            foreach ($chunk as $ids) {
                $in = implode(',', array_map('intval', $ids));
                $q = $dbh->query("SELECT following_id AS user_id, COUNT(*) AS c FROM public_follows WHERE following_id IN ($in) GROUP BY following_id");
                if ($q) {
                    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
                        $followersByUser[(int)$row['user_id']] = (int)$row['c'];
                    }
                }
            }
        }
    } catch (Throwable $e) {
        // metrics are optional
    }
}

function userlist_rel_age(?string $dt): string {
    if (!$dt) {
        return '';
    }
    $ts = strtotime($dt);
    if (!$ts) {
        return '';
    }
    $diff = max(0, time() - $ts);
    $days = (int)floor($diff / 86400);
    if ($days < 1) {
        return 'today';
    }
    if ($days < 30) {
        return $days . ' day' . ($days === 1 ? '' : 's');
    }
    $months = (int)floor($days / 30);
    if ($months < 12) {
        return $months . ' month' . ($months === 1 ? '' : 's');
    }
    $years = (int)floor($months / 12);
    $rem = $months % 12;
    $out = $years . ' year' . ($years === 1 ? '' : 's');
    if ($rem > 0) {
        $out .= ', ' . $rem . ' month' . ($rem === 1 ? '' : 's');
    }
    return $out;
}

$addUserHref = 'user_form.php';
if ($listKind === 'commerce') {
    $addUserHref = 'user_form.php?account_kind=publisher&publisher_category=commerce';
} elseif ($listKind === 'publisher') {
    $addUserHref = 'user_form.php?account_kind=publisher';
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
  <title>Users</title>
  <link href="../lib/font-awesome/css/font-awesome.css" rel="stylesheet">
  <link href="../lib/Ionicons/css/ionicons.css" rel="stylesheet">
  <link href="../lib/perfect-scrollbar/css/perfect-scrollbar.css" rel="stylesheet">
  <link href="../lib/datatables/jquery.dataTables.css" rel="stylesheet">
  <link href="../lib/select2/css/select2.min.css" rel="stylesheet">
  <link rel="stylesheet" href="../css/shamcey.css">
  <link rel="stylesheet" href="css/admin-tables-shamcey.css?v=6">
  <style>
    html,body{height:100%;overflow:hidden;}
    .sh-mainpanel{height:100vh;display:flex;flex-direction:column;overflow:hidden;}
    .sh-mainpanel > .sh-pagebody{
      overflow:hidden !important;display:flex !important;flex-direction:column !important;min-height:0 !important;
      padding-top:8px !important;padding-bottom:8px !important;flex:1 1 auto;background:#f4f6fb;
    }
    .ul-wrap{
      flex:1 1 auto;min-height:0;width:100%;max-width:100%;
      display:flex;flex-direction:column;gap:8px;overflow:hidden;padding:0 2px;box-sizing:border-box;
    }
    .ul-top{flex:0 0 auto;display:flex;align-items:center;justify-content:flex-end;gap:10px;min-width:0;}
    .ul-actions{display:flex;gap:6px;flex-wrap:wrap;align-items:center;}
    .ul-btn{
      height:30px;padding:0 10px;border-radius:8px;border:1px solid #e2e8f0;background:#fff;
      font-size:11px;font-weight:700;color:#334155;display:inline-flex;align-items:center;gap:5px;
      text-decoration:none;cursor:pointer;white-space:nowrap;
    }
    .ul-btn:hover{background:#f8fafc;text-decoration:none;color:#0f172a;}
    .ul-btn.primary{background:#2563eb;border-color:#2563eb;color:#fff;}
    .ul-btn.primary:hover{background:#1d4ed8;color:#fff;}
    .ul-btn.danger{background:#fff;border-color:#fecaca;color:#b91c1c;}
    .ul-btn.danger:hover{background:#fef2f2;}
    .ul-btn:disabled{opacity:.45;pointer-events:none;}

    .ul-cards{
      flex:0 0 auto;display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:8px;
    }
    .ul-card{
      background:#fff;border:1px solid #eef2f7;border-radius:12px;padding:10px 12px;
      box-shadow:0 1px 2px rgba(15,23,42,.04);min-width:0;
    }
    .ul-card-top{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:6px;}
    .ul-card-top .lab{font-size:11px;font-weight:700;color:#64748b;}
    .ul-card-top .delta{font-size:10px;font-weight:800;}
    .ul-card-top .delta.up{color:#16a34a;}
    .ul-card-top .delta.down{color:#dc2626;}
    .ul-card-top .delta.flat{color:#94a3b8;}
    .ul-ico{
      width:28px;height:28px;border-radius:999px;display:flex;align-items:center;justify-content:center;font-size:12px;flex:0 0 auto;
    }
    .ul-ico.purple{background:#f5f3ff;color:#7c3aed;}
    .ul-ico.green{background:#f0fdf4;color:#16a34a;}
    .ul-ico.blue{background:#dbeafe;color:#2563eb;}
    .ul-ico.orange{background:#fff7ed;color:#ea580c;}
    .ul-ico.red{background:#fef2f2;color:#dc2626;}
    .ul-card .val{font-size:20px;font-weight:800;color:#0f172a;line-height:1;}
    .ul-card .sub{font-size:10px;color:#94a3b8;font-weight:600;margin-top:4px;}

    .ul-status-bar{
      flex:0 0 auto;display:flex;align-items:center;gap:8px;min-width:0;
    }
    .ul-status-tabs{
      flex:1 1 auto;display:flex;gap:0;background:#fff;border:1px solid #eef2f7;border-radius:10px;
      padding:0 4px;overflow:auto;min-width:0;
    }
    .ul-status-tabs a{
      flex:0 0 auto;padding:8px 12px;font-size:12px;font-weight:800;color:#64748b;text-decoration:none;
      border-bottom:2px solid transparent;white-space:nowrap;
    }
    .ul-status-tabs a .cnt{font-weight:700;color:#94a3b8;margin-left:4px;}
    .ul-status-tabs a.is-active{color:#2563eb;border-bottom-color:#2563eb;}
    .ul-status-tabs a:hover{color:#0f172a;text-decoration:none;}
    .ul-bulk{position:relative;flex:0 0 auto;}
    .ul-bulk-menu{
      display:none;position:absolute;right:0;top:calc(100% + 4px);z-index:20;min-width:160px;
      background:#fff;border:1px solid #e2e8f0;border-radius:10px;box-shadow:0 8px 24px rgba(15,23,42,.12);padding:4px;
    }
    .ul-bulk.is-open .ul-bulk-menu{display:block;}
    .ul-bulk-menu button{
      display:flex;width:100%;align-items:center;gap:8px;border:0;background:transparent;
      padding:8px 10px;border-radius:8px;font-size:12px;font-weight:700;color:#334155;text-align:left;cursor:pointer;
    }
    .ul-bulk-menu button:hover{background:#f8fafc;}
    .ul-bulk-menu button.danger{color:#b91c1c;}

    .ul-main{
      flex:1 1 auto;min-height:0;min-width:0;
      background:#fff;border:1px solid #eef2f7;border-radius:12px;overflow:hidden;
      box-shadow:0 1px 2px rgba(15,23,42,.04);
      display:flex;flex-direction:column;
    }
    .ul-filters{
      flex:0 0 auto;display:flex;gap:6px;flex-wrap:wrap;align-items:center;
      padding:10px 12px;border-bottom:1px solid #eef2f7;background:#fafbfc;
    }
    .ul-search{position:relative;flex:1 1 180px;min-width:140px;max-width:260px;}
    .ul-search i{position:absolute;left:9px;top:50%;transform:translateY(-50%);color:#94a3b8;font-size:12px;}
    .ul-search input,.ul-filters select{
      height:30px;border:1px solid #e2e8f0;border-radius:8px;padding:0 9px;font-size:11px;background:#fff;color:#0f172a;
    }
    .ul-search input{width:100%;padding-left:28px;}
    .ul-clear{font-size:11px;font-weight:700;color:#2563eb;text-decoration:none;display:inline-flex;align-items:center;gap:4px;margin-left:auto;}
    .ul-clear:hover{text-decoration:underline;}

    .ul-table-wrap{flex:1 1 auto;min-height:0;overflow-x:hidden;overflow-y:auto;overscroll-behavior:contain;}
    .ul-table{width:100%;table-layout:fixed;border-collapse:separate;border-spacing:0;min-width:0;}
    .ul-table th{
      text-align:left;font-size:9px;font-weight:800;letter-spacing:.03em;text-transform:uppercase;
      color:#64748b;padding:8px 6px;border-bottom:1px solid #eef2f7;background:#fff;
      position:sticky;top:0;z-index:3;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;
    }
    .ul-table td{
      padding:8px 6px;border-bottom:1px solid #f1f5f9;vertical-align:middle;font-size:11px;color:#0f172a;overflow:hidden;
    }
    .ul-table tr:hover td{background:#f8fafc;}
    .ul-table th:nth-child(1),.ul-table td:nth-child(1){width:28px;}
    .ul-table th:nth-child(2),.ul-table td:nth-child(2){width:16%;}
    .ul-table th:nth-child(3),.ul-table td:nth-child(3){width:70px;}
    .ul-table th:nth-child(4),.ul-table td:nth-child(4){width:12%;}
    .ul-table th:nth-child(5),.ul-table td:nth-child(5){width:78px;}
    .ul-table th:nth-child(6),.ul-table td:nth-child(6){width:86px;}
    .ul-table th:nth-child(7),.ul-table td:nth-child(7){width:92px;}
    .ul-table th:nth-child(8),.ul-table td:nth-child(8){width:56px;}
    .ul-table th:nth-child(9),.ul-table td:nth-child(9){width:12%;}
    .ul-table th:nth-child(10),.ul-table td:nth-child(10){width:12%;}
    .ul-table th:nth-child(11),.ul-table td:nth-child(11){width:72px;}

    .ul-id{color:#2563eb;font-weight:800;text-decoration:none;white-space:nowrap;}
    .ul-id:hover{text-decoration:underline;}
    .ul-user{display:flex;align-items:center;gap:8px;min-width:0;}
    .ul-av{
      width:28px;height:28px;border-radius:999px;color:#fff;font-size:10px;font-weight:800;
      display:flex;align-items:center;justify-content:center;flex:0 0 28px;
    }
    .ul-user .nm{font-weight:800;font-size:11px;color:#0f172a;line-height:1.2;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
    .ul-user .un{font-size:10px;color:#64748b;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
    .ul-uname{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:#334155;font-weight:600;}
    .ul-role,.ul-status,.ul-verify{
      display:inline-flex;align-items:center;gap:4px;padding:2px 7px;border-radius:999px;font-size:10px;font-weight:800;white-space:nowrap;
    }
    .ul-role.personal{background:#dbeafe;color:#1d4ed8;}
    .ul-role.publisher{background:#ede9fe;color:#6d28d9;}
    .ul-role.commerce,.ul-role.seller{background:#fee2e2;color:#b91c1c;}
    .ul-verify.ok{background:#dbeafe;color:#1d4ed8;}
    .ul-verify.pending{background:#fef3c7;color:#b45309;}
    .ul-verify.no{background:#f1f5f9;color:#64748b;}
    .ul-acts{display:flex;align-items:center;gap:4px;}
    .ul-act{
      width:26px;height:26px;border-radius:7px;border:1px solid #e2e8f0;background:#fff;color:#64748b;
      display:inline-flex;align-items:center;justify-content:center;text-decoration:none;
    }
    .ul-act:hover{background:#f8fafc;color:#0f172a;text-decoration:none;}
    .ul-kinds{
      flex:0 0 auto;display:flex;gap:0;background:#fff;border:1px solid #eef2f7;border-radius:10px;
      padding:0 4px;overflow:hidden;min-width:0;
    }
    .ul-kinds a{
      flex:0 0 auto;padding:8px 14px;font-size:12px;font-weight:800;color:#64748b;text-decoration:none;
      border-bottom:2px solid transparent;white-space:nowrap;
    }
    .ul-kinds a .cnt{font-weight:700;color:#94a3b8;margin-left:4px;}
    .ul-kinds a.is-active{color:#2563eb;border-bottom-color:#2563eb;}
    .ul-kinds a:hover{color:#0f172a;text-decoration:none;}
    .ul-status.active{background:#dcfce7;color:#15803d;}
    .ul-status.suspended{background:#ffedd5;color:#c2410c;}
    .ul-status.left{background:#fee2e2;color:#991b1b;}
    .ul-status .dot{width:6px;height:6px;border-radius:999px;background:currentColor;}
    .ul-when{font-size:10px;color:#475569;line-height:1.25;}
    .ul-when span{display:block;color:#94a3b8;font-size:9px;}
    .ul-num{font-weight:700;color:#0f172a;font-variant-numeric:tabular-nums;}
    .ul-alert{flex:0 0 auto;padding:7px 9px;border-radius:8px;font-size:12px;font-weight:700;}
    .ul-alert.ok{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0;}
    .ul-alert.bad{background:#fef2f2;color:#991b1b;border:1px solid #fecaca;}
    .ul-foot{
      flex:0 0 auto;display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;
      padding:10px 12px;border-top:1px solid #eef2f7;background:#fff;
    }
    .ul-foot .muted{font-size:11px;color:#64748b;font-weight:600;}
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
    #datatable1_wrapper{display:contents;}
    #datatable1{width:100% !important;margin:0 !important;}
    @media (max-width:1100px){
      .ul-wrap{overflow:auto;}
      .ul-cards{grid-template-columns:repeat(2,minmax(0,1fr));}
      .ul-status-bar{flex-wrap:wrap;}
    }
  </style>
</head>
<body>
<?php
$adminChromePageIntro = [
    'title' => 'User Management',
    'description' => 'Manage users, monitor activity, and control access across the platform.',
];
include('includes/leftbar.php');
include('includes/header.php');
?>

<div class="sh-mainpanel">
  <div class="sh-pagebody">
    <div class="ul-wrap">

      <?php if ($error): ?>
        <div class="ul-alert bad"><?php echo h($error); ?></div>
      <?php elseif ($msg): ?>
        <div class="ul-alert ok">
          <?php echo h($msg); ?>
          <?php if ($createdFriendCode !== ''): ?>
            <span style="margin-left:8px;">Friend code: <b><?php echo h($createdFriendCode); ?></b></span>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <div class="ul-top">
        <div class="ul-actions">
          <button type="button" class="ul-btn" title="Export coming soon"><i class="fa fa-download"></i> Export</button>
          <button type="button" class="ul-btn" onclick="document.getElementById('ulFilters').scrollIntoView({behavior:'smooth',block:'center'})"><i class="fa fa-sliders"></i> Filters</button>
          <a class="ul-btn primary" href="<?php echo h($addUserHref); ?>" id="ulAddUserBtn"><i class="fa fa-plus"></i> Add User</a>
          <button type="button" class="ul-btn danger" <?php echo (count($results) === 0) ? 'disabled' : ''; ?> data-toggle="modal" data-target="#deleteAllModal"><i class="fa fa-trash"></i> Delete All</button>
        </div>
      </div>

      <div class="ul-cards">
        <div class="ul-card">
          <div class="ul-card-top">
            <div style="display:flex;align-items:center;gap:8px;">
              <div class="ul-ico blue"><i class="fa fa-users"></i></div>
              <div class="lab">Total Users</div>
            </div>
            <div class="delta flat">• all</div>
          </div>
          <div class="val"><?php echo number_format($totalUsers); ?></div>
          <div class="sub">All account types</div>
        </div>
        <div class="ul-card">
          <div class="ul-card-top">
            <div style="display:flex;align-items:center;gap:8px;">
              <div class="ul-ico green"><i class="fa fa-check-circle"></i></div>
              <div class="lab">Active Users</div>
            </div>
            <div class="delta up"><?php echo h((string)$activeTrendPct); ?>%</div>
          </div>
          <div class="val"><?php echo number_format($activeUsers); ?></div>
          <div class="sub">Can sign in</div>
        </div>
        <div class="ul-card">
          <div class="ul-card-top">
            <div style="display:flex;align-items:center;gap:8px;">
              <div class="ul-ico purple"><i class="fa fa-user-plus"></i></div>
              <div class="lab">New Users (7 Days)</div>
            </div>
            <div class="delta <?php echo h($newTrendDir); ?>"><?php echo ($newTrendDir === 'down' ? '' : '+') . h((string)$newTrendPct); ?>%</div>
          </div>
          <div class="val"><?php echo number_format($newUsers7d); ?></div>
          <div class="sub">vs previous 7 days</div>
        </div>
        <div class="ul-card">
          <div class="ul-card-top">
            <div style="display:flex;align-items:center;gap:8px;">
              <div class="ul-ico orange"><i class="fa fa-lock"></i></div>
              <div class="lab">Suspended Users</div>
            </div>
            <div class="delta <?php echo $suspendedUsers > 0 ? 'down' : 'flat'; ?>"><?php echo h((string)$suspendedTrendPct); ?>%</div>
          </div>
          <div class="val"><?php echo number_format($suspendedUsers); ?></div>
          <div class="sub">Cannot sign in</div>
        </div>
        <div class="ul-card">
          <div class="ul-card-top">
            <div style="display:flex;align-items:center;gap:8px;">
              <div class="ul-ico red"><i class="fa fa-exclamation-circle"></i></div>
              <div class="lab">Pending Verification</div>
            </div>
            <div class="delta <?php echo $pendingVerifyUsers > 0 ? 'down' : 'flat'; ?>"><?php echo h((string)$pendingTrendPct); ?>%</div>
          </div>
          <div class="val"><?php echo number_format($pendingVerifyUsers); ?></div>
          <div class="sub">Policy not accepted</div>
        </div>
      </div>

      <nav class="ul-kinds" id="ulKindTabs" aria-label="Account type">
        <a href="?kind=personal" data-kind="personal" class="<?php echo $listKind === 'personal' ? 'is-active' : ''; ?>">Personal <span class="cnt">(<?php echo (int)$personalCount; ?>)</span></a>
        <a href="?kind=publisher" data-kind="publisher" class="<?php echo $listKind === 'publisher' ? 'is-active' : ''; ?>">Publisher <span class="cnt">(<?php echo (int)$publisherCount; ?>)</span></a>
        <a href="?kind=commerce" data-kind="commerce" class="<?php echo $listKind === 'commerce' ? 'is-active' : ''; ?>">Seller <span class="cnt">(<?php echo (int)$commerceCount; ?>)</span></a>
      </nav>

      <div class="ul-status-bar">
        <nav class="ul-status-tabs" id="ulStatusTabs" aria-label="User status">
          <a href="#" data-status="all" class="<?php echo $listStatus === 'all' ? 'is-active' : ''; ?>">All Users<span class="cnt"><?php echo (int)$totalUsers; ?></span></a>
          <a href="#" data-status="active" class="<?php echo $listStatus === 'active' ? 'is-active' : ''; ?>">Active<span class="cnt"><?php echo (int)$activeUsers; ?></span></a>
          <a href="#" data-status="suspended" class="<?php echo $listStatus === 'suspended' ? 'is-active' : ''; ?>">Suspended<span class="cnt"><?php echo (int)$suspendedUsers; ?></span></a>
          <a href="#" data-status="pending" class="<?php echo $listStatus === 'pending' ? 'is-active' : ''; ?>">Pending Verification<span class="cnt"><?php echo (int)$pendingVerifyUsers; ?></span></a>
        </nav>
        <div class="ul-bulk" id="ulBulk">
          <button type="button" class="ul-btn" id="ulBulkToggle"><i class="fa fa-list"></i> Bulk Actions</button>
          <div class="ul-bulk-menu" role="menu">
            <button type="button" data-bulk="block"><i class="fa fa-ban"></i> Suspend selected</button>
            <button type="button" data-bulk="unblock"><i class="fa fa-check"></i> Activate selected</button>
            <button type="button" class="danger" data-bulk="delete"><i class="fa fa-trash"></i> Delete selected</button>
          </div>
        </div>
      </div>

      <div class="ul-main">
        <div class="ul-filters" id="ulFilters">
          <div class="ul-search">
            <i class="fa fa-search"></i>
            <input type="search" id="ulSearchInput" placeholder="Search by name, username, email or user ID..." autocomplete="off">
          </div>
          <select id="ulRoleFilter" aria-label="Account type">
            <option value="personal"<?php echo $listKind === 'personal' ? ' selected' : ''; ?>>Personal</option>
            <option value="publisher"<?php echo $listKind === 'publisher' ? ' selected' : ''; ?>>Publisher</option>
            <option value="commerce"<?php echo $listKind === 'commerce' ? ' selected' : ''; ?>>Seller</option>
          </select>
          <select id="ulStatusFilter" aria-label="Status">
            <option value="all"<?php echo $listStatus === 'all' ? ' selected' : ''; ?>>All Statuses</option>
            <option value="active"<?php echo $listStatus === 'active' ? ' selected' : ''; ?>>Active</option>
            <option value="suspended"<?php echo $listStatus === 'suspended' ? ' selected' : ''; ?>>Suspended</option>
          </select>
          <select id="ulVerifyFilter" aria-label="Verification">
            <option value="all">All Verification</option>
            <option value="verified">Verified</option>
            <option value="pending">Pending</option>
            <option value="unverified">Unverified</option>
          </select>
          <select id="ulRegisteredFilter" aria-label="Registered">
            <option value="all">All Registered</option>
            <option value="7d">Last 7 days</option>
            <option value="30d">Last 30 days</option>
            <option value="90d">Last 90 days</option>
          </select>
          <select id="ulPageLen" aria-label="Per page">
            <option value="10" selected>10 / page</option>
            <option value="25">25 / page</option>
            <option value="50">50 / page</option>
            <option value="100">100 / page</option>
          </select>
          <a href="#" class="ul-clear" id="ulClearFilters"><i class="fa fa-refresh"></i> Clear Filters</a>
        </div>

        <div class="ul-table-wrap">
          <table id="datatable1" class="ul-table display" style="width:100%;">
            <thead>
              <tr>
                <th><input type="checkbox" id="ulSelectAll" title="Select all on this page"></th>
                <th>User</th>
                <th>User ID</th>
                <th>Username</th>
                <th>Role</th>
                <th>Status</th>
                <th>Verification</th>
                <th>Followers</th>
                <th>Date Joined</th>
                <th>Last Active</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($results as $r):
                $uid = (int)($r->id ?? 0);
                $name = (string)($r->name ?? '');
                $email = (string)($r->email ?? '');
                $uname = (string)($r->username ?? '');
                $status = (int)($r->status ?? 0);
                $isActive = ($status === 1);
                $rowKind = userlist_row_kind($r);
                $created = (string)($r->created_at ?? '');
                $lastSeen = (string)($r->last_seen ?? '');
                $policyOk = (int)($r->policy_agreed ?? 0) === 1;
                $ageOk = (int)($r->age_confirmed ?? 0) === 1;
                $verifyKey = $policyOk ? 'verified' : ($ageOk ? 'pending' : 'unverified');
                $labelForIni = $name !== '' ? $name : ($email !== '' ? $email : 'User');
                $ini = initials2($labelForIni);
                $bg = avatarColor($email !== '' ? $email : ($name !== '' ? $name : (string)$uid));
                $followersN = (int)($followersByUser[$uid] ?? 0);
                $rel = userlist_rel_age($created);
                $statusKey = $isActive ? 'active' : 'suspended';
                $createdTs = strtotime($created) ?: 0;
                $latestAccountEv = $accountEventsByUser[$uid] ?? null;
                $latestAccountType = (string)($latestAccountEv['event_type'] ?? '');
                $latestAccountTitle = trim((string)($latestAccountEv['title'] ?? ''));
                $latestAccountNext = trim((string)($latestAccountEv['admin_next'] ?? ''));
              ?>
              <tr
                data-account-kind="<?php echo h($rowKind); ?>"
                data-status="<?php echo h($statusKey); ?>"
                data-verify="<?php echo h($verifyKey); ?>"
                data-created="<?php echo (int)$createdTs; ?>"
                data-user-id="<?php echo $uid; ?>"
              >
                <td><input type="checkbox" class="ul-row-check" value="<?php echo $uid; ?>"></td>
                <td>
                  <div class="ul-user">
                    <span class="ul-av" style="background:<?php echo h($bg); ?>;"><?php echo h($ini); ?></span>
                    <div style="min-width:0;">
                      <div class="nm"><?php echo h($name !== '' ? $name : '—'); ?></div>
                      <div class="un"><?php echo h($email !== '' ? $email : ($uname !== '' ? '@' . $uname : '—')); ?></div>
                    </div>
                  </div>
                </td>
                <td><a class="ul-id" href="user_form.php?user_id=<?php echo $uid; ?>">USR-<?php echo str_pad((string)$uid, 5, '0', STR_PAD_LEFT); ?></a></td>
                <td><div class="ul-uname"><?php echo h($uname !== '' ? $uname : '—'); ?></div></td>
                <td>
                  <?php if ($rowKind === 'commerce'): ?>
                    <span class="ul-role seller">Seller</span>
                  <?php elseif ($rowKind === 'publisher'): ?>
                    <span class="ul-role publisher">Publisher</span>
                  <?php else: ?>
                    <span class="ul-role personal">User</span>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if (!$isActive && in_array($latestAccountType, ['deactivate', 'delete'], true)): ?>
                    <span class="ul-status left" title="<?php echo h($latestAccountNext !== '' ? $latestAccountNext : $latestAccountTitle); ?>"><span class="dot"></span> Left / deactivated</span>
                  <?php elseif ($isActive): ?>
                    <span class="ul-status active"><span class="dot"></span> Active</span>
                  <?php else: ?>
                    <span class="ul-status suspended"><span class="dot"></span> Suspended</span>
                  <?php endif; ?>
                  <?php if ($latestAccountTitle !== '' && $isActive): ?>
                    <div class="ul-when"><span><?php echo h($latestAccountTitle); ?></span></div>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if ($verifyKey === 'verified'): ?>
                    <span class="ul-verify ok"><i class="fa fa-check-circle"></i> Verified</span>
                  <?php elseif ($verifyKey === 'pending'): ?>
                    <span class="ul-verify pending"><i class="fa fa-clock-o"></i> Pending</span>
                  <?php else: ?>
                    <span class="ul-verify no"><i class="fa fa-circle-o"></i> Unverified</span>
                  <?php endif; ?>
                </td>
                <td class="ul-num"><?php echo number_format($followersN); ?></td>
                <td>
                  <div class="ul-when">
                    <?php echo h(fmt_dt($created)); ?>
                    <?php if ($rel !== ''): ?><span><?php echo h($rel); ?></span><?php endif; ?>
                  </div>
                </td>
                <td>
                  <div class="ul-when">
                    <?php echo $lastSeen !== '' && $lastSeen !== '0000-00-00 00:00:00' ? h(fmt_dt($lastSeen)) : '—'; ?>
                  </div>
                </td>
                <td>
                  <div class="fries-menu">
                    <button type="button" class="fries-toggle" title="Actions" aria-label="Actions" aria-haspopup="true">
                      <span class="fries-icon" aria-hidden="true"></span>
                    </button>
                    <div class="fries-dropdown" role="menu">
                      <a class="fries-item" role="menuitem" href="user_form.php?user_id=<?php echo $uid; ?>">
                        <i class="fa fa-pencil"></i> Edit
                      </a>
                      <a class="fries-item" role="menuitem"
                         href="<?php echo h(user_admin_public_profile_href($uid)); ?>"
                         target="_blank" rel="noopener">
                        <i class="fa fa-eye"></i> View profile
                      </a>
                      <button type="button"
                              class="fries-item"
                              role="menuitem"
                              data-id="<?php echo $uid; ?>"
                              data-email="<?php echo h($email); ?>"
                              data-name="<?php echo h($name); ?>"
                              data-status="<?php echo $isActive ? '0' : '1'; ?>"
                              onclick="openStatusModal(this);">
                        <i class="fa <?php echo $isActive ? 'fa-ban' : 'fa-check'; ?>"></i>
                        <?php echo $isActive ? 'Block' : 'Unblock'; ?>
                      </button>
                      <button type="button"
                              class="fries-item fries-item-danger"
                              role="menuitem"
                              data-id="<?php echo $uid; ?>"
                              data-email="<?php echo h($email); ?>"
                              data-username="<?php echo h($uname); ?>"
                              data-name="<?php echo h($name); ?>"
                              onclick="openDeleteModal(this);">
                        <i class="fa fa-trash"></i> Delete
                      </button>
                    </div>
                  </div>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <div class="ul-foot">
          <div class="muted" id="ulShowing">Showing 0 users</div>
          <div id="ulPagerHost"></div>
          <div class="muted"><span id="visibleUserCount"><?php echo (int)$visibleSeedCount; ?></span> in this view</div>
        </div>
      </div>

    </div>
  </div>

  <div class="modal fade" id="deleteUserModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
      <div class="modal-content" style="border-radius:16px;overflow:hidden;">
        <form method="post">
          <div class="modal-header" style="background:rgba(239,68,68,.10);border-bottom:1px solid rgba(17,24,39,.10);">
            <h4 class="modal-title" style="font-weight:900;">
              <i class="fa fa-exclamation-triangle text-danger mg-r-6"></i> Confirm Delete
            </h4>
            <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
          </div>
          <div class="modal-body">
            <p style="margin-bottom:6px;">Delete this user permanently?</p>
            <p style="margin:0;"><b id="delUserName"></b></p>
            <p class="mono" style="opacity:.75;margin-top:6px;" id="delUserEmail"></p>
            <input type="hidden" name="delete_id" id="delUserId" value="">
            <input type="hidden" name="delete_email" id="delUserEmailHidden" value="">
            <input type="hidden" name="delete_username" id="delUserUsernameHidden" value="">
          </div>
          <div class="modal-footer" style="border-top:1px solid rgba(17,24,39,.10);">
            <button type="button" class="btn btn-light" data-dismiss="modal">Cancel</button>
            <button type="submit" name="delete_user" class="btn btn-danger">
              <i class="fa fa-trash mg-r-6"></i> Delete
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <div class="modal fade" id="deleteAllModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
      <div class="modal-content" style="border-radius:16px;overflow:hidden;">
        <form method="post">
          <div class="modal-header" style="background:rgba(239,68,68,.10);border-bottom:1px solid rgba(17,24,39,.10);">
            <h4 class="modal-title" style="font-weight:900;">
              <i class="fa fa-exclamation-triangle text-danger mg-r-6"></i> Delete ALL Users
            </h4>
            <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
          </div>
          <div class="modal-body">
            <p style="font-weight:800;">You are about to delete <b><?php echo (int)count($results); ?></b> user(s).</p>
            <p style="color:#b91c1c;font-weight:800;">This cannot be undone.</p>
          </div>
          <div class="modal-footer" style="border-top:1px solid rgba(17,24,39,.10);">
            <button type="button" class="btn btn-light" data-dismiss="modal">Cancel</button>
            <button type="submit" name="delete_all" class="btn btn-danger">
              <i class="fa fa-trash mg-r-6"></i> Delete All
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <div class="modal fade" id="statusModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
      <div class="modal-content" style="border-radius:16px;overflow:hidden;">
        <form method="post">
          <div class="modal-header" style="background:rgba(37,99,235,.10);border-bottom:1px solid rgba(17,24,39,.10);">
            <h4 class="modal-title" id="statusModalTitle" style="font-weight:900;">Block Account</h4>
            <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
          </div>
          <div class="modal-body">
            <p id="statusModalText" style="margin:0;"></p>
            <p class="mono" style="opacity:.75;margin-top:6px;" id="statusModalEmail"></p>
            <input type="hidden" name="status_id" id="statusUserId" value="">
            <input type="hidden" name="status_value" id="statusValue" value="">
          </div>
          <div class="modal-footer" style="border-top:1px solid rgba(17,24,39,.10);">
            <button type="button" class="btn btn-light" data-dismiss="modal">Cancel</button>
            <button type="submit" name="set_status" class="btn btn-primary" id="statusGoBtn">Continue</button>
          </div>
        </form>
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
  function openDeleteModal(btn){
    var id = btn.getAttribute('data-id');
    var email = btn.getAttribute('data-email') || '';
    var username = btn.getAttribute('data-username') || '';
    var name = btn.getAttribute('data-name') || 'User';
    document.getElementById('delUserName').textContent = name;
    document.getElementById('delUserEmail').textContent = email;
    document.getElementById('delUserId').value = id;
    document.getElementById('delUserEmailHidden').value = email;
    document.getElementById('delUserUsernameHidden').value = username;
    $('#deleteUserModal').modal('show');
  }

  function openStatusModal(btn){
    var id = btn.getAttribute('data-id');
    var email = btn.getAttribute('data-email') || '';
    var name = btn.getAttribute('data-name') || 'User';
    var st = btn.getAttribute('data-status');
    document.getElementById('statusUserId').value = id;
    document.getElementById('statusValue').value = st;
    if (st === '1') {
      document.getElementById('statusModalTitle').textContent = 'Unblock Account';
      document.getElementById('statusModalText').textContent = 'Allow sign-in again for: ' + name + '?';
      document.getElementById('statusGoBtn').className = 'btn btn-primary';
      document.getElementById('statusGoBtn').textContent = 'Unblock';
    } else {
      document.getElementById('statusModalTitle').textContent = 'Block Account';
      document.getElementById('statusModalText').textContent = 'Block sign-in and end active sessions for: ' + name + '?';
      document.getElementById('statusGoBtn').className = 'btn btn-warning';
      document.getElementById('statusGoBtn').textContent = 'Block';
    }
    document.getElementById('statusModalEmail').textContent = email;
    $('#statusModal').modal('show');
  }

  $(function() {
    'use strict';
    var activeKind = <?php echo json_encode($listKind, JSON_UNESCAPED_SLASHES); ?>;
    var activeStatus = <?php echo json_encode($listStatus, JSON_UNESCAPED_SLASHES); ?>;
    var activeVerify = 'all';
    var activeRegistered = 'all';
    var nowTs = Math.floor(Date.now() / 1000);

    $.fn.dataTable.ext.search.push(function(settings, data, dataIndex) {
      if (settings.nTable.id !== 'datatable1') return true;
      var row = settings.aoData[dataIndex].nTr;
      if (!row) return true;
      if (row.getAttribute('data-account-kind') !== activeKind) return false;

      var rowStatus = row.getAttribute('data-status') || '';
      var rowVerify = row.getAttribute('data-verify') || '';
      if (activeStatus === 'active' || activeStatus === 'suspended') {
        if (rowStatus !== activeStatus) return false;
      } else if (activeStatus === 'pending') {
        if (rowVerify === 'verified') return false;
      }

      if (activeVerify !== 'all' && rowVerify !== activeVerify) return false;

      if (activeRegistered !== 'all') {
        var created = parseInt(row.getAttribute('data-created') || '0', 10);
        var days = activeRegistered === '7d' ? 7 : (activeRegistered === '30d' ? 30 : 90);
        if (!created || created < (nowTs - days * 86400)) return false;
      }
      return true;
    });

    var dt = $('#datatable1').DataTable({
      paging: true,
      pageLength: 10,
      lengthChange: false,
      info: true,
      responsive: false,
      autoWidth: false,
      scrollX: false,
      order: [[2, 'desc']],
      columnDefs: [
        { orderable: false, targets: [0, 10] }
      ],
      dom: 'tp',
      language: {
        searchPlaceholder: 'Search users...',
        sSearch: '',
        paginate: { previous: '‹', next: '›' }
      },
      drawCallback: function() {
        var api = this.api();
        var info = api.page.info();
        var from = info.recordsDisplay === 0 ? 0 : (info.start + 1);
        var to = info.end;
        $('#ulShowing').text('Showing ' + from + ' to ' + to + ' of ' + info.recordsDisplay + ' users.');
        $('#visibleUserCount').text(info.recordsDisplay);
        var $pag = $(api.table().container()).find('.dataTables_paginate');
        if ($pag.length) {
          $('#ulPagerHost').empty().append($pag);
        }
        $('#ulSelectAll').prop('checked', false);
      }
    });

    setTimeout(function() {
      var $pag = $('#datatable1_paginate');
      if ($pag.length) $('#ulPagerHost').empty().append($pag);
    }, 0);

    function syncAddUserHref(kind) {
      var href = 'user_form.php';
      if (kind === 'commerce') href = 'user_form.php?account_kind=publisher&publisher_category=commerce';
      else if (kind === 'publisher') href = 'user_form.php?account_kind=publisher';
      $('#ulAddUserBtn').attr('href', href);
    }

    function syncKindUi() {
      $('#ulRoleFilter').val(activeKind);
      $('#ulKindTabs a').each(function() {
        $(this).toggleClass('is-active', $(this).attr('data-kind') === activeKind);
      });
    }

    function syncStatusUi() {
      $('#ulStatusFilter').val(activeStatus === 'pending' ? 'all' : activeStatus);
      $('#ulStatusTabs a').each(function() {
        $(this).toggleClass('is-active', $(this).attr('data-status') === activeStatus);
      });
    }

    function setKind(kind) {
      if (!kind || (kind !== 'personal' && kind !== 'publisher' && kind !== 'commerce')) return;
      activeKind = kind;
      applyFilters();
    }

    function setStatus(status) {
      if (!status) return;
      activeStatus = status;
      applyFilters();
    }

    function applyFilters() {
      dt.draw();
      var url = new URL(window.location.href);
      url.searchParams.set('kind', activeKind);
      url.searchParams.set('status', activeStatus);
      window.history.replaceState({}, '', url.toString());
      syncAddUserHref(activeKind);
      syncKindUi();
      syncStatusUi();
    }

    function selectedIds() {
      var ids = [];
      $('#datatable1 tbody .ul-row-check:checked').each(function() {
        ids.push(this.value);
      });
      return ids;
    }

    $('#ulSearchInput').on('input', function() {
      dt.search(this.value).draw();
    });
    $('#ulStatusFilter').on('change', function() {
      activeStatus = this.value;
      applyFilters();
    });
    $('#ulVerifyFilter').on('change', function() {
      activeVerify = this.value;
      applyFilters();
    });
    $('#ulRegisteredFilter').on('change', function() {
      activeRegistered = this.value;
      applyFilters();
    });
    $('#ulRoleFilter').on('change', function() {
      setKind(this.value);
    });
    $('#ulKindTabs').on('click', 'a', function(e) {
      e.preventDefault();
      setKind($(this).attr('data-kind'));
    });
    $('#ulStatusTabs').on('click', 'a', function(e) {
      e.preventDefault();
      setStatus($(this).attr('data-status'));
    });
    $('#ulPageLen').on('change', function() {
      dt.page.len(parseInt(this.value, 10) || 10).draw();
    });
    $('#ulClearFilters').on('click', function(e) {
      e.preventDefault();
      activeStatus = 'all';
      activeVerify = 'all';
      activeRegistered = 'all';
      $('#ulStatusFilter').val('all');
      $('#ulVerifyFilter').val('all');
      $('#ulRegisteredFilter').val('all');
      $('#ulSearchInput').val('');
      dt.search('');
      applyFilters();
    });

    $('#ulSelectAll').on('change', function() {
      var on = this.checked;
      $('#datatable1 tbody tr').each(function() {
        if ($(this).css('display') === 'none') return;
        $(this).find('.ul-row-check').prop('checked', on);
      });
    });

    $('#ulBulkToggle').on('click', function(e) {
      e.stopPropagation();
      $('#ulBulk').toggleClass('is-open');
    });
    $(document).on('click', function() {
      $('#ulBulk').removeClass('is-open');
    });
    $('#ulBulk .ul-bulk-menu').on('click', function(e) {
      e.stopPropagation();
    });
    $('#ulBulk .ul-bulk-menu button').on('click', function() {
      var action = $(this).attr('data-bulk');
      var ids = selectedIds();
      $('#ulBulk').removeClass('is-open');
      if (!ids.length) {
        alert('Select at least one user first.');
        return;
      }
      if (action === 'delete') {
        if (!confirm('Delete ' + ids.length + ' selected user(s)? This cannot be undone.')) return;
        var delForm = document.createElement('form');
        delForm.method = 'post';
        delForm.style.display = 'none';
        delForm.innerHTML = '<input type="hidden" name="bulk_delete" value="1">' +
          '<input type="hidden" name="bulk_ids" value="' + ids.join(',') + '">';
        document.body.appendChild(delForm);
        delForm.submit();
        return;
      }
      var nextStatus = action === 'unblock' ? 1 : 0;
      var label = action === 'unblock' ? 'activate' : 'suspend';
      if (!confirm('Really ' + label + ' ' + ids.length + ' selected user(s)?')) return;
      var form = document.createElement('form');
      form.method = 'post';
      form.style.display = 'none';
      form.innerHTML = '<input type="hidden" name="set_status" value="1">' +
        '<input type="hidden" name="status_id" value="' + ids[0] + '">' +
        '<input type="hidden" name="status_value" value="' + nextStatus + '">' +
        '<input type="hidden" name="bulk_ids" value="' + ids.join(',') + '">' +
        '<input type="hidden" name="bulk_status" value="' + nextStatus + '">';
      document.body.appendChild(form);
      form.submit();
    });

    applyFilters();
  });
</script>
</body>
</html>
