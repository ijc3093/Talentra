<?php
declare(strict_types=1);

/**
 * Smooth dark bridge: login/register submit → entry.php.
 * Call window.msbArmEntryBridge() when a successful submit is about to navigate.
 */
?>
<style id="msb-entry-bridge-css">
  .msb-entry-bridge{
    position:fixed;inset:0;z-index:2147483000;
    background:#05090f;
    opacity:0;pointer-events:none;
    transition:opacity .6s cubic-bezier(.4,0,.2,1);
  }
  .msb-entry-bridge.is-on{
    opacity:1;pointer-events:auto;
  }
  @media (prefers-reduced-motion:reduce){
    .msb-entry-bridge{transition:opacity .18s ease}
  }
</style>
<script>
(function () {
  if (window.msbArmEntryBridge) return;
  var KEY = 'msbEntryArrive';
  var COLOR = '#05090f';

  window.msbArmEntryBridge = function () {
    try { sessionStorage.setItem(KEY, '1'); } catch (e) {}
    try {
      document.documentElement.style.background = COLOR;
      if (document.body) document.body.style.background = COLOR;
    } catch (e2) {}
    var el = document.getElementById('msbEntryBridge');
    if (!el) {
      el = document.createElement('div');
      el.id = 'msbEntryBridge';
      el.className = 'msb-entry-bridge';
      el.setAttribute('aria-hidden', 'true');
      (document.body || document.documentElement).appendChild(el);
    }
    void el.offsetWidth;
    el.classList.add('is-on');
    return el;
  };
})();
</script>
