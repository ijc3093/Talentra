<?php
// /Business_only3/organization/groupchat.php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors','1');

require_once __DIR__ . '/includes/session_org.php';
require_once __DIR__ . '/includes/org_context.php';

$orgId = orgActiveOrgId();
$meMid = orgMemberId();

if (!function_exists('h')) {
  function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}

// room id
$roomId = (int)($_GET['room'] ?? 0);
if ($roomId <= 0) {
  header("Location: rooms.php");
  exit;
}

// verify room in org
$stR = $dbh->prepare("SELECT id, name FROM org_rooms WHERE id=:id AND org_id=:org AND status=1 LIMIT 1");
$stR->execute([':id'=>$roomId, ':org'=>$orgId]);
$ROOM = $stR->fetch(PDO::FETCH_ASSOC);
if (!$ROOM) {
  header("Location: rooms.php");
  exit;
}

// ensure I'm a member of this room (auto-join manager)
$stM = $dbh->prepare("SELECT status FROM org_room_members WHERE room_id=:rid AND member_id=:mid LIMIT 1");
$stM->execute([':rid'=>$roomId, ':mid'=>$meMid]);
$rm = $stM->fetch(PDO::FETCH_ASSOC);

if (!$rm || (int)($rm['status'] ?? 0) !== 1) {
  if (isOrgManager()) {
    // auto-join manager
    $stJ = $dbh->prepare("
      INSERT INTO org_room_members (room_id, member_id, role, status, joined_at)
      VALUES (:rid, :mid, 'admin', 1, NOW())
      ON DUPLICATE KEY UPDATE status=1
    ");
    $stJ->execute([':rid'=>$roomId, ':mid'=>$meMid]);
  } else {
    header("Location: rooms.php");
    exit;
  }
}

function memberName(PDO $dbh, int $memberRowId): string {
  $st = $dbh->prepare("SELECT member_type, member_id FROM org_members WHERE id=:id LIMIT 1");
  $st->execute([':id'=>$memberRowId]);
  $m = $st->fetch(PDO::FETCH_ASSOC);
  if (!$m) return 'member';

  if (($m['member_type'] ?? '') === 'manager') {
    $st2 = $dbh->prepare("SELECT fullname, username FROM managers WHERE id=:id LIMIT 1");
  } else {
    $st2 = $dbh->prepare("SELECT fullname, username FROM staff_accounts WHERE id=:id LIMIT 1");
  }
  $st2->execute([':id'=>(int)$m['member_id']]);
  $u = $st2->fetch(PDO::FETCH_ASSOC) ?: [];
  $name = trim((string)($u['fullname'] ?? ''));
  if ($name === '') $name = (string)($u['username'] ?? 'member');
  return $name;
}

function renderAttachment(PDO $dbh, ?int $aid): string {
  if (!$aid || $aid <= 0) return '';
  $st = $dbh->prepare("SELECT path, mime_type, original_name FROM org_attachments WHERE id=:id LIMIT 1");
  $st->execute([':id'=>$aid]);
  $a = $st->fetch(PDO::FETCH_ASSOC);
  if (!$a) return '';
  $path = (string)($a['path'] ?? '');
  $mime = (string)($a['mime_type'] ?? '');
  $name = (string)($a['original_name'] ?? 'file');

  if (strpos($mime, 'image/') === 0) {
    return '<div class="mg-t-5"><img src="'.h($path).'" style="max-width:260px;border-radius:10px;"></div>';
  }
  if (strpos($mime, 'video/') === 0) {
    return '<div class="mg-t-5"><video src="'.h($path).'" controls style="max-width:320px;border-radius:10px;"></video></div>';
  }
  return '<div class="mg-t-5"><a target="_blank" href="'.h($path).'"><i class="icon ion-document"></i> '.h($name).'</a></div>';
}

$msgErr = '';

// send text message
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_text'])) {
  $body = trim((string)($_POST['body'] ?? ''));
  if ($body === '') $msgErr = 'Message required.';
  else {
    $st = $dbh->prepare("
      INSERT INTO org_room_messages (org_id, room_id, sender_member_id, msg_type, body, created_at)
      VALUES (:org,:rid,:s,'text',:b,NOW())
    ");
    $st->execute([':org'=>$orgId, ':rid'=>$roomId, ':s'=>$meMid, ':b'=>$body]);
    header("Location: groupchat.php?room=".$roomId);
    exit;
  }
}

// send media (attachment_id from ajax upload)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_media'])) {
  $aid = (int)($_POST['attachment_id'] ?? 0);
  if ($aid <= 0) $msgErr = 'Upload failed.';
  else {
    $st = $dbh->prepare("
      INSERT INTO org_room_messages (org_id, room_id, sender_member_id, msg_type, body, attachment_id, created_at)
      VALUES (:org,:rid,:s,'media',NULL,:aid,NOW())
    ");
    $st->execute([':org'=>$orgId, ':rid'=>$roomId, ':s'=>$meMid, ':aid'=>$aid]);
    header("Location: groupchat.php?room=".$roomId);
    exit;
  }
}

// load messages
$stT = $dbh->prepare("
  SELECT id, sender_member_id, msg_type, body, attachment_id, created_at
  FROM org_room_messages
  WHERE org_id=:org AND room_id=:rid
  ORDER BY created_at ASC
  LIMIT 400
");
$stT->execute([':org'=>$orgId, ':rid'=>$roomId]);
$thread = $stT->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once __DIR__ . '/includes/org_theme_head.php'; ?>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
  <title><?= h($ORG['name']) ?> - Room: <?= h((string)$ROOM['name']) ?></title>

  <link href="../lib/font-awesome/css/font-awesome.css" rel="stylesheet">
  <link href="../lib/Ionicons/css/ionicons.css" rel="stylesheet">
  <link href="../lib/bootstrap/bootstrap.css" rel="stylesheet">
  <link rel="stylesheet" href="../css/shamcey.css">
  <?php require_once __DIR__ . '/includes/org_layout.php'; org_layout_head_assets(); ?>
  <style>
    .msg-box{height:60vh; overflow:auto; border:1px solid rgba(0,0,0,.1); padding:12px; background:#fff;}
    .msg-me{background:#eef2ff; padding:8px 10px; border-radius:10px; margin:6px 0 6px auto; max-width:75%;}
    .msg-them{background:#f3f4f6; padding:8px 10px; border-radius:10px; margin:6px auto 6px 0; max-width:75%;}
    .msg-meta{font-size:11px;opacity:.7;margin-top:4px;}
  </style>
</head>
<body>

<?php include __DIR__ . '/includes/header.php'; ?>
<?php include __DIR__ . '/includes/leftbar.php'; ?>

<div class="sh-mainpanel">
  <div class="sh-pagebody" style="padding:10px">
    <div class="card bd-0">
      <div class="card-header bg-transparent pd-y-15 d-flex align-items-center justify-content-between">
        <h6 class="card-title mg-b-0"># <?= h((string)$ROOM['name']) ?></h6>
        <a href="rooms.php" class="btn btn-sm btn-outline-secondary">Rooms</a>
      </div>
      <div class="card-body">

        <?php if ($msgErr): ?><div class="alert alert-danger"><?= h($msgErr) ?></div><?php endif; ?>

        <div id="msgBox" class="msg-box">
          <?php foreach ($thread as $m): ?>
            <?php $mine = ((int)$m['sender_member_id'] === (int)$meMid); ?>
            <div class="<?= $mine ? 'msg-me' : 'msg-them' ?>">
              <?php if (!$mine): ?>
                <div style="font-weight:600; font-size:12px; margin-bottom:2px;"><?= h(memberName($dbh, (int)$m['sender_member_id'])) ?></div>
              <?php endif; ?>

              <?php if ((string)$m['msg_type'] === 'media'): ?>
                <?= renderAttachment($dbh, (int)($m['attachment_id'] ?? 0)) ?>
              <?php else: ?>
                <?= nl2br(h((string)($m['body'] ?? ''))) ?>
              <?php endif; ?>

              <div class="msg-meta"><?= h((string)$m['created_at']) ?></div>
            </div>
          <?php endforeach; ?>
        </div>

        <form method="post" class="mg-t-15" id="sendForm">
          <input type="hidden" name="send_text" value="1">
          <div class="form-group">
            <textarea name="body" class="form-control" rows="2" placeholder="Message #<?= h((string)$ROOM['name']) ?>"></textarea>
          </div>
          <div class="d-flex align-items-center justify-content-between">
            <div>
              <input type="file" id="mediaFile" style="display:none" accept="image/*,video/mp4,application/pdf">
              <button type="button" class="btn btn-outline-primary" onclick="document.getElementById('mediaFile').click();">
                <i class="icon ion-android-attach"></i> Media
              </button>
            </div>
            <button class="btn btn-primary">Send</button>
          </div>
        </form>

        <form method="post" id="mediaSendForm" style="display:none">
          <input type="hidden" name="send_media" value="1">
          <input type="hidden" name="attachment_id" id="attachmentId" value="">
        </form>

      </div>
    </div>
  </div>

  <?php include __DIR__ . '/includes/footer.php'; ?>
</div>

<script>
(function(){
  var box = document.getElementById('msgBox');
  if (box) box.scrollTop = box.scrollHeight;

  var fileInput = document.getElementById('mediaFile');
  fileInput && fileInput.addEventListener('change', async function(){
    if (!fileInput.files || !fileInput.files[0]) return;

    var fd = new FormData();
    fd.append('file', fileInput.files[0]);

    try {
      var res = await fetch('upload_org_media.php', { method: 'POST', body: fd });
      var j = await res.json();
      if (!j || !j.ok) {
        alert((j && j.error) ? j.error : 'Upload failed');
        return;
      }
      if (!j.attachment_id || j.attachment_id <= 0) {
        alert('Uploaded, but DB insert failed. Create org_attachments table (see SQL).');
        return;
      }
      document.getElementById('attachmentId').value = j.attachment_id;
      document.getElementById('mediaSendForm').submit();
    } catch (e) {
      alert('Upload error');
    }
  });
})();
</script>

</body>
</html>
