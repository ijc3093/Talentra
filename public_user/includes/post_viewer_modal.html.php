<?php
/**
 * Shared Instagram-style post viewer modal (#pvOverlay).
 * Used by public.php (feed/profile keep their inline copies for now).
 */
if (defined('MSB_POST_VIEWER_MODAL_HTML')) {
    return;
}
define('MSB_POST_VIEWER_MODAL_HTML', true);
if (!function_exists('post_action_thin_icon')) {
    require_once __DIR__ . '/post_action_thin_icons.php';
}
?>
<!-- Post Viewer Modal (Instagram-style) -->
<div id="pvOverlay" class="pv-overlay" aria-hidden="true" hidden style="display:none">
  <button type="button" class="pv-x" id="pvClose" aria-label="Close"><i class="icon ion-close"></i></button>
  <button type="button" class="pv-nav pv-prev" id="pvPrev" aria-label="Previous" hidden><i class="fa fa-chevron-left" aria-hidden="true"></i></button>
  <button type="button" class="pv-nav pv-next" id="pvNext" aria-label="Next" hidden><i class="fa fa-chevron-right" aria-hidden="true"></i></button>

  <div class="pv-modal" role="dialog" aria-modal="true" aria-label="Post viewer">
    <div class="pv-left">
      <div class="pv-media" id="pvMedia"></div>
    </div>
    <div class="pv-right">
      <div class="pv-head">
        <div class="pv-user">
          <img id="pvAvatar" class="pv-ava" alt="" src="" />
          <div class="pv-namewrap">
            <div id="pvName" class="pv-name">—</div>
            <div id="pvMeta" class="pv-meta">—</div>
          </div>
        </div>
        <button type="button" class="pv-dots" id="pvDots" aria-label="More" hidden><i class="icon ion-android-more-horizontal"></i></button>
      </div>

      <div class="pv-body" id="pvBody">
        <div class="pv-caption" id="pvCaption" style="display:none;"></div>
        <div class="pv-comments" id="pvComments" aria-label="Comments"></div>
      </div>

      <div class="pv-actions">
        <div class="pv-actrow">
          <button type="button" class="pv-act pv-act-love js-react-love" id="pvLove" title="Love" aria-label="Love"><?= function_exists('post_action_thin_icon') ? post_action_thin_icon('heart') : '<i class="icon ion-heart"></i>' ?><span class="pv-n" id="pvLoveN">0</span></button>
          <button type="button" class="pv-act pv-act-like" id="pvLike" title="Like" aria-label="Like" hidden><?= function_exists('post_action_thin_icon') ? post_action_thin_icon('thumb') : '<i class="icon ion-thumbsup"></i>' ?><span class="pv-n" id="pvLikeN">0</span></button>
          <button type="button" class="pv-act pv-act-comment" id="pvComment" title="Comment" aria-label="Comment"><?= function_exists('post_action_thin_icon') ? post_action_thin_icon('comment') : '<i class="icon ion-chatbubble"></i>' ?><span class="pv-n" id="pvComN">0</span></button>
          <button type="button" class="pv-act pv-act-share js-share-post" id="pvShare" title="Share" aria-label="Share"><?= function_exists('post_action_thin_icon') ? post_action_thin_icon('share') : '<i class="icon ion-forward"></i>' ?><span class="pv-n" id="pvShareN">0</span></button>
          <div class="pv-sp"></div>
          <button type="button" class="pv-act pv-act-save js-save-post" id="pvSave" title="Save" aria-label="Save"><?= function_exists('post_action_thin_icon') ? post_action_thin_icon('bookmark') : '<i class="icon ion-bookmark"></i>' ?><span class="pv-n" id="pvSaveN">0</span></button>
        </div>
        <div class="pv-counts" hidden aria-hidden="true">
          <span class="pv-c" title="Views"><i class="icon ion-eye"></i> <b id="pvViewN">0</b></span>
        </div>
        <div class="pv-replybar" id="pvReplyBar" style="display:none;">
          <span><span id="pvReplyLead">Replying to</span> <b id="pvReplyName">—</b></span>
          <button type="button" class="pv-replyx" id="pvReplyCancel" aria-label="Cancel reply"><i class="icon ion-close"></i></button>
        </div>
        <div class="pv-input">
          <button type="button" class="pv-iconbtn" id="pvAtBtn" title="Mention" aria-label="Mention">
            <i class="icon ion-at"></i>
          </button>
          <input type="text" id="pvText" placeholder="Add comment..." autocomplete="off" />
          <button type="button" class="pv-iconbtn" id="pvEmojiBtn" title="Emoji" aria-label="Emoji">
            <i class="icon ion-happy-outline"></i>
          </button>
          <button type="button" class="pv-send" id="pvPostBtn" title="Send" aria-label="Send">
            <i class="icon ion-arrow-up-a"></i>
          </button>
        </div>
      </div>
    </div>
  </div>
