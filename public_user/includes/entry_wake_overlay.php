<?php
declare(strict_types=1);

/**
 * Soft "wake up" veil after entry.php sleep handoff.
 * Solid #05090f matches entry sleep veil — no color flash between pages.
 * Include as early as possible inside <head>.
 */
?>
<script>
(function () {
  try {
    var key = 'msbEntryWake';
    var wake = false;
    try { wake = sessionStorage.getItem(key) === '1'; } catch (e0) {}
    if (!wake) {
      try { wake = /(?:^|[?&])from_entry=1(?:&|$)/.test(String(location.search || '')); } catch (e1) {}
    }
    if (!wake) return;
    try { sessionStorage.removeItem(key); } catch (e2) {}
    document.documentElement.classList.add('msb-entry-wake');
    document.documentElement.style.background = '#05090f';
    try {
      var u = new URL(location.href);
      if (u.searchParams.has('from_entry')) {
        u.searchParams.delete('from_entry');
        var qs = u.searchParams.toString();
        history.replaceState(null, '', u.pathname + (qs ? ('?' + qs) : '') + u.hash);
      }
    } catch (e3) {}
  } catch (e) {}
})();
</script>
<style id="msb-entry-wake-css">
  html.msb-entry-wake,
  html.msb-entry-wake body{
    background:#05090f !important;
    overflow:hidden !important;
  }
  /* Real overlay node injected below — ::before is a fallback only. */
  html.msb-entry-wake::before{
    content:"";
    position:fixed;inset:0;z-index:2147483000;pointer-events:none;
    background:#05090f;
    opacity:1;
  }
  #msbEntryWakeVeil{
    position:fixed;inset:0;z-index:2147483001;pointer-events:none;
    background:#05090f;
    opacity:1;
    will-change:opacity;
  }
  #msbEntryWakeVeil.is-waking{
    animation:msbEntryWake 3.1s cubic-bezier(.22,.61,.36,1) forwards;
  }
  @keyframes msbEntryWake{
    0%{opacity:1}
    32%{opacity:1}
    100%{opacity:0}
  }
  @media (prefers-reduced-motion:reduce){
    #msbEntryWakeVeil.is-waking{
      animation:msbEntryWakeQuick .5s ease forwards !important;
    }
    @keyframes msbEntryWakeQuick{ to{opacity:0} }
  }
</style>
<script>
(function () {
  if (!document.documentElement.classList.contains('msb-entry-wake')) return;

  var done = false;
  var HOLD_BEFORE_WAKE_MS = 420;
  var FAILSAFE_MS = 4500;

  function clearWake() {
    if (done) return;
    done = true;
    document.documentElement.classList.remove('msb-entry-wake');
    document.documentElement.style.background = '';
    var veil = document.getElementById('msbEntryWakeVeil');
    if (veil && veil.parentNode) veil.parentNode.removeChild(veil);
    var css = document.getElementById('msb-entry-wake-css');
    if (css && css.parentNode) css.parentNode.removeChild(css);
    try {
      document.documentElement.style.overflow = '';
      if (document.body) document.body.style.overflow = '';
    } catch (e) {}
  }

  function mountVeil() {
    if (document.getElementById('msbEntryWakeVeil')) return document.getElementById('msbEntryWakeVeil');
    var veil = document.createElement('div');
    veil.id = 'msbEntryWakeVeil';
    veil.setAttribute('aria-hidden', 'true');
    var parent = document.body || document.documentElement;
    parent.appendChild(veil);
    return veil;
  }

  function startWake() {
    var veil = mountVeil();
    // Hide ::before fallback once real veil is up.
    try {
      var st = document.getElementById('msb-entry-wake-css');
      if (st) {
        st.appendChild(document.createTextNode('html.msb-entry-wake::before{display:none!important}'));
      }
    } catch (eCss) {}
    void veil.offsetWidth;
    veil.classList.add('is-waking');
    veil.addEventListener('animationend', function (ev) {
      var name = (ev && ev.animationName) ? String(ev.animationName) : '';
      if (name.indexOf('msbEntryWake') !== -1) clearWake();
    });
  }

  function whenBody(fn) {
    if (document.body) { fn(); return; }
    document.addEventListener('DOMContentLoaded', fn, { once: true });
  }

  whenBody(function () {
    mountVeil();
    // Let home paint under the solid veil, then wake slowly.
    window.setTimeout(startWake, HOLD_BEFORE_WAKE_MS);
  });

  window.setTimeout(clearWake, FAILSAFE_MS);
})();
</script>
