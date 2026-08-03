<?php
declare(strict_types=1);

/**
 * reel.php — Public Reels theater (Facebook Reels–style vertical snap scroll).
 * Opened from the video circle on public.php.
 */
require_once __DIR__ . '/includes/session_user.php';
require_once __DIR__ . '/controller.php';
require_once __DIR__ . '/includes/theme_prefs.php';
require_once __DIR__ . '/includes/post_card_actions_menu.php';
require_once __DIR__ . '/includes/post_action_thin_icons.php';
requireUserLogin();

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$controller = new Controller();
$dbh = $controller->pdo();
$meId = (int)($_SESSION['user_id'] ?? 0);
$iconHeart = post_action_thin_icon('heart');
$iconComment = post_action_thin_icon('comment');
$iconShare = post_action_thin_icon('share');
$iconBookmark = post_action_thin_icon('bookmark');
$iconFries = post_card_menu_fries_icon_html();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title>Reels</title>
  <?php theme_prefs_print_head_bootstrap($dbh, $meId); ?>
  <link href="./lib/font-awesome/css/font-awesome.css" rel="stylesheet">
  <link href="./lib/Ionicons/css/ionicons.css" rel="stylesheet">
  <?php post_card_actions_menu_render_css(); ?>
  <?php post_action_thin_icons_render_css(); ?>
  <style>
    :root{
      --reel-bg:var(--msb-palette-bg, #000);
      --reel-text:#fff;
      --reel-muted:rgba(255,255,255,.72);
      --reel-panel:rgba(255,255,255,.12);
      --reel-panel-hover:rgba(255,255,255,.18);
      --msb-love-color:#7c3aed;
      --post-media-radius:10px;
      --post-media-max:680px;
      --post-phone-max:430px;
      --post-portrait-max:520px;
      --post-square-max:620px;
      --post-landscape-max:760px;
      --feed-center-w:800px;
      --post-media-card-width:min(100%, var(--feed-center-w));
      --reel-media-max-h:min(78svh, 960px);
      --feedRailW:84px;
    }
    *{box-sizing:border-box;}
    html,body{
      margin:0;
      height:100%;
      background:var(--reel-bg);
      color:var(--reel-text);
      font-family:"Segoe UI", "Helvetica Neue", Helvetica, Arial, sans-serif;
      overflow:hidden;
    }
    a{color:inherit;text-decoration:none;}
    button{font:inherit;color:inherit;}

    .reel-app{
      position:fixed;
      left:var(--feedRailW, 84px);
      top:0;
      right:0;
      bottom:0;
      width:auto;
      height:auto;
      background:var(--reel-bg);
      overflow:hidden;
      z-index:1;
    }

    /* On-video chrome stays bright; side actions/jumps follow Appearance contrast below. */
    body.reel-page .reel-author-name,
    body.reel-page .reel-follow,
    body.reel-page .reel-music,
    body.reel-page .reel-music i,
    body.reel-page .reel-music-text,
    body.reel-page .reel-caption,
    body.reel-page .reel-caption a,
    body.reel-page .reel-caption .see-more,
    body.reel-page .reel-mute,
    body.reel-page .reel-mute i,
    html[data-msb-appearance] body.reel-page .reel-author-name,
    html[data-msb-appearance] body.reel-page .reel-follow,
    html[data-msb-appearance] body.reel-page .reel-music,
    html[data-msb-appearance] body.reel-page .reel-music i,
    html[data-msb-appearance] body.reel-page .reel-music-text,
    html[data-msb-appearance] body.reel-page .reel-caption,
    html[data-msb-appearance] body.reel-page .reel-caption a,
    html[data-msb-appearance] body.reel-page .reel-caption .see-more,
    html[data-msb-appearance] body.reel-page .reel-mute,
    html[data-msb-appearance] body.reel-page .reel-mute i{
      color:#fff !important;
      -webkit-text-fill-color:#fff !important;
      fill:#fff !important;
    }
    body.reel-page .reel-act.is-love .msb-pact-heart,
    html[data-msb-appearance] body.reel-page .reel-act.is-love .msb-pact-heart{
      color:var(--msb-love-color) !important;
      -webkit-text-fill-color:var(--msb-love-color) !important;
      fill:var(--msb-love-color) !important;
    }
    body.reel-page .reel-act.is-share .msb-pact-share,
    html[data-msb-appearance] body.reel-page .reel-act.is-share .msb-pact-share{
      color:#374151 !important;
      -webkit-text-fill-color:#374151 !important;
      fill:#374151 !important;
    }
    body.reel-page .reel-act.is-save .msb-pact-bookmark,
    html[data-msb-appearance] body.reel-page .reel-act.is-save .msb-pact-bookmark{
      color:#f59e0b !important;
      -webkit-text-fill-color:#f59e0b !important;
      fill:#f59e0b !important;
    }
    /* Viewport only — no overflow scroll (browser restore was landing on last reel). */
    .reel-scroller{
      position:absolute;
      inset:0;
      overflow:hidden;
      touch-action:pan-y;
    }
    .reel-track{
      position:relative;
      width:100%;
      will-change:transform;
      transform:translate3d(0,0,0);
    }
    .reel-track.is-animating{
      transition:transform .32s cubic-bezier(.22,.61,.36,1);
    }
    .reel-slide{
      position:relative;
      width:100%;
      display:flex;
      align-items:center;
      justify-content:center;
      padding:24px 88px 24px 40px;
      box-sizing:border-box;
      overflow:hidden;
    }
    .reel-card-row{
      display:flex;
      align-items:center;
      gap:16px;
      max-width:100%;
    }
    /* Do not expose the black reel shell, author/menu, or action rail before
       the browser has painted the first video frame. */
    .reel-slide:not(.reel-media-ready) .reel-card-row{
      visibility:hidden;
      opacity:0;
      pointer-events:none;
    }
    .reel-stage{
      position:relative;
      width:min(100%, var(--post-media-card-width, var(--post-media-max)));
      max-width:min(100%, var(--feed-center-w));
      height:auto;
      border-radius:var(--post-media-radius);
      overflow:hidden;
      background:#111;
      box-shadow:0 20px 60px rgba(0,0,0,.45);
      flex:0 0 auto;
    }
    .reel-video{
      display:block;
      width:100%;
      height:auto;
      max-height:var(--reel-media-max-h);
      object-fit:contain;
      object-position:center center;
      background:#000;
      border-radius:var(--post-media-radius);
    }
    .reel-stage.is-phone-shot{
      --device-ar-w:375;
      --device-ar-h:667;
    }

    .reel-mute{
      position:absolute;
      right:14px;
      bottom:14px;
      left:auto;
      top:auto;
      z-index:7;
      width:40px;
      height:40px;
      border:0;
      border-radius:50%;
      background:rgba(0,0,0,.55);
      color:#fff !important;
      display:flex;
      align-items:center;
      justify-content:center;
      cursor:pointer;
      box-shadow:0 1px 4px rgba(0,0,0,.45);
    }
    .reel-mute i{font-size:16px;color:#fff !important;}

    .reel-media-topbar{
      position:absolute;
      top:0;left:0;right:0;
      z-index:8;
      display:flex;
      justify-content:flex-end;
      padding:6px 6px 0 0;
      pointer-events:none;
    }
    .reel-media-topbar .post-card-menu-wrap{pointer-events:auto;margin:0;}
    .reel-media-topbar .post-card-menu-btn{
      color:#fff !important;
      --pcm-fries-filter:drop-shadow(0 1px 2px rgba(0,0,0,.7)) drop-shadow(0 0 1px rgba(0,0,0,.5));
    }

    /* Top: avatar + name + Follow + music */
    .reel-top{
      position:absolute;
      left:16px;
      right:56px;
      top:14px;
      z-index:5;
      pointer-events:none;
      display:flex;
      flex-direction:column;
      align-items:flex-start;
      gap:6px;
    }
    .reel-author{
      display:flex;
      align-items:center;
      gap:10px;
      min-width:0;
      max-width:100%;
      pointer-events:auto;
    }
    .reel-avatar{
      width:36px;
      height:36px;
      border-radius:50%;
      overflow:hidden;
      flex:0 0 36px;
      background:rgba(255,255,255,.2);
      border:1.5px solid rgba(255,255,255,.35);
    }
    .reel-avatar img{width:100%;height:100%;object-fit:cover;display:block;}
    .reel-author-name{
      font-size:15px;
      font-weight:800;
      color:#fff !important;
      -webkit-text-fill-color:#fff !important;
      text-shadow:0 1px 3px rgba(0,0,0,.75), 0 0 1px rgba(0,0,0,.5);
      max-width:200px;
      white-space:nowrap;
      overflow:hidden;
      text-overflow:ellipsis;
    }
    .reel-follow{
      border:0;
      background:transparent;
      color:#fff !important;
      -webkit-text-fill-color:#fff !important;
      font-size:15px;
      font-weight:800;
      cursor:pointer;
      text-shadow:0 1px 3px rgba(0,0,0,.75);
      padding:0 0 0 4px;
      pointer-events:auto;
    }
    .reel-follow.is-following{opacity:.7;cursor:default;}
    .reel-music{
      display:none;
      align-items:center;
      gap:6px;
      max-width:100%;
      color:#fff !important;
      -webkit-text-fill-color:#fff !important;
      font-size:13px;
      font-weight:700;
      line-height:1.3;
      text-shadow:0 1px 3px rgba(0,0,0,.75), 0 0 1px rgba(0,0,0,.5);
      pointer-events:none;
    }
    .reel-music.is-on{display:inline-flex;}
    .reel-music i{
      font-size:14px;
      opacity:.95;
      flex:0 0 auto;
      color:#fff !important;
    }
    .reel-music-text{
      min-width:0;
      white-space:nowrap;
      overflow:hidden;
      text-overflow:ellipsis;
      color:#fff !important;
    }

    .reel-bottom-fade{
      position:absolute;
      left:0;right:0;bottom:0;
      height:120px;
      z-index:4;
      pointer-events:none;
      background:linear-gradient(180deg, rgba(0,0,0,0) 0%, rgba(0,0,0,.45) 100%);
    }

    /* Bottom: description + See more */
    .reel-caption{
      position:absolute;
      left:16px;
      right:56px;
      bottom:22px;
      z-index:5;
      color:#fff !important;
      -webkit-text-fill-color:#fff !important;
      font-size:14px;
      line-height:1.4;
      text-shadow:0 1px 3px rgba(0,0,0,.75), 0 0 1px rgba(0,0,0,.5);
      pointer-events:auto;
      max-width:100%;
      word-break:break-word;
    }
    .reel-caption:empty{display:none;}
    .reel-caption .see-more{
      font-weight:800;
      opacity:1;
      cursor:pointer;
      white-space:nowrap;
      color:#fff !important;
      -webkit-text-fill-color:#fff !important;
      text-decoration:underline;
      text-underline-offset:2px;
      text-shadow:0 1px 3px rgba(0,0,0,.85), 0 0 2px rgba(0,0,0,.6);
    }
    .reel-progress{
      position:absolute;
      left:0;right:0;bottom:0;
      height:3px;
      background:rgba(255,255,255,.18);
      z-index:6;
      overflow:hidden;
    }
    .reel-progress > span{
      display:block;
      height:100%;
      width:0%;
      background:#fff;
    }

    .reel-right{
      z-index:15;
      display:flex;
      flex-direction:column;
      align-items:center;
      gap:18px;
      flex:0 0 auto;
      align-self:center;
      padding:0 4px;
    }
    .reel-act-wrap{
      display:flex;
      flex-direction:column;
      align-items:center;
      gap:6px;
    }
    .reel-act{
      background:none;
      border:none;
      padding:0;
      color:#fff;
      display:inline-flex;
      align-items:center;
      justify-content:center;
      cursor:pointer;
      text-shadow:0 1px 2px rgba(0,0,0,.55);
    }
    .reel-act:hover{opacity:.82;}
    .reel-act .msb-pact{
      width:26px;height:26px;min-width:26px;min-height:26px;flex:0 0 26px;
      color:#fff;
      filter:var(--msb-pact-contrast-filter, drop-shadow(0 0 1.35px rgba(255,255,255,.98)) drop-shadow(0 1px 2px rgba(0,0,0,.55)));
    }
    .reel-act .msb-reaction-glyph{
      width:26px;height:26px;min-width:26px;min-height:26px;flex:0 0 26px;
      display:inline-flex !important;
      align-items:center;justify-content:center;
      font-size:24px !important;
      line-height:1 !important;
      font-family:"Apple Color Emoji","Segoe UI Emoji","Noto Color Emoji","Segoe UI Symbol",sans-serif !important;
      background:transparent !important;
      -webkit-mask:none !important;
      mask:none !important;
      filter:var(--msb-pact-contrast-filter, drop-shadow(0 0 1.35px rgba(255,255,255,.98)) drop-shadow(0 1px 2px rgba(0,0,0,.55)));
    }
    .reel-act.is-love .msb-pact-heart{
      color:var(--msb-love-color) !important;
      filter:var(--msb-pact-contrast-filter, drop-shadow(0 0 1.35px rgba(255,255,255,.98)) drop-shadow(0 1px 2px rgba(0,0,0,.55))) !important;
    }
    .reel-act.is-share .msb-pact-share{
      color:#374151 !important;
      filter:var(--msb-pact-contrast-filter, drop-shadow(0 0 1.35px rgba(255,255,255,.98)) drop-shadow(0 1px 2px rgba(0,0,0,.55))) !important;
    }
    .reel-act.is-save .msb-pact-bookmark{
      color:#f59e0b !important;
      filter:var(--msb-pact-contrast-filter, drop-shadow(0 0 1.35px rgba(255,255,255,.98)) drop-shadow(0 1px 2px rgba(0,0,0,.55))) !important;
    }
    .reel-act-count{
      color:#fff;
      font-size:13px;
      font-weight:800;
      line-height:1;
      text-shadow:var(--msb-pact-contrast-text-shadow, 0 0 2px rgba(255,255,255,.95), 0 1px 2px rgba(0,0,0,.5));
      min-height:13px;
      text-align:center;
    }

    .reel-jump{
      position:fixed;
      right:24px;
      top:50%;
      transform:translateY(-50%);
      z-index:25;
      display:flex;
      flex-direction:column;
      gap:10px;
    }
    .reel-jump button{
      width:44px;
      height:44px;
      border:0;
      border-radius:50%;
      background:rgba(255,255,255,.22);
      color:#fff !important;
      display:flex;
      align-items:center;
      justify-content:center;
      cursor:pointer;
      box-shadow:0 1px 6px rgba(0,0,0,.4);
    }
    .reel-jump button:hover{background:rgba(255,255,255,.32);}
    .reel-jump i{font-size:22px;line-height:1;color:#fff !important;}

    .reel-loading{
      position:fixed;
      inset:0;
      display:flex;
      align-items:center;
      justify-content:center;
      background:var(--reel-bg);
      color:rgba(255,255,255,.7);
      z-index:50;
      font-weight:700;
    }
    .reel-loading[hidden]{display:none !important;}

    /* Comments / Read more door — same leftbar overlay as public.php */
    body.reel-page #ttLeftbarOverlays{
      left:0 !important;
      width:min(400px, 92vw) !important;
      z-index:1400 !important;
      color:var(--tt-text, #101828);
    }
    body.reel-page #ttLeftbarOverlays .tt-readmore-wrap,
    body.reel-page #ttLeftbarOverlays .tt-rm-body,
    body.reel-page #ttLeftbarOverlays .tt-rm-author,
    body.reel-page #ttLeftbarOverlays .tt-rm-sub,
    body.reel-page #ttLeftbarOverlays .tt-rm-title{
      color:var(--tt-text, #101828);
      text-align:left;
    }
    body.reel-page #ttLeftbarOverlays .tt-rm-body,
    body.reel-page #ttLeftbarOverlays .tt-rm-body .tt-richtext,
    body.reel-page #ttLeftbarOverlays .tt-rm-body .tt-rich-p{
      text-align:left;
      color:var(--tt-text, #101828);
    }
    body.reel-page #ttLeftbarOverlays .tt-rm-body .tt-rich-p{
      margin:0 0 14px;
      line-height:1.55;
    }
    body.reel-page #ttLeftbarOverlays .tt-rm-body .tt-rich-p:last-child{margin-bottom:0;}
    body.reel-page.public-leftbar-open{
      overflow:hidden;
    }
    body.reel-page.public-leftbar-open .reel-jump{
      pointer-events:none;
      opacity:.35;
    }

    @media (max-width:1024.98px){
      .reel-slide{padding:72px 64px 24px 12px;}
      .reel-card-row{gap:12px;}
    }
    @media (max-width:767.98px){
      :root{
        --reel-media-max-h:calc(100svh - 210px);
        --feedRailW:0px;
      }
      .reel-app{
        left:0;
        right:0;
        top:0;
        bottom:66px;
      }
      .reel-slide{padding:56px 58px 12px 8px;}
      .reel-card-row{width:100%;justify-content:center;gap:10px;}
      .reel-stage{max-width:100%;}
      .reel-stage.is-phone-shot{
        width:min(72vw, var(--post-phone-max)) !important;
        max-width:100%;
        max-height:min(78svh, 900px);
        aspect-ratio:var(--device-ar-w, 375) / var(--device-ar-h, 667);
        border-radius:28px;
        overflow:hidden;
      }
      .reel-stage.is-phone-shot .reel-video{
        width:100%;height:100%;max-height:none;object-fit:contain;border-radius:0;
      }
      .reel-jump{right:10px;}
    }
    @media (min-width:768px){
      .reel-stage.is-phone-shot{
        aspect-ratio:auto;
        border-radius:var(--post-media-radius);
        max-height:none;
      }
      .reel-stage.is-phone-shot .reel-video{
        width:100%;height:auto;max-height:var(--reel-media-max-h);
        object-fit:contain;border-radius:var(--post-media-radius);
      }
    }
  </style>
</head>
<body class="reel-page feed-insta-ui public-page">
<?php
  $GLOBALS['msb_skip_header_leftbar'] = true;
  $skipHeaderThemeBootstrap = true;
  include __DIR__ . '/includes/header.php';
?>
  <div class="reel-app" id="reelApp">
    <div class="reel-scroller" id="reelScroller" aria-label="Reels feed">
      <div class="reel-track" id="reelTrack"></div>
    </div>

    <div class="reel-jump" aria-label="Reel navigation">
      <button type="button" id="reelUp" aria-label="Previous reel"><i class="icon ion-chevron-up"></i></button>
      <button type="button" id="reelDown" aria-label="Next reel"><i class="icon ion-chevron-down"></i></button>
    </div>
  </div>

  <div class="reel-loading" id="reelLoading">Loading reels…</div>

  <?php include __DIR__ . '/includes/leftbar.php'; ?>

  <?php post_card_actions_menu_render_modals(); ?>
  <?php post_card_actions_menu_render_js([
    'delete_mode' => 'feed',
    'confirm_handler' => 'reel',
    'menu_surface' => 'public',
    'api_url' => 'feed_api.php',
    'always_portal' => true,
  ]); ?>
  <script id="reel-menu-outside-card-position">
  (function(){
    'use strict';

    function placeReelMenuOutside(){
      var menu = document.querySelector('body.reel-page > .pcm-menu-portal.open');
      if(!menu) return;
      var reel = document.querySelector('body.reel-page .reel-card.is-active .reel-stage, body.reel-page .reel-card-main .reel-stage');
      var button = document.querySelector('body.reel-page .post-card-menu-wrap.pcm-wrap-open .post-card-menu-btn');
      if(!reel || !button) return;

      var reelRect = reel.getBoundingClientRect();
      var buttonRect = button.getBoundingClientRect();
      var menuWidth = menu.offsetWidth || 220;
      var viewportWidth = Math.max(document.documentElement.clientWidth, window.innerWidth || 0);
      var gapAfterActions = 88;
      var left = reelRect.right + gapAfterActions;

      /* If the right side is genuinely too narrow, keep the menu outside on
         the left instead of covering the reel. */
      if(left + menuWidth > viewportWidth - 12){
        left = reelRect.left - menuWidth - 24;
      }
      left = Math.max(12, Math.min(left, viewportWidth - menuWidth - 12));

      menu.style.setProperty('position', 'fixed', 'important');
      menu.style.setProperty('left', Math.round(left) + 'px', 'important');
      menu.style.setProperty('right', 'auto', 'important');
      menu.style.setProperty('top', Math.max(12, Math.round(buttonRect.top)) + 'px', 'important');
      menu.style.setProperty('z-index', '100000', 'important');
    }

    document.addEventListener('click', function(){
      window.requestAnimationFrame(placeReelMenuOutside);
    }, true);
    window.addEventListener('resize', placeReelMenuOutside, {passive:true});
    window.addEventListener('scroll', placeReelMenuOutside, {passive:true});

    if(window.MutationObserver){
      new MutationObserver(function(records){
        var needsPlacement = records.some(function(record){
          return Array.prototype.some.call(record.addedNodes || [], function(node){
            return node && node.nodeType === 1 && node.classList && node.classList.contains('pcm-menu-portal');
          });
        });
        if(needsPlacement) window.requestAnimationFrame(placeReelMenuOutside);
      }).observe(document.body, {childList:true});
    }
  })();
  </script>
  <dialog class="reel-delete-dialog" id="reelDeleteDialog" aria-labelledby="reelDeleteDialogTitle">
    <button type="button" class="reel-delete-dialog-close" id="reelDeleteDialogClose" aria-label="Close">&times;</button>
    <div class="reel-delete-dialog-icon" aria-hidden="true"><i class="fa fa-trash"></i></div>
    <h2 id="reelDeleteDialogTitle">Delete this post?</h2>
    <p>This action cannot be undone. The post will be permanently removed.</p>
    <div class="reel-delete-dialog-actions">
      <button type="button" class="reel-delete-dialog-cancel" id="reelDeleteDialogCancel">Cancel</button>
      <button type="button" class="reel-delete-dialog-confirm" id="reelDeleteDialogConfirm">Delete</button>
    </div>
  </dialog>
  <style id="reel-delete-dialog-css">
    .reel-delete-dialog{width:min(430px,calc(100vw - 32px));max-width:430px;padding:30px;border:1px solid var(--msb-palette-border,rgba(148,163,184,.28));border-radius:22px;background:var(--msb-palette-surface,#fff);color:var(--msb-palette-text,#111827);box-shadow:0 28px 80px rgba(0,0,0,.38);text-align:center;box-sizing:border-box}
    .reel-delete-dialog::backdrop{background:rgba(15,23,42,.62);backdrop-filter:blur(5px);-webkit-backdrop-filter:blur(5px)}
    .reel-delete-dialog[open]{display:block}
    .reel-delete-dialog-close{position:absolute;top:12px;right:14px;width:34px;height:34px;padding:0;border:0;border-radius:50%;background:transparent;color:var(--msb-palette-muted,#64748b);font-size:27px;line-height:32px;cursor:pointer}
    .reel-delete-dialog-close:hover{background:var(--msb-palette-surface-2,rgba(148,163,184,.14));color:var(--msb-palette-text,#111827)}
    .reel-delete-dialog-icon{display:grid;place-items:center;width:58px;height:58px;margin:0 auto 16px;border-radius:50%;background:rgba(239,68,68,.12);color:#dc2626;font-size:23px}
    .reel-delete-dialog h2{margin:0 30px 9px;color:inherit;font-size:21px;font-weight:800;line-height:1.25}
    .reel-delete-dialog p{margin:0;color:var(--msb-palette-muted,#64748b);font-size:14px;line-height:1.55}
    .reel-delete-dialog-actions{display:flex;gap:10px;margin-top:24px}
    .reel-delete-dialog-actions button{flex:1 1 0;height:44px;border-radius:999px;font-size:14px;font-weight:800;cursor:pointer}
    .reel-delete-dialog-cancel{border:1px solid var(--msb-palette-border,rgba(148,163,184,.38));background:var(--msb-palette-surface-2,transparent);color:var(--msb-palette-text,#111827)}
    .reel-delete-dialog-confirm{border:1px solid #dc2626;background:#dc2626;color:#fff}
    @media(max-width:575.98px){.reel-delete-dialog{padding:28px 22px 22px}.reel-delete-dialog h2{font-size:19px}}
  </style>
  <script>
  (function(){
    var dialog = document.getElementById('reelDeleteDialog');
    var cancelBtn = document.getElementById('reelDeleteDialogCancel');
    var closeBtn = document.getElementById('reelDeleteDialogClose');
    var confirmBtn = document.getElementById('reelDeleteDialogConfirm');
    var pendingPostId = 0;
    var pendingDone = null;

    function closeDialog(){
      if(dialog && dialog.open) dialog.close();
      pendingPostId = 0;
      pendingDone = null;
    }
    window.MSBReelDeleteConfirm = {
      open:function(postId, done){
        pendingPostId = Number(postId || 0);
        pendingDone = typeof done === 'function' ? done : null;
        if(!dialog || !pendingPostId) return;
        if(typeof dialog.showModal === 'function'){
          if(!dialog.open) dialog.showModal();
        }else{
          dialog.setAttribute('open', '');
        }
      },
      close:closeDialog
    };
    if(cancelBtn) cancelBtn.addEventListener('click', closeDialog);
    if(closeBtn) closeBtn.addEventListener('click', closeDialog);
    if(dialog) dialog.addEventListener('click', function(e){
      if(e.target === dialog){
        var rect = dialog.getBoundingClientRect();
        if(e.clientX < rect.left || e.clientX > rect.right || e.clientY < rect.top || e.clientY > rect.bottom) closeDialog();
      }
    });
    if(confirmBtn) confirmBtn.addEventListener('click', function(){
      var postId = pendingPostId;
      var done = pendingDone;
      if(!postId || !window.MSBPostCardMenu || typeof window.MSBPostCardMenu.runDelete !== 'function') return;
      confirmBtn.disabled = true;
      window.MSBPostCardMenu.runDelete(postId, function(res){
        confirmBtn.disabled = false;
        closeDialog();
        if(typeof done === 'function') done(res);
        if(res && res.ok !== false) window.location.reload();
      });
    });
  })();
  </script>
  <style id="reel-public-card-structure">
    body.reel-page .reel-card-main{
      display:flex;
      flex-direction:column;
      align-items:stretch;
      width:min(100%, var(--post-media-card-width, var(--post-media-max)));
      max-width:min(100%, var(--feed-center-w));
      flex:0 0 auto;
      box-sizing:border-box;
    }
    body.reel-page .reel-card-main > .reel-stage{
      width:100%;
      max-width:100%;
    }
    body.reel-page .reel-outside-head{
      position:relative;
      display:flex;
      align-items:flex-start;
      width:100%;
      max-width:100%;
      min-height:48px;
      margin:0 auto;
      padding:1px 0 12px 20px;
      box-sizing:border-box;
      color:var(--msb-palette-text, #fff);
    }
    body.reel-page .reel-outside-head .reel-top{
      position:relative;
      inset:auto;
      z-index:5;
      flex:1 1 auto;
      min-width:0;
      pointer-events:auto;
      display:flex;
      flex-direction:column;
      align-items:flex-start;
      gap:4px;
      margin-left: -19px;
    }
    body.reel-page .reel-outside-head .reel-media-topbar{
      position:relative;
      inset:auto;
      z-index:8;
      flex:0 0 auto;
      display:flex;
      align-items:flex-start;
      justify-content:flex-end;
      padding:0;
      pointer-events:auto;
      background:none;
    }
    body.reel-page .reel-outside-head .reel-media-topbar .post-card-menu-wrap{
      position:relative;
      inset:auto;
      margin:0;
      transform:translateY(-10px);
    }
    body.reel-page .reel-outside-head .reel-author-name,
    body.reel-page .reel-outside-head .reel-follow,
    body.reel-page .reel-outside-head .reel-music,
    body.reel-page .reel-outside-head .reel-music i,
    body.reel-page .reel-outside-head .reel-music-text,
    body.reel-page .reel-outside-head .post-card-menu-btn,
    body.reel-page .reel-outside-head .pcm-fries-icon{
      color:var(--msb-palette-text, #fff) !important;
      -webkit-text-fill-color:var(--msb-palette-text, #fff) !important;
      text-shadow:none !important;
    }
    body.reel-page .reel-outside-head .reel-avatar{
      border-color:var(--msb-palette-border-strong, rgba(255,255,255,.35));
    }
    body.reel-page .reel-card-main > .reel-caption{
      position:relative;
      inset:auto;
      z-index:5;
      width:100%;
      max-width:100%;
      margin:0 auto 12px;
      color:var(--msb-palette-text, #fff) !important;
      -webkit-text-fill-color:var(--msb-palette-text, #fff) !important;
      text-shadow:none;
      box-sizing:border-box;
    }
    body.reel-page .reel-card-main > .reel-caption .see-more{
      color:var(--msb-palette-text, #fff) !important;
      -webkit-text-fill-color:var(--msb-palette-text, #fff) !important;
      text-shadow:none;
      font-weight:800;
    }
    @media (max-width:767.98px){
      body.reel-page .reel-card-main{
        width:min(100%, var(--post-media-card-width, 430px));
      }
    }
  </style>
  <script>
  window.API_URL = 'feed_api.php';
  (function(){
    var API = 'feed_api.php';
    var ME = <?= (int)$meId ?>;
    window.ME_ID = ME;
    var ICON = {
      heart: <?= json_encode($iconHeart, JSON_UNESCAPED_SLASHES) ?>,
      comment: <?= json_encode($iconComment, JSON_UNESCAPED_SLASHES) ?>,
      share: <?= json_encode($iconShare, JSON_UNESCAPED_SLASHES) ?>,
      bookmark: <?= json_encode($iconBookmark, JSON_UNESCAPED_SLASHES) ?>,
      fries: <?= json_encode($iconFries, JSON_UNESCAPED_SLASHES) ?>
    };

    var items = [];
    var index = 0;
    var globalMuted = true;
    var scroller = document.getElementById('reelScroller');
    var track = document.getElementById('reelTrack');
    var loadingEl = document.getElementById('reelLoading');
    var slideEls = [];
    var animating = false;
    var wheelLockUntil = 0;
    var touchStartY = 0;
    var touchDeltaY = 0;

    try{
      if('scrollRestoration' in history) history.scrollRestoration = 'manual';
    }catch(e){}
    try{ window.scrollTo(0, 0); }catch(e){}

    function esc(s){
      return String(s == null ? '' : s)
        .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
        .replace(/"/g,'&quot;');
    }
    function fmtCount(n){
      n = Number(n || 0);
      if(n >= 1000000) return (n / 1000000).toFixed(1).replace(/\.0$/, '') + 'M';
      if(n >= 1000) return (n / 1000).toFixed(1).replace(/\.0$/, '') + 'K';
      return String(n);
    }
    function isVideoItem(it){
      if(!it) return false;
      var path = String(it.preview_path || '').trim().replace(/^public_user\//, '');
      if(path === '') return false;
      var kind = String(it.preview_type || '').toLowerCase();
      if(kind === 'image' || kind === 'gif' || kind === 'file') return false;
      if(kind === 'video') return true;
      return /\.(mp4|webm|ogg|mov|m4v)(\?|$)/i.test(path);
    }
    function avatarUrl(it){
      var uid = Number((it && it.user_id) || 0);
      var name = String((it && (it.display_name || it.username)) || 'User');
      var params = ['s=96', 'name=' + encodeURIComponent(name)];
      if(uid > 0) params.push('u=' + encodeURIComponent(String(uid)));
      if(it && it.username) params.push('username=' + encodeURIComponent(String(it.username)));
      if(it && it.friend_code) params.push('friend_code=' + encodeURIComponent(String(it.friend_code)));
      return 'avatar.php?' + params.join('&');
    }
    function profileUrl(it){
      if(!it) return 'profile.php';
      if(it.friend_code) return 'profile.php?friend_code=' + encodeURIComponent(String(it.friend_code).toUpperCase());
      if(it.username) return 'profile.php?username=' + encodeURIComponent(String(it.username));
      if(Number(it.user_id || 0) > 0) return 'profile.php?id=' + encodeURIComponent(String(it.user_id));
      return 'profile.php';
    }
    function captionPlain(it){
      var t = String((it && (it.body || it.description || it.title)) || '').trim();
      return t.replace(/\[\[layout:[a-z0-9_]+\]\]/ig, '').replace(/\r\n?/g, '\n').trim();
    }
    function captionText(it){
      return captionPlain(it).replace(/\s+/g, ' ').trim();
    }
    function formatPostDate(it){
      var raw = String((it && (it.updated_at || it.created_at || it.created_at_label || it.time_ago)) || '').trim();
      if(!raw) return '';
      if(/^[A-Za-z]{3}\s+\d{1,2}$/.test(raw)) return raw;
      var d = new Date(raw.replace(' ', 'T'));
      if(isNaN(d.getTime())){
        var m = raw.match(/^(\d{4})-(\d{2})-(\d{2})/);
        if(!m) return raw;
        d = new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3]));
      }
      if(isNaN(d.getTime())) return raw;
      var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
      return months[d.getMonth()] + ' ' + d.getDate();
    }
    function truncateCaption(text, max){
      text = String(text || '').replace(/\s+/g, ' ').trim();
      max = max || 110;
      if(text.length <= max) return { short: text, more: false, full: text };
      var cut = text.slice(0, max).replace(/\s+\S*$/, '');
      if(!cut) cut = text.slice(0, max);
      return { short: cut, more: true, full: text };
    }
    function musicLine(it){
      var title = String((it && it.music_title) || '').trim();
      var artist = String((it && it.music_artist) || '').trim();
      var name = String((it && (it.display_name || it.username)) || '').trim();
      if(title && artist) return title + ' · ' + artist;
      if(title) return title;
      if(artist) return artist;
      if(name) return name + ' · Original audio';
      return '';
    }
    function parseDeviceAspect(style){
      style = String(style || '');
      var mw = style.match(/--device-ar-w:\s*(\d+)/);
      var mh = style.match(/--device-ar-h:\s*(\d+)/);
      if(!mw || !mh) return null;
      var w = Number(mw[1] || 0);
      var h = Number(mh[1] || 0);
      if(!w || !h) return null;
      return { w: w, h: h };
    }
    function reelFeedWidth(){
      var isMobile = window.matchMedia('(max-width: 767.98px)').matches;
      var isNarrow = window.matchMedia('(max-width: 1024.98px)').matches;
      var vw = Math.max(window.innerWidth || 0, 320);
      var left = isMobile ? 8 : (isNarrow ? 56 : 24);
      var right = isMobile ? 58 : 88;
      var actions = 72;
      var available = vw - left - right - actions;
      var maxCardWidth = isMobile ? 430 : 800;
      return Math.max(280, Math.min(maxCardWidth, available));
    }
    function applyPublicVideoCardWidth(stageEl, aspectW, aspectH, phoneShot){
      if(!stageEl) return;
      aspectW = Number(aspectW || 0);
      aspectH = Number(aspectH || 0);
      if(!aspectW || !aspectH) return;
      var isMobile = window.matchMedia('(max-width: 767.98px)').matches;
      var viewportH = Math.max(window.innerHeight || 0, 320);
      var maxVideoH = isMobile
        ? Math.max(viewportH - 210, 300)
        : Math.min(Math.round(viewportH * 0.78), 960);
      var aspect = aspectW / aspectH;
      var availableWidth = Math.max(280, reelFeedWidth());
      var desiredWidth = Math.round(aspect * maxVideoH);
      var maxByShape = aspect < 0.8 ? 520 : (aspect > 1.15 ? 800 : 620);
      if(phoneShot && isMobile) maxByShape = 430;
      var safeWidth = Math.max(280, Math.min(desiredWidth, availableWidth, maxByShape));
      if(aspect >= 0.8 && aspect <= 1.15) safeWidth = Math.min(availableWidth, Math.max(safeWidth, 420));
      if(aspect > 1.15) safeWidth = Math.min(availableWidth, Math.max(safeWidth, 560));
      stageEl.style.setProperty('--post-media-card-width', String(safeWidth) + 'px');
      stageEl.style.width = String(safeWidth) + 'px';
      stageEl.style.maxWidth = '100%';
      var cardMain = stageEl.closest('.reel-card-main');
      if(cardMain){
        cardMain.style.setProperty('--post-media-card-width', String(safeWidth) + 'px');
        cardMain.style.width = String(safeWidth) + 'px';
        cardMain.style.maxWidth = '100%';
      }
      if(phoneShot){
        stageEl.classList.add('is-phone-shot');
        stageEl.style.setProperty('--device-ar-w', String(Math.round(aspectW)));
        stageEl.style.setProperty('--device-ar-h', String(Math.round(aspectH)));
      } else {
        stageEl.classList.remove('is-phone-shot');
      }
    }
    function syncSlideSize(slide, it){
      if(!slide || !it) return;
      var stageEl = slide.querySelector('.reel-stage');
      var video = slide.querySelector('video');
      if(!stageEl) return;
      var phoneShot = Number(it.phone_shot || 0) === 1;
      var vw = Number((video && video.videoWidth) || 0);
      var vh = Number((video && video.videoHeight) || 0);
      if(vw > 0 && vh > 0){
        applyPublicVideoCardWidth(stageEl, vw, vh, phoneShot);
        return;
      }
      var dims = parseDeviceAspect(it.device_style || '');
      if(dims){
        applyPublicVideoCardWidth(stageEl, dims.w, dims.h, phoneShot);
        return;
      }
      applyPublicVideoCardWidth(stageEl, phoneShot ? 390 : 9, phoneShot ? 844 : 16, phoneShot);
    }

    function syncMuteBtn(slide, muted){
      var muteBtn = slide && slide.querySelector('.reel-mute');
      if(!muteBtn) return;
      var icon = muteBtn.querySelector('i');
      if(icon) icon.className = 'fa ' + (muted ? 'fa-volume-off' : 'fa-volume-up');
      muteBtn.setAttribute('aria-label', muted ? 'Unmute' : 'Mute');
    }

    function syncSlideMenu(slide, it){
      var menuWrap = slide.querySelector('.post-card-menu-wrap');
      var menu = menuWrap && menuWrap.querySelector('.post-card-menu');
      var stageEl = slide.querySelector('.reel-stage');
      if(!menuWrap || !menu || !stageEl || !it) return;
      var pid = Number(it.id || 0);
      var peer = Number(it.user_id || 0);
      var isOwner = peer > 0 && peer === ME;
      var isPublisher = Number(it.is_publisher || 0) === 1 || String(it.account_kind || '') === 'publisher';
      var href = profileUrl(it);
      menuWrap.setAttribute('data-post-id', String(pid));
      menuWrap.setAttribute('data-peer-id', String(peer));
      menuWrap.setAttribute('data-is-owner', isOwner ? '1' : '0');
      if(it.friend_code) menuWrap.setAttribute('data-peer-code', String(it.friend_code));
      else menuWrap.removeAttribute('data-peer-code');
      stageEl.setAttribute('data-post-id', String(pid));
      stageEl.setAttribute('data-peer-id', String(peer));
      stageEl.setAttribute('data-post-owner', isOwner ? '1' : '0');
      stageEl.setAttribute('data-account-kind', String(it.account_kind || (isPublisher ? 'publisher' : 'personal')));
      stageEl.setAttribute('data-is-publisher', isPublisher ? '1' : '0');
      stageEl.setAttribute('data-is-following', Number(it.is_following || 0) === 1 ? '1' : '0');
      stageEl.setAttribute('data-friend-status', String(it.friend_status || (isOwner ? 'self' : 'none')));
      stageEl.setAttribute('data-profile-url', href);
      stageEl.setAttribute('data-my-saved', Number(it.my_saved || it.is_saved || 0) === 1 ? '1' : '0');
      stageEl.setAttribute('data-is-archived', Number(it.is_archived || 0) === 1 ? '1' : '0');
      var html = '';
      if(window.MSBPostCardMenu && typeof window.MSBPostCardMenu.buildItems === 'function'){
        html = window.MSBPostCardMenu.buildItems(it, isOwner, pid) || '';
      }
      menu.innerHTML = html;
      menuWrap.style.display = html ? '' : 'none';
      if(window.MSBPostCardMenu && typeof window.MSBPostCardMenu.syncOnMediaContrast === 'function'){
        window.MSBPostCardMenu.syncOnMediaContrast(stageEl);
      }
    }

    function syncSlideActions(slide, it){
      if(!slide || !it) return;
      var my = String(it.my_reaction || '');
      var loveBtn = slide.querySelector('[data-act="love"]');
      if(loveBtn){
        if(window.MSBReactions && typeof window.MSBReactions.applyReactionButton === 'function'){
          window.MSBReactions.applyReactionButton(loveBtn, my, 'love');
        } else {
          var loveOn = my === 'love';
          loveBtn.classList.toggle('is-love', loveOn);
          var loveIcon = loveBtn.querySelector('.msb-pact-heart');
          if(loveIcon) loveIcon.classList.toggle('is-active', loveOn);
        }
      }
      var loveCount = slide.querySelector('[data-count="love"]');
      if(loveCount) loveCount.textContent = fmtCount(
        it.reaction_count != null
          ? it.reaction_count
          : (Number(it.love_count || 0) + Number(it.like_count || 0))
      );
      var cmtCount = slide.querySelector('[data-count="comment"]');
      if(cmtCount) cmtCount.textContent = fmtCount(it.comment_count || 0);
      var shareBtn = slide.querySelector('[data-act="share"]');
      if(shareBtn) shareBtn.classList.toggle('is-share', Number(it.my_shared || 0) === 1);
      var shareCount = slide.querySelector('[data-count="share"]');
      if(shareCount) shareCount.textContent = fmtCount(it.share_count || 0);
      var saveOn = Number(it.my_saved || 0) === 1;
      var saveBtn = slide.querySelector('[data-act="save"]');
      if(saveBtn){
        saveBtn.classList.toggle('is-save', saveOn);
        var saveIcon = saveBtn.querySelector('.msb-pact-bookmark');
        if(saveIcon) saveIcon.classList.toggle('is-active', saveOn);
      }
      var saveCount = slide.querySelector('[data-count="save"]');
      if(saveCount) saveCount.textContent = fmtCount(it.save_count || 0);

      var followBtn = slide.querySelector('.reel-follow');
      if(followBtn){
        var isOwner = Number(it.user_id || 0) === ME;
        var following = Number(it.is_following || 0) === 1;
        if(isOwner){
          followBtn.hidden = true;
        } else {
          followBtn.hidden = false;
          followBtn.textContent = following ? 'Following' : 'Follow';
          followBtn.classList.toggle('is-following', following);
          followBtn.disabled = following;
        }
      }
    }

    function applyReelReaction(slide, it, next){
      if(!slide || !it) return;
      var pid = Number(it.id || 0);
      if(!pid) return;
      next = String(next || 'none');
      var prevLove = Number(it.love_count || 0);
      var prevReaction = String(it.my_reaction || '');
      if(next === 'none' && !prevReaction) return;
      if(next !== 'none' && prevReaction === next) return;
      it.my_reaction = next === 'none' ? '' : next;
      it.love_count = Math.max(0, prevLove
        + (prevReaction === 'love' && next !== 'love' ? -1 : 0)
        + (prevReaction !== 'love' && next === 'love' ? 1 : 0));
      slide.setAttribute('data-engage-at', String(Date.now()));
      slide.setAttribute('data-my-reaction', it.my_reaction);
      syncSlideActions(slide, it);
      if(window.MSBPostEngagement){
        window.MSBPostEngagement.publish(pid, {
          love_count: it.love_count,
          my_reaction: it.my_reaction
        }, { source: 'reel' });
      }
      var fd = new FormData();
      fd.append('ajax', 'react');
      fd.append('post_id', String(pid));
      fd.append('reaction', next);
      fetch(API, { method:'POST', body: fd, credentials:'same-origin' })
        .then(function(r){ return r.json(); })
        .then(function(res){
          if(!res || !res.ok){
            it.love_count = prevLove;
            it.my_reaction = prevReaction;
            slide.setAttribute('data-engage-at', String(Date.now()));
            slide.setAttribute('data-my-reaction', prevReaction);
            syncSlideActions(slide, it);
            if(window.MSBPostEngagement){
              window.MSBPostEngagement.publish(pid, { love_count: prevLove, my_reaction: prevReaction }, { source: 'reel' });
            }
            return;
          }
          var counts = res.counts || {};
          it.love_count = Number(counts.love_count != null ? counts.love_count : it.love_count || 0);
          it.like_count = Number(counts.like_count != null ? counts.like_count : it.like_count || 0);
          it.reaction_count = Number(counts.reaction_count != null
            ? counts.reaction_count
            : (Number(it.love_count || 0) + Number(it.like_count || 0)));
          it.my_reaction = counts.my_reaction != null ? String(counts.my_reaction || '') : (next === 'none' ? '' : next);
          slide.setAttribute('data-engage-at', String(Date.now()));
          slide.setAttribute('data-my-reaction', it.my_reaction);
          syncSlideActions(slide, it);
          if(window.MSBPostEngagement){
            window.MSBPostEngagement.publish(pid, {
              love_count: it.love_count,
              my_reaction: it.my_reaction
            }, { source: 'reel' });
          }
        }).catch(function(){
          it.love_count = prevLove;
          it.my_reaction = prevReaction;
          slide.setAttribute('data-engage-at', String(Date.now()));
          slide.setAttribute('data-my-reaction', prevReaction);
          syncSlideActions(slide, it);
        });
    }

    function buildSlide(it, i){
      var src = String(it.preview_path || '').trim().replace(/^public_user\//, '');
      var href = profileUrl(it);
      var name = String(it.display_name || it.username || 'User');
      var fullCap = captionPlain(it);
      var cap = truncateCaption(captionText(it), 110);
      var nameInitial = String(name || 'P').replace(/[^A-Za-z0-9]/g, '').slice(0, 2).toUpperCase() || 'P';
      var postTitle = String(it.title || '').trim();
      if(postTitle.toLowerCase() === 'post') postTitle = '';
      var postDate = formatPostDate(it);
      var capHtml = '';
      if(cap.more){
        capHtml = esc(cap.short) + '… <a class="see-more js-open-readmore" href="#post-'+esc(String(it.id || 0))+'"'+
          ' data-post-id="'+esc(String(it.id || 0))+'"'+
          ' data-title="'+esc(postTitle)+'"'+
          ' data-author="'+esc(name)+'"'+
          ' data-date="'+esc(postDate)+'"'+
          ' data-avatar="'+esc(nameInitial)+'"'+
          ' data-avatar-url="'+esc(avatarUrl(it))+'"'+
          ' data-body="'+esc(fullCap)+'"'+
          '>See more</a>';
      } else if(cap.short){
        capHtml = esc(cap.short);
      }
      var music = musicLine(it);
      var slide = document.createElement('section');
      slide.className = 'reel-slide';
      slide.setAttribute('data-index', String(i));
      slide.setAttribute('data-post-id', String(it.id || 0));
      slide.innerHTML =
        '<div class="reel-card-row">'+
          '<div class="reel-card-main">'+
            '<div class="reel-outside-head">'+
              '<div class="reel-top">'+
                '<div class="reel-author">'+
                  '<a class="reel-avatar" href="'+esc(href)+'" aria-label="Open profile"><img src="'+esc(avatarUrl(it))+'" alt=""></a>'+
                  '<a class="reel-author-name" href="'+esc(href)+'">'+esc(name)+'</a>'+
                  '<button type="button" class="reel-follow" hidden>Follow</button>'+
                '</div>'+
                '<div class="reel-music'+(music ? ' is-on' : '')+'"><i class="fa fa-music" aria-hidden="true"></i><span class="reel-music-text">'+esc(music)+'</span></div>'+
              '</div>'+
              '<div class="standard-media-topbar reel-media-topbar">'+
              '<div class="post-card-menu-wrap mf-menu-wrap standard-media-topbar-menu" data-post-id="0" data-peer-id="0" data-is-owner="0" data-menu-surface="public">'+
                '<button type="button" class="post-card-menu-btn mf-menu-btn pcm-on-dark-media" aria-label="Post menu" title="Menu" aria-haspopup="true" aria-expanded="false">'+ICON.fries+'</button>'+
                '<div class="post-card-menu mf-menu" role="menu"></div>'+
              '</div>'+
              '</div>'+
            '</div>'+
            '<div class="reel-caption">'+capHtml+'</div>'+
            '<div class="reel-stage" data-post-id="'+esc(String(it.id || 0))+'">'+
              '<video class="reel-video" playsinline loop muted preload="none" src="'+esc(src)+'"></video>'+
              '<button type="button" class="reel-mute" aria-label="Unmute"><i class="fa fa-volume-off" aria-hidden="true"></i></button>'+
              '<div class="reel-bottom-fade" aria-hidden="true"></div>'+
              '<div class="reel-progress" aria-hidden="true"><span></span></div>'+
            '</div>'+
          '</div>'+
          '<div class="reel-right" aria-label="Reel actions">'+
            '<div class="reel-act-wrap"><button type="button" class="reel-act" data-act="love" aria-label="Love">'+ICON.heart+'</button><span class="reel-act-count" data-count="love">0</span></div>'+
            '<div class="reel-act-wrap"><button type="button" class="reel-act js-open-comments" data-act="comment" data-post-id="'+esc(String(it.id || 0))+'" aria-label="Comment">'+ICON.comment+'</button><span class="reel-act-count" data-count="comment">0</span></div>'+
            '<div class="reel-act-wrap"><button type="button" class="reel-act" data-act="share" aria-label="Share">'+ICON.share+'</button><span class="reel-act-count" data-count="share">0</span></div>'+
            '<div class="reel-act-wrap"><button type="button" class="reel-act" data-act="save" aria-label="Save">'+ICON.bookmark+'</button><span class="reel-act-count" data-count="save">0</span></div>'+
          '</div>'+
        '</div>';

      var video = slide.querySelector('video');
      video.muted = globalMuted;
      // Only the active reel should load — bulk preload was erroring later slides
      // and removeBrokenSlide(i) jumped the viewer to those indices after ~1s.
      video.preload = 'none';
      syncMuteBtn(slide, globalMuted);
      syncSlideActions(slide, it);
      syncSlideMenu(slide, it);
      syncSlideSize(slide, it);

      function revealPaintedReel(){
        if(slide.classList.contains('reel-media-ready')) return;
        var reveal = function(){
          requestAnimationFrame(function(){ slide.classList.add('reel-media-ready'); });
        };
        if(typeof video.requestVideoFrameCallback === 'function'){
          try{
            video.requestVideoFrameCallback(reveal);
            return;
          }catch(e){}
        }
        if(video.readyState >= 2) reveal();
      }
      video.addEventListener('loadedmetadata', function(){ syncSlideSize(slide, it); });
      video.addEventListener('loadeddata', function(){
        syncSlideSize(slide, it);
        revealPaintedReel();
      });
      video.addEventListener('playing', revealPaintedReel);
      if(video.readyState >= 2) revealPaintedReel();
      video.addEventListener('error', function(){
        var at = slideEls.indexOf(slide);
        if(at < 0) at = Number(slide.getAttribute('data-index') || -1);
        removeBrokenSlide(at);
      });
      video.addEventListener('timeupdate', function(){
        if(!video.duration) return;
        var bar = slide.querySelector('.reel-progress > span');
        if(bar) bar.style.width = Math.max(0, Math.min(100, (video.currentTime / video.duration) * 100)) + '%';
      });
      return slide;
    }

    function removeBrokenSlide(at){
      at = Number(at);
      if(!isFinite(at) || at < 0 || at >= items.length) return;
      // Keep the reel the user is watching. Never jump to the broken index
      // (that is what sent people to the bottom ~1s after open/refresh).
      var stayAt = index;
      items.splice(at, 1);
      if(at < stayAt) stayAt -= 1;
      stayAt = Math.max(0, Math.min(stayAt, Math.max(items.length - 1, 0)));

      var dead = slideEls[at];
      if(dead && dead.parentNode){
        try{ dead.parentNode.removeChild(dead); }catch(e){}
      }
      slideEls.splice(at, 1);
      slideEls.forEach(function(slide, i){
        if(slide) slide.setAttribute('data-index', String(i));
      });

      if(!items.length){
        loadingEl.hidden = false;
        loadingEl.textContent = 'No videos yet';
        applyTrackTransform(0, false);
        return;
      }
      sizeSlides();
      goTo(stayAt, false);
    }

    function pauseAllExcept(activeVideo){
      slideEls.forEach(function(slide){
        var v = slide.querySelector('video');
        if(!v) return;
        if(v === activeVideo) return;
        try{ v.pause(); }catch(e){}
      });
    }

    function viewportHeight(){
      return Math.max((scroller && scroller.clientHeight) || window.innerHeight || 0, 320);
    }

    function applyTrackTransform(at, animate){
      if(!track) return;
      at = Math.max(0, Math.min(Math.max(items.length - 1, 0), Number(at || 0)));
      var y = -(at * viewportHeight());
      if(animate){
        track.classList.add('is-animating');
      } else {
        track.classList.remove('is-animating');
      }
      track.style.transform = 'translate3d(0,' + y + 'px,0)';
    }

    function sizeSlides(){
      var h = viewportHeight();
      slideEls.forEach(function(slide){
        if(!slide) return;
        slide.style.height = h + 'px';
        slide.style.minHeight = h + 'px';
      });
      if(track){
        track.style.height = (h * Math.max(items.length, 1)) + 'px';
      }
    }

    function warmVideo(slide){
      if(!slide) return null;
      var video = slide.querySelector('video');
      if(!video) return null;
      try{
        if(video.preload !== 'metadata' && video.preload !== 'auto'){
          video.preload = 'metadata';
        }
        if(typeof video.load === 'function' && video.readyState < 1){
          video.load();
        }
      }catch(e){}
      return video;
    }

    function activateIndex(nextIndex, play){
      if(!items.length) return;
      nextIndex = Math.max(0, Math.min(items.length - 1, nextIndex));
      index = nextIndex;
      var slide = slideEls[index];
      if(!slide) return;
      // Warm current ±1 only — avoids mass video errors on distant slides.
      warmVideo(slideEls[index - 1]);
      var video = warmVideo(slide);
      warmVideo(slideEls[index + 1]);
      if(!video) return;
      pauseAllExcept(video);
      video.muted = globalMuted;
      syncMuteBtn(slide, globalMuted);
      if(play !== false){
        video.play().catch(function(){});
      }
    }

    function goTo(at, animate){
      if(!items.length) return;
      at = Math.max(0, Math.min(items.length - 1, Number(at || 0)));
      if(animate && at !== index){
        animating = true;
        window.setTimeout(function(){ animating = false; }, 340);
      }
      applyTrackTransform(at, !!animate);
      activateIndex(at, true);
    }

    function go(step){
      if(!items.length || animating) return;
      var next = Math.max(0, Math.min(items.length - 1, index + step));
      if(next === index) return;
      goTo(next, true);
    }

    function rebuildSlides(startAt){
      if(!track) return;
      track.classList.remove('is-animating');
      track.innerHTML = '';
      slideEls = [];
      if(!items.length){
        loadingEl.hidden = false;
        loadingEl.textContent = 'No videos yet';
        applyTrackTransform(0, false);
        return;
      }
      startAt = Math.max(0, Math.min(items.length - 1, Number(startAt || 0)));
      items.forEach(function(it, i){
        var slide = buildSlide(it, i);
        track.appendChild(slide);
        slideEls.push(slide);
      });
      sizeSlides();
      // Always land on newest (or deep-linked) reel — no overflow scroll to restore.
      goTo(startAt, false);
      window.requestAnimationFrame(function(){
        sizeSlides();
        goTo(startAt, false);
      });
    }

    document.getElementById('reelApp').addEventListener('click', function(e){
      var t = e.target;
      if(!t || !t.closest) return;
      var slide = t.closest('.reel-slide');
      if(!slide) return;
      var i = Number(slide.getAttribute('data-index') || 0);
      var it = items[i];
      if(!it) return;

      var menuBtn = t.closest('.post-card-menu-btn');
      if(menuBtn){
        e.preventDefault();
        e.stopPropagation();
        if(window.MSBPostCardMenu && typeof window.MSBPostCardMenu.toggle === 'function'){
          window.MSBPostCardMenu.toggle(menuBtn);
        }
        return;
      }

      if(t.closest('.reel-mute')){
        globalMuted = !globalMuted;
        slideEls.forEach(function(s){
          var v = s.querySelector('video');
          if(v) v.muted = globalMuted;
          syncMuteBtn(s, globalMuted);
        });
        return;
      }
      if(t.closest('.js-open-readmore, .see-more')) return;
      var actBtn = t.closest('[data-act]');
      if(actBtn){
        var act = actBtn.getAttribute('data-act');
        var pid = Number(it.id || 0);
        if(!pid) return;
        if(act === 'comment'){
          var commentPid = Number(it.id || 0);
          if(!commentPid) return;
          if(window.TTComments && typeof window.TTComments.clearFocusComment === 'function'){
            window.TTComments.clearFocusComment();
          }
          if(window.TTComments && typeof window.TTComments.openForPost === 'function'){
            document.body.classList.add('public-leftbar-open');
            window.TTComments.openForPost(commentPid, null, {});
            return;
          }
          if(window.TTComments && typeof window.TTComments.toggle === 'function'){
            document.body.classList.add('public-leftbar-open');
            window.TTComments.toggle(commentPid, []);
            return;
          }
          window.location.href = 'public.php?post=' + encodeURIComponent(String(commentPid)) + '#post-' + encodeURIComponent(String(commentPid));
          return;
        }
        if(act === 'love'){
          // Picker handles selection when MSBReactions is available
          if(window.MSBReactions) return;
          applyReelReaction(slide, it, String(it.my_reaction || '') === 'love' ? 'none' : 'love');
          return;
        }
        if(act === 'share'){
          var prevShare = Number(it.share_count || 0);
          var prevShared = Number(it.my_shared || 0);
          var nextShared = prevShared ? 0 : 1;
          it.my_shared = nextShared;
          it.share_count = Math.max(0, prevShare + (nextShared ? 1 : -1));
          slide.setAttribute('data-engage-at', String(Date.now()));
          syncSlideActions(slide, it);
          if(window.MSBPostEngagement){
            window.MSBPostEngagement.publishFromTrack(pid, {
              share_count: it.share_count,
              save_count: it.save_count,
              state: { shared: nextShared, saved: Number(it.my_saved || 0) }
            }, { source: 'reel' });
          }
          var fdS = new FormData();
          fdS.append('ajax', 'share');
          fdS.append('post_id', String(pid));
          fetch(API, { method:'POST', body: fdS, credentials:'same-origin' })
            .then(function(r){ return r.json(); })
            .then(function(res){
              if(!res || !res.ok){
                it.share_count = prevShare;
                it.my_shared = prevShared;
                slide.setAttribute('data-engage-at', String(Date.now()));
                syncSlideActions(slide, it);
                return;
              }
              it.share_count = Number(res.share_count != null ? res.share_count : it.share_count || 0);
              if(res.state && typeof res.state.shared !== 'undefined') it.my_shared = Number(res.state.shared || 0);
              else it.my_shared = nextShared;
              slide.setAttribute('data-engage-at', String(Date.now()));
              syncSlideActions(slide, it);
              if(window.MSBPostEngagement) window.MSBPostEngagement.publishFromTrack(pid, res, { source: 'reel' });
            }).catch(function(){
              it.share_count = prevShare;
              it.my_shared = prevShared;
              slide.setAttribute('data-engage-at', String(Date.now()));
              syncSlideActions(slide, it);
            });
          return;
        }
        if(act === 'save'){
          var prevSave = Number(it.save_count || 0);
          var prevSaved = Number(it.my_saved || 0);
          var nextSaved = prevSaved ? 0 : 1;
          it.my_saved = nextSaved;
          it.save_count = Math.max(0, prevSave + (nextSaved ? 1 : -1));
          slide.setAttribute('data-engage-at', String(Date.now()));
          syncSlideActions(slide, it);
          syncSlideMenu(slide, it);
          if(window.MSBPostEngagement){
            window.MSBPostEngagement.publishFromTrack(pid, {
              save_count: it.save_count,
              share_count: it.share_count,
              state: { saved: nextSaved, shared: Number(it.my_shared || 0) }
            }, { source: 'reel' });
          }
          var fdV = new FormData();
          fdV.append('ajax', 'save');
          fdV.append('post_id', String(pid));
          fetch(API, { method:'POST', body: fdV, credentials:'same-origin' })
            .then(function(r){ return r.json(); })
            .then(function(res){
              if(!res || !res.ok){
                it.save_count = prevSave;
                it.my_saved = prevSaved;
                slide.setAttribute('data-engage-at', String(Date.now()));
                syncSlideActions(slide, it);
                syncSlideMenu(slide, it);
                return;
              }
              it.save_count = Number(res.save_count != null ? res.save_count : it.save_count || 0);
              if(res.state && typeof res.state.saved !== 'undefined') it.my_saved = Number(res.state.saved || 0);
              else it.my_saved = nextSaved;
              slide.setAttribute('data-engage-at', String(Date.now()));
              syncSlideActions(slide, it);
              syncSlideMenu(slide, it);
              if(window.MSBPostEngagement) window.MSBPostEngagement.publishFromTrack(pid, res, { source: 'reel' });
            }).catch(function(){
              it.save_count = prevSave;
              it.my_saved = prevSaved;
              slide.setAttribute('data-engage-at', String(Date.now()));
              syncSlideActions(slide, it);
              syncSlideMenu(slide, it);
            });
          return;
        }
      }
      if(t.closest('.reel-follow')){
        if(Number(it.is_following || 0) === 1) return;
        var peer = Number(it.user_id || 0);
        if(!peer) return;
        var isPub = String(it.account_kind || '') === 'publisher';
        var url = isPub ? 'ajax/publisher_follow.php' : 'ajax/friend_action.php';
        var body = isPub
          ? ('action=follow&publisher_id=' + encodeURIComponent(String(peer)))
          : ('action=send&peer_id=' + encodeURIComponent(String(peer)));
        fetch(url, {
          method:'POST',
          headers:{ 'Content-Type':'application/x-www-form-urlencoded' },
          body: body,
          credentials:'same-origin'
        }).then(function(r){ return r.json().catch(function(){ return {}; }); })
          .then(function(){
            it.is_following = 1;
            syncSlideActions(slide, it);
          }).catch(function(){});
      }
    });

    if(window.MSBReactions && typeof window.MSBReactions.bindLikePicker === 'function'){
      window.MSBReactions.bindLikePicker('#reelApp .reel-act[data-act="love"]', function(btn, reaction){
        var slide = btn.closest('.reel-slide');
        if(!slide) return;
        var idx = slideEls.indexOf(slide);
        var it = idx >= 0 ? items[idx] : null;
        if(!it){
          var pid = Number(slide.getAttribute('data-post-id') || 0);
          for(var i = 0; i < items.length; i += 1){
            if(Number(items[i].id || 0) === pid){ it = items[i]; break; }
          }
        }
        if(!it || !reaction) return;
        applyReelReaction(slide, it, reaction);
      });
    }

    if(window.MSBPostEngagement && typeof window.MSBPostEngagement.registerAdapter === 'function'){
      window.MSBPostEngagement.registerAdapter(function(postId, patch){
        postId = Number(postId || 0);
        if(!postId || !patch) return;
        for(var i = 0; i < items.length; i++){
          if(Number(items[i].id || 0) !== postId) continue;
          if(typeof patch.love_count !== 'undefined') items[i].love_count = Number(patch.love_count || 0);
          if(typeof patch.like_count !== 'undefined') items[i].like_count = Number(patch.like_count || 0);
          if(typeof patch.reaction_count !== 'undefined') items[i].reaction_count = Number(patch.reaction_count || 0);
          if(typeof patch.comment_count !== 'undefined') items[i].comment_count = Number(patch.comment_count || 0);
          if(typeof patch.share_count !== 'undefined') items[i].share_count = Number(patch.share_count || 0);
          if(typeof patch.save_count !== 'undefined') items[i].save_count = Number(patch.save_count || 0);
          if(typeof patch.my_reaction !== 'undefined') items[i].my_reaction = String(patch.my_reaction || '');
          if(typeof patch.is_saved !== 'undefined') items[i].my_saved = Number(patch.is_saved || 0);
          if(typeof patch.is_shared !== 'undefined') items[i].my_shared = Number(patch.is_shared || 0);
          break;
        }
      });
    }

    document.getElementById('reelUp').addEventListener('click', function(){ go(-1); });
    document.getElementById('reelDown').addEventListener('click', function(){ go(1); });
    document.addEventListener('keydown', function(e){
      if(e.key === 'ArrowUp'){ e.preventDefault(); go(-1); }
      if(e.key === 'ArrowDown'){ e.preventDefault(); go(1); }
      if(e.key === 'm' || e.key === 'M'){
        globalMuted = !globalMuted;
        slideEls.forEach(function(s){
          var v = s.querySelector('video');
          if(v) v.muted = globalMuted;
          syncMuteBtn(s, globalMuted);
        });
      }
      if(e.key === 'Escape'){
        if(window.TTComments && typeof window.TTComments.isOpen === 'function' && window.TTComments.isOpen()){
          return;
        }
        if(window.TTReadMore && typeof window.TTReadMore.isOpen === 'function' && window.TTReadMore.isOpen()){
          return;
        }
        var commentsWrap = document.getElementById('tt-comments-wrap');
        if(commentsWrap && commentsWrap.classList.contains('is-open')) return;
        var readWrap = document.getElementById('tt-readmore-wrap');
        if(readWrap && readWrap.classList.contains('is-open')) return;
        window.location.href = 'public.php';
      }
    });

    scroller.addEventListener('wheel', function(e){
      if(!items.length) return;
      e.preventDefault();
      var now = Date.now();
      if(now < wheelLockUntil || animating) return;
      if(Math.abs(e.deltaY) < 8) return;
      wheelLockUntil = now + 420;
      go(e.deltaY > 0 ? 1 : -1);
    }, { passive:false });

    scroller.addEventListener('touchstart', function(e){
      if(!e.touches || !e.touches[0]) return;
      touchStartY = e.touches[0].clientY;
      touchDeltaY = 0;
    }, { passive:true });

    scroller.addEventListener('touchmove', function(e){
      if(!e.touches || !e.touches[0]) return;
      touchDeltaY = e.touches[0].clientY - touchStartY;
    }, { passive:true });

    scroller.addEventListener('touchend', function(){
      if(Math.abs(touchDeltaY) < 56 || animating) {
        touchDeltaY = 0;
        return;
      }
      go(touchDeltaY < 0 ? 1 : -1);
      touchDeltaY = 0;
    }, { passive:true });

    window.addEventListener('resize', function(){
      sizeSlides();
      applyTrackTransform(index, false);
      var it = items[index];
      var slide = slideEls[index];
      if(it && slide) syncSlideSize(slide, it);
    }, { passive:true });

    function postCreatedMs(it){
      var raw = String((it && it.created_at) || '').trim();
      if(raw){
        var t = Date.parse(raw.replace(' ', 'T'));
        if(isFinite(t)) return t;
      }
      return Number((it && it.id) || 0);
    }
    function sortNewestVideosFirst(list){
      return (list || []).slice().sort(function(a, b){
        var idDiff = Number(b.id || 0) - Number(a.id || 0);
        if(idDiff !== 0) return idDiff;
        return postCreatedMs(b) - postCreatedMs(a);
      });
    }

    function resolveStartIndex(){
      var want = 0;
      try{
        want = Number((new URL(window.location.href)).searchParams.get('post') || 0);
      }catch(e){ want = 0; }
      // No deep link → always newest (index 0).
      if(!(want > 0)) return 0;
      for(var i = 0; i < items.length; i += 1){
        if(Number(items[i].id || 0) === want) return i;
      }
      return 0;
    }

    fetch(API + '?ajax=list&filter=all&page=public&limit=80&exclude_stories=1&order=created&media=video', { credentials:'same-origin' })
      .then(function(r){ return r.json(); })
      .then(function(res){
        loadingEl.hidden = true;
        var list = (res && res.ok && Array.isArray(res.items)) ? res.items : [];
        items = sortNewestVideosFirst(list.filter(isVideoItem));
        if(!items.length){
          loadingEl.hidden = false;
          loadingEl.textContent = 'No videos yet';
          return;
        }
        rebuildSlides(resolveStartIndex());
      })
      .catch(function(){
        loadingEl.hidden = false;
        loadingEl.textContent = 'Could not load reels';
      });

    window.addEventListener('pageshow', function(e){
      if(!items.length) return;
      // Only rebuild from bfcache; otherwise keep newest (or deep link) in place.
      if(e && e.persisted){
        rebuildSlides(resolveStartIndex());
        return;
      }
      goTo(resolveStartIndex(), false);
    });
  })();
  </script>
  <style id="reel-chrome-contrast">
    /* Page/letterbox follows Appearance color; on-video chrome stays bright. */
    html[data-msb-appearance] body.reel-page,
    html[data-msb-appearance] body.reel-page .reel-app,
    html[data-msb-appearance] body.reel-page .reel-loading,
    html.dark-auto body.reel-page,
    html.dark-auto body.reel-page .reel-app,
    html.dark-auto body.reel-page .reel-loading{
      background:var(--msb-palette-bg, var(--reel-bg, #000)) !important;
      background-image:none !important;
    }
    /* Keep public left icon rail readable (do not inherit reel white text). */
    html[data-msb-appearance] body.reel-page .feed-ig-rail,
    html.dark-auto body.reel-page .feed-ig-rail,
    body.reel-page .feed-ig-rail{
      color:var(--msb-palette-text-on-nav, var(--msb-palette-text, #111827)) !important;
      -webkit-text-fill-color:unset;
    }
    /* Controls that remain on the video itself stay white. */
    body.reel-page .reel-mute,
    body.reel-page .reel-mute .fa,
    body.reel-page .reel-mute i{
      color:#fff !important;
      -webkit-text-fill-color:#fff !important;
      fill:#fff !important;
      stroke:#fff !important;
    }
    body.reel-page .reel-mute{
      background:rgba(0,0,0,.6) !important;
    }
    body.reel-page .reel-card-main > .reel-caption .see-more{
      color:var(--msb-palette-text, #fff) !important;
      -webkit-text-fill-color:var(--msb-palette-text, #fff) !important;
      text-decoration:underline !important;
      text-underline-offset:2px;
      font-weight:800 !important;
    }

    /*
     * Side actions + jump sit on the page bg.
     * data-reel-side-chrome is set from the real computed bg (works with Appearance + Dark auto).
     */
    html[data-reel-side-chrome="dark"] body.reel-page .reel-act,
    html[data-reel-side-chrome="dark"] body.reel-page .reel-act .msb-pact,
    html[data-reel-side-chrome="dark"] body.reel-page .reel-act .fa,
    html[data-reel-side-chrome="dark"] body.reel-page .reel-act-count,
    html[data-reel-side-chrome="dark"] body.reel-page .reel-jump button,
    html[data-reel-side-chrome="dark"] body.reel-page .reel-jump button .fa,
    html[data-reel-side-chrome="dark"] body.reel-page .reel-jump button i,
    html[data-reel-side-chrome="dark"] body.reel-page .reel-jump button .icon,
    html.dark-auto[data-reel-side-chrome="dark"] body.reel-page .reel-jump button .icon,
    html.dark-auto[data-reel-side-chrome="dark"] body.reel-page .reel-jump .icon{
      color:#0f172a !important;
      -webkit-text-fill-color:#0f172a !important;
      fill:#0f172a !important;
      stroke:#0f172a !important;
      text-shadow:var(--msb-pact-contrast-text-shadow, 0 0 2px rgba(255,255,255,.95), 0 1px 2px rgba(0,0,0,.45)) !important;
    }
    html[data-reel-side-chrome="dark"] body.reel-page .reel-act .msb-pact{
      filter:var(--msb-pact-contrast-filter, drop-shadow(0 0 1.35px rgba(255,255,255,.98)) drop-shadow(0 1px 2px rgba(0,0,0,.55))) !important;
    }
    html[data-reel-side-chrome="light"] body.reel-page .reel-act,
    html[data-reel-side-chrome="light"] body.reel-page .reel-act .msb-pact,
    html[data-reel-side-chrome="light"] body.reel-page .reel-act .fa,
    html[data-reel-side-chrome="light"] body.reel-page .reel-act-count,
    html[data-reel-side-chrome="light"] body.reel-page .reel-jump button,
    html[data-reel-side-chrome="light"] body.reel-page .reel-jump button .fa,
    html[data-reel-side-chrome="light"] body.reel-page .reel-jump button i,
    html[data-reel-side-chrome="light"] body.reel-page .reel-jump button .icon,
    html.dark-auto[data-reel-side-chrome="light"] body.reel-page .reel-jump button .icon,
    html.dark-auto[data-reel-side-chrome="light"] body.reel-page .reel-jump .icon{
      color:#fff !important;
      -webkit-text-fill-color:#fff !important;
      fill:#fff !important;
      stroke:#fff !important;
      text-shadow:0 1px 3px rgba(0,0,0,.75), 0 0 2px rgba(0,0,0,.55) !important;
    }
    html[data-reel-side-chrome="light"] body.reel-page .reel-act .msb-pact{
      filter:drop-shadow(0 1px 2px rgba(0,0,0,.75)) drop-shadow(0 0 1px rgba(0,0,0,.45)) !important;
    }
    html[data-reel-side-chrome="dark"] body.reel-page .reel-jump button,
    html.dark-auto[data-reel-side-chrome="dark"] body.reel-page .reel-jump button{
      background:rgba(15,23,42,.08) !important;
      border:1px solid rgba(15,23,42,.14) !important;
      box-shadow:0 1px 6px rgba(15,23,42,.12) !important;
      color:#0f172a !important;
    }
    html[data-reel-side-chrome="light"] body.reel-page .reel-jump button,
    html.dark-auto[data-reel-side-chrome="light"] body.reel-page .reel-jump button{
      background:rgba(255,255,255,.24) !important;
      border:1px solid rgba(255,255,255,.28) !important;
      box-shadow:0 1px 6px rgba(0,0,0,.4) !important;
      color:#fff !important;
    }
    body.reel-page .reel-act.is-love .msb-pact-heart{
      color:var(--msb-love-color) !important;
      -webkit-text-fill-color:var(--msb-love-color) !important;
      fill:var(--msb-love-color) !important;
      filter:var(--msb-pact-contrast-filter, drop-shadow(0 0 1.35px rgba(255,255,255,.98)) drop-shadow(0 1px 2px rgba(0,0,0,.55))) !important;
    }
    body.reel-page .reel-act.is-share .msb-pact-share{
      color:#374151 !important;
      -webkit-text-fill-color:#374151 !important;
      fill:#374151 !important;
      filter:var(--msb-pact-contrast-filter, drop-shadow(0 0 1.35px rgba(255,255,255,.98)) drop-shadow(0 1px 2px rgba(0,0,0,.55))) !important;
    }
    body.reel-page .reel-act.is-save .msb-pact-bookmark{
      color:#f59e0b !important;
      -webkit-text-fill-color:#f59e0b !important;
      fill:#f59e0b !important;
      filter:var(--msb-pact-contrast-filter, drop-shadow(0 0 1.35px rgba(255,255,255,.98)) drop-shadow(0 1px 2px rgba(0,0,0,.55))) !important;
    }
  </style>
  <script>
  (function(){
    function parseRgb(str){
      if(!str) return null;
      str = String(str).trim();
      if(str === 'transparent' || str === 'rgba(0, 0, 0, 0)') return null;
      var m = str.match(/rgba?\(\s*([0-9.]+)\s*,\s*([0-9.]+)\s*,\s*([0-9.]+)/i);
      if(m) return { r:+m[1], g:+m[2], b:+m[3] };
      m = str.match(/^#([0-9a-f]{3}|[0-9a-f]{6})$/i);
      if(m){
        var h = m[1];
        if(h.length === 3) h = h[0]+h[0]+h[1]+h[1]+h[2]+h[2];
        return { r:parseInt(h.slice(0,2),16), g:parseInt(h.slice(2,4),16), b:parseInt(h.slice(4,6),16) };
      }
      return null;
    }
    function channel(c){
      c = c / 255;
      return c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4);
    }
    function luminance(rgb){
      return 0.2126 * channel(rgb.r) + 0.7152 * channel(rgb.g) + 0.0722 * channel(rgb.b);
    }
    function syncReelSideChrome(){
      var root = document.documentElement;
      var app = document.getElementById('reelApp') || document.body;
      var rgb = parseRgb(getComputedStyle(app).backgroundColor)
        || parseRgb(getComputedStyle(document.body).backgroundColor)
        || parseRgb((getComputedStyle(root).getPropertyValue('--msb-palette-bg') || '').trim())
        || parseRgb((getComputedStyle(root).getPropertyValue('--reel-bg') || '').trim())
        || { r:0, g:0, b:0 };
      function contrastAgainst(fg){
        var L1 = luminance(fg);
        var L2 = luminance(rgb);
        var lighter = Math.max(L1, L2);
        var darker = Math.min(L1, L2);
        return (lighter + 0.05) / (darker + 0.05);
      }
      // Prefer the ink color with the stronger ratio on the real page bg (teal → white icons).
      var mode = contrastAgainst({ r:255, g:255, b:255 }) >= contrastAgainst({ r:15, g:23, b:42 })
        ? 'light'
        : 'dark';
      if(root.getAttribute('data-reel-side-chrome') !== mode){
        root.setAttribute('data-reel-side-chrome', mode);
      }
    }
    syncReelSideChrome();
    window.addEventListener('load', syncReelSideChrome);
    document.addEventListener('msb:appearance-changed', syncReelSideChrome);
    document.addEventListener('msb:theme-changed', syncReelSideChrome);
    try{
      var mo = new MutationObserver(function(){ syncReelSideChrome(); });
      mo.observe(document.documentElement, { attributes:true, attributeFilter:['class','data-msb-appearance','data-theme','style'] });
    }catch(_e){}
    try{
      if(window.matchMedia){
        var mq = window.matchMedia('(prefers-color-scheme: dark)');
        if(mq.addEventListener) mq.addEventListener('change', syncReelSideChrome);
        else if(mq.addListener) mq.addListener(syncReelSideChrome);
      }
    }catch(_e2){}
    setTimeout(syncReelSideChrome, 0);
    setTimeout(syncReelSideChrome, 120);
    setTimeout(syncReelSideChrome, 400);
  })();
  </script>
</body>
</html>
