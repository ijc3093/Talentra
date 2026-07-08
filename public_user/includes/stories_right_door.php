<?php
// Right-sidebar story viewer door (Instagram-style overlay UI).
$GLOBALS['msb_stories_right_door_included'] = true;
if (!function_exists('post_card_menu_fries_icon_html')) {
    require_once __DIR__ . '/post_card_actions_menu.php';
}
$storyDoorMenuSurface = 'feed';
$storyDoorScript = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
if ($storyDoorScript === 'public.php') {
    $storyDoorMenuSurface = 'public';
} elseif ($storyDoorScript === 'profile.php') {
    $storyDoorMenuSurface = 'profile';
}
$storySheetMeId = (int)($meId ?? ($_SESSION['user_id'] ?? 0));
$storyDoorStaffReadonly = !empty($staffReadonly);
$storySheetMeName = trim((string)($meDisplayName ?? $meUsername ?? ($_SESSION['user_login'] ?? 'You')));
$storySheetMeEmail = trim((string)($loggedEmail ?? ($_SESSION['user_login'] ?? '')));
$storySheetMeAvatar = 'avatar.php?u=' . $storySheetMeId
  . '&email=' . rawurlencode($storySheetMeEmail)
  . '&name=' . rawurlencode($storySheetMeName !== '' ? $storySheetMeName : 'You')
  . '&s=64';
