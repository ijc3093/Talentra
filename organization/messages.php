<?php
// /Business_only3/organization/messages.php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/includes/session_org.php';
require_once __DIR__ . '/includes/org_context.php';

if (!function_exists('h')) {
    function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}

$orgId      = (int)orgActiveOrgId();
$meMemberId = (int)orgMemberId();

if ($orgId <= 0 || $meMemberId <= 0) {
    die('Invalid org session.');
}

// ==============================
// SUBJECT THREADS (SAFE / OPTIONAL)
// ==============================
$threadingEnabled = false;
$selectedThreadId = (int)($_GET['thread'] ?? 0);

try {
    $stChk = $dbh->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'org_message_threads' LIMIT 1");
    $stChk->execute();
    if ($stChk->fetchColumn()) $threadingEnabled = true;
} catch (Throwable $e) {
    $threadingEnabled = false;
}

// ✅ Online/Offline: bump last_seen for current org account (manager/staff)
try {
    $t  = (string)orgAccountType();
    $id = (int)orgAccountId();
    if ($id > 0) {
        if ($t === 'manager') {
            $st = $dbh->prepare("UPDATE managers SET last_seen = NOW() WHERE id = :id LIMIT 1");
            $st->execute([':id' => $id]);
        } elseif ($t === 'staff') {
            $st = $dbh->prepare("UPDATE staff_accounts SET last_seen = NOW() WHERE id = :id LIMIT 1");
            $st->execute([':id' => $id]);
        }
    }
} catch (Throwable $e) { /* ignore */ }

function fmtTimeAMPM(?string $dt): string {
    if (!$dt) return '';
    try {
        $tz = new DateTimeZone('America/Chicago');
        $d  = new DateTime($dt);
        $d->setTimezone($tz);
        return $d->format('g:i:sA');
    } catch (Throwable $e) {
        return (string)$dt;
    }
}

function senderLabelFromRow(array $m, bool $mine): string {
    if ($mine) return 'You';
    $name = trim((string)($m['sender_fullname'] ?? ''));
    if ($name === '') $name = trim((string)($m['sender_username'] ?? ''));
    if ($name === '') $name = 'Member';
    return $name;
}

function fmtPresence(?string $lastSeen): array {
    if (!$lastSeen) return ['Offline', 'badge badge-secondary'];
    $ts = strtotime($lastSeen);
    if ($ts <= 0) return ['Offline', 'badge badge-secondary'];
    $diff = time() - $ts;
    if ($diff <= 300) return ['Online', 'badge badge-success'];
    if ($diff < 3600) { $m = (int)floor($diff/60); return ["$m min ago", 'badge badge-secondary']; }
    if ($diff < 86400) { $h = (int)floor($diff/3600); return ["$h hr ago", 'badge badge-secondary']; }
    $d = (int)floor($diff/86400);
    return ["$d d ago", 'badge badge-secondary'];
}

function shortTime(?string $dt): string {
    if (!$dt) return '';
    $ts = strtotime($dt);
    if ($ts <= 0) return (string)$dt;
    return date('M j, g:i A', $ts);
}

// --- Load peers in this org (excluding me) ---
$stPeers = $dbh->prepare("
  SELECT
    om.id,
    om.member_type,
    om.member_id,
    om.relationship_label,
    COALESCE(m.fullname, s.fullname, '') AS fullname,
    COALESCE(m.username, s.username, '') AS username,
    COALESCE(m.last_seen, s.last_seen)   AS last_seen
  FROM org_members om
  LEFT JOIN managers m
    ON om.member_type = 'manager' AND m.id = om.member_id
  LEFT JOIN staff_accounts s
    ON om.member_type = 'staff' AND s.id = om.member_id
  WHERE om.org_id = :org
    AND om.status = 1
    AND om.id <> :me
  ORDER BY om.id DESC
");
$stPeers->execute([':org' => $orgId, ':me' => $meMemberId]);
$peers = $stPeers->fetchAll(PDO::FETCH_ASSOC);

// Active peer (org_members.id)
$peerMid = (int)($_GET['peer'] ?? 0);
if ($peerMid <= 0 && !empty($peers)) {
    $peerMid = (int)$peers[0]['id'];
}

// ---- Subject threads (right sidebar): resolve selected thread + list ----
$threads = [];
if ($threadingEnabled && $peerMid > 0) {
    $pairA = min($meMemberId, $peerMid);
    $pairB = max($meMemberId, $peerMid);

    if ($selectedThreadId <= 0) {
        try {
            $stDef = $dbh->prepare("SELECT id FROM org_message_threads WHERE org_id = :org AND member_a_id = :a AND member_b_id = :b ORDER BY id DESC LIMIT 1");
            $stDef->execute([':org' => $orgId, ':a' => $pairA, ':b' => $pairB]);
            $selectedThreadId = (int)($stDef->fetchColumn() ?: 0);
        } catch (Throwable $e) {
            $selectedThreadId = 0;
        }
    }

    if ($selectedThreadId <= 0) {
        try {
            $uid = 'THR-' . $orgId . '-' . $pairA . '-' . $pairB . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(4));
            $stNew = $dbh->prepare("INSERT INTO org_message_threads (org_id, member_a_id, member_b_id, subject, uid, created_by_member_id, created_at, last_message_at) VALUES (:org,:a,:b,:sub,:uid,:by,NOW(),NOW())");
            $stNew->execute([
                ':org' => $orgId,
                ':a'   => $pairA,
                ':b'   => $pairB,
                ':sub' => 'General',
                ':uid' => $uid,
                ':by'  => $meMemberId,
            ]);
            $selectedThreadId = (int)$dbh->lastInsertId();
        } catch (Throwable $e) {
            $selectedThreadId = 0;
        }
    }

    try {
        $stTh = $dbh->prepare("SELECT id, subject, uid, created_at, last_message_at FROM org_message_threads WHERE org_id = :org AND member_a_id = :a AND member_b_id = :b ORDER BY id DESC LIMIT 50");
        $stTh->execute([':org' => $orgId, ':a' => $pairA, ':b' => $pairB]);
        $threads = $stTh->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $threads = [];
    }
}