</div>

<style id="msb-post-viewer-modal-base">
  /* Base component styles. Layout chrome (black stage, beige panel, divider)
     is applied by post_viewer_gallery_chrome.css.php after this file. */
  .pv-overlay{position:fixed;inset:0;display:none;align-items:center;justify-content:center;z-index:9999;padding:24px;overflow:auto;-webkit-overflow-scrolling:touch;overscroll-behavior:contain;}
  .pv-overlay.pv-is-switching .pv-body{opacity:.84;transition:opacity .14s ease;pointer-events:none;}
  .pv-overlay.show{display:flex;}
  .pv-modal{width:fit-content;max-width:min(1320px,96vw);height:min(720px,88vh);background:transparent;overflow:hidden;display:flex;align-items:stretch;box-shadow:none;}
  .pv-left{flex:1.15;min-width:0;background:#000;display:flex;align-items:center;justify-content:center;}
  .pv-media{width:100%;height:100%;display:flex;align-items:center;justify-content:center;position:relative;background:#000;}
  .pv-media img,.pv-media video,.pv-media iframe{max-width:100%;max-height:100%;width:auto;height:auto;}
  .pv-media video{width:100%;height:100%;object-fit:contain;}
  .pv-media .mf-media-carousel,
  .pv-media .media-carousel{position:relative;width:100%;height:100%;overflow:hidden;background:transparent;}
  .pv-media .mf-media-slides,
  .pv-media .media-slides{display:flex;flex-wrap:nowrap;width:100%;height:100%;transition:transform .28s ease;}
  .pv-media .mf-media-slide,
  .pv-media .media-slide{flex:0 0 100%;width:100%;height:100%;max-width:100%;display:flex;align-items:center;justify-content:center;overflow:hidden;background:transparent;}
  .pv-media .mf-media-slide > img,
  .pv-media .media-slide > img{max-width:100%;max-height:100%;width:auto;height:auto;object-fit:contain;object-position:center center;}
  .pv-media .mf-media-slide > video,
  .pv-media .media-slide > video{max-width:100%;max-height:100%;width:100%;height:100%;object-fit:contain;object-position:center center;}
  .pv-media .mf-media-nav,
  .pv-media .media-nav{
    position:absolute !important;top:50% !important;transform:translateY(-50%) !important;
    width:20px !important;height:20px !important;border:none !important;border-radius:999px !important;
    background:rgba(159,153,153,.9) !important;color:#fff !important;display:flex !important;
    align-items:center !important;justify-content:center !important;font-size:10px !important;
    cursor:pointer;box-shadow:0 8px 24px rgba(0,0,0,.18) !important;z-index:6 !important;padding:0 !important;
  }
  .pv-media .mf-media-nav:hover,
  .pv-media .media-nav:hover{background:rgba(180,180,180,.95) !important;}
  .pv-media .mf-media-nav.prev,
  .pv-media .media-nav.prev{left:12px !important;}
  .pv-media .mf-media-nav.next,
  .pv-media .media-nav.next{right:12px !important;}
  .pv-media .mf-media-nav i,
  .pv-media .media-nav i{font-size:10px !important;line-height:1 !important;color:#fff !important;}
  .pv-media .mf-media-dots,
  .pv-media .media-dots{
    position:absolute;left:50%;bottom:12px;transform:translateX(-50%);display:flex;align-items:center;
    justify-content:center;gap:5px;padding:0;border-radius:0;background:transparent;z-index:5;
  }
  .pv-media .mf-media-dot,
  .pv-media .media-dot{
    width:5px !important;height:5px !important;min-width:5px !important;min-height:5px !important;flex:0 0 5px !important;
    display:block !important;border:none !important;border-radius:50% !important;padding:0 !important;margin:0 !important;
    background:rgba(255,255,255,.55) !important;cursor:pointer;appearance:none;-webkit-appearance:none;box-shadow:none !important;
    font-size:0 !important;line-height:0 !important;color:transparent !important;text-indent:-9999px !important;overflow:hidden !important;
  }
  .pv-media .mf-media-dot.is-active,
  .pv-media .media-dot.is-active{
    width:6px !important;height:6px !important;min-width:6px !important;min-height:6px !important;flex:0 0 6px !important;
    background:#3897f0 !important;
  }
  @media (max-width: 900px){
    .pv-left.pv-left-scroll{align-items:stretch !important;justify-content:stretch !important;}
    .pv-left.pv-left-scroll .pv-media{overflow:auto;-webkit-overflow-scrolling:touch;align-items:flex-start !important;justify-content:flex-start !important;}
    .pv-left.pv-left-scroll .pv-media > div{height:auto !important;min-height:100%;align-items:flex-start !important;justify-content:flex-start !important;padding:22px !important;}
  }
  .pv-right{flex:.85;min-width:320px;display:flex;flex-direction:column;background:var(--msb-palette-bg, #f2f1e8);min-height:0;border-left:1px solid var(--msb-palette-border, rgba(15,23,42,.18));}
  .pv-head{padding:14px 14px;border-bottom:1px solid rgba(15,23,42,.08);display:flex;align-items:center;justify-content:space-between;gap:10px;}
  .pv-user{display:flex;align-items:center;gap:10px;min-width:0;}
  .pv-ava{width:35px;height:35px;border-radius:999px;object-fit:cover;background:#eef2ff;}
  .pv-namewrap{min-width:0;}
  .pv-name{font-weight:700;font-size:13px;line-height:1.1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
  .pv-meta{font-size:12px;color:rgba(15,23,42,.55);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
  .pv-dots{border:0;background:transparent;width:32px;height:32px;border-radius:10px;display:flex;align-items:center;justify-content:center;cursor:pointer;}
  .pv-dots:hover{background:rgba(15,23,42,.06);}
  .pv-body{flex:1;min-height:0;overflow:auto;-webkit-overflow-scrolling:touch;overscroll-behavior:contain;scrollbar-width:thin;scrollbar-color:rgba(15,23,42,.35) transparent;}
  #pvOverlay .pv-body::-webkit-scrollbar,
  .pv-body::-webkit-scrollbar{width:2px !important;height:2px !important;}
  #pvOverlay .pv-body::-webkit-scrollbar-thumb,
  .pv-body::-webkit-scrollbar-thumb{background:rgba(15,23,42,.35) !important;border-radius:999px;border:0 !important;min-height:24px;}
  #pvOverlay .pv-body::-webkit-scrollbar-track,
  .pv-body::-webkit-scrollbar-track{background:transparent !important;}
  .pv-comments{padding:4px 10px 10px;padding-bottom:160px;}
  .pv-actions{position:sticky;bottom:0;background:var(--msb-palette-bg, #f2f1e8);z-index:3;}
  .pv-input{position:sticky;bottom:0;background:var(--msb-palette-bg, #f2f1e8);padding:10px 0 calc(10px + env(safe-area-inset-bottom));margin-top:10px;z-index:4;}
  .pv-input::before{content:"";position:absolute;left:0;right:0;top:-10px;height:10px;background:linear-gradient(to top, var(--msb-palette-bg, #f2f1e8), rgba(255,255,255,0));}
  @media (max-width: 980px){
    .pv-overlay{padding:10px;align-items:stretch;}
    .pv-modal{width:100%;height:calc(var(--vh, 1vh) * 100 - 20px);max-height:none;border-radius:18px;}
  }
  @media (max-width: 640px){
    .pv-overlay{padding:0;}
    .pv-modal{width:100vw;height:calc(var(--vh, 1vh) * 100);border-radius:0;}
  }
  .pv-caption{border-bottom:1px solid rgba(15,23,42,.08);padding:10px 14px;max-height:140px;overflow:auto;}
  .pv-cap{font-size:13px;line-height:1.35;color:#0f172a;word-break:break-word;}
  .pv-cap-title{font-size:14px;font-weight:700;line-height:1.25;margin-bottom:6px;}
  .pv-cap-desc{font-size:13px;line-height:1.45;}
  .pv-cap-subtitle{font-size:13px;font-weight:700;line-height:1.3;margin:10px 0 6px;color:inherit;}
  .pv-cap-summary{font-size:13px;line-height:1.45;opacity:.95;}
  .pv-cap-summary .post-slide-summary-p{margin:0}
  .pv-cap-summary .post-slide-summary-list{margin:0;padding-left:1.15em;list-style:disc}
  .pv-cap-summary .post-slide-summary-list li{margin:0 0 .35em}
  .pv-cap-short,.pv-cap-full{white-space:normal;word-break:break-word;}
  .pv-cap[data-expanded="1"] .pv-cap-desc{max-height:220px;overflow:auto;padding-right:6px;}
  .pv-cap b{font-weight:800;}
  .pv-media-text{white-space:normal;word-break:break-word;}
  .pv-media-text[data-expanded="1"]{max-height:min(58vh, 420px);overflow:auto;padding-right:8px;}
  .pv-readmore{margin-left:6px;font-weight:800;color:var(--msb-palette-text, #0b1220);cursor:pointer;white-space:nowrap;}
  .pv-readmore:hover{text-decoration:underline;}
  .pv-richtext{display:block;}
  .pv-richtext .pv-rich-p{margin:0 0 12px;white-space:normal;word-break:break-word;}
  .pv-richtext .pv-rich-p:last-child{margin-bottom:0;}
  .pv-node{position:relative;--pv-avatar-size:20px;--pv-thread:var(--msb-palette-border-strong, rgba(15,23,42,.18));}
  .pv-node.has-children::after{content:"";position:absolute;left:calc(var(--pv-avatar-size) / 2);top:calc(var(--pv-avatar-size) + 10px);bottom:20px;width:2px;background:var(--pv-thread);border-radius:999px;}
  .pv-node.has-children.is-collapsed::after{display:none;}
  .pv-children{margin-left:calc(var(--pv-avatar-size) / 2);padding-left:28px;}
  .pv-com{display:flex;gap:7px;padding:14px 12px 12px;border-radius:18px;margin-bottom:0;}
  .pv-com .a{width:20px;height:20px;border-radius:999px;background:#111;color:#fff;flex:0 0 20px;overflow:hidden;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:8px;}
  .pv-com .a img{width:100%;height:100%;object-fit:cover;display:block;}
  .pv-com .b{min-width:0;flex:1;display:flex;flex-direction:column;}
  .pv-com .nm{font-weight:700;font-size:13px;line-height:1.25;color:var(--msb-palette-text, #101828);margin-bottom:4px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
  .pv-com .tx{font-size:13px;color:var(--msb-palette-text, #101828);line-height:1.4;word-wrap:break-word;}
  .pv-com .m{margin-top:8px;font-size:12px;color:var(--msb-palette-text-muted, #667085);display:flex;gap:14px;align-items:center;flex-wrap:wrap;}
  .pv-com .m .link{cursor:pointer;border:0;background:transparent;padding:0;color:inherit;font:inherit;font-weight:700;}
  .pv-com .m .replies-toggle{border:0;background:transparent;padding:0;color:inherit;font:inherit;font-weight:700;cursor:pointer;}
  .pv-com .m .pv-toggle-replies{color:var(--msb-palette-text-muted, #667085);font-weight:700;position:relative;padding-left:36px !important;display:inline-flex;align-items:center;gap:8px;}
  .pv-com .m .pv-toggle-replies::before{content:"";position:absolute;left:0;top:50%;width:22px;height:1px;background:var(--pv-thread);transform:translateY(-50%);}
  .pv-actions{border-top:1px solid rgba(15,23,42,.08);padding:10px 12px 12px;}
  .pv-actrow{display:flex;align-items:center;gap:14px;}
  .pv-act{border:0;background:transparent;display:inline-flex;align-items:center;justify-content:flex-start;gap:6px;cursor:pointer;color:#111827;padding:0;}
  .pv-act i{font-size:16px;line-height:1;}
  .pv-act .msb-pact{width:16px;height:16px;flex:0 0 auto;}
  .pv-act .pv-n{font-size:12px;font-weight:600;line-height:1;font-variant-numeric:tabular-nums;}
  .pv-sp{flex:1;}
  .pv-counts{display:none !important;}
  .pv-act.is-love{color:var(--msb-love-color, #ff4d6d);}
  .pv-act.is-like{color:#2563eb;}
  .pv-act.is-save{color:#f59e0b;}
  .pv-act.is-share{color:#4b5563;}
  .pv-act.is-save .msb-pact-bookmark,
  .pv-act.is-share .msb-pact-share{opacity:1;}
  .pv-act.is-save .msb-pact-bookmark{color:#f59e0b;}
  body:has(#pvOverlay.show) .msb-reaction-picker,
  html:has(#pvOverlay.show) .msb-reaction-picker{z-index:130000 !important;}
  #pvOverlay .pv-actions{position:relative;z-index:5;}
  .pv-input{margin-top:10px;display:flex;gap:10px;align-items:center;}
  .pv-input input{
    flex:1;min-width:0;min-height:40px;height:auto;border-radius:999px;
    border:1px solid var(--msb-palette-border-strong, rgba(15,23,42,.08));
    padding:10px 14px;outline:none;font-size:13px;
    background:var(--msb-palette-input-bg, #1f1f1f) !important;
    color:var(--msb-palette-text, #101828) !important;
  }
  .pv-input input::placeholder{color:var(--msb-palette-placeholder, #98a2b3) !important;font-size:13px;}
  .pv-iconbtn{
    width:22px;height:22px;border-radius:999px;border:1px solid transparent;
    background:var(--msb-palette-hover-bg, #f2f4f7);color:var(--msb-palette-text, #101828);
    display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:16px;padding:0;flex:0 0 auto;
  }
  #pvAtBtn{background:linear-gradient(180deg, #ff2e89 0%, #c11353 100%) !important;color:#fff !important;}
  .pv-send{
    width:25px;height:25px;border-radius:999px;border:none;
    background:#7c1730 !important;color:#fff !important;
    display:flex;align-items:center;justify-content:center;cursor:pointer;padding:0;flex:0 0 auto;
  }
  .pv-send:disabled{opacity:.55;cursor:not-allowed;}
  .pv-replybar{margin-bottom:10px;display:flex;align-items:center;justify-content:space-between;gap:8px;font-size:13px;color:var(--msb-palette-text-muted, #667085);}
  .pv-replyx{border:0;background:transparent;cursor:pointer;color:var(--msb-palette-text, #101828);font-weight:800;padding:0;}
  .pv-x{position:fixed;top:14px;right:14px;z-index:10000;border:0;background:rgba(255,255,255,.12);backdrop-filter:blur(8px);color:#fff;width:42px;height:42px;border-radius:999px;display:flex;align-items:center;justify-content:center;cursor:pointer;}
  .pv-x:hover{background:rgba(255,255,255,.18);}
  .pv-nav{display:none !important;}
  @media (max-width: 860px){
    .pv-overlay{align-items:stretch;justify-content:flex-start;}
    .pv-modal{flex-direction:column;width:min(720px,96vw);height:min(calc(var(--vh, 1vh) * 92),860px);margin:auto;position:relative;}
    .pv-right{min-width:0;}
    .pv-left{flex:1;min-height:42vh;}
  }
  @media (max-width: 520px){
    .pv-overlay{padding:10px;}
    .pv-modal{border-radius:14px;height:calc(var(--vh, 1vh) * 100 - 20px);}
    .pv-head{padding:12px;}
    .pv-comments{padding:10px 12px;padding-bottom:160px;}
  }
  body.pv-body-lock{touch-action:none;}
</style>
