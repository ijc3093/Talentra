<?php
declare(strict_types=1);

/**
 * Client-side Talsora splash for login/register form submits.
 * Call window.msbShowTalsoraBootSplash(optionalMessage) before navigation.
 */
?>
<style id="msb-talentra-boot-inline-css">
  .msb-te-boot{
    position:fixed;inset:0;z-index:2147483000;display:grid;place-items:center;
    padding:24px 16px;isolation:isolate;overflow:hidden;
    background:
      radial-gradient(1200px 700px at 50% -10%, rgba(232,201,138,.16), transparent 55%),
      radial-gradient(900px 600px at 12% 88%, rgba(159,214,200,.14), transparent 50%),
      linear-gradient(165deg,#05090f 0%,#0b1622 48%,#08131c 100%);
    color:#f4f1ea;font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;
  }
  .msb-te-boot[hidden]{display:none!important}
  .msb-te-boot-aurora{
    position:absolute;inset:-20%;opacity:.5;pointer-events:none;filter:blur(36px);
    background:conic-gradient(from 180deg at 50% 50%, rgba(232,201,138,0), rgba(232,201,138,.12), rgba(159,214,200,0), rgba(159,214,200,.14), rgba(232,201,138,0));
    animation:msbTeAurora 8s linear infinite;
  }
  .msb-te-boot-inner{position:relative;z-index:2;display:flex;flex-direction:column;align-items:center;gap:14px;text-align:center;width:min(92vw,420px)}
  .msb-te-boot-orb{position:relative;width:104px;height:104px;margin-bottom:6px}
  .msb-te-boot-ring{
    position:absolute;inset:0;border-radius:50%;
    background:conic-gradient(from 0deg, rgba(232,201,138,0), rgba(232,201,138,.95), rgba(159,214,200,.8), rgba(232,201,138,0));
    -webkit-mask:radial-gradient(farthest-side, transparent calc(100% - 2px), #000 calc(100% - 1px));
            mask:radial-gradient(farthest-side, transparent calc(100% - 2px), #000 calc(100% - 1px));
    animation:msbTeSpin 2.6s cubic-bezier(.4,.1,.2,1) infinite;
  }
  .msb-te-boot-mark{
    position:absolute;inset:0;display:grid;place-items:center;
    font-family:Georgia,"Times New Roman",serif;font-size:42px;font-weight:700;color:#e8c98a;line-height:1;
    text-shadow:0 0 18px rgba(232,201,138,.35);
  }
  .msb-te-boot-brand{
    margin:0;font-family:Georgia,"Times New Roman",serif;font-size:clamp(2.2rem,7vw,2.9rem);font-weight:600;letter-spacing:.02em;
    display:flex;justify-content:center;gap:.015em;
  }
  .msb-te-boot-brand span{
    display:inline-block;opacity:0;transform:translateY(16px);filter:blur(5px);
    animation:msbTeLetter .7s cubic-bezier(.16,1,.3,1) forwards;
  }
  .msb-te-boot-rule{
    width:0;height:1px;margin-top:2px;
    background:linear-gradient(90deg,transparent,#e8c98a,#9fd6c8,transparent);
    animation:msbTeRule 1s cubic-bezier(.2,.8,.2,1) .9s forwards;
  }
  .msb-te-boot-status{margin:4px 0 0;font-size:14px;font-weight:600;letter-spacing:.03em;color:rgba(232,240,248,.78)}
  .msb-te-boot-bar{
    width:min(200px,70%);height:2px;margin-top:10px;border-radius:999px;background:rgba(244,241,234,.12);overflow:hidden;
  }
  .msb-te-boot-bar > i{
    display:block;height:100%;width:0;border-radius:inherit;
    background:linear-gradient(90deg,#e8c98a,#9fd6c8);
    animation:msbTeFill 1.8s cubic-bezier(.25,.8,.25,1) .3s infinite;
  }
  @keyframes msbTeAurora{to{transform:rotate(360deg)}}
  @keyframes msbTeSpin{to{transform:rotate(360deg)}}
  @keyframes msbTeLetter{to{opacity:1;transform:translateY(0);filter:blur(0)}}
  @keyframes msbTeRule{to{width:min(200px,58%)}}
  @keyframes msbTeFill{0%{width:0}60%{width:100%}100%{width:100%;opacity:.35}}
  @media (prefers-reduced-motion:reduce){
    .msb-te-boot-aurora,.msb-te-boot-ring,.msb-te-boot-brand span,.msb-te-boot-rule,.msb-te-boot-bar > i{animation:none!important}
    .msb-te-boot-brand span{opacity:1;transform:none;filter:none}
    .msb-te-boot-rule{width:min(200px,58%)}
    .msb-te-boot-bar > i{width:100%}
  }
</style>
<script>
(function () {
  if (window.msbShowTalsoraBootSplash) return;
  window.msbShowTalsoraBootSplash = function (message) {
    var existing = document.getElementById('msbTalsoraBootSplash');
    if (existing) {
      existing.removeAttribute('hidden');
      var status = existing.querySelector('.msb-te-boot-status');
      if (status && message) status.textContent = String(message);
      return existing;
    }
    var brand = 'Talsora'.split('').map(function (ch, i) {
      return '<span style="animation-delay:' + (0.35 + i * 0.06).toFixed(2) + 's">' + ch + '</span>';
    }).join('');
    var el = document.createElement('div');
    el.id = 'msbTalsoraBootSplash';
    el.className = 'msb-te-boot';
    el.setAttribute('role', 'status');
    el.setAttribute('aria-live', 'polite');
    el.setAttribute('aria-busy', 'true');
    el.innerHTML =
      '<div class="msb-te-boot-aurora" aria-hidden="true"></div>' +
      '<div class="msb-te-boot-inner">' +
        '<div class="msb-te-boot-orb" aria-hidden="true"><div class="msb-te-boot-ring"></div><div class="msb-te-boot-mark">T</div></div>' +
        '<h1 class="msb-te-boot-brand" aria-label="Talsora">' + brand + '</h1>' +
        '<div class="msb-te-boot-rule" aria-hidden="true"></div>' +
        '<p class="msb-te-boot-status"></p>' +
        '<div class="msb-te-boot-bar" aria-hidden="true"><i></i></div>' +
      '</div>';
    var statusEl = el.querySelector('.msb-te-boot-status');
    if (statusEl) statusEl.textContent = String(message || 'Opening Talsora');
    document.body.appendChild(el);
    try { document.documentElement.style.overflow = 'hidden'; } catch (e) {}
    return el;
  };
})();
</script>
