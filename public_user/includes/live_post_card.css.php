<?php
declare(strict_types=1);

/**
 * Live post card frame sizing — mirrors reel portrait breakpoints on mobile/tablet
 * and uses landscape 16:9 on desktop (>=1025px).
 */
function live_post_card_css(): string
{
    $lines = [];

    $lines[] = '.mf-card.mf-card-live,';
    $lines[] = '.post.is-live-post{';
    $lines[] = '  --live-ar-w:9;';
    $lines[] = '  --live-ar-h:16;';
    $lines[] = '  --live-side-gap:24px;';
    $lines[] = '  --live-top-gap:28px;';
    $lines[] = '  --live-bottom-gap:104px;';
    $lines[] = '  --live-max-height:calc(100dvh - var(--live-top-gap) - var(--live-bottom-gap));';
    $lines[] = '  --live-frame-height:min(820px, max(420px, var(--live-max-height)));';
    $lines[] = '  --live-frame-width:min(calc(var(--live-frame-height) * var(--live-ar-w) / var(--live-ar-h)), calc(100vw - var(--live-side-gap)));';
    $lines[] = '  width:min(100%, var(--live-frame-width));';
    $lines[] = '  max-width:min(100%, var(--live-frame-width));';
    $lines[] = '  margin-left:auto;';
    $lines[] = '  margin-right:auto;';
    $lines[] = '}';

    $lines[] = '.mf-card.mf-card-live .mf-media{';
    $lines[] = '  width:100%;';
    $lines[] = '  max-width:100%;';
    $lines[] = '  margin:0 auto;';
    $lines[] = '  padding:0;';
    $lines[] = '  background:transparent;';
    $lines[] = '}';

    $lines[] = '.public-live-frame-wrap{';
    $lines[] = '  padding:0 0 14px;';
    $lines[] = '}';

    $lines[] = '.mf-live-stage,';
    $lines[] = 'a.mf-live-stage,';
    $lines[] = '.public-live-frame,';
    $lines[] = 'a.public-live-frame{';
    $lines[] = '  position:relative;';
    $lines[] = '  display:block;';
    $lines[] = '  box-sizing:border-box;';
    $lines[] = '  width:100%;';
    $lines[] = '  max-width:100%;';
    $lines[] = '  margin:0 auto;';
    $lines[] = '  aspect-ratio:var(--live-ar-w) / var(--live-ar-h);';
    $lines[] = '  min-height:min(820px, max(420px, var(--live-max-height)));';
    $lines[] = '  max-height:min(calc(100dvh - 150px), 840px);';
    $lines[] = '  height:auto;';
    $lines[] = '  overflow:hidden;';
    $lines[] = '  border-radius:22px;';
    $lines[] = '  text-decoration:none;';
    $lines[] = '  color:inherit;';
    $lines[] = '}';

    $lines[] = '.post.is-live-post .public-live-frame-wrap{';
    $lines[] = '  width:100%;';
    $lines[] = '}';

    $lines[] = '@media (max-width:360px){';
    $lines[] = '  .mf-card.mf-card-live,';
    $lines[] = '  .post.is-live-post{';
    $lines[] = '    --live-side-gap:20px;';
    $lines[] = '    --live-top-gap:20px;';
    $lines[] = '    --live-bottom-gap:92px;';
    $lines[] = '    width:100%;';
    $lines[] = '    max-width:100%;';
    $lines[] = '  }';
    $lines[] = '  .mf-live-stage,';
    $lines[] = '  a.mf-live-stage,';
    $lines[] = '  .public-live-frame,';
    $lines[] = '  a.public-live-frame{';
    $lines[] = '    min-height:auto;';
    $lines[] = '    border-radius:0;';
    $lines[] = '  }';
    $lines[] = '}';

    $lines[] = '@media (min-width:361px) and (max-width:430px){';
    $lines[] = '  .mf-card.mf-card-live,';
    $lines[] = '  .post.is-live-post{';
    $lines[] = '    --live-side-gap:20px;';
    $lines[] = '    --live-top-gap:20px;';
    $lines[] = '    --live-bottom-gap:92px;';
    $lines[] = '    --live-frame-height:min(760px, max(500px, var(--live-max-height)));';
    $lines[] = '    width:100%;';
    $lines[] = '    max-width:100%;';
    $lines[] = '  }';
    $lines[] = '  .mf-live-stage,';
    $lines[] = '  a.mf-live-stage,';
    $lines[] = '  .public-live-frame,';
    $lines[] = '  a.public-live-frame{';
    $lines[] = '    min-height:min(760px, max(500px, var(--live-max-height)));';
    $lines[] = '    border-radius:18px;';
    $lines[] = '  }';
    $lines[] = '}';

    $lines[] = '@media (min-width:431px) and (max-width:575.98px){';
    $lines[] = '  .mf-card.mf-card-live,';
    $lines[] = '  .post.is-live-post{';
    $lines[] = '    --live-side-gap:22px;';
    $lines[] = '    --live-top-gap:20px;';
    $lines[] = '    --live-bottom-gap:96px;';
    $lines[] = '    --live-frame-height:min(780px, max(520px, var(--live-max-height)));';
    $lines[] = '    width:min(100%, var(--live-frame-width));';
    $lines[] = '    max-width:min(100%, var(--live-frame-width));';
    $lines[] = '  }';
    $lines[] = '  .mf-live-stage,';
    $lines[] = '  a.mf-live-stage,';
    $lines[] = '  .public-live-frame,';
    $lines[] = '  a.public-live-frame{';
    $lines[] = '    min-height:min(780px, max(520px, var(--live-max-height)));';
    $lines[] = '    border-radius:18px;';
    $lines[] = '  }';
    $lines[] = '}';

    $lines[] = '@media (min-width:576px) and (max-width:767.98px){';
    $lines[] = '  .mf-card.mf-card-live,';
    $lines[] = '  .post.is-live-post{';
    $lines[] = '    --live-side-gap:24px;';
    $lines[] = '    --live-top-gap:24px;';
    $lines[] = '    --live-bottom-gap:96px;';
    $lines[] = '    --live-frame-height:min(760px, max(500px, var(--live-max-height)));';
    $lines[] = '  }';
    $lines[] = '}';

    $lines[] = '@media (max-width:767.98px){';
    $lines[] = '  .public-live-frame-wrap{';
    $lines[] = '    padding:0 12px 14px;';
    $lines[] = '  }';
    $lines[] = '  .post.is-live-post,';
    $lines[] = '  .mf-card.mf-card-live{';
    $lines[] = '    margin-bottom:14px;';
    $lines[] = '  }';
    $lines[] = '  .public-live-frame,';
    $lines[] = '  a.public-live-frame{';
    $lines[] = '    width:100%;';
    $lines[] = '    margin:0 auto;';
    $lines[] = '  }';
    $lines[] = '}';

    $lines[] = '@media (min-width:768px) and (max-width:1024.98px){';
    $lines[] = '  .mf-card.mf-card-live,';
    $lines[] = '  .post.is-live-post{';
    $lines[] = '    --live-side-gap:28px;';
    $lines[] = '    --live-top-gap:24px;';
    $lines[] = '    --live-bottom-gap:104px;';
    $lines[] = '    --live-frame-height:min(820px, max(560px, var(--live-max-height)));';
    $lines[] = '    width:min(100%, calc((82vh - 8px) * 9 / 16));';
    $lines[] = '    min-width:320px;';
    $lines[] = '    max-width:540px;';
    $lines[] = '  }';
    $lines[] = '  .mf-live-stage,';
    $lines[] = '  a.mf-live-stage,';
    $lines[] = '  .public-live-frame,';
    $lines[] = '  a.public-live-frame{';
    $lines[] = '    width:100%;';
    $lines[] = '    margin:0 auto;';
    $lines[] = '    max-height:min(82vh, 840px);';
    $lines[] = '  }';
    $lines[] = '}';

    $lines[] = '@media (min-width:1025px){';
    $lines[] = '  .mf-card.mf-card-live,';
    $lines[] = '  .post.is-live-post{';
    $lines[] = '    --live-ar-w:16;';
    $lines[] = '    --live-ar-h:9;';
    $lines[] = '    width:100%;';
    $lines[] = '    max-width:100%;';
    $lines[] = '    margin-left:auto;';
    $lines[] = '    margin-right:auto;';
    $lines[] = '  }';
    $lines[] = '  .mf-card.mf-card-live .mf-media{';
    $lines[] = '    width:100%;';
    $lines[] = '    max-width:100%;';
    $lines[] = '  }';
    $lines[] = '  .public-live-frame-wrap{';
    $lines[] = '    padding:0;';
    $lines[] = '  }';
    $lines[] = '  .mf-live-stage,';
    $lines[] = '  a.mf-live-stage,';
    $lines[] = '  .public-live-frame,';
    $lines[] = '  a.public-live-frame{';
    $lines[] = '    width:100%;';
    $lines[] = '    margin:0 auto;';
    $lines[] = '    min-height:0;';
    $lines[] = '    max-height:min(520px, 56vh);';
    $lines[] = '    border-radius:22px;';
    $lines[] = '  }';
    $lines[] = '}';

    $lines[] = '@media (max-width:767.98px){';
    $lines[] = '  body.feed-insta-ui .feed-desktop-center{';
    $lines[] = '    width:100% !important;';
    $lines[] = '    max-width:100% !important;';
    $lines[] = '  }';
    $lines[] = '}';

    return implode("\n", $lines);
}
