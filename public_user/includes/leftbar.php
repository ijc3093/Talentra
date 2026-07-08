<?php
// /Business_only3/public_user/includes/leftbar.php
if (!empty($GLOBALS['msb_leftbar_included'])) {
  return;
}
$GLOBALS['msb_leftbar_included'] = true;
require_once __DIR__ . '/session_user.php';
requireUserLogin();
?>

<style>

/* ============================================================
   Leftbar overlay drawers (do NOT render NAV here)
   These drawers sit on top of the existing left sidebar.
   ============================================================ */
#ttLeftbarOverlays{
  position:fixed;
  left:var(--feedRailW, 84px);
  top:0;
  height:100vh;
  width:min(400px, 30vw);
  z-index:990;
  pointer-events:none;
  background:transparent;
  box-sizing:border-box;
  --tt-panel-bg:var(--msb-palette-bg, #ffffff);
  --tt-panel-bg-alt:var(--msb-palette-surface-2, #f7f8fa);
  --tt-panel-bg-strong:var(--msb-palette-surface, #eef2f6);
  --tt-panel-border:var(--msb-palette-border, rgba(15,23,42,.08));
  --tt-panel-border-strong:var(--msb-palette-border-strong, rgba(15,23,42,.14));
  --tt-text:var(--msb-palette-text, #101828);
  --tt-muted:var(--msb-palette-text-muted, #667085);
  --tt-soft:var(--msb-palette-text-muted, #98a2b3);
  --tt-bubble-bg:transparent;
  --tt-bubble-border:transparent;
  --tt-thread:var(--msb-palette-border-strong, rgba(15,23,42,.18));
  --tt-control-bg:var(--msb-palette-hover-bg, #f2f4f7);
  --tt-control-hover:var(--msb-palette-nav-hover, #e9edf3);
  --tt-control-border:var(--msb-palette-border, rgba(15,23,42,.08));
  --tt-input-bg:var(--msb-palette-input-bg, #1f1f1f);
  --tt-input-border:var(--msb-palette-border-strong, rgba(15,23,42,.08));
  --tt-input-placeholder:var(--msb-palette-placeholder, #98a2b3);
  --tt-accent:var(--msb-palette-action, var(--msb-palette-link, #2563eb));
  --tt-accent-soft:var(--msb-palette-action-soft, var(--msb-palette-nav-active-bg, rgba(37,99,235,.08)));
  --tt-accent-strong:var(--msb-palette-link-hover, #1d4ed8);
  --tt-focus-bg:var(--msb-palette-hover-bg, rgba(37,99,235,.06));
  --tt-focus-border:var(--msb-palette-border-strong, rgba(37,99,235,.16));
  --tt-focus-shadow:none;
  --tt-send-bg:#7c1730;
  --tt-send-bg-hover:#991c3d;
}
@media (max-width: 991.98px){
  #ttLeftbarOverlays{
    left:0;
    width:min(400px, 88vw);
  }
}
html.dark-auto #ttLeftbarOverlays,
html[data-theme="dark"] #ttLeftbarOverlays{
  --tt-panel-bg:var(--msb-palette-bg, #171d24);
  --tt-panel-bg-alt:var(--msb-palette-surface-2, #1f2630);
  --tt-panel-bg-strong:var(--msb-palette-surface, #232b35);
  --tt-text:var(--msb-palette-text, #f3f6fb);
  --tt-muted:var(--msb-palette-text-muted, #b1bcce);
}
html[data-msb-appearance] #ttLeftbarOverlays{
  --tt-panel-bg:var(--msb-palette-bg);
  --tt-panel-bg-alt:var(--msb-palette-surface-2);
  --tt-panel-bg-strong:var(--msb-palette-surface);
  --tt-panel-border:var(--msb-palette-border);
  --tt-panel-border-strong:var(--msb-palette-border-strong);
  --tt-text:var(--msb-palette-text);
  --tt-muted:var(--msb-palette-text-muted);
  --tt-soft:var(--msb-palette-text-muted);
  --tt-thread:var(--msb-palette-border-strong);
  --tt-control-bg:var(--msb-palette-hover-bg);
  --tt-control-hover:var(--msb-palette-nav-hover);
  --tt-control-border:var(--msb-palette-border);
  --tt-input-bg:var(--msb-palette-input-bg, #1f1f1f);
  --tt-input-border:var(--msb-palette-border-strong);
  --tt-input-placeholder:var(--msb-palette-placeholder);
  --tt-accent:var(--msb-palette-action, var(--msb-palette-link));
  --tt-accent-soft:var(--msb-palette-action-soft, var(--msb-palette-nav-active-bg));
  --tt-accent-strong:var(--msb-palette-link-hover);
  --tt-focus-bg:var(--msb-palette-hover-bg);
  --tt-focus-border:var(--msb-palette-border-strong);
}
#ttLeftbarOverlays .tt-comments-wrap{
  position:absolute !important;
  inset:0 !important;
  background:var(--tt-panel-bg);
  z-index:999 !important;
  display:flex !important;
  flex-direction:column !important;
  overflow:hidden !important;
  min-height:0 !important;
  box-shadow:18px 0 48px rgba(0,0,0,.32);
  transform:translateX(-105%);
  opacity:0;
  pointer-events:none;
  transition:transform .18s ease, opacity .18s ease;
}
#ttLeftbarOverlays .tt-comments-wrap.is-open{
  transform:translateX(0);
  opacity:1;
  pointer-events:auto;
  border-left:1px solid var(--tt-panel-border-strong, #d1d5db);
  border-right:1px solid var(--tt-panel-border-strong, #d1d5db);
  box-sizing:border-box;
}
#ttLeftbarOverlays .tt-readmore-wrap{
  position:absolute !important;
  inset:0 !important;
  background:var(--tt-panel-bg);
  z-index:998 !important;
  display:flex !important;
  flex-direction:column !important;
  overflow:hidden !important;
  min-height:0 !important;
  box-shadow:18px 0 48px rgba(0,0,0,.32);
  transform:translateX(-105%);
  opacity:0;
  pointer-events:none;
  transition:transform .18s ease, opacity .18s ease;
}
#ttLeftbarOverlays .tt-readmore-wrap.is-open{
  transform:translateX(0);
  opacity:1;
  pointer-events:auto;
  border-left:1px solid var(--tt-panel-border-strong, #d1d5db);
  border-right:1px solid var(--tt-panel-border-strong, #d1d5db);
  box-sizing:border-box;
}
#ttLeftbarOverlays .tt-menu-wrap,
#ttLeftbarOverlays .tt-profile-wrap,
#ttLeftbarOverlays .tt-messages-wrap,
#ttLeftbarOverlays .tt-notifications-wrap,
#ttLeftbarOverlays .tt-friend-requests-wrap,
#ttLeftbarOverlays .tt-live-wrap{
  position:absolute !important;
  inset:0 !important;
  pointer-events:none;
}
#ttLeftbarOverlays .tt-menu-wrap.is-open,
#ttLeftbarOverlays .tt-profile-wrap.is-open,
#ttLeftbarOverlays .tt-messages-wrap.is-open,
#ttLeftbarOverlays .tt-notifications-wrap.is-open,
#ttLeftbarOverlays .tt-friend-requests-wrap.is-open,
#ttLeftbarOverlays .tt-live-wrap.is-open{
  pointer-events:auto;
  border-left:1px solid var(--tt-panel-border-strong, #d1d5db);
  border-right:1px solid var(--tt-panel-border-strong, #d1d5db);
  box-sizing:border-box;
}

/* ============================================================
   ONE Shamcey sidebar container
   Nav = normal content
   Comments = overlay drawer (no layout conflict)
   ============================================================ */
/* (scoped) Leftbar overlays only */
/* ============================================================
   COMMENTS DRAWER OVERLAY (the key fix)
   ============================================================ */
.tt-comments-wrap{
  position:absolute !important;
  inset:0 !important;
  background:var(--tt-panel-bg);
  z-index:999 !important;
  display:flex !important;
  flex-direction:column !important;
  overflow:hidden !important;
  min-height:0 !important;
  box-shadow:18px 0 48px rgba(0,0,0,.32);
  transform:translateX(-105%);
  opacity:0;
  pointer-events:none;
  transition:transform .18s ease, opacity .18s ease;
}
.tt-comments-wrap.is-open{
  transform:translateX(0);
  opacity:1;
  pointer-events:auto;
}

/* Header sticky */
.tt-comments-head{
  flex: 0 0 auto !important;
  display:flex;
  align-items:center;
  justify-content:space-between;
  padding:22px 24px 16px;
  border-bottom:1px solid transparent;
  background:var(--tt-panel-bg);
  position: sticky !important;
  top: 0 !important;
  z-index: 30 !important;
}

/* Only list scrolls */
.tt-comments-list{
  flex: 1 1 auto !important;
  min-height:0 !important;
  overflow-y:auto !important;
  overflow-x:hidden !important;
  padding:4px 18px 18px;
  -webkit-overflow-scrolling: touch;
  overscroll-behavior: contain;
  margin-bottom: 0px;
  background:var(--tt-panel-bg);
}
.tt-comments-list .text-muted{ color:var(--tt-muted) !important; }

/* Footer sticky */
.tt-comments-foot{
  flex: 0 0 auto !important;
  border-top:1px solid rgba(255,255,255,.06);
  padding:10px 16px 18px;
  background:var(--tt-panel-bg);
  position: sticky !important;
  /* bottom: 155px !important; */
  z-index: 30 !important;
  transform: translateZ(0);

}

/* UI bits */
.tt-comments-head .title{ font-weight:800; font-size:20px; line-height:1.1; color:var(--tt-text); }
.tt-comments-head .count{ font-weight:700; font-size:14px; color:var(--tt-muted); margin-left:8px; }

.tt-close{
  width:42px;height:42px;
  border-radius:999px;
  border:1px solid transparent;
  background:var(--tt-control-bg);
  color:var(--tt-text);
  display:flex;align-items:center;justify-content:center;
  cursor:pointer;
}
.tt-close i{ font-size:20px; }
.tt-close:hover{ background:var(--tt-control-hover); }

/* Comment rows */
.tt-node{position:relative;--tt-avatar-size:20px;}
.tt-node.has-children::after{
  content:"";
  position:absolute;
  left:calc(var(--tt-avatar-size) / 2);
  top:calc(var(--tt-avatar-size) + 10px);
  bottom:20px;
  width:2px;
  background:var(--tt-thread);
  border-radius:999px;
}
.tt-node.has-children.is-collapsed::after{display:none;}
.tt-children{
  margin-left:calc(var(--tt-avatar-size) / 2);
  padding-left:28px;
}
.tt-children.depth-capped{
  margin-left:0;
  padding-left:0;
}
.tt-node.is-reply::before{
  content:"";
  position:absolute;
  left:-30px;
  top:8px;
  width:30px;
  height:17px;
  border-left:2px solid var(--tt-thread);
  border-bottom:2px solid var(--tt-thread);
  border-bottom-left-radius:18px;
}
.tt-node.is-depth-clamped::before{display:none;}
.tt-comment{ display:flex; gap:7px; padding:14px 12px 12px; border-radius:18px; }
.tt-comment.is-alert-focus{ background:var(--tt-focus-bg); border:1px solid var(--tt-focus-border); box-shadow:var(--tt-focus-shadow); margin:2px 0 10px; }
.tt-avatar{
  width:20px;height:20px;border-radius:999px;
  background:#111;color:#fff;
  display:flex;align-items:center;justify-content:center;
  font-weight:700;font-size:8px; flex:0 0 auto;
  overflow:hidden;
  position:relative;
}
.tt-avatar img{ width:100%; height:100%; object-fit:cover; display:block; }
.tt-body{ flex:1; min-width:0; display:flex; flex-direction:column; }
.tt-bubble{display:block;max-width:100%;background:var(--tt-bubble-bg);border:1px solid var(--tt-bubble-border);border-radius:0;padding:0;min-width:0;}
.tt-name{ font-weight:700; font-size:15px; line-height:1.25; color:var(--tt-text); margin-bottom:6px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.tt-text{ font-size:14px; color:var(--tt-text); line-height:1.4; word-wrap:break-word; }
.tt-meta{ display:flex; gap:14px; align-items:center; flex-wrap:wrap; margin-top:8px; font-size:12px; color:var(--tt-muted); }
.tt-meta > span:first-child{ min-width:auto; }
.tt-inlinebtn{border:0;background:transparent;padding:0;color:inherit;font:inherit;font-weight:700;cursor:pointer;}
.tt-inlinebtn:hover{color:var(--tt-text);}
.tt-likebtn{margin-left:auto;order:10;font-weight:500;}
.tt-likebtn i{font-size:15px;margin-right:5px;vertical-align:-1px;}
.tt-toggle-replies{color:var(--tt-muted);font-weight:700;position:relative;padding-left:36px !important;display:inline-flex;align-items:center;gap:8px;}
.tt-toggle-replies::before{
  content:"";
  position:absolute;
  left:0;
  top:50%;
  width:22px;
  height:1px;
  background:var(--tt-thread);
  transform:translateY(-50%);
}
.tt-toggle-replies::after{
  content:"\f3d0";
  font-family:"Ionicons";
  font-size:13px;
  line-height:1;
}
.tt-toggle-replies:hover{color:var(--tt-text);}
.tt-likebtn.liked{color:var(--tt-text);}
.tt-likepill{display:none;}

/* Footer input */
.tt-input-row{ display:flex; align-items:center; gap:10px; }
.tt-input{
  flex:1; border:1px solid var(--tt-input-border);
  border-radius:999px; padding:12px 14px;
  outline:none; font-size:14px;
  background:var(--tt-input-bg);
  color:var(--tt-text);
  min-height:46px;
}
.tt-input::placeholder{ color:var(--tt-input-placeholder); font-size:14px; }
.tt-iconbtn{
  width:25px;height:25px;border-radius:999px;
  border:1px solid transparent;
  background:var(--tt-control-bg); color:var(--tt-text);
  display:flex;align-items:center;justify-content:center;
  cursor:pointer;
  font-size:22px;
}
.tt-iconbtn:hover{ background:var(--tt-control-hover); }
.tt-iconbtn i{ font-size:22px; }
#ttAtBtn{
  background:linear-gradient(180deg, #ff2e89 0%, #c11353 100%);
  color:#fff;
  box-shadow:none;
}
.tt-send{
  width:25px;height:25px;border-radius:999px;border:none;
  background:var(--tt-send-bg);color:#fff;
  display:flex;align-items:center;justify-content:center;
  cursor:pointer;
}
.tt-send:hover{ background:var(--tt-send-bg-hover); }
.tt-send i{ font-size:21px; }

.tt-replying{
  display:none; align-items:center; justify-content:space-between;
  gap:8px; font-size:13px; color:var(--tt-muted); padding:0 8px 10px;
}
.tt-replying .x{ cursor:pointer; color:var(--tt-text); font-weight:800; }

@media (min-width:1025px){
  .tt-comments-head{ padding:20px 20px 14px; }
  .tt-comments-list{ padding:4px 10px 10px; }
  .tt-comments-foot{ padding:10px 16px 20px; }
  .tt-comment{ padding-left:10px; padding-right:10px; }
  .tt-name{ font-size:15px; }
  .tt-text{ font-size:14px; }
}

/* ============================================================
   READ MORE DRAWER (different layout from comments)
   ============================================================ */
.tt-readmore-wrap{
  position:absolute !important;
  inset:0 !important;
  background:var(--tt-panel-bg);
  z-index:998 !important;
  display:flex !important;
  flex-direction:column !important;
  overflow:hidden !important;
  min-height:0 !important;
  box-shadow:18px 0 48px rgba(0,0,0,.32);
  transform:translateX(-105%);
  opacity:0;
  pointer-events:none;
  transition:transform .18s ease, opacity .18s ease;
}
.tt-readmore-wrap.is-open{
  transform:translateX(0);
  opacity:1;
  pointer-events:auto;
}
.tt-rm-head{
  flex:0 0 auto !important;
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:10px;
  padding:20px;
  border-bottom:1px solid var(--tt-panel-border);
  /* background:#fff; */
  background:var(--tt-panel-bg);
  position: sticky !important;
  top:0 !important;
  z-index:30 !important;
}
.tt-rm-left{ display:flex; align-items:center; gap:10px; min-width:0; }
.tt-rm-avatar{
  width:34px;height:34px;border-radius:999px;
  display:flex;align-items:center;justify-content:center;
  font-weight:800;font-size:12px;color:#fff;background:#111;
  flex:0 0 auto;
  overflow:hidden;
}
.tt-rm-avatar.has-photo{
  background:transparent;
}
.tt-rm-avatar img{
  width:100%;
  height:100%;
  object-fit:cover;
  display:block;
  border-radius:999px;
}
.tt-rm-txt{ min-width:0; }
.tt-rm-title{ font-weight:800; font-size:14px; color:var(--tt-text); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.tt-rm-sub{ font-size:12px; color:var(--tt-muted); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.tt-rm-body{
  flex:1 1 auto !important;
  min-height:0 !important;
  overflow-y:auto !important;
  padding:20px;
  font-size:14px;
  line-height:1.5;
  word-break:break-word;
  color:var(--tt-text);
  background:var(--tt-panel-bg);
  text-align:left;
}
.tt-rm-body .tt-richtext{display:block;color:inherit;font:inherit;line-height:inherit;text-align:left;}
.tt-rm-body .tt-rich-p{margin:0 0 12px;white-space:normal;word-break:break-word;text-align:left;}
.tt-rm-body .tt-rich-p:last-child{margin-bottom:0;}
.tt-rm-body .tt-rich-list{margin:0 0 12px;padding-left:22px;}
.tt-rm-body .tt-rich-list.is-ordered{list-style:decimal;}
.tt-rm-body .tt-rich-list.is-bullet{list-style:disc;}
.tt-rm-body .tt-rich-li{margin:0 0 6px;}
.tt-rm-body .tt-rich-li:last-child{margin-bottom:0;}
.tt-rich-ellipsis{display:inline;}

</style>

<!-- leftbar overlays only: no nav duplicated -->
<div id="ttLeftbarOverlays">

  <!-- ✅ COMMENTS drawer overlay -->
  <div class="tt-comments-wrap" id="tt-comments-wrap" aria-hidden="true">

    <div class="tt-comments-head">
      <div>
        <span class="title">Comments</span>
        <span class="count" id="ttCommentsCount">0</span>
      </div>
      <button class="tt-close" type="button" id="ttCommentsClose" title="Close">
        <i class="icon ion-close"></i>
      </button>
    </div>

    <div class="tt-comments-list" id="ttCommentsList">
      <div class="text-muted" style="padding:10px 6px;">Select a post to load comments.</div>
    </div>

    <div class="tt-comments-foot">
      <div class="tt-replying" id="ttReplyingRow">
        <div id="ttReplyingTo">Replying…</div>
        <div class="x" id="ttCancelReply">Cancel</div>
      </div>

      <form id="ttCommentForm" class="m-0" autocomplete="off">
        <input type="hidden" id="ttPostId" value="0">
        <input type="hidden" id="ttParentId" value="0">
        <div class="tt-input-row">
          <button type="button" class="tt-iconbtn" id="ttAtBtn" title="Mention">
            <i class="icon ion-at"></i>
          </button>
          <input class="tt-input" id="ttCommentText" type="text" placeholder="Add comment..." />
          <button type="button" class="tt-iconbtn" id="ttEmojiBtn" title="Emoji">
            <i class="icon ion-happy-outline"></i>
          </button>
          <button class="tt-send" type="submit" title="Send">
            <i class="icon ion-arrow-up-a"></i>
          </button>
        </div>
      </form>
    </div>

  </div>


  <?php include __DIR__ . '/menu_door.php'; ?>
  <?php include __DIR__ . '/profile_door.php'; ?>
  <?php include __DIR__ . '/messages_door.php'; ?>
  <?php include __DIR__ . '/notifications_door.php'; ?>
  <?php include __DIR__ . '/friend_requests_door.php'; ?>
  <?php
    $msbLiveDoorCanStudio = !empty($canLiveStudio ?? null)
      ? (bool)$canLiveStudio
      : (!empty($headerCanLiveStudio ?? null) ? (bool)$headerCanLiveStudio : null);
    include __DIR__ . '/live_door.php';
  ?>

  <!-- ✅ READ MORE drawer overlay -->
  <div class="tt-readmore-wrap" id="tt-readmore-wrap">
    <div class="tt-rm-head">
      <div class="tt-rm-left">
        <div class="tt-rm-avatar" id="ttRmAvatar" aria-hidden="true"></div>
        <div class="tt-rm-txt">
          <div class="tt-rm-title" id="ttRmTitle"></div>
          <div class="tt-rm-sub" id="ttRmSub"></div>
        </div>
      </div>
      <button class="tt-close" type="button" id="ttRmClose" title="Close">
        <i class="icon ion-close"></i>
      </button>
    </div>
    <div class="tt-rm-body" id="ttRmBody"></div>
  </div>
</div>
<script>
(function(){
  const $wrap = document.getElementById('tt-comments-wrap');
  const $list = document.getElementById('ttCommentsList');
  const $count = document.getElementById('ttCommentsCount');
  const $postId = document.getElementById('ttPostId');
  const $parentId = document.getElementById('ttParentId');
  const $text = document.getElementById('ttCommentText');
  const $form = document.getElementById('ttCommentForm');
  const $replyRow = document.getElementById('ttReplyingRow');
  const $replyTo = document.getElementById('ttReplyingTo');
  const $cancelReply = document.getElementById('ttCancelReply');
  const $close = document.getElementById('ttCommentsClose');
  let focusCommentId = 0;
  const defaultPlaceholder = 'Add comment...';
  let currentCommentsPostId = 0;
  let currentComments = [];
  let currentByParent = {};
  const collapsedReplyIds = new Set();
  const MAX_REPLY_CURVE_DEPTH = 4;

  function esc(s){
    return String(s ?? '').replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
  }
  function initials(name){
    name = String(name||'').trim();
    if(!name) return '?';
    const parts = name.split(/\s+/).filter(Boolean);
    return ((parts[0]||'?')[0] + ((parts[1]||'')[0]||'')).toUpperCase();
  }
  function fmtShort(dt){
    if(!dt) return '';
    const m = String(dt).match(/^(\d{4})-(\d{2})-(\d{2})\s+(\d{2}):(\d{2})/);
    if(!m) return String(dt);
    return m[2] + '-' + m[3] + ' ' + m[4] + ':' + m[5];
  }
  function timeAgo(ts){
    const t = Date.parse(ts || '');
    if(!t) return '';
    const sec = Math.floor((Date.now() - t) / 1000);
    if(sec < 60) return sec + 's';
    const m = Math.floor(sec / 60); if(m < 60) return m + 'm';
    const h = Math.floor(m / 60); if(h < 24) return h + 'h';
    const d = Math.floor(h / 24); if(d < 7) return d + 'd';
    const w = Math.floor(d / 7); if(w < 4) return w + 'w';
    const mo = Math.floor(d / 30); if(mo < 12) return mo + 'mo';
    return Math.floor(d / 365) + 'y';
  }
  function avatarUrl(c, size){
    c = c || {};
    size = Number(size || 72);
    const params = [];
    const uid = Number(c.user_id || c.uid || c.id || 0);
    const username = String(c.username || '').trim();
    const friendCode = String(c.friend_code || '').trim();
    const email = String(c.email || '').trim();
    const name = String(c.display_name || c.author_name || c.fullname || username || 'User').trim();
    if(uid > 0) params.push('u=' + encodeURIComponent(String(uid)));
    if(email) params.push('email=' + encodeURIComponent(email));
    if(friendCode) params.push('friend_code=' + encodeURIComponent(friendCode));
    if(username) params.push('username=' + encodeURIComponent(username));
    if(name) params.push('name=' + encodeURIComponent(name));
    params.push('s=' + encodeURIComponent(String(size)));
    return 'avatar.php?' + params.join('&');
  }
  function replyToggleLabel(count, isOpen){
    const noun = count === 1 ? 'reply' : 'replies';
    return isOpen ? 'Close replies' : ('Open ' + count + ' ' + noun);
  }
  function collapseRepliesByDefault(comments){
    collapsedReplyIds.clear();
    (Array.isArray(comments) ? comments : []).map(normalizeComment).forEach(c=>{
      if(Number(c.parent_id || 0) > 0) collapsedReplyIds.add(Number(c.parent_id));
    });
  }

  function normalizeComment(c){
    return {
      id: Number(c.id || c.comment_id || 0),
      user_id: Number(c.user_id || c.uid || 0),
      parent_id: Number(c.parent_id || c.parentId || 0),
      username: c.username || '',
      display_name: c.display_name || c.author_name || c.username || c.fullname || 'User',
      friend_code: c.friend_code || '',
      email: c.email || '',
      comment_text: c.comment_text || c.body || c.text || '',
      created_at: c.created_at || c.createdAt || '',
      me_liked: Number(c.me_liked || c.meLiked || 0),
      like_count: Number(c.like_count || c.likeCount || 0),
    };
  }

  function setReply(parentId, who, mode){
    mode = String(mode || 'Reply');
    const isCommentMode = mode === 'Comment';
    $parentId.value = String(parentId||0);
    if(parentId>0){
      $replyRow.style.display = 'flex';
      $replyTo.textContent = (isCommentMode ? 'Commenting on ' : 'Replying to ') + (who || 'comment');
      if($text) $text.placeholder = (isCommentMode ? 'Comment on ' : 'Reply to ') + (who || 'comment');
    } else {
      $replyRow.style.display = 'none';
      $replyTo.textContent = '';
      if($text) $text.placeholder = defaultPlaceholder;
    }
  }
  $cancelReply?.addEventListener('click', ()=>setReply(0,''));

  $close?.addEventListener('click', ()=>{
    closeCommentsPanel();
  });

  var commentsPanelOpen = false;

  function commentsDoorDomOpen(){
    return !!($wrap && $wrap.classList.contains('is-open'));
  }

  function syncCommentsPanelState(){
    commentsPanelOpen = commentsDoorDomOpen();
  }

  function readMorePanelOpen(){
    var rm = document.getElementById('tt-readmore-wrap');
    return !!(rm && rm.classList.contains('is-open'));
  }

  function closeOtherPanelsForComments(){
    try {
      if(window.TTReadMore && typeof window.TTReadMore.close === 'function') window.TTReadMore.close();
    } catch(e){}
    try {
      if(window.TTMenu && typeof window.TTMenu.close === 'function') window.TTMenu.close();
    } catch(e){}
    try {
      if(window.TTProfile && typeof window.TTProfile.close === 'function') window.TTProfile.close();
    } catch(e){}
    if(window.TTMessages && typeof window.TTMessages.close === 'function') window.TTMessages.close();
    if(window.TTNotifications && typeof window.TTNotifications.close === 'function') window.TTNotifications.close();
    if(window.TTFriendRequests && typeof window.TTFriendRequests.close === 'function') window.TTFriendRequests.close();
    if(window.TTLive && typeof window.TTLive.close === 'function') window.TTLive.close();
    try {
      if(window.TTLiveRight && typeof window.TTLiveRight.close === 'function') window.TTLiveRight.close();
    } catch(e){}
  }

  function closeCommentsPanel(){
    if(!$wrap || !commentsDoorDomOpen()) return;
    commentsPanelOpen = false;
    $wrap.classList.remove('is-open');
    $wrap.setAttribute('aria-hidden', 'true');
    if(!readMorePanelOpen()){
      document.body.classList.remove('public-leftbar-open', 'profile-leftbar-open');
    }
  }

  function openCommentsPanel(){
    if(!$wrap || commentsDoorDomOpen()) return;
    closeOtherPanelsForComments();
    commentsPanelOpen = true;
    $wrap.classList.add('is-open');
    $wrap.setAttribute('aria-hidden', 'false');
    document.body.classList.add('public-leftbar-open');
    if(document.body.classList.contains('profile-page')){
      document.body.classList.add('profile-leftbar-open');
    }
  }

  function openPanel(){
    if(!$wrap) return;
    closeOtherPanelsForComments();
    syncCommentsPanelState();
    if(commentsPanelOpen) return;
    openCommentsPanel();
  }

  function postIdFromCommentTrigger(trigger){
    if(!trigger) return 0;
    var card = trigger.closest('.mf-card, .public-post-card, article.post');
    if(card){
      var fromCard = Number(card.getAttribute('data-id') || card.getAttribute('data-post-id') || 0);
      if(fromCard > 0) return fromCard;
    }
    return Number(trigger.getAttribute('data-post-id') || 0);
  }

  function fetchCommentsForPost(postId){
    postId = Number(postId || 0);
    if(!postId) return Promise.resolve([]);
    var apiUrl = String(window.API_URL || 'feed_api.php');
    var url = apiUrl + (apiUrl.indexOf('?') >= 0 ? '&' : '?') + 'ajax=view&id=' + encodeURIComponent(String(postId));
    return fetch(url, { credentials:'same-origin', cache:'no-store' })
      .then(function(res){ return res.json(); })
      .then(function(res){
        if(res && res.ok && Array.isArray(res.comments)) return res.comments;
        return [];
      })
      .catch(function(){ return []; });
  }

  function openCommentsForPost(postId, comments, opts){
    postId = Number(postId || 0);
    if(!postId) return;
    opts = opts || {};

    if(commentsDoorDomOpen() && Number(currentCommentsPostId) === postId && opts.toggle){
      closeCommentsPanel();
      return;
    }

    if(typeof window.TTComments.clearFocusComment === 'function'){
      window.TTComments.clearFocusComment();
    }

    openCommentsPanel();

    if(Array.isArray(comments)){
      window.TTComments.setPost(postId, comments, false);
      return;
    }

    window.TTComments.setPost(postId, [], false);
    if($list) $list.innerHTML = '<div class="text-muted" style="padding:10px 6px;">Loading comments...</div>';

    fetchCommentsForPost(postId).then(function(items){
      if(Number(currentCommentsPostId) !== postId) return;
      window.TTComments.setPost(postId, items, false);
      if(typeof opts.onLoaded === 'function') opts.onLoaded(items);
    }).catch(function(){
      if(Number(currentCommentsPostId) !== postId) return;
      if($list) $list.innerHTML = '<div class="text-danger" style="padding:10px 6px;">Unable to load comments.</div>';
    });
  }

  function reactionLabel(reaction){
    if(window.MSBReactions && typeof window.MSBReactions.label === 'function'){
      return window.MSBReactions.label(reaction || 'love');
    }
    var key = String(reaction || '').trim().toLowerCase();
    if(key === 'like') return 'Like';
    if(key === 'smile') return 'Smile';
    if(key === 'laugh') return 'Laugh';
    if(key === 'wow') return 'Wow';
    if(key === 'sad') return 'Sad';
    if(key === 'angry') return 'Angry';
    return 'Love';
  }

  function commentHtml(c, depth, childrenHtml){
    const dn = c.display_name;
    const liked = (c.me_liked === 1);
    const likeCount = c.like_count;
    const myReaction = String(c.my_reaction || '');
    const currentLabel = reactionLabel(myReaction);
    const when = timeAgo(c.created_at) || fmtShort(c.created_at);
    const avatar = avatarUrl(c, 72);
    const replyCount = (currentByParent[c.id] || []).length;
    const repliesOpen = !collapsedReplyIds.has(c.id);
    const depthClamped = depth > MAX_REPLY_CURVE_DEPTH;
    const childDepthCapped = (depth + 1) > MAX_REPLY_CURVE_DEPTH;
    const replyActionLabel = c._reply_action_label || 'Reply';
    const replyTargetId = Number(c._reply_target_id || c.id);
    return `
      <div class="tt-node${depth > 0 ? ' is-reply' : ''}${replyCount > 0 ? ' has-children' : ''}${replyCount > 0 && !repliesOpen ? ' is-collapsed' : ''}${depthClamped ? ' is-depth-clamped' : ''}" data-cid="${c.id}">
        <div class="tt-comment" data-cid="${c.id}">
          <div class="tt-avatar" title="${esc(dn)}"><img src="${esc(avatar)}" alt="${esc(dn)}"></div>
          <div class="tt-body">
            <div class="tt-bubble">
              <div class="tt-name">${esc(dn)}</div>
              <div class="tt-text">${esc(c.comment_text)}</div>
            </div>
            <div class="tt-meta">
              <span>${esc(when)}</span>
              <button type="button" class="tt-inlinebtn tt-likebtn tt-reactbtn ${liked ? 'liked' : ''}" data-heart="${c.id}" data-reaction="${esc(myReaction)}"><i class="fa fa-heart-o"></i><span data-reaction-label>${esc(liked ? currentLabel : 'Love')}</span></button>
              <button type="button" class="tt-inlinebtn tt-reply-link" data-reply="${replyTargetId}" data-who="${esc(dn)}" data-mode="${esc(replyActionLabel)}">${esc(replyActionLabel)}</button>
              ${replyCount > 0 ? `<button type="button" class="tt-inlinebtn tt-toggle-replies" data-toggle-replies="${c.id}">${esc(replyToggleLabel(replyCount, repliesOpen))}</button>` : ``}
              ${likeCount > 0 ? `<span class="tt-likepill"><i class="icon ion-thumbsup"></i>${likeCount}</span>` : ``}
            </div>
          </div>
        </div>
        ${replyCount > 0 && repliesOpen ? `<div class="tt-children${childDepthCapped ? ' depth-capped' : ''}">${childrenHtml}</div>` : ``}
      </div>
    `;
  }

  function bindHeart(el){
    el.addEventListener('click', async function(e){
      e.preventDefault();
      const cid = Number(this.getAttribute('data-heart')||0);
      const pid = Number($postId.value||0);
      const currentReaction = String(this.getAttribute('data-reaction') || '');
      if(!pid || !cid) return;
      if(currentReaction === 'love') return;

      try{
        const fd = new FormData();
        fd.append('ajax','comment_like');
        fd.append('post_id', String(pid));
        fd.append('comment_id', String(cid));
        fd.append('reaction', 'love');
        const r = await fetch('feed_api.php', { method:'POST', body: fd, cache:'no-store' });
        const data = await r.json();
        if(data && data.ok){
          if(window.TTComments && typeof window.TTComments.refreshCurrent === 'function'){
            window.TTComments.refreshCurrent();
          }
        }
      }catch(err){}
    });
  }

  function render(comments){
    comments = Array.isArray(comments) ? comments.map(normalizeComment) : [];
    currentComments = comments;
    $count.textContent = String(comments.length);

    if(comments.length === 0){
      $list.innerHTML = '<div class="text-muted" style="padding:10px 6px;">No comments yet.</div>';
      return;
    }

    const byId = {};
    comments.forEach(c => { byId[c.id] = Object.assign({}, c, { _replies: [] }); });
    const top = [];
    Object.values(byId).forEach(c=>{
      if(c.parent_id > 0 && byId[c.parent_id]){
        byId[c.parent_id]._replies.push(c);
      } else {
        top.push(c);
      }
    });
    function annotateReplyDepth(node, depth, cappedAncestorId){
      const nextCappedAncestorId = (depth === MAX_REPLY_CURVE_DEPTH - 1) ? Number(node.id || 0) : cappedAncestorId;
      node._reply_target_id = (depth >= MAX_REPLY_CURVE_DEPTH && cappedAncestorId > 0) ? cappedAncestorId : Number(node.id || 0);
      node._reply_action_label = (depth >= MAX_REPLY_CURVE_DEPTH) ? 'Comment' : 'Reply';
      node._replies.forEach(child => annotateReplyDepth(child, depth + 1, nextCappedAncestorId));
    }
    top.forEach(node => annotateReplyDepth(node, 0, 0));
    currentByParent = {};
    Object.values(byId).forEach(c => { currentByParent[c.id] = c._replies; });
    function threadHtml(nodes, depth){
      return (nodes || []).map(child => commentHtml(child, depth, threadHtml(child._replies, depth + 1))).join('');
    }

    $list.innerHTML = threadHtml(top, 0);

    function bindReplyLinks(scope){
      (scope || $list).querySelectorAll('[data-reply]').forEach(el=>{
        el.addEventListener('click', function(){
          setReply(
            Number(this.getAttribute('data-reply')||0),
            this.getAttribute('data-who')||'comment',
            this.getAttribute('data-mode')||'Reply'
          );
          $text.focus();
        });
      });
    }

    function focusRenderedComment(){
      if(!focusCommentId) return false;
      $list.querySelectorAll('.tt-comment.is-alert-focus').forEach(node => node.classList.remove('is-alert-focus'));
      const row = $list.querySelector('.tt-comment[data-cid="'+String(focusCommentId)+'"]');
      if(!row) return false;
      row.classList.add('is-alert-focus');
      try{ row.scrollIntoView({ block:'center', behavior:'smooth' }); }catch(err){}
      return true;
    }

    bindReplyLinks($list);
    (function bindReplyToggles(scope){
      (scope || $list).querySelectorAll('[data-toggle-replies]').forEach(el=>{
        el.addEventListener('click', function(){
          const cid = Number(this.getAttribute('data-toggle-replies') || 0);
          if(!cid) return;
          if(collapsedReplyIds.has(cid)) collapsedReplyIds.delete(cid);
          else collapsedReplyIds.add(cid);
          render(currentComments);
        });
      });
    })($list);

    $list.querySelectorAll('[data-heart]').forEach(bindHeart);
    if(window.MSBReactions){
      $list.querySelectorAll('.tt-reactbtn').forEach(function(btn){
        window.MSBReactions.applyReactionButton(btn, btn.getAttribute('data-reaction') || '', 'love');
      });
    }
    if(focusCommentId > 0) focusRenderedComment();
    $list.scrollTop = $list.scrollHeight;
  }

  $form?.addEventListener('submit', async function(e){
    e.preventDefault();
    const txt = String($text.value||'').trim();
    const newsItemId = String(window.__newsCommentItemId || '').trim();
    if (newsItemId && txt) {
      try {
        const fd = new FormData();
        fd.append('action', 'comment');
        fd.append('item_id', newsItemId);
        fd.append('text', txt);
        const r = await fetch('news_api.php', { method:'POST', body: fd, cache:'no-store' });
        const data = await r.json();
        if (data && data.ok) {
          $text.value = '';
          setReply(0, '');
          if (window.TTNews && typeof window.TTNews.refreshComments === 'function') {
            window.TTNews.refreshComments();
          }
        }
      } catch (err) {}
      return;
    }
    const news2ItemId = String(window.__news2CommentItemId || '').trim();
    if (news2ItemId && txt) {
      try {
        const fd = new FormData();
        fd.append('action', 'comment');
        fd.append('item_id', news2ItemId);
        fd.append('text', txt);
        const r = await fetch('news2_api.php', { method:'POST', body: fd, cache:'no-store' });
        const data = await r.json();
        if (data && data.ok) {
          $text.value = '';
          setReply(0, '');
          if (window.TTNews2 && typeof window.TTNews2.refreshComments === 'function') {
            window.TTNews2.refreshComments();
          }
        }
      } catch (err) {}
      return;
    }
    const pid = Number($postId.value||0);
    const parent = Number($parentId.value||0);
    if(!pid || !txt) return;

    try{
      const fd = new FormData();
      fd.append('ajax','comment');
      fd.append('post_id', String(pid));
      fd.append('parent_id', String(parent));
      fd.append('comment_text', txt);

      const r = await fetch('feed_api.php', { method:'POST', body: fd, cache:'no-store' });
      const data = await r.json();
      if(data && data.ok){
        $text.value = '';
        setReply(0,'');
        if(window.TTComments && typeof window.TTComments.refreshCurrent === 'function'){
          window.TTComments.refreshCurrent();
        }
      }
    }catch(err){}
  });

  // Public API for feed.php
  window.TTComments = window.TTComments || {};
  window.TTComments.render = render;
  window.TTComments.setFocusComment = function(commentId){
    focusCommentId = Number(commentId || 0);
  };
  window.TTComments.clearFocusComment = function(){
    focusCommentId = 0;
  };

  window.TTComments.setPost = function(postId, comments, open){
    window.__newsCommentItemId = '';
    window.__news2CommentItemId = '';
    postId = Number(postId || 0);
    const postChanged = postId !== currentCommentsPostId;
    if(postId !== currentCommentsPostId){
      currentCommentsPostId = postId;
    }
    if((postChanged || open !== false) && focusCommentId <= 0){
      collapseRepliesByDefault(comments);
    } else if(postChanged && focusCommentId > 0){
      collapsedReplyIds.clear();
    }
    $postId.value = String(postId||0);
    setReply(0,'');
    render(comments || []);
    if(open !== false) openPanel();
  };

  window.TTComments.close = function(){ closeCommentsPanel(); };
  window.TTComments.open  = function(){ openCommentsPanel(); };
  window.TTComments.isOpen = function(){
    syncCommentsPanelState();
    return commentsPanelOpen;
  };
  window.TTComments.getPostId = function(){
    return Number(currentCommentsPostId || 0);
  };
  window.TTComments.openForPost = function(postId, comments, opts){
    openCommentsForPost(postId, Array.isArray(comments) ? comments : null, opts || {});
  };
  window.TTComments.toggle = function(postId, comments){
    postId = Number(postId || 0);
    if(commentsDoorDomOpen() && postId > 0 && postId === Number(currentCommentsPostId)){
      closeCommentsPanel();
      return false;
    }
    if(postId > 0){
      openCommentsForPost(postId, Array.isArray(comments) ? comments : null, {});
      return true;
    }
    if(commentsDoorDomOpen()){
      closeCommentsPanel();
      return false;
    }
    openCommentsPanel();
    return true;
  };

  document.addEventListener('click', function(e){
    var trigger = e.target && e.target.closest
      ? e.target.closest('.js-open-comments-door, .js-open-profile-comments-door, #profilePostsFeed .mf-comment, .mf-feed .mf-comment, .mf-card .mf-comment, #commentCountLink, #commentCountLinkV, #btnViewComments, #btnFooterComment, #btnFooterViewComments, .ig-image-overlay-btn[data-act="comment"]')
      : null;
    if(!trigger) return;
    e.preventDefault();
    e.stopPropagation();
    var postId = postIdFromCommentTrigger(trigger);
    if(typeof window.openFeedCommentsTray === 'function'){
      window.openFeedCommentsTray(postId);
      return;
    }
    if(typeof window.openProfileCommentsTray === 'function'){
      window.openProfileCommentsTray(postId);
      return;
    }
    if(!postId) return;
    openCommentsForPost(postId, null, {});
  }, true);

  document.addEventListener('keydown', function(e){
    if(e.key === 'Escape' && commentsDoorDomOpen()) closeCommentsPanel();
  });

  if(window.MSBReactions){
    window.MSBReactions.bindLikePicker('.tt-reactbtn', async function(btn, reaction){
      const cid = Number(btn.getAttribute('data-heart')||0);
      const pid = Number($postId.value||0);
      if(!pid || !cid || !reaction) return;
      if(String(btn.getAttribute('data-reaction') || '') === String(reaction)) return;
      try{
        const fd = new FormData();
        fd.append('ajax','comment_like');
        fd.append('post_id', String(pid));
        fd.append('comment_id', String(cid));
        fd.append('reaction', String(reaction));
        const r = await fetch('feed_api.php', { method:'POST', body: fd, cache:'no-store' });
        const data = await r.json();
        if(data && data.ok && window.TTComments && typeof window.TTComments.refreshCurrent === 'function'){
          window.TTComments.refreshCurrent();
        }
      }catch(err){}
    });
  }

  // ============================
  // Read More API (LEFTBAR)
  // ============================
  const $rmWrap = document.getElementById('tt-readmore-wrap');
  const $rmClose = document.getElementById('ttRmClose');
  const $rmAvatar = document.getElementById('ttRmAvatar');
  const $rmTitle = document.getElementById('ttRmTitle');
  const $rmSub = document.getElementById('ttRmSub');
  const $rmBody = document.getElementById('ttRmBody');
  var _rmLastKey = '';

  function readMoreDoorDomOpen(){
    return !!($rmWrap && $rmWrap.classList.contains('is-open'));
  }

  function closeOtherPanelsForReadMore(){
    try {
      if(window.TTComments && typeof window.TTComments.close === 'function') window.TTComments.close();
    } catch(e){}
    try {
      if(window.TTMenu && typeof window.TTMenu.close === 'function') window.TTMenu.close();
    } catch(e){}
    if(window.TTProfile && typeof window.TTProfile.close === 'function') window.TTProfile.close();
    if(window.TTMessages && typeof window.TTMessages.close === 'function') window.TTMessages.close();
    if(window.TTNotifications && typeof window.TTNotifications.close === 'function') window.TTNotifications.close();
    if(window.TTFriendRequests && typeof window.TTFriendRequests.close === 'function') window.TTFriendRequests.close();
    if(window.TTLive && typeof window.TTLive.close === 'function') window.TTLive.close();
    try {
      if(window.TTLiveRight && typeof window.TTLiveRight.close === 'function') window.TTLiveRight.close();
    } catch(e){}
  }

  function closeReadMorePanel(){
    if(!$rmWrap || !readMoreDoorDomOpen()) return;
    $rmWrap.classList.remove('is-open');
    $rmWrap.setAttribute('aria-hidden', 'true');
    _rmLastKey = '';
    if(!commentsDoorDomOpen()){
      document.body.classList.remove('public-leftbar-open', 'profile-leftbar-open');
    }
  }

  function openReadMorePanel(){
    if(!$rmWrap || readMoreDoorDomOpen()) return;
    closeOtherPanelsForReadMore();
    $rmWrap.classList.add('is-open');
    $rmWrap.setAttribute('aria-hidden', 'false');
    document.body.classList.add('public-leftbar-open');
    if(document.body.classList.contains('profile-page')){
      document.body.classList.add('profile-leftbar-open');
    }
  }

  $rmClose?.addEventListener('click', function(e){
    e.preventDefault();
    e.stopPropagation();
    closeReadMorePanel();
  });


  function ttRmEsc(s){
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }
  function ttNormalizeReadMorePlain(text){
    let src = String(text == null ? '' : text).replace(/\[\[layout:[a-z0-9_]+\]\]/ig, '');
    src = src.replace(/<\/p>\s*<p[^>]*>/ig, '\n\n');
    src = src.replace(/<br\s*\/?>/ig, '\n');
    src = src.replace(/<[^>]+>/g, '');
    src = src.replace(/\r\n?/g, '\n').replace(/[ \t]+\n/g, '\n').replace(/\n[ \t]+/g, '\n');
    src = src.replace(/\n{3,}/g, '\n\n');
    return src.trim();
  }
  function ttFormatRichText(text){
    const src = ttNormalizeReadMorePlain(text);
    if (!src) return '';
    const lines = src.split('\n');
    const out = [];
    let para = [];
    let listStack = [];
    function escLine(s){ return ttRmEsc(s).replace(/  /g, ' &nbsp;'); }
    function listInfo(line){
      const raw = String(line || '');
      const bullet = raw.match(/^(\s*)([-*•◦▪‣])\s+(.*)$/);
      if (bullet) return { type:'ul', indent: Math.floor((bullet[1] || '').replace(/\t/g, '    ').length / 2), text: bullet[3] || '' };
      const ordered = raw.match(/^(\s*)((?:\d+|[A-Za-z]|[ivxlcdmIVXLCDM]+)[\.)])\s+(.*)$/);
      if (ordered) return { type:'ol', indent: Math.floor((ordered[1] || '').replace(/\t/g, '    ').length / 2), text: ordered[3] || '' };
      return null;
    }
    function flushPara(){ if (!para.length) return; out.push('<p class="tt-rich-p">' + para.map(escLine).join('<br>') + '</p>'); para = []; }
    function closeLists(toLevel){ while (listStack.length > toLevel) { out.push('</li></' + listStack.pop() + '>'); } }
    function openList(type){ out.push('<' + type + ' class="tt-rich-list ' + (type === 'ol' ? 'is-ordered' : 'is-bullet') + '"><li class="tt-rich-li">'); listStack.push(type); }
    lines.forEach(function(line){
      const raw = String(line || '');
      const trimmed = raw.trim();
      const info = listInfo(raw);
      if (!trimmed) { flushPara(); closeLists(0); return; }
      if (info) {
        flushPara();
        const targetLevel = Math.max(0, info.indent + 1);
        while (listStack.length < targetLevel) openList(info.type);
        while (listStack.length > targetLevel) out.push('</li></' + listStack.pop() + '>');
        if (listStack.length && listStack[listStack.length - 1] !== info.type) { out.push('</li></' + listStack.pop() + '>'); openList(info.type); }
        else if (listStack.length) out.push('</li><li class="tt-rich-li">');
        out.push('<span class="tt-rich-line">' + escLine(info.text) + '</span>');
      } else {
        if (listStack.length) closeLists(0);
        para.push(raw);
      }
    });
    flushPara();
    closeLists(0);
    return '<div class="tt-richtext">' + out.join('') + '</div>';
  }
  window.TTRichText = window.TTRichText || { formatHtml: ttFormatRichText, normalizePlain: ttNormalizeReadMorePlain };

  window.TTReadMore = window.TTReadMore || {};

  function readMoreKey(payload){
    payload = payload || {};
    return [
      String(payload.title || ''),
      String(payload.author || ''),
      String(payload.date || ''),
      String(payload.body || '')
    ].join('\u0001');
  }

  window.TTReadMore.open = function(payload){
    payload = payload || {};
    closeOtherPanelsForReadMore();
    openReadMorePanel();

    const author = String(payload.author || '').trim();
    const titleRaw = String(payload.title || '').trim();
    const displayTitle = (titleRaw && titleRaw.toLowerCase() !== 'post') ? titleRaw : author;
    const avatarUrl = String(payload.avatarUrl || payload.avatar_url || '').trim();
    const avatarText = String(payload.avatarText || 'P').slice(0, 2).toUpperCase();
    const avatarBg = payload.avatarBg || '#111';

    if($rmAvatar){
      if(avatarUrl){
        $rmAvatar.innerHTML = '<img src="' + ttRmEsc(avatarUrl) + '" alt="' + ttRmEsc(author || 'Profile') + '">';
        $rmAvatar.style.background = 'transparent';
        $rmAvatar.classList.add('has-photo');
      } else {
        $rmAvatar.innerHTML = '';
        $rmAvatar.textContent = avatarText;
        $rmAvatar.style.background = avatarBg;
        $rmAvatar.classList.remove('has-photo');
      }
    }
    if($rmTitle){
      if(displayTitle){
        $rmTitle.textContent = displayTitle;
        $rmTitle.style.display = '';
      } else {
        $rmTitle.textContent = '';
        $rmTitle.style.display = 'none';
      }
    }
    if($rmSub){
      const date = String(payload.date || '').trim();
      $rmSub.textContent = date;
      $rmSub.style.display = date ? '' : 'none';
    }
    if($rmBody) $rmBody.innerHTML = ttFormatRichText(String(payload.body || ''));
    _rmLastKey = readMoreKey(payload);
  };
  window.TTReadMore.isOpen = function(){
    return readMoreDoorDomOpen();
  };
  window.TTReadMore.toggle = function(payload){
    payload = payload || {};
    var key = readMoreKey(payload);
    if(readMoreDoorDomOpen() && key && key === _rmLastKey){
      closeReadMorePanel();
      return false;
    }
    window.TTReadMore.open(payload);
    return true;
  };
  window.TTReadMore.close = function(){
    closeReadMorePanel();
  };

  function readMorePayloadFromTrigger(trigger){
    if(!trigger) return null;
    var card = trigger.closest('.mf-card, .public-post-card, article.post');
    if(!card) return null;
    var bodyHost = trigger.closest('.mf-body, .mf-reel-body, .mf-video-body');
    var body = bodyHost ? String(bodyHost.getAttribute('data-full') || '').trim() : '';
    if(!body) body = String(card.getAttribute('data-full-desc') || '').trim();
    if(!body) return null;
    return {
      title: String(card.getAttribute('data-title') || 'Post'),
      author: String(card.getAttribute('data-author') || ''),
      date: String(card.getAttribute('data-date') || ''),
      avatarText: String(card.getAttribute('data-avatar-text') || 'P'),
      avatarBg: '#111827',
      avatarUrl: String(card.getAttribute('data-avatar-url') || ''),
      body: body
    };
  }

  document.addEventListener('click', function(e){
    var trigger = e.target && e.target.closest
      ? e.target.closest('.js-open-readmore-door, .js-open-readmore, .mf-readmore, #pvCapReadMore, .ig-cap-readmore, #pvFooterReadMore, #pvInlineReadMore, #btnReadMore, #postList .pl-readmore')
      : null;
    if(!trigger) return;
    e.preventDefault();
    e.stopPropagation();
    if(typeof window.openFeedReadMoreTray === 'function'){
      window.openFeedReadMoreTray(trigger);
      return;
    }
    if(typeof window.openProfileReadMoreTray === 'function'){
      window.openProfileReadMoreTray(trigger);
      return;
    }
    var payload = readMorePayloadFromTrigger(trigger);
    if(payload){
      window.TTReadMore.toggle(payload);
    }
  }, true);

  document.addEventListener('keydown', function(e){
    if(e.key === 'Escape' && readMoreDoorDomOpen()) closeReadMorePanel();
  });

  document.addEventListener('click', function(e){
    var target = e.target;
    if(!target || !target.closest) return;

    var menuWrap = document.getElementById('tt-menu-wrap');
    var commentsWrap = document.getElementById('tt-comments-wrap');
    var readWrap = document.getElementById('tt-readmore-wrap');
    var profileWrap = document.getElementById('tt-profile-wrap');
    var messagesWrap = document.getElementById('tt-messages-wrap');
    var notificationsWrap = document.getElementById('tt-notifications-wrap');
    var friendRequestsWrap = document.getElementById('tt-friend-requests-wrap');
    var liveWrap = document.getElementById('tt-live-wrap');
    var menuOpen = !!(menuWrap && menuWrap.classList.contains('is-open'));
    var commentsOpen = !!(commentsWrap && commentsWrap.classList.contains('is-open'));
    var readOpen = !!(readWrap && readWrap.classList.contains('is-open'));
    var profileOpen = !!(profileWrap && profileWrap.classList.contains('is-open'));
    var messagesOpen = !!(messagesWrap && messagesWrap.classList.contains('is-open'));
    var notificationsOpen = !!(notificationsWrap && notificationsWrap.classList.contains('is-open'));
    var friendRequestsOpen = !!(friendRequestsWrap && friendRequestsWrap.classList.contains('is-open'));
    var liveOpen = !!(liveWrap && liveWrap.classList.contains('is-open'));
    if(!menuOpen && !commentsOpen && !readOpen && !profileOpen && !messagesOpen && !notificationsOpen && !friendRequestsOpen && !liveOpen) return;

    if(target.closest('#tt-menu-wrap, #tt-comments-wrap, #tt-readmore-wrap, #tt-profile-wrap, #tt-messages-wrap, #tt-notifications-wrap, #tt-friend-requests-wrap, #tt-live-wrap, #ttMenuClose, #ttCommentsClose, #ttRmClose, #ttProfileClose, #ttMessagesClose, #ttNotificationsClose, #ttFriendRequestsClose')) return;
    if(target.closest('.js-open-menu-door, .ig-story-item, .js-open-comments, .js-open-comments-door, .js-open-readmore, .js-open-readmore-door, .js-open-profile-door, .js-open-messages-door, .js-open-notifications-door, .js-open-friend-requests-door, .js-open-live-door, .js-open-live-studio-browse, .js-open-live-software-browse, .feed-ig-avatar')) return;
    if(target.closest('#tt-stories-wrap, #tt-live-right-wrap, #ttStoriesClose')) return;
    if(target.closest('.mf-comment, .js-open-profile-comments-door, .mf-readmore, #commentCountLink, #commentCountLinkV, #btnViewComments, #btnFooterComment, #btnFooterViewComments, .ig-image-overlay-btn[data-act="comment"], #pvCapReadMore, .ig-cap-readmore, #pvFooterReadMore, #pvInlineReadMore, #btnReadMore, #btnOpenCommentsDrawer, #postList .pl-readmore')) return;

    if(menuOpen && window.TTMenu && typeof window.TTMenu.close === 'function') window.TTMenu.close();
    if(commentsOpen){
      if(window.TTComments && typeof window.TTComments.close === 'function') window.TTComments.close();
      else if(commentsWrap) commentsWrap.classList.remove('is-open');
    }
    if(readOpen){
      if(window.TTReadMore && typeof window.TTReadMore.close === 'function') window.TTReadMore.close();
      else if(readWrap) readWrap.classList.remove('is-open');
    }
    if(profileOpen){
      if(window.TTProfile && typeof window.TTProfile.close === 'function') window.TTProfile.close();
      else if(profileWrap) profileWrap.classList.remove('is-open');
    }
    if(messagesOpen){
      if(window.TTMessages && typeof window.TTMessages.close === 'function') window.TTMessages.close();
      else if(messagesWrap) messagesWrap.classList.remove('is-open');
    }
    if(notificationsOpen){
      if(window.TTNotifications && typeof window.TTNotifications.close === 'function') window.TTNotifications.close();
      else if(notificationsWrap) notificationsWrap.classList.remove('is-open');
    }
    if(friendRequestsOpen){
      if(window.TTFriendRequests && typeof window.TTFriendRequests.close === 'function') window.TTFriendRequests.close();
      else if(friendRequestsWrap) friendRequestsWrap.classList.remove('is-open');
    }
    if(liveOpen){
      if(window.TTLive && typeof window.TTLive.close === 'function') window.TTLive.close();
      else if(liveWrap) liveWrap.classList.remove('is-open');
    }
  });

})();
</script>
