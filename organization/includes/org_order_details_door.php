<?php
declare(strict_types=1);

if (!empty($GLOBALS['msb_org_order_details_door_included'])) {
    return;
}
$GLOBALS['msb_org_order_details_door_included'] = true;
?>
<style>
.org-order-details-door{
  position:fixed;
  inset:0;
  z-index:12060;
  pointer-events:none;
}
.org-order-details-door.is-open{
  pointer-events:auto;
}
.org-order-details-door-backdrop{
  position:absolute;
  inset:0;
  border:0;
  padding:0;
  margin:0;
  background:rgba(15,23,42,.48);
  opacity:0;
  transition:opacity .2s ease;
  cursor:pointer;
}
.org-order-details-door.is-open .org-order-details-door-backdrop{
  opacity:1;
}
.org-order-details-door-panel{
  position:absolute;
  top:0;
  right:0;
  bottom:0;
  width:min(440px, 96vw);
  background:var(--bg-card, #fff);
  color:var(--text-primary, #111827);
  border-left:1px solid var(--border-color, #e5e7eb);
  box-shadow:-18px 0 48px rgba(0,0,0,.22);
  display:flex;
  flex-direction:column;
  transform:translateX(105%);
  transition:transform .2s ease;
  overflow:hidden;
}
.org-order-details-door.is-open .org-order-details-door-panel{
  transform:translateX(0);
}
.org-order-details-door-head{
  flex:0 0 auto;
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:12px;
  padding:16px 18px;
  border-bottom:1px solid var(--border-color, #e5e7eb);
  background:var(--bg-card, #fff);
}
.org-order-details-door-head .title{
  font-weight:900;
  font-size:17px;
  line-height:1.2;
  color:var(--text-primary, #111827);
}
.org-order-details-door-head .sub{
  display:block;
  margin-top:3px;
  font-size:12px;
  font-weight:600;
  color:var(--text-secondary, #6b7280);
}
.org-order-details-door-actions{
  display:inline-flex;
  align-items:center;
  gap:8px;
  flex:0 0 auto;
}
.org-order-details-door-close,
.org-order-details-door-download{
  width:38px;
  height:38px;
  border-radius:999px;
  border:1px solid var(--border-color, #e5e7eb);
  background:var(--bg-main, #fff);
  color:var(--text-primary, #111827);
  display:inline-flex;
  align-items:center;
  justify-content:center;
  cursor:pointer;
  flex:0 0 auto;
  text-decoration:none;
  font-size:18px;
  line-height:1;
  padding:0;
}
.org-order-details-door-close:hover,
.org-order-details-door-download:hover{
  background:var(--bg-sidebar, #f3f4f6);
  color:var(--text-primary, #111827);
  text-decoration:none;
}
.org-order-details-door-download[aria-disabled="true"],
.org-order-details-door-download.is-disabled{
  opacity:.45;
  pointer-events:none;
}
.org-order-details-door-frame{
  flex:1 1 auto;
  width:100%;
  min-height:0;
  border:0;
  display:block;
  background:var(--bg-card, #fff);
}
body.org-order-details-door-open{
  overflow:hidden !important;
}
.js-open-org-order-door{
  cursor:pointer;
}
html.dark-auto .org-order-details-door-panel,
html.dark-auto .org-order-details-door-head,
html.dark-auto .org-order-details-door-close,
html.dark-auto .org-order-details-door-download,
html.dark-auto .org-order-details-door-frame{
  background:#171d24;
  color:#e5e7eb;
  border-color:#334155;
}
html.dark-auto .org-order-details-door-head .title{color:#f8fafc;}
html.dark-auto .org-order-details-door-head .sub{color:#94a3b8;}
html.dark-auto .org-order-details-door-close:hover,
html.dark-auto .org-order-details-door-download:hover{
  background:#1f2937;
  color:#f8fafc;
}
</style>

<div class="org-order-details-door" id="orgOrderDetailsDoor" aria-hidden="true">
  <button type="button" class="org-order-details-door-backdrop" id="orgOrderDetailsDoorBackdrop" aria-label="Close order details"></button>
  <aside class="org-order-details-door-panel" role="dialog" aria-modal="true" aria-labelledby="orgOrderDetailsDoorTitle">
    <div class="org-order-details-door-head">
      <div>
        <span class="title" id="orgOrderDetailsDoorTitle">Order details</span>
        <span class="sub" id="orgOrderDetailsDoorSub">Customer purchase</span>
      </div>
      <div class="org-order-details-door-actions">
        <a
          href="#"
          class="org-order-details-door-download is-disabled"
          id="orgOrderDetailsDoorDownload"
          aria-label="Download order details"
          title="Download"
          aria-disabled="true"
        >
          <i class="icon ion-android-download" aria-hidden="true"></i>
        </a>
        <button type="button" class="org-order-details-door-close" id="orgOrderDetailsDoorClose" aria-label="Close order details">
          <i class="icon ion-close" aria-hidden="true"></i>
        </button>
      </div>
    </div>
    <iframe
      class="org-order-details-door-frame"
      id="orgOrderDetailsDoorFrame"
      title="Order details"
      src="about:blank"
    ></iframe>
  </aside>
</div>

<script>
(function () {
  if (window.__msbOrgOrderDetailsDoorInit) return;
  window.__msbOrgOrderDetailsDoorInit = true;

  var door = document.getElementById('orgOrderDetailsDoor');
  var frame = document.getElementById('orgOrderDetailsDoorFrame');
  var titleEl = document.getElementById('orgOrderDetailsDoorTitle');
  var subEl = document.getElementById('orgOrderDetailsDoorSub');
  var closeBtn = document.getElementById('orgOrderDetailsDoorClose');
  var downloadBtn = document.getElementById('orgOrderDetailsDoorDownload');
  var backdrop = document.getElementById('orgOrderDetailsDoorBackdrop');
  if (!door || !frame) return;

  function toDownloadUrl(url) {
    if (!url) return '';
    var next = String(url);
    next = next.replace(/([?&])embed=1(&|$)/, function (_, a, b) {
      return a + 'download=1' + (b || '');
    });
    if (next.indexOf('download=1') === -1) {
      next += (next.indexOf('?') === -1 ? '?' : '&') + 'download=1';
    }
    return next;
  }

  function setDownloadUrl(url) {
    if (!downloadBtn) return;
    var href = toDownloadUrl(url);
    if (!href) {
      downloadBtn.setAttribute('href', '#');
      downloadBtn.setAttribute('aria-disabled', 'true');
      downloadBtn.classList.add('is-disabled');
      return;
    }
    downloadBtn.setAttribute('href', href);
    downloadBtn.setAttribute('download', '');
    downloadBtn.removeAttribute('aria-disabled');
    downloadBtn.classList.remove('is-disabled');
  }

  function closeDoor() {
    door.classList.remove('is-open');
    door.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('org-order-details-door-open');
    try { frame.src = 'about:blank'; } catch (e) {}
    setDownloadUrl('');
  }

  function openDoor(url, label) {
    if (!url) return;
    if (titleEl) titleEl.textContent = 'Order details';
    if (subEl) subEl.textContent = label || 'Customer purchase';
    frame.src = url;
    setDownloadUrl(url);
    door.classList.add('is-open');
    door.setAttribute('aria-hidden', 'false');
    document.body.classList.add('org-order-details-door-open');
  }

  if (closeBtn) closeBtn.addEventListener('click', closeDoor);
  if (backdrop) backdrop.addEventListener('click', closeDoor);
  if (downloadBtn) {
    downloadBtn.addEventListener('click', function (e) {
      if (downloadBtn.classList.contains('is-disabled') || downloadBtn.getAttribute('aria-disabled') === 'true') {
        e.preventDefault();
        return;
      }
      // Open download in a new tab so the door iframe stays put.
      e.preventDefault();
      var href = downloadBtn.getAttribute('href') || '';
      if (href && href !== '#') {
        window.open(href, '_blank', 'noopener');
      }
    });
  }
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && door.classList.contains('is-open')) closeDoor();
  });

  document.addEventListener('click', function (e) {
    var btn = e.target && e.target.closest ? e.target.closest('.js-open-org-order-door') : null;
    if (!btn) return;
    e.preventDefault();
    e.stopPropagation();
    var url = btn.getAttribute('data-door-url') || btn.getAttribute('href') || '';
    var label = btn.getAttribute('data-door-label') || 'Customer purchase';
    if (url.indexOf('embed=1') === -1) {
      url += (url.indexOf('?') === -1 ? '?' : '&') + 'embed=1';
    }
    openDoor(url, label);
  });

  window.OrgOrderDetailsDoor = { open: openDoor, close: closeDoor };
})();
</script>
