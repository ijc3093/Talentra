<?php
if (!empty($GLOBALS['msb_notifications_door_included'])) {
  return;
}
$GLOBALS['msb_notifications_door_included'] = true;

$__notiItems = is_array($headerNotifications ?? null) ? $headerNotifications : [];
$__notiUnread = (int)($headerNotificationUnread ?? 0);
$__notiStandalone = !empty($msbNotificationsDoorStandalone);
?>
<style>
#ttLeftbarOverlays .tt-notifications-wrap,
.msb-notifications-door-host .tt-notifications-wrap{
  position:absolute !important;
  inset:0 !important;
  background:var(--tt-panel-bg, var(--msb-palette-bg, #ffffff));
  z-index:997 !important;
  display:flex !important;
  flex-direction:column !important;
  overflow:hidden !important;
  min-height:0 !important;
  box-shadow:8px 0 24px rgba(0,0,0,.12);
  transform:translateX(-105%);
  opacity:0;
  pointer-events:none;
  transition:transform .18s ease, opacity .18s ease;
}
#ttLeftbarOverlays .tt-notifications-wrap.is-open,
.msb-notifications-door-host .tt-notifications-wrap.is-open{
  transform:translateX(0);
  opacity:1;
  pointer-events:auto;
}
.msb-notifications-door-host{
  position:fixed;
  left:0;
  top:0;
  width:min(300px, 88vw);
  height:100vh;
  z-index:1295;
  pointer-events:none;
}
.msb-notifications-door-host .tt-notifications-wrap{
  position:absolute !important;
  inset:0 !important;
  z-index:1 !important;
}
.tt-notifications-head{
  flex:0 0 auto !important;
  display:flex;
  align-items:center;
  justify-content:space-between;
  padding:12px 14px 10px;
  border-bottom:1px solid var(--tt-panel-border, var(--msb-palette-border, rgba(15,23,42,.08)));
  background:var(--tt-panel-bg, var(--msb-palette-bg, #ffffff));
  position:sticky !important;
  top:0 !important;
  z-index:30 !important;
}
.tt-notifications-head .title{
  font-weight:700;
  font-size:16px;
  line-height:1.2;
  color:var(--tt-text, var(--msb-palette-text, #101828));
}
.msb-notifications-door-host .tt-close,
#ttLeftbarOverlays .tt-notifications-wrap .tt-close{
  width:28px;
  height:28px;
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
  box-shadow:none;
}
.msb-notifications-door-host .tt-close i,
#ttLeftbarOverlays .tt-notifications-wrap .tt-close i{ font-size:14px; }
.msb-notifications-door-host .tt-close:hover,
#ttLeftbarOverlays .tt-notifications-wrap .tt-close:hover{
  background:var(--tt-control-hover, var(--msb-palette-nav-hover, #e9edf3));
}
.tt-notifications-body{
  flex:1 1 auto !important;
  min-height:0 !important;
  overflow:hidden !important;
  padding:10px 12px 12px;
  -webkit-overflow-scrolling:touch;
  overscroll-behavior:contain;
  background:var(--tt-panel-bg, var(--msb-palette-bg, #ffffff));
  display:flex !important;
  flex-direction:column !important;
}
.tt-notifications-summary{
  padding:2px 4px 8px;
  flex:0 0 auto;
}
.tt-notifications-summary-main{
  font-size:13px;
  font-weight:700;
  line-height:1.3;
  color:var(--tt-text, var(--msb-palette-text, #101828));
}
.tt-notifications-summary-sub{
  margin-top:2px;
  font-size:12px;
  line-height:1.3;
  color:var(--tt-muted, var(--msb-palette-text-muted, #667085));
}
.tt-noti-door-tabs{
  display:flex;
  gap:6px;
  padding:0 4px 8px;
  flex:0 0 auto;
}
.tt-noti-door-tab{
  border:0;
  background:transparent;
  color:var(--tt-muted, var(--msb-palette-text-muted, #667085));
  font-size:12px;
  font-weight:600;
  padding:5px 10px;
  border-radius:999px;
  cursor:pointer;
  line-height:1.2;
}
.tt-noti-door-tab.is-active{
  background:var(--tt-accent-soft, rgba(37,99,235,.1));
  color:var(--tt-text, var(--msb-palette-text, #101828));
}
.tt-noti-door-tab:hover{
  background:var(--tt-control-hover, var(--msb-palette-nav-hover, #e9edf3));
}
.tt-notifications-divider{
  height:1px;
  background:var(--tt-panel-border, var(--msb-palette-border, rgba(15,23,42,.08)));
  margin:0 0 6px;
  flex:0 0 auto;
}
.tt-notifications-list{
  flex:1 1 auto !important;
  min-height:0 !important;
  height:auto !important;
  overflow-x:hidden !important;
  overflow-y:auto !important;
  margin:0 -4px;
  padding:4px 4px 8px;
  -webkit-overflow-scrolling:touch;
}
.tt-notifications-list .dropdown-bestnoti-empty{
  padding:12px 6px;
  font-size:12px;
  line-height:1.4;
  color:var(--tt-muted, var(--msb-palette-text-muted, #667085));
}
.tt-notifications-list .dropdown-bestnoti-item{
  display:flex !important;
  align-items:flex-start;
  gap:8px;
  padding:8px 6px;
  border-radius:8px;
  text-decoration:none;
  color:var(--tt-text, var(--msb-palette-text, #101828)) !important;
  transition:background .15s ease;
  visibility:visible !important;
  opacity:1 !important;
}
.tt-notifications-list .bestnoti-avatar{
  width:32px;
  height:32px;
  flex:0 0 32px;
  border-radius:50%;
  display:inline-flex;
  align-items:center;
  justify-content:center;
  font-size:11px;
  font-weight:700;
  color:#fff !important;
  background:linear-gradient(135deg, #2563eb, #0f172a);
}
.tt-notifications-list .bestnoti-mid{
  min-width:0;
  flex:1 1 auto;
}
.tt-notifications-list .bestnoti-text{
  font-size:13px;
  line-height:1.3;
  font-weight:400;
  color:var(--tt-text, var(--msb-palette-text, #101828)) !important;
}
.tt-notifications-list .bestnoti-text strong{
  font-weight:700;
}
.tt-notifications-list .bestnoti-time{
  margin-top:2px;
  font-size:11px;
  color:var(--tt-muted, var(--msb-palette-text-muted, #667085));
}
.tt-notifications-list .dropdown-bestnoti-item + .dropdown-bestnoti-item{
  border-top:none;
  margin-top:1px;
}
.tt-notifications-list .dropdown-bestnoti-item:hover{
  background:var(--tt-control-hover, var(--msb-palette-nav-hover, #e9edf3));
  color:var(--tt-text, var(--msb-palette-text, #101828));
}
.tt-notifications-list .dropdown-bestnoti-item.is-unread{
  background:var(--tt-accent-soft, rgba(37,99,235,.08));
}
.tt-notifications-footer{
  list-style:none;
  margin:8px 0 0;
  padding:0;
  flex:0 0 auto;
}
.tt-notifications-footer li + li{
  margin-top:1px;
}
.tt-notifications-footer a{
  display:flex;
  align-items:center;
  gap:8px;
  min-height:34px;
  padding:8px 10px;
  border-radius:8px;
  color:var(--tt-text, var(--msb-palette-text, #101828));
  font-size:13px;
  font-weight:500;
  text-decoration:none;
  transition:background .15s ease;
}
.tt-notifications-footer a:hover{
  background:var(--tt-control-hover, var(--msb-palette-nav-hover, #e9edf3));
  text-decoration:none;
}
.tt-notifications-footer a .icon{
  width:14px;
  min-width:14px;
  text-align:center;
  font-size:13px;
  color:var(--tt-muted, var(--msb-palette-text-muted, #667085));
}
body.msb-notifications-door-open{ overflow:hidden !important; }
body.msb-notifications-door-open .msb-notifications-door-host{ pointer-events:auto; }
.msb-notifications-door-backdrop{
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
body.msb-notifications-door-open .msb-notifications-door-backdrop{
  opacity:1;
  visibility:visible;
  pointer-events:auto;
  z-index:1289;
}
</style>

<?php if ($__notiStandalone): ?>
<button type="button" class="msb-notifications-door-backdrop" id="msbNotificationsDoorBackdrop" aria-label="Close notifications"></button>
<?php endif; ?>

<div class="tt-notifications-wrap" id="tt-notifications-wrap" aria-hidden="true">
  <div class="tt-notifications-head">
    <div>
      <span class="title">Notifications</span>
    </div>
    <button class="tt-close" type="button" id="ttNotificationsClose" title="Close">
      <i class="icon ion-close"></i>
    </button>
  </div>
  <div class="tt-notifications-body">
    <?php
      if (function_exists('render_header_notification_panel_inner')) {
        echo render_header_notification_panel_inner($__notiItems, $__notiUnread, 'headerNotificationSub', 'headerNotificationList', 'headerNotificationMarkAll');
      }
    ?>
  </div>
</div>

<script>
(function(){
  var $notiWrap = document.getElementById('tt-notifications-wrap');
  var $notiClose = document.getElementById('ttNotificationsClose');
  var standaloneHost = document.getElementById('msbNotificationsDoorHost');
  var standaloneBackdrop = document.getElementById('msbNotificationsDoorBackdrop');
  var isStandalone = !!standaloneHost;

  function closeOtherPanels() {
    try {
      var commentsWrap = document.getElementById('tt-comments-wrap');
      if(commentsWrap) commentsWrap.classList.remove('is-open');
    } catch(e){}
    try {
      if(window.TTMenu && typeof window.TTMenu.close === 'function') window.TTMenu.close();
    } catch(e){}
    try {
      var rmWrap = document.getElementById('tt-readmore-wrap');
      if(rmWrap) rmWrap.classList.remove('is-open');
    } catch(e){}
    if(window.TTProfile && typeof window.TTProfile.close === 'function') window.TTProfile.close();
    if(window.TTMessages && typeof window.TTMessages.close === 'function') window.TTMessages.close();
    if(window.TTFriendRequests && typeof window.TTFriendRequests.close === 'function') window.TTFriendRequests.close();
    if(window.TTLive && typeof window.TTLive.close === 'function') window.TTLive.close();
  }

  function closeNotificationsPanel(){
    if(!$notiWrap) return;
    $notiWrap.classList.remove('is-open');
    $notiWrap.setAttribute('aria-hidden', 'true');
    if(isStandalone) document.body.classList.remove('msb-notifications-door-open');
    else document.body.classList.remove('public-leftbar-open');
  }

  function openNotificationsPanel(){
    if(!$notiWrap) return;
    closeOtherPanels();
    $notiWrap.classList.add('is-open');
    $notiWrap.setAttribute('aria-hidden', 'false');
    if(isStandalone) document.body.classList.add('msb-notifications-door-open');
    else document.body.classList.add('public-leftbar-open');
    // Pull latest alerts and force-paint the list (badge alone is not enough).
    try {
      if (window.MSBNotificationsUI && typeof window.MSBNotificationsUI.refresh === 'function') {
        var refreshed = window.MSBNotificationsUI.refresh();
        if (refreshed && typeof refreshed.then === 'function') {
          refreshed.then(function(){
            try {
              if (typeof window.MSBNotificationsUI.paint === 'function') {
                window.MSBNotificationsUI.paint();
              }
            } catch (ePaint) {}
          }).catch(function(){});
        } else if (typeof window.MSBNotificationsUI.paint === 'function') {
          window.MSBNotificationsUI.paint();
        }
      }
    } catch (err) {}
  }

  function toggleNotificationsPanel(){
    if($notiWrap && $notiWrap.classList.contains('is-open')) closeNotificationsPanel();
    else openNotificationsPanel();
  }

  $notiClose?.addEventListener('click', function(e){
    e.preventDefault();
    e.stopPropagation();
    closeNotificationsPanel();
  });

  standaloneBackdrop?.addEventListener('click', function(e){
    e.preventDefault();
    e.stopPropagation();
    closeNotificationsPanel();
  });

  document.addEventListener('click', function(e){
    var btn = e.target && e.target.closest ? e.target.closest('.js-open-notifications-door') : null;
    if(!btn) return;
    e.preventDefault();
    e.stopPropagation();
    toggleNotificationsPanel();
  }, true);

  document.addEventListener('keydown', function(e){
    if(e.key === 'Escape' && $notiWrap && $notiWrap.classList.contains('is-open')){
      closeNotificationsPanel();
    }
  });

  window.TTNotifications = window.TTNotifications || {};
  window.TTNotifications.open = openNotificationsPanel;
  window.TTNotifications.close = closeNotificationsPanel;
  window.TTNotifications.toggle = toggleNotificationsPanel;
})();
</script>
