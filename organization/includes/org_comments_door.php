<?php
declare(strict_types=1);

if (!empty($GLOBALS['msb_org_comments_door_included'])) {
    return;
}
$GLOBALS['msb_org_comments_door_included'] = true;
?>
<style>
.org-comments-door{
  position:fixed;
  inset:0;
  z-index:12050;
  pointer-events:none;
}
.org-comments-door.is-open{
  pointer-events:auto;
}
.org-comments-door-backdrop{
  position:absolute;
  inset:0;
  border:0;
  padding:0;
  margin:0;
  background:rgba(15,23,42,.48);
  opacity:0;
  transition:opacity .18s ease;
  cursor:pointer;
}
.org-comments-door.is-open .org-comments-door-backdrop{
  opacity:1;
}
.org-comments-door-panel{
  position:absolute;
  top:0;
  right:0;
  bottom:0;
  width:min(400px, 92vw);
  background:var(--bg-card, #171d24);
  color:var(--text-primary, #111827);
  border-left:1px solid var(--border-color, #e5e7eb);
  box-shadow:-18px 0 48px rgba(0,0,0,.18);
  display:flex;
  flex-direction:column;
  transform:translateX(105%);
  transition:transform .18s ease;
  overflow:hidden;
}
.org-comments-door.is-open .org-comments-door-panel{
  transform:translateX(0);
}
.org-comments-door-head{
  flex:0 0 auto;
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:12px;
  padding:18px 18px 14px;
  border-bottom:1px solid var(--border-color, #e5e7eb);
  background:var(--bg-card, #171d24);
}
.org-comments-door-head .title{
  font-weight:900;
  font-size:18px;
  line-height:1.15;
  color:var(--text-primary, #111827);
}
.org-comments-door-head .count{
  font-weight:700;
  font-size:13px;
  color:var(--text-secondary, #6b7280);
  margin-left:8px;
}
.org-comments-door-close{
  width:38px;
  height:38px;
  border-radius:999px;
  border:1px solid var(--border-color, #e5e7eb);
  background:var(--bg-main, #171d24);
  color:var(--text-primary, #111827);
  display:inline-flex;
  align-items:center;
  justify-content:center;
  cursor:pointer;
}
.org-comments-door-close:hover{
  background:var(--bg-sidebar, #171d24);
}
.org-comments-door-body{
  flex:1 1 auto;
  min-height:0;
  overflow:auto;
  padding:12px 16px;
  background:var(--bg-card, #171d24);
}
.org-comments-door-foot{
  flex:0 0 auto;
  border-top:1px solid var(--border-color, #e5e7eb);
  padding:12px 16px 16px;
  background:var(--bg-card, #171d24);
}
.org-comments-door-body .comment-item{
  padding:8px 10px;
  border:1px solid var(--border-color, #e5e7eb);
  border-radius:10px;
  margin:8px 0;
  background:var(--bg-main, #171d24);
}
.org-comments-door-body .comment-meta{
  font-size:12px;
  color:var(--text-secondary, #6b7280);
  margin-bottom:4px;
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:10px;
}
.org-comments-door-body .reply-box{
  border:1px dashed var(--border-color, #d1d5db);
  border-radius:12px;
  padding:10px;
  margin-top:12px;
  background:var(--bg-card, #171d24);
}
.org-comments-door-foot .reply-box{
  border:0;
  border-radius:0;
  padding:0;
  margin:0;
  background:transparent;
}
.org-comments-door-body textarea.form-control,
.org-comments-door-foot textarea.form-control{
  background:var(--bg-main, #171d24);
  color:var(--text-primary, #111827);
  border-color:var(--border-color, #e5e7eb);
}
body.org-comments-door-open{
  overflow:hidden !important;
}
.js-open-comments-door{
  cursor:pointer;
}
button.feed-viewall.js-open-comments-door:hover,
button.mini-muted.js-open-comments-door:hover{
  text-decoration:underline;
}
</style>

<div class="org-comments-door" id="orgCommentsDoor" aria-hidden="true">
  <button type="button" class="org-comments-door-backdrop" id="orgCommentsDoorBackdrop" aria-label="Close comments"></button>
  <aside class="org-comments-door-panel" role="dialog" aria-modal="true" aria-labelledby="orgCommentsDoorTitle">
    <div class="org-comments-door-head">
      <div>
        <span class="title" id="orgCommentsDoorTitle">Comments</span>
        <span class="count" id="orgCommentsDoorCount">0</span>
      </div>
      <button type="button" class="org-comments-door-close" id="orgCommentsDoorClose" aria-label="Close comments">
        <i class="fa fa-times"></i>
      </button>
    </div>
    <div class="org-comments-door-body" id="orgCommentsDoorBody">
      <div class="mini-muted">Select a post to load comments.</div>
    </div>
    <div class="org-comments-door-foot" id="orgCommentsDoorFoot"></div>
  </aside>
</div>

<script>
(function(){
  var door = document.getElementById('orgCommentsDoor');
  var body = document.getElementById('orgCommentsDoorBody');
  var foot = document.getElementById('orgCommentsDoorFoot');
  var countEl = document.getElementById('orgCommentsDoorCount');
  var closeBtn = document.getElementById('orgCommentsDoorClose');
  var backdrop = document.getElementById('orgCommentsDoorBackdrop');
  var currentPid = 0;

  function isOpen(){
    return !!(door && door.classList.contains('is-open'));
  }

  function closeDoor(){
    if (!door) return;
    door.classList.remove('is-open');
    door.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('org-comments-door-open');
  }

  function openDoor(){
    if (!door) return;
    door.classList.add('is-open');
    door.setAttribute('aria-hidden', 'false');
    document.body.classList.add('org-comments-door-open');
  }

  function updateCounts(pid, count){
    document.querySelectorAll('.jsReplyCount[data-pid="'+pid+'"]').forEach(function(el){
      el.textContent = String(count);
    });
    document.querySelectorAll('.feed-viewall[data-pid="'+pid+'"]').forEach(function(el){
      el.textContent = 'View all ' + count + ' comments';
    });
    if (countEl) countEl.textContent = String(count);
  }

  function bindDoorForm(){
    var form = document.getElementById('rmReplyForm');
    if (!form || form.__orgDoorBound) return;
    form.__orgDoorBound = true;
    form.addEventListener('submit', function(e){
      e.preventDefault();
      var fd = new FormData(form);
      fd.append('ajax', '1');
      fetch('feed.php', { method:'POST', body: fd })
        .then(function(r){ return r.text(); })
        .then(function(txt){
          var resp = null;
          try { resp = JSON.parse(txt); } catch (err) {}
          if (!resp || !resp.ok) {
            alert((resp && resp.err) ? resp.err : 'Failed to post comment.');
            return;
          }
          if (currentPid > 0) loadComments(currentPid);
          if (resp.count !== undefined && currentPid > 0) {
            updateCounts(currentPid, resp.count);
          }
        });
    });
  }

  function bindReplyButtons(){
    document.querySelectorAll('#orgCommentsDoorBody .replyBtn').forEach(function(btn){
      if (btn.__orgDoorBound) return;
      btn.__orgDoorBound = true;
      btn.addEventListener('click', function(e){
        e.preventDefault();
        var cid = parseInt(btn.getAttribute('data-cid') || '0', 10);
        var cname = btn.getAttribute('data-cname') || 'User';
        var replyTo = document.getElementById('reply_to');
        var label = document.getElementById('replyTargetLabel');
        var ta = document.getElementById('rm_comment_body');
        if (replyTo) replyTo.value = String(cid || 0);
        if (label) label.textContent = 'Reply to ' + cname + ' (comment #' + cid + ')';
        if (ta && !ta.disabled) ta.focus();
      });
    });

    var clearBtn = document.getElementById('clearReplyTarget');
    if (clearBtn && !clearBtn.__orgDoorBound) {
      clearBtn.__orgDoorBound = true;
      clearBtn.addEventListener('click', function(){
        var replyTo = document.getElementById('reply_to');
        var label = document.getElementById('replyTargetLabel');
        if (replyTo) replyTo.value = '0';
        if (label) label.textContent = 'Reply to post';
      });
    }
  }

  function mountDoorContent(html){
    if (!body) return;
    body.innerHTML = html || '<div class="mini-muted">No comments yet.</div>';
    var wrap = body.querySelector('#rmCommentsWrap');
    if (wrap) wrap.classList.remove('rm-collapsed');
    if (foot) {
      foot.innerHTML = '';
      var replyBox = body.querySelector('.reply-box');
      var lockedNote = body.querySelector('.org-comments-door-locked');
      if (replyBox) foot.appendChild(replyBox);
      else if (lockedNote) foot.appendChild(lockedNote);
    }
  }

  function loadComments(pid){
    if (!body) return Promise.resolve();
    body.innerHTML = '<div class="mini-muted">Loading…</div>';
    if (foot) foot.innerHTML = '';
    return fetch('feed.php?ajax=replies&pid=' + encodeURIComponent(pid), {
      method: 'GET',
      credentials: 'same-origin',
      headers: { Accept: 'application/json' }
    })
      .then(function(r){ return r.text(); })
      .then(function(txt){
        var resp = null;
        try { resp = JSON.parse(txt); } catch (err) {}
        if (!resp || !resp.ok) {
          body.innerHTML = '<div class="alert alert-danger">' + (resp && resp.err ? resp.err : 'Failed to load comments.') + '</div>';
          if (foot) foot.innerHTML = '';
          return;
        }
        mountDoorContent(resp.html || '<div class="mini-muted">No comments yet.</div>');
        if (resp.count !== undefined) updateCounts(pid, resp.count);
        bindDoorForm();
        bindReplyButtons();
      })
      .catch(function(){
        body.innerHTML = '<div class="alert alert-danger">Unable to load comments.</div>';
      });
  }

  function openCommentsDoor(pid){
    pid = parseInt(pid || '0', 10);
    if (pid <= 0) return;
    if (isOpen() && currentPid === pid) {
      closeDoor();
      return;
    }
    currentPid = pid;
    openDoor();
    loadComments(pid);
  }

  window.OrgCommentsDoor = {
    open: openCommentsDoor,
    close: closeDoor,
    isOpen: isOpen,
    getPostId: function(){ return currentPid; },
    refreshIfOpen: function(pid){
      pid = parseInt(pid || '0', 10);
      if (!isOpen() || pid <= 0 || pid !== currentPid) return;
      loadComments(pid);
    }
  };

  document.addEventListener('click', function(e){
    var btn = e.target.closest('.js-open-comments-door');
    if (btn) {
      e.preventDefault();
      openCommentsDoor(btn.getAttribute('data-pid'));
      return;
    }
    if (e.target === backdrop || e.target.closest('#orgCommentsDoorClose')) {
      e.preventDefault();
      closeDoor();
    }
  });

  document.addEventListener('keydown', function(e){
    if (e.key === 'Escape' && isOpen()) closeDoor();
  });

  closeBtn?.addEventListener('click', function(e){
    e.preventDefault();
    closeDoor();
  });
  backdrop?.addEventListener('click', function(e){
    e.preventDefault();
    closeDoor();
  });
})();
</script>
