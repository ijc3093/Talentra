<?php
declare(strict_types=1);

/**
 * Post sign-in / sign-up boot gate.
 * Distinctive Talentra splash, then soft sleep into home.
 */

$homeFallback = 'home.php?tab=for-you';

try {
    require_once __DIR__ . '/includes/session_user.php';
} catch (Throwable $e) {
    header('Location: index.php');
    exit;
}

if (empty($_SESSION['user_id']) || empty($_SESSION['user_login'])) {
    header('Location: index.php');
    exit;
}

$next = trim((string)(isset($_GET['next']) ? $_GET['next'] : $homeFallback));
if ($next === ''
    || preg_match('#^(https?:)?//#i', $next)
    || strpos($next, "\n") !== false
    || strpos($next, "\r") !== false
    || strpos($next, '..') !== false
    || !preg_match('#^[a-z0-9_\-./?&=%]+$#i', $next)
) {
    $next = $homeFallback;
}

$kind = strtolower(trim((string)(isset($_SESSION['user_account_kind']) ? $_SESSION['user_account_kind'] : 'personal')));
$firstName = trim((string)(isset($_SESSION['user_name']) ? $_SESSION['user_name'] : ''));
if ($firstName !== '') {
    $parts = preg_split('/\s+/', $firstName);
    $firstName = is_array($parts) && isset($parts[0]) ? (string)$parts[0] : $firstName;
}
if ($kind === 'publisher') {
    $message = 'Preparing your publisher stage';
    $tagline = 'Stories, brands, and audiences — in one place';
} elseif ($kind === 'commerce') {
    $message = 'Preparing your commerce stage';
    $tagline = 'Brand stores and sellers, ready to open';
} else {
    $message = $firstName !== '' ? ('Welcome, ' . $firstName) : 'Welcome in';
    $tagline = 'Your story is about to open';
}

