<?php
declare(strict_types=1);

/**
 * Full-screen Talentra boot splash.
 * Expects:
 *   $talentraSplashNext (string) — URL to open after the wait
 * Optional:
 *   $talentraSplashMs (int) — delay in ms (default 2400)
 *   $talentraSplashMessage (string) — short status line under the brand
 */
$talentraSplashNext = trim((string)($talentraSplashNext ?? 'home.php?tab=for-you'));
if ($talentraSplashNext === '') {
    $talentraSplashNext = 'home.php?tab=for-you';
}
$talentraSplashMs = (int)($talentraSplashMs ?? 2400);
if ($talentraSplashMs < 800) {
    $talentraSplashMs = 800;
}
if ($talentraSplashMs > 8000) {
    $talentraSplashMs = 8000;
}
$talentraSplashMessage = trim((string)($talentraSplashMessage ?? 'Getting things ready...'));
$talentraSplashNextJson = json_encode(
    $talentraSplashNext,
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
);
if ($talentraSplashNextJson === false) {
    $talentraSplashNextJson = '"home.php?tab=for-you"';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
  <meta name="robots" content="noindex,nofollow">
  <title>Talentra</title>
  <style>
    :root{
      --te-ink:#f8fafc;
      --te-muted:rgba(226,232,240,.78);
      --te-accent:#7dd3fc;
    }
    *{box-sizing:border-box}
    html,body{
      margin:0;
      min-height:100%;
      height:100%;
      background:#070b14;
      color:var(--te-ink);
      font-family:system-ui,-apple-system,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;
      overflow:hidden;
    }
    .te-splash{
      position:fixed;
      inset:0;
      display:flex;
      align-items:center;
      justify-content:center;
      padding:24px 16px;
      background:
        radial-gradient(900px 520px at 18% 12%, rgba(29,78,216,.32), transparent 58%),
        radial-gradient(720px 480px at 88% 88%, rgba(14,165,233,.18), transparent 55%),
        radial-gradient(520px 360px at 50% 70%, rgba(22,163,74,.12), transparent 60%),
        #070b14;
    }
    .te-splash-inner{
      display:flex;
      flex-direction:column;
      align-items:center;
      gap:18px;
      text-align:center;
      transform:translateY(10px);
      opacity:0;
      animation:teRise .55s cubic-bezier(.2,.8,.2,1) forwards;
    }
    .te-mark{
      position:relative;
      width:78px;
      height:78px;
      border-radius:24px;
      display:grid;
      place-items:center;
      background:linear-gradient(145deg, #1d4ed8 0%, #0ea5e9 55%, #22c55e 130%);
      box-shadow:
        0 0 0 1px rgba(255,255,255,.18) inset,
        0 18px 48px rgba(14,165,233,.28);
      animation:tePulse 1.6s ease-in-out infinite;
    }
    .te-mark::before{
      content:"";
      position:absolute;
      inset:-10px;
      border-radius:30px;
      border:1px solid rgba(125,211,252,.35);
      opacity:.7;
      animation:teRing 1.6s ease-out infinite;
    }
    .te-mark-letter{
      font-family:Georgia,"Times New Roman",serif;
      font-size:42px;
      font-weight:700;
      line-height:1;
      letter-spacing:-.04em;
      color:#fff;
      text-shadow:0 8px 18px rgba(2,8,23,.35);
    }
    .te-brand{
      margin:0;
      font-family:Georgia,"Times New Roman",serif;
      font-size:clamp(2rem, 5vw, 2.55rem);
      font-weight:700;
      letter-spacing:-.03em;
      line-height:1;
      background:linear-gradient(90deg, #fff 10%, var(--te-accent) 55%, #86efac 100%);
      -webkit-background-clip:text;
      background-clip:text;
      color:transparent;
      animation:teShimmer 2.4s linear infinite;
      background-size:200% 100%;
    }
    .te-status{
      margin:0;
      font-size:14px;
      font-weight:600;
      color:var(--te-muted);
      letter-spacing:.01em;
    }
    .te-dots{
      display:inline-flex;
      gap:6px;
      align-items:center;
      min-height:10px;
    }
    .te-dots span{
      width:6px;
      height:6px;
      border-radius:50%;
      background:rgba(125,211,252,.85);
      animation:teDot 1.05s ease-in-out infinite;
    }
    .te-dots span:nth-child(2){animation-delay:.15s}
    .te-dots span:nth-child(3){animation-delay:.3s}
    @keyframes teRise{
      to{opacity:1; transform:translateY(0)}
    }
    @keyframes tePulse{
      0%,100%{transform:scale(1)}
      50%{transform:scale(1.045)}
    }
    @keyframes teRing{
      0%{transform:scale(.92); opacity:.65}
      100%{transform:scale(1.18); opacity:0}
    }
    @keyframes teShimmer{
      0%{background-position:0% 50%}
      100%{background-position:200% 50%}
    }
    @keyframes teDot{
      0%,100%{opacity:.35; transform:translateY(0)}
      50%{opacity:1; transform:translateY(-3px)}
    }
    @media (prefers-reduced-motion: reduce){
      .te-splash-inner,
      .te-mark,
      .te-mark::before,
      .te-brand,
      .te-dots span{
        animation:none !important;
      }
      .te-splash-inner{opacity:1; transform:none}
    }
  </style>
</head>
<body>
  <div class="te-splash" role="status" aria-live="polite" aria-busy="true">
    <div class="te-splash-inner">
      <div class="te-mark" aria-hidden="true"><span class="te-mark-letter">T</span></div>
      <h1 class="te-brand">Talentra</h1>
      <p class="te-status"><?= htmlspecialchars($talentraSplashMessage, ENT_QUOTES, 'UTF-8') ?></p>
      <div class="te-dots" aria-hidden="true"><span></span><span></span><span></span></div>
    </div>
  </div>
  <script>
  (function () {
    var next = <?= $talentraSplashNextJson ?>;
    var waitMs = <?= (int)$talentraSplashMs ?>;
    var reduced = false;
    try {
      reduced = !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
    } catch (e) {}
    if (reduced) waitMs = Math.min(waitMs, 600);
    window.setTimeout(function () {
      window.location.replace(next || 'home.php?tab=for-you');
    }, waitMs);
  })();
  </script>
</body>
</html>
