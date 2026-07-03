<?php
// /Business_only3/admin/feedback.php
declare(strict_types=1);

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

$filter = strtolower(trim((string)($_GET['filter'] ?? 'all')));
$filter = in_array($filter, ['all','unread','read'], true) ? $filter : 'all';

$adminMode = isAdmin(); // base Admin role
$meId = myAdminId();

// ✅ internal chat uses friend_code in feedback_admin.sender/receiver
$me = myAdminFriendCode();
if ($me === '') $me = ensureAdminFriendCode($dbh);
if ($me === '') $me = myUsername(); // fallback (legacy)
if ($me === '' || $meId <= 0) die("Session missing username/id.");

function fmt_dt($dt): string { return $dt ? date('M d, Y h:i A', strtotime((string)$dt)) : ''; }
function isEmail($s): bool { return (strpos((string)$s, '@') !== false); }
function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$internalChannels = allowedInternalChannelsForMe();

/**
 * ==========================
 * ✅ SIGNED LINKS (CSRF-safe GET actions)
 * ==========================
 * Signs Reply / Mark / Delete links so they can't be tampered with.
 */

function signing_key(): string {
    // Prefer a constant secret (best)
    if (defined('APP_SIGNING_KEY') && APP_SIGNING_KEY !== '') {
        return (string)APP_SIGNING_KEY;
    }
    // Fallback: session-based secret
    if (empty($_SESSION['csrf_link_key'])) {
        $_SESSION['csrf_link_key'] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['csrf_link_key'];
}

function sign_link_token(string $action, string $peer, string $view, int $expiresTs): string {
    $adminId = (int)($_SESSION['admin_id'] ?? 0);
    $sid = session_id();
    $payload = $action . '|' . $peer . '|' . $view . '|' . $expiresTs . '|' . $adminId . '|' . $sid;
    return hash_hmac('sha256', $payload, signing_key());
}

function build_signed_link(
    string $base,
    array $params,
    string $action,
    string $peer,
    string $view,
    int $ttlSeconds = 600
): string {
    $exp = time() + max(60, $ttlSeconds);
    $params['exp'] = $exp;
    $params['sig'] = sign_link_token($action, $peer, $view, $exp);
    return $base . '?' . http_build_query($params);
}

function verify_signed_request(string $action, string $peer, string $view): bool {
    $exp = (int)($_GET['exp'] ?? 0);
    $sig = (string)($_GET['sig'] ?? '');
    if ($exp <= 0 || $sig === '') return false;
    if (time() > $exp) return false;

    $want = sign_link_token($action, $peer, $view, $exp);
    return hash_equals($want, $sig);
}

/**
 * Admin base role can switch public/internal.
 * Other base roles can only access internal.
 */
$view = strtolower(trim((string)($_GET['view'] ?? ($adminMode ? 'public' : 'internal'))));
$view = in_array($view, ['public','internal'], true) ? $view : 'internal';
if (!$adminMode) $view = 'internal';

function goBack(string $view, string $filter, string $msgKey = ''): void {
    $q = "view=" . urlencode($view) . "&filter=" . urlencode($filter);
    if ($msgKey !== '') $q .= "&msg=" . urlencode($msgKey);
    header("Location: feedback.php?$q");
    exit;
}

/**
 * ==========================
 * ACTIONS (Mark / Delete) ✅ now require signed links
 * ==========================
 */

// MARK ONE THREAD READ (signed)
if (isset($_GET['mark']) && $_GET['mark'] !== '') {
    $peerKey = trim((string)$_GET['mark']);

    // ✅ MUST be signed (prevents tampering / CSRF)
    if (!verify_signed_request('mark', $peerKey, $view)) {
        goBack($view, $filter);
    }

    try {
        // PUBLIC
        if ($view === 'public') {
            if (!$adminMode || !isEmail($peerKey)) goBack($view, $filter);

            $mk = $dbh->prepare("
                UPDATE feedback_admin
                SET is_read = 1, read_at = NOW()
                WHERE channel='user_admin'
                  AND receiver='Admin'
                  AND sender = :peer
                  AND is_read = 0
            ");
            $mk->execute([':peer' => $peerKey]);
            goBack($view, $filter, 'threadread');
        }

        // INTERNAL (✅ friend_code)
        if (empty($internalChannels)) goBack($view, $filter);

        $peerCode = strtoupper(trim($peerKey));
        $ph = implode(',', array_fill(0, count($internalChannels), '?'));
        $mk = $dbh->prepare("
            UPDATE feedback_admin
            SET is_read = 1, read_at = NOW()
            WHERE receiver = ?
              AND sender = ?
              AND channel IN ($ph)
              AND is_read = 0
        ");
        $mk->execute(array_merge([$me, $peerCode], $internalChannels));
        goBack($view, $filter, 'threadread');

    } catch (Throwable $e) {
        $error = "DB error: " . $e->getMessage();
    }
}

// DELETE ONE THREAD (signed)
if (isset($_GET['del']) && $_GET['del'] !== '') {
    $peerKey = trim((string)$_GET['del']);

    // ✅ MUST be signed
    if (!verify_signed_request('del', $peerKey, $view)) {
        goBack($view, $filter);
    }

    try {
        // PUBLIC
        if ($view === 'public') {
            if (!$adminMode || !isEmail($peerKey)) goBack($view, $filter);

            $del = $dbh->prepare("
                DELETE FROM feedback_admin
                WHERE channel='user_admin'
                  AND (
                        (sender=:peer AND receiver='Admin')
                     OR (sender='Admin' AND receiver=:peer2)
                  )
            ");
            $del->execute([':peer'=>$peerKey, ':peer2'=>$peerKey]);
            goBack($view, $filter, 'deleted');
        }

        // INTERNAL (✅ friend_code)
        if (empty($internalChannels)) goBack($view, $filter);

        $peerCode = strtoupper(trim($peerKey));
        $ph = implode(',', array_fill(0, count($internalChannels), '?'));
        $del = $dbh->prepare("
            DELETE FROM feedback_admin
            WHERE channel IN ($ph)
              AND (
                    (sender = ? AND receiver = ?)
                 OR (sender = ? AND receiver = ?)
              )
        ");
        $del->execute(array_merge($internalChannels, [$me, $peerCode, $peerCode, $me]));
        goBack($view, $filter, 'deleted');

    } catch (Throwable $e) {
        $error = "DB error: " . $e->getMessage();
    }
}

// MARK ALL READ (POST stays as-is)
if (isset($_POST['mark_all_read'])) {
    try {
        // PUBLIC
        if ($view === 'public') {
            if (!$adminMode) goBack($view, $filter);

            $mk = $dbh->prepare("
                UPDATE feedback_admin
                SET is_read=1, read_at=NOW()
                WHERE receiver='Admin'
                  AND channel='user_admin'
                  AND is_read=0
            ");
            $mk->execute();
            goBack($view, $filter, 'allread');
        }

        // INTERNAL
        if (empty($internalChannels)) goBack($view, $filter);

        $ph = implode(',', array_fill(0, count($internalChannels), '?'));
        $mk = $dbh->prepare("
            UPDATE feedback_admin
            SET is_read=1, read_at=NOW()
            WHERE receiver=?
              AND channel IN ($ph)
              AND is_read=0
        ");
        $mk->execute(array_merge([$me], $internalChannels));
        goBack($view, $filter, 'allread');

    } catch (Throwable $e) {
        $error = "DB error: " . $e->getMessage();
    }
}

// DELETE ALL (POST stays as-is)
if (isset($_POST['delete_all'])) {
    try {
        // PUBLIC
        if ($view === 'public') {
            if (!$adminMode) goBack($view, $filter);

            $del = $dbh->prepare("DELETE FROM feedback_admin WHERE receiver='Admin' AND channel='user_admin'");
            $del->execute();
            goBack($view, $filter, 'deletedall');
        }

        // INTERNAL
        if (empty($internalChannels)) goBack($view, $filter);

        $ph = implode(',', array_fill(0, count($internalChannels), '?'));
        $del = $dbh->prepare("DELETE FROM feedback_admin WHERE receiver=? AND channel IN ($ph)");
        $del->execute(array_merge([$me], $internalChannels));
        goBack($view, $filter, 'deletedall');

    } catch (Throwable $e) {
        $error = "DB error: " . $e->getMessage();
    }
}

// UI messages
if (($_GET['msg'] ?? '') === 'allread')    $msg = "All messages marked as read.";
if (($_GET['msg'] ?? '') === 'threadread') $msg = "Thread marked as read.";
if (($_GET['msg'] ?? '') === 'deleted')    $msg = "Thread deleted.";
if (($_GET['msg'] ?? '') === 'deletedall') $msg = "All threads deleted.";

/**
 * ==========================
 * FETCH THREADS
 * ==========================
 */
$threads = [];

try {
    if ($view === 'public') {
        if (!$adminMode) {
            $threads = [];
        } else {
            $sql = "
              SELECT
                f.sender AS peer_key,
                f.sender AS peer_display,
                MAX(f.created_at) AS last_time,
                SUM(CASE WHEN f.is_read=0 THEN 1 ELSE 0 END) AS unread_count,
                SUBSTRING_INDEX(
                  GROUP_CONCAT(f.feedbackdata ORDER BY f.created_at DESC SEPARATOR ' ||| '),
                  ' ||| ', 1
                ) AS last_message
              FROM feedback_admin f
              WHERE f.receiver='Admin'
                AND f.channel='user_admin'
              GROUP BY f.sender
              ORDER BY last_time DESC
              LIMIT 500
            ";
            $stmt = $dbh->prepare($sql);
            $stmt->execute();
            $threads = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }

    } else {
        if (empty($internalChannels)) {
            $threads = [];
        } else {
            $ph = implode(',', array_fill(0, count($internalChannels), '?'));

            $sql = "
              SELECT
                a.friend_code AS peer_key,
                CONCAT(
                  COALESCE(NULLIF(ac.display_name,''), NULLIF(a.fullname,''), a.username),
                  ' • ',
                  COALESCE(NULLIF(a.friend_code,''), a.username)
                ) AS peer_display,
                MAX(f.created_at) AS last_time,
                SUM(CASE WHEN f.is_read=0 AND f.receiver=? THEN 1 ELSE 0 END) AS unread_count,
                SUBSTRING_INDEX(
                  GROUP_CONCAT(f.feedbackdata ORDER BY f.created_at DESC SEPARATOR ' ||| '),
                  ' ||| ', 1
                ) AS last_message
              FROM feedback_admin f
              JOIN admin a
                ON a.friend_code = CASE WHEN f.sender=? THEN f.receiver ELSE f.sender END
              LEFT JOIN admin_contacts ac
                ON ac.owner_admin_id = ?
               AND ac.friend_admin_id = a.idadmin
              WHERE (f.sender=? OR f.receiver=?)
                AND f.channel IN ($ph)
              GROUP BY a.friend_code, peer_display
              ORDER BY last_time DESC
              LIMIT 500
            ";

            $stmt = $dbh->prepare($sql);
            $params = array_merge([$me, $me, $meId, $me, $me], $internalChannels);
            $stmt->execute($params);
            $threads = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
    }

    if ($filter !== 'all') {
        $threads = array_values(array_filter($threads, function($t) use ($filter){
            $u = (int)($t['unread_count'] ?? 0);
            return ($filter === 'unread') ? ($u > 0) : ($u === 0);
        }));
    }

} catch (Throwable $e) {
    $error = "DB error: " . $e->getMessage();
}

// Short preview (keep Actions visible)
function short_preview_plain(string $s, int $n = 45): string {
    $s = trim((string)$s);
    $s = preg_replace('/\s+/', ' ', $s) ?? $s;
    if (mb_strlen($s) <= $n) return $s;
    return mb_substr($s, 0, $n - 1) . '…';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
  <title>Inbox List</title>

  <link href="../lib/font-awesome/css/font-awesome.css" rel="stylesheet">
  <link href="../lib/Ionicons/css/ionicons.css" rel="stylesheet">
  <link href="../lib/perfect-scrollbar/css/perfect-scrollbar.css" rel="stylesheet">
  <link href="../lib/datatables/jquery.dataTables.css" rel="stylesheet">
  <link href="../lib/select2/css/select2.min.css" rel="stylesheet">
  <link rel="stylesheet" href="../css/shamcey.css">

  <style>
    :root{
      --bg:#f4f6fb; --card:#fff; --border: rgba(17,24,39,.10);
      --shadow: 0 14px 44px rgba(15,23,42,.10);
      --shadow2: 0 10px 26px rgba(15,23,42,.08);
      --radius: 16px; --brand:#2563eb; --brand2:#1e40af;
      --muted: rgba(17,24,39,.62);
      --ok:#22c55e; --bad:#ef4444;
    }

    html,body{ height:100%; overflow:hidden; }

    .sh-mainpanel{
      height:100vh;
      display:flex;
      flex-direction:column;
      overflow:hidden;
    }
    .sh-pagetitle{ flex:0 0 auto; }
    .sh-pagebody{
      flex:1 1 auto;
      overflow:hidden;
      padding-bottom:0!important;
      display:flex;
      flex-direction:column;
      background: var(--bg);
    }

    .inbox-card{
      flex:1 1 auto;
      min-height:0;
      display:flex;
      flex-direction:column;
      border:1px solid var(--border);
      /* border-radius: var(--radius); */
      box-shadow: var(--shadow);
      overflow:hidden;
      background:var(--card);
    }
    .card-header.pro{
      flex:0 0 auto;
      background: linear-gradient(135deg, var(--brand2), var(--brand));
      color:#fff;
      padding:16px 18px;
      font-weight:900;
      border-bottom:1px solid rgba(255,255,255,.18);
    }
    .card-header.pro .sub{ font-size:12px; opacity:.92; margin-top:4px; font-weight:700; }

    .toolbar{
      flex:0 0 auto;
      display:flex; gap:10px; align-items:center; justify-content:space-between; flex-wrap:wrap;
      padding: 14px 18px;
      background: rgba(248,250,252,.90);
      border-bottom:1px solid rgba(17,24,39,.06);
    }
    .toolbar .left, .toolbar .right{ display:flex; gap:10px; align-items:center; flex-wrap:wrap; }

    .btn{ border-radius: 14px; font-weight: 900; }
    .btn-soft{ background: rgba(37,99,235,.10); border:1px solid rgba(37,99,235,.18); color:#1e40af; }
    .btn-soft:hover{ background: rgba(37,99,235,.16); }
    .btn-danger-soft{ background: rgba(239,68,68,.10); border:1px solid rgba(239,68,68,.18); color:#991b1b; }
    .btn-danger-soft:hover{ background: rgba(239,68,68,.16); }

    .seg{ display:inline-flex; border:1px solid rgba(17,24,39,.10); border-radius:14px; overflow:hidden; background:#fff; }
    .seg a{
      padding:8px 12px; font-weight:900; color:#0f172a; text-decoration:none; border-right:1px solid rgba(17,24,39,.08);
    }
    .seg a:last-child{ border-right:0; }
    .seg a.active{ background: rgba(37,99,235,.10); color:#1e40af; }

    .card-body-fixed{
      flex:1 1 auto;
      min-height:0;
      overflow:hidden;
      display:flex;
      flex-direction:column;
    }
    .table-scroll{
      flex:1 1 auto;
      min-height:0;
      overflow:auto;
      padding: 14px 18px 18px 18px;
    }

    table.dataTable{
      width:100% !important;
      table-layout: fixed !important;
      border-collapse: separate !important;
      border-spacing: 0;
    }

    .pill{
      display:inline-flex; align-items:center; gap:8px;
      padding: 6px 10px; border-radius: 999px; font-weight: 900; font-size: 12px;
      border:1px solid rgba(17,24,39,.10); background: rgba(17,24,39,.04);
      white-space:nowrap;
    }
    .pill.unread{ background: rgba(239,68,68,.10); border-color: rgba(239,68,68,.18); color: rgba(153,27,27,1); }
    .pill.read{ background: rgba(34,197,94,.10); border-color: rgba(34,197,94,.18); color: rgba(22,101,52,1); }

    .mono{ font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono","Courier New", monospace; font-size:12px; }
    .msg-preview{
      width:100%;
      white-space:nowrap;
      overflow:hidden;
      text-overflow:ellipsis;
      color: rgba(17,24,39,.80);
    }

    .icon-btn{
      width:38px;height:38px; border-radius:12px;
      border:1px solid rgba(17,24,39,.10); background:#fff;
      display:inline-flex; align-items:center; justify-content:center;
      cursor:pointer; transition: transform .05s ease, background .15s ease;
      box-shadow: var(--shadow2);
      color:#2563eb;
      text-decoration:none;
    }
    .icon-btn i{ font-size:16px; }
    .icon-btn:hover{ background: rgba(37,99,235,.08); text-decoration:none; }
    .icon-btn:active{ transform: translateY(1px); }

    .icon-btn.ok{ color:#16a34a; }
    .icon-btn.ok:hover{ background: rgba(34,197,94,.12); }

    .icon-btn.danger{ color:#ef4444; }
    .icon-btn.danger:hover{ background: rgba(239,68,68,.10); }

    /* ✅ disabled still visible */
    .icon-btn:disabled,
    .icon-btn.is-disabled{
      opacity:.75 !important;
      background:#f8fafc;
      border-color: rgba(17,24,39,.10);
      cursor:not-allowed;
      pointer-events:none;
    }
    .icon-btn:disabled i,
    .icon-btn.is-disabled i{
      color:#94a3b8 !important;
      opacity:1 !important;
    }

    #threadsTable thead th{
      position: sticky;
      top: 0;
      background: #fff;
      z-index: 10;
      border-bottom: 1px solid rgba(17,24,39,.12) !important;
      font-weight:900;
      color: rgba(17,24,39,.78);
    }
    #threadsTable tbody td{
      vertical-align: middle;
      border-bottom: 1px solid rgba(17,24,39,.06);
      overflow:hidden;
      text-overflow:ellipsis;
      white-space:nowrap;
    }

    .dataTables_wrapper .dataTables_filter input{
      border-radius: 14px !important; border: 1px solid var(--border) !important;
      height: 40px; padding: 6px 10px; box-shadow:none !important; background:#fff;
    }
    .dataTables_wrapper .dataTables_length select{
      border-radius: 14px !important; border: 1px solid var(--border) !important;
      height: 40px; box-shadow:none !important; background:#fff;
    }
  </style>
</head>

<body>
<?php include('includes/leftbar.php'); ?>
<?php include('includes/header.php'); ?>

<div class="sh-mainpanel">

  <!-- <div class="sh-pagetitle">
    <div class="input-group"></div>
    <div class="sh-pagetitle-left">
      <div class="sh-pagetitle-icon"><i class="icon ion-ios-email-outline"></i></div>
      <div class="sh-pagetitle-title">
        <span>Inbox</span>
        <h2><?php echo ($view === 'public') ? 'Public Users → Admin' : 'Internal Roles Inbox'; ?></h2>
      </div>
    </div>
  </div> -->

  <div class="sh-pagebody">

    <?php if ($error): ?><div class="alert alert-danger" id="msgshow" style="margin:0 0 10px 0;"><?php echo h($error); ?></div><?php endif; ?>
    <?php if ($msg): ?><div class="alert alert-success" id="msgshow" style="margin:0 0 10px 0;"><?php echo h($msg); ?></div><?php endif; ?>

    <div class="inbox-card">
      <!-- <div class="card-header pro">
        <div style="font-size:16px;">Threads</div>
        <div class="sub">
          <?php echo ($view === 'public')
            ? 'Manage threads from public users (email-based).'
            : 'Manage internal threads (friend_code-based) with contacts display names.'; ?>
        </div>
      </div> -->

      <div class="toolbar">
        <div class="left">
          <?php if ($adminMode): ?>
            <div class="seg" role="tablist" aria-label="View">
              <a class="<?php echo ($view==='public')?'active':''; ?>" href="feedback.php?view=public&filter=<?php echo urlencode($filter); ?>">
                <i class="fa fa-globe"></i> Public
              </a>
              <a class="<?php echo ($view==='internal')?'active':''; ?>" href="feedback.php?view=internal&filter=<?php echo urlencode($filter); ?>">
                <i class="fa fa-users"></i> Internal
              </a>
            </div>
          <?php else: ?>
            <div class="seg">
              <a class="active" href="feedback.php?view=internal&filter=<?php echo urlencode($filter); ?>">
                <i class="fa fa-users"></i> Internal
              </a>
            </div>
          <?php endif; ?>

          <div class="seg" role="tablist" aria-label="Filter">
            <a class="<?php echo ($filter==='all')?'active':''; ?>" href="feedback.php?view=<?php echo urlencode($view); ?>&filter=all">All</a>
            <a class="<?php echo ($filter==='unread')?'active':''; ?>" href="feedback.php?view=<?php echo urlencode($view); ?>&filter=unread">Unread</a>
            <a class="<?php echo ($filter==='read')?'active':''; ?>" href="feedback.php?view=<?php echo urlencode($view); ?>&filter=read">Read</a>
          </div>

          <?php if ($view === 'internal'): ?>
            <a class="btn btn-soft btn-sm" href="contacts.php"><i class="fa fa-address-book"></i> Contacts</a>
          <?php endif; ?>
          <a class="btn btn-soft btn-sm" href="compose.php"><i class="fa fa-plus"></i> New Message</a>
        </div>

        <div class="right">
          <button type="button" class="btn btn-soft btn-sm"
                  data-toggle="modal" data-target="#markAllModal"
                  <?php echo empty($threads) ? 'disabled' : ''; ?>>
            <i class="fa fa-check"></i> Mark All Read
          </button>

          <button type="button" class="btn btn-danger-soft btn-sm"
                  data-toggle="modal" data-target="#deleteAllModal"
                  <?php echo empty($threads) ? 'disabled' : ''; ?>>
            <i class="fa fa-trash"></i> Delete All
          </button>
        </div>
      </div>

      <div class="card-body-fixed">
        <div class="table-scroll">
          <table id="threadsTable" class="table display" style="width:100%;">
            <thead>
              <tr>
                <th style="width:70px;">#</th>
                <th style="width:330px;"><?php echo ($view === 'public') ? 'From (User Email)' : 'Peer (Name • Friend Code)'; ?></th>
                <th style="width:320px;">Last Message</th>
                <th style="width:190px;">Last Time</th>
                <th style="width:110px;">Unread</th>
                <th style="width:190px;">Actions</th>
              </tr>
            </thead>
            <tbody>
            <?php $i=1; foreach ($threads as $t): ?>
              <?php
                $peerKey = (string)($t['peer_key'] ?? '');
                $peerDisplay = (string)($t['peer_display'] ?? $peerKey);
                $unread = (int)($t['unread_count'] ?? 0);

                $raw = (string)($t['last_message'] ?? '');
                $plain = trim(html_entity_decode(strip_tags($raw)));
                $plain = short_preview_plain($plain, 45);

                $lastTime = fmt_dt($t['last_time'] ?? '');

                // ✅ Signed URLs for row actions
                $replyUrl = build_signed_link(
                    'sendreply.php',
                    [
                        'reply'  => $peerKey,
                        'view'   => $view,
                        'filter' => $filter
                    ],
                    'reply',
                    $peerKey,
                    $view,
                    900
                );

                $markUrl = build_signed_link(
                    'feedback.php',
                    [
                        'view'   => $view,
                        'filter' => $filter,
                        'mark'   => $peerKey
                    ],
                    'mark',
                    $peerKey,
                    $view,
                    900
                );

                $delUrl = build_signed_link(
                    'feedback.php',
                    [
                        'view'   => $view,
                        'filter' => $filter,
                        'del'    => $peerKey
                    ],
                    'del',
                    $peerKey,
                    $view,
                    900
                );
              ?>
              <tr>
                <td class="mono"><?php echo (int)$i++; ?></td>

                <td>
                  <div style="display:flex;align-items:center;gap:10px;min-width:0;">
                    <div style="width:38px;height:38px;border-radius:999px;background:rgba(37,99,235,.12);display:flex;align-items:center;justify-content:center;font-weight:900;color:#1e40af;flex:0 0 auto;">
                      <?php echo h(mb_strtoupper(mb_substr($peerDisplay, 0, 1))); ?>
                    </div>
                    <div style="min-width:0;">
                      <div style="font-weight:900;color:#0f172a;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:320px;">
                        <?php echo h($peerDisplay); ?>
                      </div>
                      <div class="mono" style="color:var(--muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:320px;">
                        <?php echo h($peerKey); ?>
                      </div>
                    </div>
                  </div>
                </td>

                <td><div class="msg-preview"><?php echo h($plain); ?></div></td>
                <td class="mono"><?php echo h($lastTime); ?></td>

                <td>
                  <?php if ($unread > 0): ?>
                    <span class="pill unread"><i class="fa fa-circle" style="font-size:10px;"></i> <?php echo (int)$unread; ?></span>
                  <?php else: ?>
                    <span class="pill read"><i class="fa fa-check"></i> 0</span>
                  <?php endif; ?>
                </td>

                <td>
                  <div style="display:flex;gap:10px;align-items:center;justify-content:flex-start;">
                    <!-- Reply (signed) -->
                    <a class="icon-btn" href="<?php echo h($replyUrl); ?>"
                       data-toggle="tooltip" data-placement="top" title="Reply">
                      <i class="fa fa-mail-reply"></i>
                    </a>

                    <!-- Mark Read (signed via modal) -->
                    <?php if ($unread > 0): ?>
                      <button type="button"
                              class="icon-btn ok"
                              data-toggle="tooltip" data-placement="top" title="Mark thread read"
                              data-peer="<?php echo h($peerKey); ?>"
                              data-peerdisplay="<?php echo h($peerDisplay); ?>"
                              data-markurl="<?php echo h($markUrl); ?>"
                              onclick="openThreadMarkModal(this);">
                        <i class="fa fa-check"></i>
                      </button>
                    <?php else: ?>
                      <button type="button" class="icon-btn is-disabled" disabled title="Already read">
                        <i class="fa fa-check"></i>
                      </button>
                    <?php endif; ?>

                    <!-- Delete (signed via modal) -->
                    <button type="button"
                            class="icon-btn danger"
                            data-toggle="tooltip" data-placement="top" title="Delete thread"
                            data-peer="<?php echo h($peerKey); ?>"
                            data-peerdisplay="<?php echo h($peerDisplay); ?>"
                            data-delurl="<?php echo h($delUrl); ?>"
                            onclick="openThreadDeleteModal(this);">
                      <i class="fa fa-trash"></i>
                    </button>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>

          <?php if (empty($threads)): ?>
            <div class="alert alert-info" style="margin-top:12px;">No chat threads found.</div>
          <?php endif; ?>
        </div>
      </div>
    </div>

  </div>

  <!-- ✅ MODALS -->

  <div class="modal fade" id="markAllModal" tabindex="-1" role="dialog" aria-labelledby="markAllModalLabel">
    <div class="modal-dialog" role="document">
      <div class="modal-content">
        <form method="post">
          <div class="modal-header">
            <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
            <h4 class="modal-title" id="markAllModalLabel">Mark All Read</h4>
          </div>
          <div class="modal-body">
            <p>Mark <b>all threads</b> in this view as read?</p>
            <p class="mono" style="opacity:.75;margin:0;">View: <?php echo h($view); ?> • Filter: <?php echo h($filter); ?></p>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-default" data-dismiss="modal">Cancel</button>
            <button type="submit" name="mark_all_read" class="btn btn-primary">
              <i class="fa fa-check"></i> Mark All Read
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <div class="modal fade" id="deleteAllModal" tabindex="-1" role="dialog" aria-labelledby="deleteAllModalLabel">
    <div class="modal-dialog" role="document">
      <div class="modal-content">
        <form method="post">
          <div class="modal-header">
            <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
            <h4 class="modal-title" id="deleteAllModalLabel">Delete All Threads</h4>
          </div>
          <div class="modal-body">
            <p>You are about to delete <b><?php echo (int)count($threads); ?></b> thread(s) in this view.</p>
            <p style="color:#b91c1c;font-weight:700;margin:0;">This cannot be undone.</p>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-default" data-dismiss="modal">Cancel</button>
            <button type="submit" name="delete_all" class="btn btn-danger">
              <i class="fa fa-trash"></i> Delete All
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <div class="modal fade" id="markThreadModal" tabindex="-1" role="dialog" aria-labelledby="markThreadModalLabel">
    <div class="modal-dialog" role="document">
      <div class="modal-content">
        <div class="modal-header">
          <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
          <h4 class="modal-title" id="markThreadModalLabel">Mark Thread Read</h4>
        </div>
        <div class="modal-body">
          <p>Mark this thread as read?</p>
          <p style="margin:0;"><b id="mkPeerName"></b></p>
          <p class="mono" style="opacity:.75;margin-top:6px;" id="mkPeerKey"></p>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-default" data-dismiss="modal">Cancel</button>
          <a id="mkGo" class="btn btn-primary" href="#"><i class="fa fa-check"></i> Mark Read</a>
        </div>
      </div>
    </div>
  </div>

  <div class="modal fade" id="deleteThreadModal" tabindex="-1" role="dialog" aria-labelledby="deleteThreadModalLabel">
    <div class="modal-dialog" role="document">
      <div class="modal-content">
        <div class="modal-header">
          <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
          <h4 class="modal-title" id="deleteThreadModalLabel">Delete Thread</h4>
        </div>
        <div class="modal-body">
          <p>Delete this thread permanently?</p>
          <p style="margin:0;"><b id="delPeerName"></b></p>
          <p class="mono" style="opacity:.75;margin-top:6px;" id="delPeerKey"></p>
          <p style="margin-top:10px;color:#b91c1c;font-weight:700;">This cannot be undone.</p>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-default" data-dismiss="modal">Cancel</button>
          <a id="delGo" class="btn btn-danger" href="#"><i class="fa fa-trash"></i> Delete</a>
        </div>
      </div>
    </div>
  </div>

  <div class="sh-footer">
    <div>Copyright &copy; 2017. All Rights Reserved.</div>
    <div class="mg-t-10 mg-md-t-0">Designed by: ThemePixels</div>
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
  $(function(){
    $('#threadsTable').DataTable({
      paging: false,
      info: false,
      responsive: false,
      autoWidth: false,
      scrollX: false,
      order: [[3,'desc']],
      language: { searchPlaceholder:'Search threads...', sSearch:'', lengthMenu:'_MENU_' },
      columnDefs: [
        { orderable: false, targets: [5] } // actions not sortable
      ]
    });

    $('.dataTables_length select').select2({ minimumResultsForSearch: Infinity });
    $('[data-toggle="tooltip"]').tooltip();

    setTimeout(function(){
      var el = document.getElementById('msgshow');
      if (el) $(el).fadeOut();
    }, 2500);
  });

  function openThreadMarkModal(btn){
    var peer = btn.getAttribute('data-peer') || '';
    var peerDisplay = btn.getAttribute('data-peerdisplay') || peer;
    var markUrl = btn.getAttribute('data-markurl') || '#';

    document.getElementById('mkPeerName').textContent = peerDisplay;
    document.getElementById('mkPeerKey').textContent = peer;
    document.getElementById('mkGo').setAttribute('href', markUrl);

    $('#markThreadModal').modal('show');
  }

  function openThreadDeleteModal(btn){
    var peer = btn.getAttribute('data-peer') || '';
    var peerDisplay = btn.getAttribute('data-peerdisplay') || peer;
    var delUrl = btn.getAttribute('data-delurl') || '#';

    document.getElementById('delPeerName').textContent = peerDisplay;
    document.getElementById('delPeerKey').textContent = peer;
    document.getElementById('delGo').setAttribute('href', delUrl);

    $('#deleteThreadModal').modal('show');
  }
</script>
</body>
</html>