$nextJson = json_encode($next, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
if ($nextJson === false) {
    $nextJson = '"' . $homeFallback . '"';
}
$messageHtml = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
$taglineHtml = htmlspecialchars($tagline, ENT_QUOTES, 'UTF-8');
$letters = preg_split('//u', 'Talentra', -1, PREG_SPLIT_NO_EMPTY);
if (!is_array($letters) || $letters === []) {
    $letters = str_split('Talentra');
}

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
  <meta name="robots" content="noindex,nofollow">
  <title>Talentra</title>
  <script>
  (function () {
    try {
      document.documentElement.style.background = '#05090f';
      var arrive = false;
      try { arrive = sessionStorage.getItem('msbEntryArrive') === '1'; } catch (e0) {}
      if (arrive) {
        try { sessionStorage.removeItem('msbEntryArrive'); } catch (e1) {}
        document.documentElement.classList.add('msb-entry-arrive');
      }
    } catch (e) {}
  })();
  </script>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@500;600;700&family=Manrope:wght@500;600;700&display=swap" rel="stylesheet">
  <style>
    :root{
      --ink:#05090f;
      --fog:rgba(232,240,248,.78);
      --gold:#e8c98a;
      --mist:#9fd6c8;
      --paper:#f4f1ea;
    }
    *{box-sizing:border-box}
    html,body{
      margin:0;min-height:100%;height:100%;
      background:var(--ink);color:var(--paper);
      font-family:"Manrope",system-ui,-apple-system,sans-serif;
      overflow:hidden;
    }
    .te-stage{
      position:fixed;inset:0;
      display:grid;place-items:center;
      isolation:isolate;
      background:
        radial-gradient(1200px 700px at 50% -10%, rgba(232,201,138,.16), transparent 55%),
        radial-gradient(900px 600px at 12% 88%, rgba(159,214,200,.14), transparent 50%),
        radial-gradient(700px 500px at 90% 70%, rgba(120,160,210,.12), transparent 48%),
        linear-gradient(165deg, #05090f 0%, #0b1622 48%, #08131c 100%);
      opacity:0;
    }
    .te-stage.is-live{opacity:1; transition:opacity .55s cubic-bezier(.33,.1,.2,1)}
    html.msb-entry-arrive .te-stage{opacity:0}
    html.msb-entry-arrive .te-stage.is-live{
      animation:teArriveOpen 1.05s cubic-bezier(.22,.61,.36,1) forwards;
    }
    .te-arrive-veil{
      position:absolute;inset:0;z-index:12;
      background:#05090f;
      opacity:0;pointer-events:none;
    }
    html.msb-entry-arrive .te-arrive-veil{opacity:1}
    html.msb-entry-arrive .te-stage.is-live .te-arrive-veil{
      animation:teArriveVeil 1.15s cubic-bezier(.33,.1,.2,1) forwards;
    }
    .te-aurora{
      position:absolute;inset:-20%;
      background:
        conic-gradient(from 180deg at 50% 50%,
          rgba(232,201,138,.0),
          rgba(232,201,138,.10),
          rgba(159,214,200,.0),
          rgba(159,214,200,.12),
          rgba(232,201,138,.0));
      filter:blur(40px);
      opacity:.55;
      animation:teAurora 10s linear infinite;
      pointer-events:none;
      will-change:transform;
    }
    .te-grain{
      position:absolute;inset:0;
      opacity:.16;
      pointer-events:none;
      background-image:url("data:image/svg+xml,%3Csvg viewBox='0 0 200 200' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.85' numOctaves='3' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)' opacity='0.55'/%3E%3C/svg%3E");
      mix-blend-mode:soft-light;
    }
    .te-motes{position:absolute;inset:0;overflow:hidden;pointer-events:none}
    .te-mote{
      position:absolute;width:3px;height:3px;border-radius:50%;
      background:rgba(244,241,234,.5);
      animation:teMote linear infinite;
      opacity:0;
      will-change:transform,opacity;
    }
    .te-core{
      position:relative;z-index:2;
      width:min(92vw,420px);
      text-align:center;
      padding:28px 18px 18px;
      opacity:0;
      transform:translateY(14px) scale(.985);
    }
    .te-stage.is-live .te-core{
      animation:teCoreIn 1s cubic-bezier(.16,1,.3,1) .12s forwards;
    }
    .te-orb{
      position:relative;
      width:118px;height:118px;
      margin:0 auto 28px;
    }
    .te-orb-ring{
      position:absolute;inset:0;
      border-radius:50%;
      border:1px solid rgba(232,201,138,.22);
    }
    .te-orb-ring.is-spin{
      border-color:transparent;
      background:
        conic-gradient(from 0deg, rgba(232,201,138,0), rgba(232,201,138,.95), rgba(159,214,200,.8), rgba(232,201,138,0));
      -webkit-mask:radial-gradient(farthest-side, transparent calc(100% - 2px), #000 calc(100% - 1px));
              mask:radial-gradient(farthest-side, transparent calc(100% - 2px), #000 calc(100% - 1px));
      animation:teSpin 3.2s cubic-bezier(.4,.1,.2,1) .2s forwards;
      will-change:transform;
    }
    .te-orb-glow{
      position:absolute;inset:18px;
      border-radius:50%;
      background:radial-gradient(circle at 35% 30%, rgba(255,255,255,.18), transparent 55%),
                 radial-gradient(circle at 50% 55%, rgba(159,214,200,.18), transparent 70%);
      animation:teGlow 2.8s ease-in-out infinite alternate;
    }
    .te-monogram{
      position:absolute;inset:0;
      display:grid;place-items:center;
    }
    .te-monogram-t{
      font-family:"Cormorant Garamond",Georgia,serif;
      font-size:58px;
      font-weight:700;
      line-height:1;
      letter-spacing:0;
      background:linear-gradient(180deg, #f8e7c2 0%, var(--gold) 42%, #b8924a 100%);
      -webkit-background-clip:text;
      background-clip:text;
      color:transparent;
      opacity:0;
      transform:scale(.86);
      animation:teMarkIn .9s cubic-bezier(.16,1,.3,1) .28s forwards;
      filter:drop-shadow(0 0 14px rgba(232,201,138,.28));
    }
    .te-word{
      margin:0;
      font-family:"Cormorant Garamond",Georgia,serif;
      font-size:clamp(2.6rem, 9vw, 3.55rem);
      font-weight:600;
      letter-spacing:.02em;
      line-height:1;
      display:flex;
      justify-content:center;
      gap:.015em;
    }
    .te-word span{
      display:inline-block;
      opacity:0;
      transform:translateY(18px);
      color:var(--paper);
      animation:teLetter .85s cubic-bezier(.16,1,.3,1) forwards;
      will-change:transform,opacity;
    }
    .te-rule{
      width:0;
      height:1px;
      margin:18px auto 0;
      background:linear-gradient(90deg, transparent, var(--gold), var(--mist), transparent);
      animation:teRule 1.2s cubic-bezier(.2,.8,.2,1) 1.15s forwards;
      box-shadow:0 0 18px rgba(232,201,138,.35);
    }
    .te-hello{
      margin:16px 0 0;
      font-size:.95rem;
      font-weight:600;
      letter-spacing:.04em;
      text-transform:uppercase;
      color:var(--gold);
      opacity:0;
      animation:teFade .85s ease 1.45s forwards;
    }
    .te-tag{
      margin:8px 0 0;
      font-size:1rem;
      font-weight:500;
      line-height:1.45;
      color:var(--fog);
      opacity:0;
      animation:teFade .85s ease 1.65s forwards;
    }
    .te-progress{
      width:min(220px,70%);
      height:2px;
      margin:28px auto 0;
      border-radius:999px;
      background:rgba(244,241,234,.12);
      overflow:hidden;
      opacity:0;
      animation:teFade .5s ease 1.85s forwards;
    }
    .te-progress > i{
      display:block;height:100%;width:0;
      border-radius:inherit;
      background:linear-gradient(90deg, var(--gold), var(--mist));
      animation:teFill 2.85s cubic-bezier(.25,.8,.25,1) 1.9s forwards;
    }
    .te-sleep-veil{
      position:absolute;inset:0;z-index:20;
      background:#05090f;
      opacity:0;pointer-events:none;
      will-change:opacity;
    }
    .te-stage.is-sleep .te-sleep-veil{
      animation:teSleepVeil 2.05s cubic-bezier(.42,0,.18,1) forwards;
    }
    .te-stage.is-sleep .te-core{
      animation:teSleepCore 1.7s cubic-bezier(.4,0,.2,1) forwards;
    }
    .te-stage.is-sleep .te-aurora,
    .te-stage.is-sleep .te-grain,
    .te-stage.is-sleep .te-motes{
      animation:teSleepFade 1.5s ease forwards;
    }
    @keyframes teArriveOpen{
      from{opacity:0}
      to{opacity:1}
    }
    @keyframes teArriveVeil{
      to{opacity:0}
    }
    @keyframes teCoreIn{
      to{opacity:1;transform:translateY(0) scale(1)}
    }
    @keyframes teAurora{
      to{transform:rotate(360deg)}
    }
    @keyframes teMote{
      0%{opacity:0;transform:translate3d(0,20px,0) scale(.6)}
      18%{opacity:.65}
      100%{opacity:0;transform:translate3d(0,-110vh,0) scale(1)}
    }
    @keyframes teSpin{
      from{transform:rotate(0deg);opacity:.35}
      to{transform:rotate(360deg);opacity:1}
    }
    @keyframes teMarkIn{
      to{opacity:1;transform:scale(1)}
    }
    @keyframes teGlow{
      from{opacity:.55;transform:scale(.96)}
      to{opacity:1;transform:scale(1.03)}
    }
    @keyframes teLetter{
      to{opacity:1;transform:translateY(0)}
    }
    @keyframes teRule{
      to{width:min(220px,58%)}
    }
    @keyframes teFade{
      to{opacity:1}
    }
    @keyframes teFill{
      to{width:100%}
    }
    @keyframes teSleepVeil{
      0%{opacity:0}
      40%{opacity:.55}
      100%{opacity:1}
    }
    @keyframes teSleepCore{
      to{opacity:0;transform:translateY(12px) scale(.96)}
    }
    @keyframes teSleepFade{
      to{opacity:0}
    }
    @media (prefers-reduced-motion:reduce){
      .te-aurora,.te-orb-ring.is-spin,.te-orb-glow,.te-monogram-t,
      .te-word span,.te-rule,.te-hello,.te-tag,.te-progress,.te-progress > i,.te-mote,
      .te-stage.is-sleep .te-sleep-veil,.te-stage.is-sleep .te-core,
      .te-stage.is-sleep .te-aurora,.te-stage.is-sleep .te-grain,.te-stage.is-sleep .te-motes,
      html.msb-entry-arrive .te-stage.is-live,
      html.msb-entry-arrive .te-stage.is-live .te-arrive-veil,
      .te-stage.is-live .te-core{
        animation:none !important;
        transition:none !important;
      }
      .te-stage,.te-stage.is-live{opacity:1}
      .te-core{opacity:1;transform:none}
      .te-monogram-t{opacity:1;transform:none}
      .te-word span,.te-hello,.te-tag,.te-progress{opacity:1;transform:none}
      .te-rule{width:min(220px,58%)}
      .te-progress > i{width:100%}
      .te-arrive-veil{opacity:0}
      .te-stage.is-sleep .te-sleep-veil{opacity:1}
      .te-stage.is-sleep .te-core{opacity:0}
    }
  </style>
</head>
<body>
  <div class="te-stage" id="teStage" role="status" aria-live="polite" aria-busy="true">
    <div class="te-aurora" aria-hidden="true"></div>
    <div class="te-grain" aria-hidden="true"></div>
    <div class="te-motes" id="teMotes" aria-hidden="true"></div>
    <div class="te-sleep-veil" id="teSleepVeil" aria-hidden="true"></div>
    <div class="te-arrive-veil" aria-hidden="true"></div>
    <div class="te-core">
      <div class="te-orb" aria-hidden="true">
        <div class="te-orb-ring"></div>
        <div class="te-orb-ring is-spin"></div>
        <div class="te-orb-glow"></div>
        <div class="te-monogram">
          <span class="te-monogram-t">t</span>
        </div>
      </div>
      <h1 class="te-word" aria-label="Talentra">
        <?php foreach ($letters as $i => $ch): ?>
          <span style="animation-delay:<?= number_format(0.72 + ($i * 0.08), 2, '.', '') ?>s"><?= htmlspecialchars($ch, ENT_QUOTES, 'UTF-8') ?></span>
        <?php endforeach; ?>
      </h1>
      <div class="te-rule" aria-hidden="true"></div>
      <p class="te-hello"><?= $messageHtml ?></p>
      <p class="te-tag"><?= $taglineHtml ?></p>
      <div class="te-progress" aria-hidden="true"><i></i></div>
    </div>
  </div>
  <script>
  (function () {
    var next = <?= $nextJson ?>;
    var homeFallback = <?= json_encode($homeFallback, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var stage = document.getElementById('teStage');
    var motes = document.getElementById('teMotes');
    var sleepVeil = document.getElementById('teSleepVeil');
    var progressFill = stage ? stage.querySelector('.te-progress > i') : null;
    var reduced = false;
    var finished = false;
    var fillDone = false;
    var navigating = false;
    /* Must match CSS: teFill delay 1.9s + duration 2.85s */
    var FILL_DELAY_MS = 1900;
    var FILL_DURATION_MS = 2850;
    var HOLD_AFTER_FILL_MS = 720;
    var SLEEP_FALLBACK_MS = 2200;
    var FAILSAFE_MS = 12000;
    var WAKE_KEY = 'msbEntryWake';

    try {
      reduced = !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
    } catch (e) {}

    function withWakeFlag(url) {
      var target = String(url || homeFallback || 'home.php?tab=for-you');
      try { sessionStorage.setItem(WAKE_KEY, '1'); } catch (eSet) {}
      if (/[?&]from_entry=/.test(target)) return target;
      return target.indexOf('?') >= 0 ? (target + '&from_entry=1') : (target + '?from_entry=1');
    }

    function navigateHome() {
      if (navigating) return;
      navigating = true;
      window.location.replace(withWakeFlag(next || homeFallback));
    }

    function goHome() {
      if (finished) return;
      finished = true;
      if (stage) stage.classList.add('is-sleep');
      if (reduced) {
        window.setTimeout(navigateHome, 200);
        return;
      }
      var slept = false;
      function afterSleep() {
        if (slept) return;
        slept = true;
        navigateHome();
      }
      if (sleepVeil) {
        sleepVeil.addEventListener('animationend', function (ev) {
          var name = (ev && ev.animationName) ? String(ev.animationName) : '';
          if (name === 'teSleepVeil' || name.indexOf('teSleepVeil') !== -1) afterSleep();
        });
      }
      window.setTimeout(afterSleep, SLEEP_FALLBACK_MS);
    }

    function afterFillComplete() {
      if (fillDone) return;
      fillDone = true;
      window.setTimeout(goHome, reduced ? 160 : HOLD_AFTER_FILL_MS);
    }

    function prefetchHome() {
      try {
        var dest = String(next || homeFallback || 'home.php?tab=for-you');
        var link = document.createElement('link');
        link.rel = 'prefetch';
        link.href = dest;
        document.head.appendChild(link);
      } catch (ePref) {}
    }

    function startLive() {
      if (!stage) return;
      stage.classList.add('is-live');
      if (motes && !reduced) {
        for (var i = 0; i < 12; i += 1) {
          var m = document.createElement('span');
          m.className = 'te-mote';
          m.style.left = (8 + Math.random() * 84) + '%';
          m.style.bottom = (-5 - Math.random() * 20) + '%';
          m.style.animationDuration = (5.2 + Math.random() * 3.8) + 's';
          m.style.animationDelay = (0.4 + Math.random() * 2.2) + 's';
          motes.appendChild(m);
        }
      }
      prefetchHome();
      if (reduced) {
        window.setTimeout(goHome, 900);
        return;
      }
      if (progressFill) {
        progressFill.addEventListener('animationend', function (ev) {
          var name = (ev && ev.animationName) ? String(ev.animationName) : '';
          if (name === 'teFill' || name.indexOf('teFill') !== -1) afterFillComplete();
        });
      }
      window.setTimeout(afterFillComplete, FILL_DELAY_MS + FILL_DURATION_MS + 100);
      window.setTimeout(goHome, FAILSAFE_MS);
    }

    // Wait two frames so CSS is applied before animations start (avoids glitchy first paint).
    window.requestAnimationFrame(function () {
      window.requestAnimationFrame(startLive);
    });
  })();
  </script>
</body>
</html>
<?php
exit;
