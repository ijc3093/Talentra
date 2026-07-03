<?php
if (!empty($GLOBALS['msb_friend_requests_door_included'])) {
  return;
}
$GLOBALS['msb_friend_requests_door_included'] = true;

if (!isset($meId)) {
  $meId = (int)($_SESSION['user_id'] ?? 0);
}
$__frPending = (int)($pendingFriendRequestCount ?? 0);
$__frStandalone = !empty($msbFriendRequestsDoorStandalone);
?>
<style>
#ttLeftbarOverlays .tt-friend-requests-wrap,
.msb-friend-requests-door-host .tt-friend-requests-wrap{
  position:absolute !important;
  inset:0 !important;
  background:var(--tt-panel-bg, var(--msb-palette-bg, #ffffff));
  z-index:996 !important;
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
#ttLeftbarOverlays .tt-friend-requests-wrap.is-open,
.msb-friend-requests-door-host .tt-friend-requests-wrap.is-open{
  transform:translateX(0);
  opacity:1;
  pointer-events:auto;
}
.msb-friend-requests-door-host{
  position:fixed;
  left:0;
  top:0;
  width:min(360px, 88vw);
  height:100vh;
  z-index:1290;
  pointer-events:none;
}
.msb-friend-requests-door-host .tt-friend-requests-wrap{
  position:absolute !important;
  inset:0 !important;
}
.tt-friend-requests-head{
  flex:0 0 auto !important;
  display:flex;
  align-items:center;
  justify-content:space-between;
  padding:22px 24px 16px;
  border-bottom:1px solid var(--tt-panel-border, var(--msb-palette-border, rgba(15,23,42,.08)));
  background:var(--tt-panel-bg, var(--msb-palette-bg, #ffffff));
  position:sticky !important;
  top:0 !important;
  z-index:30 !important;
}
.tt-friend-requests-head .title{
  font-weight:800;
  font-size:20px;
  line-height:1.1;
  color:var(--tt-text, var(--msb-palette-text, #101828));
}
.msb-friend-requests-door-host .tt-close,
#ttLeftbarOverlays .tt-friend-requests-wrap .tt-close{
  width:34px;
  height:34px;
  border:0;
  border-radius:50%;
  background:transparent;
  color:var(--tt-text, var(--msb-palette-text, #101828));
  display:inline-flex;
  align-items:center;
  justify-content:center;
  cursor:pointer;
  padding:0;
  line-height:1;
  flex:0 0 auto;
}
.msb-friend-requests-door-host .tt-close i,
#ttLeftbarOverlays .tt-friend-requests-wrap .tt-close i{ font-size:20px; }
.msb-friend-requests-door-host .tt-close:hover,
#ttLeftbarOverlays .tt-friend-requests-wrap .tt-close:hover{
  background:var(--tt-control-hover, var(--msb-palette-nav-hover, #e9edf3));
}
.tt-friend-requests-body{
  flex:1 1 auto !important;
  min-height:0 !important;
  overflow-y:auto !important;
  overflow-x:hidden !important;
  padding:14px 16px 20px;
  -webkit-overflow-scrolling:touch;
  overscroll-behavior:contain;
  background:var(--tt-panel-bg, var(--msb-palette-bg, #ffffff));
  display:flex;
  flex-direction:column;
}
.tt-friend-requests-summary{
  padding:2px 4px 10px;
}
.tt-friend-requests-summary-main{
  font-size:15px;
  font-weight:700;
  line-height:1.35;
  color:var(--tt-text, var(--msb-palette-text, #101828));
}
.tt-friend-requests-summary-sub{
  margin-top:5px;
  font-size:13px;
  line-height:1.35;
  color:var(--tt-muted, var(--msb-palette-text-muted, #667085));
}
.tt-friend-requests-divider{
  height:1px;
  background:var(--tt-panel-border, var(--msb-palette-border, rgba(15,23,42,.08)));
  margin:0 0 8px;
  flex:0 0 auto;
}
.tt-friend-requests-list{
  flex:1 1 auto;
  min-height:0;
  overflow:auto;
  margin:0 -4px;
  padding:0 4px;
}
.tt-fr-empty{
  padding:16px 8px;
  text-align:center;
  font-size:13px;
  line-height:1.45;
  color:var(--tt-muted, var(--msb-palette-text-muted, #667085));
}
.tt-fr-row{
  display:flex;
  align-items:flex-start;
  gap:10px;
  padding:10px 8px;
  border-radius:10px;
  transition:background .15s ease;
}
.tt-fr-row + .tt-fr-row{ margin-top:2px; }
.tt-fr-avatar{
  width:40px;
  height:40px;
  border-radius:50%;
  overflow:hidden;
  flex:0 0 auto;
  background:var(--tt-control-bg, var(--msb-palette-hover-bg, #f2f4f7));
}
.tt-fr-avatar img{
  width:100%;
  height:100%;
  object-fit:cover;
  display:block;
}
.tt-fr-main{ min-width:0; flex:1 1 auto; }
.tt-fr-topline{
  display:flex;
  align-items:baseline;
  gap:8px;
  flex-wrap:wrap;
}
.tt-fr-name{
  font-size:14px;
  font-weight:700;
  color:var(--tt-text, var(--msb-palette-text, #101828));
}
.tt-fr-time{
  font-size:12px;
  color:var(--tt-muted, var(--msb-palette-text-muted, #667085));
}
.tt-fr-sub{
  margin-top:2px;
  font-size:13px;
  line-height:1.35;
  color:var(--tt-muted, var(--msb-palette-text-muted, #667085));
  word-break:break-word;
}
.tt-fr-actions{
  display:flex;
  flex-direction:column;
  align-items:stretch;
  gap:6px;
  flex:0 0 auto;
  padding-top:2px;
}
.tt-fr-btn{
  min-width:76px;
  padding:7px 12px;
  border-radius:8px;
  border:0;
  font-size:13px;
  font-weight:700;
  line-height:1.2;
  cursor:pointer;
  transition:opacity .15s ease, transform .15s ease;
}
.tt-fr-btn:active{ transform:scale(.98); }
.tt-fr-accept{
  background:#0095f6;
  color:#fff;
}
.tt-fr-accept:hover{ opacity:.9; }
.tt-fr-decline{
  background:var(--tt-control-bg, var(--msb-palette-hover-bg, #f2f4f7));
  color:var(--tt-text, var(--msb-palette-text, #101828));
}
.tt-fr-decline:hover{ opacity:.88; }
.tt-friend-requests-footer{
  list-style:none;
  margin:12px 0 0;
  padding:0;
  flex:0 0 auto;
}
.tt-friend-requests-footer a{
  display:flex;
  align-items:center;
  gap:12px;
  min-height:44px;
  padding:10px 12px;
  border-radius:10px;
  color:var(--tt-text, var(--msb-palette-text, #101828));
  font-size:15px;
  font-weight:500;
  text-decoration:none;
  transition:background .15s ease;
}
.tt-friend-requests-footer a:hover{
  background:var(--tt-control-hover, var(--msb-palette-nav-hover, #e9edf3));
  text-decoration:none;
}
.tt-friend-requests-footer a .icon{
  width:20px;
  text-align:center;
  font-size:18px;
  color:var(--tt-muted, var(--msb-palette-text-muted, #667085));
}
body.msb-friend-requests-door-open{ overflow:hidden !important; }
body.msb-friend-requests-door-open .msb-friend-requests-door-host{ pointer-events:auto; }
.msb-friend-requests-door-backdrop{
  position:fixed;
  inset:0;
  z-index:-1;
  border:0;
  padding:0;
  margin:0;
  background:rgba(0,0,0,.42);
  opacity:0;
  visibility:hidden;
  pointer-events:none;
  transition:opacity .18s ease, visibility .18s ease;
}
body.msb-friend-requests-door-open .msb-friend-requests-door-backdrop{
  opacity:1;
  visibility:visible;
  pointer-events:auto;
  z-index:1289;
}
</style>

<?php if ($__frStandalone): ?>
<button type="button" class="msb-friend-requests-door-backdrop" id="msbFriendRequestsDoorBackdrop" aria-label="Close friend requests"></button>
<?php endif; ?>

<div class="tt-friend-requests-wrap" id="tt-friend-requests-wrap" aria-hidden="true">
  <div class="tt-friend-requests-head">
    <div>
      <span class="title">Friend Requests</span>
    </div>
    <button class="tt-close" type="button" id="ttFriendRequestsClose" title="Close">
      <i class="icon ion-close"></i>
    </button>
  </div>
  <div class="tt-friend-requests-body">
    <div class="tt-friend-requests-summary">
      <div class="tt-friend-requests-summary-main" id="ttFriendRequestsSummaryMain">
        <?php echo $__frPending > 0 ? h($__frPending . ' pending request' . ($__frPending === 1 ? '' : 's')) : 'No pending requests'; ?>
      </div>
      <div class="tt-friend-requests-summary-sub" id="ttFriendRequestsSummarySub">Accept or decline incoming connection requests.</div>
    </div>
    <div class="tt-friend-requests-divider"></div>
    <div class="tt-friend-requests-list" id="msbFriendRequestsList">
      <div class="tt-fr-empty">Loading friend requests…</div>
    </div>
    <div class="tt-friend-requests-divider"></div>
    <ul class="tt-friend-requests-footer">
      <li><a href="contact_requests.php"><i class="icon ion-person-add"></i> View All Requests</a></li>
    </ul>
  </div>
</div>

<script>
(function(){
  var $frWrap = document.getElementById('tt-friend-requests-wrap');
  var $frClose = document.getElementById('ttFriendRequestsClose');
  var listEl = document.getElementById('msbFriendRequestsList');
  var summaryMain = document.getElementById('ttFriendRequestsSummaryMain');
  var standaloneHost = document.getElementById('msbFriendRequestsDoorHost');
  var standaloneBackdrop = document.getElementById('msbFriendRequestsDoorBackdrop');
  var isStandalone = !!standaloneHost;
  var isOpen = false;

  function esc(s){
    return String(s == null ? '' : s).replace(/[&<>"']/g, function(m){
      return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[m];
    });
  }

  function avatarUrl(item){
    var params = [];
    var uid = Number(item.from_user_id || 0);
    var username = String(item.username || '').trim();
    var email = String(item.email || '').trim();
    var friendCode = String(item.friend_code || '').trim();
    var name = String(item.display_name || item.username || 'User').trim();
    if(uid > 0) params.push('u=' + encodeURIComponent(String(uid)));
    if(email) params.push('email=' + encodeURIComponent(email));
    if(friendCode) params.push('friend_code=' + encodeURIComponent(friendCode));
    if(username) params.push('username=' + encodeURIComponent(username));
    if(name) params.push('name=' + encodeURIComponent(name));
    params.push('s=72');
    return 'avatar.php?' + params.join('&');
  }

  function updateRailBadge(count){
    count = Number(count || 0);
    document.querySelectorAll('.js-open-friend-requests-door').forEach(function(link){
      var badge = link.querySelector('.feed-ig-badge');
      if(count > 0){
        if(!badge){
          badge = document.createElement('span');
          badge.className = 'feed-ig-badge';
          link.appendChild(badge);
        }
        badge.textContent = count > 99 ? '99+' : String(count);
        badge.style.display = '';
      } else if(badge){
        badge.remove();
      }
    });
  }

  function updateSummary(count){
    count = Number(count || 0);
    if(summaryMain){
      summaryMain.textContent = count > 0
        ? (count + ' pending request' + (count === 1 ? '' : 's'))
        : 'No pending requests';
    }
  }

  function renderItems(items){
    items = Array.isArray(items) ? items : [];
    updateRailBadge(items.length);
    updateSummary(items.length);
    if(!listEl) return;
    if(!items.length){
      listEl.innerHTML = '<div class="tt-fr-empty">No pending friend requests.</div>';
      return;
    }
    listEl.innerHTML = items.map(function(item){
      var id = Number(item.id || 0);
      var name = esc(item.display_name || item.username || 'User');
      var sub = esc(item.username || item.email || '');
      var time = esc(item.time_label || '');
      return '<div class="tt-fr-row" data-id="' + id + '">' +
        '<div class="tt-fr-avatar"><img src="' + esc(avatarUrl(item)) + '" alt="' + name + '"></div>' +
        '<div class="tt-fr-main">' +
          '<div class="tt-fr-topline">' +
            '<span class="tt-fr-name">' + name + '</span>' +
            (time ? '<span class="tt-fr-time">' + time + '</span>' : '') +
          '</div>' +
          (sub ? '<div class="tt-fr-sub">' + sub + '</div>' : '') +
        '</div>' +
        '<div class="tt-fr-actions">' +
          '<button type="button" class="tt-fr-btn tt-fr-accept" data-fr-action="accept" data-fr-id="' + id + '">Accept</button>' +
          '<button type="button" class="tt-fr-btn tt-fr-decline" data-fr-action="decline" data-fr-id="' + id + '">Decline</button>' +
        '</div>' +
      '</div>';
    }).join('');
  }

  function loadList(){
    if(listEl) listEl.innerHTML = '<div class="tt-fr-empty">Loading friend requests…</div>';
    return fetch('contact_requests.php?ajax=list', { credentials:'same-origin', cache:'no-store' })
      .then(function(res){ return res.json(); })
      .then(function(data){
        if(data && data.ok) renderItems(data.items || []);
        else if(listEl) listEl.innerHTML = '<div class="tt-fr-empty">Could not load friend requests.</div>';
      })
      .catch(function(){
        if(listEl) listEl.innerHTML = '<div class="tt-fr-empty">Could not load friend requests.</div>';
      });
  }

  function respond(id, action){
    id = Number(id || 0);
    action = String(action || '').trim();
    if(id <= 0 || !action) return;
    var fd = new FormData();
    fd.append('ajax', 'respond');
    fd.append('id', String(id));
    fd.append('action', action);
    fetch('contact_requests.php', { method:'POST', body:fd, credentials:'same-origin' })
      .then(function(res){ return res.json(); })
      .then(function(data){
        if(data && data.ok) loadList();
      });
  }

  function closeOtherPanels(){
    try {
      var commentsWrap = document.getElementById('tt-comments-wrap');
      if(commentsWrap) commentsWrap.classList.remove('is-open');
    } catch(e){}
    try {
      var menuWrap = document.getElementById('tt-menu-wrap');
      if(menuWrap) menuWrap.classList.remove('is-open');
    } catch(e){}
    try {
      var rmWrap = document.getElementById('tt-readmore-wrap');
      if(rmWrap) rmWrap.classList.remove('is-open');
    } catch(e){}
    if(window.TTProfile && typeof window.TTProfile.close === 'function') window.TTProfile.close();
    if(window.TTMessages && typeof window.TTMessages.close === 'function') window.TTMessages.close();
    if(window.TTNotifications && typeof window.TTNotifications.close === 'function') window.TTNotifications.close();
  }

  function closeFriendRequestsPanel(){
    if(!$frWrap || !isOpen) return;
    isOpen = false;
    $frWrap.classList.remove('is-open');
    $frWrap.setAttribute('aria-hidden', 'true');
    if(isStandalone) document.body.classList.remove('msb-friend-requests-door-open');
    else document.body.classList.remove('public-leftbar-open');
  }

  function openFriendRequestsPanel(){
    if(!$frWrap || isOpen) return;
    isOpen = true;
    closeOtherPanels();
    $frWrap.classList.add('is-open');
    $frWrap.setAttribute('aria-hidden', 'false');
    if(isStandalone) document.body.classList.add('msb-friend-requests-door-open');
    else document.body.classList.add('public-leftbar-open');
    loadList();
  }

  function toggleFriendRequestsPanel(){
    if($frWrap && $frWrap.classList.contains('is-open')) closeFriendRequestsPanel();
    else openFriendRequestsPanel();
  }

  $frClose?.addEventListener('click', function(e){
    e.preventDefault();
    e.stopPropagation();
    closeFriendRequestsPanel();
  });

  standaloneBackdrop?.addEventListener('click', function(e){
    e.preventDefault();
    e.stopPropagation();
    closeFriendRequestsPanel();
  });

  if(listEl){
    listEl.addEventListener('click', function(e){
      var btn = e.target && e.target.closest ? e.target.closest('[data-fr-action]') : null;
      if(!btn) return;
      e.preventDefault();
      respond(btn.getAttribute('data-fr-id'), btn.getAttribute('data-fr-action'));
    });
  }

  document.addEventListener('keydown', function(e){
    if(e.key === 'Escape' && isOpen) closeFriendRequestsPanel();
  });

  document.addEventListener('click', function(e){
    var trigger = e.target && e.target.closest ? e.target.closest('.js-open-friend-requests-door') : null;
    if(!trigger) return;
    e.preventDefault();
    e.stopPropagation();
    toggleFriendRequestsPanel();
  }, true);

  window.TTFriendRequests = {
    open: openFriendRequestsPanel,
    close: closeFriendRequestsPanel,
    toggle: toggleFriendRequestsPanel,
    refresh: loadList
  };
  window.MSBFriendRequestsSheet = window.TTFriendRequests;
})();
</script>
