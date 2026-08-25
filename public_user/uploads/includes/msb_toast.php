<div class="msb-toast-stack" id="msbToastStack" aria-live="polite" aria-atomic="true"></div>

<style>
.msb-toast-stack{
  position:fixed;
  left:50%;
  bottom:24px;
  transform:translateX(-50%);
  z-index:1600;
  display:flex;
  flex-direction:column;
  align-items:center;
  gap:10px;
  width:min(100% - 32px, 440px);
  pointer-events:none;
}
.msb-toast{
  pointer-events:auto;
  width:100%;
  display:flex;
  align-items:flex-start;
  gap:14px;
  padding:16px 16px 16px 14px;
  border-radius:18px;
  border:1px solid rgba(15,23,42,.08);
  background:#fff;
  color:#0f172a;
  box-shadow:0 18px 48px rgba(15,23,42,.16), 0 2px 8px rgba(15,23,42,.08);
  opacity:0;
  transform:translateY(18px) scale(.98);
  transition:opacity .28s ease, transform .28s ease;
}
.msb-toast.is-visible{
  opacity:1;
  transform:translateY(0) scale(1);
}
.msb-toast.is-leaving{
  opacity:0;
  transform:translateY(10px) scale(.98);
}
.msb-toast-icon{
  flex:0 0 auto;
  width:42px;
  height:42px;
  border-radius:14px;
  display:flex;
  align-items:center;
  justify-content:center;
  font-size:20px;
  line-height:1;
}
.msb-toast-body{
  flex:1 1 auto;
  min-width:0;
  padding-top:2px;
}
.msb-toast-title{
  font-size:15px;
  font-weight:800;
  line-height:1.25;
  letter-spacing:-.01em;
  margin:0 0 4px;
  color:#0f172a;
}
.msb-toast-message{
  margin:0;
  font-size:13px;
  line-height:1.45;
  color:#475467;
}
.msb-toast-actions{
  display:flex;
  align-items:center;
  gap:8px;
  flex:0 0 auto;
  padding-top:2px;
}
.msb-toast-close{
  width:34px;
  height:34px;
  border:0;
  border-radius:12px;
  background:transparent;
  color:#667085;
  font-size:22px;
  line-height:1;
  cursor:pointer;
  display:flex;
  align-items:center;
  justify-content:center;
}
.msb-toast-close:hover{
  background:rgba(15,23,42,.06);
  color:#0f172a;
}
.msb-toast-action{
  display:inline-flex;
  align-items:center;
  justify-content:center;
  min-height:34px;
  padding:0 14px;
  border:0;
  border-radius:999px;
  background:#0f172a;
  color:#fff;
  font-size:12px;
  font-weight:800;
  letter-spacing:.01em;
  text-decoration:none;
  cursor:pointer;
  white-space:nowrap;
}
.msb-toast-action:hover{
  opacity:.92;
  color:#fff;
  text-decoration:none;
}
.msb-toast.is-warn .msb-toast-icon{
  background:#fff7ed;
  color:#ea580c;
}
.msb-toast.is-error .msb-toast-icon{
  background:#fef2f2;
  color:#dc2626;
}
.msb-toast.is-success .msb-toast-icon{
  background:#ecfdf3;
  color:#16a34a;
}
.msb-toast.is-info .msb-toast-icon{
  background:#eff6ff;
  color:#2563eb;
}
.msb-toast.is-warn .msb-toast-action{ background:#ea580c; }
.msb-toast.is-error .msb-toast-action{ background:#dc2626; }
.msb-toast.is-success .msb-toast-action{ background:#16a34a; }
.msb-toast.is-info .msb-toast-action{ background:#2563eb; }

html.dark-auto .msb-toast,
html[data-theme="dark"] .msb-toast{
  background:#111827;
  border-color:rgba(255,255,255,.08);
  color:#f8fafc;
  box-shadow:0 18px 48px rgba(0,0,0,.42), 0 2px 8px rgba(0,0,0,.28);
}
html.dark-auto .msb-toast-title,
html[data-theme="dark"] .msb-toast-title{ color:#f8fafc; }
html.dark-auto .msb-toast-message,
html[data-theme="dark"] .msb-toast-message{ color:#cbd5e1; }
html.dark-auto .msb-toast-close,
html[data-theme="dark"] .msb-toast-close{ color:#94a3b8; }
html.dark-auto .msb-toast-close:hover,
html[data-theme="dark"] .msb-toast-close:hover{
  background:rgba(255,255,255,.08);
  color:#f8fafc;
}

@media (max-width:767px){
  .msb-toast-stack{
    bottom:calc(18px + env(safe-area-inset-bottom, 0px));
    width:min(100% - 20px, 440px);
  }
  .msb-toast{
    flex-wrap:wrap;
    padding:14px;
  }
  .msb-toast-actions{
    width:100%;
    justify-content:flex-end;
    padding-top:4px;
  }
}
</style>

<script>
(function(){
  if(window.MSBToast) return;

  var stack = document.getElementById('msbToastStack');
  var iconMap = {
    warn: 'icon ion-alert-circled',
    error: 'icon ion-close-circled',
    success: 'icon ion-checkmark-circled',
    info: 'icon ion-information-circled'
  };

  function esc(s){
    return String(s || '').replace(/[&<>"']/g, function(m){
      return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]);
    });
  }

  function dismissToast(node){
    if(!node || node.classList.contains('is-leaving')) return;
    node.classList.remove('is-visible');
    node.classList.add('is-leaving');
    clearTimeout(node._msbToastTimer || 0);
    setTimeout(function(){
      if(node.parentNode) node.parentNode.removeChild(node);
    }, 280);
  }

  function showToast(opts){
    opts = opts || {};
    if(!stack) return null;

    var type = ['warn','error','success','info'].indexOf(String(opts.type || 'warn')) >= 0
      ? String(opts.type || 'warn')
      : 'warn';
    var title = String(opts.title || '').trim();
    var message = String(opts.message || '').trim();
    if(!title && !message) return null;

    var toast = document.createElement('div');
    toast.className = 'msb-toast is-' + type;
    toast.setAttribute('role', type === 'error' || type === 'warn' ? 'alert' : 'status');

    var actionLabel = String(opts.actionLabel || '').trim();
    var actionHref = String(opts.actionHref || '').trim();
    var actionModal = !!opts.actionModal;

    toast.innerHTML =
      '<div class="msb-toast-icon" aria-hidden="true"><i class="' + esc(iconMap[type] || iconMap.warn) + '"></i></div>' +
      '<div class="msb-toast-body">' +
        (title ? '<div class="msb-toast-title">' + esc(title) + '</div>' : '') +
        (message ? '<p class="msb-toast-message">' + esc(message) + '</p>' : '') +
      '</div>' +
      '<div class="msb-toast-actions">' +
        (actionLabel
          ? '<a class="msb-toast-action" href="' + esc(actionHref || '#') + '"' +
              (actionModal ? ' data-create-post-modal="1"' : '') + '>' + esc(actionLabel) + '</a>'
          : '') +
        '<button type="button" class="msb-toast-close" aria-label="Dismiss">&times;</button>' +
      '</div>';

    var closeBtn = toast.querySelector('.msb-toast-close');
    if(closeBtn){
      closeBtn.addEventListener('click', function(e){
        e.preventDefault();
        dismissToast(toast);
      });
    }

    var actionBtn = toast.querySelector('.msb-toast-action');
    if(actionBtn && actionModal){
      actionBtn.addEventListener('click', function(e){
        if(window.MSBCreatePostModal && typeof window.MSBCreatePostModal.open === 'function'){
          e.preventDefault();
          dismissToast(toast);
          window.MSBCreatePostModal.open(actionHref || 'dashboard.php?modal=1');
        }
      });
    }

    stack.appendChild(toast);
    requestAnimationFrame(function(){
      toast.classList.add('is-visible');
    });

    var duration = Number(opts.duration || 9000);
    if(duration > 0){
      toast._msbToastTimer = setTimeout(function(){ dismissToast(toast); }, duration);
    }

    return toast;
  }

  window.MSBToast = {
    show: showToast,
    dismiss: dismissToast,
    dismissAll: function(){
      if(!stack) return;
      Array.prototype.slice.call(stack.querySelectorAll('.msb-toast')).forEach(dismissToast);
    }
  };
})();
</script>
