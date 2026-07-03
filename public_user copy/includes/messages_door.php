<?php
if (!empty($GLOBALS['msb_messages_door_included'])) {
  return;
}
$GLOBALS['msb_messages_door_included'] = true;

$__msgThreads = is_array($namedChatThreads ?? null) ? $namedChatThreads : [];
$__msgUnknown = (int)($unknownUnread ?? 0);
$__msgTotal = (int)($totalUnread ?? 0);
$__msgStandalone = !empty($msbMessagesDoorStandalone);
?>
<style>
#ttLeftbarOverlays .tt-messages-wrap,
.msb-messages-door-host .tt-messages-wrap{
  position:absolute !important;
  inset:0 !important;
  background:var(--tt-panel-bg, var(--msb-palette-bg, #ffffff));
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
#ttLeftbarOverlays .tt-messages-wrap.is-open,
.msb-messages-door-host .tt-messages-wrap.is-open{
  transform:translateX(0);
  opacity:1;
  pointer-events:auto;
}
.msb-messages-door-host{
  position:fixed;
  left:0;
  top:0;
  width:min(360px, 88vw);
  height:100vh;
  z-index:1290;
  pointer-events:none;
}
.msb-messages-door-host .tt-messages-wrap{
  position:absolute !important;
  inset:0 !important;
}
.tt-messages-head{
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
.tt-messages-head .title{
  font-weight:800;
  font-size:20px;
  line-height:1.1;
  color:var(--tt-text, var(--msb-palette-text, #101828));
}
.msb-messages-door-host .tt-close,
#ttLeftbarOverlays .tt-messages-wrap .tt-close{
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
.msb-messages-door-host .tt-close i,
#ttLeftbarOverlays .tt-messages-wrap .tt-close i{ font-size:20px; }
.msb-messages-door-host .tt-close:hover,
#ttLeftbarOverlays .tt-messages-wrap .tt-close:hover{
  background:var(--tt-control-hover, var(--msb-palette-nav-hover, #e9edf3));
}
.tt-messages-body{
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
.tt-messages-summary{
  padding:2px 4px 10px;
}
.tt-messages-summary-main{
  font-size:15px;
  font-weight:700;
  line-height:1.35;
  color:var(--tt-text, var(--msb-palette-text, #101828));
}
.tt-messages-summary-sub{
  margin-top:5px;
  font-size:13px;
  line-height:1.35;
  color:var(--tt-muted, var(--msb-palette-text-muted, #667085));
}
.tt-messages-summary-sub.is-warn{
  color:#b45309;
}
.tt-messages-divider{
  height:1px;
  background:var(--tt-panel-border, var(--msb-palette-border, rgba(15,23,42,.08)));
  margin:0 0 8px;
  flex:0 0 auto;
}
.tt-messages-list{
  flex:1 1 auto;
  min-height:0;
  overflow:auto;
  margin:0 -4px;
  padding:0 4px;
}
.tt-messages-list .dropdown-bestchat-empty{
  padding:16px 8px;
  font-size:13px;
  line-height:1.45;
  color:var(--tt-muted, var(--msb-palette-text-muted, #667085));
}
.tt-messages-list .bestchat-menu-item{
  display:flex;
  align-items:flex-start;
  gap:10px;
  padding:10px 8px;
  border-radius:10px;
  text-decoration:none;
  color:var(--tt-text, var(--msb-palette-text, #101828));
  transition:background .15s ease;
}
.tt-messages-list .bestchat-menu-item + .bestchat-menu-item{
  border-top:none;
  margin-top:2px;
}
.tt-messages-list .bestchat-menu-item:hover{
  background:var(--tt-control-hover, var(--msb-palette-nav-hover, #e9edf3));
  color:var(--tt-text, var(--msb-palette-text, #101828));
}
.tt-messages-list .bestchat-menu-item-unknown{
  color:#b45309;
}
.tt-messages-list .bestchat-menu-item-unknown:hover{
  background:rgba(245,158,11,.12);
}
.tt-messages-list .bestchat-menu-sub,
.tt-messages-list .bestchat-menu-text{
  color:var(--tt-muted, var(--msb-palette-text-muted, #667085));
}
.tt-messages-footer{
  list-style:none;
  margin:12px 0 0;
  padding:0;
  flex:0 0 auto;
}
.tt-messages-footer a{
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
.tt-messages-footer a:hover{
  background:var(--tt-control-hover, var(--msb-palette-nav-hover, #e9edf3));
  text-decoration:none;
}
.tt-messages-footer a .icon{
  width:20px;
  text-align:center;
  font-size:18px;
  color:var(--tt-muted, var(--msb-palette-text-muted, #667085));
}
body.msb-messages-door-open{ overflow:hidden !important; }
body.msb-messages-door-open .msb-messages-door-host{ pointer-events:auto; }
.msb-messages-door-backdrop{
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
body.msb-messages-door-open .msb-messages-door-backdrop{
  opacity:1;
  visibility:visible;
  pointer-events:auto;
  z-index:1289;
}
</style>

<?php if ($__msgStandalone): ?>
<button type="button" class="msb-messages-door-backdrop" id="msbMessagesDoorBackdrop" aria-label="Close messages"></button>
<?php endif; ?>

<div class="tt-messages-wrap" id="tt-messages-wrap" aria-hidden="true">
  <div class="tt-messages-head">
    <div>
      <span class="title">Messages</span>
    </div>
    <button class="tt-close" type="button" id="ttMessagesClose" title="Close">
      <i class="icon ion-close"></i>
    </button>
  </div>
  <div class="tt-messages-body">
    <?php
      if (function_exists('render_header_chat_panel_inner')) {
        echo render_header_chat_panel_inner($__msgThreads, $__msgUnknown, $__msgTotal);
      }
    ?>
  </div>
</div>

<script>
(function(){
  var $messagesWrap = document.getElementById('tt-messages-wrap');
  var $messagesClose = document.getElementById('ttMessagesClose');
  var standaloneHost = document.getElementById('msbMessagesDoorHost');
  var standaloneBackdrop = document.getElementById('msbMessagesDoorBackdrop');
  var isStandalone = !!standaloneHost;

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
    if(window.TTNotifications && typeof window.TTNotifications.close === 'function') window.TTNotifications.close();
    if(window.TTFriendRequests && typeof window.TTFriendRequests.close === 'function') window.TTFriendRequests.close();
  }

  function closeMessagesPanel(){
    if(!$messagesWrap) return;
    $messagesWrap.classList.remove('is-open');
    $messagesWrap.setAttribute('aria-hidden', 'true');
    if(isStandalone) document.body.classList.remove('msb-messages-door-open');
    else document.body.classList.remove('public-leftbar-open');
  }

  function openMessagesPanel(){
    if(!$messagesWrap) return;
    closeOtherPanels();
    $messagesWrap.classList.add('is-open');
    $messagesWrap.setAttribute('aria-hidden', 'false');
    if(isStandalone) document.body.classList.add('msb-messages-door-open');
    else document.body.classList.add('public-leftbar-open');
  }

  function toggleMessagesPanel(){
    if($messagesWrap && $messagesWrap.classList.contains('is-open')) closeMessagesPanel();
    else openMessagesPanel();
  }

  $messagesClose?.addEventListener('click', function(e){
    e.preventDefault();
    e.stopPropagation();
    closeMessagesPanel();
  });

  standaloneBackdrop?.addEventListener('click', function(e){
    e.preventDefault();
    e.stopPropagation();
    closeMessagesPanel();
  });

  document.addEventListener('click', function(e){
    var btn = e.target && e.target.closest ? e.target.closest('.js-open-messages-door') : null;
    if(!btn) return;
    e.preventDefault();
    e.stopPropagation();
    toggleMessagesPanel();
  }, true);

  document.addEventListener('keydown', function(e){
    if(e.key === 'Escape' && $messagesWrap && $messagesWrap.classList.contains('is-open')){
      closeMessagesPanel();
    }
  });

  window.TTMessages = window.TTMessages || {};
  window.TTMessages.open = openMessagesPanel;
  window.TTMessages.close = closeMessagesPanel;
  window.TTMessages.toggle = toggleMessagesPanel;
})();
</script>
