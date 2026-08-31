<?php
declare(strict_types=1);

/**
 * Post-registration welcome modal.
 * Expects $registerWelcome = ['friend_code' => string, 'name' => string, 'username' => string]
 */
if (empty($registerWelcome) || !is_array($registerWelcome)) {
    return;
}

$welcomeCode = strtoupper(trim((string)($registerWelcome['friend_code'] ?? '')));
$welcomeName = trim((string)($registerWelcome['name'] ?? ''));
$welcomeUsername = trim((string)($registerWelcome['username'] ?? ''));
if ($welcomeCode === '') {
    return;
}

$welcomeFirst = $welcomeName !== '' ? preg_split('/\s+/', $welcomeName)[0] : '';
$welcomeGreeting = $welcomeFirst !== '' ? ('Welcome, ' . $welcomeFirst) : 'Welcome aboard';
$welcomeAlreadySignedIn = !empty($_SESSION['user_id']) && !empty($_SESSION['user_login']);
$welcomeContinueLabel = $welcomeAlreadySignedIn ? 'Continue to Home' : 'Continue to Sign In';
?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@500;600&family=Outfit:wght@500;600;700;800&display=swap" rel="stylesheet">
<style>
  .reg-welcome-overlay{
    --rw-ink:#0b1220;
    --rw-muted:#5b6b7c;
    --rw-line:#d7e0ea;
    --rw-panel:#f7fafc;
    --rw-accent:#1d4ed8;
    --rw-go:#16a34a;
    --rw-go-deep:#15803d;
    position:fixed;
    inset:0;
    z-index:12000;
    display:flex;
    align-items:center;
    justify-content:center;
    padding:24px 16px;
    background:
      radial-gradient(900px 520px at 18% 12%, rgba(29,78,216,.28), transparent 58%),
      radial-gradient(700px 480px at 88% 88%, rgba(22,163,74,.18), transparent 55%),
      rgba(15, 23, 42, .72);
    backdrop-filter:blur(8px);
    -webkit-backdrop-filter:blur(8px);
    opacity:0;
    animation:regWelcomeFade .42s ease forwards;
  }
  .reg-welcome-overlay[hidden]{display:none !important}
  .reg-welcome-dialog{
    width:min(100%, 420px);
    border-radius:22px;
    background:
      linear-gradient(180deg, rgba(255,255,255,.97) 0%, var(--rw-panel) 100%);
    box-shadow:
      0 1px 0 rgba(255,255,255,.65) inset,
      0 28px 60px rgba(2, 8, 23, .45);
    overflow:hidden;
    transform:translateY(18px) scale(.97);
    opacity:0;
    animation:regWelcomeRise .5s cubic-bezier(.2,.8,.2,1) .06s forwards;
    font-family:"Outfit", system-ui, sans-serif;
    color:var(--rw-ink);
  }
  .reg-welcome-hero{
    position:relative;
    padding:28px 28px 18px;
    background:
      linear-gradient(135deg, #0f172a 0%, #1e3a5f 48%, #14532d 120%);
    color:#f8fafc;
    overflow:hidden;
  }
  .reg-welcome-hero::after{
    content:"";
    position:absolute;
    inset:auto -20% -55% 35%;
    height:160px;
    background:radial-gradient(circle at center, rgba(74,222,128,.35), transparent 68%);
    pointer-events:none;
  }
  .reg-welcome-brand{
    position:relative;
    margin:0;
    font-size:clamp(1.85rem, 4.5vw, 2.15rem);
    font-weight:800;
    letter-spacing:-.03em;
    line-height:1;
  }
  .reg-welcome-kicker{
    position:relative;
    margin:10px 0 0;
    font-size:.95rem;
    font-weight:500;
    color:rgba(226,232,240,.88);
    line-height:1.4;
  }
  .reg-welcome-body{
    padding:22px 28px 28px;
  }
  .reg-welcome-title{
    margin:0 0 6px;
    font-size:1.15rem;
    font-weight:700;
    letter-spacing:-.02em;
  }
  .reg-welcome-copy{
    margin:0 0 18px;
    font-size:.92rem;
    line-height:1.45;
    color:var(--rw-muted);
    font-weight:500;
  }
  .reg-welcome-code-wrap{
    border:1px solid var(--rw-line);
    border-radius:16px;
    background:#fff;
    padding:14px 14px 12px;
  }
  .reg-welcome-code-label{
    display:block;
    margin:0 0 8px;
    font-size:.72rem;
    font-weight:700;
    letter-spacing:.12em;
    text-transform:uppercase;
    color:#64748b;
  }
  .reg-welcome-code-row{
    display:flex;
    align-items:center;
    gap:10px;
  }
  .reg-welcome-code{
    flex:1;
    min-width:0;
    margin:0;
    font-family:"IBM Plex Mono", ui-monospace, monospace;
    font-size:clamp(1.05rem, 3.4vw, 1.28rem);
    font-weight:600;
    letter-spacing:.04em;
    color:var(--rw-accent);
    word-break:break-all;
  }
  .reg-welcome-copy-btn{
    flex:0 0 auto;
    border:1px solid #bfdbfe;
    background:#eff6ff;
    color:#1d4ed8;
    border-radius:10px;
    padding:8px 12px;
    font-family:inherit;
    font-size:.78rem;
    font-weight:700;
    cursor:pointer;
    transition:background .15s ease, transform .15s ease;
  }
  .reg-welcome-copy-btn:hover{
    background:#dbeafe;
  }
  .reg-welcome-copy-btn.is-copied{
    border-color:#86efac;
    background:#f0fdf4;
    color:#15803d;
  }
  .reg-welcome-hint{
    margin:10px 0 0;
    font-size:.8rem;
    line-height:1.4;
    color:#64748b;
    font-weight:500;
  }
  .reg-welcome-actions{
    margin-top:20px;
    display:flex;
    flex-direction:column;
    gap:10px;
  }
  .reg-welcome-continue{
    appearance:none;
    border:0;
    border-radius:12px;
    background:linear-gradient(180deg, var(--rw-go) 0%, var(--rw-go-deep) 100%);
    color:#fff;
    font-family:inherit;
    font-size:1rem;
    font-weight:700;
    padding:13px 18px;
    cursor:pointer;
    box-shadow:0 10px 24px rgba(22,163,74,.28);
    transition:transform .15s ease, box-shadow .15s ease;
  }
  .reg-welcome-continue:hover{
    transform:translateY(-1px);
    box-shadow:0 14px 28px rgba(22,163,74,.34);
  }
  .reg-welcome-continue:focus-visible{
    outline:3px solid rgba(29,78,216,.35);
    outline-offset:2px;
  }
  @keyframes regWelcomeFade{
    to{opacity:1}
  }
  @keyframes regWelcomeRise{
    to{opacity:1; transform:translateY(0) scale(1)}
  }
  @media (prefers-reduced-motion: reduce){
    .reg-welcome-overlay,
    .reg-welcome-dialog{
      animation:none;
      opacity:1;
      transform:none;
    }
  }
</style>

<div
  class="reg-welcome-overlay"
  id="regWelcomeOverlay"
  role="dialog"
  aria-modal="true"
  aria-labelledby="regWelcomeBrand"
  aria-describedby="regWelcomeDesc"
>
  <div class="reg-welcome-dialog">
    <div class="reg-welcome-hero">
      <h2 class="reg-welcome-brand" id="regWelcomeBrand">Talsora</h2>
      <p class="reg-welcome-kicker"><?= htmlspecialchars($welcomeGreeting, ENT_QUOTES, 'UTF-8') ?>. Your personal account is ready.</p>
    </div>
    <div class="reg-welcome-body">
      <h3 class="reg-welcome-title">Your Friend Code</h3>
      <p class="reg-welcome-copy" id="regWelcomeDesc">
        Save this code. Friends use it to find and connect with you on Talsora.
      </p>
      <div class="reg-welcome-code-wrap">
        <span class="reg-welcome-code-label">Friend code</span>
        <div class="reg-welcome-code-row">
          <p class="reg-welcome-code" id="regWelcomeCode"><?= htmlspecialchars($welcomeCode, ENT_QUOTES, 'UTF-8') ?></p>
          <button type="button" class="reg-welcome-copy-btn" id="regWelcomeCopyBtn" aria-label="Copy friend code">Copy</button>
        </div>
        <p class="reg-welcome-hint">You can also find this later in your profile settings.</p>
      </div>
      <div class="reg-welcome-actions">
        <button type="button" class="reg-welcome-continue" id="regWelcomeContinueBtn"><?= htmlspecialchars($welcomeContinueLabel, ENT_QUOTES, 'UTF-8') ?></button>
      </div>
    </div>
  </div>
</div>

<script>
(function () {
  var overlay = document.getElementById('regWelcomeOverlay');
  if (!overlay) return;

  var codeEl = document.getElementById('regWelcomeCode');
  var copyBtn = document.getElementById('regWelcomeCopyBtn');
  var continueBtn = document.getElementById('regWelcomeContinueBtn');
  var username = <?= json_encode($welcomeUsername, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

  function closeWelcome() {
    overlay.setAttribute('hidden', 'hidden');
    document.documentElement.style.overflow = '';
    var userInput = document.getElementById('loginUsernameInput');
    if (userInput && username && !String(userInput.value || '').trim()) {
      userInput.value = username;
      try { userInput.focus(); } catch (e) {}
    }
  }

  function copyCode() {
    var text = codeEl ? String(codeEl.textContent || '').trim() : '';
    if (!text) return;
    var done = function () {
      if (!copyBtn) return;
      copyBtn.textContent = 'Copied';
      copyBtn.classList.add('is-copied');
      window.setTimeout(function () {
        copyBtn.textContent = 'Copy';
        copyBtn.classList.remove('is-copied');
      }, 1600);
    };
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(done).catch(function () {
        fallbackCopy(text, done);
      });
    } else {
      fallbackCopy(text, done);
    }
  }

  function fallbackCopy(text, done) {
    try {
      var ta = document.createElement('textarea');
      ta.value = text;
      ta.setAttribute('readonly', '');
      ta.style.position = 'fixed';
      ta.style.opacity = '0';
      document.body.appendChild(ta);
      ta.select();
      document.execCommand('copy');
      document.body.removeChild(ta);
      done();
    } catch (e) {}
  }

  document.documentElement.style.overflow = 'hidden';
  if (copyBtn) copyBtn.addEventListener('click', copyCode);
  if (continueBtn) continueBtn.addEventListener('click', closeWelcome);
  overlay.addEventListener('click', function (event) {
    if (event.target === overlay) closeWelcome();
  });
  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape' && !overlay.hasAttribute('hidden')) {
      closeWelcome();
    }
  });
})();
</script>
