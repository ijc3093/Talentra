<?php
if (!empty($GLOBALS['msb_menu_door_included'])) {
  return;
}
$GLOBALS['msb_menu_door_included'] = true;
?>
<style>
#ttLeftbarOverlays .tt-menu-wrap{
  position:absolute !important;
  inset:0 !important;
  background:var(--tt-panel-bg, var(--msb-palette-bg, #ffffff));
  z-index:997 !important;
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
#ttLeftbarOverlays .tt-menu-wrap.is-open{
  transform:translateX(0);
  opacity:1;
  pointer-events:auto;
}
.tt-menu-head{
  flex:0 0 auto !important;
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:12px;
  padding:33px 40px 20px;
  border-bottom:1px solid var(--tt-panel-border, var(--msb-palette-border, rgba(15,23,42,.08)));
  background:var(--tt-panel-bg, var(--msb-palette-bg, #ffffff));
  position:sticky !important;
  top:0 !important;
  z-index:30 !important;
}
.tt-menu-head-left{
  display:flex;
  align-items:center;
  gap:12px;
  min-width:0;
}
.tt-menu-head .title{
  font-family:'Segoe Script','Apple Chancery','Bradley Hand',cursive;
  font-weight:400;
  font-size:clamp(24px, 2.6vw, 32px);
  line-height:1;
  color:var(--tt-text, var(--msb-palette-text, #101828));
  letter-spacing:.01em;
}
.tt-menu-panel{
  flex:0 0 auto;
  display:flex;
  flex-direction:column;
  width:236px;
  max-width:calc(100% - 56px);
  height:min(340px, calc(100vh - 280px));
  max-height:min(340px, calc(100vh - 220px));
  margin:clamp(12px, 10vh, 120px) 0 24px 40px;
  overflow:hidden;
  box-sizing:border-box;
}
.tt-menu-body{
  flex:1 1 auto !important;
  min-height:0 !important;
  overflow-y:auto !important;
  overflow-x:hidden !important;
  padding:0 2px 0 0;
  -webkit-overflow-scrolling:touch;
  overscroll-behavior:contain;
  touch-action:pan-y;
  background:transparent;
  scrollbar-width:thin;
  scrollbar-color:rgba(0,0,0,.18) transparent;
}
.tt-menu-body::-webkit-scrollbar{width:5px;}
.tt-menu-body::-webkit-scrollbar-thumb{
  background:rgba(0,0,0,.18);
  border-radius:999px;
}
.tt-menu-body .feed-left-nav{
  display:flex;
  flex-direction:column;
  gap:2px;
  margin:0;
  padding:0;
  width:100%;
}
.tt-menu-body .feed-left-nav-item{
  display:flex;
  align-items:center;
  gap:12px;
  min-height:42px;
  padding:8px 12px;
  border-radius:10px;
  color:var(--tt-text, var(--msb-palette-text, #101828));
  font-size:14px;
  font-weight:500;
  line-height:1.2;
  text-decoration:none;
  transition:background .15s ease,color .15s ease;
  box-sizing:border-box;
}
.tt-menu-body .feed-left-nav-item:hover,
.tt-menu-body .feed-left-nav-item:focus{
  background:var(--msb-palette-nav-hover, var(--tt-control-hover));
  color:var(--msb-palette-text-on-nav-hover, var(--msb-palette-text-on-hover, var(--tt-text)));
  box-shadow:inset 0 0 0 1px var(--msb-palette-border-strong, var(--tt-panel-border-strong));
  text-decoration:none;
  outline:none;
}
.tt-menu-body .feed-left-nav-item.is-active,
.tt-menu-body .feed-left-nav-item.is-active:hover,
.tt-menu-body .feed-left-nav-item.is-active:focus{
  background:var(--msb-palette-nav-active-bg, var(--msb-palette-action-soft, var(--tt-accent-soft)));
  color:var(--msb-palette-nav-active-text, var(--msb-palette-text));
  font-weight:600;
}
.tt-menu-body .feed-left-nav-item.is-active .feed-left-nav-ic,
.tt-menu-body .feed-left-nav-item.is-active .feed-left-nav-label,
.tt-menu-body .feed-left-nav-item.is-active .feed-left-nav-ic svg{
  color:var(--msb-palette-nav-active-icon, var(--msb-palette-icon));
}
.tt-menu-body .feed-left-nav-ic{
  flex:0 0 20px;
  width:20px;
  height:20px;
  display:inline-flex;
  align-items:center;
  justify-content:center;
  color:var(--tt-text, var(--msb-palette-text, #101828));
}
.tt-menu-body .feed-left-nav-ic svg{
  display:block;
  width:18px;
  height:18px;
  stroke:currentColor;
  fill:none;
  stroke-width:1.75;
  stroke-linecap:round;
  stroke-linejoin:round;
}
.tt-menu-body .feed-left-nav-label{
  flex:1 1 auto;
  min-width:0;
  white-space:nowrap;
  overflow:hidden;
  text-overflow:ellipsis;
}
.tt-menu-body .feed-left-nav-section{
  width:min(100%, 220px);
  margin:14px auto 4px;
  padding:0 12px;
  font-size:11px;
  font-weight:700;
  letter-spacing:.12em;
  text-transform:uppercase;
  color:var(--tt-muted, var(--msb-palette-text-muted, #667085));
  box-sizing:border-box;
}
.tt-menu-body .feed-left-nav-item-company .feed-left-nav-label,
.tt-menu-body .feed-left-nav-item-publisher .feed-left-nav-label{
  font-weight:600;
}
.tt-menu-body .feed-left-nav-item-under-public{
  margin-left:12px;
  padding-left:20px;
  min-height:38px;
  font-size:13px;
}
.tt-menu-body .feed-left-nav-item-publisher.is-self-publisher .feed-left-nav-label{
  color:var(--tt-text, var(--msb-palette-text, #101828));
}
.tt-menu-scroll-rail{
  flex:0 0 auto;
  display:flex;
  justify-content:flex-end;
  gap:8px;
  padding:10px 2px 0;
  background:transparent;
}
.tt-menu-scroll-btn{
  width:34px;
  height:34px;
  border:0;
  border-radius:50%;
  background:var(--msb-palette-action, var(--msb-palette-btn-bg, #111827));
  color:var(--msb-palette-btn-text, #fff);
  display:inline-flex;
  align-items:center;
  justify-content:center;
  cursor:pointer;
  box-shadow:0 6px 16px rgba(15,23,42,.18);
  padding:0;
}
.tt-menu-scroll-btn svg{
  display:block;
  width:16px;
  height:16px;
  stroke:currentColor;
  fill:none;
  stroke-width:2;
  stroke-linecap:round;
  stroke-linejoin:round;
}
.tt-menu-scroll-btn:hover,
.tt-menu-scroll-btn:focus{
  background:var(--msb-palette-action-strong, var(--msb-palette-btn-hover-bg, #0f172a));
  outline:none;
}
.tt-menu-scroll-btn:disabled{
  opacity:.35;
  cursor:default;
  box-shadow:none;
}
@media (max-width: 991.98px){
  .tt-menu-panel{
    width:min(236px, calc(100% - 32px));
    max-width:calc(100% - 32px);
    margin-left:16px;
    margin-right:auto;
    height:min(340px, calc(100vh - 200px));
    max-height:min(340px, calc(100vh - 160px));
  }
}
</style>

<div class="tt-menu-wrap" id="tt-menu-wrap" aria-hidden="true">
  <div class="tt-menu-head">
    <div class="tt-menu-head-left">
      <span class="title">Menu</span>
    </div>
    <button class="tt-close" type="button" id="ttMenuClose" title="Close">
      <i class="icon ion-close"></i>
    </button>
  </div>
  <div class="tt-menu-panel">
    <div class="tt-menu-body js-tt-menu-scroll">
      <?php
        $feedLeftRailActive = strtolower(basename((string)($_SERVER['PHP_SELF'] ?? '')));
        $feedLeftRailEmbed = true;
        include __DIR__ . '/feed_left_rail.php';
      ?>
    </div>
    <div class="tt-menu-scroll-rail" aria-label="Scroll menu">
      <button type="button" class="tt-menu-scroll-btn js-tt-menu-scroll-up" aria-label="Scroll up">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 14l6-6 6 6"/></svg>
      </button>
      <button type="button" class="tt-menu-scroll-btn js-tt-menu-scroll-down" aria-label="Scroll down">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 10l6 6 6-6"/></svg>
      </button>
    </div>
  </div>
</div>

<script>
(function(){
  var $menuWrap = document.getElementById('tt-menu-wrap');
  var $menuClose = document.getElementById('ttMenuClose');
  var $menuScroll = document.querySelector('.js-tt-menu-scroll');
  var $menuScrollUp = document.querySelector('.js-tt-menu-scroll-up');
  var $menuScrollDown = document.querySelector('.js-tt-menu-scroll-down');
  var $menuScrollRail = document.querySelector('.tt-menu-scroll-rail');
  var isOpen = false;

  function syncMenuScroll(){
    if(!$menuScroll || !$menuScrollUp || !$menuScrollDown) return;
    var max = Math.max(0, $menuScroll.scrollHeight - $menuScroll.clientHeight);
    $menuScrollUp.disabled = $menuScroll.scrollTop <= 1;
    $menuScrollDown.disabled = $menuScroll.scrollTop >= max - 1;
    var show = max > 2;
    $menuScrollUp.style.display = show ? '' : 'none';
    $menuScrollDown.style.display = show ? '' : 'none';
    if($menuScrollRail) $menuScrollRail.style.display = show ? '' : 'none';
  }

  if($menuScroll && $menuScrollUp && $menuScrollDown){
    var menuScrollStep = 120;
    $menuScrollUp.addEventListener('click', function(){
      $menuScroll.scrollBy({top:-menuScrollStep, behavior:'smooth'});
    });
    $menuScrollDown.addEventListener('click', function(){
      $menuScroll.scrollBy({top:menuScrollStep, behavior:'smooth'});
    });
    $menuScroll.addEventListener('scroll', syncMenuScroll, {passive:true});
    window.addEventListener('resize', syncMenuScroll);
    syncMenuScroll();
  }

  function closeOtherPanels(){
    try {
      if(window.TTComments && typeof window.TTComments.close === 'function') window.TTComments.close();
      else {
        var commentsWrap = document.getElementById('tt-comments-wrap');
        if(commentsWrap) commentsWrap.classList.remove('is-open');
      }
    } catch(e){}
    try {
      if(window.TTReadMore && typeof window.TTReadMore.close === 'function') window.TTReadMore.close();
      else {
        var rmWrap = document.getElementById('tt-readmore-wrap');
        if(rmWrap) rmWrap.classList.remove('is-open');
      }
    } catch(e){}
    if(window.TTProfile && typeof window.TTProfile.close === 'function') window.TTProfile.close();
    if(window.TTMessages && typeof window.TTMessages.close === 'function') window.TTMessages.close();
    if(window.TTNotifications && typeof window.TTNotifications.close === 'function') window.TTNotifications.close();
    if(window.TTFriendRequests && typeof window.TTFriendRequests.close === 'function') window.TTFriendRequests.close();
    if(window.TTLive && typeof window.TTLive.close === 'function') window.TTLive.close();
  }

  function closeMenuPanel(){
    if(!$menuWrap || !isOpen) return;
    isOpen = false;
    $menuWrap.classList.remove('is-open');
    $menuWrap.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('public-leftbar-open');
  }

  function openMenuPanel(){
    if(!$menuWrap || isOpen) return;
    isOpen = true;
    closeOtherPanels();
    $menuWrap.classList.add('is-open');
    $menuWrap.setAttribute('aria-hidden', 'false');
    document.body.classList.add('public-leftbar-open');
    requestAnimationFrame(syncMenuScroll);
  }

  function toggleMenuPanel(){
    if($menuWrap && $menuWrap.classList.contains('is-open')) closeMenuPanel();
    else openMenuPanel();
  }

  $menuClose?.addEventListener('click', function(e){
    e.preventDefault();
    e.stopPropagation();
    closeMenuPanel();
  });

  document.addEventListener('keydown', function(e){
    if(e.key === 'Escape' && isOpen) closeMenuPanel();
  });

  document.addEventListener('click', function(e){
    var trigger = e.target && e.target.closest ? e.target.closest('.js-open-menu-door') : null;
    if(!trigger) return;
    e.preventDefault();
    e.stopPropagation();
    toggleMenuPanel();
  }, true);

  window.TTMenu = {
    open: openMenuPanel,
    close: closeMenuPanel,
    toggle: toggleMenuPanel
  };
})();
</script>
