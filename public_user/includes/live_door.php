<?php
declare(strict_types=1);

if (!empty($GLOBALS['msb_live_door_included'])) {
    return;
}
$GLOBALS['msb_live_door_included'] = true;

if (!function_exists('h')) {
    function h(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }
}

$__liveStandalone = !empty($msbLiveDoorStandalone);

if (isset($msbLiveDoorCanStudio)) {
    $__liveCanStudio = (bool)$msbLiveDoorCanStudio;
} else {
    $__liveCanStudio = false;
    if (!empty($_SESSION['user_id'])) {
        try {
            require_once __DIR__ . '/../controller.php';
            require_once __DIR__ . '/publisher_organization_bridge.php';
            $__liveCanStudio = live_studio_user_can_access((new Controller())->pdo(), (int)$_SESSION['user_id']);
        } catch (Throwable $e) {
            $__liveCanStudio = false;
        }
    }
}

$__liveMeId = (int)($_SESSION['user_id'] ?? 0);
$__liveFirstId = 0;
$__liveFirstUrl = '';

if (isset($dbh) && $dbh instanceof PDO) {
    try {
        $stTable = $dbh->prepare("
            SELECT 1
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'user_video_lives'
            LIMIT 1
        ");
        $stTable->execute();
        if ($stTable->fetchColumn()) {
            $st = $dbh->prepare("
                SELECT l.id
                FROM user_video_lives l
                WHERE l.status = 'live'
                  AND (
                    l.visibility = 'public'
                    OR l.user_id = :me
                    OR (
                      l.visibility = 'friends'
                      AND (
                        EXISTS (
                          SELECT 1 FROM user_contacts uc
                          WHERE uc.owner_user_id = :me2 AND uc.friend_user_id = l.user_id
                        )
                        OR EXISTS (
                          SELECT 1 FROM user_contacts uc2
                          WHERE uc2.owner_user_id = l.user_id AND uc2.friend_user_id = :me3
                        )
                      )
                    )
                  )
                ORDER BY COALESCE(l.started_at, l.updated_at) DESC, l.id DESC
                LIMIT 1
            ");
            $st->execute([
                ':me' => $__liveMeId,
                ':me2' => $__liveMeId,
                ':me3' => $__liveMeId,
            ]);
            $__liveFirstId = (int)($st->fetchColumn() ?: 0);
            if ($__liveFirstId > 0) {
                $__liveFirstUrl = 'live_watch.php?live=' . $__liveFirstId . '&door_panel=1';
            }
        }
    } catch (Throwable $e) {
        $__liveFirstId = 0;
        $__liveFirstUrl = '';
    }
}

$__hubSurface = 'public';
if (isset($msbLiveDoorSurface)) {
    $__hubSurface = strtolower(trim((string)$msbLiveDoorSurface)) === 'feed' ? 'feed' : 'public';
} else {
    $__doorPage = strtolower(trim((string)($__currentPage ?? basename((string)($_SERVER['PHP_SELF'] ?? '')))));
    $__hubSurface = ($__doorPage === 'feed.php') ? 'feed' : 'public';
}

$__liveDoorQuery = ['hub_surface' => $__hubSurface];
if ($__liveCanStudio) {
    $__liveDoorQuery['can_studio'] = '1';
}
$__liveDoorDefaultSrc = 'live_door_hub.php?' . http_build_query($__liveDoorQuery);
?>
<style>
.msb-live-door-host{
  position:fixed;
  left:0;
  top:0;
  width:min(100vw, 430px);
  height:100vh;
  z-index:1295;
  pointer-events:none;
}
.msb-live-door-host .tt-live-wrap{
  position:absolute;
  inset:0;
  background:var(--msb-palette-bg, #ffffff);
  transform:translateX(-105%);
  opacity:0;
  pointer-events:none;
  transition:transform .22s ease, opacity .22s ease, box-shadow .22s ease;
  display:flex;
  flex-direction:column;
  overflow:hidden;
  box-shadow:none;
  isolation:isolate;
}
#ttLeftbarOverlays .tt-live-wrap{
  position:absolute;
  inset:0;
  background:var(--msb-palette-bg, #ffffff);
  z-index:996;
  transform:translateX(-105%);
  opacity:0;
  pointer-events:none;
  transition:transform .22s ease, opacity .22s ease, box-shadow .22s ease;
  display:flex;
  flex-direction:column;
  overflow:hidden;
  box-shadow:none;
  isolation:isolate;
}
.msb-live-door-host .tt-live-wrap::before,
#ttLeftbarOverlays .tt-live-wrap::before{
  content:"";
  position:absolute;
  top:0;
  right:-34px;
  bottom:0;
  width:34px;
  background:linear-gradient(90deg, rgba(15,23,42,.2) 0%, rgba(15,23,42,.08) 42%, transparent 100%);
  opacity:0;
  transition:opacity .22s ease;
  pointer-events:none;
  z-index:2;
}
.msb-live-door-host .tt-live-wrap::after,
#ttLeftbarOverlays .tt-live-wrap::after{
  content:"";
  position:absolute;
  top:0;
  right:0;
  bottom:0;
  width:1px;
  background:var(--msb-palette-border, rgba(15,23,42,.12));
  opacity:0;
  transition:opacity .22s ease;
  pointer-events:none;
  z-index:3;
}
.msb-live-door-host .tt-live-wrap.is-open,
#ttLeftbarOverlays .tt-live-wrap.is-open{
  transform:translateX(0);
  opacity:1;
  pointer-events:auto;
  overflow:visible;
  box-shadow:
    8px 0 24px rgba(15,23,42,.1),
    18px 0 48px rgba(15,23,42,.14),
    32px 0 84px rgba(15,23,42,.1);
}
.msb-live-door-host .tt-live-wrap.is-open::before,
#ttLeftbarOverlays .tt-live-wrap.is-open::before,
.msb-live-door-host .tt-live-wrap.is-open::after,
#ttLeftbarOverlays .tt-live-wrap.is-open::after{
  opacity:1;
}
html.dark-auto .msb-live-door-host .tt-live-wrap.is-open,
html.dark-auto #ttLeftbarOverlays .tt-live-wrap.is-open,
html[data-theme="dark"] .msb-live-door-host .tt-live-wrap.is-open,
html[data-theme="dark"] #ttLeftbarOverlays .tt-live-wrap.is-open{
  box-shadow:
    10px 0 30px rgba(0,0,0,.34),
    24px 0 64px rgba(0,0,0,.28),
    42px 0 100px rgba(0,0,0,.2);
}
html.dark-auto .msb-live-door-host .tt-live-wrap::before,
html.dark-auto #ttLeftbarOverlays .tt-live-wrap::before,
html[data-theme="dark"] .msb-live-door-host .tt-live-wrap::before,
html[data-theme="dark"] #ttLeftbarOverlays .tt-live-wrap::before{
  background:linear-gradient(90deg, rgba(0,0,0,.48) 0%, rgba(0,0,0,.2) 42%, transparent 100%);
}
.tt-live-door-frame{
  flex:1 1 auto;
  width:100%;
  min-height:0;
  border:0;
  display:block;
  background:var(--msb-palette-bg, #ffffff);
  position:relative;
  z-index:1;
}
body.msb-live-door-open{ overflow:hidden !important; }
body.msb-live-door-open .msb-live-door-host{ pointer-events:auto; }
.msb-live-door-backdrop{
  position:fixed;
  inset:0;
  z-index:1294;
  border:0;
  padding:0;
  margin:0;
  background:rgba(0,0,0,.48);
  opacity:0;
  visibility:hidden;
  pointer-events:none;
  transition:opacity .22s ease, visibility .22s ease;
}
body.msb-live-door-open .msb-live-door-backdrop{
  opacity:1;
  visibility:visible;
  pointer-events:auto;
}
@media (max-width: 520px){
  .msb-live-door-host{ width:100vw; }
}
</style>

<?php if ($__liveStandalone): ?>
<button type="button" class="msb-live-door-backdrop" id="msbLiveDoorBackdrop" aria-label="Close live panel"></button>
<?php endif; ?>

<div
  class="tt-live-wrap"
  id="tt-live-wrap"
  aria-hidden="true"
  data-default-src="<?= h($__liveDoorDefaultSrc) ?>"
  data-first-live-id="<?= (int)$__liveFirstId ?>"
  data-can-studio="<?= $__liveCanStudio ? '1' : '0' ?>"
>
  <iframe
    class="tt-live-door-frame"
    id="ttLiveDoorFrame"
    title="Live"
    src="about:blank"
    allow="autoplay; fullscreen; picture-in-picture; camera; microphone"
  ></iframe>
</div>

<script>
(function(){
  var $liveWrap = document.getElementById('tt-live-wrap');
  var $liveFrame = document.getElementById('ttLiveDoorFrame');
  var standaloneHost = document.getElementById('msbLiveDoorHost');
  var standaloneBackdrop = document.getElementById('msbLiveDoorBackdrop');
  var isStandalone = !!standaloneHost;

  function closeOtherPanels(){
    if(window.TTProfile && typeof window.TTProfile.close === 'function') window.TTProfile.close();
    if(window.TTMessages && typeof window.TTMessages.close === 'function') window.TTMessages.close();
    if(window.TTNotifications && typeof window.TTNotifications.close === 'function') window.TTNotifications.close();
    if(window.TTFriendRequests && typeof window.TTFriendRequests.close === 'function') window.TTFriendRequests.close();
  }

  function liveDoorDefaultSrc(){
    if(!$liveWrap) return 'live_door_hub.php';
    return String($liveWrap.getAttribute('data-default-src') || 'live_door_hub.php').trim() || 'live_door_hub.php';
  }

  function closeLivePanel(){
    if(!$liveWrap) return;
    $liveWrap.classList.remove('is-open');
    $liveWrap.setAttribute('aria-hidden', 'true');
    if($liveFrame) $liveFrame.setAttribute('src', 'about:blank');
    if(isStandalone) document.body.classList.remove('msb-live-door-open');
    else document.body.classList.remove('public-leftbar-open');
  }

  function openLivePanel(liveUrl){
    if(!$liveWrap) return;
    closeOtherPanels();
    var nextSrc = String(liveUrl || liveDoorDefaultSrc()).trim() || liveDoorDefaultSrc();
    $liveWrap.classList.add('is-open');
    $liveWrap.setAttribute('aria-hidden', 'false');
    if($liveFrame && $liveFrame.getAttribute('src') !== nextSrc){
      $liveFrame.setAttribute('src', nextSrc);
    }
    try {
      if($liveFrame && $liveFrame.contentWindow){
        $liveFrame.contentWindow.postMessage({ type: 'msb-hub-live-refresh' }, '*');
      }
    } catch(err) {}
    if(isStandalone) document.body.classList.add('msb-live-door-open');
    else document.body.classList.add('public-leftbar-open');
  }

  function toggleLivePanel(){
    if($liveWrap && $liveWrap.classList.contains('is-open')) closeLivePanel();
    else openLivePanel('');
  }

  standaloneBackdrop?.addEventListener('click', function(e){
    e.preventDefault();
    e.stopPropagation();
    closeLivePanel();
  });

  document.addEventListener('click', function(e){
    var btn = e.target && e.target.closest ? e.target.closest('.js-open-live-door') : null;
    if(!btn) return;
    e.preventDefault();
    e.stopPropagation();
    toggleLivePanel();
  }, true);

  if(!window.__msbHubLiveRelayBound){
    window.__msbHubLiveRelayBound = true;
    window.addEventListener('message', function(relayEvent){
      if(!relayEvent || !relayEvent.data || typeof relayEvent.data !== 'object') return;
      if(relayEvent.data.type !== 'msb-hub-live-started' && relayEvent.data.type !== 'msb-hub-live-refresh') return;
      ['ttLiveDoorFrame', 'ttLiveRightDoorFrame'].forEach(function(frameId){
        var frame = document.getElementById(frameId);
        if(!frame || !frame.contentWindow || relayEvent.source === frame.contentWindow) return;
        try { frame.contentWindow.postMessage(relayEvent.data, '*'); } catch(err) {}
      });
    });
  }

  window.addEventListener('message', function(e){
    if(!e || !e.data || typeof e.data !== 'object') return;
    if(e.data.type === 'msb-live-door-close'){
      closeLivePanel();
      return;
    }
    if(e.data.type === 'msb-live-right-door-close'){
      if(window.TTLiveRight && typeof window.TTLiveRight.close === 'function'){
        window.TTLiveRight.close();
      }
      return;
    }
    if(e.data.type === 'msb-live-door-open' && e.data.url){
      openLivePanel(String(e.data.url));
    }
  });

  document.addEventListener('keydown', function(e){
    if(e.key === 'Escape' && $liveWrap && $liveWrap.classList.contains('is-open')){
      closeLivePanel();
    }
  });

  window.TTLive = window.TTLive || {};
  window.TTLive.open = openLivePanel;
  window.TTLive.close = closeLivePanel;
  window.TTLive.toggle = toggleLivePanel;
})();
</script>
