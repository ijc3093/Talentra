<?php
if (!empty($GLOBALS['msb_profile_door_included'])) {
  return;
}
$GLOBALS['msb_profile_door_included'] = true;

$__profileStandalone = !empty($msbProfileDoorStandalone);
$__profileMenuItems = is_array($railProfileMenuItems ?? null) ? $railProfileMenuItems : [
  ['href' => 'profile.php', 'icon' => 'ion-ios-person', 'label' => 'Profile'],
  ['href' => 'timeline.php', 'icon' => 'ion-ios-locked', 'label' => 'Timeline'],
  ['href' => 'change-password.php', 'icon' => 'ion-ios-gear', 'label' => 'Settings'],
  ['href' => 'logout.php', 'icon' => 'ion-power', 'label' => 'Sign Out'],
];
$__profileDisplayName = trim((string)($meMenuDisplayName ?? $meName ?? 'My Account'));
$__profileBadge = trim((string)($meMenuAccountBadge ?? ''));
$__profileEmail = trim((string)($meEmail ?? ''));
$__profileCode = trim((string)($meCode ?? ''));
$__profileAvatarUrl = trim((string)($meAvatarUrl ?? 'avatar.php'));
$__profileAvatarKey = trim((string)($meKey ?? ''));
$__profileAvatarGrad = trim((string)($meGrad ?? ''));
?>
<style>
#ttLeftbarOverlays .tt-profile-wrap,
.msb-profile-door-host .tt-profile-wrap{
  position:absolute !important;
  inset:0 !important;
  background:var(--tt-panel-bg, var(--msb-palette-bg, #ffffff));
  z-index:995 !important;
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
#ttLeftbarOverlays .tt-profile-wrap.is-open,
.msb-profile-door-host .tt-profile-wrap.is-open{
  transform:translateX(0);
  opacity:1;
  pointer-events:auto;
}
.msb-profile-door-host{
  position:fixed;
  left:0;
  top:0;
  width:min(360px, 88vw);
  height:100vh;
  z-index:1290;
  pointer-events:none;
}
.msb-profile-door-host .tt-profile-wrap{
  position:absolute !important;
  inset:0 !important;
}
.tt-profile-head{
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
.tt-profile-head .title{
  font-weight:800;
  font-size:20px;
  line-height:1.1;
  color:var(--tt-text, var(--msb-palette-text, #101828));
}
.msb-profile-door-host .tt-close,
#ttLeftbarOverlays .tt-profile-wrap .tt-close{
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
.msb-profile-door-host .tt-close i,
#ttLeftbarOverlays .tt-profile-wrap .tt-close i{ font-size:20px; }
.msb-profile-door-host .tt-close:hover,
#ttLeftbarOverlays .tt-profile-wrap .tt-close:hover{
  background:var(--tt-control-hover, var(--msb-palette-nav-hover, #e9edf3));
}
.tt-profile-body{
  flex:1 1 auto !important;
  min-height:0 !important;
  overflow-y:auto !important;
  overflow-x:hidden !important;
  padding:18px 18px 24px;
  -webkit-overflow-scrolling:touch;
  overscroll-behavior:contain;
  background:var(--tt-panel-bg, var(--msb-palette-bg, #ffffff));
}
.tt-profile-top{
  display:flex;
  align-items:center;
  gap:14px;
  padding:4px 6px 16px;
}
.tt-profile-avatar{
  width:56px;
  height:56px;
  border-radius:50%;
  overflow:hidden;
  flex:0 0 auto;
  display:inline-flex;
  align-items:center;
  justify-content:center;
  box-shadow:0 10px 22px rgba(17,24,39,.12);
}
.tt-profile-avatar img{
  width:100%;
  height:100%;
  object-fit:cover;
  display:block;
}
.tt-profile-meta{
  min-width:0;
  flex:1 1 auto;
}
.tt-profile-name{
  font-size:17px;
  font-weight:800;
  line-height:1.2;
  color:var(--tt-text, var(--msb-palette-text, #101828));
}
.tt-profile-badge{
  margin-top:4px;
  font-size:13px;
  font-weight:700;
  line-height:1.2;
  color:var(--tt-text, var(--msb-palette-text, #101828));
}
.tt-profile-email,
.tt-profile-code{
  margin-top:4px;
  font-size:13px;
  line-height:1.35;
  color:var(--tt-muted, var(--msb-palette-text-muted, #667085));
  word-break:break-word;
}
.tt-profile-divider{
  height:1px;
  background:var(--tt-panel-border, var(--msb-palette-border, rgba(15,23,42,.08)));
  margin:0 6px 12px;
}
.tt-profile-nav{
  list-style:none;
  margin:0;
  padding:0;
}
.tt-profile-nav li{
  margin:0;
  padding:0;
}
.tt-profile-nav a{
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
  transition:background .15s ease, color .15s ease;
}
.tt-profile-nav a:hover,
.tt-profile-nav a:focus{
  background:var(--tt-control-hover, var(--msb-palette-nav-hover, #e9edf3));
  color:var(--tt-text, var(--msb-palette-text, #101828));
  text-decoration:none;
  outline:none;
}
.tt-profile-nav a .icon{
  width:20px;
  text-align:center;
  font-size:18px;
  color:var(--tt-muted, var(--msb-palette-text-muted, #667085));
}
body.msb-profile-door-open{ overflow:hidden !important; }
body.msb-profile-door-open .msb-profile-door-host{ pointer-events:auto; }
.msb-profile-door-backdrop{
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
body.msb-profile-door-open .msb-profile-door-backdrop{
  opacity:1;
  visibility:visible;
  pointer-events:auto;
  z-index:1289;
}
</style>

<?php if ($__profileStandalone): ?>
<button type="button" class="msb-profile-door-backdrop" id="msbProfileDoorBackdrop" aria-label="Close profile"></button>
<?php endif; ?>

<div class="tt-profile-wrap" id="tt-profile-wrap" aria-hidden="true">
  <div class="tt-profile-head">
    <div>
      <span class="title">Profile</span>
    </div>
    <button class="tt-close" type="button" id="ttProfileClose" title="Close">
      <i class="icon ion-close"></i>
    </button>
  </div>
  <div class="tt-profile-body">
    <div class="tt-profile-top">
      <span class="tt-profile-avatar" data-avatar-key="<?= htmlspecialchars($__profileAvatarKey, ENT_QUOTES, 'UTF-8') ?>"<?= $__profileAvatarGrad !== '' ? ' style="' . htmlspecialchars($__profileAvatarGrad, ENT_QUOTES, 'UTF-8') . '"' : '' ?> aria-hidden="true">
        <img src="<?= htmlspecialchars($__profileAvatarUrl, ENT_QUOTES, 'UTF-8') ?>" data-live-avatar="1" data-avatar-base="<?= htmlspecialchars($__profileAvatarUrl, ENT_QUOTES, 'UTF-8') ?>" alt="Avatar">
      </span>
      <div class="tt-profile-meta">
        <div class="tt-profile-name"><?= htmlspecialchars($__profileDisplayName, ENT_QUOTES, 'UTF-8') ?></div>
        <?php if ($__profileBadge !== ''): ?>
          <div class="tt-profile-badge"><?= htmlspecialchars($__profileBadge, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
        <?php if ($__profileEmail !== ''): ?>
          <div class="tt-profile-email"><?= htmlspecialchars($__profileEmail, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
        <?php if ($__profileCode !== ''): ?>
          <div class="tt-profile-code">Code: <b><?= htmlspecialchars($__profileCode, ENT_QUOTES, 'UTF-8') ?></b></div>
        <?php endif; ?>
      </div>
    </div>
    <div class="tt-profile-divider"></div>
    <ul class="tt-profile-nav">
      <?php foreach ($__profileMenuItems as $item):
        $href = trim((string)($item['href'] ?? '#'));
        $icon = trim((string)($item['icon'] ?? 'ion-ios-arrow-right'));
        $label = trim((string)($item['label'] ?? 'Open'));
        if ($href === '' || $label === '') continue;
      ?>
        <li><a href="<?= htmlspecialchars($href, ENT_QUOTES, 'UTF-8') ?>"><i class="icon <?= htmlspecialchars($icon, ENT_QUOTES, 'UTF-8') ?>"></i> <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></a></li>
      <?php endforeach; ?>
    </ul>
  </div>
</div>

<script>
(function(){
  var $profileWrap = document.getElementById('tt-profile-wrap');
  var $profileClose = document.getElementById('ttProfileClose');
  var standaloneBackdrop = document.getElementById('msbProfileDoorBackdrop');
  var isStandalone = <?php echo $__profileStandalone ? 'true' : 'false'; ?>;
  var isOpen = false;

  function closeOtherPanels(){
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
    if(window.TTMessages && typeof window.TTMessages.close === 'function') window.TTMessages.close();
    if(window.TTNotifications && typeof window.TTNotifications.close === 'function') window.TTNotifications.close();
    if(window.TTFriendRequests && typeof window.TTFriendRequests.close === 'function') window.TTFriendRequests.close();
    if(window.TTLive && typeof window.TTLive.close === 'function') window.TTLive.close();
  }

  function closeProfilePanel(){
    if(!$profileWrap || !isOpen) return;
    isOpen = false;
    $profileWrap.classList.remove('is-open');
    $profileWrap.setAttribute('aria-hidden', 'true');
    if(isStandalone) document.body.classList.remove('msb-profile-door-open');
    else document.body.classList.remove('public-leftbar-open');
  }

  function openProfilePanel(){
    if(!$profileWrap || isOpen) return;
    isOpen = true;
    closeOtherPanels();
    $profileWrap.classList.add('is-open');
    $profileWrap.setAttribute('aria-hidden', 'false');
    if(isStandalone) document.body.classList.add('msb-profile-door-open');
    else document.body.classList.add('public-leftbar-open');
  }

  function toggleProfilePanel(){
    if($profileWrap && $profileWrap.classList.contains('is-open')) closeProfilePanel();
    else openProfilePanel();
  }

  $profileClose?.addEventListener('click', function(e){
    e.preventDefault();
    e.stopPropagation();
    closeProfilePanel();
  });

  standaloneBackdrop?.addEventListener('click', function(e){
    e.preventDefault();
    e.stopPropagation();
    closeProfilePanel();
  });

  document.addEventListener('keydown', function(e){
    if(e.key === 'Escape' && isOpen) closeProfilePanel();
  });

  document.addEventListener('click', function(e){
    var trigger = e.target && e.target.closest ? e.target.closest('.js-open-profile-door') : null;
    if(!trigger) return;
    e.preventDefault();
    e.stopPropagation();
    toggleProfilePanel();
  }, true);

  window.TTProfile = {
    open: openProfilePanel,
    close: closeProfilePanel,
    toggle: toggleProfilePanel
  };
})();
</script>
