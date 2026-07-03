<?php
// /Business_only3/admin/compose.php
declare(strict_types=1);

require_once __DIR__ . '/includes/session_admin.php';
requireAdminLogin();

require_once __DIR__ . '/controller.php';
require_once __DIR__ . '/includes/identity.php';

error_reporting(E_ALL);
ini_set('display_errors', '1');

const THREAD_DELIM = '||THREAD||';

$controller = new Controller();
$dbh = $controller->pdo();

$meUsername = myUsername();     // legacy
$meId       = myAdminId();      // admin.idadmin
$meRole     = myRoleId();       // role int

$msg = '';
$error = '';

if (!function_exists('h')) {
    function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}

if ($meId <= 0 || $meRole <= 0) {
    die('Invalid session.');
}

/**
 * Keep Summernote formatting but remove inline styles/junk.
 * PHP 7-safe (no DOM, no ?->).
 */
function sanitize_summernote_html(string $html): string
{
    $html = trim($html);

    $html = preg_replace('#<p>(\s|&nbsp;|<br\s*/?>)*</p>#i', '', $html) ?? $html;

    if (!class_exists('DOMDocument')) {
        $html = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', '', $html) ?? $html;
        $html = strip_tags($html, '<p><br><b><strong><i><em><u><ul><ol><li><a><span>');
        $html = preg_replace('#\son\w+="[^"]*"#i', '', $html) ?? $html;

        $plain = trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $hasMedia = (preg_match('/<img\b/i', $html) === 1) || (preg_match('/<iframe\b/i', $html) === 1);
        return ($plain === '' && !$hasMedia) ? '' : trim($html);
    }

    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="utf-8"?>' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();

    $allowed = ['p','br','b','strong','i','em','u','ul','ol','li','a','span'];

    $styleAllowed = [
        'color',
        'background-color',
        'text-align',
        'font-family',
        'font-size',
        'font-weight',
        'font-style',
        'text-decoration',
    ];

    $nodes = iterator_to_array($dom->getElementsByTagName('*'));
    foreach ($nodes as $node) {
        $tag = strtolower($node->nodeName);

        if (!in_array($tag, $allowed, true)) {
            if ($node->parentNode) {
                $node->parentNode->replaceChild(
                    $dom->createTextNode((string)$node->textContent),
                    $node
                );
            }
            continue;
        }

        $href = ($tag === 'a') ? (string)$node->getAttribute('href') : '';
        $sty  = (in_array($tag, ['p','span'], true)) ? (string)$node->getAttribute('style') : '';

        if ($node->hasAttributes()) {
            $attrs = [];
            foreach ($node->attributes as $attr) { $attrs[] = $attr->nodeName; }
            foreach ($attrs as $a) { $node->removeAttribute($a); }
        }

        if ($tag === 'a' && $href !== '') {
            if (preg_match('#^(https?://|mailto:|/)#i', $href) || strpos($href, '#') === 0) {
                $node->setAttribute('href', $href);
                $node->setAttribute('target', '_blank');
                $node->setAttribute('rel', 'noopener noreferrer');
            }
        }

        if (($tag === 'p' || $tag === 'span') && $sty !== '') {
            $cleanStyles = [];
            foreach (preg_split('/;/', $sty) as $decl) {
                $decl = trim((string)$decl);
                if ($decl === '' || strpos($decl, ':') === false) continue;

                [$prop, $val] = array_map('trim', explode(':', $decl, 2));
                $prop = strtolower($prop);

                if (!in_array($prop, $styleAllowed, true)) continue;
                if (preg_match('#url\s*\(|expression\s*\(#i', $val)) continue;

                $val = preg_replace('#[^a-zA-Z0-9\s\-\#\(\),\.\%"]+#', '', (string)$val);
                $val = trim((string)$val);
                if ($val === '') continue;

                $cleanStyles[] = $prop . ': ' . $val;
            }
            if (!empty($cleanStyles)) {
                $node->setAttribute('style', implode('; ', $cleanStyles));
            }
        }
    }

    $clean = trim($dom->saveHTML() ?: '');

    $plain = trim(html_entity_decode(strip_tags($clean), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    $hasMedia = (preg_match('/<img\b/i', $clean) === 1) || (preg_match('/<iframe\b/i', $clean) === 1);
    return ($plain === '' && !$hasMedia) ? '' : $clean;
}

/**
 * Resolve directory peer by friend_code / username / fullname
 */
function resolveDirectoryPeer(PDO $dbh, string $term): ?array
{
    $term = trim($term);
    if ($term === '') return null;

    $termUpper = strtoupper($term);
    $like      = '%' . $term . '%';
    $likeUpper = '%' . $termUpper . '%';

    $sql = "
        SELECT idadmin, username, fullname, role, status, friend_code
        FROM admin
        WHERE status = 1
          AND (
                UPPER(friend_code) = :codeExact
             OR username = :userExact
             OR fullname = :nameExact
             OR fullname LIKE :nameLike
             OR username LIKE :userLike
             OR UPPER(friend_code) LIKE :codeLike
          )
        ORDER BY
          (UPPER(friend_code) = :codeExact2) DESC,
          (username = :userExact2) DESC,
          (fullname = :nameExact2) DESC,
          fullname ASC
        LIMIT 1
    ";

    $st = $dbh->prepare($sql);
    $st->execute([
        ':codeExact'  => $termUpper,
        ':userExact'  => $term,
        ':nameExact'  => $term,
        ':nameLike'   => $like,
        ':userLike'   => $like,
        ':codeLike'   => $likeUpper,
        ':codeExact2' => $termUpper,
        ':userExact2' => $term,
        ':nameExact2' => $term,
    ]);

    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function canChatRole(int $myRole, int $peerRole): bool
{
    return channelForAdminRoles($myRole, $peerRole) !== '';
}

// ----------------------------------------------------
// Prefill: /admin/compose.php?to=xxxx
// ----------------------------------------------------
$prefill = null;
$toParam = trim((string)($_GET['to'] ?? ''));
if ($toParam !== '') {
    $prefill = resolveDirectoryPeer($dbh, $toParam);
    if ($prefill && !canChatRole($meRole, (int)$prefill['role'])) {
        $prefill = null;
    }
}

// -----------------------------
// SEND MESSAGE
// -----------------------------
if (isset($_POST['send'])) {

    $friendAdminId = (int)($_POST['friend_admin_id'] ?? 0);
    $fallbackTo    = trim((string)($_POST['to_fallback'] ?? ''));

    $raw  = (string)($_POST['message'] ?? '');
    $text = sanitize_summernote_html($raw);

    // Attachments JSON
    $attachmentsJson = null;
    $attRaw = (string)($_POST['attachments'] ?? '');
    if ($attRaw !== '') {
        $att = json_decode($attRaw, true);
        if (is_array($att)) {
            $clean = [];
            $base = realpath(__DIR__ . '/../attachment');

            foreach ($att as $one) {
                if (!is_array($one)) continue;
                $path = trim((string)($one['path'] ?? ''));
                $orig = trim((string)($one['original'] ?? ''));
                $mime = trim((string)($one['mime'] ?? ''));
                if ($path === '' || strpos($path, 'storage/') !== 0) continue;

                if ($base !== false) {
                    $full = realpath($base . DIRECTORY_SEPARATOR . $path);
                    if ($full === false || strpos($full, $base . DIRECTORY_SEPARATOR) !== 0) continue;
                }

                $clean[] = ['path' => $path, 'original' => $orig, 'mime' => $mime];
            }

            if (!empty($clean)) $attachmentsJson = json_encode($clean);
        }
    }

    if ($friendAdminId <= 0 && $fallbackTo !== '') {
        $r = resolveDirectoryPeer($dbh, $fallbackTo);
        if ($r) $friendAdminId = (int)$r['idadmin'];
    }

    if ($friendAdminId <= 0) {
        $error = "Please choose a person (search and select).";
    } elseif ($text === '' && $attachmentsJson === null) {
        $error = "Message cannot be empty.";
    }

    $peer = null;
    if ($error === '') {
        $st = $dbh->prepare("
            SELECT idadmin, username, fullname, role, status, friend_code
            FROM admin
            WHERE idadmin = :id
            LIMIT 1
        ");
        $st->execute([':id' => $friendAdminId]);
        $peer = $st->fetch(PDO::FETCH_ASSOC);

        if (!$peer) $error = "Recipient not found.";
        elseif ((int)$peer['status'] !== 1) $error = "Recipient is inactive.";
        elseif ((int)$peer['idadmin'] === $meId) $error = "You cannot message yourself.";
    }

    $channel = '';
    if ($error === '') {
        $peerRole = (int)($peer['role'] ?? 0);
        $channel = channelForAdminRoles($meRole, $peerRole);
        if ($channel === '') $error = "You can't message this role from your role.";
    }

    if ($error === '') {

        $subject = trim((string)($_POST['address'] ?? ''));
        if ($subject === '') $subject = 'No Subject';

        // ✅ use friend_code as sender/receiver in feedback_admin
        $meFriend = '';
        $peerFriend = (string)($peer['friend_code'] ?? '');

        try {
            $stMe = $dbh->prepare("SELECT friend_code FROM admin WHERE idadmin = :id LIMIT 1");
            $stMe->execute([':id' => $meId]);
            $meFriend = (string)($stMe->fetchColumn() ?: '');
        } catch (Throwable $e) { $meFriend = ''; }

        if ($meFriend === '' || $peerFriend === '') {
            $error = "Missing friend code (sender/receiver). Please ensure both accounts have friend_code.";
        } else {
            $uidSeed = $meFriend . '|' . $peerFriend . '|' . date('YmdHis') . '|' . session_id() . '|' . bin2hex(random_bytes(6));
            $threadUid = hash('sha256', $uidSeed);
            $threadTitle = $subject . ' ' . THREAD_DELIM . ' ' . $threadUid;

            $stmt = $dbh->prepare("
                INSERT INTO feedback_admin (sender, receiver, channel, title, feedbackdata, attachment, is_read)
                VALUES (:s, :r, :ch, :title, :msg, :att, 0)
            ");
            $stmt->execute([
                ':s'     => $meFriend,
                ':r'     => $peerFriend,
                ':ch'    => $channel,
                ':title' => $threadTitle,
                ':msg'   => $text,
                ':att'   => $attachmentsJson,
            ]);

            header("Location: mailbox.php?peer=" . urlencode($peerFriend) . "&t=" . urlencode($threadUid));
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Compose</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <link href="../lib/font-awesome/css/font-awesome.css" rel="stylesheet">
  <link href="../lib/Ionicons/css/ionicons.css" rel="stylesheet">
  <link href="../lib/perfect-scrollbar/css/perfect-scrollbar.css" rel="stylesheet">
  <link href="../lib/medium-editor/medium-editor.css" rel="stylesheet">
  <link href="../lib/medium-editor/default.css" rel="stylesheet">
  <link href="../lib/summernote/summernote-bs4.css" rel="stylesheet">
  <link rel="stylesheet" href="../css/shamcey.css">

  <style>
    /* ✅ FIXED PAGE LIKE settings.php */
    html,body{height:100%;overflow:hidden;}
    .sh-mainpanel{height:100vh;display:flex;flex-direction:column;overflow:hidden;}
    .sh-pagetitle{flex:0 0 auto;}
    .sh-pagebody{flex:1 1 auto;overflow:hidden;display:flex;flex-direction:column;min-height:0;padding-bottom:0!important;}

    /* Card fills page body */
    .compose-card{
      flex:1 1 auto;
      min-height:0;
      overflow:hidden;
      display:flex;
      flex-direction:column;
      border:0;
    }
    .compose-card .card-body-fixed{
      flex:1 1 auto;
      min-height:0;
      overflow:hidden;
      display:flex;
      flex-direction:column;
      padding:12px;
    }

    /* Only inner section scrolls */
    .compose-scroll{
      flex:1 1 auto;
      min-height:0;
      overflow:auto;
      padding:12px;
      border:1px solid rgba(0,0,0,.08);
      border-radius:12px;
      background:#fff;
    }

    .results{
      position:absolute;
      z-index:9999;
      width:100%;
      display:none;
      background:#fff;
      border:1px solid rgba(0,0,0,.12);
      border-radius:12px;
      margin-top:6px;
      overflow:hidden;
      box-shadow:0 10px 30px rgba(0,0,0,.12);
      max-height:280px;
      overflow:auto;
    }
    .results .item{
      padding:10px 12px;
      cursor:pointer;
      border-bottom:1px solid rgba(0,0,0,.06);
    }
    .results .item:hover{ background:rgba(8,97,188,.08); }
    .results .small{ font-size:12px; opacity:.75; }

    .selected-pill{
      display:inline-flex;
      align-items:center;
      gap:8px;
      background:rgba(8,97,188,.10);
      border:1px solid rgba(8,97,188,.18);
      color:#1b2a3a;
      border-radius:999px;
      padding:6px 10px;
      font-size:12px;
      margin-top:6px;
      max-width:100%;
      white-space:nowrap;
      overflow:hidden;
      text-overflow:ellipsis;
    }
    .meta-pill{
      font-size:12px;
      opacity:.7;
      margin:8px 0 10px;
    }

    /* summernote area */
    .note-editor.note-frame{
      border-radius:12px;
      overflow:hidden;
      border:1px solid rgba(0,0,0,.10);
    }
    #attachmentList .att-btn{ margin-right:6px; margin-bottom:6px; }
    #attachmentList .att-rm{ margin-right:10px; margin-bottom:6px; }

    .compose-actions{
      flex:0 0 auto;
      padding:12px;
      border-top:1px solid rgba(0,0,0,.08);
      background:#fff;
      display:flex;
      justify-content:flex-end;
      gap:10px;
    }

    .search-wrap{ position:relative; }
  </style>
</head>

<body>

<?php include __DIR__ . '/includes/leftbar.php'; ?>
<?php include __DIR__ . '/includes/header.php'; ?>

<div class="sh-mainpanel">

  <?php if ($error): ?>
    <div class="alert alert-danger" style="margin:10px;"><?= h($error) ?></div>
  <?php elseif ($msg): ?>
    <div class="alert alert-success" style="margin:10px;"><?= h($msg) ?></div>
  <?php endif; ?>

  <div class="sh-pagebody">

    <div class="card compose-card">
      <div class="card-body-fixed">

        <form method="post" autocomplete="off" style="display:flex;flex-direction:column;min-height:0;flex:1 1 auto;overflow:hidden;">
          <input type="hidden" name="friend_admin_id" id="friend_admin_id" value="<?= (int)($prefill['idadmin'] ?? 0); ?>">
          <input type="hidden" name="to_fallback" id="to_fallback" value="<?= h($toParam); ?>">
          <textarea name="message" id="message" hidden></textarea>
          <input type="hidden" name="attachments" id="attachments" value="">

          <div class="compose-scroll">

            <div class="row">
              <div class="col-lg-5">
                <div class="form-group search-wrap">
                  <label class="form-control-label">Friend Code:<span class="tx-danger">*</span></label>
                  <input type="text"
                         id="toSearch"
                         class="form-control"
                         placeholder="Type full name, username, or friend code..."
                         autocomplete="off"
                         value="<?= h($prefill['fullname'] ?? $prefill['username'] ?? $toParam); ?>">
                  <div id="results" class="results"></div>

                  <div id="selectedInfo" class="selected-pill" style="<?= $prefill ? '' : 'display:none;'; ?>">
                    <?php if ($prefill): ?>
                      <?php
                        $dn = trim((string)($prefill['fullname'] ?? ''));
                        if ($dn === '') $dn = (string)($prefill['username'] ?? '');
                        $fc = (string)($prefill['friend_code'] ?? '');
                        echo h($dn . ($fc ? " • " . $fc : ""));
                      ?>
                    <?php endif; ?>
                  </div>
                </div>
              </div>

              <div class="col-lg-7">
                <div class="form-group mg-b-10-force">
                  <label class="form-control-label">New Subject: <span class="tx-danger">*</span></label>
                  <input class="form-control" type="text" name="address" placeholder="Type subject...">
                </div>
              </div>
            </div>

            <div class="meta-pill">Directory search (no contacts needed). Only active accounts appear.</div>

            <div class="card bd-primary">
              <div class="card-header bg-primary tx-white">Type New Message</div>
              <div class="card-body pd-sm-30">
                <div id="summernote"></div>

                <div id="attachmentList" class="mg-t-10"></div>

                <div class="mg-t-10">
                  <input type="file" id="attPicker" style="display:none" multiple>
                  <button type="button" class="btn btn-sm btn-outline-primary" id="attPickBtn">
                    <i class="fa fa-paperclip"></i> Attach files
                  </button>
                </div>
              </div>
            </div>

          </div><!-- /compose-scroll -->

          <div class="compose-actions">
            <button class="btn btn-primary" name="send" type="submit"><i class="fa fa-send"></i> Send</button>
            <a class="btn btn-default" href="feedback.php?view=internal"><i class="fa fa-inbox"></i> Inbox</a>
          </div>

        </form>

      </div>
    </div>

  </div>

  <div class="sh-footer">
    <div>Copyright &copy; 2017. All Rights Reserved. Talentra</div>
    <div class="mg-t-10 mg-md-t-0">Designed by: <a href="http://themepixels.me">ThemePixels</a></div>
  </div>
</div>

<script src="../lib/jquery/jquery.js"></script>
<script src="../lib/popper.js/popper.js"></script>
<script src="../lib/bootstrap/bootstrap.js"></script>
<script src="../lib/perfect-scrollbar/js/perfect-scrollbar.jquery.js"></script>
<script src="../lib/medium-editor/medium-editor.js"></script>
<script src="../lib/summernote/summernote-bs4.min.js"></script>
<script src="../js/shamcey.js"></script>

<!-- Attachment Preview Modal -->
<div class="modal fade" id="attModal" tabindex="-1" role="dialog" aria-labelledby="attModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="attModalLabel">Attachment</h5>
        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>
      <div class="modal-body" id="attModalBody"></div>
    </div>
  </div>
</div>

<script>
$(function(){
  'use strict';

  var attachments = [];

  function esc(s){
    return String(s || '').replace(/[&<>"']/g, function(m){
      return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[m];
    });
  }

  function guessKind(mime, path){
    var ext = (path || '').split('.').pop().toLowerCase();
    if ((mime || '').indexOf('image/') === 0 || ['jpg','jpeg','png','gif','webp'].indexOf(ext) >= 0) return 'image';
    if ((mime || '').indexOf('video/') === 0 || ['mp4','webm','ogv','ogg','mov'].indexOf(ext) >= 0) return 'video';
    if (mime === 'application/pdf' || ext === 'pdf') return 'pdf';
    return 'text';
  }

  function renderAttachments(){
    var html = '';
    if (attachments.length){
      html += '<div class="tx-12 tx-gray-600 mg-b-5">Attachments (click to preview):</div>';
      html += '<div class="d-flex flex-wrap" style="gap:6px">';
      attachments.forEach(function(a, idx){
        html += '<button type="button" class="btn btn-sm btn-outline-secondary att-btn" data-idx="'+idx+'">'+esc(a.original || a.path)+'</button>';
        html += '<button type="button" class="btn btn-sm btn-outline-danger att-rm" data-idx="'+idx+'">&times;</button>';
      });
      html += '</div>';
    }
    $('#attachmentList').html(html);
    $('#attachments').val(JSON.stringify(attachments));
  }

  function openPreview(a){
    if (!a) return;
    var url = a.url || ('../attachment/' + a.path);
    var kind = guessKind(a.mime, a.path);

    $('#attModalLabel').text(a.original || a.path);
    var $body = $('#attModalBody');
    $body.html('');

    if (kind === 'image') {
      $body.html('<img src="'+esc(url)+'" class="img-fluid" alt="">');
    } else if (kind === 'video') {
      $body.html('<video controls style="width:100%;max-height:70vh;border-radius:10px"><source src="'+esc(url)+'"></video>');
    } else if (kind === 'pdf') {
      $body.html('<iframe src="'+esc(url)+'" style="width:100%;height:70vh;border:0"></iframe>');
    } else {
      $body.html('<div class="alert alert-info">This file cannot be previewed here. Please download/open it.</div>');
    }

    $('#attModal').modal('show');
  }

  function uploadOneFile(file){
    if (!file) return;
    var data = new FormData();
    data.append('file', file);

    $.ajax({
      url: 'ajax/upload_chat_attachment.php',
      method: 'POST',
      data: data,
      contentType: false,
      processData: false,
      success: function(resp){
        if (typeof resp === 'string') { try { resp = JSON.parse(resp); } catch(e) {} }
        if (resp && resp.ok && resp.path) {
          attachments.push({
            path: resp.path,
            url: resp.url,
            original: resp.original || (resp.file || resp.path),
            mime: resp.mime || ''
          });
          renderAttachments();
        } else {
          alert((resp && resp.error) ? resp.error : 'Upload failed');
        }
      },
      error: function(){
        alert('Upload failed (server error).');
      }
    });
  }

  // Summernote editor
  $('#summernote').summernote({
    height: 220,
    tooltip: false,
    dialogsInBody: true,
    toolbar: [
      ['style', ['style']],
      ['font', ['bold', 'italic', 'underline', 'clear']],
      ['fontname', ['fontname']],
      ['fontsize', ['fontsize']],
      ['color', ['color']],
      ['para', ['ul', 'ol', 'paragraph']],
      ['insert', ['link']],
      ['view', ['fullscreen', 'codeview', 'help']]
    ],
    callbacks: {
      onImageUpload: function(files) {
        if (!files || !files.length) return;
        uploadOneFile(files[0]);
      }
    }
  });

  // Attach button -> file picker
  $('#attPickBtn').on('click', function(){ $('#attPicker').trigger('click'); });
  $('#attPicker').on('change', function(){
    var fs = this.files || [];
    for (var i=0; i<fs.length; i++) uploadOneFile(fs[i]);
    this.value = '';
  });

  // Click attachment pill -> preview
  $(document).on('click', '.att-btn', function(){
    var idx = parseInt($(this).attr('data-idx'), 10);
    openPreview(attachments[idx]);
  });

  // Remove attachment
  $(document).on('click', '.att-rm', function(){
    var idx = parseInt($(this).attr('data-idx'), 10);
    if (!isNaN(idx)) {
      attachments.splice(idx, 1);
      renderAttachments();
    }
  });

  // Copy editor HTML into hidden textarea on submit
  $('form').on('submit', function(){
    $('#message').val($('#summernote').summernote('code') || '');
    $('#attachments').val(JSON.stringify(attachments));
  });
});
</script>

<script>
(function(){
  const input = document.getElementById('toSearch');
  const results = document.getElementById('results');
  const hiddenId = document.getElementById('friend_admin_id');
  const selectedInfo = document.getElementById('selectedInfo');
  const toFallback = document.getElementById('to_fallback');

  let timer = null;

  function clearResults(){
    results.innerHTML = '';
    results.style.display = 'none';
  }

  function escapeHtml(s){
    return String(s || '').replace(/[&<>"']/g, m => ({
      '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
    }[m]));
  }

  function setSelected(item){
    hiddenId.value = item.idadmin || '';
    toFallback.value = item.friend_code || item.username || input.value || '';

    input.value = item.fullname || item.username || '';
    selectedInfo.style.display = 'inline-flex';
    selectedInfo.textContent =
      (item.fullname || item.username || '') + (item.friend_code ? " • " + item.friend_code : "");

    clearResults();
  }

  async function search(term){
    term = (term || '').trim();
    if (!term || term.length < 1) { clearResults(); return; }

    try{
      const r = await fetch('ajax/admin_directory_search.php?term=' + encodeURIComponent(term), { cache: 'no-store' });
      const data = await r.json();

      if (!data || !data.ok) { clearResults(); return; }
      const items = data.items || [];

      if (items.length === 0){
        results.innerHTML = '<div class="item"><b>No match</b><div class="small">Try another name, username, or code</div></div>';
        results.style.display = 'block';
        return;
      }

      results.innerHTML = items.map(x => `
        <div class="item"
             data-id="${escapeHtml(x.idadmin)}"
             data-fullname="${encodeURIComponent(x.fullname || '')}"
             data-username="${encodeURIComponent(x.username || '')}"
             data-code="${encodeURIComponent(x.friend_code || '')}">
          <div><b>${escapeHtml(x.fullname || x.username || '')}</b></div>
          <div class="small">
            Username: ${escapeHtml(x.username || '')}
            ${x.friend_code ? ' • Friend Code: ' + escapeHtml(x.friend_code) : ''}
          </div>
        </div>
      `).join('');

      results.style.display = 'block';
    }catch(e){
      clearResults();
    }
  }

  input.addEventListener('input', function(){
    hiddenId.value = '';
    selectedInfo.style.display = 'none';
    toFallback.value = input.value || '';

    clearTimeout(timer);
    timer = setTimeout(() => search(input.value), 200);
  });

  results.addEventListener('click', function(e){
    const row = e.target.closest('.item');
    if (!row) return;

    const item = {
      idadmin: row.getAttribute('data-id'),
      fullname: decodeURIComponent(row.getAttribute('data-fullname') || ''),
      username: decodeURIComponent(row.getAttribute('data-username') || ''),
      friend_code: decodeURIComponent(row.getAttribute('data-code') || '')
    };
    setSelected(item);
  });

  document.addEventListener('click', function(e){
    if (!e.target.closest('.search-wrap')) clearResults();
  });
})();
</script>

</body>
</html>
