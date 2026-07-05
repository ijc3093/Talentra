<?php
declare(strict_types=1);

if (!empty($GLOBALS['msb_live_right_door_included'])) {
    return;
}
$GLOBALS['msb_live_right_door_included'] = true;

if (!function_exists('h')) {
    function h(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }
}

$__liveRightCanStudio = false;
if (!empty($_SESSION['user_id'])) {
    try {
        require_once __DIR__ . '/../controller.php';
        require_once __DIR__ . '/publisher_organization_bridge.php';
        $__liveRightCanStudio = live_studio_user_can_access((new Controller())->pdo(), (int)$_SESSION['user_id']);
    } catch (Throwable $e) {
        $__liveRightCanStudio = false;
    }
}

$__liveRightHubSurface = 'public';
$__rightDoorPage = strtolower(trim((string)($__currentPage ?? basename((string)($_SERVER['PHP_SELF'] ?? '')))));
if ($__rightDoorPage === 'feed.php') {
    $__liveRightHubSurface = 'feed';
}

$__liveRightDefaultSrc = 'live_door_hub.php?' . http_build_query(array_filter([
    'can_studio' => $__liveRightCanStudio ? '1' : null,
    'hub_door' => 'right',
    'hub_surface' => $__liveRightHubSurface,
]));
$__liveRightInsideOverlays = !empty($GLOBALS['msb_stories_right_door_included']);
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
#ttRightbarOverlays .tt-live-right-wrap{
  position:absolute !important;
  inset:0 !important;
  background:var(--msb-palette-bg, #ffffff);
  z-index:12052;
  display:flex;
  flex-direction:column;
  overflow:hidden;
  min-height:0;
  box-shadow:-18px 0 48px rgba(0,0,0,.32);
  transform:translateX(105%);
  opacity:0;
  pointer-events:none;
  transition:transform .22s ease, opacity .22s ease, box-shadow .22s ease;
  isolation:isolate;
}
#ttRightbarOverlays .tt-live-right-wrap::before{
  content:"";
  position:absolute;
  top:0;
  left:-34px;
  bottom:0;
  width:34px;
  background:linear-gradient(270deg, rgba(15,23,42,.2) 0%, rgba(15,23,42,.08) 42%, transparent 100%);
  opacity:0;
  transition:opacity .22s ease;
  pointer-events:none;
  z-index:2;
}
#ttRightbarOverlays .tt-live-right-wrap::after{
  content:"";
  position:absolute;
  top:0;
  left:0;
  bottom:0;
  width:1px;
  background:var(--msb-palette-border, rgba(15,23,42,.12));
  opacity:0;
  transition:opacity .22s ease;
  pointer-events:none;
  z-index:3;
}
#ttRightbarOverlays .tt-live-right-wrap.is-open{
  transform:translateX(0);
  opacity:1;
  pointer-events:auto;
  overflow:visible;
  box-shadow:
    -8px 0 24px rgba(15,23,42,.1),
    -18px 0 48px rgba(15,23,42,.14),
    -32px 0 84px rgba(15,23,42,.1);
}
#ttRightbarOverlays .tt-live-right-wrap.is-open::before,
#ttRightbarOverlays .tt-live-right-wrap.is-open::after{
  opacity:1;
}
html.dark-auto #ttRightbarOverlays .tt-live-right-wrap.is-open,
html[data-theme="dark"] #ttRightbarOverlays .tt-live-right-wrap.is-open{
  box-shadow:
    -10px 0 30px rgba(0,0,0,.34),
    -24px 0 64px rgba(0,0,0,.28),
    -42px 0 100px rgba(0,0,0,.2);
}
html.dark-auto #ttRightbarOverlays .tt-live-right-wrap::before,
html[data-theme="dark"] #ttRightbarOverlays .tt-live-right-wrap::before{
  background:linear-gradient(270deg, rgba(0,0,0,.48) 0%, rgba(0,0,0,.2) 42%, transparent 100%);
}
.tt-live-right-door-frame{
  flex:1 1 auto;
  width:100%;
  min-height:0;
  border:0;
  display:block;
  background:var(--msb-palette-bg, #ffffff);
  position:relative;
  z-index:1;
}
body.tt-live-right-open{ overflow:hidden; }
@media (max-width:991.98px){
  #ttRightbarOverlays{
    right:0;
    width:100%;
    max-width:100%;
  }
  #ttRightbarOverlays .tt-live-right-wrap.is-open{
    box-shadow:-10px 0 36px rgba(15,23,42,.16);
  }
}
</style>

