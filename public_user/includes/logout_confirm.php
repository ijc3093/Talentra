<?php
if (!empty($GLOBALS['msb_logout_confirm_included'])) {
  return;
}
$GLOBALS['msb_logout_confirm_included'] = true;
?>
<style id="msb-logout-confirm-css">
.msb-logout-dialog{
  width:min(360px, calc(100vw - 32px));
  max-width:360px;
  padding:20px 18px 16px;
  border:1px solid var(--msb-palette-border, rgba(148,163,184,.28));
  border-radius:14px;
  background:var(--msb-palette-surface, var(--msb-palette-bg, #171d24));
  color:var(--msb-palette-text, #f4f6fb);
  box-shadow:0 18px 48px rgba(0,0,0,.4);
  text-align:center;
}
.msb-logout-dialog::backdrop{background:rgba(15,23,42,.62);backdrop-filter:blur(5px);}
.msb-logout-dialog h2{margin:0 0 8px;font-size:16px;font-weight:800;line-height:1.3;color:inherit;}
.msb-logout-dialog p{margin:0;font-size:13px;line-height:1.45;color:var(--msb-palette-text-muted, #98a2b3);}
.msb-logout-actions{display:flex;gap:8px;margin-top:16px;}
.msb-logout-actions button{
  flex:1 1 0;
  height:34px;
  border-radius:10px;
  font-size:13px;
  font-weight:800;
  cursor:pointer;
}
.msb-logout-dialog:focus,
.msb-logout-dialog:focus-visible,
.msb-logout-actions button:focus,
.msb-logout-actions button:focus-visible{
  outline:none !important;
  box-shadow:none !important;
}
.msb-logout-cancel{
  border:1px solid var(--msb-palette-border,#c0c2c4);
  background:transparent;
  color:var(--msb-palette-text,#f4f6fb);
}
.msb-logout-cancel:hover{background:var(--msb-palette-hover-bg, rgba(255,255,255,.06));}
.msb-logout-go{border:1px solid #dc2626;background:#dc2626;color:#fff;}
.msb-logout-go:hover{background:#b91c1c;border-color:#b91c1c;}
</style>
<dialog class="msb-logout-dialog" id="msbLogoutConfirmDialog" aria-labelledby="msbLogoutConfirmTitle">
  <h2 id="msbLogoutConfirmTitle">Log out?</h2>
  <p>Cancel to stay. Logout ends this session and you cannot come back without signing in.</p>
  <div class="msb-logout-actions">
    <button type="button" class="msb-logout-cancel" id="msbLogoutConfirmCancel">Cancel</button>
    <button type="button" class="msb-logout-go" id="msbLogoutConfirmGo">Logout</button>
  </div>
</dialog>
<script>
(function(){
  var dialog = document.getElementById('msbLogoutConfirmDialog');
  var cancelBtn = document.getElementById('msbLogoutConfirmCancel');
  var goBtn = document.getElementById('msbLogoutConfirmGo');
  if (!dialog) return;

  function closeDialog(){
    if (dialog.open) dialog.close();
  }

  function openDialog(){
    if (dialog.showModal) dialog.showModal();
  }

  function isSignOutLink(a){
    if (!a || a.classList.contains('js-as-add-logout')) return false;
    var href = (a.getAttribute('href') || '').trim();
    if (!/logout\.php(\?|$|#)/i.test(href)) return false;
    if (a.classList.contains('js-signout-confirm')) return true;
    if (a.closest('#tt-profile-wrap')) return true;
    if (a.closest('#tt-menu-wrap')) return true;
    if (a.closest('.feed-left-rail-footer')) return true;
    if (a.closest('.dropdown-profile-nav, .bestprofile-nav')) return true;
    return false;
  }

  document.addEventListener('click', function(e){
    var a = e.target && e.target.closest ? e.target.closest('a') : null;
    if (!isSignOutLink(a)) return;
    if (e.button !== 0) return;
    if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
    e.preventDefault();
    e.stopPropagation();
    openDialog();
  }, true);

  if (cancelBtn) cancelBtn.addEventListener('click', function(){ closeDialog(); });
  if (goBtn) {
    goBtn.addEventListener('click', function(){
      window.location.replace('logout.php');
    });
  }
  dialog.addEventListener('cancel', function(e){
    e.preventDefault();
    closeDialog();
  });

  window.addEventListener('pageshow', function(e){
    if (e.persisted) window.location.reload();
  });
})();
</script>