// --- Send message (text OR attachment OR both) ---
$msgErr = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $peerMidPost  = (int)($_POST['peer_mid'] ?? 0);
    $body         = trim((string)($_POST['body'] ?? ''));
    $attachmentId = (int)($_POST['attachment_id'] ?? 0);
    $threadIdPost = (int)($_POST['thread_id'] ?? 0);

    if ($peerMidPost <= 0 || ($body === '' && $attachmentId <= 0)) {
        $msgErr = 'Message or attachment required.';
    } else {
        $stV = $dbh->prepare("
            SELECT id
            FROM org_members
            WHERE org_id = :org
              AND id = :id
              AND status = 1
            LIMIT 1
        ");
        $stV->execute([':org' => $orgId, ':id' => $peerMidPost]);

        if ($stV->fetch()) {
            $msgType = ($attachmentId > 0 && $body === '') ? 'media' : 'direct';
            $useThreadId = ($threadIdPost > 0) ? $threadIdPost : (int)$selectedThreadId;

            $insertOk = false;
            if ($threadingEnabled && $useThreadId > 0) {
                try {
                    $stIns = $dbh->prepare("
                      INSERT INTO org_messages
                        (org_id, thread_id, sender_member_id, receiver_member_id, msg_type, body, attachment_id, created_at)
                      VALUES
                        (:org, :tid, :s, :r, :t, :b, :aid, NOW())
                    ");
                    $stIns->execute([
                        ':org' => $orgId,
                        ':tid' => $useThreadId,
                        ':s'   => $meMemberId,
                        ':r'   => $peerMidPost,
                        ':t'   => $msgType,
                        ':b'   => ($body === '' ? null : $body),
                        ':aid' => ($attachmentId > 0 ? $attachmentId : null),
                    ]);
                    $insertOk = true;

                    try {
                        $stBump = $dbh->prepare("UPDATE org_message_threads SET last_message_at = NOW() WHERE id = :id AND org_id = :org LIMIT 1");
                        $stBump->execute([':id' => $useThreadId, ':org' => $orgId]);
                    } catch (Throwable $e) { /* ignore */ }

                } catch (Throwable $e) {
                    $insertOk = false;
                }
            }

            if (!$insertOk) {
                $stIns = $dbh->prepare("
                  INSERT INTO org_messages
                    (org_id, sender_member_id, receiver_member_id, msg_type, body, attachment_id, created_at)
                  VALUES
                    (:org, :s, :r, :t, :b, :aid, NOW())
                ");
                $stIns->execute([
                    ':org' => $orgId,
                    ':s'   => $meMemberId,
                    ':r'   => $peerMidPost,
                    ':t'   => $msgType,
                    ':b'   => ($body === '' ? null : $body),
                    ':aid' => ($attachmentId > 0 ? $attachmentId : null),
                ]);
            }

            $redir = "messages.php?peer=" . $peerMidPost;
            if ($threadingEnabled && $useThreadId > 0) $redir .= "&thread=" . $useThreadId;
            header("Location: " . $redir);
            exit;
        } else {
            $msgErr = 'Invalid peer.';
        }
    }
}

// ---- Mark messages as read for opened peer ----
if ($peerMid > 0) {
    try {
        $stRead = $dbh->prepare("
            UPDATE org_messages
            SET is_read = 1, read_at = NOW()
            WHERE org_id = :org
              AND sender_member_id = :peer
              AND receiver_member_id = :me
              AND is_read = 0
        ");
        $stRead->execute([':org' => $orgId, ':peer' => $peerMid, ':me' => $meMemberId]);
    } catch (Throwable $e) { /* ignore */ }
}

// ---- Unread count per peer for left sidebar ----
$unreadByPeer = [];
try {
    $stUn = $dbh->prepare("
        SELECT sender_member_id AS sid, COUNT(*) AS c
        FROM org_messages
        WHERE org_id = :org
          AND receiver_member_id = :me
          AND is_read = 0
        GROUP BY sender_member_id
    ");
    $stUn->execute([':org' => $orgId, ':me' => $meMemberId]);
    foreach ($stUn->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $sid = (int)($r['sid'] ?? 0);
        if ($sid > 0) $unreadByPeer[$sid] = (int)($r['c'] ?? 0);
    }
} catch (Throwable $e) {
    $unreadByPeer = [];
}

// Get active peer row (for conversation header)
$activePeer = null;
foreach ($peers as $pp) {
    if ((int)$pp['id'] === $peerMid) { $activePeer = $pp; break; }
}
$activePeerName = '';
$activePeerRel  = '';
$activePresenceLabel = '';
$activePresenceCls   = 'badge badge-secondary';
if ($activePeer) {
    $activePeerName = trim((string)($activePeer['fullname'] ?? ''));
    if ($activePeerName === '') $activePeerName = trim((string)($activePeer['username'] ?? 'Member'));
    $activePeerRel = trim((string)($activePeer['relationship_label'] ?? ''));
    [$activePresenceLabel, $activePresenceCls] = fmtPresence($activePeer['last_seen'] ?? null);
}

// ---- Last message per peer (for left sidebar preview) ----
$lastByPeer = [];
try {
    $sqlLast = "
      SELECT other_id, body, msg_type, attachment_id, created_at, file_path, file_type, file_name
      FROM (
        SELECT
          CASE WHEN sender_member_id = ? THEN receiver_member_id ELSE sender_member_id END AS other_id,
          body, msg_type, attachment_id, created_at, file_path, file_type, file_name,
          ROW_NUMBER() OVER (
            PARTITION BY CASE WHEN sender_member_id = ? THEN receiver_member_id ELSE sender_member_id END
            ORDER BY created_at DESC
          ) AS rn
        FROM org_messages
        WHERE org_id = ?
          AND (sender_member_id = ? OR receiver_member_id = ?)
      ) t
      WHERE rn = 1
    ";
    $stLast = $dbh->prepare($sqlLast);
    $stLast->execute([$meMemberId, $meMemberId, $orgId, $meMemberId, $meMemberId]);
    $rows = $stLast->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $oid = (int)($r['other_id'] ?? 0);
        if ($oid > 0) $lastByPeer[$oid] = $r;
    }
} catch (Throwable $e) { /* ignore */ }

// --- Load thread (me <-> peer) with attachments + sender identity ---
$thread = [];
if ($peerMid > 0) {
    $threadWhere = "";
    $params = [
        ':org'   => $orgId,
        ':me1'   => $meMemberId,
        ':peer1' => $peerMid,
        ':peer2' => $peerMid,
        ':me2'   => $meMemberId,
    ];
    if ($threadingEnabled && $selectedThreadId > 0) {
        $threadWhere = " AND m.thread_id = :tid ";
        $params[':tid'] = $selectedThreadId;
    }

    $stT = $dbh->prepare("
      SELECT
        m.*,
        a.path          AS attach_path,
        a.mime_type     AS attach_mime,
        a.original_name AS attach_name,
        COALESCE(smgr.fullname, sst.fullname, '') AS sender_fullname,
        COALESCE(smgr.username, sst.username, '') AS sender_username
      FROM org_messages m
      LEFT JOIN org_attachments a
        ON a.id = m.attachment_id
      LEFT JOIN org_members sm
        ON sm.id = m.sender_member_id
      LEFT JOIN managers smgr
        ON sm.member_type = 'manager' AND smgr.id = sm.member_id
      LEFT JOIN staff_accounts sst
        ON sm.member_type = 'staff' AND sst.id = sm.member_id
      WHERE m.org_id = :org
        AND (
          (m.sender_member_id = :me1 AND m.receiver_member_id = :peer1)
          OR
          (m.sender_member_id = :peer2 AND m.receiver_member_id = :me2)
        )
        $threadWhere
      ORDER BY m.created_at ASC
      LIMIT 300
    ");
    $stT->execute($params);
    $thread = $stT->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once __DIR__ . '/includes/org_theme_head.php'; ?>

  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
  <title><?= h($ORG['name']) ?> - Messages</title>

  <link href="../lib/font-awesome/css/font-awesome.css" rel="stylesheet">
  <link href="../lib/Ionicons/css/ionicons.css" rel="stylesheet">
  <link href="../lib/perfect-scrollbar/css/perfect-scrollbar.css" rel="stylesheet">
  <link href="../lib/bootstrap/bootstrap.css" rel="stylesheet">
  <link rel="stylesheet" href="../css/shamcey.css">
  <?php require_once __DIR__ . '/includes/org_layout.php'; org_layout_head_assets(); ?>

   <style>
    /* ✅ FIXED PAGE LIKE settings.php */
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
      min-height:0;
    }

    .chat-shell{
      flex:1 1 auto;
      min-height:0;
      overflow:hidden;
      display:flex;
      gap:6px;
      padding: 6px;
    }

    .chat-left, .chat-right{
      min-height: 0;
      overflow: hidden;
      display: flex;
      flex-direction: column;
      border: dashed;
      border-color: #3e494e;
    }
    .chat-left{ width: 34%; }
    .chat-right{ width: 66%; }

    .card.fixed-card{
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

    /* ========= your existing styles (kept) ========= */
    .msg-me{background:#eef2ff; padding:5px 8px; border-radius:8px; margin:4px 0 4px auto; max-width:70%; font-size:11px;}
    .msg-them{background:#4f535b;color:white;padding:5px 8px; border-radius:8px; margin:4px auto 4px 0; max-width:70%; font-size:11px;}
    .composer-row{display:flex; gap:6px; align-items:flex-end;}
    .composer-row .form-group{flex:1; margin:0;}

    .msg-row{display:flex;margin:6px 0;}
    .msg-row.me{ justify-content:flex-end; }
    .msg-row.them{ justify-content:flex-start; }

    .msg-me,.msg-them{
      display:inline-block;
      width:fit-content;
      max-width:70%;
      word-break:break-word;
      overflow-wrap:anywhere;
    }

    .msg-attach{display:inline-block;width:fit-content;max-width:100%;}
    .msg-attach img{
      width:auto !important;height:auto !important;
      max-width:180px;max-height:140px;display:block;border-radius:8px;
    }
    .msg-attach video{
      width:auto !important;height:auto !important;
      max-width:180px;max-height:140px;display:block;border-radius:8px;
    }

    /* Attachment preview inside textarea */
    .composer-row .form-group{ position:relative; }
    .composer-row .form-group textarea{ padding-bottom:36px; font-size:11px; min-height:52px; }

    #attachStatus{
      position:absolute;
      right:10px;
      bottom:8px;
      z-index:5;
      display:none;
      border-radius:10px;
      font-size:10px;
      line-height:1.2;
      background:rgba(0,0,0,.06);
      border:1px solid rgba(0,0,0,.10);
      max-width: 100%;
    }
    #attachStatus .attach-inline{display:flex;align-items:center;gap:10px;justify-content:space-between;}
    #attachStatus .attach-left{display:flex;align-items:center;gap:10px;min-width:0;}
    #attachStatus .attach-name{white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:220px;}
    #attachStatus .attach-thumb{width:34px;height:34px;border-radius:8px;object-fit:cover;display:block;border:1px solid rgba(0,0,0,.10);}
    #attachStatus .attach-x{border:0;background:transparent;font-size:14px;line-height:1;opacity:.7;cursor:pointer;padding:0 4px;}
    #attachStatus .attach-x:hover{ opacity:1; }
    #attachStatus.err{ background:rgba(220,53,69,.12); border-color:rgba(220,53,69,.25); }

    #emojiBoxFixed{position: fixed; z-index: 20000; width: 320px; display: none; box-shadow: 0 10px 30px rgba(0,0,0,.25);}
    #emojiBoxFixed .card-body{ padding:10px; }
    #emojiGrid{display:grid;grid-template-columns:repeat(10, 1fr);gap:4px;font-size:16px;max-height:180px;overflow:auto;}
    #emojiGrid button{ padding:6px; line-height:1; }

    .peer-row{display:flex; justify-content:space-between; gap:10px;}
    .peer-meta{min-width:0;}
    .peer-name{font-weight:600; margin:0; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;}
    .peer-sub{margin:0; opacity:.75; font-size:10px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;}
    .peer-right{display:flex; flex-direction:column; align-items:flex-end; gap:6px; flex:0 0 auto;}
    .peer-time{font-size:9px; opacity:.65; white-space:nowrap;}

    .unread-pill{display:inline-flex;align-items:center;justify-content:center;min-width:16px;height:16px;padding:0 4px;border-radius:999px;background:#dc3545;color:#fff;font-size:9px;font-weight:700;line-height:1;}
    .attach-click{ cursor:pointer; }

    .msg-status{font-size:10px;font-weight:700;margin-left:6px;letter-spacing:1px;user-select:none;}
    .msg-status.sent{ opacity:.55; }
    .msg-status.read{ opacity:.9; }

    /* ✅ Search bar inside page (fixed, not scrolling) */
    .search-bar{
      padding:6px 8px;
      border:1px solid rgba(0,0,0,.10);
      border-radius:6px;
      background:#fff;
      display:flex;
      align-items:center;
      gap:6px;
      margin: 6px;
    }
    #chatSearch{
      height:28px;
      border-radius:6px;
      font-size:11px;
    }
    .search-hint{font-size:10px;opacity:.7;white-space:nowrap;}

    /* ✅ right side split */
    .conv-split{
      flex:1 1 auto;
      min-height:0;
      overflow:hidden;
      display:flex;
      gap:6px;
      padding: 6px;
    }
    .conv-main{
      flex:1 1 auto;
      min-width:0;
      min-height:0;
      overflow:hidden;
      display:flex;
      flex-direction:column;
      gap:10px;
    }
    .conv-history{
      width: 34%;
      min-width: 240px;
      min-height:0;
      overflow:hidden;
      display:flex;
      flex-direction:column;
    }

    .conv-topbar{
      flex:0 0 auto;
      padding: 6px 8px;
      border: 1px solid rgba(0,0,0,.10);
      border-radius: 6px;
      /* background: #fff; */
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap:10px;
      margin: 6px;
      margin-bottom:0;
    }
    .conv-title{ min-width:0; }
    .conv-title strong{ display:block; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    .conv-title small{ opacity:.75; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; display:block; }

    /* ✅ Only left sidebar search */
    .members-search{
      flex:0 0 auto;
      padding:6px;
      border-bottom:1px solid rgba(0,0,0,.08);
    }
    .members-search input{
      width:100%;
      height:28px;
      border:1px solid rgba(0,0,0,.12);
      border-radius:6px;
      padding:4px 8px;
      outline:none;
      font-size:11px;
    }

    /* ✅ Only right history search */
    .history-search{
      flex:0 0 auto;
      padding:6px;
      border-bottom:1px solid rgba(0,0,0,.08);
    }
    .history-search input{
      width:100%;
      height:28px;
      border:1px solid rgba(0,0,0,.12);
      border-radius:6px;
      padding:4px 8px;
      outline:none;
      font-size:11px;
    }

    /* ✅ Only messages scroll */
    .msg-scroll{
      flex:1 1 auto;
      min-height:0;
      overflow:auto;
      border:1px solid rgba(0,0,0,.1);
      border-radius:6px;
      /* background:#fff; */
      padding:6px;
    }

    /* ✅ Composer fixed */
    .composer-fixed{
      flex:0 0 auto;
      border:1px solid rgba(0,0,0,.10);
      border-radius:10px;
      /* background:#fff; */
      padding: 6px;
    }

    .history-card{
      flex:1 1 auto;
      min-height:0;
      overflow:hidden;
      border:1px solid rgba(0,0,0,.10);
      border-radius:6px;
      /* background:#fff; */
      display:flex;
      flex-direction:column;
      border-color: #3e494e;
    }
    .history-head{
      flex:0 0 auto;
      padding:6px 8px;
      border-bottom:1px solid rgba(0,0,0,.08);
      display:flex;
      justify-content:space-between;
      align-items:center;
      gap:6px;
      font-size:11px;
    }
    .history-scroll{
      flex:1 1 auto;
      min-height:0;
      overflow:auto;
      padding:6px;
    }

    .members-list-wrap{
      border:1px solid rgba(0,0,0,.10);
      border-radius:6px;
      overflow:hidden;
      display:flex;
      flex-direction:column;
      min-height:0;
      /* background:#fff; */
    }
    .members-scroll{
      flex:1 1 auto;
      min-height:0;
      overflow:auto;
    }
    .members-scroll .list-group-item a{ display:block; }

    /* ✅ highlight matches */
    mark.chat-hit{
      padding:0 2px;
      border-radius:4px;
      background: rgba(255,193,7,.35);
    }
  </style>
</head>

<body class="org-app">
<?php include __DIR__ . '/includes/header.php'; ?>
<?php include __DIR__ . '/includes/leftbar.php'; ?>

<div class="sh-mainpanel">

  <div class="sh-pagebody">
    <!-- ✅ NEW: Search (fixed, does not scroll) -->
    <!-- <div class="search-bar">
      <i class="icon ion-ios-search-strong" style="font-size:22px;"></i>
      <input id="chatSearch" type="search" class="form-control" placeholder="Search members, messages, subjects... (Esc to clear)">
      <div class="search-hint">Filters: members • chat • history</div>
      <button id="chatSearchClear" type="button" class="btn btn-sm btn-outline-secondary">Clear</button>
    </div> -->
    <div class="chat-shell">
      <!-- LEFT: members -->
      <div class="chat-left">
        <div class="card bd-0 fixed-card">

          <div class="card-header bg-transparent pd-y-15 fixed-head">
            <h6 class="card-title mg-b-0" style="color:#a0a3a7;font-size:10px;text-transform:uppercase;">Members</h6>
          </div>

          <div class="fixed-body" style="padding:6px;">

            <div class="members-list-wrap">
              <!-- ✅ members search -->
              <div class="members-search">
                <input id="memberSearch" type="search" placeholder="Search members...">
              </div>

              <div class="members-scroll">
                <ul class="list-group list-group-flush" style="margin:0;">
                  <?php foreach ($peers as $p): ?>
                    <?php
                      $peerId = (int)$p['id'];
                      $active = ($peerId === (int)$peerMid);

                      $name = trim((string)($p['fullname'] ?? ''));
                      if ($name === '') $name = trim((string)($p['username'] ?? 'Member'));

                      [$presenceLabel, $presenceCls] = fmtPresence($p['last_seen'] ?? null);

                      $last = $lastByPeer[$peerId] ?? null;
                      $preview = '';
                      $when = '';
                      if ($last) {
                          $when = shortTime((string)($last['created_at'] ?? ''));
                          $hasAttach = !empty($last['attachment_id']) || !empty($last['file_path']);
                          $body = trim((string)($last['body'] ?? ''));
                          if ($body !== '') $preview = $body;
                          elseif ($hasAttach) $preview = '📎 Attachment';
                      }

                      $unread = (int)($unreadByPeer[$peerId] ?? 0);

                      $peerType = (string)($p['member_type'] ?? '');
                      $peerUid  = (int)($p['member_id'] ?? 0);
                      $peerAvatar = 'includes/avatar.php?type=' . urlencode($peerType) . '&id=' . $peerUid;
                    ?>
                    <li class="list-group-item <?= $active ? 'active' : '' ?>" data-peer-row>
                      <a href="messages.php?peer=<?= $peerId ?>" style="<?= $active ? 'color:#fff' : '' ?>">
                        <div class="peer-row">
                          <div class="peer-meta" style="display:flex;gap:6px;align-items:center;min-width:0;">
                            <img src="<?= h($peerAvatar) ?>" alt="" class="peer-avatar"
                                 style="width:30px;height:30px;border-radius:50%;object-fit:cover;border:1px solid rgba(0,0,0,.08);">
                            <div style="min-width:0;">
                              <p class="peer-name" data-peer-name><?= h($name) ?></p>
                              <?php if ($preview !== ''): ?>
                                <p class="peer-sub" data-peer-preview style="<?= $active ? 'color:#fff;opacity:.85' : '' ?>">
                                  <?= h($preview) ?>
                                </p>
                              <?php endif; ?>
                            </div>
                          </div>
                          <div class="peer-right">
                            <span class="<?= h($presenceCls) ?>"><?= h($presenceLabel) ?></span>
                            <div style="display:flex; gap:8px; align-items:center;">
                              <?php if ($when !== ''): ?>
                                <span class="peer-time" style="<?= $active ? 'color:#fff;opacity:.85' : '' ?>"><?= h($when) ?></span>
                              <?php endif; ?>
                              <span class="unread-pill" data-unread-pill="<?= (int)$peerId ?>" style="<?= ($unread > 0) ? '' : 'display:none;' ?>"><?= (int)$unread ?></span>
                            </div>
                          </div>
                        </div>
                      </a>
                    </li>
                  <?php endforeach; ?>
                </ul>
              </div>
            </div>

          </div>
        </div>
      </div>

      <!-- RIGHT: conversation -->
      <div class="chat-right">
        <div class="card bd-0 fixed-card">

          <div class="card-header bg-transparent fixed-head" style="padding:4px 8px;margin:6px;">
            <h6 class="card-title mg-b-0" style="color:#a0a3a7;font-size:10px;text-transform:uppercase;">Conversation</h6>
          </div>

          <div class="fixed-body">

            <?php if ($msgErr): ?>
              <div class="alert alert-danger" style="margin:10px;"><?= h($msgErr) ?></div>
            <?php endif; ?>

            <div style="padding:6px;">
              <?php if ($threadingEnabled && $peerMid > 0): ?>
                <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;margin-top:1px;">
                  <div style="min-width:0;padding: 10px;">
                    <small style="opacity:.7;">Subject:</small>
                    <strong style="max-width:100%;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                      <?php
                        $curSubject = '';
                        foreach ($threads as $th) {
                          if ((int)$th['id'] === (int)$selectedThreadId) { $curSubject = (string)($th['subject'] ?? ''); break; }
                        }
                        if ($curSubject === '') $curSubject = 'General';
                        echo h($curSubject);
                      ?>
                    </strong>
                  </div>
                  <div style="white-space:nowrap;">
                    <a class="btn btn-sm btn-outline-secondary" href="compose.php?peer=<?= (int)$peerMid ?>">New subject</a>
                  </div>
                </div>
              <?php endif; ?>

              <div class="conv-topbar">
                <div class="conv-title">
                  <strong><?= h($activePeerName !== '' ? $activePeerName : 'Conversation') ?></strong>
                  <?php if ($activePeerRel !== ''): ?>
                    <small><?= h($activePeerRel) ?></small>
                  <?php endif; ?>
                </div>

                <div style="display:flex;align-items:center;gap:10px;white-space:nowrap;">
                  <?php if ($peerMid > 0): ?>
                    <span class="<?= h($activePresenceCls) ?>"><?= h($activePresenceLabel) ?></span>
                  <?php endif; ?>
                </div>
              </div>
            </div>

            <div class="conv-split">

              <!-- MAIN conversation -->
              <div class="conv-main">

                <div id="msgBox" class="msg-scroll">
                  <?php foreach ($thread as $m): ?>
                    <?php $mine = ((int)$m['sender_member_id'] === (int)$meMemberId); ?>
                    <div class="msg-row <?= $mine ? 'me' : 'them' ?>">
                      <div class="<?= $mine ? 'msg-me' : 'msg-them' ?>">

                        <?php if (!empty($m['body'])): ?>
                          <?= nl2br(h((string)$m['body'])) ?>
                        <?php endif; ?>

                        <?php
                          $mime = (string)($m['attach_mime'] ?? ($m['file_type'] ?? ''));
                          $path = (string)($m['attach_path'] ?? ($m['file_path'] ?? ''));
                          $name = (string)($m['attach_name'] ?? ($m['file_name'] ?? 'Attachment'));
                        ?>

                        <?php if (!empty($m['attachment_id']) || $path !== ''): ?>
                          <?php if ($path !== ''): ?>
                            <div class="msg-attach" style="margin-top:6px;">
                              <?php if (strpos($mime, 'image/') === 0): ?>
                                <a href="#" class="attach-click"
                                   data-kind="image"
                                   data-src="<?= h($path) ?>"
                                   data-mime="<?= h($mime) ?>"
                                   data-name="<?= h($name) ?>">
                                  <img src="<?= h($path) ?>" alt="attachment" style="width:200px;height:150px;">
                                </a>
                              <?php elseif (strpos($mime, 'video/') === 0): ?>
                                <a href="#" class="attach-click"
                                   data-kind="video"
                                   data-src="<?= h($path) ?>"
                                   data-mime="<?= h($mime) ?>"
                                   data-name="<?= h($name) ?>">
                                  <video controls preload="metadata" style="height:150px;">
                                    <source src="<?= h($path) ?>" type="<?= h($mime) ?>">
                                  </video>
                                </a>
                              <?php elseif ($mime === 'application/pdf' || stripos($path, '.pdf') !== false): ?>
                                <a href="#" class="attach-click"
                                   data-kind="pdf"
                                   data-src="<?= h($path) ?>"
                                   data-mime="<?= h($mime) ?>"
                                   data-name="<?= h($name) ?>">
                                  <i class="icon ion-document-text" style="margin-right:6px;"></i><?= h($name) ?> (PDF)
                                </a>
                              <?php else: ?>
                                <a href="#" class="attach-click"
                                   data-kind="file"
                                   data-src="<?= h($path) ?>"
                                   data-mime="<?= h($mime) ?>"
                                   data-name="<?= h($name) ?>">
                                  <i class="icon ion-document-text" style="margin-right:6px;"></i><?= h($name) ?>
                                </a>
                              <?php endif; ?>
                            </div>
                          <?php endif; ?>
                        <?php endif; ?>

                        <?php
                          $who  = senderLabelFromRow($m, $mine);
                          $tm   = fmtTimeAMPM((string)($m['created_at'] ?? ''));
                          $isRead = (int)($m['is_read'] ?? 0) === 1 || !empty($m['read_at']);
                        ?>

                        <div style="font-size:11px;opacity:.7;margin-top:4px; display:flex; align-items:center; gap:6px;">
                          <span><?= h($who) ?></span>
                          <span style="opacity:.5;">•</span>
                          <span><?= h($tm) ?></span>

                          <?php if ($mine): ?>
                            <?php if ($isRead): ?>
                              <span class="msg-status read" title="Read">✓✓</span>
                            <?php else: ?>
                              <span class="msg-status sent" title="Sent (not read yet)">✓</span>
                            <?php endif; ?>
                          <?php endif; ?>
                        </div>

                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>

                <!-- Composer fixed -->
                <div class="composer-fixed">
                  <form method="post" id="msgForm">
                    <input type="hidden" name="peer_mid" value="<?= (int)$peerMid ?>">
                    <input type="hidden" name="thread_id" value="<?= (int)$selectedThreadId ?>">
                    <input type="hidden" name="attachment_id" id="attachmentId" value="0">

                    <div class="composer-row">
                      <i class="icon ion-happy-outline" style="font-size:18px;cursor:pointer;" id="emojiBtn" title="Emoji"></i>
                      <i class="icon ion-paperclip" style="font-size:18px;cursor:pointer;" id="attachBtn" title="Attach"></i>

                      <input type="file" id="attachFile" class="d-none" accept="image/*,video/*,application/pdf">

                      <div class="form-group">
                        <textarea name="body" class="form-control"
                                  style="height:57px;border: solid;border-color:lightgrey;"
                                  rows="3" placeholder="Type a message..."></textarea>
                        <div id="attachStatus"></div>
                      </div>

                      <div style="white-space:nowrap;">
                        <button class="btn btn-primary" type="submit">
                          <i class="fa fa-send" style="font-size:14px;"></i>
                        </button>
                      </div>
                    </div>
                  </form>
                </div>

              </div>

              <!-- HISTORY sidebar -->
              <div class="conv-history">
                <div class="history-card">
                  <div class="history-head">
                    <strong>History</strong>
                    <small style="opacity:.7;">Subjects</small>
                  </div>

                  <!-- ✅ history search -->
                  <div class="history-search">
                    <input id="subjectSearch" type="search" placeholder="Search subjects...">
                  </div>

                  <div class="history-scroll" id="historyScroll">
                    <?php if (!$threadingEnabled || $peerMid <= 0): ?>
                      <div style="opacity:.7;font-size:12px;">No subject history.</div>
                    <?php else: ?>
                      <?php foreach ($threads as $th): ?>
                        <?php
                          $tid = (int)($th['id'] ?? 0);
                          $sub = trim((string)($th['subject'] ?? ''));
                          if ($sub === '') $sub = 'General';
                          $when = shortTime((string)($th['created_at'] ?? ''));
                          $isCur = ($tid === (int)$selectedThreadId);
                        ?>
                        <a href="messages.php?peer=<?= (int)$peerMid ?>&thread=<?= $tid ?>" style="text-decoration:none;" data-subject-row>
                          <div style="padding:8px;border-radius:10px;border:1px solid rgba(0,0,0,.08);margin-bottom:8px;<?= $isCur ? 'background:rgba(8,97,188,.10);' : 'background:transparent;' ?>">
                            <div style="font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" data-subject-text>
                              <?= h($sub) ?>
                            </div>
                            <?php if ($when !== ''): ?>
                              <div style="font-size:11px;opacity:.7;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                                <?= h($when) ?>
                              </div>
                            <?php endif; ?>
                          </div>
                        </a>
                      <?php endforeach; ?>
                    <?php endif; ?>
                  </div>

                </div>
              </div>

            </div>

          </div>
        </div>
      </div>

    </div>
  </div>

  <?php include __DIR__ . '/includes/footer.php'; ?>
</div>

<!-- Emoji popup -->
<div id="emojiBoxFixed" class="card">
  <div class="card-body">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
      <strong>Emojis</strong>
      <button type="button" class="btn btn-sm btn-light" id="emojiClose">×</button>
    </div>

    <input type="text" id="emojiSearch" class="form-control form-control-sm" placeholder="Search emoji..." style="margin-bottom:8px;">
    <div id="emojiGrid"></div>
  </div>
</div>

<!-- Attachment modal -->
<div class="modal fade" id="attachModal" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-lg" role="document" style="max-width:900px;">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="attachModalTitle">Attachment</h5>
        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>
      <div class="modal-body" id="attachModalBody" style="min-height:200px;"></div>
      <div class="modal-footer">
        <a href="#" target="_blank" rel="noopener" class="btn btn-outline-secondary" id="attachOpenNew">Open in new tab</a>
        <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<!-- ✅ REQUIRED JS for popup (Bootstrap modal) -->
<!-- <script src="../lib/jquery/jquery.js"></script>
<script src="../lib/popper.js/popper.js"></script>
<script src="../lib/bootstrap/bootstrap.js"></script> -->

<script>
  // ✅ keep chat at bottom (only msg list scrolls)
  (function(){
    var box = document.getElementById('msgBox');
    if (box) box.scrollTop = box.scrollHeight;
  })();
</script>

<!-- ✅ members search (left list) -->
<script>
(function(){
  var inp = document.getElementById('memberSearch');
  if(!inp) return;

  inp.addEventListener('input', function(){
    var q = (inp.value || '').toLowerCase().trim();
    document.querySelectorAll('[data-peer-row]').forEach(function(row){
      var name = (row.querySelector('[data-peer-name]')?.innerText || '').toLowerCase();
      var prev = (row.querySelector('[data-peer-preview]')?.innerText || '').toLowerCase();
      row.style.display = (!q || name.includes(q) || prev.includes(q)) ? '' : 'none';
    });
  });
})();
</script>

<!-- ✅ subject search (history) -->
<script>
(function(){
  var inp = document.getElementById('subjectSearch');
  if(!inp) return;

  inp.addEventListener('input', function(){
    var q = (inp.value || '').toLowerCase().trim();
    document.querySelectorAll('[data-subject-row]').forEach(function(row){
      var t = (row.querySelector('[data-subject-text]')?.innerText || '').toLowerCase();
      row.style.display = (!q || t.includes(q)) ? '' : 'none';
    });
  });
})();
</script>

<!-- ✅ ATTACHMENTS upload (attach icon opens picker + uploads) -->
<script>
(function(){
  var attachBtn = document.getElementById('attachBtn');
  var attachFile = document.getElementById('attachFile');
  var attachStatus = document.getElementById('attachStatus');
  var attachmentId = document.getElementById('attachmentId');

  if (!attachBtn || !attachFile || !attachStatus || !attachmentId) return;

  function escHtml(s){
    return (s || '').toString()
      .replace(/&/g,'&amp;')
      .replace(/</g,'&lt;')
      .replace(/>/g,'&gt;')
      .replace(/"/g,'&quot;')
      .replace(/'/g,'&#039;');
  }

  function showStatus(payload, type){
    attachStatus.style.display = 'block';
    attachStatus.className = (type === 'err') ? 'err' : '';

    var name = (payload && payload.name) ? payload.name : 'Attachment';
    var msg  = (payload && payload.message) ? payload.message : '';

    var thumbHtml = '';
    if (payload && payload.kind === 'image' && payload.path) {
      thumbHtml = '<img class="attach-thumb" src="' + payload.path + '" alt="">';
    } else {
      thumbHtml = '<img class="attach-thumb" src="../img/file-thumb.png" alt="">';
    }

    attachStatus.innerHTML =
      '<div class="attach-inline">' +
        '<div class="attach-left">' +
          thumbHtml +
          '<div class="attach-name">' +
            (type === 'err'
              ? ('Upload error: ' + escHtml(msg || 'Unknown error'))
              : ('Attached: <strong>' + escHtml(name) + '</strong>')) +
          '</div>' +
        '</div>' +
        '<button type="button" class="attach-x" id="attachRemoveBtn" title="Remove">×</button>' +
      '</div>';

    var btn = document.getElementById('attachRemoveBtn');
    if (btn) {
      btn.addEventListener('click', function(){
        attachmentId.value = '0';
        attachFile.value = '';
        attachStatus.style.display = 'none';
        attachStatus.innerHTML = '';
      });
    }
  }

  attachBtn.addEventListener('click', function(e){
    e.preventDefault();
    e.stopPropagation();
    attachFile.click();
  });

  attachFile.addEventListener('change', async function(){
    if (!attachFile.files || !attachFile.files[0]) return;
    var f = attachFile.files[0];

    showStatus({ name: (f.name || 'file'), message: 'Uploading...' }, 'ok');

    try {
      var fd = new FormData();
      fd.append('file', f);

      var res = await fetch('upload_org_media.php', {
        method: 'POST',
        body: fd,
        credentials: 'same-origin'
      });

      var data = await res.json().catch(function(){ return null; });
      if (!res.ok || !data || !data.ok) {
        throw new Error((data && data.error) ? data.error : ('Upload failed (' + res.status + ')'));
      }

      attachmentId.value = String(data.attachment_id || 0);

      var kind = 'file';
      if (data.mime && data.mime.indexOf('image/') === 0) kind = 'image';
      else if (data.mime && data.mime.indexOf('video/') === 0) kind = 'video';
      else if (data.mime === 'application/pdf' || (data.path && data.path.toLowerCase().indexOf('.pdf') !== -1)) kind = 'pdf';

      showStatus({
        kind: kind,
        name: (data.name || f.name || 'Attachment'),
        path: (data.path || ''),
        mime: (data.mime || '')
      }, 'ok');

    } catch (e) {
      attachmentId.value = '0';
      showStatus({ message: (e && e.message ? e.message : 'Unknown error') }, 'err');
    }
  });
})();
</script>

<!-- ✅ EMOJI PICKER -->
<script>
(function(){
  var emojiBtn = document.getElementById('emojiBtn');
  var emojiBox = document.getElementById('emojiBoxFixed');
  var emojiClose = document.getElementById('emojiClose');
  var emojiGrid = document.getElementById('emojiGrid');
  var emojiSearch = document.getElementById('emojiSearch');
  var textarea = document.querySelector('textarea[name="body"]');

  if (!emojiBtn || !emojiBox || !emojiGrid || !textarea) return;

  var EMOJIS = [
    "😀","😁","😂","🤣","😊","😍","😘","😎","🤩","🤗",
    "😅","😇","🙂","🙃","😉","😋","😜","🤪","😏","😴",
    "😢","😭","😤","😡","🤬","😱","😳","🥺","🙏","💪",
    "👍","👎","👏","🙌","🤝","❤️","🧡","💛","💚","💙",
    "💜","🖤","🔥","✨","🎉","✅","❌","⚠️","📌","📎"
  ];

  function renderEmojis(filter){
    emojiGrid.innerHTML = "";
    var f = (filter || "").trim();
    var list = EMOJIS.filter(function(e){
      if (!f) return true;
      return e.indexOf(f) !== -1;
    });

    list.forEach(function(e){
      var b = document.createElement('button');
      b.type = "button";
      b.className = "btn btn-light btn-sm";
      b.textContent = e;
      b.addEventListener('click', function(){
        var start = textarea.selectionStart || 0;
        var end = textarea.selectionEnd || 0;
        var v = textarea.value || "";
        textarea.value = v.substring(0, start) + e + v.substring(end);
        var pos = start + e.length;
        textarea.selectionStart = textarea.selectionEnd = pos;
        textarea.focus();
      });
      emojiGrid.appendChild(b);
    });
  }

  function openEmojiBox(){
    renderEmojis("");
    if (emojiSearch) emojiSearch.value = "";

    var r = emojiBtn.getBoundingClientRect();
    var left = r.left;
    var top  = r.bottom + 8;

    var boxW = 320;
    var boxH = 300;
    var vw = window.innerWidth || document.documentElement.clientWidth;
    var vh = window.innerHeight || document.documentElement.clientHeight;

    if (left + boxW > vw - 10) left = vw - boxW - 10;
    if (top + boxH > vh - 10) top = r.top - boxH - 8;
    if (top < 10) top = 10;
    if (left < 10) left = 10;

    emojiBox.style.left = left + "px";
    emojiBox.style.top  = top + "px";
    emojiBox.style.display = "block";
    if (emojiSearch) emojiSearch.focus();
  }

  function closeEmojiBox(){ emojiBox.style.display = "none"; }

  emojiBtn.addEventListener('click', function(e){
    e.preventDefault();
    e.stopPropagation();
    if (emojiBox.style.display === "block") closeEmojiBox();
    else openEmojiBox();
  });

  if (emojiClose) emojiClose.addEventListener('click', function(e){
    e.preventDefault();
    closeEmojiBox();
  });

  document.addEventListener('click', function(e){
    if (emojiBox.style.display === "block") {
      if (!emojiBox.contains(e.target) && e.target !== emojiBtn) closeEmojiBox();
    }
  });

  window.addEventListener('resize', closeEmojiBox);

  if (emojiSearch) {
    emojiSearch.addEventListener('input', function(){
      renderEmojis(emojiSearch.value);
    });
  }
})();
</script>

<!-- ✅ Attachment modal viewer -->
<script>
(function(){
  var modalTitle = document.getElementById('attachModalTitle');
  var modalBody  = document.getElementById('attachModalBody');
  var openNew    = document.getElementById('attachOpenNew');

  function showModal(kind, src, mime, name){
    if (!modalTitle || !modalBody || !openNew) return;

    modalTitle.textContent = name || 'Attachment';
    openNew.href = src || '#';

    var html = '';
    if (kind === 'image') {
      html = '<div class="text-center"><img src="' + src + '" style="max-width:100%;height:auto;border-radius:12px;"></div>';
    } else if (kind === 'video') {
      html = '<video controls style="max-width:100%;height:auto;border-radius:12px;"><source src="' + src + '" type="' + (mime || '') + '"></video>';
    } else if (kind === 'pdf') {
      html = '<iframe src="' + src + '" style="width:100%;height:70vh;border:0;border-radius:12px;"></iframe>';
    } else {
      html = '<div class="alert alert-info">This file cannot be previewed here. Use <strong>Open in new tab</strong>.</div>';
    }

    modalBody.innerHTML = html;

    if (window.jQuery && jQuery.fn && jQuery.fn.modal) {
      jQuery('#attachModal').modal('show');
    } else {
      window.open(src, '_blank');
    }
  }

  document.addEventListener('click', function(e){
    var a = e.target.closest ? e.target.closest('.attach-click') : null;
    if (!a) return;
    e.preventDefault();

    var kind = a.getAttribute('data-kind') || 'file';
    var src  = a.getAttribute('data-src') || '';
    var mime = a.getAttribute('data-mime') || '';
    var name = a.getAttribute('data-name') || 'Attachment';

    if (!src) return;
    showModal(kind, src, mime, name);
  });
})();
</script>

<!-- Live unread badges: header + peer pills -->
<script>
(function(){
  var pollMs = 5000;

  function ensureHeaderBadge(count){
    var chip = document.querySelector('.org-header-chip--messages');
    if (!chip) return;

    var badge = document.getElementById('headerUnreadBadge');
    var label = (count > 99) ? '99+' : String(count);
    chip.classList.toggle('has-unread', count > 0);

    if (count <= 0) {
      if (badge) {
        badge.textContent = '';
        badge.classList.remove('is-visible', 'pop');
      }
      return;
    }

    if (!badge) {
      badge = chip.querySelector('.org-header-chip__count');
      if (badge) badge.id = 'headerUnreadBadge';
    }
    if (!badge) return;

    if (badge.textContent !== label) {
      badge.textContent = label;
      badge.classList.add('is-visible');
      badge.classList.remove('pop');
      void badge.offsetWidth;
      badge.classList.add('pop');
      return;
    }

    badge.classList.add('is-visible');
  }

  function ensureFeedBadge(count){
    var chip = document.querySelector('.org-header-chip--alerts');
    if (!chip) return;

    var badge = document.getElementById('headerFeedUnreadBadge');
    var label = (count > 99) ? '99+' : String(count);
    chip.classList.toggle('has-unread', count > 0);

    if (count <= 0) {
      if (badge) {
        badge.textContent = '';
        badge.classList.remove('is-visible', 'pop');
      }
      return;
    }

    if (!badge) {
      badge = chip.querySelector('.org-header-chip__count');
      if (badge) badge.id = 'headerFeedUnreadBadge';
    }
    if (!badge) return;

    if (badge.textContent !== label) {
      badge.textContent = label;
      badge.classList.add('is-visible');
      badge.classList.remove('pop');
      void badge.offsetWidth;
      badge.classList.add('pop');
      return;
    }

    badge.classList.add('is-visible');
  }

  function updatePeerPills(byPeer){
    document.querySelectorAll('[data-unread-pill]').forEach(function(el){
      el.style.display = 'none';
      el.textContent = '0';
    });
    if (!byPeer) return;
    Object.keys(byPeer).forEach(function(peerId){
      var c = parseInt(byPeer[peerId], 10) || 0;
      var pill = document.querySelector('[data-unread-pill="' + peerId + '"]');
      if (!pill) return;
      if (c > 0) {
        pill.textContent = String(c);
        pill.style.display = 'inline-flex';
      }
    });
  }

  async function poll(){
    try {
      var res = await fetch('ajax/ajax_unread_counts.php', { credentials: 'same-origin' });
      var data = await res.json();
      if (!data || !data.ok) return;
      ensureHeaderBadge(parseInt(data.total, 10) || 0);
      ensureFeedBadge(parseInt(data.feedUnread, 10) || 0);
      updatePeerPills(data.byPeer || {});
    } catch (e) {}
  }

  document.addEventListener('click', function(e){
    var a = e.target.closest ? e.target.closest('a[href*="messages.php?peer="]') : null;
    if (!a) return;
    var m = (a.getAttribute('href') || '').match(/peer=(\d+)/);
    if (!m) return;
    var peer = parseInt(m[1], 10) || 0;
    if (!peer) return;

    try {
      var fd = new FormData();
      fd.append('peer', String(peer));
      fetch('ajax/ajax_mark_read.php', { method:'POST', body: fd, credentials: 'same-origin' })
        .then(function(){ poll(); })
        .catch(function(){});
    } catch (err) {}
  }, true);

  poll();
  setInterval(poll, pollMs);
})();
</script>

<!-- ✅ members search (left list) -->
<script>
(function(){
  var inp = document.getElementById('memberSearch');
  if(!inp) return;

  inp.addEventListener('input', function(){
    var q = (inp.value || '').toLowerCase().trim();
    document.querySelectorAll('[data-peer-row]').forEach(function(row){
      var name = (row.querySelector('[data-peer-name]')?.innerText || '').toLowerCase();
      var prev = (row.querySelector('[data-peer-preview]')?.innerText || '').toLowerCase();
      row.style.display = (!q || name.includes(q) || prev.includes(q)) ? '' : 'none';
    });
  });
})();
</script>

<!-- ✅ subject search (history) -->
<script>
(function(){
  var inp = document.getElementById('subjectSearch');
  if(!inp) return;

  inp.addEventListener('input', function(){
    var q = (inp.value || '').toLowerCase().trim();
    document.querySelectorAll('[data-subject-row]').forEach(function(row){
      var t = (row.querySelector('[data-subject-text]')?.innerText || '').toLowerCase();
      row.style.display = (!q || t.includes(q)) ? '' : 'none';
    });
  });
})();
</script>

<!-- <script>
  /**
   * ✅ SEARCH (members + messages + history)
   * - No reload
   * - Filters by [data-search]
   * - Highlights matches
   * - ESC clears
   */
  (function(){
    var input = document.getElementById('chatSearch');
    var clearBtn = document.getElementById('chatSearchClear');
    if (!input) return;

    function norm(s){ return (s || '').toString().toLowerCase().trim(); }

    function clearMarks(root){
      if (!root) return;
      root.querySelectorAll('mark.chat-hit').forEach(function(m){
        var t = document.createTextNode(m.textContent || '');
        m.parentNode.replaceChild(t, m);
        // merge text nodes is not needed; browser handles it
      });
    }

    function markText(el, q){
      if (!el || !q) return;
      // only highlight inside small elements to avoid breaking layout
      var targets = el.querySelectorAll('.peer-name,.peer-sub,.msg-text,.history-item');
      targets.forEach(function(node){
        // don't double mark
        if (node.querySelector && node.querySelector('mark.chat-hit')) return;

        var text = node.textContent || '';
        var low = text.toLowerCase();
        var idx = low.indexOf(q);
        if (idx < 0) return;

        var before = text.slice(0, idx);
        var hit = text.slice(idx, idx + q.length);
        var after = text.slice(idx + q.length);

        node.innerHTML = '';
        node.appendChild(document.createTextNode(before));
        var mk = document.createElement('mark');
        mk.className = 'chat-hit';
        mk.textContent = hit;
        node.appendChild(mk);
        node.appendChild(document.createTextNode(after));
      });
    }

    function filterAll(){
      var q = norm(input.value);

      // remove old highlights
      clearMarks(document.getElementById('peerList'));
      clearMarks(document.getElementById('msgBox'));
      clearMarks(document.getElementById('historyScroll'));

      // Members
      document.querySelectorAll('#peerList .list-group-item').forEach(function(li){
        var hay = norm(li.getAttribute('data-search'));
        li.style.display = (!q || hay.indexOf(q) !== -1) ? '' : 'none';
        if (q && hay.indexOf(q) !== -1) markText(li, q);
      });

      // Messages
      document.querySelectorAll('#msgBox .msg-row').forEach(function(row){
        var hay = norm(row.getAttribute('data-search'));
        row.style.display = (!q || hay.indexOf(q) !== -1) ? '' : 'none';
        if (q && hay.indexOf(q) !== -1) markText(row, q);
      });

      // History
      document.querySelectorAll('#historyScroll .history-item').forEach(function(it){
        var hay = norm(it.getAttribute('data-search'));
        it.style.display = (!q || hay.indexOf(q) !== -1) ? '' : 'none';
        if (q && hay.indexOf(q) !== -1) markText(it, q);
      });
    }

    input.addEventListener('input', filterAll);

    if (clearBtn){
      clearBtn.addEventListener('click', function(){
        input.value = '';
        filterAll();
        input.focus();
      });
    }

    document.addEventListener('keydown', function(e){
      if (e.key === 'Escape'){
        input.value = '';
        filterAll();
      }
      if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k'){
        e.preventDefault();
        input.focus();
      }
    });
  })();
</script> -->

</body>
</html>