?>
<style>
#ttRightbarOverlays{
  position:fixed;
  right:0;
  top:0;
  height:100vh;
  width:25%;
  z-index:12050;
  pointer-events:none;
}
#ttRightbarOverlays .tt-stories-right-wrap{
  position:absolute !important;
  inset:0 !important;
  pointer-events:none;
}
#ttRightbarOverlays .tt-stories-right-wrap.is-open{
  pointer-events:auto;
}
#tt-stories-wrap.tt-stories-right-wrap{
  display:flex;
  flex-direction:column;
  overflow:hidden;
  min-height:0;
  background:var(--msb-palette-bg, #000);
  color:var(--msb-palette-text, #fff);
  box-shadow:-18px 0 48px rgba(0,0,0,.32);
  transform:translateX(105%);
  opacity:0;
  transition:transform .18s ease, opacity .18s ease;
}
#tt-stories-wrap.tt-stories-right-wrap.is-open{
  transform:translateX(0);
  opacity:1;
}
.tt-stories-stage{
  flex:1 1 auto;
  min-height:0;
  position:relative;
  background:#000;
  overflow:hidden;
}
.tt-stories-media{
  position:absolute;
  inset:0;
  display:block;
  background:#000;
  z-index:1;
  overflow:hidden;
}
#tt-stories-wrap.has-story-text .tt-stories-media{
  z-index:1;
}
.tt-stories-media-buffer{
  position:absolute;
  inset:0;
  display:flex;
  align-items:center;
  justify-content:center;
  background:#000;
  opacity:0;
  visibility:hidden;
  z-index:0;
  pointer-events:none;
}
.tt-stories-media-buffer.is-front{
  opacity:1;
  visibility:visible;
  z-index:1;
}
#tt-stories-wrap.has-story-text .tt-stories-media.is-text-backdrop .tt-stories-media-buffer.is-front{
  background:var(--msb-palette-bg, linear-gradient(160deg,#1f2937,#111827));
}
.tt-stories-media-buffer video,
.tt-stories-media-buffer img{
  display:block;
  width:100%;
  height:100%;
  object-fit:cover;
  background:#000;
  -webkit-backface-visibility:hidden;
  backface-visibility:hidden;
}
.tt-stories-media-buffer img{
  object-fit:contain;
}
.tt-stories-media .tt-stories-empty{
  padding:24px 16px;
  text-align:center;
  color:rgba(255,255,255,.72);
  font-size:14px;
}
.tt-stories-overlay-top{
  position:absolute;
  left:0;
  right:0;
  top:0;
  z-index:4;
  padding:10px 0 28px 12px;
  background:linear-gradient(180deg, rgba(0,0,0,.62) 0%, rgba(0,0,0,.28) 58%, rgba(0,0,0,0) 100%);
  pointer-events:none;
}
.tt-stories-progress{
  display:flex;
  gap:3px;
  margin-bottom:12px;
  pointer-events:none;
}
.tt-stories-progress[hidden]{
  display:none !important;
}
.tt-stories-progress-seg{
  flex:1 1 0;
  height:2px;
  border-radius:999px;
  background:rgba(255,255,255,.35);
  overflow:hidden;
}
.tt-stories-progress-seg > i{
  display:block;
  height:100%;
  width:0%;
  border-radius:inherit;
  background:#fff;
  transition:width .08s linear;
}
.tt-stories-progress-seg.is-done > i{ width:100%; }
.tt-stories-progress-seg.is-active > i{ width:100%; animation:ttStorySegFill 5s linear forwards; }
.tt-stories-progress-seg.is-active.is-instant > i{
  animation:none;
  width:0%;
  transition:none;
}
#tt-stories-wrap.is-slide-changing .tt-stories-progress-seg > i{
  transition:none !important;
  animation:none !important;
}
.tt-stories-wrap.is-paused .tt-stories-progress-seg.is-active > i{
  animation-play-state:paused;
}
@keyframes ttStorySegFill{ from{width:0%} to{width:100%} }
.tt-stories-bar{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:10px;
  pointer-events:auto;
  position:relative;
  padding-right:30px;
}
.tt-stories-user{
  display:flex;
  align-items:center;
  gap:8px;
  min-width:0;
  flex:1 1 auto;
}
.tt-stories-avatar{
  width:32px;
  height:32px;
  border-radius:50%;
  overflow:hidden;
  flex:0 0 auto;
  background:var(--msb-palette-hover-bg, #333);
  border:1px solid var(--msb-palette-border, rgba(255,255,255,.18));
}
.tt-stories-avatar img{
  width:100%;
  height:100%;
  object-fit:cover;
  display:block;
}
.tt-stories-meta{
  display:flex;
  align-items:center;
  gap:6px;
  min-width:0;
  font-size:13px;
  line-height:1.2;
  color:var(--msb-palette-text, #fff);
  text-shadow:0 1px 3px rgba(0,0,0,.45);
}
.tt-stories-name{
  font-weight:700;
  white-space:nowrap;
  overflow:hidden;
  text-overflow:ellipsis;
  max-width:140px;
}
.tt-stories-verified{
  flex:0 0 auto;
  color:#60a5fa;
  font-size:12px;
  line-height:1;
}
.tt-stories-verified[hidden]{
  display:none !important;
}
.tt-stories-time{
  flex:0 0 auto;
  color:var(--msb-palette-text-muted, rgba(255,255,255,.78));
  font-weight:400;
  white-space:nowrap;
}
.tt-stories-controls{
  display:flex;
  align-items:center;
  gap:14px;
  flex:0 0 auto;
  margin-left:auto;
}
.tt-stories-ctrl{
  width:auto;
  height:auto;
  padding:0;
  border:0;
  background:transparent;
  color:var(--msb-palette-text, #fff);
  display:flex;
  align-items:center;
  justify-content:center;
  cursor:pointer;
  text-shadow:0 1px 3px rgba(0,0,0,.45);
}
.tt-stories-ctrl i{ font-size:22px; line-height:1; margin-right:10px;}
.tt-stories-ctrl:hover{ opacity:.82; }
.tt-stories-menu-wrap{
  position:absolute;
  right:0;
  top:50%;
  transform:translateY(-50%);
  z-index:12;
  margin:0 !important;
  flex:0 0 auto;
  width:auto;
}
#tt-stories-wrap .tt-stories-bar > .tt-stories-menu-wrap{
  position:absolute !important;
  right:2% !important;
  left:auto !important;
  top:50% !important;
  transform:translateY(-50%) !important;
  margin:0 !important;
}
.tt-stories-menu-wrap .post-card-menu-btn{
  min-width:24px;
  min-height:55px;
  padding:0;
  margin:0;
  color:#fff !important;
  --pcm-fries-filter:drop-shadow(0 1px 2px rgba(0,0,0,.7)) drop-shadow(0 0 1px rgba(0,0,0,.5));
}
.tt-stories-menu-wrap .post-card-menu-btn .pcm-fries-icon{
  color:#fff;
}
.tt-stories-menu-wrap .post-card-menu{
  z-index:130;
}
#tt-stories-wrap:not(.is-open) .tt-stories-menu-wrap{
  pointer-events:none;
}
.tt-stories-caption{
  position:absolute;
  left:12px;
  right:12px;
  bottom:74px;
  z-index:6;
  font-size:13px;
  line-height:1.45;
  color:#fff;
  text-shadow:0 1px 4px rgba(0,0,0,.65);
  max-height:min(26vh, 108px);
  overflow-x:hidden;
  overflow-y:auto;
  overscroll-behavior:contain;
  -webkit-overflow-scrolling:touch;
  touch-action:pan-y;
  pointer-events:auto;
  padding-right:4px;
  scrollbar-width:thin;
  scrollbar-color:rgba(255,255,255,.45) transparent;
}
.tt-stories-caption::-webkit-scrollbar{ width:4px; }
.tt-stories-caption::-webkit-scrollbar-thumb{
  background:rgba(255,255,255,.42);
  border-radius:999px;
}
.tt-stories-caption:empty{ display:none; }
#tt-stories-wrap.has-story-text .tt-stories-caption{
  display:none !important;
}
.tt-stories-scroll-panel{
  position:absolute;
  left:0;
  right:0;
  bottom:78px;
  z-index:8;
  overflow-x:hidden;
  overflow-y:auto;
  overscroll-behavior:contain;
  -webkit-overflow-scrolling:touch;
  touch-action:pan-y;
  pointer-events:auto;
  padding:18px 20px 24px;
  box-sizing:border-box;
  scrollbar-width:thin;
  scrollbar-color:rgba(255,255,255,.45) transparent;
}
.tt-stories-scroll-panel[hidden]{
  display:none !important;
}
.tt-stories-scroll-panel::-webkit-scrollbar{ width:5px; }
.tt-stories-scroll-panel::-webkit-scrollbar-thumb{
  background:rgba(255,255,255,.42);
  border-radius:999px;
}
.tt-stories-scroll-panel.is-full{
  top:48px;
  bottom:78px;
  background:var(--msb-palette-bg, linear-gradient(160deg,#1f2937 0%,#111827 100%));
}
.tt-stories-scroll-panel.is-media-caption{
  top:auto;
  height:min(50%, 300px);
  max-height:calc(100% - 124px);
  padding-top:28px;
  background:linear-gradient(180deg, rgba(17,24,39,0) 0%, rgba(17,24,39,.9) 16%, rgba(17,24,39,.98) 100%);
}
.tt-stories-scroll-inner{
  font-size:13px;
  line-height:1.5;
  font-weight:500;
  color:#fff;
  word-break:break-word;
  text-shadow:0 1px 3px rgba(0,0,0,.35);
}
.tt-stories-paragraph{
  margin:0 0 12px;
  text-align:left;
}
.tt-stories-paragraph:last-child{
  margin-bottom:0;
}
.tt-stories-scroll-panel.is-full .tt-stories-scroll-inner{
  font-size:13px;
  line-height:1.48;
  font-weight:500;
}
#tt-stories-wrap.has-story-text .tt-stories-media.is-text-backdrop{
  background:var(--msb-palette-bg, linear-gradient(160deg,#1f2937,#111827));
}
.tt-stories-nav{
  position:absolute;
  top:50%;
  transform:translateY(-50%);
  width:34px;
  height:34px;
  border:0;
  border-radius:50%;
  background:var(--msb-palette-hover-bg, rgba(0,0,0,.42));
  color:var(--msb-palette-text, #fff);
  box-shadow:inset 0 0 0 1px var(--msb-palette-border, rgba(255,255,255,.14));
  backdrop-filter:blur(14px);
  -webkit-backdrop-filter:blur(14px);
  display:inline-flex;
  align-items:center;
  justify-content:center;
  cursor:pointer;
  z-index:8;
  padding:0;
  pointer-events:auto;
  transition:opacity .15s ease;
  -webkit-tap-highlight-color:transparent;
}
.tt-stories-nav.prev{ left:8px; }
.tt-stories-nav.next{ right:8px; }
.tt-stories-nav i{
  font-size:18px;
  line-height:1;
  pointer-events:none;
}
.tt-stories-nav:hover:not(:disabled):not(.is-disabled){
  opacity:.9;
}
.tt-stories-nav:active{
  opacity:.78;
}
.tt-stories-nav:focus{
  outline:none;
}
.tt-stories-nav:disabled,
.tt-stories-nav.is-disabled{
  opacity:.32;
  cursor:not-allowed;
  pointer-events:none;
}
#tt-stories-wrap:not(.is-open) .tt-stories-nav{
  display:none;
}
#tt-stories-wrap.is-publisher-story .tt-stories-nav,
#tt-stories-wrap:not(.is-publisher-story) .tt-stories-nav{
  display:inline-flex;
}
#tt-stories-wrap.is-publisher-story:not(.is-open) .tt-stories-nav,
#tt-stories-wrap:not(.is-publisher-story):not(.is-open) .tt-stories-nav{
  display:none;
}
.tt-story-cmt-sheet.is-open ~ .tt-stories-nav{
  opacity:0;
  pointer-events:none;
}
.tt-stories-overlay-bottom{
  position:absolute;
  left:0;
  right:0;
  bottom:0;
  z-index:5;
  padding:12px 12px 16px;
  background:linear-gradient(0deg, rgba(0,0,0,.58) 0%, rgba(0,0,0,.22) 55%, rgba(0,0,0,0) 100%);
  pointer-events:none;
}
.tt-stories-empty{
  padding:24px 16px;
  text-align:center;
  color:var(--msb-palette-text-muted, rgba(255,255,255,.72));
  font-size:14px;
}
.tt-stories-input-row{
  display:flex;
  align-items:center;
  gap:14px;
  pointer-events:auto;
}
.tt-stories-textarea{
  flex:1;
  border:1px solid rgba(255,255,255,.45);
  border-radius:999px;
  padding:11px 16px;
  outline:none;
  font-size:14px;
  line-height:1.35;
  background:var(--msb-palette-hover-bg, rgba(255,255,255,.12));
  color:var(--msb-palette-text, #fff);
  min-height:44px;
  max-height:88px;
  resize:none;
  font-family:inherit;
  backdrop-filter:blur(6px);
  -webkit-backdrop-filter:blur(6px);
}
.tt-stories-textarea::placeholder{
  color:var(--msb-palette-placeholder, rgba(255,255,255,.72));
  font-size:14px;
}
.tt-stories-lovebtn,
.tt-stories-send{
  width:auto;
  height:auto;
  padding:0;
  border:0;
  background:transparent;
  color:var(--msb-palette-text, #fff);
  display:flex;
  align-items:center;
  justify-content:center;
  cursor:pointer;
  flex:0 0 auto;
  text-shadow:0 1px 3px rgba(0,0,0,.45);
}
.tt-stories-lovebtn i,
.tt-stories-send i{
  font-size:24px;
  line-height:1;
}
.tt-stories-lovebtn:hover,
.tt-stories-send:hover{ opacity:.82; }
.tt-stories-lovebtn.is-loved,
.tt-stories-lovebtn.liked,
.tt-stories-lovebtn.rx-active{
  color:var(--msb-love-color, #7c3aed);
}
.tt-stories-send:disabled,
.tt-stories-textarea:disabled,
.tt-stories-lovebtn:disabled{
  opacity:.45;
  cursor:not-allowed;
}
.tt-stories-user-foot{
  pointer-events:auto;
}
#tt-stories-wrap:not(.is-publisher-story) .tt-stories-overlay-bottom{
  padding:12px 8px 16px;
  background:linear-gradient(0deg, rgba(0,0,0,.55) 0%, rgba(0,0,0,.28) 50%, rgba(0,0,0,0) 100%);
}
#tt-stories-wrap:not(.is-publisher-story) .tt-stories-input-row{
  align-items:center;
  gap:6px;
}
#tt-stories-wrap:not(.is-publisher-story) .tt-stories-textarea{
  flex:1 1 auto;
  min-width:0;
  border:0;
  background:var(--msb-palette-hover-bg, rgba(0,0,0,.42));
  color:var(--msb-palette-text, #fff);
  box-shadow:inset 0 0 0 1px var(--msb-palette-border, rgba(255,255,255,.14));
  backdrop-filter:blur(14px);
  -webkit-backdrop-filter:blur(14px);
  min-height:34px;
  padding:8px 14px;
  font-size:13px;
}
#tt-stories-wrap:not(.is-publisher-story) .tt-stories-textarea::placeholder{
  color:var(--msb-palette-placeholder, rgba(255,255,255,.78));
}
#tt-stories-wrap:not(.is-publisher-story) .tt-stories-textarea:focus{
  box-shadow:inset 0 0 0 1px var(--msb-palette-border-strong, rgba(255,255,255,.28));
  outline:none;
}
.tt-stories-publisher-foot{
  display:none;
  align-items:center;
  justify-content:center;
  gap:5px;
  pointer-events:auto;
  padding:0 4px;
  width:100%;
  box-sizing:border-box;
  flex-wrap:nowrap;
}
.tt-stories-action-col{
  display:flex;
  flex-direction:column;
  align-items:center;
  justify-content:flex-end;
  gap:8px;
  min-width:62px;
  flex:1 1 0;
  max-width:84px;
}
.tt-stories-action-count{
  display:inline-flex;
  align-items:center;
  justify-content:center;
  font-variant-numeric:tabular-nums;
  -webkit-font-smoothing:antialiased;
  user-select:none;
  pointer-events:none;
  letter-spacing:.01em;
  line-height:1;
}
#tt-stories-wrap.is-publisher-story .tt-stories-user-foot{
  display:none !important;
}
#tt-stories-wrap.is-publisher-story .tt-stories-publisher-foot{
  display:flex;
}
#tt-stories-wrap.is-publisher-story .tt-stories-overlay-bottom{
  padding:12px 6px 16px;
  background:linear-gradient(0deg, rgba(0,0,0,.72) 0%, rgba(0,0,0,.42) 45%, rgba(0,0,0,0) 100%);
}
#tt-stories-wrap.is-publisher-story .tt-stories-publisher-foot{
  gap:8px;
}
#tt-stories-wrap.is-publisher-story .tt-stories-action.tt-stories-action-pill,
#tt-stories-wrap.is-publisher-story .tt-stories-action.tt-stories-action-icon,
#tt-stories-wrap:not(.is-publisher-story) .tt-stories-user-foot .tt-stories-action.tt-stories-action-pill,
#tt-stories-wrap:not(.is-publisher-story) .tt-stories-user-foot .tt-stories-action.tt-stories-action-icon{
  color:#fff !important;
  background:rgba(0,0,0,.68) !important;
  border:1px solid rgba(255,255,255,.28);
  box-shadow:0 2px 12px rgba(0,0,0,.38), inset 0 1px 0 rgba(255,255,255,.08);
  backdrop-filter:blur(16px);
  -webkit-backdrop-filter:blur(16px);
}
#tt-stories-wrap .tt-stories-action.tt-stories-action-pill,
#tt-stories-wrap .tt-stories-action.tt-stories-action-icon{
  display:inline-flex;
  align-items:center;
  justify-content:center;
  border:0;
  cursor:pointer;
  flex:0 0 auto;
  color:var(--msb-palette-text, #fff) !important;
  background:var(--msb-palette-hover-bg, rgba(0,0,0,.42));
  backdrop-filter:blur(14px);
  -webkit-backdrop-filter:blur(14px);
  box-shadow:inset 0 0 0 1px var(--msb-palette-border, rgba(255,255,255,.14));
  text-shadow:none;
}
#tt-stories-wrap.is-publisher-story .tt-stories-action.tt-stories-action-pill,
#tt-stories-wrap:not(.is-publisher-story) .tt-stories-user-foot .tt-stories-action.tt-stories-action-pill{
  gap:7px;
  height:38px;
  padding:0 13px;
  border-radius:999px;
  min-width:0;
  width:auto;
  max-width:none;
}
#tt-stories-wrap .tt-stories-action.tt-stories-action-pill{
  gap:6px;
  height:34px;
  padding:0 11px;
  border-radius:999px;
  min-width:0;
  width:auto;
  max-width:none;
}
#tt-stories-wrap.is-publisher-story .tt-stories-action.tt-stories-action-icon,
#tt-stories-wrap:not(.is-publisher-story) .tt-stories-user-foot .tt-stories-action.tt-stories-action-icon{
  width:38px;
  height:38px;
}
#tt-stories-wrap .tt-stories-action.tt-stories-action-icon{
  width:34px;
  height:34px;
  padding:0;
  border-radius:50%;
}
#tt-stories-wrap.is-publisher-story .tt-stories-action.tt-stories-action-pill i,
#tt-stories-wrap.is-publisher-story .tt-stories-action.tt-stories-action-icon i,
#tt-stories-wrap:not(.is-publisher-story) .tt-stories-user-foot .tt-stories-action.tt-stories-action-pill i,
#tt-stories-wrap:not(.is-publisher-story) .tt-stories-user-foot .tt-stories-action.tt-stories-action-icon i{
  font-size:17px;
  line-height:1;
  color:#fff !important;
  opacity:1;
  flex:0 0 auto;
  filter:drop-shadow(0 1px 2px rgba(0,0,0,.45));
}
#tt-stories-wrap .tt-stories-action.tt-stories-action-pill i,
#tt-stories-wrap .tt-stories-action.tt-stories-action-icon i{
  font-size:15px;
  line-height:1;
  color:var(--msb-palette-text, #fff) !important;
  opacity:1;
  flex:0 0 auto;
}
#tt-stories-wrap.is-publisher-story .tt-stories-action .tt-stories-action-count,
#tt-stories-wrap:not(.is-publisher-story) .tt-stories-user-foot .tt-stories-action .tt-stories-action-count{
  font-size:13px;
  font-weight:800;
  font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;
  color:#fff !important;
  background:transparent !important;
  border:0 !important;
  box-shadow:none !important;
  padding:0;
  min-width:0;
  min-height:0;
  text-shadow:0 1px 4px rgba(0,0,0,.65);
}
#tt-stories-wrap .tt-stories-action .tt-stories-action-count{
  font-size:12px;
  font-weight:700;
  font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;
  color:var(--msb-palette-text, #fff) !important;
  background:transparent !important;
  border:0 !important;
  box-shadow:none !important;
  padding:0;
  min-width:0;
  min-height:0;
  text-shadow:0 1px 3px rgba(0,0,0,.45);
}
#tt-stories-wrap.is-publisher-story .tt-stories-action.tt-stories-action-pill:hover,
#tt-stories-wrap.is-publisher-story .tt-stories-action.tt-stories-action-icon:hover,
#tt-stories-wrap:not(.is-publisher-story) .tt-stories-user-foot .tt-stories-action.tt-stories-action-pill:hover,
#tt-stories-wrap:not(.is-publisher-story) .tt-stories-user-foot .tt-stories-action.tt-stories-action-icon:hover{
  background:rgba(0,0,0,.78) !important;
  border-color:rgba(255,255,255,.38);
  box-shadow:0 3px 14px rgba(0,0,0,.45), inset 0 1px 0 rgba(255,255,255,.12);
}
#tt-stories-wrap .tt-stories-action.tt-stories-action-pill:hover,
#tt-stories-wrap .tt-stories-action.tt-stories-action-icon:hover{
  background:rgba(0,0,0,.56);
  box-shadow:inset 0 0 0 1px rgba(255,255,255,.22);
}
#tt-stories-wrap .tt-stories-action.tt-stories-action-pill:disabled,
#tt-stories-wrap .tt-stories-action.tt-stories-action-icon:disabled{
  opacity:.55;
  cursor:not-allowed;
}
#tt-stories-wrap.is-publisher-story .tt-stories-action.is-loved,
#tt-stories-wrap.is-publisher-story .tt-stories-action.is-loved i,
#tt-stories-wrap.is-publisher-story .tt-stories-action.is-loved .tt-stories-action-count,
#tt-stories-wrap.is-publisher-story .tt-stories-action.liked,
#tt-stories-wrap.is-publisher-story .tt-stories-action.liked i,
#tt-stories-wrap.is-publisher-story .tt-stories-action.liked .tt-stories-action-count,
#tt-stories-wrap.is-publisher-story .tt-stories-action.rx-active,
#tt-stories-wrap.is-publisher-story .tt-stories-action.rx-active i,
#tt-stories-wrap.is-publisher-story .tt-stories-action.rx-active .tt-stories-action-count,
#tt-stories-wrap:not(.is-publisher-story) .tt-stories-user-foot .tt-stories-action.is-loved,
#tt-stories-wrap:not(.is-publisher-story) .tt-stories-user-foot .tt-stories-action.is-loved i,
#tt-stories-wrap:not(.is-publisher-story) .tt-stories-user-foot .tt-stories-action.is-loved .tt-stories-action-count,
#tt-stories-wrap .tt-stories-action.is-loved,
#tt-stories-wrap .tt-stories-action.is-loved i,
#tt-stories-wrap .tt-stories-action.is-loved .tt-stories-action-count,
#tt-stories-wrap .tt-stories-action.liked,
#tt-stories-wrap .tt-stories-action.liked i,
#tt-stories-wrap .tt-stories-action.liked .tt-stories-action-count,
#tt-stories-wrap .tt-stories-action.rx-active,
#tt-stories-wrap .tt-stories-action.rx-active i,
#tt-stories-wrap .tt-stories-action.rx-active .tt-stories-action-count{
  color:var(--msb-love-color, #c4b5fd) !important;
  text-shadow:0 1px 4px rgba(0,0,0,.7);
}
#tt-stories-wrap.is-publisher-story .tt-stories-action.is-share.is-active,
#tt-stories-wrap.is-publisher-story .tt-stories-action.is-share.is-active i,
#tt-stories-wrap.is-publisher-story .tt-stories-action.is-share.is-active .tt-stories-action-count,
#tt-stories-wrap .tt-stories-action.is-share.is-active,
#tt-stories-wrap .tt-stories-action.is-share.is-active i,
#tt-stories-wrap .tt-stories-action.is-share.is-active .tt-stories-action-count{
  color:#fff !important;
}
#tt-stories-wrap.is-publisher-story .tt-stories-action.is-save.is-active,
#tt-stories-wrap.is-publisher-story .tt-stories-action.is-save.is-active i,
#tt-stories-wrap .tt-stories-action.is-save.is-active,
#tt-stories-wrap .tt-stories-action.is-save.is-active i{
  color:#fbbf24 !important;
}
.tt-stories-action{
  width:46px;
  height:46px;
  padding:0;
  border:0;
  border-radius:999px;
  background:rgba(255,255,255,.14);
  color:#fff;
  display:inline-flex;
  align-items:center;
  justify-content:center;
  cursor:pointer;
  flex:0 0 auto;
  text-shadow:0 1px 3px rgba(0,0,0,.45);
  backdrop-filter:blur(6px);
  -webkit-backdrop-filter:blur(6px);
}
.tt-stories-action i{
  font-size:22px;
  line-height:1;
  color:inherit;
}
.tt-stories-action:hover{ opacity:.86; }
.tt-stories-action:disabled{
  opacity:.45;
  cursor:not-allowed;
}
.tt-stories-action.is-loved,
.tt-stories-action.is-loved i{
  color:var(--msb-love-color, #7c3aed);
}
.tt-stories-action.is-share.is-active,
.tt-stories-action.is-share.is-active i{
  color:#d1d5db;
}
.tt-stories-action.is-save.is-active,
.tt-stories-action.is-save.is-active i{
  color:#fbbf24;
}
.tt-stories-overlay-bottom{
  padding:24px 16px;
  text-align:center;
  color:var(--msb-palette-text-muted, rgba(255,255,255,.72));
  font-size:14px;
}
.ig-story-item{ cursor:pointer; }
.ig-story-item:focus-visible{
  outline:2px solid rgba(37,99,235,.35);
  outline-offset:3px;
  border-radius:8px;
}
.ig-story-item.is-viewing .ig-story-ring{
  box-shadow:0 0 0 2px #fff, 0 0 0 4px #2563eb;
}
body.tt-stories-open{ overflow:hidden; }
.tt-story-cmt-sheet{
  position:absolute;
  inset:0;
  z-index:30;
  display:flex;
  flex-direction:column;
  justify-content:flex-end;
  pointer-events:none;
  opacity:0;
  visibility:hidden;
  transition:opacity .18s ease, visibility .18s ease;
}
.tt-story-cmt-sheet.is-open{
  pointer-events:auto;
  opacity:1;
  visibility:visible;
}
.tt-story-cmt-backdrop{
  position:absolute;
  inset:0;
  background:rgba(0,0,0,.42);
  border:0;
  padding:0;
  cursor:pointer;
}
.tt-story-cmt-panel{
  position:relative;
  z-index:1;
  display:flex;
  flex-direction:column;
  height:min(70%, calc(100% - 28px));
  max-height:min(70%, calc(100% - 28px));
  min-height:min(70%, calc(100% - 28px));
  background:var(--msb-palette-bg, #121212);
  color:var(--msb-palette-text, #f5f5f5);
  border-radius:16px 16px 0 0;
  box-shadow:0 -12px 40px rgba(0,0,0,.45);
  transform:translateY(104%);
  transition:transform .22s cubic-bezier(.22,1,.36,1);
  overflow:hidden;
  flex:0 0 auto;
}
.tt-story-cmt-sheet.is-open .tt-story-cmt-panel{
  transform:translateY(0);
}
.tt-story-cmt-grab{
  width:42px;
  height:4px;
  border-radius:999px;
  background:rgba(255,255,255,.28);
  margin:10px auto 6px;
  flex:0 0 auto;
}
.tt-story-cmt-head{
  position:relative;
  flex:0 0 auto;
  display:flex;
  align-items:center;
  justify-content:center;
  padding:2px 44px 12px 16px;
  text-align:center;
  border-bottom:1px solid var(--msb-palette-border, rgba(255,255,255,.08));
}
.tt-story-cmt-head h3{
  margin:0;
  font-size:15px;
  font-weight:700;
  letter-spacing:.01em;
  color:var(--msb-palette-text, #fff);
}
.tt-story-cmt-close{
  position:absolute;
  top:50%;
  right:10px;
  transform:translateY(-50%);
  width:32px;
  height:32px;
  border:0;
  border-radius:50%;
  background:transparent;
  color:var(--msb-palette-text, #fff);
  display:inline-flex;
  align-items:center;
  justify-content:center;
  cursor:pointer;
  padding:0;
  line-height:1;
}
.tt-story-cmt-close i{
  font-size:22px;
  line-height:1;
}
.tt-story-cmt-close:hover{
  opacity:.82;
}
.tt-story-cmt-list{
  flex:1 1 auto;
  min-height:0;
  overflow-x:hidden;
  overflow-y:auto;
  overscroll-behavior:contain;
  -webkit-overflow-scrolling:touch;
  padding:8px 14px 12px;
  scrollbar-width:thin;
  scrollbar-color:rgba(255,255,255,.28) transparent;
}
.tt-story-cmt-list::-webkit-scrollbar{ width:4px; }
.tt-story-cmt-list::-webkit-scrollbar-thumb{
  background:rgba(255,255,255,.28);
  border-radius:999px;
}
.tt-story-cmt-empty{
  padding:28px 12px;
  text-align:center;
  color:var(--msb-palette-text-muted, rgba(255,255,255,.55));
  font-size:14px;
}
.tt-story-cmt-row{
  display:flex;
  align-items:flex-start;
  gap:10px;
  padding:10px 0;
}
.tt-story-cmt-avatar{
  width:34px;
  height:34px;
  border-radius:50%;
  overflow:hidden;
  flex:0 0 auto;
  background:var(--msb-palette-hover-bg, #2a2a2a);
}
.tt-story-cmt-avatar img{
  width:100%;
  height:100%;
  object-fit:cover;
  display:block;
}
.tt-story-cmt-main{
  flex:1 1 auto;
  min-width:0;
}
.tt-story-cmt-topline{
  display:flex;
  align-items:baseline;
  gap:6px;
  flex-wrap:wrap;
  margin-bottom:2px;
}
.tt-story-cmt-name{
  font-size:13px;
  font-weight:700;
  color:var(--msb-palette-text, #fff);
}
.tt-story-cmt-time{
  font-size:12px;
  color:var(--msb-palette-text-muted, rgba(255,255,255,.45));
}
.tt-story-cmt-text{
  font-size:14px;
  line-height:1.4;
  color:var(--msb-palette-text, #f3f4f6);
  word-break:break-word;
}
.tt-story-cmt-mention{
  color:#60a5fa;
  font-weight:500;
}
.tt-story-cmt-actions{
  display:flex;
  align-items:center;
  gap:14px;
  margin-top:6px;
}
.tt-story-cmt-reply-btn{
  border:0;
  background:transparent;
  padding:0;
  font-size:12px;
  font-weight:600;
  color:rgba(255,255,255,.45);
  cursor:pointer;
}
.tt-story-cmt-reply-btn:hover{ color:rgba(255,255,255,.72); }
.tt-story-cmt-view-replies{
  border:0;
  background:transparent;
  padding:8px 0 0 44px;
  font-size:12px;
  font-weight:600;
  color:rgba(255,255,255,.45);
  cursor:pointer;
  text-align:left;
  width:100%;
}
.tt-story-cmt-view-replies:hover{ color:rgba(255,255,255,.72); }
.tt-story-cmt-like-col{
  flex:0 0 auto;
  display:flex;
  flex-direction:column;
  align-items:center;
  gap:4px;
  padding-top:2px;
  min-width:28px;
}
.tt-story-cmt-like-btn{
  border:0;
  background:transparent;
  padding:0;
  color:rgba(255,255,255,.72);
  cursor:pointer;
  line-height:1;
}
.tt-story-cmt-like-btn i{ font-size:13px; }
.tt-story-cmt-like-btn.is-liked,
.tt-story-cmt-like-btn.is-liked i{ color:#ff3040; }
.tt-story-cmt-like-count{
  font-size:11px;
  color:rgba(255,255,255,.55);
  line-height:1;
}
.tt-story-cmt-children{
  margin-left:44px;
  padding-top:2px;
}
.tt-story-cmt-foot{
  flex:0 0 auto;
  border-top:1px solid var(--msb-palette-border, rgba(255,255,255,.08));
  background:var(--msb-palette-bg, #121212);
  padding:8px 12px calc(10px + env(safe-area-inset-bottom, 0px));
}
.tt-story-cmt-emojis{
  display:flex;
  align-items:center;
  justify-content:flex-start;
  gap:4px;
  padding:0 2px 8px;
  overflow-x:auto;
  scrollbar-width:none;
}
.tt-story-cmt-emojis::-webkit-scrollbar{ display:none; }
.tt-story-cmt-emoji{
  border:0;
  background:transparent;
  padding:2px 4px;
  font-size:22px;
  line-height:1;
  cursor:pointer;
  flex:0 0 auto;
}
.tt-story-cmt-emoji:hover{ transform:scale(1.08); }
.tt-story-cmt-replying{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:10px;
  padding:0 2px 8px 46px;
  font-size:12px;
  color:rgba(255,255,255,.55);
}
.tt-story-cmt-replying[hidden]{ display:none !important; }
.tt-story-cmt-replying button{
  border:0;
  background:transparent;
  color:rgba(255,255,255,.72);
  font-size:12px;
  font-weight:600;
  cursor:pointer;
  padding:0;
}
.tt-story-cmt-compose{
  display:flex;
  align-items:center;
  gap:10px;
}
.tt-story-cmt-send{
  width:34px;
  height:34px;
  border:0;
  border-radius:50%;
  flex:0 0 auto;
  display:inline-flex;
  align-items:center;
  justify-content:center;
  background:var(--msb-palette-hover-bg, rgba(0,0,0,.42));
  color:var(--msb-palette-text, #fff);
  box-shadow:inset 0 0 0 1px var(--msb-palette-border, rgba(255,255,255,.14));
  backdrop-filter:blur(14px);
  -webkit-backdrop-filter:blur(14px);
  cursor:pointer;
  padding:0;
}
.tt-story-cmt-send i{
  font-size:15px;
  line-height:1;
}
.tt-story-cmt-send:hover{
  opacity:.88;
}
.tt-story-cmt-send:disabled{
  opacity:.45;
  cursor:not-allowed;
}
.tt-story-cmt-me-avatar{
  width:34px;
  height:34px;
  border-radius:50%;
  overflow:hidden;
  flex:0 0 auto;
  background:var(--msb-palette-hover-bg, #2a2a2a);
}
.tt-story-cmt-me-avatar img{
  width:100%;
  height:100%;
  object-fit:cover;
  display:block;
}
.tt-story-cmt-input-wrap{
  flex:1 1 auto;
  min-width:0;
  display:flex;
  align-items:center;
  gap:8px;
  border:1px solid var(--msb-palette-border, rgba(255,255,255,.14));
  border-radius:999px;
  background:var(--msb-palette-hover-bg, rgba(255,255,255,.06));
  padding:0 12px;
  min-height:42px;
}
.tt-story-cmt-input-wrap input{
  flex:1 1 auto;
  min-width:0;
  border:0;
  outline:none;
  background:transparent;
  color:var(--msb-palette-text, #fff);
  font-size:14px;
  line-height:1.35;
  padding:10px 0;
}
.tt-story-cmt-input-wrap input::placeholder{
  color:rgba(255,255,255,.42);
}
.tt-story-cmt-gif{
  border:1px solid var(--msb-palette-border, rgba(255,255,255,.18));
  border-radius:6px;
  background:transparent;
  color:var(--msb-palette-text-muted, rgba(255,255,255,.72));
  font-size:11px;
  font-weight:700;
  padding:3px 6px;
  line-height:1;
  flex:0 0 auto;
  margin-left:auto;
  cursor:pointer;
}
.tt-story-cmt-gif:hover{
  color:var(--msb-palette-text, rgba(255,255,255,.92));
  border-color:var(--msb-palette-border-strong, rgba(255,255,255,.28));
}
@media (max-width:991.98px){
  #ttRightbarOverlays{
    right:0;
    width:100%;
    max-width:100%;
  }
  .tt-stories-name{ max-width:180px; }
}
</style>

<div id="ttRightbarOverlays">
<div
  class="tt-stories-right-wrap"
  id="tt-stories-wrap"
  aria-hidden="true"
  aria-label="Story viewer"
>
  <div class="tt-stories-stage" id="ttStoriesStage">
    <div class="tt-stories-media" id="ttStoriesMedia">
      <div class="tt-stories-media-buffer is-front" id="ttStoriesMediaA"></div>
      <div class="tt-stories-media-buffer" id="ttStoriesMediaB"></div>
    </div>

    <div class="tt-stories-scroll-panel" id="ttStoriesScrollPanel" hidden>
      <div class="tt-stories-scroll-inner" id="ttStoriesScrollInner"></div>
    </div>

    <div class="tt-stories-overlay-top">
      <div class="tt-stories-progress" id="ttStoriesProgress" hidden></div>
      <div class="tt-stories-bar">
        <div class="tt-stories-user">
          <div class="tt-stories-avatar" id="ttStoriesAvatar"><img src="" alt=""></div>
          <div class="tt-stories-meta">
            <span class="tt-stories-name" id="ttStoriesTitle">Story</span>
            <span class="tt-stories-verified" id="ttStoriesVerified" hidden aria-hidden="true"><i class="fa fa-check-circle"></i></span>
            <span class="tt-stories-time" id="ttStoriesTime"></span>
          </div>
        </div>
        <div class="tt-stories-controls">
          <button type="button" class="tt-stories-ctrl" id="ttStoriesPause" title="Pause" aria-label="Pause story">
            <i class="icon ion-pause"></i>
          </button>
          <button type="button" class="tt-stories-ctrl" id="ttStoriesClose" title="Close" aria-label="Close story">
            <i class="icon ion-close"></i>
          </button>
        </div>
        <div class="post-card-menu-wrap mf-menu-wrap tt-stories-menu-wrap mf-head--on-media" id="ttStoriesMenuWrap" data-post-id="0" data-is-owner="0" data-menu-surface="<?= htmlspecialchars($storyDoorMenuSurface, ENT_QUOTES, 'UTF-8') ?>">
          <button type="button" class="post-card-menu-btn mf-menu-btn pcm-on-dark-media tt-stories-ctrl" id="ttStoriesMenuBtn" aria-label="Story menu" title="Menu" aria-haspopup="true" aria-expanded="false">
            <?= post_card_menu_fries_icon_html() ?>
          </button>
          <div class="post-card-menu mf-menu" id="ttStoriesMenu" role="menu"></div>
        </div>
      </div>
    </div>

    <div class="tt-stories-caption" id="ttStoriesCaption"></div>

    <div class="tt-stories-overlay-bottom">
      <form id="ttStoriesCommentForm" class="tt-stories-user-foot m-0" autocomplete="off">
        <input type="hidden" id="ttStoriesPostId" value="0">
        <div class="tt-stories-input-row">
          <textarea class="tt-stories-textarea" id="ttStoriesCommentText" rows="1" placeholder="Reply to story..."></textarea>
          <button type="button" class="tt-stories-action tt-stories-action-pill tt-stories-lovebtn tt-reactbtn" id="ttStoriesLove" title="Love" aria-label="Love">
            <i class="fa fa-heart-o"></i>
            <span class="tt-stories-action-count" id="ttStoriesLoveCount">0</span>
          </button>
          <button class="tt-stories-action tt-stories-action-icon tt-stories-send" type="submit" title="Send" aria-label="Send reply">
            <i class="fa fa-paper-plane-o"></i>
          </button>
        </div>
      </form>
      <div class="tt-stories-publisher-foot" id="ttStoriesPublisherFoot" aria-hidden="true">
        <button type="button" class="tt-stories-action tt-stories-action-pill" id="ttStoriesPubComment" title="Comment" aria-label="Comment">
          <i class="fa fa-comment-o"></i>
          <span class="tt-stories-action-count" id="ttStoriesPubCommentCount">0</span>
        </button>
        <button type="button" class="tt-stories-action tt-stories-action-pill tt-stories-lovebtn tt-reactbtn" id="ttStoriesPubLove" title="Love" aria-label="Love">
          <i class="fa fa-heart-o"></i>
          <span class="tt-stories-action-count" id="ttStoriesPubLoveCount">0</span>
        </button>
        <button type="button" class="tt-stories-action tt-stories-action-pill" id="ttStoriesPubShare" title="Share" aria-label="Share">
          <i class="fa fa-paper-plane-o"></i>
          <span class="tt-stories-action-count" id="ttStoriesPubShareCount">0</span>
        </button>
        <button type="button" class="tt-stories-action tt-stories-action-icon" id="ttStoriesPubSave" title="Save" aria-label="Save">
          <i class="fa fa-bookmark-o"></i>
        </button>
      </div>
    </div>

    <div class="tt-story-cmt-sheet" id="ttStoryCmtSheet" aria-hidden="true">
      <button type="button" class="tt-story-cmt-backdrop" id="ttStoryCmtBackdrop" aria-label="Close comments"></button>
      <div class="tt-story-cmt-panel" role="dialog" aria-modal="true" aria-labelledby="ttStoryCmtTitle">
        <div class="tt-story-cmt-grab" aria-hidden="true"></div>
        <div class="tt-story-cmt-head">
          <h3 id="ttStoryCmtTitle">Comments</h3>
          <button type="button" class="tt-story-cmt-close" id="ttStoryCmtClose" title="Close" aria-label="Close comments">
            <i class="icon ion-close"></i>
          </button>
        </div>
        <div class="tt-story-cmt-list" id="ttStoryCmtList">
          <div class="tt-story-cmt-empty">No comments yet.</div>
        </div>
        <div class="tt-story-cmt-foot">
          <div class="tt-story-cmt-emojis" id="ttStoryCmtEmojis" aria-label="Quick reactions">
            <button type="button" class="tt-story-cmt-emoji" data-emoji="❤️" aria-label="Heart">❤️</button>
            <button type="button" class="tt-story-cmt-emoji" data-emoji="🙌" aria-label="Hands">🙌</button>
            <button type="button" class="tt-story-cmt-emoji" data-emoji="🔥" aria-label="Fire">🔥</button>
            <button type="button" class="tt-story-cmt-emoji" data-emoji="👏" aria-label="Clap">👏</button>
            <button type="button" class="tt-story-cmt-emoji" data-emoji="😢" aria-label="Sad">😢</button>
            <button type="button" class="tt-story-cmt-emoji" data-emoji="😍" aria-label="Love eyes">😍</button>
            <button type="button" class="tt-story-cmt-emoji" data-emoji="😮" aria-label="Wow">😮</button>
            <button type="button" class="tt-story-cmt-emoji" data-emoji="😂" aria-label="Laugh">😂</button>
            <button type="button" class="tt-story-cmt-gif" aria-label="Add GIF">GIF</button>
          </div>
          <div class="tt-story-cmt-replying" id="ttStoryCmtReplying" hidden>
            <span id="ttStoryCmtReplyingText"></span>
            <button type="button" id="ttStoryCmtCancelReply">Cancel</button>
          </div>
          <form id="ttStoryCmtForm" class="m-0" autocomplete="off">
            <input type="hidden" id="ttStoryCmtPostId" value="0">
            <input type="hidden" id="ttStoryCmtParentId" value="0">
            <div class="tt-story-cmt-compose">
              <div class="tt-story-cmt-me-avatar">
                <img id="ttStoryCmtMeAvatar" src="<?= htmlspecialchars($storySheetMeAvatar, ENT_QUOTES, 'UTF-8') ?>" alt="">
              </div>
              <div class="tt-story-cmt-input-wrap">
                <input type="text" id="ttStoryCmtInput" placeholder="Join the conversation..." autocomplete="off">
              </div>
              <button type="submit" class="tt-story-cmt-send" id="ttStoryCmtSend" title="Send" aria-label="Send comment" disabled>
                <i class="fa fa-paper-plane-o"></i>
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <button type="button" class="tt-stories-nav prev" id="ttStoriesPrev" title="Previous" aria-label="Previous story">
      <i class="icon ion-chevron-left" aria-hidden="true"></i>
    </button>
    <button type="button" class="tt-stories-nav next" id="ttStoriesNext" title="Next" aria-label="Next story">
      <i class="icon ion-chevron-right" aria-hidden="true"></i>
    </button>
  </div>
</div>
<?php include __DIR__ . '/live_right_door.php'; ?>
</div>

<script>
(function(){
  var wrap = document.getElementById('tt-stories-wrap');
  if(!wrap || window.TTStories) return;

  var overlaysRoot = document.getElementById('ttRightbarOverlays');
  if(overlaysRoot && overlaysRoot.parentNode !== document.body){
    document.body.appendChild(overlaysRoot);
  }

  var titleEl = document.getElementById('ttStoriesTitle');
  var timeEl = document.getElementById('ttStoriesTime');
  var verifiedEl = document.getElementById('ttStoriesVerified');
  var avatarEl = document.getElementById('ttStoriesAvatar');
  var mediaEl = document.getElementById('ttStoriesMedia');
  var scrollPanel = document.getElementById('ttStoriesScrollPanel');
  var scrollInner = document.getElementById('ttStoriesScrollInner');
  var captionEl = document.getElementById('ttStoriesCaption');
  var progressEl = document.getElementById('ttStoriesProgress');
  var closeBtn = document.getElementById('ttStoriesClose');
  var storyMenuWrap = document.getElementById('ttStoriesMenuWrap');
  var storyMenu = document.getElementById('ttStoriesMenu');
  var STORY_DOOR_MENU_SURFACE = <?= json_encode($storyDoorMenuSurface, JSON_UNESCAPED_UNICODE) ?>;
  var STORY_DOOR_STAFF_READONLY = <?= $storyDoorStaffReadonly ? 'true' : 'false' ?>;
  var pauseBtn = document.getElementById('ttStoriesPause');
  var prevBtn = document.getElementById('ttStoriesPrev');
  var nextBtn = document.getElementById('ttStoriesNext');
  var storyForm = document.getElementById('ttStoriesCommentForm');
  var storyText = document.getElementById('ttStoriesCommentText');
  var storyPostId = document.getElementById('ttStoriesPostId');
  var storyLoveBtn = document.getElementById('ttStoriesLove');
  var storyLoveCountEl = document.getElementById('ttStoriesLoveCount');
  var storyPubCommentBtn = document.getElementById('ttStoriesPubComment');
  var storyPubLoveBtn = document.getElementById('ttStoriesPubLove');
  var storyPubShareBtn = document.getElementById('ttStoriesPubShare');
  var storyPubSaveBtn = document.getElementById('ttStoriesPubSave');
  var storyPublisherFoot = document.getElementById('ttStoriesPublisherFoot');
  var storyPubCommentCountEl = document.getElementById('ttStoriesPubCommentCount');
  var storyPubLoveCountEl = document.getElementById('ttStoriesPubLoveCount');
  var storyPubShareCountEl = document.getElementById('ttStoriesPubShareCount');
  var storyPubSaveCountEl = document.getElementById('ttStoriesPubSaveCount');
  var storyCmtSheet = document.getElementById('ttStoryCmtSheet');
  var storyCmtBackdrop = document.getElementById('ttStoryCmtBackdrop');
  var storyCmtClose = document.getElementById('ttStoryCmtClose');
  var storyCmtList = document.getElementById('ttStoryCmtList');
  var storyCmtForm = document.getElementById('ttStoryCmtForm');
  var storyCmtPostId = document.getElementById('ttStoryCmtPostId');
  var storyCmtParentId = document.getElementById('ttStoryCmtParentId');
  var storyCmtInput = document.getElementById('ttStoryCmtInput');
  var storyCmtSend = document.getElementById('ttStoryCmtSend');
  var storyCmtReplying = document.getElementById('ttStoryCmtReplying');
  var storyCmtReplyingText = document.getElementById('ttStoryCmtReplyingText');
  var storyCmtCancelReply = document.getElementById('ttStoryCmtCancelReply');
  var storyCmtEmojis = document.getElementById('ttStoryCmtEmojis');
  var storyCmtMeAvatar = document.getElementById('ttStoryCmtMeAvatar');
  var storyCommentsOpen = false;
  var storyCommentsCache = [];
  var storyCommentsPostId = 0;
  var storyCommentsByParent = {};
  var storyCollapsedReplyIds = {};
  var STORY_CMT_PLACEHOLDER = 'Join the conversation...';
  var STORY_SHEET_ME_ID = <?= (int)$storySheetMeId ?>;
  var STORY_SHEET_ME_NAME = <?= json_encode($storySheetMeName !== '' ? $storySheetMeName : 'You', JSON_UNESCAPED_UNICODE) ?>;
  var STORY_SHEET_ME_EMAIL = <?= json_encode($storySheetMeEmail, JSON_UNESCAPED_UNICODE) ?>;

  var catalog = [];
  var storyIndex = -1;
  var slideIndex = 0;
  var slideTimer = null;
  var activeVideo = null;
  var currentStoryReaction = '';
  var isPaused = false;
  var slideNavInstant = false;
  var mediaRenderToken = 0;
  var mediaBufferA = null;
  var mediaBufferB = null;
  var mediaBufferFrontIsA = true;
  var defaultReplyPlaceholder = 'Reply to story...';

  function esc(s){
    return String(s || '').replace(/[&<>"']/g, function(m){
      return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]);
    });
  }

  function stripStoryLayoutMarker(txt){
    return formatStoryTextPreserve(txt);
  }

  function formatStoryTextPreserve(text){
    text = String(text || '');
    text = text.replace(/\[\[layout:[a-z0-9_]+\]\]/ig, '');
    text = text.replace(/\r\n/g, '\n').replace(/\r/g, '\n');
    text = text.replace(/[ \t]+\n/g, '\n').replace(/\n[ \t]+/g, '\n');
    text = text.replace(/\n{3,}/g, '\n\n');
    return text.trim();
  }

  function renderStoryTextHtml(text){
    text = formatStoryTextPreserve(text);
    if(!text) return '';
    return text.split(/\n\s*\n/).map(function(block){
      block = block.trim();
      if(!block) return '';
      var lines = block.split(/\n/).map(function(line){
        return esc(String(line || '').trim());
      }).filter(Boolean).join('<br>');
      return '<p class="tt-stories-paragraph">'+lines+'</p>';
    }).filter(Boolean).join('');
  }

  function storySlideText(slide){
    return formatStoryTextPreserve(String((slide && (slide.caption || slide.title)) || ''));
  }

  function storyReadDurationMs(text){
    text = String(text || '').trim();
    if(!text) return 2500;
    return Math.max(10000, Math.min(60000, 6000 + (text.length * 42)));
  }

  function setStoryScrollPanel(text, mode){
    text = String(text || '').trim();
    if(!scrollPanel || !scrollInner){
      if(wrap) wrap.classList.toggle('has-story-text', !!text);
      return;
    }
    if(!text || !mode){
      scrollPanel.hidden = true;
      scrollInner.textContent = '';
      scrollPanel.className = 'tt-stories-scroll-panel';
      if(wrap) wrap.classList.remove('has-story-text');
      return;
    }
    scrollPanel.hidden = false;
    scrollInner.innerHTML = renderStoryTextHtml(text);
    scrollPanel.className = 'tt-stories-scroll-panel is-' + (mode === 'full' ? 'full' : 'media-caption');
    if(wrap) wrap.classList.add('has-story-text');
    scrollPanel.scrollTop = 0;
  }

  function bindStoryScrollPanel(){
    if(!scrollPanel || scrollPanel.dataset.bound === '1') return;
    scrollPanel.dataset.bound = '1';
    ['wheel', 'touchstart', 'touchmove'].forEach(function(evtName){
      scrollPanel.addEventListener(evtName, function(e){
        e.stopPropagation();
      }, {passive:true, capture:true});
    });
    scrollPanel.addEventListener('scroll', function(){
      setPaused(true);
    }, {passive:true});
  }

  function parseStoryMoment(dt){
    dt = String(dt || '').trim();
    if(!dt) return null;
    if(window.moment){
      var m = window.moment(dt);
      return m.isValid() ? m : null;
    }
    var t = Date.parse(dt.replace(' ', 'T'));
    return isNaN(t) ? null : { diffNow: function(unit){
      var now = Date.now();
      if(unit === 'minutes') return Math.floor((now - t) / 60000);
      if(unit === 'hours') return Math.floor((now - t) / 3600000);
      if(unit === 'days') return Math.floor((now - t) / 86400000);
      if(unit === 'weeks') return Math.floor((now - t) / 604800000);
      return 0;
    }, format: function(fmt){
      var d = new Date(t);
      if(fmt === 'MMM D') return d.toLocaleDateString(undefined, { month:'short', day:'numeric' });
      return dt;
    }};
  }

  function timeAgoShort(dt){
    var m = parseStoryMoment(dt);
    if(!m) return '';
    if(window.moment && m.diff){
      var mins = window.moment().diff(m, 'minutes');
      if(mins < 1) return 'now';
      if(mins < 60) return mins + 'm';
      var hrs = window.moment().diff(m, 'hours');
      if(hrs < 24) return hrs + 'h';
      var days = window.moment().diff(m, 'days');
      if(days < 7) return days + 'd';
      var weeks = window.moment().diff(m, 'weeks');
      if(weeks < 5) return weeks + 'w';
      return m.format('MMM D');
    }
    var mins = m.diffNow('minutes');
    if(mins < 1) return 'now';
    if(mins < 60) return mins + 'm';
    var hrs = m.diffNow('hours');
    if(hrs < 24) return hrs + 'h';
    var days = m.diffNow('days');
    if(days < 7) return days + 'd';
    var weeks = m.diffNow('weeks');
    if(weeks < 5) return weeks + 'w';
    return m.format('MMM D');
  }

  function storyHandle(story){
    story = story || {};
    var username = String(story.username || '').trim();
    if(username) return username;
    return String(story.name || 'story').trim() || 'story';
  }

  function detectKind(src, type){
    type = String(type || '').toLowerCase();
    if(type === 'video' || type === 'image' || type === 'gif') return type === 'gif' ? 'image' : type;
    var clean = String(src || '').split('?')[0].split('#')[0].toLowerCase();
    if(clean.match(/\.(mp4|webm|ogg|mov|m4v)$/)) return 'video';
    if(clean.match(/\.(jpg|jpeg|png|gif|webp|bmp|svg)$/)) return 'image';
    return 'image';
  }

  function clearSlideTimer(){
    if(slideTimer){
      clearTimeout(slideTimer);
      slideTimer = null;
    }
  }

  function pauseActiveVideo(){
    if(activeVideo){
      try{ activeVideo.pause(); }catch(e){}
    }
  }

  function playActiveVideo(){
    if(activeVideo && !isPaused){
      try{ activeVideo.play().catch(function(){}); }catch(e){}
    }
  }

  function setPaused(paused){
    isPaused = !!paused;
    wrap.classList.toggle('is-paused', isPaused);
    if(pauseBtn){
      var icon = pauseBtn.querySelector('i');
      if(icon) icon.className = isPaused ? 'icon ion-play' : 'icon ion-pause';
      pauseBtn.title = isPaused ? 'Play' : 'Pause';
      pauseBtn.setAttribute('aria-label', isPaused ? 'Play story' : 'Pause story');
    }
    if(isPaused){
      clearSlideTimer();
      pauseActiveVideo();
      return;
    }
    playActiveVideo();
    var story = currentStory();
    var slide = currentSlide();
    if(story) renderProgress(story.slides.length, slideIndex);
    if(!story || !slide) return;
    var ms = 5500;
    if(activeVideo){
      var dur = Number(activeVideo.duration || 0);
      if(dur > 0) ms = Math.round(dur * 1000);
      else ms = 7000;
    }
    scheduleAutoAdvance(ms, true);
  }

  function renderProgress(total, activeIdx){
    if(!progressEl) return;
    if(!total){
      progressEl.hidden = true;
      progressEl.innerHTML = '';
      return;
    }
    progressEl.hidden = false;
    var segs = progressEl.querySelectorAll('.tt-stories-progress-seg');
    if(segs.length === total){
      for(var i = 0; i < total; i += 1){
        segs[i].classList.remove('is-done', 'is-active', 'is-instant');
        if(i < activeIdx) segs[i].classList.add('is-done');
        else if(i === activeIdx){
          segs[i].classList.add('is-active');
          if(slideNavInstant) segs[i].classList.add('is-instant');
        }
      }
      return;
    }
    var html = '';
    for(var j = 0; j < total; j += 1){
      var cls = 'tt-stories-progress-seg';
      if(j < activeIdx) cls += ' is-done';
      else if(j === activeIdx){
        cls += ' is-active';
        if(slideNavInstant) cls += ' is-instant';
      }
      html += '<div class="'+cls+'"><i></i></div>';
    }
    progressEl.innerHTML = html;
  }

  function currentStory(){
    return catalog[storyIndex] || null;
  }

  function buildStoryDoorMenuHtml(postId, isOwner, isSaved, isArchived){
    postId = Number(postId || 0);
    if(postId <= 0) return '';
    isOwner = !!isOwner;
    isSaved = !!isSaved;
    isArchived = !!isArchived;
    var html = '';
    if(isOwner && !STORY_DOOR_STAFF_READONLY){
      html += '<a class="pcm-item pcm-edit" href="dashboard.php?modal=1&edit='+esc(String(postId))+'" data-create-post-modal="1" role="menuitem">'
        + '<i class="fa fa-edit" aria-hidden="true"></i><span>Edit</span></a>';
      html += '<button type="button" class="pcm-item pcm-archive" role="menuitem" data-post-id="'+esc(String(postId))+'" data-archived="'+(isArchived ? '1' : '0')+'">'
        + '<i class="fa fa-archive" aria-hidden="true"></i><span>'+(isArchived ? 'Unarchive' : 'Archive')+'</span></button>';
      html += '<button type="button" class="pcm-item pcm-delete" role="menuitem" data-post-id="'+esc(String(postId))+'">'
        + '<i class="fa fa-trash" aria-hidden="true"></i><span>Delete</span></button>';
      html += '<div class="pcm-divider" role="separator"></div>';
    } else if(!isOwner){
      html += '<button type="button" class="pcm-item pcm-report is-danger" role="menuitem" data-post-id="'+esc(String(postId))+'">'
        + '<i class="fa fa-flag" aria-hidden="true"></i><span>Report</span></button>';
    }
    html += '<button type="button" class="pcm-item pcm-bookmark'+(isSaved ? ' is-active' : '')+'" role="menuitem" data-post-id="'+esc(String(postId))+'" data-saved="'+(isSaved ? '1' : '0')+'">'
      + '<i class="'+(isSaved ? 'fa fa-bookmark' : 'fa fa-bookmark-o')+'" aria-hidden="true"></i><span>Bookmark</span></button>';
    html += '<button type="button" class="pcm-item pcm-share" role="menuitem" data-post-id="'+esc(String(postId))+'">'
      + '<i class="fa fa-share" aria-hidden="true"></i><span>Share</span></button>';
    html += '<button type="button" class="pcm-item pcm-copy-link" role="menuitem" data-post-id="'+esc(String(postId))+'">'
      + '<i class="fa fa-link" aria-hidden="true"></i><span>Copy link</span></button>';
    return html;
  }

  function syncStoryDoorMenu(){
    ensureDomRefs();
    if(!storyMenuWrap || !storyMenuWrap.isConnected) storyMenuWrap = document.getElementById('ttStoriesMenuWrap');
    if(!storyMenu || !storyMenu.isConnected) storyMenu = document.getElementById('ttStoriesMenu');
    if(!storyMenuWrap || !storyMenu) return;

    var pid = currentPostId();
    var story = currentStory();
    var slide = currentSlide();
    var isOwner = false;
    if(story && Number(story.userId || 0) > 0 && STORY_SHEET_ME_ID > 0){
      isOwner = Number(story.userId) === STORY_SHEET_ME_ID;
    }
    var isSaved = slide ? Number(slide.mySaved || 0) === 1 : false;
    var isArchived = slide ? Number(slide.isArchived || slide.is_archived || 0) === 1 : false;
    var html = buildStoryDoorMenuHtml(pid, isOwner, isSaved, isArchived);

    storyMenuWrap.setAttribute('data-post-id', String(pid || 0));
    storyMenuWrap.setAttribute('data-is-owner', isOwner ? '1' : '0');
    storyMenuWrap.setAttribute('data-menu-surface', STORY_DOOR_MENU_SURFACE);
    storyMenu.innerHTML = html;
    storyMenuWrap.style.display = (pid > 0 && html) ? '' : 'none';

    if(window.MSBPostCardMenu && typeof window.MSBPostCardMenu.syncOnMediaContrast === 'function'){
      try{ window.MSBPostCardMenu.syncOnMediaContrast(storyMenuWrap); }catch(_err){}
    }
  }

  function patchStoryDoorTrackSync(){
    if(!window.MSBPostCardMenu || window.MSBPostCardMenu.__storyDoorTrackPatched) return;
    var orig = window.MSBPostCardMenu.syncPostTrackState;
    if(typeof orig !== 'function') return;
    window.MSBPostCardMenu.syncPostTrackState = function(postId, res){
      orig(postId, res);
      postId = Number(postId || 0);
      if(!postId || !res) return;
      var state = res.state || {};
      var counts = res.counts || res;
      var saved = Number(
        state.saved != null ? state.saved :
        (counts.is_saved != null ? counts.is_saved :
        (counts.my_saved != null ? counts.my_saved : 0))
      ) === 1;
      var shared = Number(
        state.shared != null ? state.shared :
        (counts.is_shared != null ? counts.is_shared :
        (counts.my_shared != null ? counts.my_shared : 0))
      ) === 1;
      var patch = {
        mySaved: saved ? 1 : 0,
        myShared: shared ? 1 : 0
      };
      if(typeof res.share_count !== 'undefined') patch.shareCount = Number(res.share_count || 0);
      if(typeof res.save_count !== 'undefined') patch.saveCount = Number(res.save_count || 0);
      updateSlidePublisherState(postId, patch);
      if(currentPostId() === postId) syncStoryDoorMenu();
    };
    window.MSBPostCardMenu.__storyDoorTrackPatched = true;
  }

  function currentSlide(){
    var story = currentStory();
    if(!story || !Array.isArray(story.slides)) return null;
    return story.slides[slideIndex] || null;
  }

  function currentPostId(){
    var slide = currentSlide();
    return slide ? Number(slide.postId || 0) : 0;
  }

  function storyFriendCode(){
    var story = currentStory();
    if(story && story.friendCode) return String(story.friendCode).trim().toUpperCase();
    var slide = currentSlide();
    if(slide && slide.friendCode) return String(slide.friendCode).trim().toUpperCase();
    return '';
  }

  function storyMediaMime(src, kind){
    src = String(src || '').toLowerCase();
    kind = String(kind || '').toLowerCase();
    if(kind === 'video' || src.match(/\.(mp4|webm|ogg|mov|m4v)$/)) return 'video/mp4';
    if(src.indexOf('.png') >= 0) return 'image/png';
    if(src.indexOf('.webp') >= 0) return 'image/webp';
    if(src.indexOf('.gif') >= 0) return 'image/gif';
    return 'image/jpeg';
  }

  function storyMediaPayload(){
    var slide = currentSlide();
    if(!slide) return null;
    var src = String(slide.src || '').trim().replace(/^public_user\//,'').replace(/^\.\//,'');
    if(!src) return null;
    var kind = detectKind(src, slide.type);
    return {
      url: src,
      type: storyMediaMime(src, kind),
      original: src.split('/').pop() || 'story-media'
    };
  }

  function formatStoryCompactCount(n){
    n = Number(n || 0);
    if(!isFinite(n) || n < 0) return '0';
    if(n >= 1000000){
      var mv = n / 1000000;
      return (mv >= 10 ? String(Math.round(mv)) : mv.toFixed(1).replace(/\.0$/, '')) + 'M';
    }
    if(n >= 10000) return String(Math.round(n / 1000)) + 'K';
    if(n >= 1000){
      var kv = n / 1000;
      return kv.toFixed(1).replace(/\.0$/, '') + 'K';
    }
    return String(n);
  }

  function slideActionCounts(slide){
    slide = slide || {};
    return {
      commentCount: Number(slide.commentCount != null ? slide.commentCount : (slide.comment_count || 0)),
      loveCount: Number(slide.loveCount != null ? slide.loveCount : (slide.love_count || 0)),
      shareCount: Number(slide.shareCount != null ? slide.shareCount : (slide.share_count || 0)),
      saveCount: Number(slide.saveCount != null ? slide.saveCount : (slide.save_count || 0))
    };
  }

  function setStoryActionCountEl(el, value){
    if(!el) return;
    el.textContent = formatStoryCompactCount(value);
  }

  function applyUserStoryActionCounts(slide){
    if(isPublisherStory()) return;
    var counts = slideActionCounts(slide || currentSlide());
    setStoryActionCountEl(storyLoveCountEl, counts.loveCount);
  }

  function applyStoryActionCounts(slide){
    slide = slide || currentSlide();
    if(isPublisherStory()) applyPublisherActionCounts(slide);
    else applyUserStoryActionCounts(slide);
  }

  function applyPublisherActionCounts(slide){
    var counts = slideActionCounts(slide || currentSlide());
    setStoryActionCountEl(storyPubCommentCountEl, counts.commentCount);
    setStoryActionCountEl(storyPubLoveCountEl, counts.loveCount);
    setStoryActionCountEl(storyPubShareCountEl, counts.shareCount);
    setStoryActionCountEl(storyPubSaveCountEl, counts.saveCount);
  }

  function storyPatchSlideCounts(patch){
    patch = patch || {};
    var out = {};
    if(Object.prototype.hasOwnProperty.call(patch, 'commentCount') || Object.prototype.hasOwnProperty.call(patch, 'comment_count')){
      out.commentCount = Number(patch.commentCount != null ? patch.commentCount : patch.comment_count || 0);
    }
    if(Object.prototype.hasOwnProperty.call(patch, 'loveCount') || Object.prototype.hasOwnProperty.call(patch, 'love_count')){
      out.loveCount = Number(patch.loveCount != null ? patch.loveCount : patch.love_count || 0);
    }
    if(Object.prototype.hasOwnProperty.call(patch, 'shareCount') || Object.prototype.hasOwnProperty.call(patch, 'share_count')){
      out.shareCount = Number(patch.shareCount != null ? patch.shareCount : patch.share_count || 0);
    }
    if(Object.prototype.hasOwnProperty.call(patch, 'saveCount') || Object.prototype.hasOwnProperty.call(patch, 'save_count')){
      out.saveCount = Number(patch.saveCount != null ? patch.saveCount : patch.save_count || 0);
    }
    return out;
  }

  function isPublisherStory(story){
    story = story || currentStory();
    if(!story) return false;
    if(story.isPublisher === true || Number(story.isPublisher || 0) === 1) return true;
    if(story.is_publisher === true || Number(story.is_publisher || 0) === 1) return true;
    var code = String(story.friendCode || '').trim().toUpperCase();
    return code.indexOf('PUB-') === 0;
  }

  function storyLoveButtons(){
    return [storyLoveBtn, storyPubLoveBtn].filter(function(btn){ return !!btn; });
  }

  function setPublisherFootEnabled(on){
    [storyPubCommentBtn, storyPubLoveBtn, storyPubShareBtn, storyPubSaveBtn].forEach(function(btn){
      if(btn) btn.disabled = !on;
    });
  }

  function syncStoryFootMode(){
    var publisherMode = isPublisherStory();
    wrap.classList.toggle('is-publisher-story', publisherMode);
    if(storyPublisherFoot){
      storyPublisherFoot.setAttribute('aria-hidden', publisherMode ? 'false' : 'true');
    }
    if(publisherMode){
      var pid = currentPostId();
      if(storyPostId) storyPostId.value = String(pid || 0);
      applyStoryLoveState(currentSlide() ? String(currentSlide().myReaction || '') : '');
      setPublisherFootEnabled(!!pid);
      applyPublisherActionCounts(currentSlide());
      try{ applyPublisherShareSaveState(); }catch(_err){}
      return;
    }
    syncStoryFoot();
  }

  function applyPublisherShareSaveState(){
    var slide = currentSlide();
    if(!slide) return;
    var shared = Number(slide.myShared || 0) === 1;
    var saved = Number(slide.mySaved || 0) === 1;
    if(storyPubShareBtn){
      storyPubShareBtn.classList.toggle('is-share', shared);
      storyPubShareBtn.classList.toggle('is-active', shared);
      var shareIcon = storyPubShareBtn.querySelector('i');
      if(shareIcon) shareIcon.className = shared ? 'fa fa-paper-plane' : 'fa fa-paper-plane-o';
    }
    if(storyPubSaveBtn){
      storyPubSaveBtn.classList.toggle('is-save', saved);
      storyPubSaveBtn.classList.toggle('is-active', saved);
      var saveIcon = storyPubSaveBtn.querySelector('i');
      if(saveIcon) saveIcon.className = saved ? 'fa fa-bookmark' : 'fa fa-bookmark-o';
    }
  }

  function updateSlidePublisherState(postId, patch){
    postId = Number(postId || 0);
    if(!postId || !patch || typeof patch !== 'object') return;
    var countPatch = storyPatchSlideCounts(patch);
    catalog.forEach(function(story){
      if(!Array.isArray(story.slides)) return;
      story.slides.forEach(function(slide){
        if(Number(slide.postId || 0) !== postId) return;
        if(Object.prototype.hasOwnProperty.call(patch, 'myReaction')) slide.myReaction = String(patch.myReaction || '');
        if(Object.prototype.hasOwnProperty.call(patch, 'myShared')) slide.myShared = Number(patch.myShared || 0) ? 1 : 0;
        if(Object.prototype.hasOwnProperty.call(patch, 'mySaved')) slide.mySaved = Number(patch.mySaved || 0) ? 1 : 0;
        if(Object.prototype.hasOwnProperty.call(countPatch, 'commentCount')) slide.commentCount = countPatch.commentCount;
        if(Object.prototype.hasOwnProperty.call(countPatch, 'loveCount')) slide.loveCount = countPatch.loveCount;
        if(Object.prototype.hasOwnProperty.call(countPatch, 'shareCount')) slide.shareCount = countPatch.shareCount;
        if(Object.prototype.hasOwnProperty.call(countPatch, 'saveCount')) slide.saveCount = countPatch.saveCount;
      });
    });
    if(currentPostId() === postId){
      if(Object.prototype.hasOwnProperty.call(patch, 'myReaction')) applyStoryLoveState(String(patch.myReaction || ''));
      if(Object.prototype.hasOwnProperty.call(patch, 'myShared') || Object.prototype.hasOwnProperty.call(patch, 'mySaved')) applyPublisherShareSaveState();
      if(Object.keys(countPatch).length) applyStoryActionCounts(currentSlide());
    }
  }

  function storyFeedPost(action, postId, cb){
    postId = Number(postId || 0);
    if(!postId) return;
    var body = 'ajax=' + encodeURIComponent(String(action || '')) + '&post_id=' + encodeURIComponent(String(postId));
    fetch('feed_api.php', {
      method:'POST',
      headers:{ 'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8' },
      body: body,
      cache:'no-store',
      credentials:'same-origin'
    })
      .then(function(r){ return r.json(); })
      .then(function(data){
        if(typeof cb === 'function') cb(data || { ok:false });
      })
      .catch(function(){
        if(typeof cb === 'function') cb({ ok:false });
      });
  }

  function storyCommentAvatarUrl(c, size){
    c = c || {};
    size = Number(size || 72);
    var params = [];
    var uid = Number(c.user_id || c.uid || 0);
    var username = String(c.username || '').trim();
    var friendCode = String(c.friend_code || '').trim();
    var email = String(c.email || '').trim();
    var name = String(c.display_name || c.author_name || c.fullname || username || 'User').trim();
    if(uid > 0) params.push('u=' + encodeURIComponent(String(uid)));
    if(email) params.push('email=' + encodeURIComponent(email));
    if(friendCode) params.push('friend_code=' + encodeURIComponent(friendCode));
    if(username) params.push('username=' + encodeURIComponent(username));
    if(name) params.push('name=' + encodeURIComponent(name));
    params.push('s=' + encodeURIComponent(String(size)));
    return 'avatar.php?' + params.join('&');
  }

  function storyCommentTimeAgo(ts){
    var t = Date.parse(ts || '');
    if(!t) return '';
    var sec = Math.floor((Date.now() - t) / 1000);
    if(sec < 60) return sec + 's';
    var m = Math.floor(sec / 60); if(m < 60) return m + 'm';
    var h = Math.floor(m / 60); if(h < 24) return h + 'h';
    var d = Math.floor(h / 24); if(d < 7) return d + 'd';
    var w = Math.floor(d / 7); if(w < 4) return w + 'w';
    var mo = Math.floor(d / 30); if(mo < 12) return mo + 'mo';
    return Math.floor(d / 365) + 'y';
  }

  function storyNormalizeComment(c){
    return {
      id: Number(c.id || c.comment_id || 0),
      user_id: Number(c.user_id || c.uid || 0),
      parent_id: Number(c.parent_id || c.parentId || 0),
      username: String(c.username || ''),
      display_name: String(c.display_name || c.author_name || c.username || c.fullname || 'User'),
      friend_code: String(c.friend_code || ''),
      email: String(c.email || ''),
      comment_text: String(c.comment_text || c.body || c.text || ''),
      created_at: String(c.created_at || c.createdAt || ''),
      me_liked: Number(c.me_liked || c.meLiked || 0),
      like_count: Number(c.like_count || c.likeCount || 0)
    };
  }

  function storyHighlightMentions(text){
    return esc(text).replace(/@([A-Za-z0-9_\.]+)/g, '<span class="tt-story-cmt-mention">@$1</span>');
  }

  function storySetCommentReply(parentId, who){
    parentId = Number(parentId || 0);
    if(storyCmtParentId) storyCmtParentId.value = String(parentId);
    if(parentId > 0){
      if(storyCmtReplying) storyCmtReplying.hidden = false;
      if(storyCmtReplyingText) storyCmtReplyingText.textContent = 'Replying to ' + String(who || 'comment');
      if(storyCmtInput) storyCmtInput.placeholder = 'Reply to ' + String(who || 'comment') + '...';
    }else{
      if(storyCmtReplying) storyCmtReplying.hidden = true;
      if(storyCmtReplyingText) storyCmtReplyingText.textContent = '';
      if(storyCmtInput) storyCmtInput.placeholder = STORY_CMT_PLACEHOLDER;
    }
    syncStoryCmtSendState();
  }

  function syncStoryCmtSendState(){
    if(!storyCmtSend) return;
    var hasText = String(storyCmtInput && storyCmtInput.value || '').trim().length > 0;
    var inputDisabled = !!(storyCmtInput && storyCmtInput.disabled);
    storyCmtSend.disabled = inputDisabled || !hasText;
  }

  function storyCommentRowHtml(c, depth){
    depth = Number(depth || 0);
    var liked = Number(c.me_liked || 0) === 1;
    var likeCount = Number(c.like_count || 0);
    var when = storyCommentTimeAgo(c.created_at) || '';
    var avatar = storyCommentAvatarUrl(c, 72);
    var replyCount = (storyCommentsByParent[c.id] || []).length;
    var repliesOpen = !storyCollapsedReplyIds[c.id];
    var childrenHtml = '';
    if(replyCount > 0 && repliesOpen){
      childrenHtml = '<div class="tt-story-cmt-children">' + storyThreadHtml(storyCommentsByParent[c.id] || [], depth + 1) + '</div>';
    }
    var viewReplies = '';
    if(replyCount > 0 && !repliesOpen){
      var noun = replyCount === 1 ? 'reply' : 'replies';
      viewReplies = '<button type="button" class="tt-story-cmt-view-replies" data-toggle-story-replies="'+c.id+'">View '+replyCount+' more '+noun+'</button>';
    }
    return ''+
      '<div class="tt-story-cmt-row" data-cid="'+c.id+'">'+
        '<div class="tt-story-cmt-avatar"><img src="'+esc(avatar)+'" alt="'+esc(c.display_name)+'"></div>'+
        '<div class="tt-story-cmt-main">'+
          '<div class="tt-story-cmt-topline">'+
            '<span class="tt-story-cmt-name">'+esc(c.display_name)+'</span>'+
            (when ? '<span class="tt-story-cmt-time">'+esc(when)+'</span>' : '')+
          '</div>'+
          '<div class="tt-story-cmt-text">'+storyHighlightMentions(c.comment_text)+'</div>'+
          '<div class="tt-story-cmt-actions">'+
            '<button type="button" class="tt-story-cmt-reply-btn" data-story-reply="'+c.id+'" data-story-reply-who="'+esc(c.display_name)+'">Reply</button>'+
          '</div>'+
          viewReplies+
          childrenHtml+
        '</div>'+
        '<div class="tt-story-cmt-like-col">'+
          '<button type="button" class="tt-story-cmt-like-btn'+(liked ? ' is-liked' : '')+'" data-story-like="'+c.id+'" aria-label="Like comment">'+
            '<i class="fa '+(liked ? 'fa-heart' : 'fa-heart-o')+'"></i>'+
          '</button>'+
          (likeCount > 0 ? '<span class="tt-story-cmt-like-count">'+likeCount+'</span>' : '<span class="tt-story-cmt-like-count"></span>')+
        '</div>'+
      '</div>';
  }

  function storyThreadHtml(nodes, depth){
    return (nodes || []).map(function(node){
      return storyCommentRowHtml(node, depth);
    }).join('');
  }

  function storyRenderComments(comments){
    comments = Array.isArray(comments) ? comments.map(storyNormalizeComment) : [];
    storyCommentsCache = comments;
    if(!storyCmtList) return;

    if(!comments.length){
      storyCmtList.innerHTML = '<div class="tt-story-cmt-empty">No comments yet. Be the first to comment.</div>';
      return;
    }

    var byId = {};
    comments.forEach(function(c){ byId[c.id] = Object.assign({}, c, { _replies: [] }); });
    var top = [];
    Object.keys(byId).forEach(function(key){
      var c = byId[key];
      if(c.parent_id > 0 && byId[c.parent_id]) byId[c.parent_id]._replies.push(c);
      else top.push(c);
    });
    storyCommentsByParent = {};
    Object.keys(byId).forEach(function(key){
      storyCommentsByParent[byId[key].id] = byId[key]._replies;
    });
    storyCmtList.innerHTML = storyThreadHtml(top, 0);
    try{ storyCmtList.scrollTop = storyCmtList.scrollHeight; }catch(_e){}
  }

  function storyCollapseRepliesByDefault(comments){
    storyCollapsedReplyIds = {};
    (Array.isArray(comments) ? comments : []).forEach(function(c){
      c = storyNormalizeComment(c);
      if(c.parent_id > 0) storyCollapsedReplyIds[c.parent_id] = true;
    });
  }

  function storyLoadComments(postId){
    postId = Number(postId || 0);
    if(!postId || !storyCmtList) return;
    storyCommentsPostId = postId;
    if(storyCmtPostId) storyCmtPostId.value = String(postId);
    storyCmtList.innerHTML = '<div class="tt-story-cmt-empty">Loading comments...</div>';
    fetch('feed_api.php?ajax=view&id=' + encodeURIComponent(String(postId)) + '&post_id=' + encodeURIComponent(String(postId)) + '&count_view=0', {
      method:'GET',
      cache:'no-store',
      credentials:'same-origin'
    })
      .then(function(r){ return r.json(); })
      .then(function(res){
        var comments = (res && res.ok && Array.isArray(res.comments)) ? res.comments : [];
        storyCollapseRepliesByDefault(comments);
        storyRenderComments(comments);
        updateSlidePublisherState(postId, { commentCount: comments.length });
      })
      .catch(function(){
        storyCmtList.innerHTML = '<div class="tt-story-cmt-empty">Unable to load comments.</div>';
      });
  }

  function storyRefreshComments(){
    if(storyCommentsPostId > 0) storyLoadComments(storyCommentsPostId);
  }

  function closeStoryCommentsSheet(){
    if(!storyCmtSheet) return;
    storyCommentsOpen = false;
    storyCmtSheet.classList.remove('is-open');
    storyCmtSheet.setAttribute('aria-hidden', 'true');
    storySetCommentReply(0, '');
    if(storyCmtInput) storyCmtInput.value = '';
    syncStoryCmtSendState();
    if(isPaused && wrap && wrap.classList.contains('is-open')) setPaused(false);
  }

  function openStoryComments(){
    ensureDomRefs();
    var pid = currentPostId();
    if(!pid || !storyCmtSheet) return;
    setPaused(true);
    storyCommentsOpen = true;
    storySetCommentReply(0, '');
    if(storyCmtInput) storyCmtInput.value = '';
    syncStoryCmtSendState();
    storyCmtSheet.classList.add('is-open');
    storyCmtSheet.setAttribute('aria-hidden', 'false');
    storyLoadComments(pid);
    window.setTimeout(function(){
      if(storyCmtInput) storyCmtInput.focus();
    }, 220);
  }
  function setStoryFootEnabled(on){
    if(storyText) storyText.disabled = !on;
    storyLoveButtons().forEach(function(btn){
      btn.disabled = !on;
    });
    if(storyForm){
      var sendBtn = storyForm.querySelector('.tt-stories-send');
      if(sendBtn) sendBtn.disabled = !on;
    }
  }

  function applyStoryLoveState(reaction){
    reaction = String(reaction || '');
    currentStoryReaction = reaction;
    var loved = reaction === 'love';
    var buttons = storyLoveButtons();
    if(window.MSBReactions && typeof window.MSBReactions.applyReactionButton === 'function'){
      buttons.forEach(function(btn){
        btn.setAttribute('data-reaction', reaction);
        window.MSBReactions.applyReactionButton(btn, reaction, 'love');
      });
      return;
    }
    buttons.forEach(function(btn){
      btn.setAttribute('data-reaction', reaction);
      btn.classList.toggle('is-loved', loved);
      btn.classList.toggle('liked', loved);
      btn.classList.toggle('rx-active', loved);
      var icon = btn.querySelector('i');
      if(icon){
        if(btn === storyPubLoveBtn || btn === storyLoveBtn) icon.className = loved ? 'fa fa-heart' : 'fa fa-heart-o';
        else icon.className = loved ? 'fa fa-heart' : 'fa fa-heart-o';
      }
    });
  }

  function syncStoryFoot(){
    var story = currentStory();
    var pid = currentPostId();
    if(storyPostId) storyPostId.value = String(pid || 0);
    var slide = currentSlide();
    applyStoryLoveState(slide ? String(slide.myReaction || '') : '');
    applyUserStoryActionCounts(slide);
    setStoryFootEnabled(!!pid);
    if(storyText){
      var handle = storyHandle(story);
      storyText.placeholder = 'Reply to ' + handle + '...';
    }
  }

  function updateSlideReaction(postId, reaction){
    postId = Number(postId || 0);
    reaction = String(reaction || '');
    catalog.forEach(function(story){
      if(!Array.isArray(story.slides)) return;
      story.slides.forEach(function(slide){
        if(Number(slide.postId || 0) === postId) slide.myReaction = reaction;
      });
    });
    if(currentPostId() === postId) applyStoryLoveState(reaction);
  }

  function scheduleAutoAdvance(ms, skipPauseCheck){
    if(isPaused && !skipPauseCheck) return;
    clearSlideTimer();
    slideTimer = setTimeout(function(){
      if(!isPaused) TTStories.nextSlide();
    }, Math.max(1800, Number(ms || 5000)));
  }

  function ensureDomRefs(){
    if(!wrap || !wrap.isConnected) wrap = document.getElementById('tt-stories-wrap');
    if(!mediaEl || !mediaEl.isConnected) mediaEl = document.getElementById('ttStoriesMedia');
    if(!titleEl || !titleEl.isConnected) titleEl = document.getElementById('ttStoriesTitle');
    if(!timeEl || !timeEl.isConnected) timeEl = document.getElementById('ttStoriesTime');
    if(!verifiedEl || !verifiedEl.isConnected) verifiedEl = document.getElementById('ttStoriesVerified');
    if(!avatarEl || !avatarEl.isConnected) avatarEl = document.getElementById('ttStoriesAvatar');
    if(!progressEl || !progressEl.isConnected) progressEl = document.getElementById('ttStoriesProgress');
    if(!captionEl || !captionEl.isConnected) captionEl = document.getElementById('ttStoriesCaption');
    if(!scrollPanel || !scrollPanel.isConnected) scrollPanel = document.getElementById('ttStoriesScrollPanel');
    if(!scrollInner || !scrollInner.isConnected) scrollInner = document.getElementById('ttStoriesScrollInner');
    if(!storyCmtSheet || !storyCmtSheet.isConnected) storyCmtSheet = document.getElementById('ttStoryCmtSheet');
    if(!storyCmtList || !storyCmtList.isConnected) storyCmtList = document.getElementById('ttStoryCmtList');
    if(!storyCmtInput || !storyCmtInput.isConnected) storyCmtInput = document.getElementById('ttStoryCmtInput');
    if(!storyPubCommentBtn || !storyPubCommentBtn.isConnected) storyPubCommentBtn = document.getElementById('ttStoriesPubComment');
  }

  function resolveStoryMediaUrl(src){
    src = String(src || '').trim().replace(/^public_user\//,'').replace(/^\.\//,'');
    return src;
  }

  function ensureMediaBuffers(){
    ensureDomRefs();
    if(!mediaEl) return;
    if(!mediaBufferA || !mediaBufferA.isConnected) mediaBufferA = document.getElementById('ttStoriesMediaA');
    if(!mediaBufferB || !mediaBufferB.isConnected) mediaBufferB = document.getElementById('ttStoriesMediaB');
    if(!mediaBufferA || !mediaBufferB){
      mediaEl.innerHTML = '';
      mediaBufferA = document.createElement('div');
      mediaBufferA.className = 'tt-stories-media-buffer is-front';
      mediaBufferA.id = 'ttStoriesMediaA';
      mediaBufferB = document.createElement('div');
      mediaBufferB.className = 'tt-stories-media-buffer';
      mediaBufferB.id = 'ttStoriesMediaB';
      mediaEl.appendChild(mediaBufferA);
      mediaEl.appendChild(mediaBufferB);
      mediaBufferFrontIsA = true;
    }
  }

  function getFrontMediaBuffer(){
    ensureMediaBuffers();
    return mediaBufferFrontIsA ? mediaBufferA : mediaBufferB;
  }

  function getBackMediaBuffer(){
    ensureMediaBuffers();
    return mediaBufferFrontIsA ? mediaBufferB : mediaBufferA;
  }

  function flipMediaBuffer(){
    var back = getBackMediaBuffer();
    var front = getFrontMediaBuffer();
    back.classList.add('is-front');
    front.classList.remove('is-front');
    mediaBufferFrontIsA = !mediaBufferFrontIsA;
  }

  function showMediaNode(node){
    if(!mediaEl) return;
    ensureMediaBuffers();
    var back = getBackMediaBuffer();
    back.innerHTML = '';
    if(node) back.appendChild(node);
    flipMediaBuffer();
  }

  function showMediaEmptyMessage(msg){
    if(!mediaEl) return;
    ensureMediaBuffers();
    mediaEl.classList.remove('is-text-backdrop');
    var back = getBackMediaBuffer();
    back.innerHTML = '<div class="tt-stories-empty">'+esc(msg || 'No story media available.')+'</div>';
    flipMediaBuffer();
  }

  function showTextStoryBackdrop(){
    if(!mediaEl) return;
    ensureMediaBuffers();
    var back = getBackMediaBuffer();
    back.innerHTML = '';
    mediaEl.classList.add('is-text-backdrop');
    flipMediaBuffer();
  }

  function resetMediaBuffers(){
    mediaBufferA = null;
    mediaBufferB = null;
    mediaBufferFrontIsA = true;
  }

  function setMediaEmptyMessage(msg){
    showMediaEmptyMessage(msg);
  }

  function mountStoryImage(src, callback){
    src = resolveStoryMediaUrl(src);
    if(!src){
      callback(null);
      return;
    }
    var loader = new Image();
    var finished = false;
    function finish(img){
      if(finished) return;
      finished = true;
      callback(img);
    }
    loader.onload = function(){
      var img = document.createElement('img');
      img.src = src;
      img.alt = '';
      img.decoding = 'async';
      finish(img);
    };
    loader.onerror = function(){ finish(null); };
    loader.src = src;
    if(loader.complete){
      loader.onload();
    }
  }

  function mountStoryVideo(src, callback){
    src = resolveStoryMediaUrl(src);
    if(!src){
      callback(null);
      return;
    }
    var loader = document.createElement('video');
    var finished = false;
    function finish(video){
      if(finished) return;
      finished = true;
      callback(video);
    }
    loader.muted = true;
    loader.playsInline = true;
    loader.preload = 'auto';
    loader.addEventListener('loadeddata', function(){
      var video = document.createElement('video');
      video.muted = true;
      video.playsInline = true;
      video.preload = 'metadata';
      video.src = src;
      finish(video);
    }, {once:true});
    loader.addEventListener('error', function(){ finish(null); }, {once:true});
    loader.src = src;
    if(loader.readyState >= 2){
      var readyVideo = document.createElement('video');
      readyVideo.muted = true;
      readyVideo.playsInline = true;
      readyVideo.preload = 'metadata';
      readyVideo.src = src;
      finish(readyVideo);
    }
  }

  function bindStoryVideo(video, slideText){
    activeVideo = video;
    if(slideText){
      setStoryScrollPanel(slideText, 'media-caption');
      setPaused(true);
    }
    if(!video) return;
    video.addEventListener('loadedmetadata', function onMeta(){
      video.removeEventListener('loadedmetadata', onMeta);
      var dur = Number(video.duration || 0);
      scheduleAutoAdvance(dur > 0 ? Math.round(dur * 1000) : 7000);
      playActiveVideo();
    });
    if(video.readyState >= 1){
      var durNow = Number(video.duration || 0);
      scheduleAutoAdvance(durNow > 0 ? Math.round(durNow * 1000) : 7000);
      playActiveVideo();
    }
  }

  function renderStoryMediaContent(slide){
    ensureDomRefs();
    if(!mediaEl) return;
    var renderToken = ++mediaRenderToken;

    var src = resolveStoryMediaUrl(slide ? slide.src : '');
    var kind = detectKind(src, slide ? slide.type : '');
    var slideText = storySlideText(slide);

    if(captionEl) captionEl.textContent = '';

    if(!src){
      if(slideText){
        showTextStoryBackdrop();
        setStoryScrollPanel(slideText, 'full');
        scheduleAutoAdvance(storyReadDurationMs(slideText));
        setPaused(true);
        return;
      }
      setMediaEmptyMessage('Story unavailable.');
      scheduleAutoAdvance(2500);
      return;
    }

    mediaEl.classList.remove('is-text-backdrop');
    if(!slideText) setStoryScrollPanel('', false);

    if(kind === 'video'){
      mountStoryVideo(src, function(video){
        if(renderToken !== mediaRenderToken) return;
        if(!video){
          setMediaEmptyMessage('Story unavailable.');
          scheduleAutoAdvance(2500);
          return;
        }
        showMediaNode(video);
        bindStoryVideo(video, slideText);
      });
      return;
    }

    mountStoryImage(src, function(img){
      if(renderToken !== mediaRenderToken) return;
      if(!img){
        setMediaEmptyMessage('Story unavailable.');
        scheduleAutoAdvance(2500);
        return;
      }
      showMediaNode(img);
      activeVideo = null;
      if(slideText){
        setStoryScrollPanel(slideText, 'media-caption');
        scheduleAutoAdvance(storyReadDurationMs(slideText));
        setPaused(true);
        return;
      }
      scheduleAutoAdvance(5500);
    });
  }

  function syncStoryNavState(){
    if(!prevBtn || !prevBtn.isConnected) prevBtn = document.getElementById('ttStoriesPrev');
    if(!nextBtn || !nextBtn.isConnected) nextBtn = document.getElementById('ttStoriesNext');
    if(!prevBtn || !nextBtn) return;

    var canPrev = slideIndex > 0 || storyIndex > 0;
    var story = currentStory();
    var slides = story && Array.isArray(story.slides) ? story.slides : [];
    var canNext = slideIndex < slides.length - 1 || storyIndex < catalog.length - 1;

    prevBtn.disabled = !canPrev;
    nextBtn.disabled = !canNext;
    prevBtn.classList.toggle('is-disabled', !canPrev);
    nextBtn.classList.toggle('is-disabled', !canNext);
  }

  function renderSlide(){
    ensureDomRefs();
    if(slideNavInstant && wrap) wrap.classList.add('is-slide-changing');
    closeStoryCommentsSheet();
    pauseActiveVideo();
    clearSlideTimer();
    if(!slideNavInstant){
      isPaused = false;
      if(wrap) wrap.classList.remove('is-paused');
      if(pauseBtn){
        var pauseIcon = pauseBtn.querySelector('i');
        if(pauseIcon) pauseIcon.className = 'icon ion-pause';
      }
    }

    var story = currentStory();
    var slide = currentSlide();
    if(!story || !slide){
      setMediaEmptyMessage('No story media available.');
      setStoryScrollPanel('', false);
      if(captionEl) captionEl.textContent = '';
      if(titleEl) titleEl.textContent = 'Story';
      if(timeEl) timeEl.textContent = '';
      if(verifiedEl) verifiedEl.hidden = true;
      setStoryFootEnabled(false);
      setPublisherFootEnabled(false);
      renderProgress(0, 0);
      syncStoryNavState();
      try{ syncStoryDoorMenu(); }catch(_err){}
      return;
    }

    renderStoryMediaContent(slide);

    var handle = storyHandle(story);
    if(titleEl) titleEl.textContent = handle;
    if(timeEl){
      var ago = String(slide.timeAgo || slide.timeLabel || '').trim();
      if(!ago && slide.createdAt) ago = timeAgoShort(slide.createdAt);
      timeEl.textContent = ago;
    }
    if(verifiedEl){
      var verified = !!(story.verified || story.isVerified);
      verifiedEl.hidden = !verified;
      verifiedEl.setAttribute('aria-hidden', verified ? 'false' : 'true');
    }
    if(avatarEl){
      var img = avatarEl.querySelector('img');
      if(img){
        img.src = String(story.avatarUrl || '');
        img.alt = String(story.name || handle);
      }
    }

    try{ syncStoryFootMode(); }catch(_err){}
    try{ syncStoryDoorMenu(); }catch(_err){}
    var slides = Array.isArray(story.slides) ? story.slides : [];
    renderProgress(slides.length, slideIndex);
    syncStoryNavState();
    if(slideNavInstant && wrap){
      requestAnimationFrame(function(){
        requestAnimationFrame(function(){
          wrap.classList.remove('is-slide-changing');
          slideNavInstant = false;
        });
      });
    } else {
      slideNavInstant = false;
    }
  }

  function setActiveRing(storyKey){
    document.querySelectorAll('.ig-story-item').forEach(function(item){
      var key = String(item.getAttribute('data-story-key') || '');
      item.classList.toggle('is-viewing', !!storyKey && key === String(storyKey));
    });
  }

  function openPanel(){
    ensureDomRefs();
    if(!wrap) return;
    wrap.classList.add('is-open');
    wrap.setAttribute('aria-hidden', 'false');
    document.body.classList.add('tt-stories-open');
  }

  function closePanel(){
    ensureDomRefs();
    closeStoryCommentsSheet();
    pauseActiveVideo();
    clearSlideTimer();
    activeVideo = null;
    isPaused = false;
    if(wrap) wrap.classList.remove('is-open', 'is-paused', 'has-story-text', 'is-publisher-story');
    if(wrap) wrap.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('tt-stories-open');
    setActiveRing('');
    storyIndex = -1;
    slideIndex = 0;
    if(storyText){
      storyText.value = '';
      storyText.placeholder = defaultReplyPlaceholder;
    }
    if(storyPostId) storyPostId.value = '0';
    applyStoryLoveState('');
    setStoryFootEnabled(false);
    setPublisherFootEnabled(false);
    setStoryScrollPanel('', false);
    if(mediaEl){
      mediaEl.classList.remove('is-text-backdrop');
      mediaEl.innerHTML = '';
      resetMediaBuffers();
      ensureMediaBuffers();
    }
    if(titleEl) titleEl.textContent = 'Story';
    if(timeEl) timeEl.textContent = '';
    if(verifiedEl) verifiedEl.hidden = true;
    renderProgress(0, 0);
    try{ syncStoryDoorMenu(); }catch(_err){}
    if(window.MSBPostCardMenu && typeof window.MSBPostCardMenu.closeAll === 'function'){
      try{ window.MSBPostCardMenu.closeAll(); }catch(_err){}
    }
  }

  function openStoryAt(index){
    index = Number(index);
    if(!catalog.length || index < 0 || index >= catalog.length) return;
    storyIndex = index;
    slideIndex = 0;
    setActiveRing(String(catalog[index].key || index));
    renderSlide();
    openPanel();
  }

  function openStoryByKey(key){
    key = String(key || '').trim();
    if(!key) return false;
    for(var i = 0; i < catalog.length; i += 1){
      if(String(catalog[i].key || '') === key){
        openStoryAt(i);
        return true;
      }
    }
    return false;
  }

  function openStoryFromItem(item){
    if(!item) return false;
    if(item.classList && (item.classList.contains('ig-story-create') || item.classList.contains('ig-story-empty'))) return false;
    var key = String(item.getAttribute('data-story-key') || '').trim();
    if(key && openStoryByKey(key)) return true;
    var idx = Number(item.getAttribute('data-story-index'));
    if(!isNaN(idx) && idx >= 0 && idx < catalog.length){
      openStoryAt(idx);
      return true;
    }
    return false;
  }

  function bindStoryCircleClicks(){
    if(document.documentElement.dataset.ttStoriesBound === '1') return;
    document.documentElement.dataset.ttStoriesBound = '1';
    document.addEventListener('click', function(e){
      var target = e.target;
      if(!target || !target.closest) return;
      var item = target.closest('.ig-story-item[data-story-key]');
      if(!item) return;
      if(item.classList.contains('ig-story-create') || item.classList.contains('ig-story-empty')) return;
      e.preventDefault();
      e.stopPropagation();
      openStoryFromItem(item);
    }, true);
  }

  function removePostFromCatalog(postId){
    postId = Number(postId || 0);
    if(!postId) return;
    var removedCurrent = false;
    catalog = catalog.map(function(story){
      if(!story || !Array.isArray(story.slides)) return story;
      var nextSlides = story.slides.filter(function(slide){
        return Number(slide.postId || 0) !== postId;
      });
      if(nextSlides.length !== story.slides.length && storyIndex >= 0){
        var curStory = catalog[storyIndex];
        if(curStory && String(curStory.key || '') === String(story.key || '') && Number(currentPostId()) === postId){
          removedCurrent = true;
        }
      }
      story.slides = nextSlides;
      return story;
    }).filter(function(story){
      return story && Array.isArray(story.slides) && story.slides.length;
    });
    if(removedCurrent){
      if(catalog.length){
        if(storyIndex >= catalog.length) storyIndex = catalog.length - 1;
        slideIndex = 0;
        renderSlide();
        openPanel();
      } else {
        closePanel();
      }
      return;
    }
    if(wrap && wrap.classList.contains('is-open')) syncStoryDoorMenu();
  }

  window.TTStories = {
    setCatalog: function(items){
      catalog = Array.isArray(items) ? items.slice(0) : [];
    },
    getCatalog: function(){ return catalog.slice(0); },
    openByKey: function(key){
      return openStoryByKey(key);
    },
    openByIndex: function(index){ openStoryAt(Number(index)); },
    open: function(keyOrIndex){
      if(typeof keyOrIndex === 'number') return openStoryAt(keyOrIndex);
      return this.openByKey(keyOrIndex);
    },
    close: closePanel,
    closeComments: closeStoryCommentsSheet,
    openComments: openStoryComments,
    nextSlide: function(){
      var story = currentStory();
      if(!story || !Array.isArray(story.slides) || !story.slides.length) return;
      if(slideIndex < story.slides.length - 1){
        slideIndex += 1;
        renderSlide();
        return;
      }
      if(storyIndex < catalog.length - 1){
        openStoryAt(storyIndex + 1);
        return;
      }
      closePanel();
    },
    prevSlide: function(){
      if(slideIndex > 0){
        slideIndex -= 1;
        renderSlide();
        return;
      }
      if(storyIndex > 0){
        var prevStory = catalog[storyIndex - 1];
        storyIndex = storyIndex - 1;
        slideIndex = Math.max(0, (prevStory && prevStory.slides ? prevStory.slides.length : 1) - 1);
        setActiveRing(String(prevStory.key || storyIndex));
        renderSlide();
        openPanel();
      }
    },
    removePost: removePostFromCatalog
  };

  if(storyCmtBackdrop){
    storyCmtBackdrop.addEventListener('click', function(e){
      e.preventDefault();
      e.stopPropagation();
      closeStoryCommentsSheet();
    });
  }

  if(storyCmtClose){
    storyCmtClose.addEventListener('click', function(e){
      e.preventDefault();
      e.stopPropagation();
      closeStoryCommentsSheet();
    });
  }

  var storyCmtPanel = storyCmtSheet ? storyCmtSheet.querySelector('.tt-story-cmt-panel') : null;
  if(storyCmtPanel){
    storyCmtPanel.addEventListener('click', function(e){
      e.stopPropagation();
    });
  }

  if(storyCmtCancelReply){
    storyCmtCancelReply.addEventListener('click', function(e){
      e.preventDefault();
      storySetCommentReply(0, '');
      if(storyCmtInput) storyCmtInput.focus();
    });
  }

  if(storyCmtEmojis){
    storyCmtEmojis.addEventListener('click', function(e){
      var btn = e.target && e.target.closest ? e.target.closest('[data-emoji]') : null;
      if(!btn || !storyCmtInput) return;
      e.preventDefault();
      var emoji = String(btn.getAttribute('data-emoji') || '');
      if(!emoji) return;
      storyCmtInput.value = String(storyCmtInput.value || '') + emoji;
      storyCmtInput.focus();
      syncStoryCmtSendState();
    });
  }

  if(storyCmtInput){
    storyCmtInput.addEventListener('input', syncStoryCmtSendState);
  }

  if(storyCmtList){
    storyCmtList.addEventListener('click', function(e){
      var replyBtn = e.target && e.target.closest ? e.target.closest('[data-story-reply]') : null;
      if(replyBtn){
        e.preventDefault();
        storySetCommentReply(
          Number(replyBtn.getAttribute('data-story-reply') || 0),
          replyBtn.getAttribute('data-story-reply-who') || 'comment'
        );
        if(storyCmtInput) storyCmtInput.focus();
        return;
      }
      var toggleBtn = e.target && e.target.closest ? e.target.closest('[data-toggle-story-replies]') : null;
      if(toggleBtn){
        e.preventDefault();
        var parentId = Number(toggleBtn.getAttribute('data-toggle-story-replies') || 0);
        if(parentId > 0) delete storyCollapsedReplyIds[parentId];
        storyRenderComments(storyCommentsCache);
        return;
      }
      var likeBtn = e.target && e.target.closest ? e.target.closest('[data-story-like]') : null;
      if(likeBtn){
        e.preventDefault();
        var cid = Number(likeBtn.getAttribute('data-story-like') || 0);
        var pid = Number(storyCmtPostId && storyCmtPostId.value || storyCommentsPostId || 0);
        if(!pid || !cid) return;
        if(likeBtn.classList.contains('is-liked')) return;
        var fd = new FormData();
        fd.append('ajax', 'comment_like');
        fd.append('post_id', String(pid));
        fd.append('comment_id', String(cid));
        fd.append('reaction', 'love');
        fetch('feed_api.php', { method:'POST', body: fd, cache:'no-store', credentials:'same-origin' })
          .then(function(r){ return r.json(); })
          .then(function(res){
            if(res && res.ok) storyRefreshComments();
          })
          .catch(function(){});
      }
    });
  }

  if(storyCmtForm){
    storyCmtForm.addEventListener('submit', function(e){
      e.preventDefault();
      var pid = Number(storyCmtPostId && storyCmtPostId.value || storyCommentsPostId || currentPostId() || 0);
      var parent = Number(storyCmtParentId && storyCmtParentId.value || 0);
      var txt = String(storyCmtInput && storyCmtInput.value || '').trim();
      if(!pid || !txt) return;
      var fd = new FormData();
      fd.append('ajax', 'comment');
      fd.append('post_id', String(pid));
      fd.append('parent_id', String(parent));
      fd.append('comment_text', txt);
      if(storyCmtInput) storyCmtInput.disabled = true;
      if(storyCmtSend) storyCmtSend.disabled = true;
      fetch('feed_api.php', { method:'POST', body: fd, cache:'no-store', credentials:'same-origin' })
        .then(function(r){ return r.json(); })
        .then(function(res){
          if(storyCmtInput) storyCmtInput.disabled = false;
          syncStoryCmtSendState();
          if(!res || !res.ok) return;
          if(storyCmtInput) storyCmtInput.value = '';
          syncStoryCmtSendState();
          storySetCommentReply(0, '');
          storyRefreshComments();
          if(window.TTComments && typeof window.TTComments.refreshCurrent === 'function'){
            window.TTComments.refreshCurrent();
          }
        })
        .catch(function(){
          if(storyCmtInput) storyCmtInput.disabled = false;
          syncStoryCmtSendState();
        });
    });
  }

  if(storyCmtMeAvatar && STORY_SHEET_ME_ID > 0 && !storyCmtMeAvatar.getAttribute('src')){
    storyCmtMeAvatar.src = storyCommentAvatarUrl({
      user_id: STORY_SHEET_ME_ID,
      display_name: STORY_SHEET_ME_NAME,
      email: STORY_SHEET_ME_EMAIL
    }, 64);
  }

  closeBtn && closeBtn.addEventListener('click', function(e){
    e.preventDefault();
    e.stopPropagation();
    closePanel();
  });
  patchStoryDoorTrackSync();
  window.addEventListener('load', patchStoryDoorTrackSync);
  pauseBtn && pauseBtn.addEventListener('click', function(e){
    e.preventDefault();
    e.stopPropagation();
    setPaused(!isPaused);
  });
  prevBtn && prevBtn.addEventListener('click', function(e){
    e.preventDefault();
    e.stopPropagation();
    slideNavInstant = true;
    TTStories.prevSlide();
  });
  nextBtn && nextBtn.addEventListener('click', function(e){
    e.preventDefault();
    e.stopPropagation();
    slideNavInstant = true;
    TTStories.nextSlide();
  });

  storyForm && storyForm.addEventListener('submit', function(e){
    e.preventDefault();
    if(isPublisherStory()) return;
    var pid = Number(storyPostId && storyPostId.value || 0);
    var txt = String(storyText && storyText.value || '').trim();
    var peerCode = storyFriendCode();
    var media = storyMediaPayload();
    if(!peerCode){
      window.alert('Cannot send a private reply because this story owner has no friend code.');
      return;
    }
    if(!txt && !media){
      return;
    }

    var fd = new FormData();
    fd.append('to', peerCode);
    fd.append('message', txt);
    if(media){
      fd.append('attachment_url', media.url);
      fd.append('attachment_type', media.type);
      fd.append('attachment_original', media.original);
    }

    setStoryFootEnabled(false);
    fetch('ajax/user_chat_send.php', { method:'POST', body: fd, cache:'no-store', credentials:'same-origin' })
      .then(function(r){ return r.json(); })
      .then(function(data){
        if(!data || !data.ok){
          var err = (data && data.error) ? String(data.error) : 'Could not send private message.';
          window.alert(err);
          setStoryFootEnabled(!!pid);
          return;
        }
        if(storyText) storyText.value = '';
        if(window.TTComments && typeof window.TTComments.refreshCurrent === 'function' && pid){
          window.TTComments.refreshCurrent();
        }
        setStoryFootEnabled(!!pid);
      })
      .catch(function(){
        window.alert('Could not send private message right now.');
        setStoryFootEnabled(!!pid);
      });
  });

  function reactToCurrentStory(reaction){
    var pid = currentPostId();
    reaction = String(reaction || 'love');
    if(!pid || !reaction || reaction === currentStoryReaction) return;

    var slide = currentSlide();
    var story = currentStory();
    var loveBefore = slideActionCounts(slide).loveCount;
    var ownerUserId = Number(story && story.userId || 0);

    var body = 'post_id=' + encodeURIComponent(String(pid)) + '&reaction=' + encodeURIComponent(reaction);
    fetch('feed_api.php?ajax=react', {
      method:'POST',
      headers:{ 'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8' },
      body: body,
      cache:'no-store',
      credentials:'same-origin'
    })
      .then(function(r){ return r.json(); })
      .then(function(data){
        if(!data || !data.ok) return;
        var nextReaction = String((data.counts && data.counts.my_reaction) || reaction);
        var patch = { myReaction: nextReaction };
        if(data.counts && data.counts.love_count != null) patch.loveCount = Number(data.counts.love_count || 0);
        updateSlidePublisherState(pid, patch);
        updateSlideReaction(pid, nextReaction);
        if(typeof window.msbOnPostLoveCountChange === 'function'){
          var loveAfter = Number((data.counts && data.counts.love_count != null) ? data.counts.love_count : loveBefore);
          window.msbOnPostLoveCountChange({
            ownerUserId: ownerUserId,
            delta: loveAfter - loveBefore
          });
        }
      })
      .catch(function(){});
  }

  if(storyLoveBtn){
    storyLoveBtn.addEventListener('click', function(e){
      e.preventDefault();
      e.stopPropagation();
      reactToCurrentStory('love');
    });
    if(window.MSBReactions && typeof window.MSBReactions.bindLikePicker === 'function'){
      window.MSBReactions.bindLikePicker('#ttStoriesLove', function(_btn, reaction){
        reactToCurrentStory(reaction || 'love');
      });
    }
  }

  if(storyPubLoveBtn){
    storyPubLoveBtn.addEventListener('click', function(e){
      e.preventDefault();
      e.stopPropagation();
      reactToCurrentStory('love');
    });
    if(window.MSBReactions && typeof window.MSBReactions.bindLikePicker === 'function'){
      window.MSBReactions.bindLikePicker('#ttStoriesPubLove', function(_btn, reaction){
        reactToCurrentStory(reaction || 'love');
      });
    }
  }

  if(storyPubCommentBtn){
    storyPubCommentBtn.addEventListener('click', function(e){
      e.preventDefault();
      e.stopPropagation();
      openStoryComments();
    });
  }

  if(storyPubShareBtn){
    storyPubShareBtn.addEventListener('click', function(e){
      e.preventDefault();
      e.stopPropagation();
      var pid = currentPostId();
      if(!pid) return;
      storyFeedPost('share', pid, function(data){
        if(!data || !data.ok) return;
        var state = data.state || {};
        updateSlidePublisherState(pid, {
          myShared: Number(state.shared || 0) ? 1 : 0,
          mySaved: Number(state.saved || slideMySaved()) ? 1 : 0,
          shareCount: Number(data.share_count || 0),
          saveCount: Number(data.save_count != null ? data.save_count : slideActionCounts(currentSlide()).saveCount)
        });
      });
    });
  }

  if(storyPubSaveBtn){
    storyPubSaveBtn.addEventListener('click', function(e){
      e.preventDefault();
      e.stopPropagation();
      var pid = currentPostId();
      if(!pid) return;
      storyFeedPost('save', pid, function(data){
        if(!data || !data.ok) return;
        var state = data.state || {};
        updateSlidePublisherState(pid, {
          mySaved: Number(state.saved || 0) ? 1 : 0,
          myShared: Number(state.shared || slideMyShared()) ? 1 : 0,
          shareCount: Number(data.share_count != null ? data.share_count : slideActionCounts(currentSlide()).shareCount),
          saveCount: Number(data.save_count || 0)
        });
      });
    });
  }

  function slideMyShared(){
    var slide = currentSlide();
    return slide ? Number(slide.myShared || 0) : 0;
  }

  function slideMySaved(){
    var slide = currentSlide();
    return slide ? Number(slide.mySaved || 0) : 0;
  }

  storyText && storyText.addEventListener('keydown', function(e){
    if(e.key === 'Enter' && !e.shiftKey){
      e.preventDefault();
      storyForm && storyForm.dispatchEvent(new Event('submit', { cancelable:true }));
    }
  });

  storyText && storyText.addEventListener('focus', function(){
    setPaused(true);
  });

  document.addEventListener('keydown', function(e){
    if(!wrap.classList.contains('is-open')) return;
    if(e.key === 'Escape'){
      if(storyCommentsOpen){
        e.preventDefault();
        closeStoryCommentsSheet();
        return;
      }
      closePanel();
    }
    else if(e.key === 'ArrowRight'){
      slideNavInstant = true;
      TTStories.nextSlide();
    }
    else if(e.key === 'ArrowLeft'){
      slideNavInstant = true;
      TTStories.prevSlide();
    }
    else if(e.key === ' ' && e.target !== storyText && e.target !== storyCmtInput){
      e.preventDefault();
      setPaused(!isPaused);
    }
  });

  setStoryFootEnabled(false);
  setPublisherFootEnabled(false);
  bindStoryScrollPanel();
  bindStoryCircleClicks();
})();
</script>