<?php if (!$__liveRightInsideOverlays): ?>
<div id="ttRightbarOverlays">
<?php endif; ?>

<div
  class="tt-live-right-wrap"
  id="tt-live-right-wrap"
  aria-hidden="true"
  data-default-src="<?= h($__liveRightDefaultSrc) ?>"
  data-can-studio="<?= $__liveRightCanStudio ? '1' : '0' ?>"
>
  <iframe
    class="tt-live-right-door-frame"
    id="ttLiveRightDoorFrame"
    title="Talentra Live"
    src="about:blank"
    allow="autoplay; fullscreen; picture-in-picture; camera; microphone"
  ></iframe>
</div>

<?php if (!$__liveRightInsideOverlays): ?>
</div>
<?php endif; ?>

<script>
(function(){
  var wrap = document.getElementById('tt-live-right-wrap');
  var frame = document.getElementById('ttLiveRightDoorFrame');
  if (!wrap) return;
  if (window.__msbLiveRightDoorInit) return;
  window.__msbLiveRightDoorInit = true;

  var overlaysRoot = document.getElementById('ttRightbarOverlays');
  if (!overlaysRoot) {
    overlaysRoot = document.createElement('div');
    overlaysRoot.id = 'ttRightbarOverlays';
    document.body.appendChild(overlaysRoot);
  } else if (overlaysRoot.parentNode !== document.body) {
    document.body.appendChild(overlaysRoot);
  }
  if (wrap.parentNode !== overlaysRoot) {
    overlaysRoot.appendChild(wrap);
  }

  function resolveLiveDoorUrl(url){
    url = String(url || '').trim();
    if (!url || url === 'about:blank') return '';
    if (/^https?:\/\//i.test(url)) return url;
    try {
      return new URL(url, window.location.href).href;
    } catch (e) {
      return url;
    }
  }

  function defaultSrc(){
    return resolveLiveDoorUrl(wrap.getAttribute('data-default-src') || 'live_door_hub.php');
  }

  function liveRightDoorFriendBrowseSrc(){
    try {
      var url = new URL(defaultSrc(), window.location.href);
      url.searchParams.set('hub_door', 'right');
      url.searchParams.set('hub_tab', 'public');
      if (document.body && document.body.classList.contains('feed-page')) {
        url.searchParams.set('hub_surface', 'feed');
      }
      return url.href;
    } catch (error) {
      var base = defaultSrc();
      var join = base.indexOf('?') >= 0 ? '&' : '?';
      return base + join + 'hub_door=right&hub_tab=public';
    }
  }

  function isRightLiveDoorOpen(){
    return !!(wrap && wrap.classList.contains('is-open'));
  }

  function notifyHubFriendBrowse(){
    if (!frame || !isRightLiveDoorOpen()) return;
    try {
      frame.contentWindow.postMessage({ type: 'msb-hub-open-friend-browse', door: 'right' }, '*');
    } catch (error) {}
  }

  function fetchHostingLiveOnRight(){
    return fetch('ajax/live_studio_host_action.php?host_door=right', {
      credentials: 'same-origin',
      cache: 'no-store'
    }).then(function(res){
      return res.json();
    }).then(function(data){
      if (!data || !data.ok || !data.live) return false;
      var live = data.live || {};
      if (String(live.status || '').toLowerCase() !== 'live') return false;
      var studioSource = String(live.studio_source || '').toLowerCase();
      var hostDoor = String(live.host_door || '').toLowerCase();
      return studioSource === 'software' || hostDoor === 'right';
    }).catch(function(){
      return false;
    });
  }

  function setRightLiveDoorOpen(nextSrc){
    if (!wrap) return false;
    closeOtherRightPanels();
    wrap.classList.add('is-open');
    wrap.setAttribute('aria-hidden', 'false');
    document.body.classList.add('tt-live-right-open');
    if (!frame) return true;
    var currentSrc = resolveLiveDoorUrl(frame.getAttribute('src') || '');
    var normalizedCurrent = '';
    var normalizedNext = '';
    try {
      if (currentSrc && currentSrc !== 'about:blank') {
        normalizedCurrent = new URL(currentSrc, window.location.href).toString().split('#')[0];
      }
      normalizedNext = new URL(String(nextSrc || liveRightDoorFriendBrowseSrc()), window.location.href).toString().split('#')[0];
    } catch (e) {
      normalizedCurrent = String(currentSrc || '').split('#')[0];
      normalizedNext = String(nextSrc || '').split('#')[0];
    }
    if (!currentSrc || currentSrc === 'about:blank' || normalizedCurrent !== normalizedNext) {
      frame.setAttribute('src', nextSrc || liveRightDoorFriendBrowseSrc());
    } else {
      notifyHubFriendBrowse();
    }
    return true;
  }

  function closeOtherRightPanels(){
    if (window.TTStories && typeof window.TTStories.close === 'function') {
      window.TTStories.close();
    }
  }

  function closeLiveRightPanel(){
    if (!wrap) return;
    wrap.classList.remove('is-open');
    wrap.setAttribute('aria-hidden', 'true');
    if (frame) frame.setAttribute('src', 'about:blank');
    document.body.classList.remove('tt-live-right-open');
  }

  function buildLiveRightDoorSoftwareSrc(){
    try {
      var url = new URL(defaultSrc(), window.location.href);
      url.searchParams.set('hub_door', 'right');
      url.searchParams.set('hub_tab', 'software');
      if (document.body && document.body.classList.contains('feed-page')) {
        url.searchParams.set('hub_surface', 'feed');
      }
      return url.href;
    } catch (error) {
      var base = defaultSrc();
      var join = base.indexOf('?') >= 0 ? '&' : '?';
      return base + join + 'hub_door=right&hub_tab=software';
    }
  }

  function openLiveRightPanel(liveUrl){
    var nextSrc = resolveLiveDoorUrl(liveUrl || '') || '';
    if (!nextSrc) {
      setRightLiveDoorOpen(liveRightDoorFriendBrowseSrc());
      fetchHostingLiveOnRight().then(function(isHosting){
        if (isHosting && isRightLiveDoorOpen()) {
          setRightLiveDoorOpen(buildLiveRightDoorSoftwareSrc());
        }
      });
      return;
    }
    setRightLiveDoorOpen(nextSrc);
    try {
      if (frame && frame.contentWindow) {
        frame.contentWindow.postMessage({ type: 'msb-hub-live-refresh' }, '*');
      }
    } catch (e) {}
  }

  function openLiveSoftwareBrowsePanel(){
    if (isRightLiveDoorOpen()) {
      try {
        var src = frame ? new URL(String(frame.getAttribute('src') || ''), window.location.href) : null;
        if (src && String(src.searchParams.get('hub_tab') || '').toLowerCase() === 'software') {
          closeLiveRightPanel();
          return;
        }
      } catch (e) {}
      setRightLiveDoorOpen(buildLiveRightDoorSoftwareSrc());
      return;
    }
    setRightLiveDoorOpen(buildLiveRightDoorSoftwareSrc());
    fetchHostingLiveOnRight().then(function(isHosting){
      if (isHosting && isRightLiveDoorOpen()) {
        setRightLiveDoorOpen(buildLiveRightDoorSoftwareSrc());
      }
    });
  }

  function toggleLiveRightPanel(){
    if (isRightLiveDoorOpen()) closeLiveRightPanel();
    else openLiveRightPanel('');
  }

  document.addEventListener('click', function(e){
    var softwareBrowseBtn = e.target && e.target.closest ? e.target.closest('.js-open-live-software-browse') : null;
    if (softwareBrowseBtn) {
      e.preventDefault();
      e.stopPropagation();
      openLiveSoftwareBrowsePanel();
      return;
    }
    var btn = e.target && e.target.closest ? e.target.closest('.js-open-live-right-door') : null;
    if (!btn) return;
    e.preventDefault();
    e.stopPropagation();
    toggleLiveRightPanel();
  }, true);

  window.addEventListener('message', function(e){
    if (!e || !e.data || typeof e.data !== 'object') return;
    if (e.data.type === 'msb-live-right-door-close') {
      closeLiveRightPanel();
      return;
    }
    if (e.data.type === 'msb-live-right-door-open') {
      openLiveRightPanel(String(e.data.url || ''));
    }
  });

  document.addEventListener('keydown', function(e){
    if (e.key === 'Escape' && wrap.classList.contains('is-open')) {
      closeLiveRightPanel();
    }
  });

  window.TTLiveRight = window.TTLiveRight || {};
  window.TTLiveRight.open = openLiveRightPanel;
  window.TTLiveRight.close = closeLiveRightPanel;
  window.TTLiveRight.toggle = toggleLiveRightPanel;
  window.MSBOpenLiveSoftwareBrowse = openLiveSoftwareBrowsePanel;
})();
</script>
