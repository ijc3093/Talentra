<?php
// /Business_only3/organization/compose.php
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

function shortTime(?string $dt): string {
    if (!$dt) return '';
    $ts = strtotime($dt);
    if ($ts <= 0) return (string)$dt;
    return date('M j, g:i A', $ts);
}

// Resolve my display name
$meDisplay = 'You';
try {
    $t  = (string)orgAccountType();
    $id = (int)orgAccountId();
    if ($id > 0) {
        if ($t === 'manager') {
            $st = $dbh->prepare("SELECT fullname, username FROM managers WHERE id = :id LIMIT 1");
            $st->execute([':id' => $id]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if ($r) {
                $meDisplay = trim((string)($r['fullname'] ?? '')) ?: trim((string)($r['username'] ?? 'You'));
            }
        } elseif ($t === 'staff') {
            $st = $dbh->prepare("SELECT fullname, username FROM staff_accounts WHERE id = :id LIMIT 1");
            $st->execute([':id' => $id]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if ($r) {
                $meDisplay = trim((string)($r['fullname'] ?? '')) ?: trim((string)($r['username'] ?? 'You'));
            }
        }
    }
} catch (Throwable $e) {
    // ignore
}

// Peers
$sqlPeers = "
  SELECT
    om.id,
    om.member_type,
    om.member_id,
    om.relationship_label,
    COALESCE(m.fullname, s.fullname, '') AS fullname,
    COALESCE(m.username, s.username, '') AS username
  FROM org_members om
  LEFT JOIN managers m
    ON om.member_type = 'manager' AND m.id = om.member_id
  LEFT JOIN staff_accounts s
    ON om.member_type = 'staff' AND s.id = om.member_id
  WHERE om.org_id = :org
    AND om.status = 1
    AND om.id <> :me
  ORDER BY om.id DESC
";
$stPeers = $dbh->prepare($sqlPeers);
$stPeers->execute([':org' => $orgId, ':me' => $meMemberId]);
$peers = $stPeers->fetchAll(PDO::FETCH_ASSOC);

// Preselect peer
$peerMid = (int)($_GET['peer'] ?? 0);
if ($peerMid <= 0 && !empty($peers)) $peerMid = (int)$peers[0]['id'];

// Threading available?
$threadingEnabled = false;
try {
    $stChk = $dbh->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'org_message_threads' LIMIT 1");
    $stChk->execute();
    $threadingEnabled = (bool)$stChk->fetchColumn();
} catch (Throwable $e) {
    $threadingEnabled = false;
}

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $peerMidPost  = (int)($_POST['peer_mid'] ?? 0);
    $subject      = trim((string)($_POST['subject'] ?? ''));
    $body         = trim((string)($_POST['body'] ?? ''));
    $attachmentId = (int)($_POST['attachment_id'] ?? 0);

    if ($peerMidPost <= 0) {
        $err = 'Receiver is required.';
    } elseif ($subject === '') {
        $err = 'Subject is required.';
    } elseif ($body === '' && $attachmentId <= 0) {
        $err = 'Message or attachment required.';
    } else {
        // Verify peer belongs to same org
        $stV = $dbh->prepare("SELECT id FROM org_members WHERE org_id = :org AND id = :id AND status = 1 LIMIT 1");
        $stV->execute([':org' => $orgId, ':id' => $peerMidPost]);

        if (!$stV->fetch()) {
            $err = 'Invalid receiver.';
        } else {
            // Create new subject thread (if threading schema exists). Otherwise, just send as normal message.
            $threadId = 0;
            if ($threadingEnabled) {
                try {
                    $pairA = min($meMemberId, $peerMidPost);
                    $pairB = max($meMemberId, $peerMidPost);

                    // UID uses org + pair + timestamp + random; stored in session to ensure "this submit" is unique.
                    $uid = 'THR-' . $orgId . '-' . $pairA . '-' . $pairB . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(4));
                    $_SESSION['last_thread_uid'] = $uid;

                    $stNew = $dbh->prepare("INSERT INTO org_message_threads (org_id, member_a_id, member_b_id, subject, uid, created_by_member_id, created_at, last_message_at) VALUES (:org,:a,:b,:sub,:uid,:by,NOW(),NOW())");
                    $stNew->execute([
                        ':org' => $orgId,
                        ':a'   => $pairA,
                        ':b'   => $pairB,
                        ':sub' => $subject,
                        ':uid' => $uid,
                        ':by'  => $meMemberId,
                    ]);
                    $threadId = (int)$dbh->lastInsertId();
                } catch (Throwable $e) {
                    $threadId = 0;
                }
            }

            $msgType = ($attachmentId > 0 && $body === '') ? 'media' : 'direct';

            // Insert message (try new schema first)
            $insertOk = false;
            if ($threadingEnabled && $threadId > 0) {
                try {
                    $stIns = $dbh->prepare("INSERT INTO org_messages (org_id, thread_id, sender_member_id, receiver_member_id, msg_type, body, attachment_id, created_at) VALUES (:org,:tid,:s,:r,:t,:b,:aid,NOW())");
                    $stIns->execute([
                        ':org' => $orgId,
                        ':tid' => $threadId,
                        ':s'   => $meMemberId,
                        ':r'   => $peerMidPost,
                        ':t'   => $msgType,
                        ':b'   => ($body === '' ? null : $body),
                        ':aid' => ($attachmentId > 0 ? $attachmentId : null),
                    ]);
                    $insertOk = true;
                } catch (Throwable $e) {
                    $insertOk = false;
                }
            }

            if (!$insertOk) {
                $stIns = $dbh->prepare("INSERT INTO org_messages (org_id, sender_member_id, receiver_member_id, msg_type, body, attachment_id, created_at) VALUES (:org,:s,:r,:t,:b,:aid,NOW())");
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
            if ($threadingEnabled && $threadId > 0) {
                $redir .= "&thread=" . $threadId;
            }
            header("Location: " . $redir);
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once __DIR__ . '/includes/org_theme_head.php'; ?>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
  <title><?= h($ORG['name']) ?> - Compose</title>

  <link href="../lib/font-awesome/css/font-awesome.css" rel="stylesheet">
  <link href="../lib/Ionicons/css/ionicons.css" rel="stylesheet">
  <link href="../lib/perfect-scrollbar/css/perfect-scrollbar.css" rel="stylesheet">
  <link href="../lib/bootstrap/bootstrap.css" rel="stylesheet">
  <link rel="stylesheet" href="../css/shamcey.css">
  <?php require_once __DIR__ . '/includes/org_layout.php'; org_layout_head_assets(); ?>

  <style>
    .composer-row{display:flex; gap:10px; align-items:flex-end;}
    .composer-row .form-group{flex:1; margin:0;}
    #attachStatus{margin-top:6px;}
    .attach-inline{
      display:flex;align-items:center;justify-content:space-between;
      gap:10px;padding:8px;border-radius:10px;border:1px solid rgba(0,0,0,.1);
      background:#f8f9fa;
    }
    .attach-left{display:flex;align-items:center;gap:10px;min-width:0;}
    .attach-thumb{width:34px;height:34px;border-radius:8px;object-fit:cover;background:#fff;border:1px solid rgba(0,0,0,.08);}
    .attach-name{font-size:12px;opacity:.85;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
    .attach-x{
      appearance:none;border:0;background:#fff;border:1px solid rgba(0,0,0,.15);
      width:26px;height:26px;border-radius:999px;line-height:24px;
      text-align:center;cursor:pointer;font-size:16px;
    }
    #attachStatus.err .attach-inline{background:#fff5f5;border-color:#f1b0b7;}
  </style>
</head>
<body>

<?php include __DIR__ . '/includes/header.php'; ?>
<?php include __DIR__ . '/includes/leftbar.php'; ?>

<div class="sh-mainpanel">
  <div class="sh-pagetitle" style="border-bottom: 1px solid #4a535c;">
    <div class="input-group">
      <!-- <input type="search" class="form-control" placeholder="Search">
      <span class="input-group-btn">
        <button class="btn btn-search" type="button"><i class="fa fa-search"></i></button>
      </span> -->
    </div>
    <div class="sh-pagetitle-left">
      <div class="sh-pagetitle-icon"><i class="icon ion-ios-chatbubble"></i></div>
      <div class="sh-pagetitle-title">
        <span>New subject message</span>
        <h2>Compose</h2>
      </div>
    </div>
  </div>

  <div class="sh-pagebody">
    <div class="row row-sm">
      <div class="col-lg-12">
        <div class="card bd-0">
          <div class="card-header bg-transparent">
            <h6 class="card-title mg-b-0">Compose</h6>
          </div>
          <div class="card-body">

            <?php if ($err): ?>
              <div class="alert alert-danger"><?= h($err) ?></div>
            <?php endif; ?>

            <form method="post" id="composeForm">
              <div class="form-group">
                <label>To (Member)</label>
                <select name="peer_mid" class="form-control" required>
                  <?php foreach ($peers as $p): ?>
                    <?php
                      $pid = (int)($p['id'] ?? 0);
                      $nm = trim((string)($p['fullname'] ?? ''));
                      if ($nm === '') $nm = trim((string)($p['username'] ?? 'Member'));
                    ?>
                    <option value="<?= $pid ?>" <?= ($pid === $peerMid) ? 'selected' : '' ?>><?= h($nm) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="form-group">
                <label>From (Name)</label>
                <input type="text" class="form-control" value="<?= h($meDisplay) ?>" readonly>
              </div>

              <div class="form-group">
                <label>Subject</label>
                <input type="text" name="subject" class="form-control" required placeholder="Type subject...">
              </div>

              <input type="hidden" name="attachment_id" id="attachmentId" value="0">

              <div class="composer-row">
                <i class="icon ion-paperclip" style="font-size:35px;cursor:pointer;" id="attachBtn" title="Attach"></i>
                <input type="file" id="attachFile" class="d-none" accept="image/*,video/*,application/pdf">

                <div class="form-group">
                  <textarea name="body" class="form-control" style="height:120px;" rows="5" placeholder="Write your message..."></textarea>

                  <!-- attachment preview inside textarea area -->
                  <div id="attachStatus" style="display:none;"></div>
                </div>

                <div style="white-space:nowrap;">
                  <button class="btn btn-primary"><i class="fa fa-send"></i> Send</button>
                </div>
              </div>
            </form>

            <div class="mg-t-20">
              <a href="messages.php?peer=<?= (int)$peerMid ?>" class="btn btn-outline-secondary btn-sm">Back to messages</a>
            </div>

          </div>
        </div>
      </div>
    </div>
  </div>

  <?php include __DIR__ . '/includes/footer.php'; ?>
</div>

<script>
(function(){
  // --- ATTACHMENTS upload (INLINE preview inside textarea) ---
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
      .replace(/\\"/g,'&quot;')
      .replace(/'/g,'&#039;');
  }

  function showStatus(payload, type){
    attachStatus.style.display = 'block';
    attachStatus.className = (type === 'err') ? 'err' : '';

    var name = (payload && payload.name) ? payload.name : 'Attachment';
    var msg  = (payload && payload.message) ? payload.message : '';

    var thumbHtml = '<img class="attach-thumb" src="../img/file-thumb.png" alt="">';
    if (payload && payload.kind === 'image' && payload.path) {
      thumbHtml = '<img class="attach-thumb" src="' + payload.path + '" alt="">';
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

  attachBtn.addEventListener('click', function(){
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

      var data = await res.json();
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

</body>
</html>
