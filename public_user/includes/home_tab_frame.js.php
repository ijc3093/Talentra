<?php
declare(strict_types=1);
?>
<script>
window.msbFitHomeTabFrame = function(frame){
  if(!frame || !frame.parentElement) return;
  var host = frame.parentElement;
  var rect = host.getBoundingClientRect();
  var w = Math.max(1025, window.innerWidth || document.documentElement.clientWidth || 1025);
  var h = Math.max(host.clientHeight || 0, window.innerHeight || 0);
  frame.style.position = 'absolute';
  frame.style.left = (-rect.left) + 'px';
  frame.style.top = (-rect.top) + 'px';
  frame.style.width = w + 'px';
  frame.style.height = h + 'px';
  frame.style.minWidth = w + 'px';
  frame.style.maxWidth = 'none';
  frame.style.border = '0';
  var hostW = window.innerWidth || document.documentElement.clientWidth || 0;
  if(hostW <= 1024.98) frame.setAttribute('data-msb-host-phone', '1');
  else frame.removeAttribute('data-msb-host-phone');
  frame.removeAttribute('data-msb-host-tablet');
  try{
    var doc = frame.contentDocument;
    if(doc && doc.documentElement){
      doc.documentElement.classList.toggle('msb-phone-viewport', hostW <= 1024.98);
      doc.documentElement.classList.remove('msb-tablet-viewport');
    }
  }catch(err){}
};
window.msbWatchHomeTabFrame = function(frame){
  if(!frame || frame.getAttribute('data-msb-fit') === '1') return;
  frame.setAttribute('data-msb-fit', '1');
  var fit = function(){ window.msbFitHomeTabFrame(frame); };
  frame.addEventListener('load', fit);
  window.addEventListener('resize', fit);
  if(window.visualViewport) window.visualViewport.addEventListener('resize', fit);
  fit();
};
document.addEventListener('click', function(e){
  if(document.documentElement.classList.contains('tab-embed')) return;
  var link = e.target.closest('a.feed-program-nav-item');
  if(!link) return;
  if(link.hasAttribute('hidden') || link.getAttribute('aria-hidden') === 'true') return;
  try{
    var hrefUrl = new URL(link.href, window.location.href);
    if(!/home\.php$/i.test(hrefUrl.pathname)) return;
  }catch(err){
    return;
  }
  if(typeof window.msbSwitchHomeTabLink !== 'function') return;
  e.preventDefault();
  e.stopPropagation();
  if(link.classList.contains('is-active')) return;
  window.msbSwitchHomeTabLink(link);
}, true);
</script>
