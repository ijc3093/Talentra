<?php
require_once __DIR__ . '/includes/session_user.php';
requireUserLogin();

require_once __DIR__ . '/includes/user_identity.php';
require_once __DIR__ . '/controller.php';

error_reporting(E_ALL);
ini_set('display_errors', '0');

$controller = new Controller();
$dbh = $controller->pdo();

$meId   = myUserId();
$meEmail = myUserEmail();

$convUuid = trim($_GET['c'] ?? '');
if ($convUuid === '') die("Missing conversation id.");

$msg = '';
$error = '';

function fmt_dt($dt){ return $dt ? date('M d, Y h:i A', strtotime($dt)) : ''; }
function safe_text($txt){ return nl2br(htmlentities($txt ?? '')); }

function getConversation(PDO $dbh, string $uuid, int $meId): ?array {
    $st = $dbh->prepare("
      SELECT c.id, c.uuid, c.type
      FROM conversations c
      JOIN conversation_participants p ON p.conversation_id = c.id AND p.user_id = :me
      WHERE c.uuid = :u
      LIMIT 1
    ");
    $st->execute([':me'=>$meId, ':u'=>$uuid]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function getPeer(PDO $dbh, int $convId, int $meId): ?array {
    $st = $dbh->prepare("
      SELECT u.id, u.name, u.username, u.email
      FROM conversation_participants p
      JOIN users u ON u.id = p.user_id
      WHERE p.conversation_id = :cid AND u.id <> :me
      LIMIT 1
    ");
    $st->execute([':cid'=>$convId, ':me'=>$meId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

$conv = getConversation($dbh, $convUuid, $meId);
if (!$conv) die("Conversation not found (or you do not have access).");

$convId = (int)$conv['id'];
$type   = $conv['type'];

$peer = null;
$peerTitle = 'Support';

if ($type === 'user') {
    $peer = getPeer($dbh, $convId, $meId);
    if (!$peer) die("Peer not found.");
    $peerTitle = ($peer['username'] ?: $peer['email']);
}

// SEND MESSAGE
if (isset($_POST['send'])) {
    $text = trim($_POST['message'] ?? '');

    // attachment optional (same folder style as admin)
    $attachment = null;
    $folder = __DIR__ . "/attachment/";
    if (!is_dir($folder)) mkdir($folder, 0755, true);

    if (!empty($_FILES['attachment']['name'])) {
        $file     = $_FILES['attachment']['name'];
        $file_loc = $_FILES['attachment']['tmp_name'];
        $ext      = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $allowed  = ['jpg','jpeg','png','pdf','doc','docx'];
        $allowedMimeByExt = [
            'jpg' => ['image/jpeg'],
            'jpeg' => ['image/jpeg'],
            'png' => ['image/png'],
            'pdf' => ['application/pdf'],
            'doc' => ['application/msword'],
            'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
        ];

        if (!is_uploaded_file($file_loc) || !in_array($ext, $allowed, true)) {
            $error = "Invalid attachment type.";
        } else {
            $detectedMime = '';
            if (class_exists('finfo')) {
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $detectedMime = strtolower(trim((string)$finfo->file($file_loc)));
            }
            if ($detectedMime === '' || !in_array($detectedMime, $allowedMimeByExt[$ext] ?? [], true)) {
                $error = "Invalid attachment type.";
            } else {
            $base = preg_replace('/[^a-zA-Z0-9-_]/', '-', pathinfo($file, PATHINFO_FILENAME));
            $final = strtolower($base . '-' . time() . '.' . $ext);

            if (move_uploaded_file($file_loc, $folder . $final)) {
                $attachment = $final;
            } else {
                $error = "Attachment upload failed.";
            }
            }
        }
    }

    if ($error === '' && $text === '' && !$attachment) {
        $error = "Message cannot be empty (add text or attachment).";
    }

    if ($error === '') {
        // insert message
        $ins = $dbh->prepare("INSERT INTO messages (conversation_id, sender_user_id, body, attachment) VALUES (:c,:s,:b,:a)");
        $ins->execute([':c'=>$convId, ':s'=>$meId, ':b'=>$text, ':a'=>$attachment]);

        // create notification for peer or admin
        if ($type === 'user') {
            // notify peer by email (matches your notification.php)
            require_once __DIR__ . '/admin/controller.php';
            $controller = new Controller();
            $controller->addNotification($meEmail, $peer['email'], 'New chat message');
        } else {
            // support -> notify Admin (legacy receiver key)
            $controller->addNotification($meEmail, 'Admin', 'New support message');
        }

        header("Location: chat.php?c=" . urlencode($convUuid));
        exit;
    }
}

// mark messages as read for me (simple: insert reads for all messages not yet marked)
$mk = $dbh->prepare("
  INSERT IGNORE INTO message_reads (message_id, user_id)
  SELECT m.id, :me
  FROM messages m
  WHERE m.conversation_id = :cid
");
$mk->execute([':me'=>$meId, ':cid'=>$convId]);

// load messages
$st = $dbh->prepare("
  SELECT m.id, m.sender_user_id, m.body, m.attachment, m.created_at, u.username, u.email
  FROM messages m
  JOIN users u ON u.id = m.sender_user_id
  WHERE m.conversation_id = :cid
  ORDER BY m.created_at ASC
");
$st->execute([':cid'=>$convId]);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="en" class="no-js">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Chat</title>
  <?php
  require_once __DIR__ . '/includes/theme_prefs.php';
  theme_prefs_print_head_bootstrap($dbh, $meId);
  ?>

  <link rel="stylesheet" href="css/font-awesome.min.css">
  <link rel="stylesheet" href="css/bootstrap.min.css">
  <link rel="stylesheet" href="css/style.css">

  <style>
    .chat-wrap{max-height:55vh;overflow:auto;padding:15px;background:#f7f7f7;border:1px solid #ddd;border-radius:6px;}
    .row-msg{display:flex;width:100%;margin:6px 0;}
    .row-left{justify-content:flex-start;}
    .row-right{justify-content:flex-end;}
    .bubble{display:inline-block;padding:10px 12px;border-radius:14px;max-width:75%;word-wrap:break-word;background:#eee;border:1px solid #e5e5e5;}
    .bubble-me{background:#dff1ff;border-color:#cbe8ff;}
    .meta{font-size:12px;color:#777;margin-top:2px;}
    .form-box{margin-top:15px;background:#fff;border:1px solid #ddd;border-radius:6px;padding:15px;}
    .text-muted{color:#777 !important;}
  </style>
</head>
<body>

<?php $forceFeedRail = true; $skipHeaderThemeBootstrap = true; include __DIR__ . '/includes/header.php'; ?>

<div class="app-main">
<div class="ts-main-content">

<div class="content-wrapper">
<div class="container-fluid">

  <h2 class="page-title">
    Chat: <strong><?php echo htmlentities($peerTitle); ?></strong>
  </h2>

  <?php if ($error): ?><div class="alert alert-danger"><?php echo htmlentities($error); ?></div><?php endif; ?>
  <?php if ($msg): ?><div class="alert alert-success"><?php echo htmlentities($msg); ?></div><?php endif; ?>

  <div id="chatBox" class="chat-wrap">
    <?php if (empty($rows)): ?>
      <div class="alert alert-info">No messages yet.</div>
    <?php else: ?>
      <?php foreach ($rows as $r): ?>
        <?php
          $isMe = ((int)$r['sender_user_id'] === $meId);
          $rowClass  = $isMe ? 'row-right' : 'row-left';
          $bubbleCls = $isMe ? 'bubble bubble-me' : 'bubble';
          $who = $isMe ? 'You' : ($r['username'] ?: $r['email']);
        ?>
        <div class="row-msg <?php echo $rowClass; ?>">
          <div class="<?php echo $bubbleCls; ?>">
            <?php echo safe_text($r['body']); ?>

            <?php if (!empty($r['attachment'])): ?>
              <div style="margin-top:8px;">
                <i class="fa fa-paperclip"></i>
                <a target="_blank" href="attachment/<?php echo urlencode($r['attachment']); ?>">
                  <?php echo htmlentities($r['attachment']); ?>
                </a>
              </div>
            <?php endif; ?>

            <div class="meta"><?php echo htmlentities($who); ?> • <?php echo htmlentities(fmt_dt($r['created_at'])); ?></div>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <div class="form-box">
    <form id="chatForm" method="post" enctype="multipart/form-data" autocomplete="off">
      <div class="row">
        <div class="col-md-8">
          <textarea id="chatInput" name="message" class="form-control" rows="4" placeholder="Type your message..."></textarea>
        </div>
        <div class="col-md-4">
          <input type="file" name="attachment" class="form-control">
          <br/>
          <button type="submit" name="send" class="btn btn-primary btn-block">
            <i class="fa fa-send"></i> Send
          </button>
          <small class="text-muted">Allowed: jpg, jpeg, png, pdf, doc, docx</small>
        </div>
      </div>
    </form>
  </div>

</div>
</div>
</div>

<script src="js/jquery.min.js"></script>
<script src="js/bootstrap.min.js"></script>
<script>
(function(){
  function scrollChatToBottom(force=false){
    const box = document.getElementById('chatBox');
    if (!box) return;
    const nearBottom = (box.scrollHeight - box.scrollTop - box.clientHeight) < 120;
    if (force || nearBottom) box.scrollTop = box.scrollHeight;
  }
  if ('scrollRestoration' in history) history.scrollRestoration = 'manual';
  window.addEventListener('load', function(){
    scrollChatToBottom(true);
    setTimeout(() => scrollChatToBottom(true), 50);
    setTimeout(() => scrollChatToBottom(true), 200);
  });

  document.getElementById('chatForm').addEventListener('submit', function(e){
    const msg = (document.getElementById('chatInput').value || '').trim();
    const file = document.querySelector('input[type="file"]').files.length;
    if (!msg && !file) {
      e.preventDefault();
      alert('Message cannot be empty (add text or attachment).');
    }
  });
})();
</script>
</div>

<!-- <?php include __DIR__ . '/includes/footer.php'; ?> -->
</body>
</html>
