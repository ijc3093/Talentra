<?php
declare(strict_types=1);

/**
 * Shared client helper for abuse reports.
 * Uses the same centered dialog pattern as fries Delete / Tag (not window.prompt).
 * Exposes window.msbSubmitReport({ target_type, target_id, endpoint?, onDone?, silent? })
 */
if (defined('MSB_REPORT_CLIENT_UI')) {
    return;
}
define('MSB_REPORT_CLIENT_UI', true);
?>
<style id="msb-report-dialog-css">
  html body dialog.pcm-delete-dialog.msb-report-dialog{
    position:fixed!important;inset:0!important;top:0!important;right:0!important;bottom:0!important;left:0!important;
    width:min(360px,calc(100vw - 32px))!important;max-width:360px!important;height:max-content!important;min-height:0!important;
    max-height:calc(100dvh - 32px)!important;margin:auto!important;padding:20px 18px 16px!important;overflow:auto!important;
    transform:none!important;border:1px solid var(--msb-palette-border,rgba(148,163,184,.28))!important;border-radius:14px!important;
    background:var(--msb-palette-surface,var(--msb-palette-bg,#fff))!important;color:var(--msb-palette-text,#111827)!important;
    box-shadow:0 18px 48px rgba(0,0,0,.28)!important;text-align:center!important;box-sizing:border-box!important;z-index:2147483647!important;
  }
  .msb-report-dialog::backdrop{background:rgba(15,23,42,.62);backdrop-filter:blur(5px);-webkit-backdrop-filter:blur(5px)}
  html body dialog.msb-report-dialog:not([open]){display:none!important}
  html body dialog.msb-report-dialog[open]{display:block!important}
  html body .msb-report-dialog .pcm-delete-dialog-close{
    position:absolute!important;top:10px!important;right:10px!important;width:28px!important;height:28px!important;margin:0!important;padding:0!important;
    border:0!important;border-radius:50%!important;background:transparent!important;color:var(--msb-palette-text-muted,#64748b)!important;
    font-size:18px!important;line-height:28px!important;cursor:pointer!important;display:inline-flex!important;align-items:center!important;justify-content:center!important;
  }
  html body .msb-report-dialog .pcm-delete-dialog-close:hover{
    background:var(--msb-palette-hover-bg,rgba(148,163,184,.14));color:var(--msb-palette-text,#111827);
  }
  html body .msb-report-dialog-icon{
    position:static!important;display:grid!important;place-items:center!important;width:40px!important;height:40px!important;
    margin:0 auto 10px!important;border-radius:50%!important;background:rgba(220,38,38,.12)!important;color:#dc2626!important;font-size:16px!important;
  }
  html body .msb-report-dialog h2{
    position:static!important;display:block!important;margin:0 28px 6px!important;padding:0!important;color:inherit!important;
    font-size:15px!important;font-weight:700!important;line-height:1.3!important;
  }
  html body .msb-report-dialog p.msb-report-sub{
    position:static!important;display:block!important;margin:0 0 12px!important;padding:0!important;
    color:var(--msb-palette-text-muted,#64748b)!important;font-size:13px!important;line-height:1.45!important;
  }
  html body .msb-report-reasons{
    display:flex!important;flex-wrap:wrap!important;gap:6px!important;justify-content:center!important;margin:0 0 12px!important;
  }
  html body .msb-report-reason-chip{
    appearance:none!important;-webkit-appearance:none!important;margin:0!important;padding:6px 10px!important;border-radius:999px!important;
    border:1px solid var(--msb-palette-border,rgba(148,163,184,.38))!important;
    background:var(--msb-palette-hover-bg,rgba(148,163,184,.08))!important;color:var(--msb-palette-text,#111827)!important;
    font-size:12px!important;font-weight:600!important;line-height:1.2!important;cursor:pointer!important;
  }
  html body .msb-report-reason-chip.is-active{
    border-color:#dc2626!important;background:rgba(220,38,38,.12)!important;color:#b91c1c!important;
  }
  html body .msb-report-details{
    width:100%!important;min-height:72px!important;margin:0!important;padding:10px 12px!important;box-sizing:border-box!important;
    border:1px solid var(--msb-palette-border,rgba(148,163,184,.38))!important;border-radius:12px!important;
    background:var(--msb-palette-surface,var(--msb-palette-bg,#fff))!important;color:var(--msb-palette-text,#111827)!important;
    font-size:13px!important;line-height:1.4!important;resize:vertical!important;
  }
  html body .msb-report-details:focus{
    outline:none!important;border-color:rgba(220,38,38,.45)!important;box-shadow:0 0 0 3px rgba(220,38,38,.12)!important;
  }
  html body .msb-report-dialog .pcm-delete-dialog-actions{
    position:static!important;display:flex!important;gap:8px!important;width:100%!important;margin:16px 0 0!important;padding:0!important;
  }
  html body .msb-report-dialog .pcm-delete-dialog-actions button{
    flex:1 1 0!important;height:34px!important;border-radius:999px!important;font-size:13px!important;font-weight:600!important;cursor:pointer!important;
  }
  html body .msb-report-dialog .pcm-delete-dialog-cancel{
    border:1px solid var(--msb-palette-border,rgba(148,163,184,.38))!important;
    background:var(--msb-palette-hover-bg,transparent)!important;color:var(--msb-palette-text,#111827)!important;
  }
  html body .msb-report-dialog .pcm-delete-dialog-confirm{
    border:1px solid #dc2626!important;background:#dc2626!important;color:#fff!important;
  }
  html body .msb-report-dialog .pcm-delete-dialog-confirm[disabled]{opacity:.65!important;cursor:wait!important}
</style>
<dialog class="pcm-delete-dialog msb-report-dialog" id="msbReportDialog" aria-labelledby="msbReportTitle">
  <button type="button" class="pcm-delete-dialog-close" data-msb-report-dismiss aria-label="Close">&times;</button>
  <div class="pcm-delete-dialog-icon msb-report-dialog-icon" aria-hidden="true"><i class="fa fa-flag"></i></div>
  <h2 id="msbReportTitle">Report this?</h2>
  <p class="msb-report-sub">Tell admin why. Pick a reason — optional details help review.</p>
  <div class="msb-report-reasons" id="msbReportReasons" role="group" aria-label="Report reason"></div>
  <textarea id="msbReportDetails" class="msb-report-details" placeholder="Optional details for admin…" rows="3" maxlength="2000"></textarea>
  <div class="pcm-delete-dialog-actions">
    <button type="button" class="pcm-delete-dialog-cancel" data-msb-report-dismiss>Cancel</button>
    <button type="button" class="pcm-delete-dialog-confirm" id="msbReportSubmitBtn">Report</button>
  </div>
</dialog>
<script>
(function(){
  if (window.msbSubmitReport) return;

  var REASONS = [
    { id: 'spam', label: 'Spam' },
    { id: 'harassment', label: 'Harassment' },
    { id: 'hate', label: 'Hate' },
    { id: 'violence', label: 'Violence' },
    { id: 'nudity', label: 'Nudity' },
    { id: 'scam', label: 'Scam' },
    { id: 'fake_product', label: 'Fake product' },
    { id: 'copyright', label: 'Copyright' },
    { id: 'other', label: 'Other' }
  ];

  var pending = null;
  var selectedReason = 'spam';

  function normalizeReason(raw){
    var s = String(raw || '').toLowerCase().replace(/[\s-]+/g, '_').trim();
    for (var i = 0; i < REASONS.length; i += 1) {
      if (REASONS[i].id === s) return s;
    }
    return 'other';
  }

  function defaultEndpoint(){
    try {
      return new URL('ajax/report_action.php', window.location.href).pathname;
    } catch (_e) {}
    return 'ajax/report_action.php';
  }

  function ensureDialog(){
    var dialog = document.getElementById('msbReportDialog');
    if (!dialog) return null;
    if (dialog.parentNode !== document.body) {
      try { document.body.appendChild(dialog); } catch (_e) {}
    }
    var wrap = document.getElementById('msbReportReasons');
    if (wrap && !wrap.getAttribute('data-built')) {
      wrap.setAttribute('data-built', '1');
      wrap.innerHTML = '';
      REASONS.forEach(function(item){
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'msb-report-reason-chip';
        btn.setAttribute('data-reason', item.id);
        btn.textContent = item.label;
        wrap.appendChild(btn);
      });
    }
    return dialog;
  }

  function setReason(reason){
    selectedReason = normalizeReason(reason);
    var wrap = document.getElementById('msbReportReasons');
    if (!wrap) return;
    wrap.querySelectorAll('.msb-report-reason-chip').forEach(function(btn){
      btn.classList.toggle('is-active', btn.getAttribute('data-reason') === selectedReason);
    });
  }

  function closeReportDialog(result){
    var dialog = document.getElementById('msbReportDialog');
    if (dialog) {
      try {
        if (typeof dialog.close === 'function') dialog.close();
        else dialog.removeAttribute('open');
      } catch (_e) {
        try { dialog.removeAttribute('open'); } catch (_e2) {}
      }
    }
    var submitBtn = document.getElementById('msbReportSubmitBtn');
    if (submitBtn) {
      submitBtn.disabled = false;
      submitBtn.textContent = 'Report';
    }
    var resolver = pending && pending.resolve ? pending.resolve : null;
    var opts = pending && pending.opts ? pending.opts : null;
    pending = null;
    if (typeof resolver === 'function') {
      try { resolver(result || { ok:false, cancelled:true }); } catch (_r) {}
    }
    if (opts && typeof opts.onDone === 'function' && result && !result.cancelled) {
      // onDone already called after successful fetch path; skip here for cancel
    }
  }

  function openReportDialog(opts){
    return new Promise(function(resolve){
      var dialog = ensureDialog();
      if (!dialog) {
        resolve({ ok:false, error:'dialog_missing' });
        return;
      }
      pending = { opts: opts || {}, resolve: resolve };
      setReason('spam');
      var details = document.getElementById('msbReportDetails');
      if (details) details.value = '';
      var title = document.getElementById('msbReportTitle');
      var sub = dialog.querySelector('.msb-report-sub');
      var t = String((opts && opts.target_type) || 'other');
      if (title) {
        title.textContent = t === 'user' ? 'Report this person?'
          : (t === 'message' ? 'Report this message?'
          : (t === 'product' ? 'Report this product?'
          : 'Report this?'));
      }
      if (sub) {
        sub.textContent = 'Tell admin why. Pick a reason — optional details help review.';
      }
      setTimeout(function(){
        try {
          if (typeof dialog.showModal === 'function') {
            if (!dialog.open) dialog.showModal();
          } else {
            dialog.setAttribute('open', '');
          }
          var first = dialog.querySelector('.msb-report-reason-chip.is-active') || dialog.querySelector('.msb-report-reason-chip');
          if (first) first.focus();
        } catch (_e) {
          try { dialog.setAttribute('open', ''); } catch (_e2) {}
        }
      }, 0);
    });
  }

  function submitPending(){
    if (!pending || !pending.opts) return;
    var opts = pending.opts;
    var resolve = pending.resolve;
    var targetType = String(opts.target_type || 'other');
    var targetId = Number(opts.target_id || 0);
    var reason = selectedReason;
    var detailsEl = document.getElementById('msbReportDetails');
    var details = detailsEl ? String(detailsEl.value || '').trim() : '';
    var endpoint = String(opts.endpoint || defaultEndpoint());
    var submitBtn = document.getElementById('msbReportSubmitBtn');
    if (submitBtn) {
      submitBtn.disabled = true;
      submitBtn.textContent = 'Sending…';
    }

    var body = new URLSearchParams();
    body.set('target_type', targetType);
    body.set('target_id', String(targetId));
    body.set('reason', reason);
    body.set('details', details);

    fetch(endpoint, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
      credentials: 'same-origin',
      body: body
    }).then(function(r){ return r.json(); }).then(function(data){
      pending = null;
      var dialog = document.getElementById('msbReportDialog');
      if (dialog) {
        try {
          if (typeof dialog.close === 'function') dialog.close();
          else dialog.removeAttribute('open');
        } catch (_e) {}
      }
      if (submitBtn) {
        submitBtn.disabled = false;
        submitBtn.textContent = 'Report';
      }
      if (typeof opts.onDone === 'function') {
        try { opts.onDone(data); } catch (_e) {}
      }
      if (!opts.silent) {
        if (data && data.ok) {
          window.alert(data.message || 'Thanks — report sent to admin.');
        } else if (!(data && data.cancelled)) {
          window.alert((data && data.error) ? data.error : 'Could not submit report.');
        }
      }
      if (typeof resolve === 'function') resolve(data || { ok:false });
    }).catch(function(){
      if (submitBtn) {
        submitBtn.disabled = false;
        submitBtn.textContent = 'Report';
      }
      var fail = { ok:false, error:'network' };
      if (!opts.silent) {
        window.alert('Could not submit report.');
      }
      if (typeof opts.onDone === 'function') {
        try { opts.onDone(fail); } catch (_e) {}
      }
      pending = null;
      var dialog = document.getElementById('msbReportDialog');
      if (dialog) {
        try {
          if (typeof dialog.close === 'function') dialog.close();
          else dialog.removeAttribute('open');
        } catch (_e2) {}
      }
      if (typeof resolve === 'function') resolve(fail);
    });
  }

  document.addEventListener('click', function(e){
    var t = e.target;
    if (!t || !t.closest) return;
    if (t.closest('[data-msb-report-dismiss]')) {
      e.preventDefault();
      closeReportDialog({ ok:false, cancelled:true });
      return;
    }
    var chip = t.closest('.msb-report-reason-chip');
    if (chip && chip.closest('#msbReportDialog')) {
      e.preventDefault();
      setReason(chip.getAttribute('data-reason') || 'other');
      return;
    }
    if (t.closest('#msbReportSubmitBtn')) {
      e.preventDefault();
      submitPending();
    }
  }, true);

  document.addEventListener('keydown', function(e){
    if (e.key !== 'Escape') return;
    var dialog = document.getElementById('msbReportDialog');
    if (dialog && dialog.open) {
      closeReportDialog({ ok:false, cancelled:true });
    }
  }, true);

  /**
   * @param {{target_type:string,target_id:number|string,endpoint?:string,onDone?:function,silent?:boolean}} opts
   */
  window.msbSubmitReport = function(opts){
    opts = opts || {};
    var targetType = String(opts.target_type || 'other');
    var targetId = Number(opts.target_id || 0);
    if (!targetType || (targetType !== 'other' && targetId <= 0)) {
      if (!opts.silent) window.alert('Nothing to report.');
      return Promise.resolve({ ok:false, error:'missing_target' });
    }
    return openReportDialog(opts);
  };

  // Build chips once DOM is ready if script ran early.
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function(){ ensureDialog(); });
  } else {
    ensureDialog();
  }
})();
</script>
